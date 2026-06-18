<?php
/**
 * Knowly_Admin_Pool — Pool Manager admin page.
 *
 * Trial data is sourced from Railway (Supabase).
 * Quest data is sourced from wp_knowly_quests (WP local store).
 *
 * Tabs:
 *   Trial Packages  — inventory per slot (level/period/subject/difficulty) from Railway
 *   Quest Catalogue — quests stored in wp_knowly_quests (all statuses)
 *   Review Queue    — pending_review trial packages awaiting admin approval
 *
 * Railway endpoints used (quests — generation only):
 *   POST /api/v1/quest/generate          (X-AEP-Server-Key) → WP stores both variants
 *
 * Railway endpoints used (trials):
 *   GET  /api/v1/pool/summary            (X-AEP-Server-Key)
 *   GET  /api/v1/pool                    (X-AEP-Server-Key, filtered)
 *   POST /api/v1/generate-exam           (X-AEP-Server-Key, force_generate:true)
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
        add_action( 'wp_ajax_knowly_pool_approve_quest',  [ __CLASS__, 'ajax_approve_quest' ] );
        add_action( 'wp_ajax_knowly_pool_sync_trials',    [ __CLASS__, 'ajax_sync_trials' ] );
        add_action( 'wp_ajax_knowly_pool_sync_quests',    [ __CLASS__, 'ajax_sync_quests' ] );
        add_action( 'wp_ajax_knowly_pool_quest_board',    [ __CLASS__, 'ajax_quest_board' ] );
        add_action( 'wp_ajax_knowly_quests_gen_questions', [ __CLASS__, 'ajax_gen_questions' ] );

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

            <!-- ── QUEST BOARD ────────────────────────────────────────────── -->
            <div id="knowly-tab-quests" class="knowly-pool-panel" style="display:none;border:1px solid #c3c4c7;border-top:none;padding:20px;background:#fff;">
                <p style="font-size:12px;color:#666;margin:0 0 12px;">
                    Slots are derived from your training data — one quest per module. Generation fires asynchronously.
                    Click <strong>↻ Re-check</strong> after ~2 minutes to pull results from Railway.
                </p>
                <div id="quest-board-generating-notice" style="display:none;padding:8px 12px;background:#fef3c7;border:1px solid #d97706;border-radius:4px;margin-bottom:12px;font-size:12px;color:#92400e;">
                    ⏳ Quest generation in progress. Click <strong>↻ Re-check</strong> to pull the result when ready.
                </div>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                    <select id="quest-board-level" style="height:30px;">
                        <option value="std_1">std_1</option>
                        <option value="std_2">std_2</option>
                        <option value="std_3">std_3</option>
                        <option value="std_4" selected>std_4</option>
                        <option value="std_5">std_5</option>
                    </select>
                    <select id="quest-board-period" style="height:30px;">
                        <option value="term_1" selected>term_1</option>
                        <option value="term_2">term_2</option>
                        <option value="term_3">term_3</option>
                        <option value="">capstone</option>
                    </select>
                    <button id="quest-board-load" class="button button-primary" <?= $server_key ? '' : 'disabled' ?>>
                        ↓ Load Quest Board
                    </button>
                    <button id="quest-board-recheck" class="button" <?= $server_key ? '' : 'disabled' ?>>
                        ↻ Re-check
                    </button>
                    <span id="quest-board-summary" style="color:#666;font-size:13px;"></span>
                </div>
                <div id="quest-board-sync-result" style="margin-bottom:10px;font-size:13px;"></div>
                <div id="quest-board-tabs" style="display:none;">
                    <div id="quest-subject-tabs" style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:0;"></div>
                    <div id="quest-board-table" style="border:1px solid #e5e7eb;border-top:none;padding:16px;background:#fafafa;min-height:80px;"></div>
                </div>
                <div id="quest-board-placeholder">
                    <p style="color:#888;">Select a level and period, then click "↓ Load Quest Board".</p>
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

            // ── Quest Board ───────────────────────────────────────────────────
            var questBoardData = null;
            var activeSubject  = null;
            var canGenerate    = <?= ( $railway_ok && $server_key ) ? 'true' : 'false' ?>;

            function slotBadge(status) {
                var cfg = {
                    fulfilled:      { bg:'#dcfce7', color:'#166534', label:'✅ Fulfilled' },
                    pending_review: { bg:'#fef3c7', color:'#92400e', label:'🔍 Review' },
                    rejected:       { bg:'#fee2e2', color:'#991b1b', label:'✗ Rejected' },
                    archived:       { bg:'#f3f4f6', color:'#6b7280', label:'Archived' },
                    generating:     { bg:'#e0f2fe', color:'#0369a1', label:'⏳ Generating' },
                    empty:          { bg:'#f9fafb', color:'#9ca3af', label:'— empty —' },
                };
                var c = cfg[status] || cfg.empty;
                return '<span style="font-size:11px;background:' + c.bg + ';color:' + c.color + ';padding:2px 8px;border-radius:3px;white-space:nowrap;">' + c.label + '</span>';
            }

            function renderSubjectTabs(subjects, active) {
                var html = '';
                subjects.forEach(function(s) {
                    var isActive = s === active;
                    var label = s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ');
                    html += '<button class="quest-subject-tab" data-subject="' + s + '" style="'
                        + 'padding:8px 20px;border:none;cursor:pointer;font-size:13px;background:transparent;'
                        + (isActive ? 'border-bottom:3px solid #2563eb;color:#2563eb;font-weight:600;margin-bottom:-2px;'
                                    : 'border-bottom:3px solid transparent;color:#6b7280;')
                        + '">' + label + '</button>';
                });
                $('#quest-subject-tabs').html(html);
            }

            function renderSlotTable(slots, level, period, subject) {
                if (!slots || !slots.length) {
                    $('#quest-board-table').html('<p style="color:#888;padding:8px 0;">No curriculum modules found for this subject. Import training data first (Training tab).</p>');
                    $('#quest-board-summary').text('');
                    return;
                }
                var fulfilled = slots.filter(function(s) { return s.status === 'fulfilled'; }).length;
                $('#quest-board-summary').text(fulfilled + ' / ' + slots.length + ' modules fulfilled · ' + subject);

                var html = '<table class="widefat" style="font-size:12px;border-collapse:collapse;">'
                    + '<thead><tr style="background:#f3f4f6;">'
                    + '<th style="padding:8px 10px;width:44px;">#</th>'
                    + '<th style="padding:8px 10px;">Module Title</th>'
                    + '<th style="padding:8px 10px;width:110px;">Training</th>'
                    + '<th style="padding:8px 10px;width:140px;">Status</th>'
                    + '<th style="padding:8px 10px;width:190px;">Actions</th>'
                    + '</tr></thead><tbody>';

                slots.forEach(function(slot, i) {
                    var bg = i % 2 === 0 ? '#fff' : '#f9fafb';
                    var actions = '';
                    var trainingBadge = slot.has_training
                        ? '<span class="knowly-badge ok" title="Training data found in WP — Pinecone vectors exist">✅ Ready</span>'
                        : '<span class="knowly-badge warn" title="No training data — quest will use AI general knowledge">⚠️ No data</span>';

                    if (slot.status === 'empty' || slot.status === 'rejected') {
                        if (canGenerate) {
                            actions = '<button class="button button-small button-primary quest-gen-btn"'
                                + ' data-level="' + level + '"'
                                + ' data-period="' + (period || '') + '"'
                                + ' data-subject="' + subject + '"'
                                + ' data-module-index="' + (slot.module_number - 1) + '"'
                                + ' data-module-num="' + slot.module_number + '">'
                                + 'Generate</button>';
                        }
                    } else if (slot.status === 'pending_review') {
                        actions = '<button class="button button-small" style="color:#16a34a;margin-right:4px;"'
                            + ' onclick="knowlyQuestBoardAction(\'' + slot.quest_id + '\',\'approve\',this)">✓ Approve</button>'
                            + '<button class="button button-small" style="color:#dc2626;"'
                            + ' onclick="knowlyQuestBoardAction(\'' + slot.quest_id + '\',\'reject\',this)">✗ Reject</button>';
                    } else if (slot.status === 'fulfilled') {
                        actions = '<span style="font-size:11px;color:#9ca3af;">Locked</span>';
                    } else if (slot.status === 'generating') {
                        actions = '<span style="font-size:11px;color:#0369a1;">Waiting for Railway…</span>';
                    }

                    html += '<tr id="qboard-row-' + slot.module_number + '" style="background:' + bg + ';border-bottom:1px solid #e5e7eb;">'
                        + '<td style="padding:8px 10px;color:#9ca3af;">' + slot.module_number + '</td>'
                        + '<td style="padding:8px 10px;' + (slot.status === 'fulfilled' ? 'font-weight:600;' : '') + '">' + slot.module_title + '</td>'
                        + '<td style="padding:8px 10px;">' + trainingBadge + '</td>'
                        + '<td style="padding:8px 10px;">' + slotBadge(slot.status) + '</td>'
                        + '<td style="padding:8px 10px;">' + actions + '</td>'
                        + '</tr>';
                });

                html += '</tbody></table>';
                $('#quest-board-table').html(html);
            }

            function loadQuestBoard() {
                var level  = $('#quest-board-level').val();
                var period = $('#quest-board-period').val();
                $('#quest-board-load').prop('disabled', true).text('Loading…');
                $('#quest-board-placeholder').hide();
                $('#quest-board-table').html('<p style="color:#888;padding:8px 0;">Loading…</p>');

                $.post(ajaxUrl, { action: 'knowly_pool_quest_board', nonce: nonce, level: level, period: period }, function(res) {
                    $('#quest-board-load').prop('disabled', false).text('↓ Load Quest Board');
                    if (!res.success) {
                        $('#quest-board-table').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown') + '</p>');
                        return;
                    }
                    questBoardData = res.data;
                    var subjects = res.data.subjects || [];
                    if (!activeSubject || subjects.indexOf(activeSubject) === -1) {
                        activeSubject = subjects[0] || null;
                    }
                    $('#quest-board-tabs').show();
                    renderSubjectTabs(subjects, activeSubject);
                    var slots = (res.data.slots_by_subject || {})[activeSubject] || [];
                    renderSlotTable(slots, res.data.level, res.data.period || '', activeSubject);
                });
            }

            $('#quest-board-load').on('click', loadQuestBoard);

            $(document).on('click', '.quest-subject-tab', function() {
                activeSubject = $(this).data('subject');
                if (!questBoardData) return;
                renderSubjectTabs(questBoardData.subjects || [], activeSubject);
                var slots = (questBoardData.slots_by_subject || {})[activeSubject] || [];
                renderSlotTable(slots, questBoardData.level, questBoardData.period || '', activeSubject);
            });

            $(document).on('click', '.quest-gen-btn', function() {
                var $btn    = $(this).prop('disabled', true).text('Starting…');
                var level   = $(this).data('level');
                var period  = $(this).data('period');
                var subject = $(this).data('subject');
                var mIndex  = $(this).data('module-index');
                var mNum    = $(this).data('module-num');

                $.post(ajaxUrl, {
                    action: 'knowly_pool_generate_quest', nonce: nonce,
                    level: level, period: period, subject: subject, module_index: mIndex
                }, function(res) {
                    if (res.success) {
                        var $row = $('#qboard-row-' + mNum);
                        $row.find('td:nth-child(4)').html(slotBadge('generating'));
                        $row.find('td:nth-child(5)').html('<span style="font-size:11px;color:#0369a1;">Waiting for Railway…</span>');
                        $('#quest-board-generating-notice').show();
                    } else {
                        $btn.prop('disabled', false).text('Generate');
                        alert('Error: ' + (res.data.message || 'Generation failed'));
                    }
                });
            });

            window.knowlyQuestBoardAction = function(questId, action, btn) {
                if (!confirm((action === 'approve' ? 'Approve' : 'Reject') + ' this quest?')) return;
                $(btn).prop('disabled', true);
                $.post(ajaxUrl, { action: 'knowly_pool_approve_quest', nonce: nonce, quest_id: questId, quest_action: action }, function(res) {
                    if (res.success) {
                        var newStatus = res.data.status === 'approved' ? 'fulfilled' : res.data.status;
                        var $row = $(btn).closest('tr');
                        $row.find('td:nth-child(4)').html(slotBadge(newStatus));
                        $row.find('td:nth-child(5)').html(
                            newStatus === 'fulfilled' ? '<span style="font-size:11px;color:#9ca3af;">Locked</span>' : ''
                        );
                    } else {
                        alert('Error: ' + (res.data.message || 'Failed'));
                        $(btn).prop('disabled', false);
                    }
                });
            };

            $('#quest-board-recheck').on('click', function() {
                var $btn    = $(this).prop('disabled', true).text('Checking…');
                var $result = $('#quest-board-sync-result');
                $result.html('<em>Pulling from Railway…</em>');
                $.post(ajaxUrl, { action: 'knowly_pool_sync_quests', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('↻ Re-check');
                    if (res.success) {
                        var d = res.data;
                        $result.html('<span style="color:#16a34a;">↻ ' + d.synced + ' new, ' + d.updated + ' updated.</span>');
                        if (d.synced > 0 || d.updated > 0) {
                            $('#quest-board-generating-notice').hide();
                            loadQuestBoard();
                        }
                    } else {
                        $result.html('<span style="color:#dc2626;">✗ ' + (res.data.message || 'Sync failed') + '</span>');
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

    // ── AJAX: Trial Summary — full catalog from curriculum config ─────────────

    public static function ajax_trial_summary(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $table      = $wpdb->prefix . 'knowly_trial_packages';
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $all_cfg    = get_option( 'knowly_curriculum_subjects', [] );
        $cfg        = $all_cfg[ $curriculum ] ?? null;

        if ( ! $cfg ) {
            wp_send_json_error( [ 'message' => "Curriculum config not found for '{$curriculum}'. Check Settings or run plugin re-activation." ] );
        }

        $levels    = array_column( $cfg['levels']                ?? [], 'value' ) ?: [ 'std_4', 'std_5' ];
        $periods   = array_column( $cfg['periods']               ?? [], 'value' ) ?: [ 'term_1', 'term_2', 'term_3' ];
        $std_diffs = array_column( $cfg['standard_difficulties'] ?? [], 'value' ) ?: [ 'easy', 'medium', 'hard' ];
        $sea_diffs = array_column( $cfg['capstone_difficulties'] ?? [], 'value' ) ?: [ 'sea_paper' ];
        $subjects  = array_column( $cfg['subjects']              ?? [], 'value' );

        // Build full expected matrix: level × (period + capstone) × subject × difficulty
        $expected = [];
        foreach ( $levels as $level ) {
            foreach ( array_merge( $periods, [ null ] ) as $period ) {
                $diffs = ( $period === null ) ? $sea_diffs : $std_diffs;
                foreach ( $subjects as $subject ) {
                    foreach ( $diffs as $diff ) {
                        $key             = $level . '|' . ( $period ?? '' ) . '|' . $subject . '|' . $diff;
                        $expected[ $key ] = [
                            'level'        => $level,
                            'period'       => $period,
                            'subject'      => $subject,
                            'difficulty'   => $diff,
                            'trial_type'   => ( $period === null ) ? 'sea_paper' : 'practice',
                            'count'        => 0,
                            'total_served' => 0,
                        ];
                    }
                }
            }
        }

        // Merge actual approved counts from WP pool
        $actual = $wpdb->get_results(
            "SELECT level, period, subject, difficulty, COUNT(*) AS cnt
             FROM {$table}
             WHERE status = 'approved'
             GROUP BY level, period, subject, difficulty",
            ARRAY_A
        );

        foreach ( $actual ?? [] as $row ) {
            $key = $row['level'] . '|' . ( $row['period'] ?? '' ) . '|' . $row['subject'] . '|' . ( $row['difficulty'] ?? '' );
            if ( isset( $expected[ $key ] ) ) {
                $expected[ $key ]['count'] = (int) $row['cnt'];
            } else {
                // Orphaned data not in curriculum config — include it anyway
                $expected[ $key ] = [
                    'level'        => $row['level'],
                    'period'       => $row['period'] ?: null,
                    'subject'      => $row['subject'],
                    'difficulty'   => $row['difficulty'] ?? '',
                    'trial_type'   => $row['period'] ? 'practice' : 'sea_paper',
                    'count'        => (int) $row['cnt'],
                    'total_served' => 0,
                ];
            }
        }

        $shaped = array_values( $expected );
        $total  = array_sum( array_column( $shaped, 'count' ) );
        $empty  = count( array_filter( $shaped, static fn( $s ) => $s['count'] === 0 ) );

        Knowly_Debug::log( 'admin.pool', 'Trial full catalog loaded', [
            'total'      => $total,
            'slot_count' => count( $shaped ),
            'empty'      => $empty,
        ], null, 'info' );

        wp_send_json_success( [
            'slots'          => $shaped,
            'slot_count'     => count( $shaped ),
            'total_packages' => (int) $total,
            'empty_slots'    => $empty,
        ] );
    }

    // ── AJAX: Trial Packages (detail view for a slot — reads WP DB) ───────────

    public static function ajax_trial_packages(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $table = $wpdb->prefix . 'knowly_trial_packages';

        $level      = sanitize_text_field( $_POST['level']      ?? '' );
        $period     = sanitize_text_field( $_POST['period']     ?? '' );
        $subject    = sanitize_text_field( $_POST['subject']    ?? '' );
        $difficulty = sanitize_text_field( $_POST['difficulty'] ?? '' );

        $sql  = "SELECT package_id, subject, level, period, difficulty, trial_type, topic, questions, answer_sheet, status, synced_at FROM {$table} WHERE status = 'approved'";
        $args = [];
        if ( $level )      { $sql .= ' AND level = %s';      $args[] = $level; }
        if ( $period )     { $sql .= ' AND period = %s';     $args[] = $period; }
        if ( $subject )    { $sql .= ' AND subject = %s';    $args[] = $subject; }
        if ( $difficulty ) { $sql .= ' AND difficulty = %s'; $args[] = $difficulty; }
        $sql .= ' ORDER BY synced_at DESC LIMIT 20';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = empty( $args )
            ? $wpdb->get_results( $sql, ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        ob_start();
        if ( empty( $rows ) ) {
            echo '<p style="color:#666;">No packages found for this slot. Use Sync Trials to pull from Railway.</p>';
        } else {
            foreach ( $rows as $row ) {
                $pid     = esc_html( $row['package_id'] );
                $q_count = count( json_decode( $row['questions'] ?? '[]', true ) );
                $has_ans = ! empty( $row['answer_sheet'] );
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
                        <tr><td style="padding:2px 8px 2px 0;color:#888;">Subject</td><td style="font-weight:600;"><?= esc_html( $row['subject'] ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Level</td><td><?= esc_html( $row['level'] ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Period</td><td><?= esc_html( $row['period'] ?: 'SEA' ) ?></td></tr>
                        <tr><td style="padding:2px 8px 2px 0;color:#888;">Difficulty</td><td><?= esc_html( $row['difficulty'] ?: '—' ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Type</td><td><?= esc_html( $row['trial_type'] ) ?></td>
                            <td style="padding:2px 8px;color:#888;">Synced</td><td><?= esc_html( $row['synced_at'] ) ?></td></tr>
                    </table>
                </div>
                <?php
            }
        }
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html, 'count' => count( $rows ?? [] ) ] );
    }

    // ── AJAX: Sync Trials from Railway ────────────────────────────────────────

    /**
     * Pull approved packages from Railway's pool into wp_knowly_trial_packages.
     *
     * Normal sync: uses ?exclude= to only fetch packages WP doesn't already have (incremental).
     * Force sync:  skips exclude, re-fetches ALL packages and replaces rows — use this to
     *              backfill missing answer_sheet data on existing packages.
     */
    public static function ajax_sync_trials(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        @set_time_limit( 300 );

        global $wpdb;
        $table      = $wpdb->prefix . 'knowly_trial_packages';
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $force      = ! empty( $_POST['force'] );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }
        if ( ! $server_key ) {
            wp_send_json_error( [ 'message' => 'Railway server key not configured. Set it in Knowly → Settings so answer sheets are included.' ] );
        }

        // Incremental sync: exclude packages already in WP.
        // Force sync: skip exclude so all packages are re-fetched (backfills answer_sheet).
        $existing_ids  = $wpdb->get_col( "SELECT package_id FROM {$table}" );
        $exclude_param = ( ! $force && ! empty( $existing_ids ) ) ? implode( ',', $existing_ids ) : '';

        $params = [ 'status' => 'approved', 'limit' => 200 ];
        if ( $exclude_param ) $params['exclude'] = $exclude_param;

        $url = $endpoint . '/api/v1/pool?' . http_build_query( $params );

        // GET /api/v1/pool requires Bearer JWT + server key for answer_sheet inclusion
        $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $jwt       = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : '';

        $response = wp_remote_get( $url, [
            'timeout' => 60,
            'headers' => [
                'Authorization'   => 'Bearer ' . $jwt,
                'X-AEP-Server-Key' => $server_key,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Railway connection failed: ' . $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['packages'] ) ) {
            if ( $code === 200 && isset( $body['packages'] ) && count( $body['packages'] ) === 0 ) {
                wp_send_json_success( [ 'synced' => 0, 'skipped' => count( $existing_ids ), 'message' => 'WP pool is already up to date.' ] );
            }
            wp_send_json_error( [ 'message' => 'Railway returned HTTP ' . $code . ': ' . ( $body['error'] ?? 'Unknown error' ) ] );
        }

        $synced = 0;
        $failed = 0;
        $now    = current_time( 'mysql', true );

        foreach ( $body['packages'] as $pkg ) {
            $package_id = $pkg['package_id'] ?? null;
            if ( ! $package_id ) { $failed++; continue; }

            $meta = $pkg['meta'] ?? [];

            $inserted = $wpdb->replace(
                $table,
                [
                    'package_id'   => $package_id,
                    'curriculum'   => $meta['curriculum'] ?? get_option( 'knowly_default_curriculum', 'tt_primary' ),
                    'level'        => $meta['level']       ?? '',
                    'period'       => $meta['period']      ?? null,
                    'subject'      => $meta['subject']     ?? '',
                    'difficulty'   => $meta['difficulty']  ?? null,
                    'trial_type'   => $meta['trial_type']  ?? 'practice',
                    'topic'        => $meta['topic']       ?? null,
                    'questions'    => wp_json_encode( $pkg['questions'] ?? [] ),
                    'answer_sheet' => isset( $pkg['answer_sheet'] ) ? wp_json_encode( $pkg['answer_sheet'] ) : null,
                    'meta'         => wp_json_encode( $meta ),
                    'status'       => 'approved',
                    'synced_at'    => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( $inserted === false ) { $failed++; } else { $synced++; }
        }

        Knowly_Debug::log( 'admin.pool', 'Trial sync complete', [
            'synced'  => $synced,
            'skipped' => count( $existing_ids ),
            'failed'  => $failed,
        ], null, 'info' );

        wp_send_json_success( [
            'synced'  => $synced,
            'skipped' => count( $existing_ids ),
            'failed'  => $failed,
            'message' => "{$synced} package(s) synced, " . count( $existing_ids ) . " already in WP pool.",
        ] );
    }

    // ── AJAX: Sync Quests from Railway/Supabase ───────────────────────────────
    // Pulls recent quests from Railway's /quest/list endpoint and upserts any
    // that are not yet in wp_knowly_quests. Designed to be called after a
    // non-blocking generate to pick up the result once Railway finishes.

    public static function ajax_sync_quests(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        @set_time_limit( 120 );

        global $wpdb;
        $table      = $wpdb->prefix . 'knowly_quests';
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }
        if ( ! $server_key ) {
            wp_send_json_error( [ 'message' => 'Railway server key not configured.' ] );
        }

        // Fetch list of all pending_review quests from Railway (most recent 50)
        $list_url = $endpoint . '/api/v1/quest/list?' . http_build_query( [
            'status'   => 'pending_review',
            'per_page' => 50,
        ] );

        $list_response = wp_remote_get( $list_url, [
            'timeout' => 30,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        if ( is_wp_error( $list_response ) ) {
            wp_send_json_error( [ 'message' => 'Railway connection failed: ' . $list_response->get_error_message() ] );
        }

        $list_code = wp_remote_retrieve_response_code( $list_response );
        $list_body = json_decode( wp_remote_retrieve_body( $list_response ), true );

        if ( $list_code !== 200 ) {
            wp_send_json_error( [ 'message' => 'Railway returned HTTP ' . $list_code ] );
        }

        $remote_quests = $list_body['quests'] ?? [];
        $synced  = 0;
        $updated = 0;
        $failed  = 0;
        $now     = current_time( 'mysql' );

        foreach ( $remote_quests as $rq ) {
            $quest_id = $rq['quest_id'] ?? null;
            if ( ! $quest_id ) { $failed++; continue; }

            // Check whether this quest already exists in WP
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE quest_id = %s AND variant = 'student'",
                $quest_id
            ) );

            // Fetch full content from Railway editor endpoint
            $detail_url = $endpoint . '/api/v1/quest/editor/' . rawurlencode( $quest_id );
            $detail_response = wp_remote_get( $detail_url, [
                'timeout' => 20,
                'headers' => [ 'X-AEP-Server-Key' => $server_key ],
            ] );

            if ( is_wp_error( $detail_response ) || wp_remote_retrieve_response_code( $detail_response ) !== 200 ) {
                $failed++;
                continue;
            }

            $detail = json_decode( wp_remote_retrieve_body( $detail_response ), true );
            if ( empty( $detail ) ) { $failed++; continue; }

            $row = [
                'quest_id'         => $quest_id,
                'curriculum'       => $detail['curriculum']    ?? 'tt_primary',
                'level'            => $detail['level']         ?? '',
                'period'           => $detail['period']        ?? null,
                'subject'          => $detail['subject']       ?? '',
                'topic'            => $detail['topic']         ?? null,
                'module_number'    => $detail['module_number'] ?? null,
                'module_title'     => $detail['module_title']  ?? null,
                'objectives'       => wp_json_encode( $detail['objectives'] ?? [] ),
                'variant'          => 'student',
                'content'          => wp_json_encode( $detail['content']  ?? [] ),
                'status'           => $detail['status']        ?? 'pending_review',
                'railway_quest_id' => $quest_id,
                'generated_at'     => $detail['generated_at'] ?? $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            $result = $wpdb->replace( $table, $row );
            if ( $result === false ) {
                $failed++;
            } elseif ( $existing > 0 ) {
                $updated++;
            } else {
                $synced++;
            }
        }

        Knowly_Debug::log( 'admin.pool', 'Quest sync complete', [
            'synced'  => $synced,
            'updated' => $updated,
            'failed'  => $failed,
        ], null, 'info' );

        wp_send_json_success( [
            'synced'  => $synced,
            'updated' => $updated,
            'pruned'  => 0,
            'failed'  => $failed,
            'message' => "{$synced} new, {$updated} updated.",
        ] );
    }

    // ── AJAX: Quest Board — curriculum-driven slot map ────────────────────────
    // Fetches all curriculum modules from Railway, merges with WP quest state,
    // returns a slot map grouped by subject.

    public static function ajax_quest_board(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $level      = sanitize_text_field( $_POST['level']  ?? 'std_4' );
        $period     = sanitize_text_field( $_POST['period'] ?? '' );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        // Fetch all active curriculum topics for this level/period (all subjects at once)
        $params = [
            'curriculum' => $curriculum,
            'level'      => $level,
            'per_page'   => 500,
            'status'     => 'active',
        ];
        // API uses 'null' string to mean IS NULL (capstone); empty period = capstone
        $params['period'] = ( $period !== '' ) ? $period : 'null';

        $url      = $endpoint . '/api/v1/curriculum-topics?' . http_build_query( $params );
        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Railway error: ' . $response->get_error_message() ] );
        }

        $code  = wp_remote_retrieve_response_code( $response );
        $body  = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            wp_send_json_error( [ 'message' => 'Railway returned HTTP ' . $code . ': ' . ( $body['error'] ?? 'Unknown' ) ] );
        }

        $topics = $body['items'] ?? [];

        // Build distinct subjects list and per-subject module maps
        $subjects_set    = [];
        $modules_by_subj = [];

        foreach ( $topics as $t ) {
            $subj = $t['subject'] ?? '';
            // Fall back to module_number 1 if null — groups all un-numbered topics under module 1
            $mn   = isset( $t['module_number'] ) && $t['module_number'] !== null
                ? (int) $t['module_number']
                : 1;
            if ( ! $subj ) continue;

            if ( ! in_array( $subj, $subjects_set, true ) ) {
                $subjects_set[]          = $subj;
                $modules_by_subj[ $subj ] = [];
            }
            if ( ! isset( $modules_by_subj[ $subj ][ $mn ] ) ) {
                $modules_by_subj[ $subj ][ $mn ] = [
                    'module_number' => $mn,
                    'module_title'  => $t['module_title'] ?? 'Module ' . $mn,
                    'sort_order'    => (int) ( $t['sort_order'] ?? ( $mn * 100 ) ),
                ];
            }
        }

        sort( $subjects_set );

        // Fetch all matching quests from WP local store for this level/period
        global $wpdb;
        $table         = $wpdb->prefix . 'knowly_quests';
        $period_clause = ( $period !== '' )
            ? $wpdb->prepare( 'AND period = %s', $period )
            : 'AND period IS NULL';

        $wp_quests = $wpdb->get_results( $wpdb->prepare(
            "SELECT quest_id, subject, module_number, status, module_title, generated_at, approved_at, audio_url, audio_generated_at, content
             FROM {$table}
             WHERE curriculum = %s AND level = %s {$period_clause} AND variant = 'student'
             ORDER BY subject ASC, module_number ASC",
            $curriculum, $level
        ), ARRAY_A );

        // Fetch quest question counts from Railway for each approved quest
        $quest_q_counts = [];
        if ( ! empty( $wp_quests ) && $endpoint && $server_key ) {
            foreach ( $wp_quests as $q ) {
                if ( $q['status'] !== 'approved' ) continue;
                $qid  = $q['quest_id'];
                $resp = wp_remote_get( $endpoint . '/api/v1/quest/' . rawurlencode( $qid ) . '/questions', [
                    'timeout' => 5,
                    'headers' => [ 'X-AEP-Server-Key' => $server_key ],
                ] );
                if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                    $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );
                    $quest_q_counts[ $qid ] = (int) ( $parsed['count'] ?? 0 );
                }
            }
        }

        // Index by subject → module_number for O(1) lookup
        $quest_map = [];
        foreach ( $wp_quests as $q ) {
            $quest_map[ $q['subject'] ][ (int) $q['module_number'] ] = $q;
        }

        // Fetch training coverage: which module_titles have WP training rows for this level/period
        // Training subtopic column stores the module_title from the CSV import
        $tm_table  = $wpdb->prefix . 'knowly_training_material';
        $tm_rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT subject, subtopic
             FROM {$tm_table}
             WHERE curriculum = %s AND level = %s {$period_clause} AND status = 'active' AND subtopic IS NOT NULL",
            $curriculum, $level
        ), ARRAY_A );

        // Build coverage map: subject → normalised module_title → true
        $training_map = [];
        foreach ( $tm_rows as $tm ) {
            $training_map[ $tm['subject'] ][ strtolower( trim( $tm['subtopic'] ) ) ] = true;
        }

        // Build slots_by_subject merging curriculum modules with WP quest state
        $slots_by_subject = [];
        foreach ( $subjects_set as $subj ) {
            $modules = array_values( $modules_by_subj[ $subj ] ?? [] );
            usort( $modules, fn( $a, $b ) => $a['sort_order'] <=> $b['sort_order'] );

            $slots = [];
            foreach ( $modules as $m ) {
                $quest = $quest_map[ $subj ][ $m['module_number'] ] ?? null;

                if ( ! $quest ) {
                    $display_status = 'empty';
                } elseif ( $quest['status'] === 'approved' ) {
                    $display_status = 'fulfilled';
                } else {
                    $display_status = $quest['status']; // pending_review | rejected | archived
                }

                $title_key    = strtolower( trim( $m['module_title'] ) );
                $has_training = isset( $training_map[ $subj ][ $title_key ] );

                $qid = $quest['quest_id'] ?? null;

                $char_count = 0;
                if ( $quest && $display_status === 'fulfilled' && ! empty( $quest['content'] ) ) {
                    $decoded = json_decode( $quest['content'], true );
                    if ( $decoded ) {
                        $char_count = Knowly_Polly_Service::training_char_count( $decoded );
                    }
                }

                $slots[] = [
                    'module_number'      => $m['module_number'],
                    'module_title'       => $m['module_title'],
                    'sort_order'         => $m['sort_order'],
                    'quest_id'           => $qid,
                    'status'             => $display_status,
                    'generated_at'       => $quest['generated_at']       ?? null,
                    'approved_at'        => $quest['approved_at']         ?? null,
                    'has_training'       => $has_training,
                    'q_count'            => $qid ? ( $quest_q_counts[ $qid ] ?? 0 ) : 0,
                    'char_count'         => $char_count,
                    'has_audio'          => ! empty( $quest['audio_url'] ),
                    'audio_url'          => $quest['audio_url']          ?? null,
                    'audio_generated_at' => $quest['audio_generated_at'] ?? null,
                ];
            }
            $slots_by_subject[ $subj ] = $slots;
        }

        wp_send_json_success( [
            'subjects'         => $subjects_set,
            'slots_by_subject' => $slots_by_subject,
            'level'            => $level,
            'period'           => $period ?: null,
        ] );
    }

    // ── AJAX: Quest Catalogue — full catalog from curriculum config ───────────

    public static function ajax_quest_catalogue(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $table      = $wpdb->prefix . 'knowly_quests';
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $all_cfg    = get_option( 'knowly_curriculum_subjects', [] );
        $cfg        = $all_cfg[ $curriculum ] ?? null;

        $levels   = array_column( $cfg['levels']  ?? [], 'value' ) ?: [ 'std_4', 'std_5' ];
        $periods  = array_column( $cfg['periods'] ?? [], 'value' ) ?: [ 'term_1', 'term_2', 'term_3' ];
        $subjects = array_column( $cfg['subjects'] ?? [], 'value' );

        // Build expected level × (period + capstone) × subject slots
        $expected_map = [];
        foreach ( $levels as $level ) {
            foreach ( array_merge( $periods, [ null ] ) as $period ) {
                foreach ( $subjects as $subject ) {
                    $key                  = $level . '|' . ( $period ?? '' ) . '|' . $subject;
                    $expected_map[ $key ] = [
                        'level'      => $level,
                        'period'     => $period,
                        'subject'    => $subject,
                        'quests'     => [],
                        'max_module' => -1,
                    ];
                }
            }
        }

        // Fetch all student quests (no server-side filter — client filters client-side)
        $rows = $wpdb->get_results(
            "SELECT quest_id, level, period, subject, module_number, module_title, topic, status, generated_at, approved_at
             FROM {$table}
             WHERE variant = 'student'
             ORDER BY level ASC, period ASC, subject ASC, module_number ASC",
            ARRAY_A
        );

        if ( $rows === null ) {
            Knowly_Debug::log( 'admin.pool', 'ajax_quest_catalogue DB error', [ 'error' => $wpdb->last_error ], null, 'error' );
            wp_send_json_error( [ 'message' => 'Database error: ' . $wpdb->last_error ] );
        }

        // Group quests into their slots
        foreach ( $rows as $row ) {
            $key = $row['level'] . '|' . ( $row['period'] ?? '' ) . '|' . $row['subject'];
            if ( ! isset( $expected_map[ $key ] ) ) {
                // Quest exists but slot not in curriculum config — add it
                $expected_map[ $key ] = [
                    'level'      => $row['level'],
                    'period'     => $row['period'] ?: null,
                    'subject'    => $row['subject'],
                    'quests'     => [],
                    'max_module' => -1,
                ];
            }
            $expected_map[ $key ]['quests'][] = $row;
            $mn = (int) ( $row['module_number'] ?? -1 );
            if ( $mn > $expected_map[ $key ]['max_module'] ) {
                $expected_map[ $key ]['max_module'] = $mn;
            }
        }

        // Flatten: empty slot → one placeholder row; filled slot → individual quest rows
        $output = [];
        foreach ( $expected_map as $slot ) {
            if ( empty( $slot['quests'] ) ) {
                $output[] = [
                    'quest_id'     => null,
                    'level'        => $slot['level'],
                    'period'       => $slot['period'],
                    'subject'      => $slot['subject'],
                    'module_number'=> null,
                    'module_title' => null,
                    'topic'        => null,
                    'status'       => 'empty',
                    'next_module'  => 0,
                    'generated_at' => null,
                    'approved_at'  => null,
                ];
            } else {
                $next = $slot['max_module'] + 1;
                foreach ( $slot['quests'] as $q ) {
                    $q['next_module'] = $next;
                    $output[]         = $q;
                }
            }
        }

        $empty_count = count( array_filter( $output, static fn( $r ) => $r['status'] === 'empty' ) );
        Knowly_Debug::log( 'admin.pool', 'Quest full catalog loaded', [
            'total' => count( $output ),
            'empty' => $empty_count,
        ], null, 'info' );

        wp_send_json_success( [
            'quests' => $output,
            'count'  => count( $output ),
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
        set_time_limit( 180 );

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

    // ── AJAX: Generate Quest (fire-and-forget) ───────────────────────────────
    // Fires the Railway generation request non-blocking so WP returns immediately.
    // Railway stores the quest in Supabase; use ajax_sync_quests() to pull it into WP.

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

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        // Fire non-blocking — WP does not wait for the response.
        // Railway generates the quest and stores it in Supabase directly.
        wp_remote_post( $endpoint . '/api/v1/quest/generate', [
            'timeout'  => 1,
            'blocking' => false,
            'headers'  => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( [
                'curriculum'   => 'tt_primary',
                'level'        => $level,
                'period'       => $period ?: null,
                'subject'      => $subject,
                'module_index' => $module_index,
                'status'       => 'pending_review',
            ] ),
        ] );

        Knowly_Debug::log( 'admin.pool', 'Quest generation fired (non-blocking)', [
            'level'   => $level,
            'period'  => $period ?: null,
            'subject' => $subject,
        ], null, 'info' );

        wp_send_json_success( [
            'status'  => 'generating',
            'message' => 'Quest generation started. Click ↻ Re-check Quests in ~2 minutes.',
        ] );
    }

    // ── AJAX: Generate Quest Questions ────────────────────────────────────────

    public static function ajax_gen_questions(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id    = sanitize_text_field( $_POST['quest_id']     ?? '' );
        $level       = sanitize_text_field( $_POST['level']        ?? '' );
        $period      = sanitize_text_field( $_POST['period']       ?? '' );
        $subject     = sanitize_text_field( $_POST['subject']      ?? '' );
        $module_title = sanitize_text_field( $_POST['module_title'] ?? '' );

        if ( ! $quest_id || ! $level || ! $subject || ! $module_title ) {
            wp_send_json_error( [ 'message' => 'quest_id, level, subject, and module_title are required.' ] );
            return;
        }

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) { wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] ); return; }

        $body = [
            'curriculum'   => 'tt_primary',
            'level'        => $level,
            'period'       => $period ?: null,
            'subject'      => $subject,
            'quest_id'     => $quest_id,
            'module_title' => $module_title,
            'topics'       => [],
        ];

        $resp = wp_remote_post( $endpoint . '/api/v1/quest/generate-questions', [
            'timeout' => 60,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( [ 'message' => $resp->get_error_message() ] ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) {
            wp_send_json_error( [ 'message' => $parsed['error'] ?? "HTTP {$code}" ] );
            return;
        }

        wp_send_json_success( [
            'quest_id'       => $quest_id,
            'question_count' => $parsed['question_count'] ?? 3,
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

    // ── AJAX: Approve / Reject / Archive Quest ────────────────────────────────

    public static function ajax_approve_quest(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $table = $wpdb->prefix . 'knowly_quests';

        $quest_id    = sanitize_text_field( $_POST['quest_id']    ?? '' );
        $quest_action = sanitize_key( $_POST['quest_action'] ?? '' );

        if ( ! $quest_id || ! in_array( $quest_action, [ 'approve', 'reject', 'archive' ], true ) ) {
            wp_send_json_error( [ 'message' => 'quest_id and quest_action (approve|reject|archive) are required.' ] );
        }

        $status_map = [
            'approve' => 'approved',
            'reject'  => 'rejected',
            'archive' => 'archived',
        ];
        $new_status = $status_map[ $quest_action ];

        $update = [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ];

        if ( $new_status === 'approved' ) {
            $update['approved_at'] = current_time( 'mysql' );
            $update['approved_by'] = get_current_user_id();
        }

        // Update both student and teacher variants atomically
        $rows_updated = $wpdb->update(
            $table,
            $update,
            [ 'quest_id' => $quest_id ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        if ( $rows_updated === false ) {
            wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error ] );
        }

        // Sync status to Supabase via Railway (approved and rejected only — no 'archived' on Railway).
        if ( in_array( $new_status, [ 'approved', 'rejected' ], true ) ) {
            $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
            $server_key = get_option( 'knowly_railway_server_key', '' );

            if ( $endpoint ) {
                $railway_response = wp_remote_request( $endpoint . '/api/v1/quest/status', [
                    'method'  => 'PATCH',
                    'timeout' => 15,
                    'headers' => [
                        'X-AEP-Server-Key' => $server_key,
                        'Content-Type'     => 'application/json',
                    ],
                    'body' => wp_json_encode( [ 'quest_id' => $quest_id, 'status' => $new_status ] ),
                ] );

                if ( is_wp_error( $railway_response ) ) {
                    Knowly_Debug::log( 'admin.pool', 'Railway status sync failed', [
                        'quest_id' => $quest_id,
                        'error'    => $railway_response->get_error_message(),
                    ], null, 'error' );
                }
            }
        }

        Knowly_Debug::log( 'admin.pool', 'Quest status updated', [
            'quest_id'   => $quest_id,
            'new_status' => $new_status,
            'rows'       => $rows_updated,
        ], null, 'info' );

        wp_send_json_success( [ 'quest_id' => $quest_id, 'status' => $new_status ] );
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
            'timeout' => 120,
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
            'timeout' => 120,
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
