	$.tzfdform.typeList.push(
		{
			id:"fdiv",
			name:"Div",
			control_category:10
		}
	);
	$.tzfdform.controls[ 'fdiv' ]=function(){};
	$.extend(
		$.tzfdform.controls[ 'fdiv' ].prototype,
		$.tzfdform.controls[ 'fcontainer' ].prototype,
		{
			title: 'div', 
			ftype:"fdiv",
			fields:[],
			columns:1,
			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'fcontainer' ].prototype.editItemEvents.call(this);
				}
	});