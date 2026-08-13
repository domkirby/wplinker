<?php
/**
 * The request interceptor.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Matches the incoming request against the routing table and redirects.
 */
class WPLinker_Router {

	/**
	 * Singleton instance.
	 *
	 * @var WPLinker_Router|null
	 */
	private static $instance = null;

	/**
	 * Maximum number of path segments considered for prefix matching.
	 */
	const MAX_CANDIDATES = 25;

	/**
	 * Returns the shared instance.
	 *
	 * @return WPLinker_Router
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the interceptor into the request lifecycle.
	 *
	 * `parse_request` runs before WordPress resolves the query, so a matched
	 * route never pays for a main query or a template lookup.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_redirect' ), 0 );
	}

	/**
	 * Intercepts the current request.
	 *
	 * @param WP $wp Current WordPress environment instance.
	 * @return void
	 */
	public function maybe_redirect( $wp = null ) {
		if ( ! $this->should_handle( $wp ) ) {
			return;
		}

		$path = $this->current_path();

		if ( '' === $path ) {
			return;
		}

		$route = $this->match( $path );

		if ( ! $route ) {
			return;
		}

		$destination = $this->resolve_destination( $route, $path );

		/**
		 * Filters the resolved destination immediately before redirecting.
		 *
		 * Returning an empty value cancels the redirect and lets WordPress
		 * handle the request normally.
		 *
		 * @param string $destination Resolved destination URL.
		 * @param object $route       Matched route row.
		 * @param string $path        Normalised request path.
		 */
		$destination = apply_filters( 'wplinker_redirect_destination', $destination, $route, $path );

		if ( ! $destination ) {
			return;
		}

		$this->redirect( $destination, (int) $route->status_code, $route );
	}

	/**
	 * Finds the best route for a path.
	 *
	 * An exact route always beats a prefix route; between prefix routes the
	 * longest (most specific) source wins.
	 *
	 * @param string $path Normalised request path.
	 * @return object|null
	 */
	public function match( $path ) {
		if ( 0 === WPLinker_Routes::total() ) {
			return null;
		}

		$cache_key = WPLinker_Routes::cache_key( 'match:' . md5( $path ) );
		$cached    = wp_cache_get( $cache_key, WPLinker_Routes::CACHE_GROUP );

		if ( false !== $cached ) {
			return 'none' === $cached ? null : $cached;
		}

		$candidates = WPLinker_Routes::find_candidates( $this->candidate_paths( $path ) );
		$match      = null;

		foreach ( $candidates as $candidate ) {
			if ( 'exact' === $candidate->match_type ) {
				if ( 0 === strcasecmp( $candidate->source_path, $path ) ) {
					$match = $candidate;
					break;
				}

				continue;
			}

			if ( ! WPLinker_Validator::path_matches_prefix( $path, $candidate->source_path ) ) {
				continue;
			}

			if ( ! $match || strlen( $candidate->source_path ) > strlen( $match->source_path ) ) {
				$match = $candidate;
			}
		}

		wp_cache_set( $cache_key, $match ? $match : 'none', WPLinker_Routes::CACHE_GROUP, HOUR_IN_SECONDS );

		return $match;
	}

	/**
	 * Builds the list of source paths that could possibly match a request.
	 *
	 * For `/a/b/c` this is `/a/b/c`, `/a/b`, `/a` and `/`, which turns the
	 * lookup into one indexed `IN ()` query rather than a LIKE scan.
	 *
	 * @param string $path Normalised request path.
	 * @return array<int, string>
	 */
	public function candidate_paths( $path ) {
		$candidates = array( $path );
		$segments   = array_values( array_filter( explode( '/', $path ), 'strlen' ) );

		if ( count( $segments ) > self::MAX_CANDIDATES ) {
			$segments = array_slice( $segments, 0, self::MAX_CANDIDATES );
		}

		$current = '';

		foreach ( $segments as $segment ) {
			$current     .= '/' . $segment;
			$candidates[] = $current;
		}

		$candidates[] = '/';

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Builds the final URL for a matched route.
	 *
	 * @param object $route Matched route row.
	 * @param string $path  Normalised request path.
	 * @return string
	 */
	public function resolve_destination( $route, $path ) {
		$destination = $route->destination_url;

		if ( 'prefix' === $route->match_type ) {
			$remainder = '/' === $route->source_path ? $path : substr( $path, strlen( $route->source_path ) );

			if ( '/' === $remainder ) {
				$remainder = '';
			}

			if ( $remainder ) {
				$destination = $this->append_path( $destination, $remainder );
			}
		}

		/**
		 * Filters whether the incoming query string is forwarded to the destination.
		 *
		 * @param bool   $forward Whether to forward the query string. Default true.
		 * @param object $route   Matched route row.
		 */
		if ( apply_filters( 'wplinker_forward_query_string', true, $route ) ) {
			$destination = $this->append_query( $destination, $this->current_query_string() );
		}

		if ( '/' === substr( $destination, 0, 1 ) ) {
			$destination = home_url( $destination );
		}

		return $destination;
	}

	/**
	 * Appends trailing path segments to a URL, leaving its query and fragment in place.
	 *
	 * @param string $url       Destination URL or path.
	 * @param string $remainder Path remainder, beginning with a slash.
	 * @return string
	 */
	private function append_path( $url, $remainder ) {
		$fragment = '';
		$query    = '';

		$hash_pos = strpos( $url, '#' );

		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}

		$query_pos = strpos( $url, '?' );

		if ( false !== $query_pos ) {
			$query = substr( $url, $query_pos );
			$url   = substr( $url, 0, $query_pos );
		}

		return rtrim( $url, '/' ) . $remainder . $query . $fragment;
	}

