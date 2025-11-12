<?php
/*
 * Plugin Name: AI Workflow Automation
 * Plugin URI: https://wpaiworkflowautomation.com
 * Description: Build AI-powered workflows with a visual interface.
 * Version: 1.4.2
 * Requires at least: 6.0.0
 * Requires PHP: 8.0.0
 * Author: Massive Shift
 * Author URI: https://wpaiworkflowautomation.com 
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-workflows
 * Domain Path: /languages
*/

// Exit if accessed directly
if (!defined('WPINC')) {
    die;
}

// Custom error handler for initialization
function wp_ai_workflows_handle_error($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log(sprintf('WP AI Workflows Error: %s in %s on line %d', $errstr, $errfile, $errline));
    return true;
}
set_error_handler('wp_ai_workflows_handle_error');

// Define constants
define('WP_AI_WORKFLOWS_VERSION', '1.4.2');
define('WP_AI_WORKFLOWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_AI_WORKFLOWS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_AI_WORKFLOWS_PLUGIN_FILE', __FILE__);
define('WP_AI_WORKFLOWS_DEBUG', true);
define('WP_AI_WORKFLOWS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WP_AI_WORKFLOWS_DB_VERSION_OPTION', 'wp_ai_workflows_db_version');
define('WP_AI_WORKFLOWS_DB_CHARSET', 'utf8mb4');
define('WP_AI_WORKFLOWS_DB_COLLATE', 'utf8mb4_unicode_ci');

// Include required files
$required_files = [
    'utilities', 'database', 'workflow', 'workflow-dbal', 'node-execution', 'rest-api', 'shortcode',
    'encryption', 'human-tasks', 'google-service', 'generator', 'chat-session', 'chat-handler',
    'chat-embedder', 'analytics-collector', 'cost-management', 'assistant-chat', 'vector-store',
    'viewer', 'multimedia-generator', 'mcp-client'
];

foreach ($required_files as $file) {
    $file_path = WP_AI_WORKFLOWS_PLUGIN_DIR . "includes/class-wp-ai-workflows-{$file}.php";
    if (file_exists($file_path)) {
        require_once $file_path;
    }
}

// Always require admin class
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'admin/class-wp-ai-workflows-admin.php';

// Temporary method for the transition period
function wp_ai_workflows_repair_options() {
    if (!get_option('wp_ai_workflows_migrated_to_table', false)) {
        return;
    }
    
    $option_value = get_option('wp_ai_workflows');
    if (!is_array($option_value)) {
        update_option('wp_ai_workflows', array());
        WP_AI_Workflows_Utilities::debug_log("Repaired corrupted workflows option", "info");
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $results = $wpdb->get_results("SELECT id, data FROM $table_name ORDER BY updated_at DESC", ARRAY_A);
            
            if (!empty($results)) {
                $workflows = array();
                foreach ($results as $row) {
                    $workflows[] = json_decode($row['data'], true);
                }
                
                update_option('wp_ai_workflows', $workflows);
                WP_AI_Workflows_Utilities::debug_log("Restored workflows from database to options", "info", [
                    'count' => count($workflows)
                ]);
            }
        }
    }
}

add_action('plugins_loaded', 'wp_ai_workflows_repair_options', 20);

/**
 * Plugin activation
 */
function activate_wp_ai_workflows() {
    ob_start();
    
    $lock_key = 'wp_ai_workflows_activating';
    if (get_transient($lock_key)) {
        WP_AI_Workflows_Utilities::debug_log("Activation already in progress", "warning");
        ob_end_clean();
        return;
    }
    set_transient($lock_key, true, 30);

    try {
        WP_AI_Workflows_Utilities::debug_log("Starting plugin activation", "info", [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => WP_AI_WORKFLOWS_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'active_plugins' => get_option('active_plugins'),
            'is_multisite' => is_multisite(),
            'db_version' => get_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION)
        ]);

        // Core activation tasks
        WP_AI_Workflows_Database::create_tables();
        WP_AI_Workflows_Utilities::generate_and_encrypt_api_key();
        wp_ai_workflows_schedule_cleanup_cron();
        delete_option('wp_ai_workflows_human_tasks_db_version');
        update_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_VERSION);
        wp_ai_workflows_setup_files();

        // Initialize cost settings
        if (class_exists('WP_AI_Workflows_Cost_Management')) {
            WP_AI_Workflows_Cost_Management::get_instance()->initialize_cost_settings();
        }

        // Setup analytics
        if (get_option('wp_ai_workflows_analytics_opt_out') === false) {
            update_option('wp_ai_workflows_analytics_opt_out', false);
        }

        // Setup chat cleanup schedule
        if (!wp_next_scheduled('wp_ai_workflows_cleanup_chat_data')) {
            wp_schedule_event(time(), 'daily', 'wp_ai_workflows_cleanup_chat_data');
        }

        // Setup daily maintenance schedule
        if (!wp_next_scheduled('wp_ai_workflows_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'wp_ai_workflows_daily_maintenance');
        }

        // Setup administrator capabilities
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_workflow_tasks');
        }
        
        if (!get_option('wp_ai_workflows_task_roles')) {
            update_option('wp_ai_workflows_task_roles', ['administrator']);
        }

        WP_AI_Workflows_Utilities::debug_log("Plugin activation completed successfully", "info");

        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('WP AI Workflows activation output: ' . $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        WP_AI_Workflows_Utilities::debug_log("Activation failed", "error", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        error_log('WP AI Workflows activation error: ' . $e->getMessage());
        throw $e;
    } finally {
        delete_transient($lock_key);
    }
}

