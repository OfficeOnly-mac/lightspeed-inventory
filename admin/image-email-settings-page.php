<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized.' );
}

$email_recipients    = get_option( 'lic_image_email_recipients', '' );
$email_filter        = get_option( 'lic_image_email_filter', 'mismatches' );
$email_min_api_count = max( 0, (int) get_option( 'lic_image_email_min_api_count', 0 ) );
$email_time_utc      = get_option( 'lic_image_email_send_time', '15:00' );
$email_dt            = new DateTime( 'today ' . $email_time_utc, new DateTimeZone( 'UTC' ) );
$email_dt->setTimezone( wp_timezone() );
$email_time_local    = $email_dt->format( 'H:i' );
$site_tz_label       = wp_timezone_string();

$extra_fields = json_decode( get_option( 'lic_image_email_extra_fields', '[]' ), true ) ?: [];
?>
<?php if ( empty( $lic_in_tab ) ) : ?><div class="wrap"><?php lic_sync_disabled_notice(); ?>
    <h1>Image Email Settings</h1>
<?php endif; ?>

    <h2>Email Delivery</h2>
    <p>Sent once per day. The email body always includes a list of products with no API images. A CSV attachment contains the filtered report.</p>
    <table class="form-table" style="max-width:640px;">
        <tr>
            <th style="width:220px;">Recipients</th>
            <td>
                <input type="text" id="lic-email-recipients" value="<?php echo esc_attr( $email_recipients ); ?>" style="width:100%;max-width:460px;" placeholder="email@example.com, another@example.com" />
                <p class="description">Comma-separated. Defaults to the WordPress admin email when blank.</p>
            </td>
        </tr>
        <tr>
            <th>Send time (<?php echo esc_html( $site_tz_label ); ?>)</th>
            <td><input type="time" id="lic-email-send-time" value="<?php echo esc_attr( $email_time_local ); ?>" style="width:120px;" /></td>
        </tr>
        <tr>
            <th>CSV report filter</th>
            <td>
                <select id="lic-email-filter">
                    <option value="mismatches" <?php selected( $email_filter, 'mismatches' ); ?>>Mismatches only</option>
                    <option value="all" <?php selected( $email_filter, 'all' ); ?>>All products</option>
                </select>
                <p class="description">Controls which products appear in the attached CSV.</p>
            </td>
        </tr>
        <tr>
            <th>Minimum API image count</th>
            <td>
                <input type="number" id="lic-email-min-api-count" value="<?php echo esc_attr( $email_min_api_count ); ?>" min="0" style="width:70px;" />
                <p class="description">Only include products with at least this many API images in the CSV. Products with 0 images always appear in the email body regardless of this setting.</p>
            </td>
        </tr>
    </table>
    <button id="lic-save-email-settings" class="button">Save Delivery Settings</button>
    <span id="lic-email-settings-message" style="margin-left:10px;"></span>

    <hr style="border:none;border-top:1px solid #ccd0d4;margin:30px 0;">

    <h2>Extra Columns for No-Images Table</h2>
    <p>These fields appear as extra columns in the <em>Products With No Images</em> section of the daily email. The list is populated from ACF fields registered to products and WooCommerce product attribute taxonomies — only explicitly defined fields are shown.</p>

    <div id="lic-fields-loading" style="color:#666;padding:6px 0;">Loading available fields&hellip;</div>

    <table id="lic-extra-fields-table" style="display:none;border-collapse:collapse;margin-bottom:12px;">
        <thead>
            <tr style="background:#f0f0f1;">
                <th style="padding:7px 10px;text-align:left;border:1px solid #ccd0d4;min-width:180px;">Column Label</th>
                <th style="padding:7px 10px;text-align:left;border:1px solid #ccd0d4;min-width:260px;">Field</th>
                <th style="padding:7px 10px;border:1px solid #ccd0d4;width:40px;"></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <div id="lic-fields-actions" style="display:none;">
        <button type="button" id="lic-add-field" class="button">+ Add Field</button>
        &nbsp;
        <button type="button" id="lic-save-extra-fields" class="button button-primary" style="margin-left:4px;">Save Fields</button>
        <span id="lic-extra-fields-message" style="margin-left:10px;"></span>
    </div>

    <p id="lic-fields-error" style="display:none;color:#a00;">Could not load product fields. Make sure products exist and try refreshing the page.</p>

<?php if ( empty( $lic_in_tab ) ) : ?></div><?php endif; ?>

