jQuery.noConflict();

// (function($) {
//     // API Location and all API data function
    jQuery(document).ready(function($) {
        // Add Location button action
        $('#add_location_button').off('click').on('click', function() {
            addLocation();
        });
        function addLocation() {
            var index = $('.location-row').length;
            var rowNumber = Math.floor(index / 5) + 1;
        
            var html = '<div class="location-row row-' + rowNumber + '">';
            html += '<h3 class="location_name">Location ' + (index + 1) + '</h3>';
            html += '<input type="hidden" name="lic_settings[locations][' + index + '][id]" value="' + index + '" />'; // Unique identifier
            html += '<input type="text" required name="lic_settings[locations][' + index + '][name]" placeholder="Location Name" required /><br />';
            html += '<input type="text" required name="lic_settings[locations][' + index + '][api_url]" placeholder="API URL" required /><br />';
        
            html += '<input type="hidden" name="lic_settings[locations][' + index + '][use_sandbox]" value="0" />';
            html += '<input type="checkbox" name="lic_settings[locations][' + index + '][use_sandbox]" class="use-sandbox-checkbox" value="1" /> Use Sandbox Details<br />';
        
            html += '<div class="sandbox-details" style="display:none">';
            html += '<input type="text" class="sandbox_username" name="lic_settings[locations][' + index + '][api_username_sandbox]" placeholder="Sandbox API Username" /><br />';
            html += '<input type="password" class="sandbox_password" data-has-saved="0" name="lic_settings[locations][' + index + '][api_password_sandbox]" placeholder="Sandbox API Password" /><br />';
            html += '</div>';
        
            html += '<div class="live-details">';
            html += '<input type="text" class="live_username" required name="lic_settings[locations][' + index + '][api_username_live]" placeholder="Live API Username" /><br />';
            html += '<input type="password" class="live_password" data-has-saved="0" name="lic_settings[locations][' + index + '][api_password_live]" placeholder="Live API Password" /><br />';
            html += '</div>';
        
            html += '<button type="button" class="delete_location_button">Delete</button>';
            html += '<button type="button" class="save_location_with_cred_button">Save</button>';
            html += '</div>';
        
            $('#locations_container').append(html);
        }

        /** ****************************** */
        // Delete Non Saved Location 
        $(document).on('click', '.delete_location_button', function() {
            var row = $(this).closest('.location-row');
            row.remove();
            reindexLocations();
        });

        // Delete Non Saved Location Function
        function reindexLocations() {
            $('.location-row').each(function(index) {
                var rowNumber = Math.floor(index / 5) + 1;
                $(this).attr('class', 'location-row row-' + rowNumber);
        
                $(this).find('.location_name').text('Location ' + (index + 1));
                $(this).find('input[type="hidden"][name^="lic_settings[locations]["]').val(index);
                $(this).find('input[name^="lic_settings[locations]["]').each(function() {
                    var name = $(this).attr('name');
                    var newName = name.replace(/lic_settings\[locations\]\[\d+\]/, 'lic_settings[locations][' + index + ']');
                    $(this).attr('name', newName);
                });
            });
        }
        /** ****************************** */

    
        // Toggle sandbox/live details based on checkbox
        $(document).off('change').on('change', '.use-sandbox-checkbox', function() {
            var row = $(this).closest('.location-row');
            var sandboxDetails = row.find('.sandbox-details');
            var liveDetails = row.find('.live-details');

            if ($(this).is(':checked')) {
                sandboxDetails.show();
                liveDetails.hide();

                // Set sandbox fields as required (password fields are validated separately —
                // a blank password is allowed when a credential is already saved)
                sandboxDetails.find('.sandbox_username').prop('required', true);

                // Remove required attribute from live fields
                liveDetails.find('.live_username').prop('required', false);
            } else {
                sandboxDetails.hide();
                liveDetails.show();

                // Remove required attribute from sandbox fields
                sandboxDetails.find('.sandbox_username').prop('required', false);

                // Set live fields as required
                liveDetails.find('.live_username').prop('required', true);
            }
        });

        // Save Location Data
        $(document).on('click', '.save_location_with_cred_button', function() {
            // Disable the save button to prevent multiple clicks
            $('.save_location_with_cred_button').prop('disabled', true);
            
            var row = $(this).closest('.location-row');
            var index = row.index('.location-row');
            var formData = row.find('input, textarea, select').serialize();
        
            // Get the sandbox and live detail elements
            var sandboxChecked = row.find('.use-sandbox-checkbox').is(':checked');
            var sandboxDetails = row.find('.sandbox-details');
            var liveDetails = row.find('.live-details');
        
            // Set the required attribute based on the checkbox (usernames only —
            // password fields are validated separately below)
            if (sandboxChecked) {
                sandboxDetails.find('.sandbox_username').prop('required', true);
                liveDetails.find('.live_username').prop('required', false);
            } else {
                sandboxDetails.find('.sandbox_username').prop('required', false);
                liveDetails.find('.live_username').prop('required', true);
            }

            // Check for empty required fields
            var error = false;
            row.find('input[required]').each(function() {
                if ($(this).val() === '') {
                    error = true;
                    $(this).addClass('error'); // Optionally add a class for styling invalid fields
                } else {
                    $(this).removeClass('error');
                }
            });

            // Password fields are only required when no credential has been saved yet for
            // this location — a blank value on an already-saved location means "keep existing".
            var activePassword = sandboxChecked ? sandboxDetails.find('.sandbox_password') : liveDetails.find('.live_password');
            if (activePassword.val() === '' && activePassword.data('has-saved') != 1) {
                error = true;
                activePassword.addClass('error');
            } else {
                activePassword.removeClass('error');
            }

            if (error) {
                alert('Please fill in all required fields.');
                $('.save_location_with_cred_button').prop('disabled', false);
                return;
            }
        
            // Ajax call to save data
            $.ajax({
                type: 'POST',
                url: lightspeed_connector_ajax.ajaxurl,
                data: {
                    action: 'save_location_data',
                    index: index,
                    formData: formData,
                    nonce: lightspeed_connector_ajax.nonce
                },
                success: function(response) {
                    // Handle success response if needed
                    alert('Location data saved successfully.');
                    location.reload();
                    $('.save_location_with_cred_button').prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    // Handle error response if needed
                    console.error('Error saving location data:', error);
                    $('.save_location_with_cred_button').prop('disabled', false);
                }
            });
        });
        
        // Delete Saved Location button action
        $(document).on('click', '.saved_delete_location_button', function() {
            $('.saved_delete_location_button').prop('disabled',true);
            var row = $(this).closest('.location-row');
            var locationId = row.find('input[name^="lic_settings[locations]"][name$="[id]"]').val();

            // Ajax call to delete data
            $.ajax({
                type: 'POST',
                url: lightspeed_connector_ajax.ajaxurl,
                data: {
                    action: 'delete_location_data',
                    id: locationId,
                    nonce: lightspeed_connector_ajax.nonce
                },
                success: function(response) {
                    $('.saved_delete_location_button').prop('disabled',false);
                    if (response.success) {
                        row.remove(); 
                        alert('Location data deleted successfully.');
                        location.reload();
                    } else {
                        console.error('Error deleting location data:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    // Handle error response if needed
                    console.error('Error deleting location data:', error);
                }
            });
        });

        
        // Call API After selecting Loctaion
        $('#selected_location').off('change').on('change', function() {
            $('#selected_location').prop('disabled',true);
            var selectedLocation = $(this).val();
            if(selectedLocation == 'null'){
                $('.api_fields_container').empty();
                $('#selected_location').prop('disabled',false);
                return false;
            }
            else if(selectedLocation !== 'null') {
                $.ajax({
                    url: lightspeed_connector_ajax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fetch_api_fields',
                        selected_location: selectedLocation,
                        nonce: lightspeed_connector_ajax.nonce,
                    },
                    success: function(response) {
                        $('#selected_location').prop('disabled',false);
                        if (response.success) {
                            $('.api_fields_container').html(response.data.data);

                            $('#spanContainer').html(response.data.spanData);
                            $('.post_title_field').css('margin-bottom','55px');
                            function updateTagsStatus() {
                                var currentInput = $('#permalink-input').val();
                                
                                // Iterate through all tags to enable/disable them based on input content
                                $('.tag').each(function() {
                                    var tag = $(this).data('tag');
                                    
                                    if (currentInput.includes(tag)) {
                                        $(this).addClass('disabled').prop('disabled', true);
                                    } else {
                                        $(this).removeClass('disabled').prop('disabled', false);
                                    }
                                });
                            }

                            $('.tag').click(function() {
                                var tag = $(this).data('tag');
                                var currentInput = $('#permalink-input').val();
                                
                                if (!currentInput.includes(tag)) {
                                    $('#permalink-input').val(currentInput + tag);
                                }
                                
                                updateTagsStatus();  
                            });
                        
                            setTimeout(
                                $('#permalink-input').on('input', function() {
                                    updateTagsStatus();  
                                }), 
                            500);

                           
                            $.ajax({
                                url: lightspeed_connector_ajax.ajaxurl, // WordPress AJAX URL
                                type: 'POST',
                                data: {
                                    action: 'get_mapped_fields',
                                    location: selectedLocation,
                                    nonce: lightspeed_connector_ajax.nonce,
                                },
                                success: function(response) {
                                    if (response.success && response.data) {
                                        function updateTagsStatus() {
                                            var currentInput = $('#permalink-input').val();
                                            
                                            // Iterate through all tags to enable/disable them based on input content
                                            $('.tag').each(function() {
                                                var tag = $(this).data('tag');
                                                
                                                if (currentInput.includes(tag)) {
                                                    $(this).addClass('disabled').prop('disabled', true);
                                                } else {
                                                    $(this).removeClass('disabled').prop('disabled', false);
                                                }
                                            });
                                        }

                                        
                                        setTimeout(updateTagsStatus, 450);

                                        const wcFields = response.data.mapped_fields.WC.api_fields;
                                        const acfFields = response.data.mapped_fields.ACF;

                                        const custom_title = response.data.custom_title;

                                        // For Custom Title
                                        $('.custom-title-select').val(custom_title);

                                        // Populate WC Fields
                                        for (const fieldKey in wcFields) {
                                            const fieldValue = wcFields[fieldKey];
                                            const selectElement = document.querySelector(`select[name="api_fields][${fieldKey}]"]`);
                                            if (selectElement) {
                                                const option = selectElement.querySelector(`option[value="${fieldValue}"]`);
                                                if (option) {
                                                    selectElement.value = fieldValue;
                                                }
                                            }
                                        }
                            
                                        // Populate ACF Fields
                                        for (const groupKey in acfFields) {
                                            // Assuming acfFields has a nested structure with api_fields
                                            const fields = acfFields[groupKey];
                                            
                                            for (const fieldLabel in fields) {
                                                const fieldValue = fields[fieldLabel];
                                                
                                                // Log to verify the values being processed
                                                console.log(`Processing Field: ${fieldLabel} with Value: ${fieldValue}`);
                                                
                                                // Construct the correct selector to target the select element
                                                const selectElement = document.querySelector(`select[data-field_name="${fieldLabel}"]`);
                                                
                                                if (selectElement) {
                                                    // Find the option element matching the value in fieldValue
                                                    const option = selectElement.querySelector(`option[value="${fieldValue}"]`);
                                                    
                                                    if (option) {
                                                        // Set the select's value to the matching option's value
                                                        selectElement.value = fieldValue;
                                                        console.log(`Set value for field ${fieldLabel} to ${fieldValue}`);
                                                    } else {
                                                        console.warn(`Option with value "${fieldValue}" not found for field ${fieldLabel}`);
                                                    }
                                                } else {
                                                    console.warn(`Select element not found for field ${fieldLabel}`);
                                                }
                                            }
                                        }
                                    }
                                },
                                error: function() {
                                    console.log('Error retrieving mapped fields.');
                                }
                            });
                            
                        } else {
                            console.error('Error fetching API fields:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error fetching API fields:', error);
                    }
                });
            } else {
                $('.api_fields_container').empty();
            }
        });
    
        // Pull Data API to woocommerce product
    
        $('#pull_data').off('click').on('click', function() {
            $('#pull_data').prop('disabled',true);
            $('.loader_cls').addClass('loader');
            var temp_data = 'Hi'; // Get the HTML content of ul#temp_data
            var selected_Location = $('#selected_location').val(); // Get the selected location
            
            if (!selected_Location) {
                alert('Select Location');
                $('#pull_data').prop('disabled',false);
                $('.loader_cls').removeClass('loader');
                return;
            }

            // Validate Product Title and SKU fields
            var productTitleSelected = false;
            var skuSelected = false;

            // Check if post_title select box has a selected value other than "- Select -"
            $('select.api-select[name="api_fields][post_title]"]').each(function() {
                var selectedValue = $(this).val();
                if (selectedValue && selectedValue !== '- Select -') {
                    productTitleSelected = true;
                    return false; // Exit the loop early since validation is passed
                }
            });

            // Check if _sku select box has a selected value other than "- Select -"
            $('select.api-select[name="api_fields][_sku]"]').each(function() {
                var selectedValue = $(this).val();
                if (selectedValue && selectedValue !== '- Select -') {
                    skuSelected = true;
                    return false; // Exit the loop early since validation is passed
                }
            });

            // Perform validation
            if (!productTitleSelected || !skuSelected) {
                alert('Please select a value for both Product Title and SKU.');
                $('#pull_data').prop('disabled', false);
                $('.loader_cls').removeClass('loader');
                return;
            }
            var field_data = {
                ACF: {},
                WC: {}
            };
            
            // Loop through ACF fields
            $('.acf-label').each(function() {
                var acfLabelName = $(this).attr('name'); 
                var acfSelectedKey = $(this).data('field_group_key'); 
                var acfSelectedValue = $('.acf-api-select[data-field_group_key="' + acfSelectedKey + '"]').val();
            
                if (!field_data.ACF[acfSelectedKey]) {
                    field_data.ACF[acfSelectedKey] = {};
                }
            
                // Set the value directly to the nested structure
                field_data.ACF[acfSelectedKey][acfLabelName] = acfSelectedValue;
            });
            
            // Loop through WooCommerce (WC) fields
            $('.wc-label').each(function() {
                var wcLabelName = $(this).attr('name'); 
                var wcSelectedValue = $('select.api-select[name="' + wcLabelName + '"]').val();
            
                // Set the value directly to the nested structure
                field_data.WC[wcLabelName] = wcSelectedValue;
            });
            
            $.ajax({
                url: lightspeed_connector_ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pull_data_LIC_WC',
                    temp_data: field_data,
                    selected_location: selected_Location,
                    nonce: lightspeed_connector_ajax.nonce
                },
                success: function(response) {
                    $('#pull_data').prop('disabled',false);
                    $('.loader_cls').removeClass('loader');
                    if (response.success) {
                        alert('Data processed successfully!');                    
                    } else {
                        alert('Error processing data: ' + response.data);
                    }
                },
                error: function(error) {
                    console.error('Error processing data:', error);
                    alert('Error processing data. Please try again.');
                }
            });
        });
    
        // Save API/WooCommerce Fields In DB Based on Location
        $('#save_mapped_fields').off('click').on('click', function() {
            $('#save_mapped_fields').prop('disabled',true);
            var selected_Location = $('#selected_location').val();
            console.log(selected_Location);
            if (selected_Location == 'null') {
                alert('Select Location');
                $('#save_mapped_fields').prop('disabled', false);
                return false;
            }

            // Validate Product Title and SKU fields
            var productTitleSelected = false;
            var skuSelected = false;

            // Check if post_title select box has a selected value other than "- Select -"
            var selectedValue = $('.custom-title-select').val().trim();  // Trim any leading or trailing spaces
            if (!selectedValue) {  // Check if the trimmed value is empty
                alert('Input field is empty or contains only spaces');
                return false;  // Prevent further actions
            } else {
                productTitleSelected = true;
            }

            // Check if _sku select box has a selected value other than "- Select -"
            $('select.api-select[name="api_fields][_sku]"]').each(function() {
                var selectedValue = $(this).val();
                if (selectedValue && selectedValue !== '- Select -') {
                    skuSelected = true;
                    return false;
                }
            });

            // Perform validation
            if (!productTitleSelected || !skuSelected) {
                alert('Please select a value for both Product Title and SKU.');
                $('#save_mapped_fields').prop('disabled', false);
                return;
            }
            var field_data = {
                ACF: {},
                WC: {}
            };
            
            var custom_title = '';

            // Loop through ACF fields
            $('.acf-label').each(function() {
                var fieldLabel       = $(this).data('field_name');
                var acfSelectedValue = $('.acf-api-select[data-field_name="' + fieldLabel + '"]').val();

                if (!field_data.ACF['api_fields']) {
                    field_data.ACF['api_fields'] = {};
                }

                field_data.ACF['api_fields'][fieldLabel] = acfSelectedValue;
            });
            
            // Loop through WooCommerce (WC) fields
            $('.wc-label').each(function() {
                var wcLabelName = $(this).attr('name'); 
                
                var wcSelectedValue = $('select.api-select[name="' + wcLabelName + '"]').val();

                var wcSelectedCustomTitle = $('.custom-title-select').val();
                
                if( wcLabelName == 'api_fields][post_title]'){
                    custom_title = wcSelectedCustomTitle;
                }

                // Set the value directly to the nested structure
                field_data.WC[wcLabelName] = wcSelectedValue;
                
            });
            console.log(custom_title);
            
            $.ajax({
                url: lightspeed_connector_ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'save_mapped_LIC_WC_API_Field',
                    mapped_fields: field_data,
                    selected_location: selected_Location,
                    custom_title: custom_title,
                    nonce: lightspeed_connector_ajax.nonce,
                },
                success: function(response) {
                    $('#save_mapped_fields').prop('disabled',false);
                    if (response.success) {
                        alert('Mapping saved successfully for location: ' + selected_Location);                    
                    } else {
                        alert('Error processing data: ' + response.data);
                    }
                },
                error: function(error) {
                    console.error('Error processing data:', error);
                    alert('Error processing data. Please try again.');
                }
            });
        });
    
        // Custom Title input field functionaltiy
        function updateTagsStatus() {
            var currentInput = $('#permalink-input').val();
            
            // Iterate through all tags to enable/disable them based on input content
            $('.tag').each(function() {
                var tag = $(this).data('tag');
                
                if (currentInput.includes(tag)) {
                    $(this).addClass('disabled').prop('disabled', true);
                } else {
                    $(this).removeClass('disabled').prop('disabled', false);
                }
            });
        }
    
        $('.tag').click(function() {
            var tag = $(this).data('tag');
            var currentInput = $('#permalink-input').val();
            
            if (!currentInput.includes(tag)) {
                $('#permalink-input').val(currentInput + tag);
            }
            
            updateTagsStatus();  
        });
    
        $('#permalink-input').on('input', function() {
            updateTagsStatus();  
        });
    
        $('#my-inventory-button').on('click', function(e) {
            e.preventDefault();
    
            // Disable the button and change its color
            var $button = $(this);
            $button.addClass('disabled');
            
            $.ajax({
                url: lightspeed_connector_ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pull_inventory',
                    nonce: lightspeed_connector_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        setTimeout(function() {
                            alert(response.data);
                            location.reload();
                        }, 800000); // Display success message
                    } else {
                        alert('Error: ' + response.data); // Display error message
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', error);
                },
                complete: function() {
                }
            });
        });
    });
    
    
