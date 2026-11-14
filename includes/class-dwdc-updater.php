<?php
/**
 * Consulta a última release do GitHub e informa o WordPress.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Updater {
    const REPO    = 'davidwilliamcosta/dw-dropbox-connect';
    const API     = 'https://api.github.com/repos/davidwilliamcosta/dw-dropbox-connect/releases/latest';
    const PACKAGE = 'dw-dropbox-connect.zip';
    const CACHE   = 'dwdc_gh_release';

    public function __construct() {
        add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 4 );
        add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
    }

    /**
     * @param string $tag
     * @return string
     */
    public static function version_from_tag( $tag ) {
        $tag = is_string( $tag ) ? trim( $tag ) : '';
        if ( $tag !== '' && ( $tag[0] === 'v' || $tag[0] === 'V' ) ) {
            $tag = substr( $tag, 1 );
        }
        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $tag ) ) {
            return '';
        }
        return $tag;
    }

    /**
     * @param string $url
     * @return bool
     */
    public static function trusted_package( $url ) {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }
        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        $host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
        $path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
        if ( $scheme !== 'https' || $host !== 'github.com' ) {
            return false;
        }
        return (bool) preg_match(
            '#^/davidwilliamcosta/dw-dropbox-connect/releases/download/[^/]+/dw-dropbox-connect\.zip$#',
            $path
        );
    }

    /**
     * @param array<string,mixed> $release
     * @return array{version:string,package:string,notes:string,url:string}|null
     */
    public static function parse_release( array $release ) {
        $version = self::version_from_tag( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '' );
        if ( $version === '' || empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
            return null;
        }
        $package = '';
        foreach ( $release['assets'] as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }
            $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
            $url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
            if ( $name === self::PACKAGE && self::trusted_package( $url ) ) {
                $package = $url;
                break;
            }
        }
        if ( $package === '' ) {
            return null;
        }
        $notes = isset( $release['body'] ) ? (string) $release['body'] : '';
        $url   = isset( $release['html_url'] ) ? (string) $release['html_url'] : '';
        if ( ! self::trusted_release_page( $url ) ) {
            $url = 'https://github.com/' . self::REPO . '/releases';
        }
        return array(
            'version' => $version,
            'package' => $package,
            'notes'   => $notes,
            'url'     => $url,
        );
    }

    /**
     * @param mixed               $update
     * @param array<string,mixed> $plugin_data
     * @param string              $plugin_file
     * @param array<int,string>   $locales
     * @return mixed
     */
    public function check( $update, $plugin_data, $plugin_file, $locales ) {
        unset( $plugin_data, $locales );
        if ( plugin_basename( DWDC_FILE ) !== $plugin_file ) {
            return $update;
        }
        $release = $this->release();
        if ( ! is_array( $release ) ) {
            return $update;
        }
        return array(
            'slug'         => 'dw-dropbox-connect',
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'tested'       => '7.1',
            'requires'     => '6.2',
            'requires_php' => '7.4',
        );
    }

    /**
     * @param mixed        $result
     * @param string       $action
     * @param object|array $args
     * @return mixed
     */
    public function info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        $slug = is_object( $args ) && isset( $args->slug ) ? (string) $args->slug : '';
        if ( $slug !== 'dw-dropbox-connect' ) {
            return $result;
        }
        $release = $this->release();
        if ( ! is_array( $release ) ) {
            return $result;
        }
        $info                = new stdClass();
        $info->name          = 'DW Dropbox Connect';
        $info->slug          = 'dw-dropbox-connect';
        $info->version       = $release['version'];
        $info->author        = 'David William da Costa';
        $info->homepage      = 'https://github.com/' . self::REPO;
        $info->download_link = $release['package'];
        $info->requires      = '6.2';
        $info->tested        = '7.1';
        $info->requires_php  = '7.4';
        $info->sections      = array(
            'description' => wpautop( esc_html( $release['notes'] ) ),
        );
        return $info;
    }

    /**
     * @return array{version:string,package:string,notes:string,url:string}|null
     */
    private function release() {
        $cached = get_site_transient( self::CACHE );
        if ( $cached === 'none' ) {
            return null;
        }
        if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
            return $cached;
        }
        $response = wp_remote_get(
            self::API,
            array(
                'timeout' => 8,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'DW-Dropbox-Connect/' . DWDC_VERSION,
                ),
            )
        );
        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            set_site_transient( self::CACHE, 'none', 10 * MINUTE_IN_SECONDS );
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $parsed = is_array( $body ) ? self::parse_release( $body ) : null;
        if ( ! is_array( $parsed ) ) {
            set_site_transient( self::CACHE, 'none', 10 * MINUTE_IN_SECONDS );
            return null;
        }
        set_site_transient( self::CACHE, $parsed, HOUR_IN_SECONDS );
        return $parsed;
    }

    /**
     * @param string $url
     * @return bool
     */
    private static function trusted_release_page( $url ) {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }
        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        $host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
        $path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
        return $scheme === 'https'
            && $host === 'github.com'
            && strpos( $path, '/davidwilliamcosta/dw-dropbox-connect/releases' ) === 0;
    }
}
