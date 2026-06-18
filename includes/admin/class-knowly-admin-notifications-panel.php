<?php
/**
 * Knowly_Admin_Notifications_Panel — Notifications module admin page.
 *
 * Tabs: Queue | Send | Health Checks | Unit Tests
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Notifications_Panel {

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_notif_panel_health',    [ __CLASS__, 'ajax_health' ] );
        add_action( 'wp_ajax_knowly_notif_panel_mark_read', [ __CLASS__, 'ajax_mark_read' ] );
        add_action( 'wp_ajax_knowly_notif_panel_delete',    [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_knowly_notif_panel_send',      [ __CLASS__, 'ajax_send' ] );
        add_action( 'wp_ajax_knowly_notif_user_search',     [ __CLASS__, 'ajax_user_search' ] );
        add_action( 'wp_ajax_knowly_notif_broadcast',       [ __CLASS__, 'ajax_broadcast' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $tab = sanitize_key( $_GET['tab'] ?? 'queue' );
        $tabs = [
            'queue'     => 'Queue',
            'send'      => 'Send Notification',
            'broadcast' => 'Broadcast',
            'health'    => 'Health Checks',
            'tests'     => 'Unit Tests',
        ];
        ?>
        <div class="wrap knowly-wrap">
            <h1>Notifications</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=knowly-notifications-panel&tab=' . $key ) ) ?>"
                   class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </nav>
            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
            <?php
            match ( $tab ) {
                'queue'     => self::render_queue(),
                'send'      => self::render_send(),
                'broadcast' => self::render_broadcast(),
                'health'    => self::render_health(),
                'tests'     => self::render_tests(),
                default     => self::render_queue(),
            };
            ?>
            </div>
        </div>
        <?php
    }

    // ── Queue Tab ─────────────────────────────────────────────────────────────

    private static function render_queue(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_notifications';

        $total_unread = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" );
        $total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $notifications = $wpdb->get_results(
            "SELECT n.*, u.display_name AS recipient_name, s.display_name AS sender_name
             FROM {$table} n
             LEFT JOIN {$wpdb->users} u ON n.recipient_user_id = u.ID
             LEFT JOIN {$wpdb->users} s ON n.sender_user_id = s.ID
             ORDER BY n.created_at DESC
             LIMIT 200"
        );
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div class="knowly-stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;max-width:500px;">
            <div class="knowly-stat-card" style="<?= $total_unread > 0 ? 'border-color:#d63638;' : '' ?>">
                <div class="knowly-stat-number" style="<?= $total_unread > 0 ? 'color:#d63638;' : '' ?>"><?= esc_html( $total_unread ) ?></div>
                <div class="knowly-stat-label">Unread</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number"><?= esc_html( $total ) ?></div>
                <div class="knowly-stat-label">Total (all time)</div>
            </div>
            <div class="knowly-stat-card">
                <div class="knowly-stat-number"><?= count( $notifications ) ?></div>
                <div class="knowly-stat-label">Showing (latest 200)</div>
            </div>
        </div>

        <?php if ( empty( $notifications ) ) : ?>
        <p style="color:#888;">No notifications found.</p>
        <?php else : ?>
        <table class="knowly-table widefat" style="font-size:12px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Recipient</th>
                    <th>Sender</th>
                    <th>Type</th>
                    <th>Subject</th>
                    <th>Message</th>
                    <th>Read</th>
                    <th>Response</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $notifications as $n ) : ?>
                <tr id="notif-row-<?= (int) $n->id ?>">
                    <td style="color:#888;">#<?= (int) $n->id ?></td>
                    <td><?= esc_html( $n->recipient_name ?? 'User #' . $n->recipient_user_id ) ?></td>
                    <td><?= esc_html( $n->sender_name ?? ( $n->sender_user_id ? 'User #' . $n->sender_user_id : '—' ) ) ?></td>
                    <td><span class="knowly-badge <?= $n->type === 'confirmation' ? 'warn' : '' ?>"><?= esc_html( $n->type ) ?></span></td>
                    <td><?= esc_html( $n->subject ) ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= esc_attr( $n->message ) ?>"><?= esc_html( $n->message ) ?></td>
                    <td><?= $n->is_read ? '<span style="color:#888;">read</span>' : '<strong style="color:#d63638;">unread</strong>' ?></td>
                    <td><?= $n->response ? esc_html( $n->response ) : '—' ?></td>
                    <td><?= esc_html( substr( $n->created_at, 0, 16 ) ) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ( ! $n->is_read ) : ?>
                        <button class="button button-small" style="margin-right:4px;" onclick="knowlyNotifPanel.markRead(<?= (int) $n->id ?>, this)">Mark Read</button>
                        <?php endif; ?>
                        <button class="button button-small" style="color:#d63638;border-color:#d63638;" onclick="knowlyNotifPanel.deleteNotif(<?= (int) $n->id ?>, this)">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        const knowlyNotifPanel = {
            markRead(id, btn) {
                jQuery(btn).prop('disabled', true);
                jQuery.post(ajaxurl, { action: 'knowly_notif_panel_mark_read', nonce: '<?= esc_js( $nonce ) ?>', id }, r => {
                    if (r.success) {
                        const row = document.getElementById('notif-row-' + id);
                        if (row) {
                            const readCell = row.cells[6];
                            if (readCell) readCell.innerHTML = '<span style="color:#888;">read</span>';
                            btn.remove();
                        }
                    } else {
                        alert(r.data?.message || 'Error');
                        jQuery(btn).prop('disabled', false);
                    }
                });
            },
            deleteNotif(id, btn) {
                if (!confirm('Delete notification #' + id + '? This cannot be undone.')) return;
                jQuery(btn).prop('disabled', true);
                jQuery.post(ajaxurl, { action: 'knowly_notif_panel_delete', nonce: '<?= esc_js( $nonce ) ?>', id }, r => {
                    if (r.success) {
                        const row = document.getElementById('notif-row-' + id);
                        if (row) row.remove();
                    } else {
                        alert(r.data?.message || 'Error');
                        jQuery(btn).prop('disabled', false);
                    }
                });
            }
        };
        </script>
        <?php endif; ?>
        <?php
    }

    // ── Send Tab ──────────────────────────────────────────────────────────────

    private static function render_send(): void {
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div style="max-width:600px;">
            <p style="color:#666;margin-bottom:20px;">Send a notification directly to any user — teacher, parent, or student.</p>

            <table class="form-table" style="max-width:600px;">
                <tr>
                    <th scope="row"><label>Target</label></th>
                    <td>
                        <select id="kn-send-target" style="width:100%;max-width:300px;" onchange="knowlySend.onTargetChange()">
                            <option value="parent">Parent (by User ID)</option>
                            <option value="student">Student (by User ID)</option>
                            <option value="teacher">Teacher (by User ID)</option>
                            <option value="student_and_parent">Student &amp; Parent (student User ID)</option>
                        </select>
                        <p class="description" id="kn-target-desc">Notification sent to the parent's account.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kn-send-search">User Search</label></th>
                    <td>
                        <input type="text" id="kn-send-search" placeholder="Search by name or email…" style="width:100%;max-width:300px;" oninput="knowlySend.search(this.value)" autocomplete="off" />
                        <div id="kn-search-results" style="border:1px solid #ddd;background:#fff;max-width:300px;display:none;position:relative;z-index:10;max-height:200px;overflow-y:auto;"></div>
                        <p class="description">Or enter User ID directly below.</p>
                        <input type="number" id="kn-send-user-id" placeholder="User ID" style="width:120px;margin-top:6px;" />
                        <span id="kn-selected-user" style="margin-left:8px;color:#2271b1;font-weight:600;"></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kn-send-subject">Subject</label></th>
                    <td>
                        <input type="text" id="kn-send-subject" value="admin_message" style="width:100%;max-width:300px;" />
                        <p class="description">Internal subject key (e.g. admin_message, reminder, alert).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kn-send-msg">Message</label></th>
                    <td>
                        <textarea id="kn-send-msg" rows="4" style="width:100%;max-width:400px;" placeholder="Enter the notification message…"></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kn-send-type">Type</label></th>
                    <td>
                        <select id="kn-send-type" style="width:200px;">
                            <option value="simple">Simple (read-only)</option>
                            <option value="confirmation">Confirmation (accept/decline)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <div style="margin-top:16px;">
                <button id="kn-send-btn" class="button button-primary" onclick="knowlySend.send()">Send Notification</button>
                <span id="kn-send-status" style="margin-left:12px;font-weight:600;"></span>
            </div>

            <div id="kn-send-log" style="margin-top:20px;font-size:12px;color:#666;"></div>
        </div>

        <script>
        var knowlySend = {
            searchTimer: null,
            targetDescriptions: {
                parent:             'Notification sent to the parent\'s account only.',
                student:            'Notification sent to the student\'s account only.',
                teacher:            'Notification sent to the teacher\'s account only.',
                student_and_parent: 'Notification sent to both the student and their linked parent. Provide the student\'s User ID.',
            },
            onTargetChange() {
                var t = document.getElementById('kn-send-target').value;
                document.getElementById('kn-target-desc').textContent = this.targetDescriptions[t] || '';
            },
            search(q) {
                clearTimeout(this.searchTimer);
                var box = document.getElementById('kn-search-results');
                if (q.length < 2) { box.style.display = 'none'; return; }
                this.searchTimer = setTimeout(() => {
                    jQuery.post(ajaxurl, { action: 'knowly_notif_user_search', nonce: '<?= esc_js( $nonce ) ?>', q }, r => {
                        if (!r.success) return;
                        var users = r.data.users || [];
                        if (!users.length) { box.innerHTML = '<div style="padding:8px;color:#888;">No users found.</div>'; box.style.display = 'block'; return; }
                        box.innerHTML = users.map(u =>
                            '<div style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;" onmousedown="knowlySend.selectUser(' + u.id + ', \'' + u.display_name.replace(/'/g, "\\'") + '\')">' +
                            '<strong>' + u.display_name + '</strong> <span style="color:#888;font-size:11px;">' + u.user_email + ' · #' + u.id + ' · ' + u.role + '</span></div>'
                        ).join('');
                        box.style.display = 'block';
                    });
                }, 300);
            },
            selectUser(id, name) {
                document.getElementById('kn-send-user-id').value = id;
                document.getElementById('kn-selected-user').textContent = name + ' (#' + id + ')';
                document.getElementById('kn-search-results').style.display = 'none';
                document.getElementById('kn-send-search').value = name;
            },
            send() {
                var userId  = parseInt(document.getElementById('kn-send-user-id').value);
                var msg     = document.getElementById('kn-send-msg').value.trim();
                var subject = document.getElementById('kn-send-subject').value.trim() || 'admin_message';
                var type    = document.getElementById('kn-send-type').value;
                var target  = document.getElementById('kn-send-target').value;
                var status  = document.getElementById('kn-send-status');
                var log     = document.getElementById('kn-send-log');

                if (!userId || isNaN(userId)) { status.style.color = '#d63638'; status.textContent = 'Please enter or select a User ID.'; return; }
                if (!msg) { status.style.color = '#d63638'; status.textContent = 'Message is required.'; return; }

                status.style.color = '#888'; status.textContent = 'Sending…';
                document.getElementById('kn-send-btn').disabled = true;

                jQuery.post(ajaxurl, {
                    action:  'knowly_notif_panel_send',
                    nonce:   '<?= esc_js( $nonce ) ?>',
                    user_id: userId,
                    target:  target,
                    subject: subject,
                    message: msg,
                    type:    type,
                }, r => {
                    document.getElementById('kn-send-btn').disabled = false;
                    if (r.success) {
                        status.style.color = '#2e7d32'; status.textContent = '✓ Sent! ID(s): ' + JSON.stringify(r.data.ids || r.data.notification_id);
                        log.innerHTML = '<strong>Sent at ' + new Date().toLocaleTimeString() + ':</strong> ' +
                            'Target=' + target + ' | UserID=' + userId + ' | Subject=' + subject + ' | Message=' + msg.substring(0, 80) + (msg.length > 80 ? '…' : '');
                    } else {
                        status.style.color = '#d63638'; status.textContent = '✗ ' + (r.data?.message || 'Error sending notification.');
                    }
                });
            }
        };
        </script>
        <?php
    }

    // ── Health Checks Tab ─────────────────────────────────────────────────────

    private static function render_health(): void {
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <button id="notif-run-health" class="button button-primary" style="margin-bottom:16px;">Run Health Checks</button>
        <div id="notif-health-results"><p style="color:#888;">Click to run health checks.</p></div>
        <script>
        jQuery('#notif-run-health').on('click', function() {
            var $btn = jQuery(this).prop('disabled', true).text('Running…');
            jQuery.post(ajaxurl, { action: 'knowly_notif_panel_health', nonce: '<?= esc_js( $nonce ) ?>' }, function(res) {
                $btn.prop('disabled', false).text('Run Health Checks');
                if (!res.success) { jQuery('#notif-health-results').html('<p style="color:#dc2626;">' + (res.data?.message || 'Error') + '</p>'); return; }
                var html = '<table class="knowly-table" style="max-width:700px;"><tbody>';
                (res.data.checks || []).forEach(function(c) {
                    var col = c.status === 'pass' ? '#16a34a' : (c.status === 'warn' ? '#d97706' : '#dc2626');
                    html += '<tr><td style="color:' + col + ';font-weight:600;width:40px;">' + (c.status === 'pass' ? '✓' : c.status === 'warn' ? '⚠' : '✗') + '</td><td><strong>' + c.label + '</strong></td><td style="color:#666;">' + (c.detail || '') + '</td></tr>';
                });
                html += '</tbody></table>';
                jQuery('#notif-health-results').html(html);
            });
        });
        </script>
        <?php
    }

    // ── Unit Tests Tab ────────────────────────────────────────────────────────

    private static function render_tests(): void {
        echo '<p style="color:#666;margin-bottom:16px;">Test notification create, list, count, respond, mark-read, delete, and combined notify endpoints.</p>';
        Knowly_Admin_Testing::render_test_groups( [ 'block4_notifications', 'notif_v2', 'block2_notifications' ] );
    }

    // ── Broadcast Tab ─────────────────────────────────────────────────────────

    private static function render_broadcast(): void {
        $nonce = wp_create_nonce( 'knowly_admin_nonce' );
        ?>
        <div style="max-width:700px;">
            <p style="color:#666;margin-bottom:20px;">Send a notification to a filtered list of users, or broadcast to an entire role group.</p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

                <!-- ── Left: User search & filter ── -->
                <div>
                    <h3 style="margin-top:0;margin-bottom:12px;font-size:14px;">Target Users</h3>

                    <div style="margin-bottom:10px;">
                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Role Filter</label>
                        <select id="kn-bc-role" style="width:100%;" onchange="knowlyBroadcast.filterRole()">
                            <option value="">All roles</option>
                            <option value="knowly_teacher">Teachers</option>
                            <option value="knowly_parent">Parents</option>
                            <option value="knowly_child">Students</option>
                        </select>
                    </div>

                    <div style="margin-bottom:10px;">
                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Search by Name or Email</label>
                        <input type="text" id="kn-bc-search" placeholder="Type to filter…" style="width:100%;" oninput="knowlyBroadcast.search(this.value)" autocomplete="off" />
                    </div>

                    <div id="kn-bc-user-list" style="border:1px solid #ddd;border-radius:4px;max-height:280px;overflow-y:auto;background:#fafafa;font-size:12px;">
                        <div style="padding:10px;color:#888;">Select a role or search to load users.</div>
                    </div>

                    <div style="margin-top:8px;font-size:12px;color:#2271b1;">
                        <span id="kn-bc-selected-count">0</span> user(s) selected
                        <button onclick="knowlyBroadcast.clearSelection()" style="background:none;border:none;color:#d63638;cursor:pointer;margin-left:6px;font-size:11px;">Clear</button>
                    </div>
                </div>

                <!-- ── Right: Message composer ── -->
                <div>
                    <h3 style="margin-top:0;margin-bottom:12px;font-size:14px;">Message</h3>

                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th scope="row" style="padding:4px 0;"><label style="font-size:12px;">Subject key</label></th>
                            <td style="padding:4px 0;">
                                <input type="text" id="kn-bc-subject" value="admin_message" style="width:100%;" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding:4px 0;vertical-align:top;"><label style="font-size:12px;">Message</label></th>
                            <td style="padding:4px 0;">
                                <textarea id="kn-bc-msg" rows="5" style="width:100%;" placeholder="Enter your message…"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding:4px 0;"><label style="font-size:12px;">Type</label></th>
                            <td style="padding:4px 0;">
                                <select id="kn-bc-type" style="width:100%;">
                                    <option value="simple">Simple (read-only)</option>
                                    <option value="confirmation">Confirmation (accept/decline)</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button id="kn-bc-send-selected" class="button button-primary" onclick="knowlyBroadcast.sendSelected()">
                            Send to Selected
                        </button>
                        <button id="kn-bc-send-all" class="button" onclick="knowlyBroadcast.sendAll()" style="border-color:#2271b1;color:#2271b1;">
                            Send to All in Filter
                        </button>
                    </div>

                    <div id="kn-bc-status" style="margin-top:10px;font-weight:600;font-size:13px;"></div>
                    <div id="kn-bc-log" style="margin-top:8px;font-size:11px;color:#666;"></div>
                </div>
            </div>
        </div>

        <script>
        var knowlyBroadcast = {
            searchTimer: null,
            selectedIds: new Set(),
            allLoadedUsers: [],

            filterRole() {
                var role = document.getElementById('kn-bc-role').value;
                var q    = document.getElementById('kn-bc-search').value;
                this.load(q, role);
            },

            search(q) {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => {
                    var role = document.getElementById('kn-bc-role').value;
                    this.load(q, role);
                }, 300);
            },

            load(q, role) {
                var box = document.getElementById('kn-bc-user-list');
                box.innerHTML = '<div style="padding:10px;color:#888;">Loading…</div>';
                jQuery.post(ajaxurl, {
                    action: 'knowly_notif_user_search',
                    nonce:  '<?= esc_js( $nonce ) ?>',
                    q:      q || ' ',
                    role:   role,
                }, r => {
                    if (!r.success) { box.innerHTML = '<div style="padding:10px;color:#d63638;">Error loading users.</div>'; return; }
                    var users = r.data.users || [];
                    this.allLoadedUsers = users;
                    if (!users.length) { box.innerHTML = '<div style="padding:10px;color:#888;">No users found.</div>'; return; }
                    box.innerHTML = users.map(u => {
                        var checked = this.selectedIds.has(u.id) ? 'checked' : '';
                        var roleLabel = { knowly_teacher: 'Teacher', knowly_parent: 'Parent', knowly_child: 'Student' }[u.role] || u.role;
                        return '<label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-bottom:1px solid #f0f0f0;cursor:pointer;">' +
                            '<input type="checkbox" value="' + u.id + '" ' + checked + ' onchange="knowlyBroadcast.toggle(' + u.id + ', this.checked)">' +
                            '<span><strong>' + u.display_name + '</strong> <span style="color:#888;font-size:10px;">' + u.user_email + ' · ' + roleLabel + '</span></span>' +
                            '</label>';
                    }).join('');
                });
            },

            toggle(id, checked) {
                if (checked) this.selectedIds.add(id);
                else this.selectedIds.delete(id);
                document.getElementById('kn-bc-selected-count').textContent = this.selectedIds.size;
            },

            clearSelection() {
                this.selectedIds.clear();
                document.getElementById('kn-bc-selected-count').textContent = '0';
                document.querySelectorAll('#kn-bc-user-list input[type=checkbox]').forEach(cb => cb.checked = false);
            },

            getMsg() {
                return {
                    subject: document.getElementById('kn-bc-subject').value.trim() || 'admin_message',
                    message: document.getElementById('kn-bc-msg').value.trim(),
                    type:    document.getElementById('kn-bc-type').value,
                };
            },

            sendSelected() {
                if (!this.selectedIds.size) {
                    this.setStatus('error', 'No users selected.');
                    return;
                }
                var { subject, message, type } = this.getMsg();
                if (!message) { this.setStatus('error', 'Message is required.'); return; }
                this.send({ user_ids: JSON.stringify([...this.selectedIds]), subject, message, type });
            },

            sendAll() {
                var role = document.getElementById('kn-bc-role').value;
                var { subject, message, type } = this.getMsg();
                if (!message) { this.setStatus('error', 'Message is required.'); return; }
                var label = role ? ({ knowly_teacher: 'all Teachers', knowly_parent: 'all Parents', knowly_child: 'all Students' }[role] || 'all ' + role) : 'ALL users';
                if (!confirm('Send this notification to ' + label + '? This cannot be undone.')) return;
                this.send({ role, subject, message, type });
            },

            send(data) {
                this.setStatus('info', 'Sending…');
                jQuery('#kn-bc-send-selected, #kn-bc-send-all').prop('disabled', true);
                jQuery.post(ajaxurl, Object.assign({ action: 'knowly_notif_broadcast', nonce: '<?= esc_js( $nonce ) ?>' }, data), r => {
                    jQuery('#kn-bc-send-selected, #kn-bc-send-all').prop('disabled', false);
                    if (r.success) {
                        this.setStatus('success', '✓ Sent to ' + r.data.sent + ' user(s).');
                        document.getElementById('kn-bc-log').textContent = 'Sent at ' + new Date().toLocaleTimeString() + ' | ' + r.data.sent + ' notifications created.';
                    } else {
                        this.setStatus('error', '✗ ' + (r.data?.message || 'Error sending.'));
                    }
                });
            },

            setStatus(level, msg) {
                var el = document.getElementById('kn-bc-status');
                el.style.color = level === 'success' ? '#2e7d32' : level === 'error' ? '#d63638' : '#888';
                el.textContent = msg;
            }
        };
        </script>
        <?php
    }

    // ── AJAX: Mark notification read ──────────────────────────────────────────

    public static function ajax_mark_read(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'id required' ] );

        $wpdb->update(
            $wpdb->prefix . 'knowly_notifications',
            [ 'is_read' => 1 ],
            [ 'id' => $id ]
        );

        wp_send_json_success( [ 'id' => $id ] );
    }

    // ── AJAX: Delete notification ─────────────────────────────────────────────

    public static function ajax_delete(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'id required' ] );

        $deleted = Knowly_Notification_Service::admin_delete( $id );

        if ( $deleted ) {
            wp_send_json_success( [ 'id' => $id ] );
        } else {
            wp_send_json_error( [ 'message' => 'Notification not found or already deleted.' ] );
        }
    }

    // ── AJAX: Send notification ───────────────────────────────────────────────

    public static function ajax_send(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        $target  = sanitize_key( $_POST['target'] ?? 'student' );
        $subject = sanitize_text_field( $_POST['subject'] ?? 'admin_message' );
        $message = sanitize_textarea_field( $_POST['message'] ?? '' );
        $type    = in_array( $_POST['type'] ?? '', [ 'simple', 'confirmation' ], true ) ? $_POST['type'] : 'simple';

        if ( ! $user_id )  wp_send_json_error( [ 'message' => 'user_id is required.' ] );
        if ( ! $message )  wp_send_json_error( [ 'message' => 'message is required.' ] );

        $sender_id = get_current_user_id();
        $ids       = [];

        if ( $target === 'student_and_parent' ) {
            // user_id is the child — send to child and their parent
            $child_id = $user_id;

            $child_notif = Knowly_Notification_Service::create( [
                'recipient_user_id' => $child_id,
                'sender_user_id'    => $sender_id,
                'type'              => $type,
                'subject'           => $subject,
                'message'           => $message,
            ] );
            if ( is_wp_error( $child_notif ) ) wp_send_json_error( [ 'message' => $child_notif->get_error_message() ] );
            $ids[] = $child_notif;

            // Try to look up the linked parent via user meta
            $parent_id = (int) get_user_meta( $child_id, 'knowly_parent_id', true );
            if ( ! $parent_id ) {
                // Fallback: search class memberships
                global $wpdb;
                $parent_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT parent_user_id FROM {$wpdb->prefix}knowly_class_members WHERE child_user_id = %d LIMIT 1",
                    $child_id
                ) );
            }

            if ( $parent_id ) {
                $parent_notif = Knowly_Notification_Service::create( [
                    'recipient_user_id' => $parent_id,
                    'sender_user_id'    => $sender_id,
                    'type'              => $type,
                    'subject'           => $subject,
                    'message'           => $message,
                ] );
                if ( ! is_wp_error( $parent_notif ) ) $ids[] = $parent_notif;
            }

            wp_send_json_success( [ 'ids' => $ids, 'parent_notified' => $parent_id > 0 ] );

        } else {
            // Single recipient: parent, student, or teacher — all use user_id directly
            $notif_id = Knowly_Notification_Service::create( [
                'recipient_user_id' => $user_id,
                'sender_user_id'    => $sender_id,
                'type'              => $type,
                'subject'           => $subject,
                'message'           => $message,
            ] );

            if ( is_wp_error( $notif_id ) ) wp_send_json_error( [ 'message' => $notif_id->get_error_message() ] );

            wp_send_json_success( [ 'notification_id' => $notif_id, 'ids' => [ $notif_id ] ] );
        }
    }

    // ── AJAX: User search ─────────────────────────────────────────────────────

    public static function ajax_user_search(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $q    = sanitize_text_field( $_POST['q'] ?? '' );
        $role = sanitize_key( $_POST['role'] ?? '' );

        $args = [
            'number' => 50,
            'fields' => [ 'ID', 'display_name', 'user_email' ],
        ];

        // Role filter
        $allowed_roles = [ 'knowly_teacher', 'knowly_parent', 'knowly_child' ];
        if ( $role && in_array( $role, $allowed_roles, true ) ) {
            $args['role'] = $role;
        }

        // Search query
        if ( strlen( $q ) >= 2 && trim( $q ) !== '' ) {
            $args['search']         = '*' . $q . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
        }

        $users = get_users( $args );

        $result = array_map( function( $u ) {
            $roles = (array) get_userdata( $u->ID )->roles;
            $role  = array_intersect( $roles, [ 'knowly_teacher', 'knowly_parent', 'knowly_child' ] );
            return [
                'id'           => (int) $u->ID,
                'display_name' => $u->display_name,
                'user_email'   => $u->user_email,
                'role'         => reset( $role ) ?: implode( ', ', $roles ),
            ];
        }, $users );

        wp_send_json_success( [ 'users' => $result ] );
    }

    // ── AJAX: Broadcast ───────────────────────────────────────────────────────

    public static function ajax_broadcast(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $message  = sanitize_textarea_field( $_POST['message'] ?? '' );
        $subject  = sanitize_text_field( $_POST['subject'] ?? 'admin_message' ) ?: 'admin_message';
        $type     = in_array( $_POST['type'] ?? '', [ 'simple', 'confirmation' ], true ) ? $_POST['type'] : 'simple';
        $role     = sanitize_key( $_POST['role'] ?? '' );
        $user_ids = json_decode( stripslashes( $_POST['user_ids'] ?? '[]' ), true );

        if ( ! $message ) wp_send_json_error( [ 'message' => 'Message is required.' ] );

        $sender_id = get_current_user_id();
        $targets   = [];

        if ( ! empty( $user_ids ) && is_array( $user_ids ) ) {
            // Explicit user_ids list from selection
            $targets = array_map( 'intval', $user_ids );
        } else {
            // Role-based broadcast
            $allowed_roles = [ 'knowly_teacher', 'knowly_parent', 'knowly_child' ];
            $args = [ 'number' => -1, 'fields' => 'ID' ];
            if ( $role && in_array( $role, $allowed_roles, true ) ) {
                $args['role'] = $role;
            } else {
                // All Knowly users (any of the three roles)
                $args['role__in'] = $allowed_roles;
            }
            $targets = get_users( $args );
            $targets = array_map( 'intval', $targets );
        }

        $count = 0;
        foreach ( $targets as $uid ) {
            $result = Knowly_Notification_Service::create( [
                'recipient_user_id' => $uid,
                'sender_user_id'    => $sender_id,
                'type'              => $type,
                'subject'           => $subject,
                'message'           => $message,
            ] );
            if ( ! is_wp_error( $result ) ) $count++;
        }

        Knowly_Debug::log( 'notification.broadcast', 'Broadcast sent', [
            'role'    => $role ?: 'all',
            'count'   => $count,
            'subject' => $subject,
        ], $sender_id, 'info' );

        wp_send_json_success( [ 'sent' => $count ] );
    }

    // ── AJAX: Health ──────────────────────────────────────────────────────────

    public static function ajax_health(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        global $wpdb;
        $checks = [];
        $table  = $wpdb->prefix . 'knowly_notifications';

        // 1. Table exists
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $checks[] = [ 'label' => 'knowly_notifications table', 'status' => $exists === $table ? 'pass' : 'fail', 'detail' => $exists === $table ? 'Table exists.' : 'Missing — run plugin activation.' ];

        if ( $exists === $table ) {
            // 2. Read — count all
            $count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            $unread   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" );
            $checks[] = [ 'label' => 'Read verified', 'status' => 'pass', 'detail' => "{$count} total, {$unread} unread." ];

            // 3. Write test
            $inserted = $wpdb->insert( $table, [
                'recipient_user_id' => get_current_user_id(),
                'type'              => 'simple',
                'subject'           => '_health_check',
                'message'           => 'Health check write test.',
                'is_read'           => 1,
                'created_at'        => current_time( 'mysql' ),
            ] );
            if ( $inserted ) {
                $wpdb->delete( $table, [ 'subject' => '_health_check' ] );
                $checks[] = [ 'label' => 'Write verified', 'status' => 'pass', 'detail' => 'Insert and delete OK.' ];
            } else {
                $checks[] = [ 'label' => 'Write verified', 'status' => 'fail', 'detail' => 'Insert failed: ' . $wpdb->last_error ];
            }

            // 4. Delete endpoint sanity (service method exists)
            $checks[] = [
                'label'  => 'Delete method available',
                'status' => method_exists( 'Knowly_Notification_Service', 'admin_delete' ) ? 'pass' : 'fail',
                'detail' => 'Knowly_Notification_Service::admin_delete() exists.',
            ];
        }

        wp_send_json_success( [ 'checks' => $checks ] );
    }
}
