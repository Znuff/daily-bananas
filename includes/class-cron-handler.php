<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daily_Bananas_Cron_Handler {

	const CRON_HOOK = 'daily_bananas_generate';

	public static function init() {
		add_action( 'transition_post_status', [ __CLASS__, 'on_post_published' ], 10, 3 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ], 10, 1 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );
		add_action( 'admin_post_daily_bananas_regenerate', [ __CLASS__, 'handle_regenerate' ] );
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

		self::schedule_generation( $post->ID );
	}

	/**
	 * Schedule a cron event for image generation and trigger it immediately.
	 */
	public static function schedule_generation( int $post_id ): void {
		// Schedule the background generation (deduplicated)
		$already_scheduled = wp_next_scheduled( self::CRON_HOOK, [ $post_id ] );
		if ( $already_scheduled ) {
			Daily_Bananas_Logger::log( "Cron already scheduled for post {$post_id} at " . gmdate( 'Y-m-d H:i:s', $already_scheduled ) );
		} else {
			$scheduled = wp_schedule_single_event( time(), self::CRON_HOOK, [ $post_id ] );
			Daily_Bananas_Logger::log(
				"Scheduled cron for post {$post_id}: " .
				( $scheduled === false ? 'FAILED' : 'OK' )
			);
		}

		// Trigger cron immediately instead of waiting for next page load
		spawn_cron();
		Daily_Bananas_Logger::log( "spawn_cron() called for post {$post_id}" );
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

	/**
	 * Register the Daily Bananas meta box on the post editor.
	 */
	public static function register_meta_box(): void {
		add_meta_box(
			'daily_bananas_status',
			'Daily Bananas',
			[ __CLASS__, 'render_meta_box' ],
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box showing generation status and regenerate button.
	 */
	public static function render_meta_box( WP_Post $post ): void {
		$status        = get_post_meta( $post->ID, '_daily_bananas_status', true );
		$attachment_id = get_post_meta( $post->ID, '_daily_bananas_attachment_id', true );
		$generated_at  = get_post_meta( $post->ID, '_daily_bananas_generated_at', true );

		// Status display
		if ( empty( $status ) ) {
			echo '<p>No image generated yet.</p>';
		} else {
			$labels = [
				'processing' => '<span style="color: #b26200;">&#9679; Processing...</span>',
				'generated'  => '<span style="color: #00a32a;">&#9679; Generated</span>',
				'failed'     => '<span style="color: #d63638;">&#9679; Failed</span>',
			];
			$label = $labels[ $status ] ?? esc_html( $status );
			echo '<p><strong>Status:</strong> ' . $label . '</p>';

			if ( $generated_at ) {
				echo '<p><strong>Generated:</strong> ' . esc_html(
					get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $generated_at ), 'Y-m-d H:i:s' )
				) . '</p>';
			}

			if ( $attachment_id && 'generated' === $status ) {
				$thumb = wp_get_attachment_image( (int) $attachment_id, [ 250, 140 ] );
				if ( $thumb ) {
					echo '<p>' . $thumb . '</p>';
				}
			}
		}

		// Regenerate button (only for published posts with an API key)
		if ( 'publish' === $post->post_status && ! empty( Daily_Bananas_Settings::get( 'api_key' ) ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=daily_bananas_regenerate&post_id=' . $post->ID ),
				'daily_bananas_regenerate_' . $post->ID
			);

			$button_label = empty( $status ) ? 'Generate Image' : 'Regenerate Image';
			$disabled     = 'processing' === $status ? ' disabled' : '';

			echo '<p><a href="' . esc_url( $url ) . '" class="button button-secondary"' . $disabled . '>' . esc_html( $button_label ) . '</a></p>';
		}
	}

	/**
	 * Handle the regenerate admin action.
	 */
	public static function handle_regenerate(): void {
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Unauthorized.' );
		}

		check_admin_referer( 'daily_bananas_regenerate_' . $post_id );

		Daily_Bananas_Logger::log( "Manual regenerate triggered for post {$post_id}" );

		// Clear existing status so cron guard allows re-run
		delete_post_meta( $post_id, '_daily_bananas_status' );
		delete_post_meta( $post_id, '_daily_bananas_attachment_id' );
		delete_post_meta( $post_id, '_daily_bananas_generated_at' );

		// Also unschedule any existing event to avoid dedup blocking
		$timestamp = wp_next_scheduled( self::CRON_HOOK, [ $post_id ] );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $post_id ] );
		}

		self::schedule_generation( $post_id );

		// Redirect back to post editor
		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}
}
