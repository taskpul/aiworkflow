<?php

class WP_AI_Workflows_Workflow {

    public function init() {
        add_action('wp_ai_workflows_execute_scheduled_workflow', array($this, 'execute_scheduled_workflow'), 10, 1);
        add_action('wp_ai_workflows_execute_webhook_workflow', array($this, 'execute_webhook_workflow'), 10, 2);
        add_action('wp_ai_workflows_execute_workflow', array($this, 'handle_workflow_execution'), 10, 3);
        add_action('gform_after_submission', array($this, 'handle_gravity_forms_submission'), 10, 2);
        add_action('wpforms_process_complete', array($this, 'handle_wpforms_submission'), 10, 4);
        add_action('wpcf7_before_send_mail', array($this, 'handle_cf7_submission'), 10, 1);
        add_action('ninja_forms_after_submission', array($this, 'handle_ninja_forms_submission'));
        add_action('wp_ai_workflows_process_form_submission', array($this, 'process_form_submission'), 10, 3);
        add_action('publish_post', array($this, 'handle_publish_post_trigger'), 10, 2);
        add_action('user_register', array($this, 'handle_user_register_trigger'), 10, 1);
        add_action('wp_insert_comment', array($this, 'handle_insert_comment_trigger'), 10, 2);
        add_action('wp_login', array($this, 'handle_user_login_trigger'), 10, 2);
        add_action('wp_ai_workflows_process_login_trigger', array($this, 'process_login_trigger'), 10, 1);
        add_action('transition_post_status', array($this, 'handle_post_status_transition_trigger'), 10, 3);
        add_action('init', array($this, 'register_rss_schedules'));
        add_action('wp_ai_workflows_rss_check', array($this, 'handle_rss_check'), 10, 1);
        add_action('wp_ai_workflows_check_action_result', array('WP_AI_Workflows_Chat_Handler', 'check_action_result'), 10, 4);
    }

    public static function get_workflows($request) {
        try {
            
            WP_AI_Workflows_Utilities::debug_log("Attempting to fetch workflows", "debug", [
                'user_id' => get_current_user_id(),
                'user_roles' => wp_get_current_user()->roles
            ]);
            
            // Get pagination and search parameters
            $page = $request->get_param('page') ? (int) $request->get_param('page') : 1;
            $per_page = $request->get_param('per_page') ? (int) $request->get_param('per_page') : 200;
            $search = $request->get_param('search') ? sanitize_text_field($request->get_param('search')) : '';
            $status = $request->get_param('status') ? sanitize_text_field($request->get_param('status')) : null;
            $tags = $request->get_param('tags') ? array_map('sanitize_text_field', (array)$request->get_param('tags')) : [];
            
            // Get workflows using the DBAL
            $result = WP_AI_Workflows_Workflow_DBAL::search_workflows($search, $status, $tags, $page, $per_page);
            
            // Sanitize workflow data to prevent circular references and other encoding issues
            $safe_workflows = [];
            foreach ($result['workflows'] as $workflow) {
                // Skip null workflows
                if ($workflow === null) {
                    continue;
                }
                
                // Create a sanitized copy of the workflow
                $safe_workflow = [
                    'id' => $workflow['id'] ?? '',
                    'name' => $workflow['name'] ?? 'Unnamed Workflow',
                    'status' => $workflow['status'] ?? 'inactive',
                    'createdAt' => $workflow['createdAt'] ?? '',
                    'updatedAt' => $workflow['updatedAt'] ?? '',
                    'createdBy' => $workflow['createdBy'] ?? ''
                ];
                
                // Add nodes array safely
                if (isset($workflow['nodes']) && is_array($workflow['nodes'])) {
                    $safe_workflow['nodes'] = [];
                    foreach ($workflow['nodes'] as $node) {
                        if (!is_array($node)) continue;
                        
                        $safe_node = [
                            'id' => $node['id'] ?? '',
                            'type' => $node['type'] ?? '',
                            'position' => $node['position'] ?? ['x' => 0, 'y' => 0]
                        ];
                        
                        // Add data safely
                        if (isset($node['data']) && is_array($node['data'])) {
                            $safe_node['data'] = [];
                            foreach ($node['data'] as $key => $value) {
                                // Skip resources, deeply nested objects, etc.
                                if (is_resource($value) || is_object($value)) continue;
                                
                                if (is_array($value)) {
                                    // Flatten deep arrays to prevent circular references
                                    $safe_value = [];
                                    array_walk_recursive($value, function($item, $k) use (&$safe_value, $key) {
                                        if (!is_resource($item) && !is_object($item) && !is_array($item)) {
                                            if (!isset($safe_value[$k])) {
                                                $safe_value[$k] = $item;
                                            }
                                        }
                                    });
                                    $safe_node['data'][$key] = $safe_value;
                                } else {
                                    $safe_node['data'][$key] = $value;
                                }
                            }
                        } else {
                            $safe_node['data'] = [];
                        }
                        
                        $safe_workflow['nodes'][] = $safe_node;
                    }
                } else {
                    $safe_workflow['nodes'] = [];
                }
                
                // Add edges array safely
                if (isset($workflow['edges']) && is_array($workflow['edges'])) {
                    $safe_workflow['edges'] = [];
                    foreach ($workflow['edges'] as $edge) {
                        if (!is_array($edge)) continue;
                        
                        $safe_workflow['edges'][] = [
                            'id' => $edge['id'] ?? '',
                            'source' => $edge['source'] ?? '',
                            'target' => $edge['target'] ?? '',
                            'sourceHandle' => $edge['sourceHandle'] ?? null,
                            'targetHandle' => $edge['targetHandle'] ?? null
                        ];
                    }
                } else {
                    $safe_workflow['edges'] = [];
                }
                
                // Add viewport safely
                if (isset($workflow['viewport']) && is_array($workflow['viewport'])) {
                    $safe_workflow['viewport'] = [
                        'x' => $workflow['viewport']['x'] ?? 0,
                        'y' => $workflow['viewport']['y'] ?? 0,
                        'zoom' => $workflow['viewport']['zoom'] ?? 1
                    ];
                } else {
                    $safe_workflow['viewport'] = ['x' => 0, 'y' => 0, 'zoom' => 1];
                }
                
                // Copy tags if available
                if (isset($workflow['tags']) && is_array($workflow['tags'])) {
                    $safe_workflow['tags'] = [];
                    foreach ($workflow['tags'] as $tag) {
                        if (is_array($tag)) {
                            $safe_workflow['tags'][] = [
                                'id' => $tag['id'] ?? uniqid('tag_'),
                                'name' => $tag['name'] ?? 'Unknown Tag',
                                'color' => $tag['color'] ?? 'blue'
                            ];
                        }
                    }
                } else {
                    $safe_workflow['tags'] = [];
                }
                
                // Copy schedule if available
                if (isset($workflow['schedule']) && is_array($workflow['schedule'])) {
                    $safe_workflow['schedule'] = [
                        'enabled' => $workflow['schedule']['enabled'] ?? false,
                        'interval' => $workflow['schedule']['interval'] ?? 1,
                        'unit' => $workflow['schedule']['unit'] ?? 'day',
                        'endDate' => $workflow['schedule']['endDate'] ?? null
                    ];
                }
                
                // Copy needs_repair flag if present
                if (isset($workflow['needs_repair'])) {
                    $safe_workflow['needs_repair'] = $workflow['needs_repair'];
                }
                
                // Copy lastExecuted if present
                if (isset($workflow['lastExecuted'])) {
                    $safe_workflow['lastExecuted'] = $workflow['lastExecuted'];
                }
                
                $safe_workflows[] = $safe_workflow;
            }
            
            WP_AI_Workflows_Utilities::debug_log("Sanitized workflows for API response", "debug", [
                'count' => count($safe_workflows),
                'workflow_ids' => array_column($safe_workflows, 'id')
            ]);
            
            return new WP_REST_Response($safe_workflows, 200);
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Exception in get_workflows", "error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new WP_REST_Response(
                array('message' => 'Error retrieving workflows: ' . $e->getMessage()),
                500
            );
        }
    }

    public function handle_workflow_execution($workflow_id, $input_data, $triggered_by = null) {
        self::execute_workflow($workflow_id, $input_data);
    }

