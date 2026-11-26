<?php
/**
 * Plugin Name:       Savitri Bridge
 * Description:       Conecta este WordPress a um assistente por MCP: ler e editar arquivos de temas e plugins, ativar tema e plugins. Chave gerada em Configurações → Savitri Bridge.
 * Version:           2.5.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Anderson Dietrich
 * License:           GPL-3.0-or-later
 * Text Domain:       savitri-file-bridge
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

define( 'SV_BRIDGE_VERSION', '2.5.5' );
define( 'SV_BRIDGE_FILE', __FILE__ );
define( 'SV_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );

require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-cache.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-key.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-paths.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-log.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-auth.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-content.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-mcp.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-rest.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-update.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-settings.php';

/**
 * O canal está ligado?
 *
 * @return bool
 */
function sv_bridge_is_enabled() {
	return '' !== sv_bridge_key_source();
}

/**
 * Está em modo somente leitura?
 *
 * @return bool
 */
function sv_bridge_is_readonly() {
	return (bool) get_option( 'sv_bridge_readonly', false );
}

/**
 * Registra as rotas REST (MCP + administração do canal).
 *
 * As rotas SÃO registradas mesmo sem chave. Se não fossem, o WordPress devolveria
 * rest_no_route (404) e o cache de página guardaria esse 404: a chave seria criada,
 * o endpoint passaria a existir, mas o cache continuaria entregando o 404 antigo —
 * parecendo que a chave não funciona. Sem chave, a resposta certa é 503.
 */
function sv_bridge_boot() {
	$rest = new SV_Bridge_REST();
	$rest->register_routes();
}
add_action( 'rest_api_init', 'sv_bridge_boot' );

/**
 * Aplica o não-cache no início da requisição e das rotas REST.
 */
function sv_bridge_cache_hooks() {
	sv_bridge_no_cache_headers();
}
add_action( 'init', 'sv_bridge_cache_hooks', 1 );
add_action( 'rest_api_init', 'sv_bridge_cache_hooks', 1 );

/**
 * Aviso no painel quando ainda não há chave.
 */
function sv_bridge_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) || sv_bridge_is_enabled() ) {
		return;
	}

	$screen = get_current_screen();

	if ( $screen && 'settings_page_sv-bridge' === $screen->id ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>Savitri Bridge</strong>: %s <a href="%s">%s</a></p></div>',
		esc_html__( 'nenhuma chave configurada, o canal está desligado.', 'savitri-file-bridge' ),
		esc_url( sv_bridge_settings_url() ),
		esc_html__( 'Configurar agora', 'savitri-file-bridge' )
	);
}
add_action( 'admin_notices', 'sv_bridge_admin_notice' );

/**
 * Link "Configurações" na lista de plugins.
 *
 * @param array $links Links.
 * @return array
 */
function sv_bridge_action_links( $links ) {
	$url = sv_bridge_settings_url();

	array_unshift(
		$links,
		sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Configurações', 'savitri-file-bridge' ) )
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sv_bridge_action_links' );
