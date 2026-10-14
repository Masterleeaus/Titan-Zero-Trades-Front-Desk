	$.tzfdform.typeList.push(
		{
			id:"fradiods",
			name:"Radio Btns DS",
			control_category:20
		}
	);
	$.tzfdform.controls[ 'fradiods' ] = function(){ this.init(); };
	$.extend(
		$.tzfdform.controls[ 'fradiods' ].prototype,
		$.tzfdform.controls[ 'fradio' ].prototype,
		{
			ftype:"fradiods",
			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'fradio' ].prototype.editItemEvents.call(this);						
				}
		}
	);