<?php

class WP_AI_Workflows_Node_Execution {

    public function init(): void {
        // Any initialization code if needed
    }

    public static function execute_node($node, $node_data, $edges, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
        
        if (!isset($node['id']) || !isset($node['type'])) {
            WP_AI_Workflows_Utilities::debug_log("Invalid node structure", "error", ['node' => $node]);
            return self::create_node_data('error', "Invalid node structure");
        }
        
        $node_id = $node['id'];
        $node_type = $node['type'];

        $input_data = self::get_node_input_data($node_id, $edges, $node_data);

        // Replace input tags in node content before execution
        if (isset($node['data']['content'])) {
            $node['data']['content'] = self::replace_input_tags($node['data']['content'], $input_data);
        }

        if ($node_type === 'trigger' && $node['data']['triggerType'] === 'webhook') {
            $webhook_data = $input_data;
            $webhook_keys = $node['data']['webhookKeys'] ?? [];
            $result = array();
            foreach ($webhook_keys as $key) {
                $value = self::get_nested_value($webhook_data, $key['key'], '/');
                $result[$key['key']] = array(
                    'type' => 'webhookInput',
                    'content' => $value
                );
            }
            return $result;
        }
        
        $result = null;
        switch ($node_type) {
            case 'trigger':
                $result = self::execute_trigger_node($node, $input_data, $execution_id);
                break;
            case 'aiModel':
                $result = self::execute_ai_model_node($node, $input_data, $execution_id);
                break;
            case 'output':
                $result = self::execute_output_node($node, $input_data, $execution_id);
                break;
            case 'post':
                $result = self::execute_post_node($node, $input_data, $execution_id);
                break;
            case 'research':
                $result = self::execute_research_node($node, $input_data, $execution_id);
                break;
            case 'unsplash':
                $result = self::execute_unsplash_node($node, $input_data, $execution_id);
                break;
            case 'chat':
                $result = self::execute_chat_node($node, $input_data, $execution_id);
                break;
            default:
                WP_AI_Workflows_Utilities::debug_log("Unsupported node type", "error", ["node_type" => $node_type]);
                $result = self::create_node_data('error', "Unsupported node type: " . $node_type);
        }

        return $result;
    }

    public static function execute_trigger_node($node, $input_data, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
    
        $triggerType = isset($node['data']['triggerType']) ? $node['data']['triggerType'] : 'manual';
        $outputData = null;
        
        switch ($triggerType) {
            case 'wpCore':
                // First try to use direct input_data
                if (!empty($input_data)) {
                    $outputData = $input_data;
                } else {
                    // Try to get from transient
                    $transient_key = 'wp_ai_workflow_trigger_data_' . $node['data']['workflowId'];
                    $stored_data = get_transient($transient_key);
                    if ($stored_data) {
                        $outputData = $stored_data;
                        delete_transient($transient_key);
                    }
                }
                break;
                case 'webhook':
                    global $initial_webhook_data;
                    if (is_array($initial_webhook_data) && isset($initial_webhook_data['output'])) {
                        $outputData = $initial_webhook_data['output'];
                    } else {
                        $outputData = $initial_webhook_data; // Keep original data type
                    }
                    break;
            case 'gravityForms':
            case 'wpForms':
            case 'contactForm7':
            case 'ninjaForms':
                $outputData = $input_data;
                break;
                case 'rss':
                    if (!empty($node['data']['rssSettings']['feedUrl'])) {
                        $feed_url = $node['data']['rssSettings']['feedUrl'];
                        $last_execution_key = 'wp_ai_workflows_rss_last_execution_' . md5($feed_url . $execution_id);
                        
                        // Check if this feed was just processed
                        if (get_transient($last_execution_key)) {
                            return self::create_node_data('trigger', []);
                        }
                        
                        // Set execution lock
                        set_transient($last_execution_key, time(), 30);
                        
                        include_once(ABSPATH . WPINC . '/feed.php');
                        $rss = fetch_feed($feed_url);
                        
                        if (is_wp_error($rss)) {
                            return self::create_node_data('error', $rss->get_error_message());
                        }
                
                        $maxitems = $node['data']['rssSettings']['maxItems'] ?? 10;
                        $items = $rss->get_items(0, $maxitems);
                        
                        // Format items into a string-based format
                        $formatted_items = array_map(function($item) use ($node) {
                            $data = [
                                'title' => $item->get_title() ?: '',
                                'link' => $item->get_permalink() ?: '',
                                'description' => strip_tags($item->get_description() ?: ''),
                                'pubDate' => $item->get_date('Y-m-d H:i:s') ?: '',
                                'author' => $item->get_author() ? $item->get_author()->get_name() : '',
                            ];
                
                            // Get enclosure URL (for podcasts/media)
                            $enclosure = $item->get_enclosure();
                            if ($enclosure) {
                                $data['media_url'] = $enclosure->get_link();
                                $data['media_type'] = $enclosure->get_type();
                                $data['media_length'] = $enclosure->get_length();
                            }
                
                            // Get categories if they exist
                            $categories = $item->get_categories();
                            $data['categories'] = [];
                            if ($categories) {
                                $data['categories'] = implode(', ', array_map(function($cat) {
                                    return $cat->get_label();
                                }, $categories));
                            }
                
                            // Get content if enabled
                            if ($node['data']['rssSettings']['includeContent']) {
                                $data['content'] = strip_tags($item->get_content() ?: '');
                            }
                
                            // Process any arrays into strings
                            array_walk($data, function(&$value) {
                                if (is_array($value)) {
                                    $value = implode(', ', $value);
                                } elseif (!is_string($value) && !is_numeric($value)) {
                                    $value = strval($value);
                                }
                            });
                
                            return $data;
                        }, $items);
                
                        // Convert items list to a string for array output
                        $items_string = array_map(function($item) {
                            return implode("\n", array_map(function($key, $value) {
                                return ucfirst($key) . ": " . $value;
                            }, array_keys($item), $item));
                        }, $formatted_items);
                
                        // Create the output structure
                        $outputData = [
                            'items' => $items_string,
                            'latest' => !empty($formatted_items) ? $formatted_items[0] : null,
                            'feed_title' => strval($rss->get_title()),
                            'feed_description' => strval($rss->get_description()),
                            'feed_link' => strval($rss->get_permalink()),
                            'total_items' => strval(count($formatted_items))
                        ];
                
                        // Make sure nested arrays are converted to strings
                        $outputData = self::replace_input_tags_recursive($outputData, $input_data);
                        
                        return self::create_node_data('trigger', $outputData);
                    }
                    break;
                case 'workflowOutput':
                $source_workflow_id = isset($node['data']['selectedWorkflow']) ? $node['data']['selectedWorkflow'] : null;
                
                if (!$source_workflow_id) {
                    return self::create_node_data('error', 'No source workflow selected');
                }

                // Check for directly connected workflow output
                if (!empty($input_data)) {
                    $outputData = $input_data;
                } else {
                    // Look for the latest completed execution of the source workflow
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
                    
                    $latest_execution = $wpdb->get_row($wpdb->prepare(
                        "SELECT output_data 
                        FROM $table_name 
                        WHERE workflow_id = %s 
                        AND status = 'completed'
                        ORDER BY created_at DESC 
                        LIMIT 1",
                        $source_workflow_id
                    ));

                    if ($latest_execution) {
                        $outputData = json_decode($latest_execution->output_data, true);
                    } else {
                        $outputData = null;
                    }
                }
                break;
            case 'manual':
            default:
                $outputData = isset($node['data']['content']) ? $node['data']['content'] : '';
                break;
        }
        
        WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Executed trigger node');
        return self::create_node_data('trigger', $outputData);
    }

    private static function get_nested_value($array, $keys, $delimiter = '.') {
        // Normalize keys - if it's a string, split by delimiter
        if (is_string($keys)) {
            $keys = explode($delimiter, $keys);
        }
    
        $current = $array;
        
        foreach ($keys as $key) {
            // Handle array index access (e.g., items.0.title or items/0/title)
            if (is_numeric($key) && is_array($current)) {
                $array_keys = array_keys($current);
                if (isset($array_keys[(int)$key])) {
                    $current = $current[$array_keys[(int)$key]];
                    continue;
                }
            }
            
            // Regular key access
            if (is_array($current) && isset($current[$key])) {
                $current = $current[$key];
            } else {
                return null;
            }
        }
        
        return $current;
    }



