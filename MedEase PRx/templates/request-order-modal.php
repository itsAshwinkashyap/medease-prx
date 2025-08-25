<?php
/**
 * Template for the Request Order modal
 * 
 * @package NE_MLP
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

$prescription_id = isset($args['prescription_id']) ? absint($args['prescription_id']) : 0;

if (!$prescription_id) {
    return;
}

// Get the prescription data
$prescription_manager = NE_MLP_Prescription_Manager::getInstance();
$prescription = $prescription_manager->get_prescription($prescription_id);

if (!$prescription) {
    return;
}

// Format the upload date
try {
    $upload_date = new DateTime($prescription->created_at);
    $formatted_date = $upload_date->format('d-m-y');
} catch (Exception $e) {
    $formatted_date = date('d-m-y');
}
?>

<div id="ne-mlp-request-order-modal-<?php echo esc_attr($prescription_id); ?>" class="ne-mlp-request-order-modal" data-id="<?php echo esc_attr($prescription_id); ?>" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;padding:20px;box-sizing:border-box;opacity:0;transition:opacity 0.3s ease;">
    <div class="ne-mlp-modal-overlay" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;"></div>
    <div class="ne-mlp-modal-content" style="background:#fff;padding:30px;border-radius:12px;width:100%;max-width:500px;position:relative;z-index:2;box-shadow:0 10px 25px rgba(0,0,0,0.1);border:1px solid rgba(0,0,0,0.08);transform:translateY(-20px);transition:transform 0.3s ease;">
        <button class="ne-mlp-close-modal" style="position:absolute;top:15px;right:15px;background:#ff4d4f;border:none;font-size:20px;cursor:pointer;color:white;transition:all 0.2s ease;line-height:1;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.15);">
            <span style="display:block;margin-top:-2px;">&times;</span>
        </button>
        
        <div style="margin-bottom:20px;">
            <h3 style="margin:0 0 5px 0;color:#1f2937;font-size:1.5rem;font-weight:600;">
                <?php esc_html_e('Request Order', 'ne-med-lab-prescriptions'); ?>
            </h3>
            <div style="color:#1677FF;font: size 1.0em;em;margin-bottom:5px;">
                <?php esc_html_e('Prescription ID', 'ne-med-lab-prescriptions'); ?>: 
                <span style="font-family:monospace;background:#f3f4f6;padding:2px 8px;border-radius:4px;color:#4b5563;">
                    <?php 
                    // Format: Med-YY-MM-DD-ID using prescription's upload date
                    $formatted_id = 'Med-' . $formatted_date . '-' . $prescription_id;
                    echo esc_html($formatted_id);
                    ?>
                </span>
            </div>
        </div>
        
        <form class="ne-mlp-request-order-form" data-prescription-id="<?php echo esc_attr($prescription_id); ?>">
            <?php wp_nonce_field('ne_mlp_request_order_nonce', 'request_order_nonce'); ?>
            <input type="hidden" name="prescription_id" value="<?php echo esc_attr($prescription_id); ?>">
            
            <div class="ne-mlp-form-group" style="margin-bottom:20px;">
                <label for="days-<?php echo esc_attr($prescription_id); ?>" style="display:block;margin-bottom:8px;font-weight:500;color:#374151;font: size 0.7em;">
                    <?php esc_html_e('Select Number of Days', 'ne-med-lab-prescriptions'); ?>
                    <span style="color:#ef4444;">*</span>
                </label>
                <select 
                    id="days-<?php echo esc_attr($prescription_id); ?>" 
                    name="days" 
                    required 
                    style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.95rem;color:#111827;transition:border-color 0.2s,box-shadow 0.2s;appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E\");background-repeat:no-repeat;background-position:right 12px center;background-size:16px;"
                >
                    <option value=""><?php esc_html_e('-- Select Days --', 'ne-med-lab-prescriptions'); ?></option>
                    <option value="7"><?php esc_html_e('7 Days', 'ne-med-lab-prescriptions'); ?></option>
                    <option value="15"><?php esc_html_e('15 Days', 'ne-med-lab-prescriptions'); ?></option>
                    <option value="30"><?php esc_html_e('30 Days', 'ne-med-lab-prescriptions'); ?></option>
                    <option value="60"><?php esc_html_e('60 Days', 'ne-med-lab-prescriptions'); ?></option>
                    <option value="90"><?php esc_html_e('90 Days', 'ne-med-lab-prescriptions'); ?></option>
                    <option value="custom"><?php esc_html_e('Custom days...', 'ne-med-lab-prescriptions'); ?></option>
                </select>
                <div id="custom-days-container" style="display:none;margin-top:10px;">
                    <label for="custom-days-<?php echo esc_attr($prescription_id); ?>" style="display:block;margin-bottom:5px;font-weight:500;color:#374151;font: size 0.7em;">
                        <?php esc_html_e('Enter number of days', 'ne-med-lab-prescriptions'); ?>
                    </label>
                    <input type="number" id="custom-days-<?php echo esc_attr($prescription_id); ?>" name="custom_days" min="1" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.95rem;color:#111827;">
                </div>
            </div>
            
            <div style="margin-bottom:25px;">
                <label for="notes-<?php echo esc_attr($prescription_id); ?>" style="display:block;margin-bottom:8px;font-weight:500;color:#374151;font: size 0.7em;">
                    <?php esc_html_e('Additional Notes & Requirements (Optional)', 'ne-med-lab-prescriptions'); ?>
                </label>
                <textarea 
                    id="notes-<?php echo esc_attr($prescription_id); ?>" 
                    name="notes" 
                    rows="3" 
                    style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.95rem;color:#111827;transition:border-color 0.2s,box-shadow 0.2s;resize:vertical;min-height:80px;"
                    placeholder="<?php esc_attr_e('Any special instructions or notes...', 'ne-med-lab-prescriptions'); ?>"
                ></textarea>
            </div>
            
            <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:10px;padding-top:10px;border-top:1px solid #f3f4f6;">
                <button 
                    type="button" 
                    class="ne-mlp-close-modal" 
                    style="padding:10px 18px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;font-weight:500;color:#4b5563;transition:all 0.2s;font-size:0.95rem;"
                    onmouseover="this.style.backgroundColor='#f3f4f6';this.style.borderColor='#d1d5db'"
                    onmouseout="this.style.backgroundColor='#f9fafb';this.style.borderColor='#e5e7eb'"
                >
                    <?php esc_html_e('Cancel', 'ne-med-lab-prescriptions'); ?>
                </button>
                <button 
                    type="submit" 
                    class="ne-mlp-submit-btn"
                    style="padding:10px 22px;background:#7c3aed;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:500;transition:all 0.2s;font-size:0.95rem;display:flex;align-items:center;justify-content:center;min-width:120px;"
                    onmouseover="this.style.backgroundColor='#6d28d9'"
                    onmouseout="this.style.backgroundColor='#7c3aed'"
                >
                    <span class="button-text"><?php esc_html_e('Submit Request', 'ne-med-lab-prescriptions'); ?></span>
                    <span class="loading-spinner hidden" style="display:none;margin-left:8px;animation:spin 1s linear infinite;width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-radius:50%;border-top-color:#fff;margin-left:8px;"></span>
                </button>
            </div>
        </form>
    </div>
    <div class="ne-mlp-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1;cursor:pointer;"></div>
</div>

<style>
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    .ne-mlp-close-modal:hover {
        color: #4b5563;
        background-color: #f3f4f6;
    }
</style>

<!-- Inline JS removed to avoid duplicate event bindings. Functionality handled by assets/js/ne-mlp-order-modal.js -->
