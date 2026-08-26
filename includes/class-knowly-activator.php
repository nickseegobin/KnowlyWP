<?php
/**
 * Knowly_Activator — Plugin activation, database setup, role registration.
 *
 * Creates all KnowlyAPI database tables, registers WP roles, sets default options,
 * and schedules cron jobs.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Activator {

    // ── Activation ────────────────────────────────────────────────────────────

    public static function activate(): void {
        self::create_tables();
        self::register_roles();
        self::set_defaults();
        self::schedule_crons();

        update_option( 'knowly_db_version', KNOWLY_DB_VERSION );
        flush_rewrite_rules();
    }

    // ── Deactivation ─────────────────────────────────────────────────────────

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'knowly_weekly_digest' );
        wp_clear_scheduled_hook( 'knowly_monthly_gem_refresh' );
        wp_clear_scheduled_hook( 'knowly_monthly_red_gem_stipend' );
        flush_rewrite_rules();
    }

    // ── Safety net (called on every boot if DB version mismatch) ─────────────

    public static function maybe_upgrade(): void {
        if ( get_option( 'knowly_db_version' ) !== KNOWLY_DB_VERSION ) {
            self::create_tables();
            self::run_migrations();
            update_option( 'knowly_db_version', KNOWLY_DB_VERSION );
        }
    }

    // ── Column migrations (idempotent ALTER TABLEs) ───────────────────────────

    private static function run_migrations(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // v1.7.1 — add trial_type column to knowly_exam_sessions if missing
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'trial_type'",
            DB_NAME,
            $wpdb->prefix . 'knowly_exam_sessions'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_exam_sessions
                ADD COLUMN trial_type VARCHAR(50) NOT NULL DEFAULT 'practice'
                AFTER difficulty" );
        }

        // v1.9.4 — add source + task_id to knowly_exam_sessions for analytics segmentation
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'source'",
            DB_NAME,
            $wpdb->prefix . 'knowly_exam_sessions'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_exam_sessions
                ADD COLUMN source ENUM('self','teacher_assigned') NOT NULL DEFAULT 'self'
                AFTER trial_type" );
        }

        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'task_id'",
            DB_NAME,
            $wpdb->prefix . 'knowly_exam_sessions'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_exam_sessions
                ADD COLUMN task_id BIGINT UNSIGNED NULL DEFAULT NULL
                AFTER source" );
        }

        // v1.9.4b — add answer_sheet to knowly_exam_sessions for self-contained scoring
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'answer_sheet'",
            DB_NAME,
            $wpdb->prefix . 'knowly_exam_sessions'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_exam_sessions
                ADD COLUMN answer_sheet LONGTEXT NULL DEFAULT NULL
                AFTER task_id" );
        }

        // v1.9.6 — create quest_sessions table if missing (new WP-local session store)
        $tbl = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $wpdb->prefix . 'knowly_quest_sessions'
        ) );
        if ( ! $tbl ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_quest_sessions (
                session_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                quest_session_id VARCHAR(64)     NOT NULL,
                child_id         BIGINT UNSIGNED NOT NULL,
                quest_id         VARCHAR(200)    NOT NULL,
                source           ENUM('direct','assignment') NOT NULL DEFAULT 'direct',
                state            ENUM('active','completed')  NOT NULL DEFAULT 'active',
                started_at       DATETIME        NOT NULL,
                completed_at     DATETIME                 DEFAULT NULL,
                PRIMARY KEY      (session_id),
                UNIQUE KEY       uq_quest_session (quest_session_id),
                KEY              idx_child (child_id),
                KEY              idx_quest (quest_id),
                KEY              idx_state (state)
            ) {$charset};" );
        }

        // v1.9.7 — add task_id to knowly_quest_sessions for analytics segmentation
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'task_id'",
            DB_NAME,
            $wpdb->prefix . 'knowly_quest_sessions'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_quest_sessions
                ADD COLUMN task_id BIGINT UNSIGNED NULL DEFAULT NULL
                AFTER source" );
        }

        // v2.1.0 — add task_id to knowly_lesson_sessions for analytics segmentation
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'task_id'",
            DB_NAME,
            $wpdb->prefix . 'knowly_lesson_sessions'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_lesson_sessions
                ADD COLUMN task_id BIGINT UNSIGNED NULL DEFAULT NULL
                AFTER source" );
        }

        // v2.0.0 — add sort_order to knowly_quests for single-topic quest ordering
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'sort_order'",
            DB_NAME,
            $wpdb->prefix . 'knowly_quests'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_quests
                ADD COLUMN sort_order INT DEFAULT NULL
                AFTER module_title" );
        }

        // v1.7.2 — add type column to knowly_tasks if missing
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'type'",
            DB_NAME,
            $wpdb->prefix . 'knowly_tasks'
        ) );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_tasks
                ADD COLUMN type ENUM('quest','trial') NOT NULL DEFAULT 'trial'
                AFTER teacher_user_id" );
        }

        // v2.1.0 — add scope + module_numbers to knowly_tasks for teacher trial flavour selection
        $scope_col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'scope'",
            DB_NAME,
            $wpdb->prefix . 'knowly_tasks'
        ) );
        if ( empty( $scope_col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_tasks
                ADD COLUMN scope VARCHAR(20) NULL DEFAULT NULL
                AFTER difficulty" );
        }

        $mn_col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'module_numbers'",
            DB_NAME,
            $wpdb->prefix . 'knowly_tasks'
        ) );
        if ( empty( $mn_col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_tasks
                ADD COLUMN module_numbers TEXT NULL DEFAULT NULL
                AFTER scope" );
        }

        // v2.4.0 — expand knowly_tasks.type ENUM to include 'lesson'
        $type_enum = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'type'",
            DB_NAME,
            $wpdb->prefix . 'knowly_tasks'
        ) );
        if ( $type_enum && strpos( $type_enum, 'lesson' ) === false ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_tasks
                MODIFY COLUMN type ENUM('quest','trial','lesson') NOT NULL DEFAULT 'trial'" );
            // Fix tasks that were silently stored as '' because 'lesson' was not in the ENUM.
            // These tasks have a reference_id (lessons always reference content) and empty type.
            $wpdb->query( "UPDATE {$wpdb->prefix}knowly_tasks
                SET type = 'lesson'
                WHERE type = '' AND reference_id IS NOT NULL" );
        }

        // v2.5.0 — add lesson_section_index for section-specific lesson assignments
        $lsi_col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'lesson_section_index'",
            DB_NAME,
            $wpdb->prefix . 'knowly_tasks'
        ) );
        if ( empty( $lsi_col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_tasks
                ADD COLUMN lesson_section_index INT NULL DEFAULT NULL
                AFTER module_numbers" );
        }

        // v3.0.0 — badge system replaced. Old knowly_badge CPT and knowly_earned_badges
        // user meta are preserved but no longer read. New relational tables
        // (knowly_badge_definitions + knowly_badge_awards) are created via create_tables().
        // Note: we do not attempt to migrate CPT-based badge data — the old badge_id values
        // are CPT post IDs with no mapping to new definitions.

        // v2.5.1 — add audio_url + audio_generated_at to knowly_quests for Polly TTS
        $audio_col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'audio_url'",
            DB_NAME,
            $wpdb->prefix . 'knowly_quests'
        ) );
        if ( empty( $audio_col ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}knowly_quests
                ADD COLUMN audio_url VARCHAR(500) DEFAULT NULL
                AFTER content,
                ADD COLUMN audio_generated_at DATETIME DEFAULT NULL
                AFTER audio_url" );
        }

        // v1.9.3 — levels and periods now stored as {value,label} objects so the
        // Editor dropdowns can display human-readable names (Standard 4, Term 1, etc.)
        // Only active curricula included — future curricula removed until data exists.
        // Always overwrites so all existing installs get the corrected structure.
        update_option( 'knowly_curriculum_subjects', [
            'tt_primary' => [
                'display_name'          => 'T&T Primary (SEA)',
                'level_label'           => 'Standard',
                'period_label'          => 'Term',
                'levels'                => [
                    [ 'value' => 'std_4', 'label' => 'Standard 4', 'is_capstone' => false ],
                    [ 'value' => 'std_5', 'label' => 'Standard 5', 'is_capstone' => true  ],
                ],
                'periods'               => [
                    [ 'value' => 'term_1', 'label' => 'Term 1' ],
                    [ 'value' => 'term_2', 'label' => 'Term 2' ],
                    [ 'value' => 'term_3', 'label' => 'Term 3' ],
                ],
                'standard_difficulties' => [
                    [ 'value' => 'easy',   'label' => 'Easy'   ],
                    [ 'value' => 'medium', 'label' => 'Medium' ],
                    [ 'value' => 'hard',   'label' => 'Hard'   ],
                ],
                'capstone_difficulties' => [
                    [ 'value' => 'sea_paper', 'label' => 'SEA Paper' ],
                ],
                'subjects'              => [
                    [ 'value' => 'math',          'label' => 'Mathematics'        ],
                    [ 'value' => 'english',       'label' => 'English Language Arts' ],
                    [ 'value' => 'science',       'label' => 'Science'            ],
                    [ 'value' => 'social_studies','label' => 'Social Studies'     ],
                ],
            ],
        ] );
    }

    // ── DB Tables ─────────────────────────────────────────────────────────────

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── 1. Children (parent ↔ child relationships) ───────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_children (
            child_row_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id     BIGINT UNSIGNED NOT NULL,
            child_id      BIGINT UNSIGNED NOT NULL,
            display_name  VARCHAR(100)    NOT NULL DEFAULT '',
            level         VARCHAR(20)     NOT NULL DEFAULT '',
            period        VARCHAR(20)     NOT NULL DEFAULT '',
            age           TINYINT UNSIGNED         DEFAULT NULL,
            avatar_index  TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY   (child_row_id),
            UNIQUE KEY    uq_child (child_id),
            KEY           idx_parent (parent_id)
        ) {$charset};" );

        // ── 2. Token Ledger (append-only audit trail) ─────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_token_ledger (
            ledger_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            amount        INT             NOT NULL,
            balance_after INT             NOT NULL,
            type          ENUM('purchase','exam_deduct','registration','monthly_refresh','admin_credit','admin_deduct','refund') NOT NULL,
            reference_id  VARCHAR(100)             DEFAULT NULL,
            note          TEXT                     DEFAULT NULL,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY   (ledger_id),
            KEY           idx_user (user_id),
            KEY           idx_created (created_at)
        ) {$charset};" );

        // ── 3. Exam Pool — removed (Block 1) ─────────────────────────────────
        // WP-side pool retired in Block 1. All exam delivery goes through Railway.
        // Table intentionally not created.

        // ── 4. Exam Sessions ──────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_exam_sessions (
            session_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_session_id VARCHAR(100)    NOT NULL,
            child_id            BIGINT UNSIGNED NOT NULL,
            parent_id           BIGINT UNSIGNED NOT NULL,
            package_id          VARCHAR(100)    NOT NULL DEFAULT '',
            subject             VARCHAR(100)    NOT NULL DEFAULT '',
            level               VARCHAR(20)     NOT NULL DEFAULT '',
            period              VARCHAR(20)     NOT NULL DEFAULT '',
            difficulty          ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
            trial_type          VARCHAR(50)      NOT NULL DEFAULT 'practice',
            source              ENUM('self','teacher_assigned') NOT NULL DEFAULT 'self',
            task_id             BIGINT UNSIGNED           DEFAULT NULL,
            answer_sheet        LONGTEXT                  DEFAULT NULL,
            state               ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
            score               INT UNSIGNED             DEFAULT NULL,
            total               INT UNSIGNED             DEFAULT NULL,
            percentage          DECIMAL(5,2)             DEFAULT NULL,
            time_taken_seconds  INT UNSIGNED             DEFAULT NULL,
            started_at          DATETIME        NOT NULL,
            completed_at        DATETIME                 DEFAULT NULL,
            PRIMARY KEY         (session_id),
            UNIQUE KEY          uq_external (external_session_id),
            KEY                 idx_child (child_id),
            KEY                 idx_parent (parent_id),
            KEY                 idx_state (state),
            KEY                 idx_started (started_at)
        ) {$charset};" );

        // ── 5. Exam Answers ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_exam_answers (
            answer_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id         BIGINT UNSIGNED NOT NULL,
            child_id           BIGINT UNSIGNED NOT NULL,
            question_id        VARCHAR(100)    NOT NULL DEFAULT '',
            topic              VARCHAR(200)    NOT NULL DEFAULT '',
            subtopic           VARCHAR(200)             DEFAULT NULL,
            cognitive_level    ENUM('recall','application','analysis') NOT NULL DEFAULT 'recall',
            selected_answer    VARCHAR(10)              DEFAULT NULL,
            correct_answer     VARCHAR(10)     NOT NULL DEFAULT '',
            is_correct         TINYINT(1)      NOT NULL DEFAULT 0,
            time_taken_seconds INT UNSIGNED             DEFAULT NULL,
            PRIMARY KEY        (answer_id),
            KEY                idx_session (session_id),
            KEY                idx_child (child_id)
        ) {$charset};" );

        // ── 6. Topic Breakdown (per-session aggregate) ────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_topic_breakdown (
            breakdown_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id   BIGINT UNSIGNED NOT NULL,
            child_id     BIGINT UNSIGNED NOT NULL,
            topic        VARCHAR(200)    NOT NULL DEFAULT '',
            correct      INT UNSIGNED    NOT NULL DEFAULT 0,
            total        INT UNSIGNED    NOT NULL DEFAULT 0,
            pct          DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
            PRIMARY KEY  (breakdown_id),
            KEY          idx_session (session_id),
            KEY          idx_child (child_id)
        ) {$charset};" );

        // ── 7. Exam Insights (per-exam AI insight, cached) ────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_exam_insights (
            insight_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id   BIGINT UNSIGNED NOT NULL,
            child_id     BIGINT UNSIGNED NOT NULL,
            insight_text LONGTEXT        NOT NULL,
            model_used   VARCHAR(100)             DEFAULT NULL,
            generated_at DATETIME        NOT NULL,
            PRIMARY KEY  (insight_id),
            UNIQUE KEY   uq_session (session_id),
            KEY          idx_child (child_id)
        ) {$charset};" );

        // ── 8. Weekly Digest Insights ─────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_weekly_insights (
            digest_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id     BIGINT UNSIGNED NOT NULL,
            iso_week     VARCHAR(10)     NOT NULL,
            payload_json LONGTEXT                 DEFAULT NULL,
            insight_text LONGTEXT                 DEFAULT NULL,
            generated_at DATETIME        NOT NULL,
            PRIMARY KEY  (digest_id),
            UNIQUE KEY   uq_child_week (child_id, iso_week),
            KEY          idx_child (child_id)
        ) {$charset};" );

        // ── 9. Notifications ─────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_notifications (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipient_user_id INT UNSIGNED   NOT NULL,
            sender_user_id   INT UNSIGNED             DEFAULT NULL,
            type             ENUM('simple','confirmation') NOT NULL DEFAULT 'simple',
            subject          VARCHAR(100)    NOT NULL DEFAULT '',
            message          TEXT            NOT NULL,
            payload          LONGTEXT                 DEFAULT NULL,
            response         ENUM('accepted','declined') DEFAULT NULL,
            is_read          TINYINT(1)      NOT NULL DEFAULT 0,
            responded_at     DATETIME                 DEFAULT NULL,
            created_at       DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_recipient (recipient_user_id),
            KEY idx_read (recipient_user_id, is_read)
        ) {$charset};" );

        // ── 10. UM Migration Log ──────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_migration_log (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            status     ENUM('success','failed') NOT NULL DEFAULT 'success',
            message    TEXT                     DEFAULT NULL,
            migrated_at DATETIME               NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_status (status)
        ) {$charset};" );

        // ── 11. Gem Transactions (blue gem audit trail) ───────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_gem_transactions (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            child_id     BIGINT UNSIGNED          DEFAULT NULL,
            type         ENUM('purchase','parent_allocation','monthly_refresh','spent','admin_credit','admin_deduct') NOT NULL,
            amount       INT             NOT NULL,
            balance_after INT            NOT NULL,
            curriculum   VARCHAR(50)              DEFAULT NULL,
            reference_id VARCHAR(100)             DEFAULT NULL,
            note         TEXT                     DEFAULT NULL,
            created_at   DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY          idx_user (user_id),
            KEY          idx_child (child_id),
            KEY          idx_created (created_at)
        ) {$charset};" );

        // ── 12. Red Gem Transactions (teacher red gem audit trail) ────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_red_gem_transactions (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_user_id  BIGINT UNSIGNED NOT NULL,
            type             ENUM('stipend_reset','assignment_spent','admin_credit','admin_deduct') NOT NULL,
            amount           INT             NOT NULL,
            balance_after    INT             NOT NULL,
            reference_id     VARCHAR(100)             DEFAULT NULL,
            note             TEXT                     DEFAULT NULL,
            created_at       DATETIME        NOT NULL,
            PRIMARY KEY      (id),
            KEY              idx_teacher (teacher_user_id),
            KEY              idx_created (created_at)
        ) {$charset};" );

        // ── 13. Processed Webhooks (Fygaro idempotency guard) ─────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_processed_webhooks (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id VARCHAR(100)    NOT NULL,
            gateway        VARCHAR(50)     NOT NULL DEFAULT 'fygaro',
            processed_at   DATETIME        NOT NULL,
            PRIMARY KEY    (id),
            UNIQUE KEY     uq_transaction (transaction_id)
        ) {$charset};" );

        // ── 14. Classes ───────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_classes (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_user_id BIGINT UNSIGNED NOT NULL,
            name            VARCHAR(100)    NOT NULL DEFAULT '',
            description     TEXT                     DEFAULT NULL,
            level           VARCHAR(20)     NOT NULL DEFAULT '',
            status          ENUM('active','disbanded') NOT NULL DEFAULT 'active',
            created_at      DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_teacher (teacher_user_id),
            KEY idx_status (status)
        ) {$charset};" );

        // ── 15. Class Members ─────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_class_members (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id   BIGINT UNSIGNED NOT NULL,
            child_id   BIGINT UNSIGNED NOT NULL,
            parent_id  BIGINT UNSIGNED NOT NULL,
            status     ENUM('active','removed') NOT NULL DEFAULT 'active',
            joined_at  DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_child (class_id, child_id),
            KEY idx_class (class_id),
            KEY idx_child (child_id)
        ) {$charset};" );

        // ── 16. Tasks ─────────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_tasks (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id        BIGINT UNSIGNED NOT NULL,
            teacher_user_id BIGINT UNSIGNED NOT NULL,
            type            ENUM('quest','trial','lesson') NOT NULL DEFAULT 'trial',
            reference_id    VARCHAR(100)             DEFAULT NULL,
            title           VARCHAR(200)    NOT NULL DEFAULT '',
            description     TEXT                     DEFAULT NULL,
            subject         VARCHAR(100)             DEFAULT NULL,
            difficulty      ENUM('easy','medium','hard') DEFAULT NULL,
            due_date        DATE                     DEFAULT NULL,
            gem_reward      TINYINT UNSIGNED         DEFAULT NULL,
            red_gem_cost    TINYINT UNSIGNED NOT NULL DEFAULT 1,
            status          ENUM('active','closed')  NOT NULL DEFAULT 'active',
            created_at      DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_class (class_id),
            KEY idx_teacher (teacher_user_id),
            KEY idx_status (status)
        ) {$charset};" );

        // ── 17. Training Material (mirrors Pinecone vectors for admin display) ───
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_training_material (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vector_id    VARCHAR(200)    NOT NULL,
            curriculum   VARCHAR(50)     NOT NULL DEFAULT 'tt_primary',
            level        VARCHAR(20)     NOT NULL DEFAULT '',
            period       VARCHAR(20)              DEFAULT NULL,
            subject      VARCHAR(50)     NOT NULL DEFAULT '',
            topic        VARCHAR(200)    NOT NULL DEFAULT '',
            subtopic     VARCHAR(200)             DEFAULT NULL,
            content_text LONGTEXT        NOT NULL,
            status       ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at   DATETIME        NOT NULL,
            updated_at   DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   uq_vector (vector_id),
            KEY          idx_level (level),
            KEY          idx_subject (subject),
            KEY          idx_status (status)
        ) {$charset};" );

        // ── 18. Quest Store (WP local delivery store — both student + teacher variants) ──
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_quests (
            id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            quest_id         VARCHAR(200)        NOT NULL,
            variant          ENUM('student','teacher') NOT NULL DEFAULT 'student',
            curriculum       VARCHAR(50)         NOT NULL DEFAULT 'tt_primary',
            level            VARCHAR(20)         NOT NULL,
            period           VARCHAR(20)         DEFAULT NULL,
            subject          VARCHAR(50)         NOT NULL,
            topic            VARCHAR(200)        DEFAULT NULL,
            module_number    INT                 DEFAULT NULL,
            module_title     VARCHAR(200)        DEFAULT NULL,
            sort_order       INT                 DEFAULT NULL,
            objectives       LONGTEXT            DEFAULT NULL,
            content          LONGTEXT            DEFAULT NULL,
            audio_url        VARCHAR(500)        DEFAULT NULL,
            audio_generated_at DATETIME          DEFAULT NULL,
            status           VARCHAR(20)         NOT NULL DEFAULT 'pending_review',
            railway_quest_id VARCHAR(200)        DEFAULT NULL,
            generated_at     DATETIME            DEFAULT NULL,
            approved_at      DATETIME            DEFAULT NULL,
            approved_by      BIGINT(20)          DEFAULT NULL,
            created_at       DATETIME            NOT NULL,
            updated_at       DATETIME            NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY quest_variant (quest_id, variant),
            KEY idx_level_period_subject (level, period, subject),
            KEY idx_status (status),
            KEY idx_variant (variant)
        ) {$charset};" );

        // ── 19. Trial Packages (WP local pool — synced from Railway) ─────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_trial_packages (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            package_id   VARCHAR(200)        NOT NULL,
            curriculum   VARCHAR(50)         NOT NULL DEFAULT 'tt_primary',
            level        VARCHAR(20)         NOT NULL,
            period       VARCHAR(20)         DEFAULT NULL,
            subject      VARCHAR(50)         NOT NULL,
            difficulty   VARCHAR(20)         DEFAULT NULL,
            trial_type   VARCHAR(20)         NOT NULL DEFAULT 'practice',
            topic        VARCHAR(200)        DEFAULT NULL,
            questions    LONGTEXT            DEFAULT NULL,
            answer_sheet LONGTEXT            DEFAULT NULL,
            meta         LONGTEXT            DEFAULT NULL,
            status       VARCHAR(20)         NOT NULL DEFAULT 'approved',
            synced_at    DATETIME            NOT NULL,
            created_at   DATETIME            NOT NULL,
            updated_at   DATETIME            NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY package_id (package_id),
            KEY idx_slot (level, period, subject, difficulty, trial_type, status)
        ) {$charset};" );

        // ── 20. Quest Sessions (WP-local — no Railway dependency) ───────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_quest_sessions (
            session_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quest_session_id VARCHAR(64)     NOT NULL,
            child_id         BIGINT UNSIGNED NOT NULL,
            quest_id         VARCHAR(200)    NOT NULL,
            source           ENUM('direct','assignment') NOT NULL DEFAULT 'direct',
            state            ENUM('active','completed')  NOT NULL DEFAULT 'active',
            started_at       DATETIME        NOT NULL,
            completed_at     DATETIME                 DEFAULT NULL,
            PRIMARY KEY      (session_id),
            UNIQUE KEY       uq_quest_session (quest_session_id),
            KEY              idx_child (child_id),
            KEY              idx_quest (quest_id),
            KEY              idx_state (state)
        ) {$charset};" );

        // ── 21. Quest Question Results ────────────────────────────────────────────
        // Stores child answers to quest testing questions (separate from trial scores).
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_quest_question_results (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      VARCHAR(64)     NOT NULL,
            quest_id        VARCHAR(200)    NOT NULL,
            child_id        BIGINT UNSIGNED NOT NULL,
            question_id     VARCHAR(64)     NOT NULL,
            selected_answer CHAR(1)                  DEFAULT NULL,
            is_correct      TINYINT(1)      NOT NULL DEFAULT 0,
            answered_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_session  (session_id),
            KEY idx_child    (child_id),
            KEY idx_quest    (quest_id)
        ) {$charset};" );

        // ── 22. Lesson Sessions ───────────────────────────────────────────────────
        // Tracks lesson sessions (WP-local, no gem cost, no badge).
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_lesson_sessions (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lesson_session_id  VARCHAR(64)     NOT NULL,
            child_id           BIGINT UNSIGNED NOT NULL,
            quest_id           VARCHAR(200)    NOT NULL,
            source             VARCHAR(20)     NOT NULL DEFAULT 'direct',
            state              VARCHAR(20)     NOT NULL DEFAULT 'active',
            started_at         DATETIME        NOT NULL,
            completed_at       DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_lesson_session (lesson_session_id),
            KEY idx_child (child_id),
            KEY idx_quest (quest_id),
            KEY idx_state (state)
        ) {$charset};" );

        // ── 23. Lesson Question Results ───────────────────────────────────────────
        // Stores child answers to lesson comprehension questions. Scored silently —
        // results never returned to the student.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_lesson_question_results (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      VARCHAR(64)     NOT NULL,
            quest_id        VARCHAR(200)    NOT NULL,
            child_id        BIGINT UNSIGNED NOT NULL,
            question_id     VARCHAR(64)     NOT NULL,
            selected_answer CHAR(1)                  DEFAULT NULL,
            is_correct      TINYINT(1)      NOT NULL DEFAULT 0,
            answered_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_child   (child_id),
            KEY idx_quest   (quest_id)
        ) {$charset};" );

        // ── 25. Badge Definitions ─────────────────────────────────────────────────
        // Replaces the knowly_badge CPT. Three trigger types are supported:
        //   quest_module_completion — fires when all sub-topics in a module are done
        //   trial_count            — fires when a child reaches a trial threshold
        //   lesson_count           — fires when a child reaches a lesson threshold
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_badge_definitions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(100)    NOT NULL DEFAULT 'New Badge',
            description   TEXT                     DEFAULT NULL,
            trigger_type  ENUM('quest_module_completion','trial_count','lesson_count') NOT NULL,
            trigger_key   VARCHAR(200)    NOT NULL,
            threshold     INT UNSIGNED             DEFAULT NULL,
            curriculum    VARCHAR(50)     NOT NULL DEFAULT 'tt_primary',
            level         VARCHAR(20)     NOT NULL DEFAULT '',
            period        VARCHAR(20)              DEFAULT NULL,
            subject       VARCHAR(50)     NOT NULL DEFAULT '',
            module_number INT UNSIGNED             DEFAULT NULL,
            ai_generated  TINYINT(1)      NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL,
            updated_at    DATETIME        NOT NULL,
            PRIMARY KEY   (id),
            UNIQUE KEY    uq_trigger (trigger_type, trigger_key),
            KEY           idx_type (trigger_type),
            KEY           idx_subject (subject, level)
        ) {$charset};" );

        // ── 26. Badge Awards ──────────────────────────────────────────────────────
        // One row per child per definition. share_token powers the public /badge/{token} page.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_badge_awards (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            definition_id BIGINT UNSIGNED NOT NULL,
            child_id      BIGINT UNSIGNED NOT NULL,
            share_token   VARCHAR(32)     NOT NULL,
            awarded_at    DATETIME        NOT NULL,
            PRIMARY KEY   (id),
            UNIQUE KEY    uq_child_definition (child_id, definition_id),
            UNIQUE KEY    uq_share (share_token),
            KEY           idx_child (child_id),
            KEY           idx_definition (definition_id)
        ) {$charset};" );

        // ── 24. Debug Log ─────────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_debug_log (
            log_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level      ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
            context    VARCHAR(100)    NOT NULL DEFAULT '',
            message    TEXT            NOT NULL,
            data       LONGTEXT                 DEFAULT NULL,
            user_id    BIGINT UNSIGNED          DEFAULT NULL,
            request_id VARCHAR(20)              DEFAULT NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (log_id),
            KEY         idx_level (level),
            KEY         idx_context (context),
            KEY         idx_created (created_at)
        ) {$charset};" );

        // ── 25. Lottie Library ────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}knowly_lottie (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL DEFAULT '',
            file_url    TEXT            NOT NULL,
            file_path   TEXT            NOT NULL,
            file_size   INT UNSIGNED    NOT NULL DEFAULT 0,
            uploaded_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY         idx_uploaded (uploaded_at)
        ) {$charset};" );
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    private static function register_roles(): void {
        // Parent — account holder, billing user
        if ( ! get_role( 'knowly_parent' ) ) {
            add_role( 'knowly_parent', 'Knowly Parent', [
                'read'      => true,
                'edit_posts' => false,
            ] );
        }

        // Child — learner profile, no admin access
        if ( ! get_role( 'knowly_child' ) ) {
            add_role( 'knowly_child', 'Knowly Student', [
                'read' => true,
            ] );
        }

        // Teacher — class management and analytics access, requires admin approval
        if ( ! get_role( 'knowly_teacher' ) ) {
            add_role( 'knowly_teacher', 'Knowly Teacher', [
                'read' => true,
            ] );
        }
    }

    // ── Default Options ───────────────────────────────────────────────────────

    private static function set_defaults(): void {
        $defaults = [
            'knowly_debug_enabled'         => false,
            'knowly_railway_endpoint'      => '',
            'knowly_railway_api_key'       => '',
            'knowly_railway_server_key'    => '',
            'knowly_allowed_origins'       => '',
            'knowly_content_source'        => 'pool_only', // pool_only | railway | both
            'knowly_pool_default_target'   => 10,
            // Block 2
            'knowly_max_children'          => 3,
            'knowly_email_verification'    => false,
            'knowly_red_gem_stipend'       => 20,
            'knowly_um_migration_status'   => 'pending', // pending | complete
            // Block 3 — Fygaro gateway
            'knowly_fygaro_merchant_id'    => '',
            'knowly_fygaro_api_key'        => '',
            'knowly_fygaro_webhook_secret' => '',
            // Block 5 — Classes
            'knowly_task_gem_cost'         => 1,
            // Block 6 — Quests
            'knowly_gem_cost_quest_first_tt_primary'  => 3,
            'knowly_gem_cost_quest_retake_tt_primary' => 1,
            // Block 8 — Sign-off gate
            'knowly_signoff_status'                   => 'blocked',
            // Curriculum subject registry — mirrors Railway taxonomy.js CURRICULUM_CONFIG
            // Each entry: { display_name, levels, periods, standard_difficulties, capstone_difficulties, subjects }
            'knowly_curriculum_subjects'              => [
                'tt_primary' => [
                    'display_name'          => 'T&T Primary (SEA)',
                    'level_label'           => 'Standard',
                    'period_label'          => 'Term',
                    'levels'                => [
                        [ 'value' => 'std_4', 'label' => 'Standard 4', 'is_capstone' => false ],
                        [ 'value' => 'std_5', 'label' => 'Standard 5', 'is_capstone' => true  ],
                    ],
                    'periods'               => [
                        [ 'value' => 'term_1', 'label' => 'Term 1' ],
                        [ 'value' => 'term_2', 'label' => 'Term 2' ],
                        [ 'value' => 'term_3', 'label' => 'Term 3' ],
                    ],
                    'standard_difficulties' => [
                        [ 'value' => 'easy',   'label' => 'Easy'   ],
                        [ 'value' => 'medium', 'label' => 'Medium' ],
                        [ 'value' => 'hard',   'label' => 'Hard'   ],
                    ],
                    'capstone_difficulties' => [
                        [ 'value' => 'sea_paper', 'label' => 'SEA Paper' ],
                    ],
                    'subjects'              => [
                        [ 'value' => 'math',          'label' => 'Mathematics'           ],
                        [ 'value' => 'english',       'label' => 'English Language Arts' ],
                        [ 'value' => 'science',       'label' => 'Science'               ],
                        [ 'value' => 'social_studies','label' => 'Social Studies'        ],
                    ],
                ],
            ],
        ];

        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
    }

    // ── Cron Scheduling ───────────────────────────────────────────────────────

    private static function schedule_crons(): void {
        // Weekly digest — every Monday at 06:00 UTC
        if ( ! wp_next_scheduled( 'knowly_weekly_digest' ) ) {
            // Find next Monday
            $now        = time();
            $days_until = ( 1 - (int) date( 'N', $now ) + 7 ) % 7;
            $next_mon   = strtotime( "+{$days_until} days", mktime( 6, 0, 0, (int) date( 'n', $now ), (int) date( 'j', $now ), (int) date( 'Y', $now ) ) );
            wp_schedule_event( $next_mon, 'weekly', 'knowly_weekly_digest' );
        }

        // Monthly blue gem refresh — 1st of month at 00:10 UTC (after token refresh)
        if ( ! wp_next_scheduled( 'knowly_monthly_gem_refresh' ) ) {
            $first_of_month = mktime( 0, 10, 0, (int) date( 'n' ) + 1, 1, (int) date( 'Y' ) );
            wp_schedule_event( $first_of_month, 'monthly', 'knowly_monthly_gem_refresh' );
        }

        // Monthly red gem stipend reset — 1st of month at 00:15 UTC
        if ( ! wp_next_scheduled( 'knowly_monthly_red_gem_stipend' ) ) {
            $first_of_month = mktime( 0, 15, 0, (int) date( 'n' ) + 1, 1, (int) date( 'Y' ) );
            wp_schedule_event( $first_of_month, 'monthly', 'knowly_monthly_red_gem_stipend' );
        }
    }
}
