<?php

class LIC_Archive_Query {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_featured_archive_rule' ) );
        // Must run after WooCommerce's WC_Query::product_query (priority 10), which
        // replaces posts_per_page with the catalog page size. If that overwrites our
        // -1, filter_featured_products only sees the current catalog page and slices
        // it again, which is what dropped the first featured trailers and emptied the
        // last page while the total count still looked right.
        add_action( 'pre_get_posts', array( __CLASS__, 'featured_archive_query' ), 999 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_price_filter_to_product_queries' ), 1000 );
        add_filter( 'posts_clauses', array( __CLASS__, 'order_product_queries' ), 20, 2 );
        add_action( 'woocommerce_product_query', array( __CLASS__, 'apply_price_sort_and_filter' ), 20 );
        add_action( 'elementor/query/trailer_loop', array( __CLASS__, 'apply_elementor_trailer_loop' ) );
        add_filter( 'shopengine/query/main_query_args', array( __CLASS__, 'apply_shopengine_query_args' ) );
    }

    public static function register_featured_archive_rule() {
        add_rewrite_tag( '%featured_archive%', '([0-9]+)' );
        add_rewrite_rule(
            '^trailer-inventory/featured/?$',
            'index.php?post_type=product&featured_archive=1',
            'top'
        );
        add_rewrite_rule(
            '^trailer-inventory/featured/page/([0-9]{1,})/?$',
            'index.php?post_type=product&featured_archive=1&paged=$matches[1]',
            'top'
        );
    }

