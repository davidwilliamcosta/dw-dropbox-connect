<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals -- CLI runner, not a WordPress screen.

/**
 * Testes das partes puras do plugin. Não carregam o WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

define( 'DWDC_TESTING', true );

require dirname( __DIR__ ) . '/includes/class-dwdc-crypto.php';
require dirname( __DIR__ ) . '/includes/class-dwdc-normalizer.php';
require dirname( __DIR__ ) . '/includes/class-dwdc-updater.php';

$failed = 0;

function dwdc_assert( $label, $condition ) {
    global $failed;
    if ( $condition ) {
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}\n";
}

dwdc_assert( 'raiz vazia', DWDC_Normalizer::sanitize_path( '' ) === '' );
dwdc_assert( 'barra é raiz', DWDC_Normalizer::sanitize_path( '/' ) === '' );
dwdc_assert( 'remove barra final', DWDC_Normalizer::sanitize_path( '/Fotos/' ) === '/Fotos' );
dwdc_assert( 'rejeita relativo', DWDC_Normalizer::sanitize_path( 'Fotos' ) === null );
dwdc_assert( 'rejeita subida', DWDC_Normalizer::sanitize_path( '/a/../b' ) === null );
dwdc_assert( 'rejeita nulo', DWDC_Normalizer::sanitize_path( "/a\0b" ) === null );
dwdc_assert( 'rejeita utf-8 inválido', DWDC_Normalizer::sanitize_path( "/\xC3\x28" ) === null );
dwdc_assert( 'aceita unicode', DWDC_Normalizer::sanitize_path( '/Fotos/verão' ) === '/Fotos/verão' );
dwdc_assert( 'arquivo na raiz', DWDC_Normalizer::child_path( '', 'foto.jpg' ) === '/foto.jpg' );
dwdc_assert( 'arquivo na pasta', DWDC_Normalizer::child_path( '/Fotos', 'verão.jpg' ) === '/Fotos/verão.jpg' );
dwdc_assert( 'nome com barra', DWDC_Normalizer::child_path( '/Fotos', 'a/b.jpg' ) === '/Fotos/ab.jpg' );
dwdc_assert( 'nome vazio', DWDC_Normalizer::child_path( '/Fotos', '' ) === null );

dwdc_assert( 'mime jpg', DWDC_Normalizer::mime_from_name( 'A.JPG' ) === 'image/jpeg' );
dwdc_assert( 'miniatura de png', DWDC_Normalizer::is_thumbnailable( 'foto.png' ) === true );
dwdc_assert( 'pdf sem miniatura', DWDC_Normalizer::is_thumbnailable( 'doc.pdf' ) === false );

$list = DWDC_Normalizer::list_result( array(
    'entries' => array(
        array( '.tag' => 'folder', 'name' => 'Fotos', 'path_lower' => '/fotos', 'path_display' => '/Fotos' ),
        array( '.tag' => 'file', 'name' => 'a.JPG', 'path_lower' => '/fotos/a.jpg', 'path_display' => '/Fotos/a.JPG', 'size' => 12 ),
        array( '.tag' => 'deleted', 'name' => 'x', 'path_lower' => '/x' ),
        array( '.tag' => 'file', 'name' => 'solto' ),
    ),
    'cursor'  => "cur\n",
    'has_more' => true,
) );
dwdc_assert( 'lista ignora inválidos', count( $list['entries'] ) === 2 );
dwdc_assert( 'arquivo é imagem', $list['entries'][1]['mime'] === 'image/jpeg' && $list['entries'][1]['thumb'] === true );
dwdc_assert( 'cursor de controle é descartado', $list['has_more'] === false && $list['cursor'] === '' );

$search = DWDC_Normalizer::search_result( array(
    'matches' => array(
        array(
            'metadata' => array(
                '.tag' => 'metadata',
                'metadata' => array(
                    '.tag' => 'file',
                    'name' => 'b.png',
                    'path_lower' => '/b.png',
                    'path_display' => '/b.png',
                    'size' => 5,
                ),
            ),
        ),
        array(
            'metadata' => array(
                '.tag' => 'folder',
                'name' => 'Docs',
                'path_lower' => '/docs',
                'path_display' => '/Docs',
            ),
        ),
    ),
    'has_more' => false,
    'cursor' => 'ignored',
) );
dwdc_assert( 'busca achata metadata', count( $search['entries'] ) === 2 && $search['entries'][0]['path'] === '/b.png' );
dwdc_assert( 'busca sem próxima página limpa cursor', $search['cursor'] === '' );

$image = DWDC_Normalizer::image_item_from_metadata( array(
    '.tag'            => 'file',
    'id'              => 'id:a4ayc_80_OEAAAAAAAAAXw',
    'name'            => 'Verão 01.JPG',
    'path_lower'      => '/galeria/verão 01.jpg',
    'content_hash'    => str_repeat( 'AB', 32 ),
    'size'            => 2048,
    'server_modified' => '2026-11-16T10:00:00Z',
    'media_info'      => array( '.tag' => 'metadata', 'metadata' => array( '.tag' => 'photo', 'dimensions' => array( 'width' => 4000, 'height' => 3000 ) ) ),
) );
dwdc_assert( 'imagem da galeria é lida', is_array( $image ) && $image['key'] === 'a4ayc_80_OEAAAAAAAAAXw' && $image['id'] === 'id:a4ayc_80_OEAAAAAAAAAXw' );
dwdc_assert( 'imagem guarda dimensões e hash', $image['width'] === 4000 && $image['height'] === 3000 && $image['hash'] === str_repeat( 'ab', 32 ) );
dwdc_assert( 'imagem guarda data de envio', $image['time'] === 1794823200 );
dwdc_assert( 'pdf não entra na galeria', DWDC_Normalizer::image_item_from_metadata( array( '.tag' => 'file', 'id' => 'id:abc', 'name' => 'a.pdf', 'path_lower' => '/a.pdf' ) ) === null );
dwdc_assert( 'pasta não entra na galeria', DWDC_Normalizer::image_item_from_metadata( array( '.tag' => 'folder', 'id' => 'id:abc', 'name' => 'x', 'path_lower' => '/x' ) ) === null );
dwdc_assert( 'arquivo oculto não entra', DWDC_Normalizer::image_item_from_metadata( array( '.tag' => 'file', 'id' => 'id:abc', 'name' => '.hidden.jpg', 'path_lower' => '/.hidden.jpg' ) ) === null );
dwdc_assert( 'id inválido é recusado', DWDC_Normalizer::image_item_from_metadata( array( '.tag' => 'file', 'id' => 'id:a/../b', 'name' => 'a.jpg', 'path_lower' => '/a.jpg' ) ) === null );
$no_dims = DWDC_Normalizer::image_item_from_metadata( array( '.tag' => 'file', 'id' => 'id:abc', 'name' => 'a.png', 'path_lower' => '/a.png', 'media_info' => array( '.tag' => 'pending' ) ) );
dwdc_assert( 'imagem sem dimensões continua válida', is_array( $no_dims ) && $no_dims['width'] === 0 && $no_dims['hash'] === '' );

$sortable = array(
    array( 'name' => 'img10.jpg', 'time' => 100 ),
    array( 'name' => 'img2.jpg', 'time' => 300 ),
    array( 'name' => 'img1.jpg', 'time' => 200 ),
);
dwdc_assert( 'ordem mais recentes', array_column( DWDC_Normalizer::sort_items( $sortable, 'newest' ), 'name' ) === array( 'img2.jpg', 'img1.jpg', 'img10.jpg' ) );
dwdc_assert( 'ordem mais antigas', array_column( DWDC_Normalizer::sort_items( $sortable, 'oldest' ), 'name' ) === array( 'img10.jpg', 'img1.jpg', 'img2.jpg' ) );
dwdc_assert( 'ordem por nome é natural', array_column( DWDC_Normalizer::sort_items( $sortable, 'name' ), 'name' ) === array( 'img1.jpg', 'img2.jpg', 'img10.jpg' ) );
dwdc_assert( 'ordem por nome invertida', array_column( DWDC_Normalizer::sort_items( $sortable, 'name_desc' ), 'name' ) === array( 'img10.jpg', 'img2.jpg', 'img1.jpg' ) );

dwdc_assert( 'legenda sem extensão', DWDC_Normalizer::caption_from_name( 'Festa_de_Natal  2026.JPG' ) === 'Festa de Natal 2026' );
dwdc_assert( 'nome de câmera não vira alt', DWDC_Normalizer::is_camera_name( 'IMG_4821.JPG' ) && DWDC_Normalizer::is_camera_name( 'PXL_20261116_101500123.jpg' ) && DWDC_Normalizer::is_camera_name( '20261116_101500.jpg' ) );
dwdc_assert( 'nome descritivo vira alt', ! DWDC_Normalizer::is_camera_name( 'Mesa de jantar.jpg' ) );
dwdc_assert( 'clamp respeita limites', DWDC_Normalizer::clamp_int( '99', 1, 8, 4 ) === 8 && DWDC_Normalizer::clamp_int( '0', 1, 8, 4 ) === 1 && DWDC_Normalizer::clamp_int( 'x', 1, 8, 4 ) === 4 );

dwdc_assert( 'consulta remove controle', DWDC_Normalizer::sanitize_query( " a\nb " ) === 'a b' );
dwdc_assert( 'cursor longo é inválido', DWDC_Normalizer::sanitize_cursor( str_repeat( 'a', 9000 ) ) === null );

$key = hash( 'sha256', 'dwdc-test-key', true );
$encoded = DWDC_Crypto::encrypt( 'token-ç', $key );
dwdc_assert( 'cifra devolve texto', is_string( $encoded ) && $encoded !== '' );
dwdc_assert( 'decifra o mesmo texto', DWDC_Crypto::decrypt( $encoded, $key ) === 'token-ç' );
dwdc_assert( 'chave errada não abre', DWDC_Crypto::decrypt( $encoded, hash( 'sha256', 'outra', true ) ) === '' );
$payload = base64_decode( substr( $encoded, 3 ), true );
$payload[20] = $payload[20] === 'A' ? 'B' : 'A';
dwdc_assert( 'ciphertext alterado não devolve o segredo', DWDC_Crypto::decrypt( 'v2:' . base64_encode( $payload ), $key ) === '' );
$legacy_iv = random_bytes( 16 );
$legacy_cipher = openssl_encrypt( 'legado', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $legacy_iv );
dwdc_assert( 'formato antigo ainda abre', DWDC_Crypto::decrypt( base64_encode( $legacy_iv . $legacy_cipher ), $key ) === 'legado' );
dwdc_assert( 'lixo não decifra', DWDC_Crypto::decrypt( 'nao-e-base64', $key ) === '' );
dwdc_assert( 'chave curta não cifra', DWDC_Crypto::encrypt( 'x', 'curta' ) === false );

$release_url = 'https://github.com/davidwilliamcosta/dw-dropbox-connect/releases/download/v0.1.0/dw-dropbox-connect.zip';
dwdc_assert( 'tag vira versão', DWDC_Updater::version_from_tag( 'v0.1.0' ) === '0.1.0' );
dwdc_assert( 'tag inválida', DWDC_Updater::version_from_tag( 'latest' ) === '' );
dwdc_assert( 'zip da release é aceito', DWDC_Updater::trusted_package( $release_url ) );
dwdc_assert( 'zip de outro repositório é recusado', ! DWDC_Updater::trusted_package( 'https://github.com/other/repo/releases/download/v0.1.0/dw-dropbox-connect.zip' ) );
$parsed = DWDC_Updater::parse_release( array(
    'tag_name' => 'v0.2.0',
    'html_url' => 'https://github.com/davidwilliamcosta/dw-dropbox-connect/releases/tag/v0.2.0',
    'body'     => 'Notas',
    'assets'   => array(
        array(
            'name'                 => 'dw-dropbox-connect.zip',
            'browser_download_url' => 'https://github.com/davidwilliamcosta/dw-dropbox-connect/releases/download/v0.2.0/dw-dropbox-connect.zip',
        ),
    ),
) );
dwdc_assert( 'release válida', is_array( $parsed ) && $parsed['version'] === '0.2.0' );
dwdc_assert( 'release sem zip', DWDC_Updater::parse_release( array( 'tag_name' => 'v0.2.0', 'assets' => array() ) ) === null );

echo $failed === 0 ? "\nTodos os testes passaram.\n" : "\n{$failed} teste(s) falharam.\n";
exit( $failed === 0 ? 0 : 1 );
