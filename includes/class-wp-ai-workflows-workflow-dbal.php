<?php
/**
 * Data Access Layer for Workflow operations
 */
class WP_AI_Workflows_Workflow_DBAL {
    /**
     * Get all workflows with optional pagination and search
     */
    public static function get_all_workflows($page = 1, $per_page = 200, $search = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        // Check if the table exists and has workflows
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            // Build WHERE clause for search
            $where = '';
            $where_params = array();
            if (!empty($search)) {
                $where = "WHERE name LIKE %s";
                $where_params[] = '%' . $wpdb->esc_like($search) . '%';
            }
            
            // Count total workflows for pagination
            $total_query = "SELECT COUNT(*) FROM $table_name $where";
            $total = $wpdb->get_var($wpdb->prepare($total_query, $where_params));
            
            if ($total > 0) {
                // Get paginated results if needed
                $limit = '';
                if ($per_page > 0) {
                    $offset = ($page - 1) * $per_page;
                    $limit = "LIMIT $per_page OFFSET $offset";
                }
                
                $results = $wpdb->get_results($wpdb->prepare(
                    "SELECT data FROM $table_name $where ORDER BY updated_at DESC $limit",
                    $where_params
                ), ARRAY_A);
                
                if (!empty($results)) {
                    $workflows = array();
                    foreach ($results as $row) {
                        $workflows[] = json_decode($row['data'], true);
                    }
                    
                    return $workflows;
                }
            } else {
                return array();
            }
        }
        
        // Fallback to options table if no results from DB
        $workflows = get_option('wp_ai_workflows', array());
        
        // Check if workflows is actually an array
        if (!is_array($workflows)) {
            WP_AI_Workflows_Utilities::debug_log("Workflows option is not an array in get_all", "error", [
                'type' => gettype($workflows)
            ]);
            return array(); // Return empty array as fallback
        }
        
        // Apply search filter if needed
        if (!empty($search)) {
            $workflows = array_filter($workflows, function($workflow) use ($search) {
                return stripos($workflow['name'], $search) !== false;
            });
        }
        
