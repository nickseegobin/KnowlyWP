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
}
