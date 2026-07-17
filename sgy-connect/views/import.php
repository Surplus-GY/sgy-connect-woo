<?php
/** Import screen: category mapping (from /schema) + the batch import wizard. */
if (! defined('ABSPATH')) {
    exit;
}
$fields = $schema && isset($schema['fields']) ? $schema['fields'] : [];
$categories = $schema && isset($schema['categories']) ? $schema['categories'] : [];
$savedMappings = $schema && isset($schema['category_mappings']) ? (array) $schema['category_mappings'] : [];

// The store's own product categories, to map onto Surplus categories.
$wooCats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
?>
<div class="wrap sgy-connect">
    <h1><?php esc_html_e('Import products to Surplus GY', 'sgy-connect'); ?></h1>

    <?php if (! $schema) : ?>
        <div class="notice notice-error"><p><?php esc_html_e('Cannot reach Surplus GY. Check your connection on the Connect screen.', 'sgy-connect'); ?></p></div>
        <?php return; ?>
    <?php endif; ?>

    <h2><?php esc_html_e('1. Map your categories', 'sgy-connect'); ?></h2>
    <p class="description"><?php esc_html_e('Match each of your store categories to a Surplus GY category. This is remembered for future imports and syncs.', 'sgy-connect'); ?></p>
    <table class="widefat striped" style="max-width:820px">
        <thead><tr><th><?php esc_html_e('Your category', 'sgy-connect'); ?></th><th><?php esc_html_e('Surplus GY category', 'sgy-connect'); ?></th></tr></thead>
        <tbody>
        <?php if (! is_wp_error($wooCats)) : foreach ($wooCats as $cat) : ?>
            <tr>
                <td><?php echo esc_html($cat->name); ?></td>
                <td>
                    <select class="sgy-cat-map" data-source="<?php echo esc_attr($cat->slug); ?>">
                        <option value=""><?php esc_html_e('— not mapped —', 'sgy-connect'); ?></option>
                        <?php foreach ($categories as $sc) : ?>
                            <option value="<?php echo esc_attr($sc['id']); ?>" <?php selected(isset($savedMappings[$cat->slug]) ? (int) $savedMappings[$cat->slug] : 0, (int) $sc['id']); ?>>
                                <?php echo esc_html($sc['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <p><button type="button" class="button" id="sgy-save-mappings"><?php esc_html_e('Save mappings', 'sgy-connect'); ?></button> <span id="sgy-mappings-result"></span></p>

    <hr>
    <h2><?php esc_html_e('2. Import your products', 'sgy-connect'); ?></h2>
    <p class="description"><?php esc_html_e('Products import as unapproved drafts on Surplus GY. Anything still missing a required Surplus field appears on that product’s “Surplus GY” tab; fill it and it updates on Surplus automatically.', 'sgy-connect'); ?></p>
    <p>
        <button type="button" class="button button-primary" id="sgy-import-btn"><?php esc_html_e('Start import', 'sgy-connect'); ?></button>
        <span id="sgy-import-status"></span>
    </p>
    <div id="sgy-import-progress" style="display:none;max-width:820px">
        <div class="sgy-progress"><div class="sgy-progress-bar" id="sgy-progress-bar"></div></div>
        <div id="sgy-import-summary" class="sgy-summary"></div>
    </div>
</div>
