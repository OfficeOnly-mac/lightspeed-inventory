<?php

class Lightspeed_Settings {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        // admin_post_force_sync registration removed: handle_force_sync() was an empty,
        // unguarded stub with no UI calling it — a CSRF-exploitable endpoint waiting to happen
        // if ever implemented without a nonce/capability check. Re-add with guards if this
        // feature is built.
    }

    public static function register_settings() {
        // Main settings
        register_setting('lic_settings_group', 'lic_settings', array(
            'sanitize_callback' => array(__CLASS__, 'sanitize_settings')
        ));
    
        // Add section for locations
        add_settings_section('lic_locations_section', 'Locations', null, 'lic-settings');
    
        // Add a single settings field to render all locations
        add_settings_field(
            'lic_locations',
            '<p class="hide_data">Locations</p>',
            array(__CLASS__, 'locations_field_callback'),
            'lic-settings',
            'lic_locations_section'
        );
    }
        
    public static function add_location_button_callback() {
        echo '<button type="button" id="add_location_button">Add Location</button>';
    }

    public static function locations_field_callback() {
        $locations = get_option('lic_settings', array('locations' => array()));
        ?>
        <button type="button" id="add_location_button">Add Location</button>
        <div id="locations_container">
            <?php
            if (!empty($locations['locations']) && is_array($locations['locations'])) {
                foreach ($locations['locations'] as $index => $location) {
                    self::render_location_row($location, $index);
                }
            }
            ?>
        </div>
        
        <?php
    }
    
    public static function render_location_row($location, $index) {
        $creds = lic_get_credentials( $location['name'] ?? '' );
        ?>
        <div class="location-row">
            <h3 class="location_name">Location <?php echo ($index + 1); ?></h3>
            <input type="hidden" name="lic_settings[locations][<?php echo $index; ?>][id]" value="<?php echo esc_attr($location['id'] ?? $index); ?>" />

            <input type="text" name="lic_settings[locations][<?php echo $index; ?>][name]" required value="<?php echo esc_attr($location['name'] ?? ''); ?>" placeholder="Location Name" /><br />
            <input type="text" name="lic_settings[locations][<?php echo $index; ?>][api_url]" required value="<?php echo esc_attr($location['api_url'] ?? ''); ?>" placeholder="API URL" /><br />

            <input type="hidden" name="lic_settings[locations][<?php echo $index; ?>][use_sandbox]" value="0" />
            <input type="checkbox" name="lic_settings[locations][<?php echo $index; ?>][use_sandbox]" class="use-sandbox-checkbox" value="1" <?php checked(isset($location['use_sandbox']) && $location['use_sandbox']); ?> /> Use Sandbox Details<br />

            <div class="sandbox-details" <?php if (!isset($location['use_sandbox']) || !$location['use_sandbox']) echo 'style="display:none"'; ?>>
                <input type="text" class="sandbox_username" name="lic_settings[locations][<?php echo $index; ?>][api_username_sandbox]" value="<?php echo esc_attr($creds['api_username_sandbox'] ?? ''); ?>" placeholder="Sandbox API Username" /><br />
                <input type="password" class="sandbox_password" data-has-saved="<?php echo empty( $creds['api_password_sandbox'] ) ? '0' : '1'; ?>" name="lic_settings[locations][<?php echo $index; ?>][api_password_sandbox]" value="" placeholder="<?php echo empty( $creds['api_password_sandbox'] ) ? 'Sandbox API Password' : '(saved — leave blank to keep)'; ?>" /><br />
            </div>

            <div class="live-details" <?php if (isset($location['use_sandbox']) && $location['use_sandbox']) echo 'style="display:none"'; ?>>
                <input type="text" class="live_username" required name="lic_settings[locations][<?php echo $index; ?>][api_username_live]" value="<?php echo esc_attr($creds['api_username_live'] ?? ''); ?>" placeholder="Live API Username" /><br />
                <input type="password" class="live_password" data-has-saved="<?php echo empty( $creds['api_password_live'] ) ? '0' : '1'; ?>" name="lic_settings[locations][<?php echo $index; ?>][api_password_live]" value="" placeholder="<?php echo empty( $creds['api_password_live'] ) ? 'Live API Password' : '(saved — leave blank to keep)'; ?>" /><br />
            </div>
    
            <button type="button" class="saved_delete_location_button">Delete</button>
            <button type="button" class="save_location_with_cred_button">Save</button>
        </div>
        <?php
    }
    
    public static function sanitize_settings( $settings ) {
        $sanitized_settings = array();

        if ( isset( $settings['locations'] ) && is_array( $settings['locations'] ) ) {
            foreach ( $settings['locations'] as $location ) {
                $sanitized_settings['locations'][] = array(
                    'name'        => sanitize_text_field( $location['name'] ?? '' ),
                    'api_url'     => sanitize_text_field( $location['api_url'] ?? '' ),
                    'use_sandbox' => ! empty( $location['use_sandbox'] ) ? '1' : '0',
                );
            }
        }

        return $sanitized_settings;
    }
}
?>
