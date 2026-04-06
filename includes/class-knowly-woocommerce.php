<?php
/**
 * Knowly_WooCommerce — WooCommerce integration.
 *
 * Connects WooCommerce product purchases to the KnowlyAPI blue gem wallet.
 *
 * How it works:
 *  1. Each WooCommerce product can carry a `_knowly_gem_quantity` meta field.
 *     The field is exposed in the "General" tab of the Product Data panel.
 *  2. When an order reaches "completed" status, each line item is inspected.
 *     If a product has `_knowly_gem_quantity > 0`, that amount × quantity is
 *     credited to the purchasing parent's gem wallet.
 *  3. Order meta `_knowly_gems_granted` is set after crediting so the hook
 *     never fires twice for the same order (e.g. manual status toggle).
 *
 * Legacy: the old field `_knowly_token_amount` is read as a fallback so
 * products created before Block 3 continue to work without re-saving.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_WooCommerce {

    public static function boot(): void {
        if ( ! self::is_woocommerce_active() ) {
            return;
        }

        // Product data field — show in WooCommerce "General" tab
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_gem_field' ] );
        add_action( 'woocommerce_process_product_meta',                 [ __CLASS__, 'save_gem_field' ] );

        // Credit gems when order is completed
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'handle_order_completed' ], 10, 1 );

        // Also handle instant-payment gateways that skip "processing" and go straight to completed
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'handle_order_completed' ], 10, 1 );

        Knowly_Debug::log( 'woocommerce', 'WooCommerce integration booted', [], null, 'debug' );
    }

    // ── Product field ─────────────────────────────────────────────────────────

    /**
     * Render the "Knowly Blue Gems" number field in the WooCommerce General product tab.
     */
    public static function render_gem_field(): void {
        echo '<div class="options_group">';

        woocommerce_wp_text_input( [
            'id'                => '_knowly_gem_quantity',
            'label'             => __( 'Knowly Blue Gems granted', 'knowly-api' ),
            'description'       => __( 'Number of Blue Gems credited to the buyer\'s Knowly wallet when this product is purchased. Leave 0 or blank for no gems.', 'knowly-api' ),
            'desc_tip'          => true,
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
            'value'             => get_post_meta( get_the_ID(), '_knowly_gem_quantity', true ) ?: '',
        ] );

        echo '</div>';
    }

    /**
     * Save the `_knowly_gem_quantity` field when the product is saved.
     */
    public static function save_gem_field( int $post_id ): void {
        $amount = (int) ( $_POST['_knowly_gem_quantity'] ?? 0 );
        if ( $amount > 0 ) {
            update_post_meta( $post_id, '_knowly_gem_quantity', $amount );
        } else {
            delete_post_meta( $post_id, '_knowly_gem_quantity' );
        }
    }

    // ── Order handling ────────────────────────────────────────────────────────

    /**
     * Credit blue gems for every Knowly gem product in a completed order.
     *
     * @param int $order_id  WooCommerce order ID.
     */
    public static function handle_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Idempotency guard — never credit the same order twice
        if ( $order->get_meta( '_knowly_gems_granted' ) ) {
            Knowly_Debug::log( 'woocommerce.order', 'Order already granted — skipping', [
                'order_id' => $order_id,
            ], null, 'debug' );
            return;
        }

        // Resolve to a WordPress user
        $wp_user_id = $order->get_customer_id();
        if ( ! $wp_user_id ) {
            Knowly_Debug::log( 'woocommerce.order', 'Order has no WP user — cannot credit gems', [
                'order_id' => $order_id,
            ], null, 'warning' );
            return;
        }

        // Ensure this user is a knowly_parent (or an admin) — only parents hold wallets
        $user  = get_userdata( $wp_user_id );
        $roles = $user ? (array) $user->roles : [];

        if ( ! array_intersect( $roles, [ 'knowly_parent', 'administrator' ] ) ) {
            Knowly_Debug::log( 'woocommerce.order', 'Buyer is not a knowly_parent — skipping gem credit', [
                'order_id'   => $order_id,
                'wp_user_id' => $wp_user_id,
                'roles'      => $roles,
            ], $wp_user_id, 'warning' );
            return;
        }

        $total_credited = 0;
        $credited_items = [];

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product_id  = $item->get_product_id();

            // New field (Block 3+); fall back to legacy token_amount field
            $gem_quantity = (int) get_post_meta( $product_id, '_knowly_gem_quantity', true );
            if ( $gem_quantity <= 0 ) {
                $gem_quantity = (int) get_post_meta( $product_id, '_knowly_token_amount', true );
            }

            if ( $gem_quantity <= 0 ) {
                continue;
            }

            $qty       = max( 1, (int) $item->get_quantity() );
            $to_credit = $gem_quantity * $qty;

            $result = Knowly_Gem_Service::credit(
                $wp_user_id,
                $to_credit,
                'purchase',
                '',
                (string) $order_id,
                'WooCommerce order #' . $order_id . ' — ' . $item->get_name()
            );

            if ( ! is_wp_error( $result ) ) {
                $total_credited += $to_credit;
                $credited_items[] = [
                    'product'      => $item->get_name(),
                    'gems_each'    => $gem_quantity,
                    'qty'          => $qty,
                    'total'        => $to_credit,
                ];
            }
        }

        if ( $total_credited > 0 ) {
            // Mark order so it never credits again
            $order->update_meta_data( '_knowly_gems_granted', $total_credited );
            $order->add_order_note(
                sprintf( 'Knowly: %d Blue Gem(s) credited to user #%d.', $total_credited, $wp_user_id )
            );
            $order->save();

            Knowly_Debug::log( 'woocommerce.order', 'Blue gems credited from WooCommerce order', [
                'order_id'       => $order_id,
                'wp_user_id'     => $wp_user_id,
                'total_credited' => $total_credited,
                'items'          => $credited_items,
            ], $wp_user_id, 'info' );
        }
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    private static function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }
}
