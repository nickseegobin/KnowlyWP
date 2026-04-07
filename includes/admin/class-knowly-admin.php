<?php
/**
 * Knowly_Admin — Admin panel bootstrap.
 *
 * Registers the top-level "KnowlyAPI" admin menu with four sub-pages:
 *   Dashboard  — quick status overview
 *   Settings   — all plugin configuration
 *   Debug Log  — searchable log viewer (visible when debug mode is on)
 *   Test Suite — integrated API testing panel
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin {

    public static function boot(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_knowly_test',       [ __CLASS__, 'handle_test_ajax' ] );
        add_action( 'wp_ajax_knowly_clear_logs', [ __CLASS__, 'handle_clear_logs' ] );
        add_action( 'wp_ajax_knowly_gen_token',  [ __CLASS__, 'handle_gen_token' ] );
        add_action( 'wp_ajax_knowly_gems_test',  [ 'Knowly_Admin_Gems', 'handle_test_ajax' ] );
        // Pool AJAX (used by Trials + Quests pages)
        Knowly_Admin_Pool::boot();
        // User management (AJAX handlers used by Users page)
        Knowly_Admin_Members::boot();
        Knowly_Admin_Teachers::boot();
        // Leaderboard (register hooks + AJAX)
        Knowly_Admin_Leaderboard::register();
        // Modules
        Knowly_Admin_Gems::boot();
        Knowly_Admin_Classes::boot();
        Knowly_Admin_Analytics::boot();
        // New module pages
        Knowly_Admin_Users::boot();
        Knowly_Admin_Trials::boot();
        Knowly_Admin_Quests_Panel::boot();
        Knowly_Admin_Notifications_Panel::boot();
        Knowly_Admin_System::boot();
        // Sign-off + Training (AJAX used by System page)
        Knowly_Admin_Signoff::boot();
        Knowly_Admin_Training::boot();
    }

    // ── Menu Registration ─────────────────────────────────────────────────────

    public static function register_menus(): void {
        add_menu_page(
            'KnowlyAPI',
            'KnowlyAPI',
            'manage_options',
            'knowly-api',
            [ __CLASS__, 'render_dashboard' ],
            'dashicons-rest-api',
            30
        );

        // ── Primary navigation — ordered per Production Spec ─────────────────
        add_submenu_page( 'knowly-api', 'Dashboard',      'Dashboard',      'manage_options', 'knowly-api',                   [ __CLASS__,                         'render_dashboard' ] );
        add_submenu_page( 'knowly-api', 'System',         'System',         'manage_options', 'knowly-system',                [ 'Knowly_Admin_System',             'render' ] );
        add_submenu_page( 'knowly-api', 'Settings',       'Settings',       'manage_options', 'knowly-settings',              [ 'Knowly_Admin_Settings',           'render' ] );
        add_submenu_page( 'knowly-api', 'Users',          'Users',          'manage_options', 'knowly-users',                 [ 'Knowly_Admin_Users',              'render' ] );
        add_submenu_page( 'knowly-api', 'Teachers',       'Teachers',       'manage_options', 'knowly-teachers',              [ 'Knowly_Admin_Teachers',           'render' ] );
        add_submenu_page( 'knowly-api', 'Gems',           'Gems',           'manage_options', 'knowly-gems',                  [ 'Knowly_Admin_Gems',               'render' ] );
        add_submenu_page( 'knowly-api', 'Classes',        'Classes',        'manage_options', 'knowly-classes',               [ 'Knowly_Admin_Classes',            'render' ] );
        add_submenu_page( 'knowly-api', 'Trials',         'Trials',         'manage_options', 'knowly-trials',                [ 'Knowly_Admin_Trials',             'render' ] );
        add_submenu_page( 'knowly-api', 'Quests',         'Quests',         'manage_options', 'knowly-quests-panel',          [ 'Knowly_Admin_Quests_Panel',       'render' ] );
        // Leaderboard menu entry is registered by Knowly_Admin_Leaderboard::add_menu() via admin_menu hook
        add_submenu_page( 'knowly-api', 'Notifications',  'Notifications',  'manage_options', 'knowly-notifications-panel',   [ 'Knowly_Admin_Notifications_Panel','render' ] );
        add_submenu_page( 'knowly-api', 'Analytics',      'Analytics',      'manage_options', 'knowly-analytics',             [ 'Knowly_Admin_Analytics',          'render' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'knowly' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'knowly-admin',
            KNOWLY_PLUGIN_URL . 'assets/css/knowly-admin.css',
            [],
            KNOWLY_VERSION
        );

        wp_enqueue_script(
            'knowly-admin',
            KNOWLY_PLUGIN_URL . 'assets/js/knowly-admin.js',
            [ 'jquery' ],
            KNOWLY_VERSION,
            true
        );

        wp_localize_script( 'knowly-admin', 'KnowlyAdmin', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'knowly_admin_nonce' ),
            'siteUrl'   => get_site_url(),
            'restBase'  => rest_url( KNOWLY_REST_NAMESPACE ),
            'version'   => KNOWLY_VERSION,
            'debugMode' => Knowly_Debug::is_enabled() ? '1' : '0',
        ] );
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public static function render_dashboard(): void {
        global $wpdb;

        $parent_count  = count( get_users( [ 'role' => 'knowly_parent',  'fields' => 'ID' ] ) );
        $child_count   = count( get_users( [ 'role' => 'knowly_child',   'fields' => 'ID' ] ) );
        $teacher_count = count( get_users( [ 'role' => 'knowly_teacher', 'fields' => 'ID' ] ) );
        $pending_teachers = count( array_filter(
            get_users( [ 'role' => 'knowly_teacher', 'fields' => 'ID' ] ),
            fn( $id ) => get_user_meta( $id, 'knowly_approval_status', true ) === 'pending_approval'
        ) );
        $class_count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes" );
        $signoff_status      = get_option( 'knowly_signoff_status', 'blocked' );
        $unread_notif_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_notifications WHERE is_read = 0" );
        $session_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_sessions WHERE state = 'completed'" );
        $insight_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_insights" );
        $log_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_debug_log" );

        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI <span class="knowly-version">v<?= esc_html( KNOWLY_VERSION ) ?></span></h1>

            <div class="knowly-status-bar <?= Knowly_Debug::is_enabled() ? 'debug-on' : 'debug-off' ?>">
                <?php if ( Knowly_Debug::is_enabled() ) : ?>
                    <span class="dashicons dashicons-visibility"></span> Debug Mode is <strong>ON</strong> — <?= esc_html( $log_count ) ?> log entries
                <?php else : ?>
                    <span class="dashicons dashicons-hidden"></span> Debug Mode is <strong>OFF</strong>
                <?php endif; ?>
                &nbsp;|&nbsp;
                Railway: <?= $railway_ok ? '<span class="knowly-badge ok">Configured</span>' : '<span class="knowly-badge warn">Not configured</span>' ?>
            </div>

            <div class="knowly-stat-grid">
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $parent_count ) ?></div>
                    <div class="knowly-stat-label">Parent Accounts</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $child_count ) ?></div>
                    <div class="knowly-stat-label">Student Profiles</div>
                </div>
                <div class="knowly-stat-card" style="<?= $pending_teachers > 0 ? 'border-color:#d63638;' : '' ?>">
                    <div class="knowly-stat-number" style="<?= $pending_teachers > 0 ? 'color:#d63638;' : '' ?>"><?= esc_html( $teacher_count ) ?></div>
                    <div class="knowly-stat-label">Teachers<?= $pending_teachers > 0 ? " ({$pending_teachers} pending)" : '' ?></div>
                </div>
                <div class="knowly-stat-card" style="<?= $signoff_status === 'approved' ? 'border-color:#00a32a;' : 'border-color:#d63638;' ?>">
                    <div class="knowly-stat-number" style="<?= $signoff_status === 'approved' ? 'color:#00a32a;' : 'color:#d63638;' ?>"><?= $signoff_status === 'approved' ? 'GO' : 'HOLD' ?></div>
                    <div class="knowly-stat-label">Launch Gate</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $session_count ) ?></div>
                    <div class="knowly-stat-label">Completed Exams</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $insight_count ) ?></div>
                    <div class="knowly-stat-label">AI Insights Generated</div>
                </div>
                <div class="knowly-stat-card" style="<?= $unread_notif_count > 0 ? 'border-color:#d63638;' : '' ?>">
                    <div class="knowly-stat-number" style="<?= $unread_notif_count > 0 ? 'color:#d63638;' : '' ?>"><?= esc_html( $unread_notif_count ) ?></div>
                    <div class="knowly-stat-label">Unread Notifications</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div>
                    <div class="knowly-stat-label">Classes</div>
                </div>
            </div>

            <div class="knowly-quick-links">
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-system' ) ) ?>" class="button button-primary">System</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-users' ) ) ?>" class="button button-primary">Users</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-teachers' ) ) ?>" class="button button-primary<?= $pending_teachers > 0 ? ' knowly-alert-btn' : '' ?>">Teachers<?= $pending_teachers > 0 ? " ({$pending_teachers})" : '' ?></a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-gems' ) ) ?>" class="button button-primary">Gems</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-classes' ) ) ?>" class="button button-primary">Classes</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-trials' ) ) ?>" class="button button-primary">Trials</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-quests-panel' ) ) ?>" class="button button-primary">Quests</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-leaderboard' ) ) ?>" class="button button-primary">Leaderboard</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-notifications-panel' ) ) ?>" class="button button-primary">Notifications</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics' ) ) ?>" class="button button-primary">Analytics</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>" class="button">Settings</a>
            </div>

            <div class="knowly-api-table-wrapper">
                <h2>API Endpoints Reference</h2>
                <table class="knowly-table">
                    <thead><tr><th>Method</th><th>Route</th><th>Auth</th><th>Description</th></tr></thead>
                    <tbody>
                        <?php foreach ( self::endpoint_reference() as $ep ) : ?>
                        <tr>
                            <td><span class="knowly-method <?= esc_attr( strtolower( $ep[0] ) ) ?>"><?= esc_html( $ep[0] ) ?></span></td>
                            <td><code><?= esc_html( '/knowly/v1' . $ep[1] ) ?></code></td>
                            <td><?= esc_html( $ep[2] ) ?></td>
                            <td><?= esc_html( $ep[3] ) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

    public static function handle_test_ajax(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $test = sanitize_key( $_POST['test'] ?? '' );
        $data = json_decode( stripslashes( $_POST['data'] ?? '{}' ), true ) ?: [];

        $result = Knowly_Admin_Testing::run_test( $test, $data );
        wp_send_json( $result );
    }

    public static function handle_gen_token(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) {
            // Default: current admin user
            $user_id = get_current_user_id();
        }

        if ( ! get_userdata( $user_id ) ) {
            wp_send_json_error( [ 'message' => "User ID {$user_id} not found." ] );
            return;
        }

        $token = Knowly_JWT::encode( $user_id );
        wp_send_json_success( [
            'token'   => $token,
            'user_id' => $user_id,
        ] );
    }

    public static function handle_clear_logs(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        Knowly_Debug::clear_logs();
        wp_send_json_success( [ 'message' => 'Debug logs cleared.' ] );
    }

    // ── Endpoint Reference ────────────────────────────────────────────────────

    private static function endpoint_reference(): array {
        return [
            [ 'GET',    '/ping',                          'Open',       'Health check' ],
            [ 'POST',   '/auth/register/parent',          'Open',       'Register parent account → JWT' ],
            [ 'POST',   '/auth/register/teacher',         'Open',       'Register teacher (pending approval)' ],
            [ 'POST',   '/auth/login',                    'Open',       'Login → JWT' ],
            [ 'POST',   '/auth/password/reset',           'Open',       'Trigger WP password reset email' ],
            [ 'GET',    '/auth/me',                       'JWT',        'Current user profile' ],
            [ 'PATCH',  '/auth/profile',                  'JWT Parent', 'Update parent profile' ],
            [ 'POST',   '/auth/pin/set',                  'JWT Parent', 'Set / update parent PIN' ],
            [ 'POST',   '/auth/pin/verify',               'JWT Parent', 'Verify parent PIN' ],
            [ 'GET',    '/auth/pin/status',               'JWT Parent', 'PIN lock status' ],
            [ 'GET',    '/children',                      'JWT Parent', 'List children' ],
            [ 'POST',   '/children',                      'JWT Parent', 'Create child' ],
            [ 'GET',    '/children/{id}',                 'JWT Parent', 'Get child profile' ],
            [ 'PATCH',  '/children/{id}',                 'JWT Parent', 'Update child profile' ],
            [ 'DELETE', '/children/{id}',                 'JWT Parent', 'Remove child' ],
            [ 'POST',   '/children/{id}/switch',          'JWT Parent', 'Switch active child' ],
            [ 'POST',   '/children/deselect',             'JWT Parent', 'Return to parent view' ],
            [ 'GET',    '/exams',                         'JWT',        'Exam catalogue' ],
            [ 'POST',   '/exams/start',                   'JWT',        'Start exam (deduct token)' ],
            [ 'GET',    '/exams/{id}/checkpoint',         'JWT',        'Get checkpoint' ],
            [ 'POST',   '/exams/{id}/checkpoint',         'JWT',        'Save checkpoint' ],
            [ 'POST',   '/exams/{id}/submit',             'JWT',        'Submit exam answers' ],
            [ 'GET',    '/results',                       'JWT',        'Exam history (paginated)' ],
            [ 'GET',    '/results/stats',                 'JWT',        'Aggregate stats' ],
            [ 'GET',    '/results/{id}',                  'JWT',        'Session detail + answers' ],
            [ 'POST',   '/insights/exam/{id}',            'JWT',        'Generate per-exam insight' ],
            [ 'GET',    '/insights/exam/{id}',            'JWT',        'Retrieve per-exam insight' ],
            [ 'GET',    '/insights/weekly/{week}',        'JWT',        'Weekly digest insight' ],
            [ 'POST',   '/insights/weekly/{week}',        'JWT',        'Trigger weekly digest' ],
            // Block 4 — Notifications
            [ 'GET',    '/notifications',                 'JWT',        'List notifications (unread_only default)' ],
            [ 'GET',    '/notifications/count',           'JWT',        'Unread notification count (badge)' ],
            [ 'POST',   '/notifications',                 'Admin JWT',  'Create a notification' ],
            [ 'POST',   '/notifications/{id}/read',       'JWT',        'Mark one notification as read' ],
            [ 'POST',   '/notifications/read-all',        'JWT',        'Mark all notifications as read' ],
            [ 'POST',   '/notifications/{id}/respond',    'JWT',        'Accept or decline a confirmation notification' ],
            // Block 3 — Gems
            [ 'GET',    '/gems/balance',                  'JWT',        'Blue gem wallet balance' ],
            [ 'GET',    '/gems/ledger',                   'JWT',        'Gem transaction history' ],
            [ 'POST',   '/gems/allocate',                 'JWT Parent', 'Allocate gems parent → child' ],
            [ 'GET',    '/gems/products',                 'Open',       'List purchasable gem packages' ],
            [ 'POST',   '/gems/checkout',                 'JWT Parent', 'Initiate Fygaro checkout' ],
            [ 'POST',   '/gems/fygaro-webhook',           'HMAC',       'Receive Fygaro payment webhook' ],
            // Block 5 — Classes
            [ 'POST',   '/classes',                       'JWT Teacher','Create a class' ],
            [ 'GET',    '/classes',                       'JWT Teacher','List teacher\'s classes' ],
            [ 'GET',    '/classes/{id}',                  'JWT Teacher','Class detail (members + tasks)' ],
            [ 'GET',    '/classes/child-lookup',          'JWT Teacher','Find child by public nickname' ],
            [ 'POST',   '/classes/{id}/invite',           'JWT Teacher','Invite child by nickname (dual notification)' ],
            [ 'GET',    '/classes/{id}/members',          'JWT Teacher','List class members' ],
            [ 'DELETE', '/classes/{id}/members/{child_id}','JWT Teacher','Remove a member from class' ],
            [ 'POST',   '/classes/{id}/tasks',            'JWT Teacher','Create task (deducts red gem)' ],
            [ 'GET',    '/classes/{id}/tasks',            'JWT Teacher','List tasks for class' ],
            [ 'GET',    '/classes/my',                    'JWT Child',  'List classes the student is enrolled in' ],
            // Block 7 — Analytics
            [ 'GET',    '/analytics/class/{class_id}',               'JWT Teacher', 'Class aggregate analytics (trials, quests, scores, source)' ],
            [ 'GET',    '/analytics/class/{class_id}/student/{user_id}', 'JWT Teacher', 'Per-student drill-down (subject breakdown, recent sessions)' ],
            // Block 10 — Training Material (Pinecone vector management)
            [ 'POST',   '/training/upsert',                          'Server Key',  'Upsert a training vector into Pinecone (embed + index)' ],
            [ 'DELETE', '/training/delete',                          'Server Key',  'Delete a training vector from Pinecone by vector_id' ],
        ];
    }
}
