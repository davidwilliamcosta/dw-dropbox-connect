=== DW Dropbox Connect ===
Contributors: davidwilliamdacosta
Tags: dropbox, media library, oauth
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecta uma conta pessoal do Dropbox ao seletor de mídia, importa anexos nativos e envia a seleção de volta.

== Description ==

DW Dropbox Connect coloca uma conta pessoal do Dropbox dentro do seletor nativo `wp.media`. A navegação, a busca, o download e o envio acontecem no servidor. O navegador não recebe o token do Dropbox.

Importar grava uma cópia na Biblioteca de Mídia com `media_handle_sideload`. Gutenberg, o editor clássico, Elementor e WooCommerce recebem um anexo normal, com ID, arquivo local e metadados gerados pelo WordPress. O conteúdo publicado não guarda URL temporária do Dropbox.

Enviar faz o caminho inverso: a seleção da Biblioteca é copiada para a pasta aberta no Dropbox. Um nome que já existe no destino ganha um sufixo. O plugin não substitui, não move e não apaga nada no Dropbox.

Não há credencial embutida. A App key e o App secret vêm da tela de configuração ou de `DWDC_APP_KEY` e `DWDC_APP_SECRET` no `wp-config.php`.

= O que a aba Dropbox faz =

* Lista a pasta atual, entra em pastas e volta pela trilha.
* Pagina com o cursor do Dropbox. Atualizar pede a pasta de novo. Carregar mais só traz a página seguinte.
* Busca a partir de dois caracteres e também pagina a busca.
* Mostra miniatura dos formatos que o Dropbox gera: jpg, jpeg, png, gif, webp, tif, tiff e bmp.
* Importa o arquivo escolhido. Com “Incluir na seleção” marcado, o anexo entra na seleção do seletor ainda na aba Dropbox.
* Quando o nome sanitizado já existe na Biblioteca e o usuário pode editar esse anexo, o cartão oferece “Já contém item” e usa esse anexo, sem baixar de novo. A comparação é pelo nome, não pelo conteúdo.
* Na seleção múltipla, marca vários arquivos e importa de uma vez.
* Em Mídia → Biblioteca, o botão Dropbox abre o mesmo seletor. Arquivos importados passam a aparecer na grade ao concluir.
* Envia para a pasta aberta os anexos marcados na Biblioteca. Na grade de Mídia, marque os itens antes de abrir o Dropbox.

= Serviço externo =

O plugin fala apenas com a API do Dropbox, e só depois que um administrador conecta a conta:

* `https://www.dropbox.com` para a autorização OAuth.
* `https://api.dropbox.com` para metadados, busca e troca de token.
* `https://content.dropboxapi.com` para download, miniatura e envio.

Nada é enviado na ativação. Não há telemetria, anúncio nem script carregado de fora. CSS e JavaScript vêm da pasta do plugin.

= Permissões pedidas ao Dropbox =

* `account_info.read` para mostrar o nome da conta.
* `files.metadata.read` para listar e buscar.
* `files.content.read` para baixar e gerar miniatura.
* `files.content.write` para criar a cópia na pasta aberta.

O token antigo não ganha permissão nova sozinho. Depois de marcar `files.content.write` no app, desconecte e conecte de novo.

= Quem pode usar a conta conectada =

A conexão é do site, não de cada usuário. Quem tem a capacidade `upload_files` navega, importa e envia. Quem tem `manage_options` grava as credenciais, conecta e desconecta. O envio ainda exige poder editar aquele anexo. Restrinja `upload_files` se a conta tiver arquivos reservados.

= O que fica gravado no WordPress =

* App secret, access token e refresh token, cifrados com AES-256-CBC e autenticados com HMAC-SHA256. A chave deriva dos salts `auth` e `secure_auth`.
* App key e o nome de exibição da conta Dropbox.
* Transientes de listagem, busca, limite de pedidos e o estado OAuth de uso único.
* Miniaturas JPEG em `wp-content/uploads/dw-dropbox-connect-cache/`, com nome HMAC. No Apache, um `.htaccess` nega acesso direto.
* Os anexos importados, como qualquer outro arquivo da Biblioteca.

Trocar os salts impede a leitura dos segredos e exige reconectar. Tokens gravados por uma versão anterior, sem HMAC, continuam sendo lidos e passam ao formato novo na próxima gravação.

= Segurança da API =

