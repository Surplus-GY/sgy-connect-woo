<?php
/** Connect screen: paste the key pair from the Surplus dashboard, save, then run the health check. */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap sgy-connect">
    <h1><?php esc_html_e('Surplus GY Connect', 'sgy-connect'); ?></h1>
    <p class="description">
        <?php esc_html_e('Create a store connection on your Surplus GY vendor dashboard (Connected Stores), then paste the Key ID and Secret here. The key decides the environment automatically.', 'sgy-connect'); ?>
    </p>

    <form method="post" action="options.php">
        <?php settings_fields('sgy_connect'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="sgy_connect_key_id"><?php esc_html_e('Key ID', 'sgy-connect'); ?></label></th>
                <td><input name="sgy_connect_key_id" id="sgy_connect_key_id" type="text" class="regular-text code" value="<?php echo esc_attr($keyId); ?>" placeholder="sgy_test_… or sgy_live_…"></td>
            </tr>
            <tr>
                <th scope="row"><label for="sgy_connect_secret"><?php esc_html_e('Secret', 'sgy-connect'); ?></label></th>
                <td>
                    <input name="sgy_connect_secret" id="sgy_connect_secret" type="password" class="regular-text code" value="<?php echo esc_attr($secret); ?>" autocomplete="off">
                    <p class="description"><?php esc_html_e('Shown only once on the Surplus dashboard. If you lost it, revoke the connection there and create a new one.', 'sgy-connect'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Environment', 'sgy-connect'); ?></th>
                <td>
                    <?php if ($env === 'live') : ?>
                        <span class="sgy-badge sgy-badge-live"><?php esc_html_e('Production', 'sgy-connect'); ?></span>
                    <?php elseif ($env === 'staging') : ?>
                        <span class="sgy-badge sgy-badge-staging"><?php esc_html_e('Staging', 'sgy-connect'); ?></span>
                    <?php else : ?>
                        <span class="sgy-badge"><?php esc_html_e('Enter a key to detect', 'sgy-connect'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Save connection', 'sgy-connect')); ?>
    </form>

    <hr>
    <h2><?php esc_html_e('Connection health', 'sgy-connect'); ?></h2>
    <p><button type="button" class="button" id="sgy-health-btn"><?php esc_html_e('Test connection', 'sgy-connect'); ?></button> <span id="sgy-health-result"></span></p>

    <p class="description">
        <?php esc_html_e('Once connected, go to Surplus GY → Import products to bring your catalogue over, then fill the outstanding Surplus fields on each product’s “Surplus GY” tab.', 'sgy-connect'); ?>
    </p>
</div>
