<?php
/**
 * Centralized nonce verification, sanitization, validation, and escaping.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Security
 */
class Filtron_Security {

	/**
	 * Allowed filter type slugs.
	 *
	 * @var array<int, string>
	 */
	private const FILTER_TYPES = array( 'checkbox', 'range', 'search', 'swatch', 'select' );

	/**
	 * Allowed source type slugs.
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_TYPES = array( 'taxonomy', 'meta', 'author', 'date' );

	/**
	 * Max recursion depth for nested arrays in sanitize_filter_input().
	 */
	private const SANITIZE_MAX_DEPTH = 10;

	/**
	 * Verify AJAX nonce; on failure send JSON error and halt.
	 *
	 * @param string|null $nonce  Nonce string (e.g. from request; pass after wp_unslash if needed).
	 * @param string      $action Nonce action.
	 */
	public static function verify_nonce( ?string $nonce, string $action = 'filtron_filter_nonce' ): void {
		$nonce = null !== $nonce ? wp_unslash( $nonce ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token.', 'filtron' ),
				),
				403
			);
			die();
		}
	}

	/**
	 * Sanitize filter payload array (recursive).
	 *
	 * @param array<string|int, mixed> $input Raw input array.
	 * @return array<string|int, mixed>
	 */
	public static function sanitize_filter_input( array $input ): array {
		return self::sanitize_filter_input_depth( $input, 0 );
	}

	/**
	 * @param array<string|int, mixed> $input Raw input.
	 * @param int                        $depth Current depth.
	 * @return array<string|int, mixed>
	 */
	private static function sanitize_filter_input_depth( array $input, int $depth ): array {
		if ( $depth > self::SANITIZE_MAX_DEPTH ) {
			error_log( 'Filtron_Security: sanitize_filter_input max depth exceeded.' );
			return array();
		}

		$out = array();

		foreach ( $input as $key => $value ) {
			if ( is_string( $key ) ) {
				$key_out = sanitize_key( $key );
			} elseif ( is_int( $key ) ) {
				$key_out = $key;
			} else {
				error_log( 'Filtron_Security: invalid array key type ' . gettype( $key ) );
				continue;
			}

			$sanitized = self::sanitize_value( $value, $depth + 1 );
			if ( null !== $sanitized ) {
				$out[ $key_out ] = $sanitized;
			}
		}

		return $out;
	}

	/**
	 * Sanitize a single value; unknown types are rejected (null return).
	 *
	 * @param mixed $value Raw value.
	 * @param int   $depth Current depth for nested arrays.
	 * @return mixed|null
	 */
	private static function sanitize_value( $value, int $depth ) {
		if ( is_array( $value ) ) {
			if ( $depth > self::SANITIZE_MAX_DEPTH ) {
				error_log( 'Filtron_Security: nested array too deep.' );
				return null;
			}
			return self::sanitize_array_with_whitelist_map( $value, $depth );
		}

		if ( is_object( $value ) || is_resource( $value ) ) {
			error_log( 'Filtron_Security: rejected value type ' . gettype( $value ) );
			return null;
		}

		if ( is_bool( $value ) ) {
			error_log( 'Filtron_Security: rejected boolean in filter input.' );
			return null;
		}

		if ( null === $value ) {
			return null;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return floatval( $value );
		}

		if ( is_string( $value ) ) {
			if ( is_numeric( $value ) ) {
				return floatval( $value );
			}
			return sanitize_text_field( $value );
		}

		error_log( 'Filtron_Security: unknown scalar type ' . gettype( $value ) );
		return null;
	}

	/**
	 * Recursively sanitize array values; list values pass through array_map-style whitelist sanitization.
	 *
	 * @param array<string|int, mixed> $value Array value.
	 * @param int                      $depth Parent depth (child uses depth+1).
	 * @return array<string|int, mixed>|null
	 */
	private static function sanitize_array_with_whitelist_map( array $value, int $depth ): ?array {
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) ) {
				$k_out = sanitize_key( $k );
			} elseif ( is_int( $k ) ) {
				$k_out = $k;
			} else {
				error_log( 'Filtron_Security: invalid nested array key type ' . gettype( $k ) );
				continue;
			}
			$sv = self::sanitize_value( $v, $depth + 1 );
			if ( null !== $sv ) {
				$out[ $k_out ] = $sv;
			}
		}

		$indexed = array_keys( $out ) === range( 0, count( $out ) - 1 );
		if ( $indexed && self::array_is_list_of_strings( $out ) ) {
			return self::whitelist_string_list( $out );
		}

		return $out;
	}

	/**
	 * @param array<int|string, mixed> $arr Array to check.
	 */
	private static function array_is_list_of_strings( array $arr ): bool {
		foreach ( $arr as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whitelist-sanitize a flat list of strings (taxonomy slugs, etc.).
	 *
	 * @param array<int, string> $strings Indexed string list.
	 * @return array<int, string>
	 */
	private static function whitelist_string_list( array $strings ): array {
		$clean = array_map( 'sanitize_text_field', $strings );
		$clean = array_filter(
			array_unique( $clean ),
			static function ( $s ) {
				return '' !== $s;
			}
		);
		return array_values( $clean );
	}

	/**
	 * Whether filter type is allowed.
	 *
	 * @param string $type Filter type slug.
	 */
	public static function validate_filter_type( string $type ): bool {
		return in_array( $type, self::FILTER_TYPES, true );
	}

	/**
	 * Whether source type is allowed.
	 *
	 * @param string $type Source type slug.
	 */
	public static function validate_source_type( string $type ): bool {
		return in_array( $type, self::SOURCE_TYPES, true );
	}

	/**
	 * Escape output for HTML context.
	 *
	 * @param mixed $value Value to escape.
	 */
	public static function escape_output( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return esc_html( wp_json_encode( $value ) );
		}
		if ( is_bool( $value ) ) {
			return esc_html( $value ? '1' : '0' );
		}
		if ( is_scalar( $value ) || null === $value ) {
			return esc_html( (string) $value );
		}
		return esc_html( '' );
	}

	/**
	 * Public post types registered in WordPress.
	 *
	 * @return array<int, string>
	 */
	public static function get_allowed_post_types(): array {
		$types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);
		return array_values( $types );
	}
}
