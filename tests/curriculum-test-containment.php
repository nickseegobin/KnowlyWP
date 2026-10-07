<?php
// No WP bootstrap: a successful guard must return before any WP/network call.
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/admin/class-knowly-admin-spec-tests.php';
require __DIR__ . '/../includes/admin/class-knowly-admin-testing.php';
foreach ( [ 'curriculum_import_archives_stale', 'curriculum_import_creates_topic', 'pinecone_sync_create_archive', 'crud_create', 'crud_update', 'crud_archive' ] as $id ) {
    $result = Knowly_Admin_Spec_Tests::run_test( $id );
    if ( $result['status'] !== 'warn' || $result['duration_ms'] !== 0 || $result['pass'] !== false ) {
        throw new RuntimeException( "Unsafe spec dispatch: {$id}" );
    }
}
foreach ( [ 'curr_crud_create', 'curr_crud_update', 'curr_crud_archive' ] as $id ) {
    $result = Knowly_Admin_Testing::run_test( $id );
    if ( $result['status'] !== 'warn' || $result['duration_ms'] !== 0 || $result['pass'] !== false ) {
        throw new RuntimeException( "Unsafe embedded dispatch: {$id}" );
    }
}
echo "Curriculum test containment passed without WP or network calls.\n";