    public static function featured_archive_query( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }
        $is_featured = intval( get_query_var( 'featured_archive', 0 ) );
        if ( $is_featured ) {
            $query->set( 'post_type', 'product' );
            $query->set( 'posts_per_page', -1 );
            $query->set( 'nct_fp_per_page', 10 );
            $query->is_archive           = true;
            $query->is_post_type_archive = true;
            $query->set( 'paged', get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1 );
            add_filter( 'the_posts', array( __CLASS__, 'filter_featured_products' ), 10, 2 );
        }
    }

    public static function filter_featured_products( $posts, $query ) {
        if ( is_admin() || ! $query->is_main_query() ) {
            return $posts;
        }
        $is_featured_flag = intval( $query->get( 'featured_archive' ) );
        if ( ! $is_featured_flag ) {
            $is_featured_flag = intval( get_query_var( 'featured_archive', 0 ) );
        }
        if ( ! $is_featured_flag || empty( $posts ) ) {
            return $posts;
        }

        $featured = array();
        foreach ( $posts as $post ) {
            if ( function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $post->ID );
                if ( $product && $product->is_featured() ) {
                    $featured[] = $post;
                }
            } else {
                if ( get_post_meta( $post->ID, '_featured', true ) === 'yes' ) {
                    $featured[] = $post;
                }
            }
        }

        $paged    = max( 1, intval( get_query_var( 'paged' ) ) );
        $per_page = intval( $query->get( 'nct_fp_per_page' ) );
        if ( ! $per_page || $per_page < 1 ) {
            $per_page = intval( get_query_var( 'posts_per_page' ) );
        }
        if ( ! $per_page || $per_page < 1 ) {
            $per_page = intval( get_option( 'posts_per_page' ) );
        }

        $query->set( 'posts_per_page', $per_page );
        $query->query_vars['posts_per_page'] = $per_page;

        $total                   = count( $featured );
        $query->found_posts      = $total;
        $query->set( 'found_posts', $total );
        $query->max_num_pages    = $per_page ? ceil( $total / $per_page ) : 1;
        $query->set( 'max_num_pages', $query->max_num_pages );

        remove_filter( 'the_posts', array( __CLASS__, 'filter_featured_products' ), 10 );

        if ( $per_page ) {
            $offset = ( $paged - 1 ) * $per_page;
            return array_slice( $featured, $offset, $per_page );
        }

        return $featured;
    }

    public static function order_product_queries( $clauses, $query ) {
        if ( is_admin() || ! $query instanceof WP_Query ) {
            return $clauses;
        }
        if ( ! self::is_product_archive_context() ) {
            return $clauses;
        }

        $post_type = $query->get( 'post_type' );
        $is_product_query = $post_type === 'product'
            || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) )
            || ( $query->is_main_query() && ( is_shop() || is_product_category() || is_product_tag() ) );
        if ( ! $is_product_query ) {
            return $clauses;
        }

        global $wpdb;
        $featured_sql = "EXISTS (
            SELECT 1 FROM {$wpdb->term_relationships} lic_tr
            INNER JOIN {$wpdb->term_taxonomy} lic_tt ON lic_tt.term_taxonomy_id = lic_tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} lic_t ON lic_t.term_id = lic_tt.term_id
            WHERE lic_tr.object_id = {$wpdb->posts}.ID
              AND lic_tt.taxonomy = 'product_visibility'
              AND lic_t.slug = 'featured'
        )";
        $price_sql = "COALESCE((
            SELECT MIN(CAST(lic_price.meta_value AS DECIMAL(10,2)))
            FROM {$wpdb->postmeta} lic_price
            WHERE lic_price.post_id = {$wpdb->posts}.ID
              AND lic_price.meta_key = '_price'
        ), 999999999)";

        $clauses['orderby'] = "{$featured_sql} DESC, {$price_sql} ASC, {$wpdb->posts}.ID ASC";

        $bounds = self::get_price_filter_bounds();
        if ( $bounds ) {
            list( $min, $max ) = $bounds;
            $price_where = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} lic_filter_price
                WHERE lic_filter_price.post_id = {$wpdb->posts}.ID
                  AND lic_filter_price.meta_key = '_price'";
            if ( $min !== null && $max !== null ) {
                $price_where .= $wpdb->prepare(
                    ' AND CAST(lic_filter_price.meta_value AS DECIMAL(10,2)) BETWEEN %f AND %f',
                    $min,
                    $max
                );
            } elseif ( $min !== null ) {
                $price_where .= $wpdb->prepare(
                    ' AND CAST(lic_filter_price.meta_value AS DECIMAL(10,2)) >= %f',
                    $min
                );
            } else {
                $price_where .= $wpdb->prepare(
                    ' AND CAST(lic_filter_price.meta_value AS DECIMAL(10,2)) <= %f',
                    $max
                );
            }
            $price_where .= ')';
            $clauses['where'] .= ' AND ' . $price_where;
        }

        return $clauses;
    }

    private static function is_product_archive_context() {
        return is_shop() || is_product_category() || is_product_tag() || is_post_type_archive( 'product' );
    }

    public static function apply_price_sort_and_filter( $q ) {
        if ( is_admin() ) return;
        $q->set( 'orderby', 'meta_value_num' );
        $q->set( 'order', 'ASC' );
        $q->set( 'meta_key', '_price' );

        $price_query = self::get_price_filter_meta_query();
        if ( ! $price_query ) return;

        $q->set( 'meta_query', self::set_price_meta_query( $q->get( 'meta_query' ), $price_query ) );
    }

    public static function apply_price_filter_to_product_queries( $query ) {
        if ( is_admin() || ! $query instanceof WP_Query ) return;

        $price_query = self::get_price_filter_meta_query();
        if ( ! $price_query ) return;

        $post_type        = $query->get( 'post_type' );
        $is_product_query = false;
        if ( $query->is_main_query() ) {
            if ( $query->is_post_type_archive( 'product' ) || is_shop() || is_product_category() || is_product_tag() ) {
                $is_product_query = true;
            }
        }
        if ( ! $is_product_query ) {
            if ( $post_type === 'product' ) {
                $is_product_query = true;
            } elseif ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) {
                $is_product_query = true;
            }
        }
        if ( ! $is_product_query ) return;

        $meta_query = self::set_price_meta_query( $query->get( 'meta_query' ), $price_query );
        $query->set( 'meta_query', $meta_query );
    }

    private static function set_price_meta_query( $meta_query, $price_query ) {
        $meta_query = is_array( $meta_query ) ? $meta_query : array();
        foreach ( $meta_query as $index => $clause ) {
            if ( is_array( $clause ) && isset( $clause['key'] ) && $clause['key'] === '_price' ) {
                $meta_query[ $index ] = $price_query;
                return $meta_query;
            }
        }
        $meta_query[] = $price_query;
        return $meta_query;
    }

    public static function apply_elementor_trailer_loop( $q ) {
        $q->set( 'orderby', 'meta_value_num' );
        $q->set( 'order', 'ASC' );
        $q->set( 'meta_key', '_price' );

        $price_query = self::get_price_filter_meta_query();
        if ( $price_query ) {
            $q->set( 'meta_query', self::set_price_meta_query( $q->get( 'meta_query' ), $price_query ) );
        }

        add_filter( 'the_posts', function( $posts ) {
            $products = array();
            foreach ( $posts as $post ) {
                $product = wc_get_product( $post->ID );
                if ( $product ) {
                    $products[] = $product;
                }
            }
            usort( $products, function( $a, $b ) {
                if ( $a->is_featured() && ! $b->is_featured() ) return -1;
                if ( ! $a->is_featured() && $b->is_featured() ) return 1;
                $diff = floatval( $a->get_price() ) - floatval( $b->get_price() );
                return $diff ? ( $diff < 0 ? -1 : 1 ) : $a->get_id() - $b->get_id();
            } );
            $sorted = array();
            foreach ( $products as $product ) {
                $sorted[] = get_post( $product->get_id() );
            }
            return $sorted;
        }, 20 );
    }

    public static function apply_shopengine_query_args( $args ) {
        $args['orderby']  = 'meta_value_num';
        $args['meta_key'] = '_price';
        $args['order']    = 'ASC';

        $price_query = self::get_price_filter_meta_query();
        if ( $price_query ) {
            $args['meta_query'] = self::set_price_meta_query( $args['meta_query'] ?? array(), $price_query );
        }

        if ( ! isset( $args['tax_query'] ) ) {
            $args['tax_query'] = array();
        }
        $args['tax_query'][] = array(
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => array( 'featured' ),
            'operator' => 'IN',
        );

        return $args;
    }

    public static function get_price_filter_meta_query() {
        $bounds = self::get_price_filter_bounds();
        if ( ! $bounds ) {
            return null;
        }
        list( $min, $max ) = $bounds;

        $price_query = array(
            'key'  => '_price',
            'type' => 'NUMERIC',
        );
        if ( $min !== null && $max !== null ) {
            $price_query['value']   = array( $min, $max );
            $price_query['compare'] = 'BETWEEN';
        } elseif ( $min !== null ) {
            $price_query['value']   = $min;
            $price_query['compare'] = '>=';
        } else {
            $price_query['value']   = $max;
            $price_query['compare'] = '<=';
        }

        return $price_query;
    }

    private static function get_price_filter_bounds() {
        $min = self::parse_price_param( 'min_price' );
        $max = self::parse_price_param( 'max_price' );

        if ( $min === null && $max === null ) {
            return null;
        }
        if ( $min !== null && $max !== null && $min > $max ) {
            list( $min, $max ) = array( $max, $min );
        }
        return array( $min, $max );
    }

    private static function parse_price_param( $key ) {
        if ( ! isset( $_GET[ $key ] ) ) {
            return null;
        }
        $raw = wp_unslash( $_GET[ $key ] );
        if ( is_array( $raw ) ) {
            return null;
        }
        $clean = preg_replace( '/[^0-9.\-]/', '', (string) $raw );
        return is_numeric( $clean ) ? (float) $clean : null;
    }
}