    public static function execute_ai_model_node($node, $input_data, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
    
        $content = sanitize_text_field($node['data']['content'] ?? "Default prompt");
        $model = isset($node['data']['model']) ? $node['data']['model'] : "gpt-4o-mini";
        $imageUrls = isset($node['data']['imageUrls']) ? $node['data']['imageUrls'] : [];
        $parameters = isset($node['data']['settings']) ? $node['data']['settings'] : [];
        $openaiTools = isset($node['data']['openaiTools']) ? $node['data']['openaiTools'] : null;
        
        $is_direct_openai = strpos($model, '/') === false;

        // Check if tools are configured
        $use_openai_tools = false;
        $tools_config = null;
        
        if ($is_direct_openai && !empty($openaiTools)) {
            $tools_config = self::prepare_openai_tools($openaiTools);
            $use_openai_tools = !empty($tools_config);
            
            WP_AI_Workflows_Utilities::debug_log("Tools configuration prepared", "debug", [
                "tools_config" => $tools_config,
                "model" => $model,
                "is_direct_openai" => $is_direct_openai
            ]);
        }
    
        // Replace input tags
        $prompt = self::replace_input_tags($content, $input_data);
        $processedImageUrls = array_map(function($url) use ($input_data) {
            return self::replace_input_tags($url, $input_data);
        }, $imageUrls);
    
        WP_AI_Workflows_Utilities::debug_log("AI model input", "debug", [
            "content" => $content,
            "model" => $model,
            "imageUrls" => $imageUrls,
            "parameters" => $parameters,
            "use_tools" => $use_openai_tools
        ]);
    
        try {
            if ($use_openai_tools) {
                $response = WP_AI_Workflows_Utilities::call_openai_with_tools(
                    $prompt, 
                    $model, 
                    $processedImageUrls, 
                    $tools_config, 
                    $parameters
                );
    
                if (isset($response['usage'])) {
                    try {
                        $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
                        $cost = $cost_manager->calculate_node_cost(
                            $execution_id,
                            $node['id'],
                            'openai',
                            $model,
                            $response['usage']['input_tokens'] ?? 0,
                            $response['usage']['output_tokens'] ?? 0
                        );
    
                        WP_AI_Workflows_Utilities::debug_log("Cost calculation complete", "debug", [
                            'cost' => $cost,
                            'usage' => $response['usage']
                        ]);
                    } catch (Exception $e) {
                        WP_AI_Workflows_Utilities::debug_log("Error calculating cost", "error", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $formatted_citations = '';
                if (!empty($response['citations'])) {
                    $formatted_citations = "Sources:\n";
                    foreach ($response['citations'] as $citation) {
                        if ($citation['type'] === 'file_citation') {
                            $formatted_citations .= "- File: {$citation['filename']}\n";
                        } elseif ($citation['type'] === 'url_citation') {
                            $formatted_citations .= "- {$citation['title']}: {$citation['url']}\n";
                        }
                    }
                }
        
                // Format search results into readable string
                $formatted_search_results = '';
                if (!empty($response['search_results'])) {
                    $formatted_search_results = "Search Results:\n";
                    foreach ($response['search_results'] as $result) {
                        $formatted_search_results .= "- Type: {$result['type']}\n";
                        if ($result['type'] === 'file_search_call') {
                            $formatted_search_results .= "  Queries: " . implode(", ", $result['queries']) . "\n";
                        }
                        if (isset($result['results']) && !empty($result['results'])) {
                            $formatted_search_results .= "  Results: " . count($result['results']) . " items found\n";
                        }
                        $formatted_search_results .= "\n";
                    }
                }
    
                return self::create_node_data('aiModel', [
                    'content' => $response['text'],
                    'citations' => $formatted_citations,
                    'search_results' => $formatted_search_results
                ]);
            } else {
                // Original implementation for non-tools calls
                $provider = strpos($model, '/') !== false ? 'openrouter' : 'openai';
                $response = ($provider === 'openrouter') ?
                    WP_AI_Workflows_Utilities::call_openrouter_api($prompt, $model, $processedImageUrls, $parameters) :
                    WP_AI_Workflows_Utilities::call_openai_api($prompt, $model, $processedImageUrls, $parameters);
    
                if (!is_wp_error($response)) {
                    // Get the actual content from the response
                    $content = $response['choices'][0]['message']['content'];
                    
                    // Process the usage data if available
                    if (isset($response['usage'])) {
                        try {
                            $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
                            $cost = $cost_manager->calculate_node_cost(
                                $execution_id,
                                $node['id'],
                                $provider,
                                $model,
                                $response['usage']['prompt_tokens'] ?? 0,
                                $response['usage']['completion_tokens'] ?? 0
                            );
    
                            WP_AI_Workflows_Utilities::debug_log("Cost calculation complete", "debug", [
                                'cost' => $cost,
                                'usage' => $response['usage']
                            ]);
                        } catch (Exception $e) {
                            WP_AI_Workflows_Utilities::debug_log("Error calculating cost", "error", [
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
    
                    // Process the response content
                    $processed_response = self::process_ai_response($content);
                    
                    if (is_array($processed_response)) {
                        $processed_response = wp_json_encode($processed_response);
                    }
                    
                    // Return the node data
                    return self::create_node_data('aiModel', $processed_response);
                }
            }
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("API call failed", "error", [
                "error" => $e->getMessage(),
                "using_tools" => $use_openai_tools
            ]);
            return self::create_node_data('error', $e->getMessage());
        }
    }

    private static function prepare_openai_tools($tools_config) {
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
                        'type' => 'approximate',
                        'city' => $location['city'] ?? null,
                        'region' => $location['region'] ?? null,
                        'country' => $location['country'] ?? null,
                        'timezone' => null
                    ];
                }
            }
            
            $tools[] = $web_search_tool;
        }
        
        // Add file search tool if enabled
        if (isset($tools_config['fileSearch']) && isset($tools_config['fileSearch']['enabled']) && 
            $tools_config['fileSearch']['enabled'] && !empty($tools_config['fileSearch']['vectorStoreId'])) {
            $file_search_tool = [
                'type' => 'file_search',
                'vector_store_ids' => [$tools_config['fileSearch']['vectorStoreId']]
            ];
            
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

    public static function execute_output_node($node, $input_data, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
    
        WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Starting output node execution');
    
        global $wpdb;
        
        $output_content = array_reduce($input_data, function($carry, $input_node) {
            $content = '';
            if (isset($input_node['content'])) {
                // Handle array content
                if (is_array($input_node['content'])) {
                    $content = wp_json_encode($input_node['content']);
                } else {
                    $content = $input_node['content'];
                }
            }
            return $carry . $content . "\n\n";
        }, '');
        
        $output_content = trim($output_content);
        
        $output_type = isset($node['data']['outputType']) ? $node['data']['outputType'] : 'display';
        
        $result = array(
            'type' => 'output',
            'content' => $output_content,
            'status' => 'success',
            'message' => ''
        );
    
        // Check if delay is enabled
        if (isset($node['data']['delayEnabled']) && $node['data']['delayEnabled']) {
            $delay_time = WP_AI_Workflows_Utilities::calculate_delay_time($node['data']['delayValue'], $node['data']['delayUnit']);
            
            if ($delay_time === false) {
                WP_AI_Workflows_Utilities::debug_log("Failed to calculate delay time", "error", [
                    'node_id' => $node['id'],
                    'delay_value' => $node['data']['delayValue'],
                    'delay_unit' => $node['data']['delayUnit']
                ]);
                WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'error', 'Failed to schedule delayed output');
                return self::create_node_data('error', "Failed to schedule delayed output due to invalid delay settings");
            }
            
            // Schedule the output execution
            wp_schedule_single_event($delay_time, 'wp_ai_workflows_execute_delayed_output', [
                'node' => $node,
                'output_content' => $output_content,
                'execution_id' => $execution_id
            ]);

            WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'scheduled', "Output scheduled for execution at: " . get_date_from_gmt(gmdate('Y-m-d H:i:s', $delay_time), 'Y-m-d H:i:s'));
            return self::create_node_data('output', "Output scheduled for execution at: " . get_date_from_gmt(gmdate('Y-m-d H:i:s', $delay_time), 'Y-m-d H:i:s'));
        }

        // If no delay, continue with immediate execution
        switch ($output_type) {
            case 'save':
                $table_name = $wpdb->prefix . (isset($node['data']['selectedTable']) ? $node['data']['selectedTable'] : 'wp_ai_workflows_outputs');
                
                $insert_data = array(
                    'created_at' => current_time('mysql')
                );
        
                // Map input data to columns
                if (isset($node['data']['columns']) && is_array($node['data']['columns'])) {
                    foreach ($node['data']['columns'] as $column) {
                        $column_name = sanitize_key($column['name']);
                        if ($column_name !== 'id' && $column_name !== 'created_at') {
                            $mapping = $column['mapping'];
                            $mapped_value = self::replace_input_tags($mapping, $input_data);
                            
                            // Check if the mapped value is an array
                            if (is_array($mapped_value)) {
                                // Convert array to JSON string
                                $insert_data[$column_name] = wp_json_encode($mapped_value);
                            } else {
                                // Convert the value based on the column type
                                switch ($column['type']) {
                                    case 'number':
                                        $insert_data[$column_name] = floatval($mapped_value);
                                        break;
                                    case 'datetime':
                                        $insert_data[$column_name] = gmdate('Y-m-d H:i:s', strtotime($mapped_value));
                                        break;
                                    case 'text':
                                    default:
                                        $insert_data[$column_name] = $mapped_value;
                                        break;
                                }
                            }
                        }
                    }
                }
            
                $insert_result = $wpdb->insert($table_name, $insert_data);
            
                if ($insert_result === false) {
                    $result['status'] = 'error';
                    $result['message'] = 'Failed to save output to database: ' . $wpdb->last_error;
                    WP_AI_Workflows_Utilities::debug_log("Database insert error: " . $wpdb->last_error, "error");
                }
                break;
            
                
            case 'webhook':
                $webhook_url = isset($node['data']['webhookUrl']) ? $node['data']['webhookUrl'] : '';
                $webhook_keys = isset($node['data']['webhookKeys']) ? $node['data']['webhookKeys'] : [];
                
                if (!empty($webhook_url)) {
                    $webhook_data = self::process_webhook_data($webhook_keys, $input_data);
                    
                    $response = wp_remote_post($webhook_url, array(
                        'body' => wp_json_encode($webhook_data),
                        'headers' => array('Content-Type' => 'application/json'),
                        'timeout' => 15
                    ));
                    
                    if (is_wp_error($response)) {
                        $result['status'] = 'error';
                        $result['message'] = 'Webhook request failed: ' . $response->get_error_message();
                        WP_AI_Workflows_Utilities::debug_log("Webhook error: " . $response->get_error_message(), "error");
                    } else {
                        $response_code = wp_remote_retrieve_response_code($response);
                        if ($response_code < 200 || $response_code >= 300) {
                            $result['status'] = 'warning';
                            $result['message'] = "Webhook request received non-200 response: $response_code";
                            WP_AI_Workflows_Utilities::debug_log("Webhook non-200 response: $response_code", "warning");
                        }
                    }
                } else {
                    $result['status'] = 'error';
                    $result['message'] = 'Webhook URL is empty';
                    WP_AI_Workflows_Utilities::debug_log("Webhook URL is empty", "warning");
                }
                break;

            case 'html':
                // No additional processing needed for HTML output
                break;

            case 'display':
                // No additional processing needed for display output
                break;

                case 'googleSheets':
                    try {
                        $google_service = new WP_AI_Workflows_Google_Service();
                        $spreadsheet_id = $node['data']['selectedSpreadsheet'];
                        $sheet_id = $node['data']['selectedSheetTab'];
                        $column_mappings = $node['data']['columnMappings'];
                        
                        $values = array();
                        foreach ($column_mappings as $column => $mapping) {
                            $values[$column] = self::replace_input_tags($mapping, $input_data);
                        }
                        
                        $append_result = $google_service->append_to_sheet($spreadsheet_id, $sheet_id, $values);
                        
                        if (isset($append_result['updates'])) {
                            $result['status'] = 'success';
                            $result['message'] = 'Data appended to Google Sheet successfully';
                            WP_AI_Workflows_Utilities::debug_log("Data appended to Google Sheet", "debug", $append_result);
                        } else {
                            $result['status'] = 'error';
                            $result['message'] = 'Failed to append data to Google Sheet';
                            WP_AI_Workflows_Utilities::debug_log("Failed to append data to Google Sheet", "error", $append_result);
                        }
                    } catch (Exception $e) {
                        $result['status'] = 'error';
                        $result['message'] = 'Error appending to Google Sheet: ' . $e->getMessage();
                        WP_AI_Workflows_Utilities::debug_log("Exception while appending to Google Sheet", "error", [
                            'error_message' => $e->getMessage(),
                            'node_id' => $node['id']
                        ]);
                    }
                    break;

                    case 'googleDrive':
                        try {
                            $google_service = new WP_AI_Workflows_Google_Service();
                            $folder_id = $node['data']['selectedDriveFolder'];
                            
                            // Process the dynamic file name
                            $file_name = '';
                            if (!empty($node['data']['driveFileName'])) {
                                $file_name = self::replace_input_tags($node['data']['driveFileName'], $input_data);
                            }
                            $file_name = $file_name ?: 'output_' . time();
                            
                            $file_format = $node['data']['driveFileFormat'];
                            $sharing_level = $node['data']['sharingLevel'] ?? 'private';
                    
                            // Process the driveContent if available, otherwise fall back to input data
                            if (!empty($node['data']['driveContent'])) {
                                $content = self::replace_input_tags($node['data']['driveContent'], $input_data);
                            } else {
                                $content = '';
                                foreach ($input_data as $input_node) {
                                    if (isset($input_node['content'])) {
                                        $content .= (is_array($input_node['content']) ? 
                                            self::format_data_for_drive($input_node['content']) : 
                                            $input_node['content']) . "\n\n";
                                    }
                                }
                                $content = trim($content);
                            }
                    
                            $mime_type = self::get_mime_type($file_format);
                            $file_name .= '.' . $file_format;
                    
                            // Create document with processed content
                            $create_result = $google_service->create_drive_file(
                                $folder_id,
                                $file_name,
                                $content,
                                $mime_type,
                                $sharing_level
                            );
                            
                            WP_AI_Workflows_Utilities::debug_log("Drive API response", "debug", [
                                'create_result' => $create_result
                            ]);
                            
                            if (isset($create_result['id'])) {
                                $file_link = "https://drive.google.com/file/d/" . $create_result['id'] . "/view";
                                return [
                                    'type' => 'output',
                                    'content' => [
                                        'status' => 'success',
                                        'message' => 'File created in Google Drive successfully',
                                        'file_id' => $create_result['id'],
                                        'file_name' => $create_result['name'],
                                        'file_link' => $file_link,
                                        'sharing_level' => $sharing_level
                                    ]
                                ];
                            } else {
                                WP_AI_Workflows_Utilities::debug_log("Failed to create Drive file", "error", [
                                    'create_result' => $create_result
                                ]);
                                return [
                                    'type' => 'error',
                                    'content' => 'Failed to create file in Google Drive'
                                ];
                            }
                        } catch (Exception $e) {
                            WP_AI_Workflows_Utilities::debug_log("Drive error", "error", [
                                'error_message' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            return [
                                'type' => 'error',
                                'content' => 'Error creating file in Google Drive: ' . $e->getMessage()
                            ];
                        }
                        break;
                        
            default:
                $result['status'] = 'error';
                $result['message'] = 'Invalid output type';
                WP_AI_Workflows_Utilities::debug_log("Invalid output type: $output_type", "error");
                break;
                }
        
                // Save output regardless of the output type
                $saved_outputs = get_option('wp_ai_workflows_outputs', array());
                $saved_outputs[$node['id']] = array(
                    'data' => $output_content,
                    'timestamp' => current_time('mysql'),
                    'status' => $result['status'],
                    'message' => $result['message']
                    );
                    update_option('wp_ai_workflows_outputs', $saved_outputs);
                    WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Output node execution completed');
                    return $result;
            }

            private static function process_webhook_data($webhook_keys, $input_data) {
                $result = array();
                foreach ($webhook_keys as $webhook_key) {
                    $keys = explode('/', $webhook_key['key']);
                    $value = self::replace_input_tags($webhook_key['mapping'], $input_data);
                    self::set_nested_value($result, $keys, $value);
                }
                return $result;
            }
            
            private static function set_nested_value(&$array, $keys, $value) {
                $current = &$array;
                foreach ($keys as $key) {
                    if (is_numeric($key) && is_array($current) && !isset($current[$key])) {
                        $current = &$current[];
                    } else {
                        if (!isset($current[$key]) || !is_array($current[$key])) {
                            $current[$key] = array();
                        }
                        $current = &$current[$key];
                    }
                }
                $current = $value;
            }
        
            private static function get_mime_type($format) {
                switch ($format) {
                    case 'txt':
                        return 'text/plain';
                    case 'docx':
                        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                    case 'csv':
                        return 'text/csv';
                    case 'pdf':
                        return 'application/pdf';
                    default:
                        return 'text/plain';
                }
            }

            private static function format_data_for_drive($data, $depth = 0, $indent = '') {
                WP_AI_Workflows_Utilities::debug_log("format_data_for_drive called", "debug", [
                    'data_type' => gettype($data),
                    'depth' => $depth,
                    'data_preview' => is_array($data) ? 'array(' . count($data) . ' items)' : substr(strval($data), 0, 100)
                ]);
            
                if (!is_array($data)) {
                    return strval($data);
                }
            
                $output = '';
                $new_indent = $indent . '    ';
            
                foreach ($data as $key => $value) {
                    $output .= $indent;
                    
                    // Handle numeric keys differently
                    if (!is_numeric($key)) {
                        $output .= $key . ': ';
                    }
            
                    if (is_array($value)) {
                        $output .= "\n" . self::format_data_for_drive($value, $depth + 1, $new_indent);
                    } else {
                        $output .= strval($value) . "\n";
                    }
                }
            
                WP_AI_Workflows_Utilities::debug_log("format_data_for_drive result", "debug", [
                    'depth' => $depth,
                    'output_preview' => substr($output, 0, 100)
                ]);
            
                return $output;
            }

            
        public static function execute_post_node($node, $input_data, $execution_id) {
            WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
        
            // Prepare base post data
            $post_data = [
                'post_type' => isset($node['data']['selectedPostType']) ? $node['data']['selectedPostType'] : 'post',
                'post_status' => isset($node['data']['postStatus']) ? $node['data']['postStatus'] : 'publish',
            ];
        
            $acf_fields = [];
        
            // Handle field mappings
            if (isset($node['data']['fieldMappings'])) {
                foreach ($node['data']['fieldMappings'] as $field => $value) {
                    $replaced_value = self::replace_input_tags($value, $input_data);
                    if (strpos($field, 'acf_') === 0) {
                        $acf_fields[substr($field, 4)] = $replaced_value;
                    } else {
                        $post_data[$field] = $replaced_value;
                    }
                }
            }
        
            // Handle author assignment
            if (!empty($node['data']['selectedAuthor'])) {
                $author_value = self::replace_input_tags($node['data']['selectedAuthor'], $input_data);
                
                WP_AI_Workflows_Utilities::debug_log("Processing author assignment", "debug", [
                    "author_value" => $author_value,
                    "original_value" => $node['data']['selectedAuthor']
                ]);
                
                // Check if it's a numeric user ID
                if (is_numeric($author_value)) {
                    $user = get_user_by('id', intval($author_value));
                    if ($user && user_can($user, 'edit_posts')) {
                        $post_data['post_author'] = intval($author_value);
                        WP_AI_Workflows_Utilities::debug_log("Author set by ID", "info", [
                            "user_id" => intval($author_value),
                            "user_name" => $user->display_name
                        ]);
                    } else {
                        WP_AI_Workflows_Utilities::debug_log("Invalid author ID or user lacks permissions", "warning", [
                            "author_value" => $author_value
                        ]);
                    }
                } else {
                    // Try to find user by username or email
                    $user = get_user_by('login', $author_value);
                    if (!$user) {
                        $user = get_user_by('email', $author_value);
                    }
                    if ($user && user_can($user, 'edit_posts')) {
                        $post_data['post_author'] = $user->ID;
                        WP_AI_Workflows_Utilities::debug_log("Author set by username/email", "info", [
                            "user_id" => $user->ID,
                            "user_name" => $user->display_name,
                            "lookup_value" => $author_value
                        ]);
                    } else {
                        WP_AI_Workflows_Utilities::debug_log("Author not found or lacks permissions", "warning", [
                            "lookup_value" => $author_value
                        ]);
                    }
                }
            }
        
            // Set default title if not provided
            if (!isset($post_data['post_title'])) {
                $post_data['post_title'] = 'Auto-generated post ' . current_time('mysql');
            }
        
            // Set content from input nodes if not provided
            if (!isset($post_data['post_content'])) {
                $post_data['post_content'] = '';
                foreach ($input_data as $input) {
                    if (isset($input['content'])) {
                        $post_data['post_content'] .= $input['content'] . "\n\n";
                    }
                }
                $post_data['post_content'] = trim($post_data['post_content']);
            }
        
            // Handle scheduled posts
            if ($post_data['post_status'] === 'future' && isset($node['data']['scheduledDate'])) {
                $post_data['post_date'] = $node['data']['scheduledDate'];
                $post_data['post_date_gmt'] = get_gmt_from_date($node['data']['scheduledDate']);
            } elseif ($post_data['post_status'] === 'future') {
                $post_data['post_status'] = 'publish';
            }
        
            WP_AI_Workflows_Utilities::debug_log("Post data prepared", "debug", [
                "post_data" => $post_data,
                "acf_fields" => $acf_fields
            ]);
        
            // Insert post
            $post_id = wp_insert_post($post_data);
        
            if (is_wp_error($post_id)) {
                WP_AI_Workflows_Utilities::debug_log("Error in post node execution", "error", ["error" => $post_id->get_error_message()]);
                return self::create_node_data('error', $post_id->get_error_message());
            }
        
            // Handle categories assignment
            if (!empty($node['data']['selectedCategories']) && !is_wp_error($post_id)) {
                $categories_value = self::replace_input_tags($node['data']['selectedCategories'], $input_data);
                
                WP_AI_Workflows_Utilities::debug_log("Processing categories", "debug", [
                    "categories_value" => $categories_value,
                    "post_type" => $post_data['post_type'],
                    "original_value" => $node['data']['selectedCategories']
                ]);
                
                // Determine the main taxonomy for this post type
                $main_taxonomy = 'category'; // Default for posts
                if ($post_data['post_type'] === 'product') {
                    $main_taxonomy = 'product_cat';
                } else {
                    // Get the first hierarchical taxonomy for other post types
                    $taxonomies = get_object_taxonomies($post_data['post_type'], 'objects');
                    foreach ($taxonomies as $taxonomy) {
                        if ($taxonomy->hierarchical) {
                            $main_taxonomy = $taxonomy->name;
                            break;
                        }
                    }
                }
                
                WP_AI_Workflows_Utilities::debug_log("Determined taxonomy", "debug", [
                    "taxonomy" => $main_taxonomy,
                    "post_type" => $post_data['post_type']
                ]);
                
                // Process categories - handle both arrays and comma-separated strings
                $category_ids = [];
                
                if (is_array($categories_value)) {
                    // Handle array of category IDs/names
                    foreach ($categories_value as $item) {
                        if (is_numeric($item)) {
                            $category_ids[] = intval($item);
                        } else {
                            // Process as category name
                            $item = trim($item);
                            if (!empty($item)) {
                                $term = get_term_by('name', $item, $main_taxonomy);
                                if (!$term) {
                                    $term = get_term_by('slug', $item, $main_taxonomy);
                                }
                                
                                if ($term) {
                                    $category_ids[] = $term->term_id;
                                } else {
                                    // Create new category if it doesn't exist
                                    $new_term = wp_insert_term($item, $main_taxonomy);
                                    if (!is_wp_error($new_term)) {
                                        $category_ids[] = $new_term['term_id'];
                                        WP_AI_Workflows_Utilities::debug_log("Created new category from array", "info", [
                                            "name" => $item,
                                            "taxonomy" => $main_taxonomy,
                                            "term_id" => $new_term['term_id']
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                } else {
                    // Handle comma-separated string or single value
                    $category_items = is_string($categories_value) ? explode(',', $categories_value) : [$categories_value];
                    
                    foreach ($category_items as $item) {
                        $item = trim($item);
                        if (empty($item)) continue;
                        
                        if (is_numeric($item)) {
                            // It's a category ID
                            $category_ids[] = intval($item);
                        } else {
                            // It's a category name - try to find or create it
                            $term = get_term_by('name', $item, $main_taxonomy);
                            if (!$term) {
                                // Try by slug
                                $term = get_term_by('slug', $item, $main_taxonomy);
                            }
                            
                            if ($term) {
                                $category_ids[] = $term->term_id;
                                WP_AI_Workflows_Utilities::debug_log("Found existing category", "debug", [
                                    "name" => $item,
                                    "term_id" => $term->term_id,
                                    "taxonomy" => $main_taxonomy
                                ]);
                            } else {
                                // Create new category if it doesn't exist
                                $new_term = wp_insert_term($item, $main_taxonomy);
                                if (!is_wp_error($new_term)) {
                                    $category_ids[] = $new_term['term_id'];
                                    WP_AI_Workflows_Utilities::debug_log("Created new category from string", "info", [
                                        "name" => $item,
                                        "taxonomy" => $main_taxonomy,
                                        "term_id" => $new_term['term_id']
                                    ]);
                                } else {
                                    WP_AI_Workflows_Utilities::debug_log("Failed to create category", "error", [
                                        "name" => $item,
                                        "taxonomy" => $main_taxonomy,
                                        "error" => $new_term->get_error_message()
                                    ]);
                                }
                            }
                        }
                    }
                }
                
                // Remove any invalid category IDs
                $valid_category_ids = array_filter($category_ids, function($id) use ($main_taxonomy) {
                    return term_exists($id, $main_taxonomy);
                });
                
                if (!empty($valid_category_ids)) {
                    $result = wp_set_post_terms($post_id, $valid_category_ids, $main_taxonomy);
                    if (!is_wp_error($result)) {
                        WP_AI_Workflows_Utilities::debug_log("Categories assigned successfully", "info", [
                            "post_id" => $post_id,
                            "taxonomy" => $main_taxonomy,
                            "category_ids" => $valid_category_ids,
                            "total_assigned" => count($valid_category_ids)
                        ]);
                    } else {
                        WP_AI_Workflows_Utilities::debug_log("Error assigning categories", "error", [
                            "error" => $result->get_error_message(),
                            "post_id" => $post_id,
                            "taxonomy" => $main_taxonomy,
                            "category_ids" => $valid_category_ids
                        ]);
                    }
                } else {
                    WP_AI_Workflows_Utilities::debug_log("No valid categories to assign", "warning", [
                        "post_id" => $post_id,
                        "original_categories" => $categories_value,
                        "processed_ids" => $category_ids
                    ]);
                }
            }
        
            // Handle featured image
            if (!empty($node['data']['featuredImage'])) {
                $featured_image = $node['data']['featuredImage'];
                $attachment_id = null;
        
                // Process depending on source
                if ($featured_image['source'] === 'upload' && !empty($featured_image['id'])) {
                    // For uploaded images, use the existing attachment ID
                    $attachment_id = $featured_image['id'];
                } elseif (!empty($featured_image['url'])) {
                    // For external URLs or node input, process the URL
                    $url = self::replace_input_tags($featured_image['url'], $input_data);
                    if ($url) {
                        $attachment_id = self::create_attachment_from_url($url, $post_id);
                    }
                }
        
                if (!is_wp_error($attachment_id) && $attachment_id) {
                    set_post_thumbnail($post_id, $attachment_id);
                    WP_AI_Workflows_Utilities::debug_log("Featured image set", "debug", [
                        "attachment_id" => $attachment_id,
                        "source" => $featured_image['source']
                    ]);
                }
            }
        
            // Handle product gallery images for WooCommerce
            if ($post_data['post_type'] === 'product' && !empty($node['data']['productImages'])) {
                $gallery_ids = [];
                
                foreach ($node['data']['productImages'] as $image) {
                    $attachment_id = null;
        
                    if ($image['source'] === 'upload' && !empty($image['id'])) {
                        // For uploaded images, use the existing attachment ID
                        $attachment_id = $image['id'];
                    } elseif (!empty($image['url'])) {
                        // For external URLs or node input, process the URL
                        $url = self::replace_input_tags($image['url'], $input_data);
                        if ($url) {
                            $attachment_id = self::create_attachment_from_url($url, $post_id);
                        }
                    }
        
                    if (!is_wp_error($attachment_id) && $attachment_id) {
                        $gallery_ids[] = $attachment_id;
                    }
                }
        
                if (!empty($gallery_ids)) {
                    update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
                    WP_AI_Workflows_Utilities::debug_log("Product gallery updated", "debug", [
                        "gallery_ids" => $gallery_ids
                    ]);
                }
            }
        
            // Handle ACF fields
            if (!empty($acf_fields) && function_exists('update_field')) {
                foreach ($acf_fields as $field_name => $field_value) {
                    update_field($field_name, $field_value, $post_id);
                }
                WP_AI_Workflows_Utilities::debug_log("ACF fields updated", "debug", [
                    "acf_fields" => $acf_fields
                ]);
            }
        
            $result = array(
                'message' => "Post created with ID: {$post_id}",
                'post_id' => $post_id,
                'post_url' => get_permalink($post_id)
            );
        
            WP_AI_Workflows_Utilities::debug_log("Post node execution complete", "debug", [
                "result" => $result
            ]);
            WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Executed Post node');
            
            return self::create_node_data('post', $result);
        }
            
            
            public static function get_post_types() {
                WP_AI_Workflows_Utilities::debug_log("Fetching post types", "debug");
                $post_types = get_post_types(array('public' => true), 'objects');
                $formatted_types = array();
                foreach ($post_types as $post_type) {
                    $formatted_types[] = array(
                        'name' => $post_type->name,
                        'label' => $post_type->label
                    );
                }
                WP_AI_Workflows_Utilities::debug_log("Post types fetched", "debug", ['post_types' => $formatted_types]);
                return new WP_REST_Response($formatted_types, 200);
            }
            
            public static function get_post_fields($request) {
                $post_type = $request->get_param('post_type');
                $post_type_object = get_post_type_object($post_type);
                
                if (!$post_type_object) {
                    return new WP_Error('invalid_post_type', 'Invalid post type', array('status' => 400));
                }
            
                $fields = array();
            
                // Add default WordPress fields
                $default_fields = array(
                    'post_title' => 'Title',
                    'post_content' => 'Content',
                    'post_excerpt' => 'Excerpt'
                );
            
                foreach ($default_fields as $name => $label) {
                    $fields[] = array('name' => $name, 'label' => $label);
                }
            
                // Get registered meta keys
                $registered_meta = get_registered_meta_keys($post_type);
                foreach ($registered_meta as $meta_key => $meta_args) {
                    $fields[] = array('name' => $meta_key, 'label' => ucfirst(str_replace('_', ' ', $meta_key)));
                }
            
                // Check for WooCommerce product fields
                if ($post_type === 'product' && class_exists('WC_Product')) {
                    $wc_fields = array(
                        '_regular_price' => 'Regular Price',
                        '_sale_price' => 'Sale Price',
                        '_sku' => 'SKU',
                        '_stock' => 'Stock Quantity',
                        // Add more WooCommerce fields as needed
                    );
                    foreach ($wc_fields as $name => $label) {
                        $fields[] = array('name' => $name, 'label' => $label);
                    }
                }
            
                // Check for ACF fields
                if (function_exists('acf_get_field_groups')) {
                    $field_groups = acf_get_field_groups(array('post_type' => $post_type));
                    foreach ($field_groups as $field_group) {
                        $acf_fields = acf_get_fields($field_group);
                        foreach ($acf_fields as $field) {
                            $fields[] = array('name' => 'acf_' . $field['name'], 'label' => $field['label'] . ' (ACF)');
                        }
                    }
                }
            
                // Allow other plugins to add their custom fields
                $fields = apply_filters('wp_ai_workflows_post_fields', $fields, $post_type);
            
                // Remove any duplicate fields
                $unique_fields = array_unique($fields, SORT_REGULAR);
                return new WP_REST_Response($unique_fields, 200);
}

public static function execute_research_node($node, $input_data, $execution_id) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);

    // Get node data with defaults
    $node_data = $node['data'] ?? [];
    
    // Basic settings
    $content = $node_data['content'] ?? "Default research query";
    $model = $node_data['model'] ?? "sonar";
    $temperature = floatval($node_data['temperature'] ?? 1.0);

    // Build additional parameters - ONLY include one type of penalty
    $additional_params = [
        'searchContext' => $node_data['searchContext'] ?? true,
        'citations' => $node_data['citations'] ?? true,
        'searchDomainFilters' => $node_data['searchDomainFilters'] ?? [],
        'search_recency_filter' => $node_data['search_recency_filter'] ?? 'any',
        'citationQuality' => $node_data['citationQuality'] ?? 'standard',
        'top_p' => floatval($node_data['top_p'] ?? 1)
    ];

    // Only add one type of penalty - prefer frequency_penalty if both are set
    if (isset($node_data['frequency_penalty']) && $node_data['frequency_penalty'] != 0) {
        $additional_params['frequency_penalty'] = floatval($node_data['frequency_penalty']);
    } elseif (isset($node_data['presence_penalty']) && $node_data['presence_penalty'] != 0) {
        $additional_params['presence_penalty'] = floatval($node_data['presence_penalty']);
    }

    // Replace input tags in the prompt
    $prompt = self::replace_input_tags($content, $input_data);

    // Call Perplexity API
    $response = WP_AI_Workflows_Utilities::call_perplexity_api(
        $prompt, 
        $model, 
        $temperature,
        $additional_params
    );

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        WP_AI_Workflows_Utilities::debug_log("Error in Research node response", "error", ["error_message" => $error_message]);
        return self::create_node_data('error', "Error: " . $error_message);
    }

    try {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception("Unexpected API response structure");
        }

        // Track costs if usage data is available
        if (isset($response['usage'])) {
            try {
                $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
                $cost = $cost_manager->calculate_node_cost(
                    $execution_id,
                    $node['id'],
                    'perplexity',
                    $model,
                    $response['usage']['prompt_tokens'] ?? 0,
                    $response['usage']['completion_tokens'] ?? 0
                );
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error calculating cost", "error", [
                    "error" => $e->getMessage(),
                    "node_id" => $node['id']
                ]);
            }
        }

        $output = [
            'content' => $response['choices'][0]['message']['content'],
            'citations' => $response['citations'] ?? []
        ];
        
        if (isset($response['search_context'])) {
            $output['search_context'] = $response['search_context'];
        }

        WP_AI_Workflows_Utilities::update_execution_status(
            $execution_id, 
            'processing', 
            'Executed Research node successfully',
            $node['id']
        );

        return self::create_node_data('research', $output);

    } catch (Exception $e) {
        WP_AI_Workflows_Utilities::debug_log("Error processing research response", "error", [
            "error" => $e->getMessage(),
            "response" => $response
        ]);
        return self::create_node_data('error', "Error processing research response: " . $e->getMessage());
    }
}


public static function execute_unsplash_node($node, $input_data, $execution_id) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);

    $api_key = WP_AI_Workflows_Utilities::get_unsplash_api_key();
    if (empty($api_key)) {
        WP_AI_Workflows_Utilities::debug_log("Unsplash API key missing", "error");
        return self::create_node_data('error', 'Unsplash API key is not set');
    }

    // Get node settings
    $search_term = isset($node['data']['searchTerm']) ? $node['data']['searchTerm'] : '';
    $image_size = isset($node['data']['imageSize']) ? $node['data']['imageSize'] : 'regular';
    $orientation = isset($node['data']['orientation']) ? $node['data']['orientation'] : 'all';
    $random_result = isset($node['data']['randomResult']) && $node['data']['randomResult'];

    // Replace any input tags in the search term
    $search_term = self::replace_input_tags($search_term, $input_data);

    if (empty($search_term)) {
        WP_AI_Workflows_Utilities::debug_log("Empty search term", "error");
        return self::create_node_data('error', 'Search term is required');
    }

    // Build the API URL
    $query_params = array(
        'query' => $search_term,
        'per_page' => $random_result ? 10 : 1,
    );

    if ($orientation !== 'all') {
        $query_params['orientation'] = $orientation;
    }

    $url = add_query_arg($query_params, 'https://api.unsplash.com/search/photos');

    // Make the API request
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Client-ID ' . $api_key,
            'Accept-Version' => 'v1'
        )
    ));

    if (is_wp_error($response)) {
        WP_AI_Workflows_Utilities::debug_log("Unsplash API request failed", "error", [
            'error' => $response->get_error_message()
        ]);
        return self::create_node_data('error', 'Failed to fetch image: ' . $response->get_error_message());
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 429) {
        WP_AI_Workflows_Utilities::debug_log("Unsplash rate limit exceeded", "error");
        return self::create_node_data('error', 'Rate limit exceeded');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($response_code !== 200 || empty($body['results'])) {
        WP_AI_Workflows_Utilities::debug_log("Unsplash API error", "error", [
            'response_code' => $response_code,
            'body' => $body
        ]);
        return self::create_node_data('error', 'Failed to fetch image from Unsplash');
    }

    // Get random result if enabled, otherwise get first result
    $result = $random_result ? 
        $body['results'][array_rand($body['results'])] : 
        $body['results'][0];

    // Get the requested size URL
    $image_url = isset($result['urls'][$image_size]) ? 
        $result['urls'][$image_size] : 
        $result['urls']['regular']; // fallback to regular size

    WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Fetched image from Unsplash');

    // Return just the URL
    return self::create_node_data('unsplash', $image_url);
}

public static function execute_chat_node($node, $input_data, $execution_id) {
    WP_AI_Workflows_Utilities::debug_log("Executing chat node", "debug", [
        'node' => $node,
        'input_data' => $input_data
    ]);

    // Extract node settings
    $settings = $node['data'];
    $model = $settings['model'] ?? 'anthropic/claude-3-opus';
    $system_prompt = $settings['systemPrompt'] ?? '';

    // Replace input tags in system prompt using the replace_input_tags method
    $system_prompt = self::replace_input_tags($system_prompt, $input_data);

    // Store the processed system prompt back in the settings
    $settings['systemPrompt'] = $system_prompt;

    // Update execution status
    WP_AI_Workflows_Utilities::update_execution_status(
        $execution_id, 
        'processing', 
        'Configuring chat node', 
        $node['id']
    );

    WP_AI_Workflows_Utilities::debug_log("Chat node processed", "debug", [
        'system_prompt' => $system_prompt,
        'model' => $model
    ]);

    // Return the processed node data
    return array(
        'type' => 'chat',
        'content' => array(
            'model' => $model,
            'systemPrompt' => $system_prompt,
            'settings' => $settings
        )
    );
}


public static function execute_rss_node($node, $input_data, $execution_id) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, [
        'node_id' => $node['id'],
        'execution_id' => $execution_id
    ]);

    $settings = $node['data']['rssSettings'];
    $feed_url = $settings['feedUrl'];
    
    if (empty($feed_url)) {
        return self::create_node_data('error', 'RSS feed URL is required');
    }

    try {
        include_once(ABSPATH . WPINC . '/feed.php');
        $rss = fetch_feed($feed_url);
        
        if (is_wp_error($rss)) {
            throw new Exception($rss->get_error_message());
        }

        $maxitems = $settings['maxItems'] ?? 10;
        $items = $rss->get_items(0, $maxitems);
        
        $filtered_items = array_filter($items, function($item) use ($settings) {
            if (!empty($settings['filters']['title']) && 
                stripos($item->get_title(), $settings['filters']['title']) === false) {
                return false;
            }
            
            if (!empty($settings['filters']['content'])) {
                $content = $settings['includeContent'] ? 
                    $item->get_content() : $item->get_description();
                if (stripos($content, $settings['filters']['content']) === false) {
                    return false;
                }
            }
            
            return true;
        });

        $formatted_items = array_map(function($item) use ($settings) {
            $data = [
                'title' => $item->get_title() ?: '',
                'link' => $item->get_permalink() ?: '',
                'description' => strip_tags($item->get_description() ?: ''),
                'pubDate' => $item->get_date('Y-m-d H:i:s') ?: '',
                'author' => $item->get_author() ? $item->get_author()->get_name() : '',
            ];

            // Get enclosure URL (for podcasts/media)
            $enclosure = $item->get_enclosure();
            if ($enclosure) {
                $data['media_url'] = $enclosure->get_link();
                $data['media_type'] = $enclosure->get_type();
                $data['media_length'] = $enclosure->get_length();
            }

            // Get categories if they exist
            $categories = $item->get_categories();
            if ($categories) {
                $data['categories'] = array_map(function($cat) {
                    return $cat->get_label();
                }, $categories);
            } else {
                $data['categories'] = [];
            }

            // Get content if enabled
            if ($settings['includeContent']) {
                $data['content'] = $item->get_content();
            }

            // Get additional feed-specific data
            $additional = $item->get_item_tags('', '');
            if ($additional) {
                foreach ($additional as $key => $value) {
                    if (!isset($data[$key])) {
                        $data[$key] = $value;
                    }
                }
            }

            // Clean up any HTML in text fields if not specifically requesting HTML
            if (!$settings['includeContent']) {
                $data['description'] = strip_tags($data['description']);
                if (isset($data['content'])) {
                    $data['content'] = strip_tags($data['content']);
                }
            }

            // Ensure all array values are properly stringified
            array_walk_recursive($data, function(&$value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                } elseif (!is_string($value) && !is_numeric($value)) {
                    $value = strval($value);
                }
            });

            return $data;
        }, $filtered_items);

        $output_data = [
            'items' => $formatted_items,
            'latest' => !empty($formatted_items) ? reset($formatted_items) : null,
            'feed_title' => $rss->get_title(),
            'feed_description' => $rss->get_description(),
            'feed_link' => $rss->get_permalink(),
            'total_items' => count($formatted_items)
        ];

        WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'RSS feed processed');
        return self::create_node_data('rss', $output_data);

    } catch (Exception $e) {
        WP_AI_Workflows_Utilities::debug_log("RSS feed error", "error", [
            'error' => $e->getMessage()
        ]);
        return self::create_node_data('error', 'RSS feed error: ' . $e->getMessage());
    }
}


