<?php
/**
 * Atualização pelo GitHub: descobre a versão publicada e entrega ao WordPress
 * no formato que ele já entende (transient update_plugins).
 *
 * O repositório é público, então a consulta e o download do .zip funcionam sem
 * autenticação nenhuma.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repositório público de onde saem as versões. Fixo: é de onde este plugin vem.
 *
 * @return string
 */
function sv_bridge_github_repo() {
	return 'anderdietrich/savitri-bridge';
}

/**
 * Arquivo do plugin, como o WordPress o identifica.
 *
 * @return string
 */
function sv_bridge_plugin_basename() {
	return 'savitri-file-bridge/savitri-file-bridge.php';
}

/**
 * Faz uma chamada à API do GitHub, com cache curto.
 *
 * @param string $path Caminho depois de https://api.github.com/.
 * @param bool   $fresh Ignorar o cache.
 * @return array|WP_Error Resposta decodificada.
 */
function sv_bridge_github_api( $path, $fresh = false ) {
	$repo = sv_bridge_github_repo();

	if ( '' === $repo ) {
		return new WP_Error( 'sv_gh_no_repo', __( 'Repositório do GitHub não configurado.', 'savitri-file-bridge' ) );
	}

	$key = 'sv_bridge_gh_' . md5( $repo . '|' . $path );

	if ( $fresh ) {
		delete_transient( $key );
	}

	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$args = array(
		'timeout' => 15,
		'headers' => array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'savitri-file-bridge/' . SV_BRIDGE_VERSION,
		),
	);

	$url      = 'https://api.github.com/repos/' . $repo . $path;
	$response = wp_remote_get( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 404 === $code ) {
		return new WP_Error(
			'sv_gh_not_found',
			__( 'Não encontrei o repositório de versões no GitHub.', 'savitri-file-bridge' )
		);
	}

	if ( 401 === $code || 403 === $code ) {
		return new WP_Error( 'sv_gh_auth', __( 'O GitHub recusou a consulta (limite de acessos da API). Tente mais tarde.', 'savitri-file-bridge' ) );
	}

	if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
		return new WP_Error(
			'sv_gh_http',
			sprintf(
				/* translators: %d: código HTTP */
				__( 'O GitHub respondeu %d.', 'savitri-file-bridge' ),
				$code
			)
		);
	}

	set_transient( $key, $body, 15 * MINUTE_IN_SECONDS );

	return $body;
}

/**
 * Converte a etiqueta de versão ("v2.4.0" ou "2.4.0") em número comparável.
 *
 * @param string $tag Etiqueta.
 * @return string
 */
function sv_bridge_clean_version( $tag ) {
	$tag = trim( (string) $tag );

	if ( 0 === stripos( $tag, 'v' ) ) {
		$tag = substr( $tag, 1 );
	}

	$tag = preg_replace( '/[^0-9.].*$/', '', $tag );

	return '' !== $tag ? $tag : '0';
}

/**
 * Versão publicada mais recente, com o endereço do .zip.
 *
 * Prefere releases (com o .zip anexado); se não houver, usa tags, cujo pacote
 * é gerado pelo próprio GitHub.
 *
 * @param bool $fresh Ignorar o cache.
 * @return array|WP_Error
 */
function sv_bridge_github_latest( $fresh = false ) {
	$repo = sv_bridge_github_repo();

	if ( '' === $repo ) {
		return new WP_Error( 'sv_gh_no_repo', __( 'Repositório do GitHub não configurado.', 'savitri-file-bridge' ) );
	}

	$releases = sv_bridge_github_api( '/releases?per_page=10', $fresh );

	// Falha de credencial não se resolve tentando as tags: devolve o erro certo.
	if ( is_wp_error( $releases ) && 'sv_gh_auth' === $releases->get_error_code() ) {
		return $releases;
	}

	if ( ! is_wp_error( $releases ) && is_array( $releases ) && $releases ) {
		$best = null;

		foreach ( $releases as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
				continue;
			}

			$version = sv_bridge_clean_version( $release['tag_name'] ?? '' );

			if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
				$zip = '';

				foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
					if ( isset( $asset['name'] ) && '.zip' === substr( (string) $asset['name'], -4 ) ) {
						$zip = (string) $asset['browser_download_url'];
						break;
					}
				}

				$best = array(
					'version'      => $version,
					'tag'          => (string) ( $release['tag_name'] ?? '' ),
					'zip'          => $zip,
					'html_url'     => (string) ( $release['html_url'] ?? '' ),
					'body'         => (string) ( $release['body'] ?? '' ),
					'published_at' => (string) ( $release['published_at'] ?? '' ),
					'source'       => 'release',
				);
			}
		}

		if ( null !== $best ) {
			return $best;
		}
	}

	$tags = sv_bridge_github_api( '/tags?per_page=20', $fresh );

	if ( is_wp_error( $tags ) ) {
		return $tags;
	}

	$best = null;

	foreach ( (array) $tags as $tag ) {
		$version = sv_bridge_clean_version( $tag['name'] ?? '' );

		if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
			$best = array(
				'version'      => $version,
				'tag'          => (string) ( $tag['name'] ?? '' ),
				'zip'          => '',
				'html_url'     => 'https://github.com/' . $repo . '/tree/' . rawurlencode( (string) ( $tag['name'] ?? '' ) ),
				'body'         => '',
				'published_at' => '',
				'source'       => 'tag',
			);
		}
	}

	if ( null === $best || '0' === $best['version'] ) {
		return new WP_Error( 'sv_gh_empty', __( 'Nenhuma versão publicada encontrada no repositório.', 'savitri-file-bridge' ) );
	}

	// Tags não têm .zip anexado: usa o pacote do repositório naquela etiqueta.
	if ( '' === $best['zip'] ) {
		$best['zip'] = 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode( $best['tag'] );
	}

	return $best;
}

