<?php
/**
 * Abstract filter UI + index-backed options.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Filter_Base
 */
abstract class Filtron_Filter_Base {

	/**
	 * Instance configuration (label, source_key, etc.).
	 *
	 * @var array<string, mixed>
	 */
	protected array $config;

	/**
	 * Stable DOM id prefix from uniqid().
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * @param array<string, mixed> $config Filter configuration.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
		$this->id     = uniqid( 'filtron_' );
	}

	/**
	 * Markup for this filter.
	 */
	abstract public function render(): string;

	/**
	 * Arguments for {@see Filtron_Query::run()} (normalized filter rows).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract public function get_query_args(): array;

	/**
	 * Options from {@see wp_filtron_index} (value, count, optional label).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract public function get_available_values(): array;

	/**
	 * Human-readable label (escaped).
	 */
	public function get_label(): string {
		return esc_html( (string) ( $this->config['label'] ?? '' ) );
	}

	/**
	 * Unique instance id (uniqid filtron_ prefix).
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Whether this filter is enabled.
	 */
	public function is_active(): bool {
		if ( array_key_exists( 'is_active', $this->config ) ) {
			return (bool) $this->config['is_active'];
		}
		return true;
	}

	/**
	 * Raw config (read-only access for templates).
	 *
	 * @return array<string, mixed>
	 */
	protected function get_config(): array {
		return $this->config;
	}

	/**
	 * Index / query filter_key (taxonomy slug, meta key, etc.).
	 */
	protected function get_source_key(): string {
		if ( ! empty( $this->config['source_key'] ) ) {
			return (string) $this->config['source_key'];
		}
		if ( ! empty( $this->config['key'] ) ) {
			return (string) $this->config['key'];
		}
		return '';
	}

	/**
	 * Resolved template path (override via filtron_template_path).
	 *
	 * @param string $basename Relative to templates/filter-types/ e.g. checkbox.php.
	 */
	protected function get_template_path( string $basename ): string {
		$default = trailingslashit( FILTRON_PLUGIN_DIR ) . 'templates/filter-types/' . ltrim( $basename, '/' );

		/**
		 * Filter template file path for a filter type.
		 *
		 * @param string               $default   Default path.
		 * @param string               $basename  Template basename.
		 * @param Filtron_Filter_Base $instance  Filter instance.
		 */
		return (string) apply_filters( 'filtron_template_path', $default, $basename, $this );
	}
}