/**
 * Helper function to add directory contents to a zip file
 */
private static function add_directory_to_zip($zip, $dir, $base_in_zip = '') {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = $base_in_zip . substr($filePath, strlen($dir) + 1);
            
            $zip->addFile($filePath, $relativePath);
        }
    }
}

/**
 * Helper function to remove a directory and its contents
 */
private static function remove_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object != "." && $object != "..") {
            if (is_dir($dir . "/" . $object)) {
                self::remove_directory($dir . "/" . $object);
            } else {
                unlink($dir . "/" . $object);
            }
        }
    }
    
    rmdir($dir);
}

/**
 * Save file to WordPress media library
 * 
 * @param string $file_path Path to the file
 * @param string $file_name Name of the file
 * @param string $file_format File format extension
 * @return int|false Attachment ID if successful, false on failure
 */
private static function save_file_to_media_library($file_path, $file_name, $file_format) {
    // Get file mime type
    $mime_type = 'text/plain';
    if ($file_format === 'html') {
        $mime_type = 'text/html';
    } else if ($file_format === 'docx') {
        $mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    
    // Prepare attachment data
    $attachment = array(
        'post_mime_type' => $mime_type,
        'post_title' => $file_name,
        'post_content' => '',
        'post_status' => 'inherit'
    );
    
    // Insert the attachment
    $attachment_id = wp_insert_attachment($attachment, $file_path);
    
    if (!is_wp_error($attachment_id)) {
        // Generate metadata for the attachment
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        return $attachment_id;
    }
    
    return false;
}


private static function replace_input_tags_recursive($data, $input_data) {
    if (is_string($data)) {
        return self::replace_input_tags($data, $input_data);
    }
    
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = self::replace_input_tags_recursive($value, $input_data);
        }
    }
    
    return $data;
}

