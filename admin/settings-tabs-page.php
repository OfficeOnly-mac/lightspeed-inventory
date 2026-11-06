<?php
if ( ! defined( 'ABSPATH' ) ) die;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

$tabs = [
    'sync'          => 'Sync',
    'field-mapping' => 'Field Mapping',
    'locations'     => 'Locations',
    'unit-status'   => 'Unit Status',
    'image-email'   => 'Image Email',
];
$active = sanitize_key( $_GET['tab'] ?? 'sync' );
if ( ! array_key_exists( $active, $tabs ) ) $active = 'locations';
?>
<div class="wrap">
<?php lic_sync_disabled_notice(); ?>
<h1>Settings</h1>

<nav class="nav-tab-wrapper" style="margin-bottom:0;">
<?php foreach ( $tabs as $slug => $label ) :
    $url   = esc_url( add_query_arg( [ 'page' => 'lic-settings', 'tab' => $slug ], admin_url( 'admin.php' ) ) );
    $class = $slug === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
?>
    <a href="<?php echo $url; ?>" class="<?php echo $class; ?>"><?php echo esc_html( $label ); ?></a>
<?php endforeach; ?>
</nav>

<div class="tab-content" style="margin-top:20px;">
<?php
$lic_in_tab = true;
switch ( $active ) {
    case 'locations':
        include LIC_PLUGIN_DIR . 'admin/settings-page.php';
        break;
    case 'field-mapping':
        include LIC_PLUGIN_DIR . 'admin/wc-mapper-page.php';
        break;
    case 'unit-status':
        include LIC_PLUGIN_DIR . 'admin/unit-status-page.php';
        break;
    case 'sync':
        include LIC_PLUGIN_DIR . 'admin/cron-settings-page.php';
        break;
    case 'image-email':
        include LIC_PLUGIN_DIR . 'admin/image-email-settings-page.php';
        break;
}
?>
</div>

</div>
