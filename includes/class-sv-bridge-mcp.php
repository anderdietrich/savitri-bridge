<?php
/**
 * Servidor MCP (Model Context Protocol) sobre HTTP.
 *
 * Implementa o transporte "Streamable HTTP": o cliente envia JSON-RPC 2.0
 * por POST e recebe a resposta em JSON.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Traduz as ferramentas do canal para o protocolo MCP.
 */
class SV_Bridge_MCP {

	const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Versões que aceitamos ecoar de volta.
	 *
	 * @return string[]
	 */
	private function known_versions() {
		return array( '2025-06-18', '2025-03-26', '2024-11-05' );
	}

	/**
	 * Lista de ferramentas expostas.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function tools() {
		return array(
			array(
				'name'        => 'wp_list_files',
				'description' => 'Lista arquivos e pastas dentro de themes/ ou plugins/ do WordPress.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'      => array(
							'type'        => 'string',
							'description' => 'Caminho relativo, começando por "themes" ou "plugins". Ex.: "themes/hello-elementor-child". Vazio lista a raiz.',
						),
						'recursive' => array(
							'type'        => 'boolean',
							'description' => 'Descer nas subpastas.',
							'default'     => false,
						),
					),
					'required'   => array(),
				),
			),
			array(
				'name'        => 'wp_read_file',
				'description' => 'Lê o conteúdo de um arquivo de tema ou plugin.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Caminho relativo. Ex.: "themes/hello-elementor-child/style.css".',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			array(
				'name'        => 'wp_write_file',
				'description' => 'Cria ou sobrescreve um arquivo de tema ou plugin. Use para editar CSS, PHP e JS.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'     => array(
							'type'        => 'string',
							'description' => 'Caminho relativo. A pasta é criada se não existir.',
						),
						'content'  => array(
							'type'        => 'string',
							'description' => 'Conteúdo completo do arquivo.',
						),
						'encoding' => array(
							'type'        => 'string',
							'enum'        => array( 'text', 'base64' ),
							'default'     => 'text',
							'description' => 'Use base64 para arquivos binários.',
						),
					),
					'required'   => array( 'path', 'content' ),
				),
			),
			array(
				'name'        => 'wp_delete_file',
				'description' => 'Apaga um arquivo de tema ou plugin.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Caminho relativo do arquivo.',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			array(
				'name'        => 'wp_unzip',
				'description' => 'Extrai um .zip que já está dentro de themes/ ou plugins/.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'        => array(
							'type'        => 'string',
							'description' => 'Caminho do .zip.',
						),
						'destination' => array(
							'type'        => 'string',
							'description' => 'Pasta de destino. Vazio usa a pasta do .zip.',
						),
						'delete_zip'  => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Apagar o .zip depois de extrair.',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			array(
				'name'        => 'wp_list_plugins',
				'description' => 'Lista os plugins instalados, com versão e se estão ativos.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
			),
			array(
				'name'        => 'wp_toggle_plugin',
				'description' => 'Ativa ou desativa um plugin instalado.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Arquivo do plugin. Ex.: "savitri-file-bridge/savitri-file-bridge.php".',
						),
						'action' => array(
							'type'        => 'string',
							'enum'        => array( 'activate', 'deactivate' ),
							'description' => 'O que fazer com o plugin.',
						),
					),
					'required'   => array( 'plugin', 'action' ),
				),
			),
			array(
				'name'        => 'wp_list_themes',
				'description' => 'Lista os temas instalados, com versão e qual está ativo.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
			),
			array(
				'name'        => 'wp_activate_theme',
				'description' => 'Ativa um tema instalado.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => 'Pasta do tema. Ex.: "hello-elementor-child".',
						),
					),
					'required'   => array( 'stylesheet' ),
				),
			),
			array(
				'name'        => 'wp_list_posts',
				'description' => 'Lista posts ou páginas com id, status, título, slug, link e datas.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'type'   => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'page' ),
							'default'     => 'post',
							'description' => 'post ou page.',
						),
						'status' => array(
							'type'        => 'string',
							'description' => 'draft, publish, pending, private, future, trash, ou "any". Vazio traz todos.',
						),
						'search' => array(
							'type'        => 'string',
							'description' => 'Busca por texto no título e no conteúdo.',
						),
						'slug'   => array(
							'type'        => 'string',
							'description' => 'Filtrar por slug exato.',
						),
						'parent' => array(
							'type'        => 'integer',
							'description' => 'Páginas: ID da página pai.',
						),
						'limit'  => array(
							'type'        => 'integer',
							'default'     => 20,
							'description' => 'Máximo de itens (até 100).',
						),
						'offset' => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => 'Pular os primeiros N.',
						),
					),
					'required'   => array(),
				),
			),
			array(
				'name'        => 'wp_get_post',
				'description' => 'Lê um post ou página por ID, com conteúdo completo, taxonomias e template.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => 'ID do conteúdo.',
						),
						'with_meta' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Trazer também o meta (ex.: dados do Elementor).',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'wp_create_post',
				'description' => 'Cria um post ou página. Sem status nasce rascunho; status "publish" publica na hora. Aceita HTML e blocos.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'type'     => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'page' ),
							'default'     => 'post',
							'description' => 'post ou page.',
						),
						'title'    => array(
							'type'        => 'string',
							'description' => 'Título.',
						),
						'content'  => array(
							'type'        => 'string',
							'description' => 'Conteúdo em HTML (ou blocos do editor).',
						),
						'excerpt'  => array(
							'type'        => 'string',
							'description' => 'Resumo.',
						),
						'slug'     => array(
							'type'        => 'string',
							'description' => 'Slug da URL. Vazio gera a partir do título.',
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future' ),
							'default'     => 'draft',
							'description' => 'draft (padrão), publish, private, pending ou future.',
						),
						'date'     => array(
							'type'        => 'string',
							'description' => 'Data de publicação. Formato AAAA-MM-DD HH:MM:SS (hora do site).',
						),
						'parent'   => array(
							'type'        => 'integer',
							'description' => 'Páginas: ID da página pai.',
						),
						'categories' => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => 'Posts: nomes, slugs ou IDs de categorias (cria as que faltarem).',
						),
						'tags'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => 'Posts: tags.',
						),
						'template' => array(
							'type'        => 'string',
							'description' => 'Páginas: template (ex.: "elementor_header_footer").',
						),
						'meta'     => array(
							'type'        => 'object',
							'description' => 'Meta a gravar, chave → valor. Para página Elementor: {"_elementor_data": "[...]", "_elementor_edit_mode": "builder"}.',
						),
					),
					'required'   => array(),
				),
			),
			array(
				'name'        => 'wp_update_post',
				'description' => 'Edita um post ou página existente. Só os campos enviados mudam.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'ID do conteúdo.',
						),
						'title'   => array(
							'type'        => 'string',
							'description' => 'Novo título.',
						),
						'content' => array(
							'type'        => 'string',
							'description' => 'Novo conteúdo.',
						),
						'excerpt' => array(
							'type'        => 'string',
							'description' => 'Novo resumo.',
						),
						'slug'    => array(
							'type'        => 'string',
							'description' => 'Novo slug.',
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future', 'trash' ),
							'description' => 'publish publica, draft despublica, trash manda para a lixeira.',
						),
						'date'    => array(
							'type'        => 'string',
							'description' => 'Nova data, AAAA-MM-DD HH:MM:SS.',
						),
						'parent'  => array(
							'type'        => 'integer',
							'description' => 'Páginas: ID da página pai.',
						),
						'categories' => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => 'Substitui as categorias.',
						),
						'tags'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => 'Substitui as tags.',
						),
						'template' => array(
							'type'        => 'string',
							'description' => 'Páginas: template.',
						),
						'meta'    => array(
							'type'        => 'object',
							'description' => 'Meta a gravar; valor null apaga a chave.',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'wp_site_info',
				'description' => 'Informações do site: nome, URL, versão do WordPress, tema ativo e versão do PHP.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
			),
		);
	}

	/**
	 * Processa uma mensagem JSON-RPC e devolve a resposta.
	 *
	 * @param array $message Mensagem decodificada.
	 * @return array|null Null quando é notificação (sem resposta).
	 */
	public function handle( $message ) {
		if ( ! is_array( $message ) ) {
			return $this->rpc_error( null, -32700, 'Parse error' );
		}

		$method = isset( $message['method'] ) ? (string) $message['method'] : '';
		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		// Notificação: não tem id e não recebe resposta.
		$is_notification = ! array_key_exists( 'id', $message );

		switch ( $method ) {
			case 'initialize':
				$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
				$version   = in_array( $requested, $this->known_versions(), true ) ? $requested : self::PROTOCOL_VERSION;

				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => $version,
						'capabilities'    => array(
							'tools' => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'savitri-bridge',
							'title'   => 'WordPress — Savitri Bridge',
							'version' => SV_BRIDGE_VERSION,
						),
					)
				);

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'ping':
				return $is_notification ? null : $this->rpc_result( $id, new stdClass() );

