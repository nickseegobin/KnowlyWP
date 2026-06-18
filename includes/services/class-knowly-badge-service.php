<?php
/**
 * Knowly_Badge_Service — Badge award and retrieval.
 *
 * Three trigger types:
 *   quest_module_completion  — awarded when all sub-topics in a module are completed
 *   trial_count              — threshold-based, checked after every trial submission
 *   lesson_count             — threshold-based, checked after every lesson completion
 *
 * Definitions stored in wp_knowly_badge_definitions.
 * Awards stored in wp_knowly_badge_awards (idempotent via UNIQUE KEY uq_child_definition).
 *
 * Subject SVG templates stored in WP option knowly_badge_svgs:
 *   { "{curriculum}:{subject}": "<svg>...</svg>", ... }
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Badge_Service {

    const TABLE_DEFS   = 'knowly_badge_definitions';
    const TABLE_AWARDS = 'knowly_badge_awards';

    // ── Quest Module Completion ───────────────────────────────────────────────

    /**
     * Check if the quest's module is fully completed and award badge if so.
     *
     * Called from Knowly_Quest_Service::complete() on every sub-topic completion.
     * Idempotency is enforced by the UNIQUE KEY on badge_awards — calling this
     * multiple times after all sub-topics are done only awards the badge once.
     *
     * @param  int    $child_id  WP user ID of the child
     * @param  string $quest_id  The completed sub-topic quest ID
     * @return array|null  Award data or null if module not yet complete / no badge defined.
     */
    public static function check_quest_module_completion( int $child_id, string $quest_id ): ?array {
        global $wpdb;

        $quest = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT module_number, module_title, subject, level, period, curriculum
                 FROM {$wpdb->prefix}knowly_quests
                 WHERE quest_id = %s AND variant = 'student'
                 LIMIT 1",
                $quest_id
            ),
            ARRAY_A
        );

        if ( ! $quest || $quest['module_number'] === null ) {
            return null;
        }

        $module_number = (int) $quest['module_number'];
        $subject       = $quest['subject'];
        $level         = $quest['level'];
        $period        = $quest['period'] ?: null;
        $curriculum    = $quest['curriculum'] ?: 'tt_primary';

        // Period clause — avoids passing NULL into a %s placeholder
        $period_sql = $period !== null
            ? $wpdb->prepare( 'AND period = %s', $period )
            : 'AND period IS NULL';

        // Count total approved sub-topics in this module
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quests
             WHERE variant = 'student' AND status = 'approved'
               AND curriculum = %s AND level = %s AND subject = %s AND module_number = %d
               {$period_sql}",
            $curriculum, $level, $subject, $module_number
        ) );

        if ( ! $total ) return null;

        // Count distinct sub-topics this child has at least one completed session for
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $completed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT q.quest_id)
             FROM {$wpdb->prefix}knowly_quests q
             JOIN {$wpdb->prefix}knowly_quest_sessions s ON q.quest_id = s.quest_id
             WHERE q.variant = 'student' AND q.status = 'approved'
               AND q.curriculum = %s AND q.level = %s AND q.subject = %s AND q.module_number = %d
               {$period_sql}
               AND s.child_id = %d AND s.state = 'completed'",
            $curriculum, $level, $subject, $module_number, $child_id
        ) );

        if ( $completed < $total ) return null;

        // Look up badge definition for this module
        $trigger_key = self::quest_trigger_key( $curriculum, $level, $period, $subject, $module_number );

        $definition = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE_DEFS . "
                 WHERE trigger_type = 'quest_module_completion' AND trigger_key = %s",
                $trigger_key
            ),
            ARRAY_A
        );

        if ( ! $definition ) return null;

        return self::award_by_definition( $child_id, (int) $definition['id'], $definition );
    }

    // ── Trial Milestones ──────────────────────────────────────────────────────

    /**
     * Check threshold-based trial badges after a trial submission.
     *
     * Finds all trial_count definitions for this subject/level whose threshold
     * has been reached and which the child has not yet received.
     *
     * @param  int    $child_id
     * @param  string $subject     e.g. 'math'
     * @param  string $level       e.g. 'std_4'
     * @param  string $curriculum
     * @return array  Newly awarded badge data (may be empty array)
     */
    public static function check_trial_milestones(
        int    $child_id,
        string $subject,
        string $level,
        string $curriculum = 'tt_primary'
    ): array {
        global $wpdb;

        $trial_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_sessions
             WHERE child_id = %d AND subject = %s AND level = %s AND state = 'completed'",
            $child_id, $subject, $level
        ) );

        $defs = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.* FROM {$wpdb->prefix}" . self::TABLE_DEFS . " d
             LEFT JOIN {$wpdb->prefix}" . self::TABLE_AWARDS . " a
                    ON a.definition_id = d.id AND a.child_id = %d
             WHERE d.trigger_type = 'trial_count'
               AND d.curriculum = %s AND d.level = %s AND d.subject = %s
               AND d.threshold <= %d
               AND a.id IS NULL
             ORDER BY d.threshold ASC",
            $child_id, $curriculum, $level, $subject, $trial_count
        ), ARRAY_A ) ?: [];

        $awarded = [];
        foreach ( $defs as $def ) {
            $result = self::award_by_definition( $child_id, (int) $def['id'], $def );
            if ( $result ) $awarded[] = $result;
        }
        return $awarded;
    }

    // ── Lesson Milestones ─────────────────────────────────────────────────────

    /**
     * Check threshold-based lesson badges after a lesson completion.
     *
     * @param  int    $child_id
     * @param  string $subject
     * @param  string $level
     * @param  string $curriculum
     * @return array  Newly awarded badge data (may be empty array)
     */
    public static function check_lesson_milestones(
        int    $child_id,
        string $subject,
        string $level,
        string $curriculum = 'tt_primary'
    ): array {
        global $wpdb;

        // Count completed lessons via join with quests (which carries subject/level)
        $lesson_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_lesson_sessions ls
             JOIN {$wpdb->prefix}knowly_quests q ON q.quest_id = ls.quest_id
             WHERE ls.child_id = %d AND ls.state = 'completed'
               AND q.subject = %s AND q.level = %s AND q.curriculum = %s",
            $child_id, $subject, $level, $curriculum
        ) );

        $defs = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.* FROM {$wpdb->prefix}" . self::TABLE_DEFS . " d
             LEFT JOIN {$wpdb->prefix}" . self::TABLE_AWARDS . " a
                    ON a.definition_id = d.id AND a.child_id = %d
             WHERE d.trigger_type = 'lesson_count'
               AND d.curriculum = %s AND d.level = %s AND d.subject = %s
               AND d.threshold <= %d
               AND a.id IS NULL
             ORDER BY d.threshold ASC",
            $child_id, $curriculum, $level, $subject, $lesson_count
        ), ARRAY_A ) ?: [];

        $awarded = [];
        foreach ( $defs as $def ) {
            $result = self::award_by_definition( $child_id, (int) $def['id'], $def );
            if ( $result ) $awarded[] = $result;
        }
        return $awarded;
    }

    // ── Award ─────────────────────────────────────────────────────────────────

    /**
     * Write a badge award. Idempotent — returns null if already awarded.
     *
     * @return array|null  Award data, or null if already awarded (not an error).
     */
    private static function award_by_definition( int $child_id, int $definition_id, array $definition ): ?array {
        global $wpdb;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . self::TABLE_AWARDS . "
             WHERE child_id = %d AND definition_id = %d",
            $child_id, $definition_id
        ) );
        if ( $existing ) return null;

        $share_token = self::generate_share_token();
        $now         = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $wpdb->prefix . self::TABLE_AWARDS,
            [
                'definition_id' => $definition_id,
                'child_id'      => $child_id,
                'share_token'   => $share_token,
                'awarded_at'    => $now,
            ],
            [ '%d', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) return null;

        $award_id = (int) $wpdb->insert_id;

        Knowly_Debug::log( 'badge.award', 'Badge awarded', [
            'child_id'      => $child_id,
            'definition_id' => $definition_id,
            'badge_name'    => $definition['name'],
            'award_id'      => $award_id,
        ], $child_id, 'info' );

        return self::format_award( array_merge( $definition, [
            'id'            => $award_id,
            'definition_id' => $definition_id,
            'child_id'      => $child_id,
            'share_token'   => $share_token,
            'awarded_at'    => $now,
        ] ) );
    }

    // ── Retrieval ─────────────────────────────────────────────────────────────

    /**
     * List all awards for a child with definition data joined.
     *
     * @return array  [{ id, definition_id, name, description, trigger_type, subject,
     *                   level, period, module_number, curriculum, svg_markup,
     *                   share_token, awarded_at }]
     */
    public static function get_awards( int $child_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.definition_id, a.share_token, a.awarded_at,
                    d.name, d.description, d.trigger_type, d.subject,
                    d.level, d.period, d.module_number, d.curriculum
             FROM {$wpdb->prefix}" . self::TABLE_AWARDS . " a
             JOIN  {$wpdb->prefix}" . self::TABLE_DEFS . " d ON d.id = a.definition_id
             WHERE a.child_id = %d
             ORDER BY a.awarded_at DESC",
            $child_id
        ), ARRAY_A ) ?: [];

        return array_map( [ __CLASS__, 'format_award' ], $rows );
    }

    /**
     * Get a single award by share token for the public badge share page.
     * Includes the child's nickname (never the real name).
     *
     * @return array|null
     */
    public static function get_award_by_token( string $share_token ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.definition_id, a.child_id, a.share_token, a.awarded_at,
                    d.name, d.description, d.trigger_type, d.subject,
                    d.level, d.period, d.module_number, d.curriculum
             FROM {$wpdb->prefix}" . self::TABLE_AWARDS . " a
             JOIN  {$wpdb->prefix}" . self::TABLE_DEFS . " d ON d.id = a.definition_id
             WHERE a.share_token = %s",
            $share_token
        ), ARRAY_A );

        if ( ! $row ) return null;

        $nickname = get_user_meta( (int) $row['child_id'], 'knowly_nickname', true ) ?: null;
        $formatted              = self::format_award( $row );
        $formatted['nickname']  = $nickname;

        // Never expose the WP user ID on the public endpoint
        unset( $formatted['child_id'] );

        return $formatted;
    }

    // ── Admin: Definitions ────────────────────────────────────────────────────

    /**
     * List badge definitions, optionally filtered.
     *
     * @param  array $filters  Optional: { trigger_type, subject, level, curriculum }
     * @return array
     */
    public static function get_definitions( array $filters = [] ): array {
        global $wpdb;

        $sql   = "SELECT * FROM {$wpdb->prefix}" . self::TABLE_DEFS;
        $args  = [];
        $where = [];

        if ( ! empty( $filters['trigger_type'] ) ) {
            $where[] = 'trigger_type = %s';
            $args[]  = $filters['trigger_type'];
        }
        if ( ! empty( $filters['subject'] ) ) {
            $where[] = 'subject = %s';
            $args[]  = $filters['subject'];
        }
        if ( ! empty( $filters['level'] ) ) {
            $where[] = 'level = %s';
            $args[]  = $filters['level'];
        }
        if ( ! empty( $filters['curriculum'] ) ) {
            $where[] = 'curriculum = %s';
            $args[]  = $filters['curriculum'];
        }

        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY level ASC, subject ASC, trigger_type ASC, threshold ASC';

        if ( $args ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: [];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Create or update a badge definition.
     *
     * @param  array $data  {
     *   id?            int     — omit to create
     *   name           string
     *   description?   string
     *   trigger_type   'quest_module_completion'|'trial_count'|'lesson_count'
     *   curriculum     string
     *   level          string
     *   period?        string|null
     *   subject        string
     *   module_number? int     — required for quest_module_completion
     *   threshold?     int     — required for trial_count and lesson_count
     * }
     * @return array|WP_Error
     */
    public static function save_definition( array $data ): array|WP_Error {
        global $wpdb;

        foreach ( [ 'name', 'trigger_type', 'curriculum', 'level', 'subject' ] as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return new WP_Error( 'knowly_missing_field', "Field '{$field}' is required.", [ 'status' => 400 ] );
            }
        }

        $trigger_type = $data['trigger_type'];
        if ( ! in_array( $trigger_type, [ 'quest_module_completion', 'trial_count', 'lesson_count' ], true ) ) {
            return new WP_Error( 'knowly_invalid', 'Invalid trigger_type.', [ 'status' => 400 ] );
        }
        if ( $trigger_type === 'quest_module_completion' && empty( $data['module_number'] ) ) {
            return new WP_Error( 'knowly_missing_field', 'module_number is required for quest_module_completion.', [ 'status' => 400 ] );
        }
        if ( in_array( $trigger_type, [ 'trial_count', 'lesson_count' ], true ) && empty( $data['threshold'] ) ) {
            return new WP_Error( 'knowly_missing_field', "threshold is required for {$trigger_type}.", [ 'status' => 400 ] );
        }

        $curriculum    = sanitize_key( $data['curriculum'] );
        $level         = sanitize_key( $data['level'] );
        $subject       = sanitize_key( $data['subject'] );
        $period        = ! empty( $data['period'] ) ? sanitize_key( $data['period'] ) : null;
        $module_number = ! empty( $data['module_number'] ) ? (int) $data['module_number'] : null;
        $threshold     = ! empty( $data['threshold'] )     ? (int) $data['threshold']     : null;

        if ( $trigger_type === 'quest_module_completion' ) {
            $trigger_key = self::quest_trigger_key( $curriculum, $level, $period, $subject, (int) $module_number );
        } else {
            $trigger_key = "{$curriculum}:{$level}:{$subject}:{$threshold}";
        }

        $now    = current_time( 'mysql', true );
        $row_id = ! empty( $data['id'] ) ? (int) $data['id'] : null;

        $row_data = [
            'name'          => sanitize_text_field( $data['name'] ),
            'description'   => ! empty( $data['description'] ) ? wp_kses_post( $data['description'] ) : null,
            'trigger_type'  => $trigger_type,
            'trigger_key'   => $trigger_key,
            'threshold'     => $threshold,
            'curriculum'    => $curriculum,
            'level'         => $level,
            'period'        => $period,
            'subject'       => $subject,
            'module_number' => $module_number,
            'ai_generated'  => ! empty( $data['ai_generated'] ) ? 1 : 0,
            'updated_at'    => $now,
        ];

        if ( $row_id ) {
            $wpdb->update(
                $wpdb->prefix . self::TABLE_DEFS,
                $row_data,
                [ 'id' => $row_id ],
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ],
                [ '%d' ]
            );
        } else {
            $row_data['created_at'] = $now;
            $wpdb->insert(
                $wpdb->prefix . self::TABLE_DEFS,
                $row_data,
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
            );
            $row_id = (int) $wpdb->insert_id;
        }

        if ( ! $row_id ) {
            return new WP_Error( 'knowly_db_error', 'Failed to save badge definition.', [ 'status' => 500 ] );
        }

        $saved = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::TABLE_DEFS . " WHERE id = %d", $row_id ),
            ARRAY_A
        );
        return $saved ?: [];
    }

    /**
     * Delete a badge definition. Associated awards are preserved (they become orphaned).
     *
     * @return bool
     */
    public static function delete_definition( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . self::TABLE_DEFS,
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    /**
     * Count how many children have earned a specific definition.
     */
    public static function count_awards_for_definition( int $definition_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_AWARDS . " WHERE definition_id = %d",
            $definition_id
        ) );
    }

    // ── SVG Helpers ───────────────────────────────────────────────────────────

    /**
     * Get the badge SVG markup for a subject.
     * Key format: "{curriculum}:{subject}" — e.g. "tt_primary:math".
     */
    public static function get_subject_svg( string $curriculum, string $subject ): ?string {
        $svgs = get_option( 'knowly_badge_svgs', [] );
        return $svgs[ "{$curriculum}:{$subject}" ] ?? null;
    }

    /**
     * Save the badge SVG markup for a subject.
     */
    public static function save_subject_svg( string $curriculum, string $subject, string $svg ): void {
        $svgs  = get_option( 'knowly_badge_svgs', [] );
        $key   = "{$curriculum}:{$subject}";
        $svgs[ $key ] = wp_kses( $svg, self::allowed_svg_tags() );
        update_option( 'knowly_badge_svgs', $svgs );
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function quest_trigger_key(
        string $curriculum,
        string $level,
        ?string $period,
        string $subject,
        int $module_number
    ): string {
        $p = $period ?: 'capstone';
        return "{$curriculum}:{$level}:{$p}:{$subject}:{$module_number}";
    }

    private static function generate_share_token(): string {
        return bin2hex( random_bytes( 16 ) ); // 32 hex chars
    }

    private static function format_award( array $row ): array {
        return [
            'id'            => (int) ( $row['id'] ?? 0 ),
            'definition_id' => (int) ( $row['definition_id'] ?? 0 ),
            'name'          => $row['name'] ?? '',
            'description'   => $row['description'] ?? null,
            'trigger_type'  => $row['trigger_type'] ?? '',
            'subject'       => $row['subject'] ?? '',
            'level'         => $row['level'] ?? '',
            'period'        => $row['period'] ?? null,
            'module_number' => isset( $row['module_number'] ) ? (int) $row['module_number'] : null,
            'curriculum'    => $row['curriculum'] ?? 'tt_primary',
            'share_token'   => $row['share_token'] ?? '',
            'awarded_at'    => $row['awarded_at'] ?? '',
            'svg_markup'    => self::get_subject_svg(
                $row['curriculum'] ?? 'tt_primary',
                $row['subject'] ?? ''
            ),
        ];
    }

    private static function allowed_svg_tags(): array {
        return [
            'svg'      => [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'class' => true, 'fill' => true, 'aria-hidden' => true, 'role' => true ],
            'g'        => [ 'fill' => true, 'stroke' => true, 'transform' => true, 'opacity' => true ],
            'path'     => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true, 'class' => true ],
            'circle'   => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'class' => true ],
            'rect'     => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true, 'stroke' => true, 'class' => true ],
            'polygon'  => [ 'points' => true, 'fill' => true, 'stroke' => true, 'class' => true ],
            'text'     => [ 'x' => true, 'y' => true, 'font-size' => true, 'font-family' => true, 'fill' => true, 'text-anchor' => true, 'class' => true ],
            'defs'     => [],
            'style'    => [ 'type' => true ],
        ];
    }
}
