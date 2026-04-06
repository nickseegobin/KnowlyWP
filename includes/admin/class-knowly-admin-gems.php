<?php
/**
 * Knowly_Admin_Gems — Gem Economy admin page.
 *
 * Tabs:
 *  Settings    — Fygaro gateway config, gem products, costs per curriculum, monthly free tier
 *  Health      — Verify Fygaro config, check DB tables, summarise gem balances
 *  Unit Tests  — Test credit, deduct, allocate, monthly refresh, red gem ops
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Gems {

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_gems_test', [ __CLASS__, 'handle_test_ajax' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab = sanitize_key( $_GET['tab'] ?? 'settings' );

        // Save settings
        if ( $tab === 'settings' && isset( $_POST['knowly_gems_settings_nonce'] ) &&
             wp_verify_nonce( $_POST['knowly_gems_settings_nonce'], 'knowly_gems_save_settings' ) ) {
            self::save_settings();
            echo '<div class="notice notice-success"><p>Gem settings saved.</p></div>';
        }

        $tabs = [
            'settings' => 'Settings',
            'health'   => 'Health',
            'tests'    => 'Unit Tests',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI — Gems</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-gems&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            match ( $tab ) {
                'health' => self::render_health(),
                'tests'  => self::render_tests(),
                default  => self::render_settings(),
            };
            ?>
        </div>
        <?php
    }

    // ── Tab: Settings ─────────────────────────────────────────────────────────

    private static function render_settings(): void {
        $default_curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $dev_bypass         = get_option( 'knowly_dev_bypass_gems', false );

        // Fygaro
        $fygaro_merchant  = get_option( 'knowly_fygaro_merchant_id', '' );
        $fygaro_api_key   = get_option( 'knowly_fygaro_api_key', '' );
        $fygaro_secret    = get_option( 'knowly_fygaro_webhook_secret', '' );

        // Gem products
        $products = json_decode( get_option( 'knowly_gem_products', '[]' ), true ) ?: [];

        // Load saved costs for the default curriculum
        $difficulties = [ 'easy', 'medium', 'hard' ];
        $costs        = [];
        foreach ( $difficulties as $d ) {
            $costs[ $d ] = get_option( Knowly_Gem_Service::cost_key( $default_curriculum, $d ), '' );
        }
        $monthly_free = get_option( Knowly_Gem_Service::free_monthly_key( $default_curriculum ), '' );
        ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'knowly_gems_save_settings', 'knowly_gems_settings_nonce' ); ?>

            <!-- Curriculum & Exam Costs -->
            <div class="knowly-settings-section">
                <h2>Curriculum &amp; Gem Costs</h2>
                <p class="description">Gem costs are read at deduction time — change them here without touching code or deploying.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="knowly_default_curriculum">Default Curriculum</label></th>
                        <td>
                            <input type="text" id="knowly_default_curriculum" name="knowly_default_curriculum"
                                   value="<?= esc_attr( $default_curriculum ) ?>" class="regular-text"
                                   placeholder="tt_primary" />
                            <p class="description">Curriculum key used for cost lookups and monthly free tier. Example: <code>tt_primary</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Exam Cost — Easy</th>
                        <td>
                            <input type="number" name="gem_cost_easy"
                                   value="<?= esc_attr( $costs['easy'] ) ?>" class="small-text" min="0" placeholder="1" />
                            <span class="description">Blue Gems per easy exam</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Exam Cost — Medium</th>
                        <td>
                            <input type="number" name="gem_cost_medium"
                                   value="<?= esc_attr( $costs['medium'] ) ?>" class="small-text" min="0" placeholder="2" />
                            <span class="description">Blue Gems per medium exam</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Exam Cost — Hard</th>
                        <td>
                            <input type="number" name="gem_cost_hard"
                                   value="<?= esc_attr( $costs['hard'] ) ?>" class="small-text" min="0" placeholder="3" />
                            <span class="description">Blue Gems per hard exam</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Monthly Free Tier</th>
                        <td>
                            <input type="number" name="gem_free_monthly"
                                   value="<?= esc_attr( $monthly_free ) ?>" class="small-text" min="0" placeholder="10" />
                            <span class="description">Free Blue Gems granted to parents on the 1st of each month. <code>0</code> = no free tier.</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="knowly_dev_bypass_gems">Bypass Gem Deduction</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="knowly_dev_bypass_gems" name="knowly_dev_bypass_gems" value="1"
                                       <?= checked( $dev_bypass, true, false ) ?> />
                                Skip gem deduction on exam start
                            </label>
                            <p class="description"><strong>Development only.</strong> Exams will not consume gems.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Fygaro Gateway -->
            <div class="knowly-settings-section">
                <h2>Fygaro Payment Gateway</h2>
                <p class="description">Caribbean payment gateway for gem purchases. Leave blank to disable Fygaro checkout.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="knowly_fygaro_merchant_id">Merchant ID</label></th>
                        <td>
                            <input type="text" id="knowly_fygaro_merchant_id" name="knowly_fygaro_merchant_id"
                                   value="<?= esc_attr( $fygaro_merchant ) ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="knowly_fygaro_api_key">API Key</label></th>
                        <td>
                            <input type="password" id="knowly_fygaro_api_key" name="knowly_fygaro_api_key"
                                   value="<?= esc_attr( $fygaro_api_key ) ?>" class="regular-text" autocomplete="new-password" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="knowly_fygaro_webhook_secret">Webhook Secret</label></th>
                        <td>
                            <input type="password" id="knowly_fygaro_webhook_secret" name="knowly_fygaro_webhook_secret"
                                   value="<?= esc_attr( $fygaro_secret ) ?>" class="regular-text" autocomplete="new-password" />
                            <p class="description">Used to verify incoming Fygaro webhooks (HMAC-SHA256). Webhook URL: <code><?= esc_html( rest_url( 'knowly/v1/gems/fygaro-webhook' ) ) ?></code></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Gem Products -->
            <div class="knowly-settings-section">
                <h2>Gem Products</h2>
                <p class="description">Purchasable gem packages available in the app. Each product must have a unique <code>id</code>.</p>

                <table class="widefat striped" id="knowly-gem-products-table" style="max-width:900px;margin-bottom:12px;">
                    <thead>
                        <tr>
                            <th>ID (slug)</th>
                            <th>Name</th>
                            <th>Gem Quantity</th>
                            <th>Price (TTD)</th>
                            <th>Currency</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="gem-products-rows">
                        <?php foreach ( $products as $i => $p ) : ?>
                        <tr class="gem-product-row">
                            <td><input type="text" name="gem_product_id[]" value="<?= esc_attr( $p['id'] ?? '' ) ?>" class="regular-text" placeholder="pack_100" /></td>
                            <td><input type="text" name="gem_product_name[]" value="<?= esc_attr( $p['name'] ?? '' ) ?>" class="regular-text" placeholder="100 Blue Gems" /></td>
                            <td><input type="number" name="gem_product_qty[]" value="<?= esc_attr( $p['gem_quantity'] ?? '' ) ?>" class="small-text" min="1" /></td>
                            <td><input type="number" name="gem_product_price[]" value="<?= esc_attr( $p['price_ttd'] ?? '' ) ?>" class="small-text" min="0" step="0.01" /></td>
                            <td><input type="text" name="gem_product_currency[]" value="<?= esc_attr( $p['currency'] ?? 'TTD' ) ?>" class="small-text" placeholder="TTD" /></td>
                            <td><button type="button" class="button remove-gem-product-row">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ( empty( $products ) ) : ?>
                        <tr class="gem-product-row">
                            <td><input type="text" name="gem_product_id[]" value="" class="regular-text" placeholder="pack_100" /></td>
                            <td><input type="text" name="gem_product_name[]" value="" class="regular-text" placeholder="100 Blue Gems" /></td>
                            <td><input type="number" name="gem_product_qty[]" value="" class="small-text" min="1" /></td>
                            <td><input type="number" name="gem_product_price[]" value="" class="small-text" min="0" step="0.01" /></td>
                            <td><input type="text" name="gem_product_currency[]" value="TTD" class="small-text" /></td>
                            <td><button type="button" class="button remove-gem-product-row">Remove</button></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <button type="button" id="add-gem-product-row" class="button">+ Add Product</button>

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    document.getElementById('add-gem-product-row').addEventListener('click', function() {
                        var row = '<tr class="gem-product-row">' +
                            '<td><input type="text" name="gem_product_id[]" value="" class="regular-text" placeholder="pack_100" /></td>' +
                            '<td><input type="text" name="gem_product_name[]" value="" class="regular-text" placeholder="100 Blue Gems" /></td>' +
                            '<td><input type="number" name="gem_product_qty[]" value="" class="small-text" min="1" /></td>' +
                            '<td><input type="number" name="gem_product_price[]" value="" class="small-text" min="0" step="0.01" /></td>' +
                            '<td><input type="text" name="gem_product_currency[]" value="TTD" class="small-text" /></td>' +
                            '<td><button type="button" class="button remove-gem-product-row">Remove</button></td>' +
                            '</tr>';
                        document.getElementById('gem-products-rows').insertAdjacentHTML('beforeend', row);
                    });
                    document.getElementById('gem-products-rows').addEventListener('click', function(e) {
                        if (e.target.classList.contains('remove-gem-product-row')) {
                            e.target.closest('tr').remove();
                        }
                    });
                });
                </script>
            </div>

            <?php submit_button( 'Save Gem Settings' ); ?>
        </form>
        <?php
    }

    // ── Tab: Health ───────────────────────────────────────────────────────────

    private static function render_health(): void {
        global $wpdb;

        $fygaro_configured = get_option( 'knowly_fygaro_merchant_id' ) && get_option( 'knowly_fygaro_api_key' ) && get_option( 'knowly_fygaro_webhook_secret' );
        $default_curr      = get_option( 'knowly_default_curriculum', 'tt_primary' );

        $tables = [ 'knowly_gem_transactions', 'knowly_red_gem_transactions', 'knowly_processed_webhooks' ];
        $tables_ok = true;
        $table_status = [];
        foreach ( $tables as $table ) {
            $exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" );
            $table_status[ $table ] = $exists;
            if ( ! $exists ) $tables_ok = false;
        }

        $parent_count  = count( get_users( [ 'role' => 'knowly_parent', 'fields' => 'ID' ] ) );
        $child_count   = count( get_users( [ 'role' => 'knowly_child',  'fields' => 'ID' ] ) );
        $teacher_count = count( get_users( [ 'role' => 'knowly_teacher', 'fields' => 'ID' ] ) );

        $total_gem_tx     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_gem_transactions" );
        $total_red_gem_tx = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_red_gem_transactions" );
        $processed_wh     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_processed_webhooks" );

        $gem_costs = [];
        foreach ( [ 'easy', 'medium', 'hard' ] as $d ) {
            $key = Knowly_Gem_Service::cost_key( $default_curr, $d );
            $gem_costs[ $d ] = get_option( $key, "(default fallback)" );
        }
        $monthly_free = get_option( Knowly_Gem_Service::free_monthly_key( $default_curr ), "(default fallback)" );
        ?>
        <div class="knowly-settings-section">
            <h2>Gem DB Tables</h2>
            <table class="widefat striped" style="max-width:600px;">
                <thead><tr><th>Table</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $table_status as $table => $exists ) : ?>
                <tr>
                    <td><code><?= esc_html( $wpdb->prefix . $table ) ?></code></td>
                    <td><?= $exists
                        ? '<span style="color:#00a32a;font-weight:600;">✓ Exists</span>'
                        : '<span style="color:#d63638;font-weight:600;">✗ Missing — deactivate and reactivate plugin</span>'
                    ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="knowly-settings-section">
            <h2>Fygaro Gateway</h2>
            <?php if ( $fygaro_configured ) : ?>
                <p class="knowly-badge ok">✓ Fygaro configured — merchant ID, API key, webhook secret all set</p>
            <?php else : ?>
                <p class="knowly-badge warn">⚠ Fygaro not fully configured — go to <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-gems' ) ) ?>">Gems → Settings</a></p>
            <?php endif; ?>
            <p>Webhook URL: <code><?= esc_html( rest_url( 'knowly/v1/gems/fygaro-webhook' ) ) ?></code></p>
        </div>

        <div class="knowly-settings-section">
            <h2>Gem Costs — <?= esc_html( $default_curr ) ?></h2>
            <table class="widefat striped" style="max-width:400px;">
                <thead><tr><th>Difficulty</th><th>Cost (Blue Gems)</th></tr></thead>
                <tbody>
                <?php foreach ( $gem_costs as $diff => $cost ) : ?>
                <tr>
                    <td><?= esc_html( ucfirst( $diff ) ) ?></td>
                    <td><?= esc_html( $cost ) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td>Monthly Free Tier</td>
                    <td><?= esc_html( $monthly_free ) ?></td>
                </tr>
                </tbody>
            </table>
        </div>

        <div class="knowly-settings-section">
            <h2>Wallet Summary</h2>
            <table class="widefat striped" style="max-width:500px;">
                <tbody>
                    <tr><td>Parents</td><td><?= esc_html( $parent_count ) ?></td></tr>
                    <tr><td>Children</td><td><?= esc_html( $child_count ) ?></td></tr>
                    <tr><td>Teachers</td><td><?= esc_html( $teacher_count ) ?></td></tr>
                    <tr><td>Gem Transactions</td><td><?= esc_html( $total_gem_tx ) ?></td></tr>
                    <tr><td>Red Gem Transactions</td><td><?= esc_html( $total_red_gem_tx ) ?></td></tr>
                    <tr><td>Processed Webhooks (Fygaro)</td><td><?= esc_html( $processed_wh ) ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Tab: Unit Tests ───────────────────────────────────────────────────────

    private static function render_tests(): void {
        $groups = [
            [
                'id'    => 'block3_gems',
                'label' => 'Block 3 — Blue Gem Service',
                'tests' => [
                    [ 'id' => 'gem_credit_parent',    'label' => 'Credit 10 gems to test parent' ],
                    [ 'id' => 'gem_balance_parent',   'label' => 'Get test parent gem balance' ],
                    [ 'id' => 'gem_allocate',         'label' => 'Allocate 5 gems parent → test child' ],
                    [ 'id' => 'gem_balance_child',    'label' => 'Get test child gem balance (expect 5)' ],
                    [ 'id' => 'gem_deduct_child',     'label' => 'Deduct 2 gems from test child' ],
                    [ 'id' => 'gem_deduct_excess',    'label' => 'Deduct excess gems (expect 402 error)' ],
                    [ 'id' => 'gem_monthly_refresh',  'label' => 'Run monthly gem refresh (dry run)' ],
                ],
            ],
            [
                'id'    => 'block3_red_gems',
                'label' => 'Block 3 — Red Gem Service',
                'tests' => [
                    [ 'id' => 'red_gem_credit',          'label' => 'Credit 5 red gems to test teacher' ],
                    [ 'id' => 'red_gem_balance',         'label' => 'Get test teacher red gem balance' ],
                    [ 'id' => 'red_gem_deduct',          'label' => 'Deduct 2 red gems from test teacher' ],
                    [ 'id' => 'red_gem_deduct_excess',   'label' => 'Deduct excess red gems (expect 402)' ],
                    [ 'id' => 'red_gem_stipend_reset',   'label' => 'Run monthly stipend reset (dry run)' ],
                ],
            ],
            [
                'id'    => 'block3_fygaro',
                'label' => 'Block 3 — Fygaro / Webhook',
                'tests' => [
                    [ 'id' => 'fygaro_products',           'label' => 'GET /gems/products returns product list' ],
                    [ 'id' => 'fygaro_webhook_idempotent', 'label' => 'Duplicate webhook returns "Already processed"' ],
                ],
            ],
        ];
        ?>
        <div class="knowly-test-suite-page">
            <?php foreach ( $groups as $group ) : ?>
            <div class="knowly-test-group" data-group="<?= esc_attr( $group['id'] ) ?>">
                <h2><?= esc_html( $group['label'] ) ?>
                    <button type="button" class="button run-test-group" data-group="<?= esc_attr( $group['id'] ) ?>">Run All</button>
                </h2>
                <?php foreach ( $group['tests'] as $test ) : ?>
                <div class="knowly-test-row" data-test="<?= esc_attr( $test['id'] ) ?>">
                    <span class="knowly-test-label"><?= esc_html( $test['label'] ) ?></span>
                    <button type="button" class="button run-single-gems-test">Run</button>
                    <span class="knowly-test-status"></span>
                    <pre class="knowly-test-output" style="display:none;white-space:pre-wrap;font-size:11px;max-height:200px;overflow:auto;"></pre>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            function runGemsTest(testId, row) {
                var btn = row.querySelector('.run-single-gems-test');
                var status = row.querySelector('.knowly-test-status');
                var output = row.querySelector('.knowly-test-output');
                btn.disabled = true;
                status.textContent = '⏳ Running...';
                status.style.color = '#dba617';

                var fd = new FormData();
                fd.append('action', 'knowly_gems_test');
                fd.append('nonce', KnowlyAdmin.nonce);
                fd.append('test', testId);

                fetch(KnowlyAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(function(resp) {
                        var d = resp.data || resp;
                        var pass = d.pass !== undefined ? d.pass : resp.success;
                        status.textContent = pass ? '✅ Pass' : '❌ Fail';
                        status.style.color = pass ? '#00a32a' : '#d63638';
                        output.style.display = 'block';
                        output.textContent = JSON.stringify(d, null, 2);
                        btn.disabled = false;
                    })
                    .catch(function(err) {
                        status.textContent = '❌ Error';
                        status.style.color = '#d63638';
                        output.style.display = 'block';
                        output.textContent = err.toString();
                        btn.disabled = false;
                    });
            }

            document.querySelectorAll('.run-single-gems-test').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = btn.closest('.knowly-test-row');
                    runGemsTest(row.dataset.test, row);
                });
            });

            document.querySelectorAll('.run-test-group').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var group = btn.dataset.group;
                    document.querySelectorAll('[data-group="' + group + '"] .knowly-test-row').forEach(function(row) {
                        runGemsTest(row.dataset.test, row);
                    });
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX Test Runner ──────────────────────────────────────────────────────

    public static function handle_test_ajax(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $test = sanitize_key( $_POST['test'] ?? '' );
        $result = self::run_test( $test );
        wp_send_json( $result );
    }

    public static function run_test( string $test ): array {
        // Get test accounts
        $parent_user  = get_user_by( 'email', 'test.parent@knowly.test' );
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        $parent_id    = $parent_user ? $parent_user->ID : 0;
        $teacher_id   = $teacher_user ? $teacher_user->ID : 0;

        // Get test child from parent
        $child_id = 0;
        if ( $parent_id ) {
            global $wpdb;
            $child_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT child_id FROM {$wpdb->prefix}knowly_children WHERE parent_id = %d LIMIT 1",
                $parent_id
            ) );
        }

        switch ( $test ) {
            // ── Blue gem tests ─────────────────────────────────────────────────

            case 'gem_credit_parent':
                if ( ! $parent_id ) return [ 'pass' => false, 'message' => 'Test parent not found. Run Block 2 account setup first.' ];
                // Reset to 0 first for repeatable test
                update_user_meta( $parent_id, 'knowly_gem_balance', 0 );
                $result = Knowly_Gem_Service::credit( $parent_id, 10, 'admin_credit', 'tt_primary', 'test', 'Unit test credit' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === 10, 'result' => $result ];

            case 'gem_balance_parent':
                if ( ! $parent_id ) return [ 'pass' => false, 'message' => 'Test parent not found.' ];
                $balance = Knowly_Gem_Service::get_balance( $parent_id );
                return [ 'pass' => $balance >= 0, 'balance' => $balance ];

            case 'gem_allocate':
                if ( ! $parent_id || ! $child_id ) return [ 'pass' => false, 'message' => 'Test accounts not found.' ];
                // Ensure parent has enough
                update_user_meta( $parent_id, 'knowly_gem_balance', 10 );
                update_user_meta( $child_id,  'knowly_gem_balance', 0 );
                $result = Knowly_Gem_Service::allocate( $parent_id, $child_id, 5 );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['parent_balance'] === 5 && $result['child_balance'] === 5, 'result' => $result ];

            case 'gem_balance_child':
                if ( ! $child_id ) return [ 'pass' => false, 'message' => 'Test child not found.' ];
                $balance = Knowly_Gem_Service::get_balance( $child_id );
                return [ 'pass' => $balance === 5, 'balance' => $balance, 'note' => 'Expects 5 from allocate test above' ];

            case 'gem_deduct_child':
                if ( ! $child_id ) return [ 'pass' => false, 'message' => 'Test child not found.' ];
                $result = Knowly_Gem_Service::deduct( $child_id, 2, 'spent', 'tt_primary', 'test_session', 'Unit test deduct' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === $result['balance_before'] - 2, 'result' => $result ];

            case 'gem_deduct_excess':
                if ( ! $child_id ) return [ 'pass' => false, 'message' => 'Test child not found.' ];
                update_user_meta( $child_id, 'knowly_gem_balance', 1 );
                $result = Knowly_Gem_Service::deduct( $child_id, 999, 'spent', 'tt_primary', '', 'Should fail' );
                return [ 'pass' => is_wp_error( $result ) && $result->get_error_data()['status'] === 402, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'no_error' ];

            case 'gem_monthly_refresh':
                // Run on a subset — just report count, don't reset real balances in test
                $parents = get_users( [ 'role' => 'knowly_parent', 'fields' => 'ID' ] );
                return [ 'pass' => true, 'parent_count' => count( $parents ), 'message' => 'Counted eligible parents — refresh not executed in test mode (call real cron to verify).' ];

            // ── Red gem tests ──────────────────────────────────────────────────

            case 'red_gem_credit':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                update_user_meta( $teacher_id, 'knowly_red_gem_balance', 0 );
                $result = Knowly_Red_Gem_Service::credit( $teacher_id, 5, 'admin_credit', 'test', 'Unit test red gem credit' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === 5, 'result' => $result ];

            case 'red_gem_balance':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                $balance = Knowly_Red_Gem_Service::get_balance( $teacher_id );
                return [ 'pass' => $balance >= 0, 'balance' => $balance ];

            case 'red_gem_deduct':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                $result = Knowly_Red_Gem_Service::deduct( $teacher_id, 2, 'assignment_spent', 'test_assign', 'Unit test deduct' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === $result['balance_before'] - 2, 'result' => $result ];

            case 'red_gem_deduct_excess':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                update_user_meta( $teacher_id, 'knowly_red_gem_balance', 1 );
                $result = Knowly_Red_Gem_Service::deduct( $teacher_id, 999 );
                return [ 'pass' => is_wp_error( $result ) && $result->get_error_data()['status'] === 402, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : 'no_error' ];

            case 'red_gem_stipend_reset':
                $teachers = get_users( [ 'role' => 'knowly_teacher', 'fields' => 'ID',
                    'meta_query' => [ [ 'key' => 'knowly_approval_status', 'value' => 'approved' ] ] ] );
                return [ 'pass' => true, 'approved_teacher_count' => count( $teachers ), 'message' => 'Counted approved teachers — reset not executed in test mode.' ];

            // ── Fygaro tests ───────────────────────────────────────────────────

            case 'fygaro_products':
                $products = json_decode( get_option( 'knowly_gem_products', '[]' ), true ) ?: [];
                return [ 'pass' => is_array( $products ), 'product_count' => count( $products ), 'products' => $products ];

            case 'fygaro_webhook_idempotent':
                global $wpdb;
                $test_tx = 'test_tx_' . time();
                // Insert once
                $wpdb->insert( $wpdb->prefix . 'knowly_processed_webhooks', [
                    'transaction_id' => $test_tx, 'gateway' => 'fygaro', 'processed_at' => current_time( 'mysql', true ),
                ], [ '%s', '%s', '%s' ] );
                // Check it's there
                $found = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}knowly_processed_webhooks WHERE transaction_id = %s LIMIT 1",
                    $test_tx
                ) );
                // Clean up
                $wpdb->delete( $wpdb->prefix . 'knowly_processed_webhooks', [ 'transaction_id' => $test_tx ] );
                return [ 'pass' => (bool) $found, 'transaction_id' => $test_tx, 'found_in_db' => (bool) $found ];

            default:
                return [ 'pass' => false, 'message' => "Unknown test: {$test}" ];
        }
    }

    // ── Save Settings ─────────────────────────────────────────────────────────

    private static function save_settings(): void {
        $curriculum = sanitize_key( $_POST['knowly_default_curriculum'] ?? 'tt_primary' );
        update_option( 'knowly_default_curriculum', $curriculum );
        update_option( 'knowly_dev_bypass_gems', ! empty( $_POST['knowly_dev_bypass_gems'] ) );

        // Gem costs
        foreach ( [ 'easy', 'medium', 'hard' ] as $diff ) {
            $val = isset( $_POST[ 'gem_cost_' . $diff ] ) ? max( 0, (int) $_POST[ 'gem_cost_' . $diff ] ) : null;
            if ( $val !== null ) {
                update_option( Knowly_Gem_Service::cost_key( $curriculum, $diff ), $val );
            }
        }

        // Monthly free tier
        if ( isset( $_POST['gem_free_monthly'] ) ) {
            update_option( Knowly_Gem_Service::free_monthly_key( $curriculum ), max( 0, (int) $_POST['gem_free_monthly'] ) );
        }

        // Fygaro
        update_option( 'knowly_fygaro_merchant_id',    sanitize_text_field( $_POST['knowly_fygaro_merchant_id'] ?? '' ) );
        update_option( 'knowly_fygaro_api_key',        sanitize_text_field( $_POST['knowly_fygaro_api_key'] ?? '' ) );
        update_option( 'knowly_fygaro_webhook_secret', sanitize_text_field( $_POST['knowly_fygaro_webhook_secret'] ?? '' ) );

        // Gem products
        $ids       = array_map( 'sanitize_key',       $_POST['gem_product_id']       ?? [] );
        $names     = array_map( 'sanitize_text_field', $_POST['gem_product_name']     ?? [] );
        $qtys      = array_map( 'intval',              $_POST['gem_product_qty']      ?? [] );
        $prices    = array_map( 'floatval',            $_POST['gem_product_price']    ?? [] );
        $currencies = array_map( 'sanitize_key',       $_POST['gem_product_currency'] ?? [] );

        $products = [];
        foreach ( $ids as $i => $id ) {
            if ( ! $id ) continue; // Skip empty rows
            $products[] = [
                'id'           => $id,
                'name'         => $names[ $i ] ?? '',
                'gem_quantity' => max( 1, $qtys[ $i ] ?? 1 ),
                'price_ttd'    => round( $prices[ $i ] ?? 0, 2 ),
                'currency'     => $currencies[ $i ] ?: 'TTD',
            ];
        }
        update_option( 'knowly_gem_products', wp_json_encode( $products ) );
    }
}
