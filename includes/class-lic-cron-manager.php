<?php

class LIC_Cron_Manager {

    public static function init() {
        add_action( 'plugins_loaded', array( __CLASS__, 'schedule_daily_cache_cron' ) );
        add_action( 'lic_daily_api_cache_cron', array( __CLASS__, 'cache_all_locations' ) );
        add_action( 'plugins_loaded', array( __CLASS__, 'schedule_incremental_cache_cron' ) );
        add_action( 'lic_incremental_cache_cron', array( __CLASS__, 'incremental_update' ) );
        add_action( 'plugins_loaded', array( __CLASS__, 'schedule_sold_unit_check_cron' ) );
        add_action( 'lic_sold_unit_check_cron', array( __CLASS__, 'check_sold_units_incremental' ) );
        add_action( 'plugins_loaded', array( __CLASS__, 'schedule_sold_unit_reconciliation_cron' ) );
        add_action( 'lic_sold_unit_reconciliation_cron', array( __CLASS__, 'reconcile_sold_units' ) );
    }

    // Returns the next UTC timestamp for a given HH:MM time stored in UTC.
    public static function next_warm_timestamp( $time_utc ) {
        $today = strtotime( 'today ' . $time_utc . ' UTC' );
        return $today <= time() ? strtotime( 'tomorrow ' . $time_utc . ' UTC' ) : $today;
    }

    // Schedules the daily full-cache warm cron if not already scheduled.
    public static function schedule_daily_cache_cron() {
        if ( ! wp_next_scheduled( 'lic_daily_api_cache_cron' ) ) {
            $time_utc = get_option( 'lic_cache_warm_time', '06:00' );
            wp_schedule_event( self::next_warm_timestamp( $time_utc ), 'daily', 'lic_daily_api_cache_cron' );
        }
    }

    // Schedules the hourly incremental cache update cron if not already scheduled.
    public static function schedule_incremental_cache_cron() {
        if ( ! wp_next_scheduled( 'lic_incremental_cache_cron' ) ) {
            wp_schedule_event( time(), 'hourly', 'lic_incremental_cache_cron' );
        }
    }

    // Schedules the hourly SoldUnit delta check if not already scheduled.
    public static function schedule_sold_unit_check_cron() {
        if ( ! wp_next_scheduled( 'lic_sold_unit_check_cron' ) ) {
            wp_schedule_event( time(), 'hourly', 'lic_sold_unit_check_cron' );
        }
    }

    // Schedules the monthly SoldUnit reconciliation sweep if not already scheduled. Anchored to
    // the same admin-configured off-hours time as the daily full cache warm (lic_cache_warm_time)
    // rather than firing at whatever moment the cron first registers — the reconciliation sweep
    // is exactly the kind of "large request" the Lightspeed 3PA guide says to schedule during the
    // dealership's off-hours, since 3PA requests run in contention with the dealer's own system.
    // Uses the 'lic_monthly' interval registered in LIC_Importer::add_cron_schedules().
    public static function schedule_sold_unit_reconciliation_cron() {
        if ( ! wp_next_scheduled( 'lic_sold_unit_reconciliation_cron' ) ) {
            $time_utc = get_option( 'lic_cache_warm_time', '06:00' );
            wp_schedule_event( self::next_warm_timestamp( $time_utc ), 'lic_monthly', 'lic_sold_unit_reconciliation_cron' );
        }
    }

