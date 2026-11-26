<?php
/**
 * Conteúdo: listar, ler, publicar e editar posts e páginas.
 *
 * Toda a lógica fica aqui; o MCP e as rotas REST só traduzem a chamada.
 *
 * @package savitri-file-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Operações de conteúdo (posts e páginas).
 */
class SV_Bridge_Content {

	const MAX_LIMIT = 100;

	const MAX_META_BYTES = 1048576;

	/**
	 * Tipos aceitos. Extensível pelo filtro sv_bridge_post_types.
	 *
	 * @return string[]
	 */
	public static function types() {
		$types = (array) apply_filters( 'sv_bridge_post_types', array( 'post', 'page' ) );
		$blocked = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache' );
		$out     = array();

		foreach ( $types as $type ) {
			$type = (string) $type;

			if ( '' === $type || in_array( $type, $blocked, true ) ) {
				continue;
			}

			$out[] = $type;
		}

		$out = array_values( array_unique( $out ) );

		return $out ? $out : array( 'post', 'page' );
	}

	/**
	 * Status aceitos.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'draft', 'pending', 'private', 'publish', 'future' );
	}

	/* ---------------------------------------------------------
	 * Listar / ler
	 * ------------------------------------------------------- */

	/**
	 * Lista conteúdos.
	 *
	 * @param array $args type, status, search, slug, parent, limit, offset.
	 * @return array|WP_Error
	 */
	public static function list_posts( $args = array() ) {
		$type = self::check_type( isset( $args['type'] ) ? $args['type'] : 'post' );

		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$status = self::check_status( isset( $args['status'] ) ? $args['status'] : '' );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$limit  = $limit < 1 ? 20 : min( self::MAX_LIMIT, $limit );
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$query = array(
			'post_type'        => $type,
			'post_status'      => $status,
			'numberposts'      => $limit,
			'offset'           => $offset,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => false,
		);

		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$query['s'] = (string) $args['search'];
		}

