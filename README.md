# DW Dropbox Connect

Conecta uma conta pessoal do Dropbox ao seletor de mídia do WordPress. A importação vira um anexo nativo da Biblioteca. O envio copia a seleção de volta para a pasta aberta no Dropbox.

O arquivo [`readme.txt`](readme.txt) é a descrição no formato do WordPress: instalação, permissões, privacidade, perguntas frequentes e changelog.

## O que faz

- Aba **Dropbox** no seletor `wp.media`, com pastas, busca, paginação e miniaturas.
- Importação com `media_handle_sideload`. O conteúdo publicado não guarda URL do Dropbox.
- **Enviar seleção** para a pasta aberta. Nome repetido ganha sufixo; nada é substituído nem apagado.
- Botão **Dropbox** em Mídia → Biblioteca.
- OAuth 2.0 com refresh token. App secret e tokens ficam cifrados no site.

## Requisitos

- WordPress 6.2 ou superior (testado em 7.1)
- PHP 7.4 ou superior, com OpenSSL
- App do Dropbox em Scoped access, tipo Full Dropbox
- Permissões: `account_info.read`, `files.metadata.read`, `files.content.read`, `files.content.write`

## Instalação

1. Copie a pasta `dw-dropbox-connect` para `wp-content/plugins/` e ative o plugin.
2. Em [Dropbox App Console](https://www.dropbox.com/developers/apps), cadastre o Redirect URI mostrado em **Configurações → Dropbox**.
3. Informe a App key e o App secret na tela, ou defina `DWDC_APP_KEY` e `DWDC_APP_SECRET` no `wp-config.php`.
4. Conecte a conta. Se a escrita foi liberada depois da primeira conexão, desconecte e conecte de novo.

Quem tem `upload_files` usa a conta conectada. Quem tem `manage_options` altera a conexão.

## Licença

[GPL-2.0-or-later](LICENSE).
