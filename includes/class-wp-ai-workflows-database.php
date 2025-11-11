<?php
/**
 * Database operations for WP AI Workflows plugin.
 */
class WP_AI_Workflows_Database {

    public function init() {
        // Any initialization code if needed
    }

    public static function create_tables() {
        $current_version = get_option('wp_ai_workflows_db_version', '0');
        if (version_compare($current_version, WP_AI_WORKFLOWS_PRO_VERSION, '>=')) {
            return; // Database is up to date
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $shortcode_outputs_table = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';
        $outputs_table = $wpdb->prefix . 'wp_ai_workflows_outputs';
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
        $templates_table = $wpdb->prefix . 'wp_ai_workflows_templates';
        $human_tasks_table = $wpdb->prefix . 'wp_ai_workflows_human_tasks';
        $google_sheet_states = $wpdb->prefix . 'wp_ai_workflows_sheet_states';
        $sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
        $messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
        $cost_settings_table = $wpdb->prefix . 'wp_ai_workflows_cost_settings';
        $node_costs_table = $wpdb->prefix . 'wp_ai_workflows_node_costs';
        $assistant_sessions_table = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
        $assistant_messages_table = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';
        $vector_stores_table = $wpdb->prefix . 'wp_ai_workflows_vector_stores';
        $vector_files_table = $wpdb->prefix . 'wp_ai_workflows_vector_files';
        $license_security_table = $wpdb->prefix . 'wp_ai_workflows_license_security';
        $workflows_table = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        $mcp_servers_table = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';

        $sql_workflows = "CREATE TABLE IF NOT EXISTS $workflows_table (
            id VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            data LONGTEXT NOT NULL,
            created_by VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name (name),
            KEY status (status),
            KEY created_at (created_at),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        $sql_license_security = "CREATE TABLE IF NOT EXISTS $license_security_table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            check_time DATETIME NOT NULL,
            check_type VARCHAR(50) NOT NULL,
            ip_address VARCHAR(100) NOT NULL,
            result VARCHAR(20) NOT NULL,
            license_key_fragment VARCHAR(32),
            site_hash VARCHAR(64) NOT NULL,
            http_filter_status TINYINT(1) DEFAULT 0,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY check_time (check_time),
            KEY result (result),
            KEY site_hash (site_hash)
        ) $charset_collate;";

        $sql_shortcode_outputs = "CREATE TABLE IF NOT EXISTS $shortcode_outputs_table (
            id INT NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(255) NOT NULL,
            workflow_id VARCHAR(255) NOT NULL,
            output_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_workflow (session_id, workflow_id)
        ) $charset_collate;";