    public static function create_workflow($request) {

        
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_json_params()]);
        
        $workflow = $request->get_json_params();
        
        // Validate and prepare API Call nodes before saving
        foreach ($workflow['nodes'] as &$node) {
            if ($node['type'] === 'apiCall') {
                // Basic validation
                if (empty($node['data']['url'])) {
                    return new WP_Error(
                        'invalid_api_call',
                        'API Call node requires a URL',
                        array('status' => 400)
                    );
                }
    
                $timeout = isset($node['data']['responseConfig']['timeout']) ? 
                    intval($node['data']['responseConfig']['timeout']) : 30000;
                if ($timeout < 1000 || $timeout > 300000) {
                    return new WP_Error(
                        'invalid_timeout',
                        'Timeout must be between 1 and 300 seconds',
                        array('status' => 400)
                    );
                }
    
                try {
                    // Use existing method to encrypt credentials
                    $node = self::prepare_api_call_node_for_save($node);
                } catch (Exception $e) {
                    WP_AI_Workflows_Utilities::debug_log("Failed to encrypt API credentials", "error", [
                        'error' => $e->getMessage(),
                        'node_id' => $node['id']
                    ]);
                    return new WP_Error('encryption_failed', 'Failed to encrypt API credentials');
                }
            }
        }
    
        // Prepare workflow for saving
        $workflow['id'] = uniqid();
        $workflow['createdBy'] = wp_get_current_user()->user_login;
        $workflow['createdAt'] = current_time('mysql');
        $workflow['status'] = 'active';
        
        // Save workflow using the DBAL
        $result = WP_AI_Workflows_Workflow_DBAL::create_workflow($workflow);
        
        if ($result === false) {
            return new WP_Error('create_failed', 'Failed to create workflow', array('status' => 500));
        }
        
        return new WP_REST_Response($workflow, 201);
    }

    public static function update_workflow($request) {


        if (WP_DEBUG) {
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
        }

        try {
        
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        global $wpdb;
        $workflow_id = $request['id'];
        $updated_workflow = $request->get_json_params();
        
        // Get the original workflow
        $original_workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow_id);
        
        if (!$original_workflow) {
            return new WP_REST_Response(array('message' => 'Workflow not found'), 404);
        }
        
        // Handle status-only updates
        if (isset($updated_workflow['status']) && count($updated_workflow) === 1) {
            $original_workflow['status'] = $updated_workflow['status'];
            $original_workflow['updatedAt'] = current_time('mysql');
            
            $result = WP_AI_Workflows_Workflow_DBAL::update_workflow($workflow_id, $original_workflow);
            
            if ($result === false) {
                WP_AI_Workflows_Utilities::debug_log("Failed to update workflow status", "error", [
                    'workflow_id' => $workflow_id
                ]);
                return new WP_Error('update_failed', 'Failed to update workflow', array('status' => 500));
            }
            
            return new WP_REST_Response($original_workflow, 200);
        }
        
        // Handle node-specific data
        if (isset($updated_workflow['nodes'])) {
            foreach ($updated_workflow['nodes'] as &$node) {
                if ($node['type'] === 'chat') {
                    // Ensure chat nodes always have the workflow ID
                    $node['data']['workflowId'] = $workflow_id;
                    
                    // Validate and sanitize chat settings
                    $node['data']['design'] = isset($node['data']['design']) ? $node['data']['design'] : [
                        'theme' => 'light',
                        'position' => 'bottom-right',
                        'dimensions' => [
                            'width' => 380,
                            'height' => 600,
                            'borderRadius' => 12
                        ]
                    ];
                }
                // Handle API Call nodes
                if ($node['type'] === 'apiCall') {
                    try {
                        $existing_node = self::find_node_by_id($original_workflow['nodes'], $node['id']);
                        
                        // Add debugging for credential processing
                        WP_AI_Workflows_Utilities::debug_log("Processing API credentials", "debug", [
                            'node_id' => $node['id'],
                            'has_existing_node' => !empty($existing_node),
                            'auth_type' => $node['data']['auth']['type']
                        ]);
                        
                        if ($existing_node) {
                            $node = self::preserve_api_call_credentials($node, $existing_node);
                        } else {
                            $node = self::prepare_api_call_node_for_save($node);
                        }
                        
                        // Verify encryption
                        if (!empty($node['data']['auth'])) {
                            foreach (['username', 'password', 'token', 'apiKey'] as $field) {
                                if (!empty($node['data']['auth'][$field]) && 
                                    $node['data']['auth'][$field] !== '********' && 
                                    strpos($node['data']['auth'][$field], 'enc_') !== 0) {
                                    throw new Exception("Encryption verification failed for $field");
                                }
                            }
                        }
                        
                    } catch (Exception $e) {
                        WP_AI_Workflows_Utilities::debug_log("API credential processing failed", "error", [
                            'error' => $e->getMessage(),
                            'node_id' => $node['id']
                        ]);
                        return new WP_Error('encryption_failed', $e->getMessage());
                    }
                }
    
                if ($node['type'] === 'output' && isset($node['data']['columns'])) {
                    $node['data']['columns'] = array_map(function($column) {
                        return [
                            'name' => $column['name'],
                            'type' => $column['type'],
                            'mapping' => $column['mapping'] ?? ''
                        ];
                    }, $node['data']['columns']);
                }
                if ($node['type'] === 'parser') {
                    if (isset($node['data']['parsedContents'])) {
                        $node['data']['parsedContents'] = maybe_serialize($node['data']['parsedContents']);
                    }
                }
            }
        }
    
        // Merge updates with original workflow
        $updated_workflow = array_merge($original_workflow, $updated_workflow);
        $updated_workflow['updatedAt'] = current_time('mysql');
    
        if (self::has_chat_node($updated_workflow['nodes'])) {
            update_option(
                'wp_ai_workflows_chat_config_' . $workflow_id, 
                self::extract_chat_config($updated_workflow['nodes'])
            );
        }
    
        // Handle scheduling (both regular and RSS)
        if (isset($updated_workflow['schedule']) || self::has_rss_trigger($updated_workflow['nodes'])) {
            $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
            
            // Clear all existing schedules
            self::clear_scheduled_events($workflow_id);
            wp_clear_scheduled_hook('wp_ai_workflows_rss_check', array($workflow_id));
    
            // Handle regular schedule
            if (isset($updated_workflow['schedule'])) {
                $schedule = $updated_workflow['schedule'];
                
                WP_AI_Workflows_Utilities::debug_log("Updating workflow schedule", "debug", [
                    'workflow_id' => $workflow_id,
                    'schedule' => $schedule
                ]);
    
                if ($schedule['enabled']) {
                    $next_run = self::calculate_next_run($schedule);
                    if ($next_run === false) {
                        WP_AI_Workflows_Utilities::debug_log("Invalid schedule data", "error", $schedule);
                        return new WP_Error('invalid_schedule', 'Invalid schedule data', array('status' => 400));
                    }
                
                    wp_schedule_single_event($next_run, 'wp_ai_workflows_execute_scheduled_workflow', array($workflow_id));
                    $wpdb->insert(
                        $table_name,
                        array(
                            'workflow_id' => $workflow_id,
                            'workflow_name' => $updated_workflow['name'],
                            'status' => 'scheduled',
                            'scheduled_at' => gmdate('Y-m-d H:i:s', $next_run),
                            'created_at' => gmdate('Y-m-d H:i:s'),
                            'updated_at' => gmdate('Y-m-d H:i:s')
                        ),
                        array('%s', '%s', '%s', '%s', '%s', '%s')
                    );
                } else {
                    $wpdb->delete(
                        $table_name,
                        array('workflow_id' => $workflow_id, 'status' => 'scheduled'),
                        array('%s', '%s')
                    );
                }
            }
    
            // Handle RSS schedule
            $trigger_node = self::find_trigger_node($updated_workflow['nodes']);
            if ($trigger_node && 
                isset($trigger_node['data']['triggerType']) && 
                $trigger_node['data']['triggerType'] === 'rss' &&
                $updated_workflow['status'] === 'active') {
                
                $interval = $trigger_node['data']['rssSettings']['pollingInterval'];
                $schedule_name = 'wp_ai_workflows_' . $interval;
                
                wp_schedule_event(
                    time(), 
                    $schedule_name, 
                    'wp_ai_workflows_rss_check', 
                    array($workflow_id)
                );
                
                WP_AI_Workflows_Utilities::debug_log("RSS check scheduled", "debug", [
                    'workflow_id' => $workflow_id,
                    'interval' => $interval
                ]);
            }
        }
    
        // Update workflow using the DBAL
        $result = WP_AI_Workflows_Workflow_DBAL::update_workflow($workflow_id, $updated_workflow);
        
        if ($result === false) {
            WP_AI_Workflows_Utilities::debug_log("Failed to update workflow", "error", [
                'workflow_id' => $workflow_id
            ]);
            return new WP_Error('update_failed', 'Failed to update workflow', array('status' => 500));
        }
    
        if (isset($updated_workflow['nodes'])) {
            foreach ($updated_workflow['nodes'] as $node) {
                if ($node['type'] === 'chat') {
                    // Set transient flag to clear sessions for this workflow
                    set_transient('wp_ai_workflows_refresh_chat_' . $workflow_id, true, 300);
                    break;
                }
            }
        }
    
        WP_AI_Workflows_Utilities::debug_log("Workflow updated successfully", "debug", [
            'workflow_id' => $workflow_id
        ]);
    
        return new WP_REST_Response($updated_workflow, 200);
                } catch (Throwable $e) {
            WP_AI_Workflows_Utilities::debug_log("Uncaught exception in update_workflow", "error", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new WP_Error('update_failed', 'Exception: ' . $e->getMessage(), ['status' => 500]);
        }
    }
    

    private static function has_rss_trigger($nodes) {
        $trigger_node = self::find_trigger_node($nodes);
        return $trigger_node && 
            isset($trigger_node['data']['triggerType']) && 
            $trigger_node['data']['triggerType'] === 'rss';
    }
    
    public static function delete_workflow($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        global $wpdb;
        $workflow_id = $request['id'];
        
        // Clear any scheduled events for this workflow
        wp_clear_scheduled_hook('wp_ai_workflows_rss_check', array($workflow_id));
        wp_clear_scheduled_hook('wp_ai_workflows_execute_scheduled', array($workflow_id));
        
        // Delete using the DBAL
        $result = WP_AI_Workflows_Workflow_DBAL::delete_workflow($workflow_id);
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete workflow', array('status' => 500));
        }
    
        // Remove any executions for this workflow from the database
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        $deleted = $wpdb->delete(
            $table_name,
            array('workflow_id' => $workflow_id),
            array('%s')
        );
    
        WP_AI_Workflows_Utilities::debug_log("Workflow deleted", "info", [
            'workflow_id' => $workflow_id,
            'executions_deleted' => $deleted
        ]);
    
        return new WP_REST_Response(null, 204);
    }

    
    public static function get_single_workflow($request) {
        try {
            
            $id = $request['id'];
            
            if (empty($id)) {
                return new WP_REST_Response(array('message' => 'Workflow ID is required'), 400);
            }
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
            
            // Get the workflow directly from the database to handle corrupted data
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE id = %s",
                    $id
                ), ARRAY_A);
                
                if (!$row) {
                    return new WP_REST_Response(array('message' => 'Workflow not found'), 404);
                }
                
                // Create a safe, basic workflow structure
                $safe_workflow = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'status' => $row['status'],
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at'],
                    'createdBy' => $row['created_by'] ?? 'unknown',
                    'nodes' => [],
                    'edges' => [],
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]
                ];
                
                // Try to parse the JSON data if available
                if (!empty($row['data'])) {
                    try {
                        $data = json_decode($row['data'], true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                            // Import nodes safely
                            if (isset($data['nodes']) && is_array($data['nodes'])) {
                                $safe_nodes = [];
                                foreach ($data['nodes'] as $node) {
                                    if (!is_array($node)) continue;
                                    
                                    $safe_node = [
                                        'id' => $node['id'] ?? uniqid('node-'),
                                        'type' => $node['type'] ?? 'unknown',
                                        'position' => is_array($node['position'] ?? null) ? $node['position'] : ['x' => 0, 'y' => 0],
                                        'data' => is_array($node['data'] ?? null) ? $node['data'] : []
                                    ];
                                    
                                    $safe_nodes[] = $safe_node;
                                }
                                $safe_workflow['nodes'] = $safe_nodes;
                            }
                            
                            // Import edges safely
                            if (isset($data['edges']) && is_array($data['edges'])) {
                                $safe_edges = [];
                                foreach ($data['edges'] as $edge) {
                                    if (!is_array($edge)) continue;
                                    
                                    $safe_edge = [
                                        'id' => $edge['id'] ?? uniqid('edge-'),
                                        'source' => $edge['source'] ?? '',
                                        'target' => $edge['target'] ?? '',
                                        'sourceHandle' => $edge['sourceHandle'] ?? null,
                                        'targetHandle' => $edge['targetHandle'] ?? null
                                    ];
                                    
                                    $safe_edges[] = $safe_edge;
                                }
                                $safe_workflow['edges'] = $safe_edges;
                            }
                            
                            // Copy other safe properties
                            foreach (['tags', 'viewport', 'schedule', 'lastExecuted'] as $prop) {
                                if (isset($data[$prop])) {
                                    $safe_workflow[$prop] = $data[$prop];
                                }
                            }
                        } else {
                            // Invalid JSON - mark as needing repair
                            $safe_workflow['needs_repair'] = true;
                        }
                    } catch (Exception $e) {
                        // Exception during JSON processing - mark as needing repair
                        $safe_workflow['needs_repair'] = true;
                    }
                } else {
                    // Empty data - mark as needing repair
                    $safe_workflow['needs_repair'] = true;
                }
                
                return new WP_REST_Response($safe_workflow, 200);
            }
            
            // Fallback to options
            $workflows = get_option('wp_ai_workflows', []);
            foreach ($workflows as $workflow) {
                if (is_array($workflow) && isset($workflow['id']) && $workflow['id'] === $id) {
                    return new WP_REST_Response($workflow, 200);
                }
            }
            
            return new WP_REST_Response(array('message' => 'Workflow not found'), 404);
            
        } catch (Exception $e) {
            // Log the error and return a safe response
            WP_AI_Workflows_Utilities::debug_log("Error in get_single_workflow", "error", [
                'workflow_id' => $request['id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new WP_REST_Response(
                array(
                    'message' => 'Error processing workflow data',
                    'error' => $e->getMessage(),
                    'id' => $request['id'],
                    'needs_repair' => true,
                    'nodes' => [],
                    'edges' => [],
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]
                ), 
                200  // Return 200 instead of 500 to allow frontend to handle it
            );
        }
    }
    
    
    public static function execute_workflow_endpoint($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        $workflow_id = $request['id'];
        $params = $request->get_json_params();
        $session_id = $request->get_header('X-Session-ID');
        
        // Get workflow using DBAL
        $target_workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow_id);
    
        if (!$target_workflow) {
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(
                ['error' => 'Invalid workflow ID'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        }
        
        // Create execution record
        global $wpdb;
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
        
        $wpdb->insert(
            $executions_table,
            array(
                'workflow_id' => $workflow_id,
                'workflow_name' => $target_workflow['name'],
                'status' => 'processing',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            )
        );
        $execution_id = $wpdb->insert_id;
    
        // Send response
        header('Content-Type: application/json');
        echo wp_json_encode(
            [
                'execution_id' => $execution_id,
                'status' => 'processing'
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    
        // Process trigger data
        $trigger_data = null;
        if (isset($params['formData'])) {
            $trigger_node = self::find_trigger_node($target_workflow['nodes']);
            if ($trigger_node && $trigger_node['data']['triggerType'] === 'gravityForms') {
                $trigger_data = self::format_gravity_form_data($params['formData'], $trigger_node['data']['selectedFields']);
            }
        } else {
            $trigger_node = self::find_trigger_node($target_workflow['nodes']);
            if ($trigger_node && $trigger_node['data']['triggerType'] === 'manual') {
                $trigger_data = $trigger_node['data']['content'];
            }
        }
    
        if (is_string($trigger_data)) {
            $trigger_data = html_entity_decode($trigger_data, ENT_QUOTES, 'UTF-8');
        } elseif (is_array($trigger_data)) {
            array_walk_recursive($trigger_data, function(&$item) {
                if (is_string($item)) {
                    $item = html_entity_decode($item, ENT_QUOTES, 'UTF-8');
                }
            });
        }
    
        // Execute workflow
        $result = self::execute_workflow($workflow_id, $trigger_data, $execution_id, $session_id);
        
        if (is_wp_error($result)) {
            $wpdb->update(
                $executions_table,
                array(
                    'status' => 'error',
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $execution_id)
            );
        }
    
        exit;
    }
    
    
    public static function execute_workflow($workflow_id, $initial_data = null, $execution_id = null, $session_id = null, $resume_from_node = null, $human_action = null, $action_id = null) {

        WP_AI_Workflows_Utilities::debug_log("Starting/Resuming workflow execution", "info", [
            "execution_id" => $execution_id,
            "workflow_id" => $workflow_id,
            "resume_from_node" => $resume_from_node,
            "action_id" => $action_id
        ]);
        
        global $wpdb;
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
        $shortcode_outputs_table = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';
        
        // Get workflow using DBAL
        $workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow_id);
    
        if (!$workflow) {
            WP_AI_Workflows_Utilities::debug_log("Workflow not found", "error", [
                "workflow_id" => $workflow_id,
                "backtrace" => wp_debug_backtrace_summary()
            ]);
            do_action('wp_ai_workflows_execution_failed', 'workflow_not_found', $workflow_id);
            return new WP_Error('workflow_not_found', 'Workflow not found');
        }
    
        if ($workflow['status'] !== 'active') {
            WP_AI_Workflows_Utilities::debug_log("Workflow is inactive", "info", ["workflow_id" => $workflow_id]);
            return new WP_Error('workflow_inactive', 'Workflow is inactive');
        }
    
        $trigger_data = $initial_data; // Initialize trigger_data with initial_data

        if ($session_id) {
            global $wpdb;
            $sessions_table = $wpdb->prefix . 'wp_ai_workflows_sessions';
            
            // Insert or update session
            $wpdb->replace(
                $sessions_table,
                array(
                    'session_id' => $session_id,
                    'workflow_id' => $workflow_id,
                    'metadata' => wp_json_encode([
                        'last_execution_id' => $execution_id,
                        'status' => 'processing'
                    ])
                ),
                array('%s', '%s', '%s')
            );
        }
        
        // If no execution_id is provided, create a new execution record
        if ($execution_id === null) {
            $wpdb->insert(
                $executions_table,
                array(
                    'workflow_id' => $workflow_id,
                    'workflow_name' => $workflow['name'], // Use workflow name directly
                    'status' => 'processing',
                    'input_data' => wp_json_encode($initial_data),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                )
            );
            $execution_id = $wpdb->insert_id;
        } else {
            // If resuming, fetch the existing execution data
            $existing_execution = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $executions_table WHERE id = %d",
                $execution_id
            ));
            if ($existing_execution) {
                $trigger_data = json_decode($existing_execution->input_data, true);
            }
        }
    
        WP_AI_Workflows_Utilities::debug_log("Execution record created or retrieved", "debug", [
            "execution_id" => $execution_id,
            "workflow_id" => $workflow_id,
        ]);
    
        global $initial_webhook_data;
        $initial_webhook_data = $trigger_data;
    
        $nodes = $workflow['nodes'];
        $edges = $workflow['edges'];
    
        $sorted_nodes = self::topological_sort($nodes, $edges);

        $annotation_node_types = ['textAnnotation', 'stickyNote', 'shape'];

        // Filter out annotation nodes from sorted nodes
        $sorted_nodes = array_filter($sorted_nodes, function($node) use ($annotation_node_types) {
            return !in_array($node['type'], $annotation_node_types);
        });
    
        $node_data = array();
        $condition_results = array();
        $nodes_to_skip = array();
    
        if ($resume_from_node) {
            // Fetch existing node data from the database
            $existing_execution = $wpdb->get_row($wpdb->prepare(
                "SELECT output_data FROM $executions_table WHERE id = %d",
                $execution_id
            ));
            if ($existing_execution && $existing_execution->output_data) {
                $node_data = json_decode($existing_execution->output_data, true);
            }
        }
    
        $nodes_to_skip = array();
        $resume_mode = ($resume_from_node !== null);
        $start_from_nodes = array();
        $skip_until_start_node = false;
        $valid_nodes_for_action = array();
    
        // For action-triggered workflows, determine starting points 
        if ($action_id) {
            // Find the chat node
            $chat_node = null;
            foreach ($nodes as $node) {
                if ($node['type'] === 'chat') {
                    $chat_node = $node;
                    break;
                }
            }
        
            if ($chat_node) {
                // Store the action parameters in the node data for reference by other nodes
                $action_params = $initial_data; // These are the extracted parameters from the chat
                $node_data[$chat_node['id']] = array(
                    'type' => 'chat',
                    'content' => array(
                        'model' => $chat_node['data']['model'],
                        'systemPrompt' => $chat_node['data']['systemPrompt'],
                        'modelParams' => $chat_node['data']['modelParams'],
                        'actions' => $chat_node['data']['actions'] ?? [],
                        'action_params' => $action_params, // Store action parameters
                        'current_action_id' => $action_id // Store the current action ID
                    )
                );
                
                // Find direct target nodes connected to this action handle
                foreach ($edges as $edge) {
                    if ($edge['source'] === $chat_node['id'] && $edge['sourceHandle'] === $action_id) {
                        $start_from_nodes[] = $edge['target'];
                        WP_AI_Workflows_Utilities::debug_log("Found direct connection for action", "debug", [
                            "action" => $action_id,
                            "target" => $edge['target']
                        ]);
                    }
                }
        
                // Get ALL downstream nodes for this action
                $valid_nodes_for_action = array($chat_node['id']); // Always include the chat node itself
                foreach ($start_from_nodes as $start_node) {
                    $valid_nodes_for_action[] = $start_node;
                    $downstream = self::get_downstream_nodes($start_node, $edges);
                    $valid_nodes_for_action = array_merge($valid_nodes_for_action, $downstream);
                }
                $valid_nodes_for_action = array_unique($valid_nodes_for_action);
        
                WP_AI_Workflows_Utilities::debug_log("Action execution path", "debug", [
                    'action_id' => $action_id,
                    'chat_node' => $chat_node['id'],
                    'start_from_nodes' => $start_from_nodes,
                    'all_action_nodes' => $valid_nodes_for_action,
                    'action_params' => $action_params
                ]);
        
                // If we have starting nodes, set the flag to skip until we reach one
                if (!empty($start_from_nodes)) {
                    $skip_until_start_node = true;
                }
            }
        }
    
        $has_executed_nodes = false;
    
        foreach ($sorted_nodes as $node) {
            $node_id = $node['id'];
            $node_type = $node['type'];

                if (in_array($node_type, $annotation_node_types)) {
                    WP_AI_Workflows_Utilities::debug_log("Skipping annotation node", "debug", [
                        "node_id" => $node_id,
                        "node_type" => $node_type
                    ]);
                    continue;
                }
    
            // If we're in action mode, only process nodes that are part of the action path
            if ($action_id && !in_array($node_id, $valid_nodes_for_action)) {
                WP_AI_Workflows_Utilities::debug_log("Skipping node not in action path", "debug", [
                    "node_id" => $node_id, 
                    "node_type" => $node_type
                ]);
                continue;
            }
    
            // If in action mode and waiting for a start node
            if ($action_id && $skip_until_start_node) {
                if (!in_array($node_id, $start_from_nodes)) {
                    WP_AI_Workflows_Utilities::debug_log("Skipping until start node", "debug", [
                        "node_id" => $node_id, 
                        "node_type" => $node_type,
                        "waiting_for" => $start_from_nodes
                    ]);
                    continue; // Skip until we reach a start node
                } else {
                    // We found a start node, don't skip anymore
                    $skip_until_start_node = false;
                    WP_AI_Workflows_Utilities::debug_log("Starting execution from node", "debug", [
                        "node_id" => $node_id, 
                        "node_type" => $node_type
                    ]);
                }
            }
    
            $wpdb->update(
                $executions_table,
                array(
                    'current_node' => $node_id,
                    'output_data' => wp_json_encode($node_data),
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $execution_id)
            );
    
            $wpdb->flush();
    
            usleep(500000); 
            
            if ($resume_mode && !$action_id){
                if ($node_id !== $resume_from_node) {
                    continue;
                }
                $resume_mode = false;
    
                if ($node_type === 'humanInput' && $human_action) {
                    // Handle human input similar to condition node
                    $approve_path = self::get_downstream_nodes($node_id, $edges, 'approve');
                    $revert_path = self::get_downstream_nodes($node_id, $edges, 'revert');
                    
                    if ($human_action === 'approve') {
                        $nodes_to_skip = array_merge($nodes_to_skip, $revert_path);
                    } else if ($human_action === 'revert') {
                        $nodes_to_skip = array_merge($nodes_to_skip, $approve_path);
                    }
                    
                    WP_AI_Workflows_Utilities::debug_log("Human input action", "debug", [
                        "node_id" => $node_id,
                        "action" => $human_action,
                        "nodes_to_skip" => $nodes_to_skip
                    ]);
                    
                    continue; // Skip the human input node as we're resuming from it
                }
            }
    
            if (in_array($node_id, $nodes_to_skip)) {
                WP_AI_Workflows_Utilities::debug_log("Skipping node", "debug", ["node_id" => $node_id, "node_type" => $node_type]);
                continue;
            }
            
            WP_AI_Workflows_Utilities::debug_log("Executing node", "debug", ["node_id" => $node_id, "node_type" => $node_type]);
            $has_executed_nodes = true;
    
            if ($node_type === 'trigger') {
                if (in_array($node['data']['triggerType'], ['googleSheets', 'googleDrive'])) {
                    $result = WP_AI_Workflows_Node_Execution::execute_google_trigger_node($node, $trigger_data, $execution_id);
                } else {
                    $result = WP_AI_Workflows_Node_Execution::execute_trigger_node($node, $trigger_data, $execution_id);
                }
            } elseif ($node_type === 'humanInput') {
                $result = WP_AI_Workflows_Node_Execution::execute_human_input_node($node, $node_data, $execution_id);
                if ($result['status'] === 'pending') {
                    // Pause workflow execution for human input
                    $wpdb->update(
                        $executions_table,
                        array(
                            'status' => 'paused',
                            'output_data' => wp_json_encode($node_data),
                            'updated_at' => current_time('mysql')
                        ),
                        array('id' => $execution_id)
                    );
                    WP_AI_Workflows_Utilities::debug_log("Workflow paused for human input", "info", [
                        "execution_id" => $execution_id,
                        "node_id" => $node_id
                    ]);
                    return array('status' => 'paused', 'node_id' => $node_id);
                }
            } else {
                $result = WP_AI_Workflows_Node_Execution::execute_node($node, $node_data, $edges, $execution_id);
            }
    
            if ($result !== null) {
                $node_data[$node_id] = $result;
                
                if ($node_type === 'condition') {
                    $condition_results[$node_id] = $result['content'];
                    $true_path = self::get_downstream_nodes($node_id, $edges, 'true');
                    $false_path = self::get_downstream_nodes($node_id, $edges, 'false');
    
                    WP_AI_Workflows_Utilities::debug_log("Condition node paths", "debug", [
                        "node_id" => $node_id,
                        "condition_result" => $result['content'],
                        "true_path" => $true_path,
                        "false_path" => $false_path
                    ]);
    
                    if ($result['content']) {
                        $nodes_to_skip = array_merge($nodes_to_skip, $false_path);
                    } else {
                        $nodes_to_skip = array_merge($nodes_to_skip, $true_path);
                    }
    
                    WP_AI_Workflows_Utilities::debug_log("Updated nodes to skip", "debug", ["nodes_to_skip" => $nodes_to_skip]);
                }
            }
            
            if ($result !== null) {
                $node_data[$node_id] = $result;
                // Update execution record with latest node data
                $wpdb->update(
                    $executions_table,
                    array(
                        'output_data' => wp_json_encode($node_data),
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $execution_id)
                );
                $wpdb->flush();
            }
            
            //WP_AI_Workflows_Utilities::debug_log("Node execution result", "debug", ["node_id" => $node_id, "result" => $result]);
            usleep(250000);
        }
    
        // Add warning if no nodes were executed
        if (!$has_executed_nodes) {
            WP_AI_Workflows_Utilities::debug_log("No nodes were executed", "warning", [
                "workflow_id" => $workflow_id,
                "action_id" => $action_id,
                "sorted_nodes_count" => count($sorted_nodes)
            ]);
        }
    
        // Update the workflow with the results and last execution time
        foreach ($workflow['nodes'] as &$node) {
            if (isset($node_data[$node['id']])) {
                $node['data']['output'] = $node_data[$node['id']]['content'];
                $node['data']['executed'] = true;
            } else {
                $node['data']['executed'] = false;
            }
        }
        $workflow['lastExecuted'] = current_time('mysql');
    
        WP_AI_Workflows_Workflow_DBAL::update_workflow($workflow_id, $workflow);
    
        $final_result = wp_json_encode($node_data);
        // Update the execution record with the results
        $wpdb->update(
            $executions_table,
            array(
                'status' => 'completed',
                'output_data' => $final_result,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $execution_id)
        );
    
        self::trigger_dependent_workflows($execution_id);
    
        // Save output to shortcode table
        $insert_result = $wpdb->insert(
            $shortcode_outputs_table,
            array(
                'session_id' => $session_id ?: 'public',
                'workflow_id' => $workflow_id,
                'output_data' => $final_result
            ),
            array('%s', '%s', '%s')
        );
        
        // Verify the save immediately
        $verify_save = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shortcode_outputs_table} WHERE workflow_id = %s ORDER BY id DESC LIMIT 1",
            $workflow_id
        ));
        
        WP_AI_Workflows_Utilities::debug_log("Workflow execution completed", "info", [
            "workflow_id" => $workflow_id,
            "execution_id" => $execution_id,
            "action_id" => $action_id,
            "executed_nodes" => array_keys($node_data)
        ]);

        // For action executions, make sure we check the result
        if ($action_id && $session_id) {
            // Schedule action result check only once
            wp_clear_scheduled_hook('wp_ai_workflows_check_action_result', array(
                'execution_id' => $execution_id,
                'action_id' => $action_id,
                'workflow_id' => $workflow_id,
                'session_id' => $session_id
            ));
            
            wp_schedule_single_event(
                time(),
                'wp_ai_workflows_check_action_result',
                array(
                    'execution_id' => $execution_id,
                    'action_id' => $action_id,
                    'workflow_id' => $workflow_id,
                    'session_id' => $session_id
                )
            );
            
            WP_AI_Workflows_Utilities::debug_log("Scheduled action result check", "info", [
                'execution_id' => $execution_id,
                'action_id' => $action_id,
                'session_id' => $session_id
            ]);
        }
    
        return array(
            'execution_id' => $execution_id,
            'node_data' => $node_data
        );
    }


    
    private static function get_last_executed_node($execution_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        
        $execution = $wpdb->get_row($wpdb->prepare("SELECT output_data FROM $table_name WHERE id = %d", $execution_id));
        
        if ($execution && $execution->output_data) {
            $output_data = json_decode($execution->output_data, true);
            if (is_array($output_data)) {
                end($output_data);
                return key($output_data);
            }
        }
        
        return null;
    }
    
    private static function find_edge_by_action($edges, $source_node_id, $action) {
        foreach ($edges as $edge) {
            if ($edge['source'] === $source_node_id && $edge['sourceHandle'] === $action) {
                return $edge;
            }
        }
        return null;
    }
    

    public static function execute_scheduled_workflow($workflow_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['workflow_id' => $workflow_id]);
        
        $result = self::execute_workflow($workflow_id);
        
        if (is_wp_error($result)) {
            WP_AI_Workflows_Utilities::debug_log("Scheduled workflow execution error: " . $result->get_error_message(), "error");
        } else {
            WP_AI_Workflows_Utilities::debug_log("Scheduled workflow executed successfully", "debug");
        }
        
        // Check if this is a recurring schedule
        $workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow_id);
        
        if ($workflow && isset($workflow['schedule']) && $workflow['schedule']['enabled']) {
            $next_run = self::calculate_next_run($workflow['schedule']);
            if ($next_run) {
                wp_schedule_single_event($next_run, 'wp_ai_workflows_execute_scheduled_workflow', array($workflow_id));
                WP_AI_Workflows_Utilities::debug_log("Rescheduled recurring workflow", "debug", [
                    'workflow_id' => $workflow_id,
                    'next_run' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s')
                ]);
            }
        }
    }


    public static function handle_webhook_trigger($request) {
        $node_id = $request->get_param('node_id');
        $key = $request->get_param('key');
    
        // Verify the webhook key
        $stored_key = get_option('wp_ai_workflows_webhook_' . $node_id);
        if ($key !== $stored_key) {
            WP_AI_Workflows_Utilities::debug_log("Invalid webhook key", "error", [
                'node_id' => $node_id,
                'provided_key' => substr($key, 0, 3) . '...' // Log partial key for debugging
            ]);
            return new WP_Error('invalid_webhook_key', 'Invalid webhook key', array('status' => 403));
        }
    
        // Process the webhook data
        $webhook_data = $request->get_json_params();
        if (empty($webhook_data)) {
            $webhook_data = $request->get_body_params();
        }
    
        // Store the webhook data for sampling
        set_transient('wp_ai_workflows_webhook_sample_' . $node_id, $webhook_data, 120); 
    
        WP_AI_Workflows_Utilities::debug_log("Webhook received with valid key", "debug", [
            'node_id' => $node_id,
            'data_size' => is_array($webhook_data) ? count($webhook_data) : strlen($webhook_data)
        ]);
    
        $workflows_triggered = 0;
        
        // Get all active workflows
        $workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status('active');
        
        foreach ($workflows as $workflow) {
            $trigger_node = self::find_trigger_node($workflow['nodes']);
            if ($trigger_node && 
                $trigger_node['id'] === $node_id && 
                $trigger_node['data']['triggerType'] === 'webhook') {
                
                // Schedule the workflow execution
                wp_schedule_single_event(time(), 'wp_ai_workflows_execute_webhook_workflow', array($workflow['id'], $webhook_data));
                $workflows_triggered++;
                
                WP_AI_Workflows_Utilities::debug_log("Webhook triggered workflow", "info", [
                    'workflow_id' => $workflow['id'],
                    'workflow_name' => $workflow['name'],
                    'node_id' => $node_id
                ]);
            }
        }
    
        if ($workflows_triggered === 0) {
            WP_AI_Workflows_Utilities::debug_log("No matching workflow found for node", "warning", [
                'node_id' => $node_id
            ]);
        }
    
        return new WP_REST_Response(array(
            'message' => 'Webhook processed successfully', 
            'workflows_triggered' => $workflows_triggered
        ), 200);
    }


    public function handle_gravity_forms_submission($entry, $form) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['entry' => $entry, 'form' => $form]);
        
        // Add detailed logging of the entry data
        WP_AI_Workflows_Utilities::debug_log("Gravity Forms submission data", "debug", [
            'raw_entry' => $entry,
            'form_id' => $form['id'],
            'entry_values' => array_map(function($field_id) use ($entry) {
                return [
                    'field_id' => $field_id,
                    'value' => rgar($entry, $field_id),
                    'value_type' => gettype(rgar($entry, $field_id))
                ];
            }, array_keys($entry))
        ]);
    
        // Get active workflows
        $workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status('active');
        
        foreach ($workflows as $workflow) {
            $trigger_node = self::find_trigger_node($workflow['nodes']);
            
            if ($trigger_node && 
                $trigger_node['data']['triggerType'] === 'gravityForms' && 
                $trigger_node['data']['selectedForm'] == $form['id']) {
                
                // Add logging for the selected fields
                WP_AI_Workflows_Utilities::debug_log("Selected form fields", "debug", [
                    'trigger_node_id' => $trigger_node['id'],
                    'selected_fields' => $trigger_node['data']['selectedFields']
                ]);
    
                $formatted_data = self::format_gravity_form_data($entry, $trigger_node['data']['selectedFields']);
                
                // Add logging for the formatted data
                WP_AI_Workflows_Utilities::debug_log("Formatted form data", "debug", [
                    'formatted_data' => $formatted_data
                ]);
    
                $session_id = isset($_COOKIE['wp_ai_workflows_session_id']) ? 
                    sanitize_text_field(wp_unslash($_COOKIE['wp_ai_workflows_session_id'])) : null;
                
                wp_schedule_single_event(
                    time(), 
                    'wp_ai_workflows_process_form_submission',
                    array(
                        'workflow_id' => $workflow['id'],
                        'formatted_data' => $formatted_data,
                        'session_id' => $session_id
                    )
                );
            }
        }
    }
    
    /**
     * Handle WPForms submission
     */
    public function handle_wpforms_submission($fields, $entry, $form_data) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, [
            'entry' => $entry, 
            'form_data' => $form_data
        ]);
        
        // Get active workflows
        $workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status('active');
        
        foreach ($workflows as $workflow) {
            $trigger_node = self::find_trigger_node($workflow['nodes']);
            
            if ($trigger_node && 
                $trigger_node['data']['triggerType'] === 'wpForms' && 
                $trigger_node['data']['selectedForm'] == $form_data['id']) {
                
                // Format the data
                $formatted_data = self::format_wpforms_data($entry, $trigger_node['data']['selectedFields']);
                
                $session_id = isset($_COOKIE['wp_ai_workflows_session_id']) ? 
                    sanitize_text_field(wp_unslash($_COOKIE['wp_ai_workflows_session_id'])) : null;
                
                wp_schedule_single_event(
                    time(), 
                    'wp_ai_workflows_process_form_submission',
                    array(
                        'workflow_id' => $workflow['id'],
                        'formatted_data' => $formatted_data,
                        'session_id' => $session_id
                    )
                );
            }
        }
    }
    
    /**
     * Handle Contact Form 7 submission
     */
    public function handle_cf7_submission($contact_form) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, [
            'form_id' => $contact_form->id()
        ]);
    
        // Get the submission data
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            WP_AI_Workflows_Utilities::debug_log("No submission instance found", "error");
            return;
        }
    
        $posted_data = $submission->get_posted_data();
        
        // Log the received data
        WP_AI_Workflows_Utilities::debug_log("CF7 submission data received", "debug", [
            'form_id' => $contact_form->id(),
            'posted_data' => $posted_data
        ]);
    
        // Get active workflows
        $workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status('active');
        
        foreach ($workflows as $workflow) {
            $trigger_node = self::find_trigger_node($workflow['nodes']);
            
            // Check if this is a CF7 trigger node and matches the form ID
            if ($trigger_node && 
                $trigger_node['data']['triggerType'] === 'contactForm7' && 
                $trigger_node['data']['selectedForm'] === 'contact-form-' . $contact_form->id()) {
                
                // Format the data
                $formatted_data = array();
                foreach ($trigger_node['data']['selectedFields'] as $field) {
                    if (isset($posted_data[$field['id']])) {
                        $field_value = $posted_data[$field['id']];
                        // Handle array values (like checkboxes)
                        if (is_array($field_value)) {
                            $field_value = implode(', ', $field_value);
                        }
                        $formatted_data[$field['label']] = $field_value;
                    }
                }
    
                WP_AI_Workflows_Utilities::debug_log("Triggering workflow for CF7 submission", "debug", [
                    'workflow_id' => $workflow['id'],
                    'formatted_data' => $formatted_data
                ]);
                
                wp_schedule_single_event(
                    time(), 
                    'wp_ai_workflows_process_form_submission',
                    array(
                        'workflow_id' => $workflow['id'],
                        'formatted_data' => $formatted_data,
                        'session_id' => isset($_COOKIE['wp_ai_workflows_session_id']) ? 
                            sanitize_text_field(wp_unslash($_COOKIE['wp_ai_workflows_session_id'])) : null
                    )
                );
            }
        }
    }
    
    /**
     * Handle Ninja Forms submission
     */
    public function handle_ninja_forms_submission($form_data) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, [
            'form_id' => $form_data['form_id']
        ]);
    
        // Get active workflows
        $workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status('active');
        
        foreach ($workflows as $workflow) {
            $trigger_node = self::find_trigger_node($workflow['nodes']);
            
            if ($trigger_node && 
                $trigger_node['data']['triggerType'] === 'ninjaForms' && 
                $trigger_node['data']['selectedForm'] === 'ninja-form-' . $form_data['form_id']) {
                
                // Format the data
                $formatted_data = array();
                $fields_submitted = $form_data['fields'];
                
                foreach ($trigger_node['data']['selectedFields'] as $field) {
                    foreach ($fields_submitted as $submitted_field) {
                        if ($field['id'] === $submitted_field['key']) {
                            $field_value = $submitted_field['value'];
                            // Handle array values (like checkboxes)
                            if (is_array($field_value)) {
                                $field_value = implode(', ', $field_value);
                            }
                            $formatted_data[$field['label']] = $field_value;
                            break;
                        }
                    }
                }
                
                $session_id = isset($_COOKIE['wp_ai_workflows_session_id']) ? 
                    sanitize_text_field(wp_unslash($_COOKIE['wp_ai_workflows_session_id'])) : null;
                
                wp_schedule_single_event(
                    time(), 
                    'wp_ai_workflows_process_form_submission',
                    array(
                        'workflow_id' => $workflow['id'],
                        'formatted_data' => $formatted_data,
                        'session_id' => $session_id
                    )
                );
    
                WP_AI_Workflows_Utilities::debug_log("Triggering workflow for Ninja Forms submission", "debug", [
                    'workflow_id' => $workflow['id'],
                    'formatted_data' => $formatted_data
                ]);
            }
        }
    }


