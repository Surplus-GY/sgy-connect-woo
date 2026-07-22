<?php
/**
 * Plugin Name:       Surplus GY Connect
 * Plugin URI:        https://github.com/Surplus-GY/sgy-connect-woo
 * Description:       Import your WooCommerce products into Surplus GY and keep price, stock and visibility in sync. Shows exactly which Surplus fields are still outstanding, right in your product editor.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Surplus GY
 * Author URI:        https://surplusgy.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sgy-connect
 * WC requires at least: 6.0
 *
 * The plugin is a thin client of the Surplus GY Vendor Connect API (STORE_CONNECT_BLUEPRINT.md). The key
 * prefix chooses the environment (sgy_test_ -> staging, sgy_live_ -> prod), so ONE build serves both. All
 * server rules (required fields, construction locks, currency conversion, approval) live on Surplus and are
 * surfaced here via GET /schema, so this plugin never hardcodes them.
 */

if (! defined('ABSPATH')) {
    exit; // no direct access
}

define('SGY_CONNECT_VERSION', '0.2.0');
define('SGY_CONNECT_FILE', __FILE__);
define('SGY_CONNECT_DIR', plugin_dir_path(__FILE__));
define('SGY_CONNECT_URL', plugin_dir_url(__FILE__));
define('SGY_CONNECT_BASENAME', plugin_basename(__FILE__));

// Simple PSR-4-ish autoloader for the plugin's own classes (SGY_Connect_* -> includes/class-*.php).
spl_autoload_register(function ($class) {
    if (strpos($class, 'SGY_Connect_') !== 0) {
        return;
    }
    $slug = strtolower(str_replace('_', '-', substr($class, strlen('SGY_Connect_'))));
    $path = SGY_CONNECT_DIR . 'includes/class-' . $slug . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

/**
 * Boot the plugin once all plugins are loaded, and only if WooCommerce is active (the product editor,
 * product CRUD and hooks the plugin depends on come from Woo).
 */
add_action('plugins_loaded', function () {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('Surplus GY Connect needs WooCommerce to be installed and active.', 'sgy-connect') .
                '</p></div>';
        });

        return;
    }

    SGY_Connect_Plugin::instance()->boot();
});

// Declare HPOS (custom order tables) compatibility so the plugin never blocks a modern Woo store.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SGY_CONNECT_FILE, true);
    }
});

register_activation_hook(__FILE__, ['SGY_Connect_Plugin', 'on_activate']);
register_deactivation_hook(__FILE__, ['SGY_Connect_Plugin', 'on_deactivate']);
