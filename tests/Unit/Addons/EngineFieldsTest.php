<?php
declare(strict_types=1);

// Stub WP_Error in the GLOBAL namespace — referenced as `\WP_Error` from
// the (un-namespaced) engine field source. Bracketed namespace syntax is
// the only way to mix global + namespaced declarations in one file.
namespace {
	if ( ! class_exists( '\WP_Error' ) ) {
		class WP_Error { // phpcs:ignore
			public string $code;
			public string $message;
			public function __construct( $code = '', $message = '' ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
			}
			public function get_error_message() {
				return $this->message;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit\Addons {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Engine_Field_Factory;
	use Lafka_Engine_Field_List;
	use Lafka_Engine_Field_Textarea;
	use PHPUnit\Framework\TestCase;
	use WP_Error;

	require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

	/**
	 * Field validation + cart_item_data shape contracts. These are the heart
	 * of the cart layer — display renders inputs whose names match
	 * get_field_name(), cart parses $_POST under the same names, and these
	 * classes turn that into the cart_item['addons'][] entries that feed
	 * pricing + order writes.
	 */
	final class EngineFieldsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'sanitize_text_field' )->returnArg( 1 );
			Functions\when( 'sanitize_title' )->alias(
				fn( $s ) => strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $s ) )
			);
			Functions\when( 'wp_kses_post' )->returnArg( 1 );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		// ---------------------------------------------------------------
		// Factory
		// ---------------------------------------------------------------

		public function test_factory_creates_list_for_checkbox(): void {
			$field = Lafka_Engine_Field_Factory::create( array( 'type' => 'checkbox' ), array() );
			self::assertInstanceOf( Lafka_Engine_Field_List::class, $field );
		}

		public function test_factory_creates_list_for_radiobutton(): void {
			$field = Lafka_Engine_Field_Factory::create( array( 'type' => 'radiobutton' ), array() );
			self::assertInstanceOf( Lafka_Engine_Field_List::class, $field );
		}

		public function test_factory_creates_textarea(): void {
			$field = Lafka_Engine_Field_Factory::create( array( 'type' => 'textarea' ), array() );
			self::assertInstanceOf( Lafka_Engine_Field_Textarea::class, $field );
		}

		public function test_factory_returns_null_for_unknown_type(): void {
			self::assertNull( Lafka_Engine_Field_Factory::create( array( 'type' => 'mystery' ), array() ) );
		}

		// ---------------------------------------------------------------
		// List field validation
		// ---------------------------------------------------------------

		public function test_list_required_with_empty_returns_error(): void {
			$field  = new Lafka_Engine_Field_List(
				array( 'type' => 'checkbox', 'name' => 'Toppings', 'required' => 1, 'options' => array() ),
				array()
			);
			$result = $field->validate();
			self::assertInstanceOf( WP_Error::class, $result );
		}

		public function test_list_required_with_empty_string_array_returns_error(): void {
			$field  = new Lafka_Engine_Field_List(
				array( 'type' => 'checkbox', 'name' => 'Toppings', 'required' => 1, 'options' => array() ),
				array( '' )
			);
			$result = $field->validate();
			self::assertInstanceOf( WP_Error::class, $result );
		}

		public function test_list_required_with_value_passes(): void {
			$field = new Lafka_Engine_Field_List(
				array( 'type' => 'checkbox', 'name' => 'Toppings', 'required' => 1, 'options' => array() ),
				array( 'cheese' )
			);
			self::assertTrue( $field->validate() );
		}

		public function test_list_limit_violation_returns_error(): void {
			$field  = new Lafka_Engine_Field_List(
				array( 'type' => 'checkbox', 'name' => 'Toppings', 'limit' => 2, 'options' => array() ),
				array( 'a', 'b', 'c' )
			);
			$result = $field->validate();
			self::assertInstanceOf( WP_Error::class, $result );
		}

		// ---------------------------------------------------------------
		// List field cart-item-data
		// ---------------------------------------------------------------

		public function test_list_cart_item_data_matches_by_id(): void {
			$field = new Lafka_Engine_Field_List(
				array(
					'type'    => 'checkbox',
					'name'    => 'Toppings',
					'options' => array(
						array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.50' ),
						array( 'id' => 'opt-2', 'label' => 'Olives', 'price' => '0.75' ),
					),
				),
				array( 'opt-1' )
			);

			$data = $field->get_cart_item_data();
			self::assertCount( 1, $data );
			self::assertSame( 'Toppings', $data[0]['name'] );
			self::assertSame( 'Cheese', $data[0]['value'] );
			self::assertSame( '1.50', $data[0]['price'] );
		}

		public function test_list_cart_item_data_matches_by_legacy_label_slug(): void {
			$field = new Lafka_Engine_Field_List(
				array(
					'type'    => 'checkbox',
					'name'    => 'Toppings',
					'options' => array(
						array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.50' ),
					),
				),
				array( 'cheese' )
			);

			$data = $field->get_cart_item_data();
			self::assertCount( 1, $data );
			self::assertSame( 'Cheese', $data[0]['value'] );
		}

		public function test_list_cart_item_data_returns_false_on_empty(): void {
			$field = new Lafka_Engine_Field_List(
				array( 'type' => 'checkbox', 'options' => array() ),
				array()
			);
			self::assertFalse( $field->get_cart_item_data() );
		}

		// ---------------------------------------------------------------
		// Textarea
		// ---------------------------------------------------------------

		public function test_textarea_required_with_empty_value_returns_error(): void {
			$field  = new Lafka_Engine_Field_Textarea(
				array(
					'type'     => 'textarea',
					'name'     => 'Engraving',
					'required' => 1,
					'options'  => array(
						array( 'id' => 'opt-1', 'label' => 'Top line' ),
					),
				),
				array( 'opt-1' => '' )
			);
			$result = $field->validate();
			self::assertInstanceOf( WP_Error::class, $result );
		}

		public function test_textarea_min_length_returns_error(): void {
			$field  = new Lafka_Engine_Field_Textarea(
				array(
					'type'    => 'textarea',
					'name'    => 'Engraving',
					'options' => array(
						array( 'id' => 'opt-1', 'label' => 'Top line', 'min' => 5 ),
					),
				),
				array( 'opt-1' => 'hi' )
			);
			$result = $field->validate();
			self::assertInstanceOf( WP_Error::class, $result );
		}

		public function test_textarea_max_length_returns_error(): void {
			$field  = new Lafka_Engine_Field_Textarea(
				array(
					'type'    => 'textarea',
					'name'    => 'Engraving',
					'options' => array(
						array( 'id' => 'opt-1', 'label' => 'Top line', 'max' => 5 ),
					),
				),
				array( 'opt-1' => 'this is too long' )
			);
			$result = $field->validate();
			self::assertInstanceOf( WP_Error::class, $result );
		}

		public function test_textarea_cart_item_data_uses_id_key(): void {
			$field = new Lafka_Engine_Field_Textarea(
				array(
					'type'    => 'textarea',
					'name'    => 'Engraving',
					'options' => array(
						array( 'id' => 'opt-1', 'label' => 'Top line', 'price' => '5.00' ),
					),
				),
				array( 'opt-1' => 'Happy Birthday' )
			);

			$data = $field->get_cart_item_data();
			self::assertCount( 1, $data );
			self::assertSame( 'Happy Birthday', $data[0]['value'] );
			self::assertSame( '5.00', $data[0]['price'] );
		}

		public function test_textarea_cart_item_data_falls_back_to_legacy_label_key(): void {
			$field = new Lafka_Engine_Field_Textarea(
				array(
					'type'    => 'textarea',
					'name'    => 'Engraving',
					'options' => array(
						array( 'id' => 'opt-1', 'label' => 'Top line', 'price' => '5.00' ),
					),
				),
				array( 'top-line' => 'Happy Birthday' )
			);

			$data = $field->get_cart_item_data();
			self::assertCount( 1, $data );
			self::assertSame( 'Happy Birthday', $data[0]['value'] );
		}

		public function test_textarea_skips_options_with_empty_value(): void {
			$field = new Lafka_Engine_Field_Textarea(
				array(
					'type'    => 'textarea',
					'name'    => 'Engraving',
					'options' => array(
						array( 'id' => 'opt-1', 'label' => 'Top line', 'price' => '5.00' ),
						array( 'id' => 'opt-2', 'label' => 'Bottom line', 'price' => '5.00' ),
					),
				),
				array( 'opt-1' => 'Happy Birthday', 'opt-2' => '' )
			);

			$data = $field->get_cart_item_data();
			self::assertCount( 1, $data );
		}
	}
}
