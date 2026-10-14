/*
 * Titan Zero Trades: Front Desk, block times screen (admin only).
 * Moved out of the inline <script> block in tzfd-admin-int-block-times.inc.php (review item R2).
 * Server strings come from tzfdBlockTimesData, set with wp_localize_script().
 */

function validateblockedmultiple() {
    if (document.getElementById("bdate").value == '')
    {
        alert(tzfdBlockTimesData.selectDateMessage);
        return false;
    }
    else
        return true;
}

jQuery(function(){
    var $ = jQuery;
    $(document).on('click', '.tzfd-step', function(){
        var s = $(this).data('step');
        tzfdGoToStep(s);
    });

    window['tzfdGoToStep'] = function(s){
        $('.tzfd-step.tzfd-step-active').removeClass('tzfd-step-active');
        $('.tzfd-step[data-step="'+s+'"]').addClass('tzfd-step-active');
        $('.tzfd-adintsection.tzfd-adintsection-active').removeClass('tzfd-adintsection-active');
        $('.tzfd-adintsection[data-step="'+s+'"]').addClass('tzfd-adintsection-active');
        $(window).scrollTop( $("#topadminsection").offset().top );
    };

    // Formerly onsubmit="return validateblockedmultiple();" on the form.
    $('#tzfd_frontdesk_pform_2').on('submit', function(){
        return validateblockedmultiple();
    });

    var dates = [];
    $("#tzfd_bt_datepicker").datepicker({
        dateFormat: 'yy-mm-dd',
        firstDay: 0,
        beforeShowDay: function(d) {
            var dd = $.datepicker.formatDate("yy-mm-dd", d);
            for (var i = 0; i < dates.length; i++)
            {
                if (dates[i] == dd) {
                    return [true, 'tzfd_bt_selected'];
                }
            }
            setTimeout(function () {
                $(".ui-state-active").removeClass("ui-state-active");
            },20);
            return [true, ''];
        },
        onSelect: function(selectedDate) {
            if ($.inArray(selectedDate,dates) > -1)
                dates.splice($.inArray(selectedDate,dates), 1);
            else
                dates[dates.length] = selectedDate;
            $("#bdate").val(dates.join(","));
            $(".ui-state-active").removeClass("ui-state-active");
        }
    });
});

jQuery( function() {
    jQuery( "#admin-tabs" ).tabs();
} );
