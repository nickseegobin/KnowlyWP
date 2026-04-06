<?php
/**
 * Knowly_Admin_Members — Member & Auth management admin page.
 *
 * Features:
 *  - View all parent accounts with token balance, PIN status, child count
 *  - Expand parent row to see linked children
 *  - Create parent account
 *  - Add / remove children from a parent
 *  - Reset parent PIN
 *  - Send password recovery email
 *  - View a child's exam history (sessions + scores)
 *  - Credit tokens to a parent account
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Members {

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_members_create_parent',  [ __CLASS__, 'ajax_create_parent' ] );
        add_action( 'wp_ajax_knowly_members_add_child',      [ __CLASS__, 'ajax_add_child' ] );
        add_action( 'wp_ajax_knowly_members_remove_child',   [ __CLASS__, 'ajax_remove_child' ] );
        add_action( 'wp_ajax_knowly_members_reset_pin',      [ __CLASS__, 'ajax_reset_pin' ] );
        add_action( 'wp_ajax_knowly_members_send_recovery',  [ __CLASS__, 'ajax_send_recovery' ] );
        add_action( 'wp_ajax_knowly_members_child_exams',    [ __CLASS__, 'ajax_child_exams' ] );
        add_action( 'wp_ajax_knowly_members_credit_tokens',  [ __CLASS__, 'ajax_credit_tokens' ] );
        add_action( 'wp_ajax_knowly_members_load_children',  [ __CLASS__, 'ajax_load_children' ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $parents = get_users( [ 'role' => 'knowly_parent', 'orderby' => 'registered', 'order' => 'DESC' ] );
        $total_parents  = count( $parents );
        $total_children = (int) ( new WP_User_Query( [ 'role' => 'knowly_child', 'count_total' => true, 'number' => 0 ] ) )->get_total();
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI — Members</h1>

            <!-- Stats -->
            <div class="knowly-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $total_parents ) ?></div>
                    <div class="knowly-stat-label">Parent Accounts</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number"><?= esc_html( $total_children ) ?></div>
                    <div class="knowly-stat-label">Student Profiles</div>
                </div>
                <div class="knowly-stat-card">
                    <div class="knowly-stat-number" id="knowly-members-filtered-count"><?= esc_html( $total_parents ) ?></div>
                    <div class="knowly-stat-label">Showing</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;">
                <input type="text" id="knowly-member-search"
                    placeholder="Search by name or email…"
                    class="regular-text" style="height:34px;max-width:320px;" />
                <button id="knowly-create-parent-btn" class="button button-primary">+ Create Parent Account</button>
            </div>

            <!-- Parents table -->
            <div class="knowly-settings-section" style="padding:0;overflow:hidden;">
                <table class="knowly-table knowly-members-table" style="border:none;border-radius:0;">
                    <thead>
                        <tr>
                            <th style="width:28px;"></th>
                            <th>Name</th>
                            <th>Email</th>
                            <th style="text-align:center;">Tokens</th>
                            <th style="text-align:center;">Children</th>
                            <th style="text-align:center;">PIN</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $parents as $parent ) :
                        $balance      = (int) get_user_meta( $parent->ID, 'knowly_token_balance', true );
                        $pin_hash     = get_user_meta( $parent->ID, 'knowly_pin_hash', true );
                        $pin_locked   = (int) get_user_meta( $parent->ID, 'knowly_pin_locked_until', true );
                        $is_locked    = $pin_locked && time() < $pin_locked;
                        $child_count  = Knowly_Children_Service::child_count( $parent->ID );
                        $pin_label    = $is_locked ? 'Locked' : ( $pin_hash ? 'Set' : 'None' );
                        $pin_color    = $is_locked ? '#dc2626' : ( $pin_hash ? '#16a34a' : '#9ca3af' );
                    ?>
                    <tr class="knowly-member-row"
                        data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                        data-name="<?= esc_attr( strtolower( $parent->display_name ) ) ?>"
                        data-email="<?= esc_attr( strtolower( $parent->user_email ) ) ?>">
                        <td style="text-align:center;cursor:pointer;" class="knowly-expand-toggle" title="Show children">
                            <span class="knowly-arrow" style="font-size:10px;color:#888;display:inline-block;transition:transform .2s;">▶</span>
                        </td>
                        <td>
                            <strong><?= esc_html( $parent->display_name ) ?></strong>
                            <div style="font-size:11px;color:#888;">ID: <?= esc_html( $parent->ID ) ?> · @<?= esc_html( $parent->user_login ) ?></div>
                        </td>
                        <td><?= esc_html( $parent->user_email ) ?></td>
                        <td style="text-align:center;font-weight:700;color:#2563eb;"><?= esc_html( $balance ) ?></td>
                        <td style="text-align:center;"><?= esc_html( $child_count ) ?></td>
                        <td style="text-align:center;">
                            <span style="color:<?= $pin_color ?>;font-weight:600;font-size:11px;">● <?= esc_html( $pin_label ) ?></span>
                        </td>
                        <td style="font-size:12px;color:#666;"><?= esc_html( date_i18n( 'M j, Y', strtotime( $parent->user_registered ) ) ) ?></td>
                        <td>
                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                <button class="button button-small knowly-reset-pin"
                                    data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                    data-name="<?= esc_attr( $parent->display_name ) ?>"
                                    title="Clear PIN so parent can set a new one">
                                    Reset PIN
                                </button>
                                <button class="button button-small knowly-send-recovery"
                                    data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                    data-email="<?= esc_attr( $parent->user_email ) ?>">
                                    Send Recovery
                                </button>
                                <button class="button button-small knowly-credit-tokens"
                                    data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                    data-name="<?= esc_attr( $parent->display_name ) ?>"
                                    data-balance="<?= esc_attr( $balance ) ?>">
                                    Credit Tokens
                                </button>
                            </div>
                        </td>
                    </tr>
                    <!-- Children drawer row -->
                    <tr class="knowly-children-row" data-parent-id="<?= esc_attr( $parent->ID ) ?>" style="display:none;">
                        <td colspan="8" style="padding:0;background:#f9fafb;">
                            <div class="knowly-children-panel" style="padding:14px 20px 14px 48px;">
                                <div class="knowly-children-content" data-loaded="0">
                                    <p style="color:#888;font-size:12px;margin:0;">Loading…</p>
                                </div>
                                <div style="margin-top:10px;">
                                    <button class="button button-small knowly-add-child-btn"
                                        data-parent-id="<?= esc_attr( $parent->ID ) ?>"
                                        data-parent-name="<?= esc_attr( $parent->display_name ) ?>"
                                        <?= $child_count >= KNOWLY_MAX_CHILDREN ? 'disabled title="Max ' . KNOWLY_MAX_CHILDREN . ' children reached"' : '' ?>>
                                        + Add Child
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( empty( $parents ) ) : ?>
                    <p style="padding:20px;color:#888;text-align:center;">No parent accounts found.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Create Parent Modal ─────────────────────────────────────────── -->
        <div id="knowly-create-parent-modal" class="knowly-modal-overlay" style="display:none;">
            <div class="knowly-modal-box" style="max-width:480px;">
                <div class="knowly-modal-header">
                    <h2>Create Parent Account</h2>
                    <button class="button knowly-modal-close">✕</button>
                </div>
                <div class="knowly-modal-body">
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th>Display Name</th>
                            <td><input type="text" id="cp-display-name" class="regular-text" placeholder="Jane Smith" /></td>
                        </tr>
                        <tr>
                            <th>Username</th>
                            <td><input type="text" id="cp-username" class="regular-text" placeholder="janesmith" /></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><input type="email" id="cp-email" class="regular-text" placeholder="jane@example.com" /></td>
                        </tr>
                        <tr>
                            <th>Password</th>
                            <td><input type="password" id="cp-password" class="regular-text" placeholder="Temporary password" /></td>
                        </tr>
                        <tr>
                            <th>Initial Tokens</th>
                            <td><input type="number" id="cp-tokens" class="small-text" value="3" min="0" /></td>
                        </tr>
                    </table>
                    <div id="cp-error" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></div>
                </div>
                <div class="knowly-modal-footer">
                    <button id="cp-submit" class="button button-primary">Create Account</button>
                    <button class="button knowly-modal-close">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ── Add Child Modal ─────────────────────────────────────────────── -->
        <div id="knowly-add-child-modal" class="knowly-modal-overlay" style="display:none;">
            <div class="knowly-modal-box" style="max-width:480px;">
                <div class="knowly-modal-header">
                    <h2>Add Child to <span id="ac-parent-name"></span></h2>
                    <button class="button knowly-modal-close">✕</button>
                </div>
                <div class="knowly-modal-body">
                    <input type="hidden" id="ac-parent-id" value="" />
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th>Display Name</th>
                            <td><input type="text" id="ac-display-name" class="regular-text" placeholder="Alex" /></td>
                        </tr>
                        <tr>
                            <th>Username</th>
                            <td><input type="text" id="ac-username" class="regular-text" placeholder="alex_smith" /></td>
                        </tr>
                        <tr>
                            <th>Password</th>
                            <td><input type="password" id="ac-password" class="regular-text" placeholder="Child account password" /></td>
                        </tr>
                        <tr>
                            <th>Standard</th>
                            <td>
                                <select id="ac-standard" class="regular-text">
                                    <option value="std_4">Standard 4</option>
                                    <option value="std_5">Standard 5 (SEA)</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="ac-term-row">
                            <th>Term</th>
                            <td>
                                <select id="ac-term" class="regular-text">
                                    <option value="term_1">Term 1</option>
                                    <option value="term_2">Term 2</option>
                                    <option value="term_3">Term 3</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Age</th>
                            <td><input type="number" id="ac-age" class="small-text" placeholder="9" min="5" max="18" /></td>
                        </tr>
                    </table>
                    <div id="ac-error" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></div>
                </div>
                <div class="knowly-modal-footer">
                    <button id="ac-submit" class="button button-primary">Add Child</button>
                    <button class="button knowly-modal-close">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ── Credit Tokens Modal ─────────────────────────────────────────── -->
        <div id="knowly-credit-modal" class="knowly-modal-overlay" style="display:none;">
            <div class="knowly-modal-box" style="max-width:360px;">
                <div class="knowly-modal-header">
                    <h2>Credit Tokens — <span id="ct-parent-name"></span></h2>
                    <button class="button knowly-modal-close">✕</button>
                </div>
                <div class="knowly-modal-body">
                    <input type="hidden" id="ct-parent-id" value="" />
                    <p style="font-size:13px;margin:0 0 12px;">Current balance: <strong id="ct-current-balance"></strong> tokens</p>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Tokens to add</label>
                    <input type="number" id="ct-amount" class="regular-text" value="5" min="1" max="1000" />
                    <div id="ct-error" style="color:#dc2626;font-size:12px;margin-top:8px;display:none;"></div>
                </div>
                <div class="knowly-modal-footer">
                    <button id="ct-submit" class="button button-primary">Credit Tokens</button>
                    <button class="button knowly-modal-close">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ── Child Exams Modal ───────────────────────────────────────────── -->
        <div id="knowly-exams-modal" class="knowly-modal-overlay" style="display:none;">
            <div class="knowly-modal-box" style="max-width:860px;">
                <div class="knowly-modal-header">
                    <h2>Exam History — <span id="ex-child-name"></span></h2>
                    <button class="button knowly-modal-close">✕</button>
                </div>
                <div id="ex-body" class="knowly-modal-body" style="max-height:65vh;overflow:auto;padding:20px;"></div>
            </div>
        </div>

        <style>
        .knowly-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 99999;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 60px;
            overflow: auto;
        }
        .knowly-modal-box {
            background: #fff;
            width: 100%;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }
        .knowly-modal-header {
            padding: 14px 20px;
            background: #f6f7f7;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .knowly-modal-header h2 { margin: 0; font-size: 15px; }
        .knowly-modal-body { padding: 20px; }
        .knowly-modal-footer {
            padding: 12px 20px;
            background: #f6f7f7;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 8px;
        }
        .knowly-member-row td { vertical-align: middle; }
        .knowly-children-row td { border-top: none !important; }
        .knowly-member-row.is-open .knowly-arrow { transform: rotate(90deg); }
        .knowly-child-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            margin-bottom: 8px;
            gap: 12px;
        }
        .knowly-child-card .knowly-child-info { flex: 1; }
        .knowly-child-card .knowly-child-name { font-weight: 600; font-size: 13px; }
        .knowly-child-card .knowly-child-meta { font-size: 11px; color: #888; margin-top: 2px; }
        </style>

        <script>
        (function($) {
            var nonce = '<?= wp_create_nonce( 'knowly_admin_nonce' ) ?>';

            // ── Search / filter ───────────────────────────────────────────────
            $('#knowly-member-search').on('input', function() {
                var q = $(this).val().toLowerCase();
                var visible = 0;
                $('.knowly-member-row').each(function() {
                    var match = !q || $(this).data('name').indexOf(q) >= 0 || $(this).data('email').indexOf(q) >= 0;
                    $(this).toggle(match);
                    var pid = $(this).data('parent-id');
                    $('.knowly-children-row[data-parent-id="' + pid + '"]').toggle(match && $(this).hasClass('is-open'));
                    if (match) visible++;
                });
                $('#knowly-members-filtered-count').text(visible);
            });

            // ── Expand/collapse children row ──────────────────────────────────
            $(document).on('click', '.knowly-expand-toggle, .knowly-member-row td:not(:last-child)', function(e) {
                if ($(e.target).is('button, input, a')) return;
                var $row    = $(this).closest('.knowly-member-row');
                var pid     = $row.data('parent-id');
                var $drawer = $('.knowly-children-row[data-parent-id="' + pid + '"]');
                var $panel  = $drawer.find('.knowly-children-content');
                var isOpen  = $row.hasClass('is-open');

                $row.toggleClass('is-open', !isOpen);
                $drawer.toggle(!isOpen);

                if (!isOpen && $panel.data('loaded') == 0) {
                    $panel.data('loaded', 1);
                    loadChildren(pid, $panel);
                }
            });

            function loadChildren(parentId, $panel) {
                $.post(ajaxurl, { action: 'knowly_members_load_children', nonce: nonce, parent_id: parentId },
                    function(res) {
                        if (res.success) {
                            $panel.html(res.data.html);
                        } else {
                            $panel.html('<p style="color:#dc2626;font-size:12px;">Failed to load children.</p>');
                        }
                    }
                );
            }

            // ── Modal helpers ─────────────────────────────────────────────────
            function openModal(id) { $('#' + id).fadeIn(150); }
            function closeAllModals() { $('.knowly-modal-overlay').fadeOut(150); }

            $(document).on('click', '.knowly-modal-close', closeAllModals);
            $(document).on('click', '.knowly-modal-overlay', function(e) {
                if ($(e.target).hasClass('knowly-modal-overlay')) closeAllModals();
            });

            // ── Create Parent ─────────────────────────────────────────────────
            $('#knowly-create-parent-btn').on('click', function() {
                $('#cp-display-name,#cp-username,#cp-email,#cp-password').val('');
                $('#cp-tokens').val(3);
                $('#cp-error').hide();
                openModal('knowly-create-parent-modal');
            });

            $('#cp-submit').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Creating…');
                $('#cp-error').hide();

                $.post(ajaxurl, {
                    action:       'knowly_members_create_parent',
                    nonce:        nonce,
                    display_name: $('#cp-display-name').val(),
                    username:     $('#cp-username').val(),
                    email:        $('#cp-email').val(),
                    password:     $('#cp-password').val(),
                    tokens:       $('#cp-tokens').val()
                }, function(res) {
                    $btn.prop('disabled', false).text('Create Account');
                    if (res.success) {
                        closeAllModals();
                        location.reload();
                    } else {
                        $('#cp-error').text(res.data.message).show();
                    }
                });
            });

            // ── Add Child ─────────────────────────────────────────────────────
            $(document).on('click', '.knowly-add-child-btn', function() {
                var pid  = $(this).data('parent-id');
                var name = $(this).data('parent-name');
                $('#ac-parent-id').val(pid);
                $('#ac-parent-name').text(name);
                $('#ac-display-name,#ac-username,#ac-password,#ac-age').val('');
                $('#ac-standard').val('std_4');
                $('#ac-term').val('term_1');
                $('#ac-term-row').show();
                $('#ac-error').hide();
                openModal('knowly-add-child-modal');
            });

            $('#ac-standard').on('change', function() {
                $('#ac-term-row').toggle($(this).val() === 'std_4');
            });

            $('#ac-submit').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Adding…');
                $('#ac-error').hide();
                var std  = $('#ac-standard').val();

                $.post(ajaxurl, {
                    action:       'knowly_members_add_child',
                    nonce:        nonce,
                    parent_id:    $('#ac-parent-id').val(),
                    display_name: $('#ac-display-name').val(),
                    username:     $('#ac-username').val(),
                    password:     $('#ac-password').val(),
                    level:        std,
                    period:       std === 'std_4' ? $('#ac-term').val() : '',
                    age:          $('#ac-age').val()
                }, function(res) {
                    $btn.prop('disabled', false).text('Add Child');
                    if (res.success) {
                        var pid = $('#ac-parent-id').val();
                        closeAllModals();
                        // Reload children panel
                        var $panel = $('.knowly-children-row[data-parent-id="' + pid + '"] .knowly-children-content');
                        $panel.data('loaded', 1);
                        loadChildren(pid, $panel);
                        // Update child count cell
                        var $row = $('.knowly-member-row[data-parent-id="' + pid + '"]');
                        var cnt  = parseInt($row.find('td:eq(4)').text()) + 1;
                        $row.find('td:eq(4)').text(cnt);
                        // Disable add button if at max
                        if (cnt >= <?= KNOWLY_MAX_CHILDREN ?>) {
                            $row.next('.knowly-children-row').find('.knowly-add-child-btn')
                                .prop('disabled', true)
                                .attr('title', 'Max <?= KNOWLY_MAX_CHILDREN ?> children reached');
                        }
                    } else {
                        $('#ac-error').text(res.data.message).show();
                    }
                });
            });

            // ── Remove Child ──────────────────────────────────────────────────
            $(document).on('click', '.knowly-remove-child', function() {
                var $btn     = $(this);
                var childId  = $btn.data('child-id');
                var childName = $btn.data('child-name');
                var parentId = $btn.data('parent-id');

                if (!confirm('Remove "' + childName + '"? This will permanently delete their account and all exam data.')) return;

                $btn.prop('disabled', true).text('Removing…');
                $.post(ajaxurl, {
                    action:    'knowly_members_remove_child',
                    nonce:     nonce,
                    parent_id: parentId,
                    child_id:  childId
                }, function(res) {
                    if (res.success) {
                        var $panel = $('.knowly-children-row[data-parent-id="' + parentId + '"] .knowly-children-content');
                        loadChildren(parentId, $panel);
                        var $row = $('.knowly-member-row[data-parent-id="' + parentId + '"]');
                        var cnt  = Math.max(0, parseInt($row.find('td:eq(4)').text()) - 1);
                        $row.find('td:eq(4)').text(cnt);
                        $row.next('.knowly-children-row').find('.knowly-add-child-btn')
                            .prop('disabled', false)
                            .attr('title', '');
                    } else {
                        alert('Error: ' + (res.data.message || 'Failed to remove child.'));
                        $btn.prop('disabled', false).text('Remove');
                    }
                });
            });

            // ── Reset PIN ─────────────────────────────────────────────────────
            $(document).on('click', '.knowly-reset-pin', function() {
                var $btn  = $(this);
                var pid   = $btn.data('parent-id');
                var name  = $btn.data('name');

                if (!confirm('Clear PIN for "' + name + '"? They will need to set a new one on next login.')) return;

                $btn.prop('disabled', true).text('Resetting…');
                $.post(ajaxurl, {
                    action:    'knowly_members_reset_pin',
                    nonce:     nonce,
                    parent_id: pid
                }, function(res) {
                    $btn.prop('disabled', false).text('Reset PIN');
                    if (res.success) {
                        // Update PIN badge in row
                        var $row   = $('.knowly-member-row[data-parent-id="' + pid + '"]');
                        $row.find('td:eq(5) span').css('color', '#9ca3af').text('● None');
                        alert('PIN cleared for ' + name + '.');
                    } else {
                        alert('Error: ' + (res.data.message || 'Failed.'));
                    }
                });
            });

            // ── Send Recovery Email ────────────────────────────────────────────
            $(document).on('click', '.knowly-send-recovery', function() {
                var $btn  = $(this);
                var pid   = $btn.data('parent-id');
                var email = $btn.data('email');

                if (!confirm('Send password reset email to ' + email + '?')) return;

                $btn.prop('disabled', true).text('Sending…');
                $.post(ajaxurl, {
                    action:    'knowly_members_send_recovery',
                    nonce:     nonce,
                    parent_id: pid
                }, function(res) {
                    $btn.prop('disabled', false).text('Send Recovery');
                    if (res.success) {
                        alert('Recovery email sent to ' + email + '.');
                    } else {
                        alert('Error: ' + (res.data.message || 'Failed to send email.'));
                    }
                });
            });

            // ── Credit Tokens ─────────────────────────────────────────────────
            $(document).on('click', '.knowly-credit-tokens', function() {
                var pid     = $(this).data('parent-id');
                var name    = $(this).data('name');
                var balance = $(this).data('balance');
                $('#ct-parent-id').val(pid);
                $('#ct-parent-name').text(name);
                $('#ct-current-balance').text(balance);
                $('#ct-amount').val(5);
                $('#ct-error').hide();
                openModal('knowly-credit-modal');
            });

            $('#ct-submit').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Crediting…');
                $('#ct-error').hide();

                $.post(ajaxurl, {
                    action:    'knowly_members_credit_tokens',
                    nonce:     nonce,
                    parent_id: $('#ct-parent-id').val(),
                    amount:    $('#ct-amount').val()
                }, function(res) {
                    $btn.prop('disabled', false).text('Credit Tokens');
                    if (res.success) {
                        var pid         = $('#ct-parent-id').val();
                        var newBalance  = res.data.balance_after;
                        closeAllModals();
                        // Update balance cell and button data
                        var $row = $('.knowly-member-row[data-parent-id="' + pid + '"]');
                        $row.find('td:eq(3)').text(newBalance);
                        $row.find('.knowly-credit-tokens').data('balance', newBalance);
                        alert('Tokens credited. New balance: ' + newBalance);
                    } else {
                        $('#ct-error').text(res.data.message).show();
                    }
                });
            });

            // ── View Child Exams ───────────────────────────────────────────────
            $(document).on('click', '.knowly-view-exams', function() {
                var cid  = $(this).data('child-id');
                var name = $(this).data('child-name');
                var $btn = $(this).prop('disabled', true).text('Loading…');

                $('#ex-child-name').text(name);
                $('#ex-body').html('<p style="color:#888;padding:20px 0;">Loading exam history…</p>');
                openModal('knowly-exams-modal');

                $.post(ajaxurl, {
                    action:   'knowly_members_child_exams',
                    nonce:    nonce,
                    child_id: cid
                }, function(res) {
                    $btn.prop('disabled', false).text('View Exams');
                    if (res.success) {
                        $('#ex-body').html(res.data.html);
                    } else {
                        $('#ex-body').html('<p style="color:#dc2626;">' + (res.data.message || 'Failed to load.') + '</p>');
                    }
                });
            });

        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Load children panel ─────────────────────────────────────────────

    public static function ajax_load_children(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        if ( ! $parent_id ) wp_send_json_error( [ 'message' => 'Invalid parent.' ] );

        $children = Knowly_Children_Service::list_children( $parent_id );

        ob_start();
        if ( empty( $children ) ) {
            echo '<p style="color:#888;font-size:12px;margin:0;">No children linked yet.</p>';
        } else {
            foreach ( $children as $child ) :
                $user     = get_userdata( $child['child_id'] );
                $exams    = self::get_child_session_count( $child['child_id'] );
                $nickname = get_user_meta( $child['child_id'], 'knowly_nickname', true );
            ?>
            <div class="knowly-child-card">
                <div class="knowly-child-info">
                    <div class="knowly-child-name">
                        <?= esc_html( $child['display_name'] ) ?>
                        <?php if ( $nickname ) : ?>
                            <span style="font-size:11px;font-weight:400;color:#6366f1;margin-left:6px;">🏷 <?= esc_html( $nickname ) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="knowly-child-meta">
                        ID: <?= esc_html( $child['child_id'] ) ?>
                        <?= $user ? ' · @' . esc_html( $user->user_login ) : '' ?>
                        · <?= esc_html( strtoupper( $child['level'] ) ) ?>
                        <?= $child['period'] ? ' · ' . esc_html( strtoupper( str_replace( '_', ' ', $child['period'] ) ) ) : ' · SEA' ?>
                        <?= $child['age'] ? ' · Age ' . esc_html( $child['age'] ) : '' ?>
                        · <strong><?= esc_html( $exams ) ?> exam<?= $exams !== 1 ? 's' : '' ?></strong>
                    </div>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="button button-small knowly-view-exams"
                        data-child-id="<?= esc_attr( $child['child_id'] ) ?>"
                        data-child-name="<?= esc_attr( $child['display_name'] ) ?>">
                        View Exams
                    </button>
                    <button class="button button-small knowly-remove-child"
                        data-child-id="<?= esc_attr( $child['child_id'] ) ?>"
                        data-child-name="<?= esc_attr( $child['display_name'] ) ?>"
                        data-parent-id="<?= esc_attr( $parent_id ) ?>"
                        style="color:#dc2626;border-color:#dc2626;">
                        Remove
                    </button>
                </div>
            </div>
            <?php endforeach;
        }
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    // ── AJAX: Create parent ───────────────────────────────────────────────────

    public static function ajax_create_parent(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $username     = sanitize_user( $_POST['username'] ?? '' );
        $display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
        $email        = sanitize_email( $_POST['email'] ?? '' );
        $password     = $_POST['password'] ?? '';
        $tokens       = max( 0, (int) ( $_POST['tokens'] ?? 3 ) );

        if ( ! $username || ! $display_name || ! $email || ! $password ) {
            wp_send_json_error( [ 'message' => 'All fields are required.' ] );
        }
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Invalid email address.' ] );
        }
        if ( username_exists( $username ) ) {
            wp_send_json_error( [ 'message' => 'Username already taken.' ] );
        }
        if ( email_exists( $email ) ) {
            wp_send_json_error( [ 'message' => 'Email already in use.' ] );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
        }

        $user = new WP_User( $user_id );
        $user->set_role( 'knowly_parent' );
        wp_update_user( [ 'ID' => $user_id, 'display_name' => $display_name ] );

        Knowly_Gem_Service::grant_on_registration( $user_id );
        if ( $tokens > 0 ) {
            Knowly_Gem_Service::credit( $user_id, $tokens, 'admin_credit', '', '', 'Initial gem grant' );
        }

        Knowly_Debug::log( 'admin.members', 'Parent account created via admin', [
            'user_id'  => $user_id,
            'username' => $username,
            'tokens'   => $tokens,
        ], null, 'info' );

        wp_send_json_success( [ 'user_id' => $user_id ] );
    }

    // ── AJAX: Add child ───────────────────────────────────────────────────────

    public static function ajax_add_child(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        if ( ! $parent_id || ! get_userdata( $parent_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid parent account.' ] );
        }

        // The admin form sends display_name (child's first name) and username (used as nickname/login).
        // Map these into the fields create_child() expects.
        $display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
        $parts        = explode( ' ', $display_name, 2 );
        $first_name   = $parts[0];
        $last_name    = $parts[1] ?? '';

        $data = [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'nickname'   => sanitize_user( $_POST['username'] ?? '' ),   // username field = WP login / nickname
            'password'   => $_POST['password'] ?? '',
            'level'      => sanitize_text_field( $_POST['level']   ?? ( $_POST['standard'] ?? 'std_4' ) ),
            'period'     => sanitize_text_field( $_POST['period']  ?? ( $_POST['term']     ?? '' ) ),
        ];
        if ( ! empty( $_POST['age'] ) ) {
            $data['age'] = (int) $_POST['age'];
        }

        $result = Knowly_Children_Service::create_child( $parent_id, $data );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.members', 'Child added via admin', [
            'parent_id' => $parent_id,
            'child_id'  => $result['child_id'],
        ], null, 'info' );

        wp_send_json_success( $result );
    }

    // ── AJAX: Remove child ────────────────────────────────────────────────────

    public static function ajax_remove_child(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $child_id  = (int) ( $_POST['child_id']  ?? 0 );

        if ( ! $parent_id || ! $child_id ) {
            wp_send_json_error( [ 'message' => 'Invalid IDs.' ] );
        }

        $result = Knowly_Children_Service::remove_child( $parent_id, $child_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.members', 'Child removed via admin', [
            'parent_id' => $parent_id,
            'child_id'  => $child_id,
        ], null, 'info' );

        wp_send_json_success( [] );
    }

    // ── AJAX: Reset PIN ───────────────────────────────────────────────────────

    public static function ajax_reset_pin(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        if ( ! $parent_id ) wp_send_json_error( [ 'message' => 'Invalid parent.' ] );

        delete_user_meta( $parent_id, 'knowly_pin_hash' );
        delete_user_meta( $parent_id, 'knowly_pin_attempts' );
        delete_user_meta( $parent_id, 'knowly_pin_locked_until' );

        Knowly_Debug::log( 'admin.members', 'PIN reset via admin', [ 'parent_id' => $parent_id ], null, 'info' );

        wp_send_json_success( [] );
    }

    // ── AJAX: Send recovery email ─────────────────────────────────────────────

    public static function ajax_send_recovery(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        if ( ! $parent_id ) wp_send_json_error( [ 'message' => 'Invalid parent.' ] );

        $user = get_userdata( $parent_id );
        if ( ! $user ) wp_send_json_error( [ 'message' => 'User not found.' ] );

        $result = retrieve_password( $user->user_login );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.members', 'Password recovery email sent via admin', [
            'parent_id' => $parent_id,
            'email'     => $user->user_email,
        ], null, 'info' );

        wp_send_json_success( [ 'email' => $user->user_email ] );
    }

    // ── AJAX: Credit tokens ───────────────────────────────────────────────────

    public static function ajax_credit_tokens(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $parent_id = (int) ( $_POST['parent_id'] ?? 0 );
        $amount    = (int) ( $_POST['amount']    ?? 0 );

        if ( ! $parent_id ) wp_send_json_error( [ 'message' => 'Invalid parent.' ] );
        if ( $amount <= 0 ) wp_send_json_error( [ 'message' => 'Amount must be greater than zero.' ] );

        $result = Knowly_Gem_Service::credit( $parent_id, $amount, 'admin_credit', '', '', 'Admin gem credit' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.members', 'Tokens credited via admin', [
            'parent_id' => $parent_id,
            'amount'    => $amount,
            'balance'   => $result['balance_after'],
        ], null, 'info' );

        wp_send_json_success( $result );
    }

    // ── AJAX: Child exam history ──────────────────────────────────────────────

    public static function ajax_child_exams(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $child_id = (int) ( $_POST['child_id'] ?? 0 );
        if ( ! $child_id ) wp_send_json_error( [ 'message' => 'Invalid child.' ] );

        global $wpdb;
        $sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, external_session_id, subject, standard, term, difficulty,
                    score, total, percentage, time_taken_seconds, state, started_at, completed_at
             FROM {$wpdb->prefix}knowly_exam_sessions
             WHERE child_id = %d
             ORDER BY started_at DESC
             LIMIT 100",
            $child_id
        ), ARRAY_A ) ?: [];

        ob_start();
        if ( empty( $sessions ) ) {
            echo '<p style="color:#888;text-align:center;padding:20px 0;">No exams on record.</p>';
        } else {
            $total_exams   = count( $sessions );
            $completed     = array_filter( $sessions, fn($s) => $s['state'] === 'completed' );
            $avg_pct       = count( $completed ) > 0
                ? round( array_sum( array_column( $completed, 'percentage' ) ) / count( $completed ), 1 )
                : 0;
            ?>
            <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 16px;text-align:center;min-width:100px;">
                    <div style="font-size:20px;font-weight:700;color:#0369a1;"><?= esc_html( $total_exams ) ?></div>
                    <div style="font-size:11px;color:#666;">Total Exams</div>
                </div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 16px;text-align:center;min-width:100px;">
                    <div style="font-size:20px;font-weight:700;color:#16a34a;"><?= esc_html( count( $completed ) ) ?></div>
                    <div style="font-size:11px;color:#666;">Completed</div>
                </div>
                <div style="background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:10px 16px;text-align:center;min-width:100px;">
                    <div style="font-size:20px;font-weight:700;color:#d97706;"><?= esc_html( $avg_pct ) ?>%</div>
                    <div style="font-size:11px;color:#666;">Avg Score</div>
                </div>
            </div>
            <table class="knowly-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Standard</th>
                        <th>Difficulty</th>
                        <th style="text-align:center;">Score</th>
                        <th style="text-align:center;">%</th>
                        <th style="text-align:center;">Time</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sessions as $s ) :
                    $pct_color = (float) $s['percentage'] >= 70 ? '#16a34a' : ( (float) $s['percentage'] >= 50 ? '#d97706' : '#dc2626' );
                    $mins      = $s['time_taken_seconds'] ? floor( $s['time_taken_seconds'] / 60 ) . 'm ' . ( $s['time_taken_seconds'] % 60 ) . 's' : '—';
                ?>
                <tr>
                    <td><strong><?= esc_html( $s['subject'] ) ?></strong></td>
                    <td><?= esc_html( strtoupper( $s['level'] ) ) ?> <?= $s['period'] ? esc_html( strtoupper( str_replace( '_', ' ', $s['period'] ) ) ) : 'SEA' ?></td>
                    <td><?= esc_html( ucfirst( $s['difficulty'] ) ) ?></td>
                    <td style="text-align:center;"><?= $s['state'] === 'completed' ? esc_html( $s['score'] . '/' . $s['total'] ) : '—' ?></td>
                    <td style="text-align:center;font-weight:700;color:<?= $s['state'] === 'completed' ? $pct_color : '#9ca3af' ?>;">
                        <?= $s['state'] === 'completed' ? esc_html( $s['percentage'] ) . '%' : '—' ?>
                    </td>
                    <td style="text-align:center;color:#888;"><?= esc_html( $mins ) ?></td>
                    <td>
                        <?php
                        [ $sc, $sl ] = match ( $s['state'] ) {
                            'completed' => [ '#16a34a', 'Completed' ],
                            'active'    => [ '#d97706', 'In Progress' ],
                            default     => [ '#9ca3af', ucfirst( $s['state'] ) ],
                        };
                        ?>
                        <span style="color:<?= $sc ?>;font-weight:600;">● <?= esc_html( $sl ) ?></span>
                    </td>
                    <td style="color:#888;"><?= esc_html( date_i18n( 'M j, Y g:i a', strtotime( $s['started_at'] ) ) ) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
        $html = ob_get_clean();
        wp_send_json_success( [ 'html' => $html ] );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private static function get_child_session_count( int $child_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}knowly_exam_sessions WHERE child_id = %d AND state = 'completed'",
            $child_id
        ) );
    }
}
