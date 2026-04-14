<?php
/**
 * Knowly_Exams_API — Exam delivery endpoints.
 *
 * Routes:
 *   GET    /knowly/v1/exams/active                   JWT  Active session for current child (or null)
 *   POST   /knowly/v1/exams/start                    JWT  Start exam (deduct token + serve package)
 *   GET    /knowly/v1/exams/{session_id}/checkpoint  JWT  Get saved checkpoint
 *   POST   /knowly/v1/exams/{session_id}/checkpoint  JWT  Save mid-exam checkpoint
 *   POST   /knowly/v1/exams/{session_id}/submit      JWT  Submit exam answers
 *   DELETE /knowly/v1/exams/{session_id}             JWT  Cancel an active exam session
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Exams_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/exams/active', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'active_session' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/exams/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'level'      => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'period'     => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'difficulty' => [ 'required' => false, 'type' => 'string', 'default' => 'medium', 'enum' => [ 'easy', 'medium', 'hard' ] ],
                'trial_type' => [ 'required' => false, 'type' => 'string', 'default' => 'practice', 'enum' => [ 'practice', 'sea' ], 'sanitize_callback' => 'sanitize_text_field' ],
                'topic'      => [ 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/exams/(?P<session_id>\d+)/checkpoint', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_checkpoint' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_checkpoint' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'state' => [ 'required' => true ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/exams/(?P<session_id>\d+)/submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'answers' => [ 'required' => true, 'type' => 'array' ],
            ],
        ] );

        register_rest_route( $ns, '/exams/(?P<session_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'cancel' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function active_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $session = Knowly_Exam_Service::get_active_session( $ctx['child_id'] );
        return $this->success( [ 'session' => $session ] );
    }

    public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Exam_Service::start(
            $ctx['parent_id'],
            $ctx['child_id'],
            $request->get_param( 'level' ),
            $request->get_param( 'period' ),
            $request->get_param( 'subject' ),
            $request->get_param( 'difficulty' ),
            $request->get_param( 'trial_type' ) ?: 'practice',
            $request->get_param( 'topic' ) ?: ''
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function get_checkpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $raw = get_user_meta( $ctx['child_id'], 'knowly_checkpoint', true );
        if ( ! $raw ) {
            return $this->success( [ 'checkpoint' => null ] );
        }

        $checkpoint = json_decode( $raw, true );

        // Only return if it belongs to this session
        if ( (int) ( $checkpoint['session_id'] ?? 0 ) !== (int) $request['session_id'] ) {
            return $this->success( [ 'checkpoint' => null ] );
        }

        return $this->success( [ 'checkpoint' => $checkpoint ] );
    }

    public function save_checkpoint( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Exam_Service::checkpoint(
            (int) $request['session_id'],
            $ctx['child_id'],
            $request->get_param( 'state' )
        );

        return is_wp_error( $result ) ? $result : $this->success( [ 'saved' => true ] );
    }

    public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Exam_Service::submit(
            (int) $request['session_id'],
            $ctx['child_id'],
            $request->get_param( 'answers' )
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Exam_Service::cancel( (int) $request['session_id'], $ctx['child_id'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
