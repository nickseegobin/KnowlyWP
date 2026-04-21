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
        ?int   $task_id = null
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
            'source'     => $source,
            'task_id'    => $task_id,
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

        // ── 2. Fetch from Railway sequential pool ─────────────────────────────
        $package = self::fetch_from_railway( $child_id, $level, $period, $subject, $difficulty, $trial_type, $topic );

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
            $raw_answer_sheet = $wpdb->get_var( $wpdb->prepare(
                "SELECT answer_sheet FROM {$wpdb->prefix}knowly_trial_packages WHERE package_id = %s",
                $package['package_id']
            ) );

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
