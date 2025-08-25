<?php
/**
 * Template for displaying success/error messages in a modal
 * 
 * @package NE_MLP
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="ne-mlp-message-modal" class="ne-mlp-message-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;justify-content:center;align-items:center;opacity:0;transition:opacity 0.3s ease;">
    <div class="ne-mlp-message-overlay" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;"></div>
    <div class="ne-mlp-message-content" style="background:#fff;padding:30px;border-radius:12px;width:100%;max-width:450px;position:relative;z-index:2;box-shadow:0 10px 25px rgba(0,0,0,0.1);margin:20px;transform:translateY(-20px);transition:transform 0.3s ease;">
        <div class="ne-mlp-message-icon" style="text-align:center;margin-bottom:20px;font-size:48px;line-height:1;">
            <span class="dashicons" style="font-size:inherit;width:auto;height:auto;"></span>
        </div>
        <h3 class="ne-mlp-message-title" style="margin:0 0 15px 0;color:#1f2937;font-size:1.5rem;font-weight:600;text-align:center;"></h3>
        <div class="ne-mlp-message-text" style="color:#4b5563;margin-bottom:25px;text-align:center;line-height:1.5;"></div>
        <div class="ne-mlp-message-actions" style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;">
            <button type="button" class="ne-mlp-message-close button button-primary" style="padding:10px 24px;font-size:1rem;font-weight:500;border-radius:6px;cursor:pointer;transition:all 0.2s;">
                <?php esc_html_e('OK', 'ne-med-lab-prescriptions'); ?>
            </button>
        </div>
    </div>
</div>

<script type="text/javascript">
(function($) {
    // Make the showMessage function globally available
    window.showMessage = function(title, message, type = 'success', options = null) {
        const $modal = $('#ne-mlp-message-modal');
        const $icon = $modal.find('.ne-mlp-message-icon .dashicons');
        const $actions = $modal.find('.ne-mlp-message-actions');

        // Set icon based on message type
        if (type === 'success') {
            $icon.removeClass().addClass('dashicons dashicons-yes-alt');
            $modal.removeClass('ne-mlp-message-error').addClass('ne-mlp-message-success');
        } else {
            $icon.removeClass().addClass('dashicons dashicons-warning');
            $modal.removeClass('ne-mlp-message-success').addClass('ne-mlp-message-error');
        }

        // Set content
        $modal.find('.ne-mlp-message-title').text(title);
        $modal.find('.ne-mlp-message-text').html(message);

        // Backward compatibility: if options is a function, treat as onClose
        let onClose = null;
        if (typeof options === 'function') {
            onClose = options;
            options = {};
        } else if (options && typeof options.onClose === 'function') {
            onClose = options.onClose;
        }

        // Build actions
        $actions.empty();
        const buttons = (options && Array.isArray(options.buttons) && options.buttons.length)
            ? options.buttons
            : [
                { label: (type === 'success' ? 'OK' : 'Close'), className: 'button button-primary', action: 'close' }
            ];
        buttons.forEach(function(btn) {
            const $btn = $('<button/>', {
                type: 'button',
                text: btn.label || 'OK',
                class: (btn.className || 'button') + ' ne-mlp-message-action',
                style: 'padding:10px 24px;font-size:1rem;font-weight:500;border-radius:6px;cursor:pointer;transition:all 0.2s;'
            });
            $btn.on('click', function() {
                if (btn.action === 'close' || typeof btn.action === 'undefined') {
                    $modal.removeClass('show');
                    if (onClose) onClose();
                } else if (typeof btn.action === 'function') {
                    // Allow custom callbacks; close by default after
                    const maybeClose = btn.action();
                    if (maybeClose !== false) {
                        $modal.removeClass('show');
                        if (onClose) onClose();
                    }
                } else if (typeof btn.href === 'string') {
                    window.location.href = btn.href;
                }
            });
            $actions.append($btn);
        });

        // Show modal with animation
        $modal.addClass('show');

        // Close modal when clicking OK or overlay
        $modal.off('click').on('click', function(e) {
            if ($(e.target).closest('.ne-mlp-message-close, .ne-mlp-message-overlay').length) {
                $modal.removeClass('show');
                if (onClose) { onClose(); }
            }
        });
    };
})(jQuery);
</script>

<style>
.ne-mlp-message-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 99999;
    justify-content: center;
    align-items: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.ne-mlp-message-modal.show {
    display: flex !important;
    opacity: 1 !important;
}

.ne-mlp-message-content {
    background: #fff;
    padding: 30px;
    border-radius: 12px;
    width: 100%;
    max-width: 450px;
    position: relative;
    z-index: 2;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    margin: 20px;
    transform: translateY(-20px);
    transition: transform 0.3s ease;
}

.ne-mlp-message-modal.show .ne-mlp-message-content {
    transform: translateY(0) !important;
}

.ne-mlp-message-icon {
    text-align: center;
    margin-bottom: 20px;
    font-size: 48px;
    line-height: 1;
}

.ne-mlp-message-title {
    margin: 0 0 15px 0;
    color: #1f2937;
    font-size: 1.5rem;
    font-weight: 600;
    text-align: center;
}

.ne-mlp-message-text {
    color: #4b5563;
    margin-bottom: 25px;
    text-align: center;
    line-height: 1.5;
}

.ne-mlp-message-actions {
    display: flex;
    justify-content: center;
}

.ne-mlp-message-close {
    padding: 10px 24px;
    font-size: 1rem;
    font-weight: 500;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.ne-mlp-message-success .ne-mlp-message-icon {
    color: #52c41a;
}

.ne-mlp-message-error .ne-mlp-message-icon {
    color: #ff4d4f;
}

.ne-mlp-message-success .ne-mlp-message-close {
    background: #1677FF;
    color: white;
}

.ne-mlp-message-error .ne-mlp-message-close {
    background: #ff4d4f;
    color: white;
}

.ne-mlp-message-close:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.ne-mlp-message-close:active {
    transform: translateY(0);
}

/* Make sure the modal is above other elements */
body.ne-mlp-modal-open {
    overflow: hidden;
}
</style>
