<?php
/**
 * Knowly_Admin_Trials — Trial delivery management (Phase 2 reimagine).
 *
 * Tabs:
 *   Overview      — QB bank fill health, WP pool state, session activity dashboard
 *   Question Bank — QB v2 slot inventory (delivery health view, read-only)
 *   Legacy Pool   — exam_pool packages + review queue (preserved workflow)
 *   Sessions      — Recent trial sessions: child, subject, score, source, state
 *   Health Checks — Railway + QB bank + WP pool connectivity checks
 *   Simulations   — Placeholder
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Trials {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_trials_health',      [ __CLASS__, 'ajax_health'      ] );
        add_action( 'wp_ajax_knowly_trials_overview',    [ __CLASS__, 'ajax_overview'    ] );
        add_action( 'wp_ajax_knowly_trials_qb_slots',    [ __CLASS__, 'ajax_qb_slots'    ] );
        add_action( 'wp_ajax_knowly_trials_sessions',    [ __CLASS__, 'ajax_sessions'    ] );
        add_action( 'wp_ajax_knowly_trials_sim_preview', [ __CLASS__, 'ajax_sim_preview' ] );
        add_action( 'wp_ajax_knowly_trials_build_pack',  [ __CLASS__, 'ajax_build_pack'  ] );
        add_action( 'wp_ajax_knowly_trials_packs_list',    [ __CLASS__, 'ajax_packs_list'     ] );
        add_action( 'wp_ajax_knowly_trials_pack_detail',  [ __CLASS__, 'ajax_pack_detail'    ] );
        add_action( 'wp_ajax_knowly_trials_pack_archive',    [ __CLASS__, 'ajax_pack_archive'    ] );
        add_action( 'wp_ajax_knowly_trials_pack_reactivate',[ __CLASS__, 'ajax_pack_reactivate' ] );
        add_action( 'wp_ajax_knowly_trials_pack_disband',   [ __CLASS__, 'ajax_pack_disband'    ] );
        add_action( 'wp_ajax_knowly_trials_get_modules',    [ __CLASS__, 'ajax_get_modules'     ] );
        add_action( 'wp_ajax_knowly_dynamic_preview',       [ __CLASS__, 'ajax_dynamic_preview' ] );
        add_action( 'wp_ajax_knowly_trials_dynamic_build',  [ __CLASS__, 'ajax_dynamic_build'   ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab        = sanitize_key( $_GET['tab'] ?? 'overview' );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $nonce      = wp_create_nonce( 'knowly_admin_nonce' );

        $tabs = [
            'overview' => 'Overview',
            'qb'       => 'Question Bank',
            'packs'    => 'Pack Library',
            'sessions' => 'Sessions',
            'health'   => 'Health Checks',
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
            <div class="notice notice-warning inline" style="margin:8px 0 0;">
                <p>Railway endpoint not configured. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a></p>
            </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php
            match ( $tab ) {
                'overview' => self::render_overview( $nonce ),
                'qb'       => self::render_qb( $nonce ),
                'packs'    => self::render_packs( $nonce ),
                'sessions' => self::render_sessions( $nonce ),
                'health'   => self::render_health( $nonce ),
                'sims'     => self::render_simulations( $nonce ),
                default    => self::render_overview( $nonce ),
            };
            ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Overview Tab
    // =========================================================================

    private static function render_overview( string $nonce ): void {
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <strong style="font-size:14px;color:#1d2327;">Delivery Health Dashboard</strong>
            <button id="knowly-refresh-overview" class="button button-small">↺ Refresh</button>
            <span id="knowly-overview-ts" style="font-size:11px;color:#999;margin-left:4px;"></span>
        </div>

        <!-- Stat cards -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px;">
            <?php
            $cards = [
                [ 'id' => 'stat-today',    'label' => 'Sessions Today',  'bg' => '#f0fdf4', 'border' => '#bbf7d0', 'label_c' => '#166534', 'val_c' => '#15803d' ],
                [ 'id' => 'stat-active',   'label' => 'Active Now',       'bg' => '#eff6ff', 'border' => '#bfdbfe', 'label_c' => '#1e40af', 'val_c' => '#1d4ed8' ],
                [ 'id' => 'stat-qb',       'label' => 'QB v2 Sessions',   'bg' => '#fef9c3', 'border' => '#fde68a', 'label_c' => '#854d0e', 'val_c' => '#a16207' ],
                [ 'id' => 'stat-total',    'label' => 'Total Sessions',   'bg' => '#f9fafb', 'border' => '#e5e7eb', 'label_c' => '#374151', 'val_c' => '#111827' ],
            ];
            foreach ( $cards as $c ) : ?>
            <div style="background:<?= $c['bg'] ?>;border:1px solid <?= $c['border'] ?>;border-radius:8px;padding:16px 18px;">
                <div style="font-size:10px;color:<?= $c['label_c'] ?>;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;"><?= esc_html( $c['label'] ) ?></div>
                <div id="<?= esc_attr( $c['id'] ) ?>" style="font-size:26px;font-weight:700;color:<?= $c['val_c'] ?>;">—</div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <!-- QB bank fill -->
            <div>
                <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px;">
                    QB v2 Bank — std_4 / term_1
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-question-bank' ) ) ?>" style="font-size:11px;font-weight:400;color:#2271b1;margin-left:8px;">Generate Questions →</a>
                </div>
                <div id="knowly-overview-qb" style="font-size:12px;color:#888;">Loading…</div>
            </div>
            <!-- WP pool -->
            <div>
                <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px;">
                    Legacy WP Pool
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-trials&tab=pool' ) ) ?>" style="font-size:11px;font-weight:400;color:#2271b1;margin-left:8px;">Manage Packages →</a>
                </div>
                <div id="knowly-overview-pool" style="font-size:12px;color:#888;">Loading…</div>
            </div>
        </div>

        <!-- Recent sessions preview -->
        <div>
            <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px;">
                Recent Sessions
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-trials&tab=sessions' ) ) ?>" style="font-size:11px;font-weight:400;color:#2271b1;margin-left:8px;">View all →</a>
            </div>
            <div id="knowly-overview-recent" style="font-size:12px;color:#888;">Loading…</div>
        </div>

        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';

            function loadOverview() {
                $('#knowly-refresh-overview').prop('disabled', true).text('Loading…');
                $.post(ajaxurl, { action: 'knowly_trials_overview', nonce: nonce }, function(res) {
                    $('#knowly-refresh-overview').prop('disabled', false).text('↺ Refresh');
                    if (!res.success) return;
                    var d = res.data;

                    $('#stat-today').text(d.sessions_today);
                    $('#stat-active').text(d.active_sessions);
                    $('#stat-qb').text(d.qb_sessions);
                    $('#stat-total').text(d.total_sessions);
                    $('#knowly-overview-ts').text('Updated ' + new Date().toLocaleTimeString());

                    // QB fill table
                    var subjKeys = Object.keys(d.qb_stats || {});
                    if (!subjKeys.length) {
                        $('#knowly-overview-qb').html('<em style="color:#999;">No QB data — check Railway connection.</em>');
                    } else {
                        var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                        subjKeys.forEach(function(subj) {
                            var s     = d.qb_stats[subj];
                            var pct   = s.total > 0 ? Math.round(s.seeded / s.total * 100) : 0;
                            var color = pct >= 75 ? '#16a34a' : pct > 0 ? '#d97706' : '#dc2626';
                            var bar   = '<div style="height:5px;background:#e5e7eb;border-radius:3px;margin-top:4px;"><div style="height:5px;width:' + pct + '%;background:' + color + ';border-radius:3px;transition:width .3s;"></div></div>';
                            html += '<tr><td style="padding:5px 10px 5px 0;width:110px;font-weight:600;text-transform:capitalize;">' + subj.replace('_', ' ') + '</td>'
                                + '<td><span style="color:' + color + ';font-weight:600;">' + s.seeded + '&thinsp;/&thinsp;' + s.total + ' slots seeded</span>' + bar + '</td></tr>';
                        });
                        html += '</table>';
                        $('#knowly-overview-qb').html(html);
                    }

                    // Pool status
                    var pColor = d.pool_approved > 0 ? '#16a34a' : '#dc2626';
                    $('#knowly-overview-pool').html(
                        '<div style="font-size:22px;font-weight:700;color:' + pColor + ';">' + d.pool_approved + ' approved</div>'
                        + '<div style="font-size:11px;color:#666;margin-top:2px;">of ' + d.pool_total + ' total packages &mdash; legacy fallback delivery</div>'
                        + (d.pool_sessions > 0 ? '<div style="font-size:11px;color:#888;margin-top:6px;">' + d.pool_sessions + ' sessions served from pool all-time</div>' : '')
                    );

                    // Recent sessions
                    if (!d.recent || !d.recent.length) {
                        $('#knowly-overview-recent').html('<p style="color:#888;">No sessions yet.</p>');
                        return;
                    }
                    var th = '<table class="knowly-table widefat" style="max-width:820px;"><thead><tr>'
                        + '<th>Student</th><th>Subject</th><th>Difficulty</th><th>Source</th><th>State</th><th>Score</th><th>Started</th>'
                        + '</tr></thead><tbody>';
                    d.recent.forEach(function(s) {
                        var srcBadge = s.source === 'question_bank'
                            ? '<span style="background:#dcfce7;color:#166534;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:600;">QB v2</span>'
                            : '<span style="background:#f3f4f6;color:#4b5563;border-radius:10px;padding:1px 7px;font-size:10px;">Pool</span>';
                        var stateColor = { completed: '#16a34a', active: '#1d4ed8', cancelled: '#6b7280' };
                        var stateBadge = '<span style="color:' + (stateColor[s.state] || '#374151') + ';font-weight:600;text-transform:capitalize;">' + s.state + '</span>';
                        var score = s.percentage !== null ? '<strong>' + parseFloat(s.percentage).toFixed(0) + '%</strong>' : '—';
                        th += '<tr>'
                            + '<td>' + $('<span>').text(s.child_name).html() + '</td>'
                            + '<td style="text-transform:capitalize;">' + s.subject.replace('_',' ') + '</td>'
                            + '<td style="text-transform:capitalize;">' + s.difficulty + '</td>'
                            + '<td>' + srcBadge + '</td>'
                            + '<td>' + stateBadge + '</td>'
                            + '<td>' + score + '</td>'
                            + '<td style="color:#6b7280;font-size:11px;">' + s.started_at + '</td>'
                            + '</tr>';
                    });
                    th += '</tbody></table>';
                    $('#knowly-overview-recent').html(th);
                });
            }

            $('#knowly-refresh-overview').on('click', loadOverview);
            loadOverview();
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // Question Bank Tab
    // =========================================================================

    private static function render_qb( string $nonce ): void {
        ?>
        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#0c4a6e;">
            <strong>Delivery Health View</strong> — Read-only. Shows how many active questions are available per slot.
            Use <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-question-bank' ) ) ?>">Question Bank admin</a> to generate more.
            Target: <strong>≥30</strong> active per slot. Low watermark triggers auto-replenishment at 15.
        </div>

        <div style="display:flex;align-items:flex-end;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <?php
            $selects = [
                [ 'id' => 'qb-filter-level',   'label' => 'Level',   'opts' => [ 'std_4' => 'std_4', 'std_5' => 'std_5' ] ],
                [ 'id' => 'qb-filter-period',  'label' => 'Period',  'opts' => [ 'term_1' => 'Term 1', 'term_2' => 'Term 2', 'term_3' => 'Term 3', '' => 'Capstone' ] ],
                [ 'id' => 'qb-filter-subject', 'label' => 'Subject', 'opts' => [ 'math' => 'Math', 'english' => 'English', 'science' => 'Science', 'social_studies' => 'Social Studies' ] ],
            ];
            foreach ( $selects as $s ) : ?>
            <div>
                <label style="font-size:11px;color:#666;display:block;margin-bottom:3px;"><?= esc_html( $s['label'] ) ?></label>
                <select id="<?= esc_attr( $s['id'] ) ?>" style="height:30px;">
                    <?php foreach ( $s['opts'] as $v => $l ) : ?>
                    <option value="<?= esc_attr( $v ) ?>"><?= esc_html( $l ) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <button id="qb-load-slots" class="button button-primary" style="height:30px;">Load Board</button>
        </div>

        <div style="font-size:11px;color:#666;margin-bottom:14px;">
            <span style="display:inline-block;width:12px;height:12px;background:#dcfce7;border:1px solid #bbf7d0;border-radius:3px;vertical-align:middle;"></span> ≥25 ready &nbsp;
            <span style="display:inline-block;width:12px;height:12px;background:#fef9c3;border:1px solid #fde68a;border-radius:3px;vertical-align:middle;"></span> 1–24 low &nbsp;
            <span style="display:inline-block;width:12px;height:12px;background:#fee2e2;border:1px solid #fecaca;border-radius:3px;vertical-align:middle;"></span> 0 empty
        </div>

        <div id="qb-slot-board"><p style="color:#888;">Select filters and click "Load Board".</p></div>

        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';

            function badge(n) {
                var bg = n >= 25 ? '#dcfce7' : n > 0 ? '#fef9c3' : '#fee2e2';
                var c  = n >= 25 ? '#166534' : n > 0 ? '#854d0e' : '#991b1b';
                return '<span style="background:' + bg + ';color:' + c + ';border-radius:12px;padding:2px 10px;font-weight:700;font-size:12px;">' + n + '</span>';
            }

            function loadSlots() {
                var level   = $('#qb-filter-level').val();
                var period  = $('#qb-filter-period').val();
                var subject = $('#qb-filter-subject').val();
                $('#qb-load-slots').prop('disabled', true).text('Loading…');
                $('#qb-slot-board').html('<p style="color:#888;">Loading…</p>');

                $.post(ajaxurl, {
                    action: 'knowly_trials_qb_slots',
                    nonce: nonce,
                    level: level,
                    period: period,
                    subject: subject,
                }, function(res) {
                    $('#qb-load-slots').prop('disabled', false).text('Load Board');
                    if (!res.success) {
                        $('#qb-slot-board').html('<p style="color:#dc2626;">' + (res.data && res.data.message ? res.data.message : 'Failed') + '</p>');
                        return;
                    }
                    var slots = res.data.slots || [];
                    if (!slots.length) {
                        $('#qb-slot-board').html('<p style="color:#666;">No modules found for this slot.</p>');
                        return;
                    }

                    // Group by module_number
                    var modules = {};
                    slots.forEach(function(s) {
                        var mn = s.module_number;
                        if (!modules[mn]) modules[mn] = { title: s.module_title, diffs: {} };
                        modules[mn].diffs[s.difficulty] = s.active_count;
                    });

                    var seeded = slots.filter(function(s) { return s.active_count > 0; }).length;
                    var empty  = slots.length - seeded;
                    var total_q = slots.reduce(function(acc, s) { return acc + s.active_count; }, 0);

                    var html = '<div style="display:flex;gap:18px;font-size:11px;color:#555;margin-bottom:12px;">'
                        + '<span>📦 <strong>' + Object.keys(modules).length + '</strong> modules</span>'
                        + '<span style="color:#16a34a;">● <strong>' + seeded + '</strong> seeded slots</span>'
                        + '<span style="color:#dc2626;">● <strong>' + empty + '</strong> empty slots</span>'
                        + '<span style="color:#374151;">📝 <strong>' + total_q + '</strong> active questions total</span>'
                        + '</div>';

                    html += '<table class="knowly-table widefat" style="max-width:680px;">'
                        + '<thead><tr>'
                        + '<th style="width:36px;color:#888;">#</th>'
                        + '<th>Module</th>'
                        + '<th style="text-align:center;width:80px;">Easy</th>'
                        + '<th style="text-align:center;width:80px;">Medium</th>'
                        + '<th style="text-align:center;width:80px;">Hard</th>'
                        + '</tr></thead><tbody>';

                    Object.keys(modules).sort(function(a, b) { return a - b; }).forEach(function(mn) {
                        var mod  = modules[mn];
                        var easy = mod.diffs.easy   || 0;
                        var med  = mod.diffs.medium  || 0;
                        var hard = mod.diffs.hard    || 0;
                        var rowReady = (easy >= 25 && med >= 25 && hard >= 25);
                        var rowBg = rowReady ? '' : ' style="background:#fffbeb;"';
                        html += '<tr' + rowBg + '>'
                            + '<td style="color:#9ca3af;font-size:11px;">' + mn + '</td>'
                            + '<td style="font-weight:500;">' + mod.title + '</td>'
                            + '<td style="text-align:center;">' + badge(easy) + '</td>'
                            + '<td style="text-align:center;">' + badge(med)  + '</td>'
                            + '<td style="text-align:center;">' + badge(hard) + '</td>'
                            + '</tr>';
                    });

                    html += '</tbody></table>';
                    $('#qb-slot-board').html(html);
                });
            }

            $('#qb-load-slots').on('click', loadSlots);
            loadSlots();
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // =========================================================================
    // Sessions Tab
    // =========================================================================

    private static function render_sessions( string $nonce ): void {
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
            <button id="sessions-load" class="button button-primary" style="height:30px;">Load Sessions</button>
            <select id="sessions-filter-subject" style="height:30px;">
                <option value="">All Subjects</option>
                <option value="math">Math</option>
                <option value="english">English</option>
                <option value="science">Science</option>
                <option value="social_studies">Social Studies</option>
            </select>
            <select id="sessions-filter-source" style="height:30px;">
                <option value="">All Sources</option>
                <option value="qb">QB v2</option>
                <option value="pool">Legacy Pool</option>
            </select>
            <select id="sessions-filter-state" style="height:30px;">
                <option value="">All States</option>
                <option value="completed">Completed</option>
                <option value="active">Active</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <span id="sessions-summary" style="font-size:12px;color:#888;margin-left:4px;"></span>
        </div>

        <div id="sessions-table-wrap" style="font-size:12px;">
            <p style="color:#888;">Click "Load Sessions" to view recent trial activity.</p>
        </div>
        <div id="sessions-pagination" style="margin-top:12px;display:flex;gap:8px;align-items:center;font-size:12px;"></div>

        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';

            function srcBadge(src) {
                return src === 'question_bank'
                    ? '<span style="background:#dcfce7;color:#166534;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:600;">QB v2</span>'
                    : '<span style="background:#f3f4f6;color:#4b5563;border-radius:10px;padding:2px 8px;font-size:10px;">Pool</span>';
            }
            function stateBadge(state) {
                var cfg = { completed: ['#dcfce7','#166534'], active: ['#dbeafe','#1e40af'], cancelled: ['#f3f4f6','#6b7280'] };
                var c = cfg[state] || ['#f3f4f6','#374151'];
                return '<span style="background:' + c[0] + ';color:' + c[1] + ';border-radius:10px;padding:2px 8px;font-size:10px;font-weight:600;text-transform:capitalize;">' + state + '</span>';
            }
            function diffLabel(d) {
                var c = { easy: '#16a34a', medium: '#d97706', hard: '#dc2626' };
                return '<span style="color:' + (c[d] || '#374151') + ';text-transform:capitalize;">' + d + '</span>';
            }
            function scoreBadge(pct, state) {
                if (state !== 'completed' || pct === null) return '—';
                var p = parseFloat(pct);
                var c = p >= 75 ? '#16a34a' : p >= 50 ? '#d97706' : '#dc2626';
                return '<strong style="color:' + c + ';">' + p.toFixed(0) + '%</strong>';
            }

            function loadSessions(page) {
                page = page || 1;
                $('#sessions-load').prop('disabled', true).text('Loading…');
                $.post(ajaxurl, {
                    action:  'knowly_trials_sessions',
                    nonce:   nonce,
                    page:    page,
                    subject: $('#sessions-filter-subject').val(),
                    source:  $('#sessions-filter-source').val(),
                    state:   $('#sessions-filter-state').val(),
                }, function(res) {
                    $('#sessions-load').prop('disabled', false).text('Load Sessions');
                    if (!res.success) { $('#sessions-table-wrap').html('<p style="color:#dc2626;">Failed to load sessions.</p>'); return; }
                    var d = res.data;
                    $('#sessions-summary').text(d.total + ' total · page ' + d.page + ' of ' + d.pages);

                    if (!d.sessions.length) {
                        $('#sessions-table-wrap').html('<p style="color:#666;">No sessions found matching the current filters.</p>');
                        $('#sessions-pagination').html('');
                        return;
                    }

                    var html = '<table class="knowly-table widefat">'
                        + '<thead><tr>'
                        + '<th>Student</th><th>Subject</th><th>Level</th><th>Period</th><th>Difficulty</th>'
                        + '<th>Source</th><th>State</th><th style="text-align:center;">Score</th><th>Duration</th><th>Started</th>'
                        + '</tr></thead><tbody>';

                    d.sessions.forEach(function(s) {
                        var dur = s.time_taken_seconds ? Math.round(s.time_taken_seconds / 60) + 'm' : '—';
                        var per = s.period ? s.period.replace('_', ' ') : '<em style="color:#9ca3af;">Capstone</em>';
                        html += '<tr>'
                            + '<td style="font-weight:500;">' + $('<span>').text(s.child_name).html() + '</td>'
                            + '<td style="text-transform:capitalize;">' + s.subject.replace('_', ' ') + '</td>'
                            + '<td>' + s.level + '</td>'
                            + '<td>' + per + '</td>'
                            + '<td>' + diffLabel(s.difficulty) + '</td>'
                            + '<td>' + srcBadge(s.source) + '</td>'
                            + '<td>' + stateBadge(s.state) + '</td>'
                            + '<td style="text-align:center;">' + scoreBadge(s.percentage, s.state) + '</td>'
                            + '<td style="color:#6b7280;">' + dur + '</td>'
                            + '<td style="color:#6b7280;font-size:11px;white-space:nowrap;">' + s.started_at + '</td>'
                            + '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#sessions-table-wrap').html(html);

                    var pHtml = '';
                    if (d.page > 1)        pHtml += '<button class="button button-small sessions-page" data-page="' + (d.page - 1) + '">← Prev</button>';
                    pHtml += '<span style="color:#666;padding:0 4px;">Page ' + d.page + ' of ' + d.pages + '</span>';
                    if (d.page < d.pages)  pHtml += '<button class="button button-small sessions-page" data-page="' + (d.page + 1) + '">Next →</button>';
                    $('#sessions-pagination').html(pHtml);
                });
            }

            $('#sessions-load').on('click', function() { loadSessions(1); });
            $('#sessions-filter-subject, #sessions-filter-source, #sessions-filter-state').on('change', function() { loadSessions(1); });
            $(document).on('click', '.sessions-page', function() { loadSessions($(this).data('page')); });
            loadSessions(1);
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // Health Checks Tab
    // =========================================================================

    private static function render_health( string $nonce ): void {
        ?>
        <button id="knowly-trials-run-health" class="button button-primary" style="margin-bottom:16px;">
            Run Health Checks
        </button>
        <div id="knowly-trials-health-results"><p style="color:#888;">Click to run health checks.</p></div>
        <script>
        jQuery('#knowly-trials-run-health').on('click', function() {
            var $btn = jQuery(this).prop('disabled', true).text('Running…');
            jQuery.post(ajaxurl, { action: 'knowly_trials_health', nonce: '<?= esc_js( $nonce ) ?>' }, function(res) {
                $btn.prop('disabled', false).text('Run Health Checks');
                if (!res.success) {
                    jQuery('#knowly-trials-health-results').html('<p style="color:#dc2626;">Failed.</p>');
                    return;
                }
                var html = '<table class="knowly-table" style="max-width:740px;"><tbody>';
                (res.data.checks || []).forEach(function(c) {
                    var col  = c.status === 'pass' ? '#16a34a' : c.status === 'warn' ? '#d97706' : '#dc2626';
                    var icon = c.status === 'pass' ? '✓' : c.status === 'warn' ? '⚠' : '✗';
                    html += '<tr>'
                        + '<td style="color:' + col + ';font-weight:700;width:32px;padding:5px 8px 5px 0;">' + icon + '</td>'
                        + '<td style="padding:5px 14px 5px 0;"><strong>' + c.label + '</strong></td>'
                        + '<td style="color:#555;font-size:12px;">' + (c.detail || '') + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                jQuery('#knowly-trials-health-results').html(html);
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // Simulations Tab
    // =========================================================================

    // =========================================================================
    // Pack Library Tab
    // =========================================================================

    private static function render_packs( string $nonce ): void {
        $cp  = self::curriculum_parts();
        $lvl = '';
        foreach ( $cp['levels']   as $v => $l ) $lvl .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
        $per = '';
        foreach ( $cp['periods']  as $v => $l ) $per .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
        $sub = '';
        foreach ( $cp['subjects'] as $v => $l ) $sub .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
        ?>
        <!-- ── PACK BUILDER (collapsed by default) ──────────────────────────── -->
        <details style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 16px;margin-bottom:18px;">
            <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#166534;list-style:none;display:flex;align-items:center;gap:6px;">
                <span>+</span> Build a New Pack
            </summary>
            <div style="margin-top:14px;">
                <!-- Scope + Load Modules -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:10px;">
                    <label style="font-size:12px;">Level
                        <select id="pb-level" class="pb-scope-field" style="display:block;height:30px;margin-top:4px;">
                            <?= $lvl ?>
                        </select>
                    </label>
                    <label style="font-size:12px;">Period
                        <select id="pb-period" class="pb-scope-field" style="display:block;height:30px;margin-top:4px;">
                            <?= $per ?>
                            <option value="">Capstone</option>
                        </select>
                    </label>
                    <label style="font-size:12px;">Subject
                        <select id="pb-subject" class="pb-scope-field" style="display:block;height:30px;margin-top:4px;">
                            <?= $sub ?>
                        </select>
                    </label>
                    <div style="display:flex;align-items:flex-end;padding-bottom:1px;">
                        <button id="pb-load-modules-btn" class="button" style="height:30px;">Load Modules</button>
                    </div>
                </div>
                <!-- Module + Pack Type + Difficulty + Actions -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                    <label style="font-size:12px;">Module <span style="color:#6b7280;font-weight:400;">(Topic only)</span>
                        <select id="pb-module" style="display:block;height:30px;margin-top:4px;min-width:200px;">
                            <option value="">All modules (General)</option>
                        </select>
                    </label>
                    <label style="font-size:12px;">Pack Type
                        <select id="pb-pack-type" style="display:block;height:30px;margin-top:4px;">
                            <option value="topic">Topic (single module)</option>
                            <option value="general">General (all modules)</option>
                        </select>
                    </label>
                    <label style="font-size:12px;">Difficulty
                        <select id="pb-difficulty" style="display:block;height:30px;margin-top:4px;">
                            <option value="easy">Easy (12 Qs)</option>
                            <option value="medium">Medium (18 Qs)</option>
                            <option value="hard">Hard (24 Qs)</option>
                        </select>
                    </label>
                    <div style="display:flex;gap:6px;align-items:flex-end;padding-bottom:1px;">
                        <button id="pb-preview-btn" class="button button-primary" style="height:30px;">Preview</button>
                        <button id="pb-build-btn" class="button" style="height:30px;background:#16a34a;border-color:#16a34a;color:#fff;">Build &amp; Save</button>
                    </div>
                </div>
                <p id="pb-status" style="margin:10px 0 0;font-size:12px;color:#666;"></p>
                <!-- Preview drawer -->
                <div id="pb-results" style="display:none;margin-top:14px;">
                    <strong id="pb-results-title" style="font-size:12.5px;display:block;margin-bottom:10px;"></strong>
                    <span id="pb-pack-saved" style="font-size:12px;color:#16a34a;font-weight:600;display:none;margin-bottom:8px;display:block;"></span>
                    <div id="pb-questions"></div>
                </div>
            </div>
        </details>

        <!-- ── DYNAMIC PACK BUILDER ────────────────────────────────────────────── -->
        <details id="pb-dyn-details" style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin-bottom:18px;">
            <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#92400e;list-style:none;display:flex;align-items:center;gap:6px;">
                <span>+</span> Build a Dynamic Pack <span style="font-weight:400;color:#d97706;font-size:11.5px;margin-left:4px;">(multi-module · random difficulty per module)</span>
            </summary>
            <div style="margin-top:14px;">
                <p style="font-size:12px;color:#78716c;margin:0 0 10px;">Uses the scope selectors above. Click <strong>Load Modules</strong> first to populate the checklist, then select which modules to include.</p>

                <!-- Module checklist (populated by pb-load-modules-btn) -->
                <div id="pb-dyn-module-list" style="background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:10px 12px;margin-bottom:12px;min-height:40px;font-size:12.5px;color:#999;">
                    Load modules above first.
                </div>

                <!-- QPM + actions -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                    <label style="font-size:12px;">Qs per module
                        <select id="pb-dyn-qpm" style="display:block;height:30px;margin-top:4px;width:80px;">
                            <option value="3">3</option>
                            <option value="4" selected>4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                        </select>
                    </label>
                    <div style="padding-top:18px;display:flex;gap:6px;">
                        <button id="pb-dyn-preview-btn" class="button" style="height:30px;background:#d97706;border-color:#d97706;color:#fff;">Preview Dynamic</button>
                        <button id="pb-dyn-build-btn" class="button" style="height:30px;background:#7c3aed;border-color:#7c3aed;color:#fff;">Build &amp; Save Dynamic Pack</button>
                    </div>
                </div>
                <p id="pb-dyn-status" style="margin:10px 0 0;font-size:12px;color:#666;"></p>

                <!-- Results -->
                <div id="pb-dyn-results" style="display:none;margin-top:14px;">
                    <strong id="pb-dyn-results-title" style="font-size:12.5px;display:block;margin-bottom:6px;"></strong>
                    <span id="pb-dyn-pack-saved" style="font-size:12px;color:#7c3aed;font-weight:600;display:none;margin-bottom:10px;"></span>
                    <div id="pb-dyn-assignments" style="margin-bottom:12px;"></div>
                    <div id="pb-dyn-questions"></div>
                </div>
            </div>
        </details>

        <!-- ── PACK LIST FILTERS ─────────────────────────────────────────────── -->
        <!-- Filters -->
        <div style="background:#f6f7f7;border:1px solid #ddd;border-radius:6px;padding:14px 16px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <label style="font-size:12px;">Level
                <select id="pl-level" style="display:block;height:30px;margin-top:4px;">
                    <option value="">All</option>
                    <?= $lvl ?>
                </select>
            </label>
            <label style="font-size:12px;">Period
                <select id="pl-period" style="display:block;height:30px;margin-top:4px;">
                    <option value="">All</option>
                    <?= $per ?>
                </select>
            </label>
            <label style="font-size:12px;">Subject
                <select id="pl-subject" style="display:block;height:30px;margin-top:4px;">
                    <option value="">All</option>
                    <?= $sub ?>
                </select>
            </label>
            <label style="font-size:12px;">Difficulty
                <select id="pl-difficulty" style="display:block;height:30px;margin-top:4px;">
                    <option value="">All</option>
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </label>
            <label style="font-size:12px;">Status
                <select id="pl-status" style="display:block;height:30px;margin-top:4px;">
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                    <option value="all">All</option>
                </select>
            </label>
            <button id="pl-load-btn" class="button button-primary" style="height:30px;">Load Packs</button>
        </div>

        <!-- Pack table -->
        <div id="pl-wrap">
            <p style="color:#999;font-size:13px;">Set filters above and click <em>Load Packs</em>.</p>
        </div>

        <!-- Question drawer (hidden until a pack row is expanded) -->
        <div id="pl-drawer" style="display:none;margin-top:16px;background:#fafafa;border:1px solid #ddd;border-radius:6px;padding:16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <strong id="pl-drawer-title" style="font-size:13px;"></strong>
                <button id="pl-drawer-close" class="button button-small">✕ Close</button>
            </div>
            <div id="pl-drawer-questions"></div>
        </div>

        <script>
        (function($) {
            var NONCE = '<?= esc_js( $nonce ) ?>';
            var currentPage = 1;
            var currentFilters = {};

            // ── Difficulty badge colours ──────────────────────────────────────
            var DIFF_COLORS = {
                easy:   { bg: '#dcfce7', border: '#86efac', text: '#166534' },
                medium: { bg: '#fef9c3', border: '#fde68a', text: '#854d0e' },
                hard:   { bg: '#fee2e2', border: '#fca5a5', text: '#991b1b' },
            };
            function diffBadge(diff) {
                var c = DIFF_COLORS[diff] || { bg:'#f3f4f6', border:'#d1d5db', text:'#374151' };
                return '<span style="background:'+c.bg+';border:1px solid '+c.border+';color:'+c.text+';border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;">'+diff+'</span>';
            }

            // ── Load pack list ────────────────────────────────────────────────
            function loadPacks(page) {
                page = page || 1;
                currentPage = page;
                currentFilters = {
                    level:      $('#pl-level').val(),
                    period:     $('#pl-period').val(),
                    subject:    $('#pl-subject').val(),
                    difficulty: $('#pl-difficulty').val(),
                    status:     $('#pl-status').val(),
                };
                $('#pl-load-btn').prop('disabled', true).text('Loading…');
                $('#pl-drawer').hide();

                $.post(ajaxurl, Object.assign({
                    action: 'knowly_trials_packs_list',
                    nonce:  NONCE,
                    page:   page,
                }, currentFilters), function(resp) {
                    $('#pl-load-btn').prop('disabled', false).text('Load Packs');
                    if (!resp.success) {
                        $('#pl-wrap').html('<p style="color:#b91c1c;">'+resp.data+'</p>');
                        return;
                    }
                    renderTable(resp.data);
                });
            }

            // ── Render table ──────────────────────────────────────────────────
            function renderTable(data) {
                var packs = data.packs || [];
                if (!packs.length) {
                    $('#pl-wrap').html('<p style="color:#666;font-size:13px;">No packs found for the selected filters.</p>');
                    return;
                }

                var html = '<table class="wp-list-table widefat fixed striped" style="font-size:13px;">';
                html += '<thead><tr>';
                html += '<th style="width:120px;">Pack ID</th>';
                html += '<th style="width:70px;">Level</th>';
                html += '<th style="width:80px;">Period</th>';
                html += '<th style="width:90px;">Subject</th>';
                html += '<th style="width:80px;">Type</th>';
                html += '<th style="width:80px;">Difficulty</th>';
                html += '<th style="width:60px;">Qs</th>';
                html += '<th style="width:80px;">Modules</th>';
                html += '<th style="width:100px;">Created</th>';
                html += '<th style="width:100px;">Status</th>';
                html += '<th style="width:120px;">Actions</th>';
                html += '</tr></thead><tbody>';

                packs.forEach(function(p) {
                    var shortId = p.id ? p.id.substring(0,8)+'…' : '—';
                    var mods    = (p.module_numbers || []).join(', ') || '—';
                    var created = p.created_at ? p.created_at.substring(0,10) : '—';
                    var isActive = p.status === 'active';
                    var statusBadge = isActive
                        ? '<span style="background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:4px;padding:1px 7px;font-size:11px;">active</span>'
                        : '<span style="background:#f3f4f6;border:1px solid #d1d5db;color:#6b7280;border-radius:4px;padding:1px 7px;font-size:11px;">'+p.status+'</span>';
                    var archiveBtn = '';
                    if (isActive) {
                        archiveBtn = '<button class="button button-small pl-archive-btn" data-id="'+p.id+'" data-qcount="'+p.question_count+'" style="color:#b91c1c;border-color:#fca5a5;">Archive…</button>';
                    } else if (p.status === 'archived') {
                        archiveBtn =
                            '<button class="button button-small pl-reactivate-btn" data-id="'+p.id+'" style="color:#16a34a;border-color:#86efac;">Reactivate</button> ' +
                            '<button class="button button-small pl-disband-btn" data-id="'+p.id+'" data-qcount="'+p.question_count+'" style="color:#7c3aed;border-color:#c4b5fd;">Disband…</button>';
                    }
                    html += '<tr>';
                    html += '<td><code title="'+p.id+'">'+shortId+'</code></td>';
                    html += '<td>'+p.level+'</td>';
                    html += '<td>'+(p.period||'Capstone')+'</td>';
                    html += '<td>'+p.subject+'</td>';
                    html += '<td>'+p.pack_type+'</td>';
                    html += '<td>'+diffBadge(p.difficulty)+'</td>';
                    html += '<td>'+p.question_count+'</td>';
                    html += '<td>'+mods+'</td>';
                    html += '<td>'+created+'</td>';
                    html += '<td>'+statusBadge+'</td>';
                    html += '<td style="display:flex;gap:4px;flex-wrap:wrap;">';
                    html += '<button class="button button-small pl-view-btn" data-id="'+p.id+'" data-label="Pack '+shortId+' · '+p.difficulty+' · '+p.subject+'">View Qs</button>';
                    html += archiveBtn;
                    html += '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';

                // Pagination
                if (data.pages > 1) {
                    html += '<div style="margin-top:12px;display:flex;gap:6px;align-items:center;font-size:13px;">';
                    html += '<span style="color:#666;">Page '+data.page+' of '+data.pages+' ('+data.total+' packs)</span>';
                    if (data.page > 1) html += '<button class="button button-small pl-page-btn" data-page="'+(data.page-1)+'">← Prev</button>';
                    if (data.page < data.pages) html += '<button class="button button-small pl-page-btn" data-page="'+(data.page+1)+'">Next →</button>';
                    html += '</div>';
                } else {
                    html += '<p style="margin-top:8px;font-size:12px;color:#999;">'+data.total+' pack(s)</p>';
                }

                $('#pl-wrap').html(html);
            }

            // ── View questions in a pack ──────────────────────────────────────
            function viewPack(packId, label) {
                $('#pl-drawer-title').text('Loading…');
                $('#pl-drawer-questions').html('<p style="color:#999;">Fetching questions…</p>');
                $('#pl-drawer').show();
                $('html,body').animate({ scrollTop: $('#pl-drawer').offset().top - 40 }, 300);

                $.post(ajaxurl, {
                    action:  'knowly_trials_pack_detail',
                    nonce:   NONCE,
                    pack_id: packId,
                }, function(resp) {
                    if (!resp.success) {
                        $('#pl-drawer-questions').html('<p style="color:#b91c1c;">'+resp.data+'</p>');
                        return;
                    }
                    $('#pl-drawer-title').text(label + ' — ' + resp.data.questions.length + ' questions');
                    renderDrawerQuestions(resp.data.questions);
                });
            }

            function renderDrawerQuestions(questions) {
                if (!questions.length) {
                    $('#pl-drawer-questions').html('<p style="color:#666;">No questions returned.</p>');
                    return;
                }
                var CORRECT_STYLE = 'background:#f0fdf4;border-color:#86efac;';
                var html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));gap:14px;">';
                questions.forEach(function(q, i) {
                    var c = DIFF_COLORS[q.difficulty] || { bg:'#f9fafb', border:'#e5e7eb', text:'#374151' };
                    html += '<div style="background:#fff;border:1px solid '+c.border+';border-radius:8px;padding:14px;font-size:12.5px;">';
                    html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">';
                    html += '<span style="color:#6b7280;font-size:11px;">Q'+(i+1)+' · Mod '+q.module_number+' · '+q.topic+'</span>';
                    html += diffBadge(q.difficulty);
                    html += '</div>';
                    html += '<p style="margin:0 0 10px;font-weight:500;line-height:1.5;">'+escHtml(q.question)+'</p>';
                    ['A','B','C','D'].forEach(function(opt) {
                        var isCorrect = q.correct_answer === opt;
                        var optStyle  = 'border:1px solid #e5e7eb;border-radius:4px;padding:5px 8px;margin-bottom:4px;display:flex;align-items:center;gap:6px;';
                        if (isCorrect) optStyle += CORRECT_STYLE;
                        html += '<div style="'+optStyle+'">';
                        html += '<span style="font-weight:700;color:'+(isCorrect?'#16a34a':'#6b7280')+';">'+opt+'</span>';
                        html += '<span>'+escHtml((q.options||{})[opt]||'')+'</span>';
                        if (isCorrect) html += '<span style="margin-left:auto;color:#16a34a;font-size:11px;">✓ correct</span>';
                        html += '</div>';
                    });
                    if (q.explanation) {
                        html += '<p style="margin:8px 0 0;font-size:11.5px;color:#374151;background:#f9fafb;border-radius:4px;padding:6px 8px;"><strong>Explanation:</strong> '+escHtml(q.explanation)+'</p>';
                    }
                    if (q.tip) {
                        html += '<p style="margin:6px 0 0;font-size:11.5px;color:#7c3aed;background:#f5f3ff;border-radius:4px;padding:6px 8px;"><strong>Tip:</strong> '+escHtml(q.tip)+'</p>';
                    }
                    html += '</div>';
                });
                html += '</div>';
                $('#pl-drawer-questions').html(html);
            }

            function escHtml(str) {
                return $('<div>').text(str||'').html();
            }

            // ── Archive a pack (inline confirmation) ──────────────────────────
            var $archiveConfirm = null; // tracks the currently-open confirm row

            function closeArchiveConfirm() {
                if ($archiveConfirm) { $archiveConfirm.remove(); $archiveConfirm = null; }
            }

            function doArchive(packId, releaseQuestions, $confirmRow) {
                $confirmRow.find('button').prop('disabled', true);
                $confirmRow.find('.pl-archive-status').text('Archiving…');
                $.post(ajaxurl, {
                    action:            'knowly_trials_pack_archive',
                    nonce:             NONCE,
                    pack_id:           packId,
                    release_questions: releaseQuestions ? '1' : '0',
                }, function(resp) {
                    if (!resp.success) {
                        $confirmRow.find('.pl-archive-status').text(resp.data || 'Archive failed.').css('color','#b91c1c');
                        $confirmRow.find('button').prop('disabled', false);
                        return;
                    }
                    var msg = resp.data && resp.data.released
                        ? resp.data.released + ' question(s) released to pool.'
                        : 'Pack archived.';
                    $confirmRow.find('.pl-archive-status').text(msg).css('color','#16a34a');
                    setTimeout(function() { loadPacks(currentPage); }, 900);
                });
            }

            // ── Event bindings ────────────────────────────────────────────────
            $('#pl-load-btn').on('click', function() { loadPacks(1); });

            $(document).on('click', '.pl-view-btn', function() {
                var $b = $(this);
                viewPack($b.data('id'), $b.data('label'));
            });

            $(document).on('click', '.pl-archive-btn', function() {
                var $btn    = $(this);
                var packId  = $btn.data('id');
                var qcount  = $btn.data('qcount') || '?';
                closeArchiveConfirm();

                // Inject an inline confirmation row immediately after this table row
                var $row = $btn.closest('tr');
                var cols = $row.find('td').length;
                var confirmHtml =
                    '<tr class="pl-archive-confirm-row" style="background:#fff8f8;">' +
                    '<td colspan="'+cols+'" style="padding:10px 14px;border-top:2px solid #fca5a5;">' +
                    '<strong style="font-size:12.5px;color:#991b1b;">Archive this pack?</strong>' +
                    '<p style="margin:4px 0 10px;font-size:12px;color:#6b7280;">Choose how to handle the '+qcount+' locked questions:</p>' +
                    '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">' +
                    '<button class="button pl-archive-keep-btn" data-id="'+packId+'" style="font-size:12px;">Archive — keep questions locked</button>' +
                    '<button class="button pl-archive-release-btn" data-id="'+packId+'" style="font-size:12px;background:#7c3aed;border-color:#7c3aed;color:#fff;">Disband Pack — release '+qcount+' Qs to pool</button>' +
                    '<button class="button button-small pl-archive-cancel-btn" style="font-size:12px;">Cancel</button>' +
                    '<span class="pl-archive-status" style="font-size:12px;margin-left:6px;"></span>' +
                    '</div></td></tr>';
                $archiveConfirm = $(confirmHtml);
                $row.after($archiveConfirm);
            });

            // Disband a pack: release questions + permanently delete pack row
            function doDisband(packId, $confirmRow) {
                $confirmRow.find('button').prop('disabled', true);
                $confirmRow.find('.pl-archive-status').text('Disbanding…');
                $.post(ajaxurl, {
                    action:  'knowly_trials_pack_disband',
                    nonce:   NONCE,
                    pack_id: packId,
                }, function(resp) {
                    if (!resp.success) {
                        $confirmRow.find('.pl-archive-status').text(resp.data || 'Disband failed.').css('color','#b91c1c');
                        $confirmRow.find('button').prop('disabled', false);
                        return;
                    }
                    var released = resp.data && resp.data.released ? resp.data.released : 0;
                    $confirmRow.find('.pl-archive-status').text('Pack disbanded. ' + released + ' question(s) released to pool.').css('color','#16a34a');
                    setTimeout(function() { loadPacks(currentPage); }, 1200);
                });
            }

            $(document).on('click', '.pl-archive-keep-btn', function() {
                doArchive($(this).data('id'), false, $(this).closest('tr'));
            });
            $(document).on('click', '.pl-archive-release-btn', function() {
                doDisband($(this).data('id'), $(this).closest('tr'));
            });
            $(document).on('click', '.pl-archive-cancel-btn', function() {
                closeArchiveConfirm();
            });

            // Reactivate an archived pack
            $(document).on('click', '.pl-reactivate-btn', function() {
                var $btn   = $(this);
                var packId = $btn.data('id');
                $btn.prop('disabled', true).text('Reactivating…');
                $.post(ajaxurl, {
                    action:  'knowly_trials_pack_reactivate',
                    nonce:   NONCE,
                    pack_id: packId,
                }, function(resp) {
                    if (!resp.success) {
                        alert(resp.data || 'Reactivation failed.');
                        $btn.prop('disabled', false).text('Reactivate');
                        return;
                    }
                    loadPacks(currentPage);
                });
            });

            // Disband button on archived packs — show inline confirm
            $(document).on('click', '.pl-disband-btn', function() {
                var $btn   = $(this);
                var packId = $btn.data('id');
                var qcount = $btn.data('qcount') || '?';
                closeArchiveConfirm();
                var $row = $btn.closest('tr');
                var cols = $row.find('td').length;
                var confirmHtml =
                    '<tr class="pl-archive-confirm-row" style="background:#fdf4ff;">' +
                    '<td colspan="'+cols+'" style="padding:10px 14px;border-top:2px solid #c4b5fd;">' +
                    '<strong style="font-size:12.5px;color:#7c3aed;">Disband this pack?</strong>' +
                    '<p style="margin:4px 0 10px;font-size:12px;color:#6b7280;">This will permanently delete the pack and release all locked questions back to the pool. This cannot be undone.</p>' +
                    '<div style="display:flex;gap:8px;align-items:center;">' +
                    '<button class="button pl-disband-confirm-btn" data-id="'+packId+'" style="font-size:12px;background:#7c3aed;border-color:#7c3aed;color:#fff;">Yes, Disband Pack</button>' +
                    '<button class="button button-small pl-archive-cancel-btn" style="font-size:12px;">Cancel</button>' +
                    '<span class="pl-archive-status" style="font-size:12px;margin-left:6px;"></span>' +
                    '</div></td></tr>';
                $archiveConfirm = $(confirmHtml);
                $row.after($archiveConfirm);
            });

            $(document).on('click', '.pl-disband-confirm-btn', function() {
                doDisband($(this).data('id'), $(this).closest('tr'));
            });

            $(document).on('click', '.pl-page-btn', function() {
                loadPacks($(this).data('page'));
            });

            $('#pl-drawer-close').on('click', function() { $('#pl-drawer').hide(); });

            // ── Pack Builder (inline in Pack Library) ─────────────────────────
            var PB_NONCE = '<?= esc_js( $nonce ) ?>';

            function pbEsc(str) {
                return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }
            var PB_DIFF_COLORS = {
                easy:   { bg:'#dcfce7', border:'#86efac', text:'#166534' },
                medium: { bg:'#fef9c3', border:'#fde68a', text:'#854d0e' },
                hard:   { bg:'#fee2e2', border:'#fca5a5', text:'#991b1b' },
            };

            // Load modules for the builder dropdown (also populates dynamic checklist)
            $('#pb-load-modules-btn').on('click', function() {
                var level   = $('#pb-level').val();
                var subject = $('#pb-subject').val();
                if (!level || !subject) { alert('Select Level and Subject first.'); return; }
                $(this).prop('disabled',true).text('Loading…');
                $.post(ajaxurl, {
                    action: 'knowly_trials_get_modules', nonce: PB_NONCE,
                    level: level, subject: subject, period: $('#pb-period').val(),
                }, function(resp) {
                    $('#pb-load-modules-btn').prop('disabled',false).text('Load Modules');
                    if (!resp.success) { alert(resp.data||'Failed.'); return; }
                    var modules = resp.data.modules || [];

                    // Static builder dropdown
                    var opts = '<option value="">All modules (General)</option>';
                    modules.forEach(function(m) {
                        opts += '<option value="'+m.module_number+'">'+pbEsc(m.module_title)+'</option>';
                    });
                    $('#pb-module').html(opts);

                    // Dynamic builder checklist
                    if (!modules.length) {
                        $('#pb-dyn-module-list').html('<span style="color:#b91c1c;">No active modules found for this scope.</span>');
                        return;
                    }
                    var checks = '';
                    modules.forEach(function(m) {
                        checks += '<label style="display:inline-flex;align-items:center;gap:5px;margin:4px 12px 4px 0;cursor:pointer;">';
                        checks += '<input type="checkbox" class="pb-dyn-mod-cb" value="'+m.module_number+'" checked>';
                        checks += '<span>'+pbEsc(m.module_title)+'</span></label>';
                    });
                    $('#pb-dyn-module-list').html(checks);
                });
            });

            // Run builder preview or build+save
            function runPB(preview) {
                var $pb = $('#pb-preview-btn'), $bb = $('#pb-build-btn');
                $pb.prop('disabled',true); $bb.prop('disabled',true);
                $('#pb-status').text(preview?'Fetching preview…':'Building pack…').css('color','#666');
                $('#pb-pack-saved').hide();

                var modVal = $('#pb-module').val();
                $.post(ajaxurl, {
                    action:    preview ? 'knowly_trials_sim_preview' : 'knowly_trials_build_pack',
                    nonce:     PB_NONCE,
                    level:     $('#pb-level').val(),
                    period:    $('#pb-period').val(),
                    subject:   $('#pb-subject').val(),
                    module:    modVal,
                    pack_type: $('#pb-pack-type').val(),
                    difficulty:$('#pb-difficulty').val(),
                }, function(resp) {
                    $pb.prop('disabled',false); $bb.prop('disabled',false);
                    if (!resp.success) {
                        $('#pb-status').text('Error: '+(resp.data||'Request failed.')).css('color','#dc2626');
                        return;
                    }
                    var d   = resp.data;
                    var lbl = $('#pb-level').val()+' / '+(($('#pb-period').val())||'Capstone')+' / '+$('#pb-subject option:selected').text();
                    lbl    += modVal ? ' / '+$('#pb-module option:selected').text() : '';
                    lbl    += ' — '+$('#pb-difficulty option:selected').text();
                    $('#pb-results-title').text(lbl);
                    if (!preview && d.pack_id) {
                        $('#pb-pack-saved').text('✓ Pack saved — ID: '+d.pack_id).css('display','block');
                        setTimeout(function(){ loadPacks(1); }, 800);  // refresh list
                    }

                    // Render question cards
                    var qs = d.questions||[];
                    if (!qs.length) { $('#pb-questions').html('<p style="color:#888;">No questions returned.</p>'); }
                    else {
                        var html = '';
                        qs.forEach(function(q,i) {
                            var c   = PB_DIFF_COLORS[q.difficulty]||{bg:'#f9fafb',border:'#e5e7eb',text:'#374151'};
                            var ans = (q.correct_answer||'').toUpperCase();
                            html += '<div style="border:1px solid '+c.border+';border-radius:6px;padding:12px 14px;margin-bottom:8px;background:#fff;font-size:12.5px;">';
                            html += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">';
                            html += '<span style="color:#888;font-size:11px;">#'+(i+1)+'</span>';
                            html += '<span style="background:'+c.bg+';color:'+c.text+';border:1px solid '+c.border+';border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;">'+q.difficulty+'</span>';
                            if (q.module_title) html += '<span style="color:#aaa;font-size:11px;">'+pbEsc(q.module_title)+'</span>';
                            html += '</div><p style="margin:0 0 8px;font-weight:500;">'+pbEsc(q.question)+'</p>';
                            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:3px 12px;">';
                            ['A','B','C','D'].forEach(function(k){
                                var ok = k===ans;
                                html += '<div style="padding:4px 6px;border-radius:3px;font-size:11.5px;background:'+(ok?'#f0fdf4':'#f9fafb')+';border:'+(ok?'2px solid #16a34a':'1px solid #e5e7eb')+';">';
                                html += ok ? '<strong style="color:#16a34a;">'+k+'. '+pbEsc((q.options||{})[k]||'')+' ✓</strong>' : k+'. '+pbEsc((q.options||{})[k]||'');
                                html += '</div>';
                            });
                            html += '</div>';
                            if (q.explanation) html += '<p style="margin:5px 0 0;font-size:11.5px;color:#555;"><strong>Explanation:</strong> '+pbEsc(q.explanation)+'</p>';
                            html += '</div>';
                        });
                        $('#pb-questions').html(html);
                    }
                    $('#pb-results').show();
                    $('#pb-status').text(preview ? d.question_count+' Qs previewed (not saved).' : d.question_count+' Qs locked into pack.').css('color',preview?'#666':'#16a34a');
                }).fail(function() {
                    $pb.prop('disabled',false); $bb.prop('disabled',false);
                    $('#pb-status').text('Request failed.').css('color','#dc2626');
                });
            }

            $('#pb-preview-btn').on('click', function() { runPB(true); });
            $('#pb-build-btn').on('click', function() {
                if (!confirm('Build and save? Questions will be locked into this pack.')) return;
                runPB(false);
            });

            // ── Dynamic Pack Builder ──────────────────────────────────────────
            var PB_DYN_DIFF_COLORS = {
                easy:    { bg:'#dcfce7', border:'#86efac', text:'#166534' },
                medium:  { bg:'#fef9c3', border:'#fde68a', text:'#854d0e' },
                hard:    { bg:'#fee2e2', border:'#fca5a5', text:'#991b1b' },
                dynamic: { bg:'#ede9fe', border:'#c4b5fd', text:'#5b21b6' },
            };

            function pbDynDiffBadge(d) {
                var c = PB_DYN_DIFF_COLORS[d] || { bg:'#f3f4f6', border:'#d1d5db', text:'#374151' };
                return '<span style="background:'+c.bg+';border:1px solid '+c.border+';color:'+c.text+';border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;">'+pbEsc(d)+'</span>';
            }

            function runPBDynamic(save) {
                var selected = $('.pb-dyn-mod-cb:checked').map(function(){ return parseInt($(this).val()); }).get();
                if (!selected.length) { alert('Select at least one module.'); return; }

                var $prevBtn  = $('#pb-dyn-preview-btn');
                var $buildBtn = $('#pb-dyn-build-btn');
                $prevBtn.prop('disabled',true); $buildBtn.prop('disabled',true);
                $('#pb-dyn-status').text(save ? 'Building dynamic pack…' : 'Generating preview…').css('color','#666');
                $('#pb-dyn-pack-saved').hide();

                $.post(ajaxurl, {
                    action:  save ? 'knowly_trials_dynamic_build' : 'knowly_dynamic_preview',
                    nonce:   PB_NONCE,
                    level:   $('#pb-level').val(),
                    period:  $('#pb-period').val(),
                    subject: $('#pb-subject').val(),
                    modules: selected,
                    qpm:     $('#pb-dyn-qpm').val(),
                }, function(resp) {
                    $prevBtn.prop('disabled',false); $buildBtn.prop('disabled',false);
                    if (!resp.success) {
                        $('#pb-dyn-status').text('Error: '+(resp.data||'Request failed.')).css('color','#dc2626');
                        return;
                    }
                    var d = resp.data;
                    var lbl = $('#pb-level').val()+' / '+($('#pb-period').val()||'Capstone')+' / '+$('#pb-subject option:selected').text();
                    lbl += ' — Dynamic ('+selected.length+' modules × '+$('#pb-dyn-qpm').val()+' Qs)';
                    $('#pb-dyn-results-title').text(lbl);

                    if (save && d.pack_id) {
                        $('#pb-dyn-pack-saved').text('✓ Pack saved — ID: '+d.pack_id+' (Seq #'+d.sequence_number+')').css('display','block');
                        setTimeout(function(){ loadPacks(1); }, 800);
                    }

                    // Module assignment badges
                    var aHtml = '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">';
                    (d.module_assignments||[]).forEach(function(a) {
                        aHtml += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:12px;">';
                        aHtml += '<span style="font-weight:600;">Module '+a.module_number+'</span>&nbsp;';
                        aHtml += pbDynDiffBadge(a.difficulty_drawn);
                        aHtml += '&nbsp;<span style="color:#6b7280;">'+a.questions_drawn+' Qs</span>';
                        aHtml += '</div>';
                    });
                    aHtml += '</div>';
                    $('#pb-dyn-assignments').html(aHtml);

                    // Question cards (reuse runPB card style)
                    var qs = d.questions || [];
                    if (!qs.length) {
                        $('#pb-dyn-questions').html('<p style="color:#888;">No questions returned.</p>');
                    } else {
                        var html = '';
                        qs.forEach(function(q,i) {
                            var c   = PB_DYN_DIFF_COLORS[q.difficulty] || { bg:'#f9fafb', border:'#e5e7eb', text:'#374151' };
                            var ans = (q.correct_answer||'').toUpperCase();
                            html += '<div style="border:1px solid '+c.border+';border-radius:6px;padding:12px 14px;margin-bottom:8px;background:#fff;font-size:12.5px;">';
                            html += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">';
                            html += '<span style="color:#888;font-size:11px;">#'+(i+1)+'</span>';
                            html += pbDynDiffBadge(q.difficulty);
                            if (q.module_title) html += '<span style="color:#aaa;font-size:11px;">'+pbEsc(q.module_title)+'</span>';
                            html += '</div><p style="margin:0 0 8px;font-weight:500;">'+pbEsc(q.question)+'</p>';
                            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:3px 12px;">';
                            ['A','B','C','D'].forEach(function(k){
                                var ok = k===ans;
                                html += '<div style="padding:4px 6px;border-radius:3px;font-size:11.5px;background:'+(ok?'#f0fdf4':'#f9fafb')+';border:'+(ok?'2px solid #16a34a':'1px solid #e5e7eb')+';">';
                                html += ok ? '<strong style="color:#16a34a;">'+k+'. '+pbEsc((q.options||{})[k]||'')+' ✓</strong>' : k+'. '+pbEsc((q.options||{})[k]||'');
                                html += '</div>';
                            });
                            html += '</div>';
                            if (q.explanation) html += '<p style="margin:5px 0 0;font-size:11.5px;color:#555;"><strong>Explanation:</strong> '+pbEsc(q.explanation)+'</p>';
                            html += '</div>';
                        });
                        $('#pb-dyn-questions').html(html);
                    }
                    $('#pb-dyn-results').show();
                    $('#pb-dyn-status')
                        .text(save ? d.question_count+' Qs locked into dynamic pack.' : d.question_count+' Qs previewed (not saved).')
                        .css('color', save ? '#7c3aed' : '#666');
                }).fail(function() {
                    $prevBtn.prop('disabled',false); $buildBtn.prop('disabled',false);
                    $('#pb-dyn-status').text('Request failed.').css('color','#dc2626');
                });
            }

            $('#pb-dyn-preview-btn').on('click', function() { runPBDynamic(false); });
            $('#pb-dyn-build-btn').on('click', function() {
                if (!confirm('Build and save this dynamic pack? Questions will be locked and cannot be reused.')) return;
                runPBDynamic(true);
            });

        })(jQuery);
        </script>
        <?php
    }

    private static function render_simulations( string $nonce ): void {
        $cp  = self::curriculum_parts();
        $lvl = '';
        foreach ( $cp['levels']   as $v => $l ) $lvl .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
        $per = '';
        foreach ( $cp['periods']  as $v => $l ) $per .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
        $sub = '';
        foreach ( $cp['subjects'] as $v => $l ) $sub .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
        ?>
        <!-- Instruction guide -->
        <details style="background:#eef6ff;border:1px solid #b3d4f5;border-radius:6px;padding:12px 16px;margin-bottom:18px;">
            <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#1e40af;list-style:none;display:flex;align-items:center;gap:6px;">
                <span>&#9432;</span> How to use this page
            </summary>
            <div style="margin-top:12px;font-size:12.5px;line-height:1.7;color:#374151;">
                <p style="margin:0 0 8px;"><strong>Static Pack Builder</strong> — builds an immutable pack from a single module (Topic) or all modules (General). Questions are locked to the pack on save.</p>
                <p style="margin:0 0 8px;"><strong>Dynamic Pack</strong> — select several modules; the system randomly assigns a difficulty to each. Use <em>Generate Dynamic Preview</em> to simulate without saving, or <em>Build &amp; Save Dynamic Pack</em> to lock questions into a permanent dynamic pack (appears in the Pack Library).</p>
                <p style="margin:0 0 8px;"><strong>Difficulty mix per pack:</strong> Easy (12 Qs): 9 easy + 2 medium + 1 hard &nbsp;|&nbsp; Medium (18 Qs): 4 easy + 9 medium + 5 hard &nbsp;|&nbsp; Hard (24 Qs): 3 easy + 9 medium + 12 hard</p>
                <p style="margin:0;"><strong>Module names</strong> are loaded from the QB — select Level + Subject + Period first, then click <em>Load Modules</em>.</p>
            </div>
        </details>

        <!-- ── STATIC PACK BUILDER ─────────────────────────────────────────────── -->
        <div style="background:#f6f7f7;border:1px solid #ddd;border-radius:6px;padding:16px 18px;margin-bottom:20px;">
            <strong style="font-size:13px;display:block;margin-bottom:12px;">Static Pack Builder</strong>

            <!-- Row 1: scope selectors + Load Modules -->
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:10px;">
                <label style="font-size:12px;">Level
                    <select id="sim-level" class="sim-scope-field" style="display:block;height:30px;margin-top:4px;">
                        <?= $lvl ?>
                    </select>
                </label>
                <label style="font-size:12px;">Period
                    <select id="sim-period" class="sim-scope-field" style="display:block;height:30px;margin-top:4px;">
                        <?= $per ?>
                        <option value="">Capstone</option>
                    </select>
                </label>
                <label style="font-size:12px;">Subject
                    <select id="sim-subject" class="sim-scope-field" style="display:block;height:30px;margin-top:4px;">
                        <?= $sub ?>
                    </select>
                </label>
                <div style="display:flex;align-items:flex-end;padding-bottom:1px;">
                    <button id="sim-load-modules-btn" class="button" style="height:30px;">Load Modules</button>
                </div>
            </div>

            <!-- Row 2: module + pack type + difficulty + actions -->
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                <label style="font-size:12px;">Module <span style="color:#6b7280;font-weight:400;">(Topic only)</span>
                    <select id="sim-module" style="display:block;height:30px;margin-top:4px;min-width:200px;">
                        <option value="">All modules (General)</option>
                    </select>
                </label>
                <label style="font-size:12px;">Pack Type
                    <select id="sim-pack-type" style="display:block;height:30px;margin-top:4px;">
                        <option value="topic">Topic (single module)</option>
                        <option value="general">General (all modules)</option>
                    </select>
                </label>
                <label style="font-size:12px;">Difficulty
                    <select id="sim-difficulty" style="display:block;height:30px;margin-top:4px;">
                        <option value="easy">Easy (12 Qs)</option>
                        <option value="medium">Medium (18 Qs)</option>
                        <option value="hard">Hard (24 Qs)</option>
                    </select>
                </label>
                <div style="display:flex;gap:6px;align-items:flex-end;padding-bottom:1px;">
                    <button id="sim-preview-btn" class="button button-primary" style="height:30px;">Preview</button>
                    <button id="sim-build-btn" class="button" style="height:30px;background:#16a34a;border-color:#16a34a;color:#fff;">Build &amp; Save</button>
                </div>
            </div>
            <p id="sim-status" style="margin:10px 0 0;font-size:12px;color:#666;"></p>
        </div>

        <!-- Static pack question preview -->
        <div id="sim-results" style="display:none;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <strong id="sim-results-title" style="font-size:13px;"></strong>
                <span id="sim-pack-saved" style="font-size:12px;color:#16a34a;font-weight:600;display:none;">✓ Pack saved</span>
            </div>
            <div id="sim-questions"></div>
        </div>

        <hr style="margin:28px 0;border:none;border-top:2px solid #e5e7eb;">

        <!-- ── DYNAMIC PREVIEW / BUILD ───────────────────────────────────────── -->
        <div style="background:#fafaf5;border:1px solid #d4c97a;border-radius:6px;padding:16px 18px;margin-bottom:20px;">
            <strong style="font-size:13px;display:block;margin-bottom:6px;">Dynamic Pack <span style="font-weight:400;color:#92400e;font-size:11.5px;">(multi-module · random difficulty per module)</span></strong>
            <p style="font-size:12px;color:#78716c;margin:0 0 12px;">Select multiple modules. Difficulty is randomly assigned per module. <em>Preview</em> simulates the result without saving; <em>Build &amp; Save</em> locks questions into a permanent dynamic pack.</p>

            <div id="dyn-module-list" style="background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:10px 12px;margin-bottom:12px;min-height:40px;font-size:12.5px;color:#999;">
                Load modules above first (uses the same scope selectors).
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                <label style="font-size:12px;">Qs per module
                    <select id="dyn-qpm" style="display:block;height:30px;margin-top:4px;width:80px;">
                        <option value="3">3</option>
                        <option value="4" selected>4</option>
                        <option value="5">5</option>
                        <option value="6">6</option>
                    </select>
                </label>
                <div style="padding-top:18px;display:flex;gap:6px;">
                    <button id="dyn-preview-btn" class="button" style="height:30px;background:#d97706;border-color:#d97706;color:#fff;">Generate Dynamic Preview</button>
                    <button id="dyn-build-btn" class="button" style="height:30px;background:#7c3aed;border-color:#7c3aed;color:#fff;">Build &amp; Save Dynamic Pack</button>
                </div>
            </div>
            <p id="dyn-status" style="margin:10px 0 0;font-size:12px;color:#666;"></p>
        </div>

        <!-- Dynamic results -->
        <div id="dyn-results" style="display:none;">
            <strong id="dyn-results-title" style="font-size:13px;display:block;margin-bottom:6px;"></strong>
            <span id="dyn-pack-saved" style="font-size:12px;color:#7c3aed;font-weight:600;display:none;margin-bottom:10px;"></span>
            <div id="dyn-assignments" style="margin-bottom:14px;"></div>
            <div id="dyn-questions"></div>
        </div>

        <script>
        (function($) {
            var NONCE   = '<?= esc_js( $nonce ) ?>';
            var MODULES = [];   // [{module_number, module_title}] loaded from QB

            function esc(str) {
                return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            var DIFF_COLORS = {
                easy:   { bg:'#dcfce7', border:'#86efac', text:'#166534' },
                medium: { bg:'#fef9c3', border:'#fde68a', text:'#854d0e' },
                hard:   { bg:'#fee2e2', border:'#fca5a5', text:'#991b1b' },
            };
            function diffBadge(d) {
                var c = DIFF_COLORS[d] || { bg:'#f3f4f6', border:'#d1d5db', text:'#374151' };
                return '<span style="background:'+c.bg+';border:1px solid '+c.border+';color:'+c.text+';border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;">'+esc(d)+'</span>';
            }

            // ── Load modules from QB ──────────────────────────────────────────
            function loadModules() {
                var level   = $('#sim-level').val();
                var subject = $('#sim-subject').val();
                if (!level || !subject) {
                    alert('Select Level and Subject first.');
                    return;
                }
                $('#sim-load-modules-btn').prop('disabled',true).text('Loading…');

                $.post(ajaxurl, {
                    action:  'knowly_trials_get_modules',
                    nonce:   NONCE,
                    level:   level,
                    subject: subject,
                    period:  $('#sim-period').val(),
                }, function(resp) {
                    $('#sim-load-modules-btn').prop('disabled',false).text('Load Modules');
                    if (!resp.success) { alert(resp.data || 'Failed to load modules.'); return; }

                    MODULES = resp.data.modules || [];

                    // Rebuild static pack module dropdown
                    var opts = '<option value="">All modules (General)</option>';
                    MODULES.forEach(function(m) {
                        opts += '<option value="'+m.module_number+'">'+esc(m.module_title)+'</option>';
                    });
                    $('#sim-module').html(opts);

                    // Rebuild dynamic preview checklist
                    if (!MODULES.length) {
                        $('#dyn-module-list').html('<span style="color:#b91c1c;">No active modules found for this scope.</span>');
                        return;
                    }
                    var checks = '';
                    MODULES.forEach(function(m) {
                        checks += '<label style="display:inline-flex;align-items:center;gap:5px;margin:4px 12px 4px 0;cursor:pointer;">';
                        checks += '<input type="checkbox" class="dyn-mod-cb" value="'+m.module_number+'" checked>';
                        checks += '<span>'+esc(m.module_title)+'</span></label>';
                    });
                    $('#dyn-module-list').html(checks);
                });
            }

            // ── Render question cards (shared by static + dynamic) ────────────
            function renderQuestionCards(questions, $target) {
                if (!questions.length) { $target.html('<p style="color:#888;">No questions returned.</p>'); return; }
                var html = '';
                questions.forEach(function(q, i) {
                    var c   = DIFF_COLORS[q.difficulty] || { bg:'#f9fafb', border:'#e5e7eb', text:'#374151' };
                    var ans = (q.correct_answer||'').toUpperCase();
                    html += '<div style="border:1px solid '+c.border+';border-radius:6px;padding:14px 16px;margin-bottom:10px;background:#fff;">';
                    html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
                    html += '<span style="font-size:11px;color:#888;">#'+(i+1)+'</span>';
                    html += diffBadge(q.difficulty);
                    if (q.module_title) html += '<span style="font-size:11px;color:#aaa;">'+esc(q.module_title)+'</span>';
                    html += '</div>';
                    html += '<p style="margin:0 0 10px;font-size:13px;font-weight:500;">'+esc(q.question)+'</p>';
                    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;margin-bottom:8px;">';
                    ['A','B','C','D'].forEach(function(k) {
                        var correct = k === ans;
                        var bg  = correct ? '#f0fdf4' : '#f9fafb';
                        var bdr = correct ? '2px solid #16a34a' : '1px solid #e5e7eb';
                        var lbl = correct ? '<strong style="color:#16a34a;">'+k+'. '+esc((q.options||{})[k]||'')+' ✓</strong>' : k+'. '+esc((q.options||{})[k]||'');
                        html += '<div style="padding:5px 8px;border-radius:4px;font-size:12px;background:'+bg+';border:'+bdr+';">'+lbl+'</div>';
                    });
                    html += '</div>';
                    if (q.explanation) html += '<p style="margin:6px 0 0;font-size:12px;color:#555;"><strong>Explanation:</strong> '+esc(q.explanation)+'</p>';
                    if (q.tip)         html += '<p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><strong>Tip:</strong> '+esc(q.tip)+'</p>';
                    html += '</div>';
                });
                $target.html(html);
            }

            // ── Static pack build / preview ───────────────────────────────────
            function runSim(preview) {
                var $pb = $('#sim-preview-btn'), $bb = $('#sim-build-btn');
                $pb.prop('disabled',true); $bb.prop('disabled',true);
                $('#sim-status').text(preview ? 'Fetching preview…' : 'Building pack…').css('color','#666');
                $('#sim-pack-saved').hide();

                var modVal = $('#sim-module').val();
                $.post(ajaxurl, {
                    action:     preview ? 'knowly_trials_sim_preview' : 'knowly_trials_build_pack',
                    nonce:      NONCE,
                    level:      $('#sim-level').val(),
                    period:     $('#sim-period').val(),
                    subject:    $('#sim-subject').val(),
                    module:     modVal,
                    pack_type:  $('#sim-pack-type').val(),
                    difficulty: $('#sim-difficulty').val(),
                }, function(resp) {
                    $pb.prop('disabled',false); $bb.prop('disabled',false);
                    if (!resp.success) {
                        $('#sim-status').text('Error: '+(resp.data||'Request failed.')).css('color','#dc2626');
                        return;
                    }
                    var d   = resp.data;
                    var lbl = $('#sim-level').val()+' / '+(($('#sim-period').val())||'Capstone')+' / '+$('#sim-subject option:selected').text();
                    lbl    += modVal ? ' / '+$('#sim-module option:selected').text() : '';
                    lbl    += ' — '+$('#sim-difficulty option:selected').text();
                    $('#sim-results-title').text(lbl);
                    if (!preview && d.pack_id) $('#sim-pack-saved').text('✓ Pack saved — ID: '+d.pack_id).show();
                    renderQuestionCards(d.questions||[], $('#sim-questions'));
                    $('#sim-results').show();
                    $('#sim-status').text(preview ? d.question_count+' questions previewed (not saved).' : d.question_count+' questions locked into pack.').css('color', preview?'#666':'#16a34a');
                }).fail(function() {
                    $pb.prop('disabled',false); $bb.prop('disabled',false);
                    $('#sim-status').text('Request failed — check Railway connectivity.').css('color','#dc2626');
                });
            }

            // ── Dynamic preview / build ───────────────────────────────────────
            function runDynamic(save) {
                var selected = $('.dyn-mod-cb:checked').map(function(){ return parseInt($(this).val()); }).get();
                if (!selected.length) { alert('Select at least one module.'); return; }
                var $prevBtn  = $('#dyn-preview-btn');
                var $buildBtn = $('#dyn-build-btn');
                $prevBtn.prop('disabled',true); $buildBtn.prop('disabled',true);
                $('#dyn-status').text(save ? 'Building dynamic pack…' : 'Generating preview…').css('color','#666');
                $('#dyn-pack-saved').hide();

                $.post(ajaxurl, {
                    action:  save ? 'knowly_trials_dynamic_build' : 'knowly_dynamic_preview',
                    nonce:   NONCE,
                    level:   $('#sim-level').val(),
                    period:  $('#sim-period').val(),
                    subject: $('#sim-subject').val(),
                    modules: selected,
                    qpm:     $('#dyn-qpm').val(),
                }, function(resp) {
                    $prevBtn.prop('disabled',false); $buildBtn.prop('disabled',false);
                    if (!resp.success) {
                        $('#dyn-status').text(resp.data||'Error.').css('color','#dc2626');
                        return;
                    }
                    var d = resp.data;
                    $('#dyn-results-title').text(
                        (save ? 'Dynamic Pack Built' : 'Dynamic Preview') +
                        ' — '+d.question_count+' questions across '+d.module_assignments.length+' modules'
                    );

                    if (save && d.pack_id) {
                        $('#dyn-pack-saved').text('✓ Pack saved — ID: '+d.pack_id+' (Seq #'+d.sequence_number+')').css('display','block');
                    }

                    // Module assignment summary badges
                    var aHtml = '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">';
                    (d.module_assignments||[]).forEach(function(a) {
                        var title = 'Module '+a.module_number;
                        MODULES.forEach(function(m){ if(m.module_number===a.module_number) title=m.module_title; });
                        aHtml += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:12px;">';
                        aHtml += '<span style="font-weight:600;">'+esc(title)+'</span>&nbsp;';
                        aHtml += diffBadge(a.difficulty_drawn);
                        aHtml += '&nbsp;<span style="color:#6b7280;">'+a.questions_drawn+' Qs</span>';
                        aHtml += '</div>';
                    });
                    aHtml += '</div>';
                    $('#dyn-assignments').html(aHtml);

                    renderQuestionCards(d.questions||[], $('#dyn-questions'));
                    $('#dyn-results').show();
                    $('#dyn-status')
                        .text(save ? d.question_count+' questions locked into dynamic pack.' : d.question_count+' questions previewed (nothing saved).')
                        .css('color', save ? '#7c3aed' : '#666');
                }).fail(function() {
                    $prevBtn.prop('disabled',false); $buildBtn.prop('disabled',false);
                    $('#dyn-status').text('Request failed.').css('color','#dc2626');
                });
            }

            // ── Bindings ──────────────────────────────────────────────────────
            $('#sim-load-modules-btn').on('click', loadModules);
            $('#sim-preview-btn').on('click', function() { runSim(true); });
            $('#sim-build-btn').on('click', function() {
                if (!confirm('Build and save this pack? Questions will be locked and cannot be reused.')) return;
                runSim(false);
            });
            $('#dyn-preview-btn').on('click', function() { runDynamic(false); });
            $('#dyn-build-btn').on('click', function() {
                if (!confirm('Build and save this dynamic pack? Questions will be locked and cannot be reused.')) return;
                runDynamic(true);
            });

        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // AJAX: Overview
    // =========================================================================

    public static function ajax_overview(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $tbl   = $wpdb->prefix . 'knowly_exam_sessions';
        $today = current_time( 'Y-m-d' ) . ' 00:00:00';

        $sessions_today  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE started_at >= '{$today}'" );
        $active_sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE state = 'active'" );
        $total_sessions  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
        $qb_sessions     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE package_id LIKE 'qb-%'" );

        // QB bank fill per subject (std_4/term_1 as representative snapshot)
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $subjects   = [ 'math', 'english', 'science', 'social_studies' ];
        $qb_stats   = [];

        if ( $endpoint && $server_key ) {
            foreach ( $subjects as $subj ) {
                $resp = wp_remote_get(
                    $endpoint . '/api/v1/question-bank/list?' . http_build_query( [
                        'level'   => 'std_4',
                        'subject' => $subj,
                        'period'  => 'term_1',
                    ] ),
                    [ 'timeout' => 8, 'headers' => [ 'X-AEP-Server-Key' => $server_key ] ]
                );

                if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                    $data   = json_decode( wp_remote_retrieve_body( $resp ), true );
                    $slots  = $data['slots'] ?? [];
                    $seeded = count( array_filter( $slots, fn( $s ) => (int) ( $s['active_count'] ?? 0 ) > 0 ) );
                    $qb_stats[ $subj ] = [ 'total' => count( $slots ), 'seeded' => $seeded ];
                }
            }
        }

        // 5 most recent sessions for the overview preview
        $recent_rows = $wpdb->get_results(
            "SELECT session_id, child_id, package_id, subject, difficulty, state, percentage, time_taken_seconds, started_at
             FROM {$tbl} ORDER BY started_at DESC LIMIT 5",
            ARRAY_A
        );

        $recent = [];
        foreach ( $recent_rows ?: [] as $row ) {
            $user     = get_userdata( (int) $row['child_id'] );
            $recent[] = [
                'child_name'         => $user ? $user->display_name : 'User #' . $row['child_id'],
                'subject'            => $row['subject'],
                'difficulty'         => $row['difficulty'],
                'state'              => $row['state'],
                'source'             => str_starts_with( $row['package_id'], 'qb-' ) ? 'question_bank' : 'pool',
                'percentage'         => $row['percentage'],
                'time_taken_seconds' => $row['time_taken_seconds'],
                'started_at'         => $row['started_at'],
            ];
        }

        wp_send_json_success( [
            'sessions_today'  => $sessions_today,
            'active_sessions' => $active_sessions,
            'total_sessions'  => $total_sessions,
            'qb_sessions'     => $qb_sessions,
            'qb_stats'        => $qb_stats,
            'recent'          => $recent,
        ] );
    }

    // =========================================================================
    // AJAX: QB Slot Board
    // =========================================================================

    public static function ajax_qb_slots(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level   = sanitize_text_field( $_POST['level']   ?? 'std_4' );
        $subject = sanitize_text_field( $_POST['subject'] ?? 'math' );
        $period  = sanitize_text_field( $_POST['period']  ?? '' );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
            return;
        }

        $params = [ 'curriculum' => 'tt_primary', 'level' => $level, 'subject' => $subject ];
        if ( $period ) $params['period'] = $period;

        $resp = wp_remote_get(
            $endpoint . '/api/v1/question-bank/list?' . http_build_query( $params ),
            [ 'timeout' => 15, 'headers' => [ 'X-AEP-Server-Key' => $server_key ] ]
        );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => 'Railway error: ' . $resp->get_error_message() ] );
            return;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 ) {
            wp_send_json_error( [ 'message' => $data['error'] ?? "HTTP {$code}" ] );
            return;
        }

        wp_send_json_success( $data );
    }

    // =========================================================================
    // AJAX: Sessions
    // =========================================================================

    public static function ajax_sessions(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $tbl      = $wpdb->prefix . 'knowly_exam_sessions';
        $per_page = 30;
        $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $wheres = [ '1=1' ];
        $params = [];

        if ( ! empty( $_POST['subject'] ) ) {
            $wheres[] = 'subject = %s';
            $params[] = sanitize_text_field( $_POST['subject'] );
        }
        if ( ! empty( $_POST['state'] ) ) {
            $wheres[] = 'state = %s';
            $params[] = sanitize_text_field( $_POST['state'] );
        }
        if ( ! empty( $_POST['source'] ) ) {
            $src      = sanitize_text_field( $_POST['source'] );
            $wheres[] = $src === 'qb' ? "package_id LIKE 'qb-%'" : "package_id NOT LIKE 'qb-%'";
        }

        $where_sql = implode( ' AND ', $wheres );

        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE {$where_sql}", ...$params ) )
            : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE {$where_sql}" );

        $q_params = array_merge( $params, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT session_id, child_id, package_id, subject, level, period, difficulty, state, percentage, time_taken_seconds, started_at FROM {$tbl} WHERE {$where_sql} ORDER BY started_at DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$q_params ), ARRAY_A );

        $sessions = [];
        foreach ( $rows ?: [] as $row ) {
            $user       = get_userdata( (int) $row['child_id'] );
            $sessions[] = [
                'child_name'         => $user ? $user->display_name : 'User #' . $row['child_id'],
                'subject'            => $row['subject'],
                'level'              => $row['level'],
                'period'             => $row['period'],
                'difficulty'         => $row['difficulty'],
                'state'              => $row['state'],
                'source'             => str_starts_with( $row['package_id'], 'qb-' ) ? 'question_bank' : 'pool',
                'percentage'         => $row['percentage'],
                'time_taken_seconds' => $row['time_taken_seconds'],
                'started_at'         => $row['started_at'],
            ];
        }

        wp_send_json_success( [
            'sessions' => $sessions,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
        ] );
    }

    // =========================================================================
    // AJAX: Health Checks
    // =========================================================================

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $checks     = [];
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        // 1. Railway reachable
        if ( ! $endpoint ) {
            $checks[] = [ 'label' => 'Railway endpoint', 'status' => 'fail', 'detail' => 'Not configured in Settings.' ];
        } else {
            $resp = wp_remote_get( $endpoint . '/api/v1/health', [ 'timeout' => 8 ] );
            $ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
            $checks[] = [ 'label' => 'Railway reachable', 'status' => $ok ? 'pass' : 'fail',
                'detail' => $ok ? $endpoint : ( is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $resp ) ) ];
        }

        // 2. Server key
        $checks[] = [ 'label' => 'Server key configured', 'status' => $server_key ? 'pass' : 'warn',
            'detail' => $server_key ? 'AEP_SERVER_KEY is set.' : 'Missing — QB generation and assembly unavailable.' ];

        // 3. QB bank: math/std_4/term_1 slot health
        if ( $endpoint && $server_key ) {
            $resp = wp_remote_get(
                $endpoint . '/api/v1/question-bank/list?' . http_build_query( [ 'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1' ] ),
                [ 'timeout' => 10, 'headers' => [ 'X-AEP-Server-Key' => $server_key ] ]
            );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $data   = json_decode( wp_remote_retrieve_body( $resp ), true );
                $slots  = $data['slots'] ?? [];
                $above  = count( array_filter( $slots, fn( $s ) => (int) ( $s['active_count'] ?? 0 ) >= 15 ) );
                $total  = count( $slots );
                $checks[] = [ 'label' => 'QB bank — math / std_4 / term_1',
                    'status' => $above === $total ? 'pass' : ( $above > 0 ? 'warn' : 'fail' ),
                    'detail' => "{$above} of {$total} slots above low watermark (≥15 questions). Fill remaining via Question Bank admin." ];
            } else {
                $checks[] = [ 'label' => 'QB bank reachable', 'status' => 'fail', 'detail' => 'Could not reach /question-bank/list.' ];
            }
        }

        // 4. trial_packs table + watermark
        if ( $endpoint && $server_key ) {
            $wm_resp = wp_remote_get(
                $endpoint . '/api/v1/trial-packs/watermark?' . http_build_query( [ 'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1' ] ),
                [ 'timeout' => 10, 'headers' => [ 'X-AEP-Server-Key' => $server_key ] ]
            );
            if ( ! is_wp_error( $wm_resp ) && wp_remote_retrieve_response_code( $wm_resp ) === 200 ) {
                $wm      = json_decode( wp_remote_retrieve_body( $wm_resp ), true );
                $summary = $wm['summary'] ?? [];
                $critical = (int) ( $summary['critical'] ?? 0 );
                $low      = (int) ( $summary['low']      ?? 0 );
                $healthy  = (int) ( $summary['healthy']  ?? 0 );
                $total    = (int) ( $summary['total']    ?? 0 );
                $status   = $critical > 0 ? 'fail' : ( $low > 0 ? 'warn' : 'pass' );
                $checks[] = [ 'label' => 'QB watermark — math / std_4 / term_1',
                    'status' => $status,
                    'detail' => "{$healthy}/{$total} slots healthy · {$low} low · {$critical} critical (< 6 unassigned questions)." ];
            } else {
                $checks[] = [ 'label' => 'QB watermark', 'status' => 'warn', 'detail' => 'Could not reach /trial-packs/watermark — deploy Railway first.' ];
            }
        }

        // 5. exam_sessions table
        $sess_tbl = $wpdb->prefix . 'knowly_exam_sessions';
        $etbl     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sess_tbl ) );
        $checks[] = [ 'label' => 'exam_sessions table', 'status' => $etbl === $sess_tbl ? 'pass' : 'fail',
            'detail' => $etbl === $sess_tbl ? 'Table exists.' : 'Missing — run plugin activation.' ];

        // 6. Session activity last 7 days
        $recent = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess_tbl} WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
        $qb_pct = 0;
        if ( $recent > 0 ) {
            $qb_recent = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess_tbl} WHERE package_id LIKE 'qb-%' AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
            $qb_pct    = round( $qb_recent / $recent * 100 );
        }
        $checks[] = [ 'label' => 'Session activity (last 7 days)', 'status' => $recent > 0 ? 'pass' : 'warn',
            'detail' => $recent > 0 ? "{$recent} session(s) — {$qb_pct}% served from QB v2." : 'No sessions in the last 7 days.' ];

        wp_send_json_success( [ 'checks' => $checks ] );
    }

    // =========================================================================
    // AJAX: Simulations — Preview Pack (no save)
    // =========================================================================

    public static function ajax_sim_preview(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $resp = self::railway_pack_request( true );
        if ( isset( $resp['error'] ) ) {
            wp_send_json_error( $resp['error'] );
            return;
        }
        wp_send_json_success( $resp );
    }

    // =========================================================================
    // AJAX: Simulations — Build & Save Pack
    // =========================================================================

    public static function ajax_build_pack(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $resp = self::railway_pack_request( false );
        if ( isset( $resp['error'] ) ) {
            wp_send_json_error( $resp['error'] );
            return;
        }
        wp_send_json_success( $resp );
    }

    // ── Shared helper: call /api/v1/trial-packs/build ────────────────────────

    // =========================================================================
    // Pack Library AJAX
    // =========================================================================

    public static function ajax_packs_list(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $args = [
            'curriculum' => 'tt_primary',
            'per_page'   => 25,
            'page'       => max( 1, (int) ( $_POST['page'] ?? 1 ) ),
        ];
        foreach ( [ 'level', 'subject', 'period', 'difficulty', 'status' ] as $key ) {
            $val = sanitize_text_field( $_POST[ $key ] ?? '' );
            if ( $val !== '' ) $args[ $key ] = $val;
        }

        $url  = add_query_arg( $args, $endpoint . '/api/v1/trial-packs/list' );
        $resp = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    public static function ajax_pack_detail(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $pack_id    = sanitize_text_field( $_POST['pack_id'] ?? '' );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $pack_id ) { wp_send_json_error( 'pack_id required.' ); return; }
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $resp = wp_remote_get( $endpoint . '/api/v1/trial-packs/' . rawurlencode( $pack_id ), [
            'timeout' => 20,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    public static function ajax_pack_archive(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $pack_id           = sanitize_text_field( $_POST['pack_id']           ?? '' );
        $release_questions = ( ( $_POST['release_questions'] ?? '0' ) === '1' );
        $endpoint          = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key        = get_option( 'knowly_railway_server_key', '' );

        if ( ! $pack_id ) { wp_send_json_error( 'pack_id required.' ); return; }
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $resp = wp_remote_request( $endpoint . '/api/v1/trial-packs/' . rawurlencode( $pack_id ), [
            'method'  => 'PATCH',
            'timeout' => 30,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( [
                'status'            => 'archived',
                'release_questions' => $release_questions,
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    // =========================================================================
    // Curriculum Helper — extracts levels / periods / subjects from the
    // stored curriculum config so dropdowns stay in sync with curriculum.php
    // =========================================================================

    private static function curriculum_parts(): array {
        $cfg      = get_option( 'knowly_curriculum_subjects', [] );
        $levels   = [];
        $periods  = [];
        $subjects = [];

        foreach ( $cfg as $curriculum ) {
            foreach ( $curriculum['levels']   ?? [] as $l ) $levels[ $l['value'] ]   = $l['label'];
            foreach ( $curriculum['periods']  ?? [] as $p ) $periods[ $p['value'] ]  = $p['label'];
            foreach ( $curriculum['subjects'] ?? [] as $s ) $subjects[ $s['value'] ] = $s['label'];
        }

        return compact( 'levels', 'periods', 'subjects' );
    }

    // =========================================================================
    // AJAX: Reactivate Pack
    // =========================================================================

    public static function ajax_pack_reactivate(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $pack_id    = sanitize_text_field( $_POST['pack_id'] ?? '' );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $pack_id ) { wp_send_json_error( 'pack_id required.' ); return; }
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $resp = wp_remote_request( $endpoint . '/api/v1/trial-packs/' . rawurlencode( $pack_id ), [
            'method'  => 'PATCH',
            'timeout' => 20,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'status' => 'active' ] ),
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    // =========================================================================
    // AJAX: Disband Pack (DELETE — releases questions + removes pack row)
    // =========================================================================

    public static function ajax_pack_disband(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $pack_id    = sanitize_text_field( $_POST['pack_id'] ?? '' );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $pack_id ) { wp_send_json_error( 'pack_id required.' ); return; }
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $resp = wp_remote_request( $endpoint . '/api/v1/trial-packs/' . rawurlencode( $pack_id ), [
            'method'  => 'DELETE',
            'timeout' => 30,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    // =========================================================================
    // AJAX: Get Modules (for dynamic dropdowns)
    // =========================================================================

    public static function ajax_get_modules(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $level      = sanitize_text_field( $_POST['level']   ?? '' );
        $subject    = sanitize_text_field( $_POST['subject'] ?? '' );
        $period     = sanitize_text_field( $_POST['period']  ?? '' );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $level || ! $subject ) { wp_send_json_error( 'level and subject required.' ); return; }
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $args = [ 'curriculum' => 'tt_primary', 'level' => $level, 'subject' => $subject ];
        if ( $period ) $args['period'] = $period;

        $url  = add_query_arg( $args, $endpoint . '/api/v1/question-bank/modules' );
        $resp = wp_remote_get( $url, [ 'timeout' => 15, 'headers' => [ 'X-AEP-Server-Key' => $server_key ] ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    // =========================================================================
    // AJAX: Dynamic Preview
    // =========================================================================

    public static function ajax_dynamic_build(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $modules_raw = $_POST['modules'] ?? [];
        $modules     = array_map( 'intval', is_array( $modules_raw ) ? $modules_raw : [] );
        if ( empty( $modules ) ) { wp_send_json_error( 'At least one module must be selected.' ); return; }

        $body = [
            'curriculum'           => 'tt_primary',
            'level'                => sanitize_text_field( $_POST['level']   ?? 'std_4' ),
            'period'               => sanitize_text_field( $_POST['period']  ?? '' ) ?: null,
            'subject'              => sanitize_text_field( $_POST['subject'] ?? 'math' ),
            'modules'              => $modules,
            'questions_per_module' => max( 1, min( 10, (int) ( $_POST['qpm'] ?? 4 ) ) ),
        ];

        $resp = wp_remote_post( $endpoint . '/api/v1/trial-packs/dynamic-build', [
            'timeout' => 45,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    public static function ajax_dynamic_preview(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $modules_raw = $_POST['modules'] ?? [];
        $modules     = array_map( 'intval', is_array( $modules_raw ) ? $modules_raw : [] );
        if ( empty( $modules ) ) { wp_send_json_error( 'At least one module must be selected.' ); return; }

        $body = [
            'curriculum'           => 'tt_primary',
            'level'                => sanitize_text_field( $_POST['level']   ?? 'std_4' ),
            'period'               => sanitize_text_field( $_POST['period']  ?? '' ) ?: null,
            'subject'              => sanitize_text_field( $_POST['subject'] ?? 'math' ),
            'modules'              => $modules,
            'questions_per_module' => max( 1, min( 10, (int) ( $_POST['qpm'] ?? 4 ) ) ),
        ];

        $resp = wp_remote_post( $endpoint . '/api/v1/trial-packs/dynamic-preview', [
            'timeout' => 30,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    private static function railway_pack_request( bool $preview ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) return [ 'error' => 'Railway endpoint not configured.' ];

        $module = sanitize_text_field( $_POST['module'] ?? '' );
        $body   = [
            'curriculum' => 'tt_primary',
            'level'      => sanitize_text_field( $_POST['level']      ?? 'std_4' ),
            'period'     => sanitize_text_field( $_POST['period']     ?? '' ) ?: null,
            'subject'    => sanitize_text_field( $_POST['subject']    ?? 'math' ),
            'pack_type'  => sanitize_text_field( $_POST['pack_type']  ?? 'topic' ),
            'difficulty' => sanitize_text_field( $_POST['difficulty'] ?? 'easy' ),
            'preview'    => $preview,
        ];
        if ( $module !== '' ) $body['module_number'] = (int) $module;

        $resp = wp_remote_post( $endpoint . '/api/v1/trial-packs/build', [
            'timeout' => 30,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) return [ 'error' => $resp->get_error_message() ];

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) {
            return [ 'error' => $parsed['error'] ?? "HTTP {$code}" ];
        }

        return $parsed ?: [ 'error' => 'Empty response from Railway.' ];
    }
}
