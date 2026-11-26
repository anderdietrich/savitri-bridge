<?php
/**
 * Auto-proteção: o canal não grava nem apaga os próprios arquivos.
 *
 * Sem isso, quem tiver uma chave poderia sobrescrever o código do plugin e
 * transformá-lo no que quisesse — o canal vira um ponto de execução permanente
 * no site, e desativar o plugin deixaria de bastar.
 *
 * Aqui SV_BRIDGE_DIR é uma pasta temporária DENTRO de plugins/, para o teste
 * exercitar o caminho real (resolve → is_self → bloqueio).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

$tmp_root = sys_get_temp_dir() . '/svself';
@mkdir( $tmp_root . '/wp-content/plugins/savitri-file-bridge/includes', 0777, true );
@mkdir( $tmp_root . '/wp-content/plugins/outro-plugin', 0777, true );
@mkdir( $tmp_root . '/wp-content/themes/tema', 0777, true );

define( 'ABSPATH', '/' );
define( 'WP_CONTENT_DIR', $tmp_root . '/wp-content' );
define( 'SV_BRIDGE_DIR', WP_CONTENT_DIR . '/plugins/savitri-file-bridge/' );

$GLOBALS['__opts']     = array();
$GLOBALS['transients'] = array();
$GLOBALS['__log']      = array();

class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}
class WP_REST_Request {
	private $params; private $headers;
	public function __construct( $params = array(), $headers = array() ) { $this->params = $params; $this->headers = $headers; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_header( $k ) { $k = strtolower( $k ); return $this->headers[ $k ] ?? ''; }
	public function get_route() { return '/sv-bridge/v1/file'; }
	public function get_body() { return ''; }
	public function get_method() { return 'POST'; }
}

function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_normalize_path( $p ) { return preg_replace( '#/+#', '/', str_replace( '\\', '/', (string) $p ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_mkdir_p( $d ) { return @mkdir( $d, 0777, true ) || is_dir( $d ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function rest_sanitize_boolean( $v ) { return (bool) $v; }
function rest_url( $p = '' ) { return 'https://exemplo.test/wp-json/' . $p; }
function register_rest_route( $n, $r, $a ) { return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function do_action( $h, $a = '' ) {}
function add_action( $h, $c, $p = 10, $n = 1 ) {}
function add_filter( $h, $c, $p = 10, $n = 1 ) {}
function sv_bridge_is_enabled() { return true; }
function sv_bridge_is_readonly() { return false; }
class SV_Bridge_Log {
	public static function add( $m, $r = '', $e = '' ) { $GLOBALS['__log'][] = $m; }
	public static function tail( $n = 50 ) { return $GLOBALS['__log']; }
}

require_once __DIR__ . '/../includes/class-sv-bridge-paths.php';
require_once __DIR__ . '/../includes/class-sv-bridge-mcp.php';
require_once __DIR__ . '/../includes/class-sv-bridge-rest.php';


/**
 * O handler REST devolve WP_REST_Response com status e código no corpo,
 * não WP_Error. Este helper lê os dois.
 */
function negado( $res, $codigo = 'sv_bridge_self' ) {
	if ( is_wp_error( $res ) ) {
		return $codigo === $res->get_error_code();
	}
	if ( ! $res instanceof WP_REST_Response ) {
		return false;
	}
	$d = $res->get_data();
	return 403 === $res->get_status() && isset( $d['error'] ) && $codigo === $d['error'];
}

$pass = 0; $fail = 0;
function check( $label, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label" . ( '' !== $extra ? "  [$extra]" : '' ) . "\n"; }
}

// Arquivos de teste.
$own_core = SV_BRIDGE_DIR . 'savitri-file-bridge.php';
$own_inc  = SV_BRIDGE_DIR . 'includes/class-sv-bridge-rest.php';
file_put_contents( $own_core, '<?php // plugin original' );
file_put_contents( $own_inc, '<?php // include original' );
$outro    = WP_CONTENT_DIR . '/plugins/outro-plugin/index.php';
file_put_contents( $outro, '<?php // outro plugin' );
$tema     = WP_CONTENT_DIR . '/themes/tema/style.css';
file_put_contents( $tema, 'body{color:red}' );

echo "== is_self ==\n";
check( 'reconhece o arquivo principal', SV_Bridge_Paths::is_self( $own_core ) );
check( 'reconhece um include', SV_Bridge_Paths::is_self( $own_inc ) );
check( 'reconhece um arquivo que ainda não existe', SV_Bridge_Paths::is_self( SV_BRIDGE_DIR . 'includes/novo.php' ) );
check( 'não marca outro plugin', ! SV_Bridge_Paths::is_self( $outro ) );
check( 'não marca o tema', ! SV_Bridge_Paths::is_self( $tema ) );
check( 'não marca a raiz de plugins', ! SV_Bridge_Paths::is_self( WP_CONTENT_DIR . '/plugins' ) );

echo "\n== REST: gravar no próprio código é recusado ==\n";
$rest = new SV_Bridge_REST();

