<?php
/**
 * Phase 2 (v9.26.0) — FAQPage schema generator for the contact page.
 *
 * The contact page (slug `contact` or `contact-us`, or any page using the
 * `template-contact.php` page template) renders five FAQ entries from
 * Customizer theme_mods `lafka_contact_faq_<n>_q` / `lafka_contact_faq_<n>_a`
 * (n = 1..5). The theme also exposes a `lafka_contact_faqs` filter that
 * child themes use to inject arbitrary question/answer pairs.
 *
 * This module emits a schema.org `FAQPage` entity whose `mainEntity` array
 * mirrors that list. Operator wins SERP rich-result eligibility once the
 * page contains at least one Q+A pair.
 *
 * Resolution order:
 *
 *   1. Theme-style filter `lafka_contact_faqs` (canonical — child themes
 *      already use this; we pass an array seeded from theme_mods).
 *   2. Theme_mods `lafka_contact_faq_1_q` .. `_5_q` + matching `_a`.
 *   3. Page content parser: if the operator hand-wrote FAQ markup in the
 *      page body (Block Editor block OR Classic Editor HTML) we extract
 *      `<details class="lafka-contact__faq-item">` blocks via DOMDocument.
 *
 * Returns null when not on a contact page OR no items resolve, so the
 * orchestrator can skip emission cleanly.
 *
 * @package Lafka\Plugin\Schema
 * @since   9.26.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_schema_is_contact_page' ) ) {
	/**
	 * True when the current request is the contact page.
	 *
	 * Matches:
	 *   - is_page() with slug 'contact' or 'contact-us' (operator-controlled URL)
	 *   - is_page_template('template-contact.php') (Lafka editorial template)
	 *
	 * Both checks gate on is_page() — FAQPage schema only ever attaches to a
	 * singular Page, never archives, the front page, or singular posts/products.
	 *
	 * @return bool
	 */
	function lafka_schema_is_contact_page(): bool {
		if ( ! function_exists( 'is_page' ) || ! is_page() ) {
			return false;
		}

		// Slug match — operator-controlled URL.
		if ( function_exists( 'get_post_field' ) ) {
			$slug = (string) get_post_field( 'post_name' );
			if ( in_array( $slug, array( 'contact', 'contact-us' ), true ) ) {
				return true;
			}
		}

		// Template match — Lafka editorial template.
		if ( function_exists( 'is_page_template' ) && is_page_template( 'template-contact.php' ) ) {
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'lafka_schema_faq_items_from_theme_mods' ) ) {
	/**
	 * Pull FAQ items from theme_mods set by the Lafka theme contact page.
	 *
	 * Mirrors the array shape consumed by template-contact.php. We do NOT pass
	 * defaults — empty Q or A is dropped so unconfigured installs don't emit
	 * placeholder copy as schema. The theme uses `__()` defaults for visual
	 * rendering, but schema is operator-owned content and must be explicit.
	 *
	 * @return array<int, array{q: string, a: string}>
	 */
	function lafka_schema_faq_items_from_theme_mods(): array {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return array();
		}
		$items = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$q = trim( (string) get_theme_mod( 'lafka_contact_faq_' . $i . '_q', '' ) );
			$a = trim( (string) get_theme_mod( 'lafka_contact_faq_' . $i . '_a', '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$items[] = array(
				'q' => $q,
				'a' => $a,
			);
		}
		return $items;
	}
}

