<?php
/**
 * Knowly_Admin_Analytics — Analytics admin page.
 *
 * Displays a summary of class and student analytics pulled from Railway.
 * Teachers access per-class data via the API endpoints; this page gives
 * admins a system-wide overview from WordPress data.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Analytics {

    public static function boot(): void {
        // No AJAX hooks needed — page is read-only.
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        global $wpdb;

        $class_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes WHERE status = 'active'" );
        $member_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members WHERE status = 'active'" );
        $task_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks WHERE status = 'active'" );

        // Top 5 classes by member count
        $top_classes = $wpdb->get_results(
            "SELECT c.id, c.name, c.level, COUNT(m.id) AS member_count
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->prefix}knowly_class_members m ON m.class_id = c.id AND m.status = 'active'
             WHERE c.status = 'active'
             GROUP BY c.id
             ORDER BY member_count DESC
             LIMIT 10"
        );

        $railway_endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );

        ?>
        <div class="wrap knowly-wrap">
            <h1>Analytics</h1>
            <p style="color:#666;margin-bottom:20px;">
                System-wide class stats (WordPress data). Per-class and per-student analytics are served via
                <code>GET /knowly/v1/analytics/class/{id}</code> — consumed directly by the React teacher dashboard.
            </p>

            <div class="knowly-stat-grid">
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div>
                    <div class="knowly-stat-label">Active Classes</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $member_count ) ?></div>
                    <div class="knowly-stat-label">Enrolled Students</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $task_count ) ?></div>
                    <div class="knowly-stat-label">Active Tasks</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= $railway_endpoint ? '<span style="color:#00a32a">●</span>' : '<span style="color:#d63638">●</span>' ?></div>
                    <div class="knowly-stat-label">Railway <?= $railway_endpoint ? 'Configured' : 'Not Configured' ?></div>
                </div>
            </div>

            <h2 style="margin-top:24px;">Classes</h2>
            <?php if ( empty( $top_classes ) ) : ?>
                <p>No active classes yet.</p>
            <?php else : ?>
            <table class="knowly-table widefat">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Level</th>
                        <th>Members</th>
                        <th>Analytics API</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $top_classes as $class ) : ?>
                    <tr>
                        <td><?= esc_html( $class->id ) ?></td>
                        <td><?= esc_html( $class->name ) ?></td>
                        <td><?= esc_html( $class->level ) ?></td>
                        <td><?= esc_html( $class->member_count ) ?></td>
                        <td>
                            <code style="font-size:11px;">/knowly/v1/analytics/class/<?= esc_html( $class->id ) ?></code>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h2 style="margin-top:32px;">API Endpoints</h2>
            <table class="knowly-table widefat">
                <thead><tr><th>Method</th><th>Route</th><th>Auth</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><span class="knowly-method get">GET</span></td>
                        <td><code>/knowly/v1/analytics/class/{class_id}</code></td>
                        <td>JWT Teacher</td>
                        <td>Class aggregate: trial counts, avg score, source breakdown, per-student summary</td>
                    </tr>
                    <tr>
                        <td><span class="knowly-method get">GET</span></td>
                        <td><code>/knowly/v1/analytics/class/{class_id}/student/{user_id}</code></td>
                        <td>JWT Teacher</td>
                        <td>Per-student drill-down: subject breakdown, recent trials, quests, badges</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