	/**
	 * Merges a query string into a URL that may already carry one.
	 *
	 * @param string $url          Destination URL or path.
	 * @param string $query_string Query string without the leading question mark.
	 * @return string
	 */
	private function append_query( $url, $query_string ) {
		if ( '' === $query_string ) {
			return $url;
		}

		$fragment = '';
		$hash_pos = strpos( $url, '#' );

		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}

		$separator = false === strpos( $url, '?' ) ? '?' : '&';

		return $url . $separator . $query_string . $fragment;
	}

	/**
	 * Sends the redirect and records the hit.
	 *
	 * @param string $destination Destination URL.
	 * @param int    $status_code HTTP status code.
	 * @param object $route       Matched route row.
	 * @return void
	 */
	private function redirect( $destination, $status_code, $route ) {
		if ( headers_sent() ) {
			return;
		}

		$this->send_cache_headers( $status_code );

		/**
		 * Fires just before a route redirect is sent.
		 *
		 * @param object $route       Matched route row.
		 * @param string $destination Resolved destination URL.
		 */
		do_action( 'wplinker_pre_redirect', $route, $destination );

		// Not wp_safe_redirect(): routes are expected to point off site.
		wp_redirect( $destination, $status_code, 'WPLinker' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect

		// Flush the response before touching the database so the click counter
		// never lands in the visitor's critical path.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		/**
		 * Filters whether hits are counted.
		 *
		 * @param bool   $enabled Whether to count the hit. Default true.
		 * @param object $route   Matched route row.
		 */
		if ( apply_filters( 'wplinker_count_clicks', true, $route ) ) {
			WPLinker_Routes::increment_clicks( $route->id );
		}

		exit;
	}

	/**
	 * Sends cache directives appropriate to the status code.
	 *
	 * Permanent redirects are safe for intermediary caches to keep; temporary
	 * ones must be revalidated on every request.
	 *
	 * @param int $status_code HTTP status code.
	 * @return void
	 */
	private function send_cache_headers( $status_code ) {
		if ( in_array( (int) $status_code, array( 301, 308 ), true ) ) {
			/**
			 * Filters the max-age sent with permanent redirects.
			 *
			 * @param int $max_age Seconds. Default one hour.
			 */
			$max_age = (int) apply_filters( 'wplinker_permanent_redirect_max_age', HOUR_IN_SECONDS );

			header( 'Cache-Control: public, max-age=' . max( 0, $max_age ) );
			header_remove( 'Pragma' );
			header_remove( 'Expires' );

			return;
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
	}

	/**
	 * The normalised path for the current request.
	 *
	 * @return string
	 */
	public function current_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised
		$uri = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$uri = rawurldecode( $uri );

		return WPLinker_Validator::normalize_path( $uri );
	}

	/**
	 * The raw query string for the current request.
	 *
	 * @return string
	 */
	private function current_query_string() {
		if ( empty( $_SERVER['QUERY_STRING'] ) ) {
			return '';
		}

		return ltrim( wp_unslash( $_SERVER['QUERY_STRING'] ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised
	}

	/**
	 * Whether the current request is eligible for routing.
	 *
	 * @param WP|null $wp Current WordPress environment instance.
	 * @return bool
	 */
	private function should_handle( $wp = null ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( $wp instanceof WP && ! empty( $wp->query_vars['rest_route'] ) ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		/**
		 * Filters whether WPLinker inspects the current request at all.
		 *
		 * @param bool $should_handle Whether to run the route lookup.
		 */
		return (bool) apply_filters( 'wplinker_should_handle_request', true );
	}
}
