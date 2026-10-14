var tzfd_appointments_fpanel = function($){
            var tzfd_counter = 0;
          	function loadWindow(){
          	    tzfd_counter++;
                const formOptions = tzfdhour_formsclassic.forms;
                var myoptions = '';            
                for (var i=0; i<formOptions.length; i++)
                    myoptions += '<option value="'+formOptions[i].value+'">'+formOptions[i].label+'</option>';
          		$(' <div title="Titan Zero Trades: Front Desk"><div style="padding:20px;">'+
          		   'Select Calendar:<br /><select id="tzfd_calendar_sel'+tzfd_counter+'" name="tzfd_calendar_sel'+tzfd_counter+'">'+myoptions+'</select>'+
          		   '</div></div>'
          		  ).dialog({
          			dialogClass: 'wp-dialog',
                      modal: true,
                      closeOnEscape: true,
                      buttons: [
                          {text: "Insert", click: function() {
          						if(send_to_editor){
          							var id = $('#tzfd_calendar_sel'+tzfd_counter)[0].options[$('#tzfd_calendar_sel'+tzfd_counter)[0].options.selectedIndex].value;
                                    send_to_editor('[TZFD_FRONT_DESK id="'+id+'"]');
          						}
          						$(this).dialog("close");
          				}}
                      ]
                  });
          	}
          	var obj = {};
          	obj.open = loadWindow;
          	return obj;
          }(jQuery);
         