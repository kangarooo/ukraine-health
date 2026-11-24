/* Ukraine Health – hospital finder on OpenStreetMap (admin, Hospitals page). No API key.
 * Search uses Nominatim (one request per click, as its usage policy asks – no search-as-you-type).
 * Picking a result fills in name, street, city, country, the OSM id and the pin location. */
(function () {
	'use strict';
	var cfg = window.UHMaps || {};
	var form = document.getElementById('uh-hospital-form');
	if (!form) { return; }
	var q = document.getElementById('uh-maps-q');
	var btn = document.getElementById('uh-maps-search');
	var list = document.getElementById('uh-maps-results');
	var frame = document.getElementById('uh-maps-frame');
	var open = document.getElementById('uh-maps-open');
	var msg = form.querySelector('.uh-maps-msg');
	var lang = cfg.locale || 'en';
	var busy = false;

	function field(name) { return form.querySelector('[name="' + name + '"]'); }
	function set(name, value) { var f = field(name); if (f && value !== undefined && value !== null) { f.value = value; } }
	function say(text, bad) { msg.textContent = text || ''; msg.style.color = bad ? '#b32d2e' : ''; }

	function showMap(lat, lng) {
		lat = parseFloat(lat); lng = parseFloat(lng);
		if (isNaN(lat) || isNaN(lng)) { return; }
		var d = 0.004;
		var bbox = [lng - d, lat - d, lng + d, lat + d].join(',');
		frame.innerHTML = '';
		var ifr = document.createElement('iframe');
		ifr.src = 'https://www.openstreetmap.org/export/embed.html?bbox=' + encodeURIComponent(bbox) + '&layer=mapnik&marker=' + lat + ',' + lng;
		ifr.loading = 'lazy';
		ifr.title = 'OpenStreetMap';
		frame.appendChild(ifr);
		open.href = 'https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lng + '#map=17/' + lat + '/' + lng;
	}

	function isHospital(r) {
		return (r.category === 'amenity' || r['class'] === 'amenity' || r.category === 'healthcare' || r['class'] === 'healthcare') &&
			['hospital', 'clinic', 'rehabilitation'].indexOf(r.type) !== -1;
	}

	function placeName(r) {
		return r.name || (r.namedetails && r.namedetails.name) || (r.display_name || '').split(',')[0];
	}

	function pick(r) {
		var a = r.address || {};
		set('name', placeName(r));
		set('address', [a.road || a.pedestrian || a.street, a.house_number].filter(Boolean).join(' '));
		set('city', a.city || a.town || a.village || a.municipality || a.county || '');
		set('country', a.country || '');
		set('place_id', 'osm:' + r.osm_type + '/' + r.osm_id);
		set('maps_url', 'https://www.openstreetmap.org/' + r.osm_type + '/' + r.osm_id);
		set('lat', r.lat);
		set('lng', r.lon);
		showMap(r.lat, r.lon);
		list.innerHTML = '';
		say(cfg.picked);
	}

	function render(results) {
		list.innerHTML = '';
		results.sort(function (x, y) { return (isHospital(y) ? 1 : 0) - (isHospital(x) ? 1 : 0); });
		results.forEach(function (r) {
			var li = document.createElement('li');
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'button button-small';
			b.textContent = cfg.pick;
			b.addEventListener('click', function () { pick(r); });
			var t = document.createElement('span');
			t.innerHTML = '';
			var strong = document.createElement('strong');
			strong.textContent = placeName(r);
			t.appendChild(strong);
			if (isHospital(r)) {
				var tag = document.createElement('em');
				tag.textContent = ' · ' + cfg.hospital;
				t.appendChild(tag);
			}
			t.appendChild(document.createElement('br'));
			var small = document.createElement('small');
			small.textContent = r.display_name;
			t.appendChild(small);
			li.appendChild(b);
			li.appendChild(t);
			li.addEventListener('mouseenter', function () { showMap(r.lat, r.lon); });
			list.appendChild(li);
		});
	}

	function lookup(query) {
		var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&namedetails=1&limit=8&accept-language=' + encodeURIComponent(lang) + '&q=' + encodeURIComponent(query);
		return fetch(url, { headers: { 'Accept': 'application/json' } })
			.then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); });
	}
	function wait(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

	// Stops and stations are often named after the hospital: leave them out.
	function useful(r) {
		var c = r.category || r['class'];
		return ['highway', 'public_transport', 'railway'].indexOf(c) === -1;
	}

	function search() {
		var v = (q.value || '').trim();
		if (!v || busy) { return; }
		busy = true;
		btn.disabled = true;
		say(cfg.searching);
		var seen = {};
		var all = [];
		function add(rows) {
			(rows || []).forEach(function (r) {
				var k = r.osm_type + r.osm_id;
				if (!seen[k] && useful(r)) { seen[k] = true; all.push(r); }
			});
		}
		// "hospital <name>" makes OpenStreetMap look for hospitals; then a plain search a second later
		// (Nominatim allows one request per second).
		lookup('hospital ' + v)
			.then(add)
			.then(function () { return wait(1100); })
			.then(function () { return lookup(v); })
			.then(add)
			.then(function () {
				if (!all.length) { list.innerHTML = ''; say(cfg.noMatch, true); return; }
				say('');
				render(all);
			})
			.catch(function () { if (all.length) { render(all); say(''); } else { say(cfg.failed, true); } })
			.then(function () { return wait(1100); })
			.then(function () { busy = false; btn.disabled = false; });
	}

	btn.addEventListener('click', search);
	q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });

	// Editing a hospital that already has a pin: show it.
	if (frame.getAttribute('data-lat')) { showMap(frame.getAttribute('data-lat'), frame.getAttribute('data-lng')); }
})();
