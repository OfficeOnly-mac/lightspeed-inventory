<?php
if ( ! defined( 'ABSPATH' ) ) die;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

global $wpdb;

// ── Data ─────────────────────────────────────────────────────────────────────

$settings     = get_option( 'lic_settings', [] );
$locations    = $settings['locations'] ?? [];
$sync_enabled = get_option( 'lic_sync_enabled', '1' ) !== '0';
$next_import  = wp_next_scheduled( 'lic_process_queue_cron' );
$next_cache   = wp_next_scheduled( 'lic_daily_api_cache_cron' );

// Product counts per location (published products with a location meta value).
$raw_counts = $wpdb->get_results(
    "SELECT pm.meta_value AS loc, COUNT(DISTINCT pm.post_id) AS cnt
     FROM {$wpdb->postmeta} pm
     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE pm.meta_key = 'location'
       AND p.post_type = 'product'
       AND p.post_status = 'publish'
     GROUP BY pm.meta_value",
    ARRAY_A
);
$product_counts = [];
$total_products = 0;
foreach ( $raw_counts as $r ) {
    $product_counts[ $r['loc'] ] = (int) $r['cnt'];
    $total_products += (int) $r['cnt'];
}

// Latest sync log row per location.
$sync_table       = $wpdb->prefix . 'lic_sync_log';
$sync_by_location = [];
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sync_table ) ) === $sync_table ) {
    $rows = $wpdb->get_results(
        "SELECT s.* FROM {$sync_table} s
         INNER JOIN (
             SELECT location_name, MAX(started_at) AS latest
             FROM {$sync_table} GROUP BY location_name
         ) m ON s.location_name = m.location_name AND s.started_at = m.latest"
    );
    foreach ( $rows as $row ) {
        $sync_by_location[ $row->location_name ] = $row;
    }
}

