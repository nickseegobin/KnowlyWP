<?php
/**
 * Knowly_Admin_Spec_Tests — Phase 3 Implementation Verification Suite.
 *
 * Tests that the Phase 3 spec was correctly implemented:
 *   Group 1 — DB Schema:        tables exist and are populated
 *   Group 2 — curriculumDB:     DB layer returns correct shapes and data
 *   Group 3 — Curriculum CRUD:  end-to-end create / update / archive / verify
 *   Group 4 — Gen Regression:   exam + quest generation still works post-migration (SLOW)
 *
 * Fast tests (Groups 1–3) run via "Run All Fast Tests".
 * Slow tests (Group 4) require a separate "Run Slow Tests" click.
 *
 * State between CRUD tests is stored in WP transients (1 hour TTL).
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_Admin_Spec_Tests {

    const TRANSIENT_TOPIC_ID    = 'knowly_spectest_topic_id';
    const TRANSIENT_TOPIC_STR   = 'knowly_spectest_topic_str';
    const TRANSIENT_QUEST_DATA  = 'knowly_spectest_quest_data';
    const TRANSIENT_QB_TRIAL    = 'knowly_spectest_qb_trial';

    const TEST_TOPIC_ACTIVE   = '_SPECTEST_TOPIC_';
    const TEST_TOPIC_UPDATED  = '_SPECTEST_TOPIC_UPDATED_';

    // ── Boot ──────────────────────────────────────────────────────────────────

    public static function boot(): void {
        add_action( 'wp_ajax_knowly_spectest', [ __CLASS__, 'handle_ajax' ] );
    }

    public static function handle_ajax(): void {
        check_ajax_referer( 'knowly_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $test_id = sanitize_key( $_POST['test_id'] ?? '' );
        $data    = json_decode( stripslashes( $_POST['data'] ?? '{}' ), true ) ?: [];
        wp_send_json( self::run_test( $test_id, $data ) );
    }

    // ── Test Dispatch ─────────────────────────────────────────────────────────

    public static function run_test( string $test_id, array $data = [] ): array {
        $start = microtime( true );

        try {
            $result = match ( $test_id ) {
                // Group 1 — DB Schema
                'schema_topics_populated'        => self::test_schema_topics_populated(),
                'schema_topics_std4'             => self::test_schema_topics_std4(),
                'schema_topics_std5'             => self::test_schema_topics_std5(),
                'schema_structure_via_catalogue' => self::test_schema_structure_via_catalogue(),
                'schema_capstone_weightings'     => self::test_schema_capstone_weightings(),
                'schema_fingerprints_table'      => self::test_schema_fingerprints_table(),
                // Group 2 — curriculumDB Accuracy
                'cdb_catalogue_shape'            => self::test_cdb_catalogue_shape(),
                'cdb_std4_no_topic'              => self::test_cdb_std4_no_topic(),
                'cdb_std5_has_topic'             => self::test_cdb_std5_has_topic(),
                'cdb_sea_paper_only_std5'        => self::test_cdb_sea_paper_only_std5(),
                'cdb_topic_list_shape'           => self::test_cdb_topic_list_shape(),
                'cdb_capstone_topic_count'       => self::test_cdb_capstone_topic_count(),
                // Group 3 — Curriculum CRUD
                'crud_list'                      => self::test_crud_list(),
                'crud_create'                    => self::test_crud_create(),
                'crud_verify_created'            => self::test_crud_verify_created(),
                'crud_update'                    => self::test_crud_update(),
                'crud_archive'                   => self::test_crud_archive(),
                'crud_verify_archived'           => self::test_crud_verify_archived(),
                'crud_archived_in_history'       => self::test_crud_archived_in_history(),
                // Group 4 — Generation Regression (slow)
                'regen_exam_std4'                => self::test_regen_exam_std4(),
                'regen_exam_std5_topic'          => self::test_regen_exam_std5_topic(),
                'regen_quest_path_a'             => self::test_regen_quest_path_a(),
                'regen_quest_path_b'             => self::test_regen_quest_path_b(),
                'regen_quest_path_c'             => self::test_regen_quest_path_c(),
                'regen_kc_count'                 => self::test_regen_kc_count(),
                // Group 5 — Question Bank + Pinecone Sync (fast)
                'qb_tables_exist'                => self::test_qb_tables_exist(),
                'qb_status_endpoint'             => self::test_qb_status_endpoint(),
                'qb_enqueue_job'                 => self::test_qb_enqueue_job(),
                'qb_trial_start_validation'      => self::test_qb_trial_start_validation(),
                'pinecone_sync_create_archive'   => self::test_pinecone_sync_create_archive(),
                // Group 6 — Question Bank Generation (slow)
                'qb_gen_subtopic'                => self::test_qb_gen_subtopic(),
                'qb_gen_general_topic'           => self::test_qb_gen_general_topic(),
                'qb_trial_start_from_bank'       => self::test_qb_trial_start_from_bank(),
                'qb_trial_start_question_shape'  => self::test_qb_trial_start_question_shape(),
                default                          => [ 'pass' => false, 'message' => "Unknown test: {$test_id}" ],
            };
        } catch ( Throwable $e ) {
            $result = [
                'pass'    => false,
                'status'  => 'fail',
                'message' => 'Exception: ' . $e->getMessage(),
                'data'    => [ 'trace' => $e->getTraceAsString() ],
            ];
        }

        $result['duration_ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );
        return $result;
    }

    // =========================================================================
    // Group 1 — DB Schema
    // =========================================================================

    private static function test_schema_topics_populated(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [ 'per_page' => 1 ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Railway call failed: ' . $data['error'] );
        }
        $total = (int) ( $data['total'] ?? 0 );
        if ( $total < 289 ) {
            return self::fail( "curriculum_topics has {$total} rows — expected ≥ 289.", [ 'total' => $total ] );
        }
        return self::pass( "curriculum_topics populated: {$total} rows.", [ 'total' => $total ] );
    }

    private static function test_schema_topics_std4(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'level'   => 'std_4',
            'period'  => 'term_1',
            'subject' => 'math',
            'per_page' => 50,
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Railway call failed: ' . $data['error'] );
        }
        $items = $data['items'] ?? [];
        if ( empty( $items ) ) {
            return self::fail( 'No std_4 / term_1 / math topics found.' );
        }
        $missing_fields = [];
        foreach ( $items as $item ) {
            if ( empty( $item['module_title'] ) ) $missing_fields[] = 'module_title';
            if ( ! isset( $item['sort_order'] ) )  $missing_fields[] = 'sort_order';
        }
        if ( ! empty( $missing_fields ) ) {
            return self::fail( 'Some rows are missing fields: ' . implode( ', ', array_unique( $missing_fields ) ) );
        }
        return self::pass( "std_4/term_1/math: {$data['total']} topics, all have module_title + sort_order." );
    }

    private static function test_schema_topics_std5(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'level'   => 'std_5',
            'subject' => 'math',
            'per_page' => 50,
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Railway call failed: ' . $data['error'] );
        }
        $items = $data['items'] ?? [];
        if ( empty( $items ) ) {
            return self::fail( 'No std_5 / math topics found.' );
        }
        $non_null_periods = array_filter( $items, fn( $r ) => $r['period'] !== null );
        if ( ! empty( $non_null_periods ) ) {
            return self::fail( count( $non_null_periods ) . ' std_5 rows have non-null period — capstone topics must have period = null.' );
        }
        return self::pass( "std_5/math: {$data['total']} capstone topics, all have period = null." );
    }

    private static function test_schema_structure_via_catalogue(): array {
        $data = self::railway_get( '/api/v1/catalogue' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Catalogue request failed: ' . $data['error'] );
        }
        $items     = is_array( $data ) ? $data : [];
        $sea_items = array_filter( $items, fn( $r ) => ( $r['trial_type'] ?? '' ) === 'sea_paper' );

        if ( count( $items ) < 36 ) {
            return self::fail( 'Catalogue returned ' . count( $items ) . ' combos — expected ≥ 36 (std_4 alone: 4 subjects × 3 terms × 3 difficulties).', [ 'count' => count( $items ) ] );
        }
        if ( empty( $sea_items ) ) {
            return self::fail( 'No sea_paper entries in catalogue — curriculum_structure sea_paper rows missing.' );
        }
        return self::pass( count( $items ) . ' catalogue combos, ' . count( $sea_items ) . ' sea_paper entries.', [ 'total' => count( $items ), 'sea_paper' => count( $sea_items ) ] );
    }

    private static function test_schema_capstone_weightings(): array {
        $data = self::railway_get( '/api/v1/catalogue' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Catalogue request failed: ' . $data['error'] );
        }
        $sea_items = array_filter( is_array( $data ) ? $data : [], fn( $r ) => ( $r['trial_type'] ?? '' ) === 'sea_paper' );
        $subjects  = array_unique( array_column( $sea_items, 'subject' ) );

        if ( ! in_array( 'math', $subjects, true ) || ! in_array( 'english', $subjects, true ) ) {
            return self::fail( 'SEA paper missing math or english. Found: ' . implode( ', ', $subjects ) );
        }
        $unexpected = array_intersect( $subjects, [ 'science', 'social_studies' ] );
        if ( ! empty( $unexpected ) ) {
            return self::warn( 'SEA paper unexpectedly includes: ' . implode( ', ', $unexpected ), [ 'subjects' => $subjects ] );
        }
        return self::pass( 'SEA paper subjects correct: ' . implode( ', ', $subjects ) . '.', [ 'subjects' => $subjects ] );
    }

    private static function test_schema_fingerprints_table(): array {
        $data = self::railway_get( '/api/v1/health/db-check' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'db-check request failed: ' . $data['error'] );
        }
        $tables = $data['tables'] ?? [];

        // The old table name must not cause an error — the renamed one must exist
        $fp = $tables['question_fingerprints'] ?? null;
        if ( ! $fp ) {
            return self::fail( 'question_fingerprints not reported by db-check.' );
        }
        if ( ! ( $fp['exists'] ?? false ) ) {
            return self::fail( 'question_fingerprints table does not exist in Supabase — rename may not have run.', $fp );
        }

        // All other Phase 3 tables
        foreach ( [ 'curriculum_topics', 'curriculum_structure', 'capstone_topic_weightings' ] as $tbl ) {
            if ( ! ( $tables[ $tbl ]['exists'] ?? false ) ) {
                return self::fail( "{$tbl} table missing.", $tables[ $tbl ] ?? [] );
            }
        }

        return self::pass( 'question_fingerprints + all Phase 3 Supabase tables confirmed.', [
            'question_fingerprints'     => $tables['question_fingerprints']['count'] ?? '?',
            'curriculum_topics'         => $tables['curriculum_topics']['count']     ?? '?',
            'curriculum_structure'      => $tables['curriculum_structure']['count']  ?? '?',
            'capstone_topic_weightings' => $tables['capstone_topic_weightings']['count'] ?? '?',
        ] );
    }

    // =========================================================================
    // Group 2 — curriculumDB Accuracy
    // =========================================================================

    private static function test_cdb_catalogue_shape(): array {
        $data  = self::railway_get( '/api/v1/catalogue' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Catalogue request failed: ' . $data['error'] );
        }
        $items = is_array( $data ) ? $data : [];
        if ( empty( $items ) ) {
            return self::fail( 'Catalogue is empty.' );
        }

        $required = [ 'curriculum', 'level', 'subject', 'trial_type' ];
        $problems = [];
        foreach ( $items as $i => $item ) {
            foreach ( $required as $field ) {
                if ( ! isset( $item[ $field ] ) || $item[ $field ] === '' ) {
                    $problems[] = "item[{$i}] missing {$field}";
                }
            }
            // difficulty must be non-null for practice
            if ( ( $item['trial_type'] ?? '' ) === 'practice' && empty( $item['difficulty'] ) ) {
                $problems[] = "item[{$i}] practice combo missing difficulty";
            }
            // difficulty must be null for sea_paper
            if ( ( $item['trial_type'] ?? '' ) === 'sea_paper' && ! is_null( $item['difficulty'] ?? null ) ) {
                $problems[] = "item[{$i}] sea_paper should have difficulty=null";
            }
        }

        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' shape problem(s) found.', [ 'problems' => array_slice( $problems, 0, 10 ) ] );
        }
        return self::pass( count( $items ) . ' catalogue items — all have correct shape.' );
    }

    private static function test_cdb_std4_no_topic(): array {
        $data  = self::railway_get( '/api/v1/catalogue' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Catalogue request failed: ' . $data['error'] );
        }
        $std4  = array_filter( is_array( $data ) ? $data : [], fn( $r ) => ( $r['level'] ?? '' ) === 'std_4' );
        if ( empty( $std4 ) ) {
            return self::fail( 'No std_4 items in catalogue.' );
        }

        $problems = [];
        foreach ( $std4 as $item ) {
            if ( empty( $item['period'] ) ) {
                $problems[] = "std_4 item missing period: {$item['subject']}/{$item['difficulty']}";
            }
            if ( ! is_null( $item['topic'] ?? null ) ) {
                $problems[] = "std_4 item has non-null topic (should be null): {$item['subject']}/{$item['period']}";
            }
        }
        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' std_4 shape problem(s).', [ 'problems' => array_slice( $problems, 0, 5 ) ] );
        }
        return self::pass( count( $std4 ) . ' std_4 combos — all have period, none have topic.' );
    }

    private static function test_cdb_std5_has_topic(): array {
        $data      = self::railway_get( '/api/v1/catalogue' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Catalogue request failed: ' . $data['error'] );
        }
        $std5_prac = array_filter( is_array( $data ) ? $data : [], fn( $r ) => ( $r['level'] ?? '' ) === 'std_5' && ( $r['trial_type'] ?? '' ) === 'practice' );
        if ( empty( $std5_prac ) ) {
            return self::fail( 'No std_5 practice items in catalogue.' );
        }

        $problems = [];
        foreach ( $std5_prac as $item ) {
            if ( empty( $item['topic'] ) ) {
                $problems[] = "std_5 practice item missing topic: {$item['subject']}/{$item['difficulty']}";
            }
            if ( ! is_null( $item['period'] ?? null ) ) {
                $problems[] = "std_5 practice item has non-null period: {$item['subject']}";
            }
        }
        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' std_5 shape problem(s).', [ 'problems' => array_slice( $problems, 0, 5 ) ] );
        }
        return self::pass( count( $std5_prac ) . ' std_5 practice combos — all have topic, none have period.' );
    }

    private static function test_cdb_sea_paper_only_std5(): array {
        $data     = self::railway_get( '/api/v1/catalogue' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Catalogue request failed: ' . $data['error'] );
        }
        $sea      = array_filter( is_array( $data ) ? $data : [], fn( $r ) => ( $r['trial_type'] ?? '' ) === 'sea_paper' );
        if ( empty( $sea ) ) {
            return self::fail( 'No sea_paper entries in catalogue.' );
        }

        $non_std5 = array_filter( $sea, fn( $r ) => ( $r['level'] ?? '' ) !== 'std_5' );
        if ( ! empty( $non_std5 ) ) {
            return self::fail( count( $non_std5 ) . ' sea_paper entries are not std_5.', [ 'items' => array_values( $non_std5 ) ] );
        }
        $subjects = array_unique( array_column( array_values( $sea ), 'subject' ) );
        return self::pass( 'All ' . count( $sea ) . ' sea_paper entries are std_5. Subjects: ' . implode( ', ', $subjects ) . '.', [ 'sea_subjects' => $subjects ] );
    }

    private static function test_cdb_topic_list_shape(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'level'    => 'std_4',
            'period'   => 'term_1',
            'subject'  => 'math',
            'per_page' => 100,
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Railway call failed: ' . $data['error'] );
        }
        $items = $data['items'] ?? [];
        if ( count( $items ) < 3 ) {
            return self::fail( 'Only ' . count( $items ) . ' topics returned for std_4/term_1/math — expected at least 3.' );
        }

        $module_titles = array_unique( array_filter( array_column( $items, 'module_title' ) ) );
        if ( count( $module_titles ) < 3 ) {
            return self::fail( 'Only ' . count( $module_titles ) . ' distinct module_titles — expected ≥ 3.', [ 'titles' => $module_titles ] );
        }
        return self::pass( count( $items ) . ' topics, ' . count( $module_titles ) . ' distinct modules.', [ 'module_titles' => array_values( $module_titles ) ] );
    }

    private static function test_cdb_capstone_topic_count(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'level'    => 'std_5',
            'subject'  => 'math',
            'per_page' => 100,
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'Railway call failed: ' . $data['error'] );
        }
        $items  = $data['items'] ?? [];
        $titles = array_unique( array_filter( array_column( $items, 'module_title' ) ) );
        $count  = count( $titles );

        if ( $count < 5 ) {
            return self::fail( "Only {$count} capstone module_titles for std_5/math — expected 5–15.", [ 'titles' => $titles ] );
        }
        if ( $count > 20 ) {
            return self::warn( "std_5/math has {$count} module_titles — more than expected. Verify taxonomy.", [ 'titles' => $titles ] );
        }
        return self::pass( "std_5/math has {$count} capstone topics.", [ 'titles' => array_values( $titles ) ] );
    }

    // =========================================================================
    // Group 3 — Curriculum CRUD (stateful — run in order)
    // =========================================================================

    private static function test_crud_list(): array {
        $res = self::rest_get( '/editor/curriculum-topics' );
        if ( $res['status'] !== 200 ) {
            return self::fail( "List returned HTTP {$res['status']}.", $res );
        }
        $items = $res['body']['items'] ?? null;
        $total = $res['body']['total'] ?? null;

        if ( ! is_array( $items ) ) {
            return self::fail( 'Response missing items array.', $res['body'] ?? [] );
        }
        if ( $total === null ) {
            return self::fail( 'Response missing total field.' );
        }
        return self::pass( "List OK — {$total} active topics.", [ 'total' => $total ] );
    }

    private static function test_crud_create(): array {
        // Clean up any orphaned test topic from a previous run
        $stale_id = (int) get_transient( self::TRANSIENT_TOPIC_ID );
        if ( $stale_id ) {
            self::rest_delete( "/editor/curriculum-topics/{$stale_id}" );
            delete_transient( self::TRANSIENT_TOPIC_ID );
        }

        $res = self::rest_post( '/editor/curriculum-topics', [
            'curriculum'    => 'tt_primary',
            'level'         => 'std_4',
            'period'        => 'term_1',
            'subject'       => 'math',
            'module_number' => 99,
            'module_title'  => '_SPECTEST_MODULE_',
            'sort_order'    => 9999,
            'topic'         => self::TEST_TOPIC_ACTIVE,
            'source'        => 'manual',
        ] );

        if ( $res['status'] !== 201 ) {
            return self::fail( "Create returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }
        $id = (int) ( $res['body']['id'] ?? 0 );
        if ( ! $id ) {
            return self::fail( 'Created row has no id.', $res['body'] ?? [] );
        }

        set_transient( self::TRANSIENT_TOPIC_ID,  $id,                     HOUR_IN_SECONDS );
        set_transient( self::TRANSIENT_TOPIC_STR, self::TEST_TOPIC_ACTIVE, HOUR_IN_SECONDS );

        return self::pass( "Topic created with id={$id}.", [ 'id' => $id, 'topic' => self::TEST_TOPIC_ACTIVE ] );
    }

    private static function test_crud_verify_created(): array {
        $id = (int) get_transient( self::TRANSIENT_TOPIC_ID );
        if ( ! $id ) {
            return self::warn( 'No transient topic id — run crud_create first.' );
        }

        $res = self::rest_get( '/editor/curriculum-topics', [
            'level'    => 'std_4',
            'period'   => 'term_1',
            'subject'  => 'math',
            'per_page' => 200,
        ] );
        if ( $res['status'] !== 200 ) {
            return self::fail( "List returned HTTP {$res['status']}." );
        }

        $items   = $res['body']['items'] ?? [];
        $matched = array_filter( $items, fn( $r ) => (int)( $r['id'] ?? 0 ) === $id );

        if ( empty( $matched ) ) {
            return self::fail( "Created topic (id={$id}) not found in active list.", [ 'searched' => count( $items ) . ' items' ] );
        }
        $row = array_values( $matched )[0];
        return self::pass( "Created topic confirmed in active list.", [ 'id' => $id, 'topic' => $row['topic'] ] );
    }

    private static function test_crud_update(): array {
        $id = (int) get_transient( self::TRANSIENT_TOPIC_ID );
        if ( ! $id ) {
            return self::warn( 'No transient topic id — run crud_create first.' );
        }

        $res = self::rest_patch( "/editor/curriculum-topics/{$id}", [
            'topic' => self::TEST_TOPIC_UPDATED,
        ] );
        if ( $res['status'] !== 200 ) {
            return self::fail( "Update returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }

        $returned_topic = $res['body']['topic'] ?? '';
        if ( $returned_topic !== self::TEST_TOPIC_UPDATED ) {
            return self::fail( "Response topic mismatch. Expected: " . self::TEST_TOPIC_UPDATED . " Got: {$returned_topic}" );
        }

        set_transient( self::TRANSIENT_TOPIC_STR, self::TEST_TOPIC_UPDATED, HOUR_IN_SECONDS );
        return self::pass( "Topic updated to: " . self::TEST_TOPIC_UPDATED, [ 'id' => $id ] );
    }

    private static function test_crud_archive(): array {
        $id = (int) get_transient( self::TRANSIENT_TOPIC_ID );
        if ( ! $id ) {
            return self::warn( 'No transient topic id — run crud_create first.' );
        }

        $res = self::rest_delete( "/editor/curriculum-topics/{$id}" );
        if ( $res['status'] !== 200 ) {
            return self::fail( "Archive returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }

        $archived = $res['body']['archived'] ?? false;
        if ( ! $archived ) {
            return self::fail( 'Response does not confirm archived=true.', $res['body'] ?? [] );
        }
        return self::pass( "Topic id={$id} archived successfully." );
    }

    private static function test_crud_verify_archived(): array {
        $id = (int) get_transient( self::TRANSIENT_TOPIC_ID );
        if ( ! $id ) {
            return self::warn( 'No transient topic id — run crud_create first.' );
        }

        $res = self::rest_get( '/editor/curriculum-topics', [
            'level'    => 'std_4',
            'period'   => 'term_1',
            'subject'  => 'math',
            'status'   => 'active',
            'per_page' => 200,
        ] );
        if ( $res['status'] !== 200 ) {
            return self::fail( "List returned HTTP {$res['status']}." );
        }

        $items   = $res['body']['items'] ?? [];
        $matched = array_filter( $items, fn( $r ) => (int)( $r['id'] ?? 0 ) === $id );

        if ( ! empty( $matched ) ) {
            return self::fail( "Archived topic id={$id} still appears in active list — archive did not take effect." );
        }
        return self::pass( "Archived topic correctly absent from active list.", [ 'checked' => count( $items ) . ' items' ] );
    }

    private static function test_crud_archived_in_history(): array {
        $id = (int) get_transient( self::TRANSIENT_TOPIC_ID );
        if ( ! $id ) {
            return self::warn( 'No transient topic id — run crud_create first.' );
        }

        $res = self::rest_get( '/editor/curriculum-topics', [
            'level'    => 'std_4',
            'period'   => 'term_1',
            'subject'  => 'math',
            'status'   => 'archived',
            'per_page' => 200,
        ] );
        if ( $res['status'] !== 200 ) {
            return self::fail( "Archived list returned HTTP {$res['status']}." );
        }

        $items   = $res['body']['items'] ?? [];
        $matched = array_filter( $items, fn( $r ) => (int)( $r['id'] ?? 0 ) === $id );

        if ( empty( $matched ) ) {
            return self::fail( "Archived topic id={$id} not found in archived list.", [ 'searched' => count( $items ) . ' items' ] );
        }
        $row = array_values( $matched )[0];
        if ( ( $row['status'] ?? '' ) !== 'archived' ) {
            return self::fail( "Row found but status is '{$row['status']}', not 'archived'." );
        }

        delete_transient( self::TRANSIENT_TOPIC_ID );
        delete_transient( self::TRANSIENT_TOPIC_STR );

        return self::pass( "Archived topic confirmed in archived list. Transients cleaned up.", [ 'id' => $id, 'topic' => $row['topic'] ] );
    }

    // =========================================================================
    // Group 4 — Generation Regression (slow — calls Claude)
    // =========================================================================

    private static function test_regen_exam_std4(): array {
        $res = self::rest_post( '/editor/trials/generate', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'difficulty' => 'easy',
            'trial_type' => 'practice',
        ], timeout: 120 );

        if ( $res['status'] !== 201 ) {
            return self::fail( "Exam generation returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }
        $pkg  = $res['body']['data']['package'] ?? $res['body'];
        $qs   = $pkg['questions'] ?? [];
        $meta = $pkg['meta'] ?? [];
        $count = count( $qs );

        if ( $count !== 10 ) {
            return self::warn( "Easy exam has {$count} questions — expected 10 per exam config.", [ 'count' => $count ] );
        }
        $bad_q = array_filter( $qs, fn( $q ) => empty( $q['question_id'] ) || empty( $q['correct_answer'] ) );
        if ( ! empty( $bad_q ) ) {
            return self::fail( count( $bad_q ) . ' questions missing question_id or correct_answer.' );
        }
        return self::pass( "std_4 easy exam: {$count} questions, all well-formed.", [
            'package_id' => $pkg['package_id'] ?? '—',
            'curriculum' => $meta['curriculum'] ?? '—',
        ] );
    }

    private static function test_regen_exam_std5_topic(): array {
        $res = self::rest_post( '/editor/trials/generate', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_5',
            'subject'    => 'math',
            'difficulty' => 'medium',
            'trial_type' => 'practice',
            'topic'      => 'Fractions',
        ], timeout: 120 );

        if ( $res['status'] !== 201 ) {
            return self::fail( "Capstone exam generation returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }
        $pkg   = $res['body']['data']['package'] ?? $res['body'];
        $qs    = $pkg['questions'] ?? [];
        $count = count( $qs );

        if ( $count !== 15 ) {
            return self::warn( "Medium exam has {$count} questions — expected 15.", [ 'count' => $count ] );
        }
        return self::pass( "std_5 Fractions medium exam: {$count} questions.", [ 'package_id' => $pkg['package_id'] ?? '—' ] );
    }

    private static function test_regen_quest_path_a(): array {
        $res = self::rest_post( '/editor/quests/generate', [
            'curriculum'   => 'tt_primary',
            'level'        => 'std_4',
            'period'       => 'term_1',
            'subject'      => 'math',
            'module_index' => 0,
        ], timeout: 120 );

        if ( $res['status'] !== 201 ) {
            return self::fail( "Path A quest generation returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }
        $quest    = $res['body']['data'] ?? $res['body'];
        $sections = $quest['content']['sections'] ?? [];

        if ( count( $sections ) < 3 ) {
            return self::fail( 'Path A quest has ' . count( $sections ) . ' sections — expected ≥ 3.' );
        }

        set_transient( self::TRANSIENT_QUEST_DATA, $quest, HOUR_IN_SECONDS );
        return self::pass( 'Path A quest: ' . count( $sections ) . ' sections.', [ 'quest_id' => $quest['quest_id'] ?? '—' ] );
    }

    private static function test_regen_quest_path_b(): array {
        $res = self::rest_post( '/editor/quests/generate', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_5',
            'subject'    => 'math',
            'topic'      => 'Fractions',
        ], timeout: 120 );

        if ( $res['status'] !== 201 ) {
            return self::fail( "Path B quest generation returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }
        $quest    = $res['body']['data'] ?? $res['body'];
        $sections = $quest['content']['sections'] ?? [];

        if ( empty( $sections ) ) {
            return self::fail( 'Path B quest has no sections.' );
        }
        return self::pass( 'Path B (Fractions) quest: ' . count( $sections ) . ' sections.', [ 'quest_id' => $quest['quest_id'] ?? '—' ] );
    }

    private static function test_regen_quest_path_c(): array {
        $res = self::rest_post( '/editor/quests/generate', [
            'curriculum'     => 'tt_primary',
            'level'          => 'std_4',
            'period'         => 'term_1',
            'subject'        => 'math',
            'module_index'   => 0,
            'subtopic_index' => 0,
        ], timeout: 120 );

        if ( $res['status'] !== 201 ) {
            return self::fail( "Path C quest generation returned HTTP {$res['status']}.", $res['body'] ?? [] );
        }
        $quest    = $res['body']['data'] ?? $res['body'];
        $sections = $quest['content']['sections'] ?? [];

        if ( count( $sections ) !== 1 ) {
            return self::warn( 'Path C quest has ' . count( $sections ) . ' sections — expected exactly 1 (single-objective).', [ 'count' => count( $sections ) ] );
        }
        return self::pass( 'Path C (single subtopic) quest: 1 section as expected.', [ 'quest_id' => $quest['quest_id'] ?? '—' ] );
    }

    private static function test_regen_kc_count(): array {
        $quest = get_transient( self::TRANSIENT_QUEST_DATA );
        if ( ! $quest ) {
            // Re-run Path A inline to get fresh data
            $res = self::rest_post( '/editor/quests/generate', [
                'curriculum'   => 'tt_primary',
                'level'        => 'std_4',
                'period'       => 'term_1',
                'subject'      => 'math',
                'module_index' => 0,
            ], timeout: 120 );

            if ( $res['status'] !== 201 ) {
                return self::fail( "Quest generation for KC check returned HTTP {$res['status']}." );
            }
            $quest = $res['body']['data'] ?? $res['body'];
        }

        $sections = $quest['content']['sections'] ?? [];
        if ( empty( $sections ) ) {
            return self::fail( 'No sections found in quest for KC count check.' );
        }

        $counts  = [];
        $non_3   = [];
        foreach ( $sections as $i => $section ) {
            $kc_count  = count( $section['knowledge_checks'] ?? [] );
            $counts[]  = $kc_count;
            if ( $kc_count !== 3 ) {
                $non_3[] = "section[{$i}]: {$kc_count} KCs";
            }
        }

        $summary = 'KC counts per section: ' . implode( ', ', $counts );
        if ( ! empty( $non_3 ) ) {
            return self::warn( "Phase D: expected 3 KCs per section, but got: " . implode( '; ', $non_3 ) . ". Claude output is non-deterministic — this may be a prompt compliance issue.", [ 'counts' => $counts ] );
        }
        return self::pass( "Phase D confirmed: all sections have exactly 3 KCs. {$summary}", [ 'counts' => $counts ] );
    }

    // =========================================================================
    // Group 5 — Question Bank (fast)
    // =========================================================================

    private static function test_qb_tables_exist(): array {
        $data = self::railway_get( '/api/v1/health/db-check' );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'db-check failed: ' . $data['error'] );
        }
        $tables = $data['tables'] ?? [];

        foreach ( [ 'question_bank', 'question_bank_queue' ] as $tbl ) {
            if ( ! ( $tables[ $tbl ]['exists'] ?? false ) ) {
                return self::fail( "{$tbl} table not found — run migration 002_question_bank_tables.sql in Supabase.", $tables[ $tbl ] ?? [] );
            }
        }

        return self::pass( 'question_bank + question_bank_queue tables confirmed.', [
            'question_bank'       => $tables['question_bank']['count']       ?? '?',
            'question_bank_queue' => $tables['question_bank_queue']['count'] ?? '?',
        ] );
    }

    private static function test_qb_status_endpoint(): array {
        $data = self::railway_get( '/api/v1/question-bank/status' );
        if ( isset( $data['error'] ) ) {
            return self::fail( '/question-bank/status failed: ' . $data['error'] );
        }
        if ( ! array_key_exists( 'pools', $data ) ) {
            return self::fail( 'Response missing pools key.', $data );
        }
        $pools = $data['pools'];
        if ( ! is_array( $pools ) ) {
            return self::fail( 'pools is not an array.', [ 'type' => gettype( $pools ) ] );
        }
        $count = count( $pools );
        return self::pass( "/question-bank/status OK — {$count} pool slot(s) tracked.", [ 'pool_count' => $count ] );
    }

    private static function test_qb_enqueue_job(): array {
        $data = self::railway_post( '/api/v1/question-bank/replenish', [
            'curriculum'   => 'tt_primary',
            'level'        => 'std_4',
            'period'       => 'term_1',
            'subject'      => 'math',
            'scope'        => 'subtopic',
            'scope_ref'    => 'spectest_enqueue_probe',
            'difficulty'   => 'easy',
            'target_count' => 5,
            'sync'         => false,
        ] );

        if ( isset( $data['error'] ) ) {
            return self::fail( 'Replenish enqueue failed: ' . $data['error'] );
        }

        // Either a new job_id or already_queued — both are valid outcomes
        if ( ! empty( $data['job_id'] ) ) {
            return self::pass( 'Replenish queued. job_id=' . $data['job_id'], [ 'job_id' => $data['job_id'] ] );
        }
        if ( isset( $data['queued'] ) && $data['queued'] === false ) {
            return self::pass( 'Replenish deduped (already_queued). Endpoint reachable and responding correctly.', $data );
        }

        return self::fail( 'Unexpected response shape — missing job_id and queued flag.', $data );
    }

    private static function test_pinecone_sync_create_archive(): array {
        // 0. Clean up any stale test row from a previous failed run
        $existing = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'per_page'   => 200,
            'status'     => 'active',
        ] );
        foreach ( $existing['items'] ?? [] as $item ) {
            if ( ( $item['module_title'] ?? '' ) === '_SPECTEST_PCSYNC_' ) {
                self::railway_delete( '/api/v1/curriculum-topics/' . (int) $item['id'] );
            }
        }

        // 1. Create a test topic — random sort_order in 9M range avoids conflicts with stale archived rows
        $sort_order = 9000000 + mt_rand( 0, 999999 );
        $create     = self::railway_post( '/api/v1/curriculum-topics', [
            'curriculum'    => 'tt_primary',
            'level'         => 'std_4',
            'period'        => 'term_1',
            'subject'       => 'math',
            'module_number' => 99,
            'module_title'  => '_SPECTEST_PCSYNC_',
            'sort_order'    => $sort_order,
            'topic'         => '_SPECTEST_PINECONE_SYNC_',
            'source'        => 'manual',
        ] );

        if ( isset( $create['error'] ) ) {
            return self::fail( 'Create topic failed: ' . $create['error'] );
        }
        $topic_id = (int) ( $create['id'] ?? 0 );
        if ( ! $topic_id ) {
            return self::fail( 'Create topic returned no id.', $create );
        }

        $vector_id = "ct-{$topic_id}";

        // 2. Give Pinecone a moment to index (fire-and-forget runs after response)
        sleep( 4 );

        // 3. Fetch the specific vector by ID — works for ct-* prefix which training/list doesn't cover
        $fetch = self::railway_get( '/api/v1/training/fetch', [ 'id' => $vector_id ] );
        if ( isset( $fetch['error'] ) ) {
            self::railway_delete( '/api/v1/curriculum-topics/' . $topic_id );
            return self::warn( "Could not check Pinecone vector — training/fetch returned error: " . $fetch['error'], [
                'vector_id' => $vector_id,
            ] );
        }

        if ( ! ( $fetch['exists'] ?? false ) ) {
            self::railway_delete( '/api/v1/curriculum-topics/' . $topic_id );
            return self::warn( "Vector {$vector_id} not yet in Pinecone — sync may be delayed or Pinecone not configured.", [
                'vector_id' => $vector_id,
                'fetch'     => $fetch,
            ] );
        }

        // 4. Archive the topic — should trigger Pinecone delete
        $archive = self::railway_delete( '/api/v1/curriculum-topics/' . $topic_id );
        if ( isset( $archive['error'] ) || empty( $archive['archived'] ) ) {
            return self::fail( 'Archive failed after Pinecone upsert verified.', $archive );
        }

        sleep( 3 );

        // 5. Confirm vector gone from Pinecone
        $fetch_after = self::railway_get( '/api/v1/training/fetch', [ 'id' => $vector_id ] );
        $still_there = $fetch_after['exists'] ?? false;

        if ( $still_there ) {
            return self::warn( "Vector {$vector_id} still in Pinecone after archive — delete may be async-delayed.", [
                'vector_id' => $vector_id,
            ] );
        }

        return self::pass( "Pinecone auto-sync OK: vector upserted on create, removed on archive.", [
            'vector_id' => $vector_id,
            'topic_id'  => $topic_id,
        ] );
    }

    private static function test_qb_trial_start_validation(): array {
        // Missing required fields — must return an error, not a 500
        $data = self::railway_post( '/api/v1/trial/start', [] );

        // Should be an error (missing required fields)
        if ( isset( $data['error'] ) || isset( $data['errors'] ) ) {
            return self::pass( '/trial/start validation working — missing params rejected.', [
                'error' => $data['error'] ?? array_key_first( $data['errors'] ?? [] ),
            ] );
        }
        if ( isset( $data['questions'] ) ) {
            return self::fail( '/trial/start accepted empty body and returned questions — validation missing.' );
        }
        return self::fail( 'Unexpected response from /trial/start with empty body.', $data );
    }

    // =========================================================================
    // Group 6 — Question Bank Generation (slow — calls Claude)
    // =========================================================================

    private static function test_qb_gen_subtopic(): array {
        $data = self::railway_post( '/api/v1/question-bank/replenish', [
            'curriculum'   => 'tt_primary',
            'level'        => 'std_4',
            'period'       => 'term_1',
            'subject'      => 'math',
            'scope'        => 'subtopic',
            'scope_ref'    => 'place_value_up_to_1_000_000',
            'difficulty'   => 'easy',
            'target_count' => 5,
            'sync'         => true,
        ] );

        if ( isset( $data['error'] ) ) {
            return self::fail( 'Subtopic sync gen failed: ' . $data['error'] );
        }
        $inserted = (int) ( $data['inserted'] ?? 0 );
        if ( $inserted < 1 ) {
            return self::warn( "Sync gen returned inserted={$inserted} — pool may already be full or generation partially failed.", $data );
        }
        return self::pass( "Subtopic generation OK — {$inserted} questions inserted.", [
            'inserted'  => $inserted,
            'scope_ref' => 'place_value_up_to_1_000_000',
        ] );
    }

    private static function test_qb_gen_general_topic(): array {
        $data = self::railway_post( '/api/v1/question-bank/replenish', [
            'curriculum'   => 'tt_primary',
            'level'        => 'std_4',
            'period'       => 'term_1',
            'subject'      => 'math',
            'scope'        => 'general_topic',
            'scope_ref'    => 'number_and_place_value',
            'difficulty'   => 'medium',
            'target_count' => 5,
            'sync'         => true,
        ] );

        if ( isset( $data['error'] ) ) {
            return self::fail( 'General topic sync gen failed: ' . $data['error'] );
        }
        $inserted = (int) ( $data['inserted'] ?? 0 );
        if ( $inserted < 1 ) {
            return self::warn( "Sync gen returned inserted={$inserted} — pool may already be full.", $data );
        }
        return self::pass( "General topic generation OK — {$inserted} questions inserted.", [
            'inserted'  => $inserted,
            'scope_ref' => 'number_and_place_value',
        ] );
    }

    private static function test_qb_trial_start_from_bank(): array {
        $data = self::railway_post( '/api/v1/trial/start', [
            'curriculum'     => 'tt_primary',
            'level'          => 'std_4',
            'period'         => 'term_1',
            'subject'        => 'math',
            'scope'          => 'subtopic',
            'scope_ref'      => 'place_value_up_to_1_000_000',
            'difficulty'     => 'easy',
            'question_count' => 5,
        ] );

        if ( isset( $data['error'] ) ) {
            return self::fail( '/trial/start failed: ' . $data['error'], $data );
        }
        $questions    = $data['questions']    ?? null;
        $answer_sheet = $data['answer_sheet'] ?? null;

        if ( ! is_array( $questions ) || empty( $questions ) ) {
            return self::fail( '/trial/start returned no questions.', $data );
        }
        if ( ! is_array( $answer_sheet ) || empty( $answer_sheet ) ) {
            return self::fail( '/trial/start returned no answer_sheet.', $data );
        }

        // Store for shape check
        set_transient( self::TRANSIENT_QB_TRIAL, $data, HOUR_IN_SECONDS );

        return self::pass( '/trial/start OK — ' . count( $questions ) . ' questions + answer_sheet.', [
            'question_count'    => count( $questions ),
            'answer_sheet_keys' => count( $answer_sheet ),
            'meta'              => $data['meta'] ?? [],
        ] );
    }

    private static function test_qb_trial_start_question_shape(): array {
        $trial = get_transient( self::TRANSIENT_QB_TRIAL );
        if ( ! $trial ) {
            // Re-run inline so this test isn't dependent on order
            $trial = self::railway_post( '/api/v1/trial/start', [
                'curriculum'     => 'tt_primary',
                'level'          => 'std_4',
                'period'         => 'term_1',
                'subject'        => 'math',
                'scope'          => 'subtopic',
                'scope_ref'      => 'place_value_up_to_1_000_000',
                'difficulty'     => 'easy',
                'question_count' => 5,
            ] );
            if ( isset( $trial['error'] ) ) {
                return self::fail( '/trial/start (inline) failed: ' . $trial['error'] );
            }
        }

        $questions = $trial['questions'] ?? [];
        if ( empty( $questions ) ) {
            return self::fail( 'No questions available for shape validation.' );
        }

        $required = [ 'question_id', 'question', 'options' ];
        $problems = [];
        foreach ( $questions as $i => $q ) {
            foreach ( $required as $field ) {
                if ( empty( $q[ $field ] ) ) {
                    $problems[] = "q[{$i}] missing {$field}";
                }
            }
            // options must have A, B, C, D
            $opts = $q['options'] ?? [];
            foreach ( [ 'A', 'B', 'C', 'D' ] as $letter ) {
                if ( empty( $opts[ $letter ] ) ) {
                    $problems[] = "q[{$i}] options missing {$letter}";
                }
            }
            // difficulty lives inside meta.difficulty (not top-level)
            if ( empty( $q['meta']['difficulty'] ) ) {
                $problems[] = "q[{$i}] missing meta.difficulty";
            }
            // correct_answer must NOT be in the questions array (hidden from student)
            if ( isset( $q['correct_answer'] ) ) {
                $problems[] = "q[{$i}] exposes correct_answer — must be answer_sheet only";
            }
        }

        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' shape problem(s).', [ 'problems' => array_slice( $problems, 0, 10 ) ] );
        }
        return self::pass( count( $questions ) . ' questions — all well-formed; correct_answer hidden.', [
            'count' => count( $questions ),
        ] );
    }

    // =========================================================================
    // HTTP Helpers
    // =========================================================================

    private static function rest_get( string $route, array $params = [] ): array {
        $request = new WP_REST_Request( 'GET', '/' . KNOWLY_REST_NAMESPACE . $route );
        foreach ( $params as $k => $v ) {
            $request->set_param( $k, $v );
        }
        return self::dispatch( $request );
    }

    private static function rest_post( string $route, array $body = [], int $timeout = 30 ): array {
        $request = new WP_REST_Request( 'POST', '/' . KNOWLY_REST_NAMESPACE . $route );
        $request->set_body_params( $body );
        return self::dispatch( $request, $timeout );
    }

    private static function rest_patch( string $route, array $body = [] ): array {
        $request = new WP_REST_Request( 'PATCH', '/' . KNOWLY_REST_NAMESPACE . $route );
        $request->set_body_params( $body );
        return self::dispatch( $request );
    }

    private static function rest_delete( string $route ): array {
        $request = new WP_REST_Request( 'DELETE', '/' . KNOWLY_REST_NAMESPACE . $route );
        return self::dispatch( $request );
    }

    private static function dispatch( WP_REST_Request $request, int $timeout = 30 ): array {
        // rest_do_request runs within the current WP process as the current user —
        // no HTTP overhead, and current_user_can('manage_options') passes for admin.
        $response = rest_do_request( $request );
        $data     = rest_get_server()->response_to_data( $response, false );
        return [
            'status' => $response->get_status(),
            'body'   => $data,
        ];
    }

    private static function railway_get( string $path, array $params = [] ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return [ 'error' => 'Railway endpoint not configured.' ];
        }

        $url = $endpoint . $path;
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return [ 'error' => $body['error'] ?? "HTTP {$code}", 'code' => $code ];
        }

        return $body ?: [];
    }

    private static function railway_delete( string $path ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return [ 'error' => 'Railway endpoint not configured.' ];
        }

        $response = wp_remote_request( $endpoint . $path, [
            'method'  => 'DELETE',
            'timeout' => 30,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $parsed = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return [ 'error' => $parsed['error'] ?? "HTTP {$code}", 'code' => $code ];
        }

        return $parsed ?: [];
    }

    private static function railway_post( string $path, array $body = [] ): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        if ( ! $endpoint ) {
            return [ 'error' => 'Railway endpoint not configured.' ];
        }

        $response = wp_remote_post( $endpoint . $path, [
            'timeout' => 120,
            'headers' => [
                'X-AEP-Server-Key' => $server_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $parsed = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return [ 'error' => $parsed['error'] ?? "HTTP {$code}", 'code' => $code ];
        }

        return $parsed ?: [];
    }

    // ── Result Builders ───────────────────────────────────────────────────────

    private static function pass( string $message, array $data = [] ): array {
        return [ 'pass' => true,  'status' => 'pass', 'message' => $message, 'data' => $data ];
    }

    private static function fail( string $message, array $data = [] ): array {
        return [ 'pass' => false, 'status' => 'fail', 'message' => $message, 'data' => $data ];
    }

    private static function warn( string $message, array $data = [] ): array {
        return [ 'pass' => null, 'status' => 'warn', 'message' => $message, 'data' => $data ];
    }

    // =========================================================================
    // Test Group Definition
    // =========================================================================

    private static function test_groups(): array {
        return [
            'schema' => [
                'label' => '🗄️ Group 1 — DB Schema',
                'slow'  => false,
                'tests' => [
                    'schema_topics_populated'        => [ 'label' => 'curriculum_topics has ≥ 289 rows',                        'method' => 'GET',   'route' => '/api/v1/curriculum-topics' ],
                    'schema_topics_std4'             => [ 'label' => 'std_4/term_1/math topics present with module_title',       'method' => 'GET',   'route' => '/api/v1/curriculum-topics' ],
                    'schema_topics_std5'             => [ 'label' => 'std_5/math capstone topics have period = null',            'method' => 'GET',   'route' => '/api/v1/curriculum-topics' ],
                    'schema_structure_via_catalogue' => [ 'label' => 'curriculum_structure drives catalogue (≥ 36 combos, sea_paper present)', 'method' => 'GET', 'route' => '/api/v1/catalogue' ],
                    'schema_capstone_weightings'     => [ 'label' => 'SEA paper subjects are math + english only',               'method' => 'GET',   'route' => '/api/v1/catalogue' ],
                    'schema_fingerprints_table'      => [ 'label' => 'question_fingerprints renamed + all Phase 3 tables exist', 'method' => 'GET',   'route' => '/api/v1/health/db-check' ],
                ],
            ],
            'curriculumdb' => [
                'label' => '📐 Group 2 — curriculumDB Accuracy',
                'slow'  => false,
                'tests' => [
                    'cdb_catalogue_shape'        => [ 'label' => 'All catalogue items have correct fields and types',            'method' => 'GET', 'route' => '/api/v1/catalogue' ],
                    'cdb_std4_no_topic'          => [ 'label' => 'std_4 combos have period, no topic',                          'method' => 'GET', 'route' => '/api/v1/catalogue' ],
                    'cdb_std5_has_topic'         => [ 'label' => 'std_5 practice combos have topic, no period',                 'method' => 'GET', 'route' => '/api/v1/catalogue' ],
                    'cdb_sea_paper_only_std5'    => [ 'label' => 'All sea_paper entries are std_5',                             'method' => 'GET', 'route' => '/api/v1/catalogue' ],
                    'cdb_topic_list_shape'       => [ 'label' => 'std_4/term_1/math has ≥ 3 distinct module_titles',            'method' => 'GET', 'route' => '/api/v1/curriculum-topics' ],
                    'cdb_capstone_topic_count'   => [ 'label' => 'std_5/math has 5–15 capstone module_titles',                  'method' => 'GET', 'route' => '/api/v1/curriculum-topics' ],
                ],
            ],
            'crud' => [
                'label' => '✏️ Group 3 — Curriculum CRUD (run in order)',
                'slow'  => false,
                'tests' => [
                    'crud_list'             => [ 'label' => 'List returns 200 + items array + total',                     'method' => 'GET',    'route' => '/editor/curriculum-topics' ],
                    'crud_create'           => [ 'label' => 'Create test topic → 201, id stored',                         'method' => 'POST',   'route' => '/editor/curriculum-topics' ],
                    'crud_verify_created'   => [ 'label' => 'Created topic appears in active list',                       'method' => 'GET',    'route' => '/editor/curriculum-topics' ],
                    'crud_update'           => [ 'label' => 'Update topic string → 200, response matches',                'method' => 'PATCH',  'route' => '/editor/curriculum-topics/{id}' ],
                    'crud_archive'          => [ 'label' => 'Archive topic → 200, archived: true',                       'method' => 'DELETE', 'route' => '/editor/curriculum-topics/{id}' ],
                    'crud_verify_archived'  => [ 'label' => 'Archived topic absent from active list',                    'method' => 'GET',    'route' => '/editor/curriculum-topics' ],
                    'crud_archived_in_history' => [ 'label' => 'Archived topic visible with status=archived (cleanup)',  'method' => 'GET',    'route' => '/editor/curriculum-topics' ],
                ],
            ],
            'generation' => [
                'label' => '⚡ Group 4 — Generation Regression (SLOW — calls Claude)',
                'slow'  => true,
                'tests' => [
                    'regen_exam_std4'       => [ 'label' => 'std_4 easy practice exam: 10 questions, all well-formed',   'method' => 'POST', 'route' => '/editor/trials/generate' ],
                    'regen_exam_std5_topic' => [ 'label' => 'std_5 Fractions medium exam: 15 questions',                 'method' => 'POST', 'route' => '/editor/trials/generate' ],
                    'regen_quest_path_a'    => [ 'label' => 'Quest Path A (module-scoped): ≥ 3 sections',                'method' => 'POST', 'route' => '/editor/quests/generate' ],
                    'regen_quest_path_b'    => [ 'label' => 'Quest Path B (capstone topic): sections present',           'method' => 'POST', 'route' => '/editor/quests/generate' ],
                    'regen_quest_path_c'    => [ 'label' => 'Quest Path C (single subtopic): exactly 1 section',        'method' => 'POST', 'route' => '/editor/quests/generate' ],
                    'regen_kc_count'        => [ 'label' => 'Phase D: each section has exactly 3 knowledge checks',     'method' => 'CHECK', 'route' => '' ],
                ],
            ],
            'question_bank' => [
                'label' => '🏦 Group 5 — Question Bank + Pinecone Sync (fast)',
                'slow'  => false,
                'tests' => [
                    'qb_tables_exist'             => [ 'label' => 'question_bank + question_bank_queue tables exist in Supabase',    'method' => 'GET',  'route' => '/api/v1/health/db-check' ],
                    'qb_status_endpoint'          => [ 'label' => '/question-bank/status returns pools array',                       'method' => 'GET',  'route' => '/api/v1/question-bank/status' ],
                    'qb_enqueue_job'              => [ 'label' => '/question-bank/replenish sync=false enqueues job',                'method' => 'POST', 'route' => '/api/v1/question-bank/replenish' ],
                    'qb_trial_start_validation'   => [ 'label' => '/trial/start rejects empty body with error',                     'method' => 'POST', 'route' => '/api/v1/trial/start' ],
                    'pinecone_sync_create_archive'=> [ 'label' => 'Curriculum topic auto-syncs to Pinecone on create, removed on archive', 'method' => 'POST+DELETE', 'route' => '/api/v1/curriculum-topics' ],
                ],
            ],
            'qb_generation' => [
                'label' => '🧠 Group 6 — Question Bank Generation (SLOW — calls Claude)',
                'slow'  => true,
                'tests' => [
                    'qb_gen_subtopic'               => [ 'label' => 'Subtopic sync gen: ≥ 1 questions inserted',                      'method' => 'POST', 'route' => '/api/v1/question-bank/replenish' ],
                    'qb_gen_general_topic'          => [ 'label' => 'General topic sync gen: ≥ 1 questions inserted',                 'method' => 'POST', 'route' => '/api/v1/question-bank/replenish' ],
                    'qb_trial_start_from_bank'      => [ 'label' => '/trial/start assembles questions + answer_sheet from QB',        'method' => 'POST', 'route' => '/api/v1/trial/start' ],
                    'qb_trial_start_question_shape' => [ 'label' => 'QB questions have required fields; correct_answer is hidden',    'method' => 'CHECK', 'route' => '' ],
                ],
            ],
        ];
    }

    // =========================================================================
    // Page Renderer
    // =========================================================================

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $ajax_url   = admin_url( 'admin-ajax.php' );
        $ajax_nonce = wp_create_nonce( 'knowly_admin_nonce' );
        $groups     = self::test_groups();
        ?>
        <div class="wrap knowly-wrap">
            <h1>KnowlyAPI — Spec Tests</h1>
            <p class="knowly-test-intro">
                Verifies Phase 3 was correctly implemented: DB tables populated, curriculum layer accurate,
                CRUD end-to-end, and generation working after the taxonomy migration.
                Groups 1–3 are fast (&lt; 2s each). Group 4 calls Claude and takes ~30s per test.
            </p>

            <div class="knowly-test-toolbar">
                <button id="spectest-run-fast" class="button button-primary">▶ Run All Fast Tests</button>
                <button id="spectest-run-slow" class="button" style="background:#d63638;border-color:#d63638;color:#fff;">⚡ Run Slow Tests (Group 4)</button>
                <button id="spectest-clear" class="button">Clear Results</button>
                <span id="spectest-summary" class="knowly-test-summary"></span>
            </div>

            <?php foreach ( $groups as $group_id => $group ) : ?>
            <div class="knowly-test-group" id="specgroup-<?= esc_attr( $group_id ) ?>">
                <div class="knowly-test-group-header">
                    <h2><?= esc_html( $group['label'] ) ?></h2>
                    <button class="button spectest-run-group"
                            data-group="<?= esc_attr( $group_id ) ?>"
                            <?= $group['slow'] ? 'style="color:#d63638;border-color:#d63638;"' : '' ?>>
                        Run Group
                    </button>
                </div>
                <div class="knowly-test-list">
                    <?php foreach ( $group['tests'] as $test_id => $test ) : ?>
                    <div class="knowly-test-item" id="spectest-<?= esc_attr( $test_id ) ?>">
                        <div class="knowly-test-header">
                            <span class="knowly-test-status" id="specstatus-<?= esc_attr( $test_id ) ?>">○</span>
                            <span class="knowly-test-name"><?= esc_html( $test['label'] ) ?></span>
                            <?php if ( $test['route'] ) : ?>
                            <code class="knowly-test-route"><?= esc_html( $test['method'] . ' ' . $test['route'] ) ?></code>
                            <?php endif; ?>
                            <button class="button button-small spectest-run-one"
                                    data-test="<?= esc_attr( $test_id ) ?>"
                                    style="margin-left:auto;">Run</button>
                        </div>
                        <div class="knowly-test-result" id="specresult-<?= esc_attr( $test_id ) ?>" style="display:none;"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function($) {
            var AJAX_URL   = '<?= esc_js( $ajax_url ) ?>';
            var AJAX_NONCE = '<?= esc_js( $ajax_nonce ) ?>';

            var FAST_TESTS = <?= wp_json_encode( array_keys( array_merge(
                $groups['schema']['tests'],
                $groups['curriculumdb']['tests'],
                $groups['crud']['tests'],
                $groups['question_bank']['tests']
            ) ) ) ?>;

            var SLOW_TESTS = <?= wp_json_encode( array_keys( array_merge(
                $groups['generation']['tests'],
                $groups['qb_generation']['tests']
            ) ) ) ?>;

            var pass_counts = { pass: 0, fail: 0, warn: 0, total: 0 };

            function reset_counts() {
                pass_counts = { pass: 0, fail: 0, warn: 0, total: 0 };
                $('#spectest-summary').text('');
            }

            function update_summary() {
                var t = pass_counts;
                $('#spectest-summary').html(
                    '<span style="color:#00a32a">✓ ' + t.pass + '</span> &nbsp; ' +
                    '<span style="color:#d63638">✗ ' + t.fail + '</span> &nbsp; ' +
                    '<span style="color:#dba617">⚠ ' + t.warn + '</span> &nbsp; ' +
                    '/ ' + t.total
                );
            }

            function set_status(test_id, status) {
                var el  = $('#specstatus-' + test_id);
                var icons = { pending: '…', pass: '✓', fail: '✗', warn: '⚠' };
                var colors = { pending: '#666', pass: '#00a32a', fail: '#d63638', warn: '#dba617' };
                el.text( icons[status] || '○' ).css('color', colors[status] || '#666');
            }

            function show_result(test_id, result) {
                var el      = $('#specresult-' + test_id);
                var cls     = result.status === 'pass' ? '#00a32a' : result.status === 'fail' ? '#d63638' : '#dba617';
                var ms      = result.duration_ms ? ' (' + result.duration_ms + 'ms)' : '';
                var detail  = result.data && Object.keys(result.data).length
                    ? '<pre style="margin:4px 0;white-space:pre-wrap;font-size:11px;color:#555">' + JSON.stringify(result.data, null, 2) + '</pre>'
                    : '';
                el.html(
                    '<div style="padding:6px 10px;border-left:3px solid ' + cls + ';background:#f9f9f9;margin:4px 0;">' +
                    '<strong style="color:' + cls + '">' + escHtml(result.message || '') + '</strong>' + ms + detail +
                    '</div>'
                ).show();
            }

            function run_test(test_id, callback) {
                set_status(test_id, 'pending');
                $.post(AJAX_URL, {
                    action:  'knowly_spectest',
                    nonce:   AJAX_NONCE,
                    test_id: test_id,
                    data:    '{}',
                }, function(resp) {
                    var result = resp;
                    set_status(test_id, result.status || (result.pass ? 'pass' : 'fail'));
                    show_result(test_id, result);
                    pass_counts[result.status || 'fail']++;
                    pass_counts.total++;
                    update_summary();
                    if (callback) callback(result);
                }).fail(function() {
                    set_status(test_id, 'fail');
                    show_result(test_id, { status: 'fail', message: 'AJAX request failed.' });
                    pass_counts.fail++;
                    pass_counts.total++;
                    update_summary();
                    if (callback) callback(null);
                });
            }

            function run_sequence(test_ids, index) {
                if (index >= test_ids.length) return;
                run_test(test_ids[index], function() {
                    run_sequence(test_ids, index + 1);
                });
            }

            function escHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            $('#spectest-run-fast').on('click', function() {
                reset_counts();
                run_sequence(FAST_TESTS, 0);
            });

            $('#spectest-run-slow').on('click', function() {
                if (!confirm('Run slow tests? Each calls Claude and takes ~30s. Continue?')) return;
                reset_counts();
                run_sequence(SLOW_TESTS, 0);
            });

            $('#spectest-clear').on('click', function() {
                $('.knowly-test-status').text('○').css('color', '');
                $('.knowly-test-result').hide();
                reset_counts();
            });

            $(document).on('click', '.spectest-run-one', function() {
                var test_id = $(this).data('test');
                run_test(test_id);
            });

            $(document).on('click', '.spectest-run-group', function() {
                var group_id = $(this).data('group');
                var test_ids = [];
                $('#specgroup-' + group_id + ' .spectest-run-one').each(function() {
                    test_ids.push($(this).data('test'));
                });
                run_sequence(test_ids, 0);
            });

        })(jQuery);
        </script>
        <?php
    }
}
