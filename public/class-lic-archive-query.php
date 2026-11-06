<?php

class LIC_Archive_Query {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_featured_archive_rule' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'featured_archive_query' ), 1 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_price_filter_to_product_queries' ), 999 );
        add_filter( 'woocommerce_product_loop_start', array( __CLASS__, 'sort_loop_featured_first' ) );
        add_action( 'woocommerce_product_query', array( __CLASS__, 'apply_price_sort_and_filter' ), 20 );
        add_action( 'elementor/query/trailer_loop', array( __CLASS__, 'apply_elementor_trailer_loop' ) );
        add_filter( 'shopengine/query/main_query_args', array( __CLASS__, 'apply_shopengine_query_args' ) );
    }

    // Register a virtual "featured archive" endpoint at /trailer-inventory/featured/
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

    // Convert the main query into a product archive showing only featured products
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

    // Keep only WC featured products; handles pagination and updates found_posts/max_num_pages
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

    // Sort product category loops: featured first, then price ASC
    public static function sort_loop_featured_first( $html ) {
        global $wp_query;
        if ( ! is_product_category() ) {
            return $html;
        }
        $products = array_map( function( $post ) {
            return wc_get_product( $post->ID );
        }, $wp_query->posts );

        usort( $products, function( $a, $b ) {
            if ( $a->is_featured() && ! $b->is_featured() ) return -1;
            if ( ! $a->is_featured() && $b->is_featured() ) return -1;
            return floatval( $a->get_price() ) - floatval( $b->get_price() );
        } );

        $wp_query->posts = array_map( function( $product ) {
            return get_post( $product->get_id() );
        }, $products );

        return $html;
    }

    // Force price ASC sort and apply min/max price filter on WooCommerce product queries
    public static function apply_price_sort_and_filter( $q ) {
        if ( is_admin() ) return;
        $q->set( 'orderby', 'meta_value_num' );
        $q->set( 'order', 'ASC' );
        $q->set( 'meta_key', '_price' );

        $price_query = self::get_price_filter_meta_query();
        if ( ! $price_query ) return;

        $meta_query      = $q->get( 'meta_query' );
        $meta_query      = is_array( $meta_query ) ? $meta_query : array();
        $has_price_query = false;
        foreach ( $meta_query as $mq ) {
            if ( is_array( $mq ) && isset( $mq['key'] ) && $mq['key'] === '_price' ) {
                $has_price_query = true;
                break;
            }
        }
        if ( ! $has_price_query ) {
            $meta_query[] = $price_query;
            $q->set( 'meta_query', $meta_query );
        }
    }

    // Apply min_price/max_price to any front-end product query (catches Elementor/ShopEngine loops)
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

        $meta_query = $query->get( 'meta_query' );
        $meta_query = is_array( $meta_query ) ? $meta_query : array();

        $has_price_query = false;
        foreach ( $meta_query as $index => $mq ) {
            if ( is_array( $mq ) && isset( $mq['key'] ) && $mq['key'] === '_price' ) {
                $meta_query[ $index ] = $price_query;
                $has_price_query      = true;
                break;
            }
        }
        if ( ! $has_price_query ) {
            $meta_query[] = $price_query;
        }
        $query->set( 'meta_query', $meta_query );
    }

    // Apply price ASC sort, price filter, and featured-first sort to Elementor trailer_loop queries
    public static function apply_elementor_trailer_loop( $q ) {
        $q->set( 'orderby', 'meta_value_num' );
        $q->set( 'order', 'ASC' );
        $q->set( 'meta_key', '_price' );

        $price_query = self::get_price_filter_meta_query();
        if ( $price_query ) {
            $meta_query      = $q->get( 'meta_query' );
            $meta_query      = is_array( $meta_query ) ? $meta_query : array();
            $has_price_query = false;
            foreach ( $meta_query as $mq ) {
                if ( is_array( $mq ) && isset( $mq['key'] ) && $mq['key'] === '_price' ) {
                    $has_price_query = true;
                    break;
                }
            }
            if ( ! $has_price_query ) {
                $meta_query[] = $price_query;
                $q->set( 'meta_query', $meta_query );
            }
        }

        add_filter( 'the_posts', function( $posts ) {
            $products = array();
            foreach ( $posts as $post ) {
                $products[] = wc_get_product( $post->ID );
            }
            usort( $products, function( $a, $b ) {
                if ( $a->is_featured() && ! $b->is_featured() ) return -1;
                if ( ! $a->is_featured() && $b->is_featured() ) return 1;
                return floatval( $a->get_price() ) - floatval( $b->get_price() );
            } );
            $sorted = array();
            foreach ( $products as $product ) {
                $sorted[] = get_post( $product->get_id() );
            }
            return $sorted;
        }, 20 );
    }

    // Apply price ASC sort, price filter, and featured-first to ShopEngine main query args
    public static function apply_shopengine_query_args( $args ) {
        $args['orderby']  = 'meta_value_num';
        $args['meta_key'] = '_price';
        $args['order']    = 'ASC';

        $price_query = self::get_price_filter_meta_query();
        if ( $price_query ) {
            $meta_query      = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
            $has_price_query = false;
            foreach ( $meta_query as $mq ) {
                if ( is_array( $mq ) && isset( $mq['key'] ) && $mq['key'] === '_price' ) {
                    $has_price_query = true;
                    break;
                }
            }
            if ( ! $has_price_query ) {
                $meta_query[]       = $price_query;
                $args['meta_query'] = $meta_query;
            }
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

    // Build a _price meta_query from min_price/max_price query string params; returns null if no filter
    public static function get_price_filter_meta_query() {
        $min_raw = isset( $_GET['min_price'] ) ? wp_unslash( $_GET['min_price'] ) : null;
        $max_raw = isset( $_GET['max_price'] ) ? wp_unslash( $_GET['max_price'] ) : null;

        $min = is_numeric( $min_raw ) ? (float) $min_raw : null;
        $max = is_numeric( $max_raw ) ? (float) $max_raw : null;

        if ( $min === null && $max === null ) {
            return null;
        }
        if ( $min !== null && $max !== null && $min > $max ) {
            list( $min, $max ) = array( $max, $min );
        }

        $price_query = array(
            'key'  => '_price',
            'type' => 'CHAR',
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
}
