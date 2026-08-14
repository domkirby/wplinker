<?php
/**
 * Dependency free smoke tests for the pure routing logic.
 *
 * These cover path normalisation, prefix matching, loop detection and subpath
 * resolution without booting WordPress, so they can run in CI with just PHP:
 *
 *     php tests/smoke-test.php
 *
 * @package WPLinker
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_DEBUG', true );

$GLOBALS['wplinker_test_home']    = 'https://example.com';
$GLOBALS['wplinker_test_filters'] = array();
$GLOBALS['wplinker_test_caps']    = array();
$GLOBALS['wplinker_test_roles']   = array();
$GLOBALS['wplinker_test_notices'] = array();

/**
 * Minimal stubs for the handful of WordPress functions the logic touches.
 */
function home_url( $path = '' ) {
	return rtrim( $GLOBALS['wplinker_test_home'], '/' ) . ( $path ? '/' . ltrim( $path, '/' ) : '' );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function apply_filters( $hook, $value ) {
	if ( isset( $GLOBALS['wplinker_test_filters'][ $hook ] ) ) {
		return $GLOBALS['wplinker_test_filters'][ $hook ];
	}

	return $value;
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['wplinker_test_caps'][ $capability ] );
}

function rest_authorization_required_code() {
	return 403;
}

function wp_roles() {
	return (object) array( 'roles' => $GLOBALS['wplinker_test_roles'] );
}

function _doing_it_wrong( $function_name, $message, $version ) {
	$GLOBALS['wplinker_test_notices'][] = $message;
}

/**
 * The REST controller only needs the base class to exist; none of the parent
 * behaviour is exercised here.
 */
class WP_REST_Controller {
	protected $namespace;
	protected $rest_base;
	protected $schema;
}

function do_action( $hook ) {}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

function wp_kses_decode_entities( $text ) {
	return $text;
}

function rest_get_url_prefix() {
	return 'wp-json';
}

function wp_unslash( $value ) {
	return $value;
}

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require_once __DIR__ . '/../includes/class-wplinker-validator.php';
require_once __DIR__ . '/../includes/class-wplinker-router.php';
require_once __DIR__ . '/../includes/class-wplinker-updater.php';
require_once __DIR__ . '/../includes/class-wplinker-rest-controller.php';

$failures = 0;
$total    = 0;

/**
 * Tiny assertion helper.
 *
 * @param string $label    Test name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
function check( $label, $expected, $actual ) {
	global $failures, $total;

	++$total;

	if ( $expected === $actual ) {
		echo "  ok  {$label}\n";

		return;
	}

	++$failures;
	echo "FAIL  {$label}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Builds a route row.
 *
 * @param string $source      Source path.
 * @param string $destination Destination URL.
 * @param string $match_type  exact|prefix.
 * @return object
 */
function route( $source, $destination, $match_type = 'exact' ) {
	return (object) array(
		'id'              => 1,
		'source_path'     => $source,
		'destination_url' => $destination,
		'status_code'     => 301,
		'match_type'      => $match_type,
		'clicks'          => 0,
	);
}

echo "Path normalisation\n";
check( 'adds a leading slash', '/promo', WPLinker_Validator::normalize_path( 'promo' ) );
check( 'strips a trailing slash', '/promo', WPLinker_Validator::normalize_path( '/promo/' ) );
check( 'keeps the root path', '/', WPLinker_Validator::normalize_path( '/' ) );
check( 'collapses double slashes', '/a/b', WPLinker_Validator::normalize_path( '//a//b' ) );
check( 'drops the query string', '/promo', WPLinker_Validator::normalize_path( '/promo?utm=1' ) );
check( 'reduces a full URL to its path', '/promo', WPLinker_Validator::normalize_path( 'https://example.com/promo' ) );

echo "\nSource parsing\n";
$parsed = WPLinker_Validator::parse_source( '/docs/*' );
check( 'wildcard implies a prefix route', 'prefix', $parsed['match_type'] );
check( 'wildcard is stripped from the path', '/docs', $parsed['path'] );

