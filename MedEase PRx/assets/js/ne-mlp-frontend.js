jQuery(function($){
    // Handle redirect to order requests page with specific request
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('ne_mlp_view_order_request')) {
        // Remove the parameter to avoid infinite redirects
        var newUrl = window.location.pathname;
        if (window.location.search) {
            newUrl = window.location.pathname + window.location.search
                .replace(/[?&]ne_mlp_view_order_request=[^&]*/, '')
                .replace(/^&/, '?')
                .replace(/[?&]$/, '');
        }
        window.history.replaceState({}, document.title, newUrl);
        
        // Scroll to the specific request if possible
        var requestId = urlParams.get('ne_mlp_view_order_request');
        if (requestId) {
            var $request = $('.ne-mlp-order-request[data-request-id="' + requestId + '"]');
            if ($request.length) {
                $('html, body').animate({
                    scrollTop: $request.offset().top - 100
                }, 500);
                $request.addClass('highlight-request');
                setTimeout(function() {
                    $request.removeClass('highlight-request');
                }, 3000);
            }
        }
    }
                        
    // Back button - force full reload
    $(document).on('click', '#ne-mlp-presc-back-btn', function(e){
        e.preventDefault();
        e.stopPropagation();
        window.location.replace(wc_get_account_endpoint_url('my-prescription'));
    });
    // Reorder button
    $(document).on('click', '.ne-mlp-reorder-btn', function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var prescId = $btn.data('id');
        
        if (!$btn.data('processing')) {
            $btn.data('processing', true)
                .prop('disabled', true)
                .html('<span>⏳</span>Reordering...');
            
            $.ajax({
                url: neMLP.ajax_url,
                type: 'POST',
                data: {
                    action: 'ne_mlp_reorder_prescription',
                    presc_id: prescId,
                    nonce: neMLP.nonce
                },
                success: function(resp) {
                    if (resp.success && resp.data) {
                        if (resp.data.out_of_stock && resp.data.out_of_stock.length) {
                            alert('Some items are out of stock and were not added to your cart:\n' + resp.data.out_of_stock.join('\n') + '\n\nYou can remove them from the cart to proceed.');
                        }
                        if (resp.data.redirect) {
                            // Add a small delay before redirect to ensure session is saved
                            setTimeout(function() {
                                window.location.href = resp.data.redirect;
                            }, 500);
                        }
                    } else {
                        alert(resp.data && resp.data.message ? resp.data.message : 'Reorder failed. Please try again.');
                        $btn.data('processing', false)
                            .prop('disabled', false)
                            .html('<span>🔄</span>Reorder');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Reorder error:', error);
                    alert('Reorder failed. Please try again later.');
                    $btn.data('processing', false)
                        .prop('disabled', false)
                        .html('<span>🔄</span>Reorder');
                }
            });
        }
    });

    // --- Enhanced prescription upload live preview and multi-select ---
    function getFormBuffer($form) {
        if (!$form.data('neMLPFiles')) $form.data('neMLPFiles', []);
        if (!$form.data('neMLPInput')) $form.data('neMLPInput', null);
        return {
            files: $form.data('neMLPFiles'),
            setFiles: function(arr){ $form.data('neMLPFiles', arr); },
            input: $form.data('neMLPInput'),
            setInput: function(inp){ $form.data('neMLPInput', inp); }
        };
    }
    // Attach to ALL upload forms
    $(document).on('change', '.ne-mlp-prescription-files', function(e){
        var $form = $(this).closest('form');
        var buffer = getFormBuffer($form);
        buffer.setInput(this);
        var files = Array.from(this.files);
        var $preview = $(this).closest('.ne-mlp-upload-box, .ne-mlp-dedicated-upload-page, .ne-mlp-upload-container').find('.ne-mlp-upload-preview');
        var validTypes = ['image/jpeg','image/png','application/pdf'];
        var currentFiles = buffer.files;
        
        if (currentFiles.length + files.length > 4) {
            alert('You can select a maximum of 4 files.');
            this.value = '';
            return;
        }
        
        for(var i=0; i<files.length; i++){
            var file = files[i];
            
            // Check for duplicate files by name and size
            var isDuplicate = false;
            for(var j=0; j<currentFiles.length; j++){
                if(currentFiles[j].name === file.name && currentFiles[j].size === file.size){
                    isDuplicate = true;
                    break;
                }
            }
            
            if(isDuplicate){
                alert('File "' + file.name + '" is already selected. Please choose a different file.');
                this.value = '';
                return;
            }
            
            if(validTypes.indexOf(file.type) === -1){
                alert('Invalid file type: ' + file.name);
                this.value = '';
                return;
            }
            if(file.size > 5*1024*1024){
                alert('File too large: ' + file.name);
                this.value = '';
                return;
            }
            currentFiles.push(file);
        }
        buffer.setFiles(currentFiles);
        this.value = '';
        renderPreview($preview, buffer, $form);
        // Update upload area text after files selected
        updateUploadAreaText($form, currentFiles.length);
        // Mutual exclusion: disable dropdown if files selected
        $form.find('select[name*=prescription_select], select[name=ne_mlp_select_prev_presc]').prop('disabled', currentFiles.length > 0);
    });
    // Mutual exclusion: disable file input if dropdown selected
    $(document).on('change', 'select[name*=prescription_select], select[name=ne_mlp_select_prev_presc]', function(){
        var $form = $(this).closest('form');
        var buffer = getFormBuffer($form);
        var hasVal = !!$(this).val();
        $form.find('.ne-mlp-prescription-files').prop('disabled', hasVal);
        if(hasVal){
            buffer.setFiles([]);
            renderPreview($form.find('.ne-mlp-upload-preview'), buffer, $form);
        }
    });
    function updateUploadAreaText($form, fileCount) {
        var $uploadText = $form.find('.ne-mlp-upload-text');
        var $uploadHint = $form.find('.ne-mlp-upload-hint');
        if (fileCount > 0) {
            $uploadText.html('<strong>' + fileCount + ' file(s) selected</strong>');
            $uploadHint.text('Click to add more files');
        } else {
            $uploadText.html('<strong>UPLOAD NEW</strong>');
            $uploadHint.text('Click to browse files');
        }
    }

    function renderPreview($preview, buffer, $form){
        $preview.empty();
        var files = buffer.files;
        
        if (files.length > 0) {
            $preview.append('<div style="margin:16px 0 8px 0;font-weight:600;color:#333;font-size:14px;">Selected Files:</div>');
            var $fileGrid = $('<div style="display:flex;flex-wrap:wrap;gap:12px;"></div>');
            
            for(var i=0; i<files.length; i++){
                (function(idx, file){
                    var $item = $('<div class="ne-mlp-preview-item" style="display:flex;flex-direction:column;align-items:center;position:relative;background:#fff;border:1px solid #e1e5e9;border-radius:8px;padding:8px;min-width:100px;max-width:120px;transition:all 0.2s;">');
                    var $remove = $('<button type="button" aria-label="Remove file" class="ne-mlp-remove-file" style="position:absolute;top:-8px;right:-8px;width:20px;height:20px;background:#ff4d4f;border:none;color:#fff;border-radius:50%;font-size:12px;font-weight:bold;cursor:pointer;z-index:2;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.15);"><span style="pointer-events:none;">&times;</span></button>');
                    $remove.on('click', function(){
                        files.splice(idx,1);
                        buffer.setFiles(files);
                        renderPreview($preview, buffer, $form);
                        updateUploadAreaText($form, files.length);
                        // Re-enable dropdown if no files left
                        if(files.length === 0){
                            $form.find('select[name*=prescription_select], select[name=ne_mlp_select_prev_presc]').prop('disabled', false);
                        }
                    });
                    $item.append($remove);
                    
                    if(file.type === 'application/pdf'){
                        // PDF files - show PDF icon only
                        $item.append('<div style="font-size:32px;color:#ff7875;margin-bottom:6px;">📄</div>');
                    } else {
                        // Image files - show thumbnail only
                        var reader = new FileReader();
                        reader.onload = function(e){
                            $item.append('<img src="'+e.target.result+'" style="width:60px;height:60px;object-fit:cover;border-radius:6px;margin-bottom:6px;border:1px solid #ddd;" alt="File preview" />');
                        };
                        reader.readAsDataURL(file);
                    }
                    
                    // Removed filename display - only show thumbnails
                    
                    $fileGrid.append($item);
                })(i, files[i]);
            }
            $preview.append($fileGrid);
        }
        
        // Update the input's files property
        if(buffer.input){
            var dt = new DataTransfer();
            for(var i=0;i<files.length;i++) dt.items.add(files[i]);
            buffer.input.files = dt.files;
        }
    }
    // On form submit, always re-assign files to input
    $(document).on('submit', 'form.checkout, form.cart, .ne-mlp-upload-form, form[enctype="multipart/form-data"]', function(){
        var buffer = getFormBuffer($(this));
        if(buffer.input){
            var dt = new DataTransfer();
            for(var i=0;i<buffer.files.length;i++) dt.items.add(buffer.files[i]);
            buffer.input.files = dt.files;
        }
        // Reset after submit
        $(this).removeData('neMLPFiles').removeData('neMLPInput');
    });

    // Toggle attach now/later
    $(document).on('change', 'input[name="ne_mlp_attach_mode"]', function(){
        var mode = $('input[name="ne_mlp_attach_mode"]:checked').val();
        var $section = $(this).closest('.ne-mlp-upload-box').find('.ne-mlp-upload-section');
        if(mode === 'now'){
            $section.slideDown(150);
        }else{
            $section.slideUp(150);
        }
    });

    // Delete prescription (My Prescriptions page) - Updated for new button classes
    $(document).on('click', '.ne-mlp-delete-btn', function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var prescId = $btn.data('id'); // Updated data attribute
        
        if(!confirm('Are you sure you want to delete this prescription?')) return;
        
        if (!$btn.data('processing')) {
            $btn.data('processing', true)
                .prop('disabled', true)
                .html('<span>⏳</span>Deleting...');
            
            $.post(neMLP.ajax_url, {
                action: 'ne_mlp_delete_prescription',
                presc_id: prescId,
                nonce: neMLP.nonce
            }, function(res){
                if(res.success){
                    // Show success message and fade out card
                    $btn.closest('.ne-mlp-prescription-card').fadeOut(300, function(){
                        $(this).remove();
                        // Check if no cards left
                        if($('.ne-mlp-prescription-card').length === 0) {
                            location.reload(); // Reload to show "no prescriptions" message
                        }
                    });
                } else {
                    alert(res.data || 'Failed to delete prescription');
                    $btn.data('processing', false)
                        .prop('disabled', false)
                        .html('<span>🗑️</span>Delete');
                }
            }).fail(function(){
                alert('Network error. Please try again.');
                $btn.data('processing', false)
                    .prop('disabled', false)
                    .html('<span>🗑️</span>Delete');
            });
        }
    });

    // --- Admin AJAX for View All modal ---
    if (typeof neMLPAdmin !== 'undefined') {
        $(document).on('click', '#ne-mlp-viewall-modal .button[data-action]', function(e){
            e.preventDefault();
            var $btn = $(this);
            var $card = $btn.closest('div[style*="background:#fafbfc"]');
            var prescId = $btn.data('presc');
            var action = $btn.data('action');
            var nonce = neMLPAdmin.nonce;
            $btn.prop('disabled', true).css('opacity',0.6);
            $.post(ajaxurl, {
                action: 'ne_mlp_admin_' + action + '_presc',
                presc_id: prescId,
                nonce: nonce
            }, function(res){
                $btn.prop('disabled', false).css('opacity',1);
                if(res.success){
                    if(action==='delete'){
                        $card.fadeOut(200,function(){ $(this).remove(); });
                    }else if(action==='approve'){
                        $card.find('.ne-mlp-status-pending').removeClass('ne-mlp-status-pending').addClass('ne-mlp-status-approved').text('Approved');
                    }else if(action==='reject'){
                        $card.find('.ne-mlp-status-pending').removeClass('ne-mlp-status-pending').addClass('ne-mlp-status-rejected').text('Rejected');
                    }
                }else{
                    alert(res.data||'Error');
                }
            });
        });
        // Tooltips for action buttons
        $(document).on('mouseenter', '#ne-mlp-viewall-modal .button[title]', function(){
            var $btn = $(this);
            if($btn.find('.ne-mlp-tooltip').length) return;
            var tip = $('<span class="ne-mlp-tooltip" style="position:absolute;bottom:110%;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:3px 10px;border-radius:6px;font-size:13px;white-space:nowrap;z-index:10;">'+$btn.attr('title')+'</span>');
            $btn.append(tip);
        });
        $(document).on('mouseleave', '#ne-mlp-viewall-modal .button[title]', function(){ $(this).find('.ne-mlp-tooltip').remove(); });
    }

    // Download button - Updated for new button classes
    $(document).on('click', '.ne-mlp-download-btn', function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var prescId = $btn.data('id') || $btn.data('presc'); // Support both for backward compatibility
        
        if (!$btn.data('processing')) {
            $btn.data('processing', true)
                .prop('disabled', true)
                .html('<span class="dashicons dashicons-download"></span>Downloading...');
            
            // Create a temporary anchor element for download
            var downloadUrl = neMLP.ajax_url + '?action=ne_mlp_download_prescription&presc_id=' + prescId + '&nonce=' + neMLP.nonce + '&download=1';
            
            // Create a temporary anchor element
            var a = document.createElement('a');
            a.href = downloadUrl;
            a.download = 'prescription_' + prescId + '.pdf'; // Set a default filename
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            // Reset button after a short delay
            setTimeout(function() {
                $btn.data('processing', false)
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-download"></span>Download');
            }, 1000);
        }
    });

    // Enhanced handlers for new prescription card buttons
    
    // Delete prescription with new data attribute and styling
    $(document).on('click', '.ne-mlp-delete-btn[data-id]', function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var prescId = $btn.data('id');
        
        if(!confirm('Are you sure you want to delete this prescription?')) return;
        
        if (!$btn.data('processing')) {
            $btn.data('processing', true)
                .prop('disabled', true)
                .html('<span>⏳</span>Deleting...');
            
            $.post(neMLP.ajax_url, {
                action: 'ne_mlp_delete_prescription',
                presc_id: prescId,
                nonce: neMLP.nonce
            }, function(res){
                if(res.success){
                    // Show success message and fade out card
                    $btn.closest('.ne-mlp-prescription-card').fadeOut(300, function(){
                        $(this).remove();
                        // Check if no cards left
                        if($('.ne-mlp-prescription-card').length === 0) {
                            location.reload(); // Reload to show "no prescriptions" message
                        }
                    });
                } else {
                    alert(res.data || 'Failed to delete prescription');
                    $btn.data('processing', false)
                        .prop('disabled', false)
                        .html('<span>🗑️</span>Delete');
                }
            }).fail(function(){
                alert('Network error. Please try again.');
                $btn.data('processing', false)
                    .prop('disabled', false)
                    .html('<span>🗑️</span>Delete');
            });
        }
    });

    // Download prescription with new data attribute and styling
    $(document).on('click', '.ne-mlp-download-btn[data-id]', function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var prescId = $btn.data('id');
        
        if (!$btn.data('processing')) {
            $btn.data('processing', true)
                .prop('disabled', true)
                .html('<span>⏳</span>Downloading...');
            
            // Create a temporary anchor element for download
            var downloadUrl = neMLP.ajax_url + '?action=ne_mlp_download_prescription&presc_id=' + prescId + '&nonce=' + neMLP.nonce + '&download=1';
            
            // Create a temporary anchor element
            var a = document.createElement('a');
            a.href = downloadUrl;
            a.download = 'prescription_' + prescId + '.pdf'; // Set a default filename
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            // Reset button after a short delay
            setTimeout(function() {
                $btn.data('processing', false)
                    .prop('disabled', false)
                    .html('<span>📥</span>Download');
            }, 1000);
        }
    });

    // Reorder prescription with new data attribute and styling
    $(document).on('click', '.ne-mlp-reorder-btn[data-id]', function(e){
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var prescId = $btn.data('id');
        
        if (!$btn.data('processing')) {
            $btn.data('processing', true)
                .prop('disabled', true)
                .html('<span>⏳</span>Reordering...');
            
            $.ajax({
                url: neMLP.ajax_url,
                type: 'POST',
                data: {
                    action: 'ne_mlp_reorder_prescription',
                    presc_id: prescId,
                    nonce: neMLP.nonce
                },
                success: function(resp) {
                    if (resp.success && resp.data) {
                        if (resp.data.out_of_stock && resp.data.out_of_stock.length) {
                            alert('Some items are out of stock and were not added to your cart:\n' + resp.data.out_of_stock.join('\n') + '\n\nYou can remove them from the cart to proceed.');
                        }
                        if (resp.data.redirect) {
                            // Add a small delay before redirect to ensure session is saved
                            setTimeout(function() {
                                window.location.href = resp.data.redirect;
                            }, 500);
                        }
                    } else {
                        alert(resp.data && resp.data.message ? resp.data.message : 'Reorder failed. Please try again.');
                        $btn.data('processing', false)
                            .prop('disabled', false)
                            .html('<span>🔄</span>Reorder');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Reorder error:', error);
                    alert('Reorder failed. Please try again later.');
                    $btn.data('processing', false)
                        .prop('disabled', false)
                        .html('<span>🔄</span>Reorder');
                }
            });
        }
    });
}); 