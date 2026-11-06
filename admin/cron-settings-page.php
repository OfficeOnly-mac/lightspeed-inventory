<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized.' );
}

$batch_size   = max( 5, min( 100, (int) get_option( 'lic_batch_size', 25 ) ) );
$stale_days   = max( 1, (int) get_option( 'lic_alert_stale_days', 7 ) );
$stale_count  = max( 1, (int) get_option( 'lic_alert_stale_count', 10 ) );
$sync_enabled = get_option( 'lic_sync_enabled', '1' ) !== '0';

// Convert stored UTC time to site timezone for display.
$warm_time_utc = get_option( 'lic_cache_warm_time', '06:00' );
$warm_dt       = new DateTime( 'today ' . $warm_time_utc, new DateTimeZone( 'UTC' ) );
$warm_dt->setTimezone( wp_timezone() );
$warm_time_local = $warm_dt->format( 'H:i' );
$site_tz_label   = wp_timezone_string();

?>
<?php if ( empty( $lic_in_tab ) ) : ?><div class="wrap"><?php lic_sync_disabled_notice(); ?>
    <h1>Sync Settings</h1>
<?php endif; ?>

    <h2>Sync</h2>
    <p>When disabled, new queue runs and automatic cache warming are blocked. Any queue currently in progress will finish its remaining batches.</p>
    <label style="font-size:14px;cursor:pointer;">
        <input type="checkbox" id="lic-sync-enabled" <?php checked( $sync_enabled ); ?> style="margin-right:6px;" />
        Sync enabled
    </label>
    <span id="lic-sync-message" style="margin-left:12px;"></span>

    <h2 style="margin-top:30px;">Batch Size</h2>
    <p>Number of products processed per cron run (5–100). The queue runs every 2 minutes.</p>
    <input type="number" id="lic-batch-size" value="<?php echo esc_attr( $batch_size ); ?>" min="5" max="100" style="width:80px;" />
    <button id="lic-save-batch-size" class="button" style="margin-left:8px;">Save</button>
    <span id="lic-batch-message" style="margin-left:10px;"></span>

    <h2 style="margin-top:30px;">API Cache</h2>
    <p>The cache is refreshed automatically once per day at the time below. To warm the cache manually, use the <a href="<?php echo esc_url( admin_url( 'admin.php?page=lic-import&tab=queue' ) ); ?>">Import &rarr; Queue</a> page. The monthly SoldUnit reconciliation sweep (Logs &amp; Reports &rarr; Confirmed Sold) also runs at this same off-hours time, since it's a larger batched request against the Lightspeed API.</p>
    <label style="font-size:13px;">Daily cache warm time (<?php echo esc_html( $site_tz_label ); ?>):&nbsp;
        <input type="time" id="lic-cache-warm-time" value="<?php echo esc_attr( $warm_time_local ); ?>" style="width:120px;" />
    </label>
    <button id="lic-save-warm-time" class="button" style="margin-left:8px;">Save Time</button>
    <span id="lic-warm-time-message" style="margin-left:10px;"></span>

    <h2 style="margin-top:30px;">Sync Alert Thresholds</h2>
    <p>An admin notice and email are sent when more than <strong>N</strong> products have not been synced in more than <strong>D</strong> days. Emails are throttled to once per 4 hours per alert type.</p>
    <table class="form-table" style="max-width:500px;">
        <tr>
            <th>Stale threshold (days)</th>
            <td><input type="number" id="lic-stale-days" value="<?php echo esc_attr($stale_days); ?>" min="1" style="width:70px;" /></td>
        </tr>
        <tr>
            <th>Alert when N or more products stale</th>
            <td><input type="number" id="lic-stale-count" value="<?php echo esc_attr($stale_count); ?>" min="1" style="width:70px;" /></td>
        </tr>
    </table>
    <button id="lic-save-alert-settings" class="button" style="margin-top:8px;">Save Alert Settings</button>
    <span id="lic-alert-message" style="margin-left:10px;"></span>

<?php if ( empty( $lic_in_tab ) ) : ?></div><?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // Sync enable/disable toggle
    $('#lic-sync-enabled').on('change', function() {
        var enabled = $(this).is(':checked') ? 1 : 0;
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:       'lic_set_sync_enabled',
            nonce:        lightspeed_connector_ajax.nonce,
            sync_enabled: enabled
        }, function(r) {
            if (r.success) {
                $('#lic-sync-message').text(enabled ? 'Sync enabled.' : 'Sync disabled.').css('color', enabled ? 'green' : 'orange');
            } else {
                $('#lic-sync-message').text('Failed to save.').css('color', 'red');
            }
        });
    });

    // Save cache warm time
    $('#lic-save-warm-time').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:          'lic_save_cache_warm_time',
            nonce:           lightspeed_connector_ajax.nonce,
            cache_warm_time: $('#lic-cache-warm-time').val()
        }, function(r) {
            $btn.prop('disabled', false);
            $('#lic-warm-time-message').text(r.success ? 'Saved — cron rescheduled.' : 'Failed.').css('color', r.success ? 'green' : 'red');
        });
    });

    // Save batch size
    $('#lic-save-batch-size').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:     'lic_save_batch_size',
            nonce:      lightspeed_connector_ajax.nonce,
            batch_size: $('#lic-batch-size').val()
        }, function(r) {
            $btn.prop('disabled', false);
            $('#lic-batch-message').text(r.success ? 'Saved.' : 'Failed.').css('color', r.success ? 'green' : 'red');
        });
    });

    // Save alert settings
    $('#lic-save-alert-settings').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:      'lic_save_alert_settings',
            nonce:       lightspeed_connector_ajax.nonce,
            stale_days:  $('#lic-stale-days').val(),
            stale_count: $('#lic-stale-count').val()
        }, function(r) {
            $btn.prop('disabled', false);
            $('#lic-alert-message').text(r.success ? 'Saved.' : 'Failed.').css('color', r.success ? 'green' : 'red');
        });
    });

});

</script>
