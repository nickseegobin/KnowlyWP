<?php
/**
 * Knowly_Admin_Analytics — Analytics admin panel.
 *
 * Tabs: Overview | Class Analytics | Student Analytics | Health Checks | Unit Tests
 *
 * Class and Student tabs query Railway directly (server-key auth) and render
 * results inline — no page reload required.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Analytics {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_analytics_health',  [ __CLASS__, 'ajax_health' ] );
        add_action( 'wp_ajax_knowly_analytics_class',   [ __CLASS__, 'ajax_class_analytics' ] );
        add_action( 'wp_ajax_knowly_analytics_student', [ __CLASS__, 'ajax_student_analytics' ] );
        add_action( 'wp_ajax_knowly_analytics_members', [ __CLASS__, 'ajax_class_members' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab  = sanitize_key( $_GET['tab'] ?? 'overview' );
        $tabs = [
            'overview' => 'Overview',
            'class'    => 'Class Analytics',
            'student'  => 'Student Analytics',
            'health'   => 'Health Checks',
            'tests'    => 'Unit Tests',
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
                'class'    => self::render_class_tab(),
                'student'  => self::render_student_tab(),
                'health'   => self::render_health(),
                'tests'    => self::render_tests(),
                default    => self::render_overview(),
            };
            ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // TAB: OVERVIEW
    // =========================================================================

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
        <div class="knowly-stat-grid">
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div><div class="knowly-stat-label">Active Classes</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $member_count ) ?></div><div class="knowly-stat-label">Enrolled Students</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $task_count ) ?></div><div class="knowly-stat-label">Active Tasks</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= $railway_ok ? '<span style="color:#00a32a">●</span>' : '<span style="color:#d63638">●</span>' ?></div><div class="knowly-stat-label">Railway</div></div>
        </div>
        <h3 style="margin-top:24px;">Active Classes</h3>
        <?php if ( empty( $top_classes ) ) : ?>
        <p style="color:#888;">No active classes.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr><th style="width:60px">ID</th><th>Name</th><th>Level</th><th style="width:100px">Members</th><th>Class Analytics</th><th>Student Analytics</th></tr></thead>
            <tbody>
            <?php foreach ( $top_classes as $class ) : ?>
            <tr>
                <td><?= esc_html( $class->id ) ?></td>
                <td><?= esc_html( $class->name ) ?></td>
                <td><?= esc_html( $class->level ) ?></td>
                <td><?= esc_html( $class->member_count ) ?></td>
                <td><a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=class&class_id=' . $class->id ) ) ?>" class="button button-small">View Class →</a></td>
                <td><a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=student&class_id=' . $class->id ) ) ?>" class="button button-small">View Students →</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    // =========================================================================
    // TAB: CLASS ANALYTICS
    // =========================================================================

    private static function render_class_tab(): void {
        global $wpdb;
        $nonce    = wp_create_nonce( 'knowly_admin_nonce' );
        $classes  = $wpdb->get_results(
            "SELECT c.id, c.name, c.level, COUNT(m.id) AS member_count
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->prefix}knowly_class_members m ON m.class_id = c.id AND m.status = 'active'
             WHERE c.status = 'active' GROUP BY c.id ORDER BY c.name ASC"
        );
        $tax      = get_option( 'knowly_curriculum_subjects', [] );
        $selected = (int) ( $_GET['class_id'] ?? 0 );
        ?>
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px;">
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Class</label>
                <select id="ca-class" style="min-width:200px;">
                    <option value="">— Select class —</option>
                    <?php foreach ( $classes as $c ) : ?>
                    <option value="<?= esc_attr( $c->id ) ?>" <?= selected( $selected, $c->id, false ) ?>>
                        <?= esc_html( $c->name ) ?> (<?= esc_html( $c->member_count ) ?> students)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Period</label>
                <select id="ca-period" style="min-width:140px;">
                    <option value="">All Periods</option>
                    <?php foreach ( $tax as $cfg ) foreach ( $cfg['periods'] ?? [] as $p ) : ?>
                    <option value="<?= esc_attr( $p['value'] ) ?>"><?= esc_html( $p['label'] ) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Subject</label>
                <select id="ca-subject" style="min-width:160px;">
                    <option value="">All Subjects</option>
                    <?php foreach ( $tax as $cfg ) foreach ( $cfg['subjects'] ?? [] as $s ) : ?>
                    <option value="<?= esc_attr( $s['value'] ) ?>"><?= esc_html( $s['label'] ) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button id="ca-load" class="button button-primary">Load Analytics</button>
            </div>
        </div>

        <div id="ca-status" style="color:#888;font-style:italic;margin-bottom:12px;"></div>
        <div id="ca-results"></div>

        <script>
        (function($) {
            var nonce = <?= wp_json_encode( $nonce ) ?>;
            var preload = <?= wp_json_encode( $selected ) ?>;

            function scoreBar(pct) {
                if (pct === null || pct === undefined) return '<span style="color:#999">—</span>';
                var col = pct >= 70 ? '#16a34a' : (pct >= 50 ? '#d97706' : '#dc2626');
                return '<span style="font-weight:600;color:' + col + '">' + pct + '%</span>'
                     + '<span style="display:inline-block;width:60px;height:8px;background:#e5e7eb;border-radius:4px;margin-left:6px;vertical-align:middle;">'
                     + '<span style="display:inline-block;width:' + pct + '%;height:100%;background:' + col + ';border-radius:4px;"></span></span>';
            }

            function badge(label, type) {
                var bg = type === 'ok' ? '#dcfce7' : (type === 'warn' ? '#fef9c3' : '#fee2e2');
                var col = type === 'ok' ? '#15803d' : (type === 'warn' ? '#854d0e' : '#991b1b');
                return '<span style="background:' + bg + ';color:' + col + ';padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">' + label + '</span>';
            }

            function renderClass(d) {
                var html = '';

                // ── Stat cards ───────────────────────────────────────────────
                html += '<div class="knowly-stat-grid" style="margin-bottom:24px;">'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.student_count + '</div><div class="knowly-stat-label">Students</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.class_avg_score !== null ? d.class_avg_score + '%' : '—') + '</div><div class="knowly-stat-label">Class Avg Score</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.total_trials + '</div><div class="knowly-stat-label">Total Trials</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.total_quests + '</div><div class="knowly-stat-label">Total Quests</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.avg_engagement_rate || 0) + '</div><div class="knowly-stat-label">Avg Trials/Week</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number" style="color:' + (d.at_risk_count > 0 ? '#dc2626' : '#16a34a') + '">' + d.at_risk_count + '</div><div class="knowly-stat-label">At-Risk Students</div></div>'
                     + '</div>';

                // ── Student roster ───────────────────────────────────────────
                html += '<h3>Student Roster</h3>';
                html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                     + '<thead><tr><th>Student</th><th>Level</th><th>Avg Score</th><th>Trials</th><th>Quests</th><th>Trials This Week</th><th>Last Active</th><th>Status</th></tr></thead><tbody>';

                (d.students || []).forEach(function(s) {
                    var lastActive = s.last_active ? new Date(s.last_active).toLocaleDateString('en-TT', {day:'numeric',month:'short',year:'numeric'}) : '—';
                    html += '<tr>'
                         + '<td><strong>' + (s.nickname || 'Student ' + s.user_id) + '</strong></td>'
                         + '<td>' + (s.level || '—') + '</td>'
                         + '<td>' + scoreBar(s.avg_score) + '</td>'
                         + '<td>' + s.trial_count + '</td>'
                         + '<td>' + s.quest_count + '</td>'
                         + '<td>' + (s.weekly_trials || 0) + '</td>'
                         + '<td style="font-size:12px;color:#666;">' + lastActive + '</td>'
                         + '<td>' + (s.at_risk ? badge('At Risk', 'err') : badge('On Track', 'ok')) + '</td>'
                         + '</tr>';
                });
                html += '</tbody></table>';

                // ── Topic heatmap ────────────────────────────────────────────
                if (d.topic_heatmap && d.topic_heatmap.length) {
                    html += '<h3>Topic Heatmap <span style="font-size:12px;font-weight:400;color:#666;">— correct rate across all students</span></h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Topic</th><th>Subject</th><th>Correct Rate</th><th>Questions Attempted</th><th>Students</th><th></th></tr></thead><tbody>';
                    d.topic_heatmap.forEach(function(t) {
                        html += '<tr>'
                             + '<td><strong>' + t.topic + '</strong></td>'
                             + '<td>' + t.subject + '</td>'
                             + '<td>' + scoreBar(t.correct_rate) + '</td>'
                             + '<td>' + t.total_questions + '</td>'
                             + '<td>' + t.student_count + '</td>'
                             + '<td>' + (t.is_strength ? badge('Strength', 'ok') : (t.is_weakness ? badge('Needs Work', 'err') : '')) + '</td>'
                             + '</tr>';
                    });
                    html += '</tbody></table>';
                }

                // ── Strengths & weaknesses ───────────────────────────────────
                if (d.strengths && d.strengths.length || d.weaknesses && d.weaknesses.length) {
                    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">';

                    html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:16px;">'
                         + '<h4 style="margin:0 0 10px;color:#15803d;">Class Strengths</h4>';
                    if (d.strengths.length) {
                        d.strengths.forEach(function(t) {
                            html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #dcfce7;">'
                                 + '<span>' + t.topic + ' <span style="color:#666;font-size:11px;">(' + t.subject + ')</span></span>'
                                 + '<strong style="color:#15803d;">' + t.correct_rate + '%</strong></div>';
                        });
                    } else { html += '<p style="color:#666;margin:0;">No strong topics yet.</p>'; }
                    html += '</div>';

                    html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:16px;">'
                         + '<h4 style="margin:0 0 10px;color:#991b1b;">Needs Attention</h4>';
                    if (d.weaknesses.length) {
                        d.weaknesses.forEach(function(t) {
                            html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #fee2e2;">'
                                 + '<span>' + t.topic + ' <span style="color:#666;font-size:11px;">(' + t.subject + ')</span></span>'
                                 + '<strong style="color:#991b1b;">' + t.correct_rate + '%</strong></div>';
                        });
                    } else { html += '<p style="color:#666;margin:0;">No weak topics identified.</p>'; }
                    html += '</div>';

                    html += '</div>';
                }

                $('#ca-results').html(html);
            }

            function load() {
                var classId = $('#ca-class').val();
                if (!classId) { $('#ca-status').text('Please select a class.'); return; }
                $('#ca-status').text('Loading…');
                $('#ca-load').prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'knowly_analytics_class',
                    nonce:  nonce,
                    class_id: classId,
                    period:   $('#ca-period').val(),
                    subject:  $('#ca-subject').val(),
                }, function(res) {
                    $('#ca-load').prop('disabled', false);
                    if (!res.success) { $('#ca-status').text('Error: ' + (res.data?.message || 'Unknown error')); return; }
                    $('#ca-status').text('');
                    renderClass(res.data);
                }).fail(function() {
                    $('#ca-load').prop('disabled', false);
                    $('#ca-status').text('Request failed. Check Railway is running.');
                });
            }

            $('#ca-load').on('click', load);

            // Auto-load if class pre-selected from Overview link
            if (preload) {
                $('#ca-class').val(preload);
                load();
            }
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // TAB: STUDENT ANALYTICS
    // =========================================================================

    private static function render_student_tab(): void {
        global $wpdb;
        $nonce      = wp_create_nonce( 'knowly_admin_nonce' );
        $classes    = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}knowly_classes WHERE status = 'active' ORDER BY name ASC"
        );
        $tax        = get_option( 'knowly_curriculum_subjects', [] );
        $sel_class  = (int) ( $_GET['class_id'] ?? 0 );
        ?>
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px;">
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Class</label>
                <select id="sa-class" style="min-width:200px;">
                    <option value="">— Select class —</option>
                    <?php foreach ( $classes as $c ) : ?>
                    <option value="<?= esc_attr( $c->id ) ?>" <?= selected( $sel_class, $c->id, false ) ?>>
                        <?= esc_html( $c->name ) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Student</label>
                <select id="sa-student" style="min-width:200px;">
                    <option value="">— Select student —</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Period</label>
                <select id="sa-period" style="min-width:140px;">
                    <option value="">All Periods</option>
                    <?php foreach ( $tax as $cfg ) foreach ( $cfg['periods'] ?? [] as $p ) : ?>
                    <option value="<?= esc_attr( $p['value'] ) ?>"><?= esc_html( $p['label'] ) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Subject</label>
                <select id="sa-subject" style="min-width:160px;">
                    <option value="">All Subjects</option>
                    <?php foreach ( $tax as $cfg ) foreach ( $cfg['subjects'] ?? [] as $s ) : ?>
                    <option value="<?= esc_attr( $s['value'] ) ?>"><?= esc_html( $s['label'] ) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button id="sa-load" class="button button-primary">Load Analytics</button>
            </div>
        </div>

        <div id="sa-status" style="color:#888;font-style:italic;margin-bottom:12px;"></div>
        <div id="sa-results"></div>

        <script>
        (function($) {
            var nonce    = <?= wp_json_encode( $nonce ) ?>;
            var preClass = <?= wp_json_encode( $sel_class ) ?>;

            function scoreBar(pct) {
                if (pct === null || pct === undefined) return '<span style="color:#999">—</span>';
                var col = pct >= 70 ? '#16a34a' : (pct >= 50 ? '#d97706' : '#dc2626');
                return '<span style="font-weight:600;color:' + col + '">' + pct + '%</span>'
                     + '<span style="display:inline-block;width:60px;height:8px;background:#e5e7eb;border-radius:4px;margin-left:6px;vertical-align:middle;">'
                     + '<span style="display:inline-block;width:' + pct + '%;height:100%;background:' + col + ';border-radius:4px;"></span></span>';
            }

            function badge(label, type) {
                var bg  = type === 'ok' ? '#dcfce7' : (type === 'warn' ? '#fef9c3' : '#fee2e2');
                var col = type === 'ok' ? '#15803d' : (type === 'warn' ? '#854d0e' : '#991b1b');
                return '<span style="background:' + bg + ';color:' + col + ';padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">' + label + '</span>';
            }

            // Load student list when class changes
            function loadStudents(classId, preselectId) {
                $('#sa-student').html('<option value="">Loading…</option>').prop('disabled', true);
                $.post(ajaxurl, { action: 'knowly_analytics_members', nonce: nonce, class_id: classId }, function(res) {
                    $('#sa-student').prop('disabled', false);
                    if (!res.success || !res.data.length) {
                        $('#sa-student').html('<option value="">No students in this class</option>');
                        return;
                    }
                    var opts = '<option value="">— Select student —</option>';
                    res.data.forEach(function(s) {
                        opts += '<option value="' + s.child_id + '"' + (preselectId == s.child_id ? ' selected' : '') + '>'
                             + (s.nickname || 'Student ' + s.child_id) + '</option>';
                    });
                    $('#sa-student').html(opts);
                });
            }

            $('#sa-class').on('change', function() {
                var classId = $(this).val();
                if (classId) loadStudents(classId, 0);
                else $('#sa-student').html('<option value="">— Select student —</option>');
                $('#sa-results').html('');
            });

            function renderStudent(d) {
                var html = '';
                var name = d.nickname || 'Student ' + d.user_id;

                // ── Stat cards ───────────────────────────────────────────────
                html += '<h3>' + name + '</h3>';
                html += '<div class="knowly-stat-grid" style="margin-bottom:24px;">'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.avg_score !== null ? d.avg_score + '%' : '—') + '</div><div class="knowly-stat-label">Avg Score</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.trial_count + '</div><div class="knowly-stat-label">Trials</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.quest_count + '</div><div class="knowly-stat-label">Quests</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.badges_earned + '</div><div class="knowly-stat-label">Badges</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.weekly_trials || 0) + '</div><div class="knowly-stat-label">Trials This Week</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.topics_attempted || 0) + '</div><div class="knowly-stat-label">Topics Attempted</div></div>'
                     + '</div>';

                // ── 4-week trend ─────────────────────────────────────────────
                if (d.trend && d.trend.length) {
                    html += '<h3>4-Week Trend</h3>';
                    html += '<div style="display:flex;gap:12px;margin-bottom:24px;">';
                    d.trend.forEach(function(w) {
                        var col = w.avg_score === null ? '#9ca3af' : (w.avg_score >= 70 ? '#16a34a' : (w.avg_score >= 50 ? '#d97706' : '#dc2626'));
                        html += '<div style="flex:1;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;text-align:center;">'
                             + '<div style="font-size:22px;font-weight:700;color:' + col + '">' + (w.avg_score !== null ? w.avg_score + '%' : '—') + '</div>'
                             + '<div style="font-size:11px;color:#666;margin-top:4px;">' + w.label + '</div>'
                             + '<div style="font-size:11px;color:#9ca3af;">' + w.trial_count + ' trial' + (w.trial_count !== 1 ? 's' : '') + '</div>'
                             + '</div>';
                    });
                    html += '</div>';
                }

                // ── Subject breakdown ────────────────────────────────────────
                if (d.subjects && d.subjects.length) {
                    html += '<h3>Subject Breakdown</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Subject</th><th>Avg Score</th><th>Trials</th><th>Topics Covered</th><th>Strong</th><th>Weak</th></tr></thead><tbody>';
                    d.subjects.forEach(function(s) {
                        html += '<tr>'
                             + '<td><strong>' + s.subject + '</strong></td>'
                             + '<td>' + scoreBar(s.avg_score) + '</td>'
                             + '<td>' + s.trial_count + '</td>'
                             + '<td>' + (s.topics_covered || 0) + '</td>'
                             + '<td>' + (s.topics_strong ? badge(s.topics_strong + ' topic' + (s.topics_strong !== 1 ? 's' : ''), 'ok') : '—') + '</td>'
                             + '<td>' + (s.topics_weak ? badge(s.topics_weak + ' topic' + (s.topics_weak !== 1 ? 's' : ''), 'err') : '—') + '</td>'
                             + '</tr>';
                    });
                    html += '</tbody></table>';
                }

                // ── Topic breakdown ──────────────────────────────────────────
                if (d.topic_breakdown && d.topic_breakdown.length) {
                    html += '<h3>Topic Breakdown</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Topic</th><th>Subject</th><th>Correct Rate</th><th>Questions</th><th></th></tr></thead><tbody>';
                    d.topic_breakdown.forEach(function(t) {
                        html += '<tr>'
                             + '<td><strong>' + t.topic + '</strong></td>'
                             + '<td>' + t.subject + '</td>'
                             + '<td>' + scoreBar(t.correct_rate) + '</td>'
                             + '<td>' + t.total_questions + '</td>'
                             + '<td>' + (t.is_strength ? badge('Strength', 'ok') : (t.is_weakness ? badge('Needs Work', 'err') : '')) + '</td>'
                             + '</tr>';
                    });
                    html += '</tbody></table>';
                }

                // ── Retry effectiveness ──────────────────────────────────────
                if (d.retry_effectiveness && d.retry_effectiveness.length) {
                    html += '<h3>Retry Effectiveness</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Topic / Subject</th><th>Attempts</th><th>1st Attempt</th><th>Subsequent Avg</th><th>Improvement</th></tr></thead><tbody>';
                    d.retry_effectiveness.forEach(function(r) {
                        var imp     = r.improvement;
                        var impCol  = imp > 0 ? '#16a34a' : (imp < 0 ? '#dc2626' : '#6b7280');
                        var impSign = imp > 0 ? '+' : '';
                        html += '<tr>'
                             + '<td><strong>' + (r.topic || '—') + '</strong> <span style="color:#666;font-size:11px;">(' + r.subject + ')</span></td>'
                             + '<td>' + r.attempts + '</td>'
                             + '<td>' + scoreBar(r.first_attempt) + '</td>'
                             + '<td>' + scoreBar(r.subsequent_avg) + '</td>'
                             + '<td style="font-weight:700;color:' + impCol + '">' + impSign + imp + '%</td>'
                             + '</tr>';
                    });
                    html += '</tbody></table>';
                }

                // ── Strengths & weaknesses ───────────────────────────────────
                if ((d.strengths && d.strengths.length) || (d.weaknesses && d.weaknesses.length)) {
                    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">';
                    html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:16px;">'
                         + '<h4 style="margin:0 0 10px;color:#15803d;">Strengths</h4>';
                    (d.strengths || []).forEach(function(t) {
                        html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #dcfce7;">'
                             + '<span>' + t.topic + ' <span style="color:#666;font-size:11px;">(' + t.subject + ')</span></span>'
                             + '<strong style="color:#15803d;">' + t.correct_rate + '%</strong></div>';
                    });
                    if (!(d.strengths || []).length) html += '<p style="color:#666;margin:0;">None yet.</p>';
                    html += '</div>';

                    html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:16px;">'
                         + '<h4 style="margin:0 0 10px;color:#991b1b;">Needs Work</h4>';
                    (d.weaknesses || []).forEach(function(t) {
                        html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #fee2e2;">'
                             + '<span>' + t.topic + ' <span style="color:#666;font-size:11px;">(' + t.subject + ')</span></span>'
                             + '<strong style="color:#991b1b;">' + t.correct_rate + '%</strong></div>';
                    });
                    if (!(d.weaknesses || []).length) html += '<p style="color:#666;margin:0;">None yet.</p>';
                    html += '</div>';

                    html += '</div>';
                }

                // ── Recent trials ────────────────────────────────────────────
                if (d.recent_trials && d.recent_trials.length) {
                    html += '<h3>Recent Trials</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Subject</th><th>Topic</th><th>Difficulty</th><th>Score</th><th>Source</th><th>Date</th></tr></thead><tbody>';
                    d.recent_trials.forEach(function(t) {
                        var date = t.completed_at ? new Date(t.completed_at).toLocaleDateString('en-TT', {day:'numeric',month:'short',year:'numeric'}) : '—';
                        html += '<tr>'
                             + '<td>' + (t.subject || '—') + '</td>'
                             + '<td>' + (t.topic || '—') + '</td>'
                             + '<td>' + (t.difficulty || '—') + '</td>'
                             + '<td>' + scoreBar(t.percentage) + '</td>'
                             + '<td>' + (t.source || '—') + '</td>'
                             + '<td style="font-size:12px;color:#666;">' + date + '</td>'
                             + '</tr>';
                    });
                    html += '</tbody></table>';
                }

                $('#sa-results').html(html);
            }

            function load() {
                var classId   = $('#sa-class').val();
                var studentId = $('#sa-student').val();
                if (!classId)   { $('#sa-status').text('Please select a class.'); return; }
                if (!studentId) { $('#sa-status').text('Please select a student.'); return; }
                $('#sa-status').text('Loading…');
                $('#sa-load').prop('disabled', true);
                $.post(ajaxurl, {
                    action:   'knowly_analytics_student',
                    nonce:    nonce,
                    class_id: classId,
                    user_id:  studentId,
                    period:   $('#sa-period').val(),
                    subject:  $('#sa-subject').val(),
                }, function(res) {
                    $('#sa-load').prop('disabled', false);
                    if (!res.success) { $('#sa-status').text('Error: ' + (res.data?.message || 'Unknown error')); return; }
                    $('#sa-status').text('');
                    renderStudent(res.data);
                }).fail(function() {
                    $('#sa-load').prop('disabled', false);
                    $('#sa-status').text('Request failed. Check Railway is running.');
                });
            }

            $('#sa-load').on('click', load);

            // Auto-load student list if class pre-selected from Overview link
            if (preClass) {
                $('#sa-class').val(preClass);
                loadStudents(preClass, 0);
            }
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // TAB: HEALTH CHECKS
    // =========================================================================

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
                var html = '<table class="wp-list-table widefat" style="max-width:700px;"><tbody>';
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

    // =========================================================================
    // TAB: UNIT TESTS
    // =========================================================================

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test class analytics aggregation, per-student drill-down, and access control enforcement.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'block7_analytics' ] );
    }

    // =========================================================================
    // AJAX: Class analytics (calls Railway directly — admin only)
    // =========================================================================

    public static function ajax_class_analytics(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        if ( ! $class_id ) { wp_send_json_error( [ 'message' => 'class_id required' ] ); }

        $members = $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND status = 'active'",
            $class_id
        ) );

        if ( empty( $members ) ) {
            wp_send_json_success( [
                'student_count' => 0, 'total_trials' => 0, 'total_quests' => 0,
                'class_avg_score' => null, 'at_risk_count' => 0, 'students' => [],
                'topic_heatmap' => [], 'strengths' => [], 'weaknesses' => [],
            ] );
        }

        $params = [ 'user_ids' => implode( ',', $members ) ];
        if ( ! empty( $_POST['period'] ) )  $params['period']  = sanitize_text_field( $_POST['period'] );
        if ( ! empty( $_POST['subject'] ) ) $params['subject'] = sanitize_text_field( $_POST['subject'] );

        $data = self::railway_get( '/api/v1/analytics/class', $params );
        if ( is_wp_error( $data ) ) {
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        // Merge WP nicknames into student rows
        if ( ! empty( $data['students'] ) ) {
            $data['students'] = array_map( function( array $s ) use ( $wpdb ): array {
                $uid = (int) ( $s['user_id'] ?? 0 );
                if ( $uid ) {
                    $s['nickname'] = get_user_meta( $uid, 'knowly_nickname', true ) ?: '';
                    $s['level']    = get_user_meta( $uid, 'knowly_level',    true ) ?: '';
                }
                return $s;
            }, $data['students'] );
        }

        wp_send_json_success( $data );
    }

    // =========================================================================
    // AJAX: Student analytics (calls Railway directly — admin only)
    // =========================================================================

    public static function ajax_student_analytics(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) { wp_send_json_error( [ 'message' => 'user_id required' ] ); }

        $params = [ 'user_id' => (string) $user_id ];
        if ( ! empty( $_POST['period'] ) )  $params['period']  = sanitize_text_field( $_POST['period'] );
        if ( ! empty( $_POST['subject'] ) ) $params['subject'] = sanitize_text_field( $_POST['subject'] );

        $data = self::railway_get( '/api/v1/analytics/student', $params );
        if ( is_wp_error( $data ) ) {
            wp_send_json_error( [ 'message' => $data->get_error_message() ] );
        }

        $data['nickname'] = get_user_meta( $user_id, 'knowly_nickname', true ) ?: '';
        $data['level']    = get_user_meta( $user_id, 'knowly_level',    true ) ?: '';

        wp_send_json_success( $data );
    }

    // =========================================================================
    // AJAX: Class member list (for student dropdown)
    // =========================================================================

    public static function ajax_class_members(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        if ( ! $class_id ) { wp_send_json_error( [ 'message' => 'class_id required' ] ); }

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND status = 'active'",
            $class_id
        ) );

        $students = array_map( function( $child_id ) {
            return [
                'child_id' => (int) $child_id,
                'nickname' => get_user_meta( (int) $child_id, 'knowly_nickname', true ) ?: 'Student ' . $child_id,
            ];
        }, $rows ?: [] );

        wp_send_json_success( $students );
    }

    // =========================================================================
    // AJAX: Health check
    // =========================================================================

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $checks   = [];
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );

        if ( $endpoint ) {
            $resp = wp_remote_get( $endpoint . '/api/v1/health', [ 'timeout' => 8 ] );
            $ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
            $checks[] = [ 'label' => 'Railway reachable', 'status' => $ok ? 'pass' : 'fail', 'detail' => $ok ? 'Analytics routes reachable.' : 'Railway unreachable.' ];
        } else {
            $checks[] = [ 'label' => 'Railway endpoint', 'status' => 'fail', 'detail' => 'Not configured.' ];
        }

        $test_teacher = get_user_by( 'login', 'test.teacher' );
        $checks[] = [ 'label' => 'Test teacher account', 'status' => $test_teacher ? 'pass' : 'warn', 'detail' => $test_teacher ? 'test.teacher exists (ID ' . $test_teacher->ID . ').' : 'Not found.' ];

        global $wpdb;
        $class_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes WHERE status = 'active'" );
        $checks[] = [ 'label' => 'Active classes exist', 'status' => $class_count > 0 ? 'pass' : 'warn', 'detail' => "{$class_count} active class(es)." ];

        wp_send_json_success( [ 'checks' => $checks ] );
    }

    // =========================================================================
    // Railway HTTP helper (server-key auth)
    // =========================================================================

    private static function railway_get( string $path, array $params = [] ): array|WP_Error {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return new WP_Error( 'not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $url = $endpoint . $path;
        if ( ! empty( $params ) ) $url .= '?' . http_build_query( $params );

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'railway_error', 'Failed to connect to Railway: ' . $response->get_error_message(), [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || empty( $body ) ) {
            return new WP_Error( 'railway_error', $body['error'] ?? "Railway returned HTTP {$code}.", [ 'status' => 503 ] );
        }

        return $body;
    }
}
