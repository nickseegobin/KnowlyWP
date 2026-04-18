<?php
/**
 * Knowly_Editor_API — REST endpoints for the Knowly Editor admin panel.
 *
 * Three content systems:
 *   1. Training Material — CRUD on wp_knowly_training_material + Pinecone (via Railway)
 *   2. Trials            — proxies to Railway /api/v1/trial-editor/* (reads exam_pool in Supabase)
 *   3. Quests            — proxies to Railway /api/v1/quest/* (reads quests in Supabase)
 *
 * All endpoints require manage_options (WP administrators only).
 * The plugin enforces this server-side on every request — the JS never trusts its own role check.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Editor_API extends Knowly_API_Base {

    public function register_routes(): void {
        // ── Training Material ─────────────────────────────────────────────────
        register_rest_route( $this->namespace, '/editor/training-material', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_training_material' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_training_material' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/training-material/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update_training_material' ],
                'permission_callback' => [ $this, 'require_admin' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_training_material' ],
                'permission_callback' => [ $this, 'require_admin' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ] ],
            ],
        ] );

        // ── Trials ────────────────────────────────────────────────────────────
        register_rest_route( $this->namespace, '/editor/trials', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_trials' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/trials/generate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'generate_trial' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/trials/(?P<package_id>[a-zA-Z0-9_\-]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_trial' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update_trial' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_trial' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/trials/(?P<package_id>[a-zA-Z0-9_\-]+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update_trial_status' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        // ── Quests ────────────────────────────────────────────────────────────
        register_rest_route( $this->namespace, '/editor/quests', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_quests' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/quests/generate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'generate_quest' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/quests/(?P<quest_id>[a-zA-Z0-9_\-]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_quest' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update_quest' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_quest' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/editor/quests/(?P<quest_id>[a-zA-Z0-9_\-]+)/status', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update_quest_status' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function require_admin(): bool|WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'knowly_forbidden', 'Administrator access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    // =========================================================================
    // TRAINING MATERIAL
    // =========================================================================

    /**
     * GET /editor/training-material
     * Query params: curriculum, level, period, subject, status (default: active)
     */
    public function list_training_material( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_training_material';

        $curriculum = sanitize_text_field( $request->get_param( 'curriculum' ) ?: 'tt_primary' );
        $level      = sanitize_text_field( $request->get_param( 'level' ) ?: '' );
        $period     = sanitize_text_field( $request->get_param( 'period' ) ?: '' );
        $subject    = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );
        $status     = sanitize_text_field( $request->get_param( 'status' ) ?: 'active' );

        $where  = [ 'curriculum = %s', 'status = %s' ];
        $params = [ $curriculum, $status ];

        if ( $level )   { $where[] = 'level = %s';   $params[] = $level; }
        if ( $period )  { $where[] = 'period = %s';  $params[] = $period; }
        if ( $subject ) { $where[] = 'subject = %s'; $params[] = $subject; }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, vector_id, curriculum, level, period, subject, topic, subtopic,
                        LEFT(content_text, 120) AS content_preview, status, created_at, updated_at
                 FROM {$table} {$where_sql}
                 ORDER BY level, subject, topic
                 LIMIT 500",
                ...$params
            ),
            ARRAY_A
        );

        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE curriculum = %s AND status = 'active'", $curriculum )
        );

        return rest_ensure_response( [
            'items' => $items ?: [],
            'total' => $total,
        ] );
    }

    /**
     * POST /editor/training-material
     * Body: { curriculum, level, period, subject, topic, subtopic, content_text }
     * Embeds content via Railway → Pinecone, then stores in WP table.
     */
    public function create_training_material( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $curriculum   = sanitize_text_field( $request->get_param( 'curriculum' ) ?: 'tt_primary' );
        $level        = sanitize_text_field( $request->get_param( 'level' ) ?: '' );
        $period       = sanitize_text_field( $request->get_param( 'period' ) ?: '' );
        $subject      = sanitize_text_field( $request->get_param( 'subject' ) ?: '' );
        $topic        = sanitize_text_field( $request->get_param( 'topic' ) ?: '' );
        $subtopic     = sanitize_text_field( $request->get_param( 'subtopic' ) ?: '' );
        $content_text = sanitize_textarea_field( $request->get_param( 'content_text' ) ?: '' );

        if ( ! $level || ! $subject || ! $topic || ! $content_text ) {
            return new WP_Error( 'knowly_missing_fields', 'level, subject, topic, and content_text are required.', [ 'status' => 422 ] );
        }

        // Build a deterministic vector ID
        $slug      = sanitize_title( "{$level}-{$period}-{$subject}-{$topic}" );
        $rand      = substr( md5( uniqid( '', true ) ), 0, 6 );
        $vector_id = "tm-{$curriculum}-{$slug}-{$rand}";

        // Upsert to Pinecone via Railway
        $pinecone = $this->railway_post( '/api/v1/training/upsert', [
            'vector_id'    => $vector_id,
            'content_text' => $content_text,
            'metadata'     => [
                'curriculum' => $curriculum,
                'level'      => $level,
                'period'     => $period ?: null,
                'subject'    => $subject,
                'topic'      => $topic,
                'subtopic'   => $subtopic ?: null,
            ],
        ] );

        if ( is_wp_error( $pinecone ) ) {
            return $pinecone;
        }

        $now = current_time( 'mysql', true );
        $wpdb->insert(
            $wpdb->prefix . 'knowly_training_material',
            [
                'vector_id'    => $vector_id,
                'curriculum'   => $curriculum,
                'level'        => $level,
                'period'       => $period ?: null,
                'subject'      => $subject,
                'topic'        => $topic,
                'subtopic'     => $subtopic ?: null,
                'content_text' => $content_text,
                'status'       => 'active',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $wpdb->last_error ) {
            Knowly_Debug::log( 'editor.training', 'DB insert failed after Pinecone upsert', [ 'error' => $wpdb->last_error ], null, 'error' );
            return new WP_Error( 'knowly_db_error', 'Training material saved to Pinecone but WP record failed.', [ 'status' => 500 ] );
        }

        $new_id = $wpdb->insert_id;
        Knowly_Debug::log( 'editor.training', 'Training material created', [ 'id' => $new_id, 'vector_id' => $vector_id ], null, 'info' );

        return new WP_REST_Response( [ 'id' => $new_id, 'vector_id' => $vector_id ], 201 );
    }

    /**
     * PATCH /editor/training-material/{id}
     * Body: { topic?, subtopic?, content_text? }  — re-embeds if content_text changes.
     */
    public function update_training_material( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_training_material';
        $id    = (int) $request->get_param( 'id' );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Training material not found.', [ 'status' => 404 ] );
        }

        $topic        = sanitize_text_field( $request->get_param( 'topic' ) ?? $row['topic'] );
        $subtopic     = sanitize_text_field( $request->get_param( 'subtopic' ) ?? $row['subtopic'] );
        $content_text = sanitize_textarea_field( $request->get_param( 'content_text' ) ?? $row['content_text'] );

        // Re-upsert to Pinecone (re-embeds with new content)
        $pinecone = $this->railway_post( '/api/v1/training/upsert', [
            'vector_id'    => $row['vector_id'],
            'content_text' => $content_text,
            'metadata'     => [
                'curriculum' => $row['curriculum'],
                'level'      => $row['level'],
                'period'     => $row['period'] ?: null,
                'subject'    => $row['subject'],
                'topic'      => $topic,
                'subtopic'   => $subtopic ?: null,
            ],
        ] );

        if ( is_wp_error( $pinecone ) ) {
            return $pinecone;
        }

        $wpdb->update(
            $table,
            [
                'topic'        => $topic,
                'subtopic'     => $subtopic ?: null,
                'content_text' => $content_text,
                'updated_at'   => current_time( 'mysql', true ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        Knowly_Debug::log( 'editor.training', 'Training material updated', [ 'id' => $id, 'vector_id' => $row['vector_id'] ], null, 'info' );
        return rest_ensure_response( [ 'id' => $id, 'vector_id' => $row['vector_id'], 'updated' => true ] );
    }

    /**
     * DELETE /editor/training-material/{id}
     * Removes from Pinecone (via Railway) and from WP table.
     */
    public function delete_training_material( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_training_material';
        $id    = (int) $request->get_param( 'id' );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'knowly_not_found', 'Training material not found.', [ 'status' => 404 ] );
        }

        // Delete from Pinecone via Railway
        $pinecone = $this->railway_post( '/api/v1/training/delete', [ 'vector_id' => $row['vector_id'] ] );
        if ( is_wp_error( $pinecone ) ) {
            return $pinecone;
        }

        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        Knowly_Debug::log( 'editor.training', 'Training material deleted', [ 'id' => $id, 'vector_id' => $row['vector_id'] ], null, 'info' );
        return rest_ensure_response( [ 'id' => $id, 'deleted' => true ] );
    }

    // =========================================================================
    // TRIALS — proxies to Railway /api/v1/trial-editor/*
    // =========================================================================

    /** GET /editor/trials */
    public function list_trials( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $params = array_filter( [
            'curriculum'  => $request->get_param( 'curriculum' ),
            'level'       => $request->get_param( 'level' ),
            'period'      => $request->get_param( 'period' ),
            'subject'     => $request->get_param( 'subject' ),
            'difficulty'  => $request->get_param( 'difficulty' ),
            'trial_type'  => $request->get_param( 'trial_type' ),
            'status'      => $request->get_param( 'status' ),
            'page'        => $request->get_param( 'page' ),
            'per_page'    => $request->get_param( 'per_page' ),
        ], fn( $v ) => $v !== null && $v !== '' );

        $result = $this->railway_get( '/api/v1/trial-editor/list', $params );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    /** GET /editor/trials/{package_id} */
    public function get_trial( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $package_id = sanitize_text_field( $request->get_param( 'package_id' ) );
        $result = $this->railway_get( '/api/v1/trial-editor/' . rawurlencode( $package_id ) );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    /** POST /editor/trials/generate */
    public function generate_trial( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $body = [
            'user_id'    => 'editor_admin',
            'curriculum' => sanitize_text_field( $request->get_param( 'curriculum' ) ?: 'tt_primary' ),
            'level'      => sanitize_text_field( $request->get_param( 'level' ) ?: '' ),
            'period'     => sanitize_text_field( $request->get_param( 'period' ) ?: '' ) ?: null,
            'subject'    => sanitize_text_field( $request->get_param( 'subject' ) ?: '' ),
            'difficulty' => sanitize_text_field( $request->get_param( 'difficulty' ) ?: '' ) ?: null,
            'trial_type' => sanitize_text_field( $request->get_param( 'trial_type' ) ?: 'practice' ),
            'topic'      => sanitize_text_field( $request->get_param( 'topic' ) ?: '' ) ?: null,
            'force_generate' => true,
            'status'     => 'pending_review',
        ];

        if ( ! $body['level'] || ! $body['subject'] ) {
            return new WP_Error( 'knowly_missing_fields', 'level and subject are required.', [ 'status' => 422 ] );
        }

        $result = $this->railway_post( '/api/v1/generate-exam', $body );
        if ( is_wp_error( $result ) ) return $result;
        return new WP_REST_Response( $result, 201 );
    }

    /** PATCH /editor/trials/{package_id} */
    public function update_trial( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $package_id   = sanitize_text_field( $request->get_param( 'package_id' ) );
        $package_data = $request->get_param( 'package_data' );

        if ( ! $package_data ) {
            return new WP_Error( 'knowly_missing_fields', 'package_data is required.', [ 'status' => 422 ] );
        }

        $result = $this->railway_patch( '/api/v1/trial-editor/' . rawurlencode( $package_id ), [
            'package_data' => $package_data,
        ] );
        if ( is_wp_error( $result ) ) return $result;
        $this->sync_trial_to_wp( $package_id );
        return rest_ensure_response( $result );
    }

    /** PATCH /editor/trials/{package_id}/status */
    public function update_trial_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $package_id = sanitize_text_field( $request->get_param( 'package_id' ) );
        $status     = sanitize_text_field( $request->get_param( 'status' ) ?: '' );

        if ( ! $status ) {
            return new WP_Error( 'knowly_missing_fields', 'status is required.', [ 'status' => 422 ] );
        }

        $result = $this->railway_patch( '/api/v1/trial-editor/' . rawurlencode( $package_id ) . '/status', [
            'status' => $status,
        ] );
        if ( is_wp_error( $result ) ) return $result;
        $this->sync_trial_to_wp( $package_id );
        return rest_ensure_response( $result );
    }

    /** DELETE /editor/trials/{package_id} */
    public function delete_trial( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $package_id = sanitize_text_field( $request->get_param( 'package_id' ) );
        $result     = $this->railway_delete( '/api/v1/trial-editor/' . rawurlencode( $package_id ) );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    // =========================================================================
    // QUESTS — proxies to Railway /api/v1/quest/*
    // =========================================================================

    /** GET /editor/quests */
    public function list_quests( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $params = array_filter( [
            'curriculum' => $request->get_param( 'curriculum' ),
            'level'      => $request->get_param( 'level' ),
            'period'     => $request->get_param( 'period' ),
            'subject'    => $request->get_param( 'subject' ),
            'status'     => $request->get_param( 'status' ),
            'page'       => $request->get_param( 'page' ),
            'per_page'   => $request->get_param( 'per_page' ),
        ], fn( $v ) => $v !== null && $v !== '' );

        $result = $this->railway_get( '/api/v1/quest/list', $params );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    /** GET /editor/quests/{quest_id} */
    public function get_quest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $quest_id = sanitize_text_field( $request->get_param( 'quest_id' ) );
        $result   = $this->railway_get( '/api/v1/quest/editor/' . rawurlencode( $quest_id ) );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    /** POST /editor/quests/generate */
    public function generate_quest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $body = [
            'curriculum'   => sanitize_text_field( $request->get_param( 'curriculum' ) ?: 'tt_primary' ),
            'level'        => sanitize_text_field( $request->get_param( 'level' ) ?: '' ),
            'period'       => sanitize_text_field( $request->get_param( 'period' ) ?: '' ) ?: null,
            'subject'      => sanitize_text_field( $request->get_param( 'subject' ) ?: '' ),
            'topic'        => sanitize_text_field( $request->get_param( 'topic' ) ?: '' ) ?: null,
            'module_index' => $request->get_param( 'module_index' ) !== null ? (int) $request->get_param( 'module_index' ) : null,
            'status'       => 'draft',
        ];

        if ( ! $body['level'] || ! $body['subject'] ) {
            return new WP_Error( 'knowly_missing_fields', 'level and subject are required.', [ 'status' => 422 ] );
        }

        $result = $this->railway_post( '/api/v1/quest/generate', $body );
        if ( is_wp_error( $result ) ) return $result;
        return new WP_REST_Response( $result, 201 );
    }

    /** PATCH /editor/quests/{quest_id} — saves edited content (resets to draft) */
    public function update_quest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $quest_id   = sanitize_text_field( $request->get_param( 'quest_id' ) );
        $content    = $request->get_param( 'content' );
        $objectives = $request->get_param( 'objectives' );

        if ( ! $content ) {
            return new WP_Error( 'knowly_missing_fields', 'content is required.', [ 'status' => 422 ] );
        }

        $body = [ 'quest_id' => $quest_id, 'content' => $content ];
        if ( $objectives !== null ) $body['objectives'] = $objectives;

        $result = $this->railway_post( '/api/v1/quest/save', $body );
        if ( is_wp_error( $result ) ) return $result;
        $this->sync_quest_to_wp( $quest_id );
        return rest_ensure_response( $result );
    }

    /** PATCH /editor/quests/{quest_id}/status */
    public function update_quest_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $quest_id = sanitize_text_field( $request->get_param( 'quest_id' ) );
        $status   = sanitize_text_field( $request->get_param( 'status' ) ?: '' );

        if ( ! $status ) {
            return new WP_Error( 'knowly_missing_fields', 'status is required.', [ 'status' => 422 ] );
        }

        $result = $this->railway_patch( '/api/v1/quest/status', [
            'quest_id' => $quest_id,
            'status'   => $status,
        ] );
        if ( is_wp_error( $result ) ) return $result;
        $this->sync_quest_to_wp( $quest_id );
        return rest_ensure_response( $result );
    }

    /** DELETE /editor/quests/{quest_id} */
    public function delete_quest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $quest_id = sanitize_text_field( $request->get_param( 'quest_id' ) );
        $result   = $this->railway_delete( '/api/v1/quest/editor/' . rawurlencode( $quest_id ) );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    // =========================================================================
    // WP sync helpers — keep wp_knowly_quests / wp_knowly_trial_packages in sync
    // =========================================================================

    /**
     * Re-fetch a quest from Railway and upsert into wp_knowly_quests (student variant).
     * Called automatically after every save or status change via the Editor.
     */
    private function sync_quest_to_wp( string $quest_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_quests';

        $data = $this->railway_get( '/api/v1/quest/editor/' . rawurlencode( $quest_id ) );
        if ( is_wp_error( $data ) ) {
            Knowly_Debug::log( 'editor.sync', 'Quest WP sync failed — Railway fetch error', [
                'quest_id' => $quest_id,
                'error'    => $data->get_error_message(),
            ], null, 'error' );
            return;
        }

        $now = current_time( 'mysql', true );

        $row = [
            'quest_id'        => $quest_id,
            'variant'         => 'student',
            'curriculum'      => $data['curriculum']    ?? 'tt_primary',
            'level'           => $data['level']         ?? '',
            'period'          => $data['period']        ?? null,
            'subject'         => $data['subject']       ?? '',
            'topic'           => $data['topic']         ?? null,
            'module_number'   => isset( $data['module_number'] ) ? (int) $data['module_number'] : null,
            'module_title'    => $data['module_title']  ?? null,
            'objectives'      => isset( $data['objectives'] ) ? wp_json_encode( $data['objectives'] ) : null,
            'content'         => isset( $data['content'] )    ? wp_json_encode( $data['content'] )    : null,
            'status'          => $data['status']        ?? 'draft',
            'railway_quest_id'=> $quest_id,
            'generated_at'    => ! empty( $data['generated_at'] )
                                    ? gmdate( 'Y-m-d H:i:s', strtotime( $data['generated_at'] ) )
                                    : $now,
            'approved_at'     => ! empty( $data['approved_at'] )
                                    ? gmdate( 'Y-m-d H:i:s', strtotime( $data['approved_at'] ) )
                                    : null,
            'updated_at'      => $now,
        ];

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE quest_id = %s AND variant = 'student'", $quest_id )
        );

        if ( $exists ) {
            $wpdb->update( $table, $row, [ 'quest_id' => $quest_id, 'variant' => 'student' ] );
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( $table, $row );
        }

        Knowly_Debug::log( 'editor.sync', 'Quest synced to WP', [
            'quest_id' => $quest_id,
            'status'   => $row['status'],
        ], null, 'info' );
    }

    /**
     * Re-fetch a trial package from Railway and upsert into wp_knowly_trial_packages.
     * Called automatically after every edit or status change via the Editor.
     */
    private function sync_trial_to_wp( string $package_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'knowly_trial_packages';

        $data = $this->railway_get( '/api/v1/trial-editor/' . rawurlencode( $package_id ) );
        if ( is_wp_error( $data ) ) {
            Knowly_Debug::log( 'editor.sync', 'Trial WP sync failed — Railway fetch error', [
                'package_id' => $package_id,
                'error'      => $data->get_error_message(),
            ], null, 'error' );
            return;
        }

        $pkg  = $data['package'] ?? [];
        $meta = $pkg['meta']     ?? [];
        $now  = current_time( 'mysql', true );

        $row = [
            'package_id'   => $package_id,
            'curriculum'   => $meta['curriculum'] ?? 'tt_primary',
            'level'        => $meta['level']      ?? '',
            'period'       => $meta['period']     ?? null,
            'subject'      => $meta['subject']    ?? '',
            'difficulty'   => $meta['difficulty'] ?? null,
            'trial_type'   => $meta['trial_type'] ?? 'practice',
            'topic'        => $meta['topic']      ?? null,
            'questions'    => isset( $pkg['questions'] )    ? wp_json_encode( $pkg['questions'] )    : null,
            'answer_sheet' => isset( $pkg['answer_sheet'] ) ? wp_json_encode( $pkg['answer_sheet'] ) : null,
            'meta'         => $meta                         ? wp_json_encode( $meta )                : null,
            'status'       => $data['status']     ?? 'pending_review',
            'synced_at'    => $now,
            'updated_at'   => $now,
        ];

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE package_id = %s", $package_id )
        );

        if ( $exists ) {
            $wpdb->update( $table, $row, [ 'package_id' => $package_id ] );
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( $table, $row );
        }

        Knowly_Debug::log( 'editor.sync', 'Trial synced to WP', [
            'package_id' => $package_id,
            'status'     => $row['status'],
        ], null, 'info' );
    }

    // =========================================================================
    // Railway HTTP helpers
    // =========================================================================

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
            'timeout' => 120, // generation can be slow
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
            Knowly_Debug::log( 'editor.railway', 'Railway HTTP error', [ 'error' => $response->get_error_message() ], null, 'error' );
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
            Knowly_Debug::log( 'editor.railway', 'Railway bad response', [ 'http_code' => $code, 'body' => $body ], null, 'error' );
            return new WP_Error( 'knowly_railway_error', $body['error'] ?? 'Content service returned an error.', [ 'status' => 502 ] );
        }

        return $body ?: [];
    }
}
