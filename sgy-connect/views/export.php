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
    <h1><?php esc_html_e('Export to CSV', 'sgy-connect'); ?></h1>

    <p class="description">
        <?php echo esc_html(sprintf(
            /* translators: %d: number of products */
            _n(
                'Download your %d product as a CSV file in the standard WooCommerce format, ready to re-import anywhere.',
                'Download all %d of your products as a CSV file in the standard WooCommerce format, ready to re-import anywhere.',
                $count,
                'sgy-connect'
            ),
            $count
        )); ?>
    </p>

    <p>
        <a class="button button-primary" href="<?php echo esc_url($exportUrl); ?>">
            <?php esc_html_e('Download product CSV', 'sgy-connect'); ?>
        </a>
    </p>

    <p class="description">
        <?php esc_html_e('This is the same file as WooCommerce -> Products -> Export, made a single click here.', 'sgy-connect'); ?>
    </p>
</div>
