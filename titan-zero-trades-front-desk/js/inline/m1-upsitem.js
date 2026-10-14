/*
 * Titan Zero Trades: Front Desk - booking list "Update Status" button.
 * Replaces the inline onclick handler that used to be printed by
 * TZFD_Main_Class::filter_list(). Settings come from wp_localize_script()
 * as the tzfdUpsItem object (page, anonce, cal).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.tzfd-ups-item' ) : null;
		if ( ! button ) {
			return;
		}

		var cfg = window.tzfdUpsItem || {};
		var id = button.getAttribute( 'data-tzfd-id' ) || '';
		var item = button.getAttribute( 'data-tzfd-item' ) || '';
		var select = document.getElementById( 'sb' + id + '_' + item );
		if ( ! select || select.selectedIndex < 0 ) {
			return;
		}

		var status = select.options[ select.selectedIndex ].value;
		document.location = 'admin.php?page=' + encodeURIComponent( cfg.page || '' ) +
			'&anonce=' + encodeURIComponent( cfg.anonce || '' ) +
			'&cal=' + encodeURIComponent( cfg.cal || '' ) +
			'&list=1&ud=' + encodeURIComponent( id ) +
			'&status=' + encodeURIComponent( status ) +
			'&udidx=' + ( parseInt( item, 10 ) - 1 ) +
			'&or=shlist&r=' + Math.random();
	} );
}() );
