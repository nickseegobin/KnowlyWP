<?php
/**
 * Knowly_Task_Service — Task management business logic.
 *
 * Teachers create tasks for their classes. Each task creation deducts
 * red gems from the teacher's wallet. Cost is read from the WP option
 * `knowly_task_gem_cost` (default 1) — never hardcoded.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Task_Service {

    // ── Cost Helper ───────────────────────────────────────────────────────────

    /**
     * Red gem cost to create one task (from WP options).
     */
    public static function get_task_cost(): int {
        return max( 1, (int) get_option( 'knowly_task_gem_cost', 1 ) );
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a task for a class, deducting red gems from the teacher.
     *
     * @param  int   $class_id
     * @param  int   $teacher_id
     * @param  array $data { title, description?, subject?, difficulty?, due_date? }
     * @return int|WP_Error  task_id on success.
     */
    public static function create( int $class_id, int $teacher_id, array $data ): int|WP_Error {
        global $wpdb;

        $title = sanitize_text_field( $data['title'] ?? '' );
        if ( ! $title ) {
            return new WP_Error( 'knowly_missing_fields', 'Task title is required.', [ 'status' => 422 ] );
        }

        // Verify teacher owns the class
        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $gem_cost = self::get_task_cost();

        // Check teacher red gem balance
        if ( ! Knowly_Red_Gem_Service::has_enough( $teacher_id, $gem_cost ) ) {
            return new WP_Error(
                'knowly_insufficient_red_gems',
                "Insufficient red gems. Task creation costs {$gem_cost} red gem(s).",
                [ 'status' => 402 ]
            );
        }

        // Validate optional fields
        $allowed_difficulty = [ 'easy', 'medium', 'hard', '' ];
        $difficulty = in_array( $data['difficulty'] ?? '', $allowed_difficulty, true )
            ? ( $data['difficulty'] ?: null )
            : null;

        $due_date = null;
        if ( ! empty( $data['due_date'] ) ) {
            $parsed = date_create( $data['due_date'] );
            $due_date = $parsed ? $parsed->format( 'Y-m-d' ) : null;
        }

        // Deduct red gems before inserting task — prevents tasks being created without payment
        $deducted = Knowly_Red_Gem_Service::deduct(
            $teacher_id,
            $gem_cost,
            'assignment_spent',
            "task_pending_{$class_id}"
        );

        if ( is_wp_error( $deducted ) ) {
            return $deducted;
        }

        // Insert task
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'knowly_tasks',
            [
                'class_id'        => $class_id,
                'teacher_user_id' => $teacher_id,
                'type'            => sanitize_text_field( $data['type'] ?? 'trial' ),
                'reference_id'    => sanitize_text_field( $data['reference_id'] ?? '' ) ?: null,
                'title'           => $title,
                'description'     => sanitize_textarea_field( $data['description'] ?? '' ) ?: null,
                'subject'         => sanitize_text_field( $data['subject'] ?? '' ) ?: null,
                'difficulty'      => $difficulty,
                'scope'           => in_array( $data['scope'] ?? '', [ 'period', 'general_topic' ], true ) ? $data['scope'] : null,
                'module_numbers'       => ! empty( $data['module_numbers'] ) ? wp_json_encode( array_map( 'intval', (array) $data['module_numbers'] ) ) : null,
                'lesson_section_index' => isset( $data['lesson_section_index'] ) && is_numeric( $data['lesson_section_index'] ) ? (int) $data['lesson_section_index'] : null,
                'due_date'             => $due_date,
                'gem_reward'      => isset( $data['gem_reward'] ) ? (int) $data['gem_reward'] : null,
                'red_gem_cost'    => $gem_cost,
                'status'          => 'active',
                'created_at'      => current_time( 'mysql', true ),
            ]
        );

        if ( $inserted === false ) {
            Knowly_Debug::log( 'task.create', 'DB insert failed after gem deduction', [ 'error' => $wpdb->last_error ], $teacher_id, 'error' );
            // Refund the deducted gems since task creation failed
            Knowly_Red_Gem_Service::credit( $teacher_id, $gem_cost, 'assignment_refund', "task_pending_{$class_id}" );
            return new WP_Error( 'knowly_db_error', 'Failed to create task.', [ 'status' => 500 ] );
        }

        $task_id = (int) $wpdb->insert_id;

        Knowly_Debug::log( 'task.create', 'Task created', [
            'task_id'  => $task_id,
            'class_id' => $class_id,
            'gem_cost' => $gem_cost,
        ], $teacher_id, 'info' );

        return $task_id;
    }

    // ── List for Class (Child view) ───────────────────────────────────────────

    /**
     * List active, non-expired tasks for a class — child-safe view.
     * Excludes tasks past their due_date and non-active tasks.
     * Annotates each task with `completed` (true if this child has a finished session for it).
     *
     * @param  int $class_id
     * @param  int $child_id  Used to derive per-student completion status.
     * @return array
     */
    public static function list_for_class_child( int $class_id, int $child_id = 0 ): array {
        global $wpdb;

        $today = current_time( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knowly_tasks
             WHERE class_id = %d
               AND status = 'active'
               AND (due_date IS NULL OR due_date >= %s)
             ORDER BY created_at DESC",
            $class_id,
            $today
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        // ── Derive per-student completion ─────────────────────────────────────
        // Two strategies are combined:
        //   A) task_id match  — reliable for sessions started after the task_id
        //                       column was added (v1.9.7).
        //   B) reference_id JOIN — fallback for older quest sessions that were
        //                       completed with source='assignment' before task_id
        //                       was stored. Trials cannot use this fallback safely
        //                       (subject is not unique across tasks).
        $completed_task_ids = [];

        if ( $child_id > 0 ) {
            $task_ids     = array_column( $rows, 'id' );
            $id_ph        = implode( ',', array_fill( 0, count( $task_ids ), '%d' ) );

            // ── A: task_id match (trials + quests) ────────────────────────────
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $trial_by_id = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT task_id FROM {$wpdb->prefix}knowly_exam_sessions
                 WHERE child_id = %d
                   AND state = 'completed'
                   AND task_id IN ({$id_ph})",
                array_merge( [ $child_id ], $task_ids )
            ) );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $quest_by_id = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT task_id FROM {$wpdb->prefix}knowly_quest_sessions
                 WHERE child_id = %d
                   AND state = 'completed'
                   AND task_id IN ({$id_ph})",
                array_merge( [ $child_id ], $task_ids )
            ) );

            // ── B: reference_id JOIN fallback for older quest sessions ─────────
            // Matches knowly_tasks.id for any quest task whose reference_id has a
            // completed 'assignment' session for this child (even with NULL task_id).
            // The JOIN handles the matching — no IN (reference_ids) filter needed.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $quest_by_ref = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT t.id
                 FROM {$wpdb->prefix}knowly_tasks t
                 INNER JOIN {$wpdb->prefix}knowly_quest_sessions qs
                         ON qs.quest_id = t.reference_id
                 WHERE qs.child_id    = %d
                   AND qs.state       = 'completed'
                   AND qs.source      = 'assignment'
                   AND t.class_id     = %d
                   AND t.reference_id IS NOT NULL",
                $child_id,
                $class_id
            ) );

            // ── C: reference_id JOIN fallback for older lesson sessions ──────────
            // Handles lesson_sessions created before the task_id column was added (v2.1.0).
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $lesson_by_ref = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT t.id
                 FROM {$wpdb->prefix}knowly_tasks t
                 INNER JOIN {$wpdb->prefix}knowly_lesson_sessions ls
                         ON ls.quest_id = t.reference_id
                 WHERE ls.child_id    = %d
                   AND ls.state       = 'completed'
                   AND ls.source      = 'assignment'
                   AND ls.task_id     IS NULL
                   AND t.class_id     = %d
                   AND t.type         = 'lesson'
                   AND t.reference_id IS NOT NULL",
                $child_id,
                $class_id
            ) );

            // ── D: task_id match for lesson sessions (v2.1.0+) ───────────────
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $lesson_by_id = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT task_id FROM {$wpdb->prefix}knowly_lesson_sessions
                 WHERE child_id = %d
                   AND state    = 'completed'
                   AND task_id  IN ({$id_ph})",
                array_merge( [ $child_id ], $task_ids )
            ) );

            $completed_task_ids = array_flip(
                array_merge(
                    array_map( 'intval', $trial_by_id   ?: [] ),
                    array_map( 'intval', $quest_by_id   ?: [] ),
                    array_map( 'intval', $quest_by_ref  ?: [] ),
                    array_map( 'intval', $lesson_by_ref ?: [] ),
                    array_map( 'intval', $lesson_by_id  ?: [] )
                )
            );
        }

        return array_map(
            fn( $row ) => array_merge(
                self::format_task( $row ),
                [ 'completed' => isset( $completed_task_ids[ (int) $row['id'] ] ) ]
            ),
            $rows
        );
    }

    // ── List for Class ────────────────────────────────────────────────────────

    /**
     * List all tasks for a class, ordered by creation date descending.
     *
     * @return array
     */
    public static function list_for_class( int $class_id, bool $active_only = true ): array {
        global $wpdb;

        $where = $active_only
            ? $wpdb->prepare( "WHERE class_id = %d AND status = 'active'", $class_id )
            : $wpdb->prepare( "WHERE class_id = %d", $class_id );

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}knowly_tasks {$where} ORDER BY created_at DESC",
            ARRAY_A
        );

        return array_map( [ __CLASS__, 'format_task' ], $rows ?: [] );
    }

    // ── Task Detail + Per-Student Completions (Teacher view) ─────────────────

    /**
     * Fetch a single task with per-student completion status for the teacher.
     *
     * @param  int $task_id
     * @param  int $class_id
     * @return array|WP_Error  { task, completions[], stats }
     */
    public static function get_with_completions( int $task_id, int $class_id ): array|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}knowly_tasks WHERE id = %d AND class_id = %d",
                $task_id,
                $class_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Task not found.', [ 'status' => 404 ] );
        }

        $task = self::format_task( $row );
        $type = $row['type'];
        $ref  = $row['reference_id'];

        // Fetch all active class members via the class service
        $members = Knowly_Class_Service::get_members( $class_id );

        if ( empty( $members ) ) {
            return [ 'task' => $task, 'completions' => [], 'stats' => [ 'total' => 0, 'completed' => 0 ] ];
        }

        $child_ids = array_map( 'intval', array_column( $members, 'child_id' ) );
        $id_ph     = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );

        // Build map: child_id → completed_at (null if not completed)
        $completion_map = [];

        if ( $type === 'trial' ) {
            $done = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT child_id, completed_at FROM {$wpdb->prefix}knowly_exam_sessions
                     WHERE task_id = %d AND state = 'completed'",
                    $task_id
                ),
                ARRAY_A
            );
            foreach ( $done ?: [] as $r ) {
                $completion_map[ (int) $r['child_id'] ] = $r['completed_at'];
            }

        } elseif ( $type === 'quest' ) {
            $done = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT child_id, completed_at FROM {$wpdb->prefix}knowly_quest_sessions
                     WHERE task_id = %d AND state = 'completed'",
                    $task_id
                ),
                ARRAY_A
            );
            foreach ( $done ?: [] as $r ) {
                $completion_map[ (int) $r['child_id'] ] = $r['completed_at'];
            }
            // Fallback: older sessions without task_id, matched by reference_id + source
            if ( $ref ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $fallback = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT child_id, completed_at FROM {$wpdb->prefix}knowly_quest_sessions
                         WHERE quest_id = %s AND source = 'assignment' AND state = 'completed'
                           AND task_id IS NULL AND child_id IN ({$id_ph})",
                        array_merge( [ $ref ], $child_ids )
                    ),
                    ARRAY_A
                );
                foreach ( $fallback ?: [] as $r ) {
                    $cid = (int) $r['child_id'];
                    if ( ! isset( $completion_map[ $cid ] ) ) {
                        $completion_map[ $cid ] = $r['completed_at'];
                    }
                }
            }

        } elseif ( $type === 'lesson' && $ref ) {
            // Lesson sessions tracked by reference_id + source='assignment'
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $done = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT child_id, completed_at FROM {$wpdb->prefix}knowly_lesson_sessions
                     WHERE quest_id = %s AND source = 'assignment' AND state = 'completed'
                       AND child_id IN ({$id_ph})",
                    array_merge( [ $ref ], $child_ids )
                ),
                ARRAY_A
            );
            foreach ( $done ?: [] as $r ) {
                $completion_map[ (int) $r['child_id'] ] = $r['completed_at'];
            }
        }

        // Build completions array — completed students first, then alphabetically
        $completions = array_map( function ( $member ) use ( $completion_map ) {
            $cid       = (int) $member['child_id'];
            $completed = isset( $completion_map[ $cid ] );
            return [
                'child_id'     => $cid,
                'nickname'     => $member['nickname'],
                'level'        => $member['level'] ?? null,
                'completed'    => $completed,
                'completed_at' => $completed ? $completion_map[ $cid ] : null,
            ];
        }, $members );

        usort( $completions, function ( $a, $b ) {
            if ( $a['completed'] !== $b['completed'] ) {
                return $a['completed'] ? -1 : 1;
            }
            return strcmp( $a['nickname'], $b['nickname'] );
        } );

        $done_count = count( array_filter( $completions, fn( $c ) => $c['completed'] ) );

        return [
            'task'        => $task,
            'completions' => $completions,
            'stats'       => [
                'total'     => count( $completions ),
                'completed' => $done_count,
            ],
        ];
    }

    public static function delete( int $task_id, int $class_id, int $teacher_id ): bool|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, class_id, teacher_user_id FROM {$wpdb->prefix}knowly_tasks WHERE id = %d",
                $task_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Task not found.', [ 'status' => 404 ] );
        }

        if ( (int) $row['class_id'] !== $class_id ) {
            return new WP_Error( 'knowly_forbidden', 'Task does not belong to this class.', [ 'status' => 403 ] );
        }

        if ( (int) $row['teacher_user_id'] !== $teacher_id ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this task.', [ 'status' => 403 ] );
        }

        $deleted = $wpdb->delete( $wpdb->prefix . 'knowly_tasks', [ 'id' => $task_id ], [ '%d' ] );

        return $deleted !== false;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function format_task( array $row ): array {
        $module_numbers = null;
        if ( ! empty( $row['module_numbers'] ) ) {
            $decoded = json_decode( $row['module_numbers'], true );
            $module_numbers = is_array( $decoded ) ? array_map( 'intval', $decoded ) : null;
        }

        return [
            'id'              => (int) $row['id'],
            'class_id'        => (int) $row['class_id'],
            'teacher_user_id' => (int) $row['teacher_user_id'],
            'type'            => $row['type'],
            'reference_id'    => $row['reference_id'],
            'title'           => $row['title'],
            'description'     => $row['description'],
            'subject'         => $row['subject'],
            'difficulty'      => $row['difficulty'],
            'scope'           => $row['scope'] ?? null,
            'module_numbers'       => $module_numbers,
            'lesson_section_index' => isset( $row['lesson_section_index'] ) && $row['lesson_section_index'] !== null ? (int) $row['lesson_section_index'] : null,
            'due_date'             => $row['due_date'],
            'gem_reward'      => isset( $row['gem_reward'] ) ? (int) $row['gem_reward'] : null,
            'red_gem_cost'    => (int) $row['red_gem_cost'],
            'status'          => $row['status'],
            'created_at'      => $row['created_at'],
        ];
    }
}
