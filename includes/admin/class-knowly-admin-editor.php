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
        // No AJAX handlers needed — JS communicates via WP REST API with nonce auth
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $tab        = sanitize_key( $_GET['tab'] ?? 'training' );
        $railway_ok = ! empty( get_option( 'knowly_railway_endpoint' ) );

        $tabs = [
            'training' => 'Training Material',
            'trials'   => 'Trials',
            'quests'   => 'Quests',
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
                <p>Railway endpoint not configured — Trial and Quest operations will fail. <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-settings' ) ) ?>">Settings →</a></p>
            </div>
            <?php endif; ?>

            <div class="knowly-editor-shell">

            <?php if ( $tab === 'training' ) : ?>
                <?php self::render_training_tab( $curriculum_config ); ?>

            <?php elseif ( $tab === 'trials' ) : ?>
                <?php self::render_trials_tab( $curriculum_config ); ?>

            <?php elseif ( $tab === 'quests' ) : ?>
                <?php self::render_quests_tab( $curriculum_config ); ?>
            <?php endif; ?>

            </div><!-- /.knowly-editor-shell -->
        </div>

        <script>
        var KnowlyEditor = {
            restBase:   <?= wp_json_encode( $rest_base ) ?>,
            nonce:      <?= wp_json_encode( $rest_nonce ) ?>,
            taxonomy:   <?= wp_json_encode( $curriculum_config ) ?>,
            tab:        <?= wp_json_encode( $tab ) ?>
        };
        </script>
        <?php
    }

    // =========================================================================
    // TAB: TRAINING MATERIAL
    // =========================================================================

    private static function render_training_tab( array $taxonomy ): void {
        ?>
        <div class="knowly-editor-tab" id="tab-training">
            <div class="knowly-editor-toolbar">
                <div class="knowly-editor-filters" id="training-filters">
                    <select id="tm-filter-curriculum" class="knowly-filter-select">
                        <option value="">All Curricula</option>
                        <?php foreach ( $taxonomy as $key => $cfg ) : ?>
                        <option value="<?= esc_attr( $key ) ?>"><?= esc_html( $cfg['display_name'] ?? $key ) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="tm-filter-level" class="knowly-filter-select"><option value="">All Levels</option></select>
                    <select id="tm-filter-period" class="knowly-filter-select"><option value="">All Periods</option></select>
                    <select id="tm-filter-subject" class="knowly-filter-select"><option value="">All Subjects</option></select>
                    <button class="button" id="tm-filter-btn">Filter</button>
                </div>
                <div>
                    <button class="button button-primary" id="tm-add-btn">+ Add Training Material</button>
                </div>
            </div>

            <div id="tm-status-bar" class="knowly-editor-status"></div>

            <table class="wp-list-table widefat fixed striped knowly-editor-table" id="tm-table">
                <thead>
                    <tr>
                        <th>Curriculum</th>
                        <th>Level</th>
                        <th>Period</th>
                        <th>Subject</th>
                        <th>Topic</th>
                        <th>Content Preview</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tm-tbody">
                    <tr><td colspan="8" class="knowly-loading">Loading…</td></tr>
                </tbody>
            </table>
            <div id="tm-pagination" class="knowly-pagination"></div>
        </div>

        <!-- Training Material Modal -->
        <div id="tm-modal" class="knowly-modal" style="display:none;">
            <div class="knowly-modal-content">
                <div class="knowly-modal-header">
                    <h2 id="tm-modal-title">Add Training Material</h2>
                    <button class="knowly-modal-close" data-modal="tm-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <input type="hidden" id="tm-edit-id" value="">
                    <div class="knowly-form-row">
                        <label>Curriculum</label>
                        <select id="tm-form-curriculum" class="knowly-form-select">
                            <?php foreach ( $taxonomy as $key => $cfg ) : ?>
                            <option value="<?= esc_attr( $key ) ?>"><?= esc_html( $cfg['display_name'] ?? $key ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Level</label>
                        <select id="tm-form-level" class="knowly-form-select"><option value="">Select Level</option></select>
                    </div>
                    <div class="knowly-form-row" id="tm-period-row">
                        <label>Period</label>
                        <select id="tm-form-period" class="knowly-form-select"><option value="">Select Period</option></select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Subject</label>
                        <select id="tm-form-subject" class="knowly-form-select"><option value="">Select Subject</option></select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Topic</label>
                        <input type="text" id="tm-form-topic" class="knowly-form-input" placeholder="e.g. Place Value">
                    </div>
                    <div class="knowly-form-row">
                        <label>Subtopic <span class="knowly-optional">(optional)</span></label>
                        <input type="text" id="tm-form-subtopic" class="knowly-form-input" placeholder="e.g. Rounding to the nearest thousand">
                    </div>
                    <div class="knowly-form-row">
                        <label>Content</label>
                        <textarea id="tm-form-content" class="knowly-form-textarea" rows="10" placeholder="Paste or type the curriculum content here…"></textarea>
                    </div>
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary" id="tm-save-btn">Save</button>
                    <button class="button knowly-modal-close" data-modal="tm-modal">Cancel</button>
                    <span id="tm-save-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>

        <!-- Training Material Delete Confirm -->
        <div id="tm-delete-modal" class="knowly-modal knowly-confirm-modal" style="display:none;">
            <div class="knowly-modal-content knowly-modal-sm">
                <div class="knowly-modal-header">
                    <h2>Delete Training Material</h2>
                    <button class="knowly-modal-close" data-modal="tm-delete-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <p>This will permanently remove this training material from the curriculum knowledge base. AI generation for this topic will no longer have access to this content.</p>
                    <p><strong>Are you sure?</strong></p>
                    <input type="hidden" id="tm-delete-id" value="">
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary knowly-danger-btn" id="tm-delete-confirm-btn">Yes, Delete</button>
                    <button class="button knowly-modal-close" data-modal="tm-delete-modal">Cancel</button>
                    <span id="tm-delete-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // TAB: TRIALS
    // =========================================================================

    private static function render_trials_tab( array $taxonomy ): void {
        ?>
        <div class="knowly-editor-tab" id="tab-trials">
            <div class="knowly-editor-toolbar">
                <div class="knowly-editor-filters" id="trials-filters">
                    <select id="trial-filter-curriculum" class="knowly-filter-select">
                        <option value="">All Curricula</option>
                        <?php foreach ( $taxonomy as $key => $cfg ) : ?>
                        <option value="<?= esc_attr( $key ) ?>"><?= esc_html( $cfg['display_name'] ?? $key ) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="trial-filter-level" class="knowly-filter-select"><option value="">All Levels</option></select>
                    <select id="trial-filter-period" class="knowly-filter-select"><option value="">All Periods</option></select>
                    <select id="trial-filter-subject" class="knowly-filter-select"><option value="">All Subjects</option></select>
                    <select id="trial-filter-difficulty" class="knowly-filter-select">
                        <option value="">All Difficulties</option>
                        <option value="easy">Easy</option>
                        <option value="medium">Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                    <select id="trial-filter-status" class="knowly-filter-select">
                        <option value="">All Statuses</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                    <button class="button" id="trial-filter-btn">Filter</button>
                </div>
                <div>
                    <button class="button button-primary" id="trial-generate-btn">+ Generate</button>
                </div>
            </div>

            <div id="trial-status-bar" class="knowly-editor-status"></div>

            <table class="wp-list-table widefat fixed striped knowly-editor-table" id="trial-table">
                <thead>
                    <tr>
                        <th>Package ID</th>
                        <th>Curriculum</th>
                        <th>Level</th>
                        <th>Period</th>
                        <th>Subject</th>
                        <th>Difficulty</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Served</th>
                        <th>Generated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="trial-tbody">
                    <tr><td colspan="11" class="knowly-loading">Loading…</td></tr>
                </tbody>
            </table>
            <div id="trial-pagination" class="knowly-pagination"></div>
        </div>

        <!-- Trial View/Edit Modal -->
        <div id="trial-view-modal" class="knowly-modal knowly-modal-wide" style="display:none;">
            <div class="knowly-modal-content">
                <div class="knowly-modal-header">
                    <h2 id="trial-modal-title">Trial Package</h2>
                    <button class="knowly-modal-close" data-modal="trial-view-modal">&times;</button>
                </div>
                <div class="knowly-modal-body" id="trial-modal-body">
                    <!-- Populated by JS -->
                </div>
                <div class="knowly-modal-footer" id="trial-modal-footer">
                    <button class="button button-primary" id="trial-save-edit-btn" style="display:none;">Save Changes</button>
                    <button class="button" id="trial-edit-mode-btn">Edit</button>
                    <button class="button knowly-modal-close" data-modal="trial-view-modal">Close</button>
                    <span id="trial-save-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>

        <!-- Trial Generate Modal -->
        <div id="trial-generate-modal" class="knowly-modal" style="display:none;">
            <div class="knowly-modal-content">
                <div class="knowly-modal-header">
                    <h2>Generate Trial Package</h2>
                    <button class="knowly-modal-close" data-modal="trial-generate-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <div class="knowly-form-row">
                        <label>Curriculum</label>
                        <select id="tg-curriculum" class="knowly-form-select">
                            <?php foreach ( $taxonomy as $key => $cfg ) : ?>
                            <option value="<?= esc_attr( $key ) ?>"><?= esc_html( $cfg['display_name'] ?? $key ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Level</label>
                        <select id="tg-level" class="knowly-form-select"><option value="">Select Level</option></select>
                    </div>
                    <div class="knowly-form-row" id="tg-period-row">
                        <label>Period</label>
                        <select id="tg-period" class="knowly-form-select"><option value="">Select Period</option></select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Subject</label>
                        <select id="tg-subject" class="knowly-form-select"><option value="">Select Subject</option></select>
                    </div>
                    <div class="knowly-form-row">
                        <label>Difficulty</label>
                        <select id="tg-difficulty" class="knowly-form-select">
                            <option value="easy">Easy (10 questions)</option>
                            <option value="medium">Medium (15 questions)</option>
                            <option value="hard">Hard (20 questions)</option>
                        </select>
                    </div>
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary" id="tg-generate-btn">Generate</button>
                    <button class="button knowly-modal-close" data-modal="trial-generate-modal">Cancel</button>
                    <span id="tg-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>

        <!-- Trial Delete Confirm -->
        <div id="trial-delete-modal" class="knowly-modal knowly-confirm-modal" style="display:none;">
            <div class="knowly-modal-content knowly-modal-sm">
                <div class="knowly-modal-header">
                    <h2>Delete Trial Package</h2>
                    <button class="knowly-modal-close" data-modal="trial-delete-modal">&times;</button>
                </div>
                <div class="knowly-modal-body">
                    <p>This will permanently delete the package and all associated question bank entries. This cannot be undone.</p>
                    <input type="hidden" id="trial-delete-id" value="">
                </div>
                <div class="knowly-modal-footer">
                    <button class="button button-primary knowly-danger-btn" id="trial-delete-confirm-btn">Yes, Delete</button>
                    <button class="button knowly-modal-close" data-modal="trial-delete-modal">Cancel</button>
                    <span id="trial-delete-status" class="knowly-inline-status"></span>
                </div>
            </div>
        </div>
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
                <div>
                    <button class="button button-primary" id="quest-generate-btn">+ Generate</button>
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
        <?php
    }
}
