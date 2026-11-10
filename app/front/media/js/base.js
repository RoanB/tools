/**
 * Base JS
 *
 * Custom scripts for the application.
 */

/**
 * Turn every form-select into a searchable select2 dropdown
 *
 * Small lists (match types, colors) hide the search box via
 * minimumResultsForSearch; the width follows the data-width attribute when
 * present so inline filter dropdowns can keep their size.
 */
$(function() {
	$('select.form-select').each(function() {
		var select = $(this);
		select.select2({
			theme: 'bootstrap-5',
			width: select.data('width') || '100%',
			minimumResultsForSearch: 5,
		});
	});
});

/**
 * Selects marked data-keep-params submit their value while preserving the
 * other query parameters (used for the account and year switchers)
 */
$(function() {
	$(document).on('change', 'select[data-keep-params]', function() {
		var name = $(this).attr('name');
		var params = new URLSearchParams(window.location.search);
		params.set(name, String($(this).val()));
		params.delete('p');
		params.delete('q');

		page_loader_show();
		window.location.href = window.location.pathname + '?' + params.toString();
	});
});

/**
 * Full-page loader
 *
 * A spinner overlay that shows the moment the user navigates (link click
 * or form submit) and hides when the new page replaces this one, so slow
 * server-side renders (rule conflict report, imports) never look frozen.
 * Downloads and new-tab links never navigate away, so they are skipped;
 * a safety timeout and the pageshow event (back navigation, canceled
 * submits) hide the overlay when no navigation follows.
 */
var page_loader_timeout = null;

function page_loader_show() {
	var loader = document.getElementById('page-loader');
	if (loader === null) {
		return;
	}

	loader.hidden = false;
	if (page_loader_timeout !== null) {
		clearTimeout(page_loader_timeout);
	}

	page_loader_timeout = setTimeout(page_loader_hide, 30000);
}

function page_loader_hide() {
	var loader = document.getElementById('page-loader');
	if (loader === null) {
		return;
	}

	loader.hidden = true;
	if (page_loader_timeout !== null) {
		clearTimeout(page_loader_timeout);
		page_loader_timeout = null;
	}
}

$(function() {
	$(document).on('click', 'a[href]', function(event) {
		var link = this;
		if (link.target === '_blank' || link.hasAttribute('download') === true) {
			return;
		}

		if (link.hasAttribute('data-no-loader') === true) {
			return;
		}

		var href = $(link).attr('href');
		if (href === undefined || href === '' || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
			return;
		}

		var url = new URL(href, window.location.href);
		if (url.origin !== window.location.origin) {
			return;
		}

		page_loader_show();
	});

	$(document).on('submit', 'form', function(event) {
		if (this.target === '_blank' || event.isDefaultPrevented() === true) {
			return;
		}

		page_loader_show();
	});

	$(window).on('pageshow', function() {
		page_loader_hide();
	});
});

/**
 * Dark mode
 *
 * Three states: auto (follow the system preference — the default,
 * stored as the absence of a key), light and dark. The theme is
 * applied before the CSS paints by an inline script in the layout
 * head; these handlers wire the .theme-toggle buttons (navbar for
 * signed-in users, footer and login page everywhere else): each click
 * cycles auto -> light -> dark -> auto, stores the explicit choice and
 * forgets it again on the way back to auto. The buttons show the
 * current state: circle-half for auto, sun for light, moon for dark.
 */
function theme_stored() {
	var theme = null;
	try {
		theme = localStorage.getItem('theme');
	} catch (e) {
		// storage can be unavailable (private mode); treat as auto
	}
	return theme === 'light' || theme === 'dark' ? theme : 'auto';
}

function theme_icons_sync(state) {
	var icons = { auto: 'ti ti-circle-half', light: 'ti ti-sun', dark: 'ti ti-moon' };
	var titles = {
		auto: 'Theme: following the system setting — click for light',
		light: 'Theme: light — click for dark',
		dark: 'Theme: dark — click to follow the system setting'
	};
	$('.theme-toggle i').attr('class', icons[state]);
	$('.theme-toggle').attr('title', titles[state]);
}

function theme_apply_stored() {
	var stored = theme_stored();
	var effective = stored;
	if (effective === 'auto') {
		effective = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
	}
	document.documentElement.setAttribute('data-bs-theme', effective);
	theme_icons_sync(stored);
	document.dispatchEvent(new Event('themechange'));
}

$(function() {
	$(document).on('click', '.theme-toggle', function() {
		var next = { auto: 'light', light: 'dark', dark: 'auto' }[theme_stored()];
		try {
			if (next === 'auto') {
				localStorage.removeItem('theme');
			} else {
				localStorage.setItem('theme', next);
			}
		} catch (e) {
			// storage unavailable: the toggle still works for this view
		}
		theme_apply_stored();
	});

	// While following the system, a change of the system preference
	// (e.g. OS night schedule) updates the page live.
	window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
		if (theme_stored() === 'auto') {
			theme_apply_stored();
		}
	});

	theme_apply_stored();
});
