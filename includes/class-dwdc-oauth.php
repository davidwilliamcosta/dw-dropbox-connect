<?php
/**
 * OAuth 2.0 com refresh token. Os segredos ficam cifrados nas options.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_OAuth {
    const OPTION_SETTINGS = 'dwdc_settings';
    const OPTION_TOKENS   = 'dwdc_oauth';
    const SCOPES          = 'account_info.read files.metadata.read files.content.read files.content.write';

    /**
     * @return string
     */
    public function redirect_uri() {
        return admin_url( 'admin-post.php?action=dwdc_oauth_callback' );
    }

    /**
     * @return string
     */
    public function app_key() {
        return $this->constant_or_setting( array( 'DWDC_APP_KEY', 'WPDM_APP_KEY' ), 'app_key' );
    }

    /**
     * @return string
     */
    public function app_secret() {
        $constant = $this->first_constant( array( 'DWDC_APP_SECRET', 'WPDM_APP_SECRET' ) );
        if ( $constant !== '' ) {
            return $constant;
        }
        $settings = $this->settings();
        $stored   = isset( $settings['app_secret'] ) ? (string) $settings['app_secret'] : '';
        if ( $stored === '' ) {
            return '';
        }
        return DWDC_Crypto::decrypt( $stored, $this->key() );
    }

    /**
     * @return bool
     */
    public function uses_constants() {
        return $this->first_constant( array( 'DWDC_APP_KEY', 'WPDM_APP_KEY' ) ) !== ''
            || $this->first_constant( array( 'DWDC_APP_SECRET', 'WPDM_APP_SECRET' ) ) !== '';
    }

    /**
     * @return true|WP_Error
     */
    public function credentials_ready() {
        $key_const = $this->first_constant( array( 'DWDC_APP_KEY', 'WPDM_APP_KEY' ) ) !== '';
        $secret_const = $this->first_constant( array( 'DWDC_APP_SECRET', 'WPDM_APP_SECRET' ) ) !== '';
        if ( $key_const !== $secret_const ) {
            return new WP_Error(
                'dwdc_credentials',
                __( 'Defina DWDC_APP_KEY e DWDC_APP_SECRET juntos no wp-config.php.', 'dw-dropbox-connect' )
            );
        }
        $key = $this->app_key();
        $secret = $this->app_secret();
        if ( ! self::valid_app_key( $key ) || ! self::valid_app_secret( $secret ) ) {
            return new WP_Error(
                'dwdc_credentials',
                __( 'Informe a App key e o App secret do Dropbox antes de conectar.', 'dw-dropbox-connect' )
            );
        }
        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function valid_app_key( $key ) {
        return is_string( $key ) && (bool) preg_match( '/^[A-Za-z0-9_-]{6,128}$/', $key );
    }

    /**
     * @param string $secret
     * @return bool
     */
    public static function valid_app_secret( $secret ) {
        return is_string( $secret )
            && strlen( $secret ) >= 8
            && strlen( $secret ) <= 256
            && ! preg_match( '/[[:space:][:cntrl:]]/', $secret );
    }

    /**
     * @param string $app_key
     * @param string $app_secret Vazio mantém o segredo já salvo.
     * @return true|WP_Error
     */
    public function save_credentials( $app_key, $app_secret ) {
        if ( $this->uses_constants() ) {
            return new WP_Error(
                'dwdc_credentials',
                __( 'As credenciais estão definidas no wp-config.php e não são gravadas pelo formulário.', 'dw-dropbox-connect' )
            );
        }
        $app_key = trim( (string) $app_key );
        $app_secret = (string) $app_secret;
        if ( $app_key === '' && $app_secret === '' ) {
            delete_option( self::OPTION_SETTINGS );
            $this->disconnect();
            return true;
        }
        if ( ! self::valid_app_key( $app_key ) ) {
            return new WP_Error( 'dwdc_credentials', __( 'A App key do Dropbox é inválida.', 'dw-dropbox-connect' ) );
        }
        $previous = $this->settings();
        $previous_key = isset( $previous['app_key'] ) ? (string) $previous['app_key'] : '';
        if ( $app_secret === '' ) {
            $stored_secret = isset( $previous['app_secret'] ) ? (string) $previous['app_secret'] : '';
            if ( $stored_secret === '' || DWDC_Crypto::decrypt( $stored_secret, $this->key() ) === '' ) {
                return new WP_Error( 'dwdc_credentials', __( 'Informe o App secret do Dropbox.', 'dw-dropbox-connect' ) );
            }
        } else {
            if ( ! self::valid_app_secret( $app_secret ) ) {
                return new WP_Error( 'dwdc_credentials', __( 'O App secret do Dropbox é inválido.', 'dw-dropbox-connect' ) );
            }
            $stored_secret = DWDC_Crypto::encrypt( $app_secret, $this->key() );
            if ( ! is_string( $stored_secret ) || $stored_secret === '' ) {
                return new WP_Error( 'dwdc_crypto', __( 'Não foi possível cifrar o App secret. A extensão OpenSSL é obrigatória.', 'dw-dropbox-connect' ) );
            }
        }
        update_option(
            self::OPTION_SETTINGS,
            array(
                'app_key'    => $app_key,
                'app_secret' => $stored_secret,
            ),
            false
        );
        if ( $previous_key !== '' && ! hash_equals( $previous_key, $app_key ) ) {
            $this->disconnect();
        }
        return true;
    }

    /**
     * @return bool
     */
    public function has_saved_secret() {
        if ( $this->first_constant( array( 'DWDC_APP_SECRET', 'WPDM_APP_SECRET' ) ) !== '' ) {
            return true;
        }
        return $this->app_secret() !== '';
    }

    /**
     * @return string|WP_Error
     */
    public function authorization_url() {
        $ready = $this->credentials_ready();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        $state = wp_generate_password( 43, false, false );
        set_transient( $this->state_key(), $state, 10 * MINUTE_IN_SECONDS );
        return add_query_arg(
            array(
                'client_id'         => $this->app_key(),
                'response_type'     => 'code',
                'token_access_type' => 'offline',
                'redirect_uri'      => $this->redirect_uri(),
                'state'             => $state,
                'scope'             => self::SCOPES,
            ),
            'https://www.dropbox.com/oauth2/authorize'
        );
    }

    /**
     * @param array<string,mixed> $query
     * @return true|WP_Error
     */
    public function handle_callback( array $query ) {
        $expected = get_transient( $this->state_key() );
        delete_transient( $this->state_key() );
        $state = isset( $query['state'] ) ? sanitize_text_field( wp_unslash( $query['state'] ) ) : '';
        if ( ! is_string( $expected ) || $expected === '' || $state === '' || ! hash_equals( $expected, $state ) ) {
            return new WP_Error( 'dwdc_state', __( 'A validação OAuth falhou. Conecte novamente.', 'dw-dropbox-connect' ) );
        }
        if ( ! empty( $query['error'] ) || empty( $query['code'] ) ) {
            return new WP_Error( 'dwdc_oauth', __( 'A autorização do Dropbox não foi concluída.', 'dw-dropbox-connect' ) );
        }
        $ready = $this->credentials_ready();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        $response = wp_remote_post(
            'https://api.dropbox.com/oauth2/token',
            array(
                'timeout' => 20,
                'body'    => array(
                    'code'          => sanitize_text_field( wp_unslash( $query['code'] ) ),
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $this->app_key(),
                    'client_secret' => $this->app_secret(),
                    'redirect_uri'  => $this->redirect_uri(),
                ),
            )
        );
        $token = $this->decode_token_response( $response );
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        return $this->store_token( $token, '' );
    }

    /**
     * @return string|WP_Error
     */
    public function access_token() {
        $stored = $this->tokens();
        if ( $stored['refresh_token'] === '' ) {
            return new WP_Error(
                'dwdc_disconnected',
                __( 'Conecte uma conta do Dropbox em Configurações → Dropbox.', 'dw-dropbox-connect' ),
                array( 'status' => 401 )
            );
        }
        if ( $stored['access_token'] !== '' && $stored['expires'] > time() + 30 ) {
            return $stored['access_token'];
        }
        $ready = $this->credentials_ready();
        if ( is_wp_error( $ready ) ) {
            $ready->add_data( array( 'status' => 401 ) );
            return $ready;
        }
        $response = wp_remote_post(
            'https://api.dropbox.com/oauth2/token',
            array(
                'timeout' => 20,
                'body'    => array(
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $stored['refresh_token'],
                    'client_id'     => $this->app_key(),
                    'client_secret' => $this->app_secret(),
                ),
            )
        );
        $token = $this->decode_token_response( $response );
        if ( is_wp_error( $token ) ) {
            $token->add_data( array( 'status' => 401 ) );
            return $token;
        }
        $saved = $this->store_token( $token, $stored['refresh_token'] );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        $fresh = $this->tokens();
        return $fresh['access_token'] !== '' ? $fresh['access_token'] : new WP_Error( 'dwdc_token', __( 'O Dropbox não devolveu um token de acesso.', 'dw-dropbox-connect' ), array( 'status' => 401 ) );
    }

    /**
     * @return bool
     */
    public function connected() {
        return $this->tokens()['refresh_token'] !== '';
    }

    /**
     * @return string
     */
    public function account_label() {
        $tokens = $this->tokens();
        return $tokens['account'];
    }

    public function disconnect() {
        delete_option( self::OPTION_TOKENS );
        delete_transient( $this->state_key() );
    }

    /**
     * Copia a configuração antiga do wp-dropbox-media, se existir.
     */
    public function migrate_legacy_options() {
        if ( get_option( 'dwdc_migrated_wpdm' ) ) {
            return;
        }
        $legacy_settings = get_option( 'wpdm_settings', null );
        if ( is_array( $legacy_settings ) && ! get_option( self::OPTION_SETTINGS, null ) ) {
            $key = isset( $legacy_settings['app_key'] ) ? sanitize_text_field( (string) $legacy_settings['app_key'] ) : '';
            $secret = isset( $legacy_settings['app_secret'] ) ? (string) $legacy_settings['app_secret'] : '';
            $stored_secret = '';
            if ( $secret !== '' && self::valid_app_secret( $secret ) ) {
                $encrypted = DWDC_Crypto::encrypt( $secret, $this->key() );
                $stored_secret = is_string( $encrypted ) ? $encrypted : '';
            }
            if ( self::valid_app_key( $key ) && $stored_secret !== '' ) {
                update_option(
                    self::OPTION_SETTINGS,
                    array(
                        'app_key'    => $key,
                        'app_secret' => $stored_secret,
                    ),
                    false
                );
            }
        }
        $legacy_tokens = get_option( 'wpdm_oauth', null );
        if ( is_array( $legacy_tokens ) && ! get_option( self::OPTION_TOKENS, null ) ) {
            $legacy_key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
            $access     = DWDC_Crypto::decrypt( isset( $legacy_tokens['access_token'] ) ? (string) $legacy_tokens['access_token'] : '', $legacy_key );
            $refresh    = DWDC_Crypto::decrypt( isset( $legacy_tokens['refresh_token'] ) ? (string) $legacy_tokens['refresh_token'] : '', $legacy_key );
            $refresh_stored = $refresh !== '' ? DWDC_Crypto::encrypt( $refresh, $this->key() ) : false;
            if ( is_string( $refresh_stored ) && $refresh_stored !== '' ) {
                $access_stored = $access !== '' ? DWDC_Crypto::encrypt( $access, $this->key() ) : '';
                update_option(
                    self::OPTION_TOKENS,
                    array(
                        'access_token'  => is_string( $access_stored ) ? $access_stored : '',
                        'refresh_token' => $refresh_stored,
                        'expires'       => isset( $legacy_tokens['expires'] ) ? (int) $legacy_tokens['expires'] : 0,
                        'account'       => isset( $legacy_tokens['account'] ) ? sanitize_text_field( (string) $legacy_tokens['account'] ) : '',
                    ),
                    false
                );
            }
        }
        delete_option( 'wpdm_settings' );
        delete_option( 'wpdm_oauth' );
        update_option( 'dwdc_migrated_wpdm', 1, false );
    }

    /**
     * @param array<string,mixed> $token
     * @param string              $previous_refresh
     * @return true|WP_Error
     */
    private function store_token( array $token, $previous_refresh ) {
        if ( empty( $token['access_token'] ) || empty( $token['expires_in'] ) ) {
            return new WP_Error( 'dwdc_token', __( 'A resposta de token do Dropbox está incompleta.', 'dw-dropbox-connect' ) );
        }
        $refresh = ! empty( $token['refresh_token'] ) ? (string) $token['refresh_token'] : (string) $previous_refresh;
        if ( $refresh === '' ) {
            return new WP_Error( 'dwdc_token', __( 'O Dropbox não devolveu um refresh token. Revogue o acesso do app e conecte de novo.', 'dw-dropbox-connect' ) );
        }
        $access = DWDC_Crypto::encrypt( (string) $token['access_token'], $this->key() );
        $refresh_stored = DWDC_Crypto::encrypt( $refresh, $this->key() );
        if ( ! is_string( $access ) || ! is_string( $refresh_stored ) ) {
            return new WP_Error( 'dwdc_crypto', __( 'Não foi possível cifrar os tokens do Dropbox.', 'dw-dropbox-connect' ) );
        }
        $account = $this->account_name( (string) $token['access_token'] );
        update_option(
            self::OPTION_TOKENS,
            array(
                'access_token'  => $access,
                'refresh_token' => $refresh_stored,
                'expires'       => time() + (int) $token['expires_in'] - 120,
                'account'       => is_wp_error( $account ) ? '' : $account,
            ),
            false
        );
        return true;
    }

    /**
     * @param string $access
     * @return string|WP_Error
     */
    private function account_name( $access ) {
        $response = wp_remote_post(
            'https://api.dropboxapi.com/2/users/get_current_account',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access,
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return '';
        }
        $name = isset( $body['name']['display_name'] ) ? sanitize_text_field( (string) $body['name']['display_name'] ) : '';
        return $name;
    }

    /**
     * @param mixed $response
     * @return array<string,mixed>|WP_Error
     */
    private function decode_token_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dwdc_http', __( 'Não foi possível falar com o Dropbox.', 'dw-dropbox-connect' ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
            return new WP_Error( 'dwdc_http', __( 'O Dropbox recusou a troca do token. Confira o app, o redirect URI e as permissões.', 'dw-dropbox-connect' ) );
        }
        return $body;
    }

    /**
     * @return array{access_token:string,refresh_token:string,expires:int,account:string}
     */
    private function tokens() {
        $stored = get_option( self::OPTION_TOKENS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return array(
            'access_token'  => DWDC_Crypto::decrypt( isset( $stored['access_token'] ) ? (string) $stored['access_token'] : '', $this->key() ),
            'refresh_token' => DWDC_Crypto::decrypt( isset( $stored['refresh_token'] ) ? (string) $stored['refresh_token'] : '', $this->key() ),
            'expires'       => isset( $stored['expires'] ) ? (int) $stored['expires'] : 0,
            'account'       => isset( $stored['account'] ) ? sanitize_text_field( (string) $stored['account'] ) : '',
        );
    }

    /**
     * @return array<string,string>
     */
    private function settings() {
        $stored = get_option( self::OPTION_SETTINGS, array() );
        return is_array( $stored ) ? $stored : array();
    }

    /**
     * @return string
     */
    private function key() {
        return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|dwdc', true );
    }

    /**
     * @return string
     */
    private function state_key() {
        return 'dwdc_oauth_state_' . get_current_user_id();
    }

    /**
     * @param array<int,string> $constants
     * @param string            $setting
     * @return string
     */
    private function constant_or_setting( array $constants, $setting ) {
        $constant = $this->first_constant( $constants );
        if ( $constant !== '' ) {
            return $constant;
        }
        $settings = $this->settings();
        return isset( $settings[ $setting ] ) ? (string) $settings[ $setting ] : '';
    }

    /**
     * @param array<int,string> $constants
     * @return string
     */
    private function first_constant( array $constants ) {
        foreach ( $constants as $name ) {
            if ( defined( $name ) ) {
                $value = constant( $name );
                if ( is_string( $value ) && $value !== '' ) {
                    return $value;
                }
            }
        }
        return '';
    }
}
