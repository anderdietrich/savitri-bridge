<?php
/**
 * Tela de configuração: chave, endpoint MCP e log.
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
		'sv_bridge_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sv_bridge_sanitize_key',
			'default'           => '',
		)
	);

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
 * Sanitiza a chave (só hexadecimal, mínimo 32).
 *
 * @param string $value Valor.
 * @return string
 */
function sv_bridge_sanitize_key( $value ) {
	$value = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $value ) );

	if ( '' !== $value && strlen( $value ) < 32 ) {
		add_settings_error( 'sv_bridge', 'sv_bridge_short', __( 'A chave precisa ter no mínimo 32 caracteres. Nada foi salvo.', 'savitri-file-bridge' ), 'error' );

		return (string) get_option( 'sv_bridge_key', '' );
	}

	return $value;
}

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
 * Ação: gerar chave nova.
 */
function sv_bridge_admin_generate_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'savitri-file-bridge' ) );
	}

	check_admin_referer( 'sv_bridge_generate' );

	if ( 'constant' === sv_bridge_key_source() ) {
		wp_safe_redirect( add_query_arg( 'sv_bridge_notice', 'constant', sv_bridge_settings_url() ) );
		exit;
	}

	update_option( 'sv_bridge_key', sv_bridge_generate_key(), false );

	SV_Bridge_Log::add( 'chave gerada', 'admin' );

	wp_safe_redirect( add_query_arg( 'sv_bridge_notice', 'generated', sv_bridge_settings_url() ) );
	exit;
}
add_action( 'admin_post_sv_bridge_generate', 'sv_bridge_admin_generate_key' );

/**
 * Ação: revogar a chave.
 */
function sv_bridge_admin_revoke_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'savitri-file-bridge' ) );
	}

	check_admin_referer( 'sv_bridge_revoke' );

	if ( 'constant' === sv_bridge_key_source() ) {
		wp_safe_redirect( add_query_arg( 'sv_bridge_notice', 'constant', sv_bridge_settings_url() ) );
		exit;
	}

	update_option( 'sv_bridge_key', '', false );

	SV_Bridge_Log::add( 'chave revogada', 'admin' );

	wp_safe_redirect( add_query_arg( 'sv_bridge_notice', 'revoked', sv_bridge_settings_url() ) );
	exit;
}
add_action( 'admin_post_sv_bridge_revoke', 'sv_bridge_admin_revoke_key' );

/**
 * URL da tela de configuração.
 *
 * @return string
 */
function sv_bridge_settings_url() {
	return admin_url( 'options-general.php?page=sv-bridge' );
}

/**
 * Desenha a tela.
 */
