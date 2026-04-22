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
                'due_date'        => $due_date,
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
            $ref_ids = array_filter( array_column( $rows, 'reference_id' ) );
            $quest_by_ref = [];
            if ( ! empty( $ref_ids ) ) {
                $ref_ph = implode( ',', array_fill( 0, count( $ref_ids ), '%s' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $quest_by_ref = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT t.id
                     FROM {$wpdb->prefix}knowly_tasks t
                     JOIN {$wpdb->prefix}knowly_quest_sessions qs
                          ON qs.quest_id = t.reference_id
                     WHERE qs.child_id = %d
                       AND qs.state    = 'completed'
                       AND qs.source   = 'assignment'
                       AND t.id        IN ({$id_ph})
                       AND t.reference_id IN ({$ref_ph})",
                    array_merge( [ $child_id ], $task_ids, $ref_ids )
                ) );
            }

            $completed_task_ids = array_flip(
                array_merge(
                    array_map( 'intval', $trial_by_id  ?: [] ),
                    array_map( 'intval', $quest_by_id  ?: [] ),
                    array_map( 'intval', $quest_by_ref ?: [] )
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

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function format_task( array $row ): array {
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
            'due_date'        => $row['due_date'],
            'gem_reward'      => isset( $row['gem_reward'] ) ? (int) $row['gem_reward'] : null,
            'red_gem_cost'    => (int) $row['red_gem_cost'],
            'status'          => $row['status'],
            'created_at'      => $row['created_at'],
        ];
    }
}