/**
 * Plugin deactivation
 */
function deactivate_wp_ai_workflows() {
    ob_start();
    try {
        wp_clear_scheduled_hook('wp_ai_workflows_cleanup');
        wp_clear_scheduled_hook('wp_ai_workflows_cleanup_chat_data');
        wp_clear_scheduled_hook('wp_ai_workflows_daily_maintenance');
        WP_AI_Workflows_Utilities::debug_log("Plugin deactivated and cleanup tasks unscheduled");
        
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('WP AI Workflows deactivation output: ' . $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        error_log('WP AI Workflows deactivation error: ' . $e->getMessage());
    }
}

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'activate_wp_ai_workflows');
register_activation_hook(__FILE__, array('WP_AI_Workflows_Utilities', 'migrate_to_db_table'));
register_deactivation_hook(__FILE__, 'deactivate_wp_ai_workflows');

/**
 * Increase execution time for plugin operations
 */
function wp_ai_workflows_increase_execution_time() {
    if (isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'wp-ai-workflows') !== false) {
        ini_set('max_execution_time', 300);
        set_time_limit(300);
    }
}
add_action('init', 'wp_ai_workflows_increase_execution_time');

/**
 * Main plugin initialization
 */
function run_wp_ai_workflows() {
    ob_start();
    try {
        // Initialize core components
        $components = [
            new WP_AI_Workflows_REST_API(),
            new WP_AI_Workflows_Admin(),
            new WP_AI_Workflows_Database(),
            new WP_AI_Workflows_Analytics_Collector(WP_AI_WORKFLOWS_VERSION),
        ];

        foreach ($components as $component) {
            $component->init();
        }

        if (class_exists('WP_AI_Workflows_Encryption')) {
            WP_AI_Workflows_Encryption::init();
        }

        initialize_full_functionality();

        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('WP AI Workflows initialization output: ' . $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        error_log('WP AI Workflows initialization error: ' . $e->getMessage());
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p>' . esc_html('WP AI Workflows encountered an error: ' . $e->getMessage()) . '</p></div>';
        });
    }
}

/**
 * Initialize full plugin functionality
 */
