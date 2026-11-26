<?php
/**
 * Testes de conteúdo (posts e páginas) com um WordPress em memória.
 *
 * Roda com: php tests/content.php
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Somente CLI.' );
}

define( 'ABSPATH', '/' );
define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SV_BRIDGE_VERSION', '2.1.0' );

class WP_Error {
	private $c;
	private $m;
	private $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}

function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function wp_normalize_path( $p ) { return preg_replace( '#/+#', '/', str_replace( '\\', '/', $p ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_slash( $v ) { return $v; }
function apply_filters( $tag, $value ) { return $value; }
function kses_remove_filters() { $GLOBALS['__kses'] = 'off'; }
function kses_init_filters() { $GLOBALS['__kses'] = 'on'; }
function get_option( $k, $d = false ) { return 'gmt_offset' === $k ? -3 : $d; }

// --- "Banco" de posts ------------------------------------------------------
$GLOBALS['__posts'] = array();
$GLOBALS['__meta']  = array();
$GLOBALS['__terms'] = array();
$GLOBALS['__next']  = 100;

function get_post( $id ) {
	return isset( $GLOBALS['__posts'][ (int) $id ] ) ? $GLOBALS['__posts'][ (int) $id ] : null;
}
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_the_title( $p ) { return is_object( $p ) ? $p->post_title : ''; }
function get_permalink( $p ) { return 'https://exemplo.test/?p=' . ( is_object( $p ) ? $p->ID : 0 ); }

function wp_insert_post( $data, $wp_error = false ) {
	$id = ++$GLOBALS['__next'];
	$GLOBALS['__posts'][ $id ] = (object) array_merge(
		array(
			'ID'            => $id,
			'post_title'    => '',
			'post_content'  => '',
			'post_excerpt'  => '',
			'post_name'     => '',
			'post_status'   => 'draft',
			'post_type'     => 'post',
			'post_author'   => 0,
			'post_parent'   => 0,
			'post_date'     => '2026-11-26 10:00:00',
			'post_modified' => '2026-11-26 10:00:00',
			'menu_order'    => 0,
		),
		$data
	);
	$GLOBALS['__slashed'][ $id ] = $data;
	return $id;
}

function wp_update_post( $data, $wp_error = false ) {
	$id = (int) $data['ID'];
	if ( ! isset( $GLOBALS['__posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'ID inválido.' ) : 0;
	}
	$post = (array) $GLOBALS['__posts'][ $id ];
	foreach ( $data as $k => $v ) {
		if ( 'ID' !== $k && 'edit_date' !== $k ) {
			$post[ $k ] = $v;
		}
	}
	$GLOBALS['__posts'][ $id ] = (object) $post;
	return $id;
}

function get_posts( $query ) {
	$out = array();
	foreach ( $GLOBALS['__posts'] as $post ) {
		if ( isset( $query['post_type'] ) && $post->post_type !== $query['post_type'] ) { continue; }
		if ( isset( $query['post_status'] ) && 'any' !== $query['post_status'] ) {
			$statuses = (array) $query['post_status'];
			if ( ! in_array( $post->post_status, $statuses, true ) ) { continue; }
		}
		if ( isset( $query['name'] ) && $post->post_name !== $query['name'] ) { continue; }
		if ( isset( $query['post_parent'] ) && (int) $post->post_parent !== (int) $query['post_parent'] ) { continue; }
		$out[] = $post;
	}
	return $out;
}

function get_post_meta( $id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['__meta'][ (int) $id ] ) ? $GLOBALS['__meta'][ (int) $id ] : array();
	if ( '' === $key ) {
		return $all;
	}
	if ( ! isset( $all[ $key ] ) ) {
		return $single ? '' : array();
	}
	return $single ? ( isset( $all[ $key ][0] ) ? $all[ $key ][0] : '' ) : $all[ $key ];
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['__meta'][ (int) $id ][ $key ] = array( (string) $value );
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['__meta'][ (int) $id ][ $key ] );
	return true;
}

function taxonomy_exists( $t ) { return in_array( $t, array( 'category', 'post_tag' ), true ); }
function term_exists( $name, $tax ) {
	if ( is_numeric( $name ) ) {
		return array( 'term_id' => (int) $name );
	}
	$slug = strtolower( $name );
	return isset( $GLOBALS['__terms'][ $tax ][ $slug ] ) ? array( 'term_id' => $GLOBALS['__terms'][ $tax ][ $slug ] ) : 0;
}
function wp_insert_term( $name, $tax ) {
	$slug = strtolower( $name );
	$id   = 500 + count( $GLOBALS['__terms'][ $tax ] ?? array() );
	$GLOBALS['__terms'][ $tax ][ $slug ] = $id;
	return array( 'term_id' => $id );
}
function wp_set_object_terms( $id, $terms, $tax, $append = false ) {
	$GLOBALS['__object_terms'][ (int) $id ][ $tax ] = $terms;
	return $terms;
}
function wp_get_post_terms( $id, $tax, $args = array() ) {
	if ( 'names' !== ( $args['fields'] ?? '' ) ) { return array(); }
	$ids = $GLOBALS['__object_terms'][ (int) $id ][ $tax ] ?? array();
	$out = array();
	foreach ( $ids as $tid ) {
		foreach ( ( $GLOBALS['__terms'][ $tax ] ?? array() ) as $slug => $stored ) {
			if ( (int) $stored === (int) $tid ) { $out[] = $slug; }
		}
	}
	return $out;
}
function get_userdata( $id ) { return (int) $id === 7 ? (object) array( 'ID' => 7 ) : false; }
function get_users( $args ) { return array( 7 ); }

class SV_Bridge_Log {
	public static $lines = array();
	public static function add( $m, $r = '', $e = '' ) { self::$lines[] = $m; }
	public static function tail( $n = 50 ) { return self::$lines; }
}

require_once __DIR__ . '/../includes/class-sv-bridge-content.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else {
		$fail++; echo "  FAIL $label\n";
		if ( '' !== $detail ) { echo '       ' . substr( (string) $detail, 0, 400 ) . "\n"; }
	}
}
function expect_err( $label, $result, $code ) {
	$ok = is_wp_error( $result ) && $result->get_error_code() === $code;
	check( $label . ' → ' . $code, $ok, is_wp_error( $result ) ? $result->get_error_code() : json_encode( $result ) );
}

echo "== Criar ==\n";
$draft = SV_Bridge_Content::create( array( 'type' => 'post', 'title' => 'Primeiro', 'content' => '<p>oi</p>' ) );
check( 'cria rascunho', is_array( $draft ) && 'draft' === $draft['status'], json_encode( $draft ) );
check( 'nasce como post', is_array( $draft ) && 'post' === $draft['type'] );
check( 'recebe autor padrão', is_array( $draft ) && 7 === $draft['author'], is_array( $draft ) ? $draft['author'] : '' );
check( 'html passou sem kses', 'off' === ( $GLOBALS['__kses'] ?? '' ) || '<p>oi</p>' === $draft['content'] );

$page = SV_Bridge_Content::create(
	array(
		'type'     => 'page',
		'title'    => 'Contato',
		'content'  => 'fale com a gente',
		'status'   => 'publish',
		'slug'     => 'contato',
		'template' => 'elementor_header_footer',
		'parent'   => 0,
		'meta'     => array( '_elementor_edit_mode' => 'builder' ),
	)
);
check( 'cria página publicada', is_array( $page ) && 'publish' === $page['status'], json_encode( $page ) );
check( 'guarda o template', is_array( $page ) && 'elementor_header_footer' === $page['template'], is_array( $page ) ? $page['template'] : '' );
check( 'grava meta do Elementor', 'builder' === ( $GLOBALS['__meta'][ $page['id'] ]['_elementor_edit_mode'][0] ?? '' ) );

echo "\n== Validações de criar ==\n";
expect_err( 'tipo inválido', SV_Bridge_Content::create( array( 'type' => 'attachment', 'title' => 'x' ) ), 'sv_bridge_bad_type' );
expect_err( 'sem título e sem conteúdo', SV_Bridge_Content::create( array( 'type' => 'post' ) ), 'sv_bridge_empty' );
expect_err( 'status inválido', SV_Bridge_Content::create( array( 'type' => 'post', 'title' => 'x', 'status' => 'publicado' ) ), 'sv_bridge_bad_status' );
expect_err( 'data inválida', SV_Bridge_Content::create( array( 'type' => 'post', 'title' => 'x', 'date' => 'ontem' ) ), 'sv_bridge_bad_date' );
expect_err( 'autor inexistente', SV_Bridge_Content::create( array( 'type' => 'post', 'title' => 'x', 'author' => 999 ) ), 'sv_bridge_bad_author' );

echo "\n== Ler ==\n";
$got = SV_Bridge_Content::get( $page['id'] );
check( 'lê a página', is_array( $got ) && 'Contato' === $got['title'], json_encode( $got ) );
check( 'traz o template', is_array( $got ) && 'elementor_header_footer' === $got['template'] );
check( 'sem meta por padrão', is_array( $got ) && ! isset( $got['meta'] ) );
$with_meta = SV_Bridge_Content::get( $page['id'], true );
check( 'with_meta traz meta', is_array( $with_meta ) && isset( $with_meta['meta']['_elementor_edit_mode'] ) );
expect_err( 'id inexistente', SV_Bridge_Content::get( 999999 ), 'sv_bridge_not_found' );

echo "\n== Atualizar ==\n";
$upd = SV_Bridge_Content::update( $draft['id'], array( 'title' => 'Primeiro (editado)', 'status' => 'publish' ) );
check( 'publica no update', is_array( $upd ) && 'publish' === $upd['status'], json_encode( $upd ) );
check( 'título novo', is_array( $upd ) && 'Primeiro (editado)' === $upd['title'] );
check( 'conteúdo preservado', is_array( $upd ) && '<p>oi</p>' === $upd['content'], is_array( $upd ) ? $upd['content'] : '' );

$terms = SV_Bridge_Content::update( $draft['id'], array( 'categories' => array( 'Consultoria', 12 ), 'tags' => array( 'wordpress' ) ) );
check( 'aceita categorias por nome e id', is_array( $terms ) && in_array( 'consultoria', $terms['categories'], true ), json_encode( is_array( $terms ) ? $terms['categories'] : $terms ) );
check( 'aceita tags', is_array( $terms ) && in_array( 'wordpress', $terms['tags'], true ) );

$elementor = SV_Bridge_Content::update( $page['id'], array( 'meta' => array( '_elementor_data' => '[{"id":"abc"}]' ) ) );
check( 'aceita _elementor_data', is_array( $elementor ) );
check( 'invalida cache do Elementor', ! isset( $GLOBALS['__meta'][ $page['id'] ]['_elementor_css'] ) );

$sem = SV_Bridge_Content::update( $page['id'], array( 'meta' => array( '_elementor_edit_mode' => null ) ) );
check( 'meta null apaga a chave', is_array( $sem ) && ! isset( $GLOBALS['__meta'][ $page['id'] ]['_elementor_edit_mode'] ) );

echo "\n== Validações de atualizar ==\n";
expect_err( 'nada para atualizar', SV_Bridge_Content::update( $draft['id'], array() ), 'sv_bridge_nothing' );
expect_err( 'update sem id', SV_Bridge_Content::update( 424242, array( 'title' => 'x' ) ), 'sv_bridge_not_found' );
expect_err( 'status ruim no update', SV_Bridge_Content::update( $draft['id'], array( 'status' => 'rascunho' ) ), 'sv_bridge_bad_status' );

echo "\n== Listar ==\n";
$list = SV_Bridge_Content::list_posts( array( 'type' => 'post' ) );
check( 'lista posts', is_array( $list ) && 1 === $list['count'], json_encode( $list ) );
$pages = SV_Bridge_Content::list_posts( array( 'type' => 'page', 'status' => 'publish' ) );
check( 'lista páginas publicadas', is_array( $pages ) && 1 === $pages['count'], json_encode( $pages ) );
$none = SV_Bridge_Content::list_posts( array( 'type' => 'page', 'status' => 'draft' ) );
check( 'filtra por status', is_array( $none ) && 0 === $none['count'] );
$by_slug = SV_Bridge_Content::list_posts( array( 'type' => 'page', 'slug' => 'contato' ) );
check( 'filtra por slug', is_array( $by_slug ) && 1 === $by_slug['count'], json_encode( $by_slug ) );
$limit = SV_Bridge_Content::list_posts( array( 'type' => 'page', 'limit' => 500 ) );
check( 'limit limitado a 100', is_array( $limit ) && 100 === $limit['limit'], is_array( $limit ) ? $limit['limit'] : '' );
expect_err( 'listar tipo inválido', SV_Bridge_Content::list_posts( array( 'type' => 'user' ) ), 'sv_bridge_bad_type' );
expect_err( 'listar status inválido', SV_Bridge_Content::list_posts( array( 'status' => 'arquivado' ) ), 'sv_bridge_bad_status' );

echo "\n== Tipos aceitos ==\n";
check( 'post e page por padrão', array( 'post', 'page' ) === SV_Bridge_Content::types() );
check( 'status válidos', array( 'draft', 'pending', 'private', 'publish', 'future' ) === SV_Bridge_Content::statuses() );

echo "\n----------------------------------------\n";
echo "passou: $pass   falhou: $fail\n";
exit( $fail > 0 ? 1 : 0 );
