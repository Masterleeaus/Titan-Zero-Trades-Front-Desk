	$.tzfdform.typeList.push(
		{
			id:"fdropdownds",
			name:"Dropdown DS",
			control_category:20
		}
	);
	$.tzfdform.controls[ 'fdropdownds' ] = function(){ this.init(); };
	$.extend(
		$.tzfdform.controls[ 'fdropdownds' ].prototype,
		$.tzfdform.controls[ 'fdropdown' ].prototype,
		{
			ftype:"fdropdownds",
			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'fdropdown' ].prototype.editItemEvents.call(this);						
				}
		}
	);