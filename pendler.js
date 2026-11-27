/*
 * Pendlermeldungen (api/pendler.php): kurze Texte von Fahrgaesten zu Thema und Bahnhof,
 * optional an einer Fahrt. Dieses Script holt die laufenden Meldungen, zeichnet sie auf
 * der Startseite (Kasten unter "Aktuelle Meldungen"), in der aufgeklappten Fahrt der
 * Tafel und auf /aktuell/, und bringt den Dialog zum Schreiben mit.
 *
 * Seiten melden sich mit nbPendler.start(root, { heimat: fn }) an und hoeren mit
 * nbPendler.beiAenderung(fn) auf neue Daten. Bausteine: liste(container, optionen),
 * zugBlock(zug, erlaubt, vorgabe), zeilenSymbol(zug), schreiben(vorgabe), einladen(an).
 *
 * Im Browser bleibt (localStorage nordbahn.pendler, nach 24 Stunden verworfen), welche
 * Meldungen man selbst geschrieben hat (mit dem Zufallsgeheimnis zum Loeschen) und wie
 * man abgestimmt hat, damit die Knoepfe gedrueckt aussehen. Der Wert geht nirgendwohin
 * ausser beim Loeschen das Geheimnis der eigenen Meldung an api/pendler.
 */
(function () {
	'use strict';

	var THEMEN = [
		{ id: 'lage', text: 'Lage unterwegs', symbol: '📢', beispiel: 'Durchsage: Personen im Gleis bei Tornesch, Weiterfahrt in ca. 20 Min.' },
		{ id: 'sev', text: 'Ersatzverkehr', symbol: '🚌', beispiel: 'SEV-Bus hält am ZOB Steig 4, nicht vor dem Bahnhof.' },
		{ id: 'bahnhof', text: 'Am Bahnhof', symbol: '🚉', beispiel: 'Aufzug zu Gleis 2 außer Betrieb.' },
		{ id: 'tipp', text: 'Tipp', symbol: '💡', beispiel: 'RE7 ab Elmshorn 7:42 hat noch Platz und ist schneller.' }
	];
	var THEMA = {};
	THEMEN.forEach(function (t) { THEMA[t.id] = t; });
	// STRECKE: ohne bestimmten Bahnhof
	var ORTE = [
		['STRECKE', 'Ohne Bahnhof'], ['AH', 'Hamburg Hbf'], ['ADF', 'Dammtor'], ['AA', 'Altona'], ['AP', 'Pinneberg'], ['APD', 'Prisdorf'], ['ATM', 'Tornesch'],
		['AEL', 'Elmshorn'], ['AHZH', 'Herzhorn'], ['AGST', 'Glückstadt'], ['AKM', 'Krempe'], ['AKHD', 'Kremperheide'], ['AIZ', 'Itzehoe'],
		['AHOT', 'Horst (Holst)'], ['ADH', 'Dauenhof'], ['AWST', 'Wrist']
	];
	var MAX_TEXT = 200;
	var REFRESH_MS = 60 * 1000;
	var SPEICHER_KEY = 'nordbahn.pendler';
	var SPEICHER_MS = 24 * 60 * 60 * 1000;
	var LOESCHEN_MS = 3 * 60 * 60 * 1000;
	var GRUENDE = {
		zu_kurz: 'Bitte schreib ein paar Worte mehr (mindestens 10 Zeichen).',
		zu_lang: 'Bitte kürzer, höchstens 200 Zeichen.',
		link: 'Links veröffentlichen wir nicht.',
		kontakt: 'Telefonnummern und E-Mail-Adressen veröffentlichen wir nicht.',
		kontrolle: 'Hinweise auf Fahrkartenkontrollen veröffentlichen wir nicht.',
		beleidigung: 'Bitte ohne Beleidigungen.',
		person: 'Bitte keine Personen beschreiben oder nennen, auch kein Personal.',
		werbung: 'Werbung veröffentlichen wir nicht.',
		thema: 'Das scheint nichts mit der Bahn zu tun zu haben.',
		sonst: 'Diese Meldung können wir so nicht veröffentlichen.'
	};
	var STATUS = {
		limit: 'Du hast gerade schon einiges gemeldet. Bitte versuch es später noch mal.',
		gesperrt: 'Von hier geht heute keine Meldung mehr.',
		doppelt: 'Genau das hat schon jemand gemeldet. Bestätige es gern mit „Stimmt noch“.',
		ungueltig: 'Bitte wähle ein Thema und einen Bahnhof.',
		fehler: 'Das hat nicht geklappt. Bitte versuch es gleich noch mal.'
	};

	var state = { url: '', an: false, geladen: false, laeuft: false, zuletzt: 0, meldungen: [], heimat: null, einladen: false, status: {} };
	var hoerer = [];

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') node.className = attrs[k];
			else if (k === 'text') node.textContent = attrs[k];
			else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) node.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
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

	/** "gerade eben", "vor 4 Min.", "vor 2 Std." */
	function wann(iso) {
		var min = Math.round((Date.now() - Date.parse(iso)) / 60000);
		if (isNaN(min)) return '';
		if (min < 1) return 'gerade eben';
		if (min < 60) return 'vor ' + min + ' Min.';
		return 'vor ' + Math.floor(min / 60) + ' Std.';
	}

	function track(name, props) {
		try { if (window.plausible) window.plausible(name, { props: props || {} }); } catch (e) { /* egal */ }
	}

	/* ---------- Eigenes im Browser ---------- */

	function speicherLesen() {
		try {
			var roh = JSON.parse(localStorage.getItem(SPEICHER_KEY) || '{}') || {};
			var jetzt = Date.now(), out = { eigene: {}, stimmen: {} };
			['eigene', 'stimmen'].forEach(function (teil) {
				Object.keys(roh[teil] || {}).forEach(function (id) {
					var v = roh[teil][id];
					if (v && jetzt - v.t < SPEICHER_MS) out[teil][id] = v;
				});
			});
			return out;
		} catch (e) { return { eigene: {}, stimmen: {} }; }
	}
	function speicherSchreiben(s) {
		try {
			if (Object.keys(s.eigene).length || Object.keys(s.stimmen).length) localStorage.setItem(SPEICHER_KEY, JSON.stringify(s));
			else localStorage.removeItem(SPEICHER_KEY);
		} catch (e) { /* privater Modus: dann eben ohne Merken */ }
	}
	function eigene(id) {
		var e = speicherLesen().eigene[id];
		return e && Date.now() - e.t < LOESCHEN_MS ? e : null;
	}
	function stimme(id) {
		var s = speicherLesen().stimmen[id];
		return s ? s.a : '';
	}

	function geheimnis() {
		var bytes = new Uint8Array(16);
		try { window.crypto.getRandomValues(bytes); } catch (e) { for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256); }
		return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}

	/* ---------- Daten ---------- */

	function melden() {
		hoerer.forEach(function (fn) { try { fn(); } catch (e) { /* naechster */ } });
	}

	function uebernehmen(daten) {
		if (!daten || typeof daten !== 'object') return;
		if (typeof daten.an === 'boolean') state.an = daten.an;
		if (Array.isArray(daten.meldungen)) state.meldungen = daten.meldungen;
		state.geladen = true;
		melden();
	}

	function laden() {
		if (!state.url || state.laeuft || !window.fetch) return;
		state.laeuft = true;
		state.zuletzt = Date.now();
		fetch(state.url, { cache: 'no-store', headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(uebernehmen)
			.catch(function () { /* naechster Versuch im Intervall */ })
			.then(function () { state.laeuft = false; });
	}

	function post(felder) {
		var form = new URLSearchParams();
		Object.keys(felder).forEach(function (k) { if (felder[k] !== null && felder[k] !== undefined) form.set(k, felder[k]); });
		return fetch(state.url, { method: 'POST', body: form, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.json().catch(function () { return { ok: false, status: 'fehler' }; }); });
	}

	/** Stimmt noch, stimmt nicht mehr, unangemessen oder loeschen. */
	function aktion(id, was) {
		var felder = { aktion: was, id: id };
		if (was === 'loeschen') {
			var e = eigene(id);
			if (!e) return;
			felder.geheim = e.g;
		}
		state.status[id] = { text: 'Wird gesendet …', fehler: false };
		melden();
		post(felder).then(function (antwort) {
			var ok = antwort && antwort.ok;
			var s = speicherLesen();
			if (ok && was === 'loeschen') delete s.eigene[id];
			else if (ok) s.stimmen[id] = { a: was, t: Date.now() };
			speicherSchreiben(s);
			if (ok && was === 'stimmt') track('Pendlermeldung bestätigt');
			var texte = { bestaetigt: 'Danke, bestätigt.', notiert: was === 'unangemessen' ? 'Danke, wir schauen es uns an.' : 'Danke für den Hinweis.', beendet: 'Danke, ausgeblendet.', gemeldet: 'Danke, ausgeblendet, bis wir es geprüft haben.', geloescht: 'Gelöscht.' };
			state.status[id] = ok ? { text: texte[antwort.status] || 'Danke.', fehler: false }
				: { text: antwort && antwort.status === 'vorbei' ? 'Diese Meldung ist schon abgelaufen.' : antwort && antwort.status === 'limit' ? STATUS.limit : STATUS.fehler, fehler: true };
			if (antwort && Array.isArray(antwort.meldungen)) uebernehmen(antwort); else melden();
			statusWeg(id);
		}).catch(function () {
			state.status[id] = { text: STATUS.fehler, fehler: true };
			melden();
			statusWeg(id);
		});
	}

	function statusWeg(id) {
		var m = state.status[id];
		setTimeout(function () { if (state.status[id] === m) { delete state.status[id]; melden(); } }, 6000);
	}

	/* ---------- Bausteine ---------- */

	function themaText(m) { return (THEMA[m.thema] || {}).text || m.thema; }
	function themaSymbol(m) { return (THEMA[m.thema] || {}).symbol || ''; }

	/** Knoepfe unter einer Meldung: eigene loeschen, sonst stimmt noch / stimmt nicht mehr / melden. */
	function knoepfe(m, meta) {
		var box = el('div', { class: 'pd__aktionen' });
		if (meta) box.appendChild(el('span', { class: 'pd__meta', text: meta }));
		function knopf(text, cls, was, gedrueckt, label) {
			var b = el('button', { type: 'button', class: 'pd__knopf ' + cls, 'aria-pressed': gedrueckt === null ? null : (gedrueckt ? 'true' : 'false'), 'aria-label': label || null, text: text });
			b.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); aktion(m.id, was); });
			return b;
		}
		if (eigene(m.id)) {
			box.appendChild(el('span', { class: 'pd__eigene', text: 'Deine Meldung' }));
			box.appendChild(knopf('Löschen', 'pd__knopf--leise', 'loeschen', null, 'Eigene Meldung löschen'));
		} else {
			var meine = stimme(m.id);
			box.appendChild(knopf('Stimmt noch' + (m.stimmt ? ' · ' + m.stimmt : ''), 'pd__knopf--ja', 'stimmt', meine === 'stimmt', 'Stimmt noch, ' + (m.stimmt === 1 ? 'einmal' : m.stimmt + '-mal') + ' bestätigt'));
			box.appendChild(knopf('Nicht mehr', '', 'stimmt_nicht', meine === 'stimmt_nicht', 'Stimmt nicht mehr'));
			// Fahne statt Wort: "Melden" hiesse auf dieser Seite auch "etwas schreiben"
			var fahne = knopf('\u2691', 'pd__knopf--leise pd__knopf--fahne', 'unangemessen', meine === 'unangemessen', 'Als unpassend melden');
			fahne.title = 'Als unpassend melden';
			box.appendChild(fahne);
		}
		var s = state.status[m.id];
		box.appendChild(el('span', { class: 'pd__status' + (s && s.fehler ? ' pd__status--fehler' : ''), role: 'status', text: s ? s.text : '' }));
		return box;
	}

	/** " · Bahnhof" fuer Ueberschriften, leer bei Meldungen ohne Bahnhof. */
	function ortZusatz(m) { return m.bhf === 'STRECKE' ? '' : ' · ' + m.bhf_name; }

	/** Eine Meldung als Eintrag der Liste (gleiches Raster wie die anderen Meldungen). */
	function eintrag(m) {
		return el('li', { class: 'pd', 'data-pd-id': m.id }, [
			el('div', { class: 'feed__time' }, [wann(m.zeit), el('span', { text: 'bis ' + uhr(m.bis) })]),
			el('div', {}, [
				el('p', { class: 'feed__kicker feed__kicker--pendler', text: 'Pendlermeldung · ungeprüft' }),
				el('p', { class: 'feed__title' }, [
					el('span', { class: 'pd__symbol', 'aria-hidden': 'true', text: themaSymbol(m) }),
					themaText(m) + ortZusatz(m)
				]),
				el('p', { class: 'pd__text', text: m.text }),
				// Auf dem Handy faellt die Zeitspalte weg, die Zeit steht dann vor den Knoepfen
				knoepfe(m, wann(m.zeit))
			])
		]);
	}

	/** Reihenfolge fuer die Startseite: eigener Bahnhof, dann "Lage unterwegs", sonst juengste zuerst. */
	function sortiert(liste) {
		var heimat = state.heimat ? (state.heimat() || {}).id : '';
		function rang(m) { return m.bhf === heimat ? 0 : m.thema === 'lage' ? 1 : 2; }
		return liste.slice().sort(function (a, b) { return rang(a) - rang(b) || (a.zeit < b.zeit ? 1 : -1); });
	}

	/**
	 * Zeichnet die Meldungen in container (ein Element mit leerem Inhalt). Optionen:
	 * max (Startseite 3), bhf (nur dieser Bahnhof), alleUrl (Link "Alle ..."), immer (auch
	 * ohne Meldung zeigen, mit Knopf). Gibt die Zahl der gezeigten Meldungen zurueck und
	 * versteckt den container, wenn es nichts zu zeigen gibt.
	 */
	function liste(container, opt) {
		opt = opt || {};
		while (container.firstChild) container.removeChild(container.firstChild);
		if (!state.an || !state.geladen) { container.hidden = true; return 0; }
		var alle = state.meldungen.filter(function (m) { return !opt.bhf || m.bhf === opt.bhf || m.bhf === 'STRECKE'; });
		var zeigen = opt.max ? sortiert(alle).slice(0, opt.max) : alle;
		if (!zeigen.length && !opt.immer && !state.einladen) { container.hidden = true; return 0; }
		if (zeigen.length) {
			var ol = el('ol', { class: 'feed pd-liste' });
			zeigen.forEach(function (m) { ol.appendChild(eintrag(m)); });
			container.appendChild(ol);
		} else if (opt.immer) {
			container.appendChild(el('p', { class: 'feed__empty', text: opt.bhf ? 'Gerade keine Pendlermeldung zu diesem Bahnhof.' : 'Gerade keine Pendlermeldung.' }));
		}
		var fuss = el('div', { class: 'pd-fuss' });
		var frage = !zeigen.length && state.einladen ? 'Was siehst du vor Ort? Erzähl es den anderen.' : '';
		if (frage) fuss.appendChild(el('span', { class: 'pd-fuss__frage', text: frage }));
		var neu = el('button', { type: 'button', class: 'pd-fuss__neu', text: '+ Etwas melden' });
		neu.addEventListener('click', function () { schreiben({ bhf: opt.bhf || '' }); });
		fuss.appendChild(neu);
		if (opt.alleUrl && alle.length > zeigen.length) {
			fuss.appendChild(el('a', { class: 'pd-fuss__alle', href: opt.alleUrl, text: 'Alle ' + alle.length + ' Pendlermeldungen' }));
		}
		container.appendChild(fuss);
		container.hidden = false;
		return zeigen.length;
	}

	function fuerZug(zug) {
		return zug ? state.meldungen.filter(function (m) { return String(m.zug) === String(zug); }) : [];
	}

	/** Kleines Symbol fuer die Tafelzeile, null ohne Meldung zum Zug. */
	function zeilenSymbol(zug) {
		var n = state.an ? fuerZug(zug).length : 0;
		if (!n) return null;
		return el('span', { class: 'pd-symbol', title: n === 1 ? 'Eine Pendlermeldung zu diesem Zug' : n + ' Pendlermeldungen zu diesem Zug' }, [
			el('span', { 'aria-hidden': 'true', text: '💬' }),
			el('span', { class: 'pd-symbol__text', text: n === 1 ? '1 Pendlermeldung' : n + ' Pendlermeldungen' })
		]);
	}

	/**
	 * Block fuer die aufgeklappte Fahrt: Meldungen zum Zug und, wenn gerade gemeldet werden
	 * darf, "Mehr erzählen". vorgabe: { bhf, zugText } fuer den Dialog. null, wenn nichts da ist.
	 */
	function zugBlock(zug, erlaubt, vorgabe) {
		if (!state.an || !zug) return null;
		var liste = fuerZug(zug);
		if (!liste.length && !erlaubt) return null;
		var box = el('div', { class: 'pd-zug' });
		liste.forEach(function (m) {
			box.appendChild(el('div', { class: 'pd-zug__meldung' }, [
				el('p', { class: 'pd-zug__kopf' }, [
					el('span', { 'aria-hidden': 'true', text: themaSymbol(m) + ' ' }),
					el('strong', { text: themaText(m) }),
					ortZusatz(m) + ' · ' + wann(m.zeit)
				]),
				el('p', { class: 'pd__text', text: m.text }),
				knoepfe(m)
			]));
			box.lastChild.insertBefore(el('p', { class: 'feed__kicker feed__kicker--pendler', text: 'Pendlermeldung · ungeprüft' }), box.lastChild.firstChild);
		});
		if (erlaubt) {
			var b = el('button', { type: 'button', class: 'pd-zug__neu', text: liste.length ? '+ Noch etwas melden' : '+ Mehr erzählen, z. B. eine Durchsage' });
			b.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				schreiben({ bhf: (vorgabe && vorgabe.bhf) || '', zug: zug, zugText: vorgabe && vorgabe.zugText, thema: 'lage' });
			});
			box.appendChild(b);
		}
		return box;
	}

	/* ---------- Dialog ---------- */

	var dlg = null;

	function dialogBauen() {
		var formId = 'pd-form';
		var themen = el('div', { class: 'pd-themen', role: 'radiogroup', 'aria-labelledby': 'pd-thema-label' });
		THEMEN.forEach(function (t) {
			var input = el('input', { type: 'radio', name: 'thema', value: t.id, id: 'pd-thema-' + t.id, class: 'pd-themen__input' });
			themen.appendChild(input);
			themen.appendChild(el('label', { for: 'pd-thema-' + t.id, class: 'pd-themen__chip' }, [el('span', { 'aria-hidden': 'true', text: t.symbol + ' ' }), t.text]));
		});
		var bhf = el('select', { id: 'pd-bhf', name: 'bhf' });
		ORTE.forEach(function (o) { bhf.appendChild(el('option', { value: o[0], text: o[1] })); });
		var zugZeile = el('label', { class: 'pd-form__zug', for: 'pd-zug' }, [el('input', { type: 'checkbox', id: 'pd-zug', name: 'mit_zug', checked: true }), el('span', { class: 'pd-form__zugtext' })]);
		var text = el('textarea', { id: 'pd-text', name: 'text', maxlength: String(MAX_TEXT), rows: '3', required: true });
		var zaehler = el('span', { class: 'pd-form__zaehler', 'aria-live': 'polite', text: '0/' + MAX_TEXT });
		var senden = el('button', { type: 'submit', class: 'feedback-form__submit', text: 'Veröffentlichen' });
		var status = el('p', { class: 'feedback-form__status', role: 'status' });
		var form = el('form', { class: 'feedback-form pd-form', id: formId, novalidate: true }, [
			el('div', { class: 'feedback-dialog__head' }, [
				el('h2', { class: 'feedback-dialog__title', id: 'pd-titel', text: 'Pendlermeldung' }),
				el('button', { type: 'button', class: 'feedback-dialog__close', 'aria-label': 'Schließen', text: '×' })
			]),
			el('p', { class: 'feedback-dialog__intro', text: 'Was wissen andere Pendler noch nicht? Kurz und sachlich, ohne Namen.' }),
			el('div', { class: 'feedback-form__hp', 'aria-hidden': 'true' }, [el('label', { for: 'pd-website', text: 'Website' }), el('input', { type: 'text', id: 'pd-website', name: 'website', tabindex: '-1', autocomplete: 'off' })]),
			el('p', { class: 'pd-form__label', id: 'pd-thema-label', text: 'Thema' }),
			themen,
			el('label', { for: 'pd-bhf', text: 'Bahnhof' }),
			bhf,
			zugZeile,
			el('div', { class: 'pd-form__textkopf' }, [el('label', { for: 'pd-text', text: 'Was ist los?' }), zaehler]),
			text,
			senden,
			status,
			el('p', { class: 'feedback-form__hinweis' }, [
				'Öffentlich sichtbar, ungeprüft und ohne deinen Namen. Keine Personen, keine Kontrollen, keine Links. ',
				el('a', { href: (state.root || './') + 'nutzungsbedingungen/#pendler', text: 'Regeln' })
			])
		]);
		var dialog = el('dialog', { class: 'feedback-dialog pd-dialog', 'aria-labelledby': 'pd-titel' }, [form]);
		document.body.appendChild(dialog);

		function zu() {
			if (typeof dialog.close === 'function' && dialog.open) dialog.close();
			else dialog.removeAttribute('open');
		}
		form.querySelector('.feedback-dialog__close').addEventListener('click', zu);
		dialog.addEventListener('click', function (ev) { if (ev.target === dialog) zu(); });
		function platzhalter() {
			var t = form.querySelector('input[name="thema"]:checked');
			text.setAttribute('placeholder', t ? 'z. B. ' + THEMA[t.value].beispiel : 'Erst ein Thema wählen');
		}
		themen.addEventListener('change', platzhalter);
		text.addEventListener('input', function () {
			zaehler.textContent = text.value.length + '/' + MAX_TEXT;
			zaehler.classList.toggle('is-knapp', text.value.length > MAX_TEXT - 20);
		});
		function setStatus(t, ok) {
			status.textContent = t;
			status.className = 'feedback-form__status ' + (ok ? 'is-ok' : 'is-fehler');
		}
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var thema = form.querySelector('input[name="thema"]:checked');
			if (!thema) { setStatus('Bitte wähle ein Thema.', false); themen.querySelector('input').focus(); return; }
			if (text.value.trim().length < 10) { setStatus(GRUENDE.zu_kurz, false); text.focus(); return; }
			var g = geheimnis();
			var felder = {
				aktion: 'schreiben', thema: thema.value, bhf: bhf.value, text: text.value.trim(), geheim: g,
				website: form.querySelector('[name="website"]').value,
				zug: dlg.zug && form.querySelector('#pd-zug').checked ? dlg.zug : null
			};
			senden.disabled = true;
			senden.textContent = 'Wird geprüft …';
			post(felder).then(function (antwort) {
				antwort = antwort || {};
				if (antwort.ok) {
					var s = speicherLesen();
					if (antwort.id) s.eigene[antwort.id] = { g: g, t: Date.now() };
					speicherSchreiben(s);
					track('Pendlermeldung', { thema: THEMA[thema.value].text, status: antwort.status });
					setStatus(antwort.status === 'wartet' ? 'Danke! Wird noch geprüft und erscheint dann.' : 'Danke, ist online.', true);
					text.value = '';
					zaehler.textContent = '0/' + MAX_TEXT;
					if (Array.isArray(antwort.meldungen)) uebernehmen(antwort);
					setTimeout(zu, 1600);
				} else if (antwort.status === 'abgelehnt') {
					setStatus(GRUENDE[antwort.grund] || GRUENDE.sonst, false);
				} else {
					setStatus(STATUS[antwort.status] || STATUS.fehler, false);
				}
			}).catch(function () { setStatus(STATUS.fehler, false); })
				.then(function () { senden.disabled = false; senden.textContent = 'Veröffentlichen'; });
		});
		return { dialog: dialog, form: form, bhf: bhf, text: text, zugZeile: zugZeile, status: status, platzhalter: platzhalter, zug: null };
	}

	/** Oeffnet den Dialog. vorgabe: { thema, bhf, zug, zugText } */
	function schreiben(vorgabe) {
		if (!state.url) return;
		vorgabe = vorgabe || {};
		if (!dlg) dlg = dialogBauen();
		var heimat = state.heimat ? (state.heimat() || {}).id : '';
		dlg.bhf.value = vorgabe.bhf || heimat || 'APD';
		dlg.zug = vorgabe.zug || null;
		dlg.zugZeile.hidden = !dlg.zug;
		dlg.zugZeile.querySelector('input').checked = true;
		dlg.zugZeile.querySelector('.pd-form__zugtext').textContent = 'Gilt für den Zug ' + (vorgabe.zugText || dlg.zug);
		Array.prototype.forEach.call(dlg.form.querySelectorAll('input[name="thema"]'), function (r) { r.checked = r.value === vorgabe.thema; });
		dlg.platzhalter();
		dlg.status.textContent = '';
		dlg.status.className = 'feedback-form__status';
		if (typeof dlg.dialog.showModal === 'function') dlg.dialog.showModal();
		else dlg.dialog.setAttribute('open', '');
		(vorgabe.thema ? dlg.text : dlg.form.querySelector('input[name="thema"]')).focus();
	}

	function start(root, opt) {
		if (state.url) return;
		opt = opt || {};
		state.root = root || './';
		try { state.url = new URL(state.root + 'api/pendler', document.baseURI).href; } catch (e) { state.url = state.root + 'api/pendler'; }
		state.heimat = opt.heimat || null;
		state.an = true;
		laden();
		setInterval(function () { if (!document.hidden) laden(); }, REFRESH_MS);
		// Relative Zeiten ("vor 4 Min.") und abgelaufene Meldungen auch ohne neuen Abruf nachziehen
		setInterval(function () {
			var jetzt = Date.now();
			state.meldungen = state.meldungen.filter(function (m) { return Date.parse(m.bis) > jetzt; });
			if (!document.hidden) melden();
		}, 30000);
		function aufwachen() { if (!document.hidden && Date.now() - state.zuletzt > 5000) laden(); }
		document.addEventListener('visibilitychange', aufwachen);
		window.addEventListener('pageshow', aufwachen);
		window.addEventListener('online', aufwachen);
	}

	window.nbPendler = {
		start: start,
		laden: laden,
		liste: liste,
		zugBlock: zugBlock,
		zeilenSymbol: zeilenSymbol,
		schreiben: schreiben,
		einladen: function (an) { an = !!an; if (state.einladen !== an) { state.einladen = an; melden(); } },
		aktiv: function () { return state.an && state.geladen; },
		beiAenderung: function (fn) { hoerer.push(fn); }
	};

	// "Melden" in der Kopfleiste: Dialog direkt oeffnen, wo pendler.js laeuft
	document.addEventListener('click', function (ev) {
		var a = ev.target && ev.target.closest ? ev.target.closest('.pd-kopf-melden') : null;
		if (!a || !state.url || ev.metaKey || ev.ctrlKey || ev.shiftKey) return;
		ev.preventDefault();
		schreiben({});
	});

	// Seiten ohne dashboard.js (/aktuell/): Container mit data-pendler-liste selbst fuellen
	document.addEventListener('DOMContentLoaded', function () {
		var box = document.querySelector('[data-pendler-liste]');
		if (!box) return;
		var filter = document.getElementById('pendler-filter');
		function zeichnen() { liste(box, { immer: true, bhf: filter ? filter.value : '' }); }
		if (filter) {
			filter.hidden = false;
			filter.addEventListener('change', zeichnen);
		}
		var heimat = '';
		try { heimat = localStorage.getItem('nordbahn.heimat') || ''; } catch (e) { /* egal */ }
		start(box.getAttribute('data-root') || './', { heimat: function () { return { id: heimat }; } });
		window.nbPendler.beiAenderung(zeichnen);
		// Von "Melden" in der Kopfleiste einer anderen Seite: Dialog gleich oeffnen
		if (/[?&]melden=1/.test(location.search)) {
			try { history.replaceState(null, '', location.pathname + location.hash); } catch (e) { /* egal */ }
			schreiben({});
		}
	});
})();
