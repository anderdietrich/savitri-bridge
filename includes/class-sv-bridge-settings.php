<?php
/**
 * Tela de configuração: chaves, conexão, atualização pelo GitHub e log.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra a página em Configurações.
 */
function sv_bridge_settings_menu() {
	$parent = sv_bridge_parent_slug();

	sv_bridge_register_parent();

	$hook = add_submenu_page(
		$parent,
		__( 'Savitri Bridge', 'savitri-file-bridge' ),
		__( 'Bridge', 'savitri-file-bridge' ),
		'manage_options',
		'sv-bridge',
		'sv_bridge_render_settings'
	);

	$GLOBALS['sv_bridge_page_hook'] = $hook;
}
add_action( 'admin_menu', 'sv_bridge_settings_menu' );

/**
 * Slug do menu pai, compartilhado com Leads e Access.
 *
 * @return string
 */
function sv_bridge_parent_slug() {
	return 'savitri';
}

/**
 * Registra o menu pai se ainda não existe.
 *
 * @return void
 */
function sv_bridge_register_parent() {
	$slug = sv_bridge_parent_slug();

	if ( ! empty( $GLOBALS['sv_savitri_parent'] ) ) {
		return;
	}

	global $menu;

	if ( is_array( $menu ) ) {
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && $slug === (string) $item[2] ) {
				$GLOBALS['sv_savitri_parent'] = true;

				return;
			}
		}
	}

	add_menu_page(
		__( 'Savitri', 'savitri-file-bridge' ),
		__( 'Savitri', 'savitri-file-bridge' ),
		'manage_options',
		$slug,
		'sv_bridge_render_settings',
		'dashicons-superhero',
		58
	);

	$GLOBALS['sv_savitri_parent'] = true;
}

/**
 * Carrega o CSS do painel só nesta tela.
 *
 * @param string $hook Tela atual.
 * @return void
 */
function sv_bridge_admin_assets( $hook ) {
	// Com o menu único o hook passa a ser savitri_page_sv-bridge; a tela antiga
	// (Configurações → Savitri Bridge) continua aceita para não quebrar link.
	if ( ! in_array( $hook, array( 'settings_page_sv-bridge', 'savitri_page_sv-bridge' ), true ) ) {
		return;
	}

	wp_enqueue_style(
		'sv-bridge-admin',
		plugins_url( 'assets/css/admin.css', SV_BRIDGE_FILE ),
		array(),
		SV_BRIDGE_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'sv_bridge_admin_assets' );

/**
 * Registra os campos.
 */
function sv_bridge_register_settings() {
	register_setting(
		'sv_bridge',
		'sv_bridge_readonly',
		array(
			'type'              => 'boolean',
			'sanitize_callback' => 'sv_bridge_sanitize_bool',
			'default'           => false,
		)
	);

}
add_action( 'admin_init', 'sv_bridge_register_settings' );

/**
 * Sanitiza booleano.
 *
 * @param mixed $value Valor.
 * @return bool
 */
function sv_bridge_sanitize_bool( $value ) {
	return (bool) $value;
}

/**
 * Captura os avisos de outros plugins nesta tela.
 *
 * O wp-admin imprime os avisos de "admin_notices" no topo da área de conteúdo,
 * antes do nosso HTML — e eles acabam aparecendo no meio do nosso cabeçalho, com
 * o layout do wp-admin e não com o do painel. Aqui os avisos são executados na
 * hora e guardados para serem impressos no nosso próprio bloco.
 *
 * Roda em "current_screen", que é antes de o wp-admin imprimir qualquer aviso.
 *
 * @return void
 */
function sv_bridge_capture_admin_notices() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || ! in_array( (string) $screen->id, array( 'settings_page_sv-bridge', 'savitri_page_sv-bridge' ), true ) ) {
		return;
	}

	// "Configurações salvas." do options.php vira um aviso nosso.
	if ( isset( $_GET['settings-updated'] ) ) {
		$GLOBALS['sv_bridge_settings_saved'] = true;
		unset( $_GET['settings-updated'] );
	}

	global $wp_filter;

	$captured = array();

	foreach ( array( 'admin_notices', 'all_admin_notices' ) as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			continue;
		}

		$callbacks = $wp_filter[ $hook ]->callbacks;
		ksort( $callbacks );

		foreach ( $callbacks as $list ) {
			foreach ( $list as $cb ) {
				if ( ! isset( $cb['function'] ) || ! is_callable( $cb['function'] ) ) {
					continue;
				}

				ob_start();
				call_user_func_array( $cb['function'], array() );
				$html = trim( (string) ob_get_clean() );

				if ( '' !== $html ) {
					$captured[] = $html;
				}
			}
		}

		remove_all_actions( $hook );
	}

	$GLOBALS['sv_bridge_captured_notices'] = $captured;
}
add_action( 'current_screen', 'sv_bridge_capture_admin_notices' );

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