public function process_form_submission($workflow_id, $formatted_data, $session_id) {
    self::execute_workflow($workflow_id, $formatted_data, null, $session_id);
}

public static function execute_webhook_workflow($workflow_id, $trigger_data) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['workflow_id' => $workflow_id, 'trigger_data' => $trigger_data]);

    // Extract session_id from trigger_data if it exists
    $session_id = null;
    if (is_array($trigger_data) && isset($trigger_data['session_id'])) {
        $session_id = sanitize_text_field($trigger_data['session_id']);
        WP_AI_Workflows_Utilities::debug_log("Found session_id in webhook data", "debug", [
            'session_id' => $session_id
        ]);
    }

    // Pass session_id as the 4th parameter
    $result = self::execute_workflow($workflow_id, $trigger_data, null, $session_id);
    
    if (is_wp_error($result)) {
        WP_AI_Workflows_Utilities::debug_log("Webhook workflow execution error: " . $result->get_error_message(), "error");
    } else {
        WP_AI_Workflows_Utilities::debug_log("Webhook workflow executed successfully", "debug", [
            'with_session' => !empty($session_id)
        ]);
    }
}

public static function generate_webhook_url($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    $node_id = $request->get_param('nodeId');
    $webhook_key = wp_generate_password(12, false);
    update_option('wp_ai_workflows_webhook_' . $node_id, $webhook_key);

    $webhook_url = rest_url('wp-ai-workflows/v1/webhook/' . $node_id);
    $webhook_url = add_query_arg('key', $webhook_key, $webhook_url);

    return new WP_REST_Response(array('webhookUrl' => $webhook_url), 200);
}

