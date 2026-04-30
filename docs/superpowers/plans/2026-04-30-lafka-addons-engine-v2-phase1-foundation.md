# Lafka Addons Engine v2 — Phase 1 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundational data, pricing, and source layers of the new Lafka addon engine — interfaces, value objects, repository, four pricing strategies, two options sources, and a versioned migration — without changing operator-visible behavior.

**Architecture:** New code lives at `incl/addons/engine/` alongside the existing `incl/addons/admin/` and `incl/addons/includes/` directories. The legacy code paths continue to handle all admin and runtime behavior in Phase 1. The new engine is dormant — accessible via PHP API and tested in isolation, but not yet wired into the cart/admin lifecycle. Phase 2 wires the admin form to the new engine; Phase 3 wires the cart.

**Tech Stack:** PHP 8.1+, PHPUnit 9.6, Brain Monkey for WP function stubs, WPCS for lint. The engine is plain PHP — no new Composer deps. Follows the existing Lafka_* class naming convention.

---

## Spec reference

Spec: `docs/superpowers/specs/2026-04-30-lafka-addons-engine-v2-design.md`

This plan implements **Phase 1 — Foundation** from that spec. Acceptance criteria from the spec are validated by Task 19 (final integration test).

## File structure

All new code in `incl/addons/engine/`. Test files in `tests/Unit/Addons/`.

| File | Responsibility |
|---|---|
| `incl/addons/engine/lafka-addons-engine-bootstrap.php` | Autoloader registration, hook bootstrap, version constant |
| `incl/addons/engine/interfaces/interface-pricing-strategy.php` | Contract every pricing mode implements |
| `incl/addons/engine/interfaces/interface-options-source.php` | Contract every options source implements |
| `incl/addons/engine/data/class-addon-option.php` | Value object for one option |
| `incl/addons/engine/data/class-addon-group.php` | Value object for one addon group |
| `incl/addons/engine/data/class-addon-repository.php` | Reads/writes `_product_addons` post meta |
| `incl/addons/engine/data/class-addon-schema.php` | Schema version constants + canonical defaults |
| `incl/addons/engine/pricing/abstract-pricing-strategy.php` | Common helpers for strategies |
| `incl/addons/engine/pricing/class-flat-group-pricing.php` | `pricing_mode = flat_group` |
| `incl/addons/engine/pricing/class-flat-per-option-pricing.php` | `pricing_mode = flat_per_option` |
| `incl/addons/engine/pricing/class-flat-per-size-pricing.php` | `pricing_mode = flat_per_size` |
| `incl/addons/engine/pricing/class-matrix-pricing.php` | `pricing_mode = matrix` |
| `incl/addons/engine/pricing/class-pricing-resolver.php` | Picks strategy + applies expansion |
| `incl/addons/engine/sources/abstract-options-source.php` | Common helpers for sources |
| `incl/addons/engine/sources/class-manual-source.php` | Operator-typed options |
| `incl/addons/engine/sources/class-attribute-source.php` | Options sourced from a WC attribute taxonomy |
| `incl/addons/engine/migrations/abstract-migration.php` | Migration interface |
| `incl/addons/engine/migrations/class-migration-v8-13-0.php` | Adds `pricing_mode` + `options_source` + `schema_version` to existing groups |
| `incl/addons/engine/migrations/class-upgrader.php` | Discovers + runs pending migrations |
| `tests/Unit/Addons/AddonOptionTest.php` | Tests for Addon_Option |
| `tests/Unit/Addons/AddonGroupTest.php` | Tests for Addon_Group |
| `tests/Unit/Addons/AddonRepositoryTest.php` | Round-trip CRUD tests |
| `tests/Unit/Addons/Pricing/FlatGroupPricingTest.php` | flat_group strategy tests |
| `tests/Unit/Addons/Pricing/FlatPerOptionPricingTest.php` | flat_per_option strategy tests |
| `tests/Unit/Addons/Pricing/FlatPerSizePricingTest.php` | flat_per_size strategy tests |
| `tests/Unit/Addons/Pricing/MatrixPricingTest.php` | matrix strategy tests |
| `tests/Unit/Addons/Pricing/PricingResolverTest.php` | Resolver picks the right strategy |
| `tests/Unit/Addons/Sources/ManualSourceTest.php` | manual source tests |
| `tests/Unit/Addons/Sources/AttributeSourceTest.php` | attribute source tests |
| `tests/Unit/Addons/Migrations/MigrationV8130Test.php` | Migration is idempotent + safe |

---

### Task 1: Bootstrap + autoloader

**Files:**
- Create: `incl/addons/engine/lafka-addons-engine-bootstrap.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/AddonEngineBootstrapTest.php`:

```php
<?php
/**
 * Phase 1 Task 1: bootstrap loads the engine namespace and exposes the version constant.
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonEngineBootstrapTest extends TestCase {

    public function test_engine_version_constant_defined(): void {
        self::assertTrue( defined( 'LAFKA_ADDONS_ENGINE_VERSION' ) );
        self::assertSame( 2, LAFKA_ADDONS_ENGINE_VERSION );
    }

    public function test_engine_path_constant_defined(): void {
        self::assertTrue( defined( 'LAFKA_ADDONS_ENGINE_PATH' ) );
        self::assertDirectoryExists( LAFKA_ADDONS_ENGINE_PATH );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Volumes/HomeX/raghavgupta/MacMini-Macbook-Shared/lafka/lafka-plugin-addons-rewrite
vendor/bin/phpunit --filter AddonEngineBootstrap
```

Expected: FAIL — bootstrap file does not exist.

- [ ] **Step 3: Write minimal implementation**

Create `incl/addons/engine/lafka-addons-engine-bootstrap.php`:

```php
<?php
/**
 * Lafka Addons Engine v2 — Bootstrap
 *
 * Loads the new engine alongside the legacy addon system. Phase 1: dormant.
 * Phase 2: admin form rewires to it. Phase 3: cart/display rewires to it.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

// Engine schema version. Bumped when migrations land.
if ( ! defined( 'LAFKA_ADDONS_ENGINE_VERSION' ) ) {
    define( 'LAFKA_ADDONS_ENGINE_VERSION', 2 );
}

if ( ! defined( 'LAFKA_ADDONS_ENGINE_PATH' ) ) {
    define( 'LAFKA_ADDONS_ENGINE_PATH', __DIR__ );
}

// Autoload the engine's classes. The engine intentionally does not bootstrap
// hooks at file-load time — Phase 2+ controllers will instantiate what they
// need via the public Lafka_Addons_Engine facade (added in Task 18).
require_once __DIR__ . '/interfaces/interface-pricing-strategy.php';
require_once __DIR__ . '/interfaces/interface-options-source.php';
require_once __DIR__ . '/data/class-addon-schema.php';
require_once __DIR__ . '/data/class-addon-option.php';
require_once __DIR__ . '/data/class-addon-group.php';
require_once __DIR__ . '/data/class-addon-repository.php';
require_once __DIR__ . '/pricing/abstract-pricing-strategy.php';
require_once __DIR__ . '/pricing/class-flat-group-pricing.php';
require_once __DIR__ . '/pricing/class-flat-per-option-pricing.php';
require_once __DIR__ . '/pricing/class-flat-per-size-pricing.php';
require_once __DIR__ . '/pricing/class-matrix-pricing.php';
require_once __DIR__ . '/pricing/class-pricing-resolver.php';
require_once __DIR__ . '/sources/abstract-options-source.php';
require_once __DIR__ . '/sources/class-manual-source.php';
require_once __DIR__ . '/sources/class-attribute-source.php';
require_once __DIR__ . '/migrations/abstract-migration.php';
require_once __DIR__ . '/migrations/class-migration-v8-13-0.php';
require_once __DIR__ . '/migrations/class-upgrader.php';
```

- [ ] **Step 4: Stub every required file so the bootstrap loads**

Create empty stubs (just the opening `<?php` and an `if ( ! defined('ABSPATH') ) exit;` line) for every file the bootstrap requires. Each file gets fleshed out in subsequent tasks. The test only verifies the bootstrap loads without fatal.

```bash
for f in interfaces/interface-pricing-strategy.php interfaces/interface-options-source.php data/class-addon-schema.php data/class-addon-option.php data/class-addon-group.php data/class-addon-repository.php pricing/abstract-pricing-strategy.php pricing/class-flat-group-pricing.php pricing/class-flat-per-option-pricing.php pricing/class-flat-per-size-pricing.php pricing/class-matrix-pricing.php pricing/class-pricing-resolver.php sources/abstract-options-source.php sources/class-manual-source.php sources/class-attribute-source.php migrations/abstract-migration.php migrations/class-migration-v8-13-0.php migrations/class-upgrader.php; do
  mkdir -p "incl/addons/engine/$(dirname $f)"
  echo "<?php
defined( 'ABSPATH' ) || exit;
" > "incl/addons/engine/$f"
done
```

