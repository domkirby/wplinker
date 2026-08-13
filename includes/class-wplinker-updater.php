<?php
/**
 * Plugin updates served from GitHub Releases.
 *
 * WordPress calls `update_plugins_{$hostname}` for any plugin carrying an
 * `Update URI` header, where the hostname comes from that header. Core then
 * sorts what we return into the update transient's `response` or `no_update`
 * bucket, which is what makes the core auto-update toggle work. Using that
 * header also stops WordPress.org from ever serving an update for this plugin,
 * so the `wplinker` slug cannot be hijacked there.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub Releases update provider.
 */
class WPLinker_Updater {

	/**
	 * Site transient holding the last check.
	 */
	const TRANSIENT = 'wplinker_update_check';

	/**
	 * How long a successful check is reused.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed check is reused.
	 *
	 * Failures are cached deliberately: GitHub allows 60 unauthenticated calls
	 * per hour per IP, shared by every site on the host, so retrying on each
	 * admin page load would keep the API permanently rate limited.
	 */
	const FAILURE_TTL = HOUR_IN_SECONDS;

	/**
	 * Source repository.
	 */
	const REPOSITORY = 'domkirby/wplinker';

	/**
	 * Release asset preferred over the auto-generated zipball.
	 */
	const ASSET_NAME = 'wplinker.zip';

	/**
	 * Hosts a package may be downloaded from.
	 */
	const ALLOWED_PACKAGE_HOSTS = array(
		'github.com',
		'api.github.com',
		'codeload.github.com',
		'objects.githubusercontent.com',
		'release-assets.githubusercontent.com',
	);

	/**
	 * Singleton instance.
	 *
	 * @var WPLinker_Updater|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return WPLinker_Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the updater into the update lifecycle.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'check_for_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source' ), 10, 4 );
		add_filter( 'plugin_action_links_' . self::basename(), array( $this, 'action_links' ) );
		add_action( 'admin_post_wplinker_check_update', array( $this, 'handle_manual_check' ) );
		add_action( 'admin_notices', array( $this, 'render_check_notice' ) );
	}

	/**
	 * Plugin basename, e.g. wplinker/wplinker.php.
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( WPLINKER_FILE );
	}

	/**
	 * Installed directory name, which is not necessarily "wplinker".
	 *
	 * @return string
	 */
	public static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * Supplies the update payload for this plugin.
	 *
	 * @param array|false $update      Update offered by an earlier filter.
	 * @param array       $plugin_data Plugin header data.
	 * @param string      $plugin_file Plugin basename being checked.
	 * @return array|false
	 */
	public function check_for_update( $update, $plugin_data, $plugin_file ) {
		// The hostname based filter fires for every plugin updated from
		// github.com, so answer only for our own file.
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $update;
		}

		$current = ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : WPLINKER_VERSION;
		$headers = self::plugin_headers();

