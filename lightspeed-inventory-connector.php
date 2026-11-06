<?php 

/*
Plugin Name: Lightspeed API Connector
Description: Connect and synchronize Lightspeed inventory with WooCommerce.
Version: 3.2.22
Author: BNR Digital Agency
Author URI: https://bnrbranding.com
License: GPL2
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants
define( 'LIC_VERSION', '3.2.22' );
define( 'LIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once LIC_PLUGIN_DIR . 'includes/class-wc-mapper.php';
require_once LIC_PLUGIN_DIR . 'includes/class-settings.php';
require_once LIC_PLUGIN_DIR . 'includes/class-lic-cron-manager.php';
require_once LIC_PLUGIN_DIR . 'includes/class-lic-image-handler.php';
require_once LIC_PLUGIN_DIR . 'includes/class-lic-importer.php';
require_once LIC_PLUGIN_DIR . 'includes/class-lic-ajax-handler.php';
require_once LIC_PLUGIN_DIR . 'public/class-lic-price-display.php';
require_once LIC_PLUGIN_DIR . 'public/class-lic-archive-query.php';
require_once LIC_PLUGIN_DIR . 'public/class-lic-shortcodes.php';
require_once LIC_PLUGIN_DIR . 'admin/image-report-page.php';


// Initialize the plugin
function lic_init() {
    Lightspeed_Settings::init();
    LIC_Cron_Manager::init();
    LIC_Image_Handler::init();
    LIC_Importer::init();
    LIC_Ajax_Handler::init();
    LIC_Price_Display::init();
    LIC_Archive_Query::init();
    LIC_Shortcodes::init();
}
add_action( 'plugins_loaded', 'lic_init' );

/* ***************************************** */
// Database table creation
define( 'LIC_DB_VERSION', '1.7' );

function lic_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$wpdb->prefix}lic_api_cache (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  location_name varchar(100) NOT NULL DEFAULT '',
  api_data longtext NOT NULL,
  fetched_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY location_name (location_name),
  KEY fetched_at (fetched_at)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}lic_cron_error_logs (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  cron_name varchar(100) NOT NULL DEFAULT '',
  error_message text NOT NULL,
  start_time datetime DEFAULT NULL,
  end_time datetime DEFAULT NULL,
  logged_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY cron_name (cron_name),
  KEY logged_at (logged_at)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}lic_remove_products_logs (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  sku varchar(100) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  status varchar(50) NOT NULL DEFAULT '',
  category varchar(255) NOT NULL DEFAULT '',
  update_date datetime DEFAULT NULL,
  deleted_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY deleted_at (deleted_at)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}lic_import_queue (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  location_name varchar(100) NOT NULL DEFAULT '',
  current_offset int(11) NOT NULL DEFAULT 0,
  batch_size int(11) NOT NULL DEFAULT 25,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  last_processed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY location_name (location_name)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}lic_sync_log (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  location_name varchar(100) NOT NULL DEFAULT '',
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  items_processed int(11) NOT NULL DEFAULT 0,
  items_created int(11) NOT NULL DEFAULT 0,
  items_updated int(11) NOT NULL DEFAULT 0,
  items_trashed int(11) NOT NULL DEFAULT 0,
  items_ignored int(11) NOT NULL DEFAULT 0,
  error_count int(11) NOT NULL DEFAULT 0,
  items_missed int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY location_name (location_name),
  KEY started_at (started_at)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}lic_api_error_log (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  location_name varchar(100) NOT NULL DEFAULT '',
  context varchar(20) NOT NULL DEFAULT '',
  http_code smallint(6) NOT NULL DEFAULT 0,
  error_message text NOT NULL,
  logged_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY location_name (location_name),
  KEY logged_at (logged_at)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}lic_confirmed_sold (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_number varchar(100) NOT NULL DEFAULT '',
  vin varchar(100) NOT NULL DEFAULT '',
  unit_status varchar(10) NOT NULL DEFAULT '',
  location_name varchar(100) NOT NULL DEFAULT '',
  source varchar(50) NOT NULL DEFAULT '',
  confirmed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY stock_number (stock_number)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}lic_sold_review_queue (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_number varchar(100) NOT NULL DEFAULT '',
  old_vin varchar(100) NOT NULL DEFAULT '',
  new_vin varchar(100) NOT NULL DEFAULT '',
  event_type varchar(30) NOT NULL DEFAULT '',
  detected_at datetime NOT NULL,
  dismissed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY stock_event (stock_number, event_type)
) $charset_collate;";

    dbDelta( $sql );
    update_option( 'lic_db_version', LIC_DB_VERSION );
}

