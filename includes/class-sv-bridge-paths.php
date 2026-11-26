<?php
/**
 * Resolução e validação de caminhos.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Concentra a regra de "onde é permitido mexer".
 */
class SV_Bridge_Paths {

	const MAX_WRITE_BYTES = 4194304; // 4 MB.

	/**
	 * Diretórios permitidos: chave => caminho absoluto.
	 *
	 * @return array<string,string>
	 */
	public static function bases() {
		return array(
			'themes'  => wp_normalize_path( WP_CONTENT_DIR . '/themes' ),
			'plugins' => wp_normalize_path( WP_CONTENT_DIR . '/plugins' ),
		);
	}

	/**
	 * Normaliza um caminho relativo e devolve o absoluto, ou WP_Error.
	 *
	 * @param string $relative Caminho relativo iniciado por themes/ ou plugins/.
	 * @return string|WP_Error
	 */
	public static function resolve( $relative ) {
		$relative = (string) $relative;

		if ( '' === $relative ) {
			return new WP_Error( 'sv_bridge_missing_path', __( 'Informe o caminho do arquivo.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
		}

		$relative = wp_normalize_path( $relative );
		$relative = ltrim( $relative, '/' );

		if ( false !== strpos( $relative, "\0" ) ) {
			return new WP_Error( 'sv_bridge_bad_path', __( 'Caminho inválido.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
		}

		// Bloqueia travessia de diretório em qualquer segmento.
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment || '.' === $segment ) {
				return new WP_Error( 'sv_bridge_bad_path', __( 'Caminho inválido.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
			}
		}

		$parts = explode( '/', $relative );
		$root  = array_shift( $parts );
		$bases = self::bases();

		if ( ! isset( $bases[ $root ] ) ) {
			return new WP_Error(
				'sv_bridge_outside_scope',
				__( 'Só é permitido trabalhar em themes/ ou plugins/.', 'savitri-file-bridge' ),
				array( 'status' => 403 )
			);
		}

		if ( empty( $parts ) ) {
			// Raiz do diretório base.
			return $bases[ $root ];
		}

		$base = $bases[ $root ];
		$full = wp_normalize_path( $base . '/' . implode( '/', $parts ) );

		// O alvo pode ainda não existir, então validamos o ancestral mais próximo.
		$check = $full;

		while ( ! file_exists( $check ) && dirname( $check ) !== $check ) {
			$check = dirname( $check );
		}

		$real_check = realpath( $check );
		$real_base  = realpath( $base );

		if ( false === $real_check || false === $real_base ) {
			return new WP_Error( 'sv_bridge_bad_base', __( 'Diretório base não encontrado.', 'savitri-file-bridge' ), array( 'status' => 500 ) );
		}

		$real_check = wp_normalize_path( $real_check );
		$real_base  = wp_normalize_path( $real_base );

		// Cobre o caso de symlink apontando para fora.
		if ( $real_check !== $real_base && 0 !== strpos( $real_check, $real_base . '/' ) ) {
			return new WP_Error( 'sv_bridge_symlink_escape', __( 'O caminho resolve para fora da área permitida.', 'savitri-file-bridge' ), array( 'status' => 403 ) );
		}

		return $full;
	}

	/**
	 * Garante que é um arquivo regular dentro da área permitida.
	 *
	 * @param string $relative Caminho relativo.
	 * @return string|WP_Error
	 */
	public static function resolve_existing_file( $relative ) {
		$full = self::resolve( $relative );

		if ( is_wp_error( $full ) ) {
			return $full;
		}

		if ( ! file_exists( $full ) ) {
			return new WP_Error( 'sv_bridge_not_found', __( 'Arquivo não encontrado.', 'savitri-file-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! is_file( $full ) ) {
			return new WP_Error( 'sv_bridge_not_a_file', __( 'O caminho não é um arquivo.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
		}

		if ( ! is_readable( $full ) ) {
			return new WP_Error( 'sv_bridge_not_readable', __( 'Arquivo sem permissão de leitura.', 'savitri-file-bridge' ), array( 'status' => 403 ) );
		}

		return $full;
	}

	/**
	 * Devolve o caminho relativo (ex.: themes/x/style.css) a partir do absoluto.
	 *
	 * @param string $full Caminho absoluto.
	 * @return string
	 */
	public static function to_relative( $full ) {
		$full = wp_normalize_path( $full );

		foreach ( self::bases() as $key => $base ) {
			if ( $full === $base ) {
				return $key;
			}

			if ( 0 === strpos( $full, $base . '/' ) ) {
				return $key . substr( $full, strlen( $base ) );
			}
		}

		return $full;
	}

	/**
	 * Extensões que este canal não escreve, como trava extra.
	 *
	 * @return string[]
	 */
	public static function blocked_extensions() {
		return array( 'phar' );
	}

	/**
	 * Os atributos externos de uma entrada indicam symlink?
	 *
	 * Um .zip pode carregar um symlink apontando para fora da área permitida;
	 * extrair isso abriria um atalho para arquivos do servidor.
	 *
	 * @param int $attributes Atributos externos da entrada.
	 * @return bool
	 */
	public static function is_symlink_entry( $attributes ) {
		$mode = ( (int) $attributes >> 16 ) & 0xFFFF;

		return 0xA000 === ( $mode & 0xF000 );
	}

	/**
	 * Uma entrada de .zip é segura para extrair?
	 *
	 * @param string $name Nome da entrada.
	 * @return true|WP_Error
	 */
	public static function check_zip_entry( $name ) {
		$norm = wp_normalize_path( (string) $name );

		if ( '' === $norm ) {
			return new WP_Error( 'sv_bridge_zip_empty', __( 'Entrada vazia no .zip.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
		}

		if ( false !== strpos( $norm, "\0" ) ) {
			return new WP_Error( 'sv_bridge_zip_slip', __( 'O .zip contém caminho suspeito.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
		}

		// Caminho absoluto ou com letra de unidade (C:) não é permitido.
		if ( 0 === strpos( $norm, '/' ) || preg_match( '#^[A-Za-z]:#', $norm ) ) {
			return new WP_Error( 'sv_bridge_zip_absolute', __( 'O .zip contém caminho absoluto.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
		}

		// Travessia de diretório em qualquer posição.
		if ( preg_match( '#(^|/)\.\.(/|$)#', $norm ) ) {
			return new WP_Error( 'sv_bridge_zip_slip', __( 'O .zip contém caminho suspeito.', 'savitri-file-bridge' ), array( 'status' => 400 ) );
		}

		// Entradas de diretório terminam em "/" e não têm extensão.
		$is_dir = ( '/' === substr( $norm, -1 ) );

		$ext = $is_dir ? '' : strtolower( (string) pathinfo( $norm, PATHINFO_EXTENSION ) );

		if ( '' !== $ext && in_array( $ext, self::blocked_extensions(), true ) ) {
			return new WP_Error( 'sv_bridge_zip_blocked', __( 'O .zip contém extensão bloqueada.', 'savitri-file-bridge' ), array( 'status' => 403 ) );
		}

		return true;
	}
}
