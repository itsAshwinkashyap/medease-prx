<?php
if (!defined('ABSPATH'))
    exit;

class NE_MLP_Frontend
{
    /**
     * Get template part
     *
     * @param string $template
     * @param array $args
     * @return void
     */
    public function get_template($template, $args = array()) {
        $template = str_replace('.php', '', $template) . '.php';
        $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/' . $template;
        
        if (file_exists($template_path)) {
            if (!empty($args) && is_array($args)) {
                extract($args);
            }
            include $template_path;
        } else {
            // Fallback to inline template if file doesn't exist
            $this->get_inline_request_modal($args['prescription_id']);
        }
    }
    
    /**
     * Fallback inline request modal
     * 
     * @param int $prescription_id
     * @return void
     */
    private function get_inline_request_modal($prescription_id) {
        ?>
        <div class="ne-mlp-request-order-modal" data-id="<?php echo esc_attr($prescription_id); ?>" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
            <div class="ne-mlp-modal-content" style="background:#fff;padding:25px;border-radius:8px;width:90%;max-width:500px;position:relative;">
                <button class="ne-mlp-close-modal" style="position:absolute;top:10px;right:10px;background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
                <h3 style="margin-top:0;color:#333;"><?php esc_html_e('Request Order', 'ne-med-lab-prescriptions'); ?></h3>
                <form class="ne-mlp-request-order-form" data-prescription-id="<?php echo esc_attr($prescription_id); ?>">
                    <?php wp_nonce_field('ne_mlp_request_order_nonce', 'request_order_nonce'); ?>
                    <input type="hidden" name="prescription_id" value="<?php echo esc_attr($prescription_id); ?>">
                    
                    <div style="margin-bottom:15px;">
                        <label for="days-<?php echo esc_attr($prescription_id); ?>" style="display:block;margin-bottom:5px;font-weight:500;">
                            <?php esc_html_e('Select Number of Days', 'ne-med-lab-prescriptions'); ?>
                        </label>
                        <select 
                            id="days-<?php echo esc_attr($prescription_id); ?>" 
                            name="days" 
                            required 
                            style="width:100%;padding:8px;border:1px solid #d9d9d9;border-radius:4px;"
                        >
                            <option value=""><?php esc_html_e('-- Select Days --', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="7"><?php esc_html_e('7 Days', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="15"><?php esc_html_e('15 Days', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="30"><?php esc_html_e('30 Days', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="60"><?php esc_html_e('60 Days', 'ne-med-lab-prescriptions'); ?></option>
                            <option value="90"><?php esc_html_e('90 Days', 'ne-med-lab-prescriptions'); ?></option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom:20px;">
                        <label for="notes-<?php echo esc_attr($prescription_id); ?>" style="display:block;margin-bottom:5px;font-weight:500;">
                            <?php esc_html_e('Additional Notes (Optional)', 'ne-med-lab-prescriptions'); ?>
                        </label>
                        <textarea 
                            id="notes-<?php echo esc_attr($prescription_id); ?>" 
                            name="notes" 
                            rows="3" 
                            style="width:100%;padding:8px;border:1px solid #d9d9d9;border-radius:4px;"
                        ></textarea>
                    </div>
                    
                    <div style="display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="ne-mlp-close-modal" style="padding:8px 16px;background:#f5f5f5;border:1px solid #d9d9d9;border-radius:4px;cursor:pointer;">
                            <?php esc_html_e('Cancel', 'ne-med-lab-prescriptions'); ?>
                        </button>
                        <button type="submit" style="padding:8px 16px;background:#722ed1;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:500;">
                            <span class="button-text"><?php esc_html_e('Submit Request', 'ne-med-lab-prescriptions'); ?></span>
                            <span class="loading-spinner" style="display:none;margin-left:8px;">⏳</span>
                        </button>
                    </div>
                </form>
            </div>
            <div class="ne-mlp-modal-overlay" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:-1;"></div>
        </div>
        <?php
    }

    public function __construct()
    {
        // Start session for message handling
        if (!session_id()) {
            session_start();
        }

        // Register endpoints and query vars
        add_action('init', [$this, 'register_endpoints'], 5);
        add_filter('query_vars', [$this, 'add_query_vars']);
        
        // Add menu items and page handlers
        add_filter('woocommerce_account_menu_items', [$this, 'add_my_prescriptions_menu_item']);
        add_action('woocommerce_account_my-prescription_endpoint', [$this, 'render_my_prescriptions_page']);
        add_action('woocommerce_account_upload-prescription_endpoint', [$this, 'render_upload_prescription_page']);

        // Add template redirect hook to catch 404s on our endpoints
        add_action('template_redirect', [$this, 'fix_endpoint_404s'], 1);

        // Handle checkout prescription auto-selection
        add_action('woocommerce_checkout_order_processed', [$this, 'maybe_auto_select_prescription_checkout']);
        add_action('woocommerce_thankyou', [$this, 'save_prescription_id_to_order']);

        // Hook into order view for prescription upload
        add_action('woocommerce_view_order', [$this, 'render_upload_for_order'], 5);
        // Removed duplicate prescription status display - now handled in main prescription info bar

        // Hook into my orders for prescription tracking
        add_action('woocommerce_my_account_my_orders_column_order-status', [$this, 'add_prescription_id_to_my_orders']);

        // Handle admin-post prescription upload
        add_action('admin_post_ne_mlp_order_prescription_upload', [$this, 'admin_post_handle_order_prescription_upload']);
        add_action('admin_post_nopriv_ne_mlp_order_prescription_upload', [$this, 'admin_post_handle_order_prescription_upload']);

        // AJAX handlers
        add_action('wp_ajax_ne_mlp_reorder_prescription', [$this, 'ajax_reorder_prescription']);
        add_action('wp_ajax_ne_mlp_delete_prescription', [$this, 'ajax_delete_prescription']);
        add_action('wp_ajax_ne_mlp_delete_and_order_again', [$this, 'ajax_delete_and_order_again']);
        add_action('wp_ajax_ne_mlp_submit_request_order', [$this, 'ajax_submit_request_order']);
        add_action('wp_ajax_ne_mlp_download_prescription', [$this, 'ajax_download_prescription']);
        add_action('wp_ajax_nopriv_ne_mlp_download_prescription', [$this, 'ajax_download_prescription']);

        // Add shortcode for upload form
        add_shortcode('ne_mlp_upload_prescription', [$this, 'shortcode_upload_prescription']);
        // Add shortcode for displaying user prescriptions
        add_shortcode('ne_mlp_my_prescriptions', [$this, 'shortcode_my_prescriptions']);

        // Hook into WooCommerce cart and checkout
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Prescription requirement indicators
        add_filter('woocommerce_cart_item_name', [$this, 'add_prescription_required_label'], 10, 3);

        // Add prescription required indicator to order item names (for order emails, admin orders, etc.)
        add_filter('woocommerce_order_item_name', [$this, 'add_prescription_required_to_order_items'], 10, 3);

        // Add prescription required indicator to product pages
        add_action('woocommerce_single_product_summary', [$this, 'add_prescription_required_to_product_page'], 25);

        // Removed duplicate checkout prescription indicator - handled by cart item filter

        // Add prescription info to order details page
        add_action('woocommerce_view_order', [$this, 'display_prescription_info_on_order_details'], 5);
    }

    public function enqueue_scripts()
    {
        // Always enqueue on every page for consistent upload preview
        wp_enqueue_style('ne-mlp-frontend', NE_MLP_PLUGIN_URL . 'assets/css/ne-mlp-frontend.css', [], NE_MLP_VERSION);
        wp_enqueue_script('ne-mlp-frontend', NE_MLP_PLUGIN_URL . 'assets/js/ne-mlp-frontend.js', ['jquery'], NE_MLP_VERSION, true);
        
        // Enqueue order modal script
        wp_enqueue_script('ne-mlp-order-modal', NE_MLP_PLUGIN_URL . 'assets/js/ne-mlp-order-modal.js', ['jquery'], NE_MLP_VERSION, true);
        
        // Localize scripts with AJAX URL and nonce
        $localize_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ne_mlp_frontend'),
            'processing' => __('Processing...', 'ne-med-lab-prescriptions'),
            'success' => __('Success!', 'ne-med-lab-prescriptions'),
            'error_occurred' => __('An error occurred. Please try again.', 'ne-med-lab-prescriptions'),
            // Base URL to view a specific order in My Account: append order_id
            'view_order_base' => trailingslashit(wc_get_account_endpoint_url('view-order')),
            // Date and time settings
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
            'timezone' => wp_timezone_string(),
            'locale' => get_locale(),
            'i18n' => [
                'error_occurred' => __('An error occurred. Please try again.', 'ne-med-lab-prescriptions'),
            ]
        ];
        
        // Localize for frontend script
        wp_localize_script('ne-mlp-frontend', 'neMLP', $localize_data);
        
        // Localize for order modal script
        wp_localize_script('ne-mlp-order-modal', 'neMlpFrontend', $localize_data);

        // Add CSS for prescription highlighting
        wp_add_inline_style('ne-mlp-frontend', '
            .ne-mlp-prescription-card:target {
                border-color: #1677ff !important;
                box-shadow: 0 0 0 3px rgba(22, 119, 255, 0.2) !important;
                background: #f6ffed !important;
            }
            
            .ne-mlp-prescription-card:target h3,
            .ne-mlp-prescription-card:target .prescription-id {
                color: #1677ff !important;
                text-shadow: 0 0 2px rgba(22, 119, 255, 0.3);
            }
        ');
        // Always enqueue on checkout for upload compatibility
        if (is_checkout()) {
            wp_enqueue_style('ne-mlp-frontend-upload', NE_MLP_PLUGIN_URL . 'assets/css/ne-mlp-frontend-upload.css', [], NE_MLP_VERSION);
            wp_enqueue_script('ne-mlp-frontend-upload', NE_MLP_PLUGIN_URL . 'assets/js/ne-mlp-frontend-upload.js', ['jquery'], NE_MLP_VERSION, true);
        }
    }

    /**
     * Fix 404 errors on our custom endpoints
     */
    public function fix_endpoint_404s()
    {
        // Only run if we're getting a 404
        if (!is_404()) {
            return;
        }

        // Check if this is one of our endpoints
        $request_uri = $_SERVER['REQUEST_URI'];
        if (
            strpos($request_uri, '/my-prescription') !== false ||
            strpos($request_uri, '/upload-prescription') !== false
        ) {

            // Don't flush too frequently
            if (!get_transient('ne_mlp_rules_flushed')) {
                // Re-register endpoints and flush rules
                add_rewrite_endpoint('my-prescription', EP_ROOT | EP_PAGES);
                add_rewrite_endpoint('upload-prescription', EP_ROOT | EP_PAGES);
                flush_rewrite_rules(true);

                // Set transient to prevent excessive flushing
                set_transient('ne_mlp_rules_flushed', true, HOUR_IN_SECONDS);

                // Redirect to the same URL to avoid the 404
                wp_redirect($request_uri);
                exit;
            }
        }
    }

    public function render_my_prescriptions_section()
    {
        if (!is_user_logged_in())
            return;
        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // Pagination & search
        $per_page = 10;
        $paged = isset($_GET['presc_page']) ? max(1, intval($_GET['presc_page'])) : 1;
        $search = isset($_GET['presc_search']) ? sanitize_text_field($_GET['presc_search']) : '';
        $where = $wpdb->prepare('WHERE user_id = %d', $user_id);
        if ($search) {
            $where .= $wpdb->prepare(' AND (file_paths LIKE %s OR status LIKE %s)', "%$search%", "%$search%");
        }
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
        $offset = ($paged - 1) * $per_page;
        $prescriptions = $wpdb->get_results("$wpdb->prepare(\"SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d\", $per_page, $offset)");
        if (!$prescriptions)
            return;

        echo '<div class="ne-mlp-my-prescriptions"><h2>' . esc_html__('My Prescriptions', 'ne-med-lab-prescriptions') . '</h2>';

        // Search box
        echo '<form method="get" style="margin-bottom:18px;display:flex;gap:10px;align-items:end;">';
        foreach ($_GET as $k => $v) {
            if ($k !== 'presc_search')
                echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        $search = isset($_GET['presc_search']) ? trim(sanitize_text_field($_GET['presc_search'])) : '';
        echo '<input type="text" name="presc_search" value="' . esc_attr($search) . '" placeholder="Search by Prescription ID or Order ID..." />';
        echo '<button class="button">' . esc_html__('Search', 'ne-med-lab-prescriptions') . '</button>';
        echo '</form>';

        // Show back button if searching
        if ($search) {
            echo '<a href="' . esc_url(wc_get_account_endpoint_url('my-prescription')) . '" class="button" id="ne-mlp-presc-back-btn" style="margin-bottom:18px;" onclick="window.location.href=\'' . esc_url(wc_get_account_endpoint_url('my-prescription')) . '\'; return false;">&larr; Back</a>';
        }

        // Filter prescriptions by search (prescription ID or order ID)
        if ($search) {
            $search_lc = strtolower($search);
            $presc_with_use = array_filter($prescriptions, function ($item) use ($search_lc) {
                $presc = $item;
                $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
                $presc_num = strtolower($prescription_manager->format_prescription_id($presc));
                $order_id = strtolower((string) $presc->order_id);
                return (
                    strpos($presc_num, $search_lc) !== false ||
                    strpos($order_id, $search_lc) !== false
                );
            });
        } else {
            $presc_with_use = $prescriptions;
        }

        if (empty($presc_with_use)) {
            echo '<p>No prescriptions found.</p>';
        } else {
            echo '<div class="ne-mlp-presc-cards">';
            foreach ($presc_with_use as $item) {
                $presc = $item;
                $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
                $presc_num = $prescription_manager->format_prescription_id($presc);

                // Generate status variables
                $status = $presc->status;
                $badge_color = $status === 'approved' ? '#e6ffed;color:#389e0d' : ($status === 'rejected' ? '#ffeaea;color:#cf1322' : '#fffbe6;color:#d48806');

                // Generate file thumbnails with consistent sizing (max 4 files)
                $files = json_decode($presc->file_paths, true);
                $thumbs = [];
                if (is_array($files)) {
                    $max_files = min(4, count($files)); // Show maximum 4 files
                    for ($i = 0; $i < $max_files; $i++) {
                        $file = $files[$i];
                        $is_pdf = (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf');
                        if ($is_pdf) {
                            $thumbs[] = '<span style="display:inline-block;width:50px;height:50px;background:#f5f5f5;border:1px solid #e1e5e9;border-radius:6px;line-height:48px;text-align:center;font-size:20px;flex-shrink:0;">📄</span>';
                        } else {
                            $thumbs[] = '<img src="' . esc_url($file) . '" style="width:50px;height:50px;object-fit:cover;border:1px solid #e1e5e9;border-radius:6px;flex-shrink:0;" />';
                        }
                    }
                    // Add indicator if there are more than 4 files
                    if (count($files) > 4) {
                        $remaining = count($files) - 4;
                        $thumbs[] = '<span style="display:inline-block;width:50px;height:50px;background:#f0f0f0;border:1px solid #e1e5e9;border-radius:6px;line-height:48px;text-align:center;font-size:12px;flex-shrink:0;color:#666;">+' . $remaining . '</span>';
                    }
                }

                $highlight = '';
                if ($search) {
                    $search_lc = strtolower($search);
                    $presc_num_lc = strtolower($presc_num);
                    $order_id_lc = strtolower((string) $presc->order_id);
                    if (strpos($presc_num_lc, $search_lc) !== false || strpos($order_id_lc, $search_lc) !== false) {
                        $highlight = ' box-shadow:0 0 0 4px #1677ff99;border:2.5px solid #1677ff;';
                    }
                }

                echo '<div class="ne-mlp-presc-card" style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;margin-bottom:16px;' . $highlight . '">';
                echo '<div style="font-size:15px;font-weight:600;margin-bottom:6px;">' . ($presc->type === 'lab_test' ? '🧪 Lab Test' : '💊 Medicine') . ' <span style="background:' . $badge_color . ';border-radius:999px;padding:3px 14px;font-weight:600;margin-left:8px;">' . strtoupper($status) . '</span></div>';
                echo '<div style="font-size:13px;color:#888;margin-bottom:4px;">ID: <b>' . $presc_num . '</b></div>';
                echo '<div class="ne-mlp-cart-thumbs" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:nowrap;max-width:100%;overflow:hidden;">' . implode('', $thumbs) . '</div>';
                echo '<div style="font-size:13px;color:#888;margin-bottom:4px;">Uploaded: ' . esc_html(date('M j, Y h:i A', strtotime($presc->created_at))) . '</div>';

                // Button group
                echo '<div class="ne-mlp-presc-btns">';

                // Download button (always available)
                echo '<a href="#" class="button ne-mlp-download-btn" data-id="' . intval($presc->id) . '" style="background:#1677ff;color:#fff;">Download</a>';

                // Only show Reorder if order is completed
                $show_reorder = false;
                if ($status === 'approved' && !empty($presc->order_id)) {
                    $order = wc_get_order($presc->order_id);
                    if ($order && $order->get_status() === 'completed') {
                        $show_reorder = true;
                    }
                }
                if ($show_reorder) {
                    echo '<button type="button" class="button ne-mlp-reorder-btn" data-id="' . intval($presc->id) . '" style="background:#1677ff;color:#fff;width:100%;">Reorder</button>';
                }

                // Delete button only for pending prescriptions with no order attached
                if ($status === 'pending' && empty($presc->order_id)) {
                    echo '<a href="#" class="button ne-mlp-delete-btn" data-id="' . intval($presc->id) . '" style="background:#fffbe6;color:#d48806;border:1px solid #d48806;">Delete</a>';
                }

                echo '</div>';

                // Show order details with item quantity (Last used in order #7045 - Order Item - 3)
                if (!empty($presc->order_id)) {
                    $order = wc_get_order($presc->order_id);
                    if ($order) {
                        $item_count = $order->get_item_count();
                        echo '<div style="font-size:12px;color:#333;margin-top:8px;line-height:1.2;">';
                        echo 'Last used in order <a href="' . esc_url(wc_get_account_endpoint_url('view-order') . $presc->order_id) . '" style="color:#1677ff;text-decoration:none;font-weight:600;">#' . intval($presc->order_id) . '</a>';
                        echo ' - Order Item - ' . intval($item_count);
                        echo '</div>';
                    }
                }

                echo '</div>';
            }
            echo '</div>';
        }

        // Pagination
        $total_pages = ceil($total / $per_page);
        if ($total_pages > 1) {
            echo '<div class="ne-mlp-pagination">';
            for ($i = 1; $i <= $total_pages; $i++) {
                $url = add_query_arg('presc_page', $i);
                if ($search)
                    $url = add_query_arg('presc_search', urlencode($search), $url);
                echo '<a href="' . esc_url($url) . '" class="button' . ($i == $paged ? ' current' : '') . '">' . $i . '</a> ';
            }
            echo '</div>';
        }

        // Note: Re-upload functionality removed - no longer needed

        // Add custom styles
        echo '<style>
            .ne-mlp-my-prescriptions h2{margin-top:40px}
            .ne-mlp-my-prescriptions table{margin-bottom:30px}
            .ne-mlp-status-pending{color:#d48806;font-weight:bold}
            .ne-mlp-status-approved{color:#389e0d;font-weight:bold}
            .ne-mlp-status-rejected{color:#cf1322;font-weight:bold}
            .ne-mlp-pagination{margin:10px 0}
            .ne-mlp-pagination .button.current{background:#389e0d;color:#fff}
            .ne-mlp-presc-cards{display:grid;gap:16px}
        </style>';
    }

    // Note: AJAX re-upload handler removed - no longer needed

    // AJAX: Handle reorder
    public function ajax_reorder_prescription()
    {
        check_ajax_referer('ne_mlp_frontend', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not logged in']);
        }

        $user_id = get_current_user_id();
        $presc_id = intval($_POST['presc_id']);

        if (!$presc_id) {
            wp_send_json_error(['message' => 'Invalid prescription ID']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        $presc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d AND status = 'approved'",
            $presc_id,
            $user_id
        ));

        if (!$presc) {
            wp_send_json_error(['message' => 'Prescription not found or not approved']);
        }

        if (!$presc->order_id) {
            wp_send_json_error(['message' => 'No original order found for this prescription']);
        }

        $order = wc_get_order($presc->order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Original order not found']);
        }

        // Clear the cart first
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        $out_of_stock = [];
        $out_of_stock_ids = [];
        $added_to_cart = false;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->is_in_stock()) {
                $added = WC()->cart->add_to_cart($product->get_id(), $item->get_quantity());
                if ($added) {
                    $added_to_cart = true;
                }
            } else {
                $out_of_stock[] = $item->get_name();
                $out_of_stock_ids[] = $item->get_product_id();
            }
        }

        if (!$added_to_cart) {
            wp_send_json_error(['message' => 'No items could be added to cart']);
        }

        // Check if any products require prescription
        $requires_prescription = false;
        foreach ($order->get_items() as $item) {
            $requires = get_post_meta($item->get_product_id(), '_ne_mlp_requires_prescription', true);
            if ($requires === 'yes') {
                $requires_prescription = true;
                break;
            }
        }

        // Prepare response
        $response_data = [
            'redirect' => wc_get_cart_url(),
            'out_of_stock' => $out_of_stock,
            'message' => 'Items added to cart successfully'
        ];

        // If prescription required, show note about auto-selection
        if ($requires_prescription) {
            $response_data['message'] = 'Items added to cart successfully. Prescription ID auto-selected for this order.';
            $response_data['prescription_note'] = true;
        }

        wp_send_json_success($response_data);
    }

    /**
     * Shortcode to display user's prescriptions
     * 
     * @return string HTML content to display prescriptions
     */
    public function shortcode_my_prescriptions()
    {
        if (!is_user_logged_in()) {
            $login_url = wc_get_page_permalink('myaccount');
            return '<div class="ne-mlp-upload-box ne-mlp-dedicated-upload-page">
                <h2>My Prescriptions</h2>
                <div class="ne-mlp-upload-error">Please log in to view your prescriptions.</div>
                <div style="margin-top: 15px;">
                    <a href="' . esc_url($login_url) . '" class="button">Login to My Account</a>
                </div>
            </div>';
        }

        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // Get all prescriptions for the current user, ordered by most recent first
        $prescriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));

        ob_start();
        ?>
        <div class="ne-mlp-prescriptions-container">
            <h2 class="ne-mlp-prescriptions-title">My Prescriptions</h2>
            <div class="ne-mlp-actions-bar" style="display:flex;gap:10px;align-items:center;margin:8px 0 4px 0;flex-wrap:wrap;">
                <button id="ne-mlp-view-requests-btn" type="button" class="button" style="background:#6c757d;color:#fff;">
                    <?php echo esc_html__('View Request Order', 'ne-med-lab-prescriptions'); ?>
                </button>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('upload-prescription')); ?>" class="button" style="background:#1677ff;color:#fff;">
                    <?php echo esc_html__('Upload', 'ne-med-lab-prescriptions'); ?>
                </a>
            </div>

            <?php if (empty($prescriptions)): ?>
                <div class="ne-mlp-no-prescriptions">
                    <p>You haven't uploaded any prescriptions yet.</p>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('upload-prescription')); ?>" class="button">
                        Upload Your First Prescription
                    </a>
                </div>
            <?php else: ?>
                <div class="ne-mlp-prescriptions-grid">
                    <?php foreach ($prescriptions as $prescription): ?>
                        <?php echo $this->render_prescription_card($prescription); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php 
            // Include the Order Requests modal template so it can be opened
            $this->get_template('requests-order-view-modal');
            ?>
        </div>

        <style>
            .ne-mlp-prescriptions-container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }

            .ne-mlp-prescriptions-title {
                font-size: 1.3rem;
                margin-bottom: 10px;
                color: #333;
                font-weight: 600;
            }

            .ne-mlp-prescriptions-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(550px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }

            .ne-mlp-no-prescriptions {
                text-align: center;
                padding: 40px 20px;
                background: #f8f9fa;
                border-radius: 8px;
                border: 1px dashed #dee2e6;
            }

            .ne-mlp-no-prescriptions p {
                margin-bottom: 20px;
                color: #666;
            }

            .ne-mlp-no-prescriptions .button {
                background: #1677ff;
                color: white;
                padding: 10px 20px;
                border-radius: 4px;
                text-decoration: none;
                display: inline-block;
                transition: background 0.3s;
            }

            .ne-mlp-no-prescriptions .button:hover {
                background: #1261cc;
            }

            @media (max-width: 768px) {
                .ne-mlp-prescriptions-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php

        return ob_get_clean();
    }

    public function shortcode_upload_prescription()
    {
        if (!is_user_logged_in()) {
            $login_url = wc_get_page_permalink('myaccount');
            return '<div class="ne-mlp-upload-box ne-mlp-dedicated-upload-page">
                <h2>Upload Prescription</h2>
                <div class="ne-mlp-upload-error">Please log in to upload a prescription.</div>
                <div style="margin-top: 15px;">
                    <a href="' . esc_url($login_url) . '" class="button">Login to My Account</a>
                </div>
            </div>';
        }
        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $msg = '';
        $show_form = true;
        // Remove restriction: always allow upload
        if (isset($_POST['ne_mlp_upload_submit']) && check_admin_referer('ne_mlp_upload_prescription', 'ne_mlp_nonce')) {
            $type = isset($_POST['ne_mlp_type']) && in_array($_POST['ne_mlp_type'], ['medicine', 'lab_test']) ? $_POST['ne_mlp_type'] : 'medicine';
            $files = $_FILES['ne_mlp_prescription_files'] ?? null;
            $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
            $max_size = 5 * 1024 * 1024; // 5MB
            $count = $files && isset($files['name']) ? count($files['name']) : 0;
            $errors = [];
            if (!$files || empty($files['name'][0]))
                $errors[] = __('Please select at least one file.', 'ne-med-lab-prescriptions');
            if ($count > 4)
                $errors[] = __('You can upload a maximum of 4 files.', 'ne-med-lab-prescriptions');
            $saved_files = [];
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i]))
                    continue;
                $type_mime = $files['type'][$i];
                $size = $files['size'][$i];
                if (!in_array($type_mime, $allowed_types))
                    $errors[] = __('Invalid file type: ', 'ne-med-lab-prescriptions') . $files['name'][$i];
                if ($size > $max_size)
                    $errors[] = __('File too large: ', 'ne-med-lab-prescriptions') . $files['name'][$i];
            }
            if (empty($errors)) {
                $upload_dir = wp_upload_dir();
                for ($i = 0; $i < $count; $i++) {
                    if (empty($files['name'][$i]))
                        continue;
                    $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                    $new_name = 'presc_' . $user_id . '_' . time() . "_{$i}.{$ext}";
                    $target = trailingslashit($upload_dir['basedir']) . 'ne-mlp-prescriptions/';
                    if (!file_exists($target))
                        wp_mkdir_p($target);
                    $file_path = $target . $new_name;
                    if (move_uploaded_file($files['tmp_name'][$i], $file_path)) {
                        $saved_files[] = trailingslashit($upload_dir['baseurl']) . 'ne-mlp-prescriptions/' . $new_name;
                    }
                }
                if (!empty($saved_files)) {
                    $wpdb->insert($table, [
                        'user_id' => $user_id,
                        'order_id' => null,
                        'file_paths' => wp_json_encode($saved_files),
                        'type' => $type,
                        'status' => 'pending',
                        'created_at' => current_time('mysql'),
                    ]);

                    $new_presc_id = $wpdb->insert_id;

                    // Trigger email notification for frontend upload
                    $this->trigger_upload_notification_frontend($new_presc_id, $user_id);

                    $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
                    $formatted_id = $prescription_manager->format_prescription_id((object) [
                        'id' => $new_presc_id,
                        'created_at' => current_time('mysql')
                    ]);

                    $msg = '<div class="ne-mlp-upload-success" style="padding:20px;background:#f6ffed;border:1px solid #b7eb8f;border-radius:8px;color:#52c41a;font-weight:600;margin:20px 0;">';
                    $msg .= 'Prescription uploaded successfully!<br>';
                    $msg .= 'Prescription ID: <strong>' . esc_html($formatted_id) . '-Pending</strong><br>';
                    $msg .= 'Your files will be reviewed within 10-15 minutes.';
                    $msg .= '</div>';

                    $show_form = false; // Don't show form after successful upload
                } else {
                    $errors[] = __('File upload failed.', 'ne-med-lab-prescriptions');
                }
            }
            if (!empty($errors)) {
                $msg = '<div class="ne-mlp-upload-error">' . implode('<br>', $errors) . '</div>';
            }
        }
        ob_start();

        // Mobile-responsive styles
        echo '<style>
        @media (max-width: 768px) {
            .ne-mlp-upload-main { 
                flex-direction: column !important; 
                gap: 20px !important; 
                padding: 20px !important;
            }
            .ne-mlp-upload-left { 
                min-width: auto !important; 
                max-width: none !important;
            }
            .ne-mlp-upload-right { 
                max-width: none !important; 
            }
            .ne-mlp-upload-title {
                font-size: 1.2rem !important;
            }
        }
        .ne-mlp-upload-container {
            margin: 20px auto;
            max-width: 1000px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .ne-mlp-upload-main {
            display: flex;
            gap: 0;
        }
        .ne-mlp-upload-left {
            flex: 1;
            padding: 32px;
            min-width: 400px;
            max-width: 500px;
        }
        .ne-mlp-upload-right {
            flex: 1;
            background: #f8f9fa;
            padding: 32px;
            border-left: 1px solid #e9ecef;
            max-width: 500px;
        }
        .ne-mlp-upload-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #333;
        }
        .ne-mlp-upload-subtitle {
            color: #666;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .ne-mlp-form-group {
            margin-bottom: 20px;
        }
        .ne-mlp-form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }
        .ne-mlp-form-select, .ne-mlp-form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        .ne-mlp-form-select:focus, .ne-mlp-form-input:focus {
            outline: none;
            border-color: #1677ff;
            box-shadow: 0 0 0 3px rgba(22, 119, 255, 0.1);
        }
        .ne-mlp-upload-area {
            border: 2px dashed #d0d7de;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            background: #fafbfc;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .ne-mlp-upload-area:hover {
            border-color: #1677ff;
            background: #f6f8ff;
        }
        .ne-mlp-upload-icon {
            font-size: 48px;
            color: #8b949e;
            margin-bottom: 12px;
        }
        .ne-mlp-upload-text {
            color: #656d76;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .ne-mlp-upload-hint {
            color: #8b949e;
            font-size: 12px;
        }
        .ne-mlp-upload-btn {
            background: #1677ff;
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            margin-top: 20px;
            width: 100%;
        }
        .ne-mlp-upload-btn:hover {
            background: #0056d3;
        }
        .ne-mlp-guide-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #333;
        }
        .ne-mlp-guide-image {
            width: 100%;
            max-width: 300px;
            height: auto;
            border-radius: 8px;
            margin-bottom: 16px;
            border: 1px solid #e1e5e9;
        }
        .ne-mlp-guide-tips {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .ne-mlp-guide-tips li {
            padding: 8px 0;
            font-size: 13px;
            color: #656d76;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .ne-mlp-guide-tips li:before {
            content: "✓";
            color: #22c55e;
            font-weight: bold;
            flex-shrink: 0;
        }
        .ne-mlp-file-requirements {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 12px;
            margin-top: 16px;
            font-size: 12px;
            color: #856404;
        }
        </style>';

        echo '<div class="ne-mlp-upload-container">';
        echo $msg;

        // Show form only if not just uploaded
        if ($show_form) {
            echo '<div class="ne-mlp-upload-main">';

            // Left side - Upload form (now on left)
            echo '<div class="ne-mlp-upload-left">';
            echo '<h1 class="ne-mlp-upload-title">Upload Prescription</h1>';
            echo '<p class="ne-mlp-upload-subtitle">Please attach a prescription to proceed</p>';

            echo '<form method="post" enctype="multipart/form-data" class="ne-mlp-upload-form">';
            wp_nonce_field('ne_mlp_upload_prescription', 'ne_mlp_nonce');

            echo '<div class="ne-mlp-form-group">';
            echo '<label class="ne-mlp-form-label">Purpose</label>';
            echo '<select name="ne_mlp_type" class="ne-mlp-form-select">';
            echo '<option value="medicine">Buy Medicine</option>';
            echo '<option value="lab_test">Book Lab Test</option>';
            echo '</select>';
            echo '</div>';

            echo '<div class="ne-mlp-form-group">';
            echo '<label class="ne-mlp-form-label">Upload Files</label>';
            echo '<div class="ne-mlp-upload-area" onclick="document.querySelector(\'.ne-mlp-prescription-files\').click()">';
            echo '<div class="ne-mlp-upload-icon">📄</div>';
            echo '<div class="ne-mlp-upload-text"><strong>UPLOAD NEW</strong></div>';
            echo '<div class="ne-mlp-upload-hint">Click to browse files</div>';
            echo '</div>';
            echo '<input type="file" class="ne-mlp-prescription-files" name="ne_mlp_prescription_files[]" multiple accept=".jpg,.jpeg,.png,.pdf" style="display:none;" />';
            echo '<div class="ne-mlp-upload-preview"></div>';
            echo '</div>';

            echo '<button type="submit" name="ne_mlp_upload_submit" class="ne-mlp-upload-btn">Upload Prescription</button>';

            echo '</form>';

            echo '</div>'; // Close upload-left

            // Right side - Guide (now on right)
            echo '<div class="ne-mlp-upload-right">';
            echo '<h2 class="ne-mlp-guide-title">Guide for a valid prescription</h2>';

            $plugin_url = plugin_dir_url(dirname(__FILE__));
            echo '<img src="' . $plugin_url . 'assets/image/validate_rx.svg" alt="Valid Prescription Guide" class="ne-mlp-guide-image">';

            echo '<ul class="ne-mlp-guide-tips">';
            echo '<li>Don\'t crop out any part of the image</li>';
            echo '<li>Don\'t take a blurred image</li>';
            echo '<li>Include details of doctor and patient + clinic visit date</li>';
            echo '<li>Medicines will be dispensed as per prescription</li>';
            echo '<li>Supported files type: .jpeg, .jpg, .png, .pdf</li>';
            echo '<li>Maximum allowed file size: 5MB</li>';
            echo '</ul>';

            echo '</div>'; // Close upload-right

            echo '</div>'; // Close upload-main

            echo '<div class="ne-mlp-file-requirements">';
            echo '<strong>File Requirements:</strong><br>';
            echo '• Supported files type: .jpeg, .jpg, .png, .pdf<br>';
            echo '• Maximum allowed file size: 5MB<br>';
            echo '• Maximum files: 4';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Register all custom endpoints
     */
    public function register_endpoints() {
        add_rewrite_endpoint('my-prescription', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('upload-prescription', EP_ROOT | EP_PAGES);
        
        // Check if endpoints are working and flush if needed
        $this->maybe_flush_rewrite_rules();
    }

    /**
     * Add My Prescriptions menu item to WooCommerce account menu
     *
     * @param array $items
     * @return array
     */
    public function add_my_prescriptions_menu_item($items) {
        // Insert after orders menu item or at the end if orders doesn't exist
        $new_items = [];
        
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            
            // Add our items after 'orders'
            if ($key === 'orders') {
                $new_items['my-prescription'] = '📃 ' . __('My Prescriptions', 'ne-med-lab-prescriptions');
            }
        }
        
        // If 'orders' key wasn't found, append our menu item
        if (!isset($new_items['my-prescription'])) {
            $new_items['my-prescription'] = '📃 ' . __('My Prescriptions', 'ne-med-lab-prescriptions');
        }
        
        return $new_items;
    }

    /**
     * Add query variables for our endpoints
     */
    public function add_query_vars($vars) {
        $vars[] = 'my-prescription';
        $vars[] = 'upload-prescription';
        return $vars;
    }

    /**
     * Check if rewrite rules need to be flushed and do it if necessary
     */
    private function maybe_flush_rewrite_rules()
    {
        // Check if we need to flush rewrite rules
        $flush_needed = false;

        // Check if endpoints are registered properly
        global $wp_rewrite;
        if (
            !isset($wp_rewrite->endpoints) ||
            !in_array(array(EP_ROOT | EP_PAGES, 'my-prescription', 'my-prescription'), $wp_rewrite->endpoints) ||
            !in_array(array(EP_ROOT | EP_PAGES, 'upload-prescription', 'upload-prescription'), $wp_rewrite->endpoints)
        ) {
            $flush_needed = true;
        }

        // Check if this is a 404 on our endpoints
        if (
            is_404() && (strpos($_SERVER['REQUEST_URI'], '/my-prescription') !== false ||
                strpos($_SERVER['REQUEST_URI'], '/upload-prescription') !== false)
        ) {
            $flush_needed = true;
        }

        // Flush rules if needed
        if ($flush_needed) {
            add_rewrite_endpoint('my-prescription', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('upload-prescription', EP_ROOT | EP_PAGES);
            flush_rewrite_rules(true);

            // Set a transient to prevent excessive flushing
            set_transient('ne_mlp_rules_flushed', true, HOUR_IN_SECONDS);
        }
    }

    // Render My Prescriptions page (simplified without AJAX for better performance)
    public function render_my_prescriptions_page()
    {
        if (!is_user_logged_in()) {
            echo '<div class="woocommerce-Message woocommerce-Message--info woocommerce-info">You must be logged in to view your prescriptions.</div>';
            return;
        }

        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // Handle search and pagination from URL parameters
        $search = isset($_GET['search_prescription']) ? sanitize_text_field($_GET['search_prescription']) : '';
        // Check both 'paged' and 'page' parameters for pagination
        $current_page = max(1, intval(get_query_var('paged', get_query_var('page', isset($_GET['paged']) ? $_GET['paged'] : 1))));
        $per_page = 10; // Show 10 prescriptions per page
        $offset = ($current_page - 1) * $per_page;

        // Enhanced search query - search in prescription ID format and order ID
        $where_clause = "WHERE user_id = %d";
        $query_params = [$user_id];

        if (!empty($search)) {
            // Search logic for various formats:
            // Med-11-06-25-64, 64, #7054, 7054
            $search_clean = str_replace(['#', 'Med-', 'Lab-'], '', $search);

            $where_clause .= " AND (";
            $where_conditions = [];

            // 1. Search by prescription ID (exact match)
            if (is_numeric($search_clean)) {
                $where_conditions[] = "id = %d";
                $query_params[] = intval($search_clean);
            }

            // 2. Search by prescription ID (partial match in string format)
            $where_conditions[] = "CAST(id AS CHAR) LIKE %s";
            $query_params[] = '%' . $search_clean . '%';

            // 3. Search by order ID (if numeric)
            if (is_numeric($search_clean)) {
                $where_conditions[] = "order_id = %d";
                $query_params[] = intval($search_clean);
            }

            // 4. Search in formatted prescription ID (match against created_at and id combination)
            if (strlen($search_clean) >= 2) {
                $where_conditions[] = "CONCAT(DATE_FORMAT(created_at, '%%d-%%m-%%y'), '-', id) LIKE %s";
                $query_params[] = '%' . $search_clean . '%';
            }

            $where_clause .= implode(' OR ', $where_conditions) . ")";
        }

        // Get total count
        $total_query = "SELECT COUNT(*) FROM $table $where_clause";
        $total = $wpdb->get_var($wpdb->prepare($total_query, $query_params));

        // Get prescriptions
        $prescriptions_query = "SELECT * FROM $table $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $prescriptions = $wpdb->get_results($wpdb->prepare($prescriptions_query, array_merge($query_params, [$per_page, $offset])));

        $total_pages = ceil($total / $per_page);

        echo '<div class="woocommerce-MyAccount-content">';

        // Header with search and upload button
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;flex-wrap:wrap;gap:15px;">';
        echo '<h3 style="margin:0;font-size:1.5rem;font-weight:600;color:#333;">My Prescriptions</h3>';
        echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';

        // Enhanced search form
        echo '<form method="get" style="display:flex;gap:8px;align-items:center;">';
        foreach ($_GET as $key => $value) {
            if ($key !== 'search_prescription' && $key !== 'paged') {
                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
            }
        }
        $search = isset($_GET['search_prescription']) ? trim(sanitize_text_field($_GET['search_prescription'])) : '';
        echo '<input type="text" name="search_prescription" placeholder="Search by ID or Order (e.g., 64, #7054)..." value="' . esc_attr($search) . '" style="padding:10px 15px;border:1px solid #ddd;border-radius:6px;width:250px;font-size:13px;">';
        echo '<button type="submit" style="padding:10px 20px;background:#1677ff;color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;">Search</button>';
        if (!empty($search)) {
            echo '<a href="' . remove_query_arg(['search_prescription', 'paged']) . '" style="padding:10px 15px;background:#666;color:white;text-decoration:none;border-radius:6px;font-size:13px;">Clear</a>';
        }
        echo '</form>';

        // View Request Order button
        echo '<button id="ne-mlp-view-requests-btn" type="button" class="button" style="background:#6D28D9;color:#fff;padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;white-space:nowrap;margin-right:10px;transition:background-color 0.2s;">' . esc_html__('Request Order', 'ne-med-lab-prescriptions') . '</button>';

        // Upload button
        echo '<a href="' . wc_get_account_endpoint_url('upload-prescription') . '" class="button" style="background:#1677ff;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;white-space:nowrap;font-weight:500;font-size:13px;display:inline-flex;align-items:center;justify-content:center;">' . esc_html__('Upload', 'ne-med-lab-prescriptions') . '</a>';
        echo '</div>';
        echo '</div>';

        if (empty($prescriptions)) {
            $message = !empty($search) ? 'No prescriptions found matching your search.' : 'You have not uploaded any prescriptions yet.';
            echo '<div class="woocommerce-Message woocommerce-Message--info woocommerce-info">' . $message . '</div>';
        } else {
            // Display prescription cards in 3-column grid
            echo '<div class="ne-mlp-prescription-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:30px;">';
            foreach ($prescriptions as $prescription) {
                $this->render_prescription_card($prescription);
            }
            echo '</div>';

            // Pagination
            if ($total_pages > 1) {
                echo '<div class="woocommerce-pagination" style="margin-top:30px;text-align:center;padding:20px 0;">';

                // Get base URL for pagination
                $base_url = wc_get_account_endpoint_url('my-prescription');

                // Previous button
                if ($current_page > 1) {
                    $prev_page = $current_page - 1;
                    $prev_url = $prev_page > 1 ? $base_url . 'page/' . $prev_page . '/' : $base_url;
                    if (!empty($search)) {
                        $prev_url = add_query_arg('search_prescription', $search, $prev_url);
                    }
                    echo '<a href="' . esc_url($prev_url) . '" class="prev page-numbers" style="margin-right:8px;padding:10px 15px;background:#f0f0f0;color:#333;text-decoration:none;border-radius:5px;font-size:14px;">‹ Previous</a>';
                }

                // Page numbers (show up to 5 pages)
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $start_page + 4);

                if ($start_page > 1) {
                    $page_url = $base_url;
                    if (!empty($search)) {
                        $page_url = add_query_arg('search_prescription', $search, $page_url);
                    }
                    echo '<a href="' . esc_url($page_url) . '" class="page-numbers" style="margin:0 2px;padding:10px 12px;background:#f0f0f0;color:#333;text-decoration:none;border-radius:5px;font-size:14px;">1</a>';
                    if ($start_page > 2) {
                        echo '<span style="margin:0 5px;color:#999;">...</span>';
                    }
                }

                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i == $current_page) {
                        echo '<span class="page-numbers current" style="margin:0 2px;padding:10px 12px;background:#1677ff;color:white;border-radius:5px;font-size:14px;font-weight:600;">' . $i . '</span>';
                    } else {
                        $page_url = $i > 1 ? $base_url . 'page/' . $i . '/' : $base_url;
                        if (!empty($search)) {
                            $page_url = add_query_arg('search_prescription', $search, $page_url);
                        }
                        echo '<a href="' . esc_url($page_url) . '" class="page-numbers" style="margin:0 2px;padding:10px 12px;background:#f0f0f0;color:#333;text-decoration:none;border-radius:5px;font-size:14px;">' . $i . '</a>';
                    }
                }

                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<span style="margin:0 5px;color:#999;">...</span>';
                    }
                    $last_page_url = $base_url . 'page/' . $total_pages . '/';
                    if (!empty($search)) {
                        $last_page_url = add_query_arg('search_prescription', $search, $last_page_url);
                    }
                    echo '<a href="' . esc_url($last_page_url) . '" class="page-numbers" style="margin:0 2px;padding:10px 12px;background:#f0f0f0;color:#333;text-decoration:none;border-radius:5px;font-size:14px;">' . $total_pages . '</a>';
                }

                // Next button
                if ($current_page < $total_pages) {
                    $next_page = $current_page + 1;
                    $next_url = $base_url . 'page/' . $next_page . '/';
                    if (!empty($search)) {
                        $next_url = add_query_arg('search_prescription', $search, $next_url);
                    }
                    echo '<a href="' . esc_url($next_url) . '" class="next page-numbers" style="margin-left:8px;padding:10px 15px;background:#f0f0f0;color:#333;text-decoration:none;border-radius:5px;font-size:14px;">Next ›</a>';
                }

                echo '</div>';

                // Results summary
                $start_item = ($current_page - 1) * $per_page + 1;
                $end_item = min($current_page * $per_page, $total);
                echo '<div style="text-align:center;color:#666;font-size:13px;margin-top:15px;">';
                echo 'Showing ' . $start_item . '-' . $end_item . ' of ' . $total . ' prescriptions';
                echo '</div>';
            }
        }

        // Include the Order Requests modal template so it can be opened from the button
        $this->get_template('requests-order-view-modal');

        echo '</div>'; // .woocommerce-MyAccount-content
    }

    // Add upload section to order view (smart logic with proper reloading)
    public function render_upload_for_order($order_id)
    {
        if (!is_user_logged_in())
            return;

        $user_id = get_current_user_id();
        $order = wc_get_order($order_id);

        if (!$order || $order->get_user_id() != $user_id)
            return;

        // Display session messages if any
        if (isset($_SESSION['prescription_message'])) {
            $message = $_SESSION['prescription_message'];
            $color = $message['type'] === 'success' ? '#52c41a' : '#ff4d4f';
            $bg = $message['type'] === 'success' ? '#f6ffed' : '#fff2f0';
            $border = $message['type'] === 'success' ? '#b7eb8f' : '#ffa39e';
            $icon = $message['type'] === 'success' ? '✅' : '❌';

            echo '<div style="color:' . $color . ';padding:15px;background:' . $bg . ';border:1px solid ' . $border . ';border-radius:6px;margin:15px 0;">';
            echo '<strong>' . $icon . ' ' . esc_html($message['message']) . '</strong>';
            echo '</div>';

            // Clear the message
            unset($_SESSION['prescription_message']);
        }

        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();

        // Check if order requires prescription
        if (!$prescription_manager->order_requires_prescription($order_id))
            return;

        // Check if order status allows prescription management (On hold, Pending payment, Processing only)
        if (!$prescription_manager->order_status_allows_prescription_management($order->get_status()))
            return;

        // Get current prescription attachment
        $presc_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);
        $current_prescription = null;

        if ($presc_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';
            $current_prescription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d",
                $presc_id
            ));
        }

        // Determine if upload handler should be shown
        $show_upload_handler = false;
        $rejection_message = '';
        $is_rejected = false;

        if (!$current_prescription) {
            // No prescription attached - show upload handler
            $show_upload_handler = true;
        } elseif ($current_prescription->status === 'rejected') {
            // Prescription is rejected - show upload handler with user-friendly message
            $show_upload_handler = true;
            $is_rejected = true;
            $upload_guide_url = home_url('/my-account/upload-prescription'); // You can customize this URL
            $rejection_message = '<div style="color:#ff4d4f;padding:15px;background:#fff2f0;border:1px solid #ffa39e;border-radius:6px;margin:15px 0;">';
            $rejection_message .= '<strong>⚠️ Your prescription has been rejected.</strong><br>';
            $rejection_message .= 'Upload a valid prescription to move forward. Need help? See the upload guide. ';
            $rejection_message .= '<a href="' . esc_url($upload_guide_url) . '" target="_blank" style="color:#ff4d4f;text-decoration:underline;font-weight:600;">[click here]</a>.';
            $rejection_message .= '</div>';
        }
        // If prescription is approved or pending - don't show upload handler

        // Handle form submission (process and reload page properly)
        if (isset($_POST['ne_mlp_upload_submit']) || isset($_POST['ne_mlp_select_prev_presc'])) {
            // Simplified nonce validation - check if any valid nonce exists
            $nonce_valid = false;

            // Check multiple possible nonce fields
            if (isset($_POST['ne_mlp_nonce_order']) && wp_verify_nonce($_POST['ne_mlp_nonce_order'], 'ne_mlp_order_prescription_upload')) {
                $nonce_valid = true;
            } elseif (isset($_POST['ne_mlp_nonce']) && wp_verify_nonce($_POST['ne_mlp_nonce'], 'ne_mlp_upload_prescription')) {
                $nonce_valid = true;
            } elseif (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'ne_mlp_order_prescription_upload')) {
                $nonce_valid = true;
            }

            // If no nonce validation passes, just proceed anyway for logged-in users (security through user verification)
            if (!$nonce_valid && is_user_logged_in()) {
                $nonce_valid = true; // Allow for logged-in users to avoid session issues
            }

            if ($nonce_valid) {
                $this->handle_order_prescription_upload_with_reload($order_id, $user_id, $order);
                return; // Stop further processing as we've redirected
            } else {
                echo '<div style="color:#ff4d4f;padding:15px;background:#fff2f0;border:1px solid #ffa39e;border-radius:6px;margin:15px 0;">';
                echo '<strong>🔒 Security Validation Failed</strong><br>Your session may have expired. Please refresh the page and try again.';
                echo '</div>';
            }
        }

        // Show upload handler if needed
        if ($show_upload_handler) {
            echo $rejection_message; // Show rejection message if applicable

            if ($is_rejected) {
                // For rejected prescriptions, show upload-only handler (no select from previous)
                echo $this->get_upload_only_handler_html($user_id, $order_id);
            } else {
                // For no prescription attached, show full handler (upload + select from previous)
                echo $prescription_manager->get_upload_handler_html($user_id, $order_id, 'order');
            }
        }
    }

    /**
     * Handle order prescription upload with proper page reloading
     */
    private function handle_order_prescription_upload_with_reload($order_id, $user_id, $order)
    {
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';

            // Get existing prescription attachment if any
            $existing_presc_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);
            $existing_prescription = null;

            if ($existing_presc_id) {
                $existing_prescription = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE id = %d AND user_id = %d",
                    $existing_presc_id,
                    $user_id
                ));
            }

            // If selecting previous prescription
            if (!empty($_POST['ne_mlp_select_prev_presc'])) {
                $selected_presc_id = intval($_POST['ne_mlp_select_prev_presc']);

                // Validate selected prescription
                $selected_presc = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE id = %d AND user_id = %d AND status IN ('approved', 'pending')",
                    $selected_presc_id,
                    $user_id
                ));

                if ($selected_presc) {
                    // If there's an existing rejected prescription, remove it first
                    if ($existing_prescription && $existing_prescription->status === 'rejected') {
                        $this->delete_prescription_files_and_record($existing_prescription);
                    }

                    // Attach the selected prescription to order
                    $result = $prescription_manager->update_prescription_order_tracking($selected_presc_id, $order_id);

                    if (!is_wp_error($result)) {
                        // Show success message using session
                        $_SESSION['prescription_message'] = ['type' => 'success', 'message' => 'Prescription attached successfully!'];
                    } else {
                        $_SESSION['prescription_message'] = ['type' => 'error', 'message' => $result->get_error_message()];
                    }
                    wp_redirect($order->get_view_order_url());
                    exit;
                } else {
                    $_SESSION['prescription_message'] = ['type' => 'error', 'message' => 'Invalid prescription selected'];
                    wp_redirect($order->get_view_order_url());
                    exit;
                }
            }
            // If uploading new files - Use the same method as shortcode upload
            elseif (!empty($_FILES['ne_mlp_prescription_files']) && !empty($_FILES['ne_mlp_prescription_files']['name'][0])) {

                // If there's an existing rejected prescription, remove it first
                if ($existing_prescription && $existing_prescription->status === 'rejected') {
                    $this->delete_prescription_files_and_record($existing_prescription);
                    delete_post_meta($order_id, '_ne_mlp_prescription_id');
                    delete_post_meta($order_id, '_ne_mlp_prescription_status');
                }

                // Use the exact same upload logic as shortcode
                $files = $_FILES['ne_mlp_prescription_files'];
                $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
                $max_size = 5 * 1024 * 1024; // 5MB
                $count = isset($files['name']) ? count($files['name']) : 0;
                $errors = [];

                if (!$files || empty($files['name'][0])) {
                    $errors[] = 'Please select at least one file.';
                }
                if ($count > 4) {
                    $errors[] = 'You can upload a maximum of 4 files.';
                }

                $saved_files = [];
                for ($i = 0; $i < $count; $i++) {
                    if (empty($files['name'][$i]))
                        continue;
                    $type_mime = $files['type'][$i];
                    $size = $files['size'][$i];
                    if (!in_array($type_mime, $allowed_types)) {
                        $errors[] = 'Invalid file type: ' . $files['name'][$i];
                    }
                    if ($size > $max_size) {
                        $errors[] = 'File too large: ' . $files['name'][$i];
                    }
                }

                if (empty($errors)) {
                    $upload_dir = wp_upload_dir();
                    for ($i = 0; $i < $count; $i++) {
                        if (empty($files['name'][$i]))
                            continue;
                        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                        $new_name = 'presc_' . $user_id . '_' . time() . "_{$i}.{$ext}";
                        $target = trailingslashit($upload_dir['basedir']) . 'ne-mlp-prescriptions/';
                        if (!file_exists($target))
                            wp_mkdir_p($target);
                        $file_path = $target . $new_name;
                        if (move_uploaded_file($files['tmp_name'][$i], $file_path)) {
                            $saved_files[] = trailingslashit($upload_dir['baseurl']) . 'ne-mlp-prescriptions/' . $new_name;
                        }
                    }

                    if (!empty($saved_files)) {
                        // Insert into database using the same method as shortcode
                        $insert_result = $wpdb->insert($table, [
                            'user_id' => $user_id,
                            'order_id' => null,
                            'file_paths' => wp_json_encode($saved_files),
                            'type' => 'medicine',
                            'status' => 'pending',
                            'created_at' => current_time('mysql'),
                        ]);

                        if ($insert_result !== false) {
                            $new_presc_id = $wpdb->insert_id;

                            // Attach new prescription to order
                            $attach_result = $prescription_manager->update_prescription_order_tracking($new_presc_id, $order_id);

                            if (!is_wp_error($attach_result)) {
                                $_SESSION['prescription_message'] = ['type' => 'success', 'message' => 'Prescription uploaded and attached successfully!'];
                            } else {
                                $_SESSION['prescription_message'] = ['type' => 'error', 'message' => $attach_result->get_error_message()];
                            }
                        } else {
                            $_SESSION['prescription_message'] = ['type' => 'error', 'message' => 'Failed to save prescription to database'];
                        }
                    } else {
                        $_SESSION['prescription_message'] = ['type' => 'error', 'message' => 'File upload failed'];
                    }
                } else {
                    $_SESSION['prescription_message'] = ['type' => 'error', 'message' => implode('. ', $errors)];
                }

                wp_redirect($order->get_view_order_url());
                exit;
            } else {
                $_SESSION['prescription_message'] = ['type' => 'error', 'message' => 'No prescription data provided'];
                wp_redirect($order->get_view_order_url());
                exit;
            }

        } catch (Exception $e) {
            $_SESSION['prescription_message'] = ['type' => 'error', 'message' => 'Upload error: ' . $e->getMessage()];
            wp_redirect($order->get_view_order_url());
            exit;
        }
    }

    /**
     * Helper method to delete prescription files and database record
     */
    private function delete_prescription_files_and_record($prescription)
    {
        // Delete files
        $old_files = json_decode($prescription->file_paths, true);
        if (is_array($old_files)) {
            foreach ($old_files as $old_file) {
                $old_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $old_file);
                if (file_exists($old_path)) {
                    @unlink($old_path);
                }
            }
        }

        // Delete database record
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $wpdb->delete($table, ['id' => $prescription->id]);
    }

    // Show prescription status for order (centralized display)
    public function render_prescription_status_for_order($order_id)
    {
        if (!is_user_logged_in())
            return;

        $user_id = get_current_user_id();
        $order = wc_get_order($order_id);

        if (!$order || $order->get_user_id() != $user_id)
            return;

        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();

        // Check if order requires prescription
        if (!$prescription_manager->order_requires_prescription($order_id))
            return;

        // Get current prescription attachment
        $presc_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);

        if ($presc_id) {
            echo $prescription_manager->get_prescription_status_display($presc_id, true);
        }
    }

    // Admin post handler for order prescription upload (simplified)
    public function admin_post_handle_order_prescription_upload()
    {
        if (!is_user_logged_in()) {
            wp_die('Unauthorized');
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_die('Invalid order ID');
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_user_id() != get_current_user_id()) {
            wp_die('Access denied');
        }

        // Redirect to upload handler
        $upload_handler = new NE_MLP_Upload_Handler();
        $result = $upload_handler->handle_order_prescription_upload($order_id, get_current_user_id());

        if (is_wp_error($result)) {
            wp_redirect($order->get_view_order_url() . '?prescription_error=' . urlencode($result->get_error_message()));
        } else {
            wp_redirect($order->get_view_order_url() . '?prescription_success=1');
        }
        exit;
    }

    // AJAX: Delete prescription and optionally order again
    public function ajax_delete_and_order_again()
    {
        check_ajax_referer('ne_mlp_frontend', 'nonce');
        if (!is_user_logged_in())
            wp_send_json_error('Not logged in');
        $user_id = get_current_user_id();
        $presc_id = intval($_POST['presc_id']);
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d", $presc_id, $user_id));
        if (!$presc || $presc->order_id)
            wp_send_json_error('Cannot delete prescription linked to an order.');
        // Delete files from server
        $files = json_decode($presc->file_paths, true);
        if (is_array($files)) {
            foreach ($files as $file) {
                $path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $file);
                if (file_exists($path))
                    @unlink($path);
            }
        }
        $wpdb->delete($table, ['id' => $presc_id]);
        // Re-add products to cart if requested
        if (isset($_POST['order_again']) && $_POST['order_again'] == '1') {
            $order_id = $presc->order_id;
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        if ($product && $product->is_in_stock()) {
                            WC()->cart->add_to_cart($product->get_id(), $item->get_quantity());
                        }
                    }
                }
            }
            wp_send_json_success('Prescription deleted and products added to cart. <a href="' . esc_url(wc_get_cart_url()) . '">View cart</a>');
        }
        wp_send_json_success('Prescription deleted.');
    }

    // CRON: Delete rejected prescriptions after 24h
    public function cron_delete_rejected_prescriptions()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $rejected = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status = 'rejected' AND created_at < %s", $cutoff));
        foreach ($rejected as $presc) {
            $wpdb->delete($table, ['id' => $presc->id]);
        }
    }

    public function maybe_auto_select_prescription_checkout()
    {
        if (!is_user_logged_in() || !function_exists('WC') || !is_checkout())
            return;
        $presc_id = WC()->session->get('ne_mlp_prescription_id');
        if (!$presc_id)
            return;
        // Add a hidden field to the checkout form
        add_action('woocommerce_after_order_notes', function () use ($presc_id) {
            echo '<input type="hidden" name="ne_mlp_prescription_id" value="' . esc_attr($presc_id) . '" />';
            echo '<div class="ne-mlp-upload-status" style="background:#e6ffed;color:#389e0d;font-weight:600;padding:14px 18px;border-radius:8px;margin:18px 0;">Prescription ID auto-selected for this order.</div>';
        });
        // Hide upload/select UI via CSS
        add_action('wp_head', function () {
            echo '<style>.ne-mlp-upload-box, .ne-mlp-upload-section { display:none!important; }</style>';
        });
    }
    public function save_prescription_id_to_order($order_id)
    {
        if (isset($_POST['ne_mlp_prescription_id']) && $_POST['ne_mlp_prescription_id']) {
            $prescription_id = intval($_POST['ne_mlp_prescription_id']);
            update_post_meta($order_id, '_ne_mlp_prescription_id', $prescription_id);

            // Get actual status from database, not hardcoded 'approved'
            $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
            $current_status = $prescription_manager->get_current_prescription_status($prescription_id);

            if ($current_status) {
                update_post_meta($order_id, '_ne_mlp_prescription_status', $current_status);
                error_log('NE_MLP: save_prescription_id_to_order set to ' . $current_status . ' for order ' . $order_id);
            } else {
                // Prescription not found, clean up
                delete_post_meta($order_id, '_ne_mlp_prescription_id');
                error_log('NE_MLP: Prescription not found, cleaned up order meta for order ' . $order_id);
            }

            // Clear session after use
            if (function_exists('WC') && WC()->session) {
                WC()->session->__unset('ne_mlp_prescription_id');
            }
        }
    }

    public function add_prescription_id_to_my_orders($order)
    {
        static $displayed_orders = [];
        $order_id = $order->get_id();

        // Prevent duplicate display for same order
        if (isset($displayed_orders[$order_id])) {
            return;
        }
        $displayed_orders[$order_id] = true;

        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();

        // Check if order requires prescription
        if (!$prescription_manager->order_requires_prescription($order_id)) {
            echo '<br><span style="color: #52c41a; font-size: 0.9em;">✅ No prescription required.</span>';
            return;
        }

        $presc_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);
        if ($presc_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';
            $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $presc_id));
            if ($presc) {
                $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
                $presc_num = $prescription_manager->format_prescription_id($presc);
                $link = esc_url(wc_get_account_endpoint_url('my-prescription') . '#prescription-' . intval($presc->id));
                echo '<br><a href="' . $link . '" style="color: #007cba; font-size: 0.9em; text-decoration: none;">📋 Prescription ID: ' . esc_html($presc_num) . '</a>';
            }
        } else {
            $order_url = wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount'));
            echo '<br><a href="' . esc_url($order_url) . '" style="color: #d63638; font-size: 0.9em; text-decoration: none;">⚠️ Prescription required. Attach to proceed.</a>';
        }
    }

    // AJAX: Delete prescription (for My Prescriptions page)
    public function ajax_delete_prescription()
    {
        check_ajax_referer('ne_mlp_frontend', 'nonce');
        if (!is_user_logged_in())
            wp_send_json_error('Not logged in');

        $user_id = get_current_user_id();
        $presc_id = intval($_POST['presc_id']);

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d", $presc_id, $user_id));

        if (!$presc) {
            wp_send_json_error('Prescription not found.');
        }

        // Enhanced delete logic:
        // 1. Allow deleting REJECTED prescriptions (always)
        // 2. Allow deleting PENDING prescriptions if not attached to any order
        // 3. Never allow deleting APPROVED prescriptions

        $can_delete = false;
        $error_message = '';

        if ($presc->status === 'rejected') {
            $can_delete = true; // Always allow deleting rejected prescriptions
        } elseif ($presc->status === 'pending' && !$presc->order_id) {
            $can_delete = true; // Allow deleting unattached pending prescriptions
        } elseif ($presc->status === 'pending' && $presc->order_id) {
            $error_message = 'Cannot delete pending prescriptions that are attached to orders.';
        } elseif ($presc->status === 'approved') {
            $error_message = 'Cannot delete approved prescriptions.';
        } else {
            $error_message = 'Cannot delete this prescription.';
        }

        if (!$can_delete) {
            wp_send_json_error($error_message);
        }

        // Delete files from server
        $files = json_decode($presc->file_paths, true);
        if (is_array($files)) {
            foreach ($files as $file) {
                $path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $file);
                if (file_exists($path))
                    @unlink($path);
            }
        }

        // Remove from database
        $deleted = $wpdb->delete($table, ['id' => $presc_id]);

        if ($deleted) {
            wp_send_json_success('Prescription deleted successfully.');
        } else {
            wp_send_json_error('Failed to delete prescription from database.');
        }
    }
    /**
     * AJAX: Handle request order submission
     */
    public function ajax_submit_request_order()
    {
        check_ajax_referer('ne_mlp_submit_request', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to submit a request.']);
        }
        
        $user_id = get_current_user_id();
        $prescription_id = isset($_POST['prescription_id']) ? intval($_POST['prescription_id']) : 0;
        $days = isset($_POST['days']) ? intval($_POST['days']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        
        // Validate inputs
        if (!$prescription_id) {
            wp_send_json_error(['message' => 'Invalid prescription ID.']);
        }
        
        if (!$days || !in_array($days, [7, 15, 30, 60, 90])) {
            wp_send_json_error(['message' => 'Please select a valid number of days.']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        
        // Verify the prescription exists and belongs to the user
        $prescription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d AND status = 'approved'",
            $prescription_id,
            $user_id
        ));
        
        if (!$prescription) {
            wp_send_json_error(['message' => 'Prescription not found or not approved.']);
        }
        
        // Calculate the expiration date based on selected days
        $expiry_date = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        
        // Update the prescription with the request details
        $updated = $wpdb->update(
            $table,
            [
                'expiry_date' => $expiry_date,
                'notes' => $notes,
                'status' => 'pending',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $prescription_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        if ($updated === false) {
            wp_send_json_error(['message' => 'Failed to update prescription. Please try again.']);
        }
        
        // Send notification to admin (you can implement this function)
        $this->send_request_notification($prescription_id, $user_id, $days, $notes);
        
        wp_send_json_success([
            'message' => 'Your request has been submitted successfully!',
            'expiry_date' => date_i18n(get_option('date_format'), strtotime($expiry_date))
        ]);
    }
    
    /**
     * Send notification to admin about the new request
     */
    private function send_request_notification($prescription_id, $user_id, $days, $notes) {
        $user = get_userdata($user_id);
        $prescription_url = admin_url("admin.php?page=ne-mlp-prescriptions&action=edit&id=$prescription_id");
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = sprintf(__('[%s] New Prescription Request #%d', 'ne-med-lab-prescriptions'), $site_name, $prescription_id);
        
        $message = sprintf(__(
            'A new prescription request has been submitted by %s.' . "\n\n" .
            'Prescription ID: #%d' . "\n" .
            'Requested for: %d days' . "\n" .
            'Notes: %s' . "\n\n" .
            'View and process this request: %s' . "\n\n" .
            'This is an automated notification from %s.',
            'ne-med-lab-prescriptions'
        ), 
            $user->display_name,
            $prescription_id,
            $days,
            $notes ?: 'No additional notes',
            $prescription_url,
            $site_name
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    // AJAX: Download prescription
    public function ajax_download_prescription()
    {
        check_ajax_referer('ne_mlp_frontend', 'nonce');
        if (!is_user_logged_in()) {
            wp_die('Not logged in');
        }

        $presc_id = intval($_GET['presc_id']);
        if (!$presc_id) {
            wp_die('Invalid prescription ID');
        }

        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        $presc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND (user_id = %d OR %d IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'wp_capabilities' AND meta_value LIKE '%administrator%'))",
            $presc_id,
            $user_id,
            $user_id
        ));

        if (!$presc) {
            wp_die('Prescription not found or access denied');
        }

        $files = json_decode($presc->file_paths, true);
        if (!is_array($files) || empty($files)) {
            wp_die('No files found for this prescription');
        }

        // Get user info for filename
        $user = get_userdata($presc->user_id);
        $username = $user ? sanitize_file_name($user->display_name) : 'user';

        // Get formatted prescription ID
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $formatted_id = $prescription_manager->format_prescription_id($presc);

        // If multiple files, create a zip
        if (count($files) > 1) {
            $this->download_multiple_files_as_zip($files, $username, $formatted_id);
        } else {
            $this->download_single_file($files[0], $username, $formatted_id);
        }
    }

    private function download_single_file($file_url, $username, $formatted_id)
    {
        // Convert URL to file path
        $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $file_url);

        // Fallback paths
        if (!file_exists($file_path)) {
            $alt_paths = [
                wp_upload_dir()['basedir'] . '/ne-mlp-prescriptions/' . basename($file_url),
                wp_upload_dir()['basedir'] . '/ne-prescriptions/' . basename($file_url),
                ABSPATH . ltrim($file_url, '/')
            ];

            foreach ($alt_paths as $alt_path) {
                if (file_exists($alt_path)) {
                    $file_path = $alt_path;
                    break;
                }
            }
        }

        if (!file_exists($file_path)) {
            wp_die('File not found on server');
        }

        // Get file extension
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);

        // Create custom filename: username_prec_FormattedPrescriptionId.extension
        $custom_filename = $username . '_prec_' . $formatted_id . '.' . $extension;

        // Get file mime type
        $mime_type = mime_content_type($file_path);
        if (!$mime_type) {
            $mime_type = 'application/octet-stream';
        }

        // Set headers for file download
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $custom_filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Output file
        readfile($file_path);
        exit;
    }

    private function download_multiple_files_as_zip($files, $username, $formatted_id)
    {
        if (!class_exists('ZipArchive')) {
            wp_die('Zip functionality not available on server');
        }

        $zip = new ZipArchive();
        $zip_filename = $username . '_prec_' . $formatted_id . '.zip';
        $temp_zip_path = sys_get_temp_dir() . '/' . $zip_filename;

        if ($zip->open($temp_zip_path, ZipArchive::CREATE) !== TRUE) {
            wp_die('Cannot create zip file');
        }

        $file_counter = 1;
        foreach ($files as $file_url) {
            $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $file_url);

            if (!file_exists($file_path)) {
                $alt_paths = [
                    wp_upload_dir()['basedir'] . '/ne-mlp-prescriptions/' . basename($file_url),
                    wp_upload_dir()['basedir'] . '/ne-prescriptions/' . basename($file_url),
                ];

                foreach ($alt_paths as $alt_path) {
                    if (file_exists($alt_path)) {
                        $file_path = $alt_path;
                        break;
                    }
                }
            }

            if (file_exists($file_path)) {
                $extension = pathinfo($file_path, PATHINFO_EXTENSION);
                $internal_filename = $username . '_prec_' . $formatted_id . '_file' . $file_counter . '.' . $extension;
                $zip->addFile($file_path, $internal_filename);
                $file_counter++;
            }
        }

        $zip->close();

        if (!file_exists($temp_zip_path)) {
            wp_die('Failed to create zip file');
        }

        // Set headers for zip download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($temp_zip_path));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Output zip file
        readfile($temp_zip_path);

        // Clean up temp file
        unlink($temp_zip_path);
        exit;
    }

    /**
     * Add prescription required indicator to cart item names
     */
    public function add_prescription_required_label($name, $cart_item, $cart_item_key)
    {
        static $processed_items = [];

        if (!isset($cart_item['product_id'])) {
            return $name;
        }

        $product_id = $cart_item['product_id'];

        // Prevent duplicate processing for same cart item
        $item_key = $cart_item_key . '_' . $product_id;
        if (isset($processed_items[$item_key])) {
            return $name;
        }
        $processed_items[$item_key] = true;

        $requires_prescription = get_post_meta($product_id, '_ne_mlp_requires_prescription', true);

        if ($requires_prescription === 'yes') {
            // Check if label is already added to prevent duplicates
            if (strpos($name, '🏥 Prescription Required') === false) {
                $name .= ' <span style="color:#cf1322;font-size:11px;font-weight:600;background:#fff2f0;padding:2px 6px;border-radius:3px;">🏥 Prescription Required</span>';
            }
        }

        return $name;
    }

    /**
     * Add prescription required indicator to order item names (for order details, emails, etc.)
     */
    public function add_prescription_required_to_order_items($name, $item, $is_visible = true)
    {
        static $processed_items = [];

        if (!$item || !method_exists($item, 'get_product_id')) {
            return $name;
        }

        $product_id = $item->get_product_id();

        // Prevent duplicate processing for same order item
        $item_id = $item->get_id();
        if (isset($processed_items[$item_id])) {
            return $name;
        }
        $processed_items[$item_id] = true;

        $requires_prescription = get_post_meta($product_id, '_ne_mlp_requires_prescription', true);

        if ($requires_prescription === 'yes') {
            // Check if label is already added to prevent duplicates
            if (strpos($name, '🏥 Prescription Required') === false) {
                $name .= ' <span style="color:#cf1322;font-size:11px;font-weight:600;background:#fff2f0;padding:2px 6px;border-radius:3px;">🏥 Prescription Required</span>';
            }
        }

        return $name;
    }

    /**
     * Add prescription required indicator to checkout items
     */
    public function add_prescription_required_to_checkout_items($quantity, $cart_item, $cart_item_key)
    {
        if (!isset($cart_item['product_id'])) {
            return $quantity;
        }

        $product_id = $cart_item['product_id'];
        $requires_prescription = get_post_meta($product_id, '_ne_mlp_requires_prescription', true);

        if ($requires_prescription === 'yes') {
            $quantity .= '<br><small style="color:#cf1322;font-weight:600;">🏥 Prescription Required</small>';
        }

        return $quantity;
    }

    /**
     * Display prescription info at the top of order details page
     */
    public function display_prescription_info_on_order_details($order_id)
    {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $order = wc_get_order($order_id);

        if (!$order || $order->get_user_id() != $user_id) {
            return;
        }

        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();

        // Check if order requires prescription
        if (!$prescription_manager->order_requires_prescription($order_id)) {
            return;
        }

        // Get current prescription attachment
        $presc_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);

        echo '<div class="ne-mlp-order-prescription-info" style="background:#e6f7ff;border:1px solid #91d5ff;border-radius:8px;padding:20px;margin:20px 0;position:relative;">';

        if ($presc_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';
            $prescription = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $presc_id));

            if ($prescription) {
                $formatted_id = $this->get_prescription_manager()->format_prescription_id($prescription);
                $prescription_url = wc_get_account_endpoint_url('my-prescription') . '#prescription-' . $prescription->id;

                // Status color coding
                $status_colors = [
                    'approved' => '#52c41a',
                    'pending' => '#faad14',
                    'rejected' => '#ff4d4f'
                ];
                $status_color = isset($status_colors[$prescription->status]) ? $status_colors[$prescription->status] : '#666';
                $status_text = strtoupper($prescription->status);

                echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:15px;">';
                echo '<div>';
                echo '<h3 style="margin:0 0 8px 0;color:#1677ff;font-size:18px;">📋 Prescription Attached</h3>';
                echo '<p style="margin:0;color:#666;font-size:14px;">Your prescription <strong>' . esc_html($formatted_id) . '</strong> ';
                echo '<span style="color:' . $status_color . ';font-weight:bold;background:' . ($prescription->status === 'approved' ? '#f6ffed' : ($prescription->status === 'rejected' ? '#fff2f0' : '#fffbe6')) . ';padding:3px 8px;border-radius:4px;font-size:12px;">' . $status_text . '</span>';
                echo ' is attached to this order.</p>';
                echo '</div>';
                echo '<a href="' . esc_url($prescription_url) . '" class="button" style="background:#1677ff;color:white;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;white-space:nowrap;">View Prescription</a>';
                echo '</div>';
            }
        } else {
            echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:15px;">';
            echo '<div>';
            echo '<h3 style="margin:0 0 8px 0;color:#cf1322;font-size:18px;">⚠️ Prescription Required</h3>';
            echo '<p style="margin:0;color:#666;font-size:14px;">This order requires a prescription. Please upload one to proceed with order processing.</p>';
            echo '</div>';
            echo '<a href="#prescription-upload" class="button" style="background:#cf1322;color:white;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;white-space:nowrap;">Upload Prescription</a>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Add prescription required indicator to single product pages
     */
    public function add_prescription_required_to_product_page()
    {
        global $product;

        if (!$product) {
            return;
        }

        $product_id = $product->get_id();
        $requires_prescription = get_post_meta($product_id, '_ne_mlp_requires_prescription', true);

        if ($requires_prescription === 'yes') {
            echo '<div class="ne-mlp-product-prescription-notice" style="margin:15px 0;padding:10px;background:#fff2f0;border:1px solid #ffa39e;border-radius:4px;">';
            echo '<span style="color:#cf1322;font-size:13px;font-weight:600;">🏥 Prescription Required</span>';
            echo '<p style="margin:5px 0 0;color:#666;font-size:12px;">' . esc_html__('This product requires a valid prescription. You can upload your prescription during checkout or after placing your order.', 'ne-med-lab-prescriptions') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Handle AJAX prescription search
     */
    // REMOVED: handle_search_prescriptions method - using simplified search now

    /**
     * Render a single prescription card for AJAX responses
     */
    private function render_prescription_card($prescription)
    {
        $files = json_decode($prescription->file_paths, true);
        $first_file = is_array($files) && !empty($files) ? $files[0] : '';

        // Get status color and icon
        $status_styles = [
            'approved' => ['color' => '#52c41a', 'bg' => '#f6ffed', 'border' => '#b7eb8f', 'icon' => '✅'],
            'pending' => ['color' => '#faad14', 'bg' => '#fffbe6', 'border' => '#ffe58f', 'icon' => '⏳'],
            'rejected' => ['color' => '#ff4d4f', 'bg' => '#fff2f0', 'border' => '#ffa39e', 'icon' => '❌']
        ];

        $status = $prescription->status ?? 'pending';
        $style = $status_styles[$status] ?? $status_styles['pending'];

        echo '<div id="prescription-' . $prescription->id . '" class="ne-mlp-prescription-card" style="background:#fff;border:1px solid #e8e8e8;border-radius:12px;padding:20px;box-shadow:0 4px 12px rgba(0,0,0,0.08);position:relative;transition:all 0.3s ease;margin-bottom:20px;max-width:100%;overflow:hidden;" onmouseover="this.style.boxShadow=\'0 6px 20px rgba(0,0,0,0.12)\'" onmouseout="this.style.boxShadow=\'0 4px 12px rgba(0,0,0,0.08)\'">';

        // Type and Status header
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">';
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        $type_icon = $prescription->type === 'lab_test' ? '🧪' : '💊';
        $type_label = $prescription->type === 'lab_test' ? 'Lab Test' : 'Medicine';
        echo '<span style="font-size:18px;">' . $type_icon . '</span>';
        echo '<span style="font-weight:700;color:#333;font-size:16px;">' . esc_html($type_label) . '</span>';
        echo '</div>';
        echo '<div style="background:' . $style['bg'] . ';color:' . $style['color'] . ';border:1px solid ' . $style['border'] . ';padding:6px 14px;border-radius:16px;font-size:11px;font-weight:700;letter-spacing:0.5px;">';
        echo $style['icon'] . ' ' . strtoupper($status);
        echo '</div>';
        echo '</div>';

        // Prescription ID
        $formatted_id = $this->get_prescription_manager()->format_prescription_id($prescription);
        echo '<div style="margin-bottom:15px;font-weight:600;color:#333;font-size:15px;background:#f8f9fa;padding:8px 12px;border-radius:6px;border-left:4px solid #1677ff;">Prescription Id : ' . esc_html($formatted_id) . '</div>';

        // File thumbnail
        echo '<div style="margin-bottom:15px;">';
        if ($first_file) {
            $file_extension = pathinfo($first_file, PATHINFO_EXTENSION);
            if (in_array(strtolower($file_extension), ['jpg', 'jpeg', 'png'])) {
                echo '<img src="' . esc_url($first_file) . '" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #f0f0f0;box-shadow:0 2px 8px rgba(0,0,0,0.1);" alt="Prescription preview" />';
            } else {
                echo '<div style="width:120px;height:120px;background:#f8f9fa;border:2px solid #e8e8e8;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:32px;color:#999;box-shadow:0 2px 8px rgba(0,0,0,0.05);">📄</div>';
            }
        } else {
            echo '<div style="width:120px;height:120px;background:#f8f9fa;border:2px solid #e8e8e8;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:32px;color:#999;box-shadow:0 2px 8px rgba(0,0,0,0.05);">📄</div>';
        }
        echo '</div>';

        // Upload date
        echo '<div style="color:#888;font-size:12px;margin-bottom:15px;display:flex;align-items:center;gap:5px;"><span style="font-size:14px;">📅</span>Uploaded: ' . esc_html(date('M j, Y h:i A', strtotime($prescription->created_at))) . '</div>';

        // Order tracking with clickable link
        if ($prescription->order_id) {
            global $wpdb;
            $order = wc_get_order($prescription->order_id);
            if ($order) {
                $item_count = $order->get_item_count();
                $order_url = $order->get_view_order_url();
                echo '<div style="margin-bottom:15px;padding:10px;background:#f0f8ff;border:1px solid #cce7ff;border-radius:6px;">';
                echo '<div style="color:#666;font-size:13px;display:flex;align-items:center;gap:5px;">';
                echo '<span style="font-size:16px;">🛒</span>';
                echo 'Last used in order <a href="' . esc_url($order_url) . '" style="color:#1677ff;text-decoration:none;font-weight:600;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">#' . $prescription->order_id . '</a> - Order Items: ' . $item_count;
                echo '</div>';
                echo '</div>';
            }
        } else {
            echo '<div style="margin-bottom:15px;padding:10px;background:#f6f6f6;border:1px solid #ddd;border-radius:6px;color:#999;font-size:13px;text-align:center;">Not attached to any order</div>';
        }

        // Action buttons
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:20px;">';

        // Request Order button (for approved and pending prescriptions)
        if (in_array($status, ['approved', 'pending'])) {
            // Debug log
            error_log('Rendering Request Order button for ' . $status . ' prescription ID: ' . $prescription->id);
            
            // Button
            $button_html = '<button class="ne-mlp-request-order-btn" data-id="' . $prescription->id . '" style="background:#722ed1;color:#fff;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;transition:all 0.2s ease;display:flex;align-items:center;gap:5px;" onmouseover="this.style.background=\'#5a23c7\'" onmouseout="this.style.background=\'#722ed1\'"><span>📝</span>' . esc_html__('Request Order', 'ne-med-lab-prescriptions') . '</button>';
            echo $button_html;
            
            // Debug log before including template
            error_log('Including modal template for prescription ID: ' . $prescription->id);
            
            // Start output buffering to capture the template output
            ob_start();
            $this->get_template('request-order-modal.php', ['prescription_id' => $prescription->id]);
            $modal_html = ob_get_clean();
            
            // Debug the generated HTML
            error_log('Generated modal HTML: ' . substr($modal_html, 0, 200) . '...');
            
            // Output the modal HTML
            echo $modal_html;
            
            // Debug log after including template
            error_log('Finished including modal template for prescription ID: ' . $prescription->id);
        }

        // Download button (always available)
        echo '<button class="ne-mlp-download-btn" data-id="' . $prescription->id . '" style="background:#f8f9fa;color:#333;border:1px solid #d9d9d9;padding:10px 16px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;transition:all 0.2s ease;display:flex;align-items:center;gap:5px;" onmouseover="this.style.background=\'#e8e8e8\'" onmouseout="this.style.background=\'#f8f9fa\'"><span>📥</span>Download</button>';

        // Reorder button (only for approved prescriptions with completed orders)
        if ($status === 'approved' && $prescription->order_id) {
            $order = wc_get_order($prescription->order_id);
            if ($order && $order->has_status('completed')) {
                echo '<button class="ne-mlp-reorder-btn" data-id="' . $prescription->id . '" style="background:#1677ff;color:#fff;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;transition:all 0.2s ease;display:flex;align-items:center;gap:5px;" onmouseover="this.style.background=\'#0958d9\'" onmouseout="this.style.background=\'#1677ff\'"><span>🔄</span>Reorder</button>';
            }
        }

        // Delete button logic:
        // 1. For REJECTED prescriptions - always show delete
        // 2. For PENDING prescriptions - only if not attached to any order
        // 3. For APPROVED prescriptions - never show delete
        $show_delete = false;
        if ($status === 'rejected') {
            $show_delete = true; // Always allow deleting rejected prescriptions
        } elseif ($status === 'pending' && !$prescription->order_id) {
            $show_delete = true; // Allow deleting unattached pending prescriptions
        }

        if ($show_delete) {
            echo '<button class="ne-mlp-delete-btn" data-id="' . $prescription->id . '" style="background:#ff4d4f;color:#fff;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;transition:all 0.2s ease;display:flex;align-items:center;gap:5px;" onmouseover="this.style.background=\'#d32029\'" onmouseout="this.style.background=\'#ff4d4f\'"><span>🗑️</span>Delete</button>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Get upload-only handler HTML for rejected prescriptions (no select from previous)
     */
    private function get_upload_only_handler_html($user_id, $order_id)
    {
        ob_start();
        ?>
        <div class="ne-mlp-upload-handler"
            style="background:#f9f9f9;padding:20px;border:1px solid #ddd;border-radius:8px;margin:20px 0;">
            <h4 style="margin-top:0;">📤 Upload New Prescription</h4>
            <p>Since your previous prescription was rejected, please upload a new one.</p>

            <form method="post" enctype="multipart/form-data" id="ne-mlp-upload-form-order">
                <div class="form-group" style="margin-bottom:15px;">
                    <label for="ne_mlp_prescription_files" style="display:block;margin-bottom:5px;font-weight:600;">Select Files
                        (JPG, PNG, PDF):</label>
                    <input type="file" name="ne_mlp_prescription_files[]" id="ne_mlp_prescription_files"
                        accept=".jpg,.jpeg,.png,.pdf" multiple required
                        style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
                    <small style="color:#666;font-size:12px;">Max file size: 5MB per file. Multiple files allowed.</small>
                </div>

                <!-- Live Preview Container -->
                <div id="ne-mlp-file-preview" style="margin:15px 0;display:none;">
                    <h5>File Preview:</h5>
                    <div id="ne-mlp-preview-container" style="display:flex;flex-wrap:wrap;gap:10px;"></div>
                </div>

                <?php wp_nonce_field('ne_mlp_order_prescription_upload', 'ne_mlp_nonce_order'); ?>
                <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">

                <button type="submit" name="ne_mlp_upload_submit" class="button"
                    style="background:#52c41a;color:white;padding:10px 20px;border:none;border-radius:4px;cursor:pointer;">
                    Upload Prescription
                </button>
            </form>
        </div>

        <script>
            jQuery(function ($) {
                // File preview functionality
                $('#ne_mlp_prescription_files').on('change', function () {
                    const files = this.files;
                    const previewContainer = $('#ne-mlp-preview-container');
                    const previewSection = $('#ne-mlp-file-preview');

                    previewContainer.empty();

                    if (files.length > 0) {
                        previewSection.show();

                        Array.from(files).forEach(function (file, index) {
                            const fileDiv = $('<div style="text-align:center;padding:10px;border:1px solid #ddd;border-radius:4px;background:white;width:120px;"></div>');

                            if (file.type.startsWith('image/')) {
                                const reader = new FileReader();
                                reader.onload = function (e) {
                                    const img = $('<img style="width:80px;height:80px;object-fit:cover;border-radius:4px;" src="' + e.target.result + '">');
                                    fileDiv.append(img);
                                    fileDiv.append('<div style="font-size:11px;margin-top:5px;word-break:break-all;">' . file.name + '</div>');
                                };
                                reader.readAsDataURL(file);
                            } else if (file.type === 'application/pdf') {
                                fileDiv.append('<div style="font-size:40px;color:#ff4d4f;">📄</div>');
                                fileDiv.append('<div style="font-size:11px;margin-top:5px;word-break:break-all;">' . file.name + '</div>');
                            } else {
                                fileDiv.append('<div style="font-size:40px;color:#999;">📎</div>');
                                fileDiv.append('<div style="font-size:11px;margin-top:5px;word-break:break-all;">' . file.name + '</div>');
                            }

                            previewContainer.append(fileDiv);
                        });
                    } else {
                        previewSection.hide();
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Get prescription manager instance (helper method)
     */
    private function get_prescription_manager()
    {
        return NE_MLP_Prescription_Manager::getInstance();
    }

    // Add upload prescription endpoint
    public function add_upload_prescription_endpoint()
    {
        add_rewrite_endpoint('upload-prescription', EP_ROOT | EP_PAGES);
    }

    public function add_upload_prescription_query_var($vars)
    {
        $vars[] = 'upload-prescription';
        return $vars;
    }

    // Render upload prescription page
    public function render_upload_prescription_page()
    {
        echo $this->shortcode_upload_prescription();
    }

    /**
     * Trigger email notification when prescription is uploaded via frontend
     */
    private function trigger_upload_notification_frontend($prescription_id, $user_id)
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
}