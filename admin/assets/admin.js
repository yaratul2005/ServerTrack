/* ServerTrack Admin JS — v3.7 HOTFIX */
/* global servertrackAdmin */
( function ( $ ) {
    'use strict';

    function stAjax( action, data, onSuccess, onError ) {
        $.post(
            servertrackAdmin.ajax_url,
            $.extend( { action: action, nonce: servertrackAdmin.nonce }, data ),
            function ( res ) {
                if ( res.success ) {
                    if ( onSuccess ) onSuccess( res.data );
                } else {
                    if ( onError ) onError( res.data );
                }
            }
        ).fail( function () {
            if ( onError ) onError( null );
        } );
    }

    /* Test Connection */
    $( document ).on( 'click', '.st-test-connection, .st-test-btn, .servertrack-test-btn', function () {
        var $btn      = $( this );
        var platform  = $btn.data( 'platform' );
        var $result   = $btn.siblings( '.st-test-result, .servertrack-test-response' ).first();

        $btn.prop( 'disabled', true );
        $result.text( 'Testing...' ).removeClass( 'ok error' );

        stAjax(
            'servertrack_test_event',
            { platform: platform },
            function ( d ) {
                $result.text( d.message || 'Connected!' ).addClass( 'ok' );
                $btn.prop( 'disabled', false );
            },
            function ( d ) {
                $result.text( ( d && d.message ) || 'Connection failed.' ).addClass( 'error' );
                $btn.prop( 'disabled', false );
            }
        );
    } );

    /* Toggle Password Visibility */
    $( document ).on( 'click', '.st-toggle-visibility', function () {
        var $btn   = $( this );
        var $input = $btn.closest( '.st-input-with-action, td' ).find( 'input' ).first();
        var type   = $input.attr( 'type' ) === 'password' ? 'text' : 'password';
        $input.attr( 'type', type );
        $btn.attr( 'aria-pressed', type === 'text' );
    } );

} )( jQuery );
