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
 * Activation hook — set default options if not already set.
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
		'cfs_max_chars'           => 6000,
		'cfs_cache_enabled'       => 0,
		'cfs_cache_duration'      => 86400,
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

	$button_html = sprintf(
		'<div class="cfs-wrap" data-post-id="%1$s"><button class="cfs-btn" data-post-id="%1$s" data-nonce="%2$s">%3$s</button></div>',
		esc_attr( (string) $post_id ),
		esc_attr( $nonce ),
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
}
add_action( 'wp_enqueue_scripts', 'cfs_enqueue_assets' );
