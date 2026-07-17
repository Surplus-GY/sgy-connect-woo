<?php
/**
 * Self-updates from GitHub Releases (STORE_CONNECT_BLUEPRINT.md §6). Tag vX.Y.Z on the repo -> every
 * vendor's WP admin offers the update; a marked pre-release serves as the staging channel (opt-in via a
 * constant). No wordpress.org listing needed. Reads the public Releases API; no token required for a
 * public repo, and the check is cached to respect rate limits.
 */

if (! defined('ABSPATH')) {
    exit;
}

class SGY_Connect_Updater
{
    const REPO = 'Surplus-GY/sgy-connect-woo';
    const TRANSIENT = 'sgy_connect_latest_release';
    const TTL = 21600; // 6 hours

    public function register()
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
    }

    private function latest_release()
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        // Include pre-releases only when the store explicitly opts into the staging channel.
        $usePrerelease = defined('SGY_CONNECT_BETA') && SGY_CONNECT_BETA;
        $url = 'https://api.github.com/repos/' . self::REPO . '/releases' . ($usePrerelease ? '?per_page=5' : '/latest');

        $res = wp_remote_get($url, ['timeout' => 15, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'sgy-connect']]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        $release = $usePrerelease ? (is_array($body) ? ($body[0] ?? null) : null) : $body;
        if (! is_array($release) || empty($release['tag_name'])) {
            return null;
        }

        $data = [
            'version' => ltrim($release['tag_name'], 'v'),
            'zip'     => $this->zip_url($release),
            'url'     => isset($release['html_url']) ? $release['html_url'] : '',
            'notes'   => isset($release['body']) ? $release['body'] : '',
        ];
        set_transient(self::TRANSIENT, $data, self::TTL);

        return $data;
    }

    /** Prefer an attached built .zip asset; fall back to the source zipball. */
    private function zip_url($release)
    {
        if (! empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['name']) && substr($asset['name'], -4) === '.zip' && ! empty($asset['browser_download_url'])) {
                    return $asset['browser_download_url'];
                }
            }
        }

        return isset($release['zipball_url']) ? $release['zipball_url'] : '';
    }

    public function inject_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }
        $latest = $this->latest_release();
        if (! $latest || ! $latest['zip']) {
            return $transient;
        }
        if (version_compare($latest['version'], SGY_CONNECT_VERSION, '>')) {
            $transient->response[SGY_CONNECT_BASENAME] = (object) [
                'slug'        => 'sgy-connect',
                'plugin'      => SGY_CONNECT_BASENAME,
                'new_version' => $latest['version'],
                'url'         => $latest['url'],
                'package'     => $latest['zip'],
            ];
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'sgy-connect') {
            return $result;
        }
        $latest = $this->latest_release();
        if (! $latest) {
            return $result;
        }

        return (object) [
            'name'          => 'Surplus GY Connect',
            'slug'          => 'sgy-connect',
            'version'       => $latest['version'],
            'author'        => 'Surplus GY',
            'homepage'      => $latest['url'],
            'download_link' => $latest['zip'],
            'sections'      => ['changelog' => wp_kses_post(nl2br($latest['notes']))],
        ];
    }
}
