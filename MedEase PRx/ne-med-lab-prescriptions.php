<?php
/**
 * Plugin Name: NE Med Lab Prescriptions
 * Description: Handle prescription uploads for WooCommerce orders with JWT authentication, admin management, and comprehensive API support.
 * Version: 1.0.0
 * Author: Nira Edge Team
 * Author URI: https://niraedge.com
 * Text Domain: ne-med-lab-prescriptions
 * WC requires at least: 6.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Start session if not already started
if ( ! session_id() ) {
    session_start();
}

// Define plugin constants
define( 'NE_MLP_VERSION', '1.0.0' );            
define( 'NE_MLP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NE_MLP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Include core files
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-prescription-manager.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-product-meta.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-upload-handler.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-admin-panel.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-frontend.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-rest-api.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-order-manager.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-order-request.php';
require_once NE_MLP_PLUGIN_PATH . 'includes/class-ne-mlp-notification-handler.php';

// Initialize plugin components after WooCommerce is loaded
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Initialize notification handler
    if (class_exists('NE_MLP_Notification_Handler')) {
        new NE_MLP_Notification_Handler();
    }
});

/**
 * Load message modal template
 */
function ne_mlp_load_message_modal() {
    if (!is_admin()) {
        include_once NE_MLP_PLUGIN_PATH . 'templates/message-modal.php';
    }
}
add_action('wp_footer', 'ne_mlp_load_message_modal');

// Also include modal in admin so notifications can use it
function ne_mlp_load_message_modal_admin() {
    if (is_admin()) {
        include_once NE_MLP_PLUGIN_PATH . 'templates/message-modal.php';
    }
}
add_action('admin_footer', 'ne_mlp_load_message_modal_admin');

// Check if WooCommerce is active
register_activation_hook( __FILE__, function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( __( 'This plugin requires WooCommerce to be installed and active.', 'ne-med-lab-prescriptions' ) );
    }
    
    // Add activation notice
    add_option( 'ne_mlp_activation_notice', true );
});

// Declare WooCommerce compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
});

// Activation hook: create custom DB table
register_activation_hook( __FILE__, function() {
    global $wpdb;
    $table = $wpdb->prefix . 'prescriptions';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED DEFAULT NULL,
        file_paths TEXT NOT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'medicine',
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        source VARCHAR(32) DEFAULT 'website',
        created_at DATETIME NOT NULL,
        reject_note TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY order_id (order_id),
        KEY status (status),
        KEY created_at (created_at)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
});

// Create prescriptions table function
function ne_mlp_create_prescriptions_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'prescriptions';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED DEFAULT NULL,
        file_paths TEXT NOT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'medicine',
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        source VARCHAR(32) DEFAULT 'website',
        created_at DATETIME NOT NULL,
        reject_note TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY order_id (order_id),
        KEY status (status),
        KEY created_at (created_at)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

// Hook plugin activation and deactivation
register_activation_hook( __FILE__, 'ne_mlp_activate_plugin' );
register_deactivation_hook( __FILE__, 'ne_mlp_deactivate_plugin' );

function ne_mlp_activate_plugin() {
    // Create database table
    ne_mlp_create_prescriptions_table();
    
    // Create order requests table
    if (class_exists('NE_MLP_Order_Request')) {
        $order_request = NE_MLP_Order_Request::getInstance();
        $order_request->maybe_create_table();
    }
    
    // Mark that we need to flush rewrite rules for order requests
    update_option('ne_mlp_order_requests_flush_needed', true);
    
    // Initialize frontend to register endpoints
    $frontend = new NE_MLP_Frontend();
    
    // Manually add endpoints
    add_rewrite_endpoint( 'my-prescription', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'upload-prescription', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'order-requests', EP_ROOT | EP_PAGES );
    
    // Force immediate rewrite flush 
    flush_rewrite_rules(true);
}

function ne_mlp_deactivate_plugin() {
    // Clean up rewrite rules
    flush_rewrite_rules();
}

// Add activation notice
add_action( 'admin_notices', function() {
    if ( get_option( 'ne_mlp_activation_notice' ) ) {
        ?>
        <div class="notice notice-success is-dismissible">
            <h3>🎉 NE Med Lab Prescriptions Plugin Activated Successfully!</h3>
            <p><strong>Plugin is now ready to use.</p>
            </ul>
            <p>
                <a href="<?php echo admin_url('admin.php?page=ne-mlp-prescriptions'); ?>" class="button button-primary">View Prescriptions</a>
                <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button">Configure Products</a>
                <button type="button" class="notice-dismiss" onclick="location.href='<?php echo add_query_arg('ne_mlp_dismiss_notice', '1'); ?>'">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </p>
        </div>
        <?php
    }
});

// Handle notice dismissal
add_action( 'admin_init', function() {
    if ( isset( $_GET['ne_mlp_dismiss_notice'] ) ) {
        delete_option( 'ne_mlp_activation_notice' );
        wp_redirect( remove_query_arg( 'ne_mlp_dismiss_notice' ) );
        exit;
    }
});

// Initialize main classes
add_action('init', function() {
    NE_MLP_Prescription_Manager::getInstance();
    new NE_MLP_Upload_Handler();
    new NE_MLP_Frontend();
    
    // Admin functionality
    if (is_admin()) {
        new NE_MLP_Admin_Panel();
        
        // Add admin action to manually flush rewrite rules
        add_action('wp_loaded', function() {
            if (isset($_GET['ne_mlp_flush_rules']) && current_user_can('manage_options')) {
                add_rewrite_endpoint( 'my-prescription', EP_ROOT | EP_PAGES );
                add_rewrite_endpoint( 'upload-prescription', EP_ROOT | EP_PAGES );
                flush_rewrite_rules(true);
                wp_redirect(admin_url('admin.php?page=ne-mlp-prescriptions&flushed=1'));
                exit;
            }
        });
    }
    
    // REST API
    new NE_MLP_REST_API();
});

// Initialize Order Request System and register AJAX actions
add_action('plugins_loaded', function() {
    // Initialize Order Request System
    $order_request = NE_MLP_Order_Request::getInstance();
    
    // Handle order request submission - register these early
    add_action('wp_ajax_ne_mlp_submit_order_request', [$order_request, 'handle_order_request_submission']);
    add_action('wp_ajax_nopriv_ne_mlp_submit_order_request', [$order_request, 'handle_order_request_submission']);
});

// Fix for frontend 404 errors - ensure rewrite rules are properly maintained
add_action('wp_loaded', function() {
    // Check if endpoints are working properly
    if (is_user_logged_in() && (is_wc_endpoint_url('my-prescription') || is_wc_endpoint_url('upload-prescription'))) {
        global $wp_query;
        if ($wp_query->is_404()) {
            // Force re-registration of endpoints and flush rules
            add_rewrite_endpoint( 'my-prescription', EP_ROOT | EP_PAGES );
            add_rewrite_endpoint( 'upload-prescription', EP_ROOT | EP_PAGES );
            flush_rewrite_rules(true);
            // Redirect to avoid 404 on current request
            wp_redirect($_SERVER['REQUEST_URI']);
            exit;
        }
    }
}, 20); 