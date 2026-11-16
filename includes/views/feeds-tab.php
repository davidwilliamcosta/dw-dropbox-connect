<?php
/**
 * Aba Galerias.
 *
 * @package DW_Dropbox_Connect
 *
 * @var DWDC_Feeds_Admin $dwdc_feeds_admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$dwdc_ctx     = $dwdc_feeds_admin->context();
$dwdc_current = $dwdc_ctx['current'];
$dwdc_is_new  = is_array( $dwdc_current ) && empty( $dwdc_current['id'] );
$dwdc_hint    = static function ( $text ) {
    echo '<p class="description">' . esc_html( $text ) . '</p>';
};
?>
<?php if ( ! $dwdc_ctx['connected'] ) : ?>
    <section class="dwdc-card">
        <h2><?php esc_html_e( 'Galerias', 'dw-dropbox-connect' ); ?></h2>
        <p class="dwdc-lead"><?php esc_html_e( 'As galerias mostram no site as imagens de pastas do Dropbox. Primeiro é preciso conectar a conta.', 'dw-dropbox-connect' ); ?></p>
        <div class="dwdc-actions">
            <a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'dw-dropbox-connect', 'tab' => 'conexao' ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Ir para Conexão', 'dw-dropbox-connect' ); ?></a>
        </div>
    </section>
<?php elseif ( is_array( $dwdc_current ) ) : ?>
    <section class="dwdc-card dwdc-card-wide">
        <h2><?php echo esc_html( $dwdc_is_new ? __( 'Nova galeria', 'dw-dropbox-connect' ) : __( 'Editar galeria', 'dw-dropbox-connect' ) ); ?></h2>
        <p class="dwdc-lead"><?php esc_html_e( 'A galeria acompanha a pasta escolhida. Imagem enviada, trocada ou apagada no Dropbox muda no site sozinha.', 'dw-dropbox-connect' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="dwdc_feed_save">
            <input type="hidden" name="feed_id" value="<?php echo esc_attr( (string) $dwdc_current['id'] ); ?>">
            <?php wp_nonce_field( 'dwdc_feed_save' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="dwdc-feed-name"><?php esc_html_e( 'Nome', 'dw-dropbox-connect' ); ?></label></th>
                    <td>
                        <input id="dwdc-feed-name" name="name" class="regular-text" value="<?php echo esc_attr( (string) $dwdc_current['name'] ); ?>" autocomplete="off">
                        <?php $dwdc_hint( __( 'Só para identificar a galeria aqui no painel.', 'dw-dropbox-connect' ) ); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dwdc-feed-path"><?php esc_html_e( 'Pasta do Dropbox', 'dw-dropbox-connect' ); ?></label></th>
                    <td>
                        <div class="dwdc-copy-row">
                            <input id="dwdc-feed-path" name="path" class="regular-text" value="<?php echo esc_attr( (string) $dwdc_current['path'] ); ?>" autocomplete="off" placeholder="/Fotos/Evento" required>
                            <button type="button" class="button dwdc-pick-open"><?php esc_html_e( 'Escolher pasta', 'dw-dropbox-connect' ); ?></button>
                        </div>
                        <div id="dwdc-picker" class="dwdc-picker" hidden></div>
                        <label class="dwdc-check"><input type="checkbox" name="recursive" value="1" <?php checked( ! empty( $dwdc_current['recursive'] ) ); ?>> <?php esc_html_e( 'Incluir imagens das subpastas', 'dw-dropbox-connect' ); ?></label>
                        <?php $dwdc_hint( __( 'Só imagens aparecem: jpg, png, gif, webp, tiff e bmp. Cada pasta lida tem o limite de 10 mil arquivos.', 'dw-dropbox-connect' ) ); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dwdc-feed-order"><?php esc_html_e( 'Ordem', 'dw-dropbox-connect' ); ?></label></th>
                    <td>
                        <select id="dwdc-feed-order" name="order">
                            <option value="newest" <?php selected( $dwdc_current['order'], 'newest' ); ?>><?php esc_html_e( 'Mais recentes primeiro', 'dw-dropbox-connect' ); ?></option>
                            <option value="oldest" <?php selected( $dwdc_current['order'], 'oldest' ); ?>><?php esc_html_e( 'Mais antigas primeiro', 'dw-dropbox-connect' ); ?></option>
                            <option value="name" <?php selected( $dwdc_current['order'], 'name' ); ?>><?php esc_html_e( 'Nome do arquivo (A a Z)', 'dw-dropbox-connect' ); ?></option>
                            <option value="name_desc" <?php selected( $dwdc_current['order'], 'name_desc' ); ?>><?php esc_html_e( 'Nome do arquivo (Z a A)', 'dw-dropbox-connect' ); ?></option>
                        </select>
                        <?php $dwdc_hint( __( 'A data considerada é a do envio ao Dropbox.', 'dw-dropbox-connect' ) ); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dwdc-feed-limit"><?php esc_html_e( 'Quantidade', 'dw-dropbox-connect' ); ?></label></th>
                    <td>
                        <label><?php esc_html_e( 'Total na galeria', 'dw-dropbox-connect' ); ?> <input id="dwdc-feed-limit" type="number" name="limit" min="1" max="500" value="<?php echo esc_attr( (string) $dwdc_current['limit'] ); ?>" class="small-text"></label>
                        <label class="dwdc-inline-field"><?php esc_html_e( 'Visíveis de início', 'dw-dropbox-connect' ); ?> <input type="number" name="per_page" min="1" max="200" value="<?php echo esc_attr( (string) $dwdc_current['per_page'] ); ?>" class="small-text"></label>
                        <?php $dwdc_hint( __( 'O botão Carregar mais mostra o mesmo número de imagens a cada clique.', 'dw-dropbox-connect' ) ); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dwdc-feed-layout"><?php esc_html_e( 'Layout', 'dw-dropbox-connect' ); ?></label></th>
                    <td>
                        <select id="dwdc-feed-layout" name="layout">
                            <option value="grid" <?php selected( $dwdc_current['layout'], 'grid' ); ?>><?php esc_html_e( 'Grade', 'dw-dropbox-connect' ); ?></option>
                            <option value="masonry" <?php selected( $dwdc_current['layout'], 'masonry' ); ?>><?php esc_html_e( 'Mosaico (altura livre)', 'dw-dropbox-connect' ); ?></option>
                        </select>
                        <select name="ratio" aria-label="<?php esc_attr_e( 'Formato da miniatura na grade', 'dw-dropbox-connect' ); ?>">
                            <option value="square" <?php selected( $dwdc_current['ratio'], 'square' ); ?>><?php esc_html_e( 'Miniaturas quadradas', 'dw-dropbox-connect' ); ?></option>
                            <option value="original" <?php selected( $dwdc_current['ratio'], 'original' ); ?>><?php esc_html_e( 'Proporção original', 'dw-dropbox-connect' ); ?></option>
                        </select>
                        <?php $dwdc_hint( __( 'O formato da miniatura vale só para a grade.', 'dw-dropbox-connect' ) ); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Colunas', 'dw-dropbox-connect' ); ?></th>
                    <td>
                        <label><?php esc_html_e( 'Computador', 'dw-dropbox-connect' ); ?> <input type="number" name="columns" min="1" max="8" value="<?php echo esc_attr( (string) $dwdc_current['columns'] ); ?>" class="small-text"></label>
                        <label class="dwdc-inline-field"><?php esc_html_e( 'Tablet', 'dw-dropbox-connect' ); ?> <input type="number" name="columns_md" min="1" max="6" value="<?php echo esc_attr( (string) $dwdc_current['columns_md'] ); ?>" class="small-text"></label>
                        <label class="dwdc-inline-field"><?php esc_html_e( 'Celular', 'dw-dropbox-connect' ); ?> <input type="number" name="columns_sm" min="1" max="4" value="<?php echo esc_attr( (string) $dwdc_current['columns_sm'] ); ?>" class="small-text"></label>
                        <label class="dwdc-inline-field"><?php esc_html_e( 'Espaço (px)', 'dw-dropbox-connect' ); ?> <input type="number" name="gap" min="0" max="48" value="<?php echo esc_attr( (string) $dwdc_current['gap'] ); ?>" class="small-text"></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Exibição', 'dw-dropbox-connect' ); ?></th>
                    <td>
                        <label class="dwdc-check"><input type="checkbox" name="lightbox" value="1" <?php checked( ! empty( $dwdc_current['lightbox'] ) ); ?>> <?php esc_html_e( 'Abrir a imagem ampliada ao clicar', 'dw-dropbox-connect' ); ?></label>
                        <label class="dwdc-check"><input type="checkbox" name="captions" value="1" <?php checked( ! empty( $dwdc_current['captions'] ) ); ?>> <?php esc_html_e( 'Mostrar o nome do arquivo como legenda', 'dw-dropbox-connect' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dwdc-feed-refresh"><?php esc_html_e( 'Verificar mudanças', 'dw-dropbox-connect' ); ?></label></th>
                    <td>
                        <select id="dwdc-feed-refresh" name="refresh">
                            <?php foreach ( $dwdc_ctx['refresh_options'] as $dwdc_minutes => $dwdc_label ) : ?>
                                <option value="<?php echo esc_attr( (string) $dwdc_minutes ); ?>" <?php selected( (int) $dwdc_current['refresh'], (int) $dwdc_minutes ); ?>><?php echo esc_html( $dwdc_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php $dwdc_hint( __( 'Com o webhook ativo (veja abaixo), a galeria atualiza em segundos. Sem ele, vale este intervalo.', 'dw-dropbox-connect' ) ); ?>
                    </td>
                </tr>
            </table>
            <div class="dwdc-actions">
                <?php submit_button( $dwdc_is_new ? __( 'Criar galeria', 'dw-dropbox-connect' ) : __( 'Salvar galeria', 'dw-dropbox-connect' ), 'primary', 'submit', false ); ?>
                <a class="button" href="<?php echo esc_url( $dwdc_ctx['tab_url'] ); ?>"><?php esc_html_e( 'Cancelar', 'dw-dropbox-connect' ); ?></a>
            </div>
        </form>
    </section>
<?php else : ?>
    <section class="dwdc-card dwdc-card-wide">
        <h2><?php esc_html_e( 'Galerias', 'dw-dropbox-connect' ); ?></h2>
        <p class="dwdc-lead"><?php esc_html_e( 'Cada galeria mostra no site as imagens de uma pasta do Dropbox, no estilo de um feed. A galeria entra em qualquer página pelo bloco Galeria do Dropbox ou pelo shortcode.', 'dw-dropbox-connect' ); ?></p>
        <?php if ( ! $dwdc_ctx['feeds'] ) : ?>
            <p class="dwdc-note"><?php esc_html_e( 'Nenhuma galeria criada ainda.', 'dw-dropbox-connect' ); ?></p>
        <?php else : ?>
            <table class="widefat striped dwdc-feeds-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Galeria', 'dw-dropbox-connect' ); ?></th>
                        <th><?php esc_html_e( 'Shortcode', 'dw-dropbox-connect' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'dw-dropbox-connect' ); ?></th>
                        <th><?php esc_html_e( 'Ações', 'dw-dropbox-connect' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $dwdc_ctx['feeds'] as $dwdc_id => $dwdc_feed ) : ?>
                    <?php
                    $dwdc_snap      = $dwdc_ctx['snapshots'][ $dwdc_id ];
                    $dwdc_shortcode = '[' . DWDC_Gallery::SHORTCODE . ' id="' . $dwdc_id . '"]';
                    $dwdc_edit_url  = add_query_arg( 'feed', $dwdc_id, $dwdc_ctx['tab_url'] );
                    $dwdc_refresh   = wp_nonce_url( admin_url( 'admin-post.php?action=dwdc_feed_refresh&feed=' . $dwdc_id ), 'dwdc_feed_refresh_' . $dwdc_id );
                    $dwdc_delete    = wp_nonce_url( admin_url( 'admin-post.php?action=dwdc_feed_delete&feed=' . $dwdc_id ), 'dwdc_feed_delete_' . $dwdc_id );
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( $dwdc_edit_url ); ?>"><?php echo esc_html( (string) $dwdc_feed['name'] ); ?></a></strong><br>
                            <code><?php echo esc_html( (string) $dwdc_feed['path'] ); ?></code>
                        </td>
                        <td>
                            <div class="dwdc-copy-row">
                                <input id="dwdc-sc-<?php echo esc_attr( $dwdc_id ); ?>" class="code" type="text" readonly value="<?php echo esc_attr( $dwdc_shortcode ); ?>" size="30">
                                <button type="button" class="button dwdc-copy" data-target="dwdc-sc-<?php echo esc_attr( $dwdc_id ); ?>" data-copied="<?php esc_attr_e( 'Copiado', 'dw-dropbox-connect' ); ?>"><?php esc_html_e( 'Copiar', 'dw-dropbox-connect' ); ?></button>
                            </div>
                        </td>
                        <td>
                            <?php if ( $dwdc_snap['error'] !== '' ) : ?>
                                <span class="dwdc-badge dwdc-badge-error"><?php esc_html_e( 'Falha', 'dw-dropbox-connect' ); ?></span>
                                <span class="dwdc-note"><?php echo esc_html( (string) $dwdc_snap['error'] ); ?></span><br>
                            <?php endif; ?>
                            <?php if ( (int) $dwdc_snap['synced_at'] > 0 ) : ?>
                                <?php
                                $dwdc_count = count( $dwdc_snap['items'] );
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: number of images, 2: how long ago the last sync happened. */
                                        _n( '%1$d imagem, sincronizada há %2$s.', '%1$d imagens, sincronizada há %2$s.', $dwdc_count, 'dw-dropbox-connect' ),
                                        $dwdc_count,
                                        human_time_diff( (int) $dwdc_snap['synced_at'], time() )
                                    )
                                );
                                ?>
                            <?php else : ?>
                                <span class="dwdc-badge"><?php esc_html_e( 'Ainda não sincronizada', 'dw-dropbox-connect' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="dwdc-row-actions">
                            <a href="<?php echo esc_url( $dwdc_refresh ); ?>"><?php esc_html_e( 'Atualizar agora', 'dw-dropbox-connect' ); ?></a>
                            <a href="<?php echo esc_url( $dwdc_edit_url ); ?>"><?php esc_html_e( 'Editar', 'dw-dropbox-connect' ); ?></a>
                            <a class="dwdc-delete" href="<?php echo esc_url( $dwdc_delete ); ?>" data-confirm="<?php esc_attr_e( 'Excluir esta galeria? Os arquivos do Dropbox não são alterados.', 'dw-dropbox-connect' ); ?>"><?php esc_html_e( 'Excluir', 'dw-dropbox-connect' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="dwdc-actions">
            <a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'feed', 'new', $dwdc_ctx['tab_url'] ) ); ?>"><?php esc_html_e( 'Nova galeria', 'dw-dropbox-connect' ); ?></a>
        </div>
    </section>

    <section class="dwdc-card dwdc-card-wide dwdc-card-spaced">
        <h2><?php esc_html_e( 'Atualização imediata (webhook)', 'dw-dropbox-connect' ); ?></h2>
        <p class="dwdc-lead"><?php esc_html_e( 'Opcional. Com o webhook, o Dropbox avisa o site assim que algo muda na conta, e as galerias atualizam em segundos em vez de esperar o intervalo.', 'dw-dropbox-connect' ); ?></p>
        <div class="dwdc-copy-row">
            <input id="dwdc-webhook" class="large-text code" type="text" readonly value="<?php echo esc_attr( $dwdc_ctx['webhook_url'] ); ?>">
            <button type="button" class="button dwdc-copy" data-target="dwdc-webhook" data-copied="<?php esc_attr_e( 'Copiado', 'dw-dropbox-connect' ); ?>"><?php esc_html_e( 'Copiar', 'dw-dropbox-connect' ); ?></button>
        </div>
        <ol class="dwdc-steps dwdc-steps-spaced">
            <li><?php echo wp_kses( __( 'Em <a href="https://www.dropbox.com/developers/apps">Dropbox App Console</a>, abrir o app e ir para a aba Settings.', 'dw-dropbox-connect' ), array( 'a' => array( 'href' => array() ) ) ); ?></li>
            <li><?php esc_html_e( 'Em Webhooks, colar o endereço acima em Webhook URIs e clicar em Add. O Dropbox confirma o endereço na hora.', 'dw-dropbox-connect' ); ?></li>
            <li><?php esc_html_e( 'O site precisa estar acessível pela internet. Em ambiente local o webhook não funciona, e vale o intervalo de cada galeria.', 'dw-dropbox-connect' ); ?></li>
        </ol>
    </section>
<?php endif; ?>
