<?php
/**
 * Knowly_Admin_Signoff — Block 8 Sign-off Dashboard.
 *
 * Provides:
 *   - System health checks (Railway, DB, JWT)
 *   - Sign-off gate: toggle between Blocked / Approved
 *   - Danger Zone: manual admin triggers
 *
 * The sign-off gate blocks React development. Gate must be explicitly set to
 * Approved by an admin before frontend work begins.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Signoff {

    const GATE_OPTION = 'knowly_signoff_status'; // 'blocked' | 'approved'

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_signoff_health',    [ __CLASS__, 'ajax_health' ] );
        add_action( 'wp_ajax_knowly_signoff_set_gate',  [ __CLASS__, 'ajax_set_gate' ] );
        add_action( 'wp_ajax_knowly_danger_wipe_tests', [ __CLASS__, 'ajax_wipe_test_data' ] );
        add_action( 'wp_ajax_knowly_danger_reset_leaderboard', [ __CLASS__, 'ajax_reset_leaderboard' ] );
        add_action( 'wp_ajax_knowly_danger_stipend_reset',     [ __CLASS__, 'ajax_stipend_reset' ] );
        add_action( 'wp_ajax_knowly_danger_gem_reset',         [ __CLASS__, 'ajax_gem_reset' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $gate  = get_option( self::GATE_OPTION, 'blocked' );
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        $is_approved = $gate === 'approved';
        ?>
        <div class="wrap knowly-wrap">
            <h1>Sign-off &amp; System Control</h1>

            <!-- ── SIGN-OFF GATE ──────────────────────────────────────────── -->
            <div style="background:<?= $is_approved ? '#f0fdf4' : '#fef2f2' ?>;border:2px solid <?= $is_approved ? '#16a34a' : '#dc2626' ?>;border-radius:8px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h2 style="margin:0 0 4px;font-size:18px;color:<?= $is_approved ? '#16a34a' : '#dc2626' ?>;">
                        React Build: <strong><?= $is_approved ? 'APPROVED' : 'BLOCKED' ?></strong>
                    </h2>
                    <p style="margin:0;font-size:13px;color:#555;">
                        <?php if ( $is_approved ) : ?>
                            All health checks are confirmed green. React development may begin.
                        <?php else : ?>
                            React development is <strong>blocked</strong>. Run all test suite groups, confirm all checks green, then set to Approved.
                        <?php endif; ?>
                    </p>
                </div>
                <div style="display:flex;gap:8px;">
                    <button id="knowly-gate-approve" class="button button-primary" style="background:<?= $is_approved ? '#ccc' : '' ?>;border-color:<?= $is_approved ? '#ccc' : '' ?>;" <?= $is_approved ? 'disabled' : '' ?>>
                        ✓ Set Approved
                    </button>
                    <button id="knowly-gate-block" class="button" style="color:#dc2626;border-color:#dc2626;" <?= ! $is_approved ? 'disabled' : '' ?>>
                        ✗ Set Blocked
                    </button>
                </div>
            </div>

            <!-- ── HEALTH CHECKS ──────────────────────────────────────────── -->
            <div class="knowly-settings-section" style="margin-bottom:24px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <h2 style="margin:0;">System Health</h2>
                    <button id="knowly-run-health" class="button button-primary">▶ Run Health Checks</button>
                </div>
                <div id="knowly-health-results" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                    <?php foreach ( self::health_checks() as $id => $label ) : ?>
                    <div id="health-<?= esc_attr( $id ) ?>" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;display:flex;align-items:center;gap:10px;">
                        <span id="health-icon-<?= esc_attr( $id ) ?>" style="font-size:18px;min-width:22px;">○</span>
                        <div>
                            <div style="font-size:13px;font-weight:600;"><?= esc_html( $label ) ?></div>
                            <div id="health-msg-<?= esc_attr( $id ) ?>" style="font-size:11px;color:#888;margin-top:2px;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── BLOCK CHECKLIST ────────────────────────────────────────── -->
            <div class="knowly-settings-section" style="margin-bottom:24px;">
                <h2>Block Completion Checklist</h2>
                <p style="font-size:13px;color:#666;margin-bottom:16px;">
                    Run the <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-test-suite' ) ) ?>">Test Suite</a> for each block and verify all tests pass before setting the gate to Approved.
                </p>
                <table class="knowly-table widefat">
                    <thead><tr><th>Block</th><th>Scope</th><th>Key Gate Criteria</th><th>Test Suite Group</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Block 0 — Trials</strong></td><td>Railway + WP</td><td>Pool seeded, exam start/submit/results verified</td><td>Exams, Results, Insights</td></tr>
                        <tr><td><strong>Block 2 — Identity</strong></td><td>WP</td><td>All 3 roles created, auth endpoints verified, PIN system, teacher approval flow</td><td>Auth, Children, Block 2</td></tr>
                        <tr><td><strong>Block 3 — Gems</strong></td><td>WP</td><td>Purchase flow, allocation, deduction, monthly reset verified</td><td>(inline in Gems admin)</td></tr>
                        <tr><td><strong>Block 4 — Notifications</strong></td><td>WP</td><td>Simple + confirmation notifications, respond flow</td><td>Block 4 — Notifications</td></tr>
                        <tr><td><strong>Block 5 — Classes</strong></td><td>WP</td><td>Class formation, invite/accept, task creation, red gem deduction</td><td>Block 5 — Classes</td></tr>
                        <tr><td><strong>Block 6 — Quests</strong></td><td>Railway + WP</td><td>Quest seeded, start/complete/badge flow verified, gem costs correct</td><td>Block 6 — Quests &amp; Badges</td></tr>
                        <tr><td><strong>Block 7 — Analytics</strong></td><td>Railway + WP</td><td>Class analytics, per-student analytics, access control</td><td>Block 7 — Analytics</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ── DANGER ZONE ────────────────────────────────────────────── -->
            <div style="border:2px solid #dc2626;border-radius:8px;padding:20px 24px;background:#fff5f5;">
                <h2 style="color:#dc2626;margin-top:0;">Danger Zone</h2>
                <p style="font-size:13px;color:#666;margin-bottom:20px;">
                    Destructive or irreversible actions. Each requires confirmation.
                </p>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;">

                    <div style="border:1px solid #fca5a5;border-radius:6px;padding:14px;background:#fff;">
                        <h3 style="margin:0 0 6px;font-size:14px;">Wipe Test Data</h3>
                        <p style="font-size:12px;color:#666;margin:0 0 10px;">Deletes all WP users and their data where <code>knowly_is_test_account = true</code>.</p>
                        <button class="button knowly-danger-btn" data-action="knowly_danger_wipe_tests" data-confirm="This will permanently delete all test accounts and their associated data. Are you sure?">
                            🗑 Wipe Test Accounts
                        </button>
                        <div id="danger-result-wipe_tests" style="margin-top:8px;font-size:12px;"></div>
                    </div>

                    <div style="border:1px solid #fca5a5;border-radius:6px;padding:14px;background:#fff;">
                        <h3 style="margin:0 0 6px;font-size:14px;">Reset Test Leaderboard</h3>
                        <p style="font-size:12px;color:#666;margin:0 0 10px;">Clears all leaderboard entries for std_4 / term_1 / math (test board only). Production boards are untouched.</p>
                        <button class="button knowly-danger-btn" data-action="knowly_danger_reset_leaderboard" data-confirm="This will reset the std_4/term_1/math test leaderboard. Continue?">
                            🔄 Reset Test Leaderboard
                        </button>
                        <div id="danger-result-reset_leaderboard" style="margin-top:8px;font-size:12px;"></div>
                    </div>

                    <div style="border:1px solid #fca5a5;border-radius:6px;padding:14px;background:#fff;">
                        <h3 style="margin:0 0 6px;font-size:14px;">Trigger Teacher Stipend Reset</h3>
                        <p style="font-size:12px;color:#666;margin:0 0 10px;">Manually fires the red gem stipend reset on Railway. Resets all teacher red gem balances to their configured stipend amount.</p>
                        <button class="button knowly-danger-btn" data-action="knowly_danger_stipend_reset" data-confirm="This will reset all teacher red gem balances to their stipend amount. This cannot be undone. Continue?">
                            💎 Trigger Stipend Reset
                        </button>
                        <div id="danger-result-stipend_reset" style="margin-top:8px;font-size:12px;"></div>
                    </div>

                    <div style="border:1px solid #fca5a5;border-radius:6px;padding:14px;background:#fff;">
                        <h3 style="margin:0 0 6px;font-size:14px;">Trigger Monthly Blue Gem Reset</h3>
                        <p style="font-size:12px;color:#666;margin:0 0 10px;">Manually fires the monthly blue gem refresh cron. Credits all free-tier child accounts with the configured monthly allocation.</p>
                        <button class="button knowly-danger-btn" data-action="knowly_danger_gem_reset" data-confirm="This will credit all free-tier child accounts with the monthly gem allocation. Continue?">
                            💠 Trigger Gem Reset
                        </button>
                        <div id="danger-result-gem_reset" style="margin-top:8px;font-size:12px;"></div>
                    </div>

                </div>
            </div>
        </div>

        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';
            var ajaxUrl = '<?= esc_js( admin_url( 'admin-ajax.php' ) ) ?>';

            // ── Gate toggle ───────────────────────────────────────────────────
            $('#knowly-gate-approve').on('click', function() {
                if (!confirm('Set React build gate to APPROVED? Make sure all tests are green before proceeding.')) return;
                $.post(ajaxUrl, { action: 'knowly_signoff_set_gate', nonce: nonce, gate: 'approved' }, function() { location.reload(); });
            });
            $('#knowly-gate-block').on('click', function() {
                if (!confirm('Set React build gate back to BLOCKED?')) return;
                $.post(ajaxUrl, { action: 'knowly_signoff_set_gate', nonce: nonce, gate: 'blocked' }, function() { location.reload(); });
            });

            // ── Health checks ─────────────────────────────────────────────────
            $('#knowly-run-health').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Running…');
                $.post(ajaxUrl, { action: 'knowly_signoff_health', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('▶ Run Health Checks');
                    if (!res.success) return;
                    $.each(res.data.checks, function(id, check) {
                        var icon  = check.status === 'pass' ? '✅' : check.status === 'warn' ? '⚠️' : '❌';
                        var color = check.status === 'pass' ? '#f0fdf4' : check.status === 'warn' ? '#fffbeb' : '#fef2f2';
                        var border = check.status === 'pass' ? '#86efac' : check.status === 'warn' ? '#fde68a' : '#fca5a5';
                        $('#health-' + id).css({ background: color, borderColor: border });
                        $('#health-icon-' + id).text(icon);
                        $('#health-msg-' + id).text(check.message || '');
                    });
                });
            });

            // ── Danger zone ───────────────────────────────────────────────────
            $(document).on('click', '.knowly-danger-btn', function() {
                var action  = $(this).data('action');
                var confirm_msg = $(this).data('confirm');
                if (!confirm(confirm_msg)) return;
                var $btn     = $(this).prop('disabled', true).text('Running…');
                var resultId = '#danger-result-' + action.replace('knowly_danger_', '');
                $(resultId).html('<em style="color:#888;">Running…</em>');
                $.post(ajaxUrl, { action: action, nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text($(this).text().replace('Running…', '') || 'Done');
                    if (res.success) {
                        $(resultId).html('<span style="color:#16a34a;">✓ ' + (res.data.message || 'Done') + '</span>');
                    } else {
                        $(resultId).html('<span style="color:#dc2626;">✗ ' + (res.data.message || 'Failed') + '</span>');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Health Checks ───────────────────────────────────────────────────

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $checks = [];

        // JWT secret
        if ( defined( 'KNOWLY_JWT_SECRET' ) && KNOWLY_JWT_SECRET ) {
            $checks['jwt'] = [ 'status' => 'pass', 'message' => 'KNOWLY_JWT_SECRET defined.' ];
        } elseif ( defined( 'JWT_AUTH_SECRET_KEY' ) && JWT_AUTH_SECRET_KEY ) {
            $checks['jwt'] = [ 'status' => 'warn', 'message' => 'Using JWT_AUTH_SECRET_KEY fallback.' ];
        } else {
            $checks['jwt'] = [ 'status' => 'fail', 'message' => 'No JWT secret found in wp-config.php.' ];
        }

        // Railway connection
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            $checks['railway'] = [ 'status' => 'fail', 'message' => 'Railway endpoint not configured.' ];
        } else {
            $r = wp_remote_get( "{$endpoint}/api/v1/health", [ 'timeout' => 8 ] );
            if ( is_wp_error( $r ) ) {
                $checks['railway'] = [ 'status' => 'fail', 'message' => 'Connection failed: ' . $r->get_error_message() ];
            } else {
                $code = wp_remote_retrieve_response_code( $r );
                $checks['railway'] = $code === 200
                    ? [ 'status' => 'pass', 'message' => "Railway healthy (HTTP {$code})." ]
                    : [ 'status' => 'fail', 'message' => "Railway returned HTTP {$code}." ];
            }
        }

        // Server key configured
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $checks['server_key'] = $server_key
            ? [ 'status' => 'pass', 'message' => 'Server key is configured.' ]
            : [ 'status' => 'warn', 'message' => 'No server key — pool and analytics calls may fail.' ];

        // DB tables
        $required_tables = [
            'knowly_children', 'knowly_exam_sessions', 'knowly_exam_answers',
            'knowly_topic_breakdown', 'knowly_exam_insights', 'knowly_weekly_insights',
            'knowly_notifications', 'knowly_gem_transactions', 'knowly_red_gem_transactions',
            'knowly_processed_webhooks', 'knowly_classes', 'knowly_class_members',
            'knowly_tasks', 'knowly_debug_log',
        ];
        $missing = [];
        foreach ( $required_tables as $t ) {
            $full = $wpdb->prefix . $t;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                $missing[] = $t;
            }
        }
        $checks['db_tables'] = empty( $missing )
            ? [ 'status' => 'pass', 'message' => count( $required_tables ) . ' tables present.' ]
            : [ 'status' => 'fail', 'message' => 'Missing: ' . implode( ', ', $missing ) ];

        // Roles
        $missing_roles = [];
        foreach ( [ 'knowly_parent', 'knowly_child', 'knowly_teacher' ] as $role ) {
            if ( ! get_role( $role ) ) $missing_roles[] = $role;
        }
        $checks['roles'] = empty( $missing_roles )
            ? [ 'status' => 'pass', 'message' => 'All 3 Knowly roles registered.' ]
            : [ 'status' => 'fail', 'message' => 'Missing roles: ' . implode( ', ', $missing_roles ) ];

        // Badge CPT
        $badge_post_type = get_post_type_object( 'knowly_badge' );
        $checks['badge_cpt'] = $badge_post_type
            ? [ 'status' => 'pass', 'message' => 'knowly_badge CPT registered.' ]
            : [ 'status' => 'fail', 'message' => 'knowly_badge CPT not registered.' ];

        // Gem cost defaults
        $quest_first  = get_option( 'knowly_gem_cost_quest_first_tt_primary' );
        $quest_retake = get_option( 'knowly_gem_cost_quest_retake_tt_primary' );
        $checks['gem_defaults'] = ( $quest_first !== false && $quest_retake !== false )
            ? [ 'status' => 'pass', 'message' => "Quest gem costs set ({$quest_first} first / {$quest_retake} retake)." ]
            : [ 'status' => 'warn', 'message' => 'Quest gem cost defaults not set. Defaults (3/1) will be used.' ];

        // Test accounts
        $test_parent  = get_user_by( 'email', 'test.parent@knowly.test' );
        $test_teacher = get_user_by( 'email', 'test.teacher@knowly.test' );
        $test_child   = get_user_by( 'login', 'test.child' );
        $has_all = $test_parent && $test_teacher && $test_child;
        $checks['test_accounts'] = $has_all
            ? [ 'status' => 'pass', 'message' => 'Test parent, teacher, and child accounts exist.' ]
            : [ 'status' => 'warn', 'message' => 'One or more test accounts missing. Run Test Suite → Block 2 Setup.' ];

        wp_send_json_success( [ 'checks' => $checks ] );
    }

    // ── AJAX: Gate Toggle ─────────────────────────────────────────────────────

    public static function ajax_set_gate(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $gate = sanitize_text_field( $_POST['gate'] ?? 'blocked' );
        if ( ! in_array( $gate, [ 'approved', 'blocked' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid gate value.' ] );
        }

        update_option( self::GATE_OPTION, $gate );

        Knowly_Debug::log( 'admin.signoff', 'Sign-off gate changed', [
            'gate'       => $gate,
            'changed_by' => get_current_user_id(),
        ], null, 'info' );

        wp_send_json_success( [ 'gate' => $gate ] );
    }

    // ── AJAX: Danger — Wipe Test Data ─────────────────────────────────────────

    public static function ajax_wipe_test_data(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $test_users = get_users( [
            'meta_key'   => 'knowly_is_test_account',
            'meta_value' => true,
            'fields'     => 'ID',
        ] );

        $count = 0;
        foreach ( $test_users as $uid ) {
            if ( user_can( $uid, 'manage_options' ) ) continue; // Never delete admins
            wp_delete_user( (int) $uid );
            $count++;
        }

        Knowly_Debug::log( 'admin.signoff', 'Test data wiped', [ 'deleted' => $count ], null, 'info' );
        wp_send_json_success( [ 'message' => "Deleted {$count} test account(s)." ] );
    }

    // ── AJAX: Danger — Reset Test Leaderboard ─────────────────────────────────

    public static function ajax_reset_leaderboard(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        $response = wp_remote_post( "{$endpoint}/api/v1/leaderboard/test/reset-board", [
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'level' => 'std_4', 'period' => 'term_1', 'subject' => 'math' ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 ) {
            wp_send_json_success( [ 'message' => 'Test leaderboard reset. Cleared: ' . ( $body['entries_cleared'] ?? 0 ) . ' entries.' ] );
        } else {
            wp_send_json_error( [ 'message' => "Railway returned HTTP {$code}: " . ( $body['error'] ?? '' ) ] );
        }
    }

    // ── AJAX: Danger — Stipend Reset ──────────────────────────────────────────

    public static function ajax_stipend_reset(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        $response = wp_remote_post( "{$endpoint}/api/v1/admin/stipend-reset", [
            'timeout' => 15,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'triggered_by' => 'manual' ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 ) {
            $affected = $body['teachers_affected'] ?? $body['affected'] ?? '?';
            wp_send_json_success( [ 'message' => "Stipend reset complete. Teachers affected: {$affected}." ] );
        } else {
            wp_send_json_error( [ 'message' => "Railway returned HTTP {$code}: " . ( $body['error'] ?? '' ) ] );
        }
    }

    // ── AJAX: Danger — Monthly Gem Reset ─────────────────────────────────────

    public static function ajax_gem_reset(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        // Trigger the WP cron action directly
        do_action( 'knowly_monthly_gem_refresh' );

        Knowly_Debug::log( 'admin.signoff', 'Monthly gem reset triggered manually', [], null, 'info' );
        wp_send_json_success( [ 'message' => 'Monthly blue gem refresh triggered. Check gem transaction logs.' ] );
    }

    // ── Health check labels ───────────────────────────────────────────────────

    private static function health_checks(): array {
        return [
            'jwt'           => 'JWT Secret',
            'railway'       => 'Railway Health',
            'server_key'    => 'Server Key',
            'db_tables'     => 'Database Tables',
            'roles'         => 'WP Roles',
            'badge_cpt'     => 'Badge CPT',
            'gem_defaults'  => 'Gem Cost Defaults',
            'test_accounts' => 'Test Accounts',
        ];
    }
}
