<?php
/**
 * Knowly_Admin_Editor — Knowly Editor admin panel.
 *
 * Three-tab panel: Training Material | Trials | Quests
 * All content operations performed via WP REST calls to /wp-json/knowly/v1/editor/*
 * which proxy to Railway / Supabase and Pinecone.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Editor {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_import_trial', [ __CLASS__, 'ajax_import_trial' ] );
        add_action( 'wp_ajax_knowly_import_quest', [ __CLASS__, 'ajax_import_quest' ] );
        add_action( 'wp_ajax_knowly_qb_load',      [ __CLASS__, 'ajax_qb_load'      ] );
        add_action( 'wp_ajax_knowly_qb_edit',      [ __CLASS__, 'ajax_qb_edit'      ] );
    }

    // =========================================================================
    // AJAX: Import Trial
    // =========================================================================

    public static function ajax_import_trial(): void {
        check_ajax_referer( 'knowly_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $raw = stripslashes( $_POST['package_data'] ?? '' );
        if ( ! $raw ) {
            wp_send_json_error( [ 'message' => 'package_data is required.' ], 422 );
        }

        $pkg = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( [ 'message' => 'Invalid JSON: ' . json_last_error_msg() ], 422 );
        }

        // Validate structure
        if ( empty( $pkg['meta'] ) || empty( $pkg['meta']['level'] ) || empty( $pkg['meta']['subject'] ) ) {
            wp_send_json_error( [ 'message' => 'meta must include level and subject.' ], 422 );
        }
        if ( empty( $pkg['questions'] ) || ! is_array( $pkg['questions'] ) ) {
            wp_send_json_error( [ 'message' => 'questions must be a non-empty array.' ], 422 );
        }
        $q = $pkg['questions'][0];
        if ( empty( $q['question_id'] ) || empty( $q['question'] ) || ! isset( $q['options'] ) || empty( $q['correct_answer'] ) ) {
            wp_send_json_error( [ 'message' => 'Each question requires question_id, question, options (A/B/C/D), and correct_answer.' ], 422 );
        }
        foreach ( [ 'A', 'B', 'C', 'D' ] as $opt ) {
            if ( ! array_key_exists( $opt, $q['options'] ) ) {
                wp_send_json_error( [ 'message' => "options must include A, B, C, and D. Missing: {$opt}." ], 422 );
            }
        }
        if ( empty( $pkg['answer_sheet'] ) || ! is_array( $pkg['answer_sheet'] ) ) {
            wp_send_json_error( [ 'message' => 'answer_sheet must be a non-empty array.' ], 422 );
        }

        // Auto-fill missing fields
        if ( empty( $pkg['package_id'] ) ) {
            $pkg['package_id'] = 'pkg-' . substr( md5( wp_json_encode( $pkg ) . uniqid( '', true ) ), 0, 16 );
        }
        if ( empty( $pkg['generated_at'] ) ) {
            $pkg['generated_at'] = current_time( 'c' );
        }

        $package_id = sanitize_text_field( $pkg['package_id'] );

        // Forward to Railway
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $admin_ids  = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token      = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : get_option( 'knowly_railway_api_key', '' );

        $response = wp_remote_post( $endpoint . '/api/v1/editor-save', [
            'timeout' => 30,
            'headers' => [
                'Authorization'    => 'Bearer ' . $token,
                'Content-Type'     => 'application/json',
                'X-AEP-Server-Key' => $server_key,
            ],
            'body' => wp_json_encode( $pkg ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Railway error: ' . $response->get_error_message() ], 502 );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 409 ) {
            wp_send_json_error( [ 'message' => 'Package ID already exists in Supabase. Change the package_id in your JSON and try again.' ], 409 );
        }
        if ( $code < 200 || $code >= 300 ) {
            wp_send_json_error( [ 'message' => $body['error'] ?? "Railway returned HTTP {$code}." ], 502 );
        }

        // Sync to WP
        self::sync_trial_to_wp( $package_id );

        wp_send_json_success( [ 'package_id' => $package_id, 'status' => 'pending_review' ] );
    }

    // =========================================================================
    // AJAX: Import Quest
    // =========================================================================

    public static function ajax_import_quest(): void {
        check_ajax_referer( 'knowly_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $raw = stripslashes( $_POST['content'] ?? '' );
        if ( ! $raw ) {
            wp_send_json_error( [ 'message' => 'content is required.' ], 422 );
        }

        $parsed = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( [ 'message' => 'Invalid JSON: ' . json_last_error_msg() ], 422 );
        }

        // Support { sections: [...] } at root or { content: { sections: [...] } }
        if ( isset( $parsed['sections'] ) ) {
            $content = $parsed;
        } elseif ( isset( $parsed['content']['sections'] ) ) {
            $content = $parsed['content'];
        } else {
            wp_send_json_error( [ 'message' => 'JSON must contain a sections array.' ], 422 );
        }

        if ( empty( $content['sections'] ) || ! is_array( $content['sections'] ) ) {
            wp_send_json_error( [ 'message' => 'sections must be a non-empty array.' ], 422 );
        }
        $section = $content['sections'][0];
        if ( ! isset( $section['title'] ) || ! isset( $section['explanation'] ) || ! is_array( $section['explanation'] ) ) {
            wp_send_json_error( [ 'message' => 'Each section must have a title and an explanation array.' ], 422 );
        }

        $level   = sanitize_text_field( $_POST['level'] ?? '' );
        $subject = sanitize_text_field( $_POST['subject'] ?? '' );
        if ( ! $level || ! $subject ) {
            wp_send_json_error( [ 'message' => 'level and subject are required.' ], 422 );
        }

        $body = [
            'curriculum'    => sanitize_text_field( $_POST['curriculum'] ?? 'tt_primary' ),
            'level'         => $level,
            'period'        => sanitize_text_field( $_POST['period'] ?? '' ) ?: null,
            'subject'       => $subject,
            'topic'         => sanitize_text_field( $_POST['topic'] ?? '' ) ?: null,
            'content'       => $content,
        ];

        // Forward to Railway
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $admin_ids  = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token      = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : get_option( 'knowly_railway_api_key', '' );

        $response = wp_remote_post( $endpoint . '/api/v1/quest/import', [
            'timeout' => 30,
            'headers' => [
                'Authorization'    => 'Bearer ' . $token,
                'Content-Type'     => 'application/json',
                'X-AEP-Server-Key' => $server_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Railway error: ' . $response->get_error_message() ], 502 );
        }

        $code      = wp_remote_retrieve_response_code( $response );
        $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            wp_send_json_error( [ 'message' => $resp_body['error'] ?? "Railway returned HTTP {$code}." ], 502 );
        }

        $quest_id = sanitize_text_field( $resp_body['quest_id'] ?? '' );
        if ( $quest_id ) {
            self::sync_quest_to_wp( $quest_id );
        }

        wp_send_json_success( [ 'quest_id' => $quest_id, 'status' => 'draft' ] );
    }

    // ── WP sync helpers ───────────────────────────────────────────────────────

    private static function sync_trial_to_wp( string $package_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_trial_packages';

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $admin_ids  = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token      = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : get_option( 'knowly_railway_api_key', '' );

        $response = wp_remote_get( $endpoint . '/api/v1/trial-editor/' . rawurlencode( $package_id ), [
            'timeout' => 15,
            'headers' => [
                'Authorization'    => 'Bearer ' . $token,
                'X-AEP-Server-Key' => $server_key,
            ],
        ] );

        if ( is_wp_error( $response ) ) return;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['package'] ) ) return;

        $pkg  = $data['package'];
        $meta = $pkg['meta'] ?? [];
        $now  = current_time( 'mysql', true );

        $row = [
            'package_id'   => $package_id,
            'curriculum'   => $meta['curriculum'] ?? 'tt_primary',
            'level'        => $meta['level']      ?? '',
            'period'       => $meta['period']     ?? null,
            'subject'      => $meta['subject']    ?? '',
            'difficulty'   => $meta['difficulty'] ?? null,
            'trial_type'   => $meta['trial_type'] ?? 'practice',
            'topic'        => $meta['topic']      ?? null,
            'questions'    => isset( $pkg['questions'] )    ? wp_json_encode( $pkg['questions'] )    : null,
            'answer_sheet' => isset( $pkg['answer_sheet'] ) ? wp_json_encode( $pkg['answer_sheet'] ) : null,
            'meta'         => $meta                         ? wp_json_encode( $meta )                : null,
            'status'       => $data['status']     ?? 'pending_review',
            'synced_at'    => $now,
            'updated_at'   => $now,
        ];

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE package_id = %s", $package_id ) );
        if ( $exists ) {
            $wpdb->update( $table, $row, [ 'package_id' => $package_id ] );
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( $table, $row );
        }
    }

    private static function sync_quest_to_wp( string $quest_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_quests';

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $admin_ids  = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $token      = ! empty( $admin_ids ) ? Knowly_JWT::encode( (int) $admin_ids[0] ) : get_option( 'knowly_railway_api_key', '' );

        $response = wp_remote_get( $endpoint . '/api/v1/quest/editor/' . rawurlencode( $quest_id ), [
            'timeout' => 15,
            'headers' => [
                'Authorization'    => 'Bearer ' . $token,
                'X-AEP-Server-Key' => $server_key,
            ],
        ] );

        if ( is_wp_error( $response ) ) return;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data ) ) return;

        $now = current_time( 'mysql', true );
        $row = [
            'quest_id'         => $quest_id,
            'variant'          => 'student',
            'curriculum'       => $data['curriculum']   ?? 'tt_primary',
            'level'            => $data['level']        ?? '',
            'period'           => $data['period']       ?? null,
            'subject'          => $data['subject']      ?? '',
            'topic'            => $data['topic']        ?? null,
            'module_number'    => isset( $data['module_number'] ) ? (int) $data['module_number'] : null,
            'module_title'     => $data['module_title'] ?? null,
            'objectives'       => isset( $data['objectives'] ) ? wp_json_encode( $data['objectives'] ) : null,
            'content'          => isset( $data['content'] )    ? wp_json_encode( $data['content'] )    : null,
            'status'           => $data['status']       ?? 'draft',
            'railway_quest_id' => $quest_id,
            'generated_at'     => ! empty( $data['generated_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data['generated_at'] ) ) : $now,
            'approved_at'      => ! empty( $data['approved_at'] )  ? gmdate( 'Y-m-d H:i:s', strtotime( $data['approved_at'] ) )  : null,
            'updated_at'       => $now,
        ];

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE quest_id = %s AND variant = 'student'", $quest_id ) );
        if ( $exists ) {
            $wpdb->update( $table, $row, [ 'quest_id' => $quest_id, 'variant' => 'student' ] );
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( $table, $row );
        }
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $tab        = sanitize_key( $_GET['tab'] ?? 'trials' );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );

        $tabs = [
            'trials'  => 'Question Bank',
            'lessons' => 'Lessons',
            'quests'  => 'Quests',
        ];

        // Taxonomy data for dropdowns (sourced from WP option — mirrors Railway taxonomy.js)
        $curriculum_config = get_option( 'knowly_curriculum_subjects', [] );
        $rest_nonce        = wp_create_nonce( 'wp_rest' );
        $rest_base         = rest_url( KNOWLY_REST_NAMESPACE . '/editor' );
        ?>
        <div class="wrap knowly-wrap" id="knowly-editor-app">
            <h1>Knowly Editor</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-editor&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>">
                   <?= esc_html( $label ) ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <?php if ( ! $railway_ok ) : ?>
            <div class="notice notice-warning inline" style="margin:8px 0 0;">
                <p>Railway endpoint not configured — Question Bank operations will fail. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a></p>
            </div>
            <?php endif; ?>

            <div class="knowly-editor-shell">

            <?php if ( $tab === 'trials' ) : ?>
                <?php self::render_trials_tab( $curriculum_config ); ?>

            <?php elseif ( $tab === 'lessons' ) : ?>
                <?php self::render_lessons_tab(); ?>

            <?php elseif ( $tab === 'quests' ) : ?>
                <?php self::render_quests_tab( $curriculum_config ); ?>
            <?php endif; ?>

            </div><!-- /.knowly-editor-shell -->
        </div>

        <script>
        var KnowlyEditor = {
            restBase:    <?= wp_json_encode( $rest_base ) ?>,
            nonce:       <?= wp_json_encode( $rest_nonce ) ?>,
            taxonomy:    <?= wp_json_encode( $curriculum_config ) ?>,
            tab:         <?= wp_json_encode( $tab ) ?>,
            ajaxUrl:     <?= wp_json_encode( admin_url( 'admin-ajax.php' ) ) ?>,
            ajaxNonce:   <?= wp_json_encode( wp_create_nonce( 'knowly_import' ) ) ?>
        };
        </script>
        <?php
    }



    // =========================================================================
    // TAB: TRIALS
    // =========================================================================

    // =========================================================================
    // TAB: QUESTION BANK EDITOR
    // =========================================================================

    // =========================================================================
    // TAB: LESSONS (placeholder — ready for expansion)
    // =========================================================================

    private static function render_lessons_tab(): void {
        ?>
        <div class="knowly-editor-tab" id="tab-lessons">
            <div style="max-width:560px;margin:40px auto;text-align:center;padding:40px 30px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;">
                <div style="font-size:42px;margin-bottom:16px;">📖</div>
                <h2 style="margin:0 0 10px;font-size:18px;color:#1d2327;">Lessons — Coming Soon</h2>
                <p style="color:#6b7280;font-size:13.5px;line-height:1.6;margin:0 0 20px;">
                    The Lessons tab will let you author, preview, and publish structured lesson content
                    tied to each curriculum module — videos, reading material, worked examples, and
                    interactive exercises — available to students before and after trial sessions.
                </p>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;text-align:left;font-size:12.5px;color:#374151;line-height:1.7;">
                    <strong style="display:block;margin-bottom:6px;color:#1d2327;">Planned features:</strong>
                    <ul style="margin:0;padding-left:18px;">
                        <li>Rich-text lesson authoring per module &amp; topic</li>
                        <li>Attach worked examples and visual aids</li>
                        <li>Link lessons to specific QB question topics</li>
                        <li>Student-facing lesson library (React)</li>
                        <li>Completion tracking per child</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // TAB: QUESTION BANK EDITOR
    // =========================================================================

    private static function render_trials_tab( array $taxonomy ): void {
        // Extract curriculum options (same approach as Trials::curriculum_parts)
        $lvl = $per = $sub = '';
        foreach ( $taxonomy as $curriculum ) {
            foreach ( $curriculum['levels']   ?? [] as $item ) $lvl .= '<option value="' . esc_attr( $item['value'] ) . '">' . esc_html( $item['label'] ) . '</option>';
            foreach ( $curriculum['periods']  ?? [] as $item ) $per .= '<option value="' . esc_attr( $item['value'] ) . '">' . esc_html( $item['label'] ) . '</option>';
            foreach ( $curriculum['subjects'] ?? [] as $item ) $sub .= '<option value="' . esc_attr( $item['value'] ) . '">' . esc_html( $item['label'] ) . '</option>';
        }
        ?>
        <div class="knowly-editor-tab" id="tab-trials">

            <!-- Filters -->
            <div class="knowly-editor-toolbar" style="flex-wrap:wrap;gap:8px;">
                <div class="knowly-editor-filters" id="qbe-filters" style="flex-wrap:wrap;gap:8px;">
                    <select id="qbe-level" class="knowly-filter-select">
                        <option value="">— Level —</option>
                        <?= $lvl ?>
                    </select>
                    <select id="qbe-period" class="knowly-filter-select">
                        <option value="">— Period —</option>
                        <?= $per ?>
                    </select>
                    <select id="qbe-subject" class="knowly-filter-select">
                        <option value="">— Subject —</option>
                        <?= $sub ?>
                    </select>
                    <button class="button" id="qbe-load-modules-btn" style="height:30px;">Load Modules</button>
                    <select id="qbe-module" class="knowly-filter-select">
                        <option value="">— Module —</option>
                    </select>
                    <select id="qbe-difficulty" class="knowly-filter-select">
                        <option value="">— Difficulty —</option>
                        <option value="easy">Easy</option>
                        <option value="medium">Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                    <select id="qbe-status" class="knowly-filter-select">
                        <option value="active">Active</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="retired">Retired</option>
                        <option value="all">All</option>
                    </select>
                    <button class="button button-primary" id="qbe-load-btn">Load Questions</button>
                </div>
            </div>

            <div id="qbe-status-bar" class="knowly-editor-status" style="margin:8px 0;"></div>

            <!-- Question table -->
            <table class="wp-list-table widefat fixed striped knowly-editor-table" id="qbe-table" style="display:none;">
                <thead>
                    <tr>
                        <th style="width:50px;">Mod</th>
                        <th style="width:100px;">Topic</th>
                        <th>Question</th>
                        <th style="width:75px;">Difficulty</th>
                        <th style="width:60px;">Served</th>
                        <th style="width:80px;">Status</th>
                        <th style="width:70px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="qbe-tbody"></tbody>
            </table>
            <div id="qbe-pagination" class="knowly-pagination" style="margin-top:10px;"></div>
        </div>

        <!-- Edit modal -->
        <div id="qbe-modal" class="knowly-modal knowly-modal-wide" style="display:none;">
            <div class="knowly-modal-content">
                <div class="knowly-modal-header">
                    <h2>Edit Question</h2>
                    <button class="knowly-modal-close" data-modal="qbe-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <input type="hidden" id="qbe-edit-id">

                    <div class="knowly-form-row">
                        <label>Question text</label>
                        <textarea id="qbe-edit-question" class="knowly-form-textarea" rows="3" style="width:100%;"></textarea>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                        <?php foreach ( [ 'A', 'B', 'C', 'D' ] as $opt ) : ?>
                        <div class="knowly-form-row" style="margin:0;">
                            <label>Option <?= $opt ?></label>
                            <input type="text" id="qbe-edit-opt-<?= $opt ?>" class="knowly-form-input" style="width:100%;">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px;">
                        <div class="knowly-form-row" style="margin:0;">
                            <label>Correct Answer</label>
                            <select id="qbe-edit-correct" class="knowly-form-select" style="width:100%;">
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </div>
                        <div class="knowly-form-row" style="margin:0;">
                            <label>Difficulty</label>
                            <select id="qbe-edit-difficulty" class="knowly-form-select" style="width:100%;">
                                <option value="easy">Easy</option>
                                <option value="medium">Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>
                        <div class="knowly-form-row" style="margin:0;">
                            <label>Status</label>
                            <select id="qbe-edit-status" class="knowly-form-select" style="width:100%;">
                                <option value="active">Active</option>
                                <option value="pending_review">Pending Review</option>
                                <option value="retired">Retired</option>
                            </select>
                        </div>
                    </div>

                    <div class="knowly-form-row">
                        <label>Explanation</label>
                        <textarea id="qbe-edit-explanation" class="knowly-form-textarea" rows="2" style="width:100%;"></textarea>
                    </div>

                    <div class="knowly-form-row">
                        <label>Tip</label>
                        <input type="text" id="qbe-edit-tip" class="knowly-form-input" style="width:100%;">
                    </div>

                    <div class="knowly-form-row">
                        <label>Topic</label>
                        <input type="text" id="qbe-edit-topic" class="knowly-form-input" style="width:100%;">
                    </div>
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary" id="qbe-save-btn">Save Changes</button>
                    <button class="button knowly-modal-close" data-modal="qbe-modal">Cancel</button>
                    <span id="qbe-save-status" class="knowly-inline-status" style="margin-left:10px;"></span>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            var NONCE    = '<?= esc_js( wp_create_nonce( 'knowly_admin_nonce' ) ) ?>';
            var qbePage  = 1;
            var qbeTotal = 0;
            var DIFF_COLORS = {
                easy:   { bg:'#dcfce7', border:'#86efac', text:'#166534' },
                medium: { bg:'#fef9c3', border:'#fde68a', text:'#854d0e' },
                hard:   { bg:'#fee2e2', border:'#fca5a5', text:'#991b1b' },
            };

            function diffBadge(d) {
                var c = DIFF_COLORS[d] || { bg:'#f3f4f6', border:'#d1d5db', text:'#374151' };
                return '<span style="background:'+c.bg+';border:1px solid '+c.border+';color:'+c.text+';border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;">'+d+'</span>';
            }
            function statusBadge(s) {
                var styles = {
                    active:         'background:#dcfce7;border-color:#86efac;color:#166534;',
                    pending_review: 'background:#fef9c3;border-color:#fde68a;color:#854d0e;',
                    retired:        'background:#f3f4f6;border-color:#d1d5db;color:#6b7280;',
                };
                var st = styles[s] || styles.retired;
                return '<span style="'+st+'border:1px solid;border-radius:4px;padding:1px 7px;font-size:11px;">'+s.replace('_',' ')+'</span>';
            }
            function escHtml(str) { return $('<div>').text(str||'').html(); }

            // ── Load module list from QB ────────────────────────────────────
            $('#qbe-load-modules-btn').on('click', function() {
                var level   = $('#qbe-level').val();
                var subject = $('#qbe-subject').val();
                if (!level || !subject) {
                    alert('Select Level and Subject first.');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Loading…');
                $.post(ajaxurl, {
                    action:  'knowly_trials_get_modules',
                    nonce:   NONCE,
                    level:   level,
                    subject: subject,
                    period:  $('#qbe-period').val(),
                }, function(resp) {
                    $btn.prop('disabled', false).text('Load Modules');
                    if (!resp.success) { alert(resp.data || 'Failed to load modules.'); return; }
                    var opts = '<option value="">— Module —</option>';
                    (resp.data.modules || []).forEach(function(m) {
                        opts += '<option value="'+m.module_number+'">'+$('<div>').text(m.module_title).html()+'</option>';
                    });
                    $('#qbe-module').html(opts);
                });
            });

            // ── Load questions ──────────────────────────────────────────────
            function loadQuestions(page) {
                var level   = $('#qbe-level').val();
                var subject = $('#qbe-subject').val();
                if (!level || !subject) {
                    $('#qbe-status-bar').text('Please select a Level and Subject first.').css('color','#b91c1c');
                    return;
                }
                page = page || 1;
                qbePage = page;
                $('#qbe-load-btn').prop('disabled',true).text('Loading…');
                $('#qbe-status-bar').text('').css('color','');

                $.post(ajaxurl, {
                    action:     'knowly_qb_load',
                    nonce:      NONCE,
                    level:      level,
                    period:     $('#qbe-period').val(),
                    subject:    subject,
                    module:     $('#qbe-module').val(),
                    difficulty: $('#qbe-difficulty').val(),
                    status:     $('#qbe-status').val(),
                    page:       page,
                }, function(resp) {
                    $('#qbe-load-btn').prop('disabled',false).text('Load Questions');
                    if (!resp.success) {
                        $('#qbe-status-bar').text(resp.data).css('color','#b91c1c');
                        return;
                    }
                    var data = resp.data;
                    qbeTotal = data.total;
                    renderTable(data);
                });
            }

            function renderTable(data) {
                var qs = data.questions || [];
                $('#qbe-table').show();

                if (!qs.length) {
                    $('#qbe-tbody').html('<tr><td colspan="7" style="color:#666;text-align:center;padding:20px;">No questions found.</td></tr>');
                    $('#qbe-pagination').html('');
                    return;
                }

                var rows = '';
                qs.forEach(function(q) {
                    var preview = escHtml((q.question || '').substring(0,100)) + ((q.question||'').length > 100 ? '…' : '');
                    rows += '<tr data-id="'+q.id+'">';
                    rows += '<td>'+q.module_number+'</td>';
                    rows += '<td style="font-size:11px;">'+escHtml(q.topic||'')+'</td>';
                    rows += '<td style="font-size:12.5px;">'+preview+'</td>';
                    rows += '<td>'+diffBadge(q.difficulty)+'</td>';
                    rows += '<td style="text-align:center;">'+q.times_served+'</td>';
                    rows += '<td>'+statusBadge(q.status)+'</td>';
                    rows += '<td><button class="button button-small qbe-edit-btn" data-q="'+encodeURIComponent(JSON.stringify(q))+'">Edit</button></td>';
                    rows += '</tr>';
                });
                $('#qbe-tbody').html(rows);

                // Pagination
                var paginHtml = '';
                if (data.pages > 1) {
                    paginHtml += '<span style="font-size:13px;color:#666;">Page '+data.page+' of '+data.pages+' ('+data.total+' questions)</span> ';
                    if (data.page > 1) paginHtml += '<button class="button button-small qbe-page-btn" data-page="'+(data.page-1)+'">← Prev</button> ';
                    if (data.page < data.pages) paginHtml += '<button class="button button-small qbe-page-btn" data-page="'+(data.page+1)+'">Next →</button>';
                } else {
                    paginHtml = '<span style="font-size:12px;color:#999;">'+data.total+' question(s)</span>';
                }
                $('#qbe-pagination').html(paginHtml);
            }

            // ── Open edit modal ─────────────────────────────────────────────
            function openEdit(q) {
                $('#qbe-edit-id').val(q.id);
                $('#qbe-edit-question').val(q.question||'');
                $('#qbe-edit-opt-A').val((q.options||{}).A||'');
                $('#qbe-edit-opt-B').val((q.options||{}).B||'');
                $('#qbe-edit-opt-C').val((q.options||{}).C||'');
                $('#qbe-edit-opt-D').val((q.options||{}).D||'');
                $('#qbe-edit-correct').val(q.correct_answer||'A');
                $('#qbe-edit-difficulty').val(q.difficulty||'easy');
                $('#qbe-edit-status').val(q.status||'active');
                $('#qbe-edit-explanation').val(q.explanation||'');
                $('#qbe-edit-tip').val(q.tip||'');
                $('#qbe-edit-topic').val(q.topic||'');
                $('#qbe-save-status').text('');
                $('#qbe-modal').show();
            }

            // ── Save edit ───────────────────────────────────────────────────
            $('#qbe-save-btn').on('click', function() {
                var id = $('#qbe-edit-id').val();
                if (!id) return;
                $(this).prop('disabled',true).text('Saving…');
                $('#qbe-save-status').text('');

                $.post(ajaxurl, {
                    action:        'knowly_qb_edit',
                    nonce:         NONCE,
                    question_id:   id,
                    question:      $('#qbe-edit-question').val(),
                    opt_a:         $('#qbe-edit-opt-A').val(),
                    opt_b:         $('#qbe-edit-opt-B').val(),
                    opt_c:         $('#qbe-edit-opt-C').val(),
                    opt_d:         $('#qbe-edit-opt-D').val(),
                    correct_answer:$('#qbe-edit-correct').val(),
                    difficulty:    $('#qbe-edit-difficulty').val(),
                    status:        $('#qbe-edit-status').val(),
                    explanation:   $('#qbe-edit-explanation').val(),
                    tip:           $('#qbe-edit-tip').val(),
                    topic:         $('#qbe-edit-topic').val(),
                }, function(resp) {
                    $('#qbe-save-btn').prop('disabled',false).text('Save Changes');
                    if (!resp.success) {
                        $('#qbe-save-status').text(resp.data||'Save failed.').css('color','#b91c1c');
                        return;
                    }
                    $('#qbe-save-status').text('✓ Saved').css('color','#16a34a');
                    // Update the row in the table without a full reload
                    var q = resp.data.question;
                    var $row = $('#qbe-tbody tr[data-id="'+q.id+'"]');
                    $row.find('td:eq(3)').html(diffBadge(q.difficulty));
                    $row.find('td:eq(5)').html(statusBadge(q.status));
                    $row.find('.qbe-edit-btn').data('q', encodeURIComponent(JSON.stringify(q)));
                });
            });

            // ── Event bindings ──────────────────────────────────────────────
            $('#qbe-load-btn').on('click', function() { loadQuestions(1); });

            $(document).on('click', '.qbe-edit-btn', function() {
                var q = JSON.parse(decodeURIComponent($(this).data('q')));
                openEdit(q);
            });

            $(document).on('click', '.qbe-page-btn', function() {
                loadQuestions(parseInt($(this).data('page')));
            });

            $(document).on('click', '.knowly-modal-close[data-modal="qbe-modal"]', function() {
                $('#qbe-modal').hide();
            });

        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // TAB: QUESTS
    // =========================================================================

    private static function render_quests_tab( array $taxonomy ): void {
        ?>
        <div class="knowly-editor-tab" id="tab-quests">
            <div class="knowly-editor-toolbar">
                <div class="knowly-editor-filters" id="quests-filters">
                    <select id="quest-filter-curriculum" class="knowly-filter-select">
                        <option value="">All Curricula</option>
                        <?php foreach ( $taxonomy as $key => $cfg ) : ?>
                        <option value="<?= esc_attr( $key ) ?>"><?= esc_html( $cfg['display_name'] ?? $key ) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="quest-filter-level" class="knowly-filter-select"><option value="">All Levels</option></select>
                    <select id="quest-filter-period" class="knowly-filter-select"><option value="">All Periods</option></select>
                    <select id="quest-filter-subject" class="knowly-filter-select"><option value="">All Subjects</option></select>
                    <select id="quest-filter-status" class="knowly-filter-select">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                    <button class="button" id="quest-filter-btn">Filter</button>
                </div>
                <div style="display:flex;gap:8px;">
                    <button class="button button-primary" id="quest-generate-btn">+ Generate</button>
                    <button class="button" id="quest-import-btn">&#8679; Import JSON</button>
                </div>
            </div>

            <div id="quest-status-bar" class="knowly-editor-status"></div>

            <table class="wp-list-table widefat fixed striped knowly-editor-table" id="quest-table">
                <thead>
                    <tr>
                        <th>Quest ID</th>
                        <th>Curriculum</th>
                        <th>Level</th>
                        <th>Period</th>
                        <th>Subject</th>
                        <th>Module / Topic</th>
                        <th>Status</th>
                        <th>Generated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="quest-tbody">
                    <tr><td colspan="9" class="knowly-loading">Loading…</td></tr>
                </tbody>
            </table>
            <div id="quest-pagination" class="knowly-pagination"></div>
        </div>

        <!-- Quest View/Edit Modal -->
        <div id="quest-view-modal" class="knowly-modal knowly-modal-wide" style="display:none;">
            <div class="knowly-modal-content">
                <div class="knowly-modal-header">
                    <h2 id="quest-modal-title">Quest</h2>
                    <button class="knowly-modal-close" data-modal="quest-view-modal">&times;</button>
                </div>
                <div class="knowly-modal-body" id="quest-modal-body">
                    <!-- Populated by JS -->
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary" id="quest-save-edit-btn" style="display:none;">Save Changes</button>
                    <button class="button" id="quest-edit-mode-btn">Edit</button>
                    <button class="button knowly-modal-close" data-modal="quest-view-modal">Close</button>
                    <span id="quest-save-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>

        <!-- Quest Generate Modal -->
        <div id="quest-generate-modal" class="knowly-modal" style="display:none;">
            <div class="knowly-modal-content">
                <div class="knowly-modal-header">
                    <h2>Generate Quest</h2>
                    <button class="knowly-modal-close" data-modal="quest-generate-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <div class="knowly-form-row">
                        <label>Curriculum</label>
                        <select id="qg-curriculum" class="knowly-form-select">
                            <?php foreach ( $taxonomy as $key => $cfg ) : ?>
                            <option value="<?= esc_attr( $key ) ?>"><?= esc_html( $cfg['display_name'] ?? $key ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Level</label>
                        <select id="qg-level" class="knowly-form-select"><option value="">Select Level</option></select>
                    </div>
                    <div class="knowly-form-row" id="qg-period-row">
                        <label>Period</label>
                        <select id="qg-period" class="knowly-form-select"><option value="">Select Period</option></select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Subject</label>
                        <select id="qg-subject" class="knowly-form-select"><option value="">Select Subject</option></select>
                    </div>
                    <div class="knowly-form-row" id="qg-module-row" style="display:none;">
                        <label>Module</label>
                        <select id="qg-module" class="knowly-form-select"><option value="">Select Module</option></select>
                    </div>
                    <div class="knowly-form-row" id="qg-topic-row" style="display:none;">
                        <label>Topic</label>
                        <select id="qg-topic" class="knowly-form-select"><option value="">Select Topic</option></select>
                    </div>
                    <div class="knowly-notice notice-info" id="qg-note" style="display:none;padding:8px;background:#f0f6fc;border-left:4px solid #007cba;">
                        <p id="qg-note-text"></p>
                    </div>
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary" id="qg-generate-btn">Generate Quest</button>
                    <button class="button knowly-modal-close" data-modal="quest-generate-modal">Cancel</button>
                    <span id="qg-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>

        <!-- Quest Delete Confirm -->
        <div id="quest-delete-modal" class="knowly-modal knowly-confirm-modal" style="display:none;">
            <div class="knowly-modal-content knowly-modal-sm">
                <div class="knowly-modal-header">
                    <h2>Delete Quest</h2>
                    <button class="knowly-modal-close" data-modal="quest-delete-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <p>This will permanently delete this Quest. Only draft and rejected Quests can be deleted.</p>
                    <input type="hidden" id="quest-delete-id" value="">
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary knowly-danger-btn" id="quest-delete-confirm-btn">Yes, Delete</button>
                    <button class="button knowly-modal-close" data-modal="quest-delete-modal">Cancel</button>
                    <span id="quest-delete-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>

        <!-- Quest Import Modal -->
        <div id="quest-import-modal" class="knowly-modal" style="display:none;">
            <div class="knowly-modal-content knowly-modal-wide">
                <div class="knowly-modal-header">
                    <h2>Import Quest</h2>
                    <button class="knowly-modal-close" data-modal="quest-import-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <p style="margin-bottom:12px;color:#555;">Paste or upload a Quest JSON file. The JSON must contain a <code>sections</code> array. Fill in the quest metadata below — it is not included in the JSON format. The quest will be imported as <strong>draft</strong>.</p>
                    <div class="knowly-form-row">
                        <label>Upload .json file</label>
                        <input type="file" id="quest-import-file" accept=".json" style="display:block;margin-top:4px;">
                    </div>
                    <div class="knowly-form-row">
                        <label>Or paste JSON</label>
                        <textarea id="quest-import-json" class="knowly-form-textarea" rows="10" placeholder='{ "sections": [ { "title": "...", "section_number": 1, "explanation": [...] } ] }'></textarea>
                    </div>
                    <hr style="margin:16px 0;border:none;border-top:1px solid #ddd;">
                    <p style="font-weight:600;margin-bottom:8px;">Quest Metadata</p>
                    <div class="knowly-form-row">
                        <label>Curriculum</label>
                        <select id="qi-curriculum" class="knowly-form-select">
                            <?php foreach ( $taxonomy as $key => $cfg ) : ?>
                            <option value="<?= esc_attr( $key ) ?>"><?= esc_html( $cfg['display_name'] ?? $key ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Level</label>
                        <select id="qi-level" class="knowly-form-select"><option value="">Select Level</option></select>
                    </div>
                    <div class="knowly-form-row" id="qi-period-row">
                        <label>Period</label>
                        <select id="qi-period" class="knowly-form-select"><option value="">N/A (Capstone)</option></select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Subject</label>
                        <select id="qi-subject" class="knowly-form-select"><option value="">Select Subject</option></select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Topic / Module Title <span class="knowly-optional">(optional)</span></label>
                        <input type="text" id="qi-topic" class="knowly-form-input" placeholder="e.g. Fractions">
                    </div>
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary" id="quest-import-confirm-btn">Import</button>
                    <button class="button knowly-modal-close" data-modal="quest-import-modal">Cancel</button>
                    <span id="quest-import-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX: QB Question Browser
    // =========================================================================

    public static function ajax_qb_load(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $level   = sanitize_text_field( $_POST['level']   ?? '' );
        $subject = sanitize_text_field( $_POST['subject'] ?? '' );

        if ( ! $level || ! $subject ) {
            wp_send_json_error( 'level and subject are required.' );
            return;
        }

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $args = [
            'curriculum' => 'tt_primary',
            'level'      => $level,
            'subject'    => $subject,
            'per_page'   => 25,
            'page'       => max( 1, (int) ( $_POST['page'] ?? 1 ) ),
        ];
        foreach ( [ 'period', 'difficulty', 'status' ] as $key ) {
            $val = sanitize_text_field( $_POST[ $key ] ?? '' );
            if ( $val !== '' ) $args[ $key ] = $val;
        }
        $module = sanitize_text_field( $_POST['module'] ?? '' );
        if ( $module !== '' ) $args['module_number'] = (int) $module;

        $url  = add_query_arg( $args, $endpoint . '/api/v1/question-bank/questions' );
        $resp = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [ 'X-AEP-Server-Key' => $server_key ],
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }

    // =========================================================================
    // AJAX: QB Question Edit
    // =========================================================================

    public static function ajax_qb_edit(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $question_id = sanitize_text_field( $_POST['question_id'] ?? '' );
        if ( ! $question_id ) { wp_send_json_error( 'question_id required.' ); return; }

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) { wp_send_json_error( 'Railway endpoint not configured.' ); return; }

        $body = [
            'question'      => sanitize_textarea_field( $_POST['question']      ?? '' ),
            'correct_answer'=> sanitize_text_field(     $_POST['correct_answer'] ?? '' ),
            'difficulty'    => sanitize_text_field(     $_POST['difficulty']     ?? '' ),
            'status'        => sanitize_text_field(     $_POST['status']         ?? '' ),
            'explanation'   => sanitize_textarea_field( $_POST['explanation']    ?? '' ),
            'tip'           => sanitize_text_field(     $_POST['tip']            ?? '' ),
            'topic'         => sanitize_text_field(     $_POST['topic']          ?? '' ),
            'options'       => [
                'A' => sanitize_text_field( $_POST['opt_a'] ?? '' ),
                'B' => sanitize_text_field( $_POST['opt_b'] ?? '' ),
                'C' => sanitize_text_field( $_POST['opt_c'] ?? '' ),
                'D' => sanitize_text_field( $_POST['opt_d'] ?? '' ),
            ],
        ];

        $resp = wp_remote_request( $endpoint . '/api/v1/question-bank/questions/' . rawurlencode( $question_id ), [
            'method'  => 'PATCH',
            'timeout' => 20,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) { wp_send_json_error( $resp->get_error_message() ); return; }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) { wp_send_json_error( $parsed['error'] ?? "HTTP {$code}" ); return; }

        wp_send_json_success( $parsed );
    }
}
