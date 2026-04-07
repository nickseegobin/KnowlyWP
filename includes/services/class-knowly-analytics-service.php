<?php
/**
 * Knowly_Analytics_Service — Class and per-student analytics.
 *
 * Owns access control (teacher must own the class) then proxies to Railway,
 * merging WP user data (nickname, level) into the response.
 *
 * Railway routes (X-AEP-Server-Key auth):
 *   GET /api/v1/analytics/class?user_ids=1,2,3
 *   GET /api/v1/analytics/student?user_id=5
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Analytics_Service {

    // ── Class Analytics ───────────────────────────────────────────────────────

    /**
     * Fetch aggregate analytics for all active members of a class.
     *
     * @param  int $class_id
     * @param  int $teacher_user_id
     * @return array|WP_Error
     */
    public static function get_class_analytics( int $class_id, int $teacher_user_id ): array|WP_Error {
        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_user_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $members = Knowly_Class_Service::get_members( $class_id );

        if ( empty( $members ) ) {
            return [
                'class_id'       => $class_id,
                'student_count'  => 0,
                'total_trials'   => 0,
                'total_quests'   => 0,
                'total_badges'   => 0,
                'class_avg_score' => null,
                'most_active_subject' => null,
                'direct_count'   => 0,
                'assignment_count' => 0,
                'students'       => [],
            ];
        }

        $user_ids = array_map( fn( $m ) => (string) $m['child_id'], $members );

        $response = self::railway_get( '/api/v1/analytics/class', [
            'user_ids' => implode( ',', $user_ids ),
        ] );

        if ( is_wp_error( $response ) ) return $response;

        // Merge WP nickname + level into each student row
        $member_map = [];
        foreach ( $members as $m ) {
            $member_map[ (string) $m['child_id'] ] = $m;
        }

        if ( ! empty( $response['students'] ) ) {
            $response['students'] = array_map( function ( array $student ) use ( $member_map ): array {
                $uid = (string) ( $student['user_id'] ?? '' );
                if ( isset( $member_map[ $uid ] ) ) {
                    $student['nickname']  = $member_map[ $uid ]['nickname'];
                    $student['level']     = $member_map[ $uid ]['level'];
                    $student['joined_at'] = $member_map[ $uid ]['joined_at'];
                }
                return $student;
            }, $response['students'] );
        }

        $response['class_id'] = $class_id;
        return $response;
    }

    // ── Student Analytics ─────────────────────────────────────────────────────

    /**
     * Fetch per-student analytics for a class member.
     *
     * @param  int $class_id
     * @param  int $student_id
     * @param  int $teacher_user_id
     * @return array|WP_Error
     */
    public static function get_student_analytics( int $class_id, int $student_id, int $teacher_user_id ): array|WP_Error {
        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_user_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        // Confirm student is an active member of this class
        $members = Knowly_Class_Service::get_members( $class_id );
        $is_member = ! empty( array_filter( $members, fn( $m ) => (int) $m['child_id'] === $student_id ) );

        if ( ! $is_member ) {
            return new WP_Error( 'knowly_forbidden', 'This student is not a member of your class.', [ 'status' => 403 ] );
        }

        $response = self::railway_get( '/api/v1/analytics/student', [
            'user_id' => (string) $student_id,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        // Merge WP profile data
        $response['nickname'] = get_user_meta( $student_id, 'knowly_nickname', true ) ?: '';
        $response['level']    = get_user_meta( $student_id, 'knowly_level',    true ) ?: '';

        return $response;
    }

    // ── Railway HTTP Helper ───────────────────────────────────────────────────

    private static function railway_get( string $path, array $params = [] ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $url = $endpoint . $path;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'analytics.railway', 'Railway HTTP error', [ 'error' => $response->get_error_message() ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', 'Failed to connect to analytics service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || empty( $body ) ) {
            Knowly_Debug::log( 'analytics.railway', 'Railway bad response', [ 'http_code' => $code, 'body' => $body ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', $body['error'] ?? 'Analytics service returned an error.', [ 'status' => 503 ] );
        }

        return $body;
    }
}
