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
        <table class="knowly-table widefat" id="knowly-parents-table">
            <thead>
                <tr>
                    <th>User</th><th>Email</th><th>Registered</th>
                    <th>Gems</th><th>Children</th><th>PIN</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $parents as $parent ) : ?>
                <?php
                $gem_balance = (int) get_user_meta( $parent->ID, 'knowly_gem_balance', true );
                $has_pin     = (bool) get_user_meta( $parent->ID, 'knowly_pin_hash', true );
                $child_count = count( get_user_meta( $parent->ID, 'knowly_children', true ) ?: [] );
                $is_test     = get_user_meta( $parent->ID, 'knowly_is_test_account', true );
                ?>
                <tr class="parent-row" data-search="<?= esc_attr( strtolower( $parent->display_name . ' ' . $parent->user_email ) ) ?>">
                    <td>
                        <strong>#<?= esc_html( $parent->ID ) ?> <?= esc_html( $parent->display_name ) ?></strong>
                        <?php if ( $is_test ) : ?><span class="knowly-badge warn">test</span><?php endif; ?>
                    </td>
                    <td><?= esc_html( $parent->user_email ) ?></td>
                    <td><?= esc_html( substr( $parent->user_registered, 0, 10 ) ) ?></td>
                    <td><?= esc_html( $gem_balance ) ?> 💎</td>
                    <td><?= esc_html( $child_count ) ?></td>
                    <td><?= $has_pin ? '<span class="knowly-badge ok">Set</span>' : '<span class="knowly-badge">None</span>' ?></td>
                    <td style="white-space:nowrap;">
                        <a href="<?= esc_url( admin_url( 'user-edit.php?user_id=' . $parent->ID ) ) ?>" class="button button-small">Edit</a>
                        <button class="button button-small" onclick="knowlyUsers.resetPin(<?= (int) $parent->ID ?>)">Reset PIN</button>
                        <button class="button button-small" onclick="knowlyUsers.creditGems(<?= (int) $parent->ID ?>, '<?= esc_js( $parent->display_name ) ?>')">Credit Gems</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        document.getElementById('knowly-parent-search').addEventListener('input', function() {
            var q = this.value.toLowerCase();
            var rows = document.querySelectorAll('#knowly-parents-table .parent-row');
            var shown = 0;
            rows.forEach(function(r) {
                var match = !q || r.dataset.search.includes(q);
                r.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            document.getElementById('knowly-users-parent-count').textContent = shown;
        });
        const knowlyUsers = {
            resetPin(userId) {
                if (!confirm('Reset PIN for user ' + userId + '?')) return;
                jQuery.post(ajaxurl, { action: 'knowly_members_reset_pin', nonce: KnowlyAdmin.nonce, user_id: userId }, r => {
                    alert(r.success ? 'PIN reset.' : (r.data?.message || 'Error'));
                });
            },
            creditGems(userId, name) {
                var amount = parseInt(prompt('Credit how many gems to ' + name + '?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, { action: 'knowly_members_credit_tokens', nonce: KnowlyAdmin.nonce, user_id: userId, amount: amount }, r => {
                    alert(r.success ? 'Credited ' + amount + ' gems.' : (r.data?.message || 'Error'));
                    if (r.success) location.reload();
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
        ?>
        <p style="margin-bottom:10px;">
            <input type="search" id="knowly-child-search" placeholder="Filter children…" class="regular-text" style="height:30px;">
        </p>
        <table class="knowly-table widefat" id="knowly-children-table">
            <thead>
                <tr><th>Name / ID</th><th>Nickname</th><th>Level</th><th>Period</th><th>Gems</th><th>Parent</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $children as $child ) : ?>
                <?php
                $nickname    = get_user_meta( $child->ID, 'knowly_nickname', true );
                $level       = get_user_meta( $child->ID, 'knowly_level',    true );
                $period      = get_user_meta( $child->ID, 'knowly_period',   true );
                $gem_balance = (int) get_user_meta( $child->ID, 'knowly_gem_balance', true );
                $parent_id   = (int) get_user_meta( $child->ID, 'knowly_parent_id',  true );
                $parent      = $parent_id ? get_userdata( $parent_id ) : null;
                $is_test     = get_user_meta( $child->ID, 'knowly_is_test_account', true );
                ?>
                <tr data-search="<?= esc_attr( strtolower( $child->display_name . ' ' . ( $nickname ?? '' ) ) ) ?>">
                    <td>
                        <strong>#<?= esc_html( $child->ID ) ?> <?= esc_html( $child->display_name ) ?></strong>
                        <?php if ( $is_test ) : ?><span class="knowly-badge warn">test</span><?php endif; ?>
                    </td>
                    <td><?= esc_html( $nickname ?? '—' ) ?></td>
                    <td><?= esc_html( $level  ?? '—' ) ?></td>
                    <td><?= esc_html( $period ?? '—' ) ?></td>
                    <td><?= esc_html( $gem_balance ) ?> 💎</td>
                    <td><?= $parent ? esc_html( $parent->display_name ) : '—' ?></td>
                    <td>
                        <a href="<?= esc_url( admin_url( 'user-edit.php?user_id=' . $child->ID ) ) ?>" class="button button-small">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        document.getElementById('knowly-child-search').addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#knowly-children-table tbody tr').forEach(function(r) {
                r.style.display = (!q || r.dataset.search.includes(q)) ? '' : 'none';
            });
        });
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
