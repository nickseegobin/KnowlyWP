<?php
/**
 * Knowly_Admin_Trials — Trial (Exam) module admin page.
 *
 * Tabs: Packages | Review Queue | Health Checks | Unit Tests | Simulations
 *
 * All package data sourced from Railway (Supabase) via server key or JWT.
 * Delegates package AJAX to Knowly_Admin_Pool handlers (already registered).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Trials {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_trials_health', [ __CLASS__, 'ajax_health' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab        = sanitize_key( $_GET['tab'] ?? 'packages' );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $nonce      = wp_create_nonce( 'knowly_admin_nonce' );

        $tabs = [
            'packages' => 'Packages',
            'review'   => 'Review Queue',
            'health'   => 'Health Checks',
            'tests'    => 'Unit Tests',
            'sims'     => 'Simulations',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Trials</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-trials&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( ! $railway_ok ) : ?>
            <div class="notice notice-warning inline" style="margin:8px 0 0;"><p>Railway endpoint not configured. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php
            match ( $tab ) {
                'packages' => self::render_packages( $railway_ok, $server_key, $nonce ),
                'review'   => self::render_review_queue( $railway_ok, $nonce ),
                'health'   => self::render_health( $nonce ),
                'tests'    => self::render_tests(),
                'sims'     => self::render_simulations(),
                default    => self::render_packages( $railway_ok, $server_key, $nonce ),
            };
            ?>
            </div>
        </div>
        <?php
    }

    // ── Packages Tab ──────────────────────────────────────────────────────────

    private static function render_packages( bool $railway_ok, string $server_key, string $nonce ): void {
        ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <button id="knowly-load-trials" class="button button-primary" <?= $railway_ok ? '' : 'disabled' ?>>
                ↓ Load Trial Pool Inventory
            </button>
            <input type="text" id="knowly-trial-filter" placeholder="Filter by subject…" class="regular-text" style="height:30px;" />
            <span id="knowly-trial-summary-text" style="color:#666;font-size:13px;"></span>
        </div>
        <div id="knowly-trial-results">
            <p style="color:#888;">Click "Load Trial Pool Inventory" to fetch current stats from Railway.</p>
        </div>

        <!-- Package detail modal -->
        <div id="knowly-pkg-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;overflow:auto;">
            <div style="background:#fff;max-width:940px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                <div style="padding:14px 20px;background:#f6f7f7;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                    <h2 id="knowly-modal-title" style="margin:0;font-size:15px;"></h2>
                    <button id="knowly-modal-close" class="button">✕ Close</button>
                </div>
                <div id="knowly-modal-body" style="padding:20px;max-height:72vh;overflow:auto;font-size:13px;"></div>
            </div>
        </div>
        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';
            $('#knowly-load-trials').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                $.post(ajaxurl, { action: 'knowly_pool_trial_summary', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('↓ Load Trial Pool Inventory');
                    if (!res.success) { $('#knowly-trial-results').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown error') + '</p>'); return; }
                    renderTrialTable(res.data);
                });
            });
            $('#knowly-trial-filter').on('input', function() {
                var q = $(this).val().toLowerCase();
                $('#knowly-trial-results tbody tr').each(function() {
                    $(this).toggle(!q || $(this).text().toLowerCase().includes(q));
                });
            });
            function renderTrialTable(data) {
                var slots = data.slots || [];
                $('#knowly-trial-summary-text').text(data.total_packages + ' packages across ' + data.slot_count + ' slots');
                if (!slots.length) { $('#knowly-trial-results').html('<p style="color:#666;">No approved trial packages found.</p>'); return; }
                var html = '<table class="knowly-table widefat" style="font-size:12px;"><thead><tr><th>Level</th><th>Period</th><th>Subject</th><th>Difficulty</th><th>Count</th><th>Served</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
                $.each(slots, function(i, s) {
                    var status = s.count === 0 ? '<span style="color:#dc2626;font-weight:600;">Empty</span>' : s.count < 3 ? '<span style="color:#d97706;font-weight:600;">Low</span>' : '<span style="color:#16a34a;font-weight:600;">Ready</span>';
                    html += '<tr><td>' + s.level + '</td><td>' + (s.period || '<em>SEA</em>') + '</td><td><strong>' + s.subject + '</strong></td><td>' + (s.difficulty || '—') + '</td>'
                        + '<td style="text-align:center;font-weight:600;">' + s.count + '</td><td style="text-align:center;color:#888;">' + s.total_served + '</td>'
                        + '<td>' + status + '</td><td style="white-space:nowrap;">'
                        + '<button class="button button-small knowly-view-slot" data-level="' + s.level + '" data-period="' + (s.period||'') + '" data-subject="' + s.subject + '" data-difficulty="' + (s.difficulty||'') + '" style="margin-right:4px;">View</button>'
                        + '<button class="button button-small knowly-gen-trial" data-level="' + s.level + '" data-period="' + (s.period||'') + '" data-subject="' + s.subject + '" data-difficulty="' + (s.difficulty||'') + '">Generate</button>'
                        + '</td></tr>';
                });
                html += '</tbody></table>';
                $('#knowly-trial-results').html(html);
            }
            $(document).on('click', '.knowly-view-slot', function() {
                var $btn = $(this).prop('disabled', true).text('…');
                $.post(ajaxurl, { action: 'knowly_pool_trial_packages', nonce: nonce, level: $(this).data('level'), period: $(this).data('period'), subject: $(this).data('subject'), difficulty: $(this).data('difficulty') }, function(res) {
                    $btn.prop('disabled', false).text('View');
                    if (res.success) openModal(res.data.html); else openModal('<p style="color:#dc2626;">' + (res.data.message || 'Failed') + '</p>');
                });
            });
            $(document).on('click', '.knowly-gen-trial', function() {
                var $btn = $(this).prop('disabled', true).text('Generating…');
                $.post(ajaxurl, { action: 'knowly_pool_generate_trial', nonce: nonce, level: $(this).data('level'), period: $(this).data('period'), subject: $(this).data('subject'), difficulty: $(this).data('difficulty') }, function(res) {
                    $btn.prop('disabled', false).text('Generate');
                    alert(res.success ? 'Generated: ' + res.data.package_id : 'Error: ' + (res.data.message || 'Failed'));
                });
            });
            function openModal(html) {
                $('#knowly-modal-body').html(html);
                $('#knowly-pkg-modal').show();
            }
            $('#knowly-modal-close').on('click', function() { $('#knowly-pkg-modal').hide(); });
            $('#knowly-pkg-modal').on('click', function(e) { if ($(e.target).is(this)) $(this).hide(); });
        })(jQuery);
        </script>
        <?php
    }

    // ── Review Queue Tab ──────────────────────────────────────────────────────

    private static function render_review_queue( bool $railway_ok, string $nonce ): void {
        ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <button id="knowly-load-review" class="button button-primary" <?= $railway_ok ? '' : 'disabled' ?>>
                ↓ Load Review Queue
            </button>
            <span id="knowly-review-summary-text" style="color:#666;font-size:13px;"></span>
        </div>
        <p style="font-size:13px;color:#666;margin-bottom:16px;">
            Packages generated via <code>force_generate: true</code>. Review content, then approve or reject.
            Approved packages enter the pool immediately.
        </p>
        <div id="knowly-review-results">
            <p style="color:#888;">Click "Load Review Queue" to fetch pending packages from Railway.</p>
        </div>
        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';
            $('#knowly-load-review').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                $.post(ajaxurl, { action: 'knowly_pool_review_queue', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('↓ Load Review Queue');
                    if (!res.success) { $('#knowly-review-results').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown error') + '</p>'); return; }
                    renderQueue(res.data);
                });
            });
            function renderQueue(data) {
                var packages = data.packages || [];
                $('#knowly-review-summary-text').text(packages.length + ' package(s) pending review');
                if (!packages.length) { $('#knowly-review-results').html('<p style="color:#666;">No packages pending review.</p>'); return; }
                var html = '<table class="knowly-table widefat" style="font-size:12px;"><thead><tr><th>Package ID</th><th>Level</th><th>Period</th><th>Subject</th><th>Difficulty</th><th>Type</th><th>Actions</th></tr></thead><tbody>';
                $.each(packages, function(i, pkg) {
                    var meta = pkg.meta || {};
                    var pid  = pkg.package_id || '—';
                    html += '<tr id="rw-' + pid.replace(/[^a-z0-9]/gi,'_') + '"><td style="font-family:monospace;">' + pid + '</td>'
                        + '<td>' + (meta.level||'') + '</td><td>' + (meta.period||'<em>SEA</em>') + '</td>'
                        + '<td><strong>' + (meta.subject||'') + '</strong></td><td>' + (meta.difficulty||'—') + '</td><td>' + (meta.trial_type||'practice') + '</td>'
                        + '<td style="white-space:nowrap;">'
                        + '<button class="button button-small" style="color:#16a34a;margin-right:4px;" onclick="knowlyApproveTrials(\'' + pid + '\',\'approve\',this)">✓ Approve</button>'
                        + '<button class="button button-small" style="color:#dc2626;" onclick="knowlyApproveTrials(\'' + pid + '\',\'reject\',this)">✗ Reject</button>'
                        + '</td></tr>';
                });
                html += '</tbody></table>';
                $('#knowly-review-results').html(html);
            }
            window.knowlyApproveTrials = function(packageId, action, btn) {
                if (!confirm((action === 'approve' ? 'Approve' : 'Reject') + ' package ' + packageId + '?')) return;
                $(btn).prop('disabled', true);
                $.post(ajaxurl, { action: 'knowly_pool_approve_package', nonce: nonce, package_id: packageId, approve_action: action }, function(res) {
                    if (res.success) {
                        $('#rw-' + packageId.replace(/[^a-z0-9]/gi,'_')).html('<td colspan="7" style="color:' + (action === 'approve' ? '#16a34a' : '#dc2626') + ';padding:8px 12px;">' + (action === 'approve' ? '✓ Approved' : '✗ Rejected') + '</td>');
                    } else { alert('Error: ' + (res.data.message || 'Failed')); $(btn).prop('disabled', false); }
                });
            };
        })(jQuery);
        </script>
        <?php
    }

    // ── Health Checks Tab ─────────────────────────────────────────────────────

    private static function render_health( string $nonce ): void {
        ?>
        <button id="knowly-trials-run-health" class="button button-primary" style="margin-bottom:16px;">Run Health Checks</button>
        <div id="knowly-trials-health-results">
            <p style="color:#888;">Click to run health checks.</p>
        </div>
        <script>
        jQuery('#knowly-trials-run-health').on('click', function() {
            var $btn = jQuery(this).prop('disabled', true).text('Running…');
            jQuery.post(ajaxurl, { action: 'knowly_trials_health', nonce: '<?= esc_js( $nonce ) ?>' }, function(res) {
                $btn.prop('disabled', false).text('Run Health Checks');
                if (!res.success) { jQuery('#knowly-trials-health-results').html('<p style="color:#dc2626;">Error: ' + (res.data?.message || 'Failed') + '</p>'); return; }
                var checks = res.data.checks || [];
                var html = '<table class="knowly-table" style="max-width:700px;"><tbody>';
                checks.forEach(function(c) {
                    var color = c.status === 'pass' ? '#16a34a' : (c.status === 'warn' ? '#d97706' : '#dc2626');
                    var icon  = c.status === 'pass' ? '✓' : (c.status === 'warn' ? '⚠' : '✗');
                    html += '<tr><td style="color:' + color + ';font-weight:600;width:40px;">' + icon + '</td>'
                          + '<td><strong>' + c.label + '</strong></td><td style="color:#666;">' + (c.detail || '') + '</td></tr>';
                });
                html += '</tbody></table>';
                jQuery('#knowly-trials-health-results').html(html);
            });
        });
        </script>
        <?php
    }

    // ── Unit Tests Tab ────────────────────────────────────────────────────────

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test exam catalogue, trial start, checkpoint, submit, results and insights.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'exams', 'results', 'insights' ] );
    }

    // ── Simulations Tab ───────────────────────────────────────────────────────

    private static function render_simulations(): void {
        ?>
        <p style="color:#666;">Simulations run a full end-to-end journey using test accounts. Results are non-destructive to real data.</p>
        <div class="knowly-notice info" style="margin-top:12px;">Simulations coming soon. Run unit tests to validate individual endpoints.</div>
        <?php
    }

    // ── AJAX: Health Checks ───────────────────────────────────────────────────

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $checks = [];

        // 1. Railway reachable
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            $checks[] = [ 'label' => 'Railway endpoint', 'status' => 'fail', 'detail' => 'Not configured in Settings.' ];
        } else {
            $resp = wp_remote_get( $endpoint . '/api/v1/health', [ 'timeout' => 8 ] );
            $ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
            $detail   = $ok ? $endpoint : ( is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $resp ) );
            $checks[] = [ 'label' => 'Railway reachable', 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail ];
        }

        // 2. Server key configured
        $checks[] = [
            'label'  => 'Server key configured',
            'status' => get_option( 'knowly_railway_server_key' ) ? 'pass' : 'warn',
            'detail' => get_option( 'knowly_railway_server_key' ) ? 'AEP_SERVER_KEY is set.' : 'Missing — full packages not available.',
        ];

        // 3. Pool has approved packages
        if ( $endpoint && get_option( 'knowly_railway_server_key' ) ) {
            $resp = wp_remote_get( $endpoint . '/api/v1/pool/summary?status=approved', [
                'timeout' => 10,
                'headers' => [ 'X-AEP-Server-Key' => get_option( 'knowly_railway_server_key' ) ],
            ] );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
                $count = $body['total_packages'] ?? 0;
                $checks[] = [ 'label' => 'Approved pool packages', 'status' => $count > 0 ? 'pass' : 'warn', 'detail' => "{$count} approved package(s) in pool." ];
            } else {
                $checks[] = [ 'label' => 'Approved pool packages', 'status' => 'fail', 'detail' => 'Could not reach pool/summary.' ];
            }
        }

        // 4. DB: exam_sessions writable
        global $wpdb;
        $table  = $wpdb->prefix . 'knowly_exam_sessions';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $checks[] = [ 'label' => 'exam_sessions table', 'status' => $exists === $table ? 'pass' : 'fail', 'detail' => $exists === $table ? 'Table exists.' : 'Missing — run plugin activation.' ];

        wp_send_json_success( [ 'checks' => $checks ] );
    }
}
