<?php


class WP_AI_Workflows_Utilities {

    public function init() {
        add_action('plugins_loaded', 'wp_ai_workflows_repair_options', 5);
    }

    const LOG_LEVEL_DEBUG = 0;
    const LOG_LEVEL_INFO = 1;
    const LOG_LEVEL_WARNING = 2;
    const LOG_LEVEL_ERROR = 3;

    private static $log_level = self::LOG_LEVEL_INFO; 

    public static function set_log_level($level) {
        self::$log_level = $level;
    }

    public static function debug_log($message, $type = 'info', $context = array()) {
        if (!WP_AI_WORKFLOWS_DEBUG) {
            return;
        }
    
        $log_file = WP_CONTENT_DIR . '/wp-ai-workflows-debug.log';
        $timestamp = current_time('mysql');
        $context_string = !empty($context) ? wp_json_encode($context) : '';
        $log_entry = "[{$timestamp}] [{$type}] {$message} {$context_string}\n";
    
        self::write_log($log_file, $log_entry);
    }

    private static function write_log($log_file, $log_entry) {
        $max_size = 5 * 1024 * 1024; // 5MB

        if (file_exists($log_file) && filesize($log_file) > $max_size) {
            $fs = self::get_wp_filesystem();
            if ($fs) {
                $old_content = $fs->get_contents($log_file);
                $new_content = substr($old_content, strlen($old_content) / 2) . $log_entry;
                $fs->put_contents($log_file, $new_content, FS_CHMOD_FILE);
            } else {
                error_log($log_entry, 3, $log_file);
            }
        } else {
            error_log($log_entry, 3, $log_file);
        }
    }

    private static function get_wp_filesystem() {
        global $wp_filesystem;

        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem;
    }

    private static function get_log_level_from_type($type) {
        switch ($type) {
            case 'debug': return self::LOG_LEVEL_DEBUG;
            case 'info': return self::LOG_LEVEL_INFO;
            case 'warning': return self::LOG_LEVEL_WARNING;
            case 'error': return self::LOG_LEVEL_ERROR;
            default: return self::LOG_LEVEL_INFO;
        }
    }


    public static function debug_function($function_name, $params = array(), $result = null) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : 'Unknown';
        
        $context = array(
            'function' => $function_name,
            'params' => $params,
            'result' => $result,
            'caller' => $caller,
            'memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true)
        );
        
