<?php
/**
 * Knowly_Admin_Design — Admin settings page for site-wide design / animations.
 *
 * Sub-tabs: Home, Student, Parent, Teacher.
 * Home tab stores: lottie URL, fallback image URL, animation scale (1–5).
 * Other tabs store: lottie URL only (expandable later).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Design {

    private static array $tabs = [
        'general' => 'General',
        'home'    => 'Home',
        'student' => 'Student',
        'parent'  => 'Parent',
        'teacher' => 'Teacher',
    ];

    private static array $scale_options = [
        '1' => '1× — Small',
        '2' => '2× — Medium-small',
        '3' => '3× — Medium',
        '4' => '4× — Large',
        '5' => '5× — Full width',
    ];

    public static function boot(): void {
        // nothing beyond menu registration (done in Knowly_Admin)
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $active_tab = sanitize_key( $_GET['tab'] ?? 'home' );
        if ( ! array_key_exists( $active_tab, self::$tabs ) ) {
            $active_tab = 'home';
        }

        // ── Save ────────────────────────────────────────────────────────────────
        $saved = false;
        if (
            isset( $_POST['knowly_design_nonce'] ) &&
            wp_verify_nonce( $_POST['knowly_design_nonce'], 'knowly_save_design_' . $active_tab )
        ) {
            // Lottie URL (all tabs)
            $lottie_key = 'knowly_design_' . $active_tab . '_lottie';
            update_option( $lottie_key, esc_url_raw( wp_unslash( $_POST[ $lottie_key ] ?? '' ) ) );

            // Home-only fields
            if ( $active_tab === 'home' ) {
                update_option( 'knowly_design_home_image', esc_url_raw( wp_unslash( $_POST['knowly_design_home_image'] ?? '' ) ) );
                $scale = (int) ( $_POST['knowly_design_home_scale'] ?? 3 );
                update_option( 'knowly_design_home_scale', max( 1, min( 5, $scale ) ) );
            }

            $saved = true;
        }

        $current_lottie = (string) get_option( 'knowly_design_' . $active_tab . '_lottie', '' );
        $current_image  = $active_tab === 'home' ? (string) get_option( 'knowly_design_home_image', '' ) : '';
        $current_scale  = $active_tab === 'home' ? (string) get_option( 'knowly_design_home_scale', '3' ) : '3';
        $page_url       = admin_url( 'admin.php?page=knowly-design' );
        ?>
        <div class="wrap knowly-wrap">
            <h1>Design</h1>
            <p style="color:#777;margin-bottom:1.5em;">
                Configure Lottie animations and fallback images displayed across the app.
                Paste a Lottie URL (e.g. <code>https://lottie.host/…/.lottie</code>) or an image URL for the fallback.
                Leave fields blank to show nothing.
            </p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Design settings saved. Reload the React app page to see changes.</p>
                </div>
            <?php endif; ?>

            <!-- ── Tab nav ─────────────────────────────────────────────────── -->
            <nav class="nav-tab-wrapper knowly-tab-nav" style="margin-bottom:1.5em;">
                <?php foreach ( self::$tabs as $slug => $label ) : ?>
                    <a href="<?= esc_url( add_query_arg( 'tab', $slug, $page_url ) ) ?>"
                       class="nav-tab <?= $active_tab === $slug ? 'nav-tab-active' : '' ?>">
                        <?= esc_html( $label ) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- ── Tab content ─────────────────────────────────────────────── -->
            <form method="post" action="<?= esc_url( add_query_arg( 'tab', $active_tab, $page_url ) ) ?>">
                <?php wp_nonce_field( 'knowly_save_design_' . $active_tab, 'knowly_design_nonce' ); ?>

                <!-- Lottie URL -->
                <div class="knowly-settings-section">
                    <h2><?= esc_html( self::tab_heading( $active_tab ) ) ?></h2>
                    <?= self::tab_description( $active_tab ) ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="knowly_design_lottie_url"><?= esc_html( self::tab_field_label( $active_tab ) ) ?></label></th>
                            <td>
                                <input
                                    type="url"
                                    id="knowly_design_lottie_url"
                                    name="knowly_design_<?= esc_attr( $active_tab ) ?>_lottie"
                                    value="<?= esc_attr( $current_lottie ) ?>"
                                    class="large-text"
                                    placeholder="https://lottie.host/…/animation.lottie"
                                />
                                <p class="description">
                                    Supported: <code>.lottie</code> and <code>.json</code>.
                                    Takes priority over the fallback image when set.
                                </p>
                            </td>
                        </tr>

                        <?php if ( $active_tab === 'home' ) : ?>

                        <!-- Scale -->
                        <tr>
                            <th>Animation Scale</th>
                            <td>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                                    <?php foreach ( self::$scale_options as $val => $label ) : ?>
                                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;
                                               background:<?= $current_scale === $val ? '#2271b1' : '#f0f0f1' ?>;
                                               color:<?= $current_scale === $val ? '#fff' : '#1d2327' ?>;
                                               padding:6px 14px;border-radius:4px;font-size:13px;font-weight:<?= $current_scale === $val ? '600' : '400' ?>;">
                                            <input type="radio"
                                                   name="knowly_design_home_scale"
                                                   value="<?= esc_attr( $val ) ?>"
                                                   <?= checked( $current_scale, $val, false ) ?>
                                                   style="display:none;"
                                                   onchange="this.closest('.knowly-scale-row').querySelectorAll('label').forEach(function(l){l.style.background='#f0f0f1';l.style.color='#1d2327';l.style.fontWeight='400'});this.closest('label').style.background='#2271b1';this.closest('label').style.color='#fff';this.closest('label').style.fontWeight='600';" />
                                            <?= esc_html( $val . '×' ) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description" style="margin-top:8px;">
                                    Controls how much of the left panel the animation fills.
                                    1× = small, 5× = full width.
                                </p>
                            </td>
                        </tr>

                        <!-- Fallback Image -->
                        <tr>
                            <th><label for="knowly_design_home_image">Fallback Image URL</label></th>
                            <td>
                                <input
                                    type="url"
                                    id="knowly_design_home_image"
                                    name="knowly_design_home_image"
                                    value="<?= esc_attr( $current_image ) ?>"
                                    class="large-text"
                                    placeholder="https://…/image.png"
                                />
                                <p class="description">
                                    Shown on the homepage left panel when no Lottie URL is set.
                                    Leave blank to show nothing.
                                </p>
                                <?php if ( $current_image ) : ?>
                                    <div style="margin-top:12px;">
                                        <img src="<?= esc_attr( $current_image ) ?>" alt="Fallback image preview"
                                             style="max-width:200px;max-height:200px;border-radius:8px;border:1px solid #ddd;object-fit:contain;" />
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <?php endif; ?>
                    </table>
                </div>

                <!-- ── Live preview (Lottie) ─────────────────────────────────── -->
                <?php if ( $current_lottie ) : ?>
                <div class="knowly-settings-section">
                    <h2>Lottie Preview</h2>
                    <p class="description" style="margin-bottom:12px;">Live preview of the saved animation.</p>
                    <div style="width:300px;height:300px;border:1px solid #ddd;border-radius:8px;overflow:hidden;background:#f6f7f7;">
                        <dotlottie-player
                            src="<?= esc_attr( $current_lottie ) ?>"
                            autoplay
                            loop
                            style="width:100%;height:100%;">
                        </dotlottie-player>
                    </div>
                    <script src="https://unpkg.com/@dotlottie/player-component@latest/dist/dotlottie-player.js" type="module"></script>
                </div>
                <?php endif; ?>

                <div style="margin-top:2em;">
                    <?php submit_button( 'Save Design Settings' ); ?>
                </div>
            </form>

            <!-- ── Current settings JSON ─────────────────────────────────────── -->
            <div class="knowly-settings-section">
                <h2>Current Settings (JSON)</h2>
                <p class="description" style="margin-bottom:8px;">
                    The exact payload the React app receives from <code>GET /knowly/v1/design</code>.
                </p>
                <textarea readonly rows="10" class="large-text" style="font-family:monospace;font-size:12px;"><?= esc_textarea( wp_json_encode( Knowly_Design_API::current_settings(), JSON_PRETTY_PRINT ) ) ?></textarea>
            </div>
        </div>

        <style>
        .knowly-scale-row { display:flex; gap:12px; flex-wrap:wrap; }
        </style>

        <script>
        // Wrap the scale labels in a div with the class so the onchange selector works
        (function () {
            var scaleLabels = document.querySelectorAll('[name="knowly_design_home_scale"]');
            if (!scaleLabels.length) return;
            var wrapper = scaleLabels[0].closest('td').querySelector('div');
            if (wrapper) wrapper.classList.add('knowly-scale-row');
        })();
        </script>
        <?php
    }

    private static function tab_heading( string $tab ): string {
        $headings = [
            'general' => 'General — Loading Screen',
            'home'    => 'Home — Lottie Animation',
            'student' => 'Student — Lottie Animation',
            'parent'  => 'Parent — Lottie Animation',
            'teacher' => 'Teacher — Lottie Animation',
        ];
        return $headings[ $tab ] ?? ( self::$tabs[ $tab ] . ' — Lottie Animation' );
    }

    private static function tab_field_label( string $tab ): string {
        return $tab === 'general' ? 'Loading Animation URL' : 'Lottie URL';
    }

    private static function tab_description( string $tab ): string {
        $descriptions = [
            'general' => '<p class="description" style="margin-bottom:12px;">Shown as an overlay for ~1 second while Lottie + audio assets load when a student enters a synced lesson paragraph. Leave blank to skip the buffer and play immediately.</p>',
            'home'    => '<p class="description" style="margin-bottom:12px;">Displayed on the landing page left panel. Lottie takes priority; fallback image shows if no Lottie URL is set.</p>',
            'student' => '<p class="description" style="margin-bottom:12px;">Displayed on student-facing screens when set.</p>',
            'parent'  => '<p class="description" style="margin-bottom:12px;">Displayed on parent-facing screens when set.</p>',
            'teacher' => '<p class="description" style="margin-bottom:12px;">Displayed on teacher-facing screens when set.</p>',
        ];
        return $descriptions[ $tab ] ?? '';
    }
}
