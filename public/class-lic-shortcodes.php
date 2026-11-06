<?php

class LIC_Shortcodes {

    public static function init() {
        add_shortcode( 'monthly_financing', array( __CLASS__, 'monthly_financing' ) );
    }

    // Outputs a monthly financing estimate based on the current product's regular price
    public static function monthly_financing( $atts ) {
        global $product;
        if ( ! $product instanceof WC_Product ) return '';

        $price    = $product->get_regular_price();
        $apr      = 8.99;
        $months   = 60;

        $monthly_rate    = $apr / 100 / 12;
        $monthly_payment = $price * ( $monthly_rate * pow( 1 + $monthly_rate, $months ) ) / ( pow( 1 + $monthly_rate, $months ) - 1 );

        return '<p>As low as <strong>$' . number_format( $monthly_payment, 2 ) . '</strong> a month</p>' .
               '<p style="font-size: smaller; font-style: italic;">*' . $apr . '% APR for ' . $months . ' months with approved credit</p>';
    }
}
