	$.tzfdform.typeList.push(
		{
			id:"fnumberds",
			name:"Number DS",
			control_category:20
		}
	);
	$.tzfdform.controls[ 'fnumberds' ]=function(){  this.init();  };
	$.extend(
		$.tzfdform.controls[ 'fnumberds' ].prototype,
		$.tzfdform.controls[ 'fnumber' ].prototype,
		{
			ftype:"fnumberds",
			init : function()
				{				
					$.extend(true, this, new $.tzfdform.controls[ 'datasource' ]() );
				},
			display:function()
				{
					return $.tzfdform.controls[ 'fnumber' ].prototype.display.call(this);
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'fnumber' ].prototype.editItemEvents.call(this);
					this.editItemEventsDS();
				},
			showAllSettings:function()
				{
					return $.tzfdform.controls[ 'fnumber' ].prototype.showAllSettings.call(this)+this.showDataSource( [ 'database', 'posttype', 'taxonomy', 'user' ], 'single' );
				},
			showPredefined : function()
				{
					return '';
				}
		}
	);