/* Changed for Titan Zero Trades: Front Desk from Appointment Hour Booking 1.5.98 (GPLv2). Changes: see THIRD-PARTY-NOTICES.txt section 7. Changed 2026-11-15. */
var tz_fd_appointments_fpanel = function($){
            var tz_fd_counter = 0;
          	function loadWindow(){
          	    tz_fd_counter++;
                const formOptions = tz_fd_hour_formsclassic.forms;
                var myoptions = '';            
                for (var i=0; i<formOptions.length; i++)
                    myoptions += '<option value="'+formOptions[i].value+'">'+formOptions[i].label+'</option>';
          		$(' <div title="Titan Zero Trades: Front Desk"><div style="padding:20px;">'+
          		   'Select Calendar:<br /><select id="tz_fd_calendar_sel'+tz_fd_counter+'" name="tz_fd_calendar_sel'+tz_fd_counter+'">'+myoptions+'</select>'+
          		   '</div></div>'
          		  ).dialog({
          			dialogClass: 'wp-dialog',
                      modal: true,
                      closeOnEscape: true,
                      buttons: [
                          {text: "Insert", click: function() {
          						if(send_to_editor){
          							var id = $('#tz_fd_calendar_sel'+tz_fd_counter)[0].options[$('#tz_fd_calendar_sel'+tz_fd_counter)[0].options.selectedIndex].value;
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
         