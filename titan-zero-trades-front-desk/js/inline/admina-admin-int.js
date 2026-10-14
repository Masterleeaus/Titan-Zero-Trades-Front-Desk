/*
 * Titan Zero Trades: Front Desk, calendar edit screen (admin only).
 * Moved out of the inline <script> blocks in tzfd-admin-int.inc.php (review item R2).
 * Server values are read from tzfdAdminIntData, set with wp_localize_script():
 *   builderUrl  - builder fallback script URL
 *   captchaUrl  - captcha image base URL
 */

function update_tzfd_option()
{
    var seloption = document.tzfdformconf.fp_emailtomethod.selectedIndex;
    if (seloption == 0)
    {
        document.getElementById("tzfd_destemails").style.display = '';
        document.getElementById("tzfd_dropemails").style.display = 'none';
    }
    else
    {
        document.getElementById("tzfd_destemails").style.display = 'none';
        document.getElementById("tzfd_dropemails").style.display = '';
    }
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

    // "Next Step" buttons: formerly onclick="tzfdGoToStep(N);".
    $(document).on('click', '[data-tzfd-goto]', function(){
        tzfdGoToStep(parseInt(this.getAttribute('data-tzfd-goto'), 10));
    });
});

if (typeof jQuery === "undefined") {
    throw new Error("Titan Zero Trades: Front Desk admin dependencies failed to load WordPress jQuery.");
}
window.$easyFormQuery = jQuery.noConflict();
if (typeof $tzfdformloadedflag == 'undefined')
{
    // Same-origin builder fallback, kept from the original template.
    document.write ("<"+"script type='text/javascript' src='" + tzfdAdminIntData.builderUrl + "'></"+"script>");
}

$easyFormQuery(document).ready(function() {
    var f = $easyFormQuery("#tzfdform").tzfdform();
    window['cff_form'] = f;
    f.fBuild.loadData("form_structure", "templates");

    $easyFormQuery("#saveForm").click(function() {
        f.fBuild.saveData("form_structure");
    });

    $easyFormQuery(".itemForm").click(function() {
        f.fBuild.addItem($easyFormQuery(this).attr("id"));
    });

    $easyFormQuery( ".itemForm" ).draggable({revert1: "invalid",helper: "clone",cursor: "move"});
    $easyFormQuery( "#tzfdform" ).droppable({
        accept: ".button",
        drop: function( event, ui ) {
            f.fBuild.addItem(ui.draggable.attr("id"));
        }
    });
});

var randcaptcha = 1;
function generateCaptcha()
{
    try
    {
        var d=new Date();
        var f = document.tzfdformconf;
        var qs = "&width="+f.cv_width.value;
        qs += "&height="+f.cv_height.value;
        qs += "&letter_count="+f.cv_chars.value;
        qs += "&min_size="+f.cv_min_font_size.value;
        qs += "&max_size="+f.cv_max_font_size.value;
        qs += "&noise="+f.cv_noise.value;
        qs += "&noiselength="+f.cv_noise_length.value;
        qs += "&bcolor="+f.cv_background.value.replace('#','');
        qs += "&border="+f.cv_border.value.replace('#','');
        qs += "&font="+f.cv_font.options[f.cv_font.selectedIndex].value;
        qs += "&r="+(randcaptcha++);

        document.getElementById("captchaimg").src = tzfdAdminIntData.captchaUrl + qs;
    } catch (e) {}
}

// Shows a warning under the "from" email field. Text only (no markup): the message is set with textContent.
function tzfd_setEmailWarning(tagName, style, text)
{
    var box = document.getElementById("tzfd_email_warning");
    box.textContent = "";
    if (text === "") return;
    var el = document.createElement(tagName);
    if (style !== "") el.setAttribute("style", style);
    el.textContent = text;
    box.appendChild(el);
}
function tzfd_checkfromemail ()
{
    var em = document.getElementById("fp_from_email").value;
    var emtr = em.trim();
    if (em != emtr)
        document.getElementById("fp_from_email").value = emtr;
    if (em == '')
    {
        tzfd_setEmailWarning("span", "color:red;font-weight:bold;", "Warning! This field is required and must contain a valid email address.");
    }
    else
    {
        emtr = emtr.toLowerCase();
        var idvalid = emtr.match(
          /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/
        );
        if (!idvalid)
            tzfd_setEmailWarning("span", "color:red", "Please be sure that this field contains a valid email address.");
        else
        {
            if (emtr.match(
                  /^(.*?(\bgmail\.com\b))$/
            ) || emtr.match(/^(.*?(\baol\.com\b))$/) || emtr.match(/^(.*?(\bhotmail\.com\b))$/) || emtr.match(/^(.*?(\byahoo\.com\b))$/) || emtr.match(/^(.*?(\boutlook\.com\b))$/))
            {
                tzfd_setEmailWarning("div", "color:#006699;max-width:500px;border:1px dotted gray;padding:10px;", 'Note that if you use a "from" email from a public email service like "@hotmail.com", "@aol.com", "@outlook.com", "@gmail.com", ... it will be filtered by most antispam systems since the email will be identified as "not originated at the expected server". It is strongly recommended to use a "from" address that belongs to the website domain.');
            }
            else
                tzfd_setEmailWarning("span", "", "");
        }
    }
}

// Event bindings that used to be onkeyup / onchange / onblur attributes in the template.
jQuery(function(){
    var $ = jQuery;
    $('#fp_from_email').on('keyup', function(){ tzfd_checkfromemail(); });
    $('select[name="fp_emailtomethod"]').on('change', function(){ update_tzfd_option(); });
    $('.tzfd-captcha-field').on('blur', function(){ generateCaptcha(); });
    $('select[name="cv_font"]').on('change', function(){ generateCaptcha(); });
});

jQuery( function() {
    jQuery( "#admin-tabs" ).tabs();
} );

generateCaptcha();
tzfd_checkfromemail();
