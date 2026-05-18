<?php
/**
 * REST API for headless / external clients.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Rest
 */
class Filtron_Rest {

	/**
	 * REST namespace (path segment after wp-json).
	 */
	public const NAMESPACE = 'filtron/v1';

	/**
	 * Hook registration (bootstrap from main plugin).
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'maybe_send_cors_headers' ), 10, 4 );
	}

	/**
	 * Register routes (called on rest_api_init).
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/filter',
			array(
				array(
					'methods'             => array( 'GET', 'POST' ),
					'callback'            => array( self::class, 'handle_filter' ),
					'permission_callback' => array( self::class, 'permissions_verify_nonce_header' ),
					'args'                => self::get_filter_endpoint_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/options/(?P<filter_key>[a-zA-Z0-9_.-]{1,100})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_options' ),
					'permission_callback' => array( self::class, 'permissions_verify_nonce_header' ),
					'args'                => array(
						'filter_key' => array(
							'description'       => __( 'Index filter_key column value.', 'filtron' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( self::class, 'sanitize_filter_key_param' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Declared args for /filter (sanitized via WP_REST_Request).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_filter_endpoint_args(): array {
		return array(
			'filters'    => array(
				'description'       => __( 'Filter payload (JSON string or array).', 'filtron' ),
				'required'          => false,
				'sanitize_callback' => array( self::class, 'sanitize_filters_param' ),
			),
			'per_page'   => array(
				'description' => __( 'Results per page.', 'filtron' ),
				'type'        => 'integer',
				'required'    => false,
				'minimum'     => 1,
			),
			'page'       => array(
				'description' => __( 'Page number.', 'filtron' ),
				'type'        => 'integer',
				'required'    => false,
				'minimum'     => 1,
			),
			'orderby'    => array(
				'description' => __( 'Order by field.', 'filtron' ),
				'type'        => 'string',
				'required'    => false,
			),
			'order'      => array(
				'description' => __( 'ASC or DESC.', 'filtron' ),
				'type'        => 'string',
				'required'    => false,
			),
			'post_type'  => array(
				'description' => __( 'Limit to post type when no filters.', 'filtron' ),
				'type'        => 'string',
				'required'    => false,
			),
		);
	}

	/**
	 * @param string|array<string|int, mixed>|object|null $value Raw filters param.
	 * @return array<string|int, mixed>
	 */
	public static function sanitize_filters_param( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return (array) $value;
		}
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	/**
	 * @param string $value filter_key path param.
	 */
	public static function sanitize_filter_key_param( string $value ): string {
		return self::truncate( sanitize_text_field( $value ), 100 );
	}

