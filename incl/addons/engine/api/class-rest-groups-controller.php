<?php
/**
 * Lafka_Addons_REST_Groups_Controller — REST endpoints for addon groups.
 *
 * Phase 4: read-only foundation. Two endpoints:
 *   GET /wc-lafka/v1/addon-groups
 *     → list of all global addon groups (lafka_glb_addon CPT) with
 *       canonical engine v2 shape
 *
 *   GET /wc-lafka/v1/addon-groups/{post_id}
 *     → groups stored on a single post (global addon CPT or product post)
 *
 * Capability-gated on `manage_woocommerce` so untrusted clients can't
 * read addon configuration.
 *
 * Phase 5+ may add POST/PUT/DELETE endpoints for headless management,
 * Block Editor integration, or Lafka mobile app.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.3
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addons_REST_Groups_Controller extends WP_REST_Controller {

	const NAMESPACE = 'wc-lafka/v1';
	const ROUTE     = '/addon-groups';

	public function __construct() {
		$this->namespace = self::NAMESPACE;
		$this->rest_base = 'addon-groups';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			$this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'per_page' => array(
							'description'       => 'Maximum number of groups to return (capped at 100).',
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->mutation_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'description'       => 'Post ID — either a global addon CPT post or a WC product post.',
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						array(
							'id' => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						),
						$this->mutation_args()
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'force' => array(
							'description'       => 'Whether to bypass trash and force-delete.',
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * Args shared between POST and PATCH.
	 *
	 * @return array
	 */
	private function mutation_args(): array {
		return array(
			'title' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'priority' => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 10,
				'sanitize_callback' => 'absint',
			),
			'all_products' => array(
				'type'              => 'boolean',
				'required'          => false,
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'category_ids' => array(
				'type'     => 'array',
				'required' => false,
				'default'  => array(),
				'items'    => array( 'type' => 'integer' ),
			),
			'groups' => array(
				'type'        => 'array',
				'required'    => false,
				'default'     => array(),
				'description' => 'Array of group dicts in the editor POST shape (lafka_addon_groups[]).',
			),
		);
	}

	public function permissions_check(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /addon-groups — list all global addon CPT posts and their groups.
	 */
	public function get_items( $request ) {
		$per_page = (int) ( $request['per_page'] ?? 100 );

		$posts = get_posts(
			array(
				'post_type'      => 'lafka_glb_addon',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$payload = array();
		$repo    = Lafka_Addons_Engine::instance()->repository();

		foreach ( $posts as $post ) {
			$payload[] = $this->build_post_item( $post, $repo->get_groups( (int) $post->ID ) );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * GET /addon-groups/{id} — single post's groups.
	 */
	public function get_item( $request ) {
		$id   = (int) $request['id'];
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'lafka_addons_not_found',
				__( 'Post not found.', 'lafka-plugin' ),
				array( 'status' => 404 )
			);
		}
		if ( ! in_array( $post->post_type, array( 'lafka_glb_addon', 'product' ), true ) ) {
			return new WP_Error(
				'lafka_addons_unsupported_post_type',
				__( 'Addon groups only live on global addon CPT or product posts.', 'lafka-plugin' ),
				array( 'status' => 400 )
			);
		}

		$repo   = Lafka_Addons_Engine::instance()->repository();
		$groups = $repo->get_groups( $id );

		return rest_ensure_response( $this->build_post_item( $post, $groups ) );
	}

	/**
	 * POST /addon-groups — create a new global addon CPT post.
	 */
	public function create_item( $request ) {
		$post_id = (int) wp_insert_post(
			array(
				'post_title'  => (string) ( $request['title'] ?? __( 'Lafka Addon Group', 'lafka-plugin' ) ),
				'post_status' => 'publish',
				'post_type'   => 'lafka_glb_addon',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$response = $this->persist_via_engine( $post_id, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return rest_ensure_response( $response )->set_status( 201 );
	}

	/**
	 * PATCH /addon-groups/{id} — update an existing post.
	 */
	public function update_item( $request ) {
		$id   = (int) $request['id'];
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'lafka_glb_addon', 'product' ), true ) ) {
			return new WP_Error(
				'lafka_addons_not_found',
				__( 'Post not found.', 'lafka-plugin' ),
				array( 'status' => 404 )
			);
		}

		// Only update title for global addon CPT posts; product titles stay
		// owned by WC.
		if ( 'lafka_glb_addon' === $post->post_type && isset( $request['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => (string) $request['title'],
				)
			);
		}

		$response = $this->persist_via_engine( $id, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return rest_ensure_response( $response );
	}

	/**
	 * DELETE /addon-groups/{id} — trash (or force-delete) the post.
	 */
	public function delete_item( $request ) {
		$id    = (int) $request['id'];
		$force = (bool) ( $request['force'] ?? false );
		$post  = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || 'lafka_glb_addon' !== $post->post_type ) {
			return new WP_Error(
				'lafka_addons_not_found',
				__( 'Global addon group not found.', 'lafka-plugin' ),
				array( 'status' => 404 )
			);
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error(
				'lafka_addons_forbidden',
				__( 'You do not have permission to delete this addon.', 'lafka-plugin' ),
				array( 'status' => 403 )
			);
		}

		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		if ( ! $result ) {
			return new WP_Error(
				'lafka_addons_delete_failed',
				__( 'Could not delete the addon group.', 'lafka-plugin' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
				'force'   => $force,
			)
		);
	}

	/**
	 * Shared write path for POST + PATCH. Persists the meta + groups via
	 * the same Lafka_Engine_Editor pipeline the admin form uses.
	 *
	 * @param int            $post_id
	 * @param WP_REST_Request $request
	 * @return array|WP_Error  REST payload on success, error on failure.
	 */
	private function persist_via_engine( int $post_id, $request ) {
		$priority      = isset( $request['priority'] ) ? (int) $request['priority'] : 10;
		$all_products  = (bool) ( $request['all_products'] ?? true );
		$category_ids  = isset( $request['category_ids'] ) ? array_map( 'absint', (array) $request['category_ids'] ) : array();
		$groups_input  = isset( $request['groups'] ) ? (array) $request['groups'] : array();

		// Mutual exclusion: when all_products is true, drop categories.
		if ( $all_products ) {
			$category_ids = array();
		}

		update_post_meta( $post_id, '_priority', max( 0, $priority ) );
		update_post_meta( $post_id, '_all_products', $all_products ? 1 : 0 );
		wp_set_post_terms( $post_id, $category_ids, 'product_cat', false );

		// Reuse the editor's parser + expand pipeline so REST + admin form
		// produce identical stored shapes.
		$editor = new Lafka_Engine_Editor();
		$groups = $editor->parse_groups( array( 'lafka_addon_groups' => $groups_input ) );
		$groups = $editor->expand_groups( $groups );

		Lafka_Addons_Engine::instance()->repository()->save_groups( $post_id, $groups );

		$post = get_post( $post_id );
		return $this->build_post_item( $post, Lafka_Addons_Engine::instance()->repository()->get_groups( $post_id ) );
	}

	/**
	 * Convert a post + groups pair to canonical REST shape.
	 *
	 * @param Lafka_Addon_Group[] $groups
	 */
	private function build_post_item( WP_Post $post, array $groups ): array {
		return array(
			'id'              => (int) $post->ID,
			'post_type'       => $post->post_type,
			'title'           => $post->post_title,
			'priority'        => (int) get_post_meta( $post->ID, '_priority', true ),
			'all_products'    => (string) get_post_meta( $post->ID, '_all_products', true ) === '1',
			'category_ids'    => $this->get_category_ids( $post->ID ),
			'exclude_global'  => (string) get_post_meta( $post->ID, '_product_addons_exclude_global', true ) === '1',
			'groups'          => array_map(
				static fn( Lafka_Addon_Group $g ) => $g->to_array(),
				$groups
			),
		);
	}

	/**
	 * @return int[]
	 */
	private function get_category_ids( int $post_id ): array {
		$terms = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( $terms instanceof WP_Error || ! is_array( $terms ) ) {
			return array();
		}
		return array_map( 'intval', $terms );
	}
}
