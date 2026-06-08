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
	 * Hard cap on the number of characters sent to the AI.
	 *
	 * Roughly ~3,000 tokens. Prevents very long posts from overflowing a
	 * model's context window or inflating per-request cost. Filterable so
	 * sites with larger-context models can raise it.
	 */
	const MAX_CHARS = 12000;

	/**
	 * Return clean plain text for a post, using cached post meta when available.
	 *
	 * @param WP_Post $post The post to extract content from.
	 * @return string Cleaned plain text, truncated to a safe length (see MAX_CHARS).
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

		// 4. Normalize whitespace - collapse tabs, newlines, multiple spaces.
		$content = (string) preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		// 5. Guard against empty content.
		if ( '' === $content ) {
			throw new Exception(
				__( 'No content found to summarize.', 'cf-summarize' )
			);
		}

		// 6. Cap length so long posts don't overflow the model context or
		//    inflate cost. Cut on a word boundary near the limit when possible.
		$max_chars = (int) apply_filters( 'cfs_max_input_chars', self::MAX_CHARS );
		$length    = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );

		if ( $max_chars > 0 && $length > $max_chars ) {
			$truncated  = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, $max_chars ) : substr( $content, 0, $max_chars );
			$last_space = strrpos( $truncated, ' ' );
			if ( false !== $last_space && $last_space > ( $max_chars * 0.8 ) ) {
				$truncated = substr( $truncated, 0, $last_space );
			}
			$content = rtrim( $truncated ) . '...';
		}

		// 7. Persist so future requests skip re-extraction.
		update_post_meta( $post->ID, $meta_key, $content );

		return $content;
	}
}
