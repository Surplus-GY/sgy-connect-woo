<?php
/**
 * "Import from Surplus" screen — lists the vendor's Surplus products and imports them into Woo. The
 * list + import are driven by admin.js (ajax sgy_connect_surplus_fetch / sgy_connect_surplus_import).
 * Variables: $configured (bool), $currency (string).
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;
?>
<div class="wrap sgy-connect">
    <?php include SGY_CONNECT_DIR . "views/header.php"; ?>
    <h1><?php esc_html_e('Import from Surplus', 'sgy-connect'); ?></h1>

    <?php if (! $configured) : ?>
        <div class="notice notice-warning inline">
            <p><?php echo wp_kses_post(sprintf(
                /* translators: %s: connect screen URL */
                __('Connect this store to Surplus GY first. <a href="%s">Connect now</a>.', 'sgy-connect'),
                esc_url(admin_url('admin.php?page=sgy-connect-connect'))
            )); ?></p>
        </div>
    <?php else : ?>
        <p class="description">
            <?php esc_html_e('Everything you sell on Surplus GY. Import any product into WooCommerce with one click — its category is created for you, and it stays in sync afterwards.', 'sgy-connect'); ?>
        </p>

        <div id="sgy-surplus-fxnote" class="notice notice-info inline" style="display:none"><p></p></div>

        <div class="sgy-surplus-toolbar">
            <input type="search" id="sgy-surplus-search" class="regular-text" placeholder="<?php esc_attr_e('Search your Surplus products…', 'sgy-connect'); ?>">
            <button class="button" id="sgy-surplus-search-btn"><?php esc_html_e('Search', 'sgy-connect'); ?></button>
        </div>

        <div class="sgy-surplus-bulkbar">
            <label class="sgy-checkall"><input type="checkbox" id="sgy-surplus-checkall"> <?php esc_html_e('Select all on this page', 'sgy-connect'); ?></label>
            <button class="button" id="sgy-surplus-import-selected" disabled><?php esc_html_e('Import selected', 'sgy-connect'); ?></button>
            <button class="button button-primary" id="sgy-surplus-import-all"><?php esc_html_e('Import all my Surplus products', 'sgy-connect'); ?></button>
            <span id="sgy-surplus-bulk-status"></span>
        </div>

        <div id="sgy-surplus-bulkprogress" class="sgy-bulkprogress" style="display:none">
            <div class="sgy-progress"><div class="sgy-progress-bar" id="sgy-bulk-bar" style="width:0%"></div></div>
            <p id="sgy-bulk-text"></p>
            <button class="button" id="sgy-surplus-bulk-cancel"><?php esc_html_e('Stop import', 'sgy-connect'); ?></button>
        </div>

        <div id="sgy-surplus-list" class="sgy-surplus-grid">
            <p><?php esc_html_e('Loading your Surplus products…', 'sgy-connect'); ?></p>
        </div>

        <p id="sgy-surplus-pager" class="sgy-pager"></p>
    <?php endif; ?>
</div>
