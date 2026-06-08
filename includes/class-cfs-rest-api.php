<?php
/**
 * REST API endpoint for CF-Summarize.
 *
 * @package CF_Summarize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Rest_API
 *
 * Registers and handles the /cf-sum/v1/summarize POST endpoint.
 */
class CFS_Rest_API {

	/**
	 * Constructor - hook into REST API init.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the summarize route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'cf-sum/v1',
			'/summarize',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_request' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'force_refresh' => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
				],
			]
		);
	}

	/**
	 * Handle the summarize POST request.
	 *
	 * @param WP_REST_Request $request Full REST request object.
	 * @return WP_REST_Response|WP_Error JSON response or error.
	 */
	public function handle_request( WP_REST_Request $request ) {
		// 1. Validate post. (The endpoint is intentionally public; a nonce baked
		// into post HTML is unreliable under full-page caching, so access is
		// gated by post validity, caching, and rate limiting instead.)
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found or not published.', 'cf-summarize' ),
				[ 'status' => 404 ]
			);
		}

		// 2. Resolve caching state. force_refresh (the regenerate button) bypasses
		// the cache only for users who can edit the post, so anonymous visitors
		// can't drive repeated paid API calls past the cache.
		$cache_enabled = (bool) get_option( 'cfs_cache_enabled', false );
		$can_refresh   = current_user_can( 'edit_post', $post_id );
		$force_refresh = $can_refresh && (bool) $request->get_param( 'force_refresh' );

		// 3. Serve a fresh-enough cached summary when available.
		if ( $cache_enabled && ! $force_refresh ) {
			$cached = $this->get_cached_summary( $post_id );
			if ( null !== $cached ) {
				return new WP_REST_Response(
					[
						'key_points' => $cached['key_points'],
						'conclusion' => $cached['conclusion'],
						'cached'     => true,
					],
					200
				);
			}
		}

		// 4. Rate limit only the expensive path (cache misses / refreshes):
		// max 10 generations per 60 seconds per IP, fixed window.
		$rate_error = $this->check_rate_limit();
		if ( is_wp_error( $rate_error ) ) {
			return $rate_error;
		}

		// 5. Extract content.
		try {
			$content = CFS_Content_Extractor::extract( $post );
		} catch ( Exception $e ) {
			return new WP_Error(
				'extraction_error',
				$e->getMessage(),
				[ 'status' => 422 ]
			);
		}

		// 6. Call AI.
		try {
			$ai_client = new CFS_AI_Client();
			$result    = $ai_client->summarize( $content );
		} catch ( Exception $e ) {
			return new WP_Error(
				'ai_error',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		// 7. Cache the result with a timestamp (only if caching is enabled).
		if ( $cache_enabled ) {
			update_post_meta(
				$post_id,
				'_cfs_summary',
				[
					'key_points' => $result['key_points'],
					'conclusion' => $result['conclusion'],
					'cached_at'  => time(),
				]
			);
		}

		// 8. Return response.
		return new WP_REST_Response(
			[
				'key_points' => $result['key_points'],
				'conclusion' => $result['conclusion'],
				'cached'     => false,
			],
			200
		);
	}

	/**
	 * Return a cached summary if present and not past its configured duration.
	 *
	 * @param int $post_id Post ID.
	 * @return array{key_points: string[], conclusion: string}|null Null on miss/expiry.
	 */
	private function get_cached_summary( int $post_id ): ?array {
		$cached = get_post_meta( $post_id, '_cfs_summary', true );

		if ( ! is_array( $cached ) || ! isset( $cached['key_points'], $cached['conclusion'] ) ) {
			return null;
		}

		$duration = (int) get_option( 'cfs_cache_duration', 86400 );

		// 0 = never expire. Entries without a timestamp (legacy) are treated as fresh.
		if ( $duration > 0 && isset( $cached['cached_at'] ) ) {
			if ( ( time() - (int) $cached['cached_at'] ) > $duration ) {
				return null;
			}
		}

		return [
			'key_points' => $cached['key_points'],
			'conclusion' => $cached['conclusion'],
		];
	}

	/**
	 * Fixed-window per-IP rate limiter: 10 requests per 60 seconds.
	 *
	 * Stores the window's expiry alongside the count so incrementing the count
	 * does not slide the window forward.
	 *
	 * @return WP_Error|null WP_Error (429) when the limit is exceeded, else null.
	 */
	private function check_rate_limit(): ?WP_Error {
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rate_key = 'cfs_rate_' . md5( $ip );
		$now      = time();
		$data     = get_transient( $rate_key );

		if ( ! is_array( $data ) || ! isset( $data['count'], $data['reset'] ) || $data['reset'] <= $now ) {
			// New fixed window.
			set_transient( $rate_key, [ 'count' => 1, 'reset' => $now + 60 ], 60 );
			return null;
		}

		if ( (int) $data['count'] >= 10 ) {
			return new WP_Error(
				'rate_limit',
				__( 'Too many requests. Please wait a moment before trying again.', 'cf-summarize' ),
				[ 'status' => 429 ]
			);
		}

		// Increment while preserving the original window expiry.
		$remaining = max( 1, (int) $data['reset'] - $now );
		set_transient(
			$rate_key,
			[ 'count' => (int) $data['count'] + 1, 'reset' => (int) $data['reset'] ],
			$remaining
		);

		return null;
	}
}
