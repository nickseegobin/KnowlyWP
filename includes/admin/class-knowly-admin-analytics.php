<?php
/**
 * Knowly_Admin_Analytics — Analytics admin panel.
 *
 * Tabs: Overview | Class Analytics | Student Analytics | Health Checks | Unit Tests
 *
 * Class Analytics:
 *   List view  — filter by Teacher + Level → class list
 *   Detail view — ?class_id=X → full class analytics + student roster
 *
 * Student Analytics:
 *   List view  — filter by Level + Period + Name → student list
 *   Detail view — ?student_id=X → full student analytics
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Analytics {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_analytics_health',         [ __CLASS__, 'ajax_health' ] );
        add_action( 'wp_ajax_knowly_analytics_class',          [ __CLASS__, 'ajax_class_analytics' ] );
        add_action( 'wp_ajax_knowly_analytics_student',        [ __CLASS__, 'ajax_student_analytics' ] );
        add_action( 'wp_ajax_knowly_purge_orphaned_students',  [ __CLASS__, 'ajax_purge_orphaned_students' ] );
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
        $student_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_children" );
        $task_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks WHERE status = 'active'" );
        $railway_ok   = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $top_classes  = $wpdb->get_results(
            "SELECT c.id, c.name, c.level, c.teacher_user_id,
                    COUNT(m.id) AS member_count
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->prefix}knowly_class_members m ON m.class_id = c.id AND m.status = 'active'
             WHERE c.status = 'active'
             GROUP BY c.id ORDER BY member_count DESC LIMIT 10"
        );
        $tax = get_option( 'knowly_curriculum_subjects', [] );
        $level_map = [];
        foreach ( $tax as $cfg ) {
            foreach ( $cfg['levels'] ?? [] as $l ) {
                $level_map[ $l['value'] ] = $l['label'];
            }
        }
        ?>
        <div class="knowly-stat-grid">
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div><div class="knowly-stat-label">Active Classes</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $student_count ) ?></div><div class="knowly-stat-label">Students</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $task_count ) ?></div><div class="knowly-stat-label">Active Tasks</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= $railway_ok ? '<span style="color:#00a32a">●</span>' : '<span style="color:#d63638">●</span>' ?></div><div class="knowly-stat-label">Railway</div></div>
        </div>
        <h3 style="margin-top:24px;">Active Classes</h3>
        <?php if ( empty( $top_classes ) ) : ?>
        <p style="color:#888;">No active classes.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr><th>Class</th><th>Level</th><th>Teacher</th><th style="width:100px;">Students</th><th style="width:180px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ( $top_classes as $c ) :
                $teacher = get_userdata( (int) $c->teacher_user_id );
                $level_label = $level_map[ $c->level ] ?? $c->level;
            ?>
            <tr>
                <td><strong><?= esc_html( $c->name ) ?></strong></td>
                <td><?= esc_html( $level_label ) ?></td>
                <td><?= esc_html( $teacher ? $teacher->display_name : '—' ) ?></td>
                <td><?= esc_html( $c->member_count ) ?></td>
                <td>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=class&class_id=' . $c->id ) ) ?>" class="button button-small">Class Analytics</a>
                </td>
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
        $class_id = (int) ( $_GET['class_id'] ?? 0 );

        if ( $class_id ) {
            self::render_class_detail( $class_id );
        } else {
            self::render_class_list();
        }
    }

    private static function render_class_list(): void {
        global $wpdb;

        $tax = get_option( 'knowly_curriculum_subjects', [] );

        // Build level map
        $level_map = [];
        foreach ( $tax as $cfg ) {
            foreach ( $cfg['levels'] ?? [] as $l ) {
                $level_map[ $l['value'] ] = $l['label'];
            }
        }

        // Filters from GET
        $filter_teacher = (int) ( $_GET['teacher'] ?? 0 );
        $filter_level   = sanitize_text_field( $_GET['level']   ?? '' );

        // All teachers who have classes
        $teachers = $wpdb->get_results(
            "SELECT DISTINCT u.ID, u.display_name
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->prefix}knowly_classes c ON c.teacher_user_id = u.ID
             WHERE c.status = 'active'
             ORDER BY u.display_name ASC"
        );

        // Build class query
        $where  = [ "c.status = 'active'" ];
        $params = [];
        if ( $filter_teacher ) { $where[] = 'c.teacher_user_id = %d'; $params[] = $filter_teacher; }
        if ( $filter_level )   { $where[] = 'c.level = %s';           $params[] = $filter_level; }

        $sql = "SELECT c.id, c.name, c.level, c.teacher_user_id, c.created_at,
                       COUNT(m.id) AS member_count
                FROM {$wpdb->prefix}knowly_classes c
                LEFT JOIN {$wpdb->prefix}knowly_class_members m ON m.class_id = c.id AND m.status = 'active'
                WHERE " . implode( ' AND ', $where ) . "
                GROUP BY c.id ORDER BY c.name ASC";

        $classes = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );
        ?>

        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px;">
            <form method="get" style="display:contents;">
                <input type="hidden" name="page" value="knowly-analytics">
                <input type="hidden" name="tab"  value="class">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Teacher</label>
                    <select name="teacher" style="min-width:180px;">
                        <option value="">All Teachers</option>
                        <?php foreach ( $teachers as $t ) : ?>
                        <option value="<?= esc_attr( $t->ID ) ?>" <?= selected( $filter_teacher, $t->ID, false ) ?>><?= esc_html( $t->display_name ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Level</label>
                    <select name="level" style="min-width:160px;">
                        <option value="">All Levels</option>
                        <?php foreach ( $level_map as $val => $label ) : ?>
                        <option value="<?= esc_attr( $val ) ?>" <?= selected( $filter_level, $val, false ) ?>><?= esc_html( $label ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="button button-primary">Filter</button>
                    <?php if ( $filter_teacher || $filter_level ) : ?>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=class' ) ) ?>" class="button" style="margin-left:4px;">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ( empty( $classes ) ) : ?>
        <p style="color:#888;">No classes match the selected filters.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Class Name</th>
                    <th>Level</th>
                    <th>Teacher</th>
                    <th style="width:100px;">Students</th>
                    <th style="width:160px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $classes as $c ) :
                $teacher     = get_userdata( (int) $c->teacher_user_id );
                $level_label = $level_map[ $c->level ] ?? $c->level;
            ?>
            <tr>
                <td><strong><?= esc_html( $c->name ) ?></strong></td>
                <td><?= esc_html( $level_label ) ?></td>
                <td><?= esc_html( $teacher ? $teacher->display_name : '—' ) ?></td>
                <td><?= esc_html( $c->member_count ) ?></td>
                <td>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=class&class_id=' . $c->id ) ) ?>" class="button button-primary button-small">View Analytics →</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    private static function render_class_detail( int $class_id ): void {
        global $wpdb;

        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.*, COUNT(m.id) AS member_count
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->prefix}knowly_class_members m ON m.class_id = c.id AND m.status = 'active'
             WHERE c.id = %d GROUP BY c.id",
            $class_id
        ) );

        if ( ! $class ) {
            echo '<p style="color:#dc2626;">Class not found.</p>';
            return;
        }

        $tax = get_option( 'knowly_curriculum_subjects', [] );
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        $back_url = admin_url( 'admin.php?page=knowly-analytics&tab=class' );
        $teacher  = get_userdata( (int) $class->teacher_user_id );

        $level_map = [];
        foreach ( $tax as $cfg ) {
            foreach ( $cfg['levels'] ?? [] as $l ) { $level_map[ $l['value'] ] = $l['label']; }
        }
        $level_label = $level_map[ $class->level ] ?? $class->level;
        ?>

        <!-- Breadcrumb -->
        <p style="margin-bottom:16px;">
            <a href="<?= esc_url( $back_url ) ?>">← All Classes</a>
        </p>

        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
            <div>
                <h2 style="margin:0 0 4px;"><?= esc_html( $class->name ) ?></h2>
                <p style="margin:0;color:#666;">
                    <strong>Level:</strong> <?= esc_html( $level_label ) ?> &nbsp;|&nbsp;
                    <strong>Teacher:</strong> <?= esc_html( $teacher ? $teacher->display_name : '—' ) ?> &nbsp;|&nbsp;
                    <strong>Students:</strong> <?= esc_html( $class->member_count ) ?>
                </p>
            </div>
            <!-- Period + Subject filters -->
            <form method="get" style="display:flex;gap:8px;align-items:flex-end;">
                <input type="hidden" name="page"     value="knowly-analytics">
                <input type="hidden" name="tab"      value="class">
                <input type="hidden" name="class_id" value="<?= esc_attr( $class_id ) ?>">
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Period</label>
                    <select name="period" id="cd-period" style="min-width:120px;">
                        <option value="">All Periods</option>
                        <?php foreach ( $tax as $cfg ) foreach ( $cfg['periods'] ?? [] as $p ) : ?>
                        <option value="<?= esc_attr( $p['value'] ) ?>" <?= selected( $_GET['period'] ?? '', $p['value'], false ) ?>><?= esc_html( $p['label'] ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Subject</label>
                    <select name="subject" id="cd-subject" style="min-width:140px;">
                        <option value="">All Subjects</option>
                        <?php foreach ( $tax as $cfg ) foreach ( $cfg['subjects'] ?? [] as $s ) : ?>
                        <option value="<?= esc_attr( $s['value'] ) ?>" <?= selected( $_GET['subject'] ?? '', $s['value'], false ) ?>><?= esc_html( $s['label'] ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="button">Apply</button>
            </form>
        </div>

        <div id="cd-status" style="color:#888;font-style:italic;padding:20px 0;">Loading analytics…</div>
        <div id="cd-results"></div>

        <script>
        (function($) {
            var nonce   = <?= wp_json_encode( $nonce ) ?>;
            var classId = <?= wp_json_encode( $class_id ) ?>;
            var period  = <?= wp_json_encode( sanitize_text_field( $_GET['period']  ?? '' ) ) ?>;
            var subject = <?= wp_json_encode( sanitize_text_field( $_GET['subject'] ?? '' ) ) ?>;

            function scoreBar(pct) {
                if (pct === null || pct === undefined) return '<span style="color:#999">—</span>';
                var col = pct >= 70 ? '#16a34a' : (pct >= 50 ? '#d97706' : '#dc2626');
                return '<span style="font-weight:600;color:' + col + '">' + pct + '%</span>'
                     + ' <span style="display:inline-block;width:60px;height:8px;background:#e5e7eb;border-radius:4px;vertical-align:middle;">'
                     + '<span style="display:block;width:' + pct + '%;height:100%;background:' + col + ';border-radius:4px;"></span></span>';
            }

            function badge(label, type) {
                var bg  = type === 'ok' ? '#dcfce7' : (type === 'warn' ? '#fef9c3' : '#fee2e2');
                var col = type === 'ok' ? '#15803d' : (type === 'warn' ? '#854d0e' : '#991b1b');
                return '<span style="background:' + bg + ';color:' + col + ';padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">' + label + '</span>';
            }

            function studentUrl(userId) {
                return <?= wp_json_encode( admin_url( 'admin.php?page=knowly-analytics&tab=student&student_id=' ) ) ?> + userId;
            }

            function render(d) {
                var html = '';

                // Stat cards
                html += '<div class="knowly-stat-grid" style="margin-bottom:24px;">'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.student_count + '</div><div class="knowly-stat-label">Students</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.class_avg_score !== null ? d.class_avg_score + '%' : '—') + '</div><div class="knowly-stat-label">Class Avg Score</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.total_trials + '</div><div class="knowly-stat-label">Total Trials</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.total_quests + '</div><div class="knowly-stat-label">Quests Completed</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.avg_engagement_rate || 0) + '</div><div class="knowly-stat-label">Avg Trials / Week</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number" style="color:' + (d.at_risk_count > 0 ? '#dc2626' : '#16a34a') + '">' + d.at_risk_count + '</div><div class="knowly-stat-label">At-Risk Students</div></div>'
                     + '</div>';

                // Student roster
                html += '<h3>Enrolled Students</h3>';
                html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                     + '<thead><tr><th>Student</th><th>Avg Score</th><th>Trials</th><th>Quests</th><th>Trials This Week</th><th>Last Active</th><th>Status</th><th></th></tr></thead><tbody>';
                (d.students || []).forEach(function(s) {
                    var lastActive = s.last_active ? new Date(s.last_active).toLocaleDateString('en-TT', {day:'numeric',month:'short',year:'numeric'}) : '—';
                    html += '<tr>'
                         + '<td><strong>' + (s.nickname || 'Student ' + s.user_id) + '</strong>'
                         + (s.level ? ' <span style="color:#888;font-size:11px;">(' + s.level + ')</span>' : '') + '</td>'
                         + '<td>' + scoreBar(s.avg_score) + '</td>'
                         + '<td>' + s.trial_count + '</td>'
                         + '<td>' + s.quest_count + '</td>'
                         + '<td>' + (s.weekly_trials || 0) + '</td>'
                         + '<td style="font-size:12px;color:#666;">' + lastActive + '</td>'
                         + '<td>' + (s.at_risk ? badge('At Risk', 'err') : badge('On Track', 'ok')) + '</td>'
                         + '<td><a href="' + studentUrl(s.user_id) + '" class="button button-small">View →</a></td>'
                         + '</tr>';
                });
                html += '</tbody></table>';

                // Topic heatmap
                if (d.topic_heatmap && d.topic_heatmap.length) {
                    html += '<h3>Topic Heatmap</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Topic</th><th>Subject</th><th>Correct Rate</th><th>Questions</th><th>Students</th><th></th></tr></thead><tbody>';
                    d.topic_heatmap.forEach(function(t) {
                        html += '<tr><td><strong>' + t.topic + '</strong></td><td>' + t.subject + '</td>'
                             + '<td>' + scoreBar(t.correct_rate) + '</td><td>' + t.total_questions + '</td><td>' + t.student_count + '</td>'
                             + '<td>' + (t.is_strength ? badge('Strength','ok') : (t.is_weakness ? badge('Needs Work','err') : '')) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                // Strengths / Weaknesses
                if ((d.strengths && d.strengths.length) || (d.weaknesses && d.weaknesses.length)) {
                    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">';
                    html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:16px;">'
                         + '<h4 style="margin:0 0 10px;color:#15803d;">Class Strengths</h4>';
                    (d.strengths || []).forEach(function(t) {
                        html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #dcfce7;">'
                             + '<span>' + t.topic + ' <span style="color:#888;font-size:11px;">(' + t.subject + ')</span></span>'
                             + '<strong style="color:#15803d;">' + t.correct_rate + '%</strong></div>';
                    });
                    if (!(d.strengths || []).length) html += '<p style="color:#666;margin:0;">No strong topics yet.</p>';
                    html += '</div>';

                    html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:16px;">'
                         + '<h4 style="margin:0 0 10px;color:#991b1b;">Needs Attention</h4>';
                    (d.weaknesses || []).forEach(function(t) {
                        html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #fee2e2;">'
                             + '<span>' + t.topic + ' <span style="color:#888;font-size:11px;">(' + t.subject + ')</span></span>'
                             + '<strong style="color:#991b1b;">' + t.correct_rate + '%</strong></div>';
                    });
                    if (!(d.weaknesses || []).length) html += '<p style="color:#666;margin:0;">None identified.</p>';
                    html += '</div></div>';
                }

                $('#cd-status').hide();
                $('#cd-results').html(html);
            }

            // Auto-load on page ready
            $.post(ajaxurl, {
                action: 'knowly_analytics_class',
                nonce:  nonce,
                class_id: classId,
                period:   period,
                subject:  subject,
            }, function(res) {
                if (!res.success) {
                    $('#cd-status').text('Error: ' + (res.data?.message || 'Unknown error')).css('color','#dc2626');
                    return;
                }
                render(res.data);
            }).fail(function() {
                $('#cd-status').text('Request failed — check Railway is reachable.').css('color','#dc2626');
            });
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // TAB: STUDENT ANALYTICS
    // =========================================================================

    private static function render_student_tab(): void {
        $student_id = (int) ( $_GET['student_id'] ?? 0 );

        if ( $student_id ) {
            self::render_student_detail( $student_id );
        } else {
            self::render_student_list();
        }
    }

    private static function render_student_list(): void {
        global $wpdb;

        $tax = get_option( 'knowly_curriculum_subjects', [] );
        $level_map = [];
        foreach ( $tax as $cfg ) {
            foreach ( $cfg['levels'] ?? [] as $l ) { $level_map[ $l['value'] ] = $l['label']; }
        }
        $period_map = [];
        foreach ( $tax as $cfg ) {
            foreach ( $cfg['periods'] ?? [] as $p ) { $period_map[ $p['value'] ] = $p['label']; }
        }

        $filter_level  = sanitize_text_field( $_GET['level']  ?? '' );
        $filter_period = sanitize_text_field( $_GET['period'] ?? '' );
        $filter_name   = sanitize_text_field( $_GET['name']   ?? '' );

        // Build student query
        $where  = [ '1=1' ];
        $params = [];
        if ( $filter_level )  { $where[] = 'ch.level = %s';  $params[] = $filter_level; }
        if ( $filter_period ) { $where[] = 'ch.period = %s'; $params[] = $filter_period; }
        if ( $filter_name )   {
            $where[]  = '(ch.display_name LIKE %s OR um.meta_value LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $filter_name ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT ch.child_id, ch.display_name, ch.level, ch.period, ch.avatar_index,
                       um.meta_value AS nickname
                FROM {$wpdb->prefix}knowly_children ch
                INNER JOIN {$wpdb->users} u ON u.ID = ch.child_id
                LEFT JOIN {$wpdb->usermeta} um ON um.user_id = ch.child_id AND um.meta_key = 'knowly_nickname'
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY ch.display_name ASC
                LIMIT 100";

        $students = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );
        ?>

        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px;">
            <form method="get" style="display:contents;">
                <input type="hidden" name="page" value="knowly-analytics">
                <input type="hidden" name="tab"  value="student">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Level</label>
                    <select name="level" style="min-width:160px;">
                        <option value="">All Levels</option>
                        <?php foreach ( $level_map as $val => $label ) : ?>
                        <option value="<?= esc_attr( $val ) ?>" <?= selected( $filter_level, $val, false ) ?>><?= esc_html( $label ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Period</label>
                    <select name="period" style="min-width:140px;">
                        <option value="">All Periods</option>
                        <?php foreach ( $period_map as $val => $label ) : ?>
                        <option value="<?= esc_attr( $val ) ?>" <?= selected( $filter_period, $val, false ) ?>><?= esc_html( $label ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Search by Name</label>
                    <input type="text" name="name" value="<?= esc_attr( $filter_name ) ?>" placeholder="Nickname or display name…" style="min-width:200px;" class="regular-text">
                </div>
                <div>
                    <button type="submit" class="button button-primary">Search</button>
                    <?php if ( $filter_level || $filter_period || $filter_name ) : ?>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=student' ) ) ?>" class="button" style="margin-left:4px;">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php
        // Show orphan count so admin knows cleanup is available
        $orphan_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_children ch
             LEFT JOIN {$wpdb->users} u ON u.ID = ch.child_id
             WHERE u.ID IS NULL"
        );
        if ( $orphan_count > 0 ) : ?>
        <div style="background:#fef9c3;border:1px solid #fef08a;border-radius:4px;padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
            <span style="color:#854d0e;font-size:13px;">
                ⚠ <?= esc_html( $orphan_count ) ?> student record<?= $orphan_count !== 1 ? 's' : '' ?> belong to deleted WP users and are hidden from this list.
            </span>
            <button id="knowly-purge-orphans" class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                    data-nonce="<?= esc_attr( wp_create_nonce( 'knowly_admin_nonce' ) ) ?>">
                Purge Orphaned Data
            </button>
        </div>
        <script>
        document.getElementById('knowly-purge-orphans').addEventListener('click', function() {
            if (!confirm('This will permanently delete all Knowly data (sessions, answers, gems, quest history) for users who no longer exist in WordPress. This cannot be undone. Continue?')) return;
            var btn = this;
            btn.disabled = true; btn.textContent = 'Purging…';
            jQuery.post(ajaxurl, {
                action: 'knowly_purge_orphaned_students',
                nonce:  btn.dataset.nonce,
            }, function(res) {
                if (res.success) {
                    btn.closest('div').innerHTML = '<span style="color:#16a34a;font-size:13px;">✓ ' + (res.data.message || 'Purge complete.') + ' Reload to refresh the list.</span>';
                } else {
                    btn.disabled = false; btn.textContent = 'Purge Orphaned Data';
                    alert('Error: ' + (res.data?.message || 'Unknown error'));
                }
            });
        });
        </script>
        <?php endif; ?>
        <?php if ( empty( $students ) ) : ?>
        <p style="color:#888;">No students found.</p>
        <?php else : ?>
        <p style="color:#666;margin-bottom:8px;"><?= count( $students ) ?> student<?= count( $students ) !== 1 ? 's' : '' ?> found.</p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Nickname</th>
                    <th>Level</th>
                    <th>Period</th>
                    <th style="width:140px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $students as $s ) :
                $level_label  = $level_map[ $s->level ]   ?? $s->level;
                $period_label = $period_map[ $s->period ]  ?? $s->period;
            ?>
            <tr>
                <td><strong><?= esc_html( $s->display_name ) ?></strong></td>
                <td><?= esc_html( $s->nickname ?: '—' ) ?></td>
                <td><?= esc_html( $level_label ) ?></td>
                <td><?= esc_html( $period_label ) ?></td>
                <td>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-analytics&tab=student&student_id=' . $s->child_id ) ) ?>" class="button button-primary button-small">View Analytics →</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    private static function render_student_detail( int $student_id ): void {
        global $wpdb;

        $child = $wpdb->get_row( $wpdb->prepare(
            "SELECT ch.*, um.meta_value AS nickname
             FROM {$wpdb->prefix}knowly_children ch
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = ch.child_id AND um.meta_key = 'knowly_nickname'
             WHERE ch.child_id = %d",
            $student_id
        ) );

        if ( ! $child ) {
            echo '<p style="color:#dc2626;">Student not found.</p>';
            return;
        }

        $tax = get_option( 'knowly_curriculum_subjects', [] );
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        $back_url = admin_url( 'admin.php?page=knowly-analytics&tab=student' );

        $level_map = [];
        foreach ( $tax as $cfg ) {
            foreach ( $cfg['levels'] ?? [] as $l ) { $level_map[ $l['value'] ] = $l['label']; }
        }
        $period_map = [];
        foreach ( $tax as $cfg ) {
            foreach ( $cfg['periods'] ?? [] as $p ) { $period_map[ $p['value'] ] = $p['label']; }
        }

        $level_label  = $level_map[ $child->level ]  ?? $child->level;
        $period_label = $period_map[ $child->period ] ?? $child->period;
        $display_name = $child->nickname ?: $child->display_name;
        ?>

        <!-- Breadcrumb -->
        <p style="margin-bottom:16px;">
            <a href="<?= esc_url( $back_url ) ?>">← All Students</a>
        </p>

        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
            <div>
                <h2 style="margin:0 0 4px;"><?= esc_html( $display_name ) ?></h2>
                <p style="margin:0;color:#666;">
                    <strong>Level:</strong> <?= esc_html( $level_label ) ?> &nbsp;|&nbsp;
                    <strong>Period:</strong> <?= esc_html( $period_label ) ?>
                    <?php if ( $child->nickname && $child->nickname !== $child->display_name ) : ?>
                    &nbsp;|&nbsp; <strong>Display name:</strong> <?= esc_html( $child->display_name ) ?>
                    <?php endif; ?>
                </p>
            </div>
            <!-- Period + Subject filters -->
            <form method="get" style="display:flex;gap:8px;align-items:flex-end;">
                <input type="hidden" name="page"       value="knowly-analytics">
                <input type="hidden" name="tab"        value="student">
                <input type="hidden" name="student_id" value="<?= esc_attr( $student_id ) ?>">
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Period</label>
                    <select name="period" style="min-width:120px;">
                        <option value="">All Periods</option>
                        <?php foreach ( $period_map as $val => $label ) : ?>
                        <option value="<?= esc_attr( $val ) ?>" <?= selected( $_GET['period'] ?? '', $val, false ) ?>><?= esc_html( $label ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;margin-bottom:2px;">Subject</label>
                    <select name="subject" style="min-width:140px;">
                        <option value="">All Subjects</option>
                        <?php foreach ( $tax as $cfg ) foreach ( $cfg['subjects'] ?? [] as $s ) : ?>
                        <option value="<?= esc_attr( $s['value'] ) ?>" <?= selected( $_GET['subject'] ?? '', $s['value'], false ) ?>><?= esc_html( $s['label'] ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="button">Apply</button>
            </form>
        </div>

        <div id="sd-status" style="color:#888;font-style:italic;padding:20px 0;">Loading analytics…</div>
        <div id="sd-results"></div>

        <script>
        (function($) {
            var nonce     = <?= wp_json_encode( $nonce ) ?>;
            var studentId = <?= wp_json_encode( $student_id ) ?>;
            var period    = <?= wp_json_encode( sanitize_text_field( $_GET['period']  ?? '' ) ) ?>;
            var subject   = <?= wp_json_encode( sanitize_text_field( $_GET['subject'] ?? '' ) ) ?>;

            function scoreBar(pct) {
                if (pct === null || pct === undefined) return '<span style="color:#999">—</span>';
                var col = pct >= 70 ? '#16a34a' : (pct >= 50 ? '#d97706' : '#dc2626');
                return '<span style="font-weight:600;color:' + col + '">' + pct + '%</span>'
                     + ' <span style="display:inline-block;width:60px;height:8px;background:#e5e7eb;border-radius:4px;vertical-align:middle;">'
                     + '<span style="display:block;width:' + pct + '%;height:100%;background:' + col + ';border-radius:4px;"></span></span>';
            }

            function badge(label, type) {
                var bg  = type === 'ok' ? '#dcfce7' : (type === 'warn' ? '#fef9c3' : '#fee2e2');
                var col = type === 'ok' ? '#15803d' : (type === 'warn' ? '#854d0e' : '#991b1b');
                return '<span style="background:' + bg + ';color:' + col + ';padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;">' + label + '</span>';
            }

            function render(d) {
                var html = '';

                // Stat cards
                html += '<div class="knowly-stat-grid" style="margin-bottom:24px;">'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.avg_score !== null ? d.avg_score + '%' : '—') + '</div><div class="knowly-stat-label">Avg Score</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.trial_count + '</div><div class="knowly-stat-label">Trials</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.quest_count + '</div><div class="knowly-stat-label">Quests</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + d.badges_earned + '</div><div class="knowly-stat-label">Badges</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.weekly_trials || 0) + '</div><div class="knowly-stat-label">Trials This Week</div></div>'
                     + '<div class="knowly-stat-card"><div class="knowly-stat-number">' + (d.topics_attempted || 0) + '</div><div class="knowly-stat-label">Topics Attempted</div></div>'
                     + '</div>';

                // 4-week trend
                if (d.trend && d.trend.length) {
                    html += '<h3>4-Week Trend</h3><div style="display:flex;gap:12px;margin-bottom:24px;">';
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

                // Subject breakdown
                if (d.subjects && d.subjects.length) {
                    html += '<h3>Subject Breakdown</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Subject</th><th>Avg Score</th><th>Trials</th><th>Topics Covered</th><th>Strong</th><th>Weak</th></tr></thead><tbody>';
                    d.subjects.forEach(function(s) {
                        html += '<tr><td><strong>' + s.subject + '</strong></td><td>' + scoreBar(s.avg_score) + '</td>'
                             + '<td>' + s.trial_count + '</td><td>' + (s.topics_covered || 0) + '</td>'
                             + '<td>' + (s.topics_strong ? badge(s.topics_strong + ' topic' + (s.topics_strong !== 1 ? 's' : ''), 'ok') : '—') + '</td>'
                             + '<td>' + (s.topics_weak ? badge(s.topics_weak + ' topic' + (s.topics_weak !== 1 ? 's' : ''), 'err') : '—') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                // Topic breakdown
                if (d.topic_breakdown && d.topic_breakdown.length) {
                    html += '<h3>Topic Breakdown</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Topic</th><th>Subject</th><th>Correct Rate</th><th>Questions</th><th></th></tr></thead><tbody>';
                    d.topic_breakdown.forEach(function(t) {
                        html += '<tr><td><strong>' + t.topic + '</strong></td><td>' + t.subject + '</td>'
                             + '<td>' + scoreBar(t.correct_rate) + '</td><td>' + t.total_questions + '</td>'
                             + '<td>' + (t.is_strength ? badge('Strength','ok') : (t.is_weakness ? badge('Needs Work','err') : '')) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                // Retry effectiveness
                if (d.retry_effectiveness && d.retry_effectiveness.length) {
                    html += '<h3>Retry Effectiveness</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Topic / Subject</th><th>Attempts</th><th>1st Attempt</th><th>Subsequent Avg</th><th>Improvement</th></tr></thead><tbody>';
                    d.retry_effectiveness.forEach(function(r) {
                        var imp = r.improvement;
                        var impCol = imp > 0 ? '#16a34a' : (imp < 0 ? '#dc2626' : '#6b7280');
                        html += '<tr><td><strong>' + (r.topic || '—') + '</strong> <span style="color:#888;font-size:11px;">(' + r.subject + ')</span></td>'
                             + '<td>' + r.attempts + '</td><td>' + scoreBar(r.first_attempt) + '</td><td>' + scoreBar(r.subsequent_avg) + '</td>'
                             + '<td style="font-weight:700;color:' + impCol + '">' + (imp > 0 ? '+' : '') + imp + '%</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                // Strengths / Weaknesses
                html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">';
                html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:16px;"><h4 style="margin:0 0 10px;color:#15803d;">Strengths</h4>';
                (d.strengths || []).forEach(function(t) {
                    html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #dcfce7;">'
                         + '<span>' + t.topic + ' <span style="color:#888;font-size:11px;">(' + t.subject + ')</span></span>'
                         + '<strong style="color:#15803d;">' + t.correct_rate + '%</strong></div>';
                });
                if (!(d.strengths || []).length) html += '<p style="color:#666;margin:0;">None yet.</p>';
                html += '</div>';

                html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:16px;"><h4 style="margin:0 0 10px;color:#991b1b;">Needs Work</h4>';
                (d.weaknesses || []).forEach(function(t) {
                    html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #fee2e2;">'
                         + '<span>' + t.topic + ' <span style="color:#888;font-size:11px;">(' + t.subject + ')</span></span>'
                         + '<strong style="color:#991b1b;">' + t.correct_rate + '%</strong></div>';
                });
                if (!(d.weaknesses || []).length) html += '<p style="color:#666;margin:0;">None yet.</p>';
                html += '</div></div>';

                // Recent trials
                if (d.recent_trials && d.recent_trials.length) {
                    html += '<h3>Recent Trials</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">'
                         + '<thead><tr><th>Subject</th><th>Topic</th><th>Difficulty</th><th>Score</th><th>Source</th><th>Date</th></tr></thead><tbody>';
                    d.recent_trials.forEach(function(t) {
                        var date = t.completed_at ? new Date(t.completed_at).toLocaleDateString('en-TT', {day:'numeric',month:'short',year:'numeric'}) : '—';
                        html += '<tr><td>' + (t.subject||'—') + '</td><td>' + (t.topic||'—') + '</td><td>' + (t.difficulty||'—') + '</td>'
                             + '<td>' + scoreBar(t.percentage) + '</td><td>' + (t.source||'—') + '</td>'
                             + '<td style="font-size:12px;color:#666;">' + date + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                $('#sd-status').hide();
                $('#sd-results').html(html);
            }

            $.post(ajaxurl, {
                action:     'knowly_analytics_student',
                nonce:      nonce,
                user_id:    studentId,
                period:     period,
                subject:    subject,
            }, function(res) {
                if (!res.success) {
                    $('#sd-status').text('Error: ' + (res.data?.message || 'Unknown error')).css('color','#dc2626');
                    return;
                }
                render(res.data);
            }).fail(function() {
                $('#sd-status').text('Request failed — check Railway is reachable.').css('color','#dc2626');
            });
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
    // AJAX: Class analytics
    // =========================================================================

    public static function ajax_class_analytics(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        if ( ! $class_id ) { wp_send_json_error( [ 'message' => 'class_id required' ] ); }

        $child_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND status = 'active'",
            $class_id
        ) ) ?: [] );

        $filters = [];
        if ( ! empty( $_POST['period'] ) )  $filters['period']  = sanitize_text_field( $_POST['period'] );
        if ( ! empty( $_POST['subject'] ) ) $filters['subject'] = sanitize_text_field( $_POST['subject'] );

        $data = Knowly_Analytics_Service::get_class_data( $child_ids, $filters );

        // Merge WP nicknames and levels into per-student rows
        if ( ! empty( $data['students'] ) ) {
            $data['students'] = array_map( function( array $s ): array {
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
    // AJAX: Student analytics
    // =========================================================================

    public static function ajax_student_analytics(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) { wp_send_json_error( [ 'message' => 'user_id required' ] ); }

        $filters = [];
        if ( ! empty( $_POST['period'] ) )  $filters['period']  = sanitize_text_field( $_POST['period'] );
        if ( ! empty( $_POST['subject'] ) ) $filters['subject'] = sanitize_text_field( $_POST['subject'] );

        $data             = Knowly_Analytics_Service::get_student_data( $user_id, $filters );
        $data['nickname'] = get_user_meta( $user_id, 'knowly_nickname', true ) ?: '';
        $data['level']    = get_user_meta( $user_id, 'knowly_level',    true ) ?: '';

        wp_send_json_success( $data );
    }

    // =========================================================================
    // AJAX: Health check
    // =========================================================================

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $checks = [];

        // Analytics tables accessible and readable
        $exam_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_sessions  WHERE state = 'completed'" );
        $quest_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quest_sessions WHERE state = 'completed'" );
        $checks[] = [
            'label'  => 'Analytics data (WP-local)',
            'status' => 'pass',
            'detail' => "{$exam_count} completed trial session(s), {$quest_count} completed quest session(s).",
        ];

        $test_teacher = get_user_by( 'login', 'test.teacher' );
        $checks[] = [
            'label'  => 'Test teacher account',
            'status' => $test_teacher ? 'pass' : 'warn',
            'detail' => $test_teacher ? 'test.teacher exists (ID ' . $test_teacher->ID . ').' : 'Not found.',
        ];

        $class_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes WHERE status = 'active'" );
        $checks[] = [
            'label'  => 'Active classes exist',
            'status' => $class_count > 0 ? 'pass' : 'warn',
            'detail' => "{$class_count} active class(es).",
        ];

        wp_send_json_success( [ 'checks' => $checks ] );
    }

    // =========================================================================
    // AJAX: Purge orphaned student data
    // =========================================================================

    public static function ajax_purge_orphaned_students(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;

        // Find child IDs that no longer have a matching WP user
        $orphan_ids = $wpdb->get_col(
            "SELECT ch.child_id FROM {$wpdb->prefix}knowly_children ch
             LEFT JOIN {$wpdb->users} u ON u.ID = ch.child_id
             WHERE u.ID IS NULL"
        );

        if ( empty( $orphan_ids ) ) {
            wp_send_json_success( [ 'message' => 'No orphaned records found.' ] );
        }

        $id_list   = implode( ',', array_map( 'intval', $orphan_ids ) );
        $count     = count( $orphan_ids );
        $tables    = [
            'knowly_exam_answers'    => 'child_id',
            'knowly_topic_breakdown' => 'child_id',
            'knowly_exam_sessions'   => 'child_id',
            'knowly_quest_sessions'  => 'child_id',
            'knowly_gem_transactions' => 'child_id',
            'knowly_class_members'   => 'child_id',
            'knowly_children'        => 'child_id',
        ];

        foreach ( $tables as $table => $col ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE {$col} IN ({$id_list})" );
        }

        // Clean up WP usermeta for orphan IDs
        foreach ( $orphan_ids as $orphan_id ) {
            $meta_keys = $wpdb->get_col( $wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE 'knowly_%'",
                (int) $orphan_id
            ) );
            foreach ( $meta_keys as $key ) {
                delete_user_meta( (int) $orphan_id, $key );
            }
        }

        Knowly_Debug::log( 'admin.analytics', 'Orphaned student data purged', [
            'count'      => $count,
            'orphan_ids' => $orphan_ids,
        ], null, 'info' );

        wp_send_json_success( [ 'message' => "Purged data for {$count} deleted user(s)." ] );
    }

}
