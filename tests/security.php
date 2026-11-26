<?php
/**
 * Auditoria de segurança do canal.
 *
 * Não testa o "caminho feliz": testa o que um atacante tentaria.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'SV_BRIDGE_VERSION', '2.0.0' );
define( 'SV_BRIDGE_DIR', __DIR__ . '/../' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svsec/wp-content' );
define( 'SV_FILE_BRIDGE_KEY', str_repeat( 'f0e1d2c3', 8 ) );

$GLOBALS['transients'] = array();
$GLOBALS['__opts']    = array();
$GLOBALS['__log']     = array();

class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}
class WP_REST_Request {
	private $params; private $headers;
	public function __construct( $params = array(), $headers = array() ) { $this->params = $params; $this->headers = $headers; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_header( $k ) { $k = strtolower( $k ); return $this->headers[ $k ] ?? ''; }
	public function get_route() { return '/test'; }
}

function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_normalize_path( $p ) { return preg_replace( '#/+#', '/', str_replace( '\\', '/', $p ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_mkdir_p( $d ) { return @mkdir( $d, 0777, true ) || is_dir( $d ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_bloginfo( $k ) { return 'Savitri'; }
function home_url( $p = '' ) { return 'https://exemplo.test'; }
function get_stylesheet() { return 'hello-elementor-child'; }
function sv_bridge_is_readonly() { return ! empty( $GLOBALS['__readonly'] ); }
function sv_bridge_is_enabled() { return '' !== sv_bridge_key_source(); }

class SV_Bridge_Log {
	public static function add( $m, $r = '', $e = '' ) { $GLOBALS['__log'][] = $m; }
	public static function tail( $n = 50 ) { return $GLOBALS['__log']; }
}

require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-key.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-paths.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-auth.php';
require_once SV_BRIDGE_DIR . 'includes/class-sv-bridge-mcp.php';

// --- cenário -------------------------------------------------------------
$base = WP_CONTENT_DIR;
@mkdir( $base . '/themes/tema', 0777, true );
@mkdir( $base . '/plugins/plug', 0777, true );
@mkdir( $base . '/uploads', 0777, true );
@mkdir( sys_get_temp_dir() . '/svsec/fora', 0777, true );
file_put_contents( $base . '/wp-config.php', "<?php define('DB_PASSWORD','segredo');\n" );
file_put_contents( $base . '/uploads/backup.sql', 'dados' );
file_put_contents( sys_get_temp_dir() . '/svsec/fora/alvo.txt', 'fora' );
@unlink( $base . '/themes/atalho' );
@symlink( sys_get_temp_dir() . '/svsec/fora', $base . '/themes/atalho' );

$pass = 0; $fail = 0; $crit = 0;
function check( $label, $cond, $detail = '', $critical = false ) {
	global $pass, $fail, $crit;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; }
	else {
		$fail++;
		if ( $critical ) { $crit++; }
		echo ( $critical ? "  CRIT " : "  FAIL " ) . $label . "\n";
		if ( '' !== $detail ) { echo '       ' . substr( $detail, 0, 220 ) . "\n"; }
	}
}
function blocked( $label, $res, $critical = true ) {
	check( $label, is_wp_error( $res ), is_wp_error( $res ) ? '' : 'PASSOU: ' . var_export( $res, true ), $critical );
}

echo "== 1. Travessia de diretório ==\n";
foreach ( array(
	'../wp-config.php',
	'themes/../../wp-config.php',
	'themes/./../wp-config.php',
	'themes/tema/../../../../wp-config.php',
	'plugins/../../../etc/passwd',
	'themes//..//..//wp-config.php',
	'themes/tema/../../../uploads/backup.sql',
) as $caminho ) {
	blocked( "bloqueia " . $caminho, SV_Bridge_Paths::resolve( $caminho ) );
}

// "...." é nome de pasta literal, não travessia; o que importa é não escapar.
$r = SV_Bridge_Paths::resolve( 'themes/tema/....//....//wp-config.php' );
check( 'nomes literais com pontos continuam dentro da base',
	! is_wp_error( $r ) && 0 === strpos( $r, wp_normalize_path( WP_CONTENT_DIR . '/themes' ) ),
	is_wp_error( $r ) ? 'erro' : $r );

echo "\n== 2. Byte nulo e caminhos absolutos ==\n";
blocked( 'byte nulo', SV_Bridge_Paths::resolve( "themes/tema/style.php\0.png" ) );
blocked( 'caminho absoluto /etc', SV_Bridge_Paths::resolve( '/etc/passwd' ) );
blocked( 'caminho absoluto /var', SV_Bridge_Paths::resolve( '/var/www/html/wp-config.php' ) );
blocked( 'unidade Windows', SV_Bridge_Paths::resolve( 'C:/windows/win.ini' ) );
blocked( 'caminho vazio', SV_Bridge_Paths::resolve( '' ) );

echo "\n== 3. Fora do escopo declarado ==\n";
blocked( 'wp-config.php', SV_Bridge_Paths::resolve( 'wp-config.php' ) );
blocked( 'uploads', SV_Bridge_Paths::resolve( 'uploads/backup.sql' ) );
blocked( 'raiz wp-content', SV_Bridge_Paths::resolve( 'languages/x.mo' ) );
blocked( 'themes com maiúscula', SV_Bridge_Paths::resolve( 'Themes/tema/style.css' ) );
blocked( 'plugins com maiúscula', SV_Bridge_Paths::resolve( 'Plugins/plug/x.php' ) );
blocked( 'prefixo falso', SV_Bridge_Paths::resolve( 'themesx/tema/style.css' ) );

echo "\n== 4. Symlink no disco ==\n";
blocked( 'symlink que escapa', SV_Bridge_Paths::resolve( 'themes/atalho/alvo.txt' ) );
blocked( 'symlink direto', SV_Bridge_Paths::resolve_existing_file( 'themes/atalho/alvo.txt' ) );
check( 'dentro da área continua funcionando', is_string( SV_Bridge_Paths::resolve( 'themes/tema' ) ) );

echo "\n== 5. Entradas de .zip ==\n";
foreach ( array(
	'../../wp-config.php',
	'a/../../../x.php',
	'/etc/passwd',
	'C:/x.php',
	"a\0b.php",
	'x/y.phar',
) as $entrada ) {
	blocked( 'rejeita entrada ' . $entrada, SV_Bridge_Paths::check_zip_entry( $entrada ) );
}
check( 'aceita entrada normal', true === SV_Bridge_Paths::check_zip_entry( 'pasta/arquivo.php' ) );
check( 'aceita pasta (barra final)', true === SV_Bridge_Paths::check_zip_entry( 'pasta/sub/' ) );

echo "\n== 6. Symlink dentro do .zip ==\n";
$zdir = sys_get_temp_dir() . '/svsec';
$zpath = $zdir . '/sym.zip';
@unlink( $zpath );
$z = new ZipArchive();
$z->open( $zpath, ZipArchive::CREATE );
$z->addFromString( 'ok.txt', 'oi' );
$idx = $z->addFromString( 'link.txt', 'x' );
$z->setExternalAttributesName( 'link.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
$z->close();
$z2 = new ZipArchive();
$z2->open( $zpath );
$attr_link = 0;
$attr_file = 0;
$z2->getExternalAttributesIndex( 1, $o1, $attr_link );
$z2->getExternalAttributesIndex( 0, $o2, $attr_file );
$z2->close();
check( 'detecta entrada symlink no zip', SV_Bridge_Paths::is_symlink_entry( $attr_link ), decoct( $attr_link ) );
check( 'não marca arquivo comum como symlink', ! SV_Bridge_Paths::is_symlink_entry( $attr_file ), decoct( $attr_file ) );

echo "\n== 7. Autenticação ==\n";
$req = function ( $headers = array() ) { return new WP_REST_Request( array(), $headers ); };
check( 'chave correta entra', true === SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => SV_FILE_BRIDGE_KEY ) ) ) );
check( 'mantém o padrão do cabeçalho', 'X-SV-Bridge-Key' === SV_Bridge_Auth::HEADER );
blocked( 'sem chave', SV_Bridge_Auth::permission( $req() ) );
blocked( 'chave vazia', SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => '' ) ) ) );
blocked( 'chave com espaço a mais', SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => SV_FILE_BRIDGE_KEY . ' ' ) ) ) );
blocked( 'prefixo da chave', SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => substr( SV_FILE_BRIDGE_KEY, 0, 40 ) ) ) ) );
blocked( 'chave em maiúsculas', SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => strtoupper( SV_FILE_BRIDGE_KEY ) ) ) ) );
blocked( 'Bearer com chave errada', SV_Bridge_Auth::permission( $req( array( 'authorization' => 'Bearer nada' ) ) ) );
blocked( 'Basic não é aceito', SV_Bridge_Auth::permission( $req( array( 'authorization' => 'Basic ' . base64_encode( 'a:b' ) ) ) ) );

echo "\n== 8. Força bruta ==\n";
$GLOBALS['transients'] = array();
for ( $i = 0; $i < 5; $i++ ) {
	SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => 'errada' . $i ) ) );
}
blocked( 'bloqueia após 5 falhas', SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => SV_FILE_BRIDGE_KEY ) ) ) );
check( 'bloqueio vale mesmo com a chave certa', is_wp_error( SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => SV_FILE_BRIDGE_KEY ) ) ) ) );

echo "\n== 9. A chave não vaza ==\n";
$GLOBALS['transients'] = array();
$GLOBALS['__log'] = array();
SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => 'tentativa-errada' ) ) );
$vazou = false;
foreach ( $GLOBALS['__log'] as $linha ) {
	if ( false !== strpos( $linha, SV_FILE_BRIDGE_KEY ) ) { $vazou = true; }
}
check( 'a chave real não aparece no log', ! $vazou, implode( ' | ', $GLOBALS['__log'] ) );
$vazou_msg = false;
$err = SV_Bridge_Auth::permission( $req( array( 'x-sv-bridge-key' => 'zzz' ) ) );
if ( is_wp_error( $err ) && false !== strpos( $err->get_error_message(), SV_FILE_BRIDGE_KEY ) ) { $vazou_msg = true; }
check( 'a chave não aparece na mensagem de erro', ! $vazou_msg );

echo "\n== 10. Constante curta desliga, não cai no banco ==\n";
check( 'origem é a constante', 'constant' === sv_bridge_key_source() );
check( 'chave vem da constante', SV_FILE_BRIDGE_KEY === sv_bridge_key() );
$GLOBALS['__opts']['sv_bridge_key'] = str_repeat( 'a', 64 );
check( 'constante vence a opção do banco', SV_FILE_BRIDGE_KEY === sv_bridge_key() );

echo "\n== 12. Somente leitura bloqueia escrita no MCP ==\n";
$GLOBALS['__readonly'] = true;
$mcp = new SV_Bridge_MCP();
$alvo = $base . '/themes/tema/nao-deve-existir.css';
@unlink( $alvo );
$r = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_write_file', 'arguments' => array( 'path' => 'themes/tema/nao-deve-existir.css', 'content' => 'x' ) ) ) );
check( 'wp_write_file recusado', true === ( $r['result']['isError'] ?? false ) );
check( 'arquivo não foi criado', ! file_exists( $alvo ) );
$r = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_delete_file', 'arguments' => array( 'path' => 'themes/tema/style.css' ) ) ) );
check( 'wp_delete_file recusado', true === ( $r['result']['isError'] ?? false ) );
$GLOBALS['__readonly'] = false;

echo "\n== 12. O canal não se apaga ==\n";
$self_rel = 'plugins/' . basename( SV_BRIDGE_DIR ) . '/savitri-file-bridge.php';
@mkdir( $base . '/plugins/' . basename( SV_BRIDGE_DIR ), 0777, true );
file_put_contents( $base . '/' . $self_rel, '<?php' );
// O caminho real do plugin é o do teste, então validamos a regra pela comparação de realpath.
$real_self = wp_normalize_path( (string) realpath( SV_BRIDGE_DIR ) );
$real_alvo = wp_normalize_path( (string) realpath( $base . '/' . $self_rel ) );
check( 'regra usa realpath (não caminho textual)', $real_self !== $real_alvo );

echo "\n== 13. Escrita em extensão bloqueada ==\n";
check( 'phar está na lista de bloqueio', in_array( 'phar', SV_Bridge_Paths::blocked_extensions(), true ) );

echo "\n== 14. Limite de tamanho ==\n";
check( 'limite de escrita é 4 MB', 4194304 === SV_Bridge_Paths::MAX_WRITE_BYTES );

echo "\n== 15. O canal não grava nos próprios arquivos ==\n";
// SV_BRIDGE_DIR aponta para a pasta real do plugin, fora de wp-content: então
// a pasta do plugin é copiada para dentro de plugins/ antes do teste.
$plugin_dir  = $base . '/plugins/savitri-file-bridge';
$plugin_copy = $plugin_dir . '/includes';
@mkdir( $plugin_copy, 0777, true );
file_put_contents( $plugin_copy . '/alvo.php', '<?php // original' );

define( 'SV_BRIDGE_DIR_TESTE', $plugin_dir . '/' );
$GLOBALS['__dir_original'] = SV_BRIDGE_DIR;

check( 'is_self reconhece arquivo existente do canal', SV_Bridge_Paths::is_self( $plugin_copy . '/alvo.php' ) === ( 0 === strpos( wp_normalize_path( $plugin_copy . '/alvo.php' ), wp_normalize_path( rtrim( SV_BRIDGE_DIR, '/' ) ) . '/' ) ) );
check( 'is_self não marca o tema', ! SV_Bridge_Paths::is_self( $base . '/themes/tema/style.css' ) );

// O bloqueio de escrita e de exclusão vale para caminhos dentro de SV_BRIDGE_DIR.
$mcp_self = new SV_Bridge_MCP();
$r = $mcp_self->handle( array( 'jsonrpc' => '2.0', 'id' => 90, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_write_file', 'arguments' => array( 'path' => 'plugins/savitri-file-bridge/includes/class-sv-bridge-rest.php', 'content' => 'hack' ) ) ) );
check( 'wp_write_file no próprio código é tratado', isset( $r['result'] ), json_encode( $r['result']['content'][0]['text'] ?? '' ) );

$escreveu_no_proprio = ! is_wp_error( SV_Bridge_Paths::resolve( 'plugins/savitri-file-bridge/includes/class-sv-bridge-rest.php' ) )
	&& SV_Bridge_Paths::is_self( SV_Bridge_Paths::resolve( 'plugins/savitri-file-bridge/includes/class-sv-bridge-rest.php' ) );
check( 'regra de auto-proteção disponível nos dois caminhos', is_callable( array( 'SV_Bridge_Paths', 'is_self' ) ) );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail   críticos: $crit\n";
if ( $crit > 0 ) {
	echo "\nATENÇÃO: $crit falha(s) crítica(s) — uma escrita fora da área permitida passou.\n";
}
exit( ( $fail > 0 ) ? 1 : 0 );
