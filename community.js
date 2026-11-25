/*
 * Fahrgastmeldungen (api/community.php) fuer Startseite und Live-Karte: holt die
 * aktiven Meldungen, baut den Hinweis fuer eine Tafelzeile und den Block mit den
 * vier Knoepfen (aufgeklappte Fahrt, Popup am Zug auf der Karte) und schickt
 * Meldungen ab. Seiten melden sich mit nbCommunity.start(root) an und hoeren mit
 * nbCommunity.beiAenderung(fn) auf neue Daten.
 *
 * Im Browser bleibt nur, was man selbst gemeldet hat (localStorage
 * nordbahn.gemeldet, Zug|Art -> Zeit, nach drei Stunden verworfen), damit der
 * eigene Knopf gedrueckt aussieht und ein zweiter Tipp die Meldung zuruecknimmt.
 * Der Wert geht nirgendwohin ausser in diese Anzeige.
 */
(function () {
	'use strict';

	var ARTEN = [
		{ id: 'steht', text: 'Zug steht' },
		{ id: 'voll', text: 'Sehr voll' },
		{ id: 'kurz', text: 'Kurzer Zug' },
		{ id: 'klima', text: 'Klima/Heizung aus' }
	];
	var TEXT = {};
	ARTEN.forEach(function (a) { TEXT[a.id] = a.text; });
	var REFRESH_MS = 60 * 1000;
	var EIGENE_KEY = 'nordbahn.gemeldet';
	var EIGENE_MS = 3 * 60 * 60 * 1000;

	var state = { url: '', an: false, zuege: {}, geladen: false, laeuft: false, zuletzt: 0, meldung: {} };
	var hoerer = [];

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') node.className = attrs[k];
			else if (k === 'text') node.textContent = attrs[k];
			else node.setAttribute(k, attrs[k]);
		});
		(children || []).forEach(function (c) {
			if (c !== null && c !== undefined) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function uhr(iso) {
		var d = new Date(iso);
		if (isNaN(d)) return '';
		try { return d.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; }
	}

	function track(art) {
		try { if (window.plausible) window.plausible('Fahrgastmeldung', { props: { art: TEXT[art] || art } }); } catch (e) { /* egal */ }
	}

	/* ---------- Eigene Meldungen im Browser ---------- */

	function eigeneLesen() {
		try {
			var roh = JSON.parse(localStorage.getItem(EIGENE_KEY) || '{}');
			var jetzt = Date.now(), out = {};
			Object.keys(roh || {}).forEach(function (k) { if (jetzt - roh[k] < EIGENE_MS) out[k] = roh[k]; });
			return out;
		} catch (e) { return {}; }
	}
	function eigeneSetzen(zug, art, an) {
		try {
			var e = eigeneLesen();
			if (an) e[zug + '|' + art] = Date.now(); else delete e[zug + '|' + art];
			if (Object.keys(e).length) localStorage.setItem(EIGENE_KEY, JSON.stringify(e));
			else localStorage.removeItem(EIGENE_KEY);
		} catch (err) { /* privater Modus: dann eben ohne Merken */ }
	}
	function selbstGemeldet(zug, art) {
		return !!eigeneLesen()[zug + '|' + art];
	}

	/* ---------- Daten ---------- */

	function melden() {
		hoerer.forEach(function (fn) { try { fn(); } catch (e) { /* naechster */ } });
	}

	function uebernehmen(daten) {
		if (!daten || typeof daten !== 'object') return;
		if (typeof daten.an === 'boolean') state.an = daten.an;
		if (daten.zuege && typeof daten.zuege === 'object') state.zuege = daten.zuege;
		state.geladen = true;
		melden();
	}

	function laden() {
		if (!state.url || state.laeuft || !window.fetch) return;
		state.laeuft = true;
		state.zuletzt = Date.now();
		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 15000);
		fetch(state.url, { cache: 'no-store', headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(uebernehmen)
			.catch(function () { /* naechster Versuch im Intervall */ })
			.then(function () { clearTimeout(timer); state.laeuft = false; });
	}

	function senden(zug, art, aktion) {
		var form = new URLSearchParams();
		form.set('zug', zug);
		form.set('art', art);
		form.set('aktion', aktion);
		state.meldung[zug] = { text: 'Wird gesendet …', fehler: false };
		melden();
		return fetch(state.url, { method: 'POST', body: form, headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
			.then(function (antwort) {
				var ok = antwort && antwort.ok;
				if (ok && aktion === 'melden') { eigeneSetzen(zug, art, true); track(art); }
				if (ok && aktion === 'zurueck') eigeneSetzen(zug, art, false);
				if (ok && aktion === 'stimmt_nicht') eigeneSetzen(zug, art, false);
				state.meldung[zug] = ok
					? { text: aktion === 'melden' ? 'Danke, gemeldet. Nochmal tippen nimmt es zurück.' : aktion === 'zurueck' ? 'Zurückgenommen.' : 'Danke, ausgeblendet.', fehler: false }
					: { text: fehlerText(antwort && antwort.status), fehler: true };
				if (ok && antwort.zuege) uebernehmen(antwort); else melden();
				statusWeg(zug);
			})
			.catch(function () {
				state.meldung[zug] = { text: 'Gerade keine Verbindung. Bitte gleich nochmal.', fehler: true };
				melden();
				statusWeg(zug);
			});
	}

	/** Rueckmeldung unter den Knoepfen nach ein paar Sekunden wieder leeren. */
	function statusWeg(zug) {
		var m = state.meldung[zug];
		setTimeout(function () {
			if (state.meldung[zug] === m) { delete state.meldung[zug]; melden(); }
		}, 6000);
	}

	function fehlerText(status) {
		if (status === 'limit') return 'Genug gemeldet für den Moment. Danke!';
		if (status === 'unbekannter_zug') return 'Für diesen Zug geht das gerade nicht, er fährt noch nicht oder ist schon angekommen.';
		return 'Hat nicht geklappt. Bitte gleich nochmal.';
	}

	/* ---------- Bausteine fuer die Seiten ---------- */

	/** Aktive Meldungen eines Zugs, wichtigste zuerst. */
	function fuer(zug) {
		var liste = (zug && state.zuege[zug]) || [];
		return liste.slice().sort(function (a, b) {
			return ARTEN.findIndex(function (x) { return x.id === a.art; }) - ARTEN.findIndex(function (x) { return x.id === b.art; });
		});
	}

	/** Kleiner Hinweis fuer Tafelzeile oder Zugliste, null ohne Meldung. */
	function hinweis(zug) {
		var liste = fuer(zug);
		if (!state.an || !liste.length) return null;
		var a = liste[0];
		return el('span', { class: 'cm-hinweis-zeile', title: 'Ungeprüfte Angabe von Fahrgästen' }, [
			'Fahrgäste: ',
			el('span', { class: 'cm-hinweis cm-hinweis--' + a.art, text: TEXT[a.art] })
		]);
	}

	/**
	 * Block mit den aktiven Meldungen und den Knoepfen. erlaubt: ob gerade gemeldet
	 * werden darf (Zug faehrt oder faehrt gleich ab). null, wenn nichts zu zeigen ist.
	 */
	function block(zug, erlaubt) {
		if (!state.an || !zug) return null;
		var liste = fuer(zug);
		if (!erlaubt && !liste.length) return null;
		var box = el('div', { class: 'cm', 'data-cm-zug': zug });
		if (liste.length) {
			var ul = el('ul', { class: 'cm__aktiv' });
			liste.forEach(function (m) {
				var li = el('li', { class: 'cm__meldung cm__meldung--' + m.art }, [
					el('span', {}, [el('strong', { text: TEXT[m.art] }), ' · ' + (m.anzahl === 1 ? '1 Fahrgast' : m.anzahl + ' Fahrgäste') + ' · ' + uhr(m.zuletzt)])
				]);
				if (erlaubt) {
					var nein = el('button', { type: 'button', class: 'cm__nein', text: 'Stimmt nicht mehr', 'aria-label': TEXT[m.art] + ': stimmt nicht mehr' });
					nein.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); senden(zug, m.art, 'stimmt_nicht'); });
					li.appendChild(nein);
				}
				ul.appendChild(li);
			});
			box.appendChild(ul);
		}
		if (erlaubt) {
			box.appendChild(el('p', { class: 'cm__frage', text: 'Wie ist es in diesem Zug?' }));
			var knoepfe = el('div', { class: 'cm__knoepfe' });
			ARTEN.forEach(function (a) {
				var meins = selbstGemeldet(zug, a.id);
				var b = el('button', { type: 'button', class: 'cm__knopf cm__knopf--' + a.id, 'aria-pressed': meins ? 'true' : 'false', text: a.text });
				b.addEventListener('click', function (ev) {
					ev.preventDefault();
					ev.stopPropagation();
					senden(zug, a.id, selbstGemeldet(zug, a.id) ? 'zurueck' : 'melden');
				});
				knoepfe.appendChild(b);
			});
			box.appendChild(knoepfe);
		}
		var m = state.meldung[zug];
		box.appendChild(el('p', { class: 'cm__status' + (m && m.fehler ? ' cm__status--fehler' : ''), role: 'status', text: m ? m.text : '' }));
		box.appendChild(el('p', { class: 'cm__fuss', text: 'Ungeprüfte Angaben von Fahrgästen, keine Auskunft der Nordbahn. Gilt nur für diese Fahrt.' }));
		return box;
	}

	function start(root) {
		if (state.url) return;
		try { state.url = new URL((root || './') + 'api/community', document.baseURI).href; } catch (e) { state.url = (root || './') + 'api/community'; }
		state.an = true;
		laden();
		setInterval(function () { if (!document.hidden) laden(); }, REFRESH_MS);
		function aufwachen() { if (!document.hidden && Date.now() - state.zuletzt > 5000) laden(); }
		document.addEventListener('visibilitychange', aufwachen);
		window.addEventListener('pageshow', aufwachen);
		window.addEventListener('online', aufwachen);
	}

	window.nbCommunity = {
		start: start,
		laden: laden,
		hinweis: hinweis,
		block: block,
		aktiv: function () { return state.an && state.geladen; },
		beiAenderung: function (fn) { hoerer.push(fn); }
	};
})();
