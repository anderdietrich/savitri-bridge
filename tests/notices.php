<?php
/**
 * Avisos de outros plugins: tipo e limpeza do HTML.
 *
 * O wp-admin imprime avisos de "admin_notices" antes do nosso HTML, e eles caíam
 * no meio do cabeçalho. São capturados e reimpressos no nosso bloco, então é
 * preciso tirar o que é do layout do wp-admin (a div externa e o botão original).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function wp_kses_post( $h ) { return (string) $h; }
function add_action( $h, $c, $p = 10, $n = 1 ) {}
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://x/wp-json/' . $p; }

// Só as funções puras deste arquivo interessam aqui.
require_once __DIR__ . '/notices-include.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label" . ( '' !== $extra ? "  [$extra]" : '' ) . "\n"; }
}

echo "== Tipo do aviso ==\n";
check( 'notice-success → ok', 'ok' === sv_bridge_notice_type( '<div class="notice notice-success">x</div>' ) );
check( 'notice-error → error', 'error' === sv_bridge_notice_type( '<div class="notice notice-error">x</div>' ) );
check( 'notice-warning → warn', 'warn' === sv_bridge_notice_type( '<div class="notice notice-warning">x</div>' ) );
check( 'notice-info → info', 'info' === sv_bridge_notice_type( '<div class="notice notice-info">x</div>' ) );
check( 'sem classe → ok', 'ok' === sv_bridge_notice_type( '<p>oi</p>' ) );

echo "\n== Limpeza do HTML ==\n";
$litespeed = '<div class="notice notice-success is-dismissible"><p><span class="dashicons dashicons-yes"></span>Todos os caches foram limpos.</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dispensar este aviso.</span></button></div>';
$limpo = sv_bridge_notice_inner( $litespeed );
check( 'texto preservado', false !== strpos( $limpo, 'Todos os caches foram limpos.' ), $limpo );
check( 'botão original removido', false === strpos( $limpo, 'notice-dismiss' ), $limpo );
check( 'div externa removida', 0 !== strpos( $limpo, '<div' ), $limpo );
check( 'dashicons removido', false === strpos( $limpo, 'dashicons' ), $limpo );
check( 'parágrafo mantido', false !== strpos( $limpo, '<p>' ), $limpo );

$com_link = '<div class="notice notice-warning"><p>Aviso com <a href="/x">link</a>.</p><button class="notice-dismiss"></button></div>';
$limpo2 = sv_bridge_notice_inner( $com_link );
check( 'link preservado', false !== strpos( $limpo2, '<a href="/x">link</a>' ), $limpo2 );
check( 'botão sem conteúdo removido', false === strpos( $limpo2, 'notice-dismiss' ), $limpo2 );

$lista = '<div class="notice"><p>Dois problemas:</p><ul><li>um</li><li>dois</li></ul></div>';
check( 'lista preservada', false !== strpos( sv_bridge_notice_inner( $lista ), '<li>dois</li>' ) );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
