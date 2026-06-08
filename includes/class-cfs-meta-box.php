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
	 * Constructor - hook into WordPress.
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
	 * Reads _cfs_enabled meta ('' = inherit global; '1' = force on; '0' = force off)
	 * and renders a tri-state select. Choosing "Default" deletes the override so
	 * the post follows the global "Enable on All Posts" setting going forward.
	 *
	 * @param WP_Post $post The current post.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACT, self::NONCE_KEY );

		$meta_value    = get_post_meta( $post->ID, self::META_KEY, true );
		$current       = in_array( $meta_value, [ '0', '1' ], true ) ? $meta_value : 'default';
		$global_on     = (bool) get_option( 'cfs_enable_all', true );
		$global_label  = $global_on
			? __( 'Default (inherit global: On)', 'cf-summarize' )
			: __( 'Default (inherit global: Off)', 'cf-summarize' );
		?>
		<p style="margin:8px 0 4px;">
			<label for="cfs_enabled_select" style="display:block;margin-bottom:4px;font-weight:600;">
				<?php esc_html_e( 'Article Overview button', 'cf-summarize' ); ?>
			</label>
			<select name="cfs_enabled" id="cfs_enabled_select" style="width:100%;">
				<option value="default" <?php selected( $current, 'default' ); ?>><?php echo esc_html( $global_label ); ?></option>
				<option value="1" <?php selected( $current, '1' ); ?>><?php esc_html_e( 'Enable on this post', 'cf-summarize' ); ?></option>
				<option value="0" <?php selected( $current, '0' ); ?>><?php esc_html_e( 'Disable on this post', 'cf-summarize' ); ?></option>
			</select>
		</p>
		<p class="description" style="font-size:11px;color:#646970;">
			<?php esc_html_e( 'Overrides the global "Enable on All Posts by Default" setting. Choose Default to follow the global setting.', 'cf-summarize' ); ?>
		</p>
		<?php
	}

	/**
	 * Save meta box value on post save.
	 *
	 * 'default' deletes the override so the post inherits the global setting;
	 * '1'/'0' store a hard force-on/force-off. Skips autosaves, revisions,
	 * capability failures, and nonce mismatches.
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

		// Tri-state select: 'default' clears the override (inherit global);
		// '1'/'0' store an explicit force-on/force-off.
		$choice = isset( $_POST['cfs_enabled'] )
			? sanitize_text_field( wp_unslash( $_POST['cfs_enabled'] ) )
			: 'default';

		if ( '1' === $choice || '0' === $choice ) {
			update_post_meta( $post_id, self::META_KEY, $choice );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}
}
