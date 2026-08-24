<?php
/**
 * Plugin Name: Quay Holidays Guesty Property Sync Optimised
 * Plugin URI: https://spotzer.com
 * Description: Targeted optimisation of the Quay Holidays Guesty Property Sync plugin: lazy availability, caching, secured webhooks, local search filters, improved checkout, dashboard stats and activity logging.
 * Version: 3.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Spotzer
 * Author URI: https://spotzer.com
 * Text Domain: guesty-properties-sync
 * Domain Path: /languages
 * License: GPL v2 or later
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Disable Batcache for AJAX requests (Pressable-safe version)
add_action('init', function() {
    $uri = strtok( isset( $_SERVER["REQUEST_URI"] ) ? $_SERVER["REQUEST_URI"] : '', '?' );

    if (
        ( defined('DOING_AJAX') && DOING_AJAX ) ||
        strpos($uri, '/wp-admin/admin-ajax.php') !== false
    ) {
        if ( function_exists('batcache_cancel') ) {
            batcache_cancel();
        }
    }
}, 0); // Priority 0 to run as early as possible

// Define plugin constants
define( 'GUESTY_PROPERTY_SYNC_VERSION', '3.5.0' );
define( 'GUESTY_PROPERTY_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GUESTY_PROPERTY_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files.
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/api/class-guesty-api.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/cache/class-guesty-availability-cache.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/cache/class-guesty-pricing-cache.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/cache/class-guesty-transient-cache.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/services/class-guesty-calendar-service.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/services/class-guesty-search-service.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/services/class-guesty-quote-service.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/optimization/class-guesty-optimization.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/class-guesty-activity-log.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/class-guesty-property-sync.php';
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/class-guesty-property-short-code.php';

/**
 * The code that runs during plugin activation.
 */
function guesty_property_sync_activate() {
    // Create the main plugin instance to access the activate method
    $plugin = new Guesty_Property_Sync();
    $plugin->activate();
    Guesty_Activity_Log::create_table();
    Guesty_Optimization::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function guesty_property_sync_deactivate() {
    // Create the main plugin instance to access the deactivate method
    $plugin = new Guesty_Property_Sync();
    $plugin->deactivate();
    Guesty_Optimization::deactivate();
}

// Activation and deactivation hooks
register_activation_hook( __FILE__, 'guesty_property_sync_activate' );
register_deactivation_hook( __FILE__, 'guesty_property_sync_deactivate' );

/**
 * Begins execution of the plugin.
 */
function run_guesty_property_sync() {
    $plugin = new Guesty_Property_Sync();
    $plugin->run();

    // Initialize optimized cache/search/quote services before shortcodes.
    $GLOBALS['guesty_optimization'] = new Guesty_Optimization();

    // Instantiate the shortcode class.
    new Guesty_Property_Short_Code();
}
run_guesty_property_sync();