        return $workflows;
    }
    
    
    /**
     * Get a single workflow by ID
     */
    public static function get_workflow_by_id($workflow_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        WP_AI_Workflows_Utilities::debug_log("Getting workflow by ID", "debug", [
            'workflow_id' => $workflow_id
        ]);
        
        try {
            // Try to get from table first
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE id = %s",
                    $workflow_id
                ), ARRAY_A);
                
                if (!$row) {
                    WP_AI_Workflows_Utilities::debug_log("Workflow not found in DB", "debug", [
                        'workflow_id' => $workflow_id
                    ]);
                    return null;
                }
                
                WP_AI_Workflows_Utilities::debug_log("Found workflow in DB", "debug", [
                    'workflow_id' => $workflow_id,
                    'name' => $row['name'],
                    'data_length' => strlen($row['data']),
                    'has_data' => !empty($row['data'])
                ]);
                
                // Check if data is valid JSON
                $needs_repair = false;
                
                if (empty($row['data'])) {
                    WP_AI_Workflows_Utilities::debug_log("Workflow data is empty", "error", [
                        'workflow_id' => $workflow_id
                    ]);
                    $needs_repair = true;
                } else {
                    $workflow_data = json_decode($row['data'], true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        WP_AI_Workflows_Utilities::debug_log("JSON decode error", "error", [
                            'workflow_id' => $workflow_id,
                            'error' => json_last_error_msg(),
                            'data_sample' => substr($row['data'], 0, 100) . '...'
                        ]);
                        $needs_repair = true;
                    } else {
                        // Valid JSON - return the workflow
                        return $workflow_data;
                    }
                }
                
                // If we got here, the workflow needs repair
                if ($needs_repair) {
                    WP_AI_Workflows_Utilities::debug_log("Workflow needs repair, attempting auto-repair", "info", [
                        'workflow_id' => $workflow_id
                    ]);
                    
                    // Attempt to auto-repair
                    $repaired_workflow = self::auto_repair_workflow($workflow_id);
                    
                    if ($repaired_workflow) {
                        // Auto-repair successful
                        return $repaired_workflow;
                    } else {
                        // Create a fallback minimal workflow for display purposes
                        return [
                            'id' => $row['id'],
                            'name' => $row['name'] . ' (⚠️ Repair Failed)',
                            'status' => $row['status'],
                            'createdAt' => $row['created_at'],
                            'updatedAt' => $row['updated_at'],
                            'createdBy' => $row['created_by'] ?? 'unknown',
                            'nodes' => [],
                            'edges' => [],
                            'needs_repair' => true
                        ];
                    }
                }
            }
            
            // Fallback to options
            WP_AI_Workflows_Utilities::debug_log("Falling back to options table", "debug", [
                'workflow_id' => $workflow_id
            ]);
            
            $workflows = get_option('wp_ai_workflows', array());
            if (!is_array($workflows)) {
                WP_AI_Workflows_Utilities::debug_log("Workflows option is not an array", "error", [
                    'type' => gettype($workflows)
                ]);
                return null;
            }
            
            foreach ($workflows as $workflow) {
                if (is_array($workflow) && isset($workflow['id']) && $workflow['id'] === $workflow_id) {
                    WP_AI_Workflows_Utilities::debug_log("Found workflow in options", "debug", [
                        'workflow_id' => $workflow_id,
                        'name' => $workflow['name'] ?? 'Unknown'
                    ]);
                    
                    // Ensure the workflow has the required fields
                    if (!isset($workflow['nodes'])) {
                        $workflow['nodes'] = [];
                    }
                    
                    if (!isset($workflow['edges'])) {
                        $workflow['edges'] = [];
                    }
                    
                    if (!isset($workflow['name'])) {
                        $workflow['name'] = 'Unknown Workflow';
                    }
                    
                    return $workflow;
                }
            }
            
            WP_AI_Workflows_Utilities::debug_log("Workflow not found in options", "debug", [
                'workflow_id' => $workflow_id
            ]);
            
            return null;
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Exception in get_workflow_by_id", "error", [
                'workflow_id' => $workflow_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Create a new workflow
     */
    public static function create_workflow($workflow) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        // Always start a transaction for data integrity
        $wpdb->query('START TRANSACTION');
        
        try {
            // Always update options for backward compatibility
            $workflows = get_option('wp_ai_workflows', array());
            
            // Fix for corrupted option
            if (!is_array($workflows)) {
                WP_AI_Workflows_Utilities::debug_log("Workflows option corrupted in create_workflow", "error", [
                    'type' => gettype($workflows),
                    'value_preview' => is_string($workflows) ? substr($workflows, 0, 100) : 'non-string'
                ]);
                $workflows = array();
            }
            
            $workflows[] = $workflow;
            $option_updated = update_option('wp_ai_workflows', $workflows);
            
            // Insert into the new table
            $db_inserted = false;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $db_inserted = $wpdb->insert(
                    $table_name,
                    array(
                        'id' => $workflow['id'],
                        'name' => $workflow['name'],
                        'status' => $workflow['status'],
                        'data' => wp_json_encode($workflow),
                        'created_by' => $workflow['createdBy'],
                        'created_at' => $workflow['createdAt'],
                        'updated_at' => isset($workflow['updatedAt']) ? $workflow['updatedAt'] : $workflow['createdAt']
                    )
                );
            }
            
            // If either operation succeeded
            if ($option_updated || ($db_inserted !== false)) {
                $wpdb->query('COMMIT');
                return $workflow;
            } else {
                $wpdb->query('ROLLBACK');
                WP_AI_Workflows_Utilities::debug_log("Failed to create workflow", "error", [
                    'workflow_id' => $workflow['id'],
                    'option_updated' => $option_updated,
                    'db_inserted' => $db_inserted
                ]);
                return false;
            }
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            WP_AI_Workflows_Utilities::debug_log("Exception creating workflow", "error", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }    
    
    /**
     * Update an existing workflow
     */
    public static function update_workflow($workflow_id, $updated_workflow) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        WP_AI_Workflows_Utilities::debug_log("Starting workflow update via DBAL", "debug", [
            'workflow_id' => $workflow_id,
            'data_size' => strlen(wp_json_encode($updated_workflow))
        ]);
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Update options table for backward compatibility
            $workflows = get_option('wp_ai_workflows', array());
            
            // Check if workflows is actually an array
            if (!is_array($workflows)) {
                WP_AI_Workflows_Utilities::debug_log("Workflows option is not an array", "error", [
                    'type' => gettype($workflows),
                    'value_preview' => is_string($workflows) ? substr($workflows, 0, 100) : 'non-string'
                ]);
                
                // Reset to empty array to avoid errors
                $workflows = array();
            }
            
            // Find the workflow by ID
            $workflow_index = false;
            foreach ($workflows as $index => $workflow) {
                if (is_array($workflow) && isset($workflow['id']) && $workflow['id'] === $workflow_id) {
                    $workflow_index = $index;
                    break;
                }
            }
            
            $option_updated = false;
            if ($workflow_index !== false) {
                $workflows[$workflow_index] = $updated_workflow;
                $option_updated = update_option('wp_ai_workflows', $workflows);
            }
            
            // Update in the database table
            $db_updated = false;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $db_updated = $wpdb->update(
                    $table_name,
                    array(
                        'name' => $updated_workflow['name'],
                        'status' => $updated_workflow['status'],
                        'data' => wp_json_encode($updated_workflow),
                        'updated_at' => isset($updated_workflow['updatedAt']) ? $updated_workflow['updatedAt'] : current_time('mysql')
                    ),
                    array('id' => $workflow_id)
                );
            }
            
            // If both operations succeeded or we only updated options
            if ($option_updated || $db_updated !== false) {
                $wpdb->query('COMMIT');
                return $updated_workflow;
            } else {
                $wpdb->query('ROLLBACK');
                WP_AI_Workflows_Utilities::debug_log("Failed to update workflow", "error", [
                    'workflow_id' => $workflow_id,
                    'option_updated' => $option_updated,
                    'db_updated' => $db_updated
                ]);
                return false;
            }
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            WP_AI_Workflows_Utilities::debug_log("Exception updating workflow", "error", [
                'error' => $e->getMessage(),
                'workflow_id' => $workflow_id
            ]);
            return false;
        }
    }
    
    
    /**
     * Delete a workflow
     */
    public static function delete_workflow($workflow_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Delete from options table
            $workflows = get_option('wp_ai_workflows', array());
            
            // Check if workflows is actually an array
            if (!is_array($workflows)) {
                WP_AI_Workflows_Utilities::debug_log("Workflows option is not an array in delete", "error", [
                    'type' => gettype($workflows)
                ]);
                // Reset to empty array to avoid errors
                $workflows = array();
            }
            
            // Find the workflow by ID
            $workflow_index = false;
            foreach ($workflows as $index => $workflow) {
                if (is_array($workflow) && isset($workflow['id']) && $workflow['id'] === $workflow_id) {
                    $workflow_index = $index;
                    break;
                }
            }
            
            $option_updated = false;
            if ($workflow_index !== false) {
                array_splice($workflows, $workflow_index, 1);
                $option_updated = update_option('wp_ai_workflows', $workflows);
            }
            
            // Delete from database table
            $db_deleted = false;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                $db_deleted = $wpdb->delete(
                    $table_name,
                    array('id' => $workflow_id)
                );
            }
            
            // If either operation succeeded
            if ($option_updated || $db_deleted !== false) {
                $wpdb->query('COMMIT');
                return true;
            } else {
                $wpdb->query('ROLLBACK');
                WP_AI_Workflows_Utilities::debug_log("Failed to delete workflow", "error", [
                    'workflow_id' => $workflow_id, 
                    'option_updated' => $option_updated,
                    'db_deleted' => $db_deleted
                ]);
                return false;
            }
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            WP_AI_Workflows_Utilities::debug_log("Exception deleting workflow", "error", [
                'error' => $e->getMessage(),
                'workflow_id' => $workflow_id
            ]);
            return false;
        }
    }
    
    
    /**
     * Update the status of a workflow
     */
    public static function update_workflow_status($workflow_id, $status) {
        $workflow = self::get_workflow_by_id($workflow_id);
        if (!$workflow) {
            return false;
        }
        
        $workflow['status'] = $status;
        $workflow['updatedAt'] = current_time('mysql');
        
        return self::update_workflow($workflow_id, $workflow);
    }
    
    /**
     * Search workflows by text and other filters
     */
    public static function search_workflows($search_text = '', $status = null, $tags = [], $page = 1, $per_page = 200) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        WP_AI_Workflows_Utilities::debug_log("Starting search_workflows", "debug", [
            'search_text' => $search_text,
            'status' => $status,
            'tags' => $tags,
            'page' => $page,
            'per_page' => $per_page
        ]);
        
        // Check if the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            // Build WHERE clause for search/status filtering
            $where_clauses = [];
            $where_params = [];
            
            if (!empty($search_text)) {
                $where_clauses[] = "(name LIKE %s OR data LIKE %s)";
                $where_params[] = '%' . $wpdb->esc_like($search_text) . '%';
                $where_params[] = '%' . $wpdb->esc_like($search_text) . '%';
            }
            
            if ($status !== null) {
                $where_clauses[] = "status = %s";
                $where_params[] = $status;
            }
            
            // Combine where clauses
            $where = "";
            if (!empty($where_clauses)) {
                $where = "WHERE " . implode(" AND ", $where_clauses);
            }
            
            // Count total for pagination
            $total_query = "SELECT COUNT(*) FROM $table_name $where";
            $total = $wpdb->get_var($wpdb->prepare($total_query, $where_params));
            
            // Get paginated results
            $offset = ($page - 1) * $per_page;
            $query = $wpdb->prepare(
                "SELECT * FROM $table_name $where ORDER BY updated_at DESC LIMIT %d OFFSET %d",
                array_merge($where_params, [$per_page, $offset])
            );
            
            WP_AI_Workflows_Utilities::debug_log("DB query", "debug", [
                'query' => $query
            ]);
            
            $results = $wpdb->get_results($query, ARRAY_A);
            
            WP_AI_Workflows_Utilities::debug_log("Raw DB results", "debug", [
                'count' => count($results),
                'result_ids' => array_column($results, 'id')
            ]);
            
            $workflows = [];
            if (!empty($results)) {
                foreach ($results as $row) {
                    try {
                        // Try to decode the JSON data
                        $workflow_data = json_decode($row['data'], true);
                        
                        // Check if JSON decoding succeeded
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            WP_AI_Workflows_Utilities::debug_log("JSON decode error", "error", [
                                'workflow_id' => $row['id'],
                                'error' => json_last_error_msg(),
                                'data_sample' => substr($row['data'], 0, 100) . '...'
                            ]);
                            
                            // Try to auto-repair the workflow
                            $repaired_workflow = self::auto_repair_workflow($row['id']);
                            
                            if ($repaired_workflow) {
                                // Successfully repaired
                                WP_AI_Workflows_Utilities::debug_log("Workflow auto-repaired successfully", "info", [
                                    'workflow_id' => $row['id']
                                ]);
                                $workflow_data = $repaired_workflow;
                            } else {
                                // Create a basic workflow with repair flag
                                $workflow_data = [
                                    'id' => $row['id'],
                                    'name' => $row['name'] . ' (⚠️ Auto-Repair Failed)',
                                    'status' => $row['status'],
                                    'createdAt' => $row['created_at'],
                                    'updatedAt' => $row['updated_at'],
                                    'createdBy' => $row['created_by'] ?? 'unknown',
                                    'nodes' => [],
                                    'edges' => [],
                                    'needs_repair' => true
                                ];
                            }
                        } else {
                            // Ensure the decoded data has the basic required properties
                            if (!isset($workflow_data['id'])) {
                                $workflow_data['id'] = $row['id'];
                            }
                            
                            if (!isset($workflow_data['name'])) {
                                $workflow_data['name'] = $row['name'] ?? 'Unnamed Workflow';
                            }
                            
                            if (!isset($workflow_data['status'])) {
                                $workflow_data['status'] = $row['status'] ?? 'inactive';
                            }
                            
                            if (!isset($workflow_data['createdAt'])) {
                                $workflow_data['createdAt'] = $row['created_at'] ?? date('Y-m-d H:i:s');
                            }
                            
                            if (!isset($workflow_data['updatedAt'])) {
                                $workflow_data['updatedAt'] = $row['updated_at'] ?? date('Y-m-d H:i:s');
                            }
                            
                            if (!isset($workflow_data['nodes'])) {
                                $workflow_data['nodes'] = [];
                            }
                            
                            if (!isset($workflow_data['edges'])) {
                                $workflow_data['edges'] = [];
                            }
                        }
                        
                        // Filter by tags if any tags are specified
                        if (!empty($tags) && !isset($workflow_data['needs_repair'])) {
                            $workflow_tags = array_column($workflow_data['tags'] ?? [], 'name');
                            if (empty(array_intersect($tags, $workflow_tags))) {
                                continue; // Skip this workflow if it doesn't have any of the specified tags
                            }
                        }
                        
                        $workflows[] = $workflow_data;
                    } catch (Exception $e) {
                        WP_AI_Workflows_Utilities::debug_log("Error processing workflow data", "error", [
                            'workflow_id' => $row['id'],
                            'error' => $e->getMessage()
                        ]);
                        
                        // Still include a basic workflow structure for the UI
                        $workflows[] = [
                            'id' => $row['id'],
                            'name' => $row['name'] . ' (⚠️ Error: ' . substr($e->getMessage(), 0, 30) . ')',
                            'status' => $row['status'] ?? 'inactive',
                            'createdAt' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                            'updatedAt' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                            'createdBy' => $row['created_by'] ?? 'unknown',
                            'nodes' => [],
                            'edges' => [],
                            'needs_repair' => true
                        ];
                    }
                }
            }
            
            WP_AI_Workflows_Utilities::debug_log("Processed DB results", "debug", [
                'workflows_count' => count($workflows),
                'workflow_ids' => array_column($workflows, 'id')
            ]);
            
            return [
                'workflows' => $workflows,
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            ];
        }
        
        // Fallback to options when table doesn't exist
        WP_AI_Workflows_Utilities::debug_log("Falling back to options table", "debug");
        
        $all_workflows = get_option('wp_ai_workflows', []);
        if (!is_array($all_workflows)) {
            WP_AI_Workflows_Utilities::debug_log("Workflows option is not an array", "error", [
                'type' => gettype($all_workflows)
            ]);
            $all_workflows = [];
        }
        
        WP_AI_Workflows_Utilities::debug_log("Found workflows in options", "debug", [
            'count' => count($all_workflows)
        ]);
        
        $filtered_workflows = [];
        
        foreach ($all_workflows as $workflow) {
            if (!is_array($workflow)) {
                WP_AI_Workflows_Utilities::debug_log("Invalid workflow in options", "error", [
                    'type' => gettype($workflow)
                ]);
                continue;
            }
            
            // Apply text search filter
            if (!empty($search_text) && 
                stripos($workflow['name'] ?? '', $search_text) === false) {
                continue;
            }
            
            // Apply status filter
            if ($status !== null && ($workflow['status'] ?? '') !== $status) {
                continue;
            }
            
            // Apply tag filter
            if (!empty($tags)) {
                $workflow_tags = array_column($workflow['tags'] ?? [], 'name');
                if (empty(array_intersect($tags, $workflow_tags))) {
                    continue;
                }
            }
            
            $filtered_workflows[] = $workflow;
        }
        
        // Sort workflows by updated date (newest first)
        usort($filtered_workflows, function($a, $b) {
            $date_a = isset($a['updatedAt']) ? strtotime($a['updatedAt']) : strtotime($a['createdAt'] ?? 0);
            $date_b = isset($b['updatedAt']) ? strtotime($b['updatedAt']) : strtotime($b['createdAt'] ?? 0);
            return $date_b - $date_a;
        });
        
        // Apply pagination
        $total = count($filtered_workflows);
        $offset = ($page - 1) * $per_page;
        $workflows = array_slice($filtered_workflows, $offset, $per_page);
        
        WP_AI_Workflows_Utilities::debug_log("Returning workflows from options", "debug", [
            'count' => count($workflows),
            'total' => $total
        ]);
        
        return [
            'workflows' => $workflows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ];
    }
    
    /**
     * Add or update a tag for a workflow
     */
    public static function add_workflow_tag($workflow_id, $tag_name, $tag_color = null) {
        $workflow = self::get_workflow_by_id($workflow_id);
        if (!$workflow) {
            return false;
        }
        
        // Initialize tags array if it doesn't exist
        if (!isset($workflow['tags']) || !is_array($workflow['tags'])) {
            $workflow['tags'] = [];
        }
        
        // Check if tag already exists
        foreach ($workflow['tags'] as $key => $tag) {
            if ($tag['name'] === $tag_name) {
                // Update color if provided
                if ($tag_color !== null) {
                    $workflow['tags'][$key]['color'] = $tag_color;
                }
                return self::update_workflow($workflow_id, $workflow);
            }
        }
        
        // Add new tag
        $workflow['tags'][] = [
            'id' => uniqid('tag_'),
            'name' => $tag_name,
            'color' => $tag_color ?: self::get_random_tag_color($tag_name)
        ];
        
        return self::update_workflow($workflow_id, $workflow);
    }
    
    /**
     * Generate a random color for a tag
     */
    private static function get_random_tag_color($tag_name) {
        $colors = [
            'magenta', 'red', 'volcano', 'orange', 'gold',
            'lime', 'green', 'cyan', 'blue', 'geekblue', 'purple'
        ];
        
        // Use the tag name to generate a consistent color
        $hash = 0;
        $str = $tag_name;
        for ($i = 0; $i < strlen($str); $i++) {
            $hash = ord($str[$i]) + (($hash << 5) - $hash);
        }
        
        return $colors[abs($hash) % count($colors)];
    }
    
    /**
     * Get all unique tags across all workflows
     */
    public static function get_all_tags() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        $tags = [];
        
        // First try to get from the database table
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $workflows = $wpdb->get_col("SELECT data FROM $table_name");
            
            if (!empty($workflows)) {
                foreach ($workflows as $workflow_data) {
                    $workflow = json_decode($workflow_data, true);
                    if (isset($workflow['tags']) && is_array($workflow['tags'])) {
                        foreach ($workflow['tags'] as $tag) {
                            $tags[$tag['name']] = $tag;
                        }
                    }
                }
            }
        }
        
        // Also check options table for any tags not in the DB
        $option_workflows = get_option('wp_ai_workflows', []);
        foreach ($option_workflows as $workflow) {
            if (isset($workflow['tags']) && is_array($workflow['tags'])) {
                foreach ($workflow['tags'] as $tag) {
                    $tags[$tag['name']] = $tag;
                }
            }
        }
        
        return array_values($tags);
    }
    
    /**
     * Get workflows with specific status or matching criteria
     */
    public static function get_workflows_by_status($status, $limit = 200) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        // Try to get from the table first
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT data FROM $table_name WHERE status = %s ORDER BY updated_at DESC LIMIT %d",
                $status, $limit
            ), ARRAY_A);
            
            if (!empty($results)) {
                $workflows = [];
                foreach ($results as $row) {
                    $workflows[] = json_decode($row['data'], true);
                }
                return $workflows;
            }
        }
        
        // Fallback to options
        $all_workflows = get_option('wp_ai_workflows', []);
        $filtered = array_filter($all_workflows, function($workflow) use ($status) {
            return $workflow['status'] === $status;
        });
        
        // Sort by updated date
        usort($filtered, function($a, $b) {
            $date_a = isset($a['updatedAt']) ? strtotime($a['updatedAt']) : strtotime($a['createdAt']);
            $date_b = isset($b['updatedAt']) ? strtotime($b['updatedAt']) : strtotime($b['createdAt']);
            return $date_b - $date_a;
        });
        
        return array_slice($filtered, 0, $limit);
    }
    
    /**
     * Count workflows by status
     */
    public static function count_workflows_by_status() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        $counts = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'scheduled' => 0,
        ];
        
        // Try to get counts from the table
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $results = $wpdb->get_results(
                "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status"
            );
            
            if (!empty($results)) {
                foreach ($results as $row) {
                    if (isset($counts[$row->status])) {
                        $counts[$row->status] = (int)$row->count;
                    }
                    $counts['total'] += (int)$row->count;
                }
                
                // Add scheduled workflows (those with enabled schedule)
                $scheduled = $wpdb->get_var(
                    "SELECT COUNT(*) FROM $table_name WHERE data LIKE '%\"enabled\":true%' AND data LIKE '%\"schedule\":%'"
                );
                $counts['scheduled'] = (int)$scheduled;
                
                return $counts;
            }
        }
        
        // Fallback to options table
        $workflows = get_option('wp_ai_workflows', []);
        
        foreach ($workflows as $workflow) {
            $counts['total']++;
            if (isset($workflow['status']) && isset($counts[$workflow['status']])) {
                $counts[$workflow['status']]++;
            }
            
            // Check if workflow is scheduled
            if (isset($workflow['schedule']) && 
                is_array($workflow['schedule']) && 
                isset($workflow['schedule']['enabled']) && 
                $workflow['schedule']['enabled'] === true) {
                $counts['scheduled']++;
            }
        }
        
        return $counts;
    }

    /**
     * Auto-repair a workflow if JSON data is corrupted
     * 
     * @param string $workflow_id The ID of the workflow to repair
     * @return array|null The repaired workflow or null if repair failed
     */
    public static function auto_repair_workflow($workflow_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        WP_AI_Workflows_Utilities::debug_log("Auto-repairing workflow", "info", [
            'workflow_id' => $workflow_id
        ]);
        
        // Step 1: Try to recover from options table
        $options_workflows = get_option('wp_ai_workflows', []);
        $repaired = false;
        
        if (is_array($options_workflows)) {
            foreach ($options_workflows as $workflow) {
                if (is_array($workflow) && isset($workflow['id']) && $workflow['id'] === $workflow_id) {
                    WP_AI_Workflows_Utilities::debug_log("Found workflow in options table", "info", [
                        'workflow_id' => $workflow_id,
                        'name' => $workflow['name'] ?? 'Unknown'
                    ]);
                    
                    // Update the database with the valid workflow data
                    $result = $wpdb->update(
                        $table_name,
                        [
                            'data' => wp_json_encode($workflow),
                            'updated_at' => current_time('mysql')
                        ],
                        ['id' => $workflow_id]
                    );
                    
                    if ($result !== false) {
                        WP_AI_Workflows_Utilities::debug_log("Successfully repaired workflow from options", "info", [
                            'workflow_id' => $workflow_id
                        ]);
                        
                        return $workflow;
                    }
                    
                    break;
                }
            }
        }
        
        // Step 2: If not found in options, try to recover from execution history
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %s",
            $workflow_id
        ), ARRAY_A);
        
        if (!$row) {
            WP_AI_Workflows_Utilities::debug_log("Workflow not found in database", "error", [
                'workflow_id' => $workflow_id
            ]);
            return null;
        }
        
        // Get the executions for this workflow
        $executions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $executions_table WHERE workflow_id = %s AND status = 'completed' ORDER BY created_at DESC LIMIT 5",
            $workflow_id
        ), ARRAY_A);
        
        if (!empty($executions)) {
            $latest_execution = $executions[0];
            $output_data = json_decode($latest_execution['output_data'], true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($output_data)) {
                // We have valid execution data, try to reconstruct the workflow
                $reconstructed_workflow = [
                    'id' => $workflow_id,
                    'name' => $row['name'],
                    'status' => $row['status'],
                    'createdAt' => $row['created_at'],
                    'updatedAt' => $row['updated_at'],
                    'createdBy' => $row['created_by'],
                    'nodes' => [],
                    'edges' => [],
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]
                ];
                
                // Extract node IDs and types from execution data
                $i = 0;
                foreach ($output_data as $node_id => $node_output) {
                    if (!is_array($node_output)) continue;
                    
                    $node_type = $node_output['type'] ?? 'unknown';
                    
                    $reconstructed_workflow['nodes'][] = [
                        'id' => $node_id,
                        'type' => $node_type,
                        'position' => ['x' => 250 + $i * 150, 'y' => 100 + ($i % 3) * 150],
                        'data' => [
                            'label' => ucfirst($node_type) . ' Node',
                            'reconstructed' => true
                        ]
                    ];
                    $i++;
                }
                
                // Create edges between nodes in the order they appear
                $node_ids = array_keys($output_data);
                for ($j = 0; $j < count($node_ids) - 1; $j++) {
                    $reconstructed_workflow['edges'][] = [
                        'id' => 'edge-' . $j,
                        'source' => $node_ids[$j],
                        'target' => $node_ids[$j + 1]
                    ];
                }
                
                // Update the workflow in the database
                $result = $wpdb->update(
                    $table_name,
                    [
                        'data' => wp_json_encode($reconstructed_workflow),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $workflow_id]
                );
                
                if ($result !== false) {
                    WP_AI_Workflows_Utilities::debug_log("Successfully reconstructed workflow from execution data", "info", [
                        'workflow_id' => $workflow_id,
                        'node_count' => count($reconstructed_workflow['nodes']),
                        'edge_count' => count($reconstructed_workflow['edges'])
                    ]);
                    
                    return $reconstructed_workflow;
                }
            }
        }
        
        // Step 3: Create a minimal valid workflow as a last resort
        $minimal_workflow = [
            'id' => $workflow_id,
            'name' => $row['name'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'createdBy' => $row['created_by'] ?? 'unknown',
            'nodes' => [],
            'edges' => [],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            'recovered' => 'minimal'
        ];
        
        $result = $wpdb->update(
            $table_name,
            [
                'data' => wp_json_encode($minimal_workflow),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $workflow_id]
        );
        
        if ($result !== false) {
            WP_AI_Workflows_Utilities::debug_log("Created minimal valid workflow structure", "info", [
                'workflow_id' => $workflow_id
            ]);
            
            return $minimal_workflow;
        }
        
        return null;
    }
}