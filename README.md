# Savitri Bridge

Plugin WordPress (uso pessoal) que conecta o site a um assistente por **MCP (Model Context Protocol)**,
para ler e editar arquivos de temas e plugins, ativar tema/plugins e **publicar e editar posts e páginas**.

Escopo do disco fechado em `wp-content/themes` e `wp-content/plugins`. Conteúdo (posts/páginas) passa
pela API do WordPress.

## Instalação

1. Baixe o zip em [Releases](../../releases) (ou gere com `./build.sh`, que cria `../savitri-file-bridge.zip`).
2. Plugins → Adicionar novo → Enviar plugin → ativar.
3. Configurações → **Savitri Bridge** → *Gerar chave*.
4. No assistente, adicione um conector via URL:

   | Campo | Valor |
   |---|---|
   | Nome | WordPress Savitri |
   | Tipo | HTTP |
   | Autenticação | Token |
   | URL | `https://SEU-SITE/wp-json/sv-bridge/v1/mcp` |
   | Token | a chave gerada (botão *Copiar*) |

## Telas

Na tela de configuração a chave aparece **mascarada**, com botões **Mostrar / Ocultar** e **Copiar**.
Há também o modo **somente leitura** (bloqueia toda escrita) e o log das últimas operações.

## Ferramentas MCP

**Arquivos e site**

| Ferramenta | O que faz |
|---|---|
| `wp_site_info` | nome, URL, versão do WP, tema ativo, versão do PHP |
| `wp_list_files` | lista arquivos de `themes/` ou `plugins/` |
| `wp_read_file` | lê um arquivo |
| `wp_write_file` | cria ou sobrescreve (texto ou base64) |
| `wp_delete_file` | apaga um arquivo |
| `wp_unzip` | extrai um `.zip` que já está no servidor |
| `wp_list_plugins` | lista plugins e se estão ativos |
| `wp_toggle_plugin` | ativa ou desativa plugin |
| `wp_list_themes` | lista temas |
| `wp_activate_theme` | ativa um tema |

**Conteúdo**

| Ferramenta | O que faz |
|---|---|
| `wp_list_posts` | lista posts ou páginas (status, busca, slug, pai, limite) |
| `wp_get_post` | lê um conteúdo por ID (HTML, taxonomias, template, meta) |
| `wp_create_post` | cria post ou página — **rascunho por padrão**, `status: "publish"` publica |
| `wp_update_post` | edita conteúdo, status, taxonomias, template e meta |

`wp_create_post` e `wp_update_post` aceitam categorias/tags por nome, slug ou ID (criam as que faltarem),
`template` de página e `meta` — inclusive `_elementor_data`, com invalidação do `_elementor_css` para o
Elementor regerar o CSS.

## Rotas REST auxiliares

Base: `/wp-json/sv-bridge/v1` — autenticação por `X-SV-Bridge-Key: <chave>` ou `Authorization: Bearer <chave>`.

```
POST   /mcp                    JSON-RPC 2.0 (o servidor MCP)
GET    /ping
GET    /list?path=themes&recursive=1
GET    /file?path=themes/meu-tema/style.css
POST   /file                   { path, content, encoding: "text" | "base64" }
DELETE /file?path=themes/meu-tema/velho.css
POST   /dir                    { path }
POST   /unzip                  { path, destination, delete_zip }
GET    /plugins
POST   /plugin                 { plugin, action }
GET    /themes
POST   /theme                  { stylesheet }
GET    /posts?type=post&status=draft&limit=20
GET    /post?id=123&with_meta=1
POST   /post                   { type, title, content, status, slug, categories, tags, template, meta }
PUT    /post                   { id, title, content, status, ... }
```

## Segurança

- Escopo de disco fechado em `wp-content/themes` e `wp-content/plugins`.
- Rejeita travessia (`..`), byte nulo, caminho absoluto, unidade Windows e symlink que escapa da base.
- No `.zip` valida todas as entradas antes de extrair e recusa symlink.
- Chave comparada com `hash_equals`; 5 tentativas erradas por IP a cada 10 min → bloqueio temporário.
- A chave nunca aparece em log nem em mensagem de erro.
- O canal não apaga os próprios arquivos.
- Modo somente leitura opcional.

> Quem tiver a chave pode escrever PHP no site. Trate como senha de administrador e revogue quando o trabalho terminar.

## Testes

Sem WordPress, com stubs em PHP puro (CLI):

```bash
php tests/run.php       # núcleo de caminhos e zip
php tests/routes.php    # rotas REST
php tests/auth.php      # autenticação e força bruta
php tests/mcp.php       # protocolo MCP
php tests/content.php   # posts e páginas
php tests/security.php  # auditoria de segurança
```

## Estrutura

```
savitri-file-bridge.php          bootstrap, constantes, hooks
includes/class-sv-bridge-key.php       chave (constante ou banco)
includes/class-sv-bridge-auth.php      autenticação e limite de tentativas
includes/class-sv-bridge-paths.php     escopo e validação de caminhos/zip
includes/class-sv-bridge-mcp.php       servidor MCP (JSON-RPC 2.0)
includes/class-sv-bridge-rest.php      rotas REST
includes/class-sv-bridge-content.php   posts e páginas
includes/class-sv-bridge-settings.php  tela de configuração
includes/class-sv-bridge-log.php       log das operações
tests/                                 testes em CLI
```
