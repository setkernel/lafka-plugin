<?php
/**
 * Deterministic demo-store fixture data for `wp lafka seed-demo` (NX1-09a).
 *
 * Pure data — no side effects, no WordPress calls — so it is trivially
 * unit-testable and byte-for-byte reproducible across every seed run. The
 * seeder (incl/cli/class-lafka-cli-seed-demo.php) is the only consumer; it
 * turns this array into a browsable, orderable minimal restaurant.
 *
 * Hard rules baked into this data (see SeedDemoFixtureTest):
 *   - NEUTRAL, generic content only: no operator brand, city, vanity domain,
 *     phone or signature dish. This store ships inside a public, sellable
 *     plugin and must read as "any restaurant".
 *   - FAKE-but-valid business info: reserved 555-01xx phone, example.com email,
 *     plausible geo. Never real operator NAP data.
 *   - Deterministic slugs / SKUs / prices so a re-seed is a no-op and e2e
 *     assertions can hard-code expected values.
 *
 * @package Lafka\Plugin\CLI
 * @since   9.37.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * Always-open 7-day order-hours schedule. The order-gate decoder
 * (Lafka_Order_Hours::is_shop_open) reads a JSON array indexed 0..6 ==
 * Monday..Sunday, each element carrying a `periods` list of { start, end }.
 * An end of "00:00" is normalised to "24:00" by the gate, so a single
 * 00:00→00:00 period means "open all day" — the store is always open now.
 */
