<?php
/**
 * Maps WooCommerce products to the Surplus /products/import payload and pushes them in batches. Images
 * go as public URLs (Surplus sideloads them). The Surplus-owned fields (dimensions, VAT, condition,
 * country, is_pharma) come from the plugin's own _sgy_* postmeta if the vendor filled the Surplus GY tab;
 * everything else comes from Woo. Simple products only in the MVP (variable products are P5).
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Importer
{
    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    /** How many published SIMPLE products exist (only these import; drives the wizard's progress bar). */
    public function total()
    {
        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'tax_query'      => [[ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'simple' ]],
        ]);

        return (int) $q->found_posts;
    }

    /**
     * Import one page. Returns ['done'=>bool,'offset'=>int,'total'=>int,'sent'=>int,'results'=>[...]].
     */
    public function import_batch($offset, $limit)
    {
        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $ids = $query->posts;
        if (empty($ids)) {
            return ['done' => true, 'offset' => $offset, 'total' => $this->total(), 'sent' => 0, 'results' => []];
        }

        $products = [];
        foreach ($ids as $id) {
            $payload = $this->to_payload($id);
            if ($payload !== null) {
                $products[] = $payload;
            }
        }

        $results = [];
        if (! empty($products)) {
            $res = $this->client->post('/products/import', ['products' => $products]);
            if ($res['ok'] && isset($res['data']['results'])) {
                $results = $res['data']['results'];
                $this->store_results($results);
                SGY_Connect_Logger::log('outbound', 'import', 'ok', 'imported ' . count($results) . ' products', isset($res['data']['correlation_id']) ? $res['data']['correlation_id'] : '');
            } else {
                SGY_Connect_Logger::log('outbound', 'import', 'error', $res['error']);
                $results = [['result' => 'error', 'errors' => [$res['error']]]];
            }
        }

        return [
            'done'    => count($ids) < $limit,
            'offset'  => $offset + count($ids),
            'total'   => $this->total(),
            'sent'    => count($products),
            'results' => $results,
        ];
    }

    /** Build the import payload for one Woo product, or null if it is not a simple product. */
    public function to_payload($productId)
    {
        $product = wc_get_product($productId);
        if (! $product || ! $product->is_type('simple')) {
            return null; // MVP: simple products only
        }

        $images = [];
        $mainId = $product->get_image_id();
        if ($mainId) {
            $url = wp_get_attachment_image_url($mainId, 'full');
            if ($url) {
                $images[] = $url;
            }
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $url = wp_get_attachment_image_url($gid, 'full');
            if ($url && count($images) < 5) {
                $images[] = $url;
            }
        }

        $payload = [
            'external_id'      => (string) $productId,
            'title'            => $product->get_name(),
            'long_description' => wp_strip_all_tags($product->get_description() ?: $product->get_short_description()),
            'sku'              => $product->get_sku(),
            'price'            => $product->get_regular_price(),
            'currency'         => get_woocommerce_currency(),
            'discounted_price' => $product->get_sale_price() ?: null,
            'stock'            => $this->stock_of($product),
            'brand'            => $this->brand_of($product),
            // get_global_unique_id() only exists from WooCommerce 9.2; fall back to the meta on older Woo.
            // Sanitised below: Surplus now refuses a barcode that is not a real GTIN, and losing the
            // barcode is far better than losing the whole product.
            'upc'              => $this->clean_gtin(
                method_exists($product, 'get_global_unique_id') ? $product->get_global_unique_id() : (string) $product->get_meta('_global_unique_id'),
                $productId
            ),
            'max_weight'       => $this->to_pounds($product->get_weight()),
            'source_category'  => $this->primary_category_slug($productId),
            'images'           => $images,
        ];

        // Surplus-owned fields the vendor filled on the Surplus GY tab (postmeta), if any.
        foreach (['max_length', 'max_width', 'max_height', 'country_of_manufacture', 'is_vat_inclusive', 'vat_percentage', 'is_pharma', 'product_condition', 'category_id'] as $key) {
            $val = get_post_meta($productId, '_sgy_' . $key, true);
            if ($val !== '') {
                $payload[$key] = $val;
            }
        }
        // Woo native dimensions fill the Surplus dimensions when the vendor has not overridden them.
        foreach (['length' => 'max_length', 'width' => 'max_width', 'height' => 'max_height'] as $wooDim => $sgyDim) {
            if (empty($payload[$sgyDim])) {
                $dim = $wooDim === 'length' ? $product->get_length() : ($wooDim === 'width' ? $product->get_width() : $product->get_height());
                if ($dim !== '' && (float) $dim > 0) {
                    $payload[$sgyDim] = $this->to_inches((float) $dim);
                }
            }
        }

        return $payload;
    }

    private function store_results(array $results)
    {
        foreach ($results as $r) {
            if (empty($r['external_id'])) {
                continue;
            }
            $pid = (int) $r['external_id'];
            update_post_meta($pid, '_sgy_product_id', isset($r['product_id']) ? (int) $r['product_id'] : '');
            update_post_meta($pid, '_sgy_state', isset($r['result']) ? $r['result'] : '');
            update_post_meta($pid, '_sgy_missing', isset($r['missing_fields']) ? (array) $r['missing_fields'] : []);
            update_post_meta($pid, '_sgy_approval', isset($r['approval']) ? $r['approval'] : '');
        }
    }

    /**
     * A barcode Surplus will accept, or nothing at all.
     *
     * Surplus validates `upc` as a real GTIN: EAN-8, UPC-A or EAN-13, digits only, with a correct
     * mod-10 check digit. It used to accept any string up to 15 characters, which meant a mistyped
     * digit silently grouped two different products together on the marketplace, so the rule was
     * tightened. See App\Rules\ValidGtin on the Surplus side; the /schema endpoint states the same
     * rule and is the source of truth.
     *
     * WooCommerce's GTIN field is free text and shops legitimately put ISBNs, internal references and
     * dashed codes in it. If one of those were forwarded unchanged, Surplus would refuse the WHOLE
     * product and the shop would lose a listing over a field it does not care about. So an invalid
     * value is dropped and recorded, never passed on: the product still syncs, and the log says which
     * one lost its barcode and why.
     *
     * Deliberately does NOT repair the value. It does not strip dashes or pad lengths, because the
     * printed, stored and scanned barcode have to stay identical character for character, and a
     * "helpful" rewrite here would desync labels already on the stock.
     *
     * @param  string  $raw        whatever WooCommerce holds
     * @param  int     $productId  only used so the log names the product
     * @return string  the barcode, or '' when it is not one
     */
    private function clean_gtin($raw, $productId)
    {
        $code = trim((string) $raw);

        if ($code === '') {
            return '';
        }

        if ($this->is_valid_gtin($code)) {
            return $code;
        }

        SGY_Connect_Logger::log(
            'outbound',
            'import',
            'warning',
            sprintf(
                'Product %d: barcode "%s" is not a valid UPC or EAN, so it was left out of the sync. The product itself synced normally. Fix it in the product\'s Inventory tab if you want it on Surplus.',
                (int) $productId,
                $code
            )
        );

        return '';
    }

    /**
     * The GTIN mod-10 check digit. One rule covers EAN-8, UPC-A and EAN-13: drop the check digit, walk
     * the rest from the RIGHT and weight alternately 3, 1, 3, 1. Reading right to left is what makes it
     * length-independent. This mirrors App\Support\ProductBarcode::checkDigitValid exactly, so a code
     * that passes here is a code Surplus will accept.
     */
    private function is_valid_gtin($code)
    {
        if (! ctype_digit($code) || ! in_array(strlen($code), array(8, 12, 13), true)) {
            return false;
        }

        $digits = str_split($code);
        $check = (int) array_pop($digits);

        $sum = 0;
        $weight = 3;
        foreach (array_reverse($digits) as $digit) {
            $sum += ((int) $digit) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return ((10 - ($sum % 10)) % 10) === $check;
    }

    /**
     * The stock to send Surplus. A product that does NOT manage stock is deliberately unlimited: send a
     * large in-stock quantity (or 0 only if its status is out of stock) rather than a literal 0, which
     * would wrongly make it unbuyable on Surplus.
     */
    private function stock_of($product)
    {
        if ($product->managing_stock()) {
            return (int) $product->get_stock_quantity();
        }

        return $product->get_stock_status() === 'outofstock' ? 0 : 999998; // Surplus's max stock = "unlimited"
    }

    private function brand_of($product)
    {
        // Common Woo brand taxonomies, first match wins.
        foreach (['product_brand', 'pwb-brand', 'yith_product_brand'] as $tax) {
            if (taxonomy_exists($tax)) {
                $terms = wp_get_post_terms($product->get_id(), $tax, ['fields' => 'names']);
                if (! is_wp_error($terms) && ! empty($terms)) {
                    return $terms[0];
                }
            }
        }

        return '';
    }

    private function primary_category_slug($productId)
    {
        $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'slugs']);

        return (! is_wp_error($terms) && ! empty($terms)) ? $terms[0] : '';
    }

    /**
     * Woo weight, in whatever unit the store is set to, converted to POUNDS for Surplus.
     *
     * ⚠️ SURPLUS IS IMPERIAL, AND THIS USED TO CONVERT TO KILOGRAMS. `products.max_weight` is pounds:
     * the Surplus product form asks for "Product Packing Weight (in pounds)" and refuses anything
     * under 1 with "minimum value should be 1 pounds", the public product page prints "(in pounds)",
     * and the delivery engine multiplies the stored figure by 0.45359237 to reach kilograms before it
     * prices anything. Sending kilograms put a number 2.2 times too small in a column the delivery
     * charge is computed from, and it also parked most of the catalogue permanently below the
     * "at least 1" floor, so an ordinary 700 g item read as 0.7 and never stopped showing "Weight
     * outstanding" no matter what the shop typed. GET /schema now labels the field "Weight (lb)".
     */
    private function to_pounds($weight)
    {
        if ($weight === '' || (float) $weight <= 0) {
            return null;
        }
        $unit = get_option('woocommerce_weight_unit', 'kg');
        $w = (float) $weight;
        switch ($unit) {
            case 'g':   return round($w * 0.00220462262, 3);
            case 'kg':  return round($w * 2.20462262, 3);
            case 'oz':  return round($w * 0.0625, 3);
            default:    return round($w, 3); // lbs
        }
    }

    /**
     * Woo dimension, in whatever unit the store is set to, converted to INCHES for Surplus.
     *
     * ⚠️ SAME TRAP, OPPOSITE DIRECTION, AND THIS ONE WAS THE EXPENSIVE HALF. This used to convert to
     * centimetres, so a 10 inch box was sent as 25.4 and stored in `max_length`, which the Surplus
     * form labels "(in inches)". VolumetricService then multiplied that 25.4 by 2.54 again to reach
     * centimetres, so every side came out 2.54 times too long and the volumetric weight the customer
     * is charged delivery on, being a product of three sides, came out roughly 16 times too high.
     */
    private function to_inches($value)
    {
        $unit = get_option('woocommerce_dimension_unit', 'cm');
        switch ($unit) {
            case 'mm': return round($value * 0.0393700787, 2);
            case 'cm': return round($value * 0.393700787, 2);
            case 'm':  return round($value * 39.3700787, 2);
            case 'yd': return round($value * 36, 2);
            default:   return round($value, 2); // in
        }
    }
}
