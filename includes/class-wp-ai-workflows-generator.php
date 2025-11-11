<?php

class WP_AI_Workflows_Generator {
    private $system_prompt = null;
    private $prompt_path = null;
    private $template_path = null;
    
    public function __construct() {
        $this->prompt_path = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/system_prompt.xml';
        $this->template_path = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/templates/system_prompt.xml';
    }

    private function ensure_system_prompt() {
        // Only load if not already loaded
        if ($this->system_prompt === null) {
            $this->load_system_prompt();
        }
        return $this->system_prompt;
    }

    private function load_system_prompt() {
        // First check cache
        $cache_key = 'wp_ai_workflows_system_prompt';
        $cached_prompt = wp_cache_get($cache_key);
        
        if ($cached_prompt !== false) {
            $this->system_prompt = $cached_prompt;
            return;
        }

        // Load from file if not in cache
        if (!file_exists($this->prompt_path)) {
            $this->initialize_prompt_file();
        }

        $prompt = file_get_contents($this->prompt_path);
        
        if (empty($prompt)) {
            WP_AI_Workflows_Utilities::debug_log('System prompt is empty', 'error');
            throw new Exception('System prompt is empty');
        }

        // Cache the prompt
        wp_cache_set($cache_key, $prompt, '', HOUR_IN_SECONDS);
        
        $this->system_prompt = $prompt;

        WP_AI_Workflows_Utilities::debug_log('System prompt loaded from file', 'debug', [
            'length' => strlen($prompt)
        ]);
    }

    private function initialize_prompt_file() {
        if (!file_exists($this->template_path)) {
            WP_AI_Workflows_Utilities::debug_log('System prompt template not found', 'error', [
                'template_path' => $this->template_path
            ]);
            throw new Exception('System prompt template not found');
        }

        // Create prompts directory if it doesn't exist
        $prompts_dir = dirname($this->prompt_path);
        if (!file_exists($prompts_dir)) {
            mkdir($prompts_dir, 0755, true);
        }

        // Copy template to prompts directory
        if (!copy($this->template_path, $this->prompt_path)) {
            throw new Exception('Failed to initialize system prompt file');
        }

        WP_AI_Workflows_Utilities::debug_log('System prompt file initialized', 'debug', [
            'path' => $this->prompt_path
        ]);
    }

