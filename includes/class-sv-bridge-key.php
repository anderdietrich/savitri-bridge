<?php
/**
 * Chaves: lista, geração, edição e revogação.
 *
 * A chave pode vir de duas fontes, nesta ordem de prioridade:
 * 1. Constante SV_FILE_BRIDGE_KEY no wp-config.php.
 * 2. Lista sv_bridge_keys no banco, gerenciada em Configurações → Savitri Bridge.
 *
 * A lista guarda uma entrada por chave emitida:
 * id, name, key, created, last_used, last_ip.
 * A entrada só sai da lista quando a chave é revogada.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data e hora no formato do WordPress.
 *
 * @return string
 */
function sv_bridge_now() {
	if ( function_exists( 'current_time' ) ) {
		return (string) current_time( 'mysql' );
	}

	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Identificador curto de uma chave.
 *
 * @return string
 */
function sv_bridge_new_id() {
	return bin2hex( random_bytes( 6 ) );
}

/**
 * Todas as chaves emitidas, da mais antiga para a mais nova.
 *
 * Migra automaticamente a chave única antiga (option sv_bridge_key)
 * para o formato de lista, sem derrubar o acesso já configurado.
 *
 * @return array<int,array<string,string>>
 */
function sv_bridge_keys() {
	$keys = get_option( 'sv_bridge_keys', array() );

	if ( ! is_array( $keys ) ) {
		$keys = array();
	}

	$clean = array();

	foreach ( $keys as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['key'] ) ) {
			continue;
		}

		$clean[] = array(
			'id'        => isset( $entry['id'] ) ? (string) $entry['id'] : sv_bridge_new_id(),
			'name'      => isset( $entry['name'] ) ? (string) $entry['name'] : '',
			'key'       => (string) $entry['key'],
			'created'   => isset( $entry['created'] ) ? (string) $entry['created'] : '',
			'last_used' => isset( $entry['last_used'] ) ? (string) $entry['last_used'] : '',
			'last_ip'   => isset( $entry['last_ip'] ) ? (string) $entry['last_ip'] : '',
		);
	}

	// Migração da chave única.
	$legacy = (string) get_option( 'sv_bridge_key', '' );

	if ( strlen( $legacy ) >= 32 ) {
		$already = false;

		foreach ( $clean as $entry ) {
			if ( hash_equals( $entry['key'], $legacy ) ) {
				$already = true;
				break;
			}
		}

		if ( ! $already ) {
			$clean[] = array(
				'id'        => sv_bridge_new_id(),
				'name'      => __( 'Chave antiga', 'savitri-file-bridge' ),
				'key'       => $legacy,
				'created'   => sv_bridge_now(),
				'last_used' => '',
				'last_ip'   => '',
			);

			update_option( 'sv_bridge_keys', $clean, false );
		}
	}

	return $clean;
}

/**
 * Grava a lista de chaves e descarta o cache de página do canal.
 *
 * O resultado de uma requisição muda conforme a chave; guardar em cache faria
 * o cliente receber um 401/404 antigo depois de a chave ser criada ou revogada.
 *
 * @param array $keys Lista.
 * @return void
 */
function sv_bridge_save_keys( $keys ) {
	update_option( 'sv_bridge_keys', array_values( $keys ), false );

	if ( function_exists( 'sv_bridge_purge_cache' ) ) {
		sv_bridge_purge_cache();
	}
}

/**
 * Chave ativa (a primeira da lista). Mantida por compatibilidade.
 *
 * @return string
 */
function sv_bridge_key() {
	if ( defined( 'SV_FILE_BRIDGE_KEY' ) ) {
		$constant = (string) SV_FILE_BRIDGE_KEY;

		// Constante curta não vale: desliga o canal em vez de cair no banco.
		return strlen( $constant ) >= 32 ? $constant : '';
	}

	$keys = sv_bridge_keys();

	return $keys ? (string) $keys[0]['key'] : '';
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

	return sv_bridge_keys() ? 'option' : '';
}

