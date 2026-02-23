<?php
class ISPAG_Plugin_Updater {
    private $plugin_slug;
    private $plugin_file;
    private $update_url;
    private $plugin_data;

    public function __construct($plugin_slug, $plugin_file, $update_url) {
        $this->plugin_slug = $plugin_slug;
        $this->plugin_file = $plugin_file;
        $this->update_url = $update_url;

        // Hook dans le système de mise à jour de WP
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;

        $remote = wp_remote_get($this->update_url, ['timeout' => 10]);
        if (is_wp_error($remote) || wp_remote_retrieve_response_code($remote) !== 200) return $transient;

        $response = json_decode(wp_remote_retrieve_body($remote));
        if (!$response) return $transient;

        $plugin_version = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file)['Version'];

        if (version_compare($response->version, $plugin_version, '>')) {
            $transient->response[$this->plugin_file] = (object)[
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $response->version,
                'tested'      => $response->tested,
                'requires'    => $response->requires,
                'package'     => $response->package, // lien vers le zip
            ];
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) return $result;

        $remote = wp_remote_get($this->update_url, ['timeout' => 10]);
        if (is_wp_error($remote) || wp_remote_retrieve_response_code($remote) !== 200) return $result;

        $response = json_decode(wp_remote_retrieve_body($remote));
        if (!$response) return $result;

        return (object)[
            'name'        => $response->name,
            'slug'        => $this->plugin_slug,
            'version'     => $response->version,
            'tested'      => $response->tested ?? '',
            'requires'    => $response->requires ?? '',
            'author'      => $response->author ?? '',
            'homepage'    => $response->homepage ?? '',
            'download_link' => $response->package ?? '',
            'sections' => [
                'description' => $response->description ?? '',
                'changelog'   => $response->changelog ?? '',
            ]

        ];
    }
}
