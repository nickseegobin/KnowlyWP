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
        add_action( 'wp_ajax_knowly_quests_panel_health', [ __CLASS__, 'ajax_health'        ] );
        add_action( 'wp_ajax_knowly_quests_sync',         [ __CLASS__, 'ajax_sync'          ] );
        add_action( 'wp_ajax_knowly_quests_gen_questions',[ __CLASS__, 'ajax_gen_questions'  ] );
        add_action( 'wp_ajax_knowly_quests_gen_audio',      [ __CLASS__, 'ajax_gen_audio'       ] ); // legacy
        add_action( 'wp_ajax_knowly_quests_delete_audio',  [ __CLASS__, 'ajax_delete_audio'    ] ); // legacy
        add_action( 'wp_ajax_knowly_quests_audio_overview',[ __CLASS__, 'ajax_audio_overview'  ] );
        add_action( 'wp_ajax_knowly_quests_gen_para_audio',[ __CLASS__, 'ajax_gen_para_audio'  ] );
        add_action( 'wp_ajax_knowly_quests_del_para_audio',[ __CLASS__, 'ajax_del_para_audio'  ] );
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
        $can_gen     = ( $railway_ok && $server_key ) ? 'true' : 'false';
        ?>

        <!-- ── Stats ──────────────────────────────────────────────────────── -->
        <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 18px;text-align:center;">
                <div style="font-size:22px;font-weight:700;color:#16a34a;"><?= $approved ?></div>
                <div style="font-size:11px;color:#15803d;">Fulfilled</div>
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

        <!-- ── Quest Board ────────────────────────────────────────────────── -->
        <p style="font-size:12px;color:#666;margin:0 0 12px;">
            Slots are derived from your training data (curriculum_topics) — one quest per module.
            Generation fires asynchronously; click <strong>↻ Re-check</strong> after ~2 minutes to pull results.
        </p>

        <div id="qp-generating-notice" style="display:none;padding:8px 12px;background:#fef3c7;border:1px solid #d97706;border-radius:4px;margin-bottom:12px;font-size:12px;color:#92400e;">
            ⏳ Quest generation in progress. Click <strong>↻ Re-check</strong> to pull the result when ready.
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <select id="qp-level" style="height:30px;">
                <option value="">Loading…</option>
            </select>
            <select id="qp-period" style="height:30px;">
                <option value="">Loading…</option>
            </select>
            <button id="qp-load" class="button button-primary" <?= $server_key ? '' : 'disabled' ?>>
                ↓ Load Quest Board
            </button>
            <button id="qp-recheck" class="button" <?= $server_key ? '' : 'disabled' ?>>
                ↻ Re-check
            </button>
            <span id="qp-summary" style="color:#666;font-size:13px;"></span>
        </div>
        <div id="qp-sync-result" style="margin-bottom:10px;font-size:13px;"></div>

        <!-- Subject tabs + slot table (populated by JS) -->
        <div id="qp-board-tabs" style="display:none;">
            <div id="qp-subject-tabs" style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:0;"></div>
            <div id="qp-board-table" style="border:1px solid #e5e7eb;border-top:none;padding:16px;background:#fafafa;min-height:80px;"></div>
        </div>
        <div id="qp-placeholder"><p style="color:#888;">Select a level and period, then click "↓ Load Quest Board".</p></div>

        <script>
        (function($) {
            var nonce      = '<?= esc_js( $nonce ) ?>';
            var canGen     = <?= $can_gen ?>;
            var boardData  = null;
            var activeSubj = null;
            var levelsData = {};

            function buildQuestPeriodOpts(lv) {
                var perOpts  = '';
                var termKeys = Object.keys(lv.period_map || {}).sort();
                termKeys.forEach(function(p) {
                    var sel = p === 'term_1' ? ' selected' : '';
                    perOpts += '<option value="' + p + '"' + sel + '>' + p.replace(/term_(\d+)/, 'Term $1') + '</option>';
                });
                if (lv.capstone) {
                    var sel = !termKeys.length ? ' selected' : '';
                    perOpts += '<option value=""' + sel + '>Capstone</option>';
                }
                $('#qp-period').html(perOpts || '<option value="" selected>Capstone</option>');
            }

            function esc(str) {
                return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

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

            function renderTabs(subjects, active) {
                var html = '';
                subjects.forEach(function(s) {
                    var isActive = s === active;
                    var label = s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g,' ');
                    html += '<button class="qp-subj-tab" data-subject="' + s + '" style="'
                        + 'padding:8px 20px;border:none;cursor:pointer;font-size:13px;background:transparent;'
                        + (isActive
                            ? 'border-bottom:3px solid #2563eb;color:#2563eb;font-weight:600;margin-bottom:-2px;'
                            : 'border-bottom:3px solid transparent;color:#6b7280;')
                        + '">' + label + '</button>';
                });
                $('#qp-subject-tabs').html(html);
            }

            function renderSlots(slots, level, period, subject) {
                if (!slots || !slots.length) {
                    $('#qp-board-table').html('<p style="color:#888;padding:8px 0;">No curriculum modules found. Import training data first (Training tab).</p>');
                    $('#qp-summary').text('');
                    return;
                }
                var fulfilled = slots.filter(function(s) { return s.status === 'fulfilled'; }).length;
                $('#qp-summary').text(fulfilled + ' / ' + slots.length + ' fulfilled · ' + subject);

                var html = '<table class="widefat" style="font-size:12px;border-collapse:collapse;">'
                    + '<thead><tr style="background:#f3f4f6;">'
                    + '<th style="padding:8px 10px;width:44px;">#</th>'
                    + '<th style="padding:8px 10px;">Module Title</th>'
                    + '<th style="padding:8px 10px;width:110px;">Training</th>'
                    + '<th style="padding:8px 10px;width:140px;">Status</th>'
                    + '<th style="padding:8px 10px;width:260px;">Actions</th>'
                    + '</tr></thead><tbody>';

                slots.forEach(function(slot, i) {
                    var bg = i % 2 === 0 ? '#fff' : '#f9fafb';
                    var actions = '';
                    var trainingBadge = slot.has_training
                        ? '<span class="knowly-badge ok" title="Training data found in WP — Pinecone vectors exist">✅ Ready</span>'
                        : '<span class="knowly-badge warn" title="No training data — quest will use AI general knowledge">⚠️ No data</span>';

                    if (slot.status === 'empty' || slot.status === 'rejected') {
                        if (canGen) {
                            actions = '<button class="button button-small button-primary qp-gen-btn"'
                                + ' data-level="' + level + '"'
                                + ' data-period="' + (period || '') + '"'
                                + ' data-subject="' + subject + '"'
                                + ' data-module-index="' + (slot.module_number - 1) + '"'
                                + ' data-module-num="' + slot.module_number + '">'
                                + 'Generate</button>';
                        }
                    } else if (slot.status === 'pending_review') {
                        actions = '<button class="button button-small" style="color:#16a34a;margin-right:4px;"'
                            + ' onclick="qpBoardAction(\'' + slot.quest_id + '\',\'approve\',this)">✓ Approve</button>'
                            + '<button class="button button-small" style="color:#dc2626;"'
                            + ' onclick="qpBoardAction(\'' + slot.quest_id + '\',\'reject\',this)">✗ Reject</button>';
                    } else if (slot.status === 'fulfilled') {
                        var qCount    = slot.q_count   || 0;
                        var charCount = slot.char_count || 0;

                        // ── Row 1: Content / Questions ──────────────────────────
                        var qBadge = qCount >= 3
                            ? '<span style="font-size:11px;background:#dcfce7;color:#166534;padding:1px 6px;border-radius:3px;">3 Qs ✓</span>'
                            : '<span style="font-size:11px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;">' + qCount + '/3 Qs</span>';
                        var genQBtn = '<button class="button button-small qp-gen-q-btn"'
                            + ' data-quest-id="' + (slot.quest_id||'') + '"'
                            + ' data-level="' + level + '"'
                            + ' data-period="' + (period||'') + '"'
                            + ' data-subject="' + subject + '"'
                            + ' data-module-title="' + slot.module_title + '">'
                            + (qCount >= 3 ? 'Regenerate Qs' : 'Generate Questions') + '</button>';
                        var row1 = '<div style="display:flex;align-items:center;gap:6px;">'
                            + qBadge + genQBtn
                            + '</div>';

                        // ── Row 2: Audio Manager ────────────────────────────────
                        var row2 = '<div class="qp-audio-row" style="margin-top:6px;padding-top:6px;border-top:1px solid #e5e7eb;">'
                            + '<div style="display:flex;align-items:center;gap:8px;">'
                            + '<button class="button button-small qp-am-toggle" data-quest-id="' + (slot.quest_id||'') + '" data-loaded="0">'
                            + '🎙 Audio Manager</button>'
                            + '<span class="qp-am-badge" style="font-size:11px;color:#6b7280;"></span>'
                            + '</div>'
                            + '<div class="qp-am-panel" style="display:none;margin-top:10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:14px;">'
                            + '<p style="color:#94a3b8;font-size:12px;margin:0;">Loading…</p>'
                            + '</div>'
                            + '</div>';
                        // ── Row 3: Badge indicator ──────────────────────────────
                        var curriculum = (boardData && boardData.curriculum) ? boardData.curriculum : 'tt_primary';
                        var tKey = curriculum + ':' + level + ':' + (period || 'capstone') + ':' + subject + ':' + slot.module_number;
                        var badgeDef = _questBadgeDefs[tKey];
                        var badgesUrl = '<?= esc_js( admin_url( 'admin.php?page=knowly-badges' ) ) ?>';
                        var row3;
                        if (badgeDef) {
                            row3 = '<div class="qp-badge-row" style="margin-top:6px;padding-top:6px;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:6px;">'
                                + '<span style="font-size:11px;">🏅</span>'
                                + '<span style="font-size:11px;color:#166534;font-weight:500;">' + esc(badgeDef.name) + '</span>'
                                + '<a href="' + badgesUrl + '&edit_def=' + badgeDef.id + '" class="button button-small" style="font-size:10px;padding:1px 6px;">Edit badge</a>'
                                + '</div>';
                        } else {
                            var createUrl = badgesUrl
                                + '&auto=quest'
                                + '&curriculum=' + encodeURIComponent(curriculum)
                                + '&level='      + encodeURIComponent(level)
                                + '&period='     + encodeURIComponent(period || '')
                                + '&subject='    + encodeURIComponent(subject)
                                + '&module='     + slot.module_number;
                            row3 = '<div class="qp-badge-row" style="margin-top:6px;padding-top:6px;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:6px;">'
                                + '<span style="font-size:11px;">🏅</span>'
                                + '<span style="font-size:11px;color:#9ca3af;">No badge</span>'
                                + '<a href="' + createUrl + '" class="button button-small" style="font-size:10px;padding:1px 6px;">+ Create badge</a>'
                                + '</div>';
                        }
                        actions = row1 + row2 + row3;
                    } else if (slot.status === 'generating') {
                        actions = '<span style="font-size:11px;color:#0369a1;">Waiting for Railway…</span>';
                    }

                    html += '<tr id="qp-row-' + slot.module_number + '" style="background:' + bg + ';border-bottom:1px solid #e5e7eb;">'
                        + '<td style="padding:8px 10px;color:#9ca3af;">' + slot.module_number + '</td>'
                        + '<td style="padding:8px 10px;' + (slot.status === 'fulfilled' ? 'font-weight:600;' : '') + '">' + slot.module_title + '</td>'
                        + '<td style="padding:8px 10px;">' + trainingBadge + '</td>'
                        + '<td style="padding:8px 10px;">' + slotBadge(slot.status) + '</td>'
                        + '<td style="padding:8px 10px;">' + actions + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                $('#qp-board-table').html(html);
            }

            var _questBadgeDefs = {}; // keyed by trigger_key → { id, name }

            function loadQuestBadgeDefs(callback) {
                $.post(ajaxurl, { action: 'knowly_badges_for_quests', nonce: nonce }, function(res) {
                    if (res.success) _questBadgeDefs = res.data || {};
                    if (callback) callback();
                }).fail(function() { if (callback) callback(); });
            }

            function loadBoard() {
                var level  = $('#qp-level').val();
                var period = $('#qp-period').val();
                $('#qp-load').prop('disabled', true).text('Loading…');
                $('#qp-placeholder').hide();
                $('#qp-board-table').html('<p style="color:#888;padding:8px 0;">Loading…</p>');

                $.post(ajaxurl, { action: 'knowly_pool_quest_board', nonce: nonce, level: level, period: period }, function(res) {
                    $('#qp-load').prop('disabled', false).text('↓ Load Quest Board');
                    if (!res.success) {
                        $('#qp-board-table').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown') + '</p>');
                        return;
                    }
                    boardData = res.data;
                    var subjects = res.data.subjects || [];
                    if (!activeSubj || subjects.indexOf(activeSubj) === -1) activeSubj = subjects[0] || null;
                    // Load badge defs first so Row3 renders correctly
                    loadQuestBadgeDefs(function() {
                        $('#qp-board-tabs').show();
                        renderTabs(subjects, activeSubj);
                        var slots = (res.data.slots_by_subject || {})[activeSubj] || [];
                        renderSlots(slots, res.data.level, res.data.period || '', activeSubj);
                    });
                });
            }

            $('#qp-level').on('change', function() {
                var lv = levelsData[$(this).val()];
                if (lv) buildQuestPeriodOpts(lv);
            });

            $('#qp-load').on('click', loadBoard);

            // Populate level + period dropdowns from curriculum (same source as Curriculum page)
            $.post(ajaxurl, { action: 'knowly_curriculum_overview', nonce: nonce }, function(res) {
                if (!res.success) { loadBoard(); return; }
                var levels = res.data.levels || [];

                if (levels.length) {
                    levelsData = {};
                    var lvlOpts    = '';
                    var initialLv  = null;
                    levels.forEach(function(l) {
                        levelsData[l.slug] = l;
                        var sel = l.slug === 'std_4' ? ' selected' : '';
                        if (l.slug === 'std_4' || (!initialLv && !sel)) initialLv = initialLv || (l.slug === 'std_4' ? l : null);
                        lvlOpts += '<option value="' + l.slug + '"' + sel + '>' + l.label + ' (' + l.slug + ')</option>';
                    });
                    $('#qp-level').html(lvlOpts);
                    // Build period options for the initially selected level
                    var selSlug = $('#qp-level').val();
                    if (levelsData[selSlug]) buildQuestPeriodOpts(levelsData[selSlug]);
                } else {
                    $('#qp-period').html(
                        '<option value="term_1" selected>Term 1</option>' +
                        '<option value="term_2">Term 2</option>' +
                        '<option value="term_3">Term 3</option>' +
                        '<option value="">Capstone</option>'
                    );
                }

                loadBoard(); // auto-load after dropdowns are ready
            }).fail(function() {
                // Fallback if curriculum overview fails
                $('#qp-level').html('<option value="std_4" selected>std_4</option>');
                $('#qp-period').html('<option value="term_1" selected>term_1</option><option value="term_2">term_2</option><option value="term_3">term_3</option><option value="">capstone</option>');
                loadBoard();
            });

            $(document).on('click', '.qp-subj-tab', function() {
                activeSubj = $(this).data('subject');
                if (!boardData) return;
                renderTabs(boardData.subjects || [], activeSubj);
                var slots = (boardData.slots_by_subject || {})[activeSubj] || [];
                renderSlots(slots, boardData.level, boardData.period || '', activeSubj);
            });

            $(document).on('click', '.qp-gen-btn', function() {
                var $btn   = $(this).prop('disabled', true).text('Starting…');
                var level  = $(this).data('level');
                var period = $(this).data('period');
                var subj   = $(this).data('subject');
                var mIdx   = $(this).data('module-index');
                var mNum   = $(this).data('module-num');
                $.post(ajaxurl, {
                    action: 'knowly_pool_generate_quest', nonce: nonce,
                    level: level, period: period, subject: subj, module_index: mIdx
                }, function(res) {
                    if (res.success) {
                        var $row = $('#qp-row-' + mNum);
                        $row.find('td:nth-child(4)').html(slotBadge('generating'));
                        $row.find('td:nth-child(5)').html('<span style="font-size:11px;color:#0369a1;">Waiting for Railway…</span>');
                        $('#qp-generating-notice').show();
                    } else {
                        $btn.prop('disabled', false).text('Generate');
                        alert('Error: ' + (res.data.message || 'Failed'));
                    }
                });
            });

            $(document).on('click', '.qp-gen-q-btn', function() {
                var $btn      = $(this).prop('disabled', true).text('Generating…');
                var questId   = $(this).data('quest-id');
                var level     = $(this).data('level');
                var period    = $(this).data('period');
                var subject   = $(this).data('subject');
                var modTitle  = $(this).data('module-title');
                $.post(ajaxurl, {
                    action:       'knowly_quests_gen_questions',
                    nonce:        nonce,
                    quest_id:     questId,
                    level:        level,
                    period:       period,
                    subject:      subject,
                    module_title: modTitle,
                }, function(res) {
                    if (res.success) {
                        var count = res.data.question_count || 3;
                        $btn.closest('td').find('span').first()
                            .css({ background:'#dcfce7', color:'#166534' })
                            .text(count + ' Qs ✓');
                        $btn.prop('disabled', false).text('Regenerate Qs');
                    } else {
                        alert('Error: ' + (res.data.message || 'Generation failed.'));
                        $btn.prop('disabled', false).text('Generate Questions');
                    }
                }).fail(function() {
                    alert('Request failed — check Railway connectivity.');
                    $btn.prop('disabled', false).text('Generate Questions');
                });
            });

            // ── Audio Manager ──────────────────────────────────────────────────

            // Render the audio controls cell for one paragraph row
            function amAudioCell(questId, sectionIdx, paraIdx, audioUrl) {
                if (audioUrl) {
                    return '<audio controls preload="none" src="' + audioUrl + '"'
                        + ' style="height:26px;max-width:130px;vertical-align:middle;"></audio>'
                        + ' <button class="button button-small qp-am-del"'
                        + ' data-quest-id="' + questId + '"'
                        + ' data-section-idx="' + sectionIdx + '"'
                        + ' data-para-idx="' + paraIdx + '"'
                        + ' style="color:#dc2626;padding:0 5px;" title="Delete clip">🗑</button>';
                }
                return '<button class="button button-small qp-am-gen"'
                    + ' data-quest-id="' + questId + '"'
                    + ' data-section-idx="' + sectionIdx + '"'
                    + ' data-para-idx="' + paraIdx + '">🔊 Gen</button>';
            }

            // Render the full Audio Manager panel from overview data
            function renderAmPanel($panel, questId, sections) {
                var totalParas = 0, totalReady = 0;
                sections.forEach(function(s) { totalParas += s.total_paras; totalReady += s.clips_ready; });

                // Update summary badge
                $panel.closest('.qp-audio-row').find('.qp-am-badge')
                    .text(totalReady + ' / ' + totalParas + ' clips ready');

                var html = '';

                sections.forEach(function(sec) {
                    var secLabel = sec.clips_ready + '/' + sec.total_paras;
                    html += '<div class="qp-am-section" data-section-idx="' + sec.section_idx + '" style="margin-bottom:14px;">'
                        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
                        + '<span style="font-size:12px;font-weight:600;color:#1e293b;">' + sec.title + '</span>'
                        + '<div style="display:flex;align-items:center;gap:6px;">'
                        + '<span style="font-size:11px;color:#94a3b8;">' + secLabel + ' clips</span>'
                        + '<button class="button button-small qp-am-gen-section"'
                        + ' data-quest-id="' + questId + '"'
                        + ' data-section-idx="' + sec.section_idx + '"'
                        + ' title="Generate missing clips in this section">⬇ Gen Missing</button>'
                        + '</div></div>'
                        + '<table style="width:100%;font-size:11px;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden;border:1px solid #e2e8f0;">'
                        + '<thead><tr style="background:#f1f5f9;">'
                        + '<th style="padding:5px 8px;text-align:left;width:28px;color:#64748b;">Pg</th>'
                        + '<th style="padding:5px 8px;text-align:left;color:#64748b;">Paragraph preview</th>'
                        + '<th style="padding:5px 8px;width:70px;text-align:right;color:#64748b;">Chars</th>'
                        + '<th style="padding:5px 8px;width:58px;text-align:right;color:#64748b;">Cost</th>'
                        + '<th style="padding:5px 8px;width:180px;text-align:center;color:#64748b;">Audio</th>'
                        + '</tr></thead><tbody>';

                    sec.paras.forEach(function(para) {
                        var statusIcon = para.has_audio
                            ? '<span style="color:#16a34a;margin-right:4px;">✅</span>'
                            : '<span style="color:#cbd5e1;margin-right:4px;">○</span>';
                        html += '<tr class="qp-am-para-row" data-quest-id="' + questId + '"'
                            + ' data-section-idx="' + sec.section_idx + '"'
                            + ' data-para-idx="' + para.para_idx + '"'
                            + ' style="border-top:1px solid #f1f5f9;">'
                            + '<td style="padding:6px 8px;color:#94a3b8;font-weight:600;">' + (para.para_idx + 1) + '</td>'
                            + '<td style="padding:6px 8px;color:#374151;max-width:220px;">'
                            + statusIcon
                            + '<span title="' + para.preview.replace(/"/g, '&quot;') + '">'
                            + para.preview + '</span></td>'
                            + '<td style="padding:6px 8px;text-align:right;color:#64748b;">' + para.char_count.toLocaleString() + '</td>'
                            + '<td style="padding:6px 8px;text-align:right;color:#64748b;">$' + para.cost.toFixed(4) + '</td>'
                            + '<td style="padding:6px 8px;text-align:center;" class="qp-am-audio-cell">'
                            + amAudioCell(questId, sec.section_idx, para.para_idx, para.audio_url)
                            + '</td></tr>';
                    });

                    html += '</tbody></table></div>';
                });

                // Footer bulk actions
                html += '<div style="display:flex;gap:6px;justify-content:flex-end;padding-top:10px;border-top:1px solid #e2e8f0;">'
                    + '<button class="button button-small qp-am-gen-parallel" data-quest-id="' + questId + '"'
                    + ' title="Fire all missing clips simultaneously (faster)">⚡ Parallel</button>'
                    + '<button class="button button-small qp-am-gen-sequential" data-quest-id="' + questId + '"'
                    + ' title="Generate one clip at a time in order">→ Sequential</button>'
                    + '<button class="button button-small qp-am-clear-all" data-quest-id="' + questId + '"'
                    + ' style="color:#dc2626;" title="Delete all clips for this quest">🗑 Clear All</button>'
                    + '</div>';

                $panel.html(html);
            }

            // Generate one paragraph clip; returns a jQuery Deferred
            function amGenPara($row) {
                var questId    = $row.data('quest-id');
                var sectionIdx = $row.data('section-idx');
                var paraIdx    = $row.data('para-idx');
                var $cell      = $row.find('.qp-am-audio-cell');

                $cell.html('<span style="color:#0369a1;font-size:11px;">⏳ Generating…</span>');
                $row.find('td:first').css('color', '#0369a1');

                var dfd = $.Deferred();

                $.post(ajaxurl, {
                    action:      'knowly_quests_gen_para_audio',
                    nonce:       nonce,
                    quest_id:    questId,
                    section_idx: sectionIdx,
                    para_idx:    paraIdx,
                }, function(res) {
                    if (res.success) {
                        $cell.html(amAudioCell(questId, sectionIdx, paraIdx, res.data.audio_url));
                        $row.find('td:nth-child(2) span:first').replaceWith('<span style="color:#16a34a;margin-right:4px;">✅</span>');
                        $row.find('td:first').css('color', '#94a3b8');
                        dfd.resolve(res);
                    } else {
                        $cell.html('<span style="color:#dc2626;font-size:11px;" title="' + (res.data.message||'') + '">✗ Failed</span>');
                        $row.find('td:first').css('color', '#dc2626');
                        dfd.reject(res);
                    }
                }).fail(function() {
                    $cell.html('<span style="color:#dc2626;font-size:11px;">✗ Error</span>');
                    dfd.reject();
                });

                return dfd.promise();
            }

            // Collect all missing-clip rows from a panel (or section)
            function amMissingRows($scope) {
                return $scope.find('.qp-am-para-row').filter(function() {
                    return $(this).find('.qp-am-gen').length > 0;
                }).toArray().map(function(el) { return $(el); });
            }

            // Sequential: chain promises one by one
            function amRunSequential(rows) {
                if (!rows.length) return;
                amGenPara(rows.shift()).always(function() { amRunSequential(rows); });
            }

            // Toggle / load Audio Manager panel
            $(document).on('click', '.qp-am-toggle', function() {
                var $btn    = $(this);
                var questId = $btn.data('quest-id');
                var $panel  = $btn.closest('.qp-audio-row').find('.qp-am-panel');

                if ($panel.is(':visible')) {
                    $panel.slideUp(150);
                    $btn.text('🎙 Audio Manager');
                    return;
                }

                $panel.slideDown(150);
                $btn.text('🎙 Audio Manager ▲');

                if ($btn.data('loaded')) return; // already populated
                $btn.data('loaded', 1);

                $.post(ajaxurl, {
                    action:   'knowly_quests_audio_overview',
                    nonce:    nonce,
                    quest_id: questId,
                }, function(res) {
                    if (res.success) {
                        renderAmPanel($panel, questId, res.data.sections || []);
                    } else {
                        $panel.html('<p style="color:#dc2626;font-size:12px;">Error: ' + (res.data.message || 'Failed to load.') + '</p>');
                    }
                }).fail(function() {
                    $panel.html('<p style="color:#dc2626;font-size:12px;">Request failed.</p>');
                });
            });

            // Generate a single paragraph
            $(document).on('click', '.qp-am-gen', function() {
                amGenPara($(this).closest('.qp-am-para-row'));
            });

            // Delete a single paragraph clip
            $(document).on('click', '.qp-am-del', function() {
                if (!confirm('Delete this audio clip? It can be regenerated.')) return;
                var $btn       = $(this).prop('disabled', true);
                var $row       = $btn.closest('.qp-am-para-row');
                var questId    = $row.data('quest-id');
                var sectionIdx = $row.data('section-idx');
                var paraIdx    = $row.data('para-idx');

                $.post(ajaxurl, {
                    action:      'knowly_quests_del_para_audio',
                    nonce:       nonce,
                    quest_id:    questId,
                    section_idx: sectionIdx,
                    para_idx:    paraIdx,
                }, function(res) {
                    if (res.success) {
                        $row.find('.qp-am-audio-cell').html(amAudioCell(questId, sectionIdx, paraIdx, null));
                        $row.find('td:nth-child(2) span:first').replaceWith('<span style="color:#cbd5e1;margin-right:4px;">○</span>');
                    } else {
                        alert('Delete failed: ' + (res.data.message || 'Unknown error.'));
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Generate missing clips in one section (sequential)
            $(document).on('click', '.qp-am-gen-section', function() {
                var $btn       = $(this).prop('disabled', true).text('⏳ Running…');
                var $section   = $btn.closest('.qp-am-section');
                var rows       = amMissingRows($section);
                if (!rows.length) { $btn.prop('disabled', false).text('⬇ Gen Missing'); return; }
                amRunSequential(rows.slice());
                // Re-enable after all done (approximate — check every second)
                var check = setInterval(function() {
                    if (!amMissingRows($section).length || $section.find('⏳').length === 0) {
                        clearInterval(check);
                        $btn.prop('disabled', false).text('⬇ Gen Missing');
                    }
                }, 2000);
            });

            // Parallel: fire all missing at once
            $(document).on('click', '.qp-am-gen-parallel', function() {
                var $btn   = $(this).prop('disabled', true).text('⚡ Running…');
                var $panel = $btn.closest('.qp-am-panel');
                var rows   = amMissingRows($panel);
                if (!rows.length) { $btn.prop('disabled', false).text('⚡ Parallel'); return; }
                var promises = rows.map(function($row) { return amGenPara($row); });
                $.when.apply($, promises).always(function() {
                    $btn.prop('disabled', false).text('⚡ Parallel');
                });
            });

            // Sequential: generate all missing in order
            $(document).on('click', '.qp-am-gen-sequential', function() {
                var $btn   = $(this).prop('disabled', true).text('→ Running…');
                var $panel = $btn.closest('.qp-am-panel');
                var rows   = amMissingRows($panel);
                if (!rows.length) { $btn.prop('disabled', false).text('→ Sequential'); return; }

                function next(list) {
                    if (!list.length) { $btn.prop('disabled', false).text('→ Sequential'); return; }
                    amGenPara(list.shift()).always(function() { next(list); });
                }
                next(rows.slice());
            });

            // Clear all clips for the quest
            $(document).on('click', '.qp-am-clear-all', function() {
                if (!confirm('Delete ALL audio clips for this quest? They can be regenerated.')) return;
                var $btn   = $(this).prop('disabled', true);
                var $panel = $btn.closest('.qp-am-panel');
                var rows   = $panel.find('.qp-am-para-row').filter(function() {
                    return $(this).find('.qp-am-del').length > 0;
                }).toArray().map(function(el) { return $(el); });

                if (!rows.length) { $btn.prop('disabled', false); return; }

                function nextDel(list) {
                    if (!list.length) { $btn.prop('disabled', false); return; }
                    var $row = list.shift();
                    var questId    = $row.data('quest-id');
                    var sectionIdx = $row.data('section-idx');
                    var paraIdx    = $row.data('para-idx');
                    $.post(ajaxurl, {
                        action: 'knowly_quests_del_para_audio', nonce: nonce,
                        quest_id: questId, section_idx: sectionIdx, para_idx: paraIdx,
                    }, function(res) {
                        if (res.success) {
                            $row.find('.qp-am-audio-cell').html(amAudioCell(questId, sectionIdx, paraIdx, null));
                            $row.find('td:nth-child(2) span:first').replaceWith('<span style="color:#cbd5e1;margin-right:4px;">○</span>');
                        }
                        nextDel(list);
                    }).fail(function() { nextDel(list); });
                }
                nextDel(rows.slice());
            });

            window.qpBoardAction = function(questId, action, btn) {
                if (!confirm((action === 'approve' ? 'Approve' : 'Reject') + ' this quest?')) return;
                $(btn).prop('disabled', true);
                $.post(ajaxurl, { action: 'knowly_pool_approve_quest', nonce: nonce, quest_id: questId, quest_action: action }, function(res) {
                    if (res.success) {
                        var newStatus = res.data.status === 'approved' ? 'fulfilled' : res.data.status;
                        var $row = $(btn).closest('tr');
                        $row.find('td:nth-child(4)').html(slotBadge(newStatus));
                        $row.find('td:nth-child(5)').html(newStatus === 'fulfilled' ? '<span style="font-size:11px;color:#9ca3af;">Locked</span>' : '');
                    } else {
                        alert('Error: ' + (res.data.message || 'Failed'));
                        $(btn).prop('disabled', false);
                    }
                });
            };

            $('#qp-recheck').on('click', function() {
                var $btn    = $(this).prop('disabled', true).text('Checking…');
                var $result = $('#qp-sync-result');
                $result.html('<em>Pulling from Railway…</em>');
                $.post(ajaxurl, { action: 'knowly_pool_sync_quests', nonce: nonce }, function(res) {
                    $btn.prop('disabled', false).text('↻ Re-check');
                    if (res.success) {
                        var d = res.data;
                        $result.html('<span style="color:#16a34a;">↻ ' + d.synced + ' new, ' + d.updated + ' updated.</span>');
                        if (d.synced > 0 || d.updated > 0) {
                            $('#qp-generating-notice').hide();
                            loadBoard();
                        }
                    } else {
                        $result.html('<span style="color:#dc2626;">✗ ' + (res.data.message || 'Sync failed') + '</span>');
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
                'sort_order'       => isset( $meta['sort_order'] ) ? (int) $meta['sort_order'] : null,
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

    // ── AJAX: Generate quest MCQ questions ────────────────────────────────────

    public static function ajax_gen_questions(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id    = sanitize_text_field( $_POST['quest_id']     ?? '' );
        $level       = sanitize_key(        $_POST['level']        ?? '' );
        $period      = sanitize_key(        $_POST['period']       ?? '' );
        $subject     = sanitize_key(        $_POST['subject']      ?? '' );
        $module_title = sanitize_text_field( $_POST['module_title'] ?? '' );

        if ( ! $quest_id || ! $level || ! $subject || ! $module_title ) {
            wp_send_json_error( [ 'message' => 'quest_id, level, subject, and module_title are required.' ] );
            return;
        }

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
            return;
        }

        $body = [
            'curriculum'   => 'tt_primary',
            'quest_id'     => $quest_id,
            'level'        => $level,
            'subject'      => $subject,
            'module_title' => $module_title,
        ];
        if ( $period ) $body['period'] = $period;

        $response = wp_remote_post( $endpoint . '/api/v1/quest/generate-questions', [
            'timeout' => 60,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            wp_send_json_error( [ 'message' => $data['error'] ?? "Railway returned HTTP {$code}" ] );
            return;
        }

        Knowly_Debug::log( 'admin.quests', 'Quest questions generated', [
            'quest_id'       => $quest_id,
            'question_count' => $data['question_count'] ?? 0,
        ], null, 'info' );

        wp_send_json_success( [
            'quest_id'       => $quest_id,
            'question_count' => $data['question_count'] ?? 0,
        ] );
    }

    // ── AJAX: Audio Manager — overview ───────────────────────────────────────

    public static function ajax_audio_overview(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id = sanitize_text_field( $_POST['quest_id'] ?? '' );
        if ( ! $quest_id ) {
            wp_send_json_error( [ 'message' => 'quest_id is required.' ] );
            return;
        }

        $sections = Knowly_Polly_Service::get_overview( $quest_id );
        if ( is_wp_error( $sections ) ) {
            wp_send_json_error( [ 'message' => $sections->get_error_message() ] );
            return;
        }

        wp_send_json_success( [ 'sections' => $sections ] );
    }

    // ── AJAX: Audio Manager — generate one paragraph clip ────────────────────

    public static function ajax_gen_para_audio(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id    = sanitize_text_field( $_POST['quest_id']    ?? '' );
        $section_idx = (int) ( $_POST['section_idx'] ?? -1 );
        $para_idx    = (int) ( $_POST['para_idx']    ?? -1 );

        if ( ! $quest_id || $section_idx < 0 || $para_idx < 0 ) {
            wp_send_json_error( [ 'message' => 'quest_id, section_idx, and para_idx are required.' ] );
            return;
        }

        if ( function_exists( 'set_time_limit' ) ) set_time_limit( 120 );

        $result = Knowly_Polly_Service::generate_para( $quest_id, $section_idx, $para_idx );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            return;
        }

        wp_send_json_success( $result );
    }

    // ── AJAX: Audio Manager — delete one paragraph clip ───────────────────────

    public static function ajax_del_para_audio(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id    = sanitize_text_field( $_POST['quest_id']    ?? '' );
        $section_idx = (int) ( $_POST['section_idx'] ?? -1 );
        $para_idx    = (int) ( $_POST['para_idx']    ?? -1 );

        if ( ! $quest_id || $section_idx < 0 || $para_idx < 0 ) {
            wp_send_json_error( [ 'message' => 'quest_id, section_idx, and para_idx are required.' ] );
            return;
        }

        $result = Knowly_Polly_Service::delete_para( $quest_id, $section_idx, $para_idx );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            return;
        }

        wp_send_json_success( [ 'quest_id' => $quest_id, 'section_idx' => $section_idx, 'para_idx' => $para_idx ] );
    }

    // ── AJAX: Delete Polly audio for a quest ─────────────────────────────────

    public static function ajax_delete_audio(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id = sanitize_text_field( $_POST['quest_id'] ?? '' );
        if ( ! $quest_id ) {
            wp_send_json_error( [ 'message' => 'quest_id is required.' ] );
            return;
        }

        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'knowly_quests',
            [
                'audio_url'          => null,
                'audio_generated_at' => null,
            ],
            [ 'quest_id' => $quest_id, 'variant' => 'student' ],
            [ '%s', '%s' ],
            [ '%s', '%s' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'DB update failed: ' . $wpdb->last_error ] );
            return;
        }

        Knowly_Debug::log( 'polly.delete', 'Audio deleted', [ 'quest_id' => $quest_id ], null, 'info' );
        wp_send_json_success( [ 'quest_id' => $quest_id ] );
    }

    // ── AJAX: Generate Polly audio for a quest ────────────────────────────────

    public static function ajax_gen_audio(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id = sanitize_text_field( $_POST['quest_id'] ?? '' );
        if ( ! $quest_id ) {
            wp_send_json_error( [ 'message' => 'quest_id is required.' ] );
            return;
        }

        // Allow longer execution — Polly can take up to 60 s to synthesise
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 120 );
        }

        $result = Knowly_Polly_Service::generate( $quest_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            return;
        }

        wp_send_json_success( [
            'quest_id'  => $result['quest_id'],
            'audio_url' => $result['audio_url'],
        ] );
    }
}