    public function generate_workflow($prompt) {
        try {
            // Load system prompt only when needed
            $system_prompt = $this->ensure_system_prompt();
            
            if (empty($system_prompt)) {
                throw new Exception('System prompt not loaded');
            }

            WP_AI_Workflows_Utilities::debug_log('Starting workflow generation', 'info', [
                'prompt' => $prompt
            ]);

            // Replace placeholder in system prompt
            $full_prompt = str_replace('{USER_PROMPT}', $prompt, $system_prompt);
    
            // Get OpenRouter API key
            $api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
            if (empty($api_key)) {
                throw new Exception('OpenRouter API key is not configured');
            }
    
            // Make API call to OpenRouter
            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => get_site_url(),
                    'X-Title' => 'WP AI Workflow Generator'
                ],
                'body' => wp_json_encode([
                    'model' => 'anthropic/claude-3.5-sonnet',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $full_prompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 4000,
                    'stop' => ['</response>', '</output>']
                ]),
                'timeout' => 120
            ]);
    
            // Log API response
            if (is_wp_error($response)) {
                WP_AI_Workflows_Utilities::debug_log('API request failed', 'error', [
                    'error' => $response->get_error_message()
                ]);
                throw new Exception('API request failed: ' . $response->get_error_message());
            }
    
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
    
    
            if (!isset($data['choices'][0]['message']['content'])) {
                WP_AI_Workflows_Utilities::debug_log('Invalid API response structure', 'error', [
                    'data' => $data
                ]);
                throw new Exception('Invalid API response structure');
            }
    
            $workflow_json = $data['choices'][0]['message']['content'];
            $workflow = json_decode($workflow_json, true);
    
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON in API response: ' . json_last_error_msg());
            }
    
            // Fix missing connections before validation
            $workflow = $this->fix_missing_connections($workflow);
    
            // Clean and validate the workflow
            $cleaned_nodes = $this->clean_nodes($workflow['nodes'] ?? []);
            $cleaned_edges = $this->clean_edges($workflow['edges'] ?? []);
    
            $cleaned_workflow = [
                'nodes' => $cleaned_nodes,
                'edges' => $cleaned_edges
            ];
    
            // Validate the cleaned workflow structure
            if (!$this->validate_workflow_structure($cleaned_workflow)) {
                throw new Exception('Generated workflow structure validation failed');
            }
    
            return $cleaned_workflow;
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log('Workflow generation error', 'error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }


    private function validate_workflow($workflow) {
        if (!isset($workflow['nodes']) || !isset($workflow['edges'])) {
            WP_AI_Workflows_Utilities::debug_log('Missing nodes or edges', 'error');
            return false;
        }
    
        try {
            $cleaned_workflow = [
                'nodes' => $this->clean_nodes($workflow['nodes']),
                'edges' => $this->clean_edges($workflow['edges'])
            ];
    
            // Validate basic workflow structure
            if (!$this->validate_workflow_structure($cleaned_workflow)) {
                return false;
            }
    
            return $cleaned_workflow;
    
        } catch (Exception $e) {
            WP_AI_Workflows_Utilities::debug_log('Workflow validation error', 'error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function normalize_node_type($type) {

        $annotation_types = [
            'stickyNote',
            'textAnnotation',
            'shape'
        ];
    
        if (in_array($type, $annotation_types)) {
            return $type; // Return annotation types as-is
        }

        // List of types that should be normalized to 'output'
        $output_types = [
            'display',
            'database',
            'shortcode',
            'webhook',
            'google_sheets',
            'google_drive',
            'html'  // Adding this in case it comes through
        ];
    
        return in_array(strtolower($type), $output_types) ? 'output' : $type;
    }
    
    private function clean_nodes($nodes) {
        if (!is_array($nodes)) {
            WP_AI_Workflows_Utilities::debug_log('Nodes is not an array', 'error');
            return [];
        }
    
        $cleaned_nodes = [];
    
        foreach ($nodes as $node) {
            if (!isset($node['id']) || !isset($node['type'])) {
                WP_AI_Workflows_Utilities::debug_log('Node missing required properties', 'error', [
                    'node' => $node
                ]);
                continue;
            }

            $normalized_type = $this->normalize_node_type($node['type']);
    
            // Keep original position if it exists, otherwise provide a default
            $clean_node = [
                'id' => $node['id'],
                'type' => $normalized_type,  // Use normalized type here
                'position' => isset($node['position']) && is_array($node['position']) ? 
                    $node['position'] : ['x' => 0, 'y' => 0]
            ];

            if (in_array($normalized_type, ['textAnnotation', 'shape'])) {
                $clean_node['className'] = 'react-flow-annotation';
                $clean_node['zIndex'] = -1;
            }

            
            // Clean node data
            $clean_node['data'] = $this->clean_node_data($normalized_type, $node['data'] ?? []);

            // Log individual node cleaning
            WP_AI_Workflows_Utilities::debug_log('Cleaned node', 'debug', [
                'original' => $node,
                'cleaned' => $clean_node
            ]);
    
            $cleaned_nodes[] = $clean_node;
        }
    
        return $cleaned_nodes;
    }
    

    private function clean_edges($edges) {
        $cleaned_edges = [];
    
        foreach ($edges as $edge) {
            if (!isset($edge['id']) || !isset($edge['source']) || !isset($edge['target'])) {
                continue;
            }
    
            // Start with required properties
            $cleaned_edge = [
                'id' => $edge['id'],
                'source' => $edge['source'],
                'target' => $edge['target']
            ];
    
            // Add sourceHandle if it exists (for condition 'true'/'false' outputs or chat action IDs)
            if (isset($edge['sourceHandle'])) {
                $cleaned_edge['sourceHandle'] = $edge['sourceHandle'];
            }
    
            // Add targetHandle if it exists
            if (isset($edge['targetHandle'])) {
                $cleaned_edge['targetHandle'] = $edge['targetHandle'];
            }
    
            // Special case for condition nodes
            if (strpos($edge['source'], 'condition-') === 0 && !isset($edge['sourceHandle'])) {
                // Default to 'true' if sourceHandle is missing for condition nodes
                $cleaned_edge['sourceHandle'] = 'true';
            }
    
            // Special case for human input nodes
            if (strpos($edge['source'], 'humanInput-') === 0 && !isset($edge['sourceHandle'])) {
                $node_type = $this->get_node_type($edge['source']);
                if ($node_type === 'approval') {
                    // Default to 'approve' if sourceHandle is missing for approval nodes
                    $cleaned_edge['sourceHandle'] = 'approve';
                } else if ($node_type === 'modification') {
                    // Default to 'modify' if sourceHandle is missing for modification nodes
                    $cleaned_edge['sourceHandle'] = 'modify';
                }
            }
    
            // Special case for chat nodes with actions
            if (strpos($edge['source'], 'chat-') === 0 && !isset($edge['sourceHandle'])) {

                WP_AI_Workflows_Utilities::debug_log('Chat node connection missing sourceHandle', 'warning', [
                    'edge' => $edge
                ]);
            }
    
            $cleaned_edges[] = $cleaned_edge;
        }
    
        return $cleaned_edges;
    }

    private function get_node_type($node_id) {
        foreach ($this->workflow['nodes'] as $node) {
            if ($node['id'] === $node_id && isset($node['data']['inputType'])) {
                return $node['data']['inputType'];
            }
        }
        return null;
    }

    private function clean_number($value, $min, $max, $default) {
        if (!is_numeric($value)) {
            return $default;
        }
        return min(max((float)$value, $min), $max);
    }

    private function clean_node_data($type, $data) {
        $cleaned_data = [];

        if (in_array($type, ['stickyNote', 'textAnnotation', 'shape'])) {
            // Preserve all annotation properties
            return [
                'content' => $data['content'] ?? '',
                'color' => $data['color'] ?? '',
                'size' => $data['size'] ?? [],
                'fontSize' => $data['fontSize'] ?? 14,
                'shapeType' => $data['shapeType'] ?? 'rectangle',
                // Add any onChange/onDelete handlers if needed
                'onChange' => null,
                'onDelete' => null
            ];
        }
    
        // Common properties all nodes should have
        $cleaned_data['nodeName'] = $data['nodeName'] ?? "$type-" . substr(uniqid(), -4);

        if ($type === 'shortcode') {
            $type = 'output';
            $data['outputType'] = 'html';
        }
    
        switch ($type) {
            case 'trigger':
                $cleaned_data['triggerType'] = $data['triggerType'] ?? 'manual';
                $cleaned_data['content'] = $data['content'] ?? '';
                
                // Specific trigger type data
                switch ($cleaned_data['triggerType']) {
                    case 'gravityForms':
                        $cleaned_data['selectedForm'] = $data['selectedForm'] ?? null;
                        $cleaned_data['selectedFields'] = $data['selectedFields'] ?? [];
                        break;
                    case 'webhook':
                        $cleaned_data['webhookUrl'] = '';
                        $cleaned_data['webhookKeys'] = $data['webhookKeys'] ?? [];
                        break;
                    case 'wpCore':
                        $cleaned_data['selectedWpCoreTrigger'] = $data['selectedWpCoreTrigger'] ?? '';
                        $cleaned_data['wpCoreTriggerConditions'] = $data['wpCoreTriggerConditions'] ?? [];
                        break;
                    case 'workflowOutput':
                        $cleaned_data['selectedWorkflow'] = $data['selectedWorkflow'] ?? null;
                        break;
                    case 'rss':
                        $cleaned_data['rssSettings'] = [
                            'feedUrl' => $data['rssSettings']['feedUrl'] ?? '',
                            'pollingInterval' => in_array(
                                $data['rssSettings']['pollingInterval'] ?? '15min',
                                ['5min', '15min', '30min', '1hour', '6hours', '24hours']
                            ) ? $data['rssSettings']['pollingInterval'] : '15min',
                            'maxItems' => min(
                                max((int)($data['rssSettings']['maxItems'] ?? 10), 1),
                                50
                            ), // Between 1 and 50
                            'includeContent' => (bool)($data['rssSettings']['includeContent'] ?? false),
                            'filters' => [
                                'title' => $data['rssSettings']['filters']['title'] ?? '',
                                'content' => $data['rssSettings']['filters']['content'] ?? '',
                                'categories' => is_array($data['rssSettings']['filters']['categories'] ?? null) 
                                    ? $data['rssSettings']['filters']['categories'] 
                                    : []
                            ]
                        ];
                        break;
                }
                break;
    
            case 'aiModel':
                $cleaned_data['model'] = $this->validate_and_get_model($data['model'] ?? 'gpt-4o-mini');
                $cleaned_data['content'] = $data['content'] ?? '';
                $cleaned_data['imageUrls'] = [];
                $cleaned_data['settings'] = [
                    'temperature' => $this->clean_number($data['settings']['temperature'] ?? 1, 0, 2, 1),
                    'max_tokens' => $this->clean_number($data['settings']['max_tokens'] ?? 4096, 1, 32768, 4096),
                    'top_p' => $this->clean_number($data['settings']['top_p'] ?? 1, 0, 1, 1),
                    'frequency_penalty' => $this->clean_number($data['settings']['frequency_penalty'] ?? 0, -2, 2, 0),
                    'presence_penalty' => $this->clean_number($data['settings']['presence_penalty'] ?? 0, -2, 2, 0)
                ];
                break;
    
            case 'sentimentAnalysis':
            case 'summaryGenerator':
                $cleaned_data['content'] = $data['content'] ?? '';
                break;
    
            case 'extractInformation':
                $cleaned_data['content'] = $data['content'] ?? '';
                $cleaned_data['extractionFields'] = array_map(function($field) {
                    return [
                        'name' => $field['name'] ?? '',
                        'description' => $field['description'] ?? '',
                        'isList' => $field['isList'] ?? false
                    ];
                }, $data['extractionFields'] ?? []);
                break;
    
            case 'writeArticle':
                $cleaned_data['content'] = $data['content'] ?? '';
                $cleaned_data['wordCount'] = $this->clean_number($data['wordCount'] ?? 500, 100, 10000, 500);
                break;
    
            case 'optimizeSEO':
                $cleaned_data['content'] = $data['content'] ?? '';
                $cleaned_data['keywords'] = $data['keywords'] ?? '';
                break;
    
            case 'research':
                $cleaned_data['content'] = $data['content'] ?? '';
                $cleaned_data['model'] = $this->validate_and_get_model($data['model'] ?? 'llama-3.1-sonar-small-128k-online');
                $cleaned_data['maxTokens'] = $this->clean_number($data['maxTokens'] ?? 4096, 1, 32768, 4096);
                $cleaned_data['temperature'] = $this->clean_number($data['temperature'] ?? 0.7, 0, 2, 0.7);
                $cleaned_data['returnCitations'] = $data['returnCitations'] ?? true;
                break;
    
            case 'parser':
                $cleaned_data['inputType'] = $data['inputType'] ?? 'link';
                $cleaned_data['documentLink'] = $data['documentLink'] ?? '';
                
                if (is_string($data['uploadedFiles']) && strpos($data['uploadedFiles'], '[Input from') !== false) {
                    $cleaned_data['uploadedFiles'] = $data['uploadedFiles'];
                } else {
                    
                    $cleaned_data['uploadedFiles'] = '';
                }
                
                $cleaned_data['parserSettings'] = [
                    'language' => $data['parserSettings']['language'] ?? 'en',
                    'parsingInstructions' => $data['parserSettings']['parsingInstructions'] ?? '',
                    'skipDiagonalText' => $data['parserSettings']['skipDiagonalText'] ?? false,
                    'doNotUnrollColumns' => $data['parserSettings']['doNotUnrollColumns'] ?? false,
                    'targetPages' => $data['parserSettings']['targetPages'] ?? ''
                ];
                break;
    
            case 'firecrawl':
                $cleaned_data['operation'] = $data['operation'] ?? 'scrape';
                $cleaned_data['url'] = $data['url'] ?? '';
                $cleaned_data['format'] = $data['format'] ?? 'markdown';
                $cleaned_data['onlyMainContent'] = $data['onlyMainContent'] ?? true;
                $cleaned_data['includeTags'] = $data['includeTags'] ?? [];
                $cleaned_data['excludeTags'] = $data['excludeTags'] ?? [];
                $cleaned_data['waitFor'] = $this->clean_number($data['waitFor'] ?? 0, 0, 60000, 0);
                $cleaned_data['timeout'] = $this->clean_number($data['timeout'] ?? 30000, 1000, 120000, 30000);
                $cleaned_data['isMobile'] = $data['isMobile'] ?? false;
                
                // Add extract-specific options
                if ($cleaned_data['format'] === 'extract') {
                    $cleaned_data['extractType'] = $data['extractType'] ?? 'prompt';
                    $cleaned_data['extractPrompt'] = $data['extractPrompt'] ?? '';
                    $cleaned_data['extractFields'] = $data['extractFields'] ?? [];
                }
                
                if ($cleaned_data['operation'] === 'crawl') {
                    $cleaned_data['maxDepth'] = $this->clean_number($data['maxDepth'] ?? 2, 1, 10, 2);
                    $cleaned_data['limit'] = $this->clean_number($data['limit'] ?? 10, 1, 100, 10);
                    $cleaned_data['ignoreSitemap'] = $data['ignoreSitemap'] ?? false;
                    $cleaned_data['allowBackwardLinks'] = $data['allowBackwardLinks'] ?? false;
                    $cleaned_data['allowExternalLinks'] = $data['allowExternalLinks'] ?? false;
                }
                break;
    
            case 'unsplash':
                $cleaned_data['searchTerm'] = $data['searchTerm'] ?? '';
                $cleaned_data['imageSize'] = in_array($data['imageSize'], ['raw', 'full', 'regular', 'small']) ? 
                    $data['imageSize'] : 'regular';
                $cleaned_data['orientation'] = in_array($data['orientation'], ['all', 'landscape', 'portrait', 'squarish']) ? 
                    $data['orientation'] : 'all';
                $cleaned_data['randomResult'] = $data['randomResult'] ?? false;
                break;
                
            case 'multimediaGenerator':
            case 'mediaGenerator':
                $cleaned_data['modelType'] = in_array($data['modelType'], ['textToImage', 'imageToVideo', 'textToVideo']) ? 
                    $data['modelType'] : 'textToImage';
                $cleaned_data['selectedModel'] = $data['selectedModel'] ?? '';
                $cleaned_data['prompt'] = $data['prompt'] ?? '';
                $cleaned_data['negativePrompt'] = $data['negativePrompt'] ?? '';
                
                // Type-specific properties
                if ($cleaned_data['modelType'] === 'imageToVideo') {
                    $cleaned_data['imageUrl'] = $data['imageUrl'] ?? '';
                }
                
                if (in_array($cleaned_data['modelType'], ['imageToVideo', 'textToVideo'])) {
                    $cleaned_data['videoLength'] = in_array($data['videoLength'], [5, 6, 7, 8, 10]) ? 
                        $data['videoLength'] : 5;
                    $cleaned_data['aspectRatio'] = in_array($data['aspectRatio'], ['16:9', '9:16', '1:1', 'auto']) ? 
                        $data['aspectRatio'] : '16:9';
                }
                
                // Save generated result if available
                if (isset($data['result'])) {
                    $cleaned_data['result'] = $data['result'];
                }
                break;
                
            case 'createFile':
                $cleaned_data['fileName'] = $data['fileName'] ?? '';
                $cleaned_data['fileFormat'] = in_array($data['fileFormat'], ['txt', 'docx', 'html']) ? 
                    $data['fileFormat'] : 'txt';
                $cleaned_data['fileContent'] = $data['fileContent'] ?? '';
                $cleaned_data['saveToMedia'] = $data['saveToMedia'] !== false; // Default to true
                break;
    
            case 'humanInput':
                $cleaned_data['inputType'] = in_array($data['inputType'], ['approval', 'modification']) ? 
                    $data['inputType'] : 'approval';
                $cleaned_data['assignmentType'] = in_array($data['assignmentType'], ['user', 'role']) ? 
                    $data['assignmentType'] : 'user';
                $cleaned_data['selectedUser'] = $data['selectedUser'] ?? '';
                $cleaned_data['selectedRole'] = $data['selectedRole'] ?? '';
                $cleaned_data['content'] = $data['content'] ?? '';
                $cleaned_data['instructions'] = $data['instructions'] ?? '';
                break;
    
            case 'condition':
                $cleaned_data['conditionGroups'] = array_map(function($group) {
                    return [
                        'type' => in_array($group['type'], ['AND', 'OR']) ? $group['type'] : 'AND',
                        'conditions' => array_map(function($condition) {
                            return [
                                'input' => $condition['input'] ?? '',
                                'comparison' => $condition['comparison'] ?? 'equals',
                                'value' => $condition['value'] ?? ''
                            ];
                        }, $group['conditions'] ?? [])
                    ];
                }, $data['conditionGroups'] ?? []);
                break;
    
            case 'sendEmail':
                $cleaned_data['to'] = $data['to'] ?? '';
                $cleaned_data['cc'] = $data['cc'] ?? '';
                $cleaned_data['bcc'] = $data['bcc'] ?? '';
                $cleaned_data['subject'] = $data['subject'] ?? '';
                $cleaned_data['body'] = $data['body'] ?? '';
                $cleaned_data['useHtml'] = $data['useHtml'] ?? true;
                $cleaned_data['delayEnabled'] = $data['delayEnabled'] ?? false;
                $cleaned_data['delayValue'] = $this->clean_number($data['delayValue'] ?? 1, 1, 10000, 1);
                $cleaned_data['delayUnit'] = in_array($data['delayUnit'], ['minutes', 'hours', 'days']) ? 
                    $data['delayUnit'] : 'minutes';
                $cleaned_data['attachments'] = $data['attachments'] ?? [];
                break;
    
            case 'post':
                $cleaned_data['selectedPostType'] = $data['selectedPostType'] ?? 'post';
                $cleaned_data['fieldMappings'] = $data['fieldMappings'] ?? [];
                $cleaned_data['postStatus'] = in_array($data['postStatus'], ['draft', 'publish', 'private', 'pending', 'future']) ? 
                    $data['postStatus'] : 'draft';
                $cleaned_data['scheduledDate'] = $data['scheduledDate'] ?? null;
                break;
    
        case 'output':
            // Ensure outputType is set and valid
            $valid_output_types = ['display', 'save', 'html', 'webhook', 'googleSheets', 'googleDrive'];
            $cleaned_data['outputType'] = in_array($data['outputType'], $valid_output_types) ? 
                $data['outputType'] : 'display';
            
            // Common output properties
            $cleaned_data['content'] = $data['content'] ?? '';
            $cleaned_data['displayOutput'] = $data['displayOutput'] ?? 'Output will be displayed here after execution';
            $cleaned_data['delayEnabled'] = $data['delayEnabled'] ?? false;
            $cleaned_data['delayValue'] = $this->clean_number($data['delayValue'] ?? 1, 1, 10000, 1);
            $cleaned_data['delayUnit'] = in_array($data['delayUnit'], ['minutes', 'hours', 'days']) ? 
                $data['delayUnit'] : 'minutes';

            // Type-specific properties
            switch ($cleaned_data['outputType']) {
                case 'html':
                    $cleaned_data['workflowId'] = $data['workflowId'] ?? null;
                    break;
                case 'save':
                    $cleaned_data['selectedTable'] = $data['selectedTable'] ?? '';
                    break;
                case 'webhook':
                    $cleaned_data['webhookUrl'] = $data['webhookUrl'] ?? '';
                    $cleaned_data['webhookKeys'] = $data['webhookKeys'] ?? [];
                    break;
                case 'googleSheets':
                    $cleaned_data['selectedSpreadsheet'] = $data['selectedSpreadsheet'] ?? '';
                    $cleaned_data['selectedSheetTab'] = $data['selectedSheetTab'] ?? '';
                    $cleaned_data['columnMappings'] = $data['columnMappings'] ?? [];
                    break;
                case 'googleDrive':
                    $cleaned_data['selectedDriveFolder'] = $data['selectedDriveFolder'] ?? '';
                    $cleaned_data['driveFileName'] = $data['driveFileName'] ?? '';
                    $cleaned_data['driveFileFormat'] = in_array($data['driveFileFormat'], ['txt', 'docx', 'pdf', 'csv']) ? 
                        $data['driveFileFormat'] : 'txt';
                    break;
                }
                break;

        case 'apiCall':
            $cleaned_data['method'] = in_array($data['method'], ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS']) ? 
                $data['method'] : 'GET';
            $cleaned_data['url'] = $data['url'] ?? '';
            
            // Clean headers
            $cleaned_data['headers'] = array_map(function($header) {
                return [
                    'name' => $header['name'] ?? '',
                    'value' => $header['value'] ?? ''
                ];
            }, $data['headers'] ?? []);

            // Clean query parameters
            $cleaned_data['queryParams'] = array_map(function($param) {
                return [
                    'key' => $param['key'] ?? '',
                    'value' => $param['value'] ?? ''
                ];
            }, $data['queryParams'] ?? []);

            // Clean body
            $cleaned_data['body'] = $data['body'] ?? null;

            // Clean auth settings
            $cleaned_data['auth'] = [
                'type' => in_array($data['auth']['type'] ?? 'none', ['none', 'basic', 'bearer', 'apiKey']) ? 
                    $data['auth']['type'] : 'none',
                'username' => $data['auth']['username'] ?? '',
                'password' => $data['auth']['password'] ?? '',
                'token' => $data['auth']['token'] ?? '',
                'apiKey' => $data['auth']['apiKey'] ?? '',
                'apiKeyName' => $data['auth']['apiKeyName'] ?? 'X-API-Key'
            ];

            // Clean response configuration
            $cleaned_data['responseConfig'] = [
                'timeout' => $this->clean_number($data['responseConfig']['timeout'] ?? 30000, 1000, 300000, 30000),
                'retryCount' => $this->clean_number($data['responseConfig']['retryCount'] ?? 0, 0, 5, 0),
                'jsonPath' => $data['responseConfig']['jsonPath'] ?? '',
                'cacheResponse' => $data['responseConfig']['cacheResponse'] ?? false,
                'cacheTime' => $this->clean_number($data['responseConfig']['cacheTime'] ?? 300, 60, 86400, 300)
            ];
            break;
            case 'chat':
                $cleaned_data['model'] = $this->validate_and_get_model($data['model'] ?? 'anthropic/claude-3-opus');
                $cleaned_data['systemPrompt'] = $data['systemPrompt'] ?? '';
                
                // Process actions if they exist
                $cleaned_data['actions'] = [];
                if (isset($data['actions']) && is_array($data['actions'])) {
                    foreach ($data['actions'] as $action) {
                        $cleaned_action = [
                            'id' => $action['id'] ?? ('action-' . substr(uniqid(), -8)),
                            'name' => $action['name'] ?? 'Action',
                            'description' => $action['description'] ?? '',
                            'fields' => []
                        ];
                        
                        // Process fields for each action
                        if (isset($action['fields']) && is_array($action['fields'])) {
                            foreach ($action['fields'] as $field) {
                                $cleaned_action['fields'][] = [
                                    'name' => $field['name'] ?? '',
                                    'type' => in_array($field['type'] ?? 'text', ['text', 'email', 'number', 'phone']) ? 
                                        $field['type'] : 'text',
                                    'required' => (bool)($field['required'] ?? false)
                                ];
                            }
                        }
                        
                        $cleaned_data['actions'][] = $cleaned_action;
                    }
                }
                
                // Clean modelParams
                $cleaned_data['modelParams'] = [
                    'temperature' => $this->clean_number($data['modelParams']['temperature'] ?? 1.0, 0, 2, 1.0),
                    'top_p' => $this->clean_number($data['modelParams']['top_p'] ?? 1.0, 0, 1, 1.0),
                    'top_k' => $this->clean_number($data['modelParams']['top_k'] ?? 0, 0, 100, 0), 
                    'frequency_penalty' => $this->clean_number($data['modelParams']['frequency_penalty'] ?? 0.0, -2, 2, 0.0),
                    'presence_penalty' => $this->clean_number($data['modelParams']['presence_penalty'] ?? 0.0, -2, 2, 0.0),
                    'repetition_penalty' => $this->clean_number($data['modelParams']['repetition_penalty'] ?? 1.0, 0, 2, 1.0),
                    'max_tokens' => $this->clean_number($data['modelParams']['max_tokens'] ?? 4096, 1, 32768, 4096)
                ];
                
                // Clean OpenAI Tools
                $cleaned_data['openaiTools'] = [
                    'webSearch' => [
                        'enabled' => (bool)($data['openaiTools']['webSearch']['enabled'] ?? false),
                        'contextSize' => in_array($data['openaiTools']['webSearch']['contextSize'] ?? 'medium', 
                            ['low', 'medium', 'high']) ? $data['openaiTools']['webSearch']['contextSize'] : 'medium',
                        'location' => [
                            'city' => $data['openaiTools']['webSearch']['location']['city'] ?? '',
                            'region' => $data['openaiTools']['webSearch']['location']['region'] ?? '',
                            'country' => $data['openaiTools']['webSearch']['location']['country'] ?? ''
                        ]
                    ],
                    'fileSearch' => [
                        'enabled' => (bool)($data['openaiTools']['fileSearch']['enabled'] ?? false),
                        'vectorStoreId' => $data['openaiTools']['fileSearch']['vectorStoreId'] ?? '',
                        'maxResults' => $this->clean_number($data['openaiTools']['fileSearch']['maxResults'] ?? 5, 1, 20, 5)
                    ]
                ];
                
                // Clean design properties
                $cleaned_data['design'] = [
                    'theme' => in_array($data['design']['theme'] ?? 'light', ['light', 'dark', 'custom']) ? 
                        $data['design']['theme'] : 'light',
                    'position' => in_array($data['design']['position'] ?? 'bottom-right', 
                        ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'inline']) ? 
                        $data['design']['position'] : 'bottom-right',
                    'dimensions' => [
                        'width' => $this->clean_number($data['design']['dimensions']['width'] ?? 380, 300, 800, 380),
                        'height' => $this->clean_number($data['design']['dimensions']['height'] ?? 600, 400, 800, 600),
                        'borderRadius' => $this->clean_number($data['design']['dimensions']['borderRadius'] ?? 12, 0, 24, 12)
                    ],
                    'colors' => [
                        'primary' => $data['design']['colors']['primary'] ?? '#1677ff',
                        'secondary' => $data['design']['colors']['secondary'] ?? '#f5f5f5',
                        'text' => $data['design']['colors']['text'] ?? '#000000',
                        'background' => $data['design']['colors']['background'] ?? '#ffffff'
                    ],
                    'font' => [
                        'family' => $data['design']['font']['family'] ?? 'Inter, system-ui, sans-serif',
                        'size' => $data['design']['font']['size'] ?? '14px',
                        'headerSize' => $data['design']['font']['headerSize'] ?? '16px'
                    ],
                    'botName' => $data['design']['botName'] ?? 'AI Assistant',
                    'botIcon' => in_array($data['design']['botIcon'] ?? 'robot', ['robot', 'assistant', 'brain', 'chat']) ? 
                        $data['design']['botIcon'] : 'robot',
                    'sendButtonText' => $data['design']['sendButtonText'] ?? 'Send',
                    'showPoweredBy' => $data['design']['showPoweredBy'] !== false,
                    'customCSS' => $data['design']['customCSS'] ?? '',
                    // Add quick responses handling
                    'quickResponses' => is_array($data['design']['quickResponses'] ?? null) ? 
                        array_map(function($qr) {
                            return [
                                'text' => $qr['text'] ?? 'Quick response',
                                'message' => $qr['message'] ?? 'Quick response message'
                            ];
                        }, $data['design']['quickResponses']) : []
                ];
                
                // Clean behavior properties
                $cleaned_data['behavior'] = [
                    'initialMessageType' => in_array($data['behavior']['initialMessageType'] ?? 'static', ['static', 'dynamic']) ?
                        $data['behavior']['initialMessageType'] : 'static',
                    'initialMessage' => $data['behavior']['initialMessage'] ?? 'Hello! How can I help you today?',
                    'placeholderText' => $data['behavior']['placeholderText'] ?? 'Type your message here...',
                    'maxHistoryLength' => $this->clean_number($data['behavior']['maxHistoryLength'] ?? 50, 10, 100, 50),
                    'showTypingIndicator' => $data['behavior']['showTypingIndicator'] !== false,
                    'soundEffects' => $data['behavior']['soundEffects'] !== false,
                    'showCitations' => (bool)($data['behavior']['showCitations'] ?? false),
                    'autoOpenDelay' => $this->clean_number($data['behavior']['autoOpenDelay'] ?? 0, 0, 60, 0),
                    'persistHistory' => $data['behavior']['persistHistory'] !== false,
                    'includePageContext' => (bool)($data['behavior']['includePageContext'] ?? false),
                    'streamResponses' => (bool)($data['behavior']['streamResponses'] ?? false),
                    'rateLimit' => [
                        'enabled' => $data['behavior']['rateLimit']['enabled'] ?? true,
                        'maxMessages' => $this->clean_number($data['behavior']['rateLimit']['maxMessages'] ?? 10, 1, 100, 10),
                        'timeWindow' => $this->clean_number($data['behavior']['rateLimit']['timeWindow'] ?? 60, 10, 3600, 60)
                    ]
                ];
                
                // Make sure streaming is disabled if actions are present
                if (!empty($cleaned_data['actions']) && $cleaned_data['behavior']['streamResponses']) {
                    $cleaned_data['behavior']['streamResponses'] = false;
                }
            break;
        }
    
        return $cleaned_data;
    }

    private function validate_workflow_structure($workflow) {
        // Get output type nodes
        $output_node_types = [
            'output',      // Standard output
            'post',        // WordPress post
            'sendEmail',   // Email
            'humanInput',  // Human approval/input can be an endpoint
            'condition',   // Condition nodes can have outputs
            'firecrawl',   // Web scraping output
            'unsplash',    // Image outputs
            'research',    // Research outputs
            'shortcode',   // Shortcode output 
            'display',     // Display output 
            'webhook', 
            'APICall',
            'googleSheets', 
            'googleDrive',
            'chat',
            'save',
            'mediaGenerator', // Added multimedia generator
            'createFile'    // Added file creation
        ];

        $trigger_node_types = [
            'trigger',
            'chat',
            'aiModel',
            'parser',
            'firecrawl'
        ];
    
        $allowed_disconnected_types = ['shape', 'textAnnotation', 'stickyNote'];
    
        // Check for required trigger node (This should always be first)
        $has_trigger = false;
        foreach ($workflow['nodes'] as $node) {
            if (in_array($node['type'], $trigger_node_types)) {
                $has_trigger = true;
                break;
            }
        }
    
        if (!$has_trigger) {
            WP_AI_Workflows_Utilities::debug_log('No trigger node found', 'error');
            return false;
        }
    
        // Build node connection map
        $node_connections = [];
        foreach ($workflow['edges'] as $edge) {
            if (!isset($node_connections[$edge['source']])) {
                $node_connections[$edge['source']] = [];
            }
            if (!isset($node_connections[$edge['target']])) {
                $node_connections[$edge['target']] = [];
            }
            $node_connections[$edge['source']][] = $edge['target'];
        }
    
        // Find terminal nodes (nodes with no outgoing connections)
        $terminal_nodes = [];
        foreach ($workflow['nodes'] as $node) {
            $node_id = $node['id'];
            if (!isset($node_connections[$node_id]) || empty($node_connections[$node_id])) {
                $terminal_nodes[] = $node;
            }
        }
    
        // Validate terminal nodes
        $valid_terminal_found = false;
        foreach ($terminal_nodes as $node) {
            if (in_array($node['type'], $output_node_types)) {
                $valid_terminal_found = true;
                break;
            }
        }
    
        if (!$valid_terminal_found && !empty($terminal_nodes)) {
            WP_AI_Workflows_Utilities::debug_log('No valid output found in terminal nodes', 'error', [
                'terminal_nodes' => array_map(function($node) {
                    return ['id' => $node['id'], 'type' => $node['type']];
                }, $terminal_nodes)
            ]);
            return false;
        }
    
        // Check for disconnected nodes
        $connected_nodes = [];
        foreach ($workflow['edges'] as $edge) {
            $connected_nodes[] = $edge['source'];
            $connected_nodes[] = $edge['target'];
        }
        $connected_nodes = array_unique($connected_nodes);
    
        foreach ($workflow['nodes'] as $node) {
            if (!in_array($node['id'], $connected_nodes) && !in_array($node['type'], $allowed_disconnected_types)) {
                WP_AI_Workflows_Utilities::debug_log('Disconnected node found', 'error', [
                    'node_id' => $node['id'],
                    'node_type' => $node['type']
                ]);
                return false;
            }
        }
    
        // Optional: Validate flow direction (no cycles)
        if (!$this->validate_workflow_flow($workflow['nodes'], $node_connections)) {
            WP_AI_Workflows_Utilities::debug_log('Invalid workflow flow detected', 'error');
            return false;
        }
    
        return true;
    }
    
    
    private function validate_workflow_flow($nodes, $connections) {
        // Helper function to detect cycles and validate flow direction
        $visited = [];
        $recursion_stack = [];
    
        foreach ($nodes as $node) {
            if (!isset($visited[$node['id']])) {
                if ($this->has_cycle($node['id'], $visited, $recursion_stack, $connections)) {
                    return false;
                }
            }
        }
    
        return true;
    }
    
    private function has_cycle($node_id, &$visited, &$recursion_stack, $connections) {
        $visited[$node_id] = true;
        $recursion_stack[$node_id] = true;
    
        if (isset($connections[$node_id])) {
            foreach ($connections[$node_id] as $adjacent) {
                if (!isset($visited[$adjacent])) {
                    if ($this->has_cycle($adjacent, $visited, $recursion_stack, $connections)) {
                        return true;
                    }
                } else if (isset($recursion_stack[$adjacent]) && $recursion_stack[$adjacent]) {
                    return true;
                }
            }
        }
    
        $recursion_stack[$node_id] = false;
        return false;
    }

    private function fix_missing_connections($workflow) {
        $nodes = $workflow['nodes'];
        $edges = $workflow['edges'];
        $new_edges = [];
    
        // Create a map of existing connections to avoid duplicates
        $existing_connections = [];
        foreach ($edges as $edge) {
            $connection_key = $edge['source'] . '->' . $edge['target'];
            if (isset($edge['sourceHandle'])) {
                $connection_key .= '->' . $edge['sourceHandle'];
            }
            $existing_connections[$connection_key] = true;
        }
    
        foreach ($nodes as $target_node) {
            $input_references = $this->find_input_references($target_node['data']);
            
            foreach ($input_references as $source_node_id) {
                // Find the source node to determine its type
                $source_node = null;
                foreach ($nodes as $node) {
                    if ($node['id'] === $source_node_id) {
                        $source_node = $node;
                        break;
                    }
                }
    
                if ($source_node) {
                    // Determine if we need a sourceHandle based on node type
                    $handles = $this->get_possible_handles($source_node['type']);
                    
                    if (empty($handles)) {
                        // Regular node with single output
                        $connection_key = $source_node_id . '->' . $target_node['id'];
                        if (!isset($existing_connections[$connection_key])) {
                            $new_edges[] = [
                                'id' => 'e' . substr(uniqid(), -6),
                                'source' => $source_node_id,
                                'target' => $target_node['id']
                            ];
                            $existing_connections[$connection_key] = true;
                        }
                    } else {
                        // Node with multiple outputs (like condition or humanInput)
                        foreach ($handles as $handle) {
                            $connection_key = $source_node_id . '->' . $target_node['id'] . '->' . $handle;
                            if (!isset($existing_connections[$connection_key])) {
                                $new_edges[] = [
                                    'id' => 'e' . substr(uniqid(), -6),
                                    'source' => $source_node_id,
                                    'target' => $target_node['id'],
                                    'sourceHandle' => $handle
                                ];
                                $existing_connections[$connection_key] = true;
                            }
                        }
                    }
                }
            }
        }
    
        if (!empty($new_edges)) {
            WP_AI_Workflows_Utilities::debug_log('Added missing connections', 'info', [
                'new_edges' => $new_edges
            ]);
        }
    
        $workflow['edges'] = array_merge($edges, $new_edges);
        return $workflow;
    }
    
    private function get_possible_handles($node_type) {
        switch ($node_type) {
            case 'condition':
                return ['true', 'false'];
            case 'humanInput':
                return ['approve', 'revert', 'modify'];
            default:
                return [];
        }
    }
    
    private function find_input_references($data) {
        $references = [];
        
        // Function to extract node IDs from input tags
        $extract_node_ids = function($text) {
            $matches = [];
            $found_refs = [];
    
            // Match pattern: [Input from node-id]
            if (preg_match_all('/\[Input from ([\w-]+)\]/', $text, $matches)) {
                $found_refs = array_merge($found_refs, $matches[1]);
            }
    
            // Match pattern: [[Field Name] from node-id]
            if (preg_match_all('/\[\[([^\]]+)\] from ([\w-]+)\]/', $text, $matches)) {
                $found_refs = array_merge($found_refs, $matches[2]);
            }
    
            return $found_refs;
        };
    
        // Recursively search through data for input references
        $search_references = function($value) use (&$search_references, $extract_node_ids, &$references) {
            if (is_string($value)) {
                $refs = $extract_node_ids($value);
                $references = array_merge($references, $refs);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $search_references($item);
                }
            }
        };
    
        // Special handling for different node types and their fields
        $searchable_fields = [
            'content',
            'body',    // For email nodes
            'subject', // For email nodes
            'fileName', // For createFile nodes
            'fileContent', // For createFile nodes
            'imageUrl', // For multimediaGenerator nodes
            'prompt',  // For various AI nodes
            'url',     // For various web-related nodes
        ];
    
        foreach ($searchable_fields as $field) {
            if (isset($data[$field])) {
                $search_references($data[$field]);
            }
        }
    
        // Handle field mappings (for post nodes)
        if (isset($data['fieldMappings']) && is_array($data['fieldMappings'])) {
            foreach ($data['fieldMappings'] as $mapping) {
                $search_references($mapping);
            }
        }
    
        // Handle webhook keys
        if (isset($data['webhookKeys']) && is_array($data['webhookKeys'])) {
            foreach ($data['webhookKeys'] as $key) {
                if (isset($key['mapping'])) {
                    $search_references($key['mapping']);
                }
            }
        }
    
        // Handle condition inputs
        if (isset($data['conditionGroups']) && is_array($data['conditionGroups'])) {
            foreach ($data['conditionGroups'] as $group) {
                if (isset($group['conditions']) && is_array($group['conditions'])) {
                    foreach ($group['conditions'] as $condition) {
                        if (isset($condition['input'])) {
                            $search_references($condition['input']);
                        }
                    }
                }
            }
        }
    
        // Debug log the found references
        WP_AI_Workflows_Utilities::debug_log('Found input references', 'debug', [
            'node_data' => $data,
            'references' => array_unique($references)
        ]);
    
        return array_unique($references);
    }

    private function validate_and_get_model($model) {
        $valid_models = [
            // OpenAI models
            'gpt-4o',
            'gpt-4o-mini',
            'o1-preview',
            'o1-mini',
            'o3-mini',
            'o3-mini-high',
            'openai/chatgpt-4o-latest',
            'openai/gpt-4o-mini',
            'openai/o1',
            'openai/o1-mini',
            'openai/o3-mini',
            'openai/o3-mini-high',
            'openai/gpt-4o-search-preview',
            'openai/gpt-4o-mini-search-preview',
            
            // Anthropic models
            'anthropic/claude-3.7-sonnet',
            'anthropic/claude-3.5-sonnet',
            'anthropic/claude-3-5-haiku',
            'anthropic/claude-3-opus',
            'anthropic/claude-3-haiku',
            
            // Meta models
            'meta-llama/llama-3.2-11b-vision-instruct:free',
            'meta-llama/llama-3.2-11b-vision-instruct',
            'meta-llama/llama-3.2-3b-instruct',
            'meta-llama/llama-3.2-1b-instruct',
            'meta-llama/llama-3.2-90b-vision-instruct',
            'meta-llama/llama-3.3-70b-instruct',
            
            // Perplexity models
            'perplexity/sonar',
            'perplexity/sonar-reasoning',
            'perplexity/llama-3.1-sonar-huge-128k-online',
            'perplexity/llama-3.1-sonar-large-128k-online',
            'perplexity/llama-3.1-sonar-small-128k-online',
            'sonar',
            'sonar-pro',
            'sonar-reasoning',
            'sonar-reasoning-pro',
            'sonar-deep-research',
            
            // Mistral models
            'mistralai/pixtral-12b',
            'mistralai/mistral-nemo',
            'mistralai/pixtral-large-2411',
            'mistralai/mistral-large-2411',
            'mistralai/mistral-small-3.1-24b-instruct',
            
            // Google models
            'google/gemma-3-12b-it:free',
            'google/gemma-3-4b-it:free',
            'google/gemini-flash-8b-1.5-exp',
            'google/gemini-flash-1.5-8b',
            'google/gemini-2.0-flash-exp',
            'google/gemma-2-9b-it:free',
            
            // xAI models
            'x-ai/grok-2-1212',
            'x-ai/grok-2-vision-1212',
            
            // DeepSeek models
            'deepseek/deepseek-r1',
            'deepseek/deepseek-chat'
        ];

        return in_array($model, $valid_models) ? $model : 'gpt-4o-mini';
    }

    private function prepare_workflow_for_frontend($workflow) {
        // Add required functions to each node
        foreach ($workflow['nodes'] as &$node) {
            $node['data']['updateNodeData'] = true; // Will be replaced with actual function in frontend
            $node['data']['onDelete'] = true; // Will be replaced with actual function in frontend
        }
        return $workflow;
    }

    public function clear_prompt_cache() {
        $this->system_prompt = null;
        wp_cache_delete('wp_ai_workflows_system_prompt');
    }

    // Method to refresh the system prompt from template if needed
    public function refresh_prompt() {
        $this->clear_prompt_cache();
        if (file_exists($this->prompt_path)) {
            unlink($this->prompt_path);
        }
        $this->ensure_system_prompt();
    }
}