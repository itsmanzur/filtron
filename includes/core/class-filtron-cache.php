<?php
/**
 * Transient or object-cache storage with group isolation and cheap group flush.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Cache
 */
class Filtron_Cache {

	/**
	 * WordPress object cache group (Redis/Memcached isolation).
	 */
	public const GROUP = 'filtron';

	/**
	 * Option: incremented to invalidate all logical keys without enumerating entries.
	 */
	private const GENERATION_OPTION = 'filtron_cache_generation';

	/**
	 * Cached generation for this request.
	 *
	 * @var string|null
	 */
	private static ?string $generation_cache = null;

	/**
	 * Get cached value.
	 *
	 * @param string $key Logical key (e.g. from make_key()).
	 * @return mixed|false False on miss; note: cannot distinguish stored false from miss with transients.
	 */
	public static function get( string $key ) {
		$vk = self::versioned_key( $key );

		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $vk, self::GROUP );
			return false === $value ? false : $value;
		}

		$t = get_transient( self::transient_name( $vk ) );
		return false === $t ? false : $t;
	}

	/**
	 * Store value with TTL (seconds).
	 *
	 * @param string $key     Logical key.
	 * @param mixed  $value   Value (avoid storing bare false with transient backend).
	 * @param int    $expiry  TTL in seconds.
	 */
	public static function set( string $key, $value, int $expiry = 3600 ): bool {
		$vk = self::versioned_key( $key );

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_set( $vk, $value, self::GROUP, $expiry );
		}

		return set_transient( self::transient_name( $vk ), $value, $expiry );
	}

	/**
	 * Delete one logical key in current generation.
	 *
	 * @param string $key Logical key.
	 */
	public static function delete( string $key ): bool {
		$vk = self::versioned_key( $key );

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_delete( $vk, self::GROUP );
		}

		return delete_transient( self::transient_name( $vk ) );
	}

	/**
	 * Clear all entries for this plugin’s logical group (default filtron).
	 *
	 * @param string $group Logical group name (reserved for future multi-bucket namespaces).
	 */
	public static function flush_group( string $group = 'filtron' ): void {
		unset( $group );

		self::bump_generation();

		if ( function_exists( 'wp_cache_flush_group' ) && ( ! function_exists( 'wp_cache_supports' ) || wp_cache_supports( 'flush_group' ) ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}

	/**
	 * Clear all Filtron caches (admin “Clear cache”).
	 */
	public static function flush_all(): void {
		self::flush_group( self::GROUP );
	}

	/**
	 * Stable cache key from structured args.
	 *
	 * @param array<string|int, mixed> $args Serializable payload.
	 */
	public static function make_key( array $args ): string {
		return md5( serialize( $args ) );
	}

	/**
	 * Current invalidation generation (bumped on flush_group / index rebuild).
	 */
	private static function generation(): string {
		if ( null === self::$generation_cache ) {
			$g = get_option( self::GENERATION_OPTION, '1' );
			self::$generation_cache = is_string( $g ) ? $g : '1';
		}
		return self::$generation_cache;
	}

	/**
	 * Bump generation so all versioned keys miss without listing transients.
	 */
	private static function bump_generation(): void {
		$next = (string) ( (int) self::generation() + 1 );
		update_option( self::GENERATION_OPTION, $next, false );
		self::$generation_cache = $next;
	}

	/**
	 * Key scoped to current generation.
	 *
	 * @param string $key Logical key.
	 */
	private static function versioned_key( string $key ): string {
		return self::generation() . ':' . $key;
	}

	/**
	 * Transient option name (<= 191 chars for wp_options).
	 *
	 * @param string $versioned_key Generation + logical key.
	 */
	private static function transient_name( string $versioned_key ): string {
		return 'filtron_' . md5( $versioned_key );
	}
}
