<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NE_MLP_Product_Meta {
    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_prescription_checkbox' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_prescription_checkbox' ] );
    }

    // Add checkbox to product general tab
    public function add_prescription_checkbox() {
        woocommerce_wp_checkbox( [
            'id'            => '_ne_mlp_requires_prescription',
            'label'         => __( 'Requires Prescription', 'ne-med-lab-prescriptions' ),
            'description'   => __( 'Check if this product requires a prescription to purchase.', 'ne-med-lab-prescriptions' ),
        ] );
    }

    // Save checkbox value
    public function save_prescription_checkbox( $post_id ) {
        // Check if we have permission to save
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        
        // Check for nonce security
        if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
            return;
        }
        
        $checkbox = isset( $_POST['_ne_mlp_requires_prescription'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_ne_mlp_requires_prescription', $checkbox );
    }
}

// Initialize the class
new NE_MLP_Product_Meta(); 