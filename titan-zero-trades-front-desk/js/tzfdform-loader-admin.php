<?php 

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

header('Content-Type: application/x-javascript; charset=UTF-8'); 

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
        $tzfd_md = dir( dirname( __FILE__ )."/modules");
		$tzfd_modules_files = array();
        while( false !== ( $tzfd_entry = $tzfd_md->read() ) ) 
		{    
            if ( strlen( $tzfd_entry ) > 3 && is_dir( $tzfd_md->path.'/'.$tzfd_entry ) )
			{
				if ( file_exists( $tzfd_md->path.'/'.$tzfd_entry.'/admin' ) )
				{
					$m = dir( $tzfd_md->path.'/'.$tzfd_entry.'/admin' );
					while( false !== ( $tzfd_mentry = $m->read() ) )
					{	
						if( strlen( $tzfd_mentry ) > 3 && strtolower( substr( $tzfd_mentry, strlen( $tzfd_mentry ) - 3 ) ) == '.js' )
						{
							$tzfd_modules_files[] = $m->path.'/'.$tzfd_mentry;
						}
					}
				}	
						
			}			
        }
		sort( $tzfd_modules_files );
		foreach( $tzfd_modules_files as $tzfd_file_path )
		{
			require $tzfd_file_path;
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
        $tzfd_d = dir( dirname( __FILE__ )."/tzfdform-admin-fields" );
		$tzfd_controls_files = array();
		while (false !== ($tzfd_entry = $tzfd_d->read())) 
		{            
            if (strlen($tzfd_entry) > 3 && strtolower(substr($tzfd_entry,strlen($tzfd_entry)-3)) == '.js')
			{
				if (file_exists( $tzfd_d->path.'/'.$tzfd_entry ) )
				{
                    $tzfd_controls_files[] =  $tzfd_d->path.'/'.$tzfd_entry;
				}
			}	
        }
		sort( $tzfd_controls_files );
		foreach( $tzfd_controls_files as $tzfd_file_path )
		{
			require $tzfd_file_path;
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
