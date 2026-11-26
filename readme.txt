=== Savitri Bridge ===
Contributors: anderson
Tags: mcp, rest, files, theme, plugin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Servidor MCP para o assistente ler e editar arquivos de temas e plugins do site, e publicar
e editar posts e páginas.

== Descrição ==

Plugin-ponte de uso pessoal. Expõe um servidor MCP (Model Context Protocol) sobre HTTP
e um conjunto de rotas REST auxiliares, sempre limitados a wp-content/themes e
wp-content/plugins. Também ativa tema e ativa/desativa plugins.

A chave é gerada em Configurações → Savitri Bridge, sem precisar editar arquivo nenhum. Na tela
ela aparece mascarada, com botões para mostrar/ocultar e copiar.

== Instalação ==

1. Envie o .zip em Plugins → Adicionar novo → Enviar plugin e ative.
2. Abra Configurações → Savitri Bridge.
3. Clique em "Gerar chave" e copie o valor exibido.
4. No assistente, adicione um conector via URL:

   Nome: WordPress Savitri
   Tipo de conexão: HTTP
   Autenticação: Token
   URL do servidor: https://SEU-SITE/wp-json/sv-bridge/v1/mcp
   Token: a chave gerada

Para desligar o acesso, clique em "Revogar chave". Para remover, desative e apague o plugin.

Opcional (mais seguro, tira a chave do banco): em vez de gerar pela tela, defina no
wp-config.php, antes de /* That's all, stop editing! */:

   define( 'SV_FILE_BRIDGE_KEY', 'chave-de-64-caracteres' );

A constante tem prioridade sobre a chave gerada na tela.

== Ferramentas MCP ==

- wp_site_info — nome, URL, versão do WP, tema ativo e versão do PHP
- wp_list_files — lista arquivos de themes/ ou plugins/
- wp_read_file — lê um arquivo
- wp_write_file — cria ou sobrescreve (texto ou base64)
- wp_delete_file — apaga um arquivo
- wp_unzip — extrai um .zip que já está no servidor
- wp_list_plugins — lista plugins e se estão ativos
- wp_toggle_plugin — ativa ou desativa plugin
- wp_list_themes — lista temas
- wp_activate_theme — ativa um tema
- wp_list_posts — lista posts ou páginas (status, busca, slug, limite)
- wp_get_post — lê um conteúdo por ID (com HTML, taxonomias e template)
- wp_create_post — cria post ou página (rascunho por padrão, "publish" publica)
- wp_update_post — edita conteúdo, status, taxonomias, template e meta

== Rotas REST auxiliares ==

Base: /wp-json/sv-bridge/v1

Autenticação: X-SV-Bridge-Key: <chave> ou Authorization: Bearer <chave>

- POST /mcp (JSON-RPC 2.0) — o servidor MCP
- GET  /ping
- GET  /list?path=themes&recursive=1
- GET  /file?path=themes/meu-tema/style.css
- POST /file   { path, content, encoding: "text" | "base64" }
- DELETE /file?path=themes/meu-tema/velho.css
- POST /dir    { path }
- POST /unzip  { path, destination, delete_zip }
- GET  /plugins
- POST /plugin { plugin, action }
- GET  /themes
- POST /theme  { stylesheet }
- GET  /posts?type=post&status=draft&limit=20
- GET  /post?id=123&with_meta=1
- POST /post   { type, title, content, status, slug, categories, tags, template, meta }
- PUT  /post   { id, title, content, status, ... }

== Segurança ==

- Escopo fechado em wp-content/themes e wp-content/plugins.
- Rejeita travessia (..), byte nulo, caminho absoluto, unidade Windows e symlink que escapa da base.
- No .zip valida todas as entradas antes de extrair, e recusa entradas que sejam symlink.
- Comparação da chave com hash_equals (tempo constante).
- 5 tentativas erradas por IP a cada 10 minutos → bloqueio temporário.
- A chave nunca aparece em log nem em mensagem de erro.
- O canal não apaga os próprios arquivos.
- Modo somente leitura opcional: bloqueia toda escrita, deixando só listar e ler.
- Todas as operações ficam registradas em Configurações → Savitri Bridge.
- Recomendado HTTPS: o token trafega no cabeçalho.

Aviso: quem tiver a chave pode escrever código PHP no site. Trate como senha de administrador
e revogue quando o trabalho terminar.