			case 'tools/list':
				return $this->rpc_result( $id, array( 'tools' => $this->tools() ) );

			case 'tools/call':
				return $this->call_tool( $id, $params );

			case 'resources/list':
				return $this->rpc_result( $id, array( 'resources' => array() ) );

			case 'prompts/list':
				return $this->rpc_result( $id, array( 'prompts' => array() ) );

			default:
				if ( $is_notification ) {
					return null;
				}

				return $this->rpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Executa uma ferramenta.
	 *
	 * @param mixed $id     ID da mensagem.
	 * @param array $params Parâmetros.
	 * @return array
	 */
	private function call_tool( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$write_tools = array( 'wp_write_file', 'wp_delete_file', 'wp_unzip', 'wp_toggle_plugin', 'wp_activate_theme', 'wp_create_post', 'wp_update_post' );

		if ( in_array( $name, $write_tools, true ) && sv_bridge_is_readonly() ) {
			return $this->rpc_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Modo somente leitura ativo: esta ferramenta está bloqueada.',
						),
					),
					'isError' => true,
				)
			);
		}

		$result = $this->run( $name, $args );

		if ( is_wp_error( $result ) ) {
			return $this->rpc_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $result->get_error_message(),
						),
					),
					'isError' => true,
				)
			);
		}

		$text = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return $this->rpc_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $text,
					),
				),
				'isError' => false,
			)
		);
	}

	/**
	 * Implementação de cada ferramenta.
	 *
	 * @param string $name Nome da ferramenta.
	 * @param array  $args Argumentos.
	 * @return mixed
	 */
	private function run( $name, $args ) {
		$path = isset( $args['path'] ) ? (string) $args['path'] : '';

		switch ( $name ) {
			case 'wp_list_posts':
				return SV_Bridge_Content::list_posts(
					array(
						'type'   => isset( $args['type'] ) ? $args['type'] : 'post',
						'status' => isset( $args['status'] ) ? $args['status'] : '',
						'search' => isset( $args['search'] ) ? $args['search'] : '',
						'slug'   => isset( $args['slug'] ) ? $args['slug'] : '',
						'parent' => isset( $args['parent'] ) ? $args['parent'] : null,
						'limit'  => isset( $args['limit'] ) ? $args['limit'] : 20,
						'offset' => isset( $args['offset'] ) ? $args['offset'] : 0,
					)
				);

			case 'wp_get_post':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'sv_mcp_missing_id', 'Informe o id do conteúdo.' );
				}

				return SV_Bridge_Content::get( (int) $args['id'], ! empty( $args['with_meta'] ) );

			case 'wp_create_post':
				return SV_Bridge_Content::create( $args );

			case 'wp_update_post':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'sv_mcp_missing_id', 'Informe o id do conteúdo.' );
				}

				return SV_Bridge_Content::update( (int) $args['id'], $args );

			case 'wp_site_info':
				return array(
					'name'        => get_bloginfo( 'name' ),
					'url'         => home_url( '/' ),
					'wp'          => get_bloginfo( 'version' ),
					'php'         => PHP_VERSION,
					'theme'       => get_stylesheet(),
					'bridge'      => SV_BRIDGE_VERSION,
					'mcp_version' => self::PROTOCOL_VERSION,
				);

			case 'wp_list_files':
				$full = SV_Bridge_Paths::resolve( ltrim( $path, '/' ) );

				if ( is_wp_error( $full ) ) {
					return $full;
				}

				if ( ! is_dir( $full ) ) {
					return new WP_Error( 'sv_mcp_not_dir', 'Não é um diretório: ' . $path );
				}

				$recursive = ! empty( $args['recursive'] );

				SV_Bridge_Log::add( 'mcp listou', '/mcp', $path );

				return array(
					'path'    => SV_Bridge_Paths::to_relative( $full ),
					'entries' => $this->scan( $full, $recursive, 0 ),
				);

			case 'wp_read_file':
				$full = SV_Bridge_Paths::resolve_existing_file( ltrim( $path, '/' ) );

				if ( is_wp_error( $full ) ) {
					return $full;
				}

				$content = file_get_contents( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( false === $content ) {
					return new WP_Error( 'sv_mcp_read', 'Falha ao ler o arquivo.' );
				}

				SV_Bridge_Log::add( 'mcp leu', '/mcp', $path );

				return array(
					'path'    => SV_Bridge_Paths::to_relative( $full ),
					'bytes'   => strlen( $content ),
					'content' => $content,
				);

			case 'wp_write_file':
				$full = SV_Bridge_Paths::resolve( ltrim( $path, '/' ) );

				if ( is_wp_error( $full ) ) {
					return $full;
				}

				$content  = isset( $args['content'] ) ? (string) $args['content'] : '';
				$encoding = isset( $args['encoding'] ) ? (string) $args['encoding'] : 'text';

				if ( 'base64' === $encoding ) {
					$decoded = base64_decode( $content, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

					if ( false === $decoded ) {
						return new WP_Error( 'sv_mcp_base64', 'Conteúdo base64 inválido.' );
					}

					$content = $decoded;
				}

				if ( strlen( $content ) > SV_Bridge_Paths::MAX_WRITE_BYTES ) {
					return new WP_Error( 'sv_mcp_large', 'Conteúdo acima do limite de 4 MB.' );
				}

				if ( ! is_dir( dirname( $full ) ) && ! wp_mkdir_p( dirname( $full ) ) ) {
					return new WP_Error( 'sv_mcp_mkdir', 'Não foi possível criar a pasta.' );
				}

				$bytes = file_put_contents( $full, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

				if ( false === $bytes ) {
					return new WP_Error( 'sv_mcp_write', 'Falha ao gravar o arquivo.' );
				}

				$rel = SV_Bridge_Paths::to_relative( $full );

				SV_Bridge_Log::add( 'mcp gravou', '/mcp', $rel . ' (' . $bytes . ' bytes)' );

				return array(
					'path'  => $rel,
					'bytes' => $bytes,
					'sha1'  => sha1( $content ),
				);

			case 'wp_delete_file':
				$full = SV_Bridge_Paths::resolve_existing_file( ltrim( $path, '/' ) );

				if ( is_wp_error( $full ) ) {
					return $full;
				}

				$real_self = wp_normalize_path( realpath( SV_BRIDGE_DIR ) );
				$real_full = wp_normalize_path( (string) realpath( $full ) );

				if ( 0 === strpos( $real_full, $real_self ) ) {
					return new WP_Error( 'sv_mcp_self', 'O canal não pode apagar os próprios arquivos.' );
				}

				$rel = SV_Bridge_Paths::to_relative( $full );

				if ( ! unlink( $full ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					return new WP_Error( 'sv_mcp_delete', 'Falha ao apagar.' );
				}

				SV_Bridge_Log::add( 'mcp apagou', '/mcp', $rel );

				return array( 'deleted' => $rel );

			case 'wp_unzip':
				return $this->unzip( $path, $args );

			case 'wp_list_plugins':
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$all    = get_plugins();
				$active = (array) get_option( 'active_plugins', array() );
				$out    = array();

				foreach ( $all as $file => $data ) {
					$out[] = array(
						'plugin'  => $file,
						'name'    => isset( $data['Name'] ) ? $data['Name'] : $file,
						'version' => isset( $data['Version'] ) ? $data['Version'] : '',
						'active'  => in_array( $file, $active, true ),
					);
				}

				return array( 'plugins' => $out );

			case 'wp_toggle_plugin':
				if ( ! function_exists( 'activate_plugin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$plugin = isset( $args['plugin'] ) ? (string) $args['plugin'] : '';
				$action = isset( $args['action'] ) ? (string) $args['action'] : '';

				if ( ! in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
					return new WP_Error( 'sv_mcp_action', 'Ação inválida.' );
				}

				$full = SV_Bridge_Paths::resolve( 'plugins/' . $plugin );

				if ( is_wp_error( $full ) ) {
					return $full;
				}

				if ( ! file_exists( $full ) ) {
					return new WP_Error( 'sv_mcp_plugin', 'Plugin não encontrado: ' . $plugin );
				}

				if ( 'activate' === $action ) {
					$res = activate_plugin( $plugin );

					if ( is_wp_error( $res ) ) {
						return $res;
					}
				} else {
					deactivate_plugins( array( $plugin ) );
				}

				SV_Bridge_Log::add( 'mcp ' . $action . ' plugin', '/mcp', $plugin );

				return array(
					'plugin' => $plugin,
					'active' => is_plugin_active( $plugin ),
				);

			case 'wp_list_themes':
				$out = array();

				foreach ( wp_get_themes() as $stylesheet => $theme ) {
					$out[] = array(
						'stylesheet' => $stylesheet,
						'name'       => $theme->get( 'Name' ),
						'version'    => $theme->get( 'Version' ),
						'template'   => $theme->get( 'Template' ),
						'active'     => get_stylesheet() === $stylesheet,
					);
				}

				return array( 'themes' => $out );

			case 'wp_activate_theme':
				$stylesheet = isset( $args['stylesheet'] ) ? (string) $args['stylesheet'] : '';

				$full = SV_Bridge_Paths::resolve( 'themes/' . $stylesheet );

				if ( is_wp_error( $full ) ) {
					return $full;
				}

				if ( ! is_dir( $full ) ) {
					return new WP_Error( 'sv_mcp_theme', 'Tema não encontrado: ' . $stylesheet );
				}

				switch_theme( $stylesheet );

				SV_Bridge_Log::add( 'mcp ativou tema', '/mcp', $stylesheet );

				return array(
					'theme'  => $stylesheet,
					'active' => get_stylesheet() === $stylesheet,
				);
		}

		return new WP_Error( 'sv_mcp_unknown', 'Ferramenta desconhecida: ' . $name );
	}

	/**
	 * Varre um diretório.
	 *
	 * @param string $dir       Diretório.
	 * @param bool   $recursive Descer.
	 * @param int    $depth     Profundidade.
	 * @return array<int,array<string,mixed>>
	 */
	private function scan( $dir, $recursive, $depth ) {
		$out   = array();
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $items ) {
			return $out;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			$out[] = array(
				'name'  => $item,
				'path'  => SV_Bridge_Paths::to_relative( $path ),
				'type'  => is_dir( $path ) ? 'dir' : 'file',
				'bytes' => is_file( $path ) ? (int) filesize( $path ) : null,
			);

			if ( $recursive && is_dir( $path ) && $depth < 6 ) {
				$out = array_merge( $out, $this->scan( $path, $recursive, $depth + 1 ) );
			}
		}

		return $out;
	}

	/**
	 * Extrai um .zip validando as entradas.
	 *
	 * @param string $path Caminho do zip.
	 * @param array  $args Argumentos.
	 * @return array|WP_Error
	 */
	private function unzip( $path, $args ) {
		$zip_path = SV_Bridge_Paths::resolve_existing_file( ltrim( $path, '/' ) );

		if ( is_wp_error( $zip_path ) ) {
			return $zip_path;
		}

		if ( 'zip' !== strtolower( (string) pathinfo( $zip_path, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'sv_mcp_not_zip', 'O arquivo precisa ser .zip.' );
		}

		$dest_raw = isset( $args['destination'] ) ? (string) $args['destination'] : '';

		if ( '' === $dest_raw ) {
			$dest_raw = dirname( SV_Bridge_Paths::to_relative( $zip_path ) );
		}

		$dest = SV_Bridge_Paths::resolve( $dest_raw );

		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'sv_mcp_no_zip', 'A extensão ZipArchive não está disponível.' );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'sv_mcp_zip_open', 'Não foi possível abrir o .zip.' );
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$check = SV_Bridge_Paths::check_zip_entry( (string) $zip->getNameIndex( $i ) );

			if ( is_wp_error( $check ) ) {
				$zip->close();

				return $check;
			}

			// Entrada que é symlink é recusada: ela poderia apontar para fora.
			$attrs = 0;

			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attrs ) && SV_Bridge_Paths::is_symlink_entry( $attrs ) ) {
				$zip->close();

				return new WP_Error( 'sv_mcp_zip_symlink', 'O .zip contém um link simbólico.' );
			}
		}

		$ok = $zip->extractTo( $dest );
		$zip->close();

		if ( ! $ok ) {
			return new WP_Error( 'sv_mcp_unzip', 'Falha ao extrair.' );
		}

		$rel = SV_Bridge_Paths::to_relative( $zip_path );

		if ( ! empty( $args['delete_zip'] ) ) {
			unlink( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		SV_Bridge_Log::add( 'mcp extraiu', '/mcp', $rel . ' → ' . SV_Bridge_Paths::to_relative( $dest ) );

		return array(
			'zip'         => $rel,
			'destination' => SV_Bridge_Paths::to_relative( $dest ),
		);
	}

	/**
	 * Resposta JSON-RPC de sucesso.
	 *
	 * @param mixed $id     ID.
	 * @param mixed $result Resultado.
	 * @return array
	 */
	private function rpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Resposta JSON-RPC de erro.
	 *
	 * @param mixed  $id      ID.
	 * @param int    $code    Código.
	 * @param string $message Mensagem.
	 * @return array
	 */
	private function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