Os endpoints `dwdc/v1` exigem usuário autenticado com `upload_files` e o nonce REST `wp_rest`. Cada rota declara o tipo dos argumentos. Caminho, cursor e busca passam por validação própria: sem `..`, sem barra final solta e sem texto de controle. A importação respeita o tamanho máximo de upload e os tipos permitidos pelo WordPress, inclusive `upload_mimes`. O envio só lê um arquivo que esteja dentro de `uploads` e que o usuário possa editar. A miniatura só é servida se o arquivo estiver no cache do plugin e for um JPEG.

O retorno OAuth usa `state` de uso único, preso ao usuário que iniciou a conexão. A ida ao Dropbox só segue se o endereço for `https://www.dropbox.com` ou `https://dropbox.com`.

== Installation ==

1. Copie a pasta `dw-dropbox-connect` para `wp-content/plugins/`.
2. Ative DW Dropbox Connect. A extensão OpenSSL do PHP é obrigatória.
3. Abra Configurações → Dropbox.

= Configuração no Dropbox App Console =

1. Crie um app em https://www.dropbox.com/developers/apps.
2. Escolha Scoped access e o tipo Full Dropbox. Não use App folder.
3. Em Permissions, marque `account_info.read`, `files.metadata.read`, `files.content.read` e `files.content.write`. Envie as alterações. Se a conta já estava conectada, conecte de novo para o token receber a escrita.
4. Em Settings → OAuth 2, cadastre o Redirect URI exatamente como a tela do plugin mostra. Ele aponta para `wp-admin/admin-post.php?action=dwdc_oauth_callback`.
5. Copie a App key e o App secret. Cole nos campos da tela, ou defina os dois no `wp-config.php`:

`
define( 'DWDC_APP_KEY', 'cole-a-app-key' );
define( 'DWDC_APP_SECRET', 'cole-o-app-secret' );
`

As constantes têm prioridade e precisam existir juntas. `WPDM_APP_KEY` e `WPDM_APP_SECRET` ainda são lidas se as novas não estiverem definidas.

6. Salve e clique em Conectar Dropbox. Autorize a conta pessoal.
7. Abra o seletor de mídia no editor, em Mídia → Adicionar novo, ou o botão Dropbox em Mídia → Biblioteca.

Enquanto o app estiver em desenvolvimento, o Dropbox só autoriza o dono do app e os testadores adicionados no console.

== Frequently Asked Questions ==

= O arquivo importado continua ligado ao Dropbox? =

Não. A importação cria um anexo local. Apagar o arquivo no Dropbox não apaga o anexo, e apagar o anexo não apaga o Dropbox.

= Posso enviar da Biblioteca para o Dropbox? =

Sim. Marque os anexos na Biblioteca, abra a pasta de destino na aba Dropbox e use Enviar seleção. O plugin cria uma cópia. Não substitui um arquivo existente.

= Por que o envio responde que falta permissão? =

O app precisa ter `files.content.write` e o token precisa ter sido emitido depois disso. Desconecte e conecte de novo.

= Quem enxerga os arquivos da conta? =

Qualquer usuário com `upload_files`. A conta conectada é compartilhada pelo site.

= O que a desinstalação remove? =

As options do plugin, os transientes e o diretório de miniaturas. Os anexos já importados permanecem na Biblioteca.

= O cache de miniaturas fica público no Nginx? =

O `.htaccess` só vale no Apache. Se a pasta de uploads puder ser lida por URL, bloqueie `dw-dropbox-connect-cache` no servidor.

== Changelog ==

= 0.1.0 =
* Primeira versão pública.
* Aba Dropbox no seletor nativo, com pastas, busca, paginação, miniaturas e importação para a Biblioteca.
* Envio da seleção da Biblioteca para a pasta aberta no Dropbox.
* Botão Dropbox na Biblioteca de Mídia.
* OAuth com refresh token, segredos cifrados e endpoints restritos a quem pode enviar arquivos.

== Upgrade Notice ==

= 0.1.0 =
Primeira versão pública. Se o app do Dropbox já estava conectado antes de `files.content.write`, desconecte e conecte de novo para o envio funcionar.

== Privacy ==

O plugin não coleta dados para o autor. Os pedidos ao Dropbox levam o token da conta conectada e o caminho, o nome ou o conteúdo do arquivo que o usuário escolheu listar, buscar, importar ou enviar. O nome de exibição da conta Dropbox fica gravado no site para a tela de configuração. Consulte também a política do Dropbox.
