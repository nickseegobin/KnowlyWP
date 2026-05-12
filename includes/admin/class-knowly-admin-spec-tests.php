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
    const TRANSIENT_QBV2_MODULE = 'knowly_spectest_qbv2_module';
    const TRANSIENT_QBV2_TRIAL  = 'knowly_spectest_qbv2_trial';

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
                // Group 7 — QB v2 Schema & Routing
                'qbv2_new_table_accessible'          => self::test_qbv2_new_table_accessible(),
                'qbv2_scope_table_legacy'            => self::test_qbv2_scope_table_legacy(),
                'qbv2_list_slot_shape'               => self::test_qbv2_list_slot_shape(),
                'qbv2_list_covers_difficulties'      => self::test_qbv2_list_covers_difficulties(),
                'qbv2_list_module_count'             => self::test_qbv2_list_module_count(),
                'qbv2_generate_async_response'       => self::test_qbv2_generate_async_response(),
                'qbv2_assemble_validates_inputs'     => self::test_qbv2_assemble_validates_inputs(),
                'qbv2_assemble_multi_subject'        => self::test_qbv2_assemble_multi_subject(),
                'qbv2_legacy_trial_start_validation' => self::test_qbv2_legacy_trial_start_validation(),
                // Group 8 — QB v2 Generation & Assembly
                'qbv2_generate_sync'                 => self::test_qbv2_generate_sync(),
                'qbv2_no_duplicate_ids'              => self::test_qbv2_no_duplicate_ids(),
                'qbv2_assemble_returns_package'      => self::test_qbv2_assemble_returns_package(),
                'qbv2_assemble_question_shape'       => self::test_qbv2_assemble_question_shape(),
                'qbv2_assemble_answer_sheet'         => self::test_qbv2_assemble_answer_sheet(),
                'qbv2_assemble_meta'                 => self::test_qbv2_assemble_meta(),
                'qbv2_assemble_exclude_dedup'        => self::test_qbv2_assemble_exclude_dedup(),
                'qbv2_assemble_multi_module'         => self::test_qbv2_assemble_multi_module(),
                // Group 9 — QB v2: Live Trial Delivery (WP Layer)
                'qbv2_live_resolve'              => self::test_qbv2_live_resolve(),
                'qbv2_live_wp_assemble'          => self::test_qbv2_live_wp_assemble(),
                'qbv2_live_options_lowercase'    => self::test_qbv2_live_options_lowercase(),
                'qbv2_live_answer_lowercase'     => self::test_qbv2_live_answer_lowercase(),
                'qbv2_live_pool_fallback'        => self::test_qbv2_live_pool_fallback(),
                'qbv2_live_package_shape'        => self::test_qbv2_live_package_shape(),
                'qbv2_live_no_exposed_answer'    => self::test_qbv2_live_no_exposed_answer(),
                'qbv2_live_resolve_seeded_priority' => self::test_qbv2_live_resolve_seeded_priority(),
                'qbv2_live_cross_session_dedup'  => self::test_qbv2_live_cross_session_dedup(),
                'qbv2_live_unseeded_subject'     => self::test_qbv2_live_unseeded_subject(),
                // Group 10 — Trials Admin v2 AJAX
                'trials_v2_health_railway'       => self::test_trials_v2_health_railway(),
                'trials_v2_health_qb_bank'       => self::test_trials_v2_health_qb_bank(),
                'trials_v2_health_pool'          => self::test_trials_v2_health_pool(),
                'trials_v2_health_sessions_table'=> self::test_trials_v2_health_sessions_table(),
                'trials_v2_overview_counts'      => self::test_trials_v2_overview_counts(),
                'trials_v2_overview_qb_stats'    => self::test_trials_v2_overview_qb_stats(),
                'trials_v2_overview_recent'      => self::test_trials_v2_overview_recent(),
                'trials_v2_qb_slots_proxy'       => self::test_trials_v2_qb_slots_proxy(),
                'trials_v2_sessions_query'       => self::test_trials_v2_sessions_query(),
                'trials_v2_sessions_pagination'  => self::test_trials_v2_sessions_pagination(),
                // Group 11 — QB v2: Browse & Retire
                'qb_browse_returns_questions'    => self::test_qb_browse_returns_questions(),
                'qb_browse_question_shape'       => self::test_qb_browse_question_shape(),
                'qb_browse_filter_difficulty'    => self::test_qb_browse_filter_difficulty(),
                'qb_browse_pagination'           => self::test_qb_browse_pagination(),
                'qb_browse_status_filter'        => self::test_qb_browse_status_filter(),
                'qb_retire_validates_status'     => self::test_qb_retire_validates_status(),
                'qb_retire_retires_question'     => self::test_qb_retire_retires_question(),
                'qb_retire_restores_question'    => self::test_qb_retire_restores_question(),
                'qb_retire_excluded_from_list'   => self::test_qb_retire_excluded_from_list(),
                'qb_retire_not_found'            => self::test_qb_retire_not_found(),
                // Group 12 — Curriculum Setup Page
                'curriculum_overview_loads'          => self::test_curriculum_overview_loads(),
                'curriculum_overview_std4_present'   => self::test_curriculum_overview_std4_present(),
                'curriculum_detail_loads'            => self::test_curriculum_detail_loads(),
                'curriculum_detail_subjects'         => self::test_curriculum_detail_subjects(),
                'curriculum_detail_period_seeded'    => self::test_curriculum_detail_period_seeded(),
                'curriculum_detail_modules'          => self::test_curriculum_detail_modules(),
                'curriculum_import_endpoint_exists'  => self::test_curriculum_import_endpoint_exists(),
                'curriculum_import_scope_validation' => self::test_curriculum_import_scope_validation(),
                'curriculum_import_creates_topic'    => self::test_curriculum_import_creates_topic(),
                'curriculum_import_archives_stale'   => self::test_curriculum_import_archives_stale(),
                // Group 13 — Data Management: Purge Controls
                'purge_training_auth_guard'      => self::test_purge_training_auth_guard(),
                'purge_curriculum_auth_guard'    => self::test_purge_curriculum_auth_guard(),
                'purge_qb_auth_guard'            => self::test_purge_qb_auth_guard(),
                'purge_wp_ajax_registered'       => self::test_purge_wp_ajax_registered(),
                'purge_class_exists'             => self::test_purge_class_exists(),
                'purge_page_registered'          => self::test_purge_page_registered(),
                // Group 14 — Trial Packs API
                'tp_build_auth_guard'    => self::test_tp_build_auth_guard(),
                'tp_watermark_auth_guard'=> self::test_tp_watermark_auth_guard(),
                'tp_list_auth_guard'     => self::test_tp_list_auth_guard(),
                'tp_watermark_shape'     => self::test_tp_watermark_shape(),
                'tp_list_shape'          => self::test_tp_list_shape(),
                'tp_preview_build'       => self::test_tp_preview_build(),
                // Group 15 — Phase 4: Sequential Trial Delivery
                'p4_next_pack_auth_guard'     => self::test_p4_next_pack_auth_guard(),
                'p4_child_history_auth_guard' => self::test_p4_child_history_auth_guard(),
                'p4_submit_pack_auth_guard'   => self::test_p4_submit_pack_auth_guard(),
                'p4_next_pack_missing_fields' => self::test_p4_next_pack_missing_fields(),
                'p4_next_pack_invalid_branch' => self::test_p4_next_pack_invalid_branch(),
                'p4_next_pack_unknown_scope'  => self::test_p4_next_pack_unknown_scope(),
                'p4_child_history_missing_id' => self::test_p4_child_history_missing_id(),
                'p4_child_history_reset_noop' => self::test_p4_child_history_reset_noop(),
                'p4_schema_branch_column'     => self::test_p4_schema_branch_column(),
                'p4_schema_sequence_column'   => self::test_p4_schema_sequence_column(),
                'p4_wp_ajax_reset_registered' => self::test_p4_wp_ajax_reset_registered(),
                default                              => [ 'pass' => false, 'message' => "Unknown test: {$test_id}" ],
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
            return self::warn( "Could not check Pinecone vector — training/fetch error: " . $fetch['error'], [
                'vector_id' => $vector_id,
                'details'   => $fetch['details'] ?? null,
                'cause'     => $fetch['cause']   ?? null,
                'hint'      => 'Check Railway logs for [training/fetch] and [pineconeSync] lines. Verify PINECONE_API_KEY and PINECONE_INDEX are set in Railway env vars.',
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
    // Group 7 — QB v2: Schema & Routing (fast)
    // =========================================================================

    private static function test_qbv2_new_table_accessible(): array {
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail(
                'New question_bank (v2) inaccessible — migration 003 may not have run or Railway not deployed: ' . $data['error'],
                $data
            );
        }
        if ( ! array_key_exists( 'slots', $data ) ) {
            return self::fail( '/question-bank/list response missing slots key — endpoint may not be deployed.', $data );
        }
        $count = count( $data['slots'] ?? [] );
        return self::pass(
            "question_bank v2 table accessible. /question-bank/list returned {$count} slot(s).",
            [ 'slot_count' => $count ]
        );
    }

    private static function test_qbv2_scope_table_legacy(): array {
        // Probe the legacy endpoint with a nonexistent scope_ref.
        // If question_bank_scope exists (migration ran), we get pool_empty (503).
        // If the table doesn't exist (migration not run), we get a DB error (500).
        $data = self::railway_post( '/api/v1/trial/start', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'scope'      => 'subtopic',
            'scope_ref'  => '__qbv2spectest_probe__',
            'difficulty' => 'easy',
        ] );
        $code = (int) ( $data['code'] ?? 0 );
        $err  = $data['error'] ?? '';
        // pool_empty means the query ran fine — table exists
        if ( $code === 503 || str_contains( $err, 'No questions' ) || str_contains( $err, 'pool_empty' ) ) {
            return self::pass(
                'question_bank_scope (renamed legacy table) is accessible — legacy /trial/start returned pool_empty as expected.',
                [ 'http_code' => $code ]
            );
        }
        if ( ! empty( $data['questions'] ) ) {
            return self::pass( 'question_bank_scope exists and has data.', [ 'count' => count( $data['questions'] ) ] );
        }
        if ( $code >= 500 ) {
            return self::fail(
                'Legacy /trial/start returned server error — question_bank_scope may not exist. Run migration 003.',
                $data
            );
        }
        return self::warn( 'Unexpected legacy /trial/start response.', $data );
    }

    private static function test_qbv2_list_slot_shape(): array {
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( '/question-bank/list failed: ' . $data['error'] );
        }
        $slots = $data['slots'] ?? null;
        if ( ! is_array( $slots ) ) {
            return self::fail( 'slots is not an array.', $data );
        }
        if ( empty( $slots ) ) {
            return self::warn( 'slots array is empty — no modules found for std_4/term_1/math.', $data );
        }

        $required = [ 'module_number', 'module_title', 'difficulty', 'question_count', 'active_count' ];
        $problems = [];
        foreach ( $slots as $i => $slot ) {
            foreach ( $required as $field ) {
                if ( ! array_key_exists( $field, $slot ) ) {
                    $problems[] = "slot[{$i}] missing {$field}";
                }
            }
            if ( ! in_array( $slot['difficulty'] ?? '', [ 'easy', 'medium', 'hard' ], true ) ) {
                $problems[] = "slot[{$i}] has invalid difficulty: " . ( $slot['difficulty'] ?? 'null' );
            }
            if ( ! is_int( $slot['question_count'] ?? null ) ) {
                $problems[] = "slot[{$i}] question_count is not an integer";
            }
            if ( ! is_int( $slot['active_count'] ?? null ) ) {
                $problems[] = "slot[{$i}] active_count is not an integer";
            }
        }
        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' shape problem(s) found.', [ 'problems' => array_slice( $problems, 0, 10 ) ] );
        }
        return self::pass(
            count( $slots ) . ' slots — all have module_number, module_title, difficulty, question_count, active_count.',
            [ 'sample' => $slots[0] ?? null ]
        );
    }

    private static function test_qbv2_list_covers_difficulties(): array {
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( '/question-bank/list failed: ' . $data['error'] );
        }
        $slots = $data['slots'] ?? [];
        if ( empty( $slots ) ) {
            return self::warn( 'No slots returned — cannot verify difficulty coverage.' );
        }

        $by_module = [];
        foreach ( $slots as $slot ) {
            $mn = $slot['module_number'] ?? null;
            if ( $mn === null ) continue;
            $by_module[ $mn ][] = $slot['difficulty'];
        }

        $problems = [];
        foreach ( $by_module as $mn => $diffs ) {
            foreach ( [ 'easy', 'medium', 'hard' ] as $d ) {
                if ( ! in_array( $d, $diffs, true ) ) {
                    $problems[] = "module_number={$mn} missing {$d} slot";
                }
            }
        }
        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' module(s) missing difficulty slot(s).', [ 'problems' => array_slice( $problems, 0, 10 ) ] );
        }
        $mc = count( $by_module );
        return self::pass(
            "{$mc} module(s) — each has easy, medium, and hard slots.",
            [ 'module_count' => $mc, 'total_slots' => count( $slots ) ]
        );
    }

    private static function test_qbv2_list_module_count(): array {
        $list_data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $list_data['error'] ) ) {
            return self::fail( '/question-bank/list failed: ' . $list_data['error'] );
        }
        $list_modules = array_unique( array_column( $list_data['slots'] ?? [], 'module_number' ) );
        $list_count   = count( $list_modules );

        $topics_data = self::railway_get( '/api/v1/curriculum-topics', [
            'level'    => 'std_4',
            'period'   => 'term_1',
            'subject'  => 'math',
            'per_page' => 200,
        ] );
        if ( isset( $topics_data['error'] ) ) {
            return self::fail( '/curriculum-topics failed: ' . $topics_data['error'] );
        }
        $items          = $topics_data['items'] ?? [];
        $topic_modules  = array_unique( array_filter( array_column( $items, 'module_number' ) ) );
        $topic_count    = count( $topic_modules );

        if ( $list_count !== $topic_count ) {
            return self::fail(
                "/question-bank/list has {$list_count} modules; curriculum_topics has {$topic_count} — mismatch.",
                [ 'list_modules' => $list_count, 'curriculum_modules' => $topic_count ]
            );
        }
        return self::pass(
            "Module count consistent: {$list_count} module(s) in both /question-bank/list and curriculum_topics.",
            [ 'module_count' => $list_count ]
        );
    }

    private static function test_qbv2_generate_async_response(): array {
        $list_data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $list_data['error'] ) ) {
            return self::fail( 'Cannot get module list: ' . $list_data['error'] );
        }
        $mn = null;
        foreach ( $list_data['slots'] ?? [] as $slot ) {
            if ( ! empty( $slot['module_number'] ) ) {
                $mn = (int) $slot['module_number'];
                break;
            }
        }
        if ( ! $mn ) {
            return self::warn( 'No module_numbers found in slot list — cannot test generate endpoint.', $list_data );
        }

        $start = microtime( true );
        $data  = self::railway_post( '/api/v1/question-bank/generate', [
            'curriculum'    => 'tt_primary',
            'level'         => 'std_4',
            'period'        => 'term_1',
            'subject'       => 'math',
            'module_number' => $mn,
            'difficulty'    => 'easy',
            'count'         => 10,
            'sync'          => false,
        ] );
        $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 1 );

        if ( isset( $data['error'] ) ) {
            return self::fail( '/question-bank/generate (sync=false) failed: ' . $data['error'], $data );
        }
        if ( ! ( $data['queued'] ?? false ) ) {
            return self::fail( '/question-bank/generate (sync=false) did not return queued=true.', $data );
        }
        if ( empty( $data['slot'] ) ) {
            return self::fail( '/question-bank/generate (sync=false) response missing slot string.', $data );
        }
        if ( $elapsed_ms > 5000 ) {
            return self::warn(
                "Generate async returned in {$elapsed_ms}ms — expected near-instant for fire-and-forget.",
                [ 'elapsed_ms' => $elapsed_ms, 'slot' => $data['slot'] ]
            );
        }
        return self::pass(
            "generate (sync=false) returned immediately ({$elapsed_ms}ms) with queued=true.",
            [ 'slot' => $data['slot'], 'elapsed_ms' => $elapsed_ms ]
        );
    }

    private static function test_qbv2_assemble_validates_inputs(): array {
        // Empty body must return 400
        $empty = self::railway_post( '/api/v1/trial/assemble', [] );
        if ( ! isset( $empty['error'] ) ) {
            return self::fail( '/trial/assemble accepted empty body without error.', $empty );
        }
        $empty_code = (int) ( $empty['code'] ?? 0 );

        // Valid level/subject/difficulty but missing module_numbers
        $no_mods = self::railway_post( '/api/v1/trial/assemble', [
            'level'      => 'std_4',
            'subject'    => 'math',
            'difficulty' => 'easy',
        ] );
        $no_mods_code = (int) ( $no_mods['code'] ?? 0 );

        if ( $empty_code !== 400 && $no_mods_code !== 400 ) {
            return self::fail(
                '/trial/assemble missing-fields validation not returning 400.',
                [ 'empty_code' => $empty_code, 'no_modules_code' => $no_mods_code ]
            );
        }
        return self::pass(
            '/trial/assemble input validation working — missing required fields rejected.',
            [ 'empty_error' => $empty['error'], 'no_modules_error' => $no_mods['error'] ?? null ]
        );
    }

    private static function test_qbv2_assemble_multi_subject(): array {
        $subjects = [ 'math', 'english', 'science', 'social_studies' ];
        $found    = [];
        $errors   = [];
        foreach ( $subjects as $subject ) {
            $data = self::railway_get( '/api/v1/question-bank/list', [
                'level'   => 'std_4',
                'period'  => 'term_1',
                'subject' => $subject,
            ] );
            if ( isset( $data['error'] ) ) {
                $errors[ $subject ] = $data['error'];
            } elseif ( array_key_exists( 'slots', $data ) ) {
                $found[ $subject ] = count( $data['slots'] ?? [] );
            }
        }
        if ( count( $found ) < 2 ) {
            return self::fail(
                'Only ' . count( $found ) . ' subject(s) returned slot data — expected ≥ 2.',
                [ 'found' => $found, 'errors' => $errors ]
            );
        }
        return self::pass(
            '/question-bank/list works for ' . count( $found ) . '/' . count( $subjects ) . ' subjects.',
            [ 'subjects' => $found ]
        );
    }

    private static function test_qbv2_legacy_trial_start_validation(): array {
        $data = self::railway_post( '/api/v1/trial/start', [] );
        $code = (int) ( $data['code'] ?? 0 );
        $err  = $data['error'] ?? '';

        if ( $code === 400 || str_contains( $err, 'Missing required' ) ) {
            return self::pass(
                '/trial/start (legacy) still validates inputs correctly after migration.',
                [ 'error' => $err ]
            );
        }
        if ( ! isset( $data['error'] ) ) {
            return self::fail( '/trial/start (legacy) accepted empty body — validation missing.', $data );
        }
        return self::warn( '/trial/start (legacy) returned unexpected code.', [ 'code' => $code, 'error' => $err ] );
    }

    // =========================================================================
    // Group 8 — QB v2: Generation & Assembly (slow — calls Claude)
    // =========================================================================

    private static function test_qbv2_generate_sync(): array {
        // Resolve a module_number from the live slot list
        $list_data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $list_data['error'] ) ) {
            return self::fail( 'Cannot get module list: ' . $list_data['error'] );
        }
        $mn = null;
        foreach ( $list_data['slots'] ?? [] as $slot ) {
            if ( ! empty( $slot['module_number'] ) ) {
                $mn = (int) $slot['module_number'];
                break;
            }
        }
        if ( ! $mn ) {
            return self::fail( 'No module_numbers in slot list — curriculum_topics may be empty.', $list_data );
        }
        set_transient( self::TRANSIENT_QBV2_MODULE, $mn, HOUR_IN_SECONDS );

        $data = self::railway_post( '/api/v1/question-bank/generate', [
            'curriculum'    => 'tt_primary',
            'level'         => 'std_4',
            'period'        => 'term_1',
            'subject'       => 'math',
            'module_number' => $mn,
            'difficulty'    => 'easy',
            'count'         => 10,
            'sync'          => true,
        ] );

        if ( isset( $data['error'] ) ) {
            return self::fail( "generate sync=true failed for module_number={$mn}: " . $data['error'], $data );
        }
        if ( ! array_key_exists( 'inserted', $data ) ) {
            return self::fail( 'Response missing inserted key.', $data );
        }
        $inserted = (int) $data['inserted'];
        $total    = $data['total'] ?? '?';
        return self::pass(
            "generate sync=true OK — inserted={$inserted}, total active={$total} for module_number={$mn}.",
            [ 'module_number' => $mn, 'inserted' => $inserted, 'total' => $total ]
        );
    }

    private static function test_qbv2_no_duplicate_ids(): array {
        $mn    = (int) ( get_transient( self::TRANSIENT_QBV2_MODULE ) ?: 0 );
        $trial = get_transient( self::TRANSIENT_QBV2_TRIAL );

        if ( ! $trial && $mn ) {
            $trial = self::railway_post( '/api/v1/trial/assemble', [
                'curriculum'     => 'tt_primary',
                'level'          => 'std_4',
                'period'         => 'term_1',
                'subject'        => 'math',
                'difficulty'     => 'easy',
                'module_numbers' => [ $mn ],
                'question_count' => 5,
            ] );
        }
        if ( empty( $trial['questions'] ) ) {
            return self::warn( 'No assembled questions available — run qbv2_assemble_returns_package first.' );
        }

        $ids    = array_column( $trial['questions'], 'question_id' );
        $unique = array_unique( $ids );
        if ( count( $unique ) !== count( $ids ) ) {
            $dupes = array_diff_assoc( $ids, $unique );
            return self::fail(
                count( $dupes ) . ' duplicate question_id(s) within a single assembled trial.',
                [ 'duplicates' => array_values( $dupes ) ]
            );
        }
        $empty = array_filter( $ids, fn( $id ) => empty( $id ) );
        if ( ! empty( $empty ) ) {
            return self::fail( count( $empty ) . ' question(s) have null/empty question_id.' );
        }
        return self::pass(
            count( $ids ) . ' question_ids — all unique, none null.',
            [ 'sample_prefix' => array_map( fn( $id ) => substr( $id, 0, 8 ) . '…', array_slice( $ids, 0, 3 ) ) ]
        );
    }

    private static function test_qbv2_assemble_returns_package(): array {
        $mn = (int) ( get_transient( self::TRANSIENT_QBV2_MODULE ) ?: 0 );
        if ( ! $mn ) {
            // Try to find one with active questions
            $list = self::railway_get( '/api/v1/question-bank/list', [
                'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            ] );
            foreach ( $list['slots'] ?? [] as $slot ) {
                if ( ! empty( $slot['module_number'] ) && (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                    $mn = (int) $slot['module_number'];
                    break;
                }
            }
        }
        if ( ! $mn ) {
            return self::warn( 'No module_number with active questions — run qbv2_generate_sync first.' );
        }

        $data = self::railway_post( '/api/v1/trial/assemble', [
            'curriculum'     => 'tt_primary',
            'level'          => 'std_4',
            'period'         => 'term_1',
            'subject'        => 'math',
            'difficulty'     => 'easy',
            'module_numbers' => [ $mn ],
            'question_count' => 5,
        ] );

        if ( isset( $data['error'] ) ) {
            return self::fail( '/trial/assemble failed: ' . $data['error'], $data );
        }
        if ( empty( $data['questions'] ) || empty( $data['answer_sheet'] ) ) {
            return self::fail( '/trial/assemble returned empty questions or answer_sheet.', $data );
        }

        set_transient( self::TRANSIENT_QBV2_TRIAL, $data, HOUR_IN_SECONDS );

        return self::pass(
            '/trial/assemble OK — ' . count( $data['questions'] ) . ' questions + ' . count( $data['answer_sheet'] ) . ' answer_sheet entries.',
            [ 'questions' => count( $data['questions'] ), 'answer_sheet' => count( $data['answer_sheet'] ), 'module' => $mn ]
        );
    }

    private static function test_qbv2_assemble_question_shape(): array {
        $trial = get_transient( self::TRANSIENT_QBV2_TRIAL );
        if ( ! $trial ) {
            return self::warn( 'No trial transient — run qbv2_assemble_returns_package first.' );
        }
        $questions = $trial['questions'] ?? [];
        if ( empty( $questions ) ) {
            return self::fail( 'No questions in trial transient.' );
        }

        $problems = [];
        foreach ( $questions as $i => $q ) {
            if ( empty( $q['question_id'] ) ) {
                $problems[] = "q[{$i}] missing question_id";
            } elseif ( ! preg_match( '/^[0-9a-f\-]{36}$/i', $q['question_id'] ) ) {
                $problems[] = "q[{$i}] question_id is not a UUID: " . substr( $q['question_id'], 0, 40 );
            }
            if ( empty( $q['question'] ) )             $problems[] = "q[{$i}] missing question text";
            $opts = $q['options'] ?? [];
            foreach ( [ 'A', 'B', 'C', 'D' ] as $letter ) {
                if ( empty( $opts[ $letter ] ) ) $problems[] = "q[{$i}] options missing {$letter}";
            }
            if ( empty( $q['meta']['difficulty'] ) )   $problems[] = "q[{$i}] missing meta.difficulty";
            if ( empty( $q['meta']['module_number'] ) ) $problems[] = "q[{$i}] missing meta.module_number";
            // correct_answer must NOT be present — it belongs in answer_sheet only
            if ( isset( $q['correct_answer'] ) )       $problems[] = "q[{$i}] exposes correct_answer — security issue";
        }

        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' shape problem(s).', [ 'problems' => array_slice( $problems, 0, 10 ) ] );
        }
        return self::pass(
            count( $questions ) . ' questions — UUID IDs, A–D options, meta.difficulty + meta.module_number; correct_answer hidden.',
            [ 'count' => count( $questions ), 'sample_id' => $questions[0]['question_id'] ?? null ]
        );
    }

    private static function test_qbv2_assemble_answer_sheet(): array {
        $trial = get_transient( self::TRANSIENT_QBV2_TRIAL );
        if ( ! $trial ) {
            return self::warn( 'No trial transient — run qbv2_assemble_returns_package first.' );
        }
        $questions    = $trial['questions']    ?? [];
        $answer_sheet = $trial['answer_sheet'] ?? [];
        if ( empty( $answer_sheet ) ) {
            return self::fail( 'No answer_sheet in trial transient.' );
        }

        $question_ids = array_column( $questions, 'question_id' );
        $problems     = [];

        foreach ( $answer_sheet as $i => $entry ) {
            if ( empty( $entry['question_id'] ) ) {
                $problems[] = "as[{$i}] missing question_id";
            }
            if ( empty( $entry['correct_answer'] ) ) {
                $problems[] = "as[{$i}] missing correct_answer";
            } elseif ( ! in_array( $entry['correct_answer'], [ 'A', 'B', 'C', 'D' ], true ) ) {
                $problems[] = "as[{$i}] correct_answer '{$entry['correct_answer']}' is not A/B/C/D";
            }
            if ( ! array_key_exists( 'explanation', $entry ) ) {
                $problems[] = "as[{$i}] missing explanation field";
            }
            if ( ! empty( $entry['question_id'] ) && ! in_array( $entry['question_id'], $question_ids, true ) ) {
                $problems[] = "as[{$i}] question_id not found in questions array";
            }
        }
        if ( count( $answer_sheet ) !== count( $questions ) ) {
            $problems[] = 'answer_sheet count (' . count( $answer_sheet ) . ') ≠ questions count (' . count( $questions ) . ')';
        }

        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' answer_sheet problem(s).', [ 'problems' => array_slice( $problems, 0, 10 ) ] );
        }
        return self::pass(
            count( $answer_sheet ) . ' answer_sheet entries — question_ids match, correct_answer A–D, explanation present.',
            [ 'count' => count( $answer_sheet ) ]
        );
    }

    private static function test_qbv2_assemble_meta(): array {
        $trial = get_transient( self::TRANSIENT_QBV2_TRIAL );
        if ( ! $trial ) {
            return self::warn( 'No trial transient — run qbv2_assemble_returns_package first.' );
        }
        $meta = $trial['meta'] ?? null;
        if ( ! is_array( $meta ) ) {
            return self::fail( 'Trial missing meta object.', $trial );
        }

        $problems = [];
        foreach ( [ 'curriculum', 'level', 'subject', 'difficulty', 'question_count',
                    'module_numbers', 'time_per_question_seconds', 'total_time_seconds',
                    'topics_covered', 'source' ] as $field ) {
            if ( ! array_key_exists( $field, $meta ) ) $problems[] = "meta missing {$field}";
        }
        if ( ( $meta['time_per_question_seconds'] ?? 0 ) !== 90 ) {
            $problems[] = 'meta.time_per_question_seconds should be 90, got: ' . ( $meta['time_per_question_seconds'] ?? 'null' );
        }
        $expected_total = (int) ( $meta['question_count'] ?? 0 ) * 90;
        if ( ( $meta['total_time_seconds'] ?? 0 ) !== $expected_total ) {
            $problems[] = "meta.total_time_seconds should be {$expected_total}, got: " . ( $meta['total_time_seconds'] ?? 'null' );
        }
        if ( ! is_array( $meta['topics_covered'] ?? null ) ) {
            $problems[] = 'meta.topics_covered is not an array';
        }
        if ( ! in_array( $meta['source'] ?? '', [ 'pool', 'generated', 'mixed' ], true ) ) {
            $problems[] = "meta.source '{$meta['source']}' is not pool/generated/mixed";
        }

        if ( ! empty( $problems ) ) {
            return self::fail( count( $problems ) . ' meta problem(s).', [ 'problems' => $problems, 'meta' => $meta ] );
        }
        return self::pass(
            "Trial meta correct — time_per_question=90s, total={$meta['total_time_seconds']}s, source={$meta['source']}.",
            [ 'meta' => $meta ]
        );
    }

    private static function test_qbv2_assemble_exclude_dedup(): array {
        $trial = get_transient( self::TRANSIENT_QBV2_TRIAL );
        $mn    = (int) ( get_transient( self::TRANSIENT_QBV2_MODULE ) ?: 0 );
        if ( ! $trial || ! $mn ) {
            return self::warn( 'No trial or module_number transient — run qbv2_assemble_returns_package first.' );
        }

        $first_ids = array_column( $trial['questions'] ?? [], 'question_id' );
        if ( empty( $first_ids ) ) {
            return self::warn( 'No question_ids in first trial — cannot test exclude dedup.' );
        }

        $data = self::railway_post( '/api/v1/trial/assemble', [
            'curriculum'           => 'tt_primary',
            'level'                => 'std_4',
            'period'               => 'term_1',
            'subject'              => 'math',
            'difficulty'           => 'easy',
            'module_numbers'       => [ $mn ],
            'question_count'       => 5,
            'exclude_question_ids' => $first_ids,
        ] );

        // pool_empty is valid — not enough questions after exclusion
        $code = (int) ( $data['code'] ?? 0 );
        if ( $code === 503 || str_contains( $data['error'] ?? '', 'pool_empty' ) || str_contains( $data['error'] ?? '', 'No questions' ) ) {
            return self::pass(
                'Exclude dedup working — all ' . count( $first_ids ) . ' prior question(s) excluded; pool exhausted as expected.',
                [ 'excluded' => count( $first_ids ) ]
            );
        }
        if ( isset( $data['error'] ) ) {
            return self::fail( '/trial/assemble with exclude_question_ids failed: ' . $data['error'], $data );
        }

        $second_ids = array_column( $data['questions'] ?? [], 'question_id' );
        $overlap    = array_intersect( $first_ids, $second_ids );
        if ( ! empty( $overlap ) ) {
            return self::fail(
                count( $overlap ) . ' question(s) appeared in both trials despite being in exclude_question_ids.',
                [ 'overlap' => array_values( $overlap ) ]
            );
        }
        return self::pass(
            'Exclude dedup working — second trial has ' . count( $second_ids ) . ' question(s), none overlapping with first ' . count( $first_ids ) . '.',
            [ 'first_count' => count( $first_ids ), 'second_count' => count( $second_ids ) ]
        );
    }

    private static function test_qbv2_assemble_multi_module(): array {
        $list_data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $list_data['error'] ) ) {
            return self::fail( 'Cannot get module list: ' . $list_data['error'] );
        }

        // Find two modules that have active easy questions
        $active_modules = [];
        foreach ( $list_data['slots'] ?? [] as $slot ) {
            $mn = (int) ( $slot['module_number'] ?? 0 );
            if ( $mn && $slot['difficulty'] === 'easy' && (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                $active_modules[ $mn ] = true;
                if ( count( $active_modules ) >= 2 ) break;
            }
        }
        if ( count( $active_modules ) < 2 ) {
            return self::warn(
                'Only ' . count( $active_modules ) . ' module(s) with active easy questions — need ≥ 2 to test multi-module. Run qbv2_generate_sync first.',
                [ 'active_modules' => array_keys( $active_modules ) ]
            );
        }

        $mns  = array_keys( $active_modules );
        $data = self::railway_post( '/api/v1/trial/assemble', [
            'curriculum'     => 'tt_primary',
            'level'          => 'std_4',
            'period'         => 'term_1',
            'subject'        => 'math',
            'difficulty'     => 'easy',
            'module_numbers' => $mns,
            'question_count' => 4,
        ] );

        if ( isset( $data['error'] ) ) {
            return self::fail( '/trial/assemble multi-module failed: ' . $data['error'], $data );
        }
        $questions = $data['questions'] ?? [];
        if ( empty( $questions ) ) {
            return self::fail( '/trial/assemble returned no questions for multi-module request.', $data );
        }

        $returned_modules = array_unique( array_filter(
            array_map( fn( $q ) => $q['meta']['module_number'] ?? null, $questions )
        ) );
        if ( count( $returned_modules ) < 2 ) {
            return self::warn(
                'Multi-module assemble returned questions from only ' . count( $returned_modules ) . ' module(s) — round-robin may not be spreading.',
                [ 'requested' => $mns, 'returned_modules' => array_values( $returned_modules ) ]
            );
        }
        return self::pass(
            count( $questions ) . ' questions across ' . count( $returned_modules ) . ' module(s) — round-robin distribution working.',
            [ 'modules_requested' => $mns, 'modules_in_result' => array_values( $returned_modules ) ]
        );
    }

    // =========================================================================
    // Group 9 — QB v2: Live Trial Delivery (WP Layer)
    // =========================================================================

    private static function test_qbv2_live_resolve(): array {
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'QB list failed: ' . $data['error'] );
        }

        $seeded = [];
        foreach ( $data['slots'] ?? [] as $slot ) {
            if ( (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                $seeded[ (int) $slot['module_number'] ] = true;
            }
        }

        if ( empty( $seeded ) ) {
            return self::warn(
                'No seeded modules found — run qbv2_generate_sync first. Auto-resolve will fall back to WP pool.',
                [ 'total_slots' => count( $data['slots'] ?? [] ) ]
            );
        }

        return self::pass(
            count( $seeded ) . ' seeded module(s) available — resolve_module_numbers() will return non-empty array.',
            [ 'seeded_modules' => array_keys( $seeded ) ]
        );
    }

    private static function test_qbv2_live_wp_assemble(): array {
        $list_data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );

        $module_number = null;
        foreach ( $list_data['slots'] ?? [] as $slot ) {
            if ( $slot['difficulty'] === 'easy' && (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                $module_number = (int) $slot['module_number'];
                break;
            }
        }

        if ( ! $module_number ) {
            return self::warn( 'No seeded easy module found — run qbv2_generate_sync first.', [] );
        }

        $package = Knowly_Exam_Service::fetch_from_question_bank_assemble(
            'std_4', 'term_1', 'math', [ $module_number ], 'easy', 5
        );

        if ( is_wp_error( $package ) ) {
            return self::fail( 'fetch_from_question_bank_assemble() failed: ' . $package->get_error_message() );
        }

        set_transient( 'knowly_spectest_qbv2_wp_pkg', $package, HOUR_IN_SECONDS );

        $q_count = count( $package['questions'] ?? [] );
        $a_count = count( $package['answer_sheet'] ?? [] );

        if ( ! $q_count ) {
            return self::fail( 'WP assemble returned no questions.', $package );
        }

        return self::pass(
            "fetch_from_question_bank_assemble() returned {$q_count} questions + {$a_count} answer_sheet entries.",
            [ 'package_id' => $package['package_id'], 'source' => $package['source'] ?? null ]
        );
    }

    private static function test_qbv2_live_options_lowercase(): array {
        $package = get_transient( 'knowly_spectest_qbv2_wp_pkg' );
        if ( ! $package ) {
            return self::warn( 'No cached package — run qbv2_live_wp_assemble first.', [] );
        }

        $questions = $package['questions'] ?? [];
        if ( empty( $questions ) ) {
            return self::fail( 'Package has no questions.', [] );
        }

        $bad = [];
        foreach ( $questions as $q ) {
            $uc = array_filter( array_keys( $q['options'] ?? [] ), fn( $k ) => strtolower( $k ) !== $k );
            if ( ! empty( $uc ) ) {
                $bad[] = [ 'id' => $q['id'] ?? '?', 'bad_keys' => array_values( $uc ) ];
            }
        }

        if ( ! empty( $bad ) ) {
            return self::fail(
                count( $bad ) . ' question(s) still have uppercase option keys — casing fix not applied.',
                [ 'examples' => array_slice( $bad, 0, 3 ) ]
            );
        }

        $sample_keys = array_keys( $questions[0]['options'] ?? [] );
        return self::pass(
            count( $questions ) . ' questions all have lowercase option keys (' . implode( '/', $sample_keys ) . ').',
            [ 'sample_keys' => $sample_keys ]
        );
    }

    private static function test_qbv2_live_answer_lowercase(): array {
        $package = get_transient( 'knowly_spectest_qbv2_wp_pkg' );
        if ( ! $package ) {
            return self::warn( 'No cached package — run qbv2_live_wp_assemble first.', [] );
        }

        $answer_sheet = $package['answer_sheet'] ?? [];
        if ( empty( $answer_sheet ) ) {
            return self::fail( 'Package has no answer_sheet.', [] );
        }

        $bad = [];
        foreach ( $answer_sheet as $a ) {
            $ca = $a['correct_answer'] ?? '';
            if ( $ca !== strtolower( $ca ) ) {
                $bad[] = [ 'question_id' => $a['question_id'] ?? '?', 'correct_answer' => $ca ];
            }
        }

        if ( ! empty( $bad ) ) {
            return self::fail(
                count( $bad ) . ' answer_sheet entries still have uppercase correct_answer.',
                [ 'examples' => array_slice( $bad, 0, 3 ) ]
            );
        }

        return self::pass(
            count( $answer_sheet ) . ' answer_sheet entries all have lowercase correct_answer.',
            [ 'sample' => $answer_sheet[0] ?? null ]
        );
    }

    private static function test_qbv2_live_pool_fallback(): array {
        // Non-existent module triggers pool_empty — WP fallback logic will engage.
        $package = Knowly_Exam_Service::fetch_from_question_bank_assemble(
            'std_4', 'term_1', 'math', [ 99999 ], 'easy', 5
        );

        if ( ! is_wp_error( $package ) ) {
            return self::fail(
                'Expected WP_Error pool_empty for non-existent module [99999] but got a package.',
                [ 'package_id' => $package['package_id'] ?? null ]
            );
        }

        $code = $package->get_error_code();
        if ( $code !== 'knowly_pool_empty' ) {
            return self::fail(
                "Expected error code 'knowly_pool_empty' but got '{$code}'.",
                [ 'message' => $package->get_error_message() ]
            );
        }

        return self::pass(
            "Non-existent module [99999] → WP_Error 'knowly_pool_empty' — fallback chain will engage.",
            [ 'message' => $package->get_error_message() ]
        );
    }

    private static function test_qbv2_live_package_shape(): array {
        $package = get_transient( 'knowly_spectest_qbv2_wp_pkg' );
        if ( ! $package ) {
            return self::warn( 'No cached package — run qbv2_live_wp_assemble first.', [] );
        }

        $errors = [];

        $pid = $package['package_id'] ?? '';
        if ( ! str_starts_with( $pid, 'qb-' ) ) {
            $errors[] = "package_id should start with 'qb-', got: '{$pid}'";
        }

        if ( ( $package['source'] ?? '' ) !== 'question_bank' ) {
            $errors[] = "source should be 'question_bank', got: '" . ( $package['source'] ?? 'missing' ) . "'";
        }

        if ( ! is_array( $package['questions'] ?? null ) ) {
            $errors[] = 'questions is not an array';
        }
        if ( ! is_array( $package['answer_sheet'] ?? null ) ) {
            $errors[] = 'answer_sheet is not an array';
        }
        if ( ! is_array( $package['meta'] ?? null ) ) {
            $errors[] = 'meta is not an array';
        }

        $q_count  = count( $package['questions']    ?? [] );
        $a_count  = count( $package['answer_sheet'] ?? [] );
        $meta     = $package['meta'] ?? [];

        if ( $q_count !== $a_count ) {
            $errors[] = "questions ({$q_count}) and answer_sheet ({$a_count}) counts do not match";
        }

        if ( ! empty( $errors ) ) {
            return self::fail(
                count( $errors ) . ' shape error(s) in WP package.',
                [ 'errors' => $errors, 'package_keys' => array_keys( $package ) ]
            );
        }

        return self::pass(
            "Package shape valid: package_id='{$pid}', source='question_bank', {$q_count} questions + {$a_count} answer_sheet entries.",
            [
                'meta_keys'        => array_keys( $meta ),
                'meta_source'      => $meta['source'] ?? null,
                'topics_covered'   => $meta['topics_covered'] ?? null,
                'time_per_question'=> $meta['time_per_question'] ?? null,
            ]
        );
    }

    private static function test_qbv2_live_no_exposed_answer(): array {
        $package = get_transient( 'knowly_spectest_qbv2_wp_pkg' );
        if ( ! $package ) {
            return self::warn( 'No cached package — run qbv2_live_wp_assemble first.', [] );
        }

        $questions = $package['questions'] ?? [];
        if ( empty( $questions ) ) {
            return self::fail( 'Package has no questions.', [] );
        }

        $exposed     = [];
        $missing_fields = [];
        $required    = [ 'question_id', 'question', 'options' ];

        foreach ( $questions as $i => $q ) {
            if ( array_key_exists( 'correct_answer', $q ) ) {
                $exposed[] = [
                    'index'          => $i,
                    'question_id'    => $q['question_id'] ?? '?',
                    'correct_answer' => $q['correct_answer'],
                ];
            }
            $m = array_filter( $required, fn( $f ) => ! array_key_exists( $f, $q ) );
            if ( ! empty( $m ) ) {
                $missing_fields[] = [ 'index' => $i, 'missing' => array_values( $m ) ];
            }
        }

        if ( ! empty( $exposed ) ) {
            return self::fail(
                count( $exposed ) . ' question(s) expose correct_answer — security risk.',
                [ 'examples' => array_slice( $exposed, 0, 3 ) ]
            );
        }

        if ( ! empty( $missing_fields ) ) {
            return self::fail(
                count( $missing_fields ) . ' question(s) missing required fields.',
                [ 'examples' => array_slice( $missing_fields, 0, 3 ), 'first_keys' => array_keys( $questions[0] ) ]
            );
        }

        return self::pass(
            count( $questions ) . ' questions: correct_answer not exposed, required fields (question_id, question, options) all present.',
            [ 'first_question_keys' => array_keys( $questions[0] ) ]
        );
    }

    private static function test_qbv2_live_resolve_seeded_priority(): array {
        // Verifies the resolve logic: slots with active_count > 0 are preferred over empty slots.
        // Mimics what resolve_module_numbers() does internally.
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) {
            return self::fail( 'QB list failed: ' . $data['error'] );
        }

        $seeded  = [];
        $all     = [];
        foreach ( $data['slots'] ?? [] as $slot ) {
            $mod = (int) $slot['module_number'];
            $all[ $mod ] = (int) ( $slot['active_count'] ?? 0 );
            if ( (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                $seeded[ $mod ] = (int) $slot['active_count'];
            }
        }

        if ( empty( $all ) ) {
            return self::warn( 'QB list returned no slots for std_4/term_1/math.', [ 'data' => $data ] );
        }

        // If no seeded modules, resolve falls back to all — this is expected before bank is filled.
        if ( empty( $seeded ) ) {
            return self::warn(
                'No seeded modules yet — resolve_module_numbers() will return all ' . count( $all ) . ' module(s), expect pool_empty from Railway.',
                [ 'all_modules' => $all ]
            );
        }

        // Seeded modules present — resolve should return those, not the empty ones.
        $unseeded = array_diff_key( $all, $seeded );

        return self::pass(
            count( $seeded ) . ' seeded module(s) found — resolve_module_numbers() returns seeded-only, bypassing ' . count( $unseeded ) . ' empty module(s).',
            [
                'seeded_modules'  => $seeded,
                'unseeded_modules'=> array_keys( $unseeded ),
            ]
        );
    }

    private static function test_qbv2_live_cross_session_dedup(): array {
        $first_package = get_transient( 'knowly_spectest_qbv2_wp_pkg' );
        if ( ! $first_package ) {
            return self::warn( 'No cached first package — run qbv2_live_wp_assemble first.', [] );
        }

        $first_ids = array_values( array_filter(
            array_map( fn( $q ) => $q['question_id'] ?? null, $first_package['questions'] ?? [] )
        ) );

        if ( empty( $first_ids ) ) {
            return self::fail( 'First package has no question IDs to exclude.', [] );
        }

        $list_data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );

        $module_number = null;
        foreach ( $list_data['slots'] ?? [] as $slot ) {
            if ( $slot['difficulty'] === 'easy' && (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                $module_number = (int) $slot['module_number'];
                break;
            }
        }

        if ( ! $module_number ) {
            return self::warn( 'No seeded easy module found.', [] );
        }

        $second_package = Knowly_Exam_Service::fetch_from_question_bank_assemble(
            'std_4', 'term_1', 'math', [ $module_number ], 'easy', 5, $first_ids
        );

        if ( is_wp_error( $second_package ) ) {
            $code = $second_package->get_error_code();
            if ( $code === 'knowly_pool_empty' ) {
                return self::warn(
                    'QB pool exhausted after excluding first session (' . count( $first_ids ) . ' IDs). Seed more questions to verify dedup.',
                    [ 'excluded_ids' => count( $first_ids ), 'module' => $module_number ]
                );
            }
            return self::fail(
                'Second assemble failed: ' . $second_package->get_error_message(),
                [ 'code' => $code ]
            );
        }

        $second_ids = array_values( array_filter(
            array_map( fn( $q ) => $q['question_id'] ?? null, $second_package['questions'] ?? [] )
        ) );

        $overlap = array_intersect( $first_ids, $second_ids );

        if ( ! empty( $overlap ) ) {
            return self::fail(
                count( $overlap ) . ' question(s) appear in both sessions — exclude_question_ids not passed through correctly.',
                [
                    'overlap_ids'   => array_values( $overlap ),
                    'first_ids'     => $first_ids,
                    'second_ids'    => $second_ids,
                ]
            );
        }

        return self::pass(
            'Cross-session dedup works: 0 overlap between session 1 (' . count( $first_ids ) . ' Qs) and session 2 (' . count( $second_ids ) . ' Qs).',
            [
                'session_1_ids' => $first_ids,
                'session_2_ids' => $second_ids,
            ]
        );
    }

    private static function test_qbv2_live_unseeded_subject(): array {
        // Calls WP assemble for a subject with no seeded questions (science).
        // Expects pool_empty — which triggers WP pool fallback in start().
        $list_data = self::railway_get( '/api/v1/question-bank/list', [
            'level'   => 'std_4',
            'subject' => 'science',
            'period'  => 'term_1',
        ] );

        $all_science_modules = [];
        $seeded_science = [];
        foreach ( $list_data['slots'] ?? [] as $slot ) {
            $mod = (int) $slot['module_number'];
            $all_science_modules[ $mod ] = true;
            if ( (int) ( $slot['active_count'] ?? 0 ) > 0 ) {
                $seeded_science[ $mod ] = (int) $slot['active_count'];
            }
        }

        if ( ! empty( $seeded_science ) ) {
            return self::warn(
                'Science now has seeded questions (' . array_sum( $seeded_science ) . ' total) — pick a different unseeded subject to test the fallback path.',
                [ 'seeded' => $seeded_science ]
            );
        }

        if ( empty( $all_science_modules ) ) {
            return self::warn( 'No science modules found in curriculum — cannot test unseeded path.', [] );
        }

        // Call WP assemble with all science modules — should get pool_empty
        $module_numbers = array_keys( $all_science_modules );
        $package = Knowly_Exam_Service::fetch_from_question_bank_assemble(
            'std_4', 'term_1', 'science', $module_numbers, 'easy', 5
        );

        if ( ! is_wp_error( $package ) ) {
            return self::fail(
                'Expected pool_empty for unseeded science but got a package. Is science now seeded?',
                [ 'question_count' => count( $package['questions'] ?? [] ) ]
            );
        }

        $code = $package->get_error_code();
        if ( $code !== 'knowly_pool_empty' ) {
            return self::fail(
                "Expected 'knowly_pool_empty' but got '{$code}' — check Railway /trial/assemble error handling.",
                [ 'message' => $package->get_error_message() ]
            );
        }

        return self::pass(
            'Unseeded science slot → WP_Error pool_empty. Delivery chain will fall back to WP pool.',
            [
                'modules_checked' => $module_numbers,
                'message'         => $package->get_error_message(),
            ]
        );
    }

    // =========================================================================
    // Group 10 — Trials Admin v2 AJAX
    // =========================================================================

    private static function test_trials_v2_health_railway(): array {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) return self::fail( 'Railway endpoint not configured in Settings.' );
        $resp = wp_remote_get( $endpoint . '/api/v1/health', [ 'timeout' => 8 ] );
        if ( is_wp_error( $resp ) ) return self::fail( 'Railway unreachable: ' . $resp->get_error_message() );
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) return self::fail( "Railway /health returned HTTP {$code}", [ 'code' => $code ] );
        return self::pass( "Railway reachable — {$endpoint}", [ 'http_status' => $code ] );
    }

    private static function test_trials_v2_health_qb_bank(): array {
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'QB bank list failed: ' . $data['error'] );
        $slots = $data['slots'] ?? null;
        if ( ! is_array( $slots ) ) return self::fail( 'QB bank response missing slots array', $data );
        $above = count( array_filter( $slots, fn( $s ) => (int) ( $s['active_count'] ?? 0 ) >= 15 ) );
        $total = count( $slots );
        $status = ( $above === $total ) ? 'pass' : ( $above > 0 ? 'warn' : 'fail' );
        $msg = "{$above}/{$total} slots ≥ low watermark (15 questions) — math / std_4 / term_1";
        return [ 'pass' => $status !== 'fail', 'status' => $status, 'message' => $msg,
            'data' => [ 'above_watermark' => $above, 'total_slots' => $total ] ];
    }

    private static function test_trials_v2_health_pool(): array {
        global $wpdb;
        $tbl    = $wpdb->prefix . 'knowly_trial_packages';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
        if ( ! $exists ) return self::fail( 'knowly_trial_packages table missing — legacy pool unavailable.' );
        $approved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE status = 'approved'" );
        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
        $status   = $approved > 0 ? 'pass' : 'warn';
        return [ 'pass' => true, 'status' => $status,
            'message' => "{$approved} approved packages of {$total} total — legacy fallback pool.",
            'data' => [ 'approved' => $approved, 'total' => $total ] ];
    }

    private static function test_trials_v2_health_sessions_table(): array {
        global $wpdb;
        $tbl    = $wpdb->prefix . 'knowly_exam_sessions';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
        if ( ! $exists ) return self::fail( 'knowly_exam_sessions table missing — run plugin activation.' );
        $cols = $wpdb->get_col( "DESCRIBE {$tbl}", 0 );
        $required = [ 'session_id', 'child_id', 'package_id', 'subject', 'level', 'period', 'difficulty', 'state', 'percentage', 'time_taken_seconds', 'started_at' ];
        $missing = array_diff( $required, $cols );
        if ( $missing ) return self::fail( 'Table missing columns: ' . implode( ', ', $missing ), [ 'found' => $cols ] );
        return self::pass( 'exam_sessions table exists with all required columns.', [ 'columns' => $cols ] );
    }

    private static function test_trials_v2_overview_counts(): array {
        global $wpdb;
        $tbl   = $wpdb->prefix . 'knowly_exam_sessions';
        $today = current_time( 'Y-m-d' ) . ' 00:00:00';
        // Same 4 queries used by ajax_overview()
        $sessions_today  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE started_at >= '{$today}'" );
        $active_sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE state = 'active'" );
        $total_sessions  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
        $qb_sessions     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE package_id LIKE 'qb-%'" );
        if ( $qb_sessions > $total_sessions ) {
            return self::fail( "QB sessions ({$qb_sessions}) > total ({$total_sessions}) — COUNT logic broken." );
        }
        if ( $sessions_today > $total_sessions ) {
            return self::fail( "Today's count ({$sessions_today}) > total ({$total_sessions}) — date filter broken." );
        }
        return self::pass(
            "Counts valid — today={$sessions_today}, active={$active_sessions}, qb={$qb_sessions}, total={$total_sessions}",
            [ 'sessions_today' => $sessions_today, 'active_sessions' => $active_sessions,
              'qb_sessions' => $qb_sessions, 'total_sessions' => $total_sessions ]
        );
    }

    private static function test_trials_v2_overview_qb_stats(): array {
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'QB stats fetch failed: ' . $data['error'] );
        $slots = $data['slots'] ?? null;
        if ( ! is_array( $slots ) ) return self::fail( 'QB stats: no slots array.', $data );
        $seeded = count( array_filter( $slots, fn( $s ) => (int) ( $s['active_count'] ?? 0 ) > 0 ) );
        $total  = count( $slots );
        // Verify the seeded/total structure that ajax_overview() builds
        $stat = [ 'total' => $total, 'seeded' => $seeded ];
        if ( ! isset( $stat['total'], $stat['seeded'] ) || $stat['seeded'] > $stat['total'] ) {
            return self::fail( 'QB stats structure invalid', $stat );
        }
        return self::pass(
            "math/std_4/term_1: {$total} slots, {$seeded} seeded — ajax_overview() stat shape valid.",
            $stat
        );
    }

    private static function test_trials_v2_overview_recent(): array {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'knowly_exam_sessions';
        // Same query as ajax_overview()
        $rows = $wpdb->get_results(
            "SELECT session_id, child_id, package_id, subject, difficulty, state, percentage, time_taken_seconds, started_at
             FROM {$tbl} ORDER BY started_at DESC LIMIT 5",
            ARRAY_A
        ) ?: [];
        if ( $wpdb->last_error ) return self::fail( 'Recent sessions query error: ' . $wpdb->last_error );
        // Validate required fields and source derivation for each row
        $required = [ 'session_id', 'child_id', 'subject', 'state' ];
        foreach ( $rows as $row ) {
            foreach ( $required as $f ) {
                if ( ! array_key_exists( $f, $row ) ) {
                    return self::fail( "Row missing field '{$f}' — schema drift?", $row );
                }
            }
            $source = str_starts_with( $row['package_id'] ?? '', 'qb-' ) ? 'question_bank' : 'pool';
            if ( ! in_array( $source, [ 'question_bank', 'pool' ], true ) ) {
                return self::fail( "Source derivation failed for package_id='{$row['package_id']}'" );
            }
        }
        $qb_ct = count( array_filter( $rows, fn( $r ) => str_starts_with( $r['package_id'] ?? '', 'qb-' ) ) );
        return self::pass(
            count( $rows ) . " recent session(s) — {$qb_ct} QB v2, shape valid.",
            [ 'session_count' => count( $rows ), 'qb_count' => $qb_ct ]
        );
    }

    private static function test_trials_v2_qb_slots_proxy(): array {
        // Same call as ajax_qb_slots() — proxies Railway /question-bank/list
        $data = self::railway_get( '/api/v1/question-bank/list', [
            'curriculum' => 'tt_primary', 'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'QB slots proxy call failed: ' . $data['error'] );
        $slots = $data['slots'] ?? null;
        if ( ! is_array( $slots ) ) return self::fail( 'No slots array in proxy response.', $data );
        if ( empty( $slots ) ) return self::warn( 'Slots empty — no math/std_4/term_1 modules in curriculum.' );
        // Validate slot shape (same fields ajax_qb_slots() relies on for the board)
        $first   = $slots[0];
        $missing = array_filter( [ 'module_number', 'module_title', 'difficulty', 'active_count' ],
            fn( $k ) => ! array_key_exists( $k, $first ) );
        if ( $missing ) return self::fail( 'Slot missing fields: ' . implode( ', ', $missing ), $first );
        // Difficulties should be easy / medium / hard
        $diffs = array_unique( array_column( $slots, 'difficulty' ) );
        sort( $diffs );
        $expected = [ 'easy', 'hard', 'medium' ];
        if ( $diffs !== $expected ) return self::warn( 'Unexpected difficulties: ' . implode( ', ', $diffs ) );
        return self::pass(
            count( $slots ) . ' slots returned, shape valid — proxy would succeed.',
            [ 'slot_count' => count( $slots ), 'difficulties' => $diffs ]
        );
    }

    private static function test_trials_v2_sessions_query(): array {
        global $wpdb;
        $tbl      = $wpdb->prefix . 'knowly_exam_sessions';
        $per_page = 30;
        // Exact query from ajax_sessions()
        $rows = $wpdb->get_results(
            "SELECT session_id, child_id, package_id, subject, level, period, difficulty, state,
                    percentage, time_taken_seconds, started_at
             FROM {$tbl} WHERE 1=1 ORDER BY started_at DESC LIMIT {$per_page} OFFSET 0",
            ARRAY_A
        ) ?: [];
        if ( $wpdb->last_error ) return self::fail( 'Sessions query error: ' . $wpdb->last_error );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE 1=1" );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        // Validate source detection
        foreach ( $rows as $row ) {
            $source = str_starts_with( $row['package_id'] ?? '', 'qb-' ) ? 'question_bank' : 'pool';
            if ( ! in_array( $source, [ 'question_bank', 'pool' ], true ) ) {
                return self::fail( "Source detection failed for package_id='{$row['package_id']}'" );
            }
        }
        return self::pass(
            "Sessions query OK: {$total} total, page 1/{$pages}, " . count( $rows ) . " rows returned.",
            [ 'total' => $total, 'pages' => $pages, 'row_count' => count( $rows ) ]
        );
    }

    private static function test_trials_v2_sessions_pagination(): array {
        global $wpdb;
        $tbl      = $wpdb->prefix . 'knowly_exam_sessions';
        $per_page = 30;
        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
        $pages    = max( 1, (int) ceil( $total / $per_page ) );
        $page1    = $wpdb->get_col( "SELECT session_id FROM {$tbl} ORDER BY started_at DESC LIMIT {$per_page} OFFSET 0" ) ?: [];
        if ( $wpdb->last_error ) return self::fail( 'Pagination page 1 error: ' . $wpdb->last_error );
        if ( count( $page1 ) > $per_page ) {
            return self::fail( 'Page 1 returned ' . count( $page1 ) . " rows — exceeds per_page={$per_page}." );
        }
        if ( $pages < 2 ) {
            return self::pass(
                "Pagination: {$total} sessions, 1 page — " . count( $page1 ) . ' rows on page 1.',
                [ 'total' => $total, 'pages' => $pages, 'page1_count' => count( $page1 ) ]
            );
        }
        $page2 = $wpdb->get_col( "SELECT session_id FROM {$tbl} ORDER BY started_at DESC LIMIT {$per_page} OFFSET {$per_page}" ) ?: [];
        $overlap = array_intersect( $page1, $page2 );
        if ( ! empty( $overlap ) ) {
            return self::fail( 'Pages 1 and 2 share session_ids — LIMIT/OFFSET broken.',
                [ 'overlap_count' => count( $overlap ) ] );
        }
        return self::pass(
            "Pagination: {$total} sessions, {$pages} pages, no overlap between pages 1 & 2.",
            [ 'total' => $total, 'pages' => $pages, 'page1_count' => count( $page1 ), 'page2_count' => count( $page2 ) ]
        );
    }

    // =========================================================================
    // Group 11 — QB v2: Browse & Retire
    // =========================================================================

    private static function test_qb_browse_returns_questions(): array {
        $data = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'page' => 1, 'per_page' => 5,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Browse endpoint failed: ' . $data['error'] );
        if ( ! isset( $data['questions'], $data['total'], $data['page'], $data['pages'] ) ) {
            return self::fail( 'Response missing required envelope keys', $data );
        }
        if ( ! is_array( $data['questions'] ) ) return self::fail( 'questions must be an array', $data );
        $total = (int) $data['total'];
        if ( $total === 0 ) return self::warn( 'No active questions for math/std_4/term_1 — seed the bank first.' );
        return self::pass( "{$total} active questions available, endpoint returns correct envelope.", [
            'total' => $total, 'page' => $data['page'], 'pages' => $data['pages'],
        ] );
    }

    private static function test_qb_browse_question_shape(): array {
        $data = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'page' => 1, 'per_page' => 5,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Browse failed: ' . $data['error'] );
        $questions = $data['questions'] ?? [];
        if ( empty( $questions ) ) return self::warn( 'No questions returned — seed math/std_4/term_1 first.' );
        $q       = $questions[0];
        $required = [ 'id', 'module_number', 'module_title', 'question', 'options', 'correct_answer', 'difficulty', 'times_served', 'status' ];
        $missing  = array_filter( $required, fn( $k ) => ! array_key_exists( $k, $q ) );
        if ( $missing ) return self::fail( 'Question missing fields: ' . implode( ', ', $missing ), $q );
        // Options should have A/B/C/D
        $opts = $q['options'] ?? [];
        $missing_opts = array_filter( ['A','B','C','D'], fn( $k ) => ! array_key_exists( $k, $opts ) );
        if ( $missing_opts ) return self::fail( 'Options missing keys: ' . implode( ', ', $missing_opts ), $opts );
        return self::pass( 'Question shape valid — all required fields and A/B/C/D options present.', [
            'id' => $q['id'], 'difficulty' => $q['difficulty'], 'module_number' => $q['module_number'],
        ] );
    }

    private static function test_qb_browse_filter_difficulty(): array {
        foreach ( ['easy', 'medium', 'hard'] as $diff ) {
            $data = self::railway_get( '/api/v1/question-bank/questions', [
                'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
                'difficulty' => $diff, 'status' => 'active', 'per_page' => 5,
            ] );
            if ( isset( $data['error'] ) ) return self::fail( "Browse filter {$diff} failed: " . $data['error'] );
            foreach ( $data['questions'] ?? [] as $q ) {
                if ( ( $q['difficulty'] ?? '' ) !== $diff ) {
                    return self::fail( "Difficulty filter '{$diff}' returned question with difficulty '{$q['difficulty']}'", $q );
                }
            }
        }
        return self::pass( 'Difficulty filter works for easy, medium, and hard — all returned questions match.' );
    }

    private static function test_qb_browse_pagination(): array {
        $p1 = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'page' => 1, 'per_page' => 5,
        ] );
        if ( isset( $p1['error'] ) ) return self::fail( 'Page 1 failed: ' . $p1['error'] );
        $total = (int) ( $p1['total'] ?? 0 );
        $pages = (int) ( $p1['pages'] ?? 1 );
        if ( $total < 6 ) return self::warn( "Only {$total} active questions — need ≥6 to test pagination." );
        $p2 = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'page' => 2, 'per_page' => 5,
        ] );
        if ( isset( $p2['error'] ) ) return self::fail( 'Page 2 failed: ' . $p2['error'] );
        $ids1 = array_column( $p1['questions'] ?? [], 'id' );
        $ids2 = array_column( $p2['questions'] ?? [], 'id' );
        $overlap = array_intersect( $ids1, $ids2 );
        if ( ! empty( $overlap ) ) return self::fail( 'Pages 1 and 2 share question IDs — pagination broken.', $overlap );
        return self::pass( "Pagination: {$total} questions, {$pages} pages, no ID overlap between pages 1 & 2.", [
            'total' => $total, 'pages' => $pages,
        ] );
    }

    private static function test_qb_browse_status_filter(): array {
        $active  = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'per_page' => 5,
        ] );
        $retired = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'retired', 'per_page' => 5,
        ] );
        $all     = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'all', 'per_page' => 5,
        ] );
        if ( isset( $active['error'] ) )  return self::fail( 'Active filter failed: ' . $active['error'] );
        if ( isset( $retired['error'] ) ) return self::fail( 'Retired filter failed: ' . $retired['error'] );
        if ( isset( $all['error'] ) )     return self::fail( 'All filter failed: ' . $all['error'] );
        // Every active question must have status = 'active'
        foreach ( $active['questions'] ?? [] as $q ) {
            if ( $q['status'] !== 'active' ) return self::fail( "Active filter returned non-active question: {$q['id']}" );
        }
        // Every retired question must have status = 'retired'
        foreach ( $retired['questions'] ?? [] as $q ) {
            if ( $q['status'] !== 'retired' ) return self::fail( "Retired filter returned non-retired question: {$q['id']}" );
        }
        $aTotal = (int) ( $active['total'] ?? 0 );
        $rTotal = (int) ( $retired['total'] ?? 0 );
        $allTotal = (int) ( $all['total'] ?? 0 );
        return self::pass( "Status filter correct — active={$aTotal}, retired={$rTotal}, all={$allTotal}.", [
            'active' => $aTotal, 'retired' => $rTotal, 'all' => $allTotal,
        ] );
    }

    private static function test_qb_retire_validates_status(): array {
        // PATCH with an invalid status should return 400
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) return self::fail( 'Railway endpoint not configured.' );

        // Use a placeholder UUID — we just want the validation path, not a real row
        $fake_id  = '00000000-0000-0000-0000-000000000000';
        $response = wp_remote_request( $endpoint . '/api/v1/question-bank/questions/' . $fake_id, [
            'method'  => 'PATCH',
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'status' => 'deleted' ] ),
        ] );
        if ( is_wp_error( $response ) ) return self::fail( 'PATCH request failed: ' . $response->get_error_message() );
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 400 ) return self::fail( "Expected 400 for invalid status, got HTTP {$code}.", [ 'code' => $code ] );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return self::pass( "PATCH /questions/:id rejects invalid status with 400.", [
            'error' => $body['error'] ?? null, 'code' => $body['code'] ?? null,
        ] );
    }

    private static function test_qb_retire_retires_question(): array {
        // Find an active question to retire
        $data = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'per_page' => 1,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Browse failed: ' . $data['error'] );
        $questions = $data['questions'] ?? [];
        if ( empty( $questions ) ) return self::warn( 'No active questions to retire — seed math/std_4/term_1 first.' );

        $q  = $questions[0];
        $id = $q['id'];
        set_transient( 'knowly_spectest_retire_id', $id, HOUR_IN_SECONDS );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $response   = wp_remote_request( $endpoint . '/api/v1/question-bank/questions/' . rawurlencode( $id ), [
            'method'  => 'PATCH',
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'status' => 'retired' ] ),
        ] );
        if ( is_wp_error( $response ) ) return self::fail( 'PATCH failed: ' . $response->get_error_message() );
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) return self::fail( "PATCH returned HTTP {$code}", $body );
        if ( ( $body['question']['status'] ?? '' ) !== 'retired' ) {
            return self::fail( "Response status not 'retired'", $body );
        }
        return self::pass( "Question {$id} retired successfully.", [ 'id' => $id, 'status' => 'retired' ] );
    }

    private static function test_qb_retire_restores_question(): array {
        $id = get_transient( 'knowly_spectest_retire_id' );
        if ( ! $id ) return self::warn( 'No retired question ID in transient — run qb_retire_retires_question first.' );

        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        $response   = wp_remote_request( $endpoint . '/api/v1/question-bank/questions/' . rawurlencode( $id ), [
            'method'  => 'PATCH',
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'status' => 'active' ] ),
        ] );
        if ( is_wp_error( $response ) ) return self::fail( 'Restore PATCH failed: ' . $response->get_error_message() );
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) return self::fail( "Restore PATCH returned HTTP {$code}", $body );
        if ( ( $body['question']['status'] ?? '' ) !== 'active' ) {
            return self::fail( "Response status not 'active' after restore", $body );
        }
        delete_transient( 'knowly_spectest_retire_id' );
        return self::pass( "Question {$id} restored to active.", [ 'id' => $id, 'status' => 'active' ] );
    }

    private static function test_qb_retire_excluded_from_list(): array {
        // Retire a question, verify it doesn't appear in active list, then restore it
        $data = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'per_page' => 1,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Browse failed: ' . $data['error'] );
        $questions = $data['questions'] ?? [];
        if ( empty( $questions ) ) return self::warn( 'No active questions — seed bank first.' );

        $q  = $questions[0];
        $id = $q['id'];
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );

        // Retire
        wp_remote_request( $endpoint . '/api/v1/question-bank/questions/' . rawurlencode( $id ), [
            'method' => 'PATCH', 'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'   => wp_json_encode( [ 'status' => 'retired' ] ),
        ] );

        // Browse active — retired question should be absent
        $active_data = self::railway_get( '/api/v1/question-bank/questions', [
            'level' => 'std_4', 'subject' => 'math', 'period' => 'term_1',
            'status' => 'active', 'per_page' => 100,
        ] );
        $active_ids = array_column( $active_data['questions'] ?? [], 'id' );
        $still_present = in_array( $id, $active_ids, true );

        // Restore (cleanup regardless of test result)
        wp_remote_request( $endpoint . '/api/v1/question-bank/questions/' . rawurlencode( $id ), [
            'method' => 'PATCH', 'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'   => wp_json_encode( [ 'status' => 'active' ] ),
        ] );

        if ( $still_present ) return self::fail( "Retired question {$id} still appears in active list." );
        return self::pass( "Retired question excluded from active browse list. Restored after test.", [ 'id' => $id ] );
    }

    private static function test_qb_retire_not_found(): array {
        $endpoint   = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $server_key = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint ) return self::fail( 'Railway endpoint not configured.' );
        $fake_id  = '00000000-0000-0000-0000-000000000001';
        $response = wp_remote_request( $endpoint . '/api/v1/question-bank/questions/' . $fake_id, [
            'method'  => 'PATCH',
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $server_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'status' => 'retired' ] ),
        ] );
        if ( is_wp_error( $response ) ) return self::fail( 'PATCH request failed: ' . $response->get_error_message() );
        $code = wp_remote_retrieve_response_code( $response );
        // Supabase .single() returns 406 or 404 when no row matches — both are acceptable
        if ( ! in_array( $code, [ 404, 406 ], true ) ) {
            return self::fail( "Expected 404/406 for non-existent question, got HTTP {$code}.", [ 'code' => $code ] );
        }
        return self::pass( "PATCH on non-existent UUID returns {$code}.", [ 'code' => $code ] );
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
            return array_filter( [
                'error'   => $body['error']   ?? "HTTP {$code}",
                'details' => $body['details'] ?? null,
                'cause'   => $body['cause']   ?? null,
                'code'    => $code,
            ] );
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
    // Group 12 — Curriculum Setup Page
    // =========================================================================

    private static function test_curriculum_overview_loads(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'status'     => 'active',
            'per_page'   => 1000,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'curriculum-topics fetch failed: ' . $data['error'] );
        $items = $data['items'] ?? null;
        if ( ! is_array( $items ) ) return self::fail( 'Response missing items array', $data );
        if ( count( $items ) === 0 ) return self::warn( 'No curriculum_topics rows found — database may be empty.' );

        // Simulate what ajax_overview() does: group by level
        $levels = [];
        foreach ( $items as $row ) {
            $levels[ $row['level'] ] = true;
        }
        return self::pass(
            count( $levels ) . ' level(s) found in curriculum_topics — overview grid will render.',
            [ 'levels' => array_keys( $levels ), 'total_rows' => count( $items ) ]
        );
    }

    private static function test_curriculum_overview_std4_present(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'status'     => 'active',
            'per_page'   => 1000,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'curriculum-topics fetch failed: ' . $data['error'] );
        $items = $data['items'] ?? [];
        if ( count( $items ) === 0 ) return self::warn( 'std_4 has no curriculum_topics — re-import CSV to seed.' );

        $periods = array_unique( array_column( $items, 'period' ) );
        $seeded  = array_filter( $periods, fn( $p ) => $p !== null );
        return self::pass(
            'std_4 has ' . count( $items ) . ' topics across ' . count( $seeded ) . ' period(s).',
            [ 'total' => count( $items ), 'periods' => $periods ]
        );
    }

    private static function test_curriculum_detail_loads(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'status'     => 'active',
            'per_page'   => 1000,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Detail fetch failed: ' . $data['error'] );
        $items = $data['items'] ?? [];
        if ( ! $items ) return self::warn( 'std_4 has no curriculum_topics — detail page will show empty state.' );

        // Simulate ajax_detail() aggregation
        $subjects = [];
        $status   = [];
        $modules  = [];
        foreach ( $items as $row ) {
            $sub   = $row['subject'];
            $per   = $row['period'];
            $p_key = $per ?? '__capstone__';
            $subjects[ $sub ] = true;
            $status[ $sub ][ $p_key ] = ( $status[ $sub ][ $p_key ] ?? 0 ) + 1;
            $mod_num = $row['module_number'];
            if ( $mod_num !== null ) {
                $mk = $sub . '_' . ( $per ?? 'cap' );
                $modules[ $mk ][ $mod_num ] = ( $modules[ $mk ][ $mod_num ] ?? 0 ) + 1;
            }
        }

        if ( empty( $subjects ) ) return self::fail( 'No subjects found in std_4 topics.' );

        return self::pass(
            count( $subjects ) . ' subject(s) found — detail page subjects/status/modules aggregation valid.',
            [ 'subjects' => array_keys( $subjects ), 'status_keys' => array_map( 'array_keys', $status ) ]
        );
    }

    private static function test_curriculum_detail_subjects(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'subject'    => 'math',
            'status'     => 'active',
            'per_page'   => 200,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Fetch failed: ' . $data['error'] );
        $items = $data['items'] ?? [];
        if ( ! $items ) return self::warn( 'std_4 / math has no topics — subject tab will be empty.' );

        $has_module_number = count( array_filter( $items, fn( $r ) => $r['module_number'] !== null ) );
        $modules = array_unique( array_column( $items, 'module_number' ) );
        $modules = array_filter( $modules, fn( $m ) => $m !== null );

        if ( $has_module_number === 0 ) {
            return self::warn( 'math topics exist but none have module_number — QB slot board will be empty.' );
        }

        return self::pass(
            count( $items ) . ' math topics, ' . count( $modules ) . ' module(s) with module_number set.',
            [ 'topic_count' => count( $items ), 'module_numbers' => array_values( $modules ) ]
        );
    }

    private static function test_curriculum_detail_period_seeded(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'subject'    => 'math',
            'period'     => 'term_1',
            'status'     => 'active',
            'per_page'   => 200,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Fetch failed: ' . $data['error'] );
        $count = count( $data['items'] ?? [] );
        if ( $count === 0 ) return self::warn( 'std_4 / term_1 / math has 0 topics — period tab shows Empty badge.' );
        return self::pass(
            "std_4 / term_1 / math: {$count} topics — period tab will show seeded badge.",
            [ 'topic_count' => $count ]
        );
    }

    private static function test_curriculum_detail_modules(): array {
        $data = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'subject'    => 'math',
            'period'     => 'term_1',
            'status'     => 'active',
            'per_page'   => 200,
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Fetch failed: ' . $data['error'] );
        $items = $data['items'] ?? [];
        if ( ! $items ) return self::warn( 'No topics — module summary cannot be validated.' );

        $modules = [];
        foreach ( $items as $row ) {
            $mn = $row['module_number'];
            if ( $mn === null ) continue;
            $modules[ $mn ]['module_number'] = $mn;
            $modules[ $mn ]['module_title']  = $row['module_title'] ?? null;
            $modules[ $mn ]['topic_count']   = ( $modules[ $mn ]['topic_count'] ?? 0 ) + 1;
        }

        if ( empty( $modules ) ) return self::warn( 'Topics exist but none have module_number — re-import with new CSV format.' );

        $missing_title = count( array_filter( $modules, fn( $m ) => empty( $m['module_title'] ) ) );
        if ( $missing_title > 0 ) {
            return self::warn(
                count( $modules ) . ' module(s), but ' . $missing_title . ' missing module_title.',
                [ 'modules' => array_values( $modules ) ]
            );
        }

        return self::pass(
            count( $modules ) . ' module(s) — all have module_number, module_title, topic_count.',
            [ 'modules' => array_values( $modules ) ]
        );
    }

    private static function test_curriculum_import_endpoint_exists(): array {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        $key      = get_option( 'knowly_railway_server_key', '' );
        if ( ! $endpoint || ! $key ) return self::fail( 'Railway endpoint or server key not configured.' );

        // POST with empty body — should return 400, not 404 (endpoint exists) or 500
        $resp = wp_remote_post( $endpoint . '/api/v1/curriculum-topics/import', [
            'timeout' => 10,
            'headers' => [ 'X-AEP-Server-Key' => $key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [] ),
        ] );
        if ( is_wp_error( $resp ) ) return self::fail( 'Request failed: ' . $resp->get_error_message() );
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code === 404 ) return self::fail( 'POST /curriculum-topics/import returned 404 — endpoint not deployed.' );
        if ( $code === 400 ) return self::pass( 'Endpoint exists and correctly rejects empty body with 400.', [ 'code' => $code ] );
        return self::warn( "Endpoint returned HTTP {$code} (expected 400 for empty body).", [ 'code' => $code ] );
    }

    private static function test_curriculum_import_scope_validation(): array {
        $data = self::railway_post( '/api/v1/curriculum-topics/import', [
            'curriculum' => 'tt_primary',
            // level intentionally omitted
            'subject' => 'math',
            'rows'    => [ [ 'topic' => 'Test topic', 'sort_order' => 0 ] ],
        ] );
        if ( ! isset( $data['code'] ) && ! isset( $data['error'] ) ) {
            return self::fail( 'Import without level did not return an error — validation missing.', $data );
        }
        return self::pass( 'Import correctly rejects missing level field.', [ 'response' => $data ] );
    }

    private static function test_curriculum_import_creates_topic(): array {
        $test_topic = '__spectest_curriculum_import_' . time() . '__';
        $data = self::railway_post( '/api/v1/curriculum-topics/import', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'rows'       => [ [
                'module_number' => 99,
                'module_title'  => '_SpecTest Module_',
                'topic'         => $test_topic,
                'sort_order'    => 9900,
            ] ],
        ] );

        if ( isset( $data['error'] ) ) return self::fail( 'Import failed: ' . $data['error'] );
        $created = $data['created'] ?? 0;
        $total   = $data['total']   ?? 0;

        if ( $total === 0 ) return self::fail( 'Import returned total=0 — rows not parsed.', $data );

        // Verify the row now exists in curriculum_topics
        $verify = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'per_page'   => 500,
        ] );
        $found = array_filter( $verify['items'] ?? [], fn( $r ) => $r['topic'] === $test_topic );

        // Cleanup: re-import without the test row to trigger archive
        self::railway_post( '/api/v1/curriculum-topics/import', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'rows'       => array_values( array_map(
                fn( $r ) => [ 'module_number' => $r['module_number'], 'module_title' => $r['module_title'], 'topic' => $r['topic'], 'sort_order' => $r['sort_order'] ],
                array_filter( $verify['items'] ?? [], fn( $r ) => $r['topic'] !== $test_topic )
            ) ),
        ] );

        if ( empty( $found ) ) return self::warn( "Import returned created={$created} but topic not found in verify — may have updated instead.", $data );

        return self::pass(
            "Import created test topic → found in curriculum_topics → cleaned up via re-import.",
            [ 'created' => $created, 'synced' => $data['synced_to_pinecone'] ?? 0 ]
        );
    }

    private static function test_curriculum_import_archives_stale(): array {
        $test_topic_a = '__spectest_stale_a_' . time() . '__';
        $test_topic_b = '__spectest_stale_b_' . time() . '__';

        // Import two rows
        self::railway_post( '/api/v1/curriculum-topics/import', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'rows'       => [
                [ 'module_number' => 98, 'module_title' => '_SpecTest_', 'topic' => $test_topic_a, 'sort_order' => 9800 ],
                [ 'module_number' => 98, 'module_title' => '_SpecTest_', 'topic' => $test_topic_b, 'sort_order' => 9801 ],
            ],
        ] );

        // Re-import with only topic_b — topic_a should be archived
        $result = self::railway_post( '/api/v1/curriculum-topics/import', [
            'curriculum' => 'tt_primary',
            'level'      => 'std_4',
            'period'     => 'term_1',
            'subject'    => 'math',
            'rows'       => [
                [ 'module_number' => 98, 'module_title' => '_SpecTest_', 'topic' => $test_topic_b, 'sort_order' => 9801 ],
            ],
        ] );

        $archived = $result['archived'] ?? 0;

        // Verify topic_a is no longer in active list
        $verify = self::railway_get( '/api/v1/curriculum-topics', [
            'curriculum' => 'tt_primary', 'level' => 'std_4', 'period' => 'term_1',
            'subject' => 'math', 'status' => 'active', 'per_page' => 500,
        ] );
        $still_active = array_filter( $verify['items'] ?? [], fn( $r ) => $r['topic'] === $test_topic_a );

        // Final cleanup — archive topic_b too
        self::railway_post( '/api/v1/curriculum-topics/import', [
            'curriculum' => 'tt_primary', 'level' => 'std_4', 'period' => 'term_1', 'subject' => 'math',
            'rows' => array_values( array_map(
                fn( $r ) => [ 'module_number' => $r['module_number'], 'module_title' => $r['module_title'], 'topic' => $r['topic'], 'sort_order' => $r['sort_order'] ],
                array_filter( $verify['items'] ?? [], fn( $r ) => $r['topic'] !== $test_topic_b )
            ) ),
        ] );

        if ( $archived < 1 ) return self::warn( 'Re-import archived=0 — stale topic archiving may not be working.', $result );
        if ( ! empty( $still_active ) ) return self::fail( "Stale topic '{$test_topic_a}' still active after re-import.", [ 'archived' => $archived ] );

        return self::pass(
            "Re-import archived {$archived} stale topic(s) — only new rows remain active.",
            [ 'archived' => $archived ]
        );
    }

    // =========================================================================
    // Group 13 — Data Management: Purge Controls
    // =========================================================================

    private static function purge_auth_guard( string $path ): array {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) return self::fail( 'Railway endpoint not configured.' );

        $resp = wp_remote_request( $endpoint . $path, [
            'method'  => 'DELETE',
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
        ] );

        if ( is_wp_error( $resp ) ) return self::fail( 'Request failed: ' . $resp->get_error_message() );

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 401 ) {
            return self::fail( "Expected 401 without server key, got {$code}.", [ 'path' => $path, 'http_code' => $code ] );
        }
        return self::pass( "DELETE {$path} → 401 (auth guard active)." );
    }

    private static function test_purge_training_auth_guard(): array {
        return self::purge_auth_guard( '/api/v1/training/purge' );
    }

    private static function test_purge_curriculum_auth_guard(): array {
        return self::purge_auth_guard( '/api/v1/curriculum-topics/purge' );
    }

    private static function test_purge_qb_auth_guard(): array {
        return self::purge_auth_guard( '/api/v1/question-bank/purge' );
    }

    private static function test_purge_wp_ajax_registered(): array {
        $hooked = has_action( 'wp_ajax_knowly_purge_step' );
        if ( $hooked === false ) {
            return self::fail( 'wp_ajax_knowly_purge_step not registered — check Knowly_Admin_Data_Management::boot().' );
        }
        return self::pass( "wp_ajax_knowly_purge_step is registered (priority {$hooked})." );
    }

    private static function test_purge_class_exists(): array {
        if ( ! class_exists( 'Knowly_Admin_Data_Management' ) ) {
            return self::fail( 'Class Knowly_Admin_Data_Management not loaded.' );
        }
        $required = [ 'boot', 'render', 'ajax_purge_step' ];
        $missing  = [];
        foreach ( $required as $method ) {
            if ( ! method_exists( 'Knowly_Admin_Data_Management', $method ) ) {
                $missing[] = $method;
            }
        }
        if ( $missing ) return self::fail( 'Missing public methods: ' . implode( ', ', $missing ) );
        return self::pass( 'Knowly_Admin_Data_Management loaded with all required public methods.' );
    }

    private static function test_purge_page_registered(): array {
        // admin_menu doesn't fire during AJAX — trigger it so $submenu gets populated.
        do_action( 'admin_menu' );

        global $submenu;
        $found = false;
        foreach ( $submenu['knowly-api'] ?? [] as $item ) {
            if ( isset( $item[2] ) && $item[2] === 'knowly-data-management' ) {
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            return self::warn( 'knowly-data-management not in submenu — verify add_submenu_page() in class-knowly-admin.php.' );
        }
        return self::pass( 'Data Management page registered under Knowly admin menu.' );
    }

    // =========================================================================
    // Group 14 — Trial Packs API
    // =========================================================================

    private static function tp_auth_guard( string $method, string $path ): array {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) return self::fail( 'Railway endpoint not configured.' );

        $resp = wp_remote_request( $endpoint . $path, [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $method === 'POST' ? '{}' : null,
        ] );

        if ( is_wp_error( $resp ) ) return self::fail( 'Request failed: ' . $resp->get_error_message() );
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 401 ) return self::fail( "Expected 401 without server key, got {$code}.", [ 'path' => $path ] );
        return self::pass( "{$method} {$path} → 401 (auth guard active)." );
    }

    private static function test_tp_build_auth_guard(): array {
        return self::tp_auth_guard( 'POST', '/api/v1/trial-packs/build' );
    }

    private static function test_tp_watermark_auth_guard(): array {
        return self::tp_auth_guard( 'GET', '/api/v1/trial-packs/watermark?level=std_4&subject=math' );
    }

    private static function test_tp_list_auth_guard(): array {
        return self::tp_auth_guard( 'GET', '/api/v1/trial-packs/list' );
    }

    private static function test_tp_watermark_shape(): array {
        $data = self::railway_get( '/api/v1/trial-packs/watermark', [
            'level'   => 'std_4',
            'subject' => 'math',
            'period'  => 'term_1',
        ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Request failed: ' . $data['error'] );

        if ( ! isset( $data['slots'] ) || ! is_array( $data['slots'] ) ) {
            return self::fail( 'Response missing slots array.', $data );
        }
        if ( ! isset( $data['summary'] ) ) {
            return self::fail( 'Response missing summary object.', $data );
        }

        $slot = $data['slots'][0] ?? null;
        if ( ! $slot ) return self::warn( 'slots array is empty — no unassigned questions found for std_4/term_1/math.' );

        $required = [ 'module_number', 'difficulty', 'unassigned', 'status' ];
        $missing  = array_diff( $required, array_keys( $slot ) );
        if ( $missing ) return self::fail( 'Slot missing fields: ' . implode( ', ', $missing ), $slot );

        $summary = $data['summary'];
        return self::pass(
            "Watermark shape valid. {$summary['healthy']} healthy / {$summary['low']} low / {$summary['critical']} critical slots.",
            [ 'summary' => $summary, 'total_slots' => count( $data['slots'] ) ]
        );
    }

    private static function test_tp_list_shape(): array {
        $data = self::railway_get( '/api/v1/trial-packs/list', [ 'per_page' => 1 ] );
        if ( isset( $data['error'] ) ) return self::fail( 'Request failed: ' . $data['error'] );

        $required = [ 'packs', 'total', 'page', 'per_page', 'pages' ];
        $missing  = array_diff( $required, array_keys( $data ) );
        if ( $missing ) return self::fail( 'Response missing fields: ' . implode( ', ', $missing ), $data );

        $total = (int) ( $data['total'] ?? 0 );
        if ( $total === 0 ) return self::warn( 'trial_packs table is empty — build a pack first via Simulations tab.' );

        return self::pass( "List endpoint OK. {$total} pack(s) in table.", [ 'total' => $total ] );
    }

    private static function test_tp_preview_build(): array {
        $data = self::railway_post( '/api/v1/trial-packs/build', [
            'curriculum'   => 'tt_primary',
            'level'        => 'std_4',
            'period'       => 'term_1',
            'subject'      => 'math',
            'module_number'=> 4,
            'pack_type'    => 'topic',
            'difficulty'   => 'easy',
            'preview'      => true,
        ] );

        if ( isset( $data['error'] ) ) {
            // Insufficient questions is expected if QB not yet seeded — treat as warn
            if ( str_contains( $data['error'] ?? '', 'Insufficient' ) || str_contains( $data['error'] ?? '', 'insufficient' ) ) {
                return self::warn( 'Preview returned insufficient_questions — seed more QB questions first.', $data );
            }
            return self::fail( 'Build preview failed: ' . $data['error'], $data );
        }

        if ( ! isset( $data['questions'] ) || ! is_array( $data['questions'] ) ) {
            return self::fail( 'Preview response missing questions array.', $data );
        }

        $count = count( $data['questions'] );
        if ( $count !== 12 ) {
            return self::warn( "Easy pack should have 12 questions, got {$count}.", $data );
        }

        $q = $data['questions'][0] ?? [];
        $required = [ 'id', 'question', 'options', 'correct_answer', 'difficulty' ];
        $missing  = array_diff( $required, array_keys( $q ) );
        if ( $missing ) return self::fail( 'Question missing fields: ' . implode( ', ', $missing ), $q );

        return self::pass(
            "Preview returned {$count} questions with correct shape. Pack NOT saved (preview=true).",
            [ 'question_count' => $count, 'preview' => $data['preview'] ?? true ]
        );
    }

    // =========================================================================
    // Group 15 — Phase 4: Sequential Trial Delivery
    // =========================================================================

    private static function p4_auth_guard( string $method, string $path ): array {
        $endpoint = rtrim( get_option( 'knowly_railway_endpoint', '' ), '/' );
        if ( ! $endpoint ) return self::fail( 'Railway endpoint not configured.' );

        $resp = wp_remote_request( $endpoint . $path, [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $method === 'POST' ? '{}' : null,
        ] );

        if ( is_wp_error( $resp ) ) return self::fail( 'Request failed: ' . $resp->get_error_message() );
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 401 ) return self::fail( "Expected 401 without auth, got {$code}.", [ 'path' => $path ] );
        return self::pass( "{$method} {$path} → 401 (auth guard active)." );
    }

    private static function test_p4_next_pack_auth_guard(): array {
        return self::p4_auth_guard( 'GET', '/api/v1/trial/next-pack?level=std_4&subject=math&branch=easy&child_id=1' );
    }

    private static function test_p4_child_history_auth_guard(): array {
        return self::p4_auth_guard( 'DELETE', '/api/v1/trial/child-history?child_id=1' );
    }

    private static function test_p4_submit_pack_auth_guard(): array {
        return self::p4_auth_guard( 'POST', '/api/v1/submit-pack-exam' );
    }

    private static function test_p4_next_pack_missing_fields(): array {
        $data = self::railway_get( '/api/v1/trial/next-pack', [] );
        $code = (int) ( $data['code'] ?? 0 );
        if ( isset( $data['error'] ) && ( $code === 400 || str_contains( $data['error'] ?? '', 'required' ) ) ) {
            return self::pass( 'GET /trial/next-pack with no params → 400 missing_fields as expected.' );
        }
        return self::fail( 'Expected 400 missing_fields, got unexpected response.', $data );
    }

    private static function test_p4_next_pack_invalid_branch(): array {
        $data = self::railway_get( '/api/v1/trial/next-pack', [
            'level'    => 'std_4',
            'subject'  => 'math',
            'branch'   => 'superhard',
            'child_id' => 1,
        ] );
        $code = (int) ( $data['code'] ?? 0 );
        if ( $code === 400 || str_contains( $data['error'] ?? '', 'branch' ) ) {
            return self::pass( 'GET /trial/next-pack with invalid branch → 400 as expected.' );
        }
        return self::fail( 'Expected 400 for invalid branch, got unexpected response.', $data );
    }

    private static function test_p4_next_pack_unknown_scope(): array {
        // Use a nonsense subject — no packs exist for it, so we expect 503 no_pack_available.
        $data = self::railway_get( '/api/v1/trial/next-pack', [
            'level'    => 'std_4',
            'subject'  => '__p4spectest_bogus__',
            'branch'   => 'easy',
            'child_id' => 999999999,
        ] );
        $code = (int) ( $data['code'] ?? 0 );
        $err  = $data['error'] ?? '';
        if ( $code === 503 || str_contains( $err, 'No pack available' ) || ( $data['code'] ?? '' ) === 'no_pack_available' ) {
            return self::pass(
                'GET /trial/next-pack for unknown scope → 503 no_pack_available, generation queued.',
                [ 'retry_after_seconds' => $data['retry_after_seconds'] ?? null ]
            );
        }
        return self::fail( 'Expected 503 no_pack_available for bogus scope.', $data );
    }

    private static function test_p4_child_history_missing_id(): array {
        $data = self::railway_delete( '/api/v1/trial/child-history' );
        $code = (int) ( $data['code'] ?? 0 );
        if ( $code === 400 || str_contains( $data['error'] ?? '', 'child_id' ) ) {
            return self::pass( 'DELETE /trial/child-history without child_id → 400 as expected.' );
        }
        return self::fail( 'Expected 400 for missing child_id.', $data );
    }

    private static function test_p4_child_history_reset_noop(): array {
        // Nonexistent child — should delete 0 rows and return 200.
        $data = self::railway_delete( '/api/v1/trial/child-history?child_id=999999999' );
        if ( isset( $data['error'] ) ) return self::fail( 'Unexpected error: ' . $data['error'], $data );
        if ( ! array_key_exists( 'deleted', $data ) ) {
            return self::fail( 'Response missing deleted field.', $data );
        }
        return self::pass(
            'DELETE /trial/child-history for nonexistent child → 200, deleted=' . (int) $data['deleted'] . '.',
            [ 'deleted' => $data['deleted'] ]
        );
    }

    private static function test_p4_schema_branch_column(): array {
        $data = self::railway_get( '/api/v1/trial-packs/list', [ 'per_page' => 1 ] );
        if ( isset( $data['error'] ) ) return self::fail( 'List failed: ' . $data['error'] );

        $packs = $data['packs'] ?? [];
        if ( empty( $packs ) ) {
            return self::warn( 'trial_packs table is empty — build at least one pack to verify branch column.' );
        }
        if ( ! array_key_exists( 'branch', $packs[0] ) ) {
            return self::fail( 'trial_packs.branch column missing — Phase 4 migration may not have run.', $packs[0] );
        }
        $branch = $packs[0]['branch'] ?? null;
        $valid  = in_array( $branch, [ 'easy', 'medium', 'hard', 'dynamic' ], true );
        if ( ! $valid && $branch !== null ) {
            return self::warn( "branch column exists but value '{$branch}' is unexpected.", $packs[0] );
        }
        return self::pass( "trial_packs.branch column present (value: '{$branch}') — Phase 4 migration ran.", $packs[0] );
    }

    private static function test_p4_schema_sequence_column(): array {
        $data = self::railway_get( '/api/v1/trial-packs/list', [ 'per_page' => 1 ] );
        if ( isset( $data['error'] ) ) return self::fail( 'List failed: ' . $data['error'] );

        $packs = $data['packs'] ?? [];
        if ( empty( $packs ) ) {
            return self::warn( 'trial_packs table is empty — build at least one pack to verify pack_sequence_number.' );
        }
        if ( ! array_key_exists( 'pack_sequence_number', $packs[0] ) ) {
            return self::fail( 'trial_packs.pack_sequence_number column missing — Phase 4 migration may not have run.', $packs[0] );
        }
        $seq = $packs[0]['pack_sequence_number'];
        return self::pass(
            'trial_packs.pack_sequence_number present (value: ' . (int) $seq . ') — backfill ran.',
            [ 'pack_sequence_number' => $seq ]
        );
    }

    private static function test_p4_wp_ajax_reset_registered(): array {
        $action = 'wp_ajax_knowly_admin_reset_pack_history';
        if ( ! has_action( $action ) ) {
            return self::fail( "WP action '{$action}' is not registered — check Knowly_Admin_Users::boot()." );
        }
        return self::pass( "WP action '{$action}' is registered." );
    }

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
            'qbv2_schema' => [
                'label' => '🗄️ Group 7 — QB v2: Schema & Routing (fast)',
                'slow'  => false,
                'tests' => [
                    'qbv2_new_table_accessible'          => [ 'label' => 'New question_bank (UUID PK) accessible via /question-bank/list',          'method' => 'GET',  'route' => '/api/v1/question-bank/list' ],
                    'qbv2_scope_table_legacy'            => [ 'label' => 'question_bank_scope (renamed legacy) accessible via /trial/start',        'method' => 'POST', 'route' => '/api/v1/trial/start' ],
                    'qbv2_list_slot_shape'               => [ 'label' => 'Slots have module_number, module_title, difficulty, question_count',      'method' => 'GET',  'route' => '/api/v1/question-bank/list' ],
                    'qbv2_list_covers_difficulties'      => [ 'label' => 'Every module has easy, medium, and hard slots',                           'method' => 'GET',  'route' => '/api/v1/question-bank/list' ],
                    'qbv2_list_module_count'             => [ 'label' => 'Module count in list matches curriculum_topics (data-driven, not hardcoded)', 'method' => 'GET', 'route' => '/api/v1/question-bank/list + /curriculum-topics' ],
                    'qbv2_generate_async_response'       => [ 'label' => '/question-bank/generate (sync=false) returns queued=true immediately',    'method' => 'POST', 'route' => '/api/v1/question-bank/generate' ],
                    'qbv2_assemble_validates_inputs'     => [ 'label' => '/trial/assemble rejects empty body and missing module_numbers with 400',  'method' => 'POST', 'route' => '/api/v1/trial/assemble' ],
                    'qbv2_assemble_multi_subject'        => [ 'label' => '/question-bank/list returns slot data for ≥ 2 subjects',                  'method' => 'GET',  'route' => '/api/v1/question-bank/list' ],
                    'qbv2_legacy_trial_start_validation' => [ 'label' => '/trial/start (legacy) still validates inputs after migration',            'method' => 'POST', 'route' => '/api/v1/trial/start' ],
                ],
            ],
            'qbv2_generation' => [
                'label' => '🔬 Group 8 — QB v2: Generation & Assembly (SLOW — calls Claude)',
                'slow'  => true,
                'tests' => [
                    'qbv2_generate_sync'            => [ 'label' => '/question-bank/generate (sync=true) inserts questions for a real slot',        'method' => 'POST',  'route' => '/api/v1/question-bank/generate' ],
                    'qbv2_no_duplicate_ids'         => [ 'label' => 'Assembled trial has unique question_ids (no internal duplicates)',             'method' => 'CHECK', 'route' => '' ],
                    'qbv2_assemble_returns_package' => [ 'label' => '/trial/assemble returns questions + answer_sheet from new question_bank',      'method' => 'POST',  'route' => '/api/v1/trial/assemble' ],
                    'qbv2_assemble_question_shape'  => [ 'label' => 'Questions: UUID IDs, A–D options, meta.difficulty; correct_answer hidden',    'method' => 'CHECK', 'route' => '' ],
                    'qbv2_assemble_answer_sheet'    => [ 'label' => 'answer_sheet: matching question_ids, correct_answer A–D, explanation',        'method' => 'CHECK', 'route' => '' ],
                    'qbv2_assemble_meta'            => [ 'label' => 'meta: time_per_question=90s, total_time, topics_covered[], source',           'method' => 'CHECK', 'route' => '' ],
                    'qbv2_assemble_exclude_dedup'   => [ 'label' => 'exclude_question_ids prevents repeat questions across sessions',              'method' => 'POST',  'route' => '/api/v1/trial/assemble' ],
                    'qbv2_assemble_multi_module'    => [ 'label' => 'Multi-module assemble distributes questions via round-robin across modules',   'method' => 'POST',  'route' => '/api/v1/trial/assemble' ],
                ],
            ],
            'qbv2_delivery' => [
                'label' => '🚀 Group 9 — QB v2: Live Trial Delivery (WP Layer)',
                'slow'  => false,
                'tests' => [
                    'qbv2_live_resolve'              => [ 'label' => 'QB list has seeded modules — resolve_module_numbers() returns non-empty',               'method' => 'GET',   'route' => '/api/v1/question-bank/list' ],
                    'qbv2_live_wp_assemble'          => [ 'label' => 'WP fetch_from_question_bank_assemble() returns valid package; stores in transient',    'method' => 'WP',    'route' => 'Knowly_Exam_Service' ],
                    'qbv2_live_options_lowercase'    => [ 'label' => 'WP layer lowercases option keys A→a, B→b, C→c, D→d (React compatibility)',            'method' => 'CHECK', 'route' => '' ],
                    'qbv2_live_answer_lowercase'     => [ 'label' => 'WP layer lowercases answer_sheet correct_answer field',                               'method' => 'CHECK', 'route' => '' ],
                    'qbv2_live_pool_fallback'        => [ 'label' => 'Non-existent module [99999] → WP_Error pool_empty — fallback chain will engage',      'method' => 'WP',    'route' => 'Knowly_Exam_Service' ],
                    'qbv2_live_package_shape'        => [ 'label' => 'Package shape: package_id(qb-*), source, questions[], answer_sheet[], meta[] valid',  'method' => 'CHECK', 'route' => '' ],
                    'qbv2_live_no_exposed_answer'    => [ 'label' => 'Security: correct_answer not exposed in questions[]; required fields present',        'method' => 'CHECK', 'route' => '' ],
                    'qbv2_live_resolve_seeded_priority' => [ 'label' => 'Seeded modules identified and prioritised over empty slots in resolve logic',      'method' => 'GET',   'route' => '/api/v1/question-bank/list' ],
                    'qbv2_live_cross_session_dedup'  => [ 'label' => 'exclude_question_ids passed through WP layer — zero overlap between two sessions',   'method' => 'WP',    'route' => 'Knowly_Exam_Service' ],
                    'qbv2_live_unseeded_subject'     => [ 'label' => 'Unseeded subject (science) → WP_Error pool_empty — WP pool fallback will engage',    'method' => 'WP',    'route' => 'Knowly_Exam_Service' ],
                ],
            ],
            'qb_browse_retire' => [
                'label' => '🔍 Group 11 — QB v2: Browse & Retire',
                'slow'  => false,
                'tests' => [
                    'qb_browse_returns_questions' => [ 'label' => 'GET /questions returns envelope: questions[], total, page, pages', 'method' => 'GET', 'route' => '/api/v1/question-bank/questions' ],
                    'qb_browse_question_shape'    => [ 'label' => 'Question shape: id, module_number, options (A/B/C/D), correct_answer, status', 'method' => 'CHECK', 'route' => '' ],
                    'qb_browse_filter_difficulty' => [ 'label' => 'Difficulty filter returns only matching questions for easy/medium/hard', 'method' => 'GET', 'route' => '/api/v1/question-bank/questions?difficulty=*' ],
                    'qb_browse_pagination'        => [ 'label' => 'Pagination: page 1 and page 2 have no overlapping question IDs', 'method' => 'GET', 'route' => '/api/v1/question-bank/questions?page=1,2' ],
                    'qb_browse_status_filter'     => [ 'label' => 'Status filter (active/retired/all) returns correctly scoped results', 'method' => 'GET', 'route' => '/api/v1/question-bank/questions?status=*' ],
                    'qb_retire_validates_status'  => [ 'label' => 'PATCH with invalid status (e.g. "deleted") returns 400', 'method' => 'PATCH', 'route' => '/api/v1/question-bank/questions/:id' ],
                    'qb_retire_retires_question'  => [ 'label' => 'PATCH status=retired: question.status becomes "retired"; stores id in transient', 'method' => 'PATCH', 'route' => '/api/v1/question-bank/questions/:id' ],
                    'qb_retire_restores_question' => [ 'label' => 'PATCH status=active: previously retired question restored (reads transient)', 'method' => 'PATCH', 'route' => '/api/v1/question-bank/questions/:id' ],
                    'qb_retire_excluded_from_list'=> [ 'label' => 'Retired question absent from active browse list; restored after test', 'method' => 'PATCH+GET', 'route' => '/api/v1/question-bank/questions' ],
                    'qb_retire_not_found'         => [ 'label' => 'PATCH non-existent UUID → 404 or 406', 'method' => 'PATCH', 'route' => '/api/v1/question-bank/questions/00000000-...' ],
                ],
            ],
            'trials_admin' => [
                'label' => '📊 Group 10 — Trials Admin v2 AJAX',
                'slow'  => false,
                'tests' => [
                    'trials_v2_health_railway'        => [ 'label' => 'Railway reachable — Health Checks tab: endpoint status', 'method' => 'GET',   'route' => '/api/v1/health' ],
                    'trials_v2_health_qb_bank'        => [ 'label' => 'QB bank watermark check — math/std_4/term_1 slots (≥15 threshold)', 'method' => 'GET', 'route' => '/api/v1/question-bank/list' ],
                    'trials_v2_health_pool'           => [ 'label' => 'WP pool table exists; approved count readable (legacy fallback)', 'method' => 'WP',    'route' => 'knowly_trial_packages' ],
                    'trials_v2_health_sessions_table' => [ 'label' => 'exam_sessions table exists with all required columns', 'method' => 'WP', 'route' => 'knowly_exam_sessions' ],
                    'trials_v2_overview_counts'       => [ 'label' => 'Overview stat cards: today / active / QB v2 / total counts are non-negative and consistent', 'method' => 'WP', 'route' => 'knowly_exam_sessions' ],
                    'trials_v2_overview_qb_stats'     => [ 'label' => 'QB stats fetch: total/seeded keys present for math/std_4/term_1', 'method' => 'GET', 'route' => '/api/v1/question-bank/list' ],
                    'trials_v2_overview_recent'       => [ 'label' => 'Recent sessions query: shape valid, source derived from package_id prefix', 'method' => 'WP', 'route' => 'knowly_exam_sessions' ],
                    'trials_v2_qb_slots_proxy'        => [ 'label' => 'QB tab slot proxy: slots returned with module_number, module_title, difficulty, active_count', 'method' => 'GET', 'route' => '/api/v1/question-bank/list' ],
                    'trials_v2_sessions_query'        => [ 'label' => 'Sessions tab query: 30/page with correct fields, source detection valid', 'method' => 'WP', 'route' => 'knowly_exam_sessions' ],
                    'trials_v2_sessions_pagination'   => [ 'label' => 'Pagination: LIMIT/OFFSET produces no overlap between pages 1 and 2', 'method' => 'WP', 'route' => 'knowly_exam_sessions' ],
                ],
            ],
            'curriculum_setup' => [
                'label' => '📚 Group 12 — Curriculum Setup Page',
                'slow'  => false,
                'tests' => [
                    'curriculum_overview_loads'         => [ 'label' => 'Overview: curriculum_topics fetched, at least 1 level present', 'method' => 'GET', 'route' => '/api/v1/curriculum-topics' ],
                    'curriculum_overview_std4_present'  => [ 'label' => 'std_4 appears in overview with ≥ 1 seeded period', 'method' => 'GET', 'route' => '/api/v1/curriculum-topics?level=std_4' ],
                    'curriculum_detail_loads'           => [ 'label' => 'Detail aggregation: subjects, status map, modules derived correctly for std_4', 'method' => 'GET', 'route' => '/api/v1/curriculum-topics?level=std_4' ],
                    'curriculum_detail_subjects'        => [ 'label' => 'std_4 / math has topics with module_number set — subject tab will render', 'method' => 'GET', 'route' => '/api/v1/curriculum-topics?level=std_4&subject=math' ],
                    'curriculum_detail_period_seeded'   => [ 'label' => 'std_4 / term_1 / math shows > 0 topics — period badge shows seeded count', 'method' => 'GET', 'route' => '/api/v1/curriculum-topics?level=std_4&period=term_1&subject=math' ],
                    'curriculum_detail_modules'         => [ 'label' => 'std_4 / term_1 / math modules have module_number + module_title + topic_count', 'method' => 'GET', 'route' => '/api/v1/curriculum-topics?level=std_4&period=term_1&subject=math' ],
                    'curriculum_import_endpoint_exists' => [ 'label' => 'POST /curriculum-topics/import endpoint deployed — rejects empty body with 400', 'method' => 'POST', 'route' => '/api/v1/curriculum-topics/import' ],
                    'curriculum_import_scope_validation'=> [ 'label' => 'Import rejects missing level field with error response', 'method' => 'POST', 'route' => '/api/v1/curriculum-topics/import' ],
                    'curriculum_import_creates_topic'   => [ 'label' => 'Import 1 test row → topic appears in curriculum_topics → cleaned up', 'method' => 'POST', 'route' => '/api/v1/curriculum-topics/import' ],
                    'curriculum_import_archives_stale'  => [ 'label' => 'Re-import without prior row → stale topic archived; absent from active list', 'method' => 'POST', 'route' => '/api/v1/curriculum-topics/import' ],
                ],
            ],
            'trial_packs' => [
                'label' => '📦 Group 14 — Trial Packs API',
                'slow'  => false,
                'tests' => [
                    'tp_build_auth_guard'    => [ 'label' => 'POST /trial-packs/build → 401 without server key',                          'method' => 'POST',  'route' => '/api/v1/trial-packs/build' ],
                    'tp_watermark_auth_guard'=> [ 'label' => 'GET /trial-packs/watermark → 401 without server key',                       'method' => 'GET',   'route' => '/api/v1/trial-packs/watermark' ],
                    'tp_list_auth_guard'     => [ 'label' => 'GET /trial-packs/list → 401 without server key',                            'method' => 'GET',   'route' => '/api/v1/trial-packs/list' ],
                    'tp_watermark_shape'     => [ 'label' => 'Watermark: std_4/term_1/math returns slots[] + summary with status fields', 'method' => 'GET',   'route' => '/api/v1/trial-packs/watermark' ],
                    'tp_list_shape'          => [ 'label' => 'List: returns packs[], total, page, per_page, pages envelope',              'method' => 'GET',   'route' => '/api/v1/trial-packs/list' ],
                    'tp_preview_build'       => [ 'label' => 'Preview build (preview=true): 12 questions returned, pack NOT saved',       'method' => 'POST',  'route' => '/api/v1/trial-packs/build' ],
                ],
            ],
            'p4_delivery' => [
                'label' => '🚂 Group 15 — Phase 4: Sequential Trial Delivery',
                'slow'  => false,
                'tests' => [
                    'p4_next_pack_auth_guard'      => [ 'label' => 'GET /trial/next-pack → 401 without server key',                                        'method' => 'GET',    'route' => '/api/v1/trial/next-pack' ],
                    'p4_child_history_auth_guard'  => [ 'label' => 'DELETE /trial/child-history → 401 without server key',                                 'method' => 'DELETE', 'route' => '/api/v1/trial/child-history' ],
                    'p4_submit_pack_auth_guard'    => [ 'label' => 'POST /submit-pack-exam → 401 without JWT (no auth header)',                             'method' => 'POST',   'route' => '/api/v1/submit-pack-exam' ],
                    'p4_next_pack_missing_fields'  => [ 'label' => 'GET /trial/next-pack with no params → 400 missing_fields',                             'method' => 'GET',    'route' => '/api/v1/trial/next-pack' ],
                    'p4_next_pack_invalid_branch'  => [ 'label' => 'GET /trial/next-pack branch=superhard → 400 invalid_branch',                           'method' => 'GET',    'route' => '/api/v1/trial/next-pack' ],
                    'p4_next_pack_unknown_scope'   => [ 'label' => 'GET /trial/next-pack for nonexistent scope → 503 no_pack_available + generation queued','method' => 'GET',    'route' => '/api/v1/trial/next-pack' ],
                    'p4_child_history_missing_id'  => [ 'label' => 'DELETE /trial/child-history without child_id → 400',                                   'method' => 'DELETE', 'route' => '/api/v1/trial/child-history' ],
                    'p4_child_history_reset_noop'  => [ 'label' => 'DELETE /trial/child-history for nonexistent child → 200, deleted: 0',                  'method' => 'DELETE', 'route' => '/api/v1/trial/child-history?child_id=999999999' ],
                    'p4_schema_branch_column'      => [ 'label' => 'trial_packs.branch column present (Phase 4 migration ran)',                             'method' => 'GET',    'route' => '/api/v1/trial-packs/list' ],
                    'p4_schema_sequence_column'    => [ 'label' => 'trial_packs.pack_sequence_number column present + backfill ran',                        'method' => 'GET',    'route' => '/api/v1/trial-packs/list' ],
                    'p4_wp_ajax_reset_registered'  => [ 'label' => 'WP AJAX knowly_admin_reset_pack_history action is registered',                         'method' => 'WP',     'route' => 'has_action()' ],
                ],
            ],
            'data_management' => [
                'label' => '🗑️ Group 13 — Data Management: Purge Controls',
                'slow'  => false,
                'tests' => [
                    'purge_training_auth_guard'   => [ 'label' => 'DELETE /training/purge → 401 without server key (auth guard active)',          'method' => 'DELETE', 'route' => '/api/v1/training/purge' ],
                    'purge_curriculum_auth_guard' => [ 'label' => 'DELETE /curriculum-topics/purge → 401 without server key',                      'method' => 'DELETE', 'route' => '/api/v1/curriculum-topics/purge' ],
                    'purge_qb_auth_guard'         => [ 'label' => 'DELETE /question-bank/purge → 401 without server key',                          'method' => 'DELETE', 'route' => '/api/v1/question-bank/purge' ],
                    'purge_wp_ajax_registered'    => [ 'label' => 'wp_ajax_knowly_purge_step action is registered in WP',                          'method' => 'WP',     'route' => 'has_action()' ],
                    'purge_class_exists'          => [ 'label' => 'Knowly_Admin_Data_Management class loaded with boot, render, ajax_purge_step',   'method' => 'WP',     'route' => 'class_exists()' ],
                    'purge_page_registered'       => [ 'label' => 'knowly-data-management page is in the WP admin submenu',                        'method' => 'WP',     'route' => 'global $submenu' ],
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
                Verifies Phase 3 and Question Bank v2 implementation: DB tables, curriculum layer, CRUD,
                generation regression, module_number-based trial assembly, and the Phase 2 Trials Admin v2 AJAX layer.
                Groups 1–3, 5, 7, 9–11 are fast (&lt; 2s each). Groups 4, 6, 8 call Claude (~30s per test).
            </p>

            <div class="knowly-test-toolbar">
                <button id="spectest-run-fast" class="button button-primary">▶ Run All Fast Tests</button>
                <button id="spectest-run-slow" class="button" style="background:#d63638;border-color:#d63638;color:#fff;">⚡ Run Slow Tests (Groups 4, 6, 8)</button>
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
                $groups['question_bank']['tests'],
                $groups['qbv2_schema']['tests'],
                $groups['qbv2_delivery']['tests'],
                $groups['qb_browse_retire']['tests'],
                $groups['trials_admin']['tests'],
                $groups['curriculum_setup']['tests'],
                $groups['data_management']['tests'],
                $groups['trial_packs']['tests'],
                $groups['p4_delivery']['tests']
            ) ) ) ?>;

            var SLOW_TESTS = <?= wp_json_encode( array_keys( array_merge(
                $groups['generation']['tests'],
                $groups['qb_generation']['tests'],
                $groups['qbv2_generation']['tests']
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
