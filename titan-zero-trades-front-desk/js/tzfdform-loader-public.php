<?php 

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

header('Content-Type: application/x-javascript; charset=UTF-8'); 

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
        $tzfd_md = dir( dirname( __FILE__ )."/modules" );
		$tzfd_modules_files = array();
        while( false !== ( $tzfd_entry = $tzfd_md->read() ) ) 
		{    
            if ( strlen( $tzfd_entry ) > 3 && is_dir( $tzfd_md->path.'/'.$tzfd_entry ) )
			{
				if ( file_exists( $tzfd_md->path.'/'.$tzfd_entry.'/public' ) )
				{
					$m = dir( $tzfd_md->path.'/'.$tzfd_entry.'/public' );
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
    require __DIR__ . '/tzfd-public-accessibility.js';
    require __DIR__ . '/tzfd-calendar.js';
    require __DIR__ . '/tzfdform-core-public.jquery.js';
    try {
        $tzfd_d = dir( dirname( __FILE__ )."/tzfdform-public-fields" );
		$tzfd_controls_files = array();
        while (false !== ($tzfd_entry = $tzfd_d->read())) {            
            if (strlen($tzfd_entry) > 3 && strtolower(substr($tzfd_entry,strlen($tzfd_entry)-3)) == '.js')
                if ( file_exists( $tzfd_d->path.'/'.$tzfd_entry ) )
                    $tzfd_controls_files[] = $tzfd_d->path.'/'.$tzfd_entry;
        }
		sort( $tzfd_controls_files );
		foreach( $tzfd_controls_files as $tzfd_file_path )
		{
			require $tzfd_file_path;
		}
    } catch (Exception $e) {
        // ignore the error
    }
?>
        var fcount = 1;
        var fcount_tags = 1;
        var fnum = "_"+fcount; 
        var tzfd_avoid_hidden = false;
        while (20>fcount || typeof window["tzfd_frontdesk_tzfdform_config"+fnum] != "undefined")
        {
            try {
            var tzfd_frontdesk_tzfdform_config = window["tzfd_frontdesk_tzfdform_config"+fnum];
            while (20>fcount_tags && !$("#tzfdform_"+fcount_tags).length)
                fcount_tags++;
            tzfd_frontdesk_tzfdform_config = $.parseJSON(tzfd_frontdesk_tzfdform_config.obj);
            tzfd_frontdesk_tzfdform_config.identifier = "_"+fcount_tags;
            
                var opt_identifier = $("#fieldlist_"+fcount_tags);
                opt_identifier.attr("fcount_tags",fcount_tags); 
                opt_identifier.attr("fnum",fnum); 
                opt_identifier.addClass("tzfd_avoid_hidden")
                var f = $("#tzfdform_"+fcount_tags).tzfdform(tzfd_frontdesk_tzfdform_config);
			    f.fBuild.loadData("form_structure_"+fcount_tags);
			    $.tzfdform.configValidate($("#tzfd_frontdesk_pform_"+fcount_tags));   
			    if((typeof opt_identifier.attr("fnum") !== 'undefined') && !opt_identifier.is(':hidden'))
			        $(opt_identifier).addClass("tzfd_v_v");
			    else
			        tzfd_avoid_hidden = true;
			            
     		} catch (e) {}
	    	fcount++;
            fcount_tags++;
	    	fnum = "_"+fcount;
	    }
	    
	    if (tzfd_avoid_hidden)
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
	            						    $(".tzfd_avoid_hidden").each(function(){
	            						        var opt_identifier= $("#"+$(this).attr("id"));
	            						        if((typeof opt_identifier.attr("fnum") !== 'undefined') && !opt_identifier.is(':hidden') && !opt_identifier.hasClass("tzfd_v_v"))
	            						        {
	            						            $(opt_identifier).addClass("tzfd_v_v");
	            						            var fnum = opt_identifier.attr("fnum");   
                                                    var fcount_tags = opt_identifier.attr("fcount_tags");
	            						            var tzfd_frontdesk_tzfdform_config = window["tzfd_frontdesk_tzfdform_config"+fnum];
                                                    tzfd_frontdesk_tzfdform_config = $.parseJSON(tzfd_frontdesk_tzfdform_config.obj);
                                                    tzfd_frontdesk_tzfdform_config.identifier = "_"+fcount_tags;
	            						            var f = $("#tzfdform_"+fcount_tags).tzfdform(tzfd_frontdesk_tzfdform_config);
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