/**
 * Entrega a versão publicada ao WordPress, na forma que ele usa para
 * mostrar "atualização disponível" e atualizar com um clique.
 *
 * @param mixed $transient Transient update_plugins.
 * @return mixed
 */
function sv_bridge_check_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$latest = sv_bridge_github_latest();

	if ( is_wp_error( $latest ) ) {
		return $transient;
	}

	$plugin = sv_bridge_plugin_basename();

	if ( ! version_compare( $latest['version'], SV_BRIDGE_VERSION, '>' ) ) {
		// Já está na mais nova: limpa um aviso antigo, se houver.
		if ( isset( $transient->response[ $plugin ] ) ) {
			unset( $transient->response[ $plugin ] );
		}

		return $transient;
	}

	$item = (object) array(
		'id'           => 'github.com/' . sv_bridge_github_repo(),
		'slug'         => 'savitri-file-bridge',
		'plugin'       => $plugin,
		'new_version'  => $latest['version'],
		'url'          => $latest['html_url'],
		'package'      => $latest['zip'],
		'tested'       => '',
		'requires_php' => '7.4',
		'icons'        => array(),
		'banners'      => array(),
	);

	$transient->response[ $plugin ] = $item;

	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'sv_bridge_check_update' );

/**
 * Dados da janela "ver detalhes da versão" no WordPress.
 *
 * @param mixed  $result Valor atual.
 * @param string $action Ação pedida.
 * @param object $args   Argumentos.
 * @return mixed
 */
function sv_bridge_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'savitri-file-bridge' !== $args->slug ) {
		return $result;
	}

	$latest = sv_bridge_github_latest();

	if ( is_wp_error( $latest ) ) {
		return $result;
	}

	return (object) array(
		'name'          => 'Savitri Bridge',
		'slug'          => 'savitri-file-bridge',
		'version'       => $latest['version'],
		'author'        => '<a href="https://savitribc.com.br">Anderson Dietrich</a>',
		'homepage'      => $latest['html_url'],
		'download_link' => $latest['zip'],
		'last_updated'  => $latest['published_at'],
		'sections'      => array(
			'changelog' => '<pre style="white-space:pre-wrap;">' . esc_html( $latest['body'] ) . '</pre>',
		),
	);
}
add_filter( 'plugins_api', 'sv_bridge_plugin_info', 10, 3 );

/**
 * Compara a versão instalada com a publicada, para a tela de configuração.
 *
 * @param bool $fresh Ignorar o cache.
 * @return array
 */
function sv_bridge_update_status( $fresh = false ) {
	$latest = sv_bridge_github_latest( $fresh );

	if ( is_wp_error( $latest ) ) {
		return array(
			'ok'      => false,
			'error'   => $latest->get_error_message(),
			'current' => SV_BRIDGE_VERSION,
		);
	}

	return array(
		'ok'      => true,
		'current' => SV_BRIDGE_VERSION,
		'latest'  => $latest['version'],
		'tag'     => $latest['tag'],
		'url'     => $latest['html_url'],
		'zip'     => $latest['zip'],
		'newer'   => version_compare( $latest['version'], SV_BRIDGE_VERSION, '>' ),
		'source'  => $latest['source'],
		'date'    => $latest['published_at'],
	);
}

/**
 * Link que manda o WordPress atualizar o plugin (aparece quando há versão nova).
 *
 * @return string
 */
function sv_bridge_upgrade_url() {
	return wp_nonce_url(
		admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( sv_bridge_plugin_basename() ) ),
		'upgrade-plugin_' . sv_bridge_plugin_basename()
	);
}
