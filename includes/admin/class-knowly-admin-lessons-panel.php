<?php
/**
 * Knowly_Admin_Lessons_Panel — Lessons admin page.
 *
 * Manages lesson question generation for approved quests.
 * Training content is shared with Quests (wp_knowly_quests).
 * Lesson questions live in Supabase lesson_questions (accessed via Railway).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Lessons_Panel {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_lessons_load_board',   [ __CLASS__, 'ajax_load_board' ] );
        add_action( 'wp_ajax_knowly_lessons_gen_questions', [ __CLASS__, 'ajax_gen_questions' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $nonce      = wp_create_nonce( 'knowly_admin_nonce' );
        $ajax_url   = admin_url( 'admin-ajax.php' );
        ?>
        <div class="wrap knowly-wrap">
            <h1>Lessons</h1>
            <p style="color:#555;margin-top:4px;margin-bottom:20px;">
                Lessons use the same training content as Quests but with separate comprehension questions.
                Students can access any topic freely — no sequential lock.
                Use this panel to generate or regenerate the 3 comprehension questions per lesson module.
            </p>

            <?php if ( ! $railway_ok ) : ?>
            <div class="notice notice-warning inline"><p>Railway endpoint not configured. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a></p></div>
            <?php endif; ?>

            <!-- ── Filters ─────────────────────────────────────────────────── -->
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
                <label style="font-weight:600;font-size:13px;">Level</label>
                <select id="lp-level" style="height:30px;"><option value="">Loading…</option></select>
                <label style="font-weight:600;font-size:13px;">Period</label>
                <select id="lp-period" style="height:30px;"><option value="">Loading…</option></select>
                <label style="font-weight:600;font-size:13px;">Subject</label>
                <select id="lp-subject" style="height:30px;">
                    <option value="">All Subjects</option>
                </select>
                <button id="lp-load-btn" class="button button-primary" style="height:30px;">Load Board</button>
                <span id="lp-loading" style="display:none;color:#888;font-size:12px;">Loading…</span>
            </div>

            <!-- ── Stats row ───────────────────────────────────────────────── -->
            <div id="lp-stats" style="display:none;display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 16px;text-align:center;">
                    <div id="lp-stat-ready" style="font-size:20px;font-weight:700;color:#16a34a;">0</div>
                    <div style="font-size:11px;color:#15803d;">Ready (3 Qs)</div>
                </div>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 16px;text-align:center;">
                    <div id="lp-stat-missing" style="font-size:20px;font-weight:700;color:#d97706;">0</div>
                    <div style="font-size:11px;color:#b45309;">Missing Questions</div>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 16px;text-align:center;">
                    <div id="lp-stat-total" style="font-size:20px;font-weight:700;color:#475569;">0</div>
                    <div style="font-size:11px;color:#64748b;">Approved Lessons</div>
                </div>
            </div>

            <!-- ── Board ──────────────────────────────────────────────────── -->
            <div id="lp-board"></div>
        </div>

        <script>
        (function($) {
            var AJAX_URL = '<?= esc_js( $ajax_url ) ?>';
            var NONCE    = '<?= esc_js( $nonce ) ?>';
            var CAN_GEN  = <?= ( $railway_ok && $server_key ) ? 'true' : 'false' ?>;

            // ── Populate dropdowns from curriculum overview ─────────────────
            $.post( AJAX_URL, { action: 'knowly_curriculum_overview', nonce: NONCE }, function(res) {
                if ( ! res.success ) return;
                var levels  = res.data.levels  || [];
                var periods = res.data.periods || [];

                var $lv = $('#lp-level').empty();
                levels.forEach(function(l) { $lv.append('<option value="' + l + '">' + l + '</option>'); });

                var $pd = $('#lp-period').empty().append('<option value="">All Periods</option>');
                periods.forEach(function(p) { $pd.append('<option value="' + p + '">' + p + '</option>'); });

                if ( levels.length ) loadBoard();
            }).fail(function() {
                // Fallback to hardcoded values
                var $lv = $('#lp-level').empty();
                ['std_1','std_2','std_3','std_4','std_5'].forEach(function(l) { $lv.append('<option value="' + l + '">' + l + '</option>'); });
                var $pd = $('#lp-period').empty().append('<option value="">All Periods</option>');
                ['term_1','term_2','term_3'].forEach(function(p) { $pd.append('<option value="' + p + '">' + p + '</option>'); });
                loadBoard();
            });

            function loadBoard() {
                var level   = $('#lp-level').val();
                var period  = $('#lp-period').val();
                var subject = $('#lp-subject').val();

                if ( ! level ) return;
                $('#lp-loading').show();
                $('#lp-board').empty();
                $('#lp-stats').hide();

                $.post( AJAX_URL, {
                    action:  'knowly_lessons_load_board',
                    nonce:   NONCE,
                    level:   level,
                    period:  period,
                    subject: subject,
                }, function(res) {
                    $('#lp-loading').hide();
                    if ( ! res.success ) { $('#lp-board').html('<p style="color:#d63638;">Error: ' + (res.data.message || 'Failed to load') + '</p>'); return; }
                    renderBoard( res.data );
                });
            }

            function renderBoard( data ) {
                var lessons       = data.lessons       || [];
                var subjectFilter = $('#lp-subject').val();

                // Populate subject dropdown once
                var subjects = {};
                lessons.forEach(function(l) { if ( l.subject ) subjects[l.subject] = true; });
                var $subj = $('#lp-subject').empty().append('<option value="">All Subjects</option>');
                Object.keys(subjects).sort().forEach(function(s) {
                    var sel = s === subjectFilter ? ' selected' : '';
                    $subj.append('<option value="' + s + '"' + sel + '>' + s + '</option>');
                });

                var filtered = subjectFilter ? lessons.filter(function(l) { return l.subject === subjectFilter; }) : lessons;
                var ready    = filtered.filter(function(l) { return l.q_count >= 3; }).length;
                var missing  = filtered.length - ready;

                $('#lp-stat-ready').text(ready);
                $('#lp-stat-missing').text(missing);
                $('#lp-stat-total').text(filtered.length);
                $('#lp-stats').show();

                if ( ! filtered.length ) {
                    $('#lp-board').html('<p style="color:#888;">No approved lessons found for this scope.</p>');
                    return;
                }

                // Group by subject
                var bySubject = {};
                filtered.forEach(function(l) {
                    var s = l.subject || 'Unknown';
                    if ( ! bySubject[s] ) bySubject[s] = [];
                    bySubject[s].push(l);
                });

                var html = '';
                Object.keys(bySubject).sort().forEach(function(subj) {
                    html += '<h3 style="text-transform:capitalize;margin:20px 0 8px;">' + subj + '</h3>';
                    html += '<table class="wp-list-table widefat fixed striped" style="font-size:12px;">';
                    html += '<thead><tr><th style="width:40px;">#</th><th>Module / Topic</th><th style="width:130px;">Questions</th><th style="width:160px;">Actions</th></tr></thead><tbody>';

                    bySubject[subj].forEach(function(l) {
                        var qid  = l.quest_id || '';
                        var cnt  = l.q_count  || 0;
                        var badge = cnt >= 3
                            ? '<span style="background:#dcfce7;color:#16a34a;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;">3 Qs ✓</span>'
                            : '<span style="background:#fef9c3;color:#a16207;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;">' + cnt + '/3 Qs</span>';

                        var genBtn = ( CAN_GEN && qid )
                            ? '<button class="button button-small lp-gen-q-btn" '
                              + 'data-quest-id="' + esc(qid) + '" '
                              + 'data-level="' + esc(l.level||'') + '" '
                              + 'data-period="' + esc(l.period||'') + '" '
                              + 'data-subject="' + esc(l.subject||'') + '" '
                              + 'data-module-title="' + esc(l.module_title||l.topic||'') + '" '
                              + 'style="margin-left:6px;">'
                              + ( cnt >= 3 ? '↻ Regen Qs' : 'Generate Qs' )
                              + '</button>'
                            : '';

                        var mod = l.module_number ? '#' + l.module_number + ' — ' : '';

                        html += '<tr data-quest-id="' + esc(qid) + '">'
                             + '<td style="color:#888;">' + ( l.module_number || '—' ) + '</td>'
                             + '<td>' + esc( mod + ( l.module_title || l.topic || '—' ) ) + '</td>'
                             + '<td class="lp-qcount-cell">' + badge + '</td>'
                             + '<td>' + genBtn + '</td>'
                             + '</tr>';
                    });

                    html += '</tbody></table>';
                });

                $('#lp-board').html(html);
            }

            // ── Generate Questions ─────────────────────────────────────────
            $(document).on('click', '.lp-gen-q-btn', function() {
                var $btn  = $(this);
                var qid   = $btn.data('quest-id');
                var level = $btn.data('level');
                var period  = $btn.data('period');
                var subject = $btn.data('subject');
                var title   = $btn.data('module-title');

                $btn.prop('disabled', true).text('Generating…');

                $.post( AJAX_URL, {
                    action:       'knowly_lessons_gen_questions',
                    nonce:        NONCE,
                    quest_id:     qid,
                    level:        level,
                    period:       period,
                    subject:      subject,
                    module_title: title,
                }, function(res) {
                    if ( res.success ) {
                        var cnt  = res.data.question_count || 3;
                        var $row = $btn.closest('tr');
                        $row.find('.lp-qcount-cell').html(
                            '<span style="background:#dcfce7;color:#16a34a;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;">' + cnt + ' Qs ✓</span>'
                        );
                        $btn.prop('disabled', false).text('↻ Regen Qs');
                    } else {
                        alert( 'Generation failed: ' + ( res.data.message || 'Unknown error' ) );
                        $btn.prop('disabled', false).text('Generate Qs');
                    }
                });
            });

            // ── Event bindings ─────────────────────────────────────────────
            $('#lp-load-btn').on('click', loadBoard);
            $('#lp-subject').on('change', loadBoard);

            function esc(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Load Board ──────────────────────────────────────────────────────

    public static function ajax_load_board(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        $level   = sanitize_text_field( $_POST['level']   ?? '' );
        $period  = sanitize_text_field( $_POST['period']  ?? '' );
        $subject = sanitize_text_field( $_POST['subject'] ?? '' );

        if ( ! $level ) {
            wp_send_json_error( [ 'message' => 'level is required.' ] );
            return;
        }

        $table = $wpdb->prefix . 'knowly_quests';
        $sql   = "SELECT quest_id, module_number, module_title, topic, subject, level, period, status
                  FROM {$table}
                  WHERE curriculum = %s AND level = %s AND variant = 'student' AND status = 'approved'";
        $args  = [ $curriculum, $level ];

        if ( $period ) {
            $sql   .= ' AND period = %s';
            $args[] = $period;
        }
        if ( $subject ) {
            $sql   .= ' AND subject = %s';
            $args[] = $subject;
        }

        $sql .= ' ORDER BY subject ASC, COALESCE(module_number, 9999) ASC';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $quests = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        // Fetch lesson question counts from Railway
        $lq_counts = [];
        if ( ! empty( $quests ) && $endpoint && $server_key ) {
            foreach ( $quests as $q ) {
                $qid  = $q['quest_id'];
                $resp = wp_remote_get( $endpoint . '/api/v1/lesson/' . rawurlencode( $qid ) . '/questions', [
                    'timeout' => 5,
                    'headers' => [ 'X-AEP-Server-Key' => $server_key ],
                ] );
                if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                    $parsed             = json_decode( wp_remote_retrieve_body( $resp ), true );
                    $lq_counts[ $qid ]  = (int) ( $parsed['count'] ?? 0 );
                }
            }
        }

        $lessons = array_map( function( $q ) use ( $lq_counts ) {
            $q['q_count'] = $lq_counts[ $q['quest_id'] ] ?? 0;
            return $q;
        }, $quests );

        wp_send_json_success( [ 'lessons' => $lessons ] );
    }

    // ── AJAX: Generate Lesson Questions ──────────────────────────────────────

    public static function ajax_gen_questions(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $quest_id     = sanitize_text_field( $_POST['quest_id']     ?? '' );
        $level        = sanitize_text_field( $_POST['level']        ?? '' );
        $period       = sanitize_text_field( $_POST['period']       ?? '' );
        $subject      = sanitize_text_field( $_POST['subject']      ?? '' );
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
            'level'        => $level,
            'period'       => $period ?: null,
            'subject'      => $subject,
            'quest_id'     => $quest_id,
            'module_title' => $module_title,
            'topics'       => [],
        ];

        $resp = wp_remote_post( $endpoint . '/api/v1/lesson/generate-questions', [
            'timeout' => 60,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
            return;
        }

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
}