if ( ! function_exists( 'lafka_schema_faq_items_from_content' ) ) {
	/**
	 * Parse FAQ items out of a page's post_content.
	 *
	 * Two paths:
	 *   1. Block Editor: page content contains parseable blocks. We walk
	 *      parse_blocks() output looking for any block whose innerHTML contains
	 *      `<details class="lafka-contact__faq-item">` markup, then parse each
	 *      such block via DOMDocument.
	 *   2. Classic Editor / shortcode-rendered HTML: page content is raw HTML.
	 *      We feed the whole post_content to DOMDocument.
	 *
	 * Either way, items must contain BOTH a `<summary>` (or `.lafka-contact__faq-q`)
	 * AND an answer container (`.lafka-contact__faq-a`). Items missing either are
	 * dropped — incomplete FAQ markup confuses Google's rich-result validator.
	 *
	 * Note: we use libxml in suppress-warnings mode because page content is
	 * frequently fragmentary HTML (no <html>/<body>) and libxml is chatty about it.
	 *
	 * @param string $content Raw post_content.
	 * @return array<int, array{q: string, a: string}>
	 */
	function lafka_schema_faq_items_from_content( string $content ): array {
		$content = trim( $content );
		if ( '' === $content ) {
			return array();
		}

		// Try block-editor parse first — yields tighter scoping than parsing
		// the whole content blob when the FAQ lives inside a larger page.
		$has_blocks = function_exists( 'has_blocks' ) ? has_blocks( $content ) : false;
		$html_chunks = array();
		if ( $has_blocks && function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $content );
			$collector = static function ( array $block, callable $self ) use ( &$html_chunks ): void {
				if ( ! empty( $block['innerHTML'] ) && false !== strpos( (string) $block['innerHTML'], 'lafka-contact__faq-item' ) ) {
					$html_chunks[] = (string) $block['innerHTML'];
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					foreach ( $block['innerBlocks'] as $inner ) {
						$self( $inner, $self );
					}
				}
			};
			foreach ( $blocks as $block ) {
				$collector( $block, $collector );
			}
		}

		// Fallback: parse the whole content blob (Classic Editor / shortcode).
		if ( empty( $html_chunks ) && false !== strpos( $content, 'lafka-contact__faq-item' ) ) {
			$html_chunks[] = $content;
		}

		if ( empty( $html_chunks ) ) {
			return array();
		}

		$items = array();
		foreach ( $html_chunks as $html ) {
			$found = lafka_schema_faq_parse_html_fragment( $html );
			foreach ( $found as $entry ) {
				$items[] = $entry;
			}
		}

		return $items;
	}
}

if ( ! function_exists( 'lafka_schema_faq_parse_html_fragment' ) ) {
	/**
	 * Parse `<details class="lafka-contact__faq-item">` blocks from a fragment.
	 *
	 * Uses DOMDocument with libxml warnings suppressed (page content is often
	 * fragmentary HTML). Each item must have both a `<summary>` element and a
	 * child with class `lafka-contact__faq-a`; items missing either are dropped.
	 *
	 * @param string $html HTML fragment.
	 * @return array<int, array{q: string, a: string}>
	 */
	function lafka_schema_faq_parse_html_fragment( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return array();
		}

		$doc   = new \DOMDocument();
		$prior = libxml_use_internal_errors( true );

		// Wrap in a UTF-8 meta so DOMDocument preserves multibyte characters,
		// and a root element so libxml doesn't complain about fragment-level
		// loading. The wrapper is stripped from output via xpath scoping below.
		$wrapped = '<?xml encoding="UTF-8"?><div id="lafka-faq-wrapper">' . $html . '</div>';
		$doc->loadHTML( $wrapped, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		$xpath = new \DOMXPath( $doc );
		$nodes = $xpath->query( "//details[contains(concat(' ', normalize-space(@class), ' '), ' lafka-contact__faq-item ')]" );
		if ( false === $nodes || 0 === $nodes->length ) {
			return array();
		}

		$items = array();
		foreach ( $nodes as $detail ) {
			$q = '';
			$a = '';

			$summary = $xpath->query( './/summary', $detail );
			if ( false !== $summary && $summary->length > 0 ) {
				$q = trim( $summary->item( 0 )->textContent );
				// The visible "+" icon span is decoration — strip trailing punctuation
				// noise if it crept in via textContent.
				$q = rtrim( $q, " \t\n\r\0\x0B+−" );
				$q = trim( $q );
			}

			$ans = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' lafka-contact__faq-a ')]", $detail );
			if ( false !== $ans && $ans->length > 0 ) {
				$a = trim( $ans->item( 0 )->textContent );
			}

			if ( '' === $q || '' === $a ) {
				continue;
			}

			$items[] = array(
				'q' => $q,
				'a' => $a,
			);
		}

		return $items;
	}
}

