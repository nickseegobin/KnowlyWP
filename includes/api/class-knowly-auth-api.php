<?php
/**
 * Knowly_Auth_API — Authentication endpoints.
 *
 * v2.2 (Block 2):
 *  - /auth/register/parent  — canonical parent registration path (aliases /auth/register)
 *  - /auth/register/teacher — new teacher registration (pending_approval)
 *  - /auth/password/reset   — trigger WP core password reset email
 *  - login updated          — allows knowly_teacher role, returns teacher profile shape
 *  - /auth/me updated       — returns teacher branch
 *
 * Routes:
 *   POST  /knowly/v1/auth/register          Open       Register parent (legacy path)
 *   POST  /knowly/v1/auth/register/parent   Open       Register parent (canonical)
 *   POST  /knowly/v1/auth/register/teacher  Open       Register teacher (pending_approval)
 *   POST  /knowly/v1/auth/login             Open       Login → JWT
 *   GET   /knowly/v1/auth/me                JWT        Current user profile
 *   PATCH /knowly/v1/auth/profile           JWT parent Update name and/or avatar_index
 *   POST  /knowly/v1/auth/password/reset    Open       Trigger WP password reset email
 *   POST  /knowly/v1/auth/pin/set           JWT parent Set / update PIN
 *   POST  /knowly/v1/auth/pin/verify        JWT        Verify parent PIN
 *   GET   /knowly/v1/auth/pin/status        JWT parent PIN status
 *   GET   /knowly/v1/ping                   Open       Health check
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Auth_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        $parent_args = [
            'first_name'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'last_name'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'email'        => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
            'password'     => [ 'required' => true,  'type' => 'string' ],
            'phone'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'avatar_index' => [ 'required' => false, 'type' => 'integer' ],
        ];

        // Legacy path — kept for backwards compatibility
        register_rest_route( $ns, '/auth/register', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'register' ],
            'permission_callback' => '__return_true',
            'args'                => $parent_args,
        ] );

        // Canonical parent registration path (spec: POST /auth/register/parent)
        register_rest_route( $ns, '/auth/register/parent', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'register' ],
            'permission_callback' => '__return_true',
            'args'                => $parent_args,
        ] );

        // Teacher registration
        register_rest_route( $ns, '/auth/register/teacher', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'register_teacher' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'first_name'        => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'last_name'         => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'email'             => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
                'password'          => [ 'required' => true,  'type' => 'string' ],
                'school_name'       => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'class_name'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'phone'             => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'principal_name'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'principal_contact' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'login' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'username' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'password' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'me' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/auth/profile', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'update_profile' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'first_name'   => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'last_name'    => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'display_name' => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'avatar_index' => [ 'required' => false, 'type' => 'integer' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/teacher/profile', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'update_teacher_profile' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'first_name'        => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'last_name'         => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'avatar_index'      => [ 'required' => false, 'type' => 'integer' ],
                'school_name'       => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'class_name'        => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'phone'             => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'principal_name'    => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'principal_contact' => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/password/reset', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'password_reset' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/pin/set', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'set_pin' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'pin' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/pin/verify', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'verify_pin' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'pin' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/pin/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'pin_status' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/ping', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'ping' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Knowly_Auth_Service::register( [
            'first_name'   => $request->get_param( 'first_name' ),
            'last_name'    => $request->get_param( 'last_name' ),
            'email'        => $request->get_param( 'email' ),
            'password'     => $request->get_param( 'password' ),
            'phone'        => $request->get_param( 'phone' ),
            'avatar_index' => $request->get_param( 'avatar_index' ),
        ] );
        return is_wp_error( $result ) ? $result : $this->created( $result );
    }

    public function register_teacher( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Knowly_Teacher_Service::register( [
            'first_name'        => $request->get_param( 'first_name' ),
            'last_name'         => $request->get_param( 'last_name' ),
            'email'             => $request->get_param( 'email' ),
            'password'          => $request->get_param( 'password' ),
            'school_name'       => $request->get_param( 'school_name' ),
            'class_name'        => $request->get_param( 'class_name' ),
            'phone'             => $request->get_param( 'phone' ),
            'principal_name'    => $request->get_param( 'principal_name' ),
            'principal_contact' => $request->get_param( 'principal_contact' ),
        ] );
        return is_wp_error( $result ) ? $result : $this->created( $result );
    }

    public function password_reset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $email = $request->get_param( 'email' );
        $user  = get_user_by( 'email', $email );

        // Always return 200 to avoid user enumeration
        if ( ! $user ) {
            return $this->success( [ 'message' => 'If that email is registered, a reset link has been sent.' ] );
        }

        $result = retrieve_password( $user->user_login );

        if ( is_wp_error( $result ) ) {
            Knowly_Debug::log( 'auth.password_reset', 'retrieve_password failed', [
                'email' => $email,
                'error' => $result->get_error_message(),
            ], $user->ID, 'error' );
            return new WP_Error( 'knowly_reset_failed', 'Password reset failed. Please try again.', [ 'status' => 500 ] );
        }

        Knowly_Debug::log( 'auth.password_reset', 'Password reset email sent', [ 'user_id' => $user->ID ], $user->ID, 'info' );

        return $this->success( [ 'message' => 'If that email is registered, a reset link has been sent.' ] );
    }

    public function login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = Knowly_Auth_Service::login(
            $request->get_param( 'username' ),
            $request->get_param( 'password' )
        );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function me( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $profile = Knowly_Auth_Service::get_profile( $user_id );
        return is_wp_error( $profile ) ? $profile : $this->success( $profile );
    }

    public function update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Knowly_Auth_Service::update_profile( $parent_id, [
            'first_name'   => $request->get_param( 'first_name' ),
            'last_name'    => $request->get_param( 'last_name' ),
            'display_name' => $request->get_param( 'display_name' ),
            'avatar_index' => $request->get_param( 'avatar_index' ),
        ] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function update_teacher_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $result = Knowly_Teacher_Service::update_profile( $teacher_id, [
            'first_name'        => $request->get_param( 'first_name' ),
            'last_name'         => $request->get_param( 'last_name' ),
            'avatar_index'      => $request->get_param( 'avatar_index' ),
            'school_name'       => $request->get_param( 'school_name' ),
            'class_name'        => $request->get_param( 'class_name' ),
            'phone'             => $request->get_param( 'phone' ),
            'principal_name'    => $request->get_param( 'principal_name' ),
            'principal_contact' => $request->get_param( 'principal_contact' ),
        ] );
        return is_wp_error( $result ) ? $result : $this->success( $result );
    }

    public function set_pin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Knowly_Auth_Service::set_pin( $parent_id, $request->get_param( 'pin' ) );
        return is_wp_error( $result ) ? $result : $this->success( [ 'pin_set' => true ] );
    }

    public function verify_pin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        $result = Knowly_Auth_Service::verify_pin( $parent_id, $request->get_param( 'pin' ) );
        return is_wp_error( $result ) ? $result : $this->success( [ 'verified' => true ] );
    }

    public function pin_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $parent_id = $this->require_parent( $request );
        if ( is_wp_error( $parent_id ) ) return $parent_id;

        return $this->success( Knowly_Auth_Service::get_pin_status( $parent_id ) );
    }

    public function ping(): WP_REST_Response {
        return $this->success( [
            'status'  => 'ok',
            'version' => KNOWLY_VERSION,
            'time'    => current_time( 'mysql', true ),
        ] );
    }
}