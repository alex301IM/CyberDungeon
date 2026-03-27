<?php
if (!defined('ABSPATH')) exit;

class _301InteractiveBot_Async {

    const ACTION = '301interactivebot_process_ai_message';
    const CRON_HOOK = '301interactivebot_cron_process_ai_message';

    public static function init() {
        // Action Scheduler hook (preferred)
        add_action(self::ACTION, [__CLASS__, 'process'], 10, 2);

        // WP-Cron fallback
        add_action(self::CRON_HOOK, [__CLASS__, 'process'], 10, 2);
    }

    public static function enqueue($chat_id, $user_message_id) {
        $chat_id = (int)$chat_id;
        $user_message_id = (int)$user_message_id;
        if (!$chat_id || !$user_message_id) return false;

        global $wpdb;
        if (!$wpdb || !is_object($wpdb)) return false;
        $jobs = $wpdb->prefix . '301interactivebot_jobs';

        // Record job
        $job_id = 0;
        try {
            $wpdb->insert($jobs, [
                'chat_id' => (int)$chat_id,
                'user_message_id' => (int)$user_message_id,
                'status' => 'queued',
                'queued_at' => current_time('mysql'),
                'engine' => (function_exists('as_enqueue_async_action') ? 'action_scheduler' : 'wp_cron'),
                'created_at' => current_time('mysql'),
            ]);
            $job_id = (int)$wpdb->insert_id;
        } catch (Exception $e) {
            // ignore
        }

        // Prefer Action Scheduler if available (WooCommerce etc.)
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION, [$chat_id, $user_message_id], '301interactivebot');
            return true;
        }

        // WP-Cron fallback (runs ASAP)
        if (!wp_next_scheduled(self::CRON_HOOK, [$chat_id, $user_message_id])) {
            wp_schedule_single_event(time() + 1, self::CRON_HOOK, [$chat_id, $user_message_id]);
        }
        return true;
    }

    public static function process($chat_id, $user_message_id) {
        global $wpdb;
        $jobs = $wpdb->prefix . '301interactivebot_jobs';

        $chat_id = (int)$chat_id;
        $user_message_id = (int)$user_message_id;
        if (!$chat_id) return;

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $msgs  = $wpdb->prefix . '301interactivebot_messages';
        $jobs  = $wpdb->prefix . '301interactivebot_jobs';

        $job_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $jobs WHERE chat_id=%d AND user_message_id=%d ORDER BY id DESC LIMIT 1",
            $chat_id, $user_message_id
        ), ARRAY_A);
        $job_id = $job_row ? (int)$job_row['id'] : 0;
        $t0 = microtime(true);
        if ($job_id) {
            $wpdb->update($jobs, [
                'status' => 'started',
                'started_at' => current_time('mysql'),
            ], ['id' => $job_id]);
        }

        $chat = $wpdb->get_row($wpdb->prepare("SELECT * FROM $chats WHERE id=%d", $chat_id), ARRAY_A);
        if (!$chat) return;

        // Don't respond if chat ended or admin takeover enabled
        if (($chat['status'] ?? '') === 'ended') return;
        if ((int)($chat['admin_takeover'] ?? 0) === 1) return;

        // Avoid double-processing: if there's already a bot message after this user message, skip.
        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $msgs WHERE chat_id=%d AND sender='bot' AND id > %d ORDER BY id ASC LIMIT 1",
            $chat_id, $user_message_id
        ));
        if ($already) return;

        // Load history (cap to last 30 rows)
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT sender, message FROM $msgs WHERE chat_id=%d ORDER BY id ASC LIMIT 16",
            $chat_id
        ), ARRAY_A);

        $model_messages = [];foreach ($history as $h) {
            $role = ($h['sender'] === 'user') ? 'user' : 'assistant';
            if ($h['sender'] === 'admin') $role = 'assistant';
            $model_messages[] = [
                'role' => $role,
                'content' => $h['message']
            ];}

        $settings = _301InteractiveBot_Admin::get_settings();

        try {
            $resp = _301InteractiveBot_OpenAI::respond($chat_id, $model_messages, $settings);
            $reply_text = $resp['text'] ?? '';
            $meta = $resp['meta'] ?? [];if ($reply_text) {
                $wpdb->insert($msgs, [
                    'chat_id' => $chat_id,
                    'sender' => 'bot',
                    'message' => $reply_text,
                    'meta_json' => wp_json_encode($meta),
                    'created_at' => current_time('mysql'),
                ]);

            if ($job_id) {
                $ms = (int)round((microtime(true) - $t0) * 1000);
                $wpdb->update($jobs, [
                    'status' => 'finished',
                    'finished_at' => current_time('mysql'),
                    'duration_ms' => $ms,
                ], ['id' => $job_id]);
            }

            }
        } catch (Exception $e) {
            if (!empty($job_id)) {
                $ms = (int)round((microtime(true) - $t0) * 1000);
                $wpdb->update($jobs, [
                    'status' => 'failed',
                    'finished_at' => current_time('mysql'),
                    'duration_ms' => $ms,
                    'last_error' => $e->getMessage(),
                ], ['id' => $job_id]);
            }
            _301InteractiveBot_Logger::log('error', 'Async AI processing failed', $e->getMessage());
        }
    }
}