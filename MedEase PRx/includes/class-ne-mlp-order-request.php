<?php
if (!defined('ABSPATH')) exit;

/**
 * Order Request System for NE Med Lab Prescriptions
 * Handles prescription-based order requests with admin approval workflow
 */
class NE_MLP_Order_Request {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Create order requests table on activation
        add_action('init', [$this, 'maybe_create_table']);
        
        // Admin hooks
        if (is_admin()) {
            add_action('wp_ajax_ne_mlp_approve_order_request', [$this, 'ajax_approve_request']);
            add_action('wp_ajax_ne_mlp_reject_order_request', [$this, 'ajax_reject_request']);
            add_action('wp_ajax_ne_mlp_delete_order_request', [$this, 'ajax_delete_request']);
            add_action('wp_ajax_ne_mlp_get_pending_count', [$this, 'ajax_get_pending_count']);
            
            // Add admin footer script to update badge on page load
            add_action('admin_footer', [$this, 'add_badge_update_script']);
        }
        
        // Frontend AJAX hooks
        add_action('wp_ajax_ne_mlp_submit_order_request', [$this, 'handle_order_request_submission']);
        add_action('wp_ajax_nopriv_ne_mlp_submit_order_request', [$this, 'handle_order_request_submission']);

        // Fetch current user's requests (logged-in only)
        add_action('wp_ajax_ne_mlp_get_user_order_requests', [$this, 'ajax_get_user_order_requests']);
    }

    /**
     * AJAX: Get current user's order requests with pagination
     */
    public function ajax_get_user_order_requests() {
        // Only for logged in users
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in to view order requests.', 'ne-med-lab-prescriptions'));
        }

        // Nonce check (optional but recommended if provided)
        if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'ne_mlp_frontend')) {
            wp_send_json_error(__('Security check failed.', 'ne-med-lab-prescriptions'));
        }

        $user_id  = get_current_user_id();
        $page     = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 10;

        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';

        // Count total for this user
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));

        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ));

        // Format data for frontend
        $data = [];
        if ($rows) {
            foreach ($rows as $row) {
                // Handle both prescription_id and prescription_ids
                $prescription_id = null;
                $prescription_ids = [];
                
                // Get from prescription_id column if available
                if (!empty($row->prescription_id)) {
                    $prescription_id = (int) $row->prescription_id;
                    $prescription_ids[] = $prescription_id;
                }
                
                // Also check prescription_ids JSON if available
                if (!empty($row->prescription_ids)) {
                    $ids = json_decode($row->prescription_ids, true);
                    if (is_array($ids) && !empty($ids)) {
                        $prescription_ids = array_unique(array_merge($prescription_ids, array_map('intval', $ids)));
                        // If we still don't have a prescription_id, use the first one from the array
                        if (empty($prescription_id) && !empty($prescription_ids)) {
                            $prescription_id = $prescription_ids[0];
                        }
                    }
                }
                
                $data[] = [
                    'id' => (int) $row->id,
                    'formatted_id' => $this->format_order_request_id($row),
                    'status' => (string) $row->status,
                    'days' => (int) $row->days,
                    'created_at' => (string) $row->created_at,
                    'notes' => (string) ($row->notes ?? ''),
                    'order_id' => $row->order_id ? (int) $row->order_id : null,
                    'prescription_id' => $prescription_id,
                    'prescription_ids' => $prescription_ids,
                ];
            }
        }

        wp_send_json_success([
            'requests' => $data,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }
    
    /**
     * Handle order request submission from frontend
     */
    public function handle_order_request_submission() {
        // Verify nonce
        if (!isset($_POST['request_order_nonce']) || !wp_verify_nonce($_POST['request_order_nonce'], 'ne_mlp_request_order_nonce')) {
            wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'ne-med-lab-prescriptions'));
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in to submit an order request.', 'ne-med-lab-prescriptions'));
        }
        
        // Get and validate input
        $prescription_id = isset($_POST['prescription_id']) ? intval($_POST['prescription_id']) : 0;
        $days = isset($_POST['days']) ? intval($_POST['days']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $user_id = get_current_user_id();
        
        // Validate required fields
        if (!$prescription_id || $days <= 0) {
            wp_send_json_error(__('Please fill in all required fields.', 'ne-med-lab-prescriptions'));
        }
        
        // Delegate creation to centralized manager
        if (!class_exists('NE_MLP_Order_Manager')) {
            wp_send_json_error(__('Order manager not available.', 'ne-med-lab-prescriptions'));
        }
        
        $manager = NE_MLP_Order_Manager::getInstance();
        $result = $manager->create_request([
            'user_id' => $user_id,
            'prescription_ids' => [$prescription_id],
            'days' => $days,
            'notes' => $notes,
            'verify_ownership' => true,
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success([
            'message' => __('Your order request has been submitted successfully!', 'ne-med-lab-prescriptions'),
            'request_id' => $result['request_id'],
            'formatted_id' => $result['formatted_id'],
        ]);
    }
    
    /**
     * Generate formatted order request ID
     * Format: PRxO<YYYYMMDD><increment> (e.g., PRxO202508231)
     */
    public function format_order_request_id($request) {
        $date = date('Ymd', strtotime($request->created_at));
        return 'PRxO' . $date . $request->id;
    }
    
    /**
     * Get requests with pagination
     * 
     * @param int $page Page number
     * @param int $per_page Items per page
     * @param string $status Filter by status
     * @param string $date_from Filter by date from (YYYY-MM-DD)
     * @param string $date_to Filter by date to (YYYY-MM-DD)
     * @param string $search Search term
     * @return array Paginated requests data
     */
    public function get_requests_with_pagination($page = 1, $per_page = 20, $status = '', $date_from = '', $date_to = '', $search = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        
        // Build WHERE clause
        $where_conditions = ['1=1'];
        $params = [];
        
        if (!empty($status)) {
            $where_conditions[] = 'status = %s';
            $params[] = $status;
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = 'DATE(created_at) >= %s';
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = 'DATE(created_at) <= %s';
            $params[] = $date_to;
        }
        
        if (!empty($search)) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            
            // Build comprehensive search conditions
            $search_conditions = [];
            $search_params = [];
            
            // Search in notes
            $search_conditions[] = "notes LIKE %s";
            $search_params[] = $search_term;
            
            // Search by raw ID
            if (is_numeric($search)) {
                $search_conditions[] = "id = %d";
                $search_params[] = intval($search);
            }
            
            // Handle formatted ID search (PRxO202508233 format)
            if (preg_match('/PRxO(\d{8})(\d+)/', $search, $matches)) {
                $extracted_id = intval($matches[2]);
                $search_conditions[] = "id = %d";
                $search_params[] = $extracted_id;
            }
            
            // Search by formatted ID pattern
            $search_conditions[] = "CONCAT('PRxO', DATE_FORMAT(created_at, '%%Y%%m%%d'), id) LIKE %s";
            $search_params[] = $search_term;
            
            // Search by user email and display name
            $search_conditions[] = "user_id IN (SELECT ID FROM " . $wpdb->users . " WHERE user_email LIKE %s OR display_name LIKE %s OR user_login LIKE %s)";
            $search_params[] = $search_term;
            $search_params[] = $search_term;
            $search_params[] = $search_term;
            
            // Combine all search conditions with OR
            $where_conditions[] = "(" . implode(' OR ', $search_conditions) . ")";
            $params = array_merge($params, $search_params);
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM $table $where_clause";
        $total = $wpdb->get_var($params ? $wpdb->prepare($count_query, $params) : $count_query);
        $total = intval($total); // Ensure it's an integer
        
        // Get paginated results
        $offset = ($page - 1) * $per_page;
        $query = "SELECT * FROM $table $where_clause ORDER BY id DESC LIMIT %d OFFSET %d";
        $final_params = array_merge($params, [$per_page, $offset]);
        $requests = $wpdb->get_results($wpdb->prepare($query, $final_params));
        
        return [
            'requests' => $requests,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
            'current_page' => $page,
            'per_page' => $per_page
        ];
    }
    
    /**
     * Render request card in grid style
     * 
     * @param object $request The request object to render
     * @return void
     */
    public function render_request_card_grid($request) {
        static $modal_added = false;
        $user = get_userdata($request->user_id);
        $request_id = $this->format_order_request_id($request);

        // Build detailed user display: First Last (@username) <email>
        $user_line = 'Unknown User';
        if ($user) {
            $first_name = trim(get_user_meta($user->ID, 'first_name', true));
            $last_name  = trim(get_user_meta($user->ID, 'last_name', true));
            $full_name  = trim(trim($first_name . ' ' . $last_name));
            if (empty($full_name)) {
                $full_name = $user->display_name;
            }
            $user_line = sprintf('%s (@%s) <%s>', $full_name, $user->user_login, $user->user_email);
        }

        // Localize created_at to site timezone and include time
        $created_local = function_exists('get_date_from_gmt') ? get_date_from_gmt($request->created_at, 'Y-m-d H:i:s') : $request->created_at;
        $created_label = function_exists('date_i18n') ? date_i18n('M j, Y g:i a', strtotime($created_local)) : date('M j, Y g:i a', strtotime($created_local));
        
        // Get prescription details
        $prescription_ids = json_decode($request->prescription_ids, true);
        $prescriptions = [];
        
        if (is_array($prescription_ids)) {
            global $wpdb;
            $presc_table = $wpdb->prefix . 'prescriptions';
            foreach ($prescription_ids as $presc_id) {
                $presc = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $presc_table WHERE id = %d",
                    intval($presc_id)
                ));
                if ($presc) {
                    $prescriptions[] = $presc;
                }
            }
        }
        
        // Status styling
        $status_colors = [
            'pending' => ['bg' => '#fff7e6', 'color' => '#d46b08', 'border' => '#faad14'],
            'approved' => ['bg' => '#f6ffed', 'color' => '#389e0d', 'border' => '#52c41a'],
            'rejected' => ['bg' => '#fff1f0', 'color' => '#cf1322', 'border' => '#ffa39e']
        ];
        $status_style = $status_colors[$request->status] ?? $status_colors['pending'];
        
        ?>
        <div class="ne-mlp-request-card" style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);overflow:hidden;transition:transform 0.2s ease,box-shadow 0.2s ease;">
            <!-- Header with status -->
            <div style="background:<?php echo $status_style['bg']; ?>;padding:16px;border-bottom:1px solid #eee;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 style="margin:0;font-size:16px;font-weight:600;color:#333;">
                            <?php echo esc_html($request_id); ?>
                        </h3>
                        <p style="margin:4px 0 0 0;font-size:13px;color:#666;">
                            <?php echo esc_html($user_line); ?>
                        </p>
                    </div>
                    <span style="background:<?php echo $status_style['color']; ?>;color:#fff;padding:4px 8px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;">
                        <?php echo esc_html(ucfirst($request->status)); ?>
                    </span>
                </div>
            </div>
            
            <!-- Content -->
            <div style="padding:16px;">
                <!-- Request Details -->
                <div style="margin-bottom:12px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
                        <div>
                            <strong><?php _e('Duration:', 'ne-med-lab-prescriptions'); ?></strong><br>
                            <span style="color:#666;"><?php printf(_n('%d day', '%d days', $request->days, 'ne-med-lab-prescriptions'), $request->days); ?></span>
                        </div>
                        <div>
                            <strong><?php _e('Requested:', 'ne-med-lab-prescriptions'); ?></strong><br>
                            <span style="color:#666;"><?php echo esc_html($created_label); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- User Notes -->
                <?php if ($request->notes): ?>
                <div style="margin-bottom:12px;">
                    <strong style="font-size:13px;"><?php _e('User Notes:', 'ne-med-lab-prescriptions'); ?></strong>
                    <div style="background:#f8f9fa;padding:8px;border-radius:4px;margin-top:4px;font-size:12px;color:#666;" class="notes-container">
                        <?php 
                        $trimmed_notes = mb_strlen($request->notes) > 250 ? mb_substr($request->notes, 0, 250) . '...' : $request->notes;
                        $hidden_class = mb_strlen($request->notes) > 250 ? 'notes-hidden' : '';
                        ?>
                        <span class="notes-text"><?php echo esc_html($trimmed_notes); ?></span>
                        <?php if (mb_strlen($request->notes) > 250): ?>
                            <span class="notes-full" style="display:none;"><?php echo esc_html($request->notes); ?></span>
                            <a href="#" class="show-more-link" style="color:#1677ff;text-decoration:none;font-size:11px;display:inline-block;margin-top:5px;">
                                <?php _e('Show more', 'ne-med-lab-prescriptions'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Admin Rejection Notes -->
                <?php if ($request->status === 'rejected' && !empty($request->admin_notes)): ?>
                <div style="margin-bottom:12px;">
                    <strong style="font-size:13px;color:#cf1322;"><?php _e('Rejection Reason:', 'ne-med-lab-prescriptions'); ?></strong>
                    <div style="background:#fff1f0;padding:8px;border-radius:4px;margin-top:4px;font-size:12px;color:#cf1322;border-left:3px solid #cf1322;">
                        <?php echo esc_html($request->admin_notes); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php
                // Enqueue the order request script
                wp_enqueue_script(
                    'ne-mlp-order-request',
                    plugin_dir_url(dirname(__FILE__)) . 'assets/js/ne-mlp-order-request.js',
                    array('jquery'),
                    filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/ne-mlp-order-request.js'),
                    true
                );
                
                // Localize the script with required data
                wp_localize_script('ne-mlp-order-request', 'neMlpOrderRequest', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'showMore' => __('Show more', 'ne-med-lab-prescriptions'),
                    'showLess' => __('Show less', 'ne-med-lab-prescriptions'),
                    'confirmApprove' => __('Are you sure you want to approve this request and create a WooCommerce order?', 'ne-med-lab-prescriptions'),
                    'confirmDelete' => __('Are you sure you want to delete this request? This action cannot be undone.', 'ne-med-lab-prescriptions'),
                    'processing' => __('Processing...', 'ne-med-lab-prescriptions'),
                    'approveSuccess' => __('Request approved successfully! Order created.', 'ne-med-lab-prescriptions'),
                    'rejectSuccess' => __('Request rejected successfully.', 'ne-med-lab-prescriptions'),
                    'deleteSuccess' => __('Request deleted successfully.', 'ne-med-lab-prescriptions'),
                    'errorPrefix' => __('Error: ', 'ne-med-lab-prescriptions'),
                    'errorProcessing' => __('Error processing request. Please try again.', 'ne-med-lab-prescriptions'),
                    'approveText' => __('Approve', 'ne-med-lab-prescriptions'),
                    'rejectText' => __('🔴 Reject', 'ne-med-lab-prescriptions'),
                    'deleteText' => __('🗑️ Delete', 'ne-med-lab-prescriptions'),
                    'approveNonce' => wp_create_nonce('ne_mlp_approve_request'),
                    'rejectNonce' => wp_create_nonce('ne_mlp_reject_request'),
                    'deleteNonce' => wp_create_nonce('ne_mlp_delete_request'),
                    'orderEditBase' => admin_url('post.php'),
                ));
                ?>
                
                <!-- Prescriptions Details -->
                <div style="margin-bottom:12px;">
                    <strong style="font-size:13px;"><?php _e('Prescriptions:', 'ne-med-lab-prescriptions'); ?></strong>
                    <div style="margin-top:8px;">
                        <?php if (empty($prescriptions)): ?>
                            <div style="background:#fff2f0;padding:8px;border-radius:4px;color:#cf1322;font-size:12px;">
                                <strong><?php _e('No prescriptions found!', 'ne-med-lab-prescriptions'); ?></strong><br>
                                <small><?php _e('IDs:', 'ne-med-lab-prescriptions'); ?> <?php echo esc_html($request->prescription_ids); ?></small>
                            </div>
                        <?php else: ?>
                            <?php foreach ($prescriptions as $presc): ?>
                                <?php
                                $presc_manager = class_exists('NE_MLP_Prescription_Manager') ? NE_MLP_Prescription_Manager::getInstance() : null;
                                $presc_id_formatted = $presc_manager ? $presc_manager->format_prescription_id($presc) : 'Prescription #' . $presc->id;
                                $files = json_decode($presc->file_paths, true);
                                
                                // Status colors
                                $presc_status_colors = [
                                    'approved' => '#52c41a',
                                    'pending' => '#faad14',
                                    'rejected' => '#cf1322'
                                ];
                                $presc_status_color = $presc_status_colors[$presc->status] ?? '#666';
                                ?>
                                <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:10px;margin-bottom:8px;">
                                    <!-- Prescription Header -->
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                        <div>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=ne-mlp-prescriptions&search=' . urlencode($presc_id_formatted))); ?>" 
                                               target="_blank" 
                                               style="color:#1677ff;text-decoration:none;font-weight:600;font-size:12px;"
                                               title="<?php _e('View in Prescriptions', 'ne-med-lab-prescriptions'); ?>">
                                                <?php echo esc_html($presc_id_formatted); ?>
                                            </a>
                                            <div style="font-size:10px;color:#666;margin-top:2px;">
                                                <?php _e('Uploaded:', 'ne-med-lab-prescriptions'); ?> <?php echo esc_html(date('M j, Y', strtotime($presc->created_at))); ?>
                                            </div>
                                        </div>
                                        <span style="background:<?php echo $presc_status_color; ?>;color:#fff;padding:2px 6px;border-radius:8px;font-size:9px;font-weight:600;text-transform:uppercase;">
                                            <?php echo esc_html($presc->status); ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Prescription Files -->
                                    <?php if (is_array($files) && !empty($files)): ?>
                                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                            <?php foreach ($files as $file): ?>
                                                <?php $is_pdf = (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf'); ?>
                                                <a href="<?php echo esc_url($file); ?>" target="_blank" 
                                                   style="display:inline-block;width:28px;height:28px;border:1px solid #ddd;border-radius:3px;overflow:hidden;text-decoration:none;"
                                                   title="<?php echo esc_attr(basename($file)); ?>">
                                                    <?php if ($is_pdf): ?>
                                                        <div style="width:100%;height:100%;background:#f5f5f5;display:flex;align-items:center;justify-content:center;font-size:12px;">📄</div>
                                                    <?php else: ?>
                                                        <img src="<?php echo esc_url($file); ?>" style="width:100%;height:100%;object-fit:cover;" alt="Prescription" />
                                                    <?php endif; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="color:#666;font-style:italic;font-size:11px;">
                                            <?php _e('No files found', 'ne-med-lab-prescriptions'); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Order ID (if approved) -->
                <?php if ($request->order_id): ?>
                <div style="margin-bottom:12px;">
                    <strong style="font-size:13px;"><?php _e('Order ID:', 'ne-med-lab-prescriptions'); ?></strong>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $request->order_id . '&action=edit')); ?>" target="_blank" style="color:#1677ff;text-decoration:none;font-size:13px;">
                        #<?php echo esc_html($request->order_id); ?>
                    </a>
                </div>
                <?php endif; ?>
                
            </div>
            
            <!-- Actions -->
            <?php if ($request->status === 'pending'): ?>
            <div style="padding:12px 16px;background:#f8f9fa;border-top:1px solid #eee;">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                    <button type="button" class="button button-primary button-small approve-request" data-request-id="<?php echo esc_attr($request->id); ?>">
                        <?php _e('Approve', 'ne-med-lab-prescriptions'); ?>
                    </button>
                    <button type="button" class="button button-small reject-request" data-request-id="<?php echo esc_attr($request->id); ?>" style="background:#d63638;color:#fff;border-color:#d63638;">
                        🔴 <?php _e('Reject', 'ne-med-lab-prescriptions'); ?>
                    </button>
                    <button type="button" class="button button-small delete-request" data-request-id="<?php echo esc_attr($request->id); ?>" style="background:#cf1322;color:#fff;border-color:#cf1322;">
                        🗑️ <?php _e('Delete', 'ne-med-lab-prescriptions'); ?>
                    </button>
                </div>
            </div>
            <?php elseif ($request->status === 'rejected'): ?>
            <div style="padding:12px 16px;background:#f8f9fa;border-top:1px solid #eee;">
                <button type="button" class="button button-small delete-request" data-request-id="<?php echo esc_attr($request->id); ?>" style="background:#cf1322;color:#fff;border-color:#cf1322;width:100%;">
                    🗑️ <?php _e('Delete', 'ne-med-lab-prescriptions'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
        
        <?php
        // Add rejection modal only once
        if (!$modal_added) {
            $modal_added = true;
            ?>
            <!-- Rejection Modal -->
            <div id="reject-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
                <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:30px;border-radius:8px;width:90%;max-width:500px;">
                    <h3><?php _e('Reject Order Request', 'ne-med-lab-prescriptions'); ?></h3>
                    <p><?php _e('Please provide a reason for rejection:', 'ne-med-lab-prescriptions'); ?></p>
                    <textarea id="reject-reason" rows="4" style="width:100%;margin-bottom:15px;padding:10px;border:1px solid #ddd;border-radius:4px;" placeholder="<?php _e('Enter rejection reason...', 'ne-med-lab-prescriptions'); ?>"></textarea>
                    <div style="text-align:right;">
                        <button type="button" id="cancel-reject" class="button" style="margin-right:10px;"><?php _e('Cancel', 'ne-med-lab-prescriptions'); ?></button>
                        <button type="button" id="confirm-reject" class="button button-primary" style="background:#d63638;"><?php _e('Reject Request', 'ne-med-lab-prescriptions'); ?></button>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>
        <?php
    }
    
    /**
     * Create order requests table
     */
    public function maybe_create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ne_mlp_order_requests';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                prescription_ids TEXT NOT NULL,
                days INT NOT NULL DEFAULT 1,
                notes TEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                order_id BIGINT UNSIGNED DEFAULT NULL,
                admin_notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY status (status),
                KEY order_id (order_id),
                KEY created_at (created_at)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Get the menu title with pending count badge if applicable
     */
    public function get_menu_title() {
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        
        // Count pending requests
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        
        $menu_title = __('Orders Request', 'ne-med-lab-prescriptions');
        if ($pending_count > 0) {
            $menu_title .= ' <span class="ne-mlp-notification-badge" style="background:#d63638;color:#fff;border-radius:10px;padding:2px 6px;font-size:11px;margin-left:5px;">' . $pending_count . '</span>';
        }
        
        return $menu_title;
    }
    
    /**
     * Render admin page for order requests
     */
    public function render_admin_page() {
        // Get current page and filters
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to = sanitize_text_field($_GET['date_to'] ?? '');
        $search = sanitize_text_field($_GET['search'] ?? '');
        
        // Get requests with pagination
        $per_page = 30;
        $requests_data = $this->get_requests_with_pagination($current_page, $per_page, $status_filter, $date_from, $date_to, $search);
        
        // Determine latest request for pinning
        $latest_request = null;
        $latest_request_id = null;
        if (!empty($requests_data['requests'])) {
            // Results are ordered by id DESC in get_requests_with_pagination()
            $latest_request = $requests_data['requests'][0];
            $latest_request_id = intval($latest_request->id);
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Order Requests', 'ne-med-lab-prescriptions'); ?></h1>
            
            <!-- Search and Filter Bar (matching prescription style) -->
            <div class="ne-mlp-admin-filter-bar" style="background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);margin-bottom:24px;">
                <h2 style="margin-top:0;"><?php _e('Search Order Requests', 'ne-med-lab-prescriptions'); ?></h2>
                
                <form method="get" style="display:flex;gap:16px;flex-wrap:wrap;align-items:end;">
                    <input type="hidden" name="page" value="ne-mlp-request-order">
                    
                    <!-- Search Input -->
                    <div style="flex:1;min-width:300px;">
                        <label style="display:block;margin-bottom:8px;font-weight:600;"><?php _e('Search', 'ne-med-lab-prescriptions'); ?></label>
                        <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search by Request ID (PRxO...), User Name, Email', 'ne-med-lab-prescriptions'); ?>" style="width:100%;" />
                    </div>
                    
                    <!-- Status Filter -->
                    <div style="min-width:200px;">
                        <label style="display:block;margin-bottom:8px;font-weight:600;"><?php _e('Status', 'ne-med-lab-prescriptions'); ?></label>
                        <select name="status" style="width:100%;">
                            <option value=""><?php _e('All Statuses', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="pending"<?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="approved"<?php selected($status_filter, 'approved'); ?>><?php _e('Approved', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="rejected"<?php selected($status_filter, 'rejected'); ?>><?php _e('Rejected', 'ne-med-lab-prescriptions'); ?></option>
                        </select>
                    </div>
                    
                    <!-- Date Range -->
                    <div style="min-width:200px;">
                        <label style="display:block;margin-bottom:8px;font-weight:600;"><?php _e('Date From', 'ne-med-lab-prescriptions'); ?></label>
                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" style="width:100%;" />
                    </div>
                    
                    <div style="min-width:200px;">
                        <label style="display:block;margin-bottom:8px;font-weight:600;"><?php _e('Date To', 'ne-med-lab-prescriptions'); ?></label>
                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" style="width:100%;" />
                    </div>
                    
                    <button type="submit" class="button button-primary" style="margin-bottom:8px;"><?php _e('Search', 'ne-med-lab-prescriptions'); ?></button>
                    <a href="<?php echo admin_url('admin.php?page=ne-mlp-request-order'); ?>" class="button" style="margin-bottom:8px;"><?php _e('Reset', 'ne-med-lab-prescriptions'); ?></a>
                </form>
            </div>
            
            <?php if ($latest_request): ?>
            <!-- Pinned Latest Request -->
            <div style="margin-bottom:24px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#e6f4ff;border:1px solid #91caff;color:#0958d9;font-size:12px;font-weight:600;padding:4px 8px;border-radius:999px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        <?php _e('Latest Request', 'ne-med-lab-prescriptions'); ?>
                    </span>
                </div>
                <?php $this->render_request_card_grid($latest_request); ?>
            </div>
            <?php endif; ?>
            
            <!-- Results Summary -->
            <div style="margin-bottom:20px;">
                <p><?php printf(_n('%d order request found', '%d order requests found', $requests_data['total'], 'ne-med-lab-prescriptions'), $requests_data['total']); ?></p>
            </div>
            
            <!-- Order Requests Grid (matching prescription card style) -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;margin-bottom:30px;">
                <?php if (empty($requests_data['requests'])): ?>
                    <div style="grid-column:1/-1;text-align:center;padding:60px;background:#f9f9f9;border-radius:12px;">
                        <h3><?php _e('No Order Requests Found', 'ne-med-lab-prescriptions'); ?></h3>
                        <p><?php _e('No order requests match your search criteria.', 'ne-med-lab-prescriptions'); ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($requests_data['requests'] as $request): ?>
                        <?php if ($latest_request_id && intval($request->id) === $latest_request_id) { continue; } ?>
                        <?php $this->render_request_card_grid($request); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Modern Pagination -->
            <?php if ($requests_data['total_pages'] > 1): ?>
                <div style="padding: 20px 0; text-align: center;">
                    <div style="display: inline-flex; align-items: center; gap: 8px; background: #fff; padding: 8px 16px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                        <?php if ($current_page > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $current_page - 1)); ?>" 
                               style="display: inline-flex; align-items: center; padding: 8px 16px; background: #f5f5f5; color: #555; text-decoration: none; border-radius: 6px; transition: all 0.2s; border: 1px solid #ddd;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                                <span><?php _e('Previous', 'ne-med-lab-prescriptions'); ?></span>
                            </a>
                        <?php endif; ?>
                        
                        <span style="font-size: 14px; font-weight: 500; color: #333; padding: 8px 16px; background: #f8f9fa; border-radius: 6px;">
                            <?php printf(__('Page %d of %d', 'ne-med-lab-prescriptions'), $current_page, $requests_data['total_pages']); ?>
                        </span>
                        
                        <?php if ($current_page < $requests_data['total_pages']): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $current_page + 1)); ?>" 
                               style="display: inline-flex; align-items: center; padding: 8px 16px; background: #2271b1; color: white; text-decoration: none; border-radius: 6px; transition: all 0.2s; border: 1px solid #135e96;">
                                <span><?php _e('Next', 'ne-med-lab-prescriptions'); ?></span>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px;">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        
        <!-- Rejection Modal -->
        <div id="reject-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:30px;border-radius:8px;width:90%;max-width:500px;">
                <h3><?php _e('Reject Order Request', 'ne-med-lab-prescriptions'); ?></h3>
                <p><?php _e('Please provide a reason for rejection:', 'ne-med-lab-prescriptions'); ?></p>
                <textarea id="reject-reason" rows="4" style="width:100%;margin-bottom:15px;padding:10px;border:1px solid #ddd;border-radius:4px;" placeholder="<?php _e('Enter rejection reason...', 'ne-med-lab-prescriptions'); ?>"></textarea>
                <div style="text-align:right;">
                    <button type="button" id="cancel-reject" class="button" style="margin-right:10px;"><?php _e('Cancel', 'ne-med-lab-prescriptions'); ?></button>
                    <button type="button" id="confirm-reject" class="button button-primary" style="background:#d63638;"><?php _e('Reject Request', 'ne-med-lab-prescriptions'); ?></button>
                </div>
            </div>
        </div>
        
        <!-- JavaScript is now loaded from ne-mlp-order-request.js -->
        
        <style>
        .ne-mlp-request-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .ne-mlp-request-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        .ne-mlp-admin-filter-bar {
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            margin-bottom: 24px;
        }
        .ne-mlp-admin-filter-bar input,
        .ne-mlp-admin-filter-bar select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .ne-mlp-admin-filter-bar input:focus,
        .ne-mlp-admin-filter-bar select:focus {
            outline: none;
            border-color: #007cba;
            box-shadow: 0 0 0 1px #007cba;
        }
        </style>
        <?php
    }   
 

    

    
    /**
     * AJAX: Approve order request and create WooCommerce order
     */
    public function ajax_approve_request() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        check_ajax_referer('ne_mlp_approve_request', 'nonce');
        
        $request_id = intval($_POST['request_id']);
        if (!$request_id) {
            wp_send_json_error('Invalid request ID');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        
        // Get request details
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND status = 'pending'",
            $request_id
        ));
        
        if (!$request) {
            wp_send_json_error('Request not found or already processed');
        }
        
        // Create WooCommerce order
        $order_id = $this->create_woocommerce_order($request);
        
        if (is_wp_error($order_id)) {
            wp_send_json_error($order_id->get_error_message());
        }
        
        // Update request status
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'approved',
                'order_id' => $order_id,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $request_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
        
        if ($updated === false) {
            wp_send_json_error('Failed to update request status');
        }
        
        wp_send_json_success([
            'message' => 'Request approved and order created successfully',
            'order_id' => $order_id
        ]);
    }
    
    /**
     * AJAX: Reject order request
     */
    public function ajax_reject_request() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        check_ajax_referer('ne_mlp_reject_request', 'nonce');
        
        $request_id = intval($_POST['request_id']);
        $admin_notes = sanitize_textarea_field($_POST['admin_notes']);
        
        if (!$request_id) {
            wp_send_json_error('Invalid request ID');
        }
        
        if (empty($admin_notes)) {
            wp_send_json_error('Rejection reason is required');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        
        // Get request details
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND status = 'pending'",
            $request_id
        ));
        
        if (!$request) {
            wp_send_json_error('Request not found or already processed');
        }
        
        // Update request status
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'rejected',
                'admin_notes' => $admin_notes,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $request_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        
        if ($updated === false) {
            wp_send_json_error('Failed to update request status');
        }
        
        wp_send_json_success([
            'message' => 'Request rejected successfully'
        ]);
    }
    
    /**
     * AJAX: Delete order request
     */
    public function ajax_delete_request() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        check_ajax_referer('ne_mlp_delete_request', 'nonce');
        
        $request_id = intval($_POST['request_id']);
        
        if (!$request_id) {
            wp_send_json_error('Invalid request ID');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        
        // Get request details
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND status IN ('pending', 'rejected')",
            $request_id
        ));
        
        if (!$request) {
            wp_send_json_error('Request not found or cannot be deleted');
        }
        
        // Delete the request
        $deleted = $wpdb->delete(
            $table,
            ['id' => $request_id],
            ['%d']
        );
        
        if ($deleted === false) {
            wp_send_json_error('Failed to delete request');
        }
        
        wp_send_json_success([
            'message' => 'Request deleted successfully'
        ]);
    }
    
    /**
     * AJAX: Get pending requests count for badge update
     */
    public function ajax_get_pending_count() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        
        wp_send_json_success([
            'count' => intval($pending_count)
        ]);
    }
    
    /**
     * Add script to update badge count on admin pages
     */
    public function add_badge_update_script() {
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var pendingCount = <?php echo intval($pending_count); ?>;
            var menuItem = $('a[href*="ne-mlp-request-order"]').first();
            
            if (menuItem.length) {
                var existingBadge = menuItem.find('.ne-mlp-notification-badge');
                
                if (pendingCount > 0) {
                    if (existingBadge.length) {
                        existingBadge.text(pendingCount);
                    } else {
                        menuItem.append(' <span class="ne-mlp-notification-badge" style="background:#d63638;color:#fff;border-radius:10px;padding:2px 6px;font-size:11px;margin-left:5px;">' + pendingCount + '</span>');
                    }
                } else {
                    existingBadge.remove();
                }
            }
        });
        </script>
        <?php
    }
    
    
    /**
     * Create WooCommerce order from request
     */
    private function create_woocommerce_order($request) {
        try {
            // Create new order
            $order = wc_create_order([
                'customer_id' => $request->user_id,
                'status' => 'pending'
            ]);
            
            if (is_wp_error($order)) {
                return $order;
            }
            
            // Add a generic prescription product or create a custom line item
            // You can customize this based on your needs
            $product_name = sprintf(
                __('Prescription Order - %d days', 'ne-med-lab-prescriptions'),
                $request->days
            );
            
            // Add custom line item
            $item = new WC_Order_Item_Product();
            $item->set_name($product_name);
            $item->set_quantity(1);
            $item->set_total(0); // Set appropriate price
            $order->add_item($item);
            
            // Add order notes (explicit Request ID formatting)
            $order->add_order_note(
                sprintf(
                    __('Request ID: #%d | Duration: %d days | Notes: %s', 'ne-med-lab-prescriptions'),
                    $request->id,
                    $request->days,
                    $request->notes ?: 'None'
                ),
                false,
                true
            );
            
            // Link prescriptions to order
            $prescription_ids = json_decode($request->prescription_ids, true);
            // Fallback: handle single ID or malformed JSON
            if (!is_array($prescription_ids)) {
                if (is_numeric($request->prescription_ids)) {
                    $prescription_ids = [intval($request->prescription_ids)];
                } else {
                    $prescription_ids = [];
                }
            }
            if (!empty($prescription_ids)) {
                global $wpdb;
                $presc_table = $wpdb->prefix . 'prescriptions';
                $primary_presc_id = null;
                foreach ($prescription_ids as $presc_id) {
                    $presc_id = intval($presc_id);
                    if ($presc_id > 0) {
                        if ($primary_presc_id === null) {
                            $primary_presc_id = $presc_id;
                        }
                        // Update prescription with order ID
                        $wpdb->update(
                            $presc_table,
                            ['order_id' => $order->get_id(), 'status' => 'approved'],
                            ['id' => $presc_id],
                            ['%d', '%s'],
                            ['%d']
                        );
                    }
                }
                // Ensure order meta used by Prescription Manager hooks is present
                // This powers the "prescription_data" block on order read.
                update_post_meta($order->get_id(), '_ne_mlp_requires_prescription', 'yes');
                if ($primary_presc_id) {
                    update_post_meta($order->get_id(), '_ne_mlp_prescription_id', $primary_presc_id);
                    update_post_meta($order->get_id(), '_ne_mlp_prescription_status', 'approved');
                }
            }
            
            $order->calculate_totals();
            $order->save();
            
            return $order->get_id();
            
        } catch (Exception $e) {
            return new WP_Error('order_creation_failed', $e->getMessage());
        }
    }
    public function api_create_request($request) {
        $user_id = get_current_user_id();
        $prescription_ids = $request->get_param('prescription_ids');
        $days = intval($request->get_param('days'));
        $notes = sanitize_textarea_field($request->get_param('notes'));

        // Normalize prescription ids
        if (!is_array($prescription_ids)) {
            $prescription_ids = [$prescription_ids];
        }

        // Delegate to manager
        if (!class_exists('NE_MLP_Order_Manager')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Order manager not available.', 'ne-med-lab-prescriptions')
            ], 500);
        }

        $manager = NE_MLP_Order_Manager::getInstance();
        $result = $manager->create_request([
            'user_id' => $user_id,
            'prescription_ids' => $prescription_ids,
            'days' => $days,
            'notes' => $notes,
            'verify_ownership' => true,
        ]);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'request_id' => $result['request_id'],
            'formatted_id' => $result['formatted_id'],
            'message' => __('Order request created successfully', 'ne-med-lab-prescriptions')
        ], 201);
    }
    
    /**
     * API permission check
     */
    public function api_permission_check($request) {
        // Use the same permission check as the main REST API class
        if (class_exists('NE_MLP_REST_API')) {
            $api = new NE_MLP_REST_API();
            return $api->permission_check($request);
        }
        
        return is_user_logged_in();
    }
    
    /**
     * API: Get user's order requests
     */
    public function api_get_requests($request) {
        $user_id = get_current_user_id();
        $page = max(1, intval($request->get_param('page')));
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        
        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));
        
        $requests = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ));
        
        // Format requests with prescription details
        foreach ($requests as &$req) {
            // Add formatted request ID
            $req->formatted_id = $this->format_order_request_id($req);
            
            $req->prescription_ids = json_decode($req->prescription_ids, true);
            
            // Get prescription details
            $presc_table = $wpdb->prefix . 'prescriptions';
            $prescriptions = [];
            
            if (is_array($req->prescription_ids)) {
                foreach ($req->prescription_ids as $presc_id) {
                    $presc = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $presc_table WHERE id = %d",
                        $presc_id
                    ));
                    if ($presc) {
                        $presc->file_paths = json_decode($presc->file_paths, true);
                        
                        // Add formatted prescription ID
                        if (class_exists('NE_MLP_Prescription_Manager')) {
                            $presc_manager = NE_MLP_Prescription_Manager::getInstance();
                            $presc->formatted_id = $presc_manager->format_prescription_id($presc);
                        } else {
                            $presc->formatted_id = 'Prescription #' . $presc->id;
                        }
                        
                        $prescriptions[] = $presc;
                    }
                }
            }
            
            $req->prescriptions = $prescriptions;
        }
        
        return new WP_REST_Response([
            'requests' => $requests,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total),
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }
}