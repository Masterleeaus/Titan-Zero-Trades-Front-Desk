		$.tzfdform.typeList.push(
			{
				id:"fnumber",
				name:"Number",
				control_category:3
			}
		);
        $.tzfdform.controls[ 'fnumber' ] = function(){};
		$.extend(
			$.tzfdform.controls[ 'fnumber' ].prototype,
			$.tzfdform.controls[ 'ffields' ].prototype,
			{
				title:"Number",
				ftype:"fnumber",
				predefined:"",
				predefinedClick:false,
				required:false,
				size:"small",
				thousandSeparator:"",
				decimalSymbol:".",
				min:"",
				max:"",
				dformat:"digits",
				formats:new Array("digits","number"),
				display:function()
					{
						return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
					},
				editItemEvents:function()
					{
						$.tzfdform.controls[ 'ffields' ].prototype.editItemEvents.call(this);
					}
		});