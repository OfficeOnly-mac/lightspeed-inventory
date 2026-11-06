<?php
if ( ! defined( 'ABSPATH' ) ) die;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

global $wpdb;

$tabs = [
    'sync-log'         => 'Sync Log',
    'api-errors'       => 'API Errors',
    'queue-ops'        => 'Queue Ops',
    'deleted-products' => 'Deleted Products',
    'delete-cron'      => 'Delete Cron',
    'stock-check'      => 'Stock Check',
    'confirmed-sold'   => 'Confirmed Sold',
    'sold-alerts'      => 'Sold Unit Alerts',
    'stock-debug'      => 'Stock Debug',
    'all-cron'         => 'All Cron Logs',
    'image-report'     => 'Image Report',
];
$active = sanitize_key( $_GET['tab'] ?? 'sync-log' );
if ( ! array_key_exists( $active, $tabs ) ) $active = 'sync-log';

// Fetch data only for the active tab.
$sync_log_table    = $wpdb->prefix . 'lic_sync_log';
$api_error_table   = $wpdb->prefix . 'lic_api_error_log';
$cron_table        = $wpdb->prefix . 'lic_cron_error_logs';
$removed_table     = $wpdb->prefix . 'lic_remove_products_logs';

$rows = [];
switch ( $active ) {
    case 'sync-log':
        $rows = $wpdb->get_results( "SELECT * FROM {$sync_log_table} ORDER BY started_at DESC LIMIT 500" );
        break;
    case 'api-errors':
        $rows = $wpdb->get_results( "SELECT * FROM {$api_error_table} ORDER BY logged_at DESC LIMIT 500" );
        break;
    case 'queue-ops':
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$cron_table} WHERE cron_name IN (%s, %s) ORDER BY logged_at DESC LIMIT 500",
            'lic_process_queue_batch', 'run_product_import_cron'
        ) );
        break;
    case 'deleted-products':
        $rows = $wpdb->get_results( "SELECT * FROM {$removed_table} ORDER BY deleted_at DESC LIMIT 500" );
        break;
    case 'delete-cron':
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$cron_table} WHERE cron_name = %s ORDER BY logged_at DESC LIMIT 500",
            'delete_sold_out_products'
        ) );
        break;
    case 'stock-check':
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$cron_table} WHERE cron_name IN (%s, %s, %s) ORDER BY logged_at DESC LIMIT 500",
            'check_product_exist_in_or_not', 'sold_unit_incremental', 'sold_unit_reconciliation'
        ) );
        break;
    case 'confirmed-sold':
        $confirmed_sold_table = $wpdb->prefix . 'lic_confirmed_sold';
        $rows = $wpdb->get_results( "SELECT * FROM {$confirmed_sold_table} ORDER BY confirmed_at DESC LIMIT 500" );
        break;
    case 'sold-alerts':
        $review_table = $wpdb->prefix . 'lic_sold_review_queue';
        $rows = $wpdb->get_results( "SELECT * FROM {$review_table} WHERE dismissed_at IS NULL ORDER BY detected_at DESC LIMIT 500" );
        break;
    case 'all-cron':
        $filter_cron = sanitize_text_field( $_GET['cron_name_filter'] ?? '' );
        $query       = "SELECT * FROM {$cron_table}";
        if ( $filter_cron ) {
            $query .= $wpdb->prepare( " WHERE cron_name = %s", $filter_cron );
        }
        $query .= " ORDER BY logged_at DESC LIMIT 500";
        $rows = $wpdb->get_results( $query );
        $cron_names = $wpdb->get_col( "SELECT DISTINCT cron_name FROM {$cron_table}" );
        break;
}
?>
<div class="wrap">
<?php lic_sync_disabled_notice(); ?>
<h1>Logs &amp; Reports</h1>

<nav class="nav-tab-wrapper" style="margin-bottom:0;">
<?php foreach ( $tabs as $slug => $label ) :
    $url   = esc_url( add_query_arg( [ 'page' => 'lic-logs', 'tab' => $slug ], admin_url( 'admin.php' ) ) );
    $class = $slug === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
