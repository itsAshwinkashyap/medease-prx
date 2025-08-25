<?php
if (!defined('ABSPATH'))
    exit;

class NE_MLP_Admin_Panel
{
    public function __construct()
    {
        // Add meta box to order admin page
        add_action('add_meta_boxes', [$this, 'add_prescription_meta_box']);

        // Add admin menu for prescriptions
        add_action('admin_menu', [$this, 'add_prescription_menu']);
        // Handle approve/reject actions
        add_action('admin_post_ne_mlp_prescription_action', [$this, 'handle_prescription_action']);
        // Enqueue custom admin CSS
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
        add_action('wp_ajax_ne_mlp_admin_approve_presc', [$this, 'ajax_admin_approve_presc']);
        add_action('wp_ajax_ne_mlp_admin_reject_presc', [$this, 'ajax_admin_reject_presc']);
        add_action('wp_ajax_ne_mlp_admin_delete_presc', [$this, 'ajax_admin_delete_presc']);
        // Add AJAX handler for user dropdowns
        add_action('wp_ajax_ne_mlp_get_user_dropdowns', function () {
            if (!current_user_can('manage_woocommerce'))
                wp_send_json_error('Unauthorized');
            $user_id = intval($_POST['user_id']);
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';
            $orders = wc_get_orders([
                'customer_id' => $user_id,
                'limit' => 50,
                'orderby' => 'date',
                'order' => 'DESC',
                'return' => 'ids',
            ]);
            $order_options = [];
            foreach ($orders as $order_id) {
                $order = wc_get_order($order_id);
                $item_count = $order ? $order->get_item_count() : 0;
                $order_options[] = [
                    'id' => $order_id,
                    'label' => '#' . $order_id . ' - ' . $item_count . ' Items',
                ];
            }
            $prescs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND status = 'approved' ORDER BY created_at DESC", $user_id));
            $presc_options = [];
            foreach ($prescs as $p) {
                $date = date('Y-m-d H:i:s', strtotime($p->created_at));
                $prefix = $p->type === 'lab_test' ? 'Lab' : 'Med';
                $presc_id = $prefix . '-' . date('d-m-y', strtotime($p->created_at)) . '-' . str_pad($p->id, 2, '0', STR_PAD_LEFT);
                $presc_options[] = [
                    'id' => $p->id,
                    'label' => $presc_id . ' (' . $date . ')',
                ];
            }
            wp_send_json_success([
                'orders' => $order_options,
                'prescriptions' => $presc_options,
            ]);
        });
        // AJAX: Enhanced User search for assign order page with comprehensive search capabilities
        add_action('wp_ajax_ne_mlp_search_users', function () {
            if (!current_user_can('manage_woocommerce'))
                wp_send_json_error('Unauthorized');
            $term = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
            if (strlen($term) < 1)
                wp_send_json_success(['results' => []]);

            $args = [
                'number' => 25,
                'fields' => ['ID', 'display_name', 'user_email', 'user_login'],
                'orderby' => 'display_name'
            ];

            // Check if search term is a numeric ID
            if (preg_match('/^!?\s*(\d+)$/', $term, $m)) {
                $args['include'] = [intval($m[1])];
            } else {
                // Standard WordPress search
                $args['search'] = '*' . esc_attr($term) . '*';
                $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
            }

            $users = get_users($args);

            // If no results from standard search, try meta search for first_name and last_name
            if (empty($users) && !preg_match('/^!?\s*(\d+)$/', $term)) {
                global $wpdb;
                $term_like = '%' . $wpdb->esc_like($term) . '%';

                // Search in user meta for first_name and last_name
                $user_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT DISTINCT user_id 
                    FROM {$wpdb->usermeta} 
                    WHERE (meta_key = 'first_name' OR meta_key = 'last_name' OR meta_key = 'nickname') 
                    AND meta_value LIKE %s
                    LIMIT 25
                ", $term_like));

                if (!empty($user_ids)) {
                    $args = [
                        'include' => $user_ids,
                        'fields' => ['ID', 'display_name', 'user_email', 'user_login'],
                        'orderby' => 'display_name'
                    ];
                    $users = get_users($args);
                }
            }

            $results = [];
            foreach ($users as $u) {
                // Get additional user meta for more detailed display
                $first_name = get_user_meta($u->ID, 'first_name', true);
                $last_name = get_user_meta($u->ID, 'last_name', true);
                $full_name = trim($first_name . ' ' . $last_name);

                $display_text = $u->display_name;
                if (!empty($full_name) && $full_name !== $u->display_name) {
                    $display_text = $full_name . ' (' . $u->display_name . ')';
                }

                $results[] = [
                    'id' => $u->ID,
                    'text' => $display_text . ' - ' . $u->user_email . ' [' . $u->user_login . '] [ID: ' . $u->ID . ']'
                ];
            }
            wp_send_json(['results' => $results]);
        });
        // AJAX: Fetch orders and prescriptions for a user
        add_action('wp_ajax_ne_mlp_get_user_orders_prescs', function () {
            if (!current_user_can('manage_woocommerce'))
                wp_send_json_error('Unauthorized');
            $user_id = intval($_POST['user_id']);
            if (!$user_id)
                wp_send_json_error('No user');
            // Orders
            $orders = wc_get_orders([
                'customer_id' => $user_id,
                'limit' => 30,
                'orderby' => 'date',
                'order' => 'DESC',
                'return' => 'ids',
            ]);
            $order_options = [];
            foreach ($orders as $order_id) {
                $order = wc_get_order($order_id);
                $item_count = $order ? $order->get_item_count() : 0;
                $date = $order ? $order->get_date_created() : '';
                $order_options[] = [
                    'id' => $order_id,
                    'text' => '#' . $order_id . ' - ' . $item_count . ' items - ' . ($date ? $date->date('Y-m-d') : '')
                ];
            }
            // Prescriptions
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';
            $prescs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND status = 'approved' ORDER BY created_at DESC", $user_id));
            $presc_options = [];
            foreach ($prescs as $p) {
                $prefix = $p->type === 'lab_test' ? 'Lab' : 'Med';
                $presc_id = $prefix . '-' . date('d-m-y', strtotime($p->created_at)) . '-' . str_pad($p->id, 2, '0', STR_PAD_LEFT);
                $presc_options[] = [
                    'id' => $p->id,
                    'text' => $presc_id . ' (' . $p->created_at . ')'
                ];
            }
            wp_send_json_success([
                'orders' => $order_options,
                'prescriptions' => $presc_options
            ]);
        });

        // Add AJAX handler for user prescriptions by status
        add_action('wp_ajax_ne_mlp_get_user_prescs_by_status', function () {
            if (!current_user_can('manage_woocommerce'))
                wp_send_json_error('Unauthorized');
            $user_id = intval($_POST['user_id']);
            $status = sanitize_text_field($_POST['status']);
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';
            $prescs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND status = %s ORDER BY created_at DESC", $user_id, $status));
            if (!$prescs)
                wp_send_json_error('No prescriptions');
            ob_start();
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;">';
            foreach ($prescs as $p) {
                $files = json_decode($p->file_paths, true);
                $presc_id = ne_mlp_format_presc_id($p);
                echo '<div style="background:#fafbfc;padding:14px 16px;border-radius:10px;box-shadow:0 1px 6px #0001;">';
                echo '<div style="font-size:14px;font-weight:600;margin-bottom:4px;">' . $presc_id . '</div>';
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">' . esc_html(ucfirst($p->type)) . '</div>';
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">' . esc_html($p->created_at) . '</div>';
                if (is_array($files) && !empty($files)) {
                    foreach ($files as $f) {
                        $is_pdf = (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf');
                        if ($is_pdf) {
                            echo '<a href="' . esc_url($f) . '" target="_blank" style="display:inline-block;width:40px;height:40px;text-align:center;line-height:40px;font-size:22px;background:#f5f5f5;border-radius:6px;margin-right:4px;">📄</a>';
                        } else {
                            echo '<a href="' . esc_url($f) . '" target="_blank"><img src="' . esc_url($f) . '" style="width:40px;height:40px;object-fit:cover;border-radius:6px;margin-right:4px;" /></a>';
                        }
                    }
                }
                echo '</div>';
            }
            echo '</div>';
            wp_send_json_success(ob_get_clean());
        });
        add_action('wp_ajax_ne_mlp_get_prescription_files', [$this, 'ajax_get_prescription_files']);
        add_action('wp_ajax_ne_mlp_load_more_prescriptions', [$this, 'ajax_load_more_prescriptions']);
        add_action('wp_ajax_ne_mlp_get_user_prescs_by_status', [$this, 'ajax_get_user_prescs_by_status']);
        add_action('wp_ajax_ne_mlp_get_notification_count', [$this, 'ajax_get_notification_count']);
        add_action('wp_ajax_ne_mlp_get_user_orders', [$this, 'ajax_get_user_orders']);
        add_action('wp_ajax_ne_mlp_get_user_prescriptions', [$this, 'ajax_get_user_prescriptions']);
        add_action('wp_ajax_ne_mlp_get_user_data', [$this, 'ajax_get_user_data']);
        add_action('wp_ajax_ne_mlp_assign_prescription', [$this, 'ajax_assign_prescription']);
        add_action('wp_ajax_ne_mlp_admin_download_prescription', [$this, 'ajax_admin_download_prescription']);
        // Keep user autocomplete for backward compatibility with other parts
        add_action('wp_ajax_ne_mlp_user_autocomplete', array($this, 'ajax_user_autocomplete'));

        // Clean up duplicate user search registrations - keep only the main ones
        add_action('wp_ajax_ne_mlp_search_users', array($this, 'ajax_search_users'));
        add_action('wp_ajax_ne_mlp_load_more_prescriptions', array($this, 'ajax_load_more_prescriptions'));

        // File and notification AJAX handlers
        add_action('wp_ajax_ne_mlp_get_prescription_files', array($this, 'ajax_get_prescription_files'));
        add_action('wp_ajax_ne_mlp_get_notification_count', array($this, 'ajax_get_notification_count'));
        add_action('wp_ajax_ne_mlp_admin_download_prescription', array($this, 'ajax_admin_download_prescription'));
        add_action('wp_ajax_ne_mlp_get_user_orders', array($this, 'ajax_get_user_orders'));
        add_action('wp_ajax_ne_mlp_get_user_prescriptions', array($this, 'ajax_get_user_prescriptions'));
        add_action('wp_ajax_ne_mlp_assign_prescription', array($this, 'ajax_assign_prescription'));
    }

    // Add meta box to WooCommerce order edit page
    public function add_prescription_meta_box()
    {
        add_meta_box(
            'ne_mlp_prescription_box',
            __('Prescription', 'ne-med-lab-prescriptions'),
            [$this, 'render_prescription_meta_box'],
            'shop_order',
            'side',
            'high'
        );

        // Also add for new WooCommerce HPOS orders
        add_meta_box(
            'ne_mlp_prescription_box',
            __('Prescription', 'ne-med-lab-prescriptions'),
            [$this, 'render_prescription_meta_box'],
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }

    // Render the prescription meta box
    public function render_prescription_meta_box($post_or_order)
    {
        // Handle both old post format and new HPOS order format
        if (is_object($post_or_order) && method_exists($post_or_order, 'get_id')) {
            $order_id = $post_or_order->get_id();
        } else {
            $order_id = $post_or_order->ID;
        }

        $prescription_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);

        if (!$prescription_id) {
            echo '<div class="ne-mlp-no-prescription" style="text-align:center;padding:20px;color:#666;">';
            echo '<span class="dashicons dashicons-info" style="font-size:24px;margin-bottom:10px;display:block;"></span>';
            echo '<p style="margin:0;">' . esc_html__('No prescription linked to this order.', 'ne-med-lab-prescriptions') . '</p>';
            echo '</div>';
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $prescription_id));

        if (!$presc) {
            echo '<div class="ne-mlp-prescription-error" style="text-align:center;padding:20px;color:#d63638;">';
            echo '<span class="dashicons dashicons-warning" style="font-size:24px;margin-bottom:10px;display:block;"></span>';
            echo '<p style="margin:0;">' . esc_html__('Prescription record not found.', 'ne-med-lab-prescriptions') . '</p>';
            echo '</div>';
            return;
        }

        // Get prescription manager instance for formatting
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $presc_id = $prescription_manager->format_prescription_id($presc);
        $files = json_decode($presc->file_paths, true);

        // Status color coding
        $status_colors = [
            'approved' => '#52c41a',
            'pending' => '#faad14',
            'rejected' => '#cf1322'
        ];
        $status_color = isset($status_colors[$presc->status]) ? $status_colors[$presc->status] : '#666666';

        echo '<div class="ne-mlp-prescription-widget">';

        // Prescription ID with status badge
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;padding-bottom:10px;border-bottom:1px solid #ddd;">';
        echo '<div>';
        echo '<strong style="font-size:14px;">' . esc_html($presc_id) . '</strong>';
        echo '<div style="font-size:12px;color:#666;margin-top:2px;">' . esc_html(ucfirst(str_replace('_', ' ', $presc->type))) . '</div>';
        echo '</div>';
        echo '<span style="background:' . $status_color . ';color:#fff;padding:4px 8px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;">' . esc_html($presc->status) . '</span>';
        echo '</div>';

        // Details section
        echo '<div class="ne-mlp-prescription-details">';
        echo '<div style="margin-bottom:8px;"><strong style="font-size:12px;color:#666;">' . esc_html__('Uploaded:', 'ne-med-lab-prescriptions') . '</strong><br>';
        echo '<span style="font-size:13px;">' . esc_html(date('M j, Y g:i A', strtotime($presc->created_at))) . '</span></div>';

        if (!empty($presc->reject_note)) {
            echo '<div style="margin-bottom:8px;"><strong style="font-size:12px;color:#cf1322;">' . esc_html__('Reject Reason:', 'ne-med-lab-prescriptions') . '</strong><br>';
            echo '<span style="font-size:13px;color:#cf1322;">' . esc_html($presc->reject_note) . '</span></div>';
        }
        echo '</div>';

        // Files section
        if (is_array($files) && !empty($files)) {
            echo '<div style="margin-top:15px;padding-top:10px;border-top:1px solid #ddd;">';
            echo '<strong style="font-size:12px;color:#666;display:block;margin-bottom:8px;">' . esc_html__('Files:', 'ne-med-lab-prescriptions') . '</strong>';
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;">';

            foreach ($files as $file) {
                $is_pdf = (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf');
                $basename = basename($file);

                if ($is_pdf) {
                    echo '<a href="' . esc_url($file) . '" target="_blank" title="' . esc_attr($basename) . '" style="display:inline-block;width:40px;height:40px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;text-align:center;line-height:38px;text-decoration:none;color:#cf1322;font-size:18px;">📄</a>';
                } else {
                    echo '<a href="' . esc_url($file) . '" target="_blank" title="' . esc_attr($basename) . '" style="display:inline-block;border:1px solid #ddd;border-radius:4px;overflow:hidden;">';
                    echo '<img src="' . esc_url($file) . '" alt="' . esc_attr($basename) . '" style="width:40px;height:40px;object-fit:cover;display:block;" />';
                    echo '</a>';
                }
            }
            echo '</div>';
            echo '</div>';
        }

        // Quick actions
        echo '<div style="margin-top:15px;padding-top:10px;border-top:1px solid #ddd;">';
        $presc_admin_url = admin_url('admin.php?page=ne-mlp-prescriptions&search=' . urlencode($presc_id));
        echo '<a href="' . esc_url($presc_admin_url) . '" class="button button-small button-primary" target="_blank" style="width:100%;text-align:center;margin-bottom:5px;">' . esc_html__('View Prescription Details', 'ne-med-lab-prescriptions') . '</a>';

        $customer_prescs_url = admin_url('admin.php?page=ne-mlp-prescriptions&user_id=' . $presc->user_id);
        echo '<a href="' . esc_url($customer_prescs_url) . '" class="button button-small" target="_blank" style="width:100%;text-align:center;">' . esc_html__('View Customer All Prescriptions', 'ne-med-lab-prescriptions') . '</a>';
        echo '</div>';

        echo '</div>';
    }

    // Display prescription details in order details page (main section)
    public function display_prescription_in_order_details($order)
    {
        $order_id = $order->get_id();
        $prescription_id = get_post_meta($order_id, '_ne_mlp_prescription_id', true);

        if (!$prescription_id) {
            return; // No prescription attached
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $prescription_id));

        if (!$presc) {
            return; // Prescription not found
        }

        // Get prescription manager instance for formatting
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $presc_id = $prescription_manager->format_prescription_id($presc);
        $files = json_decode($presc->file_paths, true);

        // Status color coding
        $status_colors = [
            'approved' => '#52c41a',
            'pending' => '#faad14',
            'rejected' => '#cf1322'
        ];
        $status_color = isset($status_colors[$presc->status]) ? $status_colors[$presc->status] : '#666666';

        echo '<div class="ne-mlp-order-prescription-details" style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:20px;margin:20px 0;">';
        echo '<h3 style="margin-top:0;color:#333;display:flex;align-items:center;gap:10px;">';
        echo '<span class="dashicons dashicons-pressthis" style="color:#0073aa;"></span>';
        echo esc_html__('Attached Prescription', 'ne-med-lab-prescriptions');
        echo '</h3>';

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">';

        // Left column - Basic info
        echo '<div>';
        echo '<p style="margin:8px 0;"><strong>' . esc_html__('Prescription ID:', 'ne-med-lab-prescriptions') . '</strong> <span style="color:#0073aa;font-weight:600;">' . esc_html($presc_id) . '</span></p>';
        echo '<p style="margin:8px 0;"><strong>' . esc_html__('Status:', 'ne-med-lab-prescriptions') . '</strong> <span style="color:' . $status_color . ';font-weight:600;text-transform:uppercase;">' . esc_html($presc->status) . '</span></p>';
        echo '<p style="margin:8px 0;"><strong>' . esc_html__('Type:', 'ne-med-lab-prescriptions') . '</strong> ' . esc_html(ucfirst(str_replace('_', ' ', $presc->type))) . '</p>';
        echo '</div>';

        // Right column - Dates and source
        echo '<div>';
        echo '<p style="margin:8px 0;"><strong>' . esc_html__('Uploaded:', 'ne-med-lab-prescriptions') . '</strong> ' . esc_html(date('M j, Y g:i A', strtotime($presc->created_at))) . '</p>';
        echo '<p style="margin:8px 0;"><strong>' . esc_html__('Source:', 'ne-med-lab-prescriptions') . '</strong> ' . esc_html(ucfirst($presc->source)) . '</p>';
        if (!empty($presc->reject_note)) {
            echo '<p style="margin:8px 0;"><strong style="color:#cf1322;">' . esc_html__('Reject Reason:', 'ne-med-lab-prescriptions') . '</strong> ' . esc_html($presc->reject_note) . '</p>';
        }
        echo '</div>';

        echo '</div>';

        // Files section
        if (is_array($files) && !empty($files)) {
            echo '<div style="border-top:1px solid #ddd;padding-top:15px;">';
            echo '<h4 style="margin:0 0 10px 0;">' . esc_html__('Prescription Files:', 'ne-med-lab-prescriptions') . '</h4>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';

            foreach ($files as $file) {
                $is_pdf = (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf');
                $basename = basename($file);

                if ($is_pdf) {
                    echo '<a href="' . esc_url($file) . '" target="_blank" class="button button-secondary" style="display:flex;align-items:center;gap:8px;text-decoration:none;">';
                    echo '<span class="dashicons dashicons-media-document" style="color:#cf1322;"></span>';
                    echo esc_html($basename);
                    echo '</a>';
                } else {
                    echo '<a href="' . esc_url($file) . '" target="_blank" style="display:inline-block;border:2px solid #ddd;border-radius:6px;overflow:hidden;text-decoration:none;">';
                    echo '<img src="' . esc_url($file) . '" alt="' . esc_attr($basename) . '" style="width:80px;height:80px;object-fit:cover;display:block;" title="' . esc_attr($basename) . '" />';
                    echo '</a>';
                }
            }

            echo '</div>';
            echo '</div>';
        }

        // Quick actions
        echo '<div style="border-top:1px solid #ddd;padding-top:15px;display:flex;gap:10px;align-items:center;">';
        echo '<strong>' . esc_html__('Quick Actions:', 'ne-med-lab-prescriptions') . '</strong>';

        // Link to prescription management page
        $presc_admin_url = admin_url('admin.php?page=ne-mlp-prescriptions&search=' . urlencode($presc_id));
        echo '<a href="' . esc_url($presc_admin_url) . '" class="button button-primary" target="_blank">' . esc_html__('View in Prescriptions', 'ne-med-lab-prescriptions') . '</a>';

        // Link to customer's other prescriptions
        $customer_prescs_url = admin_url('admin.php?page=ne-mlp-prescriptions&user_id=' . $presc->user_id);
        echo '<a href="' . esc_url($customer_prescs_url) . '" class="button" target="_blank">' . esc_html__('Customer\'s Prescriptions', 'ne-med-lab-prescriptions') . '</a>';

        echo '</div>';
        echo '</div>';
    }



    // Add admin menu for prescriptions
    public function add_prescription_menu()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // Count unreviewed prescriptions (pending status)
        $unreviewed_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");

        // Create menu title with notification badge
        $menu_title = __('Prescriptions', 'ne-med-lab-prescriptions');
        if ($unreviewed_count > 0) {
            $menu_title .= ' <span class="ne-mlp-notification-badge" style="background:#d63638;color:#fff;border-radius:10px;padding:2px 6px;font-size:11px;margin-left:5px;">' . $unreviewed_count . '</span>';
        }

        add_menu_page(
            __('Prescriptions', 'ne-med-lab-prescriptions'),
            $menu_title,
            'manage_woocommerce',
            'ne-mlp-prescriptions',
            [$this, 'render_prescription_list_page'],
            'dashicons-pressthis',
            56
        );

        // Add Manual Upload submenu
        add_submenu_page(
            'ne-mlp-prescriptions',
            __('Manual Upload', 'ne-med-lab-prescriptions'),
            __('Manual Upload', 'ne-med-lab-prescriptions'),
            'manage_woocommerce',
            'ne-mlp-manual-upload',
            [$this, 'render_manual_upload_page']
        );

        // Add Assign Order submenu
        add_submenu_page(
            'ne-mlp-prescriptions',
            __('Assign Order to Prescription', 'ne-med-lab-prescriptions'),
            __('Assign Order to Prescription', 'ne-med-lab-prescriptions'),
            'manage_woocommerce',
            'ne-mlp-assign-order',
            [$this, 'render_assign_order_page']
        );

        // Add Request Order submenu with badge count
        $order_request_instance = class_exists('NE_MLP_Order_Request') ? NE_MLP_Order_Request::getInstance() : null;
        $request_menu_title = $order_request_instance ? $order_request_instance->get_menu_title() : __('Request Order', 'ne-med-lab-prescriptions');
        
        add_submenu_page(
            'ne-mlp-prescriptions',
            __('Request Order', 'ne-med-lab-prescriptions'),
            $request_menu_title,
            'manage_woocommerce',
            'ne-mlp-request-order',
            $order_request_instance ? [$order_request_instance, 'render_admin_page'] : function() { echo 'Order Request system not available.'; }
        );

        // Add Settings submenu
        add_submenu_page(
            'ne-mlp-prescriptions',
            __('Settings', 'ne-med-lab-prescriptions'),
            __('Settings', 'ne-med-lab-prescriptions'),
            'manage_woocommerce',
            'ne-mlp-settings',
            [$this, 'render_settings_page']
        );
    }

