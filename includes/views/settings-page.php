<?php
/**
 * Tela Configurações → Dropbox.
 *
 * @package DW_Dropbox_Connect
 *
 * @var array{type:string,message:string}|null $notice
 * @var bool $connected
 * @var string $account
 * @var string $redirect_uri
 * @var string $app_key
 * @var bool $has_secret
 * @var bool $uses_constants
 * @var bool $ready
 * @var string $ready_error
 * @var string $connect_url
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$dwdc_tabs = array(
    'conexao'     => __( 'Conexão', 'dw-dropbox-connect' ),
    'credenciais' => __( 'Credenciais', 'dw-dropbox-connect' ),
    'app'         => __( 'App do Dropbox', 'dw-dropbox-connect' ),
);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab, sanitized and allowlisted below.
$dwdc_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'conexao';
if ( ! isset( $dwdc_tabs[ $dwdc_tab ] ) ) {
    $dwdc_tab = 'conexao';
}
?>
<div class="wrap dwdc-settings">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Dropbox', 'dw-dropbox-connect' ); ?></h1>

    <div class="dwdc-brand">
        <div class="dwdc-brand-main">
            <strong><?php esc_html_e( 'DW Dropbox Connect', 'dw-dropbox-connect' ); ?></strong>
            <span class="dwdc-brand-description"><?php esc_html_e( 'Conta pessoal do Dropbox na aba do seletor de mídia, com importação para a Biblioteca.', 'dw-dropbox-connect' ); ?></span>
        </div>
        <span class="dwdc-brand-version"><?php
            /* translators: %s: plugin version number. */
            echo esc_html( sprintf( __( 'Versão %s', 'dw-dropbox-connect' ), DWDC_VERSION ) );
        ?></span>
    </div>

    <nav class="dwdc-nav" aria-label="<?php esc_attr_e( 'Seções do Dropbox', 'dw-dropbox-connect' ); ?>">
        <?php foreach ( $dwdc_tabs as $dwdc_key => $dwdc_label ) : ?>
            <a class="<?php echo esc_attr( $dwdc_tab === $dwdc_key ? 'is-active' : '' ); ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'dw-dropbox-connect', 'tab' => $dwdc_key ), admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $dwdc_label ); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice['type'] === 'error' ? 'error' : 'success' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
    <?php endif; ?>

    <?php if ( $dwdc_tab === 'conexao' ) : ?>
        <section class="dwdc-card">
            <h2><?php esc_html_e( 'Conexão', 'dw-dropbox-connect' ); ?></h2>
            <p class="dwdc-lead"><?php esc_html_e( 'A conta autorizada fica disponível para quem pode enviar arquivos neste site.', 'dw-dropbox-connect' ); ?></p>
            <div class="dwdc-status">
                <?php if ( $connected ) : ?>
                    <span class="dwdc-badge dwdc-badge-on"><?php esc_html_e( 'Conectado', 'dw-dropbox-connect' ); ?></span>
                    <?php if ( $account !== '' ) : ?>
                        <span class="dwdc-account"><?php echo esc_html( $account ); ?></span>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="dwdc-badge"><?php esc_html_e( 'Não conectado', 'dw-dropbox-connect' ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( ! $ready && $ready_error !== '' ) : ?>
                <p class="dwdc-note"><?php echo esc_html( $ready_error ); ?></p>
            <?php endif; ?>
            <div class="dwdc-actions">
                <?php if ( $ready ) : ?>
                    <a class="button button-primary" href="<?php echo esc_url( $connect_url ); ?>"><?php echo esc_html( $connected ? __( 'Reconectar Dropbox', 'dw-dropbox-connect' ) : __( 'Conectar Dropbox', 'dw-dropbox-connect' ) ); ?></a>
                <?php else : ?>
                    <a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'dw-dropbox-connect', 'tab' => 'credenciais' ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Informar credenciais', 'dw-dropbox-connect' ); ?></a>
                <?php endif; ?>
                <?php if ( $connected ) : ?>
                    <form class="dwdc-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="dwdc_disconnect">
                        <?php wp_nonce_field( 'dwdc_disconnect' ); ?>
                        <?php submit_button( __( 'Desconectar', 'dw-dropbox-connect' ), 'secondary dwdc-danger', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            </div>
            <p class="dwdc-callout"><?php esc_html_e( 'Quem pode enviar arquivos no WordPress consegue navegar e importar a árvore inteira da conta conectada.', 'dw-dropbox-connect' ); ?></p>
        </section>
    <?php elseif ( $dwdc_tab === 'credenciais' ) : ?>
        <section class="dwdc-card">
            <h2><?php esc_html_e( 'Credenciais', 'dw-dropbox-connect' ); ?></h2>
            <?php if ( $uses_constants ) : ?>
                <p class="dwdc-lead"><?php esc_html_e( 'A App key e o App secret estão definidos no wp-config.php.', 'dw-dropbox-connect' ); ?></p>
                <p class="dwdc-callout dwdc-callout-info"><?php esc_html_e( 'O formulário fica desligado enquanto DWDC_APP_KEY e DWDC_APP_SECRET existirem. As duas constantes precisam ser definidas juntas.', 'dw-dropbox-connect' ); ?></p>
            <?php else : ?>
                <p class="dwdc-lead"><?php esc_html_e( 'Cole a App key e o App secret do Dropbox App Console. O secret é cifrado e não volta a aparecer nesta tela.', 'dw-dropbox-connect' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="dwdc_save">
                    <?php wp_nonce_field( 'dwdc_save' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="dwdc-app-key"><?php esc_html_e( 'App key', 'dw-dropbox-connect' ); ?></label></th>
                            <td><input id="dwdc-app-key" name="app_key" class="regular-text" value="<?php echo esc_attr( $app_key ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'cole a App key', 'dw-dropbox-connect' ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dwdc-app-secret"><?php esc_html_e( 'App secret', 'dw-dropbox-connect' ); ?></label></th>
                            <td>
                                <input id="dwdc-app-secret" name="app_secret" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_secret ? __( 'salvo — deixe em branco para manter', 'dw-dropbox-connect' ) : __( 'cole o App secret', 'dw-dropbox-connect' ) ); ?>">
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Salvar credenciais', 'dw-dropbox-connect' ) ); ?>
                </form>
            <?php endif; ?>
        </section>
    <?php else : ?>
        <section class="dwdc-card">
            <h2><?php esc_html_e( 'App do Dropbox', 'dw-dropbox-connect' ); ?></h2>
            <p class="dwdc-lead"><?php esc_html_e( 'Crie um app Scoped access com acesso Full Dropbox e cadastre o endereço abaixo.', 'dw-dropbox-connect' ); ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="dwdc-redirect"><?php esc_html_e( 'Redirect URI', 'dw-dropbox-connect' ); ?></label></th>
                    <td>
                        <div class="dwdc-copy-row">
                            <input id="dwdc-redirect" class="large-text code" type="text" readonly value="<?php echo esc_attr( $redirect_uri ); ?>">
                            <button type="button" class="button dwdc-copy" data-target="dwdc-redirect" data-copied="<?php esc_attr_e( 'Copiado', 'dw-dropbox-connect' ); ?>"><?php esc_html_e( 'Copiar', 'dw-dropbox-connect' ); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e( 'Cadastre este endereço, exatamente assim, em OAuth 2 → Redirect URIs.', 'dw-dropbox-connect' ); ?></p>
                    </td>
                </tr>
            </table>
            <ol class="dwdc-steps">
                <li><?php echo wp_kses( __( 'Em <a href="https://www.dropbox.com/developers/apps">Dropbox App Console</a>, crie um app com Scoped access.', 'dw-dropbox-connect' ), array( 'a' => array( 'href' => array() ) ) ); ?></li>
                <li><?php esc_html_e( 'No tipo de acesso, escolha Full Dropbox.', 'dw-dropbox-connect' ); ?></li>
                <li><?php esc_html_e( 'Em Permissions, marque account_info.read, files.metadata.read, files.content.read e files.content.write. Envie as alterações e conecte de novo para o envio passar a valer.', 'dw-dropbox-connect' ); ?></li>
                <li><?php esc_html_e( 'Cole o Redirect URI e copie a App key e o App secret para a aba Credenciais.', 'dw-dropbox-connect' ); ?></li>
                <li><?php esc_html_e( 'Volte em Conexão e autorize a conta pessoal.', 'dw-dropbox-connect' ); ?></li>
            </ol>
            <p class="dwdc-callout dwdc-callout-info"><?php esc_html_e( 'Enquanto o app estiver em desenvolvimento, o Dropbox só autoriza a conta dona e os testadores adicionados no console.', 'dw-dropbox-connect' ); ?></p>
        </section>
    <?php endif; ?>
</div>
