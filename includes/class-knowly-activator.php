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
            update_option( 'knowly_db_version', KNOWLY_DB_VERSION );
        }
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
            type            ENUM('quest','trial') NOT NULL DEFAULT 'trial',
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

        // ── 17. Debug Log ─────────────────────────────────────────────────────────
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
