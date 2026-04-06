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
 *   GET  /knowly/v1/gems/products             Open           List purchasable gem packages
 *   POST /knowly/v1/gems/checkout             JWT Parent     Initiate Fygaro checkout
 *   POST /knowly/v1/gems/fygaro-webhook       Open (HMAC)    Receive and process Fygaro webhook
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

        // ── Purchase / Fygaro ─────────────────────────────────────────────────

        register_rest_route( $ns, '/gems/products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_products' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/gems/checkout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'checkout' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
                'redirect_url' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
            ],
        ] );

        register_rest_route( $ns, '/gems/fygaro-webhook', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'fygaro_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Wallet Handlers ───────────────────────────────────────────────────────

    public function balance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

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
     * Return the list of purchasable gem packages (from WP options).
     *
     * Packages are stored as a JSON array in the option `knowly_gem_products`.
     * Each entry: { id, name, gem_quantity, price_ttd, currency }
     */
    public function get_products( WP_REST_Request $request ): WP_REST_Response {
        $products = json_decode( get_option( 'knowly_gem_products', '[]' ), true ) ?: [];
        return $this->success( [ 'products' => $products ] );
    }

    /**
     * Initiate a Fygaro checkout session for a gem purchase.
     *
     * Calls the Fygaro payment link API and returns the redirect URL.
     */
    public function checkout( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->require_parent( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $product_id   = $request->get_param( 'product_id' );
        $redirect_url = $request->get_param( 'redirect_url' ) ?: get_site_url();

        // Look up the product
        $products = json_decode( get_option( 'knowly_gem_products', '[]' ), true ) ?: [];
        $product  = null;
        foreach ( $products as $p ) {
            if ( ( $p['id'] ?? '' ) === $product_id ) {
                $product = $p;
                break;
            }
        }

        if ( ! $product ) {
            return new WP_Error( 'knowly_product_not_found', 'Gem product not found.', [ 'status' => 404 ] );
        }

        $merchant_id = get_option( 'knowly_fygaro_merchant_id', '' );
        $api_key     = get_option( 'knowly_fygaro_api_key', '' );

        if ( ! $merchant_id || ! $api_key ) {
            return new WP_Error( 'knowly_fygaro_not_configured', 'Payment gateway is not configured.', [ 'status' => 503 ] );
        }

        $user      = get_userdata( $user_id );
        $user_meta = get_user_meta( $user_id );

        $payload = [
            'amount'       => $product['price_ttd'],
            'currency'     => $product['currency'] ?? 'TTD',
            'description'  => $product['name'] . ' — Knowly Blue Gems',
            'reference'    => 'knowly_' . $user_id . '_' . $product_id . '_' . time(),
            'customer'     => [
                'email' => $user->user_email,
                'name'  => ( $user_meta['first_name'][0] ?? '' ) . ' ' . ( $user_meta['last_name'][0] ?? '' ),
            ],
            'metadata'     => [
                'user_id'      => $user_id,
                'product_id'   => $product_id,
                'gem_quantity' => $product['gem_quantity'],
            ],
            'redirect_url' => $redirect_url,
        ];

        $response = wp_remote_post( 'https://app.fygaro.com/api/v1/payment-links', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'X-Merchant-ID' => $merchant_id,
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'gems.checkout', 'Fygaro checkout request failed', [
                'error' => $response->get_error_message(),
            ], $user_id, 'error' );
            return new WP_Error( 'knowly_gateway_error', 'Could not connect to payment gateway.', [ 'status' => 502 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 && $code !== 201 ) {
            Knowly_Debug::log( 'gems.checkout', 'Fygaro returned error', [
                'code' => $code,
                'body' => $body,
            ], $user_id, 'error' );
            return new WP_Error( 'knowly_gateway_error', 'Payment gateway error. Please try again.', [ 'status' => 502 ] );
        }

        Knowly_Debug::log( 'gems.checkout', 'Fygaro checkout created', [
            'user_id'    => $user_id,
            'product_id' => $product_id,
            'payment_url' => $body['payment_url'] ?? '',
        ], $user_id, 'info' );

        return $this->success( [
            'payment_url'  => $body['payment_url'] ?? '',
            'reference'    => $payload['reference'],
            'gem_quantity' => $product['gem_quantity'],
            'amount'       => $product['price_ttd'],
            'currency'     => $product['currency'] ?? 'TTD',
        ] );
    }

    /**
     * Receive Fygaro payment webhook, validate HMAC, credit gems (idempotent).
     *
     * Fygaro sends a POST with JSON body and X-Fygaro-Signature header.
     * Signature = HMAC-SHA256(raw body, webhook_secret).
     */
    public function fygaro_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $webhook_secret = get_option( 'knowly_fygaro_webhook_secret', '' );

        if ( ! $webhook_secret ) {
            Knowly_Debug::log( 'gems.webhook', 'Webhook secret not configured — rejecting', [], null, 'warning' );
            return new WP_Error( 'knowly_webhook_misconfigured', 'Webhook not configured.', [ 'status' => 500 ] );
        }

        // ── HMAC validation ───────────────────────────────────────────────────
        $raw_body  = $request->get_body();
        $signature = $request->get_header( 'X-Fygaro-Signature' );
        $expected  = hash_hmac( 'sha256', $raw_body, $webhook_secret );

        if ( ! hash_equals( $expected, (string) $signature ) ) {
            Knowly_Debug::log( 'gems.webhook', 'Invalid HMAC signature', [
                'received' => $signature,
            ], null, 'warning' );
            return new WP_Error( 'knowly_webhook_invalid_signature', 'Invalid signature.', [ 'status' => 401 ] );
        }

        $data = json_decode( $raw_body, true );
        if ( ! $data ) {
            return new WP_Error( 'knowly_webhook_invalid_payload', 'Invalid JSON payload.', [ 'status' => 400 ] );
        }

        // Only process completed payments
        $status = $data['status'] ?? '';
        if ( $status !== 'completed' && $status !== 'success' ) {
            return $this->success( [ 'message' => 'Non-completed event ignored.' ] );
        }

        $transaction_id = $data['transaction_id'] ?? $data['id'] ?? '';
        if ( ! $transaction_id ) {
            return new WP_Error( 'knowly_webhook_missing_transaction_id', 'Missing transaction_id.', [ 'status' => 400 ] );
        }

        // ── Idempotency guard ─────────────────────────────────────────────────
        global $wpdb;
        $already = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_processed_webhooks WHERE transaction_id = %s LIMIT 1",
            $transaction_id
        ) );

        if ( $already ) {
            Knowly_Debug::log( 'gems.webhook', 'Duplicate webhook — already processed', [
                'transaction_id' => $transaction_id,
            ], null, 'debug' );
            return $this->success( [ 'message' => 'Already processed.' ] );
        }

        // ── Extract metadata ──────────────────────────────────────────────────
        $metadata     = $data['metadata'] ?? [];
        $user_id      = (int) ( $metadata['user_id'] ?? 0 );
        $gem_quantity = (int) ( $metadata['gem_quantity'] ?? 0 );
        $product_id   = $metadata['product_id'] ?? '';

        if ( ! $user_id || ! $gem_quantity ) {
            Knowly_Debug::log( 'gems.webhook', 'Missing user_id or gem_quantity in metadata', [
                'metadata' => $metadata,
            ], null, 'warning' );
            return new WP_Error( 'knowly_webhook_missing_metadata', 'Missing user_id or gem_quantity.', [ 'status' => 400 ] );
        }

        // ── Mark as processed (before credit — prevents duplicate on retry) ───
        $wpdb->insert(
            $wpdb->prefix . 'knowly_processed_webhooks',
            [
                'transaction_id' => $transaction_id,
                'gateway'        => 'fygaro',
                'processed_at'   => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s' ]
        );

        // ── Credit gems ───────────────────────────────────────────────────────
        $result = Knowly_Gem_Service::credit(
            $user_id,
            $gem_quantity,
            'purchase',
            '',
            $transaction_id,
            "Fygaro purchase: product {$product_id}"
        );

        if ( is_wp_error( $result ) ) {
            Knowly_Debug::log( 'gems.webhook', 'Gem credit failed after webhook', [
                'user_id'        => $user_id,
                'gem_quantity'   => $gem_quantity,
                'transaction_id' => $transaction_id,
                'error'          => $result->get_error_message(),
            ], $user_id, 'error' );
            return $result;
        }

        Knowly_Debug::log( 'gems.webhook', 'Gems credited via Fygaro webhook', [
            'user_id'        => $user_id,
            'gem_quantity'   => $gem_quantity,
            'transaction_id' => $transaction_id,
            'balance_after'  => $result['balance_after'],
        ], $user_id, 'info' );

        return $this->success( [
            'message'       => 'Gems credited.',
            'user_id'       => $user_id,
            'gem_quantity'  => $gem_quantity,
            'balance_after' => $result['balance_after'],
        ] );
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function admin_permission(): bool {
        $user_id = Knowly_JWT::from_request();
        if ( is_wp_error( $user_id ) ) return false;
        return user_can( $user_id, 'manage_options' );
    }
}
