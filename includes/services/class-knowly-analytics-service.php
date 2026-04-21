<?php
/**
 * Knowly_Analytics_Service — WP-local analytics. Reads exclusively from MySQL.
 *
 * No Railway calls. Source of truth:
 *   wp_knowly_exam_sessions   — completed trial sessions
 *   wp_knowly_quest_sessions  — completed quest sessions
 *   wp_knowly_exam_answers    — per-question results (topic + correctness)
 *   wp_knowly_topic_breakdown — per-session topic aggregates
 *
 * Public API (auth-aware, called by Knowly_Analytics_API REST endpoints):
 *   get_class_analytics(class_id, teacher_id, filters)
 *   get_student_analytics(class_id, student_id, teacher_id, filters)
 *   get_self_analytics(parent_id, filters)
 *
 * Public API (no auth, called by admin panel AJAX handlers):
 *   get_student_data(child_id, filters)
 *   get_class_data(child_ids, filters)
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Analytics_Service {

    // =========================================================================
    // Auth-aware wrappers — called by REST API (Knowly_Analytics_API)
    // =========================================================================

    /**
     * Class-level analytics for a teacher viewing their own class.
     *
     * @param  int   $class_id
     * @param  int   $teacher_user_id
     * @param  array $filters  Optional: ['period' => string, 'subject' => string]
     * @return array|WP_Error
     */
    public static function get_class_analytics(
        int   $class_id,
        int   $teacher_user_id,
        array $filters = []
    ): array|WP_Error {
        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_user_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $members = Knowly_Class_Service::get_members( $class_id );
        if ( empty( $members ) ) {
            return self::empty_class_response( $class_id, $filters );
        }

        $child_ids = array_map( fn( $m ) => (int) $m['child_id'], $members );
        $data      = self::get_class_data( $child_ids, $filters );

        // Merge WP display data into each student row
        $member_map = [];
        foreach ( $members as $m ) {
            $member_map[ (int) $m['child_id'] ] = $m;
        }
        $data['students'] = array_map( function( array $s ) use ( $member_map ): array {
            $uid = (int) ( $s['user_id'] ?? 0 );
            if ( isset( $member_map[ $uid ] ) ) {
                $s['nickname']  = $member_map[ $uid ]['nickname'];
                $s['level']     = $member_map[ $uid ]['level'];
                $s['joined_at'] = $member_map[ $uid ]['joined_at'];
            }
            return $s;
        }, $data['students'] );

        $data['class_id'] = $class_id;
        return $data;
    }

    /**
     * Per-student analytics for a teacher viewing one of their class members.
     *
     * @param  int   $class_id
     * @param  int   $student_id
     * @param  int   $teacher_user_id
     * @param  array $filters
     * @return array|WP_Error
     */
    public static function get_student_analytics(
        int   $class_id,
        int   $student_id,
        int   $teacher_user_id,
        array $filters = []
    ): array|WP_Error {
        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_user_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $members   = Knowly_Class_Service::get_members( $class_id );
        $is_member = ! empty( array_filter( $members, fn( $m ) => (int) $m['child_id'] === $student_id ) );
        if ( ! $is_member ) {
            return new WP_Error( 'knowly_forbidden', 'This student is not a member of your class.', [ 'status' => 403 ] );
        }

        $data             = self::get_student_data( $student_id, $filters );
        $data['nickname'] = get_user_meta( $student_id, 'knowly_nickname', true ) ?: '';
        $data['level']    = get_user_meta( $student_id, 'knowly_level',    true ) ?: '';
        return $data;
    }

    /**
     * Self-analytics for a parent (via JWT). Resolves their linked children.
     *
     * @param  int   $parent_user_id  WP user ID from JWT.
     * @param  array $filters
     * @return array|WP_Error
     */
    public static function get_self_analytics( int $parent_user_id, array $filters = [] ): array|WP_Error {
        $children = Knowly_Children_Service::list_children( $parent_user_id );
        if ( empty( $children ) ) {
            return new WP_Error( 'knowly_not_found', 'No children linked to this account.', [ 'status' => 404 ] );
        }

        if ( count( $children ) === 1 ) {
            $child                = $children[0];
            $data                 = self::get_student_data( (int) $child['child_id'], $filters );
            $data['nickname']     = $child['display_name'] ?? '';
            $data['avatar_index'] = (int) ( $child['avatar_index'] ?? 1 );
            return $data;
        }

        $results = [];
        foreach ( $children as $child ) {
            $data      = self::get_student_data( (int) $child['child_id'], $filters );
            $results[] = array_merge( $data, [
                'nickname'     => $child['display_name'] ?? '',
                'avatar_index' => (int) ( $child['avatar_index'] ?? 1 ),
            ] );
        }
        return [ 'children' => $results ];
    }

    // =========================================================================
    // Direct data access — no auth, called by admin panel AJAX handlers
    // =========================================================================

    /**
     * Full analytics for a single student.
     * No auth check — caller is responsible for access control.
     *
     * @param  int   $child_id
     * @param  array $filters  Optional: ['period' => string, 'subject' => string]
     * @return array
     */
    public static function get_student_data( int $child_id, array $filters = [] ): array {
        $period  = sanitize_text_field( $filters['period']  ?? '' );
        $subject = sanitize_text_field( $filters['subject'] ?? '' );

        // ── 1. Data fetches ───────────────────────────────────────────────────
        $trials  = self::fetch_trials( $child_id, $period, $subject );
        $quests  = self::fetch_quests( $child_id );
        $answers = self::fetch_answers( $child_id, $period, $subject );

        // ── 2. Computations ───────────────────────────────────────────────────
        $topic_breakdown = self::build_topic_breakdown( $answers );
        $subjects_data   = self::build_subject_breakdown( $trials, $topic_breakdown );
        $trend           = self::build_trend( $trials );

        // Retry effectiveness uses per-topic scores in chronological order
        $topic_scores_chron = self::fetch_topic_scores_chron( $child_id, $period, $subject );
        $retry              = self::build_retry_effectiveness( $topic_scores_chron );

        // ── 3. Aggregate scalars ──────────────────────────────────────────────
        $scores    = array_filter( array_map( fn( $t ) => $t['percentage'], $trials ), fn( $p ) => $p !== null && $p !== '' );
        $avg_score = count( $scores ) ? (int) round( array_sum( $scores ) / count( $scores ) ) : null;

        $cutoff7d      = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        $weekly_trials = count( array_filter( $trials, fn( $t ) => ! empty( $t['completed_at'] ) && $t['completed_at'] >= $cutoff7d ) );

        $topics_attempted = count( array_unique( array_filter( array_column( $answers, 'topic' ) ) ) );

        $strengths  = array_values( array_map(
            fn( $t ) => [ 'topic' => $t['topic'], 'subject' => $t['subject'], 'correct_rate' => $t['correct_rate'] ],
            array_filter( $topic_breakdown, fn( $t ) => $t['is_strength'] )
        ) );
        $weaknesses = array_values( array_map(
            fn( $t ) => [ 'topic' => $t['topic'], 'subject' => $t['subject'], 'correct_rate' => $t['correct_rate'] ],
            array_filter( $topic_breakdown, fn( $t ) => $t['is_weakness'] )
        ) );

        // ── 4. Enrich recent trials with dominant topic ───────────────────────
        $session_ids    = array_column( $trials, 'session_id' );
        $session_topics = self::get_session_dominant_topics( $session_ids );

        $recent_trials = array_slice( array_map( function( array $t ) use ( $session_topics ): array {
            return [
                'subject'      => $t['subject'],
                'topic'        => $session_topics[ (int) $t['session_id'] ] ?? null,
                'difficulty'   => $t['difficulty'],
                'percentage'   => $t['percentage'] !== null ? (float) $t['percentage'] : null,
                'source'       => $t['source'],
                'completed_at' => $t['completed_at'],
            ];
        }, $trials ), 0, 10 );

        return [
            'user_id'             => $child_id,
            'trial_count'         => count( $trials ),
            'quest_count'         => count( $quests ),
            'badges_earned'       => 0,
            'avg_score'           => $avg_score,
            'weekly_trials'       => $weekly_trials,
            'topics_attempted'    => $topics_attempted,
            'direct_count'        => count( array_filter( $trials, fn( $t ) => $t['source'] === 'self' ) ),
            'assignment_count'    => count( array_filter( $trials, fn( $t ) => $t['source'] === 'teacher_assigned' ) ),
            'at_risk'             => self::is_at_risk( $subjects_data, $avg_score ),
            'subjects'            => $subjects_data,
            'topic_breakdown'     => $topic_breakdown,
            'strengths'           => $strengths,
            'weaknesses'          => $weaknesses,
            'trend'               => $trend,
            'retry_effectiveness' => $retry,
            'recent_trials'       => $recent_trials,
            'recent_quests'       => array_slice( $quests, 0, 10 ),
            'filters'             => [ 'period' => $period ?: null, 'subject' => $subject ?: null ],
        ];
    }

    /**
     * Class-level aggregate analytics for a list of child IDs.
     * No auth check — caller is responsible for access control.
     *
     * @param  int[]  $child_ids
     * @param  array  $filters
     * @return array
     */
    public static function get_class_data( array $child_ids, array $filters = [] ): array {
        if ( empty( $child_ids ) ) {
            return self::empty_class_response( 0, $filters );
        }

        $period  = sanitize_text_field( $filters['period']  ?? '' );
        $subject = sanitize_text_field( $filters['subject'] ?? '' );

        // ── 1. Fetch all data for the cohort ──────────────────────────────────
        $all_trials  = self::fetch_trials( $child_ids, $period, $subject );
        $all_quests  = self::fetch_quests( $child_ids );
        $all_answers = self::fetch_answers( $child_ids, $period, $subject );

        // ── 2. Build per-student summaries ─────────────────────────────────────
        $cutoff7d    = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        $student_map = [];
        foreach ( $child_ids as $uid ) {
            $uid = (int) $uid;
            $student_map[ $uid ] = [
                'user_id'          => $uid,
                'trial_count'      => 0,
                'quest_count'      => 0,
                'avg_score'        => null,
                'badges_earned'    => 0,
                'direct_count'     => 0,
                'assignment_count' => 0,
                'weekly_trials'    => 0,
                'last_active'      => null,
                'at_risk'          => false,
                '_scores'          => [],
                '_subjects'        => [],
            ];
        }

        foreach ( $all_trials as $t ) {
            $uid = (int) $t['child_id'];
            if ( ! isset( $student_map[ $uid ] ) ) continue;
            $s = &$student_map[ $uid ];
            $s['trial_count']++;
            if ( $t['percentage'] !== null && $t['percentage'] !== '' ) {
                $s['_scores'][] = (float) $t['percentage'];
            }
            $t['source'] === 'teacher_assigned' ? $s['assignment_count']++ : $s['direct_count']++;
            if ( ! empty( $t['completed_at'] ) && $t['completed_at'] >= $cutoff7d ) {
                $s['weekly_trials']++;
            }
            if ( ! empty( $t['completed_at'] ) ) {
                if ( ! $s['last_active'] || $t['completed_at'] > $s['last_active'] ) {
                    $s['last_active'] = $t['completed_at'];
                }
            }
            $subj = $t['subject'] ?? '';
            if ( $subj ) {
                if ( ! isset( $s['_subjects'][ $subj ] ) ) {
                    $s['_subjects'][ $subj ] = [ 'trial_count' => 0, 'scores' => [] ];
                }
                $s['_subjects'][ $subj ]['trial_count']++;
                if ( $t['percentage'] !== null && $t['percentage'] !== '' ) {
                    $s['_subjects'][ $subj ]['scores'][] = (float) $t['percentage'];
                }
            }
        }
        unset( $s );

        foreach ( $all_quests as $q ) {
            $uid = (int) $q['child_id'];
            if ( ! isset( $student_map[ $uid ] ) ) continue;
            $student_map[ $uid ]['quest_count']++;
            if ( ! empty( $q['completed_at'] ) &&
                ( ! $student_map[ $uid ]['last_active'] || $q['completed_at'] > $student_map[ $uid ]['last_active'] ) ) {
                $student_map[ $uid ]['last_active'] = $q['completed_at'];
            }
        }

        // Finalise per-student (compute avg_score + at_risk, strip internal keys)
        $students = [];
        foreach ( $student_map as &$s ) {
            $s['avg_score'] = count( $s['_scores'] )
                ? (int) round( array_sum( $s['_scores'] ) / count( $s['_scores'] ) )
                : null;

            $sub_data    = array_map( fn( $subj, $d ) => [
                'subject'     => $subj,
                'trial_count' => $d['trial_count'],
                'avg_score'   => count( $d['scores'] )
                    ? (int) round( array_sum( $d['scores'] ) / count( $d['scores'] ) )
                    : null,
            ], array_keys( $s['_subjects'] ), $s['_subjects'] );
            $s['at_risk'] = self::is_at_risk( $sub_data, $s['avg_score'] );

            unset( $s['_scores'], $s['_subjects'] );
            $students[] = $s;
        }
        unset( $s );

        // ── 3. Class-level aggregates ──────────────────────────────────────────
        $class_scores    = array_filter( array_column( $students, 'avg_score' ), fn( $v ) => $v !== null );
        $class_avg_score = count( $class_scores )
            ? (int) round( array_sum( $class_scores ) / count( $class_scores ) )
            : null;

        $subj_counts = [];
        foreach ( $all_trials as $t ) {
            if ( ! empty( $t['subject'] ) ) {
                $subj_counts[ $t['subject'] ] = ( $subj_counts[ $t['subject'] ] ?? 0 ) + 1;
            }
        }
        arsort( $subj_counts );
        $most_active_subject = array_key_first( $subj_counts ) ?: null;

        $avg_engagement_rate = count( $students )
            ? round( array_sum( array_column( $students, 'weekly_trials' ) ) / count( $students ), 1 )
            : 0;

        // ── 4. Topic heatmap (class-wide, includes student_count per topic) ────
        $topic_heatmap = self::build_topic_breakdown( $all_answers, true );
        $strengths     = array_values( array_filter( $topic_heatmap, fn( $t ) => $t['is_strength'] ) );
        $weaknesses    = array_values( array_filter( $topic_heatmap, fn( $t ) => $t['is_weakness'] ) );

        return [
            'student_count'       => count( $child_ids ),
            'total_trials'        => count( $all_trials ),
            'total_quests'        => count( $all_quests ),
            'total_badges'        => 0,
            'class_avg_score'     => $class_avg_score,
            'most_active_subject' => $most_active_subject,
            'avg_engagement_rate' => $avg_engagement_rate,
            'direct_count'        => count( array_filter( $all_trials, fn( $t ) => $t['source'] !== 'teacher_assigned' ) ),
            'assignment_count'    => count( array_filter( $all_trials, fn( $t ) => $t['source'] === 'teacher_assigned' ) ),
            'at_risk_count'       => count( array_filter( $students, fn( $s ) => $s['at_risk'] ) ),
            'topic_heatmap'       => $topic_heatmap,
            'strengths'           => $strengths,
            'weaknesses'          => $weaknesses,
            'students'            => $students,
            'filters'             => [ 'period' => $period ?: null, 'subject' => $subject ?: null ],
        ];
    }

    // =========================================================================
    // Private — data fetchers (single child or array of children)
    // =========================================================================

    /**
     * Fetch completed exam sessions.
     * When $child_id is an array, the result includes a child_id column.
     */
    private static function fetch_trials( int|array $child_id, string $period, string $subject ): array {
        global $wpdb;

        $is_multi = is_array( $child_id );
        $ids      = $is_multi ? array_map( 'intval', $child_id ) : [ (int) $child_id ];
        if ( empty( $ids ) ) return [];

        $ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $where  = [ "child_id IN ({$ph})", "state = 'completed'" ];
        $params = $ids;

        if ( $period )  { $where[] = 'period = %s';  $params[] = $period; }
        if ( $subject ) { $where[] = 'subject = %s'; $params[] = $subject; }

        $select = $is_multi
            ? 'session_id, child_id, subject, level, period, difficulty, trial_type, source, percentage, completed_at'
            : 'session_id, external_session_id, subject, level, period, difficulty, trial_type, source, percentage, score, total, time_taken_seconds, completed_at';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT {$select}
             FROM {$wpdb->prefix}knowly_exam_sessions
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY completed_at DESC",
            ...$params
        ), ARRAY_A ) ?: [];
    }

    /**
     * Fetch completed quest sessions.
     * When $child_id is an array, the result includes a child_id column.
     */
    private static function fetch_quests( int|array $child_id ): array {
        global $wpdb;

        $is_multi = is_array( $child_id );
        $ids      = $is_multi ? array_map( 'intval', $child_id ) : [ (int) $child_id ];
        if ( empty( $ids ) ) return [];

        $ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $select = $is_multi
            ? 'child_id, quest_session_id, quest_id, source, completed_at'
            : 'quest_session_id, quest_id, source, completed_at';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT {$select}
             FROM {$wpdb->prefix}knowly_quest_sessions
             WHERE child_id IN ({$ph}) AND state = 'completed'
             ORDER BY completed_at DESC",
            ...$ids
        ), ARRAY_A ) ?: [];
    }

    /**
     * Fetch per-question answer rows joined to sessions (for subject/period filter).
     * When $child_id is an array, the result includes a child_id column.
     */
    private static function fetch_answers( int|array $child_id, string $period, string $subject ): array {
        global $wpdb;

        $is_multi = is_array( $child_id );
        $ids      = $is_multi ? array_map( 'intval', $child_id ) : [ (int) $child_id ];
        if ( empty( $ids ) ) return [];

        $ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $where  = [ "ea.child_id IN ({$ph})", "es.state = 'completed'" ];
        $params = $ids;

        if ( $period )  { $where[] = 'es.period = %s';  $params[] = $period; }
        if ( $subject ) { $where[] = 'es.subject = %s'; $params[] = $subject; }

        $select = $is_multi
            ? 'ea.child_id, ea.topic, es.subject, ea.is_correct'
            : 'ea.topic, es.subject, ea.is_correct';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT {$select}
             FROM {$wpdb->prefix}knowly_exam_answers ea
             INNER JOIN {$wpdb->prefix}knowly_exam_sessions es ON es.session_id = ea.session_id
             WHERE " . implode( ' AND ', $where ),
            ...$params
        ), ARRAY_A ) ?: [];
    }

    /**
     * Fetch per-topic scores in chronological order for retry effectiveness.
     * Joins topic_breakdown (per-session aggregates) to exam_sessions for ordering.
     */
    private static function fetch_topic_scores_chron( int $child_id, string $period, string $subject ): array {
        global $wpdb;

        $where  = [ 'tb.child_id = %d', "es.state = 'completed'" ];
        $params = [ $child_id ];

        if ( $period )  { $where[] = 'es.period = %s';  $params[] = $period; }
        if ( $subject ) { $where[] = 'es.subject = %s'; $params[] = $subject; }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT tb.topic, es.subject, tb.pct AS percentage, es.completed_at
             FROM {$wpdb->prefix}knowly_topic_breakdown tb
             INNER JOIN {$wpdb->prefix}knowly_exam_sessions es ON es.session_id = tb.session_id
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY es.completed_at ASC",
            ...$params
        ), ARRAY_A ) ?: [];
    }

    /**
     * For each session_id, return the topic with the highest question count.
     * Used to annotate recent_trials with a dominant topic (sessions have no topic column).
     *
     * @param  int[] $session_ids
     * @return array  [ session_id => topic_string ]
     */
    private static function get_session_dominant_topics( array $session_ids ): array {
        if ( empty( $session_ids ) ) return [];
        global $wpdb;

        $ids  = implode( ',', array_map( 'intval', $session_ids ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT tb.session_id, tb.topic
             FROM {$wpdb->prefix}knowly_topic_breakdown tb
             INNER JOIN (
                 SELECT session_id, MAX(total) AS max_total
                 FROM {$wpdb->prefix}knowly_topic_breakdown
                 WHERE session_id IN ({$ids})
                 GROUP BY session_id
             ) mx ON mx.session_id = tb.session_id AND mx.max_total = tb.total
             WHERE tb.session_id IN ({$ids})",
            ARRAY_A
        ) ?: [];

        $map = [];
        foreach ( $rows as $row ) {
            $sid = (int) $row['session_id'];
            if ( ! isset( $map[ $sid ] ) ) { // first row wins on ties
                $map[ $sid ] = $row['topic'];
            }
        }
        return $map;
    }

    // =========================================================================
    // Private — computation helpers
    // =========================================================================

    /**
     * Aggregate answer rows into per-topic stats.
     *
     * @param  array $answers          Rows: topic, subject, is_correct [, child_id]
     * @param  bool  $with_student_count  Include unique student count per topic (class view).
     * @return array
     */
    private static function build_topic_breakdown( array $answers, bool $with_student_count = false ): array {
        $map = [];
        foreach ( $answers as $r ) {
            $key = ( $r['subject'] ?? '' ) . '||' . ( $r['topic'] ?: 'General' );
            if ( ! isset( $map[ $key ] ) ) {
                $map[ $key ] = [
                    'topic'       => $r['topic'] ?: 'General',
                    'subject'     => $r['subject'] ?? '',
                    'total'       => 0,
                    'correct'     => 0,
                    'student_set' => [],
                ];
            }
            $map[ $key ]['total']++;
            if ( (int) $r['is_correct'] ) $map[ $key ]['correct']++;
            if ( $with_student_count && ! empty( $r['child_id'] ) ) {
                $map[ $key ]['student_set'][ (int) $r['child_id'] ] = true;
            }
        }

        $result = [];
        foreach ( $map as $item ) {
            $rate  = $item['total'] > 0
                ? (int) round( ( $item['correct'] / $item['total'] ) * 100 )
                : null;
            $entry = [
                'topic'           => $item['topic'],
                'subject'         => $item['subject'],
                'total_questions' => $item['total'],
                'correct_count'   => $item['correct'],
                'correct_rate'    => $rate,
                'is_strength'     => $rate !== null && $rate >= 70,
                'is_weakness'     => $rate !== null && $rate < 50,
            ];
            if ( $with_student_count ) {
                $entry['student_count'] = count( $item['student_set'] );
            }
            $result[] = $entry;
        }

        usort( $result, fn( $a, $b ) => strcmp( $a['subject'] . $a['topic'], $b['subject'] . $b['topic'] ) );
        return $result;
    }

    /**
     * Per-subject summary enriched with topic counts from the topic breakdown.
     */
    private static function build_subject_breakdown( array $trials, array $topic_breakdown ): array {
        $map = [];
        foreach ( $trials as $t ) {
            $subj = $t['subject'] ?? '';
            if ( ! $subj ) continue;
            if ( ! isset( $map[ $subj ] ) ) {
                $map[ $subj ] = [ 'subject' => $subj, 'trial_count' => 0, 'scores' => [], 'direct' => 0, 'assignment' => 0 ];
            }
            $map[ $subj ]['trial_count']++;
            if ( $t['percentage'] !== null && $t['percentage'] !== '' ) {
                $map[ $subj ]['scores'][] = (float) $t['percentage'];
            }
            $t['source'] === 'teacher_assigned'
                ? $map[ $subj ]['assignment']++
                : $map[ $subj ]['direct']++;
        }

        return array_values( array_map( function( array $s ) use ( $topic_breakdown ): array {
            $sub_topics = array_values( array_filter( $topic_breakdown, fn( $t ) => $t['subject'] === $s['subject'] ) );
            $avg        = count( $s['scores'] )
                ? (int) round( array_sum( $s['scores'] ) / count( $s['scores'] ) )
                : null;
            return [
                'subject'          => $s['subject'],
                'trial_count'      => $s['trial_count'],
                'avg_score'        => $avg,
                'direct_count'     => $s['direct'],
                'assignment_count' => $s['assignment'],
                'topics_covered'   => count( $sub_topics ),
                'topics_strong'    => count( array_filter( $sub_topics, fn( $t ) => $t['is_strength'] ) ),
                'topics_weak'      => count( array_filter( $sub_topics, fn( $t ) => $t['is_weakness'] ) ),
            ];
        }, $map ) );
    }

    /**
     * 4-week rolling trend (oldest → newest: W-3, W-2, W-1, W-current).
     * Trials expected in descending order (most recent first).
     */
    private static function build_trend( array $trials ): array {
        $now   = time();
        $trend = [];
        foreach ( [ 3, 2, 1, 0 ] as $weeks_ago ) {
            $start  = gmdate( 'Y-m-d H:i:s', $now - ( $weeks_ago + 1 ) * 7 * DAY_IN_SECONDS );
            $end    = gmdate( 'Y-m-d H:i:s', $now - $weeks_ago * 7 * DAY_IN_SECONDS );
            $bucket = array_filter( $trials, fn( $t ) =>
                ! empty( $t['completed_at'] ) &&
                $t['completed_at'] >= $start &&
                $t['completed_at'] <  $end
            );
            $scores = array_filter(
                array_map( fn( $t ) => $t['percentage'] !== null ? (float) $t['percentage'] : null, array_values( $bucket ) ),
                fn( $p ) => $p !== null
            );
            $trend[] = [
                'label'       => $weeks_ago === 0 ? 'This week' : "{$weeks_ago}w ago",
                'start'       => substr( $start, 0, 10 ),
                'end'         => substr( $end,   0, 10 ),
                'trial_count' => count( $bucket ),
                'avg_score'   => count( $scores )
                    ? (int) round( array_sum( $scores ) / count( $scores ) )
                    : null,
            ];
        }
        return $trend;
    }

    /**
     * Retry effectiveness — compares first vs. subsequent attempts per topic.
     * Input: rows in chronological order (oldest first) with keys: topic, subject, percentage.
     */
    private static function build_retry_effectiveness( array $topic_scores_chron ): array {
        $by_key = [];
        foreach ( $topic_scores_chron as $r ) {
            $key = ( $r['subject'] ?? '' ) . '||' . ( $r['topic'] ?? '' );
            if ( ! isset( $by_key[ $key ] ) ) {
                $by_key[ $key ] = [
                    'subject' => $r['subject'] ?? '',
                    'topic'   => $r['topic']   ?? null,
                    'scores'  => [],
                ];
            }
            $by_key[ $key ]['scores'][] = (float) $r['percentage'];
        }

        $result = [];
        foreach ( $by_key as $entry ) {
            if ( count( $entry['scores'] ) < 2 ) continue;
            $first      = $entry['scores'][0];
            $rest       = array_slice( $entry['scores'], 1 );
            $subsequent = (int) round( array_sum( $rest ) / count( $rest ) );
            $result[]   = [
                'subject'        => $entry['subject'],
                'topic'          => $entry['topic'],
                'attempts'       => count( $entry['scores'] ),
                'first_attempt'  => $first,
                'subsequent_avg' => $subsequent,
                'improvement'    => $subsequent - (int) $first,
            ];
        }

        usort( $result, fn( $a, $b ) => $b['improvement'] - $a['improvement'] );
        return $result;
    }

    /**
     * At-risk: avg < 40% overall, OR ≥ 2 subjects with avg < 50% and ≥ 2 trials.
     *
     * @param  array    $subjects   Subject breakdown rows with avg_score + trial_count.
     * @param  int|null $avg_score
     * @return bool
     */
    private static function is_at_risk( array $subjects, ?int $avg_score ): bool {
        if ( $avg_score !== null && $avg_score < 40 ) return true;
        $weak = array_filter( $subjects, fn( $s ) => $s['avg_score'] !== null && $s['avg_score'] < 50 && $s['trial_count'] >= 2 );
        return count( $weak ) >= 2;
    }

    /**
     * Empty class-level response shape used when no members or no data.
     */
    private static function empty_class_response( int $class_id, array $filters ): array {
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
}
