<?php
/**
 * The "Surplus GY" tab in the WooCommerce product data panel (STORE_CONNECT_BLUEPRINT.md §6). Renders the
 * Surplus-owned fields the vendor still needs to fill, a completeness meter, and the product's live Surplus
 * status. Values save to _sgy_* postmeta and are pushed to Surplus on save. A products-list column shows
 * the status at a glance. The field list comes from the server /schema, so it never drifts from Surplus.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Product_Tab
{
    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    public function register()
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'add_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save'], 20);

        add_filter('manage_edit-product_columns', [$this, 'add_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 10, 2);
    }

    public function add_tab($tabs)
    {
        $tabs['sgy_connect'] = [
            'label'  => __('Surplus GY', 'sgy-connect'),
            'target' => 'sgy_connect_product_data',
            'class'  => [],
            'priority' => 80,
        ];

        return $tabs;
    }

    public function render_panel()
    {
        global $post;
        if (! $this->client->is_configured()) {
            echo '<div id="sgy_connect_product_data" class="panel woocommerce_options_panel"><p style="padding:12px">' .
                esc_html__('Connect this store to Surplus GY first (Surplus GY → Connect).', 'sgy-connect') . '</p></div>';

            return;
        }

        $schema = ( new SGY_Connect_Schema($this->client) )->get();
        $fields = $schema && isset($schema['fields']) ? $schema['fields'] : [];
        $categories = $schema && isset($schema['categories']) ? $schema['categories'] : [];
        $missing = (array) get_post_meta($post->ID, '_sgy_missing', true);
        $sgyProductId = get_post_meta($post->ID, '_sgy_product_id', true);
        $approval = get_post_meta($post->ID, '_sgy_approval', true);
        $state = get_post_meta($post->ID, '_sgy_state', true);

        include SGY_CONNECT_DIR . 'views/product-tab.php';
    }

    /** Only the Surplus-owned fields are editable here; the synced fields come from Woo's own inputs. */
    public function save($postId)
    {
        if (! current_user_can('edit_post', $postId)) {
            return;
        }
        // Nonce: Woo's own product save nonce covers this metabox submit.
        foreach (['category_id', 'max_length', 'max_width', 'max_height', 'country_of_manufacture', 'vat_percentage', 'product_condition'] as $key) {
            if (isset($_POST['_sgy_' . $key])) {
                update_post_meta($postId, '_sgy_' . $key, sanitize_text_field(wp_unslash($_POST['_sgy_' . $key])));
            }
        }
        // Selects/booleans that may legitimately be 0.
        update_post_meta($postId, '_sgy_is_vat_inclusive', isset($_POST['_sgy_is_vat_inclusive']) ? (int) $_POST['_sgy_is_vat_inclusive'] : '');
        update_post_meta($postId, '_sgy_is_pharma', isset($_POST['_sgy_is_pharma']) ? 1 : 0);

        // Push immediately so the checklist updates while the vendor is still in the editor.
        if (get_post_meta($postId, '_sgy_product_id', true)) {
            SGY_Connect_Sync::queue_product_sync($postId, 'panel_save');
        }
    }

    public function add_column($columns)
    {
        $columns['sgy_status'] = __('Surplus GY', 'sgy-connect');

        return $columns;
    }

    public function render_column($column, $postId)
    {
        if ($column !== 'sgy_status') {
            return;
        }
        $sgyId = get_post_meta($postId, '_sgy_product_id', true);
        if (! $sgyId) {
            echo '<span style="color:#888">' . esc_html__('Not imported', 'sgy-connect') . '</span>';

            return;
        }
        $missing = (array) get_post_meta($postId, '_sgy_missing', true);
        $approval = get_post_meta($postId, '_sgy_approval', true);
        if (! empty($missing)) {
            echo '<span style="color:#b45309">' . sprintf(esc_html__('%d fields outstanding', 'sgy-connect'), count($missing)) . '</span>';
        } elseif ($approval === 'approved') {
            echo '<span style="color:#15803d">' . esc_html__('Live on Surplus', 'sgy-connect') . '</span>';
        } else {
            echo '<span style="color:#2563eb">' . esc_html__('Awaiting approval', 'sgy-connect') . '</span>';
        }
    }
}
