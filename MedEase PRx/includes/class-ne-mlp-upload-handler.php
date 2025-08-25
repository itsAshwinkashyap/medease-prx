<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NE_MLP_Upload_Handler {
    private static $shown = false;
    public function __construct() {
        // Show upload form on cart page
        add_action( 'woocommerce_before_cart', [ $this, 'maybe_show_upload_form_cart' ] );
        // Show upload form on checkout page
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'maybe_show_upload_form_checkout' ] );
        // Note: Prescription required label is handled by Frontend class to avoid duplication
        
        // Handle order page prescription upload via admin-post.php
        add_action('admin_post_nopriv_ne_mlp_order_prescription_upload', [$this, 'handle_order_prescription_upload']);
        add_action('admin_post_ne_mlp_order_prescription_upload', [$this, 'handle_order_prescription_upload']);
        
        // Checkout hooks
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_prescription' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_checkout_prescription' ), 10, 2 );
    }

    // Show upload form on cart page if any item requires prescription
    public function maybe_show_upload_form_cart() {
        if ( self::$shown ) return;
        if ( $this->cart_requires_prescription() ) {
            $this->render_upload_form();
            self::$shown = true;
        }
    }

    // Show upload form on checkout page if any item requires prescription
    public function maybe_show_upload_form_checkout() {
        if ( self::$shown ) return;
        if ( $this->cart_requires_prescription() ) {
            $this->render_upload_form();
            self::$shown = true;
        }
    }

    // Check if any cart item requires prescription
    private function cart_requires_prescription() {
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        return $prescription_manager->cart_requires_prescription();
    }

    // Render the upload form with dropdown for previous approved prescriptions
    public function render_upload_form() {
        $user_id = get_current_user_id();
        
        // Check if we're on checkout page - show different UI
        if ( is_checkout() ) {
            $this->render_checkout_prescription_form();
            return;
        }
        
        // For non-checkout pages, show full upload form
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $approved = [];
        if ( $user_id ) {
            $approved = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, file_paths, created_at, status FROM $table WHERE user_id = %d AND status = 'approved' AND type = 'medicine' ORDER BY created_at DESC",
                $user_id
            ) );
        }

        // Start form section
        echo '<div class="ne-mlp-upload-box" style="margin:24px 0;padding:20px;border:1px solid #ddd;border-radius:4px;">';
        echo '<h3>' . esc_html__( 'Prescription for Medicines', 'ne-med-lab-prescriptions' ) . '</h3>';
        
        // Modern toggle
        $attach_now_checked = (!isset($_POST['ne_mlp_attach_mode']) || $_POST['ne_mlp_attach_mode'] === 'now') ? 'checked' : '';
        $attach_later_checked = (isset($_POST['ne_mlp_attach_mode']) && $_POST['ne_mlp_attach_mode'] === 'later') ? 'checked' : '';
        echo '<div class="ne-mlp-toggle-wrap" style="margin:10px 0 18px 0;">';
        echo '<label class="ne-mlp-toggle"><input type="radio" name="ne_mlp_attach_mode" value="now" ' . $attach_now_checked . '><span>' . esc_html__('Attach Now','ne-med-lab-prescriptions') . '</span></label>';
        echo '<label class="ne-mlp-toggle"><input type="radio" name="ne_mlp_attach_mode" value="later" ' . $attach_later_checked . '><span>' . esc_html__('Attach Later','ne-med-lab-prescriptions') . '</span></label>';
        echo '</div>';

        $upload_section_style = ($attach_later_checked) ? 'display:none;' : '';
        echo '<div class="ne-mlp-upload-section" style="' . $upload_section_style . '">';
        
        // Get prescription options using centralized method
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        $prescription_options = $prescription_manager->get_user_medicine_prescriptions( $user_id, 'approved', true );
        
        if ( ! empty( $prescription_options ) ) {
            echo '<label for="ne_mlp_prescription_select">' . esc_html__( 'Select from your approved prescriptions:', 'ne-med-lab-prescriptions' ) . '</label><br />';
            echo '<select name="ne_mlp_prescription_select" id="ne_mlp_prescription_select" style="width:100%;margin-bottom:10px;">';
            echo '<option value="">' . esc_html__( '-- Select --', 'ne-med-lab-prescriptions' ) . '</option>';
            foreach ( $prescription_options as $option ) {
                echo '<option value="' . esc_attr($option['value']) . '" style="color:' . $option['color'] . ';">' . esc_html($option['label']) . '</option>';
            }
            echo '</select>';
            echo '<p style="margin:4px 0 10px;">' . esc_html__( 'Or upload a new prescription below:', 'ne-med-lab-prescriptions' ) . '</p>';
        }

        echo '<input type="file" class="ne-mlp-prescription-files" name="ne_mlp_prescription_files[]" multiple accept=".jpg,.jpeg,.png,.pdf" style="width:100%;margin-bottom:10px;" />';
        echo '<div class="ne-mlp-upload-preview"></div>';
        echo '<p class="description">' . esc_html__( 'Allowed: JPG, JPEG, PNG, PDF. Max 4 files, 5MB each.', 'ne-med-lab-prescriptions' ) . '</p>';
        echo '</div>';
        echo '</div>';

        // Add toggle CSS and JS
        echo '<style>
            .ne-mlp-toggle-wrap{display:flex;gap:18px;align-items:center;}
            .ne-mlp-toggle{display:inline-flex;align-items:center;gap:6px;font-size:15px;}
            .ne-mlp-toggle input[type="radio"]{accent-color:#389e0d;width:18px;height:18px;}
            .ne-mlp-upload-box{margin:24px 0;padding:20px;border:1px solid #ddd;border-radius:4px;}
            .ne-mlp-upload-box h3{margin-top:0;}
        </style>';
        
        // Add mutual exclusion JS
        echo '<script>
            jQuery(function($){
                // Ensure form is properly set up for file uploads
                var $form = $("form.checkout");
                if($form.length) {
                    $form.attr("enctype", "multipart/form-data");
                }
                
                // Handle file input changes
                $(".ne-mlp-prescription-files").on("change", function(){
                    $("select[name=ne_mlp_prescription_select]").prop("disabled", this.files.length > 0);
                });
                
                // Handle prescription select changes
                $("select[name=ne_mlp_prescription_select]").on("change", function(){
                    $(".ne-mlp-prescription-files").prop("disabled", !!this.value);
                });
                
                // Handle attach mode changes
                $("input[name=ne_mlp_attach_mode]").on("change", function(){
                    if(this.value === "later") {
                        $(".ne-mlp-upload-section").hide();
                    } else {
                        $(".ne-mlp-upload-section").show();
                    }
                });
            });
        </script>';
    }
    
    /**
     * Render prescription form specifically for checkout page
     */
    private function render_checkout_prescription_form() {
        $user_id = get_current_user_id();
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        
        // Get both approved and pending medicine prescriptions (default behavior)
        $prescription_options = $prescription_manager->get_user_medicine_prescriptions( $user_id, null, false );
        
        echo '<div class="ne-mlp-checkout-prescription-box" style="margin:24px 0;padding:20px;border:1px solid #ddd;border-radius:4px;background:#f9f9f9;">';
        echo '<h3>' . esc_html__( 'Prescription for Medicines', 'ne-med-lab-prescriptions' ) . '</h3>';
        
        // Modern toggle for attach now/later - DEFAULT to Attach Now
        echo '<div class="ne-mlp-toggle-wrap" style="margin:10px 0 18px 0;">';
        echo '<label class="ne-mlp-toggle"><input type="radio" name="ne_mlp_attach_mode" value="now" checked><span>' . esc_html__('Attach Now','ne-med-lab-prescriptions') . '</span></label>';
        echo '<label class="ne-mlp-toggle"><input type="radio" name="ne_mlp_attach_mode" value="later"><span>' . esc_html__('Attach Later','ne-med-lab-prescriptions') . '</span></label>';
        echo '</div>';
        
        // Attach Now section - shows by default
        echo '<div class="ne-mlp-attach-now-section">';
        
        // Small red error message shown by default
        echo '<div class="ne-mlp-error-message" style="color:#cf1322;font-size:12px;margin-bottom:10px;display:block;">Select a prescription to proceed.</div>';
        
        // Prescription selection dropdown with enhanced formatting
        if ( ! empty( $prescription_options ) ) {
            echo '<label for="ne_mlp_prescription_select" style="display:block;margin-bottom:8px;font-weight:600;">' . esc_html__( 'Select from your prescriptions:', 'ne-med-lab-prescriptions' ) . '</label>';
            echo '<select name="ne_mlp_prescription_select" id="ne_mlp_prescription_select" style="width:100%;padding:8px;border-radius:4px;border:1px solid #ddd;">';
            echo '<option value="">' . esc_html__( '-- Select Prescription --', 'ne-med-lab-prescriptions' ) . '</option>';
            foreach ( $prescription_options as $option ) {
                // Use the enhanced HTML label for color-coded display
                echo '<option value="' . esc_attr($option['value']) . '" data-status="' . esc_attr($option['status']) . '">';
                echo esc_html($option['label']); // This shows: Med-DD-MM-YY-ID - Status
                echo '</option>';
            }
            echo '</select>';
            
            // Upload button for checkout
            echo '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;">';
            echo '<p style="margin:0 0 8px 0;font-size:13px;color:#666;">Upload a new prescription.</p>';
            echo '<a href="' . home_url('my-account/upload-prescription//?return_to=checkout') . '" class="button" style="background:#52c41a;color:#fff;border:none;padding:8px 16px;border-radius:4px;text-decoration:none;font-weight:600;">Upload New Prescription</a>';
            echo '</div>';
        } else {
            echo '<p style="color:#666;font-style:italic;">' . esc_html__( 'No prescriptions found.', 'ne-med-lab-prescriptions' ) . '</p>';
            echo '<a href="' . home_url('my-account/upload-prescription//?return_to=checkout') . '" class="button" style="background:#52c41a;color:#fff;border:none;padding:8px 16px;border-radius:4px;text-decoration:none;font-weight:600;margin-top:8px;">Upload New Prescription</a>';
        }
        
        echo '</div>'; // .ne-mlp-attach-now-section
        
        // Attach Later section - hidden by default
        echo '<div class="ne-mlp-attach-later-section" style="display:none;">';
        echo '<div style="color:#666;padding:15px;background:#f0f0f0;border-radius:4px;border-left:4px solid #1890ff;">';
        echo 'You selected \'Attach Later.\' After placing your order, please upload a valid prescription to proceed.';
        echo '</div>';
        echo '</div>'; // .ne-mlp-attach-later-section
        
        echo '</div>'; // .ne-mlp-checkout-prescription-box
        
        // Add CSS and JavaScript for proper behavior
        echo '<style>
            .ne-mlp-toggle-wrap{display:flex;gap:18px;align-items:center;}
            .ne-mlp-toggle{display:inline-flex;align-items:center;gap:6px;font-size:15px;}
            .ne-mlp-toggle input[type="radio"]{accent-color:#389e0d;width:18px;height:18px;}
            .ne-mlp-disabled {
                opacity: 0.6 !important;
                cursor: not-allowed !important;
                background-color: #ccc !important;
                border-color: #ccc !important;
            }
        </style>';
        
        // Add JavaScript for toggle behavior and button state management
        echo '<script>
            jQuery(function($){
                // Function to disable/enable place order button
                function setPlaceOrderButton(enabled, showError) {
                    var placeOrderButton = $("#place_order");
                    if (placeOrderButton.length) {
                        if (enabled) {
                            placeOrderButton.removeClass("ne-mlp-disabled").prop("disabled", false);
                        } else {
                            placeOrderButton.addClass("ne-mlp-disabled").prop("disabled", true);
                        }
                    }
                    
                    // Show/hide error message
                    if (showError) {
                        $(".ne-mlp-error-message").show();
                    } else {
                        $(".ne-mlp-error-message").hide();
                    }
                }
                
                // Initialize - disable button by default when prescription required
                function initializeCheckout() {
                    setTimeout(function() {
                        var attachMode = $("input[name=ne_mlp_attach_mode]:checked").val();
                        var prescriptionSelected = $("#ne_mlp_prescription_select").val();
                        
                        if (attachMode === "later") {
                            setPlaceOrderButton(true, false);
                        } else if (attachMode === "now") {
                            if (prescriptionSelected) {
                                setPlaceOrderButton(true, false);
                            } else {
                                setPlaceOrderButton(false, true);
                            }
                        }
                    }, 100);
                }
                
                // Initialize on page load
                initializeCheckout();
                
                // Handle attach mode toggle
                $("input[name=ne_mlp_attach_mode]").on("change", function(){
                    if(this.value === "later") {
                        // Show attach later section, hide attach now
                        $(".ne-mlp-attach-now-section").hide();
                        $(".ne-mlp-attach-later-section").show();
                        
                        // Enable place order button for attach later
                        setPlaceOrderButton(true, false);
                    } else {
                        // Show attach now section, hide attach later
                        $(".ne-mlp-attach-now-section").show();
                        $(".ne-mlp-attach-later-section").hide();
                        
                        // Check if prescription is selected, if not disable button
                        var prescriptionSelected = $("#ne_mlp_prescription_select").val();
                        if (prescriptionSelected) {
                            setPlaceOrderButton(true, false);
                        } else {
                            setPlaceOrderButton(false, true);
                        }
                    }
                });
                
                // Handle prescription selection change
                $("#ne_mlp_prescription_select").on("change", function(){
                    var attachMode = $("input[name=ne_mlp_attach_mode]:checked").val();
                    
                    if (attachMode === "now") {
                        if (this.value) {
                            // Prescription selected - enable button and hide error
                            setPlaceOrderButton(true, false);
                        } else {
                            // No prescription selected - disable button and show error
                            setPlaceOrderButton(false, true);
                        }
                    }
                });
                
                // Handle form submission prevention when button is disabled
                $("form.checkout").on("submit", function(e){
                    var placeOrderButton = $("#place_order");
                    if (placeOrderButton.hasClass("ne-mlp-disabled")) {
                        e.preventDefault();
                        alert("Please select a prescription or choose \\"Attach Later\\" to proceed.");
                        return false;
                    }
                });
                
                // Handle WooCommerce checkout updates
                $(document.body).on("updated_checkout", function(){
                    // Re-initialize after WooCommerce updates
                    setTimeout(initializeCheckout, 200);
                });
                
                // Also handle on checkout form update
                $(document.body).on("update_checkout", function(){
                    setTimeout(initializeCheckout, 200);
                });
            });
        </script>';
    }

    // Note: Prescription required label functionality moved to Frontend class to avoid duplication

    public function handle_order_prescription_upload() {
        if (!is_user_logged_in()) wp_die('Not allowed');
        if (!isset($_POST['order_id'])) wp_die('No order');
        if (!check_admin_referer('ne_mlp_upload_prescription_order','ne_mlp_nonce_order')) wp_die('Invalid nonce');
        
        $order_id = intval($_POST['order_id']);
        $user_id = get_current_user_id();
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_user_id() != $user_id) wp_die('Invalid order');
        
        global $wpdb;
        $table = $wpdb->prefix . 'prescriptions';
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        
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
            
            // Validate selected prescription belongs to user and is approved/pending
            $selected_presc = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d AND user_id = %d AND status IN ('approved', 'pending')", 
                $selected_presc_id, 
                $user_id
            ));
            
            if ($selected_presc) {
                // If there's an existing rejected prescription, remove it first
                if ($existing_prescription && $existing_prescription->status === 'rejected') {
                    // Delete rejected prescription files
                    $old_files = json_decode($existing_prescription->file_paths, true);
                    if (is_array($old_files)) {
                        foreach ($old_files as $old_file) {
                            $old_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $old_file);
                            if (file_exists($old_path)) {
                                @unlink($old_path);
                            }
                        }
                    }
                    
                    // Delete rejected prescription record
                    $wpdb->delete($table, ['id' => $existing_presc_id]);
                }
                
                // Attach the selected prescription to order using centralized tracking
                $result = $prescription_manager->update_prescription_order_tracking($selected_presc_id, $order_id);
                
                if (!is_wp_error($result)) {
                    wp_redirect($order->get_view_order_url() . '?prescription_attached=1');
                } else {
                    wp_redirect($order->get_view_order_url() . '?prescription_error=' . urlencode($result->get_error_message()));
                }
                exit;
            } else {
                wp_redirect($order->get_view_order_url() . '?prescription_error=' . urlencode('Invalid prescription selected'));
                exit;
            }
        }
        // If uploading new files
        elseif (!empty($_FILES['ne_mlp_prescription_files']) && !empty($_FILES['ne_mlp_prescription_files']['name'][0])) {
            
            // If there's an existing rejected prescription, remove it first (don't re-upload, create new)
            if ($existing_prescription && $existing_prescription->status === 'rejected') {
                // Delete rejected prescription files
                $old_files = json_decode($existing_prescription->file_paths, true);
                if (is_array($old_files)) {
                    foreach ($old_files as $old_file) {
                        $old_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $old_file);
                        if (file_exists($old_path)) {
                            @unlink($old_path);
                        }
                    }
                }
                
                // Delete rejected prescription record
                $wpdb->delete($table, ['id' => $existing_presc_id]);
                
                // Remove order meta for rejected prescription
                delete_post_meta($order_id, '_ne_mlp_prescription_id');
                delete_post_meta($order_id, '_ne_mlp_prescription_status');
            }
            
            // Always create new prescription (no re-upload functionality)
            $upload_result = $prescription_manager->upload_prescription_files(
                $_FILES['ne_mlp_prescription_files'], 
                $user_id, 
                'medicine', 
                'website'
            );
            
            if (!is_wp_error($upload_result)) {
                // Attach new prescription to order using centralized tracking
                $attach_result = $prescription_manager->update_prescription_order_tracking($upload_result, $order_id);
                
                if (!is_wp_error($attach_result)) {
                    wp_redirect($order->get_view_order_url() . '?prescription_uploaded=1');
                } else {
                    wp_redirect($order->get_view_order_url() . '?prescription_error=' . urlencode($attach_result->get_error_message()));
                }
            } else {
                wp_redirect($order->get_view_order_url() . '?prescription_error=' . urlencode($upload_result->get_error_message()));
            }
            exit;
        } else {
            wp_redirect($order->get_view_order_url() . '?prescription_error=' . urlencode('No prescription data provided'));
            exit;
        }
    }

    /**
     * Validate checkout before order creation
     */
    public function validate_checkout_prescription() {
        if ( ! $this->cart_requires_prescription() ) {
            return;
        }
        
        // Check if user selected "Attach Later"
        $attach_mode = isset( $_POST['ne_mlp_attach_mode'] ) ? sanitize_text_field( $_POST['ne_mlp_attach_mode'] ) : 'now';
        
        if ( $attach_mode === 'later' ) {
            // Allow checkout to proceed - order will be created without prescription
            return;
        }
        
        // If attach now, check for prescription selection
        $selected_prescription = isset( $_POST['ne_mlp_prescription_select'] ) ? absint( $_POST['ne_mlp_prescription_select'] ) : 0;
        
        // Don't block checkout if no prescription selected - user can upload later
        if ( empty( $selected_prescription ) && $attach_mode === 'now' ) {
            // Just show a notice but don't block
            wc_add_notice( __( 'Note: You can upload prescription after placing your order if needed.', 'ne-med-lab-prescriptions' ), 'notice' );
            return;
        }
        
        // If prescription is selected, validate it
        if ( $selected_prescription && is_user_logged_in() ) {
            $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
            $prescription = $prescription_manager->get_prescription( $selected_prescription );
            
            if ( ! $prescription || $prescription->user_id != get_current_user_id() ) {
                wc_add_notice( __( 'Invalid prescription selected. Please choose a valid prescription.', 'ne-med-lab-prescriptions' ), 'error' );
            } elseif ( ! in_array( $prescription->status, ['approved', 'pending'] ) ) {
                wc_add_notice( __( 'Selected prescription is not available. Please choose an approved or pending prescription.', 'ne-med-lab-prescriptions' ), 'error' );
            }
        }
    }
    
    /**
     * Process prescription on checkout (after order creation)
     */
    public function process_checkout_prescription( $order_id, $posted_data ) {
        $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
        
        // Check if order requires prescription
        if ( ! $prescription_manager->order_requires_prescription( $order_id ) ) {
            return;
        }
        
        // Handle "Attach Later" mode
        if ( isset($_POST['ne_mlp_attach_mode']) && $_POST['ne_mlp_attach_mode'] === 'later' ) {
            // Just mark order as requiring prescription, don't attach anything yet
            update_post_meta( $order_id, '_ne_mlp_requires_prescription', 'yes' );
            update_post_meta( $order_id, '_ne_mlp_prescription_status', 'not_attached' );
            return;
        }
        
        // Handle prescription selection (existing prescription)
        if ( ! empty($_POST['ne_mlp_prescription_select']) ) {
            $prescription_id = intval($_POST['ne_mlp_prescription_select']);
            
            // Use centralized order tracking update
            $result = $prescription_manager->update_prescription_order_tracking( $prescription_id, $order_id );
            
            if ( ! is_wp_error( $result ) ) {
                // Get actual status from database, not hardcoded 'attached'
                $current_status = $prescription_manager->get_current_prescription_status( $prescription_id );
                if ( $current_status ) {
                    update_post_meta( $order_id, '_ne_mlp_prescription_status', $current_status );
                }
                return;
            }
        }
        
        // Handle file upload (new prescription)
        if ( ! empty($_FILES['ne_mlp_prescription_files']) && ! empty($_FILES['ne_mlp_prescription_files']['name'][0]) ) {
            $user_id = get_current_user_id();
            
            // Upload new prescription
            $upload_result = $prescription_manager->upload_prescription_files( 
                $_FILES['ne_mlp_prescription_files'], 
                $user_id, 
                'medicine', 
                'website' 
            );
            
            if ( ! is_wp_error( $upload_result ) ) {
                // Attach new prescription to order using centralized tracking
                $attach_result = $prescription_manager->update_prescription_order_tracking( $upload_result, $order_id );
                
                if ( ! is_wp_error( $attach_result ) ) {
                    // Get actual status from database (new uploads are 'pending')
                    $current_status = $prescription_manager->get_current_prescription_status( $upload_result );
                    if ( $current_status ) {
                        update_post_meta( $order_id, '_ne_mlp_prescription_status', $current_status );
                    }
                    return;
                }
            }
        }
        
        // If we reach here and attach mode is 'now', mark as requiring prescription
        if ( isset($_POST['ne_mlp_attach_mode']) && $_POST['ne_mlp_attach_mode'] === 'now' ) {
            update_post_meta( $order_id, '_ne_mlp_requires_prescription', 'yes' );
            update_post_meta( $order_id, '_ne_mlp_prescription_status', 'not_attached' );
        }
    }
}

// Initialize the class
new NE_MLP_Upload_Handler(); 