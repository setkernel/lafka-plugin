<?php
/**
 * WidgetsEscapingTest — locks down v9.7.21 widget hardening.
 *
 * Source-grep based since the widgets render inside the WP widget API which
 * needs a full bootstrap. Each test pins one regression target.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.21
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WidgetsEscapingTest extends TestCase {

	private function widget_src( string $name ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/widgets/' . $name );
	}

	// ────────────────────────────────────────────────────────────────────────
	// LafkaPopularPostsWidget — unescaped permalink + title (XSS-class polish)
	// ────────────────────────────────────────────────────────────────────────

	public function test_popular_posts_widget_uses_esc_url_get_permalink(): void {
		// Pre-fix the <a href="..."> emitted `the_permalink( $id )` raw.
		// `the_permalink()` outputs without escaping. Switch to
		// `echo esc_url( get_permalink( $id ) )` for defense.
		$src = $this->widget_src( 'LafkaPopularPostsWidget.php' );
		$this->assertMatchesRegularExpression(
			"/echo\s+esc_url\(\s*get_permalink\(\s*\\\$popular_post->ID\s*\)\s*\)/",
			$src,
			'Popular Posts widget must use echo esc_url( get_permalink( \$id ) ) for the link href.'
		);
		$this->assertDoesNotMatchRegularExpression(
			"/the_permalink\(\s*\\\$popular_post->ID\s*\)\s*;\s*\?>\"/",
			$src,
			'Popular Posts widget must not regress to raw the_permalink() in href.'
		);
	}

	public function test_popular_posts_widget_escapes_post_title(): void {
		// Pre-fix `echo get_the_title( $id )` emitted titles raw — operators
		// with unfiltered_html cap could inject HTML/script. Must wrap in
		// esc_html().
		$src = $this->widget_src( 'LafkaPopularPostsWidget.php' );
		$this->assertMatchesRegularExpression(
			"/echo\s+esc_html\(\s*'' !== \\\$lafka_pp_title/",
			$src,
			'Popular Posts widget must wrap post-title output in esc_html().'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// LafkaLatestMenuEntriesWidget — broken esc_url(the_permalink()) call
	// ────────────────────────────────────────────────────────────────────────

	public function test_latest_menu_widget_uses_echo_esc_url_get_permalink(): void {
		// Pre-fix the href was built as esc_url(the_permalink()) — the_permalink
		// echoes (returning null), so esc_url() received null and emitted ''.
		// The actual rendered href was the raw permalink output by the_permalink
		// before esc_url got called. Fix: switch to echo esc_url(get_permalink()).
		$src = $this->widget_src( 'LafkaLatestMenuEntriesWidget.php' );
		$this->assertMatchesRegularExpression(
			"/echo\s+esc_url\(\s*get_permalink\(\s*\)\s*\)/",
			$src,
			'Latest Menu widget must use echo esc_url(get_permalink()) for the link href.'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// LafkaAboutWidget — escape post_title in admin form
	// ────────────────────────────────────────────────────────────────────────

	public function test_about_widget_form_escapes_page_title(): void {
		$src = $this->widget_src( 'LafkaAboutWidget.php' );
		$this->assertMatchesRegularExpression(
			"/esc_html\(\s*\\\$page->post_title\s*\)/",
			$src,
			'About widget admin form must esc_html() $page->post_title.'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// All 4 widgets — strip_tags → sanitize_text_field in update()
	// ────────────────────────────────────────────────────────────────────────

	/**
	 * @dataProvider widgetsProvider
	 */
	public function test_widget_update_uses_sanitize_text_field_not_strip_tags( string $widget_filename ): void {
		// strip_tags only removes HTML tags — sanitize_text_field also decodes
		// entities, normalises whitespace, and strips line breaks. Same fix as
		// reasonable WP-coding-standards baseline.
		$src = $this->widget_src( $widget_filename );

		// Find the update() function body and assert no strip_tags inside it.
		$update_pos = strpos( $src, 'function update' );
		$this->assertNotFalse( $update_pos, "{$widget_filename} must define an update() method." );

		$slice = substr( $src, $update_pos, 2000 );

		$this->assertDoesNotMatchRegularExpression(
			"/strip_tags\(\s*\\\$new_instance/",
			$slice,
			"{$widget_filename}::update() must use sanitize_text_field (not strip_tags) on \$new_instance fields."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function widgetsProvider(): array {
		return array(
			'about'      => array( 'LafkaAboutWidget.php' ),
			'contacts'   => array( 'LafkaContactsWidget.php' ),
			'payment'    => array( 'LafkaPaymentOptionsWidget.php' ),
			'latestmenu' => array( 'LafkaLatestMenuEntriesWidget.php' ),
		);
	}

	public function test_contacts_widget_email_uses_sanitize_email(): void {
		// Email field needs the address-shape check, not just sanitize_text_field.
		$src = $this->widget_src( 'LafkaContactsWidget.php' );
		$this->assertMatchesRegularExpression(
			"/sanitize_email\(\s*wp_unslash\(\s*\\\$new_instance\['email'\]\s*\)\s*\)/",
			$src,
			'Contacts widget update() must use sanitize_email on the email field.'
		);
	}
}