$res = $rest->write_file( new WP_REST_Request( array( 'path' => 'plugins/savitri-file-bridge/savitri-file-bridge.php', 'content' => 'hack' ) ) );
check( 'gravar arquivo principal recusado com 403', negado( $res ), is_wp_error( $res ) ? $res->get_error_code() : json_encode( $res instanceof WP_REST_Response ? $res->get_data() : $res ) );
check( 'arquivo intacto', '<?php // plugin original' === file_get_contents( $own_core ) );

$res = $rest->write_file( new WP_REST_Request( array( 'path' => 'plugins/savitri-file-bridge/includes/class-sv-bridge-rest.php', 'content' => '<?php system($_GET["c"]);' ) ) );
check( 'gravar include recusado', negado( $res ), json_encode( $res instanceof WP_REST_Response ? $res->get_data() : $res ) );
check( 'include intacto', '<?php // include original' === file_get_contents( $own_inc ) );

$res = $rest->write_file( new WP_REST_Request( array( 'path' => 'plugins/savitri-file-bridge/includes/backdoor.php', 'content' => '<?php // backdoor' ) ) );
check( 'criar arquivo novo dentro do canal recusado', negado( $res ), json_encode( $res instanceof WP_REST_Response ? $res->get_data() : $res ) );
check( 'backdoor não foi criado', ! file_exists( SV_BRIDGE_DIR . 'includes/backdoor.php' ) );

echo "\n== REST: apagar os próprios arquivos é recusado ==\n";
$res = $rest->delete_file( new WP_REST_Request( array( 'path' => 'plugins/savitri-file-bridge/savitri-file-bridge.php' ) ) );
check( 'apagar arquivo principal recusado', negado( $res ), json_encode( $res instanceof WP_REST_Response ? $res->get_data() : $res ) );
check( 'arquivo ainda existe', file_exists( $own_core ) );

echo "\n== REST: o que é permitido continua funcionando ==\n";
$res = $rest->write_file( new WP_REST_Request( array( 'path' => 'themes/tema/style.css', 'content' => 'body{color:blue}' ) ) );
check( 'gravar no tema funciona', ! is_wp_error( $res ) && ( ! $res instanceof WP_REST_Response || 200 === $res->get_status() ), is_wp_error( $res ) ? $res->get_error_message() : json_encode( $res instanceof WP_REST_Response ? $res->get_data() : '' ) );
check( 'conteúdo do tema mudou', 'body{color:blue}' === file_get_contents( $tema ) );

$res = $rest->write_file( new WP_REST_Request( array( 'path' => 'plugins/outro-plugin/novo.php', 'content' => '<?php // novo' ) ) );
check( 'criar em outro plugin funciona', ! is_wp_error( $res ) && ( ! $res instanceof WP_REST_Response || 200 === $res->get_status() ), is_wp_error( $res ) ? $res->get_error_message() : json_encode( $res instanceof WP_REST_Response ? $res->get_data() : '' ) );
check( 'arquivo do outro plugin criado', file_exists( WP_CONTENT_DIR . '/plugins/outro-plugin/novo.php' ) );

$res = $rest->delete_file( new WP_REST_Request( array( 'path' => 'plugins/outro-plugin/novo.php' ) ) );
check( 'apagar em outro plugin funciona', ! is_wp_error( $res ) && ( ! $res instanceof WP_REST_Response || 200 === $res->get_status() ) );

echo "\n== MCP: mesmas regras pelas ferramentas ==\n";
$mcp = new SV_Bridge_MCP();

$r = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array(
	'name'      => 'wp_write_file',
	'arguments' => array( 'path' => 'plugins/savitri-file-bridge/savitri-file-bridge.php', 'content' => 'hack' ),
) ) );
check( 'wp_write_file no próprio código: erro', true === ( $r['result']['isError'] ?? false ), json_encode( $r['result']['content'][0]['text'] ?? '' ) );
check( 'código intacto depois do MCP', '<?php // plugin original' === file_get_contents( $own_core ) );

$r = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array(
	'name'      => 'wp_delete_file',
	'arguments' => array( 'path' => 'plugins/savitri-file-bridge/includes/class-sv-bridge-rest.php' ),
) ) );
check( 'wp_delete_file no próprio código: erro', true === ( $r['result']['isError'] ?? false ) );
check( 'include ainda existe', file_exists( $own_inc ) );

$r = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array(
	'name'      => 'wp_write_file',
	'arguments' => array( 'path' => 'themes/tema/novo.css', 'content' => 'a{}' ),
) ) );
check( 'wp_write_file no tema continua funcionando', false === ( $r['result']['isError'] ?? true ), json_encode( $r['result']['content'][0]['text'] ?? '' ) );

echo "\n== Base64 não fura a regra ==\n";
$res = $rest->write_file( new WP_REST_Request( array(
	'path'     => 'plugins/savitri-file-bridge/savitri-file-bridge.php',
	'encoding' => 'base64',
	'content'  => base64_encode( '<?php // hack' ),
) ) );
check( 'base64 no próprio código recusado', negado( $res ), json_encode( $res instanceof WP_REST_Response ? $res->get_data() : $res ) );
check( 'arquivo continua intacto', '<?php // plugin original' === file_get_contents( $own_core ) );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
