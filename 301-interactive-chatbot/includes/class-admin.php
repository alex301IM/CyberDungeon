<?php
if (!defined('ABSPATH')) exit;

class _301InteractiveBot_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('wp_ajax_301interactivebot_list_chats', [__CLASS__, 'ajax_list_chats']);
        add_action('wp_ajax_301interactivebot_export_vector', [__CLASS__, 'ajax_export_vector']);
        add_action('301interactivebot_weekly_vector_export', [__CLASS__, 'cron_export_vector']);
        add_action('admin_post_301interactivebot_export_report_pdf', [__CLASS__, 'export_ai_report_pdf']);
        add_action('admin_post_301interactivebot_email_report_pdf', [__CLASS__, 'email_ai_report_pdf']);
    }

    public static function admin_menu() {
        add_menu_page(
            '301 Interactive Chatbot',
            '301 Interactive Chatbot',
            'manage_options',
            '301interactivebot',
            [__CLASS__, 'dashboard_page'],
            'dashicons-format-chat',
            56
        );

        add_submenu_page('301interactivebot', 'Dashboard', 'Dashboard', 'manage_options', '301interactivebot', [__CLASS__, 'dashboard_page']);
        add_submenu_page('301interactivebot', 'All Chats', 'All Chats', 'manage_options', '301interactivebot_chats', [__CLASS__, 'chats_page']);
        // Hidden (no menu) chat viewer
        add_submenu_page(null, 'View Chat', 'View Chat', 'manage_options', '301interactivebot_chat_view', [__CLASS__, 'chat_view_page']);
        add_submenu_page('301interactivebot', 'Active Chats', 'Active Chats', 'manage_options', '301interactivebot_active', [__CLASS__, 'active_chats_page']);
        add_submenu_page('301interactivebot', 'Blocked Users', 'Blocked Users', 'manage_options', '301interactivebot_blocked', [__CLASS__, 'blocked_users_page']);
        add_submenu_page('301interactivebot', 'Logs', 'Logs', 'manage_options', '301interactivebot_logs', [__CLASS__, 'logs_page']);
        add_submenu_page('301interactivebot', 'AI Recommendations', 'AI Recommendations', 'manage_options', '301interactivebot_ai_recommendations', [__CLASS__, 'ai_recommendations_page']);
        add_submenu_page('301interactivebot', 'Diagnostics', 'Diagnostics', 'manage_options', '301interactivebot_diagnostics', [__CLASS__, 'diagnostics_page']);
        add_submenu_page('301interactivebot', 'Settings', 'Settings', 'manage_options', '301interactivebot_settings', [__CLASS__, 'settings_page']);
    }

    public static function register_settings() {
        register_setting('301interactivebot_settings_group', '301interactivebot_settings', [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);
    }

    public static function defaults() {
        return [
            'openai_api_key' => '',
            'model' => 'gpt-4.1-mini',
            'vector_store_id' => '',
            'default_admin_email' => get_option('admin_email'),
            'system_prompt' => _301InteractiveBot_DB::default_system_prompt(),
            'faq_json' => '[]',

            // widget
            'autoload' => 1,
            'show_mode' => 'floating',
            'position' => 'bottom-right',
            'offset_x_desktop' => 20,
            'offset_y_desktop' => 20,
            'offset_x_mobile'  => 12,
            'offset_y_mobile'  => 12,
            'mobile_breakpoint' => 768,
            'z_index' => 999999,
            'include_pages_raw' => '',
            'exclude_pages_raw' => '',
            'idle_timeout_seconds' => 300,
            'lead_capture_mode' => 'form',
            'show_recommended_links' => 0,

            // theme/branding
            'primary_color' => '#0b1f3a',
            'accent_color'  => '#2563eb',
            'bubble_color'  => '#2563eb',
            'text_color'    => '#0b1f3a',
            'logo_id' => 0,
            'closed_icon' => 'chat',

            // lead routing
            'service_area_config' => [],
            'build_cities' => [],
            'build_counties' => [],
            'city_email_map' => [],
            'county_email_map' => [],
            'enable_third_party_api' => 0,
        ];
    }

    public static function get_settings() {
        $opt = get_option('301interactivebot_settings', []);
        if (!is_array($opt)) $opt = [];
        return array_merge(self::defaults(), $opt);
    }

    public static function sanitize_settings($input) {
        $out = self::get_settings();
        if (!is_array($input)) return $out;

        $out['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? '');
        $out['model'] = sanitize_text_field($input['model'] ?? $out['model']);
        $out['vector_store_id'] = sanitize_text_field($input['vector_store_id'] ?? '');
        $out['default_admin_email'] = sanitize_text_field($input['default_admin_email'] ?? $out['default_admin_email']);
        $out['system_prompt'] = wp_kses_post($input['system_prompt'] ?? $out['system_prompt']);

        $out['autoload'] = !empty($input['autoload']) ? 1 : 0;

        $mode = sanitize_text_field($input['show_mode'] ?? $out['show_mode']);
        $out['show_mode'] = in_array($mode, ['floating','embedded'], true) ? $mode : 'floating';

        $pos = sanitize_text_field($input['position'] ?? $out['position']);
        $out['position'] = in_array($pos, ['bottom-right','bottom-left','top-right','top-left'], true) ? $pos : 'bottom-right';

        $out['offset_x_desktop'] = max(0, (int)($input['offset_x_desktop'] ?? $out['offset_x_desktop']));
        $out['offset_y_desktop'] = max(0, (int)($input['offset_y_desktop'] ?? $out['offset_y_desktop']));
        $out['offset_x_mobile']  = max(0, (int)($input['offset_x_mobile'] ?? $out['offset_x_mobile']));
        $out['offset_y_mobile']  = max(0, (int)($input['offset_y_mobile'] ?? $out['offset_y_mobile']));
        $out['mobile_breakpoint'] = max(320, (int)($input['mobile_breakpoint'] ?? $out['mobile_breakpoint']));
        $out['z_index'] = max(1, (int)($input['z_index'] ?? $out['z_index']));

        $out['include_pages_raw'] = sanitize_textarea_field($input['include_pages_raw'] ?? $out['include_pages_raw']);
        $out['exclude_pages_raw'] = sanitize_textarea_field($input['exclude_pages_raw'] ?? $out['exclude_pages_raw']);

        $out['idle_timeout_seconds'] = max(60, (int)($input['idle_timeout_seconds'] ?? $out['idle_timeout_seconds']));
        $mode = sanitize_text_field($input['lead_capture_mode'] ?? $out['lead_capture_mode']);
        $out['lead_capture_mode'] = in_array($mode, ['form', 'chat'], true) ? $mode : 'form';
        $out['show_recommended_links'] = !empty($input['show_recommended_links']) ? 1 : 0;
        $out['enable_third_party_api'] = !empty($input['enable_third_party_api']) ? 1 : 0;

        $out['primary_color'] = sanitize_hex_color($input['primary_color'] ?? $out['primary_color']) ?: $out['primary_color'];
        $out['accent_color']  = sanitize_hex_color($input['accent_color'] ?? $out['accent_color']) ?: $out['accent_color'];
        $out['bubble_color']  = sanitize_hex_color($input['bubble_color'] ?? $out['bubble_color']) ?: $out['bubble_color'];
        $out['text_color']    = sanitize_hex_color($input['text_color'] ?? $out['text_color']) ?: $out['text_color'];

        $out['logo_id'] = (int)($input['logo_id'] ?? 0);
        $icon = sanitize_text_field($input['closed_icon'] ?? $out['closed_icon']);
        $out['closed_icon'] = in_array($icon, ['chat','none'], true) ? $icon : 'chat';

        // Service area config (structured UI or JSON fallback)
        $service_area = $input['service_areas'] ?? null;
        if (!is_array($service_area)) {
            $service_area_raw = (string)($input['service_area_config_raw'] ?? '[]');
            $service_area = json_decode($service_area_raw, true);
        }

        $clean_areas = [];
        if (is_array($service_area)) {
            foreach ($service_area as $item) {
                if (!is_array($item)) continue;
                $state = strtoupper(sanitize_text_field($item['state'] ?? ''));

                $counties_input = $item['counties'] ?? [];
                if (is_string($counties_input)) {
                    $counties_input = preg_split('/[\r\n,;]+/', $counties_input);
                }

                $counties = [];
                foreach ((array)$counties_input as $county) {
                    $c = sanitize_text_field($county);
                    if ($c !== '') $counties[] = $c;
                }
                $counties = array_values(array_unique($counties));
                if (!$state || empty($counties)) continue;

                $email_list_input = $item['email-list'] ?? '';
                if (is_array($email_list_input)) {
                    $email_list_input = implode(', ', array_map('sanitize_email', $email_list_input));
                }

                $clean_areas[] = [
                    'state' => $state,
                    'counties' => $counties,
                    'price-list-link' => esc_url_raw($item['price-list-link'] ?? ''),
                    'price-list-name' => sanitize_text_field($item['price-list-name'] ?? ''),
                    'community' => sanitize_text_field((string)($item['community'] ?? '')),
                    'email-list' => sanitize_textarea_field((string)$email_list_input),
                ];
            }
        }

        $out['service_area_config'] = $clean_areas;
        $out['build_counties'] = self::extract_build_counties_from_service_area($clean_areas);
        $out['build_cities'] = $out['build_counties'];

        // Legacy county/city email map is deprecated in favor of service_area_config.
        $out['county_email_map'] = [];
        $out['city_email_map'] = [];

        // FAQ JSON
        $faq_raw = (string)($input['faq_json'] ?? '[]');
        $faq = json_decode($faq_raw, true);
        if (!is_array($faq)) $faq = [];
        $clean = [];
        foreach ($faq as $item) {
            if (!is_array($item)) continue;
            $q = sanitize_text_field($item['q'] ?? '');
            $a = sanitize_textarea_field($item['a'] ?? '');
            if ($q && $a) $clean[] = ['q'=>$q,'a'=>$a];
        }
        $out['faq_json'] = wp_json_encode($clean);

        return $out;
    }

    private static function extract_build_counties_from_service_area($service_areas) {
        $counties = [];
        if (!is_array($service_areas)) return $counties;
        foreach ($service_areas as $item) {
            foreach ((array)($item['counties'] ?? []) as $county) {
                $county = sanitize_text_field($county);
                if ($county !== '') $counties[$county] = true;
            }
        }
        return array_keys($counties);
    }

    public static function get_service_area_config($settings = null) {
        if ($settings === null) $settings = self::get_settings();
        $areas = $settings['service_area_config'] ?? [];
        if (!is_array($areas)) $areas = [];
        if (!empty($areas)) return $areas;

        // Backward-compatible fallback for legacy installs.
        $legacy_counties = (array)($settings['build_counties'] ?? $settings['build_cities'] ?? []);
        $legacy_counties = array_values(array_filter(array_map('sanitize_text_field', $legacy_counties)));
        if (empty($legacy_counties)) return [];
        return [[
            'state' => '',
            'counties' => $legacy_counties,
            'price-list-link' => '',
            'price-list-name' => '',
            'community' => '',
            'email-list' => '',
        ]];
    }

    public static function find_service_area_by_county($county, $state = '', $settings = null) {
        $county = sanitize_text_field($county);
        $state = strtoupper(sanitize_text_field($state));
        if ($county === '') return null;

        $areas = self::get_service_area_config($settings);
        $fallback = null;
        foreach ($areas as $area) {
            $area_state = strtoupper((string)($area['state'] ?? ''));
            $county_list = array_map('sanitize_text_field', (array)($area['counties'] ?? []));
            if (!in_array($county, $county_list, true)) continue;
            if ($state && $state === $area_state) return $area;
            if ($fallback === null) $fallback = $area;
        }
        return $fallback;
    }

    public static function admin_assets($hook) {
        if (strpos($hook, '301interactivebot') === false) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_media();
        wp_enqueue_style('301interactivebot-admin', _301INTERACTIVEBOT_PLUGIN_URL . 'assets/admin.css', [], _301INTERACTIVEBOT_VERSION);
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script('301interactivebot-admin', _301INTERACTIVEBOT_PLUGIN_URL . 'assets/admin.js', ['jquery','wp-color-picker'], _301INTERACTIVEBOT_VERSION, true);
        wp_localize_script('301interactivebot-admin', '_301InteractiveBotAdmin', [
            'restBase' => esc_url_raw(rest_url('301interactivebot/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'exportNonce' => wp_create_nonce('301interactivebot_export_vector'),
        ]);
    }

    public static function should_show_on_page($settings = null) {
        if ($settings === null) $settings = self::get_settings();

        $include_raw = (string)($settings['include_pages_raw'] ?? '');
        $exclude_raw = (string)($settings['exclude_pages_raw'] ?? '');

        $include = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $include_raw)));
        $exclude = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $exclude_raw)));

        $current_url  = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '/'));
        $current_path = wp_parse_url($current_url, PHP_URL_PATH) ?: '';
        $post_id = get_queried_object_id();
        $slug = $post_id ? get_post_field('post_name', $post_id) : '';

        $matches = function($rule) use ($current_url, $current_path, $post_id, $slug) {
            if ($rule === '') return false;
            if (strpos($rule, 'id:') === 0) return ((int)substr($rule,3) === (int)$post_id);
            if (strpos($rule, 'slug:') === 0) return (sanitize_title(substr($rule,5)) === $slug);
            if (strpos($rule, 'url:') === 0) {
                $u = trim(substr($rule,4));
                return ($u !== '' && stripos($current_url, $u) !== false);
            }
            return ($rule !== '' && stripos($current_path, $rule) !== false);
        };

        foreach ($exclude as $r) { if ($matches($r)) return false; }
        if (empty($include)) return true;
        foreach ($include as $r) { if ($matches($r)) return true; }
        return false;
    }

    public static function dashboard_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $chats  = $wpdb->prefix . '301interactivebot_chats';
        $events = $wpdb->prefix . '301interactivebot_events';

        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $chats");
        $today = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $chats WHERE started_at >= %s", gmdate('Y-m-d 00:00:00')));
        $week  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $chats WHERE started_at >= %s", gmdate('Y-m-d 00:00:00', strtotime('-6 days'))));
        $active= (int)$wpdb->get_var("SELECT COUNT(*) FROM $chats WHERE status='active'");
        $leads = (int)$wpdb->get_var("SELECT COUNT(*) FROM $chats WHERE lead_email IS NOT NULL AND lead_email <> ''");

        $top_cities = $wpdb->get_results("SELECT COALESCE(NULLIF(build_county,''), NULLIF(build_city,''),'(unknown)') AS city, COUNT(*) AS c FROM $chats GROUP BY city ORDER BY c DESC LIMIT 8", ARRAY_A);
        $top_ref = $wpdb->get_results("SELECT COALESCE(NULLIF(referrer,''),'(unknown)') AS ref, COUNT(*) AS c FROM $chats GROUP BY ref ORDER BY c DESC LIMIT 8", ARRAY_A);

        $recent = $wpdb->get_results("SELECT id, status, COALESCE(NULLIF(build_county,''), NULLIF(build_city,'')) AS build_county, build_state, lead_email, COALESCE(ended_at, started_at) AS updated_at FROM $chats ORDER BY id DESC LIMIT 10", ARRAY_A);
        ?>
        <div class="wrap">
          <h1>301 Interactive Chatbot Dashboard</h1>

          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
            <?php self::kpi('Total Chats', $total); ?>
            <?php self::kpi('Chats Today', $today); ?>
            <?php self::kpi('Chats (7 days)', $week); ?>
            <?php self::kpi('Active Now', $active); ?>
            <?php self::kpi('Leads Captured', $leads); ?>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:18px;max-width:1100px">
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px">
              <h2 style="margin:0 0 10px">Top Build Counties</h2>
              <table class="widefat striped">
                <thead><tr><th>County</th><th style="width:90px">Chats</th></tr></thead>
                <tbody>
                  <?php foreach ($top_cities as $r): ?>
                    <tr><td><?php echo esc_html($r['city']); ?></td><td><?php echo (int)$r['c']; ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px">
              <h2 style="margin:0 0 10px">Top Referrers</h2>
              <table class="widefat striped">
                <thead><tr><th>Referrer</th><th style="width:90px">Chats</th></tr></thead>
                <tbody>
                  <?php foreach ($top_ref as $r): ?>
                    <tr><td style="word-break:break-word"><?php echo esc_html($r['ref']); ?></td><td><?php echo (int)$r['c']; ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-top:16px;max-width:1100px">
            <h2 style="margin:0 0 10px">Recent Chats</h2>
            <table class="widefat striped">
              <thead><tr><th>ID</th><th>Status</th><th>Build County</th><th>State</th><th>Email</th><th>Updated</th></tr></thead>
              <tbody>
                <?php foreach ($recent as $r): ?>
                  <tr>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=301interactivebot_chat_view&chat_id='.(int)$r['id'])); ?>"><?php echo (int)$r['id']; ?></a></td>
                    <td><?php echo esc_html($r['status']); ?></td>
                    <td><?php echo esc_html($r['build_county']); ?></td>
                    <td><?php echo esc_html($r['build_state']); ?></td>
                    <td><?php echo esc_html($r['lead_email']); ?></td>
                    <td><?php echo esc_html($r['updated_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
    }

    private static function kpi($label, $value) {
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;min-width:180px">
          <div style="font-size:12px;color:#6b7280"><?php echo esc_html($label); ?></div>
          <div style="font-size:28px;font-weight:700;margin-top:6px"><?php echo esc_html($value); ?></div>
        </div>
        <?php
    }

    public static function chats_page() { self::render_chats_table(false); }
    public static function active_chats_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
          <h1>Active Chats</h1>
          <p>Select a chat on the left to view messages. Use Takeover to reply as an admin.</p>

          <div class="301interactivebot-live-grid">
            <div class="301interactivebot-live-list" id="301interactivebot-chat-list"></div>

            <div class="301interactivebot-live-panel">
              <div class="301interactivebot-live-panel-head">
                <div id="301interactivebot-chat-title" class="301interactivebot-live-title">Select a chat</div>
                <div class="301interactivebot-live-actions">
                  <button class="button" id="301interactivebot-takeover" disabled>Takeover</button>
                  <button class="button" id="301interactivebot-release" disabled>Release</button>
                  <button class="button" id="301interactivebot-endchat" disabled>End Chat</button>
                  <button class="button" id="301interactivebot-block" disabled>Block User</button>
                </div>
              </div>

              <div id="301interactivebot-chat-summary" class="301interactivebot-live-summary" style="margin-bottom:12px;"></div>
              <div id="301interactivebot-chat-pages" class="301interactivebot-live-summary" style="margin-bottom:12px;"></div>
              <div id="301interactivebot-chat-messages" class="301interactivebot-live-messages"></div>

              <div class="301interactivebot-live-compose">
                <input type="text" id="301interactivebot-admin-input" class="regular-text" placeholder="Type a message as admin..." disabled />
                <button class="button button-primary" id="301interactivebot-admin-send" disabled>Send</button>
              </div>
            </div>
          </div>
        </div>
        <?php
    }

    public static function chat_view_page() {
        if (!current_user_can('manage_options')) return;
        $chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
        ?>
        <div class="wrap">
          <h1>Chat Viewer</h1>
          <p><a href="<?php echo esc_url(admin_url('admin.php?page=301interactivebot_chats')); ?>">&larr; Back to All Chats</a></p>

          <div id="301interactivebot-live" class="301interactivebot-live">
            <div class="301interactivebot-live-left" style="display:none">
              <div class="301interactivebot-live-list" id="301interactivebot-chat-list"></div>
            </div>

            <div class="301interactivebot-live-right">
              <div class="301interactivebot-live-top">
                <div>
                  <div id="301interactivebot-chat-title">Chat #<?php echo (int)$chat_id; ?></div>
                  <div id="301interactivebot-chat-meta" style="margin-top:4px;color:#6b7280"></div>
                </div>
                <div class="301interactivebot-live-actions">
                  <button class="button" id="301interactivebot-takeover">Takeover</button>
                  <button class="button" id="301interactivebot-release">Release</button>
                  <button class="button" id="301interactivebot-endchat">End Chat</button>
                  <button class="button" id="301interactivebot-resend-transcript">Resend Transcript</button>
                  <button class="button" id="301interactivebot-block">Block</button>
                </div>
              </div>

              <div id="301interactivebot-chat-summary" class="301interactivebot-live-summary" style="margin-bottom:12px;"></div>
              <div id="301interactivebot-chat-pages" class="301interactivebot-live-summary" style="margin-bottom:12px;"></div>
              <div id="301interactivebot-chat-messages" class="301interactivebot-live-messages"></div>

              <div class="301interactivebot-live-compose">
                <input type="text" id="301interactivebot-admin-input" class="regular-text" placeholder="Reply (requires takeover)..." />
                <button class="button button-primary" id="301interactivebot-admin-send">Send</button>
              </div>
              <p class="description">Transcript is read-only unless you click <strong>Takeover</strong>.</p>
            </div>
          </div>
        </div>

        <script>
          window._301InteractiveBotAdmin = window._301InteractiveBotAdmin || {};
          window._301InteractiveBotAdmin.initialChatId = <?php echo (int)$chat_id; ?>;
        </script>
        <?php
    }


    private static function render_chats_table($only_active) {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $where = $only_active ? "WHERE status='active'" : "";
        $rows = $wpdb->get_results(
            "SELECT id, status, COALESCE(NULLIF(build_county,''), NULLIF(build_city,'')) AS build_county, build_state, lead_email, lead_phone, started_at, COALESCE(ended_at, started_at) AS updated_at
             FROM $chats $where ORDER BY id DESC LIMIT 200",
            ARRAY_A
        );
        ?>
        <div class="wrap">
          <h1><?php echo $only_active ? 'Active Chats' : 'All Chats'; ?></h1>
          <table class="widefat striped">
            <thead><tr>
              <th>ID</th><th>Status</th><th>Build County</th><th>State</th><th>Email</th><th>Phone</th><th>Created</th><th>Updated</th>
            </tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><a href="<?php echo esc_url(admin_url('admin.php?page=301interactivebot_chat_view&chat_id='.(int)$r['id'])); ?>"><?php echo (int)$r['id']; ?></a></td>
                  <td><?php echo esc_html($r['status']); ?></td>
                  <td><?php echo esc_html($r['build_county']); ?></td>
                  <td><?php echo esc_html($r['build_state']); ?></td>
                  <td><?php echo esc_html($r['lead_email']); ?></td>
                  <td><?php echo esc_html($r['lead_phone']); ?></td>
                  <td><?php echo esc_html($r['started_at']); ?></td>
                  <td><?php echo esc_html($r['updated_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
    }


    public static function ai_recommendations_page() {
        if (!current_user_can('manage_options')) return;

        $email_status = sanitize_key(wp_unslash($_GET['email_status'] ?? ''));
        $email_count = max(0, (int)($_GET['email_count'] ?? 0));

        $today = current_time('Y-m-d');
        $default_start = gmdate('Y-m-d', strtotime('-30 days', current_time('timestamp', true)));
        $date_start = sanitize_text_field(wp_unslash($_POST['date_start'] ?? ($_GET['date_start'] ?? $default_start)));
        $date_end = sanitize_text_field(wp_unslash($_POST['date_end'] ?? ($_GET['date_end'] ?? $today)));
        $extra_instruction = sanitize_textarea_field(wp_unslash($_POST['extra_instruction'] ?? ''));
        $result = null;
        $error = '';
        $saved_report_id = 0;

        if (!empty($_GET['report_id'])) {
            $loaded = self::get_ai_saved_report((int)$_GET['report_id']);
            if ($loaded) {
                $saved_report_id = (int)$loaded['id'];
                $date_start = $loaded['date_start'];
                $date_end = $loaded['date_end'];
                $extra_instruction = (string)($loaded['extra_instruction'] ?? '');
                $result = $loaded['report_json'];
            }
        }

        if (!empty($_POST['301interactivebot_run_ai_recommendations'])) {
            check_admin_referer('301interactivebot_ai_recommendations');
            if (!$date_start || !$date_end) {
                $error = 'Please provide both a start date and end date.';
            } elseif ($date_start > $date_end) {
                $error = 'Start date must be before or equal to end date.';
            } else {
                $dataset = self::build_ai_recommendation_dataset($date_start, $date_end);
                $ai_result = _301InteractiveBot_OpenAI::analyze_chat_recommendations($dataset, $extra_instruction, self::get_settings());
                if (is_wp_error($ai_result)) {
                    $error = $ai_result->get_error_message();
                } else {
                    $result = [
                        'summary' => (string)($ai_result['summary'] ?? ''),
                        'themes' => (array)($ai_result['themes'] ?? []),
                        'recommended_faqs' => (array)($ai_result['recommended_faqs'] ?? []),
                        'lead_summary' => self::build_lead_chat_summary($date_start, $date_end),
                        'meta' => [
                            'date_start' => $date_start,
                            'date_end' => $date_end,
                            'chat_count' => (int)($dataset['chat_count'] ?? 0),
                        ],
                    ];
                    $saved_report_id = 0;
                }
            }
        }

        if (!empty($_POST['301interactivebot_save_ai_report'])) {
            check_admin_referer('301interactivebot_ai_recommendations');
            $payload = json_decode(wp_unslash($_POST['report_payload'] ?? ''), true);
            if (!is_array($payload)) {
                $error = 'Could not save this report payload.';
            } else {
                $save_id = self::save_ai_report($date_start, $date_end, $extra_instruction, $payload);
                if ($save_id > 0) {
                    $saved_report_id = $save_id;
                    $result = $payload;
                } else {
                    $error = 'Could not save this report. Please try again.';
                }
            }
        }

        $saved_reports = self::list_ai_saved_reports(30);
        $settings = self::get_settings();
        $logo_url = '';
        if (!empty($settings['logo_id'])) {
            $logo_url = wp_get_attachment_image_url((int)$settings['logo_id'], 'medium');
        }
        ?>
        <div class="wrap">
          <h1>AI Recommendations</h1>
          <p>Analyze chatbot conversations from a date range and get actionable FAQ recommendations.</p>

          <?php if ($email_status === 'sent'): ?>
            <div class="notice notice-success is-dismissible"><p>Report email sent to <?php echo (int)$email_count; ?> recipient(s).</p></div>
          <?php elseif ($email_status === 'failed'): ?>
            <div class="notice notice-error is-dismissible"><p>Unable to send the report email. Please verify the email addresses and mail settings, then try again.</p></div>
          <?php endif; ?>

          <form method="post" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;max-width:980px;">
            <?php wp_nonce_field('301interactivebot_ai_recommendations'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="301interactivebot-date-start">Start Date</label></th>
                <td><input type="date" id="301interactivebot-date-start" name="date_start" value="<?php echo esc_attr($date_start); ?>" required /></td>
              </tr>
              <tr>
                <th scope="row"><label for="301interactivebot-date-end">End Date</label></th>
                <td><input type="date" id="301interactivebot-date-end" name="date_end" value="<?php echo esc_attr($date_end); ?>" required /></td>
              </tr>
              <tr>
                <th scope="row"><label for="301interactivebot-extra-instruction">Optional Instruction</label></th>
                <td>
                  <textarea id="301interactivebot-extra-instruction" name="extra_instruction" rows="4" class="large-text" placeholder="Example: focus on pricing and financing confusion."><?php echo esc_textarea($extra_instruction); ?></textarea>
                </td>
              </tr>
            </table>
            <p><button type="submit" class="button button-primary" name="301interactivebot_run_ai_recommendations" value="1">Generate Recommendations</button></p>
          </form>

          <div style="margin-top:14px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;max-width:980px;">
            <h2 style="margin-top:0;">Saved Reports</h2>
            <?php if (!empty($saved_reports)): ?>
              <ul>
                <?php foreach ($saved_reports as $r): ?>
                  <li>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=301interactivebot_ai_recommendations&report_id=' . (int)$r['id'])); ?>">Report #<?php echo (int)$r['id']; ?></a>
                    — <?php echo esc_html($r['date_start']); ?> to <?php echo esc_html($r['date_end']); ?>
                    (<?php echo esc_html($r['created_at']); ?>)
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p>No saved reports yet.</p>
            <?php endif; ?>
          </div>

          <?php if ($error): ?>
            <div class="notice notice-error" style="margin-top:14px;"><p><?php echo esc_html($error); ?></p></div>
          <?php endif; ?>

          <?php if (is_array($result)): ?>
            <div style="margin-top:16px;max-width:1100px;display:grid;gap:16px;">
              <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
                <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" alt="Logo" style="max-height:56px;margin-bottom:8px;" /><?php endif; ?>
                <div style="font-size:13px;color:#64748b;margin-bottom:8px;">Date Range: <?php echo esc_html($date_start); ?> to <?php echo esc_html($date_end); ?></div>
                <h2 style="margin-top:0;">Analytical Summary</h2>
                <div style="white-space:pre-wrap;line-height:1.45;"><?php echo esc_html((string)($result['summary'] ?? '')); ?></div>
              </div>

              <?php if (!empty($result['themes']) && is_array($result['themes'])): ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
                  <h2 style="margin-top:0;">Key Themes</h2>
                  <ul><?php foreach ($result['themes'] as $theme): ?><li><?php echo esc_html((string)$theme); ?></li><?php endforeach; ?></ul>
                </div>
              <?php endif; ?>

              <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
                <h2 style="margin-top:0;">Recommended New FAQs</h2>
                <table class="widefat striped">
                  <thead><tr><th style="width:34%;">Question</th><th>Suggested Answer</th><th style="width:28%;">Why This Helps</th></tr></thead>
                  <tbody>
                    <?php foreach ((array)($result['recommended_faqs'] ?? []) as $faq): ?>
                      <tr>
                        <td><?php echo esc_html((string)($faq['question'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($faq['answer'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($faq['rationale'] ?? '')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
                <h2 style="margin-top:0;">Chats With Lead Information + Community</h2>
                <table class="widefat striped">
                  <thead><tr><th>Chat ID</th><th>Started</th><th>Name</th><th>Email</th><th>County/State</th><th>Community</th></tr></thead>
                  <tbody>
                    <?php foreach ((array)($result['lead_summary'] ?? []) as $lead): ?>
                      <tr>
                        <td><?php echo (int)($lead['chat_id'] ?? 0); ?></td>
                        <td><?php echo esc_html((string)($lead['started_at'] ?? '')); ?></td>
                        <td><?php echo esc_html(trim(((string)($lead['lead_first'] ?? '')) . ' ' . ((string)($lead['lead_last'] ?? '')))); ?></td>
                        <td><?php echo esc_html((string)($lead['lead_email'] ?? '')); ?></td>
                        <td><?php echo esc_html(trim(((string)($lead['build_county'] ?? '')) . ' ' . ((string)($lead['build_state'] ?? '')))); ?></td>
                        <td><?php echo esc_html((string)($lead['community'] ?? '')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div style="display:flex;gap:10px;">
                <?php if (!$saved_report_id): ?>
                  <form method="post">
                    <?php wp_nonce_field('301interactivebot_ai_recommendations'); ?>
                    <input type="hidden" name="date_start" value="<?php echo esc_attr($date_start); ?>" />
                    <input type="hidden" name="date_end" value="<?php echo esc_attr($date_end); ?>" />
                    <input type="hidden" name="extra_instruction" value="<?php echo esc_attr($extra_instruction); ?>" />
                    <input type="hidden" name="report_payload" value="<?php echo esc_attr(wp_json_encode($result)); ?>" />
                    <button type="submit" class="button button-primary" name="301interactivebot_save_ai_report" value="1">Save Report</button>
                  </form>
                <?php endif; ?>

                <?php if ($saved_report_id): ?>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('301interactivebot_export_report_pdf'); ?>
                    <input type="hidden" name="action" value="301interactivebot_export_report_pdf" />
                    <input type="hidden" name="report_id" value="<?php echo (int)$saved_report_id; ?>" />
                    <button type="submit" class="button">Export to PDF</button>
                  </form>

                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;align-items:center;gap:8px;">
                    <?php wp_nonce_field('301interactivebot_email_report_pdf'); ?>
                    <input type="hidden" name="action" value="301interactivebot_email_report_pdf" />
                    <input type="hidden" name="report_id" value="<?php echo (int)$saved_report_id; ?>" />
                    <label for="301interactivebot-report-emails" class="screen-reader-text">Email recipients</label>
                    <input
                      type="text"
                      id="301interactivebot-report-emails"
                      name="recipient_emails"
                      class="regular-text"
                      placeholder="name@example.com, second@example.com"
                      required
                    />
                    <button type="submit" class="button">Email PDF</button>
                  </form>
                <?php else: ?>
                  <p class="description">Save the report first to enable PDF export.</p>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    private static function build_lead_chat_summary($date_start, $date_end) {
        global $wpdb;
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $start = gmdate('Y-m-d H:i:s', strtotime($date_start . ' 00:00:00'));
        $end = gmdate('Y-m-d H:i:s', strtotime($date_end . ' 23:59:59'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, started_at, lead_first, lead_last, lead_email, build_county, build_state
             FROM $chats
             WHERE started_at BETWEEN %s AND %s AND lead_email IS NOT NULL AND lead_email <> ''
             ORDER BY id DESC LIMIT 500",
             $start,
             $end
        ), ARRAY_A);

        $settings = self::get_settings();
        foreach ($rows as &$r) {
            $area = self::find_service_area_by_county((string)($r['build_county'] ?? ''), (string)($r['build_state'] ?? ''), $settings);
            $r['community'] = sanitize_text_field((string)($area['community'] ?? ''));
            $r['chat_id'] = (int)($r['id'] ?? 0);
        }
        return $rows;
    }

    private static function save_ai_report($date_start, $date_end, $extra_instruction, $payload) {
        global $wpdb;
        $table = $wpdb->prefix . '301interactivebot_reports';
        $ok = $wpdb->insert($table, [
            'report_type' => 'ai_recommendations',
            'date_start' => sanitize_text_field($date_start),
            'date_end' => sanitize_text_field($date_end),
            'extra_instruction' => sanitize_textarea_field($extra_instruction),
            'report_json' => wp_json_encode($payload),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        return $ok ? (int)$wpdb->insert_id : 0;
    }

    private static function get_ai_saved_report($id) {
        global $wpdb;
        $table = $wpdb->prefix . '301interactivebot_reports';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND report_type=%s", (int)$id, 'ai_recommendations'), ARRAY_A);
        if (!$row) return null;
        $row['report_json'] = json_decode((string)($row['report_json'] ?? ''), true);
        if (!is_array($row['report_json'])) $row['report_json'] = null;
        return $row;
    }

    private static function list_ai_saved_reports($limit = 30) {
        global $wpdb;
        $table = $wpdb->prefix . '301interactivebot_reports';
        $limit = max(1, (int)$limit);
        return $wpdb->get_results($wpdb->prepare("SELECT id, date_start, date_end, created_at FROM $table WHERE report_type=%s ORDER BY id DESC LIMIT %d", 'ai_recommendations', $limit), ARRAY_A);
    }

    public static function export_ai_report_pdf() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('301interactivebot_export_report_pdf');

        $report = self::get_ai_saved_report((int)($_POST['report_id'] ?? 0));
        if (!$report || !is_array($report['report_json'])) wp_die('Report not found.');

        $settings = self::get_settings();
        $logo_path = '';
        if (!empty($settings['logo_id'])) {
            $file = get_attached_file((int)$settings['logo_id']);
            if (is_string($file) && $file !== '') $logo_path = $file;
        }

        $pdf = self::simple_text_pdf($report, $report['report_json'], $logo_path);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="301interactivebot-report-' . (int)$report['id'] . '.pdf"');
        echo $pdf;
        exit;
    }

    public static function email_ai_report_pdf() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('301interactivebot_email_report_pdf');

        $report = self::get_ai_saved_report((int)($_POST['report_id'] ?? 0));
        if (!$report || !is_array($report['report_json'])) wp_die('Report not found.');

        $emails = self::parse_email_recipients(wp_unslash($_POST['recipient_emails'] ?? ''));
        if (empty($emails)) {
            self::redirect_ai_page_with_email_status('failed');
        }

        $settings = self::get_settings();
        $logo_path = '';
        if (!empty($settings['logo_id'])) {
            $file = get_attached_file((int)$settings['logo_id']);
            if (is_string($file) && $file !== '') $logo_path = $file;
        }

        $pdf = self::simple_text_pdf($report, $report['report_json'], $logo_path);
        $tmp_file = wp_tempnam('301interactivebot-report-' . (int)$report['id'] . '.pdf');
        if (!$tmp_file) {
            self::redirect_ai_page_with_email_status('failed');
        }
        file_put_contents($tmp_file, $pdf);

        $logo_url = '';
        if (!empty($settings['logo_id'])) {
            $logo_url = wp_get_attachment_image_url((int)$settings['logo_id'], 'medium');
        }

        $date_range = trim((string)$report['date_start']) . ' to ' . trim((string)$report['date_end']);
        $summary_html = nl2br(esc_html((string)($report['report_json']['summary'] ?? '')));
        $logo_html = $logo_url ? '<div style="text-align:center;margin:0 0 16px;"><img src="' . esc_url($logo_url) . '" alt="Logo" style="max-width:220px;height:auto;" /></div>' : '';

        $subject = sprintf('AI Report PDF: %s', $date_range);
        $body = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827;max-width:700px;">'
            . $logo_html
            . '<p><strong>Date range:</strong> ' . esc_html($date_range) . '</p>'
            . '<p><strong>Analytical summary</strong></p>'
            . '<div style="white-space:normal;">' . $summary_html . '</div>'
            . '<p style="margin-top:16px;">See attached PDF for full report.</p>'
            . '</div>';

        $sent = wp_mail($emails, $subject, $body, ['Content-Type: text/html; charset=UTF-8'], [$tmp_file]);
        @unlink($tmp_file);

        self::redirect_ai_page_with_email_status($sent ? 'sent' : 'failed', count($emails));
    }

    private static function parse_email_recipients($raw) {
        $parts = preg_split('/\s*,\s*/', (string)$raw);
        if (!is_array($parts)) return [];
        $emails = [];
        foreach ($parts as $entry) {
            $email = sanitize_email((string)$entry);
            if ($email !== '' && is_email($email)) {
                $emails[$email] = $email;
            }
        }
        return array_values($emails);
    }

    private static function redirect_ai_page_with_email_status($status, $count = 0) {
        $url = add_query_arg([
            'page' => '301interactivebot_ai_recommendations',
            'email_status' => sanitize_key((string)$status),
            'email_count' => max(0, (int)$count),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function pdf_escape($text) {
        $text = trim(wp_strip_all_tags((string)$text));
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return $text;
    }

    private static function pdf_wrap_lines($text, $max = 95) {
        $plain = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$text)));
        if ($plain === '') return [''];
        $wrapped = wordwrap($plain, max(20, (int)$max), "\n", true);
        return explode("\n", $wrapped);
    }

    private static function pdf_logo_jpeg_data($path) {
        $path = (string)$path;
        if ($path === '' || !file_exists($path)) return null;

        $info = @getimagesize($path);
        if (!$info || empty($info[2])) return null;
        $type = (int)$info[2];
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        if ($w < 1 || $h < 1) return null;

        // JPEG direct embed.
        if ($type === IMAGETYPE_JPEG) {
            $bytes = @file_get_contents($path);
            if (!is_string($bytes) || $bytes === '') return null;
            return ['data' => $bytes, 'w' => $w, 'h' => $h];
        }

        // PNG/GIF/WebP fallback: convert to JPEG if GD is available.
        if (!function_exists('imagejpeg')) return null;

        $img = null;
        if ($type === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($path);
        } elseif ($type === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) {
            $img = @imagecreatefromgif($path);
        } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($path);
        }
        if (!$img) return null;

        $tmp = fopen('php://temp', 'r+');
        if (!$tmp) {
            imagedestroy($img);
            return null;
        }
        imagejpeg($img, $tmp, 88);
        imagedestroy($img);
        rewind($tmp);
        $bytes = stream_get_contents($tmp);
        fclose($tmp);
        if (!is_string($bytes) || $bytes === '') return null;

        return ['data' => $bytes, 'w' => $w, 'h' => $h];
    }

    private static function simple_text_pdf($report, $data, $logo_path = '') {
        $page_height = 842;
        $top_y = 790;
        $bottom_y = 48;

        $pages = [[]];
        $page_idx = 0;
        $y = $top_y;

        $new_page = function() use (&$pages, &$page_idx, &$y, $top_y) {
            $pages[] = [];
            $page_idx = count($pages) - 1;
            $y = $top_y;
        };

        $line = function($text, $size = 10, $x = 40) use (&$pages, &$page_idx, &$y, $bottom_y, $new_page) {
            $step = ($size >= 14 ? 20 : 14);
            if ($y < ($bottom_y + $step)) {
                $new_page();
            }
            $pages[$page_idx][] = sprintf('BT /F1 %d Tf 0 g 1 0 0 1 %d %d Tm (%s) Tj ET', (int)$size, (int)$x, (int)$y, self::pdf_escape($text));
            $y -= $step;
        };

        $line_center = function($text, $size = 10) use ($line) {
            $txt = (string)$text;
            $approx_char_width = $size * 0.52;
            $width = strlen($txt) * $approx_char_width;
            $x = max(40, (int)round((612 - $width) / 2));
            $line($txt, $size, $x);
        };

        $section = function($title) use (&$pages, &$page_idx, &$y, $bottom_y, $new_page, $line) {
            if ($y < ($bottom_y + 42)) {
                $new_page();
            }
            $pages[$page_idx][] = sprintf('0.95 0.97 1 rg 35 %d 542 22 re f 0 g', (int)($y - 4));
            $line($title, 12, 42);
            $y -= 4; // extra spacing after section headers
        };

        $lead_cols = [
            ['label' => 'Chat ID', 'x' => 40,  'w' => 50,  'max' => 7],
            ['label' => 'Started', 'x' => 90,  'w' => 110, 'max' => 19],
            ['label' => 'Name', 'x' => 200, 'w' => 100, 'max' => 22],
            ['label' => 'Email', 'x' => 300, 'w' => 140, 'max' => 32],
            ['label' => 'County/State', 'x' => 440, 'w' => 95, 'max' => 20],
            ['label' => 'Community', 'x' => 535, 'w' => 37, 'max' => 12],
        ];

        $draw_lead_header = function() use (&$pages, &$page_idx, &$y, $bottom_y, $new_page, $lead_cols) {
            if ($y < ($bottom_y + 40)) {
                $new_page();
            }
            $row_h = 16;
            $rect_y = (int)($y - $row_h + 2);
            $pages[$page_idx][] = sprintf('0.92 0.95 1 rg 40 %d 532 %d re f 0 g', $rect_y, $row_h);
            $pages[$page_idx][] = sprintf('40 %d 532 %d re S', $rect_y, $row_h);
            foreach ($lead_cols as $idx => $col) {
                if ($idx > 0) {
                    $pages[$page_idx][] = sprintf('%d %d m %d %d l S', (int)$col['x'], $rect_y, (int)$col['x'], $rect_y + $row_h);
                }
                $pages[$page_idx][] = sprintf('BT /F1 8 Tf 0 g 1 0 0 1 %d %d Tm (%s) Tj ET', (int)$col['x'] + 2, $rect_y + 5, self::pdf_escape($col['label']));
            }
            $y -= ($row_h + 4);
        };

        $draw_lead_row = function($row) use (&$pages, &$page_idx, &$y, $bottom_y, $new_page, $lead_cols, $draw_lead_header) {
            if ($y < ($bottom_y + 24)) {
                $new_page();
                $draw_lead_header();
            }

            $name = trim(((string)($row['lead_first'] ?? '')) . ' ' . ((string)($row['lead_last'] ?? '')));
            $county = trim(((string)($row['build_county'] ?? '')) . ' ' . ((string)($row['build_state'] ?? '')));

            $values = [
                (string)((int)($row['chat_id'] ?? 0)),
                (string)($row['started_at'] ?? ''),
                $name,
                (string)($row['lead_email'] ?? ''),
                $county,
                (string)($row['community'] ?? ''),
            ];

            $row_h = 16;
            $rect_y = (int)($y - $row_h + 2);
            $pages[$page_idx][] = sprintf('40 %d 532 %d re S', $rect_y, $row_h);
            foreach ($lead_cols as $idx => $col) {
                if ($idx > 0) {
                    $pages[$page_idx][] = sprintf('%d %d m %d %d l S', (int)$col['x'], $rect_y, (int)$col['x'], $rect_y + $row_h);
                }
                $text = substr((string)$values[$idx], 0, (int)$col['max']);
                $pages[$page_idx][] = sprintf('BT /F1 8 Tf 0 g 1 0 0 1 %d %d Tm (%s) Tj ET', (int)$col['x'] + 2, $rect_y + 5, self::pdf_escape($text));
            }
            $y -= ($row_h + 2);
        };

        $logo = self::pdf_logo_jpeg_data($logo_path);
        $logo_ops = '';
        if (is_array($logo) && !empty($logo['data'])) {
            $max_w = 220.0;
            $max_h = 70.0;
            $scale = min($max_w / $logo['w'], $max_h / $logo['h']);
            $draw_w = max(1.0, $logo['w'] * $scale);
            $draw_h = max(1.0, $logo['h'] * $scale);
            $x = (612.0 - $draw_w) / 2.0;
            $y_img = $y - $draw_h;
            $logo_ops = sprintf("q %.3F 0 0 %.3F %.3F %.3F cm /Im1 Do Q", $draw_w, $draw_h, $x, $y_img);
            $y = $y_img - 16;
        }

        $date_label = (string)($report['date_start'] ?? '') . ' to ' . (string)($report['date_end'] ?? '');
        $start_ts = strtotime((string)($report['date_start'] ?? ''));
        $end_ts = strtotime((string)($report['date_end'] ?? ''));
        if ($start_ts && $end_ts) {
            $date_label = gmdate('n/j/Y', $start_ts) . ' to ' . gmdate('n/j/Y', $end_ts);
        }

        $line_center('301 Interactive - AI Recommendations Report', 16);
        $line_center($date_label, 10);
        $line('Report ID: #' . (int)($report['id'] ?? 0) . '   Generated: ' . (string)($report['created_at'] ?? ''), 9, 40);
        $y -= 8;

        $section('Analytical Summary');
        foreach (self::pdf_wrap_lines((string)($data['summary'] ?? ''), 98) as $row) {
            $line($row, 10, 44);
        }
        $y -= 8;

        if (!empty($data['themes']) && is_array($data['themes'])) {
            $section('Key Themes');
            foreach ($data['themes'] as $t) {
                foreach (self::pdf_wrap_lines('- ' . (string)$t, 96) as $row) {
                    $line($row, 10, 44);
                }
            }
            $y -= 8;
        }

        if (!empty($data['recommended_faqs']) && is_array($data['recommended_faqs'])) {
            $section('Recommended New FAQs');
            foreach ($data['recommended_faqs'] as $faq) {
                $q = (string)($faq['question'] ?? '');
                $a = (string)($faq['answer'] ?? '');
                $r = (string)($faq['rationale'] ?? '');
                $line('Q: ' . $q, 10, 44);
                foreach (self::pdf_wrap_lines('A: ' . $a, 96) as $row) {
                    $line($row, 9, 54);
                }
                foreach (self::pdf_wrap_lines('Why: ' . $r, 96) as $row) {
                    $line($row, 9, 54);
                }
                $y -= 6;
            }
            $y -= 8;
        }

        if (!empty($data['lead_summary']) && is_array($data['lead_summary'])) {
            $section('Chats With Lead Information + Community');
            $draw_lead_header();
            foreach ($data['lead_summary'] as $row) {
                $draw_lead_row($row);
            }
            $y -= 6;
        }

        if ($logo_ops !== '') {
            $pages[0] = array_merge([$logo_ops], $pages[0]);
        }

        $objects = [];
        $objects[1] = "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";

        $kids = [];
        $page_obj_start = 4;
        $page_count = count($pages);
        for ($i = 0; $i < $page_count; $i++) {
            $page_obj = $page_obj_start + ($i * 2);
            $kids[] = $page_obj . ' 0 R';
        }
        $objects[2] = "2 0 obj<</Type/Pages/Count " . $page_count . "/Kids[" . implode(' ', $kids) . "]>>endobj\n";
        $objects[3] = "3 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n";

        $image_obj = 0;
        if (is_array($logo) && !empty($logo['data'])) {
            $image_obj = 4 + ($page_count * 2);
        }

        for ($i = 0; $i < $page_count; $i++) {
            $page_obj = $page_obj_start + ($i * 2);
            $content_obj = $page_obj + 1;
            $content = implode("
", $pages[$i]);
            $resource = '<</Font<</F1 3 0 R>>>>';
            if ($i === 0 && $image_obj > 0) {
                $resource = '<</Font<</F1 3 0 R>>/XObject<</Im1 ' . $image_obj . ' 0 R>>>>';
            }
            $objects[$page_obj] = $page_obj . " 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 " . $page_height . "]/Resources" . $resource . "/Contents " . $content_obj . " 0 R>>endobj\n";
            $objects[$content_obj] = $content_obj . " 0 obj<</Length " . strlen($content) . ">>stream\n" . $content . "\nendstream endobj\n";
        }

        if ($image_obj > 0) {
            $objects[$image_obj] = $image_obj . " 0 obj<</Type/XObject/Subtype/Image/Width " . (int)$logo['w'] . "/Height " . (int)$logo['h'] . "/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter/DCTDecode/Length " . strlen($logo['data']) . ">>stream\n" . $logo['data'] . "\nendstream endobj\n";
        }

        $pdf = "%PDF-1.4\n";
        ksort($objects, SORT_NUMERIC);
        $offsets = [0];
        foreach ($objects as $num => $o) {
            $offsets[(int)$num] = strlen($pdf);
            $pdf .= $o;
        }
        $xref = strlen($pdf);
        $size = (int)max(array_keys($objects)) + 1;
        $pdf .= "xref
0 " . $size . "
0000000000 65535 f 
";
        for ($i = 1; $i < $size; $i++) {
            if (isset($offsets[$i])) {
                $pdf .= sprintf("%010d 00000 n 
", (int)$offsets[$i]);
            } else {
                $pdf .= "0000000000 65535 f \n";
            }
        }
        $pdf .= "trailer<</Size " . $size . "/Root 1 0 R>>
startxref
" . $xref . "
%%EOF";
        return $pdf;
    }

    private static function build_ai_recommendation_dataset($date_start, $date_end) {
        global $wpdb;
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs = $wpdb->prefix . '301interactivebot_messages';

        $start_ts = strtotime($date_start . ' 00:00:00');
        $end_ts = strtotime($date_end . ' 23:59:59');
        $start = gmdate('Y-m-d H:i:s', $start_ts);
        $end = gmdate('Y-m-d H:i:s', $end_ts);

        $chat_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, started_at, build_county, build_state, lead_email, summary
             FROM $chats
             WHERE started_at BETWEEN %s AND %s
             ORDER BY id DESC
             LIMIT 120",
             $start,
             $end
        ), ARRAY_A);

        $max_chars = 120000;
        $chunks = [];
        $consumed = 0;

        foreach ($chat_rows as $chat) {
            $chat_id = (int)($chat['id'] ?? 0);
            if (!$chat_id) continue;

            $message_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT sender, message FROM $msgs WHERE chat_id=%d ORDER BY id ASC LIMIT 40",
                $chat_id
            ), ARRAY_A);

            $lines = [];
            foreach ($message_rows as $m) {
                $sender = sanitize_text_field($m['sender'] ?? '');
                $text = trim(wp_strip_all_tags((string)($m['message'] ?? '')));
                if ($text === '') continue;
                $lines[] = strtoupper($sender) . ': ' . $text;
            }

            $block = "Chat #{$chat_id}"
                . "
Started: " . sanitize_text_field($chat['started_at'] ?? '')
                . "
County/State: " . sanitize_text_field($chat['build_county'] ?? '') . ' ' . sanitize_text_field($chat['build_state'] ?? '')
                . "
Lead Email Provided: " . (!empty($chat['lead_email']) ? 'yes' : 'no')
                . (!empty($chat['summary']) ? "
Stored Summary: " . trim(wp_strip_all_tags((string)$chat['summary'])) : '')
                . "
Transcript:
" . implode("
", $lines);

            if ($consumed + strlen($block) > $max_chars) {
                break;
            }
            $chunks[] = $block;
            $consumed += strlen($block);
        }

        return [
            'date_start' => $date_start,
            'date_end' => $date_end,
            'chat_count' => count($chunks),
            'content' => implode("

----------------

", $chunks),
        ];
    }

    public static function blocked_users_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $blocks = $wpdb->prefix . '301interactivebot_blocks';
        $rows = $wpdb->get_results("SELECT * FROM $blocks ORDER BY id DESC LIMIT 200", ARRAY_A);
        ?>
        <div class="wrap">
          <h1>Blocked Users</h1>
          <table class="widefat striped">
            <thead><tr><th>ID</th><th>Type</th><th>Value</th><th>Reason</th><th>Created</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><a href="<?php echo esc_url(admin_url('admin.php?page=301interactivebot_chat_view&chat_id='.(int)$r['id'])); ?>"><?php echo (int)$r['id']; ?></a></td>
                  <td><?php echo esc_html($r['block_type']); ?></td>
                  <td><code><?php echo esc_html($r['block_value']); ?></code></td>
                  <td><?php echo esc_html($r['reason']); ?></td>
                  <td><?php echo esc_html($r['created_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

    public static function logs_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $logs = $wpdb->prefix . '301interactivebot_logs';
        $rows = $wpdb->get_results("SELECT * FROM $logs ORDER BY id DESC LIMIT 200", ARRAY_A);
        ?>
        <div class="wrap">
          <h1>Logs</h1>
          <table class="widefat striped">
            <thead><tr><th>ID</th><th>Level</th><th>Message</th><th>Context</th><th>Created</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><a href="<?php echo esc_url(admin_url('admin.php?page=301interactivebot_chat_view&chat_id='.(int)$r['id'])); ?>"><?php echo (int)$r['id']; ?></a></td>
                  <td><?php echo esc_html($r['level']); ?></td>
                  <td><?php echo esc_html($r['message']); ?></td>
                  <td><code style="white-space:pre-wrap"><?php echo esc_html($r['context'] ?? ''); ?></code></td>
                  <td><?php echo esc_html($r['created_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

    public static function diagnostics_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::get_settings();
        global $wpdb;
        $jobs = $wpdb->prefix . '301interactivebot_jobs';
        $has_as = function_exists('as_enqueue_async_action');
        $latest = $wpdb->get_row("SELECT * FROM $jobs ORDER BY id DESC LIMIT 1", ARRAY_A);
        ?>
        <div class="wrap">
          <h1>Diagnostics</h1>
          <h2>Configuration</h2>
          <table class="widefat striped" style="max-width:900px">
            <tbody>
              <tr><th style="width:260px">OpenAI Key Set</th><td><?php echo !empty($s['openai_api_key']) ? 'Yes' : 'No'; ?></td></tr>
              <tr><th>Vector Store ID</th><td><code><?php echo esc_html($s['vector_store_id']); ?></code></td></tr>
              <tr><th>Default Admin Email</th><td><?php echo esc_html($s['default_admin_email']); ?></td></tr>
            </tbody>
          </table>

          <hr/>
          <h2>Job Queue</h2>
          <table class="widefat striped" style="max-width:900px">
            <tbody>
              <tr><th style="width:260px">Action Scheduler Available</th><td><?php echo $has_as ? 'Yes' : 'No (using WP-Cron fallback)'; ?></td></tr>
              <tr><th>Latest Job Status</th><td><?php echo esc_html($latest['status'] ?? 'n/a'); ?></td></tr>
              <tr><th>Latest Job Engine</th><td><?php echo esc_html($latest['engine'] ?? 'n/a'); ?></td></tr>
              <tr><th>Latest Job Chat ID</th><td><?php echo esc_html($latest['chat_id'] ?? 'n/a'); ?></td></tr>
              <tr><th>Queued At</th><td><?php echo esc_html($latest['queued_at'] ?? ''); ?></td></tr>
              <tr><th>Started At</th><td><?php echo esc_html($latest['started_at'] ?? ''); ?></td></tr>
              <tr><th>Finished At</th><td><?php echo esc_html($latest['finished_at'] ?? ''); ?></td></tr>
              <tr><th>End-to-End Duration</th><td><?php echo !empty($latest['duration_ms']) ? (int)$latest['duration_ms'].' ms' : 'n/a'; ?></td></tr>
              <tr><th>Last Error</th><td><code style="white-space:pre-wrap"><?php echo esc_html($latest['last_error'] ?? ''); ?></code></td></tr>
            </tbody>
          </table>
        </div>
        <?php
    }

    
    public static function ajax_list_chats() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        global $wpdb;
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $rows = $wpdb->get_results("SELECT id, status, admin_takeover, COALESCE(NULLIF(build_county,''), NULLIF(build_city,'')) AS build_county, build_state, lead_email, lead_phone, COALESCE(ended_at, started_at) AS updated_at, started_at FROM $chats WHERE status='active' ORDER BY started_at DESC LIMIT 100", ARRAY_A);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['admin_takeover'] = (int)($r['admin_takeover'] ?? 0);
        }
        wp_send_json_success($rows);
    }

    public static function ajax_export_vector() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        check_ajax_referer('301interactivebot_export_vector', 'nonce');

        $result = self::run_vector_export();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message(), 500);
        }

        wp_send_json_success($result);
    }

    public static function cron_export_vector() {
        $result = self::run_vector_export();
        if (is_wp_error($result)) {
            _301InteractiveBot_Logger::log('error', 'Weekly vector export failed', ['error' => $result->get_error_message()]);
            return;
        }
        _301InteractiveBot_Logger::log('info', 'Weekly vector export complete', $result);
    }

    public static function run_vector_export() {
        $settings = self::get_settings();
        $api_key = trim($settings['openai_api_key'] ?? '');
        $vector_store_id = trim($settings['vector_store_id'] ?? '');
        if (!$api_key) return new WP_Error('301interactivebot_missing_api_key', 'OpenAI API key is not set.');
        if (!$vector_store_id) return new WP_Error('301interactivebot_missing_vector_store_id', 'Vector Store ID is not set.');

        $posts = self::collect_public_content();
        if (empty($posts)) return new WP_Error('301interactivebot_no_content', 'No public content found for export.');

        $chunks = [];
        foreach ($posts as $post) {
            $chunks = array_merge($chunks, self::chunk_content($post));
        }
        if (empty($chunks)) return new WP_Error('301interactivebot_no_chunks', 'No content chunks generated.');

        $upload = self::upload_chunks_to_vector_store($api_key, $vector_store_id, $chunks);
        if (is_wp_error($upload)) {
            return $upload;
        }

        return [
            'message' => 'Export complete.',
            'files_uploaded' => $upload['files_uploaded'] ?? 0,
            'chunks' => count($chunks),
        ];
    }

    private static function collect_public_content() {
        $types = ['page', 'post', 'custom-floor-plans', 'floor-plans'];
        $items = [];
        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        if (!$query->have_posts()) return $items;
        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;
            $content = apply_filters('the_content', $post->post_content);
            $content = do_shortcode($content);
            $items[] = [
                'title' => get_the_title($post),
                'url' => get_permalink($post),
                'content' => wp_strip_all_tags($content),
                'type' => $post->post_type,
            ];
        }
        return $items;
    }

    private static function chunk_content($item, $limit = 2000) {
        $text = "Title: {$item['title']}\nURL: {$item['url']}\nType: {$item['type']}\n\n{$item['content']}";
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        $chunks = [];
        while (mb_strlen($text) > $limit) {
            $slice = mb_substr($text, 0, $limit);
            $cut = mb_strrpos($slice, "\n\n");
            if ($cut === false || $cut < 200) $cut = $limit;
            $chunks[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text) $chunks[] = $text;
        return $chunks;
    }

    private static function upload_chunks_to_vector_store($api_key, $vector_store_id, $chunks) {
        $deleted = self::delete_vector_store_files($api_key, $vector_store_id);
        if (is_wp_error($deleted)) return $deleted;

        $tmp = wp_tempnam('301interactivebot-vector');
        if (!$tmp) return new WP_Error('301interactivebot_tmp', 'Unable to create temp file.');
        $content = implode("\n\n---\n\n", $chunks);
        file_put_contents($tmp, $content);

        $file_contents = file_get_contents($tmp);
        if ($file_contents === false) {
            @unlink($tmp);
            return new WP_Error('301interactivebot_upload_failed', 'Unable to read temp file contents.');
        }
        $boundary = '301interactivebot_' . wp_generate_password(24, false, false);
        $multipart = ''
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n"
            . "assistants\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"301interactivebot-site-content.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . $file_contents . "\r\n"
            . "--{$boundary}--\r\n";

        $upload = wp_remote_post('https://api.openai.com/v1/files', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $multipart,
        ]);

        @unlink($tmp);

        if (is_wp_error($upload)) return $upload;
        $code = wp_remote_retrieve_response_code($upload);
        $raw = wp_remote_retrieve_body($upload);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('301interactivebot_upload_failed', 'OpenAI upload failed: ' . $raw);
        }
        $json = json_decode($raw, true);
        $file_id = $json['id'] ?? '';
        if (!$file_id) {
            return new WP_Error('301interactivebot_upload_failed', 'OpenAI upload response missing file id.');
        }

        $batch = wp_remote_post('https://api.openai.com/v1/vector_stores/' . rawurlencode($vector_store_id) . '/file_batches', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'file_ids' => [$file_id],
            ]),
        ]);
        if (is_wp_error($batch)) return $batch;
        $batch_code = wp_remote_retrieve_response_code($batch);
        $batch_raw = wp_remote_retrieve_body($batch);
        if ($batch_code < 200 || $batch_code >= 300) {
            return new WP_Error('301interactivebot_batch_failed', 'Vector store batch failed: ' . $batch_raw);
        }

        return [
            'files_uploaded' => 1,
        ];
    }

    private static function delete_vector_store_files($api_key, $vector_store_id) {
        $after = null;
        do {
            $url = 'https://api.openai.com/v1/vector_stores/' . rawurlencode($vector_store_id) . '/files';
            if ($after) {
                $url .= '?after=' . rawurlencode($after);
            }
            $resp = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                ],
            ]);
            if (is_wp_error($resp)) return $resp;
            $code = wp_remote_retrieve_response_code($resp);
            $raw = wp_remote_retrieve_body($resp);
            if ($code < 200 || $code >= 300) {
                return new WP_Error('301interactivebot_list_failed', 'Vector store list failed: ' . $raw);
            }
            $json = json_decode($raw, true);
            $data = $json['data'] ?? [];
            foreach ($data as $entry) {
                $file_id = $entry['id'] ?? '';
                if (!$file_id) continue;
                $del = wp_remote_request('https://api.openai.com/v1/vector_stores/' . rawurlencode($vector_store_id) . '/files/' . rawurlencode($file_id), [
                    'method' => 'DELETE',
                    'timeout' => 30,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $api_key,
                    ],
                ]);
                if (is_wp_error($del)) return $del;
            }
            $after = $json['last_id'] ?? null;
            $has_more = !empty($json['has_more']);
        } while ($has_more);

        return true;
    }

