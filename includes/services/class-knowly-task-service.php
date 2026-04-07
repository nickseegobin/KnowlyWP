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
            Knowly_Debug::log( 'task.create', 'DB insert failed', [ 'error' => $wpdb->last_error ], $teacher_id, 'error' );
            return new WP_Error( 'knowly_db_error', 'Failed to create task.', [ 'status' => 500 ] );
        }

        $task_id = (int) $wpdb->insert_id;

        // Deduct red gems from teacher
        $deducted = Knowly_Red_Gem_Service::deduct(
            $teacher_id,
            $gem_cost,
            'assignment_spent',
            "task_{$task_id}"
        );

        if ( is_wp_error( $deducted ) ) {
            // Task is already created — log error but don't roll back
            Knowly_Debug::log( 'task.create', 'Red gem deduction failed after task insert', [
                'task_id'  => $task_id,
                'error'    => $deducted->get_error_message(),
            ], $teacher_id, 'error' );
        }

        Knowly_Debug::log( 'task.create', 'Task created', [
            'task_id'  => $task_id,
            'class_id' => $class_id,
            'gem_cost' => $gem_cost,
        ], $teacher_id, 'info' );

        return $task_id;
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
