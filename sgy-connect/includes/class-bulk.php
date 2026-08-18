<?php

/**
 * Background bulk import of the whole Surplus catalogue (Surplus -> Woo). Built for reliability at
 * scale: instead of one long request that would time out or exhaust memory on thousands of products,
 * it walks the catalogue one page at a time through Action Scheduler. Each scheduled action imports a
 * page (~24 products), links them back to Surplus in a single batched call, records progress, then
 * schedules the next page a couple of seconds later. That paced, chunked approach never blocks a web
 * request, survives the browser tab closing, and keeps a light, steady load on Surplus. A progress
 * option is polled by the UI.
 *
 * @package SGY_Connect
 */

defined('ABSPATH') || exit;

class SGY_Connect_Bulk
{
    const HOOK   = 'sgy_connect_bulk_import_page';
    const GROUP  = 'sgy-connect';
    const OPTION = 'sgy_connect_import_job';

    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    public function register()
    {
        add_action(self::HOOK, [$this, 'process_page'], 10, 1);
    }

    /** Kick off a background import of the entire Surplus catalogue. Returns the initial job state. */
    public function start_all()
    {
        $catalogue = new SGY_Connect_Catalogue($this->client);
        $first = $catalogue->fetch(1);
        if (empty($first['ok'])) {
            return ['ok' => false, 'message' => isset($first['error']) ? $first['error'] : 'Could not reach Surplus.'];
        }

        $total = (int) (isset($first['data']['total']) ? $first['data']['total'] : 0);
        update_option(self::OPTION, [
            'status'    => 'running',
            'total'     => $total,
            'done'      => 0,
            'errors'    => 0,
            'page'      => 1,
            'last_page' => (int) (isset($first['data']['last_page']) ? $first['data']['last_page'] : 1),
            'started'   => time(),
        ], false);

        // Import page 1 now, then hand the rest to Action Scheduler so no single request runs long.
        $this->import_page_data($first['data']);
        $this->advance(1, (int) $first['data']['last_page']);

        return ['ok' => true, 'total' => $total];
    }

    /** Action Scheduler worker: import one page then schedule the next (or finish). */
    public function process_page($page)
    {
        // Action Scheduler passes the args as an array.
        if (is_array($page)) {
            $page = isset($page['page']) ? (int) $page['page'] : 1;
        }
        $page = max(1, (int) $page);

        $job = get_option(self::OPTION);
        if (! is_array($job) || ($job['status'] ?? '') !== 'running') {
            return; // cancelled or finished
        }

        $catalogue = new SGY_Connect_Catalogue($this->client);
        $res = $catalogue->fetch($page);
        if (empty($res['ok'])) {
            // Transient failure: back off and retry this same page once more shortly.
            as_schedule_single_action(time() + 30, self::HOOK, ['page' => $page], self::GROUP);

            return;
        }

        $this->import_page_data($res['data']);
        $this->advance($page, (int) (isset($res['data']['last_page']) ? $res['data']['last_page'] : $page));
    }

    /** Import every product on a page, batch-link them, and update the progress counters. */
    private function import_page_data($data)
    {
        $catalogue = new SGY_Connect_Catalogue($this->client);
        $fx = isset($data['fx_rate']) ? (float) $data['fx_rate'] : 0.0;
        $products = isset($data['products']) && is_array($data['products']) ? $data['products'] : [];

        $done = 0;
        $errors = 0;
        $pairs = [];

        SGY_Connect_Sync::without_push(function () use ($products, $catalogue, $fx, &$done, &$errors, &$pairs) {
            foreach ($products as $row) {
                $result = $catalogue->import_product($row, $fx);
                if ($result['result'] === 'error') {
                    $errors++;
                } else {
                    $done++;
                    if (! empty($row['surplus_product_id']) && (int) $result['woo_id'] > 0) {
                        $pairs[] = ['surplus_product_id' => (int) $row['surplus_product_id'], 'external_id' => (string) $result['woo_id']];
                    }
                }
            }
        });

        // One batched link call per page instead of one per product.
        if ($pairs) {
            $catalogue->link_batch($pairs);
        }

        $job = get_option(self::OPTION);
        if (is_array($job)) {
            $job['done'] = (int) $job['done'] + $done;
            $job['errors'] = (int) $job['errors'] + $errors;
            $job['total'] = max((int) $job['total'], (int) $job['done'] + (int) $job['errors']);
            update_option(self::OPTION, $job, false);
        }
    }

    /** Schedule the next page, or mark the job finished. */
    private function advance($page, $lastPage)
    {
        $job = get_option(self::OPTION);
        if (! is_array($job)) {
            return;
        }
        if ($page < $lastPage && function_exists('as_schedule_single_action')) {
            $job['page'] = $page + 1;
            update_option(self::OPTION, $job, false);
            as_schedule_single_action(time() + 2, self::HOOK, ['page' => $page + 1], self::GROUP);
        } else {
            $job['status'] = 'done';
            update_option(self::OPTION, $job, false);
        }
    }

    /** Current job state for the polling UI. */
    public static function progress()
    {
        $job = get_option(self::OPTION);

        return is_array($job) ? $job : ['status' => 'idle'];
    }

    /** Stop an in-flight bulk import. */
    public static function cancel()
    {
        $job = get_option(self::OPTION);
        if (is_array($job)) {
            $job['status'] = 'cancelled';
            update_option(self::OPTION, $job, false);
        }
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }
    }
}
