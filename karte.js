/*
 * Live-Karte (karte/index.php): zeichnet das Streckennetz und die Zuege mit
 * Leaflet, holt alle REFRESH_MS einen Stand von api/karte und rechnet die
 * Positionen dazwischen jede Sekunde aus den Ist-Zeiten der Halte nach, mit
 * derselben Logik wie ka_lage in api/karte.php. Es gibt keine GPS-Ortung.
 *
 * Streckennetz und letzter Stand stehen als JSON im HTML (karte-strecke,
 * karte-stand). Beim Laden zeigt die Karte das ganze Netz.
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
	var TIMEOUT_MS = 15 * 1000;   // laenger wartet ein Abruf nicht auf api/karte
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
	// fehler: der letzte Abruf ist gescheitert oder der Monitor hat nicht geantwortet
	var state = { zuege: [], fetchedAt: null, ok: false, fehler: false };

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
			return { id: h.id, name: h.name, planAn: h.an ? Date.parse(h.an) : null, planAb: h.ab ? Date.parse(h.ab) : null, an: an, ab: ab, dep: ab || an, arr: an || ab, verspaetung: h.verspaetung || 0, ausfall: !!h.ausfall, faehrt: h.bedient !== false, bedient: !!h.bedient && !!station(h.id) && (an || ab), gleis: h.gleis || '' };
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

	// Strecke: eine Linie je Kante in Linienfarbe. Fahren dort mehrere Linien, wechseln
	// sich ihre Farben in Abschnitten ab (gestrichelte Lagen ueber einer durchgehenden).
	var netz = L.layerGroup().addTo(map);
	var STRICH = 14; // Laenge eines Farbabschnitts in Pixeln
	function linienFarbe(id) {
		return (strecke.linien && strecke.linien[id] && strecke.linien[id].farbe) || '#5b5b58';
	}
	(strecke.kanten || []).forEach(function (k) {
		var punkte = graph[k.von] && graph[k.von][k.bis];
		if (!punkte) return;
		var linien = (k.linien && k.linien.length ? k.linien : ['']).slice();
		L.polyline(punkte, { color: linienFarbe(linien[0]), weight: 4, opacity: 1, lineCap: 'butt', lineJoin: 'round', interactive: false }).addTo(netz);
		for (var i = 1; i < linien.length; i++) {
			L.polyline(punkte, {
				color: linienFarbe(linien[i]), weight: 4, opacity: 1, lineCap: 'butt', lineJoin: 'round', interactive: false,
				dashArray: STRICH + ' ' + STRICH * (linien.length - 1), dashOffset: String(STRICH * i)
			}).addTo(netz);
		}
	});
	Object.keys(strecke.stationen || {}).forEach(function (id) {
		var s = strecke.stationen[id];
		if (s.knoten) return;
		var m = L.circleMarker([s.lat, s.lon], { radius: s.gross ? 5 : 3.5, color: '#3a3a38', weight: 1.5, fillColor: '#ffffff', fillOpacity: 1, pane: 'markerPane' }).addTo(netz);
		var seite = s.label || 'right';
		var versatz = { right: [7, 0], left: [-7, 0], top: [0, -8], bottom: [0, 8] }[seite] || [7, 0];
		m.bindTooltip(esc(s.name), s.gross
			? { permanent: true, direction: seite, offset: versatz, className: 'karte-label', opacity: 1, pane: 'labels' }
			: { direction: 'top', offset: [0, -4], className: 'karte-tip', opacity: 1 });
	});

	var zugLayer = L.layerGroup().addTo(map);
	var marker = {}; // zugnummer -> { m: Marker, zug, linie }

	var ZUG_BILD = app.getAttribute('data-zug-bild') || '';
	function iconHtml(z, l) {
		var d = l ? l.verspaetung : z.verspaetung;
		var k = delayKlasse(d);
		return '<span class="zug zug--' + esc(linieKlasse(z.linie)) + (l ? ' zug--' + l.status : '') + (k ? ' zug--' + k : '') + '">'
			+ (ZUG_BILD ? '<img class="zug__bild" src="' + esc(ZUG_BILD) + '" alt="" width="28" height="28">' : '')
			+ '<b class="zug__linie">' + esc(z.linie) + '</b>'
			+ (d >= 3 ? '<i class="zug__delay">+' + d + '</i>' : '')
			+ '</span>';
	}

	/**
	 * Marker-Icon. Der Zug sitzt mit der Mitte seines Bildes auf dem Punkt; ein Zug, der am
	 * Startbahnhof wartet, steht ueber dem Bahnhof, ein angekommener darunter, damit sich an
	 * den Endbahnhoefen nichts ueberlagert.
	 */
	function zugIcon(z, l) {
		var y = l && l.status === 'wartet' ? 42 : (l && l.status === 'ziel' ? -12 : 15);
		return L.divIcon({ className: 'zug-icon', html: iconHtml(z, l), iconSize: [90, 30], iconAnchor: [14, y], popupAnchor: [20, 1 - y] });
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

	/**
	 * Popup als Element: der Text von oben und darunter die Fahrgastmeldungen
	 * (community.js). Melden geht, solange der Zug nicht angekommen ist; im Zug ist die
	 * Karte der Ort dafuer, denn aus der Tafel ist er nach der Abfahrt verschwunden.
	 */
	function popupInhalt(z) {
		var box = document.createElement('div');
		box.innerHTML = popupHtml(z);
		if (window.nbCommunity) {
			var l = lage(z, Date.now());
			var cm = window.nbCommunity.block(z.zug, !!l && l.status !== 'ziel');
			if (cm) box.firstChild.insertBefore(cm, box.firstChild.querySelector('.karte-popup__halte'));
		}
		return box;
	}

	/** Hinweis "Fahrgaeste: Zug steht" als HTML fuer Popup-Kopf und Liste, leer ohne Meldung. */
	function cmHinweisHtml(z) {
		var h = window.nbCommunity ? window.nbCommunity.hinweis(z.zug) : null;
		return h ? ' ' + h.outerHTML : '';
	}

	/** Marker anlegen, aktualisieren, entfernen nach dem aktuellen Stand. */
	function markerAbgleichen() {
		var now = Date.now();
		var gesehen = {};
		var n = 0;
		state.zuege.forEach(function (z) {
			var l = lage(z, now);
			var pos = position(l);
			if (!l || !pos) return;
			gesehen[z.zug] = true;
			n++;
			var e = marker[z.zug];
			var icon = zugIcon(z, l);
			if (!e) {
				var m = L.marker(pos, { icon: icon, riseOnHover: true, keyboard: true, title: z.linie + ' ' + z.von + ' → ' + z.nach, zIndexOffset: 1000 });
				m.bindPopup(function () { return popupInhalt(marker[z.zug] ? marker[z.zug].zug : z); }, { maxWidth: 300, className: 'karte-popup-wrap' });
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
					e.m.setPopupContent(popupInhalt(z));
				}
			}
		});
		Object.keys(marker).forEach(function (zug) {
			if (!gesehen[zug]) {
				zugLayer.removeLayer(marker[zug].m);
				delete marker[zug];
			}
		});
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
				e.m.setIcon(zugIcon(e.zug, l));
				e.status = l.status;
				e.delay = l.verspaetung;
			}
		});
	}

	/* ---------- Liste und Kopfzeile ---------- */

	/*
	 * Zuege nach Fahrtrichtung, je Richtung nach der Planzeit in Pinneberg (dort fahren alle
	 * drei Linien durch; fehlt der Halt, zaehlt der erste). Vormittags steht "Nach Hamburg"
	 * vorn, ab 12 Uhr "Aus Hamburg", wie auf der Startseite. Jede Zeile klappt den ganzen
	 * Halteverlauf mit Soll und Ist auf, dazu die Fahrgastmeldungen und einen Link zum
	 * Teilen (#zug=Nummer&halt=Bahnhof), der die Karte beim Empfaenger gleich aufklappt.
	 */
	var RICHTUNGEN = [{ id: 'hamburg', titel: 'Nach Hamburg' }, { id: 'nord', titel: 'Aus Hamburg' }];
	var offen = {};        // zugnummer -> true, uebersteht das Neuschreiben der Liste
	var geteilt = null;    // { zug, halt } aus dem Link, bis der Zug einmal gezeigt wurde
	var teilenText = {};   // zugnummer -> Rueckmeldung nach dem Teilen

	function hashLesen() {
		var m = /(?:^|[#&])zug=(\d{3,6})(?:&halt=([A-Z]{1,5}))?/.exec(location.hash || '');
		return m ? { zug: m[1], halt: m[2] || '' } : null;
	}
	geteilt = hashLesen();

	/** Bahnhof, der im Verlauf hervorgehoben wird: aus dem Link, sonst der Heimatbahnhof der Startseite. */
	function heimat() {
		if (geteilt && geteilt.halt) return geteilt.halt;
		try { return localStorage.getItem('nordbahn.heimat') || 'APD'; } catch (e) { return 'APD'; }
	}

	function sortZeit(z) {
		var erste = null;
		for (var i = 0; i < z._halte.length; i++) {
			var h = z._halte[i];
			var t = h.planAn || h.planAb;
			if (!t) continue;
			if (h.id === 'AP') return t;
			if (erste === null) erste = t;
		}
		return erste || 0;
	}

	function el(tag, attrs, kinder) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') n.className = attrs[k];
			else if (k === 'text') n.textContent = attrs[k];
			else if (k === 'html') n.innerHTML = attrs[k];
			else n.setAttribute(k, attrs[k]);
		});
		(kinder || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}

	/** Halteverlauf wie in der aufgeklappten Fahrt der Startseite: Soll vorn, Ist klein dahinter. */
	function verlaufHtml(z, l) {
		var naechster = l.status === 'unterwegs' ? l.nach : l.bei;
		var idxNaechster = z._halte.indexOf(naechster);
		var hb = heimat();
		var ol = el('ol', { class: 'lauf__halte' });
		z._halte.forEach(function (st, i) {
			var plan = i === 0 ? (st.planAb || st.planAn) : (st.planAn || st.planAb);
			var ist = i === 0 ? (st.ab || st.an) : (st.an || st.ab);
			var weg = st.ausfall || !st.faehrt;
			var vorbei = l.status === 'ziel' ? st !== naechster : i < idxNaechster;
			var cls = 'lauf__halt' + (i === 0 ? ' lauf__halt--start' : '') + (weg ? ' lauf__halt--ausfall' : '')
				+ (st.id === hb ? ' lauf__halt--heimat' : '') + (vorbei && !weg ? ' lauf__halt--vorbei' : '')
				+ (st === naechster && l.status !== 'ziel' ? ' lauf__halt--jetzt' : '');
			var zeit = el('span', { class: 'lauf__zeit', text: uhr(plan) || '–' });
			if (weg) zeit.appendChild(el('small', { text: 'entfällt' }));
			else if (ist && uhr(ist) !== uhr(plan)) zeit.appendChild(el('small', { class: st.verspaetung >= 3 ? 'lauf__spaet' : '', text: uhr(ist) }));
			ol.appendChild(el('li', { class: cls }, [
				zeit,
				el('span', { class: 'lauf__punkt', 'aria-hidden': 'true' }),
				el('span', { class: 'lauf__name', text: st.name }),
				el('span', { class: 'lauf__gleis', text: st.gleis && !weg ? 'Gl. ' + st.gleis : '' })
			]));
		});
		return ol;
	}

	function teilen(z) {
		var url = location.origin + location.pathname + '#zug=' + encodeURIComponent(z.zug) + '&halt=' + encodeURIComponent(heimat());
		var titel = z.linie + ' ' + z.von + ' → ' + z.nach;
		function melden(t) {
			teilenText[z.zug] = t;
			listeSchreiben();
			setTimeout(function () { if (teilenText[z.zug] === t) { delete teilenText[z.zug]; listeSchreiben(); } }, 4000);
		}
		if (navigator.share) {
			navigator.share({ title: titel, text: titel + ' live verfolgen', url: url }).catch(function () { /* abgebrochen */ });
		} else if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url).then(function () { melden('Link kopiert'); }, function () { melden(url); });
		} else {
			melden(url);
		}
	}

	function panelBauen(z, l) {
		var panel = el('div', { class: 'zugliste__auf', id: 'zug-' + z.zug }, [verlaufHtml(z, l)]);
		if (window.nbCommunity) {
			var cm = window.nbCommunity.block(z.zug, l.status !== 'ziel');
			if (cm) panel.appendChild(cm);
		}
		var knopf = el('button', { type: 'button', class: 'zugliste__teilen', text: 'Link teilen' });
		knopf.addEventListener('click', function (ev) { ev.stopPropagation(); teilen(z); });
		panel.appendChild(el('p', { class: 'zugliste__fuss' }, [
			el('span', { text: 'Zug ' + z.zug + (z.wagen ? ' · ' + z.wagen + ' Wagen' : '') }),
			teilenText[z.zug] ? el('span', { class: 'zugliste__geteilt', role: 'status', text: teilenText[z.zug] }) : null,
			knopf
		]));
		return panel;
	}

	function zeileBauen(z, l) {
		var d = l.verspaetung;
		var auf = !!offen[z.zug];
		var li = el('li', { class: 'zugliste__zug' + (auf ? ' ist-offen' : ''), 'data-zug': z.zug });
		var kopf = el('div', { class: 'zugliste__kopf', html:
			'<span class="linie linie--' + esc(linieKlasse(z.linie)) + '">' + esc(z.linie) + '</span>'
			+ '<span class="zugliste__text"><strong>' + esc(z.von) + ' &rarr; ' + esc(z.nach) + '</strong>'
			+ '<span class="zugliste__lage">' + esc(lageText(l)) + (z.hinweis ? ' &middot; ' + esc(z.hinweis) : '') + cmHinweisHtml(z) + '</span></span>'
			+ (d >= 3 ? '<span class="pill ' + (d >= 10 ? 'pill--ausfall' : 'pill--teil') + '">+' + d + '</span>' : '') });
		var knopf = el('button', { type: 'button', class: 'zugliste__knopf', 'aria-expanded': auf ? 'true' : 'false', 'aria-controls': 'zug-' + z.zug,
			'aria-label': 'Halteverlauf: ' + z.linie + ' ' + z.von + ' nach ' + z.nach });
		knopf.addEventListener('click', function () {
			if (offen[z.zug]) delete offen[z.zug]; else offen[z.zug] = true;
			listeSchreiben();
			var neu = document.querySelector('#karte-liste [data-zug="' + z.zug + '"] .zugliste__knopf');
			if (neu) neu.focus();
			try { if (offen[z.zug] && window.plausible) window.plausible('Zugverlauf'); } catch (e) { /* egal */ }
		});
		kopf.appendChild(knopf);
		li.appendChild(kopf);
		if (auf) li.appendChild(panelBauen(z, l));
		return li;
	}

	function listeSchreiben() {
		var el0 = document.getElementById('karte-liste');
		if (!el0) return;
		var now = Date.now();
		var gruppen = { hamburg: [], nord: [] };
		state.zuege.forEach(function (z) {
			var l = lage(z, now);
			if (!l) return;
			(gruppen[z.richtung === 'hamburg' ? 'hamburg' : 'nord']).push({ z: z, l: l, t: sortZeit(z) });
		});
		var frag = document.createDocumentFragment();
		var reihe = new Date().getHours() >= 12 ? [RICHTUNGEN[1], RICHTUNGEN[0]] : RICHTUNGEN;
		var hinweisGeteilt = '';
		if (geteilt && state.fetchedAt) {
			var da = gruppen.hamburg.concat(gruppen.nord).some(function (e) { return e.z.zug === geteilt.zug; });
			if (da && !geteilt.gezeigt) { offen[geteilt.zug] = true; geteilt.gezeigt = true; geteilt.scrollen = true; }
			if (!da) hinweisGeteilt = 'Zug ' + geteilt.zug + ' ist gerade nicht unterwegs, vielleicht schon angekommen.';
		}
		if (hinweisGeteilt) frag.appendChild(el('p', { class: 'zugliste__leer zugliste__geteilt-weg', text: hinweisGeteilt }));
		var n = 0;
		reihe.forEach(function (r) {
			var liste = gruppen[r.id];
			if (!liste.length) return;
			liste.sort(function (a, b) { return a.t - b.t; });
			var ul = el('ul', { class: 'zugliste' });
			liste.forEach(function (e) { ul.appendChild(zeileBauen(e.z, e.l)); n++; });
			frag.appendChild(el('h3', { class: 'zugliste__richtung', text: r.titel }));
			frag.appendChild(ul);
		});
		if (!n) frag.appendChild(el('p', { class: 'zugliste__leer', text: state.ok || state.zuege.length ? 'Gerade kein Zug auf der Karte.' : 'Gerade keine Daten vom Stationsmonitor.' }));
		el0.innerHTML = '';
		el0.appendChild(frag);
		if (geteilt && geteilt.scrollen) {
			geteilt.scrollen = false;
			var ziel = el0.querySelector('[data-zug="' + geteilt.zug + '"]');
			if (ziel && ziel.scrollIntoView) ziel.scrollIntoView({ block: 'start' });
		}
	}

	window.addEventListener('hashchange', function () {
		geteilt = hashLesen();
		listeSchreiben();
	});

	/**
	 * Hinweis nur, wenn der Stand alt ist oder der Monitor nicht antwortet; sonst bleibt die
	 * Zeile weg. Laeuft gerade ein Abruf, heisst es "Daten werden geladen" statt "nicht
	 * erreichbar": so sieht es nach dem Zurueckholen der Home-Screen-App nicht nach einer
	 * Stoerung aus, nur weil iOS die Seite im Hintergrund angehalten hat. Ein Tipp laedt neu.
	 */
	function kopfSchreiben() {
		var el = document.getElementById('karte-freshness');
		var text = document.getElementById('karte-freshness-text');
		if (!el || !text) return;
		var alt = !!state.fetchedAt && Date.now() - state.fetchedAt > STALE_MS;
		// Alter Stand und seit dem Intervall kein Abruf mehr (eingefrorene Timer): selbst nachladen
		if (alt && !laeuftNoch() && Date.now() - letzterAbruf > REFRESH_MS + 5000) refresh();
		var laedt = laeuftNoch();
		var problem = state.fetchedAt ? (alt || state.fehler) : false;
		el.hidden = !problem;
		el.classList.toggle('is-busy', laedt);
		el.classList.toggle('is-stale', problem && !laedt);
		el.classList.toggle('is-empty', problem && laedt);
		text.textContent = !problem ? '' : laedt ? 'Daten werden geladen …' : 'Stand ' + uhr(state.fetchedAt) + ' Uhr, Stationsmonitor gerade nicht erreichbar';
		el.title = problem ? 'Antippen zum Aktualisieren' : '';
	}

	/* ---------- Daten ---------- */

	function uebernehmen(daten) {
		if (!daten || typeof daten !== 'object') return;
		state.ok = !!daten.ok;
		state.fehler = !daten.ok;
		state.fetchedAt = daten.fetched_at ? Date.parse(daten.fetched_at) : state.fetchedAt;
		if (Array.isArray(daten.zuege) && (daten.ok || !state.zuege.length)) {
			state.zuege = daten.zuege.map(vorbereiten);
		}
		markerAbgleichen();
		listeSchreiben();
	}

	/*
	 * Ein Abruf, den iOS beim Wechsel in den Hintergrund einfriert, meldet sich unter
	 * Umstaenden nie wieder. Darum bricht ein Timeout ab, und ein Abruf, der laenger als
	 * das haengt, gilt nicht mehr als laufend; seine spaete Antwort zaehlt dann nicht.
	 */
	var abruf = 0;
	var laeuftSeit = 0;
	var letzterAbruf = 0;
	function laeuftNoch() {
		return !!laeuftSeit && Date.now() - laeuftSeit < TIMEOUT_MS + 2000;
	}
	function refresh() {
		if (laeuftNoch() || !window.fetch) return;
		var nr = ++abruf;
		laeuftSeit = letzterAbruf = Date.now();
		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, TIMEOUT_MS);
		kopfSchreiben();
		fetch(endpoint, { cache: 'no-store', headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
			.then(function (r) { return r.ok ? r.json() : { ok: false }; })
			.then(function (daten) { if (nr === abruf) uebernehmen(daten); })
			.catch(function () { if (nr === abruf) { state.ok = false; state.fehler = true; } })
			.then(function () {
				clearTimeout(timer);
				if (nr !== abruf) return;
				laeuftSeit = 0;
				kopfSchreiben();
			});
	}

	/** Zurueck im Vordergrund (Tab, Home-Screen-App, Netz wieder da): sofort nachrechnen und laden. */
	function aufwachen() {
		if (document.hidden) return;
		tick();
		listeSchreiben();
		// focus und visibilitychange kommen oft zusammen; ein Abruf reicht
		if (Date.now() - letzterAbruf > 5000) refresh();
	}

	var kopf = document.getElementById('karte-freshness');
	if (kopf) kopf.addEventListener('click', refresh);

	// Fahrgastmeldungen: offene Popups und die Liste mit neuen Daten neu schreiben
	if (window.nbCommunity) {
		window.nbCommunity.beiAenderung(function () {
			Object.keys(marker).forEach(function (zug) {
				var e = marker[zug];
				if (e.m.isPopupOpen()) e.m.setPopupContent(popupInhalt(e.zug));
			});
			listeSchreiben();
		});
		window.nbCommunity.start(String(endpoint || '').replace(/api\/karte$/, '') || './');
	}

	/* ---------- Ausschnitt ---------- */

	function ansichtZeigen(id, animieren) {
		var a = strecke.ansichten && strecke.ansichten[id];
		var punkte = [];
		(a ? a.stationen : Object.keys(strecke.stationen)).forEach(function (sid) {
			var p = station(sid);
			if (p) punkte.push(p);
		});
		if (!punkte.length) return;
		// rechts und unten mehr Rand: dort haengen Badge und Verspaetung an den Zuegen
		map.fitBounds(L.latLngBounds(punkte), { paddingTopLeft: [24, 36], paddingBottomRight: [100, 28], animate: !!animieren, maxZoom: 13 });
	}

	/* ---------- Start ---------- */

	// Immer das ganze Netz, unabhaengig vom Heimatbahnhof der Startseite
	ansichtZeigen('netz', false);

	uebernehmen(lesen('karte-stand'));
	refresh();
	setInterval(refresh, REFRESH_MS);
	setInterval(tick, TICK_MS);
	setInterval(listeSchreiben, LISTE_MS);
	setInterval(kopfSchreiben, 10000);
	document.addEventListener('visibilitychange', aufwachen);
	window.addEventListener('pageshow', function (e) { if (e.persisted) aufwachen(); });
	window.addEventListener('focus', aufwachen);
	window.addEventListener('online', aufwachen);
})();
