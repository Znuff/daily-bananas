<?php
/**
 * Plugin Name:       Daily Bananas
 * Plugin URI:        https://github.com/Znuff/daily-bananas
 * Description:       Auto-generates featured images for posts using Google Gemini API.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Znuff
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       daily-bananas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DAILY_BANANAS_VERSION', '1.0.1' );
define( 'DAILY_BANANAS_DIR', plugin_dir_path( __FILE__ ) );

// Load classes (logger first - others depend on it)
require_once DAILY_BANANAS_DIR . 'includes/class-logger.php';
require_once DAILY_BANANAS_DIR . 'includes/class-settings.php';
require_once DAILY_BANANAS_DIR . 'includes/class-link-extractor.php';
require_once DAILY_BANANAS_DIR . 'includes/class-image-generator.php';
require_once DAILY_BANANAS_DIR . 'includes/class-cron-handler.php';

// Initialize
Daily_Bananas_Settings::init();
Daily_Bananas_Cron_Handler::init();
