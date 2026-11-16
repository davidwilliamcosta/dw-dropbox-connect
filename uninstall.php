<?php
/**
 * Remove opções, transientes, tarefas agendadas e os caches de miniaturas e de galerias.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Apaga o cache das galerias: uma pasta por galeria, só com JPEGs de nome em hash e arquivos de proteção.
 */
function dwdc_uninstall_gallery_cache() {
    $uploads = wp_upload_dir();
    $root    = trailingslashit( $uploads['basedir'] ) . 'dw-dropbox-connect-gallery';
    if ( ! is_dir( $root ) ) {
        return;
    }
    $folders = scandir( $root );
    if ( is_array( $folders ) ) {
        foreach ( $folders as $folder ) {
            $path = $root . '/' . $folder;
            if ( ! preg_match( '/^[a-f0-9]{8}$/', $folder ) || ! is_dir( $path ) ) {
                continue;
            }
            $files = scandir( $path );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    if ( preg_match( '/^([a-f0-9]{64}\.jpg|index\.php)$/', $file ) && is_file( $path . '/' . $file ) ) {
                        wp_delete_file( $path . '/' . $file );
                    }
                }
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty directory created by this plugin.
            @rmdir( $path );
        }
    }
    foreach ( array( 'index.php', '.htaccess' ) as $file ) {
        if ( is_file( $root . '/' . $file ) ) {
            wp_delete_file( $root . '/' . $file );
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty directory created by this plugin.
    @rmdir( $root );
}

/**
 * Apaga os dados deste plugin.
 */
function dwdc_uninstall() {
    wp_clear_scheduled_hook( 'dwdc_refresh_feeds' );
    wp_clear_scheduled_hook( 'dwdc_refresh_feed' );
    wp_clear_scheduled_hook( 'dwdc_prime_feed' );
    dwdc_uninstall_gallery_cache();

    delete_option( 'dwdc_settings' );
    delete_option( 'dwdc_feeds' );
    delete_option( 'dwdc_oauth' );
    delete_option( 'dwdc_migrated_wpdm' );
    delete_option( 'wpdm_settings' );
    delete_option( 'wpdm_oauth' );

    global $wpdb;

    $like_transient = $wpdb->esc_like( '_transient_dwdc_' ) . '%';
    $like_timeout   = $wpdb->esc_like( '_transient_timeout_dwdc_' ) . '%';
    $like_old       = $wpdb->esc_like( '_transient_wpdm_' ) . '%';
    $like_old_time  = $wpdb->esc_like( '_transient_timeout_wpdm_' ) . '%';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes this plugin's transients by prefix.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $like_transient,
            $like_timeout,
            $like_old,
            $like_old_time
        )
    );

    $like_feed = $wpdb->esc_like( 'dwdc_feed_' ) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes the stored gallery snapshots by prefix.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_feed ) );

    $uploads = wp_upload_dir();
    $dir     = trailingslashit( $uploads['basedir'] ) . 'dw-dropbox-connect-cache';

    if ( ! is_dir( $dir ) ) {
        return;
    }
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
                wp_delete_file( $path );
            }
        }
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;
    if ( $wp_filesystem ) {
        $wp_filesystem->rmdir( $dir );
    }
}

dwdc_uninstall();