<script>
var licExistingExtraFields = <?php echo wp_json_encode( $extra_fields ); ?>;
</script>
<script>
jQuery(document).ready(function($) {

    // ── Delivery settings ────────────────────────────────────────────────────

    $('#lic-save-email-settings').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:           'lic_save_image_email_settings',
            nonce:            lightspeed_connector_ajax.nonce,
            email_recipients: $('#lic-email-recipients').val(),
            send_time:        $('#lic-email-send-time').val(),
            email_filter:     $('#lic-email-filter').val(),
            min_api_count:    $('#lic-email-min-api-count').val()
        }, function(r) {
            $btn.prop('disabled', false);
            $('#lic-email-settings-message').text(r.success ? 'Saved — cron rescheduled.' : (r.data || 'Failed.')).css('color', r.success ? 'green' : 'red');
        });
    });

    // ── Extra fields ─────────────────────────────────────────────────────────

    var availableFields = [];   // [{key, label, source}]
    var selectHtml      = '';   // pre-built <select> HTML, set after fields load

    function buildSelectHtml(fields) {
        var acf = fields.filter(function(f) { return f.source === 'acf'; });
        var wc  = fields.filter(function(f) { return f.source !== 'acf'; });
        var html = '<select class="field-selector" style="width:100%;max-width:320px;">'
                 + '<option value="">— select a field —</option>';
        if (acf.length) {
            html += '<optgroup label="ACF Fields">';
            acf.forEach(function(f) {
                html += '<option value="' + escAttr(f.key) + '" data-source="acf" data-label="' + escAttr(f.label) + '">'
                      + escHtml(f.label) + '</option>';
            });
            html += '</optgroup>';
        }
        if (wc.length) {
            html += '<optgroup label="WooCommerce Product Attributes">';
            wc.forEach(function(f) {
                html += '<option value="' + escAttr(f.key) + '" data-source="wc" data-label="' + escAttr(f.label) + '">'
                      + escHtml(f.label) + '</option>';
            });
            html += '</optgroup>';
        }
        html += '</select>';
        return html;
    }

    function makeRow(fieldKey, fieldLabel) {
        var $row = $(
            '<tr>' +
            '<td style="padding:5px 8px;border:1px solid #ddd;">' +
                '<input type="text" class="field-label regular-text" style="width:100%;" />' +
            '</td>' +
            '<td style="padding:5px 8px;border:1px solid #ddd;">' + selectHtml + '</td>' +
            '<td style="padding:5px 8px;border:1px solid #ddd;text-align:center;">' +
                '<button type="button" class="button lic-remove-field" style="color:#a00;" title="Remove">&#10005;</button>' +
            '</td>' +
            '</tr>'
        );
        if (fieldKey) {
            $row.find('.field-selector').val(fieldKey);
        }
        if (fieldLabel) {
            $row.find('.field-label').val(fieldLabel);
        }
        return $row;
    }

    // Auto-fill label when a field is selected, but only if the label is still blank.
    $(document).on('change', '.field-selector', function() {
        var $label = $(this).closest('tr').find('.field-label');
        if ($label.val().trim() === '') {
            $label.val($(this).find(':selected').data('label') || '');
        }
    });

    // Remove a row.
    $(document).on('click', '.lic-remove-field', function() {
        $(this).closest('tr').remove();
    });

    // Add a new empty row.
    $('#lic-add-field').on('click', function() {
        $('#lic-extra-fields-table tbody').append(makeRow('', ''));
    });

    // Save the current field list.
    $('#lic-save-extra-fields').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        var fields = [];
        $('#lic-extra-fields-table tbody tr').each(function() {
            var label  = $.trim($(this).find('.field-label').val());
            var $sel   = $(this).find('.field-selector');
            var key    = $sel.val();
            var source = $sel.find(':selected').data('source') || 'wc';
            if (label && key) {
                fields.push({ label: label, key: key, type: source });
            }
        });
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_save_image_email_extra_fields',
            nonce:  lightspeed_connector_ajax.nonce,
            fields: JSON.stringify(fields)
        }, function(r) {
            $btn.prop('disabled', false);
            $('#lic-extra-fields-message').text(r.success ? 'Saved.' : (r.data || 'Failed.')).css('color', r.success ? 'green' : 'red');
        });
    });

    // Load available fields on page ready, then populate the table.
    $.post(lightspeed_connector_ajax.ajaxurl, {
        action: 'lic_get_available_product_fields',
        nonce:  lightspeed_connector_ajax.nonce
    }, function(r) {
        $('#lic-fields-loading').hide();
        if (!r.success || !r.data || !r.data.length) {
            $('#lic-fields-error').show();
            return;
        }
        availableFields = r.data;
        selectHtml      = buildSelectHtml(availableFields);

        // Build rows for already-saved fields.
        var $tbody = $('#lic-extra-fields-table tbody');
        (licExistingExtraFields || []).forEach(function(f) {
            $tbody.append(makeRow(f.key, f.label));
        });

        $('#lic-extra-fields-table').show();
        $('#lic-fields-actions').show();
    }).fail(function() {
        $('#lic-fields-loading').hide();
        $('#lic-fields-error').show();
    });

    // ── Helpers ──────────────────────────────────────────────────────────────

    function escAttr(s) {
        return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

});
</script>
