<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the guest-IDOR fix in the PDP reorder endpoint
 * (audit f006). For signed-out visitors the reorder handler must rebuild the
 * cart only from the client-side recent-order cookie items and must NEVER fetch
 * an order from the DB by a request-supplied ID — otherwise any order's line
 * items could be exfiltrated by enumerating order_id.
 */
final class LastOrderCardGuestReorderSecurityTest extends TestCase {

    private string $src;

    protected function setUp(): void {
        $this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-last-order-card.php' );
    }

    /**
     * Slice out only the reorder AJAX handler body so ordering/containment
     * assertions can't be satisfied by code elsewhere in the file.
     */
    private function reorder_handler_src(): string {
        $start = strpos( $this->src, 'function lafka_pdp_reorder_ajax' );
        $this->assertNotFalse( $start, 'reorder handler must exist' );
        $end = strpos( $this->src, "add_action(", $start );
        $this->assertNotFalse( $end, 'reorder handler must register its hooks' );
        return substr( $this->src, $start, $end - $start );
    }

    public function test_cookie_is_httponly(): void {
        $this->assertStringContainsString( "'httponly' => true", $this->src );
        $this->assertStringNotContainsString( "'httponly' => false", $this->src );
    }

    public function test_variation_attributes_are_persisted(): void {
        // Helper exists and is used when building the stored cookie payload so
        // the exact variation selection survives a reorder.
        $this->assertStringContainsString( 'function lafka_pdp_extract_item_variation', $this->src );
        $this->assertStringContainsString( "'variation'    => lafka_pdp_extract_item_variation( \$item )", $this->src );
    }

    public function test_guest_path_rebuilds_from_cookie_items(): void {
        $fn = $this->reorder_handler_src();
        $this->assertStringContainsString( 'lafka_pdp_get_last_order()', $fn );
        $this->assertMatchesRegularExpression( '/foreach\s*\(\s*\$last\[.items.\]\s+as\s+\$line\s*\)/', $fn );
    }

    public function test_guest_path_never_fetches_order_from_db(): void {
        $fn = $this->reorder_handler_src();

        // The DB fetch must live exclusively in the authenticated branch, which
        // is gated by an ownership check. Prove that the only wc_get_order()
        // call appears before the guest rebuild path begins.
        $guest_marker = strpos( $fn, '$last = lafka_pdp_get_last_order()' );
        $this->assertNotFalse( $guest_marker, 'guest branch must read the cookie via the reader' );

        $last_db_fetch = strrpos( $fn, 'wc_get_order(' );
        $this->assertNotFalse( $last_db_fetch, 'authenticated branch should still fetch the order' );
        $this->assertLessThan(
            $guest_marker,
            $last_db_fetch,
            'wc_get_order() must not be reachable from the guest reorder path'
        );

        // The DB fetch must be inside an is_user_logged_in() gate.
        $login_gate = strpos( $fn, 'if ( is_user_logged_in() )' );
        $this->assertNotFalse( $login_gate, 'authenticated branch must be gated by is_user_logged_in()' );
        $this->assertLessThan( $last_db_fetch, $login_gate, 'DB fetch must sit inside the logged-in gate' );
    }

    public function test_guest_path_ignores_posted_order_id(): void {
        $fn           = $this->reorder_handler_src();
        $guest_marker = strpos( $fn, '$last = lafka_pdp_get_last_order()' );
        $guest_branch = substr( $fn, $guest_marker );
        // After branching into the guest path, the request order_id must not be
        // consulted at all (no $_POST['order_id'] read in that section).
        $this->assertStringNotContainsString( "\$_POST['order_id']", $guest_branch );
    }

    public function test_guest_variation_values_are_sanitized(): void {
        $fn = $this->reorder_handler_src();
        $this->assertStringContainsString( 'sanitize_text_field', $fn );
    }

    public function test_nonce_still_required(): void {
        $this->assertStringContainsString( "check_ajax_referer( 'lafka_pdp_reorder', 'nonce' )", $this->src );
    }
}
