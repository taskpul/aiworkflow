<?php

class WP_AI_Workflows_Chat_Embedder {
    private static $instance = null;
    private $script_loaded = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        // Register shortcode
        add_shortcode('wp_ai_workflow_chat', array($this, 'render_shortcode'));

        // Register widget
        add_action('widgets_init', array($this, 'register_widget'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));

        // Add chat container to footer
        add_action('wp_footer', array($this, 'add_chat_container'));
    }

    public function register_assets() {
        // Check if admin page to avoid double loading
        if (is_admin()) {
            return;
        }
    
        $js_files = glob(WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/js/main.*.js');
        $css_files = glob(WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/css/main.*.css');
    
        $js_file = !empty($js_files) ? basename($js_files[0]) : 'main.js';
        $css_file = !empty($css_files) ? basename($css_files[0]) : 'main.css';
    
        wp_register_script('wp-ai-workflows-app',
            plugins_url('build/static/js/' . $js_file, WP_AI_WORKFLOWS_PLUGIN_DIR . 'wp-ai-workflows.php'),
            array(),
            WP_AI_WORKFLOWS_VERSION,
            true
        );
    
        wp_register_style('wp-ai-workflows-app',
            plugins_url('build/static/css/' . $css_file, WP_AI_WORKFLOWS_PLUGIN_DIR . 'wp-ai-workflows.php'),
            array(),
            WP_AI_WORKFLOWS_VERSION
        );
    
        // Different settings for frontend vs admin
        wp_localize_script('wp-ai-workflows-app', 'wpAiWorkflowsSettings', array(
            'apiUrl' => rest_url('wp-ai-workflows/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'siteUrl' => get_site_url(),
            'assetsUrl' => plugins_url('assets', WP_AI_WORKFLOWS_PLUGIN_DIR . 'wp-ai-workflows.php'),
            'isChat' => true
        ));
    }
    

    public function enqueue_assets() {
        if (!$this->script_loaded) {
            error_log('Loading WP AI Workflows assets');
            wp_enqueue_script('wp-ai-workflows-app');
            wp_enqueue_style('wp-ai-workflows-app');
            $this->script_loaded = true;
        }
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'theme' => '',
            'position' => '',
            'show_citations' => '', 
            'show_powered_by' => '',
            'include_page_context' => '' 
        ), $atts, 'wp_ai_workflow_chat');
    
        if (empty($atts['id'])) {
            return '<div class="wp-ai-workflows-error">Error: Workflow ID is required</div>';
        }
    
        // Clean the ID to get base workflow ID
        $workflow_id = preg_replace('/-[\w\d]+$/', '', $atts['id']);
    
        // Get chat configuration
        $config = $this->get_chat_config($workflow_id);
        if (is_wp_error($config)) {
            return '<div class="wp-ai-workflows-error">Error: ' . esc_html($config->get_error_message()) . '</div>';
        }
    
        // Override config with shortcode attributes if provided
        if (!empty($atts['theme'])) {
            $config['design']['theme'] = $atts['theme'];
        }
        
        if (!empty($atts['position'])) {
            $config['design']['position'] = $atts['position'];
        }
        
        if ($atts['show_citations'] !== '') {
            $show_citations = filter_var($atts['show_citations'], FILTER_VALIDATE_BOOLEAN);
            $config['behavior']['showCitations'] = $show_citations;
        }
        
        if ($atts['show_powered_by'] !== '') {
            $show_powered_by = filter_var($atts['show_powered_by'], FILTER_VALIDATE_BOOLEAN);
            $config['design']['showPoweredBy'] = $show_powered_by;
        }
        
        // Override includePageContext if specified in shortcode
        if ($atts['include_page_context'] !== '') {
            $include_page_context = filter_var($atts['include_page_context'], FILTER_VALIDATE_BOOLEAN);
            $config['behavior']['includePageContext'] = $include_page_context;
        }
        
        // If page context is enabled, capture the current page information
        if (isset($config['behavior']['includePageContext']) && $config['behavior']['includePageContext']) {
            global $post;
            if ($post) {
                // Get a sanitized excerpt of the post content
                $content_excerpt = wp_strip_all_tags($post->post_content);
                $content_excerpt = wp_trim_words($content_excerpt, 400, '...');
                
                $config['page_context'] = [
                    'page_id' => $post->ID,
                    'page_title' => get_the_title($post),
                    'page_url' => get_permalink($post),
                    'content_summary' => $content_excerpt,
                    'page_type' => get_post_type($post)
                ];
                
                // For WooCommerce product pages, add product information
                if (function_exists('is_product') && is_product() && function_exists('wc_get_product')) {
                    $product = wc_get_product($post->ID);
                    if ($product) {
                        $config['page_context']['product_info'] = [
                            'price' => $product->get_price(),
                            'regular_price' => $product->get_regular_price(),
                            'sale_price' => $product->get_sale_price(),
                            'sku' => $product->get_sku(),
                            'stock_status' => $product->get_stock_status(),
                            'categories' => wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names'])
                        ];
                    }
                }
                
                // Log the context for debugging
                WP_AI_Workflows_Utilities::debug_log("Added page context to chat widget", "debug", [
                    'workflow_id' => $workflow_id,
                    'page_title' => get_the_title($post),
                    'page_type' => get_post_type($post)
                ]);
            }
        }
    
        $this->enqueue_assets();
    
        // Generate a unique ID for this widget instance
        $container_id = 'wp-ai-workflows-chat-' . $workflow_id . '-' . uniqid();

        // Ensure proper encoding
        $encoded_config = base64_encode(json_encode($config));

    
        return sprintf(
            '<div id="%s" class="wp-ai-workflows-chat-container" data-workflow-id="%s" data-config="%s"></div>',
            esc_attr($container_id),
            esc_attr($workflow_id),
            esc_attr(base64_encode(json_encode($config)))
        );
    }

    public function add_chat_container() {
        // Add global chat container for JS embeds
        echo '<div id="wp-ai-workflows-chat-global"></div>';
    }

    public function register_widget() {
        register_widget('WP_AI_Workflows_Chat_Widget');
    }

    private function get_chat_config($workflow_id) {
        $request = new WP_REST_Request('GET', '/wp-ai-workflows/v1/chat-config/' . $workflow_id);
        $response = rest_do_request($request);
    
        if ($response->is_error()) {
            return new WP_Error('chat_config_error', 'Failed to load chat configuration');
        }
    
        $data = $response->get_data();
        $config = $data['config'];
        
        // Ensure showCitations has a default value
        if (!isset($config['behavior']['showCitations'])) {
            $config['behavior']['showCitations'] = true;
        }
        
        // Log the configuration for debugging
        WP_AI_Workflows_Utilities::debug_log("Chat widget loaded config", "debug", [
            'workflow_id' => $workflow_id,
            'model' => $config['model'] ?? 'not set',
            'show_citations' => $config['behavior']['showCitations'],
            'has_openai_tools' => !empty($config['openaiTools'])
        ]);
    
        return $config;
    }

}

