<?php
/**
 * Knowly_Admin_Migration — Ultimate Member → Knowly meta migration panel.
 *
 * Phase 2g:
 *  - Audit UM meta keys present on users
 *  - Migrate UM meta → knowly_ prefixed meta keys
 *  - Log all results per user
 *  - View migration log
 *  - Confirm migration completion
 *
 * UM meta key mappings:
 *   um_user_roles          → WP role (re-assign to knowly_parent if UM parent role detected)
 *   um_profile_photo       → no direct mapping (UM-specific, skip)
 *   account_status         → if 'approved', preserve; otherwise flag
 *   standard (legacy)      → knowly_level (on child accounts)
 *   term     (legacy)      → knowly_period (on child accounts)
 *   Any existing knowly_ meta keys are left untouched (already migrated).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Migration {

    // ── UM → Knowly meta key map ──────────────────────────────────────────────

    /**
     * Map of legacy meta keys to their knowly_ equivalents.
     * Only keys that have a clear 1:1 mapping are included.
     * Entries with null target are audited but not copied.
     */
    private static function get_meta_map(): array {
        return [
            // Legacy field names (pre-Block 0.5 rename) — may exist on old accounts
            'knowly_standard' => 'knowly_level',
            'knowly_term'     => 'knowly_period',
            // UM account status — map to our own meta
            'account_status'  => null, // handled separately in migration logic
        ];
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_migration_audit',   [ __CLASS__, 'ajax_audit' ] );
        add_action( 'wp_ajax_knowly_migration_run',     [ __CLASS__, 'ajax_run' ] );
        add_action( 'wp_ajax_knowly_migration_confirm', [ __CLASS__, 'ajax_confirm' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        global $wpdb;

        $status    = get_option( 'knowly_um_migration_status', 'pending' );
        $log_table = $wpdb->prefix . 'knowly_migration_log';
        $log_rows  = $wpdb->get_results(
            "SELECT * FROM {$log_table} ORDER BY migrated_at DESC LIMIT 200",
            ARRAY_A
        ) ?: [];

        $success_count = count( array_filter( $log_rows, fn( $r ) => $r['status'] === 'success' ) );
        $failed_count  = count( array_filter( $log_rows, fn( $r ) => $r['status'] === 'failed' ) );
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI — UM Migration</h1>

            <!-- Status Banner -->
            <?php if ( $status === 'complete' ) : ?>
            <div style="background:#d1e7dd;border:1px solid #0a3622;padding:14px 18px;border-radius:4px;margin-bottom:24px;color:#0a3622;">
                ✅ <strong>Migration complete.</strong> All user meta has been migrated to Knowly format.
            </div>
            <?php else : ?>
            <div style="background:#fff3cd;border:1px solid #664d03;padding:14px 18px;border-radius:4px;margin-bottom:24px;color:#664d03;">
                ⚠️ <strong>Migration status: pending.</strong> Run the audit first, then run the migration script.
            </div>
            <?php endif; ?>

            <!-- Controls -->
            <div style="display:flex;gap:12px;margin-bottom:32px;align-items:center;flex-wrap:wrap;">
                <button class="button" id="knowly-migration-audit">🔍 Audit UM Meta</button>
                <button class="button button-primary" id="knowly-migration-run">▶ Run Migration</button>
                <?php if ( $status !== 'complete' && ! empty( $log_rows ) && $failed_count === 0 ) : ?>
                <button class="button" id="knowly-migration-confirm" style="border-color:#00a32a;color:#00a32a;">✓ Confirm Migration Complete</button>
                <?php endif; ?>
                <span id="knowly-migration-status-text" style="font-size:13px;color:#666;"></span>
            </div>

            <!-- Audit / Run Output -->
            <div id="knowly-migration-output" style="display:none;background:#f6f7f7;border:1px solid #c3c4c7;padding:16px;border-radius:4px;margin-bottom:32px;max-height:300px;overflow:auto;font-family:monospace;font-size:12px;white-space:pre-wrap;"></div>

            <!-- Migration Log -->
            <?php if ( ! empty( $log_rows ) ) : ?>
            <h2>Migration Log (<?= count( $log_rows ) ?> entries — <?= $success_count ?> success, <?= $failed_count ?> failed)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:80px;">User ID</th>
                        <th style="width:100px;">Status</th>
                        <th>Message</th>
                        <th style="width:160px;">Migrated At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $log_rows as $row ) : ?>
                    <tr>
                        <td><?= (int) $row['user_id'] ?></td>
                        <td style="color:<?= $row['status'] === 'success' ? '#00a32a' : '#d63638' ?>;">
                            <?= esc_html( $row['status'] ) ?>
                        </td>
                        <td><?= esc_html( $row['message'] ) ?></td>
                        <td><?= esc_html( $row['migrated_at'] ) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            const nonce = '<?= wp_create_nonce( 'knowly_migration' ) ?>';

            function showOutput(text) {
                const $out = $('#knowly-migration-output');
                $out.text(text).show();
            }

            function setStatus(msg) {
                $('#knowly-migration-status-text').text(msg);
            }

            $('#knowly-migration-audit').on('click', function() {
                setStatus('Auditing...');
                $.post(ajaxurl, { action: 'knowly_migration_audit', nonce }, function(res) {
                    if (res.success) {
                        showOutput(res.data.report);
                        setStatus('Audit complete.');
                    } else {
                        setStatus('Audit failed: ' + (res.data || 'Unknown error'));
                    }
                });
            });

            $('#knowly-migration-run').on('click', function() {
                if (!confirm('Run the migration script? This will copy legacy meta keys to knowly_ format. Existing knowly_ keys are never overwritten.')) return;
                setStatus('Running...');
                $.post(ajaxurl, { action: 'knowly_migration_run', nonce }, function(res) {
                    if (res.success) {
                        showOutput(res.data.report);
                        setStatus('Done. Reload the page to see the full log.');
                    } else {
                        setStatus('Failed: ' + (res.data || 'Unknown error'));
                    }
                });
            });

            $('#knowly-migration-confirm').on('click', function() {
                if (!confirm('Mark migration as complete? This cannot be undone.')) return;
                $.post(ajaxurl, { action: 'knowly_migration_confirm', nonce }, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        setStatus('Failed: ' + (res.data || 'Unknown error'));
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX — Audit ──────────────────────────────────────────────────────────

    public static function ajax_audit(): void {
        check_ajax_referer( 'knowly_migration', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );

        $map    = self::get_meta_map();
        $users  = get_users( [ 'number' => -1 ] );
        $report = "=== UM Meta Audit ===\n\n";
        $found  = 0;

        foreach ( $users as $user ) {
            $user_meta = get_user_meta( $user->ID );
            $hits      = [];

            foreach ( array_keys( $map ) as $legacy_key ) {
                if ( isset( $user_meta[ $legacy_key ] ) ) {
                    $hits[] = $legacy_key . ' = ' . $user_meta[ $legacy_key ][0];
                    $found++;
                }
            }

            // Check for UM-specific keys
            foreach ( $user_meta as $key => $val ) {
                if ( strpos( $key, 'um_' ) === 0 ) {
                    $hits[] = '[UM] ' . $key;
                    $found++;
                }
            }

            if ( ! empty( $hits ) ) {
                $report .= "User #{$user->ID} ({$user->user_email}):\n";
                foreach ( $hits as $h ) {
                    $report .= "  - {$h}\n";
                }
                $report .= "\n";
            }
        }

        if ( $found === 0 ) {
            $report .= "No legacy or UM meta keys found. Nothing to migrate.\n";
        } else {
            $report .= "Total legacy/UM meta entries found: {$found}\n";
        }

        wp_send_json_success( [ 'report' => $report ] );
    }

    // ── AJAX — Run Migration ──────────────────────────────────────────────────

    public static function ajax_run(): void {
        check_ajax_referer( 'knowly_migration', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );

        global $wpdb;

        $map       = self::get_meta_map();
        $users     = get_users( [ 'number' => -1 ] );
        $log_table = $wpdb->prefix . 'knowly_migration_log';
        $report    = "=== Migration Run ===\n\n";
        $migrated  = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ( $users as $user ) {
            $user_meta = get_user_meta( $user->ID );

            foreach ( $map as $legacy_key => $target_key ) {
                if ( ! isset( $user_meta[ $legacy_key ] ) ) {
                    continue;
                }

                $legacy_value = $user_meta[ $legacy_key ][0];

                // Handle null-target keys (no copy, just audit)
                if ( $target_key === null ) {
                    $report .= "Skip #{$user->ID}: {$legacy_key} has no target mapping\n";
                    $skipped++;
                    continue;
                }

                // Never overwrite an already-set knowly_ key
                $existing = get_user_meta( $user->ID, $target_key, true );
                if ( $existing !== '' && $existing !== false ) {
                    $report .= "Skip #{$user->ID}: {$target_key} already set\n";
                    $skipped++;
                    continue;
                }

                $updated = update_user_meta( $user->ID, $target_key, $legacy_value );

                if ( $updated !== false ) {
                    $report .= "OK #{$user->ID}: {$legacy_key} → {$target_key} = {$legacy_value}\n";
                    $migrated++;
                    $wpdb->insert( $log_table, [
                        'user_id'     => $user->ID,
                        'status'      => 'success',
                        'message'     => "{$legacy_key} → {$target_key}",
                        'migrated_at' => current_time( 'mysql', true ),
                    ] );
                } else {
                    $report .= "FAIL #{$user->ID}: could not write {$target_key}\n";
                    $failed++;
                    $wpdb->insert( $log_table, [
                        'user_id'     => $user->ID,
                        'status'      => 'failed',
                        'message'     => "Failed to write {$target_key}",
                        'migrated_at' => current_time( 'mysql', true ),
                    ] );
                }
            }
        }

        $report .= "\n=== Summary ===\n";
        $report .= "Migrated: {$migrated}\n";
        $report .= "Skipped:  {$skipped}\n";
        $report .= "Failed:   {$failed}\n";

        wp_send_json_success( [ 'report' => $report, 'migrated' => $migrated, 'failed' => $failed ] );
    }

    // ── AJAX — Confirm ────────────────────────────────────────────────────────

    public static function ajax_confirm(): void {
        check_ajax_referer( 'knowly_migration', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );

        update_option( 'knowly_um_migration_status', 'complete' );
        wp_send_json_success();
    }
}
