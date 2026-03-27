<?php
/**
 * Plugin Name: 301 Interactive Chatbot (OpenAI)
 * Description: AI chatbot with RAG (OpenAI file_search), FAQs, lead capture, analytics, transcripts, and admin live takeover.
 * Version: 1.5.0
 * Author: 301 Interactive - Chatbot
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

define('_301INTERACTIVEBOT_VERSION', '1.4.3');
define('_301INTERACTIVEBOT_PLUGIN_FILE', __FILE__);
define('_301INTERACTIVEBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('_301INTERACTIVEBOT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once _301INTERACTIVEBOT_PLUGIN_DIR . 'includes/class-db.php';
require_once _301INTERACTIVEBOT_PLUGIN_DIR . 'includes/class-async.php';
require_once _301INTERACTIVEBOT_PLUGIN_DIR . 'includes/class-openai.php';
require_once _301INTERACTIVEBOT_PLUGIN_DIR . 'includes/class-logger.php';
require_once _301INTERACTIVEBOT_PLUGIN_DIR . 'includes/class-diagnostics.php';
require_once _301INTERACTIVEBOT_PLUGIN_DIR . 'includes/class-rest.php';
require_once _301INTERACTIVEBOT_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, ['_301InteractiveBot_DB', 'activate']);
register_deactivation_hook(__FILE__, ['_301InteractiveBot_DB', 'deactivate']);

add_action('plugins_loaded', function() {
    _301InteractiveBot_DB::init();
    _301InteractiveBot_DB::maybe_upgrade();
    _301InteractiveBot_Logger::init();
    _301InteractiveBot_REST::init();
    _301InteractiveBot_Admin::init();
    _301InteractiveBot_Async::init();
});


add_action('wp_ajax_301interactivebot_list_chats', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    global $wpdb;
    $chats = $wpdb->prefix . '301interactivebot_chats';
    $rows = $wpdb->get_results("SELECT id, COALESCE(NULLIF(build_county,''), NULLIF(build_city,'')) AS build_county, build_city, build_state, lead_first, lead_last, status, started_at, admin_takeover FROM $chats WHERE status='active' ORDER BY started_at DESC LIMIT 50", ARRAY_A);
    wp_send_json_success($rows);
});


add_action('wp_footer', function () {
    if (is_admin()) return;
    $settings = _301InteractiveBot_Admin::get_settings();
    if (empty($settings['autoload'])) return;
    if (!_301InteractiveBot_Admin::should_show_on_page($settings)) return;

    static $rendered = false;
    if ($rendered) return;
    $rendered = true;

    echo do_shortcode('[301interactive_chatbot]');
});

add_shortcode('301interactive_chatbot', function($atts = []) {
    $settings = _301InteractiveBot_Admin::get_settings();

    // Page include/exclude rules
    if (!_301InteractiveBot_Admin::should_show_on_page($settings)) {
        return '';
    }

    $companyName = !empty($settings['company_name']) ? $settings['company_name'] : '301 Interactive';

    $atts = shortcode_atts([
        'title' => $companyName.' Assistant',
        'welcome' => sprintf(
            'Hi! I can help answer questions about %s and point you to the right page. What can I help with?',
            $companyName
        ),
    ], $atts);

    wp_enqueue_style('301interactivebot-chat', _301INTERACTIVEBOT_PLUGIN_URL . 'assets/chatbot.css', [], _301INTERACTIVEBOT_VERSION);
    wp_enqueue_script('301interactivebot-chat', _301INTERACTIVEBOT_PLUGIN_URL . 'assets/chatbot.js', ['jquery'], _301INTERACTIVEBOT_VERSION, true);

    wp_localize_script('301interactivebot-chat', '_301InteractiveBot', [
        'restBase' => esc_url_raw(rest_url('301interactivebot/v1')),
        'nonce' => wp_create_nonce('wp_rest'),
        'welcome' => $atts['welcome'],
        'idleTimeoutSeconds' => (int)($settings['idle_timeout_seconds'] ?? 300),
        'leadCaptureMode' => $settings['lead_capture_mode'] ?? 'form',
        'showRecommendedLinks' => !empty($settings['show_recommended_links']),
        'requireEmail' => !empty($settings['require_email']),
        'requirePhone' => !empty($settings['require_phone']),
        'requireAddress' => !empty($settings['require_address']),
        'escalationEnabled' => !empty($settings['escalation_enabled']),
        'escalationKeywords' => preg_split('/[\r\n,;]+/', (string)($settings['escalation_keywords_raw'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
        'leadPromptIntro' => $settings['lead_prompt_intro'] ?? 'To help with your request, please share your contact details.',

        // Widget settings
        'widgetMode' => $settings['show_mode'] ?? 'floating',
        'widgetPosition' => $settings['position'] ?? 'bottom-right',
        'widgetOffsetXDesktop' => (int)($settings['offset_x_desktop'] ?? 20),
        'widgetOffsetYDesktop' => (int)($settings['offset_y_desktop'] ?? 20),
        'widgetOffsetXMobile'  => (int)($settings['offset_x_mobile'] ?? 12),
        'widgetOffsetYMobile'  => (int)($settings['offset_y_mobile'] ?? 12),
        'widgetMobileBreakpoint' => (int)($settings['mobile_breakpoint'] ?? 768),
        'widgetZIndex' => (int)($settings['z_index'] ?? 999999),

        // Theme + branding
        'primaryColor' => $settings['primary_color'] ?? '#0b1f3a',
        'accentColor'  => $settings['accent_color'] ?? '#2563eb',
        'bubbleColor'  => $settings['bubble_color'] ?? '#2563eb',
        'textColor'    => $settings['text_color'] ?? '#0b1f3a',
        'closedIcon'   => $settings['closed_icon'] ?? 'chat',
        'logoUrl'      => (!empty($settings['logo_id']) ? wp_get_attachment_image_url((int)$settings['logo_id'], 'full') : ''),

        // Lead dropdown + service area config
        'serviceAreaConfig' => array_values((array)_301InteractiveBot_Admin::get_service_area_config($settings)),
        'buildCounties' => array_values((array)($settings['build_counties'] ?? $settings['build_cities'] ?? [])),
    ]);

    $style = sprintf(
        '--301interactivebot-primary:%s;--301interactivebot-accent:%s;--301interactivebot-bubble:%s;--301interactivebot-text:%s;',
        esc_attr($settings['primary_color'] ?? '#0b1f3a'),
        esc_attr($settings['accent_color'] ?? '#2563eb'),
        esc_attr($settings['bubble_color'] ?? '#2563eb'),
        esc_attr($settings['text_color'] ?? '#0b1f3a')
    );

    ob_start(); ?>
    <div class="301interactivebot-widget" data-title="<?php echo esc_attr($atts['title']); ?>" style="<?php echo $style; ?>">
      <button class="301interactivebot-bubble" type="button" aria-label="Open chat">
        <span class="301interactivebot-bubble-icon">💬</span>
      </button>

      <div class="301interactivebot-window" aria-hidden="true">
        <div class="301interactivebot-header">
          <div class="301interactivebot-title-wrap">
            <img class="301interactivebot-logo" alt="" style="display:none"/>
            <div class="301interactivebot-title"><?php echo esc_html($atts['title']); ?></div>
          </div>
          <button class="301interactivebot-toggle" type="button" aria-label="Minimize">—</button>
        </div>

        <div class="301interactivebot-body">
          <div class="301interactivebot-messages"></div>

          <div class="301interactivebot-thinking" aria-live="polite">
            <span class="301interactivebot-spinner" aria-hidden="true"></span>
            <span class="301interactivebot-thinking-text">Thinking…</span>
          </div>

          <div class="301interactivebot-status"></div>

          <div class="301interactivebot-input-row">
            <input class="301interactivebot-input" type="text" placeholder="Type your message..." />
            <button class="301interactivebot-send" type="button">Send</button>
          </div>

          <div class="301interactivebot-lead" style="display:none">
            <div class="301interactivebot-lead-title">Get more information</div>
            <div class="301interactivebot-lead-grid">
              <input class="301interactivebot-lead-first" type="text" placeholder="First Name" />
              <input class="301interactivebot-lead-last" type="text" placeholder="Last Name" />
              <input class="301interactivebot-lead-phone" type="tel" placeholder="Phone (optional)" />
              <input class="301interactivebot-lead-email" type="email" placeholder="Email (optional)" />
              <input class="301interactivebot-lead-address" type="text" placeholder="Address" />
            </div>
            <button class="301interactivebot-lead-submit" type="button">Submit</button>
          </div>

          <div class="301interactivebot-end-row">
            <button class="301interactivebot-endchat" type="button">End chat</button>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});
