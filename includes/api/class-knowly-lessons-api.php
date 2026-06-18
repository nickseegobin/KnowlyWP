<?php
/**
 * Knowly_Lessons_API — Lesson delivery endpoints.
 *
 * Child / parent routes (JWT — active child context):
 *   GET    /knowly/v1/lessons                          Lesson catalogue (all approved quests for level/period)
 *   GET    /knowly/v1/lessons/{quest_id}               Lesson content (same training data as Quests)
 *   GET    /knowly/v1/lessons/{quest_id}/questions     Lesson questions (no correct_answer)
 *   POST   /knowly/v1/lessons/start                    Start Lesson — deducts gems, creates WP session
 *   POST   /knowly/v1/lessons/complete                 Complete Lesson — marks session done (no badge)
 *   POST   /knowly/v1/lessons/submit-questions         Submit answers — scored and recorded silently
 *
 * Teacher route (JWT — teacher context):
 *   GET    /knowly/v1/lessons/teacher/catalogue        Lesson catalogue for a given level/period/subject
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Lessons_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        // Static sub-routes must be registered before /{quest_id} to avoid collisions

        register_rest_route( $ns, '/lessons/teacher/catalogue', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'teacher_catalogue' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'level'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'period'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'subject' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/lessons/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'quest_id' => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'source'   => [ 'required' => false, 'type' => 'string',  'default' => 'direct',
                                'enum' => [ 'direct', 'assignment' ] ],
                'task_id'  => [ 'required' => false, 'type' => 'integer', 'default' => null ],
            ],
        ] );

        register_rest_route( $ns, '/lessons/complete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'complete' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'session_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/lessons/submit-questions', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_questions' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'session_id' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'quest_id'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'answers'    => [ 'required' => true,  'type' => 'object' ],
            ],
        ] );

        register_rest_route( $ns, '/lessons', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'catalogue' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'subject' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/lessons/(?P<quest_id>[a-zA-Z0-9_\-]+)/questions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'questions' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/lessons/(?P<quest_id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'show' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function catalogue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $child_id = $ctx['child_id'];

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

        $lessons = Knowly_Lesson_Service::get_catalogue( $level, $period, 'tt_primary', $subject );
        if ( is_wp_error( $lessons ) ) return $lessons;

        return $this->success( [ 'lessons' => $lessons, 'count' => count( $lessons ) ] );
    }

    public function teacher_catalogue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $level   = sanitize_text_field( $request->get_param( 'level' )   ?: '' );
        $period  = sanitize_text_field( $request->get_param( 'period' )  ?: '' );
        $subject = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );

        if ( ! $level ) {
            return new WP_Error( 'knowly_missing_fields', 'level is required.', [ 'status' => 422 ] );
        }

        $lessons = Knowly_Lesson_Service::get_catalogue( $level, $period, 'tt_primary', $subject );
        if ( is_wp_error( $lessons ) ) return $lessons;

        return $this->success( [ 'lessons' => $lessons, 'count' => count( $lessons ) ] );
    }

    public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        $child_id = is_wp_error( $ctx ) ? null : $ctx['child_id'];

        $lesson = Knowly_Lesson_Service::get_lesson( $request['quest_id'], $child_id );
        if ( is_wp_error( $lesson ) ) return $lesson;

        return $this->success( $lesson );
    }

    public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Lesson_Service::start(
            $ctx['child_id'],
            $request->get_param( 'quest_id' ),
            $request->get_param( 'source' ),
            $request->get_param( 'task_id' ) ? (int) $request->get_param( 'task_id' ) : null
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Lesson_Service::complete(
            $request->get_param( 'session_id' ),
            $ctx['child_id']
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function questions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $result = Knowly_Lesson_Service::get_questions( $request['quest_id'] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function submit_questions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $ctx = $this->require_child_context( $request );
        if ( is_wp_error( $ctx ) ) return $ctx;

        $answers = $request->get_param( 'answers' );
        if ( ! is_array( $answers ) || empty( $answers ) ) {
            return new WP_Error( 'knowly_invalid_answers', 'answers must be a non-empty object.', [ 'status' => 422 ] );
        }

        $result = Knowly_Lesson_Service::submit_questions(
            $request->get_param( 'session_id' ),
            $request->get_param( 'quest_id' ),
            $ctx['child_id'],
            $answers
        );

        return is_wp_error( $result ) ? $result : $this->success( $result );
    }
}
