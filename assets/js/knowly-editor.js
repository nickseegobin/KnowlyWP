/**
 * Knowly Editor — vanilla JS
 *
 * Communicates exclusively via WP REST API (/wp-json/knowly/v1/editor/*)
 * using the wp_rest nonce injected by the PHP panel.
 *
 * Three modules, one file. Each module initialises if its tab is active.
 */

/* global KnowlyEditor */

(function () {
    'use strict';

    if (typeof KnowlyEditor === 'undefined') return;

    const BASE       = KnowlyEditor.restBase;    // …/wp-json/knowly/v1/editor
    const NONCE      = KnowlyEditor.nonce;
    const TAX        = KnowlyEditor.taxonomy;
    const ACTIVE     = KnowlyEditor.tab;
    const AJAX_URL   = KnowlyEditor.ajaxUrl;
    const AJAX_NONCE = KnowlyEditor.ajaxNonce;

    // ── REST helper ───────────────────────────────────────────────────────────

    async function api(method, path, body) {
        const opts = {
            method,
            headers: {
                'Content-Type':  'application/json',
                'X-WP-Nonce':    NONCE,
            },
        };
        if (body !== undefined) opts.body = JSON.stringify(body);

        const res  = await fetch(BASE + path, opts);
        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            const msg = data.message || data.error || `HTTP ${res.status}`;
            throw new Error(msg);
        }
        return data;
    }

    // ── Taxonomy helpers ──────────────────────────────────────────────────────

    function getLevels(curriculum) {
        return TAX[curriculum]?.levels || [];
    }

    function getPeriods(curriculum) {
        return TAX[curriculum]?.periods || [];
    }

    function getSubjects(curriculum) {
        return TAX[curriculum]?.subjects || [];
    }

    function isCapstone(curriculum, level) {
        const levels = TAX[curriculum]?.levels || [];
        const found  = levels.find(l => (l.value || l) === level);
        return found ? !!found.is_capstone : false;
    }

    function populateSelect(sel, items, valueKey, labelKey, emptyLabel) {
        sel.innerHTML = `<option value="">${emptyLabel}</option>`;
        items.forEach(item => {
            const val   = valueKey ? item[valueKey] : item;
            const label = labelKey ? item[labelKey] : item;
            const opt   = document.createElement('option');
            opt.value       = val;
            opt.textContent = label;
            sel.appendChild(opt);
        });
    }

    function formatDate(iso) {
        if (!iso) return '—';
        return new Date(iso).toLocaleDateString('en-TT', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function truncate(str, n) {
        if (!str) return '—';
        return str.length > n ? str.slice(0, n) + '…' : str;
    }

    function statusBadge(status) {
        const map = {
            approved:       'knowly-badge ok',
            pending_review: 'knowly-badge warn',
            draft:          'knowly-badge warn',
            rejected:       'knowly-badge err',
        };
        return `<span class="${map[status] || 'knowly-badge'}">${status || '—'}</span>`;
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────

    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    function setStatus(elId, msg, type) {
        const el = document.getElementById(elId);
        if (!el) return;
        el.textContent   = msg;
        el.className     = `knowly-inline-status ${type || ''}`;
    }

    document.querySelectorAll('.knowly-modal-close').forEach(btn => {
        btn.addEventListener('click', () => {
            const mid = btn.dataset.modal;
            if (mid) closeModal(mid);
        });
    });

    // Close modals on backdrop click
    document.querySelectorAll('.knowly-modal').forEach(modal => {
        modal.addEventListener('click', e => {
            if (e.target === modal) closeModal(modal.id);
        });
    });

    // ── Pagination helper ─────────────────────────────────────────────────────

    function buildPagination(containerId, total, page, perPage, onPage) {
        const container = document.getElementById(containerId);
        if (!container) return;
        const pages = Math.ceil(total / perPage);
        if (pages <= 1) { container.innerHTML = ''; return; }

        let html = '<div class="knowly-pager">';
        if (page > 1)    html += `<button data-page="${page - 1}">‹ Prev</button>`;
        html += `<span>Page ${page} of ${pages} (${total} total)</span>`;
        if (page < pages) html += `<button data-page="${page + 1}">Next ›</button>`;
        html += '</div>';
        container.innerHTML = html;

        container.querySelectorAll('button[data-page]').forEach(btn => {
            btn.addEventListener('click', () => onPage(parseInt(btn.dataset.page, 10)));
        });
    }

    // =========================================================================
    // MODULE: TRAINING MATERIAL
    // =========================================================================

    if (ACTIVE === 'training') {
        let tmPage = 1;

        function tmFilters() {
            return {
                curriculum: document.getElementById('tm-filter-curriculum')?.value || '',
                level:      document.getElementById('tm-filter-level')?.value || '',
                period:     document.getElementById('tm-filter-period')?.value || '',
                subject:    document.getElementById('tm-filter-subject')?.value || '',
                page:       tmPage,
                per_page:   25,
            };
        }

        async function loadTM() {
            const tbody = document.getElementById('tm-tbody');
            tbody.innerHTML = '<tr><td colspan="8" class="knowly-loading">Loading…</td></tr>';

            try {
                const params = new URLSearchParams(Object.entries(tmFilters()).filter(([,v]) => v !== '' && v !== undefined));
                const data   = await api('GET', '/training-material?' + params);
                const items  = data.items || [];

                if (!items.length) {
                    tbody.innerHTML = '<tr><td colspan="8" class="knowly-empty">No training material found.</td></tr>';
                    document.getElementById('tm-pagination').innerHTML = '';
                    return;
                }

                tbody.innerHTML = items.map(r => `
                    <tr>
                        <td>${escHtml(r.curriculum)}</td>
                        <td>${escHtml(r.level)}</td>
                        <td>${escHtml(r.period || '—')}</td>
                        <td>${escHtml(r.subject)}</td>
                        <td>${escHtml(r.topic)}</td>
                        <td class="knowly-preview">${escHtml(truncate(r.content_preview, 100))}</td>
                        <td>${formatDate(r.updated_at)}</td>
                        <td class="knowly-actions">
                            <button class="button button-small tm-edit-btn" data-id="${r.id}">Edit</button>
                            <button class="button button-small knowly-danger-btn tm-delete-btn" data-id="${r.id}">Delete</button>
                        </td>
                    </tr>
                `).join('');

                buildPagination('tm-pagination', data.total, tmPage, 25, p => { tmPage = p; loadTM(); });
                bindTMRowActions();

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="8" class="knowly-error">Error: ${escHtml(err.message)}</td></tr>`;
            }
        }

        function bindTMRowActions() {
            document.querySelectorAll('.tm-edit-btn').forEach(btn => {
                btn.addEventListener('click', () => openTMEdit(parseInt(btn.dataset.id, 10)));
            });
            document.querySelectorAll('.tm-delete-btn').forEach(btn => {
                btn.addEventListener('click', () => openTMDelete(parseInt(btn.dataset.id, 10)));
            });
        }

        // Form dropdowns — cascade on curriculum change
        function tmFormCascade(curriculumSel, levelSel, periodRow, periodSel, subjectSel) {
            function onCurriculum() {
                const c = curriculumSel.value;
                populateSelect(levelSel, getLevels(c), 'value', 'label', 'Select Level');
                populateSelect(periodSel, getPeriods(c), 'value', 'label', 'N/A (Capstone)');
                populateSelect(subjectSel, getSubjects(c), 'value', 'label', 'Select Subject');
            }
            function onLevel() {
                const cap = isCapstone(curriculumSel.value, levelSel.value);
                if (periodRow) periodRow.style.display = cap ? 'none' : '';
            }
            curriculumSel.addEventListener('change', onCurriculum);
            levelSel.addEventListener('change', onLevel);
            onCurriculum();
        }

        // Filter dropdowns
        const filterCurr = document.getElementById('tm-filter-curriculum');
        const filterLev  = document.getElementById('tm-filter-level');
        const filterPer  = document.getElementById('tm-filter-period');
        const filterSubj = document.getElementById('tm-filter-subject');

        if (filterCurr) {
            filterCurr.addEventListener('change', () => {
                const c = filterCurr.value;
                populateSelect(filterLev,  getLevels(c),   'value', 'label', 'All Levels');
                populateSelect(filterPer,  getPeriods(c), 'value', 'label', 'All Periods');
                populateSelect(filterSubj, getSubjects(c), 'value', 'label', 'All Subjects');
            });
            // Trigger initial populate
            filterCurr.dispatchEvent(new Event('change'));
        }

        document.getElementById('tm-filter-btn')?.addEventListener('click', () => { tmPage = 1; loadTM(); });

        // Add button
        document.getElementById('tm-add-btn')?.addEventListener('click', () => {
            document.getElementById('tm-edit-id').value = '';
            document.getElementById('tm-modal-title').textContent = 'Add Training Material';
            document.getElementById('tm-form-topic').value    = '';
            document.getElementById('tm-form-subtopic').value = '';
            document.getElementById('tm-form-content').value  = '';
            setStatus('tm-save-status', '', '');
            openModal('tm-modal');

            tmFormCascade(
                document.getElementById('tm-form-curriculum'),
                document.getElementById('tm-form-level'),
                document.getElementById('tm-period-row'),
                document.getElementById('tm-form-period'),
                document.getElementById('tm-form-subject')
            );
        });

        // Edit button (opens modal pre-populated)
        async function openTMEdit(id) {
            try {
                const params = new URLSearchParams({ page: 1, per_page: 500 });
                const data   = await api('GET', '/training-material?' + params);
                const row    = (data.items || []).find(r => r.id === id);
                if (!row) throw new Error('Item not found');

                document.getElementById('tm-edit-id').value       = id;
                document.getElementById('tm-modal-title').textContent = 'Edit Training Material';
                document.getElementById('tm-form-topic').value    = row.topic || '';
                document.getElementById('tm-form-subtopic').value = row.subtopic || '';
                document.getElementById('tm-form-content').value  = row.content_text || row.content_preview || '';
                setStatus('tm-save-status', '', '');

                // Cascade then set values
                const currSel = document.getElementById('tm-form-curriculum');
                currSel.value = row.curriculum || 'tt_primary';
                tmFormCascade(
                    currSel,
                    document.getElementById('tm-form-level'),
                    document.getElementById('tm-period-row'),
                    document.getElementById('tm-form-period'),
                    document.getElementById('tm-form-subject')
                );
                setTimeout(() => {
                    document.getElementById('tm-form-level').value   = row.level   || '';
                    document.getElementById('tm-form-period').value  = row.period  || '';
                    document.getElementById('tm-form-subject').value = row.subject || '';
                }, 50);

                openModal('tm-modal');
            } catch (err) {
                alert('Could not load item: ' + err.message);
            }
        }

        // Save (create or update)
        document.getElementById('tm-save-btn')?.addEventListener('click', async () => {
            const id      = document.getElementById('tm-edit-id').value;
            const payload = {
                curriculum:   document.getElementById('tm-form-curriculum').value,
                level:        document.getElementById('tm-form-level').value,
                period:       document.getElementById('tm-form-period').value,
                subject:      document.getElementById('tm-form-subject').value,
                topic:        document.getElementById('tm-form-topic').value.trim(),
                subtopic:     document.getElementById('tm-form-subtopic').value.trim(),
                content_text: document.getElementById('tm-form-content').value.trim(),
            };

            if (!payload.level || !payload.subject || !payload.topic || !payload.content_text) {
                setStatus('tm-save-status', 'Level, Subject, Topic, and Content are required.', 'error');
                return;
            }

            setStatus('tm-save-status', 'Saving…', 'loading');
            try {
                if (id) {
                    await api('PATCH', `/training-material/${id}`, payload);
                } else {
                    await api('POST', '/training-material', payload);
                }
                closeModal('tm-modal');
                setStatus('tm-status-bar', id ? 'Training material updated.' : 'Training material added and synced to Pinecone.', 'ok');
                loadTM();
            } catch (err) {
                setStatus('tm-save-status', err.message, 'error');
            }
        });

        // Delete
        function openTMDelete(id) {
            document.getElementById('tm-delete-id').value = id;
            setStatus('tm-delete-status', '', '');
            openModal('tm-delete-modal');
        }

        document.getElementById('tm-delete-confirm-btn')?.addEventListener('click', async () => {
            const id = document.getElementById('tm-delete-id').value;
            setStatus('tm-delete-status', 'Deleting…', 'loading');
            try {
                await api('DELETE', `/training-material/${id}`);
                closeModal('tm-delete-modal');
                setStatus('tm-status-bar', 'Training material deleted.', 'ok');
                loadTM();
            } catch (err) {
                setStatus('tm-delete-status', err.message, 'error');
            }
        });

        // Sync from Pinecone button
        document.getElementById('tm-sync-btn')?.addEventListener('click', async () => {
            const btn        = document.getElementById('tm-sync-btn');
            const curriculum = document.getElementById('tm-filter-curriculum')?.value || 'tt_primary';

            btn.disabled = true;
            btn.textContent = 'Syncing…';
            setStatus('tm-status-bar', 'Syncing from Pinecone…', 'loading');

            try {
                const result = await api('POST', '/training-material/sync-from-pinecone', { curriculum });
                setStatus('tm-status-bar', `Sync complete — ${result.synced} item(s) synced.`, 'ok');
                tmPage = 1;
                loadTM();
            } catch (err) {
                setStatus('tm-status-bar', 'Sync failed: ' + err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Sync from Pinecone';
            }
        });

        loadTM();
    }

    // =========================================================================
    // MODULE: TRIALS
    // =========================================================================

    if (ACTIVE === 'trials') {
        let trialPage = 1;
        let editMode  = false;
        let currentPkg = null;

        function trialFilters() {
            return {
                curriculum:  document.getElementById('trial-filter-curriculum')?.value || '',
                level:       document.getElementById('trial-filter-level')?.value || '',
                period:      document.getElementById('trial-filter-period')?.value || '',
                subject:     document.getElementById('trial-filter-subject')?.value || '',
                difficulty:  document.getElementById('trial-filter-difficulty')?.value || '',
                status:      document.getElementById('trial-filter-status')?.value || '',
                page:        trialPage,
                per_page:    25,
            };
        }

        async function loadTrials() {
            const tbody = document.getElementById('trial-tbody');
            tbody.innerHTML = '<tr><td colspan="11" class="knowly-loading">Loading…</td></tr>';

            try {
                const params = new URLSearchParams(Object.entries(trialFilters()).filter(([,v]) => v !== '' && v !== undefined));
                const data   = await api('GET', '/trials?' + params);
                const pkgs   = data.packages || [];

                if (!pkgs.length) {
                    tbody.innerHTML = '<tr><td colspan="11" class="knowly-empty">No packages found.</td></tr>';
                    document.getElementById('trial-pagination').innerHTML = '';
                    return;
                }

                tbody.innerHTML = pkgs.map(p => `
                    <tr>
                        <td><code title="${escHtml(p.package_id)}">${escHtml(p.package_id.slice(-12))}</code></td>
                        <td>${escHtml(p.curriculum)}</td>
                        <td>${escHtml(p.level)}</td>
                        <td>${escHtml(p.period || '—')}</td>
                        <td>${escHtml(p.subject)}</td>
                        <td>${escHtml(p.difficulty || '—')}</td>
                        <td>${escHtml(p.trial_type)}</td>
                        <td>${statusBadge(p.status)}</td>
                        <td>${p.times_served ?? 0}</td>
                        <td>${formatDate(p.generated_at)}</td>
                        <td class="knowly-actions">
                            <button class="button button-small trial-view-btn" data-id="${escHtml(p.package_id)}">View</button>
                            ${p.status === 'pending_review' ? `
                                <button class="button button-small knowly-ok-btn trial-approve-btn" data-id="${escHtml(p.package_id)}">Approve</button>
                                <button class="button button-small knowly-danger-btn trial-reject-btn" data-id="${escHtml(p.package_id)}">Reject</button>
                            ` : ''}
                            ${p.status === 'rejected' ? `
                                <button class="button button-small knowly-danger-btn trial-delete-row-btn" data-id="${escHtml(p.package_id)}">Delete</button>
                            ` : ''}
                        </td>
                    </tr>
                `).join('');

                buildPagination('trial-pagination', data.total, trialPage, 25, p => { trialPage = p; loadTrials(); });
                bindTrialRowActions();

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="11" class="knowly-error">Error: ${escHtml(err.message)}</td></tr>`;
            }
        }

        function bindTrialRowActions() {
            document.querySelectorAll('.trial-view-btn').forEach(btn =>
                btn.addEventListener('click', () => openTrialView(btn.dataset.id, false)));

            document.querySelectorAll('.trial-approve-btn').forEach(btn =>
                btn.addEventListener('click', () => updateTrialStatus(btn.dataset.id, 'approved')));

            document.querySelectorAll('.trial-reject-btn').forEach(btn =>
                btn.addEventListener('click', () => updateTrialStatus(btn.dataset.id, 'rejected')));

            document.querySelectorAll('.trial-delete-row-btn').forEach(btn =>
                btn.addEventListener('click', () => {
                    document.getElementById('trial-delete-id').value = btn.dataset.id;
                    setStatus('trial-delete-status', '', '');
                    openModal('trial-delete-modal');
                }));
        }

        async function updateTrialStatus(packageId, status) {
            try {
                await api('PATCH', `/trials/${encodeURIComponent(packageId)}/status`, { status });
                setStatus('trial-status-bar', `Package ${status}.`, 'ok');
                loadTrials();
            } catch (err) {
                setStatus('trial-status-bar', err.message, 'error');
            }
        }

        async function openTrialView(packageId, inEditMode) {
            document.getElementById('trial-modal-title').textContent = inEditMode ? 'Edit Trial Package' : 'View Trial Package';
            document.getElementById('trial-modal-body').innerHTML    = '<p class="knowly-loading">Loading…</p>';
            document.getElementById('trial-save-edit-btn').style.display = 'none';
            document.getElementById('trial-edit-mode-btn').style.display = '';
            setStatus('trial-save-status', '', '');
            openModal('trial-view-modal');
            editMode = inEditMode;

            try {
                const data = await api('GET', '/trials/' + encodeURIComponent(packageId));
                currentPkg = data;
                renderTrialModal(data, inEditMode);
            } catch (err) {
                document.getElementById('trial-modal-body').innerHTML = `<p class="knowly-error">${escHtml(err.message)}</p>`;
            }
        }

        function renderTrialModal(data, editable) {
            const pkg = data.package || {};
            const qs  = pkg.questions || [];
            const as  = pkg.answer_sheet || [];

            let html = `
                <div class="knowly-meta-sidebar">
                    <strong>ID:</strong> <code>${escHtml(data.package_id)}</code><br>
                    <strong>Status:</strong> ${statusBadge(data.status)}<br>
                    <strong>Served:</strong> ${data.times_served ?? 0} times<br>
                    <strong>Generated:</strong> ${formatDate(data.generated_at)}
                </div>
                <div class="knowly-questions-list">
            `;

            qs.forEach((q, i) => {
                const answer = as.find(a => a.question_id === q.question_id) || {};
                html += `<div class="knowly-question-card" data-idx="${i}">
                    <div class="knowly-q-number">Q${i + 1}</div>
                    <div class="knowly-q-body">
                        ${editable
                            ? `<textarea class="knowly-form-textarea q-text-field" data-qid="${escHtml(q.question_id)}">${escHtml(q.question || '')}</textarea>`
                            : `<p>${escHtml(q.question || '')}</p>`
                        }
                        <div class="knowly-options-grid">`;

                ['A', 'B', 'C', 'D'].forEach(opt => {
                    const val = q.options?.[opt] || '';
                    html += editable
                        ? `<label class="knowly-option-label"><strong>${opt}.</strong> <input type="text" class="knowly-form-input q-opt-field" data-qid="${escHtml(q.question_id)}" data-opt="${opt}" value="${escHtml(val)}"></label>`
                        : `<div class="knowly-option ${answer.correct_answer === opt ? 'knowly-option-correct' : ''}"><strong>${opt}.</strong> ${escHtml(val)}</div>`;
                });

                html += `</div>`;

                if (editable) {
                    html += `<div class="knowly-form-row"><label>Correct Answer</label>
                        <select class="knowly-form-select q-answer-field" data-qid="${escHtml(q.question_id)}">
                            ${['A','B','C','D'].map(o => `<option value="${o}"${answer.correct_answer === o ? ' selected' : ''}>${o}</option>`).join('')}
                        </select></div>
                        <div class="knowly-form-row"><label>Explanation</label>
                        <textarea class="knowly-form-textarea q-explanation-field" data-qid="${escHtml(q.question_id)}">${escHtml(answer.explanation || '')}</textarea></div>`;
                } else {
                    html += `<div class="knowly-answer-row"><strong>Answer:</strong> ${escHtml(answer.correct_answer || '—')} — ${escHtml(answer.explanation || '—')}</div>`;
                }

                html += `</div></div>`;
            });

            html += '</div>';
            document.getElementById('trial-modal-body').innerHTML = html;

            document.getElementById('trial-edit-mode-btn').style.display = editable ? 'none' : '';
            document.getElementById('trial-save-edit-btn').style.display = editable ? '' : 'none';
        }

        document.getElementById('trial-edit-mode-btn')?.addEventListener('click', () => {
            if (currentPkg) renderTrialModal(currentPkg, true);
        });

        document.getElementById('trial-save-edit-btn')?.addEventListener('click', async () => {
            if (!currentPkg) return;
            const pkg      = JSON.parse(JSON.stringify(currentPkg.package));
            const questions = pkg.questions || [];
            const answerSheet = pkg.answer_sheet || [];

            // Collect edited values from DOM
            questions.forEach(q => {
                const qid = q.question_id;
                const tf  = document.querySelector(`.q-text-field[data-qid="${qid}"]`);
                if (tf) q.question = tf.value;
                ['A','B','C','D'].forEach(opt => {
                    const of = document.querySelector(`.q-opt-field[data-qid="${qid}"][data-opt="${opt}"]`);
                    if (of && q.options) q.options[opt] = of.value;
                });
                const ans = answerSheet.find(a => a.question_id === qid);
                const af  = document.querySelector(`.q-answer-field[data-qid="${qid}"]`);
                const ef  = document.querySelector(`.q-explanation-field[data-qid="${qid}"]`);
                if (ans) {
                    if (af) ans.correct_answer = af.value;
                    if (ef) ans.explanation    = ef.value;
                }
            });

            setStatus('trial-save-status', 'Saving…', 'loading');
            try {
                await api('PATCH', `/trials/${encodeURIComponent(currentPkg.package_id)}`, { package_data: pkg });
                setStatus('trial-save-status', 'Saved. Package reset to pending review.', 'ok');
                document.getElementById('trial-save-edit-btn').style.display = 'none';
                document.getElementById('trial-edit-mode-btn').style.display = '';
                currentPkg.status = 'pending_review';
                loadTrials();
            } catch (err) {
                setStatus('trial-save-status', err.message, 'error');
            }
        });

        document.getElementById('trial-delete-confirm-btn')?.addEventListener('click', async () => {
            const id = document.getElementById('trial-delete-id').value;
            setStatus('trial-delete-status', 'Deleting…', 'loading');
            try {
                await api('DELETE', `/trials/${encodeURIComponent(id)}`);
                closeModal('trial-delete-modal');
                closeModal('trial-view-modal');
                setStatus('trial-status-bar', 'Package deleted.', 'ok');
                loadTrials();
            } catch (err) {
                setStatus('trial-delete-status', err.message, 'error');
            }
        });

        // Filter cascade
        const tFilterCurr = document.getElementById('trial-filter-curriculum');
        if (tFilterCurr) {
            tFilterCurr.addEventListener('change', () => {
                const c = tFilterCurr.value;
                populateSelect(document.getElementById('trial-filter-level'),   getLevels(c),   'value', 'label', 'All Levels');
                populateSelect(document.getElementById('trial-filter-period'),  getPeriods(c), 'value','label','All Periods');
                populateSelect(document.getElementById('trial-filter-subject'), getSubjects(c), 'value','label','All Subjects');
            });
            tFilterCurr.dispatchEvent(new Event('change'));
        }

        document.getElementById('trial-filter-btn')?.addEventListener('click', () => { trialPage = 1; loadTrials(); });

        // Generate modal
        document.getElementById('trial-generate-btn')?.addEventListener('click', () => {
            setStatus('tg-status', '', '');
            openModal('trial-generate-modal');
            const tgCurr = document.getElementById('tg-curriculum');
            if (tgCurr) {
                const c = tgCurr.value;
                populateSelect(document.getElementById('tg-level'),   getLevels(c),   'value','label','Select Level');
                populateSelect(document.getElementById('tg-period'),  getPeriods(c),'value','label','N/A (Capstone)');
                populateSelect(document.getElementById('tg-subject'), getSubjects(c), 'value','label','Select Subject');
                tgCurr.addEventListener('change', () => {
                    const nc = tgCurr.value;
                    populateSelect(document.getElementById('tg-level'),   getLevels(nc),   'value','label','Select Level');
                    populateSelect(document.getElementById('tg-period'),  getPeriods(nc),'value','label','N/A (Capstone)');
                    populateSelect(document.getElementById('tg-subject'), getSubjects(nc), 'value','label','Select Subject');
                });
                document.getElementById('tg-level')?.addEventListener('change', () => {
                    const cap = isCapstone(tgCurr.value, document.getElementById('tg-level').value);
                    document.getElementById('tg-period-row').style.display = cap ? 'none' : '';
                });
            }
        });

        document.getElementById('tg-generate-btn')?.addEventListener('click', async () => {
            const body = {
                curriculum: document.getElementById('tg-curriculum')?.value || 'tt_primary',
                level:      document.getElementById('tg-level')?.value      || '',
                period:     document.getElementById('tg-period')?.value     || null,
                subject:    document.getElementById('tg-subject')?.value    || '',
                difficulty: document.getElementById('tg-difficulty')?.value || 'easy',
                trial_type: 'practice',
            };
            if (!body.level || !body.subject) {
                setStatus('tg-status', 'Level and Subject are required.', 'error');
                return;
            }
            setStatus('tg-status', 'Generating… this may take up to 60 seconds.', 'loading');
            try {
                await api('POST', '/trials/generate', body);
                setStatus('tg-status', 'Generated successfully — appears in table as pending_review.', 'ok');
                loadTrials();
            } catch (err) {
                setStatus('tg-status', err.message, 'error');
            }
        });

        // ── Trial Import ──────────────────────────────────────────────────────

        document.getElementById('trial-import-btn')?.addEventListener('click', () => {
            document.getElementById('trial-import-json').value = '';
            document.getElementById('trial-import-file').value = '';
            setStatus('trial-import-status', '', '');
            openModal('trial-import-modal');
        });

        // File upload → fills textarea
        document.getElementById('trial-import-file')?.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = e => { document.getElementById('trial-import-json').value = e.target.result; };
            reader.readAsText(file);
        });

        document.getElementById('trial-import-confirm-btn')?.addEventListener('click', async () => {
            const raw = document.getElementById('trial-import-json').value.trim();
            if (!raw) {
                setStatus('trial-import-status', 'Paste JSON or upload a file first.', 'error');
                return;
            }

            // Client-side parse check before sending
            try { JSON.parse(raw); } catch (e) {
                setStatus('trial-import-status', 'Invalid JSON: ' + e.message, 'error');
                return;
            }

            setStatus('trial-import-status', 'Importing…', 'loading');
            try {
                const form = new URLSearchParams();
                form.append('action', 'knowly_import_trial');
                form.append('nonce',  AJAX_NONCE);
                form.append('package_data', raw);

                const res  = await fetch(AJAX_URL, { method: 'POST', body: form });
                const data = await res.json();
                if (!data.success) throw new Error(data.data?.message || 'Import failed.');

                closeModal('trial-import-modal');
                setStatus('trial-status-bar', `Trial imported (${data.data.package_id}) — pending review.`, 'ok');
                loadTrials();
            } catch (err) {
                setStatus('trial-import-status', err.message, 'error');
            }
        });

        loadTrials();
    }

    // =========================================================================
    // MODULE: QUESTS
    // =========================================================================

    if (ACTIVE === 'quests') {
        let questPage  = 1;
        let currentQ   = null;

        function questFilters() {
            return {
                curriculum: document.getElementById('quest-filter-curriculum')?.value || '',
                level:      document.getElementById('quest-filter-level')?.value || '',
                period:     document.getElementById('quest-filter-period')?.value || '',
                subject:    document.getElementById('quest-filter-subject')?.value || '',
                status:     document.getElementById('quest-filter-status')?.value || '',
                page:       questPage,
                per_page:   25,
            };
        }

        async function loadQuests() {
            const tbody = document.getElementById('quest-tbody');
            tbody.innerHTML = '<tr><td colspan="9" class="knowly-loading">Loading…</td></tr>';

            try {
                const params = new URLSearchParams(Object.entries(questFilters()).filter(([,v]) => v !== '' && v !== undefined));
                const data   = await api('GET', '/quests?' + params);
                const quests = data.quests || [];

                if (!quests.length) {
                    tbody.innerHTML = '<tr><td colspan="9" class="knowly-empty">No quests found.</td></tr>';
                    document.getElementById('quest-pagination').innerHTML = '';
                    return;
                }

                tbody.innerHTML = quests.map(q => `
                    <tr>
                        <td><code title="${escHtml(q.quest_id)}">${escHtml(q.quest_id.slice(-18))}</code></td>
                        <td>${escHtml(q.curriculum)}</td>
                        <td>${escHtml(q.level)}</td>
                        <td>${escHtml(q.period || '—')}</td>
                        <td>${escHtml(q.subject)}</td>
                        <td>${escHtml(q.module_title || q.topic || '—')}</td>
                        <td>${statusBadge(q.status)}</td>
                        <td>${formatDate(q.generated_at)}</td>
                        <td class="knowly-actions">
                            <button class="button button-small quest-view-btn" data-id="${escHtml(q.quest_id)}">View</button>
                            ${q.status === 'draft' || q.status === 'pending_review' ? `
                                <button class="button button-small knowly-ok-btn quest-approve-btn" data-id="${escHtml(q.quest_id)}">Approve</button>
                                <button class="button button-small knowly-danger-btn quest-reject-btn" data-id="${escHtml(q.quest_id)}">Reject</button>
                            ` : ''}
                            ${q.status === 'draft' || q.status === 'rejected' ? `
                                <button class="button button-small knowly-danger-btn quest-delete-row-btn" data-id="${escHtml(q.quest_id)}">Delete</button>
                            ` : ''}
                        </td>
                    </tr>
                `).join('');

                buildPagination('quest-pagination', data.total, questPage, 25, p => { questPage = p; loadQuests(); });
                bindQuestRowActions();

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="9" class="knowly-error">Error: ${escHtml(err.message)}</td></tr>`;
            }
        }

        function bindQuestRowActions() {
            document.querySelectorAll('.quest-view-btn').forEach(btn =>
                btn.addEventListener('click', () => openQuestView(btn.dataset.id, false)));

            document.querySelectorAll('.quest-approve-btn').forEach(btn =>
                btn.addEventListener('click', () => updateQuestStatus(btn.dataset.id, 'approved')));

            document.querySelectorAll('.quest-reject-btn').forEach(btn =>
                btn.addEventListener('click', () => updateQuestStatus(btn.dataset.id, 'rejected')));

            document.querySelectorAll('.quest-delete-row-btn').forEach(btn =>
                btn.addEventListener('click', () => {
                    document.getElementById('quest-delete-id').value = btn.dataset.id;
                    setStatus('quest-delete-status', '', '');
                    openModal('quest-delete-modal');
                }));
        }

        async function updateQuestStatus(questId, status) {
            try {
                await api('PATCH', `/quests/${encodeURIComponent(questId)}/status`, { status });
                setStatus('quest-status-bar', `Quest ${status}.`, 'ok');
                loadQuests();
            } catch (err) {
                setStatus('quest-status-bar', err.message, 'error');
            }
        }

        async function openQuestView(questId, editable) {
            document.getElementById('quest-modal-title').textContent = editable ? 'Edit Quest' : 'View Quest';
            document.getElementById('quest-modal-body').innerHTML    = '<p class="knowly-loading">Loading…</p>';
            document.getElementById('quest-save-edit-btn').style.display = 'none';
            document.getElementById('quest-edit-mode-btn').style.display = '';
            setStatus('quest-save-status', '', '');
            openModal('quest-view-modal');

            try {
                const data = await api('GET', '/quests/' + encodeURIComponent(questId));
                currentQ   = data;
                renderQuestModal(data, editable);
            } catch (err) {
                document.getElementById('quest-modal-body').innerHTML = `<p class="knowly-error">${escHtml(err.message)}</p>`;
            }
        }

        function renderQuestModal(data, editable) {
            const content  = data.content || {};
            const sections = content.sections || [];

            let html = `
                <div class="knowly-meta-sidebar">
                    <strong>ID:</strong> <code>${escHtml(data.quest_id)}</code><br>
                    <strong>Status:</strong> ${statusBadge(data.status)}<br>
                    <strong>Level:</strong> ${escHtml(data.level)} ${data.period ? '/ ' + escHtml(data.period) : ''}<br>
                    <strong>Subject:</strong> ${escHtml(data.subject)}<br>
                    <strong>Module:</strong> ${escHtml(data.module_title || data.topic || '—')}
                </div>
                <div class="knowly-sections-list">
            `;

            const LOTTIE_TAG_HINT = `<div style="margin:0 0 10px;padding:8px 10px;background:#f0f6fc;border-left:3px solid #007cba;border-radius:3px;font-size:11.5px;color:#374151;line-height:1.9;">
                <strong style="display:block;margin-bottom:4px;">Lottie tags (type inline in paragraphs):</strong>
                <code>[start]</code> — jump to m1 (alias for [m1]) &nbsp;|&nbsp;
                <code>[next]</code> — advance to next marker in sequence<br>
                <code>[m1]</code> <code>[m2]</code> <code>[m3]</code> <code>[m4]</code> <code>[m5]</code> <code>[m6]</code> <code>[m7]</code> <code>[m8]</code> <code>[m9]</code> <code>[m10]</code> — play segment once then hold<br>
                <code>[m1-loop]</code> <code>[m2-loop]</code> … <code>[m10-loop]</code> — loop segment continuously until next tag fires<br>
                <code>[break]</code> — split paragraph into sentence chunks (advances subtitle with audio timing)<br>
                <span style="color:#6b7280;">Tags fire at the audio timestamp of the word immediately after them. All tags are hidden from students.</span>
            </div>`;

            sections.forEach((s, i) => {
                html += `<div class="knowly-section-card">
                    <h3>Section ${i + 1}: ${escHtml(s.title || '')}</h3>`;

                if (editable) {
                    const lottiVal = escHtml(s.lottie_url || '');
                    html += `<div class="knowly-form-row" style="margin-bottom:10px;">
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Lottie Animation URL <span style="font-weight:400;color:#6b7280;">(optional)</span></label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" class="knowly-form-input section-lottie-url" data-sec="${i}" value="${lottiVal}" placeholder="Paste URL or browse library…" style="flex:1;">
                            <button type="button" class="button button-secondary kn-lottie-browse-btn" data-sec="${i}" style="white-space:nowrap;flex-shrink:0;">📁 Browse</button>
                        </div>
                    </div>`;
                    html += LOTTIE_TAG_HINT;
                } else if (s.lottie_url) {
                    html += `<p style="font-size:12px;color:#6b7280;margin-bottom:8px;">🎬 Lottie: <code>${escHtml(s.lottie_url)}</code></p>`;
                }

                if (Array.isArray(s.explanation)) {
                    s.explanation.forEach((para, pi) => {
                        html += editable
                            ? `<textarea class="knowly-form-textarea section-explanation" data-sec="${i}" data-para="${pi}">${escHtml(para)}</textarea>`
                            : `<p>${escHtml(para)}</p>`;
                    });
                }

                if (Array.isArray(s.knowledge_check) && s.knowledge_check.length) {
                    html += `<h4>Knowledge Check</h4>`;
                    s.knowledge_check.forEach((kc, ki) => {
                        html += `<div class="knowly-kc-card"><p><strong>Q${ki + 1}.</strong> ${escHtml(kc.question || '')}</p>`;
                        ['A','B','C','D'].forEach(opt => {
                            if (kc.options?.[opt] !== undefined) {
                                html += `<div class="knowly-option ${kc.correct_answer === opt ? 'knowly-option-correct' : ''}"><strong>${opt}.</strong> ${escHtml(kc.options[opt])}</div>`;
                            }
                        });
                        html += `</div>`;
                    });
                }

                html += `</div>`;
            });

            html += '</div>';
            document.getElementById('quest-modal-body').innerHTML = html;
            document.getElementById('quest-edit-mode-btn').style.display = editable ? 'none' : '';
            document.getElementById('quest-save-edit-btn').style.display = editable ? '' : 'none';
        }

        document.getElementById('quest-edit-mode-btn')?.addEventListener('click', () => {
            if (currentQ) renderQuestModal(currentQ, true);
        });

        // Lottie Library picker — delegated, works after every renderQuestModal() call
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.kn-lottie-browse-btn');
            if (!btn) return;
            const sec = btn.dataset.sec;
            const input = document.querySelector(`.section-lottie-url[data-sec="${sec}"]`);
            if (!input) return;
            if (window.KnowlyLottiePicker) {
                window.KnowlyLottiePicker.open(function(url) { input.value = url; });
            } else {
                alert('Lottie Library is still loading — please try again in a moment.');
            }
        }, { once: false });

        document.getElementById('quest-save-edit-btn')?.addEventListener('click', async () => {
            if (!currentQ) return;
            const content  = JSON.parse(JSON.stringify(currentQ.content || {}));
            const sections = content.sections || [];

            // Collect edited explanation paragraphs
            document.querySelectorAll('.section-explanation').forEach(ta => {
                const si = parseInt(ta.dataset.sec, 10);
                const pi = parseInt(ta.dataset.para, 10);
                if (sections[si] && Array.isArray(sections[si].explanation)) {
                    sections[si].explanation[pi] = ta.value;
                }
            });

            // Collect lottie_url per section
            document.querySelectorAll('.section-lottie-url').forEach(input => {
                const si = parseInt(input.dataset.sec, 10);
                if (sections[si]) {
                    sections[si].lottie_url = input.value.trim() || null;
                }
            });

            content.sections = sections;

            setStatus('quest-save-status', 'Saving…', 'loading');
            try {
                await api('PATCH', `/quests/${encodeURIComponent(currentQ.quest_id)}`, {
                    content,
                    objectives: currentQ.objectives,
                });
                setStatus('quest-save-status', 'Saved. Quest reset to draft.', 'ok');
                document.getElementById('quest-save-edit-btn').style.display = 'none';
                document.getElementById('quest-edit-mode-btn').style.display = '';
                currentQ.status = 'draft';
                loadQuests();
            } catch (err) {
                setStatus('quest-save-status', err.message, 'error');
            }
        });

        document.getElementById('quest-delete-confirm-btn')?.addEventListener('click', async () => {
            const id = document.getElementById('quest-delete-id').value;
            setStatus('quest-delete-status', 'Deleting…', 'loading');
            try {
                await api('DELETE', `/quests/${encodeURIComponent(id)}`);
                closeModal('quest-delete-modal');
                closeModal('quest-view-modal');
                setStatus('quest-status-bar', 'Quest deleted.', 'ok');
                loadQuests();
            } catch (err) {
                setStatus('quest-delete-status', err.message, 'error');
            }
        });

        // Filter cascade
        const qFilterCurr = document.getElementById('quest-filter-curriculum');
        if (qFilterCurr) {
            qFilterCurr.addEventListener('change', () => {
                const c = qFilterCurr.value;
                populateSelect(document.getElementById('quest-filter-level'),   getLevels(c),   'value','label','All Levels');
                populateSelect(document.getElementById('quest-filter-period'),  getPeriods(c),'value','label','All Periods');
                populateSelect(document.getElementById('quest-filter-subject'), getSubjects(c), 'value','label','All Subjects');
            });
            qFilterCurr.dispatchEvent(new Event('change'));
        }

        document.getElementById('quest-filter-btn')?.addEventListener('click', () => { questPage = 1; loadQuests(); });

        // Generate modal
        document.getElementById('quest-generate-btn')?.addEventListener('click', () => {
            setStatus('qg-status', '', '');
            openModal('quest-generate-modal');

            const qgCurr  = document.getElementById('qg-curriculum');
            const qgLevel = document.getElementById('qg-level');
            const qgPer   = document.getElementById('qg-period');
            const qgSubj  = document.getElementById('qg-subject');
            const qgMod   = document.getElementById('qg-module');
            const qgTopic = document.getElementById('qg-topic');

            function populateQGLevels() {
                const c = qgCurr.value;
                populateSelect(qgLevel,  getLevels(c),   'value','label','Select Level');
                populateSelect(qgPer,    getPeriods(c),'value','label','N/A');
                populateSelect(qgSubj,   getSubjects(c), 'value','label','Select Subject');
                qgMod.innerHTML  = '<option value="">Select Module</option>';
                qgTopic.innerHTML = '<option value="">Select Topic</option>';
                document.getElementById('qg-module-row').style.display = 'none';
                document.getElementById('qg-topic-row').style.display  = 'none';
            }

            function populateQGModulesOrTopics() {
                const c   = qgCurr.value;
                const lev = qgLevel.value;
                const per = qgPer.value;
                const sub = qgSubj.value;
                const cap = isCapstone(c, lev);

                document.getElementById('qg-module-row').style.display = (!cap && per && sub) ? '' : 'none';
                document.getElementById('qg-topic-row').style.display  = (cap && sub) ? '' : 'none';
                document.getElementById('qg-period-row').style.display = cap ? 'none' : '';

                if (!cap && per && sub && TAX[c]) {
                    // Build module list from taxonomy
                    const subKey  = sub.replace(/-/g,'_');
                    const modules = TAX[c]?.levels; // not stored per level/period in WP option
                    // We don't have per-level/period modules in the WP option — show numeric input fallback
                    qgMod.innerHTML = '<option value="">Select Module Index</option>';
                    for (let i = 0; i < 12; i++) {
                        const opt = document.createElement('option');
                        opt.value = i; opt.textContent = `Module ${i + 1} (index ${i})`;
                        qgMod.appendChild(opt);
                    }
                }

                if (cap && sub && TAX[c]?.subjects) {
                    const capSubjects = TAX[c].subjects || [];
                    // Use curriculum_config capstone_subjects if available — fall back to generic
                    qgTopic.innerHTML = '<option value="">Select Topic</option>';
                    const capTopics = ['Number Theory','Fractions','Decimals','Percentages','Measurement','Geometry','Statistics','Algebra',
                                       'Comprehension','Grammar','Vocabulary','Punctuation',
                                       'Living Things','Energy','Forces','Earth'];
                    capTopics.forEach(t => {
                        const o = document.createElement('option');
                        o.value = t; o.textContent = t;
                        qgTopic.appendChild(o);
                    });
                }
            }

            qgCurr.addEventListener('change', populateQGLevels);
            qgLevel.addEventListener('change', populateQGModulesOrTopics);
            qgPer.addEventListener('change', populateQGModulesOrTopics);
            qgSubj.addEventListener('change', populateQGModulesOrTopics);
            populateQGLevels();
        });

        document.getElementById('qg-generate-btn')?.addEventListener('click', async () => {
            const c   = document.getElementById('qg-curriculum').value;
            const lev = document.getElementById('qg-level').value;
            const per = document.getElementById('qg-period').value;
            const sub = document.getElementById('qg-subject').value;
            const cap = isCapstone(c, lev);

            const body = { curriculum: c, level: lev, period: per || null, subject: sub };
            if (cap) {
                body.topic = document.getElementById('qg-topic').value || null;
            } else {
                const modIdx = document.getElementById('qg-module').value;
                body.module_index = modIdx !== '' ? parseInt(modIdx, 10) : null;
            }

            if (!lev || !sub) {
                setStatus('qg-status', 'Level and Subject are required.', 'error');
                return;
            }

            setStatus('qg-status', 'Generating Quest… this may take up to 90 seconds.', 'loading');
            try {
                await api('POST', '/quests/generate', body);
                setStatus('qg-status', 'Quest generated as draft — review and approve it in the table.', 'ok');
                loadQuests();
            } catch (err) {
                setStatus('qg-status', err.message, 'error');
            }
        });

        // ── Quest Import ──────────────────────────────────────────────────────

        document.getElementById('quest-import-btn')?.addEventListener('click', () => {
            document.getElementById('quest-import-json').value = '';
            document.getElementById('quest-import-file').value = '';
            setStatus('quest-import-status', '', '');
            openModal('quest-import-modal');

            // Populate metadata dropdowns
            const qiCurr  = document.getElementById('qi-curriculum');
            const qiLevel = document.getElementById('qi-level');
            const qiPer   = document.getElementById('qi-period');
            const qiSubj  = document.getElementById('qi-subject');

            function populateQI() {
                const c = qiCurr.value;
                populateSelect(qiLevel, getLevels(c),   'value', 'label', 'Select Level');
                populateSelect(qiPer,   getPeriods(c),  'value', 'label', 'N/A (Capstone)');
                populateSelect(qiSubj,  getSubjects(c), 'value', 'label', 'Select Subject');
            }
            function onQILevel() {
                const cap = isCapstone(qiCurr.value, qiLevel.value);
                document.getElementById('qi-period-row').style.display = cap ? 'none' : '';
            }
            qiCurr.removeEventListener('change', populateQI);
            qiCurr.addEventListener('change', populateQI);
            qiLevel.removeEventListener('change', onQILevel);
            qiLevel.addEventListener('change', onQILevel);
            populateQI();
        });

        // File upload → fills textarea
        document.getElementById('quest-import-file')?.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = e => { document.getElementById('quest-import-json').value = e.target.result; };
            reader.readAsText(file);
        });

        document.getElementById('quest-import-confirm-btn')?.addEventListener('click', async () => {
            const raw = document.getElementById('quest-import-json').value.trim();
            if (!raw) {
                setStatus('quest-import-status', 'Paste JSON or upload a file first.', 'error');
                return;
            }

            // Client-side parse check before sending
            try { JSON.parse(raw); } catch (e) {
                setStatus('quest-import-status', 'Invalid JSON: ' + e.message, 'error');
                return;
            }

            const level   = document.getElementById('qi-level').value;
            const subject = document.getElementById('qi-subject').value;
            if (!level || !subject) {
                setStatus('quest-import-status', 'Level and Subject are required.', 'error');
                return;
            }

            setStatus('quest-import-status', 'Importing…', 'loading');
            try {
                const form = new URLSearchParams();
                form.append('action',     'knowly_import_quest');
                form.append('nonce',      AJAX_NONCE);
                form.append('content',    raw);
                form.append('curriculum', document.getElementById('qi-curriculum').value || 'tt_primary');
                form.append('level',      level);
                form.append('period',     document.getElementById('qi-period').value || '');
                form.append('subject',    subject);
                form.append('topic',      document.getElementById('qi-topic').value.trim());

                const res  = await fetch(AJAX_URL, { method: 'POST', body: form });
                const data = await res.json();
                if (!data.success) throw new Error(data.data?.message || 'Import failed.');

                closeModal('quest-import-modal');
                setStatus('quest-status-bar', `Quest imported (${data.data.quest_id}) — review and approve it in the table.`, 'ok');
                loadQuests();
            } catch (err) {
                setStatus('quest-import-status', err.message, 'error');
            }
        });

        loadQuests();
    }

    // ── XSS escape helper ─────────────────────────────────────────────────────
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

})();
