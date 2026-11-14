<?php
/**
 * Criptografia local dos segredos do Dropbox.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DWDC_TESTING' ) ) {
    exit;
}

class DWDC_Crypto {
    /**
     * @param string $plaintext
     * @param string $key 32 bytes.
     * @return string|false
     */
    public static function encrypt( $plaintext, $key ) {
        if ( ! is_string( $plaintext ) || ! is_string( $key ) || strlen( $key ) !== 32 ) {
            return false;
        }
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
            return false;
        }
        $iv = random_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( $cipher === false ) {
            return false;
        }
        $mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
        return 'v2:' . base64_encode( $mac . $iv . $cipher );
    }

    /**
     * @param string $encoded
     * @param string $key 32 bytes.
     * @return string
     */
    public static function decrypt( $encoded, $key ) {
        if ( ! is_string( $encoded ) || $encoded === '' || ! is_string( $key ) || strlen( $key ) !== 32 ) {
            return '';
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        if ( strpos( $encoded, 'v2:' ) === 0 ) {
            $raw = base64_decode( substr( $encoded, 3 ), true );
            if ( ! is_string( $raw ) || strlen( $raw ) < 49 ) {
                return '';
            }
            $mac    = substr( $raw, 0, 32 );
            $iv     = substr( $raw, 32, 16 );
            $cipher = substr( $raw, 48 );
            $expected = hash_hmac( 'sha256', $iv . $cipher, $key, true );
            if ( ! hash_equals( $expected, $mac ) ) {
                return '';
            }
            $plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            return is_string( $plain ) ? $plain : '';
        }

        $raw = base64_decode( $encoded, true );
        if ( ! is_string( $raw ) || strlen( $raw ) < 17 ) {
            return '';
        }
        $plain = openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
        return is_string( $plain ) ? $plain : '';
    }
}
