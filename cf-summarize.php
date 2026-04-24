<?php
/**
 * Plugin Name: CF-Summarize
 * Plugin URI:  https://github.com/ashutiwary/wp-summarize-blog
 * Description: AI-powered article overview/summarizer for any WordPress Website.
 * Version:     1.0.0
 * Author:      Ashu Tiwary
 * Text Domain: cf-summarize
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package CF_Summarize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CFS_VERSION', '1.0.0' );
define( 'CFS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation hook - set default options if not already set.
 */
function cfs_activate(): void {
	$defaults = [
		'cfs_provider'            => 'openai',
		'cfs_openai_api_key'      => '',
		'cfs_anthropic_api_key'   => '',
		'cfs_model_openai'        => '',
		'cfs_model_anthropic'     => '',
		'cfs_button_label'        => 'Article Overview',
		'cfs_button_position'     => 'before',
		'cfs_enable_all'          => 1,
		'cfs_cache_enabled'       => 0,
		'cfs_color_bg'            => '#eef2ff',
		'cfs_color_text'          => '#374151',
		'cfs_color_title'         => '#1e1b4b',
		'cfs_color_accent'        => '#6366f1',
		'cfs_color_gradient_1'    => '#6366f1',
		'cfs_color_gradient_2'    => '#a855f7',
		'cfs_color_gradient_3'    => '#ec4899',
		'cfs_btn_bg'              => '#ffffff',
		'cfs_btn_text'            => '#1e1b4b',
		'cfs_btn_border'          => '#e5e7eb',
		'cfs_btn_shape'           => 'pill',
	];

	foreach ( $defaults as $option => $value ) {
		if ( false === get_option( $option ) ) {
			add_option( $option, $value );
		}
	}
}
register_activation_hook( __FILE__, 'cfs_activate' );

/**
 * Load plugin classes and instantiate them.
 */
function cfs_load(): void {
	require_once CFS_PLUGIN_DIR . 'includes/class-cfs-content-extractor.php';
	require_once CFS_PLUGIN_DIR . 'includes/class-cfs-ai-client.php';
	require_once CFS_PLUGIN_DIR . 'includes/class-cfs-rest-api.php';
	require_once CFS_PLUGIN_DIR . 'includes/class-cfs-settings.php';
	require_once CFS_PLUGIN_DIR . 'includes/class-cfs-meta-box.php';

	new CFS_Settings();
	new CFS_Rest_API();
	new CFS_Meta_Box();
}
add_action( 'plugins_loaded', 'cfs_load' );

/**
 * Inject the "Article Overview" button into singular post content.
 *
 * @param string $content The post content.
 * @return string Modified content with button injected.
 */
function cfs_inject_button( string $content ): string {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$post_id = get_the_ID();

	// Resolve enabled state: per-post meta overrides the global default.
	// '' = meta not yet set → inherit global; '1' = explicitly on; '0' = explicitly off.
	$meta_value = get_post_meta( $post_id, '_cfs_enabled', true );

	if ( '' === $meta_value ) {
		$enabled = (bool) get_option( 'cfs_enable_all', true );
	} else {
		$enabled = '1' === $meta_value;
	}

	if ( ! $enabled ) {
		return $content;
	}

	$nonce        = wp_create_nonce( 'cfs_summarize_nonce' );
	$button_label = esc_html( get_option( 'cfs_button_label', 'Article Overview' ) );
	$position     = get_option( 'cfs_button_position', 'before' );

	// Inline sparkle SVG with a self-contained gradient definition.
	// Two stars (big + small) compose the "AI twinkle" mark.
	$sparkle_svg = '<svg class="cfs-btn-sparkle" xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
		. '<defs>'
		. '<linearGradient id="cfs-sparkle-gradient" x1="0" y1="0" x2="1" y2="1">'
		. '<stop offset="0%" stop-color="#6366f1"/>'
		. '<stop offset="50%" stop-color="#a855f7"/>'
		. '<stop offset="100%" stop-color="#ec4899"/>'
		. '</linearGradient>'
		. '</defs>'
		. '<path class="cfs-btn-sparkle-big" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09z" fill="url(#cfs-sparkle-gradient)"/>'
		. '<path class="cfs-btn-sparkle-small" d="M18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456z" fill="url(#cfs-sparkle-gradient)"/>'
		. '</svg>';

	$button_html = sprintf(
		'<div class="cfs-wrap" data-post-id="%1$s"><button class="cfs-btn" type="button" data-post-id="%1$s" data-nonce="%2$s">%3$s<span class="cfs-btn-label">%4$s</span></button></div>',
		esc_attr( (string) $post_id ),
		esc_attr( $nonce ),
		$sparkle_svg,
		$button_label
	);

	if ( 'after' === $position ) {
		return $content . $button_html;
	}

	return $button_html . $content;
}
add_filter( 'the_content', 'cfs_inject_button', 5 );

