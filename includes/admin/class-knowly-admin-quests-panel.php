<?php
/**
 * Knowly_Admin_Quests_Panel — Quest module admin page.
 *
 * Tabs: Catalogue | Health Checks | Unit Tests | Simulations
 *
 * Quest catalogue is served from wp_knowly_quests (WP local store).
 * Railway is called only for:
 *   - Sync: pulls approved quests from Railway into wp_knowly_quests
 *   - Generate: creates new quest in Railway, stores both variants in WP
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Quests_Panel {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_quests_panel_health', [ __CLASS__, 'ajax_health' ] );
        add_action( 'wp_ajax_knowly_quests_sync',         [ __CLASS__, 'ajax_sync' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab        = sanitize_key( $_GET['tab'] ?? 'catalogue' );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $nonce      = wp_create_nonce( 'knowly_admin_nonce' );

        $tabs = [
            'catalogue' => 'Catalogue',
            'health'    => 'Health Checks',
            'tests'     => 'Unit Tests',
            'sims'      => 'Simulations',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Quests</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-quests-panel&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( ! $railway_ok ) : ?>
            <div class="notice notice-warning inline" style="margin:8px 0 0;"><p>Railway endpoint not configured. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php
            match ( $tab ) {
                'catalogue' => self::render_catalogue( $railway_ok, $server_key, $nonce ),
                'health'    => self::render_health( $nonce ),
                'tests'     => self::render_tests(),
                'sims'      => self::render_simulations(),
                default     => self::render_catalogue( $railway_ok, $server_key, $nonce ),
            };
            ?>
            </div>
        </div>
        <?php
    }

    // ── Catalogue Tab ─────────────────────────────────────────────────────────

    private static function render_catalogue( bool $railway_ok, string $server_key, string $nonce ): void {
        global $wpdb;
        $local_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quests WHERE variant='student'" );
        $approved    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quests WHERE variant='student' AND status='approved'" );
        $pending     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quests WHERE variant='student' AND status='pending_review'" );
        ?>

        <!-- ── WP Store Stats ──────────────────────────────────────────────── -->
        <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 18px;text-align:center;">
                <div style="font-size:22px;font-weight:700;color:#16a34a;"><?= $approved ?></div>
                <div style="font-size:11px;color:#15803d;">Approved</div>
            </div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 18px;text-align:center;">
                <div style="font-size:22px;font-weight:700;color:#d97706;"><?= $pending ?></div>
                <div style="font-size:11px;color:#b45309;">Pending Review</div>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 18px;text-align:center;">
                <div style="font-size:22px;font-weight:700;color:#475569;"><?= $local_count ?></div>
                <div style="font-size:11px;color:#64748b;">Total in WP Store</div>
            </div>
        </div>

        <!-- ── Sync from Railway ───────────────────────────────────────────── -->
        <div style="padding:14px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <strong style="font-size:13px;">Sync Quests from Railway</strong>
                    <p style="margin:2px 0 0;font-size:12px;color:#3730a3;">
                        Pulls all approved quests from Railway and stores them in the WP local store.
                        Existing records are updated; new ones are inserted as <code>approved</code>.
                        Run this after generating new quests or to restore the local store.
                    </p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                    <select id="qp-sync-level" style="height:30px;">
                        <option value="">All levels</option>
                        <option value="std_1">std_1</option>
                        <option value="std_2">std_2</option>
                        <option value="std_3">std_3</option>
                        <option value="std_4">std_4</option>
                        <option value="std_5">std_5</option>
                    </select>
                    <button id="qp-sync" class="button button-primary" <?= $railway_ok ? '' : 'disabled' ?>>
                        ↻ Sync Quests
                    </button>
                </div>
            </div>
            <div id="qp-sync-result" style="margin-top:8px;font-size:12px;"></div>
        </div>

        <!-- ── View Local Store ────────────────────────────────────────────── -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
            <button id="qp-load" class="button button-secondary">↺ Refresh</button>
            <select id="qp-level" style="height:30px;">
                <option value="">All Levels</option>
                <option value="std_4">std_4</option>
                <option value="std_5">std_5</option>
            </select>
            <select id="qp-period" style="height:30px;">
                <option value="">All Periods</option>
                <option value="term_1">Term 1</option>
                <option value="term_2">Term 2</option>
                <option value="term_3">Term 3</option>
                <option value="capstone">Capstone (SEA)</option>
            </select>
            <select id="qp-status-filter" style="height:30px;">
                <option value="">All statuses</option>
                <option value="empty">Empty (no quests)</option>
                <option value="approved">approved</option>
                <option value="pending_review">pending_review</option>
                <option value="archived">archived</option>
                <option value="rejected">rejected</option>
            </select>
            <span id="qp-summary" style="color:#666;font-size:13px;"></span>
        </div>
        <div id="qp-results">
            <p style="color:#888;">Loading quest catalogue…</p>
        </div>

        <!-- ── Generate Quest ─────────────────────────────────────────────── -->
        <div style="margin-top:24px;padding:16px;background:#f6f7f7;border-radius:4px;border:1px solid #e5e7eb;">
            <h3 style="margin:0 0 4px;">Generate Quest</h3>
            <p style="font-size:12px;color:#666;margin:0 0 10px;">
                Calls Railway to generate a new quest. Both student and teacher variants are stored in WP as <strong>pending_review</strong>.
                <em>Generation takes 1–3 minutes</em> — use Sync Quests after generation if the quest does not appear immediately.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                <label style="font-size:12px;display:flex;flex-direction:column;gap:4px;min-width:100px;">Level
                    <select id="qp-gen-level" style="height:30px;">
                        <option value="std_1">std_1</option>
                        <option value="std_2">std_2</option>
                        <option value="std_3">std_3</option>
                        <option value="std_4" selected>std_4</option>
                        <option value="std_5">std_5</option>
                    </select>
                </label>
                <label style="font-size:12px;display:flex;flex-direction:column;gap:4px;min-width:110px;">Period
                    <select id="qp-gen-period" style="height:30px;">
                        <option value="">(none / capstone)</option>
                        <option value="term_1" selected>term_1</option>
                        <option value="term_2">term_2</option>
                        <option value="term_3">term_3</option>
                    </select>
                </label>
                <label style="font-size:12px;display:flex;flex-direction:column;gap:4px;min-width:120px;">Subject
                    <select id="qp-gen-subject" style="height:30px;">
                        <option value="math" selected>math</option>
                        <option value="english">english</option>
                        <option value="science">science</option>
                        <option value="social_studies">social_studies</option>
                    </select>
                </label>
                <label style="font-size:12px;display:flex;flex-direction:column;gap:4px;min-width:90px;">Module Index
                    <input type="number" id="qp-gen-module" style="height:30px;width:80px;" value="0" min="0">
                </label>
                <button id="qp-generate" class="button button-primary" style="height:30px;" <?= ( $railway_ok && $server_key ) ? '' : 'disabled' ?>>Generate</button>
            </div>
            <div id="qp-gen-result" style="margin-top:10px;font-size:13px;"></div>
        </div>

        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';

            // ── Status badge helper ───────────────────────────────────────────
            function statusBadge(s) {
                var cfg = {
                    approved:       { bg:'#dcfce7', color:'#16a34a' },
                    pending_review: { bg:'#fef3c7', color:'#d97706' },
                    rejected:       { bg:'#fee2e2', color:'#dc2626' },
                    archived:       { bg:'#f3f4f6', color:'#6b7280' },
                    empty:          { bg:'#f3f4f6', color:'#9ca3af' },
                };
                var c = cfg[s] || { bg:'#f3f4f6', color:'#374151' };
                return '<span style="font-size:11px;background:' + c.bg + ';color:' + c.color + ';padding:2px 6px;border-radius:3px;">' + s + '</span>';
            }

            // ── Sync quests from Railway ──────────────────────────────────────
            $('#qp-sync').on('click', function() {
                var $btn   = $(this).prop('disabled', true).text('Syncing…');
                var $res   = $('#qp-sync-result');
                var level  = $('#qp-sync-level').val();
                $res.html('<em>Fetching quest catalogue from Railway… this may take 30–60 seconds for a full sync.</em>');
                $.ajax({
                    url:     ajaxurl,
                    type:    'POST',
                    timeout: 120000,
                    data:    { action: 'knowly_quests_sync', nonce: nonce, level: level },
                    success: function(res) {
                        $btn.prop('disabled', false).text('↻ Sync Quests');
                        if (!res.success) {
                            $res.html('<span style="color:#dc2626;">✗ Sync failed: ' + (res.data.message || 'Unknown error') + '</span>');
                            return;
                        }
                        var d = res.data;
                        $res.html(
                            '<span style="color:#16a34a;">✓ Sync complete — '
                            + '<strong>' + d.synced + '</strong> synced, '
                            + '<strong>' + d.skipped + '</strong> skipped (already up to date), '
                            + '<strong>' + d.failed + '</strong> failed.'
                            + (d.no_content > 0 ? ' <em>' + d.no_content + ' had no content and were stored as metadata-only.</em>' : '')
                            + '</span>'
                        );
                        loadQuestCatalogue();
                    },
                    error: function(xhr, status) {
                        $btn.prop('disabled', false).text('↻ Sync Quests');
                        $res.html('<span style="color:#dc2626;">✗ Request timed out or failed (' + status + '). Try syncing a single level at a time.</span>');
                    }
                });
            });

            // ── Load & render quest catalogue (always loads all — client filters) ─
            function loadQuestCatalogue() {
                var $btn = $('#qp-load').prop('disabled', true).text('Loading…');
                $('#qp-results').html('<p style="color:#888;">Loading quest catalogue…</p>');
                $.post(ajaxurl, { action: 'knowly_pool_quest_catalogue', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('↺ Refresh');
                    if (!res.success) {
                        $('#qp-results').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown error') + '</p>');
                        return;
                    }
                    renderQuestTable(res.data.quests || []);
                });
            }

            function renderQuestTable(quests) {
                window._lastQuestData = quests;
                var total  = quests.length;
                var empty  = quests.filter(function(q) { return q.status === 'empty'; }).length;
                var filled = total - empty;
                $('#qp-summary').text(filled + ' quest(s) · ' + empty + ' empty slots');

                if (!total) {
                    $('#qp-results').html('<p style="color:#666;">No quest slots found — check curriculum config in Settings.</p>');
                    return;
                }

                var html = '<table class="knowly-table widefat" style="font-size:12px;">'
                    + '<thead><tr><th>Quest ID</th><th>Level</th><th>Period</th><th>Subject</th><th>Module</th><th>Title / Topic</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

                $.each(quests, function(i, q) {
                    var period    = q.period || '';
                    var periodLbl = q.period ? q.period : '<em style="color:#6b7280;">Capstone (SEA)</em>';
                    var rowBg     = q.status === 'empty' ? ' style="background:#fafafa;"' : '';

                    if (q.status === 'empty') {
                        // Empty slot — Generate only, pre-fills form below
                        html += '<tr data-level="' + q.level + '" data-period="' + period + '" data-status="empty"' + rowBg + '>'
                            + '<td style="font-family:monospace;font-size:11px;color:#9ca3af;">—</td>'
                            + '<td>' + q.level + '</td>'
                            + '<td>' + periodLbl + '</td>'
                            + '<td><strong>' + q.subject + '</strong></td>'
                            + '<td style="text-align:center;color:#9ca3af;">—</td>'
                            + '<td style="color:#9ca3af;font-style:italic;">No quests yet</td>'
                            + '<td>' + statusBadge('empty') + '</td>'
                            + '<td style="white-space:nowrap;">'
                            + '<button class="button button-small qp-prefill-gen" data-level="' + q.level + '" data-period="' + period + '" data-subject="' + q.subject + '" data-module="0">Generate</button>'
                            + '</td></tr>';
                    } else {
                        var rowId  = 'qp-row-' + q.quest_id.replace(/[^a-z0-9]/gi, '_');
                        var actions = '';
                        if (q.status === 'pending_review') {
                            actions = '<button class="button button-small" style="color:#16a34a;margin-right:4px;" onclick="qpQuestAction(\'' + q.quest_id + '\',\'approve\',this)">✓ Approve</button>'
                                    + '<button class="button button-small" style="color:#dc2626;" onclick="qpQuestAction(\'' + q.quest_id + '\',\'reject\',this)">✗ Reject</button>';
                        } else if (q.status === 'approved') {
                            actions = '<button class="button button-small" style="color:#6b7280;margin-right:4px;" onclick="qpQuestAction(\'' + q.quest_id + '\',\'archive\',this)">Archive</button>'
                                    + '<button class="button button-small qp-prefill-gen" data-level="' + q.level + '" data-period="' + period + '" data-subject="' + q.subject + '" data-module="' + (q.next_module||0) + '">+ Next</button>';
                        }
                        html += '<tr id="' + rowId + '" data-level="' + q.level + '" data-period="' + period + '" data-status="' + q.status + '">'
                            + '<td style="font-family:monospace;font-size:11px;">' + q.quest_id + '</td>'
                            + '<td>' + (q.level||'') + '</td>'
                            + '<td>' + periodLbl + '</td>'
                            + '<td><strong>' + (q.subject||'') + '</strong></td>'
                            + '<td style="text-align:center;">' + (q.module_number != null ? q.module_number : '—') + '</td>'
                            + '<td>' + (q.module_title||q.topic||'—') + '</td>'
                            + '<td>' + statusBadge(q.status) + '</td>'
                            + '<td style="white-space:nowrap;">' + actions + '</td>'
                            + '</tr>';
                    }
                });
                html += '</tbody></table>';
                $('#qp-results').html(html);
                applyQuestFilters();
            }

            // ── Client-side filters ───────────────────────────────────────────
            function applyQuestFilters() {
                var level  = $('#qp-level').val();
                var period = $('#qp-period').val();
                var status = $('#qp-status-filter').val();
                $('#qp-results tbody tr').each(function() {
                    var $tr      = $(this);
                    var trLevel  = $tr.data('level');
                    var trPeriod = String($tr.data('period') || '');
                    var trStatus = $tr.data('status');
                    var levelOk  = !level || trLevel === level;
                    var statusOk = !status || trStatus === status;
                    var periodOk;
                    if (!period)                   { periodOk = true; }
                    else if (period === 'capstone') { periodOk = (trPeriod === ''); }
                    else                           { periodOk = (trPeriod === period); }
                    $tr.toggle(levelOk && periodOk && statusOk);
                });
            }
            $('#qp-level, #qp-period, #qp-status-filter').on('change', applyQuestFilters);

            // ── Pre-fill generate form from table row ─────────────────────────
            $(document).on('click', '.qp-prefill-gen', function() {
                var $btn = $(this);
                $('#qp-gen-level').val($btn.data('level'));
                $('#qp-gen-period').val($btn.data('period'));
                $('#qp-gen-subject').val($btn.data('subject'));
                $('#qp-gen-module').val($btn.data('module') || 0);
                $('html, body').animate({ scrollTop: $('#qp-generate').offset().top - 40 }, 300);
                $('#qp-generate').focus();
            });

            $('#qp-load').on('click', loadQuestCatalogue);
            loadQuestCatalogue(); // auto-load on page open

            window.qpQuestAction = function(questId, action, btn) {
                var labels = { approve: 'Approve', reject: 'Reject', archive: 'Archive' };
                if (!confirm(labels[action] + ' quest ' + questId + '?')) return;
                $(btn).prop('disabled', true);
                $.post(ajaxurl, { action: 'knowly_pool_approve_quest', nonce: nonce, quest_id: questId, quest_action: action }, function(res) {
                    if (res.success) {
                        var rowId = '#qp-row-' + questId.replace(/[^a-z0-9]/gi, '_');
                        $(rowId).find('td:nth-last-child(2)').html(statusBadge(res.data.status));
                        $(rowId).find('td:last-child').html(
                            res.data.status === 'approved'
                                ? '<button class="button button-small" style="color:#6b7280;" onclick="qpQuestAction(\'' + questId + '\',\'archive\',this)">Archive</button>'
                                : ''
                        );
                    } else {
                        alert('Error: ' + (res.data.message || 'Failed'));
                        $(btn).prop('disabled', false);
                    }
                });
            };

            // ── Generate Quest ────────────────────────────────────────────────
            $('#qp-generate').on('click', function() {
                var level   = $('#qp-gen-level').val(),
                    period  = $('#qp-gen-period').val(),
                    subject = $('#qp-gen-subject').val(),
                    mi      = parseInt($('#qp-gen-module').val(), 10) || 0;
                if (!level || !subject) { alert('Level and Subject are required.'); return; }
                var $btn = $(this).prop('disabled', true).text('Generating…');
                var $res = $('#qp-gen-result').html('<em>Calling Railway… generation takes 1–3 minutes. Please wait.</em>');
                $.ajax({
                    url:     ajaxurl,
                    type:    'POST',
                    timeout: 240000,
                    data:    { action: 'knowly_pool_generate_quest', nonce: nonce, level: level, period: period, subject: subject, module_index: mi },
                    success: function(res) {
                        $btn.prop('disabled', false).text('Generate');
                        if (res.success) {
                            $res.html('<span style="color:#16a34a;">✓ Stored in WP: <strong>' + res.data.quest_id + '</strong> — both variants saved as <strong>pending_review</strong>.'
                                + (res.data.has_teacher ? '' : ' <em>(No teacher variant returned — student only.)</em>')
                                + '</span>');
                            loadQuestCatalogue();
                        } else {
                            $res.html('<span style="color:#dc2626;">✗ ' + (res.data.message || 'Generation failed') + '</span>');
                        }
                    },
                    error: function(xhr, status) {
                        $btn.prop('disabled', false).text('Generate');
                        $res.html('<span style="color:#d97706;">⚠ Request timed out (' + status + '). Railway may still be generating — use <strong>Sync Quests</strong> in a few minutes to import it.</span>');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── Health Checks Tab ─────────────────────────────────────────────────────

    private static function render_health( string $nonce ): void {
        ?>
        <button id="qp-run-health" class="button button-primary" style="margin-bottom:16px;">Run Health Checks</button>
        <div id="qp-health-results"><p style="color:#888;">Click to run health checks.</p></div>
        <script>
        jQuery('#qp-run-health').on('click', function() {
            var $btn = jQuery(this).prop('disabled', true).text('Running…');
            jQuery.post(ajaxurl, { action: 'knowly_quests_panel_health', nonce: '<?= esc_js( $nonce ) ?>' }, function(res) {
                $btn.prop('disabled', false).text('Run Health Checks');
                if (!res.success) { jQuery('#qp-health-results').html('<p style="color:#dc2626;">' + (res.data?.message || 'Error') + '</p>'); return; }
                var html = '<table class="knowly-table" style="max-width:700px;"><tbody>';
                (res.data.checks || []).forEach(function(c) {
                    var col = c.status === 'pass' ? '#16a34a' : (c.status === 'warn' ? '#d97706' : '#dc2626');
                    var ico = c.status === 'pass' ? '✓' : (c.status === 'warn' ? '⚠' : '✗');
                    html += '<tr><td style="color:' + col + ';font-weight:600;width:40px;">' + ico + '</td><td><strong>' + c.label + '</strong></td><td style="color:#666;">' + (c.detail || '') + '</td></tr>';
                });
                html += '</tbody></table>';
                jQuery('#qp-health-results').html(html);
            });
        });
        </script>
        <?php
    }

    // ── Unit Tests Tab ────────────────────────────────────────────────────────

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test quest catalogue, start (first/retake/assigned), badge award and idempotency.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'block6_quests', 'block6_badges' ] );
    }

    // ── Simulations Tab ───────────────────────────────────────────────────────

    private static function render_simulations(): void {
        ?>
        <p style="color:#666;">Full Quest journeys (browse → start → complete → badge).</p>
        <div class="knowly-notice info" style="margin-top:12px;">Simulations coming soon. Run unit tests to validate individual endpoints.</div>
        <?php
    }

    // ── AJAX: Sync Quests from Railway ────────────────────────────────────────

    public static function ajax_sync(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $table = $wpdb->prefix . 'knowly_quests';

        $sync_level = sanitize_key( $_POST['level'] ?? '' );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        // Generate a short-lived JWT for Railway auth
        $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        if ( empty( $admin_ids ) ) {
            wp_send_json_error( [ 'message' => 'No admin user found to generate Railway token.' ] );
        }
        $token = Knowly_JWT::encode( (int) $admin_ids[0] );

        // ── Step 1: Fetch catalogue from Railway ─────────────────────────────
        // Iterate over levels we care about
        $levels  = $sync_level ? [ $sync_level ] : [ 'std_1', 'std_2', 'std_3', 'std_4', 'std_5' ];
        $periods = [ 'term_1', 'term_2', 'term_3', '' ]; // '' = capstone

        $all_quests = [];

        foreach ( $levels as $level ) {
            foreach ( $periods as $period ) {
                $params = [ 'level' => $level, 'curriculum' => 'tt_primary' ];
                if ( $period ) $params['period'] = $period;

                $url  = $endpoint . '/api/v1/quest/catalogue?' . http_build_query( $params );
                $resp = wp_remote_get( $url, [
                    'timeout' => 20,
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Content-Type'  => 'application/json',
                    ],
                ] );

                if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) continue;

                $body   = json_decode( wp_remote_retrieve_body( $resp ), true );
                $quests = $body['quests'] ?? [];
                foreach ( $quests as $q ) {
                    $all_quests[] = $q;
                }
            }
        }

        if ( empty( $all_quests ) ) {
            wp_send_json_success( [
                'synced'     => 0,
                'skipped'    => 0,
                'failed'     => 0,
                'no_content' => 0,
                'message'    => 'No approved quests found in Railway for the selected level(s).',
            ] );
        }

        // ── Step 2: For each quest, fetch full content and upsert ─────────────
        $synced     = 0;
        $skipped    = 0;
        $failed     = 0;
        $no_content = 0;
        $now        = current_time( 'mysql' );

        foreach ( $all_quests as $meta ) {
            $quest_id = $meta['quest_id'] ?? null;
            if ( ! $quest_id ) { $failed++; continue; }

            // Fetch full content from Railway
            $content_url  = $endpoint . '/api/v1/quest/' . rawurlencode( $quest_id );
            $content_resp = wp_remote_get( $content_url, [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                ],
            ] );

            $content = null;
            if ( ! is_wp_error( $content_resp ) && wp_remote_retrieve_response_code( $content_resp ) === 200 ) {
                $content_body = json_decode( wp_remote_retrieve_body( $content_resp ), true );
                // Railway may return content nested under 'content' or 'student_content'
                $content = $content_body['student_content'] ?? $content_body['content'] ?? $content_body;
                if ( empty( $content ) ) $no_content++;
            } else {
                $no_content++;
            }

            $row = [
                'quest_id'         => $quest_id,
                'variant'          => 'student',
                'curriculum'       => $meta['curriculum']    ?? 'tt_primary',
                'level'            => $meta['level']         ?? '',
                'period'           => $meta['period']        ?? null,
                'subject'          => $meta['subject']       ?? '',
                'topic'            => $meta['topic']         ?? null,
                'module_number'    => $meta['module_number'] ?? null,
                'module_title'     => $meta['module_title']  ?? null,
                'objectives'       => wp_json_encode( $meta['objectives'] ?? [] ),
                'content'          => $content ? wp_json_encode( $content ) : null,
                'status'           => 'approved',
                'railway_quest_id' => $quest_id,
                'generated_at'     => $meta['generated_at'] ?? $now,
                'approved_at'      => $now,
                'approved_by'      => get_current_user_id(),
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            // Use REPLACE INTO — upserts on (quest_id, variant) unique key
            $result = $wpdb->replace( $table, $row );

            if ( $result === false ) {
                $failed++;
                Knowly_Debug::log( 'admin.sync', 'Failed to upsert quest', [ 'quest_id' => $quest_id, 'error' => $wpdb->last_error ], null, 'error' );
            } else {
                $synced++;
            }
        }

        Knowly_Debug::log( 'admin.sync', 'Quest sync complete', [
            'synced'     => $synced,
            'skipped'    => $skipped,
            'failed'     => $failed,
            'no_content' => $no_content,
        ], null, 'info' );

        wp_send_json_success( compact( 'synced', 'skipped', 'failed', 'no_content' ) );
    }

    // ── AJAX: Health ──────────────────────────────────────────────────────────

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $checks     = [];
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        // 1. Railway reachable
        if ( $endpoint ) {
            $resp = wp_remote_get( $endpoint . '/api/v1/health', [ 'timeout' => 8 ] );
            $ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
            $checks[] = [ 'label' => 'Railway reachable', 'status' => $ok ? 'pass' : 'fail', 'detail' => $ok ? 'Online.' : 'Unreachable.' ];
        } else {
            $checks[] = [ 'label' => 'Railway endpoint', 'status' => 'fail', 'detail' => 'Not configured.' ];
        }

        // 2. WP quest store populated
        $local_approved = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quests WHERE variant='student' AND status='approved'"
        );
        $checks[] = [
            'label'  => 'WP quest store',
            'status' => $local_approved > 0 ? 'pass' : 'warn',
            'detail' => $local_approved > 0
                ? "{$local_approved} approved quest(s) in wp_knowly_quests."
                : "No approved quests in WP store. Run Sync Quests to import from Railway.",
        ];

        // 3. Railway quest catalogue reachable (spot check std_4)
        if ( $endpoint ) {
            $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
            $token     = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : '';
            if ( $token ) {
                $resp = wp_remote_get( $endpoint . '/api/v1/quest/catalogue?level=std_4', [
                    'timeout' => 10,
                    'headers' => [ 'Authorization' => "Bearer {$token}" ],
                ] );
                if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                    $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
                    $count = $body['count'] ?? count( $body['quests'] ?? [] );
                    $checks[] = [ 'label' => 'Railway quest catalogue (std_4)', 'status' => $count > 0 ? 'pass' : 'warn', 'detail' => "{$count} approved quest(s) available on Railway for std_4." ];
                } else {
                    $checks[] = [ 'label' => 'Railway quest catalogue (std_4)', 'status' => 'fail', 'detail' => 'Could not reach Railway quest catalogue.' ];
                }
            }
        }

        // 4. Badge CPT registered
        $badge_cpt = post_type_exists( 'knowly_badge' );
        $checks[] = [ 'label' => 'Badge CPT registered', 'status' => $badge_cpt ? 'pass' : 'fail', 'detail' => $badge_cpt ? 'knowly_badge post type exists.' : 'CPT not registered.' ];

        // 5. Earned badges user meta writable
        $test_child = get_user_by( 'login', 'test.child' );
        if ( $test_child ) {
            update_user_meta( $test_child->ID, '_knowly_badges_health_check', 'ok' );
            $after = get_user_meta( $test_child->ID, '_knowly_badges_health_check', true );
            delete_user_meta( $test_child->ID, '_knowly_badges_health_check' );
            $checks[] = [ 'label' => 'earned_badges meta writable', 'status' => $after === 'ok' ? 'pass' : 'fail', 'detail' => $after === 'ok' ? 'User meta read/write OK.' : 'User meta write failed.' ];
        } else {
            $checks[] = [ 'label' => 'earned_badges meta writable', 'status' => 'warn', 'detail' => 'No test.child account — run provisioning.' ];
        }

        wp_send_json_success( [ 'checks' => $checks ] );
    }
}
