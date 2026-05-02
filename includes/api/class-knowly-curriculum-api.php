<?php
/**
 * Knowly_Curriculum_API — REST endpoints for curriculum topic management.
 *
 * All routes proxy to the Railway curriculum-topics service.
 * Admin-only (manage_options).
 *
 * Routes (under /knowly/v1):
 *   GET    /editor/curriculum-topics           list topics (filterable)
 *   POST   /editor/curriculum-topics           create topic
 *   PATCH  /editor/curriculum-topics/{id}      update topic
 *   DELETE /editor/curriculum-topics/{id}      archive topic
 *   POST   /editor/curriculum-topics/sync-pinecone  (Phase A3 — stub)
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Curriculum_API extends Knowly_API_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/editor/curriculum-topics', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_topics' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_topic' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/curriculum-topics/sync-pinecone', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'sync_pinecone' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/curriculum-topics/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update_topic' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'archive_topic' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function list_topics( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $params = array_filter( [
            'curriculum' => $request->get_param( 'curriculum' ) ?: 'tt_primary',
            'level'      => $request->get_param( 'level' ),
            'period'     => $request->get_param( 'period' ),
            'subject'    => $request->get_param( 'subject' ),
            'status'     => $request->get_param( 'status' ) ?: 'active',
            'page'       => $request->get_param( 'page' )     ?: 1,
            'per_page'   => $request->get_param( 'per_page' ) ?: 200,
        ], fn( $v ) => $v !== null && $v !== '' );

        $result = $this->railway_get( '/api/v1/curriculum-topics', $params );
        if ( is_wp_error( $result ) ) return $result;

        return new WP_REST_Response( $result, 200 );
    }

    public function create_topic( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $body = [
            'curriculum'    => $request->get_param( 'curriculum' ),
            'level'         => $request->get_param( 'level' ),
            'period'        => $request->get_param( 'period' ),
            'subject'       => $request->get_param( 'subject' ),
            'module_number' => $request->get_param( 'module_number' ),
            'module_title'  => $request->get_param( 'module_title' ),
            'sort_order'    => $request->get_param( 'sort_order' ),
            'topic'         => $request->get_param( 'topic' ),
            'source'        => $request->get_param( 'source' ) ?: 'manual',
        ];

        $result = $this->railway_post( '/api/v1/curriculum-topics', $body );
        if ( is_wp_error( $result ) ) return $result;

        return new WP_REST_Response( $result, 201 );
    }

    public function update_topic( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param( 'id' );

        $body = array_filter( [
            'module_title' => $request->get_param( 'module_title' ),
            'sort_order'   => $request->get_param( 'sort_order' ),
            'topic'        => $request->get_param( 'topic' ),
            'status'       => $request->get_param( 'status' ),
        ], fn( $v ) => $v !== null );

        $result = $this->railway_patch( '/api/v1/curriculum-topics/' . $id, $body );
        if ( is_wp_error( $result ) ) return $result;

        return new WP_REST_Response( $result, 200 );
    }

    public function archive_topic( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id     = (int) $request->get_param( 'id' );
        $result = $this->railway_delete( '/api/v1/curriculum-topics/' . $id );
        if ( is_wp_error( $result ) ) return $result;

        return new WP_REST_Response( $result, 200 );
    }

    public function sync_pinecone( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $body = array_filter( [
            'curriculum' => $request->get_param( 'curriculum' ) ?: 'tt_primary',
            'level'      => $request->get_param( 'level' ),
            'period'     => $request->get_param( 'period' ),
            'subject'    => $request->get_param( 'subject' ),
        ], fn( $v ) => $v !== null && $v !== '' );

        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $response = wp_remote_post( $endpoint . '/api/v1/curriculum-topics/sync', [
            'timeout' => 120,
            'headers' => $this->base_headers(),
            'body'    => wp_json_encode( $body ),
        ] );

        $result = $this->parse_railway_response( $response );
        if ( is_wp_error( $result ) ) return $result;

        return new WP_REST_Response( $result, 200 );
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function require_admin(): bool|WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Admin access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    // ── Railway HTTP helpers ──────────────────────────────────────────────────

    private function get_railway_token(): string {
        $admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        if ( ! empty( $admin_ids ) ) {
            return Knowly_JWT::encode( (int) $admin_ids[0] );
        }
        return get_option( 'knowly_railway_api_key', '' );
    }

    private function base_headers(): array {
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $headers    = [
            'Authorization' => 'Bearer ' . $this->get_railway_token(),
            'Content-Type'  => 'application/json',
        ];
        if ( $server_key ) {
            $headers['X-AEP-Server-Key'] = $server_key;
        }
        return $headers;
    }

    private function railway_get( string $path, array $params = [] ): array|WP_Error {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $url = $endpoint . $path;
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => $this->base_headers(),
        ] );

        return $this->parse_railway_response( $response );
    }

    private function railway_post( string $path, array $body ): array|WP_Error {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $response = wp_remote_post( $endpoint . $path, [
            'timeout' => 30,
            'headers' => $this->base_headers(),
            'body'    => wp_json_encode( $body ),
        ] );

        return $this->parse_railway_response( $response );
    }

    private function railway_patch( string $path, array $body ): array|WP_Error {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $response = wp_remote_request( $endpoint . $path, [
            'method'  => 'PATCH',
            'timeout' => 30,
            'headers' => $this->base_headers(),
            'body'    => wp_json_encode( $body ),
        ] );

        return $this->parse_railway_response( $response );
    }

    private function railway_delete( string $path ): array|WP_Error {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) {
            return new WP_Error( 'knowly_railway_not_configured', 'Railway endpoint not configured.', [ 'status' => 503 ] );
        }

        $response = wp_remote_request( $endpoint . $path, [
            'method'  => 'DELETE',
            'timeout' => 30,
            'headers' => $this->base_headers(),
        ] );

        return $this->parse_railway_response( $response );
    }

    private function parse_railway_response( $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            Knowly_Debug::log( 'curriculum.railway', 'Railway HTTP error', [ 'error' => $response->get_error_message() ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', 'Failed to connect to content service.', [ 'status' => 503 ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 404 ) {
            return new WP_Error( 'knowly_not_found', $body['error'] ?? 'Resource not found.', [ 'status' => 404 ] );
        }

        if ( $code === 403 ) {
            return new WP_Error( 'knowly_forbidden', $body['error'] ?? 'Operation not permitted.', [ 'status' => 403 ] );
        }

        if ( $code < 200 || $code >= 300 ) {
            Knowly_Debug::log( 'curriculum.railway', 'Railway bad response', [ 'http_code' => $code, 'body' => $body ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', $body['error'] ?? 'Content service returned an error.', [ 'status' => 502 ] );
        }

        return $body ?: [];
    }
}
