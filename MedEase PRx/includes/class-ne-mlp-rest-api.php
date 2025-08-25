<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NE Med Lab Prescriptions REST API
 *
 * Authentication replaced with a Global API Key + User ID header scheme.
 * All protected endpoints require the following request headers:
 * - X-API-KEY: Global API key stored in WP option `_ne_global_api_key`
 * - X-USER-ID: A valid WordPress user ID
 *
 * On success, the current user is set to the provided ID.
 */
class NE_MLP_REST_API {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Ensure file uploads work in REST API
        add_action('rest_api_init', [$this, 'enable_rest_file_uploads']);
    }
    
    /**
     * Enable file uploads for REST API endpoints
     */
    public function enable_rest_file_uploads() {
        // Remove any filters that might block file uploads
        remove_filter('rest_pre_serve_request', '_rest_send_nocache_headers', 10);
        
        // Ensure $_FILES is populated for multipart requests
        if (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
            // WordPress should handle this automatically, but let's make sure
            if (empty($_FILES) && !empty($_POST)) {
                // Force PHP to parse multipart data if it hasn't already
                if (function_exists('apache_request_headers')) {
                    $headers = apache_request_headers();
                    if (isset($headers['Content-Type']) && strpos($headers['Content-Type'], 'multipart') !== false) {
                        // Multipart data should be in $_FILES already
                    }
                }
            }
        }
    }

    /**
     * Registers all API routes for the plugin.
     */
    public function register_routes() {
        if (!get_option('ne_mlp_api_enabled', 1)) {
            return;
        }

        // --- Protected Endpoints ---
        $protected_routes = [
            '/prescriptions'                => ['methods' => 'GET', 'callback' => 'get_prescriptions'],
            '/prescriptions/approved'       => ['methods' => 'GET', 'callback' => 'get_approved_prescriptions'],
            '/prescriptions/upload'         => ['methods' => 'POST', 'callback' => 'upload_prescription'],
            '/prescriptions/reupload'       => ['methods' => 'POST', 'callback' => 'reupload_prescription'],
            '/orders/attach-prescription'   => ['methods' => 'POST', 'callback' => 'attach_prescription_to_order'],
            '/order-request'                => ['methods' => 'POST', 'callback' => 'create_order_request'],
            '/order-requests'               => ['methods' => 'GET', 'callback' => 'get_order_requests'],
        ];

        foreach ($protected_routes as $route => $config) {
            register_rest_route('ne-mlp/v1', $route, [
                'methods' => $config['methods'],
                'callback' => [$this, $config['callback']],
                'permission_callback' => [$this, 'permission_check'],
            ]);
        }
        
        // --- Protected DELETE Endpoint with Parameter ---
        register_rest_route('ne-mlp/v1', '/prescriptions/(?P<id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_prescription'],
            'permission_callback' => [$this, 'permission_check'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ],
            ],
        ]);
    }

    /**
     * Permission check for all protected endpoints using Global API Key + User ID headers.
     */
    public function permission_check($request) {
        $provided_key = $request->get_header('x-api-key');
        $provided_user_id = $request->get_header('x-user-id');

        if (!$provided_key || !$provided_user_id) {
            return new WP_Error(
                'ne_mlp_auth_missing_headers',
                'Missing authentication headers. Required: X-API-KEY and X-USER-ID.',
                ['status' => 401]
            );
        }

        $stored_key = get_option('_ne_global_api_key');
        if (!$stored_key) {
            return new WP_Error(
                'ne_mlp_api_key_not_set',
                'API key not configured on the server.',
                ['status' => 403]
            );
        }

        if (!hash_equals($stored_key, (string) $provided_key)) {
            return new WP_Error(
                'ne_mlp_invalid_api_key',
                'Invalid API Key.',
                ['status' => 403]
            );
        }

        $user_id = intval($provided_user_id);
        $user = $user_id > 0 ? get_user_by('ID', $user_id) : false;
        if (!$user) {
            return new WP_Error(
                'ne_mlp_invalid_user',
                'The provided X-USER-ID does not correspond to an existing user.',
                ['status' => 401]
            );
        }

        wp_set_current_user($user_id);
        return true;
    }

    /**
     * API Callback: Get prescriptions for the current user.
     */
    public function get_prescriptions($request) {
        $user_id = get_current_user_id();
        $page = max(1, intval($request->get_param('page')));
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d", $user_id));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", $user_id, $per_page, $offset));
        
        foreach ($rows as &$row) {
            $row->file_paths = json_decode($row->file_paths);
        }
        
        return new WP_REST_Response([
            'prescriptions' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total),
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * API Callback: Get approved prescriptions for the current user.
     */
    public function get_approved_prescriptions($request) {
        $user_id = get_current_user_id();
        $page = max(1, intval($request->get_param('page')));
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d AND status = 'approved'", $user_id));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND status = 'approved' ORDER BY created_at DESC LIMIT %d OFFSET %d", $user_id, $per_page, $offset));
        
        foreach ($rows as &$row) {
            $row->file_paths = json_decode($row->file_paths);
        }
        
        return new WP_REST_Response([
            'prescriptions' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total),
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * API Callback: Upload a new prescription.
     */
    public function upload_prescription($request) {
        $user_id = get_current_user_id();
        
        // Include WordPress file handling functions
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        // WordPress REST API file upload handling
        // Check if files were uploaded via standard multipart
        if (empty($_FILES)) {
            // Try to get files from the request object
            $files = $request->get_file_params();
            if (empty($files)) {
                return new WP_REST_Response([
                    'error' => 'No files uploaded',
                    'debug' => [
                        'files_empty' => empty($_FILES),
                        'request_files_empty' => empty($request->get_file_params()),
                        'content_type' => $request->get_header('content-type'),
                        'server_files' => $_FILES,
                        'post_data' => $_POST
                    ]
                ], 400);
            }
            
            // Convert request files to $_FILES format if needed
            if (!empty($files['files'])) {
                $_FILES['files'] = $files['files'];
            } else {
                // Take the first file found
                foreach ($files as $key => $file) {
                    $_FILES['files'] = $file;
                    break;
                }
            }
        }
        
        // Now process the files from $_FILES
        $upload_files = null;
        if (!empty($_FILES['files'])) {
            $upload_files = $_FILES['files'];
        } else {
            // Look for any file field
            foreach ($_FILES as $field_name => $file_data) {
                if (!empty($file_data['name'])) {
                    $upload_files = $file_data;
                    break;
                }
            }
        }
        
        if (!$upload_files) {
            return new WP_REST_Response([
                'error' => 'No valid files found',
                'available_files' => array_keys($_FILES)
            ], 400);
        }
        
        // Get prescription type (default to 'medicine')
        $type = sanitize_text_field($request->get_param('type')) ?: 'medicine';
        
        // Use the prescription manager's upload method
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $result = $prescription_manager->upload_prescription_files($upload_files, $user_id, $type, 'app');
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 400);
        }

        // Trigger notifications
        $this->trigger_upload_notification_api($result, $user_id);
        
        return new WP_REST_Response(['success' => true, 'id' => $result], 200);
    }
        
    /**
     * API Callback: Re-upload a rejected prescription.
     */
    public function reupload_prescription($request) {
        $user_id = get_current_user_id();
        
        // Handle file uploads same as upload method
        if (empty($_FILES)) {
            $files = $request->get_file_params();
            if (empty($files)) {
                return new WP_REST_Response(['error' => 'No files uploaded'], 400);
            }
            
            if (!empty($files['files'])) {
                $_FILES['files'] = $files['files'];
            } else {
                foreach ($files as $key => $file) {
                    $_FILES['files'] = $file;
                    break;
                }
            }
        }
        
        $upload_files = null;
        if (!empty($_FILES['files'])) {
            $upload_files = $_FILES['files'];
        } else {
            foreach ($_FILES as $field_name => $file_data) {
                if (!empty($file_data['name'])) {
                    $upload_files = $file_data;
                    break;
                }
            }
        }
        
        if (!$upload_files) {
            return new WP_REST_Response(['error' => 'No files uploaded'], 400);
        }
        
        // Get prescription ID
        $prescription_id = intval($request->get_param('prescription_id'));
        if (!$prescription_id) {
            return new WP_REST_Response(['error' => 'Prescription ID is required'], 400);
        }
        
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $result = $prescription_manager->reupload_prescription_files($prescription_id, $upload_files, $user_id);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 400);
        }

        // Trigger notifications
        $this->trigger_upload_notification_api($prescription_id, $user_id);
        
        return new WP_REST_Response(['success' => true, 'id' => $prescription_id], 200);
    }

    /**
     * API Callback: Attach a prescription to an order.
     */
    public function attach_prescription_to_order($request) {
        $user_id = get_current_user_id();
        $order_id = intval($request->get_param('order_id'));
        $presc_id = intval($request->get_param('prescription_id'));
        
        if (!$order_id || !$presc_id) {
            return new WP_REST_Response(['error' => 'Order ID and Prescription ID are required'], 400);
        }
        
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $result = $prescription_manager->attach_prescription_to_order($order_id, $presc_id);

        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 400);
        }

        return new WP_REST_Response(['success' => true, 'message' => 'Prescription attached successfully.'], 200);
    }

    /**
     * API Callback: Delete a prescription.
     */
    public function delete_prescription($request) {
        $user_id = get_current_user_id();
        $presc_id = (int) $request['id'];
        
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $result = $prescription_manager->delete_prescription($presc_id, $user_id);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 400);
        }
        
        return new WP_REST_Response(['success' => true, 'message' => 'Prescription deleted successfully.'], 200);
    }

    /**
     * Trigger email/push notification when a prescription is uploaded via API.
     */
    private function trigger_upload_notification_api($prescription_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $prescription = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $prescription_id));
        $user = get_userdata($user_id);

        if ($prescription && $user && class_exists('NE_MLP_Admin_Panel')) {
            $admin_panel = new NE_MLP_Admin_Panel();
            $admin_panel->trigger_upload_notification($user, $prescription);
        }
    }

    /**
     * Attempts to authenticate the request using WordPress cookies + REST nonce (browser session).
     * Returns the user ID on success or false on failure.
     */
    private function verify_nonce_session($request) {
        if (!is_user_logged_in()) {
            return false;
        }

        // REST nonce can be supplied via header `X-WP-Nonce` or `_wpnonce` param.
        $nonce = $request->get_header('x_wp_nonce');
        if (!$nonce) {
            $nonce = $request->get_param('_wpnonce');
            }

        if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
            return get_current_user_id();
        }

        return false;
            }

    /**
     * API Callback: Create order request
     */
    public function create_order_request($request) {
        if (class_exists('NE_MLP_Order_Request')) {
            $order_request = NE_MLP_Order_Request::getInstance();
            return $order_request->api_create_request($request);
        }
        
        return new WP_REST_Response(['error' => 'Order request system not available'], 500);
    }
    
    /**
     * API Callback: Get user's order requests
     */
    public function get_order_requests($request) {
        if (class_exists('NE_MLP_Order_Request')) {
            $order_request = NE_MLP_Order_Request::getInstance();
            return $order_request->api_get_requests($request);
        }
        
        return new WP_REST_Response(['error' => 'Order request system not available'], 500);
    }

    // Back-compat: keep stub for any legacy code referencing JWT plugins
    public function get_detected_jwt_plugins() { return []; }
}

// Initialize the class
new NE_MLP_REST_API(); 