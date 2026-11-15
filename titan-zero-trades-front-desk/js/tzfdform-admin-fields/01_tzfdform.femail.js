	$.tzfdform.typeList.push(
		{
			id:"femail",
			name:"Email",
			control_category:1
		}
	);
	$.tzfdform.controls[ 'femail'] = function(){};
	$.extend(
		$.tzfdform.controls[ 'femail' ].prototype,
		$.tzfdform.controls[ 'ffields' ].prototype,
		{
			title:"Email",
			ftype:"femail",
			predefined:"",
			predefinedClick:false,
			required:false,
			size:"medium",
			equalTo:"",
			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><input class="field disabled '+this.size+'" type="text" value="'+$.tzfdform.htmlEncode(this.predefined)+'"/><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$("#sSize").bind("change", {obj: this}, function(e) 
						{
							e.data.obj.size = $(this).val();
							$.tzfdform.reloadItems();
						});
					$("#sEqualTo").bind("change", {obj: this}, function(e) 
						{
							e.data.obj.equalTo = $(this).val();
							$.tzfdform.reloadItems();
						});
					var items = this.fBuild.getItems();
					$('.equalTo').each(function()
						{
							var str = '<option value="" '+(("" == $(this).attr("dvalue"))?"selected":"")+'></option>';
							for (var i=0;i<items.length;i++)
							{
								if ((items[i].ftype=="ftext" || items[i].ftype=="femail" || items[i].ftype=="fpassword") && (items[i].name != $(this).attr("dname")))
								{
									str += '<option value="'+items[i].name+'" '+((items[i].name == $(this).attr("dvalue"))?"selected":"")+'>'+(items[i].title)+'</option>';
								}	
							}		
							$(this).html(str);
						});
					$.tzfdform.controls[ 'ffields' ].prototype.editItemEvents.call(this);
				},
			showSpecialDataInstance: function() 
				{
					var str = "";
					return str;
				}
	});