- [ ] **Step 5: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter AddonEngineBootstrap
```

Expected: PASS — 2 tests.

- [ ] **Step 6: Commit**

```bash
git add incl/addons/engine/ tests/Unit/Addons/AddonEngineBootstrapTest.php
git commit -m "feat(addons): bootstrap + autoloader for engine v2 [phase 1 task 1]"
```

---

### Task 2: Schema constants + canonical defaults

**Files:**
- Modify: `incl/addons/engine/data/class-addon-schema.php`
- Test: `tests/Unit/Addons/AddonSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/AddonSchemaTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Lafka_Addon_Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonSchemaTest extends TestCase {

    public function test_pricing_mode_constants_defined(): void {
        self::assertSame( 'flat_group', Lafka_Addon_Schema::PRICING_FLAT_GROUP );
        self::assertSame( 'flat_per_option', Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION );
        self::assertSame( 'flat_per_size', Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE );
        self::assertSame( 'matrix', Lafka_Addon_Schema::PRICING_MATRIX );
        self::assertSame( 'legacy', Lafka_Addon_Schema::PRICING_LEGACY );
    }

    public function test_source_constants_defined(): void {
        self::assertSame( 'manual', Lafka_Addon_Schema::SOURCE_MANUAL );
        self::assertSame( 'attribute', Lafka_Addon_Schema::SOURCE_ATTRIBUTE );
    }

    public function test_default_group_returns_canonical_shape(): void {
        $defaults = Lafka_Addon_Schema::default_group();

        self::assertSame( 'legacy', $defaults['pricing_mode'] );
        self::assertSame( 'manual', $defaults['options_source'] );
        self::assertSame( 2, $defaults['schema_version'] );
        self::assertSame( '', $defaults['name'] );
        self::assertSame( 0, $defaults['variations'] );
        self::assertSame( array(), $defaults['options'] );
        self::assertSame( array(), $defaults['included_size_slugs'] );
        self::assertSame( '', $defaults['group_flat_price'] );
        self::assertSame( array(), $defaults['group_size_prices'] );
    }

    public function test_default_option_returns_canonical_shape(): void {
        $defaults = Lafka_Addon_Schema::default_option();

        self::assertArrayHasKey( 'id', $defaults );
        self::assertSame( '', $defaults['label'] );
        self::assertSame( '', $defaults['price'] );
        self::assertSame( '', $defaults['default'] );
        self::assertTrue( $defaults['included'] );
    }

    public function test_pricing_modes_returns_all_known_modes(): void {
        $modes = Lafka_Addon_Schema::pricing_modes();
        self::assertCount( 5, $modes );
        self::assertContains( 'flat_group', $modes );
        self::assertContains( 'matrix', $modes );
        self::assertContains( 'legacy', $modes );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter AddonSchemaTest
```

Expected: FAIL — `Lafka_Addon_Schema` class not found.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/data/class-addon-schema.php`:

```php
<?php
/**
 * Lafka Addon Schema — version constants and canonical default shapes.
 *
 * Single source of truth for what an addon group / option SHOULD look like
 * after migration v8.13.0. The repository validates against these defaults
 * when reading old data; the admin form uses them when constructing new
 * groups; tests use them as the canonical contract.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addon_Schema {

    /**
     * Schema version stamped onto every group after migration.
     */
    const SCHEMA_VERSION = 2;

    /**
     * Pricing mode constants. Each maps to a Lafka_Pricing_Strategy implementation.
     */
    const PRICING_FLAT_GROUP      = 'flat_group';
    const PRICING_FLAT_PER_OPTION = 'flat_per_option';
    const PRICING_FLAT_PER_SIZE   = 'flat_per_size';
    const PRICING_MATRIX          = 'matrix';
    const PRICING_LEGACY          = 'legacy';

    /**
     * Options source constants.
     */
    const SOURCE_MANUAL    = 'manual';
    const SOURCE_ATTRIBUTE = 'attribute';

    /**
     * Canonical default shape of a fresh addon group at schema v2.
     *
     * @return array
     */
    public static function default_group(): array {
        return array(
            'name'                     => '',
            'limit'                    => 0,
            'description'              => '',
            'type'                     => 'checkbox',
            'position'                 => 0,
            'required'                 => 0,
            'variations'               => 0,
            'attribute'                => 0,
            'options'                  => array(),

            // v2 fields
            'pricing_mode'             => self::PRICING_LEGACY,
            'options_source'           => self::SOURCE_MANUAL,
            'options_source_attribute' => '',
            'included_size_slugs'      => array(),
            'group_flat_price'         => '',
            'group_size_prices'        => array(),
            'schema_version'           => self::SCHEMA_VERSION,
        );
    }

    /**
     * Canonical default shape of a fresh option.
     *
     * @return array
     */
    public static function default_option(): array {
        return array(
            'id'       => self::generate_id(),
            'label'    => '',
            'image'    => '',
            'price'    => '',
            'default'  => '',
            'min'      => '',
            'max'      => '',
            'included' => true,
        );
    }

    /**
     * @return string[]
     */
    public static function pricing_modes(): array {
        return array(
            self::PRICING_FLAT_GROUP,
            self::PRICING_FLAT_PER_OPTION,
            self::PRICING_FLAT_PER_SIZE,
            self::PRICING_MATRIX,
            self::PRICING_LEGACY,
        );
    }

    /**
     * @return string[]
     */
    public static function options_sources(): array {
        return array( self::SOURCE_MANUAL, self::SOURCE_ATTRIBUTE );
    }

    /**
     * Generate a stable UUID-like ID for a new option. Falls back to a
     * deterministic hash if WP's wp_generate_uuid4() is unavailable (test
     * harness without WP loaded).
     */
    private static function generate_id(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        return sprintf( '%08x-%04x-%04x-%04x-%012x',
            mt_rand( 0, 0xffffffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0x4000, 0x4fff ),
            mt_rand( 0x8000, 0xbfff ),
            mt_rand( 0, 0xffffffffffff )
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter AddonSchemaTest
```

Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/data/class-addon-schema.php tests/Unit/Addons/AddonSchemaTest.php
git commit -m "feat(addons): schema constants + canonical defaults [phase 1 task 2]"
```

---

### Task 3: Addon_Option value object

**Files:**
- Modify: `incl/addons/engine/data/class-addon-option.php`
- Test: `tests/Unit/Addons/AddonOptionTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/AddonOptionTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Lafka_Addon_Option;
use Lafka_Addon_Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonOptionTest extends TestCase {

    public function test_from_array_normalizes_against_defaults(): void {
        $option = Lafka_Addon_Option::from_array( array(
            'label' => 'Cheese',
            'price' => '1.50',
        ) );

        self::assertSame( 'Cheese', $option->label );
        self::assertSame( '1.50', $option->price );
        self::assertNotEmpty( $option->id );
        self::assertTrue( $option->included );
    }

    public function test_to_array_round_trips(): void {
        $original = Lafka_Addon_Option::from_array( array(
            'id'       => 'fixed-id',
            'label'    => 'Mushroom',
            'price'    => '0.75',
            'default'  => '0',
            'included' => false,
        ) );
        $array = $original->to_array();

        self::assertSame( 'fixed-id', $array['id'] );
        self::assertSame( 'Mushroom', $array['label'] );
        self::assertSame( '0.75', $array['price'] );
        self::assertFalse( $array['included'] );
    }

    public function test_unknown_keys_dropped_on_normalization(): void {
        $option = Lafka_Addon_Option::from_array( array(
            'label'        => 'Bacon',
            'rogue_field'  => 'should-be-stripped',
        ) );
        $array = $option->to_array();

        self::assertArrayNotHasKey( 'rogue_field', $array );
    }

    public function test_with_price_returns_new_instance_unchanged_original(): void {
        $original = Lafka_Addon_Option::from_array( array( 'label' => 'Cheese', 'price' => '1.00' ) );
        $updated  = $original->with_price( '2.00' );

        self::assertSame( '1.00', $original->price );
        self::assertSame( '2.00', $updated->price );
        self::assertNotSame( $original, $updated );
    }

    public function test_price_can_be_nested_array(): void {
        $option = Lafka_Addon_Option::from_array( array(
            'label' => 'Cheese',
            'price' => array( 'pa_size' => array( 'small' => '1.00', 'large' => '2.00' ) ),
        ) );

        self::assertIsArray( $option->price );
        self::assertSame( '2.00', $option->price['pa_size']['large'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter AddonOptionTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/data/class-addon-option.php`:

```php
<?php
/**
 * Addon_Option — immutable-ish value object for one option within an addon group.
 *
 * Public properties are read directly. Mutation via with_* methods returns a
 * new instance, leaving the original unchanged. Round-trips lossless via
 * from_array() / to_array() against the canonical schema.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

final class Lafka_Addon_Option {

    public string $id;
    public string $label;
    public string $image;
    /** @var string|array Scalar for flat pricing, nested array for matrix. */
    public $price;
    public string $default;
    public string $min;
    public string $max;
    public bool $included;

    private function __construct() {}

    public static function from_array( array $data ): self {
        $defaults = Lafka_Addon_Schema::default_option();
        $merged   = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

        $option           = new self();
        $option->id       = (string) $merged['id'];
        $option->label    = (string) $merged['label'];
        $option->image    = (string) $merged['image'];
        $option->price    = $merged['price']; // mixed
        $option->default  = (string) $merged['default'];
        $option->min      = (string) $merged['min'];
        $option->max      = (string) $merged['max'];
        $option->included = (bool) $merged['included'];

        return $option;
    }

    public function to_array(): array {
        return array(
            'id'       => $this->id,
            'label'    => $this->label,
            'image'    => $this->image,
            'price'    => $this->price,
            'default'  => $this->default,
            'min'      => $this->min,
            'max'      => $this->max,
            'included' => $this->included,
        );
    }

    /**
     * @param string|array $price
     */
    public function with_price( $price ): self {
        $clone        = clone $this;
        $clone->price = $price;
        return $clone;
    }

    public function with_included( bool $included ): self {
        $clone           = clone $this;
        $clone->included = $included;
        return $clone;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter AddonOptionTest
```

Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/data/class-addon-option.php tests/Unit/Addons/AddonOptionTest.php
git commit -m "feat(addons): Addon_Option value object [phase 1 task 3]"
```

---

### Task 4: Addon_Group value object

**Files:**
- Modify: `incl/addons/engine/data/class-addon-group.php`
- Test: `tests/Unit/Addons/AddonGroupTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/AddonGroupTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Lafka_Addon_Group;
use Lafka_Addon_Option;
use Lafka_Addon_Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonGroupTest extends TestCase {

    public function test_from_array_normalizes_and_constructs_options(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'Toppings',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
            'options'      => array(
                array( 'label' => 'Cheese', 'price' => '1.00' ),
                array( 'label' => 'Mushroom', 'price' => '0.50' ),
            ),
        ) );

        self::assertSame( 'Toppings', $group->name );
        self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $group->pricing_mode );
        self::assertCount( 2, $group->options );
        self::assertContainsOnlyInstancesOf( Lafka_Addon_Option::class, $group->options );
        self::assertSame( 'Cheese', $group->options[0]->label );
    }

    public function test_legacy_data_preserved_when_no_pricing_mode(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'    => 'Old Group',
            'options' => array( array( 'label' => 'X', 'price' => '1.00' ) ),
        ) );

        self::assertSame( Lafka_Addon_Schema::PRICING_LEGACY, $group->pricing_mode );
        self::assertSame( Lafka_Addon_Schema::SOURCE_MANUAL, $group->options_source );
    }

    public function test_to_array_round_trips_options(): void {
        $original = Lafka_Addon_Group::from_array( array(
            'name'    => 'Group',
            'options' => array( array( 'id' => 'opt-1', 'label' => 'A', 'price' => '1.00' ) ),
        ) );
        $array = $original->to_array();

        self::assertSame( 'Group', $array['name'] );
        self::assertCount( 1, $array['options'] );
        self::assertSame( 'opt-1', $array['options'][0]['id'] );
    }

    public function test_with_options_returns_new_instance(): void {
        $original = Lafka_Addon_Group::from_array( array( 'name' => 'G', 'options' => array() ) );
        $new_options = array(
            Lafka_Addon_Option::from_array( array( 'label' => 'New' ) ),
        );
        $updated = $original->with_options( $new_options );

        self::assertCount( 0, $original->options );
        self::assertCount( 1, $updated->options );
        self::assertNotSame( $original, $updated );
    }

    public function test_uses_per_attribute_pricing_when_variations_set(): void {
        $with_variations = Lafka_Addon_Group::from_array( array(
            'name'       => 'G',
            'variations' => 1,
            'attribute'  => 5,
        ) );
        $without_variations = Lafka_Addon_Group::from_array( array( 'name' => 'G' ) );

        self::assertTrue( $with_variations->uses_per_attribute_pricing() );
        self::assertFalse( $without_variations->uses_per_attribute_pricing() );
    }

    public function test_size_terms_included_when_empty_means_all(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'                => 'G',
            'included_size_slugs' => array(),
        ) );

        self::assertTrue( $group->includes_size( 'small' ) );
        self::assertTrue( $group->includes_size( 'medium' ) );
    }

    public function test_size_terms_excluded_when_subset(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'                => 'G',
            'included_size_slugs' => array( 'medium', 'large' ),
        ) );

        self::assertFalse( $group->includes_size( 'small' ) );
        self::assertTrue( $group->includes_size( 'medium' ) );
        self::assertTrue( $group->includes_size( 'large' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter AddonGroupTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/data/class-addon-group.php`:

```php
<?php
/**
 * Addon_Group — immutable-ish value object for one addon group.
 *
 * Wraps the v2 `_product_addons[i]` shape. Round-trips lossless via
 * from_array() / to_array() against the canonical schema.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

final class Lafka_Addon_Group {

    public string $name;
    public int $limit;
    public string $description;
    public string $type;
    public int $position;
    public int $required;
    public int $variations;
    public int $attribute;
    /** @var Lafka_Addon_Option[] */
    public array $options;

    public string $pricing_mode;
    public string $options_source;
    public string $options_source_attribute;
    /** @var string[] */
    public array $included_size_slugs;
    public string $group_flat_price;
    /** @var array<string, string> */
    public array $group_size_prices;
    public int $schema_version;

    private function __construct() {}

    public static function from_array( array $data ): self {
        $defaults = Lafka_Addon_Schema::default_group();
        $merged   = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

        $group                           = new self();
        $group->name                     = (string) $merged['name'];
        $group->limit                    = (int) $merged['limit'];
        $group->description              = (string) $merged['description'];
        $group->type                     = (string) $merged['type'];
        $group->position                 = (int) $merged['position'];
        $group->required                 = (int) $merged['required'];
        $group->variations               = (int) $merged['variations'];
        $group->attribute                = (int) $merged['attribute'];
        $group->pricing_mode             = (string) $merged['pricing_mode'];
        $group->options_source           = (string) $merged['options_source'];
        $group->options_source_attribute = (string) $merged['options_source_attribute'];
        $group->included_size_slugs      = array_values( array_map( 'strval', (array) $merged['included_size_slugs'] ) );
        $group->group_flat_price         = (string) $merged['group_flat_price'];
        $group->group_size_prices        = (array) $merged['group_size_prices'];
        $group->schema_version           = (int) $merged['schema_version'];

        $group->options = array();
        foreach ( (array) $merged['options'] as $option_data ) {
            if ( ! is_array( $option_data ) ) {
                continue;
            }
            $group->options[] = Lafka_Addon_Option::from_array( $option_data );
        }

        return $group;
    }

    public function to_array(): array {
        return array(
            'name'                     => $this->name,
            'limit'                    => $this->limit,
            'description'              => $this->description,
            'type'                     => $this->type,
            'position'                 => $this->position,
            'required'                 => $this->required,
            'variations'               => $this->variations,
            'attribute'                => $this->attribute,
            'options'                  => array_map(
                static fn( Lafka_Addon_Option $o ) => $o->to_array(),
                $this->options
            ),
            'pricing_mode'             => $this->pricing_mode,
            'options_source'           => $this->options_source,
            'options_source_attribute' => $this->options_source_attribute,
            'included_size_slugs'      => $this->included_size_slugs,
            'group_flat_price'         => $this->group_flat_price,
            'group_size_prices'        => $this->group_size_prices,
            'schema_version'           => $this->schema_version,
        );
    }

    /**
     * @param Lafka_Addon_Option[] $options
     */
    public function with_options( array $options ): self {
        $clone          = clone $this;
        $clone->options = array_values( $options );
        return $clone;
    }

    public function with_pricing_mode( string $mode ): self {
        $clone               = clone $this;
        $clone->pricing_mode = $mode;
        return $clone;
    }

    public function uses_per_attribute_pricing(): bool {
        return 1 === $this->variations && $this->attribute > 0;
    }

    /**
     * Whether this group applies to a given size term slug.
     * Empty included_size_slugs = all sizes apply.
     */
    public function includes_size( string $size_slug ): bool {
        if ( empty( $this->included_size_slugs ) ) {
            return true;
        }
        return in_array( $size_slug, $this->included_size_slugs, true );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter AddonGroupTest
```

Expected: PASS — 7 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/data/class-addon-group.php tests/Unit/Addons/AddonGroupTest.php
git commit -m "feat(addons): Addon_Group value object [phase 1 task 4]"
```

---

### Task 5: Pricing strategy interface

**Files:**
- Modify: `incl/addons/engine/interfaces/interface-pricing-strategy.php`

- [ ] **Step 1: Define the contract (interface only — no test, this task is a typedef)**

Replace `incl/addons/engine/interfaces/interface-pricing-strategy.php`:

```php
<?php
/**
 * Lafka_Pricing_Strategy — contract every pricing mode implements.
 *
 * Lafka has four pricing modes (flat_group, flat_per_option, flat_per_size,
 * matrix) plus a legacy passthrough. Each mode is its own class with a
 * unique id() and a defined set of methods that the resolver / admin form /
 * cart math invoke generically.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

interface Lafka_Pricing_Strategy {

    /**
     * Strategy id matching Lafka_Addon_Schema::PRICING_* constants.
     */
    public function id(): string;

    /**
     * Operator-facing label (i18n-aware where applicable).
     */
    public function label(): string;

    /**
     * Apply this strategy to the group: take the canonical group data and
     * EXPAND any group-level price config into per-option prices, so
     * downstream readers (cart, display) see one consistent shape regardless
     * of mode.
     *
     * Mutation rules (returns a new Addon_Group, doesn't modify input):
     *   - flat_group         → every option's price = $group_flat_price scalar
     *   - flat_per_option    → no change (option prices are already per-option scalars)
     *   - flat_per_size      → every option's price = nested matrix from $group_size_prices
     *   - matrix             → no change (option prices are already nested matrices)
     *   - legacy             → no change (whatever is there)
     *
     * @param Lafka_Addon_Group $group
     * @return Lafka_Addon_Group
     */
    public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group;

    /**
     * Validate group data for this strategy. Returns array of error messages
     * (empty = valid).
     *
     * @param Lafka_Addon_Group $group
     * @return string[]
     */
    public function validate( Lafka_Addon_Group $group ): array;
}
```

- [ ] **Step 2: Run all tests to make sure nothing broke**

```bash
vendor/bin/phpunit
```

Expected: All previous tests still pass.

- [ ] **Step 3: Commit**

```bash
git add incl/addons/engine/interfaces/interface-pricing-strategy.php
git commit -m "feat(addons): pricing strategy interface [phase 1 task 5]"
```

---

### Task 6: Abstract pricing strategy base

**Files:**
- Modify: `incl/addons/engine/pricing/abstract-pricing-strategy.php`

- [ ] **Step 1: Implement the abstract base**

Replace `incl/addons/engine/pricing/abstract-pricing-strategy.php`:

```php
<?php
/**
 * Common scaffolding for pricing strategies. Concrete classes override id(),
 * label(), expand(), validate() — base provides shared validation helpers.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

abstract class Lafka_Abstract_Pricing_Strategy implements Lafka_Pricing_Strategy {

    /**
     * Resolve the taxonomy slug from $group->attribute (a WC attribute_taxonomy
     * ID). Returns empty string if WC is unavailable or attribute is invalid.
     */
    protected function resolve_taxonomy_slug( Lafka_Addon_Group $group ): string {
        if ( $group->attribute <= 0 ) {
            return '';
        }
        if ( ! function_exists( 'wc_attribute_taxonomy_name_by_id' ) ) {
            return '';
        }
        $slug = wc_attribute_taxonomy_name_by_id( $group->attribute );
        return is_string( $slug ) ? $slug : '';
    }

    /**
     * @return string[]
     */
    public function validate( Lafka_Addon_Group $group ): array {
        return array();
    }
}
```

- [ ] **Step 2: Run all tests to verify nothing broke**

```bash
vendor/bin/phpunit
```

Expected: previous tests still pass.

- [ ] **Step 3: Commit**

```bash
git add incl/addons/engine/pricing/abstract-pricing-strategy.php
git commit -m "feat(addons): abstract pricing strategy base [phase 1 task 6]"
```

---

### Task 7: Flat group pricing strategy

**Files:**
- Modify: `incl/addons/engine/pricing/class-flat-group-pricing.php`
- Test: `tests/Unit/Addons/Pricing/FlatGroupPricingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Pricing/FlatGroupPricingTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Group_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class FlatGroupPricingTest extends TestCase {

    public function test_id_and_label(): void {
        $strategy = new Lafka_Flat_Group_Pricing();
        self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $strategy->id() );
        self::assertNotEmpty( $strategy->label() );
    }

    public function test_expand_writes_group_flat_price_to_every_option(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'             => 'Toppings',
            'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
            'group_flat_price' => '1.50',
            'options'          => array(
                array( 'label' => 'Cheese', 'price' => '' ),
                array( 'label' => 'Mushroom', 'price' => '' ),
            ),
        ) );
        $strategy = new Lafka_Flat_Group_Pricing();
        $expanded = $strategy->expand( $group );

        self::assertSame( '1.50', $expanded->options[0]->price );
        self::assertSame( '1.50', $expanded->options[1]->price );
        // Original unchanged.
        self::assertSame( '', $group->options[0]->price );
    }

    public function test_validate_requires_non_empty_price(): void {
        $invalid = Lafka_Addon_Group::from_array( array(
            'name'             => 'G',
            'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
            'group_flat_price' => '',
            'options'          => array( array( 'label' => 'X' ) ),
        ) );
        $valid = Lafka_Addon_Group::from_array( array(
            'name'             => 'G',
            'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
            'group_flat_price' => '0.50',
            'options'          => array( array( 'label' => 'X' ) ),
        ) );

        $strategy = new Lafka_Flat_Group_Pricing();
        self::assertNotEmpty( $strategy->validate( $invalid ) );
        self::assertEmpty( $strategy->validate( $valid ) );
    }

    public function test_zero_price_is_allowed(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'             => 'G',
            'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
            'group_flat_price' => '0',
            'options'          => array( array( 'label' => 'X' ) ),
        ) );
        $strategy = new Lafka_Flat_Group_Pricing();
        self::assertEmpty( $strategy->validate( $group ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter FlatGroupPricing
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/pricing/class-flat-group-pricing.php`:

```php
<?php
/**
 * Flat group pricing — every option in the group costs the same fixed price
 * regardless of which option is selected or which size variation applies.
 *
 * Storage shape after expand():
 *   each option's price = $group_flat_price scalar
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Flat_Group_Pricing extends Lafka_Abstract_Pricing_Strategy {

    public function id(): string {
        return Lafka_Addon_Schema::PRICING_FLAT_GROUP;
    }

    public function label(): string {
        return __( 'Flat for whole group', 'lafka-plugin' );
    }

    public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
        $price          = $group->group_flat_price;
        $expanded_opts  = array();
        foreach ( $group->options as $option ) {
            $expanded_opts[] = $option->with_price( $price );
        }
        return $group->with_options( $expanded_opts );
    }

    public function validate( Lafka_Addon_Group $group ): array {
        $errors = array();
        if ( '' === trim( $group->group_flat_price ) ) {
            $errors[] = sprintf(
                /* translators: %s: group name */
                __( '"%s" requires a flat price.', 'lafka-plugin' ),
                $group->name
            );
        }
        return $errors;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter FlatGroupPricing
```

Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/pricing/class-flat-group-pricing.php tests/Unit/Addons/Pricing/FlatGroupPricingTest.php
git commit -m "feat(addons): flat group pricing strategy [phase 1 task 7]"
```

---

### Task 8: Flat per option pricing strategy

**Files:**
- Modify: `incl/addons/engine/pricing/class-flat-per-option-pricing.php`
- Test: `tests/Unit/Addons/Pricing/FlatPerOptionPricingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Pricing/FlatPerOptionPricingTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Per_Option_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class FlatPerOptionPricingTest extends TestCase {

    public function test_id_and_label(): void {
        $strategy = new Lafka_Flat_Per_Option_Pricing();
        self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $strategy->id() );
        self::assertNotEmpty( $strategy->label() );
    }

    public function test_expand_is_passthrough_for_already_scalar_prices(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'Toppings',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
            'options'      => array(
                array( 'label' => 'Cheese', 'price' => '1.00' ),
                array( 'label' => 'Truffle', 'price' => '3.00' ),
            ),
        ) );
        $strategy = new Lafka_Flat_Per_Option_Pricing();
        $expanded = $strategy->expand( $group );

        self::assertSame( '1.00', $expanded->options[0]->price );
        self::assertSame( '3.00', $expanded->options[1]->price );
    }

    public function test_validate_passes_when_all_options_have_a_price(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
            'options'      => array(
                array( 'label' => 'A', 'price' => '1.00' ),
                array( 'label' => 'B', 'price' => '0' ),
            ),
        ) );
        $strategy = new Lafka_Flat_Per_Option_Pricing();
        self::assertEmpty( $strategy->validate( $group ) );
    }

    public function test_validate_skips_excluded_options(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
            'options'      => array(
                array( 'label' => 'Included', 'price' => '1.00', 'included' => true ),
                array( 'label' => 'Excluded', 'price' => '', 'included' => false ),
            ),
        ) );
        $strategy = new Lafka_Flat_Per_Option_Pricing();
        self::assertEmpty( $strategy->validate( $group ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter FlatPerOptionPricing
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/pricing/class-flat-per-option-pricing.php`:

```php
<?php
/**
 * Flat per option pricing — each option has its own scalar price, same
 * across sizes. Matches the existing Lafka default behavior.
 *
 * Storage shape after expand():
 *   each option's price stays as the operator-entered scalar
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Flat_Per_Option_Pricing extends Lafka_Abstract_Pricing_Strategy {

    public function id(): string {
        return Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION;
    }

    public function label(): string {
        return __( 'Flat per option', 'lafka-plugin' );
    }

    public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
        // Per-option scalars are the canonical storage shape — no expansion.
        return $group;
    }

    public function validate( Lafka_Addon_Group $group ): array {
        // Empty prices on excluded options are fine; '' on an included option
        // is not strictly an error (treated as 0 by cart math) so we don't
        // raise a hard error here.
        return array();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter FlatPerOptionPricing
```

Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/pricing/class-flat-per-option-pricing.php tests/Unit/Addons/Pricing/FlatPerOptionPricingTest.php
git commit -m "feat(addons): flat per option pricing strategy [phase 1 task 8]"
```

---

### Task 9: Flat per size pricing strategy

**Files:**
- Modify: `incl/addons/engine/pricing/class-flat-per-size-pricing.php`
- Test: `tests/Unit/Addons/Pricing/FlatPerSizePricingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Pricing/FlatPerSizePricingTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Per_Size_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class FlatPerSizePricingTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'wc_attribute_taxonomy_name_by_id' )->alias(
            static fn( $id ) => 1 === (int) $id ? 'pa_size' : ''
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_id_and_label(): void {
        $strategy = new Lafka_Flat_Per_Size_Pricing();
        self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $strategy->id() );
        self::assertNotEmpty( $strategy->label() );
    }

    public function test_expand_writes_size_matrix_to_every_option(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'              => 'Toppings',
            'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
            'variations'        => 1,
            'attribute'         => 1,
            'group_size_prices' => array(
                'small'  => '0.50',
                'medium' => '1.00',
                'large'  => '1.50',
            ),
            'options' => array(
                array( 'label' => 'Cheese' ),
                array( 'label' => 'Mushroom' ),
            ),
        ) );
        $strategy = new Lafka_Flat_Per_Size_Pricing();
        $expanded = $strategy->expand( $group );

        $expected = array( 'pa_size' => array( 'small' => '0.50', 'medium' => '1.00', 'large' => '1.50' ) );
        self::assertSame( $expected, $expanded->options[0]->price );
        self::assertSame( $expected, $expanded->options[1]->price );
    }

    public function test_expand_returns_group_unchanged_if_taxonomy_unresolvable(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'              => 'Toppings',
            'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
            'variations'        => 1,
            'attribute'         => 999, // not 1 — taxonomy resolver returns ''
            'group_size_prices' => array( 'medium' => '1.00' ),
            'options'           => array( array( 'label' => 'X', 'price' => 'untouched' ) ),
        ) );
        $strategy = new Lafka_Flat_Per_Size_Pricing();
        $expanded = $strategy->expand( $group );

        self::assertSame( 'untouched', $expanded->options[0]->price );
    }

    public function test_validate_requires_at_least_one_size_price(): void {
        $invalid = Lafka_Addon_Group::from_array( array(
            'name'              => 'G',
            'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
            'variations'        => 1,
            'attribute'         => 1,
            'group_size_prices' => array(),
            'options'           => array( array( 'label' => 'X' ) ),
        ) );
        $valid = Lafka_Addon_Group::from_array( array(
            'name'              => 'G',
            'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
            'variations'        => 1,
            'attribute'         => 1,
            'group_size_prices' => array( 'medium' => '1.00' ),
            'options'           => array( array( 'label' => 'X' ) ),
        ) );

        $strategy = new Lafka_Flat_Per_Size_Pricing();
        self::assertNotEmpty( $strategy->validate( $invalid ) );
        self::assertEmpty( $strategy->validate( $valid ) );
    }

    public function test_validate_requires_variations_enabled(): void {
        $without_variations = Lafka_Addon_Group::from_array( array(
            'name'              => 'G',
            'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
            'variations'        => 0,
            'attribute'         => 1,
            'group_size_prices' => array( 'medium' => '1.00' ),
            'options'           => array( array( 'label' => 'X' ) ),
        ) );

        $strategy = new Lafka_Flat_Per_Size_Pricing();
        $errors = $strategy->validate( $without_variations );
        self::assertNotEmpty( $errors );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter FlatPerSizePricing
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/pricing/class-flat-per-size-pricing.php`:

```php
<?php
/**
 * Flat per size pricing — every option in the group costs the same per-size
 * price, but different sizes can have different prices.
 *
 * Example: small=$0.50, medium=$1.00, large=$1.50 — applies uniformly to
 * every topping in the group.
 *
 * Storage shape after expand():
 *   each option's price = nested matrix [taxonomy_slug => [size_slug => scalar]]
 *
 * Reuses the existing nested-array storage so cart math + display layer
 * (apply_attribute_specific_price, walk_to_scalar_price) need zero changes.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Flat_Per_Size_Pricing extends Lafka_Abstract_Pricing_Strategy {

    public function id(): string {
        return Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE;
    }

    public function label(): string {
        return __( 'Flat per size', 'lafka-plugin' );
    }

    public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
        $taxonomy = $this->resolve_taxonomy_slug( $group );
        if ( '' === $taxonomy || empty( $group->group_size_prices ) ) {
            return $group;
        }

        // Filter out sizes the operator deselected.
        $size_prices = array();
        foreach ( $group->group_size_prices as $size_slug => $price ) {
            if ( ! $group->includes_size( (string) $size_slug ) ) {
                continue;
            }
            $size_prices[ (string) $size_slug ] = (string) $price;
        }
        if ( empty( $size_prices ) ) {
            return $group;
        }

        $matrix = array( $taxonomy => $size_prices );

        $expanded_opts = array();
        foreach ( $group->options as $option ) {
            $expanded_opts[] = $option->with_price( $matrix );
        }
        return $group->with_options( $expanded_opts );
    }

    public function validate( Lafka_Addon_Group $group ): array {
        $errors = array();
        if ( 1 !== $group->variations || $group->attribute <= 0 ) {
            $errors[] = sprintf(
                /* translators: %s: group name */
                __( '"%s" needs Variations enabled with an attribute selected for flat-per-size pricing.', 'lafka-plugin' ),
                $group->name
            );
        }
        if ( empty( $group->group_size_prices ) ) {
            $errors[] = sprintf(
                /* translators: %s: group name */
                __( '"%s" requires at least one per-size price.', 'lafka-plugin' ),
                $group->name
            );
        }
        return $errors;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter FlatPerSizePricing
