<?php
/**
 * Teste de integração das rotas: stubs do WordPress e verificação
 * de que todas as rotas são registradas e que os callbacks existem.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'SV_BRIDGE_VERSION', '1.0.0' );
define( 'SV_BRIDGE_DIR', __DIR__ . '/../' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svtest2/wp-content' );

$GLOBALS['routes'] = array();

class WP_Error {
	private $code; private $msg; private $data;
	public function __construct( $code = '', $msg = '', $data = null ) {
		$this->code = $code; $this->msg = $msg; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request {
	private $params; private $headers;
	public function __construct( $params = array(), $headers = array() ) { $this->params = $params; $this->headers = $headers; }
	public function get_param( $k ) { return isset( $this->params[ $k ] ) ? $this->params[ $k ] : null; }
	public function get_header( $k ) { $k = strtolower( $k ); return isset( $this->headers[ $k ] ) ? $this->headers[ $k ] : ''; }
	public function get_route() { return '/sv-bridge/v1/test'; }
}

function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_normalize_path( $p ) { return preg_replace( '#/+#', '/', str_replace( '\\', '/', $p ) ); }
function rest_sanitize_boolean( $v ) { return (bool) $v; }
function rest_url( $p = '' ) { return 'https://exemplo.test/wp-json/' . $p; }
$GLOBALS['transients'] = array();
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function register_rest_route( $ns, $route, $args ) {
	$GLOBALS['routes'][ $ns . $route ] = $args;
	return true;
}

function sv_bridge_is_enabled() {
	return '' !== sv_bridge_key_source();
}
function sv_bridge_is_readonly() { return false; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['__opts'][ $k ] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__opts'][ $k ] = $v; return true; }

require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-key.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-paths.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-log.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-auth.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-rest.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; }
}

$rest = new SV_Bridge_REST();
$rest->register_routes();

echo "== Rotas registradas ==\n";
$expected = array(
	'sv-bridge/v1/mcp', 'sv-bridge/v1/ping', 'sv-bridge/v1/list', 'sv-bridge/v1/file',
	'sv-bridge/v1/dir', 'sv-bridge/v1/unzip', 'sv-bridge/v1/plugins',
	'sv-bridge/v1/plugin', 'sv-bridge/v1/themes', 'sv-bridge/v1/theme',
);
foreach ( $expected as $r ) {
	check( $r, isset( $GLOBALS['routes'][ $r ] ) );
}

echo "\n== Callbacks existem ==\n";
foreach ( $GLOBALS['routes'] as $route => $args ) {
	$sets = isset( $args['methods'] ) ? array( $args ) : $args;
	foreach ( $sets as $set ) {
		$cb   = isset( $set['callback'] ) ? $set['callback'] : null;
		$perm = isset( $set['permission_callback'] ) ? $set['permission_callback'] : null;

		check( $route . ' callback', is_callable( $cb ) );
		check( $route . ' permission_callback', is_callable( $perm ) );

		if ( is_callable( $perm ) ) {
			// Sem chave definida, a permissão precisa negar.
			$res = call_user_func( $perm, new WP_REST_Request() );
			check( $route . ' nega sem chave', is_wp_error( $res ) );
		}
	}
}

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
