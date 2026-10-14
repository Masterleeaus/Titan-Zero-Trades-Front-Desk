	$.tzfdform.typeList.push(
		{
			id:"fhtml",
			name:"HTML content",
			control_category:3
		}
	);
	$.tzfdform.controls[ 'fhtml' ]=function(){  this.init();  };
	$.extend(
		$.tzfdform.controls[ 'fhtml' ].prototype,
		$.tzfdform.controls[ 'ffields' ].prototype,
		{
			ftype:"fhtml",
			fcontent: "",
			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'ffields' ].prototype.editItemEvents.call(this);
				}
		}
	);