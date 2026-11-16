<?php
/**
 * Exibição pública das galerias: shortcode, bloco, endpoints das imagens e webhook do Dropbox.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Gallery {
    const SHORTCODE = 'dw_dropbox_gallery';
    const BLOCK     = 'dw-dropbox-connect/gallery';

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
        add_action( 'init', array( $this, 'register' ) );
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
    }

    public function register() {
        add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );

        wp_register_style( 'dwdc-gallery', DWDC_URL . 'assets/css/gallery.css', array(), DWDC_VERSION );
        wp_register_script( 'dwdc-gallery', DWDC_URL . 'assets/js/gallery.js', array(), DWDC_VERSION, true );
        wp_register_script(
            'dwdc-block',
            DWDC_URL . 'assets/js/block.js',
            array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
            DWDC_VERSION,
            true
        );

        register_block_type(
            self::BLOCK,
            array(
                'api_version'     => 3,
                'title'           => __( 'Galeria do Dropbox', 'dw-dropbox-connect' ),
                'description'     => __( 'Mostra as imagens de uma pasta do Dropbox e acompanha as mudanças feitas nela.', 'dw-dropbox-connect' ),
                'category'        => 'media',
                'icon'            => 'format-gallery',
                'attributes'      => array(
                    'feed'    => array(
                        'type'    => 'string',
                        'default' => '',
                    ),
                    'columns' => array(
                        'type'    => 'integer',
                        'default' => 0,
                    ),
                ),
                'supports'        => array(
                    'html'  => false,
                    'align' => array( 'wide', 'full' ),
                ),
                'render_callback' => array( $this, 'render_block' ),
                'editor_script'   => 'dwdc-block',
                'editor_style'    => 'dwdc-gallery',
            )
        );
    }

    public function editor_assets() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        $feeds = array();
        foreach ( $this->feeds->all() as $id => $feed ) {
            $feeds[] = array(
                'value' => $id,
                'label' => (string) $feed['name'],
            );
        }
        wp_localize_script(
            'dwdc-block',
            'dwdcBlock',
            array(
                'feeds'       => $feeds,
                'canManage'   => current_user_can( 'manage_options' ),
                'settingsUrl' => admin_url( 'options-general.php?page=dw-dropbox-connect&tab=galerias' ),
            )
        );
    }

    /**
     * Carrega CSS e JS cedo quando o conteúdo atual usa a galeria. A exibição também enfileira, como reserva.
     */
    public function maybe_enqueue() {
        if ( ! is_singular() ) {
            return;
        }
        $post = get_post();
        if ( ! $post ) {
            return;
        }
        if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) || has_block( self::BLOCK, $post ) ) {
            $this->enqueue_assets();
        }
    }

    private function enqueue_assets() {
        wp_enqueue_style( 'dwdc-gallery' );
        wp_enqueue_script( 'dwdc-gallery' );
    }

    /**
     * @param array<string,mixed>|string $atts
     * @return string
     */
    public function shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'id'      => '',
                'columns' => 0,
            ),
            is_array( $atts ) ? $atts : array(),
            self::SHORTCODE
        );
        return $this->render( (string) $atts['id'], array( 'columns' => (int) $atts['columns'] ) );
    }

    /**
     * @param array<string,mixed> $attributes
     * @return string
     */
    public function render_block( $attributes ) {
        return $this->render(
            isset( $attributes['feed'] ) ? (string) $attributes['feed'] : '',
            array( 'columns' => isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 0 )
        );
    }

    /**
     * @param string              $id
     * @param array<string,mixed> $overrides
     * @return string
     */
    public function render( $id, array $overrides = array() ) {
        $feed = $this->feeds->get( $id );
        if ( ! $feed ) {
            return $this->admin_notice( __( 'Galeria do Dropbox não encontrada. Confira o ID em Configurações → Dropbox → Galerias.', 'dw-dropbox-connect' ) );
        }
        $snap = $this->feeds->snapshot( $feed['id'] );
        $this->feeds->maybe_schedule_refresh( $feed );
        if ( ! $snap['items'] ) {
            if ( (int) $snap['synced_at'] === 0 ) {
                return $this->admin_notice( __( 'Esta galeria ainda não foi sincronizada. Abra Configurações → Dropbox → Galerias e use Atualizar agora.', 'dw-dropbox-connect' ) );
            }
            return $this->admin_notice( __( 'Nenhuma imagem encontrada na pasta do Dropbox desta galeria.', 'dw-dropbox-connect' ) );
        }
        $this->enqueue_assets();

        $columns = (int) $feed['columns'];
        if ( ! empty( $overrides['columns'] ) ) {
            $columns = DWDC_Normalizer::clamp_int( $overrides['columns'], 1, 8, $columns );
        }
        $columns_md = min( $columns, (int) $feed['columns_md'] );
        $columns_sm = min( $columns_md, (int) $feed['columns_sm'] );
        $style      = sprintf(
            '--dwdc-cols:%d;--dwdc-cols-md:%d;--dwdc-cols-sm:%d;--dwdc-gap:%dpx',
            $columns,
            $columns_md,
            $columns_sm,
            (int) $feed['gap']
        );
        $masonry = $feed['layout'] === 'masonry';
        $classes = array(
            'dwdc-gallery',
            'dwdc-layout-' . ( $masonry ? 'masonry' : 'grid' ),
        );
        if ( ! $masonry ) {
            $classes[] = 'dwdc-ratio-' . ( $feed['ratio'] === 'original' ? 'original' : 'square' );
        }
        $i18n = array(
            'dialog' => __( 'Galeria de imagens', 'dw-dropbox-connect' ),
            'close'  => __( 'Fechar', 'dw-dropbox-connect' ),
            'prev'   => __( 'Imagem anterior', 'dw-dropbox-connect' ),
            'next'   => __( 'Próxima imagem', 'dw-dropbox-connect' ),
            'count'  => __( '%1 de %2', 'dw-dropbox-connect' ),
        );

        $html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"';
        $html .= ' data-feed="' . esc_attr( $feed['id'] ) . '"';
        $html .= ' data-version="' . esc_attr( $snap['version'] ) . '"';
        $html .= ' data-lightbox="' . ( ! empty( $feed['lightbox'] ) ? '1' : '0' ) . '"';
        $html .= ' data-step="' . esc_attr( (string) (int) $feed['per_page'] ) . '"';
        $html .= ' data-endpoint="' . esc_url( rest_url( 'dwdc/v1/gallery/' . $feed['id'] ) ) . '"';
        $html .= ' data-i18n="' . esc_attr( (string) wp_json_encode( $i18n ) ) . '"';
        $html .= ' style="' . esc_attr( $style ) . '">';
        $html .= '<ul class="dwdc-gallery__list">' . $this->items_html( $feed, $snap ) . '</ul>';
        $html .= '<p class="dwdc-gallery__footer"><button type="button" class="dwdc-gallery__more"' . ( count( $snap['items'] ) > (int) $feed['per_page'] ? '' : ' hidden' ) . '>' . esc_html__( 'Carregar mais', 'dw-dropbox-connect' ) . '</button></p>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Itens da galeria. A exibição e o endpoint de atualização usam o mesmo trecho.
     *
     * @param array<string,mixed> $feed
     * @param array<string,mixed> $snap
     * @return string
     */
    public function items_html( array $feed, array $snap ) {
        $html     = '';
        $per_page = max( 1, (int) $feed['per_page'] );
        foreach ( array_values( $snap['items'] ) as $index => $item ) {
            $thumb   = $this->feeds->image_url( $feed['id'], $item, 'thumb' );
            $large   = $this->feeds->image_url( $feed['id'], $item, 'large' );
            $caption = DWDC_Normalizer::caption_from_name( $item['name'] );
            $alt     = DWDC_Normalizer::is_camera_name( $item['name'] ) ? '' : $caption;
            $size    = '';
            if ( ! empty( $item['width'] ) && ! empty( $item['height'] ) ) {
                $size = ' width="' . (int) $item['width'] . '" height="' . (int) $item['height'] . '"';
            }
            $html .= '<li class="dwdc-gallery__item"' . ( $index >= $per_page ? ' hidden' : '' ) . '>';
            $html .= '<a class="dwdc-gallery__link" href="' . esc_url( $large ) . '" data-caption="' . esc_attr( ! empty( $feed['captions'] ) ? $caption : '' ) . '">';
            $html .= '<img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $alt ) . '"' . $size . ' loading="' . ( $index < 4 ? 'eager' : 'lazy' ) . '" decoding="async">';
            $html .= '</a>';
            if ( ! empty( $feed['captions'] ) && $caption !== '' ) {
                $html .= '<span class="dwdc-gallery__caption">' . esc_html( $caption ) . '</span>';
            }
            $html .= '</li>';
        }
        return $html;
    }

    /**
     * Mensagem só para quem administra o site. Visitantes não veem nada.
     *
     * @param string $message
     * @return string
     */
    private function admin_notice( $message ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return '';
        }
        return '<p class="dwdc-gallery-notice">' . esc_html( $message ) . '</p>';
    }

    public function routes() {
        register_rest_route(
            'dwdc/v1',
            '/gallery/(?P<feed>[a-f0-9]{8})',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_gallery' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'ver' => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => static function ( $value ) {
                            return is_string( $value ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( $value ) ) : '';
                        },
                    ),
                ),
            )
        );
        register_rest_route(
            'dwdc/v1',
            '/gallery/(?P<feed>[a-f0-9]{8})/(?P<file>[A-Za-z0-9_\-]{1,100})/(?P<size>thumb|large)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_image' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            'dwdc/v1',
            '/webhook',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'rest_webhook_challenge' ),
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'rest_webhook_notify' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }

    /**
     * Informa se a galeria mudou desde a versão que a página mostra. Serve o HTML novo quando mudou,
     * o que mantém a galeria em dia mesmo atrás de um cache de página.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_gallery( WP_REST_Request $request ) {
        $feed = $this->feeds->get( (string) $request['feed'] );
        if ( ! $feed ) {
            return new WP_Error( 'dwdc_feed_missing', __( 'Galeria não encontrada.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $snap = $this->feeds->snapshot( $feed['id'] );
        $this->feeds->maybe_schedule_refresh( $feed );
        $known = (string) $request->get_param( 'ver' );
        if ( $known !== '' && $known === $snap['version'] ) {
            return rest_ensure_response(
                array(
                    'changed' => false,
                    'version' => $snap['version'],
                )
            );
        }
        return rest_ensure_response(
            array(
                'changed' => true,
                'version' => $snap['version'],
                'total'   => count( $snap['items'] ),
                'html'    => $this->items_html( $feed, $snap ),
            )
        );
    }

    /**
     * Entrega uma imagem da galeria. Só serve arquivos que estão na galeria; o visitante nunca informa caminho.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_image( WP_REST_Request $request ) {
        $id   = (string) $request['feed'];
        $item = $this->feeds->get( $id ) ? $this->feeds->find_item( $id, (string) $request['file'] ) : null;
        if ( ! $item ) {
            return new WP_Error( 'dwdc_not_found', __( 'Imagem não encontrada.', 'dw-dropbox-connect' ), array( 'status' => 404 ) );
        }
        $file = $this->feeds->ensure_cached( $id, $item, (string) $request['size'] );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        $response = new WP_REST_Response( '', 200 );
        add_filter(
            'rest_pre_serve_request',
            static function ( $served, $result ) use ( $file, $response ) {
                if ( $result !== $response ) {
                    return $served;
                }
                foreach ( array( 'Content-Type', 'Cache-Control', 'Expires', 'Pragma', 'X-Robots-Tag' ) as $header ) {
                    if ( function_exists( 'header_remove' ) ) {
                        header_remove( $header );
                    }
                }
                header( 'Content-Type: image/jpeg' );
                header( 'Content-Length: ' . (string) filesize( $file ) );
                header( 'Cache-Control: public, max-age=31536000, immutable' );
                header( 'X-Content-Type-Options: nosniff' );
                $image = file_get_contents( $file );
                if ( is_string( $image ) ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JPEG bytes generated by this plugin, not HTML.
                    echo $image;
                }
                exit;
            },
            10,
            2
        );
        return $response;
    }

    /**
     * Verificação do endereço do webhook: o Dropbox manda um "challenge" e espera o mesmo texto de volta.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_webhook_challenge( WP_REST_Request $request ) {
        $challenge = (string) $request->get_param( 'challenge' );
        if ( $challenge === '' || strlen( $challenge ) > 200 || ! preg_match( '/^[A-Za-z0-9_\-\.]+$/', $challenge ) ) {
            return new WP_Error( 'dwdc_challenge', __( 'Desafio inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $response = new WP_REST_Response( '', 200 );
        add_filter(
            'rest_pre_serve_request',
            static function ( $served, $result ) use ( $challenge, $response ) {
                if ( $result !== $response ) {
                    return $served;
                }
                if ( function_exists( 'header_remove' ) ) {
                    header_remove( 'Content-Type' );
                }
                header( 'Content-Type: text/plain; charset=utf-8' );
                header( 'X-Content-Type-Options: nosniff' );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- restricted to [A-Za-z0-9_.-] above.
                echo $challenge;
                exit;
            },
            10,
            2
        );
        return $response;
    }

    /**
     * Aviso de mudança na conta. Confere a assinatura e agenda a sincronização das galerias.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_webhook_notify( WP_REST_Request $request ) {
        $secret = $this->oauth->app_secret();
        if ( $secret === '' ) {
            return new WP_Error( 'dwdc_webhook', __( 'Webhook indisponível.', 'dw-dropbox-connect' ), array( 'status' => 403 ) );
        }
        $given    = (string) $request->get_header( 'X-Dropbox-Signature' );
        $expected = hash_hmac( 'sha256', (string) $request->get_body(), $secret );
        if ( $given === '' || ! hash_equals( $expected, strtolower( $given ) ) ) {
            return new WP_Error( 'dwdc_webhook', __( 'Assinatura inválida.', 'dw-dropbox-connect' ), array( 'status' => 403 ) );
        }
        if ( ! get_transient( 'dwdc_webhook_seen' ) ) {
            set_transient( 'dwdc_webhook_seen', 1, 15 );
            foreach ( array_keys( $this->feeds->all() ) as $id ) {
                wp_schedule_single_event( time(), DWDC_Feeds::REFRESH_HOOK, array( $id ) );
            }
            if ( function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }
        }
        return rest_ensure_response( array( 'ok' => true ) );
    }
}
