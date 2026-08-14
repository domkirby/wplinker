<?php
/**
 * Admin screens, built from WordPress core components only.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu registration, form handling and notices.
 */
class WPLinker_Admin {

	/**
	 * Menu slug of the list screen.
	 */
	const PAGE_LIST = 'wplinker';

	/**
	 * Menu slug of the add/edit screen.
	 */
	const PAGE_EDIT = 'wplinker-edit-route';

	/**
	 * Singleton instance.
	 *
	 * @var WPLinker_Admin|null
	 */
	private static $instance = null;

	/**
	 * List table instance for the current screen.
	 *
	 * @var WPLinker_List_Table|null
	 */
	private $list_table = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return WPLinker_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the admin screens up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_post_wplinker_save_route', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wplinker_delete_route', array( $this, 'handle_delete' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_wplinker_per_page', array( __CLASS__, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Capability required for every screen and action.
	 *
	 * This one capability covers reading the route list *and* the Add Route,
	 * edit and delete actions, so lowering it is not a read-only concession:
	 * the role also gains the ability to redirect any path on this site to any
	 * destination, which is a phishing primitive on a trusted domain. Keep it
	 * administrator-equivalent, the same way the REST write tier
	 * (wplinker_rest_write_capability) should be.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage routes in the admin.
		 *
		 * Covers both viewing and writing routes. Never set it below an
		 * administrator-equivalent capability.
		 *
		 * @param string $capability Capability name.
		 */
		return apply_filters( 'wplinker_admin_capability', 'manage_options' );
	}

	/**
	 * Registers the menu and submenus.
	 *
	 * @return void
	 */
	public function register_menus() {
		$hook = add_menu_page(
			__( 'WPLinker Routes', 'wplinker' ),
			__( 'WPLinker', 'wplinker' ),
			self::capability(),
			self::PAGE_LIST,
			array( $this, 'render_list_page' ),
			'dashicons-randomize',
			80
		);

		add_submenu_page(
			self::PAGE_LIST,
			__( 'All Routes', 'wplinker' ),
			__( 'All Routes', 'wplinker' ),
			self::capability(),
			self::PAGE_LIST,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			self::PAGE_LIST,
			__( 'Add Route', 'wplinker' ),
			__( 'Add Route', 'wplinker' ),
			self::capability(),
			self::PAGE_EDIT,
			array( $this, 'render_form_page' )
		);

		add_action( 'load-' . $hook, array( $this, 'load_list_page' ) );
	}