echo "\nPrefix matching respects segment boundaries\n";
check( 'matches the base path', true, WPLinker_Validator::path_matches_prefix( '/docs', '/docs' ) );
check( 'matches a subpath', true, WPLinker_Validator::path_matches_prefix( '/docs/api/v1', '/docs' ) );
check( 'does not match a sibling', false, WPLinker_Validator::path_matches_prefix( '/docs-archive', '/docs' ) );

echo "\nReserved paths\n";
check( 'blocks /wp-admin', true, WPLinker_Validator::is_reserved( '/wp-admin', 'exact' ) );
check( 'blocks paths under /wp-json', true, WPLinker_Validator::is_reserved( '/wp-json/wp/v2', 'exact' ) );
check( 'blocks a catch all prefix', true, WPLinker_Validator::is_reserved( '/', 'prefix' ) );
check( 'blocks a prefix containing a reserved path', true, WPLinker_Validator::is_reserved( '/wp-content', 'prefix' ) );
check( 'allows an ordinary path', false, WPLinker_Validator::is_reserved( '/promo', 'exact' ) );

echo "\nLoop detection\n";
check( 'same path on the same host loops', true, WPLinker_Validator::causes_loop( '/promo', 'exact', 'https://example.com/promo' ) );
check( 'relative self reference loops', true, WPLinker_Validator::causes_loop( '/promo', 'exact', '/promo' ) );
check( 'subpath of a prefix source loops', true, WPLinker_Validator::causes_loop( '/docs', 'prefix', '/docs/new' ) );
check( 'different local path is fine', false, WPLinker_Validator::causes_loop( '/promo', 'exact', '/new-promo' ) );
check( 'external host with the same path is fine', false, WPLinker_Validator::causes_loop( '/promo', 'exact', 'https://other.test/promo' ) );

echo "\nCandidate paths\n";
$router = WPLinker_Router::instance();
check(
	'walks the path back to the root',
	array( '/a/b/c', '/a', '/a/b', '/' ),
	$router->candidate_paths( '/a/b/c' )
);

echo "\nSubpath resolution\n";
check(
	'appends the remainder to the destination',
	'https://new-domain.com/documentation/api/v1/auth',
	$router->resolve_destination( route( '/docs', 'https://new-domain.com/documentation/', 'prefix' ), '/docs/api/v1/auth' )
);
check(
	'base path of a prefix route hits the destination itself',
	'https://new-domain.com/documentation',
	$router->resolve_destination( route( '/docs', 'https://new-domain.com/documentation', 'prefix' ), '/docs' )
);
check(
	'keeps the destination query string when appending',
	'https://new-domain.com/docs/api?ref=wp',
	$router->resolve_destination( route( '/docs', 'https://new-domain.com/docs?ref=wp', 'prefix' ), '/docs/api' )
);
check(
	'exact routes use the destination verbatim',
	'https://example.com/new-promo',
	$router->resolve_destination( route( '/promo', 'https://example.com/new-promo' ), '/promo' )
);
check(
	'site relative destinations are expanded',
	'https://example.com/new-promo',
	$router->resolve_destination( route( '/promo', '/new-promo' ), '/promo' )
);

$_SERVER['QUERY_STRING'] = 'utm_source=email';
check(
	'forwards the incoming query string',
	'https://new-domain.com/documentation/api?utm_source=email',
	$router->resolve_destination( route( '/docs', 'https://new-domain.com/documentation', 'prefix' ), '/docs/api' )
);
check(
	'merges with an existing destination query string',
	'https://new-domain.com/go?ref=wp&utm_source=email',
	$router->resolve_destination( route( '/promo', 'https://new-domain.com/go?ref=wp' ), '/promo' )
);
unset( $_SERVER['QUERY_STRING'] );

echo "\nRequest path\n";
$_SERVER['REQUEST_URI'] = '/docs/api/v1?utm=1';
check( 'derives the path from REQUEST_URI', '/docs/api/v1', $router->current_path() );
unset( $_SERVER['REQUEST_URI'] );

