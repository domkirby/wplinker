<?php
/**
 * REST API surface for the routing table.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes full CRUD over routes at custom-routes/v1/routes.
 */
class WPLinker_REST_Routes_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'custom-routes/v1';
		$this->rest_base = 'routes';
	}

	/**
	 * Registers the collection and item endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => $this->get_write_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the route.', 'wplinker' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => $this->get_write_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Capability required to read routes.
	 *
	 * Reading a route is ordinary content management, so this tier is safe to
	 * lower for an editor or marketing role.
	 *
	 * @return string
	 */
	public static function read_capability() {
		/**
		 * Filters the capability required to use the routes REST API.
		 *
		 * @deprecated 0.2.0 Use wplinker_rest_read_capability instead. This filter now
		 *                   only affects reads; writes are governed by
		 *                   wplinker_rest_write_capability so that loosening read access
		 *                   cannot hand out redirect creation by accident.
		 *
		 * @param string $capability Capability name.
		 */
		$capability = apply_filters( 'wplinker_rest_capability', 'manage_options' );

		/**
		 * Filters the capability required to read routes through the REST API.
		 *
		 * @param string $capability Capability name.
		 */
		return apply_filters( 'wplinker_rest_read_capability', $capability );
	}

	/**
	 * Capability required to create, update or delete routes.
	 *
	 * Writing a route means pointing a path on this site at any destination, so
	 * this tier must stay at a trusted, site-wide-admin-equivalent role. Lowering
	 * it hands the role a phishing primitive: arbitrary external redirects served
	 * from the site's own trusted domain.
	 *
	 * @return string
	 */
	public static function write_capability() {
		/**
		 * Filters the capability required to write routes through the REST API.
		 *
		 * Never set this below an administrator-equivalent capability. A user who
		 * can write routes can redirect any path on this site to any destination.
		 *
		 * @param string $capability Capability name.
		 */
		$capability = apply_filters( 'wplinker_rest_write_capability', 'manage_options' );

		self::warn_on_weak_write_capability( $capability );

		return $capability;
	}

	/**
	 * Read capability check, applied to the GET endpoints.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		return $this->check_capability( self::read_capability() );
	}

	/**
	 * Write capability check, applied to the POST, PUT/PATCH and DELETE endpoints.
	 *
	 * @return true|WP_Error
	 */
	public function write_permissions_check() {
		return $this->check_capability( self::write_capability(), __( 'You are not allowed to create or modify routes.', 'wplinker' ) );
	}

	/**
	 * Shared capability gate.
	 *
	 * @param string $capability Capability the current user must hold.
	 * @param string $message    Optional. Error message returned when they do not.
	 * @return true|WP_Error
	 */
	private function check_capability( $capability, $message = '' ) {
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'wplinker_forbidden',
				$message ? $message : __( 'You are not allowed to manage routes.', 'wplinker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Flags a write capability that a non-administrator role holds.
	 *
	 * Only runs under WP_DEBUG: it is a development aid for catching a filter that
	 * was meant to loosen read access, not a runtime check. The result is memoised
	 * per capability so a request never scans the roles twice.
	 *
	 * @param string $capability Filtered write capability.
	 * @return void
	 */
	private static function warn_on_weak_write_capability( $capability ) {
		static $warned = array();

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || 'manage_options' === $capability ) {
			return;
		}

		if ( isset( $warned[ $capability ] ) || ! function_exists( 'wp_roles' ) || ! function_exists( '_doing_it_wrong' ) ) {
			return;
		}

		$warned[ $capability ] = true;

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array();

			if ( empty( $caps[ $capability ] ) || ! empty( $caps['manage_options'] ) ) {
				continue;
			}

			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: capability name, 2: role slug. */
					__( 'wplinker_rest_write_capability was filtered to "%1$s", which the "%2$s" role holds without being an administrator. Writing routes allows arbitrary redirects from this site to any destination, so this capability should stay administrator-equivalent.', 'wplinker' ),
					$capability,
					$slug
				),
				'0.2.0'
			);

			return;
		}
	}

	/**
	 * Lists routes.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = array(
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
			'search'   => (string) $request['search'],
			'orderby'  => (string) $request['orderby'],
			'order'    => (string) $request['order'],
		);

		if ( $request['match_type'] ) {
			$args['match_type'] = (string) $request['match_type'];
		}

		if ( $request['status_code'] ) {
			$args['status_code'] = (int) $request['status_code'];
		}

		$routes = WPLinker_Routes::query( $args );
		$total  = WPLinker_Routes::count( $args );
		$items  = array();

		foreach ( $routes as $route ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $route, $request ) );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 1 );

		return $response;
	}

	/**
	 * Retrieves a single route.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$route = WPLinker_Routes::get( (int) $request['id'] );

		if ( ! $route ) {
			return $this->not_found();
		}

		return rest_ensure_response( $this->prepare_item_for_response( $route, $request ) );
	}

	/**
	 * Creates a route.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = WPLinker_Validator::validate( $this->extract_fields( $request ) );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id = WPLinker_Routes::insert( $data );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$route    = WPLinker_Routes::get( $id );
		$response = rest_ensure_response( $this->prepare_item_for_response( $route, $request ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $id ) ) );

		return $response;
	}

	/**
	 * Updates a route.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id    = (int) $request['id'];
		$route = WPLinker_Routes::get( $id );

		if ( ! $route ) {
			return $this->not_found();
		}

		$data = WPLinker_Validator::validate( $this->extract_fields( $request ), $id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$updated = WPLinker_Routes::update( $id, $data );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return rest_ensure_response( $this->prepare_item_for_response( WPLinker_Routes::get( $id ), $request ) );
	}

	/**
	 * Deletes a route.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id    = (int) $request['id'];
		$route = WPLinker_Routes::get( $id );

		if ( ! $route ) {
			return $this->not_found();
		}

		$previous = $this->prepare_item_for_response( $route, $request );

		if ( ! WPLinker_Routes::delete( $id ) ) {
			return new WP_Error(
				'wplinker_delete_failed',
				__( 'The route could not be deleted.', 'wplinker' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $previous->get_data(),
			)
		);
	}

	/**
	 * Shapes a row for output.
	 *
	 * @param object          $route   Route row.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $route, $request ) {
		$data = array(
			'id'              => (int) $route->id,
			'source_path'     => $route->source_path,
			'source_url'      => home_url( $route->source_path ),
			'destination_url' => $route->destination_url,
			'status_code'     => (int) $route->status_code,
			'match_type'      => $route->match_type,
			'clicks'          => (int) $route->clicks,
			'created_at'      => mysql_to_rfc3339( $route->created_at ),
			'updated_at'      => mysql_to_rfc3339( $route->updated_at ),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Item schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wplinker_route',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Unique identifier for the route.', 'wplinker' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'source_path'     => array(
					'description' => __( 'Site relative path to match, for example /promo. A trailing /* creates a prefix route.', 'wplinker' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'source_url'      => array(
					'description' => __( 'Absolute URL of the source path.', 'wplinker' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'destination_url' => array(
					'description' => __( 'Absolute URL or site relative path to redirect to.', 'wplinker' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'status_code'     => array(
					'description' => __( 'HTTP status code used for the redirect.', 'wplinker' ),
					'type'        => 'integer',
					'enum'        => WPLinker_Validator::allowed_status_codes(),
					'context'     => array( 'view', 'edit' ),
				),
				'match_type'      => array(
					'description' => __( 'How the source path is matched.', 'wplinker' ),
					'type'        => 'string',
					'enum'        => WPLinker_Validator::allowed_match_types(),
					'context'     => array( 'view', 'edit' ),
				),
				'clicks'          => array(
					'description' => __( 'Number of times the route has been served.', 'wplinker' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'created_at'      => array(
					'description' => __( 'Creation time, GMT.', 'wplinker' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'updated_at'      => array(
					'description' => __( 'Last modification time, GMT.', 'wplinker' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Arguments accepted by the create and update endpoints.
	 *
	 * Both the column names and the shorter aliases from the design document
	 * are accepted, so `source` and `source_path` behave identically.
	 *
	 * @return array
	 */
	public function get_write_params() {
		return array(
			'source_path'     => array(
				'description'       => __( 'Site relative path to match. A trailing /* creates a prefix route.', 'wplinker' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'source'          => array(
				'description'       => __( 'Alias of source_path.', 'wplinker' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'destination_url' => array(
				'description' => __( 'Absolute URL or site relative path to redirect to.', 'wplinker' ),
				'type'        => 'string',
			),
			'destination'     => array(
				'description' => __( 'Alias of destination_url.', 'wplinker' ),
				'type'        => 'string',
			),
			'status_code'     => array(
				'description' => __( 'HTTP status code used for the redirect.', 'wplinker' ),
				'type'        => 'integer',
			),
			'status'          => array(
				'description' => __( 'Alias of status_code.', 'wplinker' ),
				'type'        => 'integer',
			),
			'match_type'      => array(
				'description' => __( 'exact or prefix.', 'wplinker' ),
				'type'        => 'string',
			),
			'type'            => array(
				'description' => __( 'Alias of match_type.', 'wplinker' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * Collection query parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'page'        => array(
				'description'       => __( 'Current page of the collection.', 'wplinker' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'    => array(
				'description'       => __( 'Maximum number of items to return.', 'wplinker' ),
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'search'      => array(
				'description'       => __( 'Limit results to routes matching a string.', 'wplinker' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'     => array(
				'description' => __( 'Sort collection by route attribute.', 'wplinker' ),
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'source_path', 'destination_url', 'status_code', 'match_type', 'clicks', 'created_at', 'updated_at' ),
			),
			'order'       => array(
				'description' => __( 'Sort direction.', 'wplinker' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
			'match_type'  => array(
				'description' => __( 'Limit results to a match type.', 'wplinker' ),
				'type'        => 'string',
				'enum'        => WPLinker_Validator::allowed_match_types(),
			),
			'status_code' => array(
				'description' => __( 'Limit results to a status code.', 'wplinker' ),
				'type'        => 'integer',
			),
		);
	}

	/**
	 * Collects the writable fields present on a request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	private function extract_fields( $request ) {
		$fields = array();

		foreach ( array_keys( $this->get_write_params() ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$fields[ $key ] = $request->get_param( $key );
			}
		}

		return $fields;
	}

	/**
	 * Standard 404 response.
	 *
	 * @return WP_Error
	 */
	private function not_found() {
		return new WP_Error( 'wplinker_not_found', __( 'Route not found.', 'wplinker' ), array( 'status' => 404 ) );
	}
}
