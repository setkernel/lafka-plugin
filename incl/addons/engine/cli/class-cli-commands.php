<?php
/**
 * Lafka_Addons_CLI_Commands — WP-CLI command surface for the addon engine.
 *
 * Phase 4: read-only commands. Loadable only when WP_CLI is defined.
 *
 *   wp lafka-addons list                — table of all global addon CPT posts
 *   wp lafka-addons show <post_id>      — details for one post's groups
 *
 * Phase 5+ may add `sync`, `export`, `import` for bulk operations.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.3
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Lafka_Addons_CLI_Commands {

	/**
	 * List all global addon groups.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka-addons list
	 *
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ): void {
		$posts = get_posts(
			array(
				'post_type'      => 'lafka_glb_addon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			\WP_CLI::log( 'No global addon groups found.' );
			return;
		}

		$repo = Lafka_Addons_Engine::instance()->repository();
		$rows = array();
		foreach ( $posts as $post ) {
			$groups = $repo->get_groups( (int) $post->ID );
			$rows[] = array(
				'id'           => (int) $post->ID,
				'title'        => $post->post_title,
				'priority'     => (int) get_post_meta( $post->ID, '_priority', true ),
				'all_products' => (string) get_post_meta( $post->ID, '_all_products', true ) === '1' ? 'yes' : 'no',
				'groups'       => count( $groups ),
				'options'      => array_sum( array_map( static fn( $g ) => count( $g->options ), $groups ) ),
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$rows,
			array( 'id', 'title', 'priority', 'all_products', 'groups', 'options' )
		);
	}

	/**
	 * Show one post's addon groups in detail.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Global addon CPT post ID, or a WC product post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka-addons show 123
	 *
	 * @when after_wp_load
	 */
	public function show( $args, $assoc_args ): void {
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 ) {
			\WP_CLI::error( 'A positive integer post_id is required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			\WP_CLI::error( "Post {$post_id} not found." );
		}

		$repo   = Lafka_Addons_Engine::instance()->repository();
		$groups = $repo->get_groups( $post_id );

		\WP_CLI::log( sprintf( 'Post %d (%s): %s', $post_id, $post->post_type, $post->post_title ) );
		\WP_CLI::log( sprintf( '  groups: %d', count( $groups ) ) );

		foreach ( $groups as $idx => $group ) {
			\WP_CLI::log( sprintf( "\n  [%d] %s", $idx, $group->name ?: '(unnamed)' ) );
			\WP_CLI::log( sprintf( '      mode:    %s', $group->pricing_mode ) );
			\WP_CLI::log( sprintf( '      source:  %s', $group->options_source ) );
			if ( $group->options_source_attribute ) {
				\WP_CLI::log( sprintf( '      attr:    %s', $group->options_source_attribute ) );
			}
			\WP_CLI::log( sprintf( '      options: %d', count( $group->options ) ) );
		}
	}
}

\WP_CLI::add_command( 'lafka-addons', 'Lafka_Addons_CLI_Commands' );
