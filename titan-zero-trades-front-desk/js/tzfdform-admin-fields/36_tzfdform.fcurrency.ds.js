	$.tzfdform.typeList.push(
		{
			id:"fcurrencyds",
			name:"Currency DS",
			control_category:20
		}
	);
	$.tzfdform.controls[ 'fcurrencyds' ]=function(){  this.init();  };
	$.extend(
		$.tzfdform.controls[ 'fcurrencyds' ].prototype,
		$.tzfdform.controls[ 'fcurrency' ].prototype,
		{
			ftype:"fcurrencyds",
			init : function()
				{				
					$.extend(true, this, new $.tzfdform.controls[ 'datasource' ]() );
				},
			display:function()
				{
					return $.tzfdform.controls[ 'fcurrency' ].prototype.display.call(this);
				},
			editItemEvents:function()
				{
					$.tzfdform.controls[ 'fcurrency' ].prototype.editItemEvents.call(this);
					this.editItemEventsDS();
				},
			showAllSettings:function()
				{
					return $.tzfdform.controls[ 'fcurrency' ].prototype.showAllSettings.call(this)+this.showDataSource( [ 'database', 'posttype', 'taxonomy', 'user' ], 'single' );
				},
			showPredefined : function()
				{
					return '';
				}
		}
	);