	/**
	 * Public route; access controlled via nonce header.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function permissions_verify_nonce_header( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'x_filtron_nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return new WP_Error(
				'filtron_rest_missing_nonce',
				__( 'Missing X-Filtron-Nonce header.', 'filtron' ),
				array( 'status' => 403 )
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'filtron_filter_nonce' ) ) {
			return new WP_Error(
				'filtron_rest_invalid_nonce',
				__( 'Invalid or expired nonce.', 'filtron' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET/POST /filtron/v1/filter
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_filter( WP_REST_Request $request ) {
		if ( ! self::check_rate_limit() ) {
			return new WP_Error(
				'filtron_rest_rate_limited',
				__( 'Too many requests. Please wait a moment.', 'filtron' ),
				array( 'status' => 429 )
			);
		}

		$started = microtime( true );

		try {
			$raw_filters = self::collect_filters_from_request( $request );
			if ( ! is_array( $raw_filters ) ) {
				$raw_filters = array();
			}

			$sanitized = Filtron_Security::sanitize_filter_input( $raw_filters );

			$validated = self::validate_filter_types_rest( $sanitized );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$query_options = self::get_query_options_from_rest_request( $request );

			$cache_key = Filtron_Cache::make_key(
				array(
					'filters' => $sanitized,
					'options' => $query_options,
				)
			);

			$cached = Filtron_Cache::get( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return self::rest_success_from_payload( $cached, $started );
			}

			$filter_args = self::filter_args_for_query( $sanitized );
			$result_rows = Filtron_Query::run( $filter_args, $query_options );
			$count       = count( $result_rows );
			$posts       = self::format_posts_payload( $result_rows );
			$total_count = self::resolve_total_count( $filter_args, $query_options, $count );
			$pagination  = self::build_pagination_payload( $query_options, $total_count, $count );
			$filter_counts = Filtron_Query::get_counts( $filter_args, $query_options );

			if ( defined( 'FILTRON_PRO_VERSION' ) && class_exists( 'Filtron_Analytics' ) && is_callable( array( 'Filtron_Analytics', 'record' ) ) ) {
				Filtron_Analytics::record( $sanitized, $count );
			}

			$execution_time_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

			$payload = array(
				'posts'             => $posts,
				'count'             => $count,
				'total_count'       => $total_count,
				'total_pages'       => $pagination['total_pages'],
				'current_page'      => $pagination['current_page'],
				'per_page'          => $pagination['per_page'],
				'filters'           => $filter_counts,
				'filter_counts'     => $filter_counts,
				'has_more'          => $pagination['has_more'],
				'execution_time_ms' => $execution_time_ms,
				'execution_time'    => round( $execution_time_ms / 1000, 4 ),
			);

			Filtron_Cache::set( $cache_key, $payload, 3600 );

			return self::rest_success_from_payload( $payload, $started );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Filtron REST: ' . $e->getMessage() );
			}

			return new WP_Error(
				'filtron_rest_query_failed',
				__( 'Unable to complete the request.', 'filtron' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Build WP_REST_Response with timing fields.
	 *
	 * @param array<string, mixed> $payload Cached or fresh payload (posts, count, filters/filter_counts).
	 * @param float                $started microtime( true ) start.
	 */
	private static function rest_success_from_payload( array $payload, float $started ): WP_REST_Response {
		$filter_counts = $payload['filter_counts'] ?? array();
		if ( ! is_array( $filter_counts ) && isset( $payload['filters'] ) && is_array( $payload['filters'] ) ) {
			$filter_counts = $payload['filters'];
		}
		if ( ! is_array( $filter_counts ) ) {
			$filter_counts = array();
		}
		$execution_time_ms = isset( $payload['execution_time_ms'] ) ? (int) $payload['execution_time_ms'] : (int) round( ( microtime( true ) - $started ) * 1000 );
		$execution_time    = round( $execution_time_ms / 1000, 4 );

		$data = array(
			'posts'             => isset( $payload['posts'] ) && is_array( $payload['posts'] ) ? $payload['posts'] : array(),
			'count'             => isset( $payload['count'] ) ? (int) $payload['count'] : 0,
			'total_count'       => isset( $payload['total_count'] ) ? (int) $payload['total_count'] : ( isset( $payload['count'] ) ? (int) $payload['count'] : 0 ),
			'total_pages'       => isset( $payload['total_pages'] ) ? max( 1, (int) $payload['total_pages'] ) : 1,
			'current_page'      => isset( $payload['current_page'] ) ? max( 1, (int) $payload['current_page'] ) : 1,
			'per_page'          => isset( $payload['per_page'] ) ? max( 1, (int) $payload['per_page'] ) : max( 1, isset( $payload['count'] ) ? (int) $payload['count'] : 1 ),
			'filters'           => $filter_counts,
			'filter_counts'     => $filter_counts,
			'has_more'          => ! empty( $payload['has_more'] ),
			'execution_time_ms' => $execution_time_ms,
			'execution_time'    => $execution_time,
		);

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-Filtron-Execution-Time', (string) $execution_time );
		$response->header( 'X-Filtron-Execution-Time-Ms', (string) $execution_time_ms );

		return $response;
	}

	/**
	 * GET /filtron/v1/options/{filter_key}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_options( WP_REST_Request $request ) {
		$started = microtime( true );

		try {
			$key = (string) $request->get_param( 'filter_key' );
			if ( '' === $key ) {
				return new WP_Error(
					'filtron_rest_missing_filter_key',
					__( 'Missing filter key.', 'filtron' ),
					array( 'status' => 400 )
				);
			}

			global $wpdb;
			$table = $wpdb->prefix . 'filtron_index';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
			$sql = $wpdb->prepare(
				"SELECT DISTINCT filter_value FROM `{$table}` WHERE filter_key = %s ORDER BY filter_value ASC LIMIT 5000",
				$key
			);

			$values = $wpdb->get_col( $sql );
			if ( ! is_array( $values ) ) {
				$values = array();
			}

			$data = array(
				'filter_key'     => $key,
				'values'         => array_map( 'strval', $values ),
				'count'          => count( $values ),
				'execution_time_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'execution_time' => round( microtime( true ) - $started, 4 ),
			);

			$response = new WP_REST_Response( $data, 200 );
			$response->header( 'X-Filtron-Execution-Time', (string) $data['execution_time'] );

			return $response;
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Filtron REST options: ' . $e->getMessage() );
			}

			return new WP_Error(
				'filtron_rest_options_query_failed',
				__( 'Unable to load filter options.', 'filtron' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * CORS for allowed origins (headless / React).
	 *
	 * @param bool             $served  Whether request was served.
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 */
	public static function maybe_send_cors_headers( $served, $result, $request, $server ): bool {
		unset( $server, $result );

		if ( ! $request instanceof WP_REST_Request ) {
			return $served;
		}

		$route = $request->get_route();
		if ( ! is_string( $route ) || false === strpos( $route, '/filtron/v1' ) ) {
			return $served;
		}

		$origin = $request->get_header( 'origin' );
		if ( ! is_string( $origin ) || '' === $origin ) {
			return $served;
		}

		$origin_clean = untrailingslashit( esc_url_raw( $origin ) );
		$defaults     = array( untrailingslashit( home_url( '/' ) ) );
		$allowed      = apply_filters( 'filtron_allowed_origins', $defaults );
		if ( ! is_array( $allowed ) ) {
			$allowed = $defaults;
		}

		$allowed_normalized = array();
		foreach ( $allowed as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$allowed_normalized[] = untrailingslashit( esc_url_raw( $url ) );
		}

		if ( ! in_array( $origin_clean, $allowed_normalized, true ) ) {
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-Filtron-Nonce, X-WP-Nonce' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Max-Age: 600' );

		return $served;
	}