        self::debug_log("Function execution: {$function_name}", 'debug', $context);
    }

    public static function download_log_file($request) {
        self::debug_function(__FUNCTION__);
        
        $log_file = WP_CONTENT_DIR . '/wp-ai-workflows-debug.log';
        
        if (!file_exists($log_file)) {
            return new WP_Error(
                'log_file_not_found',
                'No log file exists yet',
                array('status' => 404)
            );
        }
    
        // Read file directly
        $file_contents = file_get_contents($log_file);
        if ($file_contents === false) {
            return new WP_Error(
                'file_read_error',
                'Unable to read log file',
                array('status' => 500)
            );
        }
    
        // Force raw output without JSON encoding
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wp-ai-workflows-debug.log"');
        header('Content-Length: ' . strlen($file_contents));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    
        // Output the raw file contents and exit
        echo $file_contents;
        exit;
    }

    public static function generate_and_encrypt_api_key() {
        self::debug_function(__FUNCTION__);
        
        $api_key = wp_generate_password(32, false);
        $encrypted_key = wp_hash_password($api_key);
        update_option('wp_ai_workflows_encrypted_api_key', $encrypted_key);
        return $api_key; // Return unencrypted for initial use
    }

    public static function get_api_key() {
        self::debug_function(__FUNCTION__);
        
        $encrypted_key = get_option('wp_ai_workflows_encrypted_api_key');
        return $encrypted_key ? '********' . substr($encrypted_key, -4) : '';
    }

    public static function generate_api_key($request) {
        self::debug_function(__FUNCTION__);
        
        $new_key = self::generate_and_encrypt_api_key();
        return new WP_REST_Response(['api_key' => self::get_api_key()], 200);
    }

    public static function get_settings($request) {
        self::debug_function(__FUNCTION__);
        
        try {

            $settings = get_option('wp_ai_workflows_settings', array());
            
            // Define API keys structure
            $api_keys = array(
                // AI API Keys
                'ai' => array(
                    'openai_api_key',
                    'perplexity_api_key',
                    'openrouter_api_key',
                    'fal_api_key'
                ),
                // Other Services API Keys
                'services' => array(
                    'firecrawl_api_key',
                    'llamaparse_api_key',
                    'unsplash_api_key' 
                )
            );
            
            // Initialize response settings array
            $response_settings = array();
            
            // Process all API keys
            foreach ($api_keys as $group) {
                foreach ($group as $key) {
                    $response_settings[$key] = isset($settings[$key]) ? 
                        self::mask_api_key($settings[$key]) : '';
                }
            }
    
            // Get task roles
            $task_roles = get_option('wp_ai_workflows_task_roles', ['administrator']);
            
            
            // Merge with other settings
            $response_settings = array_merge($response_settings, array(
                'ai_workflow_api_key' => get_option('wp_ai_workflows_api_key', ''),
                'google_client_id' => $google_settings['google_client_id'],
                'google_client_secret' => $google_settings['google_client_secret'],
                'google_redirect_uri' => $google_settings['google_redirect_uri'],
                'selected_models' => isset($settings['selected_models']) ? $settings['selected_models'] : array(),
                'analytics_opt_out' => get_option('wp_ai_workflows_analytics_opt_out', false),
                'task_roles' => $task_roles,
                'setup_completed' => (bool)get_option('wp_ai_workflows_setup_completed', 0),
            ));
            
            WP_AI_Workflows_Utilities::debug_log("Settings retrieved successfully", "debug", [
                'selected_models' => $response_settings['selected_models'],
                'analytics_opt_out' => $response_settings['analytics_opt_out'],
            ]);
            
            return new WP_REST_Response($response_settings, 200);
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error retrieving settings", "error", [
                'error_message' => $e->getMessage()
            ]);
            return new WP_Error('settings_retrieval_error', $e->getMessage(), array('status' => 500));
        }
    }

    private static function mask_api_key($key) {
        if (strlen($key) > 4) {
            return str_repeat('*', strlen($key) - 4) . substr($key, -4);
        }
        return $key;
    }

    private static function encrypt_sensitive_data($data) {

        return WP_AI_Workflows_Encryption::encrypt($data);
    }

    private static function decrypt_sensitive_data($data) {

        return WP_AI_Workflows_Encryption::decrypt($data);
    }

    public static function update_settings($request) {
        self::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        $settings = $request->get_json_params();
        $current_settings = get_option('wp_ai_workflows_settings', array());
    
        $fields_to_update = [
            'openai_api_key', 'perplexity_api_key', 'openrouter_api_key', 
            'firecrawl_api_key', 'llamaparse_api_key', 'selected_models', 
            'unsplash_api_key', 'fal_api_key'
        ];
    
        $sensitive_fields = [
            'openai_api_key', 'perplexity_api_key', 'openrouter_api_key', 
            'firecrawl_api_key', 'llamaparse_api_key', 'google_client_id', 
            'google_client_secret', 'unsplash_api_key', 'fal_api_key'
        ];
    
        foreach ($fields_to_update as $field) {
            if (isset($settings[$field])) {
                if (in_array($field, $sensitive_fields)) {
                    // For sensitive fields, only update if the new value is different from the masked version
                    $masked_current = self::mask_api_key($current_settings[$field] ?? '');
                    if ($settings[$field] !== $masked_current) {
                        $current_settings[$field] = self::encrypt_sensitive_data($settings[$field]);
                    }
                } else {
                    $current_settings[$field] = $settings[$field];
                }
            }
        }
    
        if (isset($settings['analytics_opt_out'])) {
            update_option('wp_ai_workflows_analytics_opt_out', (bool)$settings['analytics_opt_out']);
        }

        if (isset($settings['setup_completed'])) {
            update_option('wp_ai_workflows_setup_completed', $settings['setup_completed'] === '1' ? 1 : 0);
        }

        // Handle task roles
        if (isset($settings['task_roles'])) {
            $task_roles = array_map('sanitize_text_field', $settings['task_roles']);
            
            // Ensure administrator is always included
            if (!in_array('administrator', $task_roles)) {
                $task_roles[] = 'administrator';
            }
            
            // Update the roles' capabilities
            global $wp_roles;
            foreach ($wp_roles->roles as $role_name => $role) {
                $role_object = get_role($role_name);
                if ($role_object) {
                    if (in_array($role_name, $task_roles)) {
                        $role_object->add_cap('manage_workflow_tasks');
                    } else {
                        $role_object->remove_cap('manage_workflow_tasks');
                    }
                }
            }
            
            update_option('wp_ai_workflows_task_roles', $task_roles);
            WP_AI_Workflows_Utilities::debug_log("Task roles updated", "debug", ['task_roles' => $task_roles]);
        }


        update_option('wp_ai_workflows_settings', $current_settings);
    
        // Return a masked version of the settings
        $masked_settings = $current_settings;
        foreach ($sensitive_fields as $field) {
            if (isset($masked_settings[$field])) {
                $masked_settings[$field] = self::mask_api_key($masked_settings[$field]);
            }
        }
    
        $masked_settings['analytics_opt_out'] = get_option('wp_ai_workflows_analytics_opt_out', false);
        return new WP_REST_Response($masked_settings, 200);
    }

    public static function get_fal_ai_api_key() {
        self::debug_function(__FUNCTION__);
        
        $settings = get_option('wp_ai_workflows_settings', array());
        $encrypted_key = isset($settings['fal_api_key']) ? $settings['fal_api_key'] : '';
    
        if (empty($encrypted_key)) {
            return '';
        }
        
        return self::decrypt_sensitive_data($encrypted_key);
    }

    public static function get_firecrawl_api_key() {
        self::debug_function(__FUNCTION__);
        
        $settings = get_option('wp_ai_workflows_settings', array());
        $encrypted_key = isset($settings['firecrawl_api_key']) ? $settings['firecrawl_api_key'] : '';
    
        if (empty($encrypted_key)) {
            return '';
        }
        
        return self::decrypt_sensitive_data($encrypted_key);
    }

    public static function get_llamaparse_api_key() {
        self::debug_function(__FUNCTION__);
        
        $settings = get_option('wp_ai_workflows_settings', array());
        $encrypted_key = isset($settings['llamaparse_api_key']) ? $settings['llamaparse_api_key'] : '';
    
        if (empty($encrypted_key)) {
            return '';
        }
        
        return self::decrypt_sensitive_data($encrypted_key);
    }

    public static function get_openrouter_api_key() {
        self::debug_function(__FUNCTION__);
        
        $settings = get_option('wp_ai_workflows_settings', array());
        $encrypted_key = isset($settings['openrouter_api_key']) ? $settings['openrouter_api_key'] : '';
    
        if (empty($encrypted_key)) {
            return '';
        }
        
        return self::decrypt_sensitive_data($encrypted_key);
    }

    public static function get_unsplash_api_key() {
        self::debug_function(__FUNCTION__);
        
        $settings = get_option('wp_ai_workflows_settings', array());
        $encrypted_key = isset($settings['unsplash_api_key']) ? $settings['unsplash_api_key'] : '';
    
        if (empty($encrypted_key)) {
            return '';
        }
        
        return self::decrypt_sensitive_data($encrypted_key);
    }

    public static function get_gravity_forms_data($request) {
        self::debug_function(__FUNCTION__);
        
        if (!class_exists('GFAPI')) {
            return new WP_Error('gravity_forms_not_active', 'Gravity Forms is not active', array('status' => 404));
        }

        $forms = GFAPI::get_forms();
        $formatted_forms = array();

        foreach ($forms as $form) {
            $formatted_fields = array();
            foreach ($form['fields'] as $field) {
                $formatted_fields[] = array(
                    'id' => $field->id,
                    'label' => $field->label,
                    'type' => $field->type
                );
            }

            $formatted_forms[] = array(
                'id' => $form['id'],
                'title' => $form['title'],
                'fields' => $formatted_fields
            );
        }

        return new WP_REST_Response($formatted_forms, 200);
    }

    public static function get_wpforms_data($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__);
    
        // Check if WPForms is active
        if (!class_exists('WPForms')) {
            WP_AI_Workflows_Utilities::debug_log("WPForms not active", "error");
            return new WP_Error('wpforms_not_active', 'WPForms is not active', array('status' => 404));
        }
    
        try {
            // Check if the forms object is accessible
            if (!function_exists('wpforms') || !wpforms()->form) {
                WP_AI_Workflows_Utilities::debug_log("WPForms forms object not accessible", "error");
                return new WP_Error('wpforms_not_initialized', 'WPForms not properly initialized', array('status' => 500));
            }
    
            $forms = wpforms()->form->get();
            if (empty($forms)) {
                WP_AI_Workflows_Utilities::debug_log("No WPForms found", "debug");
                return new WP_REST_Response(array(), 200); // Return empty array instead of error
            }
    
            $formatted_forms = array();
            foreach ($forms as $form) {
                try {
                    $form_data = json_decode($form->post_content, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        WP_AI_Workflows_Utilities::debug_log("Error decoding form content", "error", [
                            'form_id' => $form->ID,
                            'error' => json_last_error_msg()
                        ]);
                        continue; // Skip this form but continue processing others
                    }
    
                    $fields = array();
                    if (!empty($form_data['fields'])) {
                        foreach ($form_data['fields'] as $field) {
                            $fields[] = array(
                                'id' => $field['id'],
                                'label' => isset($field['label']) ? $field['label'] : 'Unnamed Field',
                                'type' => isset($field['type']) ? $field['type'] : 'text'
                            );
                        }
                    }
    
                    $formatted_forms[] = array(
                        'id' => $form->ID,
                        'title' => $form->post_title,
                        'fields' => $fields
                    );
    
                } catch (Exception $e) {
                    WP_AI_Workflows_Utilities::debug_log("Error processing form", "error", [
                        'form_id' => $form->ID,
                        'error' => $e->getMessage()
                    ]);
                    continue; // Skip this form but continue processing others
                }
            }
    
            WP_AI_Workflows_Utilities::debug_log("Successfully retrieved WPForms data", "debug", [
                'forms_count' => count($formatted_forms)
            ]);
    
            return new WP_REST_Response($formatted_forms, 200);
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error getting WPForms data", "error", [
                'error' => $e->getMessage()
            ]);
            return new WP_Error(
                'wpforms_error', 
                'Error retrieving WPForms data: ' . $e->getMessage(), 
                array('status' => 500)
            );
        }
    }

    public static function get_cf7_data($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__);
    
        if (!class_exists('WPCF7_ContactForm')) {
            WP_AI_Workflows_Utilities::debug_log("Contact Form 7 not active", "error");
            return new WP_Error('cf7_not_active', 'Contact Form 7 is not active', array('status' => 404));
        }
    
        try {
            $args = array(
                'post_type' => 'wpcf7_contact_form',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            );
    
            $forms = get_posts($args);
            $formatted_forms = array();
    
            foreach ($forms as $form) {
                $cf7_form = wpcf7_contact_form($form->ID);
                if (!$cf7_form) continue;
    
                // Get Contact Form 7's actual ID format
                $form_id = 'contact-form-' . $form->ID;
    
                $form_tags = $cf7_form->scan_form_tags();
                $fields = array();
    
                foreach ($form_tags as $tag) {
                    if (!empty($tag['name']) && !in_array($tag['type'], array('submit', 'reset'))) {
                        $fields[] = array(
                            'id' => $tag['name'],
                            'label' => $tag['name'],
                            'type' => $tag['type']
                        );
                    }
                }
    
                $formatted_forms[] = array(
                    'id' => $form_id, // Use the CF7 format ID
                    'title' => $form->post_title,
                    'fields' => $fields
                );
            }
    
            WP_AI_Workflows_Utilities::debug_log("Successfully retrieved CF7 data", "debug", [
                'forms_count' => count($formatted_forms),
                'forms' => $formatted_forms
            ]);
    
            return new WP_REST_Response($formatted_forms, 200);
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error getting CF7 data", "error", [
                'error' => $e->getMessage()
            ]);
            return new WP_Error('cf7_error', 'Error retrieving Contact Form 7 data: ' . $e->getMessage(), array('status' => 500));
        }
    }

    public static function get_ninja_forms_data($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__);
    
        if (!class_exists('Ninja_Forms')) {
            WP_AI_Workflows_Utilities::debug_log("Ninja Forms not active", "error");
            return new WP_Error('ninja_forms_not_active', 'Ninja Forms is not active', array('status' => 404));
        }
    
        try {
            $forms = Ninja_Forms()->form()->get_forms();
            $formatted_forms = array();
    
            foreach ($forms as $form) {
                $form_id = $form->get_id();
                $form_fields = Ninja_Forms()->form($form_id)->get_fields();
                $fields = array();
    
                foreach ($form_fields as $field) {
                    $field_settings = $field->get_settings();
                    
                    // Skip submit buttons and other non-input fields
                    if (!empty($field_settings['label']) && !in_array($field_settings['type'], ['submit', 'hr', 'html'])) {
                        $fields[] = array(
                            'id' => $field_settings['key'],
                            'label' => $field_settings['label'],
                            'type' => $field_settings['type']
                        );
                    }
                }
    
                $formatted_forms[] = array(
                    'id' => 'ninja-form-' . $form_id,
                    'title' => $form->get_setting('title'),
                    'fields' => $fields
                );
    
                WP_AI_Workflows_Utilities::debug_log("Processed Ninja Form", "debug", [
                    'form_id' => $form_id,
                    'field_count' => count($fields),
                    'fields' => $fields
                ]);
            }
    
            WP_AI_Workflows_Utilities::debug_log("Successfully retrieved Ninja Forms data", "debug", [
                'forms_count' => count($formatted_forms)
            ]);
    
            return new WP_REST_Response($formatted_forms, 200);
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error getting Ninja Forms data", "error", [
                'error' => $e->getMessage()
            ]);
            return new WP_Error(
                'ninja_forms_error', 
                'Error retrieving Ninja Forms data: ' . $e->getMessage(), 
                array('status' => 500)
            );
        }
    }
    


    public static function call_openai_api($prompt, $model, $imageUrls = [], $parameters = []) {
        self::debug_function(__FUNCTION__, ['prompt' => $prompt, 'model' => $model, 'imageUrls' => $imageUrls, 'parameters' => $parameters]);
        
        $api_key = self::get_openai_api_key();
        if (empty($api_key)) {
            return new WP_Error('openai_api_key_missing', 'OpenAI API key is not set');
        }
    
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        );
    
        $messages = [['role' => 'user', 'content' => []]];
    
        if (!empty($prompt)) {
            $messages[0]['content'][] = ['type' => 'text', 'text' => $prompt];
        }
    
        foreach ($imageUrls as $imageUrl) {
            if (!empty($imageUrl)) {
                $messages[0]['content'][] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $imageUrl]
                ];
            }
        }
    
        $body = array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => isset($parameters['temperature']) ? floatval($parameters['temperature']) : 1.0,
            'top_p' => isset($parameters['top_p']) ? floatval($parameters['top_p']) : 1.0,
            'frequency_penalty' => isset($parameters['frequency_penalty']) ? floatval($parameters['frequency_penalty']) : 0.0,
            'presence_penalty' => isset($parameters['presence_penalty']) ? floatval($parameters['presence_penalty']) : 0.0,
        );
    
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 600
        ));
    
        if (is_wp_error($response)) {
            self::debug_log("OpenAI API call failed", "error", ['error' => $response->get_error_message()]);
            return new WP_Error('openai_api_wp_error', "WP_Error in API call: " . $response->get_error_message());
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if ($response_code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            self::debug_log("OpenAI API error", "error", ['http_code' => $response_code, 'error' => $error_message]);
            return new WP_Error('openai_api_error', "OpenAI API error (HTTP $response_code): $error_message");
        }
    
        if (isset($data['choices'][0]['message']['content'])) {
            self::debug_log("OpenAI API call successful", "info", [
                'model' => $model,
                'image_count' => count($imageUrls),
                'has_usage' => isset($data['usage']),
                'usage' => $data['usage'] ?? null
            ]);
            
            // Return the entire response data, not just the content
            return [
                'choices' => $data['choices'],
                'usage' => $data['usage'] ?? null,
                'model' => $data['model'] ?? $model
            ];
        } else {
            self::debug_log("Unexpected OpenAI API response", "error", ['response' => $data]);
            return new WP_Error('openai_api_unexpected_response', 'Unexpected OpenAI API response structure');
        }
    }

    public static function call_openai_with_tools($prompt, $model, $imageUrls, $tools, $parameters, $system_message = null) {
        WP_AI_Workflows_Utilities::debug_log("Calling OpenAI with tools", "debug", [
            'model' => $model,
            'tools_count' => count($tools),
            'tools' => array_map(function($tool) { return $tool['type']; }, $tools)
        ]);
        
        $api_key = self::get_openai_api_key();
        if (empty($api_key)) {
            throw new Exception('OpenAI API key is not set');
        }
        
        $url = 'https://api.openai.com/v1/responses'; // Correct endpoint
        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ];
        
        // Format input according to responses API format
        $input = '';
        if (!empty($prompt)) {
            $input = $prompt;
        }
        
        // If there are images, we need to format input as array of content
        if (!empty($imageUrls)) {
            $input = [
                ['type' => 'text', 'text' => $prompt]
            ];
            foreach ($imageUrls as $imageUrl) {
                if (!empty($imageUrl)) {
                    $input[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageUrl]
                    ];
                }
            }
        }
        
        // Prepare body according to responses API format
        $body = [
            'model' => strpos($model, 'openai/') === 0 ? substr($model, 7) : $model,
            'input' => $input,
            'tools' => $tools,
            'temperature' => isset($parameters['temperature']) ? floatval($parameters['temperature']) : 1.0,
            'tool_choice' => 'auto',
            'parallel_tool_calls' => true
        ];
        
        // Add system message as instructions if provided
        if (!empty($system_message)) {
            $body['instructions'] = $system_message;
        }
        
        // Add other parameters if set
        if (isset($parameters['top_p'])) {
            $body['top_p'] = floatval($parameters['top_p']);
        }
        
        WP_AI_Workflows_Utilities::debug_log("OpenAI Responses API request", "debug", [
            'url' => $url,
            'model' => $body['model'],
            'tools' => $tools
        ]);
        
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 600
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('Error calling OpenAI API: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
    
        WP_AI_Workflows_Utilities::debug_log("OpenAI Responses API response", "debug", [
            'status_code' => $status_code,
            'response_id' => $data['id'] ?? 'none',
            'status' => $data['status'] ?? 'unknown'
        ]);
    
        if ($status_code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            throw new Exception('OpenAI API error: ' . $error_message);
        }
    
        if ($data['status'] !== 'completed') {
            throw new Exception('Response not completed. Status: ' . $data['status']);
        }
    
        // Extract response text and other information
        $response_text = '';
        $citations = [];
        $search_results = [];
    
        if (isset($data['output'])) {
            foreach ($data['output'] as $output) {
                if ($output['type'] === 'message' && $output['role'] === 'assistant') {
                    foreach ($output['content'] as $content) {
                        if ($content['type'] === 'output_text') {
                            $response_text = $content['text'];
                            if (isset($content['annotations'])) {
                                foreach ($content['annotations'] as $annotation) {
                                    $citations[] = $annotation;
                                }
                            }
                        }
                    }
                } elseif ($output['type'] === 'file_search_call' || $output['type'] === 'web_search_call') {
                    $search_results[] = $output;
                }
            }
        }
    
        return [
            'text' => $response_text,
            'citations' => $citations,
            'search_results' => $search_results,
            'usage' => $data['usage'] ?? null
        ];
    }

    public static function get_openai_api_key() {
        self::debug_function(__FUNCTION__);
        
        $settings = get_option('wp_ai_workflows_settings', array());
        $encrypted_key = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
    
        if (empty($encrypted_key)) {
            return '';
        }
        
        return self::decrypt_sensitive_data($encrypted_key);
    }

    public static function call_openrouter_api($prompt, $model, $imageUrls = [], $parameters = []) {
        self::debug_function(__FUNCTION__, ['prompt' => $prompt, 'model' => $model, 'imageUrls' => $imageUrls, 'parameters' => $parameters]);
        
        $api_key = self::get_openrouter_api_key();
        if (empty($api_key)) {
            return new WP_Error('openrouter_api_key_missing', 'OpenRouter API key is not set');
        }
    
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => get_site_url(),
            'X-Title' => get_bloginfo('name')
        );
    
        $messages = [['role' => 'user', 'content' => []]];
    
        if (!empty($prompt)) {
            $messages[0]['content'][] = ['type' => 'text', 'text' => $prompt];
        }
    
        foreach ($imageUrls as $imageUrl) {
            if (!empty($imageUrl)) {
                $messages[0]['content'][] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $imageUrl]
                ];
            }
        }
    
        $body = array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => isset($parameters['temperature']) ? floatval($parameters['temperature']) : 1.0,
            'top_p' => isset($parameters['top_p']) ? floatval($parameters['top_p']) : 1.0,
            'top_k' => isset($parameters['top_k']) ? intval($parameters['top_k']) : null,
            'frequency_penalty' => isset($parameters['frequency_penalty']) ? floatval($parameters['frequency_penalty']) : 0.0,
            'presence_penalty' => isset($parameters['presence_penalty']) ? floatval($parameters['presence_penalty']) : 0.0,
            'repetition_penalty' => isset($parameters['repetition_penalty']) ? floatval($parameters['repetition_penalty']) : 1.0,
        );
    
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 600
        ));
    
        if (is_wp_error($response)) {
            self::debug_log("OpenRouter API call failed", "error", ['error' => $response->get_error_message()]);
            return new WP_Error('openrouter_api_wp_error', "WP_Error in API call: " . $response->get_error_message());
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if ($response_code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            self::debug_log("OpenRouter API error", "error", ['http_code' => $response_code, 'error' => $error_message]);
            return new WP_Error('openrouter_api_error', "OpenRouter API error (HTTP $response_code): $error_message");
        }
    
        if (isset($data['choices'][0]['message']['content'])) {
            self::debug_log("OpenRouter API call successful", "info", [
                'model' => $model,
                'image_count' => count($imageUrls),
                'has_usage' => isset($data['usage']),
                'usage' => $data['usage'] ?? null
            ]);
            
            // Return the entire response data, not just the content
            return [
                'choices' => $data['choices'],
                'usage' => $data['usage'] ?? null,
                'model' => $data['model'] ?? $model
            ];
        } else {
            self::debug_log("Unexpected OpenRouter API response", "error", ['response' => $data]);
            return new WP_Error('openrouter_api_unexpected_response', 'Unexpected OpenRouter API response structure');
        }
    }

    public static function call_perplexity_api($prompt, $model, $temperature, $additional_params = []) {
        // Validate temperature first
        $temperature = floatval($temperature);
        if ($temperature < 0 || $temperature >= 2) {
            self::debug_log("Invalid temperature value", "error", [
                'temperature' => $temperature
            ]);
            return new WP_Error(
                'perplexity_api_invalid_parameter',
                "Temperature must be between 0 and 1.999. Got: $temperature"
            );
        }
    
        self::debug_function(__FUNCTION__, [
            'prompt' => $prompt, 
            'model' => $model,
            'temperature' => $temperature,
            'additional_params' => $additional_params
        ]);
        
        $api_key = self::get_perplexity_api_key();
        if (empty($api_key)) {
            return new WP_Error('perplexity_api_key_missing', 'Perplexity API key is not set');
        }
    
        // Map legacy models to new ones
        $model_mapping = [
            'llama-3.1-sonar-small-128k-online' => 'sonar',
            'llama-3.1-sonar-large-128k-online' => 'sonar-pro',
            'llama-3.1-sonar-huge-128k-online' => 'sonar-pro',
            'llama-3.1-sonar-small-128k-chat' => 'sonar',
            'llama-3.1-sonar-large-128k-chat' => 'sonar-pro'
        ];
        
        $model = isset($model_mapping[$model]) ? $model_mapping[$model] : $model;
    
        $url = 'https://api.perplexity.ai/chat/completions';
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        );
    
        // Prepare system prompt
        $system_prompt = self::get_system_prompt($model);
    
        // Build request body according to official format
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => $temperature,
            'return_images' => false,
            'return_related_questions' => false,
            'stream' => false
        ];
    
        // Add optional parameters according to official format
        if (isset($additional_params['top_p'])) {
            $body['top_p'] = floatval($additional_params['top_p']);
        }
    
        if (!empty($additional_params['searchDomainFilters'])) {
            $body['search_domain_filter'] = array_values($additional_params['searchDomainFilters']);
        }
    
        if (!empty($additional_params['search_recency_filter']) && 
            $additional_params['search_recency_filter'] !== 'any') {
            $body['search_recency_filter'] = $additional_params['search_recency_filter'];
        }
    
        // Handle mutually exclusive penalties
        if (isset($additional_params['frequency_penalty']) && $additional_params['frequency_penalty'] > 0) {
            $body['frequency_penalty'] = floatval($additional_params['frequency_penalty']);
        } elseif (isset($additional_params['presence_penalty']) && $additional_params['presence_penalty'] > 0) {
            $body['presence_penalty'] = floatval($additional_params['presence_penalty']);
        }
    
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 600
        ));
    
        if (is_wp_error($response)) {
            self::debug_log("Perplexity API call failed", "error", [
                'error' => $response->get_error_message()
            ]);
            return new WP_Error(
                'perplexity_api_wp_error', 
                "API call failed: " . $response->get_error_message()
            );
        }
    
        return self::process_api_response($response, $model, $additional_params);
    }
    
    // Helper method to get system prompt
    private static function get_system_prompt($model) {
        $system_prompt = <<<EOT
        You are an expert research assistant focused on providing accurate, comprehensive, and well-sourced information. Adapt your response style based on the query type while maintaining these core principles:
    
        1. GENERAL GUIDELINES
        - Provide detailed, factual information with precise citations
        - Maintain an unbiased, professional tone
        - Write in the same language as the query
        - Use appropriate formatting (markdown for lists, tables, quotes)
        - Avoid subjective qualifiers or hedging language
        - Clearly distinguish between facts and analysis
        - Always include source URLs in citations when available
        - Prioritize recent and authoritative sources
    
        2. OUTPUT FORMATTING
        - Structure responses logically with clear sections
        - Use markdown for formatting when appropriate
        - Include proper citations with URLs
        - Keep paragraphs concise and focused
        EOT;
    
        if ($model === 'sonar-pro') {
            $system_prompt .= <<<EOT
    
            3. ENHANCED SEARCH REQUIREMENTS
            - Perform multiple searches for comprehensive coverage
            - Prioritize high-authority sources
            - Include detailed citation metadata
            - Cross-reference information across sources
            - Verify information from multiple sources
            EOT;
        }
    
        if ($model === 'sonar-reasoning') {
            $system_prompt .= <<<EOT
    
            3. REASONING REQUIREMENTS
            - Break down complex problems into clear steps
            - Show explicit chain-of-thought reasoning
            - Validate conclusions with multiple sources
            - Explain the logic behind each step
            - Consider alternative viewpoints
            EOT;
        }
    
        return $system_prompt;
    }
    
    
    // Helper method to process API response
    private static function process_api_response($response, $model, $additional_params = []) {
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
    
        if (empty($body)) {
            self::debug_log("Empty response from Perplexity API", "error", [
                'http_code' => $response_code
            ]);
            return new WP_Error(
                'perplexity_api_empty_response',
                "Empty response received from API"
            );
        }
    
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::debug_log("Invalid JSON response", "error", [
                'json_error' => json_last_error_msg(),
                'response' => $body
            ]);
            return new WP_Error(
                'perplexity_api_invalid_json',
                "Invalid JSON response: " . json_last_error_msg()
            );
        }
    
        if ($response_code !== 200) {
            $error_message = isset($data['error']['message']) ? 
                $data['error']['message'] : 'Unknown error';
            self::debug_log("Perplexity API error", "error", [
                'http_code' => $response_code,
                'error' => $error_message
            ]);
            return new WP_Error(
                'perplexity_api_error',
                "API error (HTTP $response_code): $error_message"
            );
        }
    
        if (!isset($data['choices'][0]['message']['content'])) {
            self::debug_log("Unexpected API response structure", "error", [
                'response' => $data
            ]);
            return new WP_Error(
                'perplexity_api_unexpected_response',
                'Unexpected API response structure'
            );
        }
    
        $response_data = [
            'choices' => $data['choices'],
            'usage' => $data['usage'] ?? null,
            'model' => $data['model'] ?? $model,
            'citations' => $data['citations'] ?? []
        ];
    
        if (isset($data['search_context'])) {
            $response_data['search_context'] = $data['search_context'];
        }
    
        self::debug_log("Perplexity API call successful", "info", [
            'model' => $model,
            'has_citations' => isset($response_data['citations']),
            'citations_count' => isset($response_data['citations']) ? count($response_data['citations']) : 0,
            'has_search_context' => isset($response_data['search_context'])
        ]);
    
        return $response_data;
    }
    
    private static function get_perplexity_api_key() {
        self::debug_function(__FUNCTION__);
        
        $settings = get_option('wp_ai_workflows_settings', array());
        $encrypted_key = isset($settings['perplexity_api_key']) ? $settings['perplexity_api_key'] : '';
    
        if (empty($encrypted_key)) {
            return '';
        }
        
        return self::decrypt_sensitive_data($encrypted_key);
    }

    public static function verify_api_key($request) {
        $provided_key = $request->get_param('api_key');
        $encrypted_key = get_option('wp_ai_workflows_encrypted_api_key');
        
        if (wp_check_password($provided_key, $encrypted_key)) {
            return new WP_REST_Response(array('valid' => true), 200);
        } else {
            return new WP_REST_Response(array('valid' => false), 403);
        }
    }

    public static function get_google_settings() {
        $settings = get_option('wp_ai_workflows_settings', array());
        return array(
            'google_client_id' => isset($settings['google_client_id']) ? self::decrypt_sensitive_data($settings['google_client_id']) : '',
            'google_client_secret' => isset($settings['google_client_secret']) ? self::decrypt_sensitive_data($settings['google_client_secret']) : '',
            'google_redirect_uri' => get_option('wp_ai_workflows_google_redirect_uri', ''),
        );
    }

    public static function update_google_settings($client_id, $client_secret) {
        $settings = get_option('wp_ai_workflows_settings', array());
        $settings['google_client_id'] = WP_AI_Workflows_Encryption::encrypt($client_id);
        $settings['google_client_secret'] = WP_AI_Workflows_Encryption::encrypt($client_secret);
        update_option('wp_ai_workflows_settings', $settings);
    }

    public static function get_google_tokens() {
        $access_token = get_option('wp_ai_workflows_google_access_token');
        $refresh_token = get_option('wp_ai_workflows_google_refresh_token');
        return array(
            'access_token' => $access_token ? WP_AI_Workflows_Encryption::decrypt($access_token) : null,
            'refresh_token' => $refresh_token ? WP_AI_Workflows_Encryption::decrypt($refresh_token) : null,
        );
    }

    public static function generate_google_redirect_uri() {
        $redirect_uri = home_url('/wp-json/wp-ai-workflows/v1/google-auth-callback');
        update_option('wp_ai_workflows_google_redirect_uri', $redirect_uri);
        return $redirect_uri;
    }

    public static function update_google_tokens($access_token, $refresh_token) {
        update_option('wp_ai_workflows_google_access_token', WP_AI_Workflows_Encryption::encrypt($access_token));
        update_option('wp_ai_workflows_google_refresh_token', WP_AI_Workflows_Encryption::encrypt($refresh_token));
    }
    
    public static function calculate_delay_time($delay_value, $delay_unit) {
        self::debug_function(__FUNCTION__, ['delay_value' => $delay_value, 'delay_unit' => $delay_unit]);
        
        $now = time(); // Use UTC time

        switch ($delay_unit) {
            case 'minutes':
                $delay_time = $now + ($delay_value * MINUTE_IN_SECONDS);
                break;
            case 'hours':
                $delay_time = $now + ($delay_value * HOUR_IN_SECONDS);
                break;
            case 'days':
                $delay_time = $now + ($delay_value * DAY_IN_SECONDS);
                break;
            default:
                self::debug_log("Invalid delay unit", "error", ['unit' => $delay_unit]);
                return false;
        }

        self::debug_log("Calculated delay time", "debug", [
            'delay_value' => $delay_value,
            'delay_unit' => $delay_unit,
            'delay_time' => gmdate('Y-m-d H:i:s', $delay_time)
        ]);

        return $delay_time;
    }

    public static function update_execution_status($execution_id, $status, $message = '', $node_id = '') {
        self::debug_function(__FUNCTION__, ['execution_id' => $execution_id, 'status' => $status, 'message' => $message, 'node_id' => $node_id]);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        
        $current_output = $wpdb->get_var($wpdb->prepare(
            "SELECT output_data FROM {$wpdb->prefix}wp_ai_workflows_executions WHERE id = %d",
            $execution_id
        ));
    
        if ($current_output === null || $current_output === '') {
            self::debug_log("Empty or null output encountered", "warning", ['execution_id' => $execution_id]);
            $current_output = [];
        } else {
            $decoded_output = json_decode($current_output, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                self::debug_log("JSON decoding error", "error", [
                    'execution_id' => $execution_id,
                    'json_error' => json_last_error_msg(),
                    'raw_output' => $current_output
                ]);
            }
            $current_output = (is_array($decoded_output)) ? $decoded_output : [];
        }
    
        $current_output[] = array(
            'status' => $status,
            'message' => $message,
            'node_id' => $node_id,
            'timestamp' => current_time('mysql')
        );
        
        $wpdb->update(
            $table_name,
            array(
                'status' => $status,
                'updated_at' => current_time('mysql'),
                'output_data' => wp_json_encode($current_output)
            ),
            array('id' => $execution_id)
        );
    
        self::debug_log("Execution status updated", "debug", array(
            "execution_id" => $execution_id,
            "status" => $status,
            "message" => $message,
            "node_id" => $node_id
        ));
    }

    public static function migrate_to_db_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        // Check if migration is needed
        if (get_option('wp_ai_workflows_migrated_to_table', false)) {
            WP_AI_Workflows_Utilities::debug_log("Workflow migration already completed", "info");
            return true;
        }
        
        // Make sure the table exists
        WP_AI_Workflows_Database::ensure_tables_exist();
        
        // Verify table exists after creation attempt
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            WP_AI_Workflows_Utilities::debug_log("Failed to create workflow table", "error");
            return false;
        }
        
        // Get existing workflows from options
        $workflows = get_option('wp_ai_workflows', array());
        
        if (empty($workflows)) {
            // Nothing to migrate
            update_option('wp_ai_workflows_migrated_to_table', true);
            WP_AI_Workflows_Utilities::debug_log("No workflows to migrate", "info");
            return true;
        }
        
        WP_AI_Workflows_Utilities::debug_log("Starting workflow migration", "info", [
            'workflow_count' => count($workflows)
        ]);
        
        // Use transaction for data integrity
        $wpdb->query('START TRANSACTION');
        
        try {
            $success = true;
            $migrated_count = 0;
            $error_count = 0;
            
            foreach ($workflows as $workflow) {
                // Skip invalid workflows
                if (empty($workflow['id']) || empty($workflow['name'])) {
                    WP_AI_Workflows_Utilities::debug_log("Skipping invalid workflow", "warning", [
                        'workflow' => isset($workflow['id']) ? $workflow['id'] : 'unknown'
                    ]);
                    continue;
                }
                
                // Set default values for optional fields
                $workflow['status'] = isset($workflow['status']) ? $workflow['status'] : 'active';
                $workflow['createdBy'] = isset($workflow['createdBy']) ? $workflow['createdBy'] : 'system';
                $workflow['createdAt'] = isset($workflow['createdAt']) ? $workflow['createdAt'] : current_time('mysql');
                $workflow['updatedAt'] = isset($workflow['updatedAt']) ? $workflow['updatedAt'] : $workflow['createdAt'];
                
                // Check if workflow already exists in the table (to prevent duplicates during repeated migrations)
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE id = %s",
                    $workflow['id']
                ));
                
                if ($existing > 0) {
                    WP_AI_Workflows_Utilities::debug_log("Workflow already exists in table, updating", "debug", [
                        'workflow_id' => $workflow['id']
                    ]);
                    
                    // Update existing record
                    $result = $wpdb->update(
                        $table_name,
                        array(
                            'name' => $workflow['name'],
                            'status' => $workflow['status'],
                            'data' => wp_json_encode($workflow),
                            'created_by' => $workflow['createdBy'],
                            'created_at' => $workflow['createdAt'],
                            'updated_at' => $workflow['updatedAt']
                        ),
                        array('id' => $workflow['id'])
                    );
                } else {
                    // Insert new record
                    $result = $wpdb->insert(
                        $table_name,
                        array(
                            'id' => $workflow['id'],
                            'name' => $workflow['name'],
                            'status' => $workflow['status'],
                            'data' => wp_json_encode($workflow),
                            'created_by' => $workflow['createdBy'],
                            'created_at' => $workflow['createdAt'],
                            'updated_at' => $workflow['updatedAt']
                        )
                    );
                }
                
                if ($result === false) {
                    $error_count++;
                    WP_AI_Workflows_Utilities::debug_log("Failed to migrate workflow", "error", [
                        'workflow_id' => $workflow['id'],
                        'error' => $wpdb->last_error
                    ]);
                } else {
                    $migrated_count++;
                }
            }
            
            if ($error_count === 0) {
                $wpdb->query('COMMIT');
                update_option('wp_ai_workflows_migrated_to_table', true);
                
                // Add full-text index for better search performance
                $result = $wpdb->query("ALTER TABLE $table_name ADD FULLTEXT INDEX workflow_fulltext (name, data(1000000))");
                if ($result === false) {
                    WP_AI_Workflows_Utilities::debug_log("Failed to add fulltext index", "warning", [
                        'error' => $wpdb->last_error
                    ]);
                }
                
                WP_AI_Workflows_Utilities::debug_log("Workflow migration completed", "info", [
                    'migrated_count' => $migrated_count,
                    'total_workflows' => count($workflows)
                ]);
                
                return true;
            } else {
                $wpdb->query('ROLLBACK');
                WP_AI_Workflows_Utilities::debug_log("Migration failed with errors, rolling back", "error", [
                    'error_count' => $error_count
                ]);
                return false;
            }
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            WP_AI_Workflows_Utilities::debug_log("Migration exception", "error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Hook migration into WordPress init with proper locks
     */
    public static function check_and_run_migration() {
        // Only run on admin pages or during REST API calls
        if (!is_admin() && !defined('REST_REQUEST')) {
            return;
        }
        
        // Skip if postponed (unless explicitly requested)
        if (get_transient('wp_ai_workflows_migration_postponed') && 
            (!isset($_GET['action']) || $_GET['action'] !== 'run-migration')) {
            return;
        }
        
        // Skip on AJAX requests except our own endpoints
        if (wp_doing_ajax() && 
            (!isset($_REQUEST['action']) || 
             strpos($_REQUEST['action'], 'wp_ai_workflows') === false)) {
            return;
        }
        
        // Check if we need to migrate
        if (!get_option('wp_ai_workflows_migrated_to_table', false)) {
            // Check for existing lock
            $migration_lock = get_transient('wp_ai_workflows_migration_lock');
            if ($migration_lock) {
                return;
            }
            
            // Set lock for 5 minutes to prevent concurrent migrations
            set_transient('wp_ai_workflows_migration_lock', time(), 5 * MINUTE_IN_SECONDS);
            
            try {
                // Run the migration
                $result = self::migrate_to_db_table();
                
                if ($result) {
                    // Migration succeeded, trigger any post-migration hooks
                    do_action('wp_ai_workflows_after_migration');
                    
                    // Clear postpone flag if it was set
                    delete_transient('wp_ai_workflows_migration_postponed');
                } else {
                    self::debug_log("Auto-migration failed", "error");
                }
            } finally {
                // Always remove the lock when we're done
                delete_transient('wp_ai_workflows_migration_lock');
            }
        }
    }
    
    /**
     * Add admin notice if migration is needed
     */
    public static function admin_migration_notice() {
        // Only show to users who can manage options
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Only show if not already migrated
        if (get_option('wp_ai_workflows_migrated_to_table', false)) {
            return;
        }
        
        // Get workflow count
        $workflows = get_option('wp_ai_workflows', array());
        $workflow_count = count($workflows);
        
        // Only show if there are workflows to migrate
        if ($workflow_count === 0) {
            return;
        }
        
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>WP AI Workflows:</strong> 
                Your plugin needs to upgrade the workflow storage system to improve performance.
                This will migrate <?php echo esc_html($workflow_count); ?> workflows to a new database structure.
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-ai-workflows&action=run-migration')); ?>" class="button button-primary">
                    Run Migration Now
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Handle manual migration trigger
     */
    public static function handle_manual_migration() {
        if (isset($_GET['page']) && $_GET['page'] === 'wp-ai-workflows' && 
            isset($_GET['action']) && $_GET['action'] === 'run-migration') {
            
            // Check nonce for security
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wp_ai_workflows_migration')) {
                wp_die('Security check failed. Please try again.');
            }
            
            // Run migration
            $result = wp_ai_workflows_migrate_to_db_table();
            
            // Redirect back to admin page with result
            wp_safe_redirect(add_query_arg(
                array(
                    'page' => 'wp-ai-workflows',
                    'migration' => $result ? 'success' : 'failed'
                ), 
                admin_url('admin.php')
            ));
            exit;
        }
    }
    
    /**
     * Show migration success/error message
     */
    public static function migration_result_notice() {
        if (isset($_GET['page']) && $_GET['page'] === 'wp-ai-workflows' && isset($_GET['migration'])) {
            if ($_GET['migration'] === 'success') {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> Your workflows have been migrated to the new database structure for improved performance.</p>
                </div>
                <?php
            } else {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Migration failed.</strong> Please check the error logs or contact support.</p>
                </div>
                <?php
            }
        }
    }

    function wp_ai_workflows_repair_options() {
        $option_value = get_option('wp_ai_workflows');
        if (!is_array($option_value)) {
            // Reset the option to an empty array
            update_option('wp_ai_workflows', array());
            WP_AI_Workflows_Utilities::debug_log("Repaired corrupted workflows option", "info");
            
            // Check if we have workflows in the database table we can restore
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
            
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $results = $wpdb->get_results("SELECT id, data FROM $table_name ORDER BY updated_at DESC", ARRAY_A);
                
                if (!empty($results)) {
                    $workflows = array();
                    foreach ($results as $row) {
                        $workflows[] = json_decode($row['data'], true);
                    }
                    
                    // Restore the options from the table data
                    update_option('wp_ai_workflows', $workflows);
                    WP_AI_Workflows_Utilities::debug_log("Restored workflows from database to options", "info", [
                        'count' => count($workflows)
                    ]);
                }
            }
        }
    }


}