function initialize_full_functionality() {
    ob_start();
    try {
        $components = [
            new WP_AI_Workflows_Workflow(),
            new WP_AI_Workflows_Shortcode(),
            new WP_AI_Workflows_Node_Execution(),
            new WP_AI_Workflows_Utilities(),
        ];

        // Initialize chat components if available
        if (class_exists('WP_AI_Workflows_Chat_Embedder')) {
            $components[] = new WP_AI_Workflows_Chat_Embedder();
        }
        if (class_exists('WP_AI_Workflows_Assistant_Chat')) {
            $components[] = new WP_AI_Workflows_Assistant_Chat();
        }
        if (class_exists('WP_AI_Workflows_Viewer')) {
            $components[] = new WP_AI_Workflows_Viewer();
        }
        if (class_exists('WP_AI_Workflows_Multimedia_Generator')) {
            $components[] = new WP_AI_Workflows_Multimedia_Generator();
        }
        if (class_exists('WP_AI_Workflows_MCP_Client')) {
            $components[] = new WP_AI_Workflows_MCP_Client();
        }

        foreach ($components as $component) {
            $component->init();
        }

        if (class_exists('WP_AI_Workflows_Human_Tasks')) {
            $human_tasks = new WP_AI_Workflows_Human_Tasks();
            $human_tasks->check_and_add_missing_columns();
        }

        // Initialize additional components
        if (class_exists('WP_AI_Workflows_Generator')) {
            new WP_AI_Workflows_Generator();
        }
        if (class_exists('WP_AI_Workflows_Vector_Store')) {
            new WP_AI_Workflows_Vector_Store();
        }

        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('WP AI Workflows full initialization output: ' . $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        error_log('WP AI Workflows full initialization error: ' . $e->getMessage());
        throw $e;
    }

    add_action('init', array('WP_AI_Workflows_Utilities', 'check_and_run_migration'), 20);
    add_action('admin_notices', array('WP_AI_Workflows_Utilities', 'admin_migration_notice'));
}

/**
 * Schedule daily maintenance cron job
 */
function wp_ai_workflows_schedule_daily_maintenance() {
    if (!wp_next_scheduled('wp_ai_workflows_daily_maintenance')) {
        wp_schedule_event(time(), 'daily', 'wp_ai_workflows_daily_maintenance');
    }
}
add_action('wp', 'wp_ai_workflows_schedule_daily_maintenance');

/**
 * Schedule cleanup cron job
 */
function wp_ai_workflows_schedule_cleanup_cron() {
    if (!wp_next_scheduled('wp_ai_workflows_cleanup')) {
        wp_schedule_event(time(), 'daily', 'wp_ai_workflows_cleanup');
    }
}

// Add action hooks
add_action('wp', 'wp_ai_workflows_schedule_cleanup_cron');
add_action('wp_ai_workflows_send_delayed_email', ['WP_AI_Workflows_Node_Execution', 'send_email'], 10, 2);
add_action('init', function() {
    if (class_exists('WP_AI_Workflows_Chat_Embedder')) {
        WP_AI_Workflows_Chat_Embedder::get_instance()->init();
    }
});
add_action('wp_ai_workflows_cleanup_chat_data', ['WP_AI_Workflows_Database', 'cleanup_old_chat_data']);
if (class_exists('WP_AI_Workflows_Assistant_Setup')) {
    add_action('wp_ai_workflows_cleanup_assistant_chat', array('WP_AI_Workflows_Assistant_Setup', 'cleanup_old_data'));
}
add_action('wp_ai_workflows_daily_maintenance', array('WP_AI_Workflows_Assistant_Chat', 'cleanup_old_data'));

/**
 * Plugin update check - unified edition updates
 */
function wp_ai_workflows_update_check() {
    ob_start();
    try {
        $current_version = get_option('wp_ai_workflows_version', '0');
        $current_db_version = get_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION, '0');

        if (version_compare($current_version, WP_AI_WORKFLOWS_VERSION, '<')) {
            if ($current_version === '0') {
                activate_wp_ai_workflows();
            }
            update_option('wp_ai_workflows_version', WP_AI_WORKFLOWS_VERSION);
        }

        // Always verify and update database schema
        WP_AI_Workflows_Database::update_database_schema();

        // Initialize cost settings if needed
        if (class_exists('WP_AI_Workflows_Cost_Management')) {
            $cost_settings = WP_AI_Workflows_Cost_Management::get_instance()->get_cost_settings();
            if (empty($cost_settings)) {
                WP_AI_Workflows_Cost_Management::get_instance()->initialize_cost_settings();
            }
        }

        if (version_compare($current_db_version, WP_AI_WORKFLOWS_VERSION, '<')) {
            update_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_VERSION);
            WP_AI_Workflows_Utilities::debug_log("Database updated", "info", [
                'from_version' => $current_db_version,
                'to_version' => WP_AI_WORKFLOWS_VERSION
            ]);
        }
    } catch (Exception $e) {
        WP_AI_Workflows_Utilities::debug_log("Update check failed", "error", [
            'error' => $e->getMessage()
        ]);
        error_log('WP AI Workflows update check error: ' . $e->getMessage());
    }
}
add_action('plugins_loaded', 'wp_ai_workflows_update_check');

/**
 * Plugin activation/update handler
 */
function wp_ai_workflows_activate_or_update() {
    ob_start();
    try {
        WP_AI_Workflows_Database::create_tables();
        WP_AI_Workflows_Database::update_database_schema();
        update_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_VERSION);
        
        if (class_exists('WP_AI_Workflows_Human_Tasks')) {
            $human_tasks = new WP_AI_Workflows_Human_Tasks();
            $human_tasks->check_and_add_missing_columns();
        }
        
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('WP AI Workflows activate/update output: ' . $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        error_log('WP AI Workflows activate/update error: ' . $e->getMessage());
        throw $e;
    }
}

