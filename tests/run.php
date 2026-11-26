<?php
/**
 * Testes do núcleo do plugin-ponte (sem WordPress).
 *
 * Roda com: php tests/run.php
 */

// Só roda pela linha de comando.
if ( PHP_SAPI !== 'cli' ) {
	exit( 'Este arquivo roda apenas em CLI.' );
}

// --- Stubs mínimos do WordPress -------------------------------------------
define( 'ABSPATH', '/' );

$GLOBALS['__errors'] = array();

class WP_Error {
	private $code;
	private $msg;
	private $data;
	public function __construct( $code = '', $msg = '', $data = null ) {
		$this->code = $code;
		$this->msg  = $msg;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_normalize_path( $p ) {
	$p = str_replace( '\\', '/', $p );
	$p = preg_replace( '#/+#', '/', $p );
	return $p;
}
function __( $s, $d = null ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }

class WP_Bridge_Test_Helper {
	public static function is_str( $v ) { return is_string( $v ) && '' !== $v; }
}

// Simula o wp-content do site.
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/svtest/wp-content' );

require_once __DIR__ . '/../includes/class-sv-bridge-paths.php';

// --- Mini runner ----------------------------------------------------------
$pass = 0;
$fail = 0;

function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label\n";
	}
}

function expect_error( $label, $result, $code ) {
	$ok = is_wp_error( $result ) && $result->get_error_code() === $code;
	check( $label . " → $code", $ok );
	if ( ! $ok ) {
		echo '       recebido: ' . ( is_wp_error( $result ) ? $result->get_error_code() : var_export( $result, true ) ) . "\n";
	}
}

// --- Preparo --------------------------------------------------------------
$base = WP_CONTENT_DIR;
@mkdir( $base . '/themes/meu-tema', 0777, true );
@mkdir( $base . '/plugins/meu-plugin', 0777, true );
file_put_contents( $base . '/themes/meu-tema/style.css', "/* x */\n" );
file_put_contents( $base . '/wp-config.php', "<?php // segredo\n" );

// Symlink apontando para fora da área permitida.
@unlink( $base . '/themes/evil' );
@symlink( $base, $base . '/themes/evil' );

echo "\n== Caminhos permitidos ==\n";
check( 'themes/meu-tema/style.css resolve', WP_Bridge_Test_Helper::is_str( SV_Bridge_Paths::resolve( 'themes/meu-tema/style.css' ) ) );
check( 'plugins/meu-plugin resolve', WP_Bridge_Test_Helper::is_str( SV_Bridge_Paths::resolve( 'plugins/meu-plugin' ) ) );
check( 'barra inicial é tolerada', WP_Bridge_Test_Helper::is_str( SV_Bridge_Paths::resolve( '/themes/meu-tema/style.css' ) ) );
check( 'leitura de arquivo existente', WP_Bridge_Test_Helper::is_str( SV_Bridge_Paths::resolve_existing_file( 'themes/meu-tema/style.css' ) ) );

echo "\n== Fora do escopo / bloqueios ==\n";
expect_error( 'wp-config.php bloqueado', SV_Bridge_Paths::resolve( 'wp-config.php' ), 'sv_bridge_outside_scope' );
expect_error( 'uploads bloqueado', SV_Bridge_Paths::resolve( 'uploads/x.php' ), 'sv_bridge_outside_scope' );
expect_error( 'travessia para wp-config', SV_Bridge_Paths::resolve( 'themes/../../wp-config.php' ), 'sv_bridge_bad_path' );
expect_error( 'travessia no meio', SV_Bridge_Paths::resolve( 'themes/meu-tema/../../../wp-config.php' ), 'sv_bridge_bad_path' );
expect_error( 'ponto solto', SV_Bridge_Paths::resolve( 'themes/./x' ), 'sv_bridge_bad_path' );
expect_error( 'byte nulo', SV_Bridge_Paths::resolve( "themes/x\0.php" ), 'sv_bridge_bad_path' );
expect_error( 'caminho vazio', SV_Bridge_Paths::resolve( '' ), 'sv_bridge_missing_path' );
expect_error( 'symlink para fora', SV_Bridge_Paths::resolve( 'themes/evil/plugins' ), 'sv_bridge_symlink_escape' );
expect_error( 'arquivo inexistente', SV_Bridge_Paths::resolve_existing_file( 'themes/nao-existe.php' ), 'sv_bridge_not_found' );
expect_error( 'diretório não é arquivo', SV_Bridge_Paths::resolve_existing_file( 'themes/meu-tema' ), 'sv_bridge_not_a_file' );

echo "\n== Caminho relativo de volta ==\n";
$abs = SV_Bridge_Paths::resolve( 'themes/meu-tema/style.css' );
check( 'to_relative devolve themes/...', SV_Bridge_Paths::to_relative( $abs ) === 'themes/meu-tema/style.css' );

echo "\n== Entradas de .zip ==\n";
check( 'entrada normal', true === SV_Bridge_Paths::check_zip_entry( 'pasta/arquivo.php' ) );
expect_error( 'zip slip ..', SV_Bridge_Paths::check_zip_entry( '../../wp-config.php' ), 'sv_bridge_zip_slip' );
expect_error( 'zip slip no meio', SV_Bridge_Paths::check_zip_entry( 'a/../../b.php' ), 'sv_bridge_zip_slip' );
expect_error( 'caminho absoluto', SV_Bridge_Paths::check_zip_entry( '/etc/passwd' ), 'sv_bridge_zip_absolute' );
expect_error( 'letra de unidade', SV_Bridge_Paths::check_zip_entry( 'C:/windows/x' ), 'sv_bridge_zip_absolute' );
expect_error( 'extensão phar', SV_Bridge_Paths::check_zip_entry( 'x/y.phar' ), 'sv_bridge_zip_blocked' );
expect_error( 'byte nulo no zip', SV_Bridge_Paths::check_zip_entry( "a\0b.php" ), 'sv_bridge_zip_slip' );

// --- Resultado ------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
