<?php
/**
 * Teste do protocolo MCP: handshake, tools/list e tools/call.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'SV_BRIDGE_VERSION', '2.0.0' );
define( 'SV_BRIDGE_DIR', __DIR__ . '/../' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svmcp/wp-content' );

class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return null; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function wp_normalize_path( $p ) { return preg_replace( '#/+#', '/', str_replace( '\\', '/', $p ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_mkdir_p( $d ) { return @mkdir( $d, 0777, true ) || is_dir( $d ); }
function get_bloginfo( $k ) {
	$map = array( 'name' => 'Savitri', 'version' => '6.9', 'url' => 'https://exemplo.test' );
	return isset( $map[ $k ] ) ? $map[ $k ] : '';
}
function home_url( $p = '' ) { return 'https://exemplo.test' . $p; }
function get_stylesheet() { return 'hello-elementor-child'; }
function sv_bridge_is_readonly() { return ! empty( $GLOBALS['__readonly'] ); }

class SV_Bridge_Log {
	public static $lines = array();
	public static function add( $m, $r = '', $e = '' ) { self::$lines[] = $m; }
	public static function tail( $n = 50 ) { return self::$lines; }
}

require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-paths.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-mcp.php';

$base = WP_CONTENT_DIR;
@mkdir( $base . '/themes/meu-tema', 0777, true );
file_put_contents( $base . '/themes/meu-tema/style.css', "body{color:red}\n" );

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else {
		$fail++; echo "  FAIL $label\n";
		if ( '' !== $detail ) { echo '       ' . substr( $detail, 0, 300 ) . "\n"; }
	}
}

$mcp = new SV_Bridge_MCP();

echo "== Handshake ==\n";
$init = $mcp->handle(
	array(
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => array(
			'protocolVersion' => '2025-06-18',
			'clientInfo'      => array( 'name' => 'teste', 'version' => '1' ),
			'capabilities'    => array(),
		),
	)
);
check( 'responde ao initialize', isset( $init['result'] ), json_encode( $init, JSON_PRETTY_PRINT ) );
check( 'devolve protocolVersion', isset( $init['result']['protocolVersion'] ), json_encode( $init ) );
check( 'anuncia capability tools', isset( $init['result']['capabilities']['tools'] ) );
check( 'tem serverInfo.name', isset( $init['result']['serverInfo']['name'] ) );
check( 'ecoou a versão pedida', ( $init['result']['protocolVersion'] ?? '' ) === '2025-06-18' );

$init_old = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2024-11-05' ) ) );
check( 'aceita versão antiga', ( $init_old['result']['protocolVersion'] ?? '' ) === '2024-11-05' );

$init_future = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2099-01-01' ) ) );
check( 'versão desconhecida cai na nossa', ( $init_future['result']['protocolVersion'] ?? '' ) === SV_Bridge_MCP::PROTOCOL_VERSION );

echo "\n== Notificações ==\n";
check( 'notifications/initialized sem resposta', null === $mcp->handle( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) );
check( 'notificação desconhecida sem resposta', null === $mcp->handle( array( 'jsonrpc' => '2.0', 'method' => 'qualquer/coisa' ) ) );
$ping = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping' ) );
check( 'ping responde', isset( $ping['result'] ) );

echo "\n== tools/list ==\n";
$list = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list' ) );
$tools = $list['result']['tools'] ?? array();
check( 'lista não vazia', count( $tools ) >= 8, json_encode( $list ) );
$names = array_column( $tools, 'name' );
foreach ( array( 'wp_read_file', 'wp_write_file', 'wp_list_files', 'wp_site_info', 'wp_list_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post' ) as $t ) {
	check( "tem $t", in_array( $t, $names, true ), implode( ',', $names ) );
}
$valid = true;
foreach ( $tools as $tool ) {
	if ( empty( $tool['name'] ) || empty( $tool['description'] ) || ! isset( $tool['inputSchema']['type'] ) ) { $valid = false; }
}
check( 'todas têm name/description/inputSchema', $valid );
$schema_ok = true;
foreach ( $tools as $tool ) {
	$req = $tool['inputSchema']['required'] ?? array();
	foreach ( $req as $r ) {
		if ( ! isset( $tool['inputSchema']['properties'][ $r ] ) ) { $schema_ok = false; }
	}
}
check( 'required sempre existe em properties', $schema_ok );

$json_ok  = true;
$problems = array();
foreach ( $tools as $tool ) {
	$enc = json_encode( $tool['inputSchema'] );
	$dec = json_decode( $enc, true );
	// "properties" vazio tem de sair como {} (objeto), nunca como [] (lista).
	if ( isset( $tool['inputSchema']['properties'] ) && array() === $tool['inputSchema']['properties'] && false === strpos( $enc, '"properties":{}' ) ) {
		$json_ok    = false;
		$problems[] = $tool['name'] . ':properties';
	}
	// "type" como lista de tipos é recusado por validadores estritos: usar anyOf.
	if ( isset( $tool['inputSchema']['properties'] ) ) {
		foreach ( $tool['inputSchema']['properties'] as $k => $prop ) {
			if ( isset( $prop['type'] ) && is_array( $prop['type'] ) ) {
				$json_ok    = false;
				$problems[] = $tool['name'] . '.' . $k . ':type-lista';
			}
			if ( isset( $prop['items']['type'] ) && is_array( $prop['items']['type'] ) ) {
				$json_ok    = false;
				$problems[] = $tool['name'] . '.' . $k . ':items-type-lista';
			}
			if ( isset( $prop['items'] ) && ! isset( $prop['items']['type'] ) && ! isset( $prop['items']['anyOf'] ) ) {
				$json_ok    = false;
				$problems[] = $tool['name'] . '.' . $k . ':items-sem-type';
			}
		}
	}
	if ( empty( $enc ) || null === $dec ) {
		$json_ok    = false;
		$problems[] = $tool['name'] . ':json-invalido';
	}
}
check( 'schema vira JSON válido (properties {} / anyOf)', $json_ok, implode( ', ', $problems ) );

echo "\n== tools/call ==\n";
$info = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ) );
$text = $info['result']['content'][0]['text'] ?? '';
check( 'wp_site_info responde', false === ( $info['result']['isError'] ?? true ), $text );
check( 'wp_site_info traz o site', false !== strpos( $text, 'Savitri' ), $text );

$read = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_read_file', 'arguments' => array( 'path' => 'themes/meu-tema/style.css' ) ) ) );
$readtext = $read['result']['content'][0]['text'] ?? '';
check( 'wp_read_file lê o CSS', false !== strpos( $readtext, 'body{color:red}' ), $readtext );

$write = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_write_file', 'arguments' => array( 'path' => 'themes/meu-tema/novo.css', 'content' => 'h1{}' ) ) ) );
check( 'wp_write_file grava', false === ( $write['result']['isError'] ?? true ), json_encode( $write ) );
check( 'arquivo existe no disco', file_exists( $base . '/themes/meu-tema/novo.css' ) );

$escape = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_read_file', 'arguments' => array( 'path' => '../../wp-config.php' ) ) ) );
check( 'travessia é recusada', true === ( $escape['result']['isError'] ?? false ), json_encode( $escape ) );

$outside = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_read_file', 'arguments' => array( 'path' => 'uploads/x.php' ) ) ) );
check( 'uploads é recusado', true === ( $outside['result']['isError'] ?? false ) );

$unknown = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => array( 'name' => 'nao_existe', 'arguments' => array() ) ) );
check( 'ferramenta desconhecida dá erro tratado', true === ( $unknown['result']['isError'] ?? false ) );

echo "\n== Somente leitura ==\n";
$GLOBALS['__readonly'] = true;
$blocked = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_write_file', 'arguments' => array( 'path' => 'themes/meu-tema/x.css', 'content' => 'x' ) ) ) );
check( 'escrita bloqueada', true === ( $blocked['result']['isError'] ?? false ), json_encode( $blocked ) );
check( 'arquivo não foi criado', ! file_exists( $base . '/themes/meu-tema/x.css' ) );
$readonly_read = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_read_file', 'arguments' => array( 'path' => 'themes/meu-tema/style.css' ) ) ) );
check( 'leitura continua liberada', false === ( $readonly_read['result']['isError'] ?? true ) );
$GLOBALS['__readonly'] = false;

echo "\n== Erros de protocolo ==\n";
$bad = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 14, 'method' => 'metodo/inexistente' ) );
check( 'método desconhecido → -32601', ( $bad['error']['code'] ?? 0 ) === -32601, json_encode( $bad ) );
check( 'erro mantém o id', ( $bad['id'] ?? null ) === 14 );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
