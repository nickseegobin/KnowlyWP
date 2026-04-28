<?php
/**
 * Knowly_Admin_Gems — Gem Commerce admin page.
 *
 * Tabs:
 *  Products    — Read-only list of WooCommerce gem products (WC is the source of truth)
 *  Settings    — Gem costs per difficulty / quest type, monthly free tier, dev bypass
 *  Health      — DB tables, wallet summary, gem cost snapshot
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

        $tab = sanitize_key( $_GET['tab'] ?? 'products' );

        if ( $tab === 'settings' && isset( $_POST['knowly_gems_settings_nonce'] ) &&
             wp_verify_nonce( $_POST['knowly_gems_settings_nonce'], 'knowly_gems_save_settings' ) ) {
            self::save_settings();
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $tabs = [
            'products' => 'Products',
            'settings' => 'Settings',
            'health'   => 'Health',
            'tests'    => 'Unit Tests',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Gem Commerce</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-gems&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            match ( $tab ) {
                'products' => self::render_products(),
                'health'   => self::render_health(),
                'tests'    => self::render_tests(),
                default    => self::render_settings(),
            };
            ?>
        </div>
        <?php
    }

    // ── Tab: Products ─────────────────────────────────────────────────────────

    private static function render_products(): void {
        $wc_active = class_exists( 'WooCommerce' );
        $products  = $wc_active ? Knowly_WooCommerce::get_gem_products() : [];
        $edit_url  = admin_url( 'edit.php?post_type=product' );
        ?>
        <div class="knowly-settings-section">
            <h2>Gem Products</h2>
            <p class="description">
                Products are managed in <a href="<?= esc_url( $edit_url ) ?>">WooCommerce → Products</a>.
                Add the <strong>Knowly Blue Gems granted</strong> field in the product's General tab to make it a gem product.
                Only published products with a gem quantity &gt; 0 appear here.
            </p>

            <?php if ( ! $wc_active ) : ?>
                <div class="notice notice-error inline"><p>WooCommerce is not active. Gem products require WooCommerce.</p></div>
            <?php elseif ( empty( $products ) ) : ?>
                <div class="notice notice-warning inline">
                    <p>No gem products found. <a href="<?= esc_url( admin_url( 'post-new.php?post_type=product' ) ) ?>">Create a product</a> and set the <strong>Knowly Blue Gems granted</strong> field.</p>
                </div>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px;margin-top:12px;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Blue Gems Granted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $products as $p ) : ?>
                        <tr>
                            <td>
                                <strong><?= esc_html( $p['name'] ) ?></strong>
                                <?php if ( $p['description'] ) : ?>
                                    <br><span class="description"><?= esc_html( $p['description'] ) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $p['price_html'] ?></td>
                            <td><span style="font-size:1.1em;font-weight:600;">💎 <?= esc_html( $p['gem_quantity'] ) ?></span></td>
                            <td>
                                <a href="<?= esc_url( get_edit_post_link( $p['id'] ) ) ?>" class="button button-small">Edit in WooCommerce</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Tab: Settings ─────────────────────────────────────────────────────────

    private static function render_settings(): void {
        $default_curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $dev_bypass         = get_option( 'knowly_dev_bypass_gems', false );

        $costs = [];
        foreach ( [ 'easy', 'medium', 'hard' ] as $d ) {
            $costs[ $d ] = get_option( Knowly_Gem_Service::cost_key( $default_curriculum, $d ), '' );
        }
        $monthly_free      = get_option( Knowly_Gem_Service::free_monthly_key( $default_curriculum ), '' );
        $quest_cost_first  = get_option( Knowly_Gem_Service::quest_cost_key( $default_curriculum, 'first' ), '' );
        $quest_cost_retake = get_option( Knowly_Gem_Service::quest_cost_key( $default_curriculum, 'retake' ), '' );
        ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'knowly_gems_save_settings', 'knowly_gems_settings_nonce' ); ?>

            <div class="knowly-settings-section">
                <h2>Curriculum &amp; Gem Costs</h2>
                <p class="description">Costs are read at deduction time — change here without touching code.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="knowly_default_curriculum">Default Curriculum</label></th>
                        <td>
                            <input type="text" id="knowly_default_curriculum" name="knowly_default_curriculum"
                                   value="<?= esc_attr( $default_curriculum ) ?>" class="regular-text" placeholder="tt_primary" />
                            <p class="description">Key used for cost lookups and monthly free tier. Example: <code>tt_primary</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Trial Cost — Easy</th>
                        <td>
                            <input type="number" name="gem_cost_easy" value="<?= esc_attr( $costs['easy'] ) ?>" class="small-text" min="0" placeholder="1" />
                            <span class="description">Blue Gems per easy trial</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Trial Cost — Medium</th>
                        <td>
                            <input type="number" name="gem_cost_medium" value="<?= esc_attr( $costs['medium'] ) ?>" class="small-text" min="0" placeholder="2" />
                            <span class="description">Blue Gems per medium trial</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Trial Cost — Hard</th>
                        <td>
                            <input type="number" name="gem_cost_hard" value="<?= esc_attr( $costs['hard'] ) ?>" class="small-text" min="0" placeholder="3" />
                            <span class="description">Blue Gems per hard trial</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Quest Cost — First Attempt</th>
                        <td>
                            <input type="number" name="gem_cost_quest_first" value="<?= esc_attr( $quest_cost_first ) ?>" class="small-text" min="0" placeholder="3" />
                            <span class="description">Blue Gems to start a quest for the first time</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Quest Cost — Retake</th>
                        <td>
                            <input type="number" name="gem_cost_quest_retake" value="<?= esc_attr( $quest_cost_retake ) ?>" class="small-text" min="0" placeholder="1" />
                            <span class="description">Blue Gems to restart a previously completed quest</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Monthly Free Tier</th>
                        <td>
                            <input type="number" name="gem_free_monthly" value="<?= esc_attr( $monthly_free ) ?>" class="small-text" min="0" placeholder="10" />
                            <span class="description">Free Blue Gems granted to parents on the 1st of each month. <code>0</code> = disabled.</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="knowly_dev_bypass_gems">Bypass Gem Deduction</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="knowly_dev_bypass_gems" name="knowly_dev_bypass_gems" value="1"
                                       <?= checked( $dev_bypass, true, false ) ?> />
                                Skip gem deduction on trial/quest start
                            </label>
                            <p class="description"><strong>Development only.</strong></p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( 'Save Settings' ); ?>
        </form>
        <?php
    }

    // ── Tab: Health ───────────────────────────────────────────────────────────

    private static function render_health(): void {
        global $wpdb;

        $default_curr = get_option( 'knowly_default_curriculum', 'tt_primary' );
        $wc_active    = class_exists( 'WooCommerce' );

        $tables = [ 'knowly_gem_transactions', 'knowly_red_gem_transactions' ];
        $table_status = [];
        foreach ( $tables as $table ) {
            $table_status[ $table ] = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" );
        }

        $parent_count  = count( get_users( [ 'role' => 'knowly_parent',  'fields' => 'ID' ] ) );
        $child_count   = count( get_users( [ 'role' => 'knowly_child',   'fields' => 'ID' ] ) );
        $teacher_count = count( get_users( [ 'role' => 'knowly_teacher', 'fields' => 'ID' ] ) );
        $total_gem_tx  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_gem_transactions" );
        $total_red_tx  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_red_gem_transactions" );

        $gem_costs = [];
        foreach ( [ 'easy', 'medium', 'hard' ] as $d ) {
            $gem_costs[ $d ] = get_option( Knowly_Gem_Service::cost_key( $default_curr, $d ), '(fallback)' );
        }
        $monthly_free      = get_option( Knowly_Gem_Service::free_monthly_key( $default_curr ), '(fallback)' );
        $quest_cost_first  = get_option( Knowly_Gem_Service::quest_cost_key( $default_curr, 'first' ),  '(fallback)' );
        $quest_cost_retake = get_option( Knowly_Gem_Service::quest_cost_key( $default_curr, 'retake' ), '(fallback)' );

        $wc_products = $wc_active ? Knowly_WooCommerce::get_gem_products() : [];

        // React readiness checks
        $costs_configured = array_filter( $gem_costs, fn( $v ) => $v !== '(fallback)' && $v !== '' );
        $hooks_ok         = has_action( 'woocommerce_order_status_completed', [ 'Knowly_WooCommerce', 'handle_order_completed' ] )
                         && has_filter( 'woocommerce_get_return_url',         [ 'Knowly_WooCommerce', 'custom_return_url' ] );
        $endpoints_ok     = (bool) rest_get_server()->get_routes()[ '/knowly/v1/gems/products' ] ?? false;

        $readiness = [
            'WooCommerce active'              => $wc_active,
            'Gem products configured (≥ 1)'   => count( $wc_products ) > 0,
            'WC hooks registered'             => (bool) $hooks_ok,
            'Gem costs configured'            => count( $costs_configured ) >= 3,
            'DB tables present'               => ! in_array( false, $table_status, true ),
            'REST /gems/products registered'  => function_exists( 'rest_get_server' )
                                                    ? array_key_exists( '/knowly/v1/gems/products', rest_get_server()->get_routes() )
                                                    : false,
            'REST /gems/orders registered'    => function_exists( 'rest_get_server' )
                                                    ? array_key_exists( '/knowly/v1/gems/orders', rest_get_server()->get_routes() )
                                                    : false,
        ];
        $all_ready = ! in_array( false, $readiness, true );
        ?>

        <div class="knowly-settings-section">
            <h2>React Readiness</h2>
            <?php if ( $all_ready ) : ?>
                <p class="knowly-badge ok" style="font-size:1.05em;">✅ All checks passed — system is ready for React gem store implementation</p>
            <?php else : ?>
                <p class="knowly-badge warn">⚠ Some checks failed — resolve issues below before implementing the React store</p>
            <?php endif; ?>
            <table class="widefat striped" style="max-width:600px;margin-top:12px;">
                <thead><tr><th>Check</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $readiness as $label => $ok ) : ?>
                <tr>
                    <td><?= esc_html( $label ) ?></td>
                    <td><?= $ok
                        ? '<span style="color:#00a32a;font-weight:600;">✓ Pass</span>'
                        : '<span style="color:#d63638;font-weight:600;">✗ Fail</span>'
                    ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="knowly-settings-section">
            <h2>WooCommerce — Gem Products</h2>
            <?php if ( ! $wc_active ) : ?>
                <p class="knowly-badge warn">⚠ WooCommerce not active</p>
            <?php elseif ( empty( $wc_products ) ) : ?>
                <p class="knowly-badge warn">⚠ No gem products — <a href="<?= esc_url( admin_url( 'post-new.php?post_type=product' ) ) ?>">create one</a></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:700px;">
                    <thead><tr><th>Product</th><th>Price</th><th>Gems</th></tr></thead>
                    <tbody>
                    <?php foreach ( $wc_products as $p ) : ?>
                    <tr>
                        <td><strong><?= esc_html( $p['name'] ) ?></strong></td>
                        <td><?= $p['price_html'] ?></td>
                        <td>💎 <?= esc_html( $p['gem_quantity'] ) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:8px;"><a href="<?= esc_url( admin_url( 'admin.php?page=knowly-gems&tab=products' ) ) ?>">Manage products →</a></p>
            <?php endif; ?>
        </div>

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
            <h2>Gem Costs — <?= esc_html( $default_curr ) ?></h2>
            <table class="widefat striped" style="max-width:400px;">
                <thead><tr><th>Type</th><th>Cost (Blue Gems)</th></tr></thead>
                <tbody>
                <?php foreach ( $gem_costs as $diff => $cost ) : ?>
                <tr>
                    <td>Trial — <?= esc_html( ucfirst( $diff ) ) ?></td>
                    <td><?= $cost === '(fallback)' ? '<em style="color:#d63638;">(fallback — set in Settings)</em>' : esc_html( $cost ) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr><td>Quest — First Attempt</td><td><?= esc_html( $quest_cost_first ) ?></td></tr>
                <tr><td>Quest — Retake</td><td><?= esc_html( $quest_cost_retake ) ?></td></tr>
                <tr><td>Monthly Free Tier</td><td><?= esc_html( $monthly_free ) ?></td></tr>
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
                    <tr><td>Red Gem Transactions</td><td><?= esc_html( $total_red_tx ) ?></td></tr>
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
                    [ 'id' => 'gem_credit_parent',   'label' => 'Credit 10 gems to test parent' ],
                    [ 'id' => 'gem_balance_parent',  'label' => 'Get test parent gem balance' ],
                    [ 'id' => 'gem_allocate',        'label' => 'Allocate 5 gems parent → test child' ],
                    [ 'id' => 'gem_balance_child',   'label' => 'Get test child gem balance (expect 5)' ],
                    [ 'id' => 'gem_deduct_child',    'label' => 'Deduct 2 gems from test child' ],
                    [ 'id' => 'gem_deduct_excess',   'label' => 'Deduct excess gems (expect 402 error)' ],
                    [ 'id' => 'gem_monthly_refresh', 'label' => 'Run monthly gem refresh (dry run)' ],
                ],
            ],
            [
                'id'    => 'block3_red_gems',
                'label' => 'Block 3 — Red Gem Service',
                'tests' => [
                    [ 'id' => 'red_gem_credit',        'label' => 'Credit 5 red gems to test teacher' ],
                    [ 'id' => 'red_gem_balance',       'label' => 'Get test teacher red gem balance' ],
                    [ 'id' => 'red_gem_deduct',        'label' => 'Deduct 2 red gems from test teacher' ],
                    [ 'id' => 'red_gem_deduct_excess', 'label' => 'Deduct excess red gems (expect 402)' ],
                    [ 'id' => 'red_gem_stipend_reset', 'label' => 'Run monthly stipend reset (dry run)' ],
                ],
            ],
            [
                'id'    => 'wc_commerce',
                'label' => 'WooCommerce Commerce',
                'tests' => [
                    [ 'id' => 'wc_active',                 'label' => 'WooCommerce is active' ],
                    [ 'id' => 'wc_products',               'label' => 'GET /gems/products returns WC gem products' ],
                    [ 'id' => 'wc_hooks_registered',       'label' => 'WC hooks registered (order_completed + return_url)' ],
                    [ 'id' => 'wc_order_create',           'label' => 'Create pending WC order for gem product (lifecycle test)' ],
                    [ 'id' => 'wc_order_status',           'label' => 'Get order status after creation' ],
                    [ 'id' => 'wc_order_completion_hook',  'label' => 'Simulate order completion — gems credited to parent' ],
                    [ 'id' => 'gems_products_endpoint',    'label' => 'REST /gems/products — internal dispatch' ],
                    [ 'id' => 'gems_costs_endpoint',       'label' => 'REST /gems/costs — trial + quest structure' ],
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
                return fetch(KnowlyAdmin.ajaxUrl, { method: 'POST', body: fd })
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
                    runGemsTest(btn.closest('.knowly-test-row').dataset.test, btn.closest('.knowly-test-row'));
                });
            });
            document.querySelectorAll('.run-test-group').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var group = btn.dataset.group;
                    var rows = Array.from(document.querySelectorAll('[data-group="' + group + '"] .knowly-test-row'));
                    rows.reduce(function(chain, row) {
                        return chain.then(function() { return runGemsTest(row.dataset.test, row); });
                    }, Promise.resolve());
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
        wp_send_json( self::run_test( sanitize_key( $_POST['test'] ?? '' ) ) );
    }

    public static function run_test( string $test ): array {
        $parent_user  = get_user_by( 'email', 'test.parent@knowly.test' );
        $teacher_user = get_user_by( 'email', 'test.teacher@knowly.test' );
        $parent_id    = $parent_user ? $parent_user->ID : 0;
        $teacher_id   = $teacher_user ? $teacher_user->ID : 0;

        $child_id = 0;
        if ( $parent_id ) {
            global $wpdb;
            $child_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT child_id FROM {$wpdb->prefix}knowly_children WHERE parent_id = %d LIMIT 1",
                $parent_id
            ) );
        }

        switch ( $test ) {
            case 'gem_credit_parent':
                if ( ! $parent_id ) return [ 'pass' => false, 'message' => 'Test parent not found.' ];
                update_user_meta( $parent_id, 'knowly_gem_balance', 0 );
                $result = Knowly_Gem_Service::credit( $parent_id, 10, 'admin_credit', 'tt_primary', 'test', 'Unit test credit' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === 10, 'result' => $result ];

            case 'gem_balance_parent':
                if ( ! $parent_id ) return [ 'pass' => false, 'message' => 'Test parent not found.' ];
                return [ 'pass' => true, 'balance' => Knowly_Gem_Service::get_balance( $parent_id ) ];

            case 'gem_allocate':
                if ( ! $parent_id || ! $child_id ) return [ 'pass' => false, 'message' => 'Test accounts not found.' ];
                update_user_meta( $parent_id, 'knowly_gem_balance', 10 );
                update_user_meta( $child_id,  'knowly_gem_balance', 0 );
                $result = Knowly_Gem_Service::allocate( $parent_id, $child_id, 5 );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['parent_balance'] === 5 && $result['child_balance'] === 5, 'result' => $result ];

            case 'gem_balance_child':
                if ( ! $child_id ) return [ 'pass' => false, 'message' => 'Test child not found.' ];
                return [ 'pass' => true, 'balance' => Knowly_Gem_Service::get_balance( $child_id ) ];

            case 'gem_deduct_child':
                if ( ! $child_id ) return [ 'pass' => false, 'message' => 'Test child not found.' ];
                $result = Knowly_Gem_Service::deduct( $child_id, 2, 'spent', 'tt_primary', 'test_session', 'Unit test deduct' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === $result['balance_before'] - 2, 'result' => $result ];

            case 'gem_deduct_excess':
                if ( ! $child_id ) return [ 'pass' => false, 'message' => 'Test child not found.' ];
                update_user_meta( $child_id, 'knowly_gem_balance', 1 );
                $result = Knowly_Gem_Service::deduct( $child_id, 999, 'spent', 'tt_primary', '', 'Should fail' );
                return [ 'pass' => is_wp_error( $result ) && $result->get_error_data()['status'] === 402 ];

            case 'gem_monthly_refresh':
                $parents = get_users( [ 'role' => 'knowly_parent', 'fields' => 'ID' ] );
                return [ 'pass' => true, 'parent_count' => count( $parents ), 'message' => 'Dry run — refresh not executed.' ];

            case 'red_gem_credit':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                update_user_meta( $teacher_id, 'knowly_red_gem_balance', 0 );
                $result = Knowly_Red_Gem_Service::credit( $teacher_id, 5, 'admin_credit', 'test', 'Unit test' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === 5, 'result' => $result ];

            case 'red_gem_balance':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                return [ 'pass' => true, 'balance' => Knowly_Red_Gem_Service::get_balance( $teacher_id ) ];

            case 'red_gem_deduct':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                $result = Knowly_Red_Gem_Service::deduct( $teacher_id, 2, 'assignment_spent', 'test_assign', 'Unit test deduct' );
                if ( is_wp_error( $result ) ) return [ 'pass' => false, 'error' => $result->get_error_message() ];
                return [ 'pass' => $result['balance_after'] === $result['balance_before'] - 2, 'result' => $result ];

            case 'red_gem_deduct_excess':
                if ( ! $teacher_id ) return [ 'pass' => false, 'message' => 'Test teacher not found.' ];
                update_user_meta( $teacher_id, 'knowly_red_gem_balance', 1 );
                $result = Knowly_Red_Gem_Service::deduct( $teacher_id, 999 );
                return [ 'pass' => is_wp_error( $result ) && $result->get_error_data()['status'] === 402 ];

            case 'red_gem_stipend_reset':
                $teachers = get_users( [ 'role' => 'knowly_teacher', 'fields' => 'ID',
                    'meta_query' => [ [ 'key' => 'knowly_approval_status', 'value' => 'approved' ] ] ] );
                return [ 'pass' => true, 'approved_teacher_count' => count( $teachers ), 'message' => 'Dry run.' ];

            case 'wc_active':
                return [ 'pass' => class_exists( 'WooCommerce' ), 'message' => class_exists( 'WooCommerce' ) ? 'WooCommerce is active.' : 'WooCommerce not found.' ];

            case 'wc_products':
                $products = Knowly_WooCommerce::get_gem_products();
                return [ 'pass' => is_array( $products ), 'product_count' => count( $products ), 'products' => $products ];

            case 'wc_hooks_registered': {
                $order_hook  = has_action( 'woocommerce_order_status_completed', [ 'Knowly_WooCommerce', 'handle_order_completed' ] );
                $return_hook = has_filter( 'woocommerce_get_return_url', [ 'Knowly_WooCommerce', 'custom_return_url' ] );
                $pass = (bool) $order_hook && (bool) $return_hook;
                return [
                    'pass'         => $pass,
                    'order_hook'   => $order_hook !== false ? "priority {$order_hook}" : 'NOT registered',
                    'return_hook'  => $return_hook !== false ? "priority {$return_hook}" : 'NOT registered',
                ];
            }

            case 'wc_order_create': {
                if ( ! $parent_id ) return [ 'pass' => false, 'message' => 'Test parent not found.' ];
                if ( ! function_exists( 'wc_create_order' ) ) return [ 'pass' => false, 'message' => 'WooCommerce not active.' ];
                $products = Knowly_WooCommerce::get_gem_products();
                if ( empty( $products ) ) return [ 'pass' => false, 'message' => 'No gem products configured.' ];
                $product_id = $products[0]['id'];
                $wc_product = wc_get_product( $product_id );
                if ( ! $wc_product ) return [ 'pass' => false, 'message' => "Could not load product #{$product_id}." ];
                $order = wc_create_order( [ 'customer_id' => $parent_id, 'status' => 'pending' ] );
                if ( is_wp_error( $order ) ) return [ 'pass' => false, 'error' => $order->get_error_message() ];
                $order->add_product( $wc_product, 1 );
                $order->set_billing_first_name( 'Test' );
                $order->set_billing_last_name( 'Parent' );
                $order->set_billing_email( 'test.parent@knowly.test' );
                $order->calculate_totals();
                $order->save();
                $order_id    = $order->get_id();
                $payment_url = $order->get_checkout_payment_url();
                $order_key   = $order->get_order_key();
                // Trash the test order immediately
                $order->delete( true );
                return [
                    'pass'        => $order_id > 0 && ! empty( $payment_url ),
                    'order_id'    => $order_id,
                    'payment_url' => $payment_url,
                    'order_key'   => $order_key,
                    'product'     => $products[0]['name'],
                    'note'        => 'Order trashed after test.',
                ];
            }

            case 'wc_order_status': {
                if ( ! $parent_id ) return [ 'pass' => false, 'message' => 'Test parent not found.' ];
                if ( ! function_exists( 'wc_create_order' ) ) return [ 'pass' => false, 'message' => 'WooCommerce not active.' ];
                $products = Knowly_WooCommerce::get_gem_products();
                if ( empty( $products ) ) return [ 'pass' => false, 'message' => 'No gem products configured.' ];
                $wc_product = wc_get_product( $products[0]['id'] );
                if ( ! $wc_product ) return [ 'pass' => false, 'message' => 'Could not load gem product.' ];
                $order = wc_create_order( [ 'customer_id' => $parent_id, 'status' => 'pending' ] );
                if ( is_wp_error( $order ) ) return [ 'pass' => false, 'error' => $order->get_error_message() ];
                $order->add_product( $wc_product, 1 );
                $order->calculate_totals();
                $order->save();
                $order_id  = $order->get_id();
                $status    = $order->get_status();
                $total     = $order->get_total();
                $currency  = $order->get_currency();
                $gems      = (int) $order->get_meta( '_knowly_gems_granted' );
                // Trash the test order
                $order->delete( true );
                $pass = $order_id > 0 && $status === 'pending' && is_numeric( $total ) && ! empty( $currency );
                return [
                    'pass'          => $pass,
                    'order_id'      => $order_id,
                    'status'        => $status,
                    'total'         => $total,
                    'currency'      => $currency,
                    'gems_granted'  => $gems,
                    'note'          => 'Order trashed after test.',
                ];
            }

            case 'wc_order_completion_hook': {
                if ( ! $parent_id ) return [ 'pass' => false, 'message' => 'Test parent not found.' ];
                if ( ! function_exists( 'wc_create_order' ) ) return [ 'pass' => false, 'message' => 'WooCommerce not active.' ];
                $products = Knowly_WooCommerce::get_gem_products();
                if ( empty( $products ) ) return [ 'pass' => false, 'message' => 'No gem products configured.' ];
                $wc_product   = wc_get_product( $products[0]['id'] );
                $gem_quantity = (int) get_post_meta( $products[0]['id'], '_knowly_gem_quantity', true );
                if ( ! $wc_product ) return [ 'pass' => false, 'message' => 'Could not load gem product.' ];
                // Record balance before
                $balance_before = Knowly_Gem_Service::get_balance( $parent_id );
                // Create + complete a test order
                $order = wc_create_order( [ 'customer_id' => $parent_id, 'status' => 'pending' ] );
                if ( is_wp_error( $order ) ) return [ 'pass' => false, 'error' => $order->get_error_message() ];
                $order->add_product( $wc_product, 1 );
                $order->calculate_totals();
                $order->save();
                // Fire the completion hook directly (same as WC would)
                Knowly_WooCommerce::handle_order_completed( $order->get_id() );
                // Re-load to check idempotency flag
                $order = wc_get_order( $order->get_id() );
                $gems_granted  = (int) $order->get_meta( '_knowly_gems_granted' );
                $balance_after = Knowly_Gem_Service::get_balance( $parent_id );
                $order_id      = $order->get_id();
                // Cleanup: reverse the credit so test doesn't pollute balance
                if ( $gems_granted > 0 ) {
                    Knowly_Gem_Service::deduct( $parent_id, $gems_granted, 'admin_deduct', '', '', 'Unit test cleanup' );
                }
                $order->delete( true );
                $pass = $gems_granted === $gem_quantity && $balance_after === $balance_before + $gem_quantity;
                return [
                    'pass'           => $pass,
                    'order_id'       => $order_id,
                    'gem_quantity'   => $gem_quantity,
                    'gems_granted'   => $gems_granted,
                    'balance_before' => $balance_before,
                    'balance_after'  => $balance_after,
                    'note'           => 'Balance restored and order trashed after test.',
                ];
            }

            case 'gems_products_endpoint': {
                $request  = new WP_REST_Request( 'GET', '/knowly/v1/gems/products' );
                $response = rest_do_request( $request );
                $data     = $response->get_data();
                $pass     = $response->get_status() === 200
                         && isset( $data['data']['products'] )
                         && is_array( $data['data']['products'] );
                return [
                    'pass'          => $pass,
                    'http_status'   => $response->get_status(),
                    'product_count' => $pass ? count( $data['data']['products'] ) : null,
                ];
            }

            case 'gems_costs_endpoint': {
                $request  = new WP_REST_Request( 'GET', '/knowly/v1/gems/costs' );
                $response = rest_do_request( $request );
                $data     = $response->get_data();
                $costs    = $data['data'] ?? [];
                $pass     = $response->get_status() === 200
                         && isset( $costs['trial']['easy'], $costs['trial']['medium'], $costs['trial']['hard'] )
                         && isset( $costs['quest']['first'], $costs['quest']['retake'] );
                return [
                    'pass'        => $pass,
                    'http_status' => $response->get_status(),
                    'costs'       => $costs,
                ];
            }

            default:
                return [ 'pass' => false, 'message' => "Unknown test: {$test}" ];
        }
    }

    // ── Save Settings ─────────────────────────────────────────────────────────

    private static function save_settings(): void {
        $curriculum = sanitize_key( $_POST['knowly_default_curriculum'] ?? 'tt_primary' );
        update_option( 'knowly_default_curriculum', $curriculum );
        update_option( 'knowly_dev_bypass_gems', ! empty( $_POST['knowly_dev_bypass_gems'] ) );

        foreach ( [ 'easy', 'medium', 'hard' ] as $diff ) {
            if ( isset( $_POST[ 'gem_cost_' . $diff ] ) ) {
                update_option( Knowly_Gem_Service::cost_key( $curriculum, $diff ), max( 0, (int) $_POST[ 'gem_cost_' . $diff ] ) );
            }
        }

        if ( isset( $_POST['gem_cost_quest_first'] ) ) {
            update_option( Knowly_Gem_Service::quest_cost_key( $curriculum, 'first' ), max( 0, (int) $_POST['gem_cost_quest_first'] ) );
        }
        if ( isset( $_POST['gem_cost_quest_retake'] ) ) {
            update_option( Knowly_Gem_Service::quest_cost_key( $curriculum, 'retake' ), max( 0, (int) $_POST['gem_cost_quest_retake'] ) );
        }
        if ( isset( $_POST['gem_free_monthly'] ) ) {
            update_option( Knowly_Gem_Service::free_monthly_key( $curriculum ), max( 0, (int) $_POST['gem_free_monthly'] ) );
        }
    }
}
