<?php

class LIC_Ajax_Handler {

    public static function init() {
        add_action( 'wp_ajax_lic_save_alert_settings',       array( __CLASS__, 'save_alert_settings' ) );
        add_action( 'wp_ajax_lic_save_cache_warm_time',      array( __CLASS__, 'save_cache_warm_time' ) );
        add_action( 'wp_ajax_lic_set_sync_enabled',          array( __CLASS__, 'set_sync_enabled' ) );
        add_action( 'wp_ajax_lic_warm_cache_now',            array( __CLASS__, 'warm_cache_now' ) );
        add_action( 'wp_ajax_lic_run_sold_unit_reconciliation', array( __CLASS__, 'run_sold_unit_reconciliation' ) );
        add_action( 'wp_ajax_lic_unflag_confirmed_sold',      array( __CLASS__, 'unflag_confirmed_sold' ) );
        add_action( 'wp_ajax_lic_bulk_unflag_confirmed_sold', array( __CLASS__, 'bulk_unflag_confirmed_sold' ) );
        add_action( 'wp_ajax_lic_review_sold_alert',          array( __CLASS__, 'review_sold_alert' ) );
        add_action( 'wp_ajax_lic_bulk_review_sold_alerts',    array( __CLASS__, 'bulk_review_sold_alerts' ) );
        add_action( 'wp_ajax_lic_debug_lookup_stock_number',  array( __CLASS__, 'debug_lookup_stock_number' ) );
        add_action( 'wp_ajax_save_location_data',            array( __CLASS__, 'save_location_data' ) );
        add_action( 'wp_ajax_delete_location_data',          array( __CLASS__, 'delete_location_data' ) );
        add_action( 'wp_ajax_fetch_api_fields',              array( __CLASS__, 'fetch_api_fields' ) );
        add_action( 'wp_ajax_save_mapped_LIC_WC_API_Field',  array( __CLASS__, 'save_mapped_fields' ) );
        add_action( 'wp_ajax_pull_data_LIC_WC',              array( __CLASS__, 'pull_data_lic_wc' ) );
        add_action( 'wp_ajax_lic_backfill_field_mapping',    array( __CLASS__, 'backfill_field_mapping' ) );
        add_action( 'wp_ajax_get_mapped_fields',             array( __CLASS__, 'get_mapped_fields' ) );
        add_action( 'wp_ajax_lic_refresh_unit_statuses',     array( __CLASS__, 'refresh_unit_statuses' ) );
        add_action( 'wp_ajax_lic_save_unit_status_map',      array( __CLASS__, 'save_unit_status_map' ) );
        add_action( 'wp_ajax_lic_save_inventory_flags',      array( __CLASS__, 'save_inventory_flags' ) );
        add_action( 'wp_ajax_lic_clear_log',                 array( __CLASS__, 'clear_log' ) );
        add_action( 'wp_ajax_lic_sync_product_images',       array( __CLASS__, 'sync_product_images' ) );
        add_action( 'wp_ajax_lic_scan_orphaned_images',      array( __CLASS__, 'scan_orphaned_images' ) );
        add_action( 'wp_ajax_lic_delete_orphaned_images',    array( __CLASS__, 'delete_orphaned_images' ) );
        add_action( 'wp_ajax_lic_ignore_orphaned_image',        array( __CLASS__, 'ignore_orphaned_image' ) );
        add_action( 'wp_ajax_lic_save_image_email_settings',    array( __CLASS__, 'save_image_email_settings' ) );
        add_action( 'wp_ajax_lic_save_image_email_extra_fields',  array( __CLASS__, 'save_image_email_extra_fields' ) );
        add_action( 'wp_ajax_lic_get_available_product_fields',   array( __CLASS__, 'get_available_product_fields' ) );
    }