function sv_bridge_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$source   = sv_bridge_key_source();
	$key      = sv_bridge_key();
	$enabled  = '' !== $source;
	$readonly = (bool) get_option( 'sv_bridge_readonly', false );
	$mcp_url  = rest_url( 'sv-bridge/v1/mcp' );
	$notice   = isset( $_GET['sv_bridge_notice'] ) ? sanitize_key( wp_unslash( $_GET['sv_bridge_notice'] ) ) : '';
	$log      = SV_Bridge_Log::tail( 40 );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Savitri Bridge', 'savitri-file-bridge' ); ?></h1>
		<p><?php esc_html_e( 'Canal para o assistente ler e editar arquivos de temas e plugins deste site.', 'savitri-file-bridge' ); ?></p>

		<?php if ( 'generated' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Chave gerada. Copie e cole na tela de conectores.', 'savitri-file-bridge' ); ?></p></div>
		<?php elseif ( 'revoked' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Chave revogada. O acesso está encerrado.', 'savitri-file-bridge' ); ?></p></div>
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
					<?php else : ?>
						<strong style="color:#b32d2e;"><?php esc_html_e( 'Desligado', 'savitri-file-bridge' ); ?></strong>
						— <?php esc_html_e( 'gere uma chave abaixo para ligar.', 'savitri-file-bridge' ); ?>
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
						esc_html_e( 'Gerada nesta tela (banco de dados)', 'savitri-file-bridge' );
					} else {
						esc_html_e( 'Nenhuma chave configurada', 'savitri-file-bridge' );
					}
					?>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Chave de conexão', 'savitri-file-bridge' ); ?></h2>

		<?php if ( $enabled ) : ?>
			<p><?php esc_html_e( 'Cole esta chave no campo de token da tela de conectores. Trate como senha: quem tiver a chave pode escrever código no site.', 'savitri-file-bridge' ); ?></p>
			<div class="sv-bridge-key" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;max-width:760px;">
				<input type="password" readonly id="sv-bridge-key" value="<?php echo esc_attr( $key ); ?>" class="regular-text code" style="flex:1 1 420px;min-width:240px;font-family:monospace;" autocomplete="off" spellcheck="false" autocapitalize="off" aria-describedby="sv-bridge-key-help" aria-label="<?php esc_attr_e( 'Chave de conexão', 'savitri-file-bridge' ); ?>">
				<button type="button" class="button" id="sv-bridge-key-toggle" aria-controls="sv-bridge-key" aria-pressed="false"><?php esc_html_e( 'Mostrar', 'savitri-file-bridge' ); ?></button>
				<button type="button" class="button" id="sv-bridge-key-copy"><?php esc_html_e( 'Copiar', 'savitri-file-bridge' ); ?></button>
			</div>
			<p class="description" id="sv-bridge-key-help"><?php esc_html_e( 'A chave fica mascarada. Clique em Mostrar para vê-la, ou em Copiar para colá-la na tela de conectores.', 'savitri-file-bridge' ); ?></p>
			<script>
			( function () {
				var input = document.getElementById( 'sv-bridge-key' );
				var toggle = document.getElementById( 'sv-bridge-key-toggle' );
				var copy = document.getElementById( 'sv-bridge-key-copy' );

				if ( ! input || ! toggle || ! copy ) {
					return;
				}

				var showLabel   = <?php echo wp_json_encode( __( 'Mostrar', 'savitri-file-bridge' ) ); ?>;
				var hideLabel   = <?php echo wp_json_encode( __( 'Ocultar', 'savitri-file-bridge' ) ); ?>;
				var copyLabel   = <?php echo wp_json_encode( __( 'Copiar', 'savitri-file-bridge' ) ); ?>;
				var copiedLabel = <?php echo wp_json_encode( __( 'Copiado!', 'savitri-file-bridge' ) ); ?>;
				var failLabel   = <?php echo wp_json_encode( __( 'Copie manualmente', 'savitri-file-bridge' ) ); ?>;

				function reveal() {
					input.setAttribute( 'type', 'text' );
					toggle.textContent = hideLabel;
					toggle.setAttribute( 'aria-pressed', 'true' );
				}

				toggle.addEventListener( 'click', function () {
					if ( 'password' === input.getAttribute( 'type' ) ) {
						reveal();
					} else {
						input.setAttribute( 'type', 'password' );
						toggle.textContent = showLabel;
						toggle.setAttribute( 'aria-pressed', 'false' );
					}
				} );

				copy.addEventListener( 'click', function () {
					var feedback = function ( ok ) {
						copy.textContent = ok ? copiedLabel : failLabel;
						window.setTimeout( function () {
							copy.textContent = copyLabel;
						}, 2000 );
					};

					var fallback = function () {
						reveal();
						input.focus();
						input.setSelectionRange( 0, input.value.length );
						var ok = false;
						try {
							ok = document.execCommand( 'copy' );
						} catch ( e ) {
							ok = false;
						}
						feedback( ok );
					};

					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( input.value ).then( function () {
							feedback( true );
						}, fallback );
					} else {
						fallback();
					}
				} );
			}() );
			</script>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
			<input type="hidden" name="action" value="sv_bridge_generate">
			<?php wp_nonce_field( 'sv_bridge_generate' ); ?>
			<?php submit_button( $enabled ? __( 'Gerar nova chave', 'savitri-file-bridge' ) : __( 'Gerar chave', 'savitri-file-bridge' ), 'primary', 'submit', false ); ?>
		</form>

		<?php if ( $enabled && 'option' === $source ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"
				onsubmit="return confirm('<?php echo esc_js( __( 'Revogar a chave? O assistente perde o acesso imediatamente.', 'savitri-file-bridge' ) ); ?>');">
				<input type="hidden" name="action" value="sv_bridge_revoke">
				<?php wp_nonce_field( 'sv_bridge_revoke' ); ?>
				<?php submit_button( __( 'Revogar chave', 'savitri-file-bridge' ), 'delete', 'submit', false ); ?>
			</form>
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
				<td><code><?php esc_html_e( 'a chave exibida acima', 'savitri-file-bridge' ); ?></code></td>
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
