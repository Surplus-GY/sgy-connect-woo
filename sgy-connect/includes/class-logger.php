<?php
/**
 * The plugin's local sync log (the vendor-facing debug trail on the store side). Every row carries the
 * same ULID correlation id the marketplace uses, so a single failing product traces from here to the
 * Surplus admin log (STORE_CONNECT_BLUEPRINT.md §5, §9). Fully fail-soft: a logging failure never breaks
 * a sync.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Logger
{
    public static function log($direction, $action, $result, $message = '', $correlationId = '', $context = '')
    {
        global $wpdb;
        try {
            $wpdb->insert(SGY_Connect_Plugin::log_table(), [
                'created_at'     => current_time('mysql', true),
                'direction'      => substr((string) $direction, 0, 10),
                'action'         => substr((string) $action, 0, 60),
                'correlation_id' => substr((string) $correlationId, 0, 40),
                'result'         => substr((string) $result, 0, 20),
                'message'        => is_string($message) ? substr($message, 0, 2000) : wp_json_encode($message),
                'context'        => is_string($context) ? substr($context, 0, 2000) : wp_json_encode($context),
            ]);
        } catch (\Throwable $e) {
            // swallow: logging must never break a sync
        }
    }

    public static function recent($limit = 100)
    {
        global $wpdb;
        $limit = max(1, min(500, (int) $limit));
        $table = SGY_Connect_Plugin::log_table();

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit));
    }
}