	/**
	 * Prepares the list screen: screen options and bulk actions.
	 *
	 * @return void
	 */
	public function load_list_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Routes per page', 'wplinker' ),
				'default' => 20,
				'option'  => 'wplinker_per_page',
			)
		);

		$this->handle_bulk_action();

		$this->list_table = self::make_list_table();
	}

	/**
	 * Builds the list table, loading the core base class on demand.
	 *
	 * Deferred rather than required at plugin load, because WP_List_Table needs
	 * the admin screen API that is only available once wp-admin has booted.
	 *
	 * @return WPLinker_List_Table
	 */
	private static function make_list_table() {
		require_once WPLINKER_PATH . 'admin/class-wplinker-list-table.php';

		return new WPLinker_List_Table();
	}

	/**
	 * Persists the per page screen option.
	 *
	 * @param mixed  $status Current value.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return 'wplinker_per_page' === $option ? (int) $value : $status;
	}

	/**
	 * Processes bulk deletions submitted from the list table.
	 *
	 * @return void
	 */
	private function handle_bulk_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below once an action is present.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( 'delete' !== $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same as above.
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}

		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-routes' );

		$ids = isset( $_REQUEST['route'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['route'] ) ) : array();

		if ( ! $ids ) {
			return;
		}

		$deleted = WPLinker_Routes::delete_many( $ids );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_LIST,
					'wplinker_message' => 'deleted',
					'wplinker_count'   => $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles create and update submissions from admin-post.php.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage routes.', 'wplinker' ), 403 );
		}

		check_admin_referer( 'wplinker_save_route' );

		$id = isset( $_POST['route_id'] ) ? absint( wp_unslash( $_POST['route_id'] ) ) : 0;

		$submitted = array(
			'source_path'     => isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '',
			'destination_url' => isset( $_POST['destination_url'] ) ? trim( wp_unslash( $_POST['destination_url'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- Validated in WPLinker_Validator.
			'status_code'     => isset( $_POST['status_code'] ) ? absint( wp_unslash( $_POST['status_code'] ) ) : 301,
			'match_type'      => isset( $_POST['match_type'] ) ? sanitize_key( wp_unslash( $_POST['match_type'] ) ) : 'exact',
		);

		$data = WPLinker_Validator::validate( $submitted, $id );

		if ( is_wp_error( $data ) ) {
			$this->redirect_with_error( $data->get_error_message(), $submitted, $id );
		}

		if ( $id ) {
			$result  = WPLinker_Routes::update( $id, $data );
			$message = 'updated';
		} else {
			$result  = WPLinker_Routes::insert( $data );
			$message = 'created';
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message(), $submitted, $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_LIST,
					'wplinker_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles single row deletion from the list table.
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage routes.', 'wplinker' ), 403 );
		}

		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		check_admin_referer( 'wplinker_delete_route_' . $id );

		$deleted = $id ? WPLinker_Routes::delete( $id ) : false;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_LIST,
					'wplinker_message' => $deleted ? 'deleted' : 'delete_failed',
					'wplinker_count'   => $deleted ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stores the error plus the submitted values and returns to the form.
	 *
	 * @param string $message   Error message.
	 * @param array  $submitted Submitted field values.
	 * @param int    $id        Route ID being edited, or 0.
	 * @return void
	 */
	private function redirect_with_error( $message, $submitted, $id ) {
		set_transient(
			self::form_state_key(),
			array(
				'message' => $message,
				'values'  => $submitted,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( 'wplinker_message', 'error', self::edit_url( $id ) ) );
		exit;
	}

	/**
	 * URL of the add/edit screen.
	 *
	 * @param int $id Route ID, or 0 for the add screen.
	 * @return string
	 */
	public static function edit_url( $id = 0 ) {
		$args = array( 'page' => self::PAGE_EDIT );

		if ( $id ) {
			$args['id'] = (int) $id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Nonced URL that deletes a single route.
	 *
	 * @param int $id Route ID.
	 * @return string
	 */
	public static function delete_url( $id ) {
		$url = add_query_arg(
			array(
				'action' => 'wplinker_delete_route',
				'id'     => (int) $id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'wplinker_delete_route_' . (int) $id );
	}

	/**
	 * Transient key holding a failed submission for the current user.
	 *
	 * @return string
	 */
	private static function form_state_key() {
		return 'wplinker_form_state_' . get_current_user_id();
	}

	/**
	 * Renders the list screen.
	 *
	 * @return void
	 */
	public function render_list_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage routes.', 'wplinker' ), 403 );
		}

		if ( ! $this->list_table ) {
			$this->list_table = self::make_list_table();
		}

		$this->list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Routes', 'wplinker' ); ?></h1>
			<a href="<?php echo esc_url( self::edit_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Add Route', 'wplinker' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_notices(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_LIST ); ?>" />
				<?php $this->list_table->search_box( __( 'Search routes', 'wplinker' ), 'wplinker-search' ); ?>
			</form>

			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_LIST ); ?>" />
				<?php $this->list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the add/edit form.
	 *
	 * @return void
	 */
	public function render_form_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage routes.', 'wplinker' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only screen selector.
		$id    = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$route = $id ? WPLinker_Routes::get( $id ) : null;

		if ( $id && ! $route ) {
			wp_die( esc_html__( 'Route not found.', 'wplinker' ), 404 );
		}

		$values = array(
			'source_path'     => $route ? $route->source_path : '',
			'destination_url' => $route ? $route->destination_url : '',
			'status_code'     => $route ? $route->status_code : 301,
			'match_type'      => $route ? $route->match_type : 'exact',
		);

		$state = get_transient( self::form_state_key() );

		if ( $state && ! empty( $state['values'] ) ) {
			$values = array_merge( $values, $state['values'] );
		}

		if ( 'prefix' === $values['match_type'] && $values['source_path'] ) {
			$values['source_path'] = rtrim( $values['source_path'], '/' ) . '/*';
		}
		?>
		<div class="wrap">
			<h1><?php echo $route ? esc_html__( 'Edit Route', 'wplinker' ) : esc_html__( 'Add Route', 'wplinker' ); ?></h1>

			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wplinker_save_route" />
				<input type="hidden" name="route_id" value="<?php echo esc_attr( $id ); ?>" />
				<?php wp_nonce_field( 'wplinker_save_route' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wplinker-source"><?php esc_html_e( 'Source path', 'wplinker' ); ?></label>
						</th>
						<td>
							<input name="source_path" id="wplinker-source" type="text" class="regular-text code"
								value="<?php echo esc_attr( $values['source_path'] ); ?>" required />
							<p class="description">
								<?php esc_html_e( 'Relative to the site root, for example /promo. End with /* to match every subpath, for example /docs/*.', 'wplinker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wplinker-match-type"><?php esc_html_e( 'Match type', 'wplinker' ); ?></label>
						</th>
						<td>
							<select name="match_type" id="wplinker-match-type">
								<option value="exact" <?php selected( $values['match_type'], 'exact' ); ?>>
									<?php esc_html_e( 'Exact — only this path', 'wplinker' ); ?>
								</option>
								<option value="prefix" <?php selected( $values['match_type'], 'prefix' ); ?>>
									<?php esc_html_e( 'Prefix — this path and everything under it', 'wplinker' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'A prefix route appends the remaining path to the destination, so /docs/* → https://example.com/documentation sends /docs/api/v1 to https://example.com/documentation/api/v1.', 'wplinker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wplinker-destination"><?php esc_html_e( 'Destination', 'wplinker' ); ?></label>
						</th>
						<td>
							<input name="destination_url" id="wplinker-destination" type="text" class="large-text code"
								value="<?php echo esc_attr( $values['destination_url'] ); ?>" required />
							<p class="description">
								<?php esc_html_e( 'A full URL such as https://example.com/new-promo, or a site relative path beginning with a slash.', 'wplinker' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wplinker-status"><?php esc_html_e( 'Status code', 'wplinker' ); ?></label>
						</th>
						<td>
							<select name="status_code" id="wplinker-status">
								<?php foreach ( WPLinker_Validator::allowed_status_codes() as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (int) $values['status_code'], $code ); ?>>
										<?php echo esc_html( $this->status_label( $code ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Permanent redirects are cached by browsers and CDNs. Use a temporary redirect if the destination may change.', 'wplinker' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( $route ? __( 'Update Route', 'wplinker' ) : __( 'Add Route', 'wplinker' ) ); ?>
				<a href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_LIST, admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'Back to all routes', 'wplinker' ); ?>
				</a>
			</form>
		</div>
		<?php
		delete_transient( self::form_state_key() );
	}

	/**
	 * Human readable label for a status code.
	 *
	 * @param int $code HTTP status code.
	 * @return string
	 */
	private function status_label( $code ) {
		$labels = array(
			301 => __( '301 — Permanent', 'wplinker' ),
			302 => __( '302 — Temporary', 'wplinker' ),
			307 => __( '307 — Temporary, method preserved', 'wplinker' ),
			308 => __( '308 — Permanent, method preserved', 'wplinker' ),
		);

		return isset( $labels[ $code ] ) ? $labels[ $code ] : (string) $code;
	}

	/**
	 * Renders admin notices from the redirect query args.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = isset( $_GET['wplinker_message'] ) ? sanitize_key( wp_unslash( $_GET['wplinker_message'] ) ) : '';
		$count   = isset( $_GET['wplinker_count'] ) ? absint( wp_unslash( $_GET['wplinker_count'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $message ) {
			return;
		}

		if ( 'error' === $message ) {
			$state = get_transient( self::form_state_key() );
			$text  = ! empty( $state['message'] ) ? $state['message'] : __( 'The route could not be saved.', 'wplinker' );

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $text )
			);

			return;
		}

		$messages = array(
			'created'       => __( 'Route added.', 'wplinker' ),
			'updated'       => __( 'Route updated.', 'wplinker' ),
			'delete_failed' => __( 'The route could not be deleted.', 'wplinker' ),
		);

		if ( 'deleted' === $message ) {
			$text = sprintf(
				/* translators: %s: number of routes deleted. */
				_n( '%s route deleted.', '%s routes deleted.', $count, 'wplinker' ),
				number_format_i18n( $count )
			);
		} elseif ( isset( $messages[ $message ] ) ) {
			$text = $messages[ $message ];
		} else {
			return;
		}

		$class = 'delete_failed' === $message ? 'notice-error' : 'notice-success';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}
}
