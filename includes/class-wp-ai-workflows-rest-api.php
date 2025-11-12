<?php
/**
 * Manages all REST API endpoints for the plugin.
 */
class WP_AI_Workflows_REST_API {

    public function init() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_ai_workflows_cleanup', array($this, 'clear_openrouter_models_cache'));
    }

    public function register_rest_routes() {
        // Workflows
        register_rest_route('wp-ai-workflows/v1', '/workflows', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_workflows'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows/(?P<id>[\w-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows/(?P<id>[\w-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows/(?P<id>[\w-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_single_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Workflow Execution
        register_rest_route('wp-ai-workflows/v1', '/execute-workflow/(?P<id>[\w-]+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'execute_workflow_endpoint'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/executions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_executions'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/executions/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_execution'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/executions/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'stop_and_delete_execution'),
            'permission_callback' => array($this, 'authorize_request')
        ));
    
        register_rest_route('wp-ai-workflows/v1', '/execution-status/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_execution_status'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Settings
        register_rest_route('wp-ai-workflows/v1', '/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_settings'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/settings', array(
            'methods' => 'POST,PUT',
            'callback' => array($this, 'update_settings'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/available-ai-models', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_available_ai_models'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/cost-statistics', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_cost_statistics'),
            'permission_callback' => array($this, 'authorize_request')
        ));
    
        register_rest_route('wp-ai-workflows/v1', '/cost-settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_cost_settings'),
            'permission_callback' => array($this, 'authorize_request')
        ));
    
        register_rest_route('wp-ai-workflows/v1', '/cost-settings', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_cost_settings'),
            'permission_callback' => array($this, 'authorize_request')
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/sync-costs', array(
            'methods' => 'POST',
            'callback' => array($this, 'sync_costs'),
            'permission_callback' => array($this, 'authorize_request')
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/cost-sync-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_sync_info'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/download-log', array(
            'methods' => 'GET',
            'callback' => array($this, 'download_debug_log'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/system-requirements', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_system_requirements'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        // Forms
        register_rest_route('wp-ai-workflows/v1', '/gravity-forms', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gravity_forms_data'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/wpforms', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_wpforms_data'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/contactform7', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_cf7_data'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/ninjaforms', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_ninja_forms_data'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        //WP core

        register_rest_route('wp-ai-workflows/v1', '/wp-core-triggers', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_wp_core_triggers'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Webhooks
        register_rest_route('wp-ai-workflows/v1', '/webhook/(?P<node_id>[\w-]+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook_trigger'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('wp-ai-workflows/v1', '/generate-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_webhook_url'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/sample-webhook/(?P<id>[\w-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'sample_webhook'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // MCP Client endpoints


        // Outputs
        register_rest_route('wp-ai-workflows/v1', '/save-output', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_output'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/outputs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_outputs'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/latest-output', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_latest_output'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/shortcode-output', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_shortcode_output'),
            'permission_callback' => '__return_true'
        ));

        // Email


        // Tables
        register_rest_route('wp-ai-workflows/v1', '/tables', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_tables'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/export-outputs', array(
            'methods' => 'GET',
            'callback' => array($this, 'export_outputs'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/tables', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_table'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/table-structure', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_table_structure'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/delete-table', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_table'),
            'permission_callback' => array($this, 'authorize_request')
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/delete-entry', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_entry'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Vector store endpoints


        // Post
        register_rest_route('wp-ai-workflows/v1', '/post-types', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_types'),
            'permission_callback' => array($this, 'authorize_request')
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/post-fields/(?P<post_type>[\w-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_fields'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/execute-post-node', array(
            'methods' => 'POST',
            'callback' => array($this, 'execute_post_node'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/authors', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_authors'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/categories/(?P<post_type>[\w-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories_by_post_type'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Template
        register_rest_route('wp-ai-workflows/v1', '/templates', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_templates'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/templates', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_template'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/templates/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_template'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/templates/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_template'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/templates/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_template'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        //generator
        register_rest_route('wp-ai-workflows/v1', '/generate-workflow', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_workflow'),
            'permission_callback' => array($this, 'authorize_request'),
            'args' => array(
                'prompt' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // Firecrawl


        //Parser


        //RSS
        register_rest_route('wp-ai-workflows/v1', '/rss-preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'preview_rss_feed'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // API Call test endpoint
        register_rest_route('wp-ai-workflows/v1', '/test-api-call', array(
        'methods' => 'POST',
        'callback' => array($this, 'handle_test_api_call'),
        'permission_callback' => array($this, 'authorize_request'),
        'args' => array(
            'method' => array(
                'required' => true,
                'type' => 'string',
                'enum' => array('GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'),
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'url' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'validate_callback' => function($url) {
                    return wp_http_validate_url($url);
                }
            )
        )
     ));

        // License-related routes


        //Google
        register_rest_route('wp-ai-workflows/v1', '/google-integration-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_google_integration_status'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Unsplash
        register_rest_route('wp-ai-workflows/v1', '/unsplash/search', array(
            'methods' => 'POST',
            'callback' => array($this, 'search_unsplash'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // API key verification
        register_rest_route('wp-ai-workflows/v1', '/generate-api-key', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_api_key'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/verify-api-key', array(
            'methods' => 'POST',
            'callback' => array($this, 'verify_api_key'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/human-tasks', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_human_tasks'),
            'permission_callback' => function() {
                return current_user_can('manage_workflow_tasks');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/human-tasks/(?P<id>\d+)/(?P<action>approve|reject|revert|modify)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_human_task'),
            'permission_callback' => function() {
                return current_user_can('manage_workflow_tasks');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/human-tasks-count', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_human_tasks_count'),
            'permission_callback' => function() {
                return current_user_can('manage_workflow_tasks');
            }
        ));

        register_rest_route('wp-ai-workflows/v1', '/users', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_users'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/roles', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_roles'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/task-roles', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_task_roles'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/task-roles', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_task_roles'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        // Chat endpoint
        register_rest_route('wp-ai-workflows/v1', '/chat', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_chat_message'),
            'permission_callback' => '__return_true'
        ));
    
        register_rest_route('wp-ai-workflows/v1', '/chat-history', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_chat_history'),
            'permission_callback' => '__return_true'
        ));
    
    
        register_rest_route('wp-ai-workflows/v1', '/chat-config/(?P<workflow_id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_chat_config'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('wp-ai-workflows/v1', '/chat-actions/(?P<workflow_id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_chat_actions'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('wp-ai-workflows/v1', '/chat-events', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_chat_events'),
            'permission_callback' => '__return_true' 
        ));

        register_rest_route('wp-ai-workflows/v1', '/chat-action-result', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_chat_action_result'),
            'permission_callback' => '__return_true'
        ));
        
        // Submit action data
        register_rest_route('wp-ai-workflows/v1', '/chat-action-submit', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_action_submission'),
            'permission_callback' => '__return_true'
        ));

        // API Balance Check endpoints
        register_rest_route('wp-ai-workflows/v1', '/check-openrouter-balance', array(
            'methods' => 'GET',
            'callback' => array($this, 'check_openrouter_balance'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Chat Logs
        register_rest_route('wp-ai-workflows/v1', '/chat-logs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_chat_logs'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/chat-messages/(?P<session_id>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_chat_messages'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/chat-statistics', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_chat_statistics'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/stream-chat', array(
            'methods' => 'POST',
            'callback' => array($this, 'stream_chat_message'),
            'permission_callback' => '__return_true'
        ));
        

        // Assistant chat endpoints
        register_rest_route('wp-ai-workflows/v1', '/assistant/session', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_assistant_session'),
                'permission_callback' => array($this, 'authorize_request'),
                'args' => array(
                    'workflow_id' => array(
                        'required' => true,
                        'type' => 'string'
                    ),
                    'workflow_context' => array(
                        'required' => true,
                        'type' => 'object'
                    )
                )
            )
        ));

        register_rest_route('wp-ai-workflows/v1', '/assistant/message', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'send_assistant_message'),
                'permission_callback' => array($this, 'authorize_request'),
                'args' => array(
                    'session_id' => array(
                        'required' => true,
                        'type' => 'string'
                    ),
                    'content' => array(
                        'required' => true,
                        'type' => 'string'
                    )
                )
            )
        ));

        register_rest_route('wp-ai-workflows/v1', '/assistant/context', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'update_assistant_context'),
                'permission_callback' => array($this, 'authorize_request'),
                'args' => array(
                    'session_id' => array(
                        'required' => true,
                        'type' => 'string'
                    ),
                    'workflow_context' => array(
                        'required' => true,
                        'type' => 'object'
                    ),
                    'selected_node' => array(
                        'type' => 'string'
                    )
                )
            )
        ));

        register_rest_route('wp-ai-workflows/v1', '/assistant/update-mode', array(
                'methods' => 'POST',
                'callback' => array($this, 'update_mode'),
                'permission_callback' => array($this, 'authorize_request'),
            )
        );

        register_rest_route('wp-ai-workflows/v1', '/assistant/apply-changes', array(
                'methods' => 'POST',
                'callback' => array($this, 'apply_workflow_changes'),
                'permission_callback' => array($this, 'authorize_request'),
            )
        );

        register_rest_route('wp-ai-workflows/v1', '/assistant/get-session/(?P<workflow_id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_assistant_session'),
            'permission_callback' => array($this, 'authorize_request')
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/assistant/history/(?P<session_id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_assistant_history'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/openrouter-models', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_openrouter_models'),
            'permission_callback' => array($this, 'authorize_request')
        ));


        register_rest_route('wp-ai-workflows/v1', '/stream', array(
            'methods' => 'GET',
            'callback' => array($this, 'stream_output'),
            'permission_callback' => '__return_true',
        ));
        
    }



    public function authorize_request($request) {

        if (strpos($request->get_route(), '/wp-ai-workflows/v1/firecrawl/') === 0) {
            $settings = get_option('wp_ai_workflows_settings', array());
            if (empty($settings['firecrawl_api_key'])) {
                return new WP_Error('firecrawl_api_key_missing', 'Firecrawl API key is required for this operation', array('status' => 403));
            }
        }

        if (current_user_can('manage_options')) {
            return true;
        }
    
        $provided_key = $request->get_header('X-Api-Key');
        $encrypted_key = get_option('wp_ai_workflows_encrypted_api_key');
        
        if ($provided_key && wp_check_password($provided_key, $encrypted_key)) {
            return true;
        }
    
        WP_AI_Workflows_Utilities::debug_log("Authorization failed", "error", [
            'route' => $request->get_route(),
            'method' => $request->get_method()
        ]);
        return new WP_Error('rest_forbidden', 'Unauthorized access', array('status' => 401));
    }

    // Implement all the callback methods here (get_workflows, create_workflow, update_workflow, etc.)
    // Each method should call the appropriate function from other classes (e.g., Workflow, NodeExecution, etc.)

    public function get_workflows($request) {
        return WP_AI_Workflows_Workflow::get_workflows($request);
    }

    public function create_workflow($request) {
        return WP_AI_Workflows_Workflow::create_workflow($request);
    }

    public function update_workflow($request) {
        return WP_AI_Workflows_Workflow::update_workflow($request);
    }

    public function delete_workflow($request) {
        return WP_AI_Workflows_Workflow::delete_workflow($request);
    }

    public function get_single_workflow($request) {
        return WP_AI_Workflows_Workflow::get_single_workflow($request);
    }

    public function execute_workflow_endpoint($request) {
        return WP_AI_Workflows_Workflow::execute_workflow_endpoint($request);
    }

    public function stream_output($request) {
        return WP_AI_Workflows_Shortcode::stream_output($request);
    }

    public function get_execution_status($request) {
        return WP_AI_Workflows_Workflow::get_execution_status($request);
    }

    public function get_executions($request) {
        return WP_AI_Workflows_Workflow::get_executions($request);
    }

    public function get_execution($request) {
        return WP_AI_Workflows_Workflow::get_execution($request);
    }

    public function stop_and_delete_execution($request) {
        return WP_AI_Workflows_Workflow::stop_and_delete_execution($request);
    }

    public function get_gravity_forms_data($request) {
        return WP_AI_Workflows_Utilities::get_gravity_forms_data($request);
    }

    public function get_wpforms_data($request) {
        return WP_AI_Workflows_Utilities::get_wpforms_data($request);
    }

    public function get_cf7_data($request) {
        return WP_AI_Workflows_Utilities::get_cf7_data($request);
    }

    public function get_ninja_forms_data($request) {
        return WP_AI_Workflows_Utilities::get_ninja_forms_data($request);
    }

    public function handle_webhook_trigger($request) {
        return WP_AI_Workflows_Workflow::handle_webhook_trigger($request);
    }

    public function generate_webhook_url($request) {
        return WP_AI_Workflows_Workflow::generate_webhook_url($request);
    }

    public function save_output($request) {
        return WP_AI_Workflows_Workflow::save_output($request);
    }

    public function get_outputs($request) {
        return WP_AI_Workflows_Workflow::get_outputs($request);
    }

    public function get_latest_output($request) {
        return WP_AI_Workflows_Workflow::get_latest_output($request);
    }

    public function get_shortcode_output($request) {
        return WP_AI_Workflows_Shortcode::get_shortcode_output($request);
    }

    public function send_email($request) {
        return WP_AI_Workflows_Node_Execution::send_email($request);
    }

    public function get_tables($request) {
        return WP_AI_Workflows_Database::get_tables($request);
    }

    public function export_outputs($request) {
        return WP_AI_Workflows_Database::export_outputs($request);
    }

    public function create_table($request) {
        return WP_AI_Workflows_Database::create_table($request);
    }

    public function get_table_structure($request) {
        return WP_AI_Workflows_Database::get_table_structure($request);
    }

    public function delete_table($request) {
        return WP_AI_Workflows_Database::delete_table($request);
    }

    public function delete_entry($request) {
        return WP_AI_Workflows_Database::delete_entry($request);
    }

    public function get_post_types($request) {
        return WP_AI_Workflows_Node_Execution::get_post_types($request);
    }

    public function get_post_fields($request) {
        return WP_AI_Workflows_Node_Execution::get_post_fields($request);
    }

    public function execute_post_node($request) {
        return WP_AI_Workflows_Node_Execution::execute_post_node($request);
    }

    public function get_templates($request) {
        return WP_AI_Workflows_Workflow::get_templates($request);
    }

    public function create_template($request) {
        return WP_AI_Workflows_Workflow::create_template($request);
    }

    public function get_template($request) {
        return WP_AI_Workflows_Workflow::get_template($request);
    }

    public function update_template($request) {
        return WP_AI_Workflows_Workflow::update_template($request);
    }

    public function delete_template($request) {
        return WP_AI_Workflows_Workflow::delete_template($request);
    }

    public function generate_api_key($request) {
        $new_key = WP_AI_Workflows_Utilities::generate_and_encrypt_api_key();
        update_option('wp_ai_workflows_api_key', $new_key);
        return new WP_REST_Response(['ai_workflow_api_key' => $this->get_masked_api_key('wp_ai_workflows_api_key')], 200);
    }

    public function verify_api_key($request) {
        return WP_AI_Workflows_Utilities::verify_api_key($request);
    }

    private function get_masked_api_key($option_name) {
        $api_key = get_option($option_name, '');
        if (strlen($api_key) > 4) {
            return str_repeat('*', strlen($api_key) - 4) . substr($api_key, -4);
        }
        return $api_key;
    }

    public function get_wp_core_triggers() {
        $triggers = array(
            array('value' => 'publish_post', 'label' => 'Post Published'),
            array('value' => 'user_register', 'label' => 'User Registered'),
            array('value' => 'wp_insert_comment', 'label' => 'Comment Submitted'),
            array('value' => 'wp_login', 'label' => 'User Logged In'),
            array('value' => 'transition_post_status', 'label' => 'Post Status Changed')
        );
    
        return new WP_REST_Response($triggers, 200);
    }


    public function get_settings($request) {
        return WP_AI_Workflows_Utilities::get_settings($request);
    }

    public function update_settings($request) {
        $response = WP_AI_Workflows_Utilities::update_settings($request);
        
        $settings = $request->get_json_params();
        // No license activation required in the unified edition
    
        return $response;
    }

    public function get_available_ai_models() {
        $models = array(
            array('value' => 'openai', 'label' => 'OpenAI'),
            array('value' => 'perplexity', 'label' => 'Perplexity'),
            array('value' => 'anthropic', 'label' => 'Anthropic Claude (coming soon)', 'disabled' => true),
            array('value' => 'gemini', 'label' => 'Google Gemini (coming soon)', 'disabled' => true)
        );
    
        return new WP_REST_Response($models, 200);
    }

        public function get_authors() {
        $args = array(
            'who' => 'authors',
            'has_published_posts' => true,
            'orderby' => 'display_name',
            'order' => 'ASC'
        );
        
        $authors = get_users($args);
        
        $formatted_authors = array_map(function($author) {
            return array(
                'id' => $author->ID,
                'name' => $author->display_name,
                'label' => $author->display_name, // for Select component compatibility
                'value' => (string)$author->ID    // for Select component compatibility
            );
        }, $authors);
        
        return new WP_REST_Response($formatted_authors, 200);
    }

    public function get_categories_by_post_type($request) {
        $post_type = $request->get_param('post_type');
        
        // Validate post type exists
        if (!post_type_exists($post_type)) {
            return new WP_Error(
                'invalid_post_type',
                'Invalid post type',
                array('status' => 400)
            );
        }
        
        // Get taxonomies for this post type
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $categories = array();
        
        // Process each taxonomy
        foreach ($taxonomies as $taxonomy) {
            // Skip non-hierarchical taxonomies (like tags) and focus on category-like taxonomies
            if (!$taxonomy->hierarchical) {
                continue;
            }
            
            // Get terms for this taxonomy
            $terms = get_terms(array(
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC'
            ));
            
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $categories[] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'label' => $term->name, // for Select component compatibility
                        'value' => (string)$term->term_id, // for Select component compatibility
                        'taxonomy' => $taxonomy->name,
                        'taxonomy_label' => $taxonomy->label,
                        'parent' => $term->parent,
                        'slug' => $term->slug
                    );
                }
            }
        }
        
        // Group categories by taxonomy for better organization
        $grouped_categories = array();
        foreach ($categories as $category) {
            $taxonomy = $category['taxonomy'];
            if (!isset($grouped_categories[$taxonomy])) {
                $grouped_categories[$taxonomy] = array(
                    'taxonomy' => $taxonomy,
                    'label' => $category['taxonomy_label'],
                    'terms' => array()
                );
            }
            $grouped_categories[$taxonomy]['terms'][] = $category;
        }
        
        return new WP_REST_Response(array(
            'categories' => $categories, // flat list for simple usage
            'grouped' => array_values($grouped_categories) // grouped by taxonomy
        ), 200);
    }

    public function download_debug_log($request) {
        return WP_AI_Workflows_Utilities::download_log_file($request);
    }


        public function sample_webhook($request) {
            $node_id = $request['id'];
            $timeout = 60; // 60 seconds timeout
            $start_time = time();
        
            while (time() - $start_time < $timeout) {
                $webhook_data = get_transient('wp_ai_workflows_webhook_sample_' . $node_id);
                if ($webhook_data) {
                    delete_transient('wp_ai_workflows_webhook_sample_' . $node_id);
                    $keys = $this->parse_webhook_keys($webhook_data);
                    WP_AI_Workflows_Utilities::debug_log("Webhook sample received", "debug", ['node_id' => $node_id, 'keys' => $keys]);
                    return new WP_REST_Response(array('keys' => $keys), 200);
                }
                sleep(1);
            }
        
            WP_AI_Workflows_Utilities::debug_log("No webhook sample received within timeout", "warning", ['node_id' => $node_id]);
            return new WP_REST_Response(array('message' => 'No webhook data received within the timeout period'), 404);
        }
        
        private function parse_webhook_keys($data, $prefix = '') {
            $keys = array();
            foreach ($data as $key => $value) {
                $full_key = $prefix ? $prefix . '/' . $key : $key;
                if (is_array($value) || is_object($value)) {
                    $keys = array_merge($keys, $this->parse_webhook_keys($value, $full_key));
                } else {
                    $keys[] = array(
                        'key' => $full_key,
                        'type' => $this->get_value_type($value)
                    );
                }
            }
            return $keys;
        }
        
        private function get_value_type($value) {
            if (is_numeric($value)) return 'number';
            if (is_bool($value)) return 'boolean';
            return 'string';
        }

        public function execute_firecrawl($request) {
            $params = $request->get_json_params();
            $firecrawl = new WP_AI_Workflows_Firecrawl();
            
            if (!isset($params['operation']) || !isset($params['url'])) {
                return new WP_Error(
                    'missing_parameters',
                    'Operation and URL are required',
                    array('status' => 400)
                );
            }
        
            try {
                if ($params['operation'] === 'scrape') {
                    $result = $firecrawl->scrape($params);
                } else if ($params['operation'] === 'crawl') {
                    $result = $firecrawl->crawl($params);
                } else {
                    return new WP_Error(
                        'invalid_operation',
                        'Invalid operation specified',
                        array('status' => 400)
                    );
                }
        
                if (is_wp_error($result)) {
                    return new WP_Error(
                        'firecrawl_error',
                        $result->get_error_message(),
                        array('status' => 500)
                    );
                }
        
                return new WP_REST_Response($result, 200);
        
            } catch (Exception $e) {
                return new WP_Error(
                    'firecrawl_exception',
                    $e->getMessage(),
                    array('status' => 500)
                );
            }
        }
        

        public function upload_document($request) {
            if (!function_exists('wp_handle_upload')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }
    
            $uploadedfile = $request->get_file_params();
            $upload_overrides = array('test_form' => false);
            $movefile = wp_handle_upload($uploadedfile['document'], $upload_overrides);
    
            if ($movefile && !isset($movefile['error'])) {
                $file_path = $movefile['file'];
                $attachment = array(
                    'post_mime_type' => $movefile['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );
                $attach_id = wp_insert_attachment($attachment, $file_path);
                
                return new WP_REST_Response(array(
                    'success' => true,
                    'attachment_id' => $attach_id,
                    'url' => wp_get_attachment_url($attach_id)
                ), 200);
            } else {
                return new WP_Error('upload_error', $movefile['error']);
            }
        }
    
        public function parse_uploaded_document($request) {
            $params = $request->get_json_params();
            $document_url = $params['document_url'];
            $parser_settings = $params['parser_settings'];
        
            // Convert URL to server path
            $file_path = str_replace(site_url('/'), ABSPATH, $document_url);
            if (!file_exists($file_path)) {
                return new WP_Error('file_not_found', 'The specified file does not exist');
            }
        
            $parsed_content = WP_AI_Workflows_Parser::parse_document_with_llamaparse($document_url, $parser_settings);
        
            if (is_wp_error($parsed_content)) {
                return $parsed_content;
            }
        
            return new WP_REST_Response(array(
                'success' => true,
                'parsed_content' => $parsed_content
            ), 200);
        }

        public function generate_google_redirect_uri() {
            $redirect_uri = WP_AI_Workflows_Utilities::generate_google_redirect_uri();
            return new WP_REST_Response(['redirect_uri' => $redirect_uri], 200);
        }
        
        public function handle_google_auth_callback($request) {
            $code = $request->get_param('code');
            if (!$code) {
                return new WP_Error('invalid_callback', 'Invalid callback request', array('status' => 400));
            }
        
            // Exchange the code for tokens
            $tokens = $this->exchange_code_for_tokens($code);
            if (is_wp_error($tokens)) {
                return $tokens;
            }
        
            // Store the tokens securely
            WP_AI_Workflows_Utilities::update_google_tokens($tokens['access_token'], $tokens['refresh_token']);
        
            // Set a flag indicating successful Google integration
            update_option('wp_ai_workflows_google_integrated', true);
        
            // Redirect to the settings page with a success parameter
            wp_redirect(admin_url('admin.php?page=wp-ai-workflows&action=settings&google_auth=success'));
            exit;
        }
        
        private function exchange_code_for_tokens($code) {
            $google_settings = WP_AI_Workflows_Utilities::get_google_settings();
            $client_id = $google_settings['google_client_id'];
            $client_secret = $google_settings['google_client_secret'];
            $redirect_uri = $google_settings['google_redirect_uri'];
        
            $response = wp_remote_post('https://oauth2.googleapis.com/token', [
                'body' => [
                    'code' => $code,
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'redirect_uri' => $redirect_uri,
                    'grant_type' => 'authorization_code',
                ],
            ]);
        
            if (is_wp_error($response)) {
                return new WP_Error('token_exchange_failed', 'Failed to exchange code for tokens', array('status' => 500));
            }
        
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($body['access_token']) || !isset($body['refresh_token'])) {
                return new WP_Error('invalid_token_response', 'Invalid token response from Google', array('status' => 500));
            }
        
            // Store token expiry information
            update_option('wp_ai_workflows_google_token_info', [
                'expires_at' => time() + ($body['expires_in'] ?? 3600),
                'token_type' => $body['token_type'] ?? 'Bearer',
                'scope' => $body['scope'] ?? ''
            ]);
        
            return $body;
        }
    
        // Add a method to refresh the access token
        public function refresh_google_access_token() {
            $tokens = WP_AI_Workflows_Utilities::get_google_tokens();
            $google_settings = WP_AI_Workflows_Utilities::get_google_settings();
            $client_id = $google_settings['google_client_id'];
            $client_secret = $google_settings['google_client_secret'];
    
            $response = wp_remote_post('https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $tokens['refresh_token'],
                    'grant_type' => 'refresh_token',
                ],
            ]);
    
            if (is_wp_error($response)) {
                return new WP_Error('token_refresh_failed', 'Failed to refresh access token', array('status' => 500));
            }
    
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($body['access_token'])) {
                return new WP_Error('invalid_refresh_response', 'Invalid refresh token response from Google', array('status' => 500));
            }
    
            WP_AI_Workflows_Utilities::update_google_tokens($body['access_token'], $tokens['refresh_token']);
            return $body['access_token'];
        }

        public function get_google_auth_url($request) {
            // Check nonce
            $nonce = $request->get_param('_wpnonce');
            if (!wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error('rest_forbidden', 'Unauthorized access', array('status' => 401));
            }
        
            $google_settings = WP_AI_Workflows_Utilities::get_google_settings();
            $client_id = $google_settings['google_client_id'];
            $redirect_uri = $google_settings['google_redirect_uri'];
        
            if (empty($client_id)) {
                return new WP_Error('missing_client_id', 'Google Client ID is not set', array('status' => 400));
            }
        
            if (empty($redirect_uri)) {
                $redirect_uri = WP_AI_Workflows_Utilities::generate_google_redirect_uri();
            }
        
            $scope = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets';
        
            $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?";
            $auth_url .= "client_id=" . urlencode($client_id);
            $auth_url .= "&redirect_uri=" . urlencode($redirect_uri);
            $auth_url .= "&response_type=code";
            $auth_url .= "&scope=" . urlencode($scope);
            $auth_url .= "&access_type=offline";
            $auth_url .= "&prompt=consent";
        
            // Instead of returning a WP_REST_Response, let's redirect directly
            wp_redirect($auth_url);
            exit;
        }

        public function get_google_integration_status() {
            $integrated = get_option('wp_ai_workflows_google_integrated', false);
            $settings = WP_AI_Workflows_Utilities::get_google_settings();
            return new WP_REST_Response([
                'integrated' => $integrated,
                'client_id' => $settings['google_client_id'],
                'client_secret' => $settings['google_client_secret'],
            ], 200);
        }
        
        public function reset_google_integration() {
            delete_option('wp_ai_workflows_google_integrated');
            delete_option('wp_ai_workflows_google_access_token');
            delete_option('wp_ai_workflows_google_refresh_token');
            $settings = get_option('wp_ai_workflows_settings', array());
            unset($settings['google_client_id']);
            unset($settings['google_client_secret']);
            update_option('wp_ai_workflows_settings', $settings);
            return new WP_REST_Response(['message' => 'Google integration reset successfully'], 200);
        }

        private function check_google_auth_status() {
            $needs_reauth = get_transient('wp_ai_workflows_needs_google_reauth');
            
            if ($needs_reauth) {
                delete_transient('wp_ai_workflows_needs_google_reauth');
                throw new Exception('Google authorization has expired. Please re-authenticate in the settings.');
            }
            
            if (!get_option('wp_ai_workflows_google_integrated', false)) {
                throw new Exception('Google integration is not set up. Please configure Google integration in the settings.');
            }
        }

        public function get_google_drive_items() {
            try {
                $this->check_google_auth_status();
                
                WP_AI_Workflows_Utilities::debug_log("Fetching Google Drive items", "debug");
                
                $google_service = new WP_AI_Workflows_Google_Service();
                $drive_items = $google_service->list_drive_items();
                
                if (is_wp_error($drive_items)) {
                    WP_AI_Workflows_Utilities::debug_log("Error fetching drive items", "error", [
                        'error' => $drive_items->get_error_message()
                    ]);
                    return $drive_items;
                }
                
                // Ensure the response has 'folders' and 'files' properties
                $formatted_items = [
                    'folders' => array_map(function($folder) {
                        return ['id' => $folder['id'], 'name' => $folder['name']];
                    }, $drive_items['folders'] ?? []),
                    'files' => array_map(function($file) {
                        return ['id' => $file['id'], 'name' => $file['name']];
                    }, $drive_items['files'] ?? [])
                ];
                
                WP_AI_Workflows_Utilities::debug_log("Successfully fetched drive items", "debug", [
                    'folder_count' => count($formatted_items['folders']),
                    'file_count' => count($formatted_items['files'])
                ]);
                
                return new WP_REST_Response($formatted_items, 200);
                
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error in get_google_drive_items", "error", [
                    'error_message' => $e->getMessage()
                ]);
                
                if (strpos($e->getMessage(), 'Google authorization has expired') !== false) {
                    return new WP_Error(
                        'google_auth_expired',
                        $e->getMessage(),
                        array('status' => 401, 'requires_reauth' => true)
                    );
                }
                
                return new WP_Error('google_drive_error', $e->getMessage(), array('status' => 500));
            }
        }

        public function get_google_sheets() {
            try {
                $this->check_google_auth_status();
                
                WP_AI_Workflows_Utilities::debug_log("Fetching Google Sheets", "debug");
                
                $google_service = new WP_AI_Workflows_Google_Service();
                $sheets = $google_service->list_spreadsheets();
                
                if (is_wp_error($sheets)) {
                    WP_AI_Workflows_Utilities::debug_log("Error fetching sheets", "error", [
                        'error' => $sheets->get_error_message()
                    ]);
                    return $sheets;
                }
                
                // Ensure each sheet has an 'id' and 'name' property
                $formatted_sheets = array_map(function($sheet) {
                    return [
                        'id' => $sheet['id'],
                        'name' => $sheet['name'],
                        'tabs' => isset($sheet['tabs']) ? $sheet['tabs'] : []
                    ];
                }, $sheets);
                
                WP_AI_Workflows_Utilities::debug_log("Successfully fetched sheets", "debug", [
                    'count' => count($formatted_sheets)
                ]);
                
                return new WP_REST_Response($formatted_sheets, 200);
                
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error in get_google_sheets", "error", [
                    'error_message' => $e->getMessage()
                ]);
                
                if (strpos($e->getMessage(), 'Google authorization has expired') !== false) {
                    return new WP_Error(
                        'google_auth_expired',
                        $e->getMessage(),
                        array('status' => 401, 'requires_reauth' => true)
                    );
                }
                
                return new WP_Error('google_sheets_error', $e->getMessage(), array('status' => 500));
            }
        }

        public function get_google_sheet_tabs($request) {
            $sheet_id = $request->get_param('id');
            $google_service = new WP_AI_Workflows_Google_Service();
            $tabs = $google_service->get_spreadsheet_tabs($sheet_id);
        
            if (is_wp_error($tabs)) {
                return new WP_Error('google_sheets_error', $tabs->get_error_message(), array('status' => 400));
            }
        
            // Ensure we're returning an array of tabs
            if (!is_array($tabs)) {
                $tabs = [];
            }
        
            return new WP_REST_Response($tabs, 200);
        }

        public function get_google_sheet_columns($request) {
            $spreadsheet_id = $request->get_param('spreadsheet_id');
            $sheet_id = $request->get_param('sheet_id');
            
            try {
                $google_service = new WP_AI_Workflows_Google_Service();
                $columns = $google_service->get_sheet_columns($spreadsheet_id, $sheet_id);
        
                return new WP_REST_Response($columns, 200);
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error in get_google_sheet_columns: " . $e->getMessage(), "error");
                return new WP_REST_Response(['error' => $e->getMessage()], 400);
            }
        }

        public function get_google_drive_folders() {
            try {
                $this->check_google_auth_status();
                
                WP_AI_Workflows_Utilities::debug_log("Fetching Google Drive folders", "debug");
                
                $google_service = new WP_AI_Workflows_Google_Service();
                $folders = $google_service->list_drive_folders();
                
                WP_AI_Workflows_Utilities::debug_log("Successfully fetched drive folders", "debug", [
                    'count' => count($folders)
                ]);
                
                return new WP_REST_Response($folders, 200);
                
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error in get_google_drive_folders", "error", [
                    'error_message' => $e->getMessage()
                ]);
                
                if (strpos($e->getMessage(), 'Google authorization has expired') !== false) {
                    return new WP_Error(
                        'google_auth_expired',
                        $e->getMessage(),
                        array('status' => 401, 'requires_reauth' => true)
                    );
                }
                
                return new WP_Error('google_drive_error', $e->getMessage(), array('status' => 500));
            }
        }

        public function get_google_triggers($request) {
            $workflow_id = $request->get_param('workflow_id'); // Optional filter
            $google_triggers = new WP_AI_Workflows_Google_Triggers();
            
            if ($workflow_id) {
                $triggers = $google_triggers->get_triggers_for_workflow($workflow_id);
            } else {
                global $wpdb;
                $table_name = $wpdb->prefix . 'wp_ai_workflows_google_triggers';
                $triggers = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
            }
    
            return new WP_REST_Response($triggers, 200);
        }
    
        public function create_google_trigger($request) {
            $params = $request->get_json_params();
            
            // Validate required fields
            $required_fields = ['workflow_id', 'trigger_type', 'item_id', 'polling_frequency'];
            foreach ($required_fields as $field) {
                if (!isset($params[$field])) {
                    return new WP_Error(
                        'missing_field',
                        "Missing required field: $field",
                        array('status' => 400)
                    );
                }
            }
    
            $google_triggers = new WP_AI_Workflows_Google_Triggers();
            $trigger_id = $google_triggers->save_trigger($params);
    
            if ($trigger_id === false) {
                return new WP_Error(
                    'trigger_creation_failed',
                    'Failed to create trigger',
                    array('status' => 500)
                );
            }
    
            // Return the newly created trigger
            $trigger = $google_triggers->get_trigger($trigger_id);
            return new WP_REST_Response($trigger, 201);
        }
    
        public function get_google_trigger($request) {
            $trigger_id = $request['id'];
            $google_triggers = new WP_AI_Workflows_Google_Triggers();
            $trigger = $google_triggers->get_trigger($trigger_id);
    
            if (!$trigger) {
                return new WP_Error(
                    'trigger_not_found',
                    'Trigger not found',
                    array('status' => 404)
                );
            }
    
            return new WP_REST_Response($trigger, 200);
        }
    
        public function update_google_trigger($request) {
            $trigger_id = $request['id'];
            $params = $request->get_json_params();
            
            // Ensure trigger exists
            $google_triggers = new WP_AI_Workflows_Google_Triggers();
            $existing_trigger = $google_triggers->get_trigger($trigger_id);
            
            if (!$existing_trigger) {
                return new WP_Error(
                    'trigger_not_found',
                    'Trigger not found',
                    array('status' => 404)
                );
            }
    
            // Add the ID to the params
            $params['id'] = $trigger_id;
            
            // Update the trigger
            $result = $google_triggers->save_trigger($params);
            
            if ($result === false) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update trigger',
                    array('status' => 500)
                );
            }
    
            // Return the updated trigger
            $updated_trigger = $google_triggers->get_trigger($trigger_id);
            return new WP_REST_Response($updated_trigger, 200);
        }
    
        public function delete_google_trigger($request) {
            $trigger_id = $request['id'];
            $google_triggers = new WP_AI_Workflows_Google_Triggers();
            
            // Ensure trigger exists
            $existing_trigger = $google_triggers->get_trigger($trigger_id);
            if (!$existing_trigger) {
                return new WP_Error(
                    'trigger_not_found',
                    'Trigger not found',
                    array('status' => 404)
                );
            }
    
            // Delete the trigger
            $result = $google_triggers->delete_trigger($trigger_id);
            
            if ($result === false) {
                return new WP_Error(
                    'delete_failed',
                    'Failed to delete trigger',
                    array('status' => 500)
                );
            }
    
            return new WP_REST_Response(null, 204);
        }

        public function handle_attachment_upload($request) {
            if (!function_exists('wp_handle_upload')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }
        
            $files = $request->get_file_params();
            if (empty($files['file'])) {
                return new WP_Error('no_file', 'No file uploaded', array('status' => 400));
            }
        
            $upload_overrides = array('test_form' => false);
            $movefile = wp_handle_upload($files['file'], $upload_overrides);
        
            if ($movefile && !isset($movefile['error'])) {
                $attachment = array(
                    'post_mime_type' => $movefile['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );
        
                $attach_id = wp_insert_attachment($attachment, $movefile['file']);
        
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
                wp_update_attachment_metadata($attach_id, $attach_data);
        
                return new WP_REST_Response(array(
                    'id' => $attach_id,
                    'url' => wp_get_attachment_url($attach_id)
                ), 200);
            }
        
            return new WP_Error('upload_error', $movefile['error'], array('status' => 500));
        }
        
        public function get_media_library_items($request) {
            $query_args = array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 20,
                'paged' => $request->get_param('page') ?: 1
            );
        
            $query = new WP_Query($query_args);
            $items = array_map(function($post) {
                return array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'url' => wp_get_attachment_url($post->ID),
                    'type' => get_post_mime_type($post->ID)
                );
            }, $query->posts);
        
            return new WP_REST_Response(array(
                'items' => $items,
                'total' => $query->found_posts,
                'pages' => $query->max_num_pages
            ), 200);
        }

        public function search_unsplash($request) {
            $params = $request->get_json_params();
            $search_term = sanitize_text_field($params['searchTerm']);
            $orientation = sanitize_text_field($params['orientation']);
            $random_result = isset($params['randomResult']) ? (bool)$params['randomResult'] : false;
            $image_size = isset($params['imageSize']) ? sanitize_text_field($params['imageSize']) : 'regular';
        
            $api_key = WP_AI_Workflows_Utilities::get_unsplash_api_key();
            if (empty($api_key)) {
                return new WP_Error('unsplash_api_key_missing', 'Unsplash API key is not set', array('status' => 403));
            }
        
            $query_params = array(
                'query' => $search_term,
                'per_page' => $random_result ? 10 : 1,
            );
        
            if ($orientation !== 'all') {
                $query_params['orientation'] = $orientation;
            }
        
            $url = add_query_arg($query_params, 'https://api.unsplash.com/search/photos');
        
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Client-ID ' . $api_key,
                    'Accept-Version' => 'v1'
                )
            ));
        
            if (is_wp_error($response)) {
                return new WP_Error('unsplash_api_error', $response->get_error_message(), array('status' => 500));
            }
        
            $response_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
        
            if ($response_code === 429) {
                return new WP_Error('rate_limit_exceeded', 'Unsplash API rate limit exceeded', array('status' => 429));
            }
        
            if ($response_code !== 200 || empty($body['results'])) {
                return new WP_Error('unsplash_api_error', 'Failed to fetch image from Unsplash', array('status' => $response_code));
            }
        
            // Get random result if enabled, otherwise get first result
            $result = $random_result ? 
                $body['results'][array_rand($body['results'])] : 
                $body['results'][0];
        
            // Just return the specific URL and basic info needed for preview
            return new WP_REST_Response([
                'url' => $result['urls'][$image_size] ?? $result['urls']['regular'],
                'thumb' => $result['urls']['thumb'], // For preview in the node
                'id' => $result['id']
            ], 200);
        }

        public function generate_workflow($request) {
            try {
                $prompt = $request->get_param('prompt');
                
                if (empty($prompt)) {
                    return new WP_Error(
                        'invalid_prompt',
                        'Prompt cannot be empty',
                        array('status' => 400)
                    );
                }
                
                $generator = new WP_AI_Workflows_Generator();
                $workflow = $generator->generate_workflow($prompt);
                
                return new WP_REST_Response($workflow, 200);
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log('REST API error', 'error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return new WP_Error(
                    'workflow_generation_failed',
                    $e->getMessage(),
                    array(
                        'status' => 500,
                        'error_details' => [
                            'message' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]
                    )
                );
            }
        }

        public function handle_chat_message($request) {
            try {
                // Extract all necessary parameters
                $workflow_id = $request->get_param('workflow_id');
                $message = $request->get_param('message');
                $session_id = $request->get_param('session_id');
                $page_context = $request->get_param('page_context');
                $is_preview = $request->get_param('preview') === true;
                
                // Add new parameter for dynamic initial message
                $is_initial_message = $request->get_param('is_initial_message') === true;
                
                // Validate required parameters
                if (empty($workflow_id) && !$is_preview) {
                    return new WP_Error(
                        'invalid_request',
                        'Workflow ID is required',
                        array('status' => 400)
                    );
                }
                
                if (empty($message)) {
                    return new WP_Error(
                        'invalid_request',
                        'Message is required',
                        array('status' => 400)
                    );
                }
                
                // Log page context if provided
                if (!empty($page_context)) {
                    WP_AI_Workflows_Utilities::debug_log("Received page context in chat request", "debug", [
                        'page_title' => $page_context['page_title'] ?? 'Not provided',
                        'page_type' => $page_context['page_type'] ?? 'Not provided',
                        'has_content' => !empty($page_context['content_summary']),
                        'has_product_info' => !empty($page_context['product_info'])
                    ]);
                }
                
                // Log if this is an initial message request
                if ($is_initial_message) {
                    WP_AI_Workflows_Utilities::debug_log("Processing dynamic initial message request", "debug", [
                        'workflow_id' => $workflow_id,
                        'has_page_context' => !empty($page_context),
                        'is_preview' => $is_preview
                    ]);
                }
                
                // Handle preview mode
                if ($is_preview) {
                    $preview_config = $request->get_param('preview_config');
                    if (empty($preview_config)) {
                        return new WP_Error(
                            'invalid_request',
                            'Preview configuration is required for preview mode',
                            array('status' => 400)
                        );
                    }
                    
                    // Create temporary chat handler for preview
                    $chat_handler = $this->create_preview_chat_handler($preview_config, $session_id);
                } else {
                    // Regular chat handler
                    $chat_handler = new WP_AI_Workflows_Chat_Handler($workflow_id, $session_id);
                }
                
                // Process the message with page context and initial message flag
                $response = $chat_handler->handle_message($message, false, $page_context, $is_initial_message);
                
                // Log if citations are included
                if (isset($response['citations']) && !empty($response['citations'])) {
                    WP_AI_Workflows_Utilities::debug_log("Response includes citations", "debug", [
                        'citation_count' => count($response['citations']),
                        'session_id' => $response['session_id']
                    ]);
                }
                
                // Create the response structure
                $api_response = array(
                    'type' => $response['type'],
                    'message' => $response['display_message'],
                    'action_data' => $response['type'] === 'action' ? array(
                        'action_id' => $response['action_id'],
                        'confidence' => $response['confidence'] ?? 1.0,
                        'extracted_params' => $response['action_data']
                    ) : null,
                    'session_id' => $chat_handler->get_session()->get_session_id()
                );
                
                // Add citations to the response if they exist
                if (isset($response['citations']) && !empty($response['citations'])) {
                    $api_response['citations'] = $response['citations'];
                    
                    WP_AI_Workflows_Utilities::debug_log("Including citations in API response", "debug", [
                        'citation_count' => count($response['citations']),
                        'api_response_keys' => array_keys($api_response)
                    ]);
                }
                
                // Log successful response
                WP_AI_Workflows_Utilities::debug_log("Chat response generated successfully", "debug", [
                    'response_type' => $response['type'],
                    'has_page_context' => !empty($page_context),
                    'is_preview' => $is_preview,
                    'is_initial_message' => $is_initial_message
                ]);
                
                return new WP_REST_Response($api_response, 200);
                
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Chat error", "error", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
        
                return new WP_Error(
                    'chat_error',
                    $e->getMessage(),
                    array('status' => 500)
                );
            }
        }

        public function stream_chat_message($request) {
            $workflow_id = $request->get_param('workflow_id');
            $message = $request->get_param('message');
            $session_id = $request->get_param('session_id');
            $page_context = $request->get_param('page_context');
            $preview = $request->get_param('preview');
            $preview_config = $request->get_param('preview_config');
            
            try {
                // Set headers for SSE immediately
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no'); // Important for Nginx
                
                // Disable output buffering
                if (ob_get_level()) ob_end_clean();
                ob_implicit_flush(true);
                
                // Send an initial message to establish the connection
                echo "data: " . json_encode(['content' => '']) . "\n\n";
                flush();
                
                if ($preview) {
                    // For preview mode
                    if (empty($preview_config)) {
                        echo "data: " . json_encode([
                            'error' => true, 
                            'message' => 'Preview configuration is required'
                        ]) . "\n\n";
                        echo "data: [DONE]\n\n";
                        exit;
                    }
                    
                    // Create temporary chat handler for preview
                    $handler = new WP_AI_Workflows_Chat_Handler(null);
                    
                    // Set preview configuration
                    $handler->set_preview_config($preview_config);
                    
                    // Handle streaming with provided configuration
                    $handler->handle_streaming_message($message, $page_context);
                    exit; // Streaming already handled
                } else {
                    // Regular streaming mode
                    if (empty($workflow_id)) {
                        echo "data: " . json_encode([
                            'error' => true, 
                            'message' => 'Workflow ID is required'
                        ]) . "\n\n";
                        echo "data: [DONE]\n\n";
                        exit;
                    }
                    
                    $handler = new WP_AI_Workflows_Chat_Handler($workflow_id, $session_id);
                    
                    // Handle streaming message
                    $handler->handle_streaming_message($message, $page_context);
                    exit; // Streaming already handled
                }
            } catch (Exception $e) {
                // Handle errors in SSE format
                echo "data: " . json_encode([
                    'error' => true,
                    'message' => $e->getMessage()
                ]) . "\n\n";
                echo "data: [DONE]\n\n";
                exit;
            }
        }
        
        // Also add this method to WP_AI_Workflows_Chat_Handler to support preview mode
        
        public function set_preview_config($config) {
            $this->model = $config['model'] ?? 'gpt-4o';
            $this->system_prompt = $config['system_prompt'] ?? '';
            $this->model_params = $config['model_params'] ?? [
                'temperature' => 1.0,
                'top_p' => 1.0,
                'max_tokens' => 4096
            ];
            $this->actions = $config['actions'] ?? [];
            $this->openai_tools = $config['openai_tools'] ?? null;

        }
        /**
         * Creates a temporary chat handler for preview mode
         * 
         * @param array $config Preview configuration
         * @param string|null $session_id Session ID
         * @return WP_AI_Workflows_Chat_Handler
         */
        private function create_preview_chat_handler($config, $session_id = null) {
            // Create a temporary workflow ID for preview
            $temp_workflow_id = 'preview-' . uniqid();
            
            $chat_handler = new WP_AI_Workflows_Chat_Handler($temp_workflow_id, $session_id);
            
            // Use reflection to set the required properties
            $reflection = new ReflectionClass($chat_handler);
            
            if (isset($config['model'])) {
                $property = $reflection->getProperty('model');
                $property->setAccessible(true);
                $property->setValue($chat_handler, $config['model']);
            }
            
            if (isset($config['system_prompt'])) {
                $property = $reflection->getProperty('system_prompt');
                $property->setAccessible(true);
                $property->setValue($chat_handler, $config['system_prompt']);
            }
            
            if (isset($config['model_params'])) {
                $property = $reflection->getProperty('model_params');
                $property->setAccessible(true);
                $property->setValue($chat_handler, $config['model_params']);
            }
            
            if (isset($config['actions'])) {
                $property = $reflection->getProperty('actions');
                $property->setAccessible(true);
                $property->setValue($chat_handler, $config['actions']);
            }
            
            return $chat_handler;
        }
        
        
        public function get_chat_history($request) {
            try {
                $workflow_id = $request->get_param('workflow_id');
                $session_id = $request->get_param('session_id');
                
                if (empty($workflow_id)) {
                    return new WP_Error(
                        'invalid_request',
                        'Workflow ID is required',
                        array('status' => 400)
                    );
                }
                
                $chat_handler = new WP_AI_Workflows_Chat_Handler($workflow_id, $session_id);
                $history = $chat_handler->get_session()->get_history();
                
                return new WP_REST_Response(array(
                    'success' => true,
                    'history' => $history,
                    'session_id' => $chat_handler->get_session()->get_session_id()
                ), 200);
                
            } catch (Exception $e) {
                return new WP_Error(
                    'chat_error',
                    $e->getMessage(),
                    array('status' => 400)
                );
            }
        }
        
        public function get_chat_config($request) {
            try {
                $workflow_id = $request['workflow_id'];
                WP_AI_Workflows_Utilities::debug_log("Getting chat config", "debug", [
                    'workflow_id' => $workflow_id
                ]);
        
                // Extract base workflow ID without node suffix if present
                $base_workflow_id = preg_replace('/-[\w\d]+$/', '', $workflow_id);
                
                // Get workflow using DBAL
                $workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($base_workflow_id);
        
                if (!$workflow) {
                    WP_AI_Workflows_Utilities::debug_log("Workflow not found", "error", [
                        'workflow_id' => $workflow_id,
                        'base_workflow_id' => $base_workflow_id
                    ]);
                    return new WP_Error('not_found', 'Workflow not found', array('status' => 404));
                }
        
                // Find chat node
                $chat_node = null;
                foreach ($workflow['nodes'] as $node) {
                    if ($node['type'] === 'chat') {
                        $chat_node = $node;
                        break;
                    }
                }
        
                if (!$chat_node) {
                    WP_AI_Workflows_Utilities::debug_log("Chat node not found", "error", [
                        'workflow_id' => $workflow_id
                    ]);
                    return new WP_Error('no_chat', 'No chat configuration found', array('status' => 404));
                }
        
                // Make sure showCitations has a default value if not set
                if (!isset($chat_node['data']['behavior']['showCitations'])) {
                    $chat_node['data']['behavior']['showCitations'] = true;
                }
        
                $config = array(
                    'design' => $chat_node['data']['design'],
                    'behavior' => $chat_node['data']['behavior'],
                    'model' => $chat_node['data']['model'],
                    'modelParams' => $chat_node['data']['modelParams'],
                    'systemPrompt' => $chat_node['data']['systemPrompt'],
                    'openaiTools' => isset($chat_node['data']['openaiTools']) ? $chat_node['data']['openaiTools'] : null
                );
        
                WP_AI_Workflows_Utilities::debug_log("Chat config retrieved", "debug", [
                    'workflow_id' => $workflow_id,
                    'config' => [
                        'model' => $config['model'],
                        'has_system_prompt' => !empty($config['systemPrompt']),
                        'has_openai_tools' => !empty($config['openaiTools']),
                        'show_citations' => $config['behavior']['showCitations']
                    ]
                ]);
        
                return new WP_REST_Response(array('config' => $config), 200);
        
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error getting chat config", "error", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return new WP_Error('error', $e->getMessage(), array('status' => 500));
            }
        }

       
        public function get_chat_action_result($request) {
            try {
                $session_id = $request->get_param('session_id');
                
                global $wpdb;
                $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
                
                // First check for result
                $result_key = 'wp_ai_workflows_action_result_' . $session_id;
                $action_result = get_transient($result_key);
                
                if ($action_result) {
                    // Found completed result
                    delete_transient($result_key);
                    delete_transient('wp_ai_workflows_pending_execution_' . $session_id);
                    
                    return new WP_REST_Response([
                        'success' => true,
                        'has_result' => true,
                        'result' => $action_result
                    ], 200);
                }
        
                // Check for pending execution
                $execution_key = 'wp_ai_workflows_pending_execution_' . $session_id;
                $pending_data = get_transient($execution_key);
        
                if ($pending_data && isset($pending_data['execution_id'])) {
                    // Check execution status in DB
                    $execution = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, status FROM $executions_table 
                        WHERE id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                        $pending_data['execution_id']
                    ));
        
                    if ($execution) {
                        $is_pending = in_array($execution->status, ['processing', 'paused', null]);
                        
                        // REMOVED THE EXPLICIT TRIGGER - Let the original scheduled task handle this
                        // Just provide status information to the client
        
                        return new WP_REST_Response([
                            'success' => true,
                            'has_result' => false,
                            'has_pending' => $is_pending,
                            'status' => $execution->status
                        ], 200);
                    }
                }
        
                // Check for any recent execution as fallback
                $recent_execution = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status FROM $executions_table 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    ORDER BY id DESC LIMIT 1"
                ));
        
                if ($recent_execution) {
                    $is_pending = in_array($recent_execution->status, ['processing', 'paused', null]);
                    return new WP_REST_Response([
                        'success' => true,
                        'has_result' => false,
                        'has_pending' => $is_pending,
                        'status' => $recent_execution->status
                    ], 200);
                }
        
                return new WP_REST_Response([
                    'success' => true,
                    'has_result' => false,
                    'has_pending' => false
                ], 200);
        
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error checking action result", "error", [
                    'error' => $e->getMessage()
                ]);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Internal server error'
                ], 500);
            }
        }

        public function handle_chat_events($request) {
            $session_id = $request->get_param('session_id');
            
            if (empty($session_id)) {
                return new WP_Error('missing_session_id', 'Session ID is required', array('status' => 400));
            }
            
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable buffering for Nginx
            
            WP_AI_Workflows_Utilities::debug_log("SSE connection established", "debug", [
                'session_id' => $session_id,
                'server_time' => current_time('mysql')
            ]);
            
            // Send an initial message to confirm connection
            echo "data: " . json_encode(['type' => 'connected', 'session_id' => $session_id, 'timestamp' => time()]) . "\n\n";
            flush();
            
            // Store connection in active sessions
            $active_sessions = get_option('wp_ai_workflows_active_sse_sessions', []);
            $active_sessions[$session_id] = time();
            update_option('wp_ai_workflows_active_sse_sessions', $active_sessions);
            
            // Set up a long polling loop
            $timeout = 30 * 60; // 30 minutes timeout
            $start = time();
            $check_interval = 1; // Check every 1 second
            
            while (time() - $start < $timeout) {
                // Check for messages
                $message_key = 'wp_ai_workflows_sse_message_' . $session_id;
                $message = get_transient($message_key);
                
                if ($message) {
                    // Log the message being sent
                    WP_AI_Workflows_Utilities::debug_log("SSE sending message", "debug", [
                        'session_id' => $session_id,
                        'message_type' => $message['type'],
                        'timestamp' => current_time('mysql')
                    ]);
                    
                    // Send the message
                    echo "data: " . json_encode($message) . "\n\n";
                    flush();
                    
                    // Delete the transient
                    delete_transient($message_key);
                }
                
                // Check if the client is still connected
                if (connection_aborted()) {
                    WP_AI_Workflows_Utilities::debug_log("SSE connection aborted", "debug", [
                        'session_id' => $session_id
                    ]);
                    break;
                }
                
                // Sleep for a short time to avoid CPU overuse
                sleep($check_interval);
            }
            
            // Clean up
            $active_sessions = get_option('wp_ai_workflows_active_sse_sessions', []);
            unset($active_sessions[$session_id]);
            update_option('wp_ai_workflows_active_sse_sessions', $active_sessions);
            
            WP_AI_Workflows_Utilities::debug_log("SSE connection closed", "debug", [
                'session_id' => $session_id,
                'duration' => time() - $start
            ]);
            
            exit;
        }

        public function check_action_status($request) {
            $session_id = $request->get_param('session_id');
            
            WP_AI_Workflows_Utilities::debug_log("Action status check request", "debug", [
                'session_id' => $session_id,
                'endpoint' => 'check_action_status'
            ]);
            
            if (empty($session_id)) {
                WP_AI_Workflows_Utilities::debug_log("Missing session ID", "error");
                return new WP_Error('missing_session_id', 'Session ID is required');
            }
            
            // Check if there's a pending execution for this session
            $execution_key = 'wp_ai_workflows_pending_execution_' . $session_id;
            $pending_execution = get_transient($execution_key);
            
            WP_AI_Workflows_Utilities::debug_log("Checking pending execution", "debug", [
                'execution_key' => $execution_key,
                'has_pending' => !empty($pending_execution)
            ]);
            
            if (!$pending_execution) {
                // No pending execution
                return new WP_REST_Response([
                    'has_result' => false
                ]);
            }
            
            WP_AI_Workflows_Utilities::debug_log("Found pending execution", "debug", [
                'execution_id' => $pending_execution['execution_id'],
                'action_id' => $pending_execution['action_id']
            ]);
            
            // Check the execution status
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
            $execution = $wpdb->get_row($wpdb->prepare(
                "SELECT status, output_data FROM $table_name WHERE id = %d",
                $pending_execution['execution_id']
            ));
            
            if (!$execution) {
                WP_AI_Workflows_Utilities::debug_log("Execution not found", "error", [
                    'execution_id' => $pending_execution['execution_id']
                ]);
                return new WP_REST_Response([
                    'has_result' => false,
                    'status' => 'not_found'
                ]);
            }
            
            WP_AI_Workflows_Utilities::debug_log("Execution status retrieved", "debug", [
                'execution_id' => $pending_execution['execution_id'],
                'status' => $execution->status
            ]);
            
            if ($execution->status !== 'completed') {
                // Still processing
                return new WP_REST_Response([
                    'has_result' => false,
                    'status' => $execution->status
                ]);
            }
            
            WP_AI_Workflows_Utilities::debug_log("Execution completed, generating result", "info");
            
            // Execution is complete - generate a result message
            $output_data = json_decode($execution->output_data, true);
            
            try {
                // Create a chat handler for this session to process the result
                $chat_handler = new WP_AI_Workflows_Chat_Handler(
                    $pending_execution['workflow_id'], 
                    $session_id
                );
                
                // Process the output to get a user-friendly message
                $result_message = $chat_handler->generate_result_message([
                    'execution_id' => $pending_execution['execution_id'],
                    'output_data' => $output_data
                ]);
                
                WP_AI_Workflows_Utilities::debug_log("Generated result message", "debug", [
                    'message_length' => strlen($result_message)
                ]);
                
                // Add the result message to the chat history
                $chat_handler->get_session()->add_message('assistant', $result_message);
                
                // Clear the pending execution transient
                delete_transient($execution_key);
                
                WP_AI_Workflows_Utilities::debug_log("Returning action result", "info", [
                    'has_result' => true,
                    'message_preview' => substr($result_message, 0, 50) . '...'
                ]);
                
                // Return the result
                return new WP_REST_Response([
                    'has_result' => true,
                    'message' => $result_message,
                    'role' => 'assistant'
                ]);
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Error generating result message", "error", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return new WP_REST_Response([
                    'has_result' => false,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ]);
            }
        }

        public function get_chat_actions($request) {
            try {
                $workflow_id = $request['workflow_id'];
                
                // Extract base workflow ID
                $base_workflow_id = preg_replace('/-[\w\d]+$/', '', $workflow_id);
                
                // Get workflow using DBAL
                $workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($base_workflow_id);
        
                if (!$workflow) {
                    return new WP_Error('not_found', 'Workflow not found', array('status' => 404));
                }
        
                // Find chat node
                $chat_node = null;
                foreach ($workflow['nodes'] as $node) {
                    if ($node['type'] === 'chat') {
                        $chat_node = $node;
                        break;
                    }
                }
        
                if (!$chat_node || empty($chat_node['data']['actions'])) {
                    return new WP_REST_Response(array('actions' => []), 200);
                }
        
                return new WP_REST_Response(array(
                    'actions' => $chat_node['data']['actions']
                ), 200);
        
            } catch (Exception $e) {
                return new WP_Error('error', $e->getMessage(), array('status' => 500));
            }
        }
        
        public function handle_action_submission($request) {
            try {
                $workflow_id = $request->get_param('workflow_id');
                $action_id = $request->get_param('action_id');
                $action_data = $request->get_param('action_data');
                
                if (empty($workflow_id) || empty($action_id)) {
                    return new WP_Error(
                        'invalid_request',
                        'Workflow ID and action ID are required',
                        array('status' => 400)
                    );
                }
        
                // Trigger workflow execution for this action
                $result = WP_AI_Workflows_Workflow::execute_workflow(
                    $workflow_id,
                    $action_data,
                    null,
                    null,
                    $action_id
                );
        
                if (is_wp_error($result)) {
                    return $result;
                }
        
                return new WP_REST_Response(array(
                    'success' => true,
                    'execution_id' => $result['execution_id'] ?? null
                ), 200);
        
            } catch (Exception $e) {
                return new WP_Error('error', $e->getMessage(), array('status' => 500));
            }
        }

        public function get_chat_logs($request) {
            global $wpdb;
            $sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
            $messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        
            // Get all workflow names 
            $workflows = WP_AI_Workflows_Workflow_DBAL::get_all_workflows();
            $workflow_names = array();
            foreach ($workflows as $workflow) {
                $workflow_names[$workflow['id']] = $workflow['name'];
            }
        
            $page = $request->get_param('page') ?: 1;
            $per_page = $request->get_param('per_page') ?: 10;
            $search = $request->get_param('search');
            $workflow_id = $request->get_param('workflow_id');
            $start_date = $request->get_param('start_date');
            $end_date = $request->get_param('end_date');
        
            // Build query conditions
            $where_clauses = [];
            $where_values = [];
        
            if ($search) {
                $where_clauses[] = "(s.session_id LIKE %s OR m.content LIKE %s)";
                $where_values[] = '%' . $wpdb->esc_like($search) . '%';
                $where_values[] = '%' . $wpdb->esc_like($search) . '%';
            }
        
            if ($workflow_id) {
                $where_clauses[] = "s.workflow_id = %s";
                $where_values[] = $workflow_id;
            }
        
            if ($start_date) {
                $where_clauses[] = "s.created_at >= %s";
                $where_values[] = $start_date . ' 00:00:00';
            }
        
            if ($end_date) {
                $where_clauses[] = "s.created_at <= %s";
                $where_values[] = $end_date . ' 23:59:59';
            }
        
            $where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        
            // Get total count
            $count_query = $wpdb->prepare(
                "SELECT COUNT(DISTINCT s.session_id) 
                FROM $sessions_table s 
                LEFT JOIN $messages_table m ON s.session_id = m.session_id 
                $where_sql",
                $where_values
            );
            
            $total = $wpdb->get_var($count_query);
        
            // Get paginated results
            $offset = ($page - 1) * $per_page;
            $query = $wpdb->prepare(
                "SELECT 
                    s.session_id,
                    s.workflow_id,
                    s.created_at,
                    s.updated_at,
                    COUNT(DISTINCT m.id) as message_count
                FROM $sessions_table s
                LEFT JOIN $messages_table m ON s.session_id = m.session_id
                $where_sql
                GROUP BY s.session_id, s.workflow_id, s.created_at, s.updated_at
                ORDER BY s.updated_at DESC
                LIMIT %d OFFSET %d",
                array_merge($where_values, array($per_page, $offset))
            );
        
            $logs = $wpdb->get_results($query);
            
            // Add workflow names to the results
            foreach ($logs as &$log) {
                $log->workflow_name = isset($workflow_names[$log->workflow_id]) 
                    ? $workflow_names[$log->workflow_id] 
                    : 'Unknown Workflow';
            }
        
            WP_AI_Workflows_Utilities::debug_log("Chat logs fetched");
        
            return new WP_REST_Response([
                'logs' => $logs,
                'total' => (int) $total
            ], 200);
        }
        
        public function get_chat_messages($request) {
            global $wpdb;
            $messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
            $session_id = $request->get_param('session_id');
        
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $messages_table WHERE session_id = %s ORDER BY created_at ASC",
                $session_id
            ));
        
            return new WP_REST_Response($messages, 200);
        }
        
        public function get_chat_statistics($request) {
            global $wpdb;
            $sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
            $messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        
            // Get total sessions
            $total_sessions = $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table");
        
            // Get active sessions today
            $active_today = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id) FROM $sessions_table WHERE DATE(updated_at) = %s",
                current_time('Y-m-d')
            ));
        
            // Get total messages and average per chat
            $message_stats = $wpdb->get_row(
                "SELECT 
                    COUNT(*) as total_messages,
                    COUNT(*) / COUNT(DISTINCT session_id) as avg_messages_per_chat
                FROM $messages_table"
            );
        
            return new WP_REST_Response([
                'totalSessions' => (int) $total_sessions,
                'activeToday' => (int) $active_today,
                'totalMessages' => (int) $message_stats->total_messages,
                'averageMessagesPerChat' => round($message_stats->avg_messages_per_chat, 1)
            ], 200);
        }

        public function preview_rss_feed($request) {
            $feed_url = $request->get_param('feedUrl');
            
            if (empty($feed_url)) {
                return new WP_Error('invalid_url', 'Feed URL is required');
            }
        
            include_once(ABSPATH . WPINC . '/feed.php');
            $rss = fetch_feed($feed_url);
            
            if (is_wp_error($rss)) {
                return new WP_Error('feed_error', $rss->get_error_message());
            }
        
            $items = $rss->get_items(0, 5); // Get first 5 items for preview
            
            $preview_data = array_map(function($item) {
                return [
                    'title' => $item->get_title(),
                    'categories' => array_map(function($cat) {
                        return $cat->get_label();
                    }, $item->get_categories() ?: [])
                ];
            }, $items);
        
            return new WP_REST_Response([
                'items' => $preview_data
            ], 200);
        }
        
        public function handle_test_api_call($request) {
            try {
                $params = $request->get_params();
                
                // Validate required parameters
                if (empty($params['method']) || empty($params['url'])) {
                    throw new Exception('Method and URL are required');
                }
        
                // URL validation
                if (!wp_http_validate_url($params['url'])) {
                    throw new Exception('Invalid URL format');
                }
        
                // Method validation
                $valid_methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
                $method = strtoupper(sanitize_text_field($params['method']));
                if (!in_array($method, $valid_methods)) {
                    throw new Exception('Invalid HTTP method');
                }
        
                // Process headers
                $headers = [];
                if (!empty($params['headers']) && is_array($params['headers'])) {
                    foreach ($params['headers'] as $key => $value) {
                        if (isset($value['name']) && isset($value['value'])) {
                            $header_name = str_replace(' ', '-', trim($value['name']));
                            $headers[] = [
                                'name' => $header_name,
                                'value' => trim($value['value'])
                            ];
                        } else {
                            $header_name = str_replace(' ', '-', trim($key));
                            $headers[] = [
                                'name' => $header_name,
                                'value' => trim($value)
                            ];
                        }
                    }
                }
        
                // Process query parameters
                $query_params = [];
                if (!empty($params['queryParams']) && is_array($params['queryParams'])) {
                    foreach ($params['queryParams'] as $param) {
                        if (!empty($param['key'])) {
                            $query_params[] = [
                                'key' => sanitize_text_field($param['key']),
                                'value' => sanitize_text_field($param['value'] ?? '')
                            ];
                        }
                    }
                }
        
                // Process body
                $body = null;
                if (!empty($params['body'])) {
                    $is_json = false;
                    foreach ($headers as $header) {
                        if (strtolower($header['name']) === 'content-type' && 
                            strpos(strtolower($header['value']), 'application/json') !== false) {
                            $is_json = true;
                            break;
                        }
                    }
        
                    if ($is_json) {
                        if (is_string($params['body'])) {
                            $decoded = json_decode($params['body'], true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                throw new Exception('Invalid JSON in request body: ' . json_last_error_msg());
                            }
                            $body = $params['body'];
                        } else if (is_array($params['body'])) {
                            $body = wp_json_encode($params['body']);
                            if ($body === false) {
                                throw new Exception('Failed to encode body as JSON');
                            }
                        } else {
                            throw new Exception('Invalid body format for JSON content-type');
                        }
                    } else {
                        $body = is_array($params['body']) ? wp_json_encode($params['body']) : $params['body'];
                    }
                }
        
                // Add default content type if needed
                $has_content_type = false;
                foreach ($headers as $header) {
                    if (strtolower($header['name']) === 'content-type') {
                        $has_content_type = true;
                        break;
                    }
                }
                if (!$has_content_type && $body !== null) {
                    $headers[] = [
                        'name' => 'Content-Type',
                        'value' => 'application/json'
                    ];
                }
        
                // Process authentication
                if (!empty($params['auth']) && $params['auth']['type'] !== 'none') {
                    $test_node_auth = [
                        'type' => sanitize_text_field($params['auth']['type']),
                        'username' => isset($params['auth']['username']) ? sanitize_text_field($params['auth']['username']) : '',
                        'password' => isset($params['auth']['password']) ? sanitize_text_field($params['auth']['password']) : '',
                        'token' => isset($params['auth']['token']) ? sanitize_text_field($params['auth']['token']) : '',
                        'apiKey' => isset($params['auth']['apiKey']) ? sanitize_text_field($params['auth']['apiKey']) : '',
                        'apiKeyName' => isset($params['auth']['apiKeyName']) ? sanitize_text_field($params['auth']['apiKeyName']) : ''
                    ];
        
                    switch ($params['auth']['type']) {
                        case 'basic':
                            if (!empty($test_node_auth['username']) && !empty($test_node_auth['password'])) {
                                $headers[] = [
                                    'name' => 'Authorization',
                                    'value' => 'Basic ' . base64_encode($test_node_auth['username'] . ':' . $test_node_auth['password'])
                                ];
                                $test_node_auth['username'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt($test_node_auth['username']);
                                $test_node_auth['password'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt($test_node_auth['password']);
                            }
                            break;
                        case 'bearer':
                            if (!empty($test_node_auth['token'])) {
                                $headers[] = [
                                    'name' => 'Authorization',
                                    'value' => 'Bearer ' . $test_node_auth['token']
                                ];
                                $test_node_auth['token'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt($test_node_auth['token']);
                            }
                            break;
                        case 'apiKey':
                            if (!empty($test_node_auth['apiKey']) && !empty($test_node_auth['apiKeyName'])) {
                                $headers[] = [
                                    'name' => $test_node_auth['apiKeyName'],
                                    'value' => $test_node_auth['apiKey']
                                ];
                                $test_node_auth['apiKey'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt($test_node_auth['apiKey']);
                            }
                            break;
                    }
                } else {
                    $test_node_auth = ['type' => 'none'];
                }
        
                // Build test node
                $test_node = [
                    'id' => 'test-' . uniqid(),
                    'type' => 'apiCall',
                    'data' => [
                        'method' => $method,
                        'url' => esc_url_raw($params['url']),
                        'headers' => $headers,
                        'queryParams' => $query_params,
                        'body' => $body,
                        'auth' => $test_node_auth,
                        'responseConfig' => [
                            'timeout' => isset($params['responseConfig']['timeout']) 
                                ? max(1000, min(300000, intval($params['responseConfig']['timeout'])))
                                : 30000,
                            'retryCount' => isset($params['responseConfig']['retryCount'])
                                ? max(0, min(5, intval($params['responseConfig']['retryCount'])))
                                : 0,
                            'jsonPath' => isset($params['responseConfig']['jsonPath'])
                                ? sanitize_text_field($params['responseConfig']['jsonPath'])
                                : '',
                            'cacheResponse' => false
                        ]
                    ]
                ];
        
                // Execute test
                $result = WP_AI_Workflows_Node_Execution::execute_api_call_node(
                    $test_node,
                    [],
                    'test-' . uniqid()
                );
                
        
                // Process result
                if (isset($result['type']) && $result['type'] === 'error') {
                    throw new Exception($result['content']);
                }
        
                // Extract data based on pattern if specified
                $extracted_data = null;
                if (!empty($test_node['data']['responseConfig']['jsonPath']) && 
                    isset($result['content']['data'])) {
                    
                    try {
                        $response_data = $result['content']['data'];
                        
                        // If response_data is a JSON string, decode it
                        if (is_string($response_data)) {
                            $decoded = json_decode($response_data, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $response_data = $decoded;
                            }
                        }
        
                        // Function to handle nested value extraction
                        $extract_specific_values = function($array, $path) {
                            // If path is empty, return empty result
                            if (empty($path)) {
                                return [];
                            }
                        
                            // Handle numeric index access first, before any other processing
                            if (preg_match('/^(\d+)\.(.+)$/', $path, $matches)) {
                                $index = intval($matches[1]);
                                $field = $matches[2];
                                
                                // Only process if the index exists
                                if (!isset($array[$index])) {
                                    return [];
                                }
                            
                                $item = $array[$index];
                                
                                // For direct field access, return immediately
                                if (!str_contains($field, '.')) {
                                    return isset($item[$field]) ? [$item[$field]] : [];
                                }
                                
                                // For nested fields, traverse the path
                                $current_value = $item;
                                $field_parts = explode('.', $field);
                                
                                foreach ($field_parts as $part) {
                                    if (!is_array($current_value) || !isset($current_value[$part])) {
                                        return [];
                                    }
                                    $current_value = $current_value[$part];
                                }
                                
                                return [$current_value];
                            }
                            
                            // Function for recursive value extraction (only used for non-numeric paths)
                            $extract_values = function($array, $key) use (&$extract_values) {
                                $results = [];
                                foreach ($array as $k => $v) {
                                    if ($k === $key) {
                                        $results[] = $v;
                                    } elseif (is_array($v)) {
                                        $results = array_merge($results, $extract_values($v, $key));
                                    }
                                }
                                return $results;
                            };
                            
                            // Split path into parts
                            $parts = explode('.', $path);
                            
                            // If first part is an array key, get that array first
                            if (isset($array[$parts[0]]) && is_array($array[$parts[0]])) {
                                $array = $array[$parts[0]];
                                array_shift($parts);
                                $path = implode('.', $parts);
                            }
                        
                            // Case 1: Simple field name (e.g., "email")
                            if (!str_contains($path, '.')) {
                                return $extract_values($array, $path);
                            }
                        
                            // Case 2: Field equality condition (e.g., "id=187.email" or 'email="test@test.com".id')
                            if (preg_match('/^(\w+)=([^\.]+)\.(.+)$/', $path, $matches)) {
                                $filterField = $matches[1];
                                $filterValue = trim($matches[2], '"\'');
                                $targetField = $matches[3];
                                
                                $results = [];
                                foreach ($array as $item) {
                                    if (isset($item[$filterField]) && 
                                        (string)$item[$filterField] === (string)$filterValue && 
                                        isset($item[$targetField])) {
                                        $results[] = $item[$targetField];
                                    }
                                }
                                return $results;
                            }
                        
                            // Fallback to simple field extraction
                            return $extract_values($array, $path);
                        };
        
                        if (is_array($response_data)) {
                            $extracted_data = $extract_specific_values($response_data, $test_node['data']['responseConfig']['jsonPath']);
                            
                            if (is_array($extracted_data) && count($extracted_data) === 1) {
                                $extracted_data = reset($extracted_data);
                            }

                            WP_AI_Workflows_Utilities::debug_log("Test value extraction completed", "debug", [
                                'pattern' => $test_node['data']['responseConfig']['jsonPath'],
                                'extracted_count' => count($extracted_data),
                                'results' => $extracted_data,
                                'response_structure' => array_keys($response_data)
                            ]);
                        }
        
                    } catch (Exception $e) {
                        WP_AI_Workflows_Utilities::debug_log("Test value extraction failed", "error", [
                            'error' => $e->getMessage(),
                            'path' => $test_node['data']['responseConfig']['jsonPath']
                        ]);
                    }
                }

        
                // Return response
                return new WP_REST_Response([
                    'success' => true,
                    'status' => $result['content']['status'] ?? 200,
                    'headers' => $result['content']['headers'] ?? [],
                    'data' => $result['content']['data'] ?? null,
                    'extractedData' => $extracted_data,
                    'raw_response' => $result['content']
                ], 200);
        
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("API test failed", "error", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return new WP_REST_Response([
                    'success' => false,
                    'error' => $e->getMessage()
                ], 400);
            }
        }
        
        private function get_content_type_from_headers($headers) {
            foreach ($headers as $header) {
                if (strtolower($header['name']) === 'content-type') {
                    return $header['value'];
                }
            }
            return null;
        }

        public function get_task_roles() {
            $task_roles = get_option('wp_ai_workflows_task_roles', ['administrator']);
            return new WP_REST_Response($task_roles, 200);
        }
        
        public function update_task_roles($request) {
            $roles = $request->get_json_params();
            
            if (!is_array($roles)) {
                return new WP_Error('invalid_roles', 'Roles must be an array', array('status' => 400));
            }
            
            // Sanitize roles
            $roles = array_map('sanitize_text_field', $roles);
            
            // Get WordPress roles object
            global $wp_roles;
            
            // Remove capability from all roles first
            foreach ($wp_roles->roles as $role_name => $role) {
                $role_object = get_role($role_name);
                if ($role_object) {
                    $role_object->remove_cap('manage_workflow_tasks');
                }
            }
        
            // Add capability to selected roles
            foreach ($roles as $role_name) {
                $role_object = get_role($role_name);
                if ($role_object) {
                    $role_object->add_cap('manage_workflow_tasks');
                }
            }
        
            update_option('wp_ai_workflows_task_roles', $roles);
            
            return new WP_REST_Response(['success' => true], 200);
        }

    public function get_roles($request) {
            global $wp_roles;
            $roles = $wp_roles->get_names();
            return new WP_REST_Response($roles, 200);
    }


    /**
     * Get cost statistics for the specified timeframe
     */
    public static function get_cost_statistics($request) {
        try {
            $timeframe = $request->get_param('timeframe') ?? '30days';
            $start_date = $request->get_param('start_date');
            $end_date = $request->get_param('end_date');
            
            if (!$start_date || !$end_date) {
                $end_date = current_time('mysql');
                $start_date = match($timeframe) {
                    '7days' => date('Y-m-d H:i:s', strtotime('-7 days')),
                    '30days' => date('Y-m-d H:i:s', strtotime('-30 days')),
                    '90days' => date('Y-m-d H:i:s', strtotime('-90 days')),
                    'year' => date('Y-m-d H:i:s', strtotime('-1 year')),
                    default => date('Y-m-d H:i:s', strtotime('-30 days'))
                };
            }

            $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
            
            // Get model statistics
            $stats = $cost_manager->get_cost_statistics(null, $start_date, $end_date);
            
            // Calculate overview metrics
            $overview = [
                'total_cost' => array_sum(array_column($stats, 'total_cost')),
                'total_calls' => array_sum(array_column($stats, 'total_executions')),
                'total_tokens' => array_sum(array_column($stats, 'total_prompt_tokens')) + 
                                array_sum(array_column($stats, 'total_completion_tokens'))
            ];

            // Get daily cost breakdown
            $daily_costs = $cost_manager->get_daily_costs($start_date, $end_date);
            
            // Get current pricing settings
            $pricing = $cost_manager->get_cost_settings();

            return new WP_REST_Response([
                'overview' => $overview,
                'model_stats' => $stats,
                'daily_costs' => $daily_costs,
                'pricing' => $pricing,
                'timeframe' => [
                    'start' => $start_date,
                    'end' => $end_date
                ]
            ], 200);

        } catch (Exception $e) {
            return new WP_Error(
                'cost_statistics_error',
                'Failed to retrieve cost statistics: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get cost settings
     */
    public function get_cost_settings($request) {
        try {
            $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
            $settings = $cost_manager->get_cost_settings();

            return new WP_REST_Response($settings, 200);
        } catch (Exception $e) {
            return new WP_Error(
                'cost_settings_error',
                'Failed to retrieve cost settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Update cost settings
     */
    public function update_cost_settings($request) {
        try {
            $costs = $request->get_json_params();
            
            WP_AI_Workflows_Utilities::debug_log("Received cost update request", "debug", [
                'raw_costs' => $costs
            ]);
    
            // Ensure we're working with the correct array format
            if (!is_array($costs)) {
                WP_AI_Workflows_Utilities::debug_log("Invalid data format", "error", [
                    'received' => $costs
                ]);
                return new WP_Error(
                    'invalid_request',
                    'Invalid cost settings format. Expected array of costs.',
                    ['status' => 400]
                );
            }
    
            $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
            $updated = 0;
    
            foreach ($costs as $cost) {
                WP_AI_Workflows_Utilities::debug_log("Processing cost entry", "debug", [
                    'cost_entry' => $cost
                ]);
    
                // Validate required fields
                if (!isset($cost['provider']) || !isset($cost['model']) || 
                    !isset($cost['input_cost']) || !isset($cost['output_cost'])) {
                    WP_AI_Workflows_Utilities::debug_log("Missing required fields", "warning", [
                        'cost' => $cost,
                        'missing' => array_diff(
                            ['provider', 'model', 'input_cost', 'output_cost'],
                            array_keys($cost)
                        )
                    ]);
                    continue;
                }
    
                $result = $cost_manager->update_cost_setting(
                    $cost['provider'],
                    $cost['model'],
                    floatval($cost['input_cost']),
                    floatval($cost['output_cost'])
                );
    
                if ($result) {
                    $updated++;
                    WP_AI_Workflows_Utilities::debug_log("Cost setting updated successfully", "info", [
                        'provider' => $cost['provider'],
                        'model' => $cost['model']
                    ]);
                }
            }
    
            WP_AI_Workflows_Utilities::debug_log("Cost settings update complete", "info", [
                'updated_count' => $updated,
                'total_attempted' => count($costs)
            ]);
    
            if ($updated === 0) {
                return new WP_Error(
                    'update_failed',
                    'No cost settings were updated. Please check the provided values.',
                    ['status' => 400]
                );
            }
    
            return new WP_REST_Response([
                'message' => sprintf('%d cost settings updated successfully', $updated),
                'updated_count' => $updated
            ], 200);
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error updating cost settings", "error", [
                'error' => $e->getMessage()
            ]);
            return new WP_Error(
                'cost_settings_error',
                'Failed to update cost settings: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }


    public function create_assistant_session($request) {
        try {
            $workflow_id = $request->get_param('workflow_id');
            $workflow_context = $request->get_param('workflow_context');
            
            WP_AI_Workflows_Utilities::debug_log("Creating assistant session", "debug", [
                'workflow_id' => $workflow_id,
                'has_context' => !empty($workflow_context)
            ]);
    
            $chat = new WP_AI_Workflows_Assistant_Chat();
            $session_id = $chat->start_session($workflow_id);
    
            if ($workflow_context) {
                WP_AI_Workflows_Utilities::debug_log("Updating initial context", "debug", [
                    'session_id' => $session_id
                ]);
                $chat->update_workflow_context($workflow_context);
            }

            $request->set_param('chat_instance', $chat);
    
            return new WP_REST_Response([
                'session_id' => $session_id,
                'success' => true
            ], 200);
            
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error creating assistant session", "error", [
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new WP_Error('chat_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    public function send_assistant_message($request) {
        try {
            $session_id = $request->get_param('session_id');
            $content = $request->get_param('content');
            $mode = $request->get_param('mode');
            $workflow_id = $this->get_workflow_id_from_session($session_id);
            
            $chat = new WP_AI_Workflows_Assistant_Chat($workflow_id, $session_id);
            
            // If mode is provided in the request, make sure it's set before sending
            if ($mode) {
                $chat->update_mode($mode);
            }
            
            $response = $chat->send_message($content);
            
            return new WP_REST_Response([
                'success' => true,
                'content' => $response,
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (Exception $e) {
            return new WP_Error('chat_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    public function update_assistant_context($request) {
        try {
            $session_id = $request->get_param('session_id');
            $workflow_context = $request->get_param('workflow_context');
            $selected_node = $request->get_param('selected_node');
            
            $workflow_id = $this->get_workflow_id_from_session($session_id);
            $chat = new WP_AI_Workflows_Assistant_Chat($workflow_id, $session_id);
            
            $chat->update_workflow_context($workflow_context);
            
            if ($selected_node !== null) {
                $chat->update_selected_node($selected_node);
            }
            
            return new WP_REST_Response([
                'success' => true,
                'session_id' => $session_id
            ], 200);
            
        } catch (Exception $e) {
            return new WP_Error('context_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    private function get_workflow_id_from_session($session_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        
        $workflow_id = $wpdb->get_var($wpdb->prepare(
            "SELECT workflow_id FROM $table_name WHERE session_id = %s",
            $session_id
        ));
        
        if (!$workflow_id) {
            throw new Exception('Invalid session ID');
        }
        
        return $workflow_id;
    }

    public function update_mode($request) {
        try {
            $params = $request->get_params();
            $session_id = sanitize_text_field($params['session_id']);
            $mode = sanitize_text_field($params['mode']);
            
            // Get workflow ID first, just like other endpoints do
            $workflow_id = $this->get_workflow_id_from_session($session_id);
            $assistant = new WP_AI_Workflows_Assistant_Chat($workflow_id, $session_id);
            $result = $assistant->update_mode($mode);
            
            return new WP_REST_Response(array(
                'success' => true,
                'mode' => $mode
            ), 200);
        } catch (Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $e->getMessage()
            ), 500);
        }
    }

    public function apply_workflow_changes($request) {
        try {
            $session_id = $request->get_param('session_id');
            $changes = $request->get_param('changes');
            
            if (!$session_id || !$changes) {
                throw new Exception('Missing required parameters');
            }
            
            $workflow_id = $this->get_workflow_id_from_session($session_id);
            $chat = new WP_AI_Workflows_Assistant_Chat($workflow_id, $session_id);
            
            // Apply the changes to the workflow
            $updated_workflow = $chat->apply_workflow_changes($changes);
            
            WP_AI_Workflows_Utilities::debug_log('Applied workflow changes', 'debug', [
                'workflow_id' => $workflow_id,
                'changes' => $changes
            ]);
            
            return new WP_REST_Response([
                'success' => true,
                'workflow' => $updated_workflow
            ], 200);
            
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log('Error applying workflow changes', 'error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new WP_Error(
                'workflow_update_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    public function get_assistant_session($request) {
        try {
            $workflow_id = $request->get_param('workflow_id');
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
            
            // Get the most recent session for this workflow
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT session_id FROM $table_name 
                 WHERE workflow_id = %s 
                 ORDER BY updated_at DESC 
                 LIMIT 1",
                $workflow_id
            ));
            
            if ($session) {
                return new WP_REST_Response([
                    'success' => true,
                    'session_exists' => true,
                    'session_id' => $session->session_id
                ], 200);
            } else {
                return new WP_REST_Response([
                    'success' => true,
                    'session_exists' => false
                ], 200);
            }
            
        } catch (Exception $e) {
            return new WP_Error('session_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    public function get_assistant_history($request) {
        try {
            $session_id = $request->get_param('session_id');
            $workflow_id = $this->get_workflow_id_from_session($session_id);
            
            $chat = new WP_AI_Workflows_Assistant_Chat($workflow_id, $session_id);
            $messages = $chat->get_chat_history();
            
            // Get the current mode as well
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
            $mode = $wpdb->get_var($wpdb->prepare(
                "SELECT mode FROM $table_name WHERE session_id = %s",
                $session_id
            ));
            
            return new WP_REST_Response([
                'success' => true,
                'messages' => $messages,
                'mode' => $mode
            ], 200);
            
        } catch (Exception $e) {
            return new WP_Error('history_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
    * Handler for system requirements endpoint
    */
    public function get_system_requirements() {
        try {
            global $wpdb;
            $wp_version = get_bloginfo('version');
            $php_version = phpversion();
            $db_version = $wpdb->db_version();
            $is_mariadb = $wpdb->get_var("SELECT VERSION() LIKE '%MariaDB%'");
            $db_type = $is_mariadb ? 'MariaDB' : 'MySQL';
    
            // Get max execution time
            $max_execution_time = ini_get('max_execution_time');
            
            // Get memory limit in MB
            $memory_limit = ini_get('memory_limit');
            if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
                if ($matches[2] == 'G') {
                    $memory_limit = $matches[1] * 1024;
                } else if ($matches[2] == 'M') {
                    $memory_limit = $matches[1];
                } else if ($matches[2] == 'K') {
                    $memory_limit = $matches[1] / 1024;
                }
            }
    
            // Check cron status
            $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $cron_events = _get_cron_array();
            
            $overdue_count = 0;
            $max_overdue_minutes = 0;
            
            if (is_array($cron_events)) {
                $current_time = time();
                foreach ($cron_events as $timestamp => $hooks) {
                    if ($timestamp < $current_time) {
                        $overdue_count += count($hooks);
                        $minutes_overdue = ($current_time - $timestamp) / 60;
                        $max_overdue_minutes = max($max_overdue_minutes, $minutes_overdue);
                    }
                }
            }
            
            // Check WP REST API configuration
            $rest_enabled = get_option('permalink_structure') !== '';
            $rest_url = get_rest_url();
            
            // Enhanced REST API testing - perform actual diagnostic 
            $rest_api_test_results = $this->check_rest_availability($rest_url);
            $rest_accessible = $rest_api_test_results['test_endpoint_accessible'];
            $specific_issue = $rest_api_test_results['specific_issue'];
            $status_code = $rest_api_test_results['status_code'];
            
            // Get specific recommendations based on test results
            $rest_recommendations = $this->get_rest_api_recommendations($rest_api_test_results);
            
            // Format human-readable memory limit
            $memory_limit_formatted = $memory_limit . 'MB';
            if ($memory_limit >= 1024) {
                $memory_limit_formatted = round($memory_limit / 1024, 1) . 'GB';
            }
    
            // Build requirements list
            $items = [
                [
                    'title' => 'WordPress Version',
                    'value' => $wp_version,
                    'required' => '6.0+',
                    'status' => version_compare($wp_version, '6.0', '>=') ? 'success' : 'error',
                    'message' => version_compare($wp_version, '6.0', '>=') 
                        ? 'Your WordPress version is compatible with AI Workflow Automation.'
                        : 'AI Workflow Automation requires WordPress 6.0 or higher. Please update your WordPress installation.'
                ],
                [
                    'title' => 'PHP Version',
                    'value' => $php_version,
                    'required' => '8.0+',
                    'status' => version_compare($php_version, '8.0', '>=') ? 'success' : 'error',
                    'message' => version_compare($php_version, '8.0', '>=')
                        ? 'Your PHP version is compatible with AI Workflow Automation.'
                        : 'AI Workflow Automation requires PHP 8.0 or higher. Please contact your hosting provider to update PHP.'
                ],
                [
                    'title' => $db_type . ' Version',
                    'value' => $wpdb->db_version(),
                    'required' => $is_mariadb ? 'MariaDB 10.5+' : 'MySQL 8.0+',
                    'status' => $is_mariadb 
                        ? (version_compare($db_version, '10.5', '>=') ? 'success' : 'error')
                        : (version_compare($db_version, '8.0', '>=') ? 'success' : 'error'),
                    'message' => $is_mariadb
                        ? (version_compare($db_version, '10.5', '>=') 
                            ? 'Your MariaDB version is compatible with AI Workflow Automation.'
                            : 'AI Workflow Automation requires MariaDB 10.5 or higher. Older versions may cause issues with cost management, analytics features, and complex workflows. Please contact your hosting provider to update.')
                        : (version_compare($db_version, '8.0', '>=')
                            ? 'Your MySQL version is compatible with AI Workflow Automation.'
                            : 'AI Workflow Automation requires MySQL 8.0 or higher. Older versions may cause issues with cost management, analytics features, and complex workflows. Please contact your hosting provider to update.')
                ],
                [
                    'title' => 'Max Execution Time',
                    'value' => $max_execution_time . ' seconds',
                    'required' => '600 seconds (recommended)',
                    'status' => $max_execution_time >= 600 || $max_execution_time == 0 ? 'success' : 
                               ($max_execution_time >= 300 ? 'warning' : 'warning'),
                    'message' => $max_execution_time >= 600 || $max_execution_time == 0
                        ? 'Your max execution time is sufficient for complex workflows.'
                        : ($max_execution_time >= 300 
                            ? 'Your max execution time may be sufficient for most workflows, but complex operations might timeout. Consider increasing it to 600 seconds.'
                            : 'Your max execution time is too low for complex workflows. We recommend increasing to at least 600 seconds in your php.ini or contact your hosting provider.')
                ],
                [
                    'title' => 'Memory Limit',
                    'value' => $memory_limit_formatted,
                    'required' => '512MB',
                    'status' => $memory_limit >= 512 ? 'success' : 'error',
                    'message' => $memory_limit >= 512
                        ? 'Your memory limit is sufficient for AI Workflow Automation.'
                        : 'Memory limit is too low. AI Workflow Automation requires at least 512MB. Please increase your memory_limit in php.ini or contact your hosting provider.'
                ]
            ];
    
            // Update the REST API item with more specific information
            $rest_api_status = $rest_enabled ? ($rest_accessible ? 'success' : 'warning') : 'error';
            $rest_api_value = $rest_enabled ? 'Enabled' : 'Disabled';
    
            if ($rest_enabled && !$rest_accessible) {
                if ($specific_issue === 'authentication_required') {
                    $rest_api_value = 'Enabled (Restricted Access)';
                } elseif ($specific_issue === 'server_error') {
                    $rest_api_value = 'Enabled (Server Error)';
                } elseif ($specific_issue === 'connection_error') {
                    $rest_api_value = 'Enabled (Connection Issue)';
                } else {
                    $rest_api_value = 'Enabled (Issue Detected)';
                }
            }
    
            $rest_api_message = $rest_enabled 
                ? ($rest_accessible 
                    ? 'WordPress REST API is properly configured.' 
                    : "WordPress REST API is enabled but not fully accessible. " . $this->get_specific_rest_issue_message($specific_issue, $status_code))
                : 'WordPress REST API is disabled. AI Workflow Automation requires the REST API. Please enable pretty permalinks in your WordPress settings.';
    
                foreach ($items as &$item) {
                    if (!isset($item['details'])) {
                        $item['details'] = [
                            'specific_issue' => null,
                            'status_code' => null,
                            'recommendations' => []
                        ];
                    }
                }

            $items[] = [
                'title' => 'REST API Configuration',
                'value' => $rest_api_value,
                'required' => 'Enabled',
                'status' => $rest_api_status,
                'message' => $rest_api_message,
                'details' => [
                    'specific_issue' => $specific_issue,
                    'status_code' => $status_code,
                    'recommendations' => $rest_recommendations
                ]
            ];
    
            // Build response
            $response = [
                'items' => $items,
                'cron' => [
                    'enabled' => !$cron_disabled,
                    'overdue' => $overdue_count,
                    'maxOverdueTime' => round($max_overdue_minutes)
                ]
            ];
    
            // Add server environment info
            $response['environment'] = [
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'os' => PHP_OS,
                'ssl_enabled' => is_ssl()
            ];
    
            return new WP_REST_Response($response, 200);
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error retrieving system requirements", "error", [
                'error' => $e->getMessage()
            ]);
            
            return new WP_Error(
                'system_requirements_error',
                'Failed to retrieve system requirements: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Improved REST API testing function for system requirements check
     * This performs an actual test request to the REST API and provides specific diagnostics
     */
    private function check_rest_availability($rest_url) {
        // Start with basic checks
        $permalink_structure = get_option('permalink_structure');
        $rest_enabled = !empty($permalink_structure);
        
        // Store detailed diagnostics
        $test_results = [
            'enabled' => $rest_enabled,
            'permalink_structure' => $permalink_structure,
            'specific_issue' => null,
            'status_code' => null,
            'test_endpoint_accessible' => false,
            'plugin_conflicts' => [],
        ];
        
        // First exit early if permalinks aren't set
        if (!$rest_enabled) {
            $test_results['specific_issue'] = 'permalinks_disabled';
            return $test_results;
        }
        
        // Try to access a simple REST endpoint
        $test_url = trailingslashit($rest_url) . 'wp/v2/types';
        
        // Use WP HTTP API with minimal parameters to check if REST is accessible
        $response = wp_remote_get(
            $test_url,
            [
                'timeout' => 10,
                'redirection' => 0,
                'httpversion' => '1.1',
                'sslverify' => false, // For testing only
            ]
        );
        
        if (is_wp_error($response)) {
            $test_results['specific_issue'] = 'connection_error';
            $test_results['error_message'] = $response->get_error_message();
            return $test_results;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $test_results['status_code'] = $response_code;
        
        // Check for common status codes and set specific issues
        if ($response_code >= 200 && $response_code < 300) {
            $test_results['test_endpoint_accessible'] = true;
        } elseif ($response_code === 401 || $response_code === 403) {
            $test_results['specific_issue'] = 'authentication_required';
            
            // Check for known security plugins that might be blocking REST API
            $active_plugins = get_option('active_plugins');
            $security_plugins = [
                'wordfence/wordfence.php' => 'Wordfence',
                'better-wp-security/better-wp-security.php' => 'iThemes Security',
                'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
                'wp-simple-firewall/icwp-wpsf.php' => 'Shield Security',
                'sucuri-scanner/sucuri.php' => 'Sucuri Security',
            ];
            
            foreach ($security_plugins as $plugin_file => $plugin_name) {
                if (in_array($plugin_file, $active_plugins)) {
                    $test_results['plugin_conflicts'][] = $plugin_name;
                }
            }
        } elseif ($response_code === 404) {
            $test_results['specific_issue'] = 'endpoint_not_found';
        } elseif ($response_code >= 500) {
            $test_results['specific_issue'] = 'server_error';
        }
        
        // Additional .htaccess check
        $htaccess_path = ABSPATH . '.htaccess';
        $test_results['htaccess_exists'] = file_exists($htaccess_path);
        $test_results['htaccess_writable'] = is_writable($htaccess_path);
        
        // Check if this site is behind a proxy/CDN
        $test_results['behind_proxy'] = $this->detect_site_behind_proxy();
        
        return $test_results;
    }
    
    /**
     * Detect if the site is behind a proxy/CDN/WAF
     */
    private function detect_site_behind_proxy() {
        $proxy_headers = array_filter($_SERVER, function($key) {
            return strpos($key, 'HTTP_X_') === 0 || 
                   strpos($key, 'HTTP_CF_') === 0 || 
                   strpos($key, 'HTTP_CLOUDFRONT_') === 0;
        }, ARRAY_FILTER_USE_KEY);
        
        return !empty($proxy_headers);
    }
    
    /**
     * Get specific recommendations based on REST API test results
     */
    private function get_rest_api_recommendations($test_results) {
        $recommendations = [];
        
        if (!$test_results['enabled']) {
            $recommendations[] = [
                'title' => 'Enable WordPress Permalinks',
                'description' => 'Go to Settings → Permalinks and select any option other than "Plain". Post name is recommended.',
                'priority' => 'high'
            ];
            return $recommendations;
        }
        
        if ($test_results['specific_issue'] === 'authentication_required') {
            if (!empty($test_results['plugin_conflicts'])) {
                foreach ($test_results['plugin_conflicts'] as $plugin) {
                    $recommendations[] = [
                        'title' => "Check {$plugin} Settings",
                        'description' => "Your {$plugin} plugin may be blocking REST API access. Please check its settings to allow REST API endpoints.",
                        'priority' => 'high'
                    ];
                }
            } else {
                $recommendations[] = [
                    'title' => 'Check Security Plugins',
                    'description' => 'A security plugin or firewall appears to be blocking REST API access. Check settings in any security plugins.',
                    'priority' => 'high'
                ];
            }
        }
        
        if ($test_results['specific_issue'] === 'server_error') {
            $recommendations[] = [
                'title' => 'Server Configuration Issue',
                'description' => 'Your server returned an error when accessing the REST API. Check server error logs and contact your hosting provider.',
                'priority' => 'high'
            ];
        }
        
        if ($test_results['specific_issue'] === 'connection_error') {
            $recommendations[] = [
                'title' => 'Connection Error',
                'description' => 'Unable to connect to the REST API. This could be due to server configuration or a firewall blocking requests.',
                'priority' => 'high'
            ];
        }
        
        // If htaccess issues are detected
        if ($test_results['htaccess_exists'] && !$test_results['htaccess_writable']) {
            $recommendations[] = [
                'title' => 'Check .htaccess Permissions',
                'description' => 'Your .htaccess file exists but is not writable by WordPress, which may affect REST API functionality.',
                'priority' => 'medium'
            ];
        }
        
        // Special cases for sites behind proxies/CDNs
        if ($test_results['behind_proxy']) {
            $recommendations[] = [
                'title' => 'Proxy/CDN Configuration',
                'description' => 'Your site appears to be behind a proxy, CDN, or firewall. Ensure it\'s configured to allow REST API requests.',
                'priority' => 'medium'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Get a user-friendly message for specific REST API issues
     */
    private function get_specific_rest_issue_message($issue, $status_code = null) {
        switch ($issue) {
            case 'permalinks_disabled':
                return 'WordPress permalinks are set to "Plain" which disables the REST API.';
            
            case 'authentication_required':
                return 'Access to the REST API is restricted by a security plugin or firewall (Status: ' . $status_code . ').';
            
            case 'server_error':
                return 'The server encountered an error when accessing the REST API (Status: ' . $status_code . ').';
            
            case 'connection_error':
                return 'Unable to connect to the WordPress REST API. This could be due to server configuration issues.';
            
            case 'endpoint_not_found':
                return 'The REST API endpoint was not found (Status: ' . $status_code . ').';
            
            default:
                if ($status_code) {
                    return 'An issue was detected with the REST API (Status: ' . $status_code . ').';
                }
                return 'An unknown issue is preventing full access to the REST API.';
        }
    }


    /**
     * Handle logo upload
     */
    public function handle_logo_upload($request) {
        if (!class_exists('WP_AI_Workflows_Whitelabel')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Whitelabel functionality is not available in this build.'
            ), 501);
        }

        $whitelabel = new WP_AI_Workflows_Whitelabel();
        $result = $whitelabel->handle_logo_upload($request);
        
        if (isset($result['success']) && $result['success']) {
            return new WP_REST_Response($result);
        } else {
            return new WP_REST_Response($result, 400);
        }
    }

    /**
     * Import whitelabel settings
     */
    public function import_whitelabel_settings($request) {
        if (!class_exists('WP_AI_Workflows_Whitelabel')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Whitelabel functionality is not available in this build.'
            ), 501);
        }

        $whitelabel = new WP_AI_Workflows_Whitelabel();
        $result = $whitelabel->import_whitelabel_settings($request);
        
        if (isset($result['success']) && $result['success']) {
            return new WP_REST_Response($result);
        } else {
            return new WP_REST_Response($result, 400);
        }
    }


    /**
     * Get models from OpenRouter API
     * 
     * @return WP_REST_Response
     */
    public function get_openrouter_models() {
        // Get models from cache if available
        $cache_key = 'wp_ai_workflows_openrouter_models';
        $cached_models = get_transient($cache_key);
        
        if (false !== $cached_models) {
            return rest_ensure_response($cached_models);
        }
        
        // Fetch models from OpenRouter API
        $response = wp_remote_get('https://openrouter.ai/api/v1/models', array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ));
        
        if (is_wp_error($response)) {
            WP_AI_Workflows_Utilities::debug_log("Error fetching OpenRouter models", "error", [
                'error' => $response->get_error_message()
            ]);
            return new WP_Error('openrouter_api_error', 'Failed to fetch models from OpenRouter API: ' . $response->get_error_message(), array('status' => 500));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            WP_AI_Workflows_Utilities::debug_log("OpenRouter API returned non-200 status", "error", [
                'status' => $status_code,
                'body' => wp_remote_retrieve_body($response)
            ]);
            return new WP_Error('openrouter_api_error', 'OpenRouter API returned status: ' . $status_code, array('status' => $status_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $models_data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            WP_AI_Workflows_Utilities::debug_log("Failed to parse OpenRouter response", "error", [
                'error' => json_last_error_msg(),
                'body' => $body
            ]);
            return new WP_Error('openrouter_api_error', 'Failed to parse OpenRouter API response: ' . json_last_error_msg(), array('status' => 500));
        }
        
        // Cache the models for 1 hour
        set_transient($cache_key, $models_data, HOUR_IN_SECONDS);
        
        // Log successful fetch
        WP_AI_Workflows_Utilities::debug_log("Successfully fetched OpenRouter models", "info", [
            'model_count' => count($models_data['data'] ?? [])
        ]);
        
        return rest_ensure_response($models_data);
    }

    /**
     * Function to manually clear the OpenRouter models cache
     */
    public function clear_openrouter_models_cache() {
        delete_transient('wp_ai_workflows_openrouter_models');
        WP_AI_Workflows_Utilities::debug_log("OpenRouter models cache cleared", "info");
    }

    public function sync_costs($request) {
        try {
            $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
            $result = $cost_manager->sync_openrouter_costs();
            
            WP_AI_Workflows_Utilities::debug_log("Cost sync request processed", "info", [
                'success' => $result['success'],
                'models_added' => $result['models_added'],
                'models_updated' => $result['models_updated'],
                'errors' => $result['errors']
            ]);
            
            if ($result['success']) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => sprintf(
                        'Models synced successfully. Added %d new models, updated %d existing models.',
                        $result['models_added'],
                        $result['models_updated']
                    ),
                    'data' => $result
                ], 200);
            } else {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to sync models: ' . implode(' ', $result['errors']),
                    'data' => $result
                ], 400);
            }
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error syncing costs", "error", [
                'error' => $e->getMessage()
            ]);
            
            return new WP_Error(
                'sync_costs_error',
                'Failed to sync costs: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    public function get_sync_info($request) {
        try {
            $last_sync_time = get_option('wp_ai_workflows_last_cost_sync', 0);
            
            $formatted_time = '';
            if ($last_sync_time > 0) {
                $formatted_time = human_time_diff($last_sync_time, time()) . ' ago';
            } else {
                $formatted_time = 'Never';
            }
            
            return new WP_REST_Response([
                'last_sync_time' => $formatted_time,
                'last_sync_timestamp' => $last_sync_time
            ], 200);
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("Error getting sync info", "error", [
                'error' => $e->getMessage()
            ]);
            
            return new WP_Error(
                'sync_info_error',
                'Failed to get sync information: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get available multimedia generation models
     * 
     * @param WP_REST_Request $request REST Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function get_multimedia_models($request) {
        $multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
        $models = $multimedia_generator->get_model_list_for_frontend();
        
        return new WP_REST_Response($models, 200);
    }

    /**
     * Handle image generation request
     * 
     * @param WP_REST_Request $request REST Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function handle_generate_image($request) {
        $params = $request->get_params();
        
        // Validate required parameters
        $required_fields = ['model', 'prompt'];
        foreach ($required_fields as $field) {
            if (empty($params[$field])) {
                return new WP_Error(
                    'missing_required_field',
                    "Missing required field: $field",
                    array('status' => 400)
                );
            }
        }
        
        $multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
        $result = $multimedia_generator->generate_image($params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Track cost if enabled
        if (class_exists('WP_AI_Workflows_Cost_Management')) {
            $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
            $model = sanitize_text_field($params['model']);
            $num_images = isset($params['num_images']) ? intval($params['num_images']) : 1;

            $execution_id = isset($params['execution_id']) ? intval($params['execution_id']) : 0;
            $node_id = isset($params['node_id']) ? sanitize_text_field($params['node_id']) : 'multimedia';
            
            $cost = $multimedia_generator->estimate_cost($model, 'image', $num_images);
            if ($cost !== null) {
                $cost_manager->track_multimedia_cost_simple(
                    'fal_ai',
                    $model,
                    $cost,
                    $execution_id, // Pass execution ID
                    $node_id,  
                    $num_images,
                    'image'
                );
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'result' => $result
        ), 200);
    }

    /**
     * Handle video generation request
     * 
     * @param WP_REST_Request $request REST Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function handle_generate_video($request) {
        $params = $request->get_params();
        
        // Validate required parameters
        if (empty($params['model'])) {
            return new WP_Error('missing_model', 'Model is required', array('status' => 400));
        }
        
        $model = sanitize_text_field($params['model']);
        $multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
        
        // Check if this is an image-to-video model (needs image_url)
        $all_models = $multimedia_generator->get_available_models();
        $model_type = '';
        
        if (isset($all_models[$model])) {
            // Determine model type
            if (isset($multimedia_generator->get_available_models('image_to_video')[$model])) {
                $model_type = 'image_to_video';
                if (empty($params['image_url'])) {
                    return new WP_Error('missing_image_url', 'Image URL is required for this model', array('status' => 400));
                }
            } else if (isset($multimedia_generator->get_available_models('text_to_video')[$model])) {
                $model_type = 'text_to_video';
                if (empty($params['prompt'])) {
                    return new WP_Error('missing_prompt', 'Prompt is required for this model', array('status' => 400));
                }
            }
        } else {
            return new WP_Error('unknown_model', 'Unknown model', array('status' => 400));
        }
        
        $result = $multimedia_generator->generate_video($params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Track cost if enabled
        if (class_exists('WP_AI_Workflows_Cost_Management')) {
            $cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
            $video_length = isset($params['video_length']) ? intval($params['video_length']) : 5;

            $execution_id = isset($params['execution_id']) ? intval($params['execution_id']) : 0;
            $node_id = isset($params['node_id']) ? sanitize_text_field($params['node_id']) : 'multimedia';
            
            $cost = $multimedia_generator->estimate_cost($model, $model_type, $video_length);
            if ($cost !== null) {
                $cost_manager->track_multimedia_cost_simple(
                    'fal_ai',
                    $model,
                    $cost,
                    $execution_id, // Pass execution ID
                    $node_id,  
                    1,
                    'video'
                );
            }
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'result' => $result
        ), 200);
    }

    /**
     * Handle file upload for the multimedia generator
     * 
     * @param WP_REST_Request $request REST Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function handle_file_upload($request) {
        $file = $request->get_file_params()['file'] ?? null;
        if (!$file) {
            return new WP_Error('missing_file', 'No file was uploaded', array('status' => 400));
        }
        
        $multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
        $result = $multimedia_generator->upload_file($file);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return new WP_REST_Response(array(
            'url' => $result['url']
        ), 200);
    }

    /**
     * Estimate the cost of a multimedia generation request
     * 
     * @param WP_REST_Request $request REST Request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public function estimate_multimedia_cost($request) {
        $params = $request->get_params();
        
        if (empty($params['model'])) {
            return new WP_Error('missing_model', 'Model is required', array('status' => 400));
        }
        
        $model = sanitize_text_field($params['model']);
        $type = isset($params['type']) ? sanitize_text_field($params['type']) : 'image';
        $quantity = isset($params['quantity']) ? intval($params['quantity']) : 1;
        
        $multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
        $cost = $multimedia_generator->estimate_cost($model, $type, $quantity);
        
        if ($cost === null) {
            return new WP_Error('unknown_model', 'Unknown model or type', array('status' => 400));
        }
        
        return new WP_REST_Response(array(
            'cost' => $cost,
            'formatted_cost' => '$' . number_format($cost, 2)
        ), 200);
    }

    public function get_mcp_tools($request) {
        $server_type = $request->get_param('server');
        $config = $request->get_param('config');
        
        if ($config) {
            $config = json_decode($config, true);
        }
        
        // This will be implemented when we create the MCP Client class
        return WP_AI_Workflows_MCP_Client::discover_tools($server_type, $config);
    }
    
    public function test_mcp_connection($request) {
        $params = $request->get_params();
        
        // This will be implemented when we create the MCP Client class  
        return WP_AI_Workflows_MCP_Client::test_connection($params);
    }
    
    public function save_custom_mcp_server($request) {
        $params = $request->get_params();
        $user_id = get_current_user_id();
        
        try {
            $server_id = WP_AI_Workflows_Database::save_mcp_server(
                $user_id,
                $params['name'],
                $params['description'], 
                $params['config'],
                $params['discovered_tools'] ?? null
            );
            
            if ($server_id) {
                return new WP_REST_Response([
                    'success' => true,
                    'server_id' => $server_id,
                    'message' => 'MCP server saved successfully'
                ], 200);
            } else {
                return new WP_Error('save_failed', 'Failed to save MCP server', ['status' => 500]);
            }
        } catch (Exception $e) {
            return new WP_Error('save_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    public function get_mcp_servers($request) {
        $user_id = get_current_user_id();
        $servers = WP_AI_Workflows_Database::get_mcp_servers($user_id);
        
        return new WP_REST_Response($servers, 200);
    }
    
    public function delete_mcp_server($request) {
        $server_id = $request->get_param('id');
        $user_id = get_current_user_id();
        
        $result = WP_AI_Workflows_Database::delete_mcp_server($server_id, $user_id);
        
        if ($result) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'MCP server deleted successfully'
            ], 200);
        } else {
            return new WP_Error('delete_failed', 'Failed to delete MCP server', ['status' => 500]);
        }
    }

        /**
     * Check OpenRouter account balance
     */
    public function check_openrouter_balance($request) {
        try {
            $api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
            if (empty($api_key)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'OpenRouter API key not configured'
                ), 200);
            }

            $response = wp_remote_get('https://openrouter.ai/api/v1/credits', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 15
            ));

            if (is_wp_error($response)) {
                throw new Exception('Failed to fetch balance: ' . $response->get_error_message());
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($data['data'])) {
                $total_credits = isset($data['data']['total_credits']) ? $data['data']['total_credits'] : 0;
                $total_usage = isset($data['data']['total_usage']) ? $data['data']['total_usage'] : 0;
                $remaining = $total_credits - $total_usage;

                return new WP_REST_Response(array(
                    'success' => true,
                    'balance' => $remaining,
                    'total_credits' => $total_credits,
                    'total_usage' => $total_usage,
                    'currency' => 'USD'
                ));
            }

            throw new Exception('Invalid response format');

        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log("OpenRouter balance check failed", "error", [
                'error' => $e->getMessage()
            ]);
            
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to check OpenRouter balance'
            ), 200);
        }
    }

}