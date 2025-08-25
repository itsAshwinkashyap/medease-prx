<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Centralized Prescription Manager
 * Handles all prescription-related operations to avoid code duplication
 */
class NE_MLP_Prescription_Manager
{

    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Initialize hooks for WooCommerce API extensions
        add_filter('woocommerce_rest_prepare_shop_order_object', [$this, 'add_prescription_meta_to_order_api'], 10, 3);
        add_filter('woocommerce_rest_prepare_order_object', [$this, 'add_prescription_meta_to_order_api'], 10, 3);

        // Hook to sync prescription status when orders are loaded
        add_action('woocommerce_order_object_updated_props', [$this, 'maybe_sync_prescription_status'], 10, 2);

        // Hook to sync prescription status when order meta is requested
        add_filter('get_post_metadata', [$this, 'sync_prescription_status_on_meta_get'], 10, 4);
    }

    /**
     * Check if any cart item requires prescription
     */
    public function cart_requires_prescription()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($this->product_requires_prescription($cart_item['product_id'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a product requires prescription
     */
    public function product_requires_prescription($product_id)
    {
        return get_post_meta($product_id, '_ne_mlp_requires_prescription', true) === 'yes';
    }

    /**
     * Check if an order requires prescription
     */
    public function order_requires_prescription($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if ($this->product_requires_prescription($product_id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate prescription requirements
     */
    public function validate_prescription_requirements($has_selection, $has_upload, $mode = 'now')
    {
        if ($mode === 'later') {
            return true; // Allow if attaching later
        }

        if (!$has_selection && !$has_upload) {
            return new WP_Error('prescription_required', 'Prescription is required for one or more products in your cart.');
        }

        return true;
    }

    /**
     * Attach prescription to order (centralized method)
     * Uses the new centralized order tracking
     */
    public function attach_prescription_to_order($order_id, $prescription_id)
    {
        // First validate the prescription for this order
        $validation_result = $this->validate_prescription_for_order($prescription_id, $order_id);

        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        // Use the centralized order tracking update method
        $result = $this->update_prescription_order_tracking($prescription_id, $order_id);

        // Update order meta with current prescription status from database
        if (!is_wp_error($result)) {
            $this->sync_prescription_status_with_order($order_id);
        }

        return $result;
    }

    /**
     * Upload prescription files (centralized method)
     */
    public function upload_prescription_files($files, $user_id, $type = 'medicine', $source = 'website')
    {
        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $saved_files = [];

        if (!is_array($files['name'])) {
            // Single file upload
            $files = [
                'name' => [$files['name']],
                'type' => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'size' => [$files['size']],
                'error' => [$files['error']]
            ];
        }

        $count = count($files['name']);
        if ($count > 4) {
            return new WP_Error('too_many_files', 'Maximum 4 files allowed');
        }

        for ($i = 0; $i < $count; $i++) {
            if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $type_mime = $files['type'][$i];
            $size = $files['size'][$i];

            if (!in_array($type_mime, $allowed_types)) {
                return new WP_Error('invalid_file_type', 'Invalid file type: ' . $files['name'][$i]);
            }

            if ($size > $max_size) {
                return new WP_Error('file_too_large', 'File too large: ' . $files['name'][$i]);
            }

            $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
            $new_name = 'presc_' . $user_id . '_' . time() . "_{$i}.{$ext}";
            $upload_dir = wp_upload_dir();
            $target = trailingslashit($upload_dir['basedir']) . 'ne-mlp-prescriptions/';

            if (!file_exists($target)) {
                wp_mkdir_p($target);
            }

            $file_path = $target . $new_name;
            if (move_uploaded_file($files['tmp_name'][$i], $file_path)) {
                $saved_files[] = trailingslashit($upload_dir['baseurl']) . 'ne-mlp-prescriptions/' . $new_name;
            }
        }

        if (empty($saved_files)) {
            return new WP_Error('upload_failed', 'No files were uploaded successfully');
        }

        // Save prescription to database
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $result = $wpdb->insert($table, [
            'user_id' => $user_id,
            'order_id' => null,
            'file_paths' => wp_json_encode($saved_files),
            'type' => $type,
            'status' => 'pending',
            'source' => $source,
            'created_at' => current_time('mysql'),
        ]);

        if ($result === false) {
            return new WP_Error('db_insert_failed', 'Failed to save prescription to database');
        }

        $prescription_id = $wpdb->insert_id;

        // Trigger email notification for prescription upload
        $this->trigger_upload_notification($prescription_id, $user_id);

        return $prescription_id;
    }

    /**
     * Update prescription status and sync with WooCommerce order meta
     */
    public function update_prescription_status($prescription_id, $new_status, $reject_note = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // Get current prescription data
        $prescription = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $prescription_id));
        if (!$prescription) {
            return new WP_Error('prescription_not_found', 'Prescription not found');
        }

        // Update prescription status
        $update_data = ['status' => $new_status];
        if ($reject_note && $new_status === 'rejected') {
            $update_data['reject_note'] = $reject_note;
        }

        $result = $wpdb->update($table, $update_data, ['id' => $prescription_id]);
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update prescription status');
        }

        // Update WooCommerce order meta if prescription is attached to an order
        if ($prescription->order_id) {
            update_post_meta($prescription->order_id, '_ne_mlp_prescription_status', $new_status);

            // Add order note about status change
            $order = wc_get_order($prescription->order_id);
            if ($order) {
                $formatted_id = $this->format_prescription_id($prescription);
                $status_text = ucfirst($new_status);
                $note_message = sprintf(
                    __('Prescription %s status changed to: %s', 'ne-med-lab-prescriptions'),
                    $formatted_id,
                    $status_text
                );

                if ($reject_note && $new_status === 'rejected') {
                    $note_message .= '. Reason: ' . $reject_note;
                }

                $order->add_order_note($note_message, false, true);
            }
        }

        return true;
    }

    /**
     * Trigger email notification when prescription is uploaded
     */
    private function trigger_upload_notification($prescription_id, $user_id)
    {
        // Get the uploaded prescription data
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $prescription_id
        ));

        if (!$prescription) {
            return;
        }

        // Get user data
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Check if admin panel class exists and send notifications
        if (class_exists('NE_MLP_Admin_Panel')) {
            // Get admin panel instance or create a temporary one for sending notifications
            $admin_panel = new NE_MLP_Admin_Panel();
            $admin_panel->trigger_upload_notification($user, $prescription);
        }
    }

    /**
     * Re-upload prescription files (replace existing files)
     */
    public function reupload_prescription_files($prescription_id, $files, $user_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // First, verify the prescription exists and belongs to the user
        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $prescription_id,
            $user_id
        ));

        if (!$prescription) {
            return new WP_Error('prescription_not_found', 'Prescription not found or access denied');
        }

        // Only allow re-upload for rejected prescriptions
        if ($prescription->status !== 'rejected') {
            return new WP_Error('cannot_reupload', 'Can only re-upload rejected prescriptions');
        }

        // Delete old files
        $old_file_paths = json_decode($prescription->file_paths, true);
        if (is_array($old_file_paths)) {
            foreach ($old_file_paths as $file_url) {
                // Convert URL to file path
                $upload_dir = wp_upload_dir();
                $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }

        // Upload new files using the same validation as regular upload
        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $saved_files = [];

        if (!is_array($files['name'])) {
            // Single file upload
            $files = [
                'name' => [$files['name']],
                'type' => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'size' => [$files['size']],
                'error' => [$files['error']]
            ];
        }

        $count = count($files['name']);
        if ($count > 4) {
            return new WP_Error('too_many_files', 'Maximum 4 files allowed');
        }

        for ($i = 0; $i < $count; $i++) {
            if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $type_mime = $files['type'][$i];
            $size = $files['size'][$i];

            if (!in_array($type_mime, $allowed_types)) {
                return new WP_Error('invalid_file_type', 'Invalid file type: ' . $files['name'][$i]);
            }

            if ($size > $max_size) {
                return new WP_Error('file_too_large', 'File too large: ' . $files['name'][$i]);
            }

            $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
            $new_name = 'presc_' . $user_id . '_' . time() . "_{$i}.{$ext}";
            $upload_dir = wp_upload_dir();
            $target = trailingslashit($upload_dir['basedir']) . 'ne-mlp-prescriptions/';

            if (!file_exists($target)) {
                wp_mkdir_p($target);
            }

            $file_path = $target . $new_name;
            if (move_uploaded_file($files['tmp_name'][$i], $file_path)) {
                $saved_files[] = trailingslashit($upload_dir['baseurl']) . 'ne-mlp-prescriptions/' . $new_name;
            }
        }

        if (empty($saved_files)) {
            return new WP_Error('upload_failed', 'No files were uploaded successfully');
        }

        // Update prescription with new files and reset status to pending
        $result = $wpdb->update($table, [
            'file_paths' => wp_json_encode($saved_files),
            'status' => 'pending',
        ], [
            'id' => $prescription_id
        ], ['%s', '%s'], ['%d']);

        if ($result === false) {
            return new WP_Error('db_update_failed', 'Failed to update prescription in database');
        }

        // Trigger email notification for prescription re-upload
        $this->trigger_upload_notification($prescription_id, $user_id);

        return $prescription_id;
    }

    /**
     * Delete prescription and associated files
     */
    public function delete_prescription($prescription_id, $user_id = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // Build query conditions
        $where = ['id' => $prescription_id];
        $where_format = ['%d'];

        if ($user_id) {
            $where['user_id'] = $user_id;
            $where_format[] = '%d';
        }

        // Get prescription details before deletion
        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE " . implode(' AND ', array_map(function ($key) {
                return "$key = %d";
            }, array_keys($where))),
            array_values($where)
        ));

        if (!$prescription) {
            return new WP_Error('prescription_not_found', 'Prescription not found');
        }

        // Check deletion restrictions

        // 1. Cannot delete approved prescriptions
        if ($prescription->status === 'approved') {
            return new WP_Error('cannot_delete_approved', 'Cannot delete approved prescriptions');
        }

        // 2. Cannot delete prescriptions attached to any order
        if (!empty($prescription->order_id)) {
            return new WP_Error('cannot_delete_attached', 'Cannot delete prescriptions that are attached to orders');
        }

        // 3. Only allow deletion of pending or rejected prescriptions
        if (!in_array($prescription->status, ['pending', 'rejected'])) {
            return new WP_Error('invalid_status_for_deletion', 'Can only delete pending or rejected prescriptions');
        }

        // Delete associated files
        $file_paths = json_decode($prescription->file_paths, true);
        if (is_array($file_paths)) {
            foreach ($file_paths as $file_url) {
                $upload_dir = wp_upload_dir();
                $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }

        // Delete prescription record
        $deleted = $wpdb->delete($table, $where, $where_format);

        if ($deleted === false) {
            return new WP_Error('delete_failed', 'Failed to delete prescription');
        }

        return true;
    }

    /**
     * Format prescription ID for display
     */
    public function format_prescription_id($prescription)
    {
        $date = date('d-m-y', strtotime($prescription->created_at));
        $status = isset($prescription->status) ? ucfirst($prescription->status) : 'Pending';
        $type = isset($prescription->type) ? $prescription->type : 'medicine';
        $prefix = $type === 'lab_test' ? 'Lab' : 'Med';

        return $prefix . '-' . $date . '-' . $prescription->id;
    }

    /**
     * Get formatted prescription dropdown option
     */
    public function get_prescription_dropdown_option($prescription, $include_order_info = false)
    {
        $date = date('d-m-y', strtotime($prescription->created_at));
        $prefix = $prescription->type === 'lab_test' ? 'Lab' : 'Med';
        $presc_id = $prefix . '-' . $date . '-' . $prescription->id;
        $status = ucfirst($prescription->status);
        $status_lower = strtolower($prescription->status);

        // Create formatted label with prescription ID and colored status
        $label = $presc_id . ' - ' . $status;

        // Color coding for status only (prescription ID stays black)
        $status_color = '';
        switch ($status_lower) {
            case 'approved':
                $status_color = '#52c41a'; // Green
                break;
            case 'pending':
                $status_color = '#faad14'; // Yellow/Orange
                break;
            case 'rejected':
                $status_color = '#cf1322'; // Red
                break;
            default:
                $status_color = '#666666'; // Gray
        }

        // Add order info only if explicitly requested and available
        if ($include_order_info && !empty($prescription->order_id)) {
            $order = wc_get_order($prescription->order_id);
            if ($order) {
                $label .= ' (Order #' . $prescription->order_id . ')';
            }
        }

        return [
            'value' => $prescription->id,
            'label' => $label,
            'color' => $status_color,
            'status' => $prescription->status,
            'id_part' => $presc_id,
            'status_part' => $status,
            'html_label' => $presc_id . ' - <span style="color:' . $status_color . ';">' . $status . '</span>'
        ];
    }

    /**
     * Get user's medicine prescriptions for dropdown
     */
    public function get_user_medicine_prescriptions($user_id, $status = null, $include_order_info = false)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        $where = "WHERE user_id = %d AND type = 'medicine'";
        $params = [$user_id];

        // If no status specified, default to pending and approved (exclude rejected)
        if ($status === null) {
            $where .= " AND status IN ('pending', 'approved')";
        } elseif ($status) {
            if (is_array($status)) {
                $placeholders = implode(',', array_fill(0, count($status), '%s'));
                $where .= " AND status IN ($placeholders)";
                $params = array_merge($params, $status);
            } else {
                $where .= " AND status = %s";
                $params[] = $status;
            }
        }

        $prescriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table $where ORDER BY created_at DESC",
            $params
        ));

        $options = [];
        foreach ($prescriptions as $prescription) {
            $options[] = $this->get_prescription_dropdown_option($prescription, $include_order_info);
        }

        return $options;
    }

    /**
     * Validate prescription type for order attachment
     */
    public function validate_prescription_for_order($prescription_id, $order_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $prescription_id
        ));

        if (!$prescription) {
            return new WP_Error('prescription_not_found', 'Prescription not found');
        }

        // Check if prescription type is medicine (required for orders)
        if ($prescription->type !== 'medicine') {
            return new WP_Error('invalid_prescription_type', 'Only medicine prescriptions can be attached to orders. Lab prescriptions are not allowed.');
        }

        // Verify order exists and has prescription-required products
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found');
        }

        $requires_prescription = false;
        foreach ($order->get_items() as $item) {
            if ($this->product_requires_prescription($item->get_product_id())) {
                $requires_prescription = true;
                break;
            }
        }

        if (!$requires_prescription) {
            return new WP_Error('order_no_prescription_required', 'This order does not contain products that require prescriptions');
        }

        return true;
    }



    /**
     * Get user's prescriptions with pagination
     */
    public function get_user_prescriptions($user_id, $status = null, $page = 1, $per_page = 10)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        $where = "WHERE user_id = %d";
        $params = [$user_id];

        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table $where",
            $params
        ));

        // Get paginated results
        $offset = ($page - 1) * $per_page;
        $prescriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        ));

        return [
            'prescriptions' => $prescriptions,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total),
                'total_pages' => ceil($total / $per_page),
                'has_next' => $page < ceil($total / $per_page),
                'has_prev' => $page > 1
            ]
        ];
    }

    /**
     * Get prescription by ID
     */
    public function get_prescription($prescription_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $prescription_id
        ));
    }

    /**
     * Handle upload from form submission (used by shortcode and other forms)
     */
    public function handle_upload()
    {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', 'You must be logged in to upload prescriptions');
        }

        $user_id = get_current_user_id();
        $type = sanitize_text_field($_POST['ne_mlp_prescription_type'] ?? 'medicine');

        if (empty($_FILES['ne_mlp_prescription_files'])) {
            return new WP_Error('no_files', 'No files uploaded');
        }

        $files = $_FILES['ne_mlp_prescription_files'];

        // Validate files
        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $count = count($files['name']);

        if ($count > 4) {
            return new WP_Error('too_many_files', 'Maximum 4 files allowed');
        }

        // Upload and save prescription
        $result = $this->upload_prescription_files($files, $user_id, $type);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result; // Returns prescription ID
    }

    /**
     * Get centralized prescription dropdown HTML (global format)
     * Format: Upload date-Id-status with color coding
     * Example: Med-11-06-25-50 - Approved (green) or Med-11-06-25-50 - Pending (yellow)
     */
    public function get_prescription_dropdown_html($user_id, $selected_id = null, $field_name = 'prescription_id')
    {
        // Get prescriptions directly from database to avoid array/object issues
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        $prescriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND status IN ('pending', 'approved') AND type = 'medicine' ORDER BY created_at DESC",
            $user_id
        ));

        if (empty($prescriptions)) {
            return '<p style="color:#666;font-style:italic;">No approved or pending prescriptions available.</p>';
        }

        $html = '<select name="' . esc_attr($field_name) . '" style="width:100%;margin-bottom:10px;padding:8px;">';
        $html .= '<option value="">-- Select Prescription --</option>';

        foreach ($prescriptions as $presc) {
            $date = date('d-m-y', strtotime($presc->created_at));
            $presc_id_format = 'Med-' . $date . '-' . $presc->id;

            // Color coding: Green for approved, Yellow for pending
            $status_color = $presc->status === 'approved' ? '#52c41a' : '#faad14';
            $status_text = ucfirst($presc->status);

            $option_text = $presc_id_format . ' - ' . $status_text;
            $selected = ($selected_id && $selected_id == $presc->id) ? ' selected' : '';

            $html .= '<option value="' . intval($presc->id) . '"' . $selected . ' style="color:' . $status_color . ';">' . esc_html($option_text) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * Get centralized upload handler HTML (same design everywhere)
     * Shows both upload and select options with consistent styling
     */
    public function get_upload_handler_html($user_id, $order_id = null, $context = 'general')
    {
        $context_titles = [
            'checkout' => 'Upload Prescription for Checkout',
            'order' => 'Upload Prescription for this Order',
            'general' => 'Upload Prescription'
        ];

        $title = isset($context_titles[$context]) ? $context_titles[$context] : $context_titles['general'];

        // For order context, stay on same page (no redirect)
        if ($context === 'order') {
            $action_url = ''; // Empty action = same page
            $action_field = 'ne_mlp_order_prescription_upload';
            $nonce_action = 'ne_mlp_order_prescription_upload';
            $nonce_field = 'ne_mlp_nonce_order';
        } else {
            $action_url = $context === 'checkout' ? wc_get_checkout_url() : admin_url('admin-post.php');
            $action_field = 'ne_mlp_prescription_upload';
            $nonce_action = 'ne_mlp_upload_prescription';
            $nonce_field = 'ne_mlp_nonce';
        }

        $html = '<div class="ne-mlp-upload-box" style="margin:24px 0;padding:20px;border:1px solid #ddd;border-radius:8px;background:#f9f9f9;">';
        $html .= '<h3 style="margin-top:0;color:#333;font-size:18px;font-weight:600;">' . esc_html($title) . '</h3>';

        $html .= '<form method="post" enctype="multipart/form-data" class="ne-mlp-upload-form" action="' . esc_url($action_url) . '">';
        $html .= '<input type="hidden" name="MAX_FILE_SIZE" value="16777216">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr($action_field) . '">';

        if ($order_id) {
            $html .= '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
        }

        wp_nonce_field($nonce_action, $nonce_field);

        // Upload section
        $html .= '<div style="margin-bottom:20px;">';
        $html .= '<label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Upload New Prescription</label>';
        $html .= '<input type="file" class="ne-mlp-prescription-files" name="ne_mlp_prescription_files[]" multiple accept=".jpg,.jpeg,.png,.pdf" style="display:block;width:100%;margin-bottom:8px;padding:8px;border:1px solid #ddd;border-radius:4px;" />';
        $html .= '<div class="ne-mlp-upload-preview" style="margin-top:10px;"></div>';
        $html .= '</div>';

        // Select from existing section
        $dropdown_html = $this->get_prescription_dropdown_html($user_id, null, 'ne_mlp_select_prev_presc');
        if (strpos($dropdown_html, '<select') !== false) {
            $html .= '<div style="margin-bottom:20px;border-top:1px solid #ddd;padding-top:15px;">';
            $html .= '<label style="display:block;font-weight:600;margin-bottom:8px;color:#333;">Or select from your approved/pending prescriptions:</label>';
            $html .= $dropdown_html;
            $html .= '</div>';
        }

        $html .= '<button type="submit" name="ne_mlp_upload_submit" class="button" style="background:#1677ff;color:#fff;border:none;padding:12px 24px;border-radius:4px;font-weight:600;cursor:pointer;">Upload</button>';
        $html .= '<p class="description" style="margin-top:8px;color:#666;font-size:13px;">Allowed: JPG, JPEG, PNG, PDF. Max 4 files, 5MB each.</p>';
        $html .= '</form>';

        // Add live preview and validation JavaScript  
        $html .= '<script>
        jQuery(function($){
            // Mutual exclusion between file upload and dropdown
            $(".ne-mlp-prescription-files").on("change", function(){
                $("select[name=ne_mlp_select_prev_presc]").prop("disabled", this.files.length > 0).val("");
                
                // Live preview functionality
                var preview = $(this).closest("form").find(".ne-mlp-upload-preview");
                preview.empty();
                
                if (this.files && this.files.length > 0) {
                    var totalFiles = this.files.length;
                    var maxFiles = 4;
                    var maxSize = 5 * 1024 * 1024; // 5MB
                    var allowedTypes = ["image/jpeg", "image/jpg", "image/png", "application/pdf"];
                    var errors = [];
                    
                    if (totalFiles > maxFiles) {
                        errors.push("Maximum " + maxFiles + " files allowed");
                    }
                    
                    for (var i = 0; i < totalFiles && i < maxFiles; i++) {
                        var file = this.files[i];
                        
                        // Validate file type
                        if (!allowedTypes.includes(file.type)) {
                            errors.push("Invalid file type: " + file.name);
                            continue;
                        }
                        
                        // Validate file size
                        if (file.size > maxSize) {
                            errors.push("File too large: " + file.name + " (max 5MB)");
                            continue;
                        }
                        
                        // Create preview element
                        var fileDiv = $("<div style=\"display:inline-block;margin:5px;padding:8px;border:1px solid #ddd;border-radius:4px;background:#f9f9f9;text-align:center;width:120px;\">");
                        
                        if (file.type.includes("image")) {
                            // Show image thumbnail
                            var reader = new FileReader();
                            reader.onload = function(e) {
                                var img = $("<img>").attr("src", e.target.result).css({
                                    "max-width": "100px",
                                    "max-height": "80px",
                                    "border-radius": "4px",
                                    "display": "block",
                                    "margin": "0 auto 5px"
                                });
                                fileDiv.prepend(img);
                            };
                            reader.readAsDataURL(file);
                        } else if (file.type === "application/pdf") {
                            // Show PDF icon
                            fileDiv.prepend("<div style=\"font-size:24px;margin-bottom:5px;\">📄</div>");
                        }
                        
                        // Add filename (truncated if too long)
                        var filename = file.name.length > 15 ? file.name.substring(0, 12) + "..." : file.name;
                        fileDiv.append("<div style=\"font-size:11px;color:#666;word-break:break-all;\">" + filename + "</div>");
                        
                        preview.append(fileDiv);
                    }
                    
                    // Show errors if any
                    if (errors.length > 0) {
                        preview.append("<div style=\"color:#ff4d4f;margin-top:10px;font-size:12px;\">" + errors.join("<br>") + "</div>");
                    }
                }
            });
            
            $("select[name=ne_mlp_select_prev_presc]").on("change", function(){
                $(".ne-mlp-prescription-files").prop("disabled", !!this.value);
                if (this.value) {
                    $(this).closest("form").find(".ne-mlp-upload-preview").empty();
                }
            });
        });
        </script>';

        $html .= '</div>';
        return $html;
    }

    /**
     * Update prescription order tracking (simplified)
     * Just updates order_id - removes old attachment, adds new one
     */
    public function update_prescription_order_tracking($prescription_id, $new_order_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // First check if prescription exists
        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $prescription_id
        ));

        if (!$prescription) {
            return new WP_Error('prescription_not_found', 'Prescription not found');
        }

        // Note: Keep old order meta intact - only update prescription table
        // This ensures old orders still show prescription ID on their details page
        // Only the prescription card will show the new order

        // Simple update - just change order_id (no updated_at to avoid column issues)
        $result = $wpdb->update(
            $table,
            ['order_id' => $new_order_id],
            ['id' => $prescription_id],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            $error_message = $wpdb->last_error ? $wpdb->last_error : 'Database update failed';
            return new WP_Error('update_failed', 'Failed to update prescription order tracking: ' . $error_message);
        }

        // Add new order meta
        update_post_meta($new_order_id, '_ne_mlp_prescription_id', $prescription_id);
        update_post_meta($new_order_id, '_ne_mlp_requires_prescription', 'yes');
        update_post_meta($new_order_id, '_ne_mlp_prescription_status', $prescription->status);

        return true;
    }

    /**
     * Get prescription status display HTML (centralized)
     * Format: Attached Prescription ID: Med-11-05-25-12 APPROVED (with proper styling)
     */
    public function get_prescription_status_display($prescription_id, $show_label = true)
    {
        if (!$prescription_id) {
            return '';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $prescription_id
        ));

        if (!$prescription) {
            return '';
        }

        $date = date('d-m-y', strtotime($prescription->created_at));
        $presc_id_format = 'Med-' . $date . '-' . $prescription->id;

        // Status color coding
        $status_colors = [
            'approved' => '#52c41a',
            'pending' => '#faad14',
            'rejected' => '#ff4d4f'
        ];

        $status_color = isset($status_colors[$prescription->status]) ? $status_colors[$prescription->status] : '#666';
        $status_text = strtoupper($prescription->status);

        $html = '';
        if ($show_label) {
            $html .= '<div style="margin:15px 0;padding:12px;background:#f0f9ff;border:1px solid #bae7ff;border-radius:6px;">';
            $html .= '<strong style="color:#1677ff;">Attached Prescription ID:</strong> ';
        }

        $html .= '<span style="color:#333;font-weight:600;">' . esc_html($presc_id_format) . '</span> ';
        $html .= '<span style="color:' . $status_color . ';font-weight:bold;background:rgba(' . $this->hex_to_rgb($status_color) . ',0.1);padding:3px 8px;border-radius:4px;font-size:12px;">' . $status_text . '</span>';

        if ($show_label) {
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Helper function to convert hex color to RGB
     */
    private function hex_to_rgb($hex)
    {
        $hex = str_replace('#', '', $hex);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "$r,$g,$b";
    }

    /**
     * Check if order status allows prescription management
     */
    public function order_status_allows_prescription_management($order_status)
    {
        $allowed_statuses = ['on-hold', 'pending', 'processing'];
        return in_array($order_status, $allowed_statuses);
    }

    /**
     * Add prescription meta to WooCommerce order API response
     * Always gets current status from database, not cached meta
     */
    public function add_prescription_meta_to_order_api($response, $order, $request)
    {
        $order_id = $order->get_id();

        // Check if order requires prescription
        $requires_prescription = $this->order_requires_prescription($order_id);

        // Get prescription ID from order meta
        $prescription_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);

        // Initialize prescription data
        $prescription_data = [
            'requires_prescription' => $requires_prescription,
            '_ne_mlp_requires_prescription' => $requires_prescription ? 'yes' : 'no',
            'prescription_id' => $prescription_id ? $prescription_id : null,
            'prescription_status' => null
        ];

        // If prescription is attached, get current status from database
        if ($prescription_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';
            $prescription = $wpdb->get_row($wpdb->prepare(
                "SELECT status FROM $table WHERE id = %d",
                $prescription_id
            ));

            if ($prescription) {
                // Always use current database status, not cached meta
                $prescription_data['prescription_status'] = $prescription->status;

                // Update order meta to ensure it's current
                update_post_meta($order_id, '_ne_mlp_prescription_status', $prescription->status);
            } else {
                // Prescription not found in database, clean up order meta
                $prescription_data['prescription_status'] = 'not_found';
                delete_post_meta($order_id, '_ne_mlp_prescription_id');
                delete_post_meta($order_id, '_ne_mlp_prescription_status');
            }
        }

        // Add prescription data to API response
        $response->data['prescription_data'] = $prescription_data;

        return $response;
    }

    /**
     * Get current prescription status from database (not cached meta)
     */
    public function get_current_prescription_status($prescription_id)
    {
        if (!$prescription_id) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM $table WHERE id = %d",
            $prescription_id
        ));

        return $prescription ? $prescription->status : null;
    }

    /**
     * Sync prescription status with order meta
     * Ensures order meta always reflects current database status
     */
    public function sync_prescription_status_with_order($order_id)
    {
        $prescription_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);

        if ($prescription_id) {
            $current_status = $this->get_current_prescription_status($prescription_id);

            if ($current_status) {
                // Update order meta with current database status
                update_post_meta($order_id, '_ne_mlp_prescription_status', $current_status);
                return $current_status;
            } else {
                // Prescription not found, clean up order meta
                delete_post_meta($order_id, '_ne_mlp_prescription_id');
                delete_post_meta($order_id, '_ne_mlp_prescription_status');
                return null;
            }
        }

        return null;
    }





    /**
     * Maybe sync prescription status when order is updated
     */
    public function maybe_sync_prescription_status($order, $updated_props)
    {
        if ($order && method_exists($order, 'get_id')) {
            $this->sync_prescription_status_with_order($order->get_id());
        }
    }

    /**
     * Sync prescription status when prescription status meta is requested
     * This ensures the API always gets current database status
     */
    public function sync_prescription_status_on_meta_get($value, $object_id, $meta_key, $single)
    {
        // Only handle prescription status meta requests
        if ($meta_key !== '_ne_mlp_prescription_status') {
            return $value;
        }

        // Only handle for orders (post type shop_order)
        if (get_post_type($object_id) !== 'shop_order') {
            return $value;
        }

        // Get prescription ID
        $prescription_id = get_post_meta($object_id, '_ne_mlp_prescription_id', true);
        if (!$prescription_id) {
            return $value;
        }

        // Get current status from database
        $current_status = $this->get_current_prescription_status($prescription_id);
        if ($current_status) {
            // Update the meta with current status
            update_post_meta($object_id, '_ne_mlp_prescription_status', $current_status);

            // Return the current status
            return $single ? $current_status : [$current_status];
        }

        return $value;
    }
}

// Initialize the singleton
NE_MLP_Prescription_Manager::getInstance();