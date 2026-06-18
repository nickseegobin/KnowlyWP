<?php
/**
 * Knowly_Analytics_API — Class and per-student analytics endpoints.
 *
 *   GET /knowly/v1/analytics/class/{class_id}                   Teacher only
 *   GET /knowly/v1/analytics/class/{class_id}/student/{user_id}  Teacher only
 *   GET /knowly/v1/analytics/self                                JWT auth (parent/student)
 *
 * Optional query params for all three: period, subject
 *
 * Access control: teacher must own the class (enforced in service).
 * Student must be an active class member for per-student endpoint.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Analytics_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/analytics/class/(?P<class_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'class_analytics' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'class_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'period'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/analytics/class/(?P<class_id>\d+)/student/(?P<user_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'student_analytics' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'class_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'user_id'  => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'period'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/analytics/self', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'self_analytics' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'period'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/analytics/child-self', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'child_self_analytics' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'period'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // ── History (unified feed + per-type detail) ──────────────────────────

        register_rest_route( $ns, '/analytics/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'history' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'type'     => [ 'required' => false, 'type' => 'string',  'default' => 'all',
                                'enum' => [ 'all', 'trials', 'quests', 'lessons' ] ],
                'page'     => [ 'required' => false, 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
                'per_page' => [ 'required' => false, 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                'month'    => [ 'required' => false, 'type' => 'integer', 'default' => 0,  'minimum' => 0, 'maximum' => 12 ],
                'year'     => [ 'required' => false, 'type' => 'integer', 'default' => 0,  'minimum' => 0 ],
            ],
        ] );

        register_rest_route( $ns, '/analytics/history/quests/(?P<session_id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'quest_detail' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/analytics/history/lessons/(?P<session_id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'lesson_detail' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * GET /analytics/class/{class_id}[?period=term_1&subject=math]
     */
    public function class_analytics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $filters = [
            'period'  => sanitize_text_field( $request->get_param( 'period' )  ?? '' ),
            'subject' => sanitize_text_field( $request->get_param( 'subject' ) ?? '' ),
        ];

        $result = Knowly_Analytics_Service::get_class_analytics(
            (int) $request['class_id'],
            $teacher_id,
            $filters
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * GET /analytics/class/{class_id}/student/{user_id}[?period=term_1&subject=math]
     */
    public function student_analytics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $filters = [
            'period'  => sanitize_text_field( $request->get_param( 'period' )  ?? '' ),
            'subject' => sanitize_text_field( $request->get_param( 'subject' ) ?? '' ),
        ];

        $result = Knowly_Analytics_Service::get_student_analytics(
            (int) $request['class_id'],
            (int) $request['user_id'],
            $teacher_id,
            $filters
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * GET /analytics/self[?period=term_1&subject=math]
     *
     * JWT-authenticated. Parent or student calling on their own behalf.
     * The JWT identifies a WP user (parent); the child linked to that parent
     * is resolved in the service so parents see their child's analytics.
     */
    public function self_analytics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $filters = [
            'period'  => sanitize_text_field( $request->get_param( 'period' )  ?? '' ),
            'subject' => sanitize_text_field( $request->get_param( 'subject' ) ?? '' ),
        ];

        $result = Knowly_Analytics_Service::get_self_analytics( $user_id, $filters );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * GET /analytics/child-self[?period=term_1&subject=math]
     *
     * Accepts either a child JWT (child viewing their own stats) or a parent JWT
     * (parent viewing their active child's stats). Uses require_child_context()
     * to resolve the correct child_id for both cases.
     */
    public function child_self_analytics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $child_id = $ctx['child_id'];

        $filters = [
            'period'  => sanitize_text_field( $request->get_param( 'period' )  ?? '' ),
            'subject' => sanitize_text_field( $request->get_param( 'subject' ) ?? '' ),
        ];

        $data                 = Knowly_Analytics_Service::get_student_data( $child_id, $filters );
        $data['nickname']     = get_user_meta( $child_id, 'knowly_nickname',     true ) ?: '';
        $data['avatar_index'] = (int) ( get_user_meta( $child_id, 'knowly_avatar_index', true ) ?: 1 );

        return $this->success( $data );
    }

    // ── History handlers ──────────────────────────────────────────────────────

    /**
     * GET /analytics/history[?type=all|trials|quests|lessons&page=1&per_page=20]
     *
     * Unified chronological feed of completed sessions.
     * Auth: child JWT or parent JWT (parent sees active child's data).
     */
    public function history( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_History_Service::get_history(
            $ctx['child_id'],
            sanitize_text_field( $request->get_param( 'type' ) ?: 'all' ),
            max( 1, (int) $request->get_param( 'page' ) ),
            min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
            (int) $request->get_param( 'month' ),
            (int) $request->get_param( 'year' )
        );

        return $this->success( $result );
    }

    /**
     * GET /analytics/history/quests/{session_id}
     *
     * Full quest session detail with per-question results.
     */
    public function quest_detail( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_History_Service::get_quest_detail(
            sanitize_text_field( $request['session_id'] ),
            $ctx['child_id']
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * GET /analytics/history/lessons/{session_id}
     *
     * Full lesson session detail with per-question results.
     */
    public function lesson_detail( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_History_Service::get_lesson_detail(
            sanitize_text_field( $request['session_id'] ),
            $ctx['child_id']
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
