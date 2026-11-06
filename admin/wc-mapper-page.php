<?php if ( empty( $lic_in_tab ) ) : ?><div class="wrap"><?php lic_sync_disabled_notice(); ?>
    <h1>Woocommerce Field Settings</h1>
<?php endif; ?>
    <div class="container">
        <h2 class="top-text">Please select the Lightspeed field you would like to populate your product pages with.</h2>
        <!-- **************************************** -->
        <!--   API Call Based on Location Selection   -->
        <!-- **************************************** -->
        <form method="post" action="" style="margin-left: 15%;">
            <fieldset class="left_api_fields">
                <legend>Select API Fields:</legend>
                <?php LIC_WC_Mapper::mapper_fields_callback(); ?>
            </fieldset>
        </form>
        <!-- ******************************************** -->
        <!--   API Call Based on Location Selection End   -->
        <!-- ******************************************** -->
        <!-- ******************************************** -->
        <!--       API & Woocommerce default fields       -->
        <!-- ******************************************** -->
        <div class="fields-container">
            <?php LIC_WC_Mapper::mapper_field_sku_render(); ?> 
        </div>
        <!-- ******************************************** -->
        <!--    API & Woocommerce default fields end      -->
        <!-- ******************************************** -->
    </div>

    <button type="submit" class="LIC_field_btn" name="save_mapped_fields" id="save_mapped_fields" value="Save Mapping">Save Mapping</button>
    <button type="button" class="button" id="lic-backfill-mapping" style="margin-left:10px;">Apply to All Existing Products</button>
    <span id="lic-backfill-message" style="display:inline-block;margin-left:12px;font-size:13px;"></span>

<script>
jQuery(document).ready(function($) {
    $('#lic-backfill-mapping').on('click', function() {
        var location = $('#selected_location').val();
        if (!location || location === 'null' || location === '') {
            alert('Please select a location first.');
            return;
        }
        if (!confirm('Apply the current field mapping to all existing products for "' + location + '"?\n\nThis updates all mapped fields on every product in this location from the API cache.')) {
            return;
        }

        var $btn     = $(this).prop('disabled', true).text('Applying…');
        var $msg     = $('#lic-backfill-message').css('color', '#555');
        var totUp    = 0;
        var totSkip  = 0;
        var BATCH    = 50;

        function runBatch(offset) {
            $.post(lightspeed_connector_ajax.ajaxurl, {
                action:        'lic_backfill_field_mapping',
                nonce:         lightspeed_connector_ajax.nonce,
                location_name: location,
                offset:        offset,
                batch_size:    BATCH
            }, function(r) {
                if (!r.success) {
                    $btn.prop('disabled', false).text('Apply to All Existing Products');
                    $msg.text(r.data || 'Failed.').css('color', 'red');
                    return;
                }
                totUp   += r.data.updated;
                totSkip += r.data.skipped;

                if (r.data.has_more) {
                    var done = r.data.next_offset;
                    var total = r.data.total;
                    $msg.text('Processing… ' + done + ' of ' + total + ' products.');
                    runBatch(r.data.next_offset);
                } else {
                    $btn.prop('disabled', false).text('Apply to All Existing Products');
                    $msg.text(
                        'Done: ' + totUp + ' product' + (totUp !== 1 ? 's' : '') +
                        ' updated, ' + totSkip + ' skipped (not in API cache).'
                    ).css('color', 'green');
                }
            }).fail(function(xhr) {
                $btn.prop('disabled', false).text('Apply to All Existing Products');
                var detail = 'HTTP ' + xhr.status + (xhr.responseText ? ': ' + xhr.responseText.substring(0, 150) : '');
                $msg.text('Request failed — ' + detail).css('color', 'red');
            });
        }

        $msg.text('Starting…');
        runBatch(0);
    });
});
</script>

<?php if ( empty( $lic_in_tab ) ) : ?></div><?php endif; ?>