echo "\nRelease tag parsing\n";
check( 'strips a leading v', '0.2.0', WPLinker_Updater::normalize_version( 'v0.2.0' ) );
check( 'accepts a bare version', '0.2.0', WPLinker_Updater::normalize_version( '0.2.0' ) );
check( 'strips an uppercase V', '1.0', WPLinker_Updater::normalize_version( 'V1.0' ) );
check( 'keeps a prerelease suffix', '1.0.0-beta.1', WPLinker_Updater::normalize_version( 'v1.0.0-beta.1' ) );
check( 'rejects a non version tag', '', WPLinker_Updater::normalize_version( 'nightly' ) );

echo "\nPackage host allowlist\n";
check( 'allows github.com', true, WPLinker_Updater::is_allowed_package_host( 'https://github.com/o/r/releases/download/v1/wplinker.zip' ) );
check( 'allows the asset CDN', true, WPLinker_Updater::is_allowed_package_host( 'https://objects.githubusercontent.com/x' ) );
check( 'rejects another host', false, WPLinker_Updater::is_allowed_package_host( 'https://evil.example/wplinker.zip' ) );
check( 'rejects a hostless value', false, WPLinker_Updater::is_allowed_package_host( 'wplinker.zip' ) );

/**
 * Builds a GitHub release payload.
 *
 * @param array $overrides Fields to override.
 * @return array
 */
function release_payload( $overrides = array() ) {
	return array_merge(
		array(
			'tag_name'     => 'v0.2.0',
			'draft'        => false,
			'prerelease'   => false,
			'html_url'     => 'https://github.com/domkirby/wplinker/releases/tag/v0.2.0',
			'body'         => 'Notes.',
			'published_at' => '2026-08-13T00:00:00Z',
			'zipball_url'  => 'https://api.github.com/repos/domkirby/wplinker/zipball/v0.2.0',
			'assets'       => array(),
		),
		$overrides
	);
}

echo "\nRelease selection\n";
$parsed = WPLinker_Updater::parse_release( release_payload() );
check( 'parses the version', '0.2.0', $parsed['version'] );
check( 'falls back to the zipball', 'https://api.github.com/repos/domkirby/wplinker/zipball/v0.2.0', $parsed['package'] );

$with_asset = WPLinker_Updater::parse_release(
	release_payload(
		array(
			'assets' => array(
				array(
					'name'                 => 'wplinker.zip',
					'browser_download_url' => 'https://github.com/domkirby/wplinker/releases/download/v0.2.0/wplinker.zip',
				),
			),
		)
	)
);
check(
	'prefers the built asset over the zipball',
	'https://github.com/domkirby/wplinker/releases/download/v0.2.0/wplinker.zip',
	$with_asset['package']
);

$other_asset = WPLinker_Updater::parse_release(
	release_payload(
		array(
			'assets' => array(
				array(
					'name'                 => 'checksums.txt',
					'browser_download_url' => 'https://github.com/domkirby/wplinker/releases/download/v0.2.0/checksums.txt',
				),
			),
		)
	)
);
check( 'ignores unrelated assets', 'https://api.github.com/repos/domkirby/wplinker/zipball/v0.2.0', $other_asset['package'] );

$hijacked = WPLinker_Updater::parse_release(
	release_payload(
		array(
			'assets' => array(
				array(
					'name'                 => 'wplinker.zip',
					'browser_download_url' => 'https://evil.example/wplinker.zip',
				),
			),
		)
	)
);
check( 'refuses an off-GitHub asset and falls back', 'https://api.github.com/repos/domkirby/wplinker/zipball/v0.2.0', $hijacked['package'] );

check( 'skips drafts', null, WPLinker_Updater::parse_release( release_payload( array( 'draft' => true ) ) ) );
check( 'skips prereleases by default', null, WPLinker_Updater::parse_release( release_payload( array( 'prerelease' => true ) ) ) );
check( 'skips a release with no package', null, WPLinker_Updater::parse_release( release_payload( array( 'zipball_url' => '' ) ) ) );
check( 'skips a release with no tag', null, WPLinker_Updater::parse_release( release_payload( array( 'tag_name' => '' ) ) ) );
check( 'skips an unparseable payload', null, WPLinker_Updater::parse_release( 'not-a-release' ) );

