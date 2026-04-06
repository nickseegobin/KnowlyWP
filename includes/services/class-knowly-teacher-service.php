<?php
/**
 * Knowly_Teacher_Service — Teacher account business logic.
 *
 * Handles:
 *  - Teacher registration (pending_approval status)
 *  - Admin approval / suspension
 *  - Profile retrieval
 *  - Approval status guard (used by teacher middleware)
 *
 * Teacher meta keys:
 *   knowly_role                → teacher
 *   knowly_approval_status     → pending_approval | approved | suspended
 *   knowly_school_name
 *   knowly_class_name
 *   knowly_phone
 *   knowly_principal_name
 *   knowly_principal_contact
 *   knowly_red_gem_balance     INT
 *   knowly_red_gem_stipend     INT
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Teacher_Service {

    // ── Register ──────────────────────────────────────────────────────────────

    /**
     * Register a new teacher account with pending_approval status.
     *
     * @param  array $data { first_name, last_name, email, password, school_name,
     *                       class_name, phone, principal_name, principal_contact }
     * @return array|WP_Error
     */
    public static function register( array $data ): array|WP_Error {
        $first_name        = sanitize_text_field( $data['first_name']        ?? '' );
        $last_name         = sanitize_text_field( $data['last_name']         ?? '' );
        $email             = sanitize_email(      $data['email']             ?? '' );
        $password          = $data['password'] ?? '';
        $school_name       = sanitize_text_field( $data['school_name']       ?? '' );
        $class_name        = sanitize_text_field( $data['class_name']        ?? '' );
        $phone             = sanitize_text_field( $data['phone']             ?? '' );
        $principal_name    = sanitize_text_field( $data['principal_name']    ?? '' );
        $principal_contact = sanitize_text_field( $data['principal_contact'] ?? '' );

        if ( ! $first_name || ! $last_name || ! $email || ! $password || ! $school_name ) {
            return new WP_Error(
                'knowly_missing_fields',
                'first_name, last_name, email, password, and school_name are required.',
                [ 'status' => 422 ]
            );
        }

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'knowly_invalid_email', 'Please provide a valid email address.', [ 'status' => 422 ] );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'knowly_email_taken', 'An account with that email already exists.', [ 'status' => 409 ] );
        }

        $user_id = wp_create_user( $email, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            Knowly_Debug::log( 'teacher.register', 'wp_create_user failed', [
                'email' => $email,
                'error' => $user_id->get_error_message(),
            ], null, 'error' );
            return new WP_Error( 'knowly_registration_failed', 'Account creation failed. Please try again.', [ 'status' => 500 ] );
        }

        $user = new WP_User( $user_id );
        $user->set_role( 'knowly_teacher' );

        wp_update_user( [
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
            'nickname'     => $first_name,
        ] );

        // Teacher meta
        update_user_meta( $user_id, 'knowly_approval_status',   'pending_approval' );
        update_user_meta( $user_id, 'knowly_school_name',        $school_name );
        update_user_meta( $user_id, 'knowly_class_name',         $class_name );
        update_user_meta( $user_id, 'knowly_phone',              $phone );
        update_user_meta( $user_id, 'knowly_principal_name',     $principal_name );
        update_user_meta( $user_id, 'knowly_principal_contact',  $principal_contact );
        update_user_meta( $user_id, 'knowly_red_gem_balance',    0 );
        update_user_meta( $user_id, 'knowly_red_gem_stipend',    (int) get_option( 'knowly_red_gem_stipend', 20 ) );

        // Notify admin
        self::notify_admin_new_application( $user_id, $first_name, $last_name, $email, $school_name );

        Knowly_Debug::log( 'teacher.register', 'Teacher account registered (pending approval)', [
            'user_id' => $user_id,
            'email'   => $email,
        ], $user_id, 'info' );

        return [
            'user_id'          => $user_id,
            'email'            => $email,
            'display_name'     => $first_name . ' ' . $last_name,
            'role'             => 'teacher',
            'approval_status'  => 'pending_approval',
            'message'          => 'Application submitted. You will be notified when your account is approved.',
        ];
    }

    // ── Approval / Suspension ─────────────────────────────────────────────────

    /**
     * Approve a teacher account. Sets status to approved and grants red gem stipend.
     *
     * @param  int $teacher_id
     * @return true|WP_Error
     */
    public static function approve( int $teacher_id ): true|WP_Error {
        $user = get_userdata( $teacher_id );
        if ( ! $user || ! in_array( 'knowly_teacher', (array) $user->roles, true ) ) {
            return new WP_Error( 'knowly_not_found', 'Teacher not found.', [ 'status' => 404 ] );
        }

        $stipend = (int) get_user_meta( $teacher_id, 'knowly_red_gem_stipend', true );
        if ( ! $stipend ) {
            $stipend = (int) get_option( 'knowly_red_gem_stipend', 20 );
            update_user_meta( $teacher_id, 'knowly_red_gem_stipend', $stipend );
        }

        update_user_meta( $teacher_id, 'knowly_approval_status', 'approved' );
        update_user_meta( $teacher_id, 'knowly_red_gem_balance', $stipend );

        self::notify_teacher_status_change( $teacher_id, 'approved' );

        Knowly_Debug::log( 'teacher.approve', 'Teacher approved', [
            'teacher_id' => $teacher_id,
            'stipend'    => $stipend,
        ], $teacher_id, 'info' );

        return true;
    }

    /**
     * Suspend a teacher account.
     *
     * @param  int $teacher_id
     * @return true|WP_Error
     */
    public static function suspend( int $teacher_id ): true|WP_Error {
        $user = get_userdata( $teacher_id );
        if ( ! $user || ! in_array( 'knowly_teacher', (array) $user->roles, true ) ) {
            return new WP_Error( 'knowly_not_found', 'Teacher not found.', [ 'status' => 404 ] );
        }

        update_user_meta( $teacher_id, 'knowly_approval_status', 'suspended' );

        self::notify_teacher_status_change( $teacher_id, 'suspended' );

        Knowly_Debug::log( 'teacher.suspend', 'Teacher suspended', [ 'teacher_id' => $teacher_id ], $teacher_id, 'info' );

        return true;
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    /**
     * Return a teacher's full profile.
     *
     * @param  int $teacher_id
     * @return array|WP_Error
     */
    public static function get_profile( int $teacher_id ): array|WP_Error {
        $user = get_userdata( $teacher_id );
        if ( ! $user ) {
            return new WP_Error( 'knowly_not_found', 'User not found.', [ 'status' => 404 ] );
        }

        return self::format_teacher( $user );
    }

    /**
     * Return all teacher accounts.
     *
     * @param  string|null $status  Filter by approval_status (or null for all).
     * @return array
     */
    public static function list_teachers( ?string $status = null ): array {
        $users = get_users( [ 'role' => 'knowly_teacher', 'orderby' => 'registered', 'order' => 'DESC' ] );

        $result = [];
        foreach ( $users as $user ) {
            $teacher = self::format_teacher( $user );
            if ( $status === null || $teacher['approval_status'] === $status ) {
                $result[] = $teacher;
            }
        }

        return $result;
    }

    // ── Approval Guard ────────────────────────────────────────────────────────

    /**
     * Return true if the teacher is approved and can access teacher endpoints.
     *
     * @param  int $teacher_id
     * @return bool
     */
    public static function is_approved( int $teacher_id ): bool {
        return get_user_meta( $teacher_id, 'knowly_approval_status', true ) === 'approved';
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function format_teacher( WP_User $user ): array {
        return [
            'user_id'           => $user->ID,
            'display_name'      => $user->display_name,
            'first_name'        => get_user_meta( $user->ID, 'first_name', true ),
            'last_name'         => get_user_meta( $user->ID, 'last_name', true ),
            'email'             => $user->user_email,
            'role'              => 'teacher',
            'approval_status'   => get_user_meta( $user->ID, 'knowly_approval_status', true ) ?: 'pending_approval',
            'school_name'       => get_user_meta( $user->ID, 'knowly_school_name', true ),
            'class_name'        => get_user_meta( $user->ID, 'knowly_class_name', true ),
            'phone'             => get_user_meta( $user->ID, 'knowly_phone', true ),
            'principal_name'    => get_user_meta( $user->ID, 'knowly_principal_name', true ),
            'principal_contact' => get_user_meta( $user->ID, 'knowly_principal_contact', true ),
            'red_gem_balance'   => (int) get_user_meta( $user->ID, 'knowly_red_gem_balance', true ),
            'red_gem_stipend'   => (int) get_user_meta( $user->ID, 'knowly_red_gem_stipend', true ),
            'registered'        => $user->user_registered,
        ];
    }

    private static function notify_admin_new_application(
        int $user_id, string $first_name, string $last_name, string $email, string $school_name
    ): void {
        $admin_email = get_option( 'admin_email' );
        $admin_url   = admin_url( 'admin.php?page=knowly-teachers' );
        $subject     = '[Knowly] New Teacher Application — ' . $first_name . ' ' . $last_name;
        $message     = "A new teacher has applied for a Knowly account.\n\n"
                     . "Name: {$first_name} {$last_name}\n"
                     . "Email: {$email}\n"
                     . "School: {$school_name}\n\n"
                     . "Review the application: {$admin_url}\n";

        wp_mail( $admin_email, $subject, $message );
    }

    private static function notify_teacher_status_change( int $teacher_id, string $new_status ): void {
        $user = get_userdata( $teacher_id );
        if ( ! $user ) return;

        if ( $new_status === 'approved' ) {
            $subject = '[Knowly] Your teacher account has been approved';
            $message = "Hi {$user->display_name},\n\n"
                     . "Your Knowly teacher account has been approved. You can now log in and start managing your class.\n\n"
                     . "Thank you for joining Knowly.";
        } else {
            $subject = '[Knowly] Your teacher account has been suspended';
            $message = "Hi {$user->display_name},\n\n"
                     . "Your Knowly teacher account has been suspended. Please contact support if you believe this is an error.";
        }

        wp_mail( $user->user_email, $subject, $message );
    }
}
