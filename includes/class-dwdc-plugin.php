<?php
/**
 * Administração, assets do seletor e ciclo de vida.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Plugin {
    /** @var self|null */
    private static $instance = null;

    /** @var DWDC_OAuth */
    private $oauth;

    /** @var DWDC_Api */
    private $api;

    /** @var DWDC_Feeds_Admin */
    private $feeds_admin;

    /**
     * @return self
     */
    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->oauth = new DWDC_OAuth();
        $this->api   = new DWDC_Api( $this->oauth );
        new DWDC_Rest( $this->oauth );
        $feeds             = new DWDC_Feeds( $this->api );
        $this->feeds_admin = new DWDC_Feeds_Admin( $feeds, $this->oauth );
        new DWDC_Gallery( $feeds, $this->oauth );
        new DWDC_Updater();
        $this->oauth->migrate_legacy_options();

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'in_admin_header', array( $this, 'suppress_external_admin_notices' ), 999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
        add_action( 'admin_post_dwdc_save', array( $this, 'save_settings' ) );
        add_action( 'admin_post_dwdc_connect', array( $this, 'connect' ) );
        add_action( 'admin_post_dwdc_oauth_callback', array( $this, 'callback' ) );
        add_action( 'admin_post_nopriv_dwdc_oauth_callback', array( $this, 'callback_logged_out' ) );
        add_action( 'admin_post_dwdc_disconnect', array( $this, 'disconnect' ) );
        add_action( 'wp_enqueue_media', array( $this, 'enqueue_media' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( DWDC_FILE ), array( $this, 'action_links' ) );
    }

    public static function activate() {
        if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
            return;
        }
        deactivate_plugins( plugin_basename( DWDC_FILE ) );
        wp_die(
            esc_html__( 'DW Dropbox Connect precisa da extensão OpenSSL do PHP.', 'dw-dropbox-connect' ),
            '',
            array( 'back_link' => true )
        );
    }

    public function admin_menu() {
        add_options_page(
            __( 'Dropbox', 'dw-dropbox-connect' ),
            __( 'Dropbox', 'dw-dropbox-connect' ),
            'manage_options',
            'dw-dropbox-connect',
            array( $this, 'render_settings' )
        );
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function action_links( $links ) {
        $url = admin_url( 'options-general.php?page=dw-dropbox-connect' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configurações', 'dw-dropbox-connect' ) . '</a>' );
        return $links;
    }

    public function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $notice = get_transient( $this->flash_key() );
        if ( is_array( $notice ) ) {
            delete_transient( $this->flash_key() );
        } else {
            $notice = null;
        }
        $credentials   = $this->oauth->credentials_ready();
        $connected     = $this->oauth->connected();
        $account       = $this->oauth->account_label();
        $redirect_uri  = $this->oauth->redirect_uri();
        $app_key       = $this->oauth->uses_constants() ? '' : $this->oauth->app_key();
        $has_secret    = $this->oauth->has_saved_secret();
        $uses_constants = $this->oauth->uses_constants();
        $ready         = true === $credentials;
        $ready_error   = is_wp_error( $credentials ) ? $credentials->get_error_message() : '';
        $connect_url   = wp_nonce_url( admin_url( 'admin-post.php?action=dwdc_connect' ), 'dwdc_connect' );
        $dwdc_feeds_admin = $this->feeds_admin;
        include DWDC_PATH . 'includes/views/settings-page.php';
    }

    public function save_settings() {
        $this->assert_admin();
        check_admin_referer( 'dwdc_save' );
        $key = isset( $_POST['app_key'] ) ? sanitize_text_field( wp_unslash( $_POST['app_key'] ) ) : '';
        $secret = isset( $_POST['app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['app_secret'] ) ) : '';
        $saved = $this->oauth->save_credentials( $key, $secret );
        if ( is_wp_error( $saved ) ) {
            $this->redirect_notice( 'error', $saved->get_error_message(), 'credenciais' );
        }
        $this->redirect_notice( 'success', __( 'Credenciais salvas.', 'dw-dropbox-connect' ), 'credenciais' );
    }

    public function connect() {
        $this->assert_admin();
        check_admin_referer( 'dwdc_connect' );
        $url = $this->oauth->authorization_url();
        if ( is_wp_error( $url ) ) {
            $this->redirect_notice( 'error', $url->get_error_message() );
        }
        $this->redirect_dropbox( $url );
    }

    public function callback() {
        $this->assert_admin();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Dropbox returns here; the one-time OAuth state is checked in handle_callback().
        $result = $this->oauth->handle_callback( wp_unslash( $_GET ) );
        if ( is_wp_error( $result ) ) {
            $this->redirect_notice( 'error', $result->get_error_message() );
        }
        $this->redirect_notice( 'success', __( 'Dropbox conectado.', 'dw-dropbox-connect' ) );
    }

    public function callback_logged_out() {
        wp_die(
            esc_html__( 'Sua sessão do WordPress expirou durante a autorização. Entre novamente e conecte o Dropbox outra vez.', 'dw-dropbox-connect' ),
            '',
            array( 'response' => 401 )
        );
    }

    public function disconnect() {
        $this->assert_admin();
        check_admin_referer( 'dwdc_disconnect' );
        $this->oauth->disconnect();
        $this->api->clear_caches();
        $this->redirect_notice( 'success', __( 'Dropbox desconectado.', 'dw-dropbox-connect' ) );
    }

    /**
     * Tira da tela as notices globais de outros plugins.
     * O aviso desta página fica no template, fora desses hooks.
     */
    public function suppress_external_admin_notices() {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'settings_page_dw-dropbox-connect' ) {
            return;
        }
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        remove_all_actions( 'network_admin_notices' );
        remove_all_actions( 'user_admin_notices' );
    }

    /**
     * @param string $hook
     */
    public function enqueue_admin( $hook ) {
        if ( $hook === 'upload.php' ) {
            wp_enqueue_media();
        }
        if ( $hook !== 'settings_page_dw-dropbox-connect' ) {
            return;
        }
        wp_enqueue_style( 'dwdc-admin', DWDC_URL . 'assets/css/admin.css', array(), DWDC_VERSION );
        wp_enqueue_script( 'dwdc-admin', DWDC_URL . 'assets/js/admin.js', array(), DWDC_VERSION, true );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        if ( $tab === 'galerias' ) {
            wp_enqueue_script( 'dwdc-feeds-admin', DWDC_URL . 'assets/js/feeds-admin.js', array( 'wp-api-fetch' ), DWDC_VERSION, true );
            wp_localize_script(
                'dwdc-feeds-admin',
                'dwdcFeeds',
                array(
                    'i18n' => array(
                        'root'      => __( 'Dropbox', 'dw-dropbox-connect' ),
                        'use'       => __( 'Usar esta pasta', 'dw-dropbox-connect' ),
                        'more'      => __( 'Carregar mais', 'dw-dropbox-connect' ),
                        'loading'   => __( 'Carregando…', 'dw-dropbox-connect' ),
                        'failed'    => __( 'Não foi possível carregar o Dropbox.', 'dw-dropbox-connect' ),
                        'noFolders' => __( 'Nenhuma subpasta.', 'dw-dropbox-connect' ),
                        /* translators: %d: number of images, replaced by the script. */
                        'images'    => __( '%d imagens nesta pasta.', 'dw-dropbox-connect' ),
                        'partial'   => __( 'Há mais itens a carregar.', 'dw-dropbox-connect' ),
                    ),
                )
            );
        }
    }

    public function enqueue_media() {
        if ( ! current_user_can( 'upload_files' ) ) {
            return;
        }
        wp_enqueue_style( 'dwdc-media', DWDC_URL . 'assets/css/media-frame.css', array(), DWDC_VERSION );
        wp_enqueue_script(
            'dwdc-media',
            DWDC_URL . 'assets/js/media-frame.js',
            array( 'media-views', 'wp-api-fetch' ),
            DWDC_VERSION,
            true
        );
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        wp_localize_script(
            'dwdc-media',
            'dwdcMedia',
            array(
                'root'        => esc_url_raw( rest_url( 'dwdc/v1/' ) ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
                'canManage'   => current_user_can( 'manage_options' ),
                'settingsUrl' => admin_url( 'options-general.php?page=dw-dropbox-connect' ),
                'libraryPage' => $screen && $screen->base === 'upload',
                'i18n'        => array(
                    'tab'          => __( 'Dropbox', 'dw-dropbox-connect' ),
                    'up'           => __( 'Voltar', 'dw-dropbox-connect' ),
                    'refresh'      => __( 'Atualizar', 'dw-dropbox-connect' ),
                    'search'       => __( 'Buscar no Dropbox', 'dw-dropbox-connect' ),
                    'searchAction' => __( 'Buscar', 'dw-dropbox-connect' ),
                    'clear'        => __( 'Limpar busca', 'dw-dropbox-connect' ),
                    'more'         => __( 'Carregar mais', 'dw-dropbox-connect' ),
                    'loading'      => __( 'Carregando…', 'dw-dropbox-connect' ),
                    'searching'    => __( 'Buscando…', 'dw-dropbox-connect' ),
                    'empty'        => __( 'Nenhum arquivo nesta pasta.', 'dw-dropbox-connect' ),
                    'emptySearch'  => __( 'Nenhum resultado.', 'dw-dropbox-connect' ),
                    'import'          => __( 'Importar', 'dw-dropbox-connect' ),
                    'importing'       => __( 'Importando…', 'dw-dropbox-connect' ),
                    'imported'        => __( 'Importado', 'dw-dropbox-connect' ),
                    'already'         => __( 'Já contém item', 'dw-dropbox-connect' ),
                    'selected'        => __( 'Selecionado', 'dw-dropbox-connect' ),
                    'selectAll'       => __( 'Selecionar todos', 'dw-dropbox-connect' ),
                    'importSelected'  => __( 'Importar selecionados', 'dw-dropbox-connect' ),
                    'selectImported'  => __( 'Incluir na seleção', 'dw-dropbox-connect' ),
                    'included'        => __( 'Incluída na seleção. Confirme no botão do rodapé.', 'dw-dropbox-connect' ),
                    'notIncluded'     => __( 'Não foi possível incluir na seleção.', 'dw-dropbox-connect' ),
                    'importProgress'  => __( 'Importando %1 de %2…', 'dw-dropbox-connect' ),
                    'bulkDone'        => __( 'Concluído: %1 na seleção.', 'dw-dropbox-connect' ),
                    'bulkImported'    => __( 'Concluído: %1 importado(s), sem entrar na seleção.', 'dw-dropbox-connect' ),
                    'bulkPartial'     => __( 'Concluído: %1. Falhas: %2.', 'dw-dropbox-connect' ),
                    'folder'       => __( 'Pasta', 'dw-dropbox-connect' ),
                    'open'         => __( 'Abrir pasta', 'dw-dropbox-connect' ),
                    'root'         => __( 'Dropbox', 'dw-dropbox-connect' ),
                    'shortQuery'   => __( 'Digite pelo menos dois caracteres.', 'dw-dropbox-connect' ),
                    'failed'       => __( 'Não foi possível carregar o Dropbox.', 'dw-dropbox-connect' ),
                    'importFailed' => __( 'A importação falhou.', 'dw-dropbox-connect' ),
                    'sendSelection' => __( 'Enviar seleção', 'dw-dropbox-connect' ),
                    'sendProgress' => __( 'Enviando %1 de %2…', 'dw-dropbox-connect' ),
                    'sendDone'     => __( 'Enviado: %1 arquivo(s) para esta pasta.', 'dw-dropbox-connect' ),
                    'sendPartial'  => __( 'Enviados: %1. Falhas: %2.', 'dw-dropbox-connect' ),
                    'sendNone'     => __( 'Selecione arquivos na Biblioteca de mídia.', 'dw-dropbox-connect' ),
                    'sendFailed'   => __( 'O envio para o Dropbox falhou.', 'dw-dropbox-connect' ),
                    'disconnected' => __( 'Nenhuma conta do Dropbox está conectada.', 'dw-dropbox-connect' ),
                    'manage'       => __( 'Abrir configurações', 'dw-dropbox-connect' ),
                    'done'         => __( 'Concluir', 'dw-dropbox-connect' ),
                    'file'         => __( 'Arquivo', 'dw-dropbox-connect' ),
                ),
            )
        );
    }

    private function assert_admin() {
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_die( esc_html__( 'Você não tem permissão para alterar esta conexão.', 'dw-dropbox-connect' ), '', array( 'response' => 403 ) );
    }

    /**
     * @param string $type
     * @param string $message
     * @param string $tab
     */
    private function redirect_notice( $type, $message, $tab = '' ) {
        set_transient(
            $this->flash_key(),
            array(
                'type'    => $type === 'error' ? 'error' : 'success',
                'message' => wp_strip_all_tags( $message ),
            ),
            MINUTE_IN_SECONDS
        );
        $url = admin_url( 'options-general.php?page=dw-dropbox-connect' );
        if ( in_array( $tab, array( 'conexao', 'galerias', 'credenciais', 'app' ), true ) ) {
            $url = add_query_arg( 'tab', $tab, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * @param string $url
     */
    private function redirect_dropbox( $url ) {
        $parts  = wp_parse_url( $url );
        $host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        if ( $scheme !== 'https' || ! in_array( $host, array( 'www.dropbox.com', 'dropbox.com' ), true ) ) {
            $this->redirect_notice( 'error', __( 'A URL de autorização do Dropbox é inválida.', 'dw-dropbox-connect' ) );
        }
        add_filter(
            'allowed_redirect_hosts',
            static function ( $hosts ) {
                $hosts[] = 'www.dropbox.com';
                $hosts[] = 'dropbox.com';
                return $hosts;
            }
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * @return string
     */
    private function flash_key() {
        return 'dwdc_flash_' . get_current_user_id();
    }
}