```

Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/pricing/class-flat-per-size-pricing.php tests/Unit/Addons/Pricing/FlatPerSizePricingTest.php
git commit -m "feat(addons): flat per size pricing strategy [phase 1 task 9]"
```

---

### Task 10: Matrix pricing strategy

**Files:**
- Modify: `incl/addons/engine/pricing/class-matrix-pricing.php`
- Test: `tests/Unit/Addons/Pricing/MatrixPricingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Pricing/MatrixPricingTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Matrix_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class MatrixPricingTest extends TestCase {

    public function test_id_and_label(): void {
        $strategy = new Lafka_Matrix_Pricing();
        self::assertSame( Lafka_Addon_Schema::PRICING_MATRIX, $strategy->id() );
        self::assertNotEmpty( $strategy->label() );
    }

    public function test_expand_is_passthrough_for_already_nested_prices(): void {
        $matrix = array( 'pa_size' => array( 'small' => '0.50', 'medium' => '1.00' ) );
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'Toppings',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
            'variations'   => 1,
            'attribute'    => 1,
            'options'      => array(
                array( 'label' => 'Cheese', 'price' => $matrix ),
            ),
        ) );
        $strategy = new Lafka_Matrix_Pricing();
        $expanded = $strategy->expand( $group );

        self::assertSame( $matrix, $expanded->options[0]->price );
    }

    public function test_validate_requires_variations_and_attribute(): void {
        $invalid = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
            'variations'   => 0,
            'attribute'    => 0,
            'options'      => array( array( 'label' => 'X' ) ),
        ) );
        $valid = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
            'variations'   => 1,
            'attribute'    => 5,
            'options'      => array( array( 'label' => 'X' ) ),
        ) );

        $strategy = new Lafka_Matrix_Pricing();
        self::assertNotEmpty( $strategy->validate( $invalid ) );
        self::assertEmpty( $strategy->validate( $valid ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter MatrixPricing
```

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/pricing/class-matrix-pricing.php`:

```php
<?php
/**
 * Matrix pricing — full per-option × per-size price grid. The original
 * Lafka per-attribute pricing behavior, now formalized as one of four modes.
 *
 * Storage shape: each option's price is already a nested matrix
 *   [taxonomy_slug => [size_slug => scalar]]
 * which the cart math handles natively.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Matrix_Pricing extends Lafka_Abstract_Pricing_Strategy {

    public function id(): string {
        return Lafka_Addon_Schema::PRICING_MATRIX;
    }

    public function label(): string {
        return __( 'Full matrix (option × size)', 'lafka-plugin' );
    }

    public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
        // Per-option matrices are already in canonical storage shape.
        return $group;
    }

    public function validate( Lafka_Addon_Group $group ): array {
        $errors = array();
        if ( 1 !== $group->variations || $group->attribute <= 0 ) {
            $errors[] = sprintf(
                /* translators: %s: group name */
                __( '"%s" needs Variations enabled with an attribute selected for matrix pricing.', 'lafka-plugin' ),
                $group->name
            );
        }
        return $errors;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter MatrixPricing