// Helper methods

private static function create_attachment_from_url($url, $parent_post_id = 0) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Clean the URL and remove query parameters
    $clean_url = strtok($url, '?');
    
    // Download file to temp dir
    $temp_file = download_url($url);

    if (is_wp_error($temp_file)) {
        WP_AI_Workflows_Utilities::debug_log("Error downloading file", "error", [
            "error" => $temp_file->get_error_message()
        ]);
        return $temp_file;
    }

    // Get mime type of the downloaded file
    $mime_type = mime_content_type($temp_file);
    
    // Ensure we have a valid extension based on mime type
    $ext = self::get_file_extension_from_mime($mime_type);
    
    // Generate a filename with proper extension
    $filename = sanitize_file_name(
        pathinfo($clean_url, PATHINFO_FILENAME) . '.' . $ext
    );

    // Prepare file array
    $file_array = array(
        'name' => $filename,
        'tmp_name' => $temp_file,
        'error' => 0,
        'size' => filesize($temp_file),
        'type' => $mime_type
    );

    // Disable file type checking temporarily
    add_filter('upload_mimes', array(__CLASS__, 'allow_image_types'), 10, 1);
    add_filter('upload_dir', array(__CLASS__, 'set_upload_dir'), 10, 1);

    // Add the file to media library
    $attachment_id = media_handle_sideload($file_array, $parent_post_id);

    // Remove our temporary filters
    remove_filter('upload_mimes', array(__CLASS__, 'allow_image_types'));
    remove_filter('upload_dir', array(__CLASS__, 'set_upload_dir'));

    // Clean up temp file
    @unlink($temp_file);

    if (is_wp_error($attachment_id)) {
        WP_AI_Workflows_Utilities::debug_log("Error creating attachment", "error", [
            "error" => $attachment_id->get_error_message()
        ]);
        return null;
    }

    return $attachment_id;
}

