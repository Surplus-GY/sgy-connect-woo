<?php
/**
 * Admin UI: the top-level "Surplus GY" menu with the Connect (settings + health check), Import and Logs
 * screens. The Connect screen is where a vendor pastes the key pair issued on their Surplus dashboard;
 * the key prefix decides staging vs prod.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Admin
{
    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    public function register()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_sgy_connect_health', [$this, 'ajax_health']);
        add_action('wp_ajax_sgy_connect_import', [$this, 'ajax_import']);
        add_action('wp_ajax_sgy_connect_save_mappings', [$this, 'ajax_save_mappings']);
        add_action('wp_ajax_sgy_connect_force_sync', [$this, 'ajax_force_sync']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu()
    {
        add_menu_page(
            __('Surplus GY', 'sgy-connect'),
            __('Surplus GY', 'sgy-connect'),
            'manage_woocommerce',
            'sgy-connect',
            [$this, 'render_connect'],
            'dashicons-store',
            56
        );
        add_submenu_page('sgy-connect', __('Connect', 'sgy-connect'), __('Connect', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect', [$this, 'render_connect']);
        add_submenu_page('sgy-connect', __('Import products', 'sgy-connect'), __('Import products', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect-import', [$this, 'render_import']);
        add_submenu_page('sgy-connect', __('Sync log', 'sgy-connect'), __('Sync log', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect-logs', [$this, 'render_logs']);
    }

    public function register_settings()
    {
        register_setting('sgy_connect', 'sgy_connect_key_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sgy_connect', 'sgy_connect_secret', ['sanitize_callback' => 'sanitize_text_field']);
    }

    public function assets($hook)
    {
        if (strpos((string) $hook, 'sgy-connect') === false) {
            return;
        }
        wp_enqueue_script('sgy-connect-admin', SGY_CONNECT_URL . 'assets/admin.js', ['jquery'], SGY_CONNECT_VERSION, true);
        wp_localize_script('sgy-connect-admin', 'SGYConnect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sgy_connect'),
            'i18n'    => [
                'importing' => __('Importing…', 'sgy-connect'),
                'done'      => __('Done', 'sgy-connect'),
                'failed'    => __('Failed', 'sgy-connect'),
            ],
        ]);
        wp_enqueue_style('sgy-connect-admin', SGY_CONNECT_URL . 'assets/admin.css', [], SGY_CONNECT_VERSION);
    }

    // ---- screens -----------------------------------------------------------------------------------

    public function render_connect()
    {
        $keyId  = (string) get_option('sgy_connect_key_id', '');
        $secret = (string) get_option('sgy_connect_secret', '');
        $env = $this->client->environment();
        include SGY_CONNECT_DIR . 'views/connect.php';
    }

    public function render_import()
    {
        $schema = ( new SGY_Connect_Schema($this->client) )->get(true);
        include SGY_CONNECT_DIR . 'views/import.php';
    }

    public function render_logs()
    {
        $logs = SGY_Connect_Logger::recent(150);
        include SGY_CONNECT_DIR . 'views/logs.php';
    }

    // ---- ajax --------------------------------------------------------------------------------------

    private function guard()
    {
        if (! current_user_can('manage_woocommerce') || ! check_ajax_referer('sgy_connect', 'nonce', false)) {
            wp_send_json_error(['message' => __('Not allowed.', 'sgy-connect')], 403);
        }
    }

    /** Ping the API with the saved key so the vendor can confirm the connection before anything else. */
    public function ajax_health()
    {
        $this->guard();
        $client = new SGY_Connect_Client(); // re-read the just-saved options
        $res = $client->get('/ping');
        if ($res['ok']) {
            // On a successful connection, register this store's inbound callback for Surplus->store events
            // (approval + two-way stock). Generate + store a per-store webhook secret once.
            $this->ensure_webhook_registered($client);
            wp_send_json_success([
                'environment' => isset($res['data']['environment']) ? $res['data']['environment'] : $client->environment(),
                'vendor_id'   => isset($res['data']['vendor_id']) ? $res['data']['vendor_id'] : null,
                'message'     => __('Connected to Surplus GY.', 'sgy-connect'),
            ]);
        }
        wp_send_json_error(['message' => sprintf(__('Could not connect (%s). Check the key and secret.', 'sgy-connect'), esc_html($res['error']))]);
    }

    /** Register (or refresh) the inbound webhook callback so Surplus can push events to this store. */
    private function ensure_webhook_registered(SGY_Connect_Client $client)
    {
        $secret = (string) get_option('sgy_connect_webhook_secret', '');
        if ($secret === '') {
            $secret = wp_generate_password(48, false, false);
            update_option('sgy_connect_webhook_secret', $secret, false);
        }
        $callback = rest_url(SGY_Connect_Webhook::ROUTE . '/events');
        $res = $client->post('/webhooks/register', ['webhook_url' => $callback, 'webhook_secret' => $secret]);
        SGY_Connect_Logger::log('outbound', 'webhooks_register', $res['ok'] ? 'ok' : 'error', $res['ok'] ? $callback : $res['error']);
    }

    /** Import a page of products (the wizard calls this repeatedly with offset/limit). */
    public function ajax_import()
    {
        $this->guard();
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $limit  = isset($_POST['limit']) ? min(50, max(1, (int) $_POST['limit'])) : 20;

        $result = ( new SGY_Connect_Importer($this->client) )->import_batch($offset, $limit);
        wp_send_json_success($result);
    }

    public function ajax_save_mappings()
    {
        $this->guard();
        $mappings = isset($_POST['mappings']) && is_array($_POST['mappings']) ? wp_unslash($_POST['mappings']) : [];
        $clean = [];
        foreach ($mappings as $source => $categoryId) {
            $source = sanitize_text_field((string) $source);
            if ($source !== '' && (int) $categoryId > 0) {
                $clean[$source] = (int) $categoryId;
            }
        }
        $res = $this->client->put('/mappings/categories', ['category_mappings' => $clean]);
        SGY_Connect_Schema::flush();
        if ($res['ok']) {
            wp_send_json_success($res['data']);
        }
        wp_send_json_error(['message' => esc_html($res['error'])]);
    }

    public function ajax_force_sync()
    {
        $this->guard();
        $res = $this->client->post('/sync/force', []);
        if ($res['ok']) {
            wp_send_json_success($res['data']);
        }
        wp_send_json_error(['message' => esc_html($res['error'])]);
    }
}
