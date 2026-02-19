<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daily_Bananas_Image_Generator {

	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	private static $mime_to_ext = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	];

	/**
	 * Generate and set a featured image for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return bool True on success, false on failure.
	 */
	public static function generate_image( int $post_id ): bool {
		$api_key = Daily_Bananas_Settings::get( 'api_key' );
		if ( empty( $api_key ) ) {
			Daily_Bananas_Logger::log( 'No API key configured.', 'ERROR' );
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			Daily_Bananas_Logger::log( "Post {$post_id} not found.", 'ERROR' );
			return false;
		}

		// Extract links from post content
		$ignored_raw    = Daily_Bananas_Settings::get( 'ignored_domains' );
		$ignored        = array_filter( array_map( 'trim', explode( "\n", $ignored_raw ) ) );
		$content_length = strlen( $post->post_content );
		Daily_Bananas_Logger::log( "Post {$post_id}: content length={$content_length}, ignored domains=[" . implode( ', ', $ignored ) . ']' );

		$urls = Daily_Bananas_Link_Extractor::extract( $post->post_content, $ignored );
		Daily_Bananas_Logger::log( 'Extracted ' . count( $urls ) . ' URLs: [' . implode( ', ', $urls ) . ']' );

		// Randomize URL order if enabled (before slicing to max)
		if ( Daily_Bananas_Settings::get( 'randomize_urls' ) === '1' && count( $urls ) > 1 ) {
			shuffle( $urls );
			Daily_Bananas_Logger::log( 'Shuffled URLs: [' . implode( ', ', $urls ) . ']' );
		}

		// Limit to configured max URLs (use whatever is available if fewer)
		$max_urls = (int) Daily_Bananas_Settings::get( 'max_urls' );
		if ( $max_urls > 0 && count( $urls ) > $max_urls ) {
			$urls = array_slice( $urls, 0, $max_urls );
			Daily_Bananas_Logger::log( "Trimmed to {$max_urls} URLs: [" . implode( ', ', $urls ) . ']' );
		}

		// Build prompt
		$prompt_template = Daily_Bananas_Settings::get( 'prompt' );
		$url_string      = implode( ', ', $urls );
		$prompt          = str_replace( '{urls}', $url_string, $prompt_template );
		Daily_Bananas_Logger::log( 'Prompt built (' . strlen( $prompt ) . ' chars): ' . substr( $prompt, 0, 200 ) . '...' );

		// Call Gemini API
		$model        = Daily_Bananas_Settings::get( 'model' );
		$aspect_ratio = Daily_Bananas_Settings::get( 'aspect_ratio' );
		Daily_Bananas_Logger::log( "Calling Gemini API: model={$model}, aspect_ratio={$aspect_ratio}" );

		$result = self::call_api( $prompt, $model, $api_key, $aspect_ratio );

		if ( is_wp_error( $result ) ) {
			Daily_Bananas_Logger::log( 'API error: ' . $result->get_error_message(), 'ERROR' );
			return false;
		}

		Daily_Bananas_Logger::log( 'API returned image: mimeType=' . $result['mimeType'] . ', size=' . strlen( $result['data'] ) . ' bytes' );

		// Upload to media library
		$attachment_id = self::upload_to_media_library(
			$post_id,
			$result['data'],
			$result['mimeType']
		);

		if ( is_wp_error( $attachment_id ) ) {
			Daily_Bananas_Logger::log( 'Upload error: ' . $attachment_id->get_error_message(), 'ERROR' );
			return false;
		}

		Daily_Bananas_Logger::log( "Uploaded to media library: attachment_id={$attachment_id}" );

		// Set as featured image (replaces any existing one)
		set_post_thumbnail( $post_id, $attachment_id );
		Daily_Bananas_Logger::log( "Featured image set for post {$post_id} -> attachment {$attachment_id}" );

		// Record success metadata
		update_post_meta( $post_id, '_daily_bananas_status', 'generated' );
		update_post_meta( $post_id, '_daily_bananas_attachment_id', $attachment_id );
		update_post_meta( $post_id, '_daily_bananas_generated_at', time() );

		return true;
	}

	/**
	 * Call the Gemini generateContent API.
	 *
	 * @return array{data: string, mimeType: string}|WP_Error
	 */
	private static function call_api( string $prompt, string $model, string $api_key, string $aspect_ratio ) {
		$url = self::API_BASE . rawurlencode( $model ) . ':generateContent';

		$body = [
			'contents'         => [
				[
					'parts' => [
						[ 'text' => $prompt ],
					],
				],
			],
			'generationConfig' => [
				'responseModalities' => [ 'TEXT', 'IMAGE' ],
				'imageConfig'        => [
					'aspectRatio' => $aspect_ratio,
				],
			],
			'tools'            => [
				[ 'googleSearch' => new \stdClass() ],
			],
		];

		$json_body = wp_json_encode( $body );
		Daily_Bananas_Logger::log( "API request URL: {$url}" );
		Daily_Bananas_Logger::log( "API request body (" . strlen( $json_body ) . " bytes): " . substr( $json_body, 0, 500 ) . '...' );

		$start_time = microtime( true );

		$response = wp_remote_post( $url, [
			'timeout' => 120,
			'headers' => [
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			],
			'body'    => $json_body,
		] );

		$elapsed = round( microtime( true ) - $start_time, 2 );

		if ( is_wp_error( $response ) ) {
			Daily_Bananas_Logger::log( "API request failed after {$elapsed}s: " . $response->get_error_message(), 'ERROR' );
			return $response;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		Daily_Bananas_Logger::log( "API response: HTTP {$status_code}, {$elapsed}s elapsed, body length=" . strlen( $response_body ) );

		if ( $status_code < 200 || $status_code >= 300 ) {
			Daily_Bananas_Logger::log( 'API error response: ' . substr( $response_body, 0, 1000 ), 'ERROR' );
			return new WP_Error(
				'api_http_error',
				"Gemini API returned HTTP {$status_code}: " . substr( $response_body, 0, 500 )
			);
		}

		$decoded = json_decode( $response_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			Daily_Bananas_Logger::log( 'JSON decode failed: ' . json_last_error_msg(), 'ERROR' );
			return new WP_Error( 'api_json_error', 'Failed to decode Gemini response: ' . json_last_error_msg() );
		}

		// Find the image part in the response
		$parts = $decoded['candidates'][0]['content']['parts'] ?? [];
		Daily_Bananas_Logger::log( 'Response has ' . count( $parts ) . ' parts' );

		foreach ( $parts as $i => $part ) {
			if ( isset( $part['text'] ) ) {
				Daily_Bananas_Logger::log( "Part {$i}: text (" . strlen( $part['text'] ) . ' chars)' );
			}
			if ( isset( $part['inlineData']['data'] ) ) {
				$mime = $part['inlineData']['mimeType'] ?? 'unknown';
				Daily_Bananas_Logger::log( "Part {$i}: image ({$mime}, " . strlen( $part['inlineData']['data'] ) . ' base64 chars)' );

				$image_data = base64_decode( $part['inlineData']['data'] );
				if ( $image_data === false ) {
					return new WP_Error( 'api_decode_error', 'Failed to decode base64 image data.' );
				}
				return [
					'data'     => $image_data,
					'mimeType' => $part['inlineData']['mimeType'] ?? 'image/png',
				];
			}
		}

		// Log the full response structure if no image was found (for debugging)
		Daily_Bananas_Logger::log( 'No image in response. Full response keys: ' . wp_json_encode( array_keys( $decoded ) ), 'ERROR' );
		if ( isset( $decoded['candidates'][0] ) ) {
			$candidate = $decoded['candidates'][0];
			Daily_Bananas_Logger::log( 'Candidate keys: ' . wp_json_encode( array_keys( $candidate ) ), 'ERROR' );
			if ( isset( $candidate['finishReason'] ) ) {
				Daily_Bananas_Logger::log( 'finishReason: ' . $candidate['finishReason'], 'ERROR' );
			}
			if ( isset( $candidate['finishMessage'] ) ) {
				Daily_Bananas_Logger::log( 'finishMessage: ' . $candidate['finishMessage'], 'ERROR' );
			}
			if ( isset( $candidate['content'] ) ) {
				Daily_Bananas_Logger::log( 'content keys: ' . wp_json_encode( array_keys( $candidate['content'] ) ), 'ERROR' );
			}
		}
		if ( isset( $decoded['promptFeedback'] ) ) {
			Daily_Bananas_Logger::log( 'promptFeedback: ' . wp_json_encode( $decoded['promptFeedback'] ), 'ERROR' );
		}

		return new WP_Error( 'api_no_image', 'Gemini response contained no image data.' );
	}

	/**
	 * Upload raw image data to the WordPress media library.
	 *
	 * @return int|WP_Error Attachment ID on success.
	 */
	private static function upload_to_media_library( int $post_id, string $image_data, string $mime_type ) {
		$ext             = self::$mime_to_ext[ $mime_type ] ?? 'png';
		$filename_tpl    = Daily_Bananas_Settings::get( 'filename' );
		if ( empty( $filename_tpl ) ) {
			$filename_tpl = 'stirile_zilei_{date}';
		}
		$filename_base   = str_replace(
			[ '{date}', '{post_id}', '{timestamp}' ],
			[ gmdate( 'Y-m-d' ), $post_id, time() ],
			$filename_tpl
		);
		$filename = sanitize_file_name( $filename_base ) . '.' . $ext;

		Daily_Bananas_Logger::log( "Uploading: filename={$filename}, mime={$mime_type}, size=" . strlen( $image_data ) . ' bytes' );

		$upload = wp_upload_bits( $filename, null, $image_data );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		Daily_Bananas_Logger::log( "File written to: {$upload['file']}" );

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment = [
			'post_mime_type' => $mime_type,
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $post_id, true );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}
}
