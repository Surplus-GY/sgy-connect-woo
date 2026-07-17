<?php
/**
 * Instant sync (STORE_CONNECT_BLUEPRINT.md §6, §9). A product save, or a stock/price/status change, queues
 * an Action Scheduler job (bundled with WooCommerce) that PATCHes the product's synced fields to Surplus.
 * Queuing (rather than a synchronous call) keeps the store admin fast and gives free retries; a nightly
 * reconcile repairs any drift. Only products already imported to Surplus (they have a _sgy_product_id) sync.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Sync
{
    const HOOK = 'sgy_connect_push_product';
    const GROUP = 'sgy-connect';

    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    public function register()
    {
        // Fire on the events that change a synced field.
        add_action('woocommerce_update_product', [$this, 'on_product_change'], 20, 1);
        add_action('woocommerce_product_set_stock', [$this, 'on_stock_object'], 20, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'on_stock_object'], 20, 1);
        add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_status'], 20, 3);

        // The queued worker.
        add_action(self::HOOK, [$this, 'push'], 10, 1);
    }

    public function on_product_change($productId)
    {
        self::queue_product_sync((int) $productId, 'product_update');
    }

    public function on_stock_object($product)
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            self::queue_product_sync((int) $id, 'stock_change');
        }
    }

    public function on_stock_status($productId, $status, $product = null)
    {
        self::queue_product_sync((int) $productId, 'stock_status');
    }

    /** Enqueue a debounced push (a single job per product; a fresh change reschedules it a few seconds out). */
    public static function queue_product_sync($productId, $reason = '')
    {
        if (! get_post_meta($productId, '_sgy_product_id', true)) {
            return; // not imported to Surplus yet
        }
        if (! function_exists('as_enqueue_async_action')) {
            // Action Scheduler missing (very old Woo): push inline as a fallback.
            ( new self(new SGY_Connect_Client()) )->push($productId);

            return;
        }
        // Debounce: if a push for this product is already pending, leave it; otherwise schedule one.
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK, ['product_id' => $productId], self::GROUP)) {
            return;
        }
        as_schedule_single_action(time() + 5, self::HOOK, ['product_id' => $productId], self::GROUP);
    }

    /** The queued worker: PATCH the product's synced fields to Surplus. */
    public function push($productId)
    {
        $productId = is_array($productId) && isset($productId['product_id']) ? (int) $productId['product_id'] : (int) $productId;
        $sgyId = get_post_meta($productId, '_sgy_product_id', true);
        if (! $sgyId) {
            return;
        }
        $product = wc_get_product($productId);
        if (! $product) {
            return;
        }

        $client = new SGY_Connect_Client();
        $payload = [
            'title'            => $product->get_name(),
            'long_description' => wp_strip_all_tags($product->get_description() ?: $product->get_short_description()),
            'price'            => $product->get_regular_price(),
            'currency'         => get_woocommerce_currency(),
            'stock'            => $product->managing_stock() ? (int) $product->get_stock_quantity() : 0,
            'status'           => ($product->get_status() === 'publish' && $product->is_visible()) ? 1 : 0,
        ];
        $sale = $product->get_sale_price();
        if ($sale !== '') {
            $payload['discounted_price'] = $sale;
        }

        // The Surplus-owned panel fields (if the vendor filled them) go under 'surplus'.
        $surplus = [];
        foreach (['category_id', 'max_length', 'max_width', 'max_height', 'country_of_manufacture', 'is_vat_inclusive', 'vat_percentage', 'is_pharma', 'product_condition'] as $key) {
            $val = get_post_meta($productId, '_sgy_' . $key, true);
            if ($val !== '') {
                $surplus[$key] = $val;
            }
        }
        if (! empty($surplus)) {
            $payload['surplus'] = $surplus;
        }

        $res = $client->patch('/products/' . rawurlencode((string) $productId), $payload);
        if ($res['ok']) {
            if (isset($res['data']['missing_fields'])) {
                update_post_meta($productId, '_sgy_missing', (array) $res['data']['missing_fields']);
            }
            SGY_Connect_Logger::log('outbound', 'sync', 'ok', 'product ' . $productId . ' synced', isset($res['data']['correlation_id']) ? $res['data']['correlation_id'] : '');
        } else {
            SGY_Connect_Logger::log('outbound', 'sync', 'error', 'product ' . $productId . ': ' . $res['error']);
            // Let Action Scheduler retry a transient failure by throwing.
            if ($res['status'] === 0 || $res['status'] >= 500) {
                throw new \Exception('sync failed, will retry: ' . $res['error']);
            }
        }
    }
}