    // Fetches the full inventory from all locations and stores it in the cache table.
    public static function cache_all_locations() {
        if ( get_option( 'lic_sync_enabled', '1' ) === '0' ) {
            return;
        }
        global $wpdb;
        $settings    = get_option( 'lic_settings' );
        $alllocation = $settings['locations'] ?? [];
        $table_name  = $wpdb->prefix . 'lic_api_cache';

        $page_size  = 200;
        $max_pages  = 100;
        $any_cached = false;

        foreach ( $alllocation as $location_data ) {
            $location_name = $location_data['name'] ?? '';
            if ( empty( $location_name ) ) continue;

            $creds      = lic_get_credentials( $location_name );
            $api_key    = ! empty( $location_data['use_sandbox'] ) ? ( $creds['api_username_sandbox'] ?? '' ) : ( $creds['api_username_live'] ?? '' );
            $api_secret = ! empty( $location_data['use_sandbox'] ) ? ( $creds['api_password_sandbox'] ?? '' ) : ( $creds['api_password_live'] ?? '' );
            $api_url    = $location_data['api_url'] ?? '';

            if ( empty( $api_url ) || empty( $api_key ) ) continue;
            if ( ! wp_http_validate_url( $api_url ) ) {
                self::log_api_error( $location_name, 'full_warm', 0, 'Invalid or disallowed API URL — skipping location.' );
                continue;
            }

            $all_items = [];
            $skip      = 0;
            $page      = 0;
            $failed    = false;

            do {
                $paged_url = add_query_arg( [
                    '$top'  => $page_size,
                    '$skip' => $skip,
                ], $api_url );

                $response = wp_remote_get( $paged_url, [
                    'timeout' => 120,
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                    ],
                ] );

                if ( is_wp_error( $response ) ) {
                    self::log_api_error( $location_name, 'full_warm', 0, 'Transport error (skip=' . $skip . '): ' . $response->get_error_message() );
                    $failed = true;
                    break;
                }

                $http_code = wp_remote_retrieve_response_code( $response );
                if ( $http_code !== 200 ) {
                    self::log_api_error( $location_name, 'full_warm', $http_code, 'HTTP ' . $http_code . ' response (skip=' . $skip . ')' );
                    $failed = true;
                    break;
                }

                $page_items = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $page_items ) ) {
                    self::log_api_error( $location_name, 'full_warm', $http_code, 'Unexpected response shape (skip=' . $skip . '): json_decode returned non-array' );
                    $failed = true;
                    break;
                }

