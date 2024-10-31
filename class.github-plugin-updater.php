<?php

if (!class_exists('GithubPluginUpdater')) {
    /**
     * Class GithubPluginUpdater
     * Handles automatic updates for WordPress plugins hosted on GitHub.
     */
    class GithubPluginUpdater {

        public $plugin_file;
        public $github_user;
        public $github_repo;
        public $github_token;
        public $plugin_slug;
        public $version;
        public $cache_key;
        public $cache_allowed;

        /**
         * GithubPluginUpdater constructor.
         * 
         * @param string $plugin_file The main plugin file path.
         * @param string $github_user The GitHub username.
         * @param string $github_repo The GitHub repository name.
         * @param string|null $github_token The GitHub Personal Access Token (optional).
         */
        public function __construct($plugin_file, $github_user, $github_repo, $github_token = null) {
            $this->plugin_file = $plugin_file;
            $this->github_user = $github_user;
            $this->github_repo = $github_repo;
            $this->github_token = $github_token;

            $this->plugin_slug = plugin_basename(dirname($this->plugin_file));
            $this->version = (get_file_data($this->plugin_file, ['Version' => 'Version']))['Version'];
            $this->cache_key = $this->plugin_slug . '-cache-key';
            $this->cache_allowed = true;

            add_filter('plugins_api', [$this, 'getPluginInfo'], 20, 3);
            add_filter('site_transient_update_plugins', [$this, 'checkPlugin']);

            add_action('upgrader_source_selection', [$this, 'fixPluginFolderName'], 10, 2);
            add_action('upgrader_process_complete', [$this, 'cleanCache'], 10, 2);
        }

        /**
         * Makes a GET request to a specified URL.
         * 
         * @param string $url The URL to send the request to.
         * @param string|null $token The authorization token (optional).
         * @return object An object containing success status and response data or error.
         */
        public function request($url, $token = null) {
            $args = [];
            if ($token) {
                $args = array(
                    'timeout' => 15,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ),
                );
            }

            $request = wp_remote_get($url, $args);
            $response_error = is_wp_error($request);
            $response_code = wp_remote_retrieve_response_code($request);
            $response_body = wp_remote_retrieve_body($request);

            if ($response_error || $response_code !== 200) {
                $error_message = $response_error ? $request->get_error_message() : "HTTP Error $response_code";
                return (object) [
                    'success' => false,
                    'error' => $error_message,
                    'code' => $response_code
                ];
            }

            return (object) [
                'success' => true,
                'data' => json_decode($response_body)
            ];
        }

        /**
         * Retrieves the URL of the info.json file from the assets.
         * 
         * @param array $assets The list of assets from the release.
         * @return string|null The URL of the info.json file or null if not found.
         */
        public function getInfoJsonUrl($assets) {
            foreach ($assets as $asset) {
                if ($asset->name === 'info.json') {
                    return $asset->browser_download_url;
                }
            }
            return null;
        }

        /**
         * Validates the required fields in the info_data.
         * 
         * @param object $info_data The data object to validate.
         * @return array An array of missing fields.
         */
        public function validateInfoData($info_data) {
            $required_fields = [
                'name', 'slug', 'version', 'author', 'tested', 
                'requires', 'requires_php', 'sections'
            ];
            $required_sections = ['description', 'installation', 'changelog'];
            $missing_fields = [];

            foreach ($required_fields as $field) {
                if (empty($info_data->$field)) {
                    $missing_fields[] = $field;
                }
            }

            if (!empty($info_data->sections)) {
                foreach ($required_sections as $section) {
                    if (empty($info_data->sections->$section)) {
                        $missing_fields[] = "sections.{$section}";
                    }
                }
            } else {
                $missing_fields[] = 'sections';
            }

            if (!empty($missing_fields)) {
                error_log("Error: Missing required fields for plugin {$this->plugin_slug} - " . implode(', ', $missing_fields));
            }

            return $missing_fields;
        }

        /**
         * Fetches plugin data from the GitHub repository.
         * 
         * @return object|null The plugin data object or null on failure.
         */
        public function fetchPluginData() {
            $remote = $this->cache_allowed ? get_transient($this->cache_key) : false;

            if (!$remote) {
                $endpoint = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
                $response = $this->request($endpoint, $this->github_token);

                if (!$response->success) {
                    error_log("Error in GitHub request for plugin {$this->plugin_slug}: " . $response->error);
                    return null;
                }

                $data = $response->data;
                $info_json_url = $this->getInfoJsonUrl($data->assets);

                if ($info_json_url) {
                    $info_json_response = $this->request($info_json_url, $this->github_token);

                    if ($info_json_response->success) {
                        $info_data = $info_json_response->data;

                        $missing_fields = $this->validateInfoData($info_data);
                        if (!empty($missing_fields)) {
                            return null; // Abort if fields are missing
                        }

                        $res = new stdClass();
                        $res->name = $info_data->name;
                        $res->slug = $info_data->slug;
                        $res->version = $info_data->version;
                        $res->author = $info_data->author;
                        $res->tested = $info_data->tested;
                        $res->requires = $info_data->requires;
                        $res->requires_php = $info_data->requires_php;
                        $res->download_link = $data->zipball_url;
                        $res->trunk = $data->zipball_url;
                        $res->sections = array(
                            'description' => $info_data->sections->description,
                            'installation' => $info_data->sections->installation,
                            'changelog' => $info_data->sections->changelog
                        );

                        set_transient($this->cache_key, $res, DAY_IN_SECONDS);
                        return $res;
                    } else {
                        error_log("Error downloading info.json for plugin {$this->plugin_slug}: " . $info_json_response->error);
                    }
                }
            }

            return null;
        }

        /**
         * Retrieves plugin information for the WordPress API.
         * 
         * @param object $res The existing response object.
         * @param string $action The action being performed.
         * @param object $args The arguments passed to the API.
         * @return object The modified response object.
         */
        public function getPluginInfo($res, $action, $args) {
            if ('plugin_information' === $action && $this->plugin_slug === $args->slug) {
                $remote = $this->fetchPluginData();
                return $remote ?: $res;
            }

            return $res;
        }

        /**
         * Checks for plugin updates by comparing the current version with the remote version.
         * 
         * @param object $transient The transient object containing update information.
         * @return object The updated transient object.
         */
        public function checkPlugin($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            $remote = $this->fetchPluginData();

            if (
                $remote &&
                version_compare($this->version, $remote->version, '<') &&
                version_compare($remote->requires_php, PHP_VERSION, '<=') 
            ) {
                $remote->plugin = plugin_basename($this->plugin_file);
                $remote->new_version = $remote->version;
                $remote->package = $remote->download_link;
                $transient->response[$remote->plugin] = $remote;
            } else {
                if (!$remote) {
                    error_log("Error: Remote data not fetched successfully for plugin {$this->plugin_slug}.");
                } elseif (version_compare($this->version, $remote->version, '>=') ) {
                    error_log(sprintf("No updates available for plugin %s: Local version (%s) is up-to-date or newer than remote version (%s).", $this->plugin_slug, $this->version, $remote->version));
                } elseif (version_compare($remote->requires_php, PHP_VERSION, '>') ) {
                    error_log(sprintf("Error for plugin %s: Local PHP version (%s) does not meet the requirements of the remote plugin (%s).", $this->plugin_slug, PHP_VERSION, $remote->requires_php));
                }
            }

            return $transient;
        }

        /**
         * Retains the original folder name of the plugin during the update process.
         *
         * @param string $source The source directory where the plugin is being updated.
         * @param string $remote_source The remote directory where the plugin is downloaded from.
         */
        public function fixPluginFolderName($source, $remote_source) {
            $source_basename = basename($source);
        
            if ($source_basename !== $this->plugin_slug) {

                $new_plugin_source = str_replace($source_basename, $this->plugin_slug, $source);
                $updated_source = rename($source, $new_plugin_source);

                if (!$updated_source) {
                    error_log("Falha ao renomear {$source} para {$new_plugin_source}");
                    return $source;
                }
        
                return $new_plugin_source;
            }

            return $source;
        }            
        
        /**
         * Cleans the cache after a successful update.
         * 
         * @param object $upgrader The upgrader object.
         * @param array $hook_extra Extra hook arguments.
         */
        public function cleanCache($upgrader, $hook_extra) {
            // Verifica se o tipo é 'plugin' e se o plugin atualizado é o correto
            if (isset($hook_extra['type']) && $hook_extra['type'] === 'plugin' && isset($hook_extra['plugin']) && $hook_extra['plugin'] === plugin_basename($this->plugin_file)) {
                delete_transient($this->cache_key);
            }
        }        
    }
}
