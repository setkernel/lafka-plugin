<?php
/**
 * P6-A11Y-9 W2-T7: WP-CLI image-alt backfill for Lafka.
 *
 * Strategy:
 *  - For attachments attached to a WC product → alt = product name
 *  - For attachments with filename-derived alts (e.g. "Untitled-design-11",
 *    "homemade-fish-and-chips-family-pack") → alt = parent post title if available,
 *    else cleared (empty alt is better than garbage alt for screen readers).
 *  - Skip attachments with meaningful alts (≥3 words AND no obvious filename pattern).
 *
 * Usage:
 *   wp lafka image-alts scan
 *   wp lafka image-alts scan --post-type=product --limit=100
 *   wp lafka image-alts apply
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Lafka_Image_Alt_Backfill_Command {

	/**
	 * Scan attachments and report what would change. (Read-only.)
	 *
	 * ## OPTIONS
	 * [--post-type=<type>]
	 * : Only scan attachments whose parent is this post type (e.g. product). Default: any.
	 * [--limit=<n>]
	 * : Cap results at N attachments. Default: 0 (no cap).
	 */
	public function scan( $args, $assoc_args ) {
		$this->run( $args, $assoc_args, false );
	}

	/**
	 * Apply the changes scan produces.
	 *
	 * ## OPTIONS
	 * [--post-type=<type>]
	 * [--limit=<n>]
	 */
	public function apply( $args, $assoc_args ) {
		$this->run( $args, $assoc_args, true );
	}

	private function run( $args, $assoc_args, $apply ) {
		$post_type = isset( $assoc_args['post-type'] ) ? sanitize_key( $assoc_args['post-type'] ) : '';
		$limit     = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'fields'         => 'ids',
		);
		$ids = get_posts( $query_args );

		$changes = array();
		foreach ( $ids as $att_id ) {
			$current_alt = (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true );
			$parent_id   = (int) wp_get_post_parent_id( $att_id );

			if ( $post_type && $parent_id ) {
				$parent_post_type = get_post_type( $parent_id );
				if ( $parent_post_type !== $post_type ) {
					continue;
				}
			}

			$new_alt = $this->derive_alt( $att_id, $current_alt, $parent_id );
			if ( $new_alt === null ) {
				continue; // skip — current alt is fine
			}
			if ( $new_alt === $current_alt ) {
				continue; // no change
			}

			$changes[] = array(
				'id'     => $att_id,
				'parent' => $parent_id,
				'before' => $current_alt,
				'after'  => $new_alt,
				'reason' => $this->reason( $current_alt, $new_alt ),
			);
		}

		if ( empty( $changes ) ) {
			WP_CLI::success( 'No changes needed.' );
			return;
		}

		WP_CLI::log( sprintf( '%s %d attachment%s would be updated:', $apply ? 'Updating' : 'Would update', count( $changes ), count( $changes ) === 1 ? '' : 's' ) );
		WP_CLI::log( '' );
		WP_CLI\Utils\format_items(
			'table',
			$changes,
			array( 'id', 'parent', 'reason', 'before', 'after' )
		);

		if ( $apply ) {
			$applied = 0;
			foreach ( $changes as $c ) {
				update_post_meta( $c['id'], '_wp_attachment_image_alt', $c['after'] );
				$applied++;
			}
			WP_CLI::success( sprintf( 'Updated %d attachment alt%s.', $applied, $applied === 1 ? '' : 's' ) );
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( 'Run again with `apply` to update these attachments.' );
		}
	}

	/**
	 * Decide a new alt or null if no change should be made.
	 */
	private function derive_alt( int $att_id, string $current, int $parent_id ): ?string {
		// 1. If attached to a product → product name
		if ( $parent_id ) {
			$parent_type = get_post_type( $parent_id );
			if ( 'product' === $parent_type ) {
				$product_name = (string) get_the_title( $parent_id );
				if ( $product_name !== '' ) {
					return $product_name;
				}
			}
		}

		// 2. If current alt is "good enough" (≥3 words, no filename pattern) → skip
		if ( $this->is_meaningful_alt( $current ) ) {
			return null;
		}

		// 3. If parent is a non-product post with a title → use the title
		if ( $parent_id ) {
			$parent_title = (string) get_the_title( $parent_id );
			if ( $parent_title !== '' && $parent_title !== '(no title)' ) {
				return $parent_title;
			}
		}

		// 4. Else clear the alt (empty is better than garbage for screen readers)
		return '';
	}

	private function is_meaningful_alt( string $alt ): bool {
		$alt = trim( $alt );
		if ( $alt === '' ) {
			return false;
		}
		// Filename patterns (treat as not-meaningful)
		if ( preg_match( '/-\d{2,4}x\d{2,4}/', $alt ) ) {
			return false; // e.g. "image-600x600"
		}
		if ( preg_match( '/\.(png|jpe?g|webp|gif|svg)$/i', $alt ) ) {
			return false;
		}
		if ( preg_match( '/^(untitled|gemini_generated|img_\d+|dsc_|screen ?shot|scan)/i', $alt ) ) {
			return false;
		}
		// Slugs that look like filename-derived: all-lowercase, hyphens or underscores, no spaces
		if ( strpos( $alt, ' ' ) === false && ( strpos( $alt, '-' ) !== false || strpos( $alt, '_' ) !== false ) ) {
			return false;
		}
		// Word count check
		$words = preg_split( '/\s+/', $alt );
		if ( count( $words ) < 3 ) {
			return false;
		}
		return true;
	}

	private function reason( string $before, string $after ): string {
		if ( $before === '' && $after !== '' ) {
			return 'set';
		}
		if ( $before !== '' && $after === '' ) {
			return 'cleared garbage';
		}
		return 'replaced';
	}
}

WP_CLI::add_command( 'lafka image-alts', 'Lafka_Image_Alt_Backfill_Command' );
