/* ServerTrack Admin JS — v3.6 */
/* global servertrackAdmin, Chart */
( function ( $ ) {
    'use strict';

    /* ── Helpers ──────────────────────────────────────────────────────── */

    function stAjax( action, data, onSuccess, onError ) {
        $.post(
            servertrackAdmin.ajaxUrl,
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

    /* ── Settings Form Save ───────────────────────────────────────────── */

    $( document ).on( 'click', '.st-save-settings', function () {
        var $btn     = $( this );
        var $content = $btn.closest( '.st-settings-tab-content' );
        // Fallback: walk up to the nearest containing form or settings wrapper
        if ( ! $content.length ) {
            $content = $btn.closest( 'form, .st-settings-tabs-wrap, #servertrack-wrap' );
        }
        var data = {};

        $content.find( '[name]' ).each( function () {
            var $el = $( this );
            var key = $el.attr( 'name' );
            if ( $el.attr( 'type' ) === 'checkbox' ) {
                data[ key ] = $el.is( ':checked' ) ? '1' : '';
            } else {
                data[ key ] = $el.val();
            }
        } );

        var origLabel = $btn.data( 'label' ) || $btn.text().trim();
        $btn.data( 'label', origLabel );
        $btn.prop( 'disabled', true ).text( servertrackAdmin.strings.saving || '\u2026' );

        var $feedback = $btn.siblings( '.st-save-feedback' );

        stAjax(
            'servertrack_save_settings',
            { settings: data },
            function () {
                $btn.prop( 'disabled', false ).text( origLabel );
                $feedback
                    .text( servertrackAdmin.strings.saved )
                    .removeClass( 'error' ).addClass( 'ok' );
                setTimeout( function () { $feedback.text( '' ).removeClass( 'ok' ); }, 3000 );
            },
            function ( d ) {
                $btn.prop( 'disabled', false ).text( origLabel );
                $feedback
                    .text( ( d && d.message ) || servertrackAdmin.strings.saveError )
                    .removeClass( 'ok' ).addClass( 'error' );
            }
        );
    } );

    /* ── Test Connection ──────────────────────────────────────────────── */
    /*  Handles both .st-test-connection (current) and                     */
    /*  .servertrack-test-btn (legacy alias) so old views still work.      */

    $( document ).on( 'click', '.st-test-connection, .servertrack-test-btn', function () {
        var $btn      = $( this );
        var platform  = $btn.data( 'platform' );
        var $result   = $btn.siblings( '.st-test-result, .servertrack-test-response' ).first();

        $btn.prop( 'disabled', true );
        $result.text( servertrackAdmin.strings.testing ).removeClass( 'ok error' );

        stAjax(
            'servertrack_test_connection',
            { platform: platform },
            function ( d ) {
                $result.text( d.message || servertrackAdmin.strings.connected ).addClass( 'ok' );
                $btn.prop( 'disabled', false );
            },
            function ( d ) {
                $result.text( ( d && d.message ) || servertrackAdmin.strings.failed ).addClass( 'error' );
                $btn.prop( 'disabled', false );
            }
        );
    } );

    /* ── Toggle Password Visibility ───────────────────────────────────── */

    $( document ).on( 'click', '.st-toggle-visibility', function () {
        var $btn   = $( this );
        var $input = $btn.closest( '.st-input-with-action, td' ).find( 'input' ).first();
        var type   = $input.attr( 'type' ) === 'password' ? 'text' : 'password';
        $input.attr( 'type', type );
        $btn.attr( 'aria-pressed', type === 'text' );
    } );

    /* ── Event Sources ────────────────────────────────────────────────── */

    function loadSources() {
        var $list = $( '#st-sources-list' );
        if ( ! $list.length ) return;

        $list.html( '<tr><td colspan="5" class="st-loading">Loading\u2026</td></tr>' );

        stAjax(
            'servertrack_get_sources',
            {},
            function ( d ) {
                var sources = d.sources || [];
                if ( ! sources.length ) {
                    $list.html( '<tr><td colspan="5" class="st-empty">No event sources yet.</td></tr>' );
                    return;
                }
                var html = '';
                sources.forEach( function ( s ) {
                    var platforms    = ( s.platforms || [] ).join( ', ' ) || '\u2014';
                    var enabledLabel = s.enabled
                        ? '<span class="st-badge st-badge-success">Active</span>'
                        : '<span class="st-badge off">Inactive</span>';
                    html += '<tr data-id="' + s.id + '">';
                    html += '<td>' + $( '<span>' ).text( s.name ).html() + '</td>';
                    html += '<td>' + $( '<span>' ).text( s.type ).html() + '</td>';
                    html += '<td>' + platforms + '</td>';
                    html += '<td>' + enabledLabel + '</td>';
                    html += '<td class="st-source-actions">';
                    html += '<button class="button st-toggle-source" data-id="' + s.id + '" data-enabled="' + ( s.enabled ? '1' : '' ) + '">' + ( s.enabled ? 'Disable' : 'Enable' ) + '</button> ';
                    html += '<button class="button button-link-delete st-delete-source" data-id="' + s.id + '">Delete</button>';
                    html += '</td></tr>';
                } );
                $list.html( html );
            },
            function () {
                $list.html( '<tr><td colspan="5" class="st-error">Failed to load sources.</td></tr>' );
            }
        );
    }

    $( document ).on( 'click', '.st-toggle-source', function () {
        var $btn    = $( this );
        var id      = $btn.data( 'id' );
        var enabled = ! $btn.data( 'enabled' );
        stAjax( 'servertrack_toggle_source', { source_id: id, enabled: enabled ? '1' : '' }, loadSources );
    } );

    $( document ).on( 'click', '.st-delete-source', function () {
        if ( ! window.confirm( servertrackAdmin.strings.confirm_del ) ) return;
        var id = $( this ).data( 'id' );
        stAjax( 'servertrack_delete_source', { source_id: id }, loadSources );
    } );

    $( document ).on( 'submit', '#st-add-source-form', function ( e ) {
        e.preventDefault();
        var $form = $( this );
        var data  = {};
        $form.serializeArray().forEach( function ( f ) { data[ f.name ] = f.value; } );
        $form.find( 'input[type=checkbox]' ).each( function () {
            data[ $( this ).attr( 'name' ) ] = $( this ).is( ':checked' ) ? '1' : '';
        } );
        var platforms = [];
        $form.find( 'input[name="platforms[]"]' ).filter( ':checked' ).each( function () {
            platforms.push( $( this ).val() );
        } );
        data.platforms = platforms;
        stAjax( 'servertrack_save_source', { source: data }, function () {
            $form[ 0 ].reset();
            loadSources();
        } );
    } );

    /* ── Init ─────────────────────────────────────────────────────────── */

    $( function () {
        if ( $( '#st-sources-list' ).length ) {
            loadSources();
        }
    } );

} )( jQuery );
