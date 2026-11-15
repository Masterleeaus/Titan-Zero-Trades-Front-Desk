	$.tzfdform.typeList.push(
		{
			id:"fdate",
			name:"Date Time",
			control_category:3
		}
	);
	$.tzfdform.controls[ 'fdate' ] = function(){};
	$.extend(
		$.tzfdform.controls[ 'fdate' ].prototype,
		$.tzfdform.controls[ 'ffields' ].prototype,
		{
			title:"Date",
			ftype:"fdate",
			predefined:"",
			predefinedClick:false,
			size:"medium",
			required:false,
			dformat:"mm/dd/yyyy",
			tformat:"24",
			showDropdown:false,
			dropdownRange:"-10:+10",

			minDate:"",
			maxDate:"",
            invalidDates:"",
			minHour:0,
			maxHour:23,
			minMinute:0,
			maxMinute:59,

			stepHour: 1,
			stepMinute: 1,

			showTimepicker: false,

			defaultDate:"",
			defaultTime:"",
			working_dates:[true,true,true,true,true,true,true],

			formats:new Array("mm/dd/yyyy","dd/mm/yyyy"),

			display:function()
				{
					return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'ffields' ].prototype.editItemEvents.call(this);
				}
	});