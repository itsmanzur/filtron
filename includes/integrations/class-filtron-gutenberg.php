<?php
/**
 * Gutenberg blocks (free): container + filter blocks.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Gutenberg
 */
class Filtron_Gutenberg {

	/**
	 * Editor script handle (single bundle registers all blocks).
	 */
	private const EDITOR_SCRIPT = 'filtron-blocks-editor';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ), 9 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_styles' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		add_filter( 'block_categories_all', array( self::class, 'register_block_category' ), 10, 2 );
	}

	/**
	 * Scoped styles for Filtron block previews in the block editor.
	 */
	public static function enqueue_editor_styles(): void {
		$ver = defined( 'FILTRON_VERSION' ) ? FILTRON_VERSION : '1.0.0';
		wp_enqueue_style(
			'filtron-blocks-editor',
			FILTRON_PLUGIN_URL . 'assets/css/filtron-blocks-editor.css',
			array(),
			$ver
		);
	}

	/**
	 * Block inserter category.
	 *
	 * @param array<int, array<string, mixed>> $categories       Categories.
	 * @param mixed                             $editor_context Editor context (unused).
	 * @return array<int, array<string, mixed>>
	 */
	public static function register_block_category( array $categories, $editor_context ): array {
		unset( $editor_context );
		return array_merge(
			array(
				array(
					'slug'  => 'filtron',
					'title' => __( 'Filtron', 'filtron' ),
					'icon'  => 'filter',
				),
			),
			$categories
		);
	}

	/**
	 * Register editor script + block types (init).
	 */
	public static function register_blocks(): void {
		self::register_editor_script();
		self::register_block_types();
	}

	/**
	 * Register shared editor script (must run before register_block_type_from_metadata).
	 */
	private static function register_editor_script(): void {
		$ver = defined( 'FILTRON_VERSION' ) ? FILTRON_VERSION : '1.0.0';

		wp_register_script(
			self::EDITOR_SCRIPT,
			FILTRON_PLUGIN_URL . 'src/js/blocks/index.js',
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-data',
				'wp-i18n',
				'wp-api-fetch',
			),
			$ver,
			true
		);

		wp_set_script_translations( self::EDITOR_SCRIPT, 'filtron' );

		wp_localize_script(
			self::EDITOR_SCRIPT,
			'filtronBlocksData',
			array(
				'groups' => self::get_groups_for_editor(),
			)
		);
	}

	/**
	 * Active filter groups for container block select.
	 *
	 * @return array<int, array{id: int, name: string, post_type: string}>
	 */
	private static function get_groups_for_editor(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'filtron_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, name, post_type FROM `{$table}` WHERE is_active = 1 ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'name'       => isset( $row['name'] ) ? (string) $row['name'] : '',
				'post_type'  => isset( $row['post_type'] ) ? (string) $row['post_type'] : 'post',
			);
		}

		return $out;
	}

	/**
	 * Register block types from block.json + PHP render callbacks.
	 */
	private static function register_block_types(): void {
		$dir = trailingslashit( FILTRON_PLUGIN_DIR ) . 'src/js/blocks/';

		register_block_type_from_metadata(
			$dir . 'container',
			array(
				'render_callback' => array( self::class, 'render_container' ),
			)
		);

		register_block_type_from_metadata(
			$dir . 'checkbox',
			array(
				'render_callback' => array( self::class, 'render_checkbox' ),
			)
		);

		register_block_type_from_metadata(
			$dir . 'range',
			array(
				'render_callback' => array( self::class, 'render_range' ),
			)
		);

		register_block_type_from_metadata(
			$dir . 'search',
			array(
				'render_callback' => array( self::class, 'render_search' ),
			)
		);
	}

	/**
	 * REST: editor-only routes (logged-in users with edit_posts).
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			Filtron_Rest::NAMESPACE,
			'/editor-source-keys',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_editor_source_keys' ),
				'permission_callback' => array( self::class, 'rest_editor_permission' ),
				'args'                => array(
					'post_type'   => array(
						'description' => __( 'Post type slug.', 'filtron' ),
						'type'        => 'string',
						'required'    => true,
					),
					'source_type' => array(
						'description' => __( 'taxonomy or meta.', 'filtron' ),
						'type'        => 'string',
						'required'    => true,
						'enum'        => array( 'taxonomy', 'meta' ),
					),
				),
			)
		);

		register_rest_route(
			Filtron_Rest::NAMESPACE,
			'/editor-facet-preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_editor_facet_preview' ),
				'permission_callback' => array( self::class, 'rest_editor_permission' ),
				'args'                => array(
					'post_type'   => array(
						'description' => __( 'Post type slug.', 'filtron' ),
						'type'        => 'string',
						'required'    => true,
					),
					'source_type' => array(
						'description' => __( 'taxonomy or meta.', 'filtron' ),
						'type'        => 'string',
						'required'    => true,
						'enum'        => array( 'taxonomy', 'meta' ),
					),
					'source_key'  => array(
						'description' => __( 'Taxonomy slug or meta key.', 'filtron' ),
						'type'        => 'string',
						'required'    => true,
					),
					'limit'       => array(
						'description' => __( 'Max options to return (1–50).', 'filtron' ),
						'type'        => 'integer',
						'default'     => 25,
					),
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function rest_editor_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'filtron_rest_forbidden',
				__( 'You must be logged in.', 'filtron' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'filtron_rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'filtron' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /filtron/v1/editor-source-keys
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_editor_source_keys( WP_REST_Request $request ) {
		$post_type   = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$source_type = sanitize_key( (string) $request->get_param( 'source_type' ) );

		if ( '' === $post_type ) {
			$post_type = 'post';
		}

		if ( 'taxonomy' === $source_type ) {
			$taxes = get_object_taxonomies( $post_type, 'names' );
			if ( ! is_array( $taxes ) ) {
				$taxes = array();
			}
			sort( $taxes );
			return new WP_REST_Response(
				array(
					'keys' => array_values( array_map( 'strval', $taxes ) ),
				),
				200
			);
		}

		if ( 'meta' === $source_type ) {
			$keys = self::get_meta_keys_for_post_type( $post_type );
			return new WP_REST_Response(
				array(
					'keys' => $keys,
				),
				200
			);
		}

		return new WP_Error(
			'filtron_rest_bad_source',
			__( 'Invalid source type.', 'filtron' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * GET /filtron/v1/editor-facet-preview
	 *
	 * Distinct filter values from the index for block editor previews (labels for taxonomies).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_editor_facet_preview( WP_REST_Request $request ) {
		$post_type   = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$source_type = sanitize_key( (string) $request->get_param( 'source_type' ) );
		$source_key  = substr( sanitize_text_field( (string) $request->get_param( 'source_key' ) ), 0, 100 );
		$limit       = (int) $request->get_param( 'limit' );

		if ( '' === $post_type ) {
			$post_type = 'post';
		}
		if ( '' === $source_key ) {
			return new WP_Error(
				'filtron_rest_missing_source_key',
				__( 'Missing source key.', 'filtron' ),
				array( 'status' => 400 )
			);
		}
		if ( $limit < 1 || $limit > 50 ) {
			$limit = 25;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_index';

		if ( 'taxonomy' === $source_type ) {
			$taxes = get_object_taxonomies( $post_type, 'names' );
			if ( ! is_array( $taxes ) || ! in_array( $source_key, $taxes, true ) ) {
				return new WP_Error(
					'filtron_rest_bad_taxonomy',
					__( 'That taxonomy is not available for this post type.', 'filtron' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( 'meta' === $source_type ) {
			$allowed = self::get_meta_keys_for_post_type( $post_type );
			if ( ! in_array( $source_key, $allowed, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM `{$table}` WHERE filter_key = %s AND post_type = %s LIMIT 1",
						$source_key,
						$post_type
					)
				);
				if ( ! $exists ) {
					return new WP_Error(
						'filtron_rest_bad_meta_key',
						__( 'That meta key is not available for this post type.', 'filtron' ),
						array( 'status' => 400 )
					);
				}
			}
		} else {
			return new WP_Error(
				'filtron_rest_bad_source',
				__( 'Invalid source type.', 'filtron' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT filter_value, COUNT(*) AS item_count FROM `{$table}` WHERE filter_key = %s AND post_type = %s GROUP BY filter_value ORDER BY item_count DESC, filter_value ASC LIMIT %d",
				$source_key,
				$post_type,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$items = array();

		// Taxonomy with no indexed rows: still show real terms so the editor preview is useful.
		if ( 'taxonomy' === $source_type && array() === $rows ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $source_key,
					'hide_empty' => false,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => $limit,
				)
			);
			if ( is_wp_error( $terms ) ) {
				$terms = array();
			}
			foreach ( $terms as $term ) {
				if ( ! ( $term instanceof WP_Term ) ) {
					continue;
				}
				$items[] = array(
					'value' => (string) $term->slug,
					'label' => (string) $term->name,
					'count' => (int) $term->count,
				);
			}
			return new WP_REST_Response(
				array(
					'items' => $items,
				),
				200
			);
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug  = isset( $row['filter_value'] ) ? (string) $row['filter_value'] : '';
			$count = isset( $row['item_count'] ) ? (int) $row['item_count'] : 0;
			if ( '' === $slug ) {
				continue;
			}
			$label = $slug;
			if ( 'taxonomy' === $source_type ) {
				$label = self::resolve_taxonomy_facet_label( $source_key, $slug );
			}
			$items[] = array(
				'value' => $slug,
				'label' => $label,
				'count' => $count,
			);
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
			),
			200
		);
	}

	/**
	 * Editor-only: turn a stored slug into readable text when no WP_Term is found.
	 *
	 * @param string $slug Raw filter_value (often a term slug).
	 */
	private static function humanize_facet_slug( string $slug ): string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		$spaced = str_replace( array( '-', '_' ), ' ', $slug );
		if ( function_exists( 'mb_convert_case' ) ) {
			return (string) mb_convert_case( $spaced, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $spaced );
	}

	/**
	 * Resolve taxonomy facet label for editor preview (slug, numeric term ID, then humanize).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $stored   Value from filtron_index.filter_value.
	 */
	private static function resolve_taxonomy_facet_label( string $taxonomy, string $stored ): string {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return '';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => array( $stored ),
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( ! is_wp_error( $terms ) && is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof WP_Term ) {
			return (string) $terms[0]->name;
		}

		if ( ctype_digit( $stored ) ) {
			$t = get_term( (int) $stored, $taxonomy );
			if ( $t instanceof WP_Term && ! is_wp_error( $t ) ) {
				return (string) $t->name;
			}
		}

		return self::humanize_facet_slug( $stored );
	}

	/**
	 * Distinct public meta keys used by this post type (capped).
	 *
	 * @param string $post_type Post type.
	 * @return array<int, string>
	 */
	private static function get_meta_keys_for_post_type( string $post_type ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND LEFT( pm.meta_key, 1 ) <> %s
			ORDER BY pm.meta_key ASC
			LIMIT 300",
			$post_type,
			'_'
		);

		$col = $wpdb->get_col( $sql );
		if ( ! is_array( $col ) ) {
			return array();
		}

		return array_values( array_map( 'strval', $col ) );
	}

	/**
	 * @param array<string, mixed> $attributes Block attrs.
	 * @param string               $content   Inner blocks HTML.
	 * @param WP_Block|null        $block     Block instance.
	 */
	public static function render_container( array $attributes, string $content, ?WP_Block $block = null ): string {
		unset( $block );
		$gid = isset( $attributes['groupId'] ) ? (int) $attributes['groupId'] : 0;

		$align = ! empty( $attributes['align'] ) ? ' align' . sanitize_key( (string) $attributes['align'] ) : '';
		$post_type = self::get_post_type_for_group( $gid );
		$per_page = isset( $attributes['perPage'] ) ? max( 1, min( 100, (int) $attributes['perPage'] ) ) : 6;
		$orderby = isset( $attributes['orderby'] ) ? sanitize_key( (string) $attributes['orderby'] ) : 'date';
		if ( ! in_array( $orderby, array( 'date', 'title', 'price' ), true ) ) {
			$orderby = 'date';
		}
		$order = isset( $attributes['order'] ) ? strtoupper( sanitize_key( (string) $attributes['order'] ) ) : 'DESC';
		$order = 'ASC' === $order ? 'ASC' : 'DESC';
		$view = isset( $attributes['view'] ) ? sanitize_key( (string) $attributes['view'] ) : 'grid';
		$view = 'list' === $view ? 'list' : 'grid';
		$sort_value = $orderby . ':' . $order;
		$results_id = 'filtron-results-' . wp_generate_uuid4();
		$toolbar_id = 'filtron-toolbar-' . wp_generate_uuid4();
		$load_more_id = 'filtron-load-more-' . wp_generate_uuid4();
		$active_label = __( 'Active filters', 'filtron' );
		$result_label = __( 'Results', 'filtron' );
		$time_label   = __( 'Load time', 'filtron' );
		$found_text   = __( 'results found', 'filtron' );
		$sort_label = __( 'Sort', 'filtron' );
		$view_grid_label = __( 'Grid view', 'filtron' );
		$view_list_label = __( 'List view', 'filtron' );
		$load_more_text = __( 'Load more', 'filtron' );
		$load_more_aria = __( 'Load next page of results', 'filtron' );
		$page_prev_text = __( 'Previous', 'filtron' );
		$page_next_text = __( 'Next', 'filtron' );
		$page_label_text = __( 'Page 1', 'filtron' );
		$page_prev_aria  = __( 'Go to previous page of results', 'filtron' );
		$page_next_aria  = __( 'Go to next page of results', 'filtron' );
		$toolbar_aria    = __( 'Results toolbar: layout and sort', 'filtron' );
		$layout_group_aria = __( 'Result layout', 'filtron' );
		$sort_field_id   = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'filtron-sort-' ) : 'filtron-sort-' . substr( md5( $toolbar_id ), 0, 10 );
		$has_sidebar_content = trim( wp_strip_all_tags( $content ) ) !== '';

		$container_classes = 'filtron-widget filtron-block-container' . $align;
		if ( ! $has_sidebar_content ) {
			$container_classes .= ' filtron-widget--no-sidebar';
		} else {
			$container_classes .= ' filtron-widget--drawer';
		}

		$html  = '<section class="' . esc_attr( $container_classes ) . '"';
		$html .= ' data-filtron-group="1"';
		$html .= ' data-filtron-group-id="' . esc_attr( (string) $gid ) . '"';
		$html .= ' data-filtron-post-type="' . esc_attr( $post_type ) . '"';
		$html .= ' data-filtron-per-page="' . esc_attr( (string) $per_page ) . '"';
		$html .= ' data-filtron-orderby="' . esc_attr( $orderby ) . '"';
		$html .= ' data-filtron-order="' . esc_attr( $order ) . '"';
		$html .= ' data-filtron-view="' . esc_attr( $view ) . '"';
		$html .= ' data-filtron-grid="#' . esc_attr( $results_id ) . '"';
		foreach ( self::get_container_theme_token_attributes( $attributes, $gid ) as $token_attr => $token_value ) {
			$html .= ' ' . esc_attr( $token_attr ) . '="' . esc_attr( $token_value ) . '"';
		}
		$html .= '>';
		if ( $has_sidebar_content ) {
			$drawer_suffix = substr( md5( uniqid( (string) $gid, true ) ), 0, 10 );
			$drawer_id     = 'filtron-offcanvas-' . $drawer_suffix;
			$drawer_title_id = 'filtron-offcanvas-title-' . $drawer_suffix;

			$drawer_title      = __( 'Filters', 'filtron' );
			$drawer_close_aria = __( 'Close filters', 'filtron' );
			$drawer_apply      = __( 'Apply filters', 'filtron' );
			/* translators: %s: number of matching posts */
			$drawer_meta0 = sprintf( __( '%s results', 'filtron' ), '0' );
			$filters_label = __( 'Filters', 'filtron' );

			$html .= '<div class="filtron-overlay" data-filtron-overlay="1"></div>';
			$html .= '<div class="filtron-offcanvas" id="' . esc_attr( $drawer_id ) . '" data-filtron-offcanvas="1" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="' . esc_attr( $drawer_title_id ) . '">';
			$html .= '<div class="filtron-offcanvas__header">';
			$html .= '<span class="filtron-offcanvas__title" id="' . esc_attr( $drawer_title_id ) . '">' . esc_html( $drawer_title ) . '</span>';
			$html .= '<button type="button" class="filtron-offcanvas__close" data-filtron-close-drawer="1" aria-label="' . esc_attr( $drawer_close_aria ) . '">&times;</button>';
			$html .= '</div>';
			$html .= '<div class="filtron-offcanvas__body">';
			$html .= '<aside class="filtron-widget__sidebar">' . $content . '</aside>';
			$html .= '</div>';
			$html .= '<div class="filtron-offcanvas__footer">';
			$html .= '<span class="filtron-offcanvas__meta" data-filtron-drawer-meta="1">' . esc_html( $drawer_meta0 ) . '</span>';
			$html .= '<button type="button" class="filtron-offcanvas__apply" data-filtron-apply-drawer="1">' . esc_html( $drawer_apply ) . '</button>';
			$html .= '</div>';
			$html .= '</div>';
			$html .= '<div class="filtron-mobile-bar" data-filtron-mobile-bar="1">';
			$html .= '<button type="button" class="filtron-mobile-bar__btn" data-filtron-open-drawer="1" aria-haspopup="dialog" aria-expanded="false" aria-controls="' . esc_attr( $drawer_id ) . '" aria-label="' . esc_attr( $filters_label ) . '">';
			$html .= esc_html( $filters_label );
			$html .= '<span class="filtron-mobile-bar__badge" data-filtron-filter-badge="1" hidden aria-hidden="true">0</span>';
			$html .= '</button></div>';
		}
		$html .= '<div class="filtron-widget__main">';
		if ( ! $has_sidebar_content && $gid > 0 && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$html .= '<p class="filtron-widget__admin-empty" role="status">' . esc_html__( 'No active filters in this Filtron group. Add or activate filters in the group editor to show storefront controls.', 'filtron' ) . '</p>';
		}
		$html .= '<div class="filtron-active-chips" data-filtron-chips-inner="1"></div>';
		$html .= '<div class="filtron-summary">';
		$html .= '<div class="filtron-summary__item"><span class="filtron-summary__value filtron-summary__value--count">0</span><span class="filtron-summary__label">' . esc_html( $result_label ) . '</span></div>';
		$html .= '<div class="filtron-summary__item"><span class="filtron-summary__value filtron-summary__value--time">0ms</span><span class="filtron-summary__label">' . esc_html( $time_label ) . '</span></div>';
		$html .= '<div class="filtron-summary__item"><span class="filtron-summary__value filtron-summary__value--active">0</span><span class="filtron-summary__label">' . esc_html( $active_label ) . '</span></div>';
		$html .= '</div>';
		$html .= '<p class="filtron-widget__result-meta"><span class="filtron-summary__result-count">0</span> ' . esc_html( $found_text ) . '</p>';
		$html .= '<div id="' . esc_attr( $toolbar_id ) . '" class="filtron-toolbar" data-filtron-toolbar="1" role="toolbar" aria-label="' . esc_attr( $toolbar_aria ) . '">';
		$html .= '<div class="filtron-toolbar__views" role="group" aria-label="' . esc_attr( $layout_group_aria ) . '">';
		$html .= '<button type="button" class="filtron-toolbar__btn' . ( 'grid' === $view ? ' is-active' : '' ) . '" data-filtron-view="grid" aria-pressed="' . ( 'grid' === $view ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $view_grid_label ) . '">&#9638;</button>';
		$html .= '<button type="button" class="filtron-toolbar__btn' . ( 'list' === $view ? ' is-active' : '' ) . '" data-filtron-view="list" aria-pressed="' . ( 'list' === $view ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $view_list_label ) . '">&#9776;</button>';
		$html .= '</div>';
		$html .= '<label class="filtron-toolbar__sort" for="' . esc_attr( $sort_field_id ) . '"><span class="screen-reader-text">' . esc_html( $sort_label ) . '</span>';
		$html .= '<select id="' . esc_attr( $sort_field_id ) . '" class="filtron-toolbar__select" data-filtron-sort="1" aria-label="' . esc_attr( $sort_label ) . '">';
		$html .= '<option value="date:DESC"' . selected( $sort_value, 'date:DESC', false ) . '>' . esc_html__( 'Newest', 'filtron' ) . '</option>';
		$html .= '<option value="title:ASC"' . selected( $sort_value, 'title:ASC', false ) . '>' . esc_html__( 'Title (A-Z)', 'filtron' ) . '</option>';
		$html .= '<option value="price:ASC"' . selected( $sort_value, 'price:ASC', false ) . '>' . esc_html__( 'Price: low to high', 'filtron' ) . '</option>';
		$html .= '<option value="price:DESC"' . selected( $sort_value, 'price:DESC', false ) . '>' . esc_html__( 'Price: high to low', 'filtron' ) . '</option>';
		$html .= '</select></label>';
		$html .= '</div>';
		$html .= '<div id="' . esc_attr( $results_id ) . '" class="filtron-results filtron-results--default' . ( 'list' === $view ? ' filtron-results--list' : '' ) . '"></div>';
		$html .= '<nav class="filtron-pagination" data-filtron-pagination="1" aria-label="' . esc_attr__( 'Results pagination', 'filtron' ) . '">';
		$html .= '<button type="button" class="filtron-pagination__btn" data-filtron-page-prev="1" aria-label="' . esc_attr( $page_prev_aria ) . '">' . esc_html( $page_prev_text ) . '</button>';
		$html .= '<span class="filtron-pagination__label" data-filtron-page-label="1" aria-live="polite">' . esc_html( $page_label_text ) . '</span>';
		$html .= '<button type="button" class="filtron-pagination__btn" data-filtron-page-next="1" aria-label="' . esc_attr( $page_next_aria ) . '">' . esc_html( $page_next_text ) . '</button>';
		$html .= '</nav>';
		$html .= '<div class="filtron-widget__actions"><button id="' . esc_attr( $load_more_id ) . '" type="button" class="filtron-load-more" data-filtron-load-more="1" aria-label="' . esc_attr( $load_more_aria ) . '">' . esc_html( $load_more_text ) . '</button></div>';
		$html .= '</div>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * Get theme token attributes for frontend container.
	 *
	 * Third parties (including filtron-pro) can inject token values with:
	 * `add_filter( 'filtron_container_theme_tokens', fn( $tokens ) => $tokens );`
	 *
	 * Allowed token keys:
	 * accent, accent_2, accent_soft, accent_border, track, text_accent, price, rating, time.
	 *
	 * @param array<string, mixed> $attributes Block attrs.
	 * @param int                  $group_id   Group id.
	 * @return array<string, string>
	 */
	private static function get_container_theme_token_attributes( array $attributes, int $group_id ): array {
		$defaults = self::get_default_theme_tokens();
		$base     = self::get_theme_tokens_from_settings();
		$raw      = apply_filters( 'filtron_container_theme_tokens', $base, $attributes, $group_id );
		if ( ! is_array( $raw ) ) {
			$raw = $base;
		}

		$out = array();
		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}
			$value = sanitize_hex_color( (string) $raw[ $key ] );
			if ( ! is_string( $value ) || '' === $value ) {
				$value = $default;
			}
			if ( '' === $value ) {
				continue;
			}
			$attr_key         = 'data-filtron-' . str_replace( '_', '-', $key );
			$out[ $attr_key ] = strtolower( $value );
		}

		return $out;
	}

	/**
	 * Default frontend theme tokens.
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
	 * Resolve saved theme tokens from plugin settings.
	 *
	 * @return array<string, string>
	 */
	private static function get_theme_tokens_from_settings(): array {
		$defaults = self::get_default_theme_tokens();
		$opts     = get_option( Filtron_Activator::OPTION_SETTINGS, array() );
		if ( ! is_array( $opts ) ) {
			return $defaults;
		}

		$raw = isset( $opts['theme_tokens'] ) && is_array( $opts['theme_tokens'] ) ? $opts['theme_tokens'] : array();
		$out = array();
		foreach ( $defaults as $key => $default ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_hex_color( (string) $raw[ $key ] ) : '';
			$out[ $key ] = is_string( $value ) && '' !== $value ? strtolower( $value ) : $default;
		}

		return $out;
	}

	/**
	 * Get post type from selected group.
	 *
	 * @param int $group_id Group id.
	 */
	private static function get_post_type_for_group( int $group_id ): string {
		if ( $group_id < 1 ) {
			return 'post';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'filtron_groups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_type FROM `{$table}` WHERE id = %d LIMIT 1",
				$group_id
			)
		);

		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return 'post';
		}

		return sanitize_key( $post_type );
	}

	/**
	 * @param array<string, mixed> $attributes Block attrs.
	 */
	public static function render_checkbox( array $attributes ): string {
		$key = isset( $attributes['sourceKey'] ) ? sanitize_key( (string) $attributes['sourceKey'] ) : '';
		if ( '' === $key ) {
			return '';
		}

		$st = isset( $attributes['sourceType'] ) ? sanitize_key( (string) $attributes['sourceType'] ) : 'taxonomy';
		if ( ! Filtron_Security::validate_source_type( $st ) ) {
			$st = 'taxonomy';
		}

		$logic = isset( $attributes['logic'] ) && 'AND' === strtoupper( (string) $attributes['logic'] ) ? 'AND' : 'OR';

		$show_count = true;
		if ( array_key_exists( 'showCount', $attributes ) ) {
			$show_count = (bool) $attributes['showCount'];
		}

		$config = array(
			'label'       => isset( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '',
			'source_key'  => $key,
			'key'         => $key,
			'source_type' => $st,
			'logic'       => $logic,
			'show_count'  => $show_count,
		);

		$filter = new Filtron_Filter_Checkbox( $config );
		return $filter->render();
	}

	/**
	 * @param array<string, mixed> $attributes Block attrs.
	 */
	public static function render_range( array $attributes ): string {
		$key = isset( $attributes['sourceKey'] ) ? sanitize_key( (string) $attributes['sourceKey'] ) : '';
		if ( '' === $key ) {
			return '';
		}

		$st = isset( $attributes['sourceType'] ) ? sanitize_key( (string) $attributes['sourceType'] ) : 'meta';
		if ( ! Filtron_Security::validate_source_type( $st ) ) {
			$st = 'meta';
		}

		$config = array(
			'label'       => isset( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '',
			'source_key'  => $key,
			'key'         => $key,
			'source_type' => $st,
		);

		$filter = new Filtron_Filter_Range( $config );
		return $filter->render();
	}

	/**
	 * @param array<string, mixed> $attributes Block attrs.
	 */
	public static function render_search( array $attributes ): string {
		$key = isset( $attributes['sourceKey'] ) ? trim( sanitize_text_field( (string) $attributes['sourceKey'] ) ) : '';
		if ( '' === $key ) {
			return '';
		}

		$st = isset( $attributes['sourceType'] ) ? sanitize_key( (string) $attributes['sourceType'] ) : 'meta';
		if ( ! Filtron_Security::validate_source_type( $st ) ) {
			$st = 'meta';
		}

		$config = array(
			'label'       => isset( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '',
			'source_key'  => $key,
			'key'         => $key,
			'source_type' => $st,
		);

		$filter = new Filtron_Filter_Search( $config );
		return $filter->render();
	}
}
