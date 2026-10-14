	$.tzfdform.typeList.push(
		{
			id:"fMedia",
			name:"Media",
			control_category:3
		}
	);
	$.tzfdform.controls[ 'fMedia' ]=function(){};
	$.extend(
		$.tzfdform.controls[ 'fMedia' ].prototype,
		$.tzfdform.controls[ 'ffields' ].prototype,
		{
			ftype:"fMedia",
            sMediaType:"image", // image, audio, video
            display:function()
				{
					return '<div class="fields fmark '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'" style><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+this[ '_display_' + this.sMediaType ]()+'</label><span class="uh">'+this.data[ this.sMediaType ][ 'sFigcaption' ]+'</span><div class="clearer"></div></div>';
				},
			editItemEvents:function()
				{
                   					$.tzfdform.controls[ 'fPhone' ].prototype.editItemEvents.call(this);
				}
	});