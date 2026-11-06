<?php

class LIC_Importer {

    public static function init() {
        add_action( 'wp_ajax_pull_inventory', array( __CLASS__, 'ajax_pull_inventory' ) );
        add_action( 'lic_process_queue_cron', array( __CLASS__, 'process_queue_batch' ) );
        add_action( 'wp_ajax_lic_get_queue_status', array( __CLASS__, 'ajax_get_queue_status' ) );
        add_action( 'wp_ajax_lic_save_batch_size', array( __CLASS__, 'ajax_save_batch_size' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );
        add_action( 'plugins_loaded', array( __CLASS__, 'cleanup_legacy_cron' ) );
        add_action( 'wp', array( __CLASS__, 'schedule_product_check' ) );
        add_action( 'check_product_exist_cron', array( __CLASS__, 'check_products_in_api' ) );
        add_action( 'delete_sold_out_products_event', array( __CLASS__, 'delete_sold_out_products' ) );
        add_action( 'init', array( __CLASS__, 'delete_empty_numeric_categories' ) );
        add_action( 'lic_log_cleanup_cron', array( __CLASS__, 'cleanup_logs' ) );
    }

    // Registers custom cron intervals used by the import pipeline.
    public static function add_cron_schedules( $schedules ) {
        $schedules['every_2_minutes'] = array( 'interval' => 120, 'display' => __( 'Every 2 Minutes' ) );
        $schedules['lic_monthly']     = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => __( 'Once Monthly (LIC)' ) );
        return $schedules;
    }

    // Removes the legacy per-minute import cron if still scheduled from an old deploy.
    public static function cleanup_legacy_cron() {
        if ( wp_next_scheduled( 'run_product_import_cron' ) ) {
            wp_clear_scheduled_hook( 'run_product_import_cron' );
        }
    }

