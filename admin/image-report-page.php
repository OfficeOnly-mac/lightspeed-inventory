<?php

if ( ! defined( 'ABSPATH' ) ) die;

// Extract the UUID/filename from a URL for cross-scheme comparison.
// _lic_source_url stores the local WP URL; API ImageUrl is the Lightspeed CDN URL.
// Both share the same UUID basename, so comparing filenames resolves the mismatch.
function lic_img_uuid( $url ) {
    return pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME );
}

// Routes between the summary table and the per-product detail page.
function lic_image_report() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_GET['product_id'] ) ) {
        lic_image_report_detail( absint( $_GET['product_id'] ) );
        return;
    }
    lic_image_report_table();
}

// Renders the sortable, paginated, searchable summary table.
function lic_image_report_table() {
    global $wpdb;

    $per_page_raw = (int) ( $_GET['per_page'] ?? 25 );
    $per_page     = in_array( $per_page_raw, [ 25, 50, 100 ], true ) ? $per_page_raw : 25;
    $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $sort_col     = in_array( $_GET['orderby'] ?? '', [ 'sku', 'location', 'api_count', 'site_count', 'match' ], true )
                    ? sanitize_key( $_GET['orderby'] )
                    : 'match';
    $sort_dir     = ( ( $_GET['order'] ?? 'asc' ) === 'desc' ) ? 'desc' : 'asc';
    $filter       = ( ( $_GET['filter'] ?? 'all' ) === 'mismatches' ) ? 'mismatches' : 'all';
    $search       = sanitize_text_field( $_GET['search'] ?? '' );
    $in_logs_tab  = ( ( $_GET['page'] ?? '' ) === 'lic-logs' );
    $base_url     = $in_logs_tab
        ? admin_url( 'admin.php?page=lic-logs&tab=image-report' )
        : admin_url( 'admin.php?page=image-logs-report' );

    // Build per-location API cache index (one DB query per location).
    $settings   = get_option( 'lic_settings' );
    $locations  = $settings['locations'] ?? [];
    $api_by_loc = [];
    foreach ( $locations as $loc_data ) {
        $loc_name = $loc_data['name'] ?? '';
        if ( empty( $loc_name ) ) continue;
        $api_by_loc[ $loc_name ] = [];
        foreach ( LIC_Cron_Manager::get_cached_data( $loc_name ) as $item ) {
            $sku = (string) ( $item['StockNumber'] ?? '' );
            if ( $sku !== '' ) {
                $api_by_loc[ $loc_name ][ $sku ] = $item;
            }
        }
    }

    // Fetch all published products that have a non-empty SKU.
    $product_ids = $wpdb->get_col(
        "SELECT DISTINCT pm.post_id
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_sku'
           AND pm.meta_value != ''
           AND p.post_type = 'product'
           AND p.post_status = 'publish'"
    );

    // Build one comparison row per product.
    $all_rows = [];
    foreach ( $product_ids as $pid ) {
        $pid      = (int) $pid;
        $sku      = trim( (string) get_post_meta( $pid, '_sku', true ) );
        $location = trim( (string) get_post_meta( $pid, 'location', true ) );
        $api_item = $api_by_loc[ $location ][ $sku ] ?? null;

        // Only count API images that have a valid URL.
        $api_urls = [];
        if ( $api_item ) {
            foreach ( $api_item['Images'] ?? [] as $img ) {
                $u = $img['ImageUrl'] ?? '';
                if ( $u !== '' ) $api_urls[] = $u;
            }
        }

        // Collect all product attachments (thumbnail + gallery), deduplicated.
        $thumbnail_id = (int) get_post_meta( $pid, '_thumbnail_id', true );
        $gallery_ids  = array_values( array_filter(
            array_map( 'intval', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) )
        ) );
        $att_ids = array_values( array_unique( array_filter(
            array_merge( $thumbnail_id ? [ $thumbnail_id ] : [], $gallery_ids )
        ) ) );

        // Collect _lic_source_url values — set by the plugin when downloading images.
        $site_urls = [];
        foreach ( $att_ids as $att_id ) {
            $src = get_post_meta( $att_id, '_lic_source_url', true );
            if ( $src ) $site_urls[] = $src;
        }

        // Match logic:
        // - No API images → Y (site placeholder is expected, nothing to compare against).
        // - All images tracked → UUID-set match (filenames, not full URLs — the two URL
        //   schemes differ: _lic_source_url is a local WP URL, ImageUrl is the Lightspeed CDN).
        // - Not all tracked → count-based fallback (pre-C-15 imports).
        if ( count( $api_urls ) === 0 ) {
            $match = 'Y';
        } elseif ( count( $site_urls ) > 0 && count( $site_urls ) === count( $att_ids ) ) {
            $a = array_map( 'lic_img_uuid', $api_urls );
            $s = array_map( 'lic_img_uuid', $site_urls );
            sort( $a ); sort( $s );
            $match = ( $a === $s ) ? 'Y' : 'N';
        } else {
            $match = ( count( $att_ids ) === count( $api_urls ) ) ? 'Y' : 'N';
        }

        $all_rows[] = [
            'product_id' => $pid,
            'sku'        => $sku,
            'location'   => $location,
            'api_count'  => count( $api_urls ),
            'site_count' => count( $att_ids ),
            'match'      => $match,
        ];
    }

    // Count products that are in the cache but have no API images.
    $zero_api_image_count = count( array_filter( $all_rows, fn( $r ) => $r['api_count'] === 0 ) );

    // Apply stock number search filter.
    if ( $search !== '' ) {
        $search_lower = strtolower( $search );
        $all_rows = array_values( array_filter(
            $all_rows,
            fn( $r ) => strpos( strtolower( $r['sku'] ), $search_lower ) !== false
        ) );
    }

    $total_all      = count( $all_rows );
    $mismatch_count = count( array_filter( $all_rows, fn( $r ) => $r['match'] === 'N' ) );

    $rows = ( $filter === 'mismatches' )
        ? array_values( array_filter( $all_rows, fn( $r ) => $r['match'] === 'N' ) )
        : $all_rows;

    usort( $rows, function ( $a, $b ) use ( $sort_col, $sort_dir ) {
        $va  = $a[ $sort_col ] ?? '';
        $vb  = $b[ $sort_col ] ?? '';
        $cmp = is_numeric( $va ) && is_numeric( $vb )
            ? ( (int) $va <=> (int) $vb )
            : strcasecmp( (string) $va, (string) $vb );
        return $sort_dir === 'desc' ? -$cmp : $cmp;
    } );

    $total_rows   = count( $rows );
    $total_pages  = max( 1, (int) ceil( $total_rows / $per_page ) );
    $current_page = min( $current_page, $total_pages );
    $page_rows    = array_slice( $rows, ( $current_page - 1 ) * $per_page, $per_page );

    // URL builders — search is preserved in all navigation links.
    $q = function ( $extra ) use ( $base_url, $per_page, $sort_col, $sort_dir, $filter, $search ) {
        $base = [
            'per_page' => $per_page,
            'orderby'  => $sort_col,
            'order'    => $sort_dir,
            'filter'   => $filter,
        ];
        if ( $search !== '' ) $base['search'] = $search;
        $args = array_merge( $base, $extra );
        // Allow $extra to clear search by passing 'search' => ''.
        $args = array_filter( $args, fn( $v ) => $v !== '' && $v !== null );
        return esc_url( add_query_arg( $args, $base_url ) );
    };
    $sort_url = fn( $col ) => $q( [
        'orderby' => $col,
        'order'   => ( $col === $sort_col && $sort_dir === 'asc' ) ? 'desc' : 'asc',
        'paged'   => 1,
    ] );
    $arrow = fn( $col ) => $col === $sort_col ? ( $sort_dir === 'asc' ? ' ↑' : ' ↓' ) : ' ↕';

    // ── Render ──────────────────────────────────────────────────────────────
    if ( ! $in_logs_tab ) {
        echo '<div class="wrap">';
        lic_sync_disabled_notice();
        echo '<h1>Image Report</h1>';
    }

    if ( $zero_api_image_count > 0 ) {
        printf(
            '<div style="background:#fff8e5;border:1px solid #e6c200;border-left:4px solid #e6c200;border-radius:3px;padding:12px 16px;margin:0 0 16px;display:flex;gap:10px;align-items:flex-start;">'
            . '<span class="dashicons dashicons-warning" style="color:#c8a000;font-size:20px;flex-shrink:0;margin-top:1px;"></span>'
            . '<div><strong>%s product%s in the API cache have no images.</strong> '
            . 'If images exist in Lightspeed but are not appearing here, check that the images are not set to <em>private</em> in Lightspeed — '
            . 'private images are excluded from the API and will never be imported to the website.</div></div>',
            esc_html( $zero_api_image_count ),
            $zero_api_image_count !== 1 ? 's' : ''
        );
    }

    // Toolbar row 1: filter toggles + search + per-page selector.
    echo '<div class="lic-ir-toolbar">';

    echo '<div class="lic-ir-filter">';
    printf(
        '<a href="%s" class="button%s">All (%s)</a> ',
        $q( [ 'filter' => 'all', 'paged' => 1 ] ),
        $filter === 'all' ? ' button-primary' : '',
        esc_html( $total_all )
    );
    printf(
        '<a href="%s" class="button%s">Mismatches (%s)</a>',
        $q( [ 'filter' => 'mismatches', 'paged' => 1 ] ),
        $filter === 'mismatches' ? ' button-primary' : '',
        esc_html( $mismatch_count )
    );
    echo '</div>';

    // Search form — submits GET, preserves current filter/sort/per-page state.
    echo '<form class="lic-ir-search" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
    echo '<input type="hidden" name="page" value="' . ( $in_logs_tab ? 'lic-logs' : 'image-logs-report' ) . '">';
    if ( $in_logs_tab ) echo '<input type="hidden" name="tab" value="image-report">';
    printf( '<input type="hidden" name="per_page" value="%s">', esc_attr( $per_page ) );
    printf( '<input type="hidden" name="filter"   value="%s">', esc_attr( $filter ) );
    printf( '<input type="hidden" name="orderby"  value="%s">', esc_attr( $sort_col ) );
    printf( '<input type="hidden" name="order"    value="%s">', esc_attr( $sort_dir ) );
    printf(
        '<input type="text" name="search" value="%s" placeholder="Search stock number..." style="width:180px;">',
        esc_attr( $search )
    );
    echo ' <button type="submit" class="button">Search</button>';
    if ( $search !== '' ) {
        printf(
            ' <a href="%s" class="button">Clear</a>',
            $q( [ 'search' => '', 'paged' => 1 ] )
        );
    }
    echo '</form>';

    echo '<div class="lic-ir-perpage">';
    foreach ( [ 25, 50, 100 ] as $pp ) {
        printf(
            '<a href="%s" class="button%s">%s/pg</a> ',
            $q( [ 'per_page' => $pp, 'paged' => 1 ] ),
            $per_page === $pp ? ' button-primary' : '',
            $pp
        );
    }
    echo '</div>';
    echo '</div>'; // .lic-ir-toolbar

    // Toolbar row 2: bulk sync controls.
    echo '<div class="lic-ir-toolbar" style="margin-top:6px;">';
    echo '<div>';
    echo '<button id="lic-ir-sync-selected" class="button button-primary" disabled>Sync Selected</button>';
    echo ' <span id="lic-ir-sync-status" style="margin-left:10px;font-style:italic;"></span>';
    echo '</div>';
    echo '</div>';

    // Summary table.
    echo '<table class="widefat fixed lic-ir-table" cellspacing="0">';
    echo '<thead><tr>';
    echo '<th style="width:32px;"><input type="checkbox" id="lic-ir-select-all" title="Select all"></th>';
    $cols = [
        'sku'        => 'Stock Number',
        'location'   => 'Location',
        'api_count'  => '# in API',
        'site_count' => '# on Site',
        'match'      => 'Image Match',
    ];
    foreach ( $cols as $col_key => $col_label ) {
        printf( '<th><a href="%s">%s</a></th>', $sort_url( $col_key ), esc_html( $col_label . $arrow( $col_key ) ) );
    }
    echo '</tr></thead><tbody>';

    if ( empty( $page_rows ) ) {
        echo '<tr><td colspan="6">No products found.</td></tr>';
    } else {
        foreach ( $page_rows as $row ) {
            $detail_url = esc_url( add_query_arg( [
                'product_id'  => $row['product_id'],
                'back_paged'  => $current_page,
                'back_filter' => $filter,
                'back_order'  => $sort_col,
                'back_dir'    => $sort_dir,
                'back_pp'     => $per_page,
                'back_search' => $search,
            ], $base_url ) );
            $row_class = $row['match'] === 'N' ? ' class="lic-ir-mismatch"' : '';
            echo '<tr' . $row_class . '>';
            echo '<td><input type="checkbox" class="lic-ir-row-cb" value="' . esc_attr( $row['product_id'] ) . '"></td>';
            echo '<td><a href="' . $detail_url . '">' . esc_html( $row['sku'] ) . '</a></td>';
            echo '<td>' . esc_html( $row['location'] ) . '</td>';
            echo '<td>'
                . esc_html( $row['api_count'] )
                . ( $row['api_count'] === 0
                    ? ' <span class="dashicons dashicons-warning" title="No images in API — check for private images in Lightspeed" style="color:#c8a000;font-size:14px;vertical-align:middle;"></span>'
                    : '' )
                . '</td>';
            echo '<td>' . esc_html( $row['site_count'] ) . '</td>';
            echo '<td><strong>' . esc_html( $row['match'] ) . '</strong></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    // Pagination controls.
    if ( $total_pages > 1 ) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        if ( $current_page > 1 ) {
            printf( '<a href="%s" class="button">&laquo; Prev</a> ', $q( [ 'paged' => $current_page - 1 ] ) );
        }
        for ( $i = max( 1, $current_page - 2 ); $i <= min( $total_pages, $current_page + 2 ); $i++ ) {
            printf(
                '<a href="%s" class="button%s">%d</a> ',
                $q( [ 'paged' => $i ] ),
                $i === $current_page ? ' button-primary' : '',
                $i
            );
        }
        if ( $current_page < $total_pages ) {
            printf( '<a href="%s" class="button">Next &raquo;</a>', $q( [ 'paged' => $current_page + 1 ] ) );
        }
        echo '</div></div>';
    }

    if ( ! $in_logs_tab ) echo '</div>';
}

// Renders the per-product image comparison detail page.
function lic_image_report_detail( $product_id ) {
    $product = get_post( $product_id );
    if ( ! $product || $product->post_type !== 'product' ) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Product not found.</p></div></div>';
        return;
    }

    $sku      = trim( (string) get_post_meta( $product_id, '_sku', true ) );
    $location = trim( (string) get_post_meta( $product_id, 'location', true ) );

    // Find the API cache entry for this SKU.
    $cached   = LIC_Cron_Manager::get_cached_data( $location );
    $api_item = null;
    foreach ( $cached as $item ) {
        if ( trim( (string) ( $item['StockNumber'] ?? '' ) ) === $sku ) {
            $api_item = $item;
            break;
        }
    }

    $api_images = $api_item ? ( $api_item['Images'] ?? [] ) : [];

    // Build UUID set from API images for cross-scheme comparison.
    // _lic_source_url is a local WP URL; ImageUrl is the Lightspeed CDN URL.
    // Both share the same UUID basename — compare by that instead of full URL.
    $api_uuid_set  = [];
    $api_url_count = 0;
    foreach ( $api_images as $img ) {
        $u = $img['ImageUrl'] ?? '';
        if ( $u !== '' ) {
            $uuid = lic_img_uuid( $u );
            if ( $uuid !== '' ) $api_uuid_set[ $uuid ] = true;
            $api_url_count++;
        }
    }

    // Site: all product attachments (thumbnail + gallery), deduplicated.
    // 'in_api' = true/false when _lic_source_url exists; null for untracked (pre-C-15) images.
    $thumbnail_id = (int) get_post_meta( $product_id, '_thumbnail_id', true );
    $gallery_ids  = array_values( array_filter(
        array_map( 'intval', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) )
    ) );
    $gallery_ids = array_values( array_diff( $gallery_ids, $thumbnail_id ? [ $thumbnail_id ] : [] ) );

    $site_atts = [];
    if ( $thumbnail_id ) {
        $src    = get_post_meta( $thumbnail_id, '_lic_source_url', true );
        $in_api = null;
        if ( $src ) {
            $uuid   = lic_img_uuid( $src );
            $in_api = $uuid !== '' ? isset( $api_uuid_set[ $uuid ] ) : false;
        }
        $site_atts[] = [ 'id' => $thumbnail_id, 'src_url' => $src ?: '', 'type' => 'featured', 'in_api' => $in_api ];
    }
    foreach ( $gallery_ids as $att_id ) {
        $src    = get_post_meta( $att_id, '_lic_source_url', true );
        $in_api = null;
        if ( $src ) {
            $uuid   = lic_img_uuid( $src );
            $in_api = $uuid !== '' ? isset( $api_uuid_set[ $uuid ] ) : false;
        }
        $site_atts[] = [ 'id' => $att_id, 'src_url' => $src ?: '', 'type' => 'gallery', 'in_api' => $in_api ];
    }

    // Featured first, then gallery sorted by UUID so both columns are in the same order.
    usort( $site_atts, function( $a, $b ) {
        if ( $a['type'] === 'featured' ) return -1;
        if ( $b['type'] === 'featured' ) return 1;
        return strcmp( lic_img_uuid( $a['src_url'] ), lic_img_uuid( $b['src_url'] ) );
    } );

    $tracked_atts  = array_filter( $site_atts, fn( $a ) => $a['in_api'] !== null );
    $has_tracked   = ! empty( $site_atts ) && count( $tracked_atts ) === count( $site_atts );

    // UUID set for tracked site images (used for right-column "missing from site" check).
    $site_uuid_set = [];
    foreach ( $site_atts as $att ) {
        if ( $att['src_url'] !== '' && $att['in_api'] !== null ) {
            $uuid = lic_img_uuid( $att['src_url'] );
            if ( $uuid !== '' ) $site_uuid_set[ $uuid ] = true;
        }
    }

    $extra_on_site   = count( array_filter( $site_atts, fn( $a ) => $a['in_api'] === false ) );
    $missing_on_site = 0;
    if ( $has_tracked && $api_url_count > 0 ) {
        foreach ( $api_uuid_set as $uuid => $_ ) {
            if ( ! isset( $site_uuid_set[ $uuid ] ) ) $missing_on_site++;
        }
    }
    $present_count = count( array_filter( $site_atts, fn( $a ) => $a['in_api'] === true ) );

    // Preserve filter/page/search state in the back link.
    $in_logs_tab  = ( ( $_GET['page'] ?? '' ) === 'lic-logs' );
    $summary_base = $in_logs_tab
        ? admin_url( 'admin.php?page=lic-logs&tab=image-report' )
        : admin_url( 'admin.php?page=image-logs-report' );
    $back_url = esc_url( add_query_arg( array_filter( [
        'paged'    => (int) ( $_GET['back_paged']  ?? 0 ) ?: null,
        'filter'   => sanitize_key( $_GET['back_filter'] ?? '' ) ?: null,
        'orderby'  => sanitize_key( $_GET['back_order']  ?? '' ) ?: null,
        'order'    => sanitize_key( $_GET['back_dir']    ?? '' ) ?: null,
        'per_page' => (int) ( $_GET['back_pp']     ?? 0 ) ?: null,
        'search'   => sanitize_text_field( $_GET['back_search'] ?? '' ) ?: null,
    ] ), $summary_base ) );

    // ── Render ──────────────────────────────────────────────────────────────
    if ( ! $in_logs_tab ) echo '<div class="wrap">';
    echo '<p><a href="' . $back_url . '">&larr; Back to Image Report</a></p>';
    echo '<h1>' . esc_html( $product->post_title ) . '</h1>';
    echo '<p>';
    echo '<strong>SKU:</strong> ' . esc_html( $sku ) . ' &nbsp; ';
    echo '<strong>Location:</strong> ' . esc_html( $location ?: '—' );
    echo '</p>';

    if ( ! $api_item ) {
        $notice = empty( $cached )
            ? 'Cache is empty for location <strong>' . esc_html( $location ) . '</strong> — warm the cache before using this report.'
            : 'SKU <strong>' . esc_html( $sku ) . '</strong> was not found in the API cache for location <strong>' . esc_html( $location ) . '</strong>.';
        echo '<div class="notice notice-warning inline"><p>' . $notice . '</p></div>';
    }

    // Status summary bar.
    echo '<div class="lic-ir-summary">';
    if ( $api_url_count === 0 ) {
        echo 'No images in API for this product.';
        if ( ! empty( $site_atts ) ) {
            echo ' <em>(' . count( $site_atts ) . ' site image(s) — likely a placeholder.)</em>';
        }
        echo ' <strong class="lic-ir-green">No action needed.</strong>';
    } elseif ( $has_tracked ) {
        echo esc_html( $present_count ) . ' of ' . esc_html( $api_url_count ) . ' API images present on site.';
        if ( $missing_on_site > 0 ) echo ' <strong class="lic-ir-red">' . (int) $missing_on_site . ' missing from site.</strong>';
        if ( $extra_on_site > 0 )   echo ' <strong class="lic-ir-red">' . (int) $extra_on_site . ' extra (not in API).</strong>';
        if ( $missing_on_site === 0 && $extra_on_site === 0 && $api_item ) echo ' <strong class="lic-ir-green">All images match.</strong>';
    } else {
        $site_total = count( $site_atts );
        echo esc_html( $site_total ) . ' images on site, ' . esc_html( $api_url_count ) . ' in API.';
        if ( $site_total === $api_url_count ) {
            echo ' <strong class="lic-ir-green">Counts match.</strong>';
        } else {
            echo ' <strong class="lic-ir-red">Count mismatch.</strong>';
        }
        echo ' <em>(Images untracked — count-based comparison only.)</em>';
    }
    echo '</div>';

    // Sync button — only shown when there are API images to sync.
    if ( $api_item ) {
        echo '<div id="lic-ir-sync-wrap" style="margin:12px 0;">';
        printf(
            '<button id="lic-ir-sync-single" class="button button-primary" data-product-id="%d">Sync Images from API</button>',
            $product_id
        );
        echo ' <span id="lic-ir-sync-result" style="margin-left:10px;"></span>';
        echo '</div>';
    }

    echo '<div class="lic-ir-detail-grid">';

    // Left column: images on the site.
    echo '<div class="lic-ir-col">';
    echo '<h2>Images on Website (' . count( $site_atts ) . ')</h2>';
    if ( empty( $site_atts ) ) {
        echo '<p>No images found on this product.</p>';
    } else {
        echo '<div class="lic-ir-thumb-grid">';
        foreach ( $site_atts as $att ) {
            $img_url   = wp_get_attachment_image_url( $att['id'], 'thumbnail' );
            $untracked = $att['in_api'] === null;
            $extra     = $att['in_api'] === false;
            echo '<div class="lic-ir-thumb' . ( $extra ? ' lic-ir-extra' : '' ) . '">';
            if ( $img_url ) {
                echo '<img src="' . esc_url( $img_url ) . '" alt="" width="140" height="105">';
            } else {
                echo '<div class="lic-ir-no-img">No preview</div>';
            }
            if ( $att['type'] === 'featured' ) echo '<span class="lic-ir-badge">Featured Image</span>';
            if ( $untracked ) echo '<span class="lic-ir-badge">Untracked</span>';
            if ( $extra )     echo '<span class="lic-ir-badge lic-ir-badge-red">Extra (not in API)</span>';
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // Right column: images in the API cache.
    echo '<div class="lic-ir-col">';
    echo '<h2>Images in API (' . $api_url_count . ')</h2>';
    if ( $api_url_count === 0 ) {
        echo '<p>No images in the API cache for this product.</p>';
    } else {
        // Primary first, then remaining images sorted by UUID filename to match left column order.
        $api_primary  = null;
        $api_others   = [];
        foreach ( $api_images as $img ) {
            if ( ! empty( $img['PrimaryImage'] ) && $img['PrimaryImage'] === true ) {
                $api_primary = $img;
            } else {
                $api_others[] = $img;
            }
        }
        usort( $api_others, fn( $a, $b ) => strcmp( lic_img_uuid( $a['ImageUrl'] ?? '' ), lic_img_uuid( $b['ImageUrl'] ?? '' ) ) );
        $ordered_api = $api_primary ? array_merge( [ $api_primary ], $api_others ) : $api_others;

        echo '<div class="lic-ir-thumb-grid">';
        foreach ( $ordered_api as $img ) {
            $img_url    = $img['ImageUrl'] ?? '';
            if ( $img_url === '' ) continue;
            $img_uuid   = lic_img_uuid( $img_url );
            $is_primary = ! empty( $img['PrimaryImage'] ) && $img['PrimaryImage'] === true;
            $on_site    = $has_tracked && $img_uuid !== '' && isset( $site_uuid_set[ $img_uuid ] );
            $missing    = $has_tracked && $img_uuid !== '' && ! $on_site;
            echo '<div class="lic-ir-thumb' . ( $missing ? ' lic-ir-missing' : '' ) . '">';
            echo '<img src="' . esc_url( $img_url ) . '" alt="" width="140" height="105">';
            if ( $is_primary ) echo '<span class="lic-ir-badge lic-ir-badge-green">Primary Image</span>';
            if ( $missing )    echo '<span class="lic-ir-badge lic-ir-badge-red">Missing from site</span>';
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '</div>'; // .lic-ir-detail-grid
    if ( ! $in_logs_tab ) echo '</div>'; // .wrap
}