        $sql_outputs = "CREATE TABLE $outputs_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            node_id varchar(255) NOT NULL,
            output_data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_executions = "CREATE TABLE $executions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            workflow_id varchar(255) NOT NULL,
            workflow_name varchar(255) NOT NULL,
            status varchar(20) NOT NULL,
            input_data longtext,
            output_data longtext,
            current_node varchar(255),
            error_message text,
            total_cost DECIMAL(10,6) DEFAULT 0.00,
            cost_details JSON DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            scheduled_at datetime,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_templates = "CREATE TABLE $templates_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            workflow_data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_human_tasks = "CREATE TABLE $human_tasks_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            workflow_id varchar(255) NOT NULL,
            workflow_name varchar(255) NOT NULL,
            execution_id bigint(20) NOT NULL,
            node_id varchar(255) NOT NULL,
            assigned_user_id bigint(20),
            assigned_role varchar(255),
            input_type enum('approval', 'modification') NOT NULL,
            instructions longtext,
            content longtext NOT NULL,
            status enum('pending', 'approved', 'rejected', 'reverted', 'modified') NOT NULL DEFAULT 'pending',
            comments text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            action_taken_by bigint(20),
            action_taken_at datetime,
            PRIMARY KEY  (id),
            KEY workflow_id (workflow_id),
            KEY execution_id (execution_id),
            KEY assigned_user_id (assigned_user_id),
            KEY assigned_role (assigned_role),
            KEY status (status)
        ) $charset_collate;";

        $sql_google_sheet_states = "CREATE TABLE $google_sheet_states (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sheet_id varchar(255) NOT NULL,
            tab_id varchar(255) NOT NULL,
            sheet_state longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sheet_tab (sheet_id,tab_id)
        ) $charset_collate;";

        $sql_sessions = "CREATE TABLE IF NOT EXISTS $sessions_table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(255) NOT NULL,
            workflow_id VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            metadata JSON,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY workflow_id (workflow_id),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        // Create messages table
        $sql_messages = "CREATE TABLE IF NOT EXISTS $messages_table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(255) NOT NULL,
            role ENUM('system', 'user', 'assistant') NOT NULL,
            content LONGTEXT NOT NULL,
            tokens INT UNSIGNED,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            metadata JSON,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_node_costs = "CREATE TABLE IF NOT EXISTS $node_costs_table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            execution_id BIGINT(20) NOT NULL,
            node_id VARCHAR(255) NOT NULL,
            model VARCHAR(255) NOT NULL,
            provider VARCHAR(50) NOT NULL,
            prompt_tokens INT UNSIGNED DEFAULT 0,
            completion_tokens INT UNSIGNED DEFAULT 0,
            cost DECIMAL(10,6) DEFAULT 0.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY execution_node (execution_id, node_id),
            KEY model_idx (model),
            KEY provider_idx (provider)
        ) $charset_collate;";

        $sql_cost_settings = "CREATE TABLE IF NOT EXISTS $cost_settings_table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            provider VARCHAR(50) NOT NULL,
            model VARCHAR(255) NOT NULL,
            input_cost DECIMAL(10,6) NOT NULL,
            output_cost DECIMAL(10,6) NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY provider_model (provider, model)
        ) $charset_collate;";

        $sql_assistant_sessions = "CREATE TABLE IF NOT EXISTS $assistant_sessions_table (
            session_id varchar(36) NOT NULL,
            workflow_id varchar(255) NOT NULL,
            workflow_context longtext,
            selected_node varchar(255) DEFAULT NULL,
            mode varchar(20) NOT NULL DEFAULT 'chat',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id),
            KEY workflow_id (workflow_id),
            KEY mode (mode)
        ) $charset_collate;";

        $sql_assistant_messages = "CREATE TABLE IF NOT EXISTS $assistant_messages_table (
            message_id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(36) NOT NULL,
            role varchar(20) NOT NULL,
            content longtext NOT NULL,
            node_context text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id),
            KEY session_id (session_id),
            KEY message_ordering (session_id, created_at)
        ) $charset_collate;";

        $sql_vector_stores = "CREATE TABLE IF NOT EXISTS $vector_stores_table (
            id VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            is_default TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $sql_vector_files = "CREATE TABLE IF NOT EXISTS $vector_files_table (
            id VARCHAR(255) NOT NULL,
            store_id VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100),
            size BIGINT,
            status VARCHAR(20) DEFAULT 'pending',
            url TEXT,
            local_path TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY store_id (store_id)
        ) $charset_collate;";

        $sql_mcp_servers = "CREATE TABLE IF NOT EXISTS $mcp_servers_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            config longtext NOT NULL,
            discovered_tools longtext,
            is_active boolean DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY name (name),
            KEY is_active (is_active),
            KEY created_at (created_at)
        ) $charset_collate;";


        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_shortcode_outputs);
        dbDelta($sql_outputs);
        dbDelta($sql_workflows);
        dbDelta($sql_executions);
        dbDelta($sql_templates);
        dbDelta($sql_human_tasks);
        dbDelta($sql_google_sheet_states);
        dbDelta($sql_sessions);
        dbDelta($sql_messages);
        dbDelta($sql_node_costs);
        dbDelta($sql_cost_settings);
        dbDelta($sql_assistant_sessions);
        dbDelta($sql_assistant_messages);
        dbDelta($sql_vector_stores);
        dbDelta($sql_vector_files);
        dbDelta($sql_license_security);
        dbDelta($sql_mcp_servers);

        update_option('wp_ai_workflows_chat_db_version', WP_AI_WORKFLOWS_PRO_VERSION);

        WP_AI_Workflows_Utilities::debug_log("Database tables created or updated", "info");
    }

    public static function cleanup_orphaned_executions() {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        
        // Get existing workflow IDs from the workflow table first
        $workflow_table = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        $existing_workflow_ids = [];
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$workflow_table'") == $workflow_table) {
            $existing_workflow_ids = $wpdb->get_col("SELECT id FROM $workflow_table");
        }
        
        // If no workflows in table, fall back to options (for transition period)
        if (empty($existing_workflow_ids)) {
            $workflows = get_option('wp_ai_workflows', array());
            $existing_workflow_ids = array_column($workflows, 'id');
        }
    
        if (empty($existing_workflow_ids)) {
            return;
        }
    
        $placeholders = implode(',', array_fill(0, count($existing_workflow_ids), '%s'));
        $query = $wpdb->prepare(
            "DELETE FROM $table_name WHERE workflow_id NOT IN ($placeholders)",
            $existing_workflow_ids
        );
        $orphaned = $wpdb->query($query);
    
        WP_AI_Workflows_Utilities::debug_log("Cleaned up orphaned executions", "info", [
            'orphaned_executions_removed' => $orphaned
        ]);
    }

    public static function get_tables() {
        WP_AI_Workflows_Utilities::debug_log("Fetching tables", "debug");
        global $wpdb;
        
        $cache_key = 'wp_ai_workflows_tables';
        $table_names = wp_cache_get($cache_key);

        if (false === $table_names) {
            $tables = $wpdb->get_results($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($wpdb->prefix . 'ai_workflows_') . '%'
            ), ARRAY_N);
            
            $table_names = array_map(function($table) use ($wpdb) {
                return str_replace($wpdb->prefix, '', $table[0]);
            }, $tables);

            wp_cache_set($cache_key, $table_names, '', 3600); // 
        }

        WP_AI_Workflows_Utilities::debug_log("Tables fetched", "debug", ['tables' => $table_names]);
        return new WP_REST_Response($table_names, 200);
    }

    public static function export_outputs($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        global $wpdb;
        $table = $wpdb->prefix . $request->get_param('table');
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) {
            return new WP_Error('invalid_table', 'The specified table does not exist', array('status' => 400));
        }

        $outputs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created_at DESC"), ARRAY_A);

        $csv_content = self::generate_csv($outputs);

        $filename = $table . '_outputs.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv_content));

        echo esc_html($csv_content);
        exit;
    }

    private static function generate_csv($data) {
        if (empty($data)) {
            return '';
        }

        ob_start();
        $df = fopen("php://output", 'w');
        fputcsv($df, array_keys(reset($data)));
        foreach ($data as $row) {
            fputcsv($df, $row);
        }
        fclose($df);
        return ob_get_clean();
    }

    public static function create_table($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        global $wpdb;
        $table_name = $request->get_param('tableName');
        $columns = $request->get_param('columns');
        
        if (empty($table_name)) {
            return new WP_Error('invalid_table_name', 'Table name cannot be empty', array('status' => 400));
        }

        $table_name = 'ai_workflows_' . sanitize_key($table_name);
        $full_table_name = $wpdb->prefix . $table_name;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)) == $full_table_name) {
            return new WP_Error('table_exists', 'A table with this name already exists', array('status' => 400));
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $full_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
    
        if (!empty($columns) && is_array($columns)) {
            foreach ($columns as $column) {
                $column_name = sanitize_key($column['name']);
                $column_type = self::get_sql_type($column['type']);
                $sql = str_replace('PRIMARY KEY  (id)', "$column_name $column_type,\nPRIMARY KEY  (id)", $sql);
            }
        }
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)) == $full_table_name) {
            return new WP_REST_Response(array('success' => true, 'tableName' => $table_name, 'columns' => $columns), 200);
        } else {
            return new WP_Error('table_creation_failed', 'Failed to create the table', array('status' => 500));
        }
    }

    public static function get_table_structure($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $request->get_param('table');

        $cache_key = 'wp_ai_workflows_table_structure_' . md5($table_name);
        $structure = wp_cache_get($cache_key);

        if (false === $structure) {
            $columns = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table_name"));

            $structure = array_map(function($column) {
                return [
                    'name' => $column->Field,
                    'type' => $column->Type
                ];
            }, $columns);

            wp_cache_set($cache_key, $structure, '', 3600); // Cache for 1 hour
        }

        return new WP_REST_Response($structure, 200);
    }

    public static function delete_table($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $request->get_param('table');
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            return new WP_Error('invalid_table', 'The specified table does not exist', array('status' => 400));
        }

        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS $table_name"));

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) == $table_name) {
            return new WP_Error('delete_failed', 'Failed to delete the table', array('status' => 500));
        }

        return new WP_REST_Response(array('message' => 'Table deleted successfully'), 200);
    }

    public static function delete_entry($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . $request->get_param('table');
        $entry_id = $request->get_param('id');
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            return new WP_Error('invalid_table', 'The specified table does not exist', array('status' => 400));
        }

        $result = $wpdb->delete($table_name, array('id' => $entry_id), array('%d'));

        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete the entry', array('status' => 500));
        }

        return new WP_REST_Response(array('message' => 'Entry deleted successfully'), 200);
    }

    private static function get_sql_type($type) {
        switch ($type) {
            case 'text':
                return 'TEXT';
            case 'number':
                return 'FLOAT';
            case 'datetime':
                return 'DATETIME';
            default:
                return 'TEXT';
        }
    }

    public static function update_database_schema() {
        global $wpdb;
        
        // First, ensure all required tables exist
        $tables_created = self::ensure_tables_exist();
        
        // Then proceed with column updates
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        $required_columns = array(
            'current_node' => 'VARCHAR(255)',
            'error_message' => 'TEXT',
            'total_cost' => 'DECIMAL(10,6) DEFAULT 0.00',
            'cost_details' => 'JSON DEFAULT NULL'
        );
    
        // Get existing columns
        $existing_columns = array();
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        foreach ($columns as $column) {
            $existing_columns[] = $column->Field;
        }
    
        $columns_added = false;
        foreach ($required_columns as $column_name => $column_definition) {
            if (!in_array($column_name, $existing_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column_name $column_definition");
                $columns_added = true;
                WP_AI_Workflows_Utilities::debug_log("Added new column", "info", [
                    'table' => $table_name,
                    'column' => $column_name
                ]);
            }
        }
    
        if ($tables_created || $columns_added) {
            update_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION);
        }
    }

    public static function cleanup_old_chat_data() {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
        $messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
    
        // Delete sessions older than 30 days
        $old_sessions = $wpdb->get_col(
            "SELECT session_id FROM $sessions_table 
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    
        if (!empty($old_sessions)) {
            $session_ids = implode("','", array_map('esc_sql', $old_sessions));
            
            // Delete associated messages
            $wpdb->query(
                "DELETE FROM $messages_table 
                WHERE session_id IN ('$session_ids')"
            );
            
            // Delete old sessions
            $wpdb->query(
                "DELETE FROM $sessions_table 
                WHERE session_id IN ('$session_ids')"
            );
        }
    }
    
    public static function update_chat_schema() {
        $current_version = get_option('wp_ai_workflows_chat_db_version', '0');
        
        if (version_compare($current_version, WP_AI_WORKFLOWS_PRO_VERSION, '<')) {
            self::create_chat_tables();
        }
    }

    public static function schedule_cleanup() {
        if (!wp_next_scheduled('wp_ai_workflows_cleanup_chat_data')) {
            wp_schedule_event(time(), 'daily', 'wp_ai_workflows_cleanup_chat_data');
        }
    }

    public static function verify_tables_exist() {
        global $wpdb;
        $required_tables = [
            'wp_ai_workflows_shortcode_outputs',
            'wp_ai_workflows_outputs',
            'wp_ai_workflows_executions',
            'wp_ai_workflows_templates',
            'wp_ai_workflows_human_tasks',
            'wp_ai_workflows_sheet_states',
            'wp_ai_workflows_chat_sessions',
            'wp_ai_workflows_chat_messages',
            'wp_ai_workflows_cost_settings',
            'wp_ai_workflows_node_costs',
            'wp_ai_workflows_vector_stores',
            'wp_ai_workflows_vector_files',
            'wp_ai_workflows_license_security',
            'wp_ai_workflows_workflow_data',
            'wp_ai_workflows_mcp_servers', 

        ];
    
        $missing_tables = [];
        foreach ($required_tables as $table) {
            $table_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $missing_tables[] = $table;
            }
        }
    
        if (!empty($missing_tables)) {
            WP_AI_Workflows_Utilities::debug_log("Missing required tables", "error", [
                'missing_tables' => $missing_tables
            ]);
            
            // Force table creation
            delete_option('wp_ai_workflows_db_version');
            self::create_tables();
            
            // Verify again
            $still_missing = [];
            foreach ($missing_tables as $table) {
                $table_name = $wpdb->prefix . $table;
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                    $still_missing[] = $table;
                }
            }
    
            if (!empty($still_missing)) {
                WP_AI_Workflows_Utilities::debug_log("Failed to create tables", "error", [
                    'still_missing' => $still_missing
                ]);
                return false;
            }
        }
    
        return true;
    }

    public static function ensure_tables_exist() {
        global $wpdb;
        $required_tables = [
            'wp_ai_workflows_cost_settings' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_cost_settings (
                    id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    provider VARCHAR(50) NOT NULL,
                    model VARCHAR(255) NOT NULL,
                    input_cost DECIMAL(10,6) NOT NULL,
                    output_cost DECIMAL(10,6) NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY provider_model (provider, model)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_node_costs' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_node_costs (
                    id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    execution_id BIGINT(20) NOT NULL,
                    node_id VARCHAR(255) NOT NULL,
                    model VARCHAR(255) NOT NULL,
                    provider VARCHAR(50) NOT NULL,
                    prompt_tokens INT UNSIGNED DEFAULT 0,
                    completion_tokens INT UNSIGNED DEFAULT 0,
                    cost DECIMAL(10,6) DEFAULT 0.00,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY execution_node (execution_id, node_id),
                    KEY model_idx (model),
                    KEY provider_idx (provider)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_executions' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_executions (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    workflow_id varchar(255) NOT NULL,
                    workflow_name varchar(255) NOT NULL,
                    status varchar(20) NOT NULL,
                    input_data longtext,
                    output_data longtext,
                    current_node varchar(255),
                    error_message text,
                    total_cost DECIMAL(10,6) DEFAULT 0.00,
                    cost_details JSON DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    scheduled_at datetime,
                    PRIMARY KEY  (id)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_assistant_messages' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_assistant_messages (
                    message_id bigint(20) NOT NULL AUTO_INCREMENT,
                    session_id varchar(36) NOT NULL,
                    role varchar(20) NOT NULL,
                    content longtext NOT NULL,
                    node_context text DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (message_id),
                    KEY session_id (session_id),
                    KEY message_ordering (session_id, created_at)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_assistant_sessions' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_assistant_sessions (
                    session_id varchar(36) NOT NULL,
                    workflow_id varchar(255) NOT NULL,
                    workflow_context longtext,
                    selected_node varchar(255) DEFAULT NULL,
                    mode varchar(20) NOT NULL DEFAULT 'chat',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (session_id),
                    KEY workflow_id (workflow_id),
                    KEY mode (mode)
                    ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_vector_stores' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_vector_stores (
                    id VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    is_default TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_vector_files' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_vector_files (
                    id VARCHAR(255) NOT NULL,
                    store_id VARCHAR(255) NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(100),
                    size BIGINT,
                    status VARCHAR(20) DEFAULT 'pending',
                    url TEXT,
                    local_path TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY store_id (store_id)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_license_security' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_license_security (
                    id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    check_time DATETIME NOT NULL,
                    check_type VARCHAR(50) NOT NULL,
                    ip_address VARCHAR(100) NOT NULL,
                    result VARCHAR(20) NOT NULL,
                    license_key_fragment VARCHAR(32),
                    site_hash VARCHAR(64) NOT NULL,
                    http_filter_status TINYINT(1) DEFAULT 0,
                    details TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY check_time (check_time),
                    KEY result (result),
                    KEY site_hash (site_hash)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_workflow_data' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_workflow_data (
                    id VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    data LONGTEXT NOT NULL,
                    created_by VARCHAR(255),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY name (name),
                    KEY status (status),
                    KEY created_at (created_at),
                    KEY updated_at (updated_at)
                ) {$wpdb->get_charset_collate()};",
            'wp_ai_workflows_mcp_servers' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_ai_workflows_mcp_servers (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    user_id bigint(20) NOT NULL,
                    name varchar(255) NOT NULL,
                    description text,
                    config longtext NOT NULL,
                    discovered_tools longtext,
                    is_active boolean DEFAULT 1,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY user_id (user_id),
                    KEY name (name),
                    KEY is_active (is_active),
                    KEY created_at (created_at)
                ) {$wpdb->get_charset_collate()};",
        ];
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $tables_created = false;
    
        foreach ($required_tables as $table => $sql) {
            $table_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                dbDelta($sql);
                $tables_created = true;
                WP_AI_Workflows_Utilities::debug_log("Created missing table", "info", [
                    'table' => $table,
                    'sql' => $sql
                ]);
            } else {
                // Verify and update table structure if needed
                dbDelta($sql);
            }
        }
    
        // Additional verification for executions table
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$executions_table'") === $executions_table) {
            // Verify required columns exist
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $executions_table");
            $required_columns = [
                'id', 'workflow_id', 'workflow_name', 'status', 
                'input_data', 'output_data', 'current_node', 
                'error_message', 'total_cost', 'cost_details'
            ];
            
            foreach ($required_columns as $column) {
                if (!in_array($column, $columns)) {
                    WP_AI_Workflows_Utilities::debug_log("Missing required column", "warning", [
                        'table' => 'wp_ai_workflows_executions',
                        'column' => $column
                    ]);
                    $tables_created = true; // Force update
                    break;
                }
            }
        }
    
        if ($tables_created) {
            update_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION);
        }
    
        return $tables_created;
    }

    /**
     * Save custom MCP server configuration
     */
    public static function save_mcp_server($user_id, $name, $description, $config, $discovered_tools = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';
        
        $data = [
            'user_id' => $user_id,
            'name' => sanitize_text_field($name),
            'description' => sanitize_textarea_field($description),
            'config' => wp_json_encode($config),
            'discovered_tools' => $discovered_tools ? wp_json_encode($discovered_tools) : null,
            'is_active' => 1
        ];
        
        $result = $wpdb->insert($table_name, $data);
        
        if ($result === false) {
            WP_AI_Workflows_Utilities::debug_log("Failed to save MCP server", "error", [
                'user_id' => $user_id,
                'name' => $name,
                'error' => $wpdb->last_error
            ]);
            return false;
        }
        
        return $wpdb->insert_id;
    }

    /**
     * Get MCP servers for a user
     */
    public static function get_mcp_servers($user_id = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';
        
        if ($user_id) {
            $servers = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE user_id = %d AND is_active = 1 ORDER BY name ASC",
                $user_id
            ), ARRAY_A);
        } else {
            $servers = $wpdb->get_results(
                "SELECT * FROM $table_name WHERE is_active = 1 ORDER BY name ASC",
                ARRAY_A
            );
        }
        
        // Decode JSON fields
        foreach ($servers as &$server) {
            $server['config'] = json_decode($server['config'], true);
            if ($server['discovered_tools']) {
                $server['discovered_tools'] = json_decode($server['discovered_tools'], true);
            }
        }
        
        return $servers;
    }

    /**
     * Update MCP server discovered tools
     */
    public static function update_mcp_server_tools($server_id, $discovered_tools) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';
        
        $result = $wpdb->update(
            $table_name,
            ['discovered_tools' => wp_json_encode($discovered_tools)],
            ['id' => $server_id],
            ['%s'],
            ['%d']
        );
        
        return $result !== false;
    }

    /**
     * Delete MCP server
     */
    public static function delete_mcp_server($server_id, $user_id = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';
        
        $where = ['id' => $server_id];
        $where_format = ['%d'];
        
        if ($user_id) {
            $where['user_id'] = $user_id;
            $where_format[] = '%d';
        }
        
        return $wpdb->delete($table_name, $where, $where_format) !== false;
    }

    /**
     * Get single MCP server by ID
     */
    public static function get_mcp_server($server_id, $user_id = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';
        
        if ($user_id) {
            $server = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d AND user_id = %d AND is_active = 1",
                $server_id, $user_id
            ), ARRAY_A);
        } else {
            $server = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d AND is_active = 1",
                $server_id
            ), ARRAY_A);
        }
        
        if ($server) {
            $server['config'] = json_decode($server['config'], true);
            if ($server['discovered_tools']) {
                $server['discovered_tools'] = json_decode($server['discovered_tools'], true);
            }
        }
        
        return $server;
    }



}