<?php
/**
 * Fetches + caches the Surplus GET /schema (STORE_CONNECT_BLUEPRINT.md §4). The plugin renders the
 * outstanding-fields checklist and the category-mapping UI from this, so a new required field added on
 * Surplus appears here on the next cache refresh with no plugin release.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Schema
{
    const TRANSIENT = 'sgy_connect_schema';
    const TTL = 900; // 15 minutes

    /** @var SGY_Connect_Client */
    private $client;

    public function __construct(SGY_Connect_Client $client)
    {
        $this->client = $client;
    }

    public function get($forceRefresh = false)
    {
        if (! $forceRefresh) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $res = $this->client->get('/schema');
        if (! $res['ok'] || empty($res['data']['fields'])) {
            // Serve a stale copy rather than an empty editor if the API is briefly unreachable.
            $stale = get_option('sgy_connect_schema_last', '');
            return is_array($stale) && ! empty($stale['fields']) ? $stale : null;
        }

        set_transient(self::TRANSIENT, $res['data'], self::TTL);
        update_option('sgy_connect_schema_last', $res['data'], false);

        return $res['data'];
    }

    public function fields()
    {
        $schema = $this->get();

        return $schema && isset($schema['fields']) ? $schema['fields'] : [];
    }

    public function categories()
    {
        $schema = $this->get();

        return $schema && isset($schema['categories']) ? $schema['categories'] : [];
    }

    public function saved_mappings()
    {
        $schema = $this->get();

        return $schema && isset($schema['category_mappings']) ? (array) $schema['category_mappings'] : [];
    }

    public static function flush()
    {
        delete_transient(self::TRANSIENT);
    }
}
