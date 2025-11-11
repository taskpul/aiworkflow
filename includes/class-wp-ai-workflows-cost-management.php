<?php

class WP_AI_Workflows_Cost_Management {
    private static $instance = null;
    private $cost_settings_table;
    private $node_costs_table;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->cost_settings_table = $wpdb->prefix . 'wp_ai_workflows_cost_settings';
        $this->node_costs_table = $wpdb->prefix . 'wp_ai_workflows_node_costs';
        
        // Add hooks for automatic cost syncing
        add_action('wp_ai_workflows_daily_maintenance', array($this, 'sync_openrouter_costs'));
        add_action('wp_ajax_wp_ai_workflows_sync_costs', array($this, 'handle_manual_cost_sync'));
    }

    /**
     * Initialize default cost settings
     */
    public function initialize_cost_settings() {
        global $wpdb;
        
        $default_costs = [
            // OpenAI Direct Models
            ['provider' => 'openai', 'model' => 'o1', 'input_cost' => 15.0, 'output_cost' => 60.0],
            ['provider' => 'openai', 'model' => 'o1-preview', 'input_cost' => 15.0, 'output_cost' => 60.0],
            ['provider' => 'openai', 'model' => 'o1-mini', 'input_cost' => 1.1, 'output_cost' => 4.4],
            ['provider' => 'openai', 'model' => 'o3-mini', 'input_cost' => 1.1, 'output_cost' => 4.4],
            ['provider' => 'openai', 'model' => 'o3-mini-high', 'input_cost' => 1.1, 'output_cost' => 4.4],
            ['provider' => 'openai', 'model' => 'gpt-4', 'input_cost' => 10.0, 'output_cost' => 30.0],
            ['provider' => 'openai', 'model' => 'gpt-4o', 'input_cost' => 2.5, 'output_cost' => 10.0],
            ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'input_cost' => 0.15, 'output_cost' => 0.6],
            
            // Perplexity Direct Models
            ['provider' => 'perplexity', 'model' => 'Sonar', 'input_cost' => 1.0, 'output_cost' => 1.0],
            ['provider' => 'perplexity', 'model' => 'Sonar-pro', 'input_cost' => 3.0, 'output_cost' => 15.0],
            ['provider' => 'perplexity', 'model' => 'Sonar-reasoning', 'input_cost' => 1.0, 'output_cost' => 5.0],
            ['provider' => 'perplexity', 'model' => 'Sonar-reasoning-pro', 'input_cost' => 2.0, 'output_cost' => 8.0],
            ['provider' => 'perplexity', 'model' => 'Sonar-deep-research', 'input_cost' => 2.0, 'output_cost' => 8.0],
        ];

        $this->initialize_multimedia_cost_settings();
        
        foreach ($default_costs as $cost) {
            $wpdb->replace(
                $this->cost_settings_table,
                [
                    'provider' => $cost['provider'],
                    'model' => $cost['model'],
                    'input_cost' => $cost['input_cost'],
                    'output_cost' => $cost['output_cost']
                ],
                ['%s', '%s', '%f', '%f']
            );
        }

        // After initializing default costs, sync with OpenRouter for the latest models
        $this->sync_openrouter_costs();
        
        WP_AI_Workflows_Utilities::debug_log("Cost settings initialized", "info", [
            'total_models' => count($default_costs)
        ]);
    }

    public function get_costs_data() {
        $costs = get_option('wp_ai_workflows_costs', []);
        
        // Initialize with default structure if empty
        if (empty($costs)) {
            $costs = [
                'total' => 0,
                'multimedia' => [
                    'total' => 0,
                    'providers' => [],
                    'models' => [],
                    'usage' => [
                        'images' => 0,
                        'videos' => 0
                    ]
                ]
            ];
        }
        
        return $costs;
    }

    /**
     * Track multimedia generation costs
     * 
     * @param string $provider Provider name (e.g. 'fal_ai')
     * @param string $model Model used for generation
     * @param float $cost Estimated cost of the generation
     * @param int $quantity Number of items generated
     * @param string $type Type of media generated (image, video)
     * @return bool Whether the cost was successfully tracked
     */
    public function track_multimedia_cost_simple($provider, $model, $cost, $execution_id, $node_id = 'multimedia', $quantity = 1, $type = 'image') {
        // Get total cost from executions table
        global $wpdb;
        
        // Record this cost in the node_costs_table
        $wpdb->insert(
            $this->node_costs_table,
            [
                'execution_id' => $execution_id,
                'node_id' => $node_id,
                'model' => $model,
                'provider' => $provider,
                'prompt_tokens' => 1, // Not applicable for multimedia
                'completion_tokens' => 1, // Not applicable for multimedia
                'cost' => $cost
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%f']
        );
        
        // If we have a valid execution ID, update the execution's total cost
        if ($execution_id > 0) {
            $this->update_execution_total_cost($execution_id);
        }
        
        // Continue with the simplified tracking code...
        $monthly_totals = get_option('wp_ai_workflows_multimedia_costs', []);
        $month_key = date('Y-m');
        
        if (!isset($monthly_totals[$month_key])) {
            $monthly_totals[$month_key] = [
                'total' => 0,
                'images' => 0,
                'videos' => 0,
                'models' => []
            ];
        }
        
        // Update the counts
        $monthly_totals[$month_key]['total'] += $cost;
        if ($type === 'image') {
            $monthly_totals[$month_key]['images'] += $quantity;
        } else if ($type === 'video') {
            $monthly_totals[$month_key]['videos'] += $quantity;
        }
        
        // Track by model
        if (!isset($monthly_totals[$month_key]['models'][$model])) {
            $monthly_totals[$month_key]['models'][$model] = [
                'cost' => 0,
                'count' => 0
            ];
        }
        $monthly_totals[$month_key]['models'][$model]['cost'] += $cost;
        $monthly_totals[$month_key]['models'][$model]['count'] += $quantity;
        
        // Save updated tracking data
        return update_option('wp_ai_workflows_multimedia_costs', $monthly_totals);
    }

    /**
     * Initialize cost settings for multimedia generation
     */
    public function initialize_multimedia_cost_settings() {
        $settings = $this->get_cost_settings();
        
        // Initialize multimedia section if it doesn't exist
        if (!isset($settings['multimedia'])) {
            $settings['multimedia'] = [
                'budget' => 0,
                'alerts' => [
                    'enabled' => false,
                    'threshold' => 80 // percentage of budget
                ],
                'limits' => [
                    'enabled' => false,
                    'action' => 'warn' // 'warn' or 'block'
                ]
            ];
            
            // Save the updated settings
            update_option('wp_ai_workflows_cost_settings', $settings);
        }
    }

    /**
     * Sync costs with OpenRouter API
     * 
     * @return array Results of the sync operation
     */
    public function sync_openrouter_costs() {
        global $wpdb;
        
        $result = [
            'success' => false,
            'models_added' => 0,
            'models_updated' => 0,
            'errors' => []
        ];
        
        try {
            // Fetch models from OpenRouter API
            $response = wp_remote_get('https://openrouter.ai/api/v1/models', array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
            ));
            
            if (is_wp_error($response)) {
                $result['errors'][] = 'API Error: ' . $response->get_error_message();
                WP_AI_Workflows_Utilities::debug_log("Error fetching OpenRouter models for cost sync", "error", [
                    'error' => $response->get_error_message()
                ]);
                return $result;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code !== 200) {
                $result['errors'][] = 'API returned status: ' . $status_code;
                WP_AI_Workflows_Utilities::debug_log("OpenRouter API returned non-200 status for cost sync", "error", [
                    'status' => $status_code
                ]);
                return $result;
            }
            
            $body = wp_remote_retrieve_body($response);
            $models_data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $result['errors'][] = 'Failed to parse response: ' . json_last_error_msg();
                WP_AI_Workflows_Utilities::debug_log("Failed to parse OpenRouter response for cost sync", "error", [
                    'error' => json_last_error_msg()
                ]);
                return $result;
            }
            
            if (!isset($models_data['data']) || !is_array($models_data['data'])) {
                $result['errors'][] = 'Invalid response format';
                WP_AI_Workflows_Utilities::debug_log("Invalid OpenRouter API response format for cost sync", "error");
                return $result;
            }
            
            // Process each model
            foreach ($models_data['data'] as $model) {
                if (!isset($model['id']) || !isset($model['pricing'])) {
                    continue;
                }
                
                $model_id = $model['id'];
                $prompt_price = isset($model['pricing']['prompt']) ? floatval($model['pricing']['prompt']) : 0;
                $completion_price = isset($model['pricing']['completion']) ? floatval($model['pricing']['completion']) : 0;
                
                // Convert from per-token to per-million tokens
                $input_cost = $prompt_price * 1000000;
                $output_cost = $completion_price * 1000000;
                
                // Check if model already exists
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->cost_settings_table} 
                     WHERE provider = 'openrouter' AND model = %s",
                    $model_id
                ));
                
                if ($existing) {
                    // Update only if costs have changed
                    if ($existing->input_cost != $input_cost || $existing->output_cost != $output_cost) {
                        $wpdb->update(
                            $this->cost_settings_table,
                            [
                                'input_cost' => $input_cost,
                                'output_cost' => $output_cost,
                                'updated_at' => current_time('mysql')
                            ],
                            [
                                'provider' => 'openrouter',
                                'model' => $model_id
                            ],
                            ['%f', '%f', '%s'],
                            ['%s', '%s']
                        );
                        $result['models_updated']++;
                    }
                } else {
                    // Add new model
                    $wpdb->insert(
                        $this->cost_settings_table,
                        [
                            'provider' => 'openrouter',
                            'model' => $model_id,
                            'input_cost' => $input_cost,
                            'output_cost' => $output_cost
                            // updated_at will be set by default via DEFAULT CURRENT_TIMESTAMP
                        ],
                        ['%s', '%s', '%f', '%f']
                    );
                    $result['models_added']++;
                }
            }
            
            $result['success'] = true;
            
            // Store last sync time
            update_option('wp_ai_workflows_last_cost_sync', time());
            
            WP_AI_Workflows_Utilities::debug_log("OpenRouter costs synced successfully", "info", [
                'models_added' => $result['models_added'],
                'models_updated' => $result['models_updated']
            ]);
            
        } catch (Exception $e) {
            $result['errors'][] = 'Exception: ' . $e->getMessage();
            WP_AI_Workflows_Utilities::debug_log("Exception during OpenRouter cost sync", "error", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $result;
    }
    
    /**
     * AJAX handler for manual cost sync
     */
    public function handle_manual_cost_sync() {
        check_ajax_referer('wp_ai_workflows_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }
        
        $result = $this->sync_openrouter_costs();
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    'Costs synced successfully. Added %d new models, updated %d existing models.', 
                    $result['models_added'], 
                    $result['models_updated']
                ),
                'data' => $result
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Failed to sync costs. ' . implode(' ', $result['errors']),
                'data' => $result
            ]);
        }
    }

    /**
     * Get cost settings
     */
    public function get_cost_settings($provider = null, $model = null) {
        global $wpdb;
        
        if ($provider && $model) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->cost_settings_table} WHERE provider = %s AND model = %s",
                $provider,
                $model
            ));
        }
        
        return $wpdb->get_results("SELECT * FROM {$this->cost_settings_table} ORDER BY provider, model");
    }

    /**
     * Update cost setting
     */
    public function update_cost_setting($provider, $model, $input_cost, $output_cost) {
        global $wpdb;
        
        WP_AI_Workflows_Utilities::debug_log("Updating cost setting", "debug", [
            'provider' => $provider,
            'model' => $model,
            'input_cost' => $input_cost,
            'output_cost' => $output_cost
        ]);
    
        $result = $wpdb->update(
            $this->cost_settings_table,
            [
                'input_cost' => $input_cost,
                'output_cost' => $output_cost,
                'updated_at' => current_time('mysql')
            ],
            [
                'provider' => $provider,
                'model' => $model
            ],
            ['%f', '%f', '%s'],
            ['%s', '%s']
        );
    
        return $result !== false;
    }

    /**
     * Calculate node cost
     */
    public function calculate_node_cost($execution_id, $node_id, $provider, $model, $prompt_tokens, $completion_tokens) {
        global $wpdb;
        
        // For OpenRouter models, strip the provider prefix if present
        $original_model = $model;
        if (strpos($model, '/') !== false && $provider === 'openrouter') {
            $model = $model; // Leave as is, since we store the full path in costs table
        }
        
        // Get cost settings for the model
        $cost_setting = $this->get_cost_settings($provider, $model);
        
        // If not found, try checking if it's a new model that needs syncing
        if (!$cost_setting && $provider === 'openrouter') {
            $this->sync_openrouter_costs();
            $cost_setting = $this->get_cost_settings($provider, $model);
        }
        
        if (!$cost_setting) {
            WP_AI_Workflows_Utilities::debug_log("Cost settings not found for model", "warning", [
                'provider' => $provider,
                'model' => $model,
                'original_model' => $original_model
            ]);
            
            // Use default fallback prices for unknown models
            $input_cost = 5.0; // Default $5 per million tokens input
            $output_cost = 15.0; // Default $15 per million tokens output
        } else {
            $input_cost = $cost_setting->input_cost;
            $output_cost = $cost_setting->output_cost;
        }

        // Calculate costs
        $calculated_input_cost = ($prompt_tokens / 1000000) * $input_cost;
        $calculated_output_cost = ($completion_tokens / 1000000) * $output_cost;
        $total_cost = $calculated_input_cost + $calculated_output_cost;

        // Store node cost
        $wpdb->insert(
            $this->node_costs_table,
            [
                'execution_id' => $execution_id,
                'node_id' => $node_id,
                'model' => $model,
                'provider' => $provider,
                'prompt_tokens' => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'cost' => $total_cost
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%f']
        );

        // Update total execution cost
        $this->update_execution_total_cost($execution_id);

        WP_AI_Workflows_Utilities::debug_log("Node cost calculated", "debug", [
            'execution_id' => $execution_id,
            'node_id' => $node_id,
            'model' => $model,
            'total_cost' => $total_cost
        ]);

        return $total_cost;
    }

    /**
     * Update execution total cost
     */
    private function update_execution_total_cost($execution_id) {
        global $wpdb;
        
        // Calculate total cost from all nodes
        $total_cost = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(cost) FROM {$this->node_costs_table} WHERE execution_id = %d",
            $execution_id
        ));

        // Get cost details for JSON storage
        $cost_details = $wpdb->get_results($wpdb->prepare(
            "SELECT node_id, model, provider, prompt_tokens, completion_tokens, cost 
             FROM {$this->node_costs_table} 
             WHERE execution_id = %d",
            $execution_id
        ));

        // Update execution record
        $wpdb->update(
            $wpdb->prefix . 'wp_ai_workflows_executions',
            [
                'total_cost' => $total_cost,
                'cost_details' => wp_json_encode($cost_details)
            ],
            ['id' => $execution_id],
            ['%f', '%s'],
            ['%d']
        );
    }

    /**
     * Get execution costs
     */
    public function get_execution_costs($execution_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->node_costs_table} WHERE execution_id = %d ORDER BY created_at",
            $execution_id
        ));
    }

    /**
     * Get workflow total cost
     */
    public function get_workflow_total_cost($workflow_id, $date_from = null, $date_to = null) {
        global $wpdb;
        
        $query = $wpdb->prepare(
            "SELECT SUM(total_cost) FROM {$wpdb->prefix}wp_ai_workflows_executions WHERE workflow_id = %s",
            $workflow_id
        );

        if ($date_from && $date_to) {
            $query .= $wpdb->prepare(
                " AND created_at BETWEEN %s AND %s",
                $date_from,
                $date_to
            );
        }

        return $wpdb->get_var($query) ?: 0;
    }

    /**
     * Get cost statistics
     */
    public function get_cost_statistics($workflow_id = null, $date_from = null, $date_to = null) {
        global $wpdb;
        
        $where_clauses = [];
        $where_values = [];

        if ($workflow_id) {
            $where_clauses[] = "e.workflow_id = %s";
            $where_values[] = $workflow_id;
        }

        if ($date_from && $date_to) {
            $where_clauses[] = "e.created_at BETWEEN %s AND %s";
            $where_values[] = $date_from;
            $where_values[] = $date_to;
        }

        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

        $query = $wpdb->prepare(
            "SELECT 
                SUM(nc.cost) as total_cost,
                COUNT(DISTINCT e.id) as total_executions,
                SUM(nc.prompt_tokens) as total_prompt_tokens,
                SUM(nc.completion_tokens) as total_completion_tokens,
                nc.provider,
                nc.model,
                COUNT(*) as usage_count
             FROM {$wpdb->prefix}wp_ai_workflows_executions e
             JOIN {$this->node_costs_table} nc ON e.id = nc.execution_id
             $where_sql
             GROUP BY nc.provider, nc.model",
            $where_values
        );

        return $wpdb->get_results($query);
    }

    /**
     * Get daily costs
     */
    public function get_daily_costs($date_from, $date_to) {
        global $wpdb;
        
        $query = $wpdb->prepare(
            "SELECT 
                DATE(nc.created_at) as date,
                nc.model,
                SUM(nc.cost) as cost,
                SUM(nc.prompt_tokens) as prompt_tokens,
                SUM(nc.completion_tokens) as completion_tokens,
                COUNT(*) as api_calls
            FROM {$this->node_costs_table} nc
            WHERE nc.created_at BETWEEN %s AND %s
            GROUP BY DATE(nc.created_at), nc.model
            ORDER BY date",
            $date_from,
            $date_to
        );
        
        return $wpdb->get_results($query);
    }
}