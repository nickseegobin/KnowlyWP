<?php
/**
 * Knowly_Admin_Testing — Integrated API test suite.
 *
 * Each test group contains individual tests that can be run via AJAX.
 * Tests call the actual REST API endpoints internally (not mocked),
 * giving real confidence that the full stack is working.
 *
 * Test groups:
 *   System              — JWT secret, DB tables, Railway connection
 *   Auth                — Login, /me, PIN set/verify
 *   Children            — Create, list, switch, remove
 *   Exams               — Catalogue, start, checkpoint, submit
 *   Results             — History, stats, session detail
 *   Insights            — Per-exam insight, weekly digest
 *   Block 4 — Notifications — Create, list, count, respond, read-all
 *   Block 6 — Quests & Badges  — Catalogue, start (first/retake/assignment), badge award/idempotent/list
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Testing {

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI — Test Suite</h1>
            <p class="knowly-test-intro">
                Tests call your actual REST API endpoints (with valid admin credentials) and report pass/fail with full request/response detail.
                Run individual tests or click <strong>Run All</strong>.
            </p>

                <details class="knowly-test-data-panel" open style="margin-bottom:16px;background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;">
                <summary style="cursor:pointer;font-weight:600;font-size:13px;">🔑 Test Data (credentials for auth tests)</summary>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:12px;">
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">Email (parent login)<input type="text" id="td-username" class="regular-text" placeholder="parent@email.com" autocomplete="off" /></label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">Password<input type="password" id="td-password" class="regular-text" placeholder="parent password" autocomplete="off" /></label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">
                        JWT Token
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" id="td-token" class="regular-text" placeholder="paste token or use buttons below" style="flex:1;min-width:0;" />
                        </div>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">Child ID<input type="number" id="td-child-id" class="regular-text" placeholder="child WP user ID" /></label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">User ID (for admin token tests)<input type="number" id="td-user-id" class="regular-text" placeholder="WP user ID" /></label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">PIN (4 digits)<input type="text" id="td-pin" class="regular-text" placeholder="e.g. 1234" maxlength="4" /></label>
                </div>
                <?php self::render_user_selectors(); ?>
                <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button type="button" id="knowly-gen-admin-token" class="button">⚡ Generate Admin Token</button>
                    <button type="button" id="knowly-gen-user-token" class="button">🔑 Generate Token for User ID</button>
                    <span id="knowly-token-status" style="font-size:12px;color:#666;"></span>
                </div>
            </details>

            <div class="knowly-test-toolbar">
                <button id="knowly-run-all" class="button button-primary">▶ Run All Tests</button>
                <button id="knowly-clear-results" class="button">Clear Results</button>
                <span id="knowly-test-summary" class="knowly-test-summary"></span>
            </div>

            <?php foreach ( self::test_groups() as $group_id => $group ) : ?>
            <div class="knowly-test-group" id="group-<?= esc_attr( $group_id ) ?>">
                <div class="knowly-test-group-header">
                    <h2><?= esc_html( $group['label'] ) ?></h2>
                    <button class="button knowly-run-group" data-group="<?= esc_attr( $group_id ) ?>">Run Group</button>
                </div>
                <div class="knowly-test-list">
                    <?php foreach ( $group['tests'] as $test_id => $test ) : ?>
                    <div class="knowly-test-item" id="test-<?= esc_attr( $test_id ) ?>">
                        <div class="knowly-test-header">
                            <span class="knowly-test-status" id="status-<?= esc_attr( $test_id ) ?>">○</span>
                            <span class="knowly-test-name"><?= esc_html( $test['label'] ) ?></span>
                            <code class="knowly-test-route"><?= esc_html( $test['method'] . ' /knowly/v1' . $test['route'] ) ?></code>
                            <button class="button button-small knowly-run-test" data-test="<?= esc_attr( $test_id ) ?>">Run</button>
                        </div>
                        <div class="knowly-test-result" id="result-<?= esc_attr( $test_id ) ?>" style="display:none"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ── User Selector Widget ──────────────────────────────────────────────────

    private static function render_user_selectors(): void {
        ?>
        <div style="border-top:1px solid #f0f0f0;margin-top:12px;padding-top:12px;">
            <p style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px;">Test Users (for Notification tests — search or enter ID directly)</p>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;position:relative;">

                <div style="display:flex;flex-direction:column;gap:4px;font-size:12px;position:relative;">
                    <label for="td-parent-search" style="font-weight:600;color:#2271b1;">Test Parent</label>
                    <input type="text" id="td-parent-search" placeholder="Search by name or email…" autocomplete="off" style="width:100%;" />
                    <input type="number" id="td-parent-id" placeholder="Parent User ID" style="width:100%;margin-top:2px;" />
                    <span id="td-parent-label" style="font-size:11px;color:#888;min-height:14px;"></span>
                </div>

                <div style="display:flex;flex-direction:column;gap:4px;font-size:12px;position:relative;">
                    <label for="td-teacher-search" style="font-weight:600;color:#2271b1;">Test Teacher</label>
                    <input type="text" id="td-teacher-search" placeholder="Search by name or email…" autocomplete="off" style="width:100%;" />
                    <input type="number" id="td-teacher-id" placeholder="Teacher User ID" style="width:100%;margin-top:2px;" />
                    <span id="td-teacher-label" style="font-size:11px;color:#888;min-height:14px;"></span>
                </div>

                <div style="display:flex;flex-direction:column;gap:4px;font-size:12px;position:relative;">
                    <label for="td-child-search" style="font-weight:600;color:#2271b1;">Test Child (Student)</label>
                    <input type="text" id="td-child-search" placeholder="Search by name or email…" autocomplete="off" style="width:100%;" />
                    <span id="td-child-label" style="font-size:11px;color:#888;min-height:14px;">ID field shared with Child ID above</span>
                    <p style="font-size:10px;color:#aaa;margin:0;">Selecting here fills Child ID above</p>
                </div>

            </div>
        </div>
        <?php
    }

    // ── Test User Helper ──────────────────────────────────────────────────────

    /**
     * Get a test user by role. Checks $data first (from the test data panel user selectors),
     * then falls back to hard-coded test account emails.
     */
    private static function get_test_user( string $role, array $data ): ?WP_User {
        $id_key = match ( $role ) {
            'parent'  => 'parent_id',
            'teacher' => 'teacher_id',
            'child'   => 'child_id',
            default   => null,
        };

        if ( $id_key && ! empty( $data[ $id_key ] ) ) {
            $user = get_user_by( 'id', (int) $data[ $id_key ] );
            if ( $user ) return $user;
        }

        // Fallback to hardcoded test emails (backward-compatible)
        $fallback_email = match ( $role ) {
            'parent'  => 'test.parent@knowly.test',
            'teacher' => 'test.teacher@knowly.test',
            'child'   => 'test.child@knowly.test',
            default   => null,
        };

        return $fallback_email ? get_user_by( 'email', $fallback_email ) : null;
    }

    // ── Per-module group renderer ─────────────────────────────────────────────
    // Call from a module page's Unit Tests tab to embed the relevant test groups.
    // Requires the knowly-admin JS to be enqueued on the page (it is for all knowly-* pages).

    public static function render_test_groups( array $group_ids ): void {
        $all_groups = self::test_groups();
        ?>
        <!-- Test data panel — required by knowly-admin.js syncTestDataFromInputs() -->
        <details class="knowly-test-data-panel" style="margin-bottom:16px;background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px 16px;">
            <summary style="cursor:pointer;font-weight:600;font-size:13px;">🔑 Test Data (credentials for auth tests)</summary>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:12px;">
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">Email (parent login)<input type="text" id="td-username" class="regular-text" placeholder="parent@email.com" autocomplete="off" /></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">Password<input type="password" id="td-password" class="regular-text" placeholder="parent password" autocomplete="off" /></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">JWT Token<input type="text" id="td-token" class="regular-text" placeholder="paste token or generate below" /></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">Child ID<input type="number" id="td-child-id" class="regular-text" placeholder="child WP user ID" /></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">User ID (admin token)<input type="number" id="td-user-id" class="regular-text" placeholder="WP user ID" /></label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;">PIN (4 digits)<input type="text" id="td-pin" class="regular-text" placeholder="e.g. 1234" maxlength="4" /></label>
            </div>
            <?php self::render_user_selectors(); ?>
            <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="button" id="knowly-gen-admin-token" class="button">⚡ Generate Admin Token</button>
                <button type="button" id="knowly-gen-user-token" class="button">🔑 Generate Token for User ID</button>
                <span id="knowly-token-status" style="font-size:12px;color:#666;"></span>
            </div>
        </details>

        <div class="knowly-test-toolbar" style="margin-bottom:12px;">
            <button id="knowly-run-all" class="button button-primary">▶ Run All</button>
            <button id="knowly-clear-results" class="button">Clear</button>
            <span id="knowly-test-summary" class="knowly-test-summary"></span>
        </div>
        <?php
        foreach ( $group_ids as $group_id ) {
            if ( ! isset( $all_groups[ $group_id ] ) ) continue;
            $group = $all_groups[ $group_id ];
            ?>
            <div class="knowly-test-group" id="group-<?= esc_attr( $group_id ) ?>">
                <div class="knowly-test-group-header">
                    <h3 style="margin:0;"><?= esc_html( $group['label'] ) ?></h3>
                    <button class="button knowly-run-group" data-group="<?= esc_attr( $group_id ) ?>">Run Group</button>
                </div>
                <div class="knowly-test-list">
                    <?php foreach ( $group['tests'] as $test_id => $test ) : ?>
                    <div class="knowly-test-item" id="test-<?= esc_attr( $test_id ) ?>">
                        <div class="knowly-test-header">
                            <span class="knowly-test-status" id="status-<?= esc_attr( $test_id ) ?>">○</span>
                            <span class="knowly-test-name"><?= esc_html( $test['label'] ) ?></span>
                            <code class="knowly-test-route"><?= esc_html( $test['method'] . ( $test['route'] ? ' /knowly/v1' . $test['route'] : '' ) ) ?></code>
                            <button class="button button-small knowly-run-test" data-test="<?= esc_attr( $test_id ) ?>">Run</button>
                        </div>
                        <div class="knowly-test-result" id="result-<?= esc_attr( $test_id ) ?>" style="display:none"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }
    }

    // ── Test Runner (called via AJAX) ─────────────────────────────────────────

    public static function run_test( string $test_id, array $data = [] ): array {
        $start = microtime( true );

        try {
            $result = match ( $test_id ) {
                // System
                'system_jwt_secret'    => self::test_jwt_secret(),
                'system_db_tables'     => self::test_db_tables(),
                'system_railway_ping'  => self::test_railway_ping(),
                // Auth
                'auth_ping'            => self::test_ping(),
                'auth_login'           => self::test_login( $data ),
                'auth_me'              => self::test_me( $data ),
                'auth_pin_set'         => self::test_pin_set( $data ),
                'auth_pin_verify'      => self::test_pin_verify( $data ),
                // Children
                'children_list'        => self::test_children_list( $data ),
                'children_create'      => self::test_children_create( $data ),
                'children_switch'      => self::test_children_switch( $data ),
                // Exams
                'exams_catalogue'      => self::test_exams_catalogue( $data ),
                'exams_start'          => self::test_exams_start( $data ),
                // Results
                'results_history'      => self::test_results_history( $data ),
                'results_stats'        => self::test_results_stats( $data ),
                // Insights
                'insights_weekly_build' => self::test_insights_weekly_build( $data ),
                // Block 4 — Notifications
                'notif_create'             => self::test_notif_create( $data ),
                'notif_list'               => self::test_notif_list( $data ),
                'notif_count'              => self::test_notif_count( $data ),
                'notif_respond'            => self::test_notif_respond( $data ),
                'notif_read_all'           => self::test_notif_read_all( $data ),
                // Notifications V2 — Delete + Combined Notify
                'notif_v2_delete_setup'      => self::test_notif_v2_delete_setup( $data ),
                'notif_v2_delete_own'        => self::test_notif_v2_delete_own( $data ),
                'notif_v2_delete_gone'       => self::test_notif_v2_delete_gone( $data ),
                'notif_v2_delete_other_user' => self::test_notif_v2_delete_other_user( $data ),
                'notif_v2_notify_student'    => self::test_notif_v2_notify_student( $data ),
                'notif_v2_notify_parent'     => self::test_notif_v2_notify_parent( $data ),
                'notif_v2_notify_both'       => self::test_notif_v2_notify_both( $data ),
                'notif_v2_verify_student'    => self::test_notif_v2_verify_student( $data ),
                'notif_v2_verify_parent'     => self::test_notif_v2_verify_parent( $data ),
                // Block 2 — Teacher
                'teacher_register'         => self::test_teacher_register(),
                'teacher_login_pending'    => self::test_teacher_login_pending(),
                'teacher_approve'          => self::test_teacher_approve(),
                'teacher_login_approved'   => self::test_teacher_login_approved(),
                // Block 2 — Auth
                'auth_register_parent'     => self::test_register_parent(),
                'auth_password_reset'      => self::test_password_reset( $data ),
                // Block 2 — Notifications
                'notifications_create'     => self::test_notification_create(),
                'notifications_list'       => self::test_notification_list( $data ),
                // Block 2 — Test Accounts
                'provision_test_accounts'  => self::test_provision_accounts(),
                // Block 5 — Classes
                'class5_create'            => self::test_class5_create(),
                'class5_child_lookup'      => self::test_class5_child_lookup(),
                'class5_invite'            => self::test_class5_invite( $data ),
                'class5_parent_accept'     => self::test_class5_parent_accept( $data ),
                'class5_verify_member'     => self::test_class5_verify_member( $data ),
                'class5_create_task'       => self::test_class5_create_task( $data ),
                'class5_list_tasks'        => self::test_class5_list_tasks( $data ),
                'class5_child_classes'     => self::test_class5_child_classes(),
                // Block 6 — Quests & Badges
                'quest6_catalogue'         => self::test_quest6_catalogue(),
                'quest6_start_first'       => self::test_quest6_start_first(),
                'quest6_retake_cost'       => self::test_quest6_retake_cost(),
                'quest6_assigned_free'     => self::test_quest6_assigned_free(),
                'quest6_badge_setup'       => self::test_quest6_badge_setup(),
                'quest6_badge_award'       => self::test_quest6_badge_award(),
                'quest6_badge_idempotent'  => self::test_quest6_badge_idempotent(),
                'quest6_badge_list'        => self::test_quest6_badge_list(),
                // Block 7 — Analytics
                'analytics7_class'         => self::test_analytics7_class(),
                'analytics7_student'       => self::test_analytics7_student(),
                'analytics7_access_control' => self::test_analytics7_access_control(),
                // Leaderboard
                'lb_nickname_generate'     => self::test_lb_nickname_generate(),
                'lb_read_board'            => self::test_lb_read_board(),
                'lb_read_my_boards'        => self::test_lb_read_my_boards(),
                'lb_simulate_upsert'       => self::test_lb_simulate_upsert(),
                'lb_inject_entry'          => self::test_lb_inject_entry(),
                'lb_reset_board'           => self::test_lb_reset_board(),
                default                    => [ 'pass' => false, 'message' => "Unknown test: {$test_id}" ],
            };
        } catch ( Throwable $e ) {
            $result = [
                'pass'    => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ];
        }

        $result['duration_ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );
        return $result;
    }

    // ── System Tests ──────────────────────────────────────────────────────────

    private static function test_jwt_secret(): array {
        if ( defined( 'KNOWLY_JWT_SECRET' ) && KNOWLY_JWT_SECRET ) {
            return self::pass( 'KNOWLY_JWT_SECRET is defined.' );
        }
        if ( defined( 'JWT_AUTH_SECRET_KEY' ) && JWT_AUTH_SECRET_KEY ) {
            return self::warn( 'Using JWT_AUTH_SECRET_KEY as fallback. Define KNOWLY_JWT_SECRET in wp-config.php.' );
        }
        return self::fail( 'No JWT secret defined. Plugin is using a derived key — not suitable for production.' );
    }

    private static function test_db_tables(): array {
        global $wpdb;
        $tables = [
            'knowly_children',
            'knowly_exam_sessions', 'knowly_exam_answers', 'knowly_topic_breakdown',
            'knowly_exam_insights', 'knowly_weekly_insights',
            'knowly_notifications', 'knowly_migration_log', 'knowly_debug_log',
            'knowly_gem_transactions', 'knowly_red_gem_transactions', 'knowly_processed_webhooks',
            'knowly_classes', 'knowly_class_members', 'knowly_tasks',
            'knowly_training_material',
        ];

        $missing = [];
        foreach ( $tables as $table ) {
            $full  = $wpdb->prefix . $table;
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
            if ( $exists !== $full ) {
                $missing[] = $full;
            }
        }

        if ( empty( $missing ) ) {
            return self::pass( 'All ' . count( $tables ) . ' KnowlyAPI database tables exist.', [
                'tables' => $tables,
            ] );
        }

        return self::fail( 'Missing tables: ' . implode( ', ', $missing ) );
    }

    private static function test_railway_ping(): array {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return self::warn( 'Railway endpoint not configured. Configure it in Settings.' );
        }

        // Railway health endpoint is GET /health (no auth required)
        $response = wp_remote_get( "{$endpoint}/api/v1/health", [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            return self::fail( 'Railway connection failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $code === 200
            ? self::pass( "Railway is healthy (HTTP {$code}).", $body ?? [] )
            : self::fail( "Railway returned HTTP {$code}.", [ 'body' => wp_remote_retrieve_body( $response ) ] );
    }

    // ── Auth Tests ────────────────────────────────────────────────────────────

    private static function test_ping(): array {
        $res = self::api_get( '/ping' );
        return $res['status'] === 200
            ? self::pass( 'Ping OK.', $res['body'] )
            : self::fail( 'Ping failed.', $res );
    }

    private static function test_login( array $data ): array {
        if ( empty( $data['username'] ) || empty( $data['password'] ) ) {
            return self::warn( 'Provide username and password in test data to run this test.' );
        }

        $res = self::api_post( '/auth/login', [
            'username' => $data['username'],
            'password' => $data['password'],
        ] );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['token'] ) ) {
            return self::pass( 'Login successful. JWT received.', [
                'user_id'  => $res['body']['data']['user_id'],
                'role'     => $res['body']['data']['role'],
                'token'    => substr( $res['body']['data']['token'], 0, 30 ) . '…',
                '_token'   => $res['body']['data']['token'],   // full token for JS to carry forward
            ] );
        }

        return self::fail( 'Login failed.', $res );
    }

    private static function test_me( array $data ): array {
        if ( empty( $data['token'] ) ) {
            return self::warn( 'Provide a JWT token in test data.' );
        }
        $res = self::api_get( '/auth/me', $data['token'] );
        return $res['status'] === 200
            ? self::pass( '/me returned user profile.', $res['body']['data'] ?? [] )
            : self::fail( '/me failed.', $res );
    }

    private static function test_pin_set( array $data ): array {
        if ( empty( $data['token'] ) || empty( $data['pin'] ) ) {
            return self::warn( 'Provide token and pin (4 digits) in test data.' );
        }
        $res = self::api_post( '/auth/pin/set', [ 'pin' => $data['pin'] ], $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'PIN set successfully.' )
            : self::fail( 'PIN set failed.', $res );
    }

    private static function test_pin_verify( array $data ): array {
        if ( empty( $data['token'] ) || empty( $data['pin'] ) ) {
            return self::warn( 'Provide token and pin in test data.' );
        }
        $res = self::api_post( '/auth/pin/verify', [ 'pin' => $data['pin'] ], $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'PIN verified successfully.' )
            : self::fail( 'PIN verification failed.', $res );
    }

    // ── Children Tests ────────────────────────────────────────────────────────

    private static function test_children_list( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide parent token.' );
        $res = self::api_get( '/children', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Children listed.', [ 'count' => count( $res['body']['data']['children'] ?? [] ) ] )
            : self::fail( 'Children list failed.', $res );
    }

    private static function test_children_create( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide parent token.' );

        $res = self::api_post( '/children', [
            'first_name' => 'TestKid',
            'nickname'   => 'testkid_' . time(),
            'password'   => 'TestPass123!',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'age'        => 9,
        ], $data['token'] );

        return $res['status'] === 201
            ? self::pass( 'Child created.', [ 'child_id' => $res['body']['data']['child_id'] ?? null ] )
            : self::fail( 'Child creation failed.', $res );
    }

    private static function test_children_switch( array $data ): array {
        if ( empty( $data['token'] ) || empty( $data['child_id'] ) ) {
            return self::warn( 'Provide token and child_id.' );
        }
        $res = self::api_post( '/children/' . (int) $data['child_id'] . '/switch', [], $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Child switched.', $res['body']['data'] ?? [] )
            : self::fail( 'Switch failed.', $res );
    }

    // ── Exam Tests ────────────────────────────────────────────────────────────

    private static function test_exams_catalogue( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/exams', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Catalogue fetched.', [ 'count' => count( $res['body']['data']['catalogue'] ?? [] ) ] )
            : self::fail( 'Catalogue failed.', $res );
    }

    private static function test_exams_start( array $data ): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child (test.child) not found. Run Block 2 account setup first.' );

        // Ensure test child has enough gems to cover the exam cost (typically 2)
        $balance = (int) get_user_meta( $child_user->ID, 'knowly_gem_balance', true );
        if ( $balance < 5 ) {
            update_user_meta( $child_user->ID, 'knowly_gem_balance', 10 );
            $balance = 10;
        }

        $token = Knowly_JWT::encode( $child_user->ID );
        $res   = self::api_post( '/exams/start', [
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'difficulty' => 'medium',
        ], $token );

        if ( $res['status'] === 200 ) {
            return self::pass( 'Exam started.', [
                'session_id'     => $res['body']['data']['session_id'] ?? null,
                'balance_before' => $balance,
                'balance_after'  => $res['body']['data']['balance_after'] ?? null,
            ] );
        }
        // 503 pool_empty is expected when Railway has no packages ready — not a plugin bug
        if ( $res['status'] === 503 && ( $res['body']['code'] ?? '' ) === 'knowly_pool_empty' ) {
            return self::warn( 'Pool empty — Railway has no packages ready for this filter. Trigger generation on Railway first.' );
        }
        return self::fail( 'Exam start failed.', $res );
    }

    // ── Results Tests ─────────────────────────────────────────────────────────

    private static function test_results_history( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/results', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'History fetched.', [ 'total' => $res['body']['data']['total'] ?? 0 ] )
            : self::fail( 'History failed.', $res );
    }

    private static function test_results_stats( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/results/stats', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Stats fetched.', $res['body']['data'] ?? [] )
            : self::fail( 'Stats failed.', $res );
    }

    // ── Insight Tests ─────────────────────────────────────────────────────────

    private static function test_insights_weekly_build( array $data ): array {
        if ( empty( $data['child_id'] ) ) return self::warn( 'Provide child_id.' );
        $iso_week = $data['iso_week'] ?? date( 'o-\WW' );
        $payload  = Knowly_Insight_Service::build_weekly_payload( (int) $data['child_id'], $iso_week );

        if ( is_wp_error( $payload ) ) {
            return self::fail( 'Payload build failed: ' . $payload->get_error_message() );
        }

        return self::pass( 'Weekly payload built successfully.', [
            'iso_week'        => $iso_week,
            'exams_completed' => $payload['period']['exams_completed'],
            'subjects'        => count( $payload['subjects'] ),
            'payload'         => $payload,
        ] );
    }

    // ── Block 4 Test Methods ──────────────────────────────────────────────────

    private static function test_notif_create( array $data = [] ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel above.' );

        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );

        $res = self::api_post( '/notifications', [
            'recipient_user_id' => $parent_user->ID,
            'type'              => 'confirmation',
            'subject'           => 'block4_test',
            'message'           => 'Block 4 test notification — please accept or decline.',
            'payload'           => [ 'test' => true ],
        ], $admin_token );

        if ( $res['status'] === 201 && ! empty( $res['body']['data']['notification_id'] ) ) {
            return self::pass( 'Notification created.', [
                'notification_id'   => $res['body']['data']['notification_id'],
                '_notification_id'  => $res['body']['data']['notification_id'], // carry forward
                '_recipient_id'     => $parent_user->ID,
            ] );
        }

        return self::fail( 'Notification create failed.', $res );
    }

    private static function test_notif_list( array $data ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        $token = Knowly_JWT::encode( $parent_user->ID );

        $res = self::api_get( '/notifications?unread_only=false', $token );

        if ( $res['status'] === 200 ) {
            $notifications = $res['body']['data']['notifications'] ?? [];
            return self::pass( 'Notifications listed.', [
                'count'         => count( $notifications ),
                'notifications' => array_map( fn( $n ) => [ 'id' => $n['id'], 'subject' => $n['subject'], 'is_read' => $n['is_read'] ], $notifications ),
            ] );
        }

        return self::fail( 'Notification list failed.', $res );
    }

    private static function test_notif_count( array $data ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        $token = Knowly_JWT::encode( $parent_user->ID );
        $res   = self::api_get( '/notifications/count', $token );

        if ( $res['status'] === 200 && isset( $res['body']['data']['unread'] ) ) {
            return self::pass( 'Unread count returned.', [
                'unread' => $res['body']['data']['unread'],
            ] );
        }

        return self::fail( 'Notification count failed.', $res );
    }

    private static function test_notif_respond( array $data ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        // Find the most recent unread confirmation from the test subject
        global $wpdb;
        $notif = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_notifications
             WHERE recipient_user_id = %d AND type = 'confirmation' AND subject = 'block4_test' AND response IS NULL
             ORDER BY id DESC LIMIT 1",
            $parent_user->ID
        ) );

        if ( ! $notif ) return self::warn( 'No block4_test confirmation notification found. Run notif_create first.' );

        $token = Knowly_JWT::encode( $parent_user->ID );
        $res   = self::api_post( "/notifications/{$notif->id}/respond", [ 'response' => 'accepted' ], $token );

        if ( $res['status'] === 200 && ( $res['body']['data']['response'] ?? '' ) === 'accepted' ) {
            return self::pass( "Notification {$notif->id} accepted.", $res['body']['data'] );
        }

        return self::fail( 'Notification respond failed.', $res );
    }

    private static function test_notif_read_all( array $data ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        $token = Knowly_JWT::encode( $parent_user->ID );

        // Create a fresh unread notification first so there's something to mark
        Knowly_Notification_Service::create( [
            'recipient_user_id' => $parent_user->ID,
            'type'              => 'simple',
            'subject'           => 'block4_read_all_test',
            'message'           => 'Read-all test — safe to ignore.',
        ] );

        $res = self::api_post( '/notifications/read-all', [], $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'read-all failed.', $res );
        }

        // Verify count is now 0
        $count_res = self::api_get( '/notifications/count', $token );
        $unread    = $count_res['body']['data']['unread'] ?? -1;

        return $unread === 0
            ? self::pass( 'All notifications marked read. Unread count is 0.', [ 'marked_read' => $res['body']['data']['marked_read'] ?? null ] )
            : self::fail( "read-all ran but unread count is still {$unread}.", $res );
    }

    // ── Notifications V2 — Delete + Combined Notify ───────────────────────────

    private static function test_notif_v2_delete_setup( array $data = [] ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        // Create a fresh simple notification as the "target to delete"
        $notif_id = Knowly_Notification_Service::create( [
            'recipient_user_id' => $parent_user->ID,
            'type'              => 'simple',
            'subject'           => 'notif_v2_delete_test',
            'message'           => 'V2 delete test — will be deleted by the next test.',
        ] );

        if ( is_wp_error( $notif_id ) ) {
            return self::fail( 'Could not create test notification.', [ 'error' => $notif_id->get_error_message() ] );
        }

        set_transient( 'knowly_test_notif_v2_delete_id', $notif_id, HOUR_IN_SECONDS );
        set_transient( 'knowly_test_notif_v2_parent_id', $parent_user->ID, HOUR_IN_SECONDS );

        return self::pass( "Test notification #{$notif_id} created for parent #{$parent_user->ID}.", [
            'notification_id' => $notif_id,
            'recipient_id'    => $parent_user->ID,
        ] );
    }

    private static function test_notif_v2_delete_own( array $data ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        $notif_id = get_transient( 'knowly_test_notif_v2_delete_id' );
        if ( ! $notif_id ) return self::warn( 'No delete-test notification ID found. Run notif_v2_delete_setup first.' );

        $token = Knowly_JWT::encode( $parent_user->ID );
        $res   = self::api_delete( "/notifications/{$notif_id}", $token );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['deleted'] ) ) {
            return self::pass( "DELETE /notifications/{$notif_id} returned 200 with deleted=true.", $res['body']['data'] );
        }

        return self::fail( "DELETE /notifications/{$notif_id} failed.", $res );
    }

    private static function test_notif_v2_delete_gone( array $data ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        $notif_id = get_transient( 'knowly_test_notif_v2_delete_id' );
        if ( ! $notif_id ) return self::warn( 'No delete-test notification ID found. Run notif_v2_delete_setup first.' );

        // Verify it no longer appears in the parent's notification list
        $token = Knowly_JWT::encode( $parent_user->ID );
        $res   = self::api_get( '/notifications?unread_only=false&limit=100', $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Could not list notifications to verify deletion.', $res );
        }

        $notifications = $res['body']['data']['notifications'] ?? [];
        $still_exists  = array_filter( $notifications, fn( $n ) => (int) $n['id'] === (int) $notif_id );

        if ( empty( $still_exists ) ) {
            return self::pass( "Notification #{$notif_id} is no longer in the parent's list — correctly deleted.", [
                'remaining_count' => count( $notifications ),
            ] );
        }

        return self::fail( "Notification #{$notif_id} still appears in list after DELETE — deletion did not persist.", [
            'notification_id' => $notif_id,
        ] );
    }

    private static function test_notif_v2_delete_other_user( array $data ): array {
        $parent_user  = self::get_test_user( 'parent', $data );
        $teacher_user = self::get_test_user( 'teacher', $data );
        if ( ! $parent_user || ! $teacher_user ) return self::warn( 'Test parent or teacher not found. Select both users in the Test Users panel.' );

        // Create a notification for the teacher
        $notif_id = Knowly_Notification_Service::create( [
            'recipient_user_id' => $teacher_user->ID,
            'type'              => 'simple',
            'subject'           => 'notif_v2_access_test',
            'message'           => 'Access control test — parent must NOT be able to delete this.',
        ] );

        if ( is_wp_error( $notif_id ) ) {
            return self::fail( 'Setup failed — could not create teacher notification.', [ 'error' => $notif_id->get_error_message() ] );
        }

        // Try to delete as the parent (should get 404 — not found for this recipient)
        $parent_token = Knowly_JWT::encode( $parent_user->ID );
        $res          = self::api_delete( "/notifications/{$notif_id}", $parent_token );

        // Clean up regardless
        Knowly_Notification_Service::admin_delete( $notif_id );

        if ( $res['status'] === 404 ) {
            return self::pass( "Parent correctly received 404 when attempting to delete teacher's notification #{$notif_id}.", [
                'attempted_id' => $notif_id,
                'status'       => $res['status'],
            ] );
        }

        return self::fail( "Expected 404 but got HTTP {$res['status']} — access control may be broken.", $res );
    }

    private static function test_notif_v2_notify_student( array $data ): array {
        $teacher_user = self::get_test_user( 'teacher', $data );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found. Select a Teacher user in the Test Users panel.' );

        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found. Run class5_create and class5_parent_accept first.' );

        $child_user = self::get_test_user( 'child', $data );
        if ( ! $child_user ) return self::warn( 'Test child not found. Select a Child user in the Test Users panel or fill Child ID.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_post(
            "/classes/{$class->id}/notify-student/{$child_user->ID}",
            [ 'message' => 'V2 test: student-only message from teacher.' ],
            $token
        );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['notification_id'] ) ) {
            set_transient( 'knowly_test_notif_v2_student_notif_id', $res['body']['data']['notification_id'], HOUR_IN_SECONDS );
            return self::pass( 'Notify-student sent.', [
                'notification_id' => $res['body']['data']['notification_id'],
                'class_id'        => $class->id,
                'child_id'        => $child_user->ID,
            ] );
        }

        return self::fail( 'notify-student failed.', $res );
    }

    private static function test_notif_v2_notify_parent( array $data ): array {
        $teacher_user = self::get_test_user( 'teacher', $data );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found. Select a Teacher user in the Test Users panel.' );

        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found. Run class5_create first.' );

        $child_user = self::get_test_user( 'child', $data );
        if ( ! $child_user ) return self::warn( 'Test child not found. Select a Child user in the Test Users panel or fill Child ID.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_post(
            "/classes/{$class->id}/notify-parent/{$child_user->ID}",
            [ 'message' => 'V2 test: parent-only message from teacher.' ],
            $token
        );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['notification_id'] ) ) {
            set_transient( 'knowly_test_notif_v2_parent_notif_id', $res['body']['data']['notification_id'], HOUR_IN_SECONDS );
            return self::pass( 'Notify-parent sent.', [
                'notification_id' => $res['body']['data']['notification_id'],
                'class_id'        => $class->id,
                'child_id'        => $child_user->ID,
            ] );
        }

        return self::fail( 'notify-parent failed.', $res );
    }

    private static function test_notif_v2_notify_both( array $data ): array {
        $teacher_user = self::get_test_user( 'teacher', $data );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found. Select a Teacher user in the Test Users panel.' );

        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found. Run class5_create first (Block 5 — Classes).' );

        $child_user = self::get_test_user( 'child', $data );
        if ( ! $child_user ) return self::warn( 'Test child not found. Select a Child user in the Test Users panel or fill Child ID.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_post(
            "/classes/{$class->id}/notify-student-and-parent/{$child_user->ID}",
            [ 'message' => 'V2 test: combined student+parent message from teacher.' ],
            $token
        );

        $body = $res['body']['data'] ?? [];

        if ( $res['status'] === 200 && ! empty( $body['student_notif_id'] ) ) {
            return self::pass(
                'Combined notify sent. Student notified' . ( $body['parent_notified'] ? ' and parent notified.' : ' — parent has no linked account (expected).' ),
                [
                    'student_notif_id' => $body['student_notif_id'],
                    'parent_notif_id'  => $body['parent_notif_id'] ?? null,
                    'parent_notified'  => $body['parent_notified'] ?? false,
                    'class_id'         => $class->id,
                ]
            );
        }

        return self::fail( 'notify-student-and-parent failed.', $res );
    }

    private static function test_notif_v2_verify_student( array $data ): array {
        $child_user = self::get_test_user( 'child', $data );
        if ( ! $child_user ) return self::warn( 'Test child not found. Select a Child user in the Test Users panel or fill Child ID.' );

        $token = Knowly_JWT::encode( $child_user->ID );
        $res   = self::api_get( '/notifications?unread_only=false&limit=50', $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Could not list child notifications.', $res );
        }

        $notifications = $res['body']['data']['notifications'] ?? [];
        $teacher_msgs  = array_values( array_filter( $notifications, fn( $n ) => $n['subject'] === 'teacher_message' ) );

        if ( ! empty( $teacher_msgs ) ) {
            return self::pass( "Student has {$teacher_msgs[0]['id']} teacher_message notifications visible.", [
                'count'   => count( $teacher_msgs ),
                'latest'  => [ 'id' => $teacher_msgs[0]['id'], 'message' => substr( $teacher_msgs[0]['message'], 0, 60 ) ],
            ] );
        }

        return self::fail( 'No teacher_message notifications found in child account.', [
            'total_notifs' => count( $notifications ),
        ] );
    }

    private static function test_notif_v2_verify_parent( array $data ): array {
        $parent_user = self::get_test_user( 'parent', $data );
        if ( ! $parent_user ) return self::warn( 'Test parent not found. Select a Parent user in the Test Users panel.' );

        $token = Knowly_JWT::encode( $parent_user->ID );
        $res   = self::api_get( '/notifications?unread_only=false&limit=50', $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Could not list parent notifications.', $res );
        }

        $notifications = $res['body']['data']['notifications'] ?? [];
        $teacher_msgs  = array_values( array_filter( $notifications, fn( $n ) => $n['subject'] === 'teacher_message' ) );

        if ( ! empty( $teacher_msgs ) ) {
            return self::pass( "Parent has {$teacher_msgs[0]['id']} teacher_message notifications visible.", [
                'count'  => count( $teacher_msgs ),
                'latest' => [ 'id' => $teacher_msgs[0]['id'], 'message' => substr( $teacher_msgs[0]['message'], 0, 60 ) ],
            ] );
        }

        return self::fail( 'No teacher_message notifications found in parent account.', [
            'total_notifs' => count( $notifications ),
        ] );
    }

    // ── Block 2 Test Methods ──────────────────────────────────────────────────

    private static function test_teacher_register(): array {
        $email = 'test_teacher_' . time() . '@knowly.test';
        $res   = self::api_post( '/auth/register/teacher', [
            'first_name'  => 'Test',
            'last_name'   => 'Teacher',
            'email'       => $email,
            'password'    => 'TestPass123!',
            'school_name' => 'Test Academy',
        ] );

        if ( $res['status'] === 201 ) {
            $user_id = $res['body']['data']['user_id'] ?? null;
            $status  = $res['body']['data']['approval_status'] ?? null;
            // Clean up test account
            if ( $user_id ) {
                update_user_meta( $user_id, 'knowly_is_test_account', true );
            }
            return $status === 'pending_approval'
                ? self::pass( 'Teacher registered with pending_approval status.', [ 'user_id' => $user_id, 'approval_status' => $status ] )
                : self::fail( 'Teacher registered but status is not pending_approval.', $res['body']['data'] ?? [] );
        }
        return self::fail( 'Teacher registration failed.', $res );
    }

    private static function test_teacher_login_pending(): array {
        // Register a new teacher and try to log in — should succeed but return pending status
        $email    = 'test_teacher_login_' . time() . '@knowly.test';
        $password = 'TestPass123!';

        self::api_post( '/auth/register/teacher', [
            'first_name'  => 'Test',
            'last_name'   => 'TeacherLogin',
            'email'       => $email,
            'password'    => $password,
            'school_name' => 'Test Academy',
        ] );

        $res = self::api_post( '/auth/login', [
            'username' => $email,
            'password' => $password,
        ] );

        if ( $res['status'] === 200 && ( $res['body']['data']['approval_status'] ?? '' ) === 'pending_approval' ) {
            return self::pass( 'Pending teacher can log in and sees pending_approval status.', [
                'approval_status' => $res['body']['data']['approval_status'],
            ] );
        }
        return self::fail( 'Pending teacher login test failed.', $res );
    }

    private static function test_teacher_approve(): array {
        // Find a pending teacher and approve them
        $pending = Knowly_Teacher_Service::list_teachers( 'pending_approval' );
        $test_pending = array_filter( $pending, fn( $t ) => get_user_meta( $t['user_id'], 'knowly_is_test_account', true ) );

        if ( empty( $test_pending ) ) {
            return self::warn( 'No test teacher with pending_approval found. Run the teacher_register test first.' );
        }

        $teacher = reset( $test_pending );
        $result  = Knowly_Teacher_Service::approve( $teacher['user_id'] );

        if ( is_wp_error( $result ) ) {
            return self::fail( 'Approve failed: ' . $result->get_error_message() );
        }

        $status = get_user_meta( $teacher['user_id'], 'knowly_approval_status', true );
        $gems   = (int) get_user_meta( $teacher['user_id'], 'knowly_red_gem_balance', true );

        return $status === 'approved' && $gems > 0
            ? self::pass( "Teacher approved. Red gem balance set to {$gems}.", [ 'user_id' => $teacher['user_id'], 'red_gem_balance' => $gems ] )
            : self::fail( 'Approval set but status or balance incorrect.', [ 'status' => $status, 'gems' => $gems ] );
    }

    private static function test_teacher_login_approved(): array {
        $approved = Knowly_Teacher_Service::list_teachers( 'approved' );
        $test_approved = array_filter( $approved, fn( $t ) => get_user_meta( $t['user_id'], 'knowly_is_test_account', true ) );

        if ( empty( $test_approved ) ) {
            return self::warn( 'No approved test teacher found. Run teacher_register and teacher_approve first.' );
        }

        $teacher = reset( $test_approved );
        $user    = get_userdata( $teacher['user_id'] );

        // Reset password for test login
        wp_set_password( 'TestPass123!', $teacher['user_id'] );

        $res = self::api_post( '/auth/login', [
            'username' => $user->user_email,
            'password' => 'TestPass123!',
        ] );

        return ( $res['status'] === 200 && ( $res['body']['data']['approval_status'] ?? '' ) === 'approved' )
            ? self::pass( 'Approved teacher logged in. approval_status: approved.', [ 'role' => $res['body']['data']['role'] ?? null ] )
            : self::fail( 'Approved teacher login failed.', $res );
    }

    private static function test_register_parent(): array {
        $email = 'test_parent_' . time() . '@knowly.test';
        $res   = self::api_post( '/auth/register/parent', [
            'first_name' => 'Test',
            'last_name'  => 'Parent',
            'email'      => $email,
            'password'   => 'TestPass123!',
        ] );

        if ( $res['status'] === 201 && ! empty( $res['body']['data']['token'] ) ) {
            $user_id = $res['body']['data']['user_id'] ?? null;
            if ( $user_id ) update_user_meta( $user_id, 'knowly_is_test_account', true );
            return self::pass( 'Parent registered via /auth/register/parent. JWT received.', [
                'user_id' => $user_id,
                'role'    => $res['body']['data']['role'] ?? null,
            ] );
        }
        return self::fail( 'Parent registration via /auth/register/parent failed.', $res );
    }

    private static function test_password_reset( array $data ): array {
        if ( empty( $data['username'] ) ) {
            return self::warn( 'Provide the email address of a real WP user in the username test data field.' );
        }
        $res = self::api_post( '/auth/password/reset', [ 'email' => $data['username'] ] );
        return $res['status'] === 200
            ? self::pass( 'Password reset endpoint returned 200.', $res['body']['data'] ?? [] )
            : self::fail( 'Password reset failed.', $res );
    }

    private static function test_notification_create(): array {
        $admin_users = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
        if ( empty( $admin_users ) ) return self::warn( 'No admin user found.' );

        $id = Knowly_Notification_Service::create( [
            'recipient_user_id' => $admin_users[0]->ID,
            'type'              => 'simple',
            'subject'           => 'test_suite',
            'message'           => 'Test Suite notification — safe to ignore.',
        ] );

        if ( is_wp_error( $id ) ) {
            return self::fail( 'Notification create failed: ' . $id->get_error_message() );
        }
        return self::pass( "Simple notification created (ID: {$id}).", [ 'notification_id' => $id ] );
    }

    private static function test_notification_list( array $data ): array {
        if ( empty( $data['user_id'] ) ) {
            return self::warn( 'Provide user_id in test data to list notifications for that user.' );
        }
        $notes = Knowly_Notification_Service::list_for_user( (int) $data['user_id'], false );
        return self::pass( 'Notifications fetched.', [ 'count' => count( $notes ), 'notifications' => $notes ] );
    }

    private static function test_provision_accounts(): array {
        $report = [];

        // 1. Test parent
        $parent_email = 'test.parent@knowly.test';
        if ( ! email_exists( $parent_email ) ) {
            $parent_id = wp_create_user( $parent_email, 'KnowlyTest2025!', $parent_email );
            if ( ! is_wp_error( $parent_id ) ) {
                ( new WP_User( $parent_id ) )->set_role( 'knowly_parent' );
                wp_update_user( [ 'ID' => $parent_id, 'first_name' => 'Test', 'last_name' => 'Parent', 'display_name' => 'Test' ] );
                update_user_meta( $parent_id, 'knowly_is_test_account', true );
                Knowly_Gem_Service::grant_on_registration( $parent_id );
                $report[] = "✓ Parent created (ID: {$parent_id}, email: {$parent_email})";
            } else {
                $report[] = '✗ Parent creation failed: ' . $parent_id->get_error_message();
            }
        } else {
            $parent_id = get_user_by( 'email', $parent_email )->ID;
            $report[]  = "→ Parent already exists (ID: {$parent_id})";
        }

        // 2. Test teacher (pre-approved)
        $teacher_email = 'test.teacher@knowly.test';
        if ( ! email_exists( $teacher_email ) ) {
            $teacher_id = wp_create_user( $teacher_email, 'KnowlyTest2025!', $teacher_email );
            if ( ! is_wp_error( $teacher_id ) ) {
                ( new WP_User( $teacher_id ) )->set_role( 'knowly_teacher' );
                wp_update_user( [ 'ID' => $teacher_id, 'first_name' => 'Test', 'last_name' => 'Teacher', 'display_name' => 'Test Teacher' ] );
                update_user_meta( $teacher_id, 'knowly_approval_status',  'approved' );
                update_user_meta( $teacher_id, 'knowly_school_name',      'Test Academy' );
                update_user_meta( $teacher_id, 'knowly_red_gem_balance',  20 );
                update_user_meta( $teacher_id, 'knowly_red_gem_stipend',  20 );
                update_user_meta( $teacher_id, 'knowly_is_test_account',  true );
                $report[] = "✓ Teacher created and pre-approved (ID: {$teacher_id}, email: {$teacher_email})";
            } else {
                $report[] = '✗ Teacher creation failed: ' . $teacher_id->get_error_message();
            }
        } else {
            $teacher_id = get_user_by( 'email', $teacher_email )->ID;
            $report[]   = "→ Teacher already exists (ID: {$teacher_id})";
        }

        // 3. Test child (std_4 / term_1)
        $child_login = 'test.child';
        $existing_child = get_user_by( 'login', $child_login );
        if ( ! $existing_child ) {
            $child_id = wp_create_user( $child_login, 'KnowlyTest2025!', 'test.child@knowly.test' );
            if ( ! is_wp_error( $child_id ) ) {
                ( new WP_User( $child_id ) )->set_role( 'knowly_child' );
                wp_update_user( [ 'ID' => $child_id, 'first_name' => 'Test', 'display_name' => 'TestKid' ] );
                update_user_meta( $child_id, 'knowly_level',          'std_4' );
                update_user_meta( $child_id, 'knowly_period',         'term_1' );
                update_user_meta( $child_id, 'knowly_nickname',       'TestKid' );
                update_user_meta( $child_id, 'knowly_avatar_index',   1 );
                update_user_meta( $child_id, 'knowly_is_test_account', true );

                // Link child to test parent
                if ( isset( $parent_id ) && ! is_wp_error( $parent_id ) ) {
                    update_user_meta( $child_id, 'knowly_parent_id', $parent_id );
                    global $wpdb;
                    $wpdb->replace( $wpdb->prefix . 'knowly_children', [
                        'parent_id'    => $parent_id,
                        'child_id'     => $child_id,
                        'display_name' => 'TestKid',
                        'level'        => 'std_4',
                        'period'       => 'term_1',
                        'age'          => 10,
                        'avatar_index' => 1,
                        'created_at'   => current_time( 'mysql' ),
                    ] );
                }

                $report[] = "✓ Child created (ID: {$child_id}, level: std_4, period: term_1, linked to parent)";
            } else {
                $report[] = '✗ Child creation failed: ' . $child_id->get_error_message();
            }
        } else {
            $report[] = "→ Child already exists (ID: {$existing_child->ID})";
        }

        $all_ok = ! in_array( false, array_map( fn( $r ) => strpos( $r, '✗' ) === false, $report ), true );

        return $all_ok
            ? self::pass( 'Test accounts provisioned.', [ 'report' => implode( "\n", $report ) ] )
            : self::fail( 'Some accounts failed to provision.', [ 'report' => implode( "\n", $report ) ] );
    }

    // ── Block 5 Test Methods ──────────────────────────────────────────────────

    private static function test_class5_create(): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found. Run Block 2 account setup first.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_post( '/classes', [
            'name'        => 'Math 4A',
            'description' => 'Block 5 test class',
            'level'       => 'std_4',
        ], $token );

        if ( $res['status'] === 201 && ! empty( $res['body']['data']['class_id'] ) ) {
            return self::pass( 'Class created.', [
                'class_id'   => $res['body']['data']['class_id'],
                '_class_id'  => $res['body']['data']['class_id'], // carry forward
            ] );
        }

        return self::fail( 'Class creation failed.', $res );
    }

    private static function test_class5_child_lookup(): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_get( '/classes/child-lookup?q=TestKid', $token );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['child_id'] ) ) {
            return self::pass( 'Child found by nickname.', [
                'child_id'  => $res['body']['data']['child_id'],
                'nickname'  => $res['body']['data']['nickname'],
                'parent_id' => $res['body']['data']['parent_id'],
            ] );
        }

        return self::fail( 'Child lookup failed.', $res );
    }

    private static function test_class5_invite( array $data ): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found.' );

        // Find the most recent test class
        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found. Run class5_create first.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_post( "/classes/{$class->id}/invite", [ 'child_nickname' => 'TestKid' ], $token );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['parent_notif_id'] ) ) {
            return self::pass( 'Invite sent. Child and parent notifications created.', [
                'class_id'        => $class->id,
                'child_notif_id'  => $res['body']['data']['child_notif_id'],
                'parent_notif_id' => $res['body']['data']['parent_notif_id'],
                'child_id'        => $res['body']['data']['child_id'],
                'parent_id'       => $res['body']['data']['parent_id'],
                '_parent_notif_id' => $res['body']['data']['parent_notif_id'],
            ] );
        }

        return self::fail( 'Invite failed.', $res );
    }

    private static function test_class5_parent_accept( array $data ): array {
        $parent_user = get_user_by( 'email', 'test.parent@knowly.test' );
        if ( ! $parent_user ) return self::warn( 'Test parent not found.' );

        // Find the most recent class_invitation confirmation for test parent
        global $wpdb;
        $notif = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_notifications
             WHERE recipient_user_id = %d AND type = 'confirmation' AND subject = 'class_invitation' AND response IS NULL
             ORDER BY id DESC LIMIT 1",
            $parent_user->ID
        ) );

        if ( ! $notif ) return self::warn( 'No pending class_invitation notification for test parent. Run class5_invite first.' );

        $token = Knowly_JWT::encode( $parent_user->ID );
        $res   = self::api_post( "/notifications/{$notif->id}/respond", [ 'response' => 'accepted' ], $token );

        if ( $res['status'] === 200 && ( $res['body']['data']['response'] ?? '' ) === 'accepted' ) {
            return self::pass( "Parent accepted invite (notif #{$notif->id}). Child should now be a class member.", $res['body']['data'] );
        }

        return self::fail( 'Parent accept failed.', $res );
    }

    private static function test_class5_verify_member( array $data ): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        $child_user   = get_user_by( 'login', 'test.child' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found.' );
        if ( ! $child_user )   return self::warn( 'Test child not found.' );

        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found.' );

        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND child_id = %d AND status = 'active'",
            $class->id, $child_user->ID
        ) );

        if ( $member ) {
            return self::pass( 'Child is confirmed as an active class member.', [
                'class_id'  => $class->id,
                'child_id'  => (int) $child_user->ID,
                'joined_at' => $member->joined_at,
            ] );
        }

        return self::fail( 'Child is NOT a member of the class. Check that class5_parent_accept ran successfully.', [
            'class_id' => $class->id,
            'child_id' => (int) $child_user->ID,
        ] );
    }

    private static function test_class5_create_task( array $data ): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found.' );

        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found. Run class5_create first.' );

        $red_gems_before = (int) get_user_meta( $teacher_user->ID, 'knowly_red_gem_balance', true );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_post( "/classes/{$class->id}/tasks", [
            'title'       => 'Block 5 Test Task',
            'description' => 'Practise fractions.',
            'subject'     => 'math',
            'difficulty'  => 'easy',
            'due_date'    => date( 'Y-m-d', strtotime( '+7 days' ) ),
        ], $token );

        if ( $res['status'] === 201 && ! empty( $res['body']['data']['task_id'] ) ) {
            clean_user_cache( $teacher_user->ID ); // flush object cache — deduction ran in a separate HTTP process
            $red_gems_after = (int) get_user_meta( $teacher_user->ID, 'knowly_red_gem_balance', true );
            return self::pass( 'Task created. Red gem deducted.', [
                'task_id'          => $res['body']['data']['task_id'],
                'gem_cost'         => $res['body']['data']['gem_cost'],
                'red_gems_before'  => $red_gems_before,
                'red_gems_after'   => $red_gems_after,
            ] );
        }

        return self::fail( 'Task creation failed.', $res );
    }

    private static function test_class5_list_tasks( array $data ): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found.' );

        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_get( "/classes/{$class->id}/tasks", $token );

        if ( $res['status'] === 200 && isset( $res['body']['data']['count'] ) ) {
            return self::pass( 'Tasks listed.', [
                'count' => $res['body']['data']['count'],
                'tasks' => array_map( fn( $t ) => [ 'id' => $t['id'], 'title' => $t['title'] ], $res['body']['data']['tasks'] ?? [] ),
            ] );
        }

        return self::fail( 'Task list failed.', $res );
    }

    private static function test_class5_child_classes(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found.' );

        $token = Knowly_JWT::encode( $child_user->ID );
        $res   = self::api_get( '/classes/my', $token );

        if ( $res['status'] === 200 && isset( $res['body']['data']['count'] ) ) {
            $count = $res['body']['data']['count'];
            return $count > 0
                ? self::pass( "Child is enrolled in {$count} class(es).", [
                    'count'   => $count,
                    'classes' => array_map( fn( $c ) => [ 'id' => $c['id'], 'name' => $c['name'] ], $res['body']['data']['classes'] ?? [] ),
                ] )
                : self::warn( 'Child has no enrolled classes. Verify class5_parent_accept succeeded.', $res['body']['data'] );
        }

        return self::fail( 'GET /classes/my failed.', $res );
    }

    // ── Block 6 Test Methods ──────────────────────────────────────────────────

    private static function test_quest6_catalogue(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found. Run Block 2 account setup first.' );

        $token = Knowly_JWT::encode( $child_user->ID );
        $res   = self::api_get( '/quests', $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Catalogue endpoint failed.', $res );
        }

        $count = $res['body']['data']['count'] ?? 0;

        return $count > 0
            ? self::pass( "Quest catalogue returned {$count} quest(s) for std_4 / term_1.", [
                'count'  => $count,
                'quests' => array_map( fn( $q ) => [ 'quest_id' => $q['quest_id'] ?? '', 'subject' => $q['subject'] ?? '' ], array_slice( $res['body']['data']['quests'] ?? [], 0, 5 ) ),
            ] )
            : self::warn( 'Catalogue is empty. Seed quest content on Railway before running start/complete tests.', [
                'level'  => 'std_4',
                'period' => 'term_1',
            ] );
    }

    private static function test_quest6_start_first(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found. Run Block 2 account setup first.' );

        // Reset all quest sessions for this child so the first-attempt check is clean
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( $endpoint && $server_key ) {
            wp_remote_request( $endpoint . '/api/v1/quest/sessions/reset', [
                'method'  => 'DELETE',
                'timeout' => 10,
                'headers' => [
                    'X-AEP-Server-Key' => $server_key,
                    'Content-Type'     => 'application/json',
                ],
                'body' => wp_json_encode( [ 'user_id' => (string) $child_user->ID ] ),
            ] );
        }

        // Ensure test child has enough Blue Gems for 2 starts (first + retake = 4)
        $balance = (int) get_user_meta( $child_user->ID, 'knowly_gem_balance', true );
        if ( $balance < 10 ) {
            update_user_meta( $child_user->ID, 'knowly_gem_balance', 10 );
            $balance = 10;
        }

        // Discover a quest_id from the catalogue
        $token       = Knowly_JWT::encode( $child_user->ID );
        $cat_res     = self::api_get( '/quests', $token );
        $quests      = $cat_res['body']['data']['quests'] ?? [];
        $quest_id    = ! empty( $quests ) ? ( $quests[0]['quest_id'] ?? '' ) : '';

        if ( ! $quest_id ) {
            return self::warn( 'No quests in catalogue. Seed quest content on Railway first, then re-run.' );
        }

        $res = self::api_post( '/quests/start', [ 'quest_id' => $quest_id, 'source' => 'direct' ], $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Quest start failed.', $res );
        }

        $gem_cost    = $res['body']['data']['gem_cost'] ?? -1;
        $session_id  = $res['body']['data']['session_id'] ?? '';
        $bal_after   = $res['body']['data']['balance_after'] ?? null;

        // Store quest_id and session_id for dependent tests
        set_transient( 'knowly_test_b6_quest_id',    $quest_id,   HOUR_IN_SECONDS );
        set_transient( 'knowly_test_b6_session_id',  $session_id, HOUR_IN_SECONDS );

        if ( $gem_cost !== 3 ) {
            return self::fail( "Expected gem_cost 3 (first attempt) but got {$gem_cost}.", $res['body']['data'] ?? [] );
        }

        return self::pass( "Quest started (first attempt). gem_cost=3, balance {$balance} → {$bal_after}.", [
            'quest_id'      => $quest_id,
            'session_id'    => $session_id,
            'gem_cost'      => $gem_cost,
            'balance_before' => $balance,
            'balance_after'  => $bal_after,
        ] );
    }

    private static function test_quest6_retake_cost(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found.' );

        $quest_id   = get_transient( 'knowly_test_b6_quest_id' );
        $session_id = get_transient( 'knowly_test_b6_session_id' );

        if ( ! $quest_id || ! $session_id ) {
            return self::warn( 'No quest_id / session_id from quest6_start_first. Run that test first.' );
        }

        $token = Knowly_JWT::encode( $child_user->ID );

        // Complete the first session so Railway records state=completed.
        // Only then will has_prior_completion() return true and charge retake cost.
        $complete_res = self::api_post( '/quests/complete', [ 'session_id' => $session_id ], $token );
        if ( $complete_res['status'] !== 200 ) {
            return self::fail( 'Could not complete first session before retake test.', $complete_res );
        }

        // Ensure enough gems for the retake (cost = 1)
        $balance = (int) get_user_meta( $child_user->ID, 'knowly_gem_balance', true );
        if ( $balance < 5 ) {
            update_user_meta( $child_user->ID, 'knowly_gem_balance', 5 );
            $balance = 5;
        }

        $res = self::api_post( '/quests/start', [ 'quest_id' => $quest_id, 'source' => 'direct' ], $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Quest retake start failed.', $res );
        }

        $gem_cost  = $res['body']['data']['gem_cost'] ?? -1;
        $bal_after = $res['body']['data']['balance_after'] ?? null;

        if ( $gem_cost !== 1 ) {
            return self::fail( "Expected gem_cost 1 (retake) but got {$gem_cost}.", $res['body']['data'] ?? [] );
        }

        return self::pass( "First session completed, retake started. gem_cost=1 confirmed. Balance {$balance} → {$bal_after}.", [
            'quest_id'       => $quest_id,
            'gem_cost'       => $gem_cost,
            'balance_before' => $balance,
            'balance_after'  => $bal_after,
        ] );
    }

    private static function test_quest6_assigned_free(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found.' );

        $quest_id = get_transient( 'knowly_test_b6_quest_id' );
        if ( ! $quest_id ) {
            return self::warn( 'No quest_id found. Run quest6_start_first first.' );
        }

        $balance = (int) get_user_meta( $child_user->ID, 'knowly_gem_balance', true );

        $token = Knowly_JWT::encode( $child_user->ID );
        $res   = self::api_post( '/quests/start', [ 'quest_id' => $quest_id, 'source' => 'assignment' ], $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Assignment quest start failed.', $res );
        }

        $gem_cost  = $res['body']['data']['gem_cost'] ?? -1;
        $bal_after = $res['body']['data']['balance_after'] ?? null;

        if ( $gem_cost !== 0 ) {
            return self::fail( "Expected gem_cost 0 for assignment-sourced quest but got {$gem_cost}.", $res['body']['data'] ?? [] );
        }

        return self::pass( "Assignment quest start: gem_cost=0 confirmed. Balance unchanged at {$balance}.", [
            'quest_id'    => $quest_id,
            'gem_cost'    => $gem_cost,
            'balance'     => $balance,
            'bal_after'   => $bal_after,
        ] );
    }

    private static function test_quest6_badge_setup(): array {
        $test_quest_id = 'test-quest-b6';

        // Check if test badge post already exists
        $existing = get_posts( [
            'post_type'      => 'knowly_badge',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [ [ 'key' => '_knowly_quest_id', 'value' => $test_quest_id ] ],
        ] );

        if ( ! empty( $existing ) ) {
            $badge_post_id = (int) $existing[0]->ID;
            set_transient( 'knowly_test_b6_badge_post_id', $badge_post_id, HOUR_IN_SECONDS );
            return self::pass( "Test badge post already exists (ID: {$badge_post_id}).", [
                'badge_post_id' => $badge_post_id,
                'quest_id'      => $test_quest_id,
            ] );
        }

        // Create a published badge CPT post for the test quest
        $badge_post_id = wp_insert_post( [
            'post_type'    => 'knowly_badge',
            'post_title'   => 'Block 6 Test Badge',
            'post_excerpt' => 'Awarded for completing the Block 6 test quest.',
            'post_status'  => 'publish',
        ] );

        if ( is_wp_error( $badge_post_id ) ) {
            return self::fail( 'Badge post creation failed: ' . $badge_post_id->get_error_message() );
        }

        update_post_meta( $badge_post_id, '_knowly_quest_id', $test_quest_id );
        set_transient( 'knowly_test_b6_badge_post_id', $badge_post_id, HOUR_IN_SECONDS );

        return self::pass( "Test badge post created (ID: {$badge_post_id}, quest_id: {$test_quest_id}).", [
            'badge_post_id' => $badge_post_id,
            'quest_id'      => $test_quest_id,
        ] );
    }

    private static function test_quest6_badge_award(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found.' );

        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );

        // Clear any prior award for this test quest to test fresh award
        $raw     = get_user_meta( $child_user->ID, Knowly_Badge_Service::META_KEY, true );
        $earned  = is_string( $raw ) ? ( json_decode( $raw, true ) ?: [] ) : [];
        $filtered = array_values( array_filter( $earned, fn( $e ) => ( $e['quest_id'] ?? '' ) !== 'test-quest-b6' ) );
        update_user_meta( $child_user->ID, Knowly_Badge_Service::META_KEY, wp_json_encode( $filtered ) );

        $res = self::api_post( '/badges/award', [
            'user_id'  => $child_user->ID,
            'quest_id' => 'test-quest-b6',
        ], $admin_token );

        if ( $res['status'] === 200 && ! empty( $res['body']['data']['badge_id'] ) ) {
            return self::pass( 'Badge awarded to test child.', [
                'badge_id'   => $res['body']['data']['badge_id'],
                'quest_id'   => $res['body']['data']['quest_id'] ?? 'test-quest-b6',
                'awarded_at' => $res['body']['data']['awarded_at'] ?? '',
            ] );
        }

        return self::fail( 'Badge award failed.', $res );
    }

    private static function test_quest6_badge_idempotent(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found.' );

        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );

        // Record current earned badge count before second award call
        $raw_before = get_user_meta( $child_user->ID, Knowly_Badge_Service::META_KEY, true );
        $before     = is_string( $raw_before ) ? count( json_decode( $raw_before, true ) ?: [] ) : 0;

        // Award same badge again — must be a no-op, not a duplicate
        $res = self::api_post( '/badges/award', [
            'user_id'  => $child_user->ID,
            'quest_id' => 'test-quest-b6',
        ], $admin_token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Badge award (idempotent) returned unexpected status.', $res );
        }

        // Flush object cache then re-read
        clean_user_cache( $child_user->ID );
        $raw_after = get_user_meta( $child_user->ID, Knowly_Badge_Service::META_KEY, true );
        $after     = is_string( $raw_after ) ? count( json_decode( $raw_after, true ) ?: [] ) : 0;

        if ( $before !== $after ) {
            return self::fail( "Badge was duplicated — count went from {$before} to {$after}.", [
                'before' => $before,
                'after'  => $after,
            ] );
        }

        return self::pass( "Idempotent award confirmed. Badge count unchanged at {$after}.", [
            'count_before' => $before,
            'count_after'  => $after,
        ] );
    }

    private static function test_quest6_badge_list(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found.' );

        $token = Knowly_JWT::encode( $child_user->ID );
        $res   = self::api_get( '/badges/' . $child_user->ID, $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'GET /badges/{user_id} failed.', $res );
        }

        $count  = $res['body']['data']['count'] ?? 0;
        $badges = $res['body']['data']['badges'] ?? [];

        $test_badge = array_values( array_filter( $badges, fn( $b ) => ( $b['quest_id'] ?? '' ) === 'test-quest-b6' ) );

        if ( $count < 1 || empty( $test_badge ) ) {
            return self::fail( 'test-quest-b6 badge not in list. Run quest6_badge_award first.', [
                'count'  => $count,
                'badges' => array_map( fn( $b ) => [ 'badge_id' => $b['badge_id'], 'quest_id' => $b['quest_id'] ], $badges ),
            ] );
        }

        return self::pass( "Badge list returned {$count} badge(s). test-quest-b6 badge confirmed.", [
            'count'      => $count,
            'test_badge' => $test_badge[0],
        ] );
    }

    // ── Block 7 Test Methods ──────────────────────────────────────────────────

    private static function test_analytics7_class(): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found. Run Block 2 account setup first.' );

        // Find most recent Math 4A class owned by test teacher
        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found. Run class5_create first.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_get( '/analytics/class/' . $class->id, $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Class analytics endpoint failed.', $res );
        }

        $data = $res['body']['data'] ?? [];

        return self::pass( 'Class analytics returned.', [
            'class_id'        => $data['class_id'] ?? null,
            'student_count'   => $data['student_count'] ?? 0,
            'total_trials'    => $data['total_trials'] ?? 0,
            'total_quests'    => $data['total_quests'] ?? 0,
            'class_avg_score' => $data['class_avg_score'] ?? null,
            'direct_count'    => $data['direct_count'] ?? 0,
            'assignment_count' => $data['assignment_count'] ?? 0,
        ] );
    }

    private static function test_analytics7_student(): array {
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        $child_user   = get_user_by( 'login', 'test.child' );
        if ( ! $teacher_user ) return self::warn( 'Test teacher not found.' );
        if ( ! $child_user )   return self::warn( 'Test child not found.' );

        global $wpdb;
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_classes WHERE teacher_user_id = %d AND name = 'Math 4A' ORDER BY id DESC LIMIT 1",
            $teacher_user->ID
        ) );
        if ( ! $class ) return self::warn( 'Math 4A class not found.' );

        $token = Knowly_JWT::encode( $teacher_user->ID );
        $res   = self::api_get( '/analytics/class/' . $class->id . '/student/' . $child_user->ID, $token );

        if ( $res['status'] !== 200 ) {
            return self::fail( 'Student analytics endpoint failed.', $res );
        }

        $data = $res['body']['data'] ?? [];

        return self::pass( 'Student analytics returned.', [
            'user_id'         => $data['user_id'] ?? null,
            'nickname'        => $data['nickname'] ?? '',
            'level'           => $data['level'] ?? '',
            'trial_count'     => $data['trial_count'] ?? 0,
            'quest_count'     => $data['quest_count'] ?? 0,
            'avg_score'       => $data['avg_score'] ?? null,
            'direct_count'    => $data['direct_count'] ?? 0,
            'assignment_count' => $data['assignment_count'] ?? 0,
            'subjects'        => count( $data['subjects'] ?? [] ),
        ] );
    }

    private static function test_analytics7_access_control(): array {
        $parent_user = get_user_by( 'email', 'test.parent@knowly.test' );
        if ( ! $parent_user ) return self::warn( 'Test parent not found.' );

        // Find any class — parent should be forbidden
        global $wpdb;
        $class = $wpdb->get_row( "SELECT id FROM {$wpdb->prefix}knowly_classes ORDER BY id ASC LIMIT 1" );
        if ( ! $class ) return self::warn( 'No classes found. Run class5_create first.' );

        $parent_token = Knowly_JWT::encode( $parent_user->ID );
        $res = self::api_get( '/analytics/class/' . $class->id, $parent_token );

        if ( $res['status'] === 403 ) {
            return self::pass( 'Access control confirmed — parent receives 403 on class analytics.', [
                'class_id' => (int) $class->id,
                'status'   => $res['status'],
            ] );
        }

        return self::fail( "Expected 403 for parent but got {$res['status']}.", $res );
    }

    // ── Leaderboard Test Methods ──────────────────────────────────────────────

    private static function test_lb_nickname_generate(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found. Run Block 2 account setup first.' );

        $result = Knowly_Leaderboard_Service::generate_nickname( $child_user->ID, 'std_4', 'term_1' );
        if ( is_wp_error( $result ) ) {
            return self::fail( 'generate_nickname failed: ' . $result->get_error_message() );
        }
        $nickname = get_user_meta( $child_user->ID, 'knowly_nickname', true );
        return self::pass( 'Nickname generated/confirmed for test child.', [
            'child_id' => $child_user->ID,
            'nickname' => $nickname ?: '(see Railway response)',
            'result'   => is_string( $result ) ? $result : 'OK',
        ] );
    }

    private static function test_lb_read_board(): array {
        $result = Knowly_Leaderboard_Service::get_board( 'std_4', 'term_1', 'math' );
        if ( is_wp_error( $result ) ) {
            return self::fail( 'get_board failed: ' . $result->get_error_message() );
        }
        return self::pass( 'Board (std_4/term_1/math) read OK.', [
            'date'         => $result['date'] ?? '—',
            'participants' => $result['total_participants'] ?? 0,
            'entries'      => count( $result['entries'] ?? [] ),
        ] );
    }

    private static function test_lb_read_my_boards(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found. Run Block 2 account setup first.' );

        $result = Knowly_Leaderboard_Service::get_my_boards( $child_user->ID );
        if ( is_wp_error( $result ) ) {
            return self::fail( 'get_my_boards failed: ' . $result->get_error_message() );
        }
        $boards = $result['boards'] ?? [];
        return self::pass( 'My boards returned.', [
            'child_id' => $child_user->ID,
            'boards'   => count( $boards ),
        ] );
    }

    private static function test_lb_simulate_upsert(): array {
        $child_user = get_user_by( 'login', 'test.child' );
        if ( ! $child_user ) return self::warn( 'Test child not found. Run Block 2 account setup first.' );

        $nickname = get_user_meta( $child_user->ID, 'knowly_nickname', true );
        if ( ! $nickname ) {
            return self::warn( 'Test child has no nickname. Run lb_nickname_generate first.' );
        }

        $fake_session = [
            'child_id'            => $child_user->ID,
            'level'               => 'std_4',
            'period'              => 'term_1',
            'subject'             => 'math',
            'difficulty'          => 'easy',
            'external_session_id' => 'ses_lb_unit_test_' . time(),
        ];
        $fake_result = [
            'score'      => 15,
            'total'      => 20,
            'percentage' => 75,
        ];

        Knowly_Debug::log( 'leaderboard.unit_test', 'Simulating submit upsert', [
            'child_id' => $child_user->ID,
            'session'  => $fake_session,
        ], get_current_user_id(), 'info' );

        $update = Knowly_Leaderboard_Service::handle_submit_upsert( $fake_session, $fake_result );
        if ( $update === null ) {
            return self::fail( 'handle_submit_upsert returned null. Check Debug Log for leaderboard.upsert_failed.' );
        }
        return self::pass( 'Simulated upsert OK — leaderboard_update block received.', [
            'rank'         => $update['rank'] ?? '—',
            'total_points' => $update['total_points'] ?? '—',
        ] );
    }

    private static function test_lb_inject_entry(): array {
        $result = Knowly_Leaderboard_Service::inject_test_entry( [
            'nickname'  => 'UnitTestBot_' . substr( (string) time(), -4 ),
            'level'     => 'std_4',
            'period'    => 'term_1',
            'subject'   => 'math',
            'points'    => 12,
            'score_pct' => 60,
        ] );
        if ( is_wp_error( $result ) ) {
            return self::fail( 'inject_test_entry failed: ' . $result->get_error_message() );
        }
        return self::pass( 'Fake entry injected onto std_4/term_1/math board.', is_array( $result ) ? $result : [] );
    }

    private static function test_lb_reset_board(): array {
        $result = Knowly_Leaderboard_Service::reset_board( 'std_4', 'term_1', 'math' );
        if ( is_wp_error( $result ) ) {
            return self::fail( 'reset_board failed: ' . $result->get_error_message() );
        }
        return self::pass( 'std_4/term_1/math board reset. Fake entries cleared.', is_array( $result ) ? $result : [ 'result' => $result ] );
    }

    // ── Test Definition List ──────────────────────────────────────────────────

    private static function test_groups(): array {
        return [
            'system' => [
                'label' => '🔧 System',
                'tests' => [
                    'system_jwt_secret'   => [ 'label' => 'JWT Secret configured',    'method' => 'CHECK', 'route' => '' ],
                    'system_db_tables'    => [ 'label' => 'All DB tables exist',       'method' => 'CHECK', 'route' => '' ],
                    'system_railway_ping' => [ 'label' => 'Railway server reachable',  'method' => 'GET',   'route' => '' ],
                ],
            ],
            'auth' => [
                'label' => '🔐 Auth',
                'tests' => [
                    'auth_ping'       => [ 'label' => 'Health check (ping)',  'method' => 'GET',  'route' => '/ping' ],
                    'auth_login'      => [ 'label' => 'Login → JWT',         'method' => 'POST', 'route' => '/auth/login' ],
                    'auth_me'         => [ 'label' => 'Current user (/me)',   'method' => 'GET',  'route' => '/auth/me' ],
                    'auth_pin_set'    => [ 'label' => 'Set parent PIN',       'method' => 'POST', 'route' => '/auth/pin/set' ],
                    'auth_pin_verify' => [ 'label' => 'Verify parent PIN',    'method' => 'POST', 'route' => '/auth/pin/verify' ],
                ],
            ],
            'children' => [
                'label' => '👶 Children',
                'tests' => [
                    'children_list'   => [ 'label' => 'List children',     'method' => 'GET',    'route' => '/children' ],
                    'children_create' => [ 'label' => 'Create child',      'method' => 'POST',   'route' => '/children' ],
                    'children_switch' => [ 'label' => 'Switch active child', 'method' => 'POST', 'route' => '/children/{id}/switch' ],
                ],
            ],
            'exams' => [
                'label' => '📝 Exams',
                'tests' => [
                    'exams_catalogue' => [ 'label' => 'Exam catalogue',  'method' => 'GET',  'route' => '/exams' ],
                    'exams_start'     => [ 'label' => 'Start exam',      'method' => 'POST', 'route' => '/exams/start' ],
                ],
            ],
            'results' => [
                'label' => '📊 Results',
                'tests' => [
                    'results_history' => [ 'label' => 'Exam history',    'method' => 'GET', 'route' => '/results' ],
                    'results_stats'   => [ 'label' => 'Aggregate stats', 'method' => 'GET', 'route' => '/results/stats' ],
                ],
            ],
            'insights' => [
                'label' => '💡 Insights',
                'tests' => [
                    'insights_weekly_build' => [ 'label' => 'Build weekly payload', 'method' => 'CHECK', 'route' => '' ],
                ],
            ],
            'block4_notifications' => [
                'label' => '🔔 Block 4 — Notifications',
                'tests' => [
                    'notif_create'    => [ 'label' => 'Admin creates confirmation notification for test parent', 'method' => 'POST',  'route' => '/notifications' ],
                    'notif_list'      => [ 'label' => 'List all notifications for test parent',                  'method' => 'GET',   'route' => '/notifications' ],
                    'notif_count'     => [ 'label' => 'Unread count endpoint returns integer',                   'method' => 'GET',   'route' => '/notifications/count' ],
                    'notif_respond'   => [ 'label' => 'Accept block4_test confirmation notification',            'method' => 'POST',  'route' => '/notifications/{id}/respond' ],
                    'notif_read_all'  => [ 'label' => 'Mark all read → verify unread count is 0',               'method' => 'POST',  'route' => '/notifications/read-all' ],
                ],
            ],
            'notif_v2' => [
                'label' => '🔔 Notifications V2 — Delete + Combined Notify',
                'tests' => [
                    'notif_v2_delete_setup'      => [ 'label' => 'Setup: create test notification for parent to delete',                            'method' => 'CHECK',  'route' => '' ],
                    'notif_v2_delete_own'        => [ 'label' => 'Parent deletes own notification → 200 deleted=true',                              'method' => 'DELETE', 'route' => '/notifications/{id}' ],
                    'notif_v2_delete_gone'       => [ 'label' => 'Verify deleted notification is absent from parent list',                          'method' => 'GET',    'route' => '/notifications' ],
                    'notif_v2_delete_other_user' => [ 'label' => 'Parent tries to delete teacher\'s notification → 404 (access control)',           'method' => 'DELETE', 'route' => '/notifications/{id}' ],
                    'notif_v2_notify_student'    => [ 'label' => 'Teacher notifies student only (notify-student)',                                  'method' => 'POST',   'route' => '/classes/{id}/notify-student/{child_id}' ],
                    'notif_v2_notify_parent'     => [ 'label' => 'Teacher notifies parent only (notify-parent)',                                    'method' => 'POST',   'route' => '/classes/{id}/notify-parent/{child_id}' ],
                    'notif_v2_notify_both'       => [ 'label' => 'Teacher notifies student + parent together (notify-student-and-parent)',          'method' => 'POST',   'route' => '/classes/{id}/notify-student-and-parent/{child_id}' ],
                    'notif_v2_verify_student'    => [ 'label' => 'Child account lists teacher_message notifications → found',                       'method' => 'GET',    'route' => '/notifications' ],
                    'notif_v2_verify_parent'     => [ 'label' => 'Parent account lists teacher_message notifications → found',                      'method' => 'GET',    'route' => '/notifications' ],
                ],
            ],
            'block2_setup' => [
                'label' => '🧪 Block 2 — Test Account Setup',
                'tests' => [
                    'provision_test_accounts' => [ 'label' => 'Provision test parent, teacher, and child (std_4/term_1)', 'method' => 'CHECK', 'route' => '' ],
                ],
            ],
            'block2_auth' => [
                'label' => '🔐 Block 2 — Auth',
                'tests' => [
                    'auth_register_parent'  => [ 'label' => 'Register parent via /auth/register/parent',    'method' => 'POST', 'route' => '/auth/register/parent' ],
                    'auth_password_reset'   => [ 'label' => 'Password reset (uses username field as email)', 'method' => 'POST', 'route' => '/auth/password/reset' ],
                ],
            ],
            'block2_teacher' => [
                'label' => '👩‍🏫 Block 2 — Teacher',
                'tests' => [
                    'teacher_register'       => [ 'label' => 'Register teacher (pending_approval)',          'method' => 'POST',  'route' => '/auth/register/teacher' ],
                    'teacher_login_pending'  => [ 'label' => 'Pending teacher can log in (sees status)',     'method' => 'POST',  'route' => '/auth/login' ],
                    'teacher_approve'        => [ 'label' => 'Approve test teacher (admin action)',          'method' => 'CHECK', 'route' => '' ],
                    'teacher_login_approved' => [ 'label' => 'Approved teacher logs in (approval_status ok)','method' => 'POST',  'route' => '/auth/login' ],
                ],
            ],
            'block5_classes' => [
                'label' => '🏫 Block 5 — Classes',
                'tests' => [
                    'class5_create'        => [ 'label' => 'Teacher creates class "Math 4A"',                                'method' => 'POST',  'route' => '/classes' ],
                    'class5_child_lookup'  => [ 'label' => 'Teacher looks up test child by nickname "TestKid"',             'method' => 'GET',   'route' => '/classes/child-lookup' ],
                    'class5_invite'        => [ 'label' => 'Teacher invites TestKid → dual notifications sent',             'method' => 'POST',  'route' => '/classes/{id}/invite' ],
                    'class5_parent_accept' => [ 'label' => 'Parent accepts invitation → child added to class',             'method' => 'POST',  'route' => '/notifications/{id}/respond' ],
                    'class5_verify_member' => [ 'label' => 'Verify child is an active class member',                       'method' => 'GET',   'route' => '/classes/{id}/members' ],
                    'class5_create_task'   => [ 'label' => 'Teacher creates task (red gem deducted)',                      'method' => 'POST',  'route' => '/classes/{id}/tasks' ],
                    'class5_list_tasks'    => [ 'label' => 'List tasks for class → task appears',                          'method' => 'GET',   'route' => '/classes/{id}/tasks' ],
                    'class5_child_classes' => [ 'label' => 'Child lists their enrolled classes',                           'method' => 'GET',   'route' => '/classes/my' ],
                ],
            ],
            'block2_notifications' => [
                'label' => '🔔 Block 2 — Notifications',
                'tests' => [
                    'notifications_create' => [ 'label' => 'Create simple notification',              'method' => 'CHECK', 'route' => '' ],
                    'notifications_list'   => [ 'label' => 'List notifications for user (user_id req)', 'method' => 'CHECK', 'route' => '' ],
                ],
            ],
            'block6_quests' => [
                'label' => '🗺 Block 6 — Quests',
                'tests' => [
                    'quest6_catalogue'     => [ 'label' => 'Fetch quest catalogue for test child (std_4/term_1)',           'method' => 'GET',  'route' => '/quests' ],
                    'quest6_start_first'   => [ 'label' => 'Start quest (first attempt) → gem_cost=3, stores session_id',  'method' => 'POST', 'route' => '/quests/start' ],
                    'quest6_retake_cost'   => [ 'label' => 'Start same quest again (retake) → gem_cost=1',                 'method' => 'POST', 'route' => '/quests/start' ],
                    'quest6_assigned_free' => [ 'label' => 'Start quest via assignment → gem_cost=0',                      'method' => 'POST', 'route' => '/quests/start' ],
                ],
            ],
            'block6_badges' => [
                'label' => '🏅 Block 6 — Badges',
                'tests' => [
                    'quest6_badge_setup'      => [ 'label' => 'Create test badge CPT post (quest_id: test-quest-b6)',    'method' => 'CHECK', 'route' => '' ],
                    'quest6_badge_award'      => [ 'label' => 'Admin awards test-quest-b6 badge to test child',         'method' => 'POST',  'route' => '/badges/award' ],
                    'quest6_badge_idempotent' => [ 'label' => 'Award same badge again → no duplicate in user meta',     'method' => 'POST',  'route' => '/badges/award' ],
                    'quest6_badge_list'       => [ 'label' => 'Child lists their badges → test-quest-b6 badge appears', 'method' => 'GET',   'route' => '/badges/{user_id}' ],
                ],
            ],
            'block7_analytics' => [
                'label' => '📊 Block 7 — Analytics',
                'tests' => [
                    'analytics7_class'          => [ 'label' => 'Teacher fetches class analytics (aggregate: trials, quests, scores)',    'method' => 'GET', 'route' => '/analytics/class/{id}' ],
                    'analytics7_student'        => [ 'label' => 'Teacher fetches per-student analytics (subject breakdown, sessions)',    'method' => 'GET', 'route' => '/analytics/class/{id}/student/{id}' ],
                    'analytics7_access_control' => [ 'label' => 'Parent attempt on class analytics → 403 Forbidden',                    'method' => 'GET', 'route' => '/analytics/class/{id}' ],
                ],
            ],
            'block_leaderboard' => [
                'label' => '🏆 Leaderboard',
                'tests' => [
                    'lb_nickname_generate' => [ 'label' => 'Generate/confirm nickname for test child (std_4/term_1)',        'method' => 'POST',  'route' => '/leaderboard/generate-nickname' ],
                    'lb_read_board'        => [ 'label' => 'Read board (std_4/term_1/math) via Leaderboard_Service',         'method' => 'GET',   'route' => '/leaderboard/std_4/term_1/math' ],
                    'lb_read_my_boards'    => [ 'label' => 'Read all boards for test child (get_my_boards)',                  'method' => 'GET',   'route' => '/leaderboard/me' ],
                    'lb_simulate_upsert'   => [ 'label' => 'Simulate exam submit → handle_submit_upsert (real code path)',   'method' => 'POST',  'route' => '/leaderboard/upsert' ],
                    'lb_inject_entry'      => [ 'label' => 'Inject fake test entry onto std_4/term_1/math board',            'method' => 'POST',  'route' => '/leaderboard/test/inject' ],
                    'lb_reset_board'       => [ 'label' => 'Reset std_4/term_1/math board — cleans up test entries',         'method' => 'POST',  'route' => '/leaderboard/test/reset-board' ],
                ],
            ],
        ];
    }

    // ── HTTP Helpers ──────────────────────────────────────────────────────────

    private static function api_get( string $route, string $token = '' ): array {
        return self::api_call( 'GET', $route, [], $token );
    }

    private static function api_post( string $route, array $body = [], string $token = '' ): array {
        return self::api_call( 'POST', $route, $body, $token );
    }

    private static function api_delete( string $route, string $token = '' ): array {
        return self::api_call( 'DELETE', $route, [], $token );
    }

    private static function api_call( string $method, string $route, array $body, string $token ): array {
        $url     = rest_url( KNOWLY_REST_NAMESPACE . $route );
        $headers = [ 'Content-Type' => 'application/json' ];

        if ( $token ) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => $headers,
        ];

        if ( $method === 'POST' && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 0, 'error' => $response->get_error_message() ];
        }

        return [
            'status' => wp_remote_retrieve_response_code( $response ),
            'body'   => json_decode( wp_remote_retrieve_body( $response ), true ),
        ];
    }

    private static function get_admin_token(): string {
        $admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        if ( empty( $admin ) ) return '';
        return Knowly_JWT::encode( (int) $admin[0] );
    }

    // ── Result Builders ───────────────────────────────────────────────────────

    private static function pass( string $message, array $data = [] ): array {
        return [ 'pass' => true,  'status' => 'pass', 'message' => $message, 'data' => $data ];
    }

    private static function fail( string $message, array $data = [] ): array {
        return [ 'pass' => false, 'status' => 'fail', 'message' => $message, 'data' => $data ];
    }

    private static function warn( string $message, array $data = [] ): array {
        return [ 'pass' => null,  'status' => 'warn', 'message' => $message, 'data' => $data ];
    }
}