register_activation_hook( __FILE__, 'lic_create_tables' );

function lic_maybe_upgrade_tables() {
    if ( get_option( 'lic_db_version' ) !== LIC_DB_VERSION ) {
        lic_create_tables();
    }
}
add_action( 'plugins_loaded', 'lic_maybe_upgrade_tables' );
/* ***************************************** */

/* ***************************************** */
// Add admin menus
function lic_add_admin_menus() {
    add_menu_page(
        'Lightspeed Inventory Connector',
        'Lightspeed API Connector',
        'manage_options',
        'lic-main-menu',
        'lic_dashboard_page',
        'dashicons-admin-generic'
    );

    add_submenu_page(
        'lic-main-menu',
        'Settings',
        'Settings',
        'manage_options',
        'lic-settings',
        'lic_settings_tabbed_page'
    );

    add_submenu_page(
        'lic-main-menu',
        'Import',
        'Import',
        'manage_options',
        'lic-import',
        'lic_import_page'
    );

    add_submenu_page(
        'lic-main-menu',
        'Logs &amp; Reports',
        'Logs &amp; Reports',
        'manage_options',
        'lic-logs',
        'lic_logs_tabbed_page'
    );


}
add_action( 'admin_menu', 'lic_add_admin_menus' );

// Output a sync-disabled banner directly inside plugin pages.
// Called manually in each page template since admin_notices is suppressed site-wide.
function lic_sync_disabled_notice() {
    if ( get_option( 'lic_sync_enabled', '1' ) !== '0' ) return;
    $url = esc_url( admin_url( 'admin.php?page=lic-settings&tab=sync' ) );
    echo '<div style="background:#fff0f0;border:2px solid #d63638;border-radius:4px;padding:14px 18px;margin:16px 0;display:flex;align-items:center;gap:14px;">
        <span class="dashicons dashicons-warning" style="font-size:32px;width:32px;height:32px;color:#d63638;flex-shrink:0;"></span>
        <div>
            <strong style="font-size:15px;color:#d63638;">Inventory Sync is disabled.</strong>
            <span style="margin-left:8px;font-size:13px;">No new imports or cache updates will run until sync is re-enabled.</span>
            <a href="' . $url . '" style="margin-left:14px;font-size:13px;">Enable sync &rarr;</a>
        </div>
    </div>';
}
// Admin menus end!!
/* ***************************************** */

/* ***************************************** */
// Callback functions for admin pages
function lic_dashboard_page() {
    include LIC_PLUGIN_DIR . 'admin/dashboard-page.php';
}
function lic_import_page() {
    include LIC_PLUGIN_DIR . 'admin/import-tabs-page.php';
}
function lic_logs_tabbed_page() {
    include LIC_PLUGIN_DIR . 'admin/logs-tabs-page.php';
}
function lic_settings_tabbed_page() {
    include LIC_PLUGIN_DIR . 'admin/settings-tabs-page.php';
}
// Callback functions for admin pages end
/* ***************************************** */

