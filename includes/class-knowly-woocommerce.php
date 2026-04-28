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
        // Autologin redirect runs before WooCommerce loads — register on init unconditionally
        add_action( 'init', [ __CLASS__, 'handle_autologin_redirect' ], 1 );

        if ( ! self::is_woocommerce_active() ) {
            return;
        }

        // Product data field — show in WooCommerce "General" tab
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_gem_field' ] );
        add_action( 'woocommerce_process_product_meta',                 [ __CLASS__, 'save_gem_field' ] );

        // Credit gems only when the order reaches "completed" status.
        // For virtual products + Stripe, WooCommerce auto-completes the order via webhook immediately
        // after payment_complete() fires — so gems are delivered within seconds of payment in production.
        // woocommerce_payment_complete is kept as a catch-all for gateways that call payment_complete()
        // directly and bypass the status transition hook (e.g. some local/test gateways).
        // Idempotency guard in handle_order_completed prevents double-crediting across both hooks.
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'handle_order_completed' ], 10, 1 );
        add_action( 'woocommerce_payment_complete',       [ __CLASS__, 'handle_order_completed' ], 10, 1 );

        // Redirect to custom Knowly return URL after payment (headless checkout)
        add_filter( 'woocommerce_get_return_url', [ __CLASS__, 'custom_return_url' ], 10, 2 );

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
        error_log( '[Knowly] handle_order_completed CALLED for order #' . $order_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( '[Knowly] handle_order_completed: order not found #' . $order_id );
            return;
        }

        // Idempotency guard — never credit the same order twice
        if ( $order->get_meta( '_knowly_gems_granted' ) ) {
            error_log( '[Knowly] handle_order_completed: already granted for order #' . $order_id );
            Knowly_Debug::log( 'woocommerce.order', 'Order already granted — skipping', [
                'order_id' => $order_id,
            ], null, 'debug' );
            return;
        }

        // Resolve to a WordPress user
        $wp_user_id = $order->get_customer_id();
        if ( ! $wp_user_id ) {
            error_log( '[Knowly] handle_order_completed: no WP user on order #' . $order_id );
            Knowly_Debug::log( 'woocommerce.order', 'Order has no WP user — cannot credit gems', [
                'order_id' => $order_id,
            ], null, 'warning' );
            return;
        }

        // Ensure this user is a knowly_parent (or an admin) — only parents hold wallets
        $user  = get_userdata( $wp_user_id );
        $roles = $user ? (array) $user->roles : [];

        error_log( '[Knowly] handle_order_completed: order #' . $order_id . ' user #' . $wp_user_id . ' roles=' . implode( ',', $roles ) );

        if ( ! array_intersect( $roles, [ 'knowly_parent', 'administrator' ] ) ) {
            error_log( '[Knowly] handle_order_completed: user #' . $wp_user_id . ' is not knowly_parent/admin — skipping' );
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

            error_log( '[Knowly] handle_order_completed: product #' . $product_id . ' "' . $item->get_name() . '" gem_quantity=' . $gem_quantity );

            if ( $gem_quantity <= 0 ) {
                error_log( '[Knowly] handle_order_completed: product #' . $product_id . ' has no gem quantity — skipping item' );
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

            if ( is_wp_error( $result ) ) {
                error_log( '[Knowly] handle_order_completed: Gem_Service::credit FAILED for order #' . $order_id . ' product #' . $product_id . ' error=' . $result->get_error_message() );
            } else {
                $total_credited += $to_credit;
                $credited_items[] = [
                    'product'      => $item->get_name(),
                    'gems_each'    => $gem_quantity,
                    'qty'          => $qty,
                    'total'        => $to_credit,
                ];
            }
        }

        error_log( '[Knowly] handle_order_completed: total_credited=' . $total_credited . ' for order #' . $order_id );

        if ( $total_credited > 0 ) {
            // Mark order so it never credits again
            $order->update_meta_data( '_knowly_gems_granted', $total_credited );
            $order->add_order_note(
                sprintf( 'Knowly: %d Blue Gem(s) credited to user #%d.', $total_credited, $wp_user_id )
            );
            $order->save();

            Knowly_Notification_Service::create( [
                'recipient_user_id' => $wp_user_id,
                'type'              => 'simple',
                'subject'           => 'gem_order_completed',
                'message'           => sprintf(
                    '%d Blue Gems have been added to your wallet! (Order #%d)',
                    $total_credited,
                    $order_id
                ),
                'payload'           => [
                    'order_id'     => $order_id,
                    'gems_granted' => $total_credited,
                ],
            ] );

            Knowly_Debug::log( 'woocommerce.order', 'Blue gems credited from WooCommerce order', [
                'order_id'       => $order_id,
                'wp_user_id'     => $wp_user_id,
                'total_credited' => $total_credited,
                'items'          => $credited_items,
            ], $wp_user_id, 'info' );
        }
    }

    // ── Headless checkout helpers ─────────────────────────────────────────────

    /**
     * Handle GET /?knowly_pay=TOKEN — validate one-time autologin token,
     * set a WP auth cookie, then redirect to the WC payment URL.
     *
     * Fires on `init` (priority 1) so WooCommerce and templates never load.
     * Token is deleted immediately on use; expires after 5 minutes if unused.
     */
    public static function handle_autologin_redirect(): void {
        $token = sanitize_text_field( wp_unslash( $_GET['knowly_pay'] ?? '' ) );
        if ( ! $token ) return;

        $hash = hash( 'sha256', $token );
        $data = get_transient( 'knowly_autologin_' . $hash );

        if ( ! $data || ! is_array( $data ) ) {
            wp_die(
                'This payment link has expired or is invalid. Please go back and try again.',
                'Link Expired',
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        // One-time use — delete immediately
        delete_transient( 'knowly_autologin_' . $hash );

        $user_id      = (int) $data['user_id'];
        $redirect_url = esc_url_raw( $data['redirect_url'] );

        // Redirect must stay on this WP site
        if ( ! str_starts_with( $redirect_url, home_url() ) ) {
            wp_die( 'Invalid redirect destination.', 'Error', [ 'response' => 403 ] );
        }

        // Log the parent in to WordPress and send to the WC payment page
        wp_clear_auth_cookie();
        wp_set_auth_cookie( $user_id, false, is_ssl() );

        Knowly_Debug::log( 'auth.autologin', 'Autologin redirect executed', [
            'user_id' => $user_id,
        ], $user_id, 'info' );

        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * After payment, redirect to the Knowly return URL stored on the order
     * instead of WooCommerce's native order-received page.
     */
    public static function custom_return_url( string $return_url, mixed $order ): string {
        if ( ! $order instanceof \WC_Abstract_Order ) return $return_url;
        $custom = $order->get_meta( '_knowly_return_url' );
        if ( ! $custom ) return $return_url;
        return add_query_arg( [
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ], $custom );
    }

    /**
     * Return all published WooCommerce products that carry a gem quantity.
     * Used by the Gems API and the Gem Commerce admin tab.
     *
     * @return array[]  Each entry: { id, name, description, price, currency, gem_quantity, image_url }
     */
    public static function get_gem_products(): array {
        if ( ! self::is_woocommerce_active() ) return [];

        $ids = get_posts( [
            'post_type'   => 'product',
            'post_status' => 'publish',
            'numberposts' => 100,
            'fields'      => 'ids',
            'meta_query'  => [ [
                'key'     => '_knowly_gem_quantity',
                'value'   => 0,
                'compare' => '>',
                'type'    => 'NUMERIC',
            ] ],
        ] );

        $products = [];
        foreach ( $ids as $id ) {
            $p = wc_get_product( $id );
            if ( ! $p || ! $p->is_purchasable() ) continue;
            $gem_qty = (int) $p->get_meta( '_knowly_gem_quantity' );
            if ( $gem_qty <= 0 ) continue;
            $image_id  = $p->get_image_id();
            $products[] = [
                'id'            => $p->get_id(),
                'name'          => $p->get_name(),
                'description'   => $p->get_short_description() ?: wp_trim_words( $p->get_description(), 20 ),
                'price'         => $p->get_price(),
                'price_html'    => $p->get_price_html(),
                'currency'      => get_woocommerce_currency(),
                'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                'gem_quantity'  => $gem_qty,
                'image_url'     => $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '',
            ];
        }
        return $products;
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    private static function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }
}
