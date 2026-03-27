<?php
if (!defined('ABSPATH')) exit;

class _301InteractiveBot_DB {
    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
        add_action('301interactivebot_auto_end_inactive_chats', ['_301InteractiveBot_REST', 'auto_end_inactive_chats']);
        self::schedule_weekly_vector_export();
        self::schedule_inactive_chat_cleanup();
    }

    public static function activate() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset = $wpdb->get_charset_collate();
        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';
        $events= $wpdb->prefix . '301interactivebot_events';
        $logs  = $wpdb->prefix . '301interactivebot_logs';
        $block = $wpdb->prefix . '301interactivebot_blocklist';
        $blocks = $wpdb->prefix . '301interactivebot_blocks';
        $jobs = $wpdb->prefix . '301interactivebot_jobs';
        $reports = $wpdb->prefix . '301interactivebot_reports';

        $sql1 = "CREATE TABLE $chats (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            current_page TEXT NULL,
            referrer TEXT NULL,
            utm_source VARCHAR(255) NULL,
            utm_medium VARCHAR(255) NULL,
            utm_campaign VARCHAR(255) NULL,
            utm_term VARCHAR(255) NULL,
            utm_content VARCHAR(255) NULL,
            build_city VARCHAR(100) NULL,
            build_county VARCHAR(100) NULL,
            build_state VARCHAR(100) NULL,
            lead_first VARCHAR(255) NULL,
            lead_last VARCHAR(255) NULL,
            lead_phone VARCHAR(255) NULL,
            lead_email VARCHAR(255) NULL,
            lead_address VARCHAR(255) NULL,
            summary LONGTEXT NULL,
            admin_takeover TINYINT(1) NOT NULL DEFAULT 0,
            admin_user_id BIGINT UNSIGNED NULL,
            last_user_ip VARCHAR(64) NULL,
            user_agent TEXT NULL,
            PRIMARY KEY  (id),
            KEY session_key (session_key),
            KEY status (status),
            KEY build_city (build_city),
            KEY build_county (build_county)
        ) $charset;";

        $sql2 = "CREATE TABLE $msgs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id BIGINT UNSIGNED NOT NULL,
            sender VARCHAR(16) NOT NULL,
            message LONGTEXT NOT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
            KEY created_at (created_at)
        ) $charset;";

        $sql3 = "CREATE TABLE $events (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            event_value TEXT NULL,
            url TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset;";


        $sql4 = "CREATE TABLE $logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(32) NOT NULL,
            message TEXT NOT NULL,
            context longtext NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset;";


        $sql5 = "CREATE TABLE $block (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(64) NOT NULL,
            reason VARCHAR(255) NULL,
            blocked_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_key (session_key),
            KEY created_at (created_at)
        ) $charset;";


        $sql6 = "CREATE TABLE $blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            block_type VARCHAR(16) NOT NULL,
            block_value VARCHAR(128) NOT NULL,
            reason VARCHAR(255) NULL,
            blocked_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY type_value (block_type, block_value),
            KEY created_at (created_at)
        ) $charset;";


        $sql7 = "CREATE TABLE $jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id BIGINT UNSIGNED NOT NULL,
            user_message_id BIGINT UNSIGNED NULL,
            status VARCHAR(16) NOT NULL,
            queued_at DATETIME NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            duration_ms INT NULL,
            engine VARCHAR(32) NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";


        $sql8 = "CREATE TABLE $reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_type VARCHAR(64) NOT NULL,
            date_start DATE NULL,
            date_end DATE NULL,
            extra_instruction TEXT NULL,
            report_json LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY report_type (report_type),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        dbDelta($sql5);
        dbDelta($sql6);
        dbDelta($sql7);
        dbDelta($sql8);

        $site_name = sanitize_text_field(get_bloginfo('name'));
        if ($site_name === '') $site_name = 'Website';
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $site_host = is_string($site_host) ? strtolower($site_host) : '';
        $site_host = preg_replace('/^www\./', '', $site_host);
        $default_from_email = $site_host ? 'no-reply@' . $site_host : 'no-reply@domain.com';

        add_option('301interactivebot_settings', [
            'openai_api_key' => '',
            'model' => 'gpt-4.1-mini',
            'vector_store_id' => '',
            'default_admin_email' => get_option('admin_email'),
            'company_name' => $site_name,
            'from_email' => $default_from_email,
            'service_area_config' => [],
            'build_cities' => [],
            'build_counties' => [],
            'city_email_map' => [],
            'county_email_map' => [],
            'faq_json' => '[]',
            'system_prompt' => self::default_system_prompt(),
            'autoload' => 1,
            'show_mode' => 'floating',
            'position' => 'bottom-right',
            'offset_x_desktop' => 20,
            'offset_y_desktop' => 20,
            'offset_x_mobile' => 12,
            'offset_y_mobile' => 12,
            'mobile_breakpoint' => 768,
            'z_index' => 999999,
            'include_pages_raw' => '',
            'exclude_pages_raw' => '',
            'lead_capture_mode' => 'form',
            'show_recommended_links' => 0,
            'require_email' => 0,
            'require_phone' => 0,
            'require_address' => 1,
            'escalation_enabled' => 1,
            'escalation_keywords_raw' => "price\npricing\nquote\nestimate\ncost\nhow much\ntalk to someone\nspeak to someone\nhuman",
            'lead_prompt_intro' => 'To help with your request, please share your contact details.',
            'enable_third_party_api' => 0,
        ]);

        self::schedule_weekly_vector_export();
        self::schedule_inactive_chat_cleanup();
    }

    public static function deactivate() {
        self::unschedule_weekly_vector_export();
        self::unschedule_inactive_chat_cleanup();
    }

    public static function schedule_weekly_vector_export() {
        if (wp_next_scheduled('301interactivebot_weekly_vector_export')) return;

        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $next = $now->setTime(0, 0, 0);

        if ((int)$now->format('w') !== 0 || $now >= $next) {
            $next = $next->modify('next sunday');
        }

        wp_schedule_event($next->getTimestamp(), 'weekly', '301interactivebot_weekly_vector_export');
    }


    public static function add_cron_schedules($schedules) {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Every Five Minutes', '301interactive-chatbot'),
            ];
        }
        return $schedules;
    }

    public static function schedule_inactive_chat_cleanup() {
        if (!wp_next_scheduled('301interactivebot_auto_end_inactive_chats')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'five_minutes', '301interactivebot_auto_end_inactive_chats');
        }
    }

    public static function unschedule_inactive_chat_cleanup() {
        $timestamp = wp_next_scheduled('301interactivebot_auto_end_inactive_chats');
        while ($timestamp) {
            wp_unschedule_event($timestamp, '301interactivebot_auto_end_inactive_chats');
            $timestamp = wp_next_scheduled('301interactivebot_auto_end_inactive_chats');
        }
    }

    public static function unschedule_weekly_vector_export() {
        $timestamp = wp_next_scheduled('301interactivebot_weekly_vector_export');
        while ($timestamp) {
            wp_unschedule_event($timestamp, '301interactivebot_weekly_vector_export');
            $timestamp = wp_next_scheduled('301interactivebot_weekly_vector_export');
        }
    }

    public static function default_system_prompt() {
        return "You are a website assistant.\n\n"
        . "Rules:\n"
        . "- Answer using ONLY information found in retrieved website content and the provided FAQs.\n"
        . "- If you cannot find the answer in retrieved content, say you are not sure and suggest the customer request more info.\n"
        . "- When relevant, recommend a specific page and include its URL.\n"
        . "- Be concise, friendly, and professional.\n"
        . "- Ask only one question at a time.\n"
        . "- If the customer asks about pricing, a quote, or wants to talk to someone, ask for First Name, Last Name, Address, and optional Phone + Email.\n"
        . "- Only suggest recommended pages when you are answering a question (not when asking the customer for info).\n"
        . "- If admin takeover is active, do not respond as the bot.";
    }

    public static function maybe_upgrade() {
        global $wpdb;
        $logs = $wpdb->prefix . '301interactivebot_logs';
        $chats = $wpdb->prefix . '301interactivebot_chats';
        // Add 'context' column to logs table if missing (prevents PHP 8+ undefined index warnings)
        $has = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$logs} LIKE %s", 'context'));
        if (!$has) {
            $wpdb->query("ALTER TABLE {$logs} ADD COLUMN context longtext NULL");
        }
        $reports = $wpdb->prefix . '301interactivebot_reports';
        $has_reports = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $reports));
        if (!$has_reports) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset = $wpdb->get_charset_collate();
            $sql_reports = "CREATE TABLE $reports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                report_type VARCHAR(64) NOT NULL,
                date_start DATE NULL,
                date_end DATE NULL,
                extra_instruction TEXT NULL,
                report_json LONGTEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY report_type (report_type),
                KEY created_at (created_at)
            ) $charset;";
            dbDelta($sql_reports);
        }
        $has_county = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$chats} LIKE %s", 'build_county'));
        if (!$has_county) {
            $wpdb->query("ALTER TABLE {$chats} ADD COLUMN build_county VARCHAR(100) NULL");
        }
        $has_state = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$chats} LIKE %s", 'build_state'));
        if (!$has_state) {
            $wpdb->query("ALTER TABLE {$chats} ADD COLUMN build_state VARCHAR(100) NULL");
        }
        $has_address = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$chats} LIKE %s", 'lead_address'));
        if (!$has_address) {
            $wpdb->query("ALTER TABLE {$chats} ADD COLUMN lead_address VARCHAR(255) NULL");
        }
    }

}
