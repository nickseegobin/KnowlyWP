<?php
/**
 * Knowly_Admin_Pool — Pool Manager admin page.
 *
 * All pool data is sourced from Railway (Supabase) — there is no WP-local pool table.
 * Data is loaded on-demand via AJAX to avoid slow page loads from Railway calls.
 *
 * Tabs:
 *   Trial Packages  — inventory per slot (level/period/subject/difficulty) from Railway
 *   Quest Catalogue — approved quests per level/subject from Railway
 *   Review Queue    — pending_review trial packages awaiting admin approval
 *
 * Railway endpoints used:
 *   GET  /api/v1/pool/summary            (X-AEP-Server-Key)
 *   GET  /api/v1/pool                    (X-AEP-Server-Key, filtered)
 *   GET  /api/v1/quest/catalogue         (Bearer JWT)
 *   POST /api/v1/generate-exam           (X-AEP-Server-Key, force_generate:true)
 *   POST /api/v1/quest/generate          (X-AEP-Server-Key)
 *   PATCH /api/v1/pool/approve           (X-AEP-Server-Key)
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Pool {

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_pool_trial_summary',  [ __CLASS__, 'ajax_trial_summary' ] );
        add_action( 'wp_ajax_knowly_pool_trial_packages', [ __CLASS__, 'ajax_trial_packages' ] );
        add_action( 'wp_ajax_knowly_pool_quest_catalogue', [ __CLASS__, 'ajax_quest_catalogue' ] );
        add_action( 'wp_ajax_knowly_pool_review_queue',   [ __CLASS__, 'ajax_review_queue' ] );
        add_action( 'wp_ajax_knowly_pool_generate_trial', [ __CLASS__, 'ajax_generate_trial' ] );
        add_action( 'wp_ajax_knowly_pool_generate_quest', [ __CLASS__, 'ajax_generate_quest' ] );
        add_action( 'wp_ajax_knowly_pool_approve_package', [ __CLASS__, 'ajax_approve_package' ] );

        // Legacy handlers referenced elsewhere — re-route to new implementations
        add_action( 'wp_ajax_knowly_pool_packages',      [ __CLASS__, 'ajax_trial_packages' ] );
        add_action( 'wp_ajax_knowly_railway_catalogue',  [ __CLASS__, 'ajax_trial_summary' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $railway_ok  = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $server_key  = get_option( 'knowly_railway_server_key', '' );
        $nonce       = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div class="wrap knowly-wrap">
            <h1>Pool Manager</h1>
            <p style="color:#666;margin-bottom:16px;">
                All package data is sourced from Railway (Supabase). Click a tab and load to fetch live inventory.
            </p>

            <?php if ( ! $railway_ok ) : ?>
            <div class="notice notice-warning">
                <p>Railway endpoint not configured. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Configure in Settings →</a></p>
            </div>
            <?php endif; ?>

            <?php if ( $railway_ok && ! $server_key ) : ?>
            <div class="notice notice-warning">
                <p>No <strong>Server Key</strong> configured. Package details and answer sheets will not be available. Set it in <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings</a>.</p>
            </div>
            <?php endif; ?>

            <!-- Tab nav -->
            <div style="display:flex;gap:0;margin-bottom:0;border-bottom:2px solid #c3c4c7;">
                <button class="knowly-pool-tab button" data-tab="trials" style="border-radius:4px 4px 0 0;border-bottom:2px solid #2271b1;margin-bottom:-2px;background:#fff;color:#2271b1;font-weight:600;">
                    Trial Packages
                </button>
                <button class="knowly-pool-tab button" data-tab="quests" style="border-radius:4px 4px 0 0;background:#f6f7f7;border-bottom:none;">
                    Quest Catalogue
                </button>
                <button class="knowly-pool-tab button" data-tab="review" style="border-radius:4px 4px 0 0;background:#f6f7f7;border-bottom:none;">
                    Review Queue
                </button>
            </div>

            <!-- ── TRIAL PACKAGES ─────────────────────────────────────────── -->
            <div id="knowly-tab-trials" class="knowly-pool-panel" style="border:1px solid #c3c4c7;border-top:none;padding:20px;background:#fff;">
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
            </div>

            <!-- ── QUEST CATALOGUE ────────────────────────────────────────── -->
            <div id="knowly-tab-quests" class="knowly-pool-panel" style="display:none;border:1px solid #c3c4c7;border-top:none;padding:20px;background:#fff;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                    <select id="knowly-quest-level" style="height:30px;">
                        <option value="std_1">std_1</option>
                        <option value="std_2">std_2</option>
                        <option value="std_3">std_3</option>
                        <option value="std_4" selected>std_4</option>
                        <option value="std_5">std_5</option>
                    </select>
                    <select id="knowly-quest-period-filter" style="height:30px;">
                        <option value="">All periods</option>
                        <option value="term_1">term_1</option>
                        <option value="term_2">term_2</option>
                        <option value="term_3">term_3</option>
                    </select>
                    <button id="knowly-load-quests" class="button button-primary" <?= $railway_ok ? '' : 'disabled' ?>>
                        ↓ Load Quest Catalogue
                    </button>
                    <span id="knowly-quest-summary-text" style="color:#666;font-size:13px;"></span>
                </div>
                <div id="knowly-quest-results">
                    <p style="color:#888;">Click "Load Quest Catalogue" to fetch approved quests from Railway.</p>
                </div>
                <div style="margin-top:20px;padding:16px;background:#f6f7f7;border-radius:4px;border:1px solid #e5e7eb;">
                    <h3 style="margin:0 0 12px;">Generate Quest</h3>
                    <p style="font-size:12px;color:#666;margin:0 0 10px;">Generate a new quest and store it as approved. Requires Railway server key.</p>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr) auto;gap:8px;align-items:end;">
                        <label style="font-size:12px;">Level<br><input type="text" id="gen-quest-level" class="regular-text" placeholder="std_4" style="margin-top:4px;" /></label>
                        <label style="font-size:12px;">Period<br><input type="text" id="gen-quest-period" class="regular-text" placeholder="term_1 or blank" style="margin-top:4px;" /></label>
                        <label style="font-size:12px;">Subject<br><input type="text" id="gen-quest-subject" class="regular-text" placeholder="math" style="margin-top:4px;" /></label>
                        <label style="font-size:12px;">Module Index (0-based)<br><input type="number" id="gen-quest-module-index" class="regular-text" value="0" min="0" style="margin-top:4px;" /></label>
                        <button id="knowly-generate-quest" class="button button-primary" style="height:30px;align-self:end;" <?= ( $railway_ok && $server_key ) ? '' : 'disabled' ?>>Generate</button>
                    </div>
                    <div id="knowly-quest-gen-result" style="margin-top:10px;font-size:13px;"></div>
                </div>
            </div>

            <!-- ── REVIEW QUEUE ───────────────────────────────────────────── -->
            <div id="knowly-tab-review" class="knowly-pool-panel" style="display:none;border:1px solid #c3c4c7;border-top:none;padding:20px;background:#fff;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                    <button id="knowly-load-review" class="button button-primary" <?= $railway_ok ? '' : 'disabled' ?>>
                        ↓ Load Review Queue
                    </button>
                    <span id="knowly-review-summary-text" style="color:#666;font-size:13px;"></span>
                </div>
                <p style="font-size:13px;color:#666;margin-bottom:16px;">
                    These packages were generated via the Editor (<code>force_generate: true</code>). Review the content, then approve or reject.
                    Approved packages enter the pool immediately. Rejected packages are excluded from delivery.
                </p>
                <div id="knowly-review-results">
                    <p style="color:#888;">Click "Load Review Queue" to fetch pending packages from Railway.</p>
                </div>
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

        </div>

        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';
            var ajaxUrl = '<?= esc_js( admin_url( 'admin-ajax.php' ) ) ?>';

            // ── Tab switching ─────────────────────────────────────────────────
            $('.knowly-pool-tab').on('click', function() {
                var tab = $(this).data('tab');
                $('.knowly-pool-tab').css({ background: '#f6f7f7', color: '', fontWeight: '', borderBottom: 'none' });
                $(this).css({ background: '#fff', color: '#2271b1', fontWeight: '600', borderBottom: '2px solid #2271b1', marginBottom: '-2px' });
                $('.knowly-pool-panel').hide();
                $('#knowly-tab-' + tab).show();
            });

            // ── Trial inventory ───────────────────────────────────────────────
            $('#knowly-load-trials').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                $.post(ajaxUrl, { action: 'knowly_pool_trial_summary', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('↓ Load Trial Pool Inventory');
                    if (!res.success) { $('#knowly-trial-results').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown error') + '</p>'); return; }
                    renderTrialTable(res.data);
                });
            });

            $('#knowly-trial-filter').on('input', function() {
                var q = $(this).val().toLowerCase();
                $('#knowly-trial-results tbody tr').each(function() {
                    $(this).toggle(!q || $(this).text().toLowerCase().indexOf(q) >= 0);
                });
            });

            function renderTrialTable(data) {
                var slots = data.slots || [];
                $('#knowly-trial-summary-text').text(data.total_packages + ' packages across ' + data.slot_count + ' slots');
                if (!slots.length) { $('#knowly-trial-results').html('<p style="color:#666;">No approved trial packages found.</p>'); return; }

                var html = '<table class="knowly-table widefat" style="font-size:12px;">';
                html += '<thead><tr><th>Level</th><th>Period</th><th>Subject</th><th>Difficulty</th><th>Count</th><th>Served</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
                $.each(slots, function(i, s) {
                    var status = s.count === 0 ? '<span style="color:#dc2626;font-weight:600;">Empty</span>'
                               : s.count < 3  ? '<span style="color:#d97706;font-weight:600;">Low</span>'
                               : '<span style="color:#16a34a;font-weight:600;">Ready</span>';
                    html += '<tr>'
                        + '<td>' + s.level + '</td>'
                        + '<td>' + (s.period || '<em>SEA</em>') + '</td>'
                        + '<td><strong>' + s.subject + '</strong></td>'
                        + '<td>' + (s.difficulty || '—') + '</td>'
                        + '<td style="text-align:center;font-weight:600;">' + s.count + '</td>'
                        + '<td style="text-align:center;color:#888;">' + s.total_served + '</td>'
                        + '<td>' + status + '</td>'
                        + '<td style="white-space:nowrap;">'
                        + '<button class="button button-small knowly-view-slot" data-level="' + s.level + '" data-period="' + (s.period||'') + '" data-subject="' + s.subject + '" data-difficulty="' + (s.difficulty||'') + '" style="margin-right:4px;">View</button>'
                        + '<button class="button button-small knowly-gen-trial" data-level="' + s.level + '" data-period="' + (s.period||'') + '" data-subject="' + s.subject + '" data-difficulty="' + (s.difficulty||'') + '">Generate</button>'
                        + '</td></tr>';
                });
                html += '</tbody></table>';
                $('#knowly-trial-results').html(html);
            }

            $(document).on('click', '.knowly-view-slot', function() {
                var $btn = $(this).prop('disabled', true).text('…');
                var level = $(this).data('level'), period = $(this).data('period'),
                    subject = $(this).data('subject'), diff = $(this).data('difficulty');
                $.post(ajaxUrl, {
                    action: 'knowly_pool_trial_packages', nonce: nonce,
                    level: level, period: period, subject: subject, difficulty: diff
                }, function(res) {
                    $btn.prop('disabled', false).text('View');
                    if (res.success) openModal(subject + ' ' + diff + ' (' + level + '/' + period + ')', res.data.html);
                    else openModal('Error', '<p style="color:#dc2626;">' + (res.data.message || 'Failed') + '</p>');
                });
            });

            $(document).on('click', '.knowly-gen-trial', function() {
                var $btn = $(this).prop('disabled', true).text('Generating…');
                var level = $(this).data('level'), period = $(this).data('period'),
                    subject = $(this).data('subject'), diff = $(this).data('difficulty');
                $.post(ajaxUrl, {
                    action: 'knowly_pool_generate_trial', nonce: nonce,
                    level: level, period: period, subject: subject, difficulty: diff
                }, function(res) {
                    $btn.prop('disabled', false).text('Generate');
                    if (res.success) alert('Generated: ' + res.data.package_id);
                    else alert('Error: ' + (res.data.message || 'Failed'));
                });
            });

            // ── Quest catalogue ───────────────────────────────────────────────
            $('#knowly-load-quests').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                var level  = $('#knowly-quest-level').val();
                var period = $('#knowly-quest-period-filter').val();
                $.post(ajaxUrl, { action: 'knowly_pool_quest_catalogue', nonce: nonce, level: level, period: period }, function(res) {
                    $btn.prop('disabled', false).text('↓ Load Quest Catalogue');
                    if (!res.success) { $('#knowly-quest-results').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown error') + '</p>'); return; }
                    renderQuestTable(res.data);
                });
            });

            function renderQuestTable(data) {
                var quests = data.quests || [];
                $('#knowly-quest-summary-text').text(quests.length + ' approved quest(s)');
                if (!quests.length) {
                    $('#knowly-quest-results').html('<p style="color:#666;">No approved quests found. Generate some using the form below.</p>');
                    return;
                }
                var html = '<table class="knowly-table widefat" style="font-size:12px;">';
                html += '<thead><tr><th>Quest ID</th><th>Level</th><th>Period</th><th>Subject</th><th>Module</th><th>Topic</th><th>Generated</th></tr></thead><tbody>';
                $.each(quests, function(i, q) {
                    html += '<tr>'
                        + '<td style="font-family:monospace;">' + q.quest_id + '</td>'
                        + '<td>' + (q.level||'') + '</td>'
                        + '<td>' + (q.period||'<em>capstone</em>') + '</td>'
                        + '<td><strong>' + (q.subject||'') + '</strong></td>'
                        + '<td>' + (q.module_number != null ? q.module_number : '—') + '</td>'
                        + '<td>' + (q.topic||q.module_title||'—') + '</td>'
                        + '<td>' + (q.generated_at ? q.generated_at.slice(0,10) : '—') + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                $('#knowly-quest-results').html(html);
            }

            $('#knowly-generate-quest').on('click', function() {
                var level = $('#gen-quest-level').val().trim();
                var period = $('#gen-quest-period').val().trim();
                var subject = $('#gen-quest-subject').val().trim();
                var moduleIndex = parseInt($('#gen-quest-module-index').val(), 10);
                if (!level || !subject) { alert('Level and Subject are required.'); return; }

                var $btn = $(this).prop('disabled', true).text('Generating…');
                var $result = $('#knowly-quest-gen-result');
                $result.html('<em>Generating… this may take 10–20 seconds.</em>');

                $.post(ajaxUrl, {
                    action: 'knowly_pool_generate_quest', nonce: nonce,
                    level: level, period: period, subject: subject, module_index: moduleIndex
                }, function(res) {
                    $btn.prop('disabled', false).text('Generate');
                    if (res.success) {
                        $result.html('<span style="color:#16a34a;">✓ Generated: <strong>' + res.data.quest_id + '</strong> (status: ' + res.data.status + ')</span>');
                    } else {
                        $result.html('<span style="color:#dc2626;">✗ ' + (res.data.message || 'Generation failed') + '</span>');
                    }
                });
            });

            // ── Review queue ──────────────────────────────────────────────────
            $('#knowly-load-review').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                $.post(ajaxUrl, { action: 'knowly_pool_review_queue', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('↓ Load Review Queue');
                    if (!res.success) { $('#knowly-review-results').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown error') + '</p>'); return; }
                    renderReviewQueue(res.data);
                });
            });

            function renderReviewQueue(data) {
                var packages = data.packages || [];
                $('#knowly-review-summary-text').text(packages.length + ' package(s) pending review');
                if (!packages.length) { $('#knowly-review-results').html('<p style="color:#666;">No packages pending review.</p>'); return; }

                var html = '<table class="knowly-table widefat" style="font-size:12px;">';
                html += '<thead><tr><th>Package ID</th><th>Level</th><th>Period</th><th>Subject</th><th>Difficulty</th><th>Type</th><th>Actions</th></tr></thead><tbody>';
                $.each(packages, function(i, pkg) {
                    var meta = pkg.meta || {};
                    var pid  = pkg.package_id || '—';
                    html += '<tr id="review-row-' + pid.replace(/[^a-z0-9]/gi, '_') + '">'
                        + '<td style="font-family:monospace;">' + pid + '</td>'
                        + '<td>' + (meta.level||'') + '</td>'
                        + '<td>' + (meta.period||'<em>SEA</em>') + '</td>'
                        + '<td><strong>' + (meta.subject||'') + '</strong></td>'
                        + '<td>' + (meta.difficulty||'—') + '</td>'
                        + '<td>' + (meta.trial_type||'practice') + '</td>'
                        + '<td style="white-space:nowrap;">'
                        + '<button class="button button-small" style="color:#16a34a;margin-right:4px;" onclick="knowlyApprove(\'' + pid + '\',\'approve\',this)">✓ Approve</button>'
                        + '<button class="button button-small" style="color:#dc2626;" onclick="knowlyApprove(\'' + pid + '\',\'reject\',this)">✗ Reject</button>'
                        + '</td></tr>';
                });
                html += '</tbody></table>';
                $('#knowly-review-results').html(html);
            }

            window.knowlyApprove = function(packageId, action, btn) {
                if (!confirm((action === 'approve' ? 'Approve' : 'Reject') + ' package ' + packageId + '?')) return;
                $(btn).prop('disabled', true);
                $.post(ajaxUrl, { action: 'knowly_pool_approve_package', nonce: nonce, package_id: packageId, approve_action: action }, function(res) {
                    if (res.success) {
                        var rowId = '#review-row-' + packageId.replace(/[^a-z0-9]/gi, '_');
                        $(rowId).html('<td colspan="7" style="color:' + (action === 'approve' ? '#16a34a' : '#dc2626') + ';padding:8px 12px;">'
                            + (action === 'approve' ? '✓ Approved' : '✗ Rejected') + ' — ' + packageId + '</td>');
                    } else {
                        alert('Error: ' + (res.data.message || 'Failed'));
                        $(btn).prop('disabled', false);
                    }
                });
            };

            // ── Modal ─────────────────────────────────────────────────────────
            function openModal(title, html) {
                $('#knowly-modal-title').text(title);
                $('#knowly-modal-body').html(html);
                $('#knowly-pkg-modal').show();
            }
            $('#knowly-modal-close').on('click', function() { $('#knowly-pkg-modal').hide(); });
            $('#knowly-pkg-modal').on('click', function(e) { if ($(e.target).is(this)) $(this).hide(); });

        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Trial Summary ───────────────────────────────────────────────────

    public static function ajax_trial_summary(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $data = self::railway_get( '/api/v1/pool/summary', [ 'status' => 'approved' ] );

        if ( is_wp_error( $data ) ) {
            Knowly_Debug::log( 'admin.pool', 'ajax_trial_summary failed', [ 'error' => $data->get_error_message() ], null, 'error' );
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.pool', 'Trial summary loaded', [ 'total' => $data['total_packages'] ?? 0 ], null, 'info' );
        wp_send_json_success( $data );
    }

    // ── AJAX: Trial Packages (detail view for a slot) ─────────────────────────

    public static function ajax_trial_packages(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level      = sanitize_text_field( $_POST['level']      ?? '' );
        $period     = sanitize_text_field( $_POST['period']     ?? '' );
        $subject    = sanitize_text_field( $_POST['subject']    ?? '' );
        $difficulty = sanitize_text_field( $_POST['difficulty'] ?? '' );

        $params = [ 'status' => 'approved', 'limit' => 20 ];
        if ( $level )      $params['level']      = $level;
        if ( $period )     $params['period']     = $period;
        if ( $subject )    $params['subject']    = $subject;
        if ( $difficulty ) $params['difficulty'] = $difficulty;

        // GET /api/v1/pool requires Bearer JWT (authenticateToken middleware)
        $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token     = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : '';

        if ( ! $token ) {
            wp_send_json_error( [ 'message' => 'Could not generate admin JWT.' ] );
        }

        $data = self::railway_get_token( '/api/v1/pool', $params, $token );

        if ( is_wp_error( $data ) ) {
            Knowly_Debug::log( 'admin.pool', 'ajax_trial_packages failed', [ 'params' => $params, 'error' => $data->get_error_message() ], null, 'error' );
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        $packages = $data['packages'] ?? [];

        ob_start();
        if ( empty( $packages ) ) {
            echo '<p style="color:#666;">No packages found for this slot.</p>';
        } else {
            foreach ( $packages as $pkg ) {
                $pid      = esc_html( $pkg['package_id'] ?? '—' );
                $meta     = $pkg['meta'] ?? [];
                $q_count  = count( $pkg['questions'] ?? [] );
                $has_ans  = ! empty( $pkg['answer_sheet'] );
                ?>
                <div style="border:1px solid #e5e7eb;border-radius:4px;padding:12px 16px;margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <code style="font-size:12px;"><?= $pid ?></code>
                        <div>
                            <span style="font-size:11px;background:<?= $has_ans ? '#dcfce7' : '#fef3c7' ?>;color:<?= $has_ans ? '#16a34a' : '#d97706' ?>;padding:2px 6px;border-radius:3px;margin-right:6px;">
                                <?= $has_ans ? '✓ Answer sheet' : '⚠ No answer sheet' ?>
                            </span>
                            <span style="font-size:11px;background:#f3f4f6;color:#374151;padding:2px 6px;border-radius:3px;">
                                <?= esc_html( $q_count ) ?> questions
                            </span>
                        </div>
                    </div>
                    <table style="width:100%;font-size:11px;border-collapse:collapse;">
                        <tr><td style="padding:2px 8px 2px 0;color:#888;">Subject</td><td style="font-weight:600;"><?= esc_html( $meta['subject'] ?? '—' ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Level</td><td><?= esc_html( $meta['level'] ?? '—' ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Period</td><td><?= esc_html( $meta['period'] ?? 'SEA' ) ?></td></tr>
                        <tr><td style="padding:2px 8px 2px 0;color:#888;">Difficulty</td><td><?= esc_html( $meta['difficulty'] ?? '—' ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Type</td><td><?= esc_html( $meta['trial_type'] ?? 'practice' ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Topic</td><td><?= esc_html( $meta['topic'] ?? '—' ) ?></td></tr>
                    </table>
                </div>
                <?php
            }
        }
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html, 'count' => count( $packages ), 'total' => $data['total'] ?? count( $packages ) ] );
    }

    // ── AJAX: Quest Catalogue ─────────────────────────────────────────────────

    public static function ajax_quest_catalogue(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level  = sanitize_key( $_POST['level']  ?? 'std_4' );
        $period = sanitize_key( $_POST['period'] ?? '' );

        if ( ! $level ) {
            wp_send_json_error( [ 'message' => 'level is required.' ] );
        }

        $admin_id = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token    = ! empty( $admin_id ) ? Knowly_JWT::encode( (int) $admin_id[0] ) : '';

        if ( ! $token ) {
            wp_send_json_error( [ 'message' => 'Could not generate admin token for Railway auth.' ] );
        }

        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        $params = [ 'level' => $level ];
        if ( $period ) $params['period'] = $period;

        // Quest catalogue uses Bearer JWT auth (not server key)
        $response = wp_remote_get( $endpoint . '/api/v1/quest/catalogue?' . http_build_query( $params ), [
            'timeout' => 15,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'admin.pool', 'ajax_quest_catalogue HTTP error', [ 'error' => $response->get_error_message(), 'params' => $params ], null, 'error' );
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            Knowly_Debug::log( 'admin.pool', 'ajax_quest_catalogue non-200', [ 'code' => $code, 'body' => $body, 'params' => $params ], null, 'warning' );
            wp_send_json_error( [ 'message' => "Railway returned HTTP {$code}: " . ( $body['error'] ?? '' ) ] );
        }

        Knowly_Debug::log( 'admin.pool', 'Quest catalogue loaded', [ 'level' => $level, 'period' => $period, 'count' => $body['count'] ?? 0 ], null, 'info' );
        wp_send_json_success( [
            'quests' => $body['quests'] ?? [],
            'count'  => $body['count']  ?? 0,
        ] );
    }

    // ── AJAX: Review Queue ────────────────────────────────────────────────────

    public static function ajax_review_queue(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        // GET /api/v1/pool uses authenticateToken (Bearer JWT), not server key
        $admin_id = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token    = ! empty( $admin_id ) ? Knowly_JWT::encode( (int) $admin_id[0] ) : '';

        if ( ! $token ) {
            wp_send_json_error( [ 'message' => 'Could not generate admin JWT for Railway auth.' ] );
        }

        $data = self::railway_get_token( '/api/v1/pool', [ 'status' => 'pending_review', 'limit' => 50 ], $token );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        wp_send_json_success( [
            'packages' => $data['packages'] ?? [],
            'total'    => $data['total']    ?? 0,
        ] );
    }

    // ── AJAX: Generate Trial ──────────────────────────────────────────────────

    public static function ajax_generate_trial(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level      = sanitize_text_field( $_POST['level']      ?? '' );
        $period     = sanitize_text_field( $_POST['period']     ?? '' );
        $subject    = sanitize_text_field( $_POST['subject']    ?? '' );
        $difficulty = sanitize_text_field( $_POST['difficulty'] ?? '' );

        if ( ! $level || ! $subject || ! $difficulty ) {
            wp_send_json_error( [ 'message' => 'level, subject, and difficulty are required.' ] );
        }

        $api_key  = get_option( 'knowly_railway_api_key', '' );
        $admin_id = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token    = ! empty( $admin_id ) ? Knowly_JWT::encode( (int) $admin_id[0] ) : '';

        $data = self::railway_post_token( '/api/v1/generate-exam', [
            'user_id'        => 'admin_pool_gen',
            'level'          => $level,
            'period'         => $period ?: null,
            'subject'        => $subject,
            'difficulty'     => $difficulty,
            'trial_type'     => 'practice',
            'force_generate' => true,
        ], $token );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        wp_send_json_success( [
            'package_id' => $data['package_id'] ?? $data['session_id'] ?? 'ok',
            'status'     => 'pending_review',
        ] );
    }

    // ── AJAX: Generate Quest ──────────────────────────────────────────────────

    public static function ajax_generate_quest(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level        = sanitize_text_field( $_POST['level']        ?? '' );
        $period       = sanitize_text_field( $_POST['period']       ?? '' );
        $subject      = sanitize_text_field( $_POST['subject']      ?? '' );
        $module_index = (int) ( $_POST['module_index'] ?? 0 );

        if ( ! $level || ! $subject ) {
            wp_send_json_error( [ 'message' => 'level and subject are required.' ] );
        }

        $data = self::railway_post( '/api/v1/quest/generate', [
            'curriculum'   => 'tt_primary',
            'level'        => $level,
            'period'       => $period ?: null,
            'subject'      => $subject,
            'module_index' => $module_index,
            'status'       => 'approved',
        ] );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        wp_send_json_success( [
            'quest_id' => $data['quest_id'] ?? '—',
            'status'   => $data['status']   ?? 'approved',
        ] );
    }

    // ── AJAX: Approve / Reject Package ────────────────────────────────────────

    public static function ajax_approve_package(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $package_id     = sanitize_text_field( $_POST['package_id']     ?? '' );
        $approve_action = sanitize_text_field( $_POST['approve_action'] ?? '' );

        if ( ! $package_id || ! in_array( $approve_action, [ 'approve', 'reject' ], true ) ) {
            wp_send_json_error( [ 'message' => 'package_id and approve_action (approve|reject) required.' ] );
        }

        $data = self::railway_patch( '/api/v1/pool/approve', [
            'package_id' => $package_id,
            'action'     => $approve_action,
        ] );

        if ( is_wp_error( $data ) ) {
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.pool', 'Package ' . $approve_action . 'd', [
            'package_id' => $package_id,
            'new_status' => $data['status'] ?? '—',
        ], null, 'info' );

        wp_send_json_success( [ 'package_id' => $package_id, 'status' => $data['status'] ?? $approve_action . 'd' ] );
    }

    // ── Railway HTTP Helpers ──────────────────────────────────────────────────

    private static function railway_get( string $path, array $params = [] ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_not_configured', 'Railway endpoint not configured.' );
        }

        $url = $endpoint . $path;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
        ] );

        return self::parse_response( $response );
    }

    private static function railway_get_token( string $path, array $params, string $token ): array|WP_Error {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_not_configured', 'Railway endpoint not configured.' );
        }

        $url = $endpoint . $path;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
        ] );

        return self::parse_response( $response );
    }

    private static function railway_post( string $path, array $body ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_not_configured', 'Railway endpoint not configured.' );
        }

        $response = wp_remote_post( $endpoint . $path, [
            'timeout' => 30,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ] );

        return self::parse_response( $response );
    }

    private static function railway_post_token( string $path, array $body, string $token ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_not_configured', 'Railway endpoint not configured.' );
        }

        $response = wp_remote_post( $endpoint . $path, [
            'timeout' => 30,
            'headers' => [
                'Authorization'    => "Bearer {$token}",
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ] );

        return self::parse_response( $response );
    }

    private static function railway_patch( string $path, array $body ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_not_configured', 'Railway endpoint not configured.' );
        }

        $response = wp_remote_request( $endpoint . $path, [
            'method'  => 'PATCH',
            'timeout' => 10,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ] );

        return self::parse_response( $response );
    }

    private static function parse_response( $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'knowly_railway_error', 'Railway connection failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'knowly_railway_error', $body['error'] ?? "Railway returned HTTP {$code}" );
        }

        return $body ?: [];
    }
}