```

Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/pricing/class-matrix-pricing.php tests/Unit/Addons/Pricing/MatrixPricingTest.php
git commit -m "feat(addons): matrix pricing strategy [phase 1 task 10]"
```

---

### Task 11: Legacy pricing strategy + Pricing resolver

**Files:**
- Modify: `incl/addons/engine/pricing/class-pricing-resolver.php`
- Test: `tests/Unit/Addons/Pricing/PricingResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Pricing/PricingResolverTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Group_Pricing;
use Lafka_Flat_Per_Option_Pricing;
use Lafka_Flat_Per_Size_Pricing;
use Lafka_Matrix_Pricing;
use Lafka_Pricing_Resolver;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class PricingResolverTest extends TestCase {

    private Lafka_Pricing_Resolver $resolver;

    protected function setUp(): void {
        parent::setUp();
        $this->resolver = new Lafka_Pricing_Resolver();
    }

    public function test_returns_flat_group_for_flat_group_mode(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
        ) );
        $strategy = $this->resolver->for_group( $group );
        self::assertInstanceOf( Lafka_Flat_Group_Pricing::class, $strategy );
    }

    public function test_returns_flat_per_option_for_flat_per_option_mode(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
        ) );
        self::assertInstanceOf( Lafka_Flat_Per_Option_Pricing::class, $this->resolver->for_group( $group ) );
    }

    public function test_returns_flat_per_size_for_flat_per_size_mode(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
        ) );
        self::assertInstanceOf( Lafka_Flat_Per_Size_Pricing::class, $this->resolver->for_group( $group ) );
    }

    public function test_returns_matrix_for_matrix_mode(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
        ) );
        self::assertInstanceOf( Lafka_Matrix_Pricing::class, $this->resolver->for_group( $group ) );
    }

    public function test_legacy_mode_returns_passthrough_strategy(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_LEGACY,
        ) );
        $strategy = $this->resolver->for_group( $group );
        self::assertSame( Lafka_Addon_Schema::PRICING_LEGACY, $strategy->id() );

        // Legacy is passthrough — expand returns the group unchanged.
        $original_options = $group->options;
        $expanded = $strategy->expand( $group );
        self::assertSame( $original_options, $expanded->options );
    }

    public function test_unknown_mode_falls_back_to_legacy(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'G',
            'pricing_mode' => 'something_unknown',
        ) );
        $strategy = $this->resolver->for_group( $group );
        self::assertSame( Lafka_Addon_Schema::PRICING_LEGACY, $strategy->id() );
    }

    public function test_register_filter_allows_third_party_strategies(): void {
        // Spec: third parties can register strategies via the filter.
        // Check the filter is documented and applied.
        $resolver = new Lafka_Pricing_Resolver();
        $strategies = $resolver->all_strategies();

        self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $strategies );
        self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $strategies );
        self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $strategies );
        self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_MATRIX, $strategies );
        self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_LEGACY, $strategies );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter PricingResolver
```

