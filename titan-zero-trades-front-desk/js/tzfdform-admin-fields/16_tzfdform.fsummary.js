	$.tzfdform.typeList.push(
		{
			id:"fsummary",
			name:"Summary",
			control_category:3
		}
	);
	$.tzfdform.controls[ 'fsummary' ] = function(){};
	$.extend(
		$.tzfdform.controls[ 'fsummary' ].prototype,
		$.tzfdform.controls[ 'ffields' ].prototype,
		{
			title:"Summary",
			ftype:"fsummary",
			fields:"",
			titleClassname:"summary-field-title",
			valueClassname:"summary-field-value",
			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'ffields' ].prototype.editItemEvents.call(this);
				}
	});