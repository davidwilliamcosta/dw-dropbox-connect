<?php
/**
 * Envia um anexo da biblioteca para uma pasta do Dropbox.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Exporter {
    /** @var DWDC_Api */
    private $api;

    /**
     * @param DWDC_Api $api
     */
    public function __construct( DWDC_Api $api ) {
        $this->api = $api;
    }

    /**
     * @param int    $attachment_id
     * @param string $folder Caminho já validado. Vazio é a raiz.
     * @return array<string,mixed>|WP_Error
     */
    public function export( $attachment_id, $folder ) {
        $attachment_id = (int) $attachment_id;
        $post = get_post( $attachment_id );
        if ( ! $post || $post->post_type !== 'attachment' || ! current_user_can( 'edit_post', $attachment_id ) ) {
            return new WP_Error( 'dwdc_attachment', __( 'O item da biblioteca não existe.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $file = get_attached_file( $attachment_id );
        if ( ! is_string( $file ) || $file === '' ) {
            return new WP_Error( 'dwdc_export', __( 'O arquivo da biblioteca não está disponível.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $real = realpath( $file );
        if ( ! is_string( $real ) || ! $this->inside_uploads( $real ) || ! is_readable( $real ) ) {
            return new WP_Error( 'dwdc_export', __( 'O arquivo da biblioteca não está legível.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $name = sanitize_file_name( wp_basename( $real ) );
        $dest = DWDC_Normalizer::child_path( $folder, $name );
        if ( $dest === null || $dest === '' ) {
            return new WP_Error( 'dwdc_path', __( 'O nome do arquivo não pode ir para o Dropbox.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $uploaded = $this->api->upload( $dest, $real );
        if ( is_wp_error( $uploaded ) ) {
            return $uploaded;
        }
        $saved = isset( $uploaded['path_display'] ) ? (string) $uploaded['path_display'] : $dest;
        return array(
            'id'           => $attachment_id,
            'name'         => isset( $uploaded['name'] ) ? (string) $uploaded['name'] : $name,
            'path'         => isset( $uploaded['path_lower'] ) ? (string) $uploaded['path_lower'] : $dest,
            'path_display' => $saved,
        );
    }

    /**
     * @param string $file
     * @return bool
     */
    private function inside_uploads( $file ) {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            return false;
        }
        $base = wp_normalize_path( (string) $uploads['basedir'] );
        $path = wp_normalize_path( $file );
        $base = rtrim( $base, '/' );
        return $path === $base || strpos( $path, $base . '/' ) === 0;
    }
}
