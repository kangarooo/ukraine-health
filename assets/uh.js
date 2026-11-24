/* Ukraine Health – live group counters + size fields. No dependencies. */
(function () {
	'use strict';
	var form = document.getElementById('uh-request-form');
	if (!form || !window.UH) { return; }

	var hospital = form.querySelector('#uh-hospital');
	var tiles = form.querySelectorAll('.uh-tile[data-service]');
	var sizes = form.querySelector('.uh-sizes');

	function toggleSizes() {
		var need = false;
		tiles.forEach(function (t) {
			var cb = t.querySelector('input');
			t.classList.toggle('is-checked', cb.checked);
			if (cb.checked && t.getAttribute('data-sizes') === '1') { need = true; }
		});
		if (sizes) { sizes.hidden = !need; }
	}

	function refresh() {
		tiles.forEach(function (t) { t.querySelector('.uh-tile-count').textContent = ''; });
		if (!hospital.value) { return; }
		fetch(UH.count + (UH.count.indexOf('?') > -1 ? '&' : '?') + 'hospital=' + encodeURIComponent(hospital.value), { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : {}; })
			.then(function (data) {
				tiles.forEach(function (t) {
					var d = data[t.getAttribute('data-service')];
					var el = t.querySelector('.uh-tile-count');
					if (!d) { return; }
					if (d.min > 1) {
						if (d.status === 'ready' || d.members >= d.min) {
							el.textContent = d.members + ' ' + UH.i18n.waiting + ' · ✓';
							el.title = UH.i18n.ready;
						} else {
							el.textContent = d.members + '/' + d.min + ' · ' + (d.min - d.members) + ' ' + UH.i18n.need;
						}
					} else if (d.members > 0) {
						el.textContent = d.members + ' ' + UH.i18n.waiting;
					}
				});
			})
			.catch(function () {});
	}

	form.addEventListener('change', function (e) {
		if (e.target === hospital) { refresh(); }
		if (e.target.name === 'services[]') { toggleSizes(); }
	});
	form.addEventListener('submit', function (e) {
		if (!form.querySelector('input[name="services[]"]:checked')) {
			e.preventDefault();
			form.querySelector('.uh-cat').scrollIntoView({ behavior: 'smooth' });
			form.querySelector('.uh-cat').classList.add('uh-shake');
			setTimeout(function () { form.querySelector('.uh-cat').classList.remove('uh-shake'); }, 600);
			return;
		}
		var btn = form.querySelector('button[type="submit"]');
		btn.disabled = true;
	});
	toggleSizes();
	refresh();
})();
