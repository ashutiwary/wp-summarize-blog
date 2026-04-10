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
 */
class CFS_Content_Extractor {

	/**
	 * Extract and clean text content from a post.
	 *
	 * Steps:
	 *  1. Run through the_content filter (handles shortcodes, page builder blocks).
	 *  2. Strip all HTML tags.
	 *  3. Decode HTML entities.
	 *  4. Normalize whitespace.
	 *  5. Truncate to configured character limit (cut at last space to avoid mid-word break).
	 *
	 * @param WP_Post $post The post to extract content from.
	 * @return string Cleaned, truncated plain text.
	 * @throws Exception If no usable content is found after extraction.
	 */
	public static function extract( WP_Post $post ): string {
		// 1. Apply the_content filters so shortcodes and builder content are rendered.
		$content = apply_filters( 'the_content', $post->post_content );

		// 2. Strip all HTML tags.
		$content = wp_strip_all_tags( $content );

		// 3. Decode HTML entities (e.g. &amp; → &, &#8217; → ').
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		// 4. Normalize whitespace — collapse tabs, newlines, multiple spaces.
		$content = (string) preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		// 5. Truncate to max_chars, breaking at the last space to avoid mid-word cuts.
		$max_chars = (int) get_option( 'cfs_max_chars', 6000 );

		if ( $max_chars > 0 && mb_strlen( $content ) > $max_chars ) {
			$truncated = mb_substr( $content, 0, $max_chars );
			$last_space = mb_strrpos( $truncated, ' ' );

			if ( false !== $last_space ) {
				$content = mb_substr( $truncated, 0, $last_space );
			} else {
				$content = $truncated;
			}
		}

		// 6. Guard against empty content.
		if ( '' === $content ) {
			throw new Exception(
				__( 'No content found to summarize.', 'cf-summarize' )
			);
		}

		return $content;
	}
}
