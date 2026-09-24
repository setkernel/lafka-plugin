<?php
/**
 * Required-field validation for checkbox / radio add-on groups.
 *
 * Regression: an operator-precedence bug let a crafted POST of `[""]` (or
 * whitespace) satisfy a required group. A required group passes only when at
 * least one real selection is present.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/Stubs/wp-error-class.php';
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Engine_Field_List;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use WP_Error;

	require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

	final class AddonFieldListRequiredValidationTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) ) );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @return array<string, array{0: mixed, 1: bool, 2: bool}> [ submitted value, required, passes ]
		 */
		public static function submissions(): array {
			return array(
				'one selection'                 => array( array( 'extra-cheese' ), true, true ),
				'radio string value'            => array( 'extra-cheese', true, true ),
				'one real value among empties'  => array( array( '', 'extra-cheese', '' ), true, true ),
				'array of only empty strings'   => array( array( '' ), true, false ),
				'array of only whitespace'      => array( array( '   ', "\t" ), true, false ),
				'empty array'                   => array( array(), true, false ),
				'empty string'                  => array( '', true, false ),
				'null'                          => array( null, true, false ),
				'optional group left empty'     => array( array(), false, true ),
			);
		}

		#[DataProvider( 'submissions' )]
		public function test_required_group_needs_a_real_selection( $value, bool $required, bool $passes ): void {
			$field = new Lafka_Engine_Field_List(
				array(
					'name'     => 'Toppings',
					'required' => $required ? 1 : 0,
					'options'  => array( array( 'id' => 'extra-cheese', 'label' => 'Extra Cheese' ) ),
				),
				$value
			);

			$result = $field->validate();

			if ( $passes ) {
				self::assertTrue( $result );
			} else {
				self::assertInstanceOf( WP_Error::class, $result );
				self::assertSame( 'lafka_addon_required', $result->code );
			}
		}
	}
}
