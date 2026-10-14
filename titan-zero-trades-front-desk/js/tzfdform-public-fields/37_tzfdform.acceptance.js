	$.tzfdform.controls[ 'facceptance' ]=function(){};
	$.extend(
		$.tzfdform.controls[ 'facceptance' ].prototype,
		$.tzfdform.controls[ 'ffields' ].prototype,
		{
			title:"Accept terms and conditions",
			ftype:"facceptance",
			value:"I accept",
			required:true,
			url:"",
			message:"",
			show:function()
				{
					var me = this,
						dlg = '',
						label = me.title;

					if(!/^\s*$/.test(me.url))
					{
						label = '<a href="'+$.tzfdform.htmlEncode($.trim(me.url))+'" target="_blank">'+label+'</a>';
					}
					else if(!/^\s*$/.test(me.message))
					{
						label = '<a href="#'+me.name+'_dialog" id="'+me.name+'_opener" class="cff-open-dlg" role="button" aria-haspopup="dialog" aria-controls="'+me.name+'_dialog" aria-expanded="false">'+label+'</a>';
						dlg += '<dialog id="'+me.name+'_dialog" class="cff-dialog hide" aria-labelledby="'+me.name+'_dialog_title"><h2 class="cff-dialog-title" id="'+me.name+'_dialog_title">'+$.tzfdform.htmlEncode(me.title)+'</h2><button type="button" class="cff-close-dlg" aria-label="'+$.tzfdform.htmlEncode(typeof tzfd_hour_close_label !== 'undefined' ? tzfd_hour_close_label : 'Close')+'">&#215;</button><div class="cff-dialog-content">'+me.message+'</div></dialog>';
					}
					return '<div class="fields '+$.tzfdform.htmlEncode(me.csslayout)+' cff-checkbox-field" id="field'+me.form_identifier+'-'+me.index+'"><div class="dfield">'+
					'<div class="one_column"><label><input name="'+me.name+'" id="'+me.name+'" class="field required" value="'+$.tzfdform.htmlEncode(me.value)+'" vt="'+$.tzfdform.htmlEncode((/^\s*$/.test(me.value)) ? me.title : me.value)+'" type="checkbox" /> <span>'+
					$.tzfdform.htmlDecode( label )+''+((me.required)?'<span class="r">*</span>':'')+
					'</span></label></div>'+
					dlg+
					'</div><div class="clearer"></div></div>';
				},
			after_show:function()
                {
                    $.tzfdform.enhanceAcceptanceDialog(document.getElementById('field'+this.form_identifier+'-'+this.index));
                },
            val:function()
				{
					var e = $('[id="'+this.name+'"]:checked:not(.ignore)');
					if( e.length )
					{
						var t = $.tzfdform.parseValStr( e[0].value );
						if(!$.isNumeric(t)) t = t.replace(/^"/,'').replace(/"$/,'');
					}
					return (t) ? (($.isNumeric(t)) ? t : '"'+t+'"') : 0;
				}
		}
	);