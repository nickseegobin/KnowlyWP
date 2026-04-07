<?php
/**
 * Knowly_Badges_API — Badge endpoints.
 *
 *   POST /knowly/v1/badges/award         Internal — write badge after Railway returns badge_awarded:true
 *   GET  /knowly/v1/badges/{user_id}     Fetch all earned badges for a child (React display)
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Badges_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/badges/award', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'award' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'user_id'  => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'quest_id' => [ 'required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/badges/(?P<user_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'index' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'user_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * POST /badges/award
     *
     * Internal endpoint — called by the plugin itself after receiving
     * badge_awarded: true from Railway's quest/complete response.
     * Requires admin JWT (manage_options capability).
     */
    public function award( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $caller = $this->authenticate( $request );
        if ( is_wp_error( $caller ) ) return $caller;

        if ( ! user_can( $caller, 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Admin access required.', [ 'status' => 403 ] );
        }

        $result = Knowly_Badge_Service::award(
            (int) $request->get_param( 'user_id' ),
            $request->get_param( 'quest_id' )
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * GET /badges/{user_id}
     *
     * Returns earned badges for a child. Parent may view their own child's badges;
     * child may view their own; admin may view any.
     */
    public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $caller  = $this->authenticate( $request );
        if ( is_wp_error( $caller ) ) return $caller;

        $user_id = (int) $request['user_id'];

        // Access control
        if (
            $caller !== $user_id
            && ! user_can( $caller, 'manage_options' )
            && ! Knowly_Children_Service::owns_child( $caller, $user_id )
        ) {
            return new WP_Error( 'knowly_forbidden', 'You do not have access to these badges.', [ 'status' => 403 ] );
        }

        $badges = Knowly_Badge_Service::get_earned( $user_id );
        return $this->success( [ 'badges' => $badges, 'count' => count( $badges ) ] );
    }
}
