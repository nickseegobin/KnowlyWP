<?php
/**
 * Knowly_Admin_Badges — Badge Definitions admin panel.
 *
 * Allows creating, editing, and deleting badge definitions across all three trigger types:
 *   quest_module_completion  — all sub-topics in a module completed
 *   trial_count              — threshold number of trials in a subject/level
 *   lesson_count             — threshold number of lessons in a subject/level
 *
 * The "AI Generate" button calls Railway /api/v1/badge/generate to suggest a name
 * and description based on the definition context.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Badges {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_badges_list',         [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_knowly_badges_save',         [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_knowly_badges_delete',       [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_knowly_badges_generate',     [ __CLASS__, 'ajax_generate' ] );
        add_action( 'wp_ajax_knowly_badges_quest_modules',[ __CLASS__, 'ajax_quest_modules' ] );
        add_action( 'wp_ajax_knowly_badges_for_quests',   [ __CLASS__, 'ajax_for_quests' ] );
    }

    // ── Page Entry ────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $nonce      = wp_create_nonce( 'knowly_badges_nonce' );
        $ajax_url   = admin_url( 'admin-ajax.php' );
        $cs         = get_option( 'knowly_curriculum_subjects', [] );
        $badge_svgs = get_option( 'knowly_badge_svgs', [] ); // keyed by subject slug

        // Build flat lists for dropdowns
        $curricula = array_keys( $cs );
        $levels    = [];
        $periods   = [];
        $subjects  = [];
        foreach ( $cs as $curr => $cfg ) {
            foreach ( $cfg['levels']   ?? [] as $l ) $levels[]   = [ 'curriculum' => $curr, 'value' => $l['value'], 'label' => $l['label'] ];
            foreach ( $cfg['periods']  ?? [] as $p ) $periods[]  = [ 'curriculum' => $curr, 'value' => $p['value'], 'label' => $p['label'] ];
            foreach ( $cfg['subjects'] ?? [] as $s ) $subjects[] = [ 'curriculum' => $curr, 'value' => $s['value'], 'label' => $s['label'] ];
        }

        // URL param auto-open: ?auto=quest&curriculum=...&level=...&period=...&subject=...&module=N
        // or ?edit_def=ID
        $auto_open = [
            'mode'       => sanitize_key( $_GET['auto']       ?? '' ),
            'curriculum' => sanitize_key( $_GET['curriculum'] ?? '' ),
            'level'      => sanitize_key( $_GET['level']      ?? '' ),
            'period'     => sanitize_key( $_GET['period']     ?? '' ),
            'subject'    => sanitize_key( $_GET['subject']    ?? '' ),
            'module'     => absint( $_GET['module']           ?? 0 ),
            'edit_def'   => absint( $_GET['edit_def']         ?? 0 ),
        ];
        ?>
        <div class="wrap knowly-wrap" id="knowly-badges-page">
            <h1>Badges
                <button class="page-title-action" id="btn-new-badge">+ New Badge</button>
            </h1>
            <p class="description">Define the conditions under which students earn badges.
               Badges are awarded automatically — no manual intervention needed.</p>

            <div id="badges-notice" style="display:none;margin:12px 0;"></div>
            <div id="badges-list"><p style="color:#777;">Loading…</p></div>
        </div>

        <!-- Create / Edit Modal -->
        <div id="modal-badge" class="knowly-modal" style="display:none;">
            <div class="knowly-modal-backdrop"></div>
            <div class="knowly-modal-box" style="max-width:720px;">
                <button class="knowly-modal-close">&times;</button>
                <h2 id="modal-badge-title">New Badge</h2>

                <div style="display:flex;gap:20px;align-items:flex-start;">

                    <!-- ── Form column ── -->
                    <div style="flex:1;min-width:0;">
                        <input type="hidden" id="badge-id">

                        <table class="form-table" style="margin-top:8px;">
                            <tr>
                                <th><label for="badge-trigger-type">Trigger type</label></th>
                                <td>
                                    <select id="badge-trigger-type" class="regular-text">
                                        <option value="quest_module_completion">Quest module completion</option>
                                        <option value="trial_count">Trial count (threshold)</option>
                                        <option value="lesson_count">Lesson count (threshold)</option>
                                    </select>
                                </td>
                            </tr>

                            <!-- Quest module selector (shown for quest_module_completion) -->
                            <tr id="row-quest-module">
                                <th><label for="badge-quest-module">Quest module</label></th>
                                <td>
                                    <select id="badge-quest-module" class="regular-text">
                                        <option value="">Loading modules…</option>
                                    </select>
                                    <p class="description" id="quest-module-hint" style="margin-top:4px;"></p>
                                </td>
                            </tr>

                            <!-- Manual fields (shown for threshold types; curriculum/level always visible for quest type as hidden helpers) -->
                            <tr id="row-curriculum">
                                <th><label>Curriculum</label></th>
                                <td>
                                    <select id="badge-curriculum" class="regular-text">
                                        <?php foreach ( $curricula as $c ) : ?>
                                            <option value="<?= esc_attr( $c ) ?>"><?= esc_html( $cs[ $c ]['display_name'] ?? $c ) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr id="row-level">
                                <th><label for="badge-level">Level</label></th>
                                <td>
                                    <select id="badge-level" class="regular-text">
                                        <?php foreach ( $levels as $l ) : ?>
                                            <option value="<?= esc_attr( $l['value'] ) ?>" data-curriculum="<?= esc_attr( $l['curriculum'] ) ?>"><?= esc_html( $l['label'] ) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr id="row-period">
                                <th><label for="badge-period">Period</label></th>
                                <td>
                                    <select id="badge-period" class="regular-text">
                                        <option value="">— Any / Capstone —</option>
                                        <?php foreach ( $periods as $p ) : ?>
                                            <option value="<?= esc_attr( $p['value'] ) ?>" data-curriculum="<?= esc_attr( $p['curriculum'] ) ?>"><?= esc_html( $p['label'] ) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr id="row-subject">
                                <th><label for="badge-subject">Subject</label></th>
                                <td>
                                    <select id="badge-subject" class="regular-text">
                                        <?php foreach ( $subjects as $s ) : ?>
                                            <option value="<?= esc_attr( $s['value'] ) ?>" data-curriculum="<?= esc_attr( $s['curriculum'] ) ?>"><?= esc_html( $s['label'] ) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr id="row-module" style="display:none;">
                                <th><label for="badge-module-number">Module number</label></th>
                                <td>
                                    <input type="number" id="badge-module-number" class="small-text" min="1" placeholder="1">
                                    <p class="description">Module number as it appears in the curriculum (e.g. 1 = first module).</p>
                                </td>
                            </tr>
                            <tr id="row-threshold" style="display:none;">
                                <th><label for="badge-threshold">Count threshold</label></th>
                                <td>
                                    <input type="number" id="badge-threshold" class="small-text" min="1" placeholder="5">
                                    <p class="description" id="threshold-hint">Number of trials/lessons the child must complete.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="badge-name">Badge name</label></th>
                                <td>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <input type="text" id="badge-name" class="regular-text" placeholder="e.g. Number Patterns Master">
                                        <button type="button" class="button" id="btn-ai-generate" title="Generate name with AI">✦ Suggest</button>
                                    </div>
                                    <span id="generate-status" style="font-size:12px;color:#777;margin-top:4px;display:block;"></span>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="badge-description">Description</label></th>
                                <td>
                                    <textarea id="badge-description" class="large-text" rows="3" placeholder="Short motivational description shown on the badge."></textarea>
                                </td>
                            </tr>
                        </table>

                        <p class="submit" style="margin-top:0;">
                            <button class="button button-primary" id="btn-save-badge">Save Badge</button>
                            <button type="button" class="button knowly-modal-close" style="margin-left:6px;">Cancel</button>
                            <span id="save-status" style="margin-left:12px;font-size:13px;"></span>
                        </p>
                    </div><!-- /form column -->

                    <!-- ── Preview column ── -->
                    <div style="width:160px;shrink:0;text-align:center;">
                        <p style="font-size:11px;color:#888;margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em;">Preview</p>
                        <div id="badge-preview"
                             style="width:140px;height:140px;margin:0 auto;display:flex;align-items:center;justify-content:center;background:#f3f4f6;border-radius:12px;overflow:hidden;">
                            <span style="color:#aaa;font-size:12px;text-align:center;">Select subject &amp; period</span>
                        </div>
                        <p id="badge-preview-label" style="font-size:11px;color:#888;margin:6px 0 0;"></p>
                    </div>

                </div><!-- /flex row -->
            </div>
        </div>

        <script>
        (function($) {
            var AJAX_URL = '<?= esc_js( $ajax_url ) ?>';
            var NONCE    = '<?= esc_js( $nonce ) ?>';
            var AUTO     = <?= wp_json_encode( $auto_open ) ?>;
            var BADGE_SVGS = <?= wp_json_encode( $badge_svgs ) ?>;

            // ── Period → SVG color map (mirrors badgeSvg.ts) ──────────────────
            var PERIOD_COLORS = {
                term_1:     { primary: '#2563eb', secondary: '#93c5fd' },
                term_2:     { primary: '#16a34a', secondary: '#86efac' },
                term_3:     { primary: '#ea580c', secondary: '#fdba74' },
                semester_1: { primary: '#7c3aed', secondary: '#c4b5fd' },
                semester_2: { primary: '#dc2626', secondary: '#fca5a5' },
                capstone:   { primary: '#1e3a8a', secondary: '#93c5fd' },
            };

            var DEFAULT_BADGE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
                + '<defs><style>:root,svg{--badge-primary:#2563eb;--badge-secondary:#93c5fd}</style></defs>'
                + '<circle cx="50" cy="50" r="45" fill="var(--badge-secondary)" stroke="var(--badge-primary)" stroke-width="4"/>'
                + '<circle cx="50" cy="50" r="30" fill="var(--badge-primary)"/>'
                + '<text x="50" y="56" text-anchor="middle" fill="#fff" font-size="22" font-family="sans-serif">★</text>'
                + '</svg>';

            function injectPeriodColors(svg, period) {
                var colors = PERIOD_COLORS[period] || PERIOD_COLORS['term_1'];
                var style  = '<style>:root,svg{--badge-primary:' + colors.primary + ';--badge-secondary:' + colors.secondary + '}</style>';
                if (svg.indexOf('<style>') !== -1) {
                    return svg.replace(/<style>[^<]*<\/style>/, style);
                }
                return svg.replace('<svg', '<svg><defs>' + style + '</defs>').replace('><defs>', '<defs>');
            }

            // ── Update badge preview ──────────────────────────────────────────

            function updatePreview() {
                var subject = $('#badge-subject').val() || '';
                var period  = $('#badge-period').val()  || '';
                var svg     = (BADGE_SVGS && BADGE_SVGS[subject]) ? BADGE_SVGS[subject] : DEFAULT_BADGE_SVG;
                var final   = injectPeriodColors(svg, period || 'term_1');
                $('#badge-preview').html(final);

                var labels = [];
                if (subject) labels.push(subject.charAt(0).toUpperCase() + subject.slice(1).replace(/_/g,' '));
                if (period)  labels.push(period.replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();}));
                $('#badge-preview-label').text(labels.join(' · ') || '');
            }

            // ── Load list ─────────────────────────────────────────────────────

            function loadList() {
                $('#badges-list').html('<p style="color:#777;">Loading…</p>');
                $.post(AJAX_URL, { action: 'knowly_badges_list', nonce: NONCE }, function(resp) {
                    if (!resp.success) { $('#badges-list').html('<div class="notice notice-error inline"><p>' + esc(resp.data || 'Failed') + '</p></div>'); return; }
                    renderList(resp.data);
                    handleAutoOpen();
                });
            }

            function renderList(defs) {
                if (!defs.length) {
                    $('#badges-list').html('<p style="color:#777;margin-top:16px;">No badge definitions yet. Click <strong>+ New Badge</strong> to create one.</p>');
                    return;
                }

                var typeLabel = {
                    quest_module_completion: 'Quest Module',
                    trial_count:             'Trial Count',
                    lesson_count:            'Lesson Count',
                };

                var html = '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">'
                    + '<thead><tr>'
                    + '<th>Name</th>'
                    + '<th style="width:160px;">Type</th>'
                    + '<th style="width:120px;">Level / Period</th>'
                    + '<th style="width:100px;">Subject</th>'
                    + '<th style="width:80px;">Threshold</th>'
                    + '<th style="width:70px;">Earned</th>'
                    + '<th style="width:120px;"></th>'
                    + '</tr></thead><tbody>';

                defs.forEach(function(d) {
                    html += '<tr>'
                        + '<td><strong>' + esc(d.name) + '</strong>'
                        + (d.description ? '<br><small style="color:#777;">' + esc(d.description.substring(0,80)) + (d.description.length > 80 ? '…' : '') + '</small>' : '')
                        + '</td>'
                        + '<td>' + esc(typeLabel[d.trigger_type] || d.trigger_type) + '</td>'
                        + '<td>' + esc(d.level) + (d.period ? ' / ' + esc(d.period) : '') + '</td>'
                        + '<td>' + esc(d.subject) + '</td>'
                        + '<td>' + (d.threshold ? d.threshold : (d.module_number ? 'Module ' + d.module_number : '—')) + '</td>'
                        + '<td>' + (d.award_count || 0) + '</td>'
                        + '<td>'
                        + '<button class="button button-small btn-edit-badge" data-id="' + d.id + '" style="margin-right:4px;">Edit</button>'
                        + '<button class="button button-small btn-delete-badge" data-id="' + d.id + '" data-name="' + esc(d.name) + '" style="color:#b32d2e;">Delete</button>'
                        + '</td>'
                        + '</tr>';
                });

                html += '</tbody></table>';
                $('#badges-list').html(html);
                window._badgeDefs = defs;
            }

            // ── Quest module dropdown ─────────────────────────────────────────

            var _questModules = null; // cached after first load

            function loadQuestModules(callback) {
                if (_questModules) { if (callback) callback(_questModules); return; }
                $.post(AJAX_URL, { action: 'knowly_badges_quest_modules', nonce: NONCE }, function(resp) {
                    if (resp.success) {
                        _questModules = resp.data || [];
                    } else {
                        _questModules = [];
                    }
                    populateQuestModuleSelect(_questModules);
                    if (callback) callback(_questModules);
                }).fail(function() {
                    _questModules = [];
                    populateQuestModuleSelect([]);
                    if (callback) callback([]);
                });
            }

            function populateQuestModuleSelect(modules) {
                var opts = '<option value="">— Select a quest module —</option>';
                modules.forEach(function(m) {
                    var key = m.curriculum + ':' + m.level + ':' + (m.period || 'capstone') + ':' + m.subject + ':' + m.module_number;
                    var label = '[' + m.level + '] ' + (m.period ? m.period + ' · ' : '') + m.subject + ' · Module ' + m.module_number
                        + (m.module_title ? ' — ' + m.module_title : '');
                    opts += '<option value="' + esc(key) + '"'
                        + ' data-curriculum="' + esc(m.curriculum) + '"'
                        + ' data-level="'      + esc(m.level)      + '"'
                        + ' data-period="'     + esc(m.period||'') + '"'
                        + ' data-subject="'    + esc(m.subject)    + '"'
                        + ' data-module="'     + m.module_number   + '"'
                        + ' data-title="'      + esc(m.module_title||'') + '"'
                        + '>' + esc(label) + '</option>';
                });
                $('#badge-quest-module').html(opts);
            }

            $('#badge-quest-module').on('change', function() {
                var $opt = $(this).find('option:selected');
                if (!$opt.val()) { $('#quest-module-hint').text(''); return; }

                $('#badge-curriculum').val($opt.data('curriculum'));
                $('#badge-level').val($opt.data('level'));
                $('#badge-period').val($opt.data('period') || '');
                $('#badge-subject').val($opt.data('subject'));
                $('#badge-module-number').val($opt.data('module'));
                var title = $opt.data('title');
                $('#quest-module-hint').text(title ? 'Module title: ' + title : '');
                updatePreview();
            });

            // ── Trigger type → show/hide fields ───────────────────────────────

            function updateFields() {
                var type = $('#badge-trigger-type').val();
                if (type === 'quest_module_completion') {
                    $('#row-quest-module').show();
                    $('#row-curriculum').hide();
                    $('#row-level').hide();
                    $('#row-period').hide();
                    $('#row-subject').hide();
                    $('#row-module').hide();
                    $('#row-threshold').hide();
                    loadQuestModules();
                } else {
                    $('#row-quest-module').hide();
                    $('#row-curriculum').show();
                    $('#row-level').show();
                    $('#row-period').hide(); // threshold badges are period-agnostic
                    $('#row-subject').show();
                    $('#row-module').hide();
                    $('#row-threshold').show();
                    var hint = type === 'trial_count' ? 'Number of completed trials in this subject.' : 'Number of completed lessons in this subject.';
                    $('#threshold-hint').text(hint);
                }
                updatePreview();
            }

            $('#badge-trigger-type').on('change', updateFields);
            $('#badge-subject').on('change', updatePreview);
            $('#badge-period').on('change', updatePreview);

            // ── Open modal for new badge ──────────────────────────────────────

            $('#btn-new-badge').on('click', function() {
                resetModal();
                $('#modal-badge-title').text('New Badge');
                updateFields();
                $('#modal-badge').show();
            });

            function resetModal() {
                $('#badge-id').val('');
                $('#badge-trigger-type').val('quest_module_completion');
                $('#badge-name').val('');
                $('#badge-description').val('');
                $('#badge-module-number').val('');
                $('#badge-threshold').val('');
                $('#badge-period').val('');
                $('#badge-quest-module').val('');
                $('#quest-module-hint').text('');
                $('#save-status').text('').css('color','');
                $('#generate-status').text('');
                $('#badge-preview').html('<span style="color:#aaa;font-size:12px;text-align:center;">Select subject &amp; period</span>');
                $('#badge-preview-label').text('');
            }

            // ── Edit ─────────────────────────────────────────────────────────

            $(document).on('click', '.btn-edit-badge', function() {
                var id  = $(this).data('id');
                var def = (window._badgeDefs || []).find(function(d){ return d.id == id; });
                if (!def) return;

                resetModal();
                $('#modal-badge-title').text('Edit Badge');
                $('#badge-id').val(def.id);
                $('#badge-trigger-type').val(def.trigger_type);
                updateFields();

                if (def.trigger_type === 'quest_module_completion') {
                    // Pre-populate hidden fields; select matching quest module after modules load
                    $('#badge-curriculum').val(def.curriculum);
                    $('#badge-level').val(def.level);
                    $('#badge-period').val(def.period || '');
                    $('#badge-subject').val(def.subject);
                    $('#badge-module-number').val(def.module_number || '');
                    loadQuestModules(function() {
                        var key = def.curriculum + ':' + def.level + ':' + (def.period || 'capstone') + ':' + def.subject + ':' + def.module_number;
                        $('#badge-quest-module').val(key);
                        var $opt = $('#badge-quest-module option:selected');
                        $('#quest-module-hint').text($opt.data('title') ? 'Module title: ' + $opt.data('title') : '');
                    });
                } else {
                    $('#badge-curriculum').val(def.curriculum);
                    $('#badge-level').val(def.level);
                    $('#badge-period').val(def.period || '');
                    $('#badge-subject').val(def.subject);
                    $('#badge-threshold').val(def.threshold || '');
                }

                $('#badge-name').val(def.name);
                $('#badge-description').val(def.description || '');
                updatePreview();
                $('#modal-badge').show();
            });

            // ── Save ─────────────────────────────────────────────────────────

            $('#btn-save-badge').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Saving…');
                $('#save-status').text('').css('color','');

                $.post(AJAX_URL, {
                    action:         'knowly_badges_save',
                    nonce:          NONCE,
                    id:             $('#badge-id').val() || '',
                    trigger_type:   $('#badge-trigger-type').val(),
                    curriculum:     $('#badge-curriculum').val(),
                    level:          $('#badge-level').val(),
                    period:         $('#badge-period').val(),
                    subject:        $('#badge-subject').val(),
                    module_number:  $('#badge-module-number').val() || '',
                    threshold:      $('#badge-threshold').val() || '',
                    name:           $('#badge-name').val(),
                    description:    $('#badge-description').val(),
                }, function(resp) {
                    $btn.prop('disabled', false).text('Save Badge');
                    if (resp.success) {
                        $('#save-status').text('✅ Saved').css('color','#00a32a');
                        $('#modal-badge').hide();
                        loadList();
                    } else {
                        $('#save-status').text('❌ ' + esc(resp.data || 'Error')).css('color','#b32d2e');
                    }
                });
            });

            // ── Delete ────────────────────────────────────────────────────────

            $(document).on('click', '.btn-delete-badge', function() {
                var id   = $(this).data('id');
                var name = $(this).data('name');
                if (!confirm('Delete badge "' + name + '"?\n\nExisting awards are preserved but will display without a definition name.')) return;

                var $btn = $(this).prop('disabled', true).text('Deleting…');
                $.post(AJAX_URL, { action: 'knowly_badges_delete', nonce: NONCE, id: id }, function(resp) {
                    if (resp.success) {
                        showNotice('success', 'Badge "' + name + '" deleted.');
                        loadList();
                    } else {
                        $btn.prop('disabled', false).text('Delete');
                        showNotice('error', resp.data || 'Delete failed.');
                    }
                });
            });

            // ── AI Generate ───────────────────────────────────────────────────

            $('#btn-ai-generate').on('click', function() {
                var id = $('#badge-id').val();
                if (!id) {
                    $('#generate-status').text('Save the badge first, then use AI Suggest.');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Generating…');
                $('#generate-status').text('Asking AI…').css('color','#2271b1');

                $.post(AJAX_URL, { action: 'knowly_badges_generate', nonce: NONCE, id: id }, function(resp) {
                    $btn.prop('disabled', false).text('✦ Suggest');
                    if (resp.success) {
                        $('#badge-name').val(resp.data.name || '');
                        if (resp.data.description) $('#badge-description').val(resp.data.description);
                        $('#generate-status').text('✅ Suggestion applied — review and save.').css('color','#00a32a');
                    } else {
                        $('#generate-status').text('❌ ' + esc(resp.data || 'AI generation failed.')).css('color','#b32d2e');
                    }
                });
            });

            // ── Modal close ───────────────────────────────────────────────────

            $(document).on('click', '.knowly-modal-close', function() {
                $(this).closest('.knowly-modal').hide();
            });
            $(document).on('click', '.knowly-modal-backdrop', function() {
                $(this).closest('.knowly-modal').hide();
            });

            // ── Auto-open handling ────────────────────────────────────────────

            function handleAutoOpen() {
                if (AUTO.edit_def) {
                    var def = (window._badgeDefs || []).find(function(d){ return d.id == AUTO.edit_def; });
                    if (def) {
                        $('.btn-edit-badge[data-id="' + AUTO.edit_def + '"]').trigger('click');
                    }
                    return;
                }
                if (AUTO.mode === 'quest' && AUTO.subject) {
                    resetModal();
                    $('#modal-badge-title').text('New Badge');
                    $('#badge-trigger-type').val('quest_module_completion');
                    updateFields();
                    loadQuestModules(function() {
                        if (AUTO.curriculum) $('#badge-curriculum').val(AUTO.curriculum);
                        if (AUTO.level)      $('#badge-level').val(AUTO.level);
                        if (AUTO.period)     $('#badge-period').val(AUTO.period);
                        if (AUTO.subject)    $('#badge-subject').val(AUTO.subject);
                        if (AUTO.module)     $('#badge-module-number').val(AUTO.module);
                        // pre-select the matching module in the dropdown
                        var key = (AUTO.curriculum||'tt_primary') + ':' + AUTO.level + ':' + (AUTO.period||'capstone') + ':' + AUTO.subject + ':' + AUTO.module;
                        $('#badge-quest-module').val(key);
                        var $opt = $('#badge-quest-module option:selected');
                        $('#quest-module-hint').text($opt.data('title') ? 'Module title: ' + $opt.data('title') : '');
                        updatePreview();
                    });
                    $('#modal-badge').show();
                }
            }

            // ── Helpers ───────────────────────────────────────────────────────

            function showNotice(type, msg) {
                var cls = type === 'success' ? 'notice-success' : 'notice-error';
                $('#badges-notice').html('<div class="notice ' + cls + ' inline"><p>' + esc(msg) + '</p></div>').show();
                setTimeout(function() { $('#badges-notice').hide(); }, 5000);
            }

            function esc(str) {
                return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            // ── Init ──────────────────────────────────────────────────────────
            loadList();

        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: List ────────────────────────────────────────────────────────────

    public static function ajax_list(): void {
        check_ajax_referer( 'knowly_badges_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $defs = Knowly_Badge_Service::get_definitions();
        foreach ( $defs as &$def ) {
            $def['award_count'] = Knowly_Badge_Service::count_awards_for_definition( (int) $def['id'] );
        }
        wp_send_json_success( $defs );
    }

    // ── AJAX: Save ────────────────────────────────────────────────────────────

    public static function ajax_save(): void {
        check_ajax_referer( 'knowly_badges_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $data = [
            'id'            => ! empty( $_POST['id'] )            ? (int)   $_POST['id']                              : null,
            'name'          => sanitize_text_field( $_POST['name']          ?? '' ),
            'description'   => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'trigger_type'  => sanitize_key( $_POST['trigger_type']  ?? '' ),
            'curriculum'    => sanitize_key( $_POST['curriculum']    ?? '' ),
            'level'         => sanitize_key( $_POST['level']         ?? '' ),
            'period'        => sanitize_key( $_POST['period']        ?? '' ) ?: null,
            'subject'       => sanitize_key( $_POST['subject']       ?? '' ),
            'module_number' => ! empty( $_POST['module_number'] )    ? (int)   $_POST['module_number']                : null,
            'threshold'     => ! empty( $_POST['threshold'] )        ? (int)   $_POST['threshold']                   : null,
        ];

        $result = Knowly_Badge_Service::save_definition( $data );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    // ── AJAX: Delete ──────────────────────────────────────────────────────────

    public static function ajax_delete(): void {
        check_ajax_referer( 'knowly_badges_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'ID required' );

        $deleted = Knowly_Badge_Service::delete_definition( $id );
        if ( $deleted ) {
            wp_send_json_success( [ 'deleted' => true ] );
        } else {
            wp_send_json_error( 'Definition not found.' );
        }
    }

    // ── AJAX: AI Generate ─────────────────────────────────────────────────────

    public static function ajax_generate(): void {
        check_ajax_referer( 'knowly_badges_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'ID required' );

        $defs = Knowly_Badge_Service::get_definitions();
        $def  = null;
        foreach ( $defs as $d ) {
            if ( (int) $d['id'] === $id ) { $def = $d; break; }
        }
        if ( ! $def ) wp_send_json_error( 'Definition not found.' );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) wp_send_json_error( 'Railway endpoint not configured.' );

        $resp = wp_remote_post( $endpoint . '/api/v1/badge/generate', [
            'timeout' => 30,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( [
                'trigger_type'  => $def['trigger_type'],
                'curriculum'    => $def['curriculum'],
                'level'         => $def['level'],
                'period'        => $def['period'],
                'subject'       => $def['subject'],
                'module_number' => $def['module_number'],
                'threshold'     => $def['threshold'],
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) wp_send_json_error( $resp->get_error_message() );

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body['name'] ) ) wp_send_json_error( 'AI did not return a name.' );

        // Persist the AI-generated values
        Knowly_Badge_Service::save_definition( array_merge( $def, [
            'name'         => $body['name'],
            'description'  => $body['description'] ?? $def['description'],
            'ai_generated' => 1,
        ] ) );

        wp_send_json_success( [
            'name'        => $body['name'],
            'description' => $body['description'] ?? null,
        ] );
    }

    // ── AJAX: Quest modules (for badge modal selector) ────────────────────────

    public static function ajax_quest_modules(): void {
        check_ajax_referer( 'knowly_badges_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        global $wpdb;
        $table = $wpdb->prefix . 'knowly_quests';

        // Return distinct approved quest modules so the badge modal can link to real quests.
        $rows = $wpdb->get_results(
            "SELECT DISTINCT curriculum, level, period, subject, module_number, module_title
             FROM {$table}
             WHERE variant = 'student' AND status = 'approved'
             ORDER BY level, period, subject, module_number",
            ARRAY_A
        );

        wp_send_json_success( $rows ?: [] );
    }

    // ── AJAX: Badge defs keyed by trigger_key (for quest panel) ──────────────

    public static function ajax_for_quests(): void {
        // Uses knowly_admin_nonce so the quest panel can call it with its own nonce.
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $defs = Knowly_Badge_Service::get_definitions();
        $map  = [];
        foreach ( $defs as $d ) {
            if ( $d['trigger_type'] !== 'quest_module_completion' ) continue;
            $period = $d['period'] ?: 'capstone';
            $key = implode( ':', [ $d['curriculum'], $d['level'], $period, $d['subject'], (int) $d['module_number'] ] );
            $map[ $key ] = [ 'id' => (int) $d['id'], 'name' => $d['name'] ];
        }
        wp_send_json_success( $map );
    }
}
