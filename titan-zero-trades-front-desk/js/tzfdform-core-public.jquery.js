	$.tzfdform[ 'controls' ] = ( typeof $.tzfdform[ 'controls' ] != 'undefined' ) ? $.tzfdform[ 'controls' ]: {};
	$.tzfdform[ 'forms' ] = ( typeof $.tzfdform[ 'forms' ] != 'undefined' ) ? $.tzfdform[ 'forms' ]: {};
	
	$.tzfdform[ 'htmlEncode' ] = function(value)
	{
		value = $('<div/>').text(value).html()
		// .html() above already encodes & < >; only the double quote is left to encode for attribute values.
		value = value.replace(/"/g, "&quot;");
		return value;
	};
	$.tzfdform[ 'configValidate' ] = function(elem)
	{
		elem.validate({
                    ignore:".ignore,.ignorepb",
			        errorElement: "div",
			        errorClass:"tzfdefb_error",
                highlight: function(element, errorClass, validClass) {
                    $.validator.defaults.highlight.call(this, element, errorClass, validClass);
                    $(element).attr('aria-invalid', 'true');
                },
                unhighlight: function(element, errorClass, validClass) {
                    $.validator.defaults.unhighlight.call(this, element, errorClass, validClass);
                    $(element).attr('aria-invalid', 'false');
                },
                invalidHandler: function(event, validator) { setTimeout(function(){ $.tzfdform.focusPublicError(validator); }, 0); },
			        errorPlacement: function(e, element) 
	    	        {
	    	        	if (element.parents(".dfield").find(".tzfdefb_error.message").not("[style]").length>0)
	    	        	   return;
	    	        	$.tzfdform.markValidationMessage(e);
	    	        	e.insertAfter(element.parents(".dfield").children().last());
	    	        	e.addClass("message");
	    	        }
     		    });
	};

    $.tzfdform['htmlDecode']=function(value)
    {
    	// Decode entities and drop tags without running markup: DOMParser documents are inert (no scripts, no image loads).
    	if(/&(?:#x[a-f0-9]+|#[0-9]+|[a-z0-9]+);?/ig.test(value))value=new DOMParser().parseFromString(value == null ? '' : String(value), 'text/html').documentElement.textContent;return value;
    };

	$.tzfdform[ 'escape_symbol' ] = function( value ) // Escape the symbols used in regulars expressions
	{
		return value.replace(/([\^\$\-\.\,\[\]\(\)\/\\\*\?\+\!\{\}])/g, "\\$1");
	};
	
	$.tzfdform[ 'parseValStr' ] = function( value )
	{
		return '"' + value.replace(/'/g, "\\'").replace( /\$/g, '') + '"';
	};
	
	$.tzfdform[ 'parseVal' ] = function( value, thousandSeparator, decimalSymbol )
	{
		if( value == '' ) return 0;
		value += '';
		
		thousandSeparator = new RegExp( $.tzfdform.escape_symbol( ( typeof thousandSeparator == 'undefined' ) ? ',' : thousandSeparator ), 'g' );
		decimalSymbol = new RegExp( $.tzfdform.escape_symbol( ( typeof decimalSymbol == 'undefined' ) ? '.' : decimalSymbol ), 'g' );
		
		var t = value.replace( thousandSeparator, '' ).replace( decimalSymbol, '.' ).replace( /\s/g, '' ),
			p = /[+-]?((\d+(\.\d+)?)|(\.\d+))/.exec( t );
			
		return ( p ) ? p[0]*1 : $.tzfdform[ 'parseValStr' ]( value );
	};
				
	
	$.fn.tzfdform = function(options){
		var opt = $.extend({},
					{
						pub:false,
						identifier:"",
						title:""
					},options, true);
				
		opt.messages = $.extend({
					previous: "Previous",
					next: "Next",
					pageof: "Page {0} of {0}",
					required: "This field is required.",
					email: "Please enter a valid email address.",
					datemmddyyyy: "Please enter a valid date with this format(mm/dd/yyyy)",
					dateddmmyyyy: "Please enter a valid date with this format(dd/mm/yyyy)",
					number: "Please enter a valid number.",
					digits: "Please enter only digits.",
					maxlength: $.validator.format("Please enter no more than {0} characters"),
                    minlength: $.validator.format("Please enter at least {0} characters."),
                    equalTo: "Please enter the same value again.",
					max: $.validator.format("Please enter a value less than or equal to {0}."),
					min: $.validator.format("Please enter a value greater than or equal to {0}.")
			},opt.messages);
			
		opt.messages.max = $.validator.format(opt.messages.max);
		opt.messages.min = $.validator.format(opt.messages.min);
		
		$.extend($.validator.messages, opt.messages);
		
		var items = [];
		var reloadItemsPublic = function() 
			{
				$("#fieldlist"+opt.identifier).closest( 'form' ).addClass( theForm.formtemplate );
                $("#fieldlist"+opt.identifier).html("").addClass(theForm.formlayout);
				$("#formheader"+opt.identifier).html(theForm.show());

				var page = 0;
				$("#fieldlist"+opt.identifier).append('<div class="pb'+page+' pbreak" page="'+page+'"></div>');
				for (var i=0;i<items.length;i++)
				{
					items[i].index = i;
					if (items[i].ftype=="fPageBreak")
					{
						page++;
						$("#fieldlist"+opt.identifier).append('<div class="pb'+page+' pbreak" page="'+page+'"></div>');
					}
					else
					{
						$("#fieldlist"+opt.identifier+" .pb"+page).append(items[i].show());
						if (items[i].predefinedClick)
						{
                            $("#fieldlist"+opt.identifier+" .pb"+page).find("#"+items[i].name).attr("placeholder",items[i].predefined);
                            $("#fieldlist"+opt.identifier+" .pb"+page).find("#"+items[i].name).attr("value","");
                        }
						if (items[i].userhelpTooltip)
						{
							var uh = $("#fieldlist"+opt.identifier+" .pb"+page).find("#"+items[i].name).closest(".fields");
							uh.find(".uh").css("display","none");
							if (uh.find(".uh").text()!="")
							{
								uh.attr("uh",uh.find(".uh").text());
							}	
						}
					}
				}
                
				if (page>0)
				{
                
					$("#fieldlist"+opt.identifier+" .pb"+page).addClass("pbEnd");
					$("#fieldlist"+opt.identifier+" .pbreak").each(function(index) {
						var code = $(this).html();
						var bSubmit = '';
						
						if (index == page)
						{

							if ( $( "#tzfdcaptchalayer"+opt.identifier ).length && !/^\s*$/.test( $( "#tzfdcaptchalayer"+opt.identifier ).html() ) )
							{
								code += '<div class="captcha">'+$("#tzfdcaptchalayer"+opt.identifier).html()+'</div><div class="clearer"></div>';
								$("#tzfdcaptchalayer"+opt.identifier).html("");
							}
							if ($("#tzfd_subbtn"+opt.identifier).html())
							{
								bSubmit = '<button type="button" class="pbSubmit">'+$("#tzfd_subbtn"+opt.identifier).html()+'</button>';
							}	
						}
						$(this).html('<fieldset><legend>'+opt.messages.pageof.replace( /\{\s*\d+\s*\}/, (index+1) ).replace( /\{\s*\d+\s*\}/, (page+1) )+'</legend>'+code+'<button type="button" class="pbPrevious">'+opt.messages.previous+'</button><button type="button" class="pbNext">'+opt.messages.next+'</button>'+bSubmit+'<div class="clearer"></div></fieldset>');
					});
					$( '#fieldlist'+opt.identifier).find(".pbPrevious,.pbNext").bind("click", { 'identifier' : opt.identifier }, function( evt ) {
					    var identifier = evt.data.identifier;
					    function focusTop(){try {$('html, body').animate({scrollTop: $("#fieldlist"+identifier+" .pb"+page).find("fieldset").offset().top}, 100);}catch(e){}}
						if (  ($(this).hasClass("pbPrevious")) || (($(this).hasClass("pbNext")) && $(this).parents("form").valid())  )
						{
							var page = parseInt($(this).parents(".pbreak").attr("page"));
							
							(($(this).hasClass("pbPrevious"))?page--:page++);
							$("#fieldlist"+identifier+" .pbreak").css("display","none");
							$("#fieldlist"+identifier+" .pbreak").find(".field").addClass("ignorepb");

							$("#fieldlist"+identifier+" .pb"+page).css("display","block");
							$("#fieldlist"+identifier+" .pb"+page).find(".field").removeClass("ignorepb");
                            var pageRoot = $("#fieldlist"+identifier+" .pb"+page);
                            var focusTarget = pageRoot.find('input:not([type=hidden]):visible, select:visible, textarea:visible, button:visible, a[href]:visible').first();
                            if (focusTarget.length) focusTarget.trigger('focus');
                            else pageRoot.find('fieldset').attr('tabindex','-1').trigger('focus');
						}
						else
						{
							$.tzfdform.focusPublicError($(this).parents("form").validate());
						}	
						return false;
					});
                }
				else
				{
					if ( $( "#tzfdcaptchalayer"+opt.identifier ).length && !/^\s*$/.test( $( "#tzfdcaptchalayer"+opt.identifier ).html() ) )
					{
						$("#fieldlist"+opt.identifier+" .pb"+page).append('<div class="captcha">'+$("#tzfdcaptchalayer"+opt.identifier).html()+'</div>');
						$("#tzfdcaptchalayer"+opt.identifier).html("");
					}
					if ($("#tzfd_subbtn"+opt.identifier).html())
					{
						$("#fieldlist"+opt.identifier+" .pb"+page).append('<button type="button" class="pbSubmit">'+$("#tzfd_subbtn"+opt.identifier).html()+'</button>');
					}	
				}
							
                // Set Captcha Event
				$( document ).on( 'click', '#tzfdform .captcha img', function(){ var e = $( this ); e.attr( 'src', e.attr( 'src' ).replace( /&\d+$/, '' ) + '&' + Math.floor( Math.random()*1000 ) ); } );
                
				$( document ).on("click","#fieldlist"+opt.identifier+" .pbSubmit", function( evt ) 
				{
				    $("#fieldlist"+opt.identifier+" .pbSubmit").closest("form").submit();
				});

				if (i>0)
				{
                    theForm.after_show( opt.identifier );
					for (var i=0;i<items.length;i++)
					{
						items[i].after_show();
					}	
					
					$.tzfdform.showHideDep(
						{
							'formIdentifier' : opt.identifier, 
							'throwEvent'	 : true
						}	
					);
					
					$( '#fieldlist'+opt.identifier).find(".depItemSel,.depItem").bind("change", { 'identifier' : opt.identifier }, function( evt ) 
						{
							$.tzfdform.showHideDep(
								{
									'formIdentifier' : evt.data.identifier, 
									'throwEvent'	 : true
								}	
							);
						});
					try 
					{
						$( "#tzfdform"+opt.identifier ).tooltip({show: false,hide:false,tooltipClass:"uh-tooltip",position: { my: "left top", at: "left bottom+5", collision: "none"  },items: "[uh]",content: function (){return $(this).attr("uh");} });
					} catch(e){}
                }
                $("#fieldlist"+opt.identifier+" .pbreak:not(.pb0)").find(".field").addClass("ignorepb");
                if ( theForm.autofocus)
                {
                    var ff  = $("#fieldlist"+opt.identifier+" .pbreak.pb0").find(":focusable:first");
				    if( ff &&
				    	ff.attr('type') != 'radio' &&
				    	ff.attr('type') != 'checkbox' &&
				    	ff.closest('[uh]').length == 0 /* FIXES AUTO-OPEN TOOLTIPS */
				    ) ff.focus();
			    }
            };
			
		var fform=function(){};
		$.extend(fform.prototype,
			{
				title:"Untitled Form",
				description:"This is my form. Please fill it out. It's awesome!",
				formlayout:"top_aligned",
				formtemplate:"",
                evalequations:1,
                autocomplete:1,
                autofocus:false,
				show:function(){
                     return '<div class="fform" id="field">'+(this.title!=''?'<h1>'+this.title+'</h1>':'')+(this.description!=''?'<span>'+this.description+'</span>':'')+'</div>';
				},
                after_show:function( id ){
                    $( '#tzfd_calculatedfieldsf_pform'+id ).attr( 'data-evalequations', this.evalequations ).attr( 'autocomplete', ( ( this.autocomplete ) ? 'on' : 'off' ) );
                }
			});
		
		//var theForm = new fform(),
		var theForm,
			ffunct = {
				getItem: function( name )
					{
						for( var i in items )
						{
							if( items[ i ].name == name )
							{
								return items[ i ];
							}
						}
						return false;
					},
				getItems: function() 
					{
					   return items;
					},
				loadData:function(f) {
                    var d, e;
                    
                    // 1. Identify if we are inside the Gutenberg iframe or the main document
                    var $iframe = jQuery('iframe[name="editor-canvas"]');
                    var $context = $iframe.length ? $iframe.contents() : jQuery(document);
                    
                    // 2. Search for the element specifically within that context
                    e = jQuery("#" + f, $context);
                    
                    try {
                        // 3. Extract the value and ensure it is not undefined/empty before parsing
                        var formVal = e.val();
                        
                        if (formVal) {
                            this.formId = e.parents("form").attr("id");
                            d = jQuery.parseJSON(formVal);
                            
                            if (d && 2 == d.length) {
                                items = [];
                                const allowedTypes = ["ftext", "fnumber", "femail", "fdate", "ftextarea", "fcheck","fradio", "fdropdown", "ffile", "fpassword", "fPhone","fCommentArea", "fSectionBreak", "fPageBreak","fapp","facceptance"]; 
                                
                                for(var i = 0; i < d[0].length; i++) {
                                    if (allowedTypes.includes(d[0][i].ftype)) {
                                        var obj = new jQuery.tzfdform.controls[d[0][i].ftype]();
                                        obj = jQuery.extend(!0, {}, obj, d[0][i]);
                                        obj.name = obj.name + opt.identifier;
                                        obj.form_identifier = opt.identifier;
                                        obj.init();
                                        items[items.length] = obj;
                                    }
                                }
                                
                                theForm = new fform();
                                theForm = jQuery.extend(theForm, d[1][0]);
                                reloadItemsPublic();
                            }
                        }
                    } catch(err) {
                        console.log("JSON Parse Error:", err);
                    }
                    
                    void 0 !== window.tzfdcff_load_defaults && window.tzfdcff_load_defaults();
                }
			};

		$.tzfdform[ 'forms' ][ opt.identifier ] = ffunct;
	    this.fBuild = ffunct;
	    return this;
	}; 

	$.tzfdform[ 'showSettings' ] = {
		formlayoutList : [{id:"top_aligned",name:"Top Aligned"},{id:"left_aligned",name:"Left Aligned"},{id:"right_aligned",name:"Right Aligned"},{id:"center_aligned",name:"Center Aligned"}]
	};
	
	$.tzfdform.controls[ 'ffields' ] = function(){};
	$.extend($.tzfdform.controls[ 'ffields' ].prototype, 
		{
				form_identifier:"",
				name:"",
				shortlabel:"",
				index:-1,
				ftype:"",
				userhelp:"",
				userhelpTooltip:false,
				csslayout:"",
				init:function(){},
				show:function()
					{
						return 'Not available yet';
					},
				after_show:function(){},
				val:function(){
					var e = $( "[id='" + this.name + "']:not(.ignore)" );
					if( e.length )
					{
                        return $.tzfdform.parseVal( $.trim( e.val() ) );
					}
					return 0;
				}
		});
	
	$.tzfdform[ 'showHideDep' ] = function( configObj )
		{
			if( typeof configObj[ 'formIdentifier' ] !== 'undefined' )
			{
				var identifier = configObj[ 'formIdentifier' ];
				
				if( typeof  $.tzfdform[ 'forms' ][ identifier ] != 'undefined' )
				{
					var toShow = [],
						toHide = [],
						items = $.tzfdform[ 'forms' ][ identifier ].getItems();
						
					for( var i = 0, h = items.length; i < h; i++ )
					{
						if( typeof items[ i ][ 'showHideDep' ] != 'undefined' )
						{
							items[ i ][ 'showHideDep' ]( toShow, toHide );
						}
					}
					
					if( typeof configObj[ 'throwEvent' ] == 'undefined' || configObj[ 'throwEvent' ] )
					{
						$( document ).trigger( 'showHideDepEvent', $.tzfdform[ 'forms' ][ identifier ][ 'formId' ] );
					}	
				}
			}	
		};
		
    // Page boundaries contain no submitted value; retain the existing pagination path.
    $.tzfdform.controls.fPageBreak = function() {};
    $.extend($.tzfdform.controls.fPageBreak.prototype, $.tzfdform.controls.ffields.prototype, {ftype:'fPageBreak'});
