<?php
if ( ! defined( 'ABSPATH' ) ) die;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

$tabs = [
    'queue'         => 'Queue',
    'image-cleanup' => 'Image Cleanup',
];
$active = sanitize_key( $_GET['tab'] ?? 'queue' );
if ( ! array_key_exists( $active, $tabs ) ) $active = 'queue';
?>
<div class="wrap">
<?php lic_sync_disabled_notice(); ?>
<h1>Import</h1>

<nav class="nav-tab-wrapper" style="margin-bottom:0;">
<?php foreach ( $tabs as $slug => $label ) :
    $url   = esc_url( add_query_arg( [ 'page' => 'lic-import', 'tab' => $slug ], admin_url( 'admin.php' ) ) );
    $class = $slug === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
?>
    <a href="<?php echo $url; ?>" class="<?php echo $class; ?>"><?php echo esc_html( $label ); ?></a>
<?php endforeach; ?>
</nav>

<div class="tab-content" style="margin-top:20px;">
<?php
$lic_in_tab = true;
switch ( $active ) {
    case 'queue':
        include LIC_PLUGIN_DIR . 'admin/import-queue-page.php';
        break;
    case 'image-cleanup':
        include LIC_PLUGIN_DIR . 'admin/import-image-cleanup-page.php';
        break;
}
?>
</div>

</div>
