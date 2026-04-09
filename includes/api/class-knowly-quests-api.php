<?php
/**
 * Knowly_Quests_API — Quest delivery endpoints.
 *
 * Child / parent routes (JWT — active child context):
 *   GET    /knowly/v1/quests                          Quest catalogue for child's level + period
 *   GET    /knowly/v1/quests/{quest_id}               Fetch Quest content (wraps Railway)
 *   POST   /knowly/v1/quests/start                    Start Quest — deducts gems, creates session
 *   POST   /knowly/v1/quests/complete                 Complete Quest — badge awarded if first finish
 *   POST   /knowly/v1/quests/{session_id}/section-complete  Mark section done
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Quests_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        // Static sub-routes must be registered before /{quest_id} to avoid collisions
        register_rest_route( $ns, '/quests/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'quest_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'source'   => [ 'required' => false, 'type' => 'string', 'default' => 'direct',
                                'enum' => [ 'direct', 'assignment' ] ],
            ],
        ] );

        register_rest_route( $ns, '/quests/complete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'complete' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'session_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/quests', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'catalogue' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'subject' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/quests/(?P<quest_id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'show' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/quests/(?P<session_id>[a-zA-Z0-9_\-]+)/section-complete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'section_complete' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'section_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function catalogue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $child_id = $ctx['child_id'];

        // Level and period live in knowly_children table, NOT in wp_usermeta.
        global $wpdb;
        $child_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT level, period FROM {$wpdb->prefix}knowly_children WHERE child_id = %d",
                $child_id
            ),
            ARRAY_A
        );
        $level  = $child_row['level']  ?? '';
        $period = $child_row['period'] ?? '';

        $subject  = $request->get_param( 'subject' ) ?: '';

        if ( ! $level ) {
            return new WP_Error( 'knowly_missing_profile', 'Student profile is missing a level.', [ 'status' => 422 ] );
        }

        $quests = Knowly_Quest_Service::get_catalogue( $level, $period, 'tt_primary', $subject );
        if ( is_wp_error( $quests ) ) return $quests;

        return $this->success( [ 'quests' => $quests, 'count' => count( $quests ) ] );
    }

    public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $quest = Knowly_Quest_Service::get_quest( $request['quest_id'] );
        if ( is_wp_error( $quest ) ) return $quest;

        return $this->success( $quest );
    }

    public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Quest_Service::start(
            $ctx['child_id'],
            $request->get_param( 'quest_id' ),
            $request->get_param( 'source' )
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function section_complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Quest_Service::section_complete(
            $request['session_id'],
            $request->get_param( 'section_id' ),
            $ctx['child_id']
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Quest_Service::complete(
            $request->get_param( 'session_id' ),
            $ctx['child_id']
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
