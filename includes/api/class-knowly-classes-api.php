<?php
/**
 * Knowly_Classes_API — Class management endpoints.
 *
 * Teacher routes  (JWT Teacher — approved):
 *   POST   /knowly/v1/classes                         Create a class
 *   GET    /knowly/v1/classes                         List teacher's classes
 *   GET    /knowly/v1/classes/{id}                    Get class detail + members + tasks
 *   GET    /knowly/v1/classes/child-lookup            Find child by nickname
 *   POST   /knowly/v1/classes/{id}/invite             Invite child by nickname (dual notification)
 *   GET    /knowly/v1/classes/{id}/members            List class members
 *   DELETE /knowly/v1/classes/{id}/members/{child_id} Remove a member
 *   POST   /knowly/v1/classes/{id}/tasks              Create task (deducts red gem)
 *   GET    /knowly/v1/classes/{id}/tasks              List tasks for class
 *
 * Child route  (JWT Child):
 *   GET    /knowly/v1/classes/my                      List classes the child is enrolled in
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Classes_API extends Knowly_API_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        // child-lookup and my must be registered BEFORE /{id} to avoid pattern collision

        register_rest_route( $ns, '/classes/child-lookup', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'child_lookup' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/classes/my', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'my_classes' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/classes', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'name'        => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'description' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'level'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        register_rest_route( $ns, '/classes/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'show' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( $ns, '/classes/(?P<id>\d+)/invite', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'invite' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'               => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'child_nickname'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/classes/(?P<id>\d+)/members', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'members' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( $ns, '/classes/(?P<id>\d+)/members/(?P<child_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'remove_member' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'       => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'child_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );

        register_rest_route( $ns, '/classes/(?P<id>\d+)/tasks', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_task' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id'          => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
                    'title'       => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'description' => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
                    'subject'     => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                    'difficulty'  => [ 'required' => false, 'type' => 'string',  'enum' => [ 'easy', 'medium', 'hard' ] ],
                    'due_date'    => [ 'required' => false, 'type' => 'string' ],
                ],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_tasks' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $class_id = Knowly_Class_Service::create( $teacher_id, [
            'name'        => $request->get_param( 'name' ),
            'description' => $request->get_param( 'description' ),
            'level'       => $request->get_param( 'level' ),
        ] );

        if ( is_wp_error( $class_id ) ) return $class_id;

        return $this->created( [ 'class_id' => $class_id ] );
    }

    public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $classes = Knowly_Class_Service::list_for_teacher( $teacher_id );

        return $this->success( [ 'classes' => $classes, 'count' => count( $classes ) ] );
    }

    public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $class_id = (int) $request['id'];
        $class    = Knowly_Class_Service::get( $class_id );
        if ( is_wp_error( $class ) ) return $class;

        if ( (int) $class['teacher_user_id'] !== $teacher_id ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $class['members'] = Knowly_Class_Service::get_members( $class_id );
        $class['tasks']   = Knowly_Task_Service::list_for_class( $class_id );

        return $this->success( $class );
    }

    public function child_lookup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $results = Knowly_Class_Service::search_children( $request->get_param( 'q' ) );
        if ( is_wp_error( $results ) ) return $results;

        return $this->success( [ 'results' => $results, 'count' => count( $results ) ] );
    }

    public function invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $result = Knowly_Class_Service::invite(
            (int) $request['id'],
            $teacher_id,
            $request->get_param( 'child_nickname' )
        );

        if ( is_wp_error( $result ) ) return $result;

        return $this->success( [
            'invited'          => true,
            'child_id'         => $result['child_id'],
            'parent_id'        => $result['parent_id'],
            'child_notif_id'   => $result['child_notif_id'],
            'parent_notif_id'  => $result['parent_notif_id'],
        ] );
    }

    public function members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $class_id = (int) $request['id'];

        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $members = Knowly_Class_Service::get_members( $class_id );

        return $this->success( [ 'members' => $members, 'count' => count( $members ) ] );
    }

    public function remove_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $result = Knowly_Class_Service::remove_member(
            (int) $request['id'],
            (int) $request['child_id'],
            $teacher_id
        );

        if ( is_wp_error( $result ) ) return $result;

        return $this->success( [ 'removed' => true ] );
    }

    public function create_task( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $task_id = Knowly_Task_Service::create( (int) $request['id'], $teacher_id, [
            'title'       => $request->get_param( 'title' ),
            'description' => $request->get_param( 'description' ),
            'subject'     => $request->get_param( 'subject' ),
            'difficulty'  => $request->get_param( 'difficulty' ),
            'due_date'    => $request->get_param( 'due_date' ),
        ] );

        if ( is_wp_error( $task_id ) ) return $task_id;

        return $this->created( [
            'task_id'         => $task_id,
            'red_gem_cost'    => Knowly_Task_Service::get_task_cost(),
            'red_gem_balance' => Knowly_Red_Gem_Service::get_balance( $teacher_id ),
        ] );
    }

    public function list_tasks( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $teacher_id = $this->require_teacher( $request );
        if ( is_wp_error( $teacher_id ) ) return $teacher_id;

        $class_id = (int) $request['id'];

        if ( ! Knowly_Class_Service::teacher_owns( $class_id, $teacher_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'You do not own this class.', [ 'status' => 403 ] );
        }

        $tasks = Knowly_Task_Service::list_for_class( $class_id );

        return $this->success( [ 'tasks' => $tasks, 'count' => count( $tasks ) ] );
    }

    public function my_classes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = $this->authenticate( $request );
        if ( is_wp_error( $user_id ) ) return $user_id;

        if ( ! $this->is_child( $user_id ) ) {
            return new WP_Error( 'knowly_forbidden', 'Student account required.', [ 'status' => 403 ] );
        }

        $classes = Knowly_Class_Service::list_for_child( $user_id );

        return $this->success( [ 'classes' => $classes, 'count' => count( $classes ) ] );
    }
}
