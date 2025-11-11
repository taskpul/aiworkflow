<?php

class WP_AI_Workflows_Chat_Handler {
    private $session;
    private $model;
    private $system_prompt;
    private $model_params;
    private $actions; 
    private $openai_tools; 
    private $current_message;
    private $page_context;
    
    public function __construct($workflow_id, $session_id = null) {
        $this->session = new WP_AI_Workflows_Chat_Session($workflow_id, $session_id);
        $this->load_workflow_config();
    }
    
    private function load_workflow_config() {
        global $wpdb;
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
        
        WP_AI_Workflows_Utilities::debug_log("Getting chat config", "debug", [
            'workflow_id' => $this->session->get_workflow_id()
        ]);
        
        // Always load from workflow config first (this is the change)
        $workflow = $this->get_workflow_by_id($this->session->get_workflow_id());
        if ($workflow) {
            $chat_node = $this->find_chat_node($workflow['nodes']);
            if ($chat_node) {
                $this->model = $chat_node['data']['model'];
                $this->system_prompt = $chat_node['data']['systemPrompt'];
                $this->model_params = $chat_node['data']['modelParams'];
                $this->actions = isset($chat_node['data']['actions']) ? $chat_node['data']['actions'] : [];
                $this->openai_tools = isset($chat_node['data']['openaiTools']) ? $chat_node['data']['openaiTools'] : null;
        
                WP_AI_Workflows_Utilities::debug_log("Loaded chat configuration from workflow", "debug", [
                    'model' => $this->model,
                    'has_system_prompt' => !empty($this->system_prompt),
                    'has_actions' => !empty($this->actions),
                    'action_count' => count($this->actions)
                ]);
            }
        }
        
        // Fallback to latest execution if needed
        if (!$this->model) {
            // Get the latest successful execution for this workflow
            $execution = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $executions_table 
                WHERE workflow_id = %s 
                AND status = 'completed' 
                ORDER BY created_at DESC 
                LIMIT 1",
                $this->session->get_workflow_id()
            ));
        
