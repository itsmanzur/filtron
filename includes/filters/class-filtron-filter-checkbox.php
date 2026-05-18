<?php
/**
 * Checkbox filter (taxonomy / meta values from index).
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Filter_Checkbox
 */
class Filtron_Filter_Checkbox extends Filtron_Filter_Base {

	/**
	 * Cached options from index.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $options_cache = null;

	/**
	 * @see Filtron_Filter_Base::render()
	 */
	public function render(): string {
		if ( ! $this->is_active() ) {
			return '';
		}

		$template = $this->get_template_path( 'checkbox.php' );
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
	 * @see Filtron_Filter_Base::get_query_args()
	 */
	public function get_query_args(): array {
		$values = $this->get_selected_values();
		if ( array() === $values ) {
			return array();
		}

		$key = $this->get_source_key();
		if ( '' === $key ) {
			return array();
		}

		return array(
			array(
				'key'    => $key,
				'values' => array_values( $values ),
				'logic'  => $this->get_logic(),
				'type'   => 'checkbox',
			),
		);
	}

	/**
	 * Distinct values + post counts from index.
	 *
	 * @see Filtron_Filter_Base::get_available_values()
	 */
	public function get_available_values(): array {
		if ( null !== $this->options_cache ) {
			return $this->options_cache;
		}

		$key = $this->get_source_key();
		if ( '' === $key ) {
			$this->options_cache = array();
			return $this->options_cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_index';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		$sql = $wpdb->prepare(
			"SELECT filter_value, COUNT(DISTINCT post_id) AS item_count
			FROM `{$table}`
			WHERE filter_key = %s
			GROUP BY filter_value
			ORDER BY filter_value ASC",
			$key
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$this->options_cache = array();
			return $this->options_cache;
		}

		$labels = isset( $this->config['labels'] ) && is_array( $this->config['labels'] )
			? $this->config['labels']
			: array();

		$out = array();
		foreach ( $rows as $row ) {
			$val = isset( $row['filter_value'] ) ? (string) $row['filter_value'] : '';
			if ( '' === $val ) {
				continue;
			}
			$count = isset( $row['item_count'] ) ? (int) $row['item_count'] : 0;
			$label = isset( $labels[ $val ] ) ? (string) $labels[ $val ] : $val;

			$out[] = array(
				'value' => $val,
				'label' => $label,
				'count' => $count,
			);
		}

		$this->options_cache = $out;
		return $this->options_cache;
	}

	/**
	 * Whether to show facet counts (block/widget option).
	 */
	public function show_counts(): bool {
		if ( array_key_exists( 'show_count', $this->config ) ) {
			return (bool) $this->config['show_count'];
		}
		return true;
	}

	/**
	 * AND / OR for this checkbox group (matches indexer + query).
	 */
	public function get_logic(): string {
		$logic = isset( $this->config['logic'] ) ? strtoupper( (string) $this->config['logic'] ) : 'OR';
		return 'AND' === $logic ? 'AND' : 'OR';
	}

	/**
	 * Selected slugs from URL (?filtron[key]= or ?filtron_key=).
	 *
	 * @return array<int, string>
	 */
	public function get_selected_values(): array {
		$key = $this->get_source_key();
		if ( '' === $key ) {
			return array();
		}

		$raw = null;

		if ( isset( $_GET['filtron'] ) && is_array( $_GET['filtron'] ) ) {
			$filtron = wp_unslash( $_GET['filtron'] );
			if ( isset( $filtron[ $key ] ) ) {
				$raw = $filtron[ $key ];
			}
		}

		if ( null === $raw && isset( $_GET[ 'filtron_' . $key ] ) ) {
			$raw = wp_unslash( $_GET[ 'filtron_' . $key ] );
		}

		if ( null === $raw ) {
			return array();
		}

		if ( is_array( $raw ) ) {
			return array_values(
				array_filter(
					array_map(
						static function ( $v ) {
							return sanitize_text_field( (string) $v );
						},
						$raw
					),
					'strlen'
				)
			);
		}

		return array_values(
			array_filter(
				array_map(
					'trim',
					explode( ',', sanitize_text_field( (string) $raw ) )
				),
				'strlen'
			)
		);
	}

	/**
	 * Whether a value slug is selected (URL).
	 */
	public function is_value_checked( string $value ): bool {
		return in_array( $value, $this->get_selected_values(), true );
	}
}
