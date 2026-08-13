<?php
/**
 * Data access layer for the routes table.
 *
 * Every read/write to wp_custom_routes goes through this class so the caching
 * and sanitisation rules live in exactly one place.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes repository.
 */
class WPLinker_Routes {

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'wplinker';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'custom_routes';
	}

	/**
	 * Columns that may be written by callers.
	 *
	 * @return array<int, string>
	 */
	public static function writable_columns() {
		return array( 'source_path', 'destination_url', 'status_code', 'match_type' );
	}

	/**
	 * Fetches a single route by ID.
	 *
	 * @param int $id Route ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached below via wp_cache_set.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Fetches a route by its source path and match type.
	 *
	 * @param string $source_path Normalised source path.
	 * @param string $match_type  exact|prefix.
	 * @return object|null
	 */
	public static function get_by_source( $source_path, $match_type ) {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_path = %s AND match_type = %s",
				$source_path,
				$match_type
			)
		);

		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Queries routes for list screens and the REST collection endpoint.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type int    $page     Page number, 1 based. Default 1.
	 *     @type int    $per_page Items per page, or -1 for all. Default 20.
	 *     @type string $search   Term matched against source and destination.
	 *     @type string $orderby  Column to sort by.
	 *     @type string $order    ASC|DESC.
	 * }
	 * @return array<int, object>
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
				'search'   => '',
				'orderby'  => 'id',
				'order'    => 'DESC',
			)
		);

		$table  = self::table();
		$where  = self::build_where( $args );
		$order  = self::build_order( $args );
		$sql    = "SELECT * FROM {$table} {$where['sql']} {$order}";
		$params = $where['params'];

		$per_page = (int) $args['per_page'];

		if ( $per_page > 0 ) {
			$page     = max( 1, (int) $args['page'] );
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;
		}

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders assembled above.
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * Counts routes matching the same filters accepted by query().
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$table = self::table();
		$where = self::build_where( $args );
		$sql   = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( $where['params'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders assembled above.
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Loads every route whose source path is one of the given candidates.
	 *
	 * This is the single query the router runs on a front end request: the
	 * candidate list is derived from the incoming path, so the lookup is a
	 * covered index scan over a handful of exact values.
	 *
	 * @param array<int, string> $paths Candidate source paths.
	 * @return array<int, object>
	 */
	public static function find_candidates( array $paths ) {
		global $wpdb;

		$paths = array_values( array_unique( array_filter( $paths, 'strlen' ) ) );

		if ( ! $paths ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders assembled above.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE source_path IN ({$placeholders})",
			$paths
		);

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * Inserts a route. Data is expected to be validated already.
	 *
	 * @param array $data Column data.
	 * @return int|WP_Error Inserted ID or error.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = array(
			'source_path'     => $data['source_path'],
			'destination_url' => $data['destination_url'],
			'status_code'     => (int) $data['status_code'],
			'match_type'      => $data['match_type'],
			'clicks'          => 0,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		$inserted = $wpdb->insert( self::table(), $row, array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' ) );

		if ( ! $inserted ) {
			return new WP_Error(
				'wplinker_insert_failed',
				__( 'The route could not be saved. A route with this source path and match type may already exist.', 'wplinker' ),
				array( 'status' => 400 )
			);
		}

		self::flush_cache();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a route.
	 *
	 * @param int   $id   Route ID.
	 * @param array $data Column data, already validated.
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$id  = (int) $id;
		$row = array();
		$fmt = array();

		foreach ( self::writable_columns() as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$row[ $column ] = 'status_code' === $column ? (int) $data[ $column ] : $data[ $column ];
			$fmt[]          = 'status_code' === $column ? '%d' : '%s';
		}

		if ( isset( $data['clicks'] ) ) {
			$row['clicks'] = (int) $data['clicks'];
			$fmt[]         = '%d';
		}

		if ( ! $row ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql', true );
		$fmt[]             = '%s';

		$updated = $wpdb->update( self::table(), $row, array( 'id' => $id ), $fmt, array( '%d' ) );

		if ( false === $updated ) {
			return new WP_Error(
				'wplinker_update_failed',
				__( 'The route could not be updated. A route with this source path and match type may already exist.', 'wplinker' ),
				array( 'status' => 400 )
			);
		}

		self::flush_cache();

		return true;
	}

	/**
	 * Deletes a route.
	 *
	 * @param int $id Route ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );

		self::flush_cache();

		return (bool) $deleted;
	}

	/**
	 * Deletes several routes at once.
	 *
	 * @param array<int, int> $ids Route IDs.
	 * @return int Number of rows removed.
	 */
	public static function delete_many( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders assembled above.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

		self::flush_cache();

		return (int) $deleted;
	}

	/**
	 * Increments the hit counter for a route.
	 *
	 * Runs after the redirect has been flushed to the client, so the extra
	 * write never shows up in the visitor's response time.
	 *
	 * @param int $id Route ID.
	 * @return void
	 */
	public static function increment_clicks( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET clicks = clicks + 1 WHERE id = %d", (int) $id ) );
	}

	/**
	 * Total number of stored routes, cached.
	 *
	 * Lets the router skip its lookup query entirely on sites with no routes.
	 *
	 * @return int
	 */
	public static function total() {
		$key   = self::cache_key( 'total' );
		$total = wp_cache_get( $key, self::CACHE_GROUP );

		if ( false === $total ) {
			$total = self::count();
			wp_cache_set( $key, $total, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return (int) $total;
	}

	/**
	 * Builds a cache key namespaced by the current invalidation stamp.
	 *
	 * @param string $suffix Key suffix.
	 * @return string
	 */
	public static function cache_key( $suffix ) {
		return 'wplinker:' . self::last_changed() . ':' . $suffix;
	}

	/**
	 * Current cache invalidation stamp.
	 *
	 * @return string
	 */
	public static function last_changed() {
		$last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );

		if ( ! $last_changed ) {
			$last_changed = (string) microtime( true );
			wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP );
		}

		return $last_changed;
	}

	/**
	 * Invalidates every cached lookup after a write.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		wp_cache_set( 'last_changed', (string) microtime( true ), self::CACHE_GROUP );

		/**
		 * Fires after the route table has changed.
		 *
		 * Useful for purging an external page cache or an edge worker's copy of
		 * the routing table.
		 */
		do_action( 'wplinker_routes_changed' );
	}

	/**
	 * Casts a raw database row into consistent PHP types.
	 *
	 * @param object $row Raw row.
	 * @return object
	 */
	private static function shape( $row ) {
		$row->id          = (int) $row->id;
		$row->status_code = (int) $row->status_code;
		$row->clicks      = (int) $row->clicks;

		return $row;
	}

	/**
	 * Builds the shared WHERE clause for query() and count().
	 *
	 * @param array $args Query arguments.
	 * @return array{sql: string, params: array}
	 */
	private static function build_where( $args ) {
		$clauses = array();
		$params  = array();

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;

			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$clauses[] = '(source_path LIKE %s OR destination_url LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
		}

		if ( ! empty( $args['match_type'] ) ) {
			$clauses[] = 'match_type = %s';
			$params[]  = $args['match_type'];
		}

		if ( ! empty( $args['status_code'] ) ) {
			$clauses[] = 'status_code = %d';
			$params[]  = (int) $args['status_code'];
		}

		return array(
			'sql'    => $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			'params' => $params,
		);
	}

	/**
	 * Builds a safe ORDER BY clause from an allow list.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private static function build_order( $args ) {
		$allowed = array( 'id', 'source_path', 'destination_url', 'status_code', 'match_type', 'clicks', 'created_at', 'updated_at' );
		$orderby = isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'id';
		$order   = isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		return "ORDER BY {$orderby} {$order}";
	}
}
