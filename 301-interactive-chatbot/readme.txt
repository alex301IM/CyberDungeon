=== 301 Interactive Chatbot (OpenAI) ===
Contributors: your-team
Tags: chatbot, openai, ai, lead capture
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

== Description ==
A 301 Interactive website chatbot that can answer questions using TaylorHomes.com content (RAG via OpenAI file_search), reference FAQs, collect lead info, store transcripts + analytics, email transcripts routed by build city, and allow admin live takeover.

== Installation ==
1. Upload the plugin zip in WP Admin → Plugins → Add New → Upload Plugin
2. Activate
3. Go to 301 Interactive Chatbot → Settings and set:
   - OpenAI API Key
   - Vector Store ID (recommended)
   - Build Cities
   - City → Admin Email routing map
   - FAQs
4. Add shortcode [301interactive_chatbot] to any page.

== Notes ==
- For best answers, create an OpenAI Vector Store and upload site content chunks + FAQs.
- Admin takeover works from WP Admin → 301 Interactive Chatbot → Live Chats.


== Widget Options ==
- Auto-load in footer (no shortcode needed)
- Floating/Inline mode
- Corner positioning with separate mobile/desktop offsets
- Include/Exclude page rules
- Collapse to bubble when minimized


== Logging ==
The plugin records PHP notices/warnings/fatals originating from this plugin in WP Admin → 301 Interactive Chatbot → Logs.


== Diagnostics ==
WP Admin → 301 Interactive Chatbot → Diagnostics runs checks for OpenAI connectivity, Vector Store ID validation, and email routing coverage.


== Blocked Users ==
WP Admin → 301 Interactive Chatbot → Blocked Users lets admins view, search, filter, and unblock entries.