// })(jQuery);

// ── Image Report: search, bulk sync, single-product sync ────────────────────
jQuery(document).ready(function($) {

    // Select-all checkbox toggles all row checkboxes.
    $(document).on('change', '#lic-ir-select-all', function() {
        $('.lic-ir-row-cb').prop('checked', $(this).is(':checked'));
        licIrUpdateSyncBtn();
    });

    // Individual row checkbox keeps select-all in sync.
    $(document).on('change', '.lic-ir-row-cb', function() {
        var total   = $('.lic-ir-row-cb').length;
        var checked = $('.lic-ir-row-cb:checked').length;
        $('#lic-ir-select-all')
            .prop('checked', checked === total)
            .prop('indeterminate', checked > 0 && checked < total);
        licIrUpdateSyncBtn();
    });

    function licIrUpdateSyncBtn() {
        var count = $('.lic-ir-row-cb:checked').length;
        var $btn  = $('#lic-ir-sync-selected');
        $btn.prop('disabled', count === 0);
        $btn.text(count > 0 ? 'Sync Selected (' + count + ')' : 'Sync Selected');
    }

    // Bulk sync: processes selected products one at a time to avoid server overload.
    $(document).on('click', '#lic-ir-sync-selected', function() {
        var $btn    = $(this);
        var $status = $('#lic-ir-sync-status');
        var ids     = [];
        $('.lic-ir-row-cb:checked').each(function() { ids.push($(this).val()); });
        if ( ! ids.length ) return;

        $btn.prop('disabled', true);
        $status.text('');
        var done = 0, errors = 0;

        function syncNext() {
            var idx = done + errors;
            if ( idx >= ids.length ) {
                $btn.prop('disabled', false).text('Sync Selected (' + ids.length + ')');
                var msg = 'Done: ' + done + ' of ' + ids.length + ' synced.';
                if ( errors > 0 ) msg += ' ' + errors + ' failed.';
                $status.html(msg + ' <a href="' + window.location.href + '">Reload to refresh report</a>');
                return;
            }
            $btn.text('Syncing ' + (idx + 1) + ' of ' + ids.length + '...');
            $.post(lightspeed_connector_ajax.ajaxurl, {
                action:     'lic_sync_product_images',
                nonce:      lightspeed_connector_ajax.nonce,
                product_id: ids[idx]
            })
            .done(function(res) {
                if ( res.success ) { done++; } else { errors++; }
                syncNext();
            })
            .fail(function() { errors++; syncNext(); });
        }

        syncNext();
    });

    // Single-product sync on the detail page.
    $(document).on('click', '#lic-ir-sync-single', function() {
        var $btn    = $(this);
        var $result = $('#lic-ir-sync-result');
        var pid     = $btn.data('product-id');

        $btn.prop('disabled', true).text('Syncing...');
        $result.text('');

        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:     'lic_sync_product_images',
            nonce:      lightspeed_connector_ajax.nonce,
            product_id: pid
        })
        .done(function(res) {
            $btn.prop('disabled', false).text('Sync Images from API');
            if ( res.success ) {
                $result.html(
                    '<span style="color:#2a6b2a;">&#10003; ' + res.data.message +
                    ' &mdash; <a href="' + window.location.href + '">Reload to see updated report</a></span>'
                );
            } else {
                $result.html('<span style="color:#b32d2e;">Error: ' + res.data + '</span>');
            }
        })
        .fail(function() {
            $btn.prop('disabled', false).text('Sync Images from API');
            $result.html('<span style="color:#b32d2e;">Request failed. Please try again.</span>');
        });
    });

});

jQuery(document).ready(function($){
    $('#upload_featured_image_button').click(function(e){
        e.preventDefault();
        var image = wp.media({ 
            title: 'Upload Featured Image',
            multiple: false
        }).open()
        .on('select', function(){
            var uploaded_image = image.state().get('selection').first();
            var image_id = uploaded_image.toJSON().id;
            var image_url = uploaded_image.toJSON().url;
            $('#lic_featured_default_image').val(image_id);
            $('#featured_image_preview').html('<img src="'+image_url+'" style="max-width: 300px;" />');
        });
    });
});
