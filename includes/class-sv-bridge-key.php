<?php
/**
 * Chave: leitura, geração e revogação.
 *
 * A chave pode vir de duas fontes, nesta ordem de prioridade:
 * 1. Constante SV_FILE_BRIDGE_KEY no wp-config.php.
 * 2. Opção sv_bridge_key no banco, gerada em Configurações → Savitri Bridge.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devolve a chave ativa, ou string vazia.
 *
 * @return string
 */
function sv_bridge_key() {
	if ( defined( 'SV_FILE_BRIDGE_KEY' ) ) {
		$constant = (string) SV_FILE_BRIDGE_KEY;

		// Constante curta não vale: desliga o canal em vez de cair no banco.
		return strlen( $constant ) >= 32 ? $constant : '';
	}

	return (string) get_option( 'sv_bridge_key', '' );
}

/**
 * De onde vem a chave: 'constant', 'option' ou ''.
 *
 * @return string
 */
function sv_bridge_key_source() {
	if ( defined( 'SV_FILE_BRIDGE_KEY' ) ) {
		return strlen( (string) SV_FILE_BRIDGE_KEY ) >= 32 ? 'constant' : '';
	}

	if ( strlen( (string) get_option( 'sv_bridge_key', '' ) ) >= 32 ) {
		return 'option';
	}

	return '';
}

/**
 * Gera uma chave nova.
 *
 * @return string
 */
function sv_bridge_generate_key() {
	return bin2hex( random_bytes( 32 ) );
}
