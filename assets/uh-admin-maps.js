/* Ukraine Health – hospital finder on Google Maps (admin, Hospitals page). No API key:
 * Google's keyless map embed shows what Google finds for the search, so the exact name and
 * address can be copied, and a share link can be pasted for a precise map link. */
(function () {
	'use strict';
	var cfg = window.UHMaps || {};
	var q = document.getElementById('uh-maps-q');
	var btn = document.getElementById('uh-maps-search');
	var frame = document.getElementById('uh-maps-frame');
	var open = document.getElementById('uh-maps-open');
	if (!q || !btn || !frame) { return; }
	var lang = cfg.locale || 'en';

	function show(query) {
		query = (query || '').trim();
		if (!query) { return; }
		frame.innerHTML = '';
		var ifr = document.createElement('iframe');
		ifr.src = 'https://www.google.com/maps?q=' + encodeURIComponent(query) + '&hl=' + encodeURIComponent(lang) + '&output=embed';
		ifr.loading = 'lazy';
		ifr.referrerPolicy = 'no-referrer-when-downgrade';
		ifr.title = 'Google Maps';
		frame.appendChild(ifr);
		if (open) { open.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(query); }
	}

	btn.addEventListener('click', function () { show(q.value); });
	q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); show(q.value); } });
	if (frame.getAttribute('data-q')) { show(frame.getAttribute('data-q')); }
})();
