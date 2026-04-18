<?php
/**
 * Knowly_Analytics_Service — Class, per-student, and self analytics.
 *
 * Owns access control (teacher must own the class / parent must own the child),
 * then proxies to Railway, merging WP user data into the response.
 *
 * Railway routes (X-AEP-Server-Key auth):
 *   GET /api/v1/analytics/class?user_ids=1,2&period=term_1&subject=math
 *   GET /api/v1/analytics/student?user_id=5&period=term_1&subject=math
 *
 * Railway route (JWT auth):
 *   GET /api/v1/analytics/self?period=term_1&subject=math
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Analytics_Service {

    // ── Class Analytics ───────────────────────────────────────────────────────

    /**
     * Fetch aggregate analytics for all active members of a class.
     *
     * @param  int   $class_id
     * @param  int   $teacher_user_id
     * @param  array $filters  Optional: ['period' => string, 'subject' => string]
     * @return array|WP_Error
     */
    public static function get_class_analytics( int $class_id, int $teacher_user_id, array $filters = [] ): array|WP_Error {
        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_user_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $members = Knowly_Class_Service::get_members( $class_id );

        if ( empty( $members ) ) {
            return [
                'class_id'            => $class_id,
                'student_count'       => 0,
                'total_trials'        => 0,
                'total_quests'        => 0,
                'total_badges'        => 0,
                'class_avg_score'     => null,
                'most_active_subject' => null,
                'avg_engagement_rate' => 0,
                'at_risk_count'       => 0,
                'direct_count'        => 0,
                'assignment_count'    => 0,
                'topic_heatmap'       => [],
                'strengths'           => [],
                'weaknesses'          => [],
                'students'            => [],
                'filters'             => $filters,
            ];
        }

        $user_ids = array_map( fn( $m ) => (string) $m['child_id'], $members );

        $params = [ 'user_ids' => implode( ',', $user_ids ) ];
        if ( ! empty( $filters['period'] ) )  $params['period']  = $filters['period'];
        if ( ! empty( $filters['subject'] ) ) $params['subject'] = $filters['subject'];

        $response = self::railway_get( '/api/v1/analytics/class', $params );
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

    // ── Student Analytics (Teacher view) ─────────────────────────────────────

    /**
     * Fetch per-student analytics for a class member (teacher viewing).
     *
     * @param  int   $class_id
     * @param  int   $student_id
     * @param  int   $teacher_user_id
     * @param  array $filters  Optional: ['period' => string, 'subject' => string]
     * @return array|WP_Error
     */
    public static function get_student_analytics( int $class_id, int $student_id, int $teacher_user_id, array $filters = [] ): array|WP_Error {
        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_user_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        // Confirm student is an active member of this class
        $members   = Knowly_Class_Service::get_members( $class_id );
        $is_member = ! empty( array_filter( $members, fn( $m ) => (int) $m['child_id'] === $student_id ) );

        if ( ! $is_member ) {
            return new WP_Error( 'knowly_forbidden', 'This student is not a member of your class.', [ 'status' => 403 ] );
        }

        $params = [ 'user_id' => (string) $student_id ];
        if ( ! empty( $filters['period'] ) )  $params['period']  = $filters['period'];
        if ( ! empty( $filters['subject'] ) ) $params['subject'] = $filters['subject'];

        $response = self::railway_get( '/api/v1/analytics/student', $params );
        if ( is_wp_error( $response ) ) return $response;

        // Merge WP profile data
        $response['nickname'] = get_user_meta( $student_id, 'knowly_nickname', true ) ?: '';
        $response['level']    = get_user_meta( $student_id, 'knowly_level',    true ) ?: '';

        return $response;
    }

    // ── Self Analytics (Parent / Student view) ────────────────────────────────

    /**
     * Fetch analytics for the calling user's own child.
     *
     * The JWT identifies the parent WP user. If the parent has exactly one child,
     * that child's analytics are returned. If multiple children exist, a summary
     * of all linked children is returned (individual drill-down uses the student
     * endpoint with an explicit child_id in future).
     *
     * @param  int   $parent_user_id  WP user ID from JWT.
     * @param  array $filters         Optional: ['period' => string, 'subject' => string]
     * @return array|WP_Error
     */
    public static function get_self_analytics( int $parent_user_id, array $filters = [] ): array|WP_Error {
        // Resolve linked children for this parent
        $children = Knowly_Children_Service::list_children( $parent_user_id );

        if ( empty( $children ) ) {
            return new WP_Error( 'knowly_not_found', 'No children linked to this account.', [ 'status' => 404 ] );
        }

        // Single child — return their full analytics
        if ( count( $children ) === 1 ) {
            $child = $children[0];
            return self::get_child_analytics( (int) $child['child_id'], $filters );
        }

        // Multiple children — return a summary array
        $results = [];
        foreach ( $children as $child ) {
            $data = self::get_child_analytics( (int) $child['child_id'], $filters );
            if ( ! is_wp_error( $data ) ) {
                $results[] = array_merge( $data, [
                    'nickname'     => $child['display_name'] ?? '',
                    'avatar_index' => $child['avatar_index'] ?? 1,
                ] );
            }
        }

        return [ 'children' => $results ];
    }

    // ── Internal: fetch analytics for one child ───────────────────────────────

    private static function get_child_analytics( int $child_id, array $filters = [] ): array|WP_Error {
        $params = [ 'user_id' => (string) $child_id ];
        if ( ! empty( $filters['period'] ) )  $params['period']  = $filters['period'];
        if ( ! empty( $filters['subject'] ) ) $params['subject'] = $filters['subject'];

        $response = self::railway_get( '/api/v1/analytics/student', $params );
        if ( is_wp_error( $response ) ) return $response;

        // Merge WP nickname / level
        $response['nickname']     = get_user_meta( $child_id, 'knowly_nickname', true ) ?: '';
        $response['level']        = get_user_meta( $child_id, 'knowly_level',    true ) ?: '';
        $response['avatar_index'] = (int) ( get_user_meta( $child_id, 'knowly_avatar_index', true ) ?: 1 );

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
