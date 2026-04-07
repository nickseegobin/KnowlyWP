<?php
/**
 * Knowly_Admin_Analytics — Analytics module admin page.
 *
 * Tabs: Overview | Health Checks | Unit Tests | Simulations
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Analytics {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_analytics_health', [ __CLASS__, 'ajax_health' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab = sanitize_key( $_GET['tab'] ?? 'overview' );
        $tabs = [
            'overview' => 'Overview',
            'health'   => 'Health Checks',
            'tests'    => 'Unit Tests',
            'sims'     => 'Simulations',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Analytics</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>
            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php
            match ( $tab ) {
                'overview' => self::render_overview(),
                'health'   => self::render_health(),
                'tests'    => self::render_tests(),
                'sims'     => self::render_simulations(),
                default    => self::render_overview(),
            };
            ?>
            </div>
        </div>
        <?php
    }

    private static function render_overview(): void {
        global $wpdb;
        $class_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes WHERE status = 'active'" );
        $member_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members WHERE status = 'active'" );
        $task_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks WHERE status = 'active'" );
        $top_classes  = $wpdb->get_results(
            "SELECT c.id, c.name, c.level, COUNT(m.id) AS member_count
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->prefix}knowly_class_members m ON m.class_id = c.id AND m.status = 'active'
             WHERE c.status = 'active' GROUP BY c.id ORDER BY member_count DESC LIMIT 10"
        );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        ?>
        <p style="color:#666;margin-bottom:16px;">System-wide stats. Per-class drill-down is consumed directly by the React teacher dashboard.</p>
        <div class="knowly-stat-grid">
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div><div class="knowly-stat-label">Active Classes</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $member_count ) ?></div><div class="knowly-stat-label">Enrolled Students</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $task_count ) ?></div><div class="knowly-stat-label">Active Tasks</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= $railway_ok ? '<span style="color:#00a32a">●</span>' : '<span style="color:#d63638">●</span>' ?></div><div class="knowly-stat-label">Railway</div></div>
        </div>
        <h3 style="margin-top:20px;">Classes</h3>
        <?php if ( empty( $top_classes ) ) : ?>
        <p style="color:#888;">No active classes.</p>
        <?php else : ?>
        <table class="knowly-table widefat">
            <thead><tr><th>ID</th><th>Name</th><th>Level</th><th>Members</th><th>Analytics endpoint</th></tr></thead>
            <tbody>
            <?php foreach ( $top_classes as $class ) : ?>
            <tr>
                <td><?= esc_html( $class->id ) ?></td>
                <td><?= esc_html( $class->name ) ?></td>
                <td><?= esc_html( $class->level ) ?></td>
                <td><?= esc_html( $class->member_count ) ?></td>
                <td><code style="font-size:11px;">/knowly/v1/analytics/class/<?= esc_html( $class->id ) ?></code></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    private static function render_health(): void {
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <button id="analytics-run-health" class="button button-primary" style="margin-bottom:16px;">Run Health Checks</button>
        <div id="analytics-health-results"><p style="color:#888;">Click to run health checks.</p></div>
        <script>
        jQuery('#analytics-run-health').on('click', function() {
            var $btn = jQuery(this).prop('disabled', true).text('Running…');
            jQuery.post(ajaxurl, { action: 'knowly_analytics_health', nonce: '<?= esc_js( $nonce ) ?>' }, function(res) {
                $btn.prop('disabled', false).text('Run Health Checks');
                if (!res.success) { jQuery('#analytics-health-results').html('<p style="color:#dc2626;">' + (res.data?.message || 'Error') + '</p>'); return; }
                var html = '<table class="knowly-table" style="max-width:700px;"><tbody>';
                (res.data.checks || []).forEach(function(c) {
                    var col = c.status === 'pass' ? '#16a34a' : (c.status === 'warn' ? '#d97706' : '#dc2626');
                    html += '<tr><td style="color:' + col + ';font-weight:600;width:40px;">' + (c.status === 'pass' ? '✓' : '⚠') + '</td><td><strong>' + c.label + '</strong></td><td style="color:#666;">' + (c.detail || '') + '</td></tr>';
                });
                html += '</tbody></table>';
                jQuery('#analytics-health-results').html(html);
            });
        });
        </script>
        <?php
    }

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test class analytics aggregation, per-student drill-down, and access control enforcement.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'block7_analytics' ] );
    }

    private static function render_simulations(): void {
        ?>
        <p style="color:#666;">Full teacher analytics journey: class dashboard view, per-student deep dive, source assignment vs direct.</p>
        <div class="knowly-notice info" style="margin-top:12px;">Simulations coming soon.</div>
        <?php
    }

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $checks = [];
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );

        // 1. Railway analytics routes reachable
        if ( $endpoint ) {
            $resp = wp_remote_get( $endpoint . '/api/v1/health', [ 'timeout' => 8 ] );
            $ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
            $checks[] = [ 'label' => 'Railway reachable', 'status' => $ok ? 'pass' : 'fail', 'detail' => $ok ? 'Analytics routes reachable.' : 'Railway unreachable.' ];
        } else {
            $checks[] = [ 'label' => 'Railway endpoint', 'status' => 'fail', 'detail' => 'Not configured.' ];
        }

        // 2. Access control: test teacher exists
        $test_teacher = get_user_by( 'login', 'test.teacher' );
        $checks[] = [ 'label' => 'Test teacher account', 'status' => $test_teacher ? 'pass' : 'warn', 'detail' => $test_teacher ? 'test.teacher exists (ID ' . $test_teacher->ID . ').' : 'Not found — run provisioning.' ];

        // 3. Classes table has data
        global $wpdb;
        $class_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes WHERE status = 'active'" );
        $checks[] = [ 'label' => 'Active classes exist', 'status' => $class_count > 0 ? 'pass' : 'warn', 'detail' => "{$class_count} active class(es). Run Block 5 tests to create one." ];

        wp_send_json_success( [ 'checks' => $checks ] );
    }
}
