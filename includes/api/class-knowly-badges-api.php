<?php
/**
 * Knowly_Badges_API — Badge endpoints.
 *
 * Routes:
 *   GET    /knowly/v1/badges/definitions                Admin  — list all badge definitions
 *   POST   /knowly/v1/badges/definitions                Admin  — create/update definition
 *   DELETE /knowly/v1/badges/definitions/{id}           Admin  — delete definition
 *   POST   /knowly/v1/badges/definitions/{id}/generate  Admin  — AI name+description via Railway
 *   GET    /knowly/v1/badges/awards                     Child  — own badges (child JWT context)
 *   GET    /knowly/v1/badges/public/{share_token}       Public — for share page OG metadata
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Badges_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/badges/definitions', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_definitions' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_definition' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'name'          => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'trigger_type'  => [ 'required' => true,  'type' => 'string', 'enum' => [ 'quest_module_completion', 'trial_count', 'lesson_count' ] ],
                    'curriculum'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
                    'level'         => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
                    'subject'       => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
                    'id'            => [ 'required' => false, 'type' => 'integer', 'minimum' => 1 ],
                    'description'   => [ 'required' => false, 'type' => 'string' ],
                    'period'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
                    'module_number' => [ 'required' => false, 'type' => 'integer', 'minimum' => 1 ],
                    'threshold'     => [ 'required' => false, 'type' => 'integer', 'minimum' => 1 ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/badges/definitions/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_definition' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( $ns, '/badges/definitions/(?P<id>\d+)/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_name' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( $ns, '/badges/awards', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_awards' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/badges/public/(?P<share_token>[a-f0-9]{32})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'public_badge' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'share_token' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * GET /badges/definitions
     * Admin only.
     */
    public function list_definitions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $caller = $this->authenticate( $request );
        if ( is_wp_error( $caller ) ) return $caller;
        if ( ! user_can( $caller, 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Admin access required.', [ 'status' => 403 ] );
        }

        $filters = array_filter( [
            'trigger_type' => $request->get_param( 'trigger_type' ),
            'subject'      => $request->get_param( 'subject' ),
            'level'        => $request->get_param( 'level' ),
            'curriculum'   => $request->get_param( 'curriculum' ),
        ] );

        $defs = Knowly_Badge_Service::get_definitions( $filters );

        // Append award counts
        foreach ( $defs as &$def ) {
            $def['award_count'] = Knowly_Badge_Service::count_awards_for_definition( (int) $def['id'] );
        }

        return $this->success( [ 'definitions' => $defs, 'count' => count( $defs ) ] );
    }

    /**
     * POST /badges/definitions
     * Admin only.
     */
    public function save_definition( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $caller = $this->authenticate( $request );
        if ( is_wp_error( $caller ) ) return $caller;
        if ( ! user_can( $caller, 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Admin access required.', [ 'status' => 403 ] );
        }

        $data = [
            'id'            => $request->get_param( 'id' ),
            'name'          => $request->get_param( 'name' ),
            'description'   => $request->get_param( 'description' ),
            'trigger_type'  => $request->get_param( 'trigger_type' ),
            'curriculum'    => $request->get_param( 'curriculum' ),
            'level'         => $request->get_param( 'level' ),
            'period'        => $request->get_param( 'period' ),
            'subject'       => $request->get_param( 'subject' ),
            'module_number' => $request->get_param( 'module_number' ),
            'threshold'     => $request->get_param( 'threshold' ),
        ];

        $result = Knowly_Badge_Service::save_definition( $data );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * DELETE /badges/definitions/{id}
     * Admin only.
     */
    public function delete_definition( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $caller = $this->authenticate( $request );
        if ( is_wp_error( $caller ) ) return $caller;
        if ( ! user_can( $caller, 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Admin access required.', [ 'status' => 403 ] );
        }

        $deleted = Knowly_Badge_Service::delete_definition( (int) $request['id'] );
        if ( ! $deleted ) {
            return new WP_Error( 'knowly_not_found', 'Definition not found.', [ 'status' => 404 ] );
        }
        return $this->success( [ 'deleted' => true ] );
    }

    /**
     * POST /badges/definitions/{id}/generate
     * Calls Railway for an AI-generated badge name and description.
     * Synchronous from the admin panel (Railway returns in < 10s with Claude).
     */
    public function generate_name( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $caller = $this->authenticate( $request );
        if ( is_wp_error( $caller ) ) return $caller;
        if ( ! user_can( $caller, 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Admin access required.', [ 'status' => 403 ] );
        }

        $def_id = (int) $request['id'];
        $defs   = Knowly_Badge_Service::get_definitions();
        $def    = null;
        foreach ( $defs as $d ) {
            if ( (int) $d['id'] === $def_id ) {
                $def = $d;
                break;
            }
        }
        if ( ! $def ) {
            return new WP_Error( 'knowly_not_found', 'Definition not found.', [ 'status' => 404 ] );
        }

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_config', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $resp = wp_remote_post( $endpoint . '/api/v1/badge/generate', [
            'timeout' => 30,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( [
                'trigger_type'  => $def['trigger_type'],
                'trigger_key'   => $def['trigger_key'],
                'curriculum'    => $def['curriculum'],
                'level'         => $def['level'],
                'period'        => $def['period'],
                'subject'       => $def['subject'],
                'module_number' => $def['module_number'],
                'threshold'     => $def['threshold'],
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return new WP_Error( 'knowly_railway_error', $resp->get_error_message(), [ 'status' => 502 ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body['name'] ) ) {
            return new WP_Error( 'knowly_ai_error', 'AI generation did not return a name.', [ 'status' => 502 ] );
        }

        // Save the AI-generated name/description back to the definition
        Knowly_Badge_Service::save_definition( array_merge( $def, [
            'name'         => $body['name'],
            'description'  => $body['description'] ?? $def['description'],
            'ai_generated' => 1,
        ] ) );

        return $this->success( [
            'name'        => $body['name'],
            'description' => $body['description'] ?? null,
        ] );
    }

    /**
     * GET /badges/awards
     * Returns the active child's earned badges. Requires child JWT context.
     */
    public function list_awards( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $awards = Knowly_Badge_Service::get_awards( $ctx['child_id'] );
        return $this->success( [ 'awards' => $awards, 'count' => count( $awards ) ] );
    }

    /**
     * GET /badges/public/{share_token}
     * Public — no authentication. Used by the /badge/{share_token} share page.
     */
    public function public_badge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $share_token = sanitize_hex_color_no_hash( $request['share_token'] );
        if ( ! $share_token || strlen( $share_token ) !== 32 ) {
            return new WP_Error( 'knowly_invalid', 'Invalid share token.', [ 'status' => 400 ] );
        }

        $award = Knowly_Badge_Service::get_award_by_token( $share_token );
        if ( ! $award ) {
            return new WP_Error( 'knowly_not_found', 'Badge not found.', [ 'status' => 404 ] );
        }

        return $this->success( $award );
    }
}
