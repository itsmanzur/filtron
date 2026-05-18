<?php
/**
 * Front-end scripts and styles.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Assets
 */
class Filtron_Assets {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
	}

	/**
	 * Enqueue public CSS/JS and localize filtronVars.
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		/**
		 * Whether to load Filtron frontend assets.
		 *
		 * @param bool $load Default true.
		 */
		if ( ! apply_filters( 'filtron_enqueue_frontend', true ) ) {
			return;
		}

		$ver = defined( 'FILTRON_VERSION' ) ? FILTRON_VERSION : '1.0.0';

		wp_register_style(
			'filtron-skeleton',
			FILTRON_PLUGIN_URL . 'assets/css/filtron-skeleton.css',
			array(),
			$ver
		);
		wp_enqueue_style( 'filtron-skeleton' );

		if ( ! wp_style_is( 'nouislider', 'registered' ) ) {
			wp_register_style(
				'nouislider',
				'https://cdn.jsdelivr.net/npm/nouislider@15.8.1/dist/nouislider.min.css',
				array(),
				'15.8.1'
			);
		}
		wp_enqueue_style( 'nouislider' );
		wp_register_style(
			'filtron-frontend',
			FILTRON_PLUGIN_URL . 'assets/css/filtron-frontend.css',
			array( 'filtron-skeleton', 'nouislider' ),
			$ver
		);
		wp_enqueue_style( 'filtron-frontend' );

		if ( ! wp_script_is( 'nouislider', 'registered' ) ) {
			wp_register_script(
				'nouislider',
				'https://cdn.jsdelivr.net/npm/nouislider@15.8.1/dist/nouislider.min.js',
				array(),
				'15.8.1',
				true
			);
		}
		wp_enqueue_script( 'nouislider' );

		$deps = array( 'nouislider' );

		wp_register_script(
			'filtron-frontend',
			FILTRON_PLUGIN_URL . 'src/js/filtron-frontend.js',
			$deps,
			$ver,
			true
		);
		wp_enqueue_script( 'filtron-frontend' );

		$settings = get_option( Filtron_Activator::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		wp_localize_script(
			'filtron-frontend',
			'filtronVars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'filtron_filter_nonce' ),
				'is_pro'   => defined( 'FILTRON_PRO_VERSION' ),
				'debug'    => array(
					'show' => is_user_logged_in() && current_user_can( 'manage_options' ) && ! empty( $settings['frontend_debug'] ),
				),
				'i18n'     => array(
					'clearAll'      => __( 'Clear all', 'filtron' ),
					'noResults'     => __( 'No results found.', 'filtron' ),
					'networkError'  => __( 'Network issue. Please check your connection.', 'filtron' ),
					'serverError'   => __( 'Unable to load results right now.', 'filtron' ),
					'retry'         => __( 'Retry', 'filtron' ),
					'loading'       => __( 'Loading…', 'filtron' ),
					'firstPage'     => __( 'Already on first page', 'filtron' ),
					'noMorePages'   => __( 'No more pages', 'filtron' ),
					'noMoreResults' => __( 'No more results', 'filtron' ),
					'filters'       => __( 'Filters', 'filtron' ),
					/* translators: %s: number of active filter selections */
					'activeCount'   => __( '%s active', 'filtron' ),
					/* translators: %s: number of matching posts */
					'resultsCount'  => __( '%s results', 'filtron' ),
					/* translators: 1: filter key, 2: selected value */
					'chipRemoveCheckbox' => __( 'Remove filter: %1$s equals %2$s', 'filtron' ),
					'chipRemoveRange'    => __( 'Remove range filter: %s', 'filtron' ),
					'chipRemoveSearch'   => __( 'Remove search filter: %s', 'filtron' ),
					'clearFilters'       => __( 'Clear all filters', 'filtron' ),
					'resetRanges'        => __( 'Reset price and range sliders', 'filtron' ),
					'paginationPrev'     => __( 'Previous results page', 'filtron' ),
					'paginationNext'     => __( 'Next results page', 'filtron' ),
					'paginationStatus'   => __( 'Page %1$s of %2$s', 'filtron' ),
					'toolbarSort'        => __( 'Sort results', 'filtron' ),
					'toolbarLayout'      => __( 'Result layout', 'filtron' ),
					'debugCacheHit'      => __( 'Cache hit', 'filtron' ),
					'debugFresh'         => __( 'Fresh query', 'filtron' ),
					'debugQueries'       => __( '%s SQL queries', 'filtron' ),
					'debugServerMs'      => __( '%s ms server', 'filtron' ),
					'debugAjaxMs'        => __( '%s ms AJAX', 'filtron' ),
				),
			)
		);
	}
}