    // Saves stale-product alert threshold settings.
    public static function save_alert_settings() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $days  = max( 1, (int) ( $_POST['stale_days']  ?? 7 ) );
        $count = max( 1, (int) ( $_POST['stale_count'] ?? 10 ) );
        update_option( 'lic_alert_stale_days',  $days );
        update_option( 'lic_alert_stale_count', $count );
        wp_send_json_success( 'Alert settings saved.' );
    }

    // Saves the daily cache warm time and reschedules the cron.
    public static function save_cache_warm_time() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        global $wpdb;

        $time_local = sanitize_text_field( $_POST['cache_warm_time'] ?? '06:00' );
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $time_local ) ) {
            wp_send_json_error( 'Invalid time format.' );
        }

        $dt = new DateTime( 'today ' . $time_local, wp_timezone() );
        $dt->setTimezone( new DateTimeZone( 'UTC' ) );
        $time_utc = $dt->format( 'H:i' );

        $wpdb->replace(
            $wpdb->options,
            [ 'option_name' => 'lic_cache_warm_time', 'option_value' => $time_utc, 'autoload' => 'yes' ],
            [ '%s', '%s', '%s' ]
        );
        wp_cache_delete( 'lic_cache_warm_time', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );

        wp_clear_scheduled_hook( 'lic_daily_api_cache_cron' );
        wp_schedule_event( LIC_Cron_Manager::next_warm_timestamp( $time_utc ), 'daily', 'lic_daily_api_cache_cron' );

        // The monthly SoldUnit reconciliation sweep is also a "large request" the Lightspeed 3PA
        // guide says to run during off-hours, so it's anchored to this same time — re-anchor it
        // too, preserving its 30-day cadence from today rather than from whenever it first ran.
        wp_clear_scheduled_hook( 'lic_sold_unit_reconciliation_cron' );
        wp_schedule_event( LIC_Cron_Manager::next_warm_timestamp( $time_utc ), 'lic_monthly', 'lic_sold_unit_reconciliation_cron' );

        wp_send_json_success( 'Cache warm time saved.' );
    }

    // Enables or disables the inventory sync.
    public static function set_sync_enabled() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        global $wpdb;
        $value = ! empty( $_POST['sync_enabled'] ) ? '1' : '0';
        $wpdb->replace(
            $wpdb->options,
            [ 'option_name' => 'lic_sync_enabled', 'option_value' => $value, 'autoload' => 'yes' ],
            [ '%s', '%s', '%s' ]
        );
        wp_cache_delete( 'lic_sync_enabled', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_send_json_success();
    }

    // Triggers an immediate full API cache warm for all locations.
    public static function warm_cache_now() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        LIC_Cron_Manager::cache_all_locations();
        wp_send_json_success( 'Cache warmed successfully.' );
    }

    // Triggers an immediate SoldUnit reconciliation sweep across all locations.
    public static function run_sold_unit_reconciliation() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        LIC_Cron_Manager::reconcile_sold_units();
        wp_send_json_success( 'Sold-unit reconciliation completed. See the Stock Check log for details.' );
    }

    // Removes a single row from lic_confirmed_sold and, if the matching WooCommerce product was
    // already trashed as a result, restores it — see LIC_Importer::unflag_and_restore_sku().
    public static function unflag_confirmed_sold() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }
        global $wpdb;
        $stock_number = $wpdb->get_var( $wpdb->prepare(
            "SELECT stock_number FROM {$wpdb->prefix}lic_confirmed_sold WHERE id = %d", $id
        ) );
        if ( ! $stock_number ) {
            wp_send_json_error( 'Row not found.' );
        }
        $result  = LIC_Importer::unflag_and_restore_sku( $stock_number );
        $message = 'Stock number un-flagged.' . ( $result['restored'] ? ' Product restored from trash — its images will repopulate on the next sync.' : '' );
        wp_send_json_success( $message );
    }

    // Bulk version of unflag_confirmed_sold(), for fixing a known batch of wrongly-flagged
    // stock numbers at once (e.g. units transferred between locations rather than sold).
    // Accepts newline/comma/whitespace-separated stock numbers.
    public static function bulk_unflag_confirmed_sold() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $raw = (string) ( $_POST['stock_numbers'] ?? '' );
        $stock_numbers = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ) ) ) );
        if ( empty( $stock_numbers ) ) {
            wp_send_json_error( 'No stock numbers provided.' );
        }

        $processed = 0;
        $restored  = 0;
        $not_found = [];

        foreach ( $stock_numbers as $sn ) {
            $result = LIC_Importer::unflag_and_restore_sku( sanitize_text_field( $sn ) );
            if ( ! $result['had_confirmed_sold_row'] && $result['product_id'] === null ) {
                $not_found[] = $sn;
                continue;
            }
            $processed++;
            if ( $result['restored'] ) {
                $restored++;
            }
        }

        $message = "Processed {$processed} of " . count( $stock_numbers ) . ' stock number(s), restored ' . $restored . ' trashed product(s) to publish.';
        if ( ! empty( $not_found ) ) {
            $message .= ' Not found (no confirmed-sold record and no matching product): ' . implode( ', ', $not_found ) . '.';
        }
        wp_send_json_success( $message );
    }

    // Dismisses a single row from lic_sold_review_queue. Purely an acknowledgment — for
    // vin_reappeared events this does NOT un-block the stock number; that's a separate,
    // deliberate action on the Confirmed Sold tab.
    //
    // Sets dismissed_at rather than deleting the row — queue_sold_review() re-evaluates this
    // stock number/event on every sync and leaves dismissed_at alone as long as the VIN pair
    // it sees is unchanged, so a reviewed alert stays reviewed instead of reappearing on the
    // next cycle. See the comment on queue_sold_review() for why deleting caused every alert
    // reviewed to come right back.
    public static function review_sold_alert() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'lic_sold_review_queue', [ 'dismissed_at' => current_time( 'mysql' ) ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        wp_send_json_success( 'Alert marked reviewed.' );
    }

    // Bulk version of review_sold_alert() — dismisses every id in the given list.
    public static function bulk_review_sold_alerts() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( 'No alerts selected.' );
        }
        global $wpdb;
        $table        = $wpdb->prefix . 'lic_sold_review_queue';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET dismissed_at = %s WHERE id IN ({$placeholders})",
            array_merge( [ current_time( 'mysql' ) ], $ids )
        ) );
        wp_send_json_success( count( $ids ) . ' alert(s) marked reviewed.' );
    }

    // Live-looks-up a single stock number against Unit and SoldUnit for every location, plus
    // its Confirmed Sold / Sold Unit Alerts state. Used by the "Stock Debug" admin tab.
    public static function debug_lookup_stock_number() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $stock_number = sanitize_text_field( $_POST['stock_number'] ?? '' );
        if ( $stock_number === '' ) {
            wp_send_json_error( 'Enter a stock number.' );
        }
        wp_send_json_success( LIC_Cron_Manager::debug_lookup_stock_number( $stock_number ) );
    }

    // Saves location settings and credentials.
    public static function save_location_data() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $index    = isset( $_POST['index'] ) ? intval( $_POST['index'] ) : 0;
        $formData = isset( $_POST['formData'] ) ? $_POST['formData'] : '';

        parse_str( $formData, $formFields );

        $locations = get_option( 'lic_settings', array( 'locations' => array() ) );
        if ( ! is_array( $locations ) ) {
            $locations = array( 'locations' => array() );
        }
        if ( ! isset( $locations['locations'] ) || ! is_array( $locations['locations'] ) ) {
            $locations['locations'] = array();
        }

        $use_sandbox_value = ! empty( $formFields['lic_settings']['locations'][ $index ]['use_sandbox'] ) ? '1' : '0';
        $location_name     = sanitize_text_field( $formFields['lic_settings']['locations'][ $index ]['name'] ?? '' );

        $locations['locations'][ $index ] = array(
            'name'        => $location_name,
            'api_url'     => sanitize_text_field( $formFields['lic_settings']['locations'][ $index ]['api_url'] ?? '' ),
            'use_sandbox' => $use_sandbox_value,
        );

        $existing_creds = lic_get_credentials( $location_name );

        $submitted_password_sandbox = $formFields['lic_settings']['locations'][ $index ]['api_password_sandbox'] ?? '';
        $submitted_password_live    = $formFields['lic_settings']['locations'][ $index ]['api_password_live'] ?? '';

        lic_save_credentials( $location_name, [
            'api_username_sandbox' => sanitize_text_field( $formFields['lic_settings']['locations'][ $index ]['api_username_sandbox'] ?? '' ),
            'api_password_sandbox' => ! empty( $submitted_password_sandbox )
                ? sanitize_text_field( $submitted_password_sandbox )
                : ( $existing_creds['api_password_sandbox'] ?? '' ),
            'api_username_live'    => sanitize_text_field( $formFields['lic_settings']['locations'][ $index ]['api_username_live'] ?? '' ),
            'api_password_live'    => ! empty( $submitted_password_live )
                ? sanitize_text_field( $submitted_password_live )
                : ( $existing_creds['api_password_live'] ?? '' ),
        ] );

        $updated = update_option( 'lic_settings', $locations );
        if ( $updated ) {
            wp_send_json_success( 'Location data saved successfully.' );
        } else {
            wp_send_json_error( 'Failed to save location data.' );
        }
    }

    // Deletes a location and its credentials.
    public static function delete_location_data() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $location_id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $locations       = get_option( 'lic_settings', array( 'locations' => array() ) );
        $saved_locations = $locations['locations'];

        foreach ( $saved_locations as $index => $location ) {
            if ( $index === $location_id ) {
                lic_delete_credentials( $location['name'] ?? '' );
                unset( $locations['locations'][ $index ] );
                $locations['locations'] = array_values( $locations['locations'] );
                update_option( 'lic_settings', $locations );
                wp_send_json_success( 'Location data deleted successfully.' );
                break;
            }
        }

        wp_send_json_error( 'Location not found.' );
    }

    // Returns the API field list for a location as HTML select options.
    public static function fetch_api_fields() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $selectedLocation = sanitize_text_field( $_POST['selected_location'] );
        $settings         = get_option( 'lic_settings' );

        $selected_location_details = null;
        foreach ( $settings['locations'] as $location ) {
            if ( $location['name'] === $selectedLocation ) {
                $selected_location_details = $location;
                break;
            }
        }

        if ( ! $selected_location_details ) {
            wp_send_json_error( 'Location details not found.' );
        }

        $creds = lic_get_credentials( $selectedLocation );
        if ( ! empty( $selected_location_details['use_sandbox'] ) ) {
            $api_key    = $creds['api_username_sandbox'] ?? '';
            $api_secret = $creds['api_password_sandbox'] ?? '';
        } else {
            $api_key    = $creds['api_username_live'] ?? '';
            $api_secret = $creds['api_password_live'] ?? '';
        }

        $api_url = $selected_location_details['api_url'] ?? '';
        if ( ! wp_http_validate_url( $api_url ) ) {
            wp_send_json_error( 'Invalid or disallowed API URL.' );
        }
        $url      = $api_url . '?$top=1';
        $response = wp_remote_get( $url, array(
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Error retrieving API data: ' . $response->get_error_message() );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) {
            wp_send_json_error( 'API returned HTTP ' . $http_code . '.' );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( 'Unexpected API response shape.' );
        }

        $html = '';
        $span = '';
        $html .= '<option value="">- Select -</option>';
        foreach ( $data[0] as $key => $value ) {
            $html .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $key ) . '</option>';
        }
        $html .= '<option value="">Do not populate this field</option>';
        foreach ( $data[0] as $key => $value ) {
            $span .= '<span class="tag" data-tag="%' . esc_attr( $key ) . '%">%' . esc_attr( $key ) . '%</span>';
        }

        wp_send_json_success( array( 'data' => $html, 'spanData' => $span ) );
    }

    // Saves the combined ACF+WC field mapping collected by the primary pull_data button.
    public static function pull_data_lic_wc() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $selected_location = sanitize_text_field( $_POST['selected_location'] ?? '' );
        if ( ! $selected_location ) {
            wp_send_json_error( 'No location selected.' );
        }
        $raw_data = $_POST['temp_data'] ?? [];
        if ( ! is_array( $raw_data ) ) {
            wp_send_json_error( 'Invalid mapping data.' );
        }
        $sanitized_data = lic_sanitize_mapping_array( $raw_data );
        update_option( 'mapped_fields_' . $selected_location, $sanitized_data );
        update_option( 'lic_mapping_changed_' . sanitize_key( $selected_location ), time() );
        wp_send_json_success( 'Field mapping saved.' );
    }

    // Re-applies the saved field mapping to all existing products for a location.
    // Used when a new field mapping is added and existing products need to be updated
    // without waiting for those products to change in the API.
    public static function backfill_field_mapping() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $location_name = sanitize_text_field( $_POST['location_name'] ?? '' );
        if ( ! $location_name ) {
            wp_send_json_error( 'No location selected.' );
            return;
        }

        $offset     = max( 0, (int) ( $_POST['offset']     ?? 0  ) );
        $batch_size = max( 1, (int) ( $_POST['batch_size'] ?? 50 ) );

        try {
            self::do_backfill_field_mapping( $location_name, $offset, $batch_size );
        } catch ( Throwable $e ) {
            wp_send_json_error( $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
        }
    }

    private static function do_backfill_field_mapping( $location_name, $offset, $batch_size ) {
        @set_time_limit( 60 );

        $result = LIC_Importer::apply_field_mapping_to_location( $location_name, $offset, $batch_size );

        if ( $result === null ) {
            // Distinguish between "no mapping saved" and "no products/cache".
            $data = get_option( 'mapped_fields_' . $location_name );
            if ( ! is_array( $data ) ) {
                wp_send_json_error( 'No field mapping found for this location. Save the mapping first.' );
            }
            if ( empty( LIC_Cron_Manager::get_cached_data( $location_name ) ) ) {
                wp_send_json_error( 'API cache is empty for this location — warm the cache first.' );
            }
            wp_send_json_error( 'No products found for this location.' );
            return;
        }

        // On the final batch, record that a manual backfill was done.
        if ( ! $result['has_more'] ) {
            update_option( 'lic_backfill_done_' . sanitize_key( $location_name ), time() );
        }

        wp_send_json_success( [
            'updated'     => $result['updated'],
            'skipped'     => $result['skipped'],
            'total'       => $result['total'],
            'processed'   => $result['processed'],
            'has_more'    => $result['has_more'],
            'next_offset' => $result['next_offset'],
        ] );
    }

    // Saves the WooCommerce/ACF field mapping for a location.
    public static function save_mapped_fields() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        if ( isset( $_POST['selected_location'], $_POST['mapped_fields'] ) ) {
            $selected_location = sanitize_text_field( $_POST['selected_location'] );

            if ( isset( $_POST['custom_title'] ) ) {
                $custom_title = sanitize_text_field( wp_unslash( $_POST['custom_title'] ) );
                update_option( 'custom_title_' . $selected_location, $custom_title );
            }

            $data = $_POST['mapped_fields'] ?? [];
            if ( ! is_array( $data ) ) {
                wp_send_json_error( 'Invalid mapping data.' );
            }
            $sanitized_data = lic_sanitize_mapping_array( $data );
            $option_name    = 'mapped_fields_' . $selected_location;
            update_option( $option_name, $sanitized_data );
            update_option( 'lic_mapping_changed_' . sanitize_key( $selected_location ), time() );

            wp_send_json_success( 'Mapping saved successfully for location: ' . $selected_location );
        } else {
            wp_send_json_error( 'Invalid request. Missing parameters.' );
        }
    }

    // Returns the saved field mapping and custom title for a location.
    public static function get_mapped_fields() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        if ( isset( $_POST['location'] ) ) {
            $location      = sanitize_text_field( $_POST['location'] );
            $mapped_fields = get_option( 'mapped_fields_' . $location, array() );
            $custom_title  = get_option( 'custom_title_' . $location, '' );
            wp_send_json_success( array( 'mapped_fields' => $mapped_fields, 'custom_title' => $custom_title ) );
        } else {
            wp_send_json_error();
        }
    }

    // Returns the distinct UnitStatus values found across all cached API data.
    public static function get_distinct_unit_statuses() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lic_api_cache';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            return [];
        }
        // Table name is from $wpdb->prefix, not user input; no variable to prepare.
        $rows = $wpdb->get_results(
            "SELECT api_data FROM {$table_name}
             WHERE id IN (SELECT MAX(id) FROM {$table_name} GROUP BY location_name)" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $statuses = [];
        foreach ( $rows as $row ) {
            $data = json_decode( $row->api_data, true );
            if ( ! is_array( $data ) ) continue;
            foreach ( $data as $item ) {
                if ( array_key_exists( 'UnitStatus', $item ) ) {
                    $statuses[ (string) $item['UnitStatus'] ] = true;
                }
            }
            unset( $data );
        }
        return array_keys( $statuses );
    }

    // AJAX handler: returns distinct UnitStatus values.
    public static function refresh_unit_statuses() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        wp_send_json_success( self::get_distinct_unit_statuses() );
    }

    // Saves the inventory flag field and value mappings for featured/clearance actions.
    public static function save_inventory_flags() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        // Normalise comma-separated values: strip spaces, remove blanks, deduplicate.
        $parse_values = function( $raw ) {
            return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) ) );
        };

        $flags = [
            'flag_field'              => sanitize_text_field( $_POST['flag_field'] ?? 'Odometer' ) ?: 'Odometer',
            'featured_values'         => implode( ',', $parse_values( $_POST['featured_values']  ?? '2,3' ) ) ?: '2,3',
            'clearance_values'        => implode( ',', $parse_values( $_POST['clearance_values'] ?? '1,3' ) ) ?: '1,3',
            'clearance_category_slug' => sanitize_title( $_POST['clearance_category_slug'] ?? 'trailer-deals' ) ?: 'trailer-deals',
            'clearance_category_name' => sanitize_text_field( $_POST['clearance_category_name'] ?? 'Trailer Deals' ) ?: 'Trailer Deals',
        ];

        update_option( 'lic_inventory_flags', $flags );
        wp_send_json_success( 'Inventory flag settings saved.' );
    }

    // Syncs images for a single product from the API cache.
    // Replicates the importer's image logic: primary → thumbnail, rest → gallery, orphan cleanup.
    public static function sync_product_images() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( 'Invalid product ID.' );
        }

        $product = get_post( $product_id );
        if ( ! $product || $product->post_type !== 'product' ) {
            wp_send_json_error( 'Product not found.' );
        }

        $sku      = trim( (string) get_post_meta( $product_id, '_sku', true ) );
        $location = trim( (string) get_post_meta( $product_id, 'location', true ) );

        $cached   = LIC_Cron_Manager::get_cached_data( $location );
        $api_item = null;
        foreach ( $cached as $item ) {
            if ( trim( (string) ( $item['StockNumber'] ?? '' ) ) === $sku ) {
                $api_item = $item;
                break;
            }
        }

        if ( ! $api_item ) {
            $msg = empty( $cached )
                ? 'Cache is empty for location "' . esc_html( $location ) . '".'
                : 'SKU "' . esc_html( $sku ) . '" not found in API cache for location "' . esc_html( $location ) . '".';
            wp_send_json_error( $msg );
        }

        $image_gallery_data = $api_item['Images'] ?? [];
        $default_image_id   = (int) get_option( 'lic_featured_default_image' );

        $gallery_before   = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
        $thumbnail_before = (int) get_post_meta( $product_id, '_thumbnail_id', true );
        $ids_before       = array_values( array_unique( array_filter( array_merge( $gallery_before, $thumbnail_before ? [ $thumbnail_before ] : [] ) ) ) );

        $synced = 0;
        $errors = 0;

        if ( empty( $image_gallery_data ) ) {
            delete_post_meta( $product_id, '_product_image_gallery' );
            if ( $default_image_id ) {
                set_post_thumbnail( $product_id, $default_image_id );
                update_post_meta( $product_id, '_lic_uses_default_image', '1' );
            }
        } else {
            delete_post_meta( $product_id, '_lic_uses_default_image' );
            delete_post_meta( $product_id, 'product_default_image' );

            $primary_url = null;
            $other_urls  = [];
            foreach ( $image_gallery_data as $img ) {
                if ( ( $img['PrimaryImage'] ?? false ) === true && $primary_url === null ) {
                    $primary_url = $img['ImageUrl'] ?? '';
                } else {
                    $other_urls[] = $img['ImageUrl'] ?? '';
                }
            }
            $other_urls = array_values( array_filter( $other_urls ) );
            if ( $primary_url === null && ! empty( $other_urls ) ) {
                $primary_url = array_shift( $other_urls );
            }

            if ( $primary_url ) {
                $primary_id = LIC_Image_Handler::get_or_create_attachment( $primary_url );
                if ( $primary_id && ! is_wp_error( $primary_id ) ) {
                    // Backfill _lic_source_url on pre-existing attachments that were imported without it.
                    if ( ! get_post_meta( $primary_id, '_lic_source_url', true ) ) {
                        update_post_meta( $primary_id, '_lic_source_url', basename( parse_url( $primary_url, PHP_URL_PATH ) ) );
                    }
                    set_post_thumbnail( $product_id, $primary_id );
                    $synced++;
                } else {
                    $errors++;
                }
            }

            $new_gallery_ids = [];
            foreach ( $other_urls as $url ) {
                $att_id = LIC_Image_Handler::get_or_create_attachment( $url );
                if ( $att_id && ! is_wp_error( $att_id ) ) {
                    if ( ! get_post_meta( $att_id, '_lic_source_url', true ) ) {
                        update_post_meta( $att_id, '_lic_source_url', basename( parse_url( $url, PHP_URL_PATH ) ) );
                    }
                    $new_gallery_ids[] = $att_id;
                    $synced++;
                } else {
                    $errors++;
                }
            }
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $new_gallery_ids ) );
        }

        // Remove plugin-managed attachments no longer referenced.
        $gallery_after   = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
        $thumbnail_after = (int) get_post_meta( $product_id, '_thumbnail_id', true );
        $ids_after       = array_values( array_unique( array_filter( array_merge( $gallery_after, $thumbnail_after ? [ $thumbnail_after ] : [] ) ) ) );
        foreach ( array_diff( $ids_before, $ids_after ) as $orphaned_id ) {
            if ( $orphaned_id !== $default_image_id ) {
                wp_delete_attachment( $orphaned_id, true );
            }
        }

        wp_send_json_success( [
            'synced'  => $synced,
            'errors'  => $errors,
            'message' => $synced . ' image' . ( $synced !== 1 ? 's' : '' ) . ' synced' . ( $errors > 0 ? ', ' . $errors . ' error(s)' : '' ) . '.',
        ] );
    }

    // Clears all rows from the specified log type.
    public static function clear_log() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        global $wpdb;
        $log_type = sanitize_key( $_POST['log_type'] ?? '' );
        switch ( $log_type ) {
            case 'sync_summaries':
                // Table name is from $wpdb->prefix, not user input; no variable to prepare.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_sync_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                break;
            case 'api_errors':
                // Table name is from $wpdb->prefix, not user input; no variable to prepare.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_api_error_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                break;
            case 'queue_operations':
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}lic_cron_error_logs WHERE cron_name IN (%s, %s)",
                    'lic_process_queue_batch', 'run_product_import_cron'
                ) );
                break;
            case 'deleted_products':
                // Table name is from $wpdb->prefix, not user input; no variable to prepare.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_remove_products_logs" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                break;
            case 'delete_cron':
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}lic_cron_error_logs WHERE cron_name = %s",
                    'delete_sold_out_products'
                ) );
                break;
            case 'stock_check':
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}lic_cron_error_logs WHERE cron_name IN (%s, %s, %s)",
                    'check_product_exist_in_or_not', 'sold_unit_incremental', 'sold_unit_reconciliation'
                ) );
                break;
            case 'confirmed_sold':
                // Table name is from $wpdb->prefix, not user input; no variable to prepare.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_confirmed_sold" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                break;
            case 'sold_alerts':
                // Dismisses rather than deletes — see review_sold_alert()'s comment. Deleting let
                // the very next sync re-insert an identical row for any still-true condition.
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}lic_sold_review_queue SET dismissed_at = %s WHERE dismissed_at IS NULL",
                    current_time( 'mysql' )
                ) );
                break;
            case 'all_cron_logs':
                // Table name is from $wpdb->prefix, not user input; no variable to prepare.
                $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_cron_error_logs" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                break;
            default:
                wp_send_json_error( 'Unknown log type.' );
        }
        wp_send_json_success();
    }

    // Saves the UnitStatus → WooCommerce action mapping.
    public static function save_unit_status_map() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $allowed = [ 'publish', 'draft', 'trash', 'ignore' ];
        $raw_map = isset( $_POST['status_map_json'] )
            ? json_decode( wp_unslash( $_POST['status_map_json'] ), true )
            : [];
        if ( ! is_array( $raw_map ) ) {
            wp_send_json_error( 'Invalid data.' );
        }
        $sanitized = [];
        foreach ( $raw_map as $status => $action ) {
            if ( in_array( $action, $allowed, true ) ) {
                $sanitized[ sanitize_text_field( (string) $status ) ] = $action;
            }
        }
        global $wpdb;
        $wpdb->replace(
            $wpdb->options,
            [
                'option_name'  => 'lic_unit_status_map',
                'option_value' => maybe_serialize( $sanitized ),
                'autoload'     => 'yes',
            ],
            [ '%s', '%s', '%s' ]
        );
        wp_cache_delete( 'lic_unit_status_map', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_send_json_success( 'Unit status map saved.' );
    }

    // Scans the media library for orphaned plugin-downloaded images and returns results.
    public static function scan_orphaned_images() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        global $wpdb;

        // Build the set of attachment IDs currently in use.
        $thumbnail_ids = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
               AND pm.meta_value REGEXP '^[0-9]+$'
               AND p.post_status IN ('publish','draft','pending')"
        );

        $gallery_rows = $wpdb->get_col(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
               AND pm.meta_value != ''
               AND p.post_type = 'product'
               AND p.post_status IN ('publish','draft','pending')"
        );
        $gallery_ids = [];
        foreach ( $gallery_rows as $row ) {
            foreach ( array_filter( array_map( 'intval', explode( ',', $row ) ) ) as $id ) {
                $gallery_ids[] = $id;
            }
        }

        $in_use = array_flip( array_unique( array_merge(
            array_map( 'intval', $thumbnail_ids ),
            $gallery_ids
        ) ) );

        // Tier 1: attachments with _lic_source_url, not ignored.
        $tier1_rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, pm_src.meta_value AS source_url
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_src
                 ON pm_src.post_id = p.ID AND pm_src.meta_key = '_lic_source_url'
             LEFT JOIN {$wpdb->postmeta} pm_ign
                 ON pm_ign.post_id = p.ID AND pm_ign.meta_key = '_lic_image_ignored'
             WHERE p.post_type = 'attachment'
               AND pm_ign.meta_id IS NULL
             ORDER BY p.ID"
        );

        // Tier 2: attachments without _lic_source_url, parented to a product, not ignored.
        $tier2_rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->posts} par
                 ON par.ID = p.post_parent AND par.post_type = 'product'
             LEFT JOIN {$wpdb->postmeta} pm_src
                 ON pm_src.post_id = p.ID AND pm_src.meta_key = '_lic_source_url'
             LEFT JOIN {$wpdb->postmeta} pm_ign
                 ON pm_ign.post_id = p.ID AND pm_ign.meta_key = '_lic_image_ignored'
             WHERE p.post_type = 'attachment'
               AND pm_src.meta_id IS NULL
               AND pm_ign.meta_id IS NULL
             ORDER BY p.ID"
        );

        $results     = [];
        $total_size  = 0;
        $tier1_count = 0;
        $tier2_count = 0;

        $add_result = function( $id, $title, $tier, $source_url = '' ) use (
            &$results, &$total_size, &$tier1_count, &$tier2_count, $in_use
        ) {
            if ( isset( $in_use[ $id ] ) ) return;
            // _thumbnail_id/_product_image_gallery only cover WooCommerce's own image
            // relationships — this also catches the image being placed directly on a
            // page/post via Elementor or raw content, which those meta keys can't see.
            if ( LIC_Image_Handler::is_attachment_in_use_elsewhere( $id ) ) return;
            $path      = get_attached_file( $id );
            $size      = ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
            $thumb_url = wp_get_attachment_image_url( $id, 'thumbnail' ) ?: '';
            $filename  = $path ? basename( $path ) : $title;

            $results[] = [
                'id'         => $id,
                'thumb_url'  => $thumb_url,
                'filename'   => $filename,
                'source_url' => $source_url,
                'file_size'  => $size,
                'tier'       => $tier,
            ];
            $total_size += $size;
            if ( $tier === 1 ) $tier1_count++;
            else $tier2_count++;
        };

        foreach ( $tier1_rows as $row ) {
            $add_result( (int) $row->ID, $row->post_title, 1, $row->source_url ?? '' );
        }
        foreach ( $tier2_rows as $row ) {
            $add_result( (int) $row->ID, $row->post_title, 2 );
        }

        // Store scan ID list in a transient so delete can validate against it.
        set_transient(
            'lic_orphan_scan_' . get_current_user_id(),
            array_column( $results, 'id' ),
            HOUR_IN_SECONDS
        );

        wp_send_json_success( [
            'total'       => count( $results ),
            'tier1_count' => $tier1_count,
            'tier2_count' => $tier2_count,
            'total_size'  => $total_size,
            'images'      => $results,
        ] );
    }

    // Deletes a list of orphaned attachment IDs that were found in the most recent scan.
    public static function delete_orphaned_images() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $ids_raw = json_decode( stripslashes( $_POST['ids'] ?? '[]' ), true );
        if ( ! is_array( $ids_raw ) ) {
            wp_send_json_error( 'Invalid ID list.' );
        }
        $ids = array_values( array_filter( array_map( 'intval', $ids_raw ) ) );
        if ( empty( $ids ) ) {
            wp_send_json_success( [ 'deleted' => 0, 'skipped' => 0, 'errors' => 0 ] );
        }

        $scan_ids = get_transient( 'lic_orphan_scan_' . get_current_user_id() );
        if ( ! is_array( $scan_ids ) ) {
            wp_send_json_error( 'Scan results have expired. Please scan again before deleting.' );
        }
        $scan_set = array_flip( $scan_ids );

        $deleted = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ( $ids as $id ) {
            if ( ! isset( $scan_set[ $id ] ) ) {
                $skipped++;
                continue;
            }
            if ( get_post_meta( $id, '_lic_image_ignored', true ) ) {
                $skipped++;
                continue;
            }
            $result = wp_delete_attachment( $id, true );
            if ( $result === false || $result === null ) {
                $errors++;
            } else {
                $deleted++;
            }
        }

        wp_send_json_success( [
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors'  => $errors,
        ] );
    }

    // Marks an attachment as ignored so it is excluded from all future orphan scans.
    public static function ignore_orphaned_image() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $id = absint( $_POST['attachment_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid attachment ID.' );
        }
        update_post_meta( $id, '_lic_image_ignored', '1' );
        wp_send_json_success();
    }

    // Returns explicitly registered product fields: ACF fields and WooCommerce product attributes.
    // Deliberately avoids raw postmeta discovery to prevent plugin-generated noise from appearing.
    public static function get_available_product_fields() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $acf_fields = [];
        $wc_fields  = [];

        // ACF fields registered to the product post type.
        if ( function_exists( 'acf_get_field_groups' ) ) {
            $groups = acf_get_field_groups( [ 'post_type' => 'product' ] );
            foreach ( $groups as $group ) {
                $fields = acf_get_fields( $group['key'] );
                if ( is_array( $fields ) ) {
                    foreach ( $fields as $field ) {
                        // Skip layout/tab/group pseudo-fields that store no value.
                        if ( in_array( $field['type'], [ 'tab', 'accordion', 'message', 'group', 'repeater', 'flexible_content' ], true ) ) {
                            continue;
                        }
                        $acf_fields[] = [
                            'key'    => $field['name'],
                            'label'  => $field['label'],
                            'source' => 'acf',
                        ];
                    }
                }
            }
            usort( $acf_fields, fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );
        }

        // WooCommerce registered product attribute taxonomies.
        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            $taxonomies = wc_get_attribute_taxonomies();
            foreach ( $taxonomies as $tax ) {
                $wc_fields[] = [
                    'key'    => wc_sanitize_taxonomy_name( $tax->attribute_name ),
                    'label'  => $tax->attribute_label,
                    'source' => 'wc',
                ];
            }
            usort( $wc_fields, fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );
        }

        $result = array_merge( $acf_fields, $wc_fields );

        if ( empty( $result ) ) {
            wp_send_json_error( 'No registered product fields found.' );
        }

        wp_send_json_success( $result );
    }

    // Saves the extra fields list for the no-images table in the daily image email.
    public static function save_image_email_extra_fields() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $raw    = stripslashes( $_POST['fields'] ?? '[]' );
        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) ) {
            wp_send_json_error( 'Invalid data.' );
        }

        $clean = [];
        foreach ( $parsed as $field ) {
            $label = sanitize_text_field( $field['label'] ?? '' );
            $key   = sanitize_text_field( $field['key']   ?? '' );
            $type  = in_array( $field['type'] ?? '', [ 'wc', 'acf' ], true ) ? $field['type'] : 'wc';
            if ( $label !== '' && $key !== '' ) {
                $clean[] = [ 'label' => $label, 'key' => $key, 'type' => $type ];
            }
        }

        update_option( 'lic_image_email_extra_fields', wp_json_encode( $clean ) );
        wp_send_json_success( 'Extra fields saved.' );
    }

    // Saves image email settings and reschedules the daily report cron.
    public static function save_image_email_settings() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $send_time_local = sanitize_text_field( $_POST['send_time'] ?? '15:00' );
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $send_time_local ) ) {
            wp_send_json_error( 'Invalid time format.' );
        }

        $dt = new DateTime( 'today ' . $send_time_local, wp_timezone() );
        $dt->setTimezone( new DateTimeZone( 'UTC' ) );
        $send_time_utc = $dt->format( 'H:i' );

        $raw_recipients = sanitize_text_field( $_POST['email_recipients'] ?? '' );
        $validated      = array_filter(
            array_map( 'sanitize_email', array_map( 'trim', explode( ',', $raw_recipients ) ) )
        );
        $recipients_clean = implode( ', ', $validated );

        $filter        = in_array( $_POST['email_filter'] ?? '', [ 'mismatches', 'all' ], true )
                         ? sanitize_key( $_POST['email_filter'] )
                         : 'mismatches';
        $min_api_count = max( 0, (int) ( $_POST['min_api_count'] ?? 0 ) );

        update_option( 'lic_image_email_recipients',    $recipients_clean );
        update_option( 'lic_image_email_send_time',     $send_time_utc );
        update_option( 'lic_image_email_filter',        $filter );
        update_option( 'lic_image_email_min_api_count', $min_api_count );

        wp_clear_scheduled_hook( 'generate_daily_image_report' );
        wp_schedule_event(
            LIC_Cron_Manager::next_warm_timestamp( $send_time_utc ),
            'daily',
            'generate_daily_image_report'
        );

        wp_send_json_success( 'Email settings saved.' );
    }
}