public static function save_output($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    $params = $request->get_json_params();
    $node_id = $params['nodeId'];
    $output_data = $params['outputData'];

    $output_id = uniqid('output_');

    $saved_outputs = get_option('wp_ai_workflows_outputs', array());
    $saved_outputs[$output_id] = array(
        'node_id' => $node_id,
        'data' => $output_data,
        'timestamp' => current_time('mysql')
    );
    update_option('wp_ai_workflows_outputs', $saved_outputs);

    return new WP_REST_Response(array('id' => $output_id), 200);
}

public static function get_outputs($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $table = $wpdb->prefix . $request->get_param('table');

    WP_AI_Workflows_Utilities::debug_log("Attempting to fetch outputs", "debug", ['table' => $table]);

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        WP_AI_Workflows_Utilities::debug_log("Table does not exist", "error", ['table' => $table]);
        return new WP_Error('invalid_table', 'The specified table does not exist', array('status' => 400));
    }

    $outputs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100"));
    WP_AI_Workflows_Utilities::debug_log("Outputs fetched", "debug", ['count' => count($outputs)]);
    return new WP_REST_Response($outputs, 200);
}

public static function get_latest_output($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $node_id = $request->get_param('node_id');

    $table_name = $wpdb->prefix . 'wp_ai_workflows_outputs';

    $latest_output = $wpdb->get_var($wpdb->prepare(
        "SELECT output_data FROM {$wpdb->prefix}wp_ai_workflows_outputs WHERE node_id = %s ORDER BY created_at DESC LIMIT 1",
        $node_id
    ));

    if ($latest_output) {
        return new WP_REST_Response(array('content' => $latest_output), 200);
    }

    $saved_outputs = get_option('wp_ai_workflows_outputs', array());
    if (isset($saved_outputs[$node_id])) {
        $output = $saved_outputs[$node_id]['data'];
        return new WP_REST_Response(array('content' => $output), 200);
    }

    return new WP_REST_Response(array('content' => ''), 200);
}

