<?php
/**
 * Knowly_Class_Service — Class management business logic.
 *
 * Handles class creation, child lookup by nickname, invite flow
 * (dual notifications: simple to child, confirmation to parent),
 * membership management, and listing.
 *
 * Invite flow:
 *   1. Teacher calls invite($class_id, $teacher_id, $child_nickname)
 *   2. Service resolves child + parent from nickname
 *   3. Creates simple notification → child
 *   4. Creates confirmation notification → parent  (subject: class_invitation)
 *   5. When parent accepts via POST /notifications/{id}/respond, Knowly_Notifications_API
 *      calls Knowly_Class_Service::add_member() using the notification payload.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Class_Service {

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new class.
     *
     * @param  int   $teacher_id
     * @param  array $data { name, description?, level? }
     * @return int|WP_Error  class_id on success.
     */
    public static function create( int $teacher_id, array $data ): int|WP_Error {
        global $wpdb;

        $name = sanitize_text_field( $data['name'] ?? '' );
        if ( ! $name ) {
            return new WP_Error( 'knowly_missing_fields', 'Class name is required.', [ 'status' => 422 ] );
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'knowly_classes',
            [
                'teacher_user_id' => $teacher_id,
                'name'            => $name,
                'description'     => sanitize_textarea_field( $data['description'] ?? '' ) ?: null,
                'level'           => sanitize_text_field( $data['level'] ?? '' ),
                'created_at'      => current_time( 'mysql', true ),
            ]
        );

        if ( $inserted === false ) {
            Knowly_Debug::log( 'class.create', 'DB insert failed', [ 'error' => $wpdb->last_error ], $teacher_id, 'error' );
            return new WP_Error( 'knowly_db_error', 'Failed to create class.', [ 'status' => 500 ] );
        }

        $class_id = (int) $wpdb->insert_id;

        Knowly_Debug::log( 'class.create', 'Class created', [ 'class_id' => $class_id, 'name' => $name ], $teacher_id, 'info' );
        return $class_id;
    }

    // ── Get ───────────────────────────────────────────────────────────────────

    /**
     * Get a single class by ID.
     *
     * @return array|WP_Error
     */
    public static function get( int $class_id ): array|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knowly_classes WHERE id = %d",
            $class_id
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Class not found.', [ 'status' => 404 ] );
        }

        return self::format_class( $row );
    }

    // ── List for Teacher ──────────────────────────────────────────────────────

    /**
     * List all classes created by a teacher, with member and task counts.
     *
     * @return array
     */
    public static function list_for_teacher( int $teacher_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members m WHERE m.class_id = c.id AND m.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks t WHERE t.class_id = c.id) AS task_count
             FROM {$wpdb->prefix}knowly_classes c
             WHERE c.teacher_user_id = %d
             ORDER BY c.created_at DESC",
            $teacher_id
        ), ARRAY_A );

        return array_map( [ __CLASS__, 'format_class' ], $rows ?: [] );
    }

    // ── List for Child ────────────────────────────────────────────────────────

    /**
     * List all active classes a child is enrolled in.
     *
     * @return array
     */
    public static function list_for_child( int $child_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, m.joined_at
             FROM {$wpdb->prefix}knowly_classes c
             INNER JOIN {$wpdb->prefix}knowly_class_members m ON m.class_id = c.id
             WHERE m.child_id = %d AND m.status = 'active'
             ORDER BY m.joined_at DESC",
            $child_id
        ), ARRAY_A );

        return array_map( [ __CLASS__, 'format_class' ], $rows ?: [] );
    }

    // ── Members ───────────────────────────────────────────────────────────────

    /**
     * Get active members of a class.
     *
     * @return array
     */
    public static function get_members( int $class_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.child_id, m.parent_id, m.joined_at
             FROM {$wpdb->prefix}knowly_class_members m
             WHERE m.class_id = %d AND m.status = 'active'
             ORDER BY m.joined_at ASC",
            $class_id
        ), ARRAY_A );

        return array_map( function ( array $row ): array {
            $child = get_userdata( (int) $row['child_id'] );
            return [
                'child_id'    => (int) $row['child_id'],
                'parent_id'   => (int) $row['parent_id'],
                'nickname'    => get_user_meta( (int) $row['child_id'], 'knowly_nickname', true ) ?: ( $child ? $child->display_name : '' ),
                'level'       => get_user_meta( (int) $row['child_id'], 'knowly_level', true ),
                'joined_at'   => $row['joined_at'],
            ];
        }, $rows ?: [] );
    }

    // ── Child Lookup by Nickname ──────────────────────────────────────────────

    /**
     * Find a child user by their public nickname (knowly_nickname user meta).
     *
     * @param  string $nickname  Case-insensitive.
     * @return array|WP_Error    { child_id, nickname, display_name, level, period, parent_id }
     */
    public static function find_child_by_nickname( string $nickname ): array|WP_Error {
        global $wpdb;

        $nickname = sanitize_text_field( $nickname );
        if ( ! $nickname ) {
            return new WP_Error( 'knowly_missing_fields', 'Nickname is required.', [ 'status' => 422 ] );
        }

        // Search knowly_nickname user meta (case-insensitive)
        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'knowly_nickname' AND LOWER(meta_value) = LOWER(%s)
             LIMIT 1",
            $nickname
        ) );

        if ( ! $user_id ) {
            return new WP_Error( 'knowly_not_found', 'No student found with that nickname.', [ 'status' => 404 ] );
        }

        $user      = get_userdata( (int) $user_id );
        $parent_id = (int) get_user_meta( (int) $user_id, 'knowly_parent_id', true );

        if ( ! $user || ! in_array( 'knowly_child', (array) $user->roles, true ) ) {
            return new WP_Error( 'knowly_not_found', 'No student found with that nickname.', [ 'status' => 404 ] );
        }

        if ( ! $parent_id ) {
            return new WP_Error( 'knowly_no_parent', 'Student has no linked parent account.', [ 'status' => 422 ] );
        }

        return [
            'child_id'     => (int) $user_id,
            'nickname'     => get_user_meta( (int) $user_id, 'knowly_nickname', true ),
            'display_name' => $user->display_name,
            'level'        => get_user_meta( (int) $user_id, 'knowly_level', true ),
            'period'       => get_user_meta( (int) $user_id, 'knowly_period', true ),
            'parent_id'    => $parent_id,
        ];
    }

    // ── Invite ────────────────────────────────────────────────────────────────

    /**
     * Invite a child to a class.
     *
     * Creates:
     *   - Simple notification → child  (non-actionable: "You've been invited to…")
     *   - Confirmation notification → parent  (actionable: accept/decline, payload contains class_id + child_id)
     *
     * @param  int    $class_id
     * @param  int    $teacher_id
     * @param  string $child_nickname
     * @return array|WP_Error  { child_notif_id, parent_notif_id }
     */
    public static function invite( int $class_id, int $teacher_id, string $child_nickname ): array|WP_Error {
        // Resolve child
        $child = self::find_child_by_nickname( $child_nickname );
        if ( is_wp_error( $child ) ) return $child;

        // Resolve class
        $class = self::get( $class_id );
        if ( is_wp_error( $class ) ) return $class;

        // Guard: teacher must own the class
        if ( (int) $class['teacher_user_id'] !== $teacher_id ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        // Guard: child not already an active member
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND child_id = %d AND status = 'active'",
            $class_id, $child['child_id']
        ) );
        if ( $existing ) {
            return new WP_Error( 'knowly_already_member', 'This student is already a member of the class.', [ 'status' => 409 ] );
        }

        $teacher      = get_userdata( $teacher_id );
        $teacher_name = $teacher ? trim( $teacher->first_name . ' ' . $teacher->last_name ) : 'Teacher';
        $class_name   = $class['name'];
        $child_name   = $child['nickname'] ?: $child['display_name'];
        $parent_id    = $child['parent_id'];
        $child_id     = $child['child_id'];

        $payload = [
            'class_id'     => $class_id,
            'class_name'   => $class_name,
            'teacher_id'   => $teacher_id,
            'teacher_name' => $teacher_name,
            'child_id'     => $child_id,
            'child_name'   => $child_name,
        ];

        // Notification 1: simple → child
        $child_notif_id = Knowly_Notification_Service::create( [
            'recipient_user_id' => $child_id,
            'sender_user_id'    => $teacher_id,
            'type'              => 'simple',
            'subject'           => 'class_invitation',
            'message'           => "{$teacher_name} has invited you to join the class \"{$class_name}\".",
            'payload'           => $payload,
        ] );

        if ( is_wp_error( $child_notif_id ) ) return $child_notif_id;

        // Notification 2: confirmation → parent
        $parent_notif_id = Knowly_Notification_Service::create( [
            'recipient_user_id' => $parent_id,
            'sender_user_id'    => $teacher_id,
            'type'              => 'confirmation',
            'subject'           => 'class_invitation',
            'message'           => "{$teacher_name} has invited {$child_name} to join the class \"{$class_name}\". Accept or decline?",
            'payload'           => $payload,
        ] );

        if ( is_wp_error( $parent_notif_id ) ) return $parent_notif_id;

        Knowly_Debug::log( 'class.invite', 'Class invitation sent', [
            'class_id'       => $class_id,
            'child_id'       => $child_id,
            'parent_id'      => $parent_id,
            'child_notif_id' => $child_notif_id,
            'parent_notif_id' => $parent_notif_id,
        ], $teacher_id, 'info' );

        return [
            'child_notif_id'  => $child_notif_id,
            'parent_notif_id' => $parent_notif_id,
            'child_id'        => $child_id,
            'parent_id'       => $parent_id,
        ];
    }

    // ── Add Member ────────────────────────────────────────────────────────────

    /**
     * Add a child as an active member of a class.
     * Called automatically when a parent accepts a class_invitation notification.
     *
     * @return true|WP_Error
     */
    public static function add_member( int $class_id, int $child_id, int $parent_id ): true|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'knowly_class_members';

        // If a removed record exists, reactivate it
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE class_id = %d AND child_id = %d",
            $class_id, $child_id
        ) );

        if ( $existing ) {
            if ( $existing->status === 'active' ) {
                return true; // Already a member — idempotent
            }
            $wpdb->update( $table, [ 'status' => 'active', 'joined_at' => current_time( 'mysql', true ) ], [ 'id' => $existing->id ] );
        } else {
            $wpdb->insert( $table, [
                'class_id'  => $class_id,
                'child_id'  => $child_id,
                'parent_id' => $parent_id,
                'status'    => 'active',
                'joined_at' => current_time( 'mysql', true ),
            ] );
        }

        Knowly_Debug::log( 'class.add_member', 'Child added to class', [
            'class_id'  => $class_id,
            'child_id'  => $child_id,
            'parent_id' => $parent_id,
        ], $parent_id, 'info' );

        return true;
    }

    // ── Remove Member ─────────────────────────────────────────────────────────

    /**
     * Remove a child from a class (soft delete — status = removed).
     * Only the class teacher may remove a member.
     *
     * @return true|WP_Error
     */
    public static function remove_member( int $class_id, int $child_id, int $teacher_id ): true|WP_Error {
        $class = self::get( $class_id );
        if ( is_wp_error( $class ) ) return $class;

        if ( (int) $class['teacher_user_id'] !== $teacher_id ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'knowly_class_members',
            [ 'status' => 'removed' ],
            [ 'class_id' => $class_id, 'child_id' => $child_id, 'status' => 'active' ]
        );

        return true;
    }

    // ── Ownership Guard ───────────────────────────────────────────────────────

    /**
     * Verify the given teacher owns the given class.
     */
    public static function teacher_owns( int $class_id, int $teacher_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE id = %d AND teacher_user_id = %d",
            $class_id, $teacher_id
        ) );
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function format_class( array $row ): array {
        return [
            'id'              => (int) $row['id'],
            'teacher_user_id' => (int) $row['teacher_user_id'],
            'name'            => $row['name'],
            'description'     => $row['description'],
            'level'           => $row['level'],
            'member_count'    => isset( $row['member_count'] ) ? (int) $row['member_count'] : null,
            'task_count'      => isset( $row['task_count'] )   ? (int) $row['task_count']   : null,
            'joined_at'       => $row['joined_at'] ?? null,
            'created_at'      => $row['created_at'],
        ];
    }
}
