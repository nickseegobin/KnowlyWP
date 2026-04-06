/**
 * KnowlyAPI Admin JavaScript
 * Handles: Test Suite AJAX, Debug Log interactions, auto-refresh
 */
/* global KnowlyAdmin, jQuery */
( function ( $ ) {
    'use strict';

    // ── Debug Log ─────────────────────────────────────────────────────────────

    // Toggle data rows on click
    $( document ).on( 'click', '[data-target]', function () {
        const targetId = $( this ).data( 'target' );
        $( '#' + targetId ).toggle();
    } );

    // Clear logs
    $( '#knowly-clear-logs' ).on( 'click', function () {
        if ( ! confirm( 'Delete all debug log entries? This cannot be undone.' ) ) return;

        const $btn = $( this ).prop( 'disabled', true ).text( 'Clearing…' );

        $.post( KnowlyAdmin.ajaxUrl, {
            action : 'knowly_clear_logs',
            nonce  : KnowlyAdmin.nonce,
        }, function ( res ) {
            if ( res.success ) {
                location.reload();
            } else {
                alert( 'Failed to clear logs.' );
                $btn.prop( 'disabled', false ).text( 'Clear All Logs' );
            }
        } );
    } );

    // Auto-refresh
    let autoRefreshTimer = null;

    $( '#knowly-autorefresh' ).on( 'change', function () {
        if ( this.checked ) {
            autoRefreshTimer = setInterval( () => location.reload(), 10000 );
        } else {
            clearInterval( autoRefreshTimer );
        }
    } );

    // ── Test Suite ────────────────────────────────────────────────────────────

    const testData = {};   // Shared data across tests (e.g. token from login)

    // Generate admin token (current admin user)
    $( '#knowly-gen-admin-token' ).on( 'click', function () {
        const $btn = $( this ).prop( 'disabled', true ).text( '…' );
        const $status = $( '#knowly-token-status' );
        $.post( KnowlyAdmin.ajaxUrl, {
            action  : 'knowly_gen_token',
            nonce   : KnowlyAdmin.nonce,
        }, function ( res ) {
            if ( res.success ) {
                $( '#td-token' ).val( res.data.token );
                $( '#td-user-id' ).val( res.data.user_id );
                testData.token   = res.data.token;
                testData.user_id = res.data.user_id;
                $status.text( '✓ Token generated for user #' + res.data.user_id ).css( 'color', '#16a34a' );
            } else {
                $status.text( '✗ ' + ( res.data && res.data.message || 'Failed' ) ).css( 'color', '#dc2626' );
            }
        } ).fail( () => {
            $status.text( '✗ AJAX request failed.' ).css( 'color', '#dc2626' );
        } ).always( () => {
            $btn.prop( 'disabled', false ).text( '⚡ Generate Admin Token' );
        } );
    } );

    // Generate token for a specific user ID
    $( '#knowly-gen-user-token' ).on( 'click', function () {
        const userId = parseInt( $( '#td-user-id' ).val(), 10 );
        if ( ! userId ) {
            $( '#knowly-token-status' ).text( 'Enter a User ID first.' ).css( 'color', '#d97706' );
            return;
        }
        const $btn = $( this ).prop( 'disabled', true ).text( '…' );
        const $status = $( '#knowly-token-status' );
        $.post( KnowlyAdmin.ajaxUrl, {
            action  : 'knowly_gen_token',
            nonce   : KnowlyAdmin.nonce,
            user_id : userId,
        }, function ( res ) {
            if ( res.success ) {
                $( '#td-token' ).val( res.data.token );
                testData.token   = res.data.token;
                testData.user_id = res.data.user_id;
                $status.text( '✓ Token generated for user #' + res.data.user_id ).css( 'color', '#16a34a' );
            } else {
                $status.text( '✗ ' + ( res.data && res.data.message || 'Failed' ) ).css( 'color', '#dc2626' );
            }
        } ).fail( () => {
            $status.text( '✗ AJAX request failed.' ).css( 'color', '#dc2626' );
        } ).always( () => {
            $btn.prop( 'disabled', false ).text( '🔑 Generate Token for User ID' );
        } );
    } );

    // Read test data panel inputs into testData before each run
    function syncTestDataFromInputs() {
        const username  = $( '#td-username' ).val().trim();
        const password  = $( '#td-password' ).val().trim();
        const token     = $( '#td-token' ).val().trim();
        const childId   = $( '#td-child-id' ).val().trim();
        const userId    = $( '#td-user-id' ).val().trim();
        const pin       = $( '#td-pin' ).val().trim();

        if ( username )  testData.username  = username;
        if ( password )  testData.password  = password;
        if ( token )     testData.token     = token;
        if ( childId )   testData.child_id  = childId;
        if ( userId )    testData.user_id   = userId;
        if ( pin )       testData.pin       = pin;
    }

    // Run single test
    $( document ).on( 'click', '.knowly-run-test', function ( e ) {
        e.stopPropagation();
        syncTestDataFromInputs();
        const testId = $( this ).data( 'test' );
        runTest( testId );
    } );

    // Run all tests in a group
    $( document ).on( 'click', '.knowly-run-group', function ( e ) {
        e.stopPropagation();
        syncTestDataFromInputs();
        const groupId = $( this ).data( 'group' );
        const tests   = $( '#group-' + groupId + ' .knowly-run-test' );
        runSequential( tests.map( ( i, el ) => $( el ).data( 'test' ) ).get() );
    } );

    // Run all tests
    $( '#knowly-run-all' ).on( 'click', function () {
        syncTestDataFromInputs();
        const tests = $( '.knowly-run-test' ).map( ( i, el ) => $( el ).data( 'test' ) ).get();
        runSequential( tests );
    } );

    // Clear results
    $( '#knowly-clear-results' ).on( 'click', function () {
        $( '.knowly-test-status' ).text( '○' ).css( 'color', '' );
        $( '.knowly-test-result' ).hide().removeClass( 'pass fail warn' ).html( '' );
        $( '#knowly-test-summary' ).text( '' );
        Object.keys( testData ).forEach( k => delete testData[k] );
    } );

    function runSequential( ids ) {
        let chain = Promise.resolve();
        ids.forEach( id => { chain = chain.then( () => runTest( id ) ); } );
        chain.then( updateSummary );
    }

    function runTest( testId ) {
        const $status = $( '#status-' + testId );
        const $result = $( '#result-' + testId );

        $status.text( '⟳' ).css( 'color', '#aaa' );
        $result.hide().html( '' );

        return new Promise( resolve => {
            $.post( KnowlyAdmin.ajaxUrl, {
                action : 'knowly_test',
                nonce  : KnowlyAdmin.nonce,
                test   : testId,
                data   : JSON.stringify( testData ),
            }, function ( res ) {
                if ( ! res ) { resolve(); return; }

                // Carry forward full token from login → auto-fill the input field too
                if ( testId === 'auth_login' && res.pass && res.data ) {
                    const fullToken = res.data._token || res.data.token;
                    if ( fullToken && fullToken.indexOf( '…' ) === -1 ) {
                        testData.token = fullToken;
                        $( '#td-token' ).val( fullToken );
                    }
                    if ( res.data.user_id ) {
                        testData.user_id = res.data.user_id;
                        if ( ! $( '#td-user-id' ).val() ) {
                            $( '#td-user-id' ).val( res.data.user_id );
                        }
                    }
                }

                // Carry forward child_id from create → auto-fill input
                if ( testId === 'children_create' && res.pass && res.data && res.data.child_id ) {
                    testData.child_id = res.data.child_id;
                    $( '#td-child-id' ).val( res.data.child_id );
                }

                // Carry forward session_id from exam start
                if ( testId === 'exams_start' && res.pass && res.data && res.data.session_id ) {
                    testData.session_id = res.data.session_id;
                }

                renderResult( testId, res );
                resolve( res );
            } ).fail( () => {
                renderResult( testId, { pass: false, status: 'fail', message: 'AJAX request failed.', duration_ms: 0 } );
                resolve();
            } );
        } );
    }

    function renderResult( testId, res ) {
        const $status = $( '#status-' + testId );
        const $result = $( '#result-' + testId );

        const icon  = res.pass === true ? '✓' : ( res.pass === null ? '⚠' : '✗' );
        const color = res.pass === true ? '#16a34a' : ( res.pass === null ? '#d97706' : '#dc2626' );

        $status.text( icon ).css( 'color', color );

        const cls = res.pass === true ? 'pass' : ( res.pass === null ? 'warn' : 'fail' );

        // Build display data — hide the internal _token field from the JSON viewer
        let displayData = res.data ? Object.assign( {}, res.data ) : {};
        delete displayData._token;

        const dataHtml = Object.keys( displayData ).length
            ? '<pre class="knowly-json knowly-result-data">' + escHtml( JSON.stringify( displayData, null, 2 ) ) + '</pre>'
            : '';

        $result
            .removeClass( 'pass fail warn' )
            .addClass( cls )
            .html(
                '<div class="knowly-result-message">' + escHtml( res.message || '' ) + '</div>'
                + dataHtml
                + '<div class="knowly-duration">' + ( res.duration_ms || 0 ) + 'ms</div>'
            )
            .show();
    }

    function updateSummary() {
        const pass  = $( '.knowly-test-status:contains(✓)' ).length;
        const fail  = $( '.knowly-test-status:contains(✗)' ).length;
        const warn  = $( '.knowly-test-status:contains(⚠)' ).length;
        const total = pass + fail + warn;

        $( '#knowly-test-summary' ).html(
            '<span style="color:#16a34a">✓ ' + pass + ' passed</span>  '
            + '<span style="color:#dc2626">✗ ' + fail + ' failed</span>  '
            + '<span style="color:#d97706">⚠ ' + warn + ' warnings</span>'
            + '  <span style="color:#666">/ ' + total + ' total</span>'
        );
    }

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

} )( jQuery );
