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
        add_action( 'wp_ajax_knowly_teacher_approve',           [ __CLASS__, 'ajax_approve' ] );
        add_action( 'wp_ajax_knowly_teacher_suspend',           [ __CLASS__, 'ajax_suspend' ] );
        add_action( 'wp_ajax_knowly_teacher_adjust_gems',       [ __CLASS__, 'ajax_adjust_gems' ] );
        add_action( 'wp_ajax_knowly_teacher_upload_id_doc',      [ __CLASS__, 'ajax_upload_id_doc' ] );
        add_action( 'wp_ajax_knowly_teacher_create_class',       [ __CLASS__, 'ajax_create_class' ] );
        add_action( 'wp_ajax_knowly_teacher_disband_class',      [ __CLASS__, 'ajax_disband_class' ] );
        add_action( 'wp_ajax_knowly_teacher_update_settings',    [ __CLASS__, 'ajax_update_settings' ] );
        add_action( 'wp_ajax_knowly_teacher_add_gems',           [ __CLASS__, 'ajax_add_gems' ] );
        add_action( 'wp_ajax_knowly_teacher_remove_gems',        [ __CLASS__, 'ajax_remove_gems' ] );
        add_action( 'wp_ajax_knowly_teacher_get_classes',        [ __CLASS__, 'ajax_get_teacher_classes' ] );
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
            <?php foreach ( $approved as $teacher ) :
                $id_doc_id  = (int) get_user_meta( $teacher['user_id'], 'knowly_id_document', true );
                $id_doc_url = $id_doc_id ? wp_get_attachment_url( $id_doc_id ) : '';
                $nonce = wp_create_nonce( 'knowly_teacher_action' );
            ?>
            <div style="border:1px solid #c3c4c7;border-radius:4px;margin-bottom:10px;background:#fff;"
                 id="teacher-card-<?= (int) $teacher['user_id'] ?>">
                <!-- Teacher header row -->
                <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:220px;">
                        <strong><?= esc_html( $teacher['display_name'] ) ?></strong>
                        <div style="font-size:12px;color:#666;margin-top:2px;">
                            <?= esc_html( $teacher['email'] ) ?> · <?= esc_html( $teacher['school_name'] ) ?>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:13px;flex-wrap:wrap;">
                        <span style="white-space:nowrap;">🔴 <strong id="teacher-gem-balance-<?= (int) $teacher['user_id'] ?>"><?= (int) $teacher['red_gem_balance'] ?></strong></span>
                        <button class="button button-small" style="color:#16a34a;border-color:#16a34a;"
                            onclick="knowlyTeacherAdmin.addGems(<?= (int) $teacher['user_id'] ?>, '<?= esc_js( $nonce ) ?>')">+ Gems</button>
                        <button class="button button-small" style="color:#d97706;border-color:#d97706;"
                            onclick="knowlyTeacherAdmin.removeGems(<?= (int) $teacher['user_id'] ?>, '<?= esc_js( $nonce ) ?>')">- Gems</button>
                        <span class="knowly-teacher-action-result" data-id="<?= (int) $teacher['user_id'] ?>" style="font-size:11px;"></span>
                    </div>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                        <button class="button button-small knowly-toggle-teacher-detail"
                            data-teacher-id="<?= (int) $teacher['user_id'] ?>"
                            style="color:#2563eb;border-color:#2563eb;">
                            Details ▾
                        </button>
                        <button class="button button-small knowly-teacher-suspend"
                            data-id="<?= (int) $teacher['user_id'] ?>"
                            style="color:#d63638;">Suspend</button>
                        <a href="<?= esc_url( admin_url( 'user-edit.php?user_id=' . $teacher['user_id'] ) ) ?>"
                           class="button button-small">Edit</a>
                    </div>
                </div>

                <!-- Expandable detail panel -->
                <div class="knowly-teacher-detail-panel" id="teacher-detail-<?= (int) $teacher['user_id'] ?>"
                     style="display:none;border-top:1px solid #e5e7eb;background:#f9fafb;padding:12px 14px;">

                    <!-- Teacher Settings (editable) -->
                    <?php $t_avatar = (int) get_user_meta( $teacher['user_id'], 'knowly_avatar_index', true ) ?: 1; ?>
                    <div style="margin-bottom:14px;">
                        <strong style="font-size:13px;display:block;margin-bottom:8px;">Settings</strong>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;font-size:12px;">
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:3px;">First Name</label>
                                <input type="text" id="teacher-fn-<?= (int) $teacher['user_id'] ?>"
                                    value="<?= esc_attr( $teacher['first_name'] ) ?>"
                                    style="width:100%;height:28px;font-size:12px;">
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:3px;">Last Name</label>
                                <input type="text" id="teacher-ln-<?= (int) $teacher['user_id'] ?>"
                                    value="<?= esc_attr( $teacher['last_name'] ) ?>"
                                    style="width:100%;height:28px;font-size:12px;">
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:3px;">School</label>
                                <input type="text" id="teacher-school-<?= (int) $teacher['user_id'] ?>"
                                    value="<?= esc_attr( $teacher['school_name'] ) ?>"
                                    style="width:100%;height:28px;font-size:12px;">
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:3px;">Class / Level</label>
                                <input type="text" id="teacher-classname-<?= (int) $teacher['user_id'] ?>"
                                    value="<?= esc_attr( $teacher['class_name'] ) ?>"
                                    placeholder="e.g. 4B / Standard 4"
                                    style="width:100%;height:28px;font-size:12px;">
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:3px;">Avatar (1–10)</label>
                                <input type="number" min="1" max="10" id="teacher-avatar-<?= (int) $teacher['user_id'] ?>"
                                    value="<?= esc_attr( $t_avatar ) ?>"
                                    style="width:100%;height:28px;font-size:12px;">
                            </div>
                        </div>
                        <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <button class="button button-small button-primary"
                                onclick="knowlyTeacherAdmin.saveSettings(<?= (int) $teacher['user_id'] ?>, '<?= esc_js( $nonce ) ?>', this)">
                                Save Settings
                            </button>
                            <span class="teacher-settings-status-<?= (int) $teacher['user_id'] ?>" style="font-size:11px;"></span>
                        </div>
                    </div>

                    <!-- Read-only info -->
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;margin-bottom:14px;font-size:12px;color:#555;">
                        <div><span style="color:#888;">Phone:</span> <?= esc_html( $teacher['phone'] ) ?></div>
                        <div><span style="color:#888;">Principal:</span> <?= esc_html( $teacher['principal_name'] ) ?></div>
                        <div><span style="color:#888;">Principal Contact:</span> <?= esc_html( $teacher['principal_contact'] ) ?></div>
                        <div><span style="color:#888;">Red Gem Stipend:</span> <?= esc_html( $teacher['red_gem_stipend'] ) ?></div>
                        <div><span style="color:#888;">Registered:</span> <?= esc_html( substr( $teacher['registered'], 0, 10 ) ) ?></div>
                        <div><span style="color:#888;">User ID:</span> <?= esc_html( $teacher['user_id'] ) ?></div>
                    </div>

                    <!-- ID Document -->
                    <div style="margin-bottom:14px;">
                        <strong style="font-size:13px;">ID Document</strong>
                        <div style="margin-top:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <?php if ( $id_doc_url ) : ?>
                                <a href="<?= esc_url( $id_doc_url ) ?>" target="_blank" class="button button-small">View Document</a>
                                <span style="font-size:11px;color:#888;">Attachment #<?= esc_html( $id_doc_id ) ?></span>
                            <?php else : ?>
                                <span style="color:#888;font-size:12px;">No document uploaded.</span>
                            <?php endif; ?>
                            <label class="button button-small" style="cursor:pointer;margin:0;">
                                Upload New
                                <input type="file" accept=".png,.jpg,.jpeg,.pdf"
                                       style="display:none;"
                                       onchange="knowlyTeacherAdmin.uploadIdDoc(<?= (int) $teacher['user_id'] ?>, this, '<?= esc_js( $nonce ) ?>')">
                            </label>
                            <span class="knowly-id-doc-status-<?= (int) $teacher['user_id'] ?>" style="font-size:11px;"></span>
                        </div>
                    </div>

                    <!-- Classes hierarchy — loaded fresh via AJAX on panel open -->
                    <div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                            <strong style="font-size:13px;">Classes (<span id="teacher-classes-count-<?= (int) $teacher['user_id'] ?>">…</span>)</strong>
                            <button class="button button-small"
                                style="color:#16a34a;border-color:#16a34a;"
                                onclick="knowlyTeacherAdmin.createClass(<?= (int) $teacher['user_id'] ?>, '<?= esc_js( $nonce ) ?>')">
                                + New Class
                            </button>
                        </div>
                        <div id="teacher-classes-wrapper-<?= (int) $teacher['user_id'] ?>"
                             data-teacher-id="<?= (int) $teacher['user_id'] ?>"
                             data-nonce="<?= esc_attr( $nonce ) ?>">
                            <p style="color:#888;font-size:12px;">Opening panel loads classes…</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;border-radius:4px;margin-bottom:32px;color:#666;">
                No approved teachers yet.
            </div>
            <?php endif; ?>

            <!-- Suspended Teachers -->
            <?php if ( ! empty( $suspended ) ) : ?>
            <h2>⛔ Suspended Teachers</h2>
            <?php foreach ( $suspended as $teacher ) : ?>
            <div style="border:1px solid #c3c4c7;border-radius:4px;margin-bottom:6px;background:#fff;padding:10px 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <strong><?= esc_html( $teacher['display_name'] ) ?></strong>
                    <div style="font-size:12px;color:#666;"><?= esc_html( $teacher['email'] ) ?> · <?= esc_html( $teacher['school_name'] ) ?></div>
                </div>
                <button class="button button-primary knowly-teacher-approve"
                        data-id="<?= (int) $teacher['user_id'] ?>">Re-Approve</button>
                <span class="knowly-teacher-action-result" data-id="<?= (int) $teacher['user_id'] ?>" style="font-size:12px;"></span>
            </div>
            <?php endforeach; ?>
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


            $(document).on('click', '.knowly-toggle-teacher-detail', function() {
                const id    = $(this).data('teacherId');
                const panel = document.getElementById('teacher-detail-' + id);
                if (!panel) return;
                const open = panel.style.display !== 'none';
                panel.style.display = open ? 'none' : 'block';
                $(this).text(open ? 'Details ▾' : 'Details ▴');
                if (!open) {
                    // Refresh classes fresh from DB every time the panel opens
                    const wrapper = document.getElementById('teacher-classes-wrapper-' + id);
                    const nonce   = wrapper ? wrapper.dataset.nonce : '';
                    if (nonce) knowlyTeacherAdmin.refreshClasses(id, nonce);
                }
            });
        });

        const knowlyTeacherAdmin = {
            addGems(teacherId, nonce) {
                var amount = parseInt(prompt('Add how many Red Gems to this teacher?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, { action: 'knowly_teacher_add_gems', nonce, teacher_id: teacherId, amount }, r => {
                    if (r.success) {
                        jQuery('#teacher-gem-balance-' + teacherId).text(r.data.balance_after);
                        jQuery('.knowly-teacher-action-result[data-id="' + teacherId + '"]')
                            .text('+ ' + amount + ' added').css('color','#16a34a');
                        setTimeout(() => jQuery('.knowly-teacher-action-result[data-id="' + teacherId + '"]').text(''), 3000);
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
            removeGems(teacherId, nonce) {
                var amount = parseInt(prompt('Remove how many Red Gems from this teacher?'), 10);
                if (!amount || amount <= 0) return;
                jQuery.post(ajaxurl, { action: 'knowly_teacher_remove_gems', nonce, teacher_id: teacherId, amount }, r => {
                    if (r.success) {
                        jQuery('#teacher-gem-balance-' + teacherId).text(r.data.balance_after);
                        jQuery('.knowly-teacher-action-result[data-id="' + teacherId + '"]')
                            .text('- ' + amount + ' removed').css('color','#d97706');
                        setTimeout(() => jQuery('.knowly-teacher-action-result[data-id="' + teacherId + '"]').text(''), 3000);
                    } else {
                        alert(r.data?.message || 'Error');
                    }
                });
            },
            saveSettings(teacherId, nonce, btn) {
                const first     = jQuery('#teacher-fn-'        + teacherId).val().trim();
                const last      = jQuery('#teacher-ln-'        + teacherId).val().trim();
                const school    = jQuery('#teacher-school-'    + teacherId).val().trim();
                const className = jQuery('#teacher-classname-' + teacherId).val().trim();
                const avatar    = jQuery('#teacher-avatar-'    + teacherId).val();
                const $s        = jQuery('.teacher-settings-status-' + teacherId);
                btn.disabled = true;
                jQuery.post(ajaxurl, {
                    action:      'knowly_teacher_update_settings',
                    nonce:       nonce,
                    teacher_id:  teacherId,
                    first_name:  first,
                    last_name:   last,
                    school_name: school,
                    class_name:  className,
                    avatar_index: avatar,
                }, r => {
                    btn.disabled = false;
                    $s.text(r.success ? '✓ Saved' : (r.data?.message || 'Error'))
                      .css('color', r.success ? '#16a34a' : '#dc2626');
                    setTimeout(() => $s.text(''), 3000);
                });
            },
            uploadIdDoc(teacherId, input, nonce) {
                const file = input.files[0];
                if (!file) return;
                const $status = jQuery('.knowly-id-doc-status-' + teacherId);
                $status.text('Uploading…').css('color', '#888');
                const formData = new FormData();
                formData.append('action', 'knowly_teacher_upload_id_doc');
                formData.append('nonce', nonce);
                formData.append('teacher_id', teacherId);
                formData.append('id_document', file);
                jQuery.ajax({
                    url: ajaxurl, type: 'POST', data: formData,
                    processData: false, contentType: false,
                    success(res) {
                        if (res.success) {
                            $status.text('✓ Uploaded').css('color', '#16a34a');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            $status.text(res.data?.message || 'Upload failed').css('color', '#dc2626');
                        }
                    },
                    error() { $status.text('Upload error').css('color', '#dc2626'); }
                });
            },
            refreshClasses(teacherId, nonce) {
                const wrapper = document.getElementById('teacher-classes-wrapper-' + teacherId);
                const counter = document.getElementById('teacher-classes-count-' + teacherId);
                if (!wrapper) return;
                wrapper.innerHTML = '<p style="color:#888;font-size:12px;">Loading…</p>';
                const detailBase = '<?= esc_js( admin_url( "admin.php?page=knowly-classes&tab=detail&class_id=" ) ) ?>';
                jQuery.post(ajaxurl, {
                    action: 'knowly_teacher_get_classes',
                    nonce: nonce,
                    teacher_id: teacherId,
                }, r => {
                    if (!r.success) {
                        wrapper.innerHTML = '<p style="color:#dc2626;font-size:12px;">Failed to load classes.</p>';
                        return;
                    }
                    const classes = r.data.classes;
                    if (counter) counter.textContent = classes.length;
                    if (!classes.length) {
                        wrapper.innerHTML = '<p style="color:#888;font-size:12px;">No classes yet.</p>';
                        return;
                    }
                    let html = '';
                    classes.forEach(cls => {
                        html += '<div style="display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid #e5e7eb;font-size:12px;" id="class-row-' + cls.id + '">';
                        html += '<div style="flex:1;"><strong>' + jQuery('<span>').text(cls.name).html() + '</strong>';
                        if (cls.level) html += '<span style="color:#888;margin-left:4px;">(' + jQuery('<span>').text(cls.level).html() + ')</span>';
                        html += '<span style="margin-left:8px;color:#888;">' + cls.member_count + ' student' + (cls.member_count != 1 ? 's' : '') + '</span>';
                        html += '</div>';
                        html += '<span class="knowly-badge ' + (cls.status === 'active' ? 'ok' : '') + '" style="font-size:10px;">' + cls.status + '</span>';
                        html += '<a href="' + detailBase + cls.id + '" class="button button-small">View</a>';
                        if (cls.status === 'active') {
                            html += '<button class="button button-small" style="color:#dc2626;border-color:#dc2626;" '
                                  + 'onclick="knowlyTeacherAdmin.disbandClass(' + cls.id + ', ' + teacherId + ', \'' + nonce + '\')">Disband</button>';
                        }
                        html += '</div>';
                    });
                    wrapper.innerHTML = html;
                });
            },
            createClass(teacherId, nonce) {
                const name = prompt('New class name:');
                if (!name || !name.trim()) return;
                const level = prompt('Level (optional, e.g. std_4):') || '';
                jQuery.post(ajaxurl, {
                    action: 'knowly_teacher_create_class',
                    nonce: nonce,
                    teacher_id: teacherId,
                    name: name.trim(),
                    level: level.trim(),
                }, r => {
                    if (r.success) {
                        knowlyTeacherAdmin.refreshClasses(teacherId, nonce);
                    } else {
                        alert(r.data?.message || 'Error creating class.');
                    }
                });
            },
            disbandClass(classId, teacherId, nonce) {
                if (!confirm('Disband this class? All members will be removed.')) return;
                jQuery.post(ajaxurl, {
                    action: 'knowly_teacher_disband_class',
                    nonce: nonce,
                    class_id: classId,
                }, r => {
                    if (r.success) {
                        knowlyTeacherAdmin.refreshClasses(teacherId, nonce);
                    } else {
                        alert(r.data?.message || 'Error.');
                    }
                });
            },
        };
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

    // ── AJAX: Upload teacher ID document ─────────────────────────────────────

    public static function ajax_upload_id_doc(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        if ( ! $teacher_id || ! get_userdata( $teacher_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid teacher.' ] );
        }

        if ( empty( $_FILES['id_document'] ) || $_FILES['id_document']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'No file uploaded or upload error.' ] );
        }

        // Validate file type
        $allowed_types = [ 'image/png', 'image/jpeg', 'application/pdf' ];
        $finfo         = finfo_open( FILEINFO_MIME_TYPE );
        $mime          = finfo_file( $finfo, $_FILES['id_document']['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed_types, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid file type. Allowed: PNG, JPG, PDF.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Temporarily override $_FILES key so media_handle_upload finds it
        $_FILES['id_document']['name'] = sanitize_file_name( $_FILES['id_document']['name'] );

        $attachment_id = media_handle_upload( 'id_document', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        update_user_meta( $teacher_id, 'knowly_id_document', $attachment_id );

        Knowly_Debug::log( 'admin.teachers', 'ID document uploaded', [
            'teacher_id'    => $teacher_id,
            'attachment_id' => $attachment_id,
        ], null, 'info' );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
        ] );
    }

    // ── AJAX: Create class for teacher ────────────────────────────────────────

    public static function ajax_create_class(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $level      = sanitize_text_field( $_POST['level'] ?? '' );

        if ( ! $teacher_id || ! $name ) {
            wp_send_json_error( [ 'message' => 'Teacher ID and class name are required.' ] );
        }

        $class_id = Knowly_Class_Service::create( $teacher_id, [
            'name'  => $name,
            'level' => $level,
        ] );

        if ( is_wp_error( $class_id ) ) {
            wp_send_json_error( [ 'message' => $class_id->get_error_message() ] );
        }

        Knowly_Debug::log( 'admin.teachers', 'Class created via admin', [
            'teacher_id' => $teacher_id,
            'class_id'   => $class_id,
            'name'       => $name,
        ], null, 'info' );

        wp_send_json_success( [ 'class_id' => $class_id ] );
    }

    // ── AJAX: Disband class ───────────────────────────────────────────────────

    public static function ajax_disband_class(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $class_id = (int) ( $_POST['class_id'] ?? 0 );
        if ( ! $class_id ) {
            wp_send_json_error( [ 'message' => 'Invalid class ID.' ] );
        }

        global $wpdb;

        // Mark class as disbanded
        $wpdb->update(
            $wpdb->prefix . 'knowly_classes',
            [ 'status' => 'disbanded' ],
            [ 'id' => $class_id ]
        );

        // Remove all active members
        $wpdb->update(
            $wpdb->prefix . 'knowly_class_members',
            [ 'status' => 'removed' ],
            [ 'class_id' => $class_id, 'status' => 'active' ]
        );

        Knowly_Debug::log( 'admin.teachers', 'Class disbanded via admin', [
            'class_id' => $class_id,
        ], null, 'info' );

        wp_send_json_success();
    }

    // ── AJAX: Get teacher classes (fresh from DB) ─────────────────────────────

    public static function ajax_get_teacher_classes(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        if ( ! $teacher_id ) {
            wp_send_json_error( [ 'message' => 'Invalid teacher ID.' ] );
        }

        global $wpdb;

        // Flush wpdb query cache to ensure fresh data from DB
        $wpdb->flush();

        $classes = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, level, status,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}knowly_class_members m WHERE m.class_id = c.id AND m.status='active') AS member_count
             FROM {$wpdb->prefix}knowly_classes c
             WHERE teacher_user_id = %d
             ORDER BY created_at DESC",
            $teacher_id
        ), ARRAY_A ) ?: [];

        wp_send_json_success( [ 'classes' => $classes, 'teacher_id' => $teacher_id ] );
    }

    // ── AJAX: Add red gems to teacher ─────────────────────────────────────────

    public static function ajax_add_gems(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        $amount     = max( 1, (int) ( $_POST['amount'] ?? 0 ) );

        if ( ! $teacher_id || ! get_userdata( $teacher_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid teacher.' ] );
        }

        $result = Knowly_Red_Gem_Service::credit( $teacher_id, $amount, 'admin_credit' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'balance_after' => $result['balance_after'] ] );
    }

    // ── AJAX: Remove red gems from teacher ────────────────────────────────────

    public static function ajax_remove_gems(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $teacher_id = (int) ( $_POST['teacher_id'] ?? 0 );
        $amount     = max( 1, (int) ( $_POST['amount'] ?? 0 ) );

        if ( ! $teacher_id || ! get_userdata( $teacher_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid teacher.' ] );
        }

        $result = Knowly_Red_Gem_Service::deduct( $teacher_id, $amount, 'admin_deduct' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'balance_after' => $result['balance_after'] ] );
    }

    // ── AJAX: Update teacher settings ─────────────────────────────────────────

    public static function ajax_update_settings(): void {
        check_ajax_referer( 'knowly_teacher_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

        $teacher_id  = (int) ( $_POST['teacher_id']  ?? 0 );
        $first_name  = sanitize_text_field( $_POST['first_name']  ?? '' );
        $last_name   = sanitize_text_field( $_POST['last_name']   ?? '' );
        $school_name = sanitize_text_field( $_POST['school_name'] ?? '' );
        $class_name  = sanitize_text_field( $_POST['class_name']  ?? '' );
        $avatar      = max( 1, min( 10, (int) ( $_POST['avatar_index'] ?? 1 ) ) );

        if ( ! $teacher_id || ! get_userdata( $teacher_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid teacher.' ] );
        }

        // Update WP user core fields
        $update_data = [ 'ID' => $teacher_id ];
        if ( $first_name !== '' ) $update_data['first_name']   = $first_name;
        if ( $last_name  !== '' ) $update_data['last_name']    = $last_name;
        if ( $first_name !== '' || $last_name !== '' ) {
            $fn = $first_name ?: get_user_meta( $teacher_id, 'first_name', true );
            $ln = $last_name  ?: get_user_meta( $teacher_id, 'last_name',  true );
            $update_data['display_name'] = trim( $fn . ' ' . $ln );
        }
        wp_update_user( $update_data );

        // Update teacher-specific meta
        if ( $school_name !== '' ) update_user_meta( $teacher_id, 'knowly_school_name', $school_name );
        if ( $class_name  !== '' ) update_user_meta( $teacher_id, 'knowly_class_name',  $class_name );
        update_user_meta( $teacher_id, 'knowly_avatar_index', $avatar );

        Knowly_Debug::log( 'admin.teachers', 'Teacher settings updated via admin', [
            'teacher_id' => $teacher_id,
        ], null, 'info' );

        wp_send_json_success();
    }
}
