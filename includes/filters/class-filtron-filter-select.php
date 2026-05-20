<?php
/**
 * Select filter (single taxonomy / meta value from index).
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Filter_Select
 */
class Filtron_Filter_Select extends Filtron_Filter_Checkbox {

	/**
	 * @see Filtron_Filter_Base::render()
	 */
	public function render(): string {
		if ( ! $this->is_active() ) {
			return '';
		}

		$template = $this->get_template_path( 'select.php' );
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
	 * Select is a single equality filter.
	 */
	public function get_query_args(): array {
		$value = $this->get_selected_value();
		$key   = $this->get_source_key();
		if ( '' === $key || '' === $value ) {
			return array();
		}

		return array(
			array(
				'key'    => $key,
				'values' => array( $value ),
				'logic'  => 'OR',
				'type'   => 'select',
			),
		);
	}

	/**
	 * Current selected value from URL.
	 */
	public function get_selected_value(): string {
		$values = $this->get_selected_values();
		return isset( $values[0] ) ? (string) $values[0] : '';
	}

	/**
	 * Placeholder text.
	 */
	public function get_placeholder(): string {
		$placeholder = isset( $this->config['placeholder'] ) ? (string) $this->config['placeholder'] : '';
		if ( '' === $placeholder ) {
			$placeholder = __( 'Any', 'filtron' );
		}
		return self::normalize_display_text( $placeholder );
	}
}
