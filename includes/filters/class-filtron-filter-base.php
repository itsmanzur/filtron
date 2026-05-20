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
		return esc_html( self::normalize_display_text( (string) ( $this->config['label'] ?? '' ) ) );
	}

	/**
	 * Normalize display text and repair common UTF-8-as-Latin-1 mojibake.
	 *
	 * @param string $value Raw display value.
	 */
	public static function normalize_display_text( string $value ): string {
		$value = wp_check_invalid_utf8( $value );
		if ( '' === $value || ( ! self::looks_like_mojibake( $value ) && ! self::looks_like_mojibake_bytes( $value ) ) ) {
			return $value;
		}

		if ( function_exists( 'mb_ord' ) && function_exists( 'mb_check_encoding' ) ) {
			$bytes = self::latin1_codepoints_to_bytes( $value );
			if ( null !== $bytes && mb_check_encoding( $bytes, 'UTF-8' ) ) {
				return wp_check_invalid_utf8( $bytes );
			}
		}

		return $value;
	}

	/**
	 * Whether a string contains common mojibake markers.
	 *
	 * @param string $value Display value.
	 */
	private static function looks_like_mojibake( string $value ): bool {
		return false !== strpos( $value, 'Ã' )
			|| false !== strpos( $value, 'Â' )
			|| false !== strpos( $value, 'â' )
			|| false !== strpos( $value, 'à¦' )
			|| false !== strpos( $value, 'à§' );
	}

	/**
	 * Byte-safe mojibake marker check. Keep this ASCII-only to avoid editor encoding drift.
	 *
	 * @param string $value Display value.
	 */
	private static function looks_like_mojibake_bytes( string $value ): bool {
		return false !== strpos( $value, "\xC3\x83" )
			|| false !== strpos( $value, "\xC3\x82" )
			|| false !== strpos( $value, "\xC3\xA2" )
			|| false !== strpos( $value, "\xC3\xA0\xC2\xA6" )
			|| false !== strpos( $value, "\xC3\xA0\xC2\xA7" );
	}

	/**
	 * Rebuild original bytes from single-byte-codepage mojibake characters.
	 *
	 * @param string $value Mojibake string.
	 * @return string|null
	 */
	private static function latin1_codepoints_to_bytes( string $value ): ?string {
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return null;
		}

		$bytes = '';
		foreach ( $chars as $char ) {
			$codepoint = mb_ord( $char, 'UTF-8' );
			if ( false === $codepoint || $codepoint > 255 ) {
				return null;
			}
			$bytes .= chr( $codepoint );
		}

		return $bytes;
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
