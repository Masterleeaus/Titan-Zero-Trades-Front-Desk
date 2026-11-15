/*
 * Settings page: switches between the section tabs (General, Booking rules, ...).
 * The active section comes from PHP as tzfdSettingsTabs.active (see tzfd-settings.inc.php).
 */
jQuery(function ($) {
	// Old tab hashes (linked from other admin pages) map to the section that now holds them.
	var legacy = { reports: 'notifications', troubleshooting: 'advanced', csv: 'advanced', calendar: 'advanced', other: 'booking', ai: 'integrations' };

	function sectionTab(key) {
		return $('.tzfd-tab').filter(function () { return $(this).attr('data-tab') === key; });
	}

	function navLink(key) {
		return $('.tzfd-set-nav a').filter(function () { return $(this).attr('data-tab') === key; });
	}

	function show(key) {
		if (!sectionTab(key).length) { key = 'general'; }
		$('.tzfd-set-nav a').removeClass('is-active');
		navLink(key).addClass('is-active');
		$('.tzfd-tab').removeClass('tab-active');
		sectionTab(key).addClass('tab-active');
		// The header Save button saves the section only when it has exactly one form.
		$('#tzfd-settings-save').toggle(sectionTab(key).find('form').length === 1);
	}

	$('.tzfd-set-nav a').on('click', function (e) {
		e.preventDefault();
		var key = $(this).attr('data-tab');
		show(key);
		if (window.history && history.replaceState) { history.replaceState(null, '', '#' + key); }
	});

	$('#tzfd-settings-save').on('click', function () {
		var form = $('.tzfd-tab.tab-active form')[0];
		if (form) { form.submit(); }
	});

	var hash = (window.location.hash || '').replace('#', '');
	if (legacy[hash]) { hash = legacy[hash]; }
	var initial = (window.tzfdSettingsTabs && tzfdSettingsTabs.active) || 'general';
	show(hash || initial);
});
