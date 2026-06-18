<?php
/**
 * Knowly_Quest_Service — Quest delivery and Railway integration.
 *
 * Delivery flow (no Railway call):
 *  1. get_catalogue() — queries wp_knowly_quests WHERE variant=student AND status=approved
 *  2. get_quest()     — queries wp_knowly_quests by quest_id, variant=student, status=approved
 *
 * Session flow (proxies to Railway):
 *  3. start()            — checks attempt count → deducts gems → calls Railway start
 *  4. section_complete() — proxies to Railway POST /api/v1/quest/section-complete
 *  5. complete()         — proxies to Railway POST /api/v1/quest/complete
 *                          → if badge_awarded: true, calls Knowly_Badge_Service::award()
 *
 * Gem costs (read from WP options, never hardcoded):
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
        return 0; // Quests are one-time payment — retakes are always free
    }

    // ── Catalogue ─────────────────────────────────────────────────────────────

    /**
     * Fetch the approved student quest catalogue from the WP local store.
     *
     * Queries wp_knowly_quests WHERE variant='student' AND status='approved'.
     * No Railway call is made.
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
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_quests';

        $sql  = "SELECT quest_id, module_number, module_title, topic, subject, objectives, status
                 FROM {$table}
                 WHERE variant = 'student'
                   AND status  = 'approved'
                   AND curriculum = %s
                   AND level      = %s";
        $args = [ $curriculum, $level ];

        if ( $period !== '' ) {
            $sql  .= ' AND period = %s';
            $args[] = $period;
        }
        if ( $subject !== '' ) {
            $sql  .= ' AND subject = %s';
            $args[] = $subject;
        }

        $sql .= ' ORDER BY COALESCE(sort_order, 9999) ASC, topic ASC';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        if ( $rows === null ) {
            Knowly_Debug::log( 'quest.catalogue', 'DB error fetching catalogue', [ 'last_error' => $wpdb->last_error ], null, 'error' );
            return new WP_Error( 'knowly_db_error', 'Failed to query quest catalogue.', [ 'status' => 500 ] );
        }

        foreach ( $rows as &$row ) {
            if ( ! empty( $row['objectives'] ) ) {
                $row['objectives'] = json_decode( $row['objectives'], true ) ?? [];
            }
        }

        return $rows;
    }

    // ── Fetch Quest Content ───────────────────────────────────────────────────

    /**
     * Fetch full Quest content from the WP local store.
     *
     * Queries wp_knowly_quests by quest_id, variant='student', status='approved'.
     * No Railway call is made.
     *
     * @return array|WP_Error
     */
    public static function get_quest( string $quest_id, ?int $child_id = null ): array|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_quests';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE quest_id = %s
                   AND variant  = 'student'
                   AND status   = 'approved'",
                $quest_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Quest not found.', [ 'status' => 404 ] );
        }

        $row['content']    = ! empty( $row['content'] )    ? json_decode( $row['content'],    true ) : null;
        $row['objectives'] = ! empty( $row['objectives'] ) ? json_decode( $row['objectives'], true ) : [];

        // Normalise audio_url — null when column doesn't exist yet (pre-migration installs)
        $row['audio_url'] = $row['audio_url'] ?? null;

        $curriculum        = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $is_retake         = $child_id ? self::has_prior_completion( $child_id, $quest_id ) : false;
        $row['is_retake']  = $is_retake;
        $row['gem_cost']   = $is_retake
            ? self::get_retake_cost( $curriculum )
            : self::get_first_attempt_cost( $curriculum );

        return $row;
    }

    // ── Start ─────────────────────────────────────────────────────────────────

    /**
     * Start a Quest session — fully WP-local, no Railway dependency.
     *
     *  1. Check prior completion in wp_knowly_quest_sessions
     *  2. Determine gem cost (0 if assignment)
     *  3. Pre-check gem balance
     *  4. Insert local session row
     *  5. Deduct gems
     *
     * @param  int    $child_id
     * @param  string $quest_id
     * @param  string $source    'direct' | 'assignment'
     * @return array|WP_Error    { session_id, is_first_attempt, gem_cost, balance_after }
     */
    public static function start( int $child_id, string $quest_id, string $source = 'direct', ?int $task_id = null ): array|WP_Error {
        global $wpdb;
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );

        // ── Determine gem cost ────────────────────────────────────────────────
        if ( $source === 'assignment' ) {
            $gem_cost        = 0;
            $is_first_attempt = true;
        } else {
            $is_first_attempt = ! self::has_prior_completion( $child_id, $quest_id );
            $gem_cost         = $is_first_attempt
                ? self::get_first_attempt_cost( $curriculum )
                : self::get_retake_cost( $curriculum );
        }

        // ── Pre-check balance (skip if free) ──────────────────────────────────
        if ( $gem_cost > 0 && ! Knowly_Gem_Service::has_enough( $child_id, $gem_cost ) ) {
            return new WP_Error( 'knowly_insufficient_gems', 'Not enough Blue Gems. Ask your parent to allocate more gems.', [
                'status'   => 402,
                'balance'  => Knowly_Gem_Service::get_balance( $child_id ),
                'required' => $gem_cost,
            ] );
        }

        // ── Create local session row ──────────────────────────────────────────
        $quest_session_id = 'qs_' . strtolower( bin2hex( random_bytes( 12 ) ) );

        $insert_data = [
            'quest_session_id' => $quest_session_id,
            'child_id'         => $child_id,
            'quest_id'         => $quest_id,
            'source'           => in_array( $source, [ 'direct', 'assignment' ], true ) ? $source : 'direct',
            'state'            => 'active',
            'started_at'       => current_time( 'mysql', true ),
        ];
        $insert_fmts = [ '%s', '%d', '%s', '%s', '%s', '%s' ];

        // Only store task_id if the column exists (migration guard)
        $has_task_id_col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'task_id'",
            DB_NAME,
            $wpdb->prefix . 'knowly_quest_sessions'
        ) );
        if ( $has_task_id_col ) {
            $insert_data['task_id'] = $task_id;
            $insert_fmts[]          = '%d';
        }

        $wpdb->insert( $wpdb->prefix . 'knowly_quest_sessions', $insert_data, $insert_fmts );

        if ( ! $wpdb->insert_id ) {
            return new WP_Error( 'knowly_db_error', 'Failed to create quest session.', [ 'status' => 500 ] );
        }

        // ── Deduct gems ───────────────────────────────────────────────────────
        $balance_after = Knowly_Gem_Service::get_balance( $child_id );

        if ( $gem_cost > 0 ) {
            $deduction = Knowly_Gem_Service::deduct(
                $child_id,
                $gem_cost,
                'spent',
                $curriculum,
                $quest_session_id,
                'Quest started: ' . $quest_id
            );
            if ( is_wp_error( $deduction ) ) return $deduction;
            $balance_after = $deduction['balance_after'];
        }

        Knowly_Debug::log( 'quest.start', 'Quest session started (local)', [
            'child_id'         => $child_id,
            'quest_id'         => $quest_id,
            'session_id'       => $quest_session_id,
            'gem_cost'         => $gem_cost,
            'is_first_attempt' => $is_first_attempt,
            'source'           => $source,
        ], $child_id, 'info' );

        return [
            'session_id'       => $quest_session_id,
            'is_first_attempt' => $is_first_attempt,
            'gem_cost'         => $gem_cost,
            'balance_after'    => $balance_after,
        ];
    }

    // ── Section Complete ──────────────────────────────────────────────────────

    /**
     * Section completion is tracked client-side only.
     * This endpoint is a no-op kept for API compatibility.
     *
     * @return array|WP_Error
     */
    public static function section_complete( string $session_id, string $section_id, int $child_id ): array|WP_Error {
        return [ 'recorded' => true, 'session_id' => $session_id, 'section_id' => $section_id ];
    }

    // ── Complete ──────────────────────────────────────────────────────────────

    /**
     * Mark a Quest session complete.
     *
     * Marks the local WP session as completed, awards a badge on first
     * completion, and records a progression entry in Railway's exam_sessions
     * table so that GET /child/progression reflects the completed sub-topic.
     *
     * @param  string $session_id   The quest_session_id string (qs_…)
     * @param  int    $child_id     WP user ID of the child
     * @param  string $topic        Sub-topic name (must match curriculum_topics.topic in Railway)
     * @param  string $subject      Subject e.g. 'math' — derived from quest if empty
     * @param  int    $score        Percentage score 0-100 (default 100 = completed all reviews)
     * @return array|WP_Error  { completed, badge_earned, badge_name, is_first_completion, gems_awarded }
     */
    public static function complete( string $session_id, int $child_id, string $topic = '', string $subject = '', int $score = 100 ): array|WP_Error {
        global $wpdb;

        // Load and verify the session
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_quest_sessions
                 WHERE quest_session_id = %s AND child_id = %d",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Quest session not found.', [ 'status' => 404 ] );
        }

        $quest_id = $row['quest_id'];

        // Mark completed (idempotent)
        if ( $row['state'] !== 'completed' ) {
            $wpdb->update(
                $wpdb->prefix . 'knowly_quest_sessions',
                [
                    'state'        => 'completed',
                    'completed_at' => current_time( 'mysql', true ),
                ],
                [ 'quest_session_id' => $session_id ],
                [ '%s', '%s' ],
                [ '%s' ]
            );
        }

        // First completion = this was the only (or first) completed session for this quest
        $prior_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quest_sessions
             WHERE child_id = %d AND quest_id = %s AND state = 'completed' AND quest_session_id != %s",
            $child_id,
            $quest_id,
            $session_id
        ) );
        $is_first_completion = ( $prior_count === 0 );

        // Check quest module completion badge (fires when all sub-topics in module are done)
        $badge_earned = false;
        $badge_name   = null;
        $badge_result = null;

        $badge_result = Knowly_Badge_Service::check_quest_module_completion( $child_id, $quest_id );
        if ( $badge_result ) {
            $badge_earned = true;
            $badge_name   = $badge_result['name'] ?? null;
        }

        Knowly_Debug::log( 'quest.complete', 'Quest session completed (local)', [
            'child_id'           => $child_id,
            'session_id'         => $session_id,
            'quest_id'           => $quest_id,
            'is_first_completion' => $is_first_completion,
            'badge_earned'       => $badge_earned,
        ], $child_id, 'info' );

        // ── Record progression in Railway ─────────────────────────────────────
        // Writes to exam_sessions so GET /child/progression reflects the completed sub-topic.
        // This is non-fatal: if Railway is unavailable, the quest still completes locally.
        if ( $topic ) {
            $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );

            // Derive subject from the quest if caller didn't supply one.
            if ( ! $subject ) {
                $quest_row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT subject FROM {$wpdb->prefix}knowly_quests WHERE quest_id = %s LIMIT 1",
                        $quest_id
                    ),
                    ARRAY_A
                );
                $subject = $quest_row['subject'] ?? '';
            }

            // Get child's level and period.
            $child_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT level, period FROM {$wpdb->prefix}knowly_children WHERE child_id = %d",
                    $child_id
                ),
                ARRAY_A
            );
            $level  = $child_row['level']  ?? '';
            $period = $child_row['period'] ?? '';

            if ( $subject && $level ) {
                $railway_result = self::railway_post( '/api/v1/quest/complete-progression', [
                    'session_id' => $session_id,
                    'user_id'    => $child_id,
                    'quest_id'   => $quest_id,
                    'subject'    => $subject,
                    'topic'      => $topic,
                    'curriculum' => $curriculum,
                    'level'      => $level,
                    'period'     => $period ?: null,
                    'score'      => $score,
                ] );

                Knowly_Debug::log( 'quest.complete', 'Railway progression record', [
                    'topic'   => $topic,
                    'subject' => $subject,
                    'level'   => $level,
                    'result'  => is_wp_error( $railway_result ) ? $railway_result->get_error_message() : $railway_result,
                ], $child_id, is_wp_error( $railway_result ) ? 'error' : 'info' );
            }
        }

        return [
            'completed'           => true,
            'badge_earned'        => $badge_earned,
            'badge_name'          => $badge_name,
            'badge'               => $badge_result,
            'is_first_completion' => $is_first_completion,
            'gems_awarded'        => 0,
            'quest_id'            => $quest_id,
        ];
    }

    // ── Prior Completion Check ────────────────────────────────────────────────

    private static function has_prior_completion( int $child_id, string $quest_id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quest_sessions
             WHERE child_id = %d AND quest_id = %s AND state = 'completed'",
            $child_id,
            $quest_id
        ) );
        return $count > 0;
    }

    // ── Railway HTTP Helpers ──────────────────────────────────────────────────

    /**
     * Generate a short-lived JWT for server-to-server Railway calls.
     * Uses an admin WP user so Railway's authenticateToken middleware accepts it.
     */
    private static function get_railway_token(): string {
        $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        if ( ! empty( $admin_ids ) ) {
            return Knowly_JWT::encode( (int) $admin_ids[0] );
        }
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

    // ── Quest Questions ───────────────────────────────────────────────────────

    /**
     * Fetch active quest questions from Railway (without correct_answer).
     */
    public static function get_questions( string $quest_id ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_config', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $resp = wp_remote_get( $endpoint . '/api/v1/quest/' . rawurlencode( $quest_id ) . '/questions', [
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        $body = self::parse_response( $resp );
        if ( is_wp_error( $body ) ) return $body;

        return [
            'quest_id'  => $quest_id,
            'questions' => $body['questions'] ?? [],
            'count'     => $body['count']     ?? 0,
        ];
    }

    /**
     * Score quest question answers and write results to the local WP table.
     *
     * Fetches correct answers from Railway (server-key call), scores locally,
     * inserts rows into wp_knowly_quest_question_results.
     *
     * @param  string $session_id  Quest session ID
     * @param  string $quest_id
     * @param  int    $child_id
     * @param  array  $answers     { question_id => selected_answer }
     * @return array|WP_Error
     */
    public static function submit_questions(
        string $session_id,
        string $quest_id,
        int    $child_id,
        array  $answers
    ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_config', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        // Fetch all questions WITH correct_answer using server key
        $resp = wp_remote_get( $endpoint . '/api/v1/quest/' . rawurlencode( $quest_id ) . '/questions', [
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        $body = self::parse_response( $resp );
        if ( is_wp_error( $body ) ) return $body;

        $questions = $body['questions'] ?? [];
        if ( empty( $questions ) ) {
            return new WP_Error( 'knowly_not_found', 'No questions found for this quest.', [ 'status' => 404 ] );
        }

        // Build answer-sheet map: question_id → correct_answer
        // Note: Railway returns questions without correct_answer for child-facing calls,
        // but with server-key it returns them. Add correct_answer to the Railway GET if
        // not already present — for now we re-fetch via the admin endpoint.
        $answer_sheet = [];
        foreach ( $questions as $q ) {
            $qid = $q['id'] ?? '';
            if ( $qid ) $answer_sheet[ $qid ] = $q['correct_answer'] ?? null;
        }

        // Score and persist
        global $wpdb;
        $table   = $wpdb->prefix . 'knowly_quest_question_results';
        $now     = current_time( 'mysql' );
        $results = [];
        $correct = 0;

        foreach ( $answers as $question_id => $selected ) {
            $selected   = strtoupper( substr( trim( (string) $selected ), 0, 1 ) );
            $correct_ans = $answer_sheet[ $question_id ] ?? null;
            $is_correct = $correct_ans && $selected === $correct_ans;

            if ( $is_correct ) $correct++;

            $wpdb->insert( $table, [
                'session_id'      => $session_id,
                'quest_id'        => $quest_id,
                'child_id'        => $child_id,
                'question_id'     => $question_id,
                'selected_answer' => $selected ?: null,
                'is_correct'      => $is_correct ? 1 : 0,
                'answered_at'     => $now,
            ] );

            $results[] = [
                'question_id'     => $question_id,
                'selected_answer' => $selected,
                'correct_answer'  => $correct_ans,
                'is_correct'      => $is_correct,
            ];
        }

        return [
            'session_id' => $session_id,
            'quest_id'   => $quest_id,
            'score'      => $correct,
            'total'      => count( $answers ),
            'percentage' => count( $answers ) > 0 ? round( $correct / count( $answers ) * 100 ) : 0,
            'results'    => $results,
        ];
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