public static function allow_attachment_types($mimes) {
    // Common document types
    $mimes['pdf'] = 'application/pdf';
    $mimes['doc|docx'] = 'application/msword';
    $mimes['xls|xlsx'] = 'application/vnd.ms-excel';
    $mimes['ppt|pptx'] = 'application/vnd.ms-powerpoint';
    
    // Archive types
    $mimes['zip'] = 'application/zip';
    $mimes['rar'] = 'application/x-rar-compressed';
    
    // Text types
    $mimes['txt'] = 'text/plain';
    $mimes['csv'] = 'text/csv';
    
    // Image types 
    $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
    $mimes['gif'] = 'image/gif';
    $mimes['png'] = 'image/png';
    $mimes['webp'] = 'image/webp';
    
    return $mimes;
}

// Helper function to get file extension from mime type
private static function get_file_extension_from_mime($mime_type) {
    $mime_map = [
        // Images
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        
        // Documents
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        
        // Archives
        'application/zip' => 'zip',
        'application/x-rar-compressed' => 'rar',
        
        // Text
        'text/plain' => 'txt',
        'text/csv' => 'csv'
    ];

    return isset($mime_map[$mime_type]) ? $mime_map[$mime_type] : 'bin';
}

// Filter to allow image types
public static function allow_image_types($mimes) {
    // Ensure common image types are allowed
    $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
    $mimes['gif'] = 'image/gif';
    $mimes['png'] = 'image/png';
    $mimes['webp'] = 'image/webp';
    
    return $mimes;
}