if ( ! function_exists( 'lafka_schema_faq_resolve_items' ) ) {
	/**
	 * Resolve the FAQ items for the current page from any available source.
	 *
	 * Order:
	 *   1. `lafka_contact_faqs` filter — same hook the theme uses; if a child
	 *      theme has populated it, we use that verbatim.
	 *   2. theme_mods `lafka_contact_faq_<n>_q` + `_a` — the operator-facing
	 *      Customizer panel slots.
	 *   3. Parse the page's post_content (Block Editor blocks or Classic HTML).
	 *
	 * @return array<int, array{q: string, a: string}>
	 */
	function lafka_schema_faq_resolve_items(): array {
		// Seed with theme_mods so the filter receives the same shape the theme
		// passes to it — keeps the contract identical for child-theme overrides.
		$seed = lafka_schema_faq_items_from_theme_mods();

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'lafka_contact_faqs', $seed );
			if ( is_array( $filtered ) ) {
				$normalised = array();
				foreach ( $filtered as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$q = isset( $row['q'] ) ? trim( (string) $row['q'] ) : '';
					$a = isset( $row['a'] ) ? trim( (string) $row['a'] ) : '';
					if ( '' === $q || '' === $a ) {
						continue;
					}
					$normalised[] = array(
						'q' => $q,
						'a' => $a,
					);
				}
				if ( ! empty( $normalised ) ) {
					return $normalised;
				}
			}
		}

		if ( ! empty( $seed ) ) {
			return $seed;
		}

		// Last resort: parse the page body. This handles operators who hand-wrote
		// FAQ markup into the page editor (Block or Classic) instead of using
		// the Customizer panel.
		if ( function_exists( 'get_queried_object' ) ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && '' !== $post->post_content ) {
				return lafka_schema_faq_items_from_content( $post->post_content );
			}
		}

		return array();
	}
}

if ( ! function_exists( 'lafka_schema_faq' ) ) {
	/**
	 * Build and return the FAQPage schema array.
	 *
	 * Returns null when:
	 *   - not on the contact page (slug + template gate)
	 *   - no FAQ items resolve from any source
	 *
	 * Output shape:
	 *
	 * ```json
	 * {
	 *   "@type": "FAQPage",
	 *   "mainEntity": [
	 *     {
	 *       "@type": "Question",
	 *       "name": "How long do orders take?",
	 *       "acceptedAnswer": {
	 *         "@type": "Answer",
	 *         "text": "Pickup is typically ready in about 25 minutes."
	 *       }
	 *     },
	 *     ...
	 *   ]
	 * }
	 * ```
	 *
	 * @return array<string, mixed>|null
	 */
	function lafka_schema_faq(): ?array {
		if ( ! lafka_schema_is_contact_page() ) {
			return null;
		}

		$items = lafka_schema_faq_resolve_items();
		if ( empty( $items ) ) {
			return null;
		}

		$main_entity = array();
		foreach ( $items as $item ) {
			// `text` accepts a plain-text answer. We strip block-level HTML
			// because Google rejects rich-text answers in FAQPage; inline
			// emphasis is fine but it's safer to ship the operator copy as
			// plain text. wp_strip_all_tags() preserves &amp; etc. correctly
			// (wp_json_encode handles the escaping at emit time).
			$answer_text = function_exists( 'wp_strip_all_tags' )
				? wp_strip_all_tags( $item['a'] )
				: strip_tags( $item['a'] );

			$main_entity[] = array(
				'@type'          => 'Question',
				'name'           => $item['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer_text,
				),
			);
		}

		$schema = array(
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);

		/**
		 * Filter the FAQPage schema array before emission.
		 *
		 * Use this to add, remove, or reorder questions sitewide. Return null
		 * (or an array with empty mainEntity) to skip emission entirely.
		 *
		 * @since 9.26.0
		 * @param array<string, mixed>              $schema The assembled FAQPage schema.
		 * @param array<int, array{q:string,a:string}> $items   The resolved FAQ items.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$schema = (array) apply_filters( 'lafka_schema_faq', $schema, $items );
		}

		if ( empty( $schema['mainEntity'] ) ) {
			return null;
		}

		return $schema;
	}
}
