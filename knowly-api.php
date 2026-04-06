<?php
/**
 * Plugin Name:  KnowlyAPI
 * Plugin URI:   https://knowly.app
 * Description:  Unified REST API for the Knowly learning platform. React / Next.js interface only — no WP front-end output.
 * Version:      1.1.0
 * Author:       Knowly
 * Requires PHP: 8.0
 * Requires at least: 6.3
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'KNOWLY_VERSION',            '1.2.0' );
define( 'KNOWLY_DB_VERSION',         '1.2' );
define( 'KNOWLY_PLUGIN_FILE',        __FILE__ );
define( 'KNOWLY_PLUGIN_DIR',         plugin_dir_path( __FILE__ ) );
define( 'KNOWLY_PLUGIN_URL',         plugin_dir_url( __FILE__ ) );
define( 'KNOWLY_REST_NAMESPACE',     'knowly/v1' );

// Children
define( 'KNOWLY_MAX_CHILDREN',       3 );

// Tokens
define( 'KNOWLY_FREE_TOKEN_GRANT',   3 );   // on registration
define( 'KNOWLY_FREE_TOKEN_MONTHLY', 3 );   // free-tier monthly reset

// PIN
define( 'KNOWLY_PIN_MAX_ATTEMPTS',   5 );
define( 'KNOWLY_PIN_LOCKOUT',        900 ); // seconds (15 min)

// JWT
define( 'KNOWLY_JWT_EXPIRY',         604800 ); // 7 days

// Debug
define( 'KNOWLY_DEBUG_MAX_LOGS',     2000 );

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register( static function ( string $class ): void {
    $map = [
        // Core
        'Knowly_Activator'        => 'includes/class-knowly-activator.php',
        'Knowly_Core'             => 'includes/class-knowly-core.php',
        'Knowly_WooCommerce'      => 'includes/class-knowly-woocommerce.php',
        // Debug
        'Knowly_Debug'            => 'includes/debug/class-knowly-debug.php',
        // Auth
        'Knowly_JWT'              => 'includes/auth/class-knowly-jwt.php',
        // API layer
        'Knowly_API_Base'         => 'includes/api/class-knowly-api-base.php',
        'Knowly_Auth_API'         => 'includes/api/class-knowly-auth-api.php',
        'Knowly_Children_API'     => 'includes/api/class-knowly-children-api.php',
        'Knowly_Tokens_API'       => 'includes/api/class-knowly-tokens-api.php',
        'Knowly_Exams_API'        => 'includes/api/class-knowly-exams-api.php',
        'Knowly_Results_API'      => 'includes/api/class-knowly-results-api.php',
        'Knowly_Insights_API'     => 'includes/api/class-knowly-insights-api.php',
        // Services
        'Knowly_Auth_Service'     => 'includes/services/class-knowly-auth-service.php',
        'Knowly_Children_Service' => 'includes/services/class-knowly-children-service.php',
        'Knowly_Token_Service'    => 'includes/services/class-knowly-token-service.php',
        'Knowly_Exam_Service'     => 'includes/services/class-knowly-exam-service.php',
        'Knowly_Results_Service'  => 'includes/services/class-knowly-results-service.php',
        'Knowly_Insight_Service'  => 'includes/services/class-knowly-insight-service.php',
        // Cron
        'Knowly_Cron'             => 'includes/cron/class-knowly-cron.php',
        // Admin
        'Knowly_Admin'            => 'includes/admin/class-knowly-admin.php',
        'Knowly_Admin_Settings'   => 'includes/admin/class-knowly-admin-settings.php',
        'Knowly_Admin_Debug'      => 'includes/admin/class-knowly-admin-debug.php',
        'Knowly_Admin_Testing'    => 'includes/admin/class-knowly-admin-testing.php',
        'Knowly_Admin_Pool'       => 'includes/admin/class-knowly-admin-pool.php',
        'Knowly_Admin_Members'    => 'includes/admin/class-knowly-admin-members.php',
        'Knowly_Admin_Tokens'     => 'includes/admin/class-knowly-admin-tokens.php',

        // Leaderboard
        'Knowly_Leaderboard_API'        => 'includes/api/class-knowly-leaderboard-api.php',
        'Knowly_Leaderboard_Service'    => 'includes/services/class-knowly-leaderboard-service.php',
        'Knowly_Admin_Leaderboard'      => 'includes/admin/class-knowly-admin-leaderboard.php',
        // Block 2 — Teacher + Notifications
        'Knowly_Teacher_Service'        => 'includes/services/class-knowly-teacher-service.php',
        'Knowly_Notification_Service'   => 'includes/services/class-knowly-notification-service.php',
        'Knowly_Admin_Teachers'         => 'includes/admin/class-knowly-admin-teachers.php',
        'Knowly_Admin_Migration'        => 'includes/admin/class-knowly-admin-migration.php',
    ];

    if ( isset( $map[ $class ] ) ) {
        require_once KNOWLY_PLUGIN_DIR . $map[ $class ];
    }
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'Knowly_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Knowly_Activator', 'deactivate' ] );

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', [ 'Knowly_Core', 'boot' ], 10 );
