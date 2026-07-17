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

    /** How many published simple products exist (drives the wizard's progress bar). */
    public function total()
    {
        $counts = wp_count_posts('product');

        return isset($counts->publish) ? (int) $counts->publish : 0;
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
            'stock'            => $product->managing_stock() ? (int) $product->get_stock_quantity() : 0,
            'brand'            => $this->brand_of($product),
            'upc'              => $product->get_global_unique_id(),
            'max_weight'       => $this->to_kg($product->get_weight()),
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
                    $payload[$sgyDim] = $this->to_cm((float) $dim);
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

    /** Woo weight is in the store's configured unit; Surplus wants kg. */
    private function to_kg($weight)
    {
        if ($weight === '' || (float) $weight <= 0) {
            return null;
        }
        $unit = get_option('woocommerce_weight_unit', 'kg');
        $w = (float) $weight;
        switch ($unit) {
            case 'g':  return round($w / 1000, 3);
            case 'lbs': return round($w * 0.453592, 3);
            case 'oz': return round($w * 0.0283495, 3);
            default:   return round($w, 3); // kg
        }
    }

    /** Woo dimension unit -> cm. */
    private function to_cm($value)
    {
        $unit = get_option('woocommerce_dimension_unit', 'cm');
        switch ($unit) {
            case 'mm': return round($value / 10, 2);
            case 'm':  return round($value * 100, 2);
            case 'in': return round($value * 2.54, 2);
            case 'yd': return round($value * 91.44, 2);
            default:   return round($value, 2); // cm
        }
    }
}
