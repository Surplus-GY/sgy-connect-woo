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

    /** When true, a Woo->Surplus sync is suppressed — set while an inbound Surplus->Woo import/webhook
     *  is writing the product, so a change that came FROM Surplus is not pushed straight back to it. */
    public static $suppress = false;

    public static function without_push($callback)
    {
        $previous = self::$suppress;
        self::$suppress = true;

        try {
            return call_user_func($callback);
        } finally {
            self::$suppress = $previous;
        }
    }

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
        if (self::$suppress) {
            return; // inbound Surplus->Woo write in progress; do not echo it back to Surplus
        }
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

    const MAX_ATTEMPTS = 5;

    /** The queued worker: PATCH the product's synced fields to Surplus. */
    public function push($productId, $attempt = 1)
    {
        $attempt = is_array($productId) && isset($productId['attempt']) ? (int) $productId['attempt'] : (int) $attempt;
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
            'currency'         => get_woocommerce_currency(),
            // Surplus listing on/off = the product is PUBLISHED. Do NOT use is_visible(): a catalogue-hidden
            // (search-only) product is still for sale and must not be unpublished on Surplus.
            'status'           => $product->get_status() === 'publish' ? 1 : 0,
        ];

        /**
         * ⚠️ A VARIABLE PRODUCT SENDS ITS VARIATIONS, AND SENDS NO PARENT PRICE OR STOCK AT ALL.
         *
         * This is the line that was doing real damage. A variable parent carries no `_regular_price`
         * and, unless the shop counts stock at parent level, `managing_stock()` is false, so the two
         * expressions below evaluated to `''` and to 999998, the plugin's own "unlimited" sentinel.
         * Surplus took the sentinel as the product's stock. Reproduced against the real endpoint: a
         * 5/3/2 product's master stock became 1,000,008, a warehouse row appeared holding 999,998
         * against no option, the three real options did not move, and the response was a plain
         * {"ok":true} that this log recorded as a successful sync.
         *
         * The variations carry the numbers that actually exist. `variations_complete` says the list is
         * the whole product, which is what lets Surplus take a variation the shop deleted off sale;
         * only a full read like this one may claim it.
         */
        if (SGY_Connect_Variations::is_variable($product)) {
            $built = SGY_Connect_Variations::variations_of($product);
            if (empty($built['variations'])) {
                SGY_Connect_Logger::log('outbound', 'sync', 'skipped', 'product ' . $productId . ' has options but none could be read (a variation may be set to "Any"); nothing was sent');

                return;
            }
            $payload['variations'] = $built['variations'];
            $payload['variations_complete'] = $built['skipped'] === 0;
            $payload['attributes'] = SGY_Connect_Variations::attributes_of($product);
        } else {
            // A non-stock-managed product is unlimited: send a large in-stock quantity, not a literal 0.
            $payload['stock'] = $product->managing_stock() ? (int) $product->get_stock_quantity() : ($product->get_stock_status() === 'outofstock' ? 0 : 999998);
            $regular = (string) $product->get_regular_price();
            $sale = (string) $product->get_sale_price();
            $baselineRegular = get_post_meta($productId, '_sgy_import_price_store', true);
            $baselineSale = get_post_meta($productId, '_sgy_import_sale_store', true);

            if (! metadata_exists('post', $productId, '_sgy_import_price_store') || $regular !== (string) $baselineRegular) {
                $payload['price'] = $regular;
            }
            if (! metadata_exists('post', $productId, '_sgy_import_sale_store') || $sale !== (string) $baselineSale) {
                $payload['discounted_price'] = $sale !== '' ? $sale : null;
            }
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
            if (SGY_Connect_Variations::is_variable($product)) {
                SGY_Connect_Variations::remember_synced_prices($product);
            } else {
                update_post_meta($productId, '_sgy_import_price_store', (string) $product->get_regular_price());
                update_post_meta($productId, '_sgy_import_sale_store', (string) $product->get_sale_price());
            }
            SGY_Connect_Logger::log('outbound', 'sync', 'ok', 'product ' . $productId . ' synced', isset($res['data']['correlation_id']) ? $res['data']['correlation_id'] : '');
        } else {
            SGY_Connect_Logger::log('outbound', 'sync', 'error', 'product ' . $productId . ': ' . $res['error'] . ' (attempt ' . $attempt . ')');
            // A transient failure (network / 5xx) is retried by EXPLICITLY rescheduling with backoff:
            // Action Scheduler does NOT auto-retry a scheduled single action on an exception, so throwing
            // would just mark it failed and drop the update. A 4xx is a permanent client error: do not retry.
            if (($res['status'] === 0 || $res['status'] >= 500) && $attempt < self::MAX_ATTEMPTS && function_exists('as_schedule_single_action')) {
                $delays = [30, 120, 600, 1800];
                as_schedule_single_action(
                    time() + ($delays[$attempt - 1] ?? 1800),
                    self::HOOK,
                    ['product_id' => $productId, 'attempt' => $attempt + 1],
                    self::GROUP
                );
            }
        }
    }
}
