<?php
/**
 * Knowly_Admin_QB — Question Bank admin page.
 *
 * Shows pool status per scope/difficulty and provides seeding controls
 * for the Phase 3B question_bank table.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_QB {

    public static function boot(): void {
        // No AJAX hooks needed — uses REST endpoints via fetch()
    }

    // ── Railway helpers ───────────────────────────────────────────────────────

    private static function railway_get( string $path, array $params = [] ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) return [ 'error' => 'Railway endpoint not configured.' ];

        $url = $endpoint . $path;
        if ( $params ) $url .= '?' . http_build_query( $params );

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) return [ 'error' => $response->get_error_message() ];

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) return [ 'error' => $body['error'] ?? "HTTP {$code}" ];
        return $body ?: [];
    }

    // ── Page render ───────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $status_data = self::railway_get( '/api/v1/question-bank/status' );
        $pools       = $status_data['pools'] ?? [];
        $has_error   = isset( $status_data['error'] );
        $railway_url = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key  = get_option( 'knowly_railway_server_key', '' );
        $nonce       = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div class="wrap knowly-wrap">
            <h1>Question Bank</h1>
            <p>Pool status for all seeded scope/difficulty combinations. Target: 30 unused questions per slot. Low watermark: 15.</p>

            <?php if ( $has_error ) : ?>
                <div class="notice notice-error"><p>Could not fetch status: <?= esc_html( $status_data['error'] ) ?></p></div>
            <?php endif; ?>

            <div class="knowly-test-toolbar" style="margin-bottom:20px;">
                <button id="qb-refresh" class="button">↻ Refresh Status</button>
            </div>

            <h2>Pool Status</h2>
            <table class="knowly-table" id="qb-status-table">
                <thead>
                    <tr>
                        <th>Scope</th><th>Scope Ref</th><th>Difficulty</th>
                        <th>Total</th><th>Unused</th><th>Health</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $pools ) && ! $has_error ) : ?>
                    <tr><td colspan="7" style="text-align:center;color:#777;">No questions seeded yet. Use the seeding form below.</td></tr>
                <?php else : ?>
                    <?php foreach ( $pools as $pool ) :
                        $unused  = (int) ( $pool['unused'] ?? 0 );
                        $total   = (int) ( $pool['total']  ?? 0 );
                        $health  = $unused >= 15 ? 'ok' : ( $unused >= 5 ? 'warn' : 'low' );
                        $colors  = [ 'ok' => '#00a32a', 'warn' => '#dba617', 'low' => '#d63638' ];
                        $labels  = [ 'ok' => '✓ Healthy', 'warn' => '⚠ Low', 'low' => '✗ Critical' ];
                    ?>
                    <tr>
                        <td><?= esc_html( $pool['scope'] ) ?></td>
                        <td><code><?= esc_html( $pool['scope_ref'] ) ?></code></td>
                        <td><?= esc_html( $pool['difficulty'] ) ?></td>
                        <td><?= esc_html( $total ) ?></td>
                        <td style="color:<?= esc_attr( $colors[ $health ] ) ?>;font-weight:600;"><?= esc_html( $unused ) ?></td>
                        <td style="color:<?= esc_attr( $colors[ $health ] ) ?>;"><?= esc_html( $labels[ $health ] ) ?></td>
                        <td>
                            <button class="button button-small qb-replenish-slot"
                                data-scope="<?= esc_attr( $pool['scope'] ) ?>"
                                data-scope-ref="<?= esc_attr( $pool['scope_ref'] ) ?>"
                                data-difficulty="<?= esc_attr( $pool['difficulty'] ) ?>">
                                + Top Up
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <hr style="margin:30px 0;">

            <h2>Seed New Slot</h2>
            <p>Generate questions for a new scope/difficulty combination. <strong>Sync</strong> runs immediately (may take ~30s). Async queues the job for background processing.</p>

            <div class="knowly-form-grid" style="max-width:600px;">
                <table class="form-table">
                    <tr>
                        <th>Level</th>
                        <td>
                            <select id="qb-level">
                                <option value="std_4">Standard 4</option>
                                <option value="std_5">Standard 5</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Period</th>
                        <td>
                            <select id="qb-period">
                                <option value="term_1">Term 1</option>
                                <option value="term_2">Term 2</option>
                                <option value="term_3">Term 3</option>
                                <option value="">— (Capstone / None) —</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Subject</th>
                        <td>
                            <select id="qb-subject">
                                <option value="math">Math</option>
                                <option value="english">English</option>
                                <option value="science">Science</option>
                                <option value="social_studies">Social Studies</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Scope</th>
                        <td>
                            <select id="qb-scope">
                                <option value="subtopic">Subtopic</option>
                                <option value="general_topic">General Topic (Module)</option>
                                <option value="period">Full Period</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Scope Ref</th>
                        <td>
                            <input type="text" id="qb-scope-ref" class="regular-text"
                                placeholder="e.g. place_value_up_to_1_000_000">
                            <p class="description">Slugified topic/module/period key. Use underscores, lowercase.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Difficulty</th>
                        <td>
                            <select id="qb-difficulty">
                                <option value="easy">Easy</option>
                                <option value="medium">Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Target Count</th>
                        <td>
                            <input type="number" id="qb-count" value="30" min="5" max="50" style="width:80px;">
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <button id="qb-seed-async" class="button button-primary">Queue (Async)</button>
                            &nbsp;
                            <button id="qb-seed-sync" class="button" style="background:#2271b1;border-color:#2271b1;color:#fff;">
                                Generate Now (Sync ~30s)
                            </button>
                            <span id="qb-seed-status" style="margin-left:12px;color:#777;"></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <script>
        (function() {
            var RAILWAY = <?= wp_json_encode( $railway_url ) ?>;
            var SK      = <?= wp_json_encode( $server_key ) ?>;

            function setStatus(msg, color) {
                var el = document.getElementById('qb-seed-status');
                el.textContent = msg;
                el.style.color = color || '#777';
            }

            function seed(sync) {
                var level      = document.getElementById('qb-level').value;
                var period     = document.getElementById('qb-period').value || null;
                var subject    = document.getElementById('qb-subject').value;
                var scope      = document.getElementById('qb-scope').value;
                var scope_ref  = document.getElementById('qb-scope-ref').value.trim();
                var difficulty = document.getElementById('qb-difficulty').value;
                var count      = parseInt(document.getElementById('qb-count').value, 10);

                if (!scope_ref) { setStatus('Scope Ref is required.', '#d63638'); return; }

                setStatus(sync ? 'Generating… (please wait)' : 'Queuing…', '#2271b1');

                fetch(RAILWAY + '/api/v1/question-bank/replenish', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-AEP-Server-Key': SK },
                    body: JSON.stringify({ curriculum: 'tt_primary', level, period, subject, scope, scope_ref, difficulty, target_count: count, sync }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        setStatus('Error: ' + data.error, '#d63638');
                    } else if (sync) {
                        setStatus('Done! ' + (data.inserted || 0) + ' questions inserted.', '#00a32a');
                    } else {
                        setStatus('Queued. Job ID: ' + data.job_id, '#00a32a');
                    }
                })
                .catch(err => setStatus('Request failed: ' + err.message, '#d63638'));
            }

            document.getElementById('qb-seed-async').addEventListener('click', function() { seed(false); });
            document.getElementById('qb-seed-sync').addEventListener('click', function() { seed(true); });

            document.getElementById('qb-refresh').addEventListener('click', function() {
                window.location.reload();
            });

            document.querySelectorAll('.qb-replenish-slot').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var scope      = btn.dataset.scope;
                    var scope_ref  = btn.dataset.scopeRef;
                    var difficulty = btn.dataset.difficulty;
                    btn.textContent = 'Queuing…';
                    btn.disabled = true;
                    fetch(RAILWAY + '/api/v1/question-bank/replenish', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-AEP-Server-Key': SK },
                        body: JSON.stringify({ curriculum: 'tt_primary', scope, scope_ref, difficulty, target_count: 30, sync: false }),
                    })
                    .then(r => r.json())
                    .then(data => {
                        btn.textContent = data.error ? '✗ Error' : '✓ Queued';
                        btn.disabled = false;
                    })
                    .catch(() => { btn.textContent = '✗ Failed'; btn.disabled = false; });
                });
            });
        })();
        </script>
        <?php
    }
}
