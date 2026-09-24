<?php
/**
 * Nutrition facts product panel: what the panel submits is what gets saved,
 * and saves that did not come from the panel (Quick Edit, REST, bulk edit,
 * programmatic) leave the stored nutrition untouched — the v9.7.13 data-loss
 * bug wiped every field on such saves.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Nutrition_Admin;
use Lafka_Nutrition_Config;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once dirname( __DIR__, 2 ) . '/incl/nutrition/includes/class-lafka-nutrition-config.php';
require_once dirname( __DIR__, 2 ) . '/incl/nutrition/admin/class-lafka-nutrition-admin.php';

final class NutritionTest extends TestCase {

	/** @var array<string, mixed> */
	private array $saved_fields;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->saved_fields = Lafka_Nutrition_Config::$nutrition_meta_fields;
		$_POST              = array();

		$escape = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->init_fields();
	}

	protected function tearDown(): void {
		Lafka_Nutrition_Config::$nutrition_meta_fields = $this->saved_fields;
		( new ReflectionProperty( Lafka_Nutrition_Config::class, 'initialized' ) )->setValue( null, false );
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function init_fields(): void {
		( new ReflectionProperty( Lafka_Nutrition_Config::class, 'initialized' ) )->setValue( null, false );
		Lafka_Nutrition_Config::init_fields();
	}

	/**
	 * A WC_Product double recording meta writes.
	 *
	 * @param array<string, string> $meta Existing product meta.
	 */
	private static function product( array $meta = array() ): object {
		return new class( $meta ) {
			/** @var array<string, string> */
			public array $written = array();
			public bool $saved    = false;
			public function __construct( private array $meta ) {}
			public function get_meta( $key ) {
				return $this->meta[ $key ] ?? '';
			}
			public function update_meta_data( $key, $value ) {
				$this->written[ $key ] = $value;
			}
			public function save() {
				$this->saved = true;
			}
		};
	}

	/**
	 * Render the panel for $product and return the form fields a browser
	 * would submit ( name => value ), plus the input elements themselves.
	 *
	 * @return array{0: array<string, string>, 1: \DOMNodeList}
	 */
	private function submit_panel( object $product ): array {
		$GLOBALS['post'] = (object) array( 'ID' => 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		ob_start();
		( new Lafka_Nutrition_Admin() )->panel();
		$html = (string) ob_get_clean();

		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR );
		$inputs = $dom->getElementsByTagName( 'input' );
		$fields = array();
		foreach ( $inputs as $input ) {
			$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
		}
		unset( $GLOBALS['post'] );

		return array( $fields, $inputs );
	}

	public function test_panel_submission_round_trips_every_field(): void {
		$stored = array(
			'_lafka_nutrition_energy'  => '650',
			'_lafka_nutrition_protein' => '28.3',
			'_lafka_product_allergens' => 'Milk, Eggs',
		);
		list( $fields, $inputs ) = $this->submit_panel( self::product( $stored ) );

		// The operator edits one value and saves the product editor.
		$fields['_lafka_nutrition_protein'] = '30';
		$_POST                              = $fields;
		$product                            = self::product( $stored );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		( new Lafka_Nutrition_Admin() )->process_meta_box( 42 );

		self::assertTrue( $product->saved );
		self::assertSame( '650', $product->written['_lafka_nutrition_energy'] );
		self::assertSame( '30', $product->written['_lafka_nutrition_protein'] );
		self::assertSame( '', $product->written['_lafka_nutrition_salt'] );
		self::assertSame( 'Milk, Eggs', $product->written['_lafka_product_allergens'] );

		// Nutrition values are numeric, non-negative inputs.
		$non_numeric = array();
		foreach ( $inputs as $input ) {
			$name = ltrim( $input->getAttribute( 'name' ), '_' );
			if ( isset( Lafka_Nutrition_Config::$nutrition_meta_fields[ $name ] )
				&& ( 'number' !== $input->getAttribute( 'type' ) || '0' !== $input->getAttribute( 'min' ) ) ) {
				$non_numeric[] = $name;
			}
		}
		self::assertSame( array(), $non_numeric );
	}

	public function test_saves_not_made_from_the_panel_leave_nutrition_untouched(): void {
		// e.g. Quick Edit: WooCommerce fires woocommerce_process_product_meta
		// without any nutrition fields in the request.
		$_POST   = array( '_regular_price' => '12.00' );
		$product = self::product( array( '_lafka_nutrition_energy' => '650' ) );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		( new Lafka_Nutrition_Admin() )->process_meta_box( 42 );

		self::assertSame( array(), $product->written );
		self::assertFalse( $product->saved );
	}

	public function test_save_for_a_missing_product_is_a_no_op(): void {
		$_POST = array( '_lafka_nutrition_panel_present' => '1' );
		Functions\when( 'wc_get_product' )->justReturn( false );

		( new Lafka_Nutrition_Admin() )->process_meta_box( 42 );

		$this->addToAssertionCount( 1 ); // Reaching here means no fatal on a null product.
	}

	public function test_fields_are_filterable_for_other_markets(): void {
		$received = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $fields ) use ( &$received ) {
				if ( 'lafka_nutrition_meta_fields' === $hook ) {
					$received = $fields;
					// A non-US reference intake, and an extra field.
					$fields['lafka_nutrition_sodium']['DI']     = 2.0;
					$fields['lafka_nutrition_calcium']          = $fields['lafka_nutrition_salt'];
					$fields['lafka_nutrition_calcium']['label'] = 'Calcium (mg)';
				}
				return $fields;
			}
		);
		$this->init_fields();

		self::assertCount( 10, $received, 'The filter receives the full default map.' );
		self::assertSame( 2.0, Lafka_Nutrition_Config::$nutrition_meta_fields['lafka_nutrition_sodium']['DI'] );

		// A field added through the filter is rendered and saved like the built-ins.
		list( $fields ) = $this->submit_panel( self::product() );
		self::assertArrayHasKey( '_lafka_nutrition_calcium', $fields );
		$_POST   = array_merge( $fields, array( '_lafka_nutrition_calcium' => '120' ) );
		$product = self::product();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		( new Lafka_Nutrition_Admin() )->process_meta_box( 42 );
		self::assertSame( '120', $product->written['_lafka_nutrition_calcium'] );
	}
}
