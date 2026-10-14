/**
 * Titan Zero Trades: Front Desk admin screens (Customers and Payments).
 *
 * - A form with a data-confirm attribute asks for confirmation before it submits.
 * - A textarea with the tzfd-select-on-click class selects its text when clicked.
 */
( function () {
    'use strict';

    document.addEventListener( 'submit', function ( event ) {
        var form = event.target;
        if ( ! form || ! form.getAttribute ) {
            return;
        }
        var message = form.getAttribute( 'data-confirm' );
        if ( message && ! window.confirm( message ) ) {
            event.preventDefault();
        }
    } );

    document.addEventListener( 'click', function ( event ) {
        var target = event.target;
        if ( target && target.matches && target.matches( 'textarea.tzfd-select-on-click' ) ) {
            target.select();
        }
    } );
}() );
