/*
 * Titan Zero Trades: Front Desk, single-day selection (calendarless) interface.
 * Loaded per booking form by addons/bookings/calendarless-interface.addon.php.
 * The six settings come from wp_localize_script() as window.tzfdCalendarless:
 * numberOfDays, numberOfSlots, calendarCSSClass, formatDate, str_more_slots, str_more_days.
 */
var tzfdCalendarlessCfg = window.tzfdCalendarless || {};
var numberOfDays = tzfdCalendarlessCfg.numberOfDays || 0;
var numberOfSlots = tzfdCalendarlessCfg.numberOfSlots || 0;
var calendarCSSClass = tzfdCalendarlessCfg.calendarCSSClass || '';
var formatDate = tzfdCalendarlessCfg.formatDate || '';
var str_more_slots = tzfdCalendarlessCfg.str_more_slots || '';
var str_more_days = tzfdCalendarlessCfg.str_more_days || '';
jQuery(document).one('showHideDepEvent', function(){
    (function($) {
        function initLinks(cal)
        {
            var newFields = '<div id="tzfd_links_'+cal+'"></div>';
            $("."+cal).find(".dfield .fieldCalendar").css("display","none");
            $("."+cal).find(".slotsCalendar").css("flex-wrap","wrap");
            $("."+cal).find(".dfield .fieldCalendar").after(newFields);
            $(document).on("click","#tzfd_links_"+cal+" div",function(){
                var monthSelected = $(this).attr("m");
                var daySelected = $(this).attr("d");
                if (daySelected * 1 < 10) daySelected = "0"+daySelected;
                if ($("."+cal+" .ui-datepicker-calendar td[class*='d"+monthSelected+"-"+daySelected+"'] a").length > 0)
                    $("."+cal+" .ui-datepicker-calendar td[class*='d"+monthSelected+"-"+daySelected+"'] a").click();
                $("#tzfd_links_"+cal+" div").removeClass("activelink");
                $(this).addClass("activelink");
                return false;
            });
        }
        function reloadSlots(cal)
        {
            if ($("."+cal).find(".slots").length==0)
                setTimeout(function(){return reloadSlots(cal);},100);
            else
            {
                var opts = '';
                var i = 0;
                $("."+cal+" .ui-datepicker-calendar td").not(".nonworking,.ui-state-disabled").each(function(){
                    var mm = new Date(parseInt($(this).attr("data-year")),parseInt($(this).attr("data-month")),1);
                    var monthSelected = $.datepicker.formatDate("yy-mm",mm);
                    mm.setDate($(this).text()*1);
                    opts += '<div class="datelink hide" n="0" href="" d="'+$(this).text()+'" m="'+monthSelected+'" i="'+i+'"><a>'+$.datepicker.formatDate(formatDate,mm)+'</a></div>';
                    i++;
                });
                opts += '<div class="more_days" i="0"><a>'+str_more_days+'</a></div>';
                $("#tzfd_links_"+cal).html(opts);
                showLinkDay();
                function showLinkDay()
                {
                    var start = parseInt($("."+cal).find(".more_days").attr("i"));
                    for (var i = 0; i < numberOfDays; i++)
                    {
                        $("#tzfd_links_"+cal+">div[i='"+(start+i)+"']").removeClass("hide");
                    }
                    $("."+cal).find(".more_days").attr("i",start+i);
                    if ($("#tzfd_links_"+cal+">.hide").length==0)
                        $("."+cal).find(".more_days").addClass("hide");
                }
                $(document).off("click",".more_days").on("click",".more_days",function(){
                    showLinkDay();
                    return false;
                });
                moveSlots(calendarCSSClass);
                $(document).on("afterOnChange", function(e,obj){
                   moveSlots(calendarCSSClass);
                });
            }
        }
        function moveSlots(cal)
        {
            var c = $("."+cal);
            var f = c.find(".slots").attr("d").split("-");
            c.find("#tzfd_links_"+calendarCSSClass+" div").removeClass("activelink");
            var selectedDate = c.find("#tzfd_links_"+calendarCSSClass+" div[d='"+parseInt(f[2])+"'][m='"+f[0]+"-"+f[1]+"']");
            selectedDate.addClass("activelink");
            var element = c.find('.slotsCalendar');
            selectedDate.append(element);
            if (element.find(".more_slots").length == 0)
            {
                var i = 0;
                element.find(".availableslot").each(function(){
                    $(this).attr("i",i).addClass("hide");
                    i++;
                });
                element.append('<div class="more_slots" i="0"><a>'+str_more_slots+'</a></div>');
                showSlots(false);
            }
            function showSlots(if_click)
            {
                var start = parseInt(selectedDate.attr("n"));
                var more = 0
                if (if_click || (start==0))
                    more = numberOfSlots;
                for (var i = 0; i < start+more; i++)
                    element.find(".availableslot[i='"+(i)+"']").removeClass("hide");
                selectedDate.attr("n",start+more);
                if (element.find(".availableslot.hide").length==0)
                    element.find(".more_slots").addClass("hide");
            }
            $(document).off("click",".more_slots").on("click",".more_slots",function(){
                    showSlots(true);
                    return false;
                });

        }
        initLinks(calendarCSSClass);
        reloadSlots(calendarCSSClass);

    })(jQuery);
});
