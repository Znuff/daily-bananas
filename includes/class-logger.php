<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daily_Bananas_Logger {

	private static $log_file = null;

	/**
	 * Get the log file path (in wp-content/uploads/ so it's writable).
	 */
	public static function get_log_file(): string {
		if ( self::$log_file === null ) {
			$upload_dir    = wp_upload_dir();
			self::$log_file = $upload_dir['basedir'] . '/daily-bananas-debug.log';
		}
		return self::$log_file;
	}

	/**
	 * Check if debug logging is enabled.
	 */
	public static function is_enabled(): bool {
		return Daily_Bananas_Settings::get( 'debug' ) === '1';
	}

	/**
	 * Write a log entry. Always writes to our log file when debug is on,
	 * and also to error_log for errors.
	 */
	public static function log( string $message, string $level = 'INFO' ): void {
		if ( ! self::is_enabled() && $level !== 'ERROR' ) {
			return;
		}

		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$entry     = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

		// Always write to our plugin log file
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( self::get_log_file(), $entry, FILE_APPEND | LOCK_EX );

		// Also send errors to PHP error_log
		if ( $level === 'ERROR' ) {
			error_log( 'Daily Bananas: ' . $message );
		}
	}

	/**
	 * Read the last N lines of the log file.
	 */
	public static function get_recent_lines( int $lines = 100 ): string {
		$file = self::get_log_file();
		if ( ! file_exists( $file ) ) {
			return '(no log file yet - publish a post to generate entries)';
		}

		$content = file_get_contents( $file );
		if ( empty( $content ) ) {
			return '(log file is empty)';
		}

		$all_lines = explode( "\n", trim( $content ) );
		$recent    = array_slice( $all_lines, -$lines );
		return implode( "\n", $recent );
	}

	/**
	 * Clear the log file.
	 */
	public static function clear(): void {
		$file = self::get_log_file();
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, '' );
		}
	}
}