                $all_items = array_merge( $all_items, $page_items );
                $skip += $page_size;
                $page++;

            } while ( count( $page_items ) === $page_size && $page < $max_pages );

            // Diagnostic: a page coming back shorter than $page_size is treated as "this was
            // the last page" and isn't an error — but Thomasville's cached count has been
            // inconsistent run to run (527, then 24, then 147, then 173) while Winston-Salem
            // (small enough to fit in a single page) never wavers from 114, which points at
            // something going wrong specifically on later pages of a multi-page fetch. Log
            // every run's page count and final tally unconditionally so the next short result
            // has concrete evidence — which skip value came back short, and by how much —
            // instead of having to guess again.
            if ( ! $failed ) {
                self::log_api_error(
                    $location_name,
                    'full_warm',
                    200,
                    "Fetched {$page} page(s), last page had " . count( $page_items ) . " item(s) (page_size={$page_size}), total=" . count( $all_items )
                );
            }

            if ( $failed || empty( $all_items ) ) {
                if ( empty( $all_items ) && ! $failed ) {
                    self::log_api_error( $location_name, 'full_warm', 0, 'API returned 0 items — cache row not updated' );
                }
                continue;
            }

            $wpdb->insert( $table_name, [
                'location_name' => $location_name,
                'api_data'      => wp_json_encode( $all_items ),
                'fetched_at'    => current_time( 'mysql' ),
            ] );
            unset( $all_items );

            self::prune_cache( $location_name );
            update_option( 'lic_cache_last_warmed_' . sanitize_key( $location_name ), current_time( 'mysql' ) );
            $any_cached = true;
        }

        if ( $any_cached ) {
            $queue_table = $wpdb->prefix . 'lic_import_queue';
            $active      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status IN ('pending','processing')" );
            if ( $active === 0 ) {
                LIC_Importer::lic_queue_start();
            }
        }
    }

    // Fetches only items updated since the last full warm and merges them into the cache.
    public static function incremental_update() {
        if ( get_option( 'lic_sync_enabled', '1' ) === '0' ) {
            return;
        }
        global $wpdb;
        $settings   = get_option( 'lic_settings' );
        $locations  = $settings['locations'] ?? [];
        $table_name = $wpdb->prefix . 'lic_api_cache';

        foreach ( $locations as $location_data ) {
            $location_name = $location_data['name'] ?? '';
            if ( empty( $location_name ) ) continue;

            $last_warmed = get_option( 'lic_cache_last_warmed_' . sanitize_key( $location_name ) );
            if ( empty( $last_warmed ) ) continue;

            // lic_cache_last_warmed_* is stored in site-local time; convert it to a real UTC
            // timestamp before building the OData filter, otherwise sites west of UTC ask for
            // updates that are still hours in the future and silently miss the newest changes.
            $since_ts    = strtotime( get_gmt_from_date( $last_warmed ) . ' UTC' ) + 300;
            $since_odata = gmdate( "Y-m-d\TH:i:s", $since_ts );

            $creds      = lic_get_credentials( $location_name );
            $use_sandbox = ! empty( $location_data['use_sandbox'] );
            $api_key    = $use_sandbox ? ( $creds['api_username_sandbox'] ?? '' ) : ( $creds['api_username_live'] ?? '' );
            $api_secret = $use_sandbox ? ( $creds['api_password_sandbox'] ?? '' ) : ( $creds['api_password_live'] ?? '' );
            $api_url    = $location_data['api_url'] ?? '';

            if ( empty( $api_url ) || empty( $api_key ) ) continue;
            if ( ! wp_http_validate_url( $api_url ) ) {
                self::log_api_error( $location_name, 'incremental', 0, 'Invalid or disallowed API URL — skipping location.' );
                continue;
            }

            $always_required = [ 'StockNumber', 'UnitStatus', 'Images', 'lastupdatedate', 'VIN', 'videourl', 'NewUsed', 'Location', 'Odometer' ];
            $mapped          = get_option( 'mapped_fields_' . $location_name, [] );
            $mapped_api_fields = array_merge(
                array_values( $mapped['WC']['api_fields']  ?? [] ),
                array_values( $mapped['ACF']['api_fields'] ?? [] )
            );
            $select_fields = array_values( array_unique( array_filter( array_merge( $always_required, $mapped_api_fields ) ) ) );

            $incremental_url = add_query_arg( [
                '$select' => implode( ',', $select_fields ),
                '$filter' => "lastupdatedate gt datetime'" . $since_odata . "'",
            ], $api_url );

            $response = wp_remote_get( $incremental_url, [
                'timeout' => 120,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                self::log_api_error( $location_name, 'incremental', 0, 'Transport error: ' . $response->get_error_message() );
                continue;
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code !== 200 ) {
                self::log_api_error( $location_name, 'incremental', $http_code, 'HTTP ' . $http_code . ' response' );
                continue;
            }

            $new_items = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $new_items ) || empty( $new_items ) ) {
                update_option( 'lic_cache_last_warmed_' . sanitize_key( $location_name ), current_time( 'mysql' ) );
                continue;
            }

            $new_index = [];
            foreach ( $new_items as $item ) {
                if ( isset( $item['StockNumber'] ) ) {
                    $new_index[ (string) $item['StockNumber'] ] = $item;
                }
            }

            $existing = self::get_cached_data( $location_name );
            foreach ( $existing as &$existing_item ) {
                $sn = (string) ( $existing_item['StockNumber'] ?? '' );
                if ( isset( $new_index[ $sn ] ) ) {
                    // New values must win over the cached copy. Merging in the other order
                    // made every refreshed field silently revert to its old value.
                    $existing_item = array_merge( $existing_item, $new_index[ $sn ] );
                    unset( $new_index[ $sn ] );
                }
            }
            unset( $existing_item );
            foreach ( $new_index as $item ) {
                $existing[] = $item;
            }
            unset( $new_index );

            $wpdb->insert( $table_name, [
                'location_name' => $location_name,
                'api_data'      => wp_json_encode( $existing ),
                'fetched_at'    => current_time( 'mysql' ),
            ] );
            unset( $existing );

            self::prune_cache( $location_name );
            update_option( 'lic_cache_last_warmed_' . sanitize_key( $location_name ), current_time( 'mysql' ) );
        }
    }

    // Only a genuine sale (S) or trade (T) means a unit is actually gone from inventory. Other
    // SoldUnit codes represent leaving Unit for a reason that isn't a sale — most notably X
    // (Transferred to another store location), where the same physical unit reappears as
    // available in Unit at the destination location under the same stock number/VIN. Treating
    // those as "sold" trashed a still-for-sale unit's listing outright (confirmed against real
    // transfer cases: the Unit record showed up at one location while SoldUnit showed the
    // transfer-out at the other). P/C/B/V and any other non-S/T code are excluded on the same
    // reasoning — none of them were verified to mean an actual completed sale.
    const SOLD_UNIT_STATUSES = [ 'S', 'T' ];

    // Collapses raw SoldUnit rows to one entry per stock number, keeping whichever row has the
    // latest lastupdatedate, then keeps only entries whose most-recent status is an actual sale
    // (see SOLD_UNIT_STATUSES). A reused stock number can legitimately have more than one
    // historical SoldUnit row in a single response — this ensures both the VIN we record and the
    // status we act on reflect the most recent event, not whichever row happened to come back
    // first.
    private static function dedupe_sold_items( array $items ) {
        $best = [];
        foreach ( $items as $item ) {
            $sn = (string) ( $item['StockNumber'] ?? '' );
            if ( $sn === '' ) continue;
            $lu = (string) ( $item['lastupdatedate'] ?? '' );
            if ( ! isset( $best[ $sn ] ) || strcmp( $lu, $best[ $sn ]['lastupdatedate'] ) > 0 ) {
                $best[ $sn ] = [
                    'lastupdatedate' => $lu,
                    'vin'            => (string) ( $item['VIN'] ?? '' ),
                    'unit_status'    => (string) ( $item['UnitStatus'] ?? '' ),
                ];
            }
        }
        $result = [];
        foreach ( $best as $sn => $data ) {
            if ( ! in_array( $data['unit_status'], self::SOLD_UNIT_STATUSES, true ) ) {
                continue;
            }
            // A stock number can be reused by a different vehicle after the original sells.
            // Never let the old SoldUnit row trash the newer unit that now carries that number.
            if ( $data['vin'] !== '' && ! self::sold_vin_matches_current_product( $sn, $data['vin'] ) ) {
                continue;
            }
            $result[] = [
                'stock_number' => $sn,
                'vin'          => $data['vin'],
                'unit_status'  => $data['unit_status'],
            ];
        }
        return $result;
    }

    // True when the published product for this stock number is the same vehicle as the
    // SoldUnit row, or when the product has no stored VIN to compare (legacy rows).
    private static function sold_vin_matches_current_product( $stock_number, $vin ) {
        global $wpdb;
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
               AND p.post_type = 'product' AND p.post_status = 'publish'
             LIMIT 1",
            $stock_number
        ) );
        if ( ! $product_id ) {
            return true;
        }

        $product_vins = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key LIKE %s",
            $product_id,
            '%vin%'
        ) );
        $product_vins = array_filter( array_map( 'strval', (array) $product_vins ) );
        if ( empty( $product_vins ) ) {
            return true;
        }

        return in_array( (string) $vin, $product_vins, true );
    }

    // Polls SoldUnit for each location for units that appeared there since the last check, and
    // immediately marks the matching WooCommerce products out-of-stock. This is the primary,
    // low-latency defense against sold units lingering on the site — the Unit feed alone can't
    // be trusted for this, since a sold unit can sit in Unit for months without its UnitStatus
    // or WebUnit flag ever reflecting the sale.
    //
    // Any row returned by SoldUnit is treated as "no longer available," regardless of its
    // specific UnitStatus code (S/T/E/P/C/B/X/M) — the guide describes this at the endpoint
    // level ("SoldUnit displays previously sold units that are no longer available to be sold"),
    // not per status code, and every code that endpoint uses represents leaving available
    // inventory. Filtering on UnitStatus = 'S' specifically missed real sold units that show
    // other codes there (observed: a confirmed-sold unit recorded as 'C').
    //
    // Runs hourly, one delta request per location — negligible against Lightspeed's documented
    // 200 requests/minute cap.
    public static function check_sold_units_incremental() {
        if ( get_option( 'lic_sync_enabled', '1' ) === '0' ) {
            return;
        }
        $settings  = get_option( 'lic_settings' );
        $locations = $settings['locations'] ?? [];

        foreach ( $locations as $location_data ) {
            $location_name = $location_data['name'] ?? '';
            if ( empty( $location_name ) ) continue;

            $api_url  = $location_data['api_url'] ?? '';
            $sold_url = lic_get_sold_unit_url( $api_url );
            if ( empty( $sold_url ) || ! wp_http_validate_url( $sold_url ) ) {
                self::log_api_error( $location_name, 'sold_incremental', 0, 'Could not derive a valid SoldUnit URL from the configured API URL — skipping.' );
                continue;
            }

            $creds       = lic_get_credentials( $location_name );
            $use_sandbox = ! empty( $location_data['use_sandbox'] );
            $api_key     = $use_sandbox ? ( $creds['api_username_sandbox'] ?? '' ) : ( $creds['api_username_live'] ?? '' );
            $api_secret  = $use_sandbox ? ( $creds['api_password_sandbox'] ?? '' ) : ( $creds['api_password_live'] ?? '' );
            if ( empty( $api_key ) ) continue;

            $option_key  = 'lic_sold_check_last_run_' . sanitize_key( $location_name );
            $last_run    = get_option( $option_key );
            // First run for this location: look back 26 hours (one daily cycle + buffer)
            // instead of pulling all sold history.
            $since_ts    = $last_run
                ? ( strtotime( get_gmt_from_date( $last_run ) . ' UTC' ) - 300 )
                : ( time() - 26 * HOUR_IN_SECONDS );
            $since_odata = gmdate( "Y-m-d\TH:i:s", $since_ts );

            $request_url = add_query_arg( [
                '$select' => 'StockNumber,UnitStatus,lastupdatedate,VIN',
                '$filter' => "lastupdatedate gt datetime'" . $since_odata . "'",
                '$top'    => 500,
            ], $sold_url );

            $response = wp_remote_get( $request_url, [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                self::log_api_error( $location_name, 'sold_incremental', 0, 'Transport error: ' . $response->get_error_message() );
                continue;
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code !== 200 ) {
                self::log_api_error( $location_name, 'sold_incremental', $http_code, 'HTTP ' . $http_code . ' response' );
                continue;
            }

            $items = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( is_array( $items ) ) {
                $sold_items = self::dedupe_sold_items( $items );
                if ( ! empty( $sold_items ) ) {
                    LIC_Importer::remove_confirmed_sold_skus( $sold_items, 'sold_unit_incremental', $location_name );
                }
            }

            update_option( $option_key, current_time( 'mysql' ) );
        }
    }

    // Safety-net reconciliation: sweeps every currently-published product SKU against SoldUnit
    // in batches, catching anything the hourly delta missed (a missed cron run, a dealer record
    // whose lastupdatedate wasn't bumped, etc). Scoped to only the SKUs actually live on the
    // site — not the dealer's full sold history — so it stays cheap and bounded. Runs monthly;
    // can also be triggered manually from the dashboard.
    public static function reconcile_sold_units() {
        if ( get_option( 'lic_sync_enabled', '1' ) === '0' ) {
            return;
        }
        @set_time_limit( 300 );

        $cron_name  = 'sold_unit_reconciliation';
        $start_time = current_time( 'mysql' );
        LIC_Importer::log_cron_error( $cron_name, 'Reconciliation started', $start_time );

        $settings  = get_option( 'lic_settings' );
        $locations = $settings['locations'] ?? [];
        if ( empty( $locations ) ) {
            LIC_Importer::log_cron_error( $cron_name, 'No locations configured — nothing to reconcile.', $start_time, current_time( 'mysql' ) );
            return;
        }

        global $wpdb;
        $skus = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
               AND pm.meta_value != ''
               AND p.post_type = 'product'
               AND p.post_status = 'publish'"
        );
        $skus = array_values( array_filter( $skus ) );

        if ( empty( $skus ) ) {
            LIC_Importer::log_cron_error( $cron_name, 'No published product SKUs to check.', $start_time, current_time( 'mysql' ) );
            return;
        }

        $batch_size     = 25;
        $batches        = array_chunk( $skus, $batch_size );
        $sold_found     = [];
        $requests_made  = 0;
        $total_marked   = 0;
        $failed_batches = 0;

        foreach ( $locations as $location_data ) {
            $location_name       = $location_data['name'] ?? '';
            $sold_found_location = [];
            if ( empty( $location_name ) ) continue;

            $api_url  = $location_data['api_url'] ?? '';
            $sold_url = lic_get_sold_unit_url( $api_url );
            if ( empty( $sold_url ) || ! wp_http_validate_url( $sold_url ) ) {
                self::log_api_error( $location_name, 'sold_reconciliation', 0, 'Could not derive a valid SoldUnit URL from the configured API URL — skipping location.' );
                continue;
            }

            $creds       = lic_get_credentials( $location_name );
            $use_sandbox = ! empty( $location_data['use_sandbox'] );
            $api_key     = $use_sandbox ? ( $creds['api_username_sandbox'] ?? '' ) : ( $creds['api_username_live'] ?? '' );
            $api_secret  = $use_sandbox ? ( $creds['api_password_sandbox'] ?? '' ) : ( $creds['api_password_live'] ?? '' );
            if ( empty( $api_key ) ) continue;

            foreach ( $batches as $batch ) {
                $or_clauses = array_map( function ( $sku ) {
                    return "StockNumber eq '" . str_replace( "'", "''", $sku ) . "'";
                }, $batch );
                $filter = implode( ' or ', $or_clauses );

                // Paginate rather than trust a single capped request — a batch of 25 stock
                // numbers can collectively have more than $top matching SoldUnit rows if any of
                // them were resold more than once, and with no $orderby specified, which rows
                // get silently cut off by $top is unpredictable. Looping on $skip until a page
                // comes back short guarantees every matching row for this batch is retrieved,
                // not just however many fit in the first page. (Pure OR-chaining, no UnitStatus
                // condition and no combined AND+OR-group — a filter shaped like "UnitStatus eq
                // 'S' and (A or B or ...)" separately turned out to silently miss a record that
                // independently satisfied both conditions, so that combined shape isn't trusted
                // here either.)
                $page_size    = $batch_size * 4;
                $skip         = 0;
                $batch_raw    = [];
                $batch_failed = false;

                do {
                    $request_url = add_query_arg( [
                        '$select' => 'StockNumber,UnitStatus,VIN,lastupdatedate',
                        '$filter' => $filter,
                        '$top'    => $page_size,
                        '$skip'   => $skip,
                    ], $sold_url );

                    $response = wp_remote_get( $request_url, [
                        'timeout' => 60,
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                        ],
                    ] );
                    $requests_made++;

                    if ( is_wp_error( $response ) ) {
                        self::log_api_error( $location_name, 'sold_reconciliation', 0, 'Transport error: ' . $response->get_error_message() );
                        $batch_failed = true;
                        break;
                    }

                    $http_code = wp_remote_retrieve_response_code( $response );
                    if ( $http_code !== 200 ) {
                        self::log_api_error( $location_name, 'sold_reconciliation', $http_code, 'HTTP ' . $http_code . ' response' );
                        $batch_failed = true;
                        break;
                    }

                    $page_items = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( ! is_array( $page_items ) ) {
                        break;
                    }
                    $batch_raw = array_merge( $batch_raw, $page_items );
                    $skip     += $page_size;

                    // Stay comfortably under the documented 200 requests/minute cap.
                    usleep( 350000 );

                } while ( count( $page_items ) === $page_size );

                if ( $batch_failed ) {
                    $failed_batches++;
                }
                if ( ! empty( $batch_raw ) ) {
                    $sold_found_location = array_merge( $sold_found_location, self::dedupe_sold_items( $batch_raw ) );
                }
            }

            if ( ! empty( $sold_found_location ) ) {
                $total_marked += LIC_Importer::remove_confirmed_sold_skus( $sold_found_location, $cron_name, $location_name );
                $sold_found    = array_merge( $sold_found, $sold_found_location );
            }
        }

        $unique_sold_found = count( array_unique( array_column( $sold_found, 'stock_number' ) ) );
        $summary = 'Reconciliation completed. Checked ' . count( $skus ) . ' SKU(s) across ' . count( $locations ) . ' location(s) in ' . $requests_made . ' request(s). Found ' . $unique_sold_found . ' sold, trashed ' . $total_marked . ' product(s).';
        if ( $failed_batches > 0 ) {
            $summary .= ' WARNING: ' . $failed_batches . ' batch request(s) failed and were skipped — the SKUs in those batches were not checked this run. See API Errors log (context: sold_reconciliation).';
        }
        LIC_Importer::log_cron_error(
            $cron_name,
            $summary,
            $start_time,
            current_time( 'mysql' )
        );
    }

    // Debug helper for the "Stock Debug" admin tab: looks up a single stock number live
    // against both Unit and SoldUnit for every configured location, plus its
    // lic_confirmed_sold / lic_sold_review_queue state, and what the current sync logic would
    // decide for each VIN found in Unit right now. Nothing here is cached or logged to the
    // API error table — it's a one-off manual lookup, not a background sync.
    public static function debug_lookup_stock_number( $stock_number ) {
        $stock_number_escaped = str_replace( "'", "''", $stock_number );
        $settings  = get_option( 'lic_settings' );
        $locations = $settings['locations'] ?? [];

        $result = [
            'stock_number' => $stock_number,
            'locations'    => [],
        ];

        foreach ( $locations as $location_data ) {
            $location_name = $location_data['name'] ?? '';
            if ( empty( $location_name ) ) continue;

            $api_url  = $location_data['api_url'] ?? '';
            $sold_url = lic_get_sold_unit_url( $api_url );

            $creds       = lic_get_credentials( $location_name );
            $use_sandbox = ! empty( $location_data['use_sandbox'] );
            $api_key     = $use_sandbox ? ( $creds['api_username_sandbox'] ?? '' ) : ( $creds['api_username_live'] ?? '' );
            $api_secret  = $use_sandbox ? ( $creds['api_password_sandbox'] ?? '' ) : ( $creds['api_password_live'] ?? '' );

            $loc_result = [
                'location_name' => $location_name,
                'unit_records'  => [],
                'sold_records'  => [],
                'unit_error'    => '',
                'sold_error'    => '',
            ];

            if ( empty( $api_key ) || ! wp_http_validate_url( $api_url ) ) {
                $loc_result['unit_error'] = 'Missing/invalid credentials or API URL for this location.';
            } else {
                $loc_result['unit_records'] = self::debug_fetch( $api_url, $api_key, $api_secret, $stock_number_escaped, $loc_result['unit_error'] );
            }

            if ( empty( $sold_url ) || ! wp_http_validate_url( $sold_url ) ) {
                $loc_result['sold_error'] = 'Could not derive a valid SoldUnit URL for this location.';
            } elseif ( ! empty( $api_key ) ) {
                $loc_result['sold_records'] = self::debug_fetch( $sold_url, $api_key, $api_secret, $stock_number_escaped, $loc_result['sold_error'] );
            }

            $result['locations'][] = $loc_result;
        }

        global $wpdb;

        // reconcile_sold_units() only ever checks SKUs belonging to currently-*published* WC
        // products — if there's no published product for this SKU, it's never included in that
        // sweep's batches at all, regardless of what SoldUnit says. Surface that state directly
        // instead of leaving it to be inferred.
        $wc_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.ID, p.post_status
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND p.post_type = 'product'
             LIMIT 1",
            $stock_number
        ), ARRAY_A );
        if ( $wc_row ) {
            $result['wc_product'] = [
                'product_id'    => (int) $wc_row['ID'],
                'post_status'   => $wc_row['post_status'],
                'stock_status'  => get_post_meta( (int) $wc_row['ID'], '_stock_status', true ),
            ];
        } else {
            $result['wc_product'] = null;
        }

        $confirmed_table = $wpdb->prefix . 'lic_confirmed_sold';
        $result['confirmed_sold'] = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$confirmed_table} WHERE stock_number = %s", $stock_number
        ), ARRAY_A );

        $review_table = $wpdb->prefix . 'lic_sold_review_queue';
        $result['review_queue'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$review_table} WHERE stock_number = %s AND dismissed_at IS NULL ORDER BY detected_at DESC", $stock_number
        ), ARRAY_A );

        // What is_sku_confirmed_sold() would decide right now for each VIN currently in Unit.
        $result['decisions'] = [];
        foreach ( $result['locations'] as $loc ) {
            foreach ( $loc['unit_records'] as $rec ) {
                $vin = (string) ( $rec['VIN'] ?? '' );
                $result['decisions'][] = [
                    'location' => $loc['location_name'],
                    'vin'      => $vin,
                    'blocked'  => LIC_Importer::is_sku_confirmed_sold( $stock_number, $vin ),
                ];
            }
        }

        // Runs the *exact* batched SoldUnit request this stock number would fall into during a
        // real reconcile_sold_units() run, so a suspected batching problem can be observed
        // directly instead of inferred from log summaries.
        $result['batch_simulation'] = self::debug_simulate_reconciliation_batch( $stock_number );

        return $result;
    }

    // Rebuilds the same published-SKU batch reconcile_sold_units() would use, finds which batch
    // the given stock number falls into, and fires that exact request against SoldUnit for every
    // location — reporting the filter used, how many raw rows came back, and whether the target
    // stock number was actually present in that response. Ground truth for "why didn't the
    // reconciliation sweep catch this stock number," instead of guessing at the batching logic.
    public static function debug_simulate_reconciliation_batch( $stock_number ) {
        global $wpdb;
        $skus = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
               AND pm.meta_value != ''
               AND p.post_type = 'product'
               AND p.post_status = 'publish'"
        );
        $skus = array_values( array_filter( $skus ) );

        $batch_size = 25;
        $batches    = array_chunk( $skus, $batch_size );

        $target_index = null;
        foreach ( $batches as $i => $batch ) {
            if ( in_array( (string) $stock_number, $batch, true ) ) {
                $target_index = $i;
                break;
            }
        }

        $result = [
            'total_published_skus' => count( $skus ),
            'total_batches'        => count( $batches ),
            'target_batch_index'   => $target_index,
            'locations'            => [],
        ];

        if ( $target_index === null ) {
            // Not among currently-published SKUs at all — reconcile_sold_units() never includes
            // it in any batch, regardless of what SoldUnit says. Confirms/refutes this without
            // needing to separately check the WooCommerce product list.
            return $result;
        }

        $batch     = $batches[ $target_index ];
        $settings  = get_option( 'lic_settings' );
        $locations = $settings['locations'] ?? [];

        $or_clauses = array_map( function ( $sku ) {
            return "StockNumber eq '" . str_replace( "'", "''", $sku ) . "'";
        }, $batch );
        $filter = implode( ' or ', $or_clauses );

        foreach ( $locations as $location_data ) {
            $location_name = $location_data['name'] ?? '';
            if ( empty( $location_name ) ) continue;

            $api_url  = $location_data['api_url'] ?? '';
            $sold_url = lic_get_sold_unit_url( $api_url );

            $creds       = lic_get_credentials( $location_name );
            $use_sandbox = ! empty( $location_data['use_sandbox'] );
            $api_key     = $use_sandbox ? ( $creds['api_username_sandbox'] ?? '' ) : ( $creds['api_username_live'] ?? '' );
            $api_secret  = $use_sandbox ? ( $creds['api_password_sandbox'] ?? '' ) : ( $creds['api_password_live'] ?? '' );

            $loc_result = [
                'location_name'    => $location_name,
                'batch_size'       => count( $batch ),
                'filter_length'    => strlen( $filter ),
                'request_url'      => '',
                'raw_item_count'   => 0,
                'contains_target'  => false,
                'error'            => '',
            ];

            if ( empty( $sold_url ) || ! wp_http_validate_url( $sold_url ) || empty( $api_key ) ) {
                $loc_result['error'] = 'Missing/invalid credentials or SoldUnit URL for this location.';
                $result['locations'][] = $loc_result;
                continue;
            }

            $request_url = add_query_arg( [
                '$select' => 'StockNumber,UnitStatus,VIN,lastupdatedate',
                '$filter' => $filter,
                '$top'    => $batch_size * 4,
            ], $sold_url );
            $loc_result['request_url'] = $request_url;

            $response = wp_remote_get( $request_url, [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                $loc_result['error'] = 'Transport error: ' . $response->get_error_message();
            } else {
                $http_code = wp_remote_retrieve_response_code( $response );
                if ( $http_code !== 200 ) {
                    $loc_result['error'] = 'HTTP ' . $http_code . ' — ' . wp_remote_retrieve_body( $response );
                } else {
                    $items = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( is_array( $items ) ) {
                        $loc_result['raw_item_count'] = count( $items );
                        foreach ( $items as $item ) {
                            if ( (string) ( $item['StockNumber'] ?? '' ) === (string) $stock_number ) {
                                $loc_result['contains_target'] = true;
                                break;
                            }
                        }
                    } else {
                        $loc_result['error'] = 'Response body was not a JSON array: ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 300 );
                    }
                }
            }

            $result['locations'][] = $loc_result;
        }

        return $result;
    }

    // Fetches up to 10 matching records for a stock number from a given Unit/SoldUnit URL.
    private static function debug_fetch( $url, $api_key, $api_secret, $stock_number_escaped, &$error ) {
        $request_url = add_query_arg( [
            '$select' => 'StockNumber,VIN,UnitStatus,WebUnit,lastupdatedate',
            '$filter' => "StockNumber eq '" . $stock_number_escaped . "'",
            '$top'    => 10,
        ], $url );

        $response = wp_remote_get( $request_url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            $error = 'Transport error: ' . $response->get_error_message();
            return [];
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) {
            $error = 'HTTP ' . $http_code . ' — ' . wp_remote_retrieve_body( $response );
            return [];
        }

        $items = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $items ) ? $items : [];
    }

    // Inserts a row into the API error log table.
    private static function log_api_error( $location_name, $context, $http_code, $error_message ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'lic_api_error_log',
            [
                'location_name' => $location_name,
                'context'       => $context,
                'http_code'     => (int) $http_code,
                'error_message' => $error_message,
                'logged_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    // Deletes cache rows for a location older than 24 hours.
    public static function prune_cache( $location_name ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lic_api_cache';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table_name}
             WHERE location_name = %s
               AND fetched_at < DATE_SUB( NOW(), INTERVAL 24 HOUR )",
            $location_name
        ) );
    }

    // Returns the most recent cached API data array for a location.
    public static function get_cached_data( $location_name ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lic_api_cache';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT api_data FROM $table_name WHERE location_name = %s ORDER BY fetched_at DESC LIMIT 1",
            $location_name
        ) );
        return $row ? json_decode( $row->api_data, true ) : [];
    }
}
