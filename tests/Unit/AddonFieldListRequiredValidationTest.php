<?php
/**
 * v8.12.6: required-field validation in Lafka_Product_Addon_Field_List.
 *
 * Locks the operator-precedence fix: the original
 *   `if ( ! $this->value || ( is_array(...) && sizeof(...) ) == 0 )`
 * had a misplaced parenthesis. The `== 0` was OUTSIDE the inner parens,
 * so the expression evaluated to `bool == 0` — always false on a non-empty
 * array. Required-field validation was bypassable by submitting `[""]` or
 * `[0]` from a crafted POST.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

// Stub WP_Error in the GLOBAL namespace — referenced as `\WP_Error` from the
// (un-namespaced) plugin source. Bracketed namespace syntax is the only way
// to mix global + namespaced declarations in the same file.
namespace {
	if ( ! class_exists( '\WP_Error' ) ) {
		class WP_Error { // phpcs:ignore
			public string $code;
			public string $message;
			public function __construct( $code = '', $message = '' ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Engine_Field_List;
	use PHPUnit\Framework\TestCase;
	use WP_Error;

	require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonFieldListRequiredValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_field( $value, bool $required = true ): Lafka_Engine_Field_List {
		$addon = array(
			'name'     => 'Toppings',
			'required' => $required ? 1 : 0,
			'options'  => array(),
		);
		return new Lafka_Engine_Field_List( $addon, $value );
	}

	public function test_required_passes_with_one_selection(): void {
		$field  = $this->make_field( array( 'extra-cheese' ) );
		$result = $field->validate();
		self::assertTrue( $result );
	}

	public function test_required_passes_with_string_value(): void {
		$field  = $this->make_field( 'extra-cheese' );
		$result = $field->validate();
		self::assertTrue( $result );
	}

	/**
	 * THE BUG. Submitting `[""]` (empty string in array) used to pass the
	 * required check because the operator-precedence error short-circuited
	 * the array-empty branch. Fix must reject this.
	 */
	public function test_required_rejects_array_with_only_empty_strings(): void {
		$field  = $this->make_field( array( '' ) );
		$result = $field->validate();
		self::assertInstanceOf( 'WP_Error', $result, 'Array of empties must fail required validation.' );
	}

	public function test_required_rejects_array_with_only_whitespace(): void {
		$field  = $this->make_field( array( '   ', "\t" ) );
		$result = $field->validate();
		self::assertInstanceOf( 'WP_Error', $result );
	}

	public function test_required_rejects_truly_empty_array(): void {
		$field  = $this->make_field( array() );
		$result = $field->validate();
		self::assertInstanceOf( 'WP_Error', $result );
	}

	public function test_required_rejects_empty_string(): void {
		$field  = $this->make_field( '' );
		$result = $field->validate();
		self::assertInstanceOf( 'WP_Error', $result );
	}

	public function test_required_rejects_null(): void {
		$field  = $this->make_field( null );
		$result = $field->validate();
		self::assertInstanceOf( 'WP_Error', $result );
	}

	public function test_not_required_passes_on_empty(): void {
		$field  = $this->make_field( array(), false );
		$result = $field->validate();
		self::assertTrue( $result );
	}

	public function test_required_with_mixed_array_of_one_real_and_empties_passes(): void {
		// As long as ONE real value is present, required is satisfied.
		$field  = $this->make_field( array( '', 'extra-cheese', '' ) );
		$result = $field->validate();
		self::assertTrue( $result );
	}
}

} // end namespace LafkaPlugin\Tests\Unit
