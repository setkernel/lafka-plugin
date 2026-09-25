<?php
/**
 * Insights plain-English summary (GX2 / B4) + the weekly owner email and the
 * Lafka → Insights page that render it: small-number honesty ("3 of 9", no
 * trend under 30 visits a week), the zero-visits warning, and the sentences
 * the owner actually reads.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Lafka_Email_Weekly_Insights;
use Lafka_Insights_Narrative as Narrative;
use Lafka_Insights_Page;
use LafkaPlugin\Tests\Unit\Support\InsightsHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wc-email-stub.php';
require_once __DIR__ . '/Support/InsightsHarness.php';
require_once dirname( __DIR__, 2 ) . '/incl/insights/class-lafka-email-weekly-insights.php';
require_once dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-insights-page.php';

final class InsightsNarrativeTest extends TestCase {

	use InsightsHarness;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_insights();
		foreach ( array( 'esc_html', 'esc_attr', 'esc_url', 'esc_html__' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://shop.example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
	}

	protected function tearDown(): void {
		$this->tear_down_insights();
		parent::tearDown();
	}

	/** @return array<string,mixed> */
	private function week(): array {
		return array(
			'days'            => 7,
			'funnel'          => array(
				'visit'       => 112,
				'menu'        => 31,
				'product'     => 20,
				'add'         => 9,
				'cart'        => 9,
				'checkout'    => 3,
				'pay_attempt' => 2,
				'order'       => 1,
			),
			'leak'            => array(
				'from' => 'cart',
				'to'   => 'checkout',
				'lost' => 6,
				'of'   => 9,
			),
			'abandon'         => array(
				'outside_delivery_zone' => 4,
				'none'                  => 3,
				'store_closed'          => 1,
			),
			'closed_visits'   => 14,
			'closed_hour_dow' => array(
				'0-10' => 9,
				'6-22' => 5,
			),
			'items'           => array(
				'42' => array( 'name' => 'Veggie Wrap', 'views' => 22, 'adds' => 0, 'orders' => 0 ),
				'7'  => array( 'name' => 'Garden Salad', 'views' => 4, 'adds' => 0, 'orders' => 0 ),
				'9'  => array( 'name' => 'Pizza', 'views' => 30, 'adds' => 5, 'orders' => 2 ),
			),
			'search_zero'     => array( 'gluten free' => 3 ),
			'pay_fail'        => array( 'avs' => 2 ),
			'prev'            => array( 'visit' => 100, 'order' => 1 ),
		);
	}

	public function test_share_is_a_count_below_twenty_and_a_percentage_above(): void {
		$this->assertSame( '3 of 9', Narrative::share( 3, 9 ) );
		$this->assertSame( '19 of 19', Narrative::share( 19, 19 ) );
		$this->assertSame( '33%', Narrative::share( 10, 30 ) );
		$this->assertSame( '0', Narrative::share( 0, 0 ) );
	}

	public function test_ratio_never_reads_as_more_than_everything(): void {
		$this->assertSame( '—', Narrative::ratio( 4, 2 ), 'Ordered 4 of added 2 is not a conversion rate.' );
		$this->assertSame( '—', Narrative::ratio( 1, 0 ) );
		$this->assertSame( '2 of 4', Narrative::ratio( 2, 4 ) );
		$this->assertSame( '25%', Narrative::ratio( 5, 20 ) );
	}

	public function test_partial_coverage_is_said_and_suppresses_trends(): void {
		$this->options['date_format'] = 'Y-m-d';
		$week                         = $this->week();
		$week['since']                = '2026-09-22';
		$week['coverage_days']        = 3;

		$lines = Narrative::build( $week );

		$this->assertSame( 'Insights has been collecting since 2026-09-22, so this covers 3 days.', $lines[0] );
		foreach ( $lines as $line ) {
			$this->assertStringNotContainsString( 'previous period', $line );
		}

		$lines = Narrative::build( array( 'days' => 7, 'since' => '2026-09-24', 'coverage_days' => 1, 'funnel' => array( 'visit' => 0 ) ) );
		$this->assertStringContainsString( 'No visits were recorded in the last 1 day.', $lines[1] );
	}

	public function test_insights_page_never_shows_a_ratio_above_everything(): void {
		$this->options['date_format']  = 'Y-m-d';
		$this->options['start_of_week'] = 1;
		$report                         = $this->week();
		$report['days']                 = 30;
		$report['since']                = '2026-09-20';
		$report['coverage_days']        = 5;
		// The reported case: an item ordered more often than it was added.
		$report['items']                = array( '9' => array( 'name' => 'Marinara Pie', 'views' => 0, 'adds' => 2, 'orders' => 4 ) );
		$report['source']               = array( 'typein' => 1 );
		$report['orders_by_source']     = array( 'typein' => 1 );
		$report['wc_orders_by_source']  = array( 'typein' => 9 );

		ob_start();
		( new \ReflectionClass( Lafka_Insights_Page::class ) )->newInstanceWithoutConstructor()->render_report( $report );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Collecting since 2026-09-20. Figures below cover the 5 days Insights has been collecting in this range.', $html );
		$this->assertStringContainsString( '<td>Marinara Pie</td><td>0</td><td>2</td><td>4</td><td>—</td>', $html );
		$this->assertStringNotContainsString( '<td>4</td><td>2 of 2</td>', $html, 'The old min(ordered, added) clamp is gone.' );
		$this->assertStringContainsString( 'All orders by source (WooCommerce), incl. before Insights started', $html );
		$this->assertMatchesRegularExpression( '#<td>Direct \(typed in / bookmark\)</td><td>1</td><td>1</td><td>1 of 1</td>#', $html, 'Visits vs orders are both Insights visits.' );
		$this->assertMatchesRegularExpression( '#<td>Direct \(typed in / bookmark\)</td><td>9</td></tr>#', $html, 'WooCommerce orders sit in their own labelled table.' );
	}

	public function test_the_week_in_plain_english(): void {
		$lines = Narrative::build( $this->week() );

		$this->assertSame( '112 people visited; 31 opened the menu; 9 added food to a cart; 3 reached checkout; 1 ordered.', $lines[0] );
		$this->assertContains( 'The biggest drop was between cart and checkout: 6 of 9 left.', $lines );
		$this->assertContains( '14 visits came while you were closed, mostly Sunday 10:00–11:00.', $lines );
		$this->assertContains( '“Veggie Wrap” was viewed 22 times but never ordered.', $lines );
		$this->assertNotContains( '“Garden Salad” was viewed 4 times but never ordered.', $lines, 'Under 5 views is not a signal.' );
		$this->assertContains( 'People searched the menu for “gluten free” and found nothing (3 times).', $lines );
		$this->assertContains( '2 card payments were declined for an address mismatch (AVS) — consider relaxing the AVS rules in your payment gateway.', $lines );
	}

	public function test_abandon_sentence_uses_counts_under_twenty(): void {
		$lines = Narrative::build( $this->week() );
		$this->assertContains( '8 visitors left with food in the cart. Most common reason: Outside the delivery area (4 of 8).', $lines );
	}

	public function test_zero_visits_says_tracking_may_be_broken(): void {
		$lines = Narrative::build( array( 'days' => 7, 'funnel' => array( 'visit' => 0 ) ) );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'No visits were recorded in the last 7 days.', $lines[0] );
		$this->assertStringContainsString( 'Tracking may be broken', $lines[0] );
		$this->assertStringContainsString( 'Site Health', $lines[0] );
	}

	public function test_trends_need_thirty_visits_a_week_in_both_periods(): void {
		$week = $this->week();
		$this->assertContains( 'Visits were up 12% on the previous period.', Narrative::build( $week ) );

		$week['funnel']['visit'] = 29;
		$week['prev']['visit']   = 10;
		foreach ( Narrative::build( $week ) as $line ) {
			$this->assertStringNotContainsString( 'previous period', $line, 'No trend on thin data.' );
		}
		$this->assertFalse( Narrative::trend_allowed( 100, 40, 14 ), '40 visits over two weeks is 20 a week.' );
		$this->assertTrue( Narrative::trend_allowed( 120, 60, 14 ) );
	}

	public function test_weekly_email_sends_the_sentences_to_the_admin_by_default(): void {
		$this->options['admin_email'] = 'owner@example.test';
		Functions\when( 'do_action' )->justReturn( null );
		$email = new Lafka_Email_Weekly_Insights();

		$this->assertSame( 'lafka_weekly_insights', $email->id );
		$this->assertFalse( $email->customer_email );
		$this->assertSame( 'owner@example.test', $email->get_recipient() );

		$email->report = $this->week();
		$html          = $email->get_content_html();
		$this->assertStringContainsString( '112 people visited', $html );
		$this->assertStringContainsString( 'admin.php?page=lafka-insights', $html );
		$this->assertStringContainsString( '- The biggest drop was between cart and checkout: 6 of 9 left.', $email->get_content_plain() );
		$this->assertTrue( $email->trigger( $this->week() ) );
	}

	public function test_insights_page_explains_how_to_switch_the_module_on(): void {
		$this->options['lafka'] = array( 'insights' => 'disabled' );
		\Lafka_Options::flush();
		$this->can_manage = true;

		ob_start();
		( new \ReflectionClass( Lafka_Insights_Page::class ) )->newInstanceWithoutConstructor()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Insights is off.', $html );
		$this->assertStringContainsString( 'admin.php?page=lafka-modules', $html );
		$this->assertSame( array(), $this->wpdb->reads, 'No report queries while the module is off.' );
	}

	public function test_insights_page_renders_every_section_with_the_leak_called_out(): void {
		$report                     = $this->week();
		$report['device']           = array( 'mobile' => 80, 'desktop' => 32 );
		$report['source']           = array( 'typein' => 70, 'organic' => 42 );
		$report['orders_by_source'] = array( 'typein' => 1 );
		$report['hour_dow']         = array( '5-18' => 12 );
		$report['search']           = array( 'pizza' => 7 );
		$this->options['start_of_week'] = 1;

		ob_start();
		( new \ReflectionClass( Lafka_Insights_Page::class ) )->newInstanceWithoutConstructor()->render_report( $report );
		$html = (string) ob_get_clean();

		foreach ( array( 'Funnel', 'Why no order', 'Items', 'Menu search', 'Audience', 'Payment health', 'Viewed but not bought' ) as $heading ) {
			$this->assertStringContainsString( $heading, $html );
		}
		$this->assertStringContainsString( 'lafka-insights__leak', $html );
		$this->assertStringContainsString( '← biggest leak', $html );
		$this->assertStringContainsString( '6 of 9', $html, 'Small numbers are shown as counts.' );
		$this->assertStringContainsString( '<svg', $html, 'Inline SVG bars, no chart library.' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'Veggie Wrap — 22 views, 0 orders', $html );
	}
}