public static function get_templates($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_templates';
    $templates = $wpdb->get_results($wpdb->prepare("SELECT id, name, description, created_at, updated_at FROM $table_name"));
    return new WP_REST_Response($templates, 200);
}

public static function create_template($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_templates';
    $template_data = $request->get_json_params();

    // Ensure workflow_data is valid JSON
    $workflow_data = json_decode($template_data['workflow_data']);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('invalid_json', 'Invalid workflow data JSON', array('status' => 400));
    }

    $result = $wpdb->insert(
        $table_name,
        array(
            'name' => sanitize_text_field($template_data['name']),
            'description' => sanitize_textarea_field($template_data['description']),
            'workflow_data' => $template_data['workflow_data'],  // Already JSON string
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        )
    );

    if ($result === false) {
        return new WP_Error('template_creation_failed', 'Failed to create template', array('status' => 500));
    }

    return new WP_REST_Response(array('id' => $wpdb->insert_id), 201);
}

public static function get_template($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_templates';
    $template_id = $request['id'];

    $template = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $template_id));

    if (!$template) {
        return new WP_Error('template_not_found', 'Template not found', array('status' => 404));
    }

    $template->workflow_data = json_decode($template->workflow_data);
    return new WP_REST_Response($template, 200);
}

public static function update_template($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_templates';
    $template_id = $request['id'];
    $template_data = $request->get_json_params();

    $result = $wpdb->update(
        $table_name,
        array(
            'name' => $template_data['name'],
            'description' => $template_data['description'],
            'workflow_data' => wp_json_encode($template_data['workflow_data']),
            'updated_at' => current_time('mysql')
        ),
        array('id' => $template_id)
    );

    if ($result === false) {
        return new WP_Error('template_update_failed', 'Failed to update template', array('status' => 500));
    }

    return new WP_REST_Response(array('message' => 'Template updated successfully'), 200);
}

public static function delete_template($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_templates';
    $template_id = $request['id'];

    $result = $wpdb->delete($table_name, array('id' => $template_id));

    if ($result === false) {
        return new WP_Error('template_deletion_failed', 'Failed to delete template', array('status' => 500));
    }

    return new WP_REST_Response(array('message' => 'Template deleted successfully'), 200);
}

// Helper methods

public static function clear_scheduled_events($workflow_id) {
    wp_clear_scheduled_hook('wp_ai_workflows_execute_scheduled', array($workflow_id));
    }

    public static function calculate_next_run($schedule) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['schedule' => $schedule]);

    $now = time(); // Use UTC time
    $interval = $schedule['interval'];
    $unit = $schedule['unit'];
    $end_date = isset($schedule['endDate']) ? strtotime($schedule['endDate']) : false;

    switch ($unit) {
        case 'minute':
            $next_run = $now + ($interval * MINUTE_IN_SECONDS);
            break;
        case 'hour':
            $next_run = $now + ($interval * HOUR_IN_SECONDS);
            break;
        case 'day':
            $next_run = $now + ($interval * DAY_IN_SECONDS);
            break;
        case 'week':
            $next_run = $now + ($interval * WEEK_IN_SECONDS);
            break;
        case 'month':
            $next_run = strtotime("+{$interval} months", $now);
            break;
        default:
            return false;
    }

    if ($end_date && $next_run > $end_date) {
        return false;
    }

    return $next_run;
}

public static function get_workflow_name($workflow_id) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['workflow_id' => $workflow_id]);

    $workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow_id);
    
    if ($workflow && isset($workflow['name'])) {
        return $workflow['name'];
    }
    
    return 'Unnamed Workflow';
}

