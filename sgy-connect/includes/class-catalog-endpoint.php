<?php
/**
 * REST endpoint the Surplus GY marketplace calls to PULL this store's catalogue, so a vendor can browse
 * their WooCommerce products on Surplus and import the ones they want, the reverse of this plugin's own
 * "Import from Surplus" screen. POST /wp-json/sgy-connect/v1/catalog.
 *
 * Authenticated with the SAME shared secret the outbound client signs with (option sgy_connect_secret),
 * over "timestamp\nrawBody" inside a 5-minute window: byte-identical to the marketplace's ConnectApiAuth
 * and this plugin's webhook receiver, so the two ends verify each other symmetrically. Read-only: it lists
 * this store's products and never writes.
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

class SGY_Connect_Catalog_Endpoint
{
    const ROUTE = 'sgy-connect/v1';
    const PER_PAGE_MAX = 100;
    const IDS_MAX = 100;

    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    public function register()
    {
        add_action('rest_api_init', function () {
            register_rest_route(self::ROUTE, '/catalog', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true', // authenticated by HMAC below, not WP auth
            ]);
            register_rest_route(self::ROUTE, '/catalog/link', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_link'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(WP_REST_Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth !== true) {
            return $auth; // WP_REST_Response carrying the error
        }

        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $importer = new SGY_Connect_Importer($this->client);

        // Exact-ids fetch (used by "Import selected"): return just those products, so the marketplace never
        // has to trust product data the browser sent, it re-pulls the rows straight from the store.
        $ids = (isset($body['ids']) && is_array($body['ids']))
            ? array_slice(array_values(array_unique(array_map('intval', $body['ids']))), 0, self::IDS_MAX)
            : [];
        if (! empty($ids)) {
            $products = [];
            foreach ($ids as $pid) {
                $row = $this->row($importer, $pid);
                if ($row) {
                    $products[] = $row;
                }
            }

            return new WP_REST_Response([
                'ok'             => true,
                'store_currency' => get_woocommerce_currency(),
                'page'           => 1,
                'per_page'       => count($products),
                'total'          => count($products),
                'last_page'      => 1,
                'categories'     => [],
                'products'       => $products,
            ], 200);
        }

        $page     = max(1, (int) ($body['page'] ?? 1));
        $perPage  = min(self::PER_PAGE_MAX, max(1, (int) ($body['per_page'] ?? 24)));
        $search   = isset($body['search']) ? sanitize_text_field((string) $body['search']) : '';
        $category = (int) ($body['category'] ?? 0);
        $sort     = isset($body['sort']) ? (string) $body['sort'] : 'date_desc';

        // Simple AND variable, matching what SGY_Connect_Importer::to_payload will actually build. The
        // list used to be scoped to `simple` alone while the browse row was built by the same importer,
        // so a shop selling clothing browsed an empty catalogue on Surplus and had no way to tell that
        // it was a filter rather than an outage.
        $taxQuery = [['taxonomy' => 'product_type', 'field' => 'slug', 'terms' => ['simple', 'variable']]];
        if ($category > 0) {
            $taxQuery[] = ['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $category];
        }
        if (count($taxQuery) > 1) {
            $taxQuery['relation'] = 'AND';
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'no_found_rows'  => false,
            'tax_query'      => $taxQuery,
        ];
        if ($search !== '') {
            $args['s'] = $search;
        }
        $this->apply_sort($args, $sort);

        $query = new WP_Query($args);
        $products = [];
        foreach ($query->posts as $post) {
            $row = $this->row($importer, (int) $post->ID);
            if ($row) {
                $products[] = $row;
            }
        }

        $total = (int) $query->found_posts;
        $lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return new WP_REST_Response([
            'ok'             => true,
            'store_currency' => get_woocommerce_currency(),
            'page'           => $page,
            'per_page'       => $perPage,
            'total'          => $total,
            'last_page'      => max(1, $lastPage),
            'categories'     => $this->categories(),
            'products'       => $products,
        ], 200);
    }

    /**
     * Mark this store's products as linked to Surplus after the vendor imported them from the Surplus side
     * (reverse import). Writes the same _sgy_* meta the forward import writes, so the product shows as
     * "Synced" here AND the plugin's instant two-way sync (which keys off _sgy_product_id) fires for it.
     */
    public function handle_link(WP_REST_Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth !== true) {
            return $auth;
        }

        $body = json_decode($request->get_body(), true);
        $links = (is_array($body) && isset($body['links']) && is_array($body['links'])) ? $body['links'] : [];

        $linked = 0;
        foreach (array_slice($links, 0, 500) as $link) {
            $wooId = (int) ($link['external_id'] ?? 0);
            $surplusId = (int) ($link['surplus_product_id'] ?? 0);
            if ($wooId <= 0 || $surplusId <= 0 || get_post_type($wooId) !== 'product') {
                continue;
            }
            update_post_meta($wooId, '_sgy_product_id', $surplusId);
            update_post_meta($wooId, '_sgy_state', isset($link['state']) ? sanitize_text_field((string) $link['state']) : 'imported');
            update_post_meta($wooId, '_sgy_missing', isset($link['missing_fields']) ? (array) $link['missing_fields'] : []);
            update_post_meta($wooId, '_sgy_approval', isset($link['approval']) ? sanitize_text_field((string) $link['approval']) : '');
            $linked++;
        }

        return new WP_REST_Response(['ok' => true, 'linked' => $linked], 200);
    }

    /** One browse row: the import payload (SGY_Connect_Importer::to_payload) plus display extras. */
    private function row(SGY_Connect_Importer $importer, $productId)
    {
        $payload = $importer->to_payload($productId);
        if (! $payload) {
            return null; // not a simple product
        }

        $names = wp_get_post_terms($productId, 'product_cat', ['fields' => 'names']);
        $payload['category_name'] = (! is_wp_error($names) && ! empty($names)) ? $names[0] : '';

        $thumbId = get_post_thumbnail_id($productId);
        $thumb = $thumbId ? wp_get_attachment_image_url($thumbId, 'woocommerce_thumbnail') : '';
        if (! $thumb && ! empty($payload['images'])) {
            $thumb = $payload['images'][0];
        }
        $payload['thumb'] = $thumb ?: '';

        $surplusId = (int) get_post_meta($productId, '_sgy_product_id', true);
        $payload['linked'] = $surplusId > 0;
        $payload['surplus_product_id'] = $surplusId;

        return $payload;
    }

    /** The store's product categories (with counts) for the marketplace's filter dropdown. */
    private function categories()
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
        if (is_wp_error($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            $out[] = ['id' => (int) $term->term_id, 'name' => $term->name, 'count' => (int) $term->count];
        }

        return $out;
    }

    private function apply_sort(array &$args, $sort)
    {
        switch ($sort) {
            case 'date_asc':   $args['orderby'] = 'date';  $args['order'] = 'ASC';  break;
            case 'title_asc':  $args['orderby'] = 'title'; $args['order'] = 'ASC';  break;
            case 'title_desc': $args['orderby'] = 'title'; $args['order'] = 'DESC'; break;
            case 'price_asc':  $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_price'; $args['order'] = 'ASC';  break;
            case 'price_desc': $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_price'; $args['order'] = 'DESC'; break;
            case 'date_desc':
            default:           $args['orderby'] = 'date';  $args['order'] = 'DESC'; break;
        }
    }

    /**
     * Verify the request came from Surplus: X-SGY-Key matches our stored key id, the timestamp is within
     * 5 minutes, and HMAC-SHA256("timestamp\nrawBody") with our API secret matches. Mirrors the
     * marketplace's ConnectApiAuth exactly, so both ends are byte-identical.
     *
     * @return true|WP_REST_Response
     */
    private function authenticate(WP_REST_Request $request)
    {
        $secret = (string) get_option('sgy_connect_secret', '');
        $keyId  = (string) get_option('sgy_connect_key_id', '');
        if ($secret === '' || $keyId === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'not_connected'], 503);
        }

        $reqKey    = (string) $request->get_header('X-SGY-Key');
        $timestamp = (string) $request->get_header('X-SGY-Timestamp');
        $signature = (string) $request->get_header('X-SGY-Signature');

        if ($reqKey === '' || $timestamp === '' || $signature === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_auth_headers'], 401);
        }
        if (! hash_equals($keyId, $reqKey)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unknown_key'], 401);
        }
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_timestamp'], 401);
        }

        $expected = hash_hmac('sha256', $timestamp . "\n" . $request->get_body(), $secret);
        if (! hash_equals($expected, $signature)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 401);
        }

        return true;
    }
}
