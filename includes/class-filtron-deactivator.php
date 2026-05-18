<?php
/**
 * Plugin deactivation.
 *
 * @package Filtron
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Deactivator
 */
class Filtron_Deactivator {

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		// Flush caches / rewrite rules when implemented.
	}
}
