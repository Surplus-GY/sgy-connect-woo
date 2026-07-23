<?php
/**
 * Plugin bootstrap + lifecycle. Wires the admin UI, the product editor tab, the sync hooks, the webhook
 * receiver and the update checker, and owns the custom log table.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Plugin
{
    const LOG_TABLE = 'sgy_connect_logs';

    /** @var SGY_Connect_Plugin */
    private static $instance;

    /** @var SGY_Connect_Client */
    public $client;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot()
    {
        $this->client = new SGY_Connect_Client();

        // Admin surfaces (dashboard, connect, import both ways, export, logs + the product fields).
        if (is_admin()) {
            ( new SGY_Connect_Admin($this->client) )->register();
            ( new SGY_Connect_Product_Tab($this->client) )->register();
            ( new SGY_Connect_Exporter() )->register();
        }

        // Instant sync, the inbound webhook receiver, and the background bulk import all run on both admin
        // and non-admin requests (the bulk worker fires via WP-Cron / Action Scheduler, not the browser).
        ( new SGY_Connect_Sync($this->client) )->register();
        ( new SGY_Connect_Webhook() )->register();
        ( new SGY_Connect_Bulk($this->client) )->register();

        // The catalogue endpoint Surplus calls to pull this store's products (reverse import). REST route,
        // so it must register on every request, not just admin.
        ( new SGY_Connect_Catalog_Endpoint($this->client) )->register();

        // Self-updates from GitHub Releases (tag vX.Y.Z -> update offered in every vendor's WP admin).
        ( new SGY_Connect_Updater() )->register();

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('sgy', 'SGY_Connect_CLI');
        }
    }

    public static function log_table()
    {
        global $wpdb;

        return $wpdb->prefix . self::LOG_TABLE;
    }

    public static function on_activate()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::log_table();
        $charset = $wpdb->get_charset_collate();
        // A local mirror of the marketplace store_sync_logs, keyed by the same ULID correlation id so a
        // vendor can trace a failing product from here straight to the Surplus admin log.
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            direction VARCHAR(10) NOT NULL,
            action VARCHAR(60) NOT NULL,
            correlation_id VARCHAR(40) NULL,
            result VARCHAR(20) NOT NULL,
            message TEXT NULL,
            context TEXT NULL,
            PRIMARY KEY (id),
            KEY correlation_id (correlation_id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    public static function on_deactivate()
    {
        // Cancel any queued Action Scheduler sync jobs so a deactivated plugin stops pushing.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('', [], 'sgy-connect');
        }
    }
}