	/**
	 * Merge JSON body, query, and structured filters param.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string|int, mixed>
	 */
	private static function collect_filters_from_request( WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) && isset( $json['filters'] ) ) {
			$f = $json['filters'];
			return is_array( $f ) ? $f : self::sanitize_filters_param( $f );
		}

		$param = $request->get_param( 'filters' );
		if ( null !== $param ) {
			return self::sanitize_filters_param( $param );
		}

		return array();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function get_query_options_from_rest_request( WP_REST_Request $request ): array {
		$opts = array();

		if ( null !== $request->get_param( 'per_page' ) ) {
			$opts['per_page'] = max( 1, absint( $request->get_param( 'per_page' ) ) );
		}
		if ( null !== $request->get_param( 'page' ) ) {
			$opts['page'] = max( 1, absint( $request->get_param( 'page' ) ) );
		}
		if ( null !== $request->get_param( 'orderby' ) ) {
			$opts['orderby'] = sanitize_key( (string) $request->get_param( 'orderby' ) );
		}
		if ( null !== $request->get_param( 'order' ) ) {
			$o = strtoupper( sanitize_text_field( (string) $request->get_param( 'order' ) ) );
			$opts['order'] = in_array( $o, array( 'ASC', 'DESC' ), true ) ? $o : 'DESC';
		}
		if ( null !== $request->get_param( 'post_type' ) ) {
			$pt = sanitize_key( (string) $request->get_param( 'post_type' ) );
			if ( '' !== $pt ) {
				$opts['post_type'] = $pt;
			}
		}

		return $opts;
	}

	/**
	 * Build frontend-ready cards from query rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Query rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function format_posts_payload( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['ID'] ) ) {
				continue;
			}

			$post_id = absint( $row['ID'] );
			if ( $post_id < 1 ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}

			$thumb = get_the_post_thumbnail_url( $post_id, 'medium' );
			$price_raw = get_post_meta( $post_id, '_price', true );
			$price_label = '';
			if ( is_numeric( $price_raw ) ) {
				$price_number = (float) $price_raw;
				if ( function_exists( 'wc_price' ) ) {
					$price_label = wp_strip_all_tags( (string) wc_price( $price_number ) );
					$price_label = html_entity_decode( $price_label, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
					$price_label = html_entity_decode( $price_label, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
					$price_label = str_replace( "\xc2\xa0", ' ', $price_label );
				} else {
					$price_label = number_format_i18n( $price_number, 2 );
				}
			}

			$brand_label = self::get_post_brand_label( $post_id );
			$rating_value = 0.0;
			$rating_raw = get_post_meta( $post_id, '_wc_average_rating', true );
			if ( is_numeric( $rating_raw ) ) {
				$rating_value = max( 0.0, min( 5.0, (float) $rating_raw ) );
			}

			$excerpt_source = get_the_excerpt( $post );
			if ( '' === trim( (string) $excerpt_source ) ) {
				$excerpt_source = (string) $post->post_content;
			}
			$excerpt = wp_trim_words( wp_strip_all_tags( (string) $excerpt_source ), 18 );

			$out[] = array(
				'ID'         => $post_id,
				'post_title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'post_type'  => $post->post_type,
				'permalink'  => get_permalink( $post_id ),
				'thumbnail'  => is_string( $thumb ) ? $thumb : '',
				'excerpt'    => $excerpt,
				'price'      => $price_label,
				'brand'      => $brand_label,
				'rating'     => $rating_value,
			);
		}

		return $out;
	}

	/**
	 * Resolve total matched count (unpaged) for accurate pagination.
	 *
	 * @param array<int, array<string, mixed>> $filter_args Filter args for query engine.
	 * @param array<string, mixed>             $query_options Current page options.
	 * @param int                              $current_page_count Count already fetched for current page.
	 */
	private static function resolve_total_count( array $filter_args, array $query_options, int $current_page_count ): int {
		if ( ! isset( $query_options['per_page'] ) ) {
			return $current_page_count;
		}

		$count_options = $query_options;
		unset( $count_options['per_page'], $count_options['page'] );

		$all_rows = Filtron_Query::run( $filter_args, $count_options );
		if ( ! is_array( $all_rows ) ) {
			return $current_page_count;
		}

		return count( $all_rows );
	}

	/**
	 * Build pagination metadata for frontend.
	 *
	 * @param array<string, mixed> $query_options Current page options.
	 * @param int                  $total_count Total matches.
	 * @param int                  $current_page_count Count in current page response.
	 * @return array{current_page:int, total_pages:int, per_page:int, has_more:bool}
	 */
	private static function build_pagination_payload( array $query_options, int $total_count, int $current_page_count ): array {
		$current_page = isset( $query_options['page'] ) ? max( 1, (int) $query_options['page'] ) : 1;
		$per_page     = isset( $query_options['per_page'] ) ? max( 1, (int) $query_options['per_page'] ) : max( 1, $current_page_count );
		$total_pages  = $per_page > 0 ? max( 1, (int) ceil( $total_count / $per_page ) ) : 1;
		$has_more     = $current_page < $total_pages;

		return array(
			'current_page' => $current_page,
			'total_pages'  => $total_pages,
			'per_page'     => $per_page,
			'has_more'     => $has_more,
		);
	}

	/**
	 * Resolve best-effort brand text for product-like posts.
	 *
	 * @param int $post_id Post id.
	 */
	private static function get_post_brand_label( int $post_id ): string {
		$tax_candidates = array( 'pa_brand', 'product_brand', 'brand' );
		foreach ( $tax_candidates as $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			if ( is_array( $terms ) && ! empty( $terms[0] ) && isset( $terms[0]->name ) ) {
				return sanitize_text_field( (string) $terms[0]->name );
			}
		}
		return '';
	}

	/**
	 * Max 60 requests per minute per IP (transient bucket).
	 */
	private static function check_rate_limit(): bool {
		$ip = self::get_client_ip();
		if ( '' === $ip ) {
			return true;
		}

		$bucket = (int) floor( time() / 60 );
		$key    = 'filtron_rl_rest_' . md5( $ip . '|' . (string) $bucket );

		$count = (int) get_transient( $key );
		if ( $count >= 60 ) {
			return false;
		}

		set_transient( $key, $count + 1, 120 );
		return true;
	}

	/**
	 * Best-effort client IP.
	 */
	private static function get_client_ip(): string {
		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}

	/**
	 * @param array<string|int, mixed> $sanitized Sanitized filters.
	 * @return true|WP_Error
	 */
	private static function validate_filter_types_rest( array $sanitized ) {
		$rows = self::extract_filter_rows( $sanitized );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( isset( $row['min'], $row['max'], $row['key'] ) ) {
				$type = isset( $row['type'] ) ? (string) $row['type'] : 'range';
				if ( ! Filtron_Security::validate_filter_type( $type ) ) {
					return new WP_Error(
						'filtron_rest_invalid_filter_type',
						__( 'Invalid filter type for range filter.', 'filtron' ),
						array( 'status' => 400 )
					);
				}
				continue;
			}

			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( '' === $type || ! Filtron_Security::validate_filter_type( $type ) ) {
				return new WP_Error(
					'filtron_rest_invalid_filter_type',
					__( 'Invalid or missing filter type.', 'filtron' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * @param array<string|int, mixed> $sanitized Sanitized payload.
	 * @return array<int, mixed>
	 */
	private static function extract_filter_rows( array $sanitized ): array {
		if ( array() === $sanitized ) {
			return array();
		}

		$keys = array_keys( $sanitized );
		$seq  = range( 0, count( $sanitized ) - 1 );
		if ( $keys === $seq ) {
			return array_values( $sanitized );
		}

		if ( isset( $sanitized['filters'] ) && is_array( $sanitized['filters'] ) ) {
			return array_values( $sanitized['filters'] );
		}

		return array( $sanitized );
	}

	/**
	 * @param array<string|int, mixed> $sanitized Sanitized payload.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_args_for_query( array $sanitized ): array {
		$rows = self::extract_filter_rows( $sanitized );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param string $value String.
	 * @param int    $max   Max length.
	 */
	private static function truncate( string $value, int $max ): string {
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max );
	}
}
