/* ServerTrack Admin JS — v3.5 */
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
        var $btn  = $( this );
        var $form = $btn.closest( 'form, .st-settings-tab-content' );
        var data  = {};

        $form.find( '[name]' ).each( function () {
            var $el = $( this );
            var key = $el.attr( 'name' );
            if ( $el.attr( 'type' ) === 'checkbox' ) {
                data[ key ] = $el.is( ':checked' ) ? '1' : '';
            } else {
                data[ key ] = $el.val();
            }
        } );

        $btn.prop( 'disabled', true ).text( '…' );

        stAjax(
            'servertrack_save_settings',
            { settings: data },
            function () {
                $btn.prop( 'disabled', false ).text( servertrackAdmin.strings.saved );
                setTimeout( function () { $btn.text( $btn.data( 'label' ) || 'Save Settings' ); }, 2500 );
            },
            function () {
                $btn.prop( 'disabled', false ).text( servertrackAdmin.strings.saveError );
            }
        );
    } );

    /* ── Test Connection ──────────────────────────────────────────────── */

    $( document ).on( 'click', '.st-test-connection', function () {
        var $btn      = $( this );
        var platform  = $btn.data( 'platform' );
        var $result   = $btn.siblings( '.st-test-result' );

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
        var $input = $btn.siblings( 'input' );
        var type   = $input.attr( 'type' ) === 'password' ? 'text' : 'password';
        $input.attr( 'type', type );
        $btn.attr( 'aria-pressed', type === 'text' );
    } );

    /* ── Event Sources ────────────────────────────────────────────────── */

    function loadSources() {
        var $list = $( '#st-sources-list' );
        if ( ! $list.length ) return;

        $list.html( '<tr><td colspan="5" class="st-loading">Loading…</td></tr>' );

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
                    var platforms = ( s.platforms || [] ).join( ', ' ) || '—';
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
                    html += '<button class="button st-delete-source" data-id="' + s.id + '">Delete</button>';
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
        // Collect checked platforms
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
        // Load sources table if on sources page
        if ( $( '#st-sources-list' ).length ) {
            loadSources();
        }
    } );

} )( jQuery );