// Filter to set upload directory
public static function set_upload_dir($upload) {
    // Ensure the upload directory exists and is writable
    if (!file_exists($upload['path'])) {
        wp_mkdir_p($upload['path']);
    }
    return $upload;
}

private static function get_node_input_data($node_id, $edges, $node_data) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node_id' => $node_id]);
    
    $input_data = array();
    foreach ($edges as $edge) {
        if ($edge['target'] == $node_id) {
            $source_id = $edge['source'];
            if (isset($node_data[$source_id])) {
                $input_data[$source_id] = $node_data[$source_id];
            }
        }
    }
    return $input_data;
}

private static function process_dynamic_variables($content) {
    if (empty($content) || !is_string($content)) {
        return $content;
    }

    $patterns = array(
        // Existing Date/Time patterns...
        '{{current_date}}' => current_time('Y-m-d'),
        '{{current_time}}' => current_time('H:i:s'),
        '{{current_datetime}}' => current_time('Y-m-d H:i:s'),
        '{{current_timestamp}}' => current_time('timestamp'),
        '{{yesterday}}' => date('Y-m-d', strtotime('-1 day')),
        '{{tomorrow}}' => date('Y-m-d', strtotime('+1 day')),
        '{{current_month}}' => current_time('F'),
        '{{current_year}}' => current_time('Y'),
        '{{current_day}}' => current_time('l'),

        // Existing WordPress patterns...
        '{{site_name}}' => get_bloginfo('name'),
        '{{site_url}}' => get_site_url(),
        '{{admin_email}}' => get_bloginfo('admin_email'),
        '{{current_user}}' => wp_get_current_user()->user_login ?? '',
        '{{current_user_email}}' => wp_get_current_user()->user_email ?? '',
        '{{current_user_id}}' => get_current_user_id(),

        // New Post/Page Variables
        '{{post_id}}' => get_the_ID(),
        '{{post_title}}' => get_the_title(),
        '{{post_excerpt}}' => get_the_excerpt(),
        '{{post_content}}' => get_post_field('post_content', get_the_ID()),
        '{{post_author}}' => get_the_author(),
        '{{post_date}}' => get_the_date(),
        '{{post_modified_date}}' => get_the_modified_date(),
        '{{post_type}}' => get_post_type(),
        '{{post_status}}' => get_post_status(),
        '{{post_url}}' => get_permalink(),
        '{{post_categories}}' => strip_tags(get_the_category_list(', ')),
        '{{post_tags}}' => strip_tags(get_the_tag_list('', ', ', '')),

        // System patterns...
        '{{php_version}}' => phpversion(),
        '{{wp_version}}' => get_bloginfo('version'),
        '{{server_ip}}' => $_SERVER['SERVER_ADDR'] ?? '',
        '{{client_ip}}' => $_SERVER['REMOTE_ADDR'] ?? ''
    );

    // WooCommerce-specific patterns - only add if WooCommerce is active
    if (class_exists('WooCommerce')) {
        global $product;

        // Get current product if we're on a product page
        if (is_product() && $product) {
            $wc_patterns = array(
                '{{product_id}}' => $product->get_id(),
                '{{product_name}}' => $product->get_name(),
                '{{product_price}}' => $product->get_price(),
                '{{product_regular_price}}' => $product->get_regular_price(),
                '{{product_sale_price}}' => $product->get_sale_price(),
                '{{product_sku}}' => $product->get_sku(),
                '{{product_stock}}' => $product->get_stock_quantity(),
                '{{product_stock_status}}' => $product->get_stock_status(),
                '{{product_category}}' => strip_tags(wc_get_product_category_list($product->get_id())),
                '{{product_short_description}}' => $product->get_short_description(),
                '{{product_type}}' => $product->get_type(),
                '{{product_url}}' => get_permalink($product->get_id()),
                '{{product_rating}}' => $product->get_average_rating(),
                '{{product_review_count}}' => $product->get_review_count(),
            );
            $patterns = array_merge($patterns, $wc_patterns);

            // For variable products
            if ($product->is_type('variable')) {
                $patterns['{{product_min_price}}'] = $product->get_variation_price('min');
                $patterns['{{product_max_price}}'] = $product->get_variation_price('max');
            }
        }

        // Cart information
        if (WC()->cart) {
            $cart_patterns = array(
                '{{cart_total}}' => WC()->cart->get_total(),
                '{{cart_subtotal}}' => WC()->cart->get_subtotal(),
                '{{cart_item_count}}' => WC()->cart->get_cart_contents_count(),
                '{{cart_url}}' => wc_get_cart_url(),
                '{{checkout_url}}' => wc_get_checkout_url(),
            );
            $patterns = array_merge($patterns, $cart_patterns);
        }
    }

    // Handle random generators
    if (strpos($content, '{{random_number}}') !== false) {
        $patterns['{{random_number}}'] = mt_rand(0, 100);
    }
    if (strpos($content, '{{random_number_1000}}') !== false) {
        $patterns['{{random_number_1000}}'] = mt_rand(0, 1000);
    }
    if (strpos($content, '{{random_string}}') !== false) {
        $patterns['{{random_string}}'] = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    }
    if (strpos($content, '{{unique_id}}') !== false) {
        $patterns['{{unique_id}}'] = uniqid();
    }

    return str_replace(array_keys($patterns), array_values($patterns), $content);
}

