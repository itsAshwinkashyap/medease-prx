/**
 * Order Request Modal Functionality
 * Handles the order request modal display and interactions
 */
jQuery(function($) {
    // Request Order Modal Handling
    function initOrderModal() {
        // Handle modal open
        $(document).on('click', '.ne-mlp-request-order-btn', function(e) {
            e.preventDefault();
            console.log('Request Order button clicked');
            
            const prescriptionId = $(this).data('id');
            console.log('Prescription ID:', prescriptionId);
            
            const $modal = $(`.ne-mlp-request-order-modal[data-id="${prescriptionId}"]`);
            console.log('Modal element found:', $modal.length > 0);
            
            if ($modal.length) {
                console.log('Showing modal...');
                // Ensure the modal is visible
                $modal.css({
                    'display': 'flex',
                    'opacity': '1',
                    'visibility': 'visible'
                });
                $modal.addClass('active');
                $('body').addClass('ne-mlp-modal-open');
                
                // Log the current state
                console.log('Modal display style:', $modal.css('display'));
                console.log('Modal opacity:', $modal.css('opacity'));
                console.log('Modal visibility:', $modal.css('visibility'));
                console.log('Body class after addClass:', $('body').attr('class'));
            } else {
                console.error('Modal not found for prescription ID:', prescriptionId);
                console.log('All modals on page:', $('.ne-mlp-request-order-modal').length);
            }
        });
        
        // Close modal when clicking close button or outside modal
        $(document).on('click', '.ne-mlp-close-modal, .ne-mlp-modal-overlay', function(e) {
            e.preventDefault();
            closeAllModals();
        });
        
        // Prevent modal from closing when clicking inside modal content
        $(document).on('click', '.ne-mlp-request-order-modal .ne-mlp-modal-content', function(e) {
            e.stopPropagation();
        });
        
        // Toggle custom days input
        $(document).on('change', 'select[name="days"]', function() {
            var $container = $(this).closest('.ne-mlp-form-group').find('#custom-days-container');
            if ($(this).val() === 'custom') {
                $container.slideDown();
                $container.find('input').prop('required', true);
            } else {
                $container.slideUp();
                $container.find('input').prop('required', false);
            }
        });
        
        // Handle form submission
        $(document).on('submit', '.ne-mlp-request-order-form', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var $buttonText = $submitBtn.find('.button-text');
            var $spinner = $submitBtn.find('.loading-spinner');
            var $daysSelect = $form.find('select[name="days"]');
            
            // Show loading state
            $buttonText.text(neMlpFrontend.processing);
            $spinner.removeClass('hidden').show();
            $submitBtn.prop('disabled', true);
            
            // Prepare form data
            var formData = new FormData($form[0]);
            
            // Add action and nonce
            formData.append('action', 'ne_mlp_submit_order_request');
            formData.append('nonce', neMlpFrontend.nonce);
            
            // Submit form via AJAX
            $.ajax({
                url: neMlpFrontend.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        // Show success message in the new modal
                        const formattedId = (response.data && response.data.formatted_id) ? response.data.formatted_id : '';
                        
                        // Show success message with backend-formatted request ID
                        showMessage(
                            neMlpFrontend.success,
                            `Your order request has been submitted successfully!<br><br>
                            <strong>Request ID: ${formattedId}</strong>`, 
                            'success',
                            function() {
                                // Close all modals and refresh the page
                                closeAllModals();
                                if (response.data.redirect) {
                                    window.location.href = response.data.redirect;
                                } else {
                                    window.location.reload();
                                }
                            }
                        );
                    } else {
                        // Show error message in the new modal
                        const errorMessage = response.data || neMlpFrontend.errorProcessing;
                        showMessage(neMlpFrontend.error, errorMessage, 'error');
                        
                        // Re-enable the submit button
                        resetForm($form, $submitBtn, $buttonText, $spinner);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    showMessage(
                        neMlpFrontend.error, 
                        neMlpFrontend.ajaxError || 'An error occurred while processing your request. Please try again.',
                        'error'
                    );
                    resetForm($form, $submitBtn, $buttonText, $spinner);
                }
            });
        });
    }
    
    // Helper function to close all modals
    function closeAllModals() {
        $('.ne-mlp-request-order-modal, #ne-mlp-order-requests-modal').removeClass('show').css({
            'display': 'none',
            'opacity': '0',
            'visibility': 'hidden'
        }).removeClass('active');
        $('body').removeClass('ne-mlp-modal-open');
    }
    
    // Helper function to reset form state
    function resetForm($form, $submitBtn, $buttonText, $spinner) {
        $buttonText.text($submitBtn.data('original-text') || neMlpFrontend.submit);
        $spinner.addClass('hidden').hide();
        $submitBtn.prop('disabled', false);
    }
    
    // Show message using the global showMessage function
    function showMessage(title, message, type = 'success', onClose = null) {
        if (typeof window.showMessage === 'function') {
            window.showMessage(title, message, type, onClose);
        } else {
            // Fallback to alert if showMessage is not available
            alert(`${title}: ${message}`);
        }
    }
    
    // View Requests Modal: open, fetch, render, paginate
    function initViewRequestsModal() {
        const $modal = $('#ne-mlp-order-requests-modal');
        const $tbody = $modal.find('.ne-mlp-requests-tbody');
        const $loading = $modal.find('.ne-mlp-loading');
        const $empty = $modal.find('.ne-mlp-empty-state');
        const $list = $modal.find('.ne-mlp-requests-list');
        const $prev = $modal.find('.ne-mlp-prev-page');
        const $next = $modal.find('.ne-mlp-next-page');
        const $pageInfo = $modal.find('.ne-mlp-page-info');

        let currentPage = 1;
        const perPage = 10;

        function openModal() {
            $modal.css({ display: 'flex' }).addClass('show');
            $('body').addClass('ne-mlp-modal-open');
        }

        function renderRows(items) {
            $tbody.empty();
            items.forEach(function(item) {
                const status = (item.status || 'pending').toLowerCase();
                const statusClass = 'status-' + status;

                // Format date to show in local time
                let dateStr = item.created_at || '';
                try {
                    // Create date object from ISO string and adjust to local time
                    const d = new Date(dateStr);
                    if (!isNaN(d.getTime())) {
                        // Get local timezone offset in minutes and convert to milliseconds
                        const tzOffset = d.getTimezoneOffset() * 60000;
                        // Apply the timezone offset to get correct local time
                        const localTime = new Date(d.getTime() - tzOffset);
                        
                        // Format as DD/MM/YYYY, hh:mm am/pm
                        const day = String(localTime.getDate()).padStart(2, '0');
                        const month = String(localTime.getMonth() + 1).padStart(2, '0');
                        const year = localTime.getFullYear();
                        let hours = localTime.getHours();
                        const ampm = hours >= 12 ? 'pm' : 'am';
                        hours = hours % 12;
                        hours = hours ? hours : 12; // Convert 0 to 12
                        const minutes = String(localTime.getMinutes()).padStart(2, '0');
                        
                        dateStr = `${day}/${month}/${year}, ${hours}:${minutes} ${ampm}`;
                    }
                } catch (e) {
                    console.error('Date formatting error:', e);
                }

                // Build actions column
                let actionsHtml = '<span class="ne-mlp-badge ne-mlp-badge-muted">Awaiting Order</span>';
                if (item.order_id) {
                    const orderUrl = (window.neMlpFrontend && neMlpFrontend.view_order_base)
                        ? (neMlpFrontend.view_order_base + String(item.order_id) + '/')
                        : '#';
                    actionsHtml = `<a href="${orderUrl}" class="ne-mlp-btn ne-mlp-btn-purple">View Order #${item.order_id}</a>`;
                }

                // Format prescription ID as Med-DD-MM-YY-ID using local time
                let prescriptionIdDisplay = 'N/A';
                if (item.prescription_id || (item.prescription_ids && item.prescription_ids.length > 0)) {
                    const prescId = item.prescription_id || item.prescription_ids[0];
                    try {
                        const d = new Date(item.created_at || new Date().toISOString());
                        if (!isNaN(d.getTime())) {
                            // Adjust for timezone offset
                            const tzOffset = d.getTimezoneOffset() * 60000;
                            const localTime = new Date(d.getTime() - tzOffset);
                            
                            // Get local date components in DD-MM-YY format
                            const dd = String(localTime.getDate()).padStart(2, '0');
                            const mm = String(localTime.getMonth() + 1).padStart(2, '0');
                            const yy = String(localTime.getFullYear()).slice(-2);
                            
                            prescriptionIdDisplay = `Med-${dd}-${mm}-${yy}-${prescId}`;
                        } else {
                            // Fallback to current date if created_at is invalid
                            const now = new Date();
                            const tzOffset = now.getTimezoneOffset() * 60000;
                            const localNow = new Date(now.getTime() - tzOffset);
                            const dd = String(localNow.getDate()).padStart(2, '0');
                            const mm = String(localNow.getMonth() + 1).padStart(2, '0');
                            const yy = String(localNow.getFullYear()).slice(-2);
                            prescriptionIdDisplay = `Med-${dd}-${mm}-${yy}-${prescId}`;
                        }
                    } catch (e) {
                        console.error('Prescription ID formatting error:', e);
                        // Last resort fallback with just ID
                        prescriptionIdDisplay = `Med-${prescId}`;
                    }
                }

                const row = `
                    <tr class="ne-mlp-request-row">
                        <td>
                            <div class="ne-mlp-id">${item.formatted_id || ('#' + item.id)}</div>
                        </td>
                        <td>
                            <div class="ne-mlp-id">${prescriptionIdDisplay}</div>
                        </td>
                        <td>${dateStr}</td>
                        <td><span class="ne-mlp-request-status ${statusClass}">${status.toUpperCase()}</span></td>
                        <td>${item.days || ''}</td>
                        <td style="text-align:right;">${actionsHtml}</td>
                    </tr>`;
                $tbody.append(row);
            });
        }

        function setStateLoading(isLoading) {
            $loading.toggle(isLoading);
            $empty.hide();
            $list.toggle(!isLoading);
        }

        function loadRequests(page) {
            setStateLoading(true);
            $.ajax({
                url: neMlpFrontend.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ne_mlp_get_user_order_requests',
                    nonce: neMlpFrontend.nonce,
                    page: page,
                    per_page: perPage
                }
            }).done(function(res){
                if (!res || !res.success) {
                    $empty.show().find('p').text(neMlpFrontend.error_occurred || 'Error');
                    $list.hide();
                    return;
                }
                const data = res.data || {};
                const items = data.requests || [];
                if (!items.length) {
                    $empty.show();
                    $list.hide();
                } else {
                    renderRows(items);
                    $list.show();
                    $empty.hide();
                }
                currentPage = data.current_page || page;
                const totalPages = data.total_pages || 1;
                $pageInfo.text(`Page ${currentPage} of ${totalPages}`);
                $prev.prop('disabled', currentPage <= 1);
                $next.prop('disabled', currentPage >= totalPages);
            }).fail(function(){
                $empty.show().find('p').text(neMlpFrontend.error_occurred || 'Error');
                $list.hide();
            }).always(function(){
                setStateLoading(false);
            });
        }

        // Open modal and load first page
        $(document).on('click', '#ne-mlp-view-requests-btn', function(e){
            e.preventDefault();
            openModal();
            loadRequests(1);
        });

        // Background click to close (outside content)
        $(document).on('click', '#ne-mlp-order-requests-modal', function(e){
            if ($(e.target).closest('.ne-mlp-modal-content').length === 0) {
                closeAllModals();
            }
        });

        // Pagination
        $prev.on('click', function(){ if (currentPage > 1) loadRequests(currentPage - 1); });
        $next.on('click', function(){ loadRequests(currentPage + 1); });
    }
    
    // Initialize the modal functionality
    initOrderModal();
    initViewRequestsModal();
});
