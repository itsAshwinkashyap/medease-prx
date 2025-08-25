jQuery(document).ready(function($) {
    // Handle prescription approval
    $(document).on('click', '.ne-mlp-approve', function(e) {
        e.preventDefault();
        var prescId = $(this).data('presc');
        if (confirm('Are you sure you want to approve this prescription?')) {
            $.post(ajaxurl, {
                action: 'ne_mlp_admin_approve_presc',
                presc_id: prescId,
                nonce: neMLP.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
    });

    // Handle prescription rejection
    $(document).on('click', '.ne-mlp-reject', function(e) {
        e.preventDefault();
        var prescId = $(this).data('presc');
        if (confirm('Are you sure you want to reject this prescription?')) {
            $.post(ajaxurl, {
                action: 'ne_mlp_admin_reject_presc',
                presc_id: prescId,
                nonce: neMLP.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
    });

    // Handle admin download button
    $(document).on('click', '.ne-mlp-download-all', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var prescId = $btn.data('presc');
        
        if (!$btn.data('processing')) {
            $btn.data('processing', true)
                .prop('disabled', true)
                .html('<span class="dashicons dashicons-download"></span>Downloading...');
            
            // Create a temporary anchor element for download
            var downloadUrl = ajaxurl + '?action=ne_mlp_admin_download_prescription&presc_id=' + prescId + '&nonce=' + neMLP.download_nonce + '&download=1';
            
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

    // Handle prescription deletion
    $(document).on('click', '.ne-mlp-delete', function(e) {
        e.preventDefault();
        var prescId = $(this).data('presc');
        if (confirm('Are you sure you want to delete this prescription? This action cannot be undone.')) {
            $.post(ajaxurl, {
                action: 'ne_mlp_admin_delete_presc',
                presc_id: prescId,
                nonce: neMLP.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
    });

    // Initialize Select2 for user search (backward compatibility)
    if ($('#user_search').length) {
        $('#user_search').select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        search: params.term,
                        action: 'ne_mlp_search_users',
                        nonce: neMLP.nonce
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            minimumInputLength: 2,
            placeholder: 'Search for a user...'
        });
    }

    // Handle file preview in upload forms (single event binding with proper cleanup)
    function handleFilePreview(fileInput) {
        var files = fileInput.files;
        var $fileInput = $(fileInput);
        var previewContainer = $fileInput.siblings('.ne-mlp-upload-preview');
        
        if (previewContainer.length === 0) {
            previewContainer = $('<div class="ne-mlp-upload-preview"></div>');
            $fileInput.after(previewContainer);
        }
        
        previewContainer.empty();
        
        if (files.length > 0) {
            for (var i = 0; i < files.length && i < 4; i++) {
                var file = files[i];
                var fileType = file.type;
                
                if (fileType.startsWith('image/')) {
                    // Create image preview
                    var fileReader = new FileReader();
                    fileReader.onload = (function(index) {
                        return function(e) {
                            previewContainer.append(
                                '<div class="ne-mlp-file-thumb" data-index="' + index + '">' +
                                '<img src="' + e.target.result + '" style="max-width:100px;max-height:100px;" />' +
                                '</div>'
                            );
                        };
                    })(i);
                    fileReader.readAsDataURL(file);
                } else if (fileType === 'application/pdf') {
                    // Show PDF icon
                    previewContainer.append(
                        '<div class="ne-mlp-file-thumb" data-index="' + i + '">' +
                        '<span style="font-size: 32px;">📄</span>' +
                        '</div>'
                    );
                }
            }
        }
    }

    // Bind file change event only once
    $(document).off('change.ne-mlp-file').on('change.ne-mlp-file', 'input[type="file"]', function() {
        handleFilePreview(this);
    });

    // Handle single file download from popup
    $(document).on('click', '.ne-mlp-download-single', function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });

    // Handle search user on Assign Order page
    if ($('#ne-mlp-assign-user-search').length) {
        $('#ne-mlp-assign-user-search').autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: ajaxurl,
                    dataType: 'json',
                    data: {
                        action: 'ne_mlp_user_autocomplete',
                        term: request.term,
                        nonce: neMLP.download_nonce
                    },
                    success: function(data) {
                        response($.map(data, function(item) {
                            return {
                                label: item.label,
                                value: item.id
                            };
                        }));
                    }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                $('#ne_mlp_assign_user').val(ui.item.value).trigger('change');
                $('#ne-mlp-assign-user-search').val(ui.item.label);
                return false;
            }
        });

        // When user is selected, load orders and prescriptions
        $('#ne_mlp_assign_user').on('change', function() {
            var userId = $(this).val();
            var $orderSelect = $('#ne_mlp_assign_order');
            var $prescSelect = $('#ne_mlp_assign_presc');
            var $submitButton = $('#ne_mlp_assign_submit');

            if (userId) {
                $('#ne-mlp-assign-order-row, #ne-mlp-assign-presc-row').show();
                $orderSelect.prop('disabled', true).html('<option>Loading orders...</option>');
                $prescSelect.prop('disabled', true).html('<option>Loading prescriptions...</option>');
                $submitButton.prop('disabled', true);

                // Fetch orders
                $.post(ajaxurl, {
                    action: 'ne_mlp_get_user_orders',
                    user_id: userId,
                    nonce: neMLP.nonce
                }, function(resp) {
                    if (resp.success && resp.data.length) {
                        var opts = '<option value="">Select an order</option>';
                        $.each(resp.data, function(i, order) {
                            opts += '<option value="' + order.value + '">' + order.label + '</option>';
                        });
                        $orderSelect.html(opts).prop('disabled', false);
                    } else {
                        $orderSelect.html('<option>No orders requiring prescriptions found</option>');
                    }
                });

                // Fetch prescriptions
                $.post(ajaxurl, {
                    action: 'ne_mlp_get_user_prescriptions',
                    user_id: userId,
                    nonce: neMLP.nonce
                }, function(resp) {
                    if (resp.success && resp.data.length) {
                        var opts = '<option value="">Select a prescription</option>';
                        $.each(resp.data, function(i, presc) {
                            opts += '<option value="' + presc.value + '">' + presc.label + '</option>';
                        });
                        $prescSelect.html(opts).prop('disabled', false);
                    } else {
                        $prescSelect.html('<option>No available prescriptions found</option>');
                    }
                });

            } else {
                $('#ne-mlp-assign-order-row, #ne-mlp-assign-presc-row').hide();
                $orderSelect.prop('disabled', true).html('<option>Select user first</option>');
                $prescSelect.prop('disabled', true).html('<option>Select user first</option>');
                $submitButton.prop('disabled', true);
            }
        });

        // Enable submit button when both fields are selected
        $('#ne_mlp_assign_order, #ne_mlp_assign_presc').on('change', function() {
            var orderId = $('#ne_mlp_assign_order').val();
            var prescId = $('#ne_mlp_assign_presc').val();
            $('#ne_mlp_assign_submit').prop('disabled', !(orderId && prescId));
        });
    }

    // Handle toggle switches for attach mode
    $(document).on('change', 'input[name="ne_mlp_attach_mode"]', function() {
        var mode = $(this).val();
        var uploadSection = $('.ne-mlp-upload-section');
        
        if (mode === 'later') {
            uploadSection.slideUp();
        } else {
            uploadSection.slideDown();
        }
    });

    // Handle mutual exclusion between file upload and prescription select
    $(document).on('change', '.ne-mlp-prescription-files', function() {
        var hasFiles = this.files && this.files.length > 0;
        var prescSelect = $('select[name="ne_mlp_prescription_select"]');
        
        if (hasFiles) {
            prescSelect.prop('disabled', true).val('');
        } else {
            prescSelect.prop('disabled', false);
        }
    });

    $(document).on('change', 'select[name="ne_mlp_prescription_select"]', function() {
        var hasSelection = $(this).val() !== '';
        var fileInput = $('.ne-mlp-prescription-files');
        
        if (hasSelection) {
            fileInput.prop('disabled', true).val('');
            $('.ne-mlp-upload-preview').empty();
        } else {
            fileInput.prop('disabled', false);
        }
    });

    // Refresh notification badge every 30 seconds
    if ($('.ne-mlp-notification-badge').length) {
        setInterval(function() {
            $.post(ajaxurl, {
                action: 'ne_mlp_get_notification_count',
                nonce: neMLP.nonce
            }, function(response) {
                if (response.success) {
                    var count = response.data.count;
                    var $badge = $('.ne-mlp-notification-badge');
                    
                    if (count > 0) {
                        $badge.text(count).show();
                        if (count > 9) {
                            $badge.text('9+');
                        }
                    } else {
                        $badge.hide();
                    }
                }
            });
        }, 30000); // 30 seconds
    }

    // ===============================
    // MANUAL UPLOAD FUNCTIONALITY
    // ===============================
    
    // File upload with preview (no step showing/hiding)
    $('#prescription-files').on('change', function() {
        var files = this.files;
        var $preview = $('#file-preview-container');
        
        $preview.empty();
        
        if (files.length > 0) {
            // Validate file count
            if (files.length > 4) {
                alert('Maximum 4 files allowed. Only the first 4 files will be processed.');
            }
            
            // Process files (max 4)
            var filesToProcess = Math.min(files.length, 4);
            var validFiles = 0;
            
            for (var i = 0; i < filesToProcess; i++) {
                var file = files[i];
                var fileExt = file.name.split('.').pop().toLowerCase();
                var isValidType = ['jpg', 'jpeg', 'png', 'pdf'].includes(fileExt);
                var isValidSize = file.size <= 5 * 1024 * 1024; // 5MB
                
                if (!isValidType) {
                    alert('Invalid file type: ' + file.name + '. Only JPG, PNG, and PDF files are allowed.');
                    continue;
                }
                
                if (!isValidSize) {
                    alert('File too large: ' + file.name + '. Maximum size is 5MB.');
                    continue;
                }
                
                validFiles++;
                
                // Create preview item
                var $previewItem = $('<div class="file-preview-item" style="position: relative; width: 80px; height: 80px; border: 1px solid #ddd; border-radius: 4px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #f9f9f9;">');
                
                if (fileExt === 'pdf') {
                    $previewItem.html('<span style="font-size: 24px; color: #d63384;">📄</span>');
                } else {
                    // Image preview
                    var reader = new FileReader();
                    reader.onload = (function(item) {
                        return function(e) {
                            item.html('<img src="' + e.target.result + '" style="width: 100%; height: 100%; object-fit: cover;">');
                        };
                    })($previewItem);
                    reader.readAsDataURL(file);
                }
                
                // Add file name tooltip
                $previewItem.attr('title', file.name);
                
                $preview.append($previewItem);
            }
        }
    });
    
    // Form submission - show loading state (validation is handled by button enable/disable)
    $('#ne-mlp-manual-upload-form').on('submit', function(e) {
        // Show loading state
        var $submitBtn = $('#ne-mlp-submit-btn');
        $submitBtn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Uploading...');
        $('#submit-requirements').text('Uploading prescription...').css('color', '#d48806');
        
        return true;
    });
}); 