public static function get_executions($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';

    $page = $request->get_param('page') ?: 1;
    $per_page = $request->get_param('pageSize') ?: 10;
    $search = $request->get_param('search') ?: '';

    $offset = ($page - 1) * $per_page;

    $where = '';
    $where_args = [];
    if (!empty($search)) {
        $where = "WHERE workflow_name LIKE %s";
        $where_args[] = '%' . $wpdb->esc_like($search) . '%';
    }

    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wp_ai_workflows_executions $where",
        $where_args
    ));

    $query = $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wp_ai_workflows_executions $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
        array_merge($where_args, [$per_page, $offset])
    );
    $executions = $wpdb->get_results($query);

    $wp_timezone = wp_timezone();
    $timezone_offset = $wp_timezone->getOffset(new DateTime()) / 3600; // Convert seconds to hours

    foreach ($executions as &$execution) {
        $execution->input_data = !is_null($execution->input_data) ? json_decode($execution->input_data) : null;
        $execution->output_data = !is_null($execution->output_data) ? json_decode($execution->output_data) : [];
        
        // Ensure output_data is always an array
        if (is_null($execution->output_data) || !is_array($execution->output_data)) {
            $execution->output_data = is_null($execution->output_data) ? [] : [$execution->output_data];
        }
        
        // Extract the latest status message
        $latest_status = !empty($execution->output_data) ? end($execution->output_data) : null;
        $execution->latest_status = is_object($latest_status) && isset($latest_status->message) ? $latest_status->message : '';

        // Remove full output_data from the response to reduce payload size
        unset($execution->output_data);

        // Add next execution time
        if ($execution->status === 'scheduled') {
            $execution->next_execution = $execution->scheduled_at;
        } else {
            $workflow = self::get_workflow_by_id($execution->workflow_id);
            if ($workflow && isset($workflow['schedule']) && $workflow['schedule']['enabled']) {
                $next_run = self::calculate_next_run($workflow['schedule']);
                $execution->next_execution = $next_run ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s') : null;
            } else {
                $execution->next_execution = null;
            }
        }

        // Format dates
        $execution->created_at = wp_date('Y-m-d H:i:s', strtotime($execution->created_at), $wp_timezone);
        $execution->updated_at = wp_date('Y-m-d H:i:s', strtotime($execution->updated_at), $wp_timezone);
        $execution->scheduled_at = $execution->scheduled_at ? wp_date('Y-m-d H:i:s', strtotime($execution->scheduled_at), $wp_timezone) : null;
    }

    return new WP_REST_Response(array(
        'executions' => $executions,
        'total' => $total,
        'timezone_offset' => $timezone_offset
    ), 200);
}

public static function get_workflow_by_id($workflow_id) {
    return WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($workflow_id);
}

public static function get_execution($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $id = $request->get_param('id');

    $execution = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wp_ai_workflows_executions WHERE id = %d",
        $id
    ));

    if (!$execution) {
        return new WP_Error('not_found', 'Execution not found', array('status' => 404));
    }

    return new WP_REST_Response($execution, 200);
}

public static function stop_and_delete_execution($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
    $id = $request->get_param('id');

    // First, get the execution details
    $execution = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

    if (!$execution) {
        return new WP_Error('not_found', 'Execution not found', array('status' => 404));
    }

    // If the execution is still processing, we need to stop it
    if ($execution->status === 'processing') {
        // Clear any scheduled events for this execution
        wp_clear_scheduled_hook('wp_ai_workflows_execute_scheduled', array($execution->workflow_id));
        
        // If you're using a queue system, you might need to remove the job from the queue here
        // This depends on your specific implementation
        
        // Update the status to 'terminated'
        $wpdb->update(
            $table_name,
            array('status' => 'terminated', 'updated_at' => current_time('mysql')),
            array('id' => $id)
        );
    }

    // Now delete the record
    $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));

    if ($result === false) {
        return new WP_Error('delete_failed', 'Failed to delete execution', array('status' => 500));
    }

    return new WP_REST_Response(null, 204);
}

public static function get_downstream_nodes($node_id, $edges, $handle = null) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node_id' => $node_id, 'handle' => $handle]);

    $downstream = [];
    $queue = [['id' => $node_id, 'handle' => $handle]];
    $visited = [];

    WP_AI_Workflows_Utilities::debug_log("Starting get_downstream_nodes", "debug", ["start_node" => $node_id, "handle" => $handle]);

    while (!empty($queue)) {
        $current = array_shift($queue);
        $current_id = $current['id'];
        $current_handle = $current['handle'];

        if (in_array($current_id, $visited)) {
            continue;
        }
        $visited[] = $current_id;

        WP_AI_Workflows_Utilities::debug_log("Processing node", "debug", ["current_node" => $current_id, "handle" => $current_handle]);

        foreach ($edges as $edge) {
            // If we're processing the first node with a specific handle, only follow edges with that handle
            // For subsequent nodes, follow all edges regardless of handle
            if ($edge['source'] === $current_id &&
                ($current_handle === null || $current_id !== $node_id || $edge['sourceHandle'] === $current_handle)) {
                $target = $edge['target'];
                if (!in_array($target, $downstream)) {
                    $downstream[] = $target;
                    $queue[] = ['id' => $target, 'handle' => null]; // Future nodes don't need handle filtering
                    WP_AI_Workflows_Utilities::debug_log("Found downstream node", "debug", [
                        "source" => $current_id, 
                        "target" => $target, 
                        "handle" => $edge['sourceHandle']
                    ]);
                }
            }
        }
    }

    WP_AI_Workflows_Utilities::debug_log("Completed get_downstream_nodes", "debug", [
        "start_node" => $node_id, 
        "handle" => $handle, 
        "downstream_nodes" => $downstream
    ]);
    return array_unique($downstream);
}

public static function topological_sort($nodes, $edges) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['nodes_count' => count($nodes), 'edges_count' => count($edges)]);

    $graph = array();
    $sorted = array();
    $visited = array();

    // Build the graph
    foreach ($nodes as $node) {
        $graph[$node['id']] = array();
    }
    foreach ($edges as $edge) {
        $graph[$edge['source']][] = $edge['target'];
    }

    // Depth-first search function
    $dfs = function($node) use (&$graph, &$visited, &$sorted, &$dfs) {
        $visited[$node] = true;
        if (isset($graph[$node])) {
            foreach ($graph[$node] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $dfs($neighbor);
                }
            }
        }
        array_unshift($sorted, $node);
    };

    // Perform DFS for each node
    foreach ($nodes as $node) {
        if (!isset($visited[$node['id']])) {
            $dfs($node['id']);
        }
    }

    // Map the sorted node IDs back to their full node objects
    $sorted_nodes = array_map(function($id) use ($nodes) {
        return array_values(array_filter($nodes, function($node) use ($id) {
            return $node['id'] === $id;
        }))[0];
    }, $sorted);

    return $sorted_nodes;
}

private static function trigger_dependent_workflows($execution_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
    
    // Get the completed execution data
    $execution = $wpdb->get_row($wpdb->prepare(
        "SELECT workflow_id, output_data FROM $table_name WHERE id = %d",
        $execution_id
    ));

    if (!$execution) {
        return;
    }

    // Find workflows that depend on this one
    $workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status('active');
    
    foreach ($workflows as $workflow) {
        $trigger_node = self::find_trigger_node($workflow['nodes']);
        if ($trigger_node && 
            isset($trigger_node['data']['triggerType']) && 
            $trigger_node['data']['triggerType'] === 'workflowOutput' &&
            isset($trigger_node['data']['selectedWorkflow']) && 
            $trigger_node['data']['selectedWorkflow'] === $execution->workflow_id) {

            // Execute the dependent workflow with the output data from the completed workflow
            WP_AI_Workflows_Utilities::debug_log("Triggering dependent workflow", "info", [
                'source_workflow' => $execution->workflow_id,
                'dependent_workflow' => $workflow['id']
            ]);

            wp_schedule_single_event(
                time(),
                'wp_ai_workflows_execute_workflow',
                array(
                    'workflow_id' => $workflow['id'],
                    'input_data' => $execution->output_data,
                    'triggered_by' => $execution->workflow_id
                )
            );
        }
    }
}

public static function format_gravity_form_data($entry, $selected_fields) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['entry' => $entry, 'selected_fields' => $selected_fields]);

    $formatted_data = array();
    foreach ($selected_fields as $field) {
        $field_id = $field['id'];
        $field_label = $field['label'];
        
        WP_AI_Workflows_Utilities::debug_log("Processing field", "debug", ["field_id" => $field_id, "field_label" => $field_label]);
        
        if ($field_label === 'Name') {
            $first_name = rgar($entry, $field_id . '.3');
            $last_name = rgar($entry, $field_id . '.6');
            $full_name = trim($first_name . ' ' . $last_name);
            if (!empty($full_name)) {
                $formatted_data[$field_label] = $full_name;
                WP_AI_Workflows_Utilities::debug_log("Processed Name field", "debug", ["full_name" => $full_name]);
            } else {
                WP_AI_Workflows_Utilities::debug_log("Empty Name field", "warning", ["first_name" => $first_name, "last_name" => $last_name]);
            }
        } else {
            // First try direct field value
            $field_value = rgar($entry, $field_id);
            
            if ($field_value !== '') {
                $formatted_data[$field_label] = $field_value;
                WP_AI_Workflows_Utilities::debug_log("Processed field", "debug", [
                    "field_label" => $field_label, 
                    "field_value" => $field_value
                ]);
            } else {
                // Check for checkbox values (1.1, 1.2, etc)
                $checkbox_values = array();
                foreach ($entry as $key => $value) {
                    if (preg_match("/^{$field_id}\./", $key) && !empty($value)) {
                        $checkbox_values[] = $value;
                    }
                }
                
                if (!empty($checkbox_values)) {
                    $formatted_data[$field_label] = $checkbox_values;
                    WP_AI_Workflows_Utilities::debug_log("Processed checkbox field", "debug", [
                        "field_label" => $field_label,
                        "values" => $checkbox_values
                    ]);
                } else {
                    WP_AI_Workflows_Utilities::debug_log("Empty field value", "warning", [
                        "field_label" => $field_label
                    ]);
                }
            }
        }
    }

    WP_AI_Workflows_Utilities::debug_log("Formatted data result", "debug", ["formatted_data" => $formatted_data]);
    return $formatted_data;
}

public static function format_wpforms_data($entry, $selected_fields) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, [
        'entry' => $entry, 
        'selected_fields' => $selected_fields
    ]);

    $formatted_data = array();
    foreach ($selected_fields as $field) {
        $field_id = $field['id'];
        $field_label = $field['label'];
        
        WP_AI_Workflows_Utilities::debug_log("Processing field", "debug", [
            "field_id" => $field_id,
            "field_label" => $field_label,
            "field_type" => $field['type']
        ]);
        
        if ($field['type'] === 'name') {
            $first_name = isset($entry['fields'][$field_id]['first']) ? $entry['fields'][$field_id]['first'] : '';
            $last_name = isset($entry['fields'][$field_id]['last']) ? $entry['fields'][$field_id]['last'] : '';
            $full_name = trim($first_name . ' ' . $last_name);
            if (!empty($full_name)) {
                $formatted_data[$field_label] = $full_name;
                WP_AI_Workflows_Utilities::debug_log("Processed Name field", "debug", ["full_name" => $full_name]);
            }
        } else if ($field['type'] === 'checkbox') {
            $field_value = isset($entry['fields'][$field_id]) ? (array)$entry['fields'][$field_id] : array();
            if (!empty($field_value)) {
                $formatted_data[$field_label] = $field_value;
                WP_AI_Workflows_Utilities::debug_log("Processed checkbox field", "debug", ["values" => $field_value]);
            }
        } else {
            $field_value = isset($entry['fields'][$field_id]) ? $entry['fields'][$field_id] : '';
            if ($field_value !== '') {
                $formatted_data[$field_label] = $field_value;
                WP_AI_Workflows_Utilities::debug_log("Processed field", "debug", [
                    "field_label" => $field_label,
                    "field_value" => $field_value
                ]);
            }
        }
    }

    WP_AI_Workflows_Utilities::debug_log("Formatted data result", "debug", ["formatted_data" => $formatted_data]);
    return $formatted_data;
}

public static function find_trigger_node($nodes) {
    //WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['nodes_count' => count($nodes)]);
    if (!is_array($nodes)) {
        return null;
    }

    foreach ($nodes as $node) {
        if ($node['type'] === 'trigger') {
            return $node;
        }
    }
    return null;
}

public static function find_node_by_id($nodes, $id) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['id' => $id]);

    foreach ($nodes as $node) {
        if ($node['id'] == $id) {
            return $node;
        }
    }
    return null;
}

public static function pause_execution($execution_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
    $wpdb->update(
        $table_name,
        array('status' => 'paused'),
        array('id' => $execution_id)
    );
}

