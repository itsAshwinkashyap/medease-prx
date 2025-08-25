<?php
if (!defined('ABSPATH')) exit;

/**
 * Central Manager for creating Order Requests
 * Single source of truth used by AJAX and REST.
 */
class NE_MLP_Order_Manager {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Create an order request
     *
     * @param array $args {
     *   @type int          user_id
     *   @type int|int[]    prescription_ids
     *   @type int          days
     *   @type string       notes
     *   @type bool         verify_ownership  Default true
     * }
     * @return array|WP_Error { request(object), request_id(int), formatted_id(string) }
     */
    public function create_request($args) {
        $user_id = intval($args['user_id'] ?? 0);
        $days = intval($args['days'] ?? 0);
        $notes = sanitize_textarea_field($args['notes'] ?? '');
        $verify_ownership = array_key_exists('verify_ownership', $args) ? (bool)$args['verify_ownership'] : true;
        $prescription_ids = $args['prescription_ids'] ?? [];

        if (!$user_id || $days <= 0) {
            return new WP_Error('invalid_params', __('Invalid user or days.', 'ne-med-lab-prescriptions'));
        }

        // Normalize prescription IDs array
        if (!is_array($prescription_ids)) {
            $prescription_ids = [$prescription_ids];
        }
        $prescription_ids = array_values(array_unique(array_map('intval', array_filter($prescription_ids))));
        if (empty($prescription_ids)) {
            return new WP_Error('invalid_prescription', __('Please provide at least one prescription.', 'ne-med-lab-prescriptions'));
        }

        // Validate ownership
        if ($verify_ownership && class_exists('NE_MLP_Prescription_Manager')) {
            $prescription_manager = NE_MLP_Prescription_Manager::getInstance();
            foreach ($prescription_ids as $pid) {
                $prescription = $prescription_manager->get_prescription($pid);
                if (!$prescription || intval($prescription->user_id) !== $user_id) {
                    return new WP_Error('invalid_prescription', __('Invalid prescription.', 'ne-med-lab-prescriptions'));
                }
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ne_mlp_order_requests';

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'prescription_ids' => wp_json_encode($prescription_ids),
                'days' => $days,
                'notes' => $notes,
                'status' => 'pending',
                'created_at' => current_time('mysql', true)
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('db_insert_failed', __('Failed to submit order request. Please try again.', 'ne-med-lab-prescriptions'));
        }

        $request_id = (int) $wpdb->insert_id;
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

        // Notify listeners
        do_action('ne_mlp_order_request_created', $request);

        // Use formatter from Order_Request class if available
        $formatted_id = 'PRxO' . date('Ymd', strtotime($request->created_at)) . $request->id;
        if (class_exists('NE_MLP_Order_Request')) {
            $req_inst = NE_MLP_Order_Request::getInstance();
            if (method_exists($req_inst, 'format_order_request_id')) {
                $formatted_id = $req_inst->format_order_request_id($request);
            }
        }

        return [
            'request' => $request,
            'request_id' => $request_id,
            'formatted_id' => $formatted_id,
        ];
    }
}
