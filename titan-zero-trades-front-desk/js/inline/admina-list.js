/*
 * Titan Zero Trades: Front Desk, calendars list (admin only).
 * Moved out of the inline <script> block in tzfd-admin-int-list.inc.php (review item R2).
 * Server values come from tzfdListData, set with wp_localize_script():
 *   page     - admin menu slug
 *   nonce    - list nonce for the GET actions
 *   redirect - set after a new calendar is created; empty otherwise
 * Buttons carry data-tzfd-action (function name) and data-tzfd-id (calendar id).
 */

function tzfd_addItem()
{
    var calname = document.getElementById("tzfd_itemname").value;
    document.location = 'admin.php?page=' + tzfdListData.page + '&anonce=' + tzfdListData.nonce + '&a=1&r=' + Math.random() + '&name=' + encodeURIComponent(calname);
    return false;
}

function tzfd_updateItem(id)
{
    var calname = document.getElementById("calname_" + id).value;
    document.location = 'admin.php?page=' + tzfdListData.page + '&anonce=' + tzfdListData.nonce + '&u=' + id + '&r=' + Math.random() + '&name=' + encodeURIComponent(calname);
}

function tzfd_cloneItem(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&anonce=' + tzfdListData.nonce + '&c=' + id + '&r=' + Math.random();
}

function tzfd_manageSettings(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&r=' + Math.random();
}

function tzfd_publish(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&pwizard=1&cal=' + id + '&r=' + Math.random();
}

function tzfd_addbk(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&addbk=1&cal=' + id + '&r=' + Math.random();
}

function tzfd_blocktimes(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&blocktimes=1&cal=' + id + '&r=' + Math.random();
}

function tzfd_viewMessages(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&list=1&r=' + Math.random();
}

function tzfd_viewSchedule(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&schedule=1&calendarview=1&r=' + Math.random();
}

function tzfd_viewReport(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&report=1&r=' + Math.random();
}

function tzfd_deleteItem(id)
{
    if (confirm('Are you sure that you want to delete this item?'))
    {
        document.location = 'admin.php?page=' + tzfdListData.page + '&anonce=' + tzfdListData.nonce + '&d=' + id + '&r=' + Math.random();
    }
}

(function () {
    var handlers = {
        tzfd_addItem: tzfd_addItem,
        tzfd_updateItem: tzfd_updateItem,
        tzfd_cloneItem: tzfd_cloneItem,
        tzfd_manageSettings: tzfd_manageSettings,
        tzfd_publish: tzfd_publish,
        tzfd_addbk: tzfd_addbk,
        tzfd_blocktimes: tzfd_blocktimes,
        tzfd_viewMessages: tzfd_viewMessages,
        tzfd_viewSchedule: tzfd_viewSchedule,
        tzfd_viewReport: tzfd_viewReport,
        tzfd_deleteItem: tzfd_deleteItem
    };

    // Replaces the former onclick="tzfd_xxx(id);" attributes.
    document.addEventListener('click', function (event) {
        if (!event.target || !event.target.closest) return;
        var button = event.target.closest('[data-tzfd-action]');
        if (!button) return;
        var handler = handlers[button.getAttribute('data-tzfd-action')];
        if (handler) handler(button.getAttribute('data-tzfd-id'));
    });

    // Set after a new calendar is created (formerly an inline redirect script).
    if (tzfdListData.redirect) {
        document.location = tzfdListData.redirect;
    }
})();
