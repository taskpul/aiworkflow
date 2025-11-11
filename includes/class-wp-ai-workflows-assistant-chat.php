<?php

class WP_AI_Workflows_Assistant_Chat {
    private $session_id;
    private $workflow_id;
    private $workflow_context;
    private $selected_node;
    private $mode;
    private $prompt_path;
    
    public function __construct($workflow_id = null, $session_id = null) {
        if ($workflow_id && $session_id) {
            $this->workflow_id = $workflow_id;
            $this->session_id = $session_id;
            $this->load_session($session_id);
        }
        $this->prompt_path = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/assistant_system_prompt.xml';
    }

    public function init() {

    }

    private function get_system_prompt() {
        if (!file_exists($this->prompt_path)) {
            throw new Exception('Assistant system prompt not found');
        }
        return file_get_contents($this->prompt_path);
    }

    public function start_session($workflow_id, $session_id = null) {
        $this->workflow_id = $workflow_id;
        
        if ($session_id) {
            $this->load_session($session_id);
        } else {
            $this->create_session();
        }
    
        WP_AI_Workflows_Utilities::debug_log("Session started", "debug", [
            'session_id' => $this->session_id
        ]);
    
        return $this->session_id;  // Return the session_id
    }
    
    private function load_session($session_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s AND workflow_id = %s",
            $session_id,
            $this->workflow_id
        ));
        
        if (!$session) {
            throw new Exception('Invalid session');
        }
        
        $this->session_id = $session->session_id;
        $this->workflow_context = json_decode($session->workflow_context, true);
        $this->selected_node = $session->selected_node;
        $this->mode = $session->mode;
        
        $this->update_session_timestamp();
    }
    
    private function create_session() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        
        WP_AI_Workflows_Utilities::debug_log("Creating new assistant session", "debug", [
            'workflow_id' => $this->workflow_id
        ]);
        
        $this->session_id = wp_generate_uuid4();
        $this->mode = 'chat';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'session_id' => $this->session_id,
                'workflow_id' => $this->workflow_id,
                'mode' => $this->mode,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($wpdb->last_error) {
            WP_AI_Workflows_Utilities::debug_log("Database error creating session", "error", [
                'error' => $wpdb->last_error,
                'table' => $table_name
            ]);
            throw new Exception('Failed to create session: ' . $wpdb->last_error);
        }
        
        if ($result === false) {
            WP_AI_Workflows_Utilities::debug_log("Failed to insert session", "error");
            throw new Exception('Failed to create session: Insert failed');
        }
        
        WP_AI_Workflows_Utilities::debug_log("Session created successfully", "debug", [
            'session_id' => $this->session_id
        ]);
    }

    public function send_message($content) {
        global $wpdb;

        if (!$this->session_id) {
            throw new Exception('No active session');
        }
        
        // Add user message
        $this->add_message('user', $content);
        
        try {
            // Get AI response using Claude
            $response = $this->get_ai_response($content);
            
            // Add assistant message
            $this->add_message('assistant', $response);
            
            return $response;
            
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log('Assistant chat error', 'error', [
                'error' => $e->getMessage(),
                'session_id' => $this->session_id
            ]);
            throw $e;
        }
    }

    private function get_ai_response($message) {
        try {
            $api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
            if (empty($api_key)) {
                throw new Exception('OpenRouter API key not configured');
            }
    
            // Get base system prompt
            $system_prompt = $this->get_system_prompt();
    
            // Get workflow context
            $context_string = $this->workflow_context ? 
                wp_json_encode($this->workflow_context, JSON_PRETTY_PRINT) :
                "No workflow context available";
    
            // Add mode-specific instructions
            $mode_instructions = $this->mode === 'assistant' ?
                "\nYou are in assistant mode. When suggesting workflow changes, you MUST format your response as a JSON object with this structure:\n" .
                "{\n" .
                "  \"explanation\": \"Brief explanation of the changes\",\n" .
                "  \"changes\": {\n" .
                "    \"modified\": [{\n" .
                "      \"id\": \"node-id\",\n" .
                "      \"before\": { existing node properties },\n" .
                "      \"after\": { modified node properties }\n" .
                "    }],\n" .
                "    \"added\": [{ new node objects }],\n" .
                "    \"removed\": [\"node-ids-to-remove\"],\n" .
                "    \"connections\": [{\n" .
                "      \"action\": \"add/remove\",\n" .
                "      \"edge\": { edge object }\n" .
                "    }]\n" .
                "  }\n" .
                "}\n\n" .
                "Important: When asked to make changes, ALWAYS respond with this JSON structure, not with explanatory text. The changes object must " .
                "contain specific modifications to make to the workflow. For example, if asked to change a node's temperature, include the exact " .
                "node properties to modify in the JSON structure." :
                "\nYou are in chat mode. Provide explanations and guidance without making direct changes.";
    
            $messages = [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $system_prompt . $mode_instructions,
                        ],
                        [
                            'type' => 'text',
                            'text' => "\nCurrent Workflow Context:\n```json\n$context_string\n```\n" .
                                     ($this->selected_node ? "\nCurrently Selected Node: " . $this->selected_node . "\n" : "") .
                                     "\nWhen in assistant mode, you must return changes in the specified JSON format. " .
                                     "For example, to modify a temperature setting:\n" .
                                     "{\n" .
                                     "  \"explanation\": \"Adjusting temperature to 2.0 for more creative output\",\n" .
                                     "  \"changes\": {\n" .
                                     "    \"modified\": [{\n" .
                                     "      \"id\": \"aiModel-1\",\n" .
                                     "      \"before\": { \"settings\": { \"temperature\": 1.0 } },\n" .
                                     "      \"after\": { \"settings\": { \"temperature\": 2.0 } }\n" .
                                     "    }],\n" .
                                     "    \"added\": [],\n" .
                                     "    \"removed\": [],\n" .
                                     "    \"connections\": []\n" .
                                     "  }\n" .
                                     "}"
                        ]
                    ]
                ]
            ];
    
            // Add chat history
            foreach ($this->get_chat_history() as $msg) {
                $messages[] = [
                    'role' => $msg->role,
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $msg->content
                        ]
                    ]
                ];
            }
    
            // Add the current message with explicit instruction if in assistant mode
            if ($this->mode === 'assistant') {
                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $message . "\n\nRemember to respond with the JSON structure containing specific changes to apply."
                        ]
                    ]
                ];
            } else {
                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $message
                        ]
                    ]
                ];
            }

            $request_body = [
                'model' => 'anthropic/claude-3.7-sonnet',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 10000
            ];
    
            if ($this->mode === 'assistant') {
                $request_body['response_format'] = ['type' => 'json_object'];
            }
    
            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => get_site_url(),
                    'X-Title' => 'WP AI Workflow Assistant'
                ],
                'body' => wp_json_encode($request_body),
                'timeout' => 120
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new Exception('Invalid API response');
            }

            $content = $data['choices'][0]['message']['content'];
            
            if ($this->mode === 'assistant') {
                // Validate JSON response
                $content = $data['choices'][0]['message']['content'];
                $suggested_changes = json_decode($content, true);
                
                if (!$suggested_changes || json_last_error() !== JSON_ERROR_NONE) {
                    // If not valid JSON, return a formatted error response
                    return wp_json_encode([
                        'explanation' => 'I understand your request but need more specific information about what to change. Could you please be more specific about what aspects of the workflow you\'d like me to improve?',
                        'changes' => [
                            'modified' => [],
                            'added' => [],
                            'removed' => [],
                            'connections' => []
                        ]
                    ]);
                }
            }
    
            return $content;
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error in get_ai_response", "error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function validate_suggested_changes($changes) {
        if (!isset($changes['explanation']) || !isset($changes['changes'])) {
            return false;
        }

        $required_change_types = ['modified', 'added', 'removed', 'connections'];
        foreach ($required_change_types as $type) {
            if (!isset($changes['changes'][$type])) {
                return false;
            }
        }

        return true;
    }

    public function apply_workflow_changes($changes) {
        try {
            $workflow = $this->workflow_context;
    
            WP_AI_Workflows_Utilities::debug_log("Starting workflow changes", "debug", [
                'initial_workflow' => $workflow,
                'changes' => $changes
            ]);
        
            // Process node modifications
            if (!empty($changes['modified'])) {
                foreach ($changes['modified'] as $modification) {
                    foreach ($workflow['nodes'] as &$node) {
                        if ($node['id'] === $modification['id']) {
                            // Handle settings updates
                            if (isset($modification['after']['settings'])) {
                                $node['data']['settings'] = array_merge(
                                    $node['data']['settings'] ?? [],
                                    $modification['after']['settings']
                                );
                            }
                            
                            // Handle content updates
                            if (isset($modification['after']['content'])) {
                                $node['data']['content'] = $modification['after']['content'];
                            } else if (isset($modification['after']['prompt'])) {
                                // Map 'prompt' to 'content' in the node data
                                $node['data']['content'] = $modification['after']['prompt'];
                            } else if (isset($modification['after']['systemPrompt'])) {
                                
                                $node['data']['content'] = $modification['after']['systemPrompt'];
                            }
                            
                            // Handle model updates
                            if (isset($modification['after']['model'])) {
                                $node['data']['model'] = $modification['after']['model'];
                            }
                            
                            // Handle node name updates
                            if (isset($modification['after']['nodeName'])) {
                                $node['data']['nodeName'] = $modification['after']['nodeName'];
                            }
                            
                            // Handle other properties by node type
                            switch ($node['type']) {
                                case 'trigger':
                                    if (isset($modification['after']['triggerType'])) {
                                        $node['data']['triggerType'] = $modification['after']['triggerType'];
                                    }
                                    if (isset($modification['after']['selectedForm'])) {
                                        $node['data']['selectedForm'] = $modification['after']['selectedForm'];
                                    }
                                    if (isset($modification['after']['selectedFields'])) {
                                        $node['data']['selectedFields'] = $modification['after']['selectedFields'];
                                    }
                                    break;
                                    
                                case 'aiModel':
                                    if (isset($modification['after']['imageUrls'])) {
                                        $node['data']['imageUrls'] = $modification['after']['imageUrls'];
                                    }
                                    if (isset($modification['after']['openaiTools'])) {
                                        $node['data']['openaiTools'] = $modification['after']['openaiTools'];
                                    }
                                    break;
                                    
                                case 'output':
                                    if (isset($modification['after']['outputType'])) {
                                        $node['data']['outputType'] = $modification['after']['outputType'];
                                    }
                                    break;

                                case 'chat':
                                    // Handle system prompt updates
                                    if (isset($modification['after']['systemPrompt'])) {
                                        $node['data']['systemPrompt'] = $modification['after']['systemPrompt'];
                                    }
                                        
                                        // For backward compatibility, also check content
                                        if (isset($modification['after']['content']) && !isset($modification['after']['systemPrompt'])) {
                                            $node['data']['systemPrompt'] = $modification['after']['content'];
                                        }
                                        
                                        // Other chat node properties
                                        if (isset($modification['after']['design'])) {
                                            $node['data']['design'] = $modification['after']['design'];
                                        }
                                        if (isset($modification['after']['behavior'])) {
                                            $node['data']['behavior'] = $modification['after']['behavior'];
                                        }
                                        if (isset($modification['after']['model'])) {
                                            $node['data']['model'] = $modification['after']['model'];
                                        }
                                        if (isset($modification['after']['modelParams'])) {
                                            $node['data']['modelParams'] = $modification['after']['modelParams'];
                                        }
                                        if (isset($modification['after']['actions'])) {
                                            $node['data']['actions'] = $modification['after']['actions'];
                                        }
                                    break;
                                    
                                // Add cases for other node types as needed
                            }
                            
                            // Keep the generic data handling as fallback
                            if (isset($modification['after']['data'])) {
                                $node['data'] = array_merge(
                                    $node['data'],
                                    $modification['after']['data']
                                );
                            }
                            
                            WP_AI_Workflows_Utilities::debug_log("Node modified", "debug", [
                                'node_id' => $node['id'],
                                'after' => $node
                            ]);
                        }
                    }
                }
            }
    
            // Add new nodes with proper structure
            if (!empty($changes['added'])) {
                foreach ($changes['added'] as $new_node) {
                    // Generate a proper node ID if not provided
                    if (!isset($new_node['id']) || empty($new_node['id'])) {
                        $new_node['id'] = ($new_node['type'] ?? 'node') . '-' . time() . rand(1000, 9999);
                    }
                    
                    // Ensure node has required properties
                    if (!isset($new_node['position'])) {
                        // Find a reasonable position - to the right of existing nodes
                        $max_x = 250; // Default starting position
                        $max_y = 300;
                        
                        foreach ($workflow['nodes'] as $existing_node) {
                            if (isset($existing_node['position']['x']) && $existing_node['position']['x'] > $max_x) {
                                $max_x = $existing_node['position']['x'] + 400; // Add some spacing
                            }
                        }
                        
                        $new_node['position'] = [
                            'x' => $max_x,
                            'y' => $max_y
                        ];
                    }
                    
                    // Ensure data structure exists
                    if (!isset($new_node['data'])) {
                        $new_node['data'] = [];
                    }
                    
                    // Add basic required properties
                    $new_node['draggable'] = true;
                    
                    // Add default properties based on node type
                    switch ($new_node['type']) {
                        case 'aiModel':
                            $new_node['data']['nodeName'] = $new_node['data']['nodeName'] ?? 'AI Model ' . $new_node['id'];
                            $new_node['data']['settings'] = $new_node['data']['settings'] ?? [
                                'temperature' => 0.1,
                                'top_p' => 1.0,
                                'top_k' => 0,
                                'frequency_penalty' => 0.0,
                                'presence_penalty' => 0.0,
                                'repetition_penalty' => 1.0,
                                'max_tokens' => 4096
                            ];
                            $new_node['data']['model'] = $new_node['data']['model'] ?? 'gpt-4o-mini';
                            $new_node['data']['content'] = $new_node['data']['content'] ?? '';
                            $new_node['data']['imageUrls'] = $new_node['data']['imageUrls'] ?? [];
                            break;
                            
                        case 'trigger':
                            $new_node['data']['nodeName'] = $new_node['data']['nodeName'] ?? 'Trigger ' . $new_node['id'];
                            $new_node['data']['triggerType'] = $new_node['data']['triggerType'] ?? 'manual';
                            $new_node['data']['content'] = $new_node['data']['content'] ?? '';
                            break;
                            
                        case 'output':
                            $new_node['data']['nodeName'] = $new_node['data']['nodeName'] ?? 'Output ' . $new_node['id'];
                            $new_node['data']['outputType'] = $new_node['data']['outputType'] ?? 'text';
                            break;
                            
                        case 'post':
                            $new_node['data']['nodeName'] = $new_node['data']['nodeName'] ?? 'Post ' . $new_node['id'];
                            break;
                    
                            case 'chat':
                                // Set default node name
                                $new_node['data']['nodeName'] = $new_node['data']['nodeName'] ?? 'Chat ' . $new_node['id'];
                                
                                // Handle system prompt - ensure compatibility with both content and systemPrompt fields
                                if (isset($new_node['data']['content']) && !isset($new_node['data']['systemPrompt'])) {
                                    $new_node['data']['systemPrompt'] = $new_node['data']['content'];
                                    unset($new_node['data']['content']); // Remove content to avoid duplication
                                } else {
                                    $new_node['data']['systemPrompt'] = $new_node['data']['systemPrompt'] ?? '';
                                }
                                
                                // Set default model
                                $new_node['data']['model'] = $new_node['data']['model'] ?? 'anthropic/claude-3-opus';
                                
                                // Set default model parameters
                                $new_node['data']['modelParams'] = $new_node['data']['modelParams'] ?? [
                                    'temperature' => 1.0,
                                    'top_p' => 1.0,
                                    'top_k' => 0,
                                    'frequency_penalty' => 0.0,
                                    'presence_penalty' => 0.0,
                                    'repetition_penalty' => 1.0,
                                    'max_tokens' => 4096
                                ];
                                
                                // Set default design properties
                                $new_node['data']['design'] = $new_node['data']['design'] ?? [
                                    'theme' => 'light',
                                    'position' => 'bottom-right',
                                    'dimensions' => [
                                        'width' => 380,
                                        'height' => 600,
                                        'borderRadius' => 12
                                    ],
                                    'colors' => [
                                        'primary' => '#1677ff',
                                        'secondary' => '#f5f5f5',
                                        'text' => '#000000',
                                        'background' => '#ffffff'
                                    ],
                                    'font' => [
                                        'family' => 'Inter, system-ui, sans-serif',
                                        'size' => '14px',
                                        'headerSize' => '16px'
                                    ],
                                    'botName' => 'AI Assistant',
                                    'botIcon' => 'robot',
                                    'quickResponses' => [],
                                    'customCSS' => '',
                                    'sendButtonText' => 'Send',
                                    'showPoweredBy' => true
                                ];
                                
                                // Set default behavior properties
                                $new_node['data']['behavior'] = $new_node['data']['behavior'] ?? [
                                    'initialMessage' => 'Hello! How can I help you today?',
                                    'initialMessageType' => 'static',
                                    'placeholderText' => 'Type your message here...',
                                    'maxHistoryLength' => 50,
                                    'showTypingIndicator' => true,
                                    'soundEffects' => true,
                                    'showCitations' => false,
                                    'autoOpenDelay' => 0,
                                    'persistHistory' => true,
                                    'includePageContext' => false,
                                    'streamResponses' => false,
                                    'rateLimit' => [
                                        'enabled' => true,
                                        'maxMessages' => 10,
                                        'timeWindow' => 60
                                    ]
                                ];
                                
                                // Set default OpenAI tools configuration
                                $new_node['data']['openaiTools'] = $new_node['data']['openaiTools'] ?? [
                                    'webSearch' => [
                                        'enabled' => false,
                                        'contextSize' => 'medium',
                                        'location' => [
                                            'city' => '',
                                            'region' => '',
                                            'country' => ''
                                        ]
                                    ],
                                    'fileSearch' => [
                                        'enabled' => false,
                                        'vectorStoreId' => '',
                                        'maxResults' => 5
                                    ]
                                ];
                                
                                // Initialize empty actions array
                                $new_node['data']['actions'] = $new_node['data']['actions'] ?? [];
                                
                                // Set workflow ID if available
                                if (isset($workflow['id'])) {
                                    $new_node['data']['workflowId'] = $workflow['id'];
                                }
                                break;
                    
                            
                        // Add cases for other node types
                    }
                    
                    $workflow['nodes'][] = $new_node;
                    
                    WP_AI_Workflows_Utilities::debug_log("Added node", "debug", [
                        'node_id' => $new_node['id'],
                        'node' => $new_node
                    ]);
                }
            }
    
            // Remove nodes
            if (!empty($changes['removed'])) {
                $workflow['nodes'] = array_filter($workflow['nodes'], function($node) use ($changes) {
                    return !in_array($node['id'], $changes['removed']);
                });
                
                // Also remove any edges connected to the removed nodes
                if (!empty($workflow['edges'])) {
                    $workflow['edges'] = array_filter($workflow['edges'], function($edge) use ($changes) {
                        return !in_array($edge['source'], $changes['removed']) && 
                               !in_array($edge['target'], $changes['removed']);
                    });
                }
            }
    
            // Update connections with proper edge formatting
            if (!empty($changes['connections'])) {
                // Initialize edges array if it doesn't exist
                if (!isset($workflow['edges']) || !is_array($workflow['edges'])) {
                    $workflow['edges'] = [];
                }
                
                foreach ($changes['connections'] as $connection) {
                    if ($connection['action'] === 'add') {
                        // Extract the edge details
                        $edge = $connection['edge'];
                        
                        // Normalize source and target handles
                        $sourceHandle = isset($edge['sourceHandle']) ? 
                            ($edge['sourceHandle'] === 'output' ? 'a' : $edge['sourceHandle']) : 'a';
                        $targetHandle = isset($edge['targetHandle']) ? 
                            ($edge['targetHandle'] === 'input' ? null : $edge['targetHandle']) : null;
                        
                        // Generate proper edge ID
                        $edgeId = 'xy-edge__' . $edge['source'] . $sourceHandle . '-' . $edge['target'];
                        
                        // Create properly formatted edge
                        $newEdge = [
                            'id' => $edgeId,
                            'type' => 'default',
                            'animated' => false,
                            'source' => $edge['source'],
                            'sourceHandle' => $sourceHandle,
                            'target' => $edge['target']
                        ];
                        
                        // Only add targetHandle if it's not null
                        if ($targetHandle !== null) {
                            $newEdge['targetHandle'] = $targetHandle;
                        }
                        
                        // Check if this edge already exists
                        $edge_exists = false;
                        foreach ($workflow['edges'] as $existing_edge) {
                            if ($existing_edge['source'] === $newEdge['source'] && 
                                $existing_edge['target'] === $newEdge['target']) {
                                $edge_exists = true;
                                break;
                            }
                        }
                        
                        // Only add if it doesn't exist
                        if (!$edge_exists) {
                            $workflow['edges'][] = $newEdge;
                            WP_AI_Workflows_Utilities::debug_log("Added edge", "debug", [
                                'edge' => $newEdge
                            ]);
                        }
                    } else if ($connection['action'] === 'remove') {
                        // Remove edge by filtering
                        $edge_to_remove = $connection['edge'];
                        
                        $workflow['edges'] = array_filter($workflow['edges'], function($edge) use ($edge_to_remove) {
                            // Check by ID first (more specific)
                            if (isset($edge_to_remove['id']) && $edge['id'] === $edge_to_remove['id']) {
                                WP_AI_Workflows_Utilities::debug_log("Removing edge by ID", "debug", [
                                    'edge_id' => $edge['id']
                                ]);
                                return false;
                            }
                            
                            // Fallback to source/target matching if no ID provided
                            if ($edge['source'] === $edge_to_remove['source'] && 
                                $edge['target'] === $edge_to_remove['target']) {
                                WP_AI_Workflows_Utilities::debug_log("Removing edge by source/target", "debug", [
                                    'source' => $edge['source'],
                                    'target' => $edge['target']
                                ]);
                                return false;
                            }
                            
                            return true;
                        });
                        
                        // IMPORTANT: Reindex the array after filtering
                        $workflow['edges'] = array_values($workflow['edges']);
                    }
                }
                
                // Final reindexing to ensure proper JSON encoding
                $workflow['edges'] = array_values($workflow['edges']);
                
                WP_AI_Workflows_Utilities::debug_log("Connection changes applied", "debug", [
                    'final_edge_count' => count($workflow['edges']),
                    'connections_processed' => count($changes['connections'])
                ]);
            }
    
            // Get the original workflow to ensure we don't lose important data
        $original_workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow['id']);
        
        // Preserve the creator information
        if (isset($original_workflow['createdBy']) && empty($workflow['createdBy'])) {
            $workflow['createdBy'] = $original_workflow['createdBy'];
        }
        
        // Ensure the workflow remains active unless explicitly changed
        if (isset($original_workflow['status'])) {
            $workflow['status'] = $original_workflow['status'];
        } else {
            // Default to active if status is missing
            $workflow['status'] = 'active';
        }
        
        // Preserve creation date
        if (isset($original_workflow['createdAt'])) {
            $workflow['createdAt'] = $original_workflow['createdAt'];
        }
        
        // Set updated timestamp
        $workflow['updatedAt'] = current_time('mysql');
        
        // Update the workflow using DBAL
        $update_result = WP_AI_Workflows_Workflow_DBAL::update_workflow($workflow['id'], $workflow);
            
            if ($update_result === false) {
                throw new Exception('Failed to save workflow changes to database');
            }
    
            // Update the session context
            $this->update_workflow_context($workflow);
    
            WP_AI_Workflows_Utilities::debug_log("Workflow changes saved successfully", "debug", [
                'workflow_id' => $workflow['id'],
                'update_result' => $update_result
            ]);
    
            return $workflow;
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error applying workflow changes", "error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'changes' => $changes
            ]);
            throw $e;
        }
    }
    
    public function update_workflow_context($workflow_data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        
        if (!$this->session_id) {
            WP_AI_Workflows_Utilities::debug_log("Cannot update context - no session ID", "error");
            throw new Exception('No active session');
        }

        $this->workflow_context = $workflow_data;
        
        WP_AI_Workflows_Utilities::debug_log("Updating workflow context", "debug", [
            'session_id' => $this->session_id,
            'context_size' => strlen(json_encode($workflow_data))
        ]);
        
        $result = $wpdb->update(
            $table_name,
            array(
                'workflow_context' => json_encode($workflow_data),
                'updated_at' => current_time('mysql')
            ),
            array('session_id' => $this->session_id),
            array('%s', '%s'),
            array('%s')
        );
        
        if ($result === false) {
            WP_AI_Workflows_Utilities::debug_log("Failed to update context", "error", [
                'last_error' => $wpdb->last_error
            ]);
            throw new Exception('Failed to update workflow context');
        }

        return $result;
    }
    
    
    public function update_selected_node($node_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        
        $this->selected_node = $node_id;
        
        $wpdb->update(
            $table_name,
            array(
                'selected_node' => $node_id,
                'updated_at' => current_time('mysql')
            ),
            array('session_id' => $this->session_id),
            array('%s', '%s'),
            array('%s')
        );
    }
    
    private function update_session_timestamp() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        
        $wpdb->update(
            $table_name,
            array('updated_at' => current_time('mysql')),
            array('session_id' => $this->session_id),
            array('%s'),
            array('%s')
        );
    }
    
    public function get_session_id() {
        return $this->session_id;
    }

    public function update_mode($mode) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        
        WP_AI_Workflows_Utilities::debug_log("Updating session mode", "debug", [
            'session_id' => $this->session_id,
            'new_mode' => $mode
        ]);
        
        $result = $wpdb->update(
            $table_name,
            array(
                'mode' => $mode,
                'updated_at' => current_time('mysql')
            ),
            array('session_id' => $this->session_id),
            array('%s', '%s'),
            array('%s')
        );
        
        if ($result === false) {
            throw new Exception('Failed to update session mode');
        }
        
        $this->mode = $mode;
        return true;
    }

    private function add_message($role, $content) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';
        
        // First, check if we need to trim old messages
        $this->trim_message_history(30); // Keep only the most recent 30 messages
        
        $wpdb->insert(
            $table_name,
            array(
                'session_id' => $this->session_id,
                'role' => $role,
                'content' => $content,
                'node_context' => $this->selected_node,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        $this->update_session_timestamp();
    }
    
    /**
     * Trim message history to a maximum number of messages
     * 
     * @param int $max_messages Maximum number of messages to keep
     * @return bool True if messages were trimmed, false otherwise
     */
    private function trim_message_history($max_messages = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';
        
        // Count messages in this session
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE session_id = %s",
            $this->session_id
        ));
        
        if ($count <= $max_messages) {
            return false; // No need to trim
        }
        
        // Calculate how many messages to remove
        $to_remove = $count - $max_messages;
        
        // Get IDs of oldest messages to remove
        $message_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT message_id FROM $table_name 
             WHERE session_id = %s 
             ORDER BY created_at ASC 
             LIMIT %d",
            $this->session_id,
            $to_remove
        ));
        
        if (empty($message_ids)) {
            return false;
        }
        
        // Delete the oldest messages
        $placeholders = implode(',', array_fill(0, count($message_ids), '%d'));
        $query = "DELETE FROM $table_name WHERE message_id IN ($placeholders)";
        $wpdb->query($wpdb->prepare($query, $message_ids));
        
        WP_AI_Workflows_Utilities::debug_log("Trimmed assistant chat history", "debug", [
            'session_id' => $this->session_id,
            'removed_count' => $to_remove,
            'new_count' => $max_messages
        ]);
        
        return true;
    }
    
    public function get_chat_history() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE session_id = %s 
            ORDER BY created_at ASC 
            LIMIT 30",  // Always limit to the most recent 30 messages
            $this->session_id
        ));
    }

    /**
     * Cleanup old assistant chat data
     * Delete sessions and messages older than 90 days
     */
    public static function cleanup_old_data() {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        $messages_table = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';
        
        // Calculate cutoff date (90 days ago)
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-90 days'));
        
        // Find old sessions
        $old_sessions = $wpdb->get_col($wpdb->prepare(
            "SELECT session_id FROM $sessions_table WHERE updated_at < %s",
            $cutoff_date
        ));
        
        if (empty($old_sessions)) {
            WP_AI_Workflows_Utilities::debug_log("No old assistant sessions to clean up");
            return;
        }
        
        $count = count($old_sessions);
        
        // Delete messages for these sessions first
        if ($count > 0) {
            $placeholders = implode(',', array_fill(0, $count, '%s'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $messages_table WHERE session_id IN ($placeholders)",
                $old_sessions
            ));
            
            // Then delete the sessions
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $sessions_table WHERE session_id IN ($placeholders)",
                $old_sessions
            ));
        }
        
        WP_AI_Workflows_Utilities::debug_log("Cleaned up old assistant sessions", "info", [
            'sessions_removed' => $count,
            'cutoff_date' => $cutoff_date
        ]);
    }
}