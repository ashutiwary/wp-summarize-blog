<?php
/**
 * Per-post meta box for CF-Summarize.
 *
 * Registers a sidebar meta box on the post editor that lets editors override
 * the global "Enable on All Posts by Default" setting for a single post.
 *
 * @package CF_Summarize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Meta_Box
 */
class CFS_Meta_Box {

	const META_KEY  = '_cfs_enabled';
	const NONCE_KEY = 'cfs_meta_box_nonce';
	const NONCE_ACT = 'cfs_save_meta_box';

	/**
	 * Constructor — hook into WordPress.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'save_post',      [ $this, 'save' ], 10, 2 );
	}

	/**
	 * Register the meta box on the post editor screen.
	 */
	public function register(): void {
		add_meta_box(
			'cfs-meta-box',
			__( 'CF-Summarize', 'cf-summarize' ),
			[ $this, 'render' ],
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render meta box HTML.
	 *
	 * Reads _cfs_enabled meta ('' = unset, inherit global; '1' = on; '0' = off).
	 * For unset posts, inherits cfs_enable_all so the checkbox reflects the
	 * global default without creating a hard meta record.
	 *
	 * @param WP_Post $post The current post.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACT, self::NONCE_KEY );

		$meta_value = get_post_meta( $post->ID, self::META_KEY, true );

		if ( '' === $meta_value ) {
			// Post has no explicit override — reflect the global default.
			$checked = (bool) get_option( 'cfs_enable_all', true );
		} else {
			$checked = '1' === $meta_value;
		}
		?>
		<p style="margin:8px 0 4px;">
			<label for="cfs_enabled_toggle">
				<input
					type="checkbox"
					name="cfs_enabled"
					id="cfs_enabled_toggle"
					value="1"
					<?php checked( $checked ); ?>
				/>
				<?php esc_html_e( 'Show Article Overview button on this post', 'cf-summarize' ); ?>
			</label>
		</p>
		<p class="description" style="font-size:11px;color:#646970;">
			<?php esc_html_e( 'Overrides the global "Enable on All Posts by Default" setting.', 'cf-summarize' ); ?>
		</p>
		<?php
	}

	/**
	 * Save meta box value on post save.
	 *
	 * Stores '1' (enabled) or '0' (disabled) in _cfs_enabled.
	 * Skips autosaves, revisions, capability failures, and nonce mismatches.
	 *
	 * @param int     $post_id The post ID being saved.
	 * @param WP_Post $post    The post object being saved.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		// Skip autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only handle the 'post' post type.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Verify nonce.
		if (
			! isset( $_POST[ self::NONCE_KEY ] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ self::NONCE_KEY ] ) ),
				self::NONCE_ACT
			)
		) {
			return;
		}

		// Check capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Checkbox: present in POST = checked = '1', absent = unchecked = '0'.
		$value = isset( $_POST['cfs_enabled'] ) ? '1' : '0';

		update_post_meta( $post_id, self::META_KEY, $value );
	}
}
