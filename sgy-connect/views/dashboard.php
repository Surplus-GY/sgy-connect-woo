<?php
/**
 * Dashboard / onboarding screen — the landing page. Walks the vendor through connecting, importing
 * either way, and confirms live sync is active. Variables from SGY_Connect_Admin::render_dashboard():
 * $configured (bool), $env (string), $webhookReady (bool), $linkedCount (int), $importedCount (int).
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

$steps = [
    [
        'done'  => $configured,
        'title' => __('Connect your store to Surplus GY', 'sgy-connect'),
        'body'  => $configured
            ? sprintf(__('Connected to the %s environment.', 'sgy-connect'), esc_html($env))
            : __('Paste the API key and secret from your Surplus GY vendor dashboard (Connected Stores).', 'sgy-connect'),
        'cta'   => $configured ? __('Manage connection', 'sgy-connect') : __('Connect now', 'sgy-connect'),
        'url'   => admin_url('admin.php?page=sgy-connect-connect'),
    ],
    [
        'done'  => $importedCount > 0,
        'title' => __('Bring your Surplus products into WooCommerce', 'sgy-connect'),
        'body'  => $importedCount > 0
            ? sprintf(_n('%d product imported from Surplus.', '%d products imported from Surplus.', $importedCount, 'sgy-connect'), $importedCount)
            : __('See everything you sell on Surplus GY and import it into this store with one click. Categories are created for you.', 'sgy-connect'),
        'cta'   => __('Import from Surplus', 'sgy-connect'),
        'url'   => admin_url('admin.php?page=sgy-connect-surplus'),
    ],
    [
        'done'  => $linkedCount > 0,
        'title' => __('Send your WooCommerce products to Surplus GY', 'sgy-connect'),
        'body'  => $linkedCount > 0
            ? sprintf(_n('%d product linked with Surplus.', '%d products linked with Surplus.', $linkedCount, 'sgy-connect'), $linkedCount)
            : __('Push products the other way — from this store up to Surplus GY for approval and listing.', 'sgy-connect'),
        'cta'   => __('Send to Surplus', 'sgy-connect'),
        'url'   => admin_url('admin.php?page=sgy-connect-import'),
    ],
];

$doneCount = count(array_filter($steps, static function ($s) { return $s['done']; }));
$pct = (int) round(($doneCount / count($steps)) * 100);
?>
<div class="wrap sgy-connect">
    <?php include SGY_CONNECT_DIR . "views/header.php"; ?>
    <h1><?php esc_html_e('Surplus GY', 'sgy-connect'); ?></h1>
    <p class="description"><?php esc_html_e('Connect this store to Surplus GY, import products either way, and keep both in step automatically.', 'sgy-connect'); ?></p>

    <div class="sgy-progress" style="max-width:520px;margin:14px 0;">
        <div class="sgy-progress-bar" style="width:<?php echo esc_attr($pct); ?>%"></div>
    </div>
    <p><strong><?php echo esc_html(sprintf(__('%1$d of %2$d steps done', 'sgy-connect'), $doneCount, count($steps))); ?></strong></p>

    <ol class="sgy-onboard">
        <?php foreach ($steps as $i => $step) : ?>
            <li class="sgy-step <?php echo $step['done'] ? 'is-done' : 'is-todo'; ?>">
                <span class="sgy-step-num"><?php echo $step['done'] ? '&#10003;' : esc_html($i + 1); ?></span>
                <div class="sgy-step-body">
                    <strong><?php echo esc_html($step['title']); ?></strong>
                    <p><?php echo esc_html($step['body']); ?></p>
                    <a class="button <?php echo $i === $doneCount ? 'button-primary' : ''; ?>" href="<?php echo esc_url($step['url']); ?>"><?php echo esc_html($step['cta']); ?></a>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($configured && $webhookReady) : ?>
        <p class="sgy-sync-note">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e('Live two-way sync is active — changes on either side update the other automatically.', 'sgy-connect'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sgy-connect-logs')); ?>"><?php esc_html_e('View sync log', 'sgy-connect'); ?></a>
        </p>
    <?php endif; ?>
</div>
