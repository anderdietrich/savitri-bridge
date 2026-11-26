<?php
/**
 * Rotas REST do canal.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define os endpoints e executa as operações.
 */
class SV_Bridge_REST {

	const NS = 'sv-bridge/v1';

	/**
	 * Registra as rotas.
	 */
	public function register_routes() {
		$auth = array( $this, 'can' );

		// Servidor MCP (tela de conectores).
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'mcp' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NS,
			'/list',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_dir' ),
				'permission_callback' => $auth,
				'args'                => array(
					'path'      => array( 'required' => false, 'default' => '' ),
					'recursive' => array( 'required' => false, 'default' => false ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/file',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'read_file' ),
					'permission_callback' => $auth,
					'args'                => array(
						'path' => array( 'required' => true ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'write_file' ),
					'permission_callback' => $auth,
					'args'                => array(
						'path'     => array( 'required' => true ),
						'content'  => array( 'required' => true ),
						'encoding' => array( 'required' => false, 'default' => 'text' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_file' ),
					'permission_callback' => $auth,
					'args'                => array(
						'path' => array( 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/dir',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'make_dir' ),
				'permission_callback' => $auth,
				'args'                => array(
					'path' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/unzip',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'unzip' ),
				'permission_callback' => $auth,
				'args'                => array(
					'path'        => array( 'required' => true ),
					'destination' => array( 'required' => false, 'default' => '' ),
					'delete_zip'  => array( 'required' => false, 'default' => true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/plugins',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_plugins' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NS,
			'/plugin',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_plugin' ),
				'permission_callback' => $auth,
				'args'                => array(
					'plugin' => array( 'required' => true ),
					'action' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_themes' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NS,
			'/theme',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate_theme' ),
				'permission_callback' => $auth,
				'args'                => array(
					'stylesheet' => array( 'required' => true ),
				),
			)
		);

		// Conteúdo: posts e páginas.
		register_rest_route(
			self::NS,
			'/posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => $auth,
				'args'                => array(
					'type'   => array( 'required' => false, 'default' => 'post' ),
					'status' => array( 'required' => false, 'default' => '' ),
					'search' => array( 'required' => false, 'default' => '' ),
					'slug'   => array( 'required' => false, 'default' => '' ),
					'parent' => array( 'required' => false ),
					'limit'  => array( 'required' => false, 'default' => 20 ),
					'offset' => array( 'required' => false, 'default' => 0 ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/post',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => array( 'PUT', 'PATCH' ),
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => $auth,
					'args'                => array(
						'id' => array( 'required' => true ),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_post_route' ),
					'permission_callback' => $auth,
					'args'                => array(
						'id'        => array( 'required' => true ),
						'with_meta' => array( 'required' => false, 'default' => false ),
					),
				),
			)
		);
	}

	/**
	 * Permissão de todas as rotas.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return true|WP_Error
	 */
	public function can( $request ) {
		return SV_Bridge_Auth::permission( $request );
	}

	/* ---------------------------------------------------------
	 * Servidor MCP
	 * ------------------------------------------------------- */

	/**
	 * Endpoint MCP (JSON-RPC 2.0 sobre HTTP).
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function mcp( $request ) {
		$method = strtoupper( $request->get_method() );

		// GET/DELETE no endpoint MCP não abrem stream nem encerram sessão aqui.
		if ( 'POST' !== $method ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'server'  => 'savitri-bridge',
					'version' => SV_BRIDGE_VERSION,
					'transport' => 'streamable-http (stateless)',
					'hint'    => 'Envie JSON-RPC 2.0 via POST.',
				),
				200
			);
		}

		$raw = $request->get_body();

		if ( '' === trim( (string) $raw ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array( 'code' => -32700, 'message' => 'Corpo vazio.' ),
				),
				400
			);
		}

		$payload = json_decode( $raw, true );

		if ( null === $payload && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array( 'code' => -32700, 'message' => 'JSON inválido.' ),
				),
				400
			);
		}

		$server = new SV_Bridge_MCP();

		// Lote (JSON-RPC batch).
		if ( is_array( $payload ) && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) && ! empty( $payload ) ) {
			$out = array();

			foreach ( $payload as $message ) {
				$response = $server->handle( $message );

				if ( null !== $response ) {
					$out[] = $response;
				}
			}

			if ( empty( $out ) ) {
				return new WP_REST_Response( null, 202 );
			}

			return new WP_REST_Response( $out, 200 );
		}

		$response = $server->handle( $payload );

		// Notificação: 202 sem corpo.
		if ( null === $response ) {
			return new WP_REST_Response( null, 202 );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Rotas de escrita bloqueadas no modo somente leitura.
	 *
	 * @return bool
	 */
	private function readonly_blocked() {
		return sv_bridge_is_readonly();
	}

	/**
	 * Erro padrão de modo somente leitura.
	 *
	 * @return WP_REST_Response
	 */
	private function readonly_error() {
		return $this->fail(
			new WP_Error(
				'sv_bridge_readonly',
				__( 'Modo somente leitura ativo: escrita bloqueada.', 'savitri-file-bridge' ),
				array( 'status' => 403 )
			)
		);
	}

	/* ---------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------- */

	/**
	 * Resposta de erro padronizada.
	 *
	 * @param WP_Error $error Erro.
	 * @return WP_REST_Response
	 */
	private function fail( $error ) {
		$data = $error->get_error_data();
		$code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		return new WP_REST_Response(
			array(
				'ok'    => false,
				'error' => $error->get_error_code(),
				'msg'   => $error->get_error_message(),
			),
			$code
		);
	}

	/**
	 * Caminho relativo a partir do parâmetro, aceitando também absoluto.
	 *
	 * @param string $raw Valor recebido.
	 * @return string
	 */
	private function relative( $raw ) {
		$raw = wp_normalize_path( (string) $raw );

		$content = wp_normalize_path( WP_CONTENT_DIR );

		if ( 0 === strpos( $raw, $content . '/' ) ) {
			$raw = substr( $raw, strlen( $content ) + 1 );
		}

		return ltrim( $raw, '/' );
	}

	/**
	 * Grava conteúdo em um arquivo.
	 *
	 * @param string $full    Caminho absoluto validado.
	 * @param string $content Conteúdo.
	 * @return true|WP_Error
	 */
	private function put( $full, $content ) {
		if ( ! is_dir( dirname( $full ) ) ) {
			if ( ! wp_mkdir_p( dirname( $full ) ) ) {
				return new WP_Error( 'sv_bridge_mkdir', __( 'Não foi possível criar a pasta de destino.', 'savitri-file-bridge' ), array( 'status' => 500 ) );
			}
		}

		if ( strlen( $content ) > SV_Bridge_Paths::MAX_WRITE_BYTES ) {
			return new WP_Error(
				'sv_bridge_too_large',
				sprintf(
					/* translators: %d: limite em MB */
					__( 'Conteúdo maior que o limite de %d MB.', 'savitri-file-bridge' ),
					(int) ( SV_Bridge_Paths::MAX_WRITE_BYTES / 1048576 )
				),
				array( 'status' => 413 )
			);
		}

		$bytes = file_put_contents( $full, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $bytes ) {
			return new WP_Error( 'sv_bridge_write_failed', __( 'Falha ao gravar o arquivo.', 'savitri-file-bridge' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/* ---------------------------------------------------------
	 * Endpoints
	 * ------------------------------------------------------- */

	/**
	 * Prova de vida.
	 *
	 * @return WP_REST_Response
	 */
	public function ping() {
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'plugin'  => 'savitri-file-bridge',
				'version' => SV_BRIDGE_VERSION,
				'scope'   => array_keys( SV_Bridge_Paths::bases() ),
			),
			200
		);
	}

	/**
	 * Lista um diretório.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function list_dir( $request ) {
		$full = SV_Bridge_Paths::resolve( $this->relative( $request->get_param( 'path' ) ) );

		if ( is_wp_error( $full ) ) {
			return $this->fail( $full );
		}

		if ( ! is_dir( $full ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_not_dir', __( 'Não é um diretório.', 'savitri-file-bridge' ), array( 'status' => 400 ) ) );
		}

		$recursive = rest_sanitize_boolean( $request->get_param( 'recursive' ) );
		$entries   = $this->scan( $full, $recursive, 0 );

		SV_Bridge_Log::add( 'listou', $request->get_route(), SV_Bridge_Paths::to_relative( $full ) );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'path'    => SV_Bridge_Paths::to_relative( $full ),
				'entries' => $entries,
			),
			200
		);
	}

	/**
	 * Varre um diretório.
	 *
	 * @param string $dir       Diretório absoluto.
	 * @param bool   $recursive Descer em subpastas.
	 * @param int    $depth     Profundidade atual.
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
	 * Lê um arquivo.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function read_file( $request ) {
		$full = SV_Bridge_Paths::resolve_existing_file( $this->relative( $request->get_param( 'path' ) ) );

		if ( is_wp_error( $full ) ) {
			return $this->fail( $full );
		}

		$content = file_get_contents( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $content ) {
			return $this->fail( new WP_Error( 'sv_bridge_read_failed', __( 'Falha ao ler o arquivo.', 'savitri-file-bridge' ), array( 'status' => 500 ) ) );
		}

		SV_Bridge_Log::add( 'leu', $request->get_route(), SV_Bridge_Paths::to_relative( $full ) );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'path'    => SV_Bridge_Paths::to_relative( $full ),
				'bytes'   => strlen( $content ),
				'sha1'    => sha1( $content ),
				'content' => $content,
			),
			200
		);
	}

	/**
	 * Escreve um arquivo.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function write_file( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		$rel = $this->relative( $request->get_param( 'path' ) );

		$full = SV_Bridge_Paths::resolve( $rel );

		if ( is_wp_error( $full ) ) {
			return $this->fail( $full );
		}

		if ( SV_Bridge_Paths::is_self( $full ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_self', __( 'O canal não pode gravar nos próprios arquivos.', 'savitri-file-bridge' ), array( 'status' => 403 ) ) );
		}

		$blocked = SV_Bridge_Paths::blocked_extensions();
		$ext     = strtolower( (string) pathinfo( $full, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, $blocked, true ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_blocked_ext', __( 'Extensão bloqueada.', 'savitri-file-bridge' ), array( 'status' => 403 ) ) );
		}

		$encoding = (string) $request->get_param( 'encoding' );
		$content  = (string) $request->get_param( 'content' );

		if ( 'base64' === $encoding ) {
			$decoded = base64_decode( $content, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( false === $decoded ) {
				return $this->fail( new WP_Error( 'sv_bridge_bad_base64', __( 'Conteúdo base64 inválido.', 'savitri-file-bridge' ), array( 'status' => 400 ) ) );
			}

			$content = $decoded;
		}

		$result = $this->put( $full, $content );

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		$rel_path = SV_Bridge_Paths::to_relative( $full );

		SV_Bridge_Log::add( 'gravou', $request->get_route(), $rel_path . ' (' . strlen( $content ) . ' bytes)' );

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'path'  => $rel_path,
				'bytes' => strlen( $content ),
				'sha1'  => sha1( $content ),
			),
			200
		);
	}

	/**
	 * Apaga um arquivo.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function delete_file( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		$full = SV_Bridge_Paths::resolve_existing_file( $this->relative( $request->get_param( 'path' ) ) );

		if ( is_wp_error( $full ) ) {
			return $this->fail( $full );
		}

		// Não deixa o canal apagar a si mesmo.
		if ( 0 === strpos( wp_normalize_path( $full ), wp_normalize_path( SV_BRIDGE_DIR ) ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_self', __( 'O canal não pode apagar os próprios arquivos.', 'savitri-file-bridge' ), array( 'status' => 403 ) ) );
		}

		$rel = SV_Bridge_Paths::to_relative( $full );

		if ( ! unlink( $full ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return $this->fail( new WP_Error( 'sv_bridge_delete_failed', __( 'Falha ao apagar.', 'savitri-file-bridge' ), array( 'status' => 500 ) ) );
		}

		SV_Bridge_Log::add( 'apagou', $request->get_route(), $rel );

		return new WP_REST_Response( array( 'ok' => true, 'path' => $rel ), 200 );
	}

	/**
	 * Cria um diretório.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function make_dir( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		$full = SV_Bridge_Paths::resolve( $this->relative( $request->get_param( 'path' ) ) );

		if ( is_wp_error( $full ) ) {
			return $this->fail( $full );
		}

		if ( is_dir( $full ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'path' => SV_Bridge_Paths::to_relative( $full ), 'existing' => true ), 200 );
		}

		if ( ! wp_mkdir_p( $full ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_mkdir', __( 'Falha ao criar diretório.', 'savitri-file-bridge' ), array( 'status' => 500 ) ) );
		}

		SV_Bridge_Log::add( 'criou pasta', $request->get_route(), SV_Bridge_Paths::to_relative( $full ) );

		return new WP_REST_Response( array( 'ok' => true, 'path' => SV_Bridge_Paths::to_relative( $full ) ), 201 );
	}

	/**
	 * Extrai um .zip que já está no servidor.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function unzip( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		$zip_path = SV_Bridge_Paths::resolve_existing_file( $this->relative( $request->get_param( 'path' ) ) );

		if ( is_wp_error( $zip_path ) ) {
			return $this->fail( $zip_path );
		}

		if ( 'zip' !== strtolower( (string) pathinfo( $zip_path, PATHINFO_EXTENSION ) ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_not_zip', __( 'O arquivo precisa ser .zip.', 'savitri-file-bridge' ), array( 'status' => 400 ) ) );
		}

		$dest_raw = (string) $request->get_param( 'destination' );

		if ( '' === $dest_raw ) {
			$dest_raw = dirname( SV_Bridge_Paths::to_relative( $zip_path ) );
		}

		$dest = SV_Bridge_Paths::resolve( $dest_raw );

		if ( is_wp_error( $dest ) ) {
			return $this->fail( $dest );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_no_zip', __( 'A extensão ZipArchive não está disponível no servidor.', 'savitri-file-bridge' ), array( 'status' => 501 ) ) );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_zip_open', __( 'Não foi possível abrir o .zip.', 'savitri-file-bridge' ), array( 'status' => 500 ) ) );
		}

		// Confere todas as entradas antes de extrair qualquer coisa.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name  = (string) $zip->getNameIndex( $i );
			$check = SV_Bridge_Paths::check_zip_entry( $name );

			if ( is_wp_error( $check ) ) {
				$zip->close();

				return $this->fail( $check );
			}

			$attrs = 0;

			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attrs ) && SV_Bridge_Paths::is_symlink_entry( $attrs ) ) {
				$zip->close();

				return $this->fail( new WP_Error( 'sv_bridge_zip_symlink', __( 'O .zip contém um link simbólico.', 'savitri-file-bridge' ), array( 'status' => 400 ) ) );
			}
		}

		$result = $zip->extractTo( $dest );
		$zip->close();

		if ( ! $result ) {
			return $this->fail( new WP_Error( 'sv_bridge_unzip_failed', __( 'Falha ao extrair.', 'savitri-file-bridge' ), array( 'status' => 500 ) ) );
		}

		$rel_zip = SV_Bridge_Paths::to_relative( $zip_path );

		if ( rest_sanitize_boolean( $request->get_param( 'delete_zip' ) ) ) {
			unlink( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		SV_Bridge_Log::add( 'extraiu', $request->get_route(), $rel_zip . ' → ' . SV_Bridge_Paths::to_relative( $dest ) );

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'zip'         => $rel_zip,
				'destination' => SV_Bridge_Paths::to_relative( $dest ),
			),
			200
		);
	}

	/**
	 * Lista plugins instalados.
	 *
	 * @return WP_REST_Response
	 */
	public function list_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();

		foreach ( $all as $file => $data ) {
			$out[] = array(
				'plugin' => $file,
				'name'   => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version' => isset( $data['Version'] ) ? $data['Version'] : '',
				'active' => in_array( $file, $active, true ),
			);
		}

		return new WP_REST_Response( array( 'ok' => true, 'plugins' => $out ), 200 );
	}

	/**
	 * Ativa ou desativa um plugin.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function toggle_plugin( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = (string) $request->get_param( 'plugin' );
		$action = (string) $request->get_param( 'action' );

		if ( ! in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_bad_action', __( 'Ação inválida.', 'savitri-file-bridge' ), array( 'status' => 400 ) ) );
		}

		// Só aceita plugin que existe de fato na pasta de plugins.
		$full = SV_Bridge_Paths::resolve( 'plugins/' . $plugin );

		if ( is_wp_error( $full ) ) {
			return $this->fail( $full );
		}

		if ( ! file_exists( $full ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_no_plugin', __( 'Plugin não encontrado.', 'savitri-file-bridge' ), array( 'status' => 404 ) ) );
		}

		if ( 'activate' === $action ) {
			$result = activate_plugin( $plugin );
		} else {
			deactivate_plugins( array( $plugin ) );
			$result = null;
		}

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		SV_Bridge_Log::add( $action . ' plugin', $request->get_route(), $plugin );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'plugin' => $plugin,
				'active' => is_plugin_active( $plugin ),
			),
			200
		);
	}

	/**
	 * Lista temas instalados.
	 *
	 * @return WP_REST_Response
	 */
	public function list_themes() {
		$themes = wp_get_themes();
		$out    = array();

		foreach ( $themes as $stylesheet => $theme ) {
			$out[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'template'   => $theme->get( 'Template' ),
				'active'     => get_stylesheet() === $stylesheet,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'themes' => $out,
			),
			200
		);
	}

	/**
	 * Ativa um tema.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function activate_theme( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		$stylesheet = (string) $request->get_param( 'stylesheet' );

		$full = SV_Bridge_Paths::resolve( 'themes/' . $stylesheet );

		if ( is_wp_error( $full ) ) {
			return $this->fail( $full );
		}

		if ( ! is_dir( $full ) ) {
			return $this->fail( new WP_Error( 'sv_bridge_no_theme', __( 'Tema não encontrado.', 'savitri-file-bridge' ), array( 'status' => 404 ) ) );
		}

		switch_theme( $stylesheet );

		SV_Bridge_Log::add( 'ativou tema', $request->get_route(), $stylesheet );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'theme'  => $stylesheet,
				'active' => get_stylesheet() === $stylesheet,
			),
			200
		);
	}

	/**
	 * Lista posts ou páginas.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function list_posts( $request ) {
		$result = SV_Bridge_Content::list_posts(
			array(
				'type'   => $request->get_param( 'type' ),
				'status' => $request->get_param( 'status' ),
				'search' => $request->get_param( 'search' ),
				'slug'   => $request->get_param( 'slug' ),
				'parent' => $request->get_param( 'parent' ),
				'limit'  => $request->get_param( 'limit' ),
				'offset' => $request->get_param( 'offset' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 );
	}

	/**
	 * Lê um post ou página (GET /post?id=).
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function get_post_route( $request ) {
		$result = SV_Bridge_Content::get( (int) $request->get_param( 'id' ), (bool) $request->get_param( 'with_meta' ) );

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 );
	}

	/**
	 * Cria um post ou página.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function create_post( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		$params = (array) $request->get_json_params();

		if ( empty( $params ) ) {
			$params = (array) $request->get_params();
		}

		$result = SV_Bridge_Content::create( $params );

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 201 );
	}

	/**
	 * Atualiza um post ou página.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return WP_REST_Response
	 */
	public function update_post( $request ) {
		if ( $this->readonly_blocked() ) {
			return $this->readonly_error();
		}

		$params = (array) $request->get_json_params();

		if ( empty( $params ) ) {
			$params = (array) $request->get_params();
		}

		$id = isset( $params['id'] ) ? (int) $params['id'] : (int) $request->get_param( 'id' );

		unset( $params['id'] );

		$result = SV_Bridge_Content::update( $id, $params );

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 );
	}
}
