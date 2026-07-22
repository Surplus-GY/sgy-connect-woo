<?php
/**
 * Inbound receiver for Surplus->store events (STORE_CONNECT_BLUEPRINT.md §5). Surplus POSTs HMAC-signed
 * events to this REST route: order.stock_decrement (two-way stock, D3), product.approved/rejected and
 * product.fields_missing_changed. The signature is verified with the shared webhook secret; the store
 * SETS its stock to the authoritative new level (set-if-different), which is what breaks any echo loop:
 * a stock write triggered here matches the value Surplus already has, so the outbound sync it fires is a
 * no-op.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Webhook
{
    const ROUTE = 'sgy-connect/v1';

    public function register()
    {
        add_action('rest_api_init', function () {
            register_rest_route(self::ROUTE, '/events', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true', // authenticated by the HMAC signature, not WP auth
            ]);
        });
    }

    public function handle(WP_REST_Request $request)
    {
        $secret = (string) get_option('sgy_connect_webhook_secret', '');
        if ($secret === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'not_configured'], 503);
        }

        $raw = $request->get_body();
        $timestamp = (string) $request->get_header('X-SGY-Timestamp');
        $signature = (string) $request->get_header('X-SGY-Signature');
        if ($timestamp === '' || $signature === '' || abs(time() - (int) $timestamp) > 300) {
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_timestamp'], 401);
        }
        $expected = hash_hmac('sha256', $timestamp . "\n" . $raw, $secret);
        if (! hash_equals($expected, $signature)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 401);
        }

        $body = json_decode($raw, true);
        $event = isset($body['event']) ? $body['event'] : '';
        $data = isset($body['data']) ? (array) $body['data'] : [];
        $correlationId = isset($body['correlation_id']) ? $body['correlation_id'] : '';
        $externalId = isset($data['external_id']) ? (int) $data['external_id'] : 0;

        switch ($event) {
            case 'order.stock_decrement':
                $this->apply_stock($externalId, $data, $correlationId);
                break;
            case 'product.approved':
            case 'product.rejected':
                if ($externalId) {
                    update_post_meta($externalId, '_sgy_approval', $event === 'product.approved' ? 'approved' : 'rejected');
                    SGY_Connect_Logger::log('inbound', $event, 'ok', 'product ' . $externalId, $correlationId);
                }
                break;
            case 'product.fields_missing_changed':
                if ($externalId && isset($data['missing_fields'])) {
                    update_post_meta($externalId, '_sgy_missing', (array) $data['missing_fields']);
                    SGY_Connect_Logger::log('inbound', $event, 'ok', 'product ' . $externalId, $correlationId);
                }
                break;
            case 'product.created':
            case 'product.updated':
                // Instant Surplus -> Woo sync: (re)build the Woo product from the full payload. Suppress
                // the plugin's own Woo -> Surplus sync while we write, so the change is not echoed back.
                if (! empty($data['surplus_product_id'])) {
                    SGY_Connect_Sync::$suppress = true;
                    $catalogue = new SGY_Connect_Catalogue(new SGY_Connect_Client());
                    $result = $catalogue->import_product($data, $catalogue->current_fx_rate());
                    SGY_Connect_Sync::$suppress = false;
                    SGY_Connect_Logger::log(
                        'inbound',
                        $event,
                        $result['result'] === 'error' ? 'error' : 'ok',
                        'surplus #' . (int) $data['surplus_product_id'] . ' -> woo #' . $result['woo_id'] . ($result['message'] ? ' ' . $result['message'] : ''),
                        $correlationId
                    );
                }
                break;
            default:
                SGY_Connect_Logger::log('inbound', $event ?: 'unknown', 'skipped', 'unhandled event', $correlationId);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /** Set the Woo stock to the authoritative Surplus level (set-if-different avoids a redundant sync). */
    private function apply_stock($productId, array $data, $correlationId)
    {
        if (! $productId || ! isset($data['new_stock'])) {
            return;
        }
        $product = wc_get_product($productId);
        if (! $product) {
            return;
        }
        // Respect a product the vendor deliberately does NOT stock-manage (unlimited / made-to-order):
        // never silently convert it to a managed 0 and take it out of sale. Two-way stock only applies to
        // products that already manage stock.
        if (! $product->managing_stock()) {
            SGY_Connect_Logger::log('inbound', 'order.stock_decrement', 'skipped', 'product ' . $productId . ' does not manage stock; left unchanged', $correlationId);

            return;
        }
        $newStock = max(0, (int) $data['new_stock']);
        if ((int) $product->get_stock_quantity() === $newStock) {
            return; // already in step: do nothing (breaks the echo loop)
        }
        $product->set_stock_quantity($newStock);
        $product->save();
        SGY_Connect_Logger::log('inbound', 'order.stock_decrement', 'ok', 'product ' . $productId . ' set to ' . $newStock, $correlationId);
    }
}
