<?php
/**
 * Knowly_Admin_Lottie_Library — Upload, preview, and manage Lottie JSON files.
 *
 * Files are stored in wp-content/uploads/knowly-lottie/.
 * Metadata is tracked in wp_knowly_lottie.
 * The Quest editor's "Browse Library" button opens a picker modal powered by
 * the same JS that renders this page.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Lottie_Library {

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    // ── Boot ─────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_lottie_upload', [ __CLASS__, 'ajax_upload' ] );
        add_action( 'wp_ajax_knowly_lottie_list',   [ __CLASS__, 'ajax_list'   ] );
        add_action( 'wp_ajax_knowly_lottie_delete', [ __CLASS__, 'ajax_delete' ] );
    }

    // ── REST: Lottie proxy (CORS-safe on nginx) ───────────────────────────────

    public static function register_rest_routes(): void {
        register_rest_route( 'knowly/v1', '/lottie/(?P<filename>[a-zA-Z0-9_\-]+\.json)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'serve_lottie_file' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'filename' => [ 'required' => true, 'sanitize_callback' => 'sanitize_file_name' ],
            ],
        ] );
    }

    public static function serve_lottie_file( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $filename  = sanitize_file_name( $request->get_param( 'filename' ) );
        $file_path = self::upload_dir() . '/' . $filename;

        if ( ! file_exists( $file_path ) || pathinfo( $filename, PATHINFO_EXTENSION ) !== 'json' ) {
            return new \WP_Error( 'not_found', 'Lottie file not found.', [ 'status' => 404 ] );
        }

        $data = json_decode( file_get_contents( $file_path ) );
        if ( $data === null ) {
            return new \WP_Error( 'invalid_json', 'Invalid Lottie file.', [ 'status' => 500 ] );
        }

        $response = new \WP_REST_Response( $data, 200 );
        $response->header( 'Cache-Control', 'public, max-age=86400' );
        return $response;
    }

    // ── AJAX: Upload ──────────────────────────────────────────────────────────

    public static function ajax_upload(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        if ( empty( $_FILES['lottie_file'] ) || $_FILES['lottie_file']['error'] !== UPLOAD_ERR_OK ) {
            $code = $_FILES['lottie_file']['error'] ?? -1;
            wp_send_json_error( [ 'message' => "Upload error (code {$code})." ], 400 );
        }

        $file = $_FILES['lottie_file'];

        // Extension check
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'json' ) {
            wp_send_json_error( [ 'message' => 'Only .json files are accepted.' ], 400 );
        }

        // Size check
        if ( $file['size'] > self::MAX_FILE_SIZE ) {
            wp_send_json_error( [ 'message' => 'File exceeds 10 MB limit.' ], 400 );
        }

        // Read and validate JSON
        $raw = file_get_contents( $file['tmp_name'] );
        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) {
            wp_send_json_error( [ 'message' => 'File is not valid JSON.' ], 400 );
        }

        // Basic Lottie structure check
        if ( ! isset( $json['v'], $json['layers'] ) ) {
            wp_send_json_error( [ 'message' => 'File does not look like a Lottie animation (missing v or layers key).' ], 400 );
        }

        // Ensure upload directory exists
        self::ensure_dir();

        // Build a safe, unique filename
        $base     = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );
        $unique   = substr( md5( uniqid( '', true ) ), 0, 8 );
        $filename = $base . '-' . $unique . '.json';
        $dest     = self::upload_dir() . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( [ 'message' => 'Could not save file to server.' ], 500 );
        }

        $file_url = self::upload_url() . '/' . $filename;
        $name     = pathinfo( $file['name'], PATHINFO_FILENAME ); // original name without ext

        global $wpdb;
        $wpdb->insert(
            self::table(),
            [
                'name'        => sanitize_text_field( $name ),
                'file_url'    => $file_url,
                'file_path'   => $dest,
                'file_size'   => (int) $file['size'],
                'uploaded_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $wpdb->insert_id ) {
            @unlink( $dest );
            wp_send_json_error( [ 'message' => 'File saved but DB insert failed.' ], 500 );
        }

        wp_send_json_success( [
            'id'          => $wpdb->insert_id,
            'name'        => $name,
            'file_url'    => $file_url,
            'proxy_url'   => self::proxy_url( $filename ),
            'file_size'   => (int) $file['size'],
            'uploaded_at' => current_time( 'mysql' ),
        ] );
    }

    // ── AJAX: List ────────────────────────────────────────────────────────────

    public static function ajax_list(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name, file_url, file_size, uploaded_at
               FROM " . self::table() . "
              ORDER BY uploaded_at DESC",
            ARRAY_A
        );

        // Attach proxy_url so the JS can use the CORS-safe REST endpoint.
        foreach ( $rows as &$row ) {
            $row['proxy_url'] = self::proxy_url( basename( $row['file_url'] ) );
        }
        unset( $row );

        wp_send_json_success( $rows ?: [] );
    }

    // ── AJAX: Delete ──────────────────────────────────────────────────────────

    public static function ajax_delete(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => 'Invalid id.' ], 400 );
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, file_path FROM " . self::table() . " WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'File not found.' ], 404 );
        }

        // Remove from disk (ignore if already gone)
        if ( $row['file_path'] && file_exists( $row['file_path'] ) ) {
            @unlink( $row['file_path'] );
        }

        $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );

        wp_send_json_success( [ 'deleted' => $id ] );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        ?>
        <div class="wrap knowly-wrap">
            <h1>🎬 Lottie Library</h1>
            <p style="color:#6b7280;margin-top:-6px;margin-bottom:20px;">
                Upload Lottie JSON files exported from After Effects + BodyMovin.
                Files are stored on your WP server and referenced by URL in the Quest editor.
            </p>

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;padding:14px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;flex-wrap:wrap;">
                <label for="kn-lottie-file-input" class="button button-primary" style="cursor:pointer;margin:0;">
                    ⬆&nbsp; Upload JSON
                </label>
                <input type="file" id="kn-lottie-file-input" accept=".json,application/json" style="display:none;">
                <div id="kn-lottie-upload-bar" style="display:none;flex:1;min-width:160px;">
                    <div style="height:4px;background:#e5e7eb;border-radius:2px;overflow:hidden;">
                        <div id="kn-lottie-upload-progress" style="height:100%;width:0;background:#3b82f6;transition:width .2s;"></div>
                    </div>
                </div>
                <span id="kn-lottie-upload-status" style="font-size:13px;color:#6b7280;"></span>
                <span id="kn-lottie-count" style="margin-left:auto;font-size:12px;color:#9ca3af;"></span>
            </div>

            <div id="kn-lottie-grid" style="
                display:grid;
                grid-template-columns:repeat(auto-fill,minmax(210px,1fr));
                gap:16px;
            ">
                <p style="color:#9ca3af;font-size:13px;grid-column:1/-1;">Loading…</p>
            </div>
        </div>
        <?php
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'knowly_lottie';
    }

    private static function upload_dir(): string {
        return wp_upload_dir()['basedir'] . '/knowly-lottie';
    }

    private static function proxy_url( string $filename ): string {
        return rest_url( 'knowly/v1/lottie/' . rawurlencode( $filename ) );
    }

    private static function upload_url(): string {
        $base = wp_upload_dir()['baseurl'] . '/knowly-lottie';
        // Always serve over HTTPS — browsers block HTTP fetches from HTTPS pages.
        return set_url_scheme( $base, 'https' );
    }

    private static function ensure_dir(): void {
        $dir = self::upload_dir();
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            // Prevent directory listing
            file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.json$\">\n    Header set Access-Control-Allow-Origin \"*\"\n</FilesMatch>\n" );
            file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
        }
    }
}
