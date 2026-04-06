<?php
/**
 * Knowly_Admin_Testing — Integrated API test suite.
 *
 * Each test group contains individual tests that can be run via AJAX.
 * Tests call the actual REST API endpoints internally (not mocked),
 * giving real confidence that the full stack is working.
 *
 * Test groups:
 *   System     — JWT secret, DB tables, Railway connection
 *   Auth       — Login, /me, PIN set/verify
 *   Children   — Create, list, switch, remove
 *   Tokens     — Balance, credit, deduct, monthly refresh
 *   Exams      — Catalogue, start, checkpoint, submit
 *   Results    — History, stats, session detail
 *   Insights   — Per-exam insight, weekly digest
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
                // Tokens
                'tokens_balance'       => self::test_tokens_balance( $data ),
                'tokens_credit'        => self::test_tokens_credit( $data ),
                'tokens_deduct'        => self::test_tokens_deduct( $data ),
                'tokens_monthly'       => self::test_tokens_monthly(),
                // Exams
                'exams_catalogue'      => self::test_exams_catalogue( $data ),
                'exams_start'          => self::test_exams_start( $data ),
                // Results
                'results_history'      => self::test_results_history( $data ),
                'results_stats'        => self::test_results_stats( $data ),
                // Insights
                'insights_weekly_build' => self::test_insights_weekly_build( $data ),
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
            'knowly_children', 'knowly_token_ledger', 'knowly_exam_pool',
            'knowly_exam_sessions', 'knowly_exam_answers', 'knowly_topic_breakdown',
            'knowly_exam_insights', 'knowly_weekly_insights',
            'knowly_notifications', 'knowly_migration_log', 'knowly_debug_log',
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

    // ── Token Tests ───────────────────────────────────────────────────────────

    private static function test_tokens_balance( array $data ): array {
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token.' );
        $res = self::api_get( '/tokens/balance', $data['token'] );
        return $res['status'] === 200
            ? self::pass( 'Balance fetched.', $res['body']['data'] ?? [] )
            : self::fail( 'Balance failed.', $res );
    }

    private static function test_tokens_credit( array $data ): array {
        if ( empty( $data['user_id'] ) ) return self::warn( 'Provide user_id for admin credit test.' );
        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );

        $res = self::api_post( '/tokens/admin/credit', [
            'user_id' => (int) $data['user_id'],
            'amount'  => 1,
            'note'    => 'Test Suite credit',
        ], $admin_token );

        return $res['status'] === 200
            ? self::pass( 'Credit applied.', $res['body']['data'] ?? [] )
            : self::fail( 'Credit failed.', $res );
    }

    private static function test_tokens_deduct( array $data ): array {
        if ( empty( $data['user_id'] ) ) return self::warn( 'Provide user_id.' );
        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );

        $res = self::api_post( '/tokens/admin/deduct', [
            'user_id' => (int) $data['user_id'],
            'amount'  => 1,
            'note'    => 'Test Suite deduct',
        ], $admin_token );

        return $res['status'] === 200
            ? self::pass( 'Deduct applied.', $res['body']['data'] ?? [] )
            : self::fail( 'Deduct failed.', $res );
    }

    private static function test_tokens_monthly(): array {
        $admin_token = self::get_admin_token();
        if ( ! $admin_token ) return self::warn( 'Could not generate admin token.' );
        $res = self::api_post( '/tokens/admin/refresh', [], $admin_token );
        return $res['status'] === 200
            ? self::pass( 'Monthly refresh triggered.', $res['body']['data'] ?? [] )
            : self::fail( 'Monthly refresh failed.', $res );
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
        if ( empty( $data['token'] ) ) return self::warn( 'Provide token (with active child).' );
        $res = self::api_post( '/exams/start', [
            'level'     => $data['level'] ?? 'std_4',
            'period'   => $data['period'] ?? 'term_1',
            'subject'    => $data['subject'] ?? 'Mathematics',
            'difficulty' => $data['difficulty'] ?? 'medium',
        ], $data['token'] );
        if ( $res['status'] === 200 ) {
            return self::pass( 'Exam started.', [ 'session_id' => $res['body']['data']['session_id'] ?? null ] );
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
                Knowly_Token_Service::grant_on_registration( $parent_id );
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
            'tokens' => [
                'label' => '🪙 Tokens',
                'tests' => [
                    'tokens_balance' => [ 'label' => 'Get balance',          'method' => 'GET',  'route' => '/tokens/balance' ],
                    'tokens_credit'  => [ 'label' => 'Admin credit',         'method' => 'POST', 'route' => '/tokens/admin/credit' ],
                    'tokens_deduct'  => [ 'label' => 'Admin deduct',         'method' => 'POST', 'route' => '/tokens/admin/deduct' ],
                    'tokens_monthly' => [ 'label' => 'Trigger monthly reset','method' => 'POST', 'route' => '/tokens/admin/refresh' ],
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
            'block2_notifications' => [
                'label' => '🔔 Block 2 — Notifications',
                'tests' => [
                    'notifications_create' => [ 'label' => 'Create simple notification',              'method' => 'CHECK', 'route' => '' ],
                    'notifications_list'   => [ 'label' => 'List notifications for user (user_id req)', 'method' => 'CHECK', 'route' => '' ],
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
