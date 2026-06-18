<?php
/**
 * Knowly_Progression_API — REST proxy for child curriculum progression.
 *
 * Routes (under /knowly/v1):
 *   GET  /child/progression   Full curriculum path map for the authenticated child/parent
 *   GET  /child/modules       Deduplicated module list for a subject (uses curriculum-topics, server-key auth)
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Progression_API extends Knowly_API_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/child/progression', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_progression' ],
                'permission_callback' => '__return_true',
            ],
        ] );
        register_rest_route( $this->namespace, '/child/modules', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_modules' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    public function get_progression( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // Must resolve the child context so Railway receives the child's own user_id.
        // Using authenticate() alone returns the parent's user_id when a parent is logged
        // in, which caused all siblings to share the same progression bucket in Railway.
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $child_id = $ctx['child_id'];

        $params = array_filter( [
            'curriculum' => $request->get_param( 'curriculum' ) ?: 'tt_primary',
            'level'      => $request->get_param( 'level' ),
            'period'     => $request->get_param( 'period' ),
            'subject'    => $request->get_param( 'subject' ),
        ], fn( $v ) => $v !== null && $v !== '' );

        if ( empty( $params['level'] ) ) {
            return new WP_Error( 'knowly_missing_fields', 'level is required.', [ 'status' => 400 ] );
        }

        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $url = $endpoint . '/api/v1/progression/child';
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . Knowly_JWT::encode( $child_id ),
                'Content-Type'  => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'knowly_railway_error', 'Failed to connect to content service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'knowly_railway_error', $body['error'] ?? 'Content service returned an error.', [ 'status' => $code ] );
        }

        return new WP_REST_Response( $body ?: [], 200 );
    }

    public function get_modules( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $level   = sanitize_key( $request->get_param( 'level' )   ?? '' );
        $subject = sanitize_key( $request->get_param( 'subject' ) ?? '' );
        $period  = sanitize_key( $request->get_param( 'period' )  ?? '' );

        if ( ! $level || ! $subject ) {
            return new WP_Error( 'knowly_missing_fields', 'level and subject are required.', [ 'status' => 400 ] );
        }

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $params = [
            'curriculum' => 'tt_primary',
            'level'      => $level,
            'subject'    => $subject,
            'status'     => 'active',
            'per_page'   => 200,
        ];
        if ( $period ) $params['period'] = $period;

        $url      = $endpoint . '/api/v1/curriculum-topics?' . http_build_query( $params );
        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_REST_Response( [ 'modules' => [] ], 200 );
        }

        $code  = wp_remote_retrieve_response_code( $response );
        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
        $items = ( $code >= 200 && $code < 300 ) ? ( $body['items'] ?? [] ) : [];

        $seen    = [];
        $modules = [];
        foreach ( $items as $item ) {
            $num = $item['module_number'] ?? null;
            if ( $num !== null && ! isset( $seen[ $num ] ) ) {
                $seen[ $num ] = true;
                $modules[]    = [
                    'module_number' => (int) $num,
                    'module_title'  => $item['module_title'] ?? '',
                ];
            }
        }
        usort( $modules, fn( $a, $b ) => $a['module_number'] <=> $b['module_number'] );

        return new WP_REST_Response( [ 'modules' => $modules ], 200 );
    }
}
