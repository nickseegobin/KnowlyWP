<?php
/**
 * Knowly_Exam_Service — Trial delivery and Railway integration.
 *
 * Flow:
 *  1. start() — check gems → call Railway generate-exam (sequential pool) → deduct gem
 *  2. checkpoint() — persist mid-trial state to user meta
 *  3. submit() — pass results to Knowly_Results_Service
 *
 * Railway returns 200 (package from pool) or 503 pool_empty (background generation triggered).
 * Plugin never waits on Claude directly — all AI generation is async on Railway.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Exam_Service {

    // ── Start Exam ────────────────────────────────────────────────────────────

    /**
     * Serve a Trial package and deduct a gem atomically.
     *
     * @param int    $parent_id   Billing account.
     * @param int    $child_id    Learner.
     * @param string $level       e.g. 'std_4'
     * @param string $period      e.g. 'term_1'
     * @param string $subject     e.g. 'math'
     * @param string $difficulty  easy | medium | hard
     * @param string $trial_type  practice | sea_paper (default: practice)
     * @param string $topic       topic slug for std_5 topic practice (nullable)
     * @return array|WP_Error  { session_id, package, balance_after }
     */
    public static function start(
        int    $parent_id,
        int    $child_id,
        string $level,
        string $period,
        string $subject,
        string $difficulty = 'medium',
        string $trial_type = 'practice',
        string $topic = ''
    ): array|WP_Error {
        Knowly_Debug::log( 'exam.start', 'Trial start requested', [
            'parent_id'  => $parent_id,
            'child_id'   => $child_id,
            'level'      => $level,
            'period'     => $period,
            'subject'    => $subject,
            'difficulty' => $difficulty,
            'trial_type' => $trial_type,
            'topic'      => $topic,
        ], $parent_id, 'info' );

        // ── 1. Pre-check gem balance ──────────────────────────────────────────
        if ( ! Knowly_Token_Service::has_enough( $parent_id ) ) {
            Knowly_Debug::log( 'exam.start', 'Gem pre-check failed', [
                'parent_id' => $parent_id,
                'balance'   => Knowly_Token_Service::get_balance( $parent_id ),
            ], $parent_id, 'warning' );
            return new WP_Error( 'knowly_insufficient_gems', 'Not enough Blue Gems. Please purchase more to continue.', [
                'status'  => 402,
                'balance' => Knowly_Token_Service::get_balance( $parent_id ),
            ] );
        }

        // ── 2. Fetch from Railway sequential pool ─────────────────────────────
        $package = self::fetch_from_railway( $child_id, $level, $period, $subject, $difficulty, $trial_type, $topic );

        if ( is_wp_error( $package ) ) {
            return $package;
        }

        // ── 3. Generate session ID ────────────────────────────────────────────
        $external_session_id = self::generate_session_id();

        // ── 4. Deduct gem ─────────────────────────────────────────────────────
        $deduction = Knowly_Token_Service::deduct( $parent_id, 1, $external_session_id, "Trial started: {$subject}" );
        if ( is_wp_error( $deduction ) ) {
            return $deduction;
        }

        // ── 5. Create active session record ───────────────────────────────────
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'knowly_exam_sessions',
            [
                'external_session_id' => $external_session_id,
                'child_id'            => $child_id,
                'parent_id'           => $parent_id,
                'package_id'          => $package['package_id'],
                'subject'             => $subject,
                'level'               => $level,
                'period'              => $period,
                'difficulty'          => $difficulty,
                'trial_type'          => $trial_type,
                'state'               => 'active',
                'started_at'          => current_time( 'mysql', true ),
            ],
            [ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $session_id = $wpdb->insert_id;

        Knowly_Debug::log( 'exam.start', 'Trial session created', [
            'session_id'          => $session_id,
            'external_session_id' => $external_session_id,
            'package_id'          => $package['package_id'],
            'balance_after'       => $deduction['balance_after'],
        ], $parent_id, 'info' );

        // Strip answer sheet from response (security — answers stay server-side or come from Railway with key)
        $safe_package = $package;
        unset( $safe_package['answer_sheet'], $safe_package['answers'] );

        return [
            'session_id'          => $session_id,
            'external_session_id' => $external_session_id,
            'package'             => $safe_package,
            'balance_after'       => $deduction['balance_after'],
        ];
    }

    // ── Checkpoint ────────────────────────────────────────────────────────────

    /**
     * Save mid-exam progress to child user meta.
     */
    public static function checkpoint( int $session_id, int $child_id, array $state ): true|WP_Error {
        // Verify session ownership
        global $wpdb;
        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_exam_sessions WHERE session_id = %d AND child_id = %d AND state = 'active'",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return new WP_Error( 'knowly_session_not_found', 'Active session not found.', [ 'status' => 404 ] );
        }

        update_user_meta( $child_id, 'knowly_checkpoint', wp_json_encode( [
            'session_id' => $session_id,
            'state'      => $state,
            'saved_at'   => current_time( 'mysql', true ),
        ] ) );

        Knowly_Debug::log( 'exam.checkpoint', 'Checkpoint saved', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
        ], $child_id, 'debug' );

        return true;
    }

    // ── Submit ────────────────────────────────────────────────────────────────

    /**
     * Submit a completed exam. Delegates storage to Knowly_Results_Service.
     *
     * @param  int   $session_id
     * @param  int   $child_id
     * @param  array $answers     Raw answer payload from client.
     * @return array|WP_Error     Session summary.
     */
    public static function submit( int $session_id, int $child_id, array $answers ): array|WP_Error {
        Knowly_Debug::log( 'exam.submit', 'Exam submission received', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
            'answers'    => count( $answers ),
        ], $child_id, 'info' );

        // Verify session
        global $wpdb;
        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_exam_sessions WHERE session_id = %d AND child_id = %d AND state = 'active'",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            Knowly_Debug::log( 'exam.submit', 'Session not found or not active', [
                'session_id' => $session_id,
                'child_id'   => $child_id,
            ], $child_id, 'warning' );
            return new WP_Error( 'knowly_session_not_found', 'Active exam session not found.', [ 'status' => 404 ] );
        }

        // Store results
        $result = Knowly_Results_Service::save_submission( $session, $answers );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Clear checkpoint
        delete_user_meta( $child_id, 'knowly_checkpoint' );

        Knowly_Debug::log( 'exam.submit', 'Exam submitted and results saved', [
            'session_id' => $session_id,
            'score'      => $result['percentage'],
        ], $child_id, 'info' );

        // ── Leaderboard upsert (synchronous, non-fatal) ──────────────────────
        // Runs before we respond so new_rank / was_personal_best are accurate.
        // Any failure returns null — exam result is never affected.
        $leaderboard_update = Knowly_Leaderboard_Service::handle_submit_upsert( $session, $result );
 
        // Append to the result array returned to the API layer
        $result['leaderboard_update'] = $leaderboard_update;
 
        // ── END leaderboard upsert ───────────────────────────────────────────
 

        return $result;
    }

    // ── Catalogue ─────────────────────────────────────────────────────────────

    /**
     * Return distinct exam types in the pool with availability counts.
     */
    public static function get_catalogue( array $filters = [] ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $filters['level'] ) ) {
            $where[]  = 'standard = %s';
            $values[] = $filters['level'];
        }
        if ( ! empty( $filters['period'] ) ) {
            $where[]  = 'term = %s';
            $values[] = $filters['period'];
        }
        if ( ! empty( $filters['subject'] ) ) {
            $where[]  = 'subject LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['subject'] ) . '%';
        }
        if ( ! empty( $filters['difficulty'] ) ) {
            $where[]  = 'difficulty = %s';
            $values[] = $filters['difficulty'];
        }

        $sql = "SELECT standard, term, subject, difficulty, COUNT(*) as pool_count
                FROM {$wpdb->prefix}knowly_exam_pool
                WHERE " . implode( ' AND ', $where ) . "
                GROUP BY standard, term, subject, difficulty
                ORDER BY subject, difficulty";

        $rows = empty( $values )
            ? $wpdb->get_results( $sql, ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

        Knowly_Debug::log( 'exam.catalogue', 'Catalogue fetched', [
            'filters' => $filters,
            'count'   => count( $rows ?: [] ),
        ], null, 'debug' );

        return $rows ?: [];
    }

    // ── Railway API ───────────────────────────────────────────────────────────

    /**
     * Fetch the next Trial package from Railway's sequential pool.
     * Railway handles all generation — this plugin never waits on Claude directly.
     *
     * Returns WP_Error on connection failure or pool_empty (503).
     * pool_empty means Railway has triggered background generation — client should retry shortly.
     */
    public static function fetch_from_railway(
        int    $child_id,
        string $level,
        string $period,
        string $subject,
        string $difficulty,
        string $trial_type = 'practice',
        string $topic = ''
    ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $api_key    = get_option( 'knowly_railway_api_key', '' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $railway_subject = self::normalise_subject( $subject );

        Knowly_Debug::log( 'exam.railway', 'Calling Railway generate-exam', [
            'child_id'   => $child_id,
            'level'      => $level,
            'period'     => $period,
            'subject'    => $railway_subject,
            'difficulty' => $difficulty,
            'trial_type' => $trial_type,
            'topic'      => $topic,
        ], null, 'info' );

        $headers = [
            'Authorization' => "Bearer {$api_key}",
            'Content-Type'  => 'application/json',
        ];

        if ( $server_key ) {
            $headers['X-AEP-Server-Key'] = $server_key;
        }

        $body_payload = [
            'user_id'    => (string) $child_id,
            'curriculum' => 'tt_primary',
            'level'      => $level,
            'period'     => $period ?: null,
            'subject'    => $railway_subject,
            'difficulty' => $difficulty,
            'trial_type' => $trial_type,
            'topic'      => $topic ?: null,
            'source'     => 'direct',
        ];

        $response = wp_remote_post( "{$endpoint}/api/v1/generate-exam", [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => wp_json_encode( $body_payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'exam.railway', 'Railway HTTP error', [
                'error' => $response->get_error_message(),
            ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', 'Failed to connect to Trial service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // 503 pool_empty — Railway has triggered background generation, client should retry
        if ( $code === 503 ) {
            Knowly_Debug::log( 'exam.railway', 'Railway pool empty — background generation triggered', [
                'level'   => $level,
                'period'  => $period,
                'subject' => $railway_subject,
            ], null, 'info' );
            return new WP_Error( 'knowly_pool_empty', 'No Trials available right now. Please try again in a moment.', [ 'status' => 503 ] );
        }

        if ( $code !== 200 || empty( $body ) ) {
            Knowly_Debug::log( 'exam.railway', 'Railway bad response', [
                'http_code' => $code,
                'body'      => $body,
            ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', 'Trial service returned an error.', [ 'status' => 503 ] );
        }

        Knowly_Debug::log( 'exam.railway', 'Railway package received', [
            'package_id'  => $body['package_id'] ?? 'unknown',
            'source'      => $body['source'] ?? 'unknown',
            'has_answers' => isset( $body['answer_sheet'] ),
        ], null, 'info' );

        return $body;
    }

    /**
     * Normalise a display subject name to Railway's lowercase slug.
     *
     * Railway expects: math | english | science | social_studies
     */
    public static function normalise_subject( string $subject ): string {
        return match ( strtolower( trim( $subject ) ) ) {
            'mathematics', 'math'                => 'math',
            'english', 'english language arts',
            'english language', 'ela',
            'language arts', 'language_arts'     => 'english',  // ← ADD THESE TWO
            'science'                            => 'science',
            'social studies', 'social_studies'   => 'social_studies',
            default => strtolower( str_replace( ' ', '_', $subject ) ),
        };
    }

    // ── Active Session ────────────────────────────────────────────────────────

    /**
     * Return the most recent active session for a child, with checkpoint attached.
     *
     * @param  int $child_id
     * @return array|null  Session row + checkpoint, or null if none active.
     */
    public static function get_active_session( int $child_id ): ?array {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT session_id, external_session_id, subject, level, period, difficulty, trial_type, started_at
                 FROM {$wpdb->prefix}knowly_exam_sessions
                 WHERE child_id = %d AND state = 'active'
                 ORDER BY started_at DESC
                 LIMIT 1",
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return null;
        }

        // Attach matching checkpoint, if any
        $raw        = get_user_meta( $child_id, 'knowly_checkpoint', true );
        $checkpoint = null;
        if ( $raw ) {
            $decoded = json_decode( $raw, true );
            if ( (int) ( $decoded['session_id'] ?? 0 ) === (int) $session['session_id'] ) {
                $checkpoint = $decoded;
            }
        }

        $session['checkpoint'] = $checkpoint;
        return $session;
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /**
     * Cancel an active exam session.
     *
     * Sets the session state to 'cancelled' and clears any saved checkpoint.
     * The token consumed on start is NOT refunded.
     *
     * @param  int $session_id
     * @param  int $child_id
     * @return array|WP_Error  { cancelled: true, session_id: int }
     */
    public static function cancel( int $session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT session_id FROM {$wpdb->prefix}knowly_exam_sessions
                 WHERE session_id = %d AND child_id = %d AND state = 'active'",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return new WP_Error(
                'knowly_session_not_found',
                'Active exam session not found.',
                [ 'status' => 404 ]
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'knowly_exam_sessions',
            [ 'state' => 'cancelled' ],
            [ 'session_id' => $session_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // Clear checkpoint so it doesn't linger
        delete_user_meta( $child_id, 'knowly_checkpoint' );

        Knowly_Debug::log( 'exam.cancel', 'Exam session cancelled', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
        ], $child_id, 'info' );

        return [ 'cancelled' => true, 'session_id' => $session_id ];
    }

    private static function generate_session_id(): string {
        return 'ses_' . strtolower( bin2hex( random_bytes( 12 ) ) );
    }
}
