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
        // Surplus fields render right under the WooCommerce "General" tab (not a separate tab), so the
        // vendor fills them alongside price and SKU. Dropdowns + required markers + help tips; dimensions
        // and weight are reused from Woo's own Shipping tab so nothing is typed twice.
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_general_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save'], 20);

        add_filter('manage_edit-product_columns', [$this, 'add_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 10, 2);
    }

    /** Render the Surplus-owned fields inside the General product-data panel. */
    public function render_general_fields()
    {
        echo '<div class="options_group sgy-general-fields">';

        if (! $this->client->is_configured()) {
            echo '<p class="form-field" style="color:#666">' .
                esc_html__('Connect this store to Surplus GY to fill its extra product fields (Surplus GY → Connect).', 'sgy-connect') .
                '</p></div>';

            return;
        }

        $schema = ( new SGY_Connect_Schema($this->client) )->get();
        $fields = $this->index_fields($schema && isset($schema['fields']) ? $schema['fields'] : []);
        $categories = $schema && isset($schema['categories']) ? $schema['categories'] : [];

        // Category dropdown from the Surplus category tree.
        $catOptions = ['' => __('— Select a Surplus category —', 'sgy-connect')];
        foreach ($categories as $c) {
            $catOptions[(string) $c['id']] = $c['title'];
        }

        // Country dropdown from WooCommerce's own country list (no typing).
        $countries = ['' => __('— Select —', 'sgy-connect')];
        if (function_exists('WC') && WC()->countries) {
            foreach (WC()->countries->get_countries() as $name) {
                $countries[(string) $name] = $name;
            }
        }

        echo '<p class="form-field"><strong>' . esc_html__('Surplus GY details', 'sgy-connect') . '</strong> — ' .
            esc_html__('extra information Surplus needs. Required fields are marked with *.', 'sgy-connect') . '</p>';

        woocommerce_wp_select([
            'id'          => '_sgy_category_id',
            'label'       => $this->label('category_id', $fields, __('Surplus category', 'sgy-connect')),
            'options'     => $catOptions,
            'desc_tip'    => true,
            'description' => __('Which Surplus GY category this product belongs to.', 'sgy-connect'),
        ]);

        woocommerce_wp_select([
            'id'          => '_sgy_country_of_manufacture',
            'label'       => $this->label('country_of_manufacture', $fields, __('Country of manufacture', 'sgy-connect')),
            'options'     => $countries,
            'desc_tip'    => true,
            'description' => __('Where the product was made.', 'sgy-connect'),
        ]);

        woocommerce_wp_select([
            'id'          => '_sgy_product_condition',
            'label'       => $this->label('product_condition', $fields, __('Condition', 'sgy-connect')),
            'options'     => ['' => __('— Select —', 'sgy-connect'), '1' => __('New', 'sgy-connect'), '2' => __('Used', 'sgy-connect'), '3' => __('Refurbished', 'sgy-connect')],
            'desc_tip'    => true,
            'description' => __('The item condition.', 'sgy-connect'),
        ]);

        woocommerce_wp_select([
            'id'          => '_sgy_is_vat_inclusive',
            'label'       => $this->label('is_vat_inclusive', $fields, __('VAT', 'sgy-connect')),
            'options'     => ['' => __('— Select —', 'sgy-connect'), '1' => __('Price includes VAT', 'sgy-connect'), '2' => __('No VAT / exempt', 'sgy-connect')],
            'desc_tip'    => true,
            'description' => __('Whether the price already includes VAT.', 'sgy-connect'),
        ]);

        woocommerce_wp_text_input([
            'id'                => '_sgy_vat_percentage',
            'label'             => $this->label('vat_percentage', $fields, __('VAT %', 'sgy-connect')),
            'type'              => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            'desc_tip'          => true,
            'description'       => __('VAT rate as a percentage, if any.', 'sgy-connect'),
        ]);

        woocommerce_wp_checkbox([
            'id'          => '_sgy_is_pharma',
            'label'       => __('Pharmaceutical', 'sgy-connect'),
            'description' => __('Tick if this is a pharmaceutical product.', 'sgy-connect'),
        ]);

        echo '<p class="form-field" style="color:#666"><em>' .
            esc_html__('Dimensions and weight come from the WooCommerce Shipping tab, so there is no need to enter them again here.', 'sgy-connect') .
            '</em></p>';

        echo '</div>';
    }

    /** Key the /schema fields array by their key so we can read label/required per field. */
    private function index_fields($fields)
    {
        $byKey = [];
        foreach ((array) $fields as $f) {
            if (isset($f['key'])) {
                $byKey[$f['key']] = $f;
            }
        }

        return $byKey;
    }

    /** Field label from the schema (fallback to $default), with a trailing * when the field is required. */
    private function label($key, $fields, $default)
    {
        $f = isset($fields[$key]) ? $fields[$key] : null;
        $label = ($f && ! empty($f['label'])) ? $f['label'] : $default;
        if ($f && ! empty($f['required'])) {
            $label .= ' *';
        }

        return $label;
    }

    /** Only the Surplus-owned fields are editable here; the synced fields come from Woo's own inputs. */
    public function save($postId)
    {
        if (! current_user_can('edit_post', $postId)) {
            return;
        }
        // Nonce: Woo's own product save nonce covers this metabox submit. Dimensions/weight are NOT saved
        // here any more — they come from Woo's native Shipping fields (the importer reads them directly).
        foreach (['category_id', 'country_of_manufacture', 'vat_percentage', 'product_condition'] as $key) {
            if (isset($_POST['_sgy_' . $key])) {
                update_post_meta($postId, '_sgy_' . $key, sanitize_text_field(wp_unslash($_POST['_sgy_' . $key])));
            }
        }
        // Selects/booleans that may legitimately be 0/empty.
        update_post_meta($postId, '_sgy_is_vat_inclusive', (isset($_POST['_sgy_is_vat_inclusive']) && $_POST['_sgy_is_vat_inclusive'] !== '') ? (int) $_POST['_sgy_is_vat_inclusive'] : '');
        // woocommerce_wp_checkbox posts 'yes' when ticked.
        update_post_meta($postId, '_sgy_is_pharma', (isset($_POST['_sgy_is_pharma']) && $_POST['_sgy_is_pharma'] === 'yes') ? 1 : 0);

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
            echo '<span style="display:inline-block;padding:2px 9px;border-radius:10px;background:#f0f0f1;color:#888;font-size:11px;">'
                . esc_html__('Not on Surplus', 'sgy-connect') . '</span>';

            return;
        }

        // A clear "Synced" pill so a vendor can see at a glance which products are linked to Surplus, with
        // the detailed state (outstanding fields / approval) underneath.
        echo '<span style="display:inline-block;padding:2px 9px;border-radius:10px;background:#E7F4EA;color:#15803d;font-size:11px;font-weight:600;">&#10003; '
            . esc_html__('Synced', 'sgy-connect') . '</span>';

        $missing = (array) get_post_meta($postId, '_sgy_missing', true);
        $approval = get_post_meta($postId, '_sgy_approval', true);
        if (! empty($missing)) {
            echo '<br><span style="color:#b45309;font-size:11px;">' . sprintf(esc_html__('%d fields outstanding', 'sgy-connect'), count($missing)) . '</span>';
        } elseif ($approval === 'approved') {
            echo '<br><span style="color:#15803d;font-size:11px;">' . esc_html__('Live on Surplus', 'sgy-connect') . '</span>';
        } else {
            echo '<br><span style="color:#2563eb;font-size:11px;">' . esc_html__('Awaiting approval', 'sgy-connect') . '</span>';
        }
    }
}
