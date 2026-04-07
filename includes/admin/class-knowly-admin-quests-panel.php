<?php
/**
 * Knowly_Admin_Quests_Panel — Quest module admin page.
 *
 * Tabs: Catalogue | Health Checks | Unit Tests | Simulations
 *
 * Quest catalogue uses Bearer JWT. Generation uses server key.
 * Delegates AJAX to Knowly_Admin_Pool handlers (already registered).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Quests_Panel {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_quests_panel_health', [ __CLASS__, 'ajax_health' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab        = sanitize_key( $_GET['tab'] ?? 'catalogue' );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $nonce      = wp_create_nonce( 'knowly_admin_nonce' );

        $tabs = [
            'catalogue' => 'Catalogue',
            'health'    => 'Health Checks',
            'tests'     => 'Unit Tests',
            'sims'      => 'Simulations',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Quests</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-quests-panel&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( ! $railway_ok ) : ?>
            <div class="notice notice-warning inline" style="margin:8px 0 0;"><p>Railway endpoint not configured. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php
            match ( $tab ) {
                'catalogue' => self::render_catalogue( $railway_ok, $server_key, $nonce ),
                'health'    => self::render_health( $nonce ),
                'tests'     => self::render_tests(),
                'sims'      => self::render_simulations(),
                default     => self::render_catalogue( $railway_ok, $server_key, $nonce ),
            };
            ?>
            </div>
        </div>
        <?php
    }

    // ── Catalogue Tab ─────────────────────────────────────────────────────────

    private static function render_catalogue( bool $railway_ok, string $server_key, string $nonce ): void {
        ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <select id="qp-level" style="height:30px;">
                <option value="std_1">std_1</option>
                <option value="std_2">std_2</option>
                <option value="std_3">std_3</option>
                <option value="std_4" selected>std_4</option>
                <option value="std_5">std_5</option>
            </select>
            <select id="qp-period" style="height:30px;">
                <option value="">All periods</option>
                <option value="term_1">term_1</option>
                <option value="term_2">term_2</option>
                <option value="term_3">term_3</option>
            </select>
            <button id="qp-load" class="button button-primary" <?= $railway_ok ? '' : 'disabled' ?>>
                ↓ Load Quest Catalogue
            </button>
            <span id="qp-summary" style="color:#666;font-size:13px;"></span>
        </div>
        <div id="qp-results">
            <p style="color:#888;">Select a level and click "Load Quest Catalogue" to view approved quests from Railway.</p>
        </div>

        <div style="margin-top:24px;padding:16px;background:#f6f7f7;border-radius:4px;border:1px solid #e5e7eb;">
            <h3 style="margin:0 0 12px;">Generate Quest</h3>
            <p style="font-size:12px;color:#666;margin:0 0 10px;">Generate a new quest and store it as approved in Railway/Supabase.</p>
            <div style="display:grid;grid-template-columns:repeat(4,1fr) auto;gap:8px;align-items:end;">
                <label style="font-size:12px;">Level<br><input type="text" id="qp-gen-level" class="regular-text" placeholder="std_4" style="margin-top:4px;"></label>
                <label style="font-size:12px;">Period<br><input type="text" id="qp-gen-period" class="regular-text" placeholder="term_1 or blank" style="margin-top:4px;"></label>
                <label style="font-size:12px;">Subject<br><input type="text" id="qp-gen-subject" class="regular-text" placeholder="math" style="margin-top:4px;"></label>
                <label style="font-size:12px;">Module Index<br><input type="number" id="qp-gen-module" class="regular-text" value="0" min="0" style="margin-top:4px;"></label>
                <button id="qp-generate" class="button button-primary" style="height:30px;align-self:end;" <?= ( $railway_ok && $server_key ) ? '' : 'disabled' ?>>Generate</button>
            </div>
            <div id="qp-gen-result" style="margin-top:10px;font-size:13px;"></div>
        </div>

        <script>
        (function($) {
            var nonce = '<?= esc_js( $nonce ) ?>';
            $('#qp-load').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Loading…');
                var level = $('#qp-level').val(), period = $('#qp-period').val();
                $.post(ajaxurl, { action: 'knowly_pool_quest_catalogue', nonce: nonce, level: level, period: period }, function(res) {
                    $btn.prop('disabled', false).text('↓ Load Quest Catalogue');
                    if (!res.success) { $('#qp-results').html('<p style="color:#dc2626;">Error: ' + (res.data.message || 'Unknown error') + '</p>'); return; }
                    var quests = res.data.quests || [];
                    $('#qp-summary').text(quests.length + ' approved quest(s) for ' + level + (period ? '/' + period : ''));
                    if (!quests.length) { $('#qp-results').html('<p style="color:#666;">No approved quests found for this combination. Use Generate below.</p>'); return; }
                    var html = '<table class="knowly-table widefat" style="font-size:12px;"><thead><tr><th>Quest ID</th><th>Level</th><th>Period</th><th>Subject</th><th>Module</th><th>Topic</th><th>Generated</th></tr></thead><tbody>';
                    $.each(quests, function(i, q) {
                        html += '<tr><td style="font-family:monospace;">' + q.quest_id + '</td>'
                            + '<td>' + (q.level||'') + '</td><td>' + (q.period||'<em>capstone</em>') + '</td>'
                            + '<td><strong>' + (q.subject||'') + '</strong></td><td>' + (q.module_number != null ? q.module_number : '—') + '</td>'
                            + '<td>' + (q.topic||q.module_title||'—') + '</td>'
                            + '<td>' + (q.generated_at ? q.generated_at.slice(0,10) : '—') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    $('#qp-results').html(html);
                });
            });
            $('#qp-generate').on('click', function() {
                var level = $('#qp-gen-level').val().trim(), period = $('#qp-gen-period').val().trim(),
                    subject = $('#qp-gen-subject').val().trim(), mi = parseInt($('#qp-gen-module').val(), 10);
                if (!level || !subject) { alert('Level and Subject are required.'); return; }
                $(this).prop('disabled', true).text('Generating…');
                var $res = $('#qp-gen-result').html('<em>Generating… this may take 10–20 seconds.</em>');
                $.post(ajaxurl, { action: 'knowly_pool_generate_quest', nonce: nonce, level: level, period: period, subject: subject, module_index: mi }, function(res) {
                    $('#qp-generate').prop('disabled', false).text('Generate');
                    $res.html(res.success
                        ? '<span style="color:#16a34a;">✓ Generated: <strong>' + res.data.quest_id + '</strong> (' + res.data.status + ')</span>'
                        : '<span style="color:#dc2626;">✗ ' + (res.data.message || 'Generation failed') + '</span>');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── Health Checks Tab ─────────────────────────────────────────────────────

    private static function render_health( string $nonce ): void {
        ?>
        <button id="qp-run-health" class="button button-primary" style="margin-bottom:16px;">Run Health Checks</button>
        <div id="qp-health-results"><p style="color:#888;">Click to run health checks.</p></div>
        <script>
        jQuery('#qp-run-health').on('click', function() {
            var $btn = jQuery(this).prop('disabled', true).text('Running…');
            jQuery.post(ajaxurl, { action: 'knowly_quests_panel_health', nonce: '<?= esc_js( $nonce ) ?>' }, function(res) {
                $btn.prop('disabled', false).text('Run Health Checks');
                if (!res.success) { jQuery('#qp-health-results').html('<p style="color:#dc2626;">' + (res.data?.message || 'Error') + '</p>'); return; }
                var html = '<table class="knowly-table" style="max-width:700px;"><tbody>';
                (res.data.checks || []).forEach(function(c) {
                    var col = c.status === 'pass' ? '#16a34a' : (c.status === 'warn' ? '#d97706' : '#dc2626');
                    var ico = c.status === 'pass' ? '✓' : (c.status === 'warn' ? '⚠' : '✗');
                    html += '<tr><td style="color:' + col + ';font-weight:600;width:40px;">' + ico + '</td><td><strong>' + c.label + '</strong></td><td style="color:#666;">' + (c.detail || '') + '</td></tr>';
                });
                html += '</tbody></table>';
                jQuery('#qp-health-results').html(html);
            });
        });
        </script>
        <?php
    }

    // ── Unit Tests Tab ────────────────────────────────────────────────────────

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test quest catalogue, start (first/retake/assigned), badge award and idempotency.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'block6_quests' ] );
    }

    // ── Simulations Tab ───────────────────────────────────────────────────────

    private static function render_simulations(): void {
        ?>
        <p style="color:#666;">Full Quest journeys (browse → start → complete → badge).</p>
        <div class="knowly-notice info" style="margin-top:12px;">Simulations coming soon. Run unit tests to validate individual endpoints.</div>
        <?php
    }

    // ── AJAX: Health ──────────────────────────────────────────────────────────

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $checks = [];
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        // 1. Railway reachable
        if ( $endpoint ) {
            $resp = wp_remote_get( $endpoint . '/api/v1/health', [ 'timeout' => 8 ] );
            $ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
            $checks[] = [ 'label' => 'Railway reachable', 'status' => $ok ? 'pass' : 'fail', 'detail' => $ok ? 'Online.' : 'Unreachable.' ];
        } else {
            $checks[] = [ 'label' => 'Railway endpoint', 'status' => 'fail', 'detail' => 'Not configured.' ];
        }

        // 2. Approved quests exist
        if ( $endpoint && $server_key ) {
            $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
            $token     = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : '';
            if ( $token ) {
                $resp = wp_remote_get( $endpoint . '/api/v1/quest/catalogue?level=std_4', [
                    'timeout' => 10,
                    'headers' => [ 'Authorization' => "Bearer {$token}" ],
                ] );
                if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                    $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
                    $count = $body['count'] ?? 0;
                    $checks[] = [ 'label' => 'Approved quests (std_4)', 'status' => $count > 0 ? 'pass' : 'warn', 'detail' => "{$count} approved quest(s) for std_4." ];
                } else {
                    $checks[] = [ 'label' => 'Approved quests (std_4)', 'status' => 'fail', 'detail' => 'Could not reach quest catalogue.' ];
                }
            }
        }

        // 3. knowly_badge CPT registered
        $badge_cpt = post_type_exists( 'knowly_badge' );
        $checks[] = [ 'label' => 'Badge CPT registered', 'status' => $badge_cpt ? 'pass' : 'fail', 'detail' => $badge_cpt ? 'knowly_badge post type exists.' : 'CPT not registered.' ];

        // 4. earned_badges user meta writable (spot check)
        $test_child = get_user_by( 'login', 'test.child' );
        if ( $test_child ) {
            $current = get_user_meta( $test_child->ID, '_knowly_badges_health_check', true );
            update_user_meta( $test_child->ID, '_knowly_badges_health_check', 'ok' );
            $after = get_user_meta( $test_child->ID, '_knowly_badges_health_check', true );
            delete_user_meta( $test_child->ID, '_knowly_badges_health_check' );
            $checks[] = [ 'label' => 'earned_badges meta writable', 'status' => $after === 'ok' ? 'pass' : 'fail', 'detail' => $after === 'ok' ? 'User meta read/write OK.' : 'User meta write failed.' ];
        } else {
            $checks[] = [ 'label' => 'earned_badges meta writable', 'status' => 'warn', 'detail' => 'No test.child account — run provisioning.' ];
        }

        wp_send_json_success( [ 'checks' => $checks ] );
    }
}
