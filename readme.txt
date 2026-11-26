=== Savitri Bridge ===
Contributors: anderson
Tags: mcp, rest, files, theme, plugin
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Servidor MCP para o assistente ler e editar arquivos de temas e plugins do site, e publicar
e editar posts e páginas.

== Descrição ==

Plugin-ponte de uso pessoal. Expõe um servidor MCP (Model Context Protocol) sobre HTTP
e um conjunto de rotas REST auxiliares, sempre limitados a wp-content/themes e
wp-content/plugins. Também ativa tema e ativa/desativa plugins.

As chaves são criadas em Configurações → Savitri Bridge, sem precisar editar arquivo nenhum. A tela
tem uma tabela com todas as chaves emitidas: nome da ferramenta conectada, a chave (mascarada, com
botões Mostrar/Ocultar e Copiar), data de criação, último uso com IP e as ações de editar o nome e
revogar. Cada chave serve para uma ferramenta; a que for revogada perde o acesso na hora e sai da
lista. As demais continuam válidas.

== Instalação ==

1. Envie o .zip em Plugins → Adicionar novo → Enviar plugin e ative.
2. Abra Configurações → Savitri Bridge.
3. Dê um nome à ferramenta conectada (ex.: "Maestro — Bob") e clique em "Criar chave".
   Copie o valor exibido na tabela.
4. No assistente, adicione um conector via URL:

   Nome: WordPress Savitri
   Tipo de conexão: HTTP
   Autenticação: Token
   URL do servidor: https://SEU-SITE/wp-json/sv-bridge/v1/mcp
   Token: a chave criada para essa ferramenta

Para desligar o acesso de uma ferramenta, clique em "Revogar" na linha dela. Para remover tudo,
desative e apague o plugin.

Opcional (mais seguro, tira a chave do banco): em vez de gerar pela tela, defina no
wp-config.php, antes de /* That's all, stop editing! */:

   define( 'SV_FILE_BRIDGE_KEY', 'chave-de-64-caracteres' );

A constante tem prioridade sobre as chaves criadas na tela e desliga a tabela.

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

== Changelog ==

= 2.4.1 =
* Segurança: o canal não grava mais nos próprios arquivos. Antes, apagar era bloqueado mas
  sobrescrever não: com uma chave seria possível trocar o código do plugin e deixar algo
  permanente no site. Vale para REST e para as ferramentas MCP, inclusive com base64.
* Teste dedicado (`tests/self.php`) cobre os dois caminhos e confirma que gravar e apagar em
  temas e em outros plugins continua funcionando.

= 2.4.1 =
* Painel de configuração redesenhado: cartões, tabela de chaves com status de uso, atalhos de
  conexão e rodapé de log.
* Atualização pelo GitHub: o painel de plugins passa a ver a versão publicada no repositório e
  oferece "Atualizar agora". Repositório e token configuráveis na tela (token só para repo privado).
* Correção do "criar chave não funciona": as rotas passaram a ser registradas mesmo sem chave
  (respondiam 404, que o cache de página guardava) e o cache do canal é descartado ao criar,
  renomear ou revogar uma chave. Respostas do canal vão com cabeçalho de não-cachear.

= 2.4.1 =
* Tabela de chaves: cada chave tem nome da ferramenta conectada, data de criação, último uso
  com IP, editar nome e revogar. A chave só sai da lista quando é revogada.
* Várias chaves ao mesmo tempo: cada ferramenta usa a sua e revogar uma não derruba as outras.
* Correção: ferramentas sem parâmetros iam com "properties": [] e listas de tipo iam como
  "type": ["string","integer"], o que é schema JSON inválido e fazia o provedor recusar a
  lista inteira de ferramentas. Agora sai "properties": {} e "anyOf".
* Botão "Liberar bloqueio" para zerar o limite de tentativas do próprio IP.
* Avisos de confirmação para criar, renomear e revogar.
* Migração automática da chave única anterior para a tabela.
