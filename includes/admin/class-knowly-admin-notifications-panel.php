<?php
/**
 * Knowly_Admin_Notifications_Panel — Notifications module admin page.
 *
 * Tabs: Queue | Health Checks | Unit Tests | Simulations
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Notifications_Panel {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_notif_panel_health', [ __CLASS__, 'ajax_health' ] );
        add_action( 'wp_ajax_knowly_notif_panel_mark_read', [ __CLASS__, 'ajax_mark_read' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab = sanitize_key( $_GET['tab'] ?? 'queue' );
        $tabs = [
            'queue'  => 'Queue',
            'health' => 'Health Checks',
            'tests'  => 'Unit Tests',
            'sims'   => 'Simulations',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Notifications</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-notifications-panel&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>
            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php
            match ( $tab ) {
                'queue'  => self::render_queue(),
                'health' => self::render_health(),
                'tests'  => self::render_tests(),
                'sims'   => self::render_simulations(),
                default  => self::render_queue(),
            };
            ?>
            </div>
        </div>
        <?php
    }

    // ── Queue Tab ─────────────────────────────────────────────────────────────

    private static function render_queue(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_notifications';

        $total_unread = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" );
        $total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $notifications = $wpdb->get_results(
            "SELECT n.*, u.display_name AS recipient_name
             FROM {$table} n
             LEFT JOIN {$wpdb->users} u ON n.recipient_user_id = u.ID
             ORDER BY n.created_at DESC
             LIMIT 100"
        );
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div class="knowly-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;max-width:500px;">
            <div class="knowly-stat-card" style="<?= $total_unread > 0 ? 'border-color:#d63638;' : '' ?>">
                <div class="knowly-stat-number" style="<?= $total_unread > 0 ? 'color:#d63638;' : '' ?>"><?= esc_html( $total_unread ) ?></div>
                <div class="knowly-stat-label">Unread</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number"><?= esc_html( $total ) ?></div>
                <div class="knowly-stat-label">Total (all time)</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number"><?= count( $notifications ) ?></div>
                <div class="knowly-stat-label">Showing (latest 100)</div>
            </div>
        </div>

        <?php if ( empty( $notifications ) ) : ?>
        <p style="color:#888;">No notifications found.</p>
        <?php else : ?>
        <table class="knowly-table widefat" style="font-size:12px;">
            <thead>
                <tr><th>Recipient</th><th>Type</th><th>Subject</th><th>Message</th><th>Read</th><th>Response</th><th>Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $notifications as $n ) : ?>
                <tr>
                    <td><?= esc_html( $n->recipient_name ?? 'User #' . $n->recipient_user_id ) ?></td>
                    <td><span class="knowly-badge <?= $n->type === 'confirmation' ? 'warn' : '' ?>"><?= esc_html( $n->type ) ?></span></td>
                    <td><?= esc_html( $n->subject ) ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= esc_html( $n->message ) ?></td>
                    <td><?= $n->is_read ? '<span style="color:#888;">read</span>' : '<strong style="color:#d63638;">unread</strong>' ?></td>
                    <td><?= $n->response ? esc_html( $n->response ) : '—' ?></td>
                    <td><?= esc_html( substr( $n->created_at, 0, 16 ) ) ?></td>
                    <td>
                        <?php if ( ! $n->is_read ) : ?>
                        <button class="button button-small" onclick="knowlyNotifPanel.markRead(<?= (int) $n->id ?>, this)">Mark Read</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        const knowlyNotifPanel = {
            markRead(id, btn) {
                jQuery(btn).prop('disabled', true);
                jQuery.post(ajaxurl, { action: 'knowly_notif_panel_mark_read', nonce: '<?= esc_js( $nonce ) ?>', id }, r => {
                    if (r.success) location.reload();
                    else { alert(r.data?.message || 'Error'); jQuery(btn).prop('disabled', false); }
                });
            }
        };
        </script>
        <?php endif; ?>
        <?php
    }

    // ── Health Checks Tab ─────────────────────────────────────────────────────

    private static function render_health(): void {
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <button id="notif-run-health" class="button button-primary" style="margin-bottom:16px;">Run Health Checks</button>
        <div id="notif-health-results"><p style="color:#888;">Click to run health checks.</p></div>
        <script>
        jQuery('#notif-run-health').on('click', function() {
            var $btn = jQuery(this).prop('disabled', true).text('Running…');
            jQuery.post(ajaxurl, { action: 'knowly_notif_panel_health', nonce: '<?= esc_js( $nonce ) ?>' }, function(res) {
                $btn.prop('disabled', false).text('Run Health Checks');
                if (!res.success) { jQuery('#notif-health-results').html('<p style="color:#dc2626;">' + (res.data?.message || 'Error') + '</p>'); return; }
                var html = '<table class="knowly-table" style="max-width:700px;"><tbody>';
                (res.data.checks || []).forEach(function(c) {
                    var col = c.status === 'pass' ? '#16a34a' : (c.status === 'warn' ? '#d97706' : '#dc2626');
                    html += '<tr><td style="color:' + col + ';font-weight:600;width:40px;">' + (c.status === 'pass' ? '✓' : c.status === 'warn' ? '⚠' : '✗') + '</td><td><strong>' + c.label + '</strong></td><td style="color:#666;">' + (c.detail || '') + '</td></tr>';
                });
                html += '</tbody></table>';
                jQuery('#notif-health-results').html(html);
            });
        });
        </script>
        <?php
    }

    // ── Unit Tests Tab ────────────────────────────────────────────────────────

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test notification create, list, count, respond, and mark-read.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'block4_notifications', 'block2_notifications' ] );
    }

    // ── Simulations Tab ───────────────────────────────────────────────────────

    private static function render_simulations(): void {
        ?>
        <p style="color:#666;">Simulate the full class invitation flow: teacher invites → parent receives notification → parent accepts → child added to class.</p>
        <div class="knowly-notice info" style="margin-top:12px;">Simulations coming soon. Run unit tests to validate individual endpoints.</div>
        <?php
    }

    // ── AJAX: Mark notification read ──────────────────────────────────────────

    public static function ajax_mark_read(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'id required' ] );

        $wpdb->update(
            $wpdb->prefix . 'knowly_notifications',
            [ 'is_read' => 1 ],
            [ 'id' => $id ]
        );

        wp_send_json_success( [ 'id' => $id ] );
    }

    // ── AJAX: Health ──────────────────────────────────────────────────────────

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $checks = [];
        $table  = $wpdb->prefix . 'knowly_notifications';

        // 1. Table exists
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $checks[] = [ 'label' => 'knowly_notifications table', 'status' => $exists === $table ? 'pass' : 'fail', 'detail' => $exists === $table ? 'Table exists.' : 'Missing — run plugin activation.' ];

        if ( $exists === $table ) {
            // 2. Read — count all
            $count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            $unread   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" );
            $checks[] = [ 'label' => 'Read verified', 'status' => 'pass', 'detail' => "{$count} total, {$unread} unread." ];

            // 3. Write test
            $inserted = $wpdb->insert( $table, [
                'recipient_user_id' => get_current_user_id(),
                'type'              => 'simple',
                'subject'           => '_health_check',
                'message'           => 'Health check write test.',
                'is_read'           => 1,
                'created_at'        => current_time( 'mysql' ),
            ] );
            if ( $inserted ) {
                $wpdb->delete( $table, [ 'subject' => '_health_check' ] );
                $checks[] = [ 'label' => 'Write verified', 'status' => 'pass', 'detail' => 'Insert and delete OK.' ];
            } else {
                $checks[] = [ 'label' => 'Write verified', 'status' => 'fail', 'detail' => 'Insert failed: ' . $wpdb->last_error ];
            }
        }

        wp_send_json_success( [ 'checks' => $checks ] );
    }
}