/**
 * Botão de fechar dos avisos.
 *
 * @return void
 */
function sv_bridge_close_button() {
	printf(
		'<button type="button" class="sv-alert__close" aria-label="%s" title="%s"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>',
		esc_attr__( 'Fechar aviso', 'savitri-file-bridge' ),
		esc_attr__( 'Fechar aviso', 'savitri-file-bridge' )
	);
}

/**
 * Imprime um aviso no layout do painel.
 *
 * @param string $type ok|error|warn|info.
 * @param string $body Texto simples (escapado) ou HTML já tratado.
 * @param bool   $html O corpo é HTML?
 * @return void
 */
function sv_bridge_render_alert( $type, $body, $html = false ) {
	printf( '<div class="sv-alert sv-alert--%s">', esc_attr( $type ) );

	if ( $html ) {
		echo '<div class="sv-alert__body">' . wp_kses_post( $body ) . '</div>';
	} else {
		echo '<p>' . esc_html( $body ) . '</p>';
	}

	sv_bridge_close_button();
	echo '</div>';
}

/**
 * URL da tela de configuração.
 *
 * @return string
 */
function sv_bridge_settings_url() {
	return admin_url( 'admin.php?page=sv-bridge' );
}

/**
 * Volta para a tela com um aviso.
 *
 * @param string $notice Código do aviso.
 * @param string $name   Nome da chave (para o aviso).
 * @return void
 */
function sv_bridge_back( $notice, $name = '' ) {
	$args = array( 'sv_bridge_notice' => $notice );

	if ( '' !== $name ) {
		$args['sv_bridge_name'] = rawurlencode( $name );
	}

	wp_safe_redirect( add_query_arg( $args, sv_bridge_settings_url() ) );
	exit;
}

/**
 * Ação: criar uma chave nova com nome.
 */
function sv_bridge_admin_create_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'savitri-file-bridge' ) );
	}

	check_admin_referer( 'sv_bridge_create' );

	if ( 'constant' === sv_bridge_key_source() ) {
		sv_bridge_back( 'constant' );
	}

	$name = isset( $_POST['sv_bridge_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sv_bridge_name'] ) ) : '';

	if ( '' === $name ) {
		sv_bridge_back( 'no_name' );
	}

	$entry = sv_bridge_add_key( $name );

	SV_Bridge_Log::add( sprintf( 'chave criada: %s', $entry['name'] ), 'admin' );

	sv_bridge_back( 'created', $entry['name'] );
}
add_action( 'admin_post_sv_bridge_create', 'sv_bridge_admin_create_key' );

/**
 * Ação: renomear uma chave.
 */
function sv_bridge_admin_rename_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'savitri-file-bridge' ) );
	}

	check_admin_referer( 'sv_bridge_rename' );

	$id   = isset( $_POST['sv_bridge_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sv_bridge_id'] ) ) : '';
	$name = isset( $_POST['sv_bridge_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sv_bridge_name'] ) ) : '';

	if ( '' === $id || '' === $name ) {
		sv_bridge_back( 'no_name' );
	}

	if ( sv_bridge_rename_key( $id, $name ) ) {
		SV_Bridge_Log::add( sprintf( 'chave renomeada: %s', $name ), 'admin' );
		sv_bridge_back( 'renamed', $name );
	}

	sv_bridge_back( 'not_found' );
}
add_action( 'admin_post_sv_bridge_rename', 'sv_bridge_admin_rename_key' );

/**
 * Ação: revogar (apagar) uma chave.
 */
