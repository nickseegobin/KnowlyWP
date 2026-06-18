<?php
/**
 * Knowly_Notifications_API — Notification endpoints.
 *
 * Routes:
 *   GET  /knowly/v1/notifications                JWT  List notifications for the authenticated user
 *   GET  /knowly/v1/notifications/count          JWT  Unread count only (for badge)
 *   POST /knowly/v1/notifications                Admin Create a notification
 *   POST /knowly/v1/notifications/{id}/read      JWT  Mark one notification as read
 *   POST /knowly/v1/notifications/read-all       JWT  Mark all notifications as read
 *   POST   /knowly/v1/notifications/{id}/respond   JWT  Accept or decline a confirmation notification
 *   DELETE /knowly/v1/notifications/{id}          JWT  Delete a notification (recipient only)
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Notifications_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/notifications', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'unread_only' => [ 'default' => true,   'type' => 'boolean' ],
                    'limit'       => [ 'default' => 50,     'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                    'offset'      => [ 'default' => 0,      'type' => 'integer', 'minimum' => 0 ],
                    'scope'       => [ 'default' => 'self', 'type' => 'string',  'enum' => [ 'self', 'child' ] ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'recipient_user_id' => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                    'type'              => [ 'required' => false, 'type' => 'string',  'enum' => [ 'simple', 'confirmation' ], 'default' => 'simple' ],
                    'subject'           => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'message'           => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
                    'payload'           => [ 'required' => false, 'type' => 'object' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/notifications/count', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'count' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'scope' => [ 'default' => 'self', 'type' => 'string', 'enum' => [ 'self', 'child' ] ],
            ],
        ] );

        register_rest_route( $ns, '/notifications/read-all', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'read_all' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/notifications/(?P<id>\d+)/read', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_read' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'    => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                'scope' => [ 'required' => false, 'type' => 'string',  'enum' => [ 'self', 'child' ], 'default' => 'self' ],
            ],
        ] );

        register_rest_route( $ns, '/notifications/(?P<id>\d+)/respond', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'respond' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'       => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'response' => [ 'required' => true, 'type' => 'string',  'enum' => [ 'accepted', 'declined' ] ],
            ],
        ] );

        register_rest_route( $ns, '/notifications/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'destroy' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'    => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                'scope' => [ 'required' => false, 'type' => 'string',  'enum' => [ 'self', 'child' ], 'default' => 'self' ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        // scope=child resolves to the active child so siblings stay isolated
        if ( $request->get_param( 'scope' ) === 'child' ) {
            $ctx = $this->require_child_context( $request );
            if ( is_wp_error( $ctx ) ) return $ctx;
            $user_id = $ctx['child_id'];
        }

        $unread_only = (bool) $request->get_param( 'unread_only' );
        $limit       = (int)  $request->get_param( 'limit' );
        $offset      = (int)  $request->get_param( 'offset' );

        $notifications = Knowly_Notification_Service::list_for_user( $user_id, $unread_only, $limit, $offset );

        return $this->success( [
            'notifications' => $notifications,
            'count'         => count( $notifications ),
            'unread_only'   => $unread_only,
        ] );
    }

    public function count( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        if ( $request->get_param( 'scope' ) === 'child' ) {
            $ctx = $this->require_child_context( $request );
            if ( is_wp_error( $ctx ) ) return $ctx;
            $user_id = $ctx['child_id'];
        }

        $unread = Knowly_Notification_Service::count_unread( $user_id );

        return $this->success( [ 'unread' => $unread ] );
    }

    public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        if ( ! user_can( $user_id, 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Admin account required to create notifications.', [ 'status' => 403 ] );
        }

        $payload = $request->get_param( 'payload' );

        $id = Knowly_Notification_Service::create( [
            'recipient_user_id' => (int) $request->get_param( 'recipient_user_id' ),
            'sender_user_id'    => $user_id,
            'type'              => $request->get_param( 'type' ) ?: 'simple',
            'subject'           => $request->get_param( 'subject' ),
            'message'           => $request->get_param( 'message' ),
            'payload'           => $payload ?: null,
        ] );

        if ( is_wp_error( $id ) ) return $id;

        return $this->created( [ 'notification_id' => $id ] );
    }

    public function mark_read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        if ( $request->get_param( 'scope' ) === 'child' ) {
            $ctx = $this->require_child_context( $request );
            if ( is_wp_error( $ctx ) ) return $ctx;
            $user_id = $ctx['child_id'];
        }

        $result = Knowly_Notification_Service::mark_read( (int) $request['id'], $user_id );
        if ( is_wp_error( $result ) ) return $result;

        return $this->success( [ 'marked_read' => true ] );
    }

    public function read_all( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $count = Knowly_Notification_Service::mark_all_read( $user_id );

        return $this->success( [ 'marked_read' => $count ] );
    }

    public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        if ( $request->get_param( 'scope' ) === 'child' ) {
            $ctx = $this->require_child_context( $request );
            if ( is_wp_error( $ctx ) ) return $ctx;
            $user_id = $ctx['child_id'];
        }

        $result = Knowly_Notification_Service::delete( (int) $request['id'], $user_id );
        if ( is_wp_error( $result ) ) return $result;

        return $this->success( [ 'deleted' => true ] );
    }

    public function respond( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id  = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        $notification_id = (int) $request['id'];
        $response        = $request->get_param( 'response' );

        // Fetch notification before recording response so we can act on its payload
        $notif = Knowly_Notification_Service::get( $notification_id, $user_id );
        if ( is_wp_error( $notif ) ) return $notif;

        $result = Knowly_Notification_Service::respond( $notification_id, $user_id, $response );
        if ( is_wp_error( $result ) ) return $result;

        // Post-respond hook: class invitation accepted → add child to class
        if ( $notif['subject'] === 'class_invitation' && $response === 'accepted' ) {
            $payload  = $notif['payload'];
            $class_id = (int) ( $payload['class_id'] ?? 0 );
            $child_id = (int) ( $payload['child_id'] ?? 0 );

            if ( $class_id && $child_id ) {
                $add = Knowly_Class_Service::add_member( $class_id, $child_id, $user_id );
                if ( is_wp_error( $add ) ) {
                    Knowly_Debug::log( 'notification.respond', 'add_member failed after class_invitation accepted', [
                        'notification_id' => $notification_id,
                        'class_id'        => $class_id,
                        'child_id'        => $child_id,
                        'error'           => $add->get_error_message(),
                    ], $user_id, 'error' );
                }
            }
        }

        return $this->success( [ 'responded' => true, 'response' => $response ] );
    }
}
