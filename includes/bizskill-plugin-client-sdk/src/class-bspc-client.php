<?php
if (!defined('ABSPATH')) exit;

final class BSPC_Client {
    private static array $products = [];
    private array $config;
    private string $option_key;

    public static function register(array $config): self {
        $required = ['product_name','product_slug','plugin_file','plugin_basename','version','server_url'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new InvalidArgumentException('Missing BizSkill client configuration: ' . $field);
            }
        }

        $client = new self($config);
        self::$products[$client->config['product_slug']] = $client;
        $client->hooks();
        return $client;
    }

    private function __construct(array $config) {
        $this->config = [
            'product_name' => sanitize_text_field($config['product_name']),
            'product_slug' => sanitize_key($config['product_slug']),
            'plugin_file' => $config['plugin_file'],
            'plugin_basename' => sanitize_text_field($config['plugin_basename']),
            'version' => sanitize_text_field($config['version']),
            'server_url' => untrailingslashit(esc_url_raw($config['server_url'])),
            'menu_parent' => sanitize_text_field($config['menu_parent'] ?? 'options-general.php'),
            'menu_title' => sanitize_text_field($config['menu_title'] ?? 'Licence'),
            'settings_capability' => sanitize_key($config['settings_capability'] ?? 'manage_options'),
        ];

        $this->option_key = 'bspc_' . $this->config['product_slug'];
    }

    private function hooks(): void {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_bspc_activate_' . $this->config['product_slug'], [$this, 'activate_action']);
        add_action('admin_post_bspc_deactivate_' . $this->config['product_slug'], [$this, 'deactivate_action']);
        add_action('admin_post_bspc_recheck_' . $this->config['product_slug'], [$this, 'recheck_action']);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('plugin_action_links_' . $this->config['plugin_basename'], [$this, 'action_links']);

        add_action('admin_notices', [$this, 'admin_notice']);
    }

    private function state(): array {
        $state = get_option($this->option_key, []);
        if (!is_array($state)) {
            $state = [];
        }

        if (empty($state['instance_id'])) {
            $state['instance_id'] = wp_generate_uuid4();
            update_option($this->option_key, $state, false);
        }

        return $state;
    }

    private function save(array $state): void {
        update_option($this->option_key, $state, false);
    }

    private function endpoint(string $path): string {
        return $this->config['server_url'] . '/wp-json/bizskill-plugins/v1/' . ltrim($path, '/');
    }

    private function sdk_version(): string {
        return defined('BSPC_SDK_VERSION') ? (string) BSPC_SDK_VERSION : '0.1.0-roomscore';
    }

    private function request(string $path, array $payload): array {
        $response = wp_remote_post($this->endpoint($path), [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'user-agent' => 'BizSkill-Plugin-Client/' . $this->sdk_version() . '; ' . home_url('/'),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return ['success' => false, 'message' => 'Invalid response from licence server.'];
        }

        return $body;
    }

    private function base_payload(array $state): array {
        return [
            'product_slug' => $this->config['product_slug'],
            'license_key' => $state['license_key'] ?? '',
            'activation_id' => $state['activation_id'] ?? '',
            'activation_token' => $state['activation_token'] ?? '',
            'instance_id' => $state['instance_id'] ?? '',
            'site_url' => home_url('/'),
            'plugin_version' => $this->config['version'],
            'installed_version' => $this->config['version'],
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ];
    }

    private function update_cache_key(): string {
        return 'bspc_update_' . md5($this->config['product_slug'] . '|' . home_url('/'));
    }

    private function admin_page_url(): string {
        return admin_url('admin.php?page=bspc-' . $this->config['product_slug']);
    }

    private function is_active(array $state): bool {
        return !empty($state['activation_id']) && !empty($state['activation_token']);
    }

    private function flag_to_bool($value, bool $default = false): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'active', 'allowed'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'inactive', 'blocked', 'denied'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function response_message(array $result, string $fallback): string {
        return sanitize_text_field((string) ($result['message'] ?? $fallback));
    }

    private function apply_status_fields(array &$state, array $result): void {
        if (isset($result['expires_at'])) {
            $state['expires_at'] = sanitize_text_field((string) $result['expires_at']);
        }

        if (isset($result['license_status'])) {
            $state['license_status'] = sanitize_text_field((string) $result['license_status']);
        } elseif (isset($result['status'])) {
            $state['license_status'] = sanitize_text_field((string) $result['status']);
        }

        if (array_key_exists('updates_allowed', $result)) {
            $state['updates_allowed'] = $this->flag_to_bool($result['updates_allowed']);
        }

        $state['last_checked_at'] = current_time('mysql');
    }

    public function admin_menu(): void {
        add_submenu_page(
            $this->config['menu_parent'],
            $this->config['product_name'] . ' Licence',
            $this->config['menu_title'],
            $this->config['settings_capability'],
            'bspc-' . $this->config['product_slug'],
            [$this, 'settings_page']
        );
    }

    public function settings_page(): void {
        if (!current_user_can($this->config['settings_capability'])) {
            return;
        }

        $state = $this->state();
        $active = $this->is_active($state);

        $license_status = (string) ($state['license_status'] ?? ($active ? 'active' : 'not_active'));
        $expires_at = (string) ($state['expires_at'] ?? '');
        $last_error = (string) ($state['last_error'] ?? '');
        $updates_allowed = $this->flag_to_bool($state['updates_allowed'] ?? false);
        $site_url = home_url('/');

        echo '<div class="wrap"><h1>' . esc_html($this->config['product_name']) . ' Licence</h1>';

        if (isset($_GET['bspc_message'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash((string) $_GET['bspc_message'])) . '</p></div>';
        }
        if (isset($_GET['bspc_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash((string) $_GET['bspc_error'])) . '</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:860px"><tbody>';
        echo '<tr><th style="width:240px">Product</th><td>' . esc_html($this->config['product_name']) . '</td></tr>';
        echo '<tr><th>Installed version</th><td>' . esc_html($this->config['version']) . '</td></tr>';
        echo '<tr><th>Licence status</th><td><strong style="color:' . ($active ? '#16803d' : '#b42318') . '">' . esc_html($license_status) . '</strong></td></tr>';
        echo '<tr><th>Expiry date</th><td>' . esc_html($expires_at !== '' ? $expires_at : 'N/A') . '</td></tr>';
        echo '<tr><th>Updates allowed</th><td>' . ($updates_allowed ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><th>Current website URL</th><td><code>' . esc_html($site_url) . '</code></td></tr>';
        echo '<tr><th>Last API error</th><td>' . esc_html($last_error !== '' ? $last_error : 'None') . '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:20px;max-width:860px;background:#fff;padding:20px">';
        wp_nonce_field('bspc_activate_' . $this->config['product_slug']);
        echo '<input type="hidden" name="action" value="bspc_activate_' . esc_attr($this->config['product_slug']) . '">';
        echo '<label><strong>Licence Key</strong><br><input name="license_key" class="regular-text" required autocomplete="off" value="' . esc_attr((string) ($state['license_key'] ?? '')) . '"></label>';
        echo '<p><button class="button button-primary">Activate Licence</button></p>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;display:inline-block;margin-right:12px">';
        wp_nonce_field('bspc_recheck_' . $this->config['product_slug']);
        echo '<input type="hidden" name="action" value="bspc_recheck_' . esc_attr($this->config['product_slug']) . '">';
        echo '<button class="button">Recheck Licence</button>';
        echo '</form>';

        if ($active) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;display:inline-block">';
            wp_nonce_field('bspc_deactivate_' . $this->config['product_slug']);
            echo '<input type="hidden" name="action" value="bspc_deactivate_' . esc_attr($this->config['product_slug']) . '">';
            echo '<button class="button">Deactivate Licence</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    public function activate_action(): void {
        if (!current_user_can($this->config['settings_capability'])) {
            wp_die('Unauthorised');
        }

        check_admin_referer('bspc_activate_' . $this->config['product_slug']);

        $state = $this->state();
        $state['license_key'] = sanitize_text_field(wp_unslash((string) ($_POST['license_key'] ?? '')));

        $result = $this->request('activate', $this->base_payload($state));
        $url = $this->admin_page_url();

        if (!empty($result['success'])) {
            $state['activation_id'] = sanitize_text_field((string) ($result['activation_id'] ?? ''));
            $state['activation_token'] = sanitize_text_field((string) ($result['activation_token'] ?? ''));
            $this->apply_status_fields($state, $result);
            $state['last_error'] = '';

            if (!isset($state['updates_allowed'])) {
                $state['updates_allowed'] = true;
            }

            $this->save($state);
            delete_site_transient('update_plugins');
            wp_safe_redirect(add_query_arg('bspc_message', 'Licence activated.', $url));
            exit;
        }

        $state['last_error'] = $this->response_message($result, 'Activation failed.');
        $this->save($state);
        wp_safe_redirect(add_query_arg('bspc_error', $state['last_error'], $url));
        exit;
    }

    public function deactivate_action(): void {
        if (!current_user_can($this->config['settings_capability'])) {
            wp_die('Unauthorised');
        }

        check_admin_referer('bspc_deactivate_' . $this->config['product_slug']);

        $state = $this->state();
        $result = $this->request('deactivate', $this->base_payload($state));
        $url = $this->admin_page_url();

        if (!empty($result['success'])) {
            $state['activation_id'] = '';
            $state['activation_token'] = '';
            $state['license_status'] = 'deactivated';
            $state['updates_allowed'] = false;
            $state['expires_at'] = '';
            $state['last_error'] = '';
            $state['last_checked_at'] = current_time('mysql');

            $this->save($state);
            delete_site_transient('update_plugins');
            wp_safe_redirect(add_query_arg('bspc_message', 'Licence deactivated.', $url));
            exit;
        }

        $state['last_error'] = $this->response_message($result, 'Deactivation failed.');
        $this->save($state);
        wp_safe_redirect(add_query_arg('bspc_error', $state['last_error'], $url));
        exit;
    }

    public function recheck_action(): void {
        if (!current_user_can($this->config['settings_capability'])) {
            wp_die('Unauthorised');
        }

        check_admin_referer('bspc_recheck_' . $this->config['product_slug']);

        $url = $this->admin_page_url();
        $data = $this->update_data(true);

        if (!empty($data['success'])) {
            delete_site_transient('update_plugins');
            wp_safe_redirect(add_query_arg('bspc_message', 'Licence recheck completed.', $url));
            exit;
        }

        $state = $this->state();
        $error = (string) ($state['last_error'] ?? 'Licence recheck failed.');
        wp_safe_redirect(add_query_arg('bspc_error', $error, $url));
        exit;
    }

    private function update_data(bool $force = false): array {
        $state = $this->state();

        if (empty($state['activation_id']) || empty($state['activation_token']) || empty($state['license_key'])) {
            return [];
        }

        $cache_key = $this->update_cache_key();
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->request('check-update', $this->base_payload($state));

        if (empty($result['success'])) {
            $state['last_error'] = $this->response_message($result, 'Update check failed.');
            $state['last_checked_at'] = current_time('mysql');
            $this->save($state);
            set_transient($cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        $state['last_error'] = '';
        $this->apply_status_fields($state, $result);

        if (!isset($state['updates_allowed'])) {
            $state['updates_allowed'] = $this->flag_to_bool($result['updates_allowed'] ?? null, !empty($result['package']) || !empty($result['update_available']));
        }

        $this->save($state);
        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);

        return $result;
    }

    public function inject_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        $data = $this->update_data();
        if (empty($data['update_available']) || empty($data['version']) || empty($data['package'])) {
            return $transient;
        }

        if (version_compare((string) $data['version'], $this->config['version'], '<=')) {
            return $transient;
        }

        $item = (object) [
            'id' => $this->config['product_slug'],
            'slug' => $this->config['product_slug'],
            'plugin' => $this->config['plugin_basename'],
            'new_version' => sanitize_text_field((string) $data['version']),
            'url' => $this->config['server_url'],
            'package' => esc_url_raw((string) $data['package']),
            'requires' => sanitize_text_field((string) ($data['requires'] ?? '')),
            'tested' => sanitize_text_field((string) ($data['tested'] ?? '')),
            'requires_php' => sanitize_text_field((string) ($data['requires_php'] ?? '')),
        ];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[$this->config['plugin_basename']] = $item;
        return $transient;
    }

    public function plugin_information($result, string $action, object $args) {
        if ($action !== 'plugin_information' || (($args->slug ?? '') !== $this->config['product_slug'])) {
            return $result;
        }

        $data = $this->update_data();
        if (!$data) {
            return $result;
        }

        return (object) [
            'name' => $this->config['product_name'],
            'slug' => $this->config['product_slug'],
            'version' => sanitize_text_field((string) ($data['version'] ?? $this->config['version'])),
            'author' => '<a href="https://bizskill.in">BizSkill</a>',
            'homepage' => 'https://bizskill.in',
            'requires' => sanitize_text_field((string) ($data['requires'] ?? '')),
            'tested' => sanitize_text_field((string) ($data['tested'] ?? '')),
            'requires_php' => sanitize_text_field((string) ($data['requires_php'] ?? '')),
            'download_link' => esc_url_raw((string) ($data['package'] ?? '')),
            'sections' => is_array($data['sections'] ?? null) ? $data['sections'] : [],
        ];
    }

    public function action_links(array $links): array {
        $url = $this->admin_page_url();
        array_unshift($links, '<a href="' . esc_url($url) . '">Licence</a>');
        return $links;
    }

    public function admin_notice(): void {
        if (!current_user_can($this->config['settings_capability'])) {
            return;
        }

        $state = $this->state();
        if ($this->is_active($state)) {
            return;
        }

        $url = $this->admin_page_url();
        echo '<div class="notice notice-warning"><p><strong>' . esc_html($this->config['product_name']) . ':</strong> Activate your licence to receive automatic updates. <a href="' . esc_url($url) . '">Activate licence</a></p></div>';
    }
}