public static function resume_execution($execution_id, $node_id, $human_input_result, $action) {
    WP_AI_Workflows_Utilities::debug_log("Resuming workflow", "info", [
        "execution_id" => $execution_id,
        "node_id" => $node_id,
        "action" => $action
    ]);

    global $wpdb;
    $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
    
    $execution = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $executions_table WHERE id = %d",
        $execution_id
    ));
    
    if (!$execution) {
        WP_AI_Workflows_Utilities::debug_log("Execution not found", "error", ["execution_id" => $execution_id]);
        return new WP_Error('execution_not_found', 'Execution not found');
    }
    
    $workflow_id = $execution->workflow_id;
    $node_data = json_decode($execution->output_data, true) ?: array();
    
    // Update the human input node result
    $node_data[$node_id] = array(
        'type' => 'humanInput',
        'content' => $human_input_result,
        'action' => $action
    );
    
    // Update the execution record with the new node data
    $wpdb->update(
        $executions_table,
        array(
            'output_data' => wp_json_encode($node_data),
            'status' => 'processing',
            'updated_at' => current_time('mysql')
        ),
        array('id' => $execution_id)
    );
    
    // Resume workflow execution
    return self::execute_workflow($workflow_id, null, $execution_id, null, $node_id, $action);
}

public static function complete_execution($execution_id, $status) {
    WP_AI_Workflows_Utilities::debug_log("Completing execution", "info", [
        'execution_id' => $execution_id,
        'status' => $status
    ]);

    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
    
    $wpdb->update(
        $table_name,
        array('status' => $status, 'updated_at' => current_time('mysql')),
        array('id' => $execution_id)
    );

    // Additional cleanup or notification logic can be added here
}

public static function revert_execution($execution_id) {
    WP_AI_Workflows_Utilities::debug_log("Reverting execution", "info", [
        'execution_id' => $execution_id
    ]);

    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
    
    $execution = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $execution_id));
    
    if ($execution) {
        $workflow_id = $execution->workflow_id;
        $input_data = json_decode($execution->input_data, true);
        $output_data = json_decode($execution->output_data, true) ?: array();
        
        // Find the previous human input node
        $previous_human_input_node = self::find_previous_human_input_node($output_data);
        
        if ($previous_human_input_node) {
            // Update status to processing and clear output data after the previous human input node
            $reverted_output_data = array_slice($output_data, 0, array_search($previous_human_input_node, array_keys($output_data)) + 1);
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'processing',
                    'output_data' => wp_json_encode($reverted_output_data),
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $execution_id)
            );
            
            WP_AI_Workflows_Utilities::debug_log("Execution reverted, re-executing workflow from previous human input node", "info", [
                'execution_id' => $execution_id,
                'workflow_id' => $workflow_id,
                'previous_human_input_node' => $previous_human_input_node
            ]);

            return self::execute_workflow($workflow_id, $input_data, $execution_id);
        } else {
            WP_AI_Workflows_Utilities::debug_log("No previous human input node found, cannot revert", "error", ["execution_id" => $execution_id]);
            return false;
        }
    } else {
        WP_AI_Workflows_Utilities::debug_log("Failed to revert execution: Execution not found", "error", ["execution_id" => $execution_id]);
        return false;
    }
}

private static function find_previous_human_input_node($output_data) {
    $human_input_nodes = array_filter(array_keys($output_data), function($key) use ($output_data) {
        return isset($output_data[$key]['type']) && $output_data[$key]['type'] === 'humanInput';
    });
    if (count($human_input_nodes) > 1) {
        return $human_input_nodes[count($human_input_nodes) - 2]; // Return the second last human input node
    }
    return null; // Return null if there's no previous human input node
}


private static function find_last_human_input_node($output_data) {
    $human_input_nodes = array_filter(array_keys($output_data), function($key) use ($output_data) {
        return isset($output_data[$key]['type']) && $output_data[$key]['type'] === 'humanInput';
    });
    return end($human_input_nodes);
}

public function handle_publish_post_trigger($post_ID, $post) {
    $this->handle_wp_core_trigger('publish_post', array(
        'post_id' => $post_ID,
        'postType' => $post->post_type,
        'post_data' => $post
    ));
}

public function handle_user_register_trigger($user_id) {
    $user = get_userdata($user_id);
    $this->handle_wp_core_trigger('user_register', array(
        'user_id' => $user_id,
        'userRole' => $user->roles[0],
        'user_data' => $user
    ));
}

public function handle_insert_comment_trigger($comment_ID, $comment_object) {
    $this->handle_wp_core_trigger('wp_insert_comment', array(
        'comment_id' => $comment_ID,
        'commentType' => $comment_object->comment_type,
        'postType' => get_post_type($comment_object->comment_post_ID),
        'comment_data' => $comment_object
    ));
}

public function handle_user_login_trigger($user_login, $user) {
    $trigger_data = array(
        'user_login' => $user_login,
        'userRole' => $user->roles[0],
        'user_data' => $user
    );
    
    // Schedule immediate background processing
    wp_schedule_single_event(
        time(),
        'wp_ai_workflows_process_login_trigger',
        array('trigger_data' => $trigger_data)
    );
}

public function process_login_trigger($trigger_data) {
    $this->handle_wp_core_trigger('wp_login', $trigger_data);
}


private static function end_response_keep_processing() {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return true;
    }
    return false;
}

public function handle_post_status_transition_trigger($new_status, $old_status, $post) {
    $this->handle_wp_core_trigger('transition_post_status', array(
        'post_id' => $post->ID,
        'toStatus' => $new_status,     
        'fromStatus' => $old_status,   
        'postType' => $post->post_type, 
        'post_data' => $post
    ));
}

private function handle_wp_core_trigger($trigger_type, $trigger_data) {
    $workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status('active');
    
    foreach ($workflows as $workflow) {
        $trigger_node = self::find_trigger_node($workflow['nodes']);
        if ($trigger_node && 
            $trigger_node['data']['triggerType'] === 'wpCore' && 
            $trigger_node['data']['selectedWpCoreTrigger'] === $trigger_type) {
            
            if ($this->check_wp_core_trigger_conditions($trigger_node['data']['wpCoreTriggerConditions'], $trigger_data)) {
                // Format the data
                $formatted_data = $this->format_wp_core_trigger_data($trigger_type, $trigger_data);
                $session_id = wp_generate_uuid4();

                WP_AI_Workflows_Utilities::debug_log("WP Core trigger initiating workflow", "debug", [
                    "workflow_id" => $workflow['id'],
                    "workflow_name" => $workflow['name']
                ]);

                // Let execute_workflow handle the execution record creation and status check
                self::execute_workflow($workflow['id'], $formatted_data, null, $session_id);
            }
        }
    }
}

private function check_wp_core_trigger_conditions($conditions, $trigger_data) {
    foreach ($conditions as $key => $value) {
        if ($value !== 'any' && isset($trigger_data[$key])) {
            if ($trigger_data[$key] !== $value) {
                return false;
            }
        }
    }
    return true;
}

private function format_wp_core_trigger_data($trigger_type, $trigger_data) {
    $formatted_data = array(
        'trigger_type' => $trigger_type,
        'timestamp' => current_time('mysql'),
    );

    switch ($trigger_type) {
        case 'publish_post':
            $post = get_post($trigger_data['post_id']);
            $formatted_data = array_merge($formatted_data, array(
                'post_id' => $trigger_data['post_id'],
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_author' => $post->post_author,
                'post_date' => $post->post_date,
                'post_status' => $post->post_status,
                'post_categories' => wp_get_post_categories($trigger_data['post_id'], array('fields' => 'names')),
                'post_tags' => wp_get_post_tags($trigger_data['post_id'], array('fields' => 'names')),
                'post_url' => get_permalink($trigger_data['post_id']),
            ));
            break;

        case 'user_register':
            $user = get_userdata($trigger_data['user_id']);
            $formatted_data = array_merge($formatted_data, array(
                'user_id' => $trigger_data['user_id'],
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'user_registered' => $user->user_registered,
                'display_name' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_role' => !empty($user->roles) ? $user->roles[0] : '',
            ));
            break;

        case 'wp_insert_comment':
            $comment = get_comment($trigger_data['comment_id']);
            $formatted_data = array_merge($formatted_data, array(
                'comment_id' => $trigger_data['comment_id'],
                'comment_post_id' => $comment->comment_post_ID,
                'comment_author' => $comment->comment_author,
                'comment_author_email' => $comment->comment_author_email,
                'comment_author_url' => $comment->comment_author_url,
                'comment_content' => $comment->comment_content,
                'comment_type' => $comment->comment_type,
                'comment_parent' => $comment->comment_parent,
                'user_id' => $comment->user_id,
                'comment_date' => $comment->comment_date,
                'comment_approved' => $comment->comment_approved,
                'post_type' => get_post_type($comment->comment_post_ID),
            ));
            break;

        case 'wp_login':
            $user = get_user_by('login', $trigger_data['user_login']);
            $formatted_data = array_merge($formatted_data, array(
                'user_id' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'display_name' => $user->display_name,
                'user_role' => !empty($user->roles) ? $user->roles[0] : '',
            ));
            break;

        case 'transition_post_status':
            $post = get_post($trigger_data['post_id']);
            $formatted_data = array_merge($formatted_data, array(
                'post_id' => $trigger_data['post_id'],
                'post_type' => $trigger_data['postType'],
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,  
                'post_excerpt' => $post->post_excerpt,  
                'post_author' => $post->post_author,
                'old_status' => $trigger_data['fromStatus'],
                'new_status' => $trigger_data['toStatus'],
                'post_date' => $post->post_date,
                'post_modified' => $post->post_modified,
                'post_url' => get_permalink($trigger_data['post_id']),
                'post_categories' => wp_get_post_categories($trigger_data['post_id'], array('fields' => 'names')), 
                'post_tags' => wp_get_post_tags($trigger_data['post_id'], array('fields' => 'names')), 
            ));
            break;

        default:
            // For any unhandled trigger types, just return the original data
            $formatted_data = array_merge($formatted_data, $trigger_data);
            break;
    }

    return $formatted_data;
}



public static function get_execution_status($request) {
    global $wpdb;
    $execution_id = $request['id'];
    
    $execution = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wp_ai_workflows_executions WHERE id = %d",
        $execution_id
    ));

    if (!$execution) {
        return new WP_Error('not_found', 'Execution not found', array('status' => 404));
    }

    $output_data = json_decode($execution->output_data, true);
    
    return new WP_REST_Response(array(
        'status' => $execution->status,
        'current_node' => $execution->current_node,
        'nodes' => $output_data,
        'is_complete' => $execution->status === 'completed'
    ), 200);
}

private function schedule_rss_check($workflow_id, $interval) {
    $schedule_key = 'wp_ai_workflows_rss_check_' . $workflow_id;
    
    if (!wp_next_scheduled($schedule_key)) {
        $interval_seconds = $this->get_interval_seconds($interval);
        wp_schedule_event(time(), $interval_seconds, $schedule_key, array($workflow_id));
    }
}

private function get_interval_seconds($interval) {
    $intervals = [
        '5min' => 300,
        '15min' => 900,
        '30min' => 1800,
        '1hour' => 3600,
        '6hours' => 21600,
        '24hours' => 86400
    ];
    
    return $intervals[$interval] ?? 900; // Default to 15 minutes
}

