<?php
/**
 * KDSEmailIdentityTest — locks down the per-status configuration tuples on
 * each Lafka_KDS_Email_* subclass.
 *
 * The base-class refactor (v9.7.0) collapsed ~300 lines of duplicated
 * trigger/content/store code into Lafka_KDS_Email_Base, leaving each subclass
 * with only its constant config: id, title, template, status-transition
 * hook(s), sent_flag dedupe key, and copy strings.
 *
 * If a future edit copies a subclass to add a fifth status email and
 * forgets to bump `sent_flag_meta_key`, all that subclass's emails would
 * silently dedupe against the original's flag — a bug invisible until
 * staff notice an email never arrives. This test catches that drift.
 *
 * @package Lafka_Kitchen_Display
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Stubs/wc-email-stub.php';
require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-email-base.php';
require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-email-accepted.php';
require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-email-preparing.php';
require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-email-ready.php';
require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-email-rejected.php';

final class KDSEmailIdentityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Subclass constructors call __() and add_action(); the latter is a no-op
		// stub from bootstrap, the former we make a passthrough here.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string, 3:string}>
	 */
	public function emailIdentityProvider(): array {
		// [class, expected id, expected sent_flag_meta_key, expected template_html]
		return array(
			'accepted'  => array( \Lafka_KDS_Email_Accepted::class, 'lafka_kds_order_accepted', '_lafka_kds_accepted_email_sent', 'customer-order-accepted.php' ),
			'preparing' => array( \Lafka_KDS_Email_Preparing::class, 'lafka_kds_order_preparing', '_lafka_kds_preparing_email_sent', 'customer-order-preparing.php' ),
			'ready'     => array( \Lafka_KDS_Email_Ready::class, 'lafka_kds_order_ready', '_lafka_kds_ready_email_sent', 'customer-order-ready.php' ),
			'rejected'  => array( \Lafka_KDS_Email_Rejected::class, 'lafka_kds_order_rejected', '_lafka_kds_rejected_email_sent', 'customer-order-rejected.php' ),
		);
	}

	/**
	 * @dataProvider emailIdentityProvider
	 */
	public function test_email_identity( string $class, string $expected_id, string $expected_flag, string $expected_template ): void {
		$email = new $class();
		$this->assertSame( $expected_id, $email->id, "id mismatch for {$class}" );
		$this->assertSame( $expected_template, $email->template_html, "template_html mismatch for {$class}" );
		$this->assertSame( $expected_template, $email->template_plain, "template_plain mismatch for {$class}" );

		$prop = ( new ReflectionClass( $email ) )->getProperty( 'sent_flag_meta_key' );
		$this->assertSame( $expected_flag, $prop->getValue( $email ), "sent_flag_meta_key mismatch for {$class}" );
	}

	public function test_each_subclass_has_unique_dedupe_flag(): void {
		$flags = array();
		foreach ( $this->emailIdentityProvider() as $row ) {
			$flags[] = $row[2];
		}
		$this->assertSame(
			count( $flags ),
			count( array_unique( $flags ) ),
			'Two subclasses share the same sent_flag_meta_key — emails would silently dedupe against each other.'
		);
	}

	public function test_each_subclass_has_unique_id(): void {
		$ids = array();
		foreach ( $this->emailIdentityProvider() as $row ) {
			$ids[] = $row[1];
		}
		$this->assertSame(
			count( $ids ),
			count( array_unique( $ids ) ),
			'Two subclasses share the same email id — WC would treat them as one email.'
		);
	}
}
