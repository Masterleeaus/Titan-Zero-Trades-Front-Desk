/*
 * Titan Zero Trades: Front Desk, schedule screen (admin only).
 * Moved out of the inline <script> blocks in tzfd-admin-int-schedule.inc.php (review item R2).
 * Server values come from tzfdScheduleData, set with wp_localize_script().
 * Needs the calendar script (tzfdtslots-schedcal-tzfdcalendar) in calendar view, and jQuery UI datepicker.
 */

// Calendar view (formerly an inline script). Runs only when the calendar view is shown.
if (tzfdScheduleData.calendarView) {
 var pathCalendar = tzfdScheduleData.pathCalendar;
 var dc_subjects = "";var dc_locations = "";
             initMultiViewCal("cal" + parseInt(tzfdScheduleData.calendarId, 10), parseInt(tzfdScheduleData.calendarId, 10),
          {viewDay:true,
          viewWeek:true,
          viewMonth:true,
          viewNMonth:true,
          viewList:true,
          viewdefault:"week",
          numberOfMonths:12,
          showtooltip:false,
          tooltipon:0,
          shownavigate:false,
          url:"",
          target:0,
          start_weekday: parseInt(tzfdScheduleData.firstDay, 10),
          language:"en-GB",
          cssStyle:"cupertino",
          edition:true,
          btoday:true,
          dialogWidth: 330,
          bnavigation:true,
          brefresh:true,
          bnew:false,
          path:pathCalendar,
          userAdd:false,
          userEdit:false,
          userDel:false,
          userEditOwner:false,
          userDelOwner:false,
          showtooltipdwm:true,
          userOwner:0 ,cellheight:62 , palette:0, paletteDefault:"F00",
          paletteFull:["FFF","FCC","FC9","FF9","FFC","9F9","9FF","CFF","CCF","FCF","CCC","F66","F96","FF6","FF3","6F9","3FF","6FF","99F","F9F","BBB","F00","F90","FC6","FF0","3F3","6CC","3CF","66C","C6C","999","C00","F60","FC3","FC0","3C0","0CC","36F","63F","C3C","666","900","C60","C93","990","090","399","33F","60C","939","333","600","930","963","660","060","366","009","339","636","000","300","630","633","330","030","033","006","309","303"]});
}

 function do_dexapp_print()
 {
      w=window.open();
      // DOM copy instead of document.write(innerHTML): the page's nodes are cloned, nothing is re-parsed as HTML.
      var printStyle = w.document.createElement('style');
      printStyle.textContent = ".tzfd_field_header {font-weight: bold;background-color: #dcdcdc;}.tzfdapp_break { clear: both; }.tzfd_field_0, .tzfd_field_1,.tzfd_field_2, .tzfd_field_3,.tzfd_field_4, .tzfd_field_5,.tzfd_field_6, .tzfd_field_7,.tzfd_field_8, .tzfd_field_9,.tzfd_field_10, .tzfd_field_11{float: left; min-width: 80px;padding-right:11px;border-bottom: 1px dotted #777777;margin-left: 1px;     padding: 5px;margin: 2px;}.tzfd_field_0 {color: #44aa44;font-weight: bold; }.tzfd_field_1 {color: #aaaa44;font-weight: bold; }.tzfd_field_2{width:180px;}.tzfd_field_3{min-width: 20px;width:20px ;max-width:20px;}.tzfd_field_5{width:200px;overflow:hidden;}.tzfd_field_4,.tzfd_field_6{display:none;}.tzfdnopr{display:none;};table{border:2px solid black;width:100%;}th{border-bottom:2px solid black;text-align:left}td{padding-left:10px;border-bottom:1px solid black;}.tzfd_field_8 {display:none;}";
      w.document.head.appendChild(printStyle);
      Array.prototype.forEach.call(document.getElementById('dex_printable_contents').childNodes, function (node) {
          w.document.body.appendChild(node.cloneNode(true));
      });
      w.print();
      w.close();
 }

 var $tzfd_j = jQuery.noConflict();
 $tzfd_j(function() {
 	$tzfd_j("#dfrom").datepicker({
                    dateFormat: tzfdScheduleData.dateFormat
                 });
 	$tzfd_j("#dto").datepicker({
                    dateFormat: tzfdScheduleData.dateFormat
                 });
 });

// Print and "change view" buttons (formerly onclick attributes).
(function () {
    document.addEventListener('click', function (event) {
        if (!event.target || !event.target.closest) return;
        var button = event.target.closest('[data-tzfd-url]');
        if (button) document.location = button.getAttribute('data-tzfd-url');
    });

    var printButton = document.getElementById('tzfd-print-schedule');
    if (printButton) printButton.addEventListener('click', function () { do_dexapp_print(); });
})();
