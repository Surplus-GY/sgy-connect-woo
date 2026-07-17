<?php
/** Sync log: the store-side debug trail; correlation ids match the Surplus admin log. */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap sgy-connect">
    <h1><?php esc_html_e('Surplus GY sync log', 'sgy-connect'); ?></h1>
    <p>
        <button type="button" class="button" id="sgy-force-sync"><?php esc_html_e('Force sync now', 'sgy-connect'); ?></button>
        <span id="sgy-force-result"></span>
    </p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('When', 'sgy-connect'); ?></th>
                <th><?php esc_html_e('Direction', 'sgy-connect'); ?></th>
                <th><?php esc_html_e('Action', 'sgy-connect'); ?></th>
                <th><?php esc_html_e('Result', 'sgy-connect'); ?></th>
                <th><?php esc_html_e('Message', 'sgy-connect'); ?></th>
                <th><?php esc_html_e('Correlation', 'sgy-connect'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)) : ?>
            <tr><td colspan="6"><?php esc_html_e('No sync activity yet.', 'sgy-connect'); ?></td></tr>
        <?php else : foreach ($logs as $log) : ?>
            <tr>
                <td><?php echo esc_html($log->created_at); ?></td>
                <td><?php echo esc_html($log->direction); ?></td>
                <td><?php echo esc_html($log->action); ?></td>
                <td>
                    <?php if ($log->result === 'ok') : ?>
                        <span style="color:#15803d">ok</span>
                    <?php elseif ($log->result === 'skipped') : ?>
                        <span style="color:#b45309">skipped</span>
                    <?php else : ?>
                        <span style="color:#b91c1c">error</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($log->message); ?></td>
                <td><code><?php echo esc_html($log->correlation_id); ?></code></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
