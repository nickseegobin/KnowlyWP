<?php
/**
 * Knowly_Admin_Classes — WP Admin page for class management.
 *
 * Overview  : list all classes with delete + create controls
 * Detail    : editable name/level, live member search & add/remove,
 *             assign and remove quests/trials (teacher tasks, admin bypass)
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
        add_action( 'wp_ajax_knowly_class_delete',           [ __CLASS__, 'ajax_delete_class' ] );
        add_action( 'wp_ajax_knowly_class_create',           [ __CLASS__, 'ajax_create_class' ] );
        add_action( 'wp_ajax_knowly_class_update',           [ __CLASS__, 'ajax_update_class' ] );
        add_action( 'wp_ajax_knowly_class_search_members',   [ __CLASS__, 'ajax_search_members' ] );
        add_action( 'wp_ajax_knowly_class_assign_task',      [ __CLASS__, 'ajax_assign_task' ] );
        add_action( 'wp_ajax_knowly_class_delete_task',      [ __CLASS__, 'ajax_delete_task' ] );
        add_action( 'wp_ajax_knowly_class_get_content_pool',    [ __CLASS__, 'ajax_get_content_pool' ] );
        add_action( 'wp_ajax_knowly_class_get_trial_subjects', [ __CLASS__, 'ajax_get_trial_subjects' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $tab      = sanitize_key( $_GET['tab'] ?? 'overview' );
        $class_id = (int) ( $_GET['class_id'] ?? 0 );
        $tabs     = [ 'overview' => 'Overview', 'settings' => 'Settings', 'tests' => 'Unit Tests' ];
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

    // ── Overview ──────────────────────────────────────────────────────────────

    private static function render_overview(): void {
        global $wpdb;
        $nonce        = wp_create_nonce( 'knowly_class_admin' );
        $class_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_classes" );
        $member_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members WHERE status = 'active'" );
        $task_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks" );
        $classes      = $wpdb->get_results(
            "SELECT c.*, u.display_name AS teacher_display_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members m WHERE m.class_id = c.id AND m.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}knowly_tasks t WHERE t.class_id = c.id) AS task_count
             FROM {$wpdb->prefix}knowly_classes c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.teacher_user_id
             ORDER BY c.created_at DESC LIMIT 200"
        );

        // Load all teachers for the create form
        $teachers = get_users( [ 'role' => 'knowly_teacher', 'orderby' => 'display_name', 'number' => 200 ] );
        ?>
        <div class="knowly-stat-grid" style="margin-bottom:20px;">
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $class_count ) ?></div><div class="knowly-stat-label">Total Classes</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $member_count ) ?></div><div class="knowly-stat-label">Active Memberships</div></div>
            <div class="knowly-stat-card"><div class="knowly-stat-number"><?= esc_html( $task_count ) ?></div><div class="knowly-stat-label">Tasks Created</div></div>
        </div>

        <!-- Create Class button + form -->
        <div style="margin-bottom:16px;">
            <button class="button button-primary" onclick="knowlyClasses.toggleCreateForm()">+ Create Class</button>
            <div id="create-class-form" style="display:none;margin-top:10px;background:#f9fafb;border:1px solid #c3c4c7;border-radius:4px;padding:14px;max-width:700px;">
                <strong style="display:block;margin-bottom:10px;">New Class</strong>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;font-size:13px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Class Name *</label>
                        <input type="text" id="create-class-name" placeholder="e.g. 4B Maths" style="width:100%;height:30px;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Level</label>
                        <input type="text" id="create-class-level" placeholder="e.g. std_4" style="width:100%;height:30px;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Teacher *</label>
                        <select id="create-class-teacher" style="width:100%;height:30px;">
                            <option value="">— Select Teacher —</option>
                            <?php foreach ( $teachers as $t ) : ?>
                            <option value="<?= (int) $t->ID ?>"><?= esc_html( $t->display_name ) ?> (#<?= (int) $t->ID ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
                    <button class="button button-primary" onclick="knowlyClasses.createClass('<?= esc_js( $nonce ) ?>', this)">Create</button>
                    <button class="button" onclick="knowlyClasses.toggleCreateForm()">Cancel</button>
                    <span id="create-class-status" style="font-size:12px;"></span>
                </div>
            </div>
        </div>

        <?php if ( empty( $classes ) ) : ?>
            <p style="color:#888;">No classes created yet.</p>
        <?php else : ?>
        <table class="knowly-table widefat">
            <thead>
                <tr><th>ID</th><th>Class Name</th><th>Teacher</th><th>Level</th><th>Status</th><th>Members</th><th>Tasks</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ( $classes as $cls ) : ?>
            <tr id="class-overview-row-<?= (int) $cls->id ?>">
                <td><?= esc_html( $cls->id ) ?></td>
                <td><strong><?= esc_html( $cls->name ) ?></strong></td>
                <td><?= esc_html( $cls->teacher_display_name ?: "User #{$cls->teacher_user_id}" ) ?></td>
                <td><?= esc_html( $cls->level ?: '—' ) ?></td>
                <td><?= esc_html( $cls->status ?? 'active' ) ?></td>
                <td><?= esc_html( $cls->member_count ) ?></td>
                <td><?= esc_html( $cls->task_count ) ?></td>
                <td><?= esc_html( wp_date( 'Y-m-d', strtotime( $cls->created_at ) ) ) ?></td>
                <td style="display:flex;gap:4px;flex-wrap:wrap;">
                    <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-classes&tab=detail&class_id=' . (int) $cls->id ) ) ?>"
                       class="button button-small">View</a>
                    <button class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                        onclick="knowlyClasses.deleteClass(<?= (int) $cls->id ?>, '<?= esc_js( $cls->name ) ?>', '<?= esc_js( $nonce ) ?>')">
                        Delete
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <script>
        const knowlyClasses = {
            toggleCreateForm() {
                var f = document.getElementById('create-class-form');
                f.style.display = f.style.display === 'none' ? 'block' : 'none';
            },
            createClass(nonce, btn) {
                var name      = document.getElementById('create-class-name').value.trim();
                var level     = document.getElementById('create-class-level').value.trim();
                var teacherId = document.getElementById('create-class-teacher').value;
                var $s        = document.getElementById('create-class-status');
                if (!name) { alert('Class name is required.'); return; }
                if (!teacherId) { alert('Please select a teacher.'); return; }
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action: 'knowly_class_create', nonce, name, level, teacher_id: teacherId,
                }, r => {
                    btn.disabled = false;
                    if (r.success) {
                        $s.textContent = '✓ Created';
                        $s.style.color = '#16a34a';
                        setTimeout(() => location.reload(), 800);
                    } else {
                        $s.textContent = r.data?.message || 'Error';
                        $s.style.color = '#dc2626';
                    }
                });
            },
            deleteClass(classId, name, nonce) {
                if (!confirm('Delete class "' + name + '"?\n\nThis will disband all members and delete all tasks. This cannot be undone.')) return;
                jQuery.post(ajaxurl, { action: 'knowly_class_delete', nonce, class_id: classId }, r => {
                    if (r.success) {
                        var row = document.getElementById('class-overview-row-' + classId);
                        if (row) row.remove();
                    } else {
                        alert(r.data?.message || 'Error deleting class.');
                    }
                });
            },
        };
        </script>
        <?php
    }

    // ── Settings ──────────────────────────────────────────────────────────────

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

    // ── Unit Tests ────────────────────────────────────────────────────────────

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

        // Batch-load child DB data for level display
        $child_ids     = array_column( $members, 'child_id' );
        $child_db_data = [];
        if ( $child_ids ) {
            $placeholders  = implode( ',', array_map( 'intval', $child_ids ) );
            $child_db_rows = $wpdb->get_results(
                "SELECT child_id, level, period FROM {$wpdb->prefix}knowly_children WHERE child_id IN ($placeholders)",
                ARRAY_A
            ) ?: [];
            foreach ( $child_db_rows as $r ) {
                $child_db_data[ (int) $r['child_id'] ] = $r;
            }
        }
        ?>

        <!-- Class header + inline edit -->
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:14px;margin-bottom:20px;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:260px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <input type="text" id="edit-class-name" value="<?= esc_attr( $cls->name ) ?>"
                               style="font-size:18px;font-weight:700;border:1px solid #c3c4c7;border-radius:4px;padding:4px 8px;height:36px;min-width:200px;">
                        <input type="text" id="edit-class-level" value="<?= esc_attr( $cls->level ?: '' ) ?>"
                               placeholder="Level (e.g. std_4)"
                               style="font-size:13px;border:1px solid #c3c4c7;border-radius:4px;padding:4px 8px;height:36px;width:140px;">
                        <button class="button button-primary" onclick="knowlyClassDetail.saveClass(<?= $class_id ?>, '<?= esc_js( $nonce ) ?>', this)">
                            Save
                        </button>
                        <span id="class-update-status" style="font-size:12px;"></span>
                    </div>
                    <div style="font-size:12px;color:#666;margin-top:6px;">
                        Teacher: <strong><?= esc_html( $cls->teacher_display_name ?: "User #{$cls->teacher_user_id}" ) ?></strong>
                        · Status: <strong><?= esc_html( $cls->status ) ?></strong>
                        · Created: <strong><?= esc_html( wp_date( 'Y-m-d', strtotime( $cls->created_at ) ) ) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Members section -->
        <div style="margin-bottom:28px;">
            <h3 style="margin:0 0 10px;">Members (<?= count( $members ) ?>)</h3>

            <!-- Live member search -->
            <div style="background:#f0f4ff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;margin-bottom:12px;">
                <strong style="font-size:13px;display:block;margin-bottom:8px;">Add Member</strong>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                    <input type="text" id="member-search-first" placeholder="First name…"
                           oninput="knowlyClassDetail.searchMembers(<?= $class_id ?>)"
                           style="height:30px;width:160px;font-size:13px;">
                    <input type="text" id="member-search-last" placeholder="Last name…"
                           oninput="knowlyClassDetail.searchMembers(<?= $class_id ?>)"
                           style="height:30px;width:160px;font-size:13px;">
                    <input type="text" id="member-search-nick" placeholder="Nickname…"
                           oninput="knowlyClassDetail.searchMembers(<?= $class_id ?>)"
                           style="height:30px;width:160px;font-size:13px;">
                </div>
                <div id="member-search-results" style="font-size:12px;min-height:20px;color:#888;">
                    Type a name above to search students…
                </div>
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
                    $nickname    = get_user_meta( $member['child_id'], 'knowly_nickname', true );
                    $db          = $child_db_data[ (int) $member['child_id'] ] ?? [];
                    $level       = strtoupper( $db['level'] ?? '' ) ?: '—';
                    $gems        = Knowly_Gem_Service::get_balance( $member['child_id'] );
                    $parent_user = $member['parent_id'] ? get_userdata( $member['parent_id'] ) : null;
                ?>
                <tr id="member-row-<?= (int) $member['child_id'] ?>">
                    <td><strong>#<?= esc_html( $member['child_id'] ) ?> <?= esc_html( $member['display_name'] ) ?></strong></td>
                    <td><?= $nickname ? esc_html( $nickname ) : '—' ?></td>
                    <td><?= $parent_user ? esc_html( $parent_user->display_name ) : '—' ?></td>
                    <td><?= esc_html( $level ) ?></td>
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

        <!-- Tasks / Quests & Trials section -->
        <div>
            <h3 style="margin:0 0 10px;">Tasks — Quests &amp; Trials (<?= count( $tasks ) ?>)</h3>

            <!-- Assign new task form -->
            <div style="background:#f0fff4;border:1px solid #c3c4c7;border-radius:4px;padding:12px;margin-bottom:12px;">
                <strong style="font-size:13px;display:block;margin-bottom:8px;">Assign Task to Class</strong>

                <!-- Step 1: Type + filter -->
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;font-size:12px;margin-bottom:8px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Type *</label>
                        <select id="task-type" onchange="knowlyClassDetail.onTypeChange(<?= $class_id ?>, '<?= esc_js( $nonce ) ?>')"
                                style="width:100%;height:28px;font-size:12px;">
                            <option value="trial">Trial (Practice)</option>
                            <option value="quest">Quest</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Level</label>
                        <select id="task-level" onchange="knowlyClassDetail.onFilterChange(<?= $class_id ?>, '<?= esc_js( $nonce ) ?>')"
                                style="width:100%;height:28px;font-size:12px;">
                            <option value="std_4" <?= esc_attr( $cls->level ) === 'std_4' ? 'selected' : '' ?>>Standard 4</option>
                            <option value="std_5" <?= esc_attr( $cls->level ) === 'std_5' ? 'selected' : '' ?>>Standard 5</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Period</label>
                        <select id="task-period" onchange="knowlyClassDetail.onFilterChange(<?= $class_id ?>, '<?= esc_js( $nonce ) ?>')"
                                style="width:100%;height:28px;font-size:12px;">
                            <option value="term_1">Term 1</option>
                            <option value="term_2">Term 2</option>
                            <option value="term_3">Term 3</option>
                        </select>
                    </div>
                    <div style="display:flex;align-items:flex-end;">
                        <button class="button button-small" id="task-load-btn"
                            onclick="knowlyClassDetail.loadContentPool(<?= $class_id ?>, '<?= esc_js( $nonce ) ?>', this)"
                            style="width:100%;height:28px;font-size:12px;">
                            Load Content
                        </button>
                    </div>
                </div>

                <!-- Step 2: Content picker (shown after loading) -->
                <div id="task-content-picker" style="display:none;margin-bottom:8px;font-size:12px;">
                    <!-- Quest picker -->
                    <div id="task-quest-picker" style="display:none;">
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Select Quest *</label>
                        <select id="task-quest-select" onchange="knowlyClassDetail.onQuestSelect()"
                                style="width:100%;height:28px;font-size:12px;">
                            <option value="">— Choose a quest —</option>
                        </select>
                    </div>
                    <!-- Trial picker -->
                    <div id="task-trial-picker" style="display:none;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:3px;">Subject *</label>
                                <select id="task-subject" style="width:100%;height:28px;font-size:12px;">
                                    <option value="">— Click Load Content to see available subjects —</option>
                                </select>
                                <span id="task-subject-hint" style="font-size:11px;color:#888;"></span>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:3px;">Difficulty</label>
                                <select id="task-difficulty" style="width:100%;height:28px;font-size:12px;">
                                    <option value="">Any</option>
                                    <option value="easy">Easy</option>
                                    <option value="medium">Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hidden fields auto-filled from content picker -->
                <input type="hidden" id="task-title" value="">
                <input type="hidden" id="task-reference-id" value="">
                <input type="hidden" id="task-auto-subject" value="">
                <input type="hidden" id="task-auto-difficulty" value="">

                <!-- Step 3: Expiry + reward (always shown) -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;margin-bottom:8px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Expiry Date *</label>
                        <input type="date" id="task-expiry" min="<?= esc_attr( date( 'Y-m-d' ) ) ?>"
                               style="width:100%;height:28px;font-size:12px;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Gem Reward (optional)</label>
                        <input type="number" id="task-gem-reward" min="0" value="0"
                               style="width:100%;height:28px;font-size:12px;">
                    </div>
                </div>

                <div id="task-selected-preview" style="display:none;background:#fff;border:1px solid #d1fae5;border-radius:4px;padding:8px;margin-bottom:8px;font-size:12px;">
                    <strong>Selected:</strong> <span id="task-preview-text"></span>
                </div>

                <div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
                    <button class="button button-primary button-small" id="task-assign-btn" disabled
                        onclick="knowlyClassDetail.assignTask(<?= $class_id ?>, <?= (int) $cls->teacher_user_id ?>, '<?= esc_js( $nonce ) ?>', this)">
                        Assign Task
                    </button>
                    <span id="task-assign-status" style="font-size:11px;"></span>
                </div>
                <p style="font-size:11px;color:#888;margin:6px 0 0;">
                    Load content to pick from available quests or specify trial subject. Assigned tasks are free for students (no blue gems) and expire on the set date.
                </p>
            </div>

            <?php if ( empty( $tasks ) ) : ?>
            <p style="color:#888;">No tasks assigned yet.</p>
            <?php else : ?>
            <table class="knowly-table widefat" id="knowly-tasks-table">
                <thead>
                    <tr><th>Title</th><th>Type</th><th>Subject</th><th>Difficulty</th><th>Expiry</th><th>Reward</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ( $tasks as $task ) : ?>
                <tr id="task-row-<?= (int) $task['id'] ?>">
                    <td><strong><?= esc_html( $task['title'] ) ?></strong></td>
                    <td><?= esc_html( ucfirst( $task['type'] ) ) ?></td>
                    <td><?= esc_html( $task['subject'] ?: '—' ) ?></td>
                    <td><?= esc_html( ucfirst( $task['difficulty'] ?: '—' ) ) ?></td>
                    <td style="color:#888;"><?= esc_html( $task['due_date'] ?: '—' ) ?></td>
                    <td><?= $task['gem_reward'] ? esc_html( $task['gem_reward'] ) . ' 💎' : '—' ?></td>
                    <td>
                        <span class="knowly-badge <?= $task['status'] === 'active' ? 'ok' : '' ?>" style="font-size:11px;">
                            <?= esc_html( $task['status'] ) ?>
                        </span>
                    </td>
                    <td style="display:flex;gap:4px;flex-wrap:wrap;">
                        <?php if ( $task['status'] === 'active' ) : ?>
                        <button class="button button-small" style="color:#d97706;border-color:#d97706;"
                            onclick="knowlyClassDetail.closeTask(<?= (int) $task['id'] ?>, '<?= esc_js( $nonce ) ?>')">
                            Close
                        </button>
                        <?php endif; ?>
                        <button class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                            onclick="knowlyClassDetail.deleteTask(<?= (int) $task['id'] ?>, '<?= esc_js( $nonce ) ?>')">
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script>
        const knowlyClassDetail = {
            saveClass(classId, nonce, btn) {
                var name  = document.getElementById('edit-class-name').value.trim();
                var level = document.getElementById('edit-class-level').value.trim();
                var $s    = document.getElementById('class-update-status');
                if (!name) { alert('Class name cannot be empty.'); return; }
                btn.disabled = true;
                jQuery.post(ajaxurl, { action: 'knowly_class_update', nonce, class_id: classId, name, level }, r => {
                    btn.disabled = false;
                    $s.textContent = r.success ? '✓ Saved' : (r.data?.message || 'Error');
                    $s.style.color = r.success ? '#16a34a' : '#dc2626';
                    setTimeout(() => $s.textContent = '', 3000);
                });
            },
            _searchTimer: null,
            searchMembers(classId) {
                clearTimeout(this._searchTimer);
                this._searchTimer = setTimeout(() => {
                    var first = document.getElementById('member-search-first').value.trim();
                    var last  = document.getElementById('member-search-last').value.trim();
                    var nick  = document.getElementById('member-search-nick').value.trim();
                    var q     = [first, last, nick].filter(Boolean).join(' ');
                    if (!q) {
                        document.getElementById('member-search-results').innerHTML = 'Type a name above to search students…';
                        return;
                    }
                    var nonce = '<?= esc_js( $nonce ) ?>';
                    document.getElementById('member-search-results').innerHTML = '<em style="color:#888;">Searching…</em>';
                    jQuery.post(ajaxurl, {
                        action: 'knowly_class_search_members', nonce, class_id: classId,
                        first_name: first, last_name: last, nickname: nick,
                    }, r => {
                        if (r.success && r.data.results.length) {
                            var html = '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
                            r.data.results.forEach(s => {
                                html += '<button class="button button-small" style="color:#2563eb;border-color:#2563eb;" '
                                      + 'onclick="knowlyClassDetail.addMember(' + classId + ',' + s.id + ',\'' + s.name.replace(/'/g,"\\'") + '\',\'' + nonce + '\')">'
                                      + '#' + s.id + ' ' + s.name + (s.nick ? ' @' + s.nick : '') + ' <span style="color:#888;font-size:10px;">(' + s.level + ')</span>'
                                      + '</button>';
                            });
                            html += '</div>';
                            document.getElementById('member-search-results').innerHTML = html;
                        } else {
                            document.getElementById('member-search-results').innerHTML = r.data?.message || 'No students found.';
                        }
                    });
                }, 300);
            },
            addMember(classId, childId, childName, nonce) {
                jQuery.post(ajaxurl, {
                    action: 'knowly_class_add_member', nonce, class_id: classId, child_id: childId,
                }, r => {
                    if (r.success) {
                        alert(childName + ' added to class.');
                        location.reload();
                    } else {
                        alert(r.data?.message || 'Error adding member.');
                    }
                });
            },
            removeMember(classId, childId, name, nonce) {
                if (!confirm('Remove ' + name + ' from this class?')) return;
                jQuery.post(ajaxurl, {
                    action: 'knowly_class_remove_member', nonce, class_id: classId, child_id: childId,
                }, r => {
                    if (r.success) {
                        var row = document.getElementById('member-row-' + childId);
                        if (row) row.remove();
                    } else {
                        alert(r.data?.message || 'Error removing member.');
                    }
                });
            },
            closeTask(taskId, nonce) {
                if (!confirm('Close this task? Students will no longer be able to access it.')) return;
                jQuery.post(ajaxurl, { action: 'knowly_class_close_task', nonce, task_id: taskId }, r => {
                    if (r.success) {
                        var row = document.getElementById('task-row-' + taskId);
                        if (row) {
                            row.querySelector('.knowly-badge').textContent = 'closed';
                            row.querySelector('.knowly-badge').classList.remove('ok');
                            var closeBtn = row.querySelector('[style*="d97706"]');
                            if (closeBtn) closeBtn.remove();
                        }
                    } else { alert(r.data?.message || 'Error.'); }
                });
            },
            deleteTask(taskId, nonce) {
                if (!confirm('Delete this task permanently?')) return;
                jQuery.post(ajaxurl, { action: 'knowly_class_delete_task', nonce, task_id: taskId }, r => {
                    if (r.success) {
                        var row = document.getElementById('task-row-' + taskId);
                        if (row) row.remove();
                    } else { alert(r.data?.message || 'Error.'); }
                });
            },
            onTypeChange(classId, nonce) {
                // Reset content picker state when type changes
                document.getElementById('task-content-picker').style.display = 'none';
                document.getElementById('task-quest-picker').style.display   = 'none';
                document.getElementById('task-trial-picker').style.display   = 'none';
                document.getElementById('task-selected-preview').style.display = 'none';
                document.getElementById('task-assign-btn').disabled = true;
                document.getElementById('task-title').value        = '';
                document.getElementById('task-reference-id').value = '';
            },
            onFilterChange(classId, nonce) {
                // Reset selection when level/period changes
                document.getElementById('task-content-picker').style.display = 'none';
                document.getElementById('task-quest-picker').style.display   = 'none';
                document.getElementById('task-trial-picker').style.display   = 'none';
                document.getElementById('task-selected-preview').style.display = 'none';
                document.getElementById('task-assign-btn').disabled = true;
                document.getElementById('task-title').value        = '';
                document.getElementById('task-reference-id').value = '';
            },
            loadContentPool(classId, nonce, btn) {
                var type   = document.getElementById('task-type').value;
                var level  = document.getElementById('task-level').value;
                var period = document.getElementById('task-period').value;
                var $s     = document.getElementById('task-assign-status');
                btn.disabled = true;
                btn.textContent = 'Loading…';

                jQuery.post(ajaxurl, {
                    action: 'knowly_class_get_content_pool', nonce,
                    type, level, period,
                }, r => {
                    btn.disabled = false;
                    btn.textContent = 'Load Content';
                    if (!r.success) {
                        $s.textContent = r.data?.message || 'Failed to load content.';
                        $s.style.color = '#dc2626';
                        setTimeout(() => $s.textContent = '', 4000);
                        return;
                    }

                    document.getElementById('task-content-picker').style.display = 'block';
                    document.getElementById('task-selected-preview').style.display = 'none';
                    document.getElementById('task-assign-btn').disabled = true;
                    document.getElementById('task-title').value        = '';
                    document.getElementById('task-reference-id').value = '';

                    if (type === 'quest') {
                        var questPicker  = document.getElementById('task-quest-picker');
                        var questSelect  = document.getElementById('task-quest-select');
                        var trialPicker  = document.getElementById('task-trial-picker');
                        trialPicker.style.display = 'none';
                        questPicker.style.display = 'block';

                        questSelect.innerHTML = '<option value="">— Choose a quest —</option>';
                        var quests = r.data.items || [];
                        if (!quests.length) {
                            questSelect.innerHTML = '<option value="">No quests available for this level/period</option>';
                        } else {
                            quests.forEach(q => {
                                var opt = document.createElement('option');
                                opt.value = JSON.stringify(q);
                                var label = q.title || q.id;
                                if (q.subject)    label += ' — ' + q.subject;
                                if (q.difficulty) label += ' (' + q.difficulty + ')';
                                opt.textContent = label;
                                questSelect.appendChild(opt);
                            });
                        }
                    } else {
                        document.getElementById('task-quest-picker').style.display = 'none';
                        document.getElementById('task-trial-picker').style.display = 'block';

                        // Load available subjects from WP local trial pool
                        var $subjectSel  = document.getElementById('task-subject');
                        var $subjectHint = document.getElementById('task-subject-hint');
                        $subjectSel.innerHTML = '<option value="">Loading subjects…</option>';
                        $subjectSel.disabled  = true;
                        document.getElementById('task-assign-btn').disabled = true;

                        jQuery.post(ajaxurl, {
                            action: 'knowly_class_get_trial_subjects', nonce,
                            level, period,
                        }, sr => {
                            if (!sr.success || !sr.data.subjects.length) {
                                $subjectSel.innerHTML = '<option value="">No subjects available — Sync Trials first</option>';
                                $subjectHint.textContent = 'Go to Trials admin → Sync Trials from Railway to populate the pool.';
                            } else {
                                $subjectSel.innerHTML = '<option value="">— Select a subject —</option>';
                                sr.data.subjects.forEach(s => {
                                    var opt = document.createElement('option');
                                    opt.value       = s.value;
                                    opt.textContent = s.label + ' (' + s.count + ' packages)';
                                    $subjectSel.appendChild(opt);
                                });
                                $subjectHint.textContent = sr.data.subjects.length + ' subject(s) available in pool';
                                document.getElementById('task-assign-btn').disabled = false;
                            }
                            $subjectSel.disabled = false;
                        });
                    }
                });
            },
            onQuestSelect() {
                var sel = document.getElementById('task-quest-select').value;
                var preview = document.getElementById('task-selected-preview');
                var btn     = document.getElementById('task-assign-btn');
                if (!sel) {
                    preview.style.display = 'none';
                    btn.disabled = true;
                    document.getElementById('task-title').value        = '';
                    document.getElementById('task-reference-id').value = '';
                    return;
                }
                var q = JSON.parse(sel);
                document.getElementById('task-title').value        = q.title || q.id || '';
                document.getElementById('task-reference-id').value = q.id    || '';
                document.getElementById('task-auto-subject').value    = q.subject    || '';
                document.getElementById('task-auto-difficulty').value = q.difficulty || '';
                var label = q.title || q.id;
                if (q.subject)    label += ' — ' + q.subject;
                if (q.difficulty) label += ' (' + q.difficulty + ')';
                document.getElementById('task-preview-text').textContent = label;
                preview.style.display = 'block';
                btn.disabled = false;
            },
            assignTask(classId, teacherId, nonce, btn) {
                var type   = document.getElementById('task-type').value;
                var expiry = document.getElementById('task-expiry').value;
                var reward = parseInt(document.getElementById('task-gem-reward').value, 10) || 0;
                var $s     = document.getElementById('task-assign-status');

                var title, subject, diff, refId;

                if (type === 'quest') {
                    title   = document.getElementById('task-title').value.trim();
                    subject = document.getElementById('task-auto-subject').value.trim();
                    diff    = document.getElementById('task-auto-difficulty').value.trim();
                    refId   = document.getElementById('task-reference-id').value.trim();
                    if (!title || !refId) { alert('Please select a quest from the content picker.'); return; }
                } else {
                    subject = document.getElementById('task-subject').value;
                    diff    = document.getElementById('task-difficulty').value;
                    if (!subject) { alert('Please select a subject for the trial.'); return; }
                    var level  = document.getElementById('task-level').value;
                    var period = document.getElementById('task-period').value;
                    title  = (subject || 'Trial') + ' — ' + (level ? level.replace('std_', 'Standard ') : '') + ' ' + (period ? period.replace('term_', 'Term ') : '');
                    refId  = '';
                }

                if (!expiry) { alert('Expiry date is required.'); return; }
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action: 'knowly_class_assign_task', nonce,
                    class_id: classId, teacher_id: teacherId,
                    type, title, subject, difficulty: diff, reference_id: refId,
                    due_date: expiry, gem_reward: reward,
                }, r => {
                    btn.disabled = false;
                    if (r.success) {
                        $s.textContent = '✓ Task assigned';
                        $s.style.color = '#16a34a';
                        setTimeout(() => location.reload(), 700);
                    } else {
                        $s.textContent = r.data?.message || 'Error';
                        $s.style.color = '#dc2626';
                        setTimeout(() => $s.textContent = '', 4000);
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

    // ── AJAX: Create class (from overview) ────────────────────────────────────

    public static function ajax_create_class(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $level      = sanitize_text_field( $_POST['level'] ?? '' );

        if ( ! $teacher_id || ! $name ) {
            wp_send_json_error( [ 'message' => 'Teacher and class name are required.' ] );
        }

        $class_id = Knowly_Class_Service::create( $teacher_id, [ 'name' => $name, 'level' => $level ] );
        if ( is_wp_error( $class_id ) ) {
            wp_send_json_error( [ 'message' => $class_id->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.classes', 'Class created via overview', [
            'class_id'   => $class_id,
            'teacher_id' => $teacher_id,
        ], null, 'info' );

        wp_send_json_success( [ 'class_id' => $class_id ] );
    }

    // ── AJAX: Delete class ────────────────────────────────────────────────────

    public static function ajax_delete_class(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        if ( ! $class_id ) wp_send_json_error( [ 'message' => 'Invalid class ID.' ] );

        global $wpdb;

        // Delete tasks first
        $wpdb->delete( $wpdb->prefix . 'knowly_tasks', [ 'class_id' => $class_id ] );

        // Remove all members
        $wpdb->delete( $wpdb->prefix . 'knowly_class_members', [ 'class_id' => $class_id ] );

        // Delete the class
        $wpdb->delete( $wpdb->prefix . 'knowly_classes', [ 'id' => $class_id ] );

        Knowly_Debug::log( 'admin.classes', 'Class deleted via admin', [ 'class_id' => $class_id ], null, 'info' );

        wp_send_json_success();
    }

    // ── AJAX: Update class name/level ─────────────────────────────────────────

    public static function ajax_update_class(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        $name     = sanitize_text_field( $_POST['name']  ?? '' );
        $level    = sanitize_text_field( $_POST['level'] ?? '' );

        if ( ! $class_id || ! $name ) {
            wp_send_json_error( [ 'message' => 'Class ID and name are required.' ] );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'knowly_classes',
            [ 'name' => $name, 'level' => $level ?: null ],
            [ 'id' => $class_id ]
        );

        wp_send_json_success();
    }

    // ── AJAX: Search children for adding to class ─────────────────────────────

    public static function ajax_search_members(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id   = (int) ( $_POST['class_id']   ?? 0 );
        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name']  ?? '' );
        $nickname   = sanitize_text_field( $_POST['nickname']   ?? '' );

        if ( ! $first_name && ! $last_name && ! $nickname ) {
            wp_send_json_error( [ 'message' => 'Enter at least one search field.' ] );
        }

        // Get current active members of this class to exclude them
        global $wpdb;
        $existing_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND status = 'active'",
            $class_id
        ) );

        // Build WP_User_Query args for name search
        $args = [
            'role'   => 'knowly_child',
            'number' => 20,
        ];

        // Search by display_name (first+last combined by WP) or user_login (nickname)
        $search_terms = array_filter( [ $first_name, $last_name, $nickname ] );
        if ( $search_terms ) {
            $args['search']         = '*' . implode( '*', $search_terms ) . '*';
            $args['search_columns'] = [ 'display_name', 'user_login', 'user_nicename' ];
        }

        $users = get_users( $args );

        // Also search meta for nickname separately if nickname provided
        if ( $nickname && empty( $users ) ) {
            $users = get_users( [
                'role'       => 'knowly_child',
                'meta_key'   => 'knowly_nickname',
                'meta_value' => $nickname,
                'number'     => 20,
            ] );
        }

        // Filter: match all provided fields, exclude current members
        $results = [];
        foreach ( $users as $user ) {
            if ( in_array( $user->ID, $existing_ids ) ) continue;

            $fn   = strtolower( get_user_meta( $user->ID, 'first_name', true ) );
            $ln   = strtolower( get_user_meta( $user->ID, 'last_name',  true ) );
            $nick = strtolower( get_user_meta( $user->ID, 'knowly_nickname', true ) );

            if ( $first_name && strpos( $fn, strtolower( $first_name ) ) === false ) continue;
            if ( $last_name  && strpos( $ln, strtolower( $last_name  ) ) === false ) continue;
            if ( $nickname   && strpos( $nick, strtolower( $nickname  ) ) === false ) continue;

            // Get level from knowly_children table
            $db_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT level FROM {$wpdb->prefix}knowly_children WHERE child_id = %d LIMIT 1",
                $user->ID
            ) );

            $results[] = [
                'id'    => $user->ID,
                'name'  => $user->display_name,
                'nick'  => $nick,
                'level' => $db_row ? strtoupper( $db_row->level ?: 'SEA' ) : '—',
            ];

            if ( count( $results ) >= 10 ) break;
        }

        if ( empty( $results ) ) {
            wp_send_json_error( [ 'message' => 'No students found matching those criteria.' ] );
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    // ── AJAX: Add member by child_id (from search result click) ──────────────

    public static function ajax_add_member(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        $child_id = (int) ( $_POST['child_id'] ?? 0 );

        // Legacy: support search-by-text for backwards compat
        if ( ! $child_id && isset( $_POST['search'] ) ) {
            $search = sanitize_text_field( $_POST['search'] );
            $users  = get_users( [
                'role'           => 'knowly_child',
                'search'         => '*' . $search . '*',
                'search_columns' => [ 'display_name', 'user_login' ],
                'number'         => 1,
            ] );
            if ( empty( $users ) ) {
                wp_send_json_error( [ 'message' => "No child found matching '{$search}'." ] );
            }
            $child_id = $users[0]->ID;
        }

        if ( ! $class_id || ! $child_id ) {
            wp_send_json_error( [ 'message' => 'Class ID and child ID are required.' ] );
        }

        $parent_id = (int) get_user_meta( $child_id, 'knowly_parent_id', true );
        if ( ! $parent_id ) {
            wp_send_json_error( [ 'message' => 'Child has no linked parent account.' ] );
        }

        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}knowly_class_members WHERE class_id = %d AND child_id = %d AND status = 'active'",
            $class_id, $child_id
        ) );
        if ( $existing ) {
            $child = get_userdata( $child_id );
            wp_send_json_error( [ 'message' => ( $child ? $child->display_name : "Child #{$child_id}" ) . ' is already a member.' ] );
        }

        $result = Knowly_Class_Service::add_member( $class_id, $child_id, $parent_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        $child = get_userdata( $child_id );
        wp_send_json_success( [ 'message' => ( $child ? $child->display_name : "Child #{$child_id}" ) . ' added to class.' ] );
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

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'knowly_class_members',
            [ 'status' => 'removed' ],
            [ 'class_id' => $class_id, 'child_id' => $child_id, 'status' => 'active' ]
        );

        wp_send_json_success( [ 'removed' => true ] );
    }

    // ── AJAX: Close task ──────────────────────────────────────────────────────

    public static function ajax_close_task(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $task_id = (int) ( $_POST['task_id'] ?? 0 );
        if ( ! $task_id ) wp_send_json_error( [ 'message' => 'Invalid task ID.' ] );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'knowly_tasks', [ 'status' => 'closed' ], [ 'id' => $task_id ] );

        wp_send_json_success();
    }

    // ── AJAX: Delete task ─────────────────────────────────────────────────────

    public static function ajax_delete_task(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $task_id = (int) ( $_POST['task_id'] ?? 0 );
        if ( ! $task_id ) wp_send_json_error( [ 'message' => 'Invalid task ID.' ] );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'knowly_tasks', [ 'id' => $task_id ] );

        wp_send_json_success();
    }

    // ── AJAX: Assign task (admin, no gem cost) ────────────────────────────────

    public static function ajax_assign_task(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id    = (int) ( $_POST['class_id']     ?? 0 );
        $teacher_id  = (int) ( $_POST['teacher_id']   ?? 0 );
        $title       = sanitize_text_field( $_POST['title']        ?? '' );
        $type        = sanitize_text_field( $_POST['type']         ?? 'trial' );
        $subject     = sanitize_text_field( $_POST['subject']      ?? '' );
        $difficulty  = sanitize_text_field( $_POST['difficulty']   ?? '' );
        $due_date    = sanitize_text_field( $_POST['due_date']     ?? '' );
        $reference_id = sanitize_text_field( $_POST['reference_id'] ?? '' );
        $gem_reward  = max( 0, (int) ( $_POST['gem_reward'] ?? 0 ) );

        if ( ! $class_id || ! $title || ! $due_date ) {
            wp_send_json_error( [ 'message' => 'Class, title, and expiry date are required.' ] );
        }

        $allowed_types        = [ 'trial', 'quest', 'lesson' ];
        $allowed_difficulties = [ '', 'easy', 'medium', 'hard' ];
        if ( ! in_array( $type, $allowed_types, true ) ) $type = 'trial';
        if ( ! in_array( $difficulty, $allowed_difficulties, true ) ) $difficulty = '';

        // Quest and lesson types require a reference_id
        if ( in_array( $type, [ 'quest', 'lesson' ], true ) && ! $reference_id ) {
            wp_send_json_error( [ 'message' => 'Quest/lesson tasks require a content reference. Please pick content from the content pool.' ] );
        }

        $parsed = date_create( $due_date );
        if ( ! $parsed ) {
            wp_send_json_error( [ 'message' => 'Invalid expiry date.' ] );
        }

        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'knowly_tasks',
            [
                'class_id'        => $class_id,
                'teacher_user_id' => $teacher_id,
                'type'            => $type,
                'reference_id'    => $reference_id ?: null,
                'title'           => $title,
                'description'     => null,
                'subject'         => $subject ?: null,
                'difficulty'      => $difficulty ?: null,
                'due_date'        => $parsed->format( 'Y-m-d' ),
                'gem_reward'      => $gem_reward > 0 ? $gem_reward : null,
                'red_gem_cost'    => 0,   // admin-assigned tasks cost nothing
                'status'          => 'active',
                'created_at'      => current_time( 'mysql', true ),
            ]
        );

        if ( $inserted === false ) {
            wp_send_json_error( [ 'message' => 'Database error: ' . $wpdb->last_error ] );
        }

        $task_id = (int) $wpdb->insert_id;

        Knowly_Debug::log( 'admin.classes', 'Task assigned via admin', [
            'class_id'     => $class_id,
            'task_id'      => $task_id,
            'type'         => $type,
            'title'        => $title,
            'reference_id' => $reference_id ?: null,
        ], null, 'info' );

        wp_send_json_success( [ 'task_id' => $task_id ] );
    }

    // ── AJAX: Get available content pool for a given type/level/period ────────

    public static function ajax_get_content_pool(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $type   = sanitize_text_field( $_POST['type']   ?? 'quest' );
        $level  = sanitize_text_field( $_POST['level']  ?? '' );
        $period = sanitize_text_field( $_POST['period'] ?? '' );

        if ( ! $level ) {
            wp_send_json_error( [ 'message' => 'Level is required.' ] );
        }

        if ( $type === 'quest' ) {
            $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );
            $result     = Knowly_Quest_Service::get_catalogue( $level, $period, $curriculum );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => 'Could not load quests: ' . $result->get_error_message() ] );
            }

            // Normalise fields — Railway may return snake_case or camelCase
            $items = array_map( function( $q ) {
                return [
                    'id'         => $q['id']         ?? $q['quest_id'] ?? $q['_id'] ?? '',
                    'title'      => $q['title']       ?? $q['name']     ?? '',
                    'subject'    => $q['subject']     ?? '',
                    'difficulty' => $q['difficulty']  ?? '',
                ];
            }, (array) $result );

            wp_send_json_success( [ 'type' => 'quest', 'items' => $items, 'count' => count( $items ) ] );

        } else {
            // Trial — subjects loaded separately via knowly_class_get_trial_subjects.
            wp_send_json_success( [ 'type' => 'trial', 'items' => [], 'count' => 0 ] );
        }
    }

    // ── AJAX: Get available trial subjects from WP local pool ─────────────────

    /**
     * Returns distinct subjects available in wp_knowly_trial_packages for a
     * given level/period/curriculum. Used to populate the subject dropdown in
     * the task assignment form — no Railway call needed.
     */
    public static function ajax_get_trial_subjects(): void {
        check_ajax_referer( 'knowly_class_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $table = $wpdb->prefix . 'knowly_trial_packages';

        $level      = sanitize_text_field( $_POST['level']  ?? '' );
        $period     = sanitize_text_field( $_POST['period'] ?? '' );
        $curriculum = get_option( 'knowly_default_curriculum', 'tt_primary' );

        // ── 1. Full subject list from curriculum config (source of truth) ─────
        $all_curricula = get_option( 'knowly_curriculum_subjects', [] );
        $curriculum_cfg = $all_curricula[ $curriculum ] ?? null;

        if ( ! $curriculum_cfg ) {
            wp_send_json_error( [ 'message' => "No subject config found for curriculum '{$curriculum}'. Check Settings." ] );
        }

        $defined_subjects = $curriculum_cfg['subjects'] ?? [];

        // ── 2. Pool counts for this level/period (what's actually synced) ─────
        $sql  = "SELECT subject, COUNT(*) AS package_count
                 FROM {$table}
                 WHERE status = 'approved' AND curriculum = %s";
        $args = [ $curriculum ];

        if ( $level )  { $sql .= ' AND level = %s';  $args[] = $level; }
        if ( $period ) { $sql .= ' AND period = %s'; $args[] = $period; }

        $sql .= ' GROUP BY subject';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $pool_rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
        $pool_counts = [];
        foreach ( $pool_rows ?? [] as $row ) {
            $pool_counts[ $row['subject'] ] = (int) $row['package_count'];
        }

        // ── 3. Merge: all curriculum subjects + pool counts ───────────────────
        $subjects = array_map( function( $s ) use ( $pool_counts ) {
            $count = $pool_counts[ $s['value'] ] ?? 0;
            return [
                'value' => $s['value'],
                'label' => $s['label'],
                'count' => $count,
                'ready' => $count > 0,
            ];
        }, $defined_subjects );

        wp_send_json_success( [
            'subjects'       => $subjects,
            'total'          => count( $subjects ),
            'curriculum'     => $curriculum,
            'curriculum_name'=> $curriculum_cfg['display_name'] ?? $curriculum,
        ] );
    }
}
