<?php
/**
 * Branded header bar shown at the top of every Surplus GY Connect admin page. Uses the real Surplus GY
 * brand logo (the running man wordmark, bundled as assets/surplus-logo.png). $env (optional) shows the
 * environment badge.
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

$sgyEnv = isset($env) ? $env : '';
?>
<div class="sgy-topbar">
    <div class="sgy-brand">
        <img class="sgy-logo-img"
             src="<?php echo esc_url(SGY_CONNECT_URL . 'assets/surplus-logo.png'); ?>"
             alt="<?php esc_attr_e('Surplus GY', 'sgy-connect'); ?>" width="200" height="50">
        <span class="sgy-logo-divider" aria-hidden="true"></span>
        <span class="sgy-logo-tag"><?php esc_html_e('Connect', 'sgy-connect'); ?></span>
    </div>
    <?php if ($sgyEnv === 'live') : ?>
        <span class="sgy-badge sgy-badge-live"><?php esc_html_e('Live', 'sgy-connect'); ?></span>
    <?php elseif ($sgyEnv === 'staging') : ?>
        <span class="sgy-badge sgy-badge-staging"><?php esc_html_e('Test', 'sgy-connect'); ?></span>
    <?php endif; ?>
</div>
