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
        add_action( 'wp_ajax_knowly_class_add_member',       [ __CLASS__, 'ajax_add_member' ] );
        add_action( 'wp_ajax_knowly_class_remove_member',    [ __CLASS__, 'ajax_remove_member' ] );
        add_action( 'wp_ajax_knowly_class_close_task',       [ __CLASS__, 'ajax_close_task' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $tab      = sanitize_key( $_GET['tab'] ?? 'overview' );
        $class_id = (int) ( $_GET['class_id'] ?? 0 );
        $tabs = [ 'overview' => 'Overview', 'settings' => 'Settings', 'tests' => 'Unit Tests' ];
        // Don't show 'detail' as a nav tab — it's only accessed via View link
        ?>
        <div class="wrap knowly-wrap">
            <h1>Classes<?php if ( $tab === 'detail' && $class_id ) : ?> — <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-classes' ) ) ?>" style="font-size:14px;font-weight:400;text-decoration:none;">← Back</a><?php endif; ?></h1>
            <?php if ( $tab !== 'detail' ) : ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-classes&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <div style="background:#fff;border:1px solid #c3c4c7;<?= $tab !== 'detail' ? 'border-top:none;' : '' ?>padding:20px;">
            <?php
            if ( $tab === 'detail' && $class_id ) {
                self::render_detail( $class_id );
            } else {
                match ( $tab ) {
                    'overview' => self::render_overview(),
                    'settings' => self::render_settings(),
                    'tests'    => self::render_tests(),
                    default    => self::render_overview(),
                };
            }
            ?>
            </div>
        </div>
        <?php
    }

    private static function render_overview(): void {
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
             ORDER BY c.created_at DESC LIMIT 200"
        );
        ?>
        <div class="knowly-stat-grid" style="margin-bottom:20px;">
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div><div class="knowly-stat-label">Total Classes</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $member_count ) ?></div><div class="knowly-stat-label">Active Memberships</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $task_count ) ?></div><div class="knowly-stat-label">Tasks Created</div></div>
        </div>
        <?php if ( empty( $classes ) ) : ?>
            <p style="color:#888;">No classes created yet.</p>
        <?php else : ?>
        <table class="knowly-table widefat">
            <thead><tr><th>ID</th><th>Class Name</th><th>Teacher</th><th>Level</th><th>Status</th><th>Members</th><th>Tasks</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $classes as $cls ) : ?>
            <tr>
                <td><?= esc_html( $cls->id ) ?></td>
                <td><strong><?= esc_html( $cls->name ) ?></strong></td>
                <td><?= esc_html( $cls->teacher_display_name ?: "User #{$cls->teacher_user_id}" ) ?></td>
                <td><?= esc_html( $cls->level ?: '—' ) ?></td>
                <td><?= esc_html( $cls->status ?? 'active' ) ?></td>
                <td><?= esc_html( $cls->member_count ) ?></td>
                <td><?= esc_html( $cls->task_count ) ?></td>
                <td><?= esc_html( wp_date( 'Y-m-d', strtotime( $cls->created_at ) ) ) ?></td>
                <td>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-classes&tab=detail&class_id=' . (int) $cls->id ) ) ?>"
                       class="button button-small">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    private static function render_settings(): void {
        $task_gem_cost = (int) get_option( 'knowly_task_gem_cost', 1 );
        ?>
        <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>">
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
        <?php
    }

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test class creation, invitations, task assignment, membership, and leaderboard integration.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'block5_classes' ] );
    }

    // ── Class Detail ──────────────────────────────────────────────────────────

    private static function render_detail( int $class_id ): void {
        global $wpdb;

        $cls = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.*, u.display_name AS teacher_display_name
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.teacher_user_id
             WHERE c.id = %d",
            $class_id
        ) );

        if ( ! $cls ) {
            echo '<p style="color:#dc2626;">Class not found.</p>';
            return;
        }

        $members = Knowly_Class_Service::get_members( $class_id );
        $tasks   = Knowly_Task_Service::list_for_class( $class_id, false );
        $nonce   = wp_create_nonce( 'knowly_class_admin' );
        ?>
        <div style="margin-bottom:20px;">
            <h2 style="margin:0 0 4px;"><?= esc_html( $cls->name ) ?></h2>
            <div style="font-size:13px;color:#666;">
                Teacher: <strong><?= esc_html( $cls->teacher_display_name ?: "User #{$cls->teacher_user_id}" ) ?></strong>
                <?php if ( $cls->level ) : ?> · Level: <strong><?= esc_html( $cls->level ) ?></strong><?php endif; ?>
                · Status: <strong><?= esc_html( $cls->status ) ?></strong>
                · Created: <strong><?= esc_html( wp_date( 'Y-m-d', strtotime( $cls->created_at ) ) ) ?></strong>
            </div>
            <?php if ( $cls->description ) : ?>
            <p style="margin-top:6px;color:#555;"><?= esc_html( $cls->description ) ?></p>
            <?php endif; ?>
        </div>

        <!-- Members section -->
        <div style="margin-bottom:24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <h3 style="margin:0;">Members (<?= count( $members ) ?>)</h3>
            </div>

            <!-- Add member by search -->
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
                <input type="text" id="knowly-member-search-input"
                    placeholder="Search by first/last name or nickname…"
                    class="regular-text" style="height:30px;max-width:300px;">
                <button class="button" onclick="knowlyClassDetail.searchAndAdd(<?= $class_id ?>, '<?= esc_js( $nonce ) ?>')">
                    + Add Member
                </button>
                <span id="knowly-search-status" style="font-size:12px;color:#888;"></span>
            </div>

            <?php if ( empty( $members ) ) : ?>
            <p style="color:#888;">No members yet.</p>
            <?php else : ?>
            <table class="knowly-table widefat" id="knowly-members-table">
                <thead>
                    <tr><th>Child</th><th>Nickname</th><th>Parent</th><th>Level</th><th>Gems</th><th>Joined</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ( $members as $member ) :
                    $child_user = get_userdata( $member['child_id'] );
                    $nickname   = get_user_meta( $member['child_id'], 'knowly_nickname', true );
                    $level      = get_user_meta( $member['child_id'], 'knowly_level', true );
                    $gems       = Knowly_Gem_Service::get_balance( $member['child_id'] );
                    $parent_user = $member['parent_id'] ? get_userdata( $member['parent_id'] ) : null;
                ?>
                <tr id="member-row-<?= (int) $member['child_id'] ?>">
                    <td><strong>#<?= esc_html( $member['child_id'] ) ?> <?= esc_html( $member['display_name'] ) ?></strong></td>
                    <td><?= $nickname ? esc_html( $nickname ) : '—' ?></td>
                    <td><?= $parent_user ? esc_html( $parent_user->display_name ) : '—' ?></td>
                    <td><?= esc_html( strtoupper( $level ?: '—' ) ) ?></td>
                    <td><?= esc_html( $gems ) ?> 💎</td>
                    <td style="color:#888;"><?= esc_html( substr( $member['joined_at'], 0, 10 ) ) ?></td>
                    <td>
                        <button class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                            onclick="knowlyClassDetail.removeMember(<?= $class_id ?>, <?= (int) $member['child_id'] ?>, '<?= esc_js( $member['display_name'] ) ?>', '<?= esc_js( $nonce ) ?>')">
                            Remove
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Tasks section -->
        <div>
            <h3 style="margin-bottom:10px;">Tasks (<?= count( $tasks ) ?>)</h3>
            <?php if ( empty( $tasks ) ) : ?>
            <p style="color:#888;">No tasks created yet.</p>
            <?php else : ?>
            <table class="knowly-table widefat">
                <thead>
                    <tr><th>Title</th><th>Type</th><th>Subject</th><th>Difficulty</th><th>Due</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ( $tasks as $task ) : ?>
                <tr id="task-row-<?= (int) $task['id'] ?>">
                    <td><strong><?= esc_html( $task['title'] ) ?></strong></td>
                    <td><?= esc_html( ucfirst( $task['type'] ) ) ?></td>
                    <td><?= esc_html( $task['subject'] ?: '—' ) ?></td>
                    <td><?= esc_html( ucfirst( $task['difficulty'] ?: '—' ) ) ?></td>
                    <td style="color:#888;"><?= esc_html( $task['due_date'] ?: '—' ) ?></td>
                    <td>
                        <span class="knowly-badge <?= $task['status'] === 'active' ? 'ok' : '' ?>" style="font-size:11px;">
                            <?= esc_html( $task['status'] ) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ( $task['status'] === 'active' ) : ?>
                        <button class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                            onclick="knowlyClassDetail.closeTask(<?= (int) $task['id'] ?>, '<?= esc_js( $nonce ) ?>')">
                            Close
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script>
        const knowlyClassDetail = {
            searchAndAdd(classId, nonce) {
                const q = document.getElementById('knowly-member-search-input').value.trim();
                if (!q) { alert('Enter a name or nickname to search.'); return; }
                const status = document.getElementById('knowly-search-status');
                status.textContent = 'Searching…';
                jQuery.post(ajaxurl, {
                    action: 'knowly_class_add_member',
                    nonce: nonce,
                    class_id: classId,
                    search: q,
                }, r => {
                    if (r.success) {
                        status.textContent = r.data.message || 'Member added.';
                        setTimeout(() => location.reload(), 800);
                    } else {
                        status.textContent = r.data?.message || 'Not found or already a member.';
                        status.style.color = '#dc2626';
                    }
                });
            },
            removeMember(classId, childId, name, nonce) {
                if (!confirm('Remove ' + name + ' from this class?')) return;
                jQuery.post(ajaxurl, {
                    action: 'knowly_class_remove_member',
                    nonce: nonce,
                    class_id: classId,
                    child_id: childId,
                }, r => {
                    if (r.success) {
                        const row = document.getElementById('member-row-' + childId);
                        if (row) row.remove();
                    } else {
                        alert(r.data?.message || 'Error removing member.');
                    }
                });
            },
            closeTask(taskId, nonce) {
                if (!confirm('Close this task?')) return;
                jQuery.post(ajaxurl, {
                    action: 'knowly_class_close_task',
                    nonce: nonce,
                    task_id: taskId,
                }, r => {
                    if (r.success) {
                        const row = document.getElementById('task-row-' + taskId);
                        if (row) {
                            row.querySelector('.knowly-badge').textContent = 'closed';
                            row.querySelector('.knowly-badge').classList.remove('ok');
                            const closeBtn = row.querySelector('button');
                            if (closeBtn) closeBtn.remove();
                        }
                    } else {
                        alert(r.data?.message || 'Error.');
                    }
                });
            },
        };
        </script>
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

    // ── AJAX: Add member by name/nickname search ──────────────────────────────

    public static function ajax_add_member(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );

        if ( ! $class_id || ! $search ) {
            wp_send_json_error( [ 'message' => 'Class ID and search term are required.' ] );
        }

        // Search children by display_name (first/last) or nickname
        $users = get_users( [
            'role'       => 'knowly_child',
            'search'     => '*' . $search . '*',
            'search_columns' => [ 'display_name', 'user_login' ],
            'number'     => 10,
        ] );

        // Also try searching by knowly_nickname meta
        if ( empty( $users ) ) {
            $users = get_users( [
                'role'       => 'knowly_child',
                'meta_key'   => 'knowly_nickname',
                'meta_value' => $search,
                'number'     => 5,
            ] );
        }

        if ( empty( $users ) ) {
            wp_send_json_error( [ 'message' => "No child found matching '{$search}'." ] );
        }

        // Use the first match
        $child        = $users[0];
        $child_id     = $child->ID;
        $parent_id    = (int) get_user_meta( $child_id, 'knowly_parent_id', true );

        if ( ! $parent_id ) {
            wp_send_json_error( [ 'message' => 'Child has no linked parent account.' ] );
        }

        // Check if already a member
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND child_id = %d AND status = 'active'",
            $class_id, $child_id
        ) );
        if ( $existing ) {
            wp_send_json_error( [ 'message' => $child->display_name . ' is already a member.' ] );
        }

        $result = Knowly_Class_Service::add_member( $class_id, $child_id, $parent_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => $child->display_name . ' added to class.' ] );
    }

    // ── AJAX: Remove member ───────────────────────────────────────────────────

    public static function ajax_remove_member(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        $child_id = (int) ( $_POST['child_id'] ?? 0 );

        if ( ! $class_id || ! $child_id ) {
            wp_send_json_error( [ 'message' => 'Invalid parameters.' ] );
        }

        // Admin bypass: directly mark as removed without teacher ownership check
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'knowly_class_members',
            [ 'status' => 'removed' ],
            [ 'class_id' => $class_id, 'child_id' => $child_id, 'status' => 'active' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'Failed to remove member.' ] );
        }

        wp_send_json_success( [ 'removed' => true ] );
    }

    // ── AJAX: Close task ──────────────────────────────────────────────────────

    public static function ajax_close_task(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $task_id = (int) ( $_POST['task_id'] ?? 0 );
        if ( ! $task_id ) {
            wp_send_json_error( [ 'message' => 'Invalid task ID.' ] );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'knowly_tasks',
            [ 'status' => 'closed' ],
            [ 'id' => $task_id ]
        );

        wp_send_json_success();
    }
}
