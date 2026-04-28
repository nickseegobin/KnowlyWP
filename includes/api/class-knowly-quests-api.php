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

        // Teacher-only quest catalogue — no child context required
        register_rest_route( $ns, '/quests/teacher/catalogue', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'teacher_catalogue' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'level'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'period'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/quests/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'quest_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'source'   => [ 'required' => false, 'type' => 'string', 'default' => 'direct',
                                'enum' => [ 'direct', 'assignment' ] ],
                'task_id'  => [ 'required' => false, 'type' => 'integer', 'default' => null ],
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

        // Accept explicit level/period from the React layer (more reliable than meta look-up).
        // Fall back to knowly_children DB row if not supplied.
        $level  = sanitize_text_field( $request->get_param( 'level' )  ?: '' );
        $period = sanitize_text_field( $request->get_param( 'period' ) ?: '' );

        if ( ! $level ) {
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
        }

        $subject = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );

        if ( ! $level ) {
            return new WP_Error( 'knowly_missing_profile', 'Student profile is missing a level.', [ 'status' => 422 ] );
        }

        $quests = Knowly_Quest_Service::get_catalogue( $level, $period, 'tt_primary', $subject );
        if ( is_wp_error( $quests ) ) return $quests;

        return $this->success( [ 'quests' => $quests, 'count' => count( $quests ) ] );
    }

    /**
     * GET /quests/teacher/catalogue?level=std_4&period=term_1&subject=math
     * Teacher-only — returns approved student-variant quests for the given params.
     */
    public function teacher_catalogue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $level   = sanitize_text_field( $request->get_param( 'level' )   ?: '' );
        $period  = sanitize_text_field( $request->get_param( 'period' )  ?: '' );
        $subject = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );

        if ( ! $level ) {
            return new WP_Error( 'knowly_missing_fields', 'level is required.', [ 'status' => 422 ] );
        }

        $quests = Knowly_Quest_Service::get_catalogue( $level, $period, 'tt_primary', $subject );
        if ( is_wp_error( $quests ) ) return $quests;

        return $this->success( [ 'quests' => $quests, 'count' => count( $quests ) ] );
    }

    public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        // If child context resolution fails, fall back to unauthenticated view (no gem_cost personalisation)
        $child_id = is_wp_error( $ctx ) ? null : $ctx['child_id'];

        $quest = Knowly_Quest_Service::get_quest( $request['quest_id'], $child_id );
        if ( is_wp_error( $quest ) ) return $quest;

        return $this->success( $quest );
    }

    public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Quest_Service::start(
            $ctx['child_id'],
            $request->get_param( 'quest_id' ),
            $request->get_param( 'source' ),
            $request->get_param( 'task_id' ) ? (int) $request->get_param( 'task_id' ) : null
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
