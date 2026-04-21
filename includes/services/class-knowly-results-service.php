<?php
/**
 * Knowly_Results_Service — Exam results persistence and analytics.
 *
 * Handles:
 *  - Saving session + answers + topic breakdown
 *  - Updating child summary meta
 *  - Querying history, single session, and aggregate stats
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Results_Service {

    // ── Save ──────────────────────────────────────────────────────────────────

    /**
     * Persist a completed exam submission.
     *
     * @param  array $session  Raw session row from wp_knowly_exam_sessions.
     * @param  array $answers  Array of answer objects from client.
     * @return array|WP_Error  Session summary.
     */
    public static function save_submission( array $session, array $answers ): array|WP_Error {
        global $wpdb;

        Knowly_Debug::log( 'results.save', 'Saving exam submission', [
            'session_id' => $session['session_id'],
            'child_id'   => $session['child_id'],
            'answers'    => count( $answers ),
        ], (int) $session['child_id'], 'info' );

        // ── Score calculation ─────────────────────────────────────────────────
        $total      = count( $answers );
        $correct    = 0;
        $time_taken = 0;

        // Load server-side answer key.
        // Prefer the answer_sheet stored on the session itself (captured at exam-start).
        // Fall back to looking up the trial_packages table in case the session row pre-dates
        // the answer_sheet column being added to exam_sessions.
        // answer_sheet format: array of { question_id, correct_answer, explanation }.
        $answer_key = [];
        $raw_sheet  = $session['answer_sheet'] ?? null;

        if ( ! $raw_sheet && ! empty( $session['package_id'] ) ) {
            $raw_sheet = $wpdb->get_var( $wpdb->prepare(
                "SELECT answer_sheet FROM {$wpdb->prefix}knowly_trial_packages WHERE package_id = %s",
                $session['package_id']
            ) );
        }

        if ( $raw_sheet ) {
            $sheet = json_decode( $raw_sheet, true );
            if ( is_array( $sheet ) ) {
                foreach ( $sheet as $entry ) {
                    if ( isset( $entry['question_id'], $entry['correct_answer'] ) ) {
                        $answer_key[ $entry['question_id'] ] = strtoupper( trim( $entry['correct_answer'] ) );
                    }
                }
            }
        }

        Knowly_Debug::log( 'results.save', 'Answer key loaded', [
            'session_id'  => $session['session_id'],
            'package_id'  => $session['package_id'],
            'key_count'   => count( $answer_key ),
            'sheet_source' => ! empty( $session['answer_sheet'] ) ? 'session' : ( $raw_sheet ? 'package_lookup' : 'missing' ),
        ], (int) $session['child_id'], 'info' );


        foreach ( $answers as &$ans ) {
            $qid      = $ans['question_id'] ?? '';
            $selected = strtoupper( trim( $ans['selected_answer'] ?? '' ) );

            // Server-side scoring: compare against the stored answer key.
            if ( isset( $answer_key[ $qid ] ) ) {
                $ans['correct_answer'] = $answer_key[ $qid ];
                $ans['is_correct']     = ( $selected === $answer_key[ $qid ] );
            }
            // If question_id is absent from the key (edge case), keep client value.

            if ( ! empty( $ans['is_correct'] ) ) {
                $correct++;
            }
            $time_taken += (int) ( $ans['time_taken_seconds'] ?? 0 );
        }
        unset( $ans );

        $percentage = $total > 0 ? round( ( $correct / $total ) * 100, 2 ) : 0;

        // ── Update session state ──────────────────────────────────────────────
        $wpdb->update(
            $wpdb->prefix . 'knowly_exam_sessions',
            [
                'state'              => 'completed',
                'score'              => $correct,
                'total'              => $total,
                'percentage'         => $percentage,
                'time_taken_seconds' => $time_taken,
                'completed_at'       => current_time( 'mysql', true ),
            ],
            [ 'session_id' => $session['session_id'] ],
            [ '%s', '%d', '%d', '%f', '%d', '%s' ],
            [ '%d' ]
        );

        // ── Insert answers ────────────────────────────────────────────────────
        foreach ( $answers as $ans ) {
            $cognitive = self::normalise_cognitive( $ans['cognitive_level'] ?? '' );
            $wpdb->insert(
                $wpdb->prefix . 'knowly_exam_answers',
                [
                    'session_id'         => $session['session_id'],
                    'child_id'           => $session['child_id'],
                    'question_id'        => sanitize_text_field( $ans['question_id'] ?? '' ),
                    'topic'              => sanitize_text_field( $ans['topic'] ?? '' ),
                    'subtopic'           => sanitize_text_field( $ans['subtopic'] ?? '' ) ?: null,
                    'cognitive_level'    => $cognitive,
                    'selected_answer'    => sanitize_text_field( $ans['selected_answer'] ?? '' ) ?: null,
                    'correct_answer'     => sanitize_text_field( $ans['correct_answer'] ?? '' ),
                    'is_correct'         => ! empty( $ans['is_correct'] ) ? 1 : 0,
                    'time_taken_seconds' => (int) ( $ans['time_taken_seconds'] ?? 0 ) ?: null,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
            );
        }

        // ── Topic breakdown ───────────────────────────────────────────────────
        $breakdown = self::calculate_topic_breakdown( (int) $session['session_id'], (int) $session['child_id'], $answers );
        self::save_topic_breakdown( (int) $session['session_id'], (int) $session['child_id'], $breakdown );

        // ── Update child summary meta ─────────────────────────────────────────
        self::update_child_summary( (int) $session['child_id'] );

        Knowly_Debug::log( 'results.save', 'Results saved successfully', [
            'session_id' => $session['session_id'],
            'score'      => "{$correct}/{$total}",
            'percentage' => $percentage,
            'topics'     => count( $breakdown ),
        ], (int) $session['child_id'], 'info' );

        return [
            'session_id'         => (int) $session['session_id'],
            'external_session_id' => $session['external_session_id'],
            'score'              => $correct,
            'total'              => $total,
            'percentage'         => $percentage,
            'time_taken_seconds' => $time_taken,
            'topic_breakdown'    => $breakdown,
            'completed_at'       => current_time( 'mysql', true ),
        ];
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * List completed sessions for a child (paginated).
     */
    public static function get_sessions( int $child_id, int $page = 1, int $per_page = 20 ): array {
        global $wpdb;

        $offset = ( $page - 1 ) * $per_page;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT session_id, external_session_id, subject, standard, term, difficulty,
                        score, total, percentage, time_taken_seconds, started_at, completed_at
                 FROM {$wpdb->prefix}knowly_exam_sessions
                 WHERE child_id = %d AND state = 'completed'
                 ORDER BY completed_at DESC
                 LIMIT %d OFFSET %d",
                $child_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_sessions WHERE child_id = %d AND state = 'completed'",
                $child_id
            )
        );

        Knowly_Debug::log( 'results.sessions', 'Session history fetched', [
            'child_id' => $child_id,
            'page'     => $page,
            'total'    => $total,
        ], $child_id, 'debug' );

        return [
            'sessions'   => array_map( [ __CLASS__, 'format_session' ], $rows ),
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /**
     * Get a single session with full answer detail.
     */
    public static function get_session_detail( int $session_id, int $child_id ): array|WP_Error {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_exam_sessions WHERE session_id = %d AND child_id = %d",
                $session_id,
                $child_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return new WP_Error( 'knowly_not_found', 'Session not found.', [ 'status' => 404 ] );
        }

        $answers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_exam_answers WHERE session_id = %d ORDER BY answer_id ASC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        $breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_topic_breakdown WHERE session_id = %d ORDER BY pct DESC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        Knowly_Debug::log( 'results.detail', 'Session detail fetched', [
            'session_id' => $session_id,
            'child_id'   => $child_id,
        ], $child_id, 'debug' );

        return [
            'session'         => self::format_session( $session ),
            'answers'         => $answers,
            'topic_breakdown' => $breakdown,
        ];
    }

    /**
     * Aggregate stats for a child.
     */
    public static function get_stats( int $child_id ): array {
        global $wpdb;

        $totals = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) as exams_completed,
                        AVG(percentage) as average_pct,
                        SUM(time_taken_seconds) as total_time_seconds
                 FROM {$wpdb->prefix}knowly_exam_sessions
                 WHERE child_id = %d AND state = 'completed'",
                $child_id
            ),
            ARRAY_A
        );

        // Topic performance aggregated across all sessions
        $topics = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT topic,
                        SUM(correct) as total_correct,
                        SUM(total)   as total_questions,
                        ROUND((SUM(correct) / SUM(total)) * 100, 1) as overall_pct
                 FROM {$wpdb->prefix}knowly_topic_breakdown
                 WHERE child_id = %d
                 GROUP BY topic
                 ORDER BY overall_pct DESC",
                $child_id
            ),
            ARRAY_A
        ) ?: [];

        $strongest = ! empty( $topics ) ? $topics[0]['topic'] : null;
        $weakest   = ! empty( $topics ) ? $topics[ count( $topics ) - 1 ]['topic'] : null;

        Knowly_Debug::log( 'results.stats', 'Stats fetched', [
            'child_id'         => $child_id,
            'exams_completed'  => $totals['exams_completed'] ?? 0,
        ], $child_id, 'debug' );

        return [
            'exams_completed'    => (int) ( $totals['exams_completed'] ?? 0 ),
            'average_pct'        => round( (float) ( $totals['average_pct'] ?? 0 ), 1 ),
            'total_time_seconds' => (int) ( $totals['total_time_seconds'] ?? 0 ),
            'strongest_topic'    => $strongest,
            'weakest_topic'      => $weakest,
            'topics'             => $topics,
        ];
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function calculate_topic_breakdown( int $session_id, int $child_id, array $answers ): array {
        $topics = [];

        foreach ( $answers as $ans ) {
            $topic = sanitize_text_field( $ans['topic'] ?? 'Unknown' );
            if ( ! isset( $topics[ $topic ] ) ) {
                $topics[ $topic ] = [ 'correct' => 0, 'total' => 0 ];
            }
            $topics[ $topic ]['total']++;
            if ( ! empty( $ans['is_correct'] ) ) {
                $topics[ $topic ]['correct']++;
            }
        }

        $breakdown = [];
        foreach ( $topics as $topic => $counts ) {
            $pct         = $counts['total'] > 0 ? round( ( $counts['correct'] / $counts['total'] ) * 100, 1 ) : 0;
            $breakdown[] = [
                'topic'   => $topic,
                'correct' => $counts['correct'],
                'total'   => $counts['total'],
                'pct'     => $pct,
            ];
        }

        return $breakdown;
    }

    private static function save_topic_breakdown( int $session_id, int $child_id, array $breakdown ): void {
        global $wpdb;

        foreach ( $breakdown as $item ) {
            $wpdb->insert(
                $wpdb->prefix . 'knowly_topic_breakdown',
                [
                    'session_id' => $session_id,
                    'child_id'   => $child_id,
                    'topic'      => $item['topic'],
                    'correct'    => $item['correct'],
                    'total'      => $item['total'],
                    'pct'        => $item['pct'],
                ],
                [ '%d', '%d', '%s', '%d', '%d', '%f' ]
            );
        }
    }

    private static function update_child_summary( int $child_id ): void {
        global $wpdb;

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) as total,
                        AVG(percentage) as avg_pct,
                        MAX(completed_at) as last_at
                 FROM {$wpdb->prefix}knowly_exam_sessions
                 WHERE child_id = %d AND state = 'completed'",
                $child_id
            ),
            ARRAY_A
        );

        update_user_meta( $child_id, 'knowly_total_exams', (int) $stats['total'] );
        update_user_meta( $child_id, 'knowly_average_score_pct', round( (float) $stats['avg_pct'], 1 ) );
        update_user_meta( $child_id, 'knowly_last_exam_at', $stats['last_at'] );
    }

    private static function normalise_cognitive( string $level ): string {
        return match ( strtolower( $level ) ) {
            'knowledge', 'recall'                             => 'recall',
            'comprehension', 'application', 'apply'          => 'application',
            'analysis', 'analyse', 'analyze', 'synthesis',
            'evaluation', 'evaluate'                         => 'analysis',
            default                                          => 'recall',
        };
    }

    private static function format_session( array $row ): array {
        return [
            'session_id'          => (int) $row['session_id'],
            'external_session_id' => $row['external_session_id'],
            'subject'             => $row['subject'],
            'level'     => $row['level'],
            'period'   => $row['period'],
            'difficulty'          => $row['difficulty'],
            'score'               => (int) $row['score'],
            'total'               => (int) $row['total'],
            'percentage'          => (float) $row['percentage'],
            'time_taken_seconds'  => (int) $row['time_taken_seconds'],
            'started_at'          => $row['started_at'],
            'completed_at'        => $row['completed_at'],
        ];
    }
}