    // AJAX handler: starts a new import queue run.
    public static function ajax_pull_inventory() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        self::lic_queue_start();
        wp_send_json_success( 'Import queue started.' );
    }

    // Initialises a fresh import queue for all locations.
    public static function lic_queue_start() {
        if ( get_option( 'lic_sync_enabled', '1' ) === '0' ) {
            return;
        }
        global $wpdb;
        $table      = $wpdb->prefix . 'lic_import_queue';
        $settings   = get_option( 'lic_settings', [] );
        $locations  = $settings['locations'] ?? [];
        $batch_size = max( 1, (int) get_option( 'lic_batch_size', 25 ) );

        $wpdb->query( "DELETE FROM {$table} WHERE status IN ('pending','processing','error')" );

        foreach ( $locations as $location_data ) {
            $loc = $location_data['name'] ?? '';
            if ( empty( $loc ) ) continue;
            $keep_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE status = 'complete' AND location_name = %s ORDER BY id DESC LIMIT 5",
                $loc
            ) );
            if ( ! empty( $keep_ids ) ) {
                $placeholders = implode( ',', array_map( 'intval', $keep_ids ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$table} WHERE status = 'complete' AND location_name = %s AND id NOT IN ({$placeholders})",
                    $loc
                ) );
            }
        }

        foreach ( $locations as $location_data ) {
            $location_name = $location_data['name'] ?? '';
            if ( empty( $location_name ) ) continue;

            delete_option( "cron_import_status_{$location_name}" );
            update_option( "cron_api_skip_value_{$location_name}", 0 );

            $wpdb->insert( $table, [
                'location_name'     => $location_name,
                'current_offset'    => 0,
                'batch_size'        => $batch_size,
                'status'            => 'pending',
                'created_at'        => current_time( 'mysql' ),
                'last_processed_at' => null,
            ], [ '%s', '%d', '%d', '%s', '%s', '%s' ] );

            $wpdb->insert( $wpdb->prefix . 'lic_sync_log', [
                'location_name' => $location_name,
                'started_at'    => current_time( 'mysql' ),
            ], [ '%s', '%s' ] );
            update_option( "lic_sync_log_current_{$location_name}", $wpdb->insert_id );
        }

        wp_clear_scheduled_hook( 'lic_process_queue_cron' );
        wp_schedule_event( time(), 'every_2_minutes', 'lic_process_queue_cron' );
    }

    // Processes a single batch from the import queue.
    public static function process_queue_batch() {
        global $wpdb;
        $table       = $wpdb->prefix . 'lic_import_queue';
        $cron_name   = 'lic_process_queue_batch';
        $batch_start = current_time( 'mysql' );

        self::log_cron_error( $cron_name, 'Batch processor started', $batch_start );

        // Reset items stuck in 'processing' for more than 5 minutes (e.g. PHP timeout mid-run).
        $reset_count = (int) $wpdb->query(
            "UPDATE {$table} SET status = 'pending'
             WHERE status = 'processing'
             AND last_processed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        if ( $reset_count > 0 ) {
            self::log_cron_error( $cron_name, "Reset {$reset_count} stuck 'processing' item(s) to 'pending' after 5-minute timeout.", $batch_start );
        }

        // Claim the next pending row atomically — a separate SELECT-then-UPDATE here was a
        // real check-then-act race: two overlapping process_queue_batch() invocations (observed
        // in production — duplicate "Batch processor started" log entries at identical
        // timestamps) could both SELECT the same row before either committed its UPDATE, then
        // both run_import() the identical batch in parallel. That let two processes both
        // download/create the same image concurrently, producing duplicate attachment posts
        // pointing at the same physical file — deleting either duplicate later (via the daily
        // cleanup or the manual image-cleanup tool) took the file out from under the other,
        // still-referenced one. A single UPDATE ... ORDER BY ... LIMIT 1 takes the row lock
        // atomically, so only one process can ever claim a given row. LAST_INSERT_ID(id) is a
        // standard MySQL idiom for finding out which row an UPDATE (not just INSERT) touched —
        // it's session-scoped, so it's immune to another connection's concurrent claim.
        $affected = $wpdb->query(
            "UPDATE {$table}
             SET status = 'processing', last_processed_at = NOW(), id = LAST_INSERT_ID(id)
             WHERE status = 'pending'
             ORDER BY id ASC
             LIMIT 1"
        );

        if ( ! $affected ) {
            // Don't clear the cron if items are still 'processing' — they may reset on the next tick.
            $still_processing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'processing'" );
            if ( $still_processing > 0 ) {
                self::log_cron_error( $cron_name, "No 'pending' entries but {$still_processing} item(s) still 'processing' — leaving cron scheduled to allow recovery.", $batch_start );
                return;
            }
            self::log_cron_error( $cron_name, 'Queue empty — clearing scheduled cron hook.', $batch_start, current_time( 'mysql' ) );
            wp_clear_scheduled_hook( 'lic_process_queue_cron' );
            return;
        }

        $claimed_id = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
        $entry      = $claimed_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $claimed_id ) ) : null;
        if ( ! $entry ) {
            return;
        }

        update_option( "cron_api_skip_value_{$entry->location_name}", $entry->current_offset );
        delete_option( "cron_import_status_{$entry->location_name}" );

        self::maybe_backfill_field_mapping( $entry->location_name );
        // Scoped to this claimed row's location only — run_import() skips every other
        // location by name, so it never reads or writes their cron_import_status_*/
        // cron_api_skip_value_* options. Previously this function relied on those shared
        // options alone (via a "mark every other location completed" side channel) to
        // decide what to process, which meant two process_queue_batch() invocations
        // legitimately claiming two DIFFERENT locations' rows at the same time (routine
        // given ~2-4 minute batches on a 2-minute cron interval) would each stomp on the
        // option the other was relying on mid-run. Confirmed live: one invocation's
        // run_import() call ended up silently processing both Thomasville AND
        // Winston-Salem in a single ~4-minute run after a second, overlapping invocation
        // reopened Winston-Salem's "completed" flag mid-flight — and the offset/status
        // readback below then reflected the wrong location's progress, marking
        // Thomasville's queue row "complete" 46 items short of its real total with
        // nothing logged as an error.
        self::run_import( $entry->location_name );

        $new_offset    = (int) get_option( "cron_api_skip_value_{$entry->location_name}", 0 );
        $import_status = get_option( "cron_import_status_{$entry->location_name}", 'pending' );
        $new_status    = ( $import_status === 'completed' ) ? 'complete' : 'pending';

        $wpdb->update( $table,
            [ 'status' => $new_status, 'current_offset' => $new_offset, 'last_processed_at' => current_time( 'mysql' ) ],
            [ 'id' => $entry->id ],
            [ '%s', '%d', '%s' ], [ '%d' ]
        );

        self::log_cron_error(
            $cron_name,
            "Batch complete for location '{$entry->location_name}': status={$new_status}, offset={$new_offset}",
            $batch_start,
            current_time( 'mysql' )
        );
    }

    // Returns all import queue rows ordered by most recent first.
    public static function get_queue_status() {
        global $wpdb;
        $table = $wpdb->prefix . 'lic_import_queue';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
    }

    // AJAX handler: returns queue status and next scheduled run time.
    public static function ajax_get_queue_status() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $next_run = wp_next_scheduled( 'lic_process_queue_cron' );
        wp_send_json_success( [
            'entries'  => self::get_queue_status(),
            'next_run' => $next_run ? human_time_diff( time(), $next_run ) : null,
        ] );
    }

    // AJAX handler: saves the import batch size setting.
    public static function ajax_save_batch_size() {
        check_ajax_referer( 'lic_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        $size = max( 5, min( 100, (int) ( $_POST['batch_size'] ?? 25 ) ) );
        update_option( 'lic_batch_size', $size );
        wp_send_json_success( 'Batch size saved.' );
    }

    // Returns true if any post field or meta value in $product_data differs from what's
    // currently stored for $product_id — used by run_import() to skip a no-op wp_update_post()
    // call. Calling wp_update_post() unconditionally on every product every sync (regardless of
    // whether anything changed) fires the full post-save hook cascade for nothing — this was
    // observed triggering Yoast SEO's indexable rebuild (and a duplicate-key DB error from it)
    // on every single product, every day, and is a meaningful part of the daily sync's runtime.
    private static function product_data_changed( $product_id, array $product_data ) {
        $post = get_post( $product_id );
        if ( ! $post ) {
            return true; // Can't compare — safe default is to let it update.
        }

        foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_status' ] as $field ) {
            if ( isset( $product_data[ $field ] ) && (string) $product_data[ $field ] !== (string) $post->$field ) {
                return true;
            }
        }

        foreach ( $product_data['meta_input'] ?? [] as $key => $value ) {
            $current = get_post_meta( $product_id, $key, true );
            if ( is_array( $value ) || is_array( $current ) ) {
                if ( $value !== $current ) {
                    return true;
                }
            } elseif ( (string) $value !== (string) $current ) {
                return true;
            }
        }

        return false;
    }

    // Runs one batch of the product import from the API cache.
    // Its WC/ACF field-mapping logic below has a counterpart in apply_field_mapping_to_location()
    // (used by the manual backfill button) — they're intentionally NOT unified: this path uses a
    // fixed meta-key allowlist with unsanitized values written via meta_input at post-creation time,
    // while the backfill path sanitizes and covers any mapped field via direct update_post_meta().
    // Check both when changing field-mapping behavior.
    // $only_location, when given, restricts this run to that single location — every other
    // configured location is skipped by name before any shared option for it is even read.
    // process_queue_batch() always passes its claimed row's location; this is what makes
    // two concurrent invocations for two different locations safe to run at the same time
    // (see the comment at its call site).
    public static function run_import( $only_location = null ) {
        global $wpdb;

        $cron_name  = 'run_product_import_cron';
        $sync_start = current_time( 'mysql' );

        $current_inventory = 0;
        $items_removed     = 0;
        $images_added      = 0;

        $settings    = get_option( 'lic_settings' );
        $alllocation = $settings['locations'] ?? '';

        $all_successes = array();
        $api_skus      = array();
        $all_product_id = array();
        $top           = max( 1, (int) get_option( 'lic_batch_size', 25 ) );
        $existing_skus = array();

        $has_more_data = false;
        try {
            self::log_cron_error( $cron_name, 'Product Import started', $sync_start );
            foreach ( $alllocation as $location_data ) {
                $location_name = $location_data['name'];

                if ( $only_location !== null && $location_name !== $only_location ) {
                    continue;
                }

                $import_status = get_option( "cron_import_status_{$location_name}", 'pending' );
                if ( $import_status === 'completed' ) {
                    continue;
                }

                if ( ! empty( $location_name ) ) {
                    $data = get_option( 'mapped_fields_' . $location_name );

                    if ( isset( $location_name, $data ) ) {
                        $skip = get_option( "cron_api_skip_value_{$location_name}", 0 );

                        $loc_created = 0;
                        $loc_updated = 0;
                        $loc_trashed = 0;
                        $loc_ignored = 0;
                        $loc_errors  = 0;

                        $location_api_status = false;

                        $existing_products = wc_get_products( array(
                            'limit'  => -1,
                            'return' => 'ids',
                        ) );

                        foreach ( $existing_products as $product_id ) {
                            $existing_skus[ $product_id ] = get_post_meta( $product_id, '_sku', true );
                        }

                        $cached_api_data = LIC_Cron_Manager::get_cached_data( $location_name );

                        if ( empty( $cached_api_data ) ) {
                            self::log_cron_error( $cron_name, "No cached data found for location: $location_name", $sync_start );
                            update_option( "cron_import_status_{$location_name}", 'completed' );
                            continue;
                        }

                        $total_cached_items = count( $cached_api_data );

                        if ( $skip >= $total_cached_items ) {
                            self::log_cron_error( $cron_name, "All cached data processed for location: $location_name", $sync_start );
                            update_option( "cron_import_status_{$location_name}", 'completed' );
                            $has_more_data = false;
                            continue;
                        }

                        $api_data = array_slice( $cached_api_data, $skip, $top );

                        if ( empty( $api_data ) ) {
                            self::log_cron_error( $cron_name, "No cached data available for processing for location: $location_name", $sync_start );
                            update_option( "cron_import_status_{$location_name}", 'completed' );
                            $has_more_data = false;
                            continue;
                        }

                        $location_api_status = true;

                        if ( ! empty( $api_data ) ) {
                            $ACF_fields = isset( $data['ACF'] ) ? $data['ACF'] : array();
                            $WC_fields  = isset( $data['WC'] ) ? $data['WC'] : array();

                            $updated_count = 0;

                            foreach ( $api_data as $index => $api_values ) {

                                try {

                                    $api_sku = $api_values[ $WC_fields['api_fields']['_sku'] ] ?? '';
                                    if ( empty( $api_sku ) ) {
                                        self::log_cron_error( $cron_name, "Missing SKU for product at index $index in location: $location_name", $sync_start );
                                        continue;
                                    }
                                    if ( ! empty( $api_sku ) ) {
                                        $api_skus[] = $api_sku;
                                    }

                                    // The Unit feed can keep listing a stock number for months after it's
                                    // actually sold (UnitStatus stays blank there — see SoldUnit). Once
                                    // SoldUnit has confirmed a sale, that overrides whatever Unit still
                                    // says: skip entirely so this sync never republishes/re-instocks it.
                                    // VIN comparison lets a different vehicle that reused the same stock
                                    // number through — see is_sku_confirmed_sold().
                                    if ( self::is_sku_confirmed_sold( $api_sku, (string) ( $api_values['VIN'] ?? '' ) ) ) {
                                        $loc_ignored++;
                                        continue;
                                    }

                                    $get_custom_title_option_name = get_option( 'custom_title_' . $location_name, '' );

                                    $product_title = '';

                                    if ( $get_custom_title_option_name ) {
                                        $match_count = preg_match_all( '/%[^%]+%|[^% ]+/', $get_custom_title_option_name, $matches );
                                        $parts       = $matches[0];

                                        $variables = array();
                                        foreach ( $parts as $index => $part ) {
                                            $variables[ $index ] = $part;
                                        }

                                        $formatted_title = '';
                                        foreach ( $variables as $key => $value ) {
                                            if ( preg_match( '/^%[^%]+%$/', $value ) ) {
                                                $key = trim( $value, '%' );
                                                if ( array_key_exists( $key, $api_values ) ) {
                                                    $replacement = $api_values[ $key ];
                                                    if ( $replacement === 'N' || $replacement == 'New' || $replacement == 'NEW' || $replacement == 'Other' || $replacement == 'OTHER' ) {
                                                        $replacement = '';
                                                    } elseif ( $replacement === 'U' ) {
                                                        $replacement = 'Used';
                                                    } elseif ( $replacement == '0' ) {
                                                        $replacement = '';
                                                    } elseif ( $replacement == 'North Carolina Trailer Sales Thomasville' ) {
                                                        $replacement = 'Thomasville';
                                                    } elseif ( $replacement == 'North Carolina Trailer Sales North Winston' ) {
                                                        $replacement = 'Winston-Salem';
                                                    }
                                                    $formatted_title .= $replacement . ' ';
                                                    if ( $key == 'Location' && ( empty( $replacement ) || $replacement == null ) ) {
                                                        if ( str_ends_with( trim( $formatted_title ), 'for sale in' ) ) {
                                                            $formatted_title = substr( $formatted_title, 0, strrpos( trim( $formatted_title ), 'for sale in' ) );
                                                        }
                                                    }
                                                } else {
                                                    $formatted_title .= $value . ' ';
                                                }
                                            } else {
                                                $formatted_title .= $value . ' ';
                                            }
                                        }
                                        $product_title = trim( $formatted_title );
                                    }

                                    if ( empty( $product_title ) ) {
                                        $product_title = $api_values['Model'] ?? $api_values['ModelName'] ?? 'Unknown';
                                    }

                                    $product_data = array(
                                        'post_title'   => $product_title,
                                        'post_content' => $api_values[ $WC_fields['api_fields']['post_content'] ] ?? '',
                                        'post_excerpt' => $api_values[ $WC_fields['api_fields']['post_excerpt'] ] ?? '',
                                        'post_status'  => 'publish',
                                        'post_type'    => 'product',
                                        'meta_input'   => array(),
                                    );

                                    foreach ( $WC_fields['api_fields'] as $meta_key => $api_field ) {
                                        if ( isset( $api_values[ $api_field ] ) ) {
                                            switch ( $meta_key ) {
                                                case '_sku':
                                                case '_stock':
                                                case '_manage_stock':
                                                case '_weight':
                                                case '_length':
                                                case '_width':
                                                case '_height':
                                                case '_product_condition_field':
                                                case '_sale_price_dates_from':
                                                case '_sale_price_dates_to':
                                                    $product_data['meta_input'][ $meta_key ] = $api_values[ $api_field ];
                                                    break;
                                            }
                                        }
                                    }

                                    if ( $ACF_fields ) {
                                        if ( isset( $ACF_fields['api_fields'] ) && is_array( $ACF_fields['api_fields'] ) ) {
                                            foreach ( $ACF_fields['api_fields'] as $field_name => $api_field ) {
                                                if ( empty( $field_name ) || ( $api_field === '' && $field_name !== 'Location' ) ) {
                                                    continue;
                                                }
                                                if ( $field_name == 'Location' ) {
                                                    $product_data['meta_input']['location'] = $location_name;
                                                } elseif ( $field_name == 'New/Used' ) {
                                                    if ( $api_values[ $api_field ] == 'N' ) {
                                                        $product_data['meta_input']['newused']                  = 'New';
                                                        $product_data['meta_input']['_product_condition_field'] = 'New';
                                                    } elseif ( $api_values[ $api_field ] == 'U' ) {
                                                        $product_data['meta_input']['newused']                  = 'Used';
                                                        $product_data['meta_input']['_product_condition_field'] = 'Used';
                                                    }
                                                } else {
                                                    if ( isset( $api_values[ $api_field ] ) ) {
                                                        $meta_key_lower                              = strtolower( $field_name );
                                                        $product_data['meta_input']['location']      = $location_name;
                                                        $product_data['meta_input']['video']         = $api_values['videourl'];
                                                        $product_data['meta_input'][ $meta_key_lower ] = $api_values[ $api_field ];
                                                    }
                                                }
                                            }
                                        }
                                    }

                                    global $wpdb;
                                    $sku = $product_data['meta_input']['_sku'];

                                    // Named lock scoped to this SKU — the check-then-insert below isn't
                                    // atomic on its own, and overlapping sync invocations (a known,
                                    // longstanding issue with this hosting's WP-Cron setup — see the
                                    // atomic queue-claim in process_queue_batch()) can both see "no
                                    // existing product" for a brand-new SKU at the same instant and both
                                    // insert. Confirmed live: three Winston-Salem units each ended up
                                    // with two identical published posts, created in the same second.
                                    // GET_LOCK() is a real cross-process mutex at the database layer
                                    // (each PHP process holds its own connection), so only one process
                                    // can run the check-and-create sequence for a given SKU at a time no
                                    // matter how many overlapping processes are racing.
                                    $lock_name = 'lic_sku_' . md5( $sku );
                                    if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name ) ) ) {
                                        throw new Exception( "Could not acquire SKU lock for {$sku} within 10s — another process is likely handling it; will retry next cycle." );
                                    }

                                    try {
                                        // Joined against wp_posts so a stale _sku meta row left behind
                                        // by a post that no longer exists doesn't get treated as an
                                        // existing product. Without this, wp_update_post() against that
                                        // dead ID fails silently (returns 0, no exception, nothing
                                        // logged) and the SKU can never be created — confirmed live for
                                        // three stock numbers whose _sku meta pointed at post IDs with
                                        // no matching row in wp_posts at all.
                                        $existing_product_id = $wpdb->get_var( $wpdb->prepare(
                                            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                                             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                                             WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
                                               AND p.post_type = 'product'
                                               AND p.post_status != 'auto-draft'
                                             LIMIT 1",
                                            $sku
                                        ) );

                                        if ( $existing_product_id ) {
                                            if ( self::product_data_changed( $existing_product_id, $product_data ) ) {
                                                $product_data['ID'] = $existing_product_id;
                                                $product_id         = wp_update_post( $product_data );
                                                if ( $product_id ) $loc_updated++;
                                            } else {
                                                // Nothing to write — skip wp_update_post() entirely. Calling it
                                                // unconditionally on every product every sync (even when nothing
                                                // changed) fires the full post-save hook cascade for no reason —
                                                // this is what was triggering Yoast SEO's indexable rebuild (and a
                                                // duplicate-key DB error from it) on every single product, every day.
                                                $product_id = (int) $existing_product_id;
                                            }
                                        } else {
                                            $product_id = wp_insert_post( $product_data );
                                            if ( $product_id ) $loc_created++;
                                        }
                                    } finally {
                                        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
                                    }

                                    if ( $product_id ) {

                                        $status_map  = get_option( 'lic_unit_status_map', [ '' => 'publish' ] );
                                        $unit_status = (string) ( $api_values['UnitStatus'] ?? '' );
                                        $action      = $status_map[ $unit_status ] ?? 'ignore';

                                        switch ( $action ) {
                                            case 'publish':
                                                wp_update_post( [ 'ID' => $product_id, 'post_status' => 'publish' ] );
                                                update_post_meta( $product_id, '_stock_status', 'instock' );
                                                break;
                                            case 'draft':
                                                wp_update_post( [ 'ID' => $product_id, 'post_status' => 'draft' ] );
                                                break;
                                            case 'trash':
                                                self::lic_remove_product( $product_id );
                                                $loc_trashed++;
                                                break;
                                            case 'ignore':
                                            default:
                                                $loc_ignored++;
                                                break;
                                        }

                                        $category_names    = array();
                                        $category_term_ids = array();

                                        if ( ! empty( $WC_fields['api_fields']['product_cat'] ) ) {
                                            $product_category_raw = $api_values[ $WC_fields['api_fields']['product_cat'] ] ?? '';
                                            if ( is_array( $product_category_raw ) ) {
                                                $category_names = $product_category_raw;
                                            } elseif ( is_string( $product_category_raw ) && $product_category_raw !== '' ) {
                                                $category_names = preg_split( '/[|,;]+/', $product_category_raw );
                                            }
                                        }

                                        $category_names = array_values( array_unique( array_filter( array_map( 'trim', $category_names ) ) ) );

                                        foreach ( $category_names as $category_name ) {
                                            $category_exists = term_exists( $category_name, 'product_cat' );
                                            if ( ! $category_exists ) {
                                                $new_category = wp_insert_term( $category_name, 'product_cat' );
                                                if ( is_wp_error( $new_category ) ) {
                                                    $loc_errors++;
                                                    continue;
                                                }
                                                $category_term_ids[] = (int) $new_category['term_id'];
                                            } else {
                                                $category_term_ids[] = (int) ( is_array( $category_exists ) ? $category_exists['term_id'] : $category_exists );
                                            }
                                        }

                                        $flag_map        = get_option( 'lic_inventory_flags', [] );
                                        $flag_field      = $flag_map['flag_field'] ?? 'Odometer';
                                        $featured_ints   = array_map( 'intval', array_filter( explode( ',', $flag_map['featured_values']  ?? '2,3' ) ) );
                                        $clearance_ints  = array_map( 'intval', array_filter( explode( ',', $flag_map['clearance_values']  ?? '1,3' ) ) );
                                        $clearance_slug  = $flag_map['clearance_category_slug'] ?? 'trailer-deals';
                                        $clearance_name  = $flag_map['clearance_category_name'] ?? 'Trailer Deals';

                                        // Normalise to int so '2', 2, 2.0, and '2.00' all match.
                                        $flag_raw        = $api_values[ $flag_field ] ?? null;
                                        $flag_int        = is_numeric( $flag_raw ) ? (int) $flag_raw : null;

                                        $add_to_clearance = $flag_int !== null && in_array( $flag_int, $clearance_ints, true );
                                        $mark_featured    = $flag_int !== null && in_array( $flag_int, $featured_ints, true );

                                        if ( $add_to_clearance ) {
                                            $clearance_term = get_term_by( 'slug', $clearance_slug, 'product_cat' );
                                            if ( ! $clearance_term ) {
                                                $clearance_term = wp_insert_term( $clearance_name, 'product_cat', array( 'slug' => $clearance_slug ) );
                                            }
                                            if ( ! is_wp_error( $clearance_term ) && ! empty( $clearance_term ) ) {
                                                $category_term_ids[] = (int) ( is_array( $clearance_term ) ? $clearance_term['term_id'] : $clearance_term->term_id );
                                            }
                                        }

                                        if ( function_exists( 'update_field' ) ) {
                                            update_field( 'odometer', $flag_int ?? 0, $product_id );
                                        }
                                        lic_set_product_featured_status( $product_id, $mark_featured );

                                        $is_used_item = false;
                                        if ( isset( $api_values['NewUsed'] ) && (string) $api_values['NewUsed'] === 'U' ) {
                                            $is_used_item = true;
                                        } elseif ( ! empty( $product_data['meta_input']['newused'] ) && strtolower( (string) $product_data['meta_input']['newused'] ) === 'used' ) {
                                            $is_used_item = true;
                                        }

                                        $is_trailer = false;
                                        foreach ( $category_names as $category_name ) {
                                            if ( stripos( $category_name, 'trailer' ) !== false ) {
                                                $is_trailer = true;
                                                break;
                                            }
                                        }

                                        if ( $is_used_item && $is_trailer ) {
                                            $used_trailer_slug = 'used-trailer';
                                            $used_trailer_term = get_term_by( 'slug', $used_trailer_slug, 'product_cat' );
                                            if ( ! $used_trailer_term ) {
                                                $used_trailer_term = wp_insert_term( 'Used Trailer', 'product_cat', array( 'slug' => $used_trailer_slug ) );
                                            }
                                            if ( ! is_wp_error( $used_trailer_term ) && ! empty( $used_trailer_term ) ) {
                                                $category_term_ids[] = (int) ( is_array( $used_trailer_term ) ? $used_trailer_term['term_id'] : $used_trailer_term->term_id );
                                            }
                                        }

                                        $category_term_ids = array_values( array_unique( array_filter( $category_term_ids ) ) );
                                        wp_set_object_terms( $product_id, $category_term_ids, 'product_cat', false );
                                        wp_set_object_terms( $product_id, 'simple', 'product_type' );

                                        $image_gallery_data = $api_values['Images'];
                                        $default_image_id   = (int) get_option( 'lic_featured_default_image' );

                                        $gallery_before   = array_filter( array_map( 'intval', explode( ',', get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
                                        $thumbnail_before = (int) get_post_meta( $product_id, '_thumbnail_id', true );
                                        $ids_before       = array_values( array_unique( array_filter( array_merge( $gallery_before, $thumbnail_before ? [ $thumbnail_before ] : [] ) ) ) );

                                        // Every image the API currently reports for this product, by the same
                                        // basename used as _lic_source_url — lets the orphan cleanup below tell a
                                        // genuine removal (dealer deleted the photo in Lightspeed) apart from a
                                        // transient download failure this cycle.
                                        $current_api_uuids = array_map( function ( $img ) {
                                            return basename( parse_url( $img['ImageUrl'] ?? '', PHP_URL_PATH ) );
                                        }, $image_gallery_data );

                                        if ( empty( $image_gallery_data ) ) {
                                            // No API images — clear gallery and apply site default if configured.
                                            delete_post_meta( $product_id, '_product_image_gallery' );
                                            if ( $default_image_id ) {
                                                set_post_thumbnail( $product_id, $default_image_id );
                                                update_post_meta( $product_id, '_lic_uses_default_image', '1' );
                                            }
                                        } else {
                                            delete_post_meta( $product_id, '_lic_uses_default_image' );
                                            delete_post_meta( $product_id, 'product_default_image' );

                                            // Identify the primary image; if none is flagged, promote the first.
                                            $primary_url = null;
                                            $other_urls  = [];
                                            foreach ( $image_gallery_data as $img ) {
                                                if ( $img['PrimaryImage'] === true && $primary_url === null ) {
                                                    $primary_url = $img['ImageUrl'];
                                                } else {
                                                    $other_urls[] = $img['ImageUrl'];
                                                }
                                            }
                                            if ( $primary_url === null && ! empty( $other_urls ) ) {
                                                $primary_url = array_shift( $other_urls );
                                            }

                                            // Ensure primary exists and set as the product thumbnail.
                                            if ( $primary_url ) {
                                                $primary_id = LIC_Image_Handler::get_or_create_attachment( $primary_url );
                                                if ( $primary_id && ! is_wp_error( $primary_id ) ) {
                                                    if ( ! get_post_meta( $primary_id, '_lic_source_url', true ) ) {
                                                        update_post_meta( $primary_id, '_lic_source_url', basename( parse_url( $primary_url, PHP_URL_PATH ) ) );
                                                    }
                                                    set_post_thumbnail( $product_id, $primary_id );
                                                    $images_added++;
                                                } else {
                                                    $loc_errors++;
                                                    self::log_cron_error(
                                                        $cron_name,
                                                        "Featured image failed for SKU {$api_sku} in location {$location_name}: " . ( is_wp_error( $primary_id ) ? $primary_id->get_error_message() : 'unknown error' ),
                                                        $sync_start
                                                    );
                                                }
                                            }

                                            // Ensure all other images exist and set the gallery explicitly.
                                            $new_gallery_ids = [];
                                            foreach ( $other_urls as $url ) {
                                                $att_id = LIC_Image_Handler::get_or_create_attachment( $url );
                                                if ( $att_id && ! is_wp_error( $att_id ) ) {
                                                    if ( ! get_post_meta( $att_id, '_lic_source_url', true ) ) {
                                                        update_post_meta( $att_id, '_lic_source_url', basename( parse_url( $url, PHP_URL_PATH ) ) );
                                                    }
                                                    $new_gallery_ids[] = $att_id;
                                                    $images_added++;
                                                } else {
                                                    $loc_errors++;
                                                    self::log_cron_error(
                                                        $cron_name,
                                                        "Gallery image failed for SKU {$api_sku} in location {$location_name}: " . ( is_wp_error( $att_id ) ? $att_id->get_error_message() : 'unknown error' ),
                                                        $sync_start
                                                    );
                                                }
                                            }
                                            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $new_gallery_ids ) );
                                        }

                                        // Delete attachments no longer referenced after sync — but only when the API
                                        // has genuinely stopped listing that image. An attachment merely missing from
                                        // $ids_after because THIS cycle's download attempt failed (get_or_create_attachment
                                        // returned a WP_Error, e.g. a truncated transfer) must NOT be deleted just for
                                        // that — its source image is still current per the API, so deleting it would
                                        // turn a transient failure into a permanent one (a working image silently
                                        // disappearing into a 404, rather than just staying put until a later sync
                                        // succeeds).
                                        $gallery_after   = array_filter( array_map( 'intval', explode( ',', get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
                                        $thumbnail_after = (int) get_post_meta( $product_id, '_thumbnail_id', true );
                                        $ids_after       = array_values( array_unique( array_filter( array_merge( $gallery_after, $thumbnail_after ? [ $thumbnail_after ] : [] ) ) ) );
                                        foreach ( array_diff( $ids_before, $ids_after ) as $orphaned_id ) {
                                            if ( $orphaned_id === $default_image_id ) continue;
                                            $source_uuid = get_post_meta( $orphaned_id, '_lic_source_url', true );
                                            if ( $source_uuid && in_array( $source_uuid, $current_api_uuids, true ) ) {
                                                continue;
                                            }
                                            // This image dropped out of THIS product's gallery, but it may still be
                                            // in use elsewhere — another product reusing the same photo, or the
                                            // image placed directly on a page/post via Elementor or in raw content.
                                            // Automatic deletion runs unattended every sync, so it must not delete
                                            // anything still referenced outside the relationship being removed here.
                                            if ( LIC_Image_Handler::is_attachment_in_use_elsewhere( $orphaned_id, $product_id ) ) {
                                                continue;
                                            }
                                            wp_delete_attachment( $orphaned_id, true );
                                        }

                                        if ( ! empty( $WC_fields['api_fields']['_sale_price'] ) ) {
                                            $sale_price = $api_values[ $WC_fields['api_fields']['_sale_price'] ];
                                            if ( is_numeric( $sale_price ) ) {
                                                update_post_meta( $product_id, '_sale_price', $sale_price );
                                            }
                                        }

                                        if ( ! empty( $WC_fields['api_fields']['_regular_price'] ) ) {
                                            $regular_price = $api_values[ $WC_fields['api_fields']['_regular_price'] ];
                                            if ( is_numeric( $regular_price ) ) {
                                                update_post_meta( $product_id, '_regular_price', $regular_price );
                                            }
                                        }

                                        if ( ! empty( $WC_fields['api_fields']['_price'] ) ) {
                                            $regular_price = $api_values[ $WC_fields['api_fields']['_price'] ];
                                            if ( is_numeric( $regular_price ) ) {
                                                update_post_meta( $product_id, '_price', $regular_price );
                                            }
                                        }

                                        $make_attr = '';
                                        if ( ! empty( $WC_fields['api_fields']['make'] ) ) {
                                            $make_attr = trim( $api_values[ $WC_fields['api_fields']['make'] ] ?? '' );
                                        }
                                        if ( ! empty( $make_attr ) ) {
                                            lic_set_product_attribute( $product_id, 'Make', 'make', $make_attr );
                                        }

                                        $length_attr = trim( $api_values['Length'] );
                                        if ( ! empty( $length_attr ) ) {
                                            lic_set_product_attribute( $product_id, 'Length', 'length', $length_attr );
                                        }

                                        $location_attr = $location_name;
                                        if ( ! empty( $location_attr ) ) {
                                            lic_set_product_attribute( $product_id, 'Location', 'location', $location_attr );
                                        }

                                        $condition_attr = '';
                                        if ( $api_values['NewUsed'] == 'N' ) {
                                            $condition_attr = 'New';
                                        } elseif ( $api_values['NewUsed'] == 'U' ) {
                                            $condition_attr = 'Used';
                                        }
                                        if ( ! empty( $condition_attr ) ) {
                                            lic_set_product_attribute( $product_id, 'Condition', 'condition', $condition_attr );
                                        }

                                        if ( ! empty( $WC_fields['api_fields'] ) && is_array( $WC_fields['api_fields'] ) ) {
                                            static $attribute_labels = null;
                                            if ( $attribute_labels === null ) {
                                                $attribute_labels = array();
                                                if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
                                                    foreach ( wc_get_attribute_taxonomies() as $attribute_taxonomy ) {
                                                        $attribute_labels[ $attribute_taxonomy->attribute_name ] = $attribute_taxonomy->attribute_label;
                                                    }
                                                }
                                            }

                                            foreach ( $WC_fields['api_fields'] as $meta_key => $api_field ) {
                                                if ( strpos( $meta_key, 'attr_' ) !== 0 ) continue;
                                                $attr_slug = substr( $meta_key, 5 );
                                                if ( empty( $attr_slug ) ) continue;
                                                if ( in_array( $attr_slug, array( 'length', 'location', 'condition', 'make' ), true ) ) continue;
                                                if ( empty( $api_field ) ) continue;
                                                $attr_value_raw = $api_values[ $api_field ] ?? '';
                                                $attr_values    = is_array( $attr_value_raw )
                                                    ? $attr_value_raw
                                                    : preg_split( '/[|,;]+/', (string) $attr_value_raw );
                                                $attr_values = array_filter( array_map( 'trim', $attr_values ) );
                                                if ( empty( $attr_values ) ) continue;
                                                $attr_label = $attribute_labels[ $attr_slug ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $attr_slug ) );
                                                lic_set_product_attribute( $product_id, $attr_label, $attr_slug, $attr_values );
                                            }
                                        }
                                    }

                                    if ( $product_id ) {
                                        $all_product_id[] = $product_id;
                                        update_post_meta( $product_id, '_lic_last_synced', current_time( 'mysql' ) );
                                        if ( isset( $existing_skus[ $product_id ] ) ) {
                                            unset( $existing_skus[ $product_id ] );
                                        }
                                    }
                                    $updated_count++;

                                } catch ( Exception $e ) {
                                    $loc_errors++;
                                    self::log_cron_error( $cron_name, "Error processing product at index $index in location: $location_name. Error: " . $e->getMessage(), $sync_start );
                                }
                            }

                            $increment  = $updated_count > 0 ? $updated_count : $top;
                            $next_skip  = $skip + $increment;

                            $location_complete = $next_skip >= $total_cached_items;
                            if ( $location_complete ) {
                                $has_more_data = false;
                                $next_skip     = $total_cached_items;
                                update_option( "cron_import_status_{$location_name}", 'completed' );
                            } else {
                                $has_more_data = true;
                            }

                            update_option( "cron_api_skip_value_{$location_name}", $next_skip );

                            // Update the sync log row with this batch's counters.
                            $log_id = (int) get_option( "lic_sync_log_current_{$location_name}" );
                            if ( $log_id ) {
                                $wpdb->query( $wpdb->prepare(
                                    "UPDATE {$wpdb->prefix}lic_sync_log
                                     SET items_processed = items_processed + %d,
                                         items_created   = items_created   + %d,
                                         items_updated   = items_updated   + %d,
                                         items_trashed   = items_trashed   + %d,
                                         items_ignored   = items_ignored   + %d,
                                         error_count     = error_count     + %d
                                     WHERE id = %d",
                                    $updated_count, $loc_created, $loc_updated, $loc_trashed, $loc_ignored, $loc_errors, $log_id
                                ) );

                                if ( $location_complete ) {
                                    $items_missed = self::calculate_items_missed( $location_name, $log_id );
                                    $wpdb->update(
                                        $wpdb->prefix . 'lic_sync_log',
                                        [ 'finished_at' => current_time( 'mysql' ), 'items_missed' => $items_missed ],
                                        [ 'id' => $log_id ],
                                        [ '%s', '%d' ],
                                        [ '%d' ]
                                    );
                                    delete_option( "lic_sync_log_current_{$location_name}" );
                                    $location_key = sanitize_key( $location_name );
                                    if ( $items_missed > 0 ) {
                                        update_option( "lic_sync_missed_{$location_key}", $items_missed );
                                    } else {
                                        delete_option( "lic_sync_missed_{$location_key}" );
                                    }
                                }
                            }
                        }

                        update_option( "API_location_{$location_name}", $location_api_status ? 'true' : 'false' );
                        $all_successes[] = 'Products for location ' . $location_name . ' created/updated successfully!';
                        self::log_cron_error( $cron_name, 'Products for location ' . $location_name . ' created/updated successfully!', $sync_start );
                        update_option( "lic_last_import_message_{$location_name}", 'Products for location ' . $location_name . ' created/updated successfully!' );
                    }
                }
            }

            self::get_out_of_stock_products( $items_removed );
            self::get_total_products( $current_inventory );

        } catch ( Exception $e ) {
            self::log_cron_error( $cron_name, "Unexpected error occurred: " . $e->getMessage(), $sync_start );
        }

        $sync_end = current_time( 'mysql' );
        self::log_cron_error( $cron_name, 'Product Import completed', $sync_start, $sync_end );

        self::clean_all_galleries( $all_product_id );

        return $has_more_data;
    }

    // Removes broken attachment IDs from all product galleries.
    public static function clean_all_galleries( $all_product_id ) {
        foreach ( $all_product_id as $product_id ) {
            self::clean_gallery( $product_id );
        }
    }

    // Removes broken attachment IDs from a single product's gallery.
    public static function clean_gallery( $product_id ) {
        $gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
        if ( ! empty( $gallery_ids ) ) {
            $gallery_ids = explode( ',', $gallery_ids );
            $valid_ids   = array_filter( $gallery_ids, function ( $image_id ) {
                return get_post( $image_id ) !== null;
            } );
            if ( count( $valid_ids ) !== count( $gallery_ids ) ) {
                if ( ! empty( $valid_ids ) ) {
                    update_post_meta( $product_id, '_product_image_gallery', implode( ',', $valid_ids ) );
                } else {
                    delete_post_meta( $product_id, '_product_image_gallery' );
                }
            }
        }
    }

    // Counts out-of-stock products and increments the $items_removed counter.
    public static function get_out_of_stock_products( &$items_removed ) {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array( 'key' => '_stock_status', 'value' => 'outofstock', 'compare' => '=' ),
            ),
        );
        $out_of_stock_products = get_posts( $args );
        $items_removed        += count( $out_of_stock_products );
        return $out_of_stock_products;
    }

    // Trashes WooCommerce products whose SKU matches a stock number confirmed sold via the
    // Lightspeed SoldUnit endpoint — regardless of what the Unit feed still shows, since a sold
    // unit can otherwise linger in Unit for months. Shared by the hourly SoldUnit delta check and
    // the monthly reconciliation sweep in LIC_Cron_Manager.
    //
    // Trashes immediately rather than just marking out-of-stock and waiting for the daily
    // delete_sold_out_products_event cron to catch up: an out-of-stock-but-still-published
    // product is hidden from catalog listings/search but its URL stays directly reachable (and
    // crawlable) for up to 24 hours until that cron runs. Since SoldUnit confirmation is a
    // reliable signal (unlike the weaker "just disappeared from Unit" signal
    // check_products_in_api() acts on, which still goes through the softer out-of-stock ->
    // daily-cron-trash path since it's not independently confirmed), there's no reason to leave
    // that window open here.
    //
    // $sold_items is a list of ['stock_number' => ..., 'vin' => ..., 'unit_status' => ...] (VIN
    // and unit_status may be '' if the feed didn't return them). Every stock number is
    // permanently recorded in lic_confirmed_sold, independent of whether a matching WC product
    // exists — this is what stops run_import()/check_products_in_api() from resurrecting/
    // re-instocking it on a later sync, since the sold unit can keep sitting in the Unit feed
    // indefinitely and the WC post itself isn't durable (trash gets auto-emptied by WordPress
    // after 30 days, taking its _sku postmeta with it). Storing the VIN alongside the stock
    // number is what lets is_sku_confirmed_sold() tell "still the same sold vehicle" apart from
    // "stock number got reused for a different vehicle" — stock numbers are only the last 5
    // digits of the VIN (10,000 possible values), so reuse across vehicles is expected, not an
    // edge case. unit_status is stored purely for admin visibility into why a stock number was
    // flagged (SoldUnit uses several non-'S' codes — T/E/P/C/B/X/M — for "no longer available").
    public static function remove_confirmed_sold_skus( array $sold_items, $cron_name, $location_name = '' ) {
        $sku_data = [];
        foreach ( $sold_items as $item ) {
            $sn = (string) ( is_array( $item ) ? ( $item['stock_number'] ?? '' ) : $item );
            if ( $sn === '' ) continue;
            $sku_data[ $sn ] = [
                'vin'         => is_array( $item ) ? (string) ( $item['vin'] ?? '' ) : '',
                'unit_status' => is_array( $item ) ? (string) ( $item['unit_status'] ?? '' ) : '',
            ];
        }
        if ( empty( $sku_data ) ) {
            return 0;
        }

        self::record_confirmed_sold( $sku_data, $location_name, $cron_name );

        global $wpdb;
        $stock_numbers = array_keys( $sku_data );
        $placeholders  = implode( ',', array_fill( 0, count( $stock_numbers ), '%s' ) );
        $product_ids   = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($placeholders)
               AND p.post_type = 'product' AND p.post_status = 'publish'",
            $stock_numbers
        ) );

        if ( empty( $product_ids ) ) {
            return 0;
        }

        $removed      = 0;
        $removed_skus = [];

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            $removed_skus[] = $product->get_sku();
            self::lic_remove_product( $product_id );
            $removed++;
        }

        if ( $removed > 0 ) {
            self::log_cron_error(
                $cron_name,
                "Trashed {$removed} product(s) confirmed sold via SoldUnit (SKUs: " . implode( ', ', array_slice( $removed_skus, 0, 20 ) ) . ( count( $removed_skus ) > 20 ? '…' : '' ) . ').',
                current_time( 'mysql' ),
                current_time( 'mysql' )
            );
        }

        return $removed;
    }

    // Upserts stock number => {vin, unit_status} into the lic_confirmed_sold table (survives
    // independently of any WC product post). One row per stock number; a repeat confirmation
    // just refreshes it with whatever SoldUnit most recently reported for that number.
    private static function record_confirmed_sold( array $sku_data, $location_name, $source ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lic_confirmed_sold';
        $now   = current_time( 'mysql' );
        foreach ( $sku_data as $stock_number => $data ) {
            $wpdb->replace(
                $table,
                [
                    'stock_number'  => (string) $stock_number,
                    'vin'           => (string) ( $data['vin'] ?? '' ),
                    'unit_status'   => (string) ( $data['unit_status'] ?? '' ),
                    'location_name' => (string) $location_name,
                    'source'        => (string) $source,
                    'confirmed_at'  => $now,
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    // Returns stock_number => vin for every row in lic_confirmed_sold, for O(1) lookups.
    // Cached for the lifetime of the request/cron run.
    public static function get_confirmed_sold_set() {
        static $set = null;
        if ( $set !== null ) {
            return $set;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lic_confirmed_sold';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return $set = [];
        }
        $rows = $wpdb->get_results( "SELECT stock_number, vin FROM {$table}", ARRAY_A );
        $set  = [];
        foreach ( $rows as $row ) {
            $set[ $row['stock_number'] ] = $row['vin'];
        }
        return $set;
    }

    // Whether a stock number/VIN pair is blocked from (re)publishing because it's confirmed sold
    // — the authoritative override that Unit-feed-driven sync logic must defer to.
    //
    // Stock numbers are only the last 5 digits of the VIN (10,000 possible values), so the same
    // number gets reused across unrelated vehicles over time — and a vehicle can also legitimately
    // come back on trade with the *same* VIN and stock number it sold under before. So a bare
    // stock-number match isn't enough on its own:
    //   - VIN differs from what was recorded sold  -> a different vehicle reusing the number.
    //     Not blocked; queued for review so an admin can see the reuse happened.
    //   - VIN matches what was recorded sold       -> possibly the same vehicle back on trade.
    //     Still blocked; queued for review so an admin can decide whether to un-flag it.
    //   - No VIN available to compare (legacy row, or feed item missing VIN) -> falls back to
    //     blocking on stock number alone, same as before VIN tracking existed.
    public static function is_sku_confirmed_sold( $sku, $vin = '' ) {
        $sku = (string) $sku;
        $set = self::get_confirmed_sold_set();
        if ( ! array_key_exists( $sku, $set ) ) {
            return false;
        }

        $stored_vin = (string) $set[ $sku ];
        $vin        = (string) $vin;

        if ( $stored_vin !== '' && $vin !== '' ) {
            if ( $stored_vin !== $vin ) {
                self::queue_sold_review( $sku, $stored_vin, $vin, 'vin_mismatch' );
                return false;
            }
            self::queue_sold_review( $sku, $stored_vin, $vin, 'vin_reappeared' );
        }

        return true;
    }

    // Queues a review-worthy event (stock number reused for a different VIN, or a previously-sold
    // VIN reappearing in the Unit feed) for admin attention. Deduplicated per (stock_number,
    // event_type) so a recurring condition doesn't spam a new row every sync cycle — surfaced via
    // lic_collect_sync_alerts() in the main plugin file and the "Sold Unit Alerts" log tab.
    //
    // is_sku_confirmed_sold() calls this on every sync cycle for as long as the underlying
    // condition stays true, which for a reused stock number can be indefinitely. A plain
    // INSERT IGNORE only suppressed re-inserts while the row still existed — "Mark Reviewed"
    // deleted the row, so the very next sync (there can be dozens a day) freely re-inserted an
    // identical one. Confirmed live: every one of 25 alerts cleared on 2026-09-11 had reappeared
    // by 2026-09-13, unchanged. ON DUPLICATE KEY UPDATE instead: if the VIN pair is unchanged
    // from what's stored, dismissed_at is left alone, so a reviewed alert stays reviewed even
    // though the condition keeps re-evaluating true. Only a genuine change in the VIN pair
    // (real new information) clears dismissed_at so it resurfaces.
    private static function queue_sold_review( $stock_number, $old_vin, $new_vin, $event_type ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lic_sold_review_queue';
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (stock_number, old_vin, new_vin, event_type, detected_at, dismissed_at)
             VALUES (%s, %s, %s, %s, %s, NULL)
             ON DUPLICATE KEY UPDATE
               dismissed_at = IF(old_vin = VALUES(old_vin) AND new_vin = VALUES(new_vin), dismissed_at, NULL),
               detected_at  = IF(old_vin = VALUES(old_vin) AND new_vin = VALUES(new_vin), detected_at, VALUES(detected_at)),
               old_vin      = VALUES(old_vin),
               new_vin      = VALUES(new_vin)",
            (string) $stock_number, (string) $old_vin, (string) $new_vin, (string) $event_type, current_time( 'mysql' )
        ) );
    }

    // Counts all published products and increments the $current_inventory counter.
    public static function get_total_products( &$current_inventory ) {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        );
        $total_products     = get_posts( $args );
        $current_inventory += count( $total_products );
        return $total_products;
    }

    // Deletes product categories whose name is purely numeric and have no products.
    public static function delete_empty_numeric_categories() {
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
        if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
            foreach ( $categories as $category ) {
                if ( is_numeric( $category->name ) && $category->count == 0 ) {
                    wp_delete_term( $category->term_id, 'product_cat' );
                }
            }
        }
    }

    // Schedules the hourly product-existence check cron if not already scheduled.
    public static function schedule_product_check() {
        if ( ! wp_next_scheduled( 'check_product_exist_cron' ) ) {
            wp_schedule_event( time(), 'hourly', 'check_product_exist_cron' );
        }
    }

    // Compares WooCommerce SKUs against the live API and marks missing ones out-of-stock.
    public static function check_products_in_api() {
        $cron_name       = 'check_product_exist_in_or_not';
        $cron_start_time = current_time( 'mysql' );
        self::log_cron_error( $cron_name, 'Cron started', $cron_start_time );

        $sold_out_image_id = get_option( 'lic_sold_products_image' );

        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array( array( 'key' => '_sku', 'compare' => 'EXISTS' ) ),
        );

        $existing_products = [];
        foreach ( get_posts( $args ) as $product ) {
            $existing_products[ get_post_meta( $product->ID, '_sku', true ) ] = $product->ID;
        }

        $total_wc_products = count( $existing_products );
        $settings          = get_option( 'lic_settings' );
        $alllocation       = $settings['locations'] ?? '';
        $api_skus          = [];
        $api_sku_vin_map   = [];
        $abort_comparison  = false;

        foreach ( $alllocation as $location_data ) {
            $location_name = $location_data['name'];
            if ( ! empty( $location_name ) ) {
                $data = get_option( 'mapped_fields_' . $location_name );
                if ( isset( $location_name, $data ) ) {
                    $creds = lic_get_credentials( $location_name );
                    if ( ! empty( $location_data['use_sandbox'] ) ) {
                        $api_key    = $creds['api_username_sandbox'] ?? '';
                        $api_secret = $creds['api_password_sandbox'] ?? '';
                    } else {
                        $api_key    = $creds['api_username_live'] ?? '';
                        $api_secret = $creds['api_password_live'] ?? '';
                    }
                    if ( ! wp_http_validate_url( $location_data['api_url'] ?? '' ) ) {
                        self::log_cron_error( $cron_name, 'CRITICAL: Invalid or disallowed API URL for location ' . $location_name . ' — aborting comparison to prevent stock corruption.', $cron_start_time );
                        $abort_comparison = true;
                        continue;
                    }
                    // Paginated — a single unpaginated request here silently returned only
                    // Lightspeed's first page for a larger location (Thomasville, 500+ units),
                    // making every SKU past that page look "not in Lightspeed anymore" to the
                    // comparison below. That marked real, still-available inventory outofstock,
                    // and the next day's delete_sold_out_products cron trashed it — actively
                    // deleting live listings. Every other API call in this codebase already
                    // paginates with $top/$skip (see LIC_Cron_Manager::cache_all_locations());
                    // this is the one call that didn't.
                    $page_size     = 200;
                    $max_pages     = 100;
                    $skip          = 0;
                    $page          = 0;
                    $location_skus = [];
                    $page_failed   = false;

                    do {
                        $paged_url = add_query_arg( [
                            '$select' => 'StockNumber,VIN',
                            '$top'    => $page_size,
                            '$skip'   => $skip,
                        ], $location_data['api_url'] ?? '' );

                        $response = wp_remote_get( $paged_url, array(
                            'timeout' => 60,
                            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ) ),
                        ) );

                        if ( is_wp_error( $response ) ) {
                            self::log_cron_error( $cron_name, 'CRITICAL: Transport error for location ' . $location_name . ' (skip=' . $skip . '): ' . $response->get_error_message() . ' — aborting comparison to prevent stock corruption.', $cron_start_time );
                            $abort_comparison = true;
                            $page_failed      = true;
                            break;
                        }

                        $http_code = wp_remote_retrieve_response_code( $response );
                        if ( $http_code !== 200 ) {
                            self::log_cron_error( $cron_name, 'CRITICAL: HTTP ' . $http_code . ' from API for location ' . $location_name . ' (skip=' . $skip . ') — aborting comparison to prevent stock corruption.', $cron_start_time );
                            $abort_comparison = true;
                            $page_failed      = true;
                            break;
                        }

                        $page_items = json_decode( wp_remote_retrieve_body( $response ), true );
                        if ( ! is_array( $page_items ) ) {
                            self::log_cron_error( $cron_name, 'CRITICAL: Unexpected response shape for location ' . $location_name . ' (skip=' . $skip . ') — aborting comparison to prevent stock corruption.', $cron_start_time );
                            $abort_comparison = true;
                            $page_failed      = true;
                            break;
                        }

                        foreach ( $page_items as $api_values ) {
                            $sn = (string) ( $api_values['StockNumber'] ?? '' );
                            if ( $sn === '' ) continue;
                            $location_skus[]        = $sn;
                            $api_sku_vin_map[ $sn ] = (string) ( $api_values['VIN'] ?? '' );
                        }

                        $skip += $page_size;
                        $page++;
                    } while ( count( $page_items ) === $page_size && $page < $max_pages );

                    if ( $page_failed ) {
                        continue;
                    }

                    $fresh_count      = count( $location_skus );
                    $transient_key    = 'lic_sku_count_' . sanitize_key( $location_name );
                    $last_known_count = get_transient( $transient_key );

                    if ( $last_known_count !== false ) {
                        $threshold = (int) ( $last_known_count * 0.20 );
                        if ( $fresh_count === 0 || $fresh_count < $threshold ) {
                            self::log_cron_error( $cron_name, 'CRITICAL: Location ' . $location_name . ' returned ' . $fresh_count . ' SKUs (last known: ' . $last_known_count . ', threshold: ' . $threshold . '). Aborting comparison to prevent stock corruption.', $cron_start_time );
                            $abort_comparison = true;
                            continue;
                        }
                    }

                    set_transient( $transient_key, $fresh_count, WEEK_IN_SECONDS );
                    foreach ( $location_skus as $sku ) {
                        $api_skus[] = $sku;
                    }
                }
            }
        }

        $api_skus = array_values( array_unique( $api_skus ) );
        self::log_cron_error( $cron_name, 'API SKUs: ' . count( $api_skus ) . ' | WC products with SKU: ' . $total_wc_products, $cron_start_time );

        if ( $abort_comparison ) {
            self::log_cron_error( $cron_name, 'CRITICAL: Stock comparison aborted — one or more locations returned suspicious or failed data. No stock statuses were modified.', $cron_start_time );
            return;
        }

        $marked_instock    = 0;
        $marked_outofstock = 0;
        $sample_outofstock = [];

        foreach ( $existing_products as $sku => $product_id ) {
            // $existing_products was built as [$sku => $product_id] — PHP silently casts any
            // purely-numeric, no-leading-zero string key ("65661") to an actual int array key,
            // so $sku comes back out here as an int for every such SKU. $api_skus holds plain
            // strings, and in_array(..., true) below is a strict comparison — 65661 !== "65661",
            // so every numeric SKU without a leading zero could never match regardless of
            // whether it was genuinely present, permanently stuck outofstock. Confirmed live:
            // every sample outofstock SKU from this check was purely numeric with no leading
            // zero; none with letters or a leading zero were affected. Re-cast to string here
            // rather than relying on PHP's key coercion happening to work out.
            $sku     = (string) $sku;
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            // A SoldUnit-confirmed SKU stays out-of-stock even if Unit still lists it — otherwise
            // this hourly check would flip it back to instock before the daily cleanup cron
            // (which only runs once a day) gets a chance to trash it. VIN comparison lets a
            // different vehicle that reused the same stock number through — see is_sku_confirmed_sold().
            if ( in_array( $sku, $api_skus, true ) && ! self::is_sku_confirmed_sold( $sku, $api_sku_vin_map[ $sku ] ?? '' ) ) {
                $product->set_stock_status( 'instock' );
                $marked_instock++;
            } else {
                $product->set_stock_status( 'outofstock' );
                $marked_outofstock++;
                if ( count( $sample_outofstock ) < 10 && ! empty( $sku ) ) {
                    $sample_outofstock[] = $sku;
                }
                if ( $sold_out_image_id ) {
                    set_post_thumbnail( $product_id, $sold_out_image_id );
                }
            }
            $product->save();
        }

        $cron_end_time = current_time( 'mysql' );
        $message       = 'Instock: ' . $marked_instock . ' | Outofstock: ' . $marked_outofstock;
        if ( ! empty( $sample_outofstock ) ) {
            $message .= ' | Sample outofstock SKUs: ' . implode( ', ', $sample_outofstock );
        }
        self::log_cron_error( $cron_name, $message, $cron_start_time, $cron_end_time );
    }

    // Logs the product to the deleted-products table, deletes plugin-managed images, and trashes the post.
    public static function lic_remove_product( $product_id ) {
        global $wpdb;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $table_name = $wpdb->prefix . 'lic_remove_products_logs';
        $categories = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $wpdb->insert(
            $table_name,
            [
                'product_id'  => $product_id,
                'sku'         => $product->get_sku(),
                'name'        => $product->get_name(),
                'status'      => $product->get_status(),
                'category'    => implode( ', ', ! is_wp_error( $categories ) ? $categories : [] ),
                'update_date' => get_post_modified_time( 'Y-m-d H:i:s', false, $product_id ),
                'deleted_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $gallery_ids  = array_filter( array_map( 'intval', explode( ',', get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
        $thumbnail_id = (int) get_post_meta( $product_id, '_thumbnail_id', true );
        $all_ids      = array_values( array_unique( array_filter( array_merge( $gallery_ids, $thumbnail_id ? [ $thumbnail_id ] : [] ) ) ) );
        foreach ( $all_ids as $attachment_id ) {
            if ( get_post_meta( $attachment_id, '_lic_source_url', true ) ) {
                wp_delete_attachment( $attachment_id, true );
            }
        }

        delete_post_meta( $product_id, '_product_image_gallery' );
        delete_post_meta( $product_id, '_thumbnail_id' );
        wp_trash_post( $product_id );
    }

    // Un-flags a stock number that was wrongly confirmed sold and, if a matching WooCommerce
    // product exists and is currently trashed, restores it to publish. Used when a
    // lic_confirmed_sold entry turns out to be wrong (e.g. a unit transferred between locations
    // rather than actually sold) and needs the live site fixed, not just the flag cleared.
    //
    // Note: lic_remove_product() permanently deletes plugin-managed gallery/thumbnail images
    // when it trashes a product (force-delete, not just trash), so restoring the post does not
    // restore its images — those repopulate on the next normal sync for that SKU (or via the
    // per-product "Sync Images" action), not automatically here.
    //
    // Returns ['stock_number' => ..., 'had_confirmed_sold_row' => bool, 'product_id' => int|null,
    // 'restored' => bool].
    public static function unflag_and_restore_sku( $stock_number ) {
        $stock_number = (string) $stock_number;
        global $wpdb;

        $confirmed_table = $wpdb->prefix . 'lic_confirmed_sold';
        $had_row = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$confirmed_table} WHERE stock_number = %s", $stock_number
        ) );
        $wpdb->delete( $confirmed_table, [ 'stock_number' => $stock_number ], [ '%s' ] );

        $review_table = $wpdb->prefix . 'lic_sold_review_queue';
        $wpdb->delete( $review_table, [ 'stock_number' => $stock_number ], [ '%s' ] );

        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND p.post_type = 'product'
             LIMIT 1",
            $stock_number
        ) );

        $restored = false;
        if ( $product_id ) {
            $product_id = (int) $product_id;
            if ( get_post_status( $product_id ) === 'trash' ) {
                wp_untrash_post( $product_id );
                $restored = true;
            }
        }

        return [
            'stock_number'           => $stock_number,
            'had_confirmed_sold_row' => $had_row,
            'product_id'             => $product_id ? (int) $product_id : null,
            'restored'               => $restored,
        ];
    }

    // Deletes all out-of-stock products via lic_remove_product. SoldUnit-confirmed sales are
    // trashed immediately by remove_confirmed_sold_skus() and never reach this cron in an
    // out-of-stock state; this catches the weaker, unconfirmed signal from
    // check_products_in_api() (a SKU simply missing from the current Unit feed), which still
    // gets a grace period before removal since it isn't independently confirmed sold.
    // Trashes outofstock products in small batches rather than all at once. Previously
    // fetched every outofstock product (hundreds, once the sold-unit backlog built up)
    // and processed them in a single unbatched, un-isolated loop — confirmed live via 5
    // consecutive days of "Cron started" with no matching "Cron completed" in the log,
    // while the hourly stock check kept reporting a flat, unmoving outofstock count the
    // entire time. One bad product (any exception out of lic_remove_product() — image
    // deletion, a hook someone else's plugin attached to wp_trash_post, anything) killed
    // the whole run silently: everything processed before it that run did get trashed
    // (each call is independent and synchronous), but "Cron completed" never logged and
    // everything after it in that batch never got touched. A batch size this large was
    // also simply slow — plausible to near PHP's execution time limit on its own even
    // without a hard failure.
    //
    // A MySQL named lock prevents overlapping invocations from processing the same
    // backlog concurrently (this cron showed the same near-simultaneous double-start
    // pattern seen elsewhere in this plugin's WP-Cron setup). Each batch is wrapped
    // per-item in try/catch so one failure is logged and skipped rather than aborting
    // the rest of the batch, and if there's more backlog left after this batch, a
    // near-immediate follow-up run is scheduled rather than waiting for tomorrow.
    public static function delete_sold_out_products() {
        global $wpdb;
        $cron_name       = 'delete_sold_out_products';
        $cron_start_time = current_time( 'mysql' );
        $batch_size      = 25;

        $lock_name = 'lic_delete_sold_out_products';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ) ) {
            self::log_cron_error( $cron_name, 'Could not acquire lock within 5s — another invocation is already running; skipping.', $cron_start_time );
            return;
        }

        try {
            self::log_cron_error( $cron_name, 'Cron started', $cron_start_time );

            $args = array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'meta_key'       => '_stock_status',
                'meta_value'     => 'outofstock',
                'fields'         => 'ids',
                'posts_per_page' => $batch_size,
            );

            $products_to_delete = get_posts( $args );
            $deleted             = 0;
            $errors              = 0;

            foreach ( $products_to_delete as $product_id ) {
                try {
                    self::lic_remove_product( $product_id );
                    $deleted++;
                } catch ( \Throwable $e ) {
                    $errors++;
                    self::log_cron_error( $cron_name, "Error removing product {$product_id}: " . $e->getMessage(), $cron_start_time );
                }
            }

            // More than a batch's worth may still be outofstock — check without pulling
            // the full list again, and schedule a near-immediate follow-up rather than
            // waiting for tomorrow's run.
            $remaining = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'product' AND p.post_status = 'publish'
                   AND pm.meta_key = '_stock_status' AND pm.meta_value = 'outofstock'"
            );
            if ( $remaining > 0 ) {
                wp_schedule_single_event( time() + 60, 'delete_sold_out_products_event' );
            }

            $cron_end_time = current_time( 'mysql' );
            self::log_cron_error(
                $cron_name,
                "Cron completed — deleted {$deleted}, errors {$errors}, {$remaining} still outofstock" . ( $remaining > 0 ? ' (follow-up scheduled in 60s)' : '' ),
                $cron_start_time,
                $cron_end_time
            );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    // Applies the saved field mapping to a batch of products for a location.
    // $offset / $batch_size control pagination; pass 0 for both to process everything (cron use).
    // Returns ['updated'=>N,'skipped'=>N,'total'=>N,'processed'=>N,'has_more'=>bool,'next_offset'=>N]
    // or null if there is nothing to do (no mapping, no cache, or no products).
    // Has a counterpart in run_import() — see the note there before changing field-mapping behavior.
    public static function apply_field_mapping_to_location( $location_name, $offset = 0, $batch_size = 0 ) {
        $data = get_option( 'mapped_fields_' . $location_name );
        if ( ! is_array( $data ) ) {
            return null;
        }

        $acf_map = isset( $data['ACF']['api_fields'] ) && is_array( $data['ACF']['api_fields'] ) ? $data['ACF']['api_fields'] : [];
        $wc_map  = isset( $data['WC']['api_fields'] )  && is_array( $data['WC']['api_fields'] )  ? $data['WC']['api_fields']  : [];

        if ( empty( $acf_map ) && empty( $wc_map ) ) {
            return null;
        }

        $api_items = LIC_Cron_Manager::get_cached_data( $location_name );
        if ( empty( $api_items ) ) {
            return null;
        }

        $api_index = [];
        foreach ( $api_items as $item ) {
            $sn = (string) ( $item['StockNumber'] ?? '' );
            if ( $sn !== '' ) {
                $api_index[ $sn ] = $item;
            }
        }
        unset( $api_items );

        global $wpdb;
        $all_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'location' AND pm.meta_value = %s
               AND p.post_type = 'product'
               AND p.post_status IN ('publish','draft')",
            $location_name
        ) );

        if ( empty( $all_ids ) ) {
            return null;
        }

        $total       = count( $all_ids );
        $product_ids = $batch_size > 0 ? array_slice( $all_ids, $offset, $batch_size ) : $all_ids;
        $processed   = count( $product_ids );
        $next_offset = $offset + $processed;
        $has_more    = $batch_size > 0 && $next_offset < $total;

        $updated = 0;
        $skipped = 0;

        foreach ( $product_ids as $pid ) {
            $pid        = (int) $pid;
            $sku        = (string) get_post_meta( $pid, '_sku', true );
            $api_values = $api_index[ $sku ] ?? null;

            if ( ! is_array( $api_values ) ) {
                $skipped++;
                continue;
            }

            $post_fields = [];

            foreach ( $wc_map as $meta_key => $api_field ) {
                if ( empty( $api_field ) || ! array_key_exists( $api_field, $api_values ) ) continue;
                $value = sanitize_text_field( (string) $api_values[ $api_field ] );
                if ( in_array( $meta_key, [ 'post_title', 'post_content', 'post_excerpt' ], true ) ) {
                    $post_fields[ $meta_key ] = $value;
                } else {
                    update_post_meta( $pid, $meta_key, $value );
                }
            }

            foreach ( $acf_map as $field_name => $api_field ) {
                if ( empty( $field_name ) ) continue;
                if ( $field_name === 'Location' ) {
                    update_post_meta( $pid, 'location', $location_name );
                } elseif ( $field_name === 'New/Used' ) {
                    if ( empty( $api_field ) || ! array_key_exists( $api_field, $api_values ) ) continue;
                    $raw       = (string) $api_values[ $api_field ];
                    $condition = ( $raw === 'N' ) ? 'New' : ( ( $raw === 'U' ) ? 'Used' : sanitize_text_field( $raw ) );
                    update_post_meta( $pid, 'newused', $condition );
                    update_post_meta( $pid, '_product_condition_field', $condition );
                } else {
                    if ( empty( $api_field ) || ! array_key_exists( $api_field, $api_values ) ) continue;
                    update_post_meta( $pid, strtolower( $field_name ), sanitize_text_field( (string) $api_values[ $api_field ] ) );
                }
            }

            if ( ! empty( $post_fields ) ) {
                wp_update_post( array_merge( $post_fields, [ 'ID' => $pid ] ) );
            }

            $updated++;
        }

        return [
            'updated'     => $updated,
            'skipped'     => $skipped,
            'total'       => $total,
            'processed'   => $processed,
            'has_more'    => $has_more,
            'next_offset' => $next_offset,
        ];
    }

    // Runs apply_field_mapping_to_location only when the mapping has changed since the last backfill.
    private static function maybe_backfill_field_mapping( $location_name ) {
        $key             = sanitize_key( $location_name );
        $mapping_changed = (int) get_option( 'lic_mapping_changed_' . $key, 0 );
        $backfill_done   = (int) get_option( 'lic_backfill_done_'   . $key, 0 );

        if ( $mapping_changed === 0 || $backfill_done >= $mapping_changed ) {
            return;
        }

        $result = self::apply_field_mapping_to_location( $location_name );
        if ( $result !== null ) {
            update_option( 'lic_backfill_done_' . $key, time() );
            self::log_cron_error(
                'lic_field_mapping_backfill',
                sprintf( 'Auto-backfill for "%s": %d updated, %d skipped.', $location_name, $result['updated'], $result['skipped'] ),
                current_time( 'mysql' )
            );
        }
    }

    // Inserts a row into the cron error/operation log table.
    public static function log_cron_error( $cron_name, $error_message, $start_time = null, $end_time = null ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'lic_cron_error_logs',
            array(
                'cron_name'     => $cron_name,
                'error_message' => $error_message,
                'start_time'    => $start_time,
                'end_time'      => $end_time,
                'logged_at'     => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
    }

    // Returns the count of published products in the API cache for a location that were not updated during the sync run.
    private static function calculate_items_missed( $location_name, $log_id ) {
        global $wpdb;

        $started_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT started_at FROM {$wpdb->prefix}lic_sync_log WHERE id = %d",
            $log_id
        ) );
        if ( ! $started_at ) return 0;

        $data      = get_option( 'mapped_fields_' . $location_name, [] );
        $sku_field = $data['WC']['api_fields']['_sku'] ?? 'StockNumber';

        $cached_data = LIC_Cron_Manager::get_cached_data( $location_name );
        if ( empty( $cached_data ) ) return 0;

        $cached_skus = array_values( array_filter( array_unique( array_column( $cached_data, $sku_field ) ) ) );
        if ( empty( $cached_skus ) ) return 0;

        $placeholders = implode( ',', array_fill( 0, count( $cached_skus ), '%s' ) );
        $args         = array_merge( $cached_skus, [ $started_at ] );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_sku  ON pm_sku.post_id  = p.ID AND pm_sku.meta_key  = '_sku'
                 LEFT  JOIN {$wpdb->postmeta} pm_sync ON pm_sync.post_id = p.ID AND pm_sync.meta_key = '_lic_last_synced'
                 WHERE p.post_type   = 'product'
                   AND p.post_status = 'publish'
                   AND pm_sku.meta_value IN ({$placeholders})
                   AND (pm_sync.meta_value IS NULL OR pm_sync.meta_value < %s)",
                ...$args
            )
        );
    }

    // Deletes old rows from log tables to enforce retention limits.
    public static function cleanup_logs() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_sync_log             WHERE started_at < DATE_SUB(NOW(), INTERVAL 60 DAY)" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_remove_products_logs WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_cron_error_logs      WHERE logged_at  < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}lic_api_error_log        WHERE logged_at  < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
    }
}