/* ***************************************** */
// Activation and Deactivation hooks
function lic_activate() {
    if ( ! wp_next_scheduled( 'delete_sold_out_products_event' ) ) {
        $first_run = strtotime( 'tomorrow 02:00:00 UTC' );
        wp_schedule_event( $first_run, 'daily', 'delete_sold_out_products_event' );
    }
    if ( ! wp_next_scheduled( 'lic_log_cleanup_cron' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 03:00:00 UTC' ), 'daily', 'lic_log_cleanup_cron' );
    }
}
register_activation_hook( __FILE__, 'lic_activate' );

function lic_deactivate() {
    wp_clear_scheduled_hook( 'delete_sold_out_products_event' );
    wp_clear_scheduled_hook( 'lic_log_cleanup_cron' );
    wp_clear_scheduled_hook( 'lic_inventory_update' );
    wp_clear_scheduled_hook( 'lic_sync_health_check_cron' );
    wp_clear_scheduled_hook( 'lic_sold_unit_check_cron' );
    wp_clear_scheduled_hook( 'lic_sold_unit_reconciliation_cron' );
}
register_deactivation_hook( __FILE__, 'lic_deactivate' );
// Activation and Deactivation hooks end
/* ***************************************** */

/* ***************************************** */
// Uninstall cleanup lives in uninstall.php — WordPress runs that file instead of firing
// register_uninstall_hook() when it's present, so a hook registration here would be dead code.
/* ***************************************** */

/* ***************************************** */
// Enqueue LIC CSS/Scripts and localize AJAX URL and nonce
function enqueue_lic_scripts() {
    // Only load on this plugin's own admin pages. Matched on the 'page' query var
    // (stable, plugin-defined slugs) rather than the WP-computed $hook suffix, since
    // the latter is derived from the top-level menu title and would silently break
    // if that title is ever renamed.
    $plugin_pages = [
        'lic-main-menu',
        'lic-settings',
        'lic-import',
        'lic-logs',
        'lic_wc_mapper',
    ];
    $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( ! in_array( $current_page, $plugin_pages, true ) ) {
        return;
    }

    // Enqueue scripts
    wp_enqueue_script( 'lightspeed-connector-admin-script', plugin_dir_url( __FILE__ ) . 'assets/js/lic_admin.js', array( 'jquery' ), LIC_VERSION, true );

    // Localize script with AJAX URL and nonce
    wp_localize_script( 'lightspeed-connector-admin-script', 'lightspeed_connector_ajax', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'lic_nonce' ),
    ));

    // Enqueue styles
    wp_enqueue_style( 'admin_css', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', [], LIC_VERSION );
}
add_action('admin_enqueue_scripts', 'enqueue_lic_scripts');
// Enqueue LIC CSS/Scripts and localize AJAX URL and nonce End
/* ***************************************** */

/* ***************************************** */
// C-16: per-product sync timestamp column and filter in WooCommerce product list

add_filter('manage_edit-product_columns', function ($columns) {
    $columns['lic_last_synced'] = 'LIC Last Synced';
    return $columns;
});

add_action('manage_product_posts_custom_column', function ($column, $post_id) {
    if ($column !== 'lic_last_synced') return;
    $synced = get_post_meta($post_id, '_lic_last_synced', true);
    echo $synced ? esc_html($synced) : 'â€”';
}, 10, 2);

add_filter('manage_edit-product_sortable_columns', function ($columns) {
    $columns['lic_last_synced'] = 'lic_last_synced';
    return $columns;
});

add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('post_type') !== 'product') return;

    // Handle column sorting
    if ($query->get('orderby') === 'lic_last_synced') {
        $query->set('meta_key', '_lic_last_synced');
        $query->set('orderby', 'meta_value');
    }

    // Handle "not synced in N days" filter from the dropdown
    $stale_days = isset($_GET['lic_stale_days']) ? (int) $_GET['lic_stale_days'] : 0;
    if ($stale_days > 0) {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$stale_days} days"));
        $existing  = (array) $query->get('meta_query');
        $existing[] = [
            'relation' => 'OR',
            [
                'key'     => '_lic_last_synced',
                'value'   => $threshold,
                'compare' => '<',
                'type'    => 'DATETIME',
            ],
            [
                'key'     => '_lic_last_synced',
                'compare' => 'NOT EXISTS',
            ],
        ];
        $query->set('meta_query', $existing);
    }
});

add_action('restrict_manage_posts', function ($post_type) {
    if ($post_type !== 'product') return;
    $current    = isset($_GET['lic_stale_days']) ? (int) $_GET['lic_stale_days'] : 0;
    $options    = [0 => 'All (LIC sync)', 1 => 'Not synced in 1 day', 3 => 'Not synced in 3 days', 7 => 'Not synced in 7 days', 14 => 'Not synced in 14 days', 30 => 'Not synced in 30 days'];
    echo '<select name="lic_stale_days">';
    foreach ($options as $val => $label) {
        printf('<option value="%d"%s>%s</option>', $val, selected($current, $val, false), esc_html($label));
    }
    echo '</select>';
});
// C-16 end
/* ***************************************** */

/* ***************************************** */
// C-18: sync gap alerting