    // Render the prescription list page
    public function render_prescription_list_page()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        // --- Enhanced Search & Filter Bar ---
        echo '<div class="ne-mlp-admin-filter-bar" style="background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);margin-bottom:24px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Search Prescriptions', 'ne-med-lab-prescriptions') . '</h2>';

        // Unified Search Form
        echo '<form method="get" style="display:flex;gap:16px;flex-wrap:wrap;align-items:end;">';
        echo '<input type="hidden" name="page" value="ne-mlp-prescriptions">';

        // If viewing specific user, add hidden user_id field
        if (isset($_GET['user_id'])) {
            echo '<input type="hidden" name="user_id" value="' . esc_attr($_GET['user_id']) . '">';
        }

        // Search Input
        echo '<div style="flex:1;min-width:300px;">';
        echo '<label style="display:block;margin-bottom:8px;font-weight:600;">' . esc_html__('Search', 'ne-med-lab-prescriptions') . '</label>';
        echo '<input type="text" name="search" value="' . esc_attr(isset($_GET['search']) ? $_GET['search'] : '') . '" placeholder="' . esc_attr__('Search by Prescription ID, Order ID, User ID, Email, or Username', 'ne-med-lab-prescriptions') . '" style="width:100%;" />';
        echo '</div>';

        // Status Filter
        echo '<div style="min-width:200px;">';
        echo '<label style="display:block;margin-bottom:8px;font-weight:600;">' . esc_html__('Status', 'ne-med-lab-prescriptions') . '</label>';
        echo '<select name="status" style="width:100%;">';
        echo '<option value="">' . esc_html__('All Statuses', 'ne-med-lab-prescriptions') . '</option>';
        echo '<option value="pending"' . (isset($_GET['status']) && $_GET['status'] === 'pending' ? ' selected' : '') . '>' . esc_html__('Pending', 'ne-med-lab-prescriptions') . '</option>';
        echo '<option value="approved"' . (isset($_GET['status']) && $_GET['status'] === 'approved' ? ' selected' : '') . '>' . esc_html__('Approved', 'ne-med-lab-prescriptions') . '</option>';
        echo '<option value="rejected"' . (isset($_GET['status']) && $_GET['status'] === 'rejected' ? ' selected' : '') . '>' . esc_html__('Rejected', 'ne-med-lab-prescriptions') . '</option>';
        echo '</select>';
        echo '</div>';

        // Date Range
        echo '<div style="min-width:200px;">';
        echo '<label style="display:block;margin-bottom:8px;font-weight:600;">' . esc_html__('Date From', 'ne-med-lab-prescriptions') . '</label>';
        echo '<input type="date" name="date_from" value="' . esc_attr(isset($_GET['date_from']) ? $_GET['date_from'] : '') . '" style="width:100%;" />';
        echo '</div>';

        echo '<div style="min-width:200px;">';
        echo '<label style="display:block;margin-bottom:8px;font-weight:600;">' . esc_html__('Date To', 'ne-med-lab-prescriptions') . '</label>';
        echo '<input type="date" name="date_to" value="' . esc_attr(isset($_GET['date_to']) ? $_GET['date_to'] : '') . '" style="width:100%;" />';
        echo '</div>';

        echo '<button type="submit" class="button button-primary" style="margin-bottom:8px;">' . esc_html__('Search', 'ne-med-lab-prescriptions') . '</button>';
        echo '</form>';
        echo '</div>';

        // Show back button if viewing specific user or search results
        if (isset($_GET['user_id']) || !empty($_GET['search']) || !empty($_GET['status']) || !empty($_GET['date_from']) || !empty($_GET['date_to'])) {
            echo '<div style="margin-bottom:20px;display:flex;align-items:center;gap:12px;">';
            echo '<a href="' . esc_url(admin_url('admin.php?page=ne-mlp-prescriptions')) . '" class="button" style="display:inline-flex;align-items:center;gap:8px;"><span class="dashicons dashicons-arrow-left-alt2"></span> ' . esc_html__('Back', 'ne-med-lab-prescriptions') . '</a>';
            if (!empty($_GET['search'])) {
                echo '<span style="color:#666;">' . sprintf(esc_html__('Showing results for: %s', 'ne-med-lab-prescriptions'), '<strong>' . esc_html($_GET['search']) . '</strong>') . '</span>';
            }
            echo '</div>';
        }

        // After the back button in render_prescription_list_page()
        if (isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            $user = get_userdata($user_id);
            if ($user) {
                $orders = wc_get_orders([
                    'customer_id' => $user_id,
                    'return' => 'ids',
                    'limit' => -1
                ]);
                $order_count = count($orders);
                // Use the dynamic WooCommerce orders filter URL
                $orders_url = admin_url('admin.php?page=wc-orders&s&search-filter=all&action=-1&m=0&_customer_user=' . $user_id . '&filter_action=Filter&paged=1&action2=-1');
                echo '<div style="margin-bottom:20px;"><a href="' . esc_url($orders_url) . '" class="button button-primary" style="font-size:15px;font-weight:600;">' . esc_html($user->display_name) . ' : ' . esc_html__('View All Orders (', 'ne-med-lab-prescriptions') . $order_count . ')</a></div>';
            }
        }

        // --- Results Display ---
        $where = 'WHERE 1=1';
        $params = [];

        // Apply user filter
        if (isset($_GET['user_id'])) {
            $where .= " AND t1.user_id = %d";
            $params[] = intval($_GET['user_id']);
        }

        // Apply search filters
        if (!empty($_GET['search'])) {
            $search = sanitize_text_field($_GET['search']);

            // 1) Formatted ID: Med-DD-MM-YY-<RAW_ID> or Lab-DD-MM-YY-<RAW_ID>
            //    Match by RAW_ID only to avoid false negatives due to date/timezones or type differences.
            if (preg_match('/^(Med|Lab)-(\d{2})-(\d{2})-(\d{2})-(\d+)$/i', $search, $matches)) {
                $seq = intval($matches[5]);
                $where .= " AND t1.id = %d";
                $params[] = $seq;
            }
            // 2) Pure numeric input: search across prescription id, order id, and user id (exact matches)
            elseif (preg_match('/^\d+$/', $search)) {
                $num = intval($search);
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where .= " AND (t1.id = %d OR t1.order_id = %d OR t1.user_id = %d OR u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)";
                $params[] = $num;
                $params[] = $num;
                $params[] = $num;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            } else {
                $search_param = '%' . $wpdb->esc_like($search) . '%';
                $user_ids = [];

                // Search users by login, email, display name
                $user_query_args = [
                    'search' => '*' . esc_attr($search) . '*',
                    'search_columns' => ['user_login', 'user_email', 'display_name'],
                    'fields' => 'ID',
                ];
                $user_ids = get_users($user_query_args);

                // Meta search for first name and last name
                $meta_user_ids = get_users([
                    'meta_query' => [
                        'relation' => 'OR',
                        [
                            'key' => 'first_name',
                            'value' => $search,
                            'compare' => 'LIKE'
                        ],
                        [
                            'key' => 'last_name',
                            'value' => $search,
                            'compare' => 'LIKE'
                        ]
                    ],
                    'fields' => 'ID'
                ]);

                $all_user_ids = array_unique(array_merge($user_ids, $meta_user_ids));

                $search_conditions = [
                    "t1.id LIKE %s",
                    "t1.order_id LIKE %s",
                    "t1.user_id LIKE %s",
                    "u.user_login LIKE %s",
                    "u.user_email LIKE %s",
                    "u.display_name LIKE %s"
                ];
                $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);

                if (!empty($all_user_ids)) {
                    $user_id_placeholders = implode(',', array_fill(0, count($all_user_ids), '%d'));
                    $search_conditions[] = "t1.user_id IN ($user_id_placeholders)";
                    $params = array_merge($params, $all_user_ids);
                }

                $where .= " AND (" . implode(' OR ', $search_conditions) . ")";
            }
        }

        // Apply status filter
        if (!empty($_GET['status'])) {
            $where .= " AND t1.status = %s";
            $params[] = sanitize_text_field($_GET['status']);
        }

        // Apply date range
        if (!empty($_GET['date_from'])) {
            $where .= " AND t1.created_at >= %s";
            $params[] = sanitize_text_field($_GET['date_from']) . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where .= " AND t1.created_at <= %s";
            $params[] = sanitize_text_field($_GET['date_to']) . ' 23:59:59';
        }

        // Add pagination
        $per_page = 20; // Show 20 prescriptions per page
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM $table t1 LEFT JOIN {$wpdb->users} u ON t1.user_id = u.ID $where";
        if (!empty($params)) {
            $total = $wpdb->get_var($wpdb->prepare($count_query, $params));
        } else {
            $total = $wpdb->get_var($count_query);
        }
        // Ensure numeric value to avoid TypeError in PHP 8+
        $total = intval($total);
        $total_pages = (int) ceil($total / $per_page);

        // Fetch prescriptions with user info and pagination
        $query = "SELECT t1.*, u.display_name, u.user_email 
                 FROM $table t1 
                 LEFT JOIN {$wpdb->users} u ON t1.user_id = u.ID 
                 $where 
                 ORDER BY t1.created_at DESC
                 LIMIT %d OFFSET %d";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, array_merge($params, [$per_page, $offset]));
        } else {
            $query = $wpdb->prepare($query, [$per_page, $offset]);
        }

        $prescriptions = $wpdb->get_results($query);

        if (empty($prescriptions)) {
            echo '<div style="background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);">';
            echo '<p>' . esc_html__('No prescriptions found.', 'ne-med-lab-prescriptions') . '</p>';
            echo '</div>';
        } else {
            echo '<div class="ne-mlp-presc-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px;">';

            foreach ($prescriptions as $presc) {
                $files = json_decode($presc->file_paths, true);
                $file_previews = '';

                if (is_array($files) && !empty($files)) {
                    foreach ($files as $file) {
                        $is_pdf = (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf');
                        if ($is_pdf) {
                            $file_previews .= '<a href="' . esc_url($file) . '" target="_blank" class="ne-mlp-presc-thumb" title="' . esc_attr(basename($file)) . '" style="flex:0 0 64px;">';
                            $file_previews .= '<span style="display:inline-block;width:64px;height:64px;background:#f5f5f5;border-radius:6px;box-shadow:0 1px 4px #ccc;line-height:64px;text-align:center;font-size:32px;color:#cf1322;">📄</span>';
                            $file_previews .= '</a>';
                        } else {
                            $file_previews .= '<a href="' . esc_url($file) . '" target="_blank" class="ne-mlp-presc-thumb" title="' . esc_attr(basename($file)) . '" style="flex:0 0 64px;">';
                            $file_previews .= '<img src="' . esc_url($file) . '" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:6px;box-shadow:0 1px 4px #ccc;" />';
                            $file_previews .= '</a>';
                        }
                    }
                }

                $date = date('d-m-y', strtotime($presc->created_at));
                $prefix = $presc->type === 'lab_test' ? 'Lab' : 'Med';
                $presc_id = $prefix . '-' . $date . '-' . $presc->id;

                $last_usage = '';
                if ($presc->order_id) {
                    $order = wc_get_order($presc->order_id);
                    if ($order) {
                        $last_usage = true;
                    }
                }

                echo '<div class="ne-mlp-presc-card" style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);padding:20px;">';

                // Header
                echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">';
                echo '<span style="font-size:15px;font-weight:600;">' . ($presc->type === 'lab_test' ? '🔬 Lab Test' : '💊 Medicine') . '</span>';
                echo '<span style="background:' . ($presc->status === 'approved' ? '#e6ffed;color:#389e0d' : ($presc->status === 'rejected' ? '#ffeaea;color:#cf1322' : '#fffbe6;color:#d48806')) . ';border-radius:999px;padding:3px 14px;font-weight:600;">' . strtoupper($presc->status) . '</span>';
                echo '</div>';

                // Prescription Info
                echo '<div style="margin-bottom:16px;">';
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Prescription ID: <b>' . esc_html($presc_id) . '</b></div>';
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Customer Name:</div>';
                echo '<div style="font-size:16px;font-weight:700;margin-bottom:4px;"><a href="' . esc_url(admin_url('user-edit.php?user_id=' . $presc->user_id)) . '" target="_blank" style="color:#222;text-decoration:underline;">' . esc_html($presc->display_name) . '</a></div>';
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Email: <b>' . esc_html($presc->user_email) . '</b></div>';
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Uploaded: <b>' . esc_html(ne_mlp_format_uploaded_date($presc->created_at)) . '</b></div>';
                if ($presc->source) {
                    echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Source: <b>' . esc_html($presc->source) . '</b></div>';
                }
                if ($presc->order_id) {
                    echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Order: <a href="' . esc_url(admin_url('post.php?post=' . $presc->order_id . '&action=edit')) . '">#' . intval($presc->order_id) . '</a></div>';
                }
                echo '</div>';

                // File Previews - Update to single line with fixed size
                if ($file_previews) {
                    echo '<div style="margin-bottom:16px;">';
                    echo '<div style="font-size:13px;font-weight:600;margin-bottom:8px;">Files:</div>';
                    echo '<div style="display:flex;gap:8px;align-items:center;overflow-x:auto;padding-bottom:8px;">';
                    echo $file_previews;
                    echo '</div>';
                    echo '</div>';
                }

                // Action Buttons - Reordered with View User first
                echo '<div style="display:flex;flex-direction:column;gap:8px;">';

                // View User Prescriptions Button (moved to top)
                echo '<a href="' . esc_url(add_query_arg(['page' => 'ne-mlp-prescriptions', 'user_id' => $presc->user_id], admin_url('admin.php'))) . '" class="button" style="width:100%;">';
                echo '<span class="dashicons dashicons-admin-users" style="margin-right:8px;"></span>';
                echo esc_html__('View User All Prescriptions', 'ne-med-lab-prescriptions');
                echo '</a>';

                // Only show View All Orders if viewing a user page
                if (isset($_GET['user_id'])) {
                    echo '<a href="' . esc_url(add_query_arg([
                        'page' => 'wc-orders',
                        's' => '',
                        'search-filter' => 'all',
                        'action' => '-1',
                        'm' => '0',
                        '_customer_user' => $presc->user_id,
                        'filter_action' => 'Filter',
                        'paged' => '1',
                        'action2' => '-1'
                    ], admin_url('admin.php'))) . '" class="button" style="width:100%;">';
                    echo '<span class="dashicons dashicons-cart" style="margin-right:8px;"></span>';
                    echo esc_html__('View All Orders', 'ne-med-lab-prescriptions');
                    echo '</a>';
                }

                // Download Button
                if (is_array($files) && !empty($files)) {
                    echo '<button type="button" class="ne-mlp-download-all" data-presc="' . intval($presc->id) . '" style="width:100%;">';
                    echo '<span class="dashicons dashicons-download" style="margin-right:8px;"></span>';
                    echo esc_html__('Download', 'ne-med-lab-prescriptions');
                    echo '</button>';
                }

                if ($presc->status === 'pending') {
                    echo '<button class="button button-primary ne-mlp-approve" data-presc="' . intval($presc->id) . '" style="width:100%;">';
                    echo '<span class="dashicons dashicons-yes" style="margin-right:8px;"></span>';
                    echo esc_html__('Approve', 'ne-med-lab-prescriptions');
                    echo '</button>';

                    echo '<button class="button ne-mlp-reject" data-presc="' . intval($presc->id) . '" style="width:100%;background:#cf1322;color:#fff;border-color:#cf1322;">';
                    echo '<span class="dashicons dashicons-no" style="margin-right:8px;"></span>';
                    echo esc_html__('Reject', 'ne-med-lab-prescriptions');
                    echo '</button>';

                    echo '<button class="button ne-mlp-delete" data-presc="' . intval($presc->id) . '" style="width:100%;background:#cf1322;color:#fff;border-color:#cf1322;">';
                    echo '<span class="dashicons dashicons-trash" style="margin-right:8px;"></span>';
                    echo esc_html__('Delete', 'ne-med-lab-prescriptions');
                    echo '</button>';
                }

                if ($presc->status === 'rejected') {
                    echo '<button class="button ne-mlp-delete" data-presc="' . intval($presc->id) . '" style="width:100%;background:#cf1322;color:#fff;border-color:#cf1322;">';
                    echo '<span class="dashicons dashicons-trash" style="margin-right:8px;"></span>';
                    echo esc_html__('Delete', 'ne-med-lab-prescriptions');
                    echo '</button>';
                }

                if ($presc->status === 'approved') {
                    if (empty($presc->order_id)) {
                        echo '<a href="' . esc_url(admin_url('post-new.php?post_type=shop_order&customer_id=' . intval($presc->user_id))) . '" class="button button-primary" style="width:100%;">';
                        echo '<span class="dashicons dashicons-cart" style="margin-right:8px;"></span>';
                        echo esc_html__('Create Order', 'ne-med-lab-prescriptions');
                        echo '</a>';
                    } else {
                        echo '<a href="' . esc_url(admin_url('post.php?post=' . intval($presc->order_id) . '&action=edit')) . '" class="button button-primary" style="width:100%;">';
                        echo '<span class="dashicons dashicons-cart" style="margin-right:8px;"></span>';
                        echo esc_html__('View Order', 'ne-med-lab-prescriptions');
                        echo '</a>';
                    }
                }

                // Add last usage information
                if ($last_usage) {
                    echo '<div style="font-size:12px;color:#666;margin-top:8px;padding-top:8px;border-top:1px solid #eee;">';
                    echo '<span class="dashicons dashicons-clock" style="margin-right:4px;"></span>';
                    if ($presc->order_id) {
                        $order = wc_get_order($presc->order_id);
                        $item_count = $order ? $order->get_item_count() : 0;
                        echo 'Last used in order <a href="' . esc_url(admin_url('post.php?post=' . $presc->order_id . '&action=edit')) . '">#' . intval($presc->order_id) . '</a> - Order Item - ' . $item_count;
                    }
                    echo '</div>';
                }

                echo '</div>';
                echo '</div>';
            }

            echo '</div>';

            // Add pagination if there are multiple pages
            if ($total_pages > 1) {
                echo '<div class="ne-mlp-admin-pagination" style="margin-top:30px;text-align:center;padding:20px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">';

                $base_url = admin_url('admin.php?page=ne-mlp-prescriptions');

                // Preserve search parameters
                $url_params = [];
                foreach (['search', 'status', 'date_from', 'date_to', 'user_id'] as $param) {
                    if (!empty($_GET[$param])) {
                        $url_params[$param] = $_GET[$param];
                    }
                }

                // Previous button
                if ($current_page > 1) {
                    $prev_url = add_query_arg(array_merge($url_params, ['paged' => $current_page - 1]), $base_url);
                    echo '<a href="' . esc_url($prev_url) . '" class="button" style="margin:0 5px;">‹ Previous</a>';
                }

                // Page numbers (show 5 pages around current)
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $start_page + 4);

                if ($start_page > 1) {
                    $page_url = add_query_arg(array_merge($url_params, ['paged' => 1]), $base_url);
                    echo '<a href="' . esc_url($page_url) . '" class="button" style="margin:0 2px;">1</a>';
                    if ($start_page > 2) {
                        echo '<span style="margin:0 5px;">...</span>';
                    }
                }

                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i == $current_page) {
                        echo '<span class="button button-primary" style="margin:0 2px;">' . $i . '</span>';
                    } else {
                        $page_url = add_query_arg(array_merge($url_params, ['paged' => $i]), $base_url);
                        echo '<a href="' . esc_url($page_url) . '" class="button" style="margin:0 2px;">' . $i . '</a>';
                    }
                }

                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<span style="margin:0 5px;">...</span>';
                    }
                    $page_url = add_query_arg(array_merge($url_params, ['paged' => $total_pages]), $base_url);
                    echo '<a href="' . esc_url($page_url) . '" class="button" style="margin:0 2px;">' . $total_pages . '</a>';
                }

                // Next button
                if ($current_page < $total_pages) {
                    $next_url = add_query_arg(array_merge($url_params, ['paged' => $current_page + 1]), $base_url);
                    echo '<a href="' . esc_url($next_url) . '" class="button" style="margin:0 5px;">Next ›</a>';
                }

                echo '<div style="margin-top:10px;font-size:13px;color:#666;">';
                $start_item = ($current_page - 1) * $per_page + 1;
                $end_item = min($current_page * $per_page, $total);
                echo 'Showing ' . $start_item . '-' . $end_item . ' of ' . $total . ' prescriptions';
                echo '</div>';

                echo '</div>';
            }
        }

        // Add CSS for hover effects on red buttons
        ?>
        <style>
            .ne-mlp-reject:hover,
            .ne-mlp-delete:hover {
                background: #a8071a !important;
                border-color: #a8071a !important;
                color: #fff !important;
            }

            .ne-mlp-presc-thumb {
                transition: transform 0.2s;
            }

            .ne-mlp-presc-thumb:hover {
                transform: scale(1.05);
            }
        </style>
        <?php
    }

    // Render the Assign Order to Prescription page
    public function render_assign_order_page()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $msg = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ne_mlp_assign_user'], $_POST['ne_mlp_assign_order'], $_POST['ne_mlp_assign_presc'])) {
            check_admin_referer('ne_mlp_assign_prescription', 'ne_mlp_assign_nonce');

            $user_id = intval($_POST['ne_mlp_assign_user']);
            $order_id = intval($_POST['ne_mlp_assign_order']);
            $presc_id = intval($_POST['ne_mlp_assign_presc']);

            // Use centralized validation and attachment
            $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
            $validation_result = $prescription_manager->validate_prescription_for_order($presc_id, $order_id);

            if (is_wp_error($validation_result)) {
                $msg = '<div class="notice notice-error"><p>' . esc_html($validation_result->get_error_message()) . '</p></div>';
            } else {
                $attachment_result = $prescription_manager->attach_prescription_to_order($order_id, $presc_id);

                if (is_wp_error($attachment_result)) {
                    $msg = '<div class="notice notice-error"><p>' . esc_html($attachment_result->get_error_message()) . '</p></div>';
                } else {
                    $msg = '<div class="notice notice-success"><p>' . esc_html__('Prescription assigned to order successfully.', 'ne-med-lab-prescriptions') . '</p></div>';
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Assign Prescription to Order', 'ne-med-lab-prescriptions') . '</h1>';
        if ($msg)
            echo $msg;

        echo '<div class="ne-mlp-assign-form-container">';
        echo '<form method="post" id="ne-mlp-assign-form">';
        wp_nonce_field('ne_mlp_assign_prescription', 'ne_mlp_assign_nonce');

        echo '<table class="form-table">';

        // User search (jQuery UI Autocomplete)
        echo '<tr>';
        echo '<th scope="row"><label for="ne-mlp-assign-user-search">' . esc_html__('Search & Select User', 'ne-med-lab-prescriptions') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="ne-mlp-assign-user-search" class="regular-text" placeholder="' . esc_attr__('Type username, email, name, or user ID...', 'ne-med-lab-prescriptions') . '" style="width:100%;" />';
        echo '<input type="hidden" name="ne_mlp_assign_user" id="ne_mlp_assign_user" />';
        echo '<p class="description">' . esc_html__('Start typing to search users. Supports username, email, display name, first/last name, or numeric user ID.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Order select (populated by JS)
        echo '<tr id="ne-mlp-assign-order-row" style="display:none;">';
        echo '<th scope="row"><label for="ne_mlp_assign_order">' . esc_html__('Select Order', 'ne-med-lab-prescriptions') . '</label></th>';
        echo '<td>';
        echo '<select name="ne_mlp_assign_order" id="ne_mlp_assign_order" class="regular-text" required disabled>';
        echo '<option value="">' . esc_html__('Select user first', 'ne-med-lab-prescriptions') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Orders will load after selecting a user.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Prescription select (populated by JS)
        echo '<tr id="ne-mlp-assign-presc-row" style="display:none;">';
        echo '<th scope="row"><label for="ne_mlp_assign_presc">' . esc_html__('Select Prescription', 'ne-med-lab-prescriptions') . '</label></th>';
        echo '<td>';
        echo '<select name="ne_mlp_assign_presc" id="ne_mlp_assign_presc" class="regular-text" required disabled>';
        echo '<option value="">' . esc_html__('Select user first', 'ne-med-lab-prescriptions') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Only medicine prescriptions can be assigned to orders.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary" disabled id="ne_mlp_assign_submit">' . esc_html__('Assign Prescription to Order', 'ne-med-lab-prescriptions') . '</button>';
        echo '</p>';

        echo '</form>';
        echo '</div>';
        echo '</div>';
        ?>
        <script>
            jQuery(function ($) {
                // User Autocomplete
                $('#ne-mlp-assign-user-search').autocomplete({
                    source: function (request, response) {
                        $.ajax({
                            url: ajaxurl,
                            dataType: 'json',
                            data: {
                                action: 'ne_mlp_user_autocomplete',
                                term: request.term,
                                nonce: neMLP.download_nonce // Using a nonce available on the page
                            },
                            success: function (data) {
                                response($.map(data, function (item) {
                                    return { label: item.label, value: item.id, full_label: item.label };
                                }));
                            }
                        });
                    },
                    minLength: 2,
                    select: function (event, ui) {
                        $('#ne_mlp_assign_user').val(ui.item.value).trigger('change');
                        $('#ne-mlp-assign-user-search').val(ui.item.full_label);
                        return false;
                    }
                });

                // When user is selected, load orders and prescriptions
                $('#ne_mlp_assign_user').on('change', function () {
                    var userId = $(this).val();
                    var $orderSelect = $('#ne_mlp_assign_order');
                    var $prescSelect = $('#ne_mlp_assign_presc');
                    var $submitButton = $('#ne_mlp_assign_submit');

                    if (userId) {
                        $('#ne-mlp-assign-order-row, #ne-mlp-assign-presc-row').show();
                        $orderSelect.prop('disabled', true).html('<option>Loading orders...</option>');
                        $prescSelect.prop('disabled', true).html('<option>Loading prescriptions...</option>');
                        $submitButton.prop('disabled', true);

                        // Fetch orders
                        $.post(ajaxurl, {
                            action: 'ne_mlp_get_user_orders',
                            user_id: userId,
                            nonce: neMLP.nonce
                        }, function (resp) {
                            if (resp.success && resp.data.length) {
                                var opts = '<option value="">Select an order</option>';
                                $.each(resp.data, function (i, order) {
                                    opts += '<option value="' + order.value + '">' + order.label + '</option>';
                                });
                                $orderSelect.html(opts).prop('disabled', false);
                            } else {
                                $orderSelect.html('<option>No orders requiring prescriptions found</option>');
                            }
                        });

                        // Fetch prescriptions
                        $.post(ajaxurl, {
                            action: 'ne_mlp_get_user_prescriptions',
                            user_id: userId,
                            nonce: neMLP.nonce
                        }, function (resp) {
                            if (resp.success && resp.data.length) {
                                var opts = '<option value="">Select a prescription</option>';
                                $.each(resp.data, function (i, presc) {
                                    opts += '<option value="' + presc.value + '">' + presc.label + '</option>';
                                });
                                $prescSelect.html(opts).prop('disabled', false);
                            } else {
                                $prescSelect.html('<option>No available prescriptions found</option>');
                            }
                        });

                    } else {
                        $('#ne-mlp-assign-order-row, #ne-mlp-assign-presc-row').hide();
                        $orderSelect.prop('disabled', true).html('<option>Select user first</option>');
                        $prescSelect.prop('disabled', true).html('<option>Select user first</option>');
                        $submitButton.prop('disabled', true);
                    }
                });

                // Enable submit button when both fields are selected
                $('#ne_mlp_assign_order, #ne_mlp_assign_presc').on('change', function () {
                    var orderId = $('#ne_mlp_assign_order').val();
                    var prescId = $('#ne_mlp_assign_presc').val();
                    $('#ne_mlp_assign_submit').prop('disabled', !(orderId && prescId));
                });
            });
        </script>
        <?php
    }

    // Render the Settings page with tab structure
    public function render_settings_page()
    {
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('NE Med Lab Prescriptions - Settings', 'ne-med-lab-prescriptions') . '</h1>';

        // Tab navigation
        echo '<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">';

        $tabs = [
            'general' => __('General', 'ne-med-lab-prescriptions'),
            'email' => __('Email Notifications', 'ne-med-lab-prescriptions'),
            'onesignal' => __('Push Notifications', 'ne-med-lab-prescriptions'),
            'api' => __('API Settings', 'ne-med-lab-prescriptions')
        ];

        foreach ($tabs as $tab_key => $tab_name) {
            $class = $current_tab === $tab_key ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = add_query_arg(['page' => 'ne-mlp-settings', 'tab' => $tab_key], admin_url('admin.php'));
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($tab_name) . '</a>';
        }

        echo '</nav>';

        // Tab content container
        echo '<div class="ne-mlp-settings-content" style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">';

        // Render current tab content
        switch ($current_tab) {
            case 'general':
                $this->render_general_settings();
                break;
            case 'email':
                $this->render_email_settings();
                break;
            case 'onesignal':
                $this->render_onesignal_settings();
                break;
            case 'api':
                $this->render_api_settings();
                break;
            default:
                $this->render_general_settings();
        }

        echo '</div>';
        echo '</div>';
    }

    // Render General Settings tab
    private function render_general_settings()
    {
        // Handle form submission
        if (isset($_POST['ne_mlp_save_general_settings'])) {
            check_admin_referer('ne_mlp_general_settings', 'ne_mlp_general_nonce');

            // Save general settings
            $file_types = array_map('sanitize_text_field', $_POST['ne_mlp_allowed_file_types'] ?? []);
            $max_file_size = intval($_POST['ne_mlp_max_file_size'] ?? 5);
            $max_files = intval($_POST['ne_mlp_max_files_per_upload'] ?? 4);
            $auto_delete_rejected = isset($_POST['ne_mlp_auto_delete_rejected']) ? 1 : 0;
            $delete_after_days = intval($_POST['ne_mlp_delete_after_days'] ?? 30);
            $require_prescription_products = isset($_POST['ne_mlp_require_prescription_products']) ? 1 : 0;
            $prescription_required_message = sanitize_textarea_field($_POST['ne_mlp_prescription_required_message'] ?? '');

            // Validation
            $validation_errors = [];

            if ($max_file_size < 1 || $max_file_size > 50) {
                $validation_errors[] = __('Maximum file size must be between 1 and 50 MB.', 'ne-med-lab-prescriptions');
            }

            if ($max_files < 1 || $max_files > 10) {
                $validation_errors[] = __('Maximum files per upload must be between 1 and 10.', 'ne-med-lab-prescriptions');
            }

            if ($auto_delete_rejected && ($delete_after_days < 1 || $delete_after_days > 365)) {
                $validation_errors[] = __('Delete after days must be between 1 and 365.', 'ne-med-lab-prescriptions');
            }

            if (empty($validation_errors)) {
                update_option('ne_mlp_allowed_file_types', $file_types);
                update_option('ne_mlp_max_file_size', $max_file_size);
                update_option('ne_mlp_max_files_per_upload', $max_files);
                update_option('ne_mlp_auto_delete_rejected', $auto_delete_rejected);
                update_option('ne_mlp_delete_after_days', $delete_after_days);
                update_option('ne_mlp_require_prescription_products', $require_prescription_products);
                update_option('ne_mlp_prescription_required_message', $prescription_required_message);

                echo '<div class="notice notice-success"><p>' . esc_html__('General settings saved successfully.', 'ne-med-lab-prescriptions') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html(implode('<br>', $validation_errors)) . '</p></div>';
            }
        }

        // Get current settings
        $allowed_file_types = get_option('ne_mlp_allowed_file_types', ['jpg', 'jpeg', 'png', 'pdf']);
        $max_file_size = get_option('ne_mlp_max_file_size', 5);
        $max_files = get_option('ne_mlp_max_files_per_upload', 4);
        $auto_delete_rejected = get_option('ne_mlp_auto_delete_rejected', 0);
        $delete_after_days = get_option('ne_mlp_delete_after_days', 30);
        $require_prescription_products = get_option('ne_mlp_require_prescription_products', 1);
        $prescription_required_message = get_option(
            'ne_mlp_prescription_required_message',
            'This product requires a valid prescription. Please upload your prescription before completing your order.'
        );

        echo '<h2>' . esc_html__('General Settings', 'ne-med-lab-prescriptions') . '</h2>';
        echo '<p class="description">' . esc_html__('Configure general plugin settings and file upload preferences.', 'ne-med-lab-prescriptions') . '</p>';

        echo '<form method="post" action="">';
        wp_nonce_field('ne_mlp_general_settings', 'ne_mlp_general_nonce');

        echo '<table class="form-table" role="presentation">';

        // Allowed File Types
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Allowed File Types', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        $available_types = ['jpg' => 'JPG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'pdf' => 'PDF', 'gif' => 'GIF'];
        foreach ($available_types as $ext => $label) {
            $checked = in_array($ext, $allowed_file_types) ? 'checked' : '';
            echo '<label style="margin-right:15px;">';
            echo '<input type="checkbox" name="ne_mlp_allowed_file_types[]" value="' . esc_attr($ext) . '" ' . $checked . ' />';
            echo ' ' . esc_html($label);
            echo '</label>';
        }
        echo '<p class="description">' . esc_html__('Select which file types users can upload for prescriptions.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Maximum File Size
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_max_file_size">' . esc_html__('Maximum File Size (MB)', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="number" name="ne_mlp_max_file_size" id="ne_mlp_max_file_size" value="' . esc_attr($max_file_size) . '" min="1" max="50" class="small-text" />';
        echo ' MB';
        echo '<p class="description">' . esc_html__('Maximum file size allowed for each uploaded file. Range: 1-50 MB.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Maximum Files per Upload
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_max_files_per_upload">' . esc_html__('Maximum Files per Upload', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="number" name="ne_mlp_max_files_per_upload" id="ne_mlp_max_files_per_upload" value="' . esc_attr($max_files) . '" min="1" max="10" class="small-text" />';
        echo ' files';
        echo '<p class="description">' . esc_html__('Maximum number of files users can upload in a single prescription submission. Range: 1-10 files.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Prescription Product Requirement
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Prescription Required Products', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_require_prescription_products" value="1"' . checked($require_prescription_products, 1, false) . ' id="ne_mlp_require_prescription_products" />';
        echo ' ' . esc_html__('Enforce prescription requirement for products marked as prescription-required', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, customers cannot checkout with prescription-required products until they upload and get approved prescriptions.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Prescription Required Message
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_prescription_required_message">' . esc_html__('Prescription Required Message', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_prescription_required_message" id="ne_mlp_prescription_required_message" rows="3" cols="50" class="large-text">' . esc_textarea($prescription_required_message) . '</textarea>';
        echo '<p class="description">' . esc_html__('Message displayed to customers when they try to purchase prescription-required products without valid prescriptions.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // File Management Section
        echo '<h3 style="margin-top:30px;">' . esc_html__('File Management', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<p class="description">' . esc_html__('Configure automatic file cleanup and storage management.', 'ne-med-lab-prescriptions') . '</p>';

        echo '<table class="form-table" role="presentation">';

        // Auto Delete Rejected Prescriptions
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Auto-Delete Rejected Prescriptions', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_auto_delete_rejected" value="1"' . checked($auto_delete_rejected, 1, false) . ' id="ne_mlp_auto_delete_rejected" />';
        echo ' ' . esc_html__('Automatically delete rejected prescriptions after specified days', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, rejected prescriptions and their files will be permanently deleted after the specified number of days.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Delete After Days
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_delete_after_days">' . esc_html__('Delete After (Days)', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="number" name="ne_mlp_delete_after_days" id="ne_mlp_delete_after_days" value="' . esc_attr($delete_after_days) . '" min="1" max="365" class="small-text" />';
        echo ' days';
        echo '<p class="description">' . esc_html__('Number of days to wait before auto-deleting rejected prescriptions. Range: 1-365 days.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Save button
        echo '<p class="submit">';
        echo '<input type="submit" name="ne_mlp_save_general_settings" class="button-primary" value="' . esc_attr__('Save General Settings', 'ne-med-lab-prescriptions') . '" />';
        echo '</p>';

        echo '</form>';

        // Storage Info Section
        echo '<div style="margin-top:30px;background:#e7f3ff;border:1px solid #bee5eb;border-radius:4px;padding:16px;">';
        echo '<h4 style="margin-top:0;color:#004085;">' . esc_html__('📁 Storage Information', 'ne-med-lab-prescriptions') . '</h4>';
        $upload_dir = wp_upload_dir();
        $presc_dir = $upload_dir['basedir'] . '/ne-mlp-prescriptions';
        if (file_exists($presc_dir)) {
            $dir_size = $this->get_directory_size($presc_dir);
            $file_count = $this->count_files_in_directory($presc_dir);
            echo '<p><strong>Upload Directory:</strong> ' . esc_html($presc_dir) . '</p>';
            echo '<p><strong>Total Files:</strong> ' . esc_html($file_count) . '</p>';
            echo '<p><strong>Total Size:</strong> ' . esc_html($this->format_bytes($dir_size)) . '</p>';
        } else {
            echo '<p>' . esc_html__('Upload directory will be created when first prescription is uploaded.', 'ne-med-lab-prescriptions') . '</p>';
        }
        echo '</div>';

        // Show/hide delete days field based on auto-delete checkbox
        echo '<script>
        jQuery(document).ready(function($) {
            function toggleDeleteDays() {
                var enabled = $("#ne_mlp_auto_delete_rejected").is(":checked");
                $("#ne_mlp_delete_after_days").closest("tr").toggle(enabled);
            }
            
            $("#ne_mlp_auto_delete_rejected").on("change", toggleDeleteDays);
            toggleDeleteDays(); // Initial state
        });
        </script>';
    }

    // Render Email Notifications Settings tab
    private function render_email_settings()
    {
        // Handle form submission
        if (isset($_POST['ne_mlp_save_email_settings'])) {
            check_admin_referer('ne_mlp_email_settings', 'ne_mlp_email_nonce');

            // Save email notification settings
            $email_enabled = isset($_POST['ne_mlp_email_enabled']) ? 1 : 0;
            update_option('ne_mlp_email_notifications_enabled', $email_enabled);

            // Save email templates
            $templates = [
                'uploaded' => sanitize_textarea_field($_POST['ne_mlp_email_template_uploaded'] ?? ''),
                'approved' => sanitize_textarea_field($_POST['ne_mlp_email_template_approved'] ?? ''),
                'rejected' => sanitize_textarea_field($_POST['ne_mlp_email_template_rejected'] ?? '')
            ];
            update_option('ne_mlp_email_templates', $templates);

            echo '<div class="notice notice-success"><p>' . esc_html__('Email notification settings saved successfully.', 'ne-med-lab-prescriptions') . '</p></div>';
        }

        // Get current settings
        $email_enabled = get_option('ne_mlp_email_notifications_enabled', 1);
        $templates = get_option('ne_mlp_email_templates', $this->get_default_email_templates());

        echo '<h2>' . esc_html__('Email Notification Settings', 'ne-med-lab-prescriptions') . '</h2>';
        echo '<p class="description">' . esc_html__('Configure email notifications sent to users when prescription status changes.', 'ne-med-lab-prescriptions') . '</p>';

        echo '<form method="post" action="">';
        wp_nonce_field('ne_mlp_email_settings', 'ne_mlp_email_nonce');

        echo '<table class="form-table" role="presentation">';

        // Enable/Disable Email Notifications
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Enable Email Notifications', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_email_enabled" value="1"' . checked($email_enabled, 1, false) . ' />';
        echo ' ' . esc_html__('Send email notifications to users when prescription status changes', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When disabled, no email notifications will be sent.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Email Templates Section
        echo '<h3 style="margin-top:30px;">' . esc_html__('Email Templates', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<p class="description">' . esc_html__('Customize the email templates sent to users. You can use the following placeholders:', 'ne-med-lab-prescriptions') . '</p>';

        // Placeholder guide
        echo '<div style="background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;padding:12px;margin:16px 0;">';
        echo '<strong>' . esc_html__('Available Placeholders:', 'ne-med-lab-prescriptions') . '</strong><br>';
        echo '<code>{username}</code> - ' . esc_html__('User display name', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{user_email}</code> - ' . esc_html__('User email address', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{prescription_id}</code> - ' . esc_html__('Formatted prescription ID (e.g., Med-01-12-23-001)', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{prescription_type}</code> - ' . esc_html__('Medicine or Lab Test', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{upload_date}</code> - ' . esc_html__('Date prescription was uploaded', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{site_name}</code> - ' . esc_html__('Website name', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{site_url}</code> - ' . esc_html__('Website URL', 'ne-med-lab-prescriptions') . '<br>';
        echo '</div>';

        echo '<table class="form-table" role="presentation">';

        // Prescription Uploaded Template
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_email_template_uploaded">' . esc_html__('Prescription Uploaded', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_email_template_uploaded" id="ne_mlp_email_template_uploaded" rows="8" cols="50" class="large-text code">' . esc_textarea($templates['uploaded']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Email sent when a user uploads a new prescription.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Prescription Approved Template
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_email_template_approved">' . esc_html__('Prescription Approved', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_email_template_approved" id="ne_mlp_email_template_approved" rows="8" cols="50" class="large-text code">' . esc_textarea($templates['approved']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Email sent when admin approves a prescription.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Prescription Rejected Template
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_email_template_rejected">' . esc_html__('Prescription Rejected', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_email_template_rejected" id="ne_mlp_email_template_rejected" rows="8" cols="50" class="large-text code">' . esc_textarea($templates['rejected']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Email sent when admin rejects a prescription.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Save button
        echo '<p class="submit">';
        echo '<input type="submit" name="ne_mlp_save_email_settings" class="button-primary" value="' . esc_attr__('Save Email Settings', 'ne-med-lab-prescriptions') . '" />';
        echo '</p>';

        echo '</form>';
    }

    // Get default email templates
    private function get_default_email_templates()
    {
        return [
            'uploaded' => "Hi {username},\n\nThank you for uploading your prescription to {site_name}.\n\nPrescription Details:\n- Prescription ID: {prescription_id}\n- Type: {prescription_type}\n- Upload Date: {upload_date}\n\nOur team will review your prescription and notify you once it's processed.\n\nBest regards,\n{site_name} Team\n{site_url}",

            'approved' => "Hi {username},\n\nGreat news! Your prescription has been approved.\n\nPrescription Details:\n- Prescription ID: {prescription_id}\n- Type: {prescription_type}\n- Upload Date: {upload_date}\n\nYou can now proceed with your order. If you have any questions, please don't hesitate to contact us.\n\nBest regards,\n{site_name} Team\n{site_url}",

            'rejected' => "Hi {username},\n\nWe're sorry, but your prescription has been rejected after review.\n\nPrescription Details:\n- Prescription ID: {prescription_id}\n- Type: {prescription_type}\n- Upload Date: {upload_date}\n\nPlease upload a valid prescription to proceed with your order. For guidance on acceptable prescription formats, please visit our help section.\n\nIf you believe this is an error, please contact our support team.\n\nBest regards,\n{site_name} Team\n{site_url}"
        ];
    }

    // Process email template with placeholders
    private function process_email_template($template, $user, $prescription)
    {
        // Get prescription manager for ID formatting
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $prescription_id = $prescription_manager->format_prescription_id($prescription);

        // Define placeholders and their values
        $placeholders = [
            '{username}' => $user->display_name,
            '{user_email}' => $user->user_email,
            '{prescription_id}' => $prescription_id,
            '{prescription_type}' => ucfirst($prescription->type === 'lab_test' ? 'Lab Test' : 'Medicine'),
            '{upload_date}' => date('F j, Y g:i A', strtotime($prescription->created_at)),
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => home_url()
        ];

        // Replace placeholders in template
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    // Send customizable email notification
    public function send_email_notification($user, $prescription, $type)
    {
        // Check if email notifications are enabled
        if (!get_option('ne_mlp_email_notifications_enabled', 1)) {
            return;
        }

        // Get email templates
        $templates = get_option('ne_mlp_email_templates', $this->get_default_email_templates());

        if (!isset($templates[$type])) {
            return;
        }

        // Process template with placeholders
        $message = $this->process_email_template($templates[$type], $user, $prescription);

        // Create subject based on type
        $subjects = [
            'uploaded' => __('Prescription Uploaded Successfully', 'ne-med-lab-prescriptions'),
            'approved' => __('Prescription Approved', 'ne-med-lab-prescriptions'),
            'rejected' => __('Prescription Rejected', 'ne-med-lab-prescriptions')
        ];

        $subject = isset($subjects[$type]) ? $subjects[$type] : __('Prescription Update', 'ne-med-lab-prescriptions');

        // Send email
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $formatted_message = nl2br(esc_html($message));

        wp_mail($user->user_email, $subject, $formatted_message, $headers);
    }

    // Send OneSignal push notification
    public function send_onesignal_notification($user, $prescription, $type)
    {
        // Check if OneSignal notifications are enabled
        if (!get_option('ne_mlp_onesignal_notifications_enabled', 0)) {
            return;
        }

        // Get OneSignal credentials
        $app_id = get_option('ne_mlp_onesignal_app_id', '');
        $api_key = get_option('ne_mlp_onesignal_api_key', '');

        if (empty($app_id) || empty($api_key)) {
            error_log('NE MLP: OneSignal credentials not configured');
            return;
        }

        // Get notification content based on type
        $notification_data = $this->get_onesignal_notification_content($user, $prescription, $type);

        if (!$notification_data) {
            error_log('NE MLP: Failed to generate OneSignal notification content');
            return;
        }

        // Send notification via OneSignal API
        $this->send_onesignal_api_request($app_id, $api_key, $notification_data, $user, $prescription);
    }

    // Get OneSignal notification content based on type
    private function get_onesignal_notification_content($user, $prescription, $type)
    {
        // Get custom push templates
        $push_templates = get_option('ne_mlp_push_templates', $this->get_default_push_templates());

        if (!isset($push_templates[$type])) {
            return null;
        }

        $template = $push_templates[$type];

        // Process templates with placeholders (same as email)
        $processed_title = $this->process_push_template($template['title'], $user, $prescription);
        $processed_message = $this->process_push_template($template['message'], $user, $prescription);

        return [
            'title' => $processed_title,
            'message' => $processed_message,
            'icon' => $type
        ];
    }

    // Get default push notification templates
    private function get_default_push_templates()
    {
        return [
            'uploaded' => [
                'title' => '📋 Prescription Uploaded',
                'message' => 'Your {prescription_type} prescription ({prescription_id}) has been uploaded to {site_name} and is being reviewed.'
            ],
            'approved' => [
                'title' => '✅ Prescription Approved',
                'message' => 'Great news! Your {prescription_type} prescription ({prescription_id}) has been approved. You can now proceed with your order.'
            ],
            'rejected' => [
                'title' => '❌ Prescription Rejected',
                'message' => 'Your {prescription_type} prescription ({prescription_id}) has been rejected. Please upload a valid prescription to proceed.'
            ]
        ];
    }

    // Process push notification template with placeholders
    private function process_push_template($template, $user, $prescription)
    {
        // Get prescription manager for ID formatting
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $prescription_id = $prescription_manager->format_prescription_id($prescription);

        // Define placeholders and their values (subset of email placeholders for push)
        $placeholders = [
            '{username}' => $user->display_name,
            '{prescription_id}' => $prescription_id,
            '{prescription_type}' => ucfirst($prescription->type === 'lab_test' ? 'Lab Test' : 'Medicine'),
            '{site_name}' => get_bloginfo('name')
        ];

        // Replace placeholders in template
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    // Send notification via OneSignal REST API
    private function send_onesignal_api_request($app_id, $api_key, $notification_data, $user, $prescription)
    {
        $url = 'https://onesignal.com/api/v1/notifications';

        // Get prescription manager instance for ID formatting
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();

        // Prepare notification payload
        $payload = [
            'app_id' => $app_id,
            'headings' => ['en' => $notification_data['title']],
            'contents' => ['en' => $notification_data['message']],
            'filters' => [
                [
                    'field' => 'tag',
                    'key' => 'user_id',
                    'relation' => '=',
                    'value' => (string) $user->ID
                ]
            ],
            'web_url' => home_url(),
            'chrome_web_icon' => $this->get_notification_icon_url($notification_data['icon']),
            'firefox_icon' => $this->get_notification_icon_url($notification_data['icon']),
            'data' => [
                'prescription_id' => $prescription_manager->format_prescription_id($prescription),
                'user_id' => $user->ID,
                'type' => $notification_data['icon']
            ]
        ];

        // Prepare request headers
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $api_key
        ];

        // Make the API request
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ]);

        // Handle response
        if (is_wp_error($response)) {
            error_log('NE MLP OneSignal Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            error_log('NE MLP OneSignal API Error: HTTP ' . $response_code . ' - ' . $response_body);
            return false;
        }

        // Log successful notification
        $result = json_decode($response_body, true);
        if (isset($result['id'])) {
            error_log('NE MLP: OneSignal notification sent successfully. ID: ' . $result['id']);
            return true;
        } else {
            error_log('NE MLP OneSignal Error: Invalid response - ' . $response_body);
            return false;
        }
    }

    // Get notification icon URL based on type
    private function get_notification_icon_url($type)
    {
        $default_icon = NE_MLP_PLUGIN_URL . 'assets/image/validate_rx.svg';

        // You can customize icons for different notification types
        $icons = [
            'upload' => $default_icon,
            'approved' => $default_icon,
            'rejected' => $default_icon
        ];

        return isset($icons[$type]) ? $icons[$type] : $default_icon;
    }

    // Method to trigger upload notification (called from other components)
    public function trigger_upload_notification($user, $prescription)
    {
        // Send both email and push notifications for uploads
        $this->send_email_notification($user, $prescription, 'uploaded');
        $this->send_onesignal_notification($user, $prescription, 'uploaded');
    }

    // Render OneSignal Push Notifications Settings tab
    private function render_onesignal_settings()
    {
        // Handle form submission
        if (isset($_POST['ne_mlp_save_onesignal_settings'])) {
            check_admin_referer('ne_mlp_onesignal_settings', 'ne_mlp_onesignal_nonce');

            // Save OneSignal notification settings
            $onesignal_enabled = isset($_POST['ne_mlp_onesignal_enabled']) ? 1 : 0;
            update_option('ne_mlp_onesignal_notifications_enabled', $onesignal_enabled);

            // Save OneSignal credentials
            $app_id = sanitize_text_field($_POST['ne_mlp_onesignal_app_id'] ?? '');
            $api_key = sanitize_text_field($_POST['ne_mlp_onesignal_api_key'] ?? '');

            // Save OneSignal notification templates
            $push_templates = [
                'uploaded' => [
                    'title' => sanitize_text_field($_POST['ne_mlp_push_title_uploaded'] ?? ''),
                    'message' => sanitize_textarea_field($_POST['ne_mlp_push_message_uploaded'] ?? '')
                ],
                'approved' => [
                    'title' => sanitize_text_field($_POST['ne_mlp_push_title_approved'] ?? ''),
                    'message' => sanitize_textarea_field($_POST['ne_mlp_push_message_approved'] ?? '')
                ],
                'rejected' => [
                    'title' => sanitize_text_field($_POST['ne_mlp_push_title_rejected'] ?? ''),
                    'message' => sanitize_textarea_field($_POST['ne_mlp_push_message_rejected'] ?? '')
                ]
            ];
            update_option('ne_mlp_push_templates', $push_templates);

            $validation_errors = [];

            // Basic validation
            if ($onesignal_enabled) {
                if (empty($app_id)) {
                    $validation_errors[] = __('OneSignal App ID is required when push notifications are enabled.', 'ne-med-lab-prescriptions');
                } elseif (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $app_id)) {
                    $validation_errors[] = __('OneSignal App ID must be a valid UUID format.', 'ne-med-lab-prescriptions');
                }

                if (empty($api_key)) {
                    $validation_errors[] = __('OneSignal REST API Key is required when push notifications are enabled.', 'ne-med-lab-prescriptions');
                } elseif (strlen($api_key) < 20) {
                    $validation_errors[] = __('OneSignal REST API Key appears to be too short.', 'ne-med-lab-prescriptions');
                }
            }

            if (empty($validation_errors)) {
                update_option('ne_mlp_onesignal_app_id', $app_id);
                update_option('ne_mlp_onesignal_api_key', $api_key);

                echo '<div class="notice notice-success"><p>' . esc_html__('OneSignal settings saved successfully.', 'ne-med-lab-prescriptions') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html(implode('<br>', $validation_errors)) . '</p></div>';
            }
        }

        // Get current settings
        $onesignal_enabled = get_option('ne_mlp_onesignal_notifications_enabled', 0);
        $app_id = get_option('ne_mlp_onesignal_app_id', '');
        $api_key = get_option('ne_mlp_onesignal_api_key', '');
        $push_templates = get_option('ne_mlp_push_templates', $this->get_default_push_templates());

        echo '<h2>' . esc_html__('Push Notification Settings', 'ne-med-lab-prescriptions') . '</h2>';
        echo '<p class="description">' . esc_html__('Configure OneSignal push notifications for real-time updates to users. Push notifications will be sent parallel to email notifications for prescription status changes.', 'ne-med-lab-prescriptions') . '</p>';

        // OneSignal setup guide
        echo '<div style="background:#e7f3ff;border:1px solid #bee5eb;border-radius:4px;padding:16px;margin:16px 0;">';
        echo '<h4 style="margin-top:0;color:#004085;">' . esc_html__('OneSignal Setup Guide', 'ne-med-lab-prescriptions') . '</h4>';
        echo '<ol style="margin:0;padding-left:20px;">';
        echo '<li>' . sprintf(
            esc_html__('Create a free account at %s', 'ne-med-lab-prescriptions'),
            '<a href="https://onesignal.com" target="_blank">OneSignal.com</a>'
        ) . '</li>';
        echo '<li>' . esc_html__('Create a new Web App in your OneSignal dashboard', 'ne-med-lab-prescriptions') . '</li>';
        echo '<li>' . esc_html__('Copy your App ID from Settings > Keys & IDs', 'ne-med-lab-prescriptions') . '</li>';
        echo '<li>' . esc_html__('Copy your REST API Key from Settings > Keys & IDs', 'ne-med-lab-prescriptions') . '</li>';
        echo '<li>' . esc_html__('Enter both values below and enable push notifications', 'ne-med-lab-prescriptions') . '</li>';
        echo '</ol>';
        echo '</div>';

        echo '<form method="post" action="">';
        wp_nonce_field('ne_mlp_onesignal_settings', 'ne_mlp_onesignal_nonce');

        echo '<table class="form-table" role="presentation">';

        // Enable/Disable OneSignal Notifications
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Enable Push Notifications', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_onesignal_enabled" value="1"' . checked($onesignal_enabled, 1, false) . ' id="ne_mlp_onesignal_enabled" />';
        echo ' ' . esc_html__('Send push notifications to users via OneSignal', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, users will receive push notifications for prescription status updates.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // OneSignal App ID
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_onesignal_app_id">' . esc_html__('OneSignal App ID', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="text" name="ne_mlp_onesignal_app_id" id="ne_mlp_onesignal_app_id" value="' . esc_attr($app_id) . '" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />';
        echo '<p class="description">' . esc_html__('Your OneSignal App ID (UUID format). Found in Settings > Keys & IDs.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // OneSignal REST API Key
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_onesignal_api_key">' . esc_html__('OneSignal REST API Key', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="password" name="ne_mlp_onesignal_api_key" id="ne_mlp_onesignal_api_key" value="' . esc_attr($api_key) . '" class="regular-text" placeholder="Your OneSignal REST API Key" />';
        echo '<p class="description">' . esc_html__('Your OneSignal REST API Key. Found in Settings > Keys & IDs. Keep this secure.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Connection Status (if credentials are provided)
        if (!empty($app_id) && !empty($api_key)) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Connection Status', 'ne-med-lab-prescriptions') . '</th>';
            echo '<td>';
            echo '<div id="ne-mlp-onesignal-status" style="padding:8px 12px;border-radius:4px;background:#d4edda;border:1px solid #c3e6cb;color:#155724;">';
            echo '<span class="dashicons dashicons-yes-alt" style="margin-right:5px;"></span>';
            echo esc_html__('OneSignal credentials configured. Push notifications are ready to send.', 'ne-med-lab-prescriptions');
            echo '</div>';
            echo '<p class="description">';
            echo esc_html__('Push notifications will be sent to users who have subscribed to OneSignal notifications on your website. Make sure to integrate OneSignal on your frontend.', 'ne-med-lab-prescriptions');
            echo '</p>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        // Push Notification Templates Section
        echo '<h3 style="margin-top:30px;">' . esc_html__('Push Notification Templates', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<p class="description">' . esc_html__('Customize push notification content. You can use the same placeholders as email templates.', 'ne-med-lab-prescriptions') . '</p>';

        // Placeholder guide for push notifications
        echo '<div style="background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;padding:12px;margin:16px 0;">';
        echo '<strong>' . esc_html__('Available Placeholders:', 'ne-med-lab-prescriptions') . '</strong><br>';
        echo '<code>{username}</code> - ' . esc_html__('User display name', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{prescription_id}</code> - ' . esc_html__('Formatted prescription ID', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{prescription_type}</code> - ' . esc_html__('Medicine or Lab Test', 'ne-med-lab-prescriptions') . '<br>';
        echo '<code>{site_name}</code> - ' . esc_html__('Website name', 'ne-med-lab-prescriptions') . '<br>';
        echo '<strong>' . esc_html__('Note:', 'ne-med-lab-prescriptions') . '</strong> ' . esc_html__('Keep titles short (recommended: under 65 characters) and messages concise for better mobile display.', 'ne-med-lab-prescriptions');
        echo '</div>';

        echo '<table class="form-table" role="presentation">';

        // Prescription Uploaded Template
        echo '<tr>';
        echo '<th scope="row" colspan="2" style="padding-left:0;"><h4 style="margin:0;">' . esc_html__('📋 Prescription Uploaded Notification', 'ne-med-lab-prescriptions') . '</h4></th>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_push_title_uploaded">' . esc_html__('Title', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="text" name="ne_mlp_push_title_uploaded" id="ne_mlp_push_title_uploaded" value="' . esc_attr($push_templates['uploaded']['title']) . '" class="large-text" maxlength="65" />';
        echo '<p class="description">' . esc_html__('Push notification title for prescription uploads. Keep under 65 characters.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_push_message_uploaded">' . esc_html__('Message', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_push_message_uploaded" id="ne_mlp_push_message_uploaded" rows="3" cols="50" class="large-text" maxlength="200">' . esc_textarea($push_templates['uploaded']['message']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Push notification message for prescription uploads. Keep under 200 characters for best mobile experience.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Prescription Approved Template
        echo '<tr>';
        echo '<th scope="row" colspan="2" style="padding-left:0;"><h4 style="margin:0;">' . esc_html__('✅ Prescription Approved Notification', 'ne-med-lab-prescriptions') . '</h4></th>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_push_title_approved">' . esc_html__('Title', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="text" name="ne_mlp_push_title_approved" id="ne_mlp_push_title_approved" value="' . esc_attr($push_templates['approved']['title']) . '" class="large-text" maxlength="65" />';
        echo '<p class="description">' . esc_html__('Push notification title for prescription approvals.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_push_message_approved">' . esc_html__('Message', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_push_message_approved" id="ne_mlp_push_message_approved" rows="3" cols="50" class="large-text" maxlength="200">' . esc_textarea($push_templates['approved']['message']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Push notification message for prescription approvals.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Prescription Rejected Template
        echo '<tr>';
        echo '<th scope="row" colspan="2" style="padding-left:0;"><h4 style="margin:0;">' . esc_html__('❌ Prescription Rejected Notification', 'ne-med-lab-prescriptions') . '</h4></th>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_push_title_rejected">' . esc_html__('Title', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="text" name="ne_mlp_push_title_rejected" id="ne_mlp_push_title_rejected" value="' . esc_attr($push_templates['rejected']['title']) . '" class="large-text" maxlength="65" />';
        echo '<p class="description">' . esc_html__('Push notification title for prescription rejections.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_push_message_rejected">' . esc_html__('Message', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_push_message_rejected" id="ne_mlp_push_message_rejected" rows="3" cols="50" class="large-text" maxlength="200">' . esc_textarea($push_templates['rejected']['message']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Push notification message for prescription rejections.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Save button
        echo '<p class="submit">';
        echo '<input type="submit" name="ne_mlp_save_onesignal_settings" class="button-primary" value="' . esc_attr__('Save OneSignal Settings', 'ne-med-lab-prescriptions') . '" />';
        echo '</p>';

        echo '</form>';

        // Show/hide fields based on checkbox state
        echo '<script>
        jQuery(document).ready(function($) {
            function toggleOneSignalFields() {
                var enabled = $("#ne_mlp_onesignal_enabled").is(":checked");
                $("#ne_mlp_onesignal_app_id, #ne_mlp_onesignal_api_key").closest("tr").toggle(enabled);
            }
            
            $("#ne_mlp_onesignal_enabled").on("change", toggleOneSignalFields);
            toggleOneSignalFields(); // Initial state
        });
        </script>';
    }

    // Render API Settings tab
    private function render_api_settings()
    {
        // Handle form submission
        if (isset($_POST['ne_mlp_save_api_settings'])) {
            check_admin_referer('ne_mlp_api_settings', 'ne_mlp_api_nonce');

            // Save API settings
            $api_enabled = isset($_POST['ne_mlp_api_enabled']) ? 1 : 0;
            $rate_limit = intval($_POST['ne_mlp_rate_limit'] ?? 100);
            $allowed_origins = sanitize_textarea_field($_POST['ne_mlp_allowed_origins'] ?? '');
            $log_requests = isset($_POST['ne_mlp_log_requests']) ? 1 : 0;
            
            // Save webhook settings
            $webhook_enabled = isset($_POST['ne_mlp_webhook_enabled']) ? 1 : 0;
            $webhook_url = sanitize_url($_POST['ne_mlp_webhook_url'] ?? '');
            $webhook_secret = sanitize_text_field($_POST['ne_mlp_webhook_secret'] ?? '');
            
            // Save notification API settings  
            $notification_api_enabled = isset($_POST['ne_mlp_notification_api_enabled']) ? 1 : 0;
            $notification_api_key = sanitize_text_field($_POST['ne_mlp_notification_api_key'] ?? '');

            // Validation
            $validation_errors = [];

            if ($api_enabled) {
                if ($rate_limit < 10 || $rate_limit > 1000) {
                    $validation_errors[] = __('Rate limit must be between 10 and 1000 requests per hour.', 'ne-med-lab-prescriptions');
                }
            }

            if ($webhook_enabled && empty($webhook_url)) {
                $validation_errors[] = __('Webhook URL is required when webhooks are enabled.', 'ne-med-lab-prescriptions');
            }

            if (empty($validation_errors)) {
                update_option('ne_mlp_api_enabled', $api_enabled);
                update_option('ne_mlp_rate_limit', $rate_limit);
                update_option('ne_mlp_allowed_origins', $allowed_origins);
                update_option('ne_mlp_log_requests', $log_requests);

                update_option('ne_mlp_webhook_enabled', $webhook_enabled);
                update_option('ne_mlp_webhook_url', $webhook_url);
                update_option('ne_mlp_webhook_secret', $webhook_secret);
                
                update_option('ne_mlp_notification_api_enabled', $notification_api_enabled);
                update_option('ne_mlp_notification_api_key', $notification_api_key);

                echo '<div class="notice notice-success"><p>' . esc_html__('API settings saved successfully.', 'ne-med-lab-prescriptions') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html(implode('<br>', $validation_errors)) . '</p></div>';
            }
        }

        // Handle API key regeneration
        if (isset($_POST['ne_mlp_regenerate_api_key'])) {
            if (!current_user_can('manage_woocommerce')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Unauthorized: insufficient capability.', 'ne-med-lab-prescriptions') . '</p></div>';
            } else {
                try {
                    check_admin_referer('ne_mlp_regen_api_key', 'ne_mlp_regen_api_nonce');
                    $new_key = $this->generate_global_api_key();
                    update_option('_ne_global_api_key', $new_key, false);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Global API key regenerated successfully. The old key is now invalid.', 'ne-med-lab-prescriptions') . '</p></div>';
                } catch (Exception $e) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Failed to regenerate API key: ', 'ne-med-lab-prescriptions') . esc_html($e->getMessage()) . '</p></div>';
                }
            }
        }

        // Get current settings
        $api_enabled = get_option('ne_mlp_api_enabled', 1); // Default enabled
        $rate_limit = get_option('ne_mlp_rate_limit', 100);
        $allowed_origins = get_option('ne_mlp_allowed_origins', '');
        $log_requests = get_option('ne_mlp_log_requests', 0);

        // Get webhook settings
        $webhook_enabled = get_option('ne_mlp_webhook_enabled', 0);
        $webhook_url = get_option('ne_mlp_webhook_url', '');
        $webhook_secret = get_option('ne_mlp_webhook_secret', '');

        // Get notification API settings
        $notification_api_enabled = get_option('ne_mlp_notification_api_enabled', 0);
        $notification_api_key = get_option('ne_mlp_notification_api_key', '');

        echo '<h2>' . esc_html__('API Settings', 'ne-med-lab-prescriptions') . '</h2>';
        echo '<p class="description">' . esc_html__('Configure REST API endpoints and external integrations for prescription management.', 'ne-med-lab-prescriptions') . '</p>';

        // Ensure global API key exists
        $current_api_key = get_option('_ne_global_api_key');
        if (empty($current_api_key)) {
            $current_api_key = $this->generate_global_api_key();
            update_option('_ne_global_api_key', $current_api_key, false);
        }
        $masked_key = substr($current_api_key, 0, 6) . str_repeat('•', max(0, strlen($current_api_key) - 10)) . substr($current_api_key, -4);

        // API Information Box
        echo '<div style="background:#e7f3ff;border:1px solid #bee5eb;border-radius:4px;padding:16px;margin:16px 0;">';
        echo '<h4 style="margin-top:0;color:#004085;">' . esc_html__('API Endpoints Available', 'ne-med-lab-prescriptions') . '</h4>';
        echo '<div style="font-family:monospace;font-size:13px;line-height:1.6;">';
        echo '<strong>Base URL:</strong> ' . esc_html(rest_url('ne-mlp/v1/')) . '<br>';
        echo '<strong>Authentication:</strong> Include headers <code>X-API-KEY</code> and <code>X-USER-ID</code> with every request<br><br>';
        echo '<strong>Required Headers:</strong><br>';
        echo '• <code>X-API-KEY</code> - Global API key (generated below)<br>';
        echo '• <code>X-USER-ID</code> - WordPress user ID of the requesting user<br><br>';
        echo '<strong>Available Endpoints:</strong><br>';
        echo '• <code>GET /prescriptions</code> - Get user prescriptions<br>';
        echo '• <code>POST /prescriptions</code> - Upload new prescription<br>';
        echo '• <code>DELETE /prescriptions/{id}</code> - Delete prescription<br>';
        echo '• <code>POST /orders/attach-prescription</code> - Attach prescription to order<br>';
        echo '</div>';
        echo '</div>';

        // Global API Key box
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin:16px 0;">';
        echo '<h3 style="margin:0 0 10px;">' . esc_html__('Global API Key', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<p class="description" style="margin-top:4px;">' . esc_html__('This key is required in the X-API-KEY header for all API requests. Share securely only with trusted systems.', 'ne-med-lab-prescriptions') . '</p>';
        echo '<div style="display:flex;gap:12px;align-items:center;margin-top:8px;">';
        echo '<input type="text" readonly value="' . esc_attr($masked_key) . '" style="width:380px;max-width:100%;font-family:monospace;" />';
        echo '<button type="button" class="button" onclick="(function(){var i=this.previousElementSibling;i.value=\'' . esc_js($current_api_key) . '\';setTimeout(function(){i.value=\'' . esc_js($masked_key) . '\';},8000);}).call(this)">' . esc_html__('Reveal (8s)', 'ne-med-lab-prescriptions') . '</button>';
        echo '</div>';
        echo '<form method="post" action="" style="margin-top:12px;">';
        wp_nonce_field('ne_mlp_regen_api_key', 'ne_mlp_regen_api_nonce');
        echo '<input type="submit" name="ne_mlp_regenerate_api_key" class="button button-secondary" value="' . esc_attr__('Regenerate API Key', 'ne-med-lab-prescriptions') . '" onclick="return confirm(\'' . esc_js(__('Regenerate the API key now? All clients must update immediately.', 'ne-med-lab-prescriptions')) . '\');" />';
        echo '</form>';
        echo '</div>';

        echo '<form method="post" action="">';
        wp_nonce_field('ne_mlp_api_settings', 'ne_mlp_api_nonce');

        echo '<table class="form-table" role="presentation">';

        // Enable/Disable API
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Enable REST API', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_api_enabled" value="1"' . checked($api_enabled, 1, false) . ' id="ne_mlp_api_enabled" />';
        echo ' ' . esc_html__('Enable REST API endpoints for external integrations', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, external applications can interact with prescriptions via REST API.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Global API auth info row
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Authentication', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<div style="padding:8px 12px;border-radius:4px;background:#e7f3ff;border:1px solid #bee5eb;color:#004085;">';
        echo '<span class="dashicons dashicons-lock" style="margin-right:5px;"></span>';
        echo esc_html__('All API requests must include headers X-API-KEY (global key) and X-USER-ID (WordPress user ID).', 'ne-med-lab-prescriptions');
        echo '</div>';
        echo '<p class="description">' . esc_html__('Regenerate the key above to immediately invalidate old credentials.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Rate Limiting
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_rate_limit">' . esc_html__('Rate Limit', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="number" name="ne_mlp_rate_limit" id="ne_mlp_rate_limit" value="' . esc_attr($rate_limit) . '" min="10" max="1000" class="small-text" />';
        echo ' ' . esc_html__('requests per hour per user', 'ne-med-lab-prescriptions');
        echo '<p class="description">' . esc_html__('Maximum number of API requests allowed per user per hour. Range: 10-1000.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // CORS Origins
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_allowed_origins">' . esc_html__('Allowed Origins (CORS)', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<textarea name="ne_mlp_allowed_origins" id="ne_mlp_allowed_origins" rows="3" cols="50" class="large-text code" placeholder="https://yourmobileapp.com&#10;https://yourfrontend.com">' . esc_textarea($allowed_origins) . '</textarea>';
        echo '<p class="description">' . esc_html__('Enter allowed origins for CORS requests (one per line). Leave blank to allow all origins. Example: https://yourmobileapp.com', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Request Logging
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Request Logging', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_log_requests" value="1"' . checked($log_requests, 1, false) . ' />';
        echo ' ' . esc_html__('Log API requests for debugging and monitoring', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, API requests will be logged to WordPress error log. Useful for debugging but may increase log file size.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Webhook Settings Section
        echo '<h3 style="margin-top:30px;">' . esc_html__('Webhook Settings', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<p class="description">' . esc_html__('Configure webhooks to send prescription events to external systems in real-time.', 'ne-med-lab-prescriptions') . '</p>';

        echo '<table class="form-table" role="presentation">';

        // Enable Webhooks
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Enable Webhooks', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_webhook_enabled" value="1"' . checked($webhook_enabled, 1, false) . ' id="ne_mlp_webhook_enabled" />';
        echo ' ' . esc_html__('Send webhook notifications for prescription events', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, HTTP POST requests will be sent to your webhook URL for prescription uploads, approvals, and rejections.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Webhook URL
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_webhook_url">' . esc_html__('Webhook URL', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="url" name="ne_mlp_webhook_url" id="ne_mlp_webhook_url" value="' . esc_attr($webhook_url) . '" class="regular-text" placeholder="https://yourapp.com/webhooks/prescriptions" />';
        echo '<p class="description">' . esc_html__('The URL where webhook payloads will be sent. Must be HTTPS for security.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Webhook Secret
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_webhook_secret">' . esc_html__('Webhook Secret', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="password" name="ne_mlp_webhook_secret" id="ne_mlp_webhook_secret" value="' . esc_attr($webhook_secret) . '" class="regular-text" placeholder="Enter a secret key for webhook verification" />';
        echo '<p class="description">' . esc_html__('Optional secret key for webhook signature verification. Recommended for security.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // (Legacy JWT settings removed)

        // Notification API Settings Section
        echo '<h3 style="margin-top:30px;">' . esc_html__('External Notification API', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<p class="description">' . esc_html__('Configure external notification services (Firebase, Pusher, etc.) for mobile app notifications.', 'ne-med-lab-prescriptions') . '</p>';

        echo '<table class="form-table" role="presentation">';

        // Enable Notification API
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Enable External Notifications', 'ne-med-lab-prescriptions') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="ne_mlp_notification_api_enabled" value="1"' . checked($notification_api_enabled, 1, false) . ' id="ne_mlp_notification_api_enabled" />';
        echo ' ' . esc_html__('Send notifications via external API (Firebase, Pusher, etc.)', 'ne-med-lab-prescriptions');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Enable this to send notifications to mobile apps or external systems via their APIs.', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Notification API Key
        echo '<tr>';
        echo '<th scope="row">';
        echo '<label for="ne_mlp_notification_api_key">' . esc_html__('API Key/Token', 'ne-med-lab-prescriptions') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<input type="password" name="ne_mlp_notification_api_key" id="ne_mlp_notification_api_key" value="' . esc_attr($notification_api_key) . '" class="regular-text" placeholder="Firebase Server Key or API Token" />';
        echo '<p class="description">' . esc_html__('API key for your external notification service (Firebase Server Key, Pusher API Key, etc.).', 'ne-med-lab-prescriptions') . '</p>';
        echo '</td>';
        echo '</tr>';

        // API Status (if enabled)
        if ($api_enabled) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('API Status', 'ne-med-lab-prescriptions') . '</th>';
            echo '<td>';
            echo '<div style="padding:8px 12px;border-radius:4px;background:#d4edda;border:1px solid #c3e6cb;color:#155724;">';
            echo '<span class="dashicons dashicons-yes-alt" style="margin-right:5px;"></span>';
            echo esc_html__('REST API is enabled and ready to accept requests.', 'ne-med-lab-prescriptions');
            echo '</div>';
            echo '<p class="description">';
            echo esc_html__('API endpoints are accessible at: ', 'ne-med-lab-prescriptions') . '<code>' . esc_html(rest_url('ne-mlp/v1/')) . '</code>';
            echo '</p>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        // Save button
        echo '<p class="submit">';
        echo '<input type="submit" name="ne_mlp_save_api_settings" class="button-primary" value="' . esc_attr__('Save API Settings', 'ne-med-lab-prescriptions') . '" />';
        echo '</p>';

        echo '</form>';

        // API Implementation Examples Section
        echo '<div style="margin-top:40px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;color:#495057;">' . esc_html__('🔧 API Implementation Examples', 'ne-med-lab-prescriptions') . '</h3>';

        // REST API Examples
        echo '<h4 style="color:#6c757d;">' . esc_html__('REST API Usage Examples', 'ne-med-lab-prescriptions') . '</h4>';
        echo '<div style="font-family:monospace;font-size:13px;background:#ffffff;border:1px solid #ddd;border-radius:4px;padding:15px;margin-bottom:20px;">';
        echo '<strong>1. Upload Prescription (POST /wp-json/ne-mlp/v1/prescriptions)</strong><br>';
        echo '<code style="color:#d73527;">curl -X POST ' . esc_html(rest_url('ne-mlp/v1/prescriptions')) . ' \\\n+<br>';
        echo '&nbsp;&nbsp;-H "X-API-KEY: YOUR_GLOBAL_KEY" \\\n+<br>';
        echo '&nbsp;&nbsp;-H "X-USER-ID: 123" \\\n+<br>';
        echo '&nbsp;&nbsp;-F "type=medicine" \\\n+<br>';
        echo '&nbsp;&nbsp;-F "files[]=@prescription1.pdf"</code><br><br>';

        echo '<strong>2. Get User Prescriptions (GET /wp-json/ne-mlp/v1/prescriptions)</strong><br>';
        echo '<code style="color:#d73527;">curl -X GET ' . esc_html(rest_url('ne-mlp/v1/prescriptions')) . ' \\\n+<br>';
        echo '&nbsp;&nbsp;-H "X-API-KEY: YOUR_GLOBAL_KEY" \\\n+<br>';
        echo '&nbsp;&nbsp;-H "X-USER-ID: 123"</code><br>';
        echo '</div>';

        // Webhook Examples
        echo '<h4 style="color:#6c757d;">' . esc_html__('Webhook Payload Examples', 'ne-med-lab-prescriptions') . '</h4>';
        echo '<div style="font-family:monospace;font-size:13px;background:#ffffff;border:1px solid #ddd;border-radius:4px;padding:15px;margin-bottom:20px;">';
        echo '<strong>Prescription Upload Event:</strong><br>';
        echo '<code style="color:#6c757d;">{<br>';
        echo '&nbsp;&nbsp;"event": "prescription.uploaded",<br>';
        echo '&nbsp;&nbsp;"timestamp": "2023-12-01T10:30:00Z",<br>';
        echo '&nbsp;&nbsp;"data": {<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;"prescription_id": "Med-01-12-23-001",<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;"user_id": 123,<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;"user_email": "user@example.com",<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;"type": "medicine",<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;"status": "pending",<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;"files": ["file1.pdf", "file2.jpg"]<br>';
        echo '&nbsp;&nbsp;}<br>';
        echo '}</code>';
        echo '</div>';

    }

    // Handle approve/reject actions
    public function handle_prescription_action()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized', 'ne-med-lab-prescriptions'));
        }
        $presc_id = isset($_GET['presc_id']) ? intval($_GET['presc_id']) : 0;
        $do = isset($_GET['do']) ? sanitize_text_field($_GET['do']) : '';
        if (!$presc_id || !in_array($do, ['approve', 'reject'])) {
            wp_die(__('Invalid request', 'ne-med-lab-prescriptions'));
        }
        check_admin_referer('ne_mlp_prescription_action_' . $presc_id);
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $wpdb->update($table, ['status' => $do === 'approve' ? 'approved' : 'rejected'], ['id' => $presc_id]);
        // Fetch prescription and user info for email
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $presc_id));
        if ($presc) {
            $user = get_userdata($presc->user_id);
            if ($user) {
                $this->send_status_email($user, $presc, $do);
            }
            // If linked to order, update order status
            if ($presc->order_id) {
                $order = wc_get_order($presc->order_id);
                if ($order) {
                    if ($do === 'approve') {
                        $order->update_status('processing', __('Prescription approved.', 'ne-med-lab-prescriptions'));
                        update_post_meta($order->get_id(), '_ne_mlp_prescription_status', 'approved');
                    } else {
                        $order->update_status('on-hold', __('Prescription rejected.', 'ne-med-lab-prescriptions'));
                        update_post_meta($order->get_id(), '_ne_mlp_prescription_status', 'rejected');
                    }
                }
            }
        }
        wp_redirect(admin_url('admin.php?page=ne-mlp-prescriptions'));
        exit;
    }

    // Send email notification to user on approval/rejection
    private function send_status_email($user, $presc, $action)
    {
        // Use the new customizable email notification system
        $type = $action === 'approve' ? 'approved' : 'rejected';
        $this->send_email_notification($user, $presc, $type);

        // Also send push notification
        $this->send_onesignal_notification($user, $presc, $type);
    }

    // Enqueue custom admin CSS for modern look
    public function enqueue_admin_styles($hook)
    {
        // Always load admin CSS for notification badge in menu
        wp_enqueue_style('ne-mlp-admin', NE_MLP_PLUGIN_URL . 'assets/css/ne-mlp-admin.css', [], NE_MLP_VERSION);

        // Load scripts only on prescriptions-related admin pages
        if (
            $hook === 'toplevel_page_ne-mlp-prescriptions' ||
            $hook === 'prescriptions_page_ne-mlp-assign-order' ||
            $hook === 'prescriptions_page_ne-mlp-deattach' ||
            $hook === 'prescriptions_page_ne-mlp-manual-upload' ||
            $hook === 'prescriptions_page_ne-mlp-settings' ||
            (isset($_GET['page']) && in_array($_GET['page'], ['ne-mlp-prescriptions', 'ne-mlp-manual-upload', 'ne-mlp-assign-order', 'ne-mlp-deattach', 'ne-mlp-settings']))
        ) {
            wp_enqueue_script('jquery-ui-autocomplete');

            // Enqueue Select2 from CDN if not already loaded
            if (!wp_script_is('select2', 'enqueued')) {
                wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
                wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
            }

            wp_enqueue_script('ne-mlp-admin-js', NE_MLP_PLUGIN_URL . 'assets/js/ne-mlp-admin.js', ['jquery', 'jquery-ui-autocomplete', 'select2'], NE_MLP_VERSION, true);
            wp_localize_script('ne-mlp-admin-js', 'ajaxurl', admin_url('admin-ajax.php'));
            wp_localize_script('ne-mlp-admin-js', 'neMLP', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ne_mlp_admin_action'), // for approve/reject/delete
                'download_nonce' => wp_create_nonce('ne_mlp_admin_nonce') // for download
            ]);
        }
    }

    // AJAX: Approve prescription
    public function ajax_admin_approve_presc()
    {
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized');
        check_ajax_referer('ne_mlp_admin_action', 'nonce');
        $presc_id = intval($_POST['presc_id']);

        // Use centralized prescription manager for status update
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $result = $prescription_manager->update_prescription_status($presc_id, 'approved');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Send notifications
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $presc_id));
        if ($presc) {
            $user = get_userdata($presc->user_id);
            if ($user) {
                $this->send_email_notification($user, $presc, 'approved');
                $this->send_onesignal_notification($user, $presc, 'approved');
            }
        }

        wp_send_json_success('Approved');
    }

    // AJAX: Reject prescription
    public function ajax_admin_reject_presc()
    {
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized');
        check_ajax_referer('ne_mlp_admin_action', 'nonce');
        $presc_id = intval($_POST['presc_id']);
        $reject_note = isset($_POST['reject_note']) ? sanitize_textarea_field($_POST['reject_note']) : null;

        // Use centralized prescription manager for status update
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $result = $prescription_manager->update_prescription_status($presc_id, 'rejected', $reject_note);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Send notifications
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $presc_id));
        if ($presc) {
            $user = get_userdata($presc->user_id);
            if ($user) {
                $this->send_email_notification($user, $presc, 'rejected');
                $this->send_onesignal_notification($user, $presc, 'rejected');
            }
        }

        wp_send_json_success('Rejected');
    }

    // AJAX: Delete prescription
    public function ajax_admin_delete_presc()
    {
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized');
        check_ajax_referer('ne_mlp_admin_action', 'nonce');
        $presc_id = intval($_POST['presc_id']);
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $wpdb->delete($table, ['id' => $presc_id]);
        wp_send_json_success('Deleted');
    }

    public function ajax_search_users()
    {
        if (isset($_REQUEST['nonce'])) {
            check_ajax_referer('ne_mlp_admin_nonce', 'nonce');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $search = '';
        if (isset($_GET['search'])) {
            $search = sanitize_text_field($_GET['search']);
        } elseif (isset($_GET['q'])) { // select2 typically sends 'q'
            $search = sanitize_text_field($_GET['q']);
        }
        if (strlen($search) < 1) {
            wp_send_json([]);
        }

        $users = get_users(array(
            'search' => '*' . $search . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name', 'ID'),
            'number' => 10
        ));

        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'id' => $user->ID,
                'text' => sprintf(
                    '%s (ID: %d, Email: %s)',
                    $user->display_name,
                    $user->ID,
                    $user->user_email
                )
            );
        }

        wp_send_json($results);
    }

    public function ajax_load_more_prescriptions()
    {
        check_ajax_referer('ne_mlp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = 12;
        $offset = ($page - 1) * $per_page;

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';

        $where = 'WHERE 1=1';
        $params = [];

        // Apply user filter
        if (isset($_GET['user_id'])) {
            $where .= " AND t1.user_id = %d";
            $params[] = intval($_GET['user_id']);
        }

        // Apply search filters
        if (!empty($_GET['search'])) {
            $search = sanitize_text_field($_GET['search']);

            // Try to parse prescription ID format first
            if (preg_match('/^(Med|Lab)-(\d{2})-(\d{2})-(\d{2})-(\d{4})$/', $search, $matches)) {
                $date = $matches[2] . '-' . $matches[3] . '-' . $matches[4];
                $seq = intval($matches[5]);
                $where .= " AND DATE_FORMAT(t1.created_at, '%d-%m-%y') = %s AND t1.id = %d";
                $params[] = $date;
                $params[] = $seq;
            } else {
                $search_param = '%' . $wpdb->esc_like($search) . '%';
                $user_ids = [];

                // Search users by login, email, display name
                $user_query_args = [
                    'search' => '*' . esc_attr($search) . '*',
                    'search_columns' => ['user_login', 'user_email', 'display_name'],
                    'fields' => 'ID',
                ];
                $user_ids = get_users($user_query_args);

                // Meta search for first name and last name
                $meta_user_ids = get_users([
                    'meta_query' => [
                        'relation' => 'OR',
                        [
                            'key' => 'first_name',
                            'value' => $search,
                            'compare' => 'LIKE'
                        ],
                        [
                            'key' => 'last_name',
                            'value' => $search,
                            'compare' => 'LIKE'
                        ]
                    ],
                    'fields' => 'ID'
                ]);

                $all_user_ids = array_unique(array_merge($user_ids, $meta_user_ids));

                $search_conditions = [
                    "t1.id LIKE %s",
                    "t1.order_id LIKE %s",
                    "t1.user_id LIKE %s"
                ];
                $params = array_merge($params, [$search_param, $search_param, $search_param]);

                if (!empty($all_user_ids)) {
                    $user_id_placeholders = implode(',', array_fill(0, count($all_user_ids), '%d'));
                    $search_conditions[] = "t1.user_id IN ($user_id_placeholders)";
                    $params = array_merge($params, $all_user_ids);
                }

                $where .= " AND (" . implode(' OR ', $search_conditions) . ")";
            }
        }

        // Apply status filter
        if (!empty($_GET['status'])) {
            $where .= " AND t1.status = %s";
            $params[] = sanitize_text_field($_GET['status']);
        }

        // Apply date range
        if (!empty($_GET['date_from'])) {
            $where .= " AND t1.created_at >= %s";
            $params[] = sanitize_text_field($_GET['date_from']) . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where .= " AND t1.created_at <= %s";
            $params[] = sanitize_text_field($_GET['date_to']) . ' 23:59:59';
        }

        // Fetch prescriptions with user info
        $query = "SELECT t1.*, u.display_name, u.user_email 
                 FROM $table t1 
                 LEFT JOIN {$wpdb->users} u ON t1.user_id = u.ID 
                 $where 
                 ORDER BY t1.created_at DESC
                 LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $query = $wpdb->prepare($query, $params);
        $prescriptions = $wpdb->get_results($query);

        if (empty($prescriptions)) {
            wp_send_json_error(array('message' => 'No more prescriptions'));
            return;
        }

        ob_start();
        foreach ($prescriptions as $presc) {
            $files = json_decode($presc->file_paths, true);
            $file_previews = '';

            if (is_array($files) && !empty($files)) {
                foreach ($files as $file) {
                    $is_pdf = (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf');
                    if ($is_pdf) {
                        $file_previews .= '<a href="' . esc_url($file) . '" target="_blank" class="ne-mlp-presc-thumb" title="' . esc_attr(basename($file)) . '" style="flex:0 0 64px;">';
                        $file_previews .= '<span style="display:inline-block;width:64px;height:64px;background:#f5f5f5;border-radius:6px;box-shadow:0 1px 4px #ccc;line-height:64px;text-align:center;font-size:32px;color:#cf1322;">📄</span>';
                        $file_previews .= '</a>';
                    } else {
                        $file_previews .= '<a href="' . esc_url($file) . '" target="_blank" class="ne-mlp-presc-thumb" title="' . esc_attr(basename($file)) . '" style="flex:0 0 64px;">';
                        $file_previews .= '<img src="' . esc_url($file) . '" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:6px;box-shadow:0 1px 4px #ccc;" />';
                        $file_previews .= '</a>';
                    }
                }
            }

            $date = date('d-m-y', strtotime($presc->created_at));
            $prefix = $presc->type === 'lab_test' ? 'Lab' : 'Med';
            $presc_id = $prefix . '-' . $date . '-' . $presc->id;

            echo '<div class="ne-mlp-presc-card" style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.07);padding:20px;">';

            // Header
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">';
            echo '<span style="font-size:15px;font-weight:600;">' . ($presc->type === 'lab_test' ? '🔬 Lab Test' : '💊 Medicine') . '</span>';
            echo '<span style="background:' . ($presc->status === 'approved' ? '#e6ffed;color:#389e0d' : ($presc->status === 'rejected' ? '#ffeaea;color:#cf1322' : '#fffbe6;color:#d48806')) . ';border-radius:999px;padding:3px 14px;font-weight:600;">' . strtoupper($presc->status) . '</span>';
            echo '</div>';

            // Prescription Info
            echo '<div style="margin-bottom:16px;">';
            echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Prescription ID: <b>' . esc_html($presc_id) . '</b></div>';
            echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Customer Name:</div>';
            echo '<div style="font-size:16px;font-weight:700;margin-bottom:4px;"><a href="' . esc_url(admin_url('user-edit.php?user_id=' . $presc->user_id)) . '" target="_blank" style="color:#222;text-decoration:underline;">' . esc_html($presc->display_name) . '</a></div>';
            echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Email: <b>' . esc_html($presc->user_email) . '</b></div>';
            echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Uploaded: <b>' . esc_html(ne_mlp_format_uploaded_date($presc->created_at)) . '</b></div>';
            if ($presc->source) {
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Source: <b>' . esc_html($presc->source) . '</b></div>';
            }
            if ($presc->order_id) {
                echo '<div style="font-size:13px;color:#666;margin-bottom:4px;">Order: <a href="' . esc_url(admin_url('post.php?post=' . $presc->order_id . '&action=edit')) . '">#' . intval($presc->order_id) . '</a></div>';
            }
            echo '</div>';

            // File Previews - Update to single line with fixed size
            if ($file_previews) {
                echo '<div style="margin-bottom:16px;">';
                echo '<div style="font-size:13px;font-weight:600;margin-bottom:8px;">Files:</div>';
                echo '<div style="display:flex;gap:8px;align-items:center;overflow-x:auto;padding-bottom:8px;">';
                echo $file_previews;
                echo '</div>';
                echo '</div>';
            }

            // Action Buttons - Reordered with View User first
            echo '<div style="display:flex;flex-direction:column;gap:8px;">';

            // View User Prescriptions Button (moved to top)
            echo '<a href="' . esc_url(add_query_arg(['page' => 'ne-mlp-prescriptions', 'user_id' => $presc->user_id], admin_url('admin.php'))) . '" class="button" style="width:100%;">';
            echo '<span class="dashicons dashicons-admin-users" style="margin-right:8px;"></span>';
            echo esc_html__('View User All Prescriptions', 'ne-med-lab-prescriptions');
            echo '</a>';

            // Only show View All Orders if viewing a user page
            if (isset($_GET['user_id'])) {
                echo '<a href="' . esc_url(add_query_arg([
                    'page' => 'wc-orders',
                    's' => '',
                    'search-filter' => 'all',
                    'action' => '-1',
                    'm' => '0',
                    '_customer_user' => $presc->user_id,
                    'filter_action' => 'Filter',
                    'paged' => '1',
                    'action2' => '-1'
                ], admin_url('admin.php'))) . '" class="button" style="width:100%;">';
                echo '<span class="dashicons dashicons-cart" style="margin-right:8px;"></span>';
                echo esc_html__('View All Orders', 'ne-med-lab-prescriptions');
                echo '</a>';
            }

            // Download Button
            if (is_array($files) && !empty($files)) {
                echo '<button type="button" class="ne-mlp-download-all" data-presc="' . intval($presc->id) . '" style="width:100%;">';
                echo '<span class="dashicons dashicons-download" style="margin-right:8px;"></span>';
                echo esc_html__('Download', 'ne-med-lab-prescriptions');
                echo '</button>';
            }

            if ($presc->status === 'pending') {
                echo '<button class="button button-primary ne-mlp-approve" data-presc="' . intval($presc->id) . '" style="width:100%;">';
                echo '<span class="dashicons dashicons-yes" style="margin-right:8px;"></span>';
                echo esc_html__('Approve', 'ne-med-lab-prescriptions');
                echo '</button>';

                echo '<button class="button ne-mlp-reject" data-presc="' . intval($presc->id) . '" style="width:100%;background:#cf1322;color:#fff;border-color:#cf1322;">';
                echo '<span class="dashicons dashicons-no" style="margin-right:8px;"></span>';
                echo esc_html__('Reject', 'ne-med-lab-prescriptions');
                echo '</button>';

                echo '<button class="button ne-mlp-delete" data-presc="' . intval($presc->id) . '" style="width:100%;background:#cf1322;color:#fff;border-color:#cf1322;">';
                echo '<span class="dashicons dashicons-trash" style="margin-right:8px;"></span>';
                echo esc_html__('Delete', 'ne-med-lab-prescriptions');
                echo '</button>';
            }

            if ($presc->status === 'rejected') {
                echo '<button class="button ne-mlp-delete" data-presc="' . intval($presc->id) . '" style="width:100%;background:#cf1322;color:#fff;border-color:#cf1322;">';
                echo '<span class="dashicons dashicons-trash" style="margin-right:8px;"></span>';
                echo esc_html__('Delete', 'ne-med-lab-prescriptions');
                echo '</button>';
            }

            if ($presc->status === 'approved') {
                if (empty($presc->order_id)) {
                    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=shop_order&customer_id=' . intval($presc->user_id))) . '" class="button button-primary" style="width:100%;">';
                    echo '<span class="dashicons dashicons-cart" style="margin-right:8px;"></span>';
                    echo esc_html__('Create Order', 'ne-med-lab-prescriptions');
                    echo '</a>';
                } else {
                    echo '<a href="' . esc_url(admin_url('post.php?post=' . intval($presc->order_id) . '&action=edit')) . '" class="button button-primary" style="width:100%;">';
                    echo '<span class="dashicons dashicons-cart" style="margin-right:8px;"></span>';
                    echo esc_html__('View Order', 'ne-med-lab-prescriptions');
                    echo '</a>';
                }
            }

            // Initialize last_usage based on whether prescription has an order
            $last_usage = !empty($presc->order_id);
            
            // Add last usage information
            if ($last_usage) {
                echo '<div style="font-size:12px;color:#666;margin-top:8px;padding-top:8px;border-top:1px solid #eee;">';
                echo '<span class="dashicons dashicons-clock" style="margin-right:4px;"></span>';
                if ($presc->order_id) {
                    $order = wc_get_order($presc->order_id);
                    $item_count = $order ? $order->get_item_count() : 0;
                    echo 'Last used in order <a href="' . esc_url(admin_url('post.php?post=' . $presc->order_id . '&action=edit')) . '">#' . intval($presc->order_id) . '</a> - Order Item - ' . $item_count;
                }
                echo '</div>';
            }

            echo '</div>';
            echo '</div>';
        }

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }

    /**
     * Render the Request Order page
     */
    public function render_request_order_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ne-med-lab-prescriptions'));
        }

        // Enqueue necessary scripts and styles
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        
        // Get order request instance
        $order_request = NE_MLP_Order_Request::getInstance();
        
        // Handle pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 10;
        
        // Get filtered requests
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        $requests_data = $order_request->get_requests_with_pagination($page, $per_page, $status, $date_from, $date_to, $search);
        
        // Start output
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Order Requests', 'ne-med-lab-prescriptions') . '</h1>';
        
        // Filters
        echo '<div class="ne-mlp-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">';
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="ne-mlp-request-order" />';
        
        // Status filter
        echo '<select name="status" style="margin-right: 10px;">';
        echo '<option value="">' . esc_html__('All Statuses', 'ne-med-lab-prescriptions') . '</option>';
        $statuses = array(
            'pending' => __('Pending', 'ne-med-lab-prescriptions'),
            'approved' => __('Approved', 'ne-med-lab-prescriptions'),
            'rejected' => __('Rejected', 'ne-med-lab-prescriptions')
        );
        foreach ($statuses as $value => $label) {
            $selected = $status === $value ? ' selected' : '';
            echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        
        // Date range
        echo '<span style="margin: 0 10px;">' . esc_html__('From:', 'ne-med-lab-prescriptions') . ' </span>';
        echo '<input type="date" name="date_from" value="' . esc_attr($date_from) . '" style="margin-right: 20px;" />';
        
        echo '<span style="margin: 0 10px;">' . esc_html__('To:', 'ne-med-lab-prescriptions') . ' </span>';
        echo '<input type="date" name="date_to" value="' . esc_attr($date_to) . '" style="margin-right: 20px;" />';
        
        // Search
        echo '<input type="text" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search requests...', 'ne-med-lab-prescriptions') . '" style="margin-right: 10px;" />';
        
        // Buttons
        echo '<button type="submit" class="button button-primary">' . esc_html__('Filter', 'ne-med-lab-prescriptions') . '</button>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=ne-mlp-request-order')) . '" class="button" style="margin-left: 10px;">' . esc_html__('Reset', 'ne-med-lab-prescriptions') . '</a>';
        
        echo '</form>';
        echo '</div>';
        
        // Requests grid
        if (!empty($requests_data['requests'])) {
            echo '<div class="ne-mlp-requests-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; margin-top: 20px;">';
            foreach ($requests_data['requests'] as $request) {
                $order_request->render_request_card_grid($request);
            }
            echo '</div>';
            
            // Output modern pagination
            if ($requests_data['total_pages'] > 1) {
                echo '<div class="ne-mlp-pagination" style="margin: 20px 0; text-align: center;">';
                echo '<div class="pagination-links" style="display: inline-flex; gap: 5px; align-items: center;">';
                
                // Previous button
                if ($requests_data['current_page'] > 1) {
                    echo sprintf(
                        '<a href="%s" class="button" style="padding: 5px 12px; border-radius: 4px; text-decoration: none;">&laquo; %s</a> ',
                        esc_url(add_query_arg('paged', $requests_data['current_page'] - 1)),
                        esc_html__('Previous', 'ne-med-lab-prescriptions')
                    );
                } else {
                    echo '<span class="button disabled" style="padding: 5px 12px; color: #a0a5aa; cursor: not-allowed;">&laquo; ' . esc_html__('Previous', 'ne-med-lab-prescriptions') . '</span> ';
                }
                
                // Page numbers
                $start = max(1, $requests_data['current_page'] - 2);
                $end = min($requests_data['total_pages'], $start + 4);
                
                if ($start > 1) {
                    echo '<a href="' . esc_url(add_query_arg('paged', 1)) . '" class="button" style="padding: 5px 12px; border-radius: 4px; text-decoration: none;">1</a>';
                    if ($start > 2) echo '<span style="padding: 5px;">...</span>';
                }
                
                for ($i = $start; $i <= $end; $i++) {
                    if ($i == $requests_data['current_page']) {
                        echo '<span class="button button-primary" style="padding: 5px 12px; border-radius: 4px;">' . $i . '</span> ';
                    } else {
                        echo '<a href="' . esc_url(add_query_arg('paged', $i)) . '" class="button" style="padding: 5px 12px; border-radius: 4px; text-decoration: none;">' . $i . '</a> ';
                    }
                }
                
                if ($end < $requests_data['total_pages']) {
                    if ($end < $requests_data['total_pages'] - 1) echo '<span style="padding: 5px;">...</span>';
                    echo '<a href="' . esc_url(add_query_arg('paged', $requests_data['total_pages'])) . '" class="button" style="padding: 5px 12px; border-radius: 4px; text-decoration: none;">' . $requests_data['total_pages'] . '</a>';
                }
                
                // Next button
                if ($requests_data['current_page'] < $requests_data['total_pages']) {
                    echo sprintf(
                        '<a href="%s" class="button" style="padding: 5px 12px; border-radius: 4px; text-decoration: none;">%s &raquo;</a>',
                        esc_url(add_query_arg('paged', $requests_data['current_page'] + 1)),
                        esc_html__('Next', 'ne-med-lab-prescriptions')
                    );
                } else {
                    echo '<span class="button disabled" style="padding: 5px 12px; color: #a0a5aa; cursor: not-allowed;">' . esc_html__('Next', 'ne-med-lab-prescriptions') . ' &raquo;</span>';
                }
                
                echo '</div>'; // End .pagination-links
                
                // Page info
                echo '<div class="pagination-info" style="margin-top: 10px; color: #646970; font-size: 13px;">';
                echo sprintf(
                    esc_html__('Page %1$d of %2$d (%3$d items)', 'ne-med-lab-prescriptions'),
                    $requests_data['current_page'],
                    $requests_data['total_pages'],
                    $requests_data['total']
                );
                echo '</div>';
                
                echo '</div>'; // End .ne-mlp-pagination
            }    
        } else {
            echo '<div class="notice notice-info"><p>' . esc_html__('No order requests found.', 'ne-med-lab-prescriptions') . '</p></div>';
        }
        
        echo '</div>'; // .wrap
    }
    
    // New method for manual upload page
    public function render_manual_upload_page()
    {
        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ne_mlp_manual_upload_nonce'])) {
            check_admin_referer('ne_mlp_manual_upload', 'ne_mlp_manual_upload_nonce');

            $user_id = isset($_POST['selected_user_id']) ? intval($_POST['selected_user_id']) : 0;
            $type = isset($_POST['prescription_type']) ? sanitize_text_field($_POST['prescription_type']) : '';
            $status = isset($_POST['prescription_status']) ? sanitize_text_field($_POST['prescription_status']) : 'pending';

            $errors = [];
            $success_message = '';

            // Validate required fields
            if (!$user_id) {
                $errors[] = __('Please select a user.', 'ne-med-lab-prescriptions');
            }
            if (!$type) {
                $errors[] = __('Please select prescription type.', 'ne-med-lab-prescriptions');
            }
            if (!in_array($status, ['pending', 'approved'])) {
                $errors[] = __('Invalid status selected.', 'ne-med-lab-prescriptions');
            }

            // Validate files
            if (empty($_FILES['prescription_files']) || empty($_FILES['prescription_files']['name'][0])) {
                $errors[] = __('Please select at least one file to upload.', 'ne-med-lab-prescriptions');
            }

            // Process upload if no validation errors
            if (empty($errors)) {
                $upload_result = $this->process_manual_upload($user_id, $type, $status, $_FILES['prescription_files']);

                if ($upload_result['success']) {
                    $success_message = $upload_result['message'];

                    // Trigger notifications
                    $user = get_userdata($user_id);
                    if ($user && $upload_result['prescription']) {
                        $this->trigger_upload_notification($user, $upload_result['prescription']);
                    }
                } else {
                    $errors = array_merge($errors, $upload_result['errors']);
                }
            }

            // Display messages
            if (!empty($errors)) {
                echo '<div class="notice notice-error"><p>' . implode('<br>', array_map('esc_html', $errors)) . '</p></div>';
            }
            if ($success_message) {
                echo '<div class="notice notice-success"><p>' . esc_html($success_message) . '</p></div>';
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Manually Upload Prescription', 'ne-med-lab-prescriptions') . '</h1>';

        // Upload Form
        echo '<div class="ne-mlp-manual-upload-container" style="background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 800px;">';
        echo '<form method="post" enctype="multipart/form-data" id="ne-mlp-manual-upload-form">';
        wp_nonce_field('ne_mlp_manual_upload', 'ne_mlp_manual_upload_nonce');

        // Step 1: User Selection
        echo '<div class="ne-mlp-upload-step" id="ne-mlp-step-user">';
        echo '<h3 style="margin-top: 0; color: #1d2327; border-bottom: 2px solid #2271b1; padding-bottom: 10px;">' . esc_html__('Step 1: Select User', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<div style="margin-bottom: 20px;">';
        echo '<label for="selected_user_id" style="display: block; margin-bottom: 8px; font-weight: 600;">' . esc_html__('Select User:', 'ne-med-lab-prescriptions') . '</label>';

        // Get all users for dropdown (limit to reasonable number)
        $users = get_users(array(
            'number' => 500, // Limit to 500 users for performance
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => array('ID', 'display_name', 'user_email', 'user_login')
        ));

        echo '<select name="selected_user_id" id="selected_user_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">';
        echo '<option value="">' . esc_html__('-- Select a User --', 'ne-med-lab-prescriptions') . '</option>';

        foreach ($users as $user) {
            $user_label = sprintf(
                '%s (%s) - ID: %d',
                $user->display_name,
                $user->user_email,
                $user->ID
            );
            echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user_label) . '</option>';
        }

        echo '</select>';

        // Add search functionality to the select
        echo '<script>
        jQuery(document).ready(function($) {
            // Add search functionality to user dropdown
            $("#selected_user_id").select2({
                placeholder: "' . esc_js(__('Search and select a user...', 'ne-med-lab-prescriptions')) . '",
                allowClear: true,
                width: "100%"
            });
            
            // Function to check if form is ready for submission
            function checkFormReady() {
                var userId = $("#selected_user_id").val();
                var files = $("#prescription-files")[0].files;
                var prescType = $("#prescription-type").val();
                var prescStatus = $("#prescription-status").val();
                
                var isReady = userId && files && files.length > 0 && prescType && prescStatus;
                
                $("#ne-mlp-submit-btn").prop("disabled", !isReady);
                
                if (isReady) {
                    $("#submit-requirements").text("' . esc_js(__('Ready to upload!', 'ne-med-lab-prescriptions')) . '").css("color", "#389e0d");
                } else {
                    var missing = [];
                    if (!userId) missing.push("user");
                    if (!files || files.length === 0) missing.push("files");
                    if (!prescType) missing.push("prescription type");
                    if (!prescStatus) missing.push("status");
                    
                    $("#submit-requirements").text("' . esc_js(__('Missing:', 'ne-med-lab-prescriptions')) . ' " + missing.join(", ")).css("color", "#666");
                }
            }
            
            // Check form readiness when any field changes
            $("#selected_user_id, #prescription-type, #prescription-status, #prescription-files").on("change", checkFormReady);
            
            // Initial check
            checkFormReady();
        });
        </script>';
        echo '</div>';
        echo '</div>';

        // Step 2: Prescription Details (always visible)
        echo '<div class="ne-mlp-upload-step" id="ne-mlp-step-details">';
        echo '<h3 style="color: #1d2327; border-bottom: 2px solid #2271b1; padding-bottom: 10px;">' . esc_html__('Step 2: Prescription Details', 'ne-med-lab-prescriptions') . '</h3>';

        echo '<div style="display: flex; gap: 20px; margin-bottom: 20px;">';
        // Type selection
        echo '<div style="flex: 1;">';
        echo '<label for="prescription-type" style="display: block; margin-bottom: 8px; font-weight: 600;">' . esc_html__('Prescription Type:', 'ne-med-lab-prescriptions') . '</label>';
        echo '<select name="prescription_type" id="prescription-type" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<option value="medicine">' . esc_html__('Medicine', 'ne-med-lab-prescriptions') . '</option>';
        echo '<option value="lab_test">' . esc_html__('Lab Test', 'ne-med-lab-prescriptions') . '</option>';
        echo '</select>';
        echo '</div>';

        // Status selection
        echo '<div style="flex: 1;">';
        echo '<label for="prescription-status" style="display: block; margin-bottom: 8px; font-weight: 600;">' . esc_html__('Initial Status:', 'ne-med-lab-prescriptions') . '</label>';
        echo '<select name="prescription_status" id="prescription-status" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<option value="pending">' . esc_html__('Pending Review', 'ne-med-lab-prescriptions') . '</option>';
        echo '<option value="approved">' . esc_html__('Pre-approved', 'ne-med-lab-prescriptions') . '</option>';
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Step 3: File Upload (always visible)
        echo '<div class="ne-mlp-upload-step" id="ne-mlp-step-files">';
        echo '<h3 style="color: #1d2327; border-bottom: 2px solid #2271b1; padding-bottom: 10px;">' . esc_html__('Step 3: Upload Files', 'ne-med-lab-prescriptions') . '</h3>';
        echo '<div style="margin-bottom: 20px;">';
        echo '<label for="prescription-files" style="display: block; margin-bottom: 8px; font-weight: 600;">' . esc_html__('Select Files:', 'ne-med-lab-prescriptions') . '</label>';
        echo '<input type="file" name="prescription_files[]" id="prescription-files" multiple accept=".jpg,.jpeg,.png,.pdf" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<p style="margin-top: 8px; color: #666; font-size: 13px;">' . esc_html__('Allowed: JPG, PNG, PDF files. Max 4 files, 5MB each.', 'ne-med-lab-prescriptions') . '</p>';
        echo '<div id="file-preview-container" style="margin-top: 15px; display: flex; flex-wrap: wrap; gap: 10px;"></div>';
        echo '</div>';
        echo '</div>';

        // Submit Section (always visible, but button disabled until ready)
        echo '<div class="ne-mlp-upload-step" id="ne-mlp-step-submit" style="border-top: 1px solid #ddd; padding-top: 20px; margin-top: 20px;">';
        echo '<button type="submit" id="ne-mlp-submit-btn" class="button button-primary button-large" style="padding: 12px 24px;" disabled>';
        echo '<span class="dashicons dashicons-upload" style="margin-right: 8px;"></span>';
        echo esc_html__('Upload Prescription', 'ne-med-lab-prescriptions');
        echo '</button>';
        echo '<p id="submit-requirements" style="margin-top: 10px; color: #666; font-size: 13px;">';
        echo esc_html__('Please select a user and upload files to enable submission.', 'ne-med-lab-prescriptions');
        echo '</p>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // container
        echo '</div>'; // wrap
    }

    /**
     * Process manual file upload
     */
    private function process_manual_upload($user_id, $type, $status, $files)
    {
        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $max_files = 4;
        $uploaded_files = [];
        $errors = [];

        // Validate file count
        $file_count = count($files['name']);
        if ($file_count > $max_files) {
            return [
                'success' => false,
                'errors' => [sprintf(__('Maximum %d files allowed.', 'ne-med-lab-prescriptions'), $max_files)]
            ];
        }

        // Setup upload directory
        $upload_dir = wp_upload_dir();
        $presc_dir = $upload_dir['basedir'] . '/ne-mlp-prescriptions';
        $presc_url = $upload_dir['baseurl'] . '/ne-mlp-prescriptions';

        if (!file_exists($presc_dir)) {
            if (!wp_mkdir_p($presc_dir)) {
                return [
                    'success' => false,
                    'errors' => [__('Failed to create upload directory.', 'ne-med-lab-prescriptions')]
                ];
            }
        }

        // Process each file
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = sprintf(
                    __('Upload error for file %s: %s', 'ne-med-lab-prescriptions'),
                    $files['name'][$i],
                    $this->get_upload_error_message($files['error'][$i])
                );
                continue;
            }

            // Validate file type
            $file_type = wp_check_filetype($files['name'][$i]);
            if (!in_array($file_type['type'], $allowed_types)) {
                $errors[] = sprintf(__('Invalid file type for %s. Only JPG, PNG, and PDF allowed.', 'ne-med-lab-prescriptions'), $files['name'][$i]);
                continue;
            }

            // Validate file size
            if ($files['size'][$i] > $max_size) {
                $errors[] = sprintf(__('File %s exceeds 5MB limit.', 'ne-med-lab-prescriptions'), $files['name'][$i]);
                continue;
            }

            // Generate unique filename
            $file_name = 'presc-' . time() . '-' . uniqid() . '-' . $i . '.' . $file_type['ext'];
            $file_path = $presc_dir . '/' . $file_name;
            $file_url = $presc_url . '/' . $file_name;

            // Move uploaded file
            if (move_uploaded_file($files['tmp_name'][$i], $file_path)) {
                $uploaded_files[] = $file_url;
            } else {
                $errors[] = sprintf(__('Failed to save file: %s', 'ne-med-lab-prescriptions'), $files['name'][$i]);
            }
        }

        // Save to database if files were uploaded
        if (!empty($uploaded_files) && empty($errors)) {
            global $wpdb;
            $table = $wpdb->prefix . 'prescriptions';

            $result = $wpdb->insert(
                $table,
                [
                    'user_id' => $user_id,
                    'order_id' => null,
                    'file_paths' => json_encode($uploaded_files),
                    'type' => $type,
                    'status' => $status,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s']
            );

            if ($result) {
                $prescription_id = $wpdb->insert_id;
                $prescription = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $prescription_id));

                return [
                    'success' => true,
                    'message' => __('Prescription uploaded successfully!', 'ne-med-lab-prescriptions'),
                    'prescription' => $prescription
                ];
            } else {
                return [
                    'success' => false,
                    'errors' => [__('Database error: Failed to save prescription.', 'ne-med-lab-prescriptions')]
                ];
            }
        }

        return [
            'success' => false,
            'errors' => $errors ?: [__('No files were uploaded successfully.', 'ne-med-lab-prescriptions')]
        ];
    }

    // Helper function to get upload error messages
    private function get_upload_error_message($error_code)
    {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    // Add AJAX handler for getting prescription files
    public function ajax_get_prescription_files()
    {
        check_ajax_referer('ne_mlp_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        $presc_id = intval($_POST['prescription_id']);
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $presc_id));
        if (!$presc) {
            wp_send_json_error(array('message' => 'Prescription not found'));
        }
        $files = json_decode($presc->file_paths, true);
        if (!is_array($files) || empty($files)) {
            wp_send_json_error(array('message' => 'No files found'));
        }
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $presc_id_formatted = $prescription_manager->format_prescription_id($presc);
        wp_send_json_success(array(
            'files' => $files,
            'meta' => array(
                'prescription_id' => $presc_id_formatted,
                'order_id' => $presc->order_id ? $presc->order_id : '',
                'user_id' => $presc->user_id
            )
        ));
    }

    // AJAX: Get notification count for badge refresh
    public function ajax_get_notification_count()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        check_ajax_referer('ne_mlp_admin_action', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");

        wp_send_json_success(['count' => intval($count)]);
    }

    // AJAX: Admin download prescription with custom filename
    public function ajax_admin_download_prescription()
    {
        check_ajax_referer('ne_mlp_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }

        $presc_id = intval($_GET['presc_id']);
        if (!$presc_id) {
            wp_die('Invalid prescription ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $presc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $presc_id));

        if (!$presc) {
            wp_die('Prescription not found');
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
            $this->admin_download_multiple_files_as_zip($files, $username, $formatted_id);
        } else {
            $this->admin_download_single_file($files[0], $username, $formatted_id);
        }
    }

    private function admin_download_single_file($file_url, $username, $formatted_id)
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

    private function admin_download_multiple_files_as_zip($files, $username, $formatted_id)
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

    // AJAX: Get user orders for assignment
    public function ajax_get_user_orders()
    {
        check_ajax_referer('ne_mlp_admin_action', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        // Get orders for this user that have prescription-required products
        // Exclude completed and cancelled orders
        $args = [
            'customer_id' => $user_id,
            'limit' => -1,
            'return' => 'objects',
            'status' => ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-refunded'] // Exclude completed and cancelled
        ];

        $orders = wc_get_orders($args);
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $options = [];

        foreach ($orders as $order) {
            // Check if order has prescription-required products
            $has_prescription_products = false;
            foreach ($order->get_items() as $item) {
                if ($prescription_manager->product_requires_prescription($item->get_product_id())) {
                    $has_prescription_products = true;
                    break;
                }
            }

            if ($has_prescription_products) {
                $status = $order->get_status();
                $total = $order->get_total();
                $date = $order->get_date_created()->format('Y-m-d');

                $options[] = [
                    'value' => $order->get_id(),
                    'label' => sprintf(
                        '#%d - %s - %s (%s)',
                        $order->get_id(),
                        wc_price($total),
                        $date,
                        ucfirst($status)
                    )
                ];
            }
        }

        wp_send_json_success($options);
    }

    // AJAX: Get user prescriptions for assignment
    public function ajax_get_user_prescriptions()
    {
        check_ajax_referer('ne_mlp_admin_action', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();

        // Get pending and approved medicine prescriptions (default behavior - excludes rejected)
        $prescription_options = $prescription_manager->get_user_medicine_prescriptions($user_id, null, true);

        wp_send_json_success($prescription_options);
    }

    // AJAX: Assign prescription to order from admin assignment tool
    public function ajax_assign_prescription()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        check_ajax_referer('ne_mlp_admin_nonce', 'nonce');

        $order_id = intval($_POST['order_id']);
        $prescription_id = intval($_POST['prescription_id']);

        if (!$order_id || !$prescription_id) {
            wp_send_json_error('Invalid order or prescription ID');
        }

        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();

        // Validate prescription for order
        $validation_result = $prescription_manager->validate_prescription_for_order($prescription_id, $order_id);
        if (is_wp_error($validation_result)) {
            wp_send_json_error($validation_result->get_error_message());
        }

        // Attach prescription to order (this updates both order_id and current_order_id)
        $attachment_result = $prescription_manager->attach_prescription_to_order($order_id, $prescription_id);
        if (is_wp_error($attachment_result)) {
            wp_send_json_error($attachment_result->get_error_message());
        }

        wp_send_json_success('Prescription assigned successfully');
    }

    // Helper method to get directory size
    private function get_directory_size($directory)
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    // Helper method to count files in directory
    private function count_files_in_directory($directory)
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    // Helper method to format bytes
    private function format_bytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Generate and store a secure global API key.
     *
     * Returns the newly generated key. Persists to the `_ne_global_api_key` option.
     */
    private function generate_global_api_key()
    {
        $key = '';
        try {
            // 32 random bytes -> 64 char hex string
            $bytes = random_bytes(32);
            $key = bin2hex($bytes);
        } catch (Exception $e) {
            if (function_exists('openssl_random_pseudo_bytes')) {
                $bytes = openssl_random_pseudo_bytes(32);
                if ($bytes !== false) {
                    $key = bin2hex($bytes);
                }
            }
            if (empty($key)) {
                // Fallback: strong WP password (64 chars, includes special chars)
                $key = wp_generate_password(64, true, true);
            }
        }

        $option_name = '_ne_global_api_key';
        // Add with autoload = no if not present; otherwise update existing value
        if (get_option($option_name, false) === false) {
            add_option($option_name, $key, '', 'no');
        } else {
            update_option($option_name, $key, false);
        }

        return $key;
    }

    public function ajax_user_autocomplete()
    {
        // Debug logging for troubleshooting
        error_log('NE MLP: User autocomplete AJAX called with data: ' . print_r($_REQUEST, true));

        // Check nonce first if provided
        if (isset($_REQUEST['nonce'])) {
            try {
                check_ajax_referer('ne_mlp_admin_nonce', 'nonce');
            } catch (Exception $e) {
                error_log('NE MLP: Nonce verification failed: ' . $e->getMessage());
                wp_send_json_error(array('message' => 'Nonce verification failed'));
            }
        }

        // Check permissions - use manage_options for consistency with other functions
        if (!current_user_can('manage_options')) {
            error_log('NE MLP: User autocomplete permission denied for user: ' . get_current_user_id());
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $term = '';
        if (isset($_REQUEST['term'])) {
            $term = sanitize_text_field($_REQUEST['term']);
        } elseif (isset($_REQUEST['q'])) {
            $term = sanitize_text_field($_REQUEST['q']);
        }

        error_log('NE MLP: Searching for users with term: ' . $term);

        if (strlen($term) < 1) {
            wp_send_json([]);
        }

        global $wpdb;
        $users_found = [];

        // Prioritize exact ID match if search term is numeric
        if (is_numeric($term)) {
            $user = get_user_by('ID', intval($term));
            if ($user) {
                $users_found[$user->ID] = $user;
            }
        }

        // Comprehensive search using direct DB query for reliability
        $search_term_like = '%' . $wpdb->esc_like($term) . '%';

        $query = $wpdb->prepare("
            SELECT DISTINCT u.ID
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um_first ON u.ID = um_first.user_id AND um_first.meta_key = 'first_name'
            LEFT JOIN {$wpdb->usermeta} um_last ON u.ID = um_last.user_id AND um_last.meta_key = 'last_name'
            WHERE
                u.user_login LIKE %s OR
                u.user_email LIKE %s OR
                u.display_name LIKE %s OR
                um_first.meta_value LIKE %s OR
                um_last.meta_value LIKE %s
            LIMIT 20
        ", $search_term_like, $search_term_like, $search_term_like, $search_term_like, $search_term_like);

        $found_ids = $wpdb->get_col($query);

        if (!empty($found_ids)) {
            foreach ($found_ids as $user_id) {
                if (!isset($users_found[$user_id])) {
                    $user = get_user_by('ID', $user_id);
                    if ($user) {
                        $users_found[$user_id] = $user;
                    }
                }
            }
        }

        $results = [];
        foreach ($users_found as $user) {
            $formatted_label = sprintf('%s - %s (ID: %d)', $user->display_name, $user->user_email, $user->ID);
            $results[] = [
                'id' => $user->ID,
                'value' => $user->ID,
                'label' => $formatted_label,
                'text' => $formatted_label  // For backwards compatibility
            ];
        }

        error_log('NE MLP: Returning ' . count($results) . ' user results: ' . print_r($results, true));
        wp_send_json($results);
    }
}

// --- Prescription ID formatting helper ---
if (!function_exists('ne_mlp_format_presc_id')) {
    function ne_mlp_format_presc_id($presc)
    {
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        return $prescription_manager->format_prescription_id($presc);
    }
}
// --- Uploaded date formatting helper ---
if (!function_exists('ne_mlp_format_uploaded_date')) {
    function ne_mlp_format_uploaded_date($datetime)
    {
        return date('d M Y  h:i A', strtotime($datetime));
    }
}