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
		$value = self::decode_mojibake_entities( $value );
		$value = wp_check_invalid_utf8( $value );
		if ( '' === $value || ( ! self::looks_like_mojibake( $value ) && ! self::looks_like_mojibake_bytes( $value ) ) ) {
			return $value;
		}

		if ( function_exists( 'mb_ord' ) && function_exists( 'mb_check_encoding' ) ) {
			$bytes = self::latin1_codepoints_to_bytes( $value );
			if ( null !== $bytes && mb_check_encoding( $bytes, 'UTF-8' ) ) {
				return wp_check_invalid_utf8( $bytes );
			}

			$bytes = self::windows1252_codepoints_to_bytes( $value );
			if ( null !== $bytes && mb_check_encoding( $bytes, 'UTF-8' ) ) {
				return wp_check_invalid_utf8( $bytes );
			}
		}

		return $value;
	}

	/**
	 * Repair entity-encoded mojibake fragments produced by late content filters.
	 *
	 * @param string $html Rendered HTML.
	 */
	public static function repair_mojibake_entities_in_html( string $html ): string {
		if ( '' === $html || false === strpos( $html, '&' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/(?:&(?:[A-Za-z][A-Za-z0-9]+|#[0-9]+|#x[0-9A-Fa-f]+);){2,}/',
			static function ( array $matches ): string {
				$decoded = self::decode_mojibake_entities( (string) $matches[0] );
				$fixed   = self::normalize_display_text( $decoded );
				if ( $fixed !== $decoded && self::contains_bengali( $fixed ) ) {
					return esc_html( $fixed );
				}
				return (string) $matches[0];
			},
			$html
		);
	}

	/**
	 * Decode HTML entities, including Windows-1252 numeric controls used in mojibake.
	 *
	 * @param string $value Text or entity fragment.
	 */
	private static function decode_mojibake_entities( string $value ): string {
		if ( false === strpos( $value, '&' ) ) {
			return $value;
		}

		$value = preg_replace_callback(
			'/&#(1[2-5][0-9]);/',
			static function ( array $matches ): string {
				$codepoint = (int) $matches[1];
				$map       = self::windows1252_control_codepoint_map();
				if ( ! isset( $map[ $codepoint ] ) ) {
					return (string) $matches[0];
				}
				return self::codepoint_to_utf8( $map[ $codepoint ] );
			},
			$value
		);

		return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Whether text contains Bengali Unicode characters.
	 *
	 * @param string $value Text.
	 */
	private static function contains_bengali( string $value ): bool {
		return 1 === preg_match( '/\p{Bengali}/u', $value );
	}

	/**
	 * Encode one codepoint as UTF-8.
	 *
	 * @param int $codepoint Unicode codepoint.
	 */
	private static function codepoint_to_utf8( int $codepoint ): string {
		if ( function_exists( 'mb_chr' ) ) {
			return (string) mb_chr( $codepoint, 'UTF-8' );
		}

		return html_entity_decode( '&#' . $codepoint . ';', ENT_NOQUOTES, 'UTF-8' );
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
	 * Rebuild original bytes from Windows-1252 mojibake characters.
	 *
	 * @param string $value Mojibake string.
	 * @return string|null
	 */
	private static function windows1252_codepoints_to_bytes( string $value ): ?string {
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return null;
		}

		$map = array_flip( self::windows1252_control_codepoint_map() );

		$bytes = '';
		foreach ( $chars as $char ) {
			$codepoint = mb_ord( $char, 'UTF-8' );
			if ( false === $codepoint ) {
				return null;
			}
			if ( isset( $map[ $codepoint ] ) ) {
				$bytes .= chr( $map[ $codepoint ] );
				continue;
			}
			if ( $codepoint > 255 ) {
				return null;
			}
			$bytes .= chr( $codepoint );
		}

		return $bytes;
	}

	/**
	 * Windows-1252 C1 control byte to Unicode codepoint map.
	 *
	 * @return array<int, int>
	 */
	private static function windows1252_control_codepoint_map(): array {
		return array(
			0x80 => 0x20AC,
			0x82 => 0x201A,
			0x83 => 0x0192,
			0x84 => 0x201E,
			0x85 => 0x2026,
			0x86 => 0x2020,
			0x87 => 0x2021,
			0x88 => 0x02C6,
			0x89 => 0x2030,
			0x8A => 0x0160,
			0x8B => 0x2039,
			0x8C => 0x0152,
			0x8E => 0x017D,
			0x91 => 0x2018,
			0x92 => 0x2019,
			0x93 => 0x201C,
			0x94 => 0x201D,
			0x95 => 0x2022,
			0x96 => 0x2013,
			0x97 => 0x2014,
			0x98 => 0x02DC,
			0x99 => 0x2122,
			0x9A => 0x0161,
			0x9B => 0x203A,
			0x9C => 0x0153,
			0x9E => 0x017E,
			0x9F => 0x0178,
		);
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