		if ( isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] ) ) {
			$query['name'] = (string) $args['slug'];
		}

		if ( isset( $args['parent'] ) && is_numeric( $args['parent'] ) ) {
			$query['post_parent'] = (int) $args['parent'];
		}

		$found = get_posts( $query );
		$out   = array();

		foreach ( (array) $found as $post ) {
			$out[] = self::summary( $post );
		}

		return array(
			'type'   => $type,
			'count'  => count( $out ),
			'limit'  => $limit,
			'offset' => $offset,
			'posts'  => $out,
		);
	}

	/**
	 * Lê um conteúdo.
	 *
	 * @param int  $id        ID.
	 * @param bool $with_meta Trazer meta também.
	 * @return array|WP_Error
	 */
	public static function get( $id, $with_meta = false ) {
		$post = get_post( (int) $id );

		if ( ! $post ) {
			return self::error( 'sv_bridge_not_found', 'Conteúdo não encontrado: #' . (int) $id . '.', 404 );
		}

		if ( ! in_array( (string) $post->post_type, self::types(), true ) ) {
			return self::error( 'sv_bridge_bad_type', 'Este conteúdo não é post nem página: ' . $post->post_type . '.', 400 );
		}

		return self::detail( $post, (bool) $with_meta );
	}

	/* ---------------------------------------------------------
	 * Criar / atualizar
	 * ------------------------------------------------------- */

	/**
	 * Cria um conteúdo. Sem status, nasce rascunho; status=publish publica.
	 *
	 * @param array $args Campos.
	 * @return array|WP_Error
	 */
	public static function create( $args ) {
		$type = self::check_type( isset( $args['type'] ) ? $args['type'] : 'post' );

		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$fields = self::fields( $args, true );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$title   = isset( $fields['post_title'] ) ? trim( (string) $fields['post_title'] ) : '';
		$content = isset( $fields['post_content'] ) ? trim( (string) $fields['post_content'] ) : '';

		if ( '' === $title && '' === $content ) {
			return self::error( 'sv_bridge_empty', 'Informe pelo menos o título ou o conteúdo.', 400 );
		}

		$fields['post_type'] = $type;

		if ( ! isset( $fields['post_status'] ) ) {
			$fields['post_status'] = 'draft';
		}

		if ( ! isset( $fields['post_author'] ) ) {
			$fields['post_author'] = self::default_author();
		}

		$id = self::write( 'wp_insert_post', wp_slash( $fields ) );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$id = (int) $id;

		if ( $id < 1 ) {
			return self::error( 'sv_bridge_insert', 'Não foi possível criar o conteúdo.', 500 );
		}

		$extra = self::apply_extra( $id, $args );

		if ( is_wp_error( $extra ) ) {
			return self::error(
				'sv_bridge_partial',
				'Conteúdo #' . $id . ' criado, mas parte dos complementos falhou: ' . $extra->get_error_message(),
				500
			);
		}

		$post = get_post( $id );

		SV_Bridge_Log::add(
			'criou ' . $type . ' (' . ( isset( $fields['post_status'] ) ? $fields['post_status'] : 'draft' ) . ')',
			'/conteudo',
			'#' . $id . ' ' . $title
		);

		return self::detail( $post, ! empty( $args['meta'] ) );
	}

	/**
	 * Atualiza um conteúdo existente.
	 *
	 * @param int   $id   ID.
	 * @param array $args Campos.
	 * @return array|WP_Error
	 */
	public static function update( $id, $args ) {
		$post = get_post( (int) $id );

		if ( ! $post ) {
			return self::error( 'sv_bridge_not_found', 'Conteúdo não encontrado: #' . (int) $id . '.', 404 );
		}

		if ( ! in_array( (string) $post->post_type, self::types(), true ) ) {
			return self::error( 'sv_bridge_bad_type', 'Este conteúdo não é post nem página: ' . $post->post_type . '.', 400 );
		}

		$fields = self::fields( $args, false );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$touches_extra = isset( $args['categories'] ) || isset( $args['tags'] ) || isset( $args['meta'] ) || isset( $args['template'] );

		if ( empty( $fields ) && ! $touches_extra ) {
			return self::error( 'sv_bridge_nothing', 'Nada para atualizar: envie ao menos um campo.', 400 );
		}

		if ( ! empty( $fields ) ) {
			$fields['ID'] = (int) $post->ID;

			$result = self::write( 'wp_update_post', wp_slash( $fields ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! $result ) {
				return self::error( 'sv_bridge_update', 'Não foi possível atualizar o conteúdo.', 500 );
			}
		}

		$extra = self::apply_extra( (int) $post->ID, $args );

		if ( is_wp_error( $extra ) ) {
			return $extra;
		}

		$fresh = get_post( (int) $post->ID );

		SV_Bridge_Log::add(
			'atualizou ' . $post->post_type,
			'/conteudo',
			'#' . (int) $post->ID . ' ' . implode( ', ', array_keys( $fields ) )
		);

		return self::detail( $fresh, ! empty( $args['meta'] ) );
	}

	/* ---------------------------------------------------------
	 * Internos
	 * ------------------------------------------------------- */

	/**
	 * Grava sem o kses podar HTML (iframes, shortcodes, blocos).
	 *
	 * @param string $function wp_insert_post ou wp_update_post.
	 * @param array  $data     Dados já com wp_slash.
	 * @return int|WP_Error
	 */
	private static function write( $function, $data ) {
		if ( ! function_exists( 'kses_remove_filters' ) ) {
			require_once ABSPATH . WPINC . '/kses.php';
		}

		kses_remove_filters();

		$result = 'wp_update_post' === $function ? wp_update_post( $data, true ) : wp_insert_post( $data, true );

		kses_init_filters();

		if ( is_wp_error( $result ) ) {
			return self::error( 'sv_bridge_save', $result->get_error_message(), 500 );
		}

		return (int) $result;
	}

	/**
	 * Aplica taxonomias, meta e template de página.
	 *
	 * @param int   $id   ID.
	 * @param array $args Campos.
	 * @return true|WP_Error
	 */
	private static function apply_extra( $id, $args ) {
		$taxonomies = array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		);

		foreach ( $taxonomies as $key => $taxonomy ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}

			if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$ids = self::resolve_terms( $taxonomy, $args[ $key ] );

			if ( is_wp_error( $ids ) ) {
				return $ids;
			}

			$result = wp_set_object_terms( $id, $ids, $taxonomy, false );

			if ( is_wp_error( $result ) ) {
				return self::error( 'sv_bridge_terms', $result->get_error_message(), 400 );
			}
		}

		if ( isset( $args['template'] ) && 'page' === get_post_type( $id ) ) {
			update_post_meta( $id, '_wp_page_template', wp_slash( (string) $args['template'] ) );
		}

		if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
			kses_remove_filters();

			foreach ( $args['meta'] as $meta_key => $meta_value ) {
				$meta_key = (string) $meta_key;

				if ( '' === $meta_key ) {
					continue;
				}

				if ( null === $meta_value ) {
					delete_post_meta( $id, $meta_key );
					continue;
				}

				if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
					$meta_value = wp_json_encode( $meta_value );
				} elseif ( is_bool( $meta_value ) ) {
					$meta_value = $meta_value ? '1' : '0';
				} elseif ( ! is_scalar( $meta_value ) ) {
					continue;
				}

				$meta_value = (string) $meta_value;

				if ( strlen( $meta_value ) > self::MAX_META_BYTES ) {
					kses_init_filters();

					return self::error( 'sv_bridge_meta_large', 'O meta ' . $meta_key . ' passa de 1 MB.', 413 );
				}

				update_post_meta( $id, $meta_key, wp_slash( $meta_value ) );
			}

			kses_init_filters();

			// Elementor guarda o layout em meta: invalida o CSS para ser regerado.
			if ( array_key_exists( '_elementor_data', $args['meta'] ) ) {
				delete_post_meta( $id, '_elementor_css' );
				delete_post_meta( $id, '_elementor_element_cache' );
			}
		}

		return true;
	}

	/**
	 * Resolve nomes/IDs de termos, criando os que faltam.
	 *
	 * @param string $taxonomy Taxonomia.
	 * @param mixed  $values   Nomes, slugs ou IDs.
	 * @return int[]|WP_Error
	 */
	private static function resolve_terms( $taxonomy, $values ) {
		$values = is_array( $values ) ? $values : array( $values );
		$ids    = array();

		foreach ( $values as $value ) {
			if ( is_numeric( $value ) ) {
				$ids[] = (int) $value;
				continue;
			}

			$name = trim( (string) $value );

			if ( '' === $name ) {
				continue;
			}

			$term = term_exists( $name, $taxonomy );

			if ( ! $term ) {
				$term = wp_insert_term( $name, $taxonomy );
			}

			if ( is_wp_error( $term ) ) {
				return self::error( 'sv_bridge_term', 'Termo "' . $name . '": ' . $term->get_error_message(), 400 );
			}

			if ( is_array( $term ) && isset( $term['term_id'] ) ) {
				$ids[] = (int) $term['term_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Monta os campos aceitos.
	 *
	 * @param array $args   Entrada.
	 * @param bool  $is_new Criação.
	 * @return array|WP_Error
	 */
	private static function fields( $args, $is_new ) {
		$out = array();
		$map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		);

		foreach ( $map as $key => $field ) {
			if ( array_key_exists( $key, $args ) && null !== $args[ $key ] ) {
				$out[ $field ] = (string) $args[ $key ];
			}
		}

		if ( array_key_exists( 'status', $args ) && null !== $args['status'] && '' !== (string) $args['status'] ) {
			$status = (string) $args['status'];

			if ( ! in_array( $status, self::statuses(), true ) ) {
				return self::error( 'sv_bridge_bad_status', 'Status inválido: ' . $status . '. Use ' . implode( ', ', self::statuses() ) . '.', 400 );
			}

			$out['post_status'] = $status;
		}

		if ( array_key_exists( 'date', $args ) && null !== $args['date'] && '' !== (string) $args['date'] ) {
			$timestamp = strtotime( (string) $args['date'] );

			if ( false === $timestamp ) {
				return self::error( 'sv_bridge_bad_date', 'Data inválida: ' . $args['date'] . '. Use AAAA-MM-DD HH:MM:SS.', 400 );
			}

			$out['post_date'] = gmdate( 'Y-m-d H:i:s', $timestamp + (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
			$out['edit_date'] = true;
		}

		if ( array_key_exists( 'parent', $args ) && null !== $args['parent'] ) {
			$out['post_parent'] = max( 0, (int) $args['parent'] );
		}

		if ( array_key_exists( 'menu_order', $args ) && null !== $args['menu_order'] ) {
			$out['menu_order'] = (int) $args['menu_order'];
		}

		if ( array_key_exists( 'author', $args ) && null !== $args['author'] ) {
			$author = (int) $args['author'];

			if ( $author > 0 && ! get_userdata( $author ) ) {
				return self::error( 'sv_bridge_bad_author', 'Autor inexistente: #' . $author . '.', 400 );
			}

			$out['post_author'] = max( 0, $author );
		}

		if ( array_key_exists( 'password', $args ) && null !== $args['password'] ) {
			$out['post_password'] = (string) $args['password'];
		}

		if ( array_key_exists( 'comments', $args ) && null !== $args['comments'] ) {
			$out['comment_status'] = in_array( $args['comments'], array( 'open', 'closed' ), true ) ? $args['comments'] : 'closed';
		}

		unset( $is_new );

		return $out;
	}

	/**
	 * Valida o tipo.
	 *
	 * @param string $type Tipo.
	 * @return string|WP_Error
	 */
	private static function check_type( $type ) {
		$type = '' === (string) $type ? 'post' : (string) $type;

		if ( ! in_array( $type, self::types(), true ) ) {
			return self::error( 'sv_bridge_bad_type', 'Tipo não permitido: ' . $type . '. Use ' . implode( ' ou ', self::types() ) . '.', 400 );
		}

		return $type;
	}

	/**
	 * Normaliza o filtro de status.
	 *
	 * @param string $status Texto (vazio, any, um ou vários separados por vírgula).
	 * @return string|string[]|WP_Error
	 */
	private static function check_status( $status ) {
		$status = trim( (string) $status );

		if ( '' === $status || 'any' === $status ) {
			return 'any';
		}

		$allowed = array_merge( self::statuses(), array( 'trash' ) );
		$parts   = array_values( array_filter( array_map( 'trim', explode( ',', $status ) ) ) );

		foreach ( $parts as $part ) {
			if ( ! in_array( $part, $allowed, true ) ) {
				return self::error( 'sv_bridge_bad_status', 'Status inválido: ' . $part . '.', 400 );
			}
		}

		return 1 === count( $parts ) ? $parts[0] : $parts;
	}

	/**
	 * Resumo de um conteúdo.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function summary( $post ) {
		return array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'link'     => get_permalink( $post ),
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
		);
	}

	/**
	 * Conteúdo completo.
	 *
	 * @param WP_Post $post      Post.
	 * @param bool    $with_meta Trazer meta.
	 * @return array
	 */
	private static function detail( $post, $with_meta ) {
		$out = array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'link'     => get_permalink( $post ),
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'author'   => (int) $post->post_author,
			'parent'   => (int) $post->post_parent,
			'template' => (string) get_post_meta( (int) $post->ID, '_wp_page_template', true ),
			'excerpt'  => $post->post_excerpt,
			'content'  => $post->post_content,
		);

		if ( 'post' === $post->post_type ) {
			$out['categories'] = self::term_names( (int) $post->ID, 'category' );
			$out['tags']       = self::term_names( (int) $post->ID, 'post_tag' );
		}

		if ( $with_meta ) {
			$out['meta'] = self::meta_dump( (int) $post->ID );
		}

		return $out;
	}

	/**
	 * Nomes dos termos de um conteúdo.
	 *
	 * @param int    $id       ID.
	 * @param string $taxonomy Taxonomia.
	 * @return string[]
	 */
	private static function term_names( $id, $taxonomy ) {
		if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $id, $taxonomy, array( 'fields' => 'names' ) );

		return is_wp_error( $terms ) ? array() : array_values( (array) $terms );
	}

	/**
	 * Meta do conteúdo, sem os campos de edição e com corte no que for enorme.
	 *
	 * @param int $id ID.
	 * @return array
	 */
	private static function meta_dump( $id ) {
		$meta = get_post_meta( $id );
		$out  = array();

		foreach ( (array) $meta as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
				continue;
			}

			$value = is_array( $values ) && 1 === count( $values ) ? $values[0] : $values;

			if ( is_string( $value ) && strlen( $value ) > 20000 ) {
				$value = substr( $value, 0, 20000 ) . '… [cortado, ' . strlen( $value ) . ' bytes no total]';
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Autor padrão: o administrador mais antigo.
	 *
	 * @return int
	 */
	private static function default_author() {
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( empty( $ids ) ) {
			$ids = get_users(
				array(
					'number'  => 1,
					'fields'  => 'ID',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
		}

		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Erro com status HTTP.
	 *
	 * @param string $code    Código.
	 * @param string $message Mensagem.
	 * @param int    $status  Status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}
}
