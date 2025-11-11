<?php

class WP_AI_Workflows_Chat_Session {
    private $session_id;
    private $workflow_id;
    private $max_history_length;
    private $rate_limit_config;
    
    public function __construct($workflow_id, $session_id = null) {
        $this->workflow_id = $workflow_id;
    
        // First try to get session from the provided session_id
        if ($session_id) {
            // Verify this session exists and belongs to this workflow
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
            $existing_session = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE session_id = %s AND workflow_id = %s",
                $session_id,
                $workflow_id
            ));
            
            if ($existing_session) {
                $this->session_id = $session_id;
                $this->load_session_config();
                return;
            }
        }
        
        // If no valid session found, create new one
        $this->session_id = wp_generate_uuid4();
        $this->initialize_session();
    }

    private function initialize_session() {
        $expiry = time() + (12 * 3600); // 12 hours
        
        // Store session with expiry
        set_transient('wp_ai_chat_session_' . $this->session_id, [
            'expires' => $expiry,
            'workflow_id' => $this->workflow_id
        ], 12 * 3600);

        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
        
        // Create new session
        $wpdb->insert(
            $table_name,
            array(
                'session_id' => $this->session_id,
                'workflow_id' => $this->workflow_id,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );
        
        // Load session configuration from workflow
        $this->load_session_config();
        
        // Log for debugging
        WP_AI_Workflows_Utilities::debug_log("New chat session initialized", "debug", [
            'session_id' => $this->session_id,
            'workflow_id' => $this->workflow_id
        ]);
    }
    
    private function load_session_config() {
        $workflow = $this->get_workflow();
        if ($workflow) {
            $chat_node = $this->find_chat_node($workflow['nodes']);
            if ($chat_node) {
                $this->max_history_length = $chat_node['data']['behavior']['maxHistoryLength'] ?? 50;
                $this->rate_limit_config = $chat_node['data']['behavior']['rateLimit'] ?? null;
            }
        }
    }

    public function update_last_activity() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
        
        $wpdb->update(
            $table_name,
            array('updated_at' => current_time('mysql')),
            array('session_id' => $this->session_id)
        );
    }
    
    public function can_send_message() {
        if (!$this->rate_limit_config || !$this->rate_limit_config['enabled']) {
            return true;
        }
        
        // Get both IP-based and session-based counts
        $recent_messages = $this->get_recent_messages_count();
        
        // Get client IP and check IP-based limit
        $ip = $this->get_client_ip();
        $ip_key = 'wp_ai_chat_ip_' . md5($ip);
        $ip_count = get_transient($ip_key) ?: 0;
        
        // Update IP count
        set_transient($ip_key, $ip_count + 1, 3600); // 1 hour expiry
        
        // Check both limits
        // 1. Session-based limit from config
        $session_limit_exceeded = $recent_messages >= $this->rate_limit_config['maxMessages'];
        // 2. Global IP-based limit
        $ip_limit_exceeded = $ip_count > 100; // 100 messages per hour per IP
        
        return !($session_limit_exceeded || $ip_limit_exceeded);
    }
    
    private function get_client_ip() {
        $ip_headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
    
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // If it's a list of IPs, take the first one
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    
        return '0.0.0.0'; // Fallback
    }
    
    private function get_recent_messages_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        $time_window = $this->rate_limit_config['timeWindow'];
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
            WHERE session_id = %s 
            AND role = 'user'
            AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $this->session_id,
            $time_window
        ));
    }
    
    public function add_message($role, $content) {
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
        
        // Update session last activity
        $sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
        $wpdb->update(
            $sessions_table,
            array('updated_at' => current_time('mysql')),
            array('session_id' => $this->session_id)
        );
        
        // Trim history if needed
        $this->trim_history();
    }
    
    public function get_history() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE session_id = %s 
            ORDER BY created_at ASC 
            LIMIT %d",
            $this->session_id,
            $this->max_history_length
        ));
    }
    
    private function trim_history() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE session_id = %s",
            $this->session_id
        ));
        
        if ($count > $this->max_history_length) {
            $to_delete = $count - $this->max_history_length;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name 
                WHERE session_id = %s 
                ORDER BY created_at ASC 
                LIMIT %d",
                $this->session_id,
                $to_delete
            ));
        }
    }
    
    public function get_session_id() {
        return $this->session_id;
    }

    public function get_workflow_id() {
        return $this->workflow_id;
    }
    
    private function get_workflow() {
        // Use DBAL to get workflow using the instance property
        return WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id($this->workflow_id);
    }
    
    private function find_chat_node($nodes) {
        foreach ($nodes as $node) {
            if ($node['type'] === 'chat') {
                return $node;
            }
        }
        return null;
    }

    public function is_new_session() {
        if (!isset($this->is_new)) {
            $history = $this->get_history();
            $this->is_new = empty($history);
        }
        return $this->is_new;
    }
}
