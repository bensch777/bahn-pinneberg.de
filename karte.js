/*
 * Live-Karte (karte/index.php): zeichnet das Streckennetz und die Zuege mit
 * Leaflet, holt alle REFRESH_MS einen Stand von api/karte und rechnet die
 * Positionen dazwischen jede Sekunde aus den Ist-Zeiten der Halte nach, mit
 * derselben Logik wie ka_lage in api/karte.php. Es gibt keine GPS-Ortung.
 *
 * Streckennetz und letzter Stand stehen als JSON im HTML (karte-strecke,
 * karte-stand). Linie und Ausschnitt lassen sich per Chip waehlen und bleiben
 * im Browser gespeichert.
 */
(function () {
	'use strict';

	var app = document.getElementById('karte-app');
	var mapEl = document.getElementById('karte');
	if (!app || !mapEl || !window.L) {
		return;
	}

	var REFRESH_MS = 30 * 1000;   // neuer Stand von api/karte
	var TICK_MS = 1000;           // Positionen nachrechnen
	var LISTE_MS = 15 * 1000;     // Liste neu schreiben
	var STALE_MS = 3 * 60 * 1000; // ab dann gilt der Stand als alt
	var NACHLAUF_MS = 3 * 60 * 1000;
	var TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

	function lesen(id) {
		var el = document.getElementById(id);
		try {
			return el ? JSON.parse(el.textContent) : null;
		} catch (e) {
			return null;
		}
	}
	var strecke = lesen('karte-strecke') || { stationen: {}, kanten: [], ansichten: {}, linien: {} };
	var endpoint = app.getAttribute('data-endpoint');
	var state = { zuege: [], fetchedAt: null, ok: false, linie: '', ansicht: '' };

	/* ---------- Hilfen ---------- */

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function uhr(ms) {
		if (!ms) return '';
		var d = new Date(ms);
		return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
	}
	function uhrSek(ms) {
		var d = new Date(ms);
		return uhr(ms) + ':' + ('0' + d.getSeconds()).slice(-2);
	}
	function linieKlasse(linie) {
		return String(linie || '').toLowerCase().replace(/[^a-z0-9]/g, '');
	}
	function speichern(key, wert) {
		try { localStorage.setItem(key, wert); } catch (e) { /* privater Modus */ }
	}
	function laden(key) {
		try { return localStorage.getItem(key); } catch (e) { return null; }
	}

	/* ---------- Streckennetz ---------- */

	var graph = {};
	Object.keys(strecke.kanten || {}).forEach(function (i) {
		var k = strecke.kanten[i];
		var von = strecke.stationen[k.von];
		var bis = strecke.stationen[k.bis];
		if (!von || !bis) return;
		var punkte = [[von.lat, von.lon]].concat(k.zwischen || [], [[bis.lat, bis.lon]]);
		graph[k.von] = graph[k.von] || {};
		graph[k.bis] = graph[k.bis] || {};
		graph[k.von][k.bis] = punkte;
		graph[k.bis][k.von] = punkte.slice().reverse();
	});

	var pfadCache = {};
	/** Linienzug von Station zu Station durch das Netz (Breitensuche), mit Laengen. */
	function pfad(von, bis) {
		var key = von + '>' + bis;
		if (key in pfadCache) return pfadCache[key];
		if (!graph[von] || !graph[bis]) return (pfadCache[key] = null);
		var vorgaenger = {};
		vorgaenger[von] = null;
		var queue = [von];
		while (queue.length && !(bis in vorgaenger)) {
			var n = queue.shift();
			Object.keys(graph[n]).forEach(function (nachbar) {
				if (!(nachbar in vorgaenger)) {
					vorgaenger[nachbar] = n;
					queue.push(nachbar);
				}
			});
		}
		if (!(bis in vorgaenger)) return (pfadCache[key] = null);
		var knoten = [];
		for (var n2 = bis; n2 !== null; n2 = vorgaenger[n2]) knoten.unshift(n2);
		var punkte = [];
		for (var i = 1; i < knoten.length; i++) {
			var teil = graph[knoten[i - 1]][knoten[i]];
			punkte = punkte.concat(punkte.length ? teil.slice(1) : teil);
		}
		var laengen = [0];
		var gesamt = 0;
		for (var j = 1; j < punkte.length; j++) {
			gesamt += dist(punkte[j - 1], punkte[j]);
			laengen.push(gesamt);
		}
		return (pfadCache[key] = { punkte: punkte, laengen: laengen, gesamt: gesamt });
	}
	function dist(a, b) {
		var r = 6371000, la1 = a[0] * Math.PI / 180, la2 = b[0] * Math.PI / 180;
		var dla = la2 - la1, dlo = (b[1] - a[1]) * Math.PI / 180;
		var h = Math.sin(dla / 2) * Math.sin(dla / 2) + Math.cos(la1) * Math.cos(la2) * Math.sin(dlo / 2) * Math.sin(dlo / 2);
		return 2 * r * Math.asin(Math.min(1, Math.sqrt(h)));
	}
	function punktAufPfad(p, anteil) {
		var n = p.punkte.length;
		if (!n) return null;
		if (n === 1 || p.gesamt <= 0) return p.punkte[0];
		var ziel = Math.max(0, Math.min(1, anteil)) * p.gesamt;
		for (var i = 1; i < n; i++) {
			if (p.laengen[i] >= ziel || i === n - 1) {
				var l = p.laengen[i] - p.laengen[i - 1];
				var f = l > 0 ? (ziel - p.laengen[i - 1]) / l : 0;
				f = Math.max(0, Math.min(1, f));
				return [p.punkte[i - 1][0] + (p.punkte[i][0] - p.punkte[i - 1][0]) * f, p.punkte[i - 1][1] + (p.punkte[i][1] - p.punkte[i - 1][1]) * f];
			}
		}
		return p.punkte[n - 1];
	}
	function station(id) {
		var s = strecke.stationen[id];
		return s ? [s.lat, s.lon] : null;
	}

	/* ---------- Lage eines Zuges (wie ka_lage in api/karte.php) ---------- */

	function vorbereiten(z) {
		z._halte = (z.halte || []).map(function (h) {
			var an = h.an_ist ? Date.parse(h.an_ist) : null;
			var ab = h.ab_ist ? Date.parse(h.ab_ist) : null;
			return { id: h.id, name: h.name, an: an, ab: ab, dep: ab || an, arr: an || ab, verspaetung: h.verspaetung || 0, ausfall: !!h.ausfall, bedient: !!h.bedient && !!station(h.id) && (an || ab), gleis: h.gleis || '' };
		});
		z._bedient = [];
		z._halte.forEach(function (h, i) { if (h.bedient) z._bedient.push(i); });
		return z;
	}

	function lage(z, now) {
		var b = z._bedient, h = z._halte;
		if (b.length < 2) return null;
		var erster = h[b[0]], letzter = h[b[b.length - 1]];
		if (now < erster.dep) return { status: 'wartet', bei: erster, verspaetung: erster.verspaetung };
		if (now >= letzter.arr) {
			if (now - letzter.arr > NACHLAUF_MS) return null;
			return { status: 'ziel', bei: letzter, verspaetung: letzter.verspaetung };
		}
		for (var k = 0; k < b.length - 1; k++) {
			var i = h[b[k]], j = h[b[k + 1]];
			if (now < i.dep) return { status: 'halt', bei: i, verspaetung: i.verspaetung };
			if (now < j.arr) {
				var dauer = j.arr - i.dep;
				return { status: 'unterwegs', von: i, nach: j, anteil: dauer > 0 ? Math.max(0, Math.min(1, (now - i.dep) / dauer)) : 1, verspaetung: j.verspaetung };
			}
		}
		return { status: 'ziel', bei: letzter, verspaetung: letzter.verspaetung };
	}

	function position(l) {
		if (!l) return null;
		if (l.status === 'unterwegs') {
			var p = pfad(l.von.id, l.nach.id);
			return p ? punktAufPfad(p, l.anteil) : station(l.von.id);
		}
		return station(l.bei.id);
	}

	function lageText(l) {
		if (!l) return '';
		if (l.status === 'wartet') return 'Steht in ' + l.bei.name + ', Abfahrt ' + uhr(l.bei.dep) + (l.bei.gleis ? ', Gleis ' + l.bei.gleis : '');
		if (l.status === 'halt') return 'Hält in ' + l.bei.name + (l.bei.ab ? ', weiter ' + uhr(l.bei.ab) : '');
		if (l.status === 'ziel') return 'Angekommen in ' + l.bei.name + ' um ' + uhr(l.bei.arr);
		return 'Zwischen ' + l.von.name + ' und ' + l.nach.name + ', ' + l.nach.name + ' ' + uhr(l.nach.arr);
	}

	function delayKlasse(min) {
		return min >= 10 ? 'stark' : (min >= 3 ? 'spaet' : '');
	}

	/* ---------- Karte ---------- */

	var map = L.map(mapEl, { zoomControl: true, minZoom: 8, maxZoom: 16, zoomSnap: 0.5 });
	L.tileLayer(TILES, {
		maxZoom: 16,
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener">OpenStreetMap</a>-Mitwirkende'
	}).addTo(map);

	// Dauerhafte Bahnhofsnamen liegen unter den Zuegen (Leaflets Tooltip-Ebene laege darueber)
	map.createPane('labels').style.zIndex = 450;

	// Strecke: helle Kante unter dunkler Linie, darauf die Halte
	var netz = L.layerGroup().addTo(map);
	Object.keys(graph).forEach(function (von) {
		Object.keys(graph[von]).forEach(function (bis) {
			if (von < bis) {
				L.polyline(graph[von][bis], { color: '#ffffff', weight: 7, opacity: 0.9, interactive: false }).addTo(netz);
			}
		});
	});
	Object.keys(graph).forEach(function (von) {
		Object.keys(graph[von]).forEach(function (bis) {
			if (von < bis) {
				L.polyline(graph[von][bis], { color: '#3a3a38', weight: 3, opacity: 1, interactive: false }).addTo(netz);
			}
		});
	});
	Object.keys(strecke.stationen || {}).forEach(function (id) {
		var s = strecke.stationen[id];
		if (s.knoten) return;
		var m = L.circleMarker([s.lat, s.lon], { radius: s.gross ? 5.5 : 3.5, color: '#3a3a38', weight: 2, fillColor: '#ffffff', fillOpacity: 1, pane: 'markerPane' }).addTo(netz);
		var seite = s.label || 'right';
		var versatz = { right: [7, 0], left: [-7, 0], top: [0, -8], bottom: [0, 8] }[seite] || [7, 0];
		m.bindTooltip(esc(s.name), s.gross
			? { permanent: true, direction: seite, offset: versatz, className: 'karte-label', opacity: 1, pane: 'labels' }
			: { direction: 'top', offset: [0, -4], className: 'karte-tip', opacity: 1 });
	});

	var zugLayer = L.layerGroup().addTo(map);
	var marker = {}; // zugnummer -> { m: Marker, zug, linie }

	function iconHtml(z, l) {
		var d = l ? l.verspaetung : z.verspaetung;
		var k = delayKlasse(d);
		return '<span class="zug zug--' + esc(linieKlasse(z.linie)) + (l ? ' zug--' + l.status : '') + (k ? ' zug--' + k : '') + '">'
			+ '<svg class="zug__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3l1.5 2.5h-2L15 18H9l-1.5 2.5h-2L7 18a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3Zm0 3v6h10V5H7Zm1 9a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm8 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/></svg>'
			+ '<b class="zug__linie">' + esc(z.linie) + '</b>'
			+ (d >= 3 ? '<i class="zug__delay">+' + d + '</i>' : '')
			+ '</span>';
	}

	function popupHtml(z) {
		var l = lage(z, Date.now());
		var h = '<div class="karte-popup">'
			+ '<p class="karte-popup__kopf"><span class="linie linie--' + esc(linieKlasse(z.linie)) + '">' + esc(z.linie) + '</span> <strong>' + esc(z.von) + ' &rarr; ' + esc(z.nach) + '</strong></p>'
			+ '<p class="karte-popup__lage">' + esc(lageText(l)) + (l && l.verspaetung >= 3 ? ' <span class="pill ' + (l.verspaetung >= 10 ? 'pill--ausfall' : 'pill--teil') + '">+' + l.verspaetung + '</span>' : '') + '</p>'
			+ (z.hinweis ? '<p class="karte-popup__hinweis">' + esc(z.hinweis) + '</p>' : '')
			+ '<ol class="karte-popup__halte">';
		var naechster = l ? (l.status === 'unterwegs' ? l.nach : l.bei) : null;
		z._halte.forEach(function (st) {
			var cls = st.ausfall || !st.bedient ? 'ist-ausfall' : (naechster && st === naechster ? 'ist-naechster' : (st.arr && st.arr < Date.now() && st !== naechster ? 'ist-vorbei' : ''));
			var zeit = st.ab && !st.an ? uhr(st.ab) : uhr(st.arr);
			h += '<li class="' + cls + '"><span class="karte-popup__zeit">' + esc(zeit) + '</span> ' + esc(st.name)
				+ (st.verspaetung >= 3 && !st.ausfall ? ' <small>+' + st.verspaetung + '</small>' : '')
				+ (st.gleis && !st.ausfall ? ' <small>Gl. ' + esc(st.gleis) + '</small>' : '') + '</li>';
		});
		h += '</ol><p class="karte-popup__fuss">Zug ' + esc(z.zug) + (z.wagen ? ' &middot; ' + z.wagen + (z.wagen === 1 ? ' Wagen' : ' Wagen') : '') + '</p></div>';
		return h;
	}

	function sichtbar(z) {
		return !state.linie || z.linie === state.linie;
	}

	/** Marker anlegen, aktualisieren, entfernen nach dem aktuellen Stand. */
	function markerAbgleichen() {
		var now = Date.now();
		var gesehen = {};
		var n = 0;
		state.zuege.forEach(function (z) {
			var l = lage(z, now);
			var pos = position(l);
			if (!l || !pos || !sichtbar(z)) return;
			gesehen[z.zug] = true;
			n++;
			var e = marker[z.zug];
			var icon = L.divIcon({ className: 'zug-icon', html: iconHtml(z, l), iconSize: [64, 26], iconAnchor: [32, 13], popupAnchor: [0, -12] });
			if (!e) {
				var m = L.marker(pos, { icon: icon, riseOnHover: true, keyboard: true, title: z.linie + ' ' + z.von + ' → ' + z.nach, zIndexOffset: 1000 });
				m.bindPopup(function () { return popupHtml(z); }, { maxWidth: 300, className: 'karte-popup-wrap' });
				m.addTo(zugLayer);
				marker[z.zug] = e = { m: m, zug: z, status: l.status, delay: l.verspaetung };
			} else {
				e.zug = z;
				e.m.setLatLng(pos);
				if (e.status !== l.status || e.delay !== l.verspaetung) {
					e.m.setIcon(icon);
					e.status = l.status;
					e.delay = l.verspaetung;
				}
				if (e.m.isPopupOpen()) {
					e.m.setPopupContent(popupHtml(z));
				}
			}
		});
		Object.keys(marker).forEach(function (zug) {
			if (!gesehen[zug]) {
				zugLayer.removeLayer(marker[zug].m);
				delete marker[zug];
			}
		});
		var anzahl = document.getElementById('karte-anzahl');
		var text = document.getElementById('karte-anzahl-text');
		if (anzahl) anzahl.textContent = String(n);
		if (text) text.textContent = (n === 1 ? 'Zug' : 'Züge') + ' dargestellt' + (state.linie ? ' (' + state.linie + ')' : '');
		var leer = document.getElementById('karte-leer');
		if (leer) {
			var msg = !state.ok && !state.zuege.length ? 'Gerade keine Daten vom Stationsmonitor.' : (n === 0 ? 'Gerade kein Zug auf der Karte.' : '');
			leer.textContent = msg;
			leer.hidden = msg === '';
		}
	}

	/** Positionen jede Sekunde nachrechnen (ohne Icons neu zu bauen). */
	function tick() {
		var now = Date.now();
		Object.keys(marker).forEach(function (zug) {
			var e = marker[zug];
			var l = lage(e.zug, now);
			var pos = position(l);
			if (!l || !pos) {
				zugLayer.removeLayer(e.m);
				delete marker[zug];
				return;
			}
			e.m.setLatLng(pos);
			if (e.status !== l.status || e.delay !== l.verspaetung) {
				e.m.setIcon(L.divIcon({ className: 'zug-icon', html: iconHtml(e.zug, l), iconSize: [64, 26], iconAnchor: [32, 13], popupAnchor: [0, -12] }));
				e.status = l.status;
				e.delay = l.verspaetung;
			}
		});
	}

	/* ---------- Liste und Kopfzeile ---------- */

	function listeSchreiben() {
		var el = document.getElementById('karte-liste');
		if (!el) return;
		var now = Date.now();
		var zeilen = [];
		state.zuege.forEach(function (z) {
			var l = lage(z, now);
			if (!l || !sichtbar(z)) return;
			var d = l.verspaetung;
			zeilen.push('<li><span class="linie linie--' + esc(linieKlasse(z.linie)) + '">' + esc(z.linie) + '</span>'
				+ '<span class="zugliste__text"><strong>' + esc(z.von) + ' &rarr; ' + esc(z.nach) + '</strong>'
				+ '<span class="zugliste__lage">' + esc(lageText(l)) + (z.hinweis ? ' &middot; ' + esc(z.hinweis) : '') + '</span></span>'
				+ (d >= 3 ? '<span class="pill ' + (d >= 10 ? 'pill--ausfall' : 'pill--teil') + '">+' + d + '</span>' : '') + '</li>');
		});
		el.innerHTML = zeilen.length ? '<ul class="zugliste">' + zeilen.join('') + '</ul>' : '<p class="zugliste__leer">' + (state.ok || state.zuege.length ? 'Gerade kein Zug auf der Karte.' : 'Gerade keine Daten vom Stationsmonitor.') + '</p>';
	}

	function kopfSchreiben() {
		var el = document.getElementById('karte-freshness');
		var text = document.getElementById('karte-freshness-text');
		if (!el || !text) return;
		var alt = state.fetchedAt ? Date.now() - state.fetchedAt > STALE_MS : true;
		el.classList.toggle('is-stale', !!state.fetchedAt && (alt || !state.ok));
		el.classList.toggle('is-empty', !state.fetchedAt);
		text.textContent = state.fetchedAt ? 'Letzter Abruf ' + uhrSek(state.fetchedAt) + (!state.ok ? ' · Monitor nicht erreichbar' : '') : 'Noch kein Stand';
	}

	/* ---------- Daten ---------- */

	function uebernehmen(daten) {
		if (!daten || typeof daten !== 'object') return;
		state.ok = !!daten.ok;
		state.fetchedAt = daten.fetched_at ? Date.parse(daten.fetched_at) : state.fetchedAt;
		if (Array.isArray(daten.zuege) && (daten.ok || !state.zuege.length)) {
			state.zuege = daten.zuege.map(vorbereiten);
		}
		markerAbgleichen();
		listeSchreiben();
		kopfSchreiben();
	}

	var laeuft = false;
	function refresh() {
		if (laeuft) return;
		laeuft = true;
		fetch(endpoint, { cache: 'no-cache', headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(uebernehmen)
			.catch(function () { state.ok = false; kopfSchreiben(); })
			.then(function () { laeuft = false; });
	}

	/* ---------- Chips ---------- */

	function ansichtZeigen(id, animieren) {
		var a = strecke.ansichten && strecke.ansichten[id];
		var punkte = [];
		(a ? a.stationen : Object.keys(strecke.stationen)).forEach(function (sid) {
			var p = station(sid);
			if (p) punkte.push(p);
		});
		if (!punkte.length) return;
		map.fitBounds(L.latLngBounds(punkte), { padding: [28, 28], animate: !!animieren, maxZoom: 13 });
	}

	function chipsSetzen(attr, wert) {
		Array.prototype.forEach.call(app.querySelectorAll('.chip[' + attr + ']'), function (b) {
			b.setAttribute('aria-pressed', b.getAttribute(attr) === wert ? 'true' : 'false');
		});
	}

	app.addEventListener('click', function (ev) {
		var b = ev.target.closest ? ev.target.closest('.chip') : null;
		if (!b) return;
		if (b.hasAttribute('data-linie')) {
			state.linie = b.getAttribute('data-linie') || '';
			chipsSetzen('data-linie', state.linie);
			speichern('nordbahn.karte.linie', state.linie);
			markerAbgleichen();
			listeSchreiben();
		} else if (b.hasAttribute('data-ansicht')) {
			state.ansicht = b.getAttribute('data-ansicht') || '';
			chipsSetzen('data-ansicht', state.ansicht);
			speichern('nordbahn.karte.ansicht', state.ansicht);
			ansichtZeigen(state.ansicht, true);
		}
	});

	/* ---------- Start ---------- */

	var ansichten = Object.keys(strecke.ansichten || {});
	var gemerkt = laden('nordbahn.karte.ansicht');
	state.ansicht = gemerkt && strecke.ansichten[gemerkt] ? gemerkt : (ansichten[0] || '');
	chipsSetzen('data-ansicht', state.ansicht);
	ansichtZeigen(state.ansicht, false);
	var linie = laden('nordbahn.karte.linie');
	if (linie && strecke.linien && strecke.linien[linie]) {
		state.linie = linie;
		chipsSetzen('data-linie', linie);
	}

	uebernehmen(lesen('karte-stand'));
	refresh();
	setInterval(refresh, REFRESH_MS);
	setInterval(tick, TICK_MS);
	setInterval(listeSchreiben, LISTE_MS);
	setInterval(kopfSchreiben, 10000);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			tick();
			refresh();
		}
	});
})();
