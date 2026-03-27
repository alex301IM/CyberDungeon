<?php
if (!defined('ABSPATH')) exit;

class _301InteractiveBot_Logger {

    public static function init() {
        // Capture PHP notices/warnings triggered during WP lifecycle
        set_error_handler([__CLASS__, 'handle_error'], E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

        // Capture fatals on shutdown (can't prevent, but we can record)
        register_shutdown_function([__CLASS__, 'handle_shutdown']);
    }

    public static function log($level, $message, $context = null) {
        global $wpdb;
        $table = $wpdb->prefix . '301interactivebot_logs';

        $level = sanitize_text_field((string)$level);
        $message = wp_strip_all_tags((string)$message);

        if ($context !== null && !is_string($context)) {
            $context = wp_json_encode($context);
        }
        if ($context !== null) {
            $context = (string)$context;
            if (strlen($context) > 10000) $context = substr($context, 0, 10000);
        }

        $wpdb->insert($table, [
            'level' => $level ?: 'info',
            'message' => $message,
            'context_json' => $context,
            'created_at' => current_time('mysql'),
        ]);

        // Also send to PHP error_log for server visibility
        error_log("[_301InteractiveBot][$level] $message");
    }

    public static function handle_error($errno, $errstr, $errfile, $errline) {
        // Only log errors from this plugin to reduce noise
        if (strpos($errfile, '301interactive-chatbot') === false) {
            return false; // let WP handle
        }
        $level = self::errno_to_level($errno);
        self::log($level, $errstr, ['file' => $errfile, 'line' => $errline, 'errno' => $errno]);
        return false; // do not swallow
    }

    public static function handle_shutdown() {
        $e = error_get_last();
        if (!$e) return;

        // Fatal-like errors
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($e['type'], $fatal_types, true)) return;

        if (strpos(($e['file'] ?? ''), '301interactive-chatbot') === false) return;

        self::log('fatal', $e['message'] ?? 'Fatal error', $e);
    }

    private static function errno_to_level($errno) {
        switch ($errno) {
            case E_USER_ERROR:
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                return 'error';
            case E_USER_WARNING:
            case E_WARNING:
                return 'warning';
            case E_USER_NOTICE:
            case E_NOTICE:
                return 'notice';
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'deprecated';
            default:
                return 'info';
        }
    }
}