function lic_collect_sync_alerts() {
    global $wpdb;

    $settings  = get_option('lic_settings', []);
    $locations = $settings['locations'] ?? [];
    $alerts    = [];

    // Alert 1: cache not warmed in > 25 hours for any location (daily cron + 1hr buffer).
    foreach ($locations as $location_data) {
        $name = $location_data['name'] ?? '';
        if (empty($name)) continue;
        $last_warmed = get_option('lic_cache_last_warmed_' . sanitize_key($name));
        if (!$last_warmed || strtotime($last_warmed) < (time() - 25 * HOUR_IN_SECONDS)) {
            $alerts['cache_' . sanitize_key($name)] = sprintf(
                'LIC: Cache for location "%s" has not been successfully warmed in over 25 hours.',
                $name
            );
        }
    }

    // Alert 2: import queue stuck without any progress for > 30 minutes.
    // A location's queue row is created once at the start of its sync cycle and reused
    // across every batch — created_at never changes, only status/current_offset/
    // last_processed_at do. Checking created_at for 'pending' rows meant any location
    // whose full cycle legitimately takes longer than 30 minutes (Thomasville's 527-unit
    // catalog always has) tripped this every time, indistinguishable from an actually-dead
    // cron. COALESCE(last_processed_at, created_at) checks recency of the last real
    // update instead — falling back to created_at only for a row that's never been
    // processed at all, so a genuinely stalled cron (nothing touching the row, ever) is
    // still caught, but a slow-but-steadily-advancing sync is not mistaken for one.
    // Lists which location(s), their status/offset, and exactly how long since the row
    // was last touched — not just a bare count. This is the one alert that triggers
    // self-heal (a full queue restart), so knowing which location and how stale it was
    // matters for confirming the restart was actually warranted after the fact, rather
    // than having to go pull the queue-status log separately every time this fires.
    $queue_table = $wpdb->prefix . 'lic_import_queue';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $queue_table)) === $queue_table) {
        $stuck_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT location_name, status, current_offset, last_processed_at, created_at,
                    TIMESTAMPDIFF(MINUTE, COALESCE(last_processed_at, created_at), NOW()) AS minutes_stale
             FROM {$queue_table}
             WHERE status IN (%s, %s)
               AND COALESCE(last_processed_at, created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
            'processing',
            'pending'
        ));
        if (!empty($stuck_rows)) {
            $detail = implode('; ', array_map(function ($r) {
                return sprintf(
                    '%s: status=%s, offset=%d, last touched %s (%d min ago)',
                    $r->location_name,
                    $r->status,
                    (int) $r->current_offset,
                    $r->last_processed_at ?: ('created ' . $r->created_at),
                    (int) $r->minutes_stale
                );
            }, $stuck_rows));
            $alerts['queue_stuck'] = sprintf(
                'LIC: The import queue has %d item(s) stuck without progress for over 30 minutes. WordPress cron may not be running. %s',
                count($stuck_rows),
                $detail
            );
        }
    }

    // Alert 3: products missed in the most recent sync run for any location.
    foreach ($locations as $location_data) {
        $name = $location_data['name'] ?? '';
        if (empty($name)) continue;
        $missed = (int) get_option('lic_sync_missed_' . sanitize_key($name), 0);
        if ($missed > 0) {
            $alerts['missed_' . sanitize_key($name)] = sprintf(
                'LIC: %d product(s) in the API cache for "%s" were not updated in the last sync run. Check the Sync Summaries log for details.',
                $missed,
                $name
            );
        }
    }

    // Alert 4: too many products unsynced beyond threshold. Lists the actual SKUs and
    // their stock status (not just a count) so this is self-diagnosing without a DB
    // query — outofstock strongly suggests the product has left Lightspeed's feed and
    // is waiting on the sold-unit cleanup cron rather than a sync problem, while instock
    // suggests it's a real product the sync just hasn't reached yet.
    $stale_days  = max(1, (int) get_option('lic_alert_stale_days', 7));
    $stale_limit = max(1, (int) get_option('lic_alert_stale_count', 10));
    $threshold   = date('Y-m-d H:i:s', strtotime("-{$stale_days} days"));
    $stale_rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, pm_sku.meta_value AS sku, pm_stock.meta_value AS stock_status
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm_sync  ON pm_sync.post_id  = p.ID AND pm_sync.meta_key  = '_lic_last_synced'
         LEFT JOIN {$wpdb->postmeta} pm_sku   ON pm_sku.post_id   = p.ID AND pm_sku.meta_key   = '_sku'
         LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id = p.ID AND pm_stock.meta_key = '_stock_status'
         WHERE p.post_type = 'product' AND p.post_status = 'publish'
         AND (pm_sync.meta_value IS NULL OR pm_sync.meta_value < %s)",
        $threshold
    ));
    $stale_count = count($stale_rows);
    if ($stale_count >= $stale_limit) {
        $shown = array_slice($stale_rows, 0, 25);
        $list  = implode(', ', array_map(function ($r) {
            return ($r->sku ?: "post {$r->ID}") . ' (' . ($r->stock_status ?: 'unknown') . ')';
        }, $shown));
        if ($stale_count > count($shown)) {
            $list .= sprintf(' … and %d more', $stale_count - count($shown));
        }
        $alerts['stale_products'] = sprintf(
            'LIC: %d published products have not been synced in more than %d days: %s. Outofstock items have likely left Lightspeed\'s feed and are waiting on the sold-unit cleanup cron; instock items are real products the sync hasn\'t reached yet.',
            $stale_count,
            $stale_days,
            $list
        );
    }

    // Alert 5: a previously-confirmed-sold VIN reappearing under the same stock number —
    // a possible trade-in, worth a human look. A different VIN reusing an old stock number
    // is excluded entirely: that's just normal inventory turnover (see
    // LIC_Importer::is_sku_confirmed_sold()'s 'vin_mismatch' vs 'vin_reappeared' event
    // types), not something worth flagging. Watermarked on id rather than time-throttled —
    // once a given occurrence has been emailed about, it never triggers another alert on its
    // own; only a genuinely new reappearance (a higher id) does.
    $review_table = $wpdb->prefix . 'lic_sold_review_queue';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $review_table)) === $review_table) {
        $last_notified_id = (int) get_option('lic_sold_review_last_notified_id', 0);
        $new_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$review_table} WHERE event_type = %s AND id > %d AND dismissed_at IS NULL",
            'vin_reappeared', $last_notified_id
        ));
        if ($new_count > 0) {
            $alerts['sold_review_queue'] = sprintf(
                'LIC: %d stock number(s) previously confirmed sold have reappeared under the same VIN — possible trade-in. Review the "Sold Unit Alerts" log.',
                $new_count
            );
        }
    }

    return $alerts;
}

