<?php

class LIC_Price_Display {

    public static function init() {
        add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'call_for_price_message' ), 10, 2 );
        add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'cash_price_message' ), 10, 2 );
    }

    public static function call_for_price_message( $price_html, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $price_html;
        }
        if ( $product->is_type( 'variable' ) ) {
            $prices = $product->get_variation_prices( true );
            if ( empty( $prices['price'] ) || ( count( $prices['price'] ) === 1 && reset( $prices['price'] ) == 0 ) ) {
                return '<span class="call-for-price">Call for price</span>';
            }
        } elseif ( $product->get_price() == 0 || $product->get_price() === '' ) {
            return '<span class="call-for-price">Call for price</span>';
        }
        return $price_html;
    }

    public static function cash_price_message( $price_html, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $price_html;
        }
        $regular_price = $product->get_regular_price();
        $sale_price    = $product->get_sale_price();

        if ( $regular_price && ! $sale_price ) {
            return sprintf(
                '<span class="cash-price">Cash Price: <span class="cash_offer"> %s </span></span>',
                wc_price( wc_get_price_to_display( $product, array( 'price' => $regular_price ) ) )
            );
        } elseif ( ! $regular_price && ! $sale_price ) {
            return '<span class="call-for-price">Call for price</span>';
        } elseif ( $sale_price ) {
            $regular_price_display = wc_get_price_to_display( $product, array( 'price' => $regular_price ) );
            $sale_price_display    = wc_get_price_to_display( $product, array( 'price' => $sale_price ) );

            if ( $regular_price_display > 0 ) {
                $discount_percentage = round( ( ( $regular_price_display - $sale_price_display ) / $regular_price_display ) * 100 );
                return sprintf(
                    '<span class="cash-price">Cash Price: <span class="cash_offer"> %s <span class="price_off">[%d%% OFF]</span></span></span>',
                    wc_price( $sale_price_display ),
                    $discount_percentage
                );
            } else {
                return sprintf(
                    '<span class="cash-price">Cash Price: <span class="cash_offer"> %s </span></span>',
                    wc_price( $sale_price_display )
                );
            }
        }

        return $price_html;
    }
}
