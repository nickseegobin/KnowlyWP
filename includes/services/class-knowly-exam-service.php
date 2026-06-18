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
        string $topic = '',
        string $source = 'self',
        ?int   $task_id = null,
        string $scope = '',
        string $scope_ref = '',
        array  $module_numbers = []
    ): array|WP_Error {
        Knowly_Debug::log( 'exam.start', 'Trial start requested', [
            'parent_id'      => $parent_id,
            'child_id'       => $child_id,
            'level'          => $level,
            'period'         => $period,
            'subject'        => $subject,
            'difficulty'     => $difficulty,
            'trial_type'     => $trial_type,
            'topic'          => $topic,
            'source'         => $source,
            'task_id'        => $task_id,
            'scope'          => $scope,
            'scope_ref'      => $scope_ref,
            'module_numbers' => $module_numbers,
        ], $parent_id, 'info' );

        // ── 1. Pre-check gem balance ──────────────────────────────────────────
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $gem_cost   = Knowly_Gem_Service::get_exam_cost( $curriculum, $difficulty );

        if ( ! Knowly_Gem_Service::has_enough( $child_id, $gem_cost ) ) {
            Knowly_Debug::log( 'exam.start', 'Gem pre-check failed', [
                'child_id'  => $child_id,
                'required'  => $gem_cost,
                'balance'   => Knowly_Gem_Service::get_balance( $child_id ),
            ], $parent_id, 'warning' );
            return new WP_Error( 'knowly_insufficient_gems', 'Not enough Blue Gems. Ask your parent to allocate more gems.', [
                'status'   => 402,
                'balance'  => Knowly_Gem_Service::get_balance( $child_id ),
                'required' => $gem_cost,
            ] );
        }

        // ── 2. Fetch package ─────────────────────────────────────────────────
        // QB v2 is the default delivery path. When module_numbers are not
        // explicitly provided (React's normal flow), auto-resolve from the
        // question bank slot board. Fallback to WP pool if QB slot is unseeded.
        if ( empty( $module_numbers ) ) {
            $module_numbers = self::resolve_module_numbers( $level, $period, $subject, $topic );
        }

        if ( ! empty( $module_numbers ) ) {
            $package = self::fetch_from_question_bank_assemble(
                $level, $period ?: null, $subject, $module_numbers, $difficulty
            );

            if ( is_wp_error( $package ) && 'knowly_pool_empty' === $package->get_error_code() ) {
                Knowly_Debug::log( 'exam.start', 'QB v2 pool empty — falling back to WP pool', [
                    'level'          => $level,
                    'subject'        => $subject,
                    'module_numbers' => $module_numbers,
                ], $parent_id, 'warning' );
                $package = self::fetch_from_wp_pool( $child_id, $level, $period, $subject, $difficulty, $trial_type, $topic );
            }
        } else {
            $package = self::fetch_from_wp_pool( $child_id, $level, $period, $subject, $difficulty, $trial_type, $topic );
        }

        if ( is_wp_error( $package ) ) {
            return $package;
        }

        // ── 3. Generate session ID ────────────────────────────────────────────
        $external_session_id = self::generate_session_id();

        // ── 4. Deduct gem from child wallet ───────────────────────────────────
        $deduction = Knowly_Gem_Service::deduct(
            $child_id,
            $gem_cost,
            'spent',
            $curriculum,
            $external_session_id,
            "Trial started: {$subject} ({$difficulty})"
        );
        if ( is_wp_error( $deduction ) ) {
            return $deduction;
        }

        // ── 5. Create active session record ───────────────────────────────────
        global $wpdb;

        // ── 5a. Core INSERT — only original columns (always safe) ────────────────
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

        // ── 5b. UPDATE new columns if they exist (added in v1.9.4 migration) ──
        // Separated from the INSERT so older DB schemas don't break.
        if ( $session_id ) {
            // QB path: answer_sheet is already in the package; legacy path: read from WP pool table.
            if ( ! empty( $package['answer_sheet'] ) ) {
                $raw_answer_sheet = wp_json_encode( $package['answer_sheet'] );
            } else {
                $raw_answer_sheet = $wpdb->get_var( $wpdb->prepare(
                    "SELECT answer_sheet FROM {$wpdb->prefix}knowly_trial_packages WHERE package_id = %s",
                    $package['package_id']
                ) );
            }

            $new_cols = $wpdb->get_col( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME IN ('source','task_id','answer_sheet')",
                DB_NAME,
                $wpdb->prefix . 'knowly_exam_sessions'
            ) );

            $update = [];
            $fmts   = [];
            if ( in_array( 'source', $new_cols, true ) ) {
                $update['source'] = in_array( $source, [ 'self', 'teacher_assigned' ], true ) ? $source : 'self';
                $fmts[]           = '%s';
            }
            if ( in_array( 'task_id', $new_cols, true ) ) {
                $update['task_id'] = $task_id;
                $fmts[]            = '%d';
            }
            if ( in_array( 'answer_sheet', $new_cols, true ) ) {
                $update['answer_sheet'] = $raw_answer_sheet ?: null;
                $fmts[]                 = '%s';
            }
            if ( ! empty( $update ) ) {
                $wpdb->update(
                    $wpdb->prefix . 'knowly_exam_sessions',
                    $update,
                    [ 'session_id' => $session_id ],
                    $fmts,
                    [ '%d' ]
                );
            }
        }

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

    // ── WP Pool Delivery ──────────────────────────────────────────────────────

    /**
     * Fetch the next Trial package from the WP local pool (wp_knowly_trial_packages).
     *
     * Delivery priority:
     *  1. Approved package for this slot that this child has never taken.
     *  2. If all packages exhausted, pick the one served least recently (wrap-around).
     *
     * No Railway call is made. Railway is only called during admin Sync.
     *
     * @return array|WP_Error  Full package row with questions; answer_sheet stripped before returning.
     */
    public static function fetch_from_wp_pool(
        int    $child_id,
        string $level,
        string $period,
        string $subject,
        string $difficulty,
        string $trial_type = 'practice',
        string $topic = ''
    ): array|WP_Error {
        global $wpdb;
        $table    = $wpdb->prefix . 'knowly_trial_packages';
        $sessions = $wpdb->prefix . 'knowly_exam_sessions';
        $subject  = self::normalise_subject( $subject );

        // Build slot conditions
        $where_args = [ $subject, $level, $trial_type ];
        $where_sql  = "subject = %s AND level = %s AND trial_type = %s AND status = 'approved'";

        if ( $period !== '' ) {
            $where_sql   .= ' AND period = %s';
            $where_args[] = $period;
        }
        if ( $difficulty !== '' ) {
            $where_sql   .= ' AND difficulty = %s';
            $where_args[] = $difficulty;
        }
        if ( $topic !== '' ) {
            $where_sql   .= ' AND topic = %s';
            $where_args[] = $topic;
        }

        // 1. Prefer a package this child has never taken
        $fresh_args   = array_merge( $where_args, [ $child_id ] );
        $fresh_sql    = "SELECT * FROM {$table}
                         WHERE {$where_sql}
                           AND package_id NOT IN (
                               SELECT package_id FROM {$sessions} WHERE child_id = %d
                           )
                         ORDER BY RAND() LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( $fresh_sql, ...$fresh_args ), ARRAY_A );

        // 2. Wrap-around — serve the oldest-served package for this child
        if ( ! $row ) {
            // ISNULL() returns 1 for NULL → ORDER BY ASC puts non-NULL (already-served) first,
            // then NULL (never served by anyone) last. Effectively serves least-recently-used.
            $wrap_sql  = "SELECT p.* FROM {$table} p
                          LEFT JOIN {$sessions} s
                              ON s.package_id = p.package_id AND s.child_id = %d
                          WHERE p.subject = %s AND p.level = %s AND p.trial_type = %s
                            AND p.status = 'approved'";
            $wrap_args = [ $child_id, $subject, $level, $trial_type ];

            if ( $period !== '' ) {
                $wrap_sql   .= ' AND p.period = %s';
                $wrap_args[] = $period;
            }
            if ( $difficulty !== '' ) {
                $wrap_sql   .= ' AND p.difficulty = %s';
                $wrap_args[] = $difficulty;
            }
            if ( $topic !== '' ) {
                $wrap_sql   .= ' AND p.topic = %s';
                $wrap_args[] = $topic;
            }

            $wrap_sql .= ' ORDER BY ISNULL(s.started_at) DESC, s.started_at ASC LIMIT 1';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row( $wpdb->prepare( $wrap_sql, ...$wrap_args ), ARRAY_A );
        }

        if ( ! $row ) {
            Knowly_Debug::log( 'exam.pool', 'WP pool empty for slot', [
                'child_id'   => $child_id,
                'level'      => $level,
                'period'     => $period,
                'subject'    => $subject,
                'difficulty' => $difficulty,
                'trial_type' => $trial_type,
            ], null, 'warning' );
            return new WP_Error(
                'knowly_pool_empty',
                'No Trials are available for this subject yet. Please check back soon.',
                [ 'status' => 503 ]
            );
        }

        // Decode JSON columns
        $package               = json_decode( $row['meta'],   true ) ?? [];
        $package['package_id'] = $row['package_id'];
        $package['questions']  = json_decode( $row['questions'], true ) ?? [];
        // answer_sheet stored separately — do NOT include in the response (security)

        Knowly_Debug::log( 'exam.pool', 'WP pool package served', [
            'child_id'   => $child_id,
            'package_id' => $row['package_id'],
        ], null, 'info' );

        return $package;
    }

    /**
     * Assemble a trial from the question_bank via POST /api/v1/trial/assemble.
     *
     * Used when module_numbers are provided. Railway selects least-served questions
     * from the module_number-keyed bank and returns { meta, questions, answer_sheet }.
     *
     * @param  string   $level
     * @param  string|null $period
     * @param  string   $subject
     * @param  int[]    $module_numbers   One or more module_numbers to draw from.
     * @param  string   $difficulty
     * @param  int      $question_count
     * @param  string[] $exclude_question_ids  UUIDs of questions to skip (cross-session dedup).
     * @return array|WP_Error  { package_id, questions, answer_sheet, meta } or WP_Error
     */
    public static function fetch_from_question_bank_assemble(
        string $level,
        ?string $period,
        string $subject,
        array  $module_numbers,
        string $difficulty,
        int    $question_count = 10,
        array  $exclude_question_ids = []
    ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $body = [
            'curriculum'           => get_option( 'knowly_default_curriculum', 'tt_primary' ),
            'level'                => $level,
            'period'               => $period ?: null,
            'subject'              => self::normalise_subject( $subject ),
            'difficulty'           => $difficulty,
            'module_numbers'       => $module_numbers,
            'question_count'       => $question_count,
            'exclude_question_ids' => $exclude_question_ids,
        ];

        $response = wp_remote_post( $endpoint . '/api/v1/trial/assemble', [
            'timeout' => 120,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-AEP-Server-Key' => $server_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'exam.qb', 'Railway /trial/assemble HTTP error', [ 'error' => $response->get_error_message() ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', 'Failed to connect to content service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 503 ) {
            return new WP_Error( 'knowly_pool_empty', $data['error'] ?? 'Question bank is being populated. Please try again shortly.', [ 'status' => 503 ] );
        }

        if ( $code < 200 || $code >= 300 ) {
            Knowly_Debug::log( 'exam.qb', 'Railway /trial/assemble error', [ 'http_code' => $code, 'body' => $data ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', $data['error'] ?? 'Content service returned an error.', [ 'status' => 502 ] );
        }

        // Lowercase option keys and correct_answer — Railway returns A/B/C/D, React expects a/b/c/d
        $questions    = $data['questions']    ?? [];
        $answer_sheet = $data['answer_sheet'] ?? [];

        foreach ( $questions as &$q ) {
            if ( ! empty( $q['options'] ) && is_array( $q['options'] ) ) {
                $lc = [];
                foreach ( $q['options'] as $k => $v ) {
                    $lc[ strtolower( $k ) ] = $v;
                }
                $q['options'] = $lc;
            }
            if ( isset( $q['correct_answer'] ) ) {
                $q['correct_answer'] = strtolower( $q['correct_answer'] );
            }
        }
        unset( $q );

        foreach ( $answer_sheet as &$a ) {
            if ( isset( $a['correct_answer'] ) ) {
                $a['correct_answer'] = strtolower( $a['correct_answer'] );
            }
        }
        unset( $a );

        $package = [
            'package_id'   => 'qb-' . uniqid(),
            'questions'    => $questions,
            'answer_sheet' => $answer_sheet,
            'meta'         => $data['meta'] ?? [],
            'source'       => 'question_bank',
        ];

        Knowly_Debug::log( 'exam.qb', 'QB assemble package ready', [
            'module_numbers' => $module_numbers,
            'question_count' => count( $package['questions'] ),
        ], null, 'info' );

        return $package;
    }

    /**
     * Fetch a trial from the Railway question_bank via POST /api/v1/trial/start.
     *
     * Used when scope + scope_ref params are present in the start request.
     * Railway assembles individual questions by scope, returns { meta, questions, answer_sheet }.
     *
     * @param  string $level
     * @param  string|null $period
     * @param  string $subject
     * @param  string $scope       'subtopic' | 'general_topic' | 'period'
     * @param  string $scope_ref   slugified topic/module/period key
     * @param  string $difficulty
     * @param  int    $question_count
     * @return array|WP_Error  { package_id, questions, answer_sheet, meta } or WP_Error
     */
    public static function fetch_from_question_bank(
        string $level,
        ?string $period,
        string $subject,
        string $scope,
        string $scope_ref,
        string $difficulty,
        int    $question_count = 10
    ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $body = [
            'curriculum'     => get_option( 'knowly_default_curriculum', 'tt_primary' ),
            'level'          => $level,
            'period'         => $period ?: null,
            'subject'        => self::normalise_subject( $subject ),
            'scope'          => $scope,
            'scope_ref'      => $scope_ref,
            'difficulty'     => $difficulty,
            'question_count' => $question_count,
        ];

        $response = wp_remote_post( $endpoint . '/api/v1/trial/start', [
            'timeout' => 120,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-AEP-Server-Key' => $server_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'exam.qb', 'Railway /trial/start HTTP error', [ 'error' => $response->get_error_message() ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', 'Failed to connect to content service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 503 ) {
            return new WP_Error( 'knowly_pool_empty', $data['error'] ?? 'Question bank is being populated. Please try again shortly.', [ 'status' => 503 ] );
        }

        if ( $code < 200 || $code >= 300 ) {
            Knowly_Debug::log( 'exam.qb', 'Railway /trial/start error', [ 'http_code' => $code, 'body' => $data ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', $data['error'] ?? 'Content service returned an error.', [ 'status' => 502 ] );
        }

        // Normalise into a package-like shape the rest of start() can consume
        $package = [
            'package_id'   => 'qb-' . uniqid(),
            'questions'    => $data['questions']    ?? [],
            'answer_sheet' => $data['answer_sheet'] ?? [],
            'meta'         => $data['meta']         ?? [],
            'source'       => 'question_bank',
        ];

        Knowly_Debug::log( 'exam.qb', 'QB package assembled', [
            'scope'          => $scope,
            'scope_ref'      => $scope_ref,
            'question_count' => count( $package['questions'] ),
        ], null, 'info' );

        return $package;
    }

    /**
     * Keep fetch_from_railway as a legacy alias pointing at fetch_from_wp_pool.
     * Called by start() — renamed inline below too, but this keeps any external callers safe.
     *
     * @deprecated Use fetch_from_wp_pool() directly.
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
        return self::fetch_from_wp_pool( $child_id, $level, $period, $subject, $difficulty, $trial_type, $topic );
    }

    /**
     * Resolve module_numbers for a slot by querying the Railway question bank list.
     *
     * Returns module_numbers that have active questions. Falls back to all modules
     * for the slot if none are seeded yet (so fetch_from_question_bank_assemble can
     * return pool_empty cleanly and trigger the WP pool fallback).
     *
     * For std_5 with a topic slug, narrows candidates to modules whose title
     * contains the topic keyword (best-effort — falls back to all if no match).
     */
    private static function resolve_module_numbers(
        string $level,
        string $period,
        string $subject,
        string $topic = ''
    ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );

        if ( ! $endpoint ) return [];

        $params = [
            'curriculum' => $curriculum,
            'level'      => $level,
            'subject'    => self::normalise_subject( $subject ),
        ];
        if ( $period ) {
            $params['period'] = $period;
        }

        $url      = $endpoint . '/api/v1/question-bank/list?' . http_build_query( $params );
        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'exam.resolve', 'Module list fetch failed', [ 'error' => $response->get_error_message() ], null, 'warning' );
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $data['slots'] ) ) {
            return [];
        }

        // Gather unique module_numbers; prefer those with active questions
        $seeded  = [];
        $all     = [];
        foreach ( $data['slots'] as $slot ) {
            $mod        = (int) $slot['module_number'];
            $all[ $mod ] = $slot;
            if ( (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                $seeded[ $mod ] = $slot;
            }
        }

        $candidates = ! empty( $seeded ) ? $seeded : $all;

        // std_5 topic filtering — narrow to module whose title matches topic keyword
        if ( $topic && $level === 'std_5' && count( $candidates ) > 1 ) {
            $keyword = strtolower( str_replace( [ '_', '-' ], ' ', $topic ) );
            $matched = [];
            foreach ( $candidates as $mod => $slot ) {
                if ( false !== strpos( strtolower( $slot['module_title'] ?? '' ), $keyword ) ) {
                    $matched[ $mod ] = $slot;
                }
            }
            if ( ! empty( $matched ) ) {
                $candidates = $matched;
            }
        }

        return array_values( array_unique( array_keys( $candidates ) ) );
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

    // ── Resume ────────────────────────────────────────────────────────────────

    /**
     * Resume an active exam session — re-hydrates the package without re-deducting gems.
     *
     * Returns the same shape as start(): { session_id, external_session_id, package, checkpoint, balance_after }
     *
     * @param  int $session_id
     * @param  int $child_id
     * @return array|WP_Error
     */
    public static function resume( int $session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_exam_sessions
                 WHERE session_id = %d AND child_id = %d AND state = 'active'",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return new WP_Error( 'knowly_session_not_found', 'Active exam session not found.', [ 'status' => 404 ] );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_trial_packages WHERE package_id = %s",
                $session['package_id']
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'knowly_package_not_found', 'Trial package no longer available.', [ 'status' => 404 ] );
        }

        $package               = json_decode( $row['meta'], true ) ?? [];
        $package['package_id'] = $row['package_id'];
        $package['questions']  = json_decode( $row['questions'], true ) ?? [];
        // answer_sheet intentionally excluded

        $raw        = get_user_meta( $child_id, 'knowly_checkpoint', true );
        $checkpoint = null;
        if ( $raw ) {
            $decoded = json_decode( $raw, true );
            if ( (int) ( $decoded['session_id'] ?? 0 ) === $session_id ) {
                $checkpoint = $decoded;
            }
        }

        Knowly_Debug::log( 'exam.resume', 'Session resumed', [
            'session_id'  => $session_id,
            'child_id'    => $child_id,
            'has_checkpoint' => (bool) $checkpoint,
        ], $child_id, 'info' );

        return [
            'session_id'          => (int) $session['session_id'],
            'external_session_id' => $session['external_session_id'],
            'package'             => $package,
            'checkpoint'          => $checkpoint,
            'balance_after'       => Knowly_Gem_Service::get_balance( $child_id ),
        ];
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
