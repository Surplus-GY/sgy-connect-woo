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

    /**
     * The callback URL Surplus should POST events to for this store.
     *
     * Built with rest_url() rather than a hand-assembled path so it matches whatever permalink
     * structure the site actually has.
     */
    public static function callback_url()
    {
        return rest_url(self::ROUTE . '/events');
    }

    /**
     * Register (or refresh) this store's inbound callback with Surplus.
     *
     * Returns the registration error, or '' when Surplus accepted the callback.
     *
     * ⚠️ THE RETURN VALUE IS THE WHOLE POINT, AND IT USED NOT TO HAVE ONE. The secret is generated and
     * saved locally BEFORE the registration call, so "a webhook secret exists" was true whether Surplus
     * accepted the callback or refused it. The dashboard read exactly that option to decide whether to
     * print "Live two-way sync is active", and the health check answered "Connected to Surplus GY"
     * either way, so a store whose callback was refused was told the opposite of the truth: no approval
     * events, no rejection events, and no order.stock_decrement, which is the one that stops the shop
     * selling a unit Surplus has already sold. Refusal is not exotic. Surplus rejects any callback that
     * does not resolve to a public address on port 80 or 443, so a shop on a non-standard port, on a
     * private staging host, or briefly unresolvable fails here.
     *
     * ⚠️ LIVES HERE, NOT ON THE ADMIN SCREEN, and that move is what made `wp sgy connect` possible.
     * It was a private method behind the admin's AJAX handler, so the ONLY way to connect a store was
     * for a human to press a button in wp-admin: a shopkeeper who scripts their setup, or anyone
     * automating a test environment, could not connect at all. The receiver is the right owner anyway,
     * since it already knows its own route and verifies signatures with this same secret.
     */
    public static function ensure_registered(SGY_Connect_Client $client)
    {
        $secret = (string) get_option('sgy_connect_webhook_secret', '');
        if ($secret === '') {
            $secret = wp_generate_password(48, false, false);
            update_option('sgy_connect_webhook_secret', $secret, false);
        }

        $callback = self::callback_url();
        $res = $client->post('/webhooks/register', ['webhook_url' => $callback, 'webhook_secret' => $secret]);

        $error = $res['ok'] ? '' : (string) $res['error'];
        update_option('sgy_connect_webhook_registered', $res['ok'] ? 'yes' : 'no', false);
        update_option('sgy_connect_webhook_error', $error, false);
        update_option('sgy_connect_webhook_url', $callback, false);

        SGY_Connect_Logger::log('outbound', 'webhooks_register', $res['ok'] ? 'ok' : 'error', $res['ok'] ? $callback : $error);

        return $error;
    }

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
                    $catalogue = new SGY_Connect_Catalogue(new SGY_Connect_Client());
                    $result = SGY_Connect_Sync::without_push(function () use ($catalogue, $data) {
                        return $catalogue->import_product($data, $catalogue->current_fx_rate());
                    });
                    $logResult = 'ok';
                    if ($result['result'] === 'error') {
                        $logResult = 'error';
                    } elseif ($result['result'] === 'skipped') {
                        $logResult = 'skipped'; // bundle/custom kinds are not buildable in Woo
                    }
                    SGY_Connect_Logger::log(
                        'inbound',
                        $event,
                        $logResult,
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
        SGY_Connect_Sync::without_push(function () use ($product, $newStock) {
            $product->set_stock_quantity($newStock);
            $product->save();
        });
        SGY_Connect_Logger::log('inbound', 'order.stock_decrement', 'ok', 'product ' . $productId . ' set to ' . $newStock, $correlationId);
    }
}
