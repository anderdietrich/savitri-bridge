<?php
/**
 * Testes da atualização pelo GitHub: leitura de versão, escolha da publicação
 * mais recente e o objeto que o WordPress usa para atualizar.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'SV_BRIDGE_VERSION', '2.4.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svupd/wp-content' );

$GLOBALS['__opts']     = array();
$GLOBALS['transients'] = array();
$GLOBALS['__http']     = array(); // url => array( code, body )

function __( $s, $d = null ) { return $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function add_filter( $h, $c, $p = 10, $n = 1 ) { return true; }
function wp_parse_url( $u, $c = null ) { return parse_url( $u, $c ?? PHP_URL_HOST ); }
function admin_url( $p = '' ) { return 'https://exemplo.test/wp-admin/' . $p; }
function wp_nonce_url( $u, $a = '' ) { return $u . '&_wpnonce=x'; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function plugins_url( $p = '', $f = '' ) { return 'https://exemplo.test/wp-content/plugins/' . $p; }

class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

function wp_remote_get( $url, $args = array() ) {
	$key = preg_replace( '/\?.*$/', '', $url );
	if ( ! isset( $GLOBALS['__http'][ $key ] ) ) {
		return array( 'code' => 404, 'body' => '{}' );
	}
	return array( 'code' => $GLOBALS['__http'][ $key ][0], 'body' => json_encode( $GLOBALS['__http'][ $key ][1] ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

require_once __DIR__ . '/../includes/class-sv-bridge-update.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label" . ( '' !== $extra ? "  [$extra]" : '' ) . "\n"; }
}

echo "== Etiqueta de versão ==\n";
check( 'v2.4.0 → 2.4.0', '2.4.0' === sv_bridge_clean_version( 'v2.4.0' ) );
check( '2.4.0 → 2.4.0', '2.4.0' === sv_bridge_clean_version( '2.4.0' ) );
check( 'v2.4.0-beta → 2.4.0', '2.4.0' === sv_bridge_clean_version( 'v2.4.0-beta' ) );
check( 'vazio → 0', '0' === sv_bridge_clean_version( '' ) );
check( 'comparação funciona', version_compare( '2.5.0', '2.4.0', '>' ) );

echo "\n== Repositório fixo ==\n";
check( 'repositório do plugin', 'anderdietrich/savitri-bridge' === sv_bridge_github_repo() );

echo "\n== Escolhe a release mais recente, não a primeira da lista ==\n";
$GLOBALS['transients'] = array();
$GLOBALS['__http'] = array(
	'https://api.github.com/repos/anderdietrich/savitri-bridge/releases' => array( 200, array(
		array( 'tag_name' => 'v2.1.0', 'draft' => false, 'html_url' => 'u1', 'assets' => array() ),
		array( 'tag_name' => 'v2.6.0', 'draft' => false, 'html_url' => 'u6', 'assets' => array(
			array( 'name' => 'savitri-file-bridge.zip', 'browser_download_url' => 'https://github.com/x/y/releases/download/v2.6.0/savitri-file-bridge.zip' ),
		) ),
		array( 'tag_name' => 'v2.3.0', 'draft' => false, 'html_url' => 'u3', 'assets' => array() ),
		array( 'tag_name' => 'v9.0.0', 'draft' => true, 'html_url' => 'rascunho', 'assets' => array() ),
	) ),
);
$latest = sv_bridge_github_latest( true );
check( 'sem erro', ! is_wp_error( $latest ), is_wp_error( $latest ) ? $latest->get_error_message() : '' );
check( 'versão mais alta: 2.6.0', '2.6.0' === $latest['version'], $latest['version'] ?? '' );
check( 'rascunho ignorado', '2.6.0' !== '9.0.0' );
check( 'zip da release usado', false !== strpos( $latest['zip'], 'v2.6.0' ), $latest['zip'] ?? '' );

echo "\n== Sem release, cai para as tags ==\n";
$GLOBALS['transients'] = array();
$GLOBALS['__http'] = array(
	'https://api.github.com/repos/anderdietrich/savitri-bridge/releases' => array( 200, array() ),
	'https://api.github.com/repos/anderdietrich/savitri-bridge/tags'     => array( 200, array(
		array( 'name' => 'v2.2.0' ),
		array( 'name' => 'v2.7.1' ),
		array( 'name' => 'v2.5.0' ),
	) ),
);
$latest = sv_bridge_github_latest( true );
check( 'tag mais alta: 2.7.1', ! is_wp_error( $latest ) && '2.7.1' === $latest['version'], is_wp_error( $latest ) ? $latest->get_error_message() : $latest['version'] );
check( 'zip gerado pelo GitHub', '' !== $latest['zip'] && false !== strpos( $latest['zip'], 'zipball' ), $latest['zip'] );
check( 'fonte é tag', 'tag' === $latest['source'] );

echo "\n== Erros do GitHub são explicados ==\n";
$GLOBALS['transients'] = array();
$GLOBALS['__http'] = array(
	'https://api.github.com/repos/anderdietrich/savitri-bridge/releases' => array( 404, array() ),
	'https://api.github.com/repos/anderdietrich/savitri-bridge/tags'     => array( 404, array() ),
);
$err = sv_bridge_github_latest( true );
check( '404 vira erro explicado', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'repositório' ), is_wp_error( $err ) ? $err->get_error_message() : '' );

$GLOBALS['transients'] = array();
$GLOBALS['__http'] = array(
	'https://api.github.com/repos/anderdietrich/savitri-bridge/releases' => array( 401, array() ),
);
$err = sv_bridge_github_latest( true );
check( '401 vira erro de API', is_wp_error( $err ) && 'sv_gh_auth' === $err->get_error_code() );

echo "\n== Aviso de atualização no WordPress ==\n";
$GLOBALS['transients'] = array();
$GLOBALS['__http'] = array(
	'https://api.github.com/repos/anderdietrich/savitri-bridge/releases' => array( 200, array(
		array( 'tag_name' => 'v2.9.0', 'draft' => false, 'html_url' => 'https://github.com/x/releases/v2.9.0', 'published_at' => '2026-12-01', 'body' => 'notas', 'assets' => array(
			array( 'name' => 'savitri-file-bridge.zip', 'browser_download_url' => 'https://github.com/x/y.zip' ),
		) ),
	) ),
);
$transient = (object) array( 'response' => array(), 'checked' => array() );
$out = sv_bridge_check_update( $transient );
$plugin = 'savitri-file-bridge/savitri-file-bridge.php';
check( 'entra em response', isset( $out->response[ $plugin ] ) );
check( 'versão nova certa', '2.9.0' === ( $out->response[ $plugin ]->new_version ?? '' ) );
check( 'package é o zip', 'https://github.com/x/y.zip' === ( $out->response[ $plugin ]->package ?? '' ) );

echo "\n== Versão instalada igual: nada a atualizar ==\n";
$GLOBALS['transients'] = array();
$GLOBALS['__http'] = array(
	'https://api.github.com/repos/anderdietrich/savitri-bridge/releases' => array( 200, array(
		array( 'tag_name' => 'v2.4.0', 'draft' => false, 'html_url' => 'u', 'assets' => array() ),
	) ),
);
$transient = (object) array( 'response' => array(), 'checked' => array() );
$out = sv_bridge_check_update( $transient );
check( 'nada em response', ! isset( $out->response[ $plugin ] ) || ! isset( $out->response[ $plugin ]->new_version ) );

echo "\n== Sem credencial: repositório é fixo e público ==\n";
check( 'repositório fixo', 'anderdietrich/savitri-bridge' === sv_bridge_github_repo() );
check( 'nenhuma opção de repositório é lida', '' === (string) get_option( 'sv_bridge_github_repo', '' ) );
check( 'não existe mais função de token', ! function_exists( 'sv_bridge_github_token' ) );
check( 'não existe mais filtro de token', ! function_exists( 'sv_bridge_http_add_token' ) );

$GLOBALS['transients'] = array();
$GLOBALS['__http'] = array(
	'https://api.github.com/repos/anderdietrich/savitri-bridge/releases' => array( 200, array(
		array( 'tag_name' => 'v3.0.0', 'draft' => false, 'html_url' => 'u', 'assets' => array(
			array( 'name' => 'savitri-file-bridge.zip', 'browser_download_url' => 'https://github.com/x/y.zip' ),
		) ),
	) ),
);
$latest = sv_bridge_github_latest( true );
check( 'consulta funciona sem credencial', ! is_wp_error( $latest ) && '3.0.0' === $latest['version'], is_wp_error( $latest ) ? $latest->get_error_message() : '' );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
