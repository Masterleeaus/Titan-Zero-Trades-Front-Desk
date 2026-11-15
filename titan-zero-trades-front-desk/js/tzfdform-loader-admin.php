<?php 

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store'); 

require __DIR__ . '/jQuery.stringify.js';

?>

$tzfdformloadedflag = true;

tzfdformjQuery = (typeof tzfdformjQuery != 'undefined' ) ? tzfdformjQuery : jQuery;
tzfdformjQuery(function(){
(function($) {
	// Namespace of tzfdform
	$.tzfdform = $.tzfdform || {};
	$.tzfdform[ 'objName' ] = 'tzfdformjQuery';
	
<?php
	// Load Module files
	try 
	{
        $tz_md = dir( dirname( __FILE__ )."/modules");
		$tz_modules_files = array();
        while( false !== ( $tz_entry = $tz_md->read() ) ) 
		{    
            if ( strlen( $tz_entry ) > 3 && is_dir( $tz_md->path.'/'.$tz_entry ) )
			{
				if ( file_exists( $tz_md->path.'/'.$tz_entry.'/admin' ) )
				{
					$m = dir( $tz_md->path.'/'.$tz_entry.'/admin' );
					while( false !== ( $tz_mentry = $m->read() ) )
					{	
						if( strlen( $tz_mentry ) > 3 && strtolower( substr( $tz_mentry, strlen( $tz_mentry ) - 3 ) ) == '.js' )
						{
							$tz_modules_files[] = $m->path.'/'.$tz_mentry;
						}
					}
				}	
						
			}			
        }
		sort( $tz_modules_files );
		foreach( $tz_modules_files as $tz_file_path )
		{
			require $tz_file_path;
		}
	} 
	catch (Exception $e) 
	{
        // ignore the error
    }

	// Load Control files
    require __DIR__ . '/tzfdform-core-admin.jquery.js';
    try 
	{
        $tz_d = dir( dirname( __FILE__ )."/tzfdform-admin-fields" );
		$tz_controls_files = array();
		while (false !== ($tz_entry = $tz_d->read())) 
		{            
            if (strlen($tz_entry) > 3 && strtolower(substr($tz_entry,strlen($tz_entry)-3)) == '.js')
			{
				if (file_exists( $tz_d->path.'/'.$tz_entry ) )
				{
                    $tz_controls_files[] =  $tz_d->path.'/'.$tz_entry;
				}
			}	
        }
		sort( $tz_controls_files );
		foreach( $tz_controls_files as $tz_file_path )
		{
			require $tz_file_path;
		}
	} 
	catch (Exception $e) 
	{
        // ignore the error
    }

?>
	// Global events
	$('[name="paypal_recurrent"]').change( function(){
		if( $( this ).val() == 'field' ) $( '[name="paypal_recurrent_field"]' ).show();
		else $( '[name="paypal_recurrent_field"]' ).hide();
	} );
})(tzfdformjQuery);
});