public function register_rss_schedules() {
    add_filter('cron_schedules', function($schedules) {
        $schedules['wp_ai_workflows_5min'] = array(
            'interval' => 300,
            'display' => 'Every 5 minutes'
        );
        $schedules['wp_ai_workflows_15min'] = array(
            'interval' => 900,
            'display' => 'Every 15 minutes'
        );
        $schedules['wp_ai_workflows_30min'] = array(
            'interval' => 1800,
            'display' => 'Every 30 minutes'
        );
        $schedules['wp_ai_workflows_1hour'] = array(
            'interval' => 3600,
            'display' => 'Every Hour'
        );
        $schedules['wp_ai_workflows_6hours'] = array(
            'interval' => 21600,
            'display' => 'Every 6 Hours'
        );
        $schedules['wp_ai_workflows_24hours'] = array(
            'interval' => 86400,
            'display' => 'Every 24 Hours'
        );
        return $schedules;
    });
}

public function handle_rss_check($workflow_id) {
    $workflow = $this->get_workflow_by_id($workflow_id);
    if (!$workflow || $workflow['status'] !== 'active') {
        return;
    }

    $trigger_node = $this->find_trigger_node($workflow['nodes']);
    if ($trigger_node && $trigger_node['data']['triggerType'] === 'rss') {
        $this->execute_workflow($workflow_id);
    }
    }

   // Helper methods for API Call nodes

   private static function prepare_api_call_node_for_save($node) {
    if (!empty($node['data']['auth'])) {
        try {
            WP_AI_Workflows_Utilities::debug_log("Preparing API node for save", "debug", [
                'node_id' => $node['id'],
                'auth_type' => $node['data']['auth']['type']
            ]);

            switch ($node['data']['auth']['type']) {
                case 'basic':
                    if (!empty($node['data']['auth']['username']) && 
                        $node['data']['auth']['username'] !== '********') {
                        $encrypted = WP_AI_Workflows_Encryption::encrypt($node['data']['auth']['username']);
                        if ($encrypted === false) {
                            throw new Exception('Username encryption failed');
                        }
                        $node['data']['auth']['username'] = 'enc_' . $encrypted;
                    }
                    if (!empty($node['data']['auth']['password']) && 
                        $node['data']['auth']['password'] !== '********') {
                        $encrypted = WP_AI_Workflows_Encryption::encrypt($node['data']['auth']['password']);
                        if ($encrypted === false) {
                            throw new Exception('Password encryption failed');
                        }
                        $node['data']['auth']['password'] = 'enc_' . $encrypted;
                    }
                    break;

                case 'bearer':
                    if (!empty($node['data']['auth']['token']) && 
                        $node['data']['auth']['token'] !== '********') {
                        $encrypted = WP_AI_Workflows_Encryption::encrypt($node['data']['auth']['token']);
                        if ($encrypted === false) {
                            throw new Exception('Bearer token encryption failed');
                        }
                        $node['data']['auth']['token'] = 'enc_' . $encrypted;
                    }
                    break;

                case 'apiKey':
                    if (!empty($node['data']['auth']['apiKey']) && 
                        $node['data']['auth']['apiKey'] !== '********') {
                        $encrypted = WP_AI_Workflows_Encryption::encrypt($node['data']['auth']['apiKey']);
                        if ($encrypted === false) {
                            throw new Exception('API key encryption failed');
                        }
                        $node['data']['auth']['apiKey'] = 'enc_' . $encrypted;
                    }
                    break;
            }
            
            WP_AI_Workflows_Utilities::debug_log("API credentials encrypted successfully", "debug", [
                'node_id' => $node['id'],
                'auth_type' => $node['data']['auth']['type']
            ]);
            
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Failed to encrypt API credentials", "error", [
                'error' => $e->getMessage(),
                'node_id' => $node['id']
            ]);
            throw $e;
        }
    }
    return $node;
}

private static function prepare_api_call_node_for_edit($node) {
    if (!empty($node['data']['auth'])) {
        try {
            switch ($node['data']['auth']['type']) {
                case 'basic':
                    if (!empty($node['data']['auth']['username']) && 
                        strpos($node['data']['auth']['username'], 'enc_') === 0) {
                        $username = substr($node['data']['auth']['username'], 4);
                        $decrypted = WP_AI_Workflows_Encryption::decrypt($username);
                        if ($decrypted !== false) {
                            $node['data']['auth']['username'] = $decrypted;
                        }
                    }
                    if (!empty($node['data']['auth']['password']) && 
                        strpos($node['data']['auth']['password'], 'enc_') === 0) {
                        $password = substr($node['data']['auth']['password'], 4);
                        $decrypted = WP_AI_Workflows_Encryption::decrypt($password);
                        if ($decrypted !== false) {
                            $node['data']['auth']['password'] = $decrypted;
                        }
                    }
                    break;

                case 'bearer':
                    if (!empty($node['data']['auth']['token']) && 
                        strpos($node['data']['auth']['token'], 'enc_') === 0) {
                        $token = substr($node['data']['auth']['token'], 4);
                        $decrypted = WP_AI_Workflows_Encryption::decrypt($token);
                        if ($decrypted !== false) {
                            $node['data']['auth']['token'] = $decrypted;
                        }
                    }
                    break;

                case 'apiKey':
                    if (!empty($node['data']['auth']['apiKey']) && 
                        strpos($node['data']['auth']['apiKey'], 'enc_') === 0) {
                        $key = substr($node['data']['auth']['apiKey'], 4);
                        $decrypted = WP_AI_Workflows_Encryption::decrypt($key);
                        if ($decrypted !== false) {
                            $node['data']['auth']['apiKey'] = $decrypted;
                        }
                    }
                    break;
            }

            WP_AI_Workflows_Utilities::debug_log("API node prepared for edit", "debug", [
                'node_id' => $node['id'],
                'auth_type' => $node['data']['auth']['type']
            ]);

        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Failed to prepare API node for edit", "error", [
                'error' => $e->getMessage(),
                'node_id' => $node['id']
            ]);
        }
    }
    return $node;
}

private static function preserve_api_call_credentials($new_node, $existing_node) {
    if (!empty($new_node['data']['auth'])) {
        try {
            WP_AI_Workflows_Utilities::debug_log("Preserving/updating API credentials", "debug", [
                'node_id' => $new_node['id'],
                'auth_type' => $new_node['data']['auth']['type']
            ]);

            switch ($new_node['data']['auth']['type']) {
                case 'basic':
                    // Handle username
                    if ($new_node['data']['auth']['username'] === '********') {
                        // Preserve existing encrypted username
                        if (!empty($existing_node['data']['auth']['username'])) {
                            $new_node['data']['auth']['username'] = $existing_node['data']['auth']['username'];
                        }
                    } else if (!empty($new_node['data']['auth']['username'])) {
                        // Encrypt new username if it doesn't already have the enc_ prefix
                        if (strpos($new_node['data']['auth']['username'], 'enc_') !== 0) {
                            $encrypted = WP_AI_Workflows_Encryption::encrypt($new_node['data']['auth']['username']);
                            if ($encrypted === false) {
                                throw new Exception('Failed to encrypt new username');
                            }
                            $new_node['data']['auth']['username'] = 'enc_' . $encrypted;
                        }
                    }
                    
                    // Handle password
                    if ($new_node['data']['auth']['password'] === '********') {
                        // Preserve existing encrypted password
                        if (!empty($existing_node['data']['auth']['password'])) {
                            $new_node['data']['auth']['password'] = $existing_node['data']['auth']['password'];
                        }
                    } else if (!empty($new_node['data']['auth']['password'])) {
                        // Encrypt new password if it doesn't already have the enc_ prefix
                        if (strpos($new_node['data']['auth']['password'], 'enc_') !== 0) {
                            $encrypted = WP_AI_Workflows_Encryption::encrypt($new_node['data']['auth']['password']);
                            if ($encrypted === false) {
                                throw new Exception('Failed to encrypt new password');
                            }
                            $new_node['data']['auth']['password'] = 'enc_' . $encrypted;
                        }
                    }
                    break;

                case 'bearer':
                    if ($new_node['data']['auth']['token'] === '********') {
                        if (!empty($existing_node['data']['auth']['token'])) {
                            $new_node['data']['auth']['token'] = $existing_node['data']['auth']['token'];
                        }
                    } else if (!empty($new_node['data']['auth']['token'])) {
                        if (strpos($new_node['data']['auth']['token'], 'enc_') !== 0) {
                            $encrypted = WP_AI_Workflows_Encryption::encrypt($new_node['data']['auth']['token']);
                            if ($encrypted === false) {
                                throw new Exception('Failed to encrypt new bearer token');
                            }
                            $new_node['data']['auth']['token'] = 'enc_' . $encrypted;
                        }
                    }
                    break;

                case 'apiKey':
                    if ($new_node['data']['auth']['apiKey'] === '********') {
                        if (!empty($existing_node['data']['auth']['apiKey'])) {
                            $new_node['data']['auth']['apiKey'] = $existing_node['data']['auth']['apiKey'];
                        }
                    } else if (!empty($new_node['data']['auth']['apiKey'])) {
                        if (strpos($new_node['data']['auth']['apiKey'], 'enc_') !== 0) {
                            $encrypted = WP_AI_Workflows_Encryption::encrypt($new_node['data']['auth']['apiKey']);
                            if ($encrypted === false) {
                                throw new Exception('Failed to encrypt new API key');
                            }
                            $new_node['data']['auth']['apiKey'] = 'enc_' . $encrypted;
                        }
                    }
                    break;
            }

            WP_AI_Workflows_Utilities::debug_log("API credentials preserved/updated successfully", "debug", [
                'node_id' => $new_node['id'],
                'auth_type' => $new_node['data']['auth']['type']
            ]);

        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Failed to preserve/update API credentials", "error", [
                'error' => $e->getMessage(),
                'node_id' => $new_node['id'],
                'auth_type' => $new_node['data']['auth']['type']
            ]);
            throw $e;
        }
    }
    return $new_node;
}

// Add method to clear API response cache
public static function clear_api_cache($workflow_id) {
    global $wpdb;
    
    $workflow = self::get_workflow_by_id($workflow_id);
    if ($workflow) {
        foreach ($workflow['nodes'] as $node) {
            if ($node['type'] === 'apiCall' && 
                !empty($node['data']['responseConfig']['cacheResponse'])) {
                $cache_key_pattern = 'wp_ai_workflows_api_' . md5($node['id']);
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} 
                    WHERE option_name LIKE %s",
                    '_transient_' . $cache_key_pattern . '%'
                ));
            }
        }
    }
}

private static function has_chat_node($nodes) {
    foreach ($nodes as $node) {
        if ($node['type'] === 'chat') {
            return true;
        }
    }
    return false;
}

private static function extract_chat_config($nodes) {
    foreach ($nodes as $node) {
        if ($node['type'] === 'chat') {
            return [
                'model' => $node['data']['model'],
                'systemPrompt' => $node['data']['systemPrompt'],
                'settings' => $node['data']['settings'],
                'design' => $node['data']['design']
            ];
        }
    }
    return null;
}

private static function get_nodes_from_action($workflow, $action_id) {
    // Find the chat node
    $chat_node = null;
    foreach ($workflow['nodes'] as $node) {
        if ($node['type'] === 'chat') {
            $chat_node = $node;
            break;
        }
    }

    if (!$chat_node) {
        return array();
    }

    // Get all nodes that should execute for this action
    $all_nodes = array();
    $edges = $workflow['edges'];

    // Start with nodes connected to this action handle
    $connected_nodes = array();
    foreach ($edges as $edge) {
        if ($edge['source'] === $chat_node['id'] && $edge['sourceHandle'] === $action_id) {
            $connected_nodes[] = $edge['target'];
        }
    }

    // Then get all downstream nodes
    foreach ($connected_nodes as $node_id) {
        $downstream = self::get_downstream_nodes($node_id, $edges);
        $all_nodes = array_merge($all_nodes, $downstream);
    }

    // Get the actual node objects
    $action_nodes = array();
    foreach ($workflow['nodes'] as $node) {
        if (in_array($node['id'], $all_nodes)) {
            $action_nodes[] = $node;
        }
    }

    return $action_nodes;
}

}