public static function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::get_settings();
        ?>
        <div class="wrap">
          <h1>301 Interactive Chatbot Settings</h1>

          <form method="post" action="options.php">
            <?php settings_fields('301interactivebot_settings_group'); ?>

            <h2>OpenAI</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label>OpenAI API Key</label></th>
                <td><input type="password" name="301interactivebot_settings[openai_api_key]" value="<?php echo esc_attr($s['openai_api_key']); ?>" style="width:420px" autocomplete="off" /></td>
              </tr>
              <tr>
                <th scope="row"><label>Model</label></th>
                <td><input type="text" name="301interactivebot_settings[model]" value="<?php echo esc_attr($s['model']); ?>" style="width:260px" /></td>
              </tr>
              <tr>
                <th scope="row"><label>Vector Store ID</label></th>
                <td><input type="text" name="301interactivebot_settings[vector_store_id]" value="<?php echo esc_attr($s['vector_store_id']); ?>" style="width:420px" /></td>
              </tr>
              <tr>
                <th scope="row">Content Export</th>
                <td>
                  <button type="button" class="button" id="301interactivebot-export-vector">Export site content to Vector Store</button>
                  <p class="description">Exports public pages/posts and floorplan post types, chunks content, and replaces existing Vector Store files.</p>
                  <div id="301interactivebot-export-status" style="margin-top:8px;color:#64748b;"></div>
                </td>
              </tr>
            </table>

            <h2>Widget</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">Autoload Widget</th>
                <td><label><input type="checkbox" name="301interactivebot_settings[autoload]" value="1" <?php checked((int)$s['autoload'], 1); ?> /> Automatically insert on site (no shortcode)</label></td>
              </tr>
              <tr>
                <th scope="row">Display Mode</th>
                <td>
                  <select name="301interactivebot_settings[show_mode]">
                    <option value="floating" <?php selected($s['show_mode'], 'floating'); ?>>Floating (fixed)</option>
                    <option value="embedded" <?php selected($s['show_mode'], 'embedded'); ?>>Embedded (in-page)</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row">Floating Position</th>
                <td>
                  <select name="301interactivebot_settings[position]">
                    <option value="bottom-right" <?php selected($s['position'], 'bottom-right'); ?>>Bottom Right</option>
                    <option value="bottom-left" <?php selected($s['position'], 'bottom-left'); ?>>Bottom Left</option>
                    <option value="top-right" <?php selected($s['position'], 'top-right'); ?>>Top Right</option>
                    <option value="top-left" <?php selected($s['position'], 'top-left'); ?>>Top Left</option>
                  </select>
                </td>
              </tr>
              <tr><th scope="row">Desktop Offset X (px)</th><td><input type="number" min="0" max="500" name="301interactivebot_settings[offset_x_desktop]" value="<?php echo esc_attr($s['offset_x_desktop']); ?>" /></td></tr>
              <tr><th scope="row">Desktop Offset Y (px)</th><td><input type="number" min="0" max="500" name="301interactivebot_settings[offset_y_desktop]" value="<?php echo esc_attr($s['offset_y_desktop']); ?>" /></td></tr>
              <tr><th scope="row">Mobile Offset X (px)</th><td><input type="number" min="0" max="500" name="301interactivebot_settings[offset_x_mobile]" value="<?php echo esc_attr($s['offset_x_mobile']); ?>" /></td></tr>
              <tr><th scope="row">Mobile Offset Y (px)</th><td><input type="number" min="0" max="500" name="301interactivebot_settings[offset_y_mobile]" value="<?php echo esc_attr($s['offset_y_mobile']); ?>" /></td></tr>
              <tr><th scope="row">Mobile Breakpoint (px)</th><td><input type="number" min="320" max="1400" name="301interactivebot_settings[mobile_breakpoint]" value="<?php echo esc_attr($s['mobile_breakpoint']); ?>" /></td></tr>
              <tr><th scope="row">Z-Index</th><td><input type="number" min="1" max="99999999" name="301interactivebot_settings[z_index]" value="<?php echo esc_attr($s['z_index']); ?>" /></td></tr>
              <tr>
                <th scope="row">Only show on these pages</th>
                <td>
                  <textarea name="301interactivebot_settings[include_pages_raw]" rows="3" style="width:520px" placeholder="/where-we-build"><?php echo esc_textarea($s['include_pages_raw']); ?></textarea>
                  <p class="description">One path per line. Leave blank to show everywhere.</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Exclude these pages</th>
                <td><textarea name="301interactivebot_settings[exclude_pages_raw]" rows="3" style="width:520px" placeholder="/privacy-policy"><?php echo esc_textarea($s['exclude_pages_raw']); ?></textarea></td>
              </tr>
              <tr>
                <th scope="row">Idle Timeout (seconds)</th>
                <td>
                  <input type="number" min="60" step="30" name="301interactivebot_settings[idle_timeout_seconds]" value="<?php echo (int)$s['idle_timeout_seconds']; ?>" />
                  <p class="description">Warns the user with 30 seconds remaining.</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Lead Capture Mode</th>
                <td>
                  <select name="301interactivebot_settings[lead_capture_mode]">
                    <option value="form" <?php selected($s['lead_capture_mode'], 'form'); ?>>Use form</option>
                    <option value="chat" <?php selected($s['lead_capture_mode'], 'chat'); ?>>Collect via chat</option>
                  </select>
                  <p class="description">Choose whether to show the "Get more information" form or collect details conversationally.</p>
                </td>
              </tr>
              <tr>
                <th scope="row">Recommended Links</th>
                <td>
                  <label>
                    <input type="checkbox" name="301interactivebot_settings[show_recommended_links]" value="1" <?php checked((int)$s['show_recommended_links'], 1); ?> />
                    Show recommended page cards
                  </label>
                </td>
              </tr>
            </table>

            <h2>Theme + Branding</h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row">Primary Color</th><td><input class="301interactivebot-color" type="text" name="301interactivebot_settings[primary_color]" value="<?php echo esc_attr($s['primary_color']); ?>" data-default-color="#0b1f3a" /></td></tr>
              <tr><th scope="row">Accent Color</th><td><input class="301interactivebot-color" type="text" name="301interactivebot_settings[accent_color]" value="<?php echo esc_attr($s['accent_color']); ?>" data-default-color="#2563eb" /></td></tr>
              <tr><th scope="row">Bubble Color</th><td><input class="301interactivebot-color" type="text" name="301interactivebot_settings[bubble_color]" value="<?php echo esc_attr($s['bubble_color']); ?>" data-default-color="#2563eb" /></td></tr>
              <tr><th scope="row">Text Color</th><td><input class="301interactivebot-color" type="text" name="301interactivebot_settings[text_color]" value="<?php echo esc_attr($s['text_color']); ?>" data-default-color="#0b1f3a" /></td></tr>
              <tr>
                <th scope="row">Logo</th>
                <td>
                  <input type="hidden" id="301interactivebot_logo_id" name="301interactivebot_settings[logo_id]" value="<?php echo (int)$s['logo_id']; ?>" />
                  <button type="button" class="button" id="301interactivebot_logo_pick">Select / Upload Logo</button>
                  <button type="button" class="button" id="301interactivebot_logo_clear">Clear</button>
                  <div id="301interactivebot_logo_preview" style="margin-top:8px">
                    <?php if (!empty($s['logo_id'])) echo wp_get_attachment_image((int)$s['logo_id'], [160,40], false, ['style'=>'max-height:40px;width:auto']); ?>
                  </div>
                </td>
              </tr>
              <tr>
                <th scope="row">Closed Icon</th>
                <td>
                  <select name="301interactivebot_settings[closed_icon]">
                    <option value="chat" <?php selected($s['closed_icon'], 'chat'); ?>>Chat bubble</option>
                    <option value="none" <?php selected($s['closed_icon'], 'none'); ?>>None (hide)</option>
                  </select>
                </td>
              </tr>
            </table>

            <h2>Lead Capture + Routing</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">Default Admin Email</th>
                <td><input type="text" name="301interactivebot_settings[default_admin_email]" value="<?php echo esc_attr($s['default_admin_email']); ?>" style="width:420px" /></td>
              </tr>
              <tr>
                <th scope="row">Enable 3rd Party API</th>
                <td>
                  <label>
                    <input type="checkbox" name="301interactivebot_settings[enable_third_party_api]" value="1" <?php checked((int)($s['enable_third_party_api'] ?? 0), 1); ?> />
                    Send SalesSimplicity lead on chat end
                  </label>
                </td>
              </tr>
              <tr>
                <th scope="row">Service Areas</th>
                <td>
                  <?php $areas = (array)self::get_service_area_config($s); ?>
                  <div id="301interactivebot-service-area-list">
                    <?php foreach ($areas as $i => $area): ?>
                      <div class="301interactivebot-service-area-row" style="margin-bottom:12px;border:1px solid #e5e7eb;padding:12px;border-radius:10px;">
                        <div style="display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:center;margin-bottom:8px;">
                          <label>State</label>
                          <input type="text" name="301interactivebot_settings[service_areas][<?php echo (int)$i; ?>][state]" value="<?php echo esc_attr($area['state'] ?? ''); ?>" placeholder="KY" />
                          <label>Counties</label>
                          <textarea name="301interactivebot_settings[service_areas][<?php echo (int)$i; ?>][counties]" rows="4" placeholder="One county per line"><?php echo esc_textarea(implode("
", (array)($area['counties'] ?? []))); ?></textarea>
                          <label>Price List Name</label>
                          <input type="text" name="301interactivebot_settings[service_areas][<?php echo (int)$i; ?>][price-list-name]" value="<?php echo esc_attr($area['price-list-name'] ?? ''); ?>" />
                          <label>Price List Link</label>
                          <input type="url" name="301interactivebot_settings[service_areas][<?php echo (int)$i; ?>][price-list-link]" value="<?php echo esc_attr($area['price-list-link'] ?? ''); ?>" />
                          <label>Community</label>
                          <input type="text" name="301interactivebot_settings[service_areas][<?php echo (int)$i; ?>][community]" value="<?php echo esc_attr($area['community'] ?? ''); ?>" />
                          <label>Email List</label>
                          <input type="text" name="301interactivebot_settings[service_areas][<?php echo (int)$i; ?>][email-list]" value="<?php echo esc_attr($area['email-list'] ?? ''); ?>" placeholder="a@x.com, b@y.com" />
                        </div>
                        <button type="button" class="button 301interactivebot-service-area-remove">Remove</button>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="button" id="301interactivebot-service-area-add">Add Service Area</button>
                  <p class="description">Edit service areas without raw JSON. Counties are one per line.</p>
                </td>
              </tr>
            </table>

            <h2>FAQ</h2>
            <?php
              $faq_items = json_decode((string)($s['faq_json'] ?? '[]'), true);
              if (!is_array($faq_items)) $faq_items = [];
            ?>
            <input type="hidden" id="301interactivebot_faq_json" name="301interactivebot_settings[faq_json]" value="<?php echo esc_attr(wp_json_encode($faq_items)); ?>" />
            <div id="301interactivebot-faq-list">
              <?php if (empty($faq_items)) $faq_items = [['q' => '', 'a' => '']]; ?>
              <?php foreach ($faq_items as $item): ?>
                <div class="301interactivebot-faq-row" style="margin-bottom:12px;border:1px solid #e5e7eb;padding:12px;border-radius:10px;">
                  <div style="margin-bottom:8px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Question</label>
                    <input type="text" class="301interactivebot-faq-q" value="<?php echo esc_attr($item['q'] ?? ''); ?>" style="width:100%;" />
                  </div>
                  <div style="margin-bottom:8px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Answer</label>
                    <textarea class="301interactivebot-faq-a" rows="3" style="width:100%;"><?php echo esc_textarea($item['a'] ?? ''); ?></textarea>
                  </div>
                  <button type="button" class="button 301interactivebot-faq-remove">Remove</button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="301interactivebot-faq-add">Add FAQ</button>

            <h2>System Prompt</h2>
            <textarea name="301interactivebot_settings[system_prompt]" rows="10" style="width:100%"><?php echo esc_textarea($s['system_prompt']); ?></textarea>

            <?php submit_button('Save Settings'); ?>
          </form>
        </div>
        <?php
    }
}