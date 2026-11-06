<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Delete all plugin options
$options = [
    'lic_settings', 'lic_credentials', 'lic_credentials_migrated',
    'lic_batch_size', 'lic_sync_enabled', 'lic_cache_warm_time',
    'lic_alert_stale_days', 'lic_alert_stale_count', 'lic_unit_status_map',
    'lic_db_version', 'lic_cron_settings',
    'lic_sold_products_image', 'lic_sold_products_days', 'lic_featured_default_image',
    'lic_image_email_recipients', 'lic_image_email_send_time',
    'lic_image_email_filter', 'lic_image_email_min_api_count', 'lic_image_email_extra_fields',
];
foreach ( $options as $option ) {
    delete_option( $option );
}

// Delete location-specific options
$settings  = get_option( 'lic_settings', [] );
$locations = $settings['locations'] ?? [];
foreach ( $locations as $loc ) {
    $key = sanitize_key( $loc['name'] ?? '' );
    if ( ! $key ) continue;
    delete_option( "cron_api_skip_value_{$key}" );
    delete_option( "cron_import_status_{$key}" );
    delete_option( "lic_cache_last_warmed_{$key}" );
    delete_option( "mapped_fields_{$key}" );
    delete_option( "custom_title_{$key}" );
    delete_option( "API_location_{$key}" );
    delete_option( "lic_last_import_message_{$key}" );
    delete_option( "lic_sync_log_current_{$key}" );
    delete_option( "lic_sync_missed_{$key}" );
    delete_option( "lic_mapping_changed_{$key}" );
    delete_option( "lic_backfill_done_{$key}" );
}

// Drop custom tables
global $wpdb;
$tables = [
    $wpdb->prefix . 'lic_api_cache',
    $wpdb->prefix . 'lic_cron_error_logs',
    $wpdb->prefix . 'lic_remove_products_logs',
    $wpdb->prefix . 'lic_import_queue',
    $wpdb->prefix . 'lic_sync_log',
    $wpdb->prefix . 'lic_api_error_log',
];
foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Clear scheduled hooks
wp_clear_scheduled_hook( 'lic_daily_api_cache_cron' );
wp_clear_scheduled_hook( 'lic_incremental_cache_cron' );
wp_clear_scheduled_hook( 'lic_process_queue_cron' );
wp_clear_scheduled_hook( 'lic_log_cleanup_cron' );
wp_clear_scheduled_hook( 'delete_sold_out_products_event' );
wp_clear_scheduled_hook( 'check_product_exist_cron' );
wp_clear_scheduled_hook( 'generate_daily_image_report' );
wp_clear_scheduled_hook( 'lic_inventory_update' );
wp_clear_scheduled_hook( 'lic_sync_health_check_cron' );
