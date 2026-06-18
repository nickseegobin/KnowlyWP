<?php
/**
 * Knowly_Admin_Curriculum — Curriculum setup and management.
 *
 * Two views:
 *   Overview  (?page=knowly-curriculum)          — level grid with period seeding status
 *   Detail    (?page=knowly-curriculum&level=X)  — subject tabs + period tabs + CSV import
 *
 * CSV import is the single action that writes both curriculum structure
 * (curriculum_topics in Supabase) and training content (Pinecone tm-* vectors).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Curriculum {

	// ── Boot ──────────────────────────────────────────────────────────────────

	public static function boot(): void {
		add_action( 'wp_ajax_knowly_curriculum_overview',       [ __CLASS__, 'ajax_overview' ] );
		add_action( 'wp_ajax_knowly_curriculum_detail',         [ __CLASS__, 'ajax_detail' ] );
		add_action( 'wp_ajax_knowly_curriculum_import',         [ __CLASS__, 'ajax_import' ] );
		add_action( 'wp_ajax_knowly_curriculum_download',       [ __CLASS__, 'ajax_download' ] );
		add_action( 'wp_ajax_knowly_curriculum_clear',          [ __CLASS__, 'ajax_clear' ] );
		add_action( 'wp_ajax_knowly_curriculum_module_topics',  [ __CLASS__, 'ajax_module_topics' ] );
		add_action( 'wp_ajax_knowly_curriculum_save_topic',     [ __CLASS__, 'ajax_save_topic' ] );
		add_action( 'wp_ajax_knowly_curriculum_remove_subject', [ __CLASS__, 'ajax_remove_subject' ] );
		add_action( 'wp_ajax_knowly_badge_svg_get',             [ __CLASS__, 'ajax_badge_svg_get' ] );
		add_action( 'wp_ajax_knowly_badge_svg_save',            [ __CLASS__, 'ajax_badge_svg_save' ] );
	}

	// ── Page Entry ────────────────────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$level = sanitize_key( $_GET['level'] ?? '' );
		$nonce = wp_create_nonce( 'knowly_curriculum_nonce' );

		if ( $level ) {
			self::render_detail( $level, $nonce );
		} else {
			self::render_overview( $nonce );
		}
	}

	// ── Overview ──────────────────────────────────────────────────────────────

	private static function render_overview( string $nonce ): void {
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<div class="wrap knowly-wrap" id="knowly-curriculum-overview">
			<h1>Curriculum</h1>
			<p class="description">Each cell shows topics seeded for that level and period. Click <strong>Edit</strong> to upload or update content.</p>

			<div style="margin:16px 0 20px;">
				<button class="button button-primary" id="btn-add-level">+ Add New Level</button>
				<span id="overview-loading" style="margin-left:12px;color:#777;display:none;">Loading…</span>
			</div>

			<div id="overview-grid"></div>
		</div>

		<!-- Add Level Modal -->
		<div id="modal-add-level" class="knowly-modal" style="display:none;">
			<div class="knowly-modal-backdrop"></div>
			<div class="knowly-modal-box" style="max-width:440px;">
				<button class="knowly-modal-close">&times;</button>
				<h2>Add New Level</h2>
				<p class="description">Enter the level identifier used in your curriculum (e.g. <code>std_4</code>, <code>form_1</code>, <code>grade_3</code>).</p>
				<table class="form-table" style="margin-top:12px;">
					<tr>
						<th><label for="new-level-slug">Level ID</label></th>
						<td><input type="text" id="new-level-slug" class="regular-text" placeholder="std_4"></td>
					</tr>
					<tr>
						<th>Type</th>
						<td>
							<label style="display:block;margin-bottom:6px;">
								<input type="radio" name="new-level-type" value="standard" checked>
								Has periods (terms / semesters)
							</label>
							<label>
								<input type="radio" name="new-level-type" value="capstone">
								Capstone — no periods (cumulative year-round)
							</label>
						</td>
					</tr>
				</table>
				<p class="submit" style="margin-top:8px;">
					<button class="button button-primary" id="btn-confirm-add-level">Continue to Setup →</button>
					<button type="button" class="button knowly-modal-close" style="margin-left:6px;">Cancel</button>
				</p>
			</div>
		</div>

		<script>
		(function($) {
			var AJAX_URL = '<?= esc_js( $ajax_url ) ?>';
			var NONCE    = '<?= esc_js( $nonce ) ?>';

			// ── Load ─────────────────────────────────────────────────────────

			function loadOverview() {
				$('#overview-loading').show();
				$('#overview-grid').html('');
				$.post(AJAX_URL, { action: 'knowly_curriculum_overview', nonce: NONCE }, function(resp) {
					if (resp.success) {
						renderGrid(resp.data);
					} else {
						$('#overview-grid').html('<div class="notice notice-error inline"><p>' + esc(resp.data || 'Failed to load') + '</p></div>');
					}
				}).always(function() { $('#overview-loading').hide(); });
			}

			// ── Render grid ───────────────────────────────────────────────────

			function renderGrid(data) {
				var levels  = data.levels  || [];
				var periods = data.periods || [];

				if (!levels.length) {
					$('#overview-grid').html('<p>No curriculum data found. Add a new level to get started.</p>');
					return;
				}

				var html = '<table class="wp-list-table widefat fixed striped knowly-table">';
				html += '<thead><tr><th style="width:200px;">Level</th>';
				periods.forEach(function(p) { html += '<th>' + esc(p.label) + '</th>'; });
				html += '<th style="width:100px;">Capstone</th>';
				html += '<th style="width:80px;">Actions</th>';
				html += '</tr></thead><tbody>';

				levels.forEach(function(lv) {
					html += '<tr><td><strong>' + esc(lv.label) + '</strong><br><small style="color:#777;">' + esc(lv.slug) + '</small></td>';

					periods.forEach(function(p) {
						if (lv.capstone) {
							html += '<td style="color:#ccc;">—</td>';
						} else {
							var count = lv.period_map[p.slug] || 0;
							if (count > 0) {
								html += '<td><span class="knowly-badge ok">' + count + ' topics</span></td>';
							} else {
								html += '<td><span class="knowly-badge warn">Empty</span></td>';
							}
						}
					});

					// Capstone column
					var cap = lv.capstone_topics || 0;
					if (lv.capstone || cap > 0) {
						html += '<td><span class="knowly-badge ok">' + cap + ' topics</span></td>';
					} else {
						html += '<td><span style="color:#ccc;">—</span></td>';
					}

					html += '<td><a href="?page=knowly-curriculum&level=' + encodeURIComponent(lv.slug) + '" class="button button-small">Edit</a></td>';
					html += '</tr>';
				});

				html += '</tbody></table>';
				$('#overview-grid').html(html);
			}

			// ── Add level ─────────────────────────────────────────────────────

			$('#btn-add-level').on('click', function() { $('#modal-add-level').show(); });

			$(document).on('click', '.knowly-modal-close', function() {
				$(this).closest('.knowly-modal').hide();
			});
			$(document).on('click', '.knowly-modal-backdrop', function() {
				$(this).closest('.knowly-modal').hide();
			});

			$('#btn-confirm-add-level').on('click', function() {
				var slug = $('#new-level-slug').val().trim().toLowerCase().replace(/\s+/g, '_');
				if (!slug) { alert('Please enter a level ID.'); return; }
				var capstone = $('input[name="new-level-type"]:checked').val() === 'capstone';
				var url = '?page=knowly-curriculum&level=' + encodeURIComponent(slug) + (capstone ? '&capstone=1' : '');
				window.location.href = url;
			});

			function esc(str) {
				return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
			}

			// ── Init ─────────────────────────────────────────────────────────
			loadOverview();

		})(jQuery);
		</script>
		<?php
	}

	// ── Detail ────────────────────────────────────────────────────────────────

	private static function render_detail( string $level, string $nonce ): void {
		// Levels that are always capstone (no term periods)
		static $capstone_levels = [ 'std_5', 'standard_5' ];
		$capstone    = ! empty( $_GET['capstone'] ) || in_array( $level, $capstone_levels, true );
		$level_label = self::level_label( $level );
		$base_url    = admin_url( 'admin.php?page=knowly-curriculum' );
		$ajax_url    = admin_url( 'admin-ajax.php' );
		?>
		<div class="wrap knowly-wrap" id="knowly-curriculum-detail">
			<h1>
				<a href="<?= esc_url( $base_url ) ?>" style="text-decoration:none;color:inherit;">Curriculum</a>
				<span style="color:#999;font-weight:300;margin:0 8px;">›</span>
				<?= esc_html( $level_label ) ?>
			</h1>

			<!-- Subject tabs row -->
			<div style="margin-top:20px;display:flex;align-items:flex-end;gap:0;border-bottom:2px solid #2271b1;">
				<div id="subject-tabs" style="display:flex;gap:0;"></div>
				<button id="btn-add-subject" title="Add subject" style="padding:7px 14px;border:1px solid #c3c4c7;border-bottom:none;background:#f0f0f1;cursor:pointer;font-size:16px;margin-left:4px;border-radius:3px 3px 0 0;">+</button>
			</div>

			<!-- Period tabs (hidden for capstone levels) -->
			<div id="period-tabs" style="margin:16px 0 0;display:none;"></div>

			<!-- Content panel -->
			<div id="detail-content" style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:24px;min-height:200px;">
				<span id="detail-loading" style="color:#777;">Loading…</span>
			</div>
		</div>

		<!-- Edit Module Modal -->
		<div id="modal-edit-module" class="knowly-modal" style="display:none;">
			<div class="knowly-modal-backdrop"></div>
			<div class="knowly-modal-box" style="max-width:900px;max-height:85vh;overflow-y:auto;">
				<button class="knowly-modal-close">&times;</button>
				<h2 id="modal-edit-module-title" style="margin-top:0;">Edit Module</h2>
				<p class="description" style="margin-bottom:16px;">Edit the training content for each topic. Click <strong>Save</strong> on a row to re-embed and sync to Pinecone immediately.</p>
				<div id="modal-edit-module-body"><p style="color:#777;padding:20px 0;">Loading…</p></div>
			</div>
		</div>

		<!-- Add Subject Modal -->
		<div id="modal-add-subject" class="knowly-modal" style="display:none;">
			<div class="knowly-modal-backdrop"></div>
			<div class="knowly-modal-box" style="max-width:400px;">
				<button class="knowly-modal-close">&times;</button>
				<h2>Add Subject</h2>
				<p class="description">Enter the subject identifier (e.g. <code>math</code>, <code>english</code>, <code>science</code>).</p>
				<table class="form-table">
					<tr>
						<th><label for="new-subject-slug">Subject ID</label></th>
						<td><input type="text" id="new-subject-slug" class="regular-text" placeholder="math"></td>
					</tr>
				</table>
				<p class="submit">
					<button class="button button-primary" id="btn-confirm-add-subject">Add Subject</button>
					<button type="button" class="button knowly-modal-close" style="margin-left:6px;">Cancel</button>
				</p>
			</div>
		</div>

		<script>
		(function($) {
			var AJAX_URL    = '<?= esc_js( $ajax_url ) ?>';
			var NONCE       = '<?= esc_js( $nonce ) ?>';
			var LEVEL       = '<?= esc_js( $level ) ?>';
			var IS_CAPSTONE = <?= $capstone ? 'true' : 'false' ?>;

			var currentSubject = null;
			var currentPeriod  = 'term_1';
			var detailData     = null;

			// ── Load detail ───────────────────────────────────────────────────

			function loadDetail() {
				$('#detail-loading').show();
				$('#detail-content .detail-body').remove();

				$.post(AJAX_URL, { action: 'knowly_curriculum_detail', nonce: NONCE, level: LEVEL }, function(resp) {
					if (!resp.success) {
						$('#detail-loading').text('Error: ' + (resp.data || 'Failed to load'));
						return;
					}
					detailData = resp.data;

					// Capstone is server-determined (has period=null rows) OR URL flag
					if (detailData.capstone) IS_CAPSTONE = true;

					// Set default subject
					if (!currentSubject && detailData.subjects.length) {
						currentSubject = detailData.subjects[0].slug;
					}

					renderSubjectTabs();

					if (!IS_CAPSTONE) {
						$('#period-tabs').show();
						renderPeriodTabs();
					}

					renderPanel();
					$('#detail-loading').hide();
				});
			}

			// ── Subject tabs ──────────────────────────────────────────────────

			function renderSubjectTabs() {
				var html = '';
				(detailData ? detailData.subjects : []).forEach(function(s) {
					var active = s.slug === currentSubject;
					html += '<div style="display:inline-flex;align-items:stretch;margin-bottom:-1px;">';
					html += '<button class="knowly-tab-btn" data-subject="' + s.slug + '" style="'
						+ 'padding:8px 20px;border:1px solid ' + (active ? '#2271b1' : '#c3c4c7') + ';'
						+ 'border-bottom:' + (active ? '2px solid #fff' : '1px solid #c3c4c7') + ';'
						+ 'border-right:none;'
						+ 'background:' + (active ? '#fff' : '#f6f7f7') + ';'
						+ 'font-weight:' + (active ? '600' : 'normal') + ';'
						+ 'cursor:pointer;border-radius:3px 0 0 0;">'
						+ esc(s.label) + '</button>';
					html += '<button class="knowly-tab-remove-btn" data-subject="' + s.slug + '" title="Remove subject" style="'
						+ 'padding:0 8px;border:1px solid ' + (active ? '#2271b1' : '#c3c4c7') + ';'
						+ 'border-bottom:' + (active ? '2px solid #fff' : '1px solid #c3c4c7') + ';'
						+ 'background:' + (active ? '#fff' : '#f6f7f7') + ';'
						+ 'color:#b32d2e;cursor:pointer;font-size:13px;border-radius:0 3px 0 0;line-height:1;">'
						+ '&times;</button>';
					html += '</div>';
				});
				$('#subject-tabs').html(html);
			}

			// ── Period tabs ───────────────────────────────────────────────────

			function renderPeriodTabs() {
				if (!detailData || IS_CAPSTONE) return;

				var allPeriods = ['term_1','term_2','term_3'];
				var status     = (detailData.status[currentSubject] || {});
				var html       = '<div style="display:flex;gap:8px;flex-wrap:wrap;">';

				allPeriods.forEach(function(p) {
					var count  = status[p] || 0;
					var active = p === currentPeriod;
					var label  = periodLabel(p);

					var badge = count > 0
						? ' <span style="font-size:11px;background:' + (active ? 'rgba(255,255,255,0.25)' : '#d7f5de') + ';color:' + (active ? '#fff' : '#00641b') + ';padding:1px 6px;border-radius:10px;">' + count + ' topics</span>'
						: ' <span style="font-size:11px;color:' + (active ? 'rgba(255,255,255,0.6)' : '#999') + ';">Empty</span>';

					html += '<button class="knowly-period-btn" data-period="' + p + '" style="'
						+ 'padding:6px 16px;border:1px solid ' + (active ? '#2271b1' : '#c3c4c7') + ';'
						+ 'background:' + (active ? '#2271b1' : '#f6f7f7') + ';'
						+ 'color:' + (active ? '#fff' : '#1d2327') + ';'
						+ 'border-radius:3px;cursor:pointer;font-weight:' + (active ? '600' : 'normal') + ';">'
						+ label + badge + '</button>';
				});

				html += '</div>';
				$('#period-tabs').html(html);
			}

			// ── Content panel ─────────────────────────────────────────────────

			function renderPanel() {
				if (!detailData) return;

				if (!currentSubject) {
					var emptyHtml = '<div class="detail-body">'
						+ '<p style="color:#777;margin-bottom:8px;">No curriculum data uploaded yet for this level.</p>'
						+ '<p style="color:#777;margin-bottom:12px;">Click <strong>+</strong> above to add a subject, then upload a CSV to get started.</p>'
						+ '<p style="background:#f0f6fc;border:1px solid #c8daf5;border-radius:4px;padding:10px 14px;font-size:13px;color:#1d2327;">💡 This level will appear in the <a href="?page=knowly-curriculum">Curriculum overview</a> once at least one CSV is uploaded.</p>'
						+ '</div>';
					$('#detail-loading').hide();
					$('#detail-content .detail-body').remove();
					$('#detail-content').append(emptyHtml);
					return;
				}

				var period  = IS_CAPSTONE ? null : currentPeriod;
				var pKey    = IS_CAPSTONE ? '__capstone__' : (period || '__capstone__');
				var count   = ((detailData.status[currentSubject] || {})[pKey]) || 0;
				var modKey  = currentSubject + '_' + (period || 'cap');
				var modules = (detailData.modules[modKey] || []);

				var html = '<div class="detail-body">';

				if (count > 0) {
					// ── Seeded state ──────────────────────────────────────────
					var subLabel = subjectLabel(currentSubject);
					var pLabel   = IS_CAPSTONE ? 'Capstone' : periodLabel(period);

					html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap;">';
					html += '<div style="background:#d7f5de;border:1px solid #7ad19a;border-radius:4px;padding:10px 18px;">';
					html += '<strong style="color:#00641b;">✅ ' + count + ' topics seeded</strong>';
					html += '<span style="color:#555;margin-left:10px;font-size:13px;">' + esc(subLabel) + ' · ' + esc(pLabel) + '</span>';
					html += '</div>';
					html += '<button class="button" id="btn-reimport">Re-import CSV</button>';
					html += '<button class="button" id="btn-download-csv" title="Download current topics as CSV">⬇ Download CSV</button>';
					html += '<button class="button" id="btn-clear-period" style="color:#b32d2e;" title="Archive all topics for this period">✕ Clear</button>';
					html += '</div>';

					if (modules.length) {
						html += '<table class="wp-list-table widefat fixed striped" style="max-width:680px;">';
						html += '<thead><tr><th style="width:50px;">#</th><th>Module</th><th style="width:90px;">Topics</th><th style="width:70px;"></th></tr></thead><tbody>';
						modules.forEach(function(m) {
							html += '<tr>'
								+ '<td>' + m.module_number + '</td>'
								+ '<td>' + esc(m.module_title) + '</td>'
								+ '<td>' + m.topic_count + '</td>'
								+ '<td><button class="button button-small btn-edit-module" data-module="' + m.module_number + '" data-title="' + esc(m.module_title) + '">Edit</button></td>'
								+ '</tr>';
						});
						html += '</tbody></table>';
					html += renderBadgeSvgSection();
					} else {
						var flatTopics = ((detailData.topics || {})[modKey]) || [];
						html += '<p style="color:#856404;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:10px 14px;font-size:13px;margin-bottom:12px;">⚠ Topics imported without a <code>module_number</code> column — module breakdown unavailable. Re-import with a <code>module_number</code> column to see modules.</p>';
						if (flatTopics.length) {
							html += '<ul style="list-style:disc;padding-left:24px;margin-top:4px;">';
							flatTopics.forEach(function(t) { html += '<li style="margin-bottom:4px;">' + esc(t) + '</li>'; });
							html += '</ul>';
						}
					}
				} else {
					// ── Empty state — show import panel ───────────────────────
					html += renderImportPanel();
				}

				html += '</div>';

				$('#detail-loading').hide();
				$('#detail-content .detail-body').remove();
				$('#detail-content').append(html);

				bindImportHandlers();
			}

			// ── Import panel ──────────────────────────────────────────────────

			function renderImportPanel() {
				var period   = IS_CAPSTONE ? 'Capstone' : periodLabel(currentPeriod);
				var subject  = subjectLabel(currentSubject);

				return '<div id="import-panel">'
					+ '<h3 style="margin-top:0;font-size:15px;">Import CSV — ' + esc(subject) + ' · ' + esc(period) + '</h3>'
					+ '<p class="description" style="margin-bottom:16px;">Required columns: <code>module_number</code>, <code>module_title</code>, <code>topic</code>, <code>content</code>.</p>'

					+ '<div style="display:flex;gap:8px;margin-bottom:16px;">'
					+ '  <button class="button button-primary" id="btn-mode-browse">Browse File</button>'
					+ '  <button class="button" id="btn-mode-paste">Paste CSV</button>'
					+ '</div>'

					+ '<div id="import-browse-area">'
					+ '  <input type="file" id="csv-file-input" accept=".csv,text/csv">'
					+ '</div>'

					+ '<div id="import-paste-area" style="display:none;margin-top:8px;">'
					+ '  <textarea id="csv-paste-input" class="large-text" rows="10" placeholder="module_number,module_title,topic,content&#10;1,Number Concepts,Place value up to 1 000 000,&quot;Students will...&quot;"></textarea>'
					+ '</div>'

					+ '<div id="import-error" class="notice notice-error inline" style="display:none;margin:12px 0;"><p></p></div>'
					+ '<p style="margin-top:16px;">'
					+ '  <button class="button button-primary" id="btn-do-import">Import</button>'
					+ '  <span id="import-status" style="margin-left:14px;font-size:13px;"></span>'
					+ '</p>'
					+ '</div>';
			}

			// ── Bind import handlers ──────────────────────────────────────────

			function bindImportHandlers() {
				var pasteMode = false;

				$(document).off('click.import').on('click.import', '#btn-mode-browse', function() {
					pasteMode = false;
					$('#import-browse-area').show();
					$('#import-paste-area').hide();
					$('#btn-mode-browse').addClass('button-primary');
					$('#btn-mode-paste').removeClass('button-primary');
				}).on('click.import', '#btn-mode-paste', function() {
					pasteMode = true;
					$('#import-paste-area').show();
					$('#import-browse-area').hide();
					$('#btn-mode-paste').addClass('button-primary');
					$('#btn-mode-browse').removeClass('button-primary');
				}).on('click.import', '#btn-reimport', function() {
					$('#detail-content .detail-body').remove();
					$('#detail-content').append('<div class="detail-body">' + renderImportPanel() + '</div>');
					bindImportHandlers();
				}).on('click.import', '#btn-download-csv', function() {
					doDownload();
				}).on('click.import', '#btn-clear-period', function() {
					var label = subjectLabel(currentSubject) + ' · ' + (IS_CAPSTONE ? 'Capstone' : periodLabel(currentPeriod));
					if (!confirm('Archive all topics for ' + label + '?\n\nThis will remove them from the curriculum and Pinecone. You can re-import a CSV to restore.')) return;
					doClear();
				}).on('click.import', '#btn-do-import', function() {
					$('#import-error').hide();

					if (pasteMode) {
						var csvText = $('#csv-paste-input').val().trim();
						if (!csvText) { showImportError('Please paste CSV content.'); return; }
						doImport(csvText);
					} else {
						var file = $('#csv-file-input')[0].files[0];
						if (!file) { showImportError('Please select a CSV file.'); return; }
						var reader = new FileReader();
						reader.onload = function(e) { doImport(e.target.result); };
						reader.readAsText(file);
					}
				});
			}

			// ── Execute import ────────────────────────────────────────────────

			function doImport(csvText) {
				$('#btn-do-import').prop('disabled', true).text('Importing…');
				$('#import-status').text('Sending to Railway and Pinecone…').css('color', '#2271b1');

				$.post(AJAX_URL, {
					action:   'knowly_curriculum_import',
					nonce:    NONCE,
					level:    LEVEL,
					period:   IS_CAPSTONE ? '' : (currentPeriod || ''),
					subject:  currentSubject,
					csv_text: csvText,
				}, function(resp) {
					if (resp.success) {
						var d = resp.data;
						var msg = d.topics_created + ' created, ' + d.topics_updated + ' updated';
						if (d.topics_archived) msg += ', ' + d.topics_archived + ' archived';
						msg += ' · ' + d.synced_pinecone + ' synced to Pinecone';
						$('#import-status').text('✅ ' + msg).css('color', '#00a32a');
						$('#btn-do-import').prop('disabled', false).text('Import');
						setTimeout(function() { loadDetail(); }, 1800);
					} else {
						showImportError(resp.data || 'Import failed.');
						$('#btn-do-import').prop('disabled', false).text('Import');
						$('#import-status').text('');
					}
				});
			}

			function showImportError(msg) {
				$('#import-error').find('p').text(msg);
				$('#import-error').show();
			}

			// ── Download CSV ──────────────────────────────────────────────────

			function doDownload() {
				var $btn = $('#btn-download-csv');
				$btn.prop('disabled', true).text('Downloading…');

				$.post(AJAX_URL, {
					action:  'knowly_curriculum_download',
					nonce:   NONCE,
					level:   LEVEL,
					period:  IS_CAPSTONE ? '' : (currentPeriod || ''),
					subject: currentSubject,
				}, function(resp) {
					if (resp.success) {
						var blob = new Blob([resp.data.csv], { type: 'text/csv' });
						var url  = URL.createObjectURL(blob);
						var a    = document.createElement('a');
						a.href     = url;
						a.download = resp.data.filename;
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
						URL.revokeObjectURL(url);
					} else {
						alert('Download failed: ' + (resp.data || 'Unknown error'));
					}
				}).always(function() {
					$btn.prop('disabled', false).text('⬇ Download CSV');
				});
			}

			// ── Clear period ──────────────────────────────────────────────────

			function doClear() {
				var $btn = $('#btn-clear-period');
				$btn.prop('disabled', true).text('Clearing…');

				$.post(AJAX_URL, {
					action:  'knowly_curriculum_clear',
					nonce:   NONCE,
					level:   LEVEL,
					period:  IS_CAPSTONE ? '' : (currentPeriod || ''),
					subject: currentSubject,
				}, function(resp) {
					if (resp.success) {
						alert((resp.data.archived || 0) + ' topics archived.');
						loadDetail();
					} else {
						alert('Clear failed: ' + (resp.data || 'Unknown error'));
						$btn.prop('disabled', false).text('✕ Clear');
					}
				});
			}

			// ── Edit module ───────────────────────────────────────────────────

			$(document).on('click', '.btn-edit-module', function() {
				var moduleNum   = $(this).data('module');
				var moduleTitle = $(this).data('title');
				openModuleEditor(moduleNum, moduleTitle);
			});

			function openModuleEditor(moduleNum, moduleTitle) {
				$('#modal-edit-module-title').text('Module ' + moduleNum + ' — ' + moduleTitle);
				$('#modal-edit-module-body').html('<p style="color:#777;padding:20px 0;">Loading topics…</p>');
				$('#modal-edit-module').show();

				$.post(AJAX_URL, {
					action:        'knowly_curriculum_module_topics',
					nonce:         NONCE,
					level:         LEVEL,
					period:        IS_CAPSTONE ? '' : (currentPeriod || ''),
					subject:       currentSubject,
					module_number: moduleNum,
				}, function(resp) {
					if (!resp.success) {
						$('#modal-edit-module-body').html('<p style="color:#b32d2e;">' + esc(resp.data || 'Failed to load topics') + '</p>');
						return;
					}
					renderModuleEditor(resp.data.topics, moduleTitle);
				});
			}

			function renderModuleEditor(topics, moduleTitle) {
				if (!topics || !topics.length) {
					$('#modal-edit-module-body').html('<p style="color:#777;">No topics found for this module.</p>');
					return;
				}

				var html = '<table class="wp-list-table widefat" style="table-layout:fixed;">'
					+ '<colgroup><col style="width:220px;"><col><col style="width:90px;"></colgroup>'
					+ '<thead><tr><th>Topic</th><th>Training Content</th><th></th></tr></thead><tbody>';

				topics.forEach(function(t, i) {
					html += '<tr id="topic-row-' + i + '" style="vertical-align:top;">'
						+ '<td style="padding-top:12px;font-weight:500;font-size:13px;word-break:break-word;">' + esc(t.topic) + '</td>'
						+ '<td><textarea class="large-text topic-content-input" rows="6"'
						+ ' data-idx="' + i + '"'
						+ ' data-vector-id="' + esc(t.vector_id) + '"'
						+ ' data-topic="' + esc(t.topic) + '"'
						+ ' data-module-title="' + esc(moduleTitle) + '"'
						+ ' style="width:100%;font-size:13px;resize:vertical;">' + esc(t.content) + '</textarea></td>'
						+ '<td style="padding-top:12px;text-align:center;">'
						+ '<button class="button button-small btn-save-topic" data-idx="' + i + '">Save</button>'
						+ '<span class="save-status" style="display:block;font-size:11px;margin-top:6px;min-height:14px;"></span>'
						+ '</td>'
						+ '</tr>';
				});

				html += '</tbody></table>';
				$('#modal-edit-module-body').html(html);
			}

			$(document).on('click', '.btn-save-topic', function() {
				var $btn    = $(this);
				var idx     = $btn.data('idx');
				var $area   = $('.topic-content-input[data-idx="' + idx + '"]');
				var $status = $btn.closest('td').find('.save-status');

				var vectorId    = $area.data('vector-id');
				var topicName   = $area.data('topic');
				var moduleTitle = $area.data('module-title');
				var content     = $area.val().trim();

				if (!content) { $status.text('Content required').css('color', '#b32d2e'); return; }

				$btn.prop('disabled', true).text('Saving…');
				$status.text('').css('color', '');

				$.post(AJAX_URL, {
					action:       'knowly_curriculum_save_topic',
					nonce:        NONCE,
					vector_id:    vectorId,
					content:      content,
					level:        LEVEL,
					period:       IS_CAPSTONE ? '' : (currentPeriod || ''),
					subject:      currentSubject,
					topic:        topicName,
					module_title: moduleTitle,
				}, function(resp) {
					if (resp.success) {
						$status.text('✅ Saved').css('color', '#00a32a');
					} else {
						$status.text('❌ ' + esc(resp.data || 'Error')).css('color', '#b32d2e');
					}
					$btn.prop('disabled', false).text('Save');
				});
			});

			// ── Tab events ────────────────────────────────────────────────────

			$(document).on('click', '.knowly-tab-btn', function() {
				currentSubject = $(this).data('subject');
				renderSubjectTabs();
				if (!IS_CAPSTONE) renderPeriodTabs();
				renderPanel();
			});

			$(document).on('click', '.knowly-period-btn', function() {
				currentPeriod = $(this).data('period');
				renderPeriodTabs();
				renderPanel();
			});

			// ── Remove subject ────────────────────────────────────────────────

			$(document).on('click', '.knowly-tab-remove-btn', function(e) {
				e.stopPropagation();
				var slug  = $(this).data('subject');
				var entry = (detailData.subjects || []).find(function(s){ return s.slug === slug; });
				var label = entry ? entry.label : slug;
				if (!confirm('Remove "' + label + '" from this level?\n\nThis archives ALL curriculum topics for this subject across all periods and removes the Pinecone training data.\n\nThis cannot be undone.')) return;

				$(this).prop('disabled', true);
				var $self = $(this);
				$.post(AJAX_URL, {
					action:  'knowly_curriculum_remove_subject',
					nonce:   NONCE,
					level:   LEVEL,
					subject: slug,
				}, function(resp) {
					$self.prop('disabled', false);
					if (!resp.success) { alert('Error: ' + (resp.data || 'Failed to remove subject.')); return; }
					detailData.subjects = (detailData.subjects || []).filter(function(s){ return s.slug !== slug; });
					currentSubject = detailData.subjects.length ? detailData.subjects[0].slug : null;
					renderSubjectTabs();
					if (!IS_CAPSTONE) renderPeriodTabs();
					renderPanel();
				});
			});

			// ── Add subject ───────────────────────────────────────────────────

			$('#btn-add-subject').on('click', function() { $('#modal-add-subject').show(); });

			$('#btn-confirm-add-subject').on('click', function() {
				var slug = $('#new-subject-slug').val().trim().toLowerCase().replace(/\s+/g, '_');
				if (!slug) { alert('Please enter a subject ID.'); return; }
				if (!detailData) return;

				// Add to subjects list if not already present
				var exists = detailData.subjects.some(function(s) { return s.slug === slug; });
				if (!exists) {
					detailData.subjects.push({ slug: slug, label: subjectLabel(slug) });
				}
				currentSubject = slug;
				$('#modal-add-subject').hide();
				renderSubjectTabs();
				if (!IS_CAPSTONE) renderPeriodTabs();
				renderPanel();
			});

			$(document).on('click', '.knowly-modal-close', function() {
				$(this).closest('.knowly-modal').hide();
			});
			$(document).on('click', '.knowly-modal-backdrop', function() {
				$(this).closest('.knowly-modal').hide();
			});

			// ── Label helpers ─────────────────────────────────────────────────

			function periodLabel(slug) {
				var map = { term_1:'Term 1', term_2:'Term 2', term_3:'Term 3', semester_1:'Semester 1', semester_2:'Semester 2' };
				return map[slug] || slug;
			}
			function subjectLabel(slug) {
				var map = { math:'Mathematics', english:'English', science:'Science', social_studies:'Social Studies' };
				return map[slug] || slug.replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
			}
			function esc(str) {
				return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
			}

			// ── Badge SVG section ─────────────────────────────────────────────

			function renderBadgeSvgSection() {
				return '<div id="badge-svg-section" style="margin-top:28px;padding-top:20px;border-top:1px solid #e0e0e0;">'
					+ '<h3 style="margin:0 0 6px;font-size:14px;">Badge SVG Template — ' + esc(subjectLabel(currentSubject)) + '</h3>'
					+ '<p class="description" style="margin-bottom:12px;">Paste an SVG here for badge artwork. '
					+ 'Use CSS custom properties <code>--badge-primary</code> and <code>--badge-secondary</code> — the React app swaps these per period (Term 1 = blue, Term 2 = green, Term 3 = orange). '
					+ 'Leave empty for the default badge shape.</p>'
					+ '<textarea id="badge-svg-input" class="large-text code" rows="8" style="font-family:monospace;font-size:12px;" placeholder="&lt;svg xmlns=&quot;http://www.w3.org/2000/svg&quot; viewBox=&quot;0 0 100 100&quot;&gt;&lt;circle cx=&quot;50&quot; cy=&quot;50&quot; r=&quot;48&quot; fill=&quot;var(--badge-primary,#2271b1)&quot;/&gt;&lt;/svg&gt;"></textarea>'
					+ '<div id="badge-svg-preview" style="margin-top:12px;min-height:20px;"></div>'
					+ '<p style="margin-top:10px;">'
					+ '<button class="button button-primary" id="btn-save-badge-svg">Save Badge SVG</button>'
					+ '<button class="button" id="btn-clear-badge-svg" style="margin-left:6px;">Clear</button>'
					+ '<span id="badge-svg-status" style="margin-left:12px;font-size:13px;"></span>'
					+ '</p>'
					+ '</div>';
			}

			$(document).on('input', '#badge-svg-input', function() {
				var svg = $(this).val().trim();
				$('#badge-svg-preview').html(
					svg ? '<span style="font-size:11px;color:#777;">Preview:</span><div style="margin-top:4px;max-width:80px;">' + svg + '</div>' : ''
				);
			});

			$(document).on('click', '#btn-save-badge-svg', function() {
				var $btn = $(this).prop('disabled', true).text('Saving…');
				$('#badge-svg-status').text('').css('color', '');
				$.post(AJAX_URL, {
					action:     'knowly_badge_svg_save',
					nonce:      NONCE,
					curriculum: 'tt_primary',
					subject:    currentSubject,
					svg:        $('#badge-svg-input').val().trim(),
				}, function(resp) {
					$btn.prop('disabled', false).text('Save Badge SVG');
					if (resp.success) {
						$('#badge-svg-status').text('✅ Saved').css('color', '#00a32a');
					} else {
						$('#badge-svg-status').text('❌ ' + esc(resp.data || 'Error')).css('color', '#b32d2e');
					}
				});
			});

			$(document).on('click', '#btn-clear-badge-svg', function() {
				if (!confirm('Clear the badge SVG for ' + subjectLabel(currentSubject) + '?')) return;
				$('#badge-svg-input').val('').trigger('input');
				$('#btn-save-badge-svg').trigger('click');
			});

			// Load stored SVG whenever panel (re)renders — delayed so the DOM is ready
			function loadBadgeSvg() {
				if (!currentSubject) return;
				$('#badge-svg-input').val('');
				$('#badge-svg-preview').html('');
				$.post(AJAX_URL, {
					action:     'knowly_badge_svg_get',
					nonce:      NONCE,
					curriculum: 'tt_primary',
					subject:    currentSubject,
				}, function(resp) {
					if (resp.success && resp.data.svg) {
						$('#badge-svg-input').val(resp.data.svg).trigger('input');
					}
				});
			}

			// Wrap renderPanel to also trigger SVG load after each render
			var _origRenderPanel = renderPanel;
			renderPanel = function() {
				_origRenderPanel();
				if (currentSubject) setTimeout(loadBadgeSvg, 50);
			};

			// ── Init ─────────────────────────────────────────────────────────
			loadDetail();

		})(jQuery);
		</script>
		<?php
	}

	// ── AJAX: Overview ────────────────────────────────────────────────────────

	public static function ajax_overview(): void {
		// Accept nonce from both the curriculum panel and other admin panels (Quests, QB)
		$nonce = $_POST['nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'knowly_curriculum_nonce' )
		  && ! wp_verify_nonce( $nonce, 'knowly_admin_nonce' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$resp = self::railway_get( '/curriculum-topics', [
			'curriculum' => 'tt_primary',
			'status'     => 'active',
			'per_page'   => 1000,
		] );

		if ( isset( $resp['error'] ) ) wp_send_json_error( $resp['error'] );

		$items   = $resp['items'] ?? [];
		$periods = [];
		$levels  = [];

		foreach ( $items as $row ) {
			$lv  = $row['level'];
			$per = $row['period']; // null for capstone
			$sub = $row['subject'];

			if ( ! isset( $levels[ $lv ] ) ) {
				$levels[ $lv ] = [
					'slug'            => $lv,
					'label'           => self::level_label( $lv ),
					'capstone'        => false,
					'period_map'      => [],
					'capstone_topics' => 0,
				];
			}

			if ( $per === null ) {
				$levels[ $lv ]['capstone'] = true;
				$levels[ $lv ]['capstone_topics']++;
			} else {
				$periods[ $per ] = [ 'slug' => $per, 'label' => self::period_label( $per ) ];
				$levels[ $lv ]['period_map'][ $per ] = ( $levels[ $lv ]['period_map'][ $per ] ?? 0 ) + 1;
			}
		}

		ksort( $periods );
		ksort( $levels );

		wp_send_json_success( [
			'levels'  => array_values( $levels ),
			'periods' => array_values( $periods ),
		] );
	}

	// ── AJAX: Detail ──────────────────────────────────────────────────────────

	public static function ajax_detail(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$level = sanitize_key( $_POST['level'] ?? '' );
		if ( ! $level ) wp_send_json_error( 'level is required' );

		$resp = self::railway_get( '/curriculum-topics', [
			'curriculum' => 'tt_primary',
			'level'      => $level,
			'status'     => 'active',
			'per_page'   => 1000,
		] );

		if ( isset( $resp['error'] ) ) wp_send_json_error( $resp['error'] );

		$items    = $resp['items'] ?? [];
		$subjects = [];
		$status   = []; // [subject][period_key] => count
		$modules  = []; // [subject_period] => [{module_number, module_title, topic_count}]
		$topics   = []; // [subject_period] => [topic, ...]
		$capstone = false;

		foreach ( $items as $row ) {
			$sub     = $row['subject'];
			$per     = $row['period']; // null for capstone
			$mod_num = $row['module_number'];
			$mod_ttl = $row['module_title'] ?? 'Module ' . $mod_num;
			$p_key   = $per ?? '__capstone__';
			$mk      = $sub . '_' . ( $per ?? 'cap' );

			if ( ! isset( $subjects[ $sub ] ) ) {
				$subjects[ $sub ] = [ 'slug' => $sub, 'label' => self::subject_label( $sub ) ];
			}

			$status[ $sub ][ $p_key ] = ( $status[ $sub ][ $p_key ] ?? 0 ) + 1;

			if ( $per === null ) $capstone = true;

			$topics[ $mk ][] = $row['topic'];

			if ( $mod_num !== null ) {
				if ( ! isset( $modules[ $mk ][ $mod_num ] ) ) {
					$modules[ $mk ][ $mod_num ] = [
						'module_number' => $mod_num,
						'module_title'  => $mod_ttl,
						'topic_count'   => 0,
					];
				}
				$modules[ $mk ][ $mod_num ]['topic_count']++;
			}
		}

		// Flatten and sort modules
		$flat = [];
		foreach ( $modules as $mk => $mods ) {
			ksort( $mods );
			$flat[ $mk ] = array_values( $mods );
		}

		wp_send_json_success( [
			'level'    => $level,
			'capstone' => $capstone,
			'subjects' => array_values( $subjects ),
			'status'   => $status,
			'modules'  => $flat,
			'topics'   => $topics,
		] );
	}

	// ── AJAX: Import ──────────────────────────────────────────────────────────

	public static function ajax_import(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$level    = sanitize_key( $_POST['level']   ?? '' );
		$period   = sanitize_key( $_POST['period']  ?? '' );
		$subject  = sanitize_key( $_POST['subject'] ?? '' );
		$csv_text = wp_unslash( $_POST['csv_text']  ?? '' );

		if ( ! $level || ! $subject || ! $csv_text ) {
			wp_send_json_error( 'level, subject, and csv_text are required' );
		}

		$period_val = $period ?: null;

		// ── Parse CSV ────────────────────────────────────────────────────────

		$parsed = self::parse_csv( $csv_text );
		if ( is_wp_error( $parsed ) ) wp_send_json_error( $parsed->get_error_message() );
		if ( empty( $parsed ) ) wp_send_json_error( 'CSV contains no data rows' );

		$struct_rows  = [];
		$content_rows = [];
		$mod_counters = [];

		foreach ( $parsed as $row ) {
			$mod_num   = isset( $row['module_number'] ) && $row['module_number'] !== '' ? intval( $row['module_number'] ) : null;
			$mod_title = trim( $row['module_title'] ?? '' );
			$topic     = trim( $row['topic']        ?? '' );
			$content   = trim( $row['content']      ?? '' );

			if ( ! $topic || ! $content ) continue;

			if ( $mod_num !== null ) {
				$mod_counters[ $mod_num ] = $mod_counters[ $mod_num ] ?? 0;
				$sort_order = ( $mod_num - 1 ) * 100 + $mod_counters[ $mod_num ];
				$mod_counters[ $mod_num ]++;
			} else {
				$sort_order = count( $struct_rows );
			}

			$struct_rows[] = [
				'module_number' => $mod_num,
				'module_title'  => $mod_title ?: null,
				'topic'         => $topic,
				'sort_order'    => $sort_order,
			];

			$content_rows[] = [
				'curriculum'   => 'tt_primary',
				'level'        => $level,
				'period'       => $period_val,
				'subject'      => $subject,
				'module_title' => $mod_title ?: null,
				'topic'        => $topic,
				'content'      => $content,
			];
		}

		if ( empty( $struct_rows ) ) {
			wp_send_json_error( 'No valid rows found. Each row needs at minimum a "topic" and "content" column.' );
		}

		// ── 1. Upsert curriculum_topics (structure + ct-* Pinecone sync) ──────

		$ct_resp = self::railway_post( '/curriculum-topics/import', [
			'curriculum' => 'tt_primary',
			'level'      => $level,
			'period'     => $period_val,
			'subject'    => $subject,
			'rows'       => $struct_rows,
		] );

		if ( isset( $ct_resp['error'] ) ) {
			wp_send_json_error( 'Curriculum structure import failed: ' . $ct_resp['error'] );
		}

		// ── 2. Sync training content to Pinecone (tm-* vectors) ───────────────

		$tm_resp = self::railway_post( '/training/import', [ 'rows' => $content_rows ] );

		// ── 3. Mirror to local training_material table ────────────────────────

		self::upsert_local_training( $content_rows );

		wp_send_json_success( [
			'topics_created'  => $ct_resp['created']           ?? 0,
			'topics_updated'  => $ct_resp['updated']           ?? 0,
			'topics_archived' => $ct_resp['archived']          ?? 0,
			'synced_pinecone' => $tm_resp['synced']            ?? 0,
			'total_rows'      => count( $struct_rows ),
		] );
	}

	// ── AJAX: Download CSV ────────────────────────────────────────────────────

	public static function ajax_download(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$level   = sanitize_key( $_POST['level']   ?? '' );
		$period  = sanitize_key( $_POST['period']  ?? '' );
		$subject = sanitize_key( $_POST['subject'] ?? '' );

		if ( ! $level || ! $subject ) wp_send_json_error( 'level and subject are required' );

		$params = [
			'curriculum' => 'tt_primary',
			'level'      => $level,
			'subject'    => $subject,
			'status'     => 'active',
			'per_page'   => 500,
		];
		if ( $period ) {
			$params['period'] = $period;
		} else {
			$params['period'] = 'null'; // capstone — no period
		}

		$resp  = self::railway_get( '/curriculum-topics', $params );
		$items = $resp['items'] ?? [];

		if ( empty( $items ) ) {
			wp_send_json_error( 'No active topics found for this scope' );
		}

		// Fetch content from local training_material table
		global $wpdb;
		$table       = $wpdb->prefix . 'knowly_training_material';
		$content_map = [];
		foreach ( $items as $item ) {
			$vid = 'tm-tt_primary-' . $level . '-' . $subject . '-' . self::slugify( $item['topic'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT content_text FROM {$table} WHERE vector_id = %s", $vid ), ARRAY_A );
			$content_map[ $item['topic'] ] = $row ? $row['content_text'] : '';
		}

		// Build CSV in memory
		$output = fopen( 'php://temp', 'r+' );
		fputcsv( $output, [ 'module_number', 'module_title', 'topic', 'content' ] );
		foreach ( $items as $item ) {
			fputcsv( $output, [
				$item['module_number'] ?? '',
				$item['module_title']  ?? '',
				$item['topic'],
				$content_map[ $item['topic'] ] ?? '',
			] );
		}
		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		$period_slug = $period ?: 'capstone';
		$filename    = 'curriculum_' . $level . '_' . $period_slug . '_' . $subject . '.csv';

		wp_send_json_success( [ 'csv' => $csv, 'filename' => $filename ] );
	}

	// ── AJAX: Clear Period ────────────────────────────────────────────────────

	public static function ajax_clear(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$level   = sanitize_key( $_POST['level']   ?? '' );
		$period  = sanitize_key( $_POST['period']  ?? '' );
		$subject = sanitize_key( $_POST['subject'] ?? '' );

		if ( ! $level || ! $subject ) wp_send_json_error( 'level and subject are required' );

		$period_val = $period ?: null;

		// Import with empty rows archives all existing topics in this scope
		$resp = self::railway_post( '/curriculum-topics/import', [
			'curriculum' => 'tt_primary',
			'level'      => $level,
			'period'     => $period_val,
			'subject'    => $subject,
			'rows'       => [],
		] );

		if ( isset( $resp['error'] ) ) {
			wp_send_json_error( 'Clear failed: ' . $resp['error'] );
		}

		wp_send_json_success( [ 'archived' => $resp['archived'] ?? 0 ] );
	}

	// ── AJAX: Remove Subject ─────────────────────────────────────────────────

	public static function ajax_remove_subject(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$level   = sanitize_key( $_POST['level']   ?? '' );
		$subject = sanitize_key( $_POST['subject'] ?? '' );
		if ( ! $level || ! $subject ) wp_send_json_error( 'level and subject are required' );

		$endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
		$server_key = get_option( 'knowly_railway_server_key', '' );
		if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

		// Archive all periods for this subject by omitting period (= all)
		$resp = wp_remote_request(
			$endpoint . '/api/v1/curriculum-topics/by-scope?' . http_build_query( [
				'curriculum' => 'tt_primary',
				'level'      => $level,
				'subject'    => $subject,
			] ),
			[
				'method'  => 'DELETE',
				'timeout' => 20,
				'headers' => [ 'X-AEP-Server-Key' => $server_key ],
			]
		);

		if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

		$code   = wp_remote_retrieve_response_code( $resp );
		$parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 400 ) {
			wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" );
			return;
		}

		wp_send_json_success( [ 'archived' => $parsed['archived'] ?? 0 ] );
	}

	// ── AJAX: Module Topics ───────────────────────────────────────────────────

	public static function ajax_module_topics(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$level      = sanitize_key( $_POST['level']          ?? '' );
		$period     = sanitize_key( $_POST['period']         ?? '' );
		$subject    = sanitize_key( $_POST['subject']        ?? '' );
		$module_num = intval( $_POST['module_number']        ?? 0 );

		if ( ! $level || ! $subject || ! $module_num ) {
			wp_send_json_error( 'level, subject, and module_number are required' );
		}

		$params = [
			'curriculum' => 'tt_primary',
			'level'      => $level,
			'subject'    => $subject,
			'status'     => 'active',
			'per_page'   => 500,
		];
		if ( $period ) $params['period'] = $period;
		else           $params['period'] = 'null';

		$resp  = self::railway_get( '/curriculum-topics', $params );
		$items = $resp['items'] ?? [];

		// Filter to this module number
		$module_items = array_values( array_filter( $items, fn( $r ) => (int) ( $r['module_number'] ?? 0 ) === $module_num ) );

		if ( empty( $module_items ) ) {
			wp_send_json_error( 'No topics found for module ' . $module_num );
		}

		// Sort by sort_order
		usort( $module_items, fn( $a, $b ) => ( $a['sort_order'] ?? 0 ) <=> ( $b['sort_order'] ?? 0 ) );

		// Fetch content from local training_material
		global $wpdb;
		$table   = $wpdb->prefix . 'knowly_training_material';
		$results = [];

		foreach ( $module_items as $item ) {
			$vid = 'tm-tt_primary-' . $level . '-' . $subject . '-' . self::slugify( $item['topic'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row       = $wpdb->get_row( $wpdb->prepare( "SELECT content_text FROM {$table} WHERE vector_id = %s", $vid ), ARRAY_A );
			$results[] = [
				'topic'      => $item['topic'],
				'vector_id'  => $vid,
				'content'    => $row ? $row['content_text'] : '',
				'sort_order' => $item['sort_order'] ?? 0,
			];
		}

		wp_send_json_success( [
			'module_number' => $module_num,
			'topics'        => $results,
		] );
	}

	// ── AJAX: Save Topic ──────────────────────────────────────────────────────

	public static function ajax_save_topic(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$vector_id   = sanitize_text_field( $_POST['vector_id']    ?? '' );
		$content     = wp_unslash( $_POST['content']               ?? '' );
		$level       = sanitize_key( $_POST['level']               ?? '' );
		$period      = sanitize_key( $_POST['period']              ?? '' );
		$subject     = sanitize_key( $_POST['subject']             ?? '' );
		$topic       = sanitize_text_field( $_POST['topic']        ?? '' );
		$mod_title   = sanitize_text_field( $_POST['module_title'] ?? '' );

		if ( ! $vector_id || ! $content || ! $topic ) {
			wp_send_json_error( 'vector_id, content, and topic are required' );
		}

		// Re-embed and upsert to Pinecone via Railway
		$resp = self::railway_post( '/training/upsert', [
			'vector_id'    => $vector_id,
			'content_text' => $content,
			'metadata'     => [
				'curriculum' => 'tt_primary',
				'level'      => $level,
				'period'     => $period ?: null,
				'subject'    => $subject,
				'topic'      => $topic,
				'subtopic'   => $mod_title ?: null,
			],
		] );

		if ( isset( $resp['error'] ) ) {
			wp_send_json_error( 'Pinecone sync failed: ' . $resp['error'] );
		}

		// Update local training_material mirror
		global $wpdb;
		$table = $wpdb->prefix . 'knowly_training_material';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, [
			'content_text' => $content,
			'updated_at'   => current_time( 'mysql' ),
		], [ 'vector_id' => $vector_id ] );

		wp_send_json_success( [ 'vector_id' => $vector_id, 'status' => 'saved' ] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function parse_csv( string $text ): array|\WP_Error {
		$text  = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$lines = array_filter( explode( "\n", $text ), 'strlen' );
		$lines = array_values( $lines );

		if ( empty( $lines ) ) return new \WP_Error( 'empty', 'CSV is empty' );

		$headers = array_map( 'trim', str_getcsv( array_shift( $lines ) ) );

		foreach ( [ 'topic', 'content' ] as $col ) {
			if ( ! in_array( $col, $headers, true ) ) {
				return new \WP_Error( 'missing_col', "CSV must have a '{$col}' column" );
			}
		}

		$rows = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( ! $line ) continue;
			$values = str_getcsv( $line );
			$row    = [];
			foreach ( $headers as $i => $header ) {
				$row[ $header ] = $values[ $i ] ?? '';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	private static function upsert_local_training( array $rows ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'knowly_training_material';
		$now   = current_time( 'mysql' );

		foreach ( $rows as $row ) {
			$vid  = 'tm-' . $row['curriculum'] . '-' . $row['level'] . '-' . $row['subject'] . '-' . self::slugify( $row['topic'] );
			$data = [
				'curriculum'   => $row['curriculum'],
				'level'        => $row['level'],
				'period'       => $row['period'],
				'subject'      => $row['subject'],
				'topic'        => $row['topic'],
				'subtopic'     => $row['module_title'],
				'content_text' => $row['content'],
				'status'       => 'active',
				'updated_at'   => $now,
			];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE vector_id = %s", $vid ) );

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, $data, [ 'vector_id' => $vid ] );
			} else {
				$data['vector_id']  = $vid;
				$data['created_at'] = $now;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert( $table, $data );
			}
		}
	}

	private static function slugify( string $str ): string {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '_', trim( $str ) ) );
	}

	private static function railway_get( string $path, array $params = [] ): array {
		$base = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
		$url  = $base . '/api/v1' . $path;
		$key  = get_option( 'knowly_railway_server_key', '' );
		if ( $params ) $url = add_query_arg( $params, $url );
		$resp = wp_remote_get( $url, [ 'timeout' => 30, 'headers' => [ 'X-AEP-Server-Key' => $key ] ] );
		if ( is_wp_error( $resp ) ) return [ 'error' => $resp->get_error_message() ];
		return json_decode( wp_remote_retrieve_body( $resp ), true ) ?: [ 'error' => 'Empty response' ];
	}

	private static function railway_post( string $path, array $body = [] ): array {
		$base = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
		$url  = $base . '/api/v1' . $path;
		$key  = get_option( 'knowly_railway_server_key', '' );
		$resp = wp_remote_post( $url, [
			'timeout' => 120,
			'headers' => [ 'X-AEP-Server-Key' => $key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $resp ) ) return [ 'error' => $resp->get_error_message() ];
		return json_decode( wp_remote_retrieve_body( $resp ), true ) ?: [ 'error' => 'Empty response' ];
	}

	// ── AJAX: Badge SVG Get ───────────────────────────────────────────────────

	public static function ajax_badge_svg_get(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$curriculum = sanitize_key( $_POST['curriculum'] ?? 'tt_primary' );
		$subject    = sanitize_key( $_POST['subject']    ?? '' );

		wp_send_json_success( [
			'svg' => Knowly_Badge_Service::get_subject_svg( $curriculum, $subject ) ?: '',
		] );
	}

	// ── AJAX: Badge SVG Save ──────────────────────────────────────────────────

	public static function ajax_badge_svg_save(): void {
		check_ajax_referer( 'knowly_curriculum_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$curriculum = sanitize_key( $_POST['curriculum'] ?? 'tt_primary' );
		$subject    = sanitize_key( $_POST['subject']    ?? '' );
		$svg        = wp_unslash( $_POST['svg']          ?? '' );

		if ( ! $subject ) wp_send_json_error( 'subject is required' );

		Knowly_Badge_Service::save_subject_svg( $curriculum, $subject, $svg );
		wp_send_json_success( [ 'saved' => true ] );
	}

	private static function level_label( string $level ): string {
		$map = [
			'std_1' => 'Standard 1', 'std_2' => 'Standard 2',
			'std_3' => 'Standard 3', 'std_4' => 'Standard 4',
			'std_5' => 'Standard 5 (Capstone)',
			'form_1' => 'Form 1', 'form_2' => 'Form 2', 'form_3' => 'Form 3',
			'grade_1' => 'Grade 1', 'grade_2' => 'Grade 2', 'grade_3' => 'Grade 3',
			'grade_4' => 'Grade 4', 'grade_5' => 'Grade 5', 'grade_6' => 'Grade 6',
		];
		return $map[ $level ] ?? ucwords( str_replace( '_', ' ', $level ) );
	}

	private static function period_label( string $period ): string {
		$map = [
			'term_1' => 'Term 1', 'term_2' => 'Term 2', 'term_3' => 'Term 3',
			'semester_1' => 'Semester 1', 'semester_2' => 'Semester 2',
		];
		return $map[ $period ] ?? ucwords( str_replace( '_', ' ', $period ) );
	}

	private static function subject_label( string $subject ): string {
		$map = [
			'math'           => 'Mathematics',
			'english'        => 'English',
			'science'        => 'Science',
			'social_studies' => 'Social Studies',
		];
		return $map[ $subject ] ?? ucwords( str_replace( '_', ' ', $subject ) );
	}
}
