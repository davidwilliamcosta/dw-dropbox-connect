<?php
/**
 * Remove opções, transientes e o cache de miniaturas.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'dwdc_settings' );
delete_option( 'dwdc_oauth' );
delete_option( 'dwdc_migrated_wpdm' );
delete_option( 'wpdm_settings' );
delete_option( 'wpdm_oauth' );

global $wpdb;

$like_transient = $wpdb->esc_like( '_transient_dwdc_' ) . '%';
$like_timeout   = $wpdb->esc_like( '_transient_timeout_dwdc_' ) . '%';
$like_old       = $wpdb->esc_like( '_transient_wpdm_' ) . '%';
$like_old_time  = $wpdb->esc_like( '_transient_timeout_wpdm_' ) . '%';

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $like_transient,
        $like_timeout,
        $like_old,
        $like_old_time
    )
);

$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'dw-dropbox-connect-cache';

if ( is_dir( $dir ) ) {
    $items = scandir( $dir );
    if ( is_array( $items ) ) {
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            if ( ! preg_match( '/^(index\.php|\.htaccess|[a-f0-9]{64}\.jpg)$/', $item ) ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_file( $path ) ) {
                unlink( $path );
            }
        }
    }
    rmdir( $dir );
}