Expected: FAIL.

- [ ] **Step 3: Write the legacy strategy in the resolver file (small enough to inline)**

Replace `incl/addons/engine/pricing/class-pricing-resolver.php`:

```php
<?php
/**
 * Picks a pricing strategy for a given group based on its pricing_mode field.
 *
 * Built-in strategies are registered at construction. Third parties can hook
 * `lafka_addons_register_pricing_strategy` to add their own:
 *
 *     add_filter( 'lafka_addons_register_pricing_strategy', function( $strategies ) {
 *         $strategies['my_custom'] = new My_Custom_Strategy();
 *         return $strategies;
 *     });
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legacy passthrough — preserves whatever shape the data is in. Used for
 * groups that haven't been migrated to a specific mode.
 */
class Lafka_Legacy_Pricing extends Lafka_Abstract_Pricing_Strategy {
    public function id(): string {
        return Lafka_Addon_Schema::PRICING_LEGACY;
    }
    public function label(): string {
        return __( 'Legacy (no transformation)', 'lafka-plugin' );
    }
    public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
        return $group;
    }
}

class Lafka_Pricing_Resolver {

    /** @var array<string, Lafka_Pricing_Strategy> */
    private array $strategies;

    public function __construct() {
        $built_in = array(
            Lafka_Addon_Schema::PRICING_FLAT_GROUP      => new Lafka_Flat_Group_Pricing(),
            Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION => new Lafka_Flat_Per_Option_Pricing(),
            Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE   => new Lafka_Flat_Per_Size_Pricing(),
            Lafka_Addon_Schema::PRICING_MATRIX          => new Lafka_Matrix_Pricing(),
            Lafka_Addon_Schema::PRICING_LEGACY          => new Lafka_Legacy_Pricing(),
        );

        if ( function_exists( 'apply_filters' ) ) {
            $this->strategies = apply_filters( 'lafka_addons_register_pricing_strategy', $built_in );
        } else {
            $this->strategies = $built_in;
        }
    }

    public function for_group( Lafka_Addon_Group $group ): Lafka_Pricing_Strategy {
        $mode = $group->pricing_mode;
        if ( isset( $this->strategies[ $mode ] ) ) {
            return $this->strategies[ $mode ];
        }
        return $this->strategies[ Lafka_Addon_Schema::PRICING_LEGACY ];
    }

    /**
     * @return array<string, Lafka_Pricing_Strategy>
     */
    public function all_strategies(): array {
        return $this->strategies;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter PricingResolver
```

Expected: PASS — 7 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/pricing/class-pricing-resolver.php tests/Unit/Addons/Pricing/PricingResolverTest.php
git commit -m "feat(addons): pricing resolver + legacy strategy [phase 1 task 11]"
```

---

### Task 12: Options source interface + abstract base + manual source

**Files:**
- Modify: `incl/addons/engine/interfaces/interface-options-source.php`
- Modify: `incl/addons/engine/sources/abstract-options-source.php`
- Modify: `incl/addons/engine/sources/class-manual-source.php`
- Test: `tests/Unit/Addons/Sources/ManualSourceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Sources/ManualSourceTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Sources;

use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Manual_Source;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class ManualSourceTest extends TestCase {

    public function test_id_and_label(): void {
        $source = new Lafka_Manual_Source();
        self::assertSame( Lafka_Addon_Schema::SOURCE_MANUAL, $source->id() );
        self::assertNotEmpty( $source->label() );
    }

    public function test_get_options_returns_group_options_unchanged(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'    => 'G',
            'options' => array(
                array( 'label' => 'A', 'price' => '1.00' ),
                array( 'label' => 'B', 'price' => '2.00' ),
            ),
        ) );
        $source = new Lafka_Manual_Source();
        $options = $source->get_options( $group );

        self::assertCount( 2, $options );
        self::assertSame( 'A', $options[0]->label );
        self::assertSame( 'B', $options[1]->label );
    }

    public function test_sync_is_a_noop_for_manual_source(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'    => 'G',
            'options' => array( array( 'label' => 'A', 'price' => '1.00' ) ),
        ) );
        $source = new Lafka_Manual_Source();
        $synced = $source->sync( $group );

        self::assertSame( $group->options, $synced->options );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter ManualSource
