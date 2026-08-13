<?php
/**
 * Normalisation and validation rules shared by the REST API and the admin UI.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route validator.
 */
class WPLinker_Validator {

	/**
	 * Normalises a path: leading slash, no trailing slash, no query or fragment.
	 *
	 * The site's home path is stripped so routes stay portable between a root
	 * install and a subdirectory install.
	 *
	 * @param string $raw Raw path or absolute URL.
	 * @return string
	 */
	public static function normalize_path( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		// Accept a full URL and reduce it to its path component.
		if ( preg_match( '#^https?://#i', $raw ) ) {
			$raw = (string) wp_parse_url( $raw, PHP_URL_PATH );
		}

		// Drop any query string or fragment the user pasted in.
		$raw = strtok( $raw, '?' );
		$raw = strtok( (string) $raw, '#' );
		$raw = (string) $raw;

		$raw = self::strip_home_path( $raw );

		$raw = '/' . ltrim( $raw, '/' );
		$raw = preg_replace( '#/{2,}#', '/', $raw );

		if ( '/' !== $raw ) {
			$raw = rtrim( $raw, '/' );
		}

		return $raw;
	}

	/**
	 * Removes the site's home path (subdirectory installs) from a path.
	 *
	 * @param string $path Path beginning with a slash.
	 * @return string
	 */
	public static function strip_home_path( $path ) {
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = '/' . trim( (string) $home_path, '/' );

		if ( '/' === $home_path ) {
			return $path;
		}

		$candidate = '/' . ltrim( $path, '/' );

		if ( 0 === stripos( $candidate, $home_path . '/' ) ) {
			return substr( $candidate, strlen( $home_path ) );
		}

		if ( 0 === stripos( $candidate, $home_path ) && strlen( $candidate ) === strlen( $home_path ) ) {
			return '/';
		}

		return $path;
	}

	/**
	 * Splits a user supplied source into a path and an implied match type.
	 *
	 * `/docs/*` and `/docs/` both mean "everything under /docs", so a trailing
	 * wildcard is treated as an explicit request for a prefix route.
	 *
	 * @param string $raw Raw source.
	 * @return array{path: string, match_type: string|null}
	 */
	public static function parse_source( $raw ) {
		$raw        = trim( (string) $raw );
		$match_type = null;

		if ( '' !== $raw && '*' === substr( $raw, -1 ) ) {
			$match_type = 'prefix';
			$raw        = rtrim( substr( $raw, 0, -1 ), '/' );

			if ( '' === $raw ) {
				$raw = '/';
			}
		}

		return array(
			'path'       => self::normalize_path( $raw ),
			'match_type' => $match_type,
		);
	}

	/**
	 * Paths that may never be used as a source.
	 *
	 * @return array<int, string>
	 */
	public static function reserved_paths() {
		$reserved = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-json',
			'/' . ltrim( rest_get_url_prefix(), '/' ),
			'/wp-content',
			'/wp-includes',
			'/wp-cron.php',
			'/wp-signup.php',
			'/wp-activate.php',
			'/wp-comments-post.php',
			'/wp-trackback.php',
			'/wp-links-opml.php',
			'/wp-mail.php',
			'/xmlrpc.php',
		);

		/**
		 * Filters the paths that cannot be registered as a route source.
		 *
		 * @param array<int, string> $reserved Reserved paths, each with a leading slash.
		 */
		$reserved = apply_filters( 'wplinker_reserved_paths', $reserved );

