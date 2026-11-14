<?php
/**
 * Importa um arquivo baixado para a Biblioteca de Mídia nativa.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Importer {
    /**
     * @param string $tmp
     * @param string $filename
     * @return array<string,mixed>|WP_Error
     */
    public function import( $tmp, $filename ) {
        if ( ! is_string( $tmp ) || ! is_file( $tmp ) ) {
            return new WP_Error( 'dwdc_temp', __( 'Não foi possível preparar o arquivo importado.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        $filename = sanitize_file_name( $filename );
        if ( $filename === '' ) {
            $this->remove( $tmp );
            return new WP_Error( 'dwdc_name', __( 'O nome do arquivo é inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $checked = wp_check_filetype_and_ext( $tmp, $filename );
        if ( empty( $checked['type'] ) ) {
            $this->remove( $tmp );
            return new WP_Error( 'dwdc_type', __( 'O WordPress não permite este tipo de arquivo.', 'dw-dropbox-connect' ), array( 'status' => 415 ) );
        }

        $id = media_handle_sideload(
            array(
                'name'     => $filename,
                'tmp_name' => $tmp,
            ),
            0
        );
        if ( is_wp_error( $id ) ) {
            $this->remove( $tmp );
            $id->add_data( array( 'status' => 500 ) );
            return $id;
        }
        if ( get_post_type( $id ) !== 'attachment' ) {
            return new WP_Error( 'dwdc_attachment', __( 'O WordPress não criou o anexo.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }

        return array(
            'id'       => (int) $id,
            'url'      => (string) wp_get_attachment_url( $id ),
            'title'    => get_the_title( $id ),
            'mime'     => (string) get_post_mime_type( $id ),
            'filename' => wp_basename( (string) get_attached_file( $id ) ),
        );
    }

    /**
     * Marca arquivos cujo nome já existe na Biblioteca.
     *
     * A comparação usa o nome sanitizado, o mesmo que o WordPress grava
     * em _wp_attached_file. Não compara o conteúdo do arquivo.
     *
     * @param array<int,mixed> $entries
     * @return array<int,mixed>
     */
    public function annotate_library( array $entries ) {
        $names = array();
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) || ( isset( $entry['tag'] ) ? $entry['tag'] : '' ) !== 'file' ) {
                continue;
            }
            if ( ! empty( $entry['name'] ) && is_string( $entry['name'] ) ) {
                $names[] = $entry['name'];
            }
        }
        $index = $this->library_ids_for_names( $names );
        foreach ( $entries as $i => $entry ) {
            if ( ! is_array( $entry ) || ( isset( $entry['tag'] ) ? $entry['tag'] : '' ) !== 'file' ) {
                continue;
            }
            $key = $this->library_key( isset( $entry['name'] ) ? $entry['name'] : '' );
            $library_id = ( $key !== '' && isset( $index[ $key ] ) ) ? (int) $index[ $key ] : 0;
            if ( $library_id && ! current_user_can( 'edit_post', $library_id ) ) {
                $library_id = 0;
            }
            $entries[ $i ]['library_id'] = $library_id;
        }
        return $entries;
    }

    /**
     * @param array<int,string> $names
     * @return array<string,int> nome sanitizado em minúsculas => ID do anexo
     */
    public function library_ids_for_names( array $names ) {
        global $wpdb;

        $wanted = array();
        foreach ( $names as $name ) {
            $key = $this->library_key( $name );
            if ( $key !== '' ) {
                $wanted[ $key ] = true;
            }
        }
        if ( ! $wanted ) {
            return array();
        }

        $keys         = array_keys( $wanted );
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $sql          = "SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_wp_attached_file'
            AND p.post_type = 'attachment'
            AND p.post_status IN ('inherit', 'private')
            AND LOWER(SUBSTRING_INDEX(pm.meta_value, '/', -1)) IN ($placeholders)";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the placeholder list is generated to match $keys.
        $prepared = $wpdb->prepare( $sql, ...$keys );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $prepared is the result of $wpdb->prepare(); the lookup must see attachments just imported.
        $rows = is_string( $prepared ) ? $wpdb->get_results( $prepared ) : array();
        $found    = array();
        if ( ! is_array( $rows ) ) {
            return $found;
        }
        foreach ( $rows as $row ) {
            $base = $this->lower_text( wp_basename( (string) $row->meta_value ) );
            if ( ! isset( $wanted[ $base ] ) ) {
                continue;
            }
            $id = (int) $row->post_id;
            if ( ! isset( $found[ $base ] ) || $id > $found[ $base ] ) {
                $found[ $base ] = $id;
            }
        }
        return $found;
    }

    /**
     * @param string $name
     * @return string
     */
    private function library_key( $name ) {
        $safe = sanitize_file_name( (string) $name );
        if ( $safe === '' ) {
            return '';
        }
        return $this->lower_text( $safe );
    }

    /**
     * @param string $text
     * @return string
     */
    private function lower_text( $text ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    }

    /**
     * @param string $tmp
     */
    private function remove( $tmp ) {
        if ( is_string( $tmp ) && is_file( $tmp ) ) {
            wp_delete_file( $tmp );
        }
    }
}