		// Core requires `version` and derives `new_version` from it; both are
		// sent so the payload is also usable by anything reading the transient
		// directly. Core overwrites `id` and `plugin` itself.
		return array(
			'id'           => 'https://github.com/' . self::REPOSITORY,
			'slug'         => self::slug(),
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => self::is_newer( $release['version'], $current ) ? $release['package'] : '',
			'tested'       => $headers['tested'],
			'requires'     => $headers['requires'],
			'requires_php' => $headers['requires_php'],
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
		);
	}

	/**
	 * Fills the "View version details" modal.
	 *
	 * @param false|object|array $result Result from an earlier filter.
	 * @param string             $action Requested action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $result;
		}

		$headers = self::plugin_headers();

		return (object) array(
			'name'          => $headers['name'],
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => $headers['author'],
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'download_link' => $release['package'],
			'trunk'         => $release['package'],
			'requires'      => $headers['requires'],
			'tested'        => $headers['tested'],
			'requires_php'  => $headers['requires_php'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => wpautop( esc_html( $headers['description'] ) ),
				'changelog'   => self::format_changelog( $release['changelog'] ),
			),
		);
	}

	/**
	 * Renames the extracted folder to the directory the plugin already lives in.
	 *
	 * A GitHub zipball extracts as `owner-repo-<sha>/`, and even a well built
	 * asset can disagree with a site that installed the plugin under a
	 * different folder name. Renaming to the *installed* directory rather than
	 * a hardcoded one is what keeps the plugin active across the update.
	 *
	 * @param string      $source        Path the package was extracted to.
	 * @param string      $remote_source Parent working directory.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra arguments describing the upgrade.
	 * @return string|WP_Error
	 */
	public function rename_source( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;

		$desired = trailingslashit( $remote_source ) . self::slug();

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $desired, true ) ) {
			return new WP_Error(
				'wplinker_rename_failed',
				__( 'Could not rename the downloaded package to the plugin directory.', 'wplinker' )
			);
		}

		return trailingslashit( $desired );
	}

	/**
	 * Adds a manual check link to the plugin row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'action', 'wplinker_check_update', admin_url( 'admin-post.php' ) ),
			'wplinker_check_update'
		);

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Check for updates', 'wplinker' )
		);

		return $links;
	}

	/**
	 * Discards the cached check and asks WordPress to look again.
	 *
	 * @return void
	 */
	public function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'wplinker' ), 403 );
		}

		check_admin_referer( 'wplinker_check_update' );

		$this->flush();

		// wp_update_plugins() throttles itself against its own transient's
		// last_checked stamp and would otherwise return without re-running the
		// update filters, making this link look like it did nothing.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$updates   = get_site_transient( 'update_plugins' );
		$available = isset( $updates->response[ self::basename() ] );

		wp_safe_redirect(
			add_query_arg( 'wplinker_checked', $available ? 'update' : 'current', admin_url( 'plugins.php' ) )
		);
		exit;
	}

	/**
	 * Reports the outcome of a manual check.
	 *
	 * @return void
	 */
	public function render_check_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; the check itself was nonce protected.
		$checked = isset( $_GET['wplinker_checked'] ) ? sanitize_key( wp_unslash( $_GET['wplinker_checked'] ) ) : '';

		if ( ! $checked || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$text = 'update' === $checked
			? __( 'WPLinker: a new version is available.', 'wplinker' )
			: __( 'WPLinker is up to date.', 'wplinker' );

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html( $text )
		);
	}

	/**
	 * Discards the cached release.
	 *
	 * @return void
	 */
	public function flush() {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Returns the latest release, from cache when possible.
	 *
	 * @return array|null
	 */
	public function get_release() {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) && ! $this->is_force_check() ) {
			return isset( $cached['release'] ) && is_array( $cached['release'] ) ? $cached['release'] : null;
		}

		$payload = $this->fetch_release();
		$release = is_array( $payload ) ? self::parse_release( $payload ) : null;

		/**
		 * Filters the parsed release.
		 *
		 * Returning a hand built array here simulates an available update
		 * without publishing one, which is the practical way to test the
		 * upgrade path locally.
		 *
		 * @param array|null $release Parsed release data, or null.
		 */
		$release = apply_filters( 'wplinker_updater_release_data', $release );

		set_site_transient(
			self::TRANSIENT,
			array(
				'release'    => $release,
				'checked_at' => time(),
			),
			$release ? self::CACHE_TTL : self::FAILURE_TTL
		);

		return $release;
	}

	/**
	 * Requests the latest release from the GitHub API.
	 *
	 * Any failure returns null: a rate limited or unreachable GitHub must never
	 * surface as a warning or a broken Updates screen.
	 *
	 * @return array|null
	 */
	private function fetch_release() {
		$args = array(
			'timeout'    => 10,
			'headers'    => array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
			'user-agent' => 'WPLinker/' . WPLINKER_VERSION . '; ' . home_url( '/' ),
		);

		// Optional, and deliberately a constant rather than an option: a token
		// in the database would be readable through exports and backups.
		if ( defined( 'WPLINKER_GITHUB_TOKEN' ) && WPLINKER_GITHUB_TOKEN ) {
			$args['headers']['Authorization'] = 'Bearer ' . WPLINKER_GITHUB_TOKEN;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			$args
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Reduces a GitHub release payload to the fields the updater needs.
	 *
	 * @param array $release Decoded release payload.
	 * @return array|null Parsed release, or null when it is not installable.
	 */
	public static function parse_release( $release ) {
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return null;
		}

		if ( ! empty( $release['draft'] ) ) {
			return null;
		}

		/**
		 * Filters whether prereleases are offered as updates.
		 *
		 * @param bool $allow Whether to offer prereleases. Default false.
		 */
		if ( ! empty( $release['prerelease'] ) && ! apply_filters( 'wplinker_updater_allow_prereleases', false ) ) {
			return null;
		}

		$version = self::normalize_version( $release['tag_name'] );

		if ( '' === $version ) {
			return null;
		}

		$package = self::select_package( $release );

		if ( '' === $package ) {
			return null;
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'url'          => ! empty( $release['html_url'] ) ? $release['html_url'] : 'https://github.com/' . self::REPOSITORY . '/releases',
			'changelog'    => ! empty( $release['body'] ) ? (string) $release['body'] : '',
			'published_at' => ! empty( $release['published_at'] ) ? (string) $release['published_at'] : '',
		);
	}

	/**
	 * Chooses the download for a release.
	 *
	 * A purpose built asset is preferred because it carries the correct folder
	 * name and leaves development files out; the zipball is the fallback.
	 *
	 * @param array $release Decoded release payload.
	 * @return string Package URL, or an empty string when none is usable.
	 */
	public static function select_package( $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['name'] ) || self::ASSET_NAME !== $asset['name'] ) {
					continue;
				}

				if ( ! empty( $asset['browser_download_url'] ) && self::is_allowed_package_host( $asset['browser_download_url'] ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		if ( ! empty( $release['zipball_url'] ) && self::is_allowed_package_host( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}

		return '';
	}

	/**
	 * Turns a release tag into a comparable version string.
	 *
	 * @param string $tag Release tag, such as v0.2.0.
	 * @return string Version, or an empty string when the tag is not a version.
	 */
	public static function normalize_version( $tag ) {
		$version = preg_replace( '/^v/i', '', trim( (string) $tag ) );

		return preg_match( '/^\d+(\.\d+)*/', $version ) ? $version : '';
	}

	/**
	 * Whether a package URL points at GitHub.
	 *
	 * Defence in depth on top of TLS: a spoofed or tampered API response must
	 * not be able to hand WordPress an arbitrary zip to install.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	public static function is_allowed_package_host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		foreach ( self::ALLOWED_PACKAGE_HOSTS as $allowed ) {
			if ( 0 === strcasecmp( $host, $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a released version supersedes the installed one.
	 *
	 * @param string $version Released version.
	 * @param string $current Installed version.
	 * @return bool
	 */
	public static function is_newer( $version, $current ) {
		return version_compare( $version, $current, '>' );
	}

	/**
	 * Renders a release body for the details modal.
	 *
	 * Release notes are Markdown; they are escaped rather than parsed, so
	 * nothing in a release body can inject markup into the admin.
	 *
	 * @param string $changelog Raw release body.
	 * @return string
	 */
	private static function format_changelog( $changelog ) {
		if ( '' === trim( (string) $changelog ) ) {
			return wpautop( esc_html__( 'No release notes provided.', 'wplinker' ) );
		}

		return wpautop( make_clickable( esc_html( $changelog ) ) );
	}

	/**
	 * Whether the user explicitly asked for a fresh check.
	 *
	 * @return bool
	 */
	private function is_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own argument on update-core.php.
		return ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
	}

	/**
	 * Reads the values the update payload needs straight from the plugin header.
	 *
	 * Keeping these out of constants means the release workflow only has to
	 * keep the header and WPLINKER_VERSION in step.
	 *
	 * @return array<string, string>
	 */
	private static function plugin_headers() {
		static $headers = null;

		if ( null !== $headers ) {
			return $headers;
		}

		$data = get_file_data(
			WPLINKER_FILE,
			array(
				'name'         => 'Plugin Name',
				'description'  => 'Description',
				'author'       => 'Author',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
				'tested'       => 'Tested up to',
			)
		);

		/**
		 * Filters the WordPress version reported as tested.
		 *
		 * Read from the plugin header rather than the running WordPress
		 * version: reporting the site's own version would claim compatibility
		 * with every release automatically, including ones never tested.
		 *
		 * @param string $tested Version string.
		 */
		$data['tested'] = (string) apply_filters( 'wplinker_updater_tested_up_to', $data['tested'] );

		$headers = $data;

		return $headers;
	}
}
