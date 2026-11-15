<?php 

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store'); 

require __DIR__ . '/jQuery.stringify.js';
require __DIR__ . '/jquery.validate.js';


?>
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
        $tz_md = dir( dirname( __FILE__ )."/modules" );
		$tz_modules_files = array();
        while( false !== ( $tz_entry = $tz_md->read() ) ) 
		{    
            if ( strlen( $tz_entry ) > 3 && is_dir( $tz_md->path.'/'.$tz_entry ) )
			{
				if ( file_exists( $tz_md->path.'/'.$tz_entry.'/public' ) )
				{
					$m = dir( $tz_md->path.'/'.$tz_entry.'/public' );
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
    require __DIR__ . '/tzfd-public-accessibility.js';
    require __DIR__ . '/tzfd-calendar.js';
    require __DIR__ . '/tzfdform-core-public.jquery.js';
    try {
        $tz_d = dir( dirname( __FILE__ )."/tzfdform-public-fields" );
		$tz_controls_files = array();
        while (false !== ($tz_entry = $tz_d->read())) {            
            if (strlen($tz_entry) > 3 && strtolower(substr($tz_entry,strlen($tz_entry)-3)) == '.js')
                if ( file_exists( $tz_d->path.'/'.$tz_entry ) )
                    $tz_controls_files[] = $tz_d->path.'/'.$tz_entry;
        }
		sort( $tz_controls_files );
		foreach( $tz_controls_files as $tz_file_path )
		{
			require $tz_file_path;
		}
    } catch (Exception $e) {
        // ignore the error
    }
?>
        var fcount = 1;
        var fcount_tags = 1;
        var fnum = "_"+fcount; 
        var tz_fd_avoid_hidden = false;
        while (20>fcount || typeof window["tz_fd_frontdesk_tz_fd_form_config"+fnum] != "undefined")
        {
            try {
            var tz_fd_frontdesk_tz_fd_form_config = window["tz_fd_frontdesk_tz_fd_form_config"+fnum];
            while (20>fcount_tags && !$("#tz_fd_form_"+fcount_tags).length)
                fcount_tags++;
            tz_fd_frontdesk_tz_fd_form_config = $.parseJSON(tz_fd_frontdesk_tz_fd_form_config.obj);
            tz_fd_frontdesk_tz_fd_form_config.identifier = "_"+fcount_tags;
            
                var opt_identifier = $("#fieldlist_"+fcount_tags);
                opt_identifier.attr("fcount_tags",fcount_tags); 
                opt_identifier.attr("fnum",fnum); 
                opt_identifier.addClass("tz_fd_avoid_hidden")
                var f = $("#tz_fd_form_"+fcount_tags).tzfdform(tz_fd_frontdesk_tz_fd_form_config);
			    f.fBuild.loadData("form_structure_"+fcount_tags);
			    $.tzfdform.configValidate($("#tz_fd_frontdesk_pform_"+fcount_tags));   
			    if((typeof opt_identifier.attr("fnum") !== 'undefined') && !opt_identifier.is(':hidden'))
			        $(opt_identifier).addClass("tz_fd_v_v");
			    else
			        tz_fd_avoid_hidden = true;
			            
     		} catch (e) {}
	    	fcount++;
            fcount_tags++;
	    	fnum = "_"+fcount;
	    }
	    
	    if (tz_fd_avoid_hidden)
	        $( document ).each(
	            function()
	            {
	            	(new MutationObserver(
	            		function(mutationsList, observer)
	            		{   
	            			for(let k in mutationsList)
	            			{
	            			    var mutation = mutationsList[k];
	            				if (mutation.type === 'childList')
	            				{
	            					if(mutation.addedNodes.length)
	            					{
	            						try{ 
	            						    $(".tz_fd_avoid_hidden").each(function(){
	            						        var opt_identifier= $("#"+$(this).attr("id"));
	            						        if((typeof opt_identifier.attr("fnum") !== 'undefined') && !opt_identifier.is(':hidden') && !opt_identifier.hasClass("tz_fd_v_v"))
	            						        {
	            						            $(opt_identifier).addClass("tz_fd_v_v");
	            						            var fnum = opt_identifier.attr("fnum");   
                                                    var fcount_tags = opt_identifier.attr("fcount_tags");
	            						            var tz_fd_frontdesk_tz_fd_form_config = window["tz_fd_frontdesk_tz_fd_form_config"+fnum];
                                                    tz_fd_frontdesk_tz_fd_form_config = $.parseJSON(tz_fd_frontdesk_tz_fd_form_config.obj);
                                                    tz_fd_frontdesk_tz_fd_form_config.identifier = "_"+fcount_tags;
	            						            var f = $("#tz_fd_form_"+fcount_tags).tzfdform(tz_fd_frontdesk_tz_fd_form_config);
			                                        f.fBuild.loadData("form_structure_"+fcount_tags);
	            						            $.tzfdform.configValidate(opt_identifier.closest("form"));
	            						        }
	            						    })
	            						}catch(err){}
	            					}
	            				}
	            			}
	            		}
	            	)).observe(this, { childList: true, subtree: true });
	            }
	        );
})(tzfdformjQuery);
});