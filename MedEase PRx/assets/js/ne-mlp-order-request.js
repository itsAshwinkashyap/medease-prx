jQuery(document).ready(function($) {
    // Show more/less functionality for notes
    $('.show-more-link').on('click', function(e) {
        e.preventDefault();
        var $container = $(this).closest('.notes-container');
        $container.find('.notes-text, .notes-full').toggle();
        $(this).text(function(i, text) {
            return text === neMlpOrderRequest.showMore ? neMlpOrderRequest.showLess : neMlpOrderRequest.showMore;
        });
    });

    let currentRequestId = null;
    
    // Function to update badge count
    function updateBadgeCount() {
        $.ajax({
            url: neMlpOrderRequest.ajaxurl,
            type: 'POST',
            data: {
                action: 'ne_mlp_get_pending_count'
            },
            success: function(response) {
                if (response.success) {
                    const count = response.data.count;
                    const menuItem = $('a[href*="ne-mlp-request-order"]').first();
                    const existingBadge = menuItem.find('.ne-mlp-notification-badge');
                    
                    console.log('Badge update - Count:', count, 'Menu item found:', menuItem.length, 'Existing badge:', existingBadge.length);
                    
                    if (count > 0) {
                        if (existingBadge.length) {
                            existingBadge.text(count);
                        } else if (menuItem.length) {
                            menuItem.find('.ne-mlp-menu-item').append(' <span class="ne-mlp-notification-badge" style="background:#d63638;color:#fff;border-radius:10px;padding:2px 6px;font-size:11px;margin-left:5px;">' + count + '</span>');
                        }
                    } else {
                        existingBadge.remove();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log('Badge update error:', error);
            }
        });
    }
    
    // Update badge count on page load
    $(document).ready(function() {
        updateBadgeCount();
    });
    
    // Reject request
    $(document).on('click', '.reject-request', function() {
        currentRequestId = $(this).data('request-id');
        $('#reject-modal').show();
        $('#reject-reason').focus();
    });
    
    // Cancel reject
    $(document).on('click', '#cancel-reject', function() {
        $('#reject-modal').hide();
        $('#reject-reason').val('');
        currentRequestId = null;
    });
    
    // Confirm reject
    $(document).on('click', '#confirm-reject', function() {
        const reason = $('#reject-reason').val().trim();
        if (!reason) {
            alert('Please provide a rejection reason.');
            return;
        }
        
        const button = $(this);
        button.prop('disabled', true).text(neMlpOrderRequest.processing);
        
        $.ajax({
            url: neMlpOrderRequest.ajaxurl,
            type: 'POST',
            data: {
                action: 'ne_mlp_reject_order_request',
                request_id: currentRequestId,
                admin_notes: reason,
                nonce: neMlpOrderRequest.rejectNonce
            },
            success: function(response) {
                if (response.success) {
                    updateBadgeCount();
                    if (typeof showMessage === 'function') {
                        showMessage(
                            'Request Rejected',
                            neMlpOrderRequest.rejectSuccess,
                            'success',
                            {
                                buttons: [{ label: 'OK', className: 'button', action: 'close' }],
                                onClose: function() { location.reload(); }
                            }
                        );
                    } else {
                        alert(neMlpOrderRequest.rejectSuccess);
                        location.reload();
                    }
                } else {
                    if (typeof showMessage === 'function') {
                        showMessage('Error', neMlpOrderRequest.errorPrefix + (response.data || ''), 'error', { buttons: [{ label: 'Close', className: 'button', action: 'close' }] });
                    } else {
                        alert(neMlpOrderRequest.errorPrefix + response.data);
                    }
                    button.prop('disabled', false).text(neMlpOrderRequest.rejectText);
                }
            },
            error: function() {
                if (typeof showMessage === 'function') {
                    showMessage('Error', neMlpOrderRequest.errorProcessing, 'error', { buttons: [{ label: 'Close', className: 'button', action: 'close' }] });
                } else {
                    alert(neMlpOrderRequest.errorProcessing);
                }
                button.prop('disabled', false).text(neMlpOrderRequest.rejectText);
            }
        });
    });
    
    // Delete request
    $(document).on('click', '.delete-request', function() {
        const requestId = $(this).data('request-id');
        const button = $(this);
        
        if (!confirm(neMlpOrderRequest.confirmDelete)) {
            return;
        }
        
        button.prop('disabled', true).text(neMlpOrderRequest.processing);
        
        $.ajax({
            url: neMlpOrderRequest.ajaxurl,
            type: 'POST',
            data: {
                action: 'ne_mlp_delete_order_request',
                request_id: requestId,
                nonce: neMlpOrderRequest.deleteNonce
            },
            success: function(response) {
                if (response.success) {
                    updateBadgeCount();
                    if (typeof showMessage === 'function') {
                        showMessage(
                            'Request Deleted',
                            neMlpOrderRequest.deleteSuccess,
                            'success',
                            {
                                buttons: [{ label: 'OK', className: 'button', action: 'close' }],
                                onClose: function() { location.reload(); }
                            }
                        );
                    } else {
                        alert(neMlpOrderRequest.deleteSuccess);
                        location.reload();
                    }
                } else {
                    if (typeof showMessage === 'function') {
                        showMessage('Error', neMlpOrderRequest.errorPrefix + (response.data || ''), 'error', { buttons: [{ label: 'Close', className: 'button', action: 'close' }] });
                    } else {
                        alert(neMlpOrderRequest.errorPrefix + response.data);
                    }
                    button.prop('disabled', false).text(neMlpOrderRequest.deleteText);
                }
            },
            error: function() {
                if (typeof showMessage === 'function') {
                    showMessage('Error', neMlpOrderRequest.errorProcessing, 'error', { buttons: [{ label: 'Close', className: 'button', action: 'close' }] });
                } else {
                    alert(neMlpOrderRequest.errorProcessing);
                }
                button.prop('disabled', false).text(neMlpOrderRequest.deleteText);
            }
        });
    });
    
    // Approve request
    $(document).on('click', '.approve-request', function() {
        const requestId = $(this).data('request-id');
        const button = $(this);
        
        if (!confirm(neMlpOrderRequest.confirmApprove)) {
            return;
        }
        
        button.prop('disabled', true).text(neMlpOrderRequest.processing);
        
        $.ajax({
            url: neMlpOrderRequest.ajaxurl,
            type: 'POST',
            data: {
                action: 'ne_mlp_approve_order_request',
                request_id: requestId,
                nonce: neMlpOrderRequest.approveNonce
            },
            success: function(response) {
                if (response.success) {
                    updateBadgeCount();
                    // Build admin order edit URL
                    var orderId = response.data && response.data.order_id ? response.data.order_id : null;
                    var orderUrl = orderId ? (neMlpOrderRequest.orderEditBase + '?post=' + orderId + '&action=edit') : null;
                    if (typeof showMessage === 'function') {
                        showMessage(
                            'Order Created',
                            neMlpOrderRequest.approveSuccess,
                            'success',
                            {
                                buttons: [
                                    orderUrl ? { label: 'View Order', className: 'button button-primary', action: function() { window.location.href = orderUrl; return false; } } : null,
                                    { label: 'OK', className: 'button', action: 'close' }
                                ].filter(Boolean),
                                // Only reload when the user clicks OK (i.e., modal close)
                                onClose: function() { location.reload(); }
                            }
                        );
                    } else {
                        alert(neMlpOrderRequest.approveSuccess);
                        location.reload();
                    }
                } else {
                    if (typeof showMessage === 'function') {
                        showMessage('Error', neMlpOrderRequest.errorPrefix + (response.data || ''), 'error', { buttons: [{ label: 'Close', className: 'button', action: 'close' }] });
                    } else {
                        alert(neMlpOrderRequest.errorPrefix + response.data);
                    }
                    button.prop('disabled', false).text(neMlpOrderRequest.approveText);
                }
            },
            error: function() {
                if (typeof showMessage === 'function') {
                    showMessage('Error', neMlpOrderRequest.errorProcessing, 'error', { buttons: [{ label: 'Close', className: 'button', action: 'close' }] });
                } else {
                    alert(neMlpOrderRequest.errorProcessing);
                }
                button.prop('disabled', false).text(neMlpOrderRequest.approveText);
            }
        });
    });
    
    // Close modal when clicking outside
    $(document).on('click', '#reject-modal', function(e) {
        if (e.target === this) {
            $('#reject-modal').hide();
            $('#reject-reason').val('');
            currentRequestId = null;
        }
    });
    
    // Close modal with Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#reject-modal').is(':visible')) {
            $('#reject-modal').hide();
            $('#reject-reason').val('');
            currentRequestId = null;
        }
    });
    
});
