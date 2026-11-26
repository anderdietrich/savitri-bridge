<?php
/**
 * Cache: o resultado do canal depende da chave, então nunca pode ser guardado.
 *
 * O problema que isto resolve: quando a rota ainda não existia (antes da primeira
 * chave), o WordPress devolvia rest_no_route (404) e o cache de página guardava o
 * 404. Depois de criar a chave o endpoint passava a existir, mas o cache continuava
 * entregando o 404 antigo — parecendo que o botão "criar chave" não salvou.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * A rota pedida é do canal?
 *
 * @param string $route Rota REST (ex.: "/sv-bridge/v1/mcp").
 * @return bool
 */
function sv_bridge_is_bridge_route( $route ) {
	$route = ltrim( (string) $route, '/' );

	if ( 0 === strpos( $route, 'sv-bridge' ) ) {
		return true;
	}

	// Quando o site usa /?rest_route=, o WordPress não preenche a rota aqui.
	if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
		$uri = (string) $_SERVER['REQUEST_URI'];

		return false !== strpos( $uri, 'sv-bridge' );
	}

	return false;
}

/**
 * Descarta o cache de página e de objeto para o canal inteiro.
 *
 * @return void
 */
function sv_bridge_purge_cache() {
	do_action( 'litespeed_purge_api', 'savitri-bridge' );
	do_action( 'litespeed_purge_all' );

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}

	if ( function_exists( 'wp_cache_clean_cache' ) ) {
		wp_cache_clean_cache( 'savitri-bridge' );
	}

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/**
 * Cabeçalhos que proíbem o cache de guardar a resposta do canal.
 *
 * @return void
 */
function sv_bridge_no_cache_headers() {
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

	if ( ! sv_bridge_is_bridge_route( $route ) ) {
		return;
	}

	if ( ! headers_sent() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Accel-Expires: 0' );
		header( 'CDN-Cache-Control: no-store' );
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
	}

	// LiteSpeed: não guardar e descartar o que já estiver guardado para esta rota.
	do_action( 'litespeed_control_set_nocache', 'savitri-bridge: resposta depende da chave' );

	if ( defined( 'LSCWP_V' ) ) {
		do_action( 'litespeed_purge_api', 'savitri-bridge' );
	}
}