private static function replace_input_tags($content, $input_data) {
    $content = self::process_dynamic_variables($content);
    
    // Handle input from any node type using node ID
    $content = preg_replace_callback('/\[Input from ([\w-]+)\]/', function($matches) use ($input_data) {
        $node_id = $matches[1];
        if (isset($input_data[$node_id])) {
            if ($input_data[$node_id]['type'] === 'condition') {
                // For condition nodes, use the input data from the previous node
                $prev_node_data = reset($input_data[$node_id]['input_data']);
                return is_array($prev_node_data['content']) 
                    ? wp_json_encode($prev_node_data['content']) 
                    : strval($prev_node_data['content']);
            } else if ($input_data[$node_id]['type'] === 'chat' && isset($input_data[$node_id]['content']['action_params'])) {
                // For chat nodes with action parameters, return those parameters
                return is_array($input_data[$node_id]['content']['action_params']) 
                    ? wp_json_encode($input_data[$node_id]['content']['action_params']) 
                    : strval($input_data[$node_id]['content']['action_params']);
            } else {
                return is_array($input_data[$node_id]['content']) 
                    ? wp_json_encode($input_data[$node_id]['content']) 
                    : strval($input_data[$node_id]['content']);
            }
        }
        return $matches[0];
    }, $content);

    // Handle specific fields from any node type using node ID
    $content = preg_replace_callback('/\[\[([^\]]+)\] from ([\w-]+)\]/', function($matches) use ($input_data) {
        $field_path = $matches[1];
        $node_id = $matches[2];
        
        WP_AI_Workflows_Utilities::debug_log("Processing [[field] from] tag", "debug", [
            "field_path" => $field_path,
            "node_id" => $node_id,
            "input_data_exists" => isset($input_data[$node_id]),
            "input_data_type" => isset($input_data[$node_id]) ? $input_data[$node_id]['type'] : 'not_set'
        ]);

        if (isset($input_data[$node_id])) {
            if ($input_data[$node_id]['type'] === 'condition') {
                // For condition nodes, use the input data from the previous node
                $prev_node_data = reset($input_data[$node_id]['input_data']);
                $node_content = $prev_node_data['content'];
            } else if ($input_data[$node_id]['type'] === 'chat' && 
                     isset($input_data[$node_id]['content']['action_params'])) {
                // For chat nodes with action parameters, access the action parameters
                
                // Check if field_path contains a period (which might indicate an action parameter)
                if (strpos($field_path, '.') !== false) {
                    // Split the field path by the period
                    list($action_id, $param_name) = explode('.', $field_path, 2);
                    
                    // Access the parameter directly from action_params
                    $action_params = $input_data[$node_id]['content']['action_params'];
                    
                    WP_AI_Workflows_Utilities::debug_log("Processing action param access", "debug", [
                        "action_id" => $action_id,
                        "param_name" => $param_name,
                        "current_action_id" => $input_data[$node_id]['content']['current_action_id'] ?? 'none',
                        "action_params" => array_keys($action_params)
                    ]);
                    
                    // Check if parameter exists, regardless of action ID
                    // This allows access to parameters from any action
                    if (isset($action_params[$param_name])) {
                        $value = $action_params[$param_name];
                        if (is_array($value)) {
                            return implode(', ', array_filter($value));
                        }
                        return strval($value);
                    }
                }
                
                // Direct parameter access without action ID prefix
                if (isset($input_data[$node_id]['content']['action_params'][$field_path])) {
                    $value = $input_data[$node_id]['content']['action_params'][$field_path];
                    if (is_array($value)) {
                        return implode(', ', array_filter($value));
                    }
                    return strval($value);
                }
                
                WP_AI_Workflows_Utilities::debug_log("Accessing chat action params", "debug", [
                    "field_path" => $field_path,
                    "available_params" => array_keys($input_data[$node_id]['content']['action_params']),
                    "value_exists" => isset($input_data[$node_id]['content']['action_params'][$field_path])
                ]);
                
                // Not found, try standard node content handling
                $node_content = $input_data[$node_id]['content'];
            } else if ($input_data[$node_id]['type'] === 'trigger') {
                $node_content = $input_data[$node_id]['content'];
                
                // Special handling for trigger node content if it's the formatted data
                if (is_array($node_content) && isset($node_content['formatted_data'])) {
                    $node_content = $node_content['formatted_data'];
                }
                
                // NEW CODE: Check if content is a JSON string and try to decode it
                if (is_string($node_content) && $node_content !== '') {
                    $first_char = substr($node_content, 0, 1);
                    if ($first_char === '{' || $first_char === '[') {
                        $decoded = json_decode($node_content, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $node_content = $decoded;
                            
                            WP_AI_Workflows_Utilities::debug_log("Decoded JSON string in trigger node content", "debug", [
                                "field_path" => $field_path,
                                "decoded_keys" => is_array($node_content) ? array_keys($node_content) : 'not_array'
                            ]);
                        }
                    }
                }
        
                // Handle nested paths for structured data (like RSS)
                if (strpos($field_path, '.') !== false) {
                    $value = self::get_nested_value($node_content, $field_path);
                    if ($value !== null) {
                        if (is_array($value)) {
                            return implode(', ', array_filter($value));
                        }
                        return strval($value);
                    }
                }
                
                // Maintain existing direct field access
                if (isset($node_content[$field_path])) {
                    $field_value = $node_content[$field_path];
                    if (is_array($field_value)) {
                        return implode(', ', array_filter($field_value));
                    }
                    return strval($field_value);
                }
        
                WP_AI_Workflows_Utilities::debug_log("Trigger node field access", "debug", [
                    "field_path" => $field_path,
                    "node_content" => $node_content,
                    "field_exists" => isset($node_content[$field_path])
                ]);
            } else if ($input_data[$node_id]['type'] === 'firecrawl') {
                $node_content = $input_data[$node_id]['content'];
                
                // Handle extract format specifically
                if (strpos($field_path, 'extract.') === 0) {
                    // Remove 'extract.' prefix
                    $extract_path = substr($field_path, strlen('extract.'));
                    
                    if (isset($node_content['content']['data']['extract'])) {
                        $extract_data = $node_content['content']['data']['extract'];
                        
                        // Split the path into parts and traverse
                        $path_parts = explode('.', $extract_path);
                        $current = $extract_data;
                        
                        // Navigate through the nested structure
                        foreach ($path_parts as $part) {
                            if (isset($current[$part])) {
                                $current = $current[$part];
                            } else {
                                WP_AI_Workflows_Utilities::debug_log("Extract field not found in path", "debug", [
                                    "part" => $part,
                                    "path" => $extract_path,
                                    "current_keys" => is_array($current) ? array_keys($current) : 'not_array'
                                ]);
                                return $matches[0];  // Return original tag if path not found
                            }
                        }
                        
                        // Handle the final value
                        if (is_array($current) || is_object($current)) {
                            return json_encode($current);
                        }
                        return strval($current);
                    }
                    
                    WP_AI_Workflows_Utilities::debug_log("Extract data not found", "debug", [
                        "field_path" => $field_path,
                        "content_structure" => array_keys($node_content['content']['data'] ?? [])
                    ]);
                    
                    return $matches[0];
                }
                
                // Handle non-extract format
                if (isset($node_content['content'])) {
                    return is_array($node_content['content']) 
                        ? json_encode($node_content['content']) 
                        : strval($node_content['content']);
                }
            } else {
                $node_content = $input_data[$node_id]['content'];
                
                // NEW CODE: Also check for JSON strings in other node types
                if (is_string($node_content) && $node_content !== '') {
                    $first_char = substr($node_content, 0, 1);
                    if ($first_char === '{' || $first_char === '[') {
                        $decoded = json_decode($node_content, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $node_content = $decoded;
                        }
                    }
                }
            }
            
            if (is_array($node_content)) {
                if (isset($node_content[$field_path])) {
                    $field_value = $node_content[$field_path];
                    if (is_array($field_value)) {
                        return implode(', ', array_filter($field_value));
                    }
                    return strval($field_value);
                }
            }
            WP_AI_Workflows_Utilities::debug_log("Field not found in node content", "warning", [
                "field_path" => $field_path,
                "node_content_type" => gettype($node_content)
            ]);
        }
        return $matches[0];
    }, $content);

    return $content;
}



