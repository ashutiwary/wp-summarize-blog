<?php
/**
 * Content extractor and cleaner for CF-Summarize.
 *
 * @package CF_Summarize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Content_Extractor
 *
 * Processes raw WP_Post content into clean plain text suitable for AI summarization.
 * Extracted content is stored as post meta (_cfs_extracted_content) and reused on
 * subsequent requests. Meta is cleared automatically when the post is updated.
 */
class CFS_Content_Extractor {

	/**
	 * Return clean plain text for a post, using cached post meta when available.
	 *
	 * @param WP_Post $post The post to extract content from.
	 * @return string Cleaned plain text (full article, no character limit).
	 * @throws Exception If no usable content is found after extraction.
	 */
	public static function extract( WP_Post $post ): string {
		$meta_key = '_cfs_extracted_content';

		// Return stored content if already extracted.
		$stored = get_post_meta( $post->ID, $meta_key, true );
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		// 1. Apply the_content filters so shortcodes and builder content are rendered.
		$content = apply_filters( 'the_content', $post->post_content );

		// 2. Strip all HTML tags.
		$content = wp_strip_all_tags( $content );

		// 3. Decode HTML entities (e.g. &amp; → &, &#8217; → ').
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		// 4. Normalize whitespace — collapse tabs, newlines, multiple spaces.
		$content = (string) preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		// 5. Guard against empty content.
		if ( '' === $content ) {
			throw new Exception(
				__( 'No content found to summarize.', 'cf-summarize' )
			);
		}

		// 6. Persist so future requests skip re-extraction.
		update_post_meta( $post->ID, $meta_key, $content );

		return $content;
	}
}
