<?php
/**
 * Tipo do aviso, a partir das classes do wp-admin.
 *
 * @param string $html HTML do aviso.
 * @return string ok|error|warn|info
 */
function sv_bridge_notice_type( $html ) {
	if ( false !== strpos( $html, 'notice-error' ) || false !== strpos( $html, 'notice-danger' ) ) {
		return 'error';
	}

	if ( false !== strpos( $html, 'notice-warning' ) || false !== strpos( $html, 'notice-warn' ) ) {
		return 'warn';
	}

	if ( false !== strpos( $html, 'notice-info' ) ) {
		return 'info';
	}

	return 'ok';
}

/**
 * Tira o que é do wp-admin de dentro de um aviso capturado: a div externa
 * (o nosso bloco já é o cartão) e o botão de dispensar original (temos o nosso).
 *
 * @param string $html HTML do aviso.
 * @return string Conteúdo interno.
 */
function sv_bridge_notice_inner( $html ) {
	$html = (string) $html;

	// Botão "dispensar" do wp-admin, com ou sem o texto escondido dentro.
	$html = preg_replace( '#<button[^>]*notice-dismiss[^>]*>.*?</button>#is', '', $html );
	$html = preg_replace( '#<button[^>]*notice-dismiss[^>]*/?>#is', '', $html );

	// Ícone de "dashicons" que só faz sentido no layout do wp-admin.
	$html = preg_replace( '#<span[^>]*class="[^"]*dashicons[^"]*"[^>]*>\s*</span>#is', '', $html );

	// A div externa do aviso.
	$html = preg_replace( '#^\s*<div[^>]*class="[^"]*\bnotice\b[^"]*"[^>]*>#is', '', $html );
	$html = preg_replace( '#</div>\s*$#is', '', $html );

	return trim( $html );
}

