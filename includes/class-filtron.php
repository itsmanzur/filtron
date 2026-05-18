<?php
/**
 * Main plugin class (singleton).
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron
 */
class Filtron {

	/**
	 * Single instance.
	 *
	 * @var Filtron|null
	 */
	private static ?Filtron $instance = null;

	/**
	 * Hook loader.
	 *
	 * @var Filtron_Loader
	 */
	protected Filtron_Loader $loader;

	/**
	 * Get singleton instance.
	 */
	public static function instance(): Filtron {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Filtron constructor.
	 */
	private function __construct() {
		$this->loader = new Filtron_Loader();
		$this->define_hooks();
		$this->loader->run();
	}

	/**
	 * Register hooks via loader.
	 */
	private function define_hooks(): void {
		$this->loader->add_action( 'plugins_loaded', $this, 'load_textdomain', 5 );
		$this->loader->add_action( 'plugins_loaded', self::class, 'load_indexer', 15 );
		$this->loader->add_action( 'plugins_loaded', self::class, 'load_ajax', 20 );
		$this->loader->add_action( 'plugins_loaded', self::class, 'load_rest', 25 );
		$this->loader->add_action( 'plugins_loaded', self::class, 'load_assets', 12 );
		$this->loader->add_action( 'plugins_loaded', self::class, 'load_admin', 11 );
		$this->loader->add_action( 'plugins_loaded', self::class, 'load_gutenberg', 14 );
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'filtron',
			false,
			dirname( FILTRON_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register indexer hooks after core loads.
	 */
	public static function load_indexer(): void {
		Filtron_Indexer::register();
	}

	/**
	 * Register AJAX handlers.
	 */
	public static function load_ajax(): void {
		Filtron_Ajax::register();
	}

	/**
	 * Register REST API routes.
	 */
	public static function load_rest(): void {
		Filtron_Rest::register();
	}

	/**
	 * Front-end scripts and styles.
	 */
	public static function load_assets(): void {
		Filtron_Assets::register();
	}

	/**
	 * Admin menu and AJAX for filter editor.
	 */
	public static function load_admin(): void {
		Filtron_Admin::register();
	}

	/**
	 * Gutenberg blocks.
	 */
	public static function load_gutenberg(): void {
		Filtron_Gutenberg::register();
	}

	/**
	 * Loader accessor (for tests or extensions).
	 */
	public function loader(): Filtron_Loader {
		return $this->loader;
	}
}
