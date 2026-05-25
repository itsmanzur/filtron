<?php
/**
 * Frontend shortcodes for Filtron groups.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Shortcode
 */
class Filtron_Shortcode {

	/**
	 * Register public shortcodes.
	 */
	public static function register(): void {
		add_shortcode( 'filtron_group', array( self::class, 'render_group' ) );
		add_shortcode( 'filtron', array( self::class, 'render_group' ) );
		add_filter( 'the_content', array( self::class, 'repair_rendered_content' ), PHP_INT_MAX );
	}

	/**
	 * Repair entity-encoded mojibake introduced by late content filters.
	 *
	 * @param string $content Rendered post content.
	 */
	public static function repair_rendered_content( string $content ): string {
		if ( false === strpos( $content, 'filtron-' ) || false === strpos( $content, '&' ) ) {
			return $content;
		}
		return Filtron_Filter_Base::repair_mojibake_entities_in_html( $content );
	}

	/**
	 * Render a saved filter group inside the same frontend shell used by blocks.
	 *
	 * Usage:
	 * [filtron_group id="123" layout="grid"]
	 * [filtron id="123" layout="list" per_page="12" orderby="price" order="ASC"]
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public static function render_group( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'group_id' => 0,
				'layout'   => 'grid',
				'view'     => '',
				'per_page' => 6,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'align'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			'filtron_group'
		);

		$group_id = absint( $atts['id'] );
		if ( $group_id < 1 ) {
			$group_id = absint( $atts['group_id'] );
		}
		if ( $group_id < 1 || ! self::group_is_active( $group_id ) ) {
			return '';
		}

		$content = self::render_filters_for_group( $group_id );
		$layout  = '' !== (string) $atts['view'] ? (string) $atts['view'] : (string) $atts['layout'];

		return Filtron_Gutenberg::render_container(
			array(
				'groupId' => $group_id,
				'align'   => sanitize_key( (string) $atts['align'] ),
				'perPage' => max( 1, min( 100, (int) $atts['per_page'] ) ),
				'orderby' => sanitize_key( (string) $atts['orderby'] ),
				'order'   => strtoupper( sanitize_key( (string) $atts['order'] ) ),
				'view'    => sanitize_key( $layout ),
			),
			$content
		);
	}

	/**
	 * Check that a group exists and is active.
	 *
	 * @param int $group_id Group id.
	 */
	private static function group_is_active( int $group_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'filtron_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$is_active = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_active FROM `{$table}` WHERE id = %d LIMIT 1",
				$group_id
			)
		);

		return null !== $is_active && (int) $is_active > 0;
	}

	/**
	 * Render active filters saved inside a group.
	 *
	 * @param int $group_id Group id.
	 */
	private static function render_filters_for_group( int $group_id ): string {
		$items = self::get_items_for_group( $group_id );
		if ( array() === $items ) {
			return '';
		}

		$html = '';
		foreach ( $items as $item ) {
			$html .= self::render_filter_item( $item );
		}

		return $html;
	}

	/**
	 * @param int $group_id Group id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_items_for_group( int $group_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'filtron_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, group_id, filter_type, source_type, source_key, label, sort_order, config_json, is_active FROM `{$table}` WHERE group_id = %d AND is_active = 1 ORDER BY sort_order ASC, id ASC",
				$group_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::normalize_item_row( $row );
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	private static function normalize_item_row( array $row ): array {
		$config = array();
		if ( ! empty( $row['config_json'] ) ) {
			$decoded = json_decode( (string) $row['config_json'], true );
			if ( is_array( $decoded ) ) {
				$config = $decoded;
			}
		}

		$config['id']          = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$config['group_id']    = isset( $row['group_id'] ) ? (int) $row['group_id'] : 0;
		$config['filter_type'] = isset( $row['filter_type'] ) ? sanitize_key( (string) $row['filter_type'] ) : '';
		$config['source_type'] = isset( $row['source_type'] ) ? sanitize_key( (string) $row['source_type'] ) : 'taxonomy';
		$config['source_key']  = isset( $row['source_key'] ) ? sanitize_text_field( (string) $row['source_key'] ) : '';
		$config['key']         = $config['source_key'];
		$config['label']       = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
		$config['sort_order']  = isset( $row['sort_order'] ) ? (int) $row['sort_order'] : 0;
		$config['is_active']   = isset( $row['is_active'] ) ? (int) $row['is_active'] : 0;

		return $config;
	}

	/**
	 * @param array<string, mixed> $item Normalized filter config.
	 */
	private static function render_filter_item( array $item ): string {
		$type = isset( $item['filter_type'] ) ? sanitize_key( (string) $item['filter_type'] ) : '';
		if ( '' === $type || empty( $item['source_key'] ) ) {
			return '';
		}

		$html = '';
		if ( 'checkbox' === $type ) {
			$html = ( new Filtron_Filter_Checkbox( $item ) )->render();
		} elseif ( 'select' === $type ) {
			$html = ( new Filtron_Filter_Select( $item ) )->render();
		} elseif ( 'range' === $type ) {
			$html = ( new Filtron_Filter_Range( $item ) )->render();
		} elseif ( 'search' === $type ) {
			$html = ( new Filtron_Filter_Search( $item ) )->render();
		}

		/**
		 * Render custom or Pro filter types for shortcode output.
		 *
		 * @param string               $html Current rendered HTML.
		 * @param array<string, mixed> $item Normalized item config.
		 */
		return (string) apply_filters( 'filtron_shortcode_filter_html', $html, $item );
	}
}
