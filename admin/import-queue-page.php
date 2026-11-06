<?php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized.' );
}

$queue_entries = LIC_Importer::get_queue_status();
$next_run      = wp_next_scheduled( 'lic_process_queue_cron' );
$sync_enabled  = get_option( 'lic_sync_enabled', '1' ) !== '0';

if ( ! function_exists( 'lic_render_queue_table' ) ) {
    function lic_render_queue_table( $entries, $next_run ) {
        $html  = '<table class="widefat fixed striped" style="max-width:700px;">';
        $html .= '<thead><tr><th>Location</th><th>Status</th><th>Progress</th><th>Last Processed</th></tr></thead><tbody>';
        foreach ( $entries as $e ) {
            $html .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $e->location_name ),
                esc_html( $e->status ),
                esc_html( $e->current_offset . ' items processed' ),
                esc_html( $e->last_processed_at ?? '—' )
            );
        }
        $html .= '</tbody></table>';
        if ( $next_run ) {
            $html .= '<p>Next batch runs in: <strong>' . esc_html( human_time_diff( time(), $next_run ) ) . '</strong></p>';
        }
        return $html;
    }
}
?>
<?php if ( empty( $lic_in_tab ) ) : ?><div class="wrap"><?php lic_sync_disabled_notice(); ?>
    <h1>Import Queue</h1>
<?php endif; ?>

    <h2>API Cache</h2>
    <p>Fetch the latest inventory from the Lightspeed API and store it locally. This may take a minute or two for large inventories.</p>
    <button id="lic-warm-cache" class="button" <?php disabled( ! $sync_enabled ); ?>>Warm Cache Now</button>
    <span id="lic-warm-message" style="margin-left:10px;"></span>

    <h2 style="margin-top:30px;">Import Queue</h2>
    <p>Process the cached inventory and create or update WooCommerce products. The queue runs automatically every 2 minutes once started.</p>
    <button id="my-inventory-button" class="button button-primary" <?php disabled( ! $sync_enabled ); ?>>Start Queue</button>
    <?php if ( ! $sync_enabled ) : ?>
        <span style="margin-left:10px;color:#888;">Sync is disabled — enable it in <a href="<?php echo esc_url( admin_url( 'admin.php?page=lic-settings&tab=sync' ) ); ?>">Settings &rarr; Sync</a>.</span>
    <?php endif; ?>
    <span id="lic-queue-message" style="margin-left:10px;"></span>

    <div id="lic-queue-status" style="margin-top:20px;">
        <?php if ( empty( $queue_entries ) ) : ?>
            <p>No active queue. Click <strong>Start Queue</strong> to begin an import.</p>
        <?php else : ?>
            <?php echo lic_render_queue_table( $queue_entries, $next_run ); ?>
        <?php endif; ?>
    </div>

<?php if ( empty( $lic_in_tab ) ) : ?></div><?php endif; ?>

<style>
    #my-inventory-button.disabled {
        background: #ccc;
        border-color: #ccc;
        color: #666;
        cursor: not-allowed;
    }
</style>

<script>
jQuery(document).ready(function($) {
    // Warm cache now
    $('#lic-warm-cache').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Warming...');
        $('#lic-warm-message').text('Fetching all inventory pages from API — please wait...').css('color', '#888');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_warm_cache_now',
            nonce:  lightspeed_connector_ajax.nonce
        }, function(r) {
            $btn.prop('disabled', false).text('Warm Cache Now');
            if (r.success) {
                $('#lic-warm-message').text('Cache warmed successfully.').css('color', 'green');
            } else {
                $('#lic-warm-message').text('Cache warm failed.').css('color', 'red');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Warm Cache Now');
            $('#lic-warm-message').text('Request timed out — cache warm runs in background, check logs.').css('color', 'orange');
        });
    });

    // Start queue
    $('#my-inventory-button').on('click', function() {
        var $btn = $(this).addClass('disabled').prop('disabled', true);
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'pull_inventory',
            nonce:  lightspeed_connector_ajax.nonce
        }, function(r) {
            $btn.removeClass('disabled').prop('disabled', false);
            if (r.success) {
                $('#lic-queue-message').text('Queue started.').css('color', 'green');
                refreshStatus();
                if (!refreshTimer) {
                    refreshTimer = setInterval(refreshStatus, 15000);
                }
            } else {
                $('#lic-queue-message').text('Failed to start queue.').css('color', 'red');
            }
        });
    });

    var refreshTimer = null;

    function refreshStatus() {
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_get_queue_status',
            nonce:  lightspeed_connector_ajax.nonce
        }, function(r) {
            if (!r.success) return;
            var entries = r.data.entries;
            var nextRun = r.data.next_run;
            var $status = $('#lic-queue-status');

            if (!entries || !entries.length) {
                $status.html('<p>No active queue.</p>');
                clearInterval(refreshTimer);
                return;
            }

            var html = '<table class="widefat fixed striped" style="max-width:700px;">';
            html += '<thead><tr><th>Location</th><th>Status</th><th>Progress</th><th>Last Processed</th></tr></thead><tbody>';
            var allDone = true;
            entries.forEach(function(e) {
                if (e.status !== 'complete') allDone = false;
                html += '<tr><td>' + e.location_name + '</td><td>' + e.status + '</td><td>' + e.current_offset + ' items</td><td>' + (e.last_processed_at || '—') + '</td></tr>';
            });
            html += '</tbody></table>';
            if (nextRun) {
                html += '<p>Next batch in: <strong>' + nextRun + '</strong></p>';
            }
            $status.html(html);

            if (allDone) {
                clearInterval(refreshTimer);
                $('#lic-queue-message').text('Import complete.').css('color', 'green');
            }
        });
    }

    <?php if ( $next_run ) : ?>
    refreshTimer = setInterval(refreshStatus, 15000);
    <?php endif; ?>
});
</script>
