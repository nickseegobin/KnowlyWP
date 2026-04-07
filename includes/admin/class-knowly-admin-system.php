<?php
/**
 * Knowly_Admin_System — System / Launch Prep admin page.
 *
 * Tabs:
 *   Sign-off    — health grid, block checklist, React build gate
 *   Debug Log   — searchable log viewer
 *   Training    — Training material (Pinecone vector manager)
 *   Danger Zone — destructive test/reset actions (wipe test data, reset leaderboard, etc.)
 *
 * Delegates to existing class methods where they already exist.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_System {

    public static function boot(): void {
        // All AJAX handlers already registered by the individual classes.
        // No additional registration needed here.
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab = sanitize_key( $_GET['tab'] ?? 'signoff' );
        $tabs = [
            'signoff'  => 'Sign-off',
            'debug'    => 'Debug Log',
            'training' => 'Training',
            'danger'   => 'Danger Zone',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>System</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-system&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            match ( $tab ) {
                'signoff'  => self::render_signoff_tab(),
                'debug'    => self::render_debug_tab(),
                'training' => self::render_training_tab(),
                'danger'   => self::render_danger_tab(),
                default    => self::render_signoff_tab(),
            };
            ?>
        </div>
        <?php
    }

    // ── Sign-off Tab (delegates to Knowly_Admin_Signoff) ──────────────────────

    private static function render_signoff_tab(): void {
        ob_start();
        Knowly_Admin_Signoff::render();
        $html = ob_get_clean();
        // Strip outer .wrap div — render just the inner content
        $html = preg_replace( '/^.*?<div class="wrap knowly-wrap"[^>]*>(.*)<\/div>\s*$/si', '$1', $html );
        // Remove the h1 (we already have System > Sign-off)
        $html = preg_replace( '/<h1[^>]*>.*?<\/h1>/si', '', $html, 1 );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // ── Debug Log Tab (delegates to Knowly_Admin_Debug) ──────────────────────

    private static function render_debug_tab(): void {
        ob_start();
        Knowly_Admin_Debug::render();
        $html = ob_get_clean();
        $html = preg_replace( '/^.*?<div class="wrap knowly-wrap"[^>]*>(.*)<\/div>\s*$/si', '$1', $html );
        $html = preg_replace( '/<h1[^>]*>.*?<\/h1>/si', '', $html, 1 );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // ── Training Tab (delegates to Knowly_Admin_Training) ────────────────────

    private static function render_training_tab(): void {
        ob_start();
        Knowly_Admin_Training::render();
        $html = ob_get_clean();
        $html = preg_replace( '/^.*?<div class="wrap knowly-wrap"[^>]*>(.*)<\/div>\s*$/si', '$1', $html );
        $html = preg_replace( '/<h1[^>]*>.*?<\/h1>/si', '', $html, 1 );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // ── Danger Zone Tab ───────────────────────────────────────────────────────

    private static function render_danger_tab(): void {
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
        <div class="notice notice-error inline" style="margin-bottom:20px;">
            <p><strong>Warning:</strong> These actions are irreversible. Use only in development / UAT environments.</p>
        </div>

        <table class="widefat" style="max-width:700px;">
            <tbody>
                <tr>
                    <td style="padding:12px;">
                        <strong>Wipe Test Data</strong>
                        <p style="margin:4px 0 0;color:#666;font-size:12px;">Deletes all WP accounts where <code>knowly_is_test_account = true</code>. Skips administrators.</p>
                    </td>
                    <td style="padding:12px;white-space:nowrap;">
                        <button class="button" style="color:#d63638;border-color:#d63638;" onclick="knowlyDanger.wipeTestData()">Wipe Test Data</button>
                        <span id="dz-wipe-status" style="font-size:12px;color:#666;margin-left:8px;"></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px;">
                        <strong>Reset Test Leaderboard</strong>
                        <p style="margin:4px 0 0;color:#666;font-size:12px;">Clears the std_4 / term_1 / math leaderboard on Railway.</p>
                    </td>
                    <td style="padding:12px;white-space:nowrap;">
                        <button class="button" style="color:#d63638;border-color:#d63638;" onclick="knowlyDanger.resetLeaderboard()">Reset Leaderboard</button>
                        <span id="dz-lb-status" style="font-size:12px;color:#666;margin-left:8px;"></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px;">
                        <strong>Force Stipend Reset</strong>
                        <p style="margin:4px 0 0;color:#666;font-size:12px;">Triggers the monthly red gem stipend distribution for all teachers immediately.</p>
                    </td>
                    <td style="padding:12px;white-space:nowrap;">
                        <button class="button" style="color:#d63638;border-color:#d63638;" onclick="knowlyDanger.stipendReset()">Force Stipend Reset</button>
                        <span id="dz-stipend-status" style="font-size:12px;color:#666;margin-left:8px;"></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px;">
                        <strong>Force Monthly Gem Reset</strong>
                        <p style="margin:4px 0 0;color:#666;font-size:12px;">Triggers the monthly blue gem refresh for all parent accounts immediately.</p>
                    </td>
                    <td style="padding:12px;white-space:nowrap;">
                        <button class="button" style="color:#d63638;border-color:#d63638;" onclick="knowlyDanger.gemReset()">Force Gem Reset</button>
                        <span id="dz-gem-status" style="font-size:12px;color:#666;margin-left:8px;"></span>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
        <script>
        const knowlyDanger = {
            wipeTestData() {
                if (!confirm('Delete ALL test accounts? This cannot be undone.')) return;
                jQuery('#dz-wipe-status').text('Running…');
                jQuery.post(ajaxurl, { action: 'knowly_signoff_wipe_test_data', nonce: '<?= esc_js( $nonce ) ?>' }, r => {
                    jQuery('#dz-wipe-status').text(r.success ? ('Done: ' + (r.data?.deleted || 0) + ' deleted.') : (r.data?.message || 'Error'));
                });
            },
            resetLeaderboard() {
                if (!confirm('Reset the std_4/term_1/math leaderboard?')) return;
                jQuery('#dz-lb-status').text('Running…');
                jQuery.post(ajaxurl, { action: 'knowly_signoff_reset_leaderboard', nonce: '<?= esc_js( $nonce ) ?>' }, r => {
                    jQuery('#dz-lb-status').text(r.success ? 'Done.' : (r.data?.message || 'Error'));
                });
            },
            stipendReset() {
                if (!confirm('Force stipend reset now?')) return;
                jQuery('#dz-stipend-status').text('Running…');
                jQuery.post(ajaxurl, { action: 'knowly_signoff_stipend_reset', nonce: '<?= esc_js( $nonce ) ?>' }, r => {
                    jQuery('#dz-stipend-status').text(r.success ? 'Done.' : (r.data?.message || 'Error'));
                });
            },
            gemReset() {
                if (!confirm('Force monthly gem reset now?')) return;
                jQuery('#dz-gem-status').text('Running…');
                jQuery.post(ajaxurl, { action: 'knowly_signoff_gem_reset', nonce: '<?= esc_js( $nonce ) ?>' }, r => {
                    jQuery('#dz-gem-status').text(r.success ? 'Done.' : (r.data?.message || 'Error'));
                });
            },
        };
        </script>
        <?php
    }
}