// Alert keys that trigger self-healing instead of a plain email.
// Exact key: 'queue_stuck'. Prefixes: 'missed_<location>', 'cache_<location>'.
// stale_products deliberately does NOT self-heal: a stale product is stale either
// because the sync hasn't reached it yet (which a queue restart doesn't fix any faster
// than just waiting) or because it's no longer in Lightspeed's feed at all and is
// awaiting the separate sold-unit cleanup cron (which a queue restart can't touch or
// speed up either way). Restarting the whole queue in response to this fixes nothing
// and just burns a full re-sync — confirmed live: the same 4 stale products survived
// 10+ consecutive hourly restarts unchanged.
define('LIC_SELF_HEAL_ALERT_KEYS', ['queue_stuck']);
define('LIC_SELF_HEAL_ALERT_PREFIXES', ['missed_', 'cache_']);

function lic_is_self_heal_alert_key($key) {
    if (in_array($key, LIC_SELF_HEAL_ALERT_KEYS, true)) {
        return true;
    }
    foreach (LIC_SELF_HEAL_ALERT_PREFIXES as $prefix) {
        if (strpos($key, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

// Show admin notices.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    foreach (lic_collect_sync_alerts() as $alert) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($alert) . '</p></div>';
    }
});

// Formats the most recent API error log row for inclusion in an alert email, or '' if none logged.
function lic_format_last_api_error() {
    global $wpdb;
    $table = $wpdb->prefix . 'lic_api_error_log';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return '';
    }
    $row = $wpdb->get_row("SELECT location_name, context, http_code, error_message, logged_at FROM {$table} ORDER BY logged_at DESC LIMIT 1");
    if (!$row) {
        return '';
    }
    return sprintf(
        "\n\nMost recent API error log entry:\n[%s] %s (%s, HTTP %d): %s",
        $row->logged_at,
        $row->location_name,
        $row->context,
        $row->http_code,
        $row->error_message
    );
}

