<?php
/**
 * Testes de autenticação por chave.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svtest3/wp-content' );

// Chave de 64 caracteres, como no uso real.
define( 'SV_FILE_BRIDGE_KEY', str_repeat( 'a1b2c3d4', 8 ) );

$GLOBALS['transients'] = array();

class WP_Error {
	private $code; private $msg; private $data;
	public function __construct( $code = '', $msg = '', $data = null ) {
		$this->code = $code; $this->msg = $msg; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
	public function get_error_data() { return $this->data; }
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
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }

function sv_bridge_is_enabled() {
	return '' !== sv_bridge_key_source();
}

function get_option( $k, $d = false ) {
	return isset( $GLOBALS['__opts'][ $k ] ) ? $GLOBALS['__opts'][ $k ] : $d;
}
function update_option( $k, $v, $a = true ) {
	$GLOBALS['__opts'][ $k ] = $v;
	return true;
}

// Log sem banco: guarda em memória.
class SV_Bridge_Log {
	public static $lines = array();
	public static function add( $m, $r = '', $e = '' ) { self::$lines[] = $m; }
	public static function tail( $n = 50 ) { return self::$lines; }
}

require_once __DIR__ . '/../includes/class-sv-bridge-key.php';
require_once __DIR__ . '/../includes/class-sv-bridge-auth.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; }
}
function expect_code( $label, $res, $code ) {
	$ok = is_wp_error( $res ) && $res->get_error_code() === $code;
	check( $label . " → $code", $ok );
	if ( ! $ok ) {
		echo '       recebido: ' . ( is_wp_error( $res ) ? $res->get_error_code() : var_export( $res, true ) ) . "\n";
	}
}

echo "== Chave ==\n";
check( 'canal ligado com chave de 64 chars', sv_bridge_is_enabled() );

$good = array( 'x-sv-bridge-key' => SV_FILE_BRIDGE_KEY );

check( 'chave correta passa', true === SV_Bridge_Auth::permission( new WP_REST_Request( array(), $good ) ) );
expect_code( 'sem chave', SV_Bridge_Auth::permission( new WP_REST_Request() ), 'sv_bridge_missing_key' );
expect_code( 'chave errada', SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'x-sv-bridge-key' => 'errada' ) ) ), 'sv_bridge_bad_key' );
expect_code( 'prefixo da chave não vale', SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'x-sv-bridge-key' => substr( SV_FILE_BRIDGE_KEY, 0, 32 ) ) ) ), 'sv_bridge_bad_key' );

echo "\n== Authorization: Bearer (tela de conectores) ==\n";
$bearer = array( 'authorization' => 'Bearer ' . SV_FILE_BRIDGE_KEY );
check( 'Bearer com a chave passa', true === SV_Bridge_Auth::permission( new WP_REST_Request( array(), $bearer ) ) );
expect_code( 'Bearer com chave errada', SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'authorization' => 'Bearer xx' ) ) ), 'sv_bridge_bad_key' );
check( 'Bearer minúsculo também vale', true === SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'authorization' => 'bearer ' . SV_FILE_BRIDGE_KEY ) ) ) );

echo "\n== Bloqueio por tentativas ==\n";
// Zera o contador para contar do zero: 5 falhas exatas.
$GLOBALS['transients'] = array();
for ( $i = 1; $i <= 5; $i++ ) {
	SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'x-sv-bridge-key' => 'errada' . $i ) ) );
}
expect_code( 'após 5 falhas fica bloqueado', SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'x-sv-bridge-key' => SV_FILE_BRIDGE_KEY ) ) ), 'sv_bridge_locked' );
$GLOBALS['transients'] = array();
check( 'chave boa volta a funcionar após o bloqueio expirar', true === SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'x-sv-bridge-key' => SV_FILE_BRIDGE_KEY ) ) ) );

echo "\n== Log de chave inválida ==\n";
check( 'falha de chave registrada no log', count( SV_Bridge_Log::$lines ) > 0 );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
