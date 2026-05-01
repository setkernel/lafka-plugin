<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ClosedStoreMessageTest extends TestCase {
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php' );
	}

	public function test_card_uses_structured_class_names(): void {
		// New BEM-style class names so the theme has stable hooks to target.
		$this->assertStringContainsString( 'lafka-store-closed-card', $this->src );
		$this->assertStringContainsString( 'lafka-store-closed-card__title', $this->src );
		$this->assertStringContainsString( 'lafka-store-closed-card__subtitle', $this->src );
	}

	public function test_default_title_when_operator_message_unset(): void {
		// Source-grep: must have an i18n-wrapped default title literal.
		$this->assertMatchesRegularExpression(
			"/__\(\s*['\"]Closed right now['\"]\s*,\s*['\"]lafka['\"]/",
			$this->src,
			'default title must be __("Closed right now", "lafka")'
		);
	}

	public function test_subtitle_uses_format_helper(): void {
		// The subtitle line must call format_next_open_time_human() from L2-T1.
		$this->assertStringContainsString( 'format_next_open_time_human', $this->src );
	}

	public function test_subtitle_uses_opens_translatable_string(): void {
		// "Opens %s" wrapped with sprintf + __() so translators can localize.
		$this->assertMatchesRegularExpression(
			"/__\(\s*['\"]Opens %s['\"]\s*,\s*['\"]lafka['\"]/",
			$this->src
		);
	}

	public function test_countdown_markup_preserved(): void {
		// Existing operator-toggleable countdown stays inside the card.
		$this->assertStringContainsString( 'lafka_order_hours_message_countdown', $this->src );
		$this->assertStringContainsString( 'lafka_order_hours_countdown', $this->src );
	}

	public function test_method_no_longer_early_returns_on_empty_message(): void {
		// Slice the echo_closed_store_message method body and assert it does
		// NOT have an `if ( empty( ... 'lafka_order_hours_message' ... ) ) { return; }`
		// pattern that would skip rendering when the operator message is unset.
		$method_pos = strpos( $this->src, 'function echo_closed_store_message' );
		$this->assertNotFalse( $method_pos );
		$method_slice = substr( $this->src, $method_pos, 2500 );
		$this->assertStringNotContainsString(
			"if ( isset( self::\$lafka_order_hours_options['lafka_order_hours_message'] ) && self::\$lafka_order_hours_options['lafka_order_hours_message'] ) {",
			$method_slice,
			'must NOT gate rendering on operator message presence — render always with computed default'
		);
	}
}
