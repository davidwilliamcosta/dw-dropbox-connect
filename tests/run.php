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

echo $failed === 0 ? "\nTodos os testes passaram.\n" : "\n{$failed} teste(s) falharam.\n";
exit( $failed === 0 ? 0 : 1 );
