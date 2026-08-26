<?php
/**
 * Knowly_Design_API — REST endpoint for site-wide design / animation settings.
 *
 * GET /knowly/v1/design  Open.  React app fetches to get Lottie animation URLs.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Design_API extends Knowly_API_Base {

    public function register_routes(): void {
        register_rest_route( KNOWLY_REST_NAMESPACE, '/design', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_settings' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function get_settings( WP_REST_Request $request ): WP_REST_Response {
        return $this->success( self::current_settings() );
    }

    public static function current_settings(): array {
        return [
            'loading_lottie' => (string) get_option( 'knowly_design_general_lottie', '' ),
            'home_lottie'    => (string) get_option( 'knowly_design_home_lottie',    '' ),
            'home_image'     => (string) get_option( 'knowly_design_home_image',      '' ),
            'home_scale'     => (int)    get_option( 'knowly_design_home_scale',      3  ),
            'student_lottie' => (string) get_option( 'knowly_design_student_lottie', '' ),
            'parent_lottie'  => (string) get_option( 'knowly_design_parent_lottie',  '' ),
            'teacher_lottie' => (string) get_option( 'knowly_design_teacher_lottie', '' ),
        ];
    }
}
