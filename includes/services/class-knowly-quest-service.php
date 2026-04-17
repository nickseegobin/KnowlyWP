<?php
/**
 * Knowly_Quest_Service — Quest delivery and Railway integration.
 *
 * Flow:
 *  1. get_catalogue()    — calls Railway GET /api/v1/quest/catalogue
 *  2. get_quest()        — calls Railway GET /api/v1/quest/:quest_id
 *  3. start()            — checks attempt count → deducts gems → calls Railway start
 *  4. section_complete() — proxies to Railway POST /api/v1/quest/section-complete
 *  5. complete()         — proxies to Railway POST /api/v1/quest/complete
 *                          → if badge_awarded: true, calls Knowly_Badge_Service::award()
 *
 * Gem costs (Section 4.10 — read from WP options, never hardcoded):
 *   First attempt : knowly_gem_cost_quest_first_{curriculum}  (default 3)
 *   Retake        : knowly_gem_cost_quest_retake_{curriculum} (default 1)
 *   Via assignment: 0 — hardcoded by design
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Quest_Service {

    // ── Gem Cost Helpers ──────────────────────────────────────────────────────

    public static function get_first_attempt_cost( string $curriculum ): int {
        $key    = 'knowly_gem_cost_quest_first_' . sanitize_key( $curriculum );
        $stored = get_option( $key );
        return ( $stored !== false && (int) $stored >= 0 ) ? (int) $stored : 3;
    }

    public static function get_retake_cost( string $curriculum ): int {
        $key    = 'knowly_gem_cost_quest_retake_' . sanitize_key( $curriculum );
        $stored = get_option( $key );
        return ( $stored !== false && (int) $stored >= 0 ) ? (int) $stored : 1;
    }

    // ── Catalogue ─────────────────────────────────────────────────────────────

    /**
     * Fetch the Quest catalogue for a given level and period from Railway.
     *
     * @param  string $level       e.g. 'std_4'
     * @param  string $period      e.g. 'term_1' — empty string for capstone
     * @param  string $curriculum
     * @param  string $subject     Optional — filter by subject
     * @return array|WP_Error
     */
    public static function get_catalogue(
        string $level,
        string $period     = '',
        string $curriculum = 'tt_primary',
        string $subject    = ''
    ): array|WP_Error {
        $params = [ 'curriculum' => $curriculum, 'level' => $level ];
        if ( $period )  $params['period']  = $period;
        if ( $subject ) $params['subject'] = $subject;

        $response = self::railway_get( '/api/v1/quest/catalogue', $params );
        if ( is_wp_error( $response ) ) return $response;

        return $response['quests'] ?? [];
    }

    // ── Fetch Quest Content ───────────────────────────────────────────────────

    /**
     * Fetch full Quest content from Railway.
     *
     * @return array|WP_Error
     */
    public static function get_quest( string $quest_id ): array|WP_Error {
        return self::railway_get( '/api/v1/quest/' . rawurlencode( $quest_id ) );
    }

    // ── Start ─────────────────────────────────────────────────────────────────

    /**
     * Start a Quest session.
     *
     *  1. Check if first attempt (Railway GET /:quest_id/completed)
     *  2. Determine gem cost (0 if assignment)
     *  3. Pre-check gem balance
     *  4. Call Railway POST /quest/start
     *  5. Deduct gems
     *
     * @param  int    $child_id
     * @param  string $quest_id
     * @param  string $source    'direct' | 'assignment'
     * @return array|WP_Error    { session_id, is_first_attempt, balance_after }
     */
    public static function start( int $child_id, string $quest_id, string $source = 'direct' ): array|WP_Error {
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );

        // ── Determine gem cost ────────────────────────────────────────────────
        if ( $source === 'assignment' ) {
            $gem_cost = 0;
        } else {
            $completed = self::has_prior_completion( $child_id, $quest_id );
            if ( is_wp_error( $completed ) ) return $completed;

            $gem_cost = $completed
                ? self::get_retake_cost( $curriculum )
                : self::get_first_attempt_cost( $curriculum );
        }

        // ── Pre-check balance (skip if free) ──────────────────────────────────
        if ( $gem_cost > 0 && ! Knowly_Gem_Service::has_enough( $child_id, $gem_cost ) ) {
            return new WP_Error( 'knowly_insufficient_gems', 'Not enough Blue Gems. Ask your parent to allocate more gems.', [
                'status'   => 402,
                'balance'  => Knowly_Gem_Service::get_balance( $child_id ),
                'required' => $gem_cost,
            ] );
        }

        // ── Call Railway start ────────────────────────────────────────────────
        $response = self::railway_post( '/api/v1/quest/start', [
            'user_id'  => (string) $child_id,
            'quest_id' => $quest_id,
            'source'   => $source,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $session_id     = $response['session_id']     ?? null;
        $is_first_attempt = $response['is_first_attempt'] ?? true;

        if ( ! $session_id ) {
            return new WP_Error( 'knowly_railway_error', 'Quest service did not return a session ID.', [ 'status' => 503 ] );
        }

        // ── Deduct gems ───────────────────────────────────────────────────────
        $balance_after = Knowly_Gem_Service::get_balance( $child_id );

        if ( $gem_cost > 0 ) {
            $deduction = Knowly_Gem_Service::deduct(
                $child_id,
                $gem_cost,
                'spent',
                $curriculum,
                $session_id,
                'Quest started: ' . $quest_id
            );
            if ( is_wp_error( $deduction ) ) return $deduction;
            $balance_after = $deduction['balance_after'];
        }

        Knowly_Debug::log( 'quest.start', 'Quest session started', [
            'child_id'        => $child_id,
            'quest_id'        => $quest_id,
            'session_id'      => $session_id,
            'gem_cost'        => $gem_cost,
            'is_first_attempt' => $is_first_attempt,
            'source'          => $source,
        ], $child_id, 'info' );

        return [
            'session_id'       => $session_id,
            'is_first_attempt' => $is_first_attempt,
            'gem_cost'         => $gem_cost,
            'balance_after'    => $balance_after,
        ];
    }

    // ── Section Complete ──────────────────────────────────────────────────────

    /**
     * Proxy a section completion to Railway.
     *
     * @return array|WP_Error  { sections_completed, quest_complete, next_section_index }
     */
    public static function section_complete( string $session_id, string $section_id, int $child_id ): array|WP_Error {
        return self::railway_post( '/api/v1/quest/section-complete', [
            'session_id' => $session_id,
            'section_id' => $section_id,
            'user_id'    => (string) $child_id,
        ] );
    }

    // ── Complete ──────────────────────────────────────────────────────────────

    /**
     * Mark a Quest session complete.
     *
     * If Railway returns badge_awarded: true, write the badge to the child's
     * knowly_earned_badges user meta via Knowly_Badge_Service::award().
     *
     * @return array|WP_Error  { completed, badge_awarded, quest_id, badge? }
     */
    public static function complete( string $session_id, int $child_id ): array|WP_Error {
        $response = self::railway_post( '/api/v1/quest/complete', [
            'session_id' => $session_id,
            'user_id'    => (string) $child_id,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $badge_awarded = ! empty( $response['badge_awarded'] );
        $quest_id      = $response['quest_id'] ?? '';

        $result = [
            'completed'    => $response['completed']     ?? ( $response['already_complete'] ?? false ),
            'badge_awarded' => $badge_awarded,
            'quest_id'     => $quest_id,
        ];

        // Write badge to WP user meta if awarded
        if ( $badge_awarded && $quest_id ) {
            $badge_result = Knowly_Badge_Service::award( $child_id, $quest_id );
            if ( ! is_wp_error( $badge_result ) ) {
                $result['badge'] = $badge_result;
            }
        }

        Knowly_Debug::log( 'quest.complete', 'Quest session completed', [
            'child_id'     => $child_id,
            'session_id'   => $session_id,
            'quest_id'     => $quest_id,
            'badge_awarded' => $badge_awarded,
        ], $child_id, 'info' );

        return $result;
    }

    // ── Prior Completion Check ────────────────────────────────────────────────

    private static function has_prior_completion( int $child_id, string $quest_id ): bool|WP_Error {
        $response = self::railway_get(
            '/api/v1/quest/' . rawurlencode( $quest_id ) . '/completed',
            [ 'user_id' => (string) $child_id ]
        );
        if ( is_wp_error( $response ) ) return $response;
        return ! empty( $response['completed'] );
    }

    // ── Railway HTTP Helpers ──────────────────────────────────────────────────

    /**
     * Generate a short-lived JWT for server-to-server Railway calls.
     * Mirrors the approach used in Knowly_Admin_Pool::ajax_quest_catalogue().
     */
    private static function get_railway_token(): string {
        $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        if ( ! empty( $admin_ids ) ) {
            return Knowly_JWT::encode( (int) $admin_ids[0] );
        }
        // Fallback to stored key if no admin user exists
        return get_option( 'knowly_railway_api_key', '' );
    }

    private static function railway_get( string $path, array $params = [] ): array|WP_Error {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $url = $endpoint . $path;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . self::get_railway_token(),
                'Content-Type'  => 'application/json',
            ],
        ] );

        return self::parse_response( $response );
    }

    private static function railway_post( string $path, array $body ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $headers = [
            'Authorization' => 'Bearer ' . self::get_railway_token(),
            'Content-Type'  => 'application/json',
        ];
        if ( $server_key ) {
            $headers['X-AEP-Server-Key'] = $server_key;
        }

        $response = wp_remote_post( $endpoint . $path, [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
        ] );

        return self::parse_response( $response );
    }

    private static function parse_response( $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'quest.railway', 'Railway HTTP error', [ 'error' => $response->get_error_message() ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', 'Failed to connect to Quest service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 404 ) {
            return new WP_Error( 'knowly_not_found', $body['error'] ?? 'Quest not found.', [ 'status' => 404 ] );
        }

        if ( $code < 200 || $code >= 300 || empty( $body ) ) {
            Knowly_Debug::log( 'quest.railway', 'Railway bad response', [ 'http_code' => $code, 'body' => $body ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', $body['error'] ?? 'Quest service returned an error.', [ 'status' => 503 ] );
        }

        return $body;
    }
}
