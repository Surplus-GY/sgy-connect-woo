<?php
/**
 * WP-CLI commands (STORE_CONNECT_BLUEPRINT.md §6): health check, bulk import and force sync from the
 * command line, for stores that prefer scripting or a large one-off import.
 *
 *   wp sgy connect [--key=<id>] [--secret=<secret>] [--url=<base>] [--require-callback]
 *   wp sgy health
 *   wp sgy import [--limit=50]
 *   wp sgy force-sync
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_CLI
{
    /**
     * Connect this store to Surplus GY: save the credentials, verify them, and register the callback.
     *
     * ⚠️ WHY THIS EXISTS. Connecting was only ever possible by pressing Test connection in wp-admin,
     * because the callback registration sat in a private method behind that screen's AJAX handler. A
     * shopkeeper who scripts their setup, a managed host provisioning many stores, or anyone standing
     * up a test environment could not connect a store at all: they could set the key and secret as
     * options, and the store would then upload products while silently receiving NOTHING back, because
     * registering the callback is a separate step nothing else performs.
     *
     * ⚠️ CONNECTING IS TWO THINGS AND THEY CAN DIFFER, which is why the output separates them. The
     * credentials can be perfect while Surplus refuses the callback, and a store in that state uploads
     * happily and never hears about an approval or about stock Surplus has already sold, so it can
     * oversell. Reported as a warning rather than folded into the success line; pass --require-callback
     * to make it a hard failure, which is what an automated setup should do.
     *
     * ## OPTIONS
     *
     * [--key=<id>]
     * : The key id issued by Surplus (sgy_live_... or sgy_test_...). Uses the saved one if omitted.
     *
     * [--secret=<secret>]
     * : The matching secret. Uses the saved one if omitted.
     *
     * [--url=<base>]
     * : Override the Surplus base URL. Only for local or self-hosted testing; a live key already
     *   points at production and a test key at staging.
     *
     * [--require-callback]
     * : Exit non-zero if Surplus will not accept this store's callback URL.
     *
     * ## EXAMPLES
     *
     *     wp sgy connect --key=sgy_test_abc123 --secret=sgy_sec_xyz789
     *     wp sgy connect --require-callback
     */
    public function connect($args, $assoc)
    {
        // Written only when supplied, so re-running with no flags re-verifies and re-registers an
        // existing connection rather than blanking it.
        if (isset($assoc['key'])) {
            update_option('sgy_connect_key_id', trim((string) $assoc['key']));
        }
        if (isset($assoc['secret'])) {
            update_option('sgy_connect_secret', trim((string) $assoc['secret']));
        }
        if (isset($assoc['url'])) {
            update_option('sgy_connect_base_override', rtrim(trim((string) $assoc['url']), '/'));
        }

        $client = new SGY_Connect_Client(); // re-read, so this reflects what was just saved

        if (! $client->is_configured()) {
            WP_CLI::error('No key and secret are set. Pass --key and --secret, or add them on the Surplus GY settings screen.');
        }

        $res = $client->get('/ping');
        if (! $res['ok']) {
            WP_CLI::error('Could not connect (' . $res['error'] . '). Check the key and secret.');
        }

        $environment = isset($res['data']['environment']) ? $res['data']['environment'] : $client->environment();
        $vendorId    = isset($res['data']['vendor_id']) ? $res['data']['vendor_id'] : null;

        WP_CLI::log('Connected to Surplus GY (' . $environment . ($vendorId ? ', vendor ' . $vendorId : '') . ').');

        // The same registration the admin screen performs, not a second copy of it.
        $webhookError = SGY_Connect_Webhook::ensure_registered($client);

        if ($webhookError === '') {
            WP_CLI::log('Callback registered: ' . SGY_Connect_Webhook::callback_url());
            WP_CLI::success('Store connected. Two-way sync is active.');

            return;
        }

        $message = 'Connected, but Surplus could not register this site for updates (' . $webhookError . '). '
            . 'Products will still upload, but Surplus cannot tell this store about approvals or about '
            . 'stock it has already sold, so the shop can oversell. This usually means the site is not '
            . 'reachable from the internet on a normal https address.';

        if (! empty($assoc['require-callback'])) {
            WP_CLI::error($message);
        }

        WP_CLI::warning($message);
        WP_CLI::success('Store connected. Uploads will work; two-way sync is NOT active.');
    }

    /** Check the connection to Surplus GY. */
    public function health($args, $assoc)
    {
        $res = ( new SGY_Connect_Client() )->get('/ping');
        if ($res['ok']) {
            WP_CLI::success('Connected to Surplus GY (' . ($res['data']['environment'] ?? '?') . ').');
        } else {
            WP_CLI::error('Not connected: ' . $res['error']);
        }
    }

    /**
     * Import all published simple products.
     *
     * [--limit=<n>]
     * : Products per batch (default 20, max 50).
     */
    public function import($args, $assoc)
    {
        $limit = isset($assoc['limit']) ? min(50, max(1, (int) $assoc['limit'])) : 20;
        $importer = new SGY_Connect_Importer(new SGY_Connect_Client());
        $offset = 0;
        $created = 0;
        $errors = 0;
        do {
            $batch = $importer->import_batch($offset, $limit);
            foreach ($batch['results'] as $r) {
                if (($r['result'] ?? '') === 'error') {
                    $errors++;
                    WP_CLI::warning('row error: ' . implode('; ', (array) ($r['errors'] ?? [])));
                } elseif (($r['result'] ?? '') === 'created') {
                    $created++;
                }
            }
            $offset = $batch['offset'];
            WP_CLI::log("...{$offset}/{$batch['total']}");
        } while (! $batch['done']);

        WP_CLI::success("Import complete. Created {$created}, errors {$errors}.");
    }

    /** Ask Surplus to recompute every product's checklist. */
    public function force_sync($args, $assoc)
    {
        $res = ( new SGY_Connect_Client() )->post('/sync/force', []);
        if ($res['ok']) {
            WP_CLI::success('Force sync done: ' . count((array) ($res['data']['products'] ?? [])) . ' products recomputed.');
        } else {
            WP_CLI::error('Force sync failed: ' . $res['error']);
        }
    }
}
