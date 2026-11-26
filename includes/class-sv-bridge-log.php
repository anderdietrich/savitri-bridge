<?php
/**
 * Log de operações do canal.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra cada operação em wp-content/uploads, fora do alcance de escrita do canal.
 */
class SV_Bridge_Log {

	const OPTION = 'sv_bridge_log';

	const MAX_LINES = 500;

	/**
	 * Adiciona uma linha ao log.
	 *
	 * @param string $message O que foi feito.
	 * @param string $route   Rota chamada.
	 * @param string $extra   Detalhe opcional.
	 */
	public static function add( $message, $route = '', $extra = '' ) {
		$line = sprintf(
			'[%s] %s | %s | %s',
			current_time( 'Y-m-d H:i:s' ),
			$route,
			$message,
			$extra
		);

		$log = get_option( self::OPTION, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = $line;

		if ( count( $log ) > self::MAX_LINES ) {
			$log = array_slice( $log, -self::MAX_LINES );
		}

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Últimas linhas do log.
	 *
	 * @param int $limit Quantidade.
	 * @return string[]
	 */
	public static function tail( $limit = 50 ) {
		$log = get_option( self::OPTION, array() );

		if ( ! is_array( $log ) ) {
			return array();
		}

		return array_reverse( array_slice( $log, -1 * absint( $limit ) ) );
	}
}