private static function execute_with_retry($url, $args, $retry_count) {
    $attempts = 0;
    $max_attempts = max(1, $retry_count + 1);
    $last_error = null;

    do {
        if ($attempts > 0) {
            // Exponential backoff with jitter
            $delay = min(pow(2, $attempts) + rand(0, 1000) / 1000, 30);
            sleep($delay);
        }

        $response = wp_remote_request($url, $args);
        
        if (!is_wp_error($response)) {
            $status = wp_remote_retrieve_response_code($response);
            // Consider only 5xx errors for retry
            if ($status < 500) {
                return $response;
            }
            $last_error = new WP_Error('http_error', "HTTP Error: $status");
        } else {
            $last_error = $response;
        }

        $attempts++;
    } while ($attempts < $max_attempts);

    return $last_error;
}

private static function process_api_response($response, $node) {
    if (is_wp_error($response)) {
        return self::create_node_data('error', $response->get_error_message());
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $headers = wp_remote_retrieve_headers($response);

    // Try to decode JSON response
    $decoded_body = json_decode($body, true);
    $data = (json_last_error() === JSON_ERROR_NONE) ? $decoded_body : $body;

    // Extract data using JSONPath if configured
    $extracted_data = $data;
    if (!empty($node['data']['responseConfig']['jsonPath'])) {
        try {
            if (!class_exists('\Flow\JSONPath\JSONPath')) {
                require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'vendor/autoload.php';
            }

            // Only attempt JSONPath on array data
            if (is_array($data)) {
                $jsonPath = new \Flow\JSONPath\JSONPath($data);
                $extracted_data = $jsonPath->find($node['data']['responseConfig']['jsonPath']);
                
                if ($extracted_data instanceof \Flow\JSONPath\JSONPath) {
                    $extracted_data = $extracted_data->getData();
                }
            }
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("JSONPath extraction failed", "warning", [
                'error' => $e->getMessage(),
                'path' => $node['data']['responseConfig']['jsonPath'],
                'data_type' => gettype($data)
            ]);
            // Keep original data if JSONPath extraction fails
            $extracted_data = $data;
        }
    }

    // Format the response for the workflow
    $formatted_response = [
        'status' => $status,
        'headers' => $headers,
        'data' => $extracted_data,
        'raw_response' => $data
    ];

    return self::create_node_data('apiCall', $formatted_response);
}

private static function create_node_data($type, $content) {
    
    // Ensure proper UTF-8 encoding for the content
    if (is_string($content)) {
        $content = html_entity_decode(stripslashes($content), ENT_QUOTES, 'UTF-8');
    } elseif (is_array($content)) {
        array_walk_recursive($content, function(&$item) {
            if (is_string($item)) {
                $item = html_entity_decode(stripslashes($item), ENT_QUOTES, 'UTF-8');
            }
        });
    }
    
    return [
        'type' => $type,
        'content' => $content,
    ];
}

private static function process_ai_response($response) {
    // Remove any existing <br> tags
    $response = str_replace('<br>', "\n", $response);
    $response = str_replace('<br />', "\n", $response);

    // Convert Markdown to HTML
    $response = self::markdown_to_html($response);

    // Ensure proper spacing for list items
    $response = preg_replace('/<\/li><li>/', "</li>\n<li>", $response);

    return $response;
}

private static function markdown_to_html($text) {
    // Convert Markdown-style bold to HTML
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    
    // Convert Markdown-style lists to HTML
    $text = preg_replace('/^\s*-\s+/m', '<li>', $text);
    $text = preg_replace('/(<li>.*?)(\n|$)/s', '$1</li>$2', $text);
    $text = preg_replace('/((?:<li>.*?<\/li>\s*)+)/', '<ul>$1</ul>', $text);

    // Convert newlines to <br> tags, but not within list items
    $text = preg_replace('/(?<!>)\n(?!<)/', '<br>', $text);

    return $text;
}
}