<?php
/**
 * Admin: menus, notices, tools, filter editor, AJAX.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Admin
 */
class Filtron_Admin {

	/**
	 * Nonce action for admin AJAX/forms.
	 */
	public const NONCE_ACTION = 'filtron_admin_nonce';

	/**
	 * Screen option: groups list per page (user meta key).
	 */
	private const SCREEN_OPTION_GROUPS_PER_PAGE = 'filtron_groups_per_page';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( 'Filtron_Settings', 'register' ) );
		add_action( 'admin_init', array( self::class, 'handle_tools_post' ), 1 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( self::class, 'admin_notices' ) );
		add_action( 'load-toplevel_page_filtron-groups', array( self::class, 'load_groups_screen' ) );

		add_filter( 'set_screen_option_' . self::SCREEN_OPTION_GROUPS_PER_PAGE, array( self::class, 'set_groups_per_page' ), 10, 3 );

		add_action( 'wp_ajax_filtron_save_filter', array( self::class, 'ajax_save_filter' ) );
		add_action( 'wp_ajax_filtron_reorder_filters', array( self::class, 'ajax_reorder_filters' ) );
		add_action( 'wp_ajax_filtron_delete_filter', array( self::class, 'ajax_delete_filter' ) );
	}

	/**
	 * Top-level Filtron menu and submenus.
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'Filtron', 'filtron' ),
			__( 'Filtron', 'filtron' ),
			'manage_options',
			'filtron-groups',
			array( self::class, 'render_groups_page' ),
			'dashicons-filter',
			56
		);

		add_submenu_page(
			'filtron-groups',
			__( 'Filter Groups', 'filtron' ),
			__( 'Filter Groups', 'filtron' ),
			'manage_options',
			'filtron-groups',
			array( self::class, 'render_groups_page' )
		);

		if ( defined( 'FILTRON_PRO_VERSION' ) ) {
			add_submenu_page(
				'filtron-groups',
				__( 'Analytics Dashboard', 'filtron' ),
				__( 'Analytics', 'filtron' ),
				'manage_options',
				'filtron-analytics',
				array( self::class, 'render_analytics_page' )
			);
		}

		add_submenu_page(
			'filtron-groups',
			__( 'Settings', 'filtron' ),
			__( 'Settings', 'filtron' ),
			'manage_options',
			'filtron-settings',
			array( self::class, 'render_settings_page' )
		);

		add_submenu_page(
			'filtron-groups',
			__( 'Tools', 'filtron' ),
			__( 'Tools', 'filtron' ),
			'manage_options',
			'filtron-tools',
			array( self::class, 'render_tools_page' )
		);

		if ( ! defined( 'FILTRON_PRO_VERSION' ) ) {
			add_submenu_page(
				'filtron-groups',
				__( 'Upgrade to Pro', 'filtron' ),
				__( 'Upgrade to Pro', 'filtron' ),
				'manage_options',
				'filtron-upgrade',
				array( self::class, 'render_upgrade_page' )
			);
		}
	}

	/**
	 * Screen options for the groups list (not the filter editor).
	 */
	public static function load_groups_screen(): void {
		if ( isset( $_GET['action'], $_GET['group'] ) && 'edit' === $_GET['action'] ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Groups per page', 'filtron' ),
				'default' => 20,
				'option'  => self::SCREEN_OPTION_GROUPS_PER_PAGE,
			)
		);
	}

	/**
	 * Persist screen option value.
	 *
	 * @param mixed $screen_option Previous value (unused).
	 * @param string $option Option name.
	 * @param int   $value New value.
	 * @return int
	 */
	public static function set_groups_per_page( $screen_option, string $option, $value ): int {
		unset( $screen_option, $option );
		return max( 1, min( 200, (int) $value ) );
	}

	/**
	 * Groups per page for current user.
	 */
	private static function get_groups_per_page(): int {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return 20;
		}
		$v = (int) get_user_meta( $user_id, self::SCREEN_OPTION_GROUPS_PER_PAGE, true );
		return $v > 0 ? max( 1, min( 200, $v ) ) : 20;
	}

	/**
	 * Global admin notices (rebuild warning).
	 */
	public static function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( $screen->id, 'filtron' ) === false ) {
			return;
		}

		$opts = get_option( Filtron_Activator::OPTION_SETTINGS, array() );
		if ( is_array( $opts ) && ! empty( $opts['index_needs_rebuild'] ) ) {
			$tools = admin_url( 'admin.php?page=filtron-tools' );
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Filtron recommends rebuilding the search index after recent changes.', 'filtron' );
			echo ' ';
			echo '<a href="' . esc_url( $tools ) . '">' . esc_html__( 'Open Tools', 'filtron' ) . '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * Pro upsell notice on Filtron screens (excluding Upgrade page).
	 */
	public static function maybe_pro_upsell_notice(): void {
		if ( defined( 'FILTRON_PRO_VERSION' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( 'filtron-upgrade' === $page ) {
			return;
		}
		if ( ! in_array( $page, array( 'filtron-settings', 'filtron-tools' ), true ) ) {
			return;
		}

		$up = admin_url( 'admin.php?page=filtron-upgrade' );
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Go beyond checkbox, range, and search: analytics, swatches, REST, and WooCommerce-ready workflows in Filtron Pro.', 'filtron' );
		echo ' <a href="' . esc_url( $up ) . '">' . esc_html__( 'Compare plans', 'filtron' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Pro upsell strip for Filter Groups list (styled bar; list page only).
	 */
	private static function render_groups_upsell_bar(): void {
		if ( defined( 'FILTRON_PRO_VERSION' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$up = admin_url( 'admin.php?page=filtron-upgrade' );
		echo '<div class="filtron-upsell-bar" role="region" aria-label="' . esc_attr__( 'Filtron Pro', 'filtron' ) . '">';
		echo '<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>';
		echo '<div class="filtron-upsell-bar__body">';
		echo '<p class="filtron-upsell-bar__text">' . esc_html__( 'Ship faster filters with Pro: analytics, swatches, REST, and deep WooCommerce tools.', 'filtron' ) . '</p>';
		echo '<p class="filtron-upsell-bar__sub">' . esc_html__( 'Free stays fast and secure; Pro adds the growth layer.', 'filtron' ) . '</p>';
		echo '</div>';
		echo '<div class="filtron-upsell-bar__actions">';
		echo '<a class="filtron-btn filtron-btn-primary filtron-btn-sm" href="' . esc_url( $up ) . '">' . esc_html__( 'See Pro plans', 'filtron' ) . '</a>';
		echo '<a class="filtron-upsell-bar__link" href="' . esc_url( $up ) . '">' . esc_html__( 'Learn more', 'filtron' ) . '</a>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Router: group list or filter editor.
	 */
	public static function render_groups_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['action'] ) && 'add' === $_GET['action'] ) {
			self::handle_add_group_request();
			return;
		}

		if ( isset( $_GET['action'], $_GET['group'] ) && 'edit' === $_GET['action'] ) {
			self::render_group_edit( absint( $_GET['group'] ) );
			return;
		}

		self::render_groups_list();
	}

	/**
	 * Create a new empty group ("Add New") and redirect back to the list.
	 */
	private static function handle_add_group_request(): void {
		check_admin_referer( 'filtron_add_group', 'filtron_admin_nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_groups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order), 0) FROM `{$table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'name'        => __( 'New group', 'filtron' ),
				'post_type'   => 'product',
				'display_loc' => 'sidebar',
				'sort_order'  => $max + 1,
				'is_active'   => 1,
			),
			array( '%s', '%s', '%s', '%d', '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=filtron-groups' ) );
		exit;
	}

	/**
	 * Paginated list of filter groups.
	 */
	private static function render_groups_list(): void {
		global $wpdb;

		$per_page = self::get_groups_per_page();
		$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$offset   = ( $paged - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'filtron_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, post_type, display_loc, sort_order, is_active FROM `{$table}` ORDER BY sort_order ASC, id ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		if ( 0 === $total ) {
			self::ensure_default_group();
			wp_safe_redirect( admin_url( 'admin.php?page=filtron-groups' ) );
			exit;
		}

		$num_pages = (int) ceil( $total / $per_page );

		echo '<div class="wrap filtron-wrap">';
		self::render_groups_upsell_bar();

		echo '<div class="filtron-card">';
		echo '<div class="filtron-card__header">';
		echo '<div>';
		echo '<h1 class="filtron-page-title"><span class="filtron-logo-icon" aria-hidden="true">▼</span><span>' . esc_html__( 'Filter Groups', 'filtron' ) . '</span></h1>';
		echo '<p class="filtron-page-subtitle">' . esc_html__( 'Each group powers one Filtron block on the site. Open a group to add checkboxes, ranges, or search facets.', 'filtron' ) . '</p>';
		echo '</div>';
		$add_url = wp_nonce_url( admin_url( 'admin.php?page=filtron-groups&action=add' ), 'filtron_add_group', 'filtron_admin_nonce' );
		echo '<a class="filtron-btn filtron-btn-primary" href="' . esc_url( $add_url ) . '">';
		echo '<span aria-hidden="true">+</span> ' . esc_html__( 'Add New', 'filtron' );
		echo '</a>';
		echo '</div>';
		echo '<hr class="wp-header-end filtron-card__rule" />';

		echo '<table class="filtron-table wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Name', 'filtron' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Post type', 'filtron' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Active', 'filtron' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Shortcode', 'filtron' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'filtron' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id   = (int) ( $row['id'] ?? 0 );
			$edit = esc_url(
				add_query_arg(
					array(
						'page'   => 'filtron-groups',
						'action' => 'edit',
						'group'  => $id,
					),
					admin_url( 'admin.php' )
				)
			);
			$active = ! empty( $row['is_active'] );
			$badge  = $active ? 'filtron-badge filtron-badge-active' : 'filtron-badge filtron-badge-inactive';
			$label  = $active ? __( 'Active', 'filtron' ) : __( 'Inactive', 'filtron' );

			echo '<tr>';
			echo '<td><span class="filtron-group-name">' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</span></td>';
			echo '<td><span class="filtron-post-type">' . esc_html( (string) ( $row['post_type'] ?? '' ) ) . '</span></td>';
			echo '<td><span class="' . esc_attr( $badge ) . '">' . esc_html( $label ) . '</span></td>';
			echo '<td>' . self::render_shortcode_copy_control( $id, true ) . '</td>';
			echo '<td><div class="filtron-row-actions"><a class="filtron-action-link" href="' . esc_url( $edit ) . '">' . esc_html__( 'Configure filters', 'filtron' ) . '</a></div></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $num_pages > 1 ) {
			$base = add_query_arg(
				array(
					'page'  => 'filtron-groups',
					'paged' => '%#%',
				),
				admin_url( 'admin.php' )
			);
			echo '<div class="filtron-table-nav tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $num_pages,
						'current'   => $paged,
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Single-group filter editor (filtron-admin.js).
	 *
	 * @param int $group_id Group id.
	 */
	private static function render_group_edit( int $group_id ): void {
		if ( $group_id < 1 || ! self::group_exists( $group_id ) ) {
			wp_die( esc_html__( 'Invalid group.', 'filtron' ) );
		}

		self::maybe_pro_upsell_notice();

		?>
		<div class="wrap filtron-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=filtron-groups' ) ); ?>"><?php esc_html_e( '&larr; Back to groups', 'filtron' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'Drag rows to reorder facets. Add Filter creates a new row; Ctrl+S (Cmd+S on Mac) saves the filter you are editing.', 'filtron' ); ?></p>
			<?php echo self::render_shortcode_copy_panel( $group_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div
				id="filtron-admin-root"
				class="filtron-admin"
				data-group-id="<?php echo esc_attr( (string) $group_id ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
			>
				<div class="filtron-admin__toolbar">
					<button type="button" class="button button-primary" id="filtron-add-filter">
						<?php esc_html_e( 'Add Filter', 'filtron' ); ?>
					</button>
					<button type="button" class="button" id="filtron-save-filter" hidden>
						<?php esc_html_e( 'Save filter', 'filtron' ); ?>
					</button>
				</div>

				<div class="filtron-admin__layout">
					<div class="filtron-admin__list-wrap">
						<ul id="filtron-filter-list" class="filtron-filter-list" aria-label="<?php esc_attr_e( 'Filters', 'filtron' ); ?>"></ul>
						<p class="filtron-admin__empty" id="filtron-filter-list-empty" hidden>
							<?php esc_html_e( 'No filters in this group yet. Use “Add Filter” to create a checkbox, range, or search facet, then map it to a taxonomy or meta key.', 'filtron' ); ?>
						</p>
					</div>

					<div class="filtron-admin__editor" id="filtron-filter-editor" hidden>
						<h2 class="filtron-admin__editor-title"><?php esc_html_e( 'Edit filter', 'filtron' ); ?></h2>
						<form id="filtron-filter-form" onsubmit="return false;">
							<?php wp_nonce_field( self::NONCE_ACTION, 'filtron_admin_nonce', false ); ?>
							<input type="hidden" name="item_id" id="filtron-item-id" value="" />

							<p>
								<label for="filtron-field-label"><?php esc_html_e( 'Label', 'filtron' ); ?></label>
								<input type="text" class="widefat" id="filtron-field-label" name="label" autocomplete="off" />
							</p>
							<p>
								<label for="filtron-field-type"><?php esc_html_e( 'Filter type', 'filtron' ); ?></label>
								<select id="filtron-field-type" name="filter_type">
									<option value="checkbox"><?php esc_html_e( 'Checkbox', 'filtron' ); ?></option>
									<option value="range"><?php esc_html_e( 'Range', 'filtron' ); ?></option>
									<option value="search"><?php esc_html_e( 'Search', 'filtron' ); ?></option>
									<option value="select"><?php esc_html_e( 'Select', 'filtron' ); ?></option>
									<option value="swatch"><?php esc_html_e( 'Swatch', 'filtron' ); ?></option>
								</select>
							</p>
							<p>
								<label for="filtron-field-source-type"><?php esc_html_e( 'Source type', 'filtron' ); ?></label>
								<select id="filtron-field-source-type" name="source_type">
									<option value="taxonomy"><?php esc_html_e( 'Taxonomy', 'filtron' ); ?></option>
									<option value="meta"><?php esc_html_e( 'Meta', 'filtron' ); ?></option>
								</select>
							</p>
							<p>
								<label for="filtron-field-source-key"><?php esc_html_e( 'Source key', 'filtron' ); ?></label>
								<input type="text" class="widefat" id="filtron-field-source-key" name="source_key" placeholder="pa_color / _price" autocomplete="off" />
							</p>
							<p>
								<label>
									<input type="checkbox" id="filtron-field-active" name="is_active" value="1" />
									<?php esc_html_e( 'Active on storefront', 'filtron' ); ?>
								</label>
							</p>

							<div id="filtron-type-fields" class="filtron-type-fields"></div>
						</form>
					</div>

					<div class="filtron-admin__preview-wrap">
						<h2><?php esc_html_e( 'Live preview', 'filtron' ); ?></h2>
						<div id="filtron-live-preview" class="filtron-live-preview" aria-live="polite"></div>
					</div>
				</div>
			</div>
		</div>

		<dialog id="filtron-filter-modal" class="filtron-modal">
			<form method="dialog" id="filtron-modal-form">
				<?php wp_nonce_field( self::NONCE_ACTION, 'filtron_admin_nonce', false ); ?>
				<h3><?php esc_html_e( 'New filter', 'filtron' ); ?></h3>
				<p>
					<label for="filtron-modal-label"><?php esc_html_e( 'Label', 'filtron' ); ?></label>
					<input type="text" class="widefat" id="filtron-modal-label" required />
				</p>
				<p>
					<label for="filtron-modal-type"><?php esc_html_e( 'Filter type', 'filtron' ); ?></label>
					<select id="filtron-modal-type">
						<option value="checkbox"><?php esc_html_e( 'Checkbox', 'filtron' ); ?></option>
						<option value="range"><?php esc_html_e( 'Range', 'filtron' ); ?></option>
						<option value="search"><?php esc_html_e( 'Search', 'filtron' ); ?></option>
						<option value="select"><?php esc_html_e( 'Select', 'filtron' ); ?></option>
						<option value="swatch"><?php esc_html_e( 'Swatch', 'filtron' ); ?></option>
					</select>
				</p>
				<p>
					<label for="filtron-modal-source-type"><?php esc_html_e( 'Source type', 'filtron' ); ?></label>
					<select id="filtron-modal-source-type">
						<option value="taxonomy"><?php esc_html_e( 'Taxonomy', 'filtron' ); ?></option>
						<option value="meta"><?php esc_html_e( 'Meta', 'filtron' ); ?></option>
					</select>
				</p>
				<p>
					<label for="filtron-modal-source-key"><?php esc_html_e( 'Source key', 'filtron' ); ?></label>
					<input type="text" class="widefat" id="filtron-modal-source-key" required />
				</p>
				<div class="filtron-modal__footer">
					<button type="button" class="button" id="filtron-modal-cancel"><?php esc_html_e( 'Cancel', 'filtron' ); ?></button>
					<button type="submit" class="button button-primary" id="filtron-modal-confirm"><?php esc_html_e( 'Create', 'filtron' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	/**
	 * Render a consistent page header with icon, title, subtitle.
	 *
	 * @param string $title    Page title.
	 * @param string $subtitle Short description.
	 */
	private static function render_page_header( string $title, string $subtitle = '' ): void {
		echo '<div class="filtron-page-header">';
		echo '<div>';
		echo '<h1 class="filtron-page-title"><span class="filtron-logo-icon" aria-hidden="true">▼</span><span>' . esc_html( $title ) . '</span></h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="filtron-page-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		echo '</div>';
		echo '<hr class="wp-header-end" />';
	}

	/**
	 * Settings screen.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$saved = false;
		$reset = false;
		if ( isset( $_POST['filtron_admin_nonce'] ) && ( isset( $_POST['filtron_settings_submit'] ) || isset( $_POST['filtron_theme_tokens_reset'] ) ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['filtron_admin_nonce'] ) ), self::NONCE_ACTION ) ) {
				$opts = get_option( Filtron_Activator::OPTION_SETTINGS, array() );
				if ( ! is_array( $opts ) ) {
					$opts = array();
				}
				if ( isset( $_POST['filtron_theme_tokens_reset'] ) ) {
					$opts['theme_tokens'] = self::get_default_theme_tokens();
					$reset = true;
				} else {
					$opts['delete_data_on_uninstall'] = ! empty( $_POST['delete_data_on_uninstall'] ) || ! empty( $_POST['remove_data_on_uninstall'] );
					unset( $opts['remove_data_on_uninstall'] );
					$opts['frontend_debug'] = ! empty( $_POST['filtron_frontend_debug'] );

					$raw_tokens = array();
					if ( isset( $_POST['filtron_theme_tokens'] ) && is_array( $_POST['filtron_theme_tokens'] ) ) {
						$raw_tokens = wp_unslash( $_POST['filtron_theme_tokens'] );
					}
					$opts['theme_tokens'] = self::sanitize_theme_tokens( $raw_tokens );
				}
				update_option( Filtron_Activator::OPTION_SETTINGS, $opts );
				$saved = true;
			}
		}

		$opts = get_option( Filtron_Activator::OPTION_SETTINGS, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$remove_data   = ! empty( $opts['delete_data_on_uninstall'] ) || ! empty( $opts['remove_data_on_uninstall'] );
		$frontend_debug = ! empty( $opts['frontend_debug'] );
		$theme_tokens   = self::get_settings_theme_tokens( $opts );

		echo '<div class="wrap filtron-wrap">';
		self::render_page_header(
			__( 'Settings', 'filtron' ),
			__( 'Control uninstall cleanup and the colors shoppers see in the storefront widget.', 'filtron' )
		);

		self::maybe_pro_upsell_notice();

		if ( $saved ) {
			echo '<div class="filtron-notice filtron-notice-success">';
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
			echo '<span>' . esc_html( $reset ? __( 'Theme colors reset to default.', 'filtron' ) : __( 'Settings saved.', 'filtron' ) ) . '</span>';
			echo '</div>';
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION, 'filtron_admin_nonce', false );

		echo '<section class="filtron-settings-section">';
		echo '<div class="filtron-settings-section-head">';
		echo '<h2 class="filtron-settings-section-title">' . esc_html__( 'General', 'filtron' ) . '</h2>';
		echo '<p class="filtron-settings-section-desc">' . esc_html__( 'Data lifecycle when the plugin is removed from this site.', 'filtron' ) . '</p>';
		echo '</div>';

		echo '<div class="filtron-settings-row">';
		echo '<div class="filtron-settings-row-info">';
		echo '<h3 class="filtron-settings-row-label">' . esc_html__( 'Remove data on uninstall', 'filtron' ) . '</h3>';
		echo '<p class="filtron-settings-row-desc">' . esc_html__( 'When enabled, deleting Filtron from Plugins removes its custom tables and settings. Leave off if you might reinstall and want configuration preserved.', 'filtron' ) . '</p>';
		echo '</div>';

		echo '<div class="filtron-settings-row-control">';
		echo '<label class="filtron-toggle">';
		echo '<input type="checkbox" name="delete_data_on_uninstall" value="1" ' . checked( $remove_data, true, false ) . ' />';
		echo '<span class="filtron-toggle-track"><span class="filtron-toggle-thumb"></span></span>';
		echo '<span class="screen-reader-text">' . esc_html__( 'Toggle remove data on uninstall', 'filtron' ) . '</span>';
		echo '</label>';
		echo '</div>';
		echo '</div>';

		echo '<div class="filtron-settings-row">';
		echo '<div class="filtron-settings-row-info">';
		echo '<h3 class="filtron-settings-row-label">' . esc_html__( 'Storefront performance debug', 'filtron' ) . '</h3>';
		echo '<p class="filtron-settings-row-desc">' . esc_html__( 'When enabled, administrators see SQL query count and timing on the public Filtron widget after each filter request. Never shown to guests.', 'filtron' ) . '</p>';
		echo '</div>';
		echo '<div class="filtron-settings-row-control">';
		echo '<label class="filtron-toggle">';
		echo '<input type="checkbox" name="filtron_frontend_debug" value="1" ' . checked( $frontend_debug, true, false ) . ' />';
		echo '<span class="filtron-toggle-track"><span class="filtron-toggle-thumb"></span></span>';
		echo '<span class="screen-reader-text">' . esc_html__( 'Toggle storefront debug for administrators', 'filtron' ) . '</span>';
		echo '</label>';
		echo '</div>';
		echo '</div>';
		echo '</section>';

		echo '<section class="filtron-settings-section">';
		echo '<div class="filtron-settings-section-head">';
		echo '<h2 class="filtron-settings-section-title">' . esc_html__( 'Frontend Theme', 'filtron' ) . '</h2>';
		echo '<p class="filtron-settings-section-desc">' . esc_html__( 'Tune accent, chips, range track, and price colors so the block matches your theme without editing CSS.', 'filtron' ) . '</p>';
		echo '</div>';
		echo '<div class="filtron-settings-token-grid">';
		foreach ( self::get_theme_token_labels() as $token_key => $token_label ) {
			$value = isset( $theme_tokens[ $token_key ] ) ? (string) $theme_tokens[ $token_key ] : '';
			echo '<div class="filtron-settings-token-item">';
			echo '<label class="filtron-settings-token-label" for="filtron-theme-token-' . esc_attr( $token_key ) . '">' . esc_html( $token_label ) . '</label>';
			echo '<div class="filtron-settings-token-control">';
			echo '<input id="filtron-theme-token-' . esc_attr( $token_key ) . '" class="filtron-settings-token-input" type="color" name="filtron_theme_tokens[' . esc_attr( $token_key ) . ']" value="' . esc_attr( $value ) . '" />';
			echo '<code class="filtron-settings-token-value">' . esc_html( strtoupper( $value ) ) . '</code>';
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
		echo '</section>';

		echo '<div class="filtron-form-actions">';
		echo '<button type="submit" name="filtron_settings_submit" class="filtron-btn filtron-btn-primary">' . esc_html__( 'Save Changes', 'filtron' ) . '</button>';
		echo '<button type="submit" name="filtron_theme_tokens_reset" class="filtron-btn filtron-btn-secondary" onclick="return window.confirm(\'' . esc_attr__( 'Reset all theme colors to defaults?', 'filtron' ) . '\');">' . esc_html__( 'Reset Theme Colors', 'filtron' ) . '</button>';
		if ( $saved ) {
			echo '<span class="filtron-settings-save-status">' . esc_html( $reset ? __( 'Reset', 'filtron' ) : __( 'Saved', 'filtron' ) ) . '</span>';
		}
		echo '</div>';

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Default theme token values for frontend widget.
	 *
	 * @return array<string, string>
	 */
	private static function get_default_theme_tokens(): array {
		return array(
			'accent'        => '#2563eb',
			'accent_2'      => '#3b82f6',
			'accent_soft'   => '#eff6ff',
			'accent_border' => '#bfdbfe',
			'track'         => '#e2e8f0',
			'text_accent'   => '#1d4ed8',
			'price'         => '#1e40af',
			'rating'        => '#b45309',
			'time'          => '#0f766e',
		);
	}

	/**
	 * Theme token labels for settings UI.
	 *
	 * @return array<string, string>
	 */
	private static function get_theme_token_labels(): array {
		return array(
			'accent'        => __( 'Accent', 'filtron' ),
			'accent_2'      => __( 'Accent gradient start', 'filtron' ),
			'accent_soft'   => __( 'Accent soft background', 'filtron' ),
			'accent_border' => __( 'Accent border', 'filtron' ),
			'track'         => __( 'Range track', 'filtron' ),
			'text_accent'   => __( 'Accent text', 'filtron' ),
			'price'         => __( 'Price text', 'filtron' ),
			'rating'        => __( 'Rating text', 'filtron' ),
			'time'          => __( 'Load time text', 'filtron' ),
		);
	}

	/**
	 * Parse and sanitize theme tokens for saving.
	 *
	 * @param mixed $raw_tokens Raw submitted tokens.
	 * @return array<string, string>
	 */
	private static function sanitize_theme_tokens( $raw_tokens ): array {
		$defaults = self::get_default_theme_tokens();
		if ( ! is_array( $raw_tokens ) ) {
			return $defaults;
		}

		$out = array();
		foreach ( $defaults as $key => $default ) {
			$value = isset( $raw_tokens[ $key ] ) ? (string) $raw_tokens[ $key ] : '';
			$hex = sanitize_hex_color( $value );
			$out[ $key ] = is_string( $hex ) && '' !== $hex ? strtolower( $hex ) : $default;
		}

		return $out;
	}

	/**
	 * Resolve settings theme tokens from options.
	 *
	 * @param array<string, mixed> $opts Settings option.
	 * @return array<string, string>
	 */
	private static function get_settings_theme_tokens( array $opts ): array {
		$raw_tokens = isset( $opts['theme_tokens'] ) ? $opts['theme_tokens'] : array();
		return self::sanitize_theme_tokens( $raw_tokens );
	}

	/**
	 * Tools: rebuild index, clear cache.
	 */
	public static function render_tools_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = isset( $_GET['filtron_tools_msg'] )
			? sanitize_key( wp_unslash( (string) $_GET['filtron_tools_msg'] ) )
			: '';

		echo '<div class="wrap filtron-wrap">';
		self::render_page_header(
			__( 'Tools', 'filtron' ),
			__( 'Rebuild the index after bulk imports, or clear cached filter responses when results look stale.', 'filtron' )
		);

		self::maybe_pro_upsell_notice();

		if ( 'rebuilt' === $notice ) {
			echo '<div class="filtron-notice filtron-notice-success">';
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
			echo '<span>' . esc_html__( 'Index rebuild finished.', 'filtron' ) . '</span>';
			echo '</div>';
		} elseif ( 'flushed' === $notice ) {
			echo '<div class="filtron-notice filtron-notice-success">';
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
			echo '<span>' . esc_html__( 'Cache cleared.', 'filtron' ) . '</span>';
			echo '</div>';
		}

		echo '<div class="filtron-tools-grid">';

		echo '<div class="filtron-tool-card">';
		echo '<div class="filtron-tool-icon blue"><span class="dashicons dashicons-update" aria-hidden="true"></span></div>';
		echo '<h2 class="filtron-tool-title">' . esc_html__( 'Rebuild index', 'filtron' ) . '</h2>';
		echo '<p class="filtron-tool-desc">' . esc_html__( 'Re-scan published posts and rebuild the Filtron index. Run after imports, migrations, or when counts look wrong.', 'filtron' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION, 'filtron_admin_nonce', false );
		echo '<input type="hidden" name="filtron_tools_action" value="rebuild_index" />';
		echo '<button type="submit" class="filtron-btn filtron-btn-primary">' . esc_html__( 'Rebuild index', 'filtron' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<div class="filtron-tool-card">';
		echo '<div class="filtron-tool-icon orange"><span class="dashicons dashicons-trash" aria-hidden="true"></span></div>';
		echo '<h2 class="filtron-tool-title">' . esc_html__( 'Clear cache', 'filtron' ) . '</h2>';
		echo '<p class="filtron-tool-desc">' . esc_html__( 'Drop cached AJAX responses so the next filter request is fresh. Use after bulk price or stock updates.', 'filtron' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION, 'filtron_admin_nonce', false );
		echo '<input type="hidden" name="filtron_tools_action" value="clear_cache" />';
		echo '<button type="submit" class="filtron-btn filtron-btn-secondary">' . esc_html__( 'Clear cache', 'filtron' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Upgrade landing (free only).
	 */
	public static function render_upgrade_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap filtron-wrap">';

		echo '<div class="filtron-upgrade-hero">';
		echo '<div class="filtron-upgrade-badge">⭐ ' . esc_html__( 'Filtron Pro', 'filtron' ) . '</div>';
		echo '<h1 class="filtron-upgrade-title">' . esc_html__( 'Unlock the full power of Filtron', 'filtron' ) . '</h1>';
		echo '<p class="filtron-upgrade-subtitle">' . esc_html__( 'Analytics, swatch filters, WooCommerce deep integration, and more.', 'filtron' ) . '</p>';
		echo '<a class="filtron-btn filtron-btn-primary" href="https://filtron.pro/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Filtron Pro →', 'filtron' ) . '</a>';
		echo '</div>';

		echo '<div class="filtron-pricing-grid">';

		echo '<div class="filtron-pricing-card">';
		echo '<div class="filtron-pricing-name">' . esc_html__( 'Free', 'filtron' ) . '</div>';
		echo '<div class="filtron-pricing-price">$0</div>';
		echo '<div class="filtron-pricing-period">' . esc_html__( 'Forever', 'filtron' ) . '</div>';
		echo '<ul class="filtron-pricing-features">';
		echo '<li>' . esc_html__( 'Checkbox facets', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Range slider', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Search input', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'AJAX filtering', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Gutenberg blocks', 'filtron' ) . '</li>';
		echo '</ul>';
		echo '<span class="filtron-pricing-period">' . esc_html__( 'Current plan', 'filtron' ) . '</span>';
		echo '</div>';

		echo '<div class="filtron-pricing-card featured">';
		echo '<div class="filtron-pricing-featured-badge">' . esc_html__( 'Most popular', 'filtron' ) . '</div>';
		echo '<div class="filtron-pricing-name">' . esc_html__( 'Pro', 'filtron' ) . '</div>';
		echo '<div class="filtron-pricing-price"><sup>$</sup>79</div>';
		echo '<div class="filtron-pricing-period">' . esc_html__( 'per year / 1 site', 'filtron' ) . '</div>';
		echo '<ul class="filtron-pricing-features">';
		echo '<li>' . esc_html__( 'Everything in Free', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Color swatch filter', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Analytics dashboard', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'REST API (headless)', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Dark mode and RTL', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'WooCommerce deep integration', 'filtron' ) . '</li>';
		echo '</ul>';
		echo '<a href="https://filtron.pro/" target="_blank" rel="noopener noreferrer" class="filtron-btn filtron-btn-primary filtron-btn-block">' . esc_html__( 'Get Pro', 'filtron' ) . '</a>';
		echo '</div>';

		echo '<div class="filtron-pricing-card">';
		echo '<div class="filtron-pricing-name">' . esc_html__( 'Agency', 'filtron' ) . '</div>';
		echo '<div class="filtron-pricing-price"><sup>$</sup>149</div>';
		echo '<div class="filtron-pricing-period">' . esc_html__( 'per year / unlimited sites', 'filtron' ) . '</div>';
		echo '<ul class="filtron-pricing-features">';
		echo '<li>' . esc_html__( 'Everything in Pro', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Unlimited sites', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'White label', 'filtron' ) . '</li>';
		echo '<li>' . esc_html__( 'Priority support', 'filtron' ) . '</li>';
		echo '</ul>';
		echo '<a href="https://filtron.pro/" target="_blank" rel="noopener noreferrer" class="filtron-btn filtron-btn-secondary filtron-btn-block">' . esc_html__( 'Get Agency', 'filtron' ) . '</a>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Analytics (Pro only — menu registered when Pro active).
	 */
	public static function render_analytics_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! defined( 'FILTRON_PRO_VERSION' ) ) {
			self::render_pro_feature_upsell();
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		echo '<p>' . esc_html__( 'Analytics dashboard (Pro).', 'filtron' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Fallback if analytics is opened without Pro.
	 */
	private static function render_pro_feature_upsell(): void {
		$up = admin_url( 'admin.php?page=filtron-upgrade' );
		echo '<div class="wrap filtron-wrap">';
		echo '<h1 class="filtron-page-title"><span class="filtron-logo-icon" aria-hidden="true">▼</span><span>' . esc_html( get_admin_page_title() ) . '</span></h1>';
		echo '<div class="filtron-settings-section filtron-pro-upsell-fallback">';
		echo '<div class="filtron-empty-state filtron-empty-state--compact">';
		echo '<div class="filtron-empty-icon"><span class="dashicons dashicons-lock" aria-hidden="true"></span></div>';
		echo '<p class="filtron-empty-title">' . esc_html__( 'This screen is included with Filtron Pro', 'filtron' ) . '</p>';
		echo '<p class="filtron-empty-desc">' . esc_html__( 'Activate Pro to view analytics, swatch filters, and advanced storefront insights.', 'filtron' ) . '</p>';
		echo '<a class="filtron-btn filtron-btn-primary" href="' . esc_url( $up ) . '">' . esc_html__( 'View upgrade options', 'filtron' ) . '</a>';
		echo '</div></div></div>';
	}

	/**
	 * Handle Tools POST actions.
	 */
	public static function handle_tools_post(): void {
		if ( ! isset( $_POST['filtron_tools_action'], $_POST['filtron_admin_nonce'] ) ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) || 'filtron-tools' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['filtron_admin_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['filtron_tools_action'] ) );
		$msg    = '';

		if ( 'rebuild_index' === $action ) {
			if ( class_exists( 'Filtron_Indexer' ) ) {
				Filtron_Indexer::rebuild_all();
			}
			Filtron_Activator::set_index_needs_rebuild( false );
			$msg = 'rebuilt';
		} elseif ( 'clear_cache' === $action ) {
			if ( class_exists( 'Filtron_Cache' ) ) {
				Filtron_Cache::flush_all();
			}
			$msg = 'flushed';
		}

		if ( '' !== $msg ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'               => 'filtron-tools',
						'filtron_tools_msg' => $msg,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * @param int $group_id Group id.
	 */
	private static function group_exists( int $group_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'filtron_groups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE id = %d", $group_id ) );
		return $n > 0;
	}

	/**
	 * Shortcode string for a group.
	 *
	 * @param int $group_id Group id.
	 */
	private static function get_group_shortcode( int $group_id ): string {
		return '[filtron_group id="' . max( 0, $group_id ) . '" layout="grid"]';
	}

	/**
	 * Compact copyable shortcode control.
	 *
	 * @param int  $group_id Group id.
	 * @param bool $compact  Compact table variant.
	 */
	private static function render_shortcode_copy_control( int $group_id, bool $compact = false ): string {
		$shortcode = self::get_group_shortcode( $group_id );
		$classes   = 'filtron-shortcode-copy';
		if ( $compact ) {
			$classes .= ' filtron-shortcode-copy--compact';
		}

		$html  = '<div class="' . esc_attr( $classes ) . '">';
		$html .= '<input type="text" class="filtron-shortcode-copy__input" value="' . esc_attr( $shortcode ) . '" readonly aria-label="' . esc_attr__( 'Filtron shortcode', 'filtron' ) . '" />';
		$html .= '<button type="button" class="filtron-shortcode-copy__button" data-filtron-copy-shortcode="' . esc_attr( $shortcode ) . '" data-filtron-copy-label="' . esc_attr__( 'Copy', 'filtron' ) . '" data-filtron-copied-label="' . esc_attr__( 'Copied', 'filtron' ) . '">';
		$html .= '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>';
		$html .= '<span class="filtron-shortcode-copy__button-text">' . esc_html__( 'Copy', 'filtron' ) . '</span>';
		$html .= '</button>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Full shortcode helper shown on the group editor.
	 *
	 * @param int $group_id Group id.
	 */
	private static function render_shortcode_copy_panel( int $group_id ): string {
		$html  = '<div class="filtron-shortcode-panel">';
		$html .= '<div class="filtron-shortcode-panel__body">';
		$html .= '<h2 class="filtron-shortcode-panel__title">' . esc_html__( 'Shortcode', 'filtron' ) . '</h2>';
		$html .= '<p class="filtron-shortcode-panel__desc">' . esc_html__( 'Paste this shortcode into any page, post, or widget area to show this filter group.', 'filtron' ) . '</p>';
		$html .= '</div>';
		$html .= self::render_shortcode_copy_control( $group_id, false );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Enqueue scripts only on filter editor screen.
	 *
	 * @param string $hook_suffix Current screen id.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$ver = defined( 'FILTRON_VERSION' ) ? FILTRON_VERSION : '1.0.0';

		$filtron_hooks = array(
			'toplevel_page_filtron-groups',
			'filtron_page_filtron-settings',
			'filtron_page_filtron-tools',
			'filtron_page_filtron-upgrade',
		);
		if ( defined( 'FILTRON_PRO_VERSION' ) ) {
			$filtron_hooks[] = 'filtron_page_filtron-analytics';
		}

		$is_editor = 'toplevel_page_filtron-groups' === $hook_suffix
			&& isset( $_GET['action'], $_GET['group'] )
			&& 'edit' === $_GET['action'];

		if ( in_array( $hook_suffix, $filtron_hooks, true ) ) {
			wp_enqueue_style(
				'filtron-admin-global',
				FILTRON_PLUGIN_URL . 'assets/css/filtron-admin-global.css',
				array(),
				$ver
			);
			wp_enqueue_script(
				'filtron-admin-global',
				FILTRON_PLUGIN_URL . 'assets/js/filtron-admin-global.js',
				array(),
				$ver,
				true
			);
			if ( $is_editor ) {
				wp_enqueue_style(
					'filtron-admin',
					FILTRON_PLUGIN_URL . 'assets/css/filtron-admin.css',
					array( 'filtron-admin-global' ),
					$ver
				);
			}
		}

		if ( ! $is_editor ) {
			return;
		}

		wp_enqueue_script(
			'sortablejs',
			'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js',
			array(),
			'1.15.2',
			true
		);

		wp_enqueue_script(
			'filtron-admin',
			FILTRON_PLUGIN_URL . 'src/js/filtron-admin.js',
			array( 'sortablejs' ),
			$ver,
			true
		);

		$group_id = isset( $_GET['group'] ) ? absint( $_GET['group'] ) : 0;
		$items    = $group_id > 0 ? self::get_items_for_group( $group_id ) : array();

		wp_localize_script(
			'filtron-admin',
			'filtronAdmin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'groupId'  => $group_id,
				'isPro'    => defined( 'FILTRON_PRO_VERSION' ),
				'items'    => $items,
				'i18n'     => array(
					'saved'       => __( 'Filter saved.', 'filtron' ),
					'deleted'     => __( 'Filter deleted.', 'filtron' ),
					'reordered'   => __( 'Order updated.', 'filtron' ),
					'error'       => __( 'Something went wrong.', 'filtron' ),
					'activate'    => __( 'Activate', 'filtron' ),
					'deactivate'  => __( 'Deactivate', 'filtron' ),
					'delete'      => __( 'Delete', 'filtron' ),
					'confirmDelete' => __( 'Confirm delete', 'filtron' ),
					'proBadge'    => __( 'Pro feature', 'filtron' ),
					'proDisabled' => __( 'Activate Filtron Pro to use swatch filters.', 'filtron' ),
				),
			)
		);
	}

	/**
	 * AJAX: save or create filter row.
	 */
	public static function ajax_save_filter(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'filtron' ) ), 403 );
			return;
		}

		$raw = isset( $_POST['filter'] ) ? wp_unslash( $_POST['filter'] ) : '';
		if ( is_string( $raw ) ) {
			$filter = json_decode( $raw, true );
		} else {
			$filter = is_array( $raw ) ? $raw : array();
		}
		if ( ! is_array( $filter ) ) {
			$filter = array();
		}

		$group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		if ( $group_id < 1 || ! self::group_exists( $group_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid group.', 'filtron' ) ), 400 );
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_items';

		$id           = isset( $filter['id'] ) ? absint( $filter['id'] ) : 0;
		$filter_type  = isset( $filter['filter_type'] ) ? sanitize_key( (string) $filter['filter_type'] ) : '';
		$source_type  = isset( $filter['source_type'] ) ? sanitize_key( (string) $filter['source_type'] ) : 'taxonomy';
		$source_key   = isset( $filter['source_key'] ) ? sanitize_text_field( (string) $filter['source_key'] ) : '';
		$label        = isset( $filter['label'] ) ? Filtron_Filter_Base::normalize_display_text( sanitize_text_field( (string) $filter['label'] ) ) : '';
		$is_active    = isset( $filter['is_active'] ) ? (int) (bool) $filter['is_active'] : 1;
		$config       = isset( $filter['config'] ) && is_array( $filter['config'] ) ? self::sanitize_config( $filter['config'] ) : array();

		if ( '' === $filter_type || '' === $source_key || '' === $label ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'filtron' ) ), 400 );
			return;
		}

		if ( ! Filtron_Security::validate_filter_type( $filter_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid filter type.', 'filtron' ) ), 400 );
			return;
		}

		if ( 'swatch' === $filter_type && ! defined( 'FILTRON_PRO_VERSION' ) ) {
			wp_send_json_error( array( 'message' => __( 'Swatch is a Pro feature.', 'filtron' ) ), 400 );
			return;
		}

		if ( ! Filtron_Security::validate_source_type( $source_type ) ) {
			$source_type = 'taxonomy';
		}

		$row = array(
			'group_id'    => $group_id,
			'filter_type' => $filter_type,
			'source_type' => $source_type,
			'source_key'  => $source_key,
			'label'       => $label,
			'config_json' => wp_json_encode( $config ),
			'is_active'   => $is_active,
		);

		if ( $id > 0 ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE id = %d AND group_id = %d",
					$id,
					$group_id
				)
			);
			if ( $exists < 1 ) {
				wp_send_json_error( array( 'message' => __( 'Filter not found.', 'filtron' ) ), 404 );
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->update(
				$table,
				$row,
				array( 'id' => $id, 'group_id' => $group_id ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d', '%d' )
			);
			if ( false === $ok ) {
				wp_send_json_error( array( 'message' => __( 'Could not save.', 'filtron' ) ), 500 );
				return;
			}
			$out_id = $id;
		} else {
			$max_so = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT MAX(sort_order) FROM `{$table}` WHERE group_id = %d",
					$group_id
				)
			);
			$row['sort_order'] = $max_so + 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert(
				$table,
				$row,
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
			);
			if ( ! $ok ) {
				wp_send_json_error( array( 'message' => __( 'Could not create.', 'filtron' ) ), 500 );
				return;
			}
			$out_id = (int) $wpdb->insert_id;
		}

		$saved = self::get_item_by_id( $out_id, $group_id );
		wp_send_json_success( array( 'filter' => $saved ) );
	}

	/**
	 * AJAX: update sort_order for items in group.
	 */
	public static function ajax_reorder_filters(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'filtron' ) ), 403 );
			return;
		}

		$group_id  = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		$order_raw = isset( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : '[]';
		$order     = is_string( $order_raw ) ? json_decode( $order_raw, true ) : array();
		if ( ! is_array( $order ) || $group_id < 1 || ! self::group_exists( $group_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'filtron' ) ), 400 );
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_items';

		foreach ( array_values( $order ) as $i => $id_raw ) {
			$item_id = absint( $id_raw );
			if ( $item_id < 1 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table,
				array( 'sort_order' => $i ),
				array( 'id' => $item_id, 'group_id' => $group_id ),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}

		wp_send_json_success( array( 'ok' => true ) );
	}

	/**
	 * AJAX: delete one filter row from a group.
	 */
	public static function ajax_delete_filter(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'filtron' ) ), 403 );
			return;
		}

		$group_id = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		$item_id  = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		if ( $group_id < 1 || $item_id < 1 || ! self::group_exists( $group_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid filter.', 'filtron' ) ), 400 );
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete(
			$table,
			array(
				'id'       => $item_id,
				'group_id' => $group_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete.', 'filtron' ) ), 500 );
			return;
		}
		if ( $deleted < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Filter not found.', 'filtron' ) ), 404 );
			return;
		}

		wp_send_json_success( array( 'deleted' => true ) );
	}

	/**
	 * @param array<string, mixed> $config Raw config.
	 * @return array<string, mixed>
	 */
	private static function sanitize_config( array $config ): array {
		$out = array();
		if ( isset( $config['logic'] ) ) {
			$l            = strtoupper( sanitize_text_field( (string) $config['logic'] ) );
			$out['logic'] = 'AND' === $l ? 'AND' : 'OR';
		}
		if ( isset( $config['show_count'] ) ) {
			$out['show_count'] = (bool) $config['show_count'];
		}
		if ( isset( $config['placeholder'] ) ) {
			$out['placeholder'] = Filtron_Filter_Base::normalize_display_text( sanitize_text_field( (string) $config['placeholder'] ) );
		}
		if ( isset( $config['prefix'] ) ) {
			$out['prefix'] = Filtron_Filter_Base::normalize_display_text( sanitize_text_field( (string) $config['prefix'] ) );
		}
		if ( isset( $config['suffix'] ) ) {
			$out['suffix'] = Filtron_Filter_Base::normalize_display_text( sanitize_text_field( (string) $config['suffix'] ) );
		}
		if ( isset( $config['step'] ) ) {
			$out['step'] = is_numeric( $config['step'] ) ? (float) $config['step'] : 1;
		}
		return $out;
	}

	/**
	 * @param int $id Item id.
	 * @param int $group_id Group id.
	 * @return array<string, mixed>|null
	 */
	private static function get_item_by_id( int $id, int $group_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'filtron_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, group_id, filter_type, source_type, source_key, label, sort_order, config_json, is_active FROM `{$table}` WHERE id = %d AND group_id = %d",
				$id,
				$group_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return self::normalize_item_row( $row );
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	private static function normalize_item_row( array $row ): array {
		$config = array();
		if ( ! empty( $row['config_json'] ) ) {
			$decoded = json_decode( (string) $row['config_json'], true );
			if ( is_array( $decoded ) ) {
				$config = $decoded;
			}
		}
		if ( isset( $config['placeholder'] ) ) {
			$config['placeholder'] = Filtron_Filter_Base::normalize_display_text( (string) $config['placeholder'] );
		}
		if ( isset( $config['prefix'] ) ) {
			$config['prefix'] = Filtron_Filter_Base::normalize_display_text( (string) $config['prefix'] );
		}
		if ( isset( $config['suffix'] ) ) {
			$config['suffix'] = Filtron_Filter_Base::normalize_display_text( (string) $config['suffix'] );
		}
		$row['config'] = $config;
		unset( $row['config_json'] );
		$row['id']         = (int) $row['id'];
		$row['group_id']   = (int) $row['group_id'];
		$row['sort_order'] = (int) $row['sort_order'];
		$row['is_active']  = (int) $row['is_active'];
		$row['label']      = Filtron_Filter_Base::normalize_display_text( (string) $row['label'] );
		return $row;
	}

	/**
	 * @param int $group_id Group id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_items_for_group( int $group_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'filtron_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, group_id, filter_type, source_type, source_key, label, sort_order, config_json, is_active FROM `{$table}` WHERE group_id = %d ORDER BY sort_order ASC, id ASC",
				$group_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::normalize_item_row( $row );
			}
		}
		return $out;
	}

	/**
	 * First group or insert default (legacy helper).
	 */
	public static function ensure_default_group(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'filtron_groups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( "SELECT id FROM `{$table}` ORDER BY id ASC LIMIT 1" );
		if ( $id > 0 ) {
			return $id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'name'        => __( 'Default group', 'filtron' ),
				'post_type'   => 'product',
				'display_loc' => 'sidebar',
				'sort_order'  => 0,
				'is_active'   => 1,
			),
			array( '%s', '%s', '%s', '%d', '%d' )
		);
		return (int) $wpdb->insert_id;
	}
}