/**
 * Enqueue frontend assets only on singular posts.
 */
function cfs_enqueue_assets(): void {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	wp_enqueue_script(
		'cf-summarize',
		CFS_PLUGIN_URL . 'assets/cf-summarize.js',
		[],
		CFS_VERSION,
		true
	);

	wp_localize_script(
		'cf-summarize',
		'cfsData',
		[
			'restUrl' => rest_url( 'cf-sum/v1/summarize' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		]
	);

	wp_enqueue_style(
		'cf-summarize',
		CFS_PLUGIN_URL . 'assets/cf-summarize.css',
		[],
		CFS_VERSION
	);

	// Inject user-configured panel colors as CSS custom properties.
	$inline = cfs_build_panel_css_vars();
	if ( '' !== $inline ) {
		wp_add_inline_style( 'cf-summarize', $inline );
	}
}
add_action( 'wp_enqueue_scripts', 'cfs_enqueue_assets' );

/**
 * Build the inline CSS that overrides the panel + button custom properties
 * from user-configured options. Returns an empty string when no valid
 * values are saved (frontend defaults from cf-summarize.css then apply).
 *
 * All vars are emitted on `.cfs-wrap` - the root wrapper - so they cascade
 * into both the button and the panel card.
 *
 * @return string CSS text (no surrounding <style> tags).
 */
function cfs_build_panel_css_vars(): string {
	$color_map = [
		'cfs_color_bg'         => '--cfs-bg',
		'cfs_color_text'       => '--cfs-text',
		'cfs_color_title'      => '--cfs-title',
		'cfs_color_accent'     => '--cfs-accent',
		'cfs_color_gradient_1' => '--cfs-stripe-1',
		'cfs_color_gradient_2' => '--cfs-stripe-2',
		'cfs_color_gradient_3' => '--cfs-stripe-3',
		'cfs_btn_bg'           => '--cfs-btn-bg',
		'cfs_btn_text'         => '--cfs-btn-text',
		'cfs_btn_border'       => '--cfs-btn-border',
	];

	$decls = '';
	foreach ( $color_map as $option => $var ) {
		$hex = sanitize_hex_color( (string) get_option( $option, '' ) );
		if ( ! $hex ) {
			continue;
		}
		$decls .= $var . ':' . $hex . ';';
	}

	$shape_radius = [
		'pill'    => '999px',
		'rounded' => '10px',
		'square'  => '4px',
	];
	$shape = (string) get_option( 'cfs_btn_shape', 'pill' );
	if ( isset( $shape_radius[ $shape ] ) ) {
		$decls .= '--cfs-btn-radius:' . $shape_radius[ $shape ] . ';';
	}

	return '' === $decls ? '' : '.cfs-wrap{' . $decls . '}';
}

/**
 * Add a "Settings" link on the Plugins list page.
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function cfs_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=cf-summarize-settings' ) ),
		esc_html__( 'Settings', 'cf-summarize' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cfs_plugin_action_links' );

/**
 * Clear stored extracted content whenever a post is saved/updated so the
 * next summarize request re-extracts from the latest post content.
 *
 * @param int $post_id The saved post ID.
 */
function cfs_clear_extracted_on_save( int $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	delete_post_meta( $post_id, '_cfs_extracted_content' );
	delete_post_meta( $post_id, '_cfs_summary' );
}
add_action( 'save_post', 'cfs_clear_extracted_on_save' );
