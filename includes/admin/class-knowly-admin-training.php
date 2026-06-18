<?php
/**
 * Knowly_Admin_Training — Training Material Manager (Block 10).
 *
 * Displays content from the knowly_training_material table (which mirrors
 * Pinecone vectors). Supports Add, Edit, Delete, and Archive actions.
 * Pinecone is the source of truth for AI generation; this table is the
 * WP-side display/management layer.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Training {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_training_save',       [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_knowly_training_delete',      [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_knowly_training_archive',     [ __CLASS__, 'ajax_archive' ] );
        add_action( 'wp_ajax_knowly_training_get',         [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_knowly_training_sync',        [ __CLASS__, 'ajax_sync_to_pinecone' ] );
        add_action( 'wp_ajax_knowly_training_import_csv',  [ __CLASS__, 'ajax_import_csv' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_training_material';

        $status_filter = sanitize_key( $_GET['tm_status'] ?? 'active' );
        $subject_filter = sanitize_text_field( $_GET['tm_subject'] ?? '' );
        $level_filter   = sanitize_text_field( $_GET['tm_level'] ?? '' );

        $where  = [ 'status = %s' ];
        $params = [ $status_filter ];

        if ( $subject_filter ) {
            $where[]  = 'subject = %s';
            $params[] = $subject_filter;
        }
        if ( $level_filter ) {
            $where[]  = 'level = %s';
            $params[] = $level_filter;
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where_sql} ORDER BY level, subject, topic LIMIT 200",
            ...$params
        ) );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );
        $archived = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'archived'" );

        // Distinct values for filter dropdowns
        $subjects = $wpdb->get_col( "SELECT DISTINCT subject FROM {$table} WHERE status = 'active' ORDER BY subject" );
        $levels   = $wpdb->get_col( "SELECT DISTINCT level FROM {$table} WHERE status = 'active' ORDER BY level" );
        ?>
        <div class="wrap knowly-wrap">
            <h1>Training Material
                <button class="button button-primary" onclick="knowlyTM.openAdd()">+ Add Entry</button>
                <button class="button" onclick="knowlyTM.toggleImport()">&#8593; Import CSV</button>
            </h1>

            <!-- ── CSV Import Panel ──────────────────────────────────────── -->
            <div id="knowly-tm-import-panel" style="display:none;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:20px;max-width:900px;">
                <h2 style="margin-top:0;">Import from Google Sheets CSV</h2>
                <p style="color:#666;margin-top:0;">Export your Google Sheet as CSV and upload it here. Required columns: <code>curriculum, level, subject, topic, content</code>. Optional: <code>period, module_title</code>.</p>

                <p>
                    <button class="button" onclick="knowlyTM.downloadTemplate()">&#8595; Download CSV Template</button>
                </p>

                <div style="margin-top:12px;">
                    <label><strong>Select CSV file:</strong></label><br>
                    <input type="file" id="knowly-tm-csv-file" accept=".csv" style="margin-top:6px;" onchange="knowlyTM.onCSVFileChange(event)">
                </div>

                <div id="knowly-tm-csv-preview" style="display:none;margin-top:16px;"></div>

                <div id="knowly-tm-import-actions" style="display:none;margin-top:12px;">
                    <button id="knowly-tm-import-btn" class="button button-primary" onclick="knowlyTM.importCSV()">
                        Import <span id="knowly-tm-row-count">0</span> rows to Pinecone
                    </button>
                    <span style="margin-left:10px;font-size:12px;color:#888;">This will embed each row with Voyage AI and upsert into Pinecone. May take several minutes for large files.</span>
                </div>

                <div id="knowly-tm-import-result" style="display:none;margin-top:12px;"></div>
            </div>
            <!-- ── /CSV Import Panel ─────────────────────────────────────── -->

            <div class="knowly-stat-grid" style="grid-template-columns:repeat(3,1fr);max-width:500px;margin-bottom:20px;">
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $total ) ?></div>
                    <div class="knowly-stat-label">Active Entries</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $archived ) ?></div>
                    <div class="knowly-stat-label">Archived</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= count( $subjects ) ?></div>
                    <div class="knowly-stat-label">Subjects</div>
                </div>
            </div>

            <div class="knowly-filter-bar" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <form method="get" style="display:contents;">
                    <input type="hidden" name="page" value="knowly-training">
                    <select name="tm_status">
                        <option value="active"   <?= selected( $status_filter, 'active',   false ) ?>>Active</option>
                        <option value="archived" <?= selected( $status_filter, 'archived', false ) ?>>Archived</option>
                    </select>
                    <select name="tm_subject">
                        <option value="">All Subjects</option>
                        <?php foreach ( $subjects as $s ) : ?>
                        <option value="<?= esc_attr( $s ) ?>" <?= selected( $subject_filter, $s, false ) ?>><?= esc_html( $s ) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="tm_level">
                        <option value="">All Levels</option>
                        <?php foreach ( $levels as $l ) : ?>
                        <option value="<?= esc_attr( $l ) ?>" <?= selected( $level_filter, $l, false ) ?>><?= esc_html( $l ) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">Filter</button>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-training' ) ) ?>" class="button">Reset</a>
                </form>
            </div>

            <?php if ( empty( $items ) ) : ?>
            <div class="knowly-notice info">No training material entries found. Click <strong>+ Add Entry</strong> to create the first one.</div>
            <?php else : ?>
            <table class="knowly-table widefat" style="table-layout:auto;">
                <thead>
                    <tr>
                        <th>Level</th>
                        <th>Period</th>
                        <th>Subject</th>
                        <th>Topic</th>
                        <th>Subtopic</th>
                        <th>Vector ID</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $item ) : ?>
                    <tr>
                        <td><?= esc_html( $item->level ) ?></td>
                        <td><?= esc_html( $item->period ?? '—' ) ?></td>
                        <td><?= esc_html( $item->subject ) ?></td>
                        <td><?= esc_html( $item->topic ) ?></td>
                        <td><?= esc_html( $item->subtopic ?? '—' ) ?></td>
                        <td><code style="font-size:11px;"><?= esc_html( $item->vector_id ) ?></code></td>
                        <td><span class="knowly-badge <?= $item->status === 'active' ? 'ok' : 'warn' ?>"><?= esc_html( $item->status ) ?></span></td>
                        <td>
                            <button class="button button-small" onclick="knowlyTM.openEdit(<?= (int) $item->id ?>)">Edit</button>
                            <?php if ( $item->status === 'active' ) : ?>
                            <button class="button button-small" onclick="knowlyTM.archive(<?= (int) $item->id ?>)">Archive</button>
                            <?php else : ?>
                            <button class="button button-small" onclick="knowlyTM.restore(<?= (int) $item->id ?>)">Restore</button>
                            <?php endif; ?>
                            <button class="button button-small" style="color:#d63638;" onclick="knowlyTM.delete(<?= (int) $item->id ?>, '<?= esc_js( $item->vector_id ) ?>')">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Add/Edit Modal -->
        <div id="knowly-tm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:28px;width:680px;max-height:90vh;overflow-y:auto;">
                <h2 id="knowly-tm-modal-title">Add Training Entry</h2>
                <input type="hidden" id="tm-id" value="">
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th><label>Vector ID</label></th>
                        <td><input type="text" id="tm-vector-id" class="regular-text" placeholder="e.g. tm-std4-term1-math-fractions-001"></td>
                    </tr>
                    <tr>
                        <th><label>Curriculum</label></th>
                        <td>
                            <select id="tm-curriculum">
                                <option value="tt_primary">tt_primary</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Level</label></th>
                        <td>
                            <select id="tm-level">
                                <option value="std_1">std_1</option>
                                <option value="std_2">std_2</option>
                                <option value="std_3">std_3</option>
                                <option value="std_4">std_4</option>
                                <option value="std_5">std_5</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Period</label></th>
                        <td>
                            <select id="tm-period">
                                <option value="">(none / capstone)</option>
                                <option value="term_1">term_1</option>
                                <option value="term_2">term_2</option>
                                <option value="term_3">term_3</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Subject</label></th>
                        <td>
                            <select id="tm-subject">
                                <option value="math">math</option>
                                <option value="english">english</option>
                                <option value="science">science</option>
                                <option value="social_studies">social_studies</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Topic</label></th>
                        <td><input type="text" id="tm-topic" class="regular-text" placeholder="e.g. Fractions"></td>
                    </tr>
                    <tr>
                        <th><label>Subtopic</label></th>
                        <td><input type="text" id="tm-subtopic" class="regular-text" placeholder="Optional — e.g. Adding fractions with like denominators"></td>
                    </tr>
                    <tr>
                        <th><label>Content</label></th>
                        <td><textarea id="tm-content" rows="8" class="large-text" placeholder="Training material text (this is what gets upserted to Pinecone as the vector's text content)"></textarea></td>
                    </tr>
                </table>
                <p style="margin-top:16px;display:flex;gap:10px;">
                    <button class="button button-primary" onclick="knowlyTM.save()">Save &amp; Sync to Pinecone</button>
                    <button class="button" onclick="knowlyTM.closeModal()">Cancel</button>
                    <span id="tm-modal-status" style="line-height:32px;font-size:13px;color:#666;"></span>
                </p>
            </div>
        </div>

        <script>
        const knowlyTM = {

            // ── CSV Import ────────────────────────────────────────────────

            toggleImport() {
                const panel = document.getElementById('knowly-tm-import-panel');
                panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            },

            downloadTemplate() {
                const header  = 'curriculum,level,period,subject,module_title,topic,content';
                const example = 'tt_primary,std_4,term_1,math,Place Value,"Place value up to 1,000,000","Students will understand and work with numbers up to one million. They will read, write, and order these numbers and understand the value of each digit."';
                const blob = new Blob([header + '\n' + example], { type: 'text/csv' });
                const a = Object.assign(document.createElement('a'), {
                    href: URL.createObjectURL(blob), download: 'knowly-training-template.csv'
                });
                a.click();
            },

            _parsedRows: [],

            _parseCSVLine(line) {
                const result = [];
                let cur = '', inQ = false;
                for (let i = 0; i < line.length; i++) {
                    const ch = line[i];
                    if (ch === '"') {
                        if (inQ && line[i+1] === '"') { cur += '"'; i++; }
                        else inQ = !inQ;
                    } else if (ch === ',' && !inQ) { result.push(cur); cur = ''; }
                    else cur += ch;
                }
                result.push(cur);
                return result;
            },

            _parseCSV(text) {
                const lines = text.trim().split(/\r?\n/);
                const headers = this._parseCSVLine(lines[0]).map(h => h.trim());
                return lines.slice(1)
                    .filter(l => l.trim())
                    .map(l => {
                        const vals = this._parseCSVLine(l);
                        const row = {};
                        headers.forEach((h, i) => row[h] = (vals[i] || '').trim());
                        return row;
                    })
                    .filter(r => r.topic && r.content);
            },

            onCSVFileChange(e) {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = ev => {
                    this._parsedRows = this._parseCSV(ev.target.result);
                    this._showCSVPreview(this._parsedRows);
                };
                reader.readAsText(file);
            },

            _showCSVPreview(rows) {
                const preview = document.getElementById('knowly-tm-csv-preview');
                const actions = document.getElementById('knowly-tm-import-actions');
                document.getElementById('knowly-tm-row-count').textContent = rows.length;

                if (!rows.length) {
                    preview.innerHTML = '<p style="color:#d63638;">No valid rows found. Check that your CSV has <code>topic</code> and <code>content</code> columns.</p>';
                    preview.style.display = 'block';
                    actions.style.display = 'none';
                    return;
                }

                const cols = ['curriculum','level','period','subject','module_title','topic','content'];
                const sample = rows.slice(0, 5);
                let html = `<p>Found <strong>${rows.length} rows</strong>. Preview (first ${Math.min(5,rows.length)}):</p>`;
                html += '<div style="overflow-x:auto;"><table class="widefat striped" style="font-size:12px;"><thead><tr>';
                cols.forEach(c => html += `<th>${c}</th>`);
                html += '</tr></thead><tbody>';
                sample.forEach(row => {
                    html += '<tr>';
                    cols.forEach(c => {
                        const v = row[c] || '';
                        html += `<td>${v.length > 50 ? v.slice(0,50)+'…' : v}</td>`;
                    });
                    html += '</tr>';
                });
                html += '</tbody></table></div>';

                preview.innerHTML = html;
                preview.style.display = 'block';
                actions.style.display = 'block';
            },

            async importCSV() {
                const btn = document.getElementById('knowly-tm-import-btn');
                const result = document.getElementById('knowly-tm-import-result');
                btn.disabled = true;
                btn.textContent = 'Importing… (this may take several minutes)';
                result.style.display = 'none';

                try {
                    const resp = await jQuery.post(ajaxurl, {
                        action: 'knowly_training_import_csv',
                        nonce:  KnowlyAdmin.nonce,
                        rows:   JSON.stringify(this._parsedRows),
                    });

                    if (!resp.success) {
                        result.innerHTML = `<div class="notice notice-error inline"><p>Import failed: ${resp.data?.message || 'Unknown error'}</p></div>`;
                    } else {
                        const d = resp.data;
                        const pc = d.pinecone || {};
                        const cls = (pc.failed > 0) ? 'notice-warning' : 'notice-success';
                        let html = `<div class="notice ${cls} inline"><p>`;
                        html += `DB: ${d.db_inserted} inserted, ${d.db_updated} updated. `;
                        html += `Pinecone: ${pc.synced ?? '?'} synced, ${pc.failed ?? '?'} failed of ${pc.total ?? '?'}.`;
                        html += `</p></div>`;
                        if (pc.errors?.length) {
                            html += '<ul style="margin-top:8px;font-size:12px;">';
                            pc.errors.forEach(e => html += `<li style="color:#d63638;">${e.topic}: ${e.error}</li>`);
                            html += '</ul>';
                        }
                        result.innerHTML = html;
                        setTimeout(() => location.reload(), 2000);
                    }
                } catch (err) {
                    result.innerHTML = `<div class="notice notice-error inline"><p>Error: ${err.statusText || err.message || 'Request failed'}</p></div>`;
                }

                result.style.display = 'block';
                btn.disabled = false;
                btn.textContent = `Import ${this._parsedRows.length} rows to Pinecone`;
            },

            // ── Manual entry ─────────────────────────────────────────────

            openAdd() {
                document.getElementById('tm-id').value = '';
                document.getElementById('tm-vector-id').value = '';
                document.getElementById('tm-curriculum').value = 'tt_primary';
                document.getElementById('tm-level').value = 'std_4';
                document.getElementById('tm-period').value = 'term_1';
                document.getElementById('tm-subject').value = 'math';
                document.getElementById('tm-topic').value = '';
                document.getElementById('tm-subtopic').value = '';
                document.getElementById('tm-content').value = '';
                document.getElementById('knowly-tm-modal-title').textContent = 'Add Training Entry';
                document.getElementById('tm-modal-status').textContent = '';
                document.getElementById('knowly-tm-modal').style.display = 'flex';
            },

            openEdit(id) {
                jQuery.post(ajaxurl, {
                    action: 'knowly_training_get',
                    nonce:  KnowlyAdmin.nonce,
                    id
                }, r => {
                    if (!r.success) { alert(r.data?.message || 'Failed to load entry'); return; }
                    const d = r.data;
                    document.getElementById('tm-id').value          = d.id;
                    document.getElementById('tm-vector-id').value   = d.vector_id;
                    document.getElementById('tm-curriculum').value  = d.curriculum;
                    document.getElementById('tm-level').value       = d.level;
                    document.getElementById('tm-period').value      = d.period || '';
                    document.getElementById('tm-subject').value     = d.subject;
                    document.getElementById('tm-topic').value       = d.topic;
                    document.getElementById('tm-subtopic').value    = d.subtopic || '';
                    document.getElementById('tm-content').value     = d.content_text;
                    document.getElementById('knowly-tm-modal-title').textContent = 'Edit Training Entry';
                    document.getElementById('tm-modal-status').textContent = '';
                    document.getElementById('knowly-tm-modal').style.display = 'flex';
                });
            },

            closeModal() {
                document.getElementById('knowly-tm-modal').style.display = 'none';
            },

            save() {
                const status = document.getElementById('tm-modal-status');
                status.textContent = 'Saving...';
                jQuery.post(ajaxurl, {
                    action:      'knowly_training_save',
                    nonce:       KnowlyAdmin.nonce,
                    id:          document.getElementById('tm-id').value,
                    vector_id:   document.getElementById('tm-vector-id').value,
                    curriculum:  document.getElementById('tm-curriculum').value,
                    level:       document.getElementById('tm-level').value,
                    period:      document.getElementById('tm-period').value,
                    subject:     document.getElementById('tm-subject').value,
                    topic:       document.getElementById('tm-topic').value,
                    subtopic:    document.getElementById('tm-subtopic').value,
                    content_text:document.getElementById('tm-content').value,
                }, r => {
                    if (!r.success) { status.textContent = r.data?.message || 'Error'; return; }
                    status.textContent = 'Saved!';
                    setTimeout(() => { location.reload(); }, 800);
                });
            },

            archive(id) {
                if (!confirm('Archive this entry? It will remain in the DB but be hidden from active use.')) return;
                jQuery.post(ajaxurl, { action: 'knowly_training_archive', nonce: KnowlyAdmin.nonce, id, status: 'archived' }, r => {
                    if (r.success) location.reload();
                    else alert(r.data?.message || 'Error');
                });
            },

            restore(id) {
                jQuery.post(ajaxurl, { action: 'knowly_training_archive', nonce: KnowlyAdmin.nonce, id, status: 'active' }, r => {
                    if (r.success) location.reload();
                    else alert(r.data?.message || 'Error');
                });
            },

            delete(id, vectorId) {
                if (!confirm(`Permanently delete entry "${vectorId}"? This also removes the vector from Pinecone.`)) return;
                jQuery.post(ajaxurl, { action: 'knowly_training_delete', nonce: KnowlyAdmin.nonce, id }, r => {
                    if (r.success) location.reload();
                    else alert(r.data?.message || 'Error');
                });
            },
        };
        </script>
        <?php
    }

    // ── AJAX: Get single entry ────────────────────────────────────────────────

    public static function ajax_get(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $id   = (int) ( $_POST['id'] ?? 0 );
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knowly_training_material WHERE id = %d",
            $id
        ) );

        if ( ! $item ) {
            wp_send_json_error( [ 'message' => 'Entry not found' ] );
        }

        wp_send_json_success( $item );
    }

    // ── AJAX: Save (insert or update) + sync to Pinecone ─────────────────────

    public static function ajax_save(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $table = $wpdb->prefix . 'knowly_training_material';

        $id           = (int) ( $_POST['id'] ?? 0 );
        $vector_id    = sanitize_text_field( $_POST['vector_id'] ?? '' );
        $curriculum   = sanitize_key( $_POST['curriculum'] ?? 'tt_primary' );
        $level        = sanitize_key( $_POST['level'] ?? '' );
        $period       = sanitize_key( $_POST['period'] ?? '' ) ?: null;
        $subject      = sanitize_key( $_POST['subject'] ?? '' );
        $topic        = sanitize_text_field( $_POST['topic'] ?? '' );
        $subtopic     = sanitize_text_field( $_POST['subtopic'] ?? '' ) ?: null;
        $content_text = sanitize_textarea_field( $_POST['content_text'] ?? '' );
        $now          = current_time( 'mysql' );

        if ( ! $vector_id || ! $level || ! $subject || ! $topic || ! $content_text ) {
            wp_send_json_error( [ 'message' => 'vector_id, level, subject, topic, and content are required.' ] );
        }

        if ( $id ) {
            $updated = $wpdb->update(
                $table,
                compact( 'vector_id', 'curriculum', 'level', 'period', 'subject', 'topic', 'subtopic', 'content_text' ) + [ 'updated_at' => $now ],
                [ 'id' => $id ]
            );
            if ( $updated === false ) {
                wp_send_json_error( [ 'message' => 'DB update failed: ' . $wpdb->last_error ] );
            }
        } else {
            $inserted = $wpdb->insert(
                $table,
                compact( 'vector_id', 'curriculum', 'level', 'period', 'subject', 'topic', 'subtopic', 'content_text' ) + [
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if ( ! $inserted ) {
                wp_send_json_error( [ 'message' => 'DB insert failed: ' . $wpdb->last_error ] );
            }
            $id = $wpdb->insert_id;
        }

        // Sync to Pinecone via Railway
        $sync_result = self::sync_vector_to_pinecone( $vector_id, $content_text, [
            'curriculum' => $curriculum,
            'level'      => $level,
            'period'     => $period,
            'subject'    => $subject,
            'topic'      => $topic,
            'subtopic'   => $subtopic,
        ] );

        wp_send_json_success( [ 'id' => $id, 'pinecone' => $sync_result ] );
    }

    // ── AJAX: Archive / Restore ───────────────────────────────────────────────

    public static function ajax_archive(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $id     = (int) ( $_POST['id'] ?? 0 );
        $status = in_array( $_POST['status'] ?? '', [ 'active', 'archived' ], true ) ? $_POST['status'] : 'archived';

        $updated = $wpdb->update(
            $wpdb->prefix . 'knowly_training_material',
            [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'DB update failed: ' . $wpdb->last_error ] );
        }

        wp_send_json_success( [ 'id' => $id, 'status' => $status ] );
    }

    // ── AJAX: Delete (DB + Pinecone vector) ───────────────────────────────────

    public static function ajax_delete(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $id  = (int) ( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT vector_id FROM {$wpdb->prefix}knowly_training_material WHERE id = %d",
            $id
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Entry not found' ] );
        }

        // Delete from Pinecone via Railway
        $pinecone = self::delete_vector_from_pinecone( $row->vector_id );

        $wpdb->delete( $wpdb->prefix . 'knowly_training_material', [ 'id' => $id ] );

        wp_send_json_success( [ 'id' => $id, 'vector_id' => $row->vector_id, 'pinecone' => $pinecone ] );
    }

    // ── AJAX: Manual Pinecone sync (re-upsert) ────────────────────────────────

    public static function ajax_sync_to_pinecone(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $id  = (int) ( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}knowly_training_material WHERE id = %d",
            $id
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Entry not found' ] );
        }

        $result = self::sync_vector_to_pinecone( $row->vector_id, $row->content_text, [
            'curriculum' => $row->curriculum,
            'level'      => $row->level,
            'period'     => $row->period,
            'subject'    => $row->subject,
            'topic'      => $row->topic,
            'subtopic'   => $row->subtopic,
        ] );

        wp_send_json_success( $result );
    }

    // ── AJAX: CSV Bulk Import ─────────────────────────────────────────────────

    public static function ajax_import_csv(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        set_time_limit( 600 );

        $rows = json_decode( stripslashes( $_POST['rows'] ?? '[]' ), true );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            wp_send_json_error( [ 'message' => 'No rows provided' ] );
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'knowly_training_material';
        $now         = current_time( 'mysql' );
        $db_inserted = 0;
        $db_updated  = 0;

        // Normalise and upsert each row into the WP mirror table
        $clean_rows = [];
        foreach ( $rows as $row ) {
            $curriculum   = sanitize_key( $row['curriculum']   ?? 'tt_primary' );
            $level        = sanitize_key( $row['level']        ?? '' );
            $period       = sanitize_key( $row['period']       ?? '' ) ?: null;
            $subject      = sanitize_key( $row['subject']      ?? '' );
            $module_title = sanitize_text_field( $row['module_title'] ?? '' ) ?: null;
            $topic        = sanitize_text_field( $row['topic']   ?? '' );
            $content      = sanitize_textarea_field( $row['content'] ?? '' );

            if ( ! $level || ! $subject || ! $topic || ! $content ) continue;

            $slug      = trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( $topic ) ), '_' );
            $vector_id = "tm-{$curriculum}-{$level}-{$subject}-{$slug}";

            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE vector_id = %s",
                $vector_id
            ) );

            if ( $existing_id ) {
                $wpdb->update(
                    $table,
                    [ 'curriculum' => $curriculum, 'level' => $level, 'period' => $period,
                      'subject' => $subject, 'topic' => $topic, 'subtopic' => $module_title,
                      'content_text' => $content, 'updated_at' => $now ],
                    [ 'vector_id' => $vector_id ]
                );
                $db_updated++;
            } else {
                $wpdb->insert( $table, [
                    'vector_id'    => $vector_id,
                    'curriculum'   => $curriculum,
                    'level'        => $level,
                    'period'       => $period,
                    'subject'      => $subject,
                    'topic'        => $topic,
                    'subtopic'     => $module_title,
                    'content_text' => $content,
                    'status'       => 'active',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ] );
                $db_inserted++;
            }

            // Pass normalised row with generated vector_id to Railway
            $clean_rows[] = compact( 'curriculum', 'level', 'period', 'subject', 'module_title', 'topic', 'content' );
        }

        // Sync all rows to Pinecone via Railway
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint || ! $server_key ) {
            wp_send_json_success( [
                'db_inserted' => $db_inserted,
                'db_updated'  => $db_updated,
                'pinecone'    => [ 'skipped' => true, 'reason' => 'Railway not configured' ],
            ] );
        }

        $response = wp_remote_post( "{$endpoint}/api/v1/training/import", [
            'timeout' => 300,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-AEP-Server-Key' => $server_key,
            ],
            'body' => wp_json_encode( [ 'rows' => $clean_rows ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_success( [
                'db_inserted' => $db_inserted,
                'db_updated'  => $db_updated,
                'pinecone'    => [ 'error' => $response->get_error_message() ],
            ] );
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $body    = json_decode( wp_remote_retrieve_body( $response ), true );
        $pinecone = array_merge( [ 'http_status' => $code ], $body ?? [] );

        wp_send_json_success( compact( 'db_inserted', 'db_updated', 'pinecone' ) );
    }

    // ── Railway Helpers ───────────────────────────────────────────────────────

    private static function sync_vector_to_pinecone( string $vector_id, string $content_text, array $metadata ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint || ! $server_key ) {
            return [ 'skipped' => true, 'reason' => 'Railway not configured' ];
        }

        $response = wp_remote_post(
            "{$endpoint}/api/v1/training/upsert",
            [
                'timeout' => 20,
                'headers' => [
                    'Content-Type'    => 'application/json',
                    'X-AEP-Server-Key' => $server_key,
                ],
                'body' => wp_json_encode( [
                    'vector_id'    => $vector_id,
                    'content_text' => $content_text,
                    'metadata'     => $metadata,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return [ 'status' => $code, 'body' => $body ];
    }

    private static function delete_vector_from_pinecone( string $vector_id ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint || ! $server_key ) {
            return [ 'skipped' => true, 'reason' => 'Railway not configured' ];
        }

        $response = wp_remote_request(
            "{$endpoint}/api/v1/training/delete",
            [
                'method'  => 'DELETE',
                'timeout' => 15,
                'headers' => [
                    'Content-Type'    => 'application/json',
                    'X-AEP-Server-Key' => $server_key,
                ],
                'body' => wp_json_encode( [ 'vector_id' => $vector_id ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return [ 'status' => $code, 'body' => $body ];
    }
}
