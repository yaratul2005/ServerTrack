<?php
/**
 * Tests for Ratul_ACT_Source_WooCommerce v3.3.1
 * Section: Order Status Events (BUG-09 fix)
 *
 * Covers:
 *   - on-hold  → Lead dispatched to all platforms
 *   - failed   → Contact dispatched to all platforms
 *   - cancelled→ SubmitForm dispatched to all platforms
 *   - Unknown status → no dispatch
 *   - BUG-09: dedup skips only when ALL three platforms already sent
 *   - BUG-09: event fires when only ONE platform already sent
 */

use PHPUnit\Framework\TestCase;

class WooCommerceOrderStatusTest extends TestCase {

    private WC_Order $order;

    protected function setUp(): void {
        Ratul_ACT_Core::reset();
        Ratul_ACT_Dedup::reset();
        Ratul_ACT_Logger::reset();

        $this->order     = new WC_Order();
        $this->order->id = 101;
    }

    // ── Status → event mapping ───────────────────────────────────────────

    public function test_on_hold_fires_lead(): void {
        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'on-hold', $this->order );

        $this->assertCount( 1, Ratul_ACT_Core::$dispatched );
        $this->assertSame( 'Lead', Ratul_ACT_Core::$dispatched[0]['event'] );
    }

    public function test_failed_fires_contact(): void {
        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'failed', $this->order );

        $this->assertCount( 1, Ratul_ACT_Core::$dispatched );
        $this->assertSame( 'Contact', Ratul_ACT_Core::$dispatched[0]['event'] );
    }

    public function test_cancelled_fires_submit_form(): void {
        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 101, 'processing', 'cancelled', $this->order );

        $this->assertCount( 1, Ratul_ACT_Core::$dispatched );
        $this->assertSame( 'SubmitForm', Ratul_ACT_Core::$dispatched[0]['event'] );
    }

    public function test_unknown_status_fires_nothing(): void {
        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'refunded', $this->order );

        $this->assertEmpty( Ratul_ACT_Core::$dispatched, 'Unknown status must not dispatch any event.' );
    }

    // ── Dedup (BUG-09) ───────────────────────────────────────────────────

    public function test_bug09_skips_when_all_platforms_already_sent(): void {
        $key = 'order_status_101_on-hold';
        Ratul_ACT_Dedup::mark_as_sent( $key, 'meta' );
        Ratul_ACT_Dedup::mark_as_sent( $key, 'tiktok' );
        Ratul_ACT_Dedup::mark_as_sent( $key, 'google' );

        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'on-hold', $this->order );

        $this->assertEmpty( Ratul_ACT_Core::$dispatched,
            'BUG-09: event must be skipped when all 3 platforms have already been sent.'
        );
    }

    public function test_bug09_fires_when_only_one_platform_already_sent(): void {
        // Only Meta is already sent — original BUG-09 would have skipped all platforms
        $key = 'order_status_101_failed';
        Ratul_ACT_Dedup::mark_as_sent( $key, 'meta' );

        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'failed', $this->order );

        $this->assertCount( 1, Ratul_ACT_Core::$dispatched,
            'BUG-09: event must still fire when only 1 of 3 platforms was already sent.'
        );
    }

    public function test_dedup_key_format_is_correct(): void {
        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 202, 'pending', 'cancelled', $this->order );

        $dispatched = Ratul_ACT_Core::$dispatched[0];
        $this->assertSame( 'order_status_202_cancelled', $dispatched['dedup_key'] );
    }

    public function test_custom_data_includes_order_status(): void {
        Ratul_ACT_Source_WooCommerce::handle_order_status_change( 101, 'pending', 'on-hold', $this->order );

        $custom = Ratul_ACT_Core::$dispatched[0]['custom'];
        $this->assertSame( 'on-hold', $custom['order_status'] );
        $this->assertSame( 101, $custom['order_id'] );
    }
}

