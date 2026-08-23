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
        add_action('wp_ajax_sgy_connect_surplus_fetch', [$this, 'ajax_surplus_fetch']);
        add_action('wp_ajax_sgy_connect_surplus_import', [$this, 'ajax_surplus_import']);
        add_action('wp_ajax_sgy_connect_bulk_start', [$this, 'ajax_bulk_start']);
        add_action('wp_ajax_sgy_connect_bulk_progress', [$this, 'ajax_bulk_progress']);
        add_action('wp_ajax_sgy_connect_bulk_cancel', [$this, 'ajax_bulk_cancel']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu()
    {
        add_menu_page(
            __('Surplus GY', 'sgy-connect'),
            __('Surplus GY', 'sgy-connect'),
            'manage_woocommerce',
            'sgy-connect',
            [$this, 'render_dashboard'],
            'dashicons-store',
            56
        );
        add_submenu_page('sgy-connect', __('Dashboard', 'sgy-connect'), __('Dashboard', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect', [$this, 'render_dashboard']);
        add_submenu_page('sgy-connect', __('Connect', 'sgy-connect'), __('Connect', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect-connect', [$this, 'render_connect']);
        add_submenu_page('sgy-connect', __('Import from Surplus', 'sgy-connect'), __('Import from Surplus', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect-surplus', [$this, 'render_surplus']);
        add_submenu_page('sgy-connect', __('Send to Surplus', 'sgy-connect'), __('Send to Surplus', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect-import', [$this, 'render_import']);
        add_submenu_page('sgy-connect', __('Export to CSV', 'sgy-connect'), __('Export to CSV', 'sgy-connect'), 'manage_woocommerce', 'sgy-connect-export', [$this, 'render_export']);
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

    public function render_dashboard()
    {
        $configured   = $this->client->is_configured();
        $env          = $this->client->environment();
        // ⚠️ NOT `get_option('sgy_connect_webhook_secret')`. That option is written before the
        // registration call and survives a refusal, so it answered "ready" for a store Surplus can
        // send nothing to. See ensure_webhook_registered().
        $webhookReady = self::webhook_is_registered();
        $webhookError = (string) get_option('sgy_connect_webhook_error', '');

        $linked = new WP_Query(['post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_sgy_product_id', 'fields' => 'ids', 'posts_per_page' => 1]);
        $linkedCount = (int) $linked->found_posts;

        $imported = new WP_Query(['post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_sgy_source', 'meta_value' => 'surplus', 'fields' => 'ids', 'posts_per_page' => 1]);
        $importedCount = (int) $imported->found_posts;

        include SGY_CONNECT_DIR . 'views/dashboard.php';
    }

    public function render_surplus()
    {
        $configured = $this->client->is_configured();
        $currency   = get_woocommerce_currency();
        include SGY_CONNECT_DIR . 'views/surplus.php';
    }

    public function render_export()
    {
        $count      = SGY_Connect_Exporter::product_count();
        $exportUrl  = wp_nonce_url(admin_url('admin-post.php?action=' . SGY_Connect_Exporter::ACTION), SGY_Connect_Exporter::ACTION);
        include SGY_CONNECT_DIR . 'views/export.php';
    }

    // ---- ajax --------------------------------------------------------------------------------------

    /** Fetch one page of the vendor's Surplus catalogue for the "Import from Surplus" screen. */
    public function ajax_surplus_fetch()
    {
        $this->guard();
        $page     = max(1, (int) (isset($_POST['page']) ? $_POST['page'] : 1));
        $search   = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $category = isset($_POST['category']) ? (int) $_POST['category'] : 0;
        $sort     = isset($_POST['sort']) ? sanitize_text_field(wp_unslash($_POST['sort'])) : 'newest';

        $res = ( new SGY_Connect_Catalogue($this->client) )->fetch($page, $search, $category, $sort);
        if (empty($res['ok'])) {
            wp_send_json_error(['message' => isset($res['error']) ? $res['error'] : __('Could not load your Surplus products.', 'sgy-connect')]);
        }

        $data = $res['data'];
        if (! empty($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as &$p) {
                $existing = SGY_Connect_Catalogue::find_woo_product_by_surplus_id((int) $p['surplus_product_id']);
                $p['in_woo'] = (bool) $existing;
                $p['woo_id'] = (int) $existing;
            }
            unset($p);
        }

        wp_send_json_success($data);
    }

    /** Import one Surplus product into Woo (the row is the object returned by ajax_surplus_fetch). */
    public function ajax_surplus_import()
    {
        $this->guard();
        $row = json_decode(isset($_POST['row']) ? wp_unslash($_POST['row']) : '', true);
        if (! is_array($row) || empty($row['surplus_product_id'])) {
            wp_send_json_error(['message' => __('Invalid product.', 'sgy-connect')]);
        }

        $catalogue = new SGY_Connect_Catalogue($this->client);
        $result = $catalogue->import_and_link($row, $catalogue->current_fx_rate());
        if ($result['result'] === 'error') {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success($result);
    }

    /** Start a background import of the WHOLE Surplus catalogue (handles thousands via Action Scheduler). */
    public function ajax_bulk_start()
    {
        $this->guard();
        $res = ( new SGY_Connect_Bulk($this->client) )->start_all();
        if (empty($res['ok'])) {
            wp_send_json_error(['message' => isset($res['message']) ? $res['message'] : __('Could not start the import.', 'sgy-connect')]);
        }
        wp_send_json_success($res);
    }

    /** Poll the background bulk-import progress. */
    public function ajax_bulk_progress()
    {
        $this->guard();
        wp_send_json_success(SGY_Connect_Bulk::progress());
    }

    /** Cancel an in-flight background bulk import. */
    public function ajax_bulk_cancel()
    {
        $this->guard();
        SGY_Connect_Bulk::cancel();
        wp_send_json_success(['status' => 'cancelled']);
    }

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
            $webhookError = $this->ensure_webhook_registered($client);
            wp_send_json_success([
                'environment' => isset($res['data']['environment']) ? $res['data']['environment'] : $client->environment(),
                'vendor_id'   => isset($res['data']['vendor_id']) ? $res['data']['vendor_id'] : null,
                // The connection itself is genuinely up, so this stays a success; the callback is
                // reported alongside it rather than folded into it, because the two can differ and
                // the vendor needs to know which half worked.
                'webhook_ok'  => $webhookError === '',
                'message'     => $webhookError === ''
                    ? __('Connected to Surplus GY.', 'sgy-connect')
                    : sprintf(
                        /* translators: %s: the reason Surplus refused the callback URL. */
                        __('Connected to Surplus GY, but it could not register this site for updates (%s). Products will still upload, but Surplus cannot tell this store about approvals or about stock it has already sold, so the shop can oversell. This usually means the site is not reachable from the internet on a normal https address.', 'sgy-connect'),
                        esc_html($webhookError)
                    ),
            ]);
        }
        wp_send_json_error(['message' => sprintf(__('Could not connect (%s). Check the key and secret.', 'sgy-connect'), esc_html($res['error']))]);
    }

    /**
     * Register (or refresh) the inbound webhook callback so Surplus can push events to this store.
     *
     * Returns the registration error, or '' when Surplus accepted the callback.
     *
     * ⚠️ The implementation moved to SGY_Connect_Webhook::ensure_registered() so `wp sgy connect` can
     * perform the SAME registration. It used to live here as a private method, which meant the only
     * way to connect a store was a human pressing Test connection in wp-admin. Kept as a thin
     * delegation rather than replaced at the call site, because the reasoning about what a refused
     * callback means to a vendor belongs with the screen that reports it.
     *
     * ⚠️ Nothing here retries: this only runs when somebody presses Test connection, or now when
     * somebody runs the CLI command.
     */
    private function ensure_webhook_registered(SGY_Connect_Client $client)
    {
        return SGY_Connect_Webhook::ensure_registered($client);
    }

    /**
     * True only when Surplus has confirmed it will POST events to this store.
     *
     * Deliberately NOT "a webhook secret exists": see ensure_webhook_registered(). A store that has
     * never pressed Test connection is also not ready, which is why the absent option counts as no.
     */
    public static function webhook_is_registered()
    {
        return get_option('sgy_connect_webhook_registered') === 'yes';
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