function sv_bridge_admin_revoke_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'savitri-file-bridge' ) );
	}

	check_admin_referer( 'sv_bridge_revoke' );

	$id   = isset( $_POST['sv_bridge_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sv_bridge_id'] ) ) : '';
	$name = '';

	foreach ( sv_bridge_keys() as $entry ) {
		if ( $entry['id'] === $id ) {
			$name = $entry['name'];
		}
	}

	if ( '' === $id || ! sv_bridge_revoke_key( $id ) ) {
		sv_bridge_back( 'not_found' );
	}

	SV_Bridge_Log::add( sprintf( 'chave revogada: %s', '' !== $name ? $name : $id ), 'admin' );

	sv_bridge_back( 'revoked', $name );
}
add_action( 'admin_post_sv_bridge_revoke', 'sv_bridge_admin_revoke_key' );

/**
 * Ação: liberar o bloqueio de tentativas do próprio IP.
 */
function sv_bridge_admin_unlock() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'savitri-file-bridge' ) );
	}

	check_admin_referer( 'sv_bridge_unlock' );

	SV_Bridge_Auth::unlock_current_ip();

	SV_Bridge_Log::add( 'bloqueio de tentativas liberado', 'admin' );

	sv_bridge_back( 'unlocked' );
}
add_action( 'admin_post_sv_bridge_unlock', 'sv_bridge_admin_unlock' );

/**
 * Ação: consultar o GitHub agora, sem esperar o cache.
 */
function sv_bridge_admin_check_updates() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'savitri-file-bridge' ) );
	}

	check_admin_referer( 'sv_bridge_check' );

	$status = sv_bridge_update_status( true );

	if ( ! $status['ok'] ) {
		set_transient( 'sv_bridge_update_error', $status['error'], 5 * MINUTE_IN_SECONDS );
		sv_bridge_back( 'update_error' );
	}

	if ( $status['newer'] ) {
		// Faz o WordPress recalcular o aviso de atualização com a versão nova.
		delete_site_transient( 'update_plugins' );
		sv_bridge_back( 'update_found' );
	}

	sv_bridge_back( 'update_current' );
}
add_action( 'admin_post_sv_bridge_check', 'sv_bridge_admin_check_updates' );

/**
 * Desenha a tela.
 */
