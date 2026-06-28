<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the guest reorder cookie-integrity fix (audit f047).
 *
 * The recent-order cookie is fully client-controlled. To stop a guest forging
 * or tampering with it, the server HMAC-signs the payload on
 * woocommerce_thankyou (only the server holds wp_salt('auth')), and the reorder
 * endpoint recomputes that signature and refuses to act on the cookie unless it
 * validates. This complements the f006 guard (LastOrderCardGuestReorderSecurity)
 * which forbids fetching an order from the DB by a request-supplied id in the
 * guest path — the two together keep the endpoint from disclosing or priming
 * arbitrary order contents.
 */
final class LastOrderCardCookieSignatureTest extends TestCase {

    private string $src;

    protected function setUp(): void {
        $this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-last-order-card.php' );
    }

    /**
     * Slice out only the reorder AJAX handler body so ordering/containment
     * assertions cannot be satisfied by code elsewhere in the file.
     */
    private function reorder_handler_src(): string {
        $start = strpos( $this->src, 'function lafka_pdp_reorder_ajax' );
        $this->assertNotFalse( $start, 'reorder handler must exist' );
        $end = strpos( $this->src, 'add_action(', $start );
        $this->assertNotFalse( $end, 'reorder handler must register its hooks' );
        return substr( $this->src, $start, $end - $start );
    }

    public function test_signature_helper_exists(): void {
        $this->assertStringContainsString( 'function lafka_pdp_recent_order_signature', $this->src );
    }

    public function test_signature_uses_hmac_sha256_with_auth_salt(): void {
        // The secret must be a server-only salt; an attacker without it cannot
        // forge a valid signature. SHA-256 keyed HMAC is required.
        $this->assertMatchesRegularExpression(
            "/hash_hmac\(\s*'sha256',[^;]*wp_salt\(\s*'auth'\s*\)/s",
            $this->src
        );
    }

    public function test_signature_covers_order_id_and_items(): void {
        $start = strpos( $this->src, 'function lafka_pdp_recent_order_signature' );
        $end   = strpos( $this->src, 'function lafka_pdp_set_last_order_cookie' );
        $this->assertNotFalse( $start );
        $this->assertNotFalse( $end );
        $helper = substr( $this->src, $start, $end - $start );
        // Both the id and the line items are inside the signed canonical payload
        // so neither can be altered without invalidating the signature.
        $this->assertStringContainsString( "'order_id' => \$order_id", $helper );
        $this->assertStringContainsString( "'items'", $helper );
        $this->assertStringContainsString( '$items', $helper );
    }

    public function test_cookie_payload_is_signed_on_thankyou(): void {
        $start  = strpos( $this->src, 'function lafka_pdp_set_last_order_cookie' );
        $end    = strpos( $this->src, 'function lafka_pdp_get_last_order' );
        $this->assertNotFalse( $start );
        $this->assertNotFalse( $end );
        $setter = substr( $this->src, $start, $end - $start );
        $this->assertMatchesRegularExpression(
            "/\\\$payload\['sig'\]\s*=\s*lafka_pdp_recent_order_signature\(/",
            $setter
        );
    }

    public function test_guest_path_rejects_invalid_signature(): void {
        $fn           = $this->reorder_handler_src();
        $guest_marker = strpos( $fn, '$last = lafka_pdp_get_last_order()' );
        $this->assertNotFalse( $guest_marker, 'guest branch must read the cookie via the reader' );
        $guest_branch = substr( $fn, $guest_marker );
        // The guest path must recompute the signature and compare it in constant
        // time before doing anything with the cookie contents.
        $this->assertStringContainsString( 'lafka_pdp_recent_order_signature(', $guest_branch );
        $this->assertStringContainsString( 'hash_equals(', $guest_branch );
    }

    public function test_guest_signature_check_precedes_cart_rebuild(): void {
        $fn          = $this->reorder_handler_src();
        $verify_pos  = strpos( $fn, 'hash_equals(' );
        $rebuild_pos = strpos( $fn, 'foreach ( $last[\'items\'] as $line )' );
        $this->assertNotFalse( $verify_pos, 'signature must be verified' );
        $this->assertNotFalse( $rebuild_pos, 'cart must be rebuilt from cookie items' );
        $this->assertLessThan(
            $rebuild_pos,
            $verify_pos,
            'signature must be validated before any cart item is added'
        );
    }

    public function test_guest_path_still_never_fetches_order_from_db(): void {
        // The integrity fix must not reintroduce a DB fetch in the guest path
        // (preserves the f006 guarantee).
        $fn            = $this->reorder_handler_src();
        $guest_marker  = strpos( $fn, '$last = lafka_pdp_get_last_order()' );
        $last_db_fetch = strrpos( $fn, 'wc_get_order(' );
        $this->assertNotFalse( $last_db_fetch, 'authenticated branch should still fetch the order' );
        $this->assertLessThan(
            $guest_marker,
            $last_db_fetch,
            'wc_get_order() must stay out of the guest reorder path'
        );
    }
}
