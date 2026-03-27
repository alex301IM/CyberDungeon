<?php
if (!defined('ABSPATH')) exit;

class _301InteractiveBot_OpenAI {

    public static function respond($chat_id, $messages, $settings) {
        $api_key = trim($settings['openai_api_key'] ?? '');
        if (!$api_key) {
            return ['text' => "Chatbot isn't configured yet. Please contact us for help.", 'meta' => []];
        }

        $model = $settings['model'] ?? 'gpt-4.1-mini';
        $vector_store_id = trim($settings['vector_store_id'] ?? '');
        $system = $settings['system_prompt'] ?? _301InteractiveBot_DB::default_system_prompt();

        // Responses API expects input messages with string content (Chat Completions-compatible shape).
        $input = [];
        $input[] = ['role' => 'system', 'content' => $system];
        $input[] = [
            'role' => 'system',
            'content' => 'Lead capture rule override: Required fields are First Name, Last Name, Email, County, and State. Phone is optional and must never be required.',
        ];

        $price_list_data = self::get_chat_price_list_data($chat_id, $settings);
        $price_list_context = self::build_price_list_context($price_list_data);
        if ($price_list_context) {
            $input[] = [
                'role' => 'system',
                'content' => $price_list_context,
            ];
        }

        // Append chat history
        foreach ($messages as $m) {
            $role = $m['role'];
            $text = $m['content'];
            $input[] = [
                'role' => $role,
                'content' => $text,
            ];
        }

        // Provide FAQs as additional context (also recommended to upload FAQs to the Vector Store)
        $faq = $settings['faq_json'] ?? '';
        $faq_text = $faq && $faq !== '[]' ? self::faq_as_text($faq) : '';
        if ($faq_text) {
            $input[] = [
                'role' => 'system',
                'content' => "FAQ Reference (may be used if relevant):\n" . $faq_text,
            ];
        }

        $tools = [];
        if ($vector_store_id) {
            $tools[] = [
                'type' => 'file_search',
                'vector_store_ids' => [$vector_store_id],
                'max_num_results' => 3
            ];
        }

        $body = [
            'model' => $model,
            'input' => $input,
            'max_output_tokens' => 900,        
            'reasoning' => ['effort' => 'low'],
        ];
        if (!empty($tools)) $body['tools'] = $tools;

        // Structured Outputs (Responses API): use text.format json_schema
        $body['text'] = [
            'verbosity' => 'low',
            'format' => [
                'type' => 'json_schema',
                'name' => '301interactivebot_answer',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => ['type' => 'string'],
                        'suggested_links' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'url' => ['type' => 'string']
                            ],
                            'required' => ['title','url'],
                            'additionalProperties' => false
                        ]
                    ],
                        'should_collect_lead' => ['type' => 'boolean']
                    ],
                    'required' => ['answer', 'suggested_links', 'should_collect_lead'],
                    'additionalProperties' => false
                ]
            ]
        ];
        // GPT-5 family does not support temperature; ensure it's omitted.
        if (preg_match('/^gpt-5/i', $model)) { unset($body['temperature']); }


        // Log request body for diagnostics (API key not included)
        _301InteractiveBot_Logger::log('debug', 'OpenAI request (body)', [
            'url' => 'https://api.openai.com/v1/responses',
            'body' => $body,
        ]);

        $resp = self::request_response($api_key, $body, 45, 1);

        if (is_wp_error($resp)) {
            _301InteractiveBot_Logger::log('error', 'OpenAI request failed', ['error' => $resp->get_error_message()]);
            return ['text' => "Sorry—I'm having trouble right now. Please try again.", 'meta' => ['error' => $resp->get_error_message()]];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            _301InteractiveBot_Logger::log('error', 'OpenAI HTTP error', ['http_code' => $code, 'raw' => $raw]);
            return ['text' => "Sorry—I'm having trouble right now. Please try again.", 'meta' => ['http_code' => $code, 'raw' => $raw]];
        }

        $json = json_decode($raw, true);

        // If the model hit max_output_tokens before producing output_text, retry once with a higher limit.
        if (is_array($json) && ($json['status'] ?? '') === 'incomplete' && (($json['incomplete_details']['reason'] ?? '') === 'max_output_tokens') && !empty($json['id'])) {
            $retry = $body;
            $retry['previous_response_id'] = $json['id'];
            $retry['max_output_tokens'] = 1400;
            $retry['input'] = [
                ['role' => 'user', 'content' => 'Continue. Return ONLY the final JSON that matches the schema.']
            ];
            _301InteractiveBot_Logger::log('warning', 'OpenAI incomplete (max_output_tokens). Retrying once.', ['resp_id' => $json['id']]);
            $resp2 = self::request_response($api_key, $retry, 45, 1);
            if (!is_wp_error($resp2)) {
                $code2 = wp_remote_retrieve_response_code($resp2);
                $raw2 = wp_remote_retrieve_body($resp2);
                if ($code2 >= 200 && $code2 < 300) {
                    $json2 = json_decode($raw2, true);
                    if (is_array($json2)) { $json = $json2; $raw = $raw2; }
                }
            }
        }

        $text = self::extract_json_output($json);

        if (!$text) {
            $fallback = self::extract_output_text($json);
            _301InteractiveBot_Logger::log('warning', 'OpenAI response not JSON schema', ['raw' => $json]);
            return ['text' => $fallback ?: "Sorry—I'm having trouble right now. Please try again.", 'meta' => ['raw' => $json]];
        }

        $answer = trim($text['answer'] ?? '');
        $links = $text['suggested_links'] ?? [];
        $should_collect = (bool)($text['should_collect_lead'] ?? false);

        if (self::is_question($answer)) {
            $links = [];
        }
        $answer_out = self::limit_questions($answer);
        $answer_out = self::enforce_price_list_messaging($answer_out, $messages, $price_list_data);

        return [
            'text' => $answer_out,
            'meta' => [
                'suggested_links' => $links,
                'should_collect_lead' => $should_collect,
                'raw' => $text
            ]
        ];
    }

    private static function get_chat_price_list_data($chat_id, $settings) {
        global $wpdb;
        $chat_id = (int)$chat_id;
        if (!$chat_id) return null;

        $chats = $wpdb->prefix . '301interactivebot_chats';
        $chat = $wpdb->get_row($wpdb->prepare(
            "SELECT build_county, build_state FROM {$chats} WHERE id=%d",
            $chat_id
        ), ARRAY_A);
        if (empty($chat) || empty($chat['build_county'])) return null;

        $county = sanitize_text_field($chat['build_county'] ?? '');
        $state = strtoupper(sanitize_text_field($chat['build_state'] ?? ''));

        $service_area = _301InteractiveBot_Admin::find_service_area_by_county($county, $state, $settings);
        $price_list_link = esc_url_raw($service_area['price-list-link'] ?? '');
        if (!$price_list_link) return null;

        $price_list_name = sanitize_text_field($service_area['price-list-name'] ?? '');

        return [
            'county' => $county,
            'state' => $state,
            'location' => trim($county . ($state ? ', ' . $state : '')),
            'price_list_name' => $price_list_name ?: 'Price List',
            'price_list_link' => $price_list_link,
        ];
    }

    private static function build_price_list_context($price_list_data) {
        if (empty($price_list_data['price_list_link'])) return '';

        $location = $price_list_data['location'] ?? 'this county';
        $label = $price_list_data['price_list_name'] ?? 'Price List';
        $link = $price_list_data['price_list_link'];

        return "Lead info has already been submitted for {$location}. "
            . "Include this county-specific {$label} link in your first reply immediately after lead information is submitted: {$link}. "
            . "If the user asks about pricing, do not say you're unsure; direct them to this link for specific floor plan pricing.";
    }

    private static function enforce_price_list_messaging($answer, $messages, $price_list_data) {
        if (empty($price_list_data['price_list_link'])) return $answer;

        $link = $price_list_data['price_list_link'];
        $location = $price_list_data['location'] ?? 'your county';
        $label = $price_list_data['price_list_name'] ?? 'Price List';
        $is_pricing_request = self::is_pricing_request($messages);

        if ($is_pricing_request) {
            return "For specific floor plan pricing, please refer to the {$location} {$label}: {$link}";
        }

        if (!self::is_lead_submission_message($messages)) {
            return $answer;
        }

        if (stripos($answer, $link) !== false) return $answer;

        $suffix = "

Your {$location} {$label}: {$link}";
        return trim($answer . $suffix);
    }

    private static function is_lead_submission_message($messages) {
        if (!is_array($messages) || empty($messages)) return false;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if (($msg['role'] ?? '') !== 'user') continue;
            $text = trim((string)($msg['content'] ?? ''));
            if ($text === '') return false;

            return stripos($text, 'Lead info submitted:') === 0 || stripos($text, 'Customer info submitted:') === 0;
        }

        return false;
    }

    private static function is_pricing_request($messages) {
        if (!is_array($messages) || empty($messages)) return false;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if (($msg['role'] ?? '') !== 'user') continue;
            $text = strtolower((string)($msg['content'] ?? ''));
            if ($text === '') return false;

            return preg_match('/\b(price|pricing|cost|quote|how much|plan price|floor plan price|build price)\b/i', $text) === 1;
        }

        return false;
    }

    private static function is_question($text) {
        if (!$text) return false;
        return strpos($text, '?') !== false;
    }

    private static function limit_questions($text) {
        if (!$text) return '';
        $count = substr_count($text, '?');
        if ($count <= 1) return $text;
        $pos = strpos($text, '?');
        if ($pos === false) return $text;
        return trim(substr($text, 0, $pos + 1));
    }

    private static function extract_output_text($json) {
        // Responses API provides output_text in some SDKs; in raw JSON it's in output[*].content[*].text
        if (!is_array($json)) return '';
        if (isset($json['output_text']) && is_string($json['output_text'])) return $json['output_text'];

        $out = '';
        if (!empty($json['output']) && is_array($json['output'])) {
            foreach ($json['output'] as $o) {
                if (!empty($o['content']) && is_array($o['content'])) {
                    foreach ($o['content'] as $c) {
                        if (($c['type'] ?? '') !== 'output_text') continue;
                        if (is_string($c['text'] ?? null)) {
                            $out .= $c['text'];
                        } elseif (is_array($c['text'] ?? null) && isset($c['text']['value']) && is_string($c['text']['value'])) {
                            $out .= $c['text']['value'];
                        }
                    }
                }
            }
        }
        return $out;
    }

    private static function extract_json_output($json) {
        if (!is_array($json)) return null;

        // Some Responses payloads can include explicit JSON objects in content blocks.
        if (!empty($json['output']) && is_array($json['output'])) {
            foreach ($json['output'] as $o) {
                if (empty($o['content']) || !is_array($o['content'])) continue;
                foreach ($o['content'] as $c) {
                    $type = (string)($c['type'] ?? '');
                    if (($type === 'output_json' || $type === 'json' || $type === 'json_schema') && isset($c['json']) && is_array($c['json'])) {
                        return $c['json'];
                    }
                }
            }
        }

        // Find JSON serialized inside output text.
        $txt = self::extract_output_text($json);
        if (!$txt) return null;
        return self::decode_json_from_text($txt);
    }

    private static function decode_json_from_text($txt) {
        $txt = trim((string)$txt);
        if ($txt === '') return null;

        $decoded = json_decode($txt, true);
        if (is_array($decoded)) return $decoded;

        // Remove markdown fences if present.
        $no_fence = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $txt);
        if (is_string($no_fence)) {
            $decoded = json_decode(trim($no_fence), true);
            if (is_array($decoded)) return $decoded;
        }

        // Fallback: extract a JSON object from surrounding text.
        if (preg_match('/\{[\s\S]*\}/', $txt, $m)) {
            $decoded = json_decode(trim($m[0]), true);
            if (is_array($decoded)) return $decoded;
        }

        return null;
    }

    public static function faq_as_text($faq_json) {
        $arr = json_decode($faq_json, true);
        if (!is_array($arr) || empty($arr)) return '';
        $lines = [];
        foreach ($arr as $item) {
            $q = trim((string)($item['q'] ?? ''));
            $a = trim((string)($item['a'] ?? ''));
            if ($q && $a) {
                $lines[] = "Q: {$q}\nA: {$a}";
            }
        }
        return implode("\n\n", $lines);
    }


    public static function analyze_chat_recommendations($dataset, $extra_instruction, $settings) {
        $api_key = trim($settings['openai_api_key'] ?? '');
        if (!$api_key) {
            return new WP_Error('missing_api_key', 'OpenAI API key is not configured.');
        }

        $chat_count = (int)($dataset['chat_count'] ?? 0);
        $content = trim((string)($dataset['content'] ?? ''));
        if ($chat_count < 1 || $content === '') {
            return new WP_Error('no_chat_data', 'No chats were found in the selected date range.');
        }

        $model = $settings['model'] ?? 'gpt-4.1-mini';
        $extra_instruction = trim((string)$extra_instruction);

        $system_prompt = "You are a chatbot quality analyst for 301 Interactive. Analyze the provided customer chats and provide concise, practical recommendations. Focus on FAQ opportunities that would improve future responses. Return only valid JSON matching the requested schema.";
        $user_prompt = "Date range: " . sanitize_text_field($dataset['date_start'] ?? '') . " to " . sanitize_text_field($dataset['date_end'] ?? '')
            . "\nChats analyzed: " . $chat_count
            . ($extra_instruction !== '' ? "\nAdditional instruction: " . $extra_instruction : "")
            . "\n\nChat transcripts and metadata:\n" . $content;

        $body = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt],
            ],
            'max_output_tokens' => 1400,
            'reasoning' => ['effort' => 'medium'],
            'text' => [
                'verbosity' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'name' => '301interactivebot_chat_analysis',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'summary' => ['type' => 'string'],
                            'themes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string']
                            ],
                            'recommended_faqs' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'question' => ['type' => 'string'],
                                        'answer' => ['type' => 'string'],
                                        'rationale' => ['type' => 'string'],
                                    ],
                                    'required' => ['question', 'answer', 'rationale'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['summary', 'themes', 'recommended_faqs'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        _301InteractiveBot_Logger::log('debug', 'OpenAI recommendation analysis request (body)', [
            'url' => 'https://api.openai.com/v1/responses',
            'body' => $body,
        ]);

        $resp = self::request_response($api_key, $body, 60, 1);
        if (is_wp_error($resp)) {
            return new WP_Error('openai_request_failed', $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('openai_http_error', 'OpenAI request failed with HTTP ' . (int)$code . '.');
        }

        $json = json_decode($raw, true);

        if (is_array($json) && ($json['status'] ?? '') === 'incomplete' && (($json['incomplete_details']['reason'] ?? '') === 'max_output_tokens') && !empty($json['id'])) {
            $retry = $body;
            $retry['previous_response_id'] = $json['id'];
            $retry['max_output_tokens'] = 2200;
            $retry['input'] = [
                ['role' => 'user', 'content' => 'Continue and return ONLY valid JSON for the required schema.']
            ];

            $resp2 = self::request_response($api_key, $retry, 60, 1);
            if (!is_wp_error($resp2)) {
                $code2 = wp_remote_retrieve_response_code($resp2);
                $raw2 = wp_remote_retrieve_body($resp2);
                if ($code2 >= 200 && $code2 < 300) {
                    $json2 = json_decode($raw2, true);
                    if (is_array($json2)) {
                        $json = $json2;
                    }
                }
            }
        }

        $payload = self::extract_json_output($json);
        if (!is_array($payload)) {
            _301InteractiveBot_Logger::log('error', 'OpenAI recommendation parse failed', [
                'http_code' => (int)$code,
                'response_status' => is_array($json) ? ($json['status'] ?? '') : '',
                'output_text_preview' => substr((string)self::extract_output_text($json), 0, 5000),
                'raw_preview' => substr((string)$raw, 0, 5000),
            ]);
            return new WP_Error('openai_invalid_response', 'Could not parse AI recommendations response.');
        }

        $themes = [];
        foreach ((array)($payload['themes'] ?? []) as $theme) {
            $theme = trim((string)$theme);
            if ($theme !== '') $themes[] = $theme;
        }

        $faqs = [];
        foreach ((array)($payload['recommended_faqs'] ?? []) as $faq) {
            if (!is_array($faq)) continue;
            $question = trim((string)($faq['question'] ?? ''));
            $answer = trim((string)($faq['answer'] ?? ''));
            $rationale = trim((string)($faq['rationale'] ?? ''));
            if ($question === '' || $answer === '') continue;
            $faqs[] = [
                'question' => $question,
                'answer' => $answer,
                'rationale' => $rationale,
            ];
        }

        return [
            'summary' => trim((string)($payload['summary'] ?? '')),
            'themes' => $themes,
            'recommended_faqs' => $faqs,
        ];
    }

    public static function summarize_transcript($chat_id, $transcript_text, $settings) {
        $api_key = trim($settings['openai_api_key'] ?? '');
        if (!$api_key) return '';

        $model = $settings['model'] ?? 'gpt-4.1-mini';
        $body = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => "Summarize this customer chat in 5-8 bullet points. Include intent, key questions, and requested follow-up."],
                [
                    'role' => 'user',
                    'content' => $transcript_text
                ]
            ]
        ];

        // Log request body for diagnostics (API key not included)
        _301InteractiveBot_Logger::log('debug', 'OpenAI request (body)', [
            'url' => 'https://api.openai.com/v1/responses',
            'body' => $body,
        ]);

        $resp = self::request_response($api_key, $body, 45, 1);


        if (is_wp_error($resp)) {
            _301InteractiveBot_Logger::log('warning', 'OpenAI transcript summary request failed', [
                'chat_id' => (int)$chat_id,
                'error' => $resp->get_error_message(),
            ]);
            return '';
        }
        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) return '';
        $json = json_decode($raw, true);
        return trim(self::extract_output_text($json));
    }

    public static function translate_transcript_for_email($chat_id, $summary, $rows, $settings) {
        $api_key = trim($settings['openai_api_key'] ?? '');
        if (!$api_key) {
            return [
                'summary' => (string)$summary,
                'rows' => (array)$rows,
                'translated' => false,
                'source_language' => '',
            ];
        }

        $model = $settings['model'] ?? 'gpt-4.1-mini';
        $messages = [];
        foreach ((array)$rows as $i => $row) {
            if (!is_array($row)) continue;
            $messages[] = [
                'index' => (int)$i,
                'sender' => (string)($row['sender'] ?? ''),
                'message' => (string)($row['message'] ?? ''),
            ];
        }

        $body = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'Translate the provided summary and chat transcript messages into natural English for an internal staff email. Preserve names, numbers, links, and factual meaning. Detect the original language of the transcript. Return only valid JSON matching the schema.',
                ],
                [
                    'role' => 'user',
                    'content' => wp_json_encode([
                        'summary' => (string)$summary,
                        'messages' => $messages,
                    ]),
                ],
            ],
            'max_output_tokens' => 2200,
            'reasoning' => ['effort' => 'low'],
            'text' => [
                'verbosity' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'name' => '301interactivebot_transcript_email_translation',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'summary' => ['type' => 'string'],
                            'translated' => ['type' => 'boolean'],
                            'source_language' => ['type' => 'string'],
                            'messages' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'index' => ['type' => 'integer'],
                                        'message' => ['type' => 'string'],
                                    ],
                                    'required' => ['index', 'message'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['summary', 'translated', 'source_language', 'messages'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $resp = self::request_response($api_key, $body, 60, 1);
        if (is_wp_error($resp)) {
            _301InteractiveBot_Logger::log('warning', 'OpenAI transcript email translation request failed', [
                'chat_id' => (int)$chat_id,
                'error' => $resp->get_error_message(),
            ]);
            return [
                'summary' => (string)$summary,
                'rows' => (array)$rows,
                'translated' => false,
                'source_language' => '',
            ];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            return [
                'summary' => (string)$summary,
                'rows' => (array)$rows,
                'translated' => false,
                'source_language' => '',
            ];
        }

        $json = json_decode($raw, true);
        $payload = self::extract_json_output($json);
        if (!is_array($payload)) {
            return [
                'summary' => (string)$summary,
                'rows' => (array)$rows,
                'translated' => false,
                'source_language' => '',
            ];
        }

        $translated_summary = trim((string)($payload['summary'] ?? ''));
        if ($translated_summary === '') $translated_summary = (string)$summary;

        $translated_rows = (array)$rows;
        foreach ((array)($payload['messages'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $idx = isset($item['index']) ? (int)$item['index'] : -1;
            if ($idx < 0 || !isset($translated_rows[$idx]) || !is_array($translated_rows[$idx])) continue;
            $translated_message = trim((string)($item['message'] ?? ''));
            if ($translated_message === '') continue;
            $translated_rows[$idx]['message'] = $translated_message;
        }

        $source_language = sanitize_text_field((string)($payload['source_language'] ?? ''));
        $translated = !empty($payload['translated']);

        return [
            'summary' => $translated_summary,
            'rows' => $translated_rows,
            'translated' => (bool)$translated,
            'source_language' => $source_language,
        ];
    }

    private static function request_response($api_key, $body, $timeout = 45, $retries = 0) {
        $timeout = max(10, (int)$timeout);
        $attempt = 0;
        do {
            $attempt++;
            $resp = wp_remote_post('https://api.openai.com/v1/responses', [
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]);

            if (!is_wp_error($resp) || !self::is_timeout_error($resp)) {
                return $resp;
            }

            _301InteractiveBot_Logger::log('warning', 'OpenAI request timed out, retrying', [
                'attempt' => $attempt,
                'timeout' => $timeout,
                'error' => $resp->get_error_message(),
            ]);
        } while ($attempt <= (int)$retries);

        return $resp;
    }

    private static function is_timeout_error($error) {
        if (!is_wp_error($error)) return false;
        $msg = strtolower((string)$error->get_error_message());
        return strpos($msg, 'timed out') !== false
            || strpos($msg, 'timeout') !== false
            || strpos($msg, 'curl error 28') !== false
            || strpos($msg, 'operation timed out') !== false;
    }
}