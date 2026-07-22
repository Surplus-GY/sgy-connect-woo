<?php
/**
 * Branded header bar shown at the top of every Surplus GY Connect admin page. The logo is an inline
 * SVG (no external asset), so it always renders. $env (optional) shows the environment badge.
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

$sgyEnv = isset($env) ? $env : '';
?>
<div class="sgy-topbar">
    <div class="sgy-brand">
        <span class="sgy-logo-mark" aria-hidden="true">
            <svg width="30" height="30" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                <rect width="32" height="32" rx="8" fill="#0B1733"></rect>
                <path d="M9.8 12.1c0-1.9 1.7-3.2 4.1-3.2 1.9 0 3.4.8 4.1 2.2l-2.2 1.2c-.3-.6-1-1-1.9-1-.8 0-1.3.3-1.3.9 0 .6.8.9 2.2 1.2 2.1.5 4 1.3 4 3.7 0 2-1.8 3.4-4.3 3.4-2.2 0-3.9-.9-4.6-2.5l2.2-1.3c.4.9 1.3 1.4 2.5 1.4 1 0 1.5-.3 1.5-.9 0-.6-.8-.9-2.3-1.3-2-.5-3.8-1.3-3.8-3.6z" fill="#D9531A"></path>
                <circle cx="23" cy="23.5" r="2.6" fill="#F26522"></circle>
            </svg>
        </span>
        <span class="sgy-logo-text">Surplus <span>GY</span> <em>Connect</em></span>
    </div>
    <?php if ($sgyEnv === 'live') : ?>
        <span class="sgy-badge sgy-badge-live"><?php esc_html_e('Live', 'sgy-connect'); ?></span>
    <?php elseif ($sgyEnv === 'staging') : ?>
        <span class="sgy-badge sgy-badge-staging"><?php esc_html_e('Test', 'sgy-connect'); ?></span>
    <?php endif; ?>
</div>
