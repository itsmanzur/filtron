<?php
/**
 * AJAX handlers for public filtering.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Ajax
 */
class Filtron_Ajax {

	/**
	 * Register AJAX actions.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_filtron_filter', array( self::class, 'handle' ) );
		add_action( 'wp_ajax_nopriv_filtron_filter', array( self::class, 'handle' ) );

		add_action( 'wp_ajax_filtron_search_suggest', array( 'Filtron_Filter_Search', 'ajax_suggest' ) );
		add_action( 'wp_ajax_nopriv_filtron_search_suggest', array( 'Filtron_Filter_Search', 'ajax_suggest' ) );
	}

	/**
	 * Handle filtron_filter AJAX (logged-in + guests).
	 */
	public static function handle(): void {
		if ( ! self::check_rate_limit() ) {
			self::fail_response( __( 'Too many requests. Please wait a moment.', 'filtron' ), 429, 'rate_limited' );
			return;
		}

		global $wpdb;

		$started        = microtime( true );
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;

		try {
			// 1. Nonce.
			$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : null;
			Filtron_Security::verify_nonce( $nonce );

			// 2. Sanitize filters.
			$raw = isset( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array();
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$raw     = is_array( $decoded ) ? $decoded : array();
			}
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}

			$sanitized_filters = Filtron_Security::sanitize_filter_input( $raw );

			// 3. Validate filter type for each filter row.
			self::validate_filter_types_in_payload( $sanitized_filters );

			$query_options = self::get_query_options_from_request();

			// 4. Cache key (include query options so pagination/order differs; payload is still $sanitized_filters from step 2).
			$cache_key = Filtron_Cache::make_key(
				array(
					'filters' => $sanitized_filters,
					'options' => $query_options,
				)
			);

			// 5. Cache hit.
			$cached = Filtron_Cache::get( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				if ( isset( $cached['filters'] ) && ! isset( $cached['filter_counts'] ) ) {
					$cached['filter_counts'] = $cached['filters'];
				}
				if ( ! isset( $cached['execution_time_ms'] ) ) {
					$cached['execution_time_ms'] = 0;
				}
				if ( ! isset( $cached['execution_time'] ) ) {
					$cached['execution_time'] = round( (int) $cached['execution_time_ms'] / 1000, 4 );
				}
				wp_send_json_success( self::attach_debug_payload( $cached, $started, $queries_before, true ) );
				return;
			}

			// 6. Query.
			$filter_args = self::filter_args_for_query( $sanitized_filters );
			$result       = Filtron_Query::run( $filter_args, $query_options );
			$result_count = count( $result );
			$posts        = self::format_posts_payload( $result );
			$total_count  = self::resolve_total_count( $filter_args, $query_options, $result_count );
			$pagination   = self::build_pagination_payload( $query_options, $total_count, $result_count );

			// 7. Analytics (Pro).
			if ( defined( 'FILTRON_PRO_VERSION' ) && class_exists( 'Filtron_Analytics' ) && is_callable( array( 'Filtron_Analytics', 'record' ) ) ) {
				Filtron_Analytics::record( $sanitized_filters, $result_count );
			}

			$updated_counts = Filtron_Query::get_counts( $filter_args, $query_options );
			$execution_time_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

			$payload = array(
				'posts'          => $posts,
				'count'          => $result_count,
				'total_count'    => $total_count,
				'total_pages'    => $pagination['total_pages'],
				'current_page'   => $pagination['current_page'],
				'per_page'       => $pagination['per_page'],
				'filters'        => $updated_counts,
				'filter_counts'  => $updated_counts,
				'execution_time_ms' => $execution_time_ms,
				'execution_time' => round( $execution_time_ms / 1000, 4 ),
				'has_more'       => $pagination['has_more'],
			);

			// 8. Store (never cache admin-only debug keys).
			Filtron_Cache::set( $cache_key, $payload, 3600 );

			// 9. Respond.
			wp_send_json_success( self::attach_debug_payload( $payload, $started, $queries_before, false ) );
		} catch ( InvalidArgumentException $e ) {
			self::fail_response( __( 'Invalid filter configuration.', 'filtron' ), 400, 'invalid_filters' );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Filtron AJAX: ' . $e->getMessage() );
			}
			self::fail_response( __( 'Something went wrong. Please try again.', 'filtron' ), 500, 'server_error' );
		}
	}

	/**
	 * Whether to append debug stats to AJAX success (admins + opt-in only).
	 */
	private static function ajax_should_debug(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$opts = get_option( Filtron_Activator::OPTION_SETTINGS, array() );
		return is_array( $opts ) && ! empty( $opts['frontend_debug'] );
	}

	/**
	 * Attach debug block for administrators (not stored in cache).
	 *
	 * @param array<string, mixed> $payload       Response payload.
	 * @param float                $started       microtime(true) at request start.
	 * @param int                  $queries_before $wpdb->num_queries at request start.
	 * @param bool                 $cache_hit     Whether response came from object cache.
	 * @return array<string, mixed>
	 */
	private static function attach_debug_payload( array $payload, float $started, int $queries_before, bool $cache_hit ): array {
		if ( ! self::ajax_should_debug() ) {
			return $payload;
		}
		global $wpdb;
		$payload['debug'] = array(
			'server_time_ms'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'wp_query_count'    => max( 0, ( isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0 ) - $queries_before ),
			'cache_hit'         => $cache_hit,
			'execution_time_ms' => isset( $payload['execution_time_ms'] ) ? (int) $payload['execution_time_ms'] : 0,
		);
		return $payload;
	}

	/**
	 * Send a standardized JSON error payload.
	 *
	 * @param string $message Safe user-facing message.
	 * @param int    $status  HTTP status.
	 * @param string $code    Machine-readable error code.
	 */
	private static function fail_response( string $message, int $status, string $code ): void {
		wp_send_json_error(
			array(
				'message' => $message,
				'code'    => sanitize_key( $code ),
			),
			$status
		);
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
	 * Basic has-more estimation from current page payload.
	 *
	 * @param int                  $result_count Current response posts count.
	 * @param array<string, mixed> $query_options Query options.
	 */
	private static function has_more_results( int $result_count, array $query_options ): bool {
		if ( ! isset( $query_options['per_page'] ) ) {
			return false;
		}
		$per_page = max( 1, (int) $query_options['per_page'] );
		return $result_count >= $per_page;
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
		$key    = 'filtron_rl_' . md5( $ip . '|' . (string) $bucket );

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
	 * Sanitized query options from POST.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_query_options_from_request(): array {
		$opts = array();

		if ( isset( $_POST['per_page'] ) ) {
			$opts['per_page'] = max( 1, absint( wp_unslash( $_POST['per_page'] ) ) );
		}
		if ( isset( $_POST['page'] ) ) {
			$opts['page'] = max( 1, absint( wp_unslash( $_POST['page'] ) ) );
		}
		if ( isset( $_POST['orderby'] ) ) {
			$opts['orderby'] = sanitize_key( wp_unslash( (string) $_POST['orderby'] ) );
		}
		if ( isset( $_POST['order'] ) ) {
			$o = strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['order'] ) ) );
			$opts['order'] = in_array( $o, array( 'ASC', 'DESC' ), true ) ? $o : 'DESC';
		}
		if ( isset( $_POST['post_type'] ) ) {
			$pt = sanitize_key( wp_unslash( (string) $_POST['post_type'] ) );
			if ( '' !== $pt ) {
				$opts['post_type'] = $pt;
			}
		}

		return $opts;
	}

	/**
	 * Ensure each filter row has an allowed type (range rows use type "range").
	 *
	 * @param array<string|int, mixed> $sanitized Sanitized payload.
	 */
	private static function validate_filter_types_in_payload( array $sanitized ): void {
		$rows = self::extract_filter_rows( $sanitized );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( isset( $row['min'], $row['max'], $row['key'] ) ) {
				$type = isset( $row['type'] ) ? (string) $row['type'] : 'range';
				if ( ! Filtron_Security::validate_filter_type( $type ) ) {
					throw new InvalidArgumentException( 'Invalid filter type for range row.' );
				}
				continue;
			}

			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( '' === $type || ! Filtron_Security::validate_filter_type( $type ) ) {
				throw new InvalidArgumentException( 'Invalid or missing filter type.' );
			}
		}
	}

	/**
	 * Flatten sanitized payload into a list of filter rows for validation/query.
	 *
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
	 * Build filter args array for Filtron_Query::run().
	 *
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
}