// Cache last-warmed timestamp per location.
$cache_table       = $wpdb->prefix . 'lic_api_cache';
$cache_by_location = [];
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) ) === $cache_table ) {
    $rows = $wpdb->get_results(
        "SELECT location_name, MAX(fetched_at) AS fetched_at FROM {$cache_table} GROUP BY location_name"
    );
    foreach ( $rows as $row ) {
        $cache_by_location[ $row->location_name ] = $row->fetched_at;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

$ago = function( $datetime_str ) {
    if ( ! $datetime_str ) return '—';
    $diff = time() - strtotime( $datetime_str );
    if ( $diff < 60 )    return 'just now';
    if ( $diff < 3600 )  return round( $diff / 60 ) . 'm ago';
    if ( $diff < 86400 ) return round( $diff / 3600 ) . 'h ago';
    return round( $diff / 86400 ) . 'd ago';
};

$in_future = function( $timestamp ) {
    if ( ! $timestamp ) return '—';
    $diff = $timestamp - time();
    if ( $diff <= 0 )    return 'overdue';
    if ( $diff < 3600 )  return 'in ' . round( $diff / 60 ) . 'm';
    if ( $diff < 86400 ) return 'in ' . round( $diff / 3600 ) . 'h';
    return 'in ' . round( $diff / 86400 ) . 'd';
};

// Checklist evaluation.
$loc_count      = count( $locations );
$all_mapped     = $loc_count > 0;
$any_mapped     = false;
$all_cache_warm = $loc_count > 0;
$any_cache_warm = false;
foreach ( $locations as $loc ) {
    $name = $loc['name'] ?? '';
    if ( empty( get_option( 'mapped_fields_' . $name ) ) ) {
        $all_mapped = false;
    } else {
        $any_mapped = true;
    }
    $fetched = $cache_by_location[ $name ] ?? null;
    if ( ! $fetched || ( time() - strtotime( $fetched ) ) > 25 * HOUR_IN_SECONDS ) {
        $all_cache_warm = false;
    } else {
        $any_cache_warm = true;
    }
}

$check = function( $state, $label, $detail = '', $url = '' ) {
    // $state: 'ok' | 'warn' | 'error'
    $icon  = $state === 'ok'   ? '<span class="dashicons dashicons-yes-alt"  style="color:#2a6b2a;vertical-align:middle;"></span>'
           : ( $state === 'warn' ? '<span class="dashicons dashicons-warning"    style="color:#996800;vertical-align:middle;"></span>'
                                 : '<span class="dashicons dashicons-dismiss"    style="color:#b32d2e;vertical-align:middle;"></span>' );
    $link  = $url ? ' &nbsp;<a href="' . esc_url( $url ) . '" style="font-size:12px;">manage &rarr;</a>' : '';
    $sub   = $detail ? '<br><span style="color:#666;font-size:12px;padding-left:22px;">' . esc_html( $detail ) . '</span>' : '';
    echo '<li style="margin:8px 0;">' . $icon . ' &nbsp;' . esc_html( $label ) . $link . $sub . '</li>';
};

$settings_url    = admin_url( 'admin.php?page=lic-settings' );
$sync_tab_url    = admin_url( 'admin.php?page=lic-settings&tab=sync' );
$import_url      = admin_url( 'admin.php?page=lic-import' );
$mapping_tab_url = admin_url( 'admin.php?page=lic-settings&tab=field-mapping' );
$loc_tab_url     = admin_url( 'admin.php?page=lic-settings&tab=locations' );
$report_url      = admin_url( 'admin.php?page=lic-logs&tab=image-report' );

?>
<div class="wrap">
<?php lic_sync_disabled_notice(); ?>
<h1>Lightspeed API Connector</h1>
<p style="color:#666;margin-top:-4px;">Inventory sync plugin connecting Lightspeed DMS to WooCommerce.</p>

<div style="display:grid;grid-template-columns:320px 1fr;gap:24px;align-items:start;margin-top:20px;">

    <!-- ── Left column ─────────────────────────────────────── -->
    <div>

        <!-- Setup checklist -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px 20px;margin-bottom:20px;">
            <h2 style="margin:0 0 12px;font-size:14px;text-transform:uppercase;color:#666;letter-spacing:.05em;">Setup Status</h2>
            <ul style="margin:0;padding:0;list-style:none;">
                <?php
                $check(
                    $loc_count > 0 ? 'ok' : 'error',
                    $loc_count > 0 ? $loc_count . ' location' . ( $loc_count !== 1 ? 's' : '' ) . ' configured' : 'No locations configured',
                    '',
                    $loc_tab_url
                );
                $check(
                    $all_mapped ? 'ok' : ( $any_mapped ? 'warn' : 'error' ),
                    $all_mapped ? 'Fields mapped for all locations' : ( $any_mapped ? 'Fields partially mapped' : 'No field mappings set' ),
                    $all_mapped ? '' : 'Set which Lightspeed fields populate WooCommerce',
                    $mapping_tab_url
                );
                $check(
                    $all_cache_warm ? 'ok' : ( $any_cache_warm ? 'warn' : 'error' ),
                    $all_cache_warm ? 'API cache is current' : ( $any_cache_warm ? 'Cache stale for some locations' : 'API cache not warmed' ),
                    $next_cache ? 'Next warm: ' . $in_future( $next_cache ) : 'Cache warm cron not scheduled',
                    $sync_tab_url
                );
                $check(
                    $sync_enabled ? 'ok' : 'warn',
                    $sync_enabled ? 'Sync enabled' : 'Sync is disabled',
                    $next_import ? 'Next import: ' . $in_future( $next_import ) : 'Import cron not scheduled',
                    $sync_tab_url
                );
                $check(
                    $next_import ? 'ok' : 'error',
                    $next_import ? 'Import cron scheduled' : 'Import cron not scheduled',
                    $next_import ? 'Next run: ' . $in_future( $next_import ) : 'Start a queue run to register the cron',
                    $import_url
                );
                ?>
            </ul>
        </div>

        <!-- Quick actions -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px 20px;">
            <h2 style="margin:0 0 12px;font-size:14px;text-transform:uppercase;color:#666;letter-spacing:.05em;">Quick Actions</h2>
            <p style="margin:0 0 10px;">
                <button id="lic-dash-warm-cache" class="button button-primary" <?php echo $sync_enabled ? '' : 'disabled'; ?>>
                    Warm Cache Now
                </button>
                <span id="lic-dash-warm-msg" style="margin-left:8px;font-size:12px;"></span>
            </p>
            <p style="margin:0 0 10px;">
                <button id="lic-dash-sold-reconcile" class="button" <?php echo $sync_enabled ? '' : 'disabled'; ?>>
                    Run Sold-Unit Reconciliation Now
                </button>
                <span id="lic-dash-sold-reconcile-msg" style="margin-left:8px;font-size:12px;"></span>
            </p>
            <p style="margin:0 0 10px;">
                <a href="<?php echo esc_url( $import_url ); ?>" class="button">Start Import Queue &rarr;</a>
            </p>
            <p style="margin:0;">
                <a href="<?php echo esc_url( $report_url ); ?>" class="button">Image Report &rarr;</a>
            </p>
        </div>

    </div>

    <!-- ── Right column ────────────────────────────────────── -->
    <div>

        <!-- Sync summary -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px 20px;">
            <h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;color:#666;letter-spacing:.05em;">Sync Summary</h2>
            <p style="margin:0 0 14px;font-size:22px;font-weight:600;">
                <?php echo number_format( $total_products ); ?>
                <span style="font-size:14px;font-weight:400;color:#666;">products synced</span>
            </p>

            <?php if ( empty( $locations ) ) : ?>
                <p style="color:#666;">No locations configured yet. <a href="<?php echo esc_url( $loc_tab_url ); ?>">Add a location &rarr;</a></p>
            <?php else : ?>
            <table class="widefat fixed striped" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th style="width:90px;">Products</th>
                        <th style="width:110px;">Cache Warmed</th>
                        <th style="width:110px;">Last Sync</th>
                        <th style="width:180px;">Last Sync Results</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $locations as $loc ) :
                    $name    = $loc['name'] ?? '';
                    $count   = $product_counts[ $name ] ?? 0;
                    $fetched = $cache_by_location[ $name ] ?? null;
                    $sync    = $sync_by_location[ $name ] ?? null;

                    $cache_age = $fetched ? ( time() - strtotime( $fetched ) ) : null;
                    $cache_cls = ! $fetched ? 'color:#b32d2e;' : ( $cache_age > 25 * HOUR_IN_SECONDS ? 'color:#996800;' : 'color:#2a6b2a;' );
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $name ); ?></strong></td>
                        <td><?php echo number_format( $count ); ?></td>
                        <td style="<?php echo $cache_cls; ?>"><?php echo esc_html( $fetched ? $ago( $fetched ) : 'Never' ); ?></td>
                        <td><?php echo esc_html( $sync ? $ago( $sync->started_at ) : 'Never' ); ?></td>
                        <td>
                        <?php if ( $sync ) :
                            $has_errors = $sync->error_count > 0 || $sync->items_missed > 0;
                        ?>
                            <span style="color:#2a6b2a;"><?php echo (int) $sync->items_created; ?> new</span>,
                            <?php echo (int) $sync->items_updated; ?> upd,
                            <?php echo (int) $sync->items_trashed; ?> removed
                            <?php if ( $sync->error_count > 0 ) : ?>
                                &nbsp;<span style="color:#b32d2e;"><?php echo (int) $sync->error_count; ?> err</span>
                            <?php endif; ?>
                            <?php if ( $sync->items_missed > 0 ) : ?>
                                &nbsp;<span style="color:#996800;"><?php echo (int) $sync->items_missed; ?> missed</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color:#666;">No sync on record</span>
                        <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>
