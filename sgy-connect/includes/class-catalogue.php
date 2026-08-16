<?php

/**
 * Surplus -> WooCommerce direction. Fetches the vendor's Surplus catalogue (GET /catalogue) and
 * creates/updates WooCommerce products from it: prices converted from GYD into the store currency,
 * categories auto-created from the exact Surplus names and mapped, images sideloaded, Surplus-owned
 * fields written to the _sgy_* postmeta. Reused by both the "Import from Surplus" screen and the
 * instant product.created/updated webhook, so a browse-import and a live sync build identical products.
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

class SGY_Connect_Catalogue
{
    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch one page of the Surplus catalogue. Passes the store currency so the server returns a
     * converted price + the FX note. Returns the raw API result array (ok/status/data/error).
     */
    public function fetch($page = 1, $search = '', $category = 0, $sort = 'newest')
    {
        $query = [
            'page'     => max(1, (int) $page),
            'per_page' => 24,
            'currency' => get_woocommerce_currency(),
        ];
        if ($search !== '') {
            $query['search'] = (string) $search;
        }
        if ((int) $category > 0) {
            $query['category'] = (int) $category;
        }
        if ($sort !== '' && $sort !== 'newest') {
            $query['sort'] = (string) $sort;
        }

        $res = $this->client->get('/catalogue', $query);

        // Cache the FX rate so the instant-sync webhook (which carries GYD prices) can convert too.
        if (! empty($res['ok']) && isset($res['data']['fx_rate']) && $res['data']['fx_rate']) {
            update_option('sgy_connect_fx_rate', (float) $res['data']['fx_rate'], false);
            update_option('sgy_connect_fx_currency', get_woocommerce_currency(), false);
            update_option('sgy_connect_fx_at', time(), false);
        }

        return $res;
    }

    /**
     * The GYD -> store-currency rate (store-currency units per 1 GYD). Cached from the last catalogue
     * fetch; refreshed from the server (one tiny request) when missing, stale, or the currency changed.
     * Returns 0.0 for a GYD store (no conversion needed).
     */
    public function current_fx_rate()
    {
        $currency = get_woocommerce_currency();
        if ($currency === 'GYD') {
            return 0.0;
        }

        $cached = (float) get_option('sgy_connect_fx_rate');
        if ($cached > 0 && get_option('sgy_connect_fx_currency') === $currency && (time() - (int) get_option('sgy_connect_fx_at')) < HOUR_IN_SECONDS) {
            return $cached;
        }

        $res = $this->client->get('/catalogue', ['page' => 1, 'per_page' => 1, 'currency' => $currency]);
        if (! empty($res['ok']) && ! empty($res['data']['fx_rate'])) {
            $rate = (float) $res['data']['fx_rate'];
            update_option('sgy_connect_fx_rate', $rate, false);
            update_option('sgy_connect_fx_currency', $currency, false);
            update_option('sgy_connect_fx_at', time(), false);

            return $rate;
        }

        return $cached > 0 ? $cached : 0.0;
    }

    /**
     * Create or update a WooCommerce product from a Surplus catalogue/webhook row (the serializer shape).
     * Idempotent: matches an existing Woo product by the _sgy_product_id meta so a re-import or a webhook
     * updates in place instead of duplicating. Returns ['result'=>created|updated|error, 'woo_id', 'message'].
     *
     * @param array $row     one product from the catalogue/webhook payload
     * @param float $fxRate  store-currency units per 1 GYD (0/absent = the store is GYD, or use pre-converted)
     */
    public function import_product(array $row, $fxRate = 0.0)
    {
        $surplusId = isset($row['surplus_product_id']) ? (int) $row['surplus_product_id'] : 0;
        if ($surplusId <= 0) {
            return ['result' => 'error', 'woo_id' => 0, 'message' => 'missing surplus_product_id'];
        }

        // Surplus bundles and Custom & Engraving products have no WooCommerce equivalent the plugin can
        // build; flattening them into a simple product would corrupt the store, so they are skipped.
        if (! empty($row['is_bundle']) || ! empty($row['is_custom'])) {
            $kind = ! empty($row['is_bundle']) ? 'bundle' : 'custom';
            SGY_Connect_Logger::log('inbound', 'import', 'skipped', 'surplus #' . $surplusId . ' (' . $kind . '): unsupported_product_kind');

            return ['result' => 'skipped', 'woo_id' => 0, 'message' => 'unsupported_product_kind'];
        }

        try {
            $wooId = self::find_woo_product_by_surplus_id($surplusId);
            $isNew = ! $wooId;

            /**
             * ⚠️ A SURPLUS PRODUCT WITH OPTIONS IS BUILT AS A WOOCOMMERCE VARIABLE PRODUCT NOW.
             *
             * It used to be flattened into one WC_Product_Simple "priced from the master", which lost
             * the sizes and the colours and, far worse, did not stay lost: the flattened product still
             * got `_sgy_product_id`, so the plugin's own instant sync started firing for it, and the
             * next thing the shop typed pushed a single price and a single stock figure back at a
             * Surplus product whose real prices and stock live one row per option. The master price
             * being what the LISTING shows and the option price being what the CHECKOUT charges is how
             * "shown is not charged" gets shipped.
             */
            $wantsVariations = (int) (isset($row['product_type']) ? $row['product_type'] : 1) === 2
                && ! empty($row['variants']) && ! empty($row['attributes']);

            $product = $wooId ? wc_get_product($wooId) : null;

            // Switching an existing simple product to variable (or the reverse) needs a real class
            // change; Woo keys that off the product_type taxonomy, so set it before re-reading.
            if ($product && $wantsVariations && ! $product->is_type('variable')) {
                wp_set_object_terms($product->get_id(), 'variable', 'product_type');
                $product = wc_get_product($product->get_id());
            }

            if (! $product) {
                $product = $wantsVariations ? new WC_Product_Variable() : new WC_Product_Simple();
                $isNew = true;
            }

            $product->set_name((string) ($row['title'] ?? ''));
            if (isset($row['long_description'])) {
                $product->set_description((string) $row['long_description']);
            }
            if (! empty($row['sku'])) {
                // Only set the SKU if it is free (Woo enforces uniqueness and throws otherwise).
                $existingBySku = wc_get_product_id_by_sku((string) $row['sku']);
                if (! $existingBySku || (int) $existingBySku === (int) $product->get_id()) {
                    $product->set_sku((string) $row['sku']);
                }
            }

            /**
             * Price and stock, on the parent, for a SIMPLE product only.
             *
             * ⚠️ A VARIABLE PARENT MUST BE LEFT EMPTY. Surplus's `price` and `stock` on a product with
             * options are a derived summary (the lowest option price, the total of the option stock).
             * Writing them onto the Woo parent would make that parent look like a priced, stocked
             * product in its own right, which is what fed the parent-level figures straight back into
             * the next outbound sync. The real numbers go on the variations below.
             */
            if (! $wantsVariations) {
                // Price: use the server-converted value when present, else convert here, else the GYD figure.
                $product->set_regular_price((string) $this->store_price($row, 'price', $fxRate));
                $sale = $this->store_price($row, 'discounted_price', $fxRate);
                $product->set_sale_price($sale !== null && $sale !== '' ? (string) $sale : '');

                // Stock. Surplus sends a big sentinel for "unlimited"; treat that as not-managed/in-stock.
                $stock = isset($row['stock']) ? (int) $row['stock'] : 0;
                if ($stock >= 999990) {
                    $product->set_manage_stock(false);
                    $product->set_stock_status('instock');
                } else {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($stock);
                    $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                }
            } else {
                $product->set_manage_stock(false); // the variations count, not the parent
            }

            // Physical: Surplus dimensions are cm and weight kg -> convert into the store's units.
            if (! empty($row['max_length'])) { $product->set_length((string) $this->from_cm((float) $row['max_length'])); }
            if (! empty($row['max_width']))  { $product->set_width((string) $this->from_cm((float) $row['max_width'])); }
            if (! empty($row['max_height'])) { $product->set_height((string) $this->from_cm((float) $row['max_height'])); }
            if (! empty($row['max_weight'])) { $product->set_weight((string) $this->from_kg((float) $row['max_weight'])); }

            // Category: auto-create the Woo category from the exact Surplus name and assign it.
            if (! empty($row['category_title'])) {
                $termId = self::ensure_category((string) $row['category_title']);
                if ($termId) {
                    $product->set_category_ids([$termId]);
                }
            }

            $product->set_status('publish');
            $wooId = $product->save();

            // The options themselves, AFTER the parent has an id to attach them to. Variations are
            // updated in place rather than rebuilt, so a variation id this shop's own orders point at
            // survives, and a combination Surplus no longer sends goes out of stock instead of away.
            if ($wantsVariations) {
                $variableProduct = wc_get_product($wooId);
                if ($variableProduct && $variableProduct->is_type('variable')) {
                    $applied = SGY_Connect_Variations::apply_to_woo($variableProduct, $row, $fxRate);
                    SGY_Connect_Logger::log('inbound', 'import', 'ok', sprintf(
                        'surplus #%d options: %d added, %d updated, %d taken off sale',
                        $surplusId, $applied['created'], $applied['updated'], $applied['retired']
                    ));
                }
            }

            // Link + mirror the Surplus-owned fields to _sgy_* meta (drives the editor + gates the sync).
            update_post_meta($wooId, '_sgy_product_id', $surplusId);
            update_post_meta($wooId, '_sgy_state', $isNew ? 'imported' : 'updated');
            update_post_meta($wooId, '_sgy_source', 'surplus');
            foreach ([
                'category_id'            => 'category_id',
                'country_of_manufacture' => 'country_of_manufacture',
                'is_vat_inclusive'       => 'is_vat_inclusive',
                'vat_percentage'         => 'vat_percentage',
                'is_pharma'              => 'is_pharma',
                'product_condition'      => 'product_condition',
                'max_length'             => 'max_length',
                'max_width'              => 'max_width',
                'max_height'             => 'max_height',
            ] as $sgyKey => $rowKey) {
                if (isset($row[$rowKey]) && $row[$rowKey] !== null && $row[$rowKey] !== '') {
                    update_post_meta($wooId, '_sgy_' . $sgyKey, is_bool($row[$rowKey]) ? (int) $row[$rowKey] : $row[$rowKey]);
                }
            }

            // Images: sideload any that are not already attached (first = featured).
            if (! empty($row['images']) && is_array($row['images'])) {
                $this->sideload_images($wooId, $row['images']);
            }

            return ['result' => $isNew ? 'created' : 'updated', 'woo_id' => (int) $wooId, 'message' => ''];
        } catch (\Throwable $e) {
            return ['result' => 'error', 'woo_id' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * Import a product AND register the mapping on Surplus so instant Surplus->Woo sync fires for it
     * afterwards. Suppresses the plugin's own Woo->Surplus sync during the write (echo guard). Used by
     * the "Import from Surplus" screen.
     */
    public function import_and_link(array $row, $fxRate = 0.0)
    {
        SGY_Connect_Sync::$suppress = true;
        $result = $this->import_product($row, $fxRate);
        SGY_Connect_Sync::$suppress = false;

        if ($result['result'] !== 'error' && (int) $result['woo_id'] > 0 && ! empty($row['surplus_product_id'])) {
            $link = $this->client->post('/catalogue/link', [
                'surplus_product_id' => (int) $row['surplus_product_id'],
                'external_id'        => (string) $result['woo_id'],
            ]);
            if (empty($link['ok'])) {
                $result['message'] = trim($result['message'] . ' (imported, but sync link failed: ' . (isset($link['error']) ? $link['error'] : 'unknown') . ')');
            }
        }

        return $result;
    }

    /** Register many Surplus->Woo mappings in one call (used by the background bulk import). */
    public function link_batch(array $pairs)
    {
        $pairs = array_values(array_filter($pairs));
        if (empty($pairs)) {
            return;
        }
        $this->client->post('/catalogue/link-batch', ['links' => $pairs]);
    }

    /** The price to set in Woo: the server-converted figure if present, else convert GYD by $fxRate, else raw. */
    private function store_price(array $row, $key, $fxRate)
    {
        $convertedKey = $key . '_converted';
        if (array_key_exists($convertedKey, $row) && $row[$convertedKey] !== null) {
            return $row[$convertedKey];
        }
        if (! isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
            return $key === 'discounted_price' ? null : '';
        }
        $gyd = (float) $row[$key];
        if ($fxRate > 0 && get_woocommerce_currency() !== 'GYD') {
            return round($gyd * (float) $fxRate, 2);
        }

        return $gyd;
    }

    /** Find an existing Woo product previously imported/linked for this Surplus id. */
    public static function find_woo_product_by_surplus_id($surplusId)
    {
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'meta_key'       => '_sgy_product_id',
            'meta_value'     => (int) $surplusId,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        return $ids ? (int) $ids[0] : 0;
    }

    /** Find-or-create a WooCommerce product category by its exact name; returns the term id (or 0). */
    public static function ensure_category($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 0;
        }
        $term = get_term_by('name', $name, 'product_cat');
        if ($term && ! is_wp_error($term)) {
            return (int) $term->term_id;
        }
        $created = wp_insert_term($name, 'product_cat');
        if (is_wp_error($created)) {
            // A concurrent create may have won the race; look it up again.
            $term = get_term_by('name', $name, 'product_cat');

            return ($term && ! is_wp_error($term)) ? (int) $term->term_id : 0;
        }

        return (int) $created['term_id'];
    }

    /** Sideload image URLs onto the product (featured + gallery), skipping ones already attached by URL. */
    private function sideload_images($wooId, array $urls)
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing = (array) get_post_meta($wooId, '_sgy_image_src', true);
        $gallery = [];
        $featureSet = (bool) get_post_thumbnail_id($wooId);

        foreach (array_slice($urls, 0, 5) as $url) {
            $url = esc_url_raw($url);
            if (! $url || in_array($url, $existing, true)) {
                continue;
            }
            $attachmentId = media_sideload_image($url, $wooId, null, 'id');
            if (is_wp_error($attachmentId)) {
                continue;
            }
            $existing[] = $url;
            if (! $featureSet) {
                set_post_thumbnail($wooId, $attachmentId);
                $featureSet = true;
            } else {
                $gallery[] = $attachmentId;
            }
        }

        if ($gallery) {
            $prior = wc_get_product($wooId);
            if ($prior) {
                $ids = array_merge($prior->get_gallery_image_ids(), $gallery);
                $prior->set_gallery_image_ids(array_values(array_unique($ids)));
                $prior->save();
            }
        }
        update_post_meta($wooId, '_sgy_image_src', array_values(array_unique($existing)));
    }

    /** Surplus stores dimensions in cm; convert to the store's configured dimension unit. */
    private function from_cm($cm)
    {
        switch (get_option('woocommerce_dimension_unit')) {
            case 'mm': return round($cm * 10, 2);
            case 'm':  return round($cm / 100, 4);
            case 'in': return round($cm / 2.54, 2);
            case 'yd': return round($cm / 91.44, 4);
            default:   return round($cm, 2); // cm
        }
    }

    /** Surplus stores weight in kg; convert to the store's configured weight unit. */
    private function from_kg($kg)
    {
        switch (get_option('woocommerce_weight_unit')) {
            case 'g':   return round($kg * 1000, 0);
            case 'lbs': return round($kg / 0.453592, 3);
            case 'oz':  return round($kg / 0.0283495, 2);
            default:    return round($kg, 3); // kg
        }
    }
}
