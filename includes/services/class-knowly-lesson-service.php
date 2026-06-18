<?php
/**
 * Knowly_Lesson_Service — Lesson delivery and session management.
 *
 * Lessons reuse Quest training content (wp_knowly_quests) but have their own:
 *   - Testing questions: lesson_questions (Supabase via Railway)
 *   - Sessions:         wp_knowly_lesson_sessions (WP-local)
 *   - Answer results:   wp_knowly_lesson_question_results (WP-local)
 *
 * Key differences from quests:
 *   - Single gem cost (no first/retake distinction)
 *   - No badge award
 *   - No sequential lock (any approved quest accessible)
 *   - submit_questions() returns { recorded: true } — score not shown to student
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Lesson_Service {

    // ── Catalogue ─────────────────────────────────────────────────────────────

    /**
     * Fetch approved student quests from WP local store for use as lessons.
     * No sequential lock — all matching approved quests are returned.
     *
     * @param  string $level
     * @param  string $period      Empty string for capstone
     * @param  string $curriculum
     * @param  string $subject     Optional filter
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

        $sql  = "SELECT quest_id, module_number, module_title, topic, subject, period, objectives, status
                 FROM {$table}
                 WHERE variant    = 'student'
                   AND status     = 'approved'
                   AND curriculum = %s
                   AND level      = %s";
        $args = [ $curriculum, $level ];

        if ( $period !== '' ) {
            $sql   .= ' AND period = %s';
            $args[] = $period;
        }
        if ( $subject !== '' ) {
            $sql   .= ' AND subject = %s';
            $args[] = $subject;
        }

        $sql .= ' ORDER BY COALESCE(sort_order, 9999) ASC, topic ASC';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        if ( $rows === null ) {
            return new WP_Error( 'knowly_db_error', 'Failed to query lesson catalogue.', [ 'status' => 500 ] );
        }

        foreach ( $rows as &$row ) {
            if ( ! empty( $row['objectives'] ) ) {
                $row['objectives'] = json_decode( $row['objectives'], true ) ?? [];
            }
        }

        return $rows;
    }

    // ── Fetch Lesson Content ──────────────────────────────────────────────────

    /**
     * Fetch full lesson content from WP local store (same as quest content).
     *
     * @param  string   $quest_id
     * @param  int|null $child_id  Unused — kept for API symmetry with get_quest()
     * @return array|WP_Error
     */
    public static function get_lesson( string $quest_id, ?int $child_id = null ): array|WP_Error {
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
            return new WP_Error( 'knowly_not_found', 'Lesson not found.', [ 'status' => 404 ] );
        }

        $row['content']    = ! empty( $row['content'] )    ? json_decode( $row['content'],    true ) : null;
        $row['objectives'] = ! empty( $row['objectives'] ) ? json_decode( $row['objectives'], true ) : [];

        $curriculum      = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $is_retake       = $child_id ? self::has_prior_completion( $child_id, $row['quest_id'] ) : false;
        $row['gem_cost'] = $is_retake ? 0 : Knowly_Gem_Service::get_lesson_cost( $curriculum );

        return $row;
    }

    // ── Start ─────────────────────────────────────────────────────────────────

    /**
     * Start a Lesson session — deducts gems, creates WP-local session record.
     *
     * @param  int    $child_id
     * @param  string $quest_id
     * @param  string $source    'direct' | 'assignment'
     * @return array|WP_Error    { session_id, is_first_attempt, gem_cost, balance_after }
     */
    public static function start( int $child_id, string $quest_id, string $source = 'direct', ?int $task_id = null ): array|WP_Error {
        global $wpdb;

        $curriculum       = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $is_first_attempt = ! self::has_prior_completion( $child_id, $quest_id );
        $gem_cost         = ( $source === 'assignment' || ! $is_first_attempt )
            ? 0
            : Knowly_Gem_Service::get_lesson_cost( $curriculum );

        if ( $gem_cost > 0 && ! Knowly_Gem_Service::has_enough( $child_id, $gem_cost ) ) {
            return new WP_Error( 'knowly_insufficient_gems', 'Not enough Blue Gems. Ask your parent to allocate more gems.', [
                'status'   => 402,
                'balance'  => Knowly_Gem_Service::get_balance( $child_id ),
                'required' => $gem_cost,
            ] );
        }

        $lesson_session_id = 'ls_' . strtolower( bin2hex( random_bytes( 12 ) ) );

        $wpdb->insert(
            $wpdb->prefix . 'knowly_lesson_sessions',
            [
                'lesson_session_id' => $lesson_session_id,
                'child_id'          => $child_id,
                'quest_id'          => $quest_id,
                'source'            => in_array( $source, [ 'direct', 'assignment' ], true ) ? $source : 'direct',
                'state'             => 'active',
                'started_at'        => current_time( 'mysql', true ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        // Store task_id if the column exists (added v2.1.0 migration)
        if ( $wpdb->insert_id && $task_id ) {
            $col_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'task_id'",
                DB_NAME,
                $wpdb->prefix . 'knowly_lesson_sessions'
            ) );
            if ( $col_exists ) {
                $wpdb->update(
                    $wpdb->prefix . 'knowly_lesson_sessions',
                    [ 'task_id' => $task_id ],
                    [ 'lesson_session_id' => $lesson_session_id ],
                    [ '%d' ],
                    [ '%s' ]
                );
            }
        }

        if ( ! $wpdb->insert_id ) {
            return new WP_Error( 'knowly_db_error', 'Failed to create lesson session.', [ 'status' => 500 ] );
        }

        $balance_after = Knowly_Gem_Service::get_balance( $child_id );

        if ( $gem_cost > 0 ) {
            $deduction = Knowly_Gem_Service::deduct(
                $child_id,
                $gem_cost,
                'spent',
                $curriculum,
                $lesson_session_id,
                'Lesson: ' . $quest_id
            );
            if ( is_wp_error( $deduction ) ) return $deduction;
            $balance_after = $deduction['balance_after'];
        }

        return [
            'session_id'       => $lesson_session_id,
            'is_first_attempt' => $is_first_attempt,
            'gem_cost'         => $gem_cost,
            'balance_after'    => $balance_after,
        ];
    }

    // ── Complete ──────────────────────────────────────────────────────────────

    /**
     * Mark a Lesson session complete — WP-local, no badge, no gems.
     *
     * @param  string $session_id  The lesson_session_id string (ls_…)
     * @param  int    $child_id
     * @return array|WP_Error  { completed, quest_id }
     */
    public static function complete( string $session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_lesson_sessions
                 WHERE lesson_session_id = %s AND child_id = %d",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Lesson session not found.', [ 'status' => 404 ] );
        }

        if ( $row['state'] !== 'completed' ) {
            $wpdb->update(
                $wpdb->prefix . 'knowly_lesson_sessions',
                [
                    'state'        => 'completed',
                    'completed_at' => current_time( 'mysql', true ),
                ],
                [ 'lesson_session_id' => $session_id ],
                [ '%s', '%s' ],
                [ '%s' ]
            );
        }

        // Check lesson milestone badges
        $quest_meta = $wpdb->get_row( $wpdb->prepare(
            "SELECT subject, level, curriculum FROM {$wpdb->prefix}knowly_quests
             WHERE quest_id = %s LIMIT 1",
            $row['quest_id']
        ), ARRAY_A );

        $badges_earned = [];
        if ( $quest_meta && ! empty( $quest_meta['subject'] ) && ! empty( $quest_meta['level'] ) ) {
            $badges_earned = Knowly_Badge_Service::check_lesson_milestones(
                $child_id,
                $quest_meta['subject'],
                $quest_meta['level'],
                $quest_meta['curriculum'] ?: 'tt_primary'
            );
        }

        return [
            'completed'     => true,
            'quest_id'      => $row['quest_id'],
            'badges_earned' => $badges_earned,
        ];
    }

    // ── Questions ─────────────────────────────────────────────────────────────

    /**
     * Fetch active lesson questions from Railway (without correct_answer).
     *
     * Uses a server-to-server JWT so Railway's auth middleware accepts the call.
     *
     * @param  string $quest_id
     * @return array|WP_Error  { quest_id, questions[], count }
     */
    public static function get_questions( string $quest_id ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_config', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $resp = wp_remote_get( $endpoint . '/api/v1/lesson/' . rawurlencode( $quest_id ) . '/questions', [
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

    // ── Submit Questions ──────────────────────────────────────────────────────

    /**
     * Score lesson question answers and record silently.
     *
     * Fetches correct answers from Railway with server key, scores locally,
     * inserts rows into wp_knowly_lesson_question_results.
     * Returns { recorded: true } — score is NOT returned to the student.
     *
     * @param  string $session_id
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

        // Fetch questions WITH correct_answer using server key
        $resp = wp_remote_get( $endpoint . '/api/v1/lesson/' . rawurlencode( $quest_id ) . '/questions', [
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        $body = self::parse_response( $resp );
        if ( is_wp_error( $body ) ) return $body;

        $questions = $body['questions'] ?? [];
        if ( empty( $questions ) ) {
            return new WP_Error( 'knowly_not_found', 'No lesson questions found for this module.', [ 'status' => 404 ] );
        }

        $answer_sheet = [];
        foreach ( $questions as $q ) {
            $qid = $q['id'] ?? '';
            if ( $qid ) $answer_sheet[ $qid ] = $q['correct_answer'] ?? null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'knowly_lesson_question_results';
        $now   = current_time( 'mysql' );

        foreach ( $answers as $question_id => $selected ) {
            $selected    = strtoupper( substr( trim( (string) $selected ), 0, 1 ) );
            $correct_ans = $answer_sheet[ $question_id ] ?? null;
            $is_correct  = $correct_ans && $selected === $correct_ans;

            $wpdb->insert( $table, [
                'session_id'      => $session_id,
                'quest_id'        => $quest_id,
                'child_id'        => $child_id,
                'question_id'     => $question_id,
                'selected_answer' => $selected ?: null,
                'is_correct'      => $is_correct ? 1 : 0,
                'answered_at'     => $now,
            ] );
        }

        return [ 'recorded' => true ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function has_prior_completion( int $child_id, string $quest_id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_lesson_sessions
             WHERE child_id = %d AND quest_id = %s AND state = 'completed'",
            $child_id,
            $quest_id
        ) );
        return $count > 0;
    }

    private static function parse_response( $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'knowly_railway_error', $response->get_error_message(), [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = $body['error'] ?? "Railway returned HTTP {$code}";
            return new WP_Error( 'knowly_railway_error', $msg, [ 'status' => $code >= 400 ? $code : 502 ] );
        }

        return $body ?: [];
    }
}
