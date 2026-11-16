<?php
/**
 * Aba Galerias em Configurações → Dropbox: criar, editar, sincronizar e excluir.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Feeds_Admin {
    /** @var DWDC_Feeds */
    private $feeds;

    /** @var DWDC_OAuth */
    private $oauth;

    /**
     * @param DWDC_Feeds $feeds
     * @param DWDC_OAuth $oauth
     */
    public function __construct( DWDC_Feeds $feeds, DWDC_OAuth $oauth ) {
        $this->feeds = $feeds;
        $this->oauth = $oauth;
        add_action( 'admin_post_dwdc_feed_save', array( $this, 'save' ) );
        add_action( 'admin_post_dwdc_feed_delete', array( $this, 'delete' ) );
        add_action( 'admin_post_dwdc_feed_refresh', array( $this, 'refresh' ) );
    }

    /**
     * Dados que o template da aba usa.
     *
     * @return array<string,mixed>
     */
    public function context() {
        $feeds = $this->feeds->all();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector, validated below.
        $edit = isset( $_GET['feed'] ) ? sanitize_text_field( wp_unslash( $_GET['feed'] ) ) : '';
        $current = null;
        if ( $edit === 'new' ) {
            $current = DWDC_Feeds::defaults();
        } elseif ( isset( $feeds[ $edit ] ) ) {
            $current = $feeds[ $edit ];
        }
        $snapshots = array();
        foreach ( $feeds as $id => $feed ) {
            $snapshots[ $id ] = $this->feeds->snapshot( $id );
        }
        return array(
            'feeds'       => $feeds,
            'snapshots'   => $snapshots,
            'current'     => $current,
            'tab_url'     => $this->tab_url(),
            'webhook_url' => rest_url( 'dwdc/v1/webhook' ),
            'connected'   => $this->oauth->connected(),
            'refresh_options' => array(
                5    => __( 'A cada 5 minutos', 'dw-dropbox-connect' ),
                15   => __( 'A cada 15 minutos', 'dw-dropbox-connect' ),
                30   => __( 'A cada 30 minutos', 'dw-dropbox-connect' ),
                60   => __( 'A cada hora', 'dw-dropbox-connect' ),
                360  => __( 'A cada 6 horas', 'dw-dropbox-connect' ),
                1440 => __( 'Uma vez por dia', 'dw-dropbox-connect' ),
            ),
        );
    }

    public function save() {
        $this->assert_admin();
        check_admin_referer( 'dwdc_feed_save' );
        $id = isset( $_POST['feed_id'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_id'] ) ) : '';
        if ( $id !== '' && ! $this->feeds->get( $id ) ) {
            $this->redirect( 'error', __( 'Galeria não encontrada.', 'dw-dropbox-connect' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above; each field is validated in sanitize().
        $feed = $this->feeds->sanitize( $_POST, $id );
        if ( is_wp_error( $feed ) ) {
            $this->redirect( 'error', $feed->get_error_message(), $id !== '' ? $id : 'new' );
        }
        $this->feeds->save( $feed );
        $snap = $this->feeds->sync( $feed['id'] );
        if ( is_wp_error( $snap ) ) {
            $this->redirect(
                'error',
                /* translators: %s: error message from the sync. */
                sprintf( __( 'Galeria salva, mas a sincronização falhou: %s', 'dw-dropbox-connect' ), $snap->get_error_message() ),
                $feed['id']
            );
        }
        $count   = count( $snap['items'] );
        $message = sprintf(
            /* translators: %d: number of images found. */
            _n( 'Galeria salva. %d imagem encontrada.', 'Galeria salva. %d imagens encontradas.', $count, 'dw-dropbox-connect' ),
            $count
        );
        if ( ! empty( $snap['truncated'] ) ) {
            $message .= ' ' . __( 'A pasta é muito grande e só parte dela foi lida.', 'dw-dropbox-connect' );
        }
        $this->redirect( 'success', $message );
    }

    public function delete() {
        $this->assert_admin();
        $id = isset( $_GET['feed'] ) ? sanitize_text_field( wp_unslash( $_GET['feed'] ) ) : '';
        check_admin_referer( 'dwdc_feed_delete_' . $id );
        if ( ! $this->feeds->get( $id ) ) {
            $this->redirect( 'error', __( 'Galeria não encontrada.', 'dw-dropbox-connect' ) );
        }
        $this->feeds->delete( $id );
        $this->redirect( 'success', __( 'Galeria excluída. Os arquivos do Dropbox não foram alterados.', 'dw-dropbox-connect' ) );
    }

    public function refresh() {
        $this->assert_admin();
        $id = isset( $_GET['feed'] ) ? sanitize_text_field( wp_unslash( $_GET['feed'] ) ) : '';
        check_admin_referer( 'dwdc_feed_refresh_' . $id );
        $snap = $this->feeds->sync( $id );
        if ( is_wp_error( $snap ) ) {
            $this->redirect( 'error', $snap->get_error_message() );
        }
        $count = count( $snap['items'] );
        $this->redirect(
            'success',
            sprintf(
                /* translators: %d: number of images found. */
                _n( 'Sincronizado. %d imagem na galeria.', 'Sincronizado. %d imagens na galeria.', $count, 'dw-dropbox-connect' ),
                $count
            )
        );
    }

    /**
     * @return string
     */
    public function tab_url() {
        return add_query_arg(
            array(
                'page' => 'dw-dropbox-connect',
                'tab'  => 'galerias',
            ),
            admin_url( 'options-general.php' )
        );
    }

    private function assert_admin() {
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_die( esc_html__( 'Você não tem permissão para alterar as galerias.', 'dw-dropbox-connect' ), '', array( 'response' => 403 ) );
    }

    /**
     * @param string $type
     * @param string $message
     * @param string $feed   ID ou "new" para voltar ao formulário.
     */
    private function redirect( $type, $message, $feed = '' ) {
        set_transient(
            'dwdc_flash_' . get_current_user_id(),
            array(
                'type'    => $type === 'error' ? 'error' : 'success',
                'message' => wp_strip_all_tags( $message ),
            ),
            MINUTE_IN_SECONDS
        );
        $url = $this->tab_url();
        if ( $feed === 'new' || DWDC_Feeds::valid_id( $feed ) ) {
            $url = add_query_arg( 'feed', $feed, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
