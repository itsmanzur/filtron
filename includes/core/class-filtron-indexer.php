<?php
/**
 * Maintains {@see wp_filtron_index} from published posts (taxonomies + meta + filter hook).
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Indexer
 */
class Filtron_Indexer {

	/**
	 * True during rebuild_all() to avoid flushing cache on every post.
	 *
	 * @var bool
	 */
	private static bool $bulk_rebuilding = false;

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'on_save_post' ), 10, 2 );
		add_action( 'before_delete_post', array( self::class, 'on_before_delete_post' ), 10, 1 );
	}

	/**
	 * Rebuild index rows for one post (delete old rows, insert new if published).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function rebuild_index( int $post_id ): void {
		global $wpdb;

		try {
			$table = $wpdb->prefix . 'filtron_index';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE post_id = %d", $post_id ) );

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				return;
			}

			$rows = self::collect_rows( $post );
			/**
			 * Filter index rows before insert. Pro/WooCommerce can append rows.
			 *
			 * @param array<int, array<string, mixed>> $rows     Rows with keys: post_id, post_type, filter_key, filter_value, filter_value_num.
			 * @param int                               $post_id Post ID.
			 */
			$rows = apply_filters( 'filtron_index_post', $rows, $post_id );

			if ( ! is_array( $rows ) || array() === $rows ) {
				return;
			}

			$rows = self::dedupe_rows( $rows );
			foreach ( $rows as $row ) {
				if ( ! self::is_valid_row( $row ) ) {
					continue;
				}
				self::insert_row( $table, $row );
			}
		} finally {
			if ( ! self::$bulk_rebuilding ) {
				Filtron_Cache::flush_group();
			}
		}
	}

	/**
	 * Remove all index rows for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_index( int $post_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'filtron_index';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE post_id = %d", $post_id ) );

		if ( ! self::$bulk_rebuilding ) {
			Filtron_Cache::flush_group();
		}
	}

	/**
	 * Rebuild index for every published post (admin/maintenance).
	 */
	public static function rebuild_all(): void {
		self::$bulk_rebuilding = true;
		try {
			$batch = 200;
			$paged = 1;

			do {
				$query = new WP_Query(
					array(
						'post_type'              => 'any',
						'post_status'            => 'publish',
						'posts_per_page'         => $batch,
						'paged'                  => $paged,
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);

				foreach ( $query->posts as $post_id ) {
					self::rebuild_index( (int) $post_id );
				}

				$max_pages = (int) $query->max_num_pages;
				++$paged;
			} while ( $max_pages >= $paged );
		} finally {
			self::$bulk_rebuilding = false;
			Filtron_Cache::flush_group();
		}
	}

	/**
	 * save_post: rebuild index; static flag avoids re-entry loops.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function on_save_post( int $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}

		static $processing = false;
		if ( $processing ) {
			return;
		}

		$processing = true;
		try {
			self::rebuild_index( $post_id );
		} finally {
			$processing = false;
		}
	}

	/**
	 * Drop index rows when a post is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_before_delete_post( int $post_id ): void {
		self::delete_index( $post_id );
	}

	/**
	 * Build base rows from taxonomies and post meta.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_rows( WP_Post $post ): array {
		$rows   = array();
		$post_id = (int) $post->ID;

		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$key   = self::truncate( $taxonomy, 100 );
				$value = self::truncate( $term->slug, 200 );
				$rows[] = array(
					'post_id'          => $post_id,
					'post_type'        => self::truncate( $post->post_type, 50 ),
					'filter_key'       => $key,
					'filter_value'     => $value,
					'filter_value_num' => null,
				);
			}
		}

		$custom = get_post_custom( $post_id );
		foreach ( $custom as $meta_key => $values ) {
			$key = self::truncate( (string) $meta_key, 100 );
			foreach ( (array) $values as $single ) {
				$unpacked = maybe_unserialize( $single );
				if ( is_array( $unpacked ) || is_object( $unpacked ) ) {
					continue;
				}
				if ( ! is_scalar( $unpacked ) && null !== $unpacked ) {
					continue;
				}
				$str = null === $unpacked ? '' : (string) $unpacked;
				if ( '' === $str ) {
					continue;
				}
				$value     = self::truncate( $str, 200 );
				$num       = is_numeric( $str ) ? floatval( $str ) : null;
				$rows[]    = array(
					'post_id'          => $post_id,
					'post_type'        => self::truncate( $post->post_type, 50 ),
					'filter_key'       => $key,
					'filter_value'     => $value,
					'filter_value_num' => $num,
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe_rows( array $rows ): array {
		$seen = array();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sig = (int) ( $row['post_id'] ?? 0 ) . '|' . (string) ( $row['filter_key'] ?? '' ) . '|' . (string) ( $row['filter_value'] ?? '' );
			if ( isset( $seen[ $sig ] ) ) {
				continue;
			}
			$seen[ $sig ] = true;
			$out[]        = $row;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $row Row.
	 */
	private static function is_valid_row( array $row ): bool {
		return isset( $row['post_id'], $row['post_type'], $row['filter_key'], $row['filter_value'] )
			&& is_numeric( $row['post_id'] );
	}

	/**
	 * Insert one row via $wpdb->insert() (value escaping via wpdb; table name from prefix).
	 *
	 * @param string               $table Full table name.
	 * @param array<string, mixed> $row   Row data.
	 */
	private static function insert_row( string $table, array $row ): void {
		global $wpdb;

		$post_id      = (int) $row['post_id'];
		$post_type    = (string) $row['post_type'];
		$filter_key   = (string) $row['filter_key'];
		$filter_value = (string) $row['filter_value'];
		$num          = array_key_exists( 'filter_value_num', $row ) ? $row['filter_value_num'] : null;
		$num          = ( null !== $num && is_numeric( $num ) ) ? (float) $num : null;

		$data   = array(
			'post_id'      => $post_id,
			'post_type'    => $post_type,
			'filter_key'   => $filter_key,
			'filter_value' => $filter_value,
		);
		$format = array( '%d', '%s', '%s', '%s' );

		if ( null !== $num ) {
			$data['filter_value_num'] = $num;
			$format[]                 = '%f';
		}

		$wpdb->insert( $table, $data, $format );
	}

	/**
	 * @param string $value Raw string.
	 * @param int    $max   Max length.
	 */
	private static function truncate( string $value, int $max ): string {
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max );
	}
}
