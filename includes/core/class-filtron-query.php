<?php
/**
 * Query posts via {@see wp_filtron_index} (no postmeta JOIN).
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Query
 */
class Filtron_Query {

	/**
	 * Run filtered query; returns post rows (ID, post_title) plus optional meta for sorting.
	 *
	 * @param array<int, array<string, mixed>> $filter_args Filter groups.
	 * @param array<string, mixed>             $options     per_page, page, orderby, order.
	 * @return array<int, array<string, mixed>> List of rows with at least ID, post_title.
	 */
	public static function run( array $filter_args, array $options = array() ): array {
		global $wpdb;

		$args = array(
			'filters' => $filter_args,
			'options' => $options,
		);
		/**
		 * Filter query arguments before execution.
		 *
		 * @param array<string, mixed> $args Keys: filters, options.
		 */
		$args = apply_filters( 'filtron_before_query', $args );
		if ( ! is_array( $args ) || ! isset( $args['filters'] ) ) {
			$args = array(
				'filters' => $filter_args,
				'options' => $options,
			);
		}

		$filters = is_array( $args['filters'] ) ? $args['filters'] : array();
		$opts    = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

		$index_table = $wpdb->prefix . 'filtron_index';

		$post_ids = self::intersect_filter_post_ids( $filters, $index_table, $opts );
		if ( array() === $post_ids ) {
			$results = array();
			return apply_filters( 'filtron_after_query', $results, $args );
		}

		$posts_table = $wpdb->posts;

		$orderby = isset( $opts['orderby'] ) ? (string) $opts['orderby'] : 'date';
		$per_page = isset( $opts['per_page'] ) ? max( 1, (int) $opts['per_page'] ) : 0;
		$page     = isset( $opts['page'] ) ? max( 1, (int) $opts['page'] ) : 1;

		if ( 'price' === $orderby ) {
			$post_ids = self::sort_ids_by_price( $post_ids, $opts, $index_table );
			if ( $per_page > 0 ) {
				$offset   = ( $page - 1 ) * $per_page;
				$post_ids = array_slice( $post_ids, $offset, $per_page );
			}
		}

		if ( array() === $post_ids ) {
			$results = array();
			return apply_filters( 'filtron_after_query', $results, $args );
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$orderby_sql  = self::orderby_clause( $orderby );
		$order        = self::order_direction( $opts );

		$prepare_args = $post_ids;
		$limit_sql    = '';

		if ( 'price' !== $orderby && $per_page > 0 ) {
			$offset       = ( $page - 1 ) * $per_page;
			$limit_sql    = ' LIMIT %d OFFSET %d';
			$prepare_args = array_merge( $post_ids, array( $per_page, $offset ) );
		}

		$sql = "SELECT p.ID, p.post_title, p.post_date, p.post_type, p.menu_order
			FROM `{$posts_table}` p
			WHERE p.ID IN ($placeholders)
			AND p.post_status = 'publish'
			{$orderby_sql} {$order}{$limit_sql}";

		$prepared = $wpdb->prepare( $sql, ...$prepare_args );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		if ( 'price' === $orderby ) {
			$rows = self::order_rows_by_id_sequence( $rows, $post_ids );
		}

		/**
		 * Filter query results.
		 *
		 * @param array<int, array<string, mixed>> $rows Result rows.
		 * @param array<string, mixed>             $args Query args (filters, options).
		 */
		return apply_filters( 'filtron_after_query', $rows, $args );
	}

	/**
	 * Faceted counts: for each equality (key,value) in filters, how many posts match
	 * all other filter groups plus that pair (OR within same key still applies for that group’s other values when counting each facet — here we count per explicit pair in normalized list).
	 *
	 * @param array<int, array<string, mixed>> $filter_args Same shape as run().
	 * @return array<string, int> Keys "key|value" => count.
	 */
	public static function get_counts( array $filter_args, array $options = array() ): array {
		global $wpdb;

		$index_table = $wpdb->prefix . 'filtron_index';
		$normalized  = self::normalize_filters( $filter_args );
		$counts      = array();

		$pairs = array();
		foreach ( $normalized as $group ) {
			if ( 'range' === $group['type'] || 'search' === $group['type'] ) {
				continue;
			}
			$key = (string) $group['key'];
			foreach ( $group['values'] as $val ) {
				$pairs[] = array( 'key' => $key, 'value' => (string) $val );
			}
		}

		foreach ( $pairs as $pair ) {
			$others = array();
			foreach ( $normalized as $group ) {
				if ( 'range' === $group['type'] ) {
					$others[] = $group;
					continue;
				}
				if ( (string) $group['key'] !== $pair['key'] ) {
					$others[] = $group;
				}
			}
			$others[] = array(
				'type'    => 'equality',
				'key'     => $pair['key'],
				'values'  => array( $pair['value'] ),
				'logic'   => 'OR',
			);

			$ids = self::intersect_filter_post_ids( self::denormalize_for_intersect( $others ), $index_table, $options );
			$k   = $pair['key'] . '|' . $pair['value'];
			$counts[ $k ] = count( $ids );
		}

		return $counts;
	}

	/**
	 * @param array<int, array<string, mixed>> $normalized Normalized groups.
	 * @return array<int, array<string, mixed>>
	 */
	private static function denormalize_for_intersect( array $normalized ): array {
		$out = array();
		foreach ( $normalized as $g ) {
			if ( 'range' === $g['type'] ) {
				$out[] = array(
					'key' => $g['key'],
					'min' => $g['min'],
					'max' => $g['max'],
				);
				continue;
			}
			if ( 'search' === $g['type'] ) {
				$out[] = array(
					'key'   => $g['key'],
					'value' => $g['term'],
					'type'  => 'search',
				);
				continue;
			}
			$logic = 'OR' === strtoupper( (string) ( $g['logic'] ?? 'OR' ) ) ? 'OR' : 'AND';
			foreach ( $g['values'] as $v ) {
				$out[] = array(
					'key'   => $g['key'],
					'value' => $v,
					'logic' => $logic,
				);
			}
		}
		return $out;
	}

	/**
	 * Intersect post IDs from each filter group (AND between groups).
	 *
	 * @param array<int, array<string, mixed>> $filters     Raw filter args.
	 * @param string                           $index_table Prefixed filtron_index table name.
	 * @param array<string, mixed>             $options     post_type when no filters (optional).
	 * @return array<int, int> Post IDs.
	 */
	private static function intersect_filter_post_ids( array $filters, string $index_table, array $options = array() ): array {
		$groups = self::normalize_filters( $filters );
		if ( array() === $groups ) {
			return self::all_published_post_ids( $options );
		}

		$id_sets = array();
		foreach ( $groups as $group ) {
			$ids = self::post_ids_for_group( $group, $index_table, $options );
			if ( array() === $ids ) {
				return array();
			}
			$id_sets[] = $ids;
		}

		if ( 1 === count( $id_sets ) ) {
			return array_values( array_map( 'intval', $id_sets[0] ) );
		}

		$intersected = $id_sets[0];
		for ( $i = 1, $c = count( $id_sets ); $i < $c; $i++ ) {
			$intersected = array_intersect( $intersected, $id_sets[ $i ] );
			if ( array() === $intersected ) {
				return array();
			}
		}

		return array_values( array_map( 'intval', $intersected ) );
	}

	/**
	 * All published post IDs when no facet filters (optional post_type).
	 *
	 * @param array<string, mixed> $options Options (post_type).
	 * @return array<int, int>
	 */
	private static function all_published_post_ids( array $options ): array {
		global $wpdb;

		if ( isset( $options['post_type'] ) && is_string( $options['post_type'] ) && '' !== $options['post_type'] ) {
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type = %s",
				'publish',
				$options['post_type']
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s",
				'publish'
			);
		}

		$col = $wpdb->get_col( $sql );
		return array_map( 'intval', $col ? $col : array() );
	}

