<?php
/**
 * "Export to CSV" screen — one click to download the whole catalogue as a WooCommerce-format CSV,
 * for vendors who do not know about Woo's built-in Products -> Export. Variables: $count (int),
 * $exportUrl (nonce'd admin-post URL).
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;
?>
<div class="wrap sgy-connect">
    <?php include SGY_CONNECT_DIR . "views/header.php"; ?>
    <h1><?php esc_html_e('Export to CSV', 'sgy-connect'); ?></h1>

    <p class="description">
        <?php echo esc_html(sprintf(
            /* translators: %d: number of products */
            _n(
                'Download your %d product as a CSV file, ready to import straight into Surplus GY.',
                'Download all %d of your products as a CSV file, ready to import straight into Surplus GY.',
                $count,
                'sgy-connect'
            ),
            $count
        )); ?>
    </p>

    <p>
        <a class="button button-primary" href="<?php echo esc_url($exportUrl); ?>">
            <?php esc_html_e('Download products CSV', 'sgy-connect'); ?>
        </a>
    </p>

    <p class="description">
        <?php esc_html_e('Once downloaded, upload this file to Surplus GY to list your products there. Most people just connect and use "Send to Surplus" instead — this is here for when you would rather move a file across yourself.', 'sgy-connect'); ?>
    </p>
</div>