		return array_values( array_unique( array_map( array( __CLASS__, 'normalize_path' ), $reserved ) ) );
	}

	/**
	 * Whether a source path collides with a reserved WordPress path.
	 *
	 * A prefix route also collides when a reserved path sits *underneath* it,
	 * which is what stops `/*` from swallowing the whole admin.
	 *
	 * @param string $source_path Normalised source path.
	 * @param string $match_type  exact|prefix.
	 * @return bool
	 */
	public static function is_reserved( $source_path, $match_type ) {
		if ( 'prefix' === $match_type && '/' === $source_path ) {
			return true;
		}

		foreach ( self::reserved_paths() as $reserved ) {
			if ( 0 === strcasecmp( $source_path, $reserved ) ) {
				return true;
			}

			if ( 0 === stripos( $source_path, $reserved . '/' ) ) {
				return true;
			}

			if ( 'prefix' === $match_type && 0 === stripos( $reserved, $source_path . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Status codes a route may use.
	 *
	 * @return array<int, int>
	 */
	public static function allowed_status_codes() {
		/**
		 * Filters the allowed redirect status codes.
		 *
		 * @param array<int, int> $codes Allowed HTTP status codes.
		 */
		return array_map( 'intval', apply_filters( 'wplinker_allowed_status_codes', array( 301, 302 ) ) );
	}

	/**
	 * Match types a route may use.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_match_types() {
		return array( 'exact', 'prefix' );
	}

	/**
	 * Validates and normalises a full set of route fields.
	 *
	 * @param array $input       Raw field values.
	 * @param int   $existing_id Route being updated, or 0 when creating.
	 * @return array|WP_Error Normalised columns, or the first error found.
	 */
	public static function validate( array $input, $existing_id = 0 ) {
		$existing_id = (int) $existing_id;
		$current     = $existing_id ? WPLinker_Routes::get( $existing_id ) : null;

		if ( $existing_id && ! $current ) {
			return new WP_Error( 'wplinker_not_found', __( 'Route not found.', 'wplinker' ), array( 'status' => 404 ) );
		}

		$raw_source      = self::pick( $input, array( 'source_path', 'source' ), $current ? $current->source_path : '' );
		$raw_destination = self::pick( $input, array( 'destination_url', 'destination' ), $current ? $current->destination_url : '' );
		$raw_status      = self::pick( $input, array( 'status_code', 'status' ), $current ? $current->status_code : 301 );
		$raw_match_type  = self::pick( $input, array( 'match_type', 'type' ), $current ? $current->match_type : '' );

		$parsed      = self::parse_source( $raw_source );
		$source_path = $parsed['path'];

		// A trailing wildcard always wins; otherwise fall back to the supplied
		// type, then to the existing value, then to an exact match.
		$match_type = $parsed['match_type'];

		if ( ! $match_type ) {
			$match_type = strtolower( trim( (string) $raw_match_type ) );
			$match_type = $match_type ? $match_type : 'exact';
		}

		if ( 'wildcard' === $match_type ) {
			$match_type = 'prefix';
		}

		if ( '' === $source_path ) {
			return new WP_Error( 'wplinker_invalid_source', __( 'A source path is required.', 'wplinker' ), array( 'status' => 400 ) );
		}

		if ( strlen( $source_path ) > 255 ) {
			return new WP_Error( 'wplinker_invalid_source', __( 'The source path may not be longer than 255 characters.', 'wplinker' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $match_type, self::allowed_match_types(), true ) ) {
			return new WP_Error(
				'wplinker_invalid_match_type',
				__( 'The match type must be either "exact" or "prefix".', 'wplinker' ),
				array( 'status' => 400 )
			);
		}

		if ( self::is_reserved( $source_path, $match_type ) ) {
			return new WP_Error(
				'wplinker_reserved_source',
				__( 'That source path is reserved by WordPress and cannot be redirected.', 'wplinker' ),
				array( 'status' => 400 )
			);
		}

		$status_code = (int) $raw_status;

		if ( ! in_array( $status_code, self::allowed_status_codes(), true ) ) {
			return new WP_Error(
				'wplinker_invalid_status',
				sprintf(
					/* translators: %s: comma separated list of allowed status codes. */
					__( 'The status code must be one of: %s.', 'wplinker' ),
					implode( ', ', self::allowed_status_codes() )
				),
				array( 'status' => 400 )
			);
		}

		$destination_url = self::normalize_destination( $raw_destination );

		if ( is_wp_error( $destination_url ) ) {
			return $destination_url;
		}

		if ( self::causes_loop( $source_path, $match_type, $destination_url ) ) {
			return new WP_Error(
				'wplinker_redirect_loop',
				__( 'The destination resolves back to the source path, which would create a redirect loop.', 'wplinker' ),
				array( 'status' => 400 )
			);
		}

		$duplicate = WPLinker_Routes::get_by_source( $source_path, $match_type );

		if ( $duplicate && $duplicate->id !== $existing_id ) {
			return new WP_Error(
				'wplinker_duplicate_route',
				__( 'A route for that source path and match type already exists.', 'wplinker' ),
				array( 'status' => 409 )
			);
		}

		return array(
			'source_path'     => $source_path,
			'destination_url' => $destination_url,
			'status_code'     => $status_code,
			'match_type'      => $match_type,
		);
	}

	/**
	 * Validates a destination, which may be an absolute URL or a site relative path.
	 *
	 * @param string $raw Raw destination.
	 * @return string|WP_Error
	 */
	public static function normalize_destination( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return new WP_Error( 'wplinker_invalid_destination', __( 'A destination URL is required.', 'wplinker' ), array( 'status' => 400 ) );
		}

		if ( '/' === substr( $raw, 0, 1 ) ) {
			// Site relative destination: keep the path, query and fragment intact.
			$clean = wp_kses_decode_entities( $raw );
			$clean = preg_replace( '#[\r\n\t]#', '', $clean );

			return $clean;
		}

		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return new WP_Error(
				'wplinker_invalid_destination',
				__( 'The destination must be an http(s) URL or a site relative path beginning with a slash.', 'wplinker' ),
				array( 'status' => 400 )
			);
		}

		$clean = esc_url_raw( $raw, array( 'http', 'https' ) );

		if ( ! $clean ) {
			return new WP_Error(
				'wplinker_invalid_destination',
				__( 'The destination URL is not valid.', 'wplinker' ),
				array( 'status' => 400 )
			);
		}

		return $clean;
	}

	/**
	 * Detects a destination that would immediately be matched by its own route.
	 *
	 * Only same host destinations can loop, so external URLs are always allowed
	 * even when they happen to contain the source path as a substring.
	 *
	 * @param string $source_path Normalised source path.
	 * @param string $match_type  exact|prefix.
	 * @param string $destination Destination URL or path.
	 * @return bool
	 */
	public static function causes_loop( $source_path, $match_type, $destination ) {
		$destination_host = wp_parse_url( $destination, PHP_URL_HOST );
		$home_host        = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $destination_host && 0 !== strcasecmp( $destination_host, (string) $home_host ) ) {
			return false;
		}

		$destination_path = self::normalize_path( (string) wp_parse_url( $destination, PHP_URL_PATH ) );

		if ( 'prefix' === $match_type ) {
			return self::path_matches_prefix( $destination_path, $source_path );
		}

		return 0 === strcasecmp( $destination_path, $source_path );
	}

	/**
	 * Whether a path falls under a prefix source, respecting segment boundaries.
	 *
	 * `/docs` matches `/docs` and `/docs/api`, but never `/docs-archive`.
	 *
	 * @param string $path        Normalised request path.
	 * @param string $source_path Normalised prefix source path.
	 * @return bool
	 */
	public static function path_matches_prefix( $path, $source_path ) {
		if ( '/' === $source_path ) {
			return true;
		}

		if ( 0 === strcasecmp( $path, $source_path ) ) {
			return true;
		}

		return 0 === stripos( $path, $source_path . '/' );
	}

	/**
	 * Reads the first present key from an input array.
	 *
	 * Both the HLD payload names (source, destination, status, type) and the
	 * column names are accepted so the API reads naturally either way.
	 *
	 * @param array        $input   Input array.
	 * @param array<int, string> $keys Keys to try, in order.
	 * @param mixed        $default Fallback when no key is present.
	 * @return mixed
	 */
	private static function pick( array $input, array $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				return $input[ $key ];
			}
		}

		return $default;
	}
}
