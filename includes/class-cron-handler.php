<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daily_Bananas_Cron_Handler {

	const CRON_HOOK = 'daily_bananas_generate';

	public static function init() {
		add_action( 'transition_post_status', [ __CLASS__, 'on_post_published' ], 10, 3 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ], 10, 1 );
	}

	/**
	 * Fires when a post status transitions. Schedules image generation
	 * for posts newly published in the configured category.
	 */
	public static function on_post_published( string $new_status, string $old_status, WP_Post $post ): void {
		Daily_Bananas_Logger::log(
			"transition_post_status fired: post={$post->ID}, type={$post->post_type}, " .
			"old_status={$old_status}, new_status={$new_status}, title=\"{$post->post_title}\""
		);

		// Only on transition TO publish (not re-saves of already published posts)
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			Daily_Bananas_Logger::log(
				"SKIPPED post {$post->ID}: status transition {$old_status} -> {$new_status} " .
				"(need non-publish -> publish)"
			);
			return;
		}

		// Only for standard posts
		if ( 'post' !== $post->post_type ) {
			Daily_Bananas_Logger::log( "SKIPPED post {$post->ID}: post_type is '{$post->post_type}', not 'post'" );
			return;
		}

		// Check if post belongs to the configured category
		$category_slug = Daily_Bananas_Settings::get( 'category' );
		$has_term      = has_term( $category_slug, 'category', $post->ID );
		$post_cats     = wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] );
		$cat_slugs     = is_array( $post_cats ) ? wp_list_pluck( $post_cats, 'slug' ) : [];

		Daily_Bananas_Logger::log(
			"Category check: looking for slug '{$category_slug}', " .
			"post has categories: [" . implode( ', ', $cat_slugs ) . "], " .
			"has_term() = " . ( $has_term ? 'true' : 'false' )
		);

		if ( empty( $category_slug ) || ! $has_term ) {
			Daily_Bananas_Logger::log( "SKIPPED post {$post->ID}: not in category '{$category_slug}'" );
			return;
		}

		// Skip if no API key configured
		$api_key = Daily_Bananas_Settings::get( 'api_key' );
		if ( empty( $api_key ) ) {
			Daily_Bananas_Logger::log( 'SKIPPED: No API key configured in settings.' );
			return;
		}

		// Schedule the background generation (deduplicated)
		$already_scheduled = wp_next_scheduled( self::CRON_HOOK, [ $post->ID ] );
		if ( $already_scheduled ) {
			Daily_Bananas_Logger::log( "Cron already scheduled for post {$post->ID} at " . gmdate( 'Y-m-d H:i:s', $already_scheduled ) );
		} else {
			$scheduled = wp_schedule_single_event( time(), self::CRON_HOOK, [ $post->ID ] );
			Daily_Bananas_Logger::log(
				"Scheduled cron for post {$post->ID}: " .
				( $scheduled === false ? 'FAILED' : 'OK' )
			);
		}

		// Trigger cron immediately instead of waiting for next page load
		spawn_cron();
		Daily_Bananas_Logger::log( "spawn_cron() called for post {$post->ID}" );
	}

	/**
	 * Cron callback - runs in a separate PHP process.
	 * Generates a featured image for the given post.
	 */
	public static function run( int $post_id ): void {
		Daily_Bananas_Logger::log( "=== CRON RUN START for post {$post_id} ===" );

		// Verify the post still exists and is published
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			Daily_Bananas_Logger::log( "ABORTED: post {$post_id} not found or not published (status: " . ( $post ? $post->post_status : 'null' ) . ')' );
			return;
		}

		// Prevent duplicate execution
		$status = get_post_meta( $post_id, '_daily_bananas_status', true );
		if ( 'processing' === $status || 'generated' === $status ) {
			Daily_Bananas_Logger::log( "ABORTED: post {$post_id} already has status '{$status}' - skipping duplicate run" );
			return;
		}

		// Mark as processing
		update_post_meta( $post_id, '_daily_bananas_status', 'processing' );
		Daily_Bananas_Logger::log( "Set status to 'processing' for post {$post_id}, calling Image_Generator..." );

		$success = Daily_Bananas_Image_Generator::generate_image( $post_id );

		if ( $success ) {
			Daily_Bananas_Logger::log( "=== CRON RUN SUCCESS for post {$post_id} ===" );
		} else {
			update_post_meta( $post_id, '_daily_bananas_status', 'failed' );
			Daily_Bananas_Logger::log( "=== CRON RUN FAILED for post {$post_id} ===", 'ERROR' );
		}
	}
}