$lafka_seed_demo_open_schedule = array();
for ( $lafka_seed_demo_day = 0; $lafka_seed_demo_day < 7; $lafka_seed_demo_day++ ) {
	$lafka_seed_demo_open_schedule[] = array(
		'periods' => array(
			array(
				'start' => '00:00',
				'end'   => '00:00',
			),
		),
	);
}
$lafka_seed_demo_open_schedule_json = function_exists( 'wp_json_encode' )
	? wp_json_encode( $lafka_seed_demo_open_schedule )
	: json_encode( $lafka_seed_demo_open_schedule ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure fixture data; WP unavailable in unit tests.

return array(

	// ── Business info (fake but schema-valid), written to lafka_business_* ──
	'business'     => array(
		'lafka_business_name'           => 'Demo Restaurant',
		'lafka_business_street'         => '123 Example St',
		'lafka_business_city'           => 'Example City',
		'lafka_business_region'         => 'CA',
		'lafka_business_postal'         => '12345',
		'lafka_business_country'        => 'US',
		'lafka_business_phone_e164'     => '+15555550100',
		'lafka_business_phone_display'  => '+1 (555) 555-0100',
		'lafka_business_email'          => 'demo@example.com',
		'lafka_business_geo_lat'        => '44.65',
		'lafka_business_geo_lng'        => '-63.57',
		'lafka_business_price_range'    => '$$',
		'lafka_business_business_type'  => 'Restaurant, LocalBusiness, FoodEstablishment',
		'lafka_business_cuisines'       => 'Pizza, Salads, Fast Food',
		'lafka_business_payment_methods' => 'Cash, Credit Card',
		'lafka_business_hours_mon'      => '00:00-23:59',
		'lafka_business_hours_tue'      => '00:00-23:59',
		'lafka_business_hours_wed'      => '00:00-23:59',
		'lafka_business_hours_thu'      => '00:00-23:59',
		'lafka_business_hours_fri'      => '00:00-23:59',
		'lafka_business_hours_sat'      => '00:00-23:59',
		'lafka_business_hours_sun'      => '00:00-23:59',
	),

	// ── Order hours: open now, every day (lafka_order_hours_options) ──
	'order_hours'  => array(
		'lafka_order_hours_schedule'                       => $lafka_seed_demo_open_schedule_json,
		'lafka_order_hours_force_override_check'           => false,
		'lafka_order_hours_force_override_status'          => '',
		'lafka_order_hours_holidays_calendar'              => '',
		'lafka_order_hours_closed_stores_message_enabled'  => false,
	),

	// ── Feature flags merged into the 'lafka' option so the gates fire ──
	'flags'        => array(
		'order_hours'    => 'enabled',
		'shipping_areas' => 'enabled',
	),

	// ── Product categories (neutral names) ──
	'categories'   => array(
		array(
			'slug'        => 'pizzas',
			'name'        => 'Pizzas',
			'description' => 'Hand-stretched pizzas baked to order.',
		),
		array(
			'slug'        => 'sides',
			'name'        => 'Sides',
			'description' => 'Shareable starters and sides.',
		),
		array(
			'slug'        => 'salads',
			'name'        => 'Salads',
			'description' => 'Fresh, crisp salads.',
		),
		array(
			'slug'        => 'drinks',
			'name'        => 'Drinks',
			'description' => 'Cold drinks to go with your meal.',
		),
	),

	// ── 12 products across the 4 categories ──
	'products'     => array(

		// Pizzas — variable (Small / Medium / Large).
		array(
			'slug'              => 'margherita-pizza',
			'name'              => 'Margherita Pizza',
			'sku'               => 'demo-margherita-pizza',
			'category'          => 'pizzas',
			'type'              => 'variable',
			'short_description' => 'Tomato, mozzarella and basil.',
			'description'       => 'A classic pizza with tomato sauce, melted mozzarella and fresh basil.',
			'attributes'        => array( 'Size' => array( 'Small', 'Medium', 'Large' ) ),
			'variations'        => array(
				array(
					'Size' => 'Small',
					'price' => '9.99',
				),
				array(
					'Size' => 'Medium',
					'price' => '12.99',
				),
				array(
					'Size' => 'Large',
					'price' => '15.99',
				),
			),
		),
		array(
			'slug'              => 'pepperoni-pizza',
			'name'              => 'Pepperoni Pizza',
			'sku'               => 'demo-pepperoni-pizza',
			'category'          => 'pizzas',
			'type'              => 'variable',
			'short_description' => 'Tomato, mozzarella and pepperoni.',
			'description'       => 'Tomato sauce and mozzarella topped with slices of pepperoni.',
			'attributes'        => array( 'Size' => array( 'Small', 'Medium', 'Large' ) ),
			'variations'        => array(
				array(
					'Size' => 'Small',
					'price' => '10.99',
				),
				array(
					'Size' => 'Medium',
					'price' => '13.99',
				),
				array(
					'Size' => 'Large',
					'price' => '16.99',
				),
			),
		),
		array(
			'slug'              => 'veggie-pizza',
			'name'              => 'Veggie Pizza',
			'sku'               => 'demo-veggie-pizza',
			'category'          => 'pizzas',
			'type'              => 'variable',
			'short_description' => 'Peppers, mushrooms, onions and olives.',
			'description'       => 'A garden pizza loaded with peppers, mushrooms, onions and olives.',
			'attributes'        => array( 'Size' => array( 'Small', 'Medium', 'Large' ) ),
			'variations'        => array(
				array(
					'Size' => 'Small',
					'price' => '10.49',
				),
				array(
					'Size' => 'Medium',
					'price' => '13.49',
				),
				array(
					'Size' => 'Large',
					'price' => '16.49',
				),
			),
		),

		// Sides — simple.
		array(
			'slug'              => 'garlic-bread',
			'name'              => 'Garlic Bread',
			'sku'               => 'demo-garlic-bread',
			'category'          => 'sides',
			'type'              => 'simple',
			'price'             => '4.99',
			'short_description' => 'Warm bread with garlic butter.',
			'description'       => 'Oven-baked bread brushed with garlic butter.',
		),
		array(
			'slug'              => 'french-fries',
			'name'              => 'French Fries',
			'sku'               => 'demo-french-fries',
			'category'          => 'sides',
			'type'              => 'simple',
			'price'             => '3.99',
			'short_description' => 'Crispy golden fries.',
			'description'       => 'A generous portion of crispy golden fries.',
		),
		array(
			'slug'              => 'onion-rings',
			'name'              => 'Onion Rings',
			'sku'               => 'demo-onion-rings',
			'category'          => 'sides',
			'type'              => 'simple',
			'price'             => '4.49',
			'short_description' => 'Battered and fried onion rings.',
			'description'       => 'Thick-cut onion rings in a crunchy batter.',
		),

		// Salads — simple.
		array(
			'slug'              => 'garden-salad',
			'name'              => 'Garden Salad',
			'sku'               => 'demo-garden-salad',
			'category'          => 'salads',
			'type'              => 'simple',
			'price'             => '7.99',
			'short_description' => 'Mixed greens with vinaigrette.',
			'description'       => 'Mixed greens, tomato and cucumber with a light vinaigrette.',
		),
		array(
			'slug'              => 'caesar-salad',
			'name'              => 'Caesar Salad',
			'sku'               => 'demo-caesar-salad',
			'category'          => 'salads',
			'type'              => 'simple',
			'price'             => '8.49',
			'short_description' => 'Romaine, croutons and dressing.',
			'description'       => 'Crisp romaine with croutons and Caesar dressing.',
		),
		array(
			'slug'              => 'greek-salad',
			'name'              => 'Greek Salad',
			'sku'               => 'demo-greek-salad',
			'category'          => 'salads',
			'type'              => 'simple',
			'price'             => '8.99',
			'short_description' => 'Tomato, cucumber, olives and feta.',
			'description'       => 'Tomato, cucumber, red onion, olives and feta cheese.',
		),

		// Drinks — simple.
		array(
			'slug'              => 'cola',
			'name'              => 'Cola',
			'sku'               => 'demo-cola',
			'category'          => 'drinks',
			'type'              => 'simple',
			'price'             => '1.99',
			'short_description' => 'Chilled cola.',
			'description'       => 'A chilled can of cola.',
		),
		array(
			'slug'              => 'orange-juice',
			'name'              => 'Orange Juice',
			'sku'               => 'demo-orange-juice',
			'category'          => 'drinks',
			'type'              => 'simple',
			'price'             => '2.49',
			'short_description' => 'Freshly squeezed orange juice.',
			'description'       => 'A glass of freshly squeezed orange juice.',
		),
		array(
			'slug'              => 'bottled-water',
			'name'              => 'Bottled Water',
			'sku'               => 'demo-bottled-water',
			'category'          => 'drinks',
			'type'              => 'simple',
			'price'             => '1.49',
			'short_description' => 'Still spring water.',
			'description'       => 'A bottle of still spring water.',
		),
	),

	// ── Addon groups (2 pricing strategies), assigned to the pizza category ──
	'addon_groups' => array(
		array(
			'slug'           => 'demo-extra-toppings',
			'title'          => 'Demo Extra Toppings',
			'category'       => 'pizzas',
			'priority'       => '10',
			'product_addons' => array(
				array(
					'name'                     => 'Extra Toppings',
					'limit'                    => 0,
					'description'              => 'Add your favourite toppings.',
					'type'                     => 'checkbox',
					'position'                 => 0,
					'required'                 => 0,
					'variations'               => 0,
					'attribute'                => 0,
					'options'                  => array(
						array(
							'id'       => 'demo-topping-cheese',
							'label'    => 'Extra Cheese',
							'image'    => '',
							'price'    => '1.50',
							'default'  => '',
							'min'      => '',
							'max'      => '',
							'included' => true,
						),
						array(
							'id'       => 'demo-topping-mushrooms',
							'label'    => 'Mushrooms',
							'image'    => '',
							'price'    => '1.00',
							'default'  => '',
							'min'      => '',
							'max'      => '',
							'included' => true,
						),
						array(
							'id'       => 'demo-topping-olives',
							'label'    => 'Olives',
							'image'    => '',
							'price'    => '1.00',
							'default'  => '',
							'min'      => '',
							'max'      => '',
							'included' => true,
						),
						array(
							'id'       => 'demo-topping-peppers',
							'label'    => 'Peppers',
							'image'    => '',
							'price'    => '1.00',
							'default'  => '',
							'min'      => '',
							'max'      => '',
							'included' => true,
						),
					),
					'pricing_mode'             => 'flat_per_option',
					'options_source'           => 'manual',
					'options_source_attribute' => '',
					'included_size_slugs'      => array(),
					'group_flat_price'         => '',
					'group_size_prices'        => array(),
					'schema_version'           => 2,
				),
			),
		),
		array(
			'slug'           => 'demo-make-it-a-combo',
			'title'          => 'Demo Make It a Combo',
			'category'       => 'pizzas',
			'priority'       => '20',
			'product_addons' => array(
				array(
					'name'                     => 'Make It a Combo',
					'limit'                    => 0,
					'description'              => 'Add a side and a drink for one flat price.',
					'type'                     => 'radiobutton',
					'position'                 => 1,
					'required'                 => 0,
					'variations'               => 0,
					'attribute'                => 0,
					// flat_group stores the group_flat_price on every option (what
					// Lafka_Flat_Group_Pricing::expand() writes at admin save-time);
					// the cart reads per-option price, so leaving these empty makes
					// the combo silently free. Seed the expanded shape directly.
					'options'                  => array(
						array(
							'id'       => 'demo-combo-fries-drink',
							'label'    => 'Add Fries & a Drink',
							'image'    => '',
							'price'    => '2.50',
							'default'  => '',
							'min'      => '',
							'max'      => '',
							'included' => true,
						),
						array(
							'id'       => 'demo-combo-salad-drink',
							'label'    => 'Add a Salad & a Drink',
							'image'    => '',
							'price'    => '2.50',
							'default'  => '',
							'min'      => '',
							'max'      => '',
							'included' => true,
						),
					),
					'pricing_mode'             => 'flat_group',
					'options_source'           => 'manual',
					'options_source_attribute' => '',
					'included_size_slugs'      => array(),
					'group_flat_price'         => '2.50',
					'group_size_prices'        => array(),
					'schema_version'           => 2,
				),
			),
		),
	),

	// ── One branch term ──
	'branch'       => array(
		'slug' => 'main-branch',
		'name' => 'Main Branch',
		'meta' => array(
			'lafka_branch_order_type' => 'delivery',
			'lafka_branch_address'    => '123 Example St, Example City',
			'lafka_branch_timezone'   => 'default',
		),
	),

	// ── One shipping-area CPT with a square polygon around the fake centre ──
	'area'         => array(
		'slug'       => 'demo-delivery-zone',
		'title'      => 'Demo Delivery Zone',
		'lat'        => '44.65',
		'lng'        => '-63.57',
		'half_delta' => '0.05',
	),

	// ── /menu/ page so the theme's page-menu.php template flow resolves ──
	'page_menu'    => array(
		'slug'  => 'menu',
		'title' => 'Menu',
	),
);
