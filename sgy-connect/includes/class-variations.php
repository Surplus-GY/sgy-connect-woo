<?php
/**
 * WooCommerce variations <-> Surplus GY options, in ONE place, both directions.
 *
 * ⚠️ WHY THIS FILE HAD TO EXIST BEFORE ANYTHING ELSE WOULD BE SAFE. Until it did, the plugin treated
 * every product as if it had a single price and a single stock figure, and a WooCommerce VARIABLE
 * product has neither: the parent post holds no `_regular_price`, and unless the shop manages stock at
 * parent level `managing_stock()` is false. So the three paths did three different wrong things:
 *
 *   · the IMPORT wizard skipped variable products outright, so a clothing shop could not bring its
 *     catalogue to Surplus at all;
 *   · the INSTANT SYNC did fire for one (any product with a `_sgy_product_id`, which the reverse
 *     import sets), and sent `price => ''` and `stock => 999998`, the plugin's own "unlimited"
 *     sentinel. Reproduced against the real Surplus endpoint: a 5/3/2 product's master stock went to
 *     1,000,008 and a warehouse row appeared holding 999,998 against no option, while the three real
 *     options did not move;
 *   · the REVERSE import flattened a Surplus product that has options into one plain Woo product
 *     priced from the master, so the shop's own catalogue lost the sizes and the colours.
 *
 * Surplus refuses a parent-level price or stock on a product with options now, so the sync above would
 * be answered with a warning rather than silently accepted. This class is the half that sends the right
 * thing instead.
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

class SGY_Connect_Variations
{
    /** Surplus refuses more than this per product; matched here so the shop is told before the request. */
    const MAX_VARIATIONS = 100;
    const MAX_ATTRIBUTES = 6;

    /** Is this a Woo product with variations? */
    public static function is_variable($product)
    {
        return $product && method_exists($product, 'is_type') && $product->is_type('variable');
    }

    /**
     * The product's option groups, as Surplus wants them: [['name' => 'Size', 'options' => [...]]].
     *
     * Only attributes marked "Used for variations" are sent. A Woo attribute that is not used for
     * variations is a specification (material, warranty), not a choice a shopper makes, and sending it
     * as an axis would multiply the combination count for nothing.
     *
     * ⚠️ LABELS, NOT SLUGS. A taxonomy attribute is stored as `pa_size` with term slugs like `small`.
     * Surplus stores the option and its value as the words a shopper reads, and its variant key is
     * built from those words, so sending slugs would show "pa_size: small" on the product page and
     * would not match the terms this same class sends for the variations.
     */
    public static function attributes_of($product)
    {
        $out = [];
        foreach ($product->get_attributes() as $attribute) {
            if (! is_object($attribute) || ! method_exists($attribute, 'get_variation') || ! $attribute->get_variation()) {
                continue;
            }
            $name = wc_attribute_label($attribute->get_name(), $product);
            $options = [];
            if ($attribute->is_taxonomy()) {
                foreach ((array) $attribute->get_terms() as $term) {
                    $options[] = $term->name;
                }
            } else {
                $options = array_map('trim', (array) $attribute->get_options());
            }
            $options = array_values(array_filter($options, function ($o) { return $o !== ''; }));
            if ($name === '' || empty($options)) {
                continue;
            }
            $out[] = ['name' => $name, 'options' => $options];
            if (count($out) >= self::MAX_ATTRIBUTES) {
                break;
            }
        }

        return $out;
    }

    /**
     * The product's variations, as Surplus wants them.
     *
     * ⚠️ A VARIATION WITH AN EMPTY ATTRIBUTE VALUE IS SKIPPED, NOT GUESSED. WooCommerce lets a variation
     * leave an axis blank, which means "any value of this axis". Surplus has no such thing: every
     * `product_attribute_details` row names one exact combination. Expanding an "any" into every value
     * would invent rows the shop never priced, and sending it with a blank would collide every "any"
     * variation onto the same Surplus row and leave whichever arrived last holding the price. It is
     * reported to the shop instead.
     *
     * @return array{variations:array, skipped:int}
     */
    public static function variations_of($product)
    {
        $axes = array();
        foreach (self::attributes_of($product) as $attribute) {
            $axes[] = $attribute['name'];
        }

        $variations = [];
        $skipped = 0;

        foreach ($product->get_children() as $childId) {
            if (count($variations) >= self::MAX_VARIATIONS) {
                $skipped++;
                continue;
            }
            $variation = wc_get_product($childId);
            if (! $variation) {
                continue;
            }

            $options = self::options_of($variation, $product);
            if (count($options) !== count($axes)) {
                $skipped++;
                continue; // an "any" axis, or an axis the parent no longer declares
            }

            $regular = $variation->get_regular_price();
            $sale = $variation->get_sale_price();

            $variations[] = [
                'options'          => $options,
                'price'            => $regular !== '' ? $regular : $variation->get_price(),
                // Always sent, so ENDING a sale in Woo clears it on Surplus rather than leaving
                // yesterday's sale price as the thing the checkout charges.
                'discounted_price' => $sale !== '' ? $sale : null,
                'stock'            => self::stock_of($variation, $product),
                'sku'              => $variation->get_sku(),
                'image'            => self::image_of($variation),
            ];
        }

        return ['variations' => $variations, 'skipped' => $skipped];
    }

    /**
     * One variation's option values as ['Size' => 'Large'], in the words Surplus stores.
     *
     * `WC_Product_Variation::get_attributes()` returns the axis key WITHOUT the `attribute_` prefix
     * (`pa_size` or `size`) and, for a taxonomy attribute, the TERM SLUG rather than its name. Both are
     * translated back here so the pair matches what attributes_of() sent.
     */
    private static function options_of($variation, $parent)
    {
        $out = [];
        foreach ((array) $variation->get_attributes() as $key => $value) {
            $value = (string) $value;
            if ($value === '') {
                continue; // "any" — the caller treats a short list as a skip
            }
            $label = wc_attribute_label($key, $parent);
            if (taxonomy_exists($key)) {
                $term = get_term_by('slug', $value, $key);
                if ($term && ! is_wp_error($term)) {
                    $value = $term->name;
                }
            }
            if ($label !== '') {
                $out[$label] = $value;
            }
        }

        return $out;
    }

    /**
     * A variation's stock for Surplus.
     *
     * Woo's `manage_stock` on a variation can be 'parent', meaning the parent counts for all of them.
     * `managing_stock()` already resolves that, so the only extra case is a variation counting nothing
     * at all, which is the shop saying "unlimited" and must be sent as the in-stock sentinel rather
     * than as 0, which would take it off sale on Surplus.
     */
    private static function stock_of($variation, $parent)
    {
        if ($variation->managing_stock()) {
            return max(0, (int) $variation->get_stock_quantity());
        }
        if ($parent && method_exists($parent, 'managing_stock') && $parent->managing_stock()) {
            return max(0, (int) $parent->get_stock_quantity());
        }

        return $variation->is_in_stock() ? 999998 : 0;
    }

    private static function image_of($variation)
    {
        $imageId = $variation->get_image_id();
        if (! $imageId) {
            return '';
        }
        $url = wp_get_attachment_image_url($imageId, 'full');

        return $url ? $url : '';
    }

    // ── Surplus -> WooCommerce ────────────────────────────────────────────────────────────────────

    /**
     * Build (or refresh) a WooCommerce VARIABLE product from a Surplus catalogue/webhook row, instead
     * of flattening it into one plain product.
     *
     * ⚠️ THE ORDER OF THE AXES IS PRESERVED EXACTLY AS SURPLUS SENT IT. Surplus rebuilds a variant's
     * selector key from the option values in the product's own attribute order, so a store that
     * recreated the product with the axes shuffled would send back combinations that no longer match
     * the rows they came from, and the next sync would report every one of them as unknown.
     *
     * ⚠️ EXISTING VARIATIONS ARE UPDATED IN PLACE, NEVER DELETED AND REBUILT. A variation id is what
     * this shop's own orders, carts and reports point at; recreating it loses that history and unlinks
     * the variation's image. A combination Surplus no longer sends is set out of stock and left.
     *
     * @param  WC_Product_Variable $product  saved product to attach variations to
     * @param  array               $row      a serializer row carrying `attributes` and `variants`
     * @param  float               $fxRate   store-currency units per 1 GYD (0 = the store is GYD)
     * @return array{created:int, updated:int, retired:int}
     */
    public static function apply_to_woo($product, array $row, $fxRate = 0.0)
    {
        $attributes = isset($row['attributes']) && is_array($row['attributes']) ? $row['attributes'] : [];
        $variants = isset($row['variants']) && is_array($row['variants']) ? $row['variants'] : [];
        if (empty($attributes) || empty($variants)) {
            return ['created' => 0, 'updated' => 0, 'retired' => 0];
        }

        // ---- the axes, as CUSTOM (non-taxonomy) product attributes. Custom rather than global on
        // purpose: creating global `pa_*` taxonomies on somebody's shop from an import is a change to
        // the whole store, not to one product, and it cannot be undone by deleting the product.
        $wooAttributes = [];
        $position = 0;
        foreach ($attributes as $attribute) {
            $name = isset($attribute['name']) ? (string) $attribute['name'] : '';
            $options = isset($attribute['options']) && is_array($attribute['options']) ? array_values($attribute['options']) : [];
            if ($name === '' || empty($options)) {
                continue;
            }
            $wooAttribute = new WC_Product_Attribute();
            $wooAttribute->set_name($name);
            $wooAttribute->set_options($options);
            $wooAttribute->set_position($position++);
            $wooAttribute->set_visible(true);
            $wooAttribute->set_variation(true);
            $wooAttributes[] = $wooAttribute;
        }
        if (empty($wooAttributes)) {
            return ['created' => 0, 'updated' => 0, 'retired' => 0];
        }
        $product->set_attributes($wooAttributes);
        $product->save();

        // ---- index what the shop already holds, by the same order-independent key Surplus matches on.
        $existing = [];
        foreach ($product->get_children() as $childId) {
            $child = wc_get_product($childId);
            if ($child) {
                $existing[self::key(self::options_of($child, $product))] = $child;
            }
        }

        $created = 0;
        $updated = 0;
        $seen = [];

        foreach ($variants as $variant) {
            $options = isset($variant['options']) && is_array($variant['options']) ? $variant['options'] : [];
            if (empty($options)) {
                continue;
            }
            $key = self::key($options);
            $seen[$key] = true;

            $variation = isset($existing[$key]) ? $existing[$key] : null;
            if (! $variation) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product->get_id());
                $created++;
            } else {
                $updated++;
            }

            $attributeMap = [];
            foreach ($options as $name => $value) {
                $attributeMap[sanitize_title((string) $name)] = (string) $value;
            }
            $variation->set_attributes($attributeMap);

            // Prices: the server-converted figures when present, else convert the GYD ones here. Same
            // rule as the simple path, so a store never shows two different conversions of one product.
            $regular = self::store_price($variant, 'regular_price', $fxRate);
            $sale = self::store_price($variant, 'sale_price', $fxRate);
            $variation->set_regular_price($regular !== null ? (string) $regular : '');
            $variation->set_sale_price($sale !== null && $sale !== '' ? (string) $sale : '');

            $stock = isset($variant['stock']) ? (int) $variant['stock'] : 0;
            if ($stock >= 999990) {
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');
            } else {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($stock);
                $variation->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
            }

            $variation->save();
        }

        // ---- combinations Surplus no longer sends: taken off sale, never removed.
        $retired = 0;
        foreach ($existing as $key => $child) {
            if (isset($seen[$key])) {
                continue;
            }
            if ($child->get_stock_status() === 'outofstock') {
                continue;
            }
            $child->set_stock_status('outofstock');
            if ($child->managing_stock()) {
                $child->set_stock_quantity(0);
            }
            $child->save();
            $retired++;
        }

        // Woo caches a variable product's price range; a stale one shows the old "from" price for ever.
        if (class_exists('WC_Product_Variable')) {
            WC_Product_Variable::sync($product->get_id());
        }
        wc_delete_product_transients($product->get_id());

        return ['created' => $created, 'updated' => $updated, 'retired' => $retired];
    }

    /** The order-independent identity of a combination, matching Surplus's own VariantMap::key(). */
    public static function key(array $options)
    {
        $pairs = [];
        foreach ($options as $name => $value) {
            $name = strtolower(trim(str_replace([',', ':'], ['', ' '], (string) $name)));
            $value = strtolower(trim(str_replace([',', ':'], ['', ' '], (string) $value)));
            $name = trim(preg_replace('/\s+/', ' ', $name));
            $value = trim(preg_replace('/\s+/', ' ', $value));
            if ($name === '' || $value === '') {
                continue;
            }
            $pairs[$name] = $value;
        }
        ksort($pairs);

        $out = [];
        foreach ($pairs as $name => $value) {
            $out[] = $name . '=' . $value;
        }

        return implode('|', $out);
    }

    /** The figure to set in Woo: the server-converted value if present, else convert GYD by $fxRate. */
    private static function store_price(array $variant, $key, $fxRate)
    {
        $convertedKey = $key . '_converted';
        if (array_key_exists($convertedKey, $variant) && $variant[$convertedKey] !== null) {
            return $variant[$convertedKey];
        }
        if (! isset($variant[$key]) || $variant[$key] === null || $variant[$key] === '') {
            return null;
        }
        $gyd = (float) $variant[$key];
        if ($fxRate > 0 && get_woocommerce_currency() !== 'GYD') {
            return round($gyd * (float) $fxRate, 2);
        }

        return $gyd;
    }
}
