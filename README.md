# DW Dropbox Connect

Conecta uma conta pessoal do Dropbox ao WordPress de duas formas: no seletor de mídia, para importar e enviar arquivos, e em **galerias**, que mostram no site as imagens de uma pasta do Dropbox e acompanham as mudanças feitas nela.

O arquivo [`readme.txt`](readme.txt) é a descrição no formato do WordPress: instalação, permissões, privacidade, perguntas frequentes e changelog.

## Galerias

Uma galeria mostra as imagens de uma pasta do Dropbox, no estilo de um feed de rede social. Imagem enviada, trocada ou apagada no Dropbox muda no site sozinha, sem passar pela Biblioteca de mídia.

1. Em **Configurações → Dropbox → Galerias**, criar a galeria e escolher a pasta.
2. Colocar na página com o bloco **Galeria do Dropbox** ou com o shortcode `[dw_dropbox_gallery id="abcd1234"]`. Elementor e outros construtores usam o shortcode.

- Grade ou mosaico, colunas para computador, tablet e celular, espaço entre as imagens.
- Miniaturas quadradas ou na proporção original, botão Carregar mais e lightbox com teclado e gesto de deslizar.
- Ordem por data de envio ou por nome, limite de até 500 imagens, subpastas opcionais.
- As imagens são redimensionadas pelo Dropbox e guardadas no site em `uploads/dw-dropbox-connect-gallery/`. O visitante nunca fala com o Dropbox.

Como a atualização chega ao site:

- O WP-Cron confere cada galeria no intervalo escolhido (de 5 minutos a uma vez por dia).
- Webhook opcional: cadastrar `/wp-json/dwdc/v1/webhook` em Webhooks no Dropbox App Console. O Dropbox avisa a mudança e a galeria atualiza em segundos. A assinatura `X-Dropbox-Signature` é conferida em cada aviso.
- Páginas em cache perguntam ao site se a galeria mudou e trocam a lista na hora. O gancho `dwdc_feed_changed` dispara a cada mudança, para plugins de cache que preferem limpar.

As imagens de uma galeria são públicas. A galeria expõe só a pasta escolhida: os endereços públicos aceitam o ID da galeria e de um arquivo que já está nela, nunca um caminho do Dropbox.

## O que faz (seletor de mídia)

- Aba **Dropbox** no seletor `wp.media`, com pastas, busca, paginação e miniaturas.
- Importação com `media_handle_sideload`. O conteúdo publicado não guarda URL do Dropbox.
- **Enviar seleção** para a pasta aberta. Nome repetido ganha sufixo; nada é substituído nem apagado.
- Botão **Dropbox** em Mídia → Biblioteca.
- OAuth 2.0 com refresh token. App secret e tokens ficam cifrados no site.

## Requisitos

- WordPress 6.2 ou superior (testado em 7.1)
- PHP 7.4 ou superior, com OpenSSL
- App do Dropbox em Scoped access, tipo Full Dropbox
- Permissões: `account_info.read`, `files.metadata.read`, `files.content.read`, `files.content.write` (as galerias usam só leitura, sem permissão nova)

## Instalação

1. Copie a pasta `dw-dropbox-connect` para `wp-content/plugins/` e ative o plugin.
2. Em [Dropbox App Console](https://www.dropbox.com/developers/apps), cadastre o Redirect URI mostrado em **Configurações → Dropbox**.
3. Informe a App key e o App secret na tela, ou defina `DWDC_APP_KEY` e `DWDC_APP_SECRET` no `wp-config.php`.
4. Conecte a conta. Se a escrita foi liberada depois da primeira conexão, desconecte e conecte de novo.

Quem tem `upload_files` usa a conta conectada. Quem tem `manage_options` altera a conexão.

## Atualizações

O WordPress consulta a última release deste repositório. Uma versão maior, com o arquivo `dw-dropbox-connect.zip` anexado, aparece em Plugins. Um commit na branch `main` não publica atualização.

## Licença

[GPL-2.0-or-later](LICENSE).
