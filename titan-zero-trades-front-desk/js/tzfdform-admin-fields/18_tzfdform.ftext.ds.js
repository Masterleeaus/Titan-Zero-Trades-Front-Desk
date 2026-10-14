	$.tzfdform.typeList.push(
		{
			id:"ftextds",
			name:"Line Text DS",
			control_category:20
		}
	);
	$.tzfdform.controls[ 'ftextds' ]=function(){  this.init();  };
	$.extend(
		$.tzfdform.controls[ 'ftextds' ].prototype,
		$.tzfdform.controls[ 'ftext' ].prototype,
		{
			ftype:"ftextds",
			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'ftext' ].prototype.editItemEvents.call(this);					
				}
		}
	);