function sv_bridge_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$source   = sv_bridge_key_source();
	$keys     = sv_bridge_keys();
	$enabled  = '' !== $source;
	$readonly = (bool) get_option( 'sv_bridge_readonly', false );
	$mcp_url  = rest_url( 'sv-bridge/v1/mcp' );
	$notice   = isset( $_GET['sv_bridge_notice'] ) ? sanitize_key( wp_unslash( $_GET['sv_bridge_notice'] ) ) : '';
	$name     = isset( $_GET['sv_bridge_name'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['sv_bridge_name'] ) ) ) : '';
	$log      = SV_Bridge_Log::tail( 40 );
	$update   = sv_bridge_update_status();
	$upd_err  = get_transient( 'sv_bridge_update_error' );
	?>
	<div class="wrap">
	<div class="sv-app">

		<header class="sv-hero">
			<div class="sv-hero__text">
				<p class="sv-hero__eyebrow"><?php esc_html_e( 'Configurações', 'savitri-file-bridge' ); ?></p>
				<h1><?php esc_html_e( 'Savitri Bridge', 'savitri-file-bridge' ); ?></h1>
				<p><?php esc_html_e( 'Conecta este site ao assistente por MCP: ler e editar arquivos de temas e plugins, publicar posts e páginas.', 'savitri-file-bridge' ); ?></p>
			</div>
			<div class="sv-hero__meta">
				<span class="sv-pill <?php echo $enabled ? 'sv-pill--on' : 'sv-pill--off'; ?>">
					<span class="sv-pill__dot"></span>
					<?php echo $enabled ? esc_html__( 'Ativo', 'savitri-file-bridge' ) : esc_html__( 'Desligado', 'savitri-file-bridge' ); ?>
				</span>
				<?php
				// Flag de atualização: entre o estado do canal e a versão instalada.
				if ( ! $update['ok'] ) {
					$sv_upd_class = 'sv-pill--unknown';
					$sv_upd_label = __( 'Sem verificação', 'savitri-file-bridge' );
					$sv_upd_title = $update['error'];
				} elseif ( $update['newer'] ) {
					$sv_upd_class = 'sv-pill--pending';
					$sv_upd_label = __( 'Atualização pendente', 'savitri-file-bridge' );
					$sv_upd_title = sprintf(
						/* translators: %s: versão publicada */
						__( 'Versão %s disponível no repositório.', 'savitri-file-bridge' ),
						$update['latest']
					);
				} else {
					$sv_upd_class = 'sv-pill--current';
					$sv_upd_label = __( 'Atualizado', 'savitri-file-bridge' );
					$sv_upd_title = __( 'Você está na versão mais recente publicada.', 'savitri-file-bridge' );
				}
				?>
				<span class="sv-pill <?php echo esc_attr( $sv_upd_class ); ?>" title="<?php echo esc_attr( $sv_upd_title ); ?>">
					<span class="sv-pill__dot"></span>
					<?php echo esc_html( $sv_upd_label ); ?>
				</span>
				<span class="sv-pill">
					<?php
					printf(
						/* translators: %s: versão instalada */
						esc_html__( 'v%s instalada', 'savitri-file-bridge' ),
						esc_html( SV_BRIDGE_VERSION )
					);
					?>
				</span>
				<?php if ( $update['ok'] && $update['newer'] ) : ?>
					<a class="sv-hero__update" href="<?php echo esc_url( sv_bridge_upgrade_url() ); ?>">
						<span class="sv-hero__update-icon" aria-hidden="true">&#8593;</span>
						<?php
						printf(
							/* translators: %s: versão publicada */
							esc_html__( 'Atualizar para %s', 'savitri-file-bridge' ),
							esc_html( $update['latest'] )
						);
						?>
					</a>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sv-hero__check">
					<input type="hidden" name="action" value="sv_bridge_check">
					<?php wp_nonce_field( 'sv_bridge_check' ); ?>
					<button type="submit" class="sv-icon-btn" title="<?php esc_attr_e( 'Verificar se há versão mais nova', 'savitri-file-bridge' ); ?>" aria-label="<?php esc_attr_e( 'Verificar se há versão mais nova', 'savitri-file-bridge' ); ?>">
						<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
					</button>
				</form>
			</div>
		</header>

		<div class="sv-extra-notices" id="sv-extra-notices" hidden></div>

		<?php
		// Avisos do painel, no mesmo layout. Todos fecham no X.
		if ( ! empty( $GLOBALS['sv_bridge_settings_saved'] ) ) {
			sv_bridge_render_alert( 'ok', __( 'Configurações salvas.', 'savitri-file-bridge' ) );
		}

		$sv_notices = array(
			'created'        => array( 'ok', sprintf( /* translators: %s: nome da chave */ __( 'Chave «%s» criada. Clique em Copiar na linha dela e cole no campo de token do conector.', 'savitri-file-bridge' ), $name ) ),
			'renamed'        => array( 'ok', sprintf( /* translators: %s: nome da chave */ __( 'Nome da chave atualizado para «%s».', 'savitri-file-bridge' ), $name ) ),
			'revoked'        => array( 'ok', '' !== $name
				? sprintf( /* translators: %s: nome da chave */ __( 'Chave «%s» revogada. O acesso com ela está encerrado e ela saiu da lista.', 'savitri-file-bridge' ), $name )
				: __( 'Chave revogada. O acesso com ela está encerrado e ela saiu da lista.', 'savitri-file-bridge' ) ),
			'unlocked'       => array( 'ok', __( 'Bloqueio de tentativas liberado para o seu IP. O cache das rotas do canal também foi descartado.', 'savitri-file-bridge' ) ),
			'update_found'   => array( 'ok', __( 'Nova versão encontrada. Atualize seu Plugin!', 'savitri-file-bridge' ) ),
			'update_current' => array( 'ok', __( 'Você está na versão mais recente publicada.', 'savitri-file-bridge' ) ),
			'update_error'   => array( 'error', $upd_err ? (string) $upd_err : __( 'Não foi possível consultar o GitHub.', 'savitri-file-bridge' ) ),
			'no_name'        => array( 'error', __( 'Dê um nome à chave: ele identifica a ferramenta conectada.', 'savitri-file-bridge' ) ),
			'not_found'      => array( 'error', __( 'Chave não encontrada. A lista pode ter mudado.', 'savitri-file-bridge' ) ),
			'constant'       => array( 'warn', __( 'A chave está definida no wp-config.php. Para gerenciá-la por aqui, remova a constante SV_FILE_BRIDGE_KEY.', 'savitri-file-bridge' ) ),
		);

		if ( isset( $sv_notices[ $notice ] ) ) {
			sv_bridge_render_alert( $sv_notices[ $notice ][0], $sv_notices[ $notice ][1] );
		}

		// Avisos de outros plugins (ex.: "Todos os caches foram limpos."), trazidos para cá.
		foreach ( (array) ( $GLOBALS['sv_bridge_captured_notices'] ?? array() ) as $sv_other ) {
			sv_bridge_render_alert( sv_bridge_notice_type( $sv_other ), sv_bridge_notice_inner( $sv_other ), true );
		}
		?>

		<div class="sv-grid">

			<main>
				<section class="sv-card">
					<div class="sv-card__head">
						<div>
							<h2 class="sv-card__title"><?php esc_html_e( 'Chaves de conexão', 'savitri-file-bridge' ); ?></h2>
							<p class="sv-card__hint">
								<?php
								printf(
									esc_html( _n( '%s chave emitida', '%s chaves emitidas', count( $keys ), 'savitri-file-bridge' ) ),
									esc_html( number_format_i18n( count( $keys ) ) )
								);
								?>
								— <?php esc_html_e( 'uma por ferramenta conectada', 'savitri-file-bridge' ); ?>
							</p>
						</div>
					</div>

					<div class="sv-card__body">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sv-create">
							<input type="hidden" name="action" value="sv_bridge_create">
							<?php wp_nonce_field( 'sv_bridge_create' ); ?>
							<div class="sv-field">
								<label for="sv-bridge-new-name"><?php esc_html_e( 'Nome da ferramenta conectada', 'savitri-file-bridge' ); ?></label>
								<input type="text" id="sv-bridge-new-name" name="sv_bridge_name" required maxlength="80" placeholder="<?php esc_attr_e( 'Ex.: Ferramenta', 'savitri-file-bridge' ); ?>" <?php disabled( 'constant' === $source ); ?>>
							</div>
							<?php submit_button( __( 'Criar chave', 'savitri-file-bridge' ), 'primary', 'submit', false, 'constant' === $source ? array( 'disabled' => 'disabled' ) : array() ); ?>
						</form>
					</div>

					<?php if ( ! $keys ) : ?>
						<div class="sv-empty">
							<strong><?php esc_html_e( 'Nenhuma chave criada', 'savitri-file-bridge' ); ?></strong>
							<?php esc_html_e( 'Crie a primeira acima para ligar o canal.', 'savitri-file-bridge' ); ?>
						</div>
					<?php else : ?>
						<table class="sv-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Ferramenta', 'savitri-file-bridge' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Chave', 'savitri-file-bridge' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Último uso', 'savitri-file-bridge' ); ?></th>
									<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Ações', 'savitri-file-bridge' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $keys as $entry ) : ?>
								<tr>
									<td>
										<span class="sv-name"><?php echo esc_html( '' !== $entry['name'] ? $entry['name'] : __( 'Sem nome', 'savitri-file-bridge' ) ); ?></span>
										<span class="sv-sub">
											<?php
											printf(
												/* translators: %s: data de criação */
												esc_html__( 'criada em %s', 'savitri-file-bridge' ),
												esc_html( '' !== $entry['created'] ? $entry['created'] : '—' )
											);
											?>
										</span>
										<details class="sv-edit">
											<summary><?php esc_html_e( 'Editar nome', 'savitri-file-bridge' ); ?></summary>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="sv_bridge_rename">
												<input type="hidden" name="sv_bridge_id" value="<?php echo esc_attr( $entry['id'] ); ?>">
												<?php wp_nonce_field( 'sv_bridge_rename' ); ?>
												<input type="text" name="sv_bridge_name" value="<?php echo esc_attr( $entry['name'] ); ?>" required maxlength="80" aria-label="<?php esc_attr_e( 'Nome da chave', 'savitri-file-bridge' ); ?>">
												<button type="submit" class="button sv-btn--xs"><?php esc_html_e( 'Salvar', 'savitri-file-bridge' ); ?></button>
											</form>
										</details>
									</td>
									<td>
										<div class="sv-keyline">
											<input type="password" readonly value="<?php echo esc_attr( $entry['key'] ); ?>" id="sv-bridge-key-<?php echo esc_attr( $entry['id'] ); ?>" autocomplete="off" spellcheck="false" aria-label="<?php esc_attr_e( 'Chave de conexão', 'savitri-file-bridge' ); ?>">
											<button type="button" class="button sv-btn--xs sv-bridge-toggle" data-target="sv-bridge-key-<?php echo esc_attr( $entry['id'] ); ?>" aria-pressed="false"><?php esc_html_e( 'Mostrar', 'savitri-file-bridge' ); ?></button>
											<button type="button" class="button sv-btn--xs sv-bridge-copy" data-target="sv-bridge-key-<?php echo esc_attr( $entry['id'] ); ?>"><?php esc_html_e( 'Copiar', 'savitri-file-bridge' ); ?></button>
										</div>
									</td>
									<td>
										<?php if ( '' !== $entry['last_used'] ) : ?>
											<span class="sv-badge sv-badge--used"><?php esc_html_e( 'em uso', 'savitri-file-bridge' ); ?></span>
											<span class="sv-sub"><?php echo esc_html( $entry['last_used'] ); ?></span>
											<?php if ( '' !== $entry['last_ip'] ) : ?>
												<span class="sv-sub"><?php echo esc_html( $entry['last_ip'] ); ?></span>
											<?php endif; ?>
										<?php else : ?>
											<span class="sv-badge sv-badge--never"><?php esc_html_e( 'nunca usada', 'savitri-file-bridge' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<div class="sv-actions">
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: nome da chave */ __( 'Revogar a chave «%s»? A ferramenta conectada perde o acesso imediatamente e a chave sai da lista. Não dá para desfazer.', 'savitri-file-bridge' ), '' !== $entry['name'] ? $entry['name'] : __( 'sem nome', 'savitri-file-bridge' ) ) ); ?>');">
												<input type="hidden" name="action" value="sv_bridge_revoke">
												<input type="hidden" name="sv_bridge_id" value="<?php echo esc_attr( $entry['id'] ); ?>">
												<?php wp_nonce_field( 'sv_bridge_revoke' ); ?>
												<button type="submit" class="button sv-btn--xs sv-btn--danger"><?php esc_html_e( 'Revogar', 'savitri-file-bridge' ); ?></button>
											</form>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="sv-footnote" style="padding:0 20px 18px;">
							<?php esc_html_e( 'Quem tiver uma chave poderá escrever código no seu site, trate-a como cuidado.', 'savitri-file-bridge' ); ?>
						</p>
					<?php endif; ?>
				</section>

				<section class="sv-card">
					<div class="sv-card__head">
						<div>
							<h2 class="sv-card__title"><?php esc_html_e( 'Permissão do canal', 'savitri-file-bridge' ); ?></h2>
							<p class="sv-card__hint"><?php esc_html_e( 'O que as chaves acima podem fazer no site.', 'savitri-file-bridge' ); ?></p>
						</div>
					</div>
					<div class="sv-card__body">
						<form method="post" action="options.php" id="sv-bridge-perms">
							<?php settings_fields( 'sv_bridge' ); ?>
							<input type="hidden" name="sv_bridge_readonly" value="0">
							<label class="sv-switch">
								<input type="checkbox" name="sv_bridge_readonly" value="1" <?php checked( $readonly ); ?> data-autosave>
								<span class="sv-switch__text">
									<strong><?php esc_html_e( 'Modo somente leitura', 'savitri-file-bridge' ); ?></strong>
									<?php esc_html_e( 'Permite apenas listar e ler. Bloqueia gravar, apagar, extrair, ativar tema e plugin.', 'savitri-file-bridge' ); ?>
								</span>
							</label>
						</form>
					</div>
				</section>

				<section class="sv-card">
					<div class="sv-card__head">
						<div>
							<h2 class="sv-card__title"><?php esc_html_e( 'Últimas operações', 'savitri-file-bridge' ); ?></h2>
							<p class="sv-card__hint"><?php esc_html_e( 'O que o conector e o painel fizeram recentemente.', 'savitri-file-bridge' ); ?></p>
						</div>
					</div>
					<div class="sv-card__body">
						<?php if ( ! $log ) : ?>
							<p class="sv-footnote" style="margin:0;"><?php esc_html_e( 'Nenhuma operação registrada.', 'savitri-file-bridge' ); ?></p>
						<?php else : ?>
							<details class="sv-log">
								<summary>
									<span class="sv-log__show"><?php printf( esc_html( _n( 'Mostrar a última operação', 'Mostrar as %s últimas operações', count( $log ), 'savitri-file-bridge' ) ), esc_html( number_format_i18n( count( $log ) ) ) ); ?></span>
									<span class="sv-log__hide"><?php esc_html_e( 'Ocultar as operações', 'savitri-file-bridge' ); ?></span>
								</summary>
								<pre class="sv-logbox"><?php echo esc_html( implode( "\n", $log ) ); ?></pre>
							</details>
						<?php endif; ?>
					</div>
				</section>

			</main>

			<aside>
				<section class="sv-card">
					<div class="sv-card__head">
						<h2 class="sv-card__title"><?php esc_html_e( 'Conectar no assistente', 'savitri-file-bridge' ); ?></h2>
					</div>
					<div class="sv-card__body">
						<ol class="sv-steps" style="margin-bottom:14px;">
							<li><?php esc_html_e( 'Adicione um conector via URL.', 'savitri-file-bridge' ); ?></li>
							<li><?php esc_html_e( 'Tipo HTTP, autenticação Token.', 'savitri-file-bridge' ); ?></li>
							<li><?php esc_html_e( 'Cole a URL e a chave abaixo.', 'savitri-file-bridge' ); ?></li>
						</ol>
						<dl class="sv-info">
							<div class="sv-info__row" style="grid-template-columns:1fr;">
								<dt><?php esc_html_e( 'URL do servidor', 'savitri-file-bridge' ); ?></dt>
								<dd><span class="sv-code"><?php echo esc_html( $mcp_url ); ?></span></dd>
							</div>
							<div class="sv-info__row" style="grid-template-columns:1fr;">
								<dt><?php esc_html_e( 'Token', 'savitri-file-bridge' ); ?></dt>
								<dd><?php esc_html_e( 'a chave da tabela ao lado', 'savitri-file-bridge' ); ?></dd>
							</div>
						</dl>
						<?php if ( 0 !== strpos( $mcp_url, 'https://' ) ) : ?>
							<div class="sv-alert sv-alert--error" style="margin-top:14px;"><p><?php esc_html_e( 'A URL não está em HTTPS. O token trafega no cabeçalho e só é seguro com HTTPS.', 'savitri-file-bridge' ); ?></p></div>
						<?php endif; ?>
					</div>
				</section>

				<section class="sv-card">
					<div class="sv-card__head">
						<h2 class="sv-card__title"><?php esc_html_e( 'Acesso', 'savitri-file-bridge' ); ?></h2>
					</div>
					<div class="sv-card__body">
						<dl class="sv-info">
							<div class="sv-info__row">
								<dt><?php esc_html_e( 'Origem', 'savitri-file-bridge' ); ?></dt>
								<dd>
									<?php
									if ( 'constant' === $source ) {
										esc_html_e( 'Constante no wp-config.php', 'savitri-file-bridge' );
									} elseif ( 'option' === $source ) {
										esc_html_e( 'Chaves desta tela', 'savitri-file-bridge' );
									} else {
										esc_html_e( 'Nenhuma chave', 'savitri-file-bridge' );
									}
									?>
								</dd>
							</div>
							<div class="sv-info__row">
								<dt><?php esc_html_e( 'Seu IP', 'savitri-file-bridge' ); ?></dt>
								<dd><code><?php echo esc_html( SV_Bridge_Auth::current_ip() ); ?></code></dd>
							</div>
						</dl>
						<p class="sv-footnote" style="margin:10px 0 12px;"><?php esc_html_e( 'Cinco tentativas com chave errada bloqueiam o IP por 10 minutos.', 'savitri-file-bridge' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="sv_bridge_unlock">
							<?php wp_nonce_field( 'sv_bridge_unlock' ); ?>
							<button type="submit" class="button sv-btn--xs"><?php esc_html_e( 'Liberar meu bloqueio', 'savitri-file-bridge' ); ?></button>
						</form>
					</div>
				</section>

			</aside>
		</div>

		<script>
		( function () {
			var showLabel   = <?php echo wp_json_encode( __( 'Mostrar', 'savitri-file-bridge' ) ); ?>;
			var hideLabel   = <?php echo wp_json_encode( __( 'Ocultar', 'savitri-file-bridge' ) ); ?>;
			var copyLabel   = <?php echo wp_json_encode( __( 'Copiar', 'savitri-file-bridge' ) ); ?>;
			var copiedLabel = <?php echo wp_json_encode( __( 'Copiado!', 'savitri-file-bridge' ) ); ?>;
			var failLabel   = <?php echo wp_json_encode( __( 'Copie à mão', 'savitri-file-bridge' ) ); ?>;

			// Rede de segurança: avisos que outro plugin injetar depois (ou por JS)
			// não ficam no meio do cabeçalho — são movidos para o bloco de avisos.
			( function () {
				var alvo = document.getElementById( 'sv-extra-notices' );
				if ( ! alvo ) { return; }

				var tipos = [ 'error', 'warning', 'warn', 'info', 'success' ];

				var acharTipo = function ( el ) {
					for ( var i = 0; i < tipos.length; i++ ) {
						if ( el.classList.contains( 'notice-' + tipos[ i ] ) ) {
							var t = tipos[ i ];
							if ( 'warning' === t ) { t = 'warn'; }
							if ( 'success' === t ) { t = 'ok'; }
							return t;
						}
					}
					if ( el.classList.contains( 'updated' ) ) { return 'ok'; }
					if ( el.classList.contains( 'error' ) ) { return 'error'; }
					return 'info';
				};

				var mover = function () {
					var avisos = document.querySelectorAll( '.sv-app .notice, .sv-app .update-nag, #wpbody-content > .notice, #wpbody-content > .error, #wpbody-content > .updated' );

					Array.prototype.forEach.call( avisos, function ( el ) {
						if ( el.closest( '.sv-alert' ) || el.closest( '#sv-extra-notices' ) ) { return; }
						if ( el.dataset.svMovido ) { return; }
						el.dataset.svMovido = '1';

						var caixa = document.createElement( 'div' );
						caixa.className = 'sv-alert sv-alert--' + acharTipo( el );

						var corpo = document.createElement( 'div' );
						corpo.className = 'sv-alert__body';

						// Move o conteúdo, descartando o botão de dispensar do wp-admin.
						var copia = el.cloneNode( true );
						Array.prototype.forEach.call( copia.querySelectorAll( '.notice-dismiss, .dashicons' ), function ( lixo ) {
							lixo.parentNode.removeChild( lixo );
						} );

						while ( copia.firstChild ) {
							corpo.appendChild( copia.firstChild );
						}

						var fechar = document.createElement( 'button' );
						fechar.type = 'button';
						fechar.className = 'sv-alert__close';
						fechar.setAttribute( 'aria-label', <?php echo wp_json_encode( __( 'Fechar aviso', 'savitri-file-bridge' ) ); ?> );
						fechar.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
						fechar.addEventListener( 'click', function () { caixa.remove(); } );

						caixa.appendChild( corpo );
						caixa.appendChild( fechar );

						if ( el.parentNode ) { el.parentNode.removeChild( el ); }
						alvo.appendChild( caixa );
						alvo.hidden = false;
					} );
				};

				mover();
				// Plugins Reactivos injetam depois da carga: olha mais duas vezes.
				window.setTimeout( mover, 400 );
				window.setTimeout( mover, 1500 );
			}() );

			// Fecha o aviso no X (sem recarregar; a mensagem já foi lida).
			document.querySelectorAll( '.sv-alert__close' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var alert = btn.closest( '.sv-alert' );
					if ( alert ) { alert.remove(); }
				} );
			} );

			// O switch de permissão salva na hora: não há botão "Salvar".
			document.querySelectorAll( '[data-autosave]' ).forEach( function ( box ) {
				box.addEventListener( 'change', function () {
					var form = box.form;
					if ( form ) { form.submit(); }
				} );
			} );

			document.querySelectorAll( '.sv-bridge-toggle' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var input = document.getElementById( button.getAttribute( 'data-target' ) );
					if ( ! input ) { return; }
					var masking = 'password' === input.getAttribute( 'type' );
					input.setAttribute( 'type', masking ? 'text' : 'password' );
					button.textContent = masking ? hideLabel : showLabel;
					button.setAttribute( 'aria-pressed', masking ? 'true' : 'false' );
				} );
			} );

			document.querySelectorAll( '.sv-bridge-copy' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var input = document.getElementById( button.getAttribute( 'data-target' ) );
					if ( ! input ) { return; }

					var feedback = function ( ok ) {
						button.textContent = ok ? copiedLabel : failLabel;
						window.setTimeout( function () { button.textContent = copyLabel; }, 2000 );
					};

					var fallback = function () {
						input.setAttribute( 'type', 'text' );
						input.focus();
						input.setSelectionRange( 0, input.value.length );
						var ok = false;
						try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
						feedback( ok );
					};

					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( input.value ).then( function () { feedback( true ); }, fallback );
					} else {
						fallback();
					}
				} );
			} );
		}() );
		</script>
	</div>
	</div>
	<?php
}
