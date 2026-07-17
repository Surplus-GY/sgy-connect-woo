<?php
/**
 * Surplus GY Vendor Connect API client. Signs every request exactly the way the server verifies it
 * (STORE_CONNECT_BLUEPRINT.md §5 / the server's ConnectApiAuth middleware): HMAC-SHA256 over
 * "timestamp\nMETHOD\npath?query\nrawBody" with the store secret, plus X-SGY-Timestamp and, on mutating
 * calls, a caller-supplied X-SGY-Idempotency key. The base URL is derived from the key prefix, so the key
 * chooses staging vs prod with no separate build.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Client
{
    const STAGING_BASE = 'https://app-stg.surplus.gy';
    const PROD_BASE    = 'https://surplusgy.com';

    /** @var string */
    private $keyId;
    /** @var string */
    private $secret;

    public function __construct($keyId = null, $secret = null)
    {
        $this->keyId  = $keyId !== null ? $keyId : (string) get_option('sgy_connect_key_id', '');
        $this->secret = $secret !== null ? $secret : (string) get_option('sgy_connect_secret', '');
    }

    public function is_configured()
    {
        return $this->keyId !== '' && $this->secret !== '';
    }

    /** live | staging | unknown, from the key prefix. */
    public function environment()
    {
        if (strpos($this->keyId, 'sgy_live_') === 0) {
            return 'live';
        }
        if (strpos($this->keyId, 'sgy_test_') === 0) {
            return 'staging';
        }

        return 'unknown';
    }

    private function base_url()
    {
        // A live key talks to production; anything else (test key) to staging. An explicit override is
        // honoured for local development against a custom host.
        $override = get_option('sgy_connect_base_override', '');
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return $this->environment() === 'live' ? self::PROD_BASE : self::STAGING_BASE;
    }

    public function get($path, array $query = [])
    {
        return $this->request('GET', $path, $query, null, null);
    }

    public function post($path, array $body, $idempotencyKey = null)
    {
        return $this->request('POST', $path, [], $body, $idempotencyKey);
    }

    public function patch($path, array $body, $idempotencyKey = null)
    {
        return $this->request('PATCH', $path, [], $body, $idempotencyKey);
    }

    public function put($path, array $body, $idempotencyKey = null)
    {
        return $this->request('PUT', $path, [], $body, $idempotencyKey);
    }

    public function delete($path, $idempotencyKey = null)
    {
        return $this->request('DELETE', $path, [], [], $idempotencyKey);
    }

    /**
     * Perform one signed request.
     *
     * @return array{ok:bool, status:int, data:array, error:?string}
     */
    private function request($method, $path, array $query, $body, $idempotencyKey)
    {
        if (! $this->is_configured()) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'not_configured'];
        }

        $path = '/' . ltrim($path, '/');
        $pathWithQuery = '/api/connect/v1' . $path;
        if (! empty($query)) {
            $pathWithQuery .= '?' . http_build_query($query);
        }

        $rawBody = ($body === null || $body === []) ? '' : wp_json_encode($body);
        $timestamp = (string) time();
        // The signed path must be exactly what the server sees as the request URI, query included.
        $canonical = $timestamp . "\n" . strtoupper($method) . "\n" . $pathWithQuery . "\n" . $rawBody;
        $signature = hash_hmac('sha256', $canonical, $this->secret);

        $headers = [
            'X-SGY-Key'       => $this->keyId,
            'X-SGY-Timestamp' => $timestamp,
            'X-SGY-Signature' => $signature,
            'Accept'          => 'application/json',
        ];
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headers['Content-Type'] = 'application/json';
            // A FRESH key per call by default. The idempotency key must identify one user-initiated
            // ATTEMPT, never the content: a content-derived key made the server dedup legitimately-distinct
            // operations (a force-sync re-run, or a stock/price value returning to an earlier state, would
            // replay a stale 48h-cached response and silently do nothing). Store Connect is app-level
            // idempotent anyway (import upserts by external_id, sync/patch is a set-operation, force-sync is
            // meant to re-run), so re-executing a genuine retry is safe. A caller may still pass an explicit
            // stable key to dedup a specific retry of the exact same logical job.
            $headers['X-SGY-Idempotency'] = $idempotencyKey !== null
                ? $idempotencyKey
                : (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16)));
        }

        $args = [
            'method'      => strtoupper($method),
            'headers'     => $headers,
            'timeout'     => 30,
            'redirection' => 0,
            'body'        => $rawBody !== '' ? $rawBody : null,
        ];

        $response = wp_remote_request($this->base_url() . $pathWithQuery, $args);

        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'data'   => $data,
            'error'  => ($status >= 200 && $status < 300) ? null : (isset($data['error']) ? $data['error'] : ('http_' . $status)),
        ];
    }
}
