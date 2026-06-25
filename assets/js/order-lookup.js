/**
 * Order Lookup — frontend script
 * Lamper Design — v1.11
 */
jQuery( function ( $ ) {

    // ── HTML escaping om XSS te voorkomen ────────────────────────────────────

    function esc( str ) {
        return $( '<div>' ).text( str ).html();
    }

    // ── Auto-invullen vanuit URL-parameters (via link in order-mail) ─────────

    ( function () {
        var params = new URLSearchParams( window.location.search );
        var email  = params.get( 'ol_email' );
        var order  = params.get( 'ol_order' );
        if ( email && order ) {
            $( '#ol-email' ).val( email );
            $( '#ol-order' ).val( order );
            $( '#ol-btn' ).trigger( 'click' );
        }
    } )();

    // ── Stap 1: producten ophalen ─────────────────────────────────────────────

    $( '#ol-btn' ).on( 'click', function ( e ) {
        e.preventDefault();

        var email = $( '#ol-email' ).val().trim();
        var order = $( '#ol-order' ).val().trim().replace( /^FL/i, '' );
        var res   = $( '#ol-results' );

        if ( ! email || ! order ) {
            res.html( '<p style="color:red">Vul beide velden in.</p>' );
            return;
        }

        res.html( '<p>Laden…</p>' );

        $.post( orderLookup.ajaxurl, {
            action:   'get_order_products',
            nonce:    orderLookup.nonce,
            order_id: order,
            email:    email,
        }, function ( response ) {
            if ( ! response.success ) {
                res.html( '<p style="color:red">' + esc( response.data ) + '</p>' );
                return;
            }

            var data = response.data;
            var html = '<p><strong>Order #' + esc( data.order_number ) + '</strong></p>';
            html    += '<p>Selecteer de producten die je wilt retourneren:</p>';
            html    += '<form id="ol-return-form">';
            html    += '<input type="hidden" name="order_number" value="' + esc( data.order_number ) + '">';

            data.items.forEach( function ( p ) {
                var key = p.id + '|' + p.name;
                html   += '<label style="display:block;margin-bottom:.5em;">';
                html   += '<input type="checkbox" name="items[]" value="' + esc( key ) + '"> ';
                html   += esc( p.qty ) + '× ' + esc( p.name ) + ' — ' + esc( p.subtotal );
                html   += '</label>';
            } );

            html += '<br><button type="submit" id="ol-return-btn">Retourverzoek versturen</button>';
            html += '</form>';
            html += '<div id="ol-return-result" style="margin-top:1em;"></div>';

            res.html( html );
        } );
    } );

    // ── Stap 2: retourverzoek versturen ──────────────────────────────────────

    $( document ).on( 'submit', '#ol-return-form', function ( e ) {
        e.preventDefault();

        var email    = $( '#ol-email' ).val().trim();
        var form     = $( this );
        var result   = $( '#ol-return-result' );
        var selected = form.find( 'input[name="items[]"]:checked' ).map( function () {
            return $( this ).val();
        } ).get();

        if ( selected.length === 0 ) {
            result.html( '<p style="color:red">Selecteer minimaal één product.</p>' );
            return;
        }

        result.html( '<p>Versturen…</p>' );

        $.post( orderLookup.ajaxurl, {
            action:       'submit_return_request',
            nonce:        orderLookup.nonce,
            order_number: form.find( '[name="order_number"]' ).val(),
            email:        email,
            admin_email:  $( '#ol-admin-email' ).val(),
            items:        selected,
        }, function ( response ) {
            if ( ! response.success ) {
                result.html( '<p style="color:red">' + esc( response.data ) + '</p>' );
                return;
            }
            result.html( '<p style="color:green">' + esc( response.data ) + '</p>' );
            form.find( 'button[type="submit"]' ).prop( 'disabled', true ).text( 'Verstuurd' );
        } );
    } );

} );
