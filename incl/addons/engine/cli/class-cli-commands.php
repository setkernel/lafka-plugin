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

	/**
	 * Sync attribute-sourced groups against current taxonomy terms.
	 *
	 * For each group on the post that uses options_source=attribute,
	 * pulls fresh terms from its source taxonomy and merges them with
	 * existing options (preserving prices + included flags by label).
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Global addon CPT post ID, or a WC product post ID.
	 *
	 * [--dry-run]
	 * : Show what would change without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka-addons sync 123
	 *     wp lafka-addons sync 123 --dry-run
	 *
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ): void {
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$dry_run = ! empty( $assoc_args['dry-run'] );
		if ( $post_id <= 0 ) {
			\WP_CLI::error( 'A positive integer post_id is required.' );
		}

		$repo    = Lafka_Addons_Engine::instance()->repository();
		$sources = Lafka_Addons_Engine::instance()->sources();
		$groups  = $repo->get_groups( $post_id );
		if ( empty( $groups ) ) {
			\WP_CLI::log( "Post {$post_id} has no addon groups." );
			return;
		}

		$updated  = array();
		$rebuilt  = array();
		foreach ( $groups as $idx => $group ) {
			if ( Lafka_Addon_Schema::SOURCE_ATTRIBUTE !== $group->options_source ) {
				$rebuilt[] = $group;
				continue;
			}
			$source = $sources[ Lafka_Addon_Schema::SOURCE_ATTRIBUTE ] ?? null;
			if ( ! $source ) {
				$rebuilt[] = $group;
				continue;
			}
			$before = count( $group->options );
			$synced = $source->sync( $group );
			$after  = count( $synced->options );
			if ( $before !== $after || $synced !== $group ) {
				$updated[] = sprintf(
					'  [%d] %s — %d → %d options',
					$idx,
					$group->name ?: '(unnamed)',
					$before,
					$after
				);
			}
			$rebuilt[] = $synced;
		}

		if ( empty( $updated ) ) {
			\WP_CLI::log( 'No attribute-sourced groups changed.' );
			return;
		}

		\WP_CLI::log( 'Changed:' );
		foreach ( $updated as $line ) {
			\WP_CLI::log( $line );
		}

		if ( $dry_run ) {
			\WP_CLI::log( 'Dry run — no writes.' );
			return;
		}

		$repo->save_groups( $post_id, $rebuilt );
		\WP_CLI::success( sprintf( 'Synced post %d.', $post_id ) );
	}

	/**
	 * Diagnose what the resolver returns for a given product on the PDP.
	 *
	 * Use when an addon group is saved but doesn't render on the product
	 * page. Walks the same code path as the front-end display and reports
	 * each step:
	 *   - Product lookup
	 *   - Per-product groups
	 *   - Parent product groups
	 *   - Global all-products posts matched
	 *   - Global category posts matched (with the product's terms)
	 *   - Final merged group list with field-name assignment
	 *
	 * ## OPTIONS
	 *
	 * <product_id>
	 * : WC product post ID to diagnose.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka-addons resolve 123
	 *
	 * @when after_wp_load
	 */
	public function resolve( $args, $assoc_args ): void {
		$product_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $product_id <= 0 ) {
			\WP_CLI::error( 'A positive integer product_id is required.' );
		}

		$post = get_post( $product_id );
		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( "Post {$product_id} not found." );
		}

		\WP_CLI::log( sprintf( '== Product %d (%s): %s ==', $product_id, $post->post_type, $post->post_title ) );
		if ( 'product' !== $post->post_type ) {
			\WP_CLI::warning( "Post is not a 'product' — addon resolution may not match what WC displays." );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			\WP_CLI::error( 'wc_get_product() returned null. Is WooCommerce active?' );
		}

		// Per-product groups.
		$repo            = \Lafka_Addons_Engine::instance()->repository();
		$product_groups  = $repo->get_groups( $product_id );
		\WP_CLI::log( sprintf( '  per-product groups:    %d', count( $product_groups ) ) );

		// Parent product groups.
		$parent_id = wp_get_post_parent_id( $product_id );
		$parent_groups = $parent_id > 0 ? $repo->get_groups( $parent_id ) : array();
		\WP_CLI::log( sprintf( '  parent (id=%d) groups: %d', $parent_id, count( $parent_groups ) ) );

		// Global all-products posts.
		$all_posts = get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => 'lafka_glb_addon',
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key' => '_all_products',
						'value' => '1',
					),
				),
			)
		);
		\WP_CLI::log( sprintf( '  global all-products:   %d posts', count( $all_posts ) ) );
		foreach ( $all_posts as $p ) {
			$g = $repo->get_groups( $p->ID );
			\WP_CLI::log( sprintf( '    post %d "%s" → %d groups', $p->ID, $p->post_title, count( $g ) ) );
		}

		// Global category-scoped posts.
		$term_ids = function_exists( 'wc_get_object_terms' )
			? (array) wc_get_object_terms( $product_id, 'product_cat', 'term_id' )
			: array();
		\WP_CLI::log( sprintf( '  product cat term IDs:  [%s]', implode( ', ', array_map( 'intval', $term_ids ) ) ) );

		if ( ! empty( $term_ids ) ) {
			$cat_posts = get_posts(
				array(
					'posts_per_page' => -1,
					'post_type'      => 'lafka_glb_addon',
					'post_status'    => 'publish',
					'tax_query'      => array(
						array(
							'taxonomy'         => 'product_cat',
							'field'            => 'id',
							'terms'            => $term_ids,
							'include_children' => false,
						),
					),
				)
			);
			\WP_CLI::log( sprintf( '  global category-scoped: %d posts', count( $cat_posts ) ) );
			foreach ( $cat_posts as $p ) {
				$g = $repo->get_groups( $p->ID );
				\WP_CLI::log( sprintf( '    post %d "%s" → %d groups', $p->ID, $p->post_title, count( $g ) ) );
			}
		}

		// Exclude-global flag.
		$exclude = '1' === (string) $product->get_meta( '_product_addons_exclude_global' );
		\WP_CLI::log( sprintf( '  _product_addons_exclude_global: %s', $exclude ? 'YES (globals dropped!)' : 'no' ) );

		// Final resolved + helper-shape.
		\Lafka_Engine_Resolver::clear_cache();
		\Lafka_Engine_Helper::clear_cache();
		$resolved = ( new \Lafka_Engine_Resolver( $repo ) )->resolve_for_product( $product_id );
		\WP_CLI::log( sprintf( "\n== Resolver final output: %d groups ==", count( $resolved ) ) );
		foreach ( $resolved as $idx => $g ) {
			\WP_CLI::log(
				sprintf(
					'  [%d] %s (mode=%s, options=%d)',
					$idx,
					$g->name ?: '(unnamed)',
					$g->pricing_mode,
					count( $g->options )
				)
			);
		}

		$legacy = \Lafka_Engine_Helper::get_product_addons( $product_id );
		\WP_CLI::log( sprintf( "\n== Helper legacy-shape output: %d groups (after field-name assignment) ==", count( $legacy ) ) );
		foreach ( $legacy as $idx => $a ) {
			\WP_CLI::log(
				sprintf(
					'  [%d] %s field-name=%s type=%s required=%s options=%d',
					$idx,
					$a['name'] ?? '(unnamed)',
					$a['field-name'] ?? '(missing!)',
					$a['type'] ?? '?',
					$a['required'] ?? '?',
					count( $a['options'] ?? array() )
				)
			);
		}

		if ( empty( $legacy ) ) {
			\WP_CLI::warning(
				"Helper returned no addons. The PDP would render nothing. Check the inputs above:\n" .
				'  - If "global all-products: 0 posts" but you saved a group with "All products" → the _all_products meta key is wrong.' . "\n" .
				'  - If posts match but their groups count is 0 → the group has no options or didn\'t save properly.' . "\n" .
				'  - If _product_addons_exclude_global is YES → the product is opted out of all globals.'
			);
		}
	}
}

\WP_CLI::add_command( 'lafka-addons', 'Lafka_Addons_CLI_Commands' );
