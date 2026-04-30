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
			)
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
