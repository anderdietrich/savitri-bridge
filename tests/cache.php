<?php
/**
 * Testes do cache: a resposta do canal depende da chave e nunca pode ser guardada.
 *
 * Bug guardado aqui: quando a rota ainda não existia (antes da primeira chave),
 * o WordPress devolvia rest_no_route (404) e o cache de página guardava o 404.
 * Criar a chave não adiantava até alguém limpar o cache à mão.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svcache/wp-content' );

$GLOBALS['__opts']     = array();
$GLOBALS['__actions']  = array();
$GLOBALS['__routes']   = array();
$GLOBALS['transients'] = array();

class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_normalize_path( $p ) { return preg_replace( '#/+#', '/', str_replace( '\\', '/', $p ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function rest_url( $p = '' ) { return 'https://exemplo.test/wp-json/' . $p; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__routes'][ $ns . $route ] = $args; return true; }
function do_action( $hook, $arg = '' ) { $GLOBALS['__actions'][] = $hook; return true; }
function add_action( $h, $c, $p = 10, $n = 1 ) { return true; }
function wp_cache_flush() { $GLOBALS['__actions'][] = 'wp_cache_flush'; return true; }

class WP_REST_Request {
	private $params; private $headers;
	public function __construct( $params = array(), $headers = array() ) { $this->params = $params; $this->headers = $headers; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_header( $k ) { $k = strtolower( $k ); return $this->headers[ $k ] ?? ''; }
	public function get_route() { return '/sv-bridge/v1/mcp'; }
}
class SV_Bridge_Log {
	public static function add( $m, $r = '', $e = '' ) {}
	public static function tail( $n = 50 ) { return array(); }
}
function sv_bridge_is_readonly() { return false; }
function sv_bridge_is_enabled() { return '' !== sv_bridge_key_source(); }

require_once __DIR__ . '/../includes/class-sv-bridge-cache.php';
require_once __DIR__ . '/../includes/class-sv-bridge-key.php';
require_once __DIR__ . '/../includes/class-sv-bridge-paths.php';
require_once __DIR__ . '/../includes/class-sv-bridge-auth.php';
require_once __DIR__ . '/../includes/class-sv-bridge-rest.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label" . ( '' !== $extra ? "  [$extra]" : '' ) . "\n"; }
}

echo "== Qual rota é do canal ==\n";
check( 'mcp é do canal', sv_bridge_is_bridge_route( '/sv-bridge/v1/mcp' ) );
check( 'ping é do canal', sv_bridge_is_bridge_route( 'sv-bridge/v1/ping' ) );
check( 'wp/v2/posts não é', ! sv_bridge_is_bridge_route( '/wp/v2/posts' ) );
check( 'rota vazia não é', ! sv_bridge_is_bridge_route( '' ) );

echo "\n== Sem chave, a rota existe e responde 503 (não 404) ==\n";
check( 'nenhuma chave', array() === sv_bridge_keys() );
$rest = new SV_Bridge_REST();
$rest->register_routes();
check( 'rota do MCP registrada', isset( $GLOBALS['__routes']['sv-bridge/v1/mcp'] ) );
$perm = $GLOBALS['__routes']['sv-bridge/v1/ping']['permission_callback'];
$res  = call_user_func( $perm, new WP_REST_Request() );
check( 'erro é sv_bridge_disabled', is_wp_error( $res ) && 'sv_bridge_disabled' === $res->get_error_code(), is_wp_error( $res ) ? $res->get_error_code() : 'sem erro' );
check( 'status 503', is_wp_error( $res ) && 503 === ( $res->get_error_data()['status'] ?? 0 ) );

echo "\n== Criar e revogar descartam o cache ==\n";
$GLOBALS['__actions'] = array();
$entry = sv_bridge_add_key( 'Maestro — Bob' );
check( 'purge chamado ao criar', in_array( 'litespeed_purge_api', $GLOBALS['__actions'], true ), implode( ',', $GLOBALS['__actions'] ) );
check( 'cache de objeto limpo ao criar', in_array( 'wp_cache_flush', $GLOBALS['__actions'], true ) );
$GLOBALS['__actions'] = array();
sv_bridge_revoke_key( $entry['id'] );
check( 'purge chamado ao revogar', in_array( 'litespeed_purge_api', $GLOBALS['__actions'], true ) );

echo "\n== Autenticação depois de criar ==\n";
$again = sv_bridge_add_key( 'Teste' );
$res   = call_user_func( $perm, new WP_REST_Request( array(), array( 'x-sv-bridge-key' => 'errada' ) ) );
check( 'chave errada → 401', is_wp_error( $res ) && 'sv_bridge_bad_key' === $res->get_error_code() );
$ok = call_user_func( $perm, new WP_REST_Request( array(), array( 'x-sv-bridge-key' => $again['key'] ) ) );
check( 'chave certa → passa', true === $ok );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