// Self-heals a detected sync problem and emails the admin what was attempted.
// 'cache_*' forces an immediate cache re-warm (not guaranteed to succeed if the API/credentials
// are actually down). 'queue_stuck' does a targeted, non-destructive recovery (see below).
// Everything else (missed_*) restarts the import queue, which is a guaranteed fix for a
// stuck/incomplete run there.
// Throttled to once per hour per alert key so a persistent problem doesn't retrigger every check.
function lic_self_heal_stuck_sync($key, $message) {
    if (get_option('lic_sync_enabled', '1') === '0') {
        return;
    }

    $throttle_key = 'lic_selfheal_triggered_' . $key;
    if (get_transient($throttle_key)) {
        return;
    }
    set_transient($throttle_key, true, HOUR_IN_SECONDS);

    $admin_email = get_option('admin_email');
    $site        = get_site_url();
    $subject     = '[LIC Alert] Sync self-heal triggered';

    if (strpos($key, 'cache_') === 0) {
        LIC_Cron_Manager::cache_all_locations();

        $body = wp_strip_all_tags($message)
            . "\n\nAn immediate cache re-warm was attempted automatically. This does not guarantee "
            . "success if the underlying cause (API credentials, connectivity) is still present."
            . lic_format_last_api_error()
            . "\n\nSite: {$site}";
    } elseif ($key === 'queue_stuck') {
        // process_queue_batch() already resets a 'processing' row stuck for 5 minutes on
        // its own — if that were still running every 2 minutes as scheduled, a stuck
        // batch would self-correct long before this 30-minute check ever fires. So this
        // firing at all is itself the signal that lic_process_queue_cron has stopped
        // being triggered, not that any row's data or offset is bad. A full
        // lic_queue_start() wipes every location back to offset 0 regardless of which
        // one (if any) actually stalled — discarding real progress to fix what's really
        // just a missing schedule. Re-scheduling the cron and nudging only the stuck
        // row(s) back to 'pending' (offset untouched) gets the same recovery without
        // losing anything.
        global $wpdb;
        $queue_table = $wpdb->prefix . 'lic_import_queue';

        $rescheduled = false;
        if (!wp_next_scheduled('lic_process_queue_cron')) {
            wp_schedule_event(time(), 'every_2_minutes', 'lic_process_queue_cron');
            $rescheduled = true;
        }

        $reset = (int) $wpdb->query(
            "UPDATE {$queue_table}
             SET status = 'pending'
             WHERE status IN ('processing', 'pending')
               AND COALESCE(last_processed_at, created_at) < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );

        $body = wp_strip_all_tags($message)
            . "\n\n" . ($rescheduled ? 'The queue-processing cron\'s schedule had gone missing and was restored. ' : '')
            . "Reset {$reset} stuck row(s) back to pending — offsets were left untouched, so processing resumes from where it left off rather than restarting from scratch."
            . "\n\nSite: {$site}";
    } else {
        LIC_Importer::lic_queue_start();

        $body = wp_strip_all_tags($message)
            . "\n\nThe import queue was cleared and a new sync run was started automatically."
            . "\n\nSite: {$site}";
    }

    wp_mail($admin_email, $subject, $body);
}

// Runs all sync-health checks and self-heals any that meet their trigger condition.
function lic_check_and_self_heal_sync() {
    foreach (lic_collect_sync_alerts() as $key => $message) {
        if (lic_is_self_heal_alert_key($key)) {
            lic_self_heal_stuck_sync($key, $message);
        }
    }
}

// Dedicated hourly cron so self-heal doesn't depend on an admin visiting wp-admin.
add_action('plugins_loaded', function () {
    if (!wp_next_scheduled('lic_sync_health_check_cron')) {
        wp_schedule_event(time(), 'hourly', 'lic_sync_health_check_cron');
    }
});
add_action('lic_sync_health_check_cron', 'lic_check_and_self_heal_sync');

// Send throttled email alerts (once per 4 hours per alert type) for everything except
// the self-healing alert keys, which are handled by lic_check_and_self_heal_sync() above.
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;

    // These two crons are only ever scheduled inside register_activation_hook() —
    // unlike check_product_exist_cron (see schedule_product_check(), hooked to every
    // front-end 'wp' load specifically so a lost schedule self-heals), nothing else in
    // the plugin notices or recovers if either one's schedule gets cleared. A plugin
    // update that doesn't go through WordPress's official deactivate/activate flow
    // (e.g. files replaced directly rather than via the plugin updater) would silently
    // and permanently stop delete_sold_out_products_event from ever running again —
    // confirmed as the reason sold units were sitting outofstock, published, and
    // untrashed for days: nothing was left to trash them.
    if (!wp_next_scheduled('delete_sold_out_products_event')) {
        wp_schedule_event(time(), 'daily', 'delete_sold_out_products_event');
    }
    if (!wp_next_scheduled('lic_log_cleanup_cron')) {
        wp_schedule_event(time(), 'daily', 'lic_log_cleanup_cron');
    }

    lic_check_and_self_heal_sync();

    $admin_email = get_option('admin_email');
    $site        = get_site_url();

    foreach (lic_collect_sync_alerts() as $key => $message) {
        if (lic_is_self_heal_alert_key($key)) continue;

        $transient = 'lic_alert_emailed_' . $key;
        if (get_transient($transient)) continue;

        $subject = '[LIC Alert] ' . wp_strip_all_tags($message);
        $body    = wp_strip_all_tags($message) . "\n\nSite: {$site}";
        wp_mail($admin_email, $subject, $body);
        set_transient($transient, true, 4 * HOUR_IN_SECONDS);

        // sold_review_queue is watermarked on id, not just time-throttled — advance it to
        // the newest reviewed occurrence now that it's actually been emailed, so the same
        // trade-in reappearance never triggers a second alert once it's been reported.
        if ($key === 'sold_review_queue') {
            global $wpdb;
            $review_table = $wpdb->prefix . 'lic_sold_review_queue';
            $max_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(id) FROM {$review_table} WHERE event_type = %s",
                'vin_reappeared'
            ));
            update_option('lic_sold_review_last_notified_id', $max_id);
        }
    }
});

