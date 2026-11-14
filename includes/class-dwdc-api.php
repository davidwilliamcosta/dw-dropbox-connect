<?php
/**
 * Cliente HTTP da API do Dropbox.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Api {
    /** @var DWDC_OAuth */
    private $oauth;

    /**
     * @param DWDC_OAuth $oauth
     */
    public function __construct( DWDC_OAuth $oauth ) {
        $this->oauth = $oauth;
    }

    /**
     * @param string $path
     * @param string $cursor
     * @param bool   $fresh Ignora o transient. A aba pede isso para ver arquivos recém-enviados.
     * @return array<string,mixed>|WP_Error
     */
    public function list_folder( $path, $cursor = '', $fresh = false ) {
        $cache_key = 'dwdc_ls_' . md5( $cursor !== '' ? 'c:' . $cursor : 'p:' . $path );
        if ( ! $fresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        if ( $cursor !== '' ) {
            $data = $this->rpc( 'files/list_folder/continue', array( 'cursor' => $cursor ) );
        } else {
            $data = $this->rpc(
                'files/list_folder',
                array(
                    'path'                            => $path,
                    'recursive'                       => false,
                    'include_deleted'                 => false,
                    'include_media_info'              => false,
                    'include_non_downloadable_files'  => false,
                    'limit'                           => 100,
                )
            );
        }
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $normalized = DWDC_Normalizer::list_result( $data );
        if ( ! $fresh ) {
            set_transient( $cache_key, $normalized, 5 * MINUTE_IN_SECONDS );
        }
        return $normalized;
    }

    /**
     * @param string $query
     * @param string $cursor
     * @param bool   $fresh
     * @return array<string,mixed>|WP_Error
     */
    public function search( $query, $cursor = '', $fresh = false ) {
        if ( $cursor !== '' ) {
            $payload = array( 'cursor' => $cursor );
            $endpoint = 'files/search/continue_v2';
        } else {
            $payload = array(
                'query'   => $query,
                'options' => array(
                    'max_results'   => 100,
                    'file_status'   => 'active',
                    'filename_only' => false,
                ),
            );
            $endpoint = 'files/search_v2';
        }
        $cache_key = 'dwdc_search_' . md5( $endpoint . '|' . wp_json_encode( $payload ) );
        if ( ! $fresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $data = $this->rpc( $endpoint, $payload );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $normalized = DWDC_Normalizer::search_result( $data );
        if ( ! $fresh ) {
            set_transient( $cache_key, $normalized, 5 * MINUTE_IN_SECONDS );
        }
        return $normalized;
    }

    /**
     * @param string $path
     * @return string|WP_Error Caminho absoluto do JPEG em cache.
     */
    public function thumbnail_file( $path ) {
        if ( ! DWDC_Normalizer::is_thumbnailable( $path ) ) {
            return new WP_Error( 'dwdc_thumbnail', __( 'Este arquivo não tem miniatura.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $dir = $this->cache_dir();
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }
        $file = trailingslashit( $dir ) . hash_hmac( 'sha256', $path, wp_salt( 'auth' ) ) . '.jpg';
        if ( is_file( $file ) && filesize( $file ) > 0 && ( time() - (int) filemtime( $file ) ) < DAY_IN_SECONDS ) {
            return $file;
        }
        $token = $this->oauth->access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $response = wp_remote_post(
            'https://content.dropboxapi.com/2/files/get_thumbnail_v2',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $token,
                    'Dropbox-API-Arg' => wp_json_encode(
                        array(
                            'resource' => array(
                                '.tag' => 'path',
                                'path' => $path,
                            ),
                            'format'   => 'jpeg',
                            'size'     => 'w256h256',
                            'mode'     => 'bestfit',
                        )
                    ),
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dwdc_thumbnail', __( 'Não foi possível carregar a miniatura.', 'dw-dropbox-connect' ), array( 'status' => 502 ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );
        if ( $status !== 200 || ! self::is_jpeg( $body ) ) {
            return new WP_Error( 'dwdc_thumbnail', __( 'O Dropbox não gerou miniatura para este arquivo.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        if ( file_put_contents( $file, $body, LOCK_EX ) === false ) {
            return new WP_Error( 'dwdc_thumbnail', __( 'Não foi possível guardar a miniatura.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        return $file;
    }

    /**
     * Confirma que o arquivo servido é um JPEG do cache deste plugin.
     *
     * @param string $file
     * @return bool
     */
    public function is_cached_thumbnail( $file ) {
        if ( ! is_string( $file ) || ! is_file( $file ) ) {
            return false;
        }
        $dir = $this->cache_dir( false );
        if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
            return false;
        }
        $base = realpath( $dir );
        $path = realpath( $file );
        if ( ! is_string( $base ) || ! is_string( $path ) ) {
            return false;
        }
        $base = wp_normalize_path( $base );
        $path = wp_normalize_path( $path );
        if ( strpos( $path, rtrim( $base, '/' ) . '/' ) !== 0 ) {
            return false;
        }
        if ( ! preg_match( '/^[a-f0-9]{64}\.jpg$/', wp_basename( $path ) ) ) {
            return false;
        }
        $head = file_get_contents( $path, false, null, 0, 3 );
        return self::is_jpeg( $head );
    }

    /**
     * @param string $body
     * @return bool
     */
    private static function is_jpeg( $body ) {
        return is_string( $body ) && strlen( $body ) >= 3 && substr( $body, 0, 3 ) === "\xFF\xD8\xFF";
    }

    /**
     * @param string $path
     * @return array{tmp:string,name:string,size:int}|WP_Error
     */
    public function download_to_temp( $path ) {
        $meta = $this->rpc( 'files/get_metadata', array( 'path' => $path ) );
        if ( is_wp_error( $meta ) ) {
            return $meta;
        }
        if ( ( isset( $meta['.tag'] ) ? $meta['.tag'] : '' ) !== 'file' ) {
            return new WP_Error( 'dwdc_not_file', __( 'Só é possível importar arquivos.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $size = isset( $meta['size'] ) ? (int) $meta['size'] : 0;
        $max  = (int) wp_max_upload_size();
        if ( $size < 0 || ( $max > 0 && $size > $max ) ) {
            return new WP_Error( 'dwdc_size', __( 'O arquivo excede o limite de upload do WordPress.', 'dw-dropbox-connect' ), array( 'status' => 413 ) );
        }
        $name = sanitize_file_name( isset( $meta['name'] ) ? (string) $meta['name'] : basename( $path ) );
        if ( $name === '' ) {
            return new WP_Error( 'dwdc_name', __( 'O nome do arquivo é inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        if ( ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = wp_tempnam( $name );
        if ( ! is_string( $tmp ) || $tmp === '' ) {
            return new WP_Error( 'dwdc_temp', __( 'Não foi possível preparar o arquivo importado.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        $token = $this->oauth->access_token();
        if ( is_wp_error( $token ) ) {
            unlink( $tmp );
            return $token;
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }
        $response = wp_remote_post(
            'https://content.dropboxapi.com/2/files/download',
            array(
                'timeout'  => 300,
                'stream'   => true,
                'filename' => $tmp,
                'headers'  => array(
                    'Authorization'   => 'Bearer ' . $token,
                    'Dropbox-API-Arg' => wp_json_encode( array( 'path' => $path ) ),
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            unlink( $tmp );
            return new WP_Error( 'dwdc_download', __( 'O download do Dropbox falhou.', 'dw-dropbox-connect' ), array( 'status' => 502 ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $actual = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
        if ( $status !== 200 || ( $max > 0 && $actual > $max ) ) {
            if ( is_file( $tmp ) ) {
                unlink( $tmp );
            }
            return new WP_Error( 'dwdc_download', __( 'O download do Dropbox falhou.', 'dw-dropbox-connect' ), array( 'status' => $status === 200 ? 413 : 502 ) );
        }
        return array(
            'tmp'  => $tmp,
            'name' => $name,
            'size' => $actual,
        );
    }

    /**
     * Envia um arquivo local para o caminho absoluto no Dropbox.
     * Até 8 MB vai num único pedido. Acima disso, usa sessão em blocos de 8 MB.
     *
     * @param string $dropbox_path
     * @param string $local_file
     * @return array<string,mixed>|WP_Error
     */
    public function upload( $dropbox_path, $local_file ) {
        if ( ! is_string( $local_file ) || ! is_readable( $local_file ) ) {
            return new WP_Error( 'dwdc_export', __( 'O arquivo da biblioteca não está legível.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $size = filesize( $local_file );
        if ( $size === false || $size < 1 ) {
            return new WP_Error( 'dwdc_export', __( 'O arquivo da biblioteca está vazio.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $commit = array(
            'path'       => $dropbox_path,
            'mode'       => 'add',
            'autorename' => true,
            'mute'       => false,
        );
        if ( $size <= 8 * MB_IN_BYTES ) {
            $body = file_get_contents( $local_file );
            if ( ! is_string( $body ) || $body === '' ) {
                return new WP_Error( 'dwdc_export', __( 'Não foi possível ler o arquivo da biblioteca.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
            }
            return $this->content_upload( 'files/upload', $commit, $body );
        }
        return $this->upload_session( $commit, $local_file, (int) $size );
    }

    public function clear_caches() {
        global $wpdb;
        $needles = array(
            $wpdb->esc_like( '_transient_dwdc_ls_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_dwdc_ls_' ) . '%',
            $wpdb->esc_like( '_transient_dwdc_search_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_dwdc_search_' ) . '%',
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                $needles[0],
                $needles[1],
                $needles[2],
                $needles[3]
            )
        );
        $dir = $this->cache_dir( false );
        if ( is_string( $dir ) && is_dir( $dir ) ) {
            $items = scandir( $dir );
            if ( is_array( $items ) ) {
                foreach ( $items as $item ) {
                    if ( preg_match( '/^[a-f0-9]{64}\.jpg$/', $item ) ) {
                        $file = $dir . '/' . $item;
                        if ( is_file( $file ) ) {
                            unlink( $file );
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $commit
     * @param string              $local_file
     * @param int                 $size
     * @return array<string,mixed>|WP_Error
     */
    private function upload_session( array $commit, $local_file, $size ) {
        $handle = fopen( $local_file, 'rb' );
        if ( ! is_resource( $handle ) ) {
            return new WP_Error( 'dwdc_export', __( 'Não foi possível ler o arquivo da biblioteca.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        $chunk = 8 * MB_IN_BYTES;
        $offset = 0;
        $session = '';
        $result = null;
        while ( $offset < $size ) {
            $body = fread( $handle, $chunk );
            if ( ! is_string( $body ) || $body === '' ) {
                fclose( $handle );
                return new WP_Error( 'dwdc_export', __( 'A leitura do arquivo foi interrompida.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
            }
            $length = strlen( $body );
            $last = ( $offset + $length ) >= $size;
            if ( $session === '' ) {
                $started = $this->content_upload( 'files/upload_session/start', array( 'close' => false ), $body );
                if ( is_wp_error( $started ) ) {
                    fclose( $handle );
                    return $started;
                }
                $session = isset( $started['session_id'] ) ? (string) $started['session_id'] : '';
                if ( $session === '' ) {
                    fclose( $handle );
                    return new WP_Error( 'dwdc_export', __( 'O Dropbox não abriu a sessão de envio.', 'dw-dropbox-connect' ), array( 'status' => 502 ) );
                }
                $offset += $length;
                if ( ! $last ) {
                    continue;
                }
                $body = '';
            }
            $cursor = array(
                'session_id' => $session,
                'offset'     => $offset,
            );
            if ( $last ) {
                $result = $this->content_upload(
                    'files/upload_session/finish',
                    array(
                        'cursor' => $cursor,
                        'commit' => $commit,
                    ),
                    $body
                );
                $offset += $length;
            } else {
                $result = $this->content_upload(
                    'files/upload_session/append_v2',
                    array(
                        'cursor' => $cursor,
                        'close'  => false,
                    ),
                    $body,
                    true
                );
                $offset += $length;
            }
            if ( is_wp_error( $result ) ) {
                fclose( $handle );
                return $result;
            }
        }
        fclose( $handle );
        if ( ! is_array( $result ) ) {
            return new WP_Error( 'dwdc_export', __( 'O Dropbox não confirmou o envio.', 'dw-dropbox-connect' ), array( 'status' => 502 ) );
        }
        return $result;
    }

    /**
     * @param string              $endpoint
     * @param array<string,mixed> $arg
     * @param string              $body
     * @param bool                $allow_empty Resposta 2xx sem JSON, como em append_v2.
     * @return array<string,mixed>|WP_Error
     */
    private function content_upload( $endpoint, array $arg, $body, $allow_empty = false ) {
        $token = $this->oauth->access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $response = wp_remote_post(
            'https://content.dropboxapi.com/2/' . ltrim( $endpoint, '/' ),
            array(
                'timeout' => 120,
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $token,
                    'Content-Type'    => 'application/octet-stream',
                    'Dropbox-API-Arg' => wp_json_encode( $arg ),
                ),
                'body'    => $body,
            )
        );
        return $this->parse( $response, $allow_empty );
    }

    /**
     * @param string               $endpoint
     * @param array<string,mixed>  $payload
     * @return array<string,mixed>|WP_Error
     */
    private function rpc( $endpoint, array $payload ) {
        $token = $this->oauth->access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $response = wp_remote_post(
            'https://api.dropboxapi.com/2/' . ltrim( $endpoint, '/' ),
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $payload ),
            )
        );
        return $this->parse( $response );
    }

    /**
     * @param mixed $response
     * @param bool  $allow_empty
     * @return array<string,mixed>|WP_Error
     */
    private function parse( $response, $allow_empty = false ) {
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dwdc_http', __( 'Não foi possível falar com o Dropbox.', 'dw-dropbox-connect' ), array( 'status' => 502 ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 ) {
            $summary = '';
            if ( is_array( $body ) && isset( $body['error_summary'] ) ) {
                $summary = (string) $body['error_summary'];
            }
            return new WP_Error( 'dwdc_api', $this->public_error( $summary, $status ), array( 'status' => $this->map_status( $status ) ) );
        }
        if ( ! is_array( $body ) ) {
            if ( $allow_empty ) {
                return array();
            }
            return new WP_Error( 'dwdc_json', __( 'Resposta inválida do Dropbox.', 'dw-dropbox-connect' ), array( 'status' => 502 ) );
        }
        return $body;
    }

    /**
     * @param string $summary
     * @param int    $status
     * @return string
     */
    private function public_error( $summary, $status ) {
        $summary = strtolower( $summary );
        if ( $status === 401 || strpos( $summary, 'expired_access_token' ) !== false || strpos( $summary, 'invalid_access_token' ) !== false ) {
            return __( 'A conexão com o Dropbox expirou. Conecte a conta novamente.', 'dw-dropbox-connect' );
        }
        if ( strpos( $summary, 'missing_scope' ) !== false || strpos( $summary, 'insufficient_scope' ) !== false ) {
            return __( 'Falta a permissão files.content.write no token. No app do Dropbox, confirme essa permissão e conecte a conta de novo.', 'dw-dropbox-connect' );
        }
        if ( strpos( $summary, 'not_found' ) !== false ) {
            return __( 'A pasta ou o arquivo não foi encontrado no Dropbox.', 'dw-dropbox-connect' );
        }
        if ( $status === 429 || strpos( $summary, 'too_many' ) !== false ) {
            return __( 'O Dropbox limitou os pedidos. Espere um momento e tente de novo.', 'dw-dropbox-connect' );
        }
        return __( 'O Dropbox recusou o pedido.', 'dw-dropbox-connect' );
    }

    /**
     * @param int $status
     * @return int
     */
    private function map_status( $status ) {
        if ( $status === 401 || $status === 429 ) {
            return $status;
        }
        if ( $status >= 400 && $status < 500 ) {
            return 400;
        }
        return 502;
    }

    /**
     * @param bool $create
     * @return string|WP_Error
     */
    private function cache_dir( $create = true ) {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'dwdc_uploads', __( 'O diretório de uploads do WordPress não está disponível.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        $dir = trailingslashit( $uploads['basedir'] ) . 'dw-dropbox-connect-cache';
        if ( ! $create ) {
            return $dir;
        }
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'dwdc_uploads', __( 'Não foi possível criar o cache de miniaturas.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        if ( ! is_file( $dir . '/index.php' ) ) {
            file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
        }
        if ( ! is_file( $dir . '/.htaccess' ) ) {
            file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
        }
        if ( ! get_transient( 'dwdc_cache_swept' ) ) {
            $this->sweep_cache( $dir );
            set_transient( 'dwdc_cache_swept', 1, DAY_IN_SECONDS );
        }
        return $dir;
    }

    /**
     * @param string $dir
     */
    private function sweep_cache( $dir ) {
        $items = scandir( $dir );
        if ( ! is_array( $items ) ) {
            return;
        }
        $cutoff = time() - ( 2 * DAY_IN_SECONDS );
        foreach ( $items as $item ) {
            if ( ! preg_match( '/^[a-f0-9]{64}\.jpg$/', $item ) ) {
                continue;
            }
            $file = $dir . '/' . $item;
            if ( is_file( $file ) && filemtime( $file ) !== false && filemtime( $file ) < $cutoff ) {
                unlink( $file );
            }
        }
    }
}
