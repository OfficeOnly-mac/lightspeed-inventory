<?php if ( empty( $lic_in_tab ) ) : ?><div class="wrap"><?php lic_sync_disabled_notice(); ?>
    <h1>Lightspeed Connector Settings</h1>
<?php endif; ?>
    <form method="post" class="setting_page" action="options.php">
        <?php
            do_settings_sections( 'lic-settings' );
        ?>
    </form>
    <div id="locations_container">

    </div>
<?php if ( empty( $lic_in_tab ) ) : ?></div><?php endif; ?>
