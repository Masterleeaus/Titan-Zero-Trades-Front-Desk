/*
 * Titan Zero Trades: Front Desk, calendars list (admin only).
 * Moved out of the inline <script> block in tzfd-admin-int-list.inc.php (review item R2).
 * Server values come from tzfdListData, set with wp_localize_script():
 *   page     - admin menu slug
 *   nonce    - list nonce for POST-only calendar mutations
 *   redirect - set after a new calendar is created; empty otherwise
 * Buttons carry data-tzfd-action (function name) and data-tzfd-id (calendar id).
 */

// Submit state-changing operations as POST, never through navigable GET URLs.
function tz_fd_submitMutation(action, id, name) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'admin.php?page=' + encodeURIComponent(tzfdListData.page);
    function field(key, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = String(value);
        form.appendChild(input);
    }
    field('anonce', tzfdListData.nonce);
    field(action, action === 'a' ? '1' : id);
    if (action === 'a' || action === 'u') field('name', name);
    document.body.appendChild(form);
    form.submit();
}

function tz_fd_addItem() {
    var input = document.getElementById('tz_fd_itemname');
    if (!input || !input.value.trim()) return false;
    tz_fd_submitMutation('a', 0, input.value);
    return false;
}

function tz_fd_updateItem(id) {
    var input = document.getElementById('calname_' + id);
    if (!input || !input.value.trim()) return false;
    tz_fd_submitMutation('u', id, input.value);
    return false;
}

function tz_fd_cloneItem(id) {
    tz_fd_submitMutation('c', id, '');
}

function tz_fd_manageSettings(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&r=' + Math.random();
}

function tz_fd_publish(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&pwizard=1&cal=' + id + '&r=' + Math.random();
}

function tz_fd_addbk(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&addbk=1&cal=' + id + '&r=' + Math.random();
}

function tz_fd_blocktimes(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&blocktimes=1&cal=' + id + '&r=' + Math.random();
}

function tz_fd_viewMessages(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&list=1&r=' + Math.random();
}

function tz_fd_viewSchedule(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&schedule=1&calendarview=1&r=' + Math.random();
}

function tz_fd_viewReport(id)
{
    document.location = 'admin.php?page=' + tzfdListData.page + '&cal=' + id + '&report=1&r=' + Math.random();
}

function tz_fd_deleteItem(id) {
    if (confirm('Are you sure that you want to delete this item?')) {
        tz_fd_submitMutation('d', id, '');
    }
}

(function () {
    var handlers = {
        tz_fd_addItem: tz_fd_addItem,
        tz_fd_updateItem: tz_fd_updateItem,
        tz_fd_cloneItem: tz_fd_cloneItem,
        tz_fd_manageSettings: tz_fd_manageSettings,
        tz_fd_publish: tz_fd_publish,
        tz_fd_addbk: tz_fd_addbk,
        tz_fd_blocktimes: tz_fd_blocktimes,
        tz_fd_viewMessages: tz_fd_viewMessages,
        tz_fd_viewSchedule: tz_fd_viewSchedule,
        tz_fd_viewReport: tz_fd_viewReport,
        tz_fd_deleteItem: tz_fd_deleteItem
    };

    // Replaces the former onclick="tz_fd_xxx(id);" attributes.
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
