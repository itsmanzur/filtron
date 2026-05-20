<?php
/**
 * Numeric range filter (uses filter_value_num in index).
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Filter_Range
 */
class Filtron_Filter_Range extends Filtron_Filter_Base {

	/**
	 * Cached MIN/MAX from index.
	 *
	 * @var array{min: float, max: float}|null
	 */
	private ?array $min_max_cache = null;

	/**
	 * @see Filtron_Filter_Base::render()
	 */
	public function render(): string {
		if ( ! $this->is_active() ) {
			return '';
		}

		$template = $this->get_template_path( 'range.php' );
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
	 * Range row for {@see Filtron_Query}.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_query_args(): array {
		$key = $this->get_source_key();
		if ( '' === $key ) {
			return array();
		}

		$bounds = $this->get_min_max();
		$sel    = $this->get_selected_range();

		$min = $sel['min'];
		$max = $sel['max'];

		if ( null === $min || null === $max ) {
			return array();
		}

		$min = max( (float) $bounds['min'], (float) $min );
		$max = min( (float) $bounds['max'], (float) $max );
		if ( $min > $max ) {
			return array();
		}

		return array(
			array(
				'key'  => $key,
				'min'  => $min,
				'max'  => $max,
				'type' => 'range',
			),
		);
	}

	/**
	 * Single summary row for facet APIs (global span in index).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_values(): array {
		$mm = $this->get_min_max();
		return array(
			array(
				'min' => $mm['min'],
				'max' => $mm['max'],
			),
		);
	}

	/**
	 * MIN/MAX numeric bounds from index for this filter_key.
	 *
	 * @return array{min: float, max: float}
	 */
	public function get_min_max(): array {
		if ( null !== $this->min_max_cache ) {
			return $this->min_max_cache;
		}

		$key = $this->get_source_key();
		if ( '' === $key ) {
			$this->min_max_cache = $this->fallback_bounds();
			return $this->min_max_cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_index';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		$sql = $wpdb->prepare(
			"SELECT MIN(filter_value_num) AS min_v, MAX(filter_value_num) AS max_v
			FROM `{$table}`
			WHERE filter_key = %s AND filter_value_num IS NOT NULL",
			$key
		);

		$row = $wpdb->get_row( $sql, ARRAY_A );
		$min = isset( $row['min_v'] ) && null !== $row['min_v'] ? (float) $row['min_v'] : null;
		$max = isset( $row['max_v'] ) && null !== $row['max_v'] ? (float) $row['max_v'] : null;

		if ( null === $min || null === $max || $min > $max ) {
			$this->min_max_cache = $this->fallback_bounds();
			return $this->min_max_cache;
		}

		$this->min_max_cache = array(
			'min' => $min,
			'max' => $max,
		);
		return $this->min_max_cache;
	}

	/**
	 * Config or safe default when index is empty.
	 *
	 * @return array{min: float, max: float}
	 */
	private function fallback_bounds(): array {
		$min = isset( $this->config['default_min'] ) ? (float) $this->config['default_min'] : 0.0;
		$max = isset( $this->config['default_max'] ) ? (float) $this->config['default_max'] : 100.0;
		if ( $min > $max ) {
			$t   = $min;
			$min = $max;
			$max = $t;
		}
		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * Step for slider / inputs.
	 */
	public function get_step(): float {
		if ( isset( $this->config['step'] ) && is_numeric( $this->config['step'] ) ) {
			return max( (float) $this->config['step'], 0.0001 );
		}
		return 1.0;
	}

	/**
	 * Currency or unit prefix (already safe for HTML context as plain text).
	 */
	public function get_prefix(): string {
		return isset( $this->config['prefix'] ) ? self::normalize_display_text( (string) $this->config['prefix'] ) : '';
	}

	/**
	 * Suffix after values (e.g. currency symbol on the right).
	 */
	public function get_suffix(): string {
		return isset( $this->config['suffix'] ) ? self::normalize_display_text( (string) $this->config['suffix'] ) : '';
	}

	/**
	 * URL segment for query vars: ?filtron_{slug}_min= & filtron_{slug}_max=
	 * e.g. _price → price → filtron_price_min.
	 */
	public function get_url_slug(): string {
		if ( ! empty( $this->config['url_slug'] ) ) {
			return sanitize_key( (string) $this->config['url_slug'] );
		}
		$k = $this->get_source_key();
		$k = preg_replace( '/^_+/', '', $k );
		$k = str_replace( array( '.', ' ' ), '-', $k );
		$s = sanitize_key( $k );
		return '' !== $s ? $s : 'range';
	}

	/**
	 * GET param names for current / min / max.
	 *
	 * @return array{min: string, max: string}
	 */
	public function get_url_param_names(): array {
		$slug = $this->get_url_slug();
		return array(
			'min' => 'filtron_' . $slug . '_min',
			'max' => 'filtron_' . $slug . '_max',
		);
	}

	/**
	 * Selected range from URL (?filtron_{slug}_min / _max).
	 *
	 * @return array{min: float|null, max: float|null}
	 */
	public function get_selected_range(): array {
		$params = $this->get_url_param_names();
		$min    = null;
		$max    = null;

		if ( isset( $_GET[ $params['min'] ] ) ) {
			$min = floatval( wp_unslash( $_GET[ $params['min'] ] ) );
		}
		if ( isset( $_GET[ $params['max'] ] ) ) {
			$max = floatval( wp_unslash( $_GET[ $params['max'] ] ) );
		}

		$bounds = $this->get_min_max();
		if ( null === $min ) {
			$min = (float) $bounds['min'];
		}
		if ( null === $max ) {
			$max = (float) $bounds['max'];
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}
}