/* ***************************************** */

/* ***************************************** */
// Helper: create/update WooCommerce product attribute and assign terms
if (!function_exists('lic_set_product_attribute')) {
    function lic_set_product_attribute($product_id, $attribute_label, $attribute_slug, $attribute_values) {
        if (empty($product_id) || empty($attribute_slug)) {
            return;
        }

        $taxonomy = 'pa_' . sanitize_title($attribute_slug);

        // 1. Register the attribute if it doesn't exist
        if (!taxonomy_exists($taxonomy)) {
            $attribute_id = wc_create_attribute([
                'name'         => $attribute_label,
                'slug'         => sanitize_title($attribute_slug),
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ]);

            if (!is_wp_error($attribute_id)) {
                register_taxonomy(
                    $taxonomy,
                    'product',
                    [
                        'hierarchical' => false,
                        'labels'       => [
                            'name' => $attribute_label,
                        ],
                        'show_ui'      => false,
                        'query_var'    => true,
                        'rewrite'      => false,
                    ]
                );
            }
        }

        // Normalize values
        $terms = is_array($attribute_values)
            ? $attribute_values
            : preg_split('/[|,;]+/', (string) $attribute_values);

        $terms = array_filter(array_map('trim', $terms));
        if (empty($terms)) {
            return;
        }

        // 2. Ensure terms exist
        foreach ($terms as $term) {
            if (!term_exists($term, $taxonomy)) {
                wp_insert_term($term, $taxonomy);
            }
        }

        // 3. Update product attribute meta
        $attributes = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $attributes[$taxonomy] = [
            'name'         => $taxonomy,
            'value'        => implode(' | ', $terms),
            'is_visible'   => '1',
            'is_variation' => '0',
            'is_taxonomy'  => '1',
        ];

        update_post_meta($product_id, '_product_attributes', $attributes);

        // 4. Assign terms to product
        wp_set_object_terms($product_id, $terms, $taxonomy, false);
    }
}
/* ***************************************** */

/* ***************************************** */
// Helper: toggle WooCommerce featured flag using the product API
if (!function_exists('lic_set_product_featured_status')) {
    function lic_set_product_featured_status($product_id, $is_featured) {
        if (empty($product_id) || !function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product || !method_exists($product, 'set_featured')) {
            return;
        }

        $product->set_featured((bool) $is_featured);
        $product->save();
    }
}
/* ***************************************** */

/* ***************************************** */
// Credential helpers â€” credentials live in a separate, non-autoloaded option
// keyed by sanitize_key(location_name) so they are never exposed in the autoloaded
// options cache that every WordPress page load reads into memory.
function lic_get_credentials( $location_name ) {
    $all = get_option( 'lic_credentials', [] );
    return $all[ sanitize_key( $location_name ) ] ?? [];
}

function lic_save_credentials( $location_name, array $creds ) {
    $all = get_option( 'lic_credentials', [] );
    $all[ sanitize_key( $location_name ) ] = $creds;
    update_option( 'lic_credentials', $all, false );
}

function lic_delete_credentials( $location_name ) {
    $all = get_option( 'lic_credentials', [] );
    unset( $all[ sanitize_key( $location_name ) ] );
    update_option( 'lic_credentials', $all, false );
}

