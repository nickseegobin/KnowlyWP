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
        add_action( 'wp_ajax_knowly_pool_packages',      [ 'Knowly_Admin_Pool', 'handle_ajax_packages' ] );
        add_action( 'wp_ajax_knowly_railway_catalogue',  [ 'Knowly_Admin_Pool', 'handle_ajax_railway_catalogue' ] );
        Knowly_Admin_Pool::boot();
        Knowly_Admin_Members::boot();
        Knowly_Admin_Tokens::boot();
        Knowly_Admin_Leaderboard::register();
        // Block 2
        Knowly_Admin_Teachers::boot();
        Knowly_Admin_Migration::boot();
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

        add_submenu_page( 'knowly-api', 'Dashboard',    'Dashboard',    'manage_options', 'knowly-api',          [ __CLASS__, 'render_dashboard' ] );
        add_submenu_page( 'knowly-api', 'Members',      'Members',      'manage_options', 'knowly-members',      [ 'Knowly_Admin_Members', 'render' ] );
        add_submenu_page( 'knowly-api', 'Teachers',     'Teachers',     'manage_options', 'knowly-teachers',     [ 'Knowly_Admin_Teachers', 'render' ] );
        add_submenu_page( 'knowly-api', 'Tokens',       'Tokens',       'manage_options', 'knowly-tokens',       [ 'Knowly_Admin_Tokens', 'render' ] );
        add_submenu_page( 'knowly-api', 'Pool Manager', 'Pool Manager', 'manage_options', 'knowly-pool',         [ 'Knowly_Admin_Pool', 'render' ] );
        add_submenu_page( 'knowly-api', 'Settings',     'Settings',     'manage_options', 'knowly-settings',     [ 'Knowly_Admin_Settings', 'render' ] );
        add_submenu_page( 'knowly-api', 'UM Migration', 'UM Migration', 'manage_options', 'knowly-migration',    [ 'Knowly_Admin_Migration', 'render' ] );
        add_submenu_page( 'knowly-api', 'Debug Log',    'Debug Log',    'manage_options', 'knowly-debug',        [ 'Knowly_Admin_Debug', 'render' ] );
        add_submenu_page( 'knowly-api', 'Test Suite',   'Test Suite',   'manage_options', 'knowly-test-suite',   [ 'Knowly_Admin_Testing', 'render' ] );
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
        $pool_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_pool" );
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
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $pool_count ) ?></div>
                    <div class="knowly-stat-label">Exam Packages in Pool</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $session_count ) ?></div>
                    <div class="knowly-stat-label">Completed Exams</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $insight_count ) ?></div>
                    <div class="knowly-stat-label">AI Insights Generated</div>
                </div>
            </div>

            <div class="knowly-quick-links">
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-members' ) ) ?>" class="button button-primary">Members</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-teachers' ) ) ?>" class="button button-primary<?= $pending_teachers > 0 ? ' knowly-alert-btn' : '' ?>">Teachers<?= $pending_teachers > 0 ? " ({$pending_teachers})" : '' ?></a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-tokens' ) ) ?>" class="button button-primary">Tokens</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-pool' ) ) ?>" class="button button-primary">Pool Manager</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>" class="button">Settings</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-migration' ) ) ?>" class="button">UM Migration</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-debug' ) ) ?>" class="button">Debug Log</a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-test-suite' ) ) ?>" class="button">Test Suite</a>
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
            [ 'GET',    '/tokens/balance',                'JWT',        'Token balance' ],
            [ 'GET',    '/tokens/ledger',                 'JWT',        'Transaction history' ],
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
        ];
    }
}
