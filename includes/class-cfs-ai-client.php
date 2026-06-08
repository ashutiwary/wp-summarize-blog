<?php
/**
 * AI provider abstraction for CF-Summarize.
 *
 * @package CF_Summarize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_AI_Client
 *
 * Handles communication with OpenAI and Anthropic APIs to generate article summaries.
 */
class CFS_AI_Client {

	/**
	 * System prompt instructing the AI to return structured JSON.
	 *
	 * @return string
	 */
	private function system_prompt(): string {
		return 'You are a helpful assistant. Analyze the article and respond with ONLY a valid JSON object - no markdown fences, no extra text. Use this exact schema: {"key_points":["point 1","point 2",...],"conclusion":"conclusion text"}. Extract maximum 5 key points as the content warrants - include every distinct, meaningful point without artificially limiting or padding the count. Each key point should be a short, informative sentence. Write a conclusion that is as long as the content requires to accurately summarize the article\'s overall message.';
	}

	/**
	 * Strip markdown code fences and parse the JSON returned by the AI.
	 *
	 * @param string $raw Raw text from the AI.
	 * @return array{key_points: string[], conclusion: string}
	 * @throws Exception If JSON is missing or malformed.
	 */
	private function parse_structured( string $raw ): array {
		// Strip optional ```json ... ``` fences some models add.
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
		$raw = (string) preg_replace( '/\s*```$/', '', trim( $raw ) );

		$decoded = json_decode( trim( $raw ), true );

		if (
			! is_array( $decoded ) ||
			! isset( $decoded['key_points'], $decoded['conclusion'] ) ||
			! is_array( $decoded['key_points'] )
		) {
			throw new Exception(
				__( 'AI returned an unexpected response format. Please try again.', 'cf-summarize' )
			);
		}

		// Guard against the model returning nested arrays/objects for points or
		// the conclusion. Casting those to string would emit a PHP warning and
		// yield the literal "Array", so keep only scalar values.
		$scalar_points = array_filter( $decoded['key_points'], 'is_scalar' );
		$conclusion    = is_scalar( $decoded['conclusion'] ) ? (string) $decoded['conclusion'] : '';

		return [
			'key_points' => array_values(
				array_filter( array_map( 'strval', $scalar_points ) )
			),
			'conclusion' => trim( $conclusion ),
		];
	}

	/**
	 * Generate a structured summary for the given text using the configured AI provider.
	 *
	 * @param string $text Cleaned article text to summarize.
	 * @return array{key_points: string[], conclusion: string}
	 * @throws Exception On configuration errors, HTTP errors, or unexpected API responses.
	 */
	public function summarize( string $text ): array {
		$provider = get_option( 'cfs_provider', 'openai' );

		// Use switch (not match) so the plugin keeps working on PHP 7.4,
		// which WordPress still supports; match is PHP 8.0+ only.
		switch ( $provider ) {
			case 'anthropic':
				$raw = $this->summarize_anthropic( $text );
				break;
			default:
				$raw = $this->summarize_openai( $text );
				break;
		}

		return $this->parse_structured( $raw );
	}

	/**
	 * Call the OpenAI Chat Completions API.
	 *
	 * @param string $text Article text.
	 * @return string Raw JSON text from OpenAI.
	 * @throws Exception On missing key, HTTP errors, or malformed response.
	 */
	private function summarize_openai( string $text ): string {
		$api_key = get_option( 'cfs_openai_api_key', '' );

		if ( empty( $api_key ) ) {
			throw new Exception(
				__( 'API key is not configured. Please set it in CF-Summarize Settings.', 'cf-summarize' )
			);
		}

		$model = (string) get_option( 'cfs_model_openai', '' );

		if ( empty( $model ) ) {
			throw new Exception(
				__( 'OpenAI model is not configured. Please set it in CF-Summarize Settings.', 'cf-summarize' )
			);
		}

		$body = wp_json_encode(
			[
				'model'           => $model,
				'max_tokens'      => 1500,
				// Force strictly-parseable JSON so the prompt's schema is honoured
				// even if the model would otherwise wrap output in prose/fences.
				'response_format' => [ 'type' => 'json_object' ],
				'messages'        => [
					[
						'role'    => 'system',
						'content' => $this->system_prompt(),
					],
					[
						'role'    => 'user',
						'content' => $text,
					],
				],
			]
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => $body,
			]
		);

		return $this->parse_openai_response( $response );
	}

	/**
	 * Parse an OpenAI API response.
	 *
	 * @param array<string,mixed>|WP_Error $response wp_remote_post response.
	 * @return string Extracted summary text.
	 * @throws Exception On HTTP errors or unexpected response structure.
	 */
	private function parse_openai_response( $response ): string {
		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$decoded       = json_decode( $raw_body, true );

		if ( 200 !== (int) $response_code ) {
			$error_msg = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: $raw_body;

			/* translators: 1: HTTP status code, 2: API error message */
			throw new Exception(
				sprintf(
					__( 'AI API returned error: %1$s - %2$s', 'cf-summarize' ),
					$response_code,
					$error_msg
				)
			);
		}

		if ( empty( $decoded['choices'][0]['message']['content'] ) ) {
			throw new Exception(
				__( 'Unexpected response structure from OpenAI API.', 'cf-summarize' )
			);
		}

		return trim( (string) $decoded['choices'][0]['message']['content'] );
	}

	/**
	 * Call the Anthropic Messages API.
	 *
	 * @param string $text Article text.
	 * @return string Raw JSON text from Anthropic.
	 * @throws Exception On missing key, HTTP errors, or malformed response.
	 */
	private function summarize_anthropic( string $text ): string {
		$api_key = get_option( 'cfs_anthropic_api_key', '' );

		if ( empty( $api_key ) ) {
			throw new Exception(
				__( 'API key is not configured. Please set it in CF-Summarize Settings.', 'cf-summarize' )
			);
		}

		$model = (string) get_option( 'cfs_model_anthropic', '' );

		if ( empty( $model ) ) {
			throw new Exception(
				__( 'Anthropic model is not configured. Please set it in CF-Summarize Settings.', 'cf-summarize' )
			);
		}

		$body = wp_json_encode(
			[
				'model'      => $model,
				'max_tokens' => 1500,
				'system'     => $this->system_prompt(),
				'messages'   => [
					[
						'role'    => 'user',
						'content' => $text,
					],
				],
			]
		);

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			[
				'timeout' => 30,
				'headers' => [
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				],
				'body'    => $body,
			]
		);

		return $this->parse_anthropic_response( $response );
	}

	/**
	 * Parse an Anthropic API response.
	 *
	 * @param array<string,mixed>|WP_Error $response wp_remote_post response.
	 * @return string Extracted summary text.
	 * @throws Exception On HTTP errors or unexpected response structure.
	 */
	private function parse_anthropic_response( $response ): string {
		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$decoded       = json_decode( $raw_body, true );

		if ( 200 !== (int) $response_code ) {
			$error_msg = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: $raw_body;

			/* translators: 1: HTTP status code, 2: API error message */
			throw new Exception(
				sprintf(
					__( 'AI API returned error: %1$s - %2$s', 'cf-summarize' ),
					$response_code,
					$error_msg
				)
			);
		}

		if ( empty( $decoded['content'][0]['text'] ) ) {
			throw new Exception(
				__( 'Unexpected response structure from Anthropic API.', 'cf-summarize' )
			);
		}

		return trim( (string) $decoded['content'][0]['text'] );
	}
}