```

Expected: FAIL.

- [ ] **Step 3: Write the interface, abstract, and manual source**

Replace `incl/addons/engine/interfaces/interface-options-source.php`:

```php
<?php
/**
 * Lafka_Options_Source — contract for option providers.
 *
 * Two implementations in Phase 1: manual (operator typed each option) and
 * attribute (options sourced from a WC attribute taxonomy's terms). Each
 * source can also `sync` — refresh against current source state preserving
 * any per-option settings (price, included flag) that already exist.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

interface Lafka_Options_Source {

    /**
     * Source id matching Lafka_Addon_Schema::SOURCE_* constants.
     */
    public function id(): string;

    public function label(): string;

    /**
     * Return the canonical option list for this source given the group.
     *
     * @return Lafka_Addon_Option[]
     */
    public function get_options( Lafka_Addon_Group $group ): array;

    /**
     * Refresh the group's options against the current source state. For the
     * manual source this is a no-op. For the attribute source this fetches
     * current taxonomy terms, preserves any matching existing options
     * (by stable id or label), and adds any new terms as fresh options.
     */
    public function sync( Lafka_Addon_Group $group ): Lafka_Addon_Group;
}
```

Replace `incl/addons/engine/sources/abstract-options-source.php`:

```php
<?php
/**
 * Common scaffolding for options sources.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

abstract class Lafka_Abstract_Options_Source implements Lafka_Options_Source {

    /**
     * Index a list of options by their lowercased label for fast lookup
     * during sync. (We use label rather than id because attribute terms
     * have no stable id matching the option id — they have a slug.)
     *
     * @param Lafka_Addon_Option[] $options
     * @return array<string, Lafka_Addon_Option>
     */
    protected function index_by_label( array $options ): array {
        $index = array();
        foreach ( $options as $option ) {
            $index[ strtolower( $option->label ) ] = $option;
        }
        return $index;
    }
}
```

Replace `incl/addons/engine/sources/class-manual-source.php`:

```php
<?php
/**
 * Manual options source — operator typed each option label and price by hand.
 * The default source for every group; matches the legacy Lafka behavior.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Manual_Source extends Lafka_Abstract_Options_Source {

    public function id(): string {
        return Lafka_Addon_Schema::SOURCE_MANUAL;
    }

    public function label(): string {
        return __( 'Manual (type each option)', 'lafka-plugin' );
    }

    public function get_options( Lafka_Addon_Group $group ): array {
        return $group->options;
    }

    public function sync( Lafka_Addon_Group $group ): Lafka_Addon_Group {
        // Manual options have no upstream to sync from.
        return $group;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter ManualSource
```

Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/interfaces/interface-options-source.php incl/addons/engine/sources/abstract-options-source.php incl/addons/engine/sources/class-manual-source.php tests/Unit/Addons/Sources/ManualSourceTest.php
git commit -m "feat(addons): options source interface + manual source [phase 1 task 12]"
```

---

### Task 13: Attribute options source

**Files:**
- Modify: `incl/addons/engine/sources/class-attribute-source.php`
- Test: `tests/Unit/Addons/Sources/AttributeSourceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Sources/AttributeSourceTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Sources;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Attribute_Source;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AttributeSourceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'taxonomy_exists' )->alias(
            static fn( $tax ) => 'pa_premium_toppings' === $tax
        );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'get_terms' )->alias(
            static function ( $args ) {
                if ( ( $args['taxonomy'] ?? '' ) !== 'pa_premium_toppings' ) {
                    return array();
                }
                return array(
                    (object) array( 'slug' => 'cheese', 'name' => 'Cheese' ),
                    (object) array( 'slug' => 'truffle', 'name' => 'Truffle' ),
                    (object) array( 'slug' => 'bacon', 'name' => 'Bacon' ),
                );
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_id_and_label(): void {
        $source = new Lafka_Attribute_Source();
        self::assertSame( Lafka_Addon_Schema::SOURCE_ATTRIBUTE, $source->id() );
        self::assertNotEmpty( $source->label() );
    }

    public function test_get_options_returns_term_based_options(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'                     => 'G',
            'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
            'options_source_attribute' => 'pa_premium_toppings',
            'options'                  => array(),
        ) );
        $source = new Lafka_Attribute_Source();
        $options = $source->get_options( $group );

        self::assertCount( 3, $options );
        self::assertSame( 'Cheese', $options[0]->label );
        self::assertSame( 'Truffle', $options[1]->label );
        self::assertSame( 'Bacon', $options[2]->label );
    }

    public function test_sync_preserves_existing_option_settings_by_label(): void {
        // Existing group has Cheese with a price + Truffle excluded.
        // Sync against attribute should preserve those + add Bacon.
        $group = Lafka_Addon_Group::from_array( array(
            'name'                     => 'G',
            'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
            'options_source_attribute' => 'pa_premium_toppings',
            'options'                  => array(
                array( 'label' => 'Cheese', 'price' => '1.50', 'included' => true ),
                array( 'label' => 'Truffle', 'price' => '3.00', 'included' => false ),
            ),
        ) );
        $source = new Lafka_Attribute_Source();
        $synced = $source->sync( $group );

        self::assertCount( 3, $synced->options );

        $cheese  = $this->find_option( $synced->options, 'Cheese' );
        $truffle = $this->find_option( $synced->options, 'Truffle' );
        $bacon   = $this->find_option( $synced->options, 'Bacon' );

        self::assertSame( '1.50', $cheese->price );
        self::assertTrue( $cheese->included );
        self::assertSame( '3.00', $truffle->price );
        self::assertFalse( $truffle->included );
        self::assertSame( '', $bacon->price );
        self::assertTrue( $bacon->included );
    }

    public function test_sync_returns_unchanged_if_attribute_unset(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'                     => 'G',
            'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
            'options_source_attribute' => '',
            'options'                  => array( array( 'label' => 'A', 'price' => '1' ) ),
        ) );
        $source = new Lafka_Attribute_Source();
        $synced = $source->sync( $group );

        self::assertCount( 1, $synced->options );
        self::assertSame( 'A', $synced->options[0]->label );
    }

    public function test_sync_returns_unchanged_if_taxonomy_does_not_exist(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'                     => 'G',
            'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
            'options_source_attribute' => 'pa_does_not_exist',
            'options'                  => array( array( 'label' => 'A', 'price' => '1' ) ),
        ) );
        $source = new Lafka_Attribute_Source();
        $synced = $source->sync( $group );

        self::assertCount( 1, $synced->options );
        self::assertSame( 'A', $synced->options[0]->label );
    }

    /** @param Lafka_Addon_Option[] $options */
    private function find_option( array $options, string $label ): ?\Lafka_Addon_Option {
        foreach ( $options as $option ) {
            if ( $option->label === $label ) {
                return $option;
            }
        }
        return null;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter AttributeSource
```

Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

Replace `incl/addons/engine/sources/class-attribute-source.php`:

```php
<?php
/**
 * Attribute options source — load addon options from a WooCommerce product
 * attribute taxonomy's terms.
 *
 * Operators add an attribute (e.g., `pa_premium_toppings`) with terms (Cheese,
 * Truffle, Bacon) once. Then any number of addon groups can `sync` against
 * that attribute and have their option list auto-populated. Per-option
 * settings (price, included flag) are preserved across syncs by label match.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Attribute_Source extends Lafka_Abstract_Options_Source {

    public function id(): string {
        return Lafka_Addon_Schema::SOURCE_ATTRIBUTE;
    }

    public function label(): string {
        return __( 'From product attribute', 'lafka-plugin' );
    }

    public function get_options( Lafka_Addon_Group $group ): array {
        $taxonomy = $group->options_source_attribute;
        if ( '' === $taxonomy || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $taxonomy ) ) {
            return $group->options;
        }
        $terms = $this->fetch_terms( $taxonomy );
        if ( empty( $terms ) ) {
            return $group->options;
        }

        $existing_by_label = $this->index_by_label( $group->options );
        $options           = array();
        foreach ( $terms as $term ) {
            $existing = $existing_by_label[ strtolower( $term->name ) ] ?? null;
            if ( $existing ) {
                $options[] = $existing;
            } else {
                $options[] = Lafka_Addon_Option::from_array( array( 'label' => $term->name ) );
            }
        }
        return $options;
    }

    public function sync( Lafka_Addon_Group $group ): Lafka_Addon_Group {
        $synced_options = $this->get_options( $group );
        if ( $synced_options === $group->options ) {
            return $group;
        }
        return $group->with_options( $synced_options );
    }

    /**
     * @return object[]
     */
    private function fetch_terms( string $taxonomy ): array {
        if ( ! function_exists( 'get_terms' ) ) {
            return array();
        }
        $terms = get_terms(
            array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            )
        );
        if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
            return array();
        }
        return is_array( $terms ) ? $terms : array();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter AttributeSource
```

Expected: PASS — 5 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/sources/class-attribute-source.php tests/Unit/Addons/Sources/AttributeSourceTest.php
git commit -m "feat(addons): attribute options source [phase 1 task 13]"
```

---

### Task 14: Migration v8.13.0 — schema v2 stamp

**Files:**
- Modify: `incl/addons/engine/migrations/abstract-migration.php`
- Modify: `incl/addons/engine/migrations/class-migration-v8-13-0.php`
- Test: `tests/Unit/Addons/Migrations/MigrationV8130Test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Migrations/MigrationV8130Test.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Migrations;

use Lafka_Addon_Schema;
use Lafka_Migration_V8_13_0;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class MigrationV8130Test extends TestCase {

    public function test_id_and_target_version(): void {
        $migration = new Lafka_Migration_V8_13_0();
        self::assertSame( '8.13.0', $migration->id() );
        self::assertSame( 2, $migration->target_schema_version() );
    }

    public function test_migrate_legacy_meta_adds_v2_fields_with_legacy_pricing(): void {
        $legacy_meta = array(
            array(
                'name'       => 'Toppings',
                'type'       => 'checkbox',
                'variations' => 1,
                'attribute'  => 1,
                'options'    => array(
                    array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.00' ),
                ),
            ),
        );
        $migration = new Lafka_Migration_V8_13_0();
        $migrated  = $migration->migrate_meta( $legacy_meta );

        self::assertCount( 1, $migrated );
        self::assertSame( Lafka_Addon_Schema::PRICING_LEGACY, $migrated[0]['pricing_mode'] );
        self::assertSame( Lafka_Addon_Schema::SOURCE_MANUAL, $migrated[0]['options_source'] );
        self::assertSame( 2, $migrated[0]['schema_version'] );
        self::assertSame( '', $migrated[0]['group_flat_price'] );
        self::assertSame( array(), $migrated[0]['group_size_prices'] );
        self::assertSame( array(), $migrated[0]['included_size_slugs'] );
    }

    public function test_migrate_preserves_existing_v1_data(): void {
        $legacy_meta = array(
            array(
                'name'        => 'Toppings',
                'description' => 'Choose your toppings',
                'limit'       => 5,
                'required'    => 1,
                'options'     => array(
                    array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.00', 'min' => '0', 'max' => '3' ),
                ),
            ),
        );
        $migration = new Lafka_Migration_V8_13_0();
        $migrated  = $migration->migrate_meta( $legacy_meta );

        self::assertSame( 'Toppings', $migrated[0]['name'] );
        self::assertSame( 'Choose your toppings', $migrated[0]['description'] );
        self::assertSame( 5, $migrated[0]['limit'] );
        self::assertSame( 1, $migrated[0]['required'] );
        self::assertSame( '1.00', $migrated[0]['options'][0]['price'] );
    }

    public function test_migrate_is_idempotent(): void {
        $original = array(
            array(
                'name'           => 'G',
                'pricing_mode'   => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
                'options_source' => Lafka_Addon_Schema::SOURCE_MANUAL,
                'schema_version' => 2,
                'options'        => array( array( 'id' => 'opt-1', 'label' => 'X', 'price' => '1' ) ),
            ),
        );
        $migration = new Lafka_Migration_V8_13_0();
        $first     = $migration->migrate_meta( $original );
        $second    = $migration->migrate_meta( $first );

        self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $second[0]['pricing_mode'] );
        self::assertSame( 2, $second[0]['schema_version'] );
        self::assertSame( $first, $second );
    }

    public function test_migrate_skips_non_array_entries(): void {
        $bad_meta = array(
            'corrupt-string',
            42,
            array( 'name' => 'Valid', 'options' => array() ),
        );
        $migration = new Lafka_Migration_V8_13_0();
        $migrated  = $migration->migrate_meta( $bad_meta );

        self::assertCount( 1, $migrated );
        self::assertSame( 'Valid', $migrated[0]['name'] );
    }

    public function test_empty_meta_returns_empty(): void {
        $migration = new Lafka_Migration_V8_13_0();
        self::assertSame( array(), $migration->migrate_meta( array() ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter MigrationV8130
```

Expected: FAIL.

- [ ] **Step 3: Write the abstract base + migration**

Replace `incl/addons/engine/migrations/abstract-migration.php`:

```php
<?php
/**
 * Lafka_Migration — interface every migration class implements.
 *
 * Each migration carries:
 *   - id()                   — unique identifier ('8.13.0')
 *   - target_schema_version()— the version it brings groups to
 *   - migrate_meta($meta)    — pure transform, returns migrated array
 *
 * The Upgrader (next task) discovers all registered migrations, runs them
 * in id-order against every group with a lower schema_version, and stamps
 * the new version when done.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

interface Lafka_Migration {

    public function id(): string;

    public function target_schema_version(): int;

    /**
     * Pure transform — takes the meta value (an array of group dicts) and
     * returns a migrated array. Called per addon-CPT post or per product.
     *
     * @param array $meta The raw `_product_addons` meta value.
     * @return array
     */
    public function migrate_meta( array $meta ): array;
}
```

Replace `incl/addons/engine/migrations/class-migration-v8-13-0.php`:

```php
<?php
/**
 * Lafka Addons schema v2 migration — adds pricing_mode, options_source,
 * included_size_slugs, group_flat_price, group_size_prices, schema_version
 * fields to every group.
 *
 * Defaults preserve current behavior:
 *   pricing_mode   = legacy   (existing reads/writes through the legacy code path)
 *   options_source = manual   (operator entered options by hand)
 *
 * Idempotent — re-running over already-v2 data is a no-op.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Migration_V8_13_0 implements Lafka_Migration {

    public function id(): string {
        return '8.13.0';
    }

    public function target_schema_version(): int {
        return 2;
    }

    public function migrate_meta( array $meta ): array {
        $migrated = array();
        foreach ( $meta as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            // Use Addon_Group::from_array which fills missing v2 fields from
            // schema defaults, then to_array to get the canonical shape.
            $group      = Lafka_Addon_Group::from_array( $entry );
            $migrated[] = $group->to_array();
        }
        return $migrated;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter MigrationV8130
```

