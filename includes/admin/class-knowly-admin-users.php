<?php
/**
 * Knowly_Admin_Users — Users module admin page.
 *
 * Consolidates Parents, Teachers, and Children management.
 * Tabs: Parents | Teachers | Children | Unit Tests
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Users {

    public static function boot(): void {
        // Delegate to existing AJAX handlers (already registered by Member/Teacher boot)
        // No new AJAX needed — we re-use the AJAX from Knowly_Admin_Members and Knowly_Admin_Teachers
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab = sanitize_key( $_GET['tab'] ?? 'parents' );
        $tabs = [
            'parents'  => 'Parents',
            'teachers' => 'Teachers',
            'children' => 'Children',
            'tests'    => 'Unit Tests',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Users</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-users&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>
            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
                <?php
                match ( $tab ) {
                    'parents'  => self::render_parents(),
                    'teachers' => self::render_teachers(),
                    'children' => self::render_children(),
                    'tests'    => self::render_tests(),
                    default    => self::render_parents(),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // ── Parents ───────────────────────────────────────────────────────────────

    private static function render_parents(): void {
        $parents = get_users( [ 'role' => 'knowly_parent', 'orderby' => 'registered', 'order' => 'DESC' ] );
        $total_children = (int) ( new WP_User_Query( [ 'role' => 'knowly_child', 'count_total' => true, 'number' => 0 ] ) )->get_total();
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div class="knowly-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
            <div class="knowly-stat-card">
                <div class="knowly-stat-number"><?= count( $parents ) ?></div>
                <div class="knowly-stat-label">Parent Accounts</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number"><?= esc_html( $total_children ) ?></div>
                <div class="knowly-stat-label">Student Profiles</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number" id="knowly-users-parent-count"><?= count( $parents ) ?></div>
                <div class="knowly-stat-label">Showing</div>
            </div>
        </div>
        <p style="margin-bottom:10px;">
            <input type="search" id="knowly-parent-search" placeholder="Filter parents…" class="regular-text" style="height:30px;">
        </p>

        <div id="knowly-parents-list">
        <?php foreach ( $parents as $parent ) : ?>
        <?php
        $gem_balance = (int) get_user_meta( $parent->ID, 'knowly_gem_balance', true );
        $has_pin     = (bool) get_user_meta( $parent->ID, 'knowly_pin_hash', true );
        $child_ids   = get_user_meta( $parent->ID, 'knowly_children', true ) ?: [];
        $child_count = count( $child_ids );
        $is_test     = get_user_meta( $parent->ID, 'knowly_is_test_account', true );
        ?>
        <div class="knowly-parent-card"
             style="border:1px solid #c3c4c7;border-radius:4px;margin-bottom:8px;background:#fff;"
             data-search="<?= esc_attr( strtolower( $parent->display_name . ' ' . $parent->user_email ) ) ?>">

            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <strong>#<?= esc_html( $parent->ID ) ?> <?= esc_html( $parent->display_name ) ?></strong>
                    <?php if ( $is_test ) : ?><span class="knowly-badge warn" style="margin-left:6px;">test</span><?php endif; ?>
                    <div style="font-size:12px;color:#666;margin-top:2px;"><?= esc_html( $parent->user_email ) ?></div>
                </div>
                <div style="display:flex;gap:16px;align-items:center;font-size:13px;">
                    <span title="Gem balance"><span id="parent-gem-<?= (int) $parent->ID ?>"><?= esc_html( $gem_balance ) ?></span> 💎</span>
                    <span><?= esc_html( $child_count ) ?> child<?= $child_count !== 1 ? 'ren' : '' ?></span>
                    <span><?= $has_pin ? '<span class="knowly-badge ok" style="font-size:11px;">PIN Set</span>' : '<span class="knowly-badge" style="font-size:11px;">No PIN</span>' ?></span>
                    <span style="color:#888;font-size:11px;"><?= esc_html( substr( $parent->user_registered, 0, 10 ) ) ?></span>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="<?= esc_url( admin_url( 'user-edit.php?user_id=' . $parent->ID ) ) ?>" class="button button-small">Edit</a>
                    <button class="button button-small"
                        onclick="knowlyParents.resetPin(<?= (int) $parent->ID ?>)">Reset PIN</button>
                    <button class="button button-small"
                        onclick="knowlyParents.creditGems(<?= (int) $parent->ID ?>, '<?= esc_js( $parent->display_name ) ?>')">+ Gems</button>
                    <?php if ( $child_count > 0 ) : ?>
                    <button class="button button-small knowly-toggle-children"
                        data-parent-id="<?= (int) $parent->ID ?>"
                        style="color:#2563eb;border-color:#2563eb;">
                        Children ▾
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $child_count > 0 ) : ?>
            <div class="knowly-children-panel" id="children-panel-<?= (int) $parent->ID ?>"
                 style="display:none;border-top:1px solid #e5e7eb;background:#f9fafb;padding:10px 14px;">
                <?php
                // Render children inline
                $children_data = Knowly_Children_Service::list_children( $parent->ID );
                foreach ( $children_data as $child ) :
                    $child_gem_balance = Knowly_Gem_Service::get_balance( $child['child_id'] );
                    $child_user        = get_userdata( $child['child_id'] );
                    $nickname          = get_user_meta( $child['child_id'], 'knowly_nickname', true );
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;"
                     data-child-id="<?= (int) $child['child_id'] ?>">
                    <div style="flex:1;min-width:180px;">
                        <strong style="font-size:13px;"><?= esc_html( $child['display_name'] ) ?></strong>
                        <?php if ( $nickname ) : ?>
                            <span style="font-size:11px;color:#6366f1;margin-left:4px;">@<?= esc_html( $nickname ) ?></span>
                        <?php endif; ?>
                        <div style="font-size:11px;color:#666;">
                            ID <?= esc_html( $child['child_id'] ) ?> · <?= esc_html( strtoupper( $child['level'] ) ) ?>
                            <?= $child['period'] ? ' · ' . esc_html( strtoupper( str_replace( '_', ' ', $child['period'] ) ) ) : ' · SEA' ?>
                        </div>
                    </div>
                    <span style="font-size:13px;">
                        <span class="child-gem-balance" data-child-id="<?= (int) $child['child_id'] ?>"><?= esc_html( $child_gem_balance ) ?></span> 💎
                    </span>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                        <button class="button button-small"
                            style="color:#16a34a;border-color:#16a34a;"
                            onclick="knowlyParents.addGemsToChild(<?= (int) $parent->ID ?>, <?= (int) $child['child_id'] ?>, '<?= esc_js( $child['display_name'] ) ?>')">
                            → Child
                        </button>
                        <button class="button button-small"
                            style="color:#d97706;border-color:#d97706;"
                            onclick="knowlyParents.reclaimGemsFromChild(<?= (int) $parent->ID ?>, <?= (int) $child['child_id'] ?>, '<?= esc_js( $child['display_name'] ) ?>')">
                            ← Parent
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>

        <script>
        const _parentNonce = '<?= esc_js( $nonce ) ?>';
        document.getElementById('knowly-parent-search').addEventListener('input', function() {
            var q = this.value.toLowerCase();
            var cards = document.querySelectorAll('#knowly-parents-list .knowly-parent-card');
            var shown = 0;
            cards.forEach(function(c) {
                var match = !q || c.dataset.search.includes(q);
                c.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            document.getElementById('knowly-users-parent-count').textContent = shown;
        });

        document.querySelectorAll('.knowly-toggle-children').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = document.getElementById('children-panel-' + this.dataset.parentId);
                if (!panel) return;
                var open = panel.style.display !== 'none';
                panel.style.display = open ? 'none' : 'block';
                this.textContent = open ? 'Children ▾' : 'Children ▴';
            });
        });

        const knowlyParents = {
            resetPin(userId) {
                if (!confirm('Reset PIN for user ' + userId + '?')) return;
                jQuery.post(ajaxurl, { action: 'knowly_members_reset_pin', nonce: KnowlyAdmin.nonce, user_id: userId }, r => {
                    alert(r.success ? 'PIN reset.' : (r.data?.message || 'Error'));
                });
            },
            creditGems(userId, name) {
                var amount = parseInt(prompt('Credit how many gems to ' + name + '?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, { action: 'knowly_members_credit_tokens', nonce: KnowlyAdmin.nonce, parent_id: userId, amount: amount }, r => {
                    if (r.success) {
                        document.getElementById('parent-gem-' + userId).textContent = r.data.balance_after;
                        alert('Credited ' + amount + ' gems.');
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
            addGemsToChild(parentId, childId, childName) {
                var amount = parseInt(prompt('Transfer how many gems from parent to ' + childName + '?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, {
                    action: 'knowly_admin_allocate_to_child',
                    nonce: _parentNonce,
                    parent_id: parentId,
                    child_id: childId,
                    amount: amount,
                }, r => {
                    if (r.success) {
                        document.getElementById('parent-gem-' + parentId).textContent = r.data.parent_balance;
                        document.querySelectorAll('.child-gem-balance[data-child-id="' + childId + '"]').forEach(el => {
                            el.textContent = r.data.child_balance;
                        });
                        alert('Transferred ' + amount + ' gems to ' + childName + '.');
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
            reclaimGemsFromChild(parentId, childId, childName) {
                var amount = parseInt(prompt('Reclaim how many gems from ' + childName + ' back to parent?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, {
                    action: 'knowly_admin_reclaim_from_child',
                    nonce: _parentNonce,
                    parent_id: parentId,
                    child_id: childId,
                    amount: amount,
                }, r => {
                    if (r.success) {
                        document.getElementById('parent-gem-' + parentId).textContent = r.data.parent_balance;
                        document.querySelectorAll('.child-gem-balance[data-child-id="' + childId + '"]').forEach(el => {
                            el.textContent = r.data.child_balance;
                        });
                        alert('Reclaimed ' + amount + ' gems from ' + childName + '.');
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
        };
        </script>
        <?php
    }

    // ── Teachers ──────────────────────────────────────────────────────────────

    private static function render_teachers(): void {
        $all_teachers = Knowly_Teacher_Service::list_teachers();
        $pending      = array_values( array_filter( $all_teachers, fn( $t ) => $t['approval_status'] === 'pending_approval' ) );
        $approved     = array_values( array_filter( $all_teachers, fn( $t ) => $t['approval_status'] === 'approved' ) );
        $suspended    = array_values( array_filter( $all_teachers, fn( $t ) => $t['approval_status'] === 'suspended' ) );
        ?>
        <div class="knowly-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
            <div class="knowly-stat-card" style="<?= count( $pending ) ? 'border-color:#d63638;' : '' ?>">
                <div class="knowly-stat-number" style="<?= count( $pending ) ? 'color:#d63638;' : '' ?>"><?= count( $pending ) ?></div>
                <div class="knowly-stat-label">Pending Approval</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number" style="color:#00a32a;"><?= count( $approved ) ?></div>
                <div class="knowly-stat-label">Approved</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number" style="color:#888;"><?= count( $suspended ) ?></div>
                <div class="knowly-stat-label">Suspended</div>
            </div>
        </div>

        <?php if ( ! empty( $pending ) ) : ?>
        <h3 style="color:#d63638;">Pending Approval</h3>
        <?php self::render_teacher_table( $pending, true ); ?>
        <hr>
        <?php endif; ?>

        <h3>All Teachers</h3>
        <?php self::render_teacher_table( array_merge( $approved, $suspended ), false ); ?>
        <?php
    }

    private static function render_teacher_table( array $teachers, bool $show_approve_button ): void {
        if ( empty( $teachers ) ) {
            echo '<p style="color:#888;">None.</p>';
            return;
        }
        ?>
        <table class="knowly-table widefat">
            <thead>
                <tr><th>Name</th><th>Email</th><th>School</th><th>Status</th><th>Red Gems</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $teachers as $t ) : ?>
                <tr id="teacher-row-<?= esc_attr( $t['user_id'] ) ?>">
                    <td><strong><?= esc_html( $t['display_name'] ) ?></strong></td>
                    <td><?= esc_html( $t['email'] ) ?></td>
                    <td><?= esc_html( $t['school_name'] ?? '—' ) ?></td>
                    <td>
                        <?php
                        $s = $t['approval_status'];
                        $badge_color = $s === 'approved' ? 'ok' : ( $s === 'pending_approval' ? 'warn' : '' );
                        ?>
                        <span class="knowly-badge <?= esc_attr( $badge_color ) ?>"><?= esc_html( $s ) ?></span>
                    </td>
                    <td><?= esc_html( $t['red_gem_balance'] ?? 0 ) ?> 🔴</td>
                    <td style="white-space:nowrap;">
                        <?php if ( $show_approve_button || $t['approval_status'] !== 'approved' ) : ?>
                        <button class="button button-small" style="color:#00a32a;"
                            onclick="knowlyTeachers.approve(<?= (int) $t['user_id'] ?>)">Approve</button>
                        <?php endif; ?>
                        <?php if ( $t['approval_status'] === 'approved' ) : ?>
                        <button class="button button-small" style="color:#d63638;"
                            onclick="knowlyTeachers.suspend(<?= (int) $t['user_id'] ?>)">Suspend</button>
                        <?php endif; ?>
                        <button class="button button-small"
                            onclick="knowlyTeachers.adjustGems(<?= (int) $t['user_id'] ?>, '<?= esc_js( $t['display_name'] ) ?>')">Adj Gems</button>
                        <a href="<?= esc_url( admin_url( 'user-edit.php?user_id=' . $t['user_id'] ) ) ?>" class="button button-small">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        const knowlyTeachers = {
            approve(userId) {
                if (!confirm('Approve teacher ' + userId + '?')) return;
                jQuery.post(ajaxurl, { action: 'knowly_teacher_approve', nonce: KnowlyAdmin.nonce, user_id: userId }, r => {
                    if (r.success) { location.reload(); } else { alert(r.data?.message || 'Error'); }
                });
            },
            suspend(userId) {
                if (!confirm('Suspend teacher ' + userId + '?')) return;
                jQuery.post(ajaxurl, { action: 'knowly_teacher_suspend', nonce: KnowlyAdmin.nonce, user_id: userId }, r => {
                    if (r.success) { location.reload(); } else { alert(r.data?.message || 'Error'); }
                });
            },
            adjustGems(userId, name) {
                var amount = parseInt(prompt('Adjust red gems for ' + name + ' (negative to deduct):'), 10);
                if (!amount) return;
                jQuery.post(ajaxurl, { action: 'knowly_teacher_adjust_gems', nonce: KnowlyAdmin.nonce, user_id: userId, amount: amount }, r => {
                    if (r.success) { alert('Adjusted.'); location.reload(); } else { alert(r.data?.message || 'Error'); }
                });
            },
        };
        </script>
        <?php
    }

    // ── Children ──────────────────────────────────────────────────────────────

    private static function render_children(): void {
        global $wpdb;
        $children = get_users( [ 'role' => 'knowly_child', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 200 ] );
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <p style="margin-bottom:10px;">
            <input type="search" id="knowly-child-search" placeholder="Filter children…" class="regular-text" style="height:30px;">
        </p>

        <div id="knowly-children-list">
        <?php foreach ( $children as $child ) : ?>
        <?php
        $nickname    = get_user_meta( $child->ID, 'knowly_nickname',   true );
        $level       = get_user_meta( $child->ID, 'knowly_level',      true );
        $period      = get_user_meta( $child->ID, 'knowly_period',     true );
        $gem_balance = (int) get_user_meta( $child->ID, 'knowly_gem_balance', true );
        $parent_id   = (int) get_user_meta( $child->ID, 'knowly_parent_id',  true );
        $parent      = $parent_id ? get_userdata( $parent_id ) : null;
        $is_test     = get_user_meta( $child->ID, 'knowly_is_test_account', true );
        $trial_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_sessions WHERE child_id = %d", $child->ID
        ) );
        $earned_badges = json_decode( get_user_meta( $child->ID, 'knowly_earned_badges', true ) ?: '[]', true );
        $quest_count   = is_array( $earned_badges ) ? count( $earned_badges ) : 0;
        ?>
        <div class="knowly-child-entry"
             style="border:1px solid #c3c4c7;border-radius:4px;margin-bottom:6px;background:#fff;"
             data-search="<?= esc_attr( strtolower( $child->display_name . ' ' . ( $nickname ?? '' ) . ' ' . $child->user_email ) ) ?>">

            <div style="display:flex;align-items:center;gap:12px;padding:8px 14px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <strong>#<?= esc_html( $child->ID ) ?> <?= esc_html( $child->display_name ) ?></strong>
                    <?php if ( $nickname ) : ?>
                        <span style="font-size:11px;color:#6366f1;margin-left:4px;">@<?= esc_html( $nickname ) ?></span>
                    <?php endif; ?>
                    <?php if ( $is_test ) : ?><span class="knowly-badge warn" style="margin-left:4px;">test</span><?php endif; ?>
                    <div style="font-size:11px;color:#666;margin-top:2px;">
                        <?= esc_html( strtoupper( $level ?? '—' ) ) ?>
                        <?= $period ? ' · ' . esc_html( strtoupper( str_replace( '_', ' ', $period ) ) ) : ' · SEA' ?>
                        · <?= $parent ? esc_html( $parent->display_name ) : 'No parent' ?>
                    </div>
                </div>
                <div style="display:flex;gap:14px;align-items:center;font-size:13px;flex-wrap:wrap;">
                    <span><?= esc_html( $gem_balance ) ?> 💎</span>
                    <span style="color:#666;"><?= esc_html( $trial_count ) ?> trial<?= $trial_count !== 1 ? 's' : '' ?></span>
                    <span style="color:#666;"><?= esc_html( $quest_count ) ?> quest<?= $quest_count !== 1 ? 's' : '' ?></span>
                </div>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <a href="<?= esc_url( admin_url( 'user-edit.php?user_id=' . $child->ID ) ) ?>" class="button button-small">Edit</a>
                    <?php if ( $trial_count > 0 || $quest_count > 0 ) : ?>
                    <button class="button button-small knowly-toggle-child-history"
                        data-child-id="<?= (int) $child->ID ?>"
                        style="color:#2563eb;border-color:#2563eb;">
                        History ▾
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $trial_count > 0 || $quest_count > 0 ) : ?>
            <div class="knowly-child-history-panel" id="child-history-<?= (int) $child->ID ?>"
                 style="display:none;border-top:1px solid #e5e7eb;background:#f9fafb;padding:10px 14px;">

                <?php if ( $trial_count > 0 ) : ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <strong style="font-size:13px;">Trial History (<?= esc_html( $trial_count ) ?>)</strong>
                    <button class="button button-small"
                        style="color:#dc2626;border-color:#dc2626;"
                        onclick="knowlyChildren.deleteHistory(<?= (int) $child->ID ?>, 'trials', this)">
                        Remove All Trials
                    </button>
                </div>
                <?php
                $sessions = $wpdb->get_results( $wpdb->prepare(
                    "SELECT session_id, subject, level, period, difficulty, trial_type, score, total, percentage, state, started_at
                     FROM {$wpdb->prefix}knowly_exam_sessions
                     WHERE child_id = %d ORDER BY started_at DESC LIMIT 50",
                    $child->ID
                ), ARRAY_A ) ?: [];
                ?>
                <table class="knowly-table" style="font-size:12px;margin-bottom:12px;">
                    <thead>
                        <tr>
                            <th>Subject</th><th>Level</th><th>Type</th>
                            <th style="text-align:center;">Score</th><th style="text-align:center;">%</th>
                            <th>Status</th><th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $sessions as $s ) :
                        $pct_color = (float) ( $s['percentage'] ?? 0 ) >= 70 ? '#16a34a' : ( (float) ( $s['percentage'] ?? 0 ) >= 50 ? '#d97706' : '#dc2626' );
                    ?>
                    <tr>
                        <td><strong><?= esc_html( $s['subject'] ) ?></strong></td>
                        <td><?= esc_html( strtoupper( $s['level'] ?? '' ) ) ?> <?= $s['period'] ? esc_html( strtoupper( str_replace( '_', ' ', $s['period'] ) ) ) : 'SEA' ?></td>
                        <td><?= esc_html( ucfirst( $s['trial_type'] ?? 'practice' ) ) ?></td>
                        <td style="text-align:center;"><?= $s['state'] === 'completed' ? esc_html( $s['score'] . '/' . $s['total'] ) : '—' ?></td>
                        <td style="text-align:center;font-weight:700;color:<?= $s['state'] === 'completed' ? $pct_color : '#9ca3af' ?>;">
                            <?= $s['state'] === 'completed' ? esc_html( $s['percentage'] ) . '%' : '—' ?>
                        </td>
                        <td><?= esc_html( ucfirst( $s['state'] ) ) ?></td>
                        <td style="color:#888;"><?= esc_html( substr( $s['started_at'], 0, 10 ) ) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ( $quest_count > 0 ) : ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <strong style="font-size:13px;">Quest History (<?= esc_html( $quest_count ) ?> completed)</strong>
                    <button class="button button-small"
                        style="color:#dc2626;border-color:#dc2626;"
                        onclick="knowlyChildren.deleteHistory(<?= (int) $child->ID ?>, 'quests', this)">
                        Remove All Quests
                    </button>
                </div>
                <table class="knowly-table" style="font-size:12px;margin-bottom:12px;">
                    <thead>
                        <tr><th>Quest ID</th><th>Badge ID</th><th>Awarded At</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $earned_badges as $badge ) : ?>
                    <tr>
                        <td><?= esc_html( $badge['quest_id'] ?? '—' ) ?></td>
                        <td><?= esc_html( $badge['badge_id'] ?? '—' ) ?></td>
                        <td style="color:#888;"><?= esc_html( substr( $badge['awarded_at'] ?? '', 0, 10 ) ) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>

        <script>
        const _childNonce = '<?= esc_js( $nonce ) ?>';
        document.getElementById('knowly-child-search').addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#knowly-children-list .knowly-child-entry').forEach(function(c) {
                c.style.display = (!q || c.dataset.search.includes(q)) ? '' : 'none';
            });
        });

        document.querySelectorAll('.knowly-toggle-child-history').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = document.getElementById('child-history-' + this.dataset.childId);
                if (!panel) return;
                var open = panel.style.display !== 'none';
                panel.style.display = open ? 'none' : 'block';
                this.textContent = open ? 'History ▾' : 'History ▴';
            });
        });

        const knowlyChildren = {
            deleteHistory(childId, type, btn) {
                var label = type === 'quests' ? 'quest badges' : 'trial sessions';
                if (!confirm('Delete all ' + label + ' for this child? This cannot be undone.')) return;
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action: 'knowly_admin_delete_child_history',
                    nonce: _childNonce,
                    child_id: childId,
                    type: type,
                }, r => {
                    btn.disabled = false;
                    if (r.success) {
                        alert('History removed.');
                        location.reload();
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
        };
        </script>
        <?php
    }

    // ── Unit Tests ────────────────────────────────────────────────────────────

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test account provisioning, auth flows, children management, and teacher approval.</p>';
        Knowly_Admin_Testing::render_test_groups( [
            'block2_setup',
            'block2_auth',
            'auth',
            'children',
            'block2_teacher',
        ] );
    }
}
