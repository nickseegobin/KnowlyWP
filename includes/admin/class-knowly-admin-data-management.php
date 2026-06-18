<?php
/**
 * Knowly_Admin_Data_Management — Danger Zone controls for purging content.
 *
 * Allows admins to selectively wipe curriculum structure, training content,
 * question bank questions, quests, and legacy exam pool packages.
 * Each category requires an explicit typed confirmation before executing.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Data_Management {

	public static function boot(): void {
		add_action( 'wp_ajax_knowly_purge_step', [ __CLASS__, 'ajax_purge_step' ] );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		$nonce    = wp_create_nonce( 'knowly_data_management_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<div class="wrap knowly-wrap">
			<h1>Data Management</h1>
			<p class="description">Use these controls to purge content categories before rebuilding from scratch. Each action is irreversible — student data (sessions, results, accounts) is never touched.</p>

			<div class="knowly-danger-zone">
				<h2>⚠ Danger Zone</h2>

				<table class="form-table knowly-purge-table">
					<thead>
						<tr>
							<th style="width:30px;"></th>
							<th>Category</th>
							<th>What gets wiped</th>
							<th>Safe to keep?</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><input type="checkbox" id="purge-curriculum" value="curriculum"></td>
							<td><label for="purge-curriculum"><strong>Curriculum Structure</strong></label></td>
							<td>Supabase <code>curriculum_topics</code> rows (archived) + Pinecone <code>ct-*</code> vectors</td>
							<td><span class="knowly-badge warn">Re-upload required</span></td>
						</tr>
						<tr>
							<td><input type="checkbox" id="purge-training" value="training"></td>
							<td><label for="purge-training"><strong>Training Content</strong></label></td>
							<td>WP <code>knowly_training_material</code> table + Pinecone <code>tm-*</code> vectors</td>
							<td><span class="knowly-badge warn">Re-upload required</span></td>
						</tr>
						<tr>
							<td><input type="checkbox" id="purge-qb" value="qb"></td>
							<td><label for="purge-qb"><strong>Question Bank</strong></label></td>
							<td>Supabase <code>question_bank</code> rows (retired) — regenerate via QB admin board</td>
							<td><span class="knowly-badge ok">Regeneratable</span></td>
						</tr>
						<tr>
							<td><input type="checkbox" id="purge-quests" value="quests"></td>
							<td><label for="purge-quests"><strong>Quests</strong></label></td>
							<td>WP <code>knowly_quests</code> table — all stored quest content</td>
							<td><span class="knowly-badge ok">Regeneratable</span></td>
						</tr>
						<tr>
							<td><input type="checkbox" id="purge-pool" value="pool"></td>
							<td><label for="purge-pool"><strong>Legacy Exam Pool</strong></label></td>
							<td>WP <code>knowly_trial_packages</code> table — old pre-generated packages</td>
							<td><span class="knowly-badge ok">Safe to discard</span></td>
						</tr>
					</tbody>
				</table>

				<div style="margin:24px 0 16px;padding:16px;background:#fff8f8;border:1px solid #f5c6cb;border-radius:4px;">
					<p style="margin:0 0 10px;font-weight:600;color:#721c24;">Type <code>PURGE</code> to confirm and unlock the button</p>
					<input type="text" id="purge-confirm-input" class="regular-text" placeholder="Type PURGE here" autocomplete="off" style="border-color:#dc3545;">
				</div>

				<button id="btn-execute-purge" class="button" style="background:#dc3545;border-color:#dc3545;color:#fff;opacity:.4;cursor:not-allowed;" disabled>
					Purge Selected Categories
				</button>
				<span id="purge-select-hint" style="margin-left:10px;color:#777;font-size:13px;">Select at least one category and type PURGE to enable.</span>
			</div>

			<!-- Progress log -->
			<div id="purge-progress" style="display:none;margin-top:24px;">
				<h3 style="margin-bottom:8px;">Progress</h3>
				<div id="purge-log" style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:13px;padding:16px;border-radius:4px;min-height:120px;max-height:400px;overflow-y:auto;"></div>
			</div>
		</div>

		<script>
		(function($) {
			var AJAX_URL = '<?= esc_js( $ajax_url ) ?>';
			var NONCE    = '<?= esc_js( $nonce ) ?>';

			// ── Enable/disable purge button ───────────────────────────────────

			function updateButton() {
				var confirmed = $('#purge-confirm-input').val().trim() === 'PURGE';
				var selected  = $('input[type=checkbox]:checked').length > 0;
				var ready     = confirmed && selected;

				$('#btn-execute-purge').prop('disabled', !ready).css({
					opacity:  ready ? 1 : 0.4,
					cursor:   ready ? 'pointer' : 'not-allowed',
				});
				$('#purge-select-hint').text( confirmed && !selected
					? 'Select at least one category.'
					: !confirmed && selected
					? 'Type PURGE to unlock.'
					: !confirmed && !selected
					? 'Select at least one category and type PURGE to enable.'
					: ''
				);
			}

			$('#purge-confirm-input').on('input', updateButton);
			$('input[type=checkbox]').on('change', updateButton);

			// ── Execute purge ─────────────────────────────────────────────────

			$('#btn-execute-purge').on('click', function() {
				var categories = [];
				$('input[type=checkbox]:checked').each(function() {
					categories.push($(this).val());
				});
				if (!categories.length) return;

				$(this).prop('disabled', true).css({ opacity: .4, cursor: 'not-allowed' });
				$('input[type=checkbox]').prop('disabled', true);
				$('#purge-confirm-input').prop('disabled', true);
				$('#purge-progress').show();
				$('#purge-log').html('');

				log('🚀 Starting purge of: ' + categories.join(', '));
				runSteps(categories, 0);
			});

			function runSteps(categories, index) {
				if (index >= categories.length) {
					log('');
					log('✅ Purge complete.');
					return;
				}

				var category = categories[index];
				log('⏳ Purging ' + labelFor(category) + '…');

				$.post(AJAX_URL, {
					action:   'knowly_purge_step',
					nonce:    NONCE,
					category: category,
				}, function(resp) {
					if (resp.success) {
						log('✅ ' + labelFor(category) + ' — ' + resp.data.summary);
					} else {
						log('❌ ' + labelFor(category) + ' failed: ' + (resp.data || 'Unknown error'));
					}
					runSteps(categories, index + 1);
				}).fail(function() {
					log('❌ ' + labelFor(category) + ' — request failed');
					runSteps(categories, index + 1);
				});
			}

			function log(msg) {
				var $log = $('#purge-log');
				$log.append('<div>' + esc(msg) + '</div>');
				$log.scrollTop($log[0].scrollHeight);
			}

			function labelFor(cat) {
				var map = {
					curriculum: 'Curriculum Structure',
					training:   'Training Content',
					qb:         'Question Bank',
					quests:     'Quests',
					pool:       'Legacy Exam Pool',
				};
				return map[cat] || cat;
			}

			function esc(str) {
				return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
			}

		})(jQuery);
		</script>
		<?php
	}

	// ── AJAX: Purge Step ──────────────────────────────────────────────────────

	public static function ajax_purge_step(): void {
		check_ajax_referer( 'knowly_data_management_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

		$category = sanitize_key( $_POST['category'] ?? '' );

		switch ( $category ) {
			case 'curriculum':
				self::purge_curriculum();
				break;
			case 'training':
				self::purge_training();
				break;
			case 'qb':
				self::purge_question_bank();
				break;
			case 'quests':
				self::purge_quests();
				break;
			case 'pool':
				self::purge_legacy_pool();
				break;
			default:
				wp_send_json_error( 'Unknown category: ' . $category );
		}
	}

	// ── Purge handlers ────────────────────────────────────────────────────────

	private static function purge_curriculum(): void {
		$resp = self::railway_delete( '/curriculum-topics/purge' );
		if ( isset( $resp['error'] ) ) {
			wp_send_json_error( $resp['error'] );
		}
		wp_send_json_success( [
			'summary' => sprintf(
				'%d Supabase rows archived, %d Pinecone ct-* vectors deleted',
				$resp['archived_rows']    ?? 0,
				$resp['deleted_vectors']  ?? 0
			),
		] );
	}

	private static function purge_training(): void {
		// 1. Delete tm-* vectors from Pinecone via Railway
		$resp = self::railway_delete( '/training/purge' );
		if ( isset( $resp['error'] ) ) {
			wp_send_json_error( $resp['error'] );
		}
		$pinecone_deleted = $resp['deleted'] ?? 0;

		// 2. Truncate local WP training_material table
		global $wpdb;
		$table = $wpdb->prefix . 'knowly_training_material';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wp_deleted = $wpdb->query( "DELETE FROM {$table}" );

		wp_send_json_success( [
			'summary' => sprintf(
				'%d Pinecone tm-* vectors deleted, %d WP rows removed',
				$pinecone_deleted,
				$wp_deleted !== false ? $wp_deleted : 0
			),
		] );
	}

	private static function purge_question_bank(): void {
		$resp = self::railway_delete( '/question-bank/purge' );
		if ( isset( $resp['error'] ) ) {
			wp_send_json_error( $resp['error'] );
		}
		wp_send_json_success( [
			'summary' => sprintf( '%d questions retired', $resp['retired'] ?? 0 ),
		] );
	}

	private static function purge_quests(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'knowly_quests';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query( "DELETE FROM {$table}" );
		wp_send_json_success( [
			'summary' => sprintf( '%d quest records removed', $deleted !== false ? $deleted : 0 ),
		] );
	}

	private static function purge_legacy_pool(): void {
		global $wpdb;
		$packages_table = $wpdb->prefix . 'knowly_trial_packages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query( "DELETE FROM {$packages_table}" );
		wp_send_json_success( [
			'summary' => sprintf( '%d legacy packages removed', $deleted !== false ? $deleted : 0 ),
		] );
	}

	// ── Railway helper ────────────────────────────────────────────────────────

	private static function railway_delete( string $path ): array {
		$base = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
		$url  = $base . '/api/v1' . $path;
		$key  = get_option( 'knowly_railway_server_key', '' );
		$resp = wp_remote_request( $url, [
			'method'  => 'DELETE',
			'timeout' => 120,
			'headers' => [ 'X-AEP-Server-Key' => $key, 'Content-Type' => 'application/json' ],
		] );
		if ( is_wp_error( $resp ) ) return [ 'error' => $resp->get_error_message() ];
		return json_decode( wp_remote_retrieve_body( $resp ), true ) ?: [ 'error' => 'Empty response' ];
	}
}