/**
 * Gera uma chave nova.
 *
 * @return string
 */
function sv_bridge_generate_key() {
	return bin2hex( random_bytes( 32 ) );
}

/**
 * Cria uma chave e devolve a entrada gravada.
 *
 * @param string $name Nome da ferramenta conectada.
 * @return array<string,string>
 */
function sv_bridge_add_key( $name = '' ) {
	$keys = sv_bridge_keys();

	$entry = array(
		'id'        => sv_bridge_new_id(),
		'name'      => trim( (string) $name ),
		'key'       => sv_bridge_generate_key(),
		'created'   => sv_bridge_now(),
		'last_used' => '',
		'last_ip'   => '',
	);

	$keys[] = $entry;
	sv_bridge_save_keys( $keys );

	return $entry;
}

/**
 * Renomeia uma chave.
 *
 * @param string $id   Identificador.
 * @param string $name Novo nome.
 * @return bool
 */
function sv_bridge_rename_key( $id, $name ) {
	$keys = sv_bridge_keys();
	$done = false;

	foreach ( $keys as $i => $entry ) {
		if ( $entry['id'] === $id ) {
			$keys[ $i ]['name'] = trim( (string) $name );
			$done               = true;
			break;
		}
	}

	if ( $done ) {
		sv_bridge_save_keys( $keys );
	}

	return $done;
}

/**
 * Revoga (apaga) uma chave.
 *
 * @param string $id Identificador.
 * @return bool
 */
function sv_bridge_revoke_key( $id ) {
	$keys = sv_bridge_keys();
	$out  = array();

	foreach ( $keys as $entry ) {
		if ( $entry['id'] !== $id ) {
			$out[] = $entry;
		}
	}

	$removed = count( $out ) !== count( $keys );

	if ( $removed ) {
		sv_bridge_save_keys( $out );
	}

	// A chave única antiga também sai, senão a migração a traria de volta.
	$legacy = (string) get_option( 'sv_bridge_key', '' );

	if ( '' !== $legacy && ! in_array( $legacy, array_column( $out, 'key' ), true ) ) {
		update_option( 'sv_bridge_key', '', false );
	}

	return $removed;
}

/**
 * Confere a chave enviada contra a constante e contra a lista.
 *
 * @param string $provided Chave recebida.
 * @return string|false 'constant', o id da chave que bateu, ou false.
 */
function sv_bridge_verify_key( $provided ) {
	$provided = (string) $provided;

	if ( '' === $provided ) {
		return false;
	}

	if ( defined( 'SV_FILE_BRIDGE_KEY' ) ) {
		$constant = (string) SV_FILE_BRIDGE_KEY;

		return ( strlen( $constant ) >= 32 && hash_equals( $constant, $provided ) ) ? 'constant' : false;
	}

	$hit = false;

	foreach ( sv_bridge_keys() as $entry ) {
		if ( hash_equals( $entry['key'], $provided ) ) {
			$hit = $entry['id'];
		}
	}

	return $hit;
}

/**
 * Marca o último uso de uma chave (no máximo uma gravação por minuto).
 *
 * @param string $id Id da chave.
 * @param string $ip IP do cliente.
 * @return void
 */
function sv_bridge_touch_key( $id, $ip = '' ) {
	if ( '' === $id || 'constant' === $id ) {
		return;
	}

	$keys   = sv_bridge_keys();
	$now    = sv_bridge_now();
	$change = false;

	foreach ( $keys as $i => $entry ) {
		if ( $entry['id'] !== $id ) {
			continue;
		}

		$last = (string) $entry['last_used'];

		if ( '' !== $last && ( strtotime( $now ) - strtotime( $last ) ) < 60 ) {
			return;
		}

		$keys[ $i ]['last_used'] = $now;
		$keys[ $i ]['last_ip']   = substr( (string) $ip, 0, 64 );
		$change                  = true;
		break;
	}

	if ( $change ) {
		sv_bridge_save_keys( $keys );
	}
}
