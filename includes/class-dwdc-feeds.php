<?php
/**
 * Galerias (feeds) de pastas do Dropbox: configuração, sincronização e cache local das imagens.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Feeds {
    const OPTION        = 'dwdc_feeds';
    const CRON_HOOK     = 'dwdc_refresh_feeds';
    const REFRESH_HOOK  = 'dwdc_refresh_feed';
    const PRIME_HOOK    = 'dwdc_prime_feed';
    const CACHE_DIRNAME = 'dw-dropbox-connect-gallery';

    /** @var DWDC_Api */
    private $api;

    /**
     * @param DWDC_Api $api
     */
    public function __construct( DWDC_Api $api ) {
        $this->api = $api;
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( 'init', array( $this, 'ensure_schedule' ) );
        add_action( self::CRON_HOOK, array( $this, 'refresh_due' ) );
        add_action( self::REFRESH_HOOK, array( $this, 'refresh_one' ) );
        add_action( self::PRIME_HOOK, array( $this, 'prime' ) );
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaults() {
        return array(
            'id'        => '',
            'name'      => '',
            'path'      => '',
            'recursive' => false,
            'order'     => 'newest',
            'limit'     => 60,
            'per_page'  => 12,
            'layout'    => 'grid',
            'ratio'     => 'square',
            'columns'   => 4,
            'columns_md' => 3,
            'columns_sm' => 2,
            'gap'       => 8,
            'lightbox'  => true,
            'captions'  => false,
            'refresh'   => 30,
        );
    }

    /**
     * @return array<string,string>
     */
    public static function sizes() {
        return array(
            'thumb' => 'w640h480',
            'large' => 'w2048h1536',
        );
    }

    /**
     * @param array<string,mixed> $schedules
     * @return array<string,mixed>
     */
    public function cron_schedules( $schedules ) {
        if ( ! isset( $schedules['dwdc_five_minutes'] ) ) {
            $schedules['dwdc_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'A cada 5 minutos', 'dw-dropbox-connect' ),
            );
        }
        return $schedules;
    }

    public function ensure_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'dwdc_five_minutes', self::CRON_HOOK );
        }
    }

    public static function clear_schedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::REFRESH_HOOK );
        wp_clear_scheduled_hook( self::PRIME_HOOK );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all() {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            return array();
        }
        $feeds = array();
        foreach ( $stored as $id => $feed ) {
            if ( ! is_string( $id ) || ! self::valid_id( $id ) || ! is_array( $feed ) ) {
                continue;
            }
            $feeds[ $id ] = array_merge( self::defaults(), $feed, array( 'id' => $id ) );
        }
        return $feeds;
    }

    /**
     * @param string $id
     * @return array<string,mixed>|null
     */
    public function get( $id ) {
        if ( ! is_string( $id ) || ! self::valid_id( $id ) ) {
            return null;
        }
        $all = $this->all();
        return isset( $all[ $id ] ) ? $all[ $id ] : null;
    }

    /**
     * @param string $id
     * @return bool
     */
    public static function valid_id( $id ) {
        return is_string( $id ) && (bool) preg_match( '/^[a-f0-9]{8}$/', $id );
    }

    /**
     * Valida os dados do formulário e devolve a galeria pronta para salvar.
     *
     * @param array<string,mixed> $raw
     * @param string              $id  Vazio para uma galeria nova.
     * @return array<string,mixed>|WP_Error
     */
    public function sanitize( array $raw, $id = '' ) {
        foreach ( $raw as $field => $value ) {
            if ( ! is_scalar( $value ) ) {
                unset( $raw[ $field ] );
            }
        }
        $d    = self::defaults();
        $path = DWDC_Normalizer::sanitize_path( isset( $raw['path'] ) ? wp_unslash( (string) $raw['path'] ) : '' );
        if ( $path === null || $path === '' ) {
            return new WP_Error( 'dwdc_feed_path', __( 'Escolha uma pasta do Dropbox. A raiz da conta não pode ser usada.', 'dw-dropbox-connect' ) );
        }
        $name = isset( $raw['name'] ) ? sanitize_text_field( wp_unslash( (string) $raw['name'] ) ) : '';
        if ( $name === '' ) {
            $name = basename( $path );
        }
        $order = isset( $raw['order'] ) ? (string) $raw['order'] : $d['order'];
        if ( ! in_array( $order, array( 'newest', 'oldest', 'name', 'name_desc' ), true ) ) {
            $order = $d['order'];
        }
        $layout = isset( $raw['layout'] ) ? (string) $raw['layout'] : $d['layout'];
        if ( ! in_array( $layout, array( 'grid', 'masonry' ), true ) ) {
            $layout = $d['layout'];
        }
        $ratio = isset( $raw['ratio'] ) ? (string) $raw['ratio'] : $d['ratio'];
        if ( ! in_array( $ratio, array( 'square', 'original' ), true ) ) {
            $ratio = $d['ratio'];
        }
        $refresh = isset( $raw['refresh'] ) ? (int) $raw['refresh'] : $d['refresh'];
        if ( ! in_array( $refresh, array( 5, 15, 30, 60, 360, 1440 ), true ) ) {
            $refresh = $d['refresh'];
        }
        if ( ! self::valid_id( $id ) ) {
            $id = bin2hex( random_bytes( 4 ) );
        }
        $limit = DWDC_Normalizer::clamp_int( isset( $raw['limit'] ) ? $raw['limit'] : null, 1, 500, $d['limit'] );
        return array(
            'id'         => $id,
            'name'       => $name,
            'path'       => $path,
            'recursive'  => ! empty( $raw['recursive'] ),
            'order'      => $order,
            'limit'      => $limit,
            'per_page'   => min( $limit, DWDC_Normalizer::clamp_int( isset( $raw['per_page'] ) ? $raw['per_page'] : null, 1, 200, $d['per_page'] ) ),
            'layout'     => $layout,
            'ratio'      => $ratio,
            'columns'    => DWDC_Normalizer::clamp_int( isset( $raw['columns'] ) ? $raw['columns'] : null, 1, 8, $d['columns'] ),
            'columns_md' => DWDC_Normalizer::clamp_int( isset( $raw['columns_md'] ) ? $raw['columns_md'] : null, 1, 6, $d['columns_md'] ),
            'columns_sm' => DWDC_Normalizer::clamp_int( isset( $raw['columns_sm'] ) ? $raw['columns_sm'] : null, 1, 4, $d['columns_sm'] ),
            'gap'        => DWDC_Normalizer::clamp_int( isset( $raw['gap'] ) ? $raw['gap'] : null, 0, 48, $d['gap'] ),
            'lightbox'   => ! empty( $raw['lightbox'] ),
            'captions'   => ! empty( $raw['captions'] ),
            'refresh'    => $refresh,
        );
    }

    /**
     * @param array<string,mixed> $feed Já passou por sanitize().
     */
    public function save( array $feed ) {
        $all                 = $this->all();
        $all[ $feed['id'] ]  = $feed;
        update_option( self::OPTION, $all, false );
    }

    /**
     * @param string $id
     */
    public function delete( $id ) {
        if ( ! self::valid_id( $id ) ) {
            return;
        }
        $all = $this->all();
        unset( $all[ $id ] );
        update_option( self::OPTION, $all, false );
        delete_option( $this->snapshot_option( $id ) );
        $this->remove_cache_dir( $id );
    }

    /**
     * @param string $id
     * @return array<string,mixed>
     */
    public function snapshot( $id ) {
        $data = self::valid_id( $id ) ? get_option( $this->snapshot_option( $id ), null ) : null;
        $base = array(
            'items'        => array(),
            'synced_at'    => 0,
            'attempted_at' => 0,
            'error'        => '',
            'truncated'    => false,
            'version'      => '',
        );
        return is_array( $data ) ? array_merge( $base, $data ) : $base;
    }

    /**
     * Sincroniza a galeria com a pasta do Dropbox. Em caso de falha, mantém o último conjunto bom.
     *
     * @param string $id
     * @return array<string,mixed>|WP_Error O snapshot salvo.
     */
    public function sync( $id ) {
        $feed = $this->get( $id );
        if ( ! $feed ) {
            return new WP_Error( 'dwdc_feed_missing', __( 'Galeria não encontrada.', 'dw-dropbox-connect' ) );
        }
        $lock = 'dwdc_sync_' . $id;
        if ( get_transient( $lock ) ) {
            return new WP_Error( 'dwdc_feed_busy', __( 'Esta galeria já está sendo sincronizada.', 'dw-dropbox-connect' ) );
        }
        set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- listing a large folder can outlast the default PHP timer.
            set_time_limit( 120 );
        }
        $previous = $this->snapshot( $id );
        $listing  = $this->api->list_images( $feed['path'], ! empty( $feed['recursive'] ) );
        if ( is_wp_error( $listing ) ) {
            $previous['attempted_at'] = time();
            $previous['error']        = $listing->get_error_message();
            update_option( $this->snapshot_option( $id ), $previous, false );
            delete_transient( $lock );
            return $listing;
        }
        $items   = DWDC_Normalizer::sort_items( $listing['items'], (string) $feed['order'] );
        $items   = array_slice( $items, 0, (int) $feed['limit'] );
        $version = $this->version_of( $items );
        $snap    = array(
            'items'        => $items,
            'synced_at'    => time(),
            'attempted_at' => time(),
            'error'        => '',
            'truncated'    => (bool) $listing['truncated'],
            'version'      => $version,
        );
        update_option( $this->snapshot_option( $id ), $snap, false );
        delete_transient( $lock );

        if ( $version !== $previous['version'] ) {
            $this->cleanup_cache( $id, $items );
            wp_schedule_single_event( time() + 5, self::PRIME_HOOK, array( $id ) );
            /**
             * Disparado quando o conjunto de imagens de uma galeria mudou.
             * Plugins de cache de página podem limpar as páginas que usam a galeria.
             *
             * @param string $id   ID da galeria.
             * @param array  $snap Snapshot salvo.
             */
            do_action( 'dwdc_feed_changed', $id, $snap );
        }
        return $snap;
    }

    /**
     * Rotina do cron: sincroniza as galerias cujo intervalo venceu.
     */
    public function refresh_due() {
        foreach ( $this->all() as $id => $feed ) {
            if ( $this->is_due( $feed ) ) {
                $this->sync( $id );
            }
        }
    }

    /**
     * @param string $id
     */
    public function refresh_one( $id ) {
        if ( is_string( $id ) ) {
            $this->sync( $id );
        }
    }

    /**
     * @param array<string,mixed> $feed
     * @return bool
     */
    public function is_due( array $feed ) {
        $snap     = $this->snapshot( $feed['id'] );
        $interval = max( 5, (int) $feed['refresh'] ) * MINUTE_IN_SECONDS;
        return ( time() - (int) $snap['attempted_at'] ) >= $interval;
    }

    /**
     * Agenda uma sincronização em segundo plano quando o conjunto está vencido.
     * Chamado na exibição, que nunca fala com o Dropbox.
     *
     * @param array<string,mixed> $feed
     */
    public function maybe_schedule_refresh( array $feed ) {
        if ( $this->is_due( $feed ) && ! get_transient( 'dwdc_sync_' . $feed['id'] ) ) {
            wp_schedule_single_event( time(), self::REFRESH_HOOK, array( $feed['id'] ) );
        }
    }

    /**
     * Gera em segundo plano as miniaturas que ainda faltam, em lotes curtos.
     *
     * @param string $id
     */
    public function prime( $id ) {
        if ( ! is_string( $id ) || ! $this->get( $id ) ) {
            return;
        }
        $snap      = $this->snapshot( $id );
        $generated = 0;
        $pending   = false;
        foreach ( $snap['items'] as $item ) {
            if ( is_file( $this->cache_file( $id, $item, 'thumb' ) ) ) {
                continue;
            }
            if ( $generated >= 12 ) {
                $pending = true;
                break;
            }
            $result = $this->ensure_cached( $id, $item, 'thumb' );
            if ( is_wp_error( $result ) ) {
                $pending = false;
                break;
            }
            ++$generated;
        }
        if ( $pending ) {
            wp_schedule_single_event( time() + 20, self::PRIME_HOOK, array( $id ) );
        }
    }

    /**
     * @param string               $id
     * @param string               $key Parte do ID do arquivo depois de "id:".
     * @return array<string,mixed>|null
     */
    public function find_item( $id, $key ) {
        $snap = $this->snapshot( $id );
        foreach ( $snap['items'] as $item ) {
            if ( isset( $item['key'] ) && $item['key'] === $key ) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Garante que o JPEG do tamanho pedido exista no cache local.
     *
     * @param string              $id
     * @param array<string,mixed> $item
     * @param string              $size thumb ou large.
     * @return string|WP_Error Caminho do arquivo.
     */
    public function ensure_cached( $id, array $item, $size ) {
        $sizes = self::sizes();
        if ( ! isset( $sizes[ $size ] ) ) {
            return new WP_Error( 'dwdc_size', __( 'Tamanho inválido.', 'dw-dropbox-connect' ), array( 'status' => 400 ) );
        }
        $file = $this->cache_file( $id, $item, $size );
        if ( is_file( $file ) && filesize( $file ) > 0 ) {
            return $file;
        }
        $dir = $this->cache_dir( $id, true );
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }
        $lock = 'dwdc_gen_' . md5( $file );
        if ( get_transient( $lock ) ) {
            for ( $i = 0; $i < 12; $i++ ) {
                usleep( 250000 );
                if ( is_file( $file ) && filesize( $file ) > 0 ) {
                    return $file;
                }
            }
            return new WP_Error( 'dwdc_busy', __( 'A imagem ainda está sendo preparada.', 'dw-dropbox-connect' ), array( 'status' => 503 ) );
        }
        set_transient( $lock, 1, 45 );
        $body = $this->api->thumbnail_bytes( $item['path'], $sizes[ $size ] );
        if ( is_wp_error( $body ) ) {
            delete_transient( $lock );
            return $body;
        }
        $written = file_put_contents( $file, $body, LOCK_EX );
        delete_transient( $lock );
        if ( $written === false ) {
            return new WP_Error( 'dwdc_cache', __( 'Não foi possível guardar a imagem em cache.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        return $file;
    }

    /**
     * URL pública da imagem: o arquivo estático quando já está em cache, ou o endpoint que o gera.
     *
     * @param string              $id
     * @param array<string,mixed> $item
     * @param string              $size thumb ou large.
     * @return string
     */
    public function image_url( $id, array $item, $size ) {
        $file = $this->cache_file( $id, $item, $size );
        if ( is_file( $file ) ) {
            $uploads = wp_upload_dir();
            if ( empty( $uploads['error'] ) ) {
                return trailingslashit( $uploads['baseurl'] ) . self::CACHE_DIRNAME . '/' . $id . '/' . basename( $file );
            }
        }
        return add_query_arg(
            'v',
            substr( (string) $item['hash'], 0, 10 ),
            rest_url( 'dwdc/v1/gallery/' . $id . '/' . $item['key'] . '/' . $size )
        );
    }

    /**
     * @param string              $id
     * @param array<string,mixed> $item
     * @param string              $size
     * @return string
     */
    public function cache_file( $id, array $item, $size ) {
        $dir  = $this->cache_dir( $id, false );
        $name = hash_hmac( 'sha256', $item['id'] . '|' . $item['hash'] . '|' . $item['time'] . '|' . $size, wp_salt( 'auth' ) ) . '.jpg';
        return ( is_string( $dir ) ? $dir : '' ) . '/' . $name;
    }

    /**
     * @param string $id
     * @param bool   $create
     * @return string|WP_Error
     */
    private function cache_dir( $id, $create ) {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'dwdc_uploads', __( 'O diretório de uploads do WordPress não está disponível.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        $root = trailingslashit( $uploads['basedir'] ) . self::CACHE_DIRNAME;
        $dir  = $root . '/' . $id;
        if ( ! $create ) {
            return $dir;
        }
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'dwdc_uploads', __( 'Não foi possível criar o cache da galeria.', 'dw-dropbox-connect' ), array( 'status' => 500 ) );
        }
        foreach ( array( $root, $dir ) as $protect ) {
            if ( ! is_file( $protect . '/index.php' ) ) {
                file_put_contents( $protect . '/index.php', "<?php\n// Silence is golden.\n" );
            }
        }
        if ( ! is_file( $root . '/.htaccess' ) ) {
            file_put_contents( $root . '/.htaccess', "Options -Indexes\n" );
        }
        return $dir;
    }

    /**
     * Apaga do cache o que saiu da pasta ou foi substituído no Dropbox.
     *
     * @param string                         $id
     * @param array<int,array<string,mixed>> $items
     */
    private function cleanup_cache( $id, array $items ) {
        $dir = $this->cache_dir( $id, false );
        if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
            return;
        }
        $keep = array();
        foreach ( $items as $item ) {
            foreach ( array_keys( self::sizes() ) as $size ) {
                $keep[ basename( $this->cache_file( $id, $item, $size ) ) ] = true;
            }
        }
        $found = scandir( $dir );
        if ( ! is_array( $found ) ) {
            return;
        }
        foreach ( $found as $name ) {
            if ( preg_match( '/^[a-f0-9]{64}\.jpg$/', $name ) && ! isset( $keep[ $name ] ) ) {
                wp_delete_file( $dir . '/' . $name );
            }
        }
    }

    /**
     * @param string $id
     */
    private function remove_cache_dir( $id ) {
        $dir = $this->cache_dir( $id, false );
        if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
            return;
        }
        $found = scandir( $dir );
        if ( is_array( $found ) ) {
            foreach ( $found as $name ) {
                if ( preg_match( '/^([a-f0-9]{64}\.jpg|index\.php)$/', $name ) ) {
                    wp_delete_file( $dir . '/' . $name );
                }
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty directory created by this plugin.
        @rmdir( $dir );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return string
     */
    private function version_of( array $items ) {
        $parts = array();
        foreach ( $items as $item ) {
            $parts[] = $item['id'] . ':' . $item['hash'] . ':' . $item['time'] . ':' . $item['name'];
        }
        return md5( implode( '|', $parts ) );
    }

    /**
     * @param string $id
     * @return string
     */
    private function snapshot_option( $id ) {
        return 'dwdc_feed_' . $id;
    }
}
