<?php
/**
 * Knowly_Admin_QB — Question Bank admin page.
 *
 * Slot board driven by curriculum_topics — one row per module, three difficulty
 * columns (easy / medium / hard). Seeding fires to Railway asynchronously;
 * Re-check reloads counts from the question_bank table.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_QB {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_qb_load_board', [ __CLASS__, 'ajax_load_board' ] );
        add_action( 'wp_ajax_knowly_qb_generate',   [ __CLASS__, 'ajax_generate'   ] );
        add_action( 'wp_ajax_knowly_qb_browse',     [ __CLASS__, 'ajax_browse'     ] );
        add_action( 'wp_ajax_knowly_qb_retire',     [ __CLASS__, 'ajax_retire'     ] );
    }

    // ── Railway helpers ───────────────────────────────────────────────────────

    private static function railway_get( string $path, array $params = [] ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) return [ 'error' => 'Railway endpoint not configured.' ];

        $url = $endpoint . $path;
        if ( $params ) $url .= '?' . http_build_query( $params );

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) return [ 'error' => $response->get_error_message() ];
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) return [ 'error' => $body['error'] ?? "HTTP {$code}" ];
        return $body ?: [];
    }

    private static function railway_post_nowait( string $path, array $body ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) return [ 'error' => 'Railway endpoint not configured.' ];

        $response = wp_remote_post( $endpoint . $path, [
            'blocking' => false,
            'timeout'  => 1,
            'headers'  => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) return [ 'error' => $response->get_error_message() ];
        return [ 'queued' => true ];
    }

    // ── Page render ───────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $server_key = get_option( 'knowly_railway_server_key', '' );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $nonce      = wp_create_nonce( 'knowly_admin_nonce' );
        $can_gen    = ( $railway_ok && $server_key ) ? 'true' : 'false';
        $tab        = sanitize_key( $_GET['tab'] ?? 'generate' );
        $tabs       = [ 'generate' => 'Generate', 'browse' => 'Browse & Retire' ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Question Bank</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-question-bank&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( ! $railway_ok ) : ?>
            <div class="notice notice-warning inline" style="margin:8px 0 0;"><p>
                Railway endpoint not configured.
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a>
            </p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php if ( $tab === 'browse' ) : ?>
                <?php self::render_browse( $nonce ); ?>
            <?php else : ?>

            <p style="color:#555;margin-bottom:16px;">
                Slot board driven by <code>curriculum_topics</code> — one row per module, three difficulty columns.
                Target: <strong>30 active questions</strong> per slot.
                Generation fires asynchronously (~1–2 min); click <strong>↻ Re-check</strong> to refresh counts.
            </p>

            <div id="qb-generating-notice" style="display:none;padding:8px 12px;background:#fef3c7;border:1px solid #d97706;border-radius:4px;margin-bottom:12px;font-size:12px;color:#92400e;">
                ⏳ Generation in progress. Click <strong>↻ Re-check</strong> after ~1–2 minutes to see updated counts.
            </div>

            <!-- ── Controls ──────────────────────────────────────────────── -->
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
                <select id="qb-level" style="height:30px;">
                    <option value="">Loading…</option>
                </select>
                <select id="qb-period" style="height:30px;">
                    <option value="">Loading…</option>
                </select>
                <button id="qb-load" class="button button-primary" <?= $can_gen === 'true' ? '' : 'disabled' ?>>
                    ↓ Load Bank
                </button>
                <button id="qb-recheck" class="button" <?= $can_gen === 'true' ? '' : 'disabled' ?>>
                    ↻ Re-check
                </button>
                <span id="qb-summary" style="color:#666;font-size:13px;"></span>
            </div>

            <!-- ── Subject tabs + slot table (populated by JS) ────────────── -->
            <div id="qb-board-wrap" style="display:none;">
                <div id="qb-subject-tabs" style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:0;"></div>
                <div id="qb-board-table" style="border:1px solid #e5e7eb;border-top:none;padding:16px;background:#fafafa;min-height:80px;"></div>
            </div>
            <div id="qb-placeholder"><p style="color:#888;">Select level and period, then click "↓ Load Bank".</p></div>

        <script>
        (function($) {
            var nonce      = '<?= esc_js( $nonce ) ?>';
            var canGen     = <?= $can_gen ?>;
            var boardData  = null;
            var activeSubj = null;
            var levelsData = {};
            // Tracks slots currently generating: key = "module_number::difficulty"
            var generating = {};

            function buildPeriodOpts(lv) {
                var perOpts  = '';
                var termKeys = Object.keys(lv.period_map || {}).sort();
                termKeys.forEach(function(p) {
                    perOpts += '<option value="' + p + '">' + p.replace(/term_(\d+)/, 'Term $1') + '</option>';
                });
                if (lv.capstone) {
                    perOpts += '<option value="">capstone</option>';
                }
                $('#qb-period').html(perOpts || '<option value="">capstone</option>');
            }

            function initDropdowns() {
                $.post(ajaxurl, { action: 'knowly_curriculum_overview', nonce: nonce }, function(res) {
                    if (!res.success || !(res.data.levels || []).length) {
                        $('#qb-level').html('<option value="std_4" selected>std_4</option><option value="std_3">std_3</option><option value="std_5">std_5</option>');
                        $('#qb-period').html('<option value="term_1" selected>term_1</option><option value="term_2">term_2</option><option value="term_3">term_3</option><option value="">capstone</option>');
                        loadBoard(null);
                        return;
                    }
                    var levels  = res.data.levels || [];
                    var lvlOpts = '';
                    levels.forEach(function(l, i) {
                        levelsData[l.slug] = l;
                        lvlOpts += '<option value="' + l.slug + '"' + (i === 0 ? ' selected' : '') + '>'
                            + escHtml(l.label) + ' (' + l.slug + ')</option>';
                    });
                    $('#qb-level').html(lvlOpts);
                    if (levels.length) buildPeriodOpts(levels[0]);
                    loadBoard(null);
                }).fail(function() {
                    $('#qb-level').html('<option value="std_4" selected>std_4</option>');
                    $('#qb-period').html('<option value="term_1" selected>term_1</option><option value="">capstone</option>');
                    loadBoard(null);
                });
            }

            // ── Helpers ───────────────────────────────────────────────────────

            function countBadge(active) {
                if (active >= 25) return '<span style="font-size:12px;background:#dcfce7;color:#166534;padding:2px 7px;border-radius:3px;font-weight:600;">✅ ' + active + '</span>';
                if (active >= 1)  return '<span style="font-size:12px;background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:3px;font-weight:600;">⚠️ ' + active + '</span>';
                return '<span style="font-size:12px;background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:3px;font-weight:600;">❌ 0</span>';
            }

            function genKey(mn, diff) { return mn + '::' + diff; }

            function renderTabs(subjects, active) {
                var html = '';
                subjects.forEach(function(s) {
                    var label = s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ');
                    var isActive = s === active;
                    html += '<button class="qb-subj-tab" data-subject="' + s + '" style="'
                        + 'padding:8px 20px;border:none;cursor:pointer;font-size:13px;background:transparent;'
                        + (isActive
                            ? 'border-bottom:3px solid #2563eb;color:#2563eb;font-weight:600;margin-bottom:-2px;'
                            : 'border-bottom:3px solid transparent;color:#6b7280;')
                        + '">' + label + '</button>';
                });
                $('#qb-subject-tabs').html(html);
            }

            function renderBoard(data, subject) {
                var modules = data.modules || [];
                if (!modules.length) {
                    $('#qb-board-table').html('<p style="color:#888;padding:8px 0;">No curriculum modules found for this scope. Import training data first (Training tab).</p>');
                    $('#qb-summary').text('');
                    return;
                }

                var fullCount  = modules.filter(function(m) { return m.easy >= 25 && m.medium >= 25 && m.hard >= 25; }).length;
                var totalSlots = modules.length * 3;
                var seeded     = modules.reduce(function(n, m) {
                    return n + (m.easy >= 25 ? 1 : 0) + (m.medium >= 25 ? 1 : 0) + (m.hard >= 25 ? 1 : 0);
                }, 0);
                $('#qb-summary').text(seeded + ' / ' + totalSlots + ' slots seeded · ' + subject.replace(/_/g,' '));

                var level  = data.level;
                var period = data.period || '';

                var html = '<table class="widefat" style="font-size:12px;border-collapse:collapse;">'
                    + '<thead><tr style="background:#f3f4f6;">'
                    + '<th style="padding:8px 10px;width:38px;">#</th>'
                    + '<th style="padding:8px 10px;">Module Title</th>'
                    + '<th style="padding:8px 10px;width:80px;text-align:center;">Easy</th>'
                    + '<th style="padding:8px 10px;width:80px;text-align:center;">Medium</th>'
                    + '<th style="padding:8px 10px;width:80px;text-align:center;">Hard</th>'
                    + '<th style="padding:8px 10px;width:220px;">Actions</th>'
                    + '</tr></thead><tbody>';

                modules.forEach(function(mod, i) {
                    var bg = i % 2 === 0 ? '#fff' : '#f9fafb';
                    var mn = mod.module_number;

                    function diffCell(diff, active) {
                        var key = genKey(mn, diff);
                        if (generating[key]) {
                            return '<td style="padding:8px 10px;text-align:center;"><span style="font-size:11px;color:#0369a1;">⏳</span></td>';
                        }
                        return '<td id="qb-cell-' + mn + '-' + diff + '" style="padding:8px 10px;text-align:center;">' + countBadge(active) + '</td>';
                    }

                    var diffs  = ['easy','medium','hard'];
                    var actives = { easy: mod.easy, medium: mod.medium, hard: mod.hard };
                    var actions = '';

                    if (canGen) {
                        var needsDiffs = diffs.filter(function(d) { return actives[d] < 25 && !generating[genKey(mn,d)]; });
                        if (needsDiffs.length > 0) {
                            needsDiffs.forEach(function(d) {
                                actions += '<button class="button button-small qb-gen-btn" style="margin:1px 2px;"'
                                    + ' data-level="' + level + '"'
                                    + ' data-period="' + period + '"'
                                    + ' data-subject="' + subject + '"'
                                    + ' data-module-num="' + mn + '"'
                                    + ' data-difficulty="' + d + '">'
                                    + d.charAt(0).toUpperCase() + d.slice(1) + '</button>';
                            });
                            if (needsDiffs.length === 3) {
                                actions += ' <button class="button button-small qb-gen-all-btn" style="margin:1px 2px;background:#2271b1;border-color:#2271b1;color:#fff;"'
                                    + ' data-level="' + level + '"'
                                    + ' data-period="' + period + '"'
                                    + ' data-subject="' + subject + '"'
                                    + ' data-module-num="' + mn + '">All 3</button>';
                            }
                        } else if (!needsDiffs.length) {
                            actions = '<span style="font-size:11px;color:#9ca3af;">✓ Fully seeded</span>';
                        }
                    }

                    html += '<tr style="background:' + bg + ';border-bottom:1px solid #e5e7eb;">'
                        + '<td style="padding:8px 10px;color:#9ca3af;">' + mn + '</td>'
                        + '<td style="padding:8px 10px;">' + escHtml(mod.module_title) + '</td>'
                        + diffCell('easy',   mod.easy)
                        + diffCell('medium', mod.medium)
                        + diffCell('hard',   mod.hard)
                        + '<td id="qb-actions-' + mn + '" style="padding:8px 10px;">' + actions + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                $('#qb-board-table').html(html);
            }

            function escHtml(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            // ── AJAX calls ────────────────────────────────────────────────────

            function loadBoard(subject) {
                var level  = $('#qb-level').val();
                var period = $('#qb-period').val();
                $('#qb-load').prop('disabled', true).text('Loading…');
                $('#qb-placeholder').hide();
                $('#qb-board-table').html('<p style="color:#888;padding:8px 0;">Loading…</p>');
                $('#qb-board-wrap').show();

                $.post(ajaxurl, {
                    action: 'knowly_qb_load_board',
                    nonce:   nonce,
                    level:   level,
                    period:  period,
                    subject: subject || '',
                }, function(res) {
                    $('#qb-load').prop('disabled', false).text('↓ Load Bank');
                    if (!res.success) {
                        $('#qb-board-table').html('<p style="color:#dc2626;">Error: ' + escHtml(res.data.message || 'Unknown error') + '</p>');
                        return;
                    }
                    boardData  = res.data;
                    var subjects = res.data.subjects || [];
                    if (!activeSubj || subjects.indexOf(activeSubj) === -1) activeSubj = subjects[0] || null;
                    renderTabs(subjects, activeSubj);
                    renderBoard(res.data, activeSubj);
                });
            }

            function fireGenerate(level, period, subject, mn, difficulty) {
                var key = genKey(mn, difficulty);
                generating[key] = true;

                // Visually mark the cell
                var diffStr = difficulty;
                $('#qb-cell-' + mn + '-' + diffStr).html('<span style="font-size:11px;color:#0369a1;">⏳</span>');
                $('#qb-generating-notice').show();

                $.post(ajaxurl, {
                    action:        'knowly_qb_generate',
                    nonce:          nonce,
                    level:          level,
                    period:         period,
                    subject:        subject,
                    module_number:  mn,
                    difficulty:     difficulty,
                }, function(res) {
                    if (!res.success) {
                        generating[key] = false;
                        alert('Generate failed: ' + escHtml(res.data.message || 'Unknown error'));
                    }
                    // On success we leave generating[key]=true until Re-check confirms counts updated
                });
            }

            // ── Event handlers ────────────────────────────────────────────────

            $('#qb-level').on('change', function() {
                var lv = levelsData[$(this).val()];
                if (lv) buildPeriodOpts(lv);
                activeSubj = null;
            });

            $('#qb-load').on('click', function() { generating = {}; loadBoard(activeSubj); });

            $('#qb-recheck').on('click', function() {
                generating = {};
                $('#qb-generating-notice').hide();
                loadBoard(activeSubj);
            });

            $(document).on('click', '.qb-subj-tab', function() {
                activeSubj = $(this).data('subject');
                if (boardData) {
                    renderTabs(boardData.subjects || [], activeSubj);
                    renderBoard(boardData, activeSubj);
                    // Reload counts for new subject
                    loadBoard(activeSubj);
                }
            });

            $(document).on('click', '.qb-gen-btn', function() {
                var $btn   = $(this);
                var level  = $btn.data('level');
                var period = $btn.data('period');
                var subj   = $btn.data('subject');
                var mn     = parseInt($btn.data('module-num'), 10);
                var diff   = $btn.data('difficulty');
                $btn.prop('disabled', true);
                fireGenerate(level, period, subj, mn, diff);
                // Rebuild actions cell to remove the now-generating button
                $btn.closest('td').find('button[data-difficulty="' + diff + '"]').remove();
            });

            $(document).on('click', '.qb-gen-all-btn', function() {
                var $btn   = $(this);
                var level  = $btn.data('level');
                var period = $btn.data('period');
                var subj   = $btn.data('subject');
                var mn     = parseInt($btn.data('module-num'), 10);
                $btn.closest('td').html('<span style="font-size:11px;color:#0369a1;">⏳ All generating…</span>');
                ['easy','medium','hard'].forEach(function(diff) {
                    fireGenerate(level, period, subj, mn, diff);
                });
            });

            // Populate dropdowns dynamically, then auto-load
            initDropdowns();

        })(jQuery);
        </script>
        <?php
            endif; // end generate tab
            ?>
            </div><!-- /tab panel -->
        </div><!-- /wrap -->
        <?php
    }

    // =========================================================================
    // Browse & Retire Tab
    // =========================================================================

    private static function render_browse( string $nonce ): void {
        ?>
        <div style="display:flex;align-items:flex-end;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
            <?php
            $selects = [
                [ 'id' => 'br-level',   'label' => 'Level',      'opts' => [ 'std_4' => 'std_4', 'std_5' => 'std_5', 'std_3' => 'std_3' ] ],
                [ 'id' => 'br-period',  'label' => 'Period',     'opts' => [ 'term_1' => 'Term 1', 'term_2' => 'Term 2', 'term_3' => 'Term 3', '' => 'Capstone' ] ],
                [ 'id' => 'br-subject', 'label' => 'Subject',    'opts' => [ 'math' => 'Math', 'english' => 'English', 'science' => 'Science', 'social_studies' => 'Social Studies' ] ],
                [ 'id' => 'br-module',  'label' => 'Module',     'opts' => [ '' => 'All Modules' ] + array_combine( range(1,10), array_map( fn($n) => "Module {$n}", range(1,10) ) ) ],
                [ 'id' => 'br-diff',    'label' => 'Difficulty', 'opts' => [ '' => 'All', 'easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard' ] ],
                [ 'id' => 'br-status',  'label' => 'Status',     'opts' => [ 'active' => 'Active', 'retired' => 'Retired', 'all' => 'All' ] ],
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
            <button id="br-load" class="button button-primary" style="height:30px;">Load Questions</button>
        </div>

        <div id="br-summary" style="font-size:12px;color:#666;margin-bottom:10px;"></div>
        <div id="br-table-wrap"><p style="color:#888;">Select filters and click "Load Questions".</p></div>
        <div id="br-pagination" style="margin-top:12px;display:flex;gap:8px;align-items:center;font-size:12px;"></div>

        <div id="br-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;overflow:auto;">
            <div style="background:#fff;max-width:760px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                <div style="padding:12px 18px;background:#f6f7f7;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                    <h2 id="br-modal-title" style="margin:0;font-size:14px;"></h2>
                    <button id="br-modal-close" class="button" style="min-width:0;padding:2px 10px;">✕</button>
                </div>
                <div id="br-modal-body" style="padding:20px;max-height:70vh;overflow:auto;font-size:13px;"></div>
            </div>
        </div>

        <script>
        (function($) {
            var nonce     = '<?= esc_js( $nonce ) ?>';
            var curPage   = 1;

            function diffColor(d) {
                return d === 'easy' ? '#16a34a' : d === 'medium' ? '#d97706' : d === 'hard' ? '#dc2626' : '#374151';
            }
            function statusBadge(s) {
                if (s === 'active')   return '<span style="background:#dcfce7;color:#166534;border-radius:10px;padding:1px 8px;font-size:10px;font-weight:600;">active</span>';
                if (s === 'retired')  return '<span style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:1px 8px;font-size:10px;font-weight:600;">retired</span>';
                return '<span style="background:#fef9c3;color:#854d0e;border-radius:10px;padding:1px 8px;font-size:10px;">' + s + '</span>';
            }
            function escHtml(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function loadQuestions(page) {
                curPage = page || 1;
                $('#br-load').prop('disabled', true).text('Loading…');
                $('#br-table-wrap').html('<p style="color:#888;">Loading…</p>');
                $.post(ajaxurl, {
                    action:        'knowly_qb_browse',
                    nonce:         nonce,
                    level:         $('#br-level').val(),
                    period:        $('#br-period').val(),
                    subject:       $('#br-subject').val(),
                    module_number: $('#br-module').val(),
                    difficulty:    $('#br-diff').val(),
                    status:        $('#br-status').val(),
                    page:          curPage,
                }, function(res) {
                    $('#br-load').prop('disabled', false).text('Load Questions');
                    if (!res.success) {
                        $('#br-table-wrap').html('<p style="color:#dc2626;">' + escHtml(res.data && res.data.message ? res.data.message : 'Failed') + '</p>');
                        return;
                    }
                    var d = res.data;
                    $('#br-summary').text(d.total + ' question(s) — page ' + d.page + ' of ' + d.pages);

                    if (!d.questions || !d.questions.length) {
                        $('#br-table-wrap').html('<p style="color:#666;">No questions match the current filters.</p>');
                        $('#br-pagination').html('');
                        return;
                    }

                    var html = '<table class="knowly-table widefat" style="font-size:12px;">'
                        + '<thead><tr>'
                        + '<th style="width:36px;">#</th>'
                        + '<th>Question</th>'
                        + '<th style="width:60px;text-align:center;">Mod</th>'
                        + '<th style="width:60px;text-align:center;">Diff</th>'
                        + '<th style="width:55px;text-align:center;">Served</th>'
                        + '<th style="width:80px;text-align:center;">Status</th>'
                        + '<th style="width:110px;">Actions</th>'
                        + '</tr></thead><tbody>';

                    d.questions.forEach(function(q, i) {
                        var num    = (d.page - 1) * 25 + i + 1;
                        var trunc  = q.question.length > 90 ? q.question.substring(0, 90) + '…' : q.question;
                        var retireBtn = q.status === 'active'
                            ? '<button class="button button-small br-retire-btn" style="color:#dc2626;" data-id="' + q.id + '" data-status="retired">Retire</button>'
                            : '<button class="button button-small br-retire-btn" style="color:#16a34a;" data-id="' + q.id + '" data-status="active">Restore</button>';
                        html += '<tr id="br-row-' + q.id + '">'
                            + '<td style="color:#9ca3af;">' + num + '</td>'
                            + '<td><a href="#" class="br-view-btn" data-id="' + q.id + '" data-idx="' + i + '" style="color:#1d4ed8;">' + escHtml(trunc) + '</a></td>'
                            + '<td style="text-align:center;">' + q.module_number + '</td>'
                            + '<td style="text-align:center;color:' + diffColor(q.difficulty) + ';font-weight:600;text-transform:capitalize;">' + q.difficulty + '</td>'
                            + '<td style="text-align:center;color:#6b7280;">' + (q.times_served || 0) + '</td>'
                            + '<td style="text-align:center;">' + statusBadge(q.status) + '</td>'
                            + '<td>' + retireBtn + '</td>'
                            + '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#br-table-wrap').html(html).data('questions', d.questions);

                    var pHtml = '';
                    if (d.page > 1)       pHtml += '<button class="button button-small br-page" data-page="' + (d.page-1) + '">← Prev</button>';
                    pHtml += '<span style="color:#666;padding:0 4px;">Page ' + d.page + ' / ' + d.pages + '</span>';
                    if (d.page < d.pages) pHtml += '<button class="button button-small br-page" data-page="' + (d.page+1) + '">Next →</button>';
                    $('#br-pagination').html(pHtml);
                });
            }

            // View question detail modal
            $(document).on('click', '.br-view-btn', function(e) {
                e.preventDefault();
                var idx = $(this).data('idx');
                var questions = $('#br-table-wrap').data('questions') || [];
                var q = questions[idx];
                if (!q) return;
                var opts = q.options || {};
                var optHtml = ['a','b','c','d'].map(function(k) {
                    var K = k.toUpperCase();
                    var val = opts[K] || opts[k] || '';
                    var isCor = (q.correct_answer || '').toUpperCase() === K;
                    return '<div style="padding:6px 10px;margin-bottom:4px;border-radius:4px;background:' + (isCor ? '#dcfce7' : '#f9fafb') + ';border:1px solid ' + (isCor ? '#bbf7d0' : '#e5e7eb') + ';">'
                        + '<strong style="color:' + (isCor ? '#166534' : '#374151') + ';">' + K + '.</strong> ' + escHtml(val)
                        + (isCor ? ' <span style="color:#16a34a;font-weight:600;margin-left:6px;">✓ Correct</span>' : '')
                        + '</div>';
                }).join('');
                var meta = '<div style="display:flex;gap:14px;font-size:11px;color:#6b7280;margin-top:10px;flex-wrap:wrap;">'
                    + '<span>Module <strong>' + q.module_number + '</strong></span>'
                    + '<span style="text-transform:capitalize;color:' + diffColor(q.difficulty) + ';font-weight:600;">' + q.difficulty + '</span>'
                    + '<span>Served <strong>' + (q.times_served || 0) + '</strong>×</span>'
                    + (q.cognitive_level ? '<span>Cognitive: <strong>' + q.cognitive_level + '</strong></span>' : '')
                    + '</div>';
                $('#br-modal-title').text(q.module_title || 'Module ' + q.module_number);
                $('#br-modal-body').html(
                    '<p style="font-weight:600;margin-bottom:12px;">' + escHtml(q.question) + '</p>'
                    + optHtml + meta
                );
                $('#br-modal').show();
            });

            // Retire / Restore
            $(document).on('click', '.br-retire-btn', function() {
                var $btn    = $(this).prop('disabled', true).text('…');
                var id      = $(this).data('id');
                var newStat = $(this).data('status');
                var label   = newStat === 'retired' ? 'Retire' : 'Restore';
                if (!confirm((newStat === 'retired' ? 'Retire' : 'Restore') + ' this question?')) {
                    $btn.prop('disabled', false).text(label);
                    return;
                }
                $.post(ajaxurl, {
                    action:  'knowly_qb_retire',
                    nonce:   nonce,
                    id:      id,
                    status:  newStat,
                }, function(res) {
                    if (!res.success) {
                        $btn.prop('disabled', false).text(label);
                        alert('Error: ' + (res.data && res.data.message ? res.data.message : 'Failed'));
                        return;
                    }
                    // Update the row in place
                    var $row = $('#br-row-' + id);
                    var updatedStatus = res.data.status;
                    $row.find('td:nth-child(6)').html(statusBadge(updatedStatus));
                    var newBtn = updatedStatus === 'active'
                        ? '<button class="button button-small br-retire-btn" style="color:#dc2626;" data-id="' + id + '" data-status="retired">Retire</button>'
                        : '<button class="button button-small br-retire-btn" style="color:#16a34a;" data-id="' + id + '" data-status="active">Restore</button>';
                    $row.find('td:last-child').html(newBtn);
                });
            });

            // Pagination
            $(document).on('click', '.br-page', function() { loadQuestions($(this).data('page')); });

            // Load on filter change + button
            $('#br-load').on('click', function() { loadQuestions(1); });
            $('#br-level, #br-period, #br-subject, #br-module, #br-diff, #br-status').on('change', function() { loadQuestions(1); });

            // Modal close
            $('#br-modal-close').on('click', function() { $('#br-modal').hide(); });
            $('#br-modal').on('click', function(e) { if ($(e.target).is(this)) $(this).hide(); });

        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Load Board ──────────────────────────────────────────────────────

    public static function ajax_load_board(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level      = sanitize_key( $_POST['level']   ?? 'std_4' ) ?: 'std_4';
        $period     = sanitize_key( $_POST['period']  ?? '' );
        $subject_in = sanitize_key( $_POST['subject'] ?? '' );
        $curriculum = 'tt_primary';

        // ── Step 1: get distinct subjects from curriculum_topics ──────────────
        $params = [ 'curriculum' => $curriculum, 'level' => $level ];
        if ( $period ) $params['period'] = $period;

        $topics_resp = self::railway_get( '/api/v1/curriculum-topics', $params );

        if ( isset( $topics_resp['error'] ) ) {
            wp_send_json_error( [ 'message' => $topics_resp['error'] ] );
        }

        $items = $topics_resp['items'] ?? [];

        // Extract distinct subjects
        $subjects = array_values( array_unique( array_filter( array_column( $items, 'subject' ) ) ) );
        sort( $subjects );

        if ( empty( $subjects ) ) {
            wp_send_json_success( [
                'subjects' => [],
                'level'    => $level,
                'period'   => $period ?: null,
                'subject'  => null,
                'modules'  => [],
            ] );
        }

        $subject = ( $subject_in && in_array( $subject_in, $subjects, true ) ) ? $subject_in : $subjects[0];

        // ── Step 2: get slot counts from question_bank ────────────────────────
        $list_params = [
            'curriculum' => $curriculum,
            'level'      => $level,
            'subject'    => $subject,
        ];
        if ( $period ) $list_params['period'] = $period;

        $list_resp = self::railway_get( '/api/v1/question-bank/list', $list_params );

        if ( isset( $list_resp['error'] ) ) {
            wp_send_json_error( [ 'message' => $list_resp['error'] ] );
        }

        $slots = $list_resp['slots'] ?? [];

        // Re-group flat slots (one per module × difficulty) into per-module rows
        $module_map = [];
        foreach ( $slots as $slot ) {
            $mn = (int) ( $slot['module_number'] ?? 0 );
            if ( ! $mn ) continue;
            if ( ! isset( $module_map[ $mn ] ) ) {
                $module_map[ $mn ] = [
                    'module_number' => $mn,
                    'module_title'  => $slot['module_title'] ?? "Module {$mn}",
                    'easy'          => 0,
                    'medium'        => 0,
                    'hard'          => 0,
                ];
            }
            $diff = $slot['difficulty'] ?? '';
            if ( in_array( $diff, [ 'easy', 'medium', 'hard' ], true ) ) {
                $module_map[ $mn ][ $diff ] = (int) ( $slot['active_count'] ?? 0 );
            }
        }

        ksort( $module_map );
        $modules = array_values( $module_map );

        wp_send_json_success( [
            'subjects' => $subjects,
            'level'    => $level,
            'period'   => $period ?: null,
            'subject'  => $subject,
            'modules'  => $modules,
        ] );
    }

    // ── AJAX: Generate slot ───────────────────────────────────────────────────

    public static function ajax_generate(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level         = sanitize_key( $_POST['level']         ?? '' );
        $period        = sanitize_key( $_POST['period']        ?? '' );
        $subject       = sanitize_key( $_POST['subject']       ?? '' );
        $module_number = (int) ( $_POST['module_number'] ?? 0 );
        $difficulty    = sanitize_key( $_POST['difficulty']    ?? '' );

        if ( ! $level || ! $subject || ! $module_number || ! $difficulty ) {
            wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
        }

        if ( ! in_array( $difficulty, [ 'easy', 'medium', 'hard' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid difficulty.' ] );
        }

        $result = self::railway_post_nowait( '/api/v1/question-bank/generate', [
            'curriculum'    => 'tt_primary',
            'level'         => $level,
            'period'        => $period ?: null,
            'subject'       => $subject,
            'module_number' => $module_number,
            'difficulty'    => $difficulty,
            'count'         => 30,
            'sync'          => false,
        ] );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        Knowly_Debug::log( 'admin.qb', 'QB generation fired', [
            'level'         => $level,
            'period'        => $period,
            'subject'       => $subject,
            'module_number' => $module_number,
            'difficulty'    => $difficulty,
        ], null, 'info' );

        wp_send_json_success( [ 'queued' => true, 'module_number' => $module_number, 'difficulty' => $difficulty ] );
    }

    // ── AJAX: Browse questions ────────────────────────────────────────────────

    public static function ajax_browse(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $level         = sanitize_key( $_POST['level']         ?? 'std_4' );
        $period        = sanitize_key( $_POST['period']        ?? '' );
        $subject       = sanitize_key( $_POST['subject']       ?? 'math' );
        $module_number = sanitize_text_field( $_POST['module_number'] ?? '' );
        $difficulty    = sanitize_key( $_POST['difficulty']    ?? '' );
        $status        = sanitize_key( $_POST['status']        ?? 'active' );
        $page          = max( 1, (int) ( $_POST['page'] ?? 1 ) );

        $params = [
            'curriculum' => 'tt_primary',
            'level'      => $level,
            'subject'    => $subject,
            'status'     => $status,
            'page'       => $page,
            'per_page'   => 25,
        ];
        if ( $period )        $params['period']        = $period;
        if ( $module_number ) $params['module_number'] = (int) $module_number;
        if ( $difficulty )    $params['difficulty']    = $difficulty;

        $result = self::railway_get( '/api/v1/question-bank/questions', $params );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
            return;
        }

        wp_send_json_success( $result );
    }

    // ── AJAX: Retire / Restore question ──────────────────────────────────────

    public static function ajax_retire(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $id     = sanitize_text_field( $_POST['id']     ?? '' );
        $status = sanitize_key(        $_POST['status'] ?? '' );

        if ( ! $id || ! in_array( $status, [ 'active', 'retired', 'pending_review' ], true ) ) {
            wp_send_json_error( [ 'message' => 'id and valid status are required.' ] );
            return;
        }

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
            return;
        }

        $response = wp_remote_request(
            $endpoint . '/api/v1/question-bank/questions/' . rawurlencode( $id ),
            [
                'method'  => 'PATCH',
                'timeout' => 15,
                'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'status' => $status ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            wp_send_json_error( [ 'message' => $data['error'] ?? "HTTP {$code}" ] );
            return;
        }

        Knowly_Debug::log( 'admin.qb', 'QB question status updated', [
            'id'     => $id,
            'status' => $status,
        ], null, 'info' );

        wp_send_json_success( [
            'id'     => $data['question']['id']     ?? $id,
            'status' => $data['question']['status'] ?? $status,
        ] );
    }
}
