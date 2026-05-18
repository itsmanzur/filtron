<?php
/**
 * Text search filter (LIKE on filter_value in index).
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Filter_Search
 */
class Filtron_Filter_Search extends Filtron_Filter_Base {

	/**
	 * Minimum characters for main query + live suggest (master: 2).
	 */
	public const MIN_CHARS = 2;

	/**
	 * @see Filtron_Filter_Base::render()
	 */
	public function render(): string {
		if ( ! $this->is_active() ) {
			return '';
		}

		$template = $this->get_template_path( 'search.php' );
		if ( ! is_readable( $template ) ) {
			return '';
		}

		$filter = $this;

		ob_start();
		/** @noinspection PhpIncludeInspection */
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * LIKE semantics via {@see Filtron_Query} search group.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_query_args(): array {
		$key = $this->get_source_key();
		if ( '' === $key ) {
			return array();
		}

		$term = $this->get_search_term();
		if ( strlen( $term ) < self::MIN_CHARS ) {
			return array();
		}

		return array(
			array(
				'key'   => $key,
				'value' => $term,
				'type'  => 'search',
			),
		);
	}

	/**
	 * @see Filtron_Filter_Base::get_available_values()
	 */
	public function get_available_values(): array {
		return array();
	}

	/**
	 * Current search string (GET).
	 */
	public function get_search_term(): string {
		$param = $this->get_url_param_name();
		if ( ! isset( $_GET[ $param ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) );
	}

	/**
	 * ?filtron_{slug}_s=term
	 */
	public function get_url_param_name(): string {
		return 'filtron_' . $this->get_url_slug() . '_s';
	}

	/**
	 * Slug for URL/query (same idea as range).
	 */
	public function get_url_slug(): string {
		if ( ! empty( $this->config['url_slug'] ) ) {
			return sanitize_key( (string) $this->config['url_slug'] );
		}
		$k = $this->get_source_key();
		$k = preg_replace( '/^_+/', '', $k );
		$k = str_replace( array( '.', ' ' ), '-', $k );
		$s = sanitize_key( $k );
		return '' !== $s ? $s : 'search';
	}

	/**
	 * AJAX: live suggestions (debounce + min length enforced in JS too).
	 */
	public static function ajax_suggest(): void {
		$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'filtron_filter_nonce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token.', 'filtron' ),
				),
				403
			);
			return;
		}

		$key = isset( $_POST['filter_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['filter_key'] ) ) : '';
		$q   = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['q'] ) ) : '';

		if ( '' === $key ) {
			wp_send_json_error(
				array(
					'message' => __( 'Missing filter key.', 'filtron' ),
				),
				400
			);
			return;
		}

		if ( strlen( $q ) < self::MIN_CHARS ) {
			wp_send_json_success(
				array(
					'items' => array(),
				)
			);
			return;
		}

		global $wpdb;
		$index = $wpdb->prefix . 'filtron_index';
		$like  = '%' . $wpdb->esc_like( $q ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT i.post_id, p.post_title
			FROM `{$index}` i
			INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id AND p.post_status = 'publish'
			WHERE i.filter_key = %s AND i.filter_value LIKE %s
			ORDER BY p.post_title ASC
			LIMIT 20",
			$key,
			$like
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'post_id' => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0,
				'title'   => isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
			);
		}

		wp_send_json_success(
			array(
				'items' => $items,
				'q'     => $q,
			)
		);
	}
}
