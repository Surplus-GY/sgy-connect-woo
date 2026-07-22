<?php

/**
 * One-click product CSV export, wrapped around WooCommerce's own exporter so the file re-imports
 * cleanly anywhere. This exists so a vendor who does not know about Woo's built-in Tools -> Export
 * can get their catalogue out with a single button inside the Surplus GY plugin.
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

class SGY_Connect_Exporter
{
    const ACTION = 'sgy_connect_export_csv';

    public function register()
    {
        // admin-post handler (a normal form POST, not AJAX, so the browser downloads the file).
        add_action('admin_post_' . self::ACTION, [$this, 'download']);
    }

    /** How many published products exist (shown on the export screen). */
    public static function product_count()
    {
        $counts = wp_count_posts('product');

        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    /**
     * Stream a full product CSV download. Uses WC_Product_CSV_Exporter so the columns match Woo's
     * native import/export format. Synchronous (one pass) which is fine for a vendor catalogue; the
     * high limit covers all but the very largest stores, who can still use Woo's batched Tools -> Export.
     */
    public function download()
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to export products.', 'sgy-connect'), 403);
        }

        check_admin_referer(self::ACTION);

        if (! class_exists('WC_Product_CSV_Exporter')) {
            $path = WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
            if (is_readable($path)) {
                include_once $path;
            }
        }

        if (! class_exists('WC_Product_CSV_Exporter')) {
            wp_die(esc_html__('The WooCommerce exporter is not available on this site.', 'sgy-connect'));
        }

        $exporter = new WC_Product_CSV_Exporter();
        $exporter->set_page(1);
        // Export everything in one pass. Woo defaults to 50/page; lift the ceiling for a one-click.
        if (method_exists($exporter, 'set_limit')) {
            $exporter->set_limit(1000000);
        }
        $exporter->set_filename('surplus-gy-products-' . gmdate('Y-m-d') . '.csv');

        SGY_Connect_Logger::log('outbound', 'export_csv', 'ok', 'exported product CSV');

        // Sends the CSV headers + body and exits.
        $exporter->export();
    }
}
