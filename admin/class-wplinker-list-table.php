<?php
/**
 * Routes list screen, built on WP_List_Table.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the routing table with core pagination, sorting, search and bulk actions.
 */
class WPLinker_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'route',
				'plural'   => 'routes',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns rendered in the table.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'              => '<input type="checkbox" />',
			'source_path'     => __( 'Source', 'wplinker' ),
			'destination_url' => __( 'Destination', 'wplinker' ),
			'match_type'      => __( 'Match', 'wplinker' ),
			'status_code'     => __( 'Status', 'wplinker' ),
			'clicks'          => __( 'Hits', 'wplinker' ),
			'updated_at'      => __( 'Last modified', 'wplinker' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array>
	 */
	public function get_sortable_columns() {
		return array(
			'source_path'     => array( 'source_path', true ),
			'destination_url' => array( 'destination_url', false ),
			'match_type'      => array( 'match_type', false ),
			'status_code'     => array( 'status_code', false ),
			'clicks'          => array( 'clicks', false ),
			'updated_at'      => array( 'updated_at', false ),
		);
	}

	/**
	 * Available bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'wplinker' ),
		);
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No routes yet.', 'wplinker' );
	}

	/**
	 * Loads rows for the current screen.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'wplinker_per_page', 20 );
		$paged    = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only list filters.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'page'     => $paged,
			'per_page' => $per_page,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		$total = WPLinker_Routes::count( $args );

		$this->items           = WPLinker_Routes::query( $args );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'source_path' );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Route row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="route[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Source column, including the row actions.
	 *
	 * @param object $item Route row.
	 * @return string
	 */
	public function column_source_path( $item ) {
		$label = $item->source_path;

		if ( 'prefix' === $item->match_type ) {
			$label = rtrim( $label, '/' ) . '/*';
		}

		$edit_url = WPLinker_Admin::edit_url( $item->id );

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'wplinker' )
			),
			'visit'  => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( home_url( $item->source_path ) ),
				esc_html__( 'Test', 'wplinker' )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( WPLinker_Admin::delete_url( $item->id ) ),
				esc_js( __( 'Delete this route?', 'wplinker' ) ),
				esc_html__( 'Delete', 'wplinker' )
			),
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $label ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Destination column.
	 *
	 * @param object $item Route row.
	 * @return string
	 */
	public function column_destination_url( $item ) {
		$destination = $item->destination_url;
		$href        = '/' === substr( $destination, 0, 1 ) ? home_url( $destination ) : $destination;

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $href ),
			esc_html( $destination )
		);
	}

	/**
	 * Match type column.
	 *
	 * @param object $item Route row.
	 * @return string
	 */
	public function column_match_type( $item ) {
		return 'prefix' === $item->match_type
			? esc_html__( 'Prefix', 'wplinker' )
			: esc_html__( 'Exact', 'wplinker' );
	}

	/**
	 * Status code column.
	 *
	 * @param object $item Route row.
	 * @return string
	 */
	public function column_status_code( $item ) {
		$labels = array(
			301 => __( '301 Permanent', 'wplinker' ),
			302 => __( '302 Temporary', 'wplinker' ),
			307 => __( '307 Temporary', 'wplinker' ),
			308 => __( '308 Permanent', 'wplinker' ),
		);

		return isset( $labels[ $item->status_code ] ) ? esc_html( $labels[ $item->status_code ] ) : (int) $item->status_code;
	}

	/**
	 * Last modified column.
	 *
	 * @param object $item Route row.
	 * @return string
	 */
	public function column_updated_at( $item ) {
		if ( empty( $item->updated_at ) || '0000-00-00 00:00:00' === $item->updated_at ) {
			return '&mdash;';
		}

		$timestamp = (int) get_date_from_gmt( $item->updated_at, 'U' );

		return esc_html(
			sprintf(
				/* translators: %s: human readable time difference. */
				__( '%s ago', 'wplinker' ),
				human_time_diff( $timestamp, time() )
			)
		);
	}

	/**
	 * Fallback renderer for remaining columns.
	 *
	 * @param object $item        Route row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}
}