Expected: PASS — 6 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/migrations/abstract-migration.php incl/addons/engine/migrations/class-migration-v8-13-0.php tests/Unit/Addons/Migrations/MigrationV8130Test.php
git commit -m "feat(addons): migration v8.13.0 (schema v2 stamp) [phase 1 task 14]"
```

---

### Task 15: Upgrader — runs pending migrations on plugin update

**Files:**
- Modify: `incl/addons/engine/migrations/class-upgrader.php`
- Test: `tests/Unit/Addons/Migrations/UpgraderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/Migrations/UpgraderTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Migrations;

use Lafka_Addons_Upgrader;
use Lafka_Migration_V8_13_0;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class UpgraderTest extends TestCase {

    public function test_register_and_get_migrations(): void {
        $upgrader = new Lafka_Addons_Upgrader();
        $upgrader->register( new Lafka_Migration_V8_13_0() );

        $migrations = $upgrader->all();
        self::assertCount( 1, $migrations );
        self::assertSame( '8.13.0', $migrations[0]->id() );
    }

    public function test_apply_runs_each_migration_in_order(): void {
        $upgrader = new Lafka_Addons_Upgrader();
        $upgrader->register( new Lafka_Migration_V8_13_0() );

        $legacy = array(
            array( 'name' => 'G', 'options' => array( array( 'label' => 'X', 'price' => '1' ) ) ),
        );
        $migrated = $upgrader->apply_to_meta( $legacy );

        self::assertSame( 2, $migrated[0]['schema_version'] );
    }

    public function test_apply_to_meta_handles_already_migrated_data(): void {
        $upgrader = new Lafka_Addons_Upgrader();
        $upgrader->register( new Lafka_Migration_V8_13_0() );

        $already_v2 = array(
            array(
                'name'           => 'G',
                'schema_version' => 2,
                'pricing_mode'   => 'flat_group',
                'options_source' => 'manual',
                'options'        => array( array( 'id' => 'x', 'label' => 'X', 'price' => '1' ) ),
            ),
        );
        $migrated = $upgrader->apply_to_meta( $already_v2 );

        self::assertSame( 2, $migrated[0]['schema_version'] );
        self::assertSame( 'flat_group', $migrated[0]['pricing_mode'] );
    }

    public function test_no_registered_migrations_returns_meta_unchanged(): void {
        $upgrader = new Lafka_Addons_Upgrader();
        $meta = array( array( 'name' => 'G' ) );
        self::assertSame( $meta, $upgrader->apply_to_meta( $meta ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter UpgraderTest
```

Expected: FAIL.

- [ ] **Step 3: Write the upgrader**

Replace `incl/addons/engine/migrations/class-upgrader.php`:

```php
<?php
/**
 * Discovers and runs registered migrations against `_product_addons` meta.
 *
 * In Phase 1 the upgrader is invoked manually (or in tests). Phase 2 wires
 * it to the plugin activation hook and a "Run migrations" admin action.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addons_Upgrader {

    /** @var Lafka_Migration[] */
    private array $migrations = array();

    public function register( Lafka_Migration $migration ): void {
        $this->migrations[] = $migration;
    }

    /**
     * @return Lafka_Migration[]
     */
    public function all(): array {
        $sorted = $this->migrations;
        usort( $sorted, static fn( Lafka_Migration $a, Lafka_Migration $b ) => version_compare( $a->id(), $b->id() ) );
        return $sorted;
    }

    /**
     * Apply every registered migration to a single meta value (an array of
     * group dicts). Each migration runs in id-order; later migrations see
     * the output of earlier ones.
     */
    public function apply_to_meta( array $meta ): array {
        foreach ( $this->all() as $migration ) {
            $meta = $migration->migrate_meta( $meta );
        }
        return $meta;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter UpgraderTest
```

Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/migrations/class-upgrader.php tests/Unit/Addons/Migrations/UpgraderTest.php
git commit -m "feat(addons): upgrader for running migrations [phase 1 task 15]"
```

---

### Task 16: Repository — read/write `_product_addons` meta

**Files:**
- Modify: `incl/addons/engine/data/class-addon-repository.php`
- Test: `tests/Unit/Addons/AddonRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/AddonRepositoryTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Repository;
use Lafka_Addon_Schema;
use Lafka_Addons_Upgrader;
use Lafka_Migration_V8_13_0;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonRepositoryTest extends TestCase {

    private array $stored_meta = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->stored_meta = array();

        Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single ) {
                if ( '_product_addons' === $key ) {
                    return $this->stored_meta[ $post_id ] ?? array();
                }
                return '';
            }
        );
        Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) {
                if ( '_product_addons' === $key ) {
                    $this->stored_meta[ $post_id ] = $value;
                    return true;
                }
                return false;
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function repo(): Lafka_Addon_Repository {
        $upgrader = new Lafka_Addons_Upgrader();
        $upgrader->register( new Lafka_Migration_V8_13_0() );
        return new Lafka_Addon_Repository( $upgrader );
    }

    public function test_get_groups_returns_empty_array_for_post_with_no_meta(): void {
        $groups = $this->repo()->get_groups( 999 );
        self::assertSame( array(), $groups );
    }

    public function test_save_groups_persists_canonical_shape(): void {
        $group = Lafka_Addon_Group::from_array( array(
            'name'         => 'Toppings',
            'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
            'options'      => array( array( 'label' => 'Cheese', 'price' => '1.00' ) ),
        ) );
        $repo = $this->repo();
        $repo->save_groups( 42, array( $group ) );

        self::assertArrayHasKey( 42, $this->stored_meta );
        self::assertCount( 1, $this->stored_meta[42] );
        self::assertSame( 'Toppings', $this->stored_meta[42][0]['name'] );
        self::assertSame( 'flat_group', $this->stored_meta[42][0]['pricing_mode'] );
        self::assertSame( 2, $this->stored_meta[42][0]['schema_version'] );
    }

    public function test_get_groups_runs_migrations_on_legacy_data(): void {
        // Pre-seeded legacy data (no v2 fields).
        $this->stored_meta[7] = array(
            array(
                'name'    => 'Old Group',
                'options' => array( array( 'label' => 'X', 'price' => '1' ) ),
            ),
        );
        $groups = $this->repo()->get_groups( 7 );

        self::assertCount( 1, $groups );
        self::assertSame( 'Old Group', $groups[0]->name );
        self::assertSame( Lafka_Addon_Schema::PRICING_LEGACY, $groups[0]->pricing_mode );
        self::assertSame( 2, $groups[0]->schema_version );
    }

    public function test_round_trip_save_then_load(): void {
        $original = Lafka_Addon_Group::from_array( array(
            'name'                     => 'Premium Toppings',
            'pricing_mode'             => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
            'variations'               => 1,
            'attribute'                => 1,
            'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
            'options_source_attribute' => 'pa_premium_toppings',
            'group_size_prices'        => array( 'small' => '0.50', 'medium' => '1.00' ),
            'included_size_slugs'      => array( 'small', 'medium' ),
            'options'                  => array(
                array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '', 'included' => true ),
            ),
        ) );
        $repo = $this->repo();
        $repo->save_groups( 100, array( $original ) );

        $loaded = $repo->get_groups( 100 );

        self::assertCount( 1, $loaded );
        self::assertSame( 'Premium Toppings', $loaded[0]->name );
        self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $loaded[0]->pricing_mode );
        self::assertSame( 'pa_premium_toppings', $loaded[0]->options_source_attribute );
        self::assertSame( array( 'small' => '0.50', 'medium' => '1.00' ), $loaded[0]->group_size_prices );
        self::assertSame( array( 'small', 'medium' ), $loaded[0]->included_size_slugs );
        self::assertCount( 1, $loaded[0]->options );
        self::assertSame( 'opt-1', $loaded[0]->options[0]->id );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter AddonRepository
```

Expected: FAIL.

- [ ] **Step 3: Write the repository**

Replace `incl/addons/engine/data/class-addon-repository.php`:

```php
<?php
/**
 * Reads and writes `_product_addons` post meta as Lafka_Addon_Group objects.
 *
 * On read: pulls raw meta, runs registered migrations to bring legacy data
 * to the current schema, hydrates Addon_Group value objects.
 *
 * On write: serializes back to canonical array shape, calls update_post_meta.
 *
 * Phase 1: standalone — used by tests + the new engine. Phase 2 wires the
 * existing admin save handler to use this. Phase 3 wires the cart layer.
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addon_Repository {

    private Lafka_Addons_Upgrader $upgrader;

    public function __construct( Lafka_Addons_Upgrader $upgrader ) {
        $this->upgrader = $upgrader;
    }

    /**
     * @return Lafka_Addon_Group[]
     */
    public function get_groups( int $post_id ): array {
        if ( ! function_exists( 'get_post_meta' ) ) {
            return array();
        }
        $raw = get_post_meta( $post_id, '_product_addons', true );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $migrated = $this->upgrader->apply_to_meta( $raw );
        $groups   = array();
        foreach ( $migrated as $group_data ) {
            if ( ! is_array( $group_data ) ) {
                continue;
            }
            $groups[] = Lafka_Addon_Group::from_array( $group_data );
        }
        return $groups;
    }

    /**
     * @param Lafka_Addon_Group[] $groups
     */
    public function save_groups( int $post_id, array $groups ): bool {
        if ( ! function_exists( 'update_post_meta' ) ) {
            return false;
        }
        $serialized = array();
        foreach ( $groups as $group ) {
            if ( ! $group instanceof Lafka_Addon_Group ) {
                continue;
            }
            $serialized[] = $group->to_array();
        }
        return (bool) update_post_meta( $post_id, '_product_addons', $serialized );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter AddonRepository
```

Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add incl/addons/engine/data/class-addon-repository.php tests/Unit/Addons/AddonRepositoryTest.php
git commit -m "feat(addons): repository for read/write of _product_addons meta [phase 1 task 16]"
```

---

### Task 17: Wire bootstrap into lafka-plugin loader

**Files:**
- Modify: `incl/addons/lafka-product-addons.php`

- [ ] **Step 1: Inspect the current loader**

Run:

```bash
grep -n 'include_once' incl/addons/lafka-product-addons.php | head
```

Expected output: lines showing where existing addon files are loaded.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Addons/EngineLoadedTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/lafka-product-addons.php';

final class EngineLoadedTest extends TestCase {

    public function test_engine_constants_loaded_via_main_plugin(): void {
        self::assertTrue( defined( 'LAFKA_ADDONS_ENGINE_VERSION' ) );
    }

    public function test_engine_classes_available(): void {
        self::assertTrue( class_exists( 'Lafka_Addon_Schema' ) );
        self::assertTrue( class_exists( 'Lafka_Addon_Group' ) );
        self::assertTrue( class_exists( 'Lafka_Addon_Option' ) );
        self::assertTrue( class_exists( 'Lafka_Pricing_Resolver' ) );
        self::assertTrue( class_exists( 'Lafka_Addon_Repository' ) );
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter EngineLoadedTest
```

Expected: FAIL — bootstrap not wired into main loader.

- [ ] **Step 4: Modify the loader**

Open `incl/addons/lafka-product-addons.php`. Find the line that includes `class-lafka-product-addon-admin.php` (or similar — the existing addon class loads). Right after the existing include block, add:

```php
// Phase 1 (v8.13.0): the new addon engine. Loaded alongside legacy code;
// remains dormant until Phase 2 admin form rewires to it.
require_once __DIR__ . '/engine/lafka-addons-engine-bootstrap.php';
```

- [ ] **Step 5: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter EngineLoadedTest
```

Expected: PASS — 2 tests.

- [ ] **Step 6: Run the full test suite to verify nothing else broke**

```bash
vendor/bin/phpunit
```

Expected: All tests pass — existing Lafka tests should be unaffected since the new engine is dormant.

- [ ] **Step 7: Commit**

```bash
git add incl/addons/lafka-product-addons.php tests/Unit/Addons/EngineLoadedTest.php
git commit -m "feat(addons): wire engine v2 bootstrap into addons loader [phase 1 task 17]"
```

---

### Task 18: Public facade — `Lafka_Addons_Engine::instance()`

**Files:**
- Create: `incl/addons/engine/class-engine.php`
- Modify: `incl/addons/engine/lafka-addons-engine-bootstrap.php`
- Test: `tests/Unit/Addons/EngineFacadeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Addons/EngineFacadeTest.php`:

```php
<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Lafka_Addons_Engine;
use Lafka_Addon_Repository;
use Lafka_Pricing_Resolver;
use Lafka_Addons_Upgrader;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class EngineFacadeTest extends TestCase {

    public function test_instance_returns_singleton(): void {
        $a = Lafka_Addons_Engine::instance();
        $b = Lafka_Addons_Engine::instance();
        self::assertSame( $a, $b );
    }

    public function test_pricing_resolver_available(): void {
        $resolver = Lafka_Addons_Engine::instance()->pricing();
        self::assertInstanceOf( Lafka_Pricing_Resolver::class, $resolver );
    }

    public function test_repository_available(): void {
        $repo = Lafka_Addons_Engine::instance()->repository();
        self::assertInstanceOf( Lafka_Addon_Repository::class, $repo );
    }

    public function test_upgrader_has_v8_13_0_migration_registered(): void {
        $upgrader = Lafka_Addons_Engine::instance()->upgrader();
        self::assertInstanceOf( Lafka_Addons_Upgrader::class, $upgrader );

        $migrations = $upgrader->all();
        self::assertGreaterThanOrEqual( 1, count( $migrations ) );
        self::assertSame( '8.13.0', $migrations[0]->id() );
    }

    public function test_sources_resolves_manual_and_attribute(): void {
        $sources = Lafka_Addons_Engine::instance()->sources();
        self::assertArrayHasKey( 'manual', $sources );
        self::assertArrayHasKey( 'attribute', $sources );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter EngineFacade
```

Expected: FAIL.

- [ ] **Step 3: Write the facade**

Create `incl/addons/engine/class-engine.php`:

```php
<?php
/**
 * Lafka_Addons_Engine — public facade for the engine.
 *
 * Singleton. Lazy-instantiates the resolver, repository, upgrader, and
 * source registry on first access. Phase 2+ admin and cart code reaches
 * the engine through this class — no direct instantiation of internals.
 *
 * Public API:
 *   Lafka_Addons_Engine::instance()->pricing()    → Lafka_Pricing_Resolver
 *   Lafka_Addons_Engine::instance()->repository() → Lafka_Addon_Repository
 *   Lafka_Addons_Engine::instance()->upgrader()   → Lafka_Addons_Upgrader
 *   Lafka_Addons_Engine::instance()->sources()    → array<id, Lafka_Options_Source>
 *
 * Third parties extend by hooking these filters:
 *   lafka_addons_register_pricing_strategy
 *   lafka_addons_register_options_source
 *   lafka_addons_register_migration
 *
 * @package Lafka_Addons_Engine
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addons_Engine {

    private static ?Lafka_Addons_Engine $instance = null;

    private ?Lafka_Pricing_Resolver $pricing = null;
    private ?Lafka_Addon_Repository $repository = null;
    private ?Lafka_Addons_Upgrader $upgrader = null;
    /** @var array<string, Lafka_Options_Source>|null */
    private ?array $sources = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function pricing(): Lafka_Pricing_Resolver {
        if ( null === $this->pricing ) {
            $this->pricing = new Lafka_Pricing_Resolver();
        }
        return $this->pricing;
    }

    public function upgrader(): Lafka_Addons_Upgrader {
        if ( null === $this->upgrader ) {
            $upgrader = new Lafka_Addons_Upgrader();
            $upgrader->register( new Lafka_Migration_V8_13_0() );
            if ( function_exists( 'apply_filters' ) ) {
                $upgrader = apply_filters( 'lafka_addons_register_migration', $upgrader );
            }
            $this->upgrader = $upgrader;
        }
        return $this->upgrader;
    }

    public function repository(): Lafka_Addon_Repository {
        if ( null === $this->repository ) {
            $this->repository = new Lafka_Addon_Repository( $this->upgrader() );
        }
        return $this->repository;
    }

    /**
     * @return array<string, Lafka_Options_Source>
     */
    public function sources(): array {
        if ( null === $this->sources ) {
            $built_in = array(
                Lafka_Addon_Schema::SOURCE_MANUAL    => new Lafka_Manual_Source(),
                Lafka_Addon_Schema::SOURCE_ATTRIBUTE => new Lafka_Attribute_Source(),
            );
            if ( function_exists( 'apply_filters' ) ) {
                $built_in = apply_filters( 'lafka_addons_register_options_source', $built_in );
            }
            $this->sources = $built_in;
        }
        return $this->sources;
    }
}
```

- [ ] **Step 4: Add facade to bootstrap require chain**

Edit `incl/addons/engine/lafka-addons-engine-bootstrap.php` — add this line after the migrations require_once:

```php
require_once __DIR__ . '/class-engine.php';
```

- [ ] **Step 5: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter EngineFacade
```

Expected: PASS — 5 tests.

- [ ] **Step 6: Run full suite**

```bash
vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add incl/addons/engine/class-engine.php incl/addons/engine/lafka-addons-engine-bootstrap.php tests/Unit/Addons/EngineFacadeTest.php
git commit -m "feat(addons): public facade Lafka_Addons_Engine [phase 1 task 18]"
```

---

### Task 19: Acceptance — full-suite green + version bump + tag

**Files:**
- Modify: `lafka-plugin.php` (header version)
- Modify: `package.json`

- [ ] **Step 1: Run the entire test suite**

```bash
vendor/bin/phpunit
```

Expected: PASS — 256 (existing) + ~50 new = ~306 tests, all green.

- [ ] **Step 2: Run PHPCS on all new files**

```bash
vendor/bin/phpcs --standard=.phpcs.xml.dist incl/addons/engine/ tests/Unit/Addons/
```

Expected: 0 errors, 0 warnings (or only informational).

- [ ] **Step 3: Bump the plugin version header**

Use the Edit tool (NOT sed; per `feedback_no_sed_inplace.md` memory) to change `Version: 8.12.9` → `Version: 8.13.0` in `lafka-plugin.php` line 6.

Use the Edit tool to change `"version": "8.12.9"` → `"version": "8.13.0"` in `package.json` line 3.

Verify both files are intact:

```bash
wc -l lafka-plugin.php  # should be 1739
grep 'Version: 8.13\|"version":' lafka-plugin.php package.json
```

- [ ] **Step 4: Commit + tag the foundation**

```bash
git add lafka-plugin.php package.json
git commit -m "chore(release): v8.13.0 — addons engine v2 foundation [phase 1 task 19]

Phase 1 of the addons rewrite ships the foundational data, pricing, and
source layers. The new engine is dormant: existing admin and cart code
paths continue to handle all operator-visible behavior. Phase 2 wires
the admin form to the new engine.

Spec: docs/superpowers/specs/2026-04-30-lafka-addons-engine-v2-design.md
Plan: docs/superpowers/plans/2026-04-30-lafka-addons-engine-v2-phase1-foundation.md

What's in v8.13.0:
  - Lafka_Addons_Engine facade (singleton public API)
  - Lafka_Addon_Schema (canonical defaults + version constants)
  - Lafka_Addon_Group + Lafka_Addon_Option (immutable-ish value objects)
  - Lafka_Addon_Repository (read/write _product_addons meta)
  - 4 pricing strategies + legacy passthrough + Lafka_Pricing_Resolver
  - 2 options sources (manual + attribute) with sync-by-label
  - Migration v8.13.0 stamping every group with schema_version=2
  - Upgrader + extension filter hooks for future migrations / strategies / sources
  - ~50 new behavioral tests covering each layer

Backward compatibility:
  - Migration runs on read; existing groups default to pricing_mode=legacy,
    options_source=manual. No operator-visible change in v8.13.0.
  - Existing admin form, cart math, display layer untouched.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
git tag -a v8.13.0 -m "v8.13.0 — addons engine v2 foundation"
```

- [ ] **Step 5: Push branch (do NOT push tag yet — let user verify first)**

```bash
git push origin feature/addons-engine-v2
```

- [ ] **Step 6: Acceptance verification**

For each spec acceptance criterion, confirm:

1. ✅ Existing addon groups work identically — verified by tests/Unit/Addons/AddonRepositoryTest::test_get_groups_runs_migrations_on_legacy_data (legacy data round-trips with pricing_mode=legacy)
2. ✅ Migration is idempotent — verified by tests/Unit/Addons/Migrations/MigrationV8130Test::test_migrate_is_idempotent
3. ✅ All four pricing strategies pass behavioral tests — verified by FlatGroup, FlatPerOption, FlatPerSize, Matrix test files
4. ✅ Attribute source reads + sync — verified by AttributeSourceTest
5. ✅ Repository round-trips a group through save → load — verified by AddonRepositoryTest::test_round_trip_save_then_load
6. ✅ `incl/addons/admin/` and `incl/addons/includes/` untouched — verified by reading the diff:

```bash
git diff main..HEAD --stat -- incl/addons/admin/ incl/addons/includes/
```

Expected: empty output (no files changed in those directories).

7. ✅ Test suite green — verified by Step 1 of this task.

If any acceptance criterion fails, do NOT proceed with the tag push. Open a follow-up task to address the gap.

---

## Self-review checklist

After all 19 tasks land:

1. **Spec coverage**: every acceptance criterion in the spec is verified by Task 19. ✅
2. **Placeholder scan**: no "TBD", "TODO", "implement later" in plan text. ✅
3. **Type consistency**: every class name (`Lafka_Addon_Group`, `Lafka_Addon_Option`, `Lafka_Pricing_Resolver`, etc.) used identically across tasks. Each method signature is shown in full at first use, repeated where called. ✅

## After Phase 1

Phase 2 plan goes in `docs/superpowers/plans/2026-XX-XX-lafka-addons-engine-v2-phase2-admin-form.md`. It will rewrite the admin form (`html-addon.php`, `html-addon-option.php`, save handler) to use the engine. The legacy path becomes optional.

Phase 3 plan: cart/display migration + REST + WP-CLI.

Phase 4 plan: privacy, import/export, additional fields, deletion of `incl/addons/admin/` and `incl/addons/includes/`.
