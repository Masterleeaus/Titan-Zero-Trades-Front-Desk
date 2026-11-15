	$.tzfdform.typeList.push(
		{
			id:"ftext",
			name:"Single Line",
			control_category:1
		}
	);
	$.tzfdform.controls[ 'ftext' ]=function(){};
	$.extend(
		$.tzfdform.controls[ 'ftext' ].prototype,
		$.tzfdform.controls[ 'ffields' ].prototype,
		{
			title:"Untitled",
			ftype:"ftext",
			predefined:"",
			predefinedClick:false,
			required:false,
			size:"medium",
			minlength:"",
			maxlength:"",
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
					$("#sMinlength").bind("change", {obj: this}, function(e) 
						{
							e.data.obj.minlength = $(this).val();
							$.tzfdform.reloadItems();
						});
					$("#sMaxlength").bind("change", {obj: this}, function(e) 
						{
							e.data.obj.maxlength = $(this).val();
							$.tzfdform.reloadItems();
						});
					$("#sEqualTo").bind("change", {obj: this}, function(e) 
						{
							e.data.obj.equalTo = $(this).val();
							$.tzfdform.reloadItems();
						});
					var items = this.fBuild.getItems();
					$('.equalTo').each(function(){
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
					return '<div class="column"><label>Min length/characters</label><br /><input name="sMinlength" id="sMinlength" value="'+$.tzfdform.htmlEncode(this.minlength)+'"></div><div class="column"><label>Max length/characters</label><br /><input name="sMaxlength" id="sMaxlength" value="'+$.tzfdform.htmlEncode(this.maxlength)+'"></div><div class="clearer"></div>';
				}
	});