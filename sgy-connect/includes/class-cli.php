<?php
/**
 * WP-CLI commands (STORE_CONNECT_BLUEPRINT.md §6): health check, bulk import and force sync from the
 * command line, for stores that prefer scripting or a large one-off import.
 *
 *   wp sgy health
 *   wp sgy import [--limit=50]
 *   wp sgy force-sync
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_CLI
{
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