?>
    <a href="<?php echo $url; ?>" class="<?php echo $class; ?>"><?php echo esc_html( $label ); ?></a>
<?php endforeach; ?>
</nav>

<div class="tab-content" style="margin-top:20px;">
<?php

// ── Image Report tab ──────────────────────────────────────────────────────────
if ( $active === 'image-report' ) :
    lic_image_report();

// ── All Cron Logs tab ─────────────────────────────────────────────────────────
elseif ( $active === 'all-cron' ) :
    $log_label = 'All Cron Logs';
    $filter_url = esc_url( add_query_arg( [ 'page' => 'lic-logs', 'tab' => 'all-cron' ], admin_url( 'admin.php' ) ) );
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="all_cron_logs" data-label="<?php echo esc_attr( $log_label ); ?>">Clear All</button>
    </div>
    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:12px;">
        <input type="hidden" name="page" value="lic-logs">
        <input type="hidden" name="tab"  value="all-cron">
        <label>Filter by cron:
            <select name="cron_name_filter" onchange="this.form.submit()">
                <option value="">— All —</option>
                <?php foreach ( $cron_names as $cn ) : ?>
                    <option value="<?php echo esc_attr( $cn ); ?>" <?php selected( $filter_cron, $cn ); ?>><?php echo esc_html( $cn ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr><th style="width:160px;">Time</th><th style="width:200px;">Cron Name</th><th>Message</th><th style="width:140px;">Start</th><th style="width:140px;">End</th></tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log->logged_at ); ?></td>
                <td><?php echo esc_html( $log->cron_name ); ?></td>
                <td><?php echo esc_html( $log->error_message ); ?></td>
                <td><?php echo esc_html( $log->start_time ?? '—' ); ?></td>
                <td><?php echo esc_html( $log->end_time ?? '—' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No cron logs available.</p>
    <?php endif; ?>

<?php

// ── Sync Log tab ──────────────────────────────────────────────────────────────
elseif ( $active === 'sync-log' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="sync_summaries" data-label="Sync Log">Clear All</button>
    </div>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed" cellspacing="0">
        <thead><tr>
            <th style="width:120px;">Location</th>
            <th style="width:145px;">Started</th>
            <th style="width:145px;">Finished</th>
            <th style="width:70px;">Processed</th>
            <th style="width:60px;">Created</th>
            <th style="width:60px;">Updated</th>
            <th style="width:60px;">Trashed</th>
            <th style="width:60px;">Ignored</th>
            <th style="width:55px;">Errors</th>
            <th style="width:55px;">Missed</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) :
            $has_errors = (int) $log->error_count  > 0;
            $has_missed = (int) $log->items_missed > 0;
            $row_style  = $has_errors ? ' style="background:#fff0f0;"' : ( $has_missed ? ' style="background:#fff8e1;"' : '' );
        ?>
            <tr<?php echo $row_style; ?>>
                <td><?php echo esc_html( $log->location_name ); ?></td>
                <td><?php echo esc_html( $log->started_at ); ?></td>
                <td><?php echo esc_html( $log->finished_at ?? '—' ); ?></td>
                <td><?php echo esc_html( $log->items_processed ); ?></td>
                <td><?php echo esc_html( $log->items_created ); ?></td>
                <td><?php echo esc_html( $log->items_updated ); ?></td>
                <td><?php echo esc_html( $log->items_trashed ); ?></td>
                <td><?php echo esc_html( $log->items_ignored ); ?></td>
                <td><?php echo $has_errors ? '<strong>' . esc_html( $log->error_count ) . '</strong>' : '0'; ?></td>
                <td><?php echo $has_missed ? '<strong style="color:#d63638;">' . esc_html( $log->items_missed ) . '</strong>' : '0'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No sync summaries yet.</p>
    <?php endif; ?>

<?php

// ── API Errors tab ────────────────────────────────────────────────────────────
elseif ( $active === 'api-errors' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="api_errors" data-label="API Errors">Clear All</button>
    </div>
    <p style="color:#666;margin-bottom:10px;">API errors from cache warm and incremental update operations. Most recent 500 entries.</p>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr>
            <th style="width:145px;">Logged At</th>
            <th style="width:120px;">Location</th>
            <th style="width:90px;">Context</th>
            <th style="width:60px;">HTTP</th>
            <th>Message</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) :
            $is_transport = ( (int) $log->http_code === 0 );
            $row_style    = $is_transport ? ' style="background:#fff0f0;"' : ' style="background:#fff8e1;"';
        ?>
            <tr<?php echo $row_style; ?>>
                <td><?php echo esc_html( $log->logged_at ); ?></td>
                <td><?php echo esc_html( $log->location_name ); ?></td>
                <td><?php echo esc_html( $log->context ); ?></td>
                <td><?php echo $is_transport ? '—' : esc_html( $log->http_code ); ?></td>
                <td><?php echo esc_html( $log->error_message ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No API errors logged.</p>
    <?php endif; ?>

<?php

// ── Queue Ops tab ─────────────────────────────────────────────────────────────
elseif ( $active === 'queue-ops' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="queue_operations" data-label="Queue Operations">Clear All</button>
    </div>
    <p style="color:#666;margin-bottom:10px;">Batch processor events and import batches. Most recent 500 entries.</p>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr>
            <th style="width:160px;">Logged At</th>
            <th style="width:200px;">Cron</th>
            <th>Message</th>
            <th style="width:140px;">Start</th>
            <th style="width:140px;">End</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) :
            $is_error = (
                stripos( $log->error_message, 'stuck' ) !== false ||
                stripos( $log->error_message, 'reset' ) !== false ||
                stripos( $log->error_message, 'error' ) !== false ||
                stripos( $log->error_message, 'critical' ) !== false
            );
            $row_style = $is_error ? ' style="background:#fff8e1;"' : '';
        ?>
            <tr<?php echo $row_style; ?>>
                <td><?php echo esc_html( $log->logged_at ); ?></td>
                <td><?php echo esc_html( $log->cron_name ); ?></td>
                <td><?php echo esc_html( $log->error_message ); ?></td>
                <td><?php echo esc_html( $log->start_time ?? '—' ); ?></td>
                <td><?php echo esc_html( $log->end_time ?? '—' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No queue operation logs yet.</p>
    <?php endif; ?>

<?php

// ── Deleted Products tab ──────────────────────────────────────────────────────
elseif ( $active === 'deleted-products' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="deleted_products" data-label="Deleted Products">Clear All</button>
    </div>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr>
            <th>ID</th><th>SKU</th><th>Name</th><th>Status</th><th>Category</th><th>Update Date</th><th>Deleted At</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log->id ); ?></td>
                <td><?php echo esc_html( $log->sku ); ?></td>
                <td><?php echo esc_html( $log->name ); ?></td>
                <td><?php echo esc_html( $log->status ); ?></td>
                <td><?php echo esc_html( $log->category ); ?></td>
                <td><?php echo esc_html( $log->update_date ); ?></td>
                <td><?php echo esc_html( $log->deleted_at ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No deleted product logs available.</p>
    <?php endif; ?>

<?php

// ── Delete Cron tab ───────────────────────────────────────────────────────────
elseif ( $active === 'delete-cron' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="delete_cron" data-label="Delete Cron">Clear All</button>
    </div>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr>
            <th style="width:160px;">Time</th><th style="width:140px;">Start</th><th style="width:140px;">End</th><th>Message</th><th style="width:120px;">Products Deleted</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) :
            $total_deleted = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$removed_table} WHERE deleted_at BETWEEN %s AND %s",
                $log->start_time, $log->end_time
            ) );
        ?>
            <tr>
                <td><?php echo esc_html( $log->logged_at ); ?></td>
                <td><?php echo esc_html( $log->start_time ?? '—' ); ?></td>
                <td><?php echo esc_html( $log->end_time ?? '—' ); ?></td>
                <td><?php echo esc_html( $log->error_message ); ?></td>
                <td><?php echo esc_html( $total_deleted ?? 0 ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No delete cron logs available.</p>
    <?php endif; ?>

<?php

// ── Stock Check tab ───────────────────────────────────────────────────────────
elseif ( $active === 'stock-check' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="stock_check" data-label="Stock Check">Clear All</button>
    </div>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr>
            <th style="width:160px;">Time</th><th style="width:140px;">Start</th><th style="width:140px;">End</th><th>Message</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log->logged_at ); ?></td>
                <td><?php echo esc_html( $log->start_time ?? '—' ); ?></td>
                <td><?php echo esc_html( $log->end_time ?? '—' ); ?></td>
                <td><?php echo esc_html( $log->error_message ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No stock check logs available.</p>
    <?php endif; ?>

<?php

// ── Confirmed Sold tab ────────────────────────────────────────────────────────
elseif ( $active === 'confirmed-sold' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="confirmed_sold" data-label="Confirmed Sold">Clear All</button>
    </div>
    <p style="color:#666;margin-bottom:10px;">
        Stock numbers confirmed sold via the Lightspeed SoldUnit endpoint. The matching product
        is trashed immediately on confirmation (not just marked out-of-stock) — an out-of-stock-
        but-still-published product's URL stays directly reachable and crawlable, so it's removed
        outright instead of waiting on the daily cleanup cron. Once a stock number is listed here,
        the sync will never republish it — even if it's still sitting in the Unit feed — until
        it's removed from this list. Only un-flag a row if you're sure it was flagged in error
        (e.g. a data-entry correction on the dealer's side). Un-flagging restores the product from
        trash if it was trashed as a result — its images will repopulate on the next sync, not
        immediately.
    </p>

    <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:16px;max-width:600px;">
        <h4 style="margin:0 0 8px;">Bulk un-flag &amp; restore</h4>
        <p style="color:#666;margin:0 0 8px;font-size:13px;">Paste stock numbers (space, comma, or newline separated) to un-flag and restore all at once — e.g. after finding a batch that was wrongly confirmed sold.</p>
        <textarea id="lic-bulk-unflag-input" rows="4" style="width:100%;" placeholder="04581, 05125, 05525, ..."></textarea>
        <p style="margin:8px 0 0;">
            <button class="button button-primary" id="lic-bulk-unflag-btn">Un-flag &amp; Restore All</button>
            <span id="lic-bulk-unflag-msg" style="margin-left:8px;font-size:12px;"></span>
        </p>
    </div>

    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr>
            <th>Stock Number</th><th style="width:150px;">VIN</th><th style="width:90px;">Status</th><th style="width:130px;">Location</th><th style="width:140px;">Source</th><th style="width:140px;">Confirmed At</th><th style="width:90px;">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log->stock_number ); ?></td>
                <td><?php echo esc_html( $log->vin ?: '—' ); ?></td>
                <td><?php echo esc_html( $log->unit_status ?: '—' ); ?></td>
                <td><?php echo esc_html( $log->location_name ); ?></td>
                <td><?php echo esc_html( $log->source ); ?></td>
                <td><?php echo esc_html( $log->confirmed_at ); ?></td>
                <td><button class="button lic-unflag-sold-btn" data-id="<?php echo esc_attr( $log->id ); ?>" data-sku="<?php echo esc_attr( $log->stock_number ); ?>">Un-flag</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No stock numbers confirmed sold yet.</p>
    <?php endif; ?>

<?php

// ── Sold Unit Alerts tab ──────────────────────────────────────────────────────
elseif ( $active === 'sold-alerts' ) :
?>
    <div style="margin-bottom:12px;">
        <button class="button lic-clear-log-btn" data-log-type="sold_alerts" data-label="Sold Unit Alerts">Clear All</button>
        <button class="button button-primary lic-bulk-review-sold-alerts-btn" disabled>Mark Selected Reviewed</button>
    </div>
    <p style="color:#666;margin-bottom:10px;">
        Stock numbers that need a human decision. <strong>vin_mismatch</strong>: a stock number
        previously confirmed sold now shows a different VIN in the Unit feed — treated as a
        different vehicle and already published/live; reviewing here just acknowledges it.
        <strong>vin_reappeared</strong>: the exact same VIN that was confirmed sold has shown up
        in the Unit feed again (e.g. a trade-in) — it stays blocked from republishing until you
        un-flag it on the Confirmed Sold tab; "Mark Reviewed" here only dismisses the alert.
        Reviewing a row stays put as long as the same stock number/VIN pair keeps showing up — it
        only resurfaces here if Lightspeed's data for it actually changes.
    </p>
    <?php if ( ! empty( $rows ) ) : ?>
    <table class="widefat fixed striped" cellspacing="0">
        <thead><tr>
            <th style="width:30px;"><input type="checkbox" id="lic-sold-alerts-select-all"></th>
            <th>Stock Number</th><th style="width:140px;">Event</th><th style="width:160px;">Old VIN</th><th style="width:160px;">New VIN</th><th style="width:150px;">Detected At</th><th style="width:110px;">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $rows as $log ) :
            $row_style = $log->event_type === 'vin_mismatch' ? ' style="background:#fff8e1;"' : ' style="background:#fff0f0;"';
        ?>
            <tr<?php echo $row_style; ?>>
                <td><input type="checkbox" class="lic-sold-alert-checkbox" value="<?php echo esc_attr( $log->id ); ?>"></td>
                <td><?php echo esc_html( $log->stock_number ); ?></td>
                <td><?php echo esc_html( $log->event_type ); ?></td>
                <td><?php echo esc_html( $log->old_vin ?: '—' ); ?></td>
                <td><?php echo esc_html( $log->new_vin ?: '—' ); ?></td>
                <td><?php echo esc_html( $log->detected_at ); ?></td>
                <td><button class="button lic-review-sold-alert-btn" data-id="<?php echo esc_attr( $log->id ); ?>">Mark Reviewed</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p>No sold-unit alerts pending.</p>
    <?php endif; ?>

<?php

// ── Stock Debug tab ───────────────────────────────────────────────────────────
elseif ( $active === 'stock-debug' ) :
?>
    <p style="color:#666;margin-bottom:10px;">
        Live-looks-up a single stock number against <strong>Unit</strong> and
        <strong>SoldUnit</strong> for every configured location (bypasses the cache — this hits
        the API directly), plus its Confirmed Sold / Sold Unit Alerts state, and what the sync
        logic would currently decide for it. Use this to confirm exactly why a specific unit is
        or isn't showing on the site.
    </p>
    <p>
        <input type="text" id="lic-debug-stock-number" placeholder="Stock number, e.g. 00477" style="width:240px;">
        <button class="button button-primary" id="lic-debug-lookup-btn">Look Up</button>
        <span id="lic-debug-status" style="margin-left:8px;font-size:12px;"></span>
    </p>
    <div id="lic-debug-results" style="margin-top:16px;"></div>

<?php endif; ?>

</div><!-- .tab-content -->
</div><!-- .wrap -->

<script>
jQuery(document).ready(function($) {
    $('.lic-clear-log-btn').on('click', function() {
        var $btn     = $(this);
        var label    = $btn.data('label') || 'this log';
        var log_type = $btn.data('log-type');
        if (!confirm('Clear all entries from ' + label + '? This cannot be undone.')) return;
        $btn.prop('disabled', true).text('Clearing…');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:   'lic_clear_log',
            nonce:    lightspeed_connector_ajax.nonce,
            log_type: log_type
        }, function(r) {
            if (r.success) {
                location.reload();
            } else {
                alert('Error clearing log.');
                $btn.prop('disabled', false).text('Clear All');
            }
        });
    });

    $('.lic-unflag-sold-btn').on('click', function() {
        var $btn = $(this);
        var id   = $btn.data('id');
        var sku  = $btn.data('sku');
        if (!confirm('Un-flag stock number "' + sku + '" as sold? It will become eligible to be republished on the next sync if it still appears in the Unit feed.')) return;
        $btn.prop('disabled', true).text('Removing…');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_unflag_confirmed_sold',
            nonce:  lightspeed_connector_ajax.nonce,
            id:     id
        }, function(r) {
            if (r.success) {
                location.reload();
            } else {
                alert('Error removing flag.');
                $btn.prop('disabled', false).text('Un-flag');
            }
        });
    });

    $('#lic-bulk-unflag-btn').on('click', function() {
        var $btn  = $(this);
        var $msg  = $('#lic-bulk-unflag-msg');
        var input = $('#lic-bulk-unflag-input').val().trim();
        if (!input) {
            $msg.text('Paste at least one stock number.').css('color', '#b32d2e');
            return;
        }
        if (!confirm('Un-flag and restore all listed stock numbers? Any that were trashed will be restored to publish (images repopulate on the next sync, not immediately).')) return;
        $btn.prop('disabled', true).text('Processing…');
        $msg.text('').css('color', '');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:         'lic_bulk_unflag_confirmed_sold',
            nonce:          lightspeed_connector_ajax.nonce,
            stock_numbers:  input
        })
        .done(function(r) {
            if (r.success) {
                $msg.text(r.data || 'Done.').css('color', '#2a6b2a');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $msg.text('Error: ' + (r.data || 'unknown error')).css('color', '#b32d2e');
                $btn.prop('disabled', false).text('Un-flag & Restore All');
            }
        })
        .fail(function() {
            $msg.text('Request failed.').css('color', '#b32d2e');
            $btn.prop('disabled', false).text('Un-flag & Restore All');
        });
    });

    $('.lic-review-sold-alert-btn').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Removing…');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_review_sold_alert',
            nonce:  lightspeed_connector_ajax.nonce,
            id:     $btn.data('id')
        }, function(r) {
            if (r.success) {
                location.reload();
            } else {
                alert('Error marking reviewed.');
                $btn.prop('disabled', false).text('Mark Reviewed');
            }
        });
    });

    function lic_update_bulk_review_btn() {
        $('.lic-bulk-review-sold-alerts-btn').prop('disabled', $('.lic-sold-alert-checkbox:checked').length === 0);
    }

    $('#lic-sold-alerts-select-all').on('change', function() {
        $('.lic-sold-alert-checkbox').prop('checked', $(this).prop('checked'));
        lic_update_bulk_review_btn();
    });

    $(document).on('change', '.lic-sold-alert-checkbox', function() {
        if (!$(this).prop('checked')) {
            $('#lic-sold-alerts-select-all').prop('checked', false);
        }
        lic_update_bulk_review_btn();
    });

    $('.lic-bulk-review-sold-alerts-btn').on('click', function() {
        var ids = $('.lic-sold-alert-checkbox:checked').map(function() { return $(this).val(); }).get();
        if (!ids.length) return;
        var $btn = $(this).prop('disabled', true).text('Marking…');
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action: 'lic_bulk_review_sold_alerts',
            nonce:  lightspeed_connector_ajax.nonce,
            ids:    ids
        }, function(r) {
            if (r.success) {
                location.reload();
            } else {
                alert('Error: ' + (r.data || 'unknown error'));
                $btn.prop('disabled', false).text('Mark Selected Reviewed');
            }
        });
    });

    function lic_esc(s) {
        return $('<div>').text(s == null || s === '' ? '—' : s).html();
    }

    function lic_render_records(title, records, error) {
        var html = '<h4 style="margin:10px 0 4px;">' + title + '</h4>';
        if (error) {
            return html + '<p style="color:#b32d2e;">' + lic_esc(error) + '</p>';
        }
        if (!records || !records.length) {
            return html + '<p style="color:#666;">No records found.</p>';
        }
        html += '<table class="widefat fixed striped" cellspacing="0"><thead><tr>' +
            '<th>Stock Number</th><th>VIN</th><th>UnitStatus</th><th>WebUnit</th><th>Last Updated</th>' +
            '</tr></thead><tbody>';
        records.forEach(function(r) {
            html += '<tr>' +
                '<td>' + lic_esc(r.StockNumber) + '</td>' +
                '<td>' + lic_esc(r.VIN) + '</td>' +
                '<td>' + lic_esc(r.UnitStatus) + '</td>' +
                '<td>' + lic_esc(r.WebUnit) + '</td>' +
                '<td>' + lic_esc(r.lastupdatedate) + '</td>' +
                '</tr>';
        });
        return html + '</tbody></table>';
    }

    function lic_render_debug_results(data) {
        var html = '<h3>Results for stock number "' + lic_esc(data.stock_number) + '"</h3>';

        data.locations.forEach(function(loc) {
            html += '<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:14px;">';
            html += '<h3 style="margin-top:0;">' + lic_esc(loc.location_name) + '</h3>';
            html += lic_render_records('Unit', loc.unit_records, loc.unit_error);
            html += lic_render_records('SoldUnit', loc.sold_records, loc.sold_error);
            html += '</div>';
        });

        html += '<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:14px;">';
        html += '<h3 style="margin-top:0;">WooCommerce product</h3>';
        if (data.wc_product) {
            var isPublished = data.wc_product.post_status === 'publish';
            html += '<p>Product ID <strong>' + lic_esc(data.wc_product.product_id) + '</strong>, status <strong>' +
                lic_esc(data.wc_product.post_status) + '</strong>, stock status <strong>' +
                lic_esc(data.wc_product.stock_status) + '</strong>.</p>';
            if (!isPublished) {
                html += '<p style="color:#b32d2e;">Not published — the monthly/manual reconciliation sweep only checks SKUs belonging to currently-published products, so this stock number is never included in that sweep\'s batches regardless of what SoldUnit says.</p>';
            }
        } else {
            html += '<p style="color:#b32d2e;">No WooCommerce product (in any status) has this SKU — the reconciliation sweep never checks it, since it only scopes to SKUs already in WooCommerce.</p>';
        }
        html += '</div>';

        html += '<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:14px;">';
        html += '<h3 style="margin-top:0;">Confirmed Sold record</h3>';
        if (data.confirmed_sold) {
            html += '<p>Stock <strong>' + lic_esc(data.confirmed_sold.stock_number) + '</strong> is flagged sold under VIN <strong>' +
                lic_esc(data.confirmed_sold.vin) + '</strong>, SoldUnit status <strong>' + lic_esc(data.confirmed_sold.unit_status) +
                '</strong> (location: ' + lic_esc(data.confirmed_sold.location_name) +
                ', source: ' + lic_esc(data.confirmed_sold.source) + ', confirmed: ' + lic_esc(data.confirmed_sold.confirmed_at) + ').</p>';
        } else {
            html += '<p style="color:#666;">Not flagged as confirmed sold.</p>';
        }
        html += '</div>';

        html += '<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:14px;">';
        html += '<h3 style="margin-top:0;">Pending review-queue alerts</h3>';
        if (data.review_queue && data.review_queue.length) {
            data.review_queue.forEach(function(row) {
                html += '<p>' + lic_esc(row.event_type) + ' — old VIN ' + lic_esc(row.old_vin) + ', new VIN ' + lic_esc(row.new_vin) +
                    ' (detected ' + lic_esc(row.detected_at) + ')</p>';
            });
        } else {
            html += '<p style="color:#666;">None.</p>';
        }
        html += '</div>';

        html += '<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;">';
        html += '<h3 style="margin-top:0;">What the sync would decide right now</h3>';
        if (data.decisions && data.decisions.length) {
            data.decisions.forEach(function(d) {
                var color = d.blocked ? '#b32d2e' : '#2a6b2a';
                var verdict = d.blocked ? 'BLOCKED (stays out of stock)' : 'NOT blocked (would publish/instock)';
                html += '<p>' + lic_esc(d.location) + ', VIN ' + lic_esc(d.vin) + ': <strong style="color:' + color + ';">' + verdict + '</strong></p>';
            });
        } else {
            html += '<p style="color:#666;">No Unit records to evaluate — nothing currently in Unit for this stock number.</p>';
        }
        html += '</div>';

        html += '<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-top:14px;">';
        html += '<h3 style="margin-top:0;">Reconciliation batch simulation</h3>';
        html += '<p style="color:#666;">Runs the exact batched SoldUnit request this stock number would fall into during a real reconciliation sweep, so a batching problem can be observed directly.</p>';
        var sim = data.batch_simulation;
        if (sim) {
            html += '<p>' + lic_esc(sim.total_published_skus) + ' published SKU(s) chunked into ' + lic_esc(sim.total_batches) + ' batch(es) of 25.</p>';
            if (sim.target_batch_index === null || sim.target_batch_index === undefined) {
                html += '<p style="color:#b32d2e;"><strong>Not in any batch</strong> — no currently-published WooCommerce product has this exact SKU, so the reconciliation sweep never queries SoldUnit for it at all, regardless of what SoldUnit contains.</p>';
            } else {
                html += '<p>Falls into batch #' + lic_esc(sim.target_batch_index) + '.</p>';
                (sim.locations || []).forEach(function(loc) {
                    html += '<div style="border-top:1px solid #eee;padding-top:8px;margin-top:8px;">';
                    html += '<p><strong>' + lic_esc(loc.location_name) + '</strong> — batch size ' + lic_esc(loc.batch_size) + ', filter length ' + lic_esc(loc.filter_length) + ' chars</p>';
                    if (loc.error) {
                        html += '<p style="color:#b32d2e;">' + lic_esc(loc.error) + '</p>';
                    } else {
                        var found = loc.contains_target;
                        var color = found ? '#2a6b2a' : '#b32d2e';
                        var verdict = found ? 'Target stock number WAS present in the raw response.' : 'Target stock number was NOT present in the raw response.';
                        html += '<p>Raw items returned: <strong>' + lic_esc(loc.raw_item_count) + '</strong>. <strong style="color:' + color + ';">' + verdict + '</strong></p>';
                    }
                    if (loc.request_url) {
                        html += '<p style="font-size:11px;color:#888;word-break:break-all;">' + lic_esc(loc.request_url) + '</p>';
                    }
                    html += '</div>';
                });
            }
        } else {
            html += '<p style="color:#666;">No simulation data returned.</p>';
        }
        html += '</div>';

        return html;
    }

    $('#lic-debug-lookup-btn').on('click', function() {
        var stock_number = $('#lic-debug-stock-number').val().trim();
        var $status = $('#lic-debug-status');
        if (!stock_number) {
            $status.text('Enter a stock number.').css('color', '#b32d2e');
            return;
        }
        var $btn = $(this).prop('disabled', true).text('Looking up…');
        $status.text('Querying Unit and SoldUnit live for every location — this can take a few seconds...').css('color', '');
        $('#lic-debug-results').empty();
        $.post(lightspeed_connector_ajax.ajaxurl, {
            action:       'lic_debug_lookup_stock_number',
            nonce:        lightspeed_connector_ajax.nonce,
            stock_number: stock_number
        })
        .done(function(r) {
            if (r.success) {
                $status.text('');
                $('#lic-debug-results').html(lic_render_debug_results(r.data));
            } else {
                $status.text('Error: ' + (r.data || 'unknown error')).css('color', '#b32d2e');
            }
        })
        .fail(function() {
            $status.text('Request failed.').css('color', '#b32d2e');
        })
        .always(function() {
            $btn.prop('disabled', false).text('Look Up');
        });
    });
});
</script>
