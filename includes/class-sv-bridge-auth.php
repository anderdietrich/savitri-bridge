<?php
/**
 * Autenticação por chave compartilhada.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valida a chave enviada no cabeçalho e aplica limite de tentativas.
 */
class SV_Bridge_Auth {

	const HEADER = 'X-SV-Bridge-Key';

	const MAX_FAILURES = 5;
	const WINDOW       = 600; // 10 minutos.

	/**
	 * Extrai a chave enviada, aceitando dois formatos:
	 * - X-SV-Bridge-Key: <chave>
	 * - Authorization: Bearer <chave>
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return string
	 */
	private static function provided_key( $request ) {
		$direct = (string) $request->get_header( self::HEADER );

		if ( '' !== $direct ) {
			return $direct;
		}

		$auth = (string) $request->get_header( 'Authorization' );

		if ( 0 === stripos( $auth, 'Bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		return '';
	}

	/**
	 * Verifica a requisição. Devolve true ou WP_Error.
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return true|WP_Error
	 */
	public static function check( $request ) {
		$ip = self::client_ip();

		if ( self::is_locked_out( $ip ) ) {
			return new WP_Error(
				'sv_bridge_locked',
				__( 'Muitas tentativas inválidas. Tente mais tarde.', 'savitri-file-bridge' ),
				array( 'status' => 429 )
			);
		}

		if ( ! sv_bridge_is_enabled() ) {
			return new WP_Error(
				'sv_bridge_disabled',
				__( 'Canal desligado: nenhuma chave configurada.', 'savitri-file-bridge' ),
				array( 'status' => 503 )
			);
		}

		$provided = self::provided_key( $request );

		if ( '' === $provided ) {
			self::register_failure( $ip );

			return new WP_Error(
				'sv_bridge_missing_key',
				__( 'Chave ausente.', 'savitri-file-bridge' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( sv_bridge_key(), $provided ) ) {
			self::register_failure( $ip );

			SV_Bridge_Log::add( sprintf( 'chave inválida de %s', $ip ), $request->get_route() );

			return new WP_Error(
				'sv_bridge_bad_key',
				__( 'Chave inválida.', 'savitri-file-bridge' ),
				array( 'status' => 401 )
			);
		}

		self::clear_failures( $ip );

		return true;
	}

	/**
	 * IP do cliente (para o limite de tentativas).
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'desconhecido';

		return substr( $ip, 0, 64 );
	}

	/**
	 * Chave do contador de falhas.
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	private static function failure_key( $ip ) {
		return 'sv_bridge_fail_' . md5( $ip );
	}

	/**
	 * Está bloqueado por excesso de falhas?
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private static function is_locked_out( $ip ) {
		$data = get_transient( self::failure_key( $ip ) );

		return is_array( $data ) && isset( $data['count'] ) && (int) $data['count'] >= self::MAX_FAILURES;
	}

	/**
	 * Registra uma falha.
	 *
	 * @param string $ip IP.
	 */
	private static function register_failure( $ip ) {
		$key  = self::failure_key( $ip );
		$data = get_transient( $key );

		if ( ! is_array( $data ) ) {
			$data = array( 'count' => 0 );
		}

		$data['count'] = (int) $data['count'] + 1;

		set_transient( $key, $data, self::WINDOW );
	}

	/**
	 * Zera o contador após sucesso.
	 *
	 * @param string $ip IP.
	 */
	private static function clear_failures( $ip ) {
		delete_transient( self::failure_key( $ip ) );
	}

	/**
	 * Permissão para as rotas: só exige a chave (roda sem usuário logado).
	 *
	 * @param WP_REST_Request $request Requisição.
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		return self::check( $request );
	}
}
