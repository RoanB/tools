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
