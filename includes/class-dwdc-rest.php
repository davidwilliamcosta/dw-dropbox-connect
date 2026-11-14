<?php
/**
 * Endpoints REST usados pelo seletor de mídia.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Rest {
    /** @var DWDC_OAuth */
    private $oauth;

    /** @var DWDC_Api */
    private $api;

    /** @var DWDC_Importer */
    private $importer;

    /** @var DWDC_Exporter */
    private $exporter;

    /**
     * @param DWDC_OAuth $oauth
     */
    public function __construct( DWDC_OAuth $oauth ) {
        $this->oauth    = $oauth;
        $this->api      = new DWDC_Api( $oauth );
        $this->importer = new DWDC_Importer();
        $this->exporter = new DWDC_Exporter( $this->api );
        add_action( 'rest_api_init', array( $this, 'routes' ) );
    }

    public function routes() {
        $browse = array(
            'path'   => $this->text_arg(),
            'cursor' => $this->text_arg(),
            'fresh'  => $this->text_arg(),
        );
        $this->route( '/status', WP_REST_Server::READABLE, 'status' );
        $this->route( '/files', WP_REST_Server::READABLE, 'files', $browse );
        $this->route( '/search', WP_REST_Server::READABLE, 'search', array(
            'q'      => $this->text_arg(),
            'cursor' => $this->text_arg(),
            'fresh'  => $this->text_arg(),
        ) );
        $this->route( '/thumbnail', WP_REST_Server::READABLE, 'thumbnail', array(
            'path' => $this->text_arg(),
        ) );
        $this->route( '/import', WP_REST_Server::CREATABLE, 'import_file', array(
            'path' => $this->text_arg(),
        ) );
        $this->route( '/export', WP_REST_Server::CREATABLE, 'export_file', array(
            'path' => $this->text_arg(),
            'id'   => array(
                'description'       => __( 'ID do anexo na biblioteca.', 'dw-dropbox-connect' ),
                'type'              => 'integer',
                'required'          => true,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ),
        ) );
    }

    /**
     * @param string               $path
     * @param string               $methods
     * @param string               $callback
     * @param array<string,mixed>  $args
     */
    private function route( $path, $methods, $callback, array $args = array() ) {
        register_rest_route(
            'dwdc/v1',
            $path,
            array(
                'methods'             => $methods,
                'callback'            => array( $this, $callback ),
                'permission_callback' => array( $this, 'permission' ),
                'args'                => $args,
            )
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function text_arg() {
        return array(
            'type'              => 'string',
            'required'          => false,
            'sanitize_callback' => static function ( $value ) {
                return is_string( $value ) ? wp_check_invalid_utf8( wp_unslash( $value ) ) : '';
            },
            'validate_callback' => static function ( $value ) {
                return is_string( $value ) || $value === null;
            },
        );
    }

    /**
     * @return true|WP_Error
     */
    public function permission() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'dwdc_forbidden', __( 'Você não tem permissão para enviar arquivos.', 'dw-dropbox-connect' ), array( 'status' => 401 ) );
        }
        if ( ! current_user_can( 'upload_files' ) ) {
            return new WP_Error( 'dwdc_forbidden', __( 'Você não tem permissão para enviar arquivos.', 'dw-dropbox-connect' ), array( 'status' => 403 ) );
        }
        $nonce = '';
        if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
        } elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
        }
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'dwdc_nonce', __( 'A sessão expirou. Recarregue a página.', 'dw-dropbox-connect' ), array( 'status' => 403 ) );
        }
        return true;
    }

    /**
     * @return WP_REST_Response
     */
    public function status() {
        return rest_ensure_response( array(
            'connected' => $this->oauth->connected(),
            'account'   => $this->oauth->connected() ? $this->oauth->account_label() : '',
        ) );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function files( WP_REST_Request $request ) {
        $cursor = DWDC_Normalizer::sanitize_cursor( $request->get_param( 'cursor' ) );
        if ( $cursor === null ) {
            return new WP_Error( 'dwdc_cursor', __( 'A paginação é inválida.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $fresh = $this->wants_fresh( $request );
        if ( $cursor !== '' ) {
            return $this->respond( $this->api->list_folder( '', $cursor, $fresh ) );
        }
        $path = DWDC_Normalizer::sanitize_path( $this->string_param( $request, 'path' ) );
        if ( $path === null ) {
            return new WP_Error( 'dwdc_path', __( 'O caminho do Dropbox é inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        return $this->respond( $this->api->list_folder( $path, '', $fresh ) );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function search( WP_REST_Request $request ) {
        $cursor = DWDC_Normalizer::sanitize_cursor( $request->get_param( 'cursor' ) );
        if ( $cursor === null ) {
            return new WP_Error( 'dwdc_cursor', __( 'A paginação é inválida.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $fresh = $this->wants_fresh( $request );
        if ( $cursor !== '' ) {
            return $this->respond( $this->api->search( '', $cursor, $fresh ) );
        }
        $query = DWDC_Normalizer::sanitize_query( $this->string_param( $request, 'q' ) );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $query ) : strlen( $query );
        if ( $length < 2 ) {
            return new WP_Error( 'dwdc_query', __( 'Digite pelo menos dois caracteres.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        return $this->respond( $this->api->search( $query, '', $fresh ) );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function thumbnail( WP_REST_Request $request ) {
        $path = DWDC_Normalizer::sanitize_path( $this->string_param( $request, 'path' ) );
        if ( $path === null || $path === '' ) {
            return new WP_Error( 'dwdc_path', __( 'O caminho do Dropbox é inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $file = $this->api->thumbnail_file( $path );
        if ( is_wp_error( $file ) || ! $this->api->is_cached_thumbnail( $file ) ) {
            return is_wp_error( $file ) ? $file : new WP_Error( 'dwdc_thumbnail', __( 'Este arquivo não tem miniatura.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $response = new WP_REST_Response( '', 200 );
        add_filter( 'rest_pre_serve_request', static function ( $served, $result ) use ( $file, $response ) {
            if ( $result !== $response ) {
                return $served;
            }
            if ( function_exists( 'header_remove' ) ) {
                header_remove( 'Content-Type' );
            }
            header( 'Content-Type: image/jpeg' );
            header( 'Content-Length: ' . (string) filesize( $file ) );
            header( 'Cache-Control: private, max-age=86400' );
            header( 'X-Content-Type-Options: nosniff' );
            readfile( $file );
            exit;
        }, 10, 2 );
        return $response;
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function import_file( WP_REST_Request $request ) {
        try {
            return $this->import_file_body( $request );
        } catch ( Throwable $error ) {
            error_log( 'dwdc import: ' . $error->getMessage() );
            return new WP_Error( 'dwdc_import', __( 'A importação falhou.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    private function import_file_body( WP_REST_Request $request ) {
        $path = DWDC_Normalizer::sanitize_path( $this->string_param( $request, 'path' ) );
        if ( $path === null || $path === '' ) {
            return new WP_Error( 'dwdc_path', __( 'O caminho do Dropbox é inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $limited = $this->rate_limit( 'dwdc_import_', 100, __( 'Muitas importações seguidas. Espere um minuto.', 'dw-dropbox-connect' ) );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $file = $this->api->download_to_temp( $path );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        $imported = $this->importer->import( $file['tmp'], $file['name'] );
        if ( is_wp_error( $imported ) ) {
            return $imported;
        }
        return rest_ensure_response( $imported );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function export_file( WP_REST_Request $request ) {
        $path = DWDC_Normalizer::sanitize_path( $this->string_param( $request, 'path' ) );
        if ( $path === null ) {
            return new WP_Error( 'dwdc_path', __( 'O caminho do Dropbox é inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $id = (int) $request->get_param( 'id' );
        if ( $id <= 0 ) {
            return new WP_Error( 'dwdc_attachment', __( 'O item da biblioteca não existe.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $limited = $this->rate_limit( 'dwdc_export_', 40, __( 'Muitos envios seguidos. Espere um minuto.', 'dw-dropbox-connect' ) );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $exported = $this->exporter->export( $id, $path );
        if ( is_wp_error( $exported ) ) {
            return $exported;
        }
        return rest_ensure_response( $exported );
    }

    /**
     * @param mixed $value
     * @return WP_REST_Response|WP_Error
     */
    private function respond( $value ) {
        if ( is_wp_error( $value ) ) {
            return $value;
        }
        if ( is_array( $value ) && isset( $value['entries'] ) && is_array( $value['entries'] ) ) {
            $value['entries'] = $this->importer->annotate_library( $value['entries'] );
        }
        return rest_ensure_response( $value );
    }

    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    private function wants_fresh( WP_REST_Request $request ) {
        $value = $request->get_param( 'fresh' );
        return $value === '1' || $value === 1 || $value === true || $value === 'true';
    }

    /**
     * @param WP_REST_Request $request
     * @param string          $key
     * @return string
     */
    private function string_param( WP_REST_Request $request, $key ) {
        $value = $request->get_param( $key );
        if ( is_string( $value ) ) {
            return wp_unslash( $value );
        }
        return '';
    }

    /**
     * @param string $prefix
     * @param int    $limit
     * @param string $message
     * @return true|WP_Error
     */
    private function rate_limit( $prefix, $limit, $message ) {
        $key = $prefix . get_current_user_id();
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return new WP_Error( 'dwdc_rate', $message, array( 'status' => 429 ) );
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }
}
