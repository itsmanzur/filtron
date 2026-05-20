<?php
/**
 * PSR-4-style autoloader for Filtron_* classes.
 *
 * Examples:
 * - Filtron              → includes/class-filtron.php
 * - Filtron_Query        → includes/core/class-filtron-query.php
 * - Filtron_Filter_Base  → includes/filters/class-filtron-filter-base.php
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Autoload
 */
class Filtron_Autoload {

	/**
	 * First segment after Filtron_ → subdirectory under includes/.
	 *
	 * @var array<string, string>
	 */
	private static array $subdir_map = array(
		'Admin'     => 'admin',
		'Query'     => 'core',
		'Indexer'   => 'core',
		'Cache'     => 'core',
		'Security'  => 'core',
		'Ajax'      => 'api',
		'Rest'      => 'api',
		'Filter'    => 'filters',
		'Settings'  => 'admin',
		'Gutenberg' => 'integrations',
		'Shortcode' => 'integrations',
	);

	/**
	 * Register spl autoload callback.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Load class file if mapped.
	 *
	 * @param string $class Class name.
	 */
	public static function autoload( string $class ): void {
		if ( 'Filtron' === $class ) {
			$path = FILTRON_PLUGIN_DIR . 'includes/class-filtron.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
			return;
		}

		$prefix = 'Filtron_';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$suffix = substr( $class, strlen( $prefix ) );
		if ( '' === $suffix ) {
			return;
		}

		$slug = 'class-filtron-' . strtolower( str_replace( '_', '-', $suffix ) ) . '.php';

		$first = strstr( $suffix, '_', true );
		if ( false === $first ) {
			$first = $suffix;
		}

		$subdir = self::$subdir_map[ $first ] ?? '';
		$dir    = FILTRON_PLUGIN_DIR . 'includes/';
		if ( '' !== $subdir ) {
			$dir .= $subdir . '/';
		}

		$path = $dir . $slug;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