class WP_AI_Workflows_Chat_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'wp_ai_workflows_chat_widget',
            'WP AI Workflows Chat',
            array('description' => 'Add an AI chat widget to your sidebar')
        );
    }

    public function widget($args, $instance) {
        if (empty($instance['workflow_id'])) {
            return;
        }
    
        // Get embedder instance to use its methods
        $embedder = WP_AI_Workflows_Chat_Embedder::get_instance();
        
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . 
                 apply_filters('widget_title', $instance['title']) . 
                 $args['after_title'];
        }
    
        // Enqueue necessary assets
        $embedder->enqueue_assets();
    
        // Get chat configuration
        $config = $embedder->get_chat_config($instance['workflow_id']);
        if (!is_wp_error($config)) {
            // Override config with widget settings if provided
            if (!empty($instance['theme'])) {
                $config['design']['theme'] = $instance['theme'];
            }
            if (!empty($instance['position'])) {
                $config['design']['position'] = $instance['position'];
            }
            
            // Set the show_citations setting if provided
            if (isset($instance['show_citations'])) {
                $config['behavior']['showCitations'] = (bool)$instance['show_citations'];
            }
            
            // Set the show_powered_by setting if provided
            if (isset($instance['show_powered_by'])) {
                $config['design']['showPoweredBy'] = (bool)$instance['show_powered_by'];
            }
    
            $container_id = 'wp-ai-workflows-chat-widget-' . $instance['workflow_id'];
            
            echo sprintf(
                '<div id="%s" class="wp-ai-workflows-chat-container" data-workflow-id="%s" data-config="%s"></div>',
                esc_attr($container_id),
                esc_attr($instance['workflow_id']),
                esc_attr(base64_encode(json_encode($config)))
            );
        }
        
        echo $args['after_widget'];
    }
    

    public function form($instance) {
        $title = isset($instance['title']) ? $instance['title'] : '';
        $workflow_id = isset($instance['workflow_id']) ? $instance['workflow_id'] : '';
        $theme = isset($instance['theme']) ? $instance['theme'] : 'light';
        $position = isset($instance['position']) ? $instance['position'] : 'bottom-right';
        $show_citations = isset($instance['show_citations']) ? (bool)$instance['show_citations'] : true;
        $show_powered_by = isset($instance['show_powered_by']) ? (bool)$instance['show_powered_by'] : true;
        
        // Get available workflows with chat nodes
        $workflows = $this->get_chat_workflows();
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">Title:</label>
            <input class="widefat" 
                   id="<?php echo $this->get_field_id('title'); ?>" 
                   name="<?php echo $this->get_field_name('title'); ?>" 
                   type="text" 
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('workflow_id'); ?>">Select Workflow:</label>
            <select class="widefat" 
                    id="<?php echo $this->get_field_id('workflow_id'); ?>" 
                    name="<?php echo $this->get_field_name('workflow_id'); ?>">
                <option value="">Select a workflow...</option>
                <?php foreach ($workflows as $workflow) : ?>
                    <option value="<?php echo esc_attr($workflow['id']); ?>" 
                            <?php selected($workflow_id, $workflow['id']); ?>>
                        <?php echo esc_html($workflow['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('theme'); ?>">Theme:</label>
            <select class="widefat" 
                    id="<?php echo $this->get_field_id('theme'); ?>" 
                    name="<?php echo $this->get_field_name('theme'); ?>">
                <option value="light" <?php selected($theme, 'light'); ?>>Light</option>
                <option value="dark" <?php selected($theme, 'dark'); ?>>Dark</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('position'); ?>">Position:</label>
            <select class="widefat" 
                    id="<?php echo $this->get_field_id('position'); ?>" 
                    name="<?php echo $this->get_field_name('position'); ?>">
                <option value="bottom-right" <?php selected($position, 'bottom-right'); ?>>Bottom Right</option>
                <option value="bottom-left" <?php selected($position, 'bottom-left'); ?>>Bottom Left</option>
                <option value="top-right" <?php selected($position, 'top-right'); ?>>Top Right</option>
                <option value="top-left" <?php selected($position, 'top-left'); ?>>Top Left</option>
            </select>
        </p>
        <p>
            <input type="checkbox"
                   id="<?php echo $this->get_field_id('show_citations'); ?>"
                   name="<?php echo $this->get_field_name('show_citations'); ?>"
                   value="1"
                   <?php checked($show_citations, true); ?>>
            <label for="<?php echo $this->get_field_id('show_citations'); ?>">
                Show source citations for web and file search
            </label>
        </p>
        <p>
        <input type="checkbox"
               id="<?php echo $this->get_field_id('show_powered_by'); ?>"
               name="<?php echo $this->get_field_name('show_powered_by'); ?>"
               value="1"
               <?php checked($show_powered_by, true); ?>>
        <label for="<?php echo $this->get_field_id('show_powered_by'); ?>">
            Show "Powered by" footer
        </label>
    </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) 
            ? strip_tags($new_instance['title']) 
            : '';
        $instance['workflow_id'] = (!empty($new_instance['workflow_id'])) 
            ? strip_tags($new_instance['workflow_id']) 
            : '';
        $instance['theme'] = (!empty($new_instance['theme'])) 
            ? strip_tags($new_instance['theme']) 
            : 'light';
        $instance['position'] = (!empty($new_instance['position'])) 
            ? strip_tags($new_instance['position']) 
            : 'bottom-right';
        $instance['show_citations'] = !empty($new_instance['show_citations']);
        $instance['show_powered_by'] = !empty($new_instance['show_powered_by']);
        
        return $instance;
    }

    private function get_chat_workflows() {
        // Get all workflows using DBAL
        $workflows = WP_AI_Workflows_Workflow_DBAL::get_all_workflows();
        
        // Filter to find chat workflows
        return array_filter($workflows, function($workflow) {
            foreach ($workflow['nodes'] as $node) {
                if ($node['type'] === 'chat') {
                    return true;
                }
            }
            return false;
        });
    }
}