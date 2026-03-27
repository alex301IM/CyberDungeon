<?php
if (!defined('ABSPATH')) exit;

class _301InteractiveBot_Diagnostics {

    public static function run_all() {
        $settings = _301InteractiveBot_Admin::get_settings();

        $results = [];
        $results[] = self::check_openai_connectivity($settings);
        $results[] = self::check_vector_store($settings);
        $results[] = self::check_email_routing($settings);

        return $results;
    }

    public static function check_openai_connectivity($settings) {
        $api_key = trim($settings['openai_api_key'] ?? '');
        if (!$api_key) {
            return self::fail('OpenAI connectivity', 'OpenAI API key is not set.');
        }

        // Lightweight call to /v1/models as a connectivity + auth test
        $resp = wp_remote_get('https://api.openai.com/v1/models', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);

        if (is_wp_error($resp)) {
            _301InteractiveBot_Logger::log('error', 'Diagnostics OpenAI connectivity failed', ['error' => $resp->get_error_message()]);
            return self::fail('OpenAI connectivity', 'Request failed: ' . $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);

        if ($code >= 200 && $code < 300) {
            return self::pass('OpenAI connectivity', 'Successfully authenticated and reached OpenAI.');
        }

        _301InteractiveBot_Logger::log('error', 'Diagnostics OpenAI connectivity HTTP error', ['http_code' => $code, 'raw' => $raw]);
        return self::fail('OpenAI connectivity', 'HTTP ' . $code . ' from OpenAI. Check API key and outbound connectivity.', ['http_code' => $code, 'raw' => $raw]);
    }

    public static function check_vector_store($settings) {
        $api_key = trim($settings['openai_api_key'] ?? '');
        $vs = trim($settings['vector_store_id'] ?? '');

        if (!$vs) {
            return self::warn('Vector store', 'Vector Store ID is not set. file_search retrieval will be disabled.');
        }
        if (!$api_key) {
            return self::fail('Vector store', 'OpenAI API key is not set (required to validate the Vector Store ID).');
        }

        // Check vector store exists
        $resp = wp_remote_get('https://api.openai.com/v1/vector_stores/' . rawurlencode($vs), [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($resp)) {
            _301InteractiveBot_Logger::log('error', 'Diagnostics vector store request failed', ['error' => $resp->get_error_message()]);
            return self::fail('Vector store', 'Request failed: ' . $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);

        if ($code >= 200 && $code < 300) {
            $j = json_decode($raw, true);
            $name = is_array($j) ? ($j['name'] ?? '') : '';
            return self::pass('Vector store', 'Vector Store ID is valid.' . ($name ? (' Name: ' . $name) : ''));
        }

        _301InteractiveBot_Logger::log('error', 'Diagnostics vector store HTTP error', ['http_code' => $code, 'raw' => $raw]);
        return self::fail('Vector store', 'Could not fetch Vector Store. Verify the ID and that the API key has access.', ['http_code' => $code, 'raw' => $raw]);
    }

    public static function check_email_routing($settings) {
        $default = sanitize_email($settings['default_admin_email'] ?? get_option('admin_email'));
        $areas = _301InteractiveBot_Admin::get_service_area_config($settings);

        $issues = [];
        if (!$default) $issues[] = 'Default admin email is not set/valid.';
        if (!is_array($areas) || empty($areas)) $issues[] = 'Service Area Config is empty (lead county dropdown will be empty).';

        if (!empty($issues)) {
            return self::fail('Email routing', implode(' ', $issues), ['default' => $default]);
        }

        return self::pass('Email routing', 'Service Area Config is set. Leads will route to default admin email and include matching price list metadata.');
    }

    private static function pass($name, $message, $details = null) {
        return ['name' => $name, 'status' => 'pass', 'message' => $message, 'details' => $details];
    }
    private static function warn($name, $message, $details = null) {
        return ['name' => $name, 'status' => 'warn', 'message' => $message, 'details' => $details];
    }
    private static function fail($name, $message, $details = null) {
        return ['name' => $name, 'status' => 'fail', 'message' => $message, 'details' => $details];
    }
}