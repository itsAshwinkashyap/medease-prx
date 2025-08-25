<?php
/**
 * Template for the Order Requests modal
 * 
 * @package NE_MLP
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>

<div id="ne-mlp-order-requests-modal" class="ne-mlp-modal">
    <div class="ne-mlp-modal-overlay"></div>
    <div class="ne-mlp-modal-content">
        <!-- Modal Header -->
        <div class="ne-mlp-modal-header">
            <h3><?php _e('My Order Requests', 'ne-med-lab-prescriptions'); ?></h3>
            <button type="button" class="ne-mlp-close-modal" aria-label="<?php esc_attr_e('Close', 'ne-med-lab-prescriptions'); ?>">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 6L6 18M6 6L18 18" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
        
        <!-- Modal Body -->
        <div class="ne-mlp-modal-body">
            <!-- Loading State -->
            <div class="ne-mlp-loading">
                <div class="ne-mlp-spinner"></div>
                <p><?php _e('Loading your order requests...', 'ne-med-lab-prescriptions'); ?></p>
            </div>
            
            <!-- Empty State -->
            <div class="ne-mlp-empty-state">
                <div class="ne-mlp-empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 7V5C3 3.89543 3.89543 3 5 3H7M21 7V5C21 3.89543 20.1046 3 19 3H17M7 21H5C3.89543 21 3 20.1046 3 19V17M21 17V19C21 20.1046 20.1046 21 19 21H17M3 12H21M12 3V21M12 3C10.3431 3 9 4.34315 9 6C9 7.65685 10.3431 9 12 9C13.6569 9 15 7.65685 15 6C15 4.34315 13.6569 3 12 3ZM12 15C10.3431 15 9 16.3431 9 18C9 19.6569 10.3431 21 12 21C13.6569 21 15 19.6569 15 18C15 16.3431 13.6569 15 12 15Z" stroke="#9CA3AF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h4><?php _e('No order requests found', 'ne-med-lab-prescriptions'); ?></h4>
                <p><?php _e('You haven\'t made any order requests yet.', 'ne-med-lab-prescriptions'); ?></p>
            </div>
            
            <!-- Requests List -->
            <div class="ne-mlp-requests-list">
                <div class="ne-mlp-requests-table">
                    <table>
                        <thead>
                            <tr>
                                <th><?php _e('Request ID', 'ne-med-lab-prescriptions'); ?></th>
                                <th><?php _e('Prescription ID', 'ne-med-lab-prescriptions'); ?></th>
                                <th><?php _e('Date', 'ne-med-lab-prescriptions'); ?></th>
                                <th><?php _e('Status', 'ne-med-lab-prescriptions'); ?></th>
                                <th><?php _e('Days', 'ne-med-lab-prescriptions'); ?></th>
                                <th class="text-right"><?php _e('Actions', 'ne-med-lab-prescriptions'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="ne-mlp-requests-tbody">
                            <!-- Requests will be loaded here via JavaScript -->
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="ne-mlp-pagination">
                    <button class="ne-mlp-prev-page" disabled><?php _e('Previous', 'ne-med-lab-prescriptions'); ?></button>
                    <span class="ne-mlp-page-info"></span>
                    <button class="ne-mlp-next-page"><?php _e('Next', 'ne-med-lab-prescriptions'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Modal Container */
    .ne-mlp-modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .ne-mlp-modal.show {
        opacity: 1;
        visibility: visible;
    }

    .ne-mlp-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
    }

    .ne-mlp-modal-content {
        position: relative;
        width: 100%;
        max-width: 1000px;
        max-height: 90vh;
        margin: 20px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        display: flex;
        flex-direction: column;
        transform: translateY(20px);
        transition: transform 0.3s ease;
    }

    .ne-mlp-modal.show .ne-mlp-modal-content {
        transform: translateY(0);
    }

    /* Modal Header */
    .ne-mlp-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #EAECF0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .ne-mlp-modal-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: #111827;
    }

    .ne-mlp-close-modal {
        background: none;
        border: none;
        padding: 8px;
        margin: -8px;
        cursor: pointer;
        color: #6B7280;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .ne-mlp-close-modal:hover {
        background: #F3F4F6;
        color: #4B5563;
    }

    /* Modal Body */
    .ne-mlp-modal-body {
        padding: 0;
        flex: 1;
        overflow-y: auto;
    }

    /* Loading State */
    .ne-mlp-loading {
        padding: 60px 20px;
        text-align: center;
        display: none;
    }

    .ne-mlp-spinner {
        width: 40px;
        height: 40px;
        border: 4px solid #F3F4F6;
        border-top: 4px solid #6D28D9;
        border-radius: 50%;
        margin: 0 auto 16px;
        animation: ne-mlp-spin 1s linear infinite;
    }

    .ne-mlp-loading p {
        color: #6B7280;
        margin: 0;
        font-size: 14px;
    }

    /* Empty State */
    .ne-mlp-empty-state {
        padding: 60px 20px;
        text-align: center;
        display: none;
    }

    .ne-mlp-empty-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #F9FAFB;
        border-radius: 50%;
    }

    .ne-mlp-empty-state h4 {
        margin: 0 0 8px;
        font-size: 16px;
        font-weight: 500;
        color: #111827;
    }

    .ne-mlp-empty-state p {
        margin: 0;
        color: #6B7280;
        font-size: 14px;
    }

    /* Requests Table */
    .ne-mlp-requests-list {
        display: none;
        flex-direction: column;
        height: 100%;
    }

    .ne-mlp-requests-table {
        flex: 1;
        overflow-y: auto;
    }

    .ne-mlp-requests-table table {
        width: 100%;
        border-collapse: collapse;
    }

    .ne-mlp-requests-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 12px;
        font-weight: 500;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: #F9FAFB;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .ne-mlp-requests-table th.text-right {
        text-align: right;
    }

    .ne-mlp-requests-table td {
        padding: 16px;
        border-bottom: 1px solid #EAECF0;
        vertical-align: middle;
        font-size: 14px;
        color: #374151;
    }

    .ne-mlp-request-row:hover {
        background-color: #F9FAFB;
    }

    /* Status Badges */
    .ne-mlp-request-status {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 16px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .status-pending, .status-awaiting {
        background-color: #FEF3C7;
        color: #92400E;
        border: 1px solid #FCD34D;
    }

    .status-processing {
        background-color: #DBEAFE;
        color: #1E40AF;
        border: 1px solid #93C5FD;
    }

    .status-completed, .status-approved {
        background-color: #D1FAE5;
        color: #065F46;
        border: 1px solid #6EE7B7;
    }

    .status-cancelled {
        background-color: #FEE2E2;
        color: #991B1B;
        border: 1px solid #FCA5A5;
    }
    
    /* Awaiting Order Badge */
    .ne-mlp-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 16px;
        font-size: 12px;
        font-weight: 500;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }
    
    .ne-mlp-badge-muted {
        background-color: #F3F4F6;
        color: #6B7280;
        border: 1px solid #E5E7EB;
    }

    /* Action Buttons */
    .ne-mlp-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        line-height: 1.5;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s;
        border: 1px solid transparent;
    }

    .ne-mlp-btn:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.2);
    }

    .ne-mlp-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .ne-mlp-btn-purple {
        background: #6D28D9;
        color: #fff;
    }

    .ne-mlp-btn-purple:hover {
        background: #5B21B6;
    }

    /* Badges */
    .ne-mlp-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 500;
        line-height: 1.5;
    }

    .ne-mlp-badge-muted {
        background: #F3F4F6;
        color: #6B7280;
    }

    /* ID Styling */
    .ne-mlp-id {
        font-weight: 500;
        color: #111827;
        font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    }

    /* Pagination */
    .ne-mlp-pagination {
        padding: 16px 24px;
        border-top: 1px solid #EAECF0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    .ne-mlp-prev-page,
    .ne-mlp-next-page {
        padding: 8px 16px;
        background: #fff;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
        color: #4B5563;
        cursor: pointer;
        transition: all 0.2s;
    }

    .ne-mlp-prev-page:hover:not(:disabled),
    .ne-mlp-next-page:hover:not(:disabled) {
        background: #F9FAFB;
        border-color: #9CA3AF;
    }

    .ne-mlp-prev-page:disabled,
    .ne-mlp-next-page:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .ne-mlp-page-info {
        font-size: 14px;
        color: #6B7280;
        min-width: 80px;
        text-align: center;
    }

    /* Animations */
    @keyframes ne-mlp-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .ne-mlp-modal-content {
            margin: 10px;
            max-height: 95vh;
        }

        .ne-mlp-requests-table {
            display: block;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .ne-mlp-requests-table th,
        .ne-mlp-requests-table td {
            padding: 12px 8px;
            font-size: 13px;
        }
    }
</style>
