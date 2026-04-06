<?php
/**
 * Knowly_Admin_Settings — Plugin settings page.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Settings {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        // Save
        if ( isset( $_POST['knowly_settings_nonce'] ) && wp_verify_nonce( $_POST['knowly_settings_nonce'], 'knowly_save_settings' ) ) {
            self::save();
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $railway_endpoint   = get_option( 'knowly_railway_endpoint', '' );
        $railway_api_key    = get_option( 'knowly_railway_api_key', '' );
        $railway_server_key = get_option( 'knowly_railway_server_key', '' );
        $allowed_origins    = get_option( 'knowly_allowed_origins', '' );
        $content_source     = get_option( 'knowly_content_source', 'pool_only' );
        $debug_enabled      = get_option( 'knowly_debug_enabled', false );
        $dev_bypass         = get_option( 'knowly_dev_bypass_tokens', false );
        $pool_target        = get_option( 'knowly_pool_default_target', 10 );
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI — Settings</h1>

            <form method="post" action="">
                <?php wp_nonce_field( 'knowly_save_settings', 'knowly_settings_nonce' ); ?>

                <!-- Railway -->
                <div class="knowly-settings-section">
                    <h2>Railway AI Server</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="knowly_railway_endpoint">Endpoint URL</label></th>
                            <td>
                                <input type="url" id="knowly_railway_endpoint" name="knowly_railway_endpoint"
                                       value="<?= esc_attr( $railway_endpoint ) ?>" class="regular-text" placeholder="https://your-app.railway.app" />
                                <p class="description">Base URL of the Railway AI service (no trailing slash).</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_railway_api_key">API Key</label></th>
                            <td>
                                <input type="password" id="knowly_railway_api_key" name="knowly_railway_api_key"
                                       value="<?= esc_attr( $railway_api_key ) ?>" class="regular-text" autocomplete="new-password" />
                                <p class="description">Bearer token sent in Authorization header to Railway.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_railway_server_key">Server Key</label></th>
                            <td>
                                <input type="password" id="knowly_railway_server_key" name="knowly_railway_server_key"
                                       value="<?= esc_attr( $railway_server_key ) ?>" class="regular-text" autocomplete="new-password" />
                                <p class="description">Shared secret used to request packages with answer sheets.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_content_source">Content Source</label></th>
                            <td>
                                <select id="knowly_content_source" name="knowly_content_source">
                                    <option value="pool_only" <?= selected( $content_source, 'pool_only', false ) ?>>Pool Only (no Railway fallback)</option>
                                    <option value="railway"   <?= selected( $content_source, 'railway',   false ) ?>>Railway Only (always generate fresh)</option>
                                    <option value="both"      <?= selected( $content_source, 'both',      false ) ?>>Pool first, Railway fallback</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_pool_default_target">Pool Target Size</label></th>
                            <td>
                                <input type="number" id="knowly_pool_default_target" name="knowly_pool_default_target"
                                       value="<?= esc_attr( $pool_target ) ?>" class="small-text" min="1" max="100" />
                                <p class="description">Target number of packages per exam type in the pool.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- CORS -->
                <div class="knowly-settings-section">
                    <h2>React / Next.js Integration</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="knowly_allowed_origins">Allowed Origins</label></th>
                            <td>
                                <textarea id="knowly_allowed_origins" name="knowly_allowed_origins"
                                          rows="4" class="large-text"><?= esc_textarea( $allowed_origins ) ?></textarea>
                                <p class="description">One origin per line, or comma-separated. Example: <code>https://app.noeyai.com</code></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Debug -->
                <div class="knowly-settings-section">
                    <h2>Debug & Development</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="knowly_debug_enabled">Debug Mode</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="knowly_debug_enabled" name="knowly_debug_enabled" value="1"
                                           <?= checked( $debug_enabled, true, false ) ?> />
                                    Enable debug logging
                                </label>
                                <p class="description">
                                    When enabled, detailed logs are written to the <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-debug' ) ) ?>">Debug Log</a>.
                                    <strong>Disable in production.</strong>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="knowly_dev_bypass_tokens">Bypass Token Deduction</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="knowly_dev_bypass_tokens" name="knowly_dev_bypass_tokens" value="1"
                                           <?= checked( $dev_bypass, true, false ) ?> />
                                    Skip token deduction on exam start
                                </label>
                                <p class="description"><strong>Development only.</strong> Exams will not consume tokens.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <!-- JWT Secret Status -->
            <div class="knowly-settings-section">
                <h2>JWT Configuration</h2>
                <?php if ( defined( 'KNOWLY_JWT_SECRET' ) && KNOWLY_JWT_SECRET ) : ?>
                    <p class="knowly-badge ok">✓ KNOWLY_JWT_SECRET is defined in wp-config.php</p>
                <?php elseif ( defined( 'JWT_AUTH_SECRET_KEY' ) && JWT_AUTH_SECRET_KEY ) : ?>
                    <p class="knowly-badge warn">⚠ Using JWT_AUTH_SECRET_KEY — define KNOWLY_JWT_SECRET in wp-config.php for dedicated key.</p>
                <?php else : ?>
                    <p class="knowly-badge error">✗ No JWT secret defined. Add to wp-config.php:<br><code>define( 'KNOWLY_JWT_SECRET', 'your-strong-random-secret-here' );</code></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function save(): void {
        update_option( 'knowly_railway_endpoint',    esc_url_raw( $_POST['knowly_railway_endpoint'] ?? '' ) );
        update_option( 'knowly_railway_api_key',     sanitize_text_field( $_POST['knowly_railway_api_key'] ?? '' ) );
        update_option( 'knowly_railway_server_key',  sanitize_text_field( $_POST['knowly_railway_server_key'] ?? '' ) );
        update_option( 'knowly_allowed_origins',     sanitize_textarea_field( $_POST['knowly_allowed_origins'] ?? '' ) );
        update_option( 'knowly_content_source',      sanitize_key( $_POST['knowly_content_source'] ?? 'pool_only' ) );
        update_option( 'knowly_pool_default_target', max( 1, (int) ( $_POST['knowly_pool_default_target'] ?? 10 ) ) );
        update_option( 'knowly_debug_enabled',       ! empty( $_POST['knowly_debug_enabled'] ) );
        update_option( 'knowly_dev_bypass_tokens',   ! empty( $_POST['knowly_dev_bypass_tokens'] ) );
    }
}