            if ($execution && $execution->output_data) {
                $output_data = json_decode($execution->output_data, true);
                
                // Find the chat node data in the execution output
                foreach ($output_data as $node_id => $node_output) {
                    if (isset($node_output['type']) && $node_output['type'] === 'chat') {
                        $this->model = $node_output['content']['model'] ?? null;
                        $this->system_prompt = $node_output['content']['systemPrompt'] ?? null;
                        $this->model_params = $node_output['content']['modelParams'] ?? [];
                        $this->actions = $node_output['content']['actions'] ?? [];
                        $this->openai_tools = $node_output['content']['openaiTools'] ?? null;
                        
                        WP_AI_Workflows_Utilities::debug_log("Found chat config in execution", "debug", [
                            'model' => $this->model,
                            'has_system_prompt' => !empty($this->system_prompt),
                            'has_actions' => !empty($this->actions),
                            'has_openai_tools' => !empty($this->openai_tools)
                        ]);
                        
                        break;
                    }
                }
            }
        }
    
        // Log error if still no model found
        if (!$this->model) {
            WP_AI_Workflows_Utilities::debug_log("No model found in chat configuration", "error", [
                'workflow_id' => $this->session->get_workflow_id()
            ]);
        }
        
        WP_AI_Workflows_Utilities::debug_log("Chat config retrieved", "debug", [
            'workflow_id' => $this->session->get_workflow_id(),
            'config' => [
                'model' => $this->model,
                'action_count' => count($this->actions),
                'actions' => array_map(function($action) {
                    return $action['name'];
                }, $this->actions),
                'openai_tools' => $this->openai_tools ? [
                    'web_search' => isset($this->openai_tools['webSearch']) && isset($this->openai_tools['webSearch']['enabled']) && $this->openai_tools['webSearch']['enabled'],
                    'file_search' => isset($this->openai_tools['fileSearch']) && isset($this->openai_tools['fileSearch']['enabled']) && $this->openai_tools['fileSearch']['enabled']
                ] : null
            ]
        ]);
    }

    public function get_session() {
        return $this->session;
    }
    
    public function handle_message($message, $stream = false, $page_context = null, $is_initial_message = false) {
        // Origin validation
        $allowed_origins = array(get_site_url());
        if (!empty($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
            throw new Exception('Invalid request origin');
        }
    
        $this->page_context = $page_context;
    
        // Check for workflow refresh flag
        if (get_transient('wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id())) {
            $this->load_workflow_config();
            delete_transient('wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id());
        }
    
        // Input validation
        if (strlen($message) > 2000) {
            throw new Exception('Message too long');
        }
        if (empty(trim($message))) {
            throw new Exception('Empty message');
        }
    
        // Sanitize input
        $message = wp_kses($message, array(
            'a' => array('href' => array(), 'target' => array('_blank')),
            'b' => array(),
            'strong' => array(),
            'i' => array(),
            'em' => array(),
            'code' => array(),
            'pre' => array()
        ));
    
        // Special handling for initial message generation
        if ($is_initial_message) {
            WP_AI_Workflows_Utilities::debug_log("Processing initial message request", "debug", [
                'workflow_id' => $this->session->get_workflow_id(),
                'has_page_context' => !empty($page_context),
                'has_system_prompt' => !empty($this->system_prompt)
            ]);
        
            try {
                // Store the original system prompt - we'll use it as the foundation
                $original_system_prompt = $this->system_prompt;
                
                // Create a new combined prompt that preserves the user's configuration
                // but adds instructions for generating an initial greeting
                $initial_prompt = $original_system_prompt;
                
                // Add separator to distinguish the original prompt from our additions
                $initial_prompt .= "\n\n--- ADDITIONAL INSTRUCTIONS FOR INITIAL GREETING ---\n\n";
                $initial_prompt .= "You are generating the initial greeting message for this chat session. ";
                $initial_prompt .= "This will be the first message the user sees when they open the chat.";
                
                // If page context is available, use it to make the greeting more relevant
                if (!empty($page_context)) {
                    $initial_prompt .= "\n\nThe user is currently on a page with the following information:";
                    
                    if (!empty($page_context['page_title'])) {
                        $initial_prompt .= "\nTitle: " . esc_html($page_context['page_title']);
                    }
                    
                    if (!empty($page_context['page_url'])) {
                        $initial_prompt .= "\nURL: " . esc_url($page_context['page_url']);
                    }
                    
                    if (!empty($page_context['page_type'])) {
                        $initial_prompt .= "\nPage type: " . esc_html($page_context['page_type']);
                    }
                    
                    if (!empty($page_context['content_summary'])) {
                        $initial_prompt .= "\nContent summary: " . esc_html($page_context['content_summary']);
                    }
                    
                    // Add product info if available (for WooCommerce)
                    if (!empty($page_context['product_info'])) {
                        $product = $page_context['product_info'];
                        $initial_prompt .= "\nThe user is viewing a product:";
                        
                        if (!empty($product['price'])) {
                            $initial_prompt .= "\n- Price: " . esc_html($product['price']);
                        }
                        
                        if (!empty($product['sku'])) {
                            $initial_prompt .= "\n- SKU: " . esc_html($product['sku']);
                        }
                        
                        if (!empty($product['stock_status'])) {
                            $initial_prompt .= "\n- Stock Status: " . esc_html($product['stock_status']);
                        }
                        
                        if (!empty($product['categories']) && is_array($product['categories'])) {
                            $initial_prompt .= "\n- Categories: " . esc_html(implode(', ', $product['categories']));
                        }
                    }
                }
                
                // Add instructions for generating the greeting
                $initial_prompt .= "\n\nWrite a brief, friendly initial greeting message that:";
                $initial_prompt .= "\n1. Is contextually relevant to the page the user is viewing";
                $initial_prompt .= "\n2. Is brief and concise (2-3 sentences)";
                $initial_prompt .= "\n3. Maintains the tone and character established in the main instructions above";
                $initial_prompt .= "\n4. Invites the user to ask questions relevant to the current page";
                $initial_prompt .= "\n5. Avoids being overly salesy or pushy";
                
                // Assign the combined prompt
                $this->system_prompt = $initial_prompt;
                
                WP_AI_Workflows_Utilities::debug_log("Created combined prompt for initial message", "debug", [
                    'original_length' => strlen($original_system_prompt),
                    'combined_length' => strlen($initial_prompt)
                ]);
                
                // Change the message to a simple instruction
                $message = "Generate an appropriate initial greeting for this website visitor.";
                
                // Process with the combined prompt
                $result = $this->process_normal_message($message, $stream);
                
                // Restore the original system prompt
                $this->system_prompt = $original_system_prompt;
                
                return $result;
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error in initial message generation", "error", [
                    'error' => $e->getMessage()
                ]);
                
                // Restore original system prompt if there was an error
                if (isset($original_system_prompt)) {
                    $this->system_prompt = $original_system_prompt;
                }
                
                // Continue with regular processing as fallback
            }
        }
    
        try {
            // Rate limit check
            if (!$this->session->can_send_message()) {
                throw new Exception('Rate limit exceeded');
            }
    
            // Store current message and prepare context
            $this->current_message = $message;
            $context = $this->prepare_context($this->session->get_history());
    
            // Use the unified get_ai_response method that handles tools and actions
            $raw_response = $this->get_ai_response($context);
            
            // Process response with our unified processor
            $processed = $this->process_response($raw_response);
    
            // Save conversation history first
            $this->session->add_message('user', $message);
            $this->session->add_message('assistant', $processed['display_message']);
    
            // Handle action type responses
            if ($processed['type'] === 'action') {
                return $this->handle_action_response($processed);
            }
    
            // Regular message response
            WP_AI_Workflows_Utilities::debug_log("Sending regular message response to client", "debug", [
                'message_type' => $processed['type'],
                'content_preview' => substr($processed['display_message'], 0, 50) . '...',
                'session_id' => $this->session->get_session_id()
            ]);
    
            return [
                'type' => $processed['type'],
                'display_message' => $processed['display_message'],
                'message' => $processed['display_message'],
                'session_id' => $this->session->get_session_id(),
                'citations' => $processed['citations'] ?? null
            ];
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error in handle_message", "error", [
                'error_type' => get_class($e),
                'message' => 'Chat message handling failed: ' . $e->getMessage()
            ]);
            throw new Exception('Unable to process message. Please try again later.');
        }
    }
    
    // Helper method to process normal messages (added for clarity)
    private function process_normal_message($message, $stream) {
        try {
            // Rate limit check
            if (!$this->session->can_send_message()) {
                throw new Exception('Rate limit exceeded');
            }
    
            // Store current message and prepare context
            $this->current_message = $message;
            $context = $this->prepare_context($this->session->get_history());
    
            // Use the unified get_ai_response method that handles tools and actions
            $raw_response = $this->get_ai_response($context);
            
            // Process response with our unified processor
            $processed = $this->process_response($raw_response);
    
            // Save conversation history first
            $this->session->add_message('user', $message);
            $this->session->add_message('assistant', $processed['display_message']);
    
            // Handle action type responses
            if ($processed['type'] === 'action') {
                return $this->handle_action_response($processed);
            }
    
            // Regular message response
            return [
                'type' => $processed['type'],
                'display_message' => $processed['display_message'],
                'message' => $processed['display_message'],
                'session_id' => $this->session->get_session_id(),
                'citations' => $processed['citations'] ?? null
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function get_ai_response($context) {
    
        // Check if we should use OpenAI Responses API with tools
        $use_responses_api = false;
        $tools = [];
        
        // Improved OpenAI model detection - expanded to include more models
        $is_openai = (strpos($this->model, 'openai/') === 0) || 
                     (strpos($this->model, 'gpt-') === 0) ||  
                     (strpos($this->model, 'o1') === 0) || 
                     (strpos($this->model, 'o3') === 0) || 
                     (strpos($this->model, 'o4') === 0) || 
                     (strpos($this->model, 'o5') === 0) || 
                     in_array($this->model, [
                         'o1', 'o1-mini', 'o3', 'o3-mini', 'o3-mini-high', 
                         'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo'
                     ]);
        
        // Check if we have OpenAI tools enabled
        if ($is_openai && !empty($this->openai_tools)) {
            $tools = $this->prepare_openai_tools($this->openai_tools);
            if (!empty($tools)) {
                $use_responses_api = true;
            }
        }
        
        // Before calling API, ensure action instructions are added to system prompt
        $has_modified_system = false;
        foreach ($context as &$message) {
            if ($message['role'] === 'system') {
                // Only add action instructions if they're not already there
                if (!strpos($message['content'], 'This chat supports the following actions:')) {
                    $message['content'] = $this->add_action_instructions($message['content']);
                }
                $has_modified_system = true;
                break;
            }
        }
        
        // If no system message was found, add one
        if (!$has_modified_system && !empty($this->actions)) {
            array_unshift($context, [
                'role' => 'system',
                'content' => $this->add_action_instructions("You are an AI assistant.")
            ]);
        }
        
        // Call appropriate API method based on configuration
        if ($use_responses_api) {
            // IMPROVED METHOD FOR RESPONSES API
            // Extract system message
            $system_message = null;
            foreach ($context as $message) {
                if ($message['role'] === 'system') {
                    $system_message = $message['content'];
                    break;
                }
            }
    
            // Build conversation history without the system message
            $conversation_history = [];
            foreach ($context as $message) {
                if ($message['role'] !== 'system') {
                    $conversation_history[] = $message;
                }
            }
            
            // Format conversation history into a structured string
            // This allows the AI to understand the full conversation flow
            $conversation_formatted = "";
            $last_user_message = "";
            
            if (count($conversation_history) > 1) {
                // Get all messages except the last one (current user message)
                $prev_messages = array_slice($conversation_history, 0, count($conversation_history) - 1);
                
                foreach ($prev_messages as $msg) {
                    $role = ucfirst($msg['role']);
                    $conversation_formatted .= "{$role}: " . $msg['content'] . "\n\n";
                }
            }
            
            // Get the last user message separately
            $last_message = end($conversation_history);
            if ($last_message && $last_message['role'] === 'user') {
                $last_user_message = $last_message['content'];
            }
            
            // If we have previous conversation, include it in a structured format
            $input_message = $last_user_message;
            if (!empty($conversation_formatted)) {
                $input_message = "Previous conversation:\n" . $conversation_formatted . 
                                 "Current message: " . $last_user_message;
            }
            
            WP_AI_Workflows_Utilities::debug_log("Calling Responses API with conversation history", "debug", [
                'model' => $this->model,
                'tools_count' => count($tools),
                'total_messages' => count($conversation_history),
                'has_conversation_history' => !empty($conversation_formatted),
                'input_length' => strlen($input_message)
            ]);
            
            // Call OpenAI with tools
            return WP_AI_Workflows_Utilities::call_openai_with_tools(
                $input_message,
                $this->model,
                [], // No image URLs
                $tools,
                [
                    'temperature' => $this->model_params['temperature'] ?? 1.0,
                    'top_p' => $this->model_params['top_p'] ?? 1.0,
                    'max_tokens' => $this->model_params['max_tokens'] ?? 4096
                ],
                $system_message
            );
        } else if (strpos($this->model, '/') !== false) {
            // Call OpenRouter for external models
            return $this->call_openrouter($context);
        } else {
            // Call standard OpenAI API
            return $this->call_openai($context);
        }
    }
    
    /**
     * Prepare OpenAI tools configuration
     */
    private function prepare_openai_tools($tools_config) {
        $tools = [];
        
        // Add web search tool if enabled
        if (isset($tools_config['webSearch']) && isset($tools_config['webSearch']['enabled']) && $tools_config['webSearch']['enabled']) {
            $web_search_tool = [
                'type' => 'web_search_preview'
            ];
            
            // Add context size if specified
            if (isset($tools_config['webSearch']['contextSize'])) {
                $web_search_tool['search_context_size'] = $tools_config['webSearch']['contextSize'];
            }
            
            // Add location settings if specified
            if (isset($tools_config['webSearch']['location'])) {
                $location = $tools_config['webSearch']['location'];
                if (!empty($location['city']) || !empty($location['region']) || !empty($location['country'])) {
                    $web_search_tool['user_location'] = [
                        'type' => 'approximate'
                    ];
                    
                    if (!empty($location['city'])) {
                        $web_search_tool['user_location']['city'] = $location['city'];
                    } else {
                        $web_search_tool['user_location']['city'] = null;
                    }
                    
                    if (!empty($location['region'])) {
                        $web_search_tool['user_location']['region'] = $location['region'];
                    } else {
                        $web_search_tool['user_location']['region'] = null;
                    }
                    
                    if (!empty($location['country'])) {
                        $web_search_tool['user_location']['country'] = $location['country'];
                    } else {
                        $web_search_tool['user_location']['country'] = null;
                    }
                    
                    // Add timezone set to null
                    $web_search_tool['user_location']['timezone'] = null;
                }
            }
            
            $tools[] = $web_search_tool;
        }
        
        // Add file search tool if enabled
        if (isset($tools_config['fileSearch']) && isset($tools_config['fileSearch']['enabled']) && $tools_config['fileSearch']['enabled'] && !empty($tools_config['fileSearch']['vectorStoreId'])) {
            $file_search_tool = [
                'type' => 'file_search',
                'vector_store_ids' => [$tools_config['fileSearch']['vectorStoreId']]
            ];
            
            // Add max results if specified
            if (isset($tools_config['fileSearch']['maxResults'])) {
                $file_search_tool['max_num_results'] = intval($tools_config['fileSearch']['maxResults']);
            }
            
            $tools[] = $file_search_tool;
        }
        
        WP_AI_Workflows_Utilities::debug_log("Prepared OpenAI tools", "debug", [
            'tools_count' => count($tools),
            'tools' => array_map(function($tool) { return $tool['type']; }, $tools)
        ]);
        
        return $tools;
    }
    
 
    /**
     * Call OpenAI API with tools support (Responses API)
     */
    public static function call_openai_with_tools($prompt, $model, $imageUrls, $tools, $parameters, $system_message = null) {
        // Log exact inputs for debugging
        WP_AI_Workflows_Utilities::debug_log("DETAILED API REQUEST", "debug", [
            'input_prompt' => $prompt,
            'system_message' => $system_message,
            'model' => $model,
            'tools' => $tools
        ]);
        
        $api_key = self::get_openai_api_key();
        if (empty($api_key)) {
            throw new Exception('OpenAI API key is not set');
        }
        
        $url = 'https://api.openai.com/v1/responses';
        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ];
        
        // Format input for Responses API
        $input = $prompt;
        
        // Prepare body
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
        
        // Log the exact request body being sent
        WP_AI_Workflows_Utilities::debug_log("API REQUEST BODY", "debug", [
            'json_body' => json_encode($body, JSON_PRETTY_PRINT)
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
        
        // Log the raw response for debugging
        WP_AI_Workflows_Utilities::debug_log("RAW API RESPONSE", "debug", [
            'status_code' => $status_code,
            'raw_response' => $response_body
        ]);
        
        // Then proceed with normal processing
        $data = json_decode($response_body, true);
    
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
    
    private function handle_action_response($processed) {
        // Find the action
        $action = $this->find_action($processed['action_id']);
        if (!$action) {
            throw new Exception('Invalid action requested');
        }
    
        // Prepare response first
        $response = [
            'type' => 'action',
            'display_message' => $processed['display_message'],
            'action_id' => $processed['action_id'],
            'has_pending_result' => true,
            'session_id' => $this->session->get_session_id(),
            'message' => $processed['display_message']
        ];
    
        // Send the response immediately if possible
        if (function_exists('fastcgi_finish_request') && !headers_sent()) {
            header('Content-Type: application/json');
            echo wp_json_encode($response);
            fastcgi_finish_request();
        }
    
        // Now execute the workflow
        $execution_result = WP_AI_Workflows_Workflow::execute_workflow(
            $this->session->get_workflow_id(),
            $processed['action_data'],
            null,
            $this->session->get_session_id(),
            null,
            null,
            $processed['action_id']
        );
    
        // Store execution info in transient
        if (isset($execution_result['execution_id'])) {
            $execution_id = $execution_result['execution_id'];
            $execution_key = 'wp_ai_workflows_pending_execution_' . $this->session->get_session_id();
            
            set_transient($execution_key, [
                'execution_id' => $execution_id,
                'action_id' => $processed['action_id'],
                'workflow_id' => $this->session->get_workflow_id(),
                'timestamp' => time()
            ], 3600); // 1 hour expiry
    
            WP_AI_Workflows_Utilities::debug_log("Set pending execution transient", "info", [
                'session_id' => $this->session->get_session_id(),
                'execution_id' => $execution_id,
                'action_id' => $processed['action_id']
            ]);
    
            // Update session status
            global $wpdb;
            $sessions_table = $wpdb->prefix . 'wp_ai_workflows_sessions';
            $wpdb->update(
                $sessions_table,
                ['metadata' => wp_json_encode(['status' => 'processing', 'execution_id' => $execution_id])],
                ['session_id' => $this->session->get_session_id()]
            );
        }
    
        WP_AI_Workflows_Utilities::debug_log("Sending action response to client", "debug", [
            'action_id' => $processed['action_id'],
            'action_type' => $processed['type'],
            'has_pending_result' => true,
            'session_id' => $this->session->get_session_id(),
            'execution_id' => $execution_result['execution_id'] ?? 'unknown'
        ]);
    
        return $response;
    }

    
    private function prepare_context($history) {
        $messages = array();
        
        // Base system prompt
        $system_prompt = $this->system_prompt;
        
        // Add page context information if available
        if (!empty($this->page_context)) {
            $system_prompt .= "\n\n### CURRENT PAGE CONTEXT ###\n";
            $system_prompt .= "The user is currently on a page with the following information:\n";
            
            // Add basic page info
            $system_prompt .= "- Title: " . esc_html($this->page_context['page_title']) . "\n";
            $system_prompt .= "- URL: " . esc_url($this->page_context['page_url']) . "\n";
            $system_prompt .= "- Type: " . esc_html($this->page_context['page_type']) . "\n";
            
            // Add content summary if available
            if (!empty($this->page_context['content_summary'])) {
                $system_prompt .= "\nContent Summary:\n" . esc_html($this->page_context['content_summary']) . "\n";
            }
            
            // Add product info if available (for WooCommerce)
            if (!empty($this->page_context['product_info'])) {
                $product = $this->page_context['product_info'];
                $system_prompt .= "\nProduct Information:\n";
                $system_prompt .= "- Price: " . esc_html($product['price']) . "\n";
                
                if (!empty($product['sale_price'])) {
                    $system_prompt .= "- Sale Price: " . esc_html($product['sale_price']) . "\n";
                }
                
                $system_prompt .= "- SKU: " . esc_html($product['sku']) . "\n";
                $system_prompt .= "- Stock Status: " . esc_html($product['stock_status']) . "\n";
                
                if (!empty($product['categories']) && is_array($product['categories'])) {
                    $system_prompt .= "- Categories: " . esc_html(implode(', ', $product['categories'])) . "\n";
                }
            }
            
            $system_prompt .= "\nPlease use this context to provide more relevant and personalized responses to the user's questions. You can reference the page content when appropriate, but don't explicitly tell the user you're using this information unless they specifically ask about it.\n";
        }
        
        // Add actions to system prompt if any exist
        if (!empty($this->actions)) {
            $system_prompt .= "\n\n\n\nThis chat supports the following actions:";
            
            foreach ($this->actions as $action) {
                $system_prompt .= "\n- {$action['name']} (ID: {$action['id']}): {$action['description']}";
                if (!empty($action['fields'])) {
                    $system_prompt .= "\n  Required fields:";
                    foreach ($action['fields'] as $field) {
                        $system_prompt .= "\n  - {$field['name']} ({$field['type']})" . 
                                        ($field['required'] ? " *" : "");
                    }
                }
            }
        
            $system_prompt .= "\n\nWhen a user requests one of these actions, you MUST follow this process: maintain a natural conversation but include a JSON response that looks like this:
            {
                \"type\": \"action\",
                \"action_id\": \"[use the exact ID specified above]\",
                \"confidence\": 0.0-1.0,
                \"extracted_params\": {
                    \"field_name\": \"extracted_value\"
                }
            }
        
            Important: Always respond in natural language.";
        }
    
        // Add the combined system prompt
        $messages[] = array(
            'role' => 'system',
            'content' => $system_prompt
        );
        
        // Add conversation history
        foreach ($history as $msg) {
            $messages[] = array(
                'role' => $msg->role,
                'content' => $msg->content
            );
        }
        
        // Add the current message
        $messages[] = array(
            'role' => 'user',
            'content' => $this->current_message
        );
    
        return $messages;
    }

    private function build_action_prompt() {
        $prompt = "\n\nThis chat supports the following actions:\n";
        
        foreach ($this->actions as $action) {
            $prompt .= "- {$action['name']} (ID: {$action['id']}): {$action['description']}\n";
            if (!empty($action['fields'])) {
                $prompt .= "  Required fields:\n";
                foreach ($action['fields'] as $field) {
                    $prompt .= "  - {$field['name']} ({$field['type']})" . 
                              ($field['required'] ? " *" : "") . "\n";
                }
            }
        }
    
        $prompt .= "\nWhen a user requests one of these actions, you MUST follow this process: maintain a natural conversation but include a hidden JSON response that looks like this:
        {
            \"type\": \"action\",
            \"action_id\": \"[use the exact ID specified above]\",
            \"confidence\": 0.0-1.0,
            \"extracted_params\": {
                \"field_name\": \"extracted_value\"
            }
        }
    
        Important: Extracted field names should be exactly the same as the ones in the action. Always respond in natural language. Never send the JSON file as a part of your message to the user.";
    
        return $prompt;
    }

    private function process_response($response) {

        WP_AI_Workflows_Utilities::debug_log("Processing response", "debug", [
            'response_type' => gettype($response),
            'is_array' => is_array($response),
            'has_text' => is_array($response) && isset($response['text']),
            'has_citations' => is_array($response) && isset($response['citations'])
        ]);
    
        // CASE 1: Response from Responses API (with tools)
        // When using the Responses API with tools (like web search), the response is an array with 
        // specific keys like 'text', 'citations', etc.
        if (is_array($response) && isset($response['text'])) {
            $responseText = $response['text'];
            $citations = isset($response['citations']) ? $response['citations'] : [];
            $search_results = isset($response['search_results']) ? $response['search_results'] : [];
    
            WP_AI_Workflows_Utilities::debug_log("Processing Responses API output", "debug", [
                'text_length' => strlen($responseText),
                'citations_count' => count($citations),
                'search_results_count' => count($search_results)
            ]);
            
            // Even in Responses API, we still need to check for actions
            // The model can include action JSON inside the text response
            $json_str = null;
            $decoded = null;
            
            // Try to find JSON in the response text - look for action format
            if (preg_match('/\{(?:[^{}]|(?R))*\}/m', $responseText, $matches)) {
                $json_str = $matches[0];
                $decoded = json_decode($json_str, true);
                
                WP_AI_Workflows_Utilities::debug_log("Found potential action JSON in Responses API text", "debug", [
                    'json_str' => $json_str,
                    'is_valid_json' => $decoded !== null
                ]);
            }
            
            // Check if the JSON is a valid action format
            if ($decoded && 
                isset($decoded['type']) && 
                $decoded['type'] === 'action' &&
                isset($decoded['action_id'])) {
                
                // Process action response from Responses API
                $action = $this->find_action($decoded['action_id']);
                if ($action && !empty($decoded['extracted_params'])) {
                    // Clean up display message - remove the JSON and code blocks
                    $display_message = trim(preg_replace('/```(?:json)?([\s\S]*?)```/', '', $responseText));
                    $display_message = trim(str_replace($json_str, '', $display_message));
                    
                    if (empty($display_message)) {
                        $display_message = "I'll get that information for you right away.";
                    }
                    
                    WP_AI_Workflows_Utilities::debug_log("Found action in Responses API output", "debug", [
                        'action_id' => $decoded['action_id'],
                        'params_count' => count($decoded['extracted_params'])
                    ]);
                    
                    return [
                        'type' => 'action',
                        'display_message' => $display_message,
                        'action_id' => $decoded['action_id'],
                        'action_data' => $decoded['extracted_params'],
                        'confidence' => $decoded['confidence'] ?? 1.0
                    ];
                }
            }
            
            // If no action was found, return the regular message with citations
            return [
                'type' => 'message',
                'display_message' => $responseText,
                'message' => $responseText,
                'citations' => $citations,
                'search_results' => $search_results,
                'data' => null
            ];
        }
        
        // CASE 2: Response from Chat Completions API
        // When using standard Chat Completions API, the response can be a string
        // or an array with 'choices', depending on how we process it in call_openai
        // Let's first normalize to get just the text content
        $response_text = '';
        
        if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
            $response_text = $response['choices'][0]['message']['content'];
        } else if (is_string($response)) {
            $response_text = $response;
        } else {
            // For debugging unexpected formats
            WP_AI_Workflows_Utilities::debug_log("Unexpected response format", "warning", [
                'response' => $response
            ]);
            
            // Try to extract something usable
            if (is_array($response)) {
                if (isset($response['choices'][0]['delta']['content'])) {
                    $response_text = $response['choices'][0]['delta']['content'];
                } else if (isset($response['content'])) {
                    $response_text = $response['content'];
                } else {
                    $response_text = 'I received a response in an unexpected format. Please try again.';
                }
            } else {
                $response_text = 'I received a response in an unexpected format. Please try again.';
            }
        }
        
        // Now that we have the text, look for action JSON
        $json_str = null;
        $decoded = null;
    
        // Try to find JSON in the response (with or without code blocks)
        if (preg_match('/\{(?:[^{}]|(?R))*\}/m', $response_text, $matches)) {
            $json_str = $matches[0];
            $decoded = json_decode($json_str, true);
            
            WP_AI_Workflows_Utilities::debug_log("Found potential JSON in response", "debug", [
                'json_str' => $json_str,
                'is_valid_json' => $decoded !== null
            ]);
        }
        
        // Check if the JSON is a valid action format
        if ($decoded && 
            isset($decoded['type']) && 
            $decoded['type'] === 'action' &&
            isset($decoded['action_id'])) {
    
            // Validate that this is a configured action
            $action = $this->find_action($decoded['action_id']);
            if ($action && !empty($decoded['extracted_params'])) {
                // Clean up display message - remove the JSON and code blocks
                $display_message = trim(preg_replace('/```(?:json)?([\s\S]*?)```/', '', $response_text));
                $display_message = trim(str_replace($json_str, '', $display_message));
                
                // If display message is empty, provide a confirmation the action is processing
                if (empty($display_message)) {
                    $display_message = "I'll get that information for you right away.";
                }
    
                WP_AI_Workflows_Utilities::debug_log("Found valid action in Chat Completions API response", "debug", [
                    'action_id' => $decoded['action_id'],
                    'params_count' => count($decoded['extracted_params'])
                ]);
                
                return [
                    'type' => 'action',
                    'display_message' => $display_message,
                    'action_id' => $decoded['action_id'],
                    'action_data' => $decoded['extracted_params'],
                    'confidence' => $decoded['confidence'] ?? 1.0
                ];
            }
        }
    
        // No action found, return regular message
        return [
            'type' => 'message',
            'display_message' => $response_text,
            'message' => $response_text,
            'data' => null,
            'citations' => []
        ];
    }

    /**
     * Prepare system prompt with action instructions for both API formats
     *
     * @param string $system_prompt Original system prompt
     * @return string Modified system prompt with action instructions
     */
    private function add_action_instructions($system_prompt) {
        if (empty($this->actions)) {
            return $system_prompt;
        }
        
        $action_prompt = "\n\n\n\nThis chat supports the following actions:";
                
        foreach ($this->actions as $action) {
            $action_prompt .= "\n- {$action['name']} (ID: {$action['id']}): {$action['description']}";
            if (!empty($action['fields'])) {
                $action_prompt .= "\n  Required fields:";
                foreach ($action['fields'] as $field) {
                    $action_prompt .= "\n  - {$field['name']} ({$field['type']})" . 
                                    ($field['required'] ? " *" : "");
                }
            }
        }

        $action_prompt .= "\n\nWhen a user requests one of these actions, maintain a natural conversation but include a JSON response that looks like this:
        {
            \"type\": \"action\",
            \"action_id\": \"[use the exact ID specified above]\",
            \"confidence\": 0.0-1.0,
            \"extracted_params\": {
                \"field_name\": \"extracted_value\"
            }
        }

        Important: Always respond in natural language first, then include the JSON. You can put the JSON in a code block or inline, but make sure it's valid JSON. The most important thing is to include the correct action_id and extracted_params with the field names exactly as specified.";

        return $system_prompt . $action_prompt;
    }
    

    // New helper method for finding actions
    private function find_action($action_id) {
        foreach ($this->actions as $action) {
            if ($action['id'] === $action_id) {
                return $action;
            }
        }
        return null;
    }


    public static function check_action_result($execution_id, $action_id, $workflow_id, $session_id) {
        try {
            global $wpdb;
            $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
            
            // Get the execution data
            $execution = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $executions_table WHERE id = %d",
                $execution_id
            ));
            
            if (!$execution) {
                return;
            }
            
            // If execution is still in progress, reschedule
            if ($execution->status === 'processing' || $execution->status === 'paused') {
                wp_schedule_single_event(
                    time() + 3,
                    'wp_ai_workflows_check_action_result',
                    array(
                        'execution_id' => $execution_id,
                        'action_id' => $action_id,
                        'workflow_id' => $workflow_id,
                        'session_id' => $session_id
                    )
                );
    
                return;
            }
            
            // Get the output data
            $output_data = json_decode($execution->output_data, true);
            if (empty($output_data)) {
                WP_AI_Workflows_Utilities::debug_log("No output data for action result", "error", [
                    'execution_id' => $execution_id
                ]);
                return;
            }
            
            // Create a chat handler
            $chat_handler = new self($workflow_id, $session_id);
            
            // Get chat history
            $chat_history = $chat_handler->session->get_history();
            $formatted_history = [];
            
            // Format chat history for inclusion in the prompt
            if (!empty($chat_history)) {
                foreach ($chat_history as $msg) {
                    $formatted_history[] = $msg->role . ": " . $msg->content;
                }
            }
            
            // Find the chat node
            $chat_node_id = null;
            foreach ($output_data as $node_id => $node_output) {
                if (isset($node_output['type']) && $node_output['type'] === 'chat') {
                    $chat_node_id = $node_id;
                    break;
                }
            }
            
            // Prepare output summary
            $output_summary = array();
            foreach ($output_data as $node_id => $node_output) {
                if ($node_id !== $chat_node_id) {
                    $output_summary[$node_id] = $node_output;
                }
            }
            
            // Generate prompt with chat history context
            $prompt = "Based on the following workflow results and conversation history, provide a natural, conversational response to the user's latest request. Format information clearly and helpfully, but maintain a natural tone as if you're continuing a conversation. Do not reference the technical details of the execution or include things like API calls, error codes, status codes, or IDs. Your job is to present the results in a human-friendly way that fits the conversation flow.\n\n";
            
            // Add chat history context
            if (!empty($formatted_history)) {
                $prompt .= "Conversation history (most recent last):\n";
                // Include the last 5 messages at most to keep the context relevant
                $recent_history = array_slice($formatted_history, -5);
                $prompt .= implode("\n", $recent_history) . "\n\n";
            }
            
            // Add workflow output
            $prompt .= "Workflow output: " . json_encode($output_summary, JSON_PRETTY_PRINT);
            
            // Prepare context
            $context = array(
                array(
                    'role' => 'system',
                    'content' => 'You are an assistant continuing a conversation after completing an automated workflow action. Be concise, helpful, and conversational. Keep your response focused on the workflow results and user\'s request. Never mention that you are responding to an action or that you received workflow output. Your response should appear as a natural continuation of the conversation.'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            );
            
            try {
                // Call language model
                $is_openrouter = isset($chat_handler->model) && strpos($chat_handler->model, '/') !== false;
                $raw_response = $is_openrouter ? 
                    $chat_handler->call_openrouter($context) : 
                    $chat_handler->call_openai($context);
                
                // Extract content
                $response_content = '';
                if (is_array($raw_response) && isset($raw_response['choices'][0]['message']['content'])) {
                    // Handle structured OpenAI API response
                    $response_content = $raw_response['choices'][0]['message']['content'];
                } else if (is_string($raw_response)) {
                    // Handle direct string response
                    $response_content = $raw_response;
                }
                
                // Process result
                if (!empty($response_content)) {
                    // Add to chat history
                    try {
                        $chat_handler->session->add_message('assistant', $response_content);
                    } catch (Exception $e) {
                        WP_AI_Workflows_Utilities::debug_log("Failed to add to chat history", "error", [
                            'error' => $e->getMessage()
                        ]);
                    }
                    
                    // Create result array
                    $result = [
                        'role' => 'assistant',
                        'content' => $response_content,
                        'timestamp' => time()
                    ];
                    
                    // Store in transient
                    set_transient(
                        'wp_ai_workflows_action_result_' . $session_id,
                        $result,
                        3600 // 1 hour expiry
                    );
                    
                    // Final success log
                    WP_AI_Workflows_Utilities::debug_log("Action result processed successfully", "info", [
                        'execution_id' => $execution_id,
                        'session_id' => $session_id
                    ]);
                } else {
                    WP_AI_Workflows_Utilities::debug_log("Empty response content", "warning");
                }
            } catch (Exception $inner_e) {
                WP_AI_Workflows_Utilities::debug_log("Exception in language model call", "error", [
                    'error' => $inner_e->getMessage()
                ]);
            }
        } catch (Exception $outer_e) {
            WP_AI_Workflows_Utilities::debug_log("Critical error in check_action_result", "error", [
                'error' => $outer_e->getMessage()
            ]);
        }
    }
    
    
    private function call_openai($messages) {
        $response = WP_AI_Workflows_Utilities::call_openai_api(
            json_encode($messages),
            $this->model,
            [],
            $this->model_params
        );
    
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
    
        // Add debug logging
        WP_AI_Workflows_Utilities::debug_log("OpenAI API Response", "debug", [
            'response' => $response,
            'model' => $this->model,
            'content' => isset($response['choices'][0]['message']['content']) ? 
                $response['choices'][0]['message']['content'] : 'No content found'
        ]);
    
        // Extract just the content from the response
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }
    
        throw new Exception('Unexpected response format from OpenAI');
    }
    
    private function call_openrouter($messages) {
        $response = WP_AI_Workflows_Utilities::call_openrouter_api(
            json_encode($messages),
            $this->model,
            [],
            $this->model_params
        );
    
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
    
        // Extract just the content from the response
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }
    
        throw new Exception('Unexpected response format from OpenRouter');
    }
    
    public function get_chat_history() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE session_id = %s 
            ORDER BY created_at ASC",
            $this->session_id
        ));
    }
    
    private function save_message($role, $content) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        
        $wpdb->insert(
            $table_name,
            array(
                'session_id' => $this->session_id,
                'role' => $role,
                'content' => $content,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );
    }
    
    private function get_workflow_by_id($workflow_id) {
        // Use DBAL to get workflow directly
        return WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow_id);
    }
    
    private function find_chat_node($nodes) {
        foreach ($nodes as $node) {
            if ($node['type'] === 'chat') {
                return $node;
            }
        }
        return null;
    }

    public function handle_streaming_message($message, $page_context = null) {
        // Origin validation
        $allowed_origins = array(get_site_url());
        if (!empty($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
            throw new Exception('Invalid request origin');
        }
    
        $this->page_context = $page_context;
    
        // Debug log the incoming request parameters
        WP_AI_Workflows_Utilities::debug_log("Streaming message request", "debug", [
            'workflow_id' => $this->session->get_workflow_id(),
            'session_id' => $this->session->get_session_id(),
            'message_length' => strlen($message),
            'has_page_context' => !empty($page_context)
        ]);
    
        // Check for workflow refresh flag
        if (get_transient('wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id())) {
            $this->load_workflow_config();
            delete_transient('wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id());
        }
    
        // Input validation
        if (strlen($message) > 2000) {
            throw new Exception('Message too long');
        }
        if (empty(trim($message))) {
            throw new Exception('Empty message');
        }
    
        // Sanitize input
        $message = wp_kses($message, array(
            'a' => array('href' => array(), 'target' => array('_blank')),
            'b' => array(),
            'strong' => array(),
            'i' => array(),
            'em' => array(),
            'code' => array(),
            'pre' => array()
        ));
    
        try {
            // Rate limit check
            if (!$this->session->can_send_message()) {
                throw new Exception('Rate limit exceeded');
            }
    
            // Log session state prior to message handling
            WP_AI_Workflows_Utilities::debug_log("Session state before streaming", "debug", [
                'workflow_id' => $this->session->get_workflow_id(),
                'session_id' => $this->session->get_session_id(),
                'history_count' => count($this->session->get_history())
            ]);
    
            // Store current message and prepare context
            $this->current_message = $message;
            $context = $this->prepare_context($this->session->get_history());
    
            // First, save the user message to history
            $this->session->add_message('user', $message);
    
            // Validate that actions are not configured if using streaming
            if (!empty($this->actions)) {
                WP_AI_Workflows_Utilities::debug_log("Actions found in streaming mode", "warning", [
                    'action_count' => count($this->actions),
                    'session_id' => $this->session->get_session_id()
                ]);
                
                // Send error as SSE
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                echo "data: " . json_encode([
                    'error' => true,
                    'message' => 'Streaming mode does not support actions. Please disable streaming or remove actions.'
                ]) . "\n\n";
                echo "data: [DONE]\n\n";
                exit;
            }
            
            // Use streaming methods based on model type
            if (strpos($this->model, '/') !== false) {
                return $this->stream_openrouter_response($context);
            } else {
                return $this->stream_openai_response($context);
            }
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error in handle_streaming_message", "error", [
                'error_type' => get_class($e),
                'message' => 'Chat streaming failed: ' . $e->getMessage(),
                'session_id' => $this->session->get_session_id()
            ]);
            
            // Return error as SSE format
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            echo "data: " . json_encode([
                'error' => true,
                'message' => 'Unable to process message. Please try again later.'
            ]) . "\n\n";
            echo "data: [DONE]\n\n";
            exit;
        }
    }
    
   
    

    /**
     * Stream OpenAI API response with correct event type handling
     *
     * @param array $messages Array of message objects for the conversation history
     * @return void Outputs streaming response directly
     */
    private function stream_openai_response($messages) {
        $api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
        if (empty($api_key)) {
            throw new Exception('OpenAI API key is not set');
        }

        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // For Nginx
        
        // Disable output buffering
        if (ob_get_level()) ob_end_clean();
        ob_implicit_flush(true);
        
        // *** IMPORTANT CHANGE: Send session ID at the beginning of the stream ***
        echo "data: " . json_encode([
            'content' => '',
            'session_id' => $this->session->get_session_id()
        ]) . "\n\n";
        flush();
        
        // Prepare request data
        $model = strpos($this->model, 'openai/') === 0 ? substr($this->model, 7) : $this->model;
        
        // Check if we need to use the Responses API for tools
        $use_responses_api = false;
        $tools = [];
        
        if (isset($this->openai_tools)) {
            $web_search_enabled = isset($this->openai_tools['webSearch']) && 
                                isset($this->openai_tools['webSearch']['enabled']) && 
                                $this->openai_tools['webSearch']['enabled'];
                                
            $file_search_enabled = isset($this->openai_tools['fileSearch']) && 
                                isset($this->openai_tools['fileSearch']['enabled']) && 
                                $this->openai_tools['fileSearch']['enabled'];
            
            // If any tools are enabled, we'll use the Responses API
            if ($web_search_enabled || $file_search_enabled) {
                $use_responses_api = true;
                
                // Prepare tools configuration
                if ($web_search_enabled) {
                    $web_search_tool = ['type' => 'web_search'];
                    
                    // Add context size if specified
                    if (isset($this->openai_tools['webSearch']['contextSize'])) {
                        $context_size = $this->openai_tools['webSearch']['contextSize'];
                        // If it's a string value like 'medium', keep it as is
                        if (is_string($context_size) && !is_numeric($context_size)) {
                            $web_search_tool['search_context_size'] = $context_size;
                        }
                    }
                    
                    // Add location settings if specified
                    if (isset($this->openai_tools['webSearch']['location'])) {
                        $location = $this->openai_tools['webSearch']['location'];
                        if (!empty($location['city']) || !empty($location['region']) || !empty($location['country'])) {
                            $web_search_tool['user_location'] = [
                                'type' => 'approximate',
                                'city' => !empty($location['city']) ? $location['city'] : null,
                                'region' => !empty($location['region']) ? $location['region'] : null,
                                'country' => !empty($location['country']) ? $location['country'] : null,
                                'timezone' => null
                            ];
                        }
                    }
                    
                    $tools[] = $web_search_tool;
                }
                
                if ($file_search_enabled && !empty($this->openai_tools['fileSearch']['vectorStoreId'])) {
                    $file_search_tool = [
                        'type' => 'file_search',
                        'vector_store_ids' => [$this->openai_tools['fileSearch']['vectorStoreId']]
                    ];
                    
                    // Add max results if specified
                    if (isset($this->openai_tools['fileSearch']['maxResults'])) {
                        $file_search_tool['max_num_results'] = intval($this->openai_tools['fileSearch']['maxResults']);
                    }
                    
                    $tools[] = $file_search_tool;
                }
            }
        }
        
        // Set endpoint and prepare request data
        if ($use_responses_api) {
            // Responses API
            $endpoint = 'https://api.openai.com/v1/responses';
            
            // Extract system message
            $system_message = null;
            foreach ($messages as $message) {
                if ($message['role'] === 'system') {
                    $system_message = $message['content'];
                    break;
                }
            }
        
            // Build conversation history without the system message
            $conversation_history = [];
            foreach ($messages as $message) {
                if ($message['role'] !== 'system') {
                    $conversation_history[] = $message;
                }
            }
            
            // Format conversation history into a structured string
            // This allows the AI to understand the full conversation flow
            $conversation_formatted = "";
            $last_user_message = "";
            
            if (count($conversation_history) > 1) {
                // Get all messages except the last one (current user message)
                $prev_messages = array_slice($conversation_history, 0, count($conversation_history) - 1);
                
                foreach ($prev_messages as $msg) {
                    $role = ucfirst($msg['role']);
                    $conversation_formatted .= "{$role}: " . $msg['content'] . "\n\n";
                }
            }
            
            // Get the last user message separately
            $last_message = end($conversation_history);
            if ($last_message && $last_message['role'] === 'user') {
                $last_user_message = $last_message['content'];
            }
            
            // If we have previous conversation, include it in a structured format
            $input_message = $last_user_message;
            if (!empty($conversation_formatted)) {
                $input_message = "Previous conversation:\n" . $conversation_formatted . 
                                 "Current message: " . $last_user_message;
            }
            
            
            $data = [
                'model' => $model,
                'input' => $input_message,
                'stream' => true,
                'tools' => $tools
            ];
            
            // Add instructions (system message) if available
            if ($system_message !== null) {
                $data['instructions'] = $system_message;
            }
            
            // Add model parameters - ONLY ALLOWED ONES for Responses API
            if (!empty($this->model_params)) {
                // Responses API only supports temperature and top_p
                if (isset($this->model_params['temperature'])) {
                    $data['temperature'] = floatval($this->model_params['temperature']);
                }
                
                if (isset($this->model_params['top_p'])) {
                    $data['top_p'] = floatval($this->model_params['top_p']);
                }
                
                // Seed is also supported
                if (isset($this->model_params['seed'])) {
                    $data['seed'] = intval($this->model_params['seed']);
                }
            }
        }else {
            // Chat Completions API
            $endpoint = 'https://api.openai.com/v1/chat/completions';
            
            $data = [
                'model' => $model,
                'messages' => $messages,
                'stream' => true
            ];
            
            // Add model parameters for Chat Completions API
            if (!empty($this->model_params)) {
                $allowed_params = [
                    'temperature', 'top_p', 'n', 'stop', 'max_tokens', 
                    'presence_penalty', 'frequency_penalty', 'logit_bias', 
                    'user', 'seed'
                ];
                
                foreach ($this->model_params as $key => $value) {
                    // Only include supported parameters
                    if (in_array($key, $allowed_params)) {
                        if ($key === 'max_tokens') {
                            $data['max_tokens'] = intval($value);
                        } else {
                            $data[$key] = $value;
                        }
                    }
                }
            }
        }

        // Prepare cURL request
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        // Headers
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Set timeout settings
        curl_setopt($ch, CURLOPT_TIMEOUT, 180); // 3 minute timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 second connection timeout
        
        // Track chunk count and accumulated response for debugging
        $chunkCount = 0;
        $responseAccumulator = '';
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$chunkCount, &$responseAccumulator, $use_responses_api) {
            // Process SSE data chunk
            $lines = explode("\n", $data);
            $output = '';
            
            foreach ($lines as $line) {
                if (strlen(trim($line)) === 0) {
                    continue;
                }
                
                // Handle SSE format
                if (strpos($line, 'data:') === 0) {
                    $jsonData = trim(substr($line, 5));
                    
                    // Count chunks for debugging
                    $chunkCount++;
                    
                    // Handle end of stream
                    if ($jsonData === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        flush();
                        continue;
                    }
                    
                    // Parse JSON from the SSE line
                    try {
                        $responseData = json_decode($jsonData, true);
                        
                        if (!is_array($responseData)) {
                            continue;
                        }
                        
                        // Different structure for Responses API vs Chat Completions API
                        if ($use_responses_api) {
                            // Handle Responses API format - NEW FORMAT
                            // Look for the type field to determine what kind of event this is
                            if (isset($responseData['type'])) {
                                // This handles the actual text content chunks in Responses API
                                if ($responseData['type'] === 'response.output_text.delta' && isset($responseData['delta'])) {
                                    $contentText = $responseData['delta'];
                                    echo "data: " . json_encode(['content' => $contentText]) . "\n\n";
                                    flush();
                                    $output .= $contentText;
                                }
                                // You can also handle other event types if needed
                            }
                        } else {
                            // Extract content delta if available (for Chat Completions API)
                            if (isset($responseData['choices'][0]['delta']['content'])) {
                                $content = $responseData['choices'][0]['delta']['content'];
                                echo "data: " . json_encode(['content' => $content]) . "\n\n";
                                flush();
                                $output .= $content;
                            }
                        }
                    } catch (Exception $e) {
                        // Silently handle exception
                    }
                }
            }
            
            // Accumulate the complete response
            $responseAccumulator .= $output;
            
            return strlen($data);
        });
        
        // Execute the request
        $result = curl_exec($ch);
        
        // Get error info if any
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        
        curl_close($ch);
        
        if ($err || $info['http_code'] >= 400) {
            // Send error as SSE
            echo "data: " . json_encode([
                'error' => true, 
                'message' => 'Error connecting to AI service. Please try again.'
            ]) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
        } else {
            // Log success
            WP_AI_Workflows_Utilities::debug_log("API request successful", "debug", [
                'http_code' => $info['http_code'],
                'total_time' => $info['total_time'],
                'using_responses_api' => $use_responses_api,
                'chunks_received' => $chunkCount,
                'response_length' => strlen($responseAccumulator)
            ]);
            
            // Save the complete response to chat history
            if (!empty($responseAccumulator)) {
                $this->session->add_message('assistant', $responseAccumulator);
            }
            
            // Ensure we send a final DONE signal
            echo "data: [DONE]\n\n";
            flush();
        }
        
        exit;
    }

    /**
     * Stream OpenRouter API response
     *
     * @param array $messages Array of message objects for the conversation history
     * @return void Outputs streaming response directly
     */
    private function stream_openrouter_response($messages) {
        $api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
        if (empty($api_key)) {
            throw new Exception('OpenRouter API key is not set');
        }
    
        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // For Nginx
        
        // Disable output buffering
        if (ob_get_level()) ob_end_clean();
        ob_implicit_flush(true);
        
        // *** IMPORTANT CHANGE: Send session ID at the beginning of the stream ***
        echo "data: " . json_encode([
            'content' => '',
            'session_id' => $this->session->get_session_id() 
        ]) . "\n\n";
        flush();
        
        // Log the start of streaming
        WP_AI_Workflows_Utilities::debug_log("Starting OpenRouter streaming", "debug", [
            'model' => $this->model,
            'messages_count' => count($messages),
            'session_id' => $this->session->get_session_id() // Add session ID to logs
        ]);
    
        // Prepare request data
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true
        ];
    
        // Add model parameters
        if (!empty($this->model_params)) {
            foreach ($this->model_params as $key => $value) {
                if ($key === 'max_tokens') {
                    $data['max_tokens'] = intval($value);
                } else {
                    $data[$key] = $value;
                }
            }
        }
    
        // Prepare cURL request
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
            'HTTP-Referer: ' . get_site_url(),
            'X-Title: WP AI Workflows'
        ]);
        
        // Set timeout settings
        curl_setopt($ch, CURLOPT_TIMEOUT, 180); // 3 minute timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 second connection timeout
        
        // Track chunk count and accumulated response for debugging
        $chunkCount = 0;
        $GLOBALS['wp_ai_workflows_streaming_response'] = '';
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$chunkCount) {
            // Process SSE data chunk
            $lines = explode("\n", $data);
            $output = '';
            
            foreach ($lines as $line) {
                if (strlen(trim($line)) === 0) {
                    continue;
                }
                
                // Handle comments in SSE (OpenRouter specific)
                if (strpos($line, ':') === 0) {
                    // This is a comment line (e.g., ": OPENROUTER PROCESSING")
                    // Just log it for debugging but don't send to client
                    WP_AI_Workflows_Utilities::debug_log("OpenRouter SSE comment", "debug", [
                        'comment' => trim($line)
                    ]);
                    continue;
                }
                
                // Handle SSE format
                if (strpos($line, 'data:') === 0) {
                    $jsonData = trim(substr($line, 5));
                    
                    // Count chunks for debugging
                    $chunkCount++;
                    
                    // Handle end of stream
                    if ($jsonData === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        flush();
                        continue;
                    }
                    
                    // Parse JSON from the SSE line
                    try {
                        $responseData = json_decode($jsonData, true);
                        
                        // Extract content delta if available
                        if (isset($responseData['choices'][0]['delta']['content'])) {
                            $content = $responseData['choices'][0]['delta']['content'];
                            echo "data: " . json_encode(['content' => $content]) . "\n\n";
                            flush();
                            $output .= $content;
                        }
                    } catch (Exception $e) {
                        // Log parsing errors but continue
                        WP_AI_Workflows_Utilities::debug_log("JSON parse error in stream", "warning", [
                            'raw_data' => $jsonData,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            // Accumulate the complete response
            $GLOBALS['wp_ai_workflows_streaming_response'] .= $output;
            
            return strlen($data);
        });
        
        // Execute the request
        curl_exec($ch);
        
        // Get error info if any
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        if ($err) {
            WP_AI_Workflows_Utilities::debug_log("OpenRouter streaming error", "error", [
                'curl_error' => $err,
                'http_code' => $info['http_code'] ?? 'unknown',
                'session_id' => $this->session->get_session_id() // Add session ID to error logs
            ]);
            
            // Send error as SSE
            echo "data: " . json_encode([
                'error' => true, 
                'message' => 'Error: ' . $err
            ]) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
        } else {
            // Log success
            WP_AI_Workflows_Utilities::debug_log("OpenRouter streaming completed", "debug", [
                'chunks_received' => $chunkCount,
                'total_length' => strlen($GLOBALS['wp_ai_workflows_streaming_response']),
                'http_code' => $info['http_code'],
                'total_time' => $info['total_time'],
                'session_id' => $this->session->get_session_id() // Add session ID to success logs
            ]);
            
            // Save the complete response to chat history
            if (!empty($GLOBALS['wp_ai_workflows_streaming_response'])) {
                $this->session->add_message('assistant', $GLOBALS['wp_ai_workflows_streaming_response']);
            }
            
            // Ensure we send a final DONE signal
            echo "data: [DONE]\n\n";
            flush();
        }
        
        exit;
    }
}