<?php

class LIC_Image_Handler {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'schedule_daily_report_cron' ) );
        add_action( 'generate_daily_image_report', array( __CLASS__, 'generate_and_send_report' ) );
    }

    // Schedules the daily image report cron if not already scheduled.
    public static function schedule_daily_report_cron() {
        if ( ! wp_next_scheduled( 'generate_daily_image_report' ) ) {
            $time_utc = get_option( 'lic_image_email_send_time', '15:00' );
            wp_schedule_event(
                LIC_Cron_Manager::next_warm_timestamp( $time_utc ),
                'daily',
                'generate_daily_image_report'
            );
        }
    }

    // Resize image to given max dimensions, compress, and save as JPEG; returns the
    // destination path on success or false if the image genuinely couldn't be processed.
    // Prefers Imagick when available — it handles CMYK/color-profile JPEGs correctly, which
    // GD does not (GD can silently produce a visually wrong/corrupt-looking result for a
    // CMYK-encoded JPEG without raising any error). Falls back to GD otherwise.
    // Missing the exact $max_filesize_kb target after hitting minimum quality is NOT treated
    // as failure — a still-small-but-slightly-over-target image is far better than none.
    public static function resize_and_save( $source_path, $destination_path, $max_width = 800, $max_height = 600, $max_filesize_kb = 180 ) {
        if ( class_exists( 'Imagick' ) ) {
            $result = self::resize_and_save_imagick( $source_path, $destination_path, $max_width, $max_height, $max_filesize_kb );
            if ( $result !== false ) {
                return $result;
            }
            // Fall through to GD if Imagick failed for some reason (e.g. an unusual codec).
        }
        return self::resize_and_save_gd( $source_path, $destination_path, $max_width, $max_height, $max_filesize_kb );
    }

    private static function resize_and_save_imagick( $source_path, $destination_path, $max_width, $max_height, $max_filesize_kb ) {
        // catch (\Throwable) rather than (\Exception): a zero-byte or truncated-on-disk source
        // file can make Imagick throw "negative or zero image size" from internal C library
        // calls (CloneImage) — observed in production not being caught by \Exception alone,
        // which let it escape resize_and_save() entirely and abort the *whole product's* sync
        // (image gallery, thumbnail, categories — everything after it in that cycle), not just
        // skip the one bad image as intended.
        try {
            $img = new Imagick( $source_path );
            if ( $img->getImageWidth() < 1 || $img->getImageHeight() < 1 ) {
                $img->clear();
                $img->destroy();
                return false;
            }
            $img->setImageFormat( 'jpeg' );
            if ( $img->getImageColorspace() === Imagick::COLORSPACE_CMYK ) {
                $img->transformImageColorspace( Imagick::COLORSPACE_SRGB );
            }
            $img->stripImage();
            $img->thumbnailImage( $max_width, $max_height, true );

            $quality     = 85;
            $min_quality = 40;
            do {
                $img->setImageCompressionQuality( $quality );
                $blob = $img->getImageBlob();
                $size = strlen( $blob );
                $quality -= 5;
            } while ( $size > $max_filesize_kb * 1024 && $quality >= $min_quality );

            $written = file_put_contents( $destination_path, $blob );
            $img->clear();
            $img->destroy();

            return $written !== false ? $destination_path : false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private static function resize_and_save_gd( $source_path, $destination_path, $max_width, $max_height, $max_filesize_kb ) {
        $info = @getimagesize( $source_path );
        if ( ! $info || $info['mime'] !== 'image/jpeg' ) return false;

        list( $orig_width, $orig_height ) = $info;
        if ( $orig_width <= 0 || $orig_height <= 0 ) return false;

        $ratio      = min( $max_width / $orig_width, $max_height / $orig_height, 1 );
        $new_width  = max( 1, (int) ( $orig_width * $ratio ) );
        $new_height = max( 1, (int) ( $orig_height * $ratio ) );

        $src_image = @imagecreatefromjpeg( $source_path );
        if ( ! $src_image ) return false;

        $dst_image = imagecreatetruecolor( $new_width, $new_height );
        imagecopyresampled( $dst_image, $src_image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height );

        $quality     = 85;
        $min_quality = 40;
        $image_data  = '';
        do {
            ob_start();
            imagejpeg( $dst_image, null, $quality );
            $image_data  = ob_get_clean();
            $filesize_kb = strlen( $image_data ) / 1024;
            $quality    -= 5;
        } while ( $filesize_kb > $max_filesize_kb && $quality >= $min_quality );

        imagedestroy( $src_image );
        imagedestroy( $dst_image );

        if ( empty( $image_data ) ) return false;

        $written = file_put_contents( $destination_path, $image_data );
        return $written !== false ? $destination_path : false;
    }

    // Downloads image bytes with completeness validation, retrying once on any failure —
    // large (multi-MB) Lightspeed photos occasionally fail transiently. Returns raw bytes
    // (guaranteed to be a complete, decodable image) or a WP_Error describing exactly what
    // went wrong, so callers can log a specific, actionable reason instead of a bare failure.
    private static function download_and_validate_image( $image_url ) {
        $last_error = null;
        for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
            $result = self::try_download_image( $image_url );
            if ( ! is_wp_error( $result ) ) {
                return $result;
            }
            $last_error = $result;
        }
        return $last_error;
    }

    private static function try_download_image( $image_url ) {
        $response = wp_remote_get( $image_url, [ 'timeout' => 60 ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) {
            return new WP_Error( 'image_http_' . $http_code, "Image URL returned HTTP {$http_code}: {$image_url}" );
        }
        $content = wp_remote_retrieve_body( $response );
        if ( empty( $content ) ) {
            return new WP_Error( 'image_empty_body', "Empty body from: {$image_url}" );
        }

        // A connection that drops mid-transfer can still produce a non-empty, plausible-
        // looking body — wp_remote_get() won't necessarily flag this as an error. Comparing
        // against Content-Length catches a truncated transfer before it ever reaches GD/Imagick.
        $expected_length = (int) wp_remote_retrieve_header( $response, 'content-length' );
        if ( $expected_length > 0 && strlen( $content ) !== $expected_length ) {
            return new WP_Error(
                'image_incomplete',
                'Downloaded ' . strlen( $content ) . " of {$expected_length} expected bytes (truncated transfer): {$image_url}"
            );
        }

        $info = @getimagesizefromstring( $content );
        if ( ! $info || $info['mime'] !== 'image/jpeg' ) {
            // Not JPEG (or undecodable) — try converting via Imagick (covers HEIC/PNG/WEBP/etc,
            // which Lightspeed's CDN doesn't always normalize to JPEG despite the extensionless URL).
            $converted = self::convert_to_jpeg( $content );
            if ( $converted === false ) {
                return new WP_Error( 'image_unsupported_format', "Could not decode or convert downloaded image (possibly HEIC or another unsupported format): {$image_url}" );
            }
            $content = $converted;
            $info    = @getimagesizefromstring( $content );
            if ( ! $info || $info['mime'] !== 'image/jpeg' ) {
                return new WP_Error( 'image_conversion_failed', "Converted image is still not a valid JPEG: {$image_url}" );
            }
        }

        // JPEG files always end with the End-Of-Image marker (0xFFD9). GD can silently decode
        // only the readable portion of a truncated JPEG without raising any error, producing a
        // "successfully" processed but visibly corrupt image — this catches that case even when
        // the Content-Length check above passes (e.g. no Content-Length header was sent at all).
        if ( substr( $content, -2 ) !== "\xFF\xD9" ) {
            return new WP_Error( 'image_truncated', "Downloaded JPEG is missing its end-of-image marker (truncated transfer): {$image_url}" );
        }

        return $content;
    }

    // Converts non-JPEG image bytes (HEIC, PNG, WEBP, etc.) to JPEG via Imagick. Returns JPEG
    // bytes on success, or false if conversion isn't possible (Imagick unavailable, or the
    // format genuinely can't be read — e.g. Imagick built without HEIC/libheif support).
    private static function convert_to_jpeg( $content ) {
        if ( ! class_exists( 'Imagick' ) ) {
            return false;
        }
        try {
            $img = new Imagick();
            $img->readImageBlob( $content );
            $img->setImageFormat( 'jpeg' );
            $jpeg_data = $img->getImageBlob();
            $img->clear();
            $img->destroy();
            return $jpeg_data;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    // Returns true if the given image URL is already present in the product's gallery.
    public static function image_in_gallery( $product_id, $image_url ) {
        $image_url_name = basename( parse_url( $image_url, PHP_URL_PATH ) );
        $gallery_ids    = get_post_meta( $product_id, '_product_image_gallery', true );

        if ( ! empty( $gallery_ids ) ) {
            foreach ( explode( ',', $gallery_ids ) as $image_id ) {
                $image_path = get_attached_file( $image_id );
                if ( $image_path ) {
                    $name_no_ext = pathinfo( basename( $image_path ), PATHINFO_FILENAME );
                    if ( strpos( $name_no_ext, $image_url_name ) !== false ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    // Returns true if this attachment is still referenced somewhere else on the site — as
    // another post/product's featured image or gallery, in Elementor's page data (every
    // image widget embeds the full attachment URL), or directly in post content — and is
    // therefore unsafe to delete. $exclude_post_id lets a caller ignore the one relationship
    // it's actively removing (e.g. the product whose gallery this image just dropped out of)
    // so that relationship alone doesn't count as "still in use". The site doesn't use
    // Gutenberg, so this deliberately doesn't look for block markup — every builder in use
    // here (Elementor included) ends up storing the literal file URL somewhere, so a plain
    // substring search on the image's UUID covers all of them without parsing any specific
    // builder's JSON or shortcode syntax.
    public static function is_attachment_in_use_elsewhere( $attachment_id, $exclude_post_id = null ) {
        global $wpdb;
        $attachment_id   = (int) $attachment_id;
        $exclude_post_id = $exclude_post_id ? (int) $exclude_post_id : 0;

        $thumb_sql  = "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                        WHERE pm.meta_key = '_thumbnail_id'
                          AND pm.meta_value = %s
                          AND p.post_status NOT IN ('trash','auto-draft')
                          AND pm.post_id != %d
                        LIMIT 1";
        if ( $wpdb->get_var( $wpdb->prepare( $thumb_sql, (string) $attachment_id, $exclude_post_id ) ) ) {
            return true;
        }

        $gallery_sql = "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_product_image_gallery'
                           AND pm.meta_value != ''
                           AND p.post_status NOT IN ('trash','auto-draft')
                           AND pm.post_id != %d";
        foreach ( $wpdb->get_results( $wpdb->prepare( $gallery_sql, $exclude_post_id ) ) as $row ) {
            if ( in_array( $attachment_id, array_map( 'intval', explode( ',', $row->meta_value ) ), true ) ) {
                return true;
            }
        }

        // UUID/filename substring search — catches Elementor page data and any raw <img>
        // markup elsewhere on the site. Restricted to meta_key = '_elementor_data': a
        // leading-wildcard LIKE can't use an index on meta_value regardless, so an
        // unrestricted scan means a full table scan of every row in wp_postmeta on every
        // call — cheap on a small site, but this runs inline in run_import()'s per-product
        // orphan cleanup on every sync, and on a catalog this size (600+ products, each
        // with dozens of meta rows) it was slow enough to blow past process_queue_batch()'s
        // 5-minute stuck-processing timeout during the trash-restore recovery, when many
        // products had changing galleries at once. _elementor_data is the one meta key
        // that actually embeds arbitrary image placements, so narrowing to just that key
        // keeps the protection while cutting the scanned row count by orders of magnitude.
        $needle = get_post_meta( $attachment_id, '_lic_source_url', true );
        if ( ! $needle ) {
            $file   = get_attached_file( $attachment_id );
            $needle = $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';
        }
        if ( ! $needle ) {
            return false;
        }
        $like = '%' . $wpdb->esc_like( $needle ) . '%';

        $meta_sql = "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = '_elementor_data'
                       AND pm.meta_value LIKE %s
                       AND pm.post_id NOT IN (%d, %d)
                       AND p.post_status NOT IN ('trash','auto-draft')
                     LIMIT 1";
        if ( $wpdb->get_var( $wpdb->prepare( $meta_sql, $like, $attachment_id, $exclude_post_id ) ) ) {
            return true;
        }

        $content_sql = "SELECT ID FROM {$wpdb->posts}
                         WHERE post_content LIKE %s
                           AND ID NOT IN (%d, %d)
                           AND post_status NOT IN ('trash','auto-draft')
                         LIMIT 1";
        return (bool) $wpdb->get_var( $wpdb->prepare( $content_sql, $like, $attachment_id, $exclude_post_id ) );
    }

    // Returns the attachment ID for the given image URL, downloading it only if not already in
    // the media library AND that attachment's file still actually exists on disk. Returns a
    // WP_Error (never a fake/placeholder attachment) if the image can't be downloaded,
    // validated, or resized — callers must check is_wp_error() and skip the image on failure
    // rather than proceeding with unprocessed or invalid data.
    public static function get_or_create_attachment( $image_url ) {
        $image_name      = basename( parse_url( $image_url, PHP_URL_PATH ) );
        $image_full_name = $image_name . '.jpg';

        $q = new WP_Query( [
            'post_type'      => 'attachment',
            'title'          => $image_full_name,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ] );
        $existing_id = ! empty( $q->posts ) ? (int) $q->posts[0] : 0;

        if ( $existing_id ) {
            $existing_file = get_attached_file( $existing_id );
            if ( $existing_file && file_exists( $existing_file ) ) {
                return $existing_id;
            }
            // A record exists but its file is gone — most likely a duplicate attachment from
            // the queue-claim race (see process_queue_batch()) whose deletion took the shared
            // file with it. Trusting the DB record here would mean a file lost this way could
            // never self-heal, since nothing else in the sync checks file existence for an
            // already-"found" attachment — fall through and re-populate it in place instead of
            // returning a reference to a file that doesn't exist.
        }

        $image_content = self::download_and_validate_image( $image_url );
        if ( is_wp_error( $image_content ) ) {
            return $image_content;
        }

        $upload_dir = wp_upload_dir();
        $file_path  = $upload_dir['path'] . '/' . $image_full_name;

        // Write to a unique temp path first, not directly to the final filename. Two sync
        // runs can overlap (e.g. a manual trigger firing while the scheduled queue processor
        // is still mid-batch — observed in production via repeated "stuck processing item
        // reset" log entries) and both attempt this same image at once; writing straight to
        // $file_path let one process's write interleave with another's, leaving a truncated/
        // zero-byte file behind — which is what an Imagick "negative or zero image size"
        // error downstream turned out to be. Only rename into place once the file is written
        // AND successfully resized, so a partial/failed attempt never touches the real path.
        $temp_path = $file_path . '.' . uniqid( '', true ) . '.tmp';
        $written   = file_put_contents( $temp_path, $image_content );
        if ( $written === false || $written !== strlen( $image_content ) ) {
            @unlink( $temp_path );
            return new WP_Error( 'image_write_failed', "Could not write downloaded image to disk: {$image_url}" );
        }

        if ( ! self::resize_and_save( $temp_path, $temp_path, 800, 600, 180 ) ) {
            @unlink( $temp_path );
            return new WP_Error( 'image_resize_failed', "Downloaded image could not be resized/processed: {$image_url}" );
        }

        if ( ! @rename( $temp_path, $file_path ) ) {
            @unlink( $temp_path );
            return new WP_Error( 'image_move_failed', "Could not finalize downloaded image: {$image_url}" );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        if ( $existing_id ) {
            // Repair in place — reuse the existing attachment post/ID instead of creating a
            // second duplicate for the same title. wp_upload_dir() always resolves to the
            // *current* month, which can differ from the month the attachment was originally
            // created in — without repointing _wp_attached_file, WordPress keeps resolving the
            // URL from the old (still-missing) path while this freshly written file sits
            // orphaned at the new one, so the site never actually recovers and every later
            // sync re-downloads and re-writes the same file again for nothing.
            update_attached_file( $existing_id, $file_path );
            wp_update_attachment_metadata( $existing_id, wp_generate_attachment_metadata( $existing_id, $file_path ) );
            update_post_meta( $existing_id, '_lic_source_url', $image_name );
            return $existing_id;
        }

        $attachment_id = wp_insert_attachment( [
            'guid'           => $upload_dir['url'] . '/' . $image_full_name,
            'post_mime_type' => 'image/jpeg',
            'post_title'     => $image_full_name,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $file_path );

        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file_path ) );
        update_post_meta( $attachment_id, '_lic_source_url', $image_name );

        return $attachment_id;
    }

    // Generates the daily image comparison email and CSV, using the same matching logic as the Image Report page.
    public static function generate_and_send_report() {
        if ( get_transient( 'generate_daily_image_report_lock' ) ) {
            return;
        }
        set_transient( 'generate_daily_image_report_lock', true, 5 * MINUTE_IN_SECONDS );

        try {
            self::do_generate_and_send_report();
        } catch ( Throwable $e ) {
            error_log( 'LIC image report error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }

        delete_transient( 'generate_daily_image_report_lock' );
    }

    // Inner implementation — separated so the lock/unlock wrapper can catch any fatal.
    private static function do_generate_and_send_report() {
        global $wpdb;

        // Load settings.
        $filter             = get_option( 'lic_image_email_filter', 'mismatches' );
        $min_api_count      = max( 0, (int) get_option( 'lic_image_email_min_api_count', 0 ) );
        $extra_fields_cfg   = json_decode( get_option( 'lic_image_email_extra_fields', '[]' ), true ) ?: [];

        // Build per-location API cache index.
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

        // Fetch all published products with a non-empty SKU.
        $product_ids = $wpdb->get_col(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
               AND pm.meta_value != ''
               AND p.post_type = 'product'
               AND p.post_status = 'publish'"
        );

        $all_rows        = [];
        $zero_image_rows = [];

        foreach ( $product_ids as $pid ) {
            $pid      = (int) $pid;
            $sku      = trim( (string) get_post_meta( $pid, '_sku', true ) );
            $location = trim( (string) get_post_meta( $pid, 'location', true ) );
            $api_item = $api_by_loc[ $location ][ $sku ] ?? null;

            // API image URLs for this product.
            $api_urls = [];
            $vin      = '';
            if ( $api_item ) {
                $vin = (string) ( $api_item['VIN'] ?? '' );
                foreach ( $api_item['Images'] ?? [] as $img ) {
                    $u = $img['ImageUrl'] ?? '';
                    if ( $u !== '' ) $api_urls[] = $u;
                }
            }

            // Site attachments: thumbnail + gallery, deduplicated.
            $thumbnail_id = (int) get_post_meta( $pid, '_thumbnail_id', true );
            $gallery_ids  = array_values( array_filter(
                array_map( 'intval', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) )
            ) );
            $att_ids = array_values( array_unique( array_filter(
                array_merge( $thumbnail_id ? [ $thumbnail_id ] : [], $gallery_ids )
            ) ) );

            // _lic_source_url values set by the plugin when downloading images.
            $site_urls = [];
            foreach ( $att_ids as $att_id ) {
                $src = get_post_meta( $att_id, '_lic_source_url', true );
                if ( $src ) $site_urls[] = $src;
            }

            // Match logic identical to the Image Report page.
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

            $row = [
                'pid'       => $pid,
                'sku'       => $sku,
                'vin'       => $vin,
                'location'  => $location,
                'api_count' => count( $api_urls ),
                'site_count'=> count( $att_ids ),
                'match'     => $match,
            ];

            $all_rows[] = $row;

            // Zero-image: product is in the cache but the API returned no image URLs.
            if ( $api_item !== null && count( $api_urls ) === 0 ) {
                $extra = [];
                foreach ( $extra_fields_cfg as $fc ) {
                    $key         = $fc['key'] ?? '';
                    $extra[ $key ] = $key !== '' ? self::get_product_field( $pid, [ $key ] ) : '';
                }
                $zero_image_rows[] = array_merge( $row, [ 'extra' => $extra ] );
            }
        }

        // Filter rows for the CSV per settings.
        $report_rows = array_values( array_filter( $all_rows, function ( $row ) use ( $filter, $min_api_count ) {
            if ( $row['api_count'] < $min_api_count ) return false;
            if ( $filter === 'mismatches' && $row['match'] === 'Y' ) return false;
            return true;
        } ) );

        // Write CSV.
        $upload_dir = wp_upload_dir();
        $csv_file   = $upload_dir['path'] . '/daily_image_report.csv';
        $fh         = fopen( $csv_file, 'w' );
        fputcsv( $fh, [ 'Stock Number', 'VIN', 'Location', '# Images in API', '# Images on Website', 'Image Match' ] );
        foreach ( $report_rows as $row ) {
            fputcsv( $fh, [ $row['sku'], $row['vin'], $row['location'], $row['api_count'], $row['site_count'], $row['match'] ] );
        }
        fclose( $fh );

        // Build and send email.
        $to          = self::get_email_recipients();
        $subject     = 'Daily Image Report — ' . wp_date( 'Y-m-d' );
        $headers     = [ 'Content-Type: text/html; charset=UTF-8' ];
        $attachments = count( $report_rows ) > 0 ? [ $csv_file ] : [];
        $body        = self::build_email_body( $zero_image_rows, $report_rows, $filter, $extra_fields_cfg );

        wp_mail( $to, $subject, $body, $headers, $attachments );

        if ( file_exists( $csv_file ) ) {
            unlink( $csv_file );
        }
    }

    // Returns the configured email recipients, falling back to the WP admin email.
    private static function get_email_recipients() {
        $saved = get_option( 'lic_image_email_recipients', '' );
        if ( $saved !== '' ) {
            return array_values( array_filter( array_map( 'trim', explode( ',', $saved ) ) ) );
        }
        return [ get_option( 'admin_email' ) ];
    }

    // Builds the HTML email body: private-image warning, zero-image table, report summary.
    private static function build_email_body( array $zero_image_rows, array $report_rows, $filter, array $extra_fields_cfg = [] ) {
        $date       = wp_date( 'F j, Y' );
        $zero_count = count( $zero_image_rows );
        $csv_count  = count( $report_rows );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#333;max-width:800px;margin:0 auto;padding:20px;">

<h2 style="margin-top:0;">Daily Image Report — <?php echo esc_html( $date ); ?></h2>

<?php if ( $zero_count > 0 ) : ?>
<div style="background:#fff8e5;border:1px solid #e6c200;border-left:4px solid #e6c200;border-radius:3px;padding:14px 16px;margin-bottom:20px;">
    <strong style="font-size:15px;">&#9888; Lightspeed Private Images Notice</strong><br>
    <span style="margin-top:6px;display:block;">
        If a product appears in the list below but you believe images have been added in Lightspeed,
        check that those images are not set to <strong>private</strong> in Lightspeed.
        Private images are excluded from the API entirely and will not be imported to the website,
        regardless of any other settings.
    </span>
</div>
<?php endif; ?>

<?php if ( $zero_count > 0 ) : ?>
<h3 style="margin-bottom:8px;">Products With No Images in API (<?php echo esc_html( $zero_count ); ?>)</h3>
<p style="margin-top:0;color:#666;font-size:13px;">These products are in the API cache but have zero images. Images need to be added in Lightspeed, or existing images may be set to private.</p>
<table style="border-collapse:collapse;width:100%;margin-bottom:24px;">
    <thead>
        <tr style="background:#f0f0f0;">
            <th style="border:1px solid #ccc;padding:8px 10px;text-align:left;">Stock Number</th>
            <th style="border:1px solid #ccc;padding:8px 10px;text-align:left;">VIN</th>
            <th style="border:1px solid #ccc;padding:8px 10px;text-align:left;">Location</th>
            <?php foreach ( $extra_fields_cfg as $fc ) : ?>
            <th style="border:1px solid #ccc;padding:8px 10px;text-align:left;"><?php echo esc_html( $fc['label'] ?? '' ); ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $zero_image_rows as $i => $row ) : ?>
        <tr style="background:<?php echo $i % 2 === 0 ? '#fff' : '#fafafa'; ?>;">
            <td style="border:1px solid #ddd;padding:7px 10px;"><?php echo esc_html( $row['sku'] ); ?></td>
            <td style="border:1px solid #ddd;padding:7px 10px;"><?php echo esc_html( $row['vin'] ); ?></td>
            <td style="border:1px solid #ddd;padding:7px 10px;"><?php echo esc_html( $row['location'] ); ?></td>
            <?php foreach ( $extra_fields_cfg as $fc ) : ?>
            <td style="border:1px solid #ddd;padding:7px 10px;"><?php echo esc_html( $row['extra'][ $fc['key'] ?? '' ] ?? '' ); ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else : ?>
<p style="color:#2a7a2a;"><strong>&#10003; All products in the API cache have at least one image.</strong></p>
<?php endif; ?>

<hr style="border:none;border-top:1px solid #ddd;margin:20px 0;">

<h3 style="margin-bottom:4px;">Attached CSV Report</h3>
<?php if ( $csv_count > 0 ) : ?>
<p style="margin-top:0;color:#555;">
    The attached CSV contains <strong><?php echo esc_html( $csv_count ); ?></strong>
    product<?php echo $csv_count !== 1 ? 's' : ''; ?>
    (filter: <em><?php echo $filter === 'mismatches' ? 'mismatches only' : 'all products'; ?></em>).
    Columns: Stock Number, VIN, Location, # Images in API, # Images on Website, Image Match.
</p>
<?php else : ?>
<p style="margin-top:0;color:#555;">No products matched the current filter — no CSV attached.</p>
<?php endif; ?>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    // Tries multiple meta keys and product attributes to get a field value.
    public static function get_product_field( $product_id, $possible_keys = array() ) {
        $product = wc_get_product( $product_id );
        foreach ( $possible_keys as $key ) {
            $val = get_post_meta( $product_id, $key, true );
            if ( ! empty( $val ) ) return sanitize_text_field( $val );
        }
        if ( $product ) {
            foreach ( $possible_keys as $key ) {
                $attr_name = trim( str_replace( array( '_', '-' ), ' ', str_replace( 'pa_', '', $key ) ) );
                $val       = $product->get_attribute( $attr_name );
                if ( ! empty( $val ) ) return sanitize_text_field( $val );
            }
        }
        return '';
    }
}
