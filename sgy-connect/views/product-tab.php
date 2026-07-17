<?php
/**
 * The "Surplus GY" product-data panel. Shows the live Surplus status + the completeness meter, then the
 * Surplus-owned fields the vendor fills here (synced fields come from Woo's own product inputs). Field
 * definitions come from the server /schema, so the set is always current.
 *
 * @var array $fields
 * @var array $categories
 * @var array $missing
 * @var mixed $sgyProductId
 * @var string $approval
 * @var string $state
 */
if (! defined('ABSPATH')) {
    exit;
}

// Only the Surplus-owned fields are edited here; synced ones live on Woo's General/Inventory tabs.
$ownedKeys = ['category_id', 'max_length', 'max_width', 'max_height', 'country_of_manufacture', 'is_vat_inclusive', 'vat_percentage', 'is_pharma', 'product_condition'];
$byKey = [];
foreach ($fields as $f) {
    $byKey[$f['key']] = $f;
}
$missingCount = count($missing);
?>
<div id="sgy_connect_product_data" class="panel woocommerce_options_panel">
    <div class="options_group" style="padding:8px 12px">
        <?php if (! $sgyProductId) : ?>
            <p><?php esc_html_e('This product has not been imported to Surplus GY yet. Use Surplus GY → Import products, then return here to complete it.', 'sgy-connect'); ?></p>
        <?php else : ?>
            <p>
                <strong><?php esc_html_e('Surplus GY status:', 'sgy-connect'); ?></strong>
                <?php if ($missingCount > 0) : ?>
                    <span style="color:#b45309"><?php echo esc_html(sprintf(__('%d fields outstanding', 'sgy-connect'), $missingCount)); ?></span>
                <?php elseif ($approval === 'approved') : ?>
                    <span style="color:#15803d"><?php esc_html_e('Live on Surplus GY', 'sgy-connect'); ?></span>
                <?php elseif ($approval === 'rejected') : ?>
                    <span style="color:#b91c1c"><?php esc_html_e('Rejected — see Surplus GY', 'sgy-connect'); ?></span>
                <?php else : ?>
                    <span style="color:#2563eb"><?php esc_html_e('Awaiting approval', 'sgy-connect'); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="options_group">
        <?php
        // Category (mapped to a Surplus category).
        if (isset($byKey['category_id'])) {
            $val = get_post_meta($post->ID, '_sgy_category_id', true);
            echo '<p class="form-field"><label for="_sgy_category_id">' . esc_html__('Surplus category', 'sgy-connect') . '</label>';
            echo '<select id="_sgy_category_id" name="_sgy_category_id"><option value="">' . esc_html__('— choose —', 'sgy-connect') . '</option>';
            foreach ($categories as $sc) {
                echo '<option value="' . esc_attr($sc['id']) . '" ' . selected((int) $val, (int) $sc['id'], false) . '>' . esc_html($sc['title']) . '</option>';
            }
            echo '</select></p>';
        }

        // Dimensions (cm).
        foreach (['max_length' => __('Length (cm)', 'sgy-connect'), 'max_width' => __('Width (cm)', 'sgy-connect'), 'max_height' => __('Height (cm)', 'sgy-connect')] as $key => $label) {
            $val = get_post_meta($post->ID, '_sgy_' . $key, true);
            woocommerce_wp_text_input([
                'id'                => '_sgy_' . $key,
                'label'             => $label,
                'value'             => $val,
                'type'              => 'number',
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            ]);
        }

        // Country of manufacture.
        $val = get_post_meta($post->ID, '_sgy_country_of_manufacture', true);
        woocommerce_wp_text_input(['id' => '_sgy_country_of_manufacture', 'label' => __('Country of manufacture', 'sgy-connect'), 'value' => $val, 'description' => __('Surplus country id or name', 'sgy-connect')]);

        // VAT.
        $vatMode = get_post_meta($post->ID, '_sgy_is_vat_inclusive', true);
        woocommerce_wp_select([
            'id'      => '_sgy_is_vat_inclusive',
            'label'   => __('VAT treatment', 'sgy-connect'),
            'value'   => (string) $vatMode,
            'options' => ['' => __('— choose —', 'sgy-connect'), '1' => __('VAT inclusive', 'sgy-connect'), '2' => __('No VAT', 'sgy-connect')],
        ]);
        woocommerce_wp_text_input(['id' => '_sgy_vat_percentage', 'label' => __('VAT %', 'sgy-connect'), 'value' => get_post_meta($post->ID, '_sgy_vat_percentage', true), 'type' => 'number', 'custom_attributes' => ['step' => '0.01', 'min' => '0']]);

        // Condition.
        woocommerce_wp_select([
            'id'      => '_sgy_product_condition',
            'label'   => __('Condition', 'sgy-connect'),
            'value'   => (string) get_post_meta($post->ID, '_sgy_product_condition', true),
            'options' => ['' => __('— choose —', 'sgy-connect'), '1' => __('New', 'sgy-connect'), '2' => __('Used', 'sgy-connect'), '3' => __('Refurbished', 'sgy-connect')],
        ]);

        // Pharmacy.
        woocommerce_wp_checkbox(['id' => '_sgy_is_pharma', 'label' => __('Pharmacy item', 'sgy-connect'), 'value' => get_post_meta($post->ID, '_sgy_is_pharma', true) ? 'yes' : 'no']);
        ?>
    </div>
</div>
