<?php
/**
 * Knowly_Analytics_API — Class and per-student analytics endpoints.
 *
 *   GET /knowly/v1/analytics/class/{class_id}                  Teacher only
 *   GET /knowly/v1/analytics/class/{class_id}/student/{user_id} Teacher only
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
            ],
        ] );

        register_rest_route( $ns, '/analytics/class/(?P<class_id>\d+)/student/(?P<user_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'student_analytics' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'class_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'user_id'  => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * GET /analytics/class/{class_id}
     */
    public function class_analytics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $result = Knowly_Analytics_Service::get_class_analytics(
            (int) $request['class_id'],
            $teacher_id
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    /**
     * GET /analytics/class/{class_id}/student/{user_id}
     */
    public function student_analytics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $result = Knowly_Analytics_Service::get_student_analytics(
            (int) $request['class_id'],
            (int) $request['user_id'],
            $teacher_id
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
