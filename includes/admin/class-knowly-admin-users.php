<?php
/**
 * Knowly_Admin_Users — Users module admin page.
 *
 * Tabs: Parents | Children | Unit Tests
 * (Teachers tab removed — use Admin › Teachers instead)
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Users {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_admin_update_child',        [ __CLASS__, 'ajax_update_child' ] );
        add_action( 'wp_ajax_knowly_admin_update_parent',       [ __CLASS__, 'ajax_update_parent' ] );
        add_action( 'wp_ajax_knowly_admin_add_child',           [ __CLASS__, 'ajax_add_child' ] );
        add_action( 'wp_ajax_knowly_admin_remove_child',        [ __CLASS__, 'ajax_remove_child' ] );
        add_action( 'wp_ajax_knowly_admin_remove_gems',         [ __CLASS__, 'ajax_remove_gems' ] );
        add_action( 'wp_ajax_knowly_admin_reset_pack_history',  [ __CLASS__, 'ajax_reset_pack_history' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab = sanitize_key( $_GET['tab'] ?? 'parents' );
        $tabs = [
            'parents'  => 'Parents',
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
        global $wpdb;
        $parents = get_users( [ 'role' => 'knowly_parent', 'orderby' => 'registered', 'order' => 'DESC' ] );
        $total_children = (int) ( new WP_User_Query( [ 'role' => 'knowly_child', 'count_total' => true, 'number' => 0 ] ) )->get_total();
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );

        // Batch-load all children from DB to avoid N+1 queries
        $all_children_rows = $wpdb->get_results(
            "SELECT c.parent_id, c.child_id, c.display_name, c.level, c.period, c.avatar_index,
                    um.meta_value AS nickname
             FROM {$wpdb->prefix}knowly_children c
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = c.child_id AND um.meta_key = 'knowly_nickname'
             ORDER BY c.child_row_id ASC",
            ARRAY_A
        ) ?: [];

        $children_by_parent = [];
        foreach ( $all_children_rows as $row ) {
            $children_by_parent[ (int) $row['parent_id'] ][] = $row;
        }
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
        <?php foreach ( $parents as $parent ) :
            $gem_balance    = (int) get_user_meta( $parent->ID, 'knowly_gem_balance', true );
            $has_pin        = (bool) get_user_meta( $parent->ID, 'knowly_pin_hash', true );
            $is_test        = (bool) get_user_meta( $parent->ID, 'knowly_is_test_account', true );
            $first_name     = get_user_meta( $parent->ID, 'first_name', true );
            $last_name      = get_user_meta( $parent->ID, 'last_name', true );
            $children_data  = $children_by_parent[ $parent->ID ] ?? [];
            $child_count    = count( $children_data );
        ?>
        <div class="knowly-parent-card"
             style="border:1px solid #c3c4c7;border-radius:4px;margin-bottom:8px;background:#fff;"
             data-search="<?= esc_attr( strtolower( $parent->display_name . ' ' . $parent->user_email . ' ' . $first_name . ' ' . $last_name ) ) ?>">

            <!-- Parent header row -->
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <strong>#<?= esc_html( $parent->ID ) ?> <?= esc_html( $parent->display_name ) ?></strong>
                    <?php if ( $is_test ) : ?><span class="knowly-badge warn" style="margin-left:6px;">test</span><?php endif; ?>
                    <div style="font-size:12px;color:#666;margin-top:2px;"><?= esc_html( $parent->user_email ) ?></div>
                </div>
                <div style="display:flex;gap:16px;align-items:center;font-size:13px;flex-wrap:wrap;">
                    <span><span id="parent-gem-<?= (int) $parent->ID ?>"><?= esc_html( $gem_balance ) ?></span> 💎</span>
                    <span><?= esc_html( $child_count ) ?> child<?= $child_count !== 1 ? 'ren' : '' ?></span>
                    <span><?= $has_pin ? '<span class="knowly-badge ok" style="font-size:11px;">PIN Set</span>' : '<span class="knowly-badge" style="font-size:11px;">No PIN</span>' ?></span>
                    <span style="color:#888;font-size:11px;"><?= esc_html( substr( $parent->user_registered, 0, 10 ) ) ?></span>
                </div>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <button class="button button-small"
                        onclick="knowlyParents.resetPin(<?= (int) $parent->ID ?>)">Reset PIN</button>
                    <button class="button button-small" style="color:#16a34a;border-color:#16a34a;"
                        onclick="knowlyParents.addGems(<?= (int) $parent->ID ?>, '<?= esc_js( $parent->display_name ) ?>')">+ Gems</button>
                    <button class="button button-small" style="color:#d97706;border-color:#d97706;"
                        onclick="knowlyParents.removeGems(<?= (int) $parent->ID ?>, '<?= esc_js( $parent->display_name ) ?>')">- Gems</button>
                    <button class="button button-small knowly-toggle-parent-edit"
                        data-parent-id="<?= (int) $parent->ID ?>"
                        style="color:#6366f1;border-color:#6366f1;">
                        Settings ▾
                    </button>
                    <button class="button button-small knowly-toggle-children"
                        data-parent-id="<?= (int) $parent->ID ?>"
                        style="color:#2563eb;border-color:#2563eb;">
                        Children (<?= esc_html( $child_count ) ?>) ▾
                    </button>
                </div>
            </div>

            <!-- Parent settings panel -->
            <div class="knowly-parent-edit-panel" id="parent-edit-<?= (int) $parent->ID ?>"
                 style="display:none;border-top:1px solid #e5e7eb;background:#f0f4ff;padding:10px 14px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:500px;font-size:13px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">First Name</label>
                        <input type="text" class="regular-text" id="parent-firstname-<?= (int) $parent->ID ?>"
                            value="<?= esc_attr( $first_name ) ?>" style="width:100%;height:28px;font-size:12px;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Last Name</label>
                        <input type="text" class="regular-text" id="parent-lastname-<?= (int) $parent->ID ?>"
                            value="<?= esc_attr( $last_name ) ?>" style="width:100%;height:28px;font-size:12px;">
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <button class="button button-small button-primary"
                        onclick="knowlyParents.saveSettings(<?= (int) $parent->ID ?>, this)">Save</button>
                    <span class="parent-save-status-<?= (int) $parent->ID ?>" style="margin-left:8px;font-size:11px;"></span>
                </div>
            </div>

            <!-- Children hierarchy panel (always rendered) -->
            <div class="knowly-children-panel" id="children-panel-<?= (int) $parent->ID ?>"
                 style="display:none;border-top:1px solid #e5e7eb;background:#f9fafb;padding:10px 14px;">

                <?php if ( empty( $children_data ) ) : ?>
                <p style="color:#888;font-size:13px;margin:0 0 8px;">No children yet.</p>
                <?php endif; ?>

                <?php foreach ( $children_data as $child ) :
                    $child_gem_balance = Knowly_Gem_Service::get_balance( (int) $child['child_id'] );
                    $level_label  = $child['level']  ? strtoupper( $child['level'] ) : 'SEA';
                    $period_label = $child['period'] ? strtoupper( str_replace( '_', ' ', $child['period'] ) ) : '';
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;"
                     id="child-row-parent-<?= (int) $parent->ID ?>-<?= (int) $child['child_id'] ?>">
                    <div style="flex:1;min-width:180px;">
                        <strong style="font-size:13px;"><?= esc_html( $child['display_name'] ) ?></strong>
                        <?php if ( $child['nickname'] ) : ?>
                            <span style="font-size:11px;color:#6366f1;margin-left:4px;">@<?= esc_html( $child['nickname'] ) ?></span>
                        <?php endif; ?>
                        <div style="font-size:11px;color:#666;">
                            #<?= esc_html( $child['child_id'] ) ?> · <?= esc_html( $level_label ) ?>
                            <?= $period_label ? ' · ' . esc_html( $period_label ) : '' ?>
                        </div>
                    </div>
                    <span style="font-size:13px;">
                        <span class="child-gem-balance" data-child-id="<?= (int) $child['child_id'] ?>"><?= esc_html( $child_gem_balance ) ?></span> 💎
                    </span>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                        <button class="button button-small" style="color:#16a34a;border-color:#16a34a;"
                            onclick="knowlyParents.addGemsToChild(<?= (int) $parent->ID ?>, <?= (int) $child['child_id'] ?>, '<?= esc_js( $child['display_name'] ) ?>')">
                            → Child
                        </button>
                        <button class="button button-small" style="color:#d97706;border-color:#d97706;"
                            onclick="knowlyParents.reclaimGemsFromChild(<?= (int) $parent->ID ?>, <?= (int) $child['child_id'] ?>, '<?= esc_js( $child['display_name'] ) ?>')">
                            ← Parent
                        </button>
                        <button class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                            onclick="knowlyParents.removeChild(<?= (int) $parent->ID ?>, <?= (int) $child['child_id'] ?>, '<?= esc_js( $child['display_name'] ) ?>')">
                            Remove
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Add Child toggle -->
                <div style="margin-top:10px;display:flex;align-items:center;gap:8px;">
                    <button class="button button-small" style="color:#16a34a;border-color:#16a34a;"
                        onclick="knowlyParents.toggleAddChildForm(<?= (int) $parent->ID ?>)">+ Add Child</button>
                    <span class="add-child-status-<?= (int) $parent->ID ?>" style="font-size:11px;"></span>
                </div>

                <!-- Add Child form (hidden by default) -->
                <div id="add-child-form-<?= (int) $parent->ID ?>"
                     style="display:none;margin-top:10px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;">
                    <strong style="font-size:13px;display:block;margin-bottom:8px;">New Child Account</strong>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;font-size:12px;">
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:3px;">First Name *</label>
                            <input type="text" id="new-child-fn-<?= (int) $parent->ID ?>" placeholder="e.g. Bree" style="width:100%;height:28px;font-size:12px;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:3px;">Last Name</label>
                            <input type="text" id="new-child-ln-<?= (int) $parent->ID ?>" placeholder="e.g. Smith" style="width:100%;height:28px;font-size:12px;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:3px;">Nickname * <span style="color:#888;font-weight:400;">(unique)</span></label>
                            <input type="text" id="new-child-nick-<?= (int) $parent->ID ?>" placeholder="e.g. TurboConch" style="width:100%;height:28px;font-size:12px;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:3px;">Standard (Level)</label>
                            <select id="new-child-level-<?= (int) $parent->ID ?>" style="width:100%;height:28px;font-size:12px;">
                                <option value="std_1">Standard 1</option>
                                <option value="std_2">Standard 2</option>
                                <option value="std_3">Standard 3</option>
                                <option value="std_4" selected>Standard 4</option>
                                <option value="std_5">Standard 5</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:3px;">Term (Period)</label>
                            <select id="new-child-period-<?= (int) $parent->ID ?>" style="width:100%;height:28px;font-size:12px;">
                                <option value="">SEA (No Term)</option>
                                <option value="term_1">Term 1</option>
                                <option value="term_2" selected>Term 2</option>
                                <option value="term_3">Term 3</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:3px;">Avatar (1–5)</label>
                            <input type="number" min="1" max="5" id="new-child-avatar-<?= (int) $parent->ID ?>" value="1" style="width:100%;height:28px;font-size:12px;">
                        </div>
                    </div>
                    <div style="margin-top:8px;display:flex;gap:8px;align-items:center;">
                        <button class="button button-small button-primary"
                            onclick="knowlyParents.createChild(<?= (int) $parent->ID ?>, this)">Create Account</button>
                        <button class="button button-small"
                            onclick="knowlyParents.toggleAddChildForm(<?= (int) $parent->ID ?>)">Cancel</button>
                    </div>
                </div>
            </div>
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
                var label = this.textContent.replace('▾','').replace('▴','').trim();
                this.textContent = label + (open ? ' ▾' : ' ▴');
            });
        });

        document.querySelectorAll('.knowly-toggle-parent-edit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = document.getElementById('parent-edit-' + this.dataset.parentId);
                if (!panel) return;
                var open = panel.style.display !== 'none';
                panel.style.display = open ? 'none' : 'block';
                this.textContent = open ? 'Settings ▾' : 'Settings ▴';
            });
        });

        const knowlyParents = {
            resetPin(userId) {
                if (!confirm('Reset PIN for user ' + userId + '?')) return;
                jQuery.post(ajaxurl, { action: 'knowly_members_reset_pin', nonce: _parentNonce, user_id: userId }, r => {
                    alert(r.success ? 'PIN reset.' : (r.data?.message || 'Error'));
                });
            },
            addGems(userId, name) {
                var amount = parseInt(prompt('Add how many gems to ' + name + '?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, { action: 'knowly_members_credit_tokens', nonce: _parentNonce, parent_id: userId, amount: amount }, r => {
                    if (r.success) {
                        document.getElementById('parent-gem-' + userId).textContent = r.data.balance_after;
                        alert('Credited ' + amount + ' gems.');
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
            saveSettings(userId, btn) {
                var first = document.getElementById('parent-firstname-' + userId).value.trim();
                var last  = document.getElementById('parent-lastname-' + userId).value.trim();
                var $s    = jQuery('.parent-save-status-' + userId);
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action: 'knowly_admin_update_parent',
                    nonce: _parentNonce,
                    user_id: userId,
                    first_name: first,
                    last_name: last,
                }, r => {
                    btn.disabled = false;
                    $s.text(r.success ? '✓ Saved' : (r.data?.message || 'Error')).css('color', r.success ? '#16a34a' : '#dc2626');
                    setTimeout(() => $s.text(''), 3000);
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
                        document.querySelectorAll('.child-gem-balance[data-child-id="' + childId + '"]').forEach(el => el.textContent = r.data.child_balance);
                        alert('Transferred ' + amount + ' gems to ' + childName + '.');
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
            removeGems(userId, name) {
                var amount = parseInt(prompt('Remove how many gems from ' + name + '?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, { action: 'knowly_admin_remove_gems', nonce: _parentNonce, user_id: userId, amount: amount }, r => {
                    if (r.success) {
                        document.getElementById('parent-gem-' + userId).textContent = r.data.balance_after;
                        alert('Removed ' + amount + ' gems from ' + name + '.');
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
                        document.querySelectorAll('.child-gem-balance[data-child-id="' + childId + '"]').forEach(el => el.textContent = r.data.child_balance);
                        alert('Reclaimed ' + amount + ' gems from ' + childName + '.');
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
            toggleAddChildForm(parentId) {
                var form = document.getElementById('add-child-form-' + parentId);
                if (!form) return;
                form.style.display = form.style.display === 'none' ? 'block' : 'none';
            },
            createChild(parentId, btn) {
                var fn     = jQuery('#new-child-fn-'     + parentId).val().trim();
                var ln     = jQuery('#new-child-ln-'     + parentId).val().trim();
                var nick   = jQuery('#new-child-nick-'   + parentId).val().trim();
                var level  = jQuery('#new-child-level-'  + parentId).val();
                var period = jQuery('#new-child-period-' + parentId).val();
                var avatar = jQuery('#new-child-avatar-' + parentId).val();
                var $s     = jQuery('.add-child-status-' + parentId);
                if (!fn || !nick) { alert('First Name and Nickname are required.'); return; }
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action:    'knowly_admin_add_child',
                    nonce:     _parentNonce,
                    parent_id: parentId,
                    first_name: fn,
                    last_name:  ln,
                    nickname:   nick,
                    level:      level,
                    period:     period,
                    avatar_index: avatar,
                }, r => {
                    btn.disabled = false;
                    if (r.success) {
                        $s.text('✓ Child created — reloading…').css('color', '#16a34a');
                        setTimeout(() => location.reload(), 800);
                    } else {
                        $s.text(r.data?.message || 'Error').css('color', '#dc2626');
                    }
                });
            },
            removeChild(parentId, childId, childName) {
                if (!confirm('Permanently delete ' + childName + '?\n\nAny gems they hold will be refunded to the parent. This cannot be undone.')) return;
                jQuery.post(ajaxurl, {
                    action:    'knowly_admin_remove_child',
                    nonce:     _parentNonce,
                    parent_id: parentId,
                    child_id:  childId,
                }, r => {
                    if (r.success) {
                        var row = document.getElementById('child-row-parent-' + parentId + '-' + childId);
                        if (row) row.remove();
                        var bal = r.data?.parent_balance;
                        if (bal !== undefined) document.getElementById('parent-gem-' + parentId).textContent = bal;
                        alert(childName + ' removed. ' + (r.data?.refunded > 0 ? r.data.refunded + ' gems refunded to parent.' : ''));
                    } else {
                        alert(r.data?.message || 'Error');
                    }
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
        $nonce    = wp_create_nonce( 'knowly_admin_nonce' );

        // Batch-load level/period/avatar from the knowly_children DB table (NOT user meta)
        $child_db_rows = $wpdb->get_results(
            "SELECT child_id, level, period, avatar_index FROM {$wpdb->prefix}knowly_children",
            ARRAY_A
        ) ?: [];
        $child_db = [];
        foreach ( $child_db_rows as $row ) {
            $child_db[ (int) $row['child_id'] ] = $row;
        }
        ?>
        <p style="margin-bottom:10px;">
            <input type="search" id="knowly-child-search" placeholder="Filter children…" class="regular-text" style="height:30px;">
        </p>

        <div id="knowly-children-list">
        <?php foreach ( $children as $child ) :
            $nickname    = get_user_meta( $child->ID, 'knowly_nickname', true );
            $gem_balance = (int) get_user_meta( $child->ID, 'knowly_gem_balance', true );
            $parent_id   = (int) get_user_meta( $child->ID, 'knowly_parent_id', true );
            $is_test     = (bool) get_user_meta( $child->ID, 'knowly_is_test_account', true );
            $parent      = $parent_id ? get_userdata( $parent_id ) : null;
            $first_name  = get_user_meta( $child->ID, 'first_name', true );
            $last_name   = get_user_meta( $child->ID, 'last_name', true );

            // Level/period come from knowly_children DB table
            $db      = $child_db[ $child->ID ] ?? [];
            $level   = $db['level'] ?? '';
            $period  = $db['period'] ?? '';
            $avatar  = (int) ( $db['avatar_index'] ?? 1 );

            $level_label  = $level ? strtoupper( $level ) : 'SEA';
            $period_label = $period ? strtoupper( str_replace( '_', ' ', $period ) ) : '';

            $trial_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_sessions WHERE child_id = %d", $child->ID
            ) );
            $earned_badges = json_decode( get_user_meta( $child->ID, 'knowly_earned_badges', true ) ?: '[]', true );
            $quest_count   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_quest_sessions WHERE child_id = %d AND state = 'completed'",
                $child->ID
            ) );
        ?>
        <div class="knowly-child-entry"
             style="border:1px solid #c3c4c7;border-radius:4px;margin-bottom:6px;background:#fff;"
             data-search="<?= esc_attr( strtolower( $child->display_name . ' ' . $first_name . ' ' . $last_name . ' ' . ( $nickname ?? '' ) ) ) ?>">

            <!-- Child header -->
            <div style="display:flex;align-items:center;gap:12px;padding:8px 14px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <strong>#<?= esc_html( $child->ID ) ?> <?= esc_html( $child->display_name ) ?></strong>
                    <?php if ( $nickname ) : ?>
                        <span style="font-size:11px;color:#6366f1;margin-left:4px;">@<?= esc_html( $nickname ) ?></span>
                    <?php endif; ?>
                    <?php if ( $is_test ) : ?><span class="knowly-badge warn" style="margin-left:4px;">test</span><?php endif; ?>
                    <div style="font-size:11px;color:#666;margin-top:2px;">
                        <?= esc_html( $level_label ) ?><?= $period_label ? ' · ' . esc_html( $period_label ) : '' ?>
                        · <?= $parent ? esc_html( $parent->display_name ) : '<em>No parent</em>' ?>
                    </div>
                </div>
                <div style="display:flex;gap:14px;align-items:center;font-size:13px;flex-wrap:wrap;">
                    <span><?= esc_html( $gem_balance ) ?> 💎</span>
                    <span style="color:#666;"><?= esc_html( $trial_count ) ?> trial<?= $trial_count !== 1 ? 's' : '' ?></span>
                    <span style="color:#666;"><?= esc_html( $quest_count ) ?> quest<?= $quest_count !== 1 ? 's' : '' ?></span>
                </div>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <button class="button button-small knowly-toggle-child-edit"
                        data-child-id="<?= (int) $child->ID ?>"
                        style="color:#6366f1;border-color:#6366f1;">
                        Settings ▾
                    </button>
                    <?php if ( $trial_count > 0 || $quest_count > 0 ) : ?>
                    <button class="button button-small knowly-toggle-child-history"
                        data-child-id="<?= (int) $child->ID ?>"
                        style="color:#2563eb;border-color:#2563eb;">
                        History ▾
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Child settings panel -->
            <div class="knowly-child-edit-panel" id="child-edit-<?= (int) $child->ID ?>"
                 style="display:none;border-top:1px solid #e5e7eb;background:#f0f4ff;padding:10px 14px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;font-size:12px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Nickname <span style="color:#888;font-weight:400;">(read-only)</span></label>
                        <input type="text" value="<?= esc_attr( $nickname ) ?>" disabled style="width:100%;height:28px;font-size:12px;background:#f3f4f6;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">First Name</label>
                        <input type="text" class="regular-text" id="child-fn-<?= (int) $child->ID ?>"
                            value="<?= esc_attr( $first_name ) ?>" style="width:100%;height:28px;font-size:12px;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Last Name</label>
                        <input type="text" class="regular-text" id="child-ln-<?= (int) $child->ID ?>"
                            value="<?= esc_attr( $last_name ) ?>" style="width:100%;height:28px;font-size:12px;">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Standard (Level)</label>
                        <select id="child-level-<?= (int) $child->ID ?>" style="width:100%;height:28px;font-size:12px;">
                            <?php foreach ( [ 'std_1' => 'Standard 1', 'std_2' => 'Standard 2', 'std_3' => 'Standard 3', 'std_4' => 'Standard 4', 'std_5' => 'Standard 5' ] as $v => $l ) : ?>
                            <option value="<?= esc_attr( $v ) ?>" <?= $level === $v ? 'selected' : '' ?>><?= esc_html( $l ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Term (Period)</label>
                        <select id="child-period-<?= (int) $child->ID ?>" style="width:100%;height:28px;font-size:12px;">
                            <option value="" <?= $period === '' ? 'selected' : '' ?>>SEA (No Term)</option>
                            <option value="term_1" <?= $period === 'term_1' ? 'selected' : '' ?>>Term 1</option>
                            <option value="term_2" <?= $period === 'term_2' ? 'selected' : '' ?>>Term 2</option>
                            <option value="term_3" <?= $period === 'term_3' ? 'selected' : '' ?>>Term 3</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:3px;">Avatar (1–5)</label>
                        <input type="number" min="1" max="5" id="child-avatar-<?= (int) $child->ID ?>"
                            value="<?= esc_attr( $avatar ) ?>" style="width:100%;height:28px;font-size:12px;">
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <button class="button button-small button-primary"
                        onclick="knowlyChildren.saveSettings(<?= (int) $child->ID ?>, this)">Save</button>
                    <span class="child-save-status-<?= (int) $child->ID ?>" style="margin-left:8px;font-size:11px;"></span>
                </div>
            </div>

            <!-- History panel -->
            <?php if ( $trial_count > 0 || $quest_count > 0 ) : ?>
            <div class="knowly-child-history-panel" id="child-history-<?= (int) $child->ID ?>"
                 style="display:none;border-top:1px solid #e5e7eb;background:#f9fafb;padding:10px 14px;">

                <?php if ( $trial_count > 0 ) : ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <strong style="font-size:13px;">Trial History (<?= esc_html( $trial_count ) ?>)</strong>
                    <div style="display:flex;gap:6px;">
                        <button class="button button-small" style="color:#d97706;border-color:#d97706;"
                            onclick="knowlyChildren.resetPackHistory(<?= (int) $child->ID ?>, this)">Reset Pack History</button>
                        <button class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                            onclick="knowlyChildren.deleteHistory(<?= (int) $child->ID ?>, 'trials', this)">Remove All Trials</button>
                    </div>
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
                        <tr><th>Subject</th><th>Level</th><th>Type</th>
                        <th style="text-align:center;">Score</th><th style="text-align:center;">%</th>
                        <th>Status</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $sessions as $s ) :
                        $pct_color = (float) ( $s['percentage'] ?? 0 ) >= 70 ? '#16a34a' : ( (float) ( $s['percentage'] ?? 0 ) >= 50 ? '#d97706' : '#dc2626' );
                    ?>
                    <tr>
                        <td><strong><?= esc_html( $s['subject'] ) ?></strong></td>
                        <td><?= esc_html( strtoupper( $s['level'] ?? '' ) ) ?><?= ( $s['period'] ?? '' ) ? ' · ' . esc_html( strtoupper( str_replace( '_', ' ', $s['period'] ) ) ) : ' · SEA' ?></td>
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
                <?php
                $quest_sessions = $wpdb->get_results( $wpdb->prepare(
                    "SELECT quest_session_id, quest_id, source, state, started_at, completed_at
                     FROM {$wpdb->prefix}knowly_quest_sessions
                     WHERE child_id = %d ORDER BY started_at DESC LIMIT 50",
                    $child->ID
                ), ARRAY_A ) ?: [];
                // Build a badge lookup map for quick display
                $badge_map = [];
                if ( is_array( $earned_badges ) ) {
                    foreach ( $earned_badges as $b ) {
                        $badge_map[ $b['quest_id'] ?? '' ] = $b;
                    }
                }
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <strong style="font-size:13px;">Quest History (<?= esc_html( $quest_count ) ?>)</strong>
                    <button class="button button-small" style="color:#dc2626;border-color:#dc2626;"
                        onclick="knowlyChildren.deleteHistory(<?= (int) $child->ID ?>, 'quests', this)">Remove All Quests</button>
                </div>
                <table class="knowly-table" style="font-size:12px;margin-bottom:12px;">
                    <thead><tr><th>Quest ID</th><th>Source</th><th>Status</th><th>Badge</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ( $quest_sessions as $qs ) :
                        $has_badge = isset( $badge_map[ $qs['quest_id'] ] );
                    ?>
                    <tr>
                        <td><?= esc_html( $qs['quest_id'] ) ?></td>
                        <td><?= esc_html( ucfirst( $qs['source'] ) ) ?></td>
                        <td><?= esc_html( ucfirst( $qs['state'] ) ) ?></td>
                        <td><?= $has_badge ? '<span style="color:#16a34a;font-weight:600;">✓ Earned</span>' : '—' ?></td>
                        <td style="color:#888;"><?= esc_html( substr( $qs['completed_at'] ?? $qs['started_at'], 0, 10 ) ) ?></td>
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
        document.querySelectorAll('.knowly-toggle-child-edit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = document.getElementById('child-edit-' + this.dataset.childId);
                if (!panel) return;
                var open = panel.style.display !== 'none';
                panel.style.display = open ? 'none' : 'block';
                this.textContent = open ? 'Settings ▾' : 'Settings ▴';
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
            saveSettings(childId, btn) {
                var $s = jQuery('.child-save-status-' + childId);
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action:       'knowly_admin_update_child',
                    nonce:        _childNonce,
                    child_id:     childId,
                    first_name:   jQuery('#child-fn-'     + childId).val().trim(),
                    last_name:    jQuery('#child-ln-'     + childId).val().trim(),
                    level:        jQuery('#child-level-'  + childId).val(),
                    period:       jQuery('#child-period-' + childId).val(),
                    avatar_index: jQuery('#child-avatar-' + childId).val(),
                }, r => {
                    btn.disabled = false;
                    $s.text(r.success ? '✓ Saved' : (r.data?.message || 'Error')).css('color', r.success ? '#16a34a' : '#dc2626');
                    setTimeout(() => $s.text(''), 3000);
                    if (r.success) location.reload();
                });
            },
            deleteHistory(childId, type, btn) {
                var label = type === 'quests' ? 'quest badges' : 'trial sessions';
                if (!confirm('Delete all ' + label + ' for this child? This cannot be undone.')) return;
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action:   'knowly_admin_delete_child_history',
                    nonce:    _childNonce,
                    child_id: childId,
                    type:     type,
                }, r => {
                    btn.disabled = false;
                    if (r.success) { alert('History removed.'); location.reload(); }
                    else { alert(r.data?.message || 'Error'); }
                });
            },
            resetPackHistory(childId, btn) {
                if (!confirm('Reset pack history for this child?\n\nThis clears their progress through the sequential pack queue — they will restart from Pack #1 on all branches.\n\nThis cannot be undone.')) return;
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action:   'knowly_admin_reset_pack_history',
                    nonce:    _childNonce,
                    child_id: childId,
                }, r => {
                    btn.disabled = false;
                    if (r.success) {
                        alert('Pack history reset. ' + (r.data?.deleted || 0) + ' record(s) cleared.');
                        location.reload();
                    } else {
                        alert(r.data?.message || 'Error resetting pack history.');
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

    // ── AJAX: Update child ────────────────────────────────────────────────────

    public static function ajax_update_child(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $child_id    = (int) ( $_POST['child_id']    ?? 0 );
        $first_name  = sanitize_text_field( $_POST['first_name']  ?? '' );
        $last_name   = sanitize_text_field( $_POST['last_name']   ?? '' );
        $level       = sanitize_text_field( $_POST['level']       ?? '' );
        $period      = sanitize_text_field( $_POST['period']      ?? '' );
        $avatar      = max( 1, min( 5, (int) ( $_POST['avatar_index'] ?? 1 ) ) );

        if ( ! $child_id || ! get_userdata( $child_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid child.' ] );
        }

        $allowed_levels  = [ 'std_1', 'std_2', 'std_3', 'std_4', 'std_5' ];
        $allowed_periods = [ '', 'term_1', 'term_2', 'term_3' ];

        if ( $level && ! in_array( $level, $allowed_levels, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid level.' ] );
        }
        if ( ! in_array( $period, $allowed_periods, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid period.' ] );
        }

        // Update WP user fields
        $update_data = [ 'ID' => $child_id ];
        if ( $first_name ) {
            $update_data['first_name']    = $first_name;
            $update_data['display_name']  = $first_name;
        }
        if ( $last_name !== '' ) {
            $update_data['last_name'] = $last_name;
        }
        wp_update_user( $update_data );

        // Update avatar in user meta
        update_user_meta( $child_id, 'knowly_avatar_index', $avatar );

        // Update level/period/avatar in knowly_children table
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'knowly_children',
            [
                'display_name' => $first_name ?: get_user_meta( $child_id, 'first_name', true ),
                'level'        => $level,
                'period'       => $period,
                'avatar_index' => $avatar,
            ],
            [ 'child_id' => $child_id ]
        );

        Knowly_Debug::log( 'admin.users', 'Child updated via admin', [
            'child_id' => $child_id,
            'level'    => $level,
            'period'   => $period,
        ], null, 'info' );

        wp_send_json_success();
    }

    // ── AJAX: Remove gems from parent ─────────────────────────────────────────

    public static function ajax_remove_gems(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        $amount  = max( 1, (int) ( $_POST['amount'] ?? 0 ) );

        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid user.' ] );
        }

        $balance_before = (int) get_user_meta( $user_id, 'knowly_gem_balance', true );
        $balance_after  = max( 0, $balance_before - $amount );
        update_user_meta( $user_id, 'knowly_gem_balance', $balance_after );

        Knowly_Debug::log( 'admin.users', 'Gems removed from parent', [
            'user_id' => $user_id,
            'amount'  => $amount,
        ], null, 'info' );

        wp_send_json_success( [ 'balance_after' => $balance_after ] );
    }

    // ── AJAX: Add child to parent ─────────────────────────────────────────────

    public static function ajax_add_child(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        if ( ! $parent_id || ! get_userdata( $parent_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid parent.' ] );
        }

        // Auto-generate a secure password — admin doesn't need to know it
        $password = wp_generate_password( 16, true );

        $result = Knowly_Children_Service::create_child( $parent_id, [
            'first_name'   => sanitize_text_field( $_POST['first_name']   ?? '' ),
            'last_name'    => sanitize_text_field( $_POST['last_name']    ?? '' ),
            'nickname'     => sanitize_text_field( $_POST['nickname']     ?? '' ),
            'password'     => $password,
            'level'        => sanitize_text_field( $_POST['level']        ?? '' ),
            'period'       => sanitize_text_field( $_POST['period']       ?? '' ),
            'avatar_index' => max( 1, min( 5, (int) ( $_POST['avatar_index'] ?? 1 ) ) ),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.users', 'Child added via admin', [
            'parent_id' => $parent_id,
            'child_id'  => $result['id'] ?? 0,
        ], null, 'info' );

        wp_send_json_success( [ 'child' => $result ] );
    }

    // ── AJAX: Remove child from system ────────────────────────────────────────

    public static function ajax_remove_child(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $child_id  = (int) ( $_POST['child_id']  ?? 0 );

        if ( ! $child_id || ! get_userdata( $child_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid child.' ] );
        }

        // Refund child's gem balance to parent
        $child_gems    = (int) get_user_meta( $child_id, 'knowly_gem_balance', true );
        $parent_gems   = (int) get_user_meta( $parent_id, 'knowly_gem_balance', true );
        $parent_after  = $parent_gems;

        if ( $child_gems > 0 && $parent_id ) {
            $parent_after = $parent_gems + $child_gems;
            update_user_meta( $parent_id, 'knowly_gem_balance', $parent_after );
        }

        // Remove from knowly_children table
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'knowly_children', [ 'child_id' => $child_id ] );

        // Remove from any class memberships
        $wpdb->update(
            $wpdb->prefix . 'knowly_class_members',
            [ 'status' => 'removed' ],
            [ 'child_id' => $child_id ]
        );

        // Remove exam history
        $wpdb->delete( $wpdb->prefix . 'knowly_exam_sessions', [ 'child_id' => $child_id ] );

        // Delete WP user
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $child_id, $parent_id );

        Knowly_Debug::log( 'admin.users', 'Child deleted via admin', [
            'parent_id'    => $parent_id,
            'child_id'     => $child_id,
            'gems_refunded' => $child_gems,
        ], null, 'info' );

        wp_send_json_success( [
            'refunded'       => $child_gems,
            'parent_balance' => $parent_after,
        ] );
    }

    // ── AJAX: Reset pack history ──────────────────────────────────────────────

    public static function ajax_reset_pack_history(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $child_id   = (int) ( $_POST['child_id'] ?? 0 );
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $child_id ) {
            wp_send_json_error( [ 'message' => 'Invalid child.' ] );
        }
        if ( ! $endpoint ) {
            wp_send_json_error( [ 'message' => 'Railway endpoint not configured.' ] );
        }

        $resp = wp_remote_request(
            $endpoint . '/api/v1/trial/child-history?' . http_build_query( [ 'child_id' => $child_id ] ),
            [
                'method'  => 'DELETE',
                'timeout' => 15,
                'headers' => [ 'X-AEP-Server-Key' => $server_key ],
            ]
        );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
        }

        $code   = wp_remote_retrieve_response_code( $resp );
        $parsed = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code >= 400 ) {
            wp_send_json_error( [ 'message' => $parsed['error'] ?? "HTTP {$code}" ] );
        }

        Knowly_Debug::log( 'admin.users', 'Child pack history reset via admin', [
            'child_id' => $child_id,
            'deleted'  => $parsed['deleted'] ?? 0,
        ], null, 'info' );

        wp_send_json_success( $parsed );
    }

    // ── AJAX: Update parent ───────────────────────────────────────────────────

    public static function ajax_update_parent(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $user_id    = (int) ( $_POST['user_id']    ?? 0 );
        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name']  ?? '' );

        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid user.' ] );
        }

        $update_data = [ 'ID' => $user_id ];
        if ( $first_name ) $update_data['first_name'] = $first_name;
        if ( $last_name !== '' )  $update_data['last_name']  = $last_name;
        if ( $first_name || $last_name ) {
            $fn = $first_name ?: get_user_meta( $user_id, 'first_name', true );
            $ln = $last_name  ?: get_user_meta( $user_id, 'last_name',  true );
            $update_data['display_name'] = trim( $fn . ' ' . $ln );
        }

        wp_update_user( $update_data );

        Knowly_Debug::log( 'admin.users', 'Parent updated via admin', [ 'user_id' => $user_id ], null, 'info' );

        wp_send_json_success();
    }
}
