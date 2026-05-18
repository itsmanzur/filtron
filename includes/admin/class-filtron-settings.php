<?php
/**
 * Settings API registration for Filtron options.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Settings
 */
class Filtron_Settings {

	/**
	 * Option group for Settings API (settings_fields / do_settings_sections).
	 */
	public const OPTION_GROUP = 'filtron_settings_group';

	/**
	 * Register settings, sections, and fields on admin_init.
	 */
	public static function register(): void {
		register_setting(
			self::OPTION_GROUP,
			Filtron_Activator::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => Filtron_Activator::default_settings(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'filtron_section_general',
			__( 'General', 'filtron' ),
			array( self::class, 'render_section_general' ),
			'filtron-settings-page'
		);

		add_settings_field(
			'delete_data_on_uninstall',
			__( 'Remove data on uninstall', 'filtron' ),
			array( self::class, 'field_delete_data_on_uninstall' ),
			'filtron-settings-page',
			'filtron_section_general'
		);
	}

	/**
	 * Section description.
	 */
	public static function render_section_general(): void {
		echo '<p class="description">' . esc_html__( 'These options are also available on the Filtron Settings screen with the full theme editor.', 'filtron' ) . '</p>';
	}

	/**
	 * Checkbox: delete_data_on_uninstall.
	 */
	public static function field_delete_data_on_uninstall(): void {
		$opts = get_option( Filtron_Activator::OPTION_SETTINGS, Filtron_Activator::default_settings() );
		if ( ! is_array( $opts ) ) {
			$opts = Filtron_Activator::default_settings();
		}
		$opts  = wp_parse_args( $opts, Filtron_Activator::default_settings() );
		$check = ! empty( $opts['delete_data_on_uninstall'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Filtron_Activator::OPTION_SETTINGS ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $check ); ?> />
			<?php esc_html_e( 'When the plugin is deleted, remove Filtron database tables and options.', 'filtron' ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitize settings array; preserve internal flags; verify nonce when present.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $value ): array {
		$defaults = Filtron_Activator::default_settings();
		$prev     = get_option( Filtron_Activator::OPTION_SETTINGS, $defaults );
		if ( ! is_array( $prev ) ) {
			$prev = $defaults;
		}
		$prev = wp_parse_args( $prev, $defaults );

		$nonce_ok = false;
		if ( isset( $_POST['filtron_admin_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['filtron_admin_nonce'] ) ), Filtron_Admin::NONCE_ACTION ) ) {
			$nonce_ok = true;
		}
		if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::OPTION_GROUP . '-options' ) ) {
			$nonce_ok = true;
		}
		if ( ! $nonce_ok ) {
			return $prev;
		}

		if ( ! is_array( $value ) ) {
			$value = array();
		}

		$out                             = $prev;
		$out['delete_data_on_uninstall'] = ! empty( $value['delete_data_on_uninstall'] );
		$out['index_needs_rebuild']      = ! empty( $prev['index_needs_rebuild'] );
		if ( array_key_exists( 'frontend_debug', $value ) ) {
			$out['frontend_debug'] = ! empty( $value['frontend_debug'] );
		}
		unset( $out['remove_data_on_uninstall'] );

		return $out;
	}
}
