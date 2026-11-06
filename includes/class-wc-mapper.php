<?php
class LIC_WC_Mapper {

    // Field Mapping tab is rendered via admin/settings-tabs-page.php, which includes
    // admin/wc-mapper-page.php directly — this class has no hooks of its own to register.

    public static function mapper_field_sku_render() {
        $wc_fields  = self::get_woocommerce_fields();
        $acf_fields = self::get_custom_fields();

        // WooCommerce fields — one row per field keeps label and dropdown on the same line.
        echo '<div class="fields-group">';
        echo '<div class="fields-group-header">';
        echo '<span>WooCommerce Field</span>';
        echo '<span>Lightspeed Field</span>';
        echo '</div>';

        foreach ( $wc_fields as $field_key => $field_label ) {
            echo '<div class="field-row">';
            echo '<label class="wc-label" name="api_fields][' . esc_attr( $field_key ) . ']">' . esc_html( $field_label ) . '</label>';

            if ( $field_key === 'post_title' ) {
                echo '<div class="url-structure">';
                echo '<input type="text" class="custom-title-select api-select api_fields_container" name="custom-title][' . esc_attr( $field_key ) . ']" id="permalink-input" placeholder="%%fieldName%%" />';
                echo '<div class="available-tags" id="spanContainer"></div>';
                echo '</div>';
            } else {
                echo '<select class="api-select api_fields_container" name="api_fields][' . esc_attr( $field_key ) . ']">';
                echo '<option value="">- Select -</option>';
                echo '</select>';
            }

            echo '</div>';
        }

        echo '</div>'; // .fields-group

        // ACF fields — same single-row-per-field approach.
        echo '<div class="acf-fields-group">';
        echo '<div class="fields-group-header">';
        echo '<span>ACF Custom Field</span>';
        echo '<span>Lightspeed Field</span>';
        echo '</div>';

        foreach ( $acf_fields as $field ) {
            if ( $field['label'] === 'Location' ) continue;

            echo '<div class="field-row">';
            echo '<label class="acf-label" data-field_name="' . esc_attr( $field['label'] ) . '" data-field_group_key="' . esc_attr( $field['key'] ) . '" name="api_fields][' . esc_attr( $field['label'] ) . ']">' . esc_html( $field['label'] ) . '</label>';
            echo '<select class="acf-api-select api_fields_container" data-field_name="' . esc_attr( $field['label'] ) . '" data-field_group_key="' . esc_attr( $field['key'] ) . '" name="api_fields][' . esc_attr( $field['label'] ) . ']">';
            echo '<option value="">- Select -</option>';
            echo '</select>';
            echo '</div>';
        }

        echo '</div>'; // .acf-fields-group
    }
    
    public static function get_woocommerce_fields() {
        $fields = array(
            'post_title' => __('Product Title', 'woocommerce'),
            'post_content' => __('Product Description', 'woocommerce'),
            'post_excerpt' => __('Product Short Description', 'woocommerce'),
            'product_cat' => __('Product Category', 'woocommerce'),
            '_regular_price' => __('Regular Price', 'woocommerce'),
            '_sale_price' => __('Sale Price', 'woocommerce'),
            '_sku' => __('SKU', 'woocommerce'),
            '_stock' => __('Stock Quantity', 'woocommerce'),
            '_weight' => __('Weight', 'woocommerce'),
            '_length' => __('Length', 'woocommerce'),
            '_width' => __('Width', 'woocommerce'),
            '_height' => __('Height', 'woocommerce'),
            '_sale_price_dates_from' => __('Sale Price Dates From', 'woocommerce'),
            '_sale_price_dates_to' => __('Sale Price Dates To', 'woocommerce'),
            'featured_image' => __('Featured Image', 'woocommerce'),
            '_product_image_gallery' => __('Gallery Image', 'woocommerce'),
            '_price' => __('Price', 'woocommerce'),
            '_product_condition_field' => __('Condition', 'woocommerce'),
            'make' => __('Make', 'woocommerce'),
        );

        // Append dynamic product attributes (if any exist in WooCommerce)
        if (function_exists('wc_get_attribute_taxonomies')) {
            $attribute_taxonomies = wc_get_attribute_taxonomies();
            if (!empty($attribute_taxonomies)) {
                $skip_attr_slugs = array('length', 'location', 'condition', 'make');
                foreach ($attribute_taxonomies as $attribute_taxonomy) {
                    $attr_slug = $attribute_taxonomy->attribute_name;
                    if (in_array($attr_slug, $skip_attr_slugs, true)) {
                        continue;
                    }
                    $attr_label = $attribute_taxonomy->attribute_label ?: $attr_slug;
                    $fields['attr_' . $attr_slug] = sprintf(__('%s (Attribute)', 'woocommerce'), $attr_label);
                }
            }
        }
    
        return $fields;
    }

    public static function get_acf_fields(){
        $custom_fields = self::get_custom_fields();
        
        return $custom_fields;
    }
    
    public static function get_custom_fields() {
        if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return [];
        }

        $field_groups  = acf_get_field_groups( [ 'post_type' => 'product' ] );
        $custom_fields = [];

        foreach ( $field_groups as $field_group ) {
            $fields = acf_get_fields( $field_group['key'] );
            if ( empty( $fields ) ) continue;
            foreach ( $fields as $field ) {
                $custom_fields[] = [
                    'label' => $field['label'],
                    'key'   => $field_group['key'],
                ];
            }
        }

        return $custom_fields;
    }
    
    public static function mapper_fields_callback() {
        $settings = get_option('lic_settings');
        $locations = $settings['locations'] ?? array();
        
        ?>
        <select name="selected_location" id="selected_location">
            <option value="null">Select Location</option>
            <?php foreach ($locations as $location) : ?>
                <option value="<?php echo esc_attr($location['name']); ?>"><?php echo esc_html($location['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <br><br>
        <?php
    }
}
