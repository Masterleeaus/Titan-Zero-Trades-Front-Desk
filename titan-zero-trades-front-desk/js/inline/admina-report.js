/*
 * Titan Zero Trades: Front Desk, stats report (admin only).
 * Moved out of the inline <script> blocks in tzfd-admin-int-report.inc.php (review item R2).
 * Needs Chart.js (tzfdstatschart) and jQuery UI datepicker, enqueued by the admin screen.
 */

 function do_dexapp_print()
 {
      w=window.open();
      // DOM copy instead of document.write(innerHTML): the page's nodes are cloned, nothing is re-parsed as HTML.
      var printStyle = w.document.createElement('style');
      printStyle.textContent = ".tzfdnopr{display:none;};table{border:2px solid black;width:100%;}th{border-bottom:2px solid black;text-align:left}td{padding-left:10px;border-bottom:1px solid black;}";
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
                    dateFormat: 'yy-mm-dd'
                 });
 	$tzfd_j("#dto").datepicker({
                    dateFormat: 'yy-mm-dd'
                 });
 });

var $ = jQuery.noConflict();
$tzfd_j(document).ready(function(){
Chart.defaults.set({
            elements: {
                line: {
                    borderWidth: 2, // Set global borderWidth for line charts,
                    tension: 0.4
                }
            }
        });
Chart.defaults.set({
    responsive: true, // Enable responsive behavior
    maintainAspectRatio: false, // Set global maintainAspectRatio to false
    scales: {
        y: {
            beginAtZero: true, // Start the Y-axis at zero
            ticks: {
                precision: 0
            }
        }
    },
    plugins: {
        legend: {
            display: false // Disable the legend
        },
    },    
});
drawChartDaily($("#chartDailyIncoming"));
drawChartDaily($("#chartDaily"));
drawChartWeekly($("#chartWeeklyIncoming"));
drawChartWeekly($("#chartWeekly"));
drawChartMonthly($("#chartMonthlyIncoming"));
drawChartMonthly($("#chartMonthly"));
function drawChartMonthly(obj)
{
    function getLabels(obj)
    {       
        for (var i = 0; i < obj.length; i++)
            obj[i] = $.datepicker.formatDate( ((i==0 || (i==obj.length - 1))?"M yy":"M"),$.datepicker.parseDate("yymmdd", obj[i]+"01"));
        return obj;
    }
    new Chart(obj, {
        type: 'line',
        data: {
          labels: getLabels(obj.attr("x").split(',')),
          datasets: [{
            label: obj.attr("label"),
            data: obj.attr("y").split(','),
            startdate: obj.attr("x").split(',')
          }]
        },         
        options: { 
            plugins: {
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            return $.datepicker.formatDate( "M yy",$.datepicker.parseDate("yymmdd", context[0].dataset.startdate[context[0].parsed.x]+"01"));;
                        }
                    }
                }
            }
        }   
      });    
}
function drawChartWeekly(obj)
{
    function getLabels(obj)
    {       
        for (var i = 0; i < obj.length; i++)
            obj[i] =((i==0 || (i==obj.length - 1))?obj[i].substring(0, 4)+ " Week ":"") + obj[i].substring(4, 6);
        return obj;
    }
    new Chart(obj, {
        type: 'line',
        data: {
          labels: getLabels(obj.attr("x").split(',')),
          datasets: [{
            label: obj.attr("label"),
            data: obj.attr("y").split(','),
            startdate: obj.attr("x").split(',')
          }]
        },         
        options: { 
            plugins: {
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            var s = context[0].dataset.startdate[context[0].parsed.x];
                            return s.substring(0, 4)+ " Week #" + s.substring(4, 6);
                        }
                    }
                }
            }
        }   
      });    
}
function drawChartDaily(obj)
{
    function getLabels(obj)
    {       
        for (var i = 0; i < obj.length; i++)
            obj[i] = $.datepicker.formatDate( ((i==0 || (i==obj.length - 1))?"M dd":"dd"),$.datepicker.parseDate("yymmdd", obj[i]));
        return obj;
    }
    new Chart(obj, {
        type: 'line',
        data: {
          labels: getLabels(obj.attr("x").split(',')),
          datasets: [{
            label: obj.attr("label"),
            data: obj.attr("y").split(','),
            startdate: obj.attr("x").split(',')
          }]
        },         
        options: { 
            plugins: {
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            return $.datepicker.formatDate( "M d, yy",$.datepicker.parseDate("yymmdd", context[0].dataset.startdate[context[0].parsed.x]));;
                        }
                    }
                }
            }
        }   
      });    
}           
            
chartperday($("#chartperday")); 
chartperday($("#chartrevenue")); // --- MODIFICATION: Initialize Revenue Chart

function chartperday(obj)
{           
    var data = jQuery.parseJSON(obj.attr("questions"));
    var st = $.datepicker.parseDate("yy-mm-dd", obj.attr("st"));
    var et = $.datepicker.parseDate("yy-mm-dd", obj.attr("et"));
    var l = [];
    while (st < et)
    {
        l[l.length] = $.datepicker.formatDate("yy-mm-dd", st);
        st.setDate(st.getDate() + 1);
    }        
    new Chart(obj, {
        type: 'line',
        data: {
          labels: l,
          datasets: [{
            label: obj.attr("label"),
            data: data[0].values,
            borderColor: data[0].color, // --- MODIFICATION: Allow custom color from data
            backgroundColor: data[0].color // --- MODIFICATION: Allow custom color from data
          }]
        },
      });    
}    
chartperhour($("#chartperhour"));
function chartperhour(obj)
{           
    var data = jQuery.parseJSON(obj.attr("questions"));
    var l = [];
        for (var i = 0; i < 24; i++)
            l[l.length] = i;
    new Chart(obj, {
        type: 'line',
        data: {
          labels: l,
          datasets: [{
            label: obj.attr("label"),
            data: data[0].values
          }]
        },
      });    
}
});

// Print button and the report field selector (formerly onclick / onchange attributes).
(function () {
    var printButton = document.getElementById('tzfd-print-stats');
    if (printButton) printButton.addEventListener('click', function () { do_dexapp_print(); });

    var fieldSelect = document.querySelector('form[name="cfm_formrep"] select[name="field"]');
    if (fieldSelect) fieldSelect.addEventListener('change', function () { document.cfm_formrep.submit(); });
})();