// Version check and update trigger
register_activation_hook(__FILE__, 'wp_ai_workflows_activate_or_update');

add_action('plugins_loaded', function() {
    ob_start();
    try {
        $current_version = get_option('wp_ai_workflows_version');
        $current_db_version = get_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION);
        
        if ($current_version !== WP_AI_WORKFLOWS_VERSION ||
            $current_db_version !== WP_AI_WORKFLOWS_VERSION) {
            
            // Force DB structure check
            delete_option('wp_ai_workflows_human_tasks_db_version');
            wp_ai_workflows_activate_or_update();
            
            // Update versions
            update_option('wp_ai_workflows_version', WP_AI_WORKFLOWS_VERSION);
            update_option(WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_VERSION);
            
            WP_AI_Workflows_Utilities::debug_log(
                "Plugin and database updated", 
                "info", 
                [
                    'old_version' => $current_version,
                    'old_db_version' => $current_db_version,
                    'new_version' => WP_AI_WORKFLOWS_VERSION
                ]
            );
        }

        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('WP AI Workflows version check output: ' . $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        error_log('WP AI Workflows version check error: ' . $e->getMessage());
    }
});

/**
 * Initialize charset settings
 */
function wp_ai_workflows_init_charset() {
    add_filter('rest_pre_serve_request', function($served, $result) {
        header('Content-Type: application/json; charset=utf-8');
        return $served;
    }, 10, 2);
}
add_action('init', 'wp_ai_workflows_init_charset');

/**
 * Set up plugin files and directories
 */
function wp_ai_workflows_setup_files() {
    ob_start();
    try {
        $directories = [
            'includes/prompts',
            'includes/templates',
        ];

        foreach ($directories as $dir) {
            $path = WP_AI_WORKFLOWS_PLUGIN_DIR . $dir;
            if (!file_exists($path)) {
                if (!mkdir($path, 0755, true) && !is_dir($path)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $path));
                }
            }
        }

        // Copy system prompt template
        $template_file = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/templates/system_prompt.xml';
        $prompt_file = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/system_prompt.xml';
        $assistant_template = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/templates/assistant_system_prompt.xml';
        $assistant_prompt = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/assistant_system_prompt.xml';

        if (!file_exists($template_file)) {
            $default_template = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/data/system_prompt.xml';
            if (file_exists($default_template) && !copy($default_template, $template_file)) {
                throw new \RuntimeException('Failed to copy system prompt template');
            }
        }

        if (!file_exists($assistant_prompt) && file_exists($assistant_template)) {
            copy($assistant_template, $assistant_prompt);
        }

        if (!file_exists($prompt_file) && file_exists($template_file)) {
            if (!copy($template_file, $prompt_file)) {
                throw new \RuntimeException('Failed to copy system prompt file');
            }
        }

        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('WP AI Workflows file setup output: ' . $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        error_log('WP AI Workflows file setup error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Pre-migration notice to inform users about the database optimization
 */
function wp_ai_workflows_pre_migration_notice() {
    if (!get_option('wp_ai_workflows_migrated_to_table', false) && 
        !get_transient('wp_ai_workflows_migration_postponed')) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>Database Optimization Planned</strong></p>
            <p>AI Workflow Automation will be optimizing its database storage to improve performance. 
               We recommend backing up your database before proceeding.</p>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wp-ai-workflows&action=run-migration'), 'wp_ai_workflows_migration'); ?>" 
                   class="button button-primary">Optimize Now</a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wp-ai-workflows&action=postpone-migration'), 'wp_ai_workflows_postpone'); ?>" 
                   class="button">Remind Me Tomorrow</a>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'wp_ai_workflows_pre_migration_notice');

/**
 * Handle postpone migration action
 */
function wp_ai_workflows_handle_postpone() {
    if (isset($_GET['action']) && $_GET['action'] === 'postpone-migration' && 
        isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wp_ai_workflows_postpone')) {
        set_transient('wp_ai_workflows_migration_postponed', true, DAY_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=wp-ai-workflows'));
        exit;
    }
}
add_action('admin_init', 'wp_ai_workflows_handle_postpone');

/**
 * Show migration result notice
 */
function wp_ai_workflows_migration_result_notice() {
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
add_action('admin_notices', 'wp_ai_workflows_migration_result_notice');

// Run the plugin
run_wp_ai_workflows();

// Restore default error handler
restore_error_handler();