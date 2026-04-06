<?php
/**
 * Knowly_Admin_Teachers — Teacher management admin panel.
 *
 * Features:
 *  - List all pending teacher applications
 *  - List all approved and suspended teachers
 *  - Approve / suspend teacher accounts
 *  - View teacher profile details (school, class, principal info)
 *  - Adjust red gem balance
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Teachers {

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_teacher_approve',      [ __CLASS__, 'ajax_approve' ] );
        add_action( 'wp_ajax_knowly_teacher_suspend',      [ __CLASS__, 'ajax_suspend' ] );
        add_action( 'wp_ajax_knowly_teacher_adjust_gems',  [ __CLASS__, 'ajax_adjust_gems' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $all_teachers = Knowly_Teacher_Service::list_teachers();
        $pending      = array_filter( $all_teachers, fn( $t ) => $t['approval_status'] === 'pending_approval' );
        $approved     = array_filter( $all_teachers, fn( $t ) => $t['approval_status'] === 'approved' );
        $suspended    = array_filter( $all_teachers, fn( $t ) => $t['approval_status'] === 'suspended' );
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI — Teachers</h1>

            <!-- Stats -->
            <div class="knowly-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number" style="color:#d63638;"><?= count( $pending ) ?></div>
                    <div class="knowly-stat-label">Pending Approval</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number" style="color:#00a32a;"><?= count( $approved ) ?></div>
                    <div class="knowly-stat-label">Approved</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number" style="color:#dba617;"><?= count( $suspended ) ?></div>
                    <div class="knowly-stat-label">Suspended</div>
                </div>
            </div>

            <!-- Pending Applications -->
            <?php if ( ! empty( $pending ) ) : ?>
            <h2 style="margin-top:0;">⏳ Pending Applications</h2>
            <table class="wp-list-table widefat fixed striped" style="margin-bottom:32px;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>School</th>
                        <th>Class</th>
                        <th>Phone</th>
                        <th>Applied</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $pending as $teacher ) : ?>
                    <tr id="teacher-row-<?= (int) $teacher['user_id'] ?>">
                        <td><strong><?= esc_html( $teacher['display_name'] ) ?></strong></td>
                        <td><?= esc_html( $teacher['email'] ) ?></td>
                        <td><?= esc_html( $teacher['school_name'] ) ?></td>
                        <td><?= esc_html( $teacher['class_name'] ) ?></td>
                        <td><?= esc_html( $teacher['phone'] ) ?></td>
                        <td><?= esc_html( date( 'M j, Y', strtotime( $teacher['registered'] ) ) ) ?></td>
                        <td>
                            <button class="button button-primary knowly-teacher-approve"
                                    data-id="<?= (int) $teacher['user_id'] ?>">Approve</button>
                            <button class="button knowly-teacher-suspend" style="margin-left:4px;"
                                    data-id="<?= (int) $teacher['user_id'] ?>">Suspend</button>
                            <span class="knowly-teacher-action-result" data-id="<?= (int) $teacher['user_id'] ?>" style="margin-left:8px;font-size:12px;"></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;border-radius:4px;margin-bottom:32px;color:#666;">
                No pending teacher applications.
            </div>
            <?php endif; ?>

            <!-- Approved Teachers -->
            <h2>✅ Approved Teachers</h2>
            <?php if ( ! empty( $approved ) ) : ?>
            <table class="wp-list-table widefat fixed striped" style="margin-bottom:32px;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>School</th>
                        <th>Red Gems</th>
                        <th>Stipend</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $approved as $teacher ) : ?>
                    <tr id="teacher-row-<?= (int) $teacher['user_id'] ?>">
                        <td><strong><?= esc_html( $teacher['display_name'] ) ?></strong></td>
                        <td><?= esc_html( $teacher['email'] ) ?></td>
                        <td><?= esc_html( $teacher['school_name'] ) ?></td>
                        <td>
                            <input type="number" class="small-text knowly-teacher-gem-input"
                                   data-id="<?= (int) $teacher['user_id'] ?>"
                                   value="<?= (int) $teacher['red_gem_balance'] ?>" min="0" style="width:70px;" />
                        </td>
                        <td><?= (int) $teacher['red_gem_stipend'] ?></td>
                        <td>
                            <button class="button knowly-teacher-save-gems" data-id="<?= (int) $teacher['user_id'] ?>">Save Gems</button>
                            <button class="button knowly-teacher-suspend" style="margin-left:4px;color:#d63638;"
                                    data-id="<?= (int) $teacher['user_id'] ?>">Suspend</button>
                            <span class="knowly-teacher-action-result" data-id="<?= (int) $teacher['user_id'] ?>" style="margin-left:8px;font-size:12px;"></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;border-radius:4px;margin-bottom:32px;color:#666;">
                No approved teachers yet.
            </div>
            <?php endif; ?>

            <!-- Suspended Teachers -->
            <?php if ( ! empty( $suspended ) ) : ?>
            <h2>⛔ Suspended Teachers</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>School</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $suspended as $teacher ) : ?>
                    <tr id="teacher-row-<?= (int) $teacher['user_id'] ?>">
                        <td><strong><?= esc_html( $teacher['display_name'] ) ?></strong></td>
                        <td><?= esc_html( $teacher['email'] ) ?></td>
                        <td><?= esc_html( $teacher['school_name'] ) ?></td>
                        <td>
                            <button class="button button-primary knowly-teacher-approve"
                                    data-id="<?= (int) $teacher['user_id'] ?>">Re-Approve</button>
                            <span class="knowly-teacher-action-result" data-id="<?= (int) $teacher['user_id'] ?>" style="margin-left:8px;font-size:12px;"></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            const nonce = '<?= wp_create_nonce( 'knowly_teacher_action' ) ?>';

            function setResult(id, msg, ok) {
                const $el = $('.knowly-teacher-action-result[data-id="' + id + '"]');
                $el.text(msg).css('color', ok ? '#00a32a' : '#d63638');
                setTimeout(() => $el.text(''), 4000);
            }

            $(document).on('click', '.knowly-teacher-approve', function() {
                const id = $(this).data('id');
                $.post(ajaxurl, { action: 'knowly_teacher_approve', teacher_id: id, nonce }, function(res) {
                    if (res.success) {
                        setResult(id, '✓ Approved', true);
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        setResult(id, res.data || 'Error', false);
                    }
                });
            });

            $(document).on('click', '.knowly-teacher-suspend', function() {
                if (!confirm('Suspend this teacher? They will lose access immediately.')) return;
                const id = $(this).data('id');
                $.post(ajaxurl, { action: 'knowly_teacher_suspend', teacher_id: id, nonce }, function(res) {
                    if (res.success) {
                        setResult(id, '✓ Suspended', true);
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        setResult(id, res.data || 'Error', false);
                    }
                });
            });

            $(document).on('click', '.knowly-teacher-save-gems', function() {
                const id    = $(this).data('id');
                const gems  = parseInt($('.knowly-teacher-gem-input[data-id="' + id + '"]').val(), 10);
                $.post(ajaxurl, { action: 'knowly_teacher_adjust_gems', teacher_id: id, balance: gems, nonce }, function(res) {
                    if (res.success) {
                        setResult(id, '✓ Saved', true);
                    } else {
                        setResult(id, res.data || 'Error', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

    public static function ajax_approve(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        $result     = Knowly_Teacher_Service::approve( $teacher_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success();
    }

    public static function ajax_suspend(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        $result     = Knowly_Teacher_Service::suspend( $teacher_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success();
    }

    public static function ajax_adjust_gems(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        $balance    = max( 0, (int) ( $_POST['balance'] ?? 0 ) );

        if ( ! $teacher_id ) {
            wp_send_json_error( 'Invalid teacher ID.' );
        }

        update_user_meta( $teacher_id, 'knowly_red_gem_balance', $balance );
        wp_send_json_success( [ 'balance' => $balance ] );
    }
}
