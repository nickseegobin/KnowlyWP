<?php
/**
 * Knowly_Admin_Curriculum — Curriculum Management admin page.
 *
 * Renders a filterable tree of curriculum_topics rows with add / edit / archive
 * actions. All data is fetched via the REST API (proxied to Railway).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Curriculum {

    public static function boot(): void {
        // No extra AJAX hooks — this page uses the REST API exclusively.
    }

    // ── Page Renderer ─────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }

        $rest_base  = esc_url( rest_url( KNOWLY_REST_NAMESPACE ) );
        $rest_nonce = wp_create_nonce( 'wp_rest' );
        ?>
        <div class="wrap knowly-wrap" id="knowly-curriculum-page">
            <h1>Curriculum Topics</h1>
            <p class="description">Browse, add, edit, and archive learning objectives stored in Supabase. Changes are reflected immediately in AI content generation.</p>

            <?php self::render_filter_bar(); ?>

            <div class="knowly-curriculum-actions" style="margin: 12px 0;">
                <button class="button button-primary" id="btn-load-topics">Load Topics</button>
                <button class="button button-secondary" id="btn-add-topic" style="margin-left:8px;" disabled>+ Add Topic</button>
                <button class="button" id="btn-sync-pinecone" style="margin-left:8px;" disabled title="Sync loaded topics to Pinecone vector index">Sync to Pinecone</button>
                <span id="sync-pinecone-status" style="margin-left:10px;font-size:13px;color:#777;"></span>
                <span id="curriculum-loading" style="display:none;margin-left:12px;">Loading…</span>
            </div>

            <div id="curriculum-results"></div>
        </div>

        <?php self::render_add_modal(); ?>
        <?php self::render_edit_modal(); ?>

        <script>
        (function($) {
            var REST_BASE   = '<?= $rest_base ?>/editor/curriculum-topics';
            var REST_NONCE  = '<?= $rest_nonce ?>';
            var currentFilters = {};
            var editingId = null;

            function apiFetch(method, url, body) {
                var opts = {
                    method: method,
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
                };
                if (body) opts.body = JSON.stringify(body);
                return fetch(url, opts).then(function(r) {
                    return r.json().then(function(data) {
                        if (!r.ok) throw new Error(data.message || data.error || 'Request failed');
                        return data;
                    });
                });
            }

            // ── Filters ───────────────────────────────────────────────────────

            function getFilters() {
                return {
                    curriculum: $('#filter-curriculum').val() || 'tt_primary',
                    level:      $('#filter-level').val()      || '',
                    period:     $('#filter-period').val()     || '',
                    subject:    $('#filter-subject').val()    || '',
                    status:     $('#filter-status').val()     || 'active',
                };
            }

            // ── Load topics ───────────────────────────────────────────────────

            $('#btn-load-topics').on('click', function() {
                currentFilters = getFilters();
                var params = new URLSearchParams();
                $.each(currentFilters, function(k, v) { if (v) params.append(k, v); });

                $('#curriculum-loading').show();
                $('#curriculum-results').html('');
                $('#btn-add-topic').prop('disabled', true);

                apiFetch('GET', REST_BASE + '?' + params.toString())
                    .then(function(resp) {
                        renderTopicsTable(resp.items || []);
                        $('#btn-add-topic').prop('disabled', false);
                        $('#btn-sync-pinecone').prop('disabled', false);
                    })
                    .catch(function(err) {
                        $('#curriculum-results').html('<div class="notice notice-error inline"><p>' + err.message + '</p></div>');
                    })
                    .finally(function() { $('#curriculum-loading').hide(); });
            });

            // ── Render table ──────────────────────────────────────────────────

            function renderTopicsTable(items) {
                if (!items.length) {
                    $('#curriculum-results').html('<p>No topics found for the selected filters.</p>');
                    return;
                }

                var html = '<table class="knowly-table wp-list-table widefat fixed striped">';
                html += '<thead><tr>'
                    + '<th style="width:60px">Order</th>'
                    + '<th>Level / Period / Subject</th>'
                    + '<th>Module</th>'
                    + '<th>Topic (Learning Objective)</th>'
                    + '<th style="width:80px">Source</th>'
                    + '<th style="width:80px">Status</th>'
                    + '<th style="width:120px">Actions</th>'
                    + '</tr></thead><tbody>';

                $.each(items, function(_, row) {
                    var level_period = row.level + (row.period ? ' / ' + row.period : ' (capstone)') + ' / ' + row.subject;
                    var module_label = row.module_title ? ('#' + (row.module_number || '?') + ' ' + escHtml(row.module_title)) : '—';
                    html += '<tr data-id="' + row.id + '">'
                        + '<td>' + row.sort_order + '</td>'
                        + '<td>' + escHtml(level_period) + '</td>'
                        + '<td>' + module_label + '</td>'
                        + '<td>' + escHtml(row.topic) + '</td>'
                        + '<td><span class="knowly-badge">' + escHtml(row.source) + '</span></td>'
                        + '<td><span class="knowly-badge ' + (row.status === 'active' ? 'ok' : 'warn') + '">' + escHtml(row.status) + '</span></td>'
                        + '<td>'
                        + '<button class="button button-small btn-edit-topic" data-row=\'' + JSON.stringify(row).replace(/'/g, '&#39;') + '\'>Edit</button> '
                        + (row.status === 'active' ? '<button class="button button-small btn-archive-topic" data-id="' + row.id + '" style="color:#d63638">Archive</button>' : '')
                        + '</td>'
                        + '</tr>';
                });

                html += '</tbody></table>';
                html += '<p style="color:#666;margin-top:8px;">' + items.length + ' topic(s) loaded.</p>';
                $('#curriculum-results').html(html);
            }

            function escHtml(str) {
                return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            // ── Edit topic ────────────────────────────────────────────────────

            $(document).on('click', '.btn-edit-topic', function() {
                var row = $(this).data('row');
                if (typeof row === 'string') row = JSON.parse(row);
                editingId = row.id;
                $('#edit-sort-order').val(row.sort_order);
                $('#edit-module-title').val(row.module_title || '');
                $('#edit-topic').val(row.topic);
                $('#edit-status').val(row.status);
                $('#edit-topic-context').text(row.level + (row.period ? ' / ' + row.period : '') + ' / ' + row.subject);
                $('#edit-topic-error').hide().text('');
                $('#modal-edit-topic').show();
            });

            $('#modal-edit-topic .knowly-modal-close, #btn-cancel-edit').on('click', function() {
                $('#modal-edit-topic').hide();
            });

            $('#form-edit-topic').on('submit', function(e) {
                e.preventDefault();
                if (!editingId) return;

                var body = {
                    sort_order:   parseInt($('#edit-sort-order').val(), 10),
                    module_title: $('#edit-module-title').val().trim() || null,
                    topic:        $('#edit-topic').val().trim(),
                    status:       $('#edit-status').val(),
                };

                if (!body.topic) { $('#edit-topic-error').text('Topic is required.').show(); return; }

                $('#btn-save-edit').prop('disabled', true).text('Saving…');
                apiFetch('PATCH', REST_BASE + '/' + editingId, body)
                    .then(function() {
                        $('#modal-edit-topic').hide();
                        $('#btn-load-topics').trigger('click');
                    })
                    .catch(function(err) { $('#edit-topic-error').text(err.message).show(); })
                    .finally(function() { $('#btn-save-edit').prop('disabled', false).text('Save Changes'); });
            });

            // ── Archive topic ─────────────────────────────────────────────────

            $(document).on('click', '.btn-archive-topic', function() {
                if (!confirm('Archive this topic? It will no longer be used in generation.')) return;
                var id = $(this).data('id');
                apiFetch('DELETE', REST_BASE + '/' + id)
                    .then(function() { $('#btn-load-topics').trigger('click'); })
                    .catch(function(err) { alert('Archive failed: ' + err.message); });
            });

            // ── Add topic ─────────────────────────────────────────────────────

            $('#btn-add-topic').on('click', function() {
                var f = getFilters();
                $('#add-curriculum').val(f.curriculum || 'tt_primary');
                $('#add-level').val(f.level || '');
                $('#add-period').val(f.period || '');
                $('#add-subject').val(f.subject || '');
                $('#add-module-number').val('');
                $('#add-module-title').val('');
                $('#add-sort-order').val('');
                $('#add-topic').val('');
                $('#add-topic-error').hide().text('');
                $('#modal-add-topic').show();
            });

            $('#modal-add-topic .knowly-modal-close, #btn-cancel-add').on('click', function() {
                $('#modal-add-topic').hide();
            });

            $('#form-add-topic').on('submit', function(e) {
                e.preventDefault();
                var body = {
                    curriculum:    $('#add-curriculum').val().trim(),
                    level:         $('#add-level').val().trim(),
                    period:        $('#add-period').val().trim() || null,
                    subject:       $('#add-subject').val().trim(),
                    module_number: parseInt($('#add-module-number').val(), 10) || null,
                    module_title:  $('#add-module-title').val().trim() || null,
                    sort_order:    parseInt($('#add-sort-order').val(), 10),
                    topic:         $('#add-topic').val().trim(),
                    source:        'manual',
                };

                if (!body.curriculum || !body.level || !body.subject || !body.topic || isNaN(body.sort_order)) {
                    $('#add-topic-error').text('Curriculum, level, subject, topic, and sort_order are required.').show();
                    return;
                }

                $('#btn-save-add').prop('disabled', true).text('Adding…');
                apiFetch('POST', REST_BASE, body)
                    .then(function() {
                        $('#modal-add-topic').hide();
                        $('#btn-load-topics').trigger('click');
                    })
                    .catch(function(err) { $('#add-topic-error').text(err.message).show(); })
                    .finally(function() { $('#btn-save-add').prop('disabled', false).text('Add Topic'); });
            });

            // ── Sync to Pinecone ──────────────────────────────────────────────

            $('#btn-sync-pinecone').on('click', function() {
                var f = getFilters();
                var scope = [f.level, f.period, f.subject].filter(Boolean).join('/') || 'all topics';
                if (!confirm('Sync ' + scope + ' to Pinecone? This re-embeds and upserts all active topics in the current filter scope. Continue?')) return;

                var $btn    = $('#btn-sync-pinecone');
                var $status = $('#sync-pinecone-status');
                $btn.prop('disabled', true).text('Syncing…');
                $status.text('Sending to Pinecone…').css('color', '#2271b1');

                var body = { curriculum: f.curriculum || 'tt_primary' };
                if (f.level)   body.level   = f.level;
                if (f.period)  body.period  = f.period;
                if (f.subject) body.subject = f.subject;

                apiFetch('POST', REST_BASE + '/sync-pinecone', body)
                    .then(function(r) {
                        $status.text('✓ ' + r.synced + ' synced, ' + r.failed + ' failed of ' + r.total + ' topics.').css('color', r.failed > 0 ? '#dba617' : '#00a32a');
                    })
                    .catch(function(err) {
                        $status.text('✗ Sync failed: ' + err.message).css('color', '#d63638');
                    })
                    .finally(function() {
                        $btn.prop('disabled', false).text('Sync to Pinecone');
                    });
            });

        })(jQuery);
        </script>
        <?php
    }

    // ── Filter Bar ────────────────────────────────────────────────────────────

    private static function render_filter_bar(): void {
        ?>
        <div class="knowly-filter-bar" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:16px;">
            <div>
                <label>Curriculum<br>
                    <select id="filter-curriculum" class="knowly-select">
                        <option value="tt_primary">T&amp;T Primary (SEA)</option>
                    </select>
                </label>
            </div>
            <div>
                <label>Level<br>
                    <select id="filter-level" class="knowly-select">
                        <option value="">All levels</option>
                        <option value="std_4">Standard 4</option>
                        <option value="std_5">Standard 5 (Capstone)</option>
                    </select>
                </label>
            </div>
            <div>
                <label>Period<br>
                    <select id="filter-period" class="knowly-select">
                        <option value="">All periods</option>
                        <option value="term_1">Term 1</option>
                        <option value="term_2">Term 2</option>
                        <option value="term_3">Term 3</option>
                        <option value="null">Capstone (no period)</option>
                    </select>
                </label>
            </div>
            <div>
                <label>Subject<br>
                    <select id="filter-subject" class="knowly-select">
                        <option value="">All subjects</option>
                        <option value="math">Mathematics</option>
                        <option value="english">English Language Arts</option>
                        <option value="science">Science</option>
                        <option value="social_studies">Social Studies</option>
                    </select>
                </label>
            </div>
            <div>
                <label>Status<br>
                    <select id="filter-status" class="knowly-select">
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>
                </label>
            </div>
        </div>
        <?php
    }

    // ── Add Topic Modal ───────────────────────────────────────────────────────

    private static function render_add_modal(): void {
        ?>
        <div id="modal-add-topic" class="knowly-modal" style="display:none;">
            <div class="knowly-modal-backdrop"></div>
            <div class="knowly-modal-box" style="max-width:520px;">
                <button class="knowly-modal-close">&times;</button>
                <h2>Add Topic</h2>
                <div id="add-topic-error" class="notice notice-error inline" style="display:none;"></div>
                <form id="form-add-topic">
                    <table class="form-table">
                        <tr>
                            <th><label for="add-curriculum">Curriculum</label></th>
                            <td><input type="text" id="add-curriculum" class="regular-text" value="tt_primary"></td>
                        </tr>
                        <tr>
                            <th><label for="add-level">Level *</label></th>
                            <td><input type="text" id="add-level" class="regular-text" placeholder="std_4"></td>
                        </tr>
                        <tr>
                            <th><label for="add-period">Period</label></th>
                            <td><input type="text" id="add-period" class="regular-text" placeholder="term_1 (blank for capstone)"></td>
                        </tr>
                        <tr>
                            <th><label for="add-subject">Subject *</label></th>
                            <td><input type="text" id="add-subject" class="regular-text" placeholder="math"></td>
                        </tr>
                        <tr>
                            <th><label for="add-module-number">Module #</label></th>
                            <td><input type="number" id="add-module-number" class="small-text" min="1" placeholder="1"></td>
                        </tr>
                        <tr>
                            <th><label for="add-module-title">Module Title</label></th>
                            <td><input type="text" id="add-module-title" class="regular-text" placeholder="Number Concepts and Place Value"></td>
                        </tr>
                        <tr>
                            <th><label for="add-sort-order">Sort Order *</label></th>
                            <td><input type="number" id="add-sort-order" class="small-text" placeholder="100"><br><span class="description">Formula: (moduleIndex × 100) + subtopicIndex</span></td>
                        </tr>
                        <tr>
                            <th><label for="add-topic">Topic *</label></th>
                            <td><textarea id="add-topic" class="large-text" rows="3" placeholder="The single learning objective string"></textarea></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" id="btn-save-add" class="button button-primary">Add Topic</button>
                        <button type="button" id="btn-cancel-add" class="button">Cancel</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    // ── Edit Topic Modal ──────────────────────────────────────────────────────

    private static function render_edit_modal(): void {
        ?>
        <div id="modal-edit-topic" class="knowly-modal" style="display:none;">
            <div class="knowly-modal-backdrop"></div>
            <div class="knowly-modal-box" style="max-width:520px;">
                <button class="knowly-modal-close">&times;</button>
                <h2>Edit Topic</h2>
                <p class="description">Context: <strong id="edit-topic-context"></strong></p>
                <div id="edit-topic-error" class="notice notice-error inline" style="display:none;"></div>
                <form id="form-edit-topic">
                    <table class="form-table">
                        <tr>
                            <th><label for="edit-module-title">Module Title</label></th>
                            <td><input type="text" id="edit-module-title" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="edit-sort-order">Sort Order *</label></th>
                            <td><input type="number" id="edit-sort-order" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="edit-topic">Topic *</label></th>
                            <td><textarea id="edit-topic" class="large-text" rows="3"></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="edit-status">Status</label></th>
                            <td>
                                <select id="edit-status">
                                    <option value="active">Active</option>
                                    <option value="archived">Archived</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" id="btn-save-edit" class="button button-primary">Save Changes</button>
                        <button type="button" id="btn-cancel-edit" class="button">Cancel</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
