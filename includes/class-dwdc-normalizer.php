<?php
/**
 * Normaliza caminhos e respostas do Dropbox sem depender do WordPress.
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DWDC_Normalizer {
    /**
     * @return array<string,string>
     */
    public static function mime_map() {
        return array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
            'tif'  => 'image/tiff',
            'tiff' => 'image/tiff',
            'svg'  => 'image/svg+xml',
            'mp3'  => 'audio/mpeg',
            'm4a'  => 'audio/mp4',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'mp4'  => 'video/mp4',
            'm4v'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'webm' => 'video/webm',
            'pdf'  => 'application/pdf',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip'  => 'application/zip',
        );
    }

    /**
     * @param string $name
     * @return string
     */
    public static function extension_from_name( $name ) {
        $name = str_replace( "\0", '', (string) $name );
        $base = basename( str_replace( '\\', '/', $name ) );
        $dot  = strrpos( $base, '.' );
        if ( $dot === false || $dot === strlen( $base ) - 1 ) {
            return '';
        }
        return strtolower( substr( $base, $dot + 1 ) );
    }

    /**
     * @param string $name
     * @return string
     */
    public static function mime_from_name( $name ) {
        $ext  = self::extension_from_name( $name );
        $map  = self::mime_map();
        return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function is_thumbnailable( $name ) {
        return in_array( self::extension_from_name( $name ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp' ), true );
    }

    /**
     * @param mixed $path
     * @return string|null '' é a raiz. null é inválido.
     */
    public static function sanitize_path( $path ) {
        if ( ! is_string( $path ) ) {
            return null;
        }
        if ( strpos( $path, "\0" ) !== false ) {
            return null;
        }
        $path = trim( str_replace( '\\', '/', $path ) );
        if ( strlen( $path ) > 4096 ) {
            return null;
        }
        if ( $path === '' || $path === '/' ) {
            return '';
        }
        if ( $path[0] !== '/' ) {
            return null;
        }
        if ( self::invalid_text( $path ) ) {
            return null;
        }
        $path = rtrim( $path, '/' );
        if ( $path === '' ) {
            return '';
        }
        $parts = explode( '/', $path );
        $clean = array();
        foreach ( $parts as $index => $part ) {
            if ( $index === 0 ) {
                continue;
            }
            if ( $part === '' || $part === '.' || $part === '..' ) {
                return null;
            }
            $clean[] = $part;
        }
        if ( ! $clean ) {
            return '';
        }
        return '/' . implode( '/', $clean );
    }

    /**
     * Junta a pasta atual com o nome do arquivo. Rejeita barras no nome.
     *
     * @param string $folder
     * @param string $name
     * @return string|null
     */
    public static function child_path( $folder, $name ) {
        $folder = self::sanitize_path( $folder );
        if ( $folder === null || ! is_string( $name ) ) {
            return null;
        }
        $name = str_replace( array( '/', '\\' ), '', $name );
        if ( $name === '' || $name === '.' || $name === '..' || self::invalid_text( $name ) ) {
            return null;
        }
        return self::sanitize_path( ( $folder === '' ? '' : $folder ) . '/' . $name );
    }

    /**
     * @param mixed $cursor
     * @return string|null null é inválido.
     */
    public static function sanitize_cursor( $cursor ) {
        if ( $cursor === null || $cursor === '' ) {
            return '';
        }
        if ( ! is_string( $cursor ) ) {
            return null;
        }
        if ( strlen( $cursor ) > 8192 || self::invalid_text( $cursor ) ) {
            return null;
        }
        return $cursor;
    }

    /**
     * @param mixed $query
     * @return string
     */
    public static function sanitize_query( $query ) {
        if ( ! is_string( $query ) ) {
            return '';
        }
        $query = preg_replace( '/[[:cntrl:]]+/u', ' ', $query );
        if ( ! is_string( $query ) ) {
            return '';
        }
        $query = trim( $query );
        if ( strlen( $query ) > 200 ) {
            $query = substr( $query, 0, 200 );
        }
        return $query;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>|null
     */
    public static function entry_from_metadata( array $meta ) {
        $tag = isset( $meta['.tag'] ) ? (string) $meta['.tag'] : '';
        if ( $tag !== 'file' && $tag !== 'folder' ) {
            return null;
        }
        $name = isset( $meta['name'] ) ? str_replace( "\0", '', (string) $meta['name'] ) : '';
        $path = isset( $meta['path_lower'] ) ? (string) $meta['path_lower'] : '';
        $display = isset( $meta['path_display'] ) ? (string) $meta['path_display'] : $path;
        if ( $name === '' ) {
            return null;
        }
        $safe_path = self::sanitize_path( $path );
        $safe_display = self::sanitize_path( $display );
        if ( $safe_path === null || $safe_path === '' ) {
            return null;
        }
        if ( $safe_display === null || $safe_display === '' ) {
            $safe_display = $safe_path;
        }
        return array(
            'tag'          => $tag,
            'name'         => $name,
            'path'         => $safe_path,
            'path_display' => $safe_display,
            'size'         => $tag === 'file' ? max( 0, (int) ( isset( $meta['size'] ) ? $meta['size'] : 0 ) ) : 0,
            'mime'         => $tag === 'file' ? self::mime_from_name( $name ) : '',
            'extension'    => strtoupper( self::extension_from_name( $name ) ),
            'thumb'        => $tag === 'file' && self::is_thumbnailable( $name ),
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function list_result( array $data ) {
        $entries = array();
        $raw = isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : array();
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $item = self::entry_from_metadata( $entry );
            if ( $item ) {
                $entries[] = $item;
            }
        }
        return self::page( $entries, isset( $data['cursor'] ) ? $data['cursor'] : '', ! empty( $data['has_more'] ) );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function search_result( array $data ) {
        $entries = array();
        $raw = isset( $data['matches'] ) && is_array( $data['matches'] ) ? $data['matches'] : array();
        foreach ( $raw as $match ) {
            $meta = self::match_metadata( $match );
            if ( ! $meta ) {
                continue;
            }
            $item = self::entry_from_metadata( $meta );
            if ( $item ) {
                $entries[] = $item;
            }
        }
        return self::page( $entries, isset( $data['cursor'] ) ? $data['cursor'] : '', ! empty( $data['has_more'] ) );
    }

    /**
     * Item de galeria a partir do metadata de um arquivo do Dropbox.
     * Só aceita imagens que o Dropbox consegue miniaturizar.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>|null
     */
    public static function image_item_from_metadata( array $meta ) {
        if ( ( isset( $meta['.tag'] ) ? $meta['.tag'] : '' ) !== 'file' ) {
            return null;
        }
        $name = isset( $meta['name'] ) ? str_replace( "\0", '', (string) $meta['name'] ) : '';
        if ( $name === '' || $name[0] === '.' || ! self::is_thumbnailable( $name ) ) {
            return null;
        }
        $id = isset( $meta['id'] ) ? (string) $meta['id'] : '';
        if ( ! preg_match( '/^id:([A-Za-z0-9_\-]{1,100})$/', $id, $found ) ) {
            return null;
        }
        $path = self::sanitize_path( isset( $meta['path_lower'] ) ? (string) $meta['path_lower'] : '' );
        if ( $path === null || $path === '' ) {
            return null;
        }
        $hash = isset( $meta['content_hash'] ) ? strtolower( (string) $meta['content_hash'] ) : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            $hash = '';
        }
        $width  = 0;
        $height = 0;
        if ( isset( $meta['media_info']['metadata']['dimensions'] ) && is_array( $meta['media_info']['metadata']['dimensions'] ) ) {
            $dimensions = $meta['media_info']['metadata']['dimensions'];
            $width      = isset( $dimensions['width'] ) ? max( 0, min( 20000, (int) $dimensions['width'] ) ) : 0;
            $height     = isset( $dimensions['height'] ) ? max( 0, min( 20000, (int) $dimensions['height'] ) ) : 0;
        }
        if ( $width < 1 || $height < 1 ) {
            $width  = 0;
            $height = 0;
        }
        $uploaded = isset( $meta['server_modified'] ) ? strtotime( (string) $meta['server_modified'] ) : false;
        return array(
            'key'    => $found[1],
            'id'     => $id,
            'name'   => $name,
            'path'   => $path,
            'hash'   => $hash,
            'size'   => max( 0, (int) ( isset( $meta['size'] ) ? $meta['size'] : 0 ) ),
            'time'   => $uploaded === false ? 0 : (int) $uploaded,
            'width'  => $width,
            'height' => $height,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param string                         $order newest, oldest, name ou name_desc.
     * @return array<int,array<string,mixed>>
     */
    public static function sort_items( array $items, $order ) {
        usort(
            $items,
            static function ( $a, $b ) use ( $order ) {
                $by_name = strnatcasecmp( (string) $a['name'], (string) $b['name'] );
                switch ( $order ) {
                    case 'oldest':
                        $cmp = (int) $a['time'] <=> (int) $b['time'];
                        break;
                    case 'name':
                        return $by_name;
                    case 'name_desc':
                        return -$by_name;
                    default:
                        $cmp = (int) $b['time'] <=> (int) $a['time'];
                }
                return $cmp !== 0 ? $cmp : $by_name;
            }
        );
        return $items;
    }

    /**
     * Legenda legível a partir do nome do arquivo.
     *
     * @param string $name
     * @return string
     */
    public static function caption_from_name( $name ) {
        $base = basename( str_replace( '\\', '/', (string) $name ) );
        $dot  = strrpos( $base, '.' );
        if ( $dot !== false && $dot > 0 ) {
            $base = substr( $base, 0, $dot );
        }
        return trim( (string) preg_replace( '/[\s_]+/u', ' ', $base ) );
    }

    /**
     * Nomes gerados por câmeras e celulares não servem como texto alternativo.
     *
     * @param string $name
     * @return bool
     */
    public static function is_camera_name( $name ) {
        $caption = self::caption_from_name( $name );
        return (bool) preg_match( '/^(img|dsc|dscn|dscf|pxl|mvimg|photo|image|foto|whatsapp image)[\s\-]*[\d\-\s\.\(\)]*[a-z]{0,3}$/iu', $caption )
            || (bool) preg_match( '/^[\d\s\-\.\(\)]+$/', $caption );
    }

    /**
     * @param mixed $value
     * @param int   $min
     * @param int   $max
     * @param int   $default
     * @return int
     */
    public static function clamp_int( $value, $min, $max, $default ) {
        if ( ! is_numeric( $value ) ) {
            return $default;
        }
        return max( $min, min( $max, (int) $value ) );
    }

    /**
     * @param string $value
     * @return bool
     */
    private static function invalid_text( $value ) {
        $control = preg_match( '/[[:cntrl:]]/u', $value );
        return $control === false || $control === 1;
    }

    /**
     * @param mixed $match
     * @return array<string,mixed>|null
     */
    private static function match_metadata( $match ) {
        if ( ! is_array( $match ) ) {
            return null;
        }
        if ( isset( $match['metadata'] ) && is_array( $match['metadata'] ) ) {
            $metadata = $match['metadata'];
            if ( isset( $metadata['metadata'] ) && is_array( $metadata['metadata'] ) ) {
                return $metadata['metadata'];
            }
            if ( isset( $metadata['.tag'] ) && ( $metadata['.tag'] === 'file' || $metadata['.tag'] === 'folder' ) ) {
                return $metadata;
            }
        }
        if ( isset( $match['.tag'] ) && ( $match['.tag'] === 'file' || $match['.tag'] === 'folder' ) ) {
            return $match;
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     * @param mixed                          $cursor
     * @param bool                           $has_more
     * @return array<string,mixed>
     */
    private static function page( array $entries, $cursor, $has_more ) {
        $safe_cursor = self::sanitize_cursor( is_string( $cursor ) ? $cursor : '' );
        if ( $safe_cursor === null || $safe_cursor === '' ) {
            $has_more = false;
            $safe_cursor = '';
        }
        if ( ! $has_more ) {
            $safe_cursor = '';
        }
        return array(
            'entries'  => $entries,
            'cursor'   => $safe_cursor,
            'has_more' => (bool) $has_more,
        );
    }
}