// Derives the SoldUnit endpoint URL from a configured Unit endpoint URL. Per the Lightspeed
// 3PA Developer Guide ("Unit and SoldUnit — Major Unit Inventory"), both live at the same
// host/Cmf and differ only in the DataType path segment (lsapi/Unit/{Cmf} vs lsapi/SoldUnit/{Cmf}).
// Returns '' if the configured URL doesn't match that pattern.
function lic_get_sold_unit_url( $unit_api_url ) {
    if ( empty( $unit_api_url ) ) {
        return '';
    }
    $sold_url = preg_replace( '#(/lsapi/)Unit(/)#i', '$1SoldUnit$2', $unit_api_url, 1, $count );
    return $count > 0 ? $sold_url : '';
}

// One-time migration: move credentials out of lic_settings into lic_credentials.
function lic_migrate_credentials() {
    if ( get_option( 'lic_credentials_migrated' ) ) {
        return;
    }
    $settings = get_option( 'lic_settings', [] );
    if ( empty( $settings['locations'] ) ) {
        update_option( 'lic_credentials_migrated', '1' );
        return;
    }
    $credential_fields = [ 'api_username_sandbox', 'api_password_sandbox', 'api_username_live', 'api_password_live' ];
    $all_credentials   = get_option( 'lic_credentials', [] );
    foreach ( $settings['locations'] as $i => $location ) {
        $key = sanitize_key( $location['name'] ?? '' );
        if ( ! $key ) continue;
        $all_credentials[ $key ] = [];
        foreach ( $credential_fields as $field ) {
            $all_credentials[ $key ][ $field ] = $location[ $field ] ?? '';
            unset( $settings['locations'][ $i ][ $field ] );
        }
    }
    update_option( 'lic_credentials', $all_credentials, false );
    update_option( 'lic_settings', $settings );
    update_option( 'lic_credentials_migrated', '1' );
}
add_action( 'plugins_loaded', 'lic_migrate_credentials' );
/* ***************************************** */



// Only For category Code 09-03-2026
add_action('pre_get_posts', 'nct_only_current_product_category_on_archive', 20);
add_action('woocommerce_product_query', 'nct_only_current_product_category_on_archive_wc', 20);

// Rewrites $query's tax_query so it filters on only the current product_cat term —
// shared by the pre_get_posts and woocommerce_product_query hooks below.
function lic_apply_current_product_category_tax_query($query) {
    $term = get_queried_object();
    if (! $term || is_wp_error($term) || empty($term->term_id)) {
        return;
    }

    $tax_query = $query->get('tax_query');
    if (! is_array($tax_query)) {
        $tax_query = array();
    }

    $clean_tax_query = array();
    foreach ($tax_query as $clause) {
        if (! is_array($clause)) {
            continue;
        }

        if (isset($clause['taxonomy']) && $clause['taxonomy'] === 'product_cat') {
            continue;
        }

        $clean_tax_query[] = $clause;
    }

    $clean_tax_query[] = array(
        'taxonomy' => 'product_cat',
        'field' => 'term_id',
        'terms' => array((int) $term->term_id),
        'operator' => 'IN',
        'include_children' => false,
    );

    $query->set('tax_query', $clean_tax_query);
}

function nct_only_current_product_category_on_archive($query) {
    if (is_admin() || ! $query->is_main_query() || ! $query->is_tax('product_cat')) {
        return;
    }
    lic_apply_current_product_category_tax_query($query);
}

function nct_only_current_product_category_on_archive_wc($query) {
    if (is_admin() || ! is_tax('product_cat')) {
        return;
    }
    lic_apply_current_product_category_tax_query($query);
}

// Recursively sanitizes a field mapping array from POST data.
// Scalar values are run through sanitize_text_field(); arrays are recursed into;
// non-scalar, non-array values are dropped.
if ( ! function_exists( 'lic_sanitize_mapping_array' ) ) {
    function lic_sanitize_mapping_array( $data ) {
        if ( ! is_array( $data ) ) {
            return sanitize_text_field( (string) $data );
        }
        $clean = [];
        foreach ( $data as $key => $value ) {
            $clean_key = sanitize_text_field( (string) $key );
            if ( is_array( $value ) ) {
                $clean[ $clean_key ] = lic_sanitize_mapping_array( $value );
            } elseif ( is_scalar( $value ) ) {
                $clean[ $clean_key ] = sanitize_text_field( (string) $value );
            }
            // Drop non-scalar, non-array values entirely.
        }
        return $clean;
    }
}
