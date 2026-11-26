<?php
/**
 * Tela de configuração: tabela de chaves, endpoint MCP e log.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra a página em Configurações.
 */
function sv_bridge_settings_menu() {
	add_options_page(
		__( 'Savitri Bridge', 'savitri-file-bridge' ),
		__( 'Savitri Bridge', 'savitri-file-bridge' ),
		'manage_options',
		'sv-bridge',
		'sv_bridge_render_settings'
	);
}
add_action( 'admin_menu', 'sv_bridge_settings_menu' );

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
 * URL da tela de configuração.
 *
 * @return string
 */
function sv_bridge_settings_url() {
	return admin_url( 'options-general.php?page=sv-bridge' );
}

/**
 * Volta para a tela com um aviso.
 *
 * @param string  $notice  Código do aviso.
 * @param string  $name    Nome da chave (para o aviso).
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
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Savitri Bridge', 'savitri-file-bridge' ); ?></h1>
		<p><?php esc_html_e( 'Canal para o assistente ler e editar arquivos de temas e plugins deste site.', 'savitri-file-bridge' ); ?></p>

		<?php if ( 'created' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				printf(
					/* translators: %s: nome da chave */
					esc_html__( 'Chave «%s» criada. Copie e cole no campo de token da tela de conectores.', 'savitri-file-bridge' ),
					esc_html( $name )
				);
				?>
			</p></div>
		<?php elseif ( 'renamed' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				printf(
					/* translators: %s: nome da chave */
					esc_html__( 'Nome da chave atualizado para «%s».', 'savitri-file-bridge' ),
					esc_html( $name )
				);
				?>
			</p></div>
		<?php elseif ( 'revoked' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				if ( '' !== $name ) {
					printf(
						/* translators: %s: nome da chave */
						esc_html__( 'Chave «%s» revogada. O acesso com ela está encerrado e ela saiu da lista.', 'savitri-file-bridge' ),
						esc_html( $name )
					);
				} else {
					esc_html_e( 'Chave revogada. O acesso com ela está encerrado e ela saiu da lista.', 'savitri-file-bridge' );
				}
				?>
			</p></div>
		<?php elseif ( 'unlocked' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bloqueio de tentativas liberado para o seu IP.', 'savitri-file-bridge' ); ?></p></div>
		<?php elseif ( 'no_name' === $notice ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Dê um nome à chave: ele identifica a ferramenta conectada.', 'savitri-file-bridge' ); ?></p></div>
		<?php elseif ( 'not_found' === $notice ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Chave não encontrada. A lista pode ter mudado.', 'savitri-file-bridge' ); ?></p></div>
		<?php elseif ( 'constant' === $notice ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'A chave está definida no wp-config.php. Para gerenciá-la por aqui, remova a constante SV_FILE_BRIDGE_KEY.', 'savitri-file-bridge' ); ?></p></div>
		<?php endif; ?>

		<h2 class="title"><?php esc_html_e( 'Status', 'savitri-file-bridge' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Canal', 'savitri-file-bridge' ); ?></th>
				<td>
					<?php if ( $enabled ) : ?>
						<strong style="color:#1a7f37;"><?php esc_html_e( 'Ativo', 'savitri-file-bridge' ); ?></strong>
						— <?php printf( esc_html( _n( '%s chave ativa', '%s chaves ativas', count( $keys ), 'savitri-file-bridge' ) ), esc_html( number_format_i18n( count( $keys ) ) ) ); ?>
					<?php else : ?>
						<strong style="color:#b32d2e;"><?php esc_html_e( 'Desligado', 'savitri-file-bridge' ); ?></strong>
						— <?php esc_html_e( 'crie uma chave abaixo para ligar.', 'savitri-file-bridge' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Origem da chave', 'savitri-file-bridge' ); ?></th>
				<td>
					<?php
					if ( 'constant' === $source ) {
						esc_html_e( 'Constante SV_FILE_BRIDGE_KEY no wp-config.php', 'savitri-file-bridge' );
					} elseif ( 'option' === $source ) {
						esc_html_e( 'Chaves criadas nesta tela (banco de dados)', 'savitri-file-bridge' );
					} else {
						esc_html_e( 'Nenhuma chave configurada', 'savitri-file-bridge' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Seu IP', 'savitri-file-bridge' ); ?></th>
				<td>
					<code><?php echo esc_html( SV_Bridge_Auth::current_ip() ); ?></code>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px;">
						<input type="hidden" name="action" value="sv_bridge_unlock">
						<?php wp_nonce_field( 'sv_bridge_unlock' ); ?>
						<?php submit_button( __( 'Liberar bloqueio', 'savitri-file-bridge' ), 'secondary', 'submit', false ); ?>
					</form>
					<p class="description"><?php esc_html_e( 'Cinco tentativas com chave errada bloqueiam o acesso por 10 minutos. Este botão zera o bloqueio do seu IP.', 'savitri-file-bridge' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Chaves de conexão', 'savitri-file-bridge' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;max-width:760px;">
			<input type="hidden" name="action" value="sv_bridge_create">
			<?php wp_nonce_field( 'sv_bridge_create' ); ?>
			<label style="flex:1 1 320px;">
				<span style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Nome da ferramenta conectada', 'savitri-file-bridge' ); ?></span>
				<input type="text" name="sv_bridge_name" class="regular-text" style="width:100%;" required maxlength="80" placeholder="<?php esc_attr_e( 'Ex.: Maestro — Bob', 'savitri-file-bridge' ); ?>" <?php disabled( 'constant' === $source ); ?>>
			</label>
			<?php submit_button( __( 'Criar chave', 'savitri-file-bridge' ), 'primary', 'submit', false, 'constant' === $source ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>

		<?php if ( ! $keys ) : ?>
			<p><?php esc_html_e( 'Nenhuma chave criada. Crie a primeira acima para ligar o canal.', 'savitri-file-bridge' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th scope="col" style="width:22%;"><?php esc_html_e( 'Ferramenta conectada', 'savitri-file-bridge' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Chave', 'savitri-file-bridge' ); ?></th>
						<th scope="col" style="width:15%;"><?php esc_html_e( 'Criada', 'savitri-file-bridge' ); ?></th>
						<th scope="col" style="width:17%;"><?php esc_html_e( 'Último uso', 'savitri-file-bridge' ); ?></th>
						<th scope="col" style="width:20%;"><?php esc_html_e( 'Ações', 'savitri-file-bridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $keys as $i => $entry ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' !== $entry['name'] ? $entry['name'] : __( 'Sem nome', 'savitri-file-bridge' ) ); ?></strong>
							<details style="margin-top:6px;">
								<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Editar nome', 'savitri-file-bridge' ); ?></summary>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
									<input type="hidden" name="action" value="sv_bridge_rename">
									<input type="hidden" name="sv_bridge_id" value="<?php echo esc_attr( $entry['id'] ); ?>">
									<?php wp_nonce_field( 'sv_bridge_rename' ); ?>
									<input type="text" name="sv_bridge_name" value="<?php echo esc_attr( $entry['name'] ); ?>" class="regular-text" style="flex:1 1 140px;min-width:120px;" required maxlength="80" aria-label="<?php esc_attr_e( 'Nome da chave', 'savitri-file-bridge' ); ?>">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Salvar', 'savitri-file-bridge' ); ?></button>
								</form>
							</details>
						</td>
						<td>
							<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
								<input type="password" readonly value="<?php echo esc_attr( $entry['key'] ); ?>" class="code sv-bridge-key-input" id="sv-bridge-key-<?php echo esc_attr( $entry['id'] ); ?>" style="flex:1 1 260px;min-width:180px;font-family:monospace;" autocomplete="off" spellcheck="false" aria-label="<?php esc_attr_e( 'Chave de conexão', 'savitri-file-bridge' ); ?>">
								<button type="button" class="button button-small sv-bridge-toggle" data-target="sv-bridge-key-<?php echo esc_attr( $entry['id'] ); ?>" aria-pressed="false"><?php esc_html_e( 'Mostrar', 'savitri-file-bridge' ); ?></button>
								<button type="button" class="button button-small sv-bridge-copy" data-target="sv-bridge-key-<?php echo esc_attr( $entry['id'] ); ?>"><?php esc_html_e( 'Copiar', 'savitri-file-bridge' ); ?></button>
							</div>
						</td>
						<td><?php echo esc_html( '' !== $entry['created'] ? $entry['created'] : '—' ); ?></td>
						<td>
							<?php echo esc_html( '' !== $entry['last_used'] ? $entry['last_used'] : __( 'nunca usada', 'savitri-file-bridge' ) ); ?>
							<?php if ( '' !== $entry['last_ip'] ) : ?>
								<br><span class="description"><?php echo esc_html( $entry['last_ip'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: nome da chave */ __( 'Revogar a chave «%s»? A ferramenta conectada perde o acesso imediatamente e a chave sai da lista. Não dá para desfazer.', 'savitri-file-bridge' ), '' !== $entry['name'] ? $entry['name'] : __( 'sem nome', 'savitri-file-bridge' ) ) ); ?>');">
								<input type="hidden" name="action" value="sv_bridge_revoke">
								<input type="hidden" name="sv_bridge_id" value="<?php echo esc_attr( $entry['id'] ); ?>">
								<?php wp_nonce_field( 'sv_bridge_revoke' ); ?>
								<button type="submit" class="button button-small button-link-delete" style="color:#b32d2e;"><?php esc_html_e( 'Revogar', 'savitri-file-bridge' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'A chave só sai desta lista quando é revogada. Quem tiver uma chave pode escrever código no site: trate como senha.', 'savitri-file-bridge' ); ?></p>

			<script>
			( function () {
				var showLabel   = <?php echo wp_json_encode( __( 'Mostrar', 'savitri-file-bridge' ) ); ?>;
				var hideLabel   = <?php echo wp_json_encode( __( 'Ocultar', 'savitri-file-bridge' ) ); ?>;
				var copyLabel   = <?php echo wp_json_encode( __( 'Copiar', 'savitri-file-bridge' ) ); ?>;
				var copiedLabel = <?php echo wp_json_encode( __( 'Copiado!', 'savitri-file-bridge' ) ); ?>;
				var failLabel   = <?php echo wp_json_encode( __( 'Copie manualmente', 'savitri-file-bridge' ) ); ?>;

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
		<?php endif; ?>

		<h2 class="title"><?php esc_html_e( 'Conexão na tela de conectores', 'savitri-file-bridge' ); ?></h2>
		<p><?php esc_html_e( 'No assistente, escolha adicionar conector via URL e preencha assim:', 'savitri-file-bridge' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Nome', 'savitri-file-bridge' ); ?></th>
				<td><code>WordPress Savitri</code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tipo de conexão', 'savitri-file-bridge' ); ?></th>
				<td><code>HTTP</code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Autenticação', 'savitri-file-bridge' ); ?></th>
				<td><code><?php esc_html_e( 'Token', 'savitri-file-bridge' ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'URL do servidor', 'savitri-file-bridge' ); ?></th>
				<td>
					<code style="user-select:all;"><?php echo esc_html( $mcp_url ); ?></code>
					<?php if ( 0 !== strpos( $mcp_url, 'https://' ) ) : ?>
						<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'A URL não está em HTTPS. O token trafega no cabeçalho e só é seguro com HTTPS.', 'savitri-file-bridge' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Token', 'savitri-file-bridge' ); ?></th>
				<td><code><?php esc_html_e( 'uma das chaves da tabela acima', 'savitri-file-bridge' ); ?></code></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Permissão', 'savitri-file-bridge' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'sv_bridge' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Modo somente leitura', 'savitri-file-bridge' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sv_bridge_readonly" value="1" <?php checked( $readonly ); ?>>
							<?php esc_html_e( 'Permitir apenas listar e ler. Bloqueia gravar, apagar, extrair, ativar tema e plugin.', 'savitri-file-bridge' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Salvar', 'savitri-file-bridge' ) ); ?>
		</form>

		<h2 class="title"><?php esc_html_e( 'Últimas operações', 'savitri-file-bridge' ); ?></h2>
		<?php if ( ! $log ) : ?>
			<p><?php esc_html_e( 'Nenhuma operação registrada.', 'savitri-file-bridge' ); ?></p>
		<?php else : ?>
			<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;overflow:auto;max-height:380px;"><?php echo esc_html( implode( "\n", $log ) ); ?></pre>
		<?php endif; ?>
	</div>
	<?php
}
