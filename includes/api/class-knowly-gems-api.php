<?php
/**
 * Knowly_Gems_API — Blue gem wallet endpoints.
 *
 * Routes:
 *   GET  /knowly/v1/gems/balance              JWT            Parent or child wallet balance
 *   GET  /knowly/v1/gems/ledger               JWT            Transaction history (paginated)
 *   POST /knowly/v1/gems/allocate             JWT Parent     Allocate gems from parent → child
 *   POST /knowly/v1/gems/admin/credit         Admin          Credit gems to any user
 *   POST /knowly/v1/gems/admin/deduct         Admin          Deduct gems from any user
 *   POST /knowly/v1/gems/admin/refresh        Admin          Trigger monthly refresh manually
 *   GET  /knowly/v1/gems/products             Open           WooCommerce gem products
 *   GET  /knowly/v1/gems/costs                Open           Gem costs per difficulty / quest type
 *   POST /knowly/v1/gems/orders               JWT Parent     Create WooCommerce order + return payment_url
 *   GET  /knowly/v1/gems/orders             JWT Parent     List all gem orders for the parent
 *   GET  /knowly/v1/gems/orders/{order_id}    JWT Parent     Order status for results page
 *   GET  /knowly/v1/gems/orders/{order_id}/stripe-intent  JWT Parent  Get/create Stripe PaymentIntent client_secret
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Gems_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        // ── Wallet ────────────────────────────────────────────────────────────

        register_rest_route( $ns, '/gems/balance', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'balance' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/gems/ledger', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'ledger' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'limit'  => [ 'default' => 50, 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                'offset' => [ 'default' => 0,  'type' => 'integer', 'minimum' => 0 ],
            ],
        ] );

        register_rest_route( $ns, '/gems/allocate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'allocate' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'child_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'amount'   => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        // ── Admin ─────────────────────────────────────────────────────────────

        register_rest_route( $ns, '/gems/admin/credit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_credit' ],
            'permission_callback' => [ $this, 'admin_permission' ],
            'args'                => [
                'user_id' => [ 'required' => true,  'type' => 'integer' ],
                'amount'  => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                'note'    => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/gems/admin/deduct', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_deduct' ],
            'permission_callback' => [ $this, 'admin_permission' ],
            'args'                => [
                'user_id' => [ 'required' => true,  'type' => 'integer' ],
                'amount'  => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                'note'    => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/gems/admin/refresh', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_refresh' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        // ── Commerce (WooCommerce) ────────────────────────────────────────────

        register_rest_route( $ns, '/gems/products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_products' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/gems/costs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_costs' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/gems/orders', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_orders' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'limit'  => [ 'required' => false, 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
                    'offset' => [ 'required' => false, 'type' => 'integer', 'default' => 0,  'minimum' => 0 ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_order' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'product_id' => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                    'quantity'   => [ 'required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                    'billing'    => [ 'required' => true ],
                    'return_url' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/gems/orders/(?P<order_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_order' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'key' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/gems/orders/(?P<order_id>\d+)/stripe-intent', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'stripe_intent' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'key' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    // ── Wallet Handlers ───────────────────────────────────────────────────────

    public function balance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        // scope=parent: caller explicitly wants the parent's own wallet (e.g. gem shop header).
        // Otherwise: when a parent has an active child, return the child's balance so the
        // child-facing UI always shows the active child's gems.
        $scope = sanitize_text_field( $request->get_param( 'scope' ) ?? '' );

        if ( $scope !== 'parent' && $this->is_parent( $user_id ) ) {
            $child_id = (int) get_user_meta( $user_id, 'knowly_active_child_id', true );
            if ( $child_id ) {
                $user_id = $child_id;
            }
        }

        return $this->success( [
            'balance' => Knowly_Gem_Service::get_balance( $user_id ),
        ] );
    }

    public function ledger( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $rows = Knowly_Gem_Service::get_ledger(
            $user_id,
            (int) $request->get_param( 'limit' ),
            (int) $request->get_param( 'offset' )
        );

        return $this->success( [ 'ledger' => $rows ] );
    }

    public function allocate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->require_parent( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $child_id = (int) $request->get_param( 'child_id' );
        $amount   = (int) $request->get_param( 'amount' );

        // Verify child belongs to this parent
        $parent_of_child = (int) get_user_meta( $child_id, 'knowly_parent_id', true );
        if ( $parent_of_child !== $user_id ) {
            return new WP_Error( 'knowly_forbidden', 'Child does not belong to this parent.', [ 'status' => 403 ] );
        }

        $result = Knowly_Gem_Service::allocate( $user_id, $child_id, $amount );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    // ── Admin Handlers ────────────────────────────────────────────────────────

    public function admin_credit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Knowly_Gem_Service::credit(
            (int) $request->get_param( 'user_id' ),
            (int) $request->get_param( 'amount' ),
            'admin_credit',
            '',
            '',
            $request->get_param( 'note' ) ?: 'Admin credit'
        );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function admin_deduct( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Knowly_Gem_Service::deduct(
            (int) $request->get_param( 'user_id' ),
            (int) $request->get_param( 'amount' ),
            'admin_deduct',
            '',
            '',
            $request->get_param( 'note' ) ?: 'Admin deduct'
        );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function admin_refresh(): WP_REST_Response {
        $count = Knowly_Gem_Service::run_monthly_refresh();
        return $this->success( [ 'accounts_refreshed' => $count ] );
    }

    // ── Purchase / Fygaro Handlers ────────────────────────────────────────────

    /**
     * Return all published WooCommerce products that have _knowly_gem_quantity > 0.
     */
    public function get_products( WP_REST_Request $request ): WP_REST_Response {
        return $this->success( [ 'products' => Knowly_WooCommerce::get_gem_products() ] );
    }

    /**
     * Return all gem orders for the authenticated parent, newest first.
     */
    public function list_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->require_parent( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $limit  = (int) $request->get_param( 'limit' );
        $offset = (int) $request->get_param( 'offset' );

        $wc_orders = wc_get_orders( [
            'customer_id' => $user_id,
            'limit'       => $limit,
            'offset'      => $offset,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );

        $orders = [];
        foreach ( $wc_orders as $order ) {
            // Sum gem quantity across all items in the order
            $gem_quantity = 0;
            foreach ( $order->get_items() as $item ) {
                $product_id    = $item->get_product_id();
                $item_gems     = (int) get_post_meta( $product_id, '_knowly_gem_quantity', true );
                $gem_quantity += $item_gems * $item->get_quantity();
            }

            $orders[] = [
                'order_id'     => $order->get_id(),
                'status'       => $order->get_status(),
                'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
                'total'        => $order->get_total(),
                'currency'     => $order->get_currency(),
                'gem_quantity' => $gem_quantity,
                'gems_granted' => (int) $order->get_meta( '_knowly_gems_granted' ),
                'product_name' => $order->get_item_count() > 0
                    ? array_values( $order->get_items() )[0]->get_name()
                    : '',
            ];
        }

        return $this->success( [ 'orders' => $orders, 'total' => count( $wc_orders ) ] );
    }

    /**
     * Create a WooCommerce order for a gem product and return the payment URL.
     *
     * React collects billing info, POSTs here, then redirects to payment_url.
     * After payment the gateway redirects to return_url with ?order_id=&key=.
     */
    public function create_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->require_parent( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        if ( ! function_exists( 'wc_get_product' ) ) {
            return new WP_Error( 'knowly_wc_unavailable', 'WooCommerce is not active.', [ 'status' => 503 ] );
        }

        $product_id = (int) $request->get_param( 'product_id' );
        $quantity   = max( 1, (int) $request->get_param( 'quantity' ) );
        $billing    = (array) $request->get_param( 'billing' );
        $return_url = $request->get_param( 'return_url' ) ?: get_site_url();

        // Verify product is a knowly gem product
        $gem_quantity = (int) get_post_meta( $product_id, '_knowly_gem_quantity', true );
        if ( $gem_quantity <= 0 ) {
            return new WP_Error( 'knowly_not_gem_product', 'Product is not a gem product.', [ 'status' => 400 ] );
        }

        $wc_product = wc_get_product( $product_id );
        if ( ! $wc_product || ! $wc_product->is_purchasable() ) {
            return new WP_Error( 'knowly_product_unavailable', 'Product is not available.', [ 'status' => 400 ] );
        }

        $order = wc_create_order( [ 'customer_id' => $user_id, 'status' => 'pending' ] );
        if ( is_wp_error( $order ) ) {
            return new WP_Error( 'knowly_order_create_failed', 'Could not create order.', [ 'status' => 500 ] );
        }

        $order->add_product( $wc_product, $quantity );

        $order->set_billing_first_name( sanitize_text_field( $billing['first_name'] ?? '' ) );
        $order->set_billing_last_name( sanitize_text_field( $billing['last_name']  ?? '' ) );
        $order->set_billing_email( sanitize_email( $billing['email']        ?? '' ) );
        $order->set_billing_phone( sanitize_text_field( $billing['phone']       ?? '' ) );
        $order->set_billing_address_1( sanitize_text_field( $billing['address_1']   ?? '' ) );
        $order->set_billing_address_2( sanitize_text_field( $billing['address_2']   ?? '' ) );
        $order->set_billing_city( sanitize_text_field( $billing['city']        ?? '' ) );
        $order->set_billing_state( sanitize_text_field( $billing['state']       ?? '' ) );
        $order->set_billing_postcode( sanitize_text_field( $billing['postcode']    ?? '' ) );
        $order->set_billing_country( sanitize_text_field( $billing['country']     ?? '' ) );

        $order->set_payment_method( 'stripe' );
        $order->set_payment_method_title( 'Credit Card (Stripe)' );
        $order->update_meta_data( '_knowly_return_url', esc_url_raw( $return_url ) );
        $order->calculate_totals();
        $order->save();

        // Persist billing address to WC user meta so it pre-fills on the next purchase
        $billing_meta_map = [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
        ];
        foreach ( $billing_meta_map as $key => $value ) {
            if ( $value !== '' ) {
                update_user_meta( $user_id, 'billing_' . $key, $value );
            }
        }

        Knowly_Debug::log( 'gems.order', 'WooCommerce order created', [
            'order_id'   => $order->get_id(),
            'user_id'    => $user_id,
            'product_id' => $product_id,
            'gems'       => $gem_quantity * $quantity,
        ], $user_id, 'info' );

        Knowly_Notification_Service::create( [
            'recipient_user_id' => $user_id,
            'type'              => 'simple',
            'subject'           => 'gem_order_placed',
            'message'           => sprintf(
                'Your order #%d for %d Blue Gems has been placed and is being processed.',
                $order->get_id(),
                $gem_quantity * $quantity
            ),
            'payload'           => [
                'order_id'     => $order->get_id(),
                'gem_quantity' => $gem_quantity * $quantity,
                'total'        => $order->get_total(),
                'currency'     => $order->get_currency(),
            ],
        ] );

        return $this->success( [
            'order_id'     => $order->get_id(),
            'payment_url'  => $order->get_checkout_payment_url(),
            'order_key'    => $order->get_order_key(),
            'total'        => $order->get_total(),
            'currency'     => $order->get_currency(),
            'gem_quantity' => $gem_quantity * $quantity,
        ], 201 );
    }

    /**
     * Return order status — polled by the React results page after payment.
     */
    public function get_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->require_parent( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $order = wc_get_order( (int) $request['order_id'] );
        if ( ! $order ) {
            return new WP_Error( 'knowly_order_not_found', 'Order not found.', [ 'status' => 404 ] );
        }

        if ( (int) $order->get_customer_id() !== $user_id ) {
            return new WP_Error( 'knowly_forbidden', 'Access denied.', [ 'status' => 403 ] );
        }

        $key = sanitize_text_field( $request->get_param( 'key' ) ?? '' );
        if ( $key && $order->get_order_key() !== $key ) {
            return new WP_Error( 'knowly_forbidden', 'Invalid order key.', [ 'status' => 403 ] );
        }

        return $this->success( [
            'order_id'     => $order->get_id(),
            'status'       => $order->get_status(),
            'gems_granted' => (int) $order->get_meta( '_knowly_gems_granted' ),
            'total'        => $order->get_total(),
            'currency'     => $order->get_currency(),
        ] );
    }

    /**
     * Get or create a Stripe PaymentIntent for an existing pending WC order.
     *
     * If the order already has _stripe_intent_id set we retrieve that intent so the
     * same client_secret can be reused (idempotent on page reload).
     * Otherwise we create a new PaymentIntent with metadata.order_id set so that
     * the WC Stripe webhook handler can locate the order via its Tier 2 lookup.
     *
     * Returns: { client_secret, publishable_key, amount, currency }
     */
    public function stripe_intent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->require_parent( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $order = wc_get_order( (int) $request['order_id'] );
        if ( ! $order ) {
            return new WP_Error( 'knowly_order_not_found', 'Order not found.', [ 'status' => 404 ] );
        }
        if ( (int) $order->get_customer_id() !== $user_id ) {
            return new WP_Error( 'knowly_forbidden', 'Access denied.', [ 'status' => 403 ] );
        }

        $key = sanitize_text_field( $request->get_param( 'key' ) ?? '' );
        if ( $key && $order->get_order_key() !== $key ) {
            return new WP_Error( 'knowly_forbidden', 'Invalid order key.', [ 'status' => 403 ] );
        }

        if ( ! class_exists( 'WC_Stripe_API' ) ) {
            return new WP_Error( 'knowly_stripe_unavailable', 'Stripe gateway is not active.', [ 'status' => 503 ] );
        }

        // Read Stripe publishable key from WC Stripe settings
        $stripe_settings = get_option( 'woocommerce_stripe_settings', [] );
        $test_mode       = isset( $stripe_settings['testmode'] ) && $stripe_settings['testmode'] === 'yes';
        $publishable_key = $test_mode
            ? ( $stripe_settings['test_publishable_key'] ?? '' )
            : ( $stripe_settings['publishable_key'] ?? '' );

        // Reuse existing intent if we already created one for this order
        $existing_intent_id = $order->get_meta( '_stripe_intent_id' );
        if ( $existing_intent_id ) {
            $intent = WC_Stripe_API::request( [], 'payment_intents/' . $existing_intent_id, 'GET' );
            if ( ! is_wp_error( $intent ) && isset( $intent->client_secret ) ) {
                return $this->success( [
                    'client_secret'   => $intent->client_secret,
                    'publishable_key' => $publishable_key,
                    'amount'          => $intent->amount,
                    'currency'        => $intent->currency,
                ] );
            }
        }

        // Create a new PaymentIntent
        $amount   = (int) round( (float) $order->get_total() * 100 ); // smallest currency unit
        $currency = strtolower( $order->get_currency() );

        $intent_data = [
            'amount'               => $amount,
            'currency'             => $currency,
            'payment_method_types' => [ 'card' ],
            'metadata'             => [
                'order_id' => $order->get_id(), // Tier 2 webhook lookup
            ],
        ];

        $intent = WC_Stripe_API::request( $intent_data, 'payment_intents' );
        if ( is_wp_error( $intent ) ) {
            return new WP_Error( 'knowly_stripe_error', $intent->get_error_message(), [ 'status' => 502 ] );
        }
        if ( empty( $intent->client_secret ) ) {
            return new WP_Error( 'knowly_stripe_error', 'Invalid PaymentIntent response.', [ 'status' => 502 ] );
        }

        // Store intent ID on the order for Tier 3 webhook lookup + future idempotency
        $order->update_meta_data( '_stripe_intent_id', $intent->id );
        $order->save();

        return $this->success( [
            'client_secret'   => $intent->client_secret,
            'publishable_key' => $publishable_key,
            'amount'          => $intent->amount,
            'currency'        => $intent->currency,
        ] );
    }

    public function get_costs( WP_REST_Request $request ): WP_REST_Response {
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        return $this->success( [
            'trial' => [
                'easy'   => Knowly_Gem_Service::get_exam_cost( $curriculum, 'easy' ),
                'medium' => Knowly_Gem_Service::get_exam_cost( $curriculum, 'medium' ),
                'hard'   => Knowly_Gem_Service::get_exam_cost( $curriculum, 'hard' ),
            ],
            'quest' => [
                'first'  => Knowly_Gem_Service::get_quest_cost( $curriculum, false ),
                'retake' => Knowly_Gem_Service::get_quest_cost( $curriculum, true ),
            ],
            'lesson' => Knowly_Gem_Service::get_lesson_cost( $curriculum ),
        ] );
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function admin_permission(): bool {
        $user_id = Knowly_JWT::from_request();
        if ( is_wp_error( $user_id ) ) return false;
        return user_can( $user_id, 'manage_options' );
    }
}
