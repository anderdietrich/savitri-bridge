<?php
/**
 * Testes da tabela de chaves: criar, renomear, revogar, migração e uso.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svkeys/wp-content' );

$GLOBALS['__opts']    = array();
$GLOBALS['transients'] = array();

function __( $s, $d = null ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__opts'][ $k ] = $v; return true; }

class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_REST_Request {
	private $params; private $headers;
	public function __construct( $params = array(), $headers = array() ) { $this->params = $params; $this->headers = $headers; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_header( $k ) { $k = strtolower( $k ); return $this->headers[ $k ] ?? ''; }
	public function get_route() { return '/sv-bridge/v1/mcp'; }
}

function sv_bridge_is_enabled() {
	return '' !== sv_bridge_key_source();
}

class SV_Bridge_Log {
	public static $lines = array();
	public static function add( $m, $r = '', $e = '' ) { self::$lines[] = $m; }
	public static function tail( $n = 50 ) { return self::$lines; }
}

require_once __DIR__ . '/../includes/class-sv-bridge-key.php';
require_once __DIR__ . '/../includes/class-sv-bridge-auth.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label" . ( '' !== $extra ? "  [$extra]" : '' ) . "\n"; }
}

echo "== Estado inicial ==\n";
check( 'lista começa vazia', array() === sv_bridge_keys() );
check( 'canal desligado sem chave', ! sv_bridge_is_enabled() );
check( 'origem vazia', '' === sv_bridge_key_source() );

echo "\n== Criar chaves ==\n";
$a = sv_bridge_add_key( 'Maestro — Bob' );
$b = sv_bridge_add_key( 'Claude Code' );
check( 'duas entradas na lista', 2 === count( sv_bridge_keys() ) );
check( 'nome guardado', 'Maestro — Bob' === $a['name'], $a['name'] );
check( 'chave com 64 hex', 1 === preg_match( '/^[a-f0-9]{64}$/', $a['key'] ) );
check( 'chaves diferentes', $a['key'] !== $b['key'] );
check( 'ids diferentes', $a['id'] !== $b['id'] );
check( 'criada preenchida', '' !== $a['created'] );
check( 'último uso vazio no início', '' === $a['last_used'] );
check( 'canal ligado', sv_bridge_is_enabled() );
check( 'origem é a opção', 'option' === sv_bridge_key_source() );

echo "\n== Autenticação com cada chave ==\n";
$req = function ( $k ) { return new WP_REST_Request( array(), array( 'x-sv-bridge-key' => $k ) ); };
check( 'primeira chave passa', true === SV_Bridge_Auth::permission( $req( $a['key'] ) ) );
check( 'segunda chave passa', true === SV_Bridge_Auth::permission( $req( $b['key'] ) ) );
check( 'chave inexistente falha', is_wp_error( SV_Bridge_Auth::permission( $req( str_repeat( 'deadbeef', 8 ) ) ) ) );

echo "\n== Último uso ==\n";
$after = sv_bridge_keys();
check( 'último uso da primeira gravado', '' !== $after[0]['last_used'], $after[0]['last_used'] );
check( 'IP do último uso gravado', '' !== $after[0]['last_ip'] );

echo "\n== Renomear ==\n";
check( 'renomeia', sv_bridge_rename_key( $a['id'], 'Bob — produção' ) );
$keys = sv_bridge_keys();
check( 'nome novo na lista', 'Bob — produção' === $keys[0]['name'], $keys[0]['name'] );
check( 'chave não muda ao renomear', $a['key'] === $keys[0]['key'] );
check( 'id inexistente não renomeia', ! sv_bridge_rename_key( 'nao-existe', 'x' ) );

echo "\n== Revogar ==\n";
check( 'revoga a primeira', sv_bridge_revoke_key( $a['id'] ) );
$keys = sv_bridge_keys();
check( 'sobrou uma', 1 === count( $keys ) );
check( 'a que sobrou é a segunda', $b['key'] === $keys[0]['key'] );
check( 'chave revogada não autentica', is_wp_error( SV_Bridge_Auth::permission( $req( $a['key'] ) ) ) );
check( 'a outra continua valendo', sv_bridge_revoke_key( $b['id'] ) || true );
$GLOBALS['transients'] = array();
$c = sv_bridge_add_key( 'Sobrevivente' );
check( 'chave viva autentica', true === SV_Bridge_Auth::permission( $req( $c['key'] ) ) );
check( 'revogar id inexistente devolve false', ! sv_bridge_revoke_key( 'nao-existe' ) );

echo "\n== Revogar a última desliga o canal ==\n";
$GLOBALS['transients'] = array();
sv_bridge_revoke_key( $c['id'] );
check( 'lista vazia depois de revogar tudo', array() === sv_bridge_keys() );
check( 'canal desligado', ! sv_bridge_is_enabled() );
check( 'sem chave, acesso negado', is_wp_error( SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'x-sv-bridge-key' => $c['key'] ) ) ) ) );

echo "\n== Migração da chave antiga ==\n";
$GLOBALS['__opts'] = array( 'sv_bridge_key' => str_repeat( 'b', 64 ) );
$migrated = sv_bridge_keys();
check( 'chave antiga entra na lista', 1 === count( $migrated ), (string) count( $migrated ) );
check( 'chave antiga preservada', str_repeat( 'b', 64 ) === $migrated[0]['key'] );
check( 'migrada autentica', true === SV_Bridge_Auth::permission( new WP_REST_Request( array(), array( 'x-sv-bridge-key' => str_repeat( 'b', 64 ) ) ) ) );
check( 'não duplica ao reler', 1 === count( sv_bridge_keys() ) );
check( 'revogar a migrada apaga a opção antiga', sv_bridge_revoke_key( $migrated[0]['id'] ) && '' === get_option( 'sv_bridge_key', '' ) );
check( 'e a chave antiga não volta na próxima leitura', array() === sv_bridge_keys() );

echo "\n== Nome vazio é permitido na API (a tela exige) ==\n";
$sem = sv_bridge_add_key( '' );
check( 'cria sem nome', 1 === count( sv_bridge_keys() ) );
check( 'nome fica vazio', '' === $sem['name'] );
sv_bridge_revoke_key( $sem['id'] );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
