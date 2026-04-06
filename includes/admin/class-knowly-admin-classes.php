<?php
/**
 * Knowly_Admin_Classes — WP Admin page for class management overview.
 *
 * Displays:
 *   - Summary counts (total classes, members, tasks)
 *   - Table of all classes with teacher name, member count, task count, date
 *   - Settings row for knowly_task_gem_cost
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Classes {

    public static function boot(): void {
        add_action( 'admin_post_knowly_save_class_settings', [ __CLASS__, 'handle_save_settings' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        global $wpdb;

        $class_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes" );
        $member_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members WHERE status = 'active'" );
        $task_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks" );

        $classes = $wpdb->get_results(
            "SELECT c.*, u.display_name AS teacher_display_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members m WHERE m.class_id = c.id AND m.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks t WHERE t.class_id = c.id) AS task_count
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.teacher_user_id
             ORDER BY c.created_at DESC
             LIMIT 200"
        );

        $task_gem_cost = (int) get_option( 'knowly_task_gem_cost', 1 );
        ?>
        <div class="wrap knowly-wrap">
            <h1>Classes</h1>

            <!-- Settings -->
            <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin-bottom:24px;">
                <?php wp_nonce_field( 'knowly_class_settings' ); ?>
                <input type="hidden" name="action" value="knowly_save_class_settings" />
                <table class="form-table" style="max-width:600px;">
                    <tr>
                        <th><label for="knowly_task_gem_cost">Red gems per task creation</label></th>
                        <td>
                            <input type="number" id="knowly_task_gem_cost" name="knowly_task_gem_cost"
                                   value="<?= esc_attr( $task_gem_cost ) ?>" min="0" max="99" class="small-text" />
                            <p class="description">Red gems deducted from the teacher's wallet each time a task is created. Default: 1.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
            </form>

            <!-- Summary -->
            <div class="knowly-stat-grid" style="margin-bottom:24px;">
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div>
                    <div class="knowly-stat-label">Total Classes</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $member_count ) ?></div>
                    <div class="knowly-stat-label">Active Memberships</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $task_count ) ?></div>
                    <div class="knowly-stat-label">Tasks Created</div>
                </div>
            </div>

            <!-- Class Table -->
            <h2>All Classes</h2>
            <?php if ( empty( $classes ) ) : ?>
                <p>No classes created yet.</p>
            <?php else : ?>
            <table class="knowly-table widefat">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Class Name</th>
                        <th>Teacher</th>
                        <th>Level</th>
                        <th>Members</th>
                        <th>Tasks</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $classes as $cls ) : ?>
                    <tr>
                        <td><?= esc_html( $cls->id ) ?></td>
                        <td><?= esc_html( $cls->name ) ?></td>
                        <td><?= esc_html( $cls->teacher_display_name ?: "User #{$cls->teacher_user_id}" ) ?></td>
                        <td><?= esc_html( $cls->level ?: '—' ) ?></td>
                        <td><?= esc_html( $cls->member_count ) ?></td>
                        <td><?= esc_html( $cls->task_count ) ?></td>
                        <td><?= esc_html( wp_date( 'Y-m-d', strtotime( $cls->created_at ) ) ) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Save Settings ─────────────────────────────────────────────────────────

    public static function handle_save_settings(): void {
        check_admin_referer( 'knowly_class_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $cost = max( 0, (int) ( $_POST['knowly_task_gem_cost'] ?? 1 ) );
        update_option( 'knowly_task_gem_cost', $cost );

        wp_safe_redirect( add_query_arg( [ 'page' => 'knowly-classes', 'updated' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
