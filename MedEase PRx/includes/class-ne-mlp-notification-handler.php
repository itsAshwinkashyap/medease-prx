<?php
/**
 * Handles admin notifications for new order requests
 * 
 * @package NE_MLP
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Ensure WordPress is loaded
if (!function_exists('add_action')) {
    return;
}

// Include WordPress database functions
if (!function_exists('wpdb')) {
    global $wpdb;
}

class NE_MLP_Notification_Handler {
    
    /**
     * Initialize the notification handler
     */
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_ne_mlp_check_new_requests', [$this, 'ajax_check_new_requests']);
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        // Enqueue the notification script on all admin pages
        wp_enqueue_script(
            'ne-mlp-notify',
            NE_MLP_PLUGIN_URL . 'assets/js/notify.js',
            ['jquery'],
            NE_MLP_VERSION,
            true
        );
        
        // Determine if we are on the order requests admin page
        $is_order_page = 0;
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && (false !== strpos($screen->id, 'ne-mlp-request-order'))) {
                $is_order_page = 1;
            }
        }
        if (!$is_order_page) {
            if (false !== strpos($hook, 'ne-mlp-request-order')) {
                $is_order_page = 1;
            }
        }

        // Build URLs with proper scheme to avoid mixed-content blocking
        $ajax_url   = admin_url('admin-ajax.php');
        $plugin_url = set_url_scheme(NE_MLP_PLUGIN_URL, is_ssl() ? 'https' : 'http');
        $audio_url  = set_url_scheme(NE_MLP_PLUGIN_URL . 'assets/mixkit-alert-alarm-1005.wav', is_ssl() ? 'https' : 'http');

        // Localize script with required data
        wp_localize_script('ne-mlp-notify', 'neMLPNotify', [
            'ajaxurl' => $ajax_url,
            'audioUrl' => $audio_url,
            'pluginUrl' => $plugin_url,
            'nonce' => wp_create_nonce('ne_mlp_notification_nonce'),
            'isOrderPage' => $is_order_page,
            'requestsPageUrl' => admin_url('admin.php?page=ne-mlp-request-order')
        ]);
    }
    
    /**
     * AJAX handler to check for new pending requests
     */
    public function ajax_check_new_requests() {
        check_ajax_referer('ne_mlp_notification_nonce', 'nonce');
        
        // Allow either WooCommerce managers or site admins
        if (!(current_user_can('manage_woocommerce') || current_user_can('manage_options'))) {
            wp_send_json_error('Unauthorized');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ne_mlp_order_requests';
        $last_check = isset($_POST['last_check']) ? intval($_POST['last_check']) : 0;
        $is_background_check = !empty($_POST['is_background_check']);
        
        // Get count of new pending requests since last check
        $query = $wpdb->prepare(
            "SELECT 
                COUNT(*) as count,
                UNIX_TIMESTAMP(MAX(created_at)) as latest_timestamp
             FROM {$table_name}
             WHERE status = 'pending' AND created_at >= FROM_UNIXTIME(%d)",
            $last_check
        );
        $result = $wpdb->get_row($query);

        // Get total pending count for duplicate prevention on client
        $total_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'pending'");

        $response = [
            'count' => $result ? intval($result->count) : 0,
            'timestamp' => $result ? intval($result->latest_timestamp ?: time()) : time(),
            'requests' => [],
            'total_pending' => $total_pending
        ];

        // Only fetch request details if this is not a background check
        if (!$is_background_check && $response['count'] > 0) {
            $requests_query = $wpdb->prepare(
                "SELECT * FROM {$table_name} 
                 WHERE status = 'pending' AND created_at >= FROM_UNIXTIME(%d)
                 ORDER BY created_at DESC",
                $last_check
            );
            $response['requests'] = $wpdb->get_results($requests_query, 'ARRAY_A');
        }

        wp_send_json_success($response);
    }
}

// Initialize the notification handler when WordPress is fully loaded
add_action('plugins_loaded', function() {
    // Initialize regardless of WooCommerce availability so notifications work in all admin contexts
    new NE_MLP_Notification_Handler();
});
