<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized.' );
}

$flags = get_option( 'lic_inventory_flags', [] );

$status_map      = get_option( 'lic_unit_status_map', [ '' => 'publish' ] );
$known_statuses  = LIC_Ajax_Handler::get_distinct_unit_statuses();

// Merge saved map entries not yet in the cache, but skip numeric artifact
// keys that were created by the jQuery empty-string serialization bug.
foreach ( array_keys( $status_map ) as $saved_status ) {
    if ( is_numeric( $saved_status ) ) continue;
    if ( ! in_array( $saved_status, $known_statuses, true ) ) {
        $known_statuses[] = $saved_status;
    }
}

$action_labels = [
    'publish' => 'Publish (instock)',
    'draft'   => 'Draft (hidden)',
    'trash'   => 'Trash (soft delete)',
    'ignore'  => 'Ignore (no change)',
];
?>
<?php if ( empty( $lic_in_tab ) ) : ?><div class="wrap"><?php lic_sync_disabled_notice(); ?>
    <h1>Unit Status Settings</h1>
<?php endif; ?>
    <p>Map each Lightspeed <code>UnitStatus</code> value to a WordPress action applied during import. Use <strong>Refresh from Cache</strong> to detect any new status values.</p>
    <p style="background:#fff8e1;border-left:4px solid #dba617;padding:8px 12px;max-width:600px;">
        <strong>Note:</strong> this table is fed from the <code>Unit</code> endpoint, where
        <code>UnitStatus</code> is only ever blank, <code>R</code> (Requested), or <code>O</code>
        (On Order) — the <code>S</code> (Sold) value only appears on Lightspeed's separate
        <code>SoldUnit</code> endpoint, so it will never show up here. Sold-unit detection is
        handled independently under Logs &amp; Reports → <strong>Confirmed Sold</strong> /
        <strong>Sold Unit Alerts</strong> / <strong>Stock Debug</strong>. Do not map the blank
        status to Trash expecting it to catch sold units — blank covers every available unit too.
    </p>

    <table class="widefat fixed striped" id="lic-unit-status-table" style="max-width:600px;margin-bottom:16px;">
        <thead>
            <tr>
                <th>UnitStatus Value</th>
                <th>WordPress Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $known_statuses ) ) : ?>
                <tr id="lic-no-statuses-row">
                    <td colspan="2">No UnitStatus values found in cache. Run a cache warm or click Refresh.</td>
                </tr>
            <?php else : ?>
                <?php foreach ( $known_statuses as $status ) :
                    $display = ( $status === '' ) ? '<em>(empty)</em>' : esc_html( $status );
                    $current_action = $status_map[ $status ] ?? 'ignore';
                ?>
                <tr>
                    <td><?php echo wp_kses( $display, [ 'em' => [] ] ); ?></td>
                    <td>
                        <select name="lic_status_map[<?php echo esc_attr( $status ); ?>]" data-status="<?php echo esc_attr( $status ); ?>" class="lic-status-action">
                            <?php foreach ( $action_labels as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_action, $value ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <button id="lic-refresh-statuses" class="button" style="margin-right:8px;">Refresh from Cache</button>
    <button id="lic-save-status-map" class="button button-primary">Save Mappings</button>
    <span id="lic-status-message" style="margin-left:12px;"></span>

    <hr style="margin:32px 0;" />

    <h2>Inventory Flag Mapping</h2>
    <p>The plugin can read a numeric code from any API field and use it to mark products as <strong>Featured</strong> or add them to a <strong>Clearance</strong> category. Enter comma-separated values for each action.</p>

    <table class="form-table" style="max-width:600px;">
        <tr>
            <th scope="row"><label for="lic-flag-field">API field name</label></th>
            <td>
                <input type="text" id="lic-flag-field" value="<?php echo esc_attr( $flags['flag_field'] ?? 'Odometer' ); ?>" style="width:200px;" />
                <p class="description">The Lightspeed API field whose value contains the flag code (e.g. <code>Odometer</code>).</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="lic-featured-values">Featured when value is</label></th>
            <td>
                <input type="text" id="lic-featured-values" value="<?php echo esc_attr( $flags['featured_values'] ?? '2,3' ); ?>" style="width:200px;" placeholder="e.g. 2,3" />
                <p class="description">Comma-separated list of numeric codes that mark a product as WooCommerce Featured.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="lic-clearance-values">Clearance when value is</label></th>
            <td>
                <input type="text" id="lic-clearance-values" value="<?php echo esc_attr( $flags['clearance_values'] ?? '1,3' ); ?>" style="width:200px;" placeholder="e.g. 1,3" />
                <p class="description">Comma-separated list of numeric codes that add a product to the clearance category.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="lic-clearance-slug">Clearance category slug</label></th>
            <td>
                <input type="text" id="lic-clearance-slug" value="<?php echo esc_attr( $flags['clearance_category_slug'] ?? 'trailer-deals' ); ?>" style="width:200px;" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="lic-clearance-name">Clearance category name</label></th>
            <td>
                <input type="text" id="lic-clearance-name" value="<?php echo esc_attr( $flags['clearance_category_name'] ?? 'Trailer Deals' ); ?>" style="width:200px;" />
                <p class="description">Used only when creating the category for the first time.</p>
            </td>
        </tr>
    </table>

    <button id="lic-save-flags" class="button button-primary" style="margin-top:8px;">Save Flag Settings</button>
    <span id="lic-flags-message" style="margin-left:12px;"></span>
<?php if ( empty( $lic_in_tab ) ) : ?></div><?php endif; ?>

<script>
jQuery(document).ready(function($) {
    var actionLabels = <?php echo wp_json_encode( $action_labels ); ?>;

    $('#lic-refresh-statuses').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Refreshing...');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_refresh_unit_statuses',
            nonce:  lightspeed_connector_ajax.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Refresh from Cache');
            if (!response.success) {
                $('#lic-status-message').text('Refresh failed.').css('color', 'red');
                return;
            }
            var statuses = response.data;
            var savedMap = {};
            $('.lic-status-action').each(function() {
                savedMap[$(this).data('status')] = $(this).val();
            });

            var $tbody = $('#lic-unit-status-table tbody');
            $tbody.empty();

            if (!statuses.length) {
                $tbody.append('<tr><td colspan="2">No UnitStatus values found in cache.</td></tr>');
                return;
            }

            statuses.forEach(function(status) {
                var display  = status === '' ? '<em>(empty)</em>' : $('<span>').text(status).html();
                var current  = savedMap[status] || 'ignore';
                var $select  = $('<select class="lic-status-action">').attr('data-status', status);
                $.each(actionLabels, function(val, label) {
                    $select.append($('<option>').val(val).text(label).prop('selected', val === current));
                });
                $tbody.append($('<tr>').append($('<td>').html(display)).append($('<td>').append($select)));
            });

            $('#lic-status-message').text('Refreshed.').css('color', 'green');
        });
    });

    $('#lic-save-status-map').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Saving...');
        var map  = {};
        $('.lic-status-action').each(function() {
            map[$(this).data('status')] = $(this).val();
        });
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:          'lic_save_unit_status_map',
            nonce:           lightspeed_connector_ajax.nonce,
            status_map_json: JSON.stringify(map)
        }, function(response) {
            $btn.prop('disabled', false).text('Save Mappings');
            if (response.success) {
                $('#lic-status-message').text('Saved.').css('color', 'green');
            } else {
                $('#lic-status-message').text('Save failed.').css('color', 'red');
            }
        });
    });

    $('#lic-save-flags').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Saving...');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:                  'lic_save_inventory_flags',
            nonce:                   lightspeed_connector_ajax.nonce,
            flag_field:              $('#lic-flag-field').val().trim(),
            featured_values:         $('#lic-featured-values').val().trim(),
            clearance_values:        $('#lic-clearance-values').val().trim(),
            clearance_category_slug: $('#lic-clearance-slug').val().trim(),
            clearance_category_name: $('#lic-clearance-name').val().trim()
        }, function(response) {
            $btn.prop('disabled', false).text('Save Flag Settings');
            $('#lic-flags-message').text(response.success ? 'Saved.' : 'Save failed.').css('color', response.success ? 'green' : 'red');
        });
    });
});
</script>