	/**
	 * Sort post IDs by indexed WooCommerce-style _price value (numeric).
	 *
	 * @param array<int, int>      $post_ids IDs.
	 * @param array<string, mixed> $opts     order ASC/DESC.
	 * @param string               $index_table Prefixed filtron_index table name.
	 * @return array<int, int>
	 */
	private static function sort_ids_by_price( array $post_ids, array $opts, string $index_table ): array {
		global $wpdb;

		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		if ( array() === $post_ids ) {
			return array();
		}

		$order = isset( $opts['order'] ) ? strtoupper( (string) $opts['order'] ) : 'DESC';
		$asc   = 'ASC' === $order;
		$price_key = isset( $opts['price_key'] ) && is_string( $opts['price_key'] ) && '' !== $opts['price_key']
			? $opts['price_key']
			: '_price';

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$prepare_args = array_merge( array( $price_key ), $post_ids );
		$post_type    = self::post_type_option( $opts );
		$post_type_sql = '';
		if ( null !== $post_type ) {
			$post_type_sql = ' AND post_type = %s';
			$prepare_args[] = $post_type;
		}

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix, placeholders generated for IDs.
			"SELECT post_id, MIN(filter_value_num) AS price
			FROM `{$index_table}`
			WHERE filter_key = %s
			AND filter_value_num IS NOT NULL
			AND post_id IN ($placeholders)
			{$post_type_sql}
			GROUP BY post_id",
			...$prepare_args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$price_by_id = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( isset( $row['post_id'], $row['price'] ) && is_numeric( $row['price'] ) ) {
					$price_by_id[ (int) $row['post_id'] ] = (float) $row['price'];
				}
			}
		}

		$with_price = array();
		foreach ( $post_ids as $position => $id ) {
			$with_price[] = array(
				'id'       => (int) $id,
				'price'    => $price_by_id[ (int) $id ] ?? null,
				'position' => (int) $position,
			);
		}

		usort(
			$with_price,
			static function ( $a, $b ) use ( $asc ) {
				$a_has = null !== $a['price'];
				$b_has = null !== $b['price'];

				if ( $a_has !== $b_has ) {
					return $a_has ? -1 : 1;
				}
				if ( ! $a_has && ! $b_has ) {
					return $a['position'] <=> $b['position'];
				}
				if ( $a['price'] === $b['price'] ) {
					return $a['position'] <=> $b['position'];
				}
				if ( $asc ) {
					return ( $a['price'] < $b['price'] ) ? -1 : 1;
				}
				return ( $a['price'] > $b['price'] ) ? -1 : 1;
			}
		);

		return array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			$with_price
		);
	}

	/**
	 * Match SQL result order to sorted ID list (e.g. after price sort + slice).
	 *
	 * @param array<int, array<string, mixed>> $rows     DB rows.
	 * @param array<int, int>                  $id_order Desired ID order.
	 * @return array<int, array<string, mixed>>
	 */
	private static function order_rows_by_id_sequence( array $rows, array $id_order ): array {
		$map = array();
		foreach ( $rows as $r ) {
			if ( isset( $r['ID'] ) ) {
				$map[ (int) $r['ID'] ] = $r;
			}
		}
		$out = array();
		foreach ( $id_order as $id ) {
			if ( isset( $map[ $id ] ) ) {
				$out[] = $map[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Normalize raw filter_args into internal groups.
	 *
	 * @param array<int, array<string, mixed>> $filters Raw filters.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_filters( array $filters ): array {
		$groups = array();
		$bucket = array();

		foreach ( $filters as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['min'], $row['max'] ) && isset( $row['key'] ) ) {
				$groups[] = array(
					'type' => 'range',
					'key'  => (string) $row['key'],
					'min'  => (float) $row['min'],
					'max'  => (float) $row['max'],
				);
				continue;
			}

			if ( isset( $row['type'] ) && 'search' === strtolower( (string) $row['type'] ) && isset( $row['key'] ) ) {
				$term = isset( $row['value'] ) ? trim( (string) $row['value'] ) : '';
				if ( '' !== $term && strlen( $term ) >= 2 ) {
					$groups[] = array(
						'type' => 'search',
						'key'  => (string) $row['key'],
						'term' => $term,
					);
				}
				continue;
			}

			if ( ! isset( $row['key'] ) ) {
				continue;
			}

			$key   = (string) $row['key'];
			$logic = strtoupper( (string) ( $row['logic'] ?? 'OR' ) );

			if ( isset( $row['value'] ) && is_array( $row['value'] ) ) {
				$vals = $row['value'];
			} elseif ( isset( $row['values'] ) && is_array( $row['values'] ) ) {
				$vals = $row['values'];
			} elseif ( isset( $row['value'] ) ) {
				$vals = array( $row['value'] );
			} else {
				continue;
			}

			$vals = array_map( 'strval', $vals );
			$vals = array_values(
				array_filter(
					$vals,
					static function ( $v ) {
						return '' !== (string) $v;
					}
				)
			);
			if ( array() === $vals ) {
				continue;
			}

			$sig = $key . "\x00" . $logic;
			if ( ! isset( $bucket[ $sig ] ) ) {
				$bucket[ $sig ] = array(
					'type'   => 'equality',
					'key'    => $key,
					'values' => array(),
					'logic'  => $logic,
				);
			}
			foreach ( $vals as $v ) {
				$bucket[ $sig ]['values'][] = $v;
			}
		}

		foreach ( $bucket as $b ) {
			$b['values'] = array_values( array_unique( $b['values'] ) );
			$groups[]    = $b;
		}

		return $groups;
	}

	/**
	 * Post IDs for one normalized group (OR or AND inside group; range single group).
	 *
	 * @param array<string, mixed> $group       Normalized group.
	 * @param string               $index_table Table name.
	 * @param array<string, mixed> $options     Query options.
	 * @return array<int, int>
	 */
	private static function post_ids_for_group( array $group, string $index_table, array $options = array() ): array {
		global $wpdb;

		$post_type     = self::post_type_option( $options );
		$post_type_sql = '';
		$post_type_arg = array();
		if ( null !== $post_type ) {
			$post_type_sql = ' AND post_type = %s';
			$post_type_arg = array( $post_type );
		}

		if ( 'range' === $group['type'] ) {
			$key = (string) $group['key'];
			$min = (float) $group['min'];
			$max = (float) $group['max'];

			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
				"SELECT post_id FROM `{$index_table}` WHERE filter_key = %s AND filter_value_num BETWEEN %f AND %f{$post_type_sql}",
				...array_merge( array( $key, $min, $max ), $post_type_arg )
			);

			$ids = $wpdb->get_col( $sql );
			return array_map( 'intval', $ids ? $ids : array() );
		}

		if ( 'search' === ( $group['type'] ?? '' ) ) {
			$key  = (string) $group['key'];
			$term = isset( $group['term'] ) ? (string) $group['term'] : '';
			if ( '' === $key || '' === $term || strlen( $term ) < 2 ) {
				return array();
			}
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$sql  = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
				"SELECT post_id FROM `{$index_table}` WHERE filter_key = %s AND filter_value LIKE %s{$post_type_sql}",
				...array_merge( array( $key, $like ), $post_type_arg )
			);
			$ids = $wpdb->get_col( $sql );
			return array_map( 'intval', $ids ? $ids : array() );
		}

		$key    = (string) $group['key'];
		$values = $group['values'];
		$logic  = 'AND' === strtoupper( (string) ( $group['logic'] ?? 'OR' ) ) ? 'AND' : 'OR';

		if ( 1 === count( $values ) ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT post_id FROM `{$index_table}` WHERE filter_key = %s AND filter_value = %s{$post_type_sql}",
				...array_merge( array( $key, $values[0] ), $post_type_arg )
			);
			$ids = $wpdb->get_col( $sql );
			return array_map( 'intval', $ids ? $ids : array() );
		}

		if ( 'OR' === $logic ) {
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT post_id FROM `{$index_table}` WHERE filter_key = %s AND filter_value IN ($placeholders){$post_type_sql}",
				...array_merge( array( $key ), $values, $post_type_arg )
			);
			$ids = $wpdb->get_col( $sql );
			return array_map( 'intval', $ids ? $ids : array() );
		}

		$id_sets = array();
		foreach ( $values as $val ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT post_id FROM `{$index_table}` WHERE filter_key = %s AND filter_value = %s{$post_type_sql}",
				...array_merge( array( $key, $val ), $post_type_arg )
			);
			$col = $wpdb->get_col( $sql );
			$ids = array_map( 'intval', $col ? $col : array() );
			if ( array() === $ids ) {
				return array();
			}
			$id_sets[] = $ids;
		}

		$intersected = $id_sets[0];
		for ( $i = 1, $c = count( $id_sets ); $i < $c; $i++ ) {
			$intersected = array_intersect( $intersected, $id_sets[ $i ] );
			if ( array() === $intersected ) {
				return array();
			}
		}

		return array_values( array_map( 'intval', $intersected ) );
	}

	/**
	 * Optional post_type scope for index queries.
	 *
	 * @param array<string, mixed> $options Query options.
	 */
	private static function post_type_option( array $options ): ?string {
		if ( ! isset( $options['post_type'] ) || ! is_string( $options['post_type'] ) ) {
			return null;
		}
		$post_type = sanitize_key( $options['post_type'] );
		return '' !== $post_type ? $post_type : null;
	}

	/**
	 * ORDER BY fragment (whitelist). Price uses ID order from PHP sort; SQL uses post_date as tie-breaker only when not price branch.
	 *
	 * @param string $orderby orderby key.
	 */
	private static function orderby_clause( string $orderby ): string {
		switch ( $orderby ) {
			case 'title':
				return 'ORDER BY p.post_title';
			case 'menu_order':
				return 'ORDER BY p.menu_order';
			case 'price':
				return 'ORDER BY p.post_date';
			case 'date':
			default:
				return 'ORDER BY p.post_date';
		}
	}

	/**
	 * ASC/DESC.
	 *
	 * @param array<string, mixed> $opts Options.
	 */
	private static function order_direction( array $opts ): string {
		$order = isset( $opts['order'] ) ? strtoupper( (string) $opts['order'] ) : 'DESC';
		return in_array( $order, array( 'ASC', 'DESC' ), true ) ? ' ' . $order : ' DESC';
	}

}
