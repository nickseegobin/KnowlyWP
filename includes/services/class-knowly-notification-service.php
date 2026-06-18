<?php
/**
 * Knowly_Notification_Service — Notification business logic.
 *
 * Two notification types:
 *   simple        — informational, no action required
 *   confirmation  — requires accepted | declined from the parent
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Notification_Service {

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a notification.
     *
     * @param  array $data {
     *   recipient_user_id  int     required
     *   sender_user_id     int     optional
     *   type               string  simple | confirmation
     *   subject            string  e.g. class_invitation
     *   message            string  human-readable
     *   payload            array   optional JSON payload
     * }
     * @return int|WP_Error  Notification ID on success.
     */
    public static function create( array $data ): int|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'knowly_notifications';

        $recipient = (int) ( $data['recipient_user_id'] ?? 0 );
        if ( ! $recipient ) {
            return new WP_Error( 'knowly_missing_fields', 'recipient_user_id is required.', [ 'status' => 422 ] );
        }

        $type    = in_array( $data['type'] ?? '', [ 'simple', 'confirmation' ], true ) ? $data['type'] : 'simple';
        $payload = ! empty( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null;

        $inserted = $wpdb->insert( $table, [
            'recipient_user_id' => $recipient,
            'sender_user_id'    => ! empty( $data['sender_user_id'] ) ? (int) $data['sender_user_id'] : null,
            'type'              => $type,
            'subject'           => sanitize_text_field( $data['subject'] ?? '' ),
            'message'           => sanitize_textarea_field( $data['message'] ?? '' ),
            'payload'           => $payload,
            'is_read'           => 0,
            'created_at'        => current_time( 'mysql', true ),
        ] );

        if ( $inserted === false ) {
            Knowly_Debug::log( 'notification.create', 'DB insert failed', [
                'error' => $wpdb->last_error,
            ], null, 'error' );
            return new WP_Error( 'knowly_db_error', 'Failed to create notification.', [ 'status' => 500 ] );
        }

        return (int) $wpdb->insert_id;
    }

    // ── Get Single ───────────────────────────────────────────────────────────

    /**
     * Fetch a single notification by ID, scoped to the given recipient.
     *
     * @return array|WP_Error  Formatted notification row.
     */
    public static function get( int $notification_id, int $user_id ): array|WP_Error {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knowly_notifications WHERE id = %d AND recipient_user_id = %d",
            $notification_id, $user_id
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Notification not found.', [ 'status' => 404 ] );
        }

        return self::format_row( $row );
    }

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * Fetch notifications for a user.
     *
     * @param  int   $user_id
     * @param  bool  $unread_only  Default true — only unread.
     * @return array
     */
    public static function list_for_user( int $user_id, bool $unread_only = true, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'knowly_notifications';

        if ( $unread_only ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE recipient_user_id = %d AND is_read = 0 ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $user_id, $limit, $offset
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE recipient_user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $user_id, $limit, $offset
            ), ARRAY_A );
        }

        return array_map( [ __CLASS__, 'format_row' ], $rows ?: [] );
    }

    // ── Count Unread ──────────────────────────────────────────────────────────

    public static function count_unread( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_notifications WHERE recipient_user_id = %d AND is_read = 0",
            $user_id
        ) );
    }

    // ── Mark All Read ─────────────────────────────────────────────────────────

    /**
     * Mark all unread notifications as read for a user.
     *
     * @return int  Number of rows updated.
     */
    public static function mark_all_read( int $user_id ): int {
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'knowly_notifications',
            [ 'is_read' => 1 ],
            [ 'recipient_user_id' => $user_id, 'is_read' => 0 ],
            [ '%d' ],
            [ '%d', '%d' ]
        );
        return (int) $updated;
    }

    // ── Respond ───────────────────────────────────────────────────────────────

    /**
     * Record a response on a confirmation notification.
     *
     * @param  int    $notification_id
     * @param  int    $user_id          Must be the recipient.
     * @param  string $response         accepted | declined
     * @return true|WP_Error
     */
    public static function respond( int $notification_id, int $user_id, string $response ): true|WP_Error {
        global $wpdb;

        if ( ! in_array( $response, [ 'accepted', 'declined' ], true ) ) {
            return new WP_Error( 'knowly_invalid_response', 'Response must be accepted or declined.', [ 'status' => 422 ] );
        }

        $table = $wpdb->prefix . 'knowly_notifications';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND recipient_user_id = %d",
            $notification_id, $user_id
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Notification not found.', [ 'status' => 404 ] );
        }

        if ( $row['type'] !== 'confirmation' ) {
            return new WP_Error( 'knowly_invalid_type', 'Only confirmation notifications can be responded to.', [ 'status' => 422 ] );
        }

        if ( $row['response'] !== null ) {
            return new WP_Error( 'knowly_already_responded', 'This notification has already been responded to.', [ 'status' => 409 ] );
        }

        $wpdb->update( $table, [
            'response'     => $response,
            'is_read'      => 1,
            'responded_at' => current_time( 'mysql', true ),
        ], [ 'id' => $notification_id ] );

        Knowly_Debug::log( 'notification.respond', 'Notification responded', [
            'id'       => $notification_id,
            'user_id'  => $user_id,
            'response' => $response,
        ], $user_id, 'info' );

        return true;
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    /**
     * Delete a notification, scoped to the recipient (user can only delete their own).
     *
     * @param  int $notification_id
     * @param  int $user_id          Must be the recipient.
     * @return true|WP_Error
     */
    public static function delete( int $notification_id, int $user_id ): true|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'knowly_notifications';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND recipient_user_id = %d",
            $notification_id, $user_id
        ) );

        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Notification not found.', [ 'status' => 404 ] );
        }

        $wpdb->delete( $table, [ 'id' => $notification_id ], [ '%d' ] );

        return true;
    }

    /**
     * Admin-only hard delete (no recipient check).
     *
     * @param  int $notification_id
     * @return bool
     */
    public static function admin_delete( int $notification_id ): bool {
        global $wpdb;
        $deleted = $wpdb->delete( $wpdb->prefix . 'knowly_notifications', [ 'id' => $notification_id ], [ '%d' ] );
        return $deleted !== false && $deleted > 0;
    }

    // ── Mark Read ─────────────────────────────────────────────────────────────

    /**
     * Mark a notification as read.
     *
     * @param  int $notification_id
     * @param  int $user_id
     * @return true|WP_Error
     */
    public static function mark_read( int $notification_id, int $user_id ): true|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'knowly_notifications';

        $updated = $wpdb->update(
            $table,
            [ 'is_read' => 1 ],
            [ 'id' => $notification_id, 'recipient_user_id' => $user_id ]
        );

        if ( $updated === false ) {
            return new WP_Error( 'knowly_db_error', 'Failed to mark notification as read.', [ 'status' => 500 ] );
        }

        return true;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function format_row( array $row ): array {
        return [
            'id'                => (int) $row['id'],
            'recipient_user_id' => (int) $row['recipient_user_id'],
            'sender_user_id'    => $row['sender_user_id'] ? (int) $row['sender_user_id'] : null,
            'type'              => $row['type'],
            'subject'           => $row['subject'],
            'message'           => $row['message'],
            'payload'           => $row['payload'] ? json_decode( $row['payload'], true ) : null,
            'response'          => $row['response'],
            'is_read'           => (bool) $row['is_read'],
            'responded_at'      => $row['responded_at'],
            'created_at'        => $row['created_at'],
        ];
    }
}
