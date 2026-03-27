<?php
if (!defined('ABSPATH')) exit;

class _301InteractiveBot_REST {

    private const INACTIVITY_TIMEOUT_MINUTES = 30;
    private const SS_GUID = "AF2D0FC1-43D8-4786-AD37-10C961617849";
    private const SS_WSDL = "http://salessimplicity.net/ssnet/svceleads/eleads.asmx?WSDL";

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('301interactivebot/v1', '/start', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'start_chat'],
        ]);

        register_rest_route('301interactivebot/v1', '/message', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'send_message'],
        ]);

        register_rest_route('301interactivebot/v1', '/lead', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'save_lead'],
        ]);

        register_rest_route('301interactivebot/v1', '/event', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'log_event'],
        ]);

        register_rest_route('301interactivebot/v1', '/end', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'end_chat'],
        ]);

        // Poll endpoint for both users and admins
        register_rest_route('301interactivebot/v1', '/poll', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'poll'],
        ]);

        // Admin actions (requires manage_options)
        register_rest_route('301interactivebot/v1', '/admin/takeover', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_takeover'],
        ]);
        register_rest_route('301interactivebot/v1', '/admin/release', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_release'],
        ]);
        register_rest_route('301interactivebot/v1', '/admin/chat', [
            'methods' => 'GET',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_chat'],
        ]);
        register_rest_route('301interactivebot/v1', '/admin/end', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_end'],
        ]);
        register_rest_route('301interactivebot/v1', '/admin/resend-transcript', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_resend_transcript'],
        ]);
        register_rest_route('301interactivebot/v1', '/admin/block', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_block'],
        ]);
        register_rest_route('301interactivebot/v1', '/admin/unblock', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_unblock'],
        ]);

        register_rest_route('301interactivebot/v1', '/admin/send', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'admin_send'],
        ]);
    }

    public static function can_manage($request) {
        return current_user_can('manage_options');
    }

    private static function settings() {
        return _301InteractiveBot_Admin::get_settings();
    }

    private static function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return sanitize_text_field($ip);
    }

    private static function is_blocked($session_key, $ip = '', $email = '', $phone = '') {
        global $wpdb;
        $old = $wpdb->prefix . '301interactivebot_blocklist';
        $blocks = $wpdb->prefix . '301interactivebot_blocks';

        // Backward compatible: old session-only table
        if ($session_key) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $old WHERE session_key=%s", $session_key), ARRAY_A);
            if (!empty($row)) return true;
        }

        $checks = [];
        if ($session_key) $checks[] = ['session', $session_key];
        if ($ip) $checks[] = ['ip', $ip];
        if ($email) $checks[] = ['email', strtolower($email)];
        if ($phone) $checks[] = ['phone', preg_replace('/[^0-9]/', '', $phone)];

        foreach ($checks as $c) {
            [$t,$v] = $c;
            if (!$v) continue;
            $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $blocks WHERE block_type=%s AND block_value=%s", $t, $v), ARRAY_A);
            if (!empty($row)) return true;
        }
        return false;
    }

    private static function is_session_blocked($session_key) {
        return self::is_blocked($session_key);
    }

    private static function sanitize_text($s, $max = 8000) {
        $s = is_string($s) ? $s : '';
        $s = wp_strip_all_tags($s);
        $s = trim($s);
        if (strlen($s) > $max) $s = substr($s, 0, $max);
        return $s;
    }

    public static function start_chat($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $session_key = self::sanitize_text($params['session_key'] ?? '', 64);
        $requested_chat_id = (int)($params['chat_id'] ?? 0);
        if (!$session_key) $session_key = wp_generate_uuid4();

        $ip = self::get_client_ip();
        if (self::is_blocked($session_key, $ip)) {
            return new WP_REST_Response(['error' => 'blocked'], 403);
        }

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $now = current_time('mysql');

        $utm = [
            'utm_source' => self::sanitize_text($params['utm_source'] ?? '', 255),
            'utm_medium' => self::sanitize_text($params['utm_medium'] ?? '', 255),
            'utm_campaign' => self::sanitize_text($params['utm_campaign'] ?? '', 255),
            'utm_term' => self::sanitize_text($params['utm_term'] ?? '', 255),
            'utm_content' => self::sanitize_text($params['utm_content'] ?? '', 255),
        ];

        $current_page = esc_url_raw($params['current_page'] ?? '');
        $referrer = esc_url_raw($params['referrer'] ?? '');

        if ($requested_chat_id) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM $chats WHERE id=%d AND session_key=%s",
                $requested_chat_id,
                $session_key
            ), ARRAY_A);
            if ($existing && ($existing['status'] ?? '') === 'active') {
                $wpdb->update($chats, [
                    'current_page' => $current_page,
                    'referrer' => $referrer,
                    'last_user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ], ['id' => $requested_chat_id]);

                return rest_ensure_response([
                    'chat_id' => $requested_chat_id,
                    'session_key' => $session_key,
                ]);
            }
        }

        return rest_ensure_response([
            'session_key' => $session_key,
        ]);
    }

    public static function send_message($request) {
        global $wpdb;

        $params = $request->get_json_params();
        $chat_id = (int)($params['chat_id'] ?? 0);
        $session_key = self::sanitize_text($params['session_key'] ?? '', 64);
        if (!$session_key) $session_key = wp_generate_uuid4();
        $text = self::sanitize_text($params['message'] ?? '');

        if (!$text) {
            return new WP_REST_Response(['error' => 'Missing message'], 400);
        }

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';
        $now = current_time('mysql');

        $chat = null;
        if ($chat_id) {
            $chat = $wpdb->get_row($wpdb->prepare("SELECT * FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
        }
        if ($chat) {
            $ip = self::get_client_ip();
            $email = $chat['lead_email'] ?? '';
            $phone = $chat['lead_phone'] ?? '';
            if (self::is_blocked($chat['session_key'] ?? '', $ip, $email, $phone)) {
                return new WP_REST_Response(['error' => 'blocked'], 403);
            }
        }
        if (!$chat && !$chat_id && $session_key) {
            $current_page = esc_url_raw($params['current_page'] ?? '');
            $referrer = esc_url_raw($params['referrer'] ?? '');
            $wpdb->insert($chats, [
                'session_key' => $session_key,
                'status' => 'active',
                'started_at' => $now,
                'current_page' => $current_page,
                'referrer' => $referrer,
                'last_user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
            $chat_id = (int)$wpdb->insert_id;
            $chat = $wpdb->get_row($wpdb->prepare("SELECT * FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
        }
        if (!$chat) return new WP_REST_Response(['error' => 'Chat not found'], 404);

        // Store user message
        $wpdb->insert($msgs, [
            'chat_id' => $chat_id,
            'sender' => 'user',
            'message' => $text,
            'meta_json' => null,
            'created_at' => $now,
        ]);
        $user_message_id = (int)$wpdb->insert_id;

        // Update current page if provided
        if (!empty($params['current_page'])) {
            $wpdb->update($chats, ['current_page' => esc_url_raw($params['current_page'])], ['id' => $chat_id]);
        }

        // If admin takeover is active, do not call OpenAI; client should poll for admin response
        if ((int)$chat['admin_takeover'] === 1) {
            return rest_ensure_response([
                'mode' => 'admin',
                'reply' => '',
                'should_collect_lead' => false,
                'user_message_id' => $user_message_id,
                'chat_id' => $chat_id,
                'session_key' => $session_key,
            ]);
        }

        // Build message history for the model (last N)
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT sender, message FROM $msgs WHERE chat_id=%d ORDER BY id ASC LIMIT 16",
            $chat_id
        ), ARRAY_A);

        $model_messages = [];
        foreach ($history as $h) {
            $role = ($h['sender'] === 'user') ? 'user' : 'assistant';
            if ($h['sender'] === 'admin') $role = 'assistant';
            $model_messages[] = [
                'role' => $role,
                'content' => $h['message']
            ];
        }

        $settings = _301InteractiveBot_Admin::get_settings();
        $resp = _301InteractiveBot_OpenAI::respond($chat_id, $model_messages, $settings);
        $reply_text = $resp['text'] ?? '';
        $meta = $resp['meta'] ?? [];
        $suggested_links = $meta['suggested_links'] ?? [];
        $should_collect_lead = (bool)($meta['should_collect_lead'] ?? false);

        if ($reply_text) {
            $wpdb->insert($msgs, [
                'chat_id' => $chat_id,
                'sender' => 'bot',
                'message' => $reply_text,
                'meta_json' => wp_json_encode($meta),
                'created_at' => current_time('mysql'),
            ]);
            $bot_message_id = (int)$wpdb->insert_id;

            return rest_ensure_response([
                'mode' => 'bot',
                'reply' => $reply_text,
                'should_collect_lead' => $should_collect_lead,
                'suggested_links' => $suggested_links,
                'user_message_id' => $user_message_id,
                'message_id' => $bot_message_id,
                'chat_id' => $chat_id,
                'session_key' => $session_key,
            ]);
        }

        // Enqueue async AI response as fallback
        _301InteractiveBot_Async::enqueue($chat_id, $user_message_id);

        return rest_ensure_response([
            'mode' => 'queued',
            'reply' => '',
            'should_collect_lead' => false,
            'suggested_links' => [],
            'user_message_id' => $user_message_id,
            'chat_id' => $chat_id,
            'session_key' => $session_key,
        ]);

    }

    public static function save_lead($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $chat_id = (int)($params['chat_id'] ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);
        $announce = !empty($params['announce']);

        $first = self::sanitize_text($params['first_name'] ?? '', 255);
        $last  = self::sanitize_text($params['last_name'] ?? '', 255);
        $phone = self::sanitize_text($params['phone'] ?? '', 255);
        $email = sanitize_email($params['email'] ?? '');
        $address = self::sanitize_text($params['address'] ?? '', 255);
        $county = self::sanitize_text($params['build_county'] ?? '', 100);
        $state = self::sanitize_text($params['build_state'] ?? '', 100);
        if (!$county) $county = self::sanitize_text($params['build_city'] ?? '', 100);
        // Service-area inference intentionally disabled for generic deployments.
        /*
        if (!$state && $county) {
            if (preg_match('/([A-Za-z]{2})$/', trim($county), $match)) {
                $state = strtoupper($match[1]);
            }
        }
        if ($county) {
            $service_area = _301InteractiveBot_Admin::find_service_area_by_county($county, $state, self::settings());
            if (!$state && !empty($service_area['state'])) $state = strtoupper(sanitize_text_field($service_area['state']));
        }
        */

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';
        $wpdb->update($chats, [
            'lead_first' => $first,
            'lead_last' => $last,
            'lead_phone' => $phone,
            'lead_email' => $email,
            'lead_address' => $address,
            'build_county' => $county,
            'build_state' => $state,
            'build_city' => $county,
        ], ['id' => $chat_id]);

        if ($announce) {
            $email_line = $email ? $email : '(not provided)';
            $summary = sprintf(
                'Customer info submitted: %s %s, Phone: %s, Email: %s, Address: %s.',
                $first,
                $last,
                $phone,
                $email_line,
                $address ?: '(not provided)'
            );
            $wpdb->insert($msgs, [
                'chat_id' => $chat_id,
                'sender' => 'user',
                'message' => $summary,
                'meta_json' => wp_json_encode(['type' => 'lead_submit']),
                'created_at' => current_time('mysql'),
            ]);
        }

        self::log_event_internal($chat_id, 'lead_submit', wp_json_encode([
            'first' => $first,
            'last' => $last,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'county' => $county,
            'state' => $state
        ]), $params['current_page'] ?? '');

        return rest_ensure_response(['ok' => true]);
    }

    public static function log_event($request) {
        $p = $request->get_json_params();
        $chat_id = (int)($p['chat_id'] ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);

        $type = self::sanitize_text($p['event_type'] ?? '', 100);
        $val  = self::sanitize_text($p['event_value'] ?? '', 2000);
        $url  = esc_url_raw($p['url'] ?? '');

        self::log_event_internal($chat_id, $type, $val, $url);
        return rest_ensure_response(['ok' => true]);
    }

    private static function log_event_internal($chat_id, $type, $val, $url) {
        global $wpdb;
        $events = $wpdb->prefix . '301interactivebot_events';
        $wpdb->insert($events, [
            'chat_id' => (int)$chat_id,
            'event_type' => $type,
            'event_value' => $val,
            'url' => $url,
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function end_chat($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $chat_id = (int)($params['chat_id'] ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';

        $chat = $wpdb->get_row($wpdb->prepare("SELECT * FROM $chats WHERE status != 'ended' AND id=%d", $chat_id), ARRAY_A);
        if ($chat) {
            $ip = self::get_client_ip();
            $email = $chat['lead_email'] ?? '';
            $phone = $chat['lead_phone'] ?? '';
            if (self::is_blocked($chat['session_key'] ?? '', $ip, $email, $phone)) {
                return new WP_REST_Response(['error' => 'blocked'], 403);
            }
        }
        if (!$chat) return new WP_REST_Response(['error' => 'Chat not found'], 404);

        $wpdb->update($chats, [
            'status' => 'ended',
            'ended_at' => current_time('mysql'),
        ], ['id' => $chat_id]);

        self::run_chat_close_actions($chat_id, $chat);

        return rest_ensure_response(['ok' => true]);
    }

    private static function run_chat_close_actions($chat_id, $chat) {
        global $wpdb;
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';

        $rows = $wpdb->get_results($wpdb->prepare("SELECT sender, message, created_at FROM $msgs WHERE chat_id=%d ORDER BY id ASC", $chat_id), ARRAY_A);
        $lines = [];
        foreach ($rows as $r) {
            $who = strtoupper($r['sender']);
            $lines[] = "[{$r['created_at']}] {$who}: {$r['message']}";
        }
        $transcript = implode("\n\n", $lines);
        $pages = self::get_pages_visited($chat_id);
        if (!empty($pages)) {
            $transcript .= "\n\nPAGES VISITED:\n" . implode("\n", $pages);
        }

        $settings = self::settings();
        $summary = _301InteractiveBot_OpenAI::summarize_transcript($chat_id, $transcript, $settings);
        if ($summary) {
            $wpdb->update($chats, ['summary' => $summary], ['id' => $chat_id]);
        }

        $email_content = _301InteractiveBot_OpenAI::translate_transcript_for_email($chat_id, $summary, $rows, $settings);
        $email_summary = (string)($email_content['summary'] ?? $summary);
        $email_rows = (array)($email_content['rows'] ?? $rows);
        $email_translation_meta = [
            'translated' => !empty($email_content['translated']),
            'source_language' => sanitize_text_field((string)($email_content['source_language'] ?? '')),
        ];

        $email_payload = self::build_transcript_email_payload($chat_id, $chat, $email_rows, $email_summary, $pages, $settings, $email_translation_meta);
        self::send_transcript_email($email_payload);

        // Service-area / third-party lead handoff intentionally disabled for generic deployments.
        /*
        $county = (string)($email_payload['county'] ?? '');
        $state = (string)($email_payload['state'] ?? '');
        $service_area = (array)($email_payload['service_area'] ?? []);
        $has_required_lead = !empty($chat['lead_first']) && !empty($chat['lead_last']) && !empty($chat['lead_email']) && !empty($county) && !empty($state);

        if ($has_required_lead) {
            self::send_sales_simplicity_lead($chat_id, $chat, $service_area, $county, $state);
        } else {
            _301InteractiveBot_Logger::log('info', 'Sales Simplicity skipped (lead information incomplete)', [
                'chat_id' => $chat_id,
                'lead_first' => !empty($chat['lead_first']),
                'lead_last' => !empty($chat['lead_last']),
                'lead_email' => !empty($chat['lead_email']),
                'county' => !empty($county),
                'state' => !empty($state),
            ]);
        }
        */
    }


    private static function build_transcript_email_payload($chat_id, $chat, $rows, $summary, $pages, $settings, $translation_meta = []) {
        $county = $chat['build_county'] ?? $chat['build_city'] ?? '';
        $state = $chat['build_state'] ?? '';
        // Service-area pricing/community routing intentionally disabled for generic deployments.
        $service_area = [];
        $price_list_html = '—';
        $community = '';
        /*
        $service_area = _301InteractiveBot_Admin::find_service_area_by_county($county, $state, $settings);
        $price_list_name = sanitize_text_field($service_area['price-list-name'] ?? '');
        $price_list_link = esc_url_raw($service_area['price-list-link'] ?? '');
        $community = sanitize_text_field($service_area['community'] ?? '');
        $price_list_html = $price_list_name ? esc_html($price_list_name) : '—';
        if ($price_list_name && $price_list_link) {
            $price_list_html = '<a href="' . esc_url($price_list_link) . '">' . esc_html($price_list_name) . '</a>';
        }
        */

        $to = self::resolve_admin_recipients([], $settings);
        $company_name = sanitize_text_field($settings['company_name'] ?? get_bloginfo('name'));
        if ($company_name === '') $company_name = 'Website';
        $subject = $company_name . ' Chat Transcript — Chat #' . $chat_id;

        $lead_name = trim(($chat['lead_first'] ?? '') . ' ' . ($chat['lead_last'] ?? ''));
        $lead_name = $lead_name ?: '—';
        $summary_html = $summary ? nl2br(esc_html($summary)) : '—';
        $translation_note_html = '';
        $translated = !empty($translation_meta['translated']);
        $source_language = strtolower(trim((string)($translation_meta['source_language'] ?? '')));
        if ($translated && $source_language === 'spanish') {
            $translation_note_html = '<div style="margin:8px 0 0;color:#92400e;font-size:12px;">'
                . 'Note: This transcript was automatically translated to English from Spanish for email readability.'
                . '</div>';
        }
        $transcript_html = '';
        foreach ($rows as $r) {
            $who = strtoupper($r['sender']);
            $when = esc_html($r['created_at']);
            $msg = nl2br(esc_html($r['message']));
            $transcript_html .= '<tr>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;color:#64748b;">' . $when . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#0f172a;">' . $who . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#0f172a;">' . $msg . '</td>'
                . '</tr>';
        }
        if ($transcript_html === '') {
            $transcript_html = '<tr><td colspan="3" style="padding:8px 10px;color:#64748b;">No messages.</td></tr>';
        }

        $pages_html = '';
        if (!empty($pages)) {
            $page_items = '';
            foreach ($pages as $p) {
                $page_items .= '<li>' . esc_html($p) . '</li>';
            }
            $pages_html = '<h3 style="margin:16px 0 8px;font-size:16px;">Pages Visited</h3>'
                . '<ul style="margin:0 0 16px 18px;padding:0;color:#0f172a;">' . $page_items . '</ul>';
        }

        $body = '<div style="font-family:Arial, sans-serif;color:#0f172a;line-height:1.4;">'
              . '<h2 style="margin:0 0 12px;font-size:18px;">Chat Transcript</h2>'
              . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">'
              . '<tr><td style="padding:6px 0;color:#64748b;width:140px;">Chat ID</td><td style="padding:6px 0;">' . $chat_id . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Status</td><td style="padding:6px 0;">' . esc_html($chat['status'] ?? '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Build County</td><td style="padding:6px 0;">' . esc_html($county ?: '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">State</td><td style="padding:6px 0;">' . esc_html($state ?: '—') . '</td></tr>'
              /*
              . '<tr><td style="padding:6px 0;color:#64748b;">Community</td><td style="padding:6px 0;">' . esc_html($community ?: '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Price List</td><td style="padding:6px 0;">' . $price_list_html . '</td></tr>'
              */
              . '<tr><td style="padding:6px 0;color:#64748b;">Lead</td><td style="padding:6px 0;">' . esc_html($lead_name) . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Phone</td><td style="padding:6px 0;">' . esc_html($chat['lead_phone'] ?? '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Email</td><td style="padding:6px 0;">' . esc_html($chat['lead_email'] ?? '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Address</td><td style="padding:6px 0;">' . esc_html($chat['lead_address'] ?? '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Current Page</td><td style="padding:6px 0;">' . esc_html($chat['current_page'] ?? '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">Referrer</td><td style="padding:6px 0;">' . esc_html($chat['referrer'] ?? '—') . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">UTM Source/Medium/Campaign</td><td style="padding:6px 0;">'
              . esc_html(($chat['utm_source'] ?? '—') . ' / ' . ($chat['utm_medium'] ?? '—') . ' / ' . ($chat['utm_campaign'] ?? '—')) . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#64748b;">UTM Term/Content</td><td style="padding:6px 0;">'
              . esc_html(($chat['utm_term'] ?? '—') . ' / ' . ($chat['utm_content'] ?? '—')) . '</td></tr>'
              . '</table>'
              . $pages_html
              . '<h3 style="margin:16px 0 8px;font-size:16px;">Summary</h3>'
              . '<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px;">' . $summary_html . '</div>'
              . $translation_note_html
              . '<h3 style="margin:16px 0 8px;font-size:16px;">Transcript</h3>'
              . '<table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;">'
              . '<thead><tr style="background:#f1f5f9;">'
              . '<th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;font-size:12px;">Time</th>'
              . '<th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;font-size:12px;">Sender</th>'
              . '<th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;font-size:12px;">Message</th>'
              . '</tr></thead>'
              . '<tbody>' . $transcript_html . '</tbody>'
              . '</table>'
              . '</div>';

        return [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'service_area' => $service_area,
            'county' => $county,
            'state' => $state,
        ];
    }

    private static function send_transcript_email($payload) {
        $to = (array)($payload['to'] ?? []);
        if (empty($to)) return false;
        $settings = self::settings();
        $company_name = sanitize_text_field($settings['company_name'] ?? '301 Interactive');
        $from_email = sanitize_email($settings['from_email'] ?? 'no-reply@301interactive.com');
        if ($company_name === '') $company_name = '301 Interactive';
        if ($from_email === '') $from_email = 'no-reply@301interactive.com';
		
        return wp_mail(
            $to,
            (string)($payload['subject'] ?? ''),
            (string)($payload['body'] ?? ''),
             [
				'Content-Type: text/html; charset=UTF-8',
				sprintf('From: %s <%s>', $company_name, $from_email),
        	]
        );
    }



    private static function send_sales_simplicity_lead($chat_id, $chat, $service_area, $county, $state) {
        $settings = self::settings();
        if (empty($settings['enable_third_party_api'])) {
            return;
        }

        $first = sanitize_text_field($chat['lead_first'] ?? '');
        $last = sanitize_text_field($chat['lead_last'] ?? '');
        $email = sanitize_email($chat['lead_email'] ?? '');
        $phone = self::sanitize_text($chat['lead_phone'] ?? '', 255);

        if (!$first || !$last || !$email || !$county || !$state) {
            return;
        }

        $community = sanitize_text_field($service_area['community'] ?? '');

        $payload = [
            'BuilderName' => '301 Interactive',
            'Email' => $email,
            'FirstName' => $first,
            'LastName' => $last,
            'Phone' => $phone,
            'Community' => $community,
            'Demos' => [
                'Demo39Txt=' . $county,
                'Demo2Txt=' . $community,
                'Demo17=Website Chatbot',
            ],
        ];

        self::log_third_party_message($chat_id, '3rd Party API Request', wp_json_encode(['endpoint' => self::SS_WSDL, 'payload' => $payload]));
        _301InteractiveBot_Logger::log('info', 'SalesSimplicity request', ['chat_id' => $chat_id, 'payload' => $payload]);

        if (!class_exists('SoapClient')) {
            $msg = 'SOAP extension is not available on this server.';
            self::log_third_party_message($chat_id, '3rd Party API Response', $msg);
            _301InteractiveBot_Logger::log('error', 'SalesSimplicity request failed', ['chat_id' => $chat_id, 'error' => $msg]);
            return;
        }

        try {
            $client = new SoapClient(self::SS_WSDL);
            $lead = (object)$payload;
            $result = $client->SubmitLead(['Contact' => $lead, 'sGUID' => self::SS_GUID]);

            $result_json = wp_json_encode($result);
            self::log_third_party_message($chat_id, '3rd Party API Response', $result_json ?: print_r($result, true));
            _301InteractiveBot_Logger::log('info', 'SalesSimplicity response', ['chat_id' => $chat_id, 'response' => $result_json ?: print_r($result, true)]);
        } catch (Throwable $e) {
            self::log_third_party_message($chat_id, '3rd Party API Response', 'Error: ' . $e->getMessage());
            _301InteractiveBot_Logger::log('error', 'SalesSimplicity request exception', ['chat_id' => $chat_id, 'error' => $e->getMessage()]);
        }
    }

    private static function log_third_party_message($chat_id, $title, $content) {
        global $wpdb;
        $msgs = $wpdb->prefix . '301interactivebot_messages';
        $wpdb->insert($msgs, [
            'chat_id' => (int)$chat_id,
            'sender' => 'bot',
            'message' => '[' . $title . '] ' . (string)$content,
            'meta_json' => wp_json_encode(['type' => 'integration_log']),
            'created_at' => current_time('mysql'),
        ]);
    }

    private static function resolve_admin_recipients($service_area, $settings) {
        $recipients = [];

        $fallback = $settings['default_admin_email'] ?? get_option('admin_email');
        //if ($fallback) $recipients[$fallback] = true;
        if($fallback !== '') {
			$parts = preg_split('/[\s,;]+/', $fallback);
            foreach ((array)$parts as $admin) {
                $email = sanitize_email($admin);
                if ($email) $recipients[$email] = true;
            }
		}

        // Service-area email list routing intentionally disabled for generic deployments.
        /*
        $email_list_raw = (string)($service_area['email-list'] ?? '');
        if ($email_list_raw !== '') {
            $parts = preg_split('/[\s,;]+/', $email_list_raw);
            foreach ((array)$parts as $candidate) {
                $email = sanitize_email($candidate);
                if ($email) $recipients[$email] = true;
            }
        }
        */

        return array_keys($recipients);
    }

    private static function maybe_auto_end_chat($chat_id) {
        global $wpdb;
        $chat_id = (int)$chat_id;
        if (!$chat_id) return false;

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs = $wpdb->prefix . '301interactivebot_messages';

        $chat = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, started_at FROM $chats WHERE id=%d",
            $chat_id
        ), ARRAY_A);
        if (empty($chat) || ($chat['status'] ?? '') !== 'active') return false;

        $last_message_at = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) FROM $msgs WHERE chat_id=%d",
            $chat_id
        ));
        $last_activity_at = $last_message_at ?: ($chat['started_at'] ?? '');
        if (!$last_activity_at) return false;

        $last_ts = strtotime($last_activity_at);
        if (!$last_ts) return false;

        $now_ts = (int)current_time('timestamp');
        $inactive_for = $now_ts - $last_ts;
        if ($inactive_for < (self::INACTIVITY_TIMEOUT_MINUTES * MINUTE_IN_SECONDS)) return false;

        $updated = $wpdb->update($chats, [
            'status' => 'ended',
            'ended_at' => current_time('mysql'),
        ], [
            'id' => $chat_id,
            'status' => 'active',
        ]);

        if ($updated) {
            self::log_event_internal($chat_id, 'chat_end', 'auto_inactive_30m', '');
            $chat_full = $wpdb->get_row($wpdb->prepare("SELECT * FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
            if (!empty($chat_full)) {
                self::run_chat_close_actions($chat_id, $chat_full);
            }
            return true;
        }

        return false;
    }

    public static function auto_end_inactive_chats() {
        global $wpdb;
        $chats = $wpdb->prefix . '301interactivebot_chats';

        $chat_ids = $wpdb->get_col("SELECT id FROM {$chats} WHERE status='active' ORDER BY id ASC LIMIT 500");
        if (empty($chat_ids) || !is_array($chat_ids)) return;

        foreach ($chat_ids as $chat_id) {
            self::maybe_auto_end_chat((int)$chat_id);
        }
    }

    public static function poll($request) {
        global $wpdb;
        $chat_id = (int)($request->get_param('chat_id') ?? 0);
        $since_id = (int)($request->get_param('since_id') ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';

        self::maybe_auto_end_chat($chat_id);

        $chat = $wpdb->get_row($wpdb->prepare("SELECT admin_takeover, status FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
        if (!$chat) return new WP_REST_Response(['error' => 'Chat not found'], 404);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender, message, created_at FROM $msgs WHERE chat_id=%d AND id>%d ORDER BY id ASC LIMIT 50",
            $chat_id, $since_id
        ), ARRAY_A);

        return rest_ensure_response([
            'admin_takeover' => (int)$chat['admin_takeover'],
            'status' => $chat['status'],
            'messages' => $rows,
            'last_id' => !empty($rows) ? (int)end($rows)['id'] : $since_id
        ]);
    }

    public static function admin_takeover($request) {
        global $wpdb;
        $p = $request->get_json_params();
        $chat_id = (int)($p['chat_id'] ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $wpdb->update($chats, [
            'admin_takeover' => 1,
            'admin_user_id' => get_current_user_id(),
        ], ['id' => $chat_id]);

        return rest_ensure_response(['ok' => true]);
    }

    public static function admin_release($request) {
        global $wpdb;
        $p = $request->get_json_params();
        $chat_id = (int)($p['chat_id'] ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $wpdb->update($chats, [
            'admin_takeover' => 0,
            'admin_user_id' => null,
        ], ['id' => $chat_id]);

        return rest_ensure_response(['ok' => true]);
    }

    public static function admin_send($request) {
        global $wpdb;
        $p = $request->get_json_params();
        $chat_id = (int)($p['chat_id'] ?? 0);
        $msg = self::sanitize_text($p['message'] ?? '');
        if (!$chat_id || !$msg) return new WP_REST_Response(['error' => 'Missing chat_id or message'], 400);

        $msgs  = $wpdb->prefix . '301interactivebot_messages';
        $wpdb->insert($msgs, [
            'chat_id' => $chat_id,
            'sender' => 'admin',
            'message' => $msg,
            'meta_json' => null,
            'created_at' => current_time('mysql'),
        ]);

        return rest_ensure_response(['ok' => true]);
    }
    public static function admin_chat($request) {
        global $wpdb;
        $chat_id = (int)($request->get_param('chat_id') ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $chat = $wpdb->get_row($wpdb->prepare("SELECT * FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
        if (!$chat) return new WP_REST_Response(['error' => 'Chat not found'], 404);
        $chat['pages'] = self::get_pages_visited($chat_id);
        return rest_ensure_response(['chat' => $chat]);
    }

    private static function get_pages_visited($chat_id) {
        global $wpdb;
        $events = $wpdb->prefix . '301interactivebot_events';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT url FROM $events WHERE chat_id=%d AND event_type=%s AND url <> '' ORDER BY id ASC",
            $chat_id,
            'page_view'
        ), ARRAY_A);
        if (empty($rows)) return [];
        $seen = [];
        $pages = [];
        foreach ($rows as $row) {
            $url = $row['url'] ?? '';
            if (!$url || isset($seen[$url])) continue;
            $seen[$url] = true;
            $pages[] = $url;
        }
        return $pages;
    }

    public static function admin_end($request) {
        return self::end_chat($request);
    }

    public static function admin_resend_transcript($request) {
        global $wpdb;
        $p = $request->get_json_params();
        $chat_id = (int)($p['chat_id'] ?? 0);
        if (!$chat_id) return new WP_REST_Response(['error' => 'Missing chat_id'], 400);

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';

        $chat = $wpdb->get_row($wpdb->prepare("SELECT * FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
        if (!$chat) return new WP_REST_Response(['error' => 'Chat not found'], 404);

        $rows = $wpdb->get_results($wpdb->prepare("SELECT sender, message, created_at FROM $msgs WHERE chat_id=%d ORDER BY id ASC", $chat_id), ARRAY_A);
        $pages = self::get_pages_visited($chat_id);
        $settings = self::settings();

        $summary = $chat['summary'] ?? '';
        if ($summary === '') {
            $lines = [];
            foreach ($rows as $r) {
                $who = strtoupper($r['sender']);
                $lines[] = "[{$r['created_at']}] {$who}: {$r['message']}";
            }
            $transcript = implode("\n\n", $lines);
            if (!empty($pages)) {
                $transcript .= "\n\nPAGES VISITED:\n" . implode("\n", $pages);
            }
            $summary = _301InteractiveBot_OpenAI::summarize_transcript($chat_id, $transcript, $settings);
            if ($summary) {
                $wpdb->update($chats, ['summary' => $summary], ['id' => $chat_id]);
            }
        }

        $email_content = _301InteractiveBot_OpenAI::translate_transcript_for_email($chat_id, $summary, $rows, $settings);
        $email_summary = (string)($email_content['summary'] ?? $summary);
        $email_rows = (array)($email_content['rows'] ?? $rows);
        $email_translation_meta = [
            'translated' => !empty($email_content['translated']),
            'source_language' => sanitize_text_field((string)($email_content['source_language'] ?? '')),
        ];

        $payload = self::build_transcript_email_payload($chat_id, $chat, $email_rows, $email_summary, $pages, $settings, $email_translation_meta);
        $sent = self::send_transcript_email($payload);
        if (!$sent) {
            return new WP_REST_Response(['error' => 'Failed to send transcript email'], 500);
        }

        return rest_ensure_response(['ok' => true]);
    }



    public static function admin_block($request) {
        global $wpdb;
        $p = $request->get_json_params();
        $chat_id = (int)($p['chat_id'] ?? 0);
        $reason = self::sanitize_text($p['reason'] ?? '', 255);
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $block = $wpdb->prefix . '301interactivebot_blocklist';
        $chat = $wpdb->get_row($wpdb->prepare("SELECT session_key FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
        if (!$chat) return new WP_REST_Response(['error' => 'Chat not found'], 404);
        $session_key = $chat['session_key'] ?? '';
        if (!$session_key) return new WP_REST_Response(['error' => 'Missing session_key'], 400);
        $wpdb->replace($block, [
            'session_key' => $session_key,
            'reason' => $reason,
            'blocked_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        return rest_ensure_response(['ok' => true]);
    }

    public static function admin_unblock($request) {
        global $wpdb;
        $p = $request->get_json_params();
        $session_key = self::sanitize_text($p['session_key'] ?? '', 64);
        if (!$session_key) return new WP_REST_Response(['error' => 'Missing session_key'], 400);
        $block = $wpdb->prefix . '301interactivebot_blocklist';
        $wpdb->delete($block, ['session_key' => $session_key]);
        return rest_ensure_response(['ok' => true]);
    }

}
