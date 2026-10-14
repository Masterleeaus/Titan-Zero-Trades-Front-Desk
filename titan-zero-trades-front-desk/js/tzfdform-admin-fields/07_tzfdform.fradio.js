		$.tzfdform.typeList.push(
			{
				id:"fradio",
				name:"Radio Buttons",
				control_category:3
			}
		);
		$.tzfdform.controls[ 'fradio' ] = function(){};
		$.extend(
			$.tzfdform.controls[ 'fradio' ].prototype,
			$.tzfdform.controls[ 'ffields' ].prototype,
			{
				title:"Select a Choice",
				ftype:"fradio",
				layout:"one_column",
				required:false,
				choiceSelected:"",
				showDep:false,
				display:function()
					{
						return '<div class="fields '+this.name+'" id="field'+this.form_identifier+'-'+this.index+'"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>'+$.tzfdform.htmlEncode(this.title)+''+((this.required)?"*":"")+'</label><div class="dfield"><span class="uh">'+$.tzfdform.htmlEncode(this.userhelp)+'</span></div><div class="clearer"></div></div>';
					},
				editItemEvents:function()
					{
						$.tzfdform.controls[ 'ffields' ].prototype.editItemEvents.call(this);
					}
		});