<?php
/**
 * Knowly_History_Service — Unified activity history across Trials, Quests and Lessons.
 *
 * All three content types are merged into a single chronological feed.
 * Trials carry a full score; Quests and Lessons derive their score from
 * their respective question-results tables (if questions were answered).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_History_Service {

    // ── Unified history ───────────────────────────────────────────────────────

    /**
     * Return a paginated list of completed sessions across all content types.
     *
     * @param int    $child_id
     * @param string $type      all | trials | quests | lessons
     * @param int    $page
     * @param int    $per_page
     * @return array
     */
    public static function get_history( int $child_id, string $type = 'all', int $page = 1, int $per_page = 20, int $month = 0, int $year = 0 ): array {
        $items = [];

        if ( $type === 'all' || $type === 'trials' ) {
            $items = array_merge( $items, self::trial_rows( $child_id, $month, $year ) );
        }
        if ( $type === 'all' || $type === 'quests' ) {
            $items = array_merge( $items, self::quest_rows( $child_id, $month, $year ) );
        }
        if ( $type === 'all' || $type === 'lessons' ) {
            $items = array_merge( $items, self::lesson_rows( $child_id, $month, $year ) );
        }

        // Sort unified feed newest-first
        usort( $items, fn( $a, $b ) => strcmp( $b['completed_at'], $a['completed_at'] ) );

        $total  = count( $items );
        $offset = ( $page - 1 ) * $per_page;
        $page_items = array_slice( $items, $offset, $per_page );

        return [
            'items'       => $page_items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
            'type'        => $type,
        ];
    }

    // ── Quest detail ──────────────────────────────────────────────────────────

    /**
     * Full quest session detail: metadata + per-question results.
     *
     * @param  string $quest_session_id  UUID stored in quest_sessions.quest_session_id
     * @param  int    $child_id          Enforced ownership check
     * @return array|WP_Error
     */
    public static function get_quest_detail( string $quest_session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_id, quest_session_id, child_id, quest_id, source, task_id,
                    state, started_at, completed_at
             FROM {$wpdb->prefix}knowly_quest_sessions
             WHERE quest_session_id = %s AND child_id = %d AND state = 'completed'",
            $quest_session_id,
            $child_id
        ), ARRAY_A );

        if ( ! $session ) {
            return new WP_Error( 'knowly_not_found', 'Quest session not found.', [ 'status' => 404 ] );
        }

        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT question_id, selected_answer, is_correct, answered_at
             FROM {$wpdb->prefix}knowly_quest_question_results
             WHERE session_id = %s AND child_id = %d
             ORDER BY answered_at ASC",
            $quest_session_id,
            $child_id
        ), ARRAY_A ) ?: [];

        $correct = array_sum( array_column( $questions, 'is_correct' ) );
        $total   = count( $questions );

        return [
            'session' => [
                'session_id'      => $session['quest_session_id'],
                'quest_id'        => $session['quest_id'],
                'source'          => $session['source'],
                'task_id'         => $session['task_id'] ? (int) $session['task_id'] : null,
                'state'           => $session['state'],
                'started_at'      => $session['started_at'],
                'completed_at'    => $session['completed_at'],
            ],
            'summary' => [
                'correct'    => $correct,
                'total'      => $total,
                'percentage' => $total > 0 ? round( ( $correct / $total ) * 100, 1 ) : null,
            ],
            'questions' => array_map( fn( $q ) => [
                'question_id'     => $q['question_id'],
                'selected_answer' => $q['selected_answer'],
                'is_correct'      => (bool) $q['is_correct'],
                'answered_at'     => $q['answered_at'],
            ], $questions ),
        ];
    }

    // ── Lesson detail ─────────────────────────────────────────────────────────

    /**
     * Full lesson session detail: metadata + per-question results.
     *
     * @param  string $lesson_session_id  UUID stored in lesson_sessions.lesson_session_id
     * @param  int    $child_id
     * @return array|WP_Error
     */
    public static function get_lesson_detail( string $lesson_session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, lesson_session_id, child_id, quest_id, source,
                    state, started_at, completed_at
             FROM {$wpdb->prefix}knowly_lesson_sessions
             WHERE lesson_session_id = %s AND child_id = %d AND state = 'completed'",
            $lesson_session_id,
            $child_id
        ), ARRAY_A );

        if ( ! $session ) {
            return new WP_Error( 'knowly_not_found', 'Lesson session not found.', [ 'status' => 404 ] );
        }

        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT question_id, selected_answer, is_correct, answered_at
             FROM {$wpdb->prefix}knowly_lesson_question_results
             WHERE session_id = %s AND child_id = %d
             ORDER BY answered_at ASC",
            $lesson_session_id,
            $child_id
        ), ARRAY_A ) ?: [];

        $correct = array_sum( array_column( $questions, 'is_correct' ) );
        $total   = count( $questions );

        return [
            'session' => [
                'session_id'   => $session['lesson_session_id'],
                'quest_id'     => $session['quest_id'],
                'source'       => $session['source'],
                'state'        => $session['state'],
                'started_at'   => $session['started_at'],
                'completed_at' => $session['completed_at'],
            ],
            'summary' => [
                'correct'    => $correct,
                'total'      => $total,
                'percentage' => $total > 0 ? round( ( $correct / $total ) * 100, 1 ) : null,
            ],
            'questions' => array_map( fn( $q ) => [
                'question_id'     => $q['question_id'],
                'selected_answer' => $q['selected_answer'],
                'is_correct'      => (bool) $q['is_correct'],
                'answered_at'     => $q['answered_at'],
            ], $questions ),
        ];
    }

    // ── Private row builders ──────────────────────────────────────────────────

    private static function date_clause( int $month, int $year, string $col = 'completed_at' ): string {
        if ( $month > 0 && $year > 0 ) {
            return $GLOBALS['wpdb']->prepare( " AND YEAR({$col}) = %d AND MONTH({$col}) = %d", $year, $month );
        }
        return '';
    }

    private static function trial_rows( int $child_id, int $month = 0, int $year = 0 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, external_session_id, subject, level, period,
                    difficulty, source, task_id, score, total, percentage,
                    time_taken_seconds, started_at, completed_at
             FROM {$wpdb->prefix}knowly_exam_sessions
             WHERE child_id = %d AND state = 'completed'" . self::date_clause( $month, $year ),
            $child_id
        ), ARRAY_A ) ?: [];

        return array_map( fn( $r ) => [
            'type'               => 'trial',
            'session_id'         => (int) $r['session_id'],
            'content_id'         => $r['external_session_id'],
            'subject'            => $r['subject'],
            'level'              => $r['level'],
            'period'             => $r['period'],
            'difficulty'         => $r['difficulty'],
            'source'             => $r['source'],
            'task_id'            => $r['task_id'] ? (int) $r['task_id'] : null,
            'score'              => (int) $r['score'],
            'total'              => (int) $r['total'],
            'percentage'         => (float) $r['percentage'],
            'duration_seconds'   => (int) $r['time_taken_seconds'],
            'started_at'         => $r['started_at'],
            'completed_at'       => $r['completed_at'],
        ], $rows );
    }

    private static function quest_rows( int $child_id, int $month = 0, int $year = 0 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT qs.quest_session_id, qs.quest_id, qs.source, qs.task_id,
                    qs.started_at, qs.completed_at,
                    q.topic, q.module_title, q.subject,
                    COUNT(qr.id)       AS total_questions,
                    SUM(qr.is_correct) AS correct_questions
             FROM {$wpdb->prefix}knowly_quest_sessions qs
             LEFT JOIN {$wpdb->prefix}knowly_quests q  ON q.quest_id  = qs.quest_id
             LEFT JOIN {$wpdb->prefix}knowly_quest_question_results qr
                    ON qr.session_id = qs.quest_session_id
                   AND qr.child_id   = qs.child_id
             WHERE qs.child_id = %d AND qs.state = 'completed'" . self::date_clause( $month, $year, 'qs.completed_at' ) . "
             GROUP BY qs.quest_session_id
             ORDER BY qs.completed_at DESC",
            $child_id
        ), ARRAY_A ) ?: [];

        return array_map( function( $r ) {
            $total   = (int) $r['total_questions'];
            $correct = (int) $r['correct_questions'];
            return [
                'type'             => 'quest',
                'session_id'       => $r['quest_session_id'],
                'content_id'       => $r['quest_id'],
                'subject'          => $r['subject']      ?? null,
                'topic'            => $r['topic']        ?? null,
                'module_title'     => $r['module_title'] ?? null,
                'level'            => null,
                'period'           => null,
                'difficulty'       => null,
                'source'           => $r['source'],
                'task_id'          => $r['task_id'] ? (int) $r['task_id'] : null,
                'score'            => $correct,
                'total'            => $total,
                'percentage'       => $total > 0 ? round( ( $correct / $total ) * 100, 1 ) : null,
                'duration_seconds' => null,
                'started_at'       => $r['started_at'],
                'completed_at'     => $r['completed_at'],
            ];
        }, $rows );
    }

    private static function lesson_rows( int $child_id, int $month = 0, int $year = 0 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ls.lesson_session_id, ls.quest_id, ls.source,
                    ls.started_at, ls.completed_at,
                    q.topic, q.module_title, q.subject,
                    COUNT(lr.id)       AS total_questions,
                    SUM(lr.is_correct) AS correct_questions
             FROM {$wpdb->prefix}knowly_lesson_sessions ls
             LEFT JOIN {$wpdb->prefix}knowly_quests q  ON q.quest_id  = ls.quest_id
             LEFT JOIN {$wpdb->prefix}knowly_lesson_question_results lr
                    ON lr.session_id = ls.lesson_session_id
                   AND lr.child_id   = ls.child_id
             WHERE ls.child_id = %d AND ls.state = 'completed'" . self::date_clause( $month, $year, 'ls.completed_at' ) . "
             GROUP BY ls.lesson_session_id
             ORDER BY ls.completed_at DESC",
            $child_id
        ), ARRAY_A ) ?: [];

        return array_map( function( $r ) {
            $total   = (int) $r['total_questions'];
            $correct = (int) $r['correct_questions'];
            return [
                'type'             => 'lesson',
                'session_id'       => $r['lesson_session_id'],
                'content_id'       => $r['quest_id'],
                'subject'          => $r['subject']      ?? null,
                'topic'            => $r['topic']        ?? null,
                'module_title'     => $r['module_title'] ?? null,
                'level'            => null,
                'period'           => null,
                'difficulty'       => null,
                'source'           => $r['source'],
                'task_id'          => null,
                'score'            => $correct,
                'total'            => $total,
                'percentage'       => $total > 0 ? round( ( $correct / $total ) * 100, 1 ) : null,
                'duration_seconds' => null,
                'started_at'       => $r['started_at'],
                'completed_at'     => $r['completed_at'],
            ];
        }, $rows );
    }
}