echo "\nUpdate decision\n";
check( 'newer version updates', true, WPLinker_Updater::is_newer( '0.2.0', '0.1.0' ) );
check( 'same version does not', false, WPLinker_Updater::is_newer( '0.1.0', '0.1.0' ) );
check( 'older version does not', false, WPLinker_Updater::is_newer( '0.0.9', '0.1.0' ) );
check( 'a prerelease is older than its release', true, WPLinker_Updater::is_newer( '1.0.0', '1.0.0-beta.1' ) );

echo "\nREST capabilities\n";

/**
 * Points a filter hook at a fixed value for the next check.
 *
 * @param string $hook  Hook name.
 * @param string $value Value the hook returns.
 * @return void
 */
function filter_returns( $hook, $value ) {
	$GLOBALS['wplinker_test_filters'][ $hook ] = $value;
}

/**
 * Clears the filter registry between checks.
 *
 * @return void
 */
function reset_filters() {
	$GLOBALS['wplinker_test_filters'] = array();
}

check( 'read defaults to manage_options', 'manage_options', WPLinker_REST_Routes_Controller::read_capability() );
check( 'write defaults to manage_options', 'manage_options', WPLinker_REST_Routes_Controller::write_capability() );

filter_returns( 'wplinker_rest_capability', 'edit_posts' );
check( 'the legacy filter still lowers read', 'edit_posts', WPLinker_REST_Routes_Controller::read_capability() );
check( 'the legacy filter cannot lower write', 'manage_options', WPLinker_REST_Routes_Controller::write_capability() );

filter_returns( 'wplinker_rest_read_capability', 'publish_posts' );
check( 'the read filter overrides the legacy filter', 'publish_posts', WPLinker_REST_Routes_Controller::read_capability() );

reset_filters();
filter_returns( 'wplinker_rest_write_capability', 'edit_pages' );
check( 'the write filter moves write', 'edit_pages', WPLinker_REST_Routes_Controller::write_capability() );
check( 'the write filter leaves read alone', 'manage_options', WPLinker_REST_Routes_Controller::read_capability() );

reset_filters();
filter_returns( 'wplinker_rest_capability', 'edit_posts' );
$GLOBALS['wplinker_test_caps'] = array( 'edit_posts' => true );

$controller = new WPLinker_REST_Routes_Controller();
$denied     = $controller->write_permissions_check();

check( 'a read-only role may read', true, $controller->permissions_check() );
check( 'a read-only role may not write', 'wplinker_forbidden', is_wp_error( $denied ) ? $denied->get_error_code() : 'allowed' );

$GLOBALS['wplinker_test_caps']  = array( 'manage_options' => true );
$GLOBALS['wplinker_test_roles'] = array(
	'editor'        => array( 'capabilities' => array( 'edit_posts' => true, 'wplinker_weak_cap' => true ) ),
	'administrator' => array( 'capabilities' => array( 'manage_options' => true, 'wplinker_admin_cap' => true ) ),
);

reset_filters();
check( 'an administrator may write', true, $controller->write_permissions_check() );
check( 'the default write capability raises no notice', 0, count( $GLOBALS['wplinker_test_notices'] ) );

filter_returns( 'wplinker_rest_write_capability', 'wplinker_admin_cap' );
WPLinker_REST_Routes_Controller::write_capability();
check( 'an admin only capability raises no notice', 0, count( $GLOBALS['wplinker_test_notices'] ) );

filter_returns( 'wplinker_rest_write_capability', 'wplinker_weak_cap' );
WPLinker_REST_Routes_Controller::write_capability();
check( 'a capability a non-admin role holds raises a notice', 1, count( $GLOBALS['wplinker_test_notices'] ) );

WPLinker_REST_Routes_Controller::write_capability();
check( 'the notice is not repeated for the same capability', 1, count( $GLOBALS['wplinker_test_notices'] ) );

reset_filters();

echo "\n{$total} checks, {$failures} failed\n";

exit( $failures > 0 ? 1 : 0 );