</div>

</div>

<script>
jQuery(document).ready(function($) {
    $('#lic-dash-warm-cache').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Warming cache...');
        var $msg = $('#lic-dash-warm-msg');
        $msg.text('').css('color', '');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_warm_cache_now',
            nonce:  lightspeed_connector_ajax.nonce
        })
        .done(function(r) {
            if ( r.success ) {
                $msg.text('Cache warmed successfully.').css('color', '#2a6b2a');
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                $msg.text('Failed: ' + (r.data || 'unknown error')).css('color', '#b32d2e');
                $btn.prop('disabled', false).text('Warm Cache Now');
            }
        })
        .fail(function() {
            $msg.text('Request failed.').css('color', '#b32d2e');
            $btn.prop('disabled', false).text('Warm Cache Now');
        });
    });

    $('#lic-dash-sold-reconcile').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Reconciling...');
        var $msg = $('#lic-dash-sold-reconcile-msg');
        $msg.text('This can take a minute for large catalogs...').css('color', '');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_run_sold_unit_reconciliation',
            nonce:  lightspeed_connector_ajax.nonce
        })
        .done(function(r) {
            if ( r.success ) {
                $msg.text(r.data || 'Reconciliation completed.').css('color', '#2a6b2a');
            } else {
                $msg.text('Failed: ' + (r.data || 'unknown error')).css('color', '#b32d2e');
            }
            $btn.prop('disabled', false).text('Run Sold-Unit Reconciliation Now');
        })
        .fail(function() {
            $msg.text('Request failed.').css('color', '#b32d2e');
            $btn.prop('disabled', false).text('Run Sold-Unit Reconciliation Now');
        });
    });
});
</script>
