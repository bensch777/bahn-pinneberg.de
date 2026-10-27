/*
 * Nordbahn Monitor: baut aus dem eingebetteten JSON (siehe index.php) das
 * Dashboard, holt jede Minute frische Daten von api/data und laedt die
 * Seite einmal pro Stunde komplett neu.
 *
 * Alle Texte aus den Daten (Titel, Meldungstexte, Bahnhofsnamen) stammen von
 * fremden Seiten und landen deshalb ausschliesslich per textContent im DOM.
 * Zeiten werden in Europe/Berlin gerechnet, egal wo der Browser steht.
 */
(function () {
	'use strict';

	var TZ = 'Europe/Berlin';
	var REFRESH_MS = 60 * 1000;           // Störungen und Eilmeldungen nachladen
	var RELOAD_MS = 60 * 60 * 1000;        // komplette Seite neu laden, damit lange offene Tabs neue Versionen bekommen
	var STALE_MS = 20 * 60 * 1000;

	/** Adressen der Endpunkte einmal beim Start aus den Attributen der Seite lesen. */
	var urls = { root: './', data: 'api/data', abfahrten: 'api/abfahrten', s3: 'api/s3' };

	function resolveUrls() {
		var section = document.getElementById('abfahrten');
		var s3Section = document.getElementById('s3');
		var rel = {
			root: (section && section.getAttribute('data-root')) || './',
			data: (section && section.getAttribute('data-data-endpoint')) || 'api/data',
			abfahrten: (section && section.getAttribute('data-endpoint')) || 'api/abfahrten',
			s3: (s3Section && s3Section.getAttribute('data-endpoint')) || 'api/s3'
		};
		try {
			Object.keys(rel).forEach(function (k) { urls[k] = new URL(rel[k], document.baseURI).href; });
		} catch (e) { urls = rel; }
	}

	/** Wurzelverzeichnis der Seite, fuer Icons. */
	function root() {
		return urls.root;
	}

	/** Anker wie nb_anker(): alles ausser Buchstaben, Ziffern, _ . - wird Bindestrich */
	function anker(id) {
		return String(id || '').replace(/[^A-Za-z0-9_.-]+/g, '-');
	}

	/** Link auf eine Ereignisseite (wie nb_link) */
	// Lesbarer Zusatz wie nb_slug() auf dem Server: Kleinbuchstaben, Umlaute umschrieben,
	// alles andere Bindestrich, hoechstens 60 Zeichen an einer Wortgrenze
	function slug(text) {
		var s = String(text || '').trim().toLowerCase().replace(/[äöüß]/g, function (c) { return { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss' }[c]; });
		s = s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		if (s.length > 60) { s = s.slice(0, 60); s = s.indexOf('-') >= 0 ? s.slice(0, s.lastIndexOf('-')) : s; }
		return s;
	}

	function ereignisLink(art, id, titel) {
		var s = slug(titel);
		return root() + art + '/' + encodeURIComponent(anker(id)) + '/' + (s ? s + '/' : '');
	}

	/** Zaehlt ein Ereignis in Plausible, wenn das Script geladen ist. */
	function track(name, props) {
		try { if (window.plausible) window.plausible(name, props ? { props: props } : undefined); } catch (e) { /* egal */ }
	}

	var state = {
		data: null,
		items: [],       // aufbereitete Eilmeldungen
		hvv: null        // HVV-Meldungen aus api/s3, null bis zur ersten Antwort
	};

	// Was die Tab-Markierung (Favicon-Punkt und Titel) wissen muss
	var alertState = { stoerungen: 0, stoerungenSchwer: 0, ausfaelle: 0, verspaetet: 0 };

	/* ---------- Zeit-Helfer ---------- */

	function fmtDateTime(iso, withYear) {
		if (!iso) return '';
		var dt = new Date(iso);
		if (isNaN(dt)) return '';
		var opts = { timeZone: TZ, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' };
		if (withYear) opts.year = 'numeric';
		return dt.toLocaleString('de-DE', opts).replace(', ', ', ') + ' Uhr';
	}

	/** "YYYY-MM-DD" in deutscher Zeit */
	function tagKey(ms) {
		return new Date(ms).toLocaleDateString('en-CA', { timeZone: TZ });
	}

	/** "Heute", "Gestern" oder "13.10." (wie nb_fmt_tag) */
	function fmtTag(ms, klein) {
		var now = Date.now();
		var heute = tagKey(now);
		var t = tagKey(ms);
		if (t === heute) return klein ? 'heute' : 'Heute';
		var p = heute.split('-');
		var gestern = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] - 1)).toISOString().slice(0, 10);
		if (t === gestern) return klein ? 'gestern' : 'Gestern';
		return new Date(ms).toLocaleDateString('de-DE', { timeZone: TZ, day: '2-digit', month: '2-digit' });
	}

	function istHeute(ms) {
		return tagKey(ms) === tagKey(Date.now());
	}

	function fmtUhr(ms) {
		return new Date(ms).toLocaleTimeString('de-DE', { timeZone: TZ, hour: '2-digit', minute: '2-digit' }) + ' Uhr';
	}

	/** "Heute, 08:50 Uhr" bzw. "13.10., 18:14 Uhr" (wie nb_fmt_wann) */
	function fmtWann(ms, klein) {
		return fmtTag(ms, klein) + ', ' + fmtUhr(ms);
	}

	/** Ende einer Stoerung: am selben Tag nur die Uhrzeit (wie nb_fmt_bis) */
	function fmtBis(start, ende) {
		return 'bis ' + (tagKey(start) === tagKey(ende) ? fmtUhr(ende) : fmtWann(ende, true));
	}

	function relTime(ms) {
		var diff = Date.now() - ms;
		if (diff < 0) diff = 0;
		var min = Math.round(diff / 60000);
		if (min < 1) return 'gerade eben';
		if (min < 60) return 'vor ' + min + ' Min.';
		var h = Math.round(min / 60);
		if (h < 24) return 'vor ' + h + ' Std.';
		var d = Math.round(h / 24);
		return 'vor ' + d + (d === 1 ? ' Tag' : ' Tagen');
	}

	function plural(n, one, many) {
		return n + ' ' + (n === 1 ? one : many);
	}

	/* ---------- Aufbereitung ---------- */

	function prepare(data) {
		var liveById = {};
		(data.eilmeldungen_live || []).forEach(function (e) { if (e && e.id) liveById[e.id] = e; });

		var seen = {};
		var items = [];
		// Der Pendler-Titel der KI (aus der Historie) ersetzt den Nordbahn-Titel in der Anzeige
		function push(id, title, iso, live, kiTitel, kiText, wichtig) {
			var ms = Date.parse(iso || '');
			if (!id || isNaN(ms)) return;
			seen[id] = true;
			kiTitel = (kiTitel || '').trim();
			items.push({
				id: id, title: kiTitel || title || '', original: title || '', text: (kiText || '').trim(), ms: ms,
				stand: live && live.stand ? Date.parse(live.stand) : NaN,
				link: live && live.link ? live.link : '',
				wichtig: !!wichtig // Einstufung kommt vom Server (nb_wichtig_markieren)
			});
		}
		(data.eilmeldungen || []).forEach(function (e) { if (e) push(e.id, e.title, e.seen_at, liveById[e.id], e.ki_titel, e.ki_text, e.wichtig); });
		// Meldungen, die auf der Seite stehen, aber noch nicht in der Historie sind
		(data.eilmeldungen_live || []).forEach(function (e) { if (e && !seen[e.id]) push(e.id, e.title, e.stand, e, '', '', e.wichtig); });
		// Nach der angezeigten Zeit: Stand der Nordbahn, sonst Erfassung; aelteste zuerst
		items.sort(function (a, b) { return (isNaN(a.stand) ? a.ms : a.stand) - (isNaN(b.stand) ? b.ms : b.stand); });

		state.items = items;
	}

	/* ---------- DOM-Helfer ---------- */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'class') node.className = attrs[k];
				else if (k === 'text') node.textContent = attrs[k];
				else node.setAttribute(k, attrs[k]);
			});
		}
		(children || []).forEach(function (c) {
			if (c === null || c === undefined) return;
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	var SVG_NS = 'http://www.w3.org/2000/svg';
	function svgEl(tag, attrs) {
		var node = document.createElementNS(SVG_NS, tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') node.textContent = attrs[k];
			else node.setAttribute(k, attrs[k]);
		});
		return node;
	}

	function clear(node) {
		while (node.firstChild) node.removeChild(node.firstChild);
	}

	/* ---------- Kopf: Aktualitaet ---------- */

	function renderFreshness() {
		var box = document.getElementById('freshness');
		var busy = box.classList.contains('is-busy');
		clear(box);
		box.className = 'freshness' + (busy ? ' is-busy' : '');
		var received = state.data && state.data.received_at ? Date.parse(state.data.received_at) : NaN;
		if (isNaN(received)) {
			box.classList.add('is-empty');
			box.appendChild(el('span', { class: 'dot' }));
			box.appendChild(document.createTextNode('Noch keine Daten empfangen'));
			return;
		}
		// Eine Zeitangabe fuer die ganze Seite: der juengere Stand von Lagebild und Abfahrten
		var abf = departuresState.last;
		var abfMs = abf && abf.ok && abf.fetched_at ? Date.parse(abf.fetched_at) : NaN;
		var latest = isNaN(abfMs) ? received : Math.max(received, abfMs);
		var stale = Date.now() - received > STALE_MS;
		if (stale) box.classList.add('is-stale');
		box.appendChild(el('span', { class: 'dot' }));
		var text = 'Aktualisiert ' + relTime(latest);
		if (stale) text += ', Störungsdaten veraltet (Stand ' + fmtDateTime(state.data.received_at) + ')';
		box.title = 'Lagebild: ' + fmtDateTime(state.data.received_at) + (isNaN(abfMs) ? '' : ', Abfahrten: ' + fmtDateTime(abf.fetched_at)) + '. Antippen zum Aktualisieren';
		box.appendChild(document.createTextNode(text));
		box.appendChild(refreshIcon());
		box.appendChild(el('span', { class: 'visually-hidden', text: ', jetzt aktualisieren' }));
	}

	/** Kreispfeil hinter der Zeitangabe: zeigt, dass ein Tipp nachlaedt, und dreht sich dabei. */
	function refreshIcon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('class', 'freshness__icon');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		var path = document.createElementNS(ns, 'path');
		path.setAttribute('d', 'M17.65 6.35A7.96 7.96 0 0 0 12 4a8 8 0 1 0 7.74 10h-2.08A6 6 0 1 1 12 6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35Z');
		svg.appendChild(path);
		return svg;
	}

	/* ---------- Lagebild ---------- */

	/** Fuellt eine der drei Lagekarten: Stufe ok, warn, alert oder unknown, Titel, optionale Zweitzeile. */
	function setStatus(id, level, title, detail) {
		var box = document.getElementById(id);
		if (!box) return;
		var icons = { ok: '✓', warn: '!', alert: '!', unknown: '?' };
		box.className = 'card status status--' + level;
		clear(box);
		box.appendChild(el('div', { class: 'status__icon', 'aria-hidden': 'true', text: icons[level] || '?' }));
		var h = el('h3', { class: 'status__title', text: title });
		if (detail) h.appendChild(el('small', { text: detail }));
		box.appendChild(h);
		renderKompakt();
	}

	/* Lagekarten kompakt (wie nb_status_kompakt): alle gruen ergibt eine Karte "Alles planmaessig",
	   sonst nur die nicht gruenen Karten und darunter eine Zeile mit den gruenen. Unbekannt ist nicht gruen. */
	var STATUS_NAMEN = { 'status-infra': 'Infrastruktur', 'status-eil': 'Eilmeldungen', 'status-betrieb': 'Nordbahn', 'status-s3': 'S3' };

	/** "A", "A und B", "A, B und C" (wie nb_aufzaehlung). */
	function aufzaehlung(teile) {
		return teile.length > 1 ? teile.slice(0, -1).join(', ') + ' und ' + teile[teile.length - 1] : (teile[0] || '');
	}

	function renderKompakt() {
		var alles = document.getElementById('status-alles');
		var rest = document.getElementById('status-rest');
		if (!alles || !rest) return;
		var karten = Object.keys(STATUS_NAMEN).map(function (id) { return document.getElementById(id); }).filter(Boolean);
		var gruen = karten.filter(function (k) { return k.classList.contains('status--ok'); });
		var allesGruen = gruen.length === karten.length;
		karten.forEach(function (k) { k.hidden = allesGruen || k.classList.contains('status--ok'); });
		alles.hidden = !allesGruen;
		var eil = document.querySelector('#status-eil small');
		var detail = 'Keine Störung oder Eilmeldung, Nordbahn' + (document.getElementById('status-s3') ? ' und S3' : '') + ' planmäßig'
			+ (eil && eil.textContent ? ' · ' + eil.textContent.replace('Letzte ', 'letzte Eilmeldung ') : '');
		var small = alles.querySelector('small');
		if (small) small.textContent = detail;
		else alles.querySelector('.status__title').appendChild(el('small', { text: detail }));
		var text = !allesGruen && gruen.length ? aufzaehlung(gruen.map(function (k) { return STATUS_NAMEN[k.id]; })) + ' ok' : '';
		rest.textContent = text;
		rest.hidden = text === '';
	}

	var EIL_AKUT_MS = 4 * 3600 * 1000;   // so lange gilt eine Eilmeldung als akut
	var EIL_HINWEIS_MS = 12 * 3600 * 1000; // bis dahin nennt die gruene Karte "Letzte vor …" (wie NB_EIL_HINWEIS_S)

	function renderStatus() {
		var section = document.getElementById('status');
		if (!section) return;

		var data = state.data;
		var hasData = data && data.received_at;
		var list = hasData ? data.stoerungen || [] : [];
		var ok = hasData && data.quellen && data.quellen.strecken_info_ok;

		// Karte 1: Infrastruktur laut strecken-info.de. Stuft strecken-info alle
		// Stoerungen als "leicht" ein, bleibt die Karte orange; ab "mittel" wird sie rot.
		var schwer = list.filter(function (st) { return !/^leicht/i.test(st.einschraenkung || ''); }).length;
		if (!hasData) setStatus('status-infra', 'unknown', 'Noch keine Daten');
		else if (list.length === 0 && ok) setStatus('status-infra', 'ok', 'Keine Infrastrukturstörung');
		else if (list.length === 0) setStatus('status-infra', 'unknown', 'Infrastrukturstörungen unbekannt');
		else if (schwer === 0) setStatus('status-infra', 'warn', plural(list.length, 'leichte Infrastrukturstörung', 'leichte Infrastrukturstörungen'));
		else setStatus('status-infra', 'alert', plural(list.length, 'Infrastrukturstörung', 'Infrastrukturstörungen'));

		// Karte 2: Eilmeldungen der Nordbahn, akut heisst juenger als vier Stunden
		var last = state.items.length ? state.items[state.items.length - 1] : null;
		if (!hasData) {
			setStatus('status-eil', 'unknown', 'Noch keine Daten');
		} else if (!last) {
			setStatus('status-eil', 'ok', 'Keine akuten Eilmeldungen');
		} else {
			var lastMs = isNaN(last.stand) ? last.ms : last.stand;
			var age = Date.now() - lastMs;
			if (age > EIL_AKUT_MS) setStatus('status-eil', 'ok', 'Keine akuten Eilmeldungen', age <= EIL_HINWEIS_MS ? 'Letzte ' + relTime(lastMs) : '');
			else setStatus('status-eil', age < 3600 * 1000 ? 'alert' : 'warn', 'Eilmeldung ' + relTime(lastMs), last.title);
		}

		alertState.stoerungen = list.length;
		alertState.stoerungenSchwer = schwer;
		updateBadge();
		notifyNew(list.map(function (st) {
			var t = st.ki_titel || st.titel || 'Störung';
			var b = st.ki_text || st.text || '';
			return { id: 'st:' + st.key, title: 'Infrastrukturstörung', body: t + (b ? ': ' + b : '') };
		}));

		var ul = document.getElementById('incidents');
		if (ul) {
			clear(ul);
			ul.hidden = list.length === 0;
			list.forEach(function (s) { ul.appendChild(renderIncident(s)); });
		}
	}

	/**
	 * Karte 3: laeuft der Fahrplan? Zaehlt Ausfaelle, Umleitungen ueber Altona (bei RB61/RB71,
	 * die RB60 faehrt planmaessig ab Altona) und Verspaetungen ab zehn Minuten und setzt sie ins
	 * Verhaeltnis zu allen angezeigten Zuegen: bis zu einem Viertel betroffen ist eine Warnung,
	 * darueber eine Stoerung.
	 */
	var BETRIEB_ALERT_ANTEIL = 0.25;

	/**
	 * Aktiver Sonderfahrplan, nach dem RB61 und RB71 planmaessig ab/bis Altona fahren
	 * (wie nb_baufahrplan): dann sind Fahrten ueber Altona keine Umleitung.
	 */
	function baufahrplan(data) {
		var heute = new Date().toLocaleDateString('sv-SE', { timeZone: TZ });
		var treffer = null;
		((data && data.sonderfahrplaene) || []).some(function (s) {
			var titel = s.title || '';
			if (s.von <= heute && heute <= s.bis && /RB\s?(61|71)/i.test(titel) && /\b(ab|bis|nach|in)\s+(hamburg-)?altona\b/i.test(titel)) {
				treffer = s;
				return true;
			}
			return false;
		});
		return treffer;
	}

	/** "Bis 02.11.: Alle Züge …" (wie nb_baufahrplan_text). */
	function baufahrplanText(bau) {
		var text = (bau.title || '').replace(/^[^:]*\d{1,2}\.\d{1,2}\.(\d{2,4})?\s*:\s*/, '');
		var m = /^\d{4}-(\d{2})-(\d{2})$/.exec(bau.bis || '');
		return (m ? 'Bis ' + m[2] + '.' + m[1] + '.: ' : '') + text;
	}

	function renderBetrieb(result) {
		var card = document.getElementById('status-betrieb');
		if (!result) {
			// Vom Server gerenderte Karte stehen lassen, bis die ersten Abfahrten da sind
			if (card && card.hasAttribute('data-ssr')) return;
			setStatus('status-betrieb', 'unknown', 'Lade Nordbahn …');
			return;
		}
		if (card) card.removeAttribute('data-ssr');
		if (!result.richtungen) { setStatus('status-betrieb', 'unknown', 'Nordbahn-Lage unbekannt'); return; }
		var bau = baufahrplan(state.data);
		var total = 0, ausfaelle = 0, umleitungen = 0, verspaetet = 0, maxDelay = 0, teil = 0, teilOrt = '';
		['hin', 'rueck'].forEach(function (key) {
			(result.richtungen[key] || []).slice(0, 6).forEach(function (d) {
				if (d.ersatz_fuer) return; // Ersatzzug ist schon ueber den Ausfall erfasst
				total++;
				var rb60 = /RB\s?60/i.test(d.linie || '');
				var altona = !rb60 && (/altona/i.test(d.ab || '') || /altona/i.test(d.ziel || '') || !!d.ersatz);
				if (d.teil && !d.teil.plan) { teil++; teilOrt = d.teil.name; }
				else if (d.ausfall && !d.ersatz) ausfaelle++;
				else if (altona && !bau) umleitungen++;
				else if (d.verspaetung >= 10) { verspaetet++; maxDelay = Math.max(maxDelay, d.verspaetung); }
			});
		});
		var betroffen = ausfaelle + umleitungen + verspaetet + teil;
		var bauText = bau ? baufahrplanText(bau) : '';
		if (!total || !betroffen) {
			if (bau && result.ok) setStatus('status-betrieb', 'warn', 'Nordbahn fährt nach Baufahrplan', bauText);
			else setStatus('status-betrieb', result.ok ? 'ok' : 'unknown', result.ok ? 'Nordbahn fährt planmäßig' : 'Nordbahn-Lage unbekannt');
			return;
		}
		var parts = [];
		if (ausfaelle) parts.push(plural(ausfaelle, 'Zugausfall', 'Zugausfälle'));
		if (teil) parts.push(plural(teil, 'Zug', 'Züge') + ' nur ab/bis ' + teilOrt + (/pinneberg/i.test(teilOrt) ? ', dazwischen S-Bahn' : ''));
		if (umleitungen) parts.push(umleitungen === 1 ? 'Umleitung über Altona' : umleitungen + ' Umleitungen über Altona');
		if (verspaetet) parts.push('Verspätungen bis ' + maxDelay + ' Min');
		var anteil = betroffen / total;
		var level = anteil > BETRIEB_ALERT_ANTEIL ? 'alert' : 'warn';
		var prozent = Math.round(anteil * 100);
		setStatus('status-betrieb', level,
			level === 'alert' ? 'Nordbahn gestört' : (bau ? 'Nordbahn fährt nach Baufahrplan' : 'Nordbahn beeinträchtigt'),
			(bau ? bauText + ' · ' : '') + betroffen + ' von ' + total + ' Zügen (' + prozent + ' %): ' + parts.join(', '));
	}

	function renderIncident(s) {
		var meta = el('dl', { class: 'incident__meta' });
		function row(label, value) {
			if (!value) return;
			meta.appendChild(el('div', {}, [el('dt', { text: label }), el('dd', { text: value })]));
		}
		// Liegt der Pendler-Text der KI vor, reichen Titel, Text und Zeitraum; die technischen
		// Details zeigt die Karte nur, solange der Text noch fehlt.
		var ki = !!(s.ki_titel || s.ki_text);
		var titel = ki && s.ki_titel ? s.ki_titel : (s.titel || 'Störung');
		var text = ki && s.ki_text ? s.ki_text : (s.text || '');
		if (!ki) {
			var abschnitte = (s.abschnitte || []).map(function (a) {
				return [a.von, a.bis].filter(Boolean).join(' – ') + (a.strecke ? ' (Strecke ' + a.strecke + ')' : '');
			}).filter(Boolean).join(', ');
			row('Abschnitt', abschnitte || (s.betriebsstellen || []).join(', '));
			row('Auswirkung', (s.wirkungen || []).join(', '));
			row('Einschränkung', s.einschraenkung ? s.einschraenkung.charAt(0).toUpperCase() + s.einschraenkung.slice(1) : '');
		}
		var zeit = '';
		if (s.beginn) zeit = 'seit ' + fmtDateTime(s.beginn);
		// "abgelaufen": das gemeldete Ende ist vorbei, die Stoerung dauert aber an
		if (s.ende) zeit += (zeit ? ', ' : '') + (s.abgelaufen ? 'gemeldetes Ende ' + fmtDateTime(s.ende) + ' überschritten, dauert an' : 'voraussichtlich bis ' + fmtDateTime(s.ende));
		else if (s.abgelaufen) zeit += (zeit ? ', ' : '') + 'dauert länger als gemeldet';
		row('Zeitraum', zeit);

		return el('li', { class: 'card incident' }, [
			el('h4', { class: 'incident__title', text: titel }),
			text ? el('p', { class: 'incident__text', text: text }) : null,
			meta,
			s.key ? el('p', { class: 'incident__link' }, [el('a', { href: ereignisLink('stoerung', s.key, s.ki_titel || s.titel), text: 'Details und Verlauf' })]) : null
		]);
	}

	/* ---------- Aktuelle Meldungen ---------- */

	var STOERUNG_NACHLAUF_MS = 3600 * 1000; // aufgehobene Stoerung bleibt so lange stehen (wie NB_STOERUNG_NACHLAUF_S)

	/** Aufgehobene Stoerungen aus dem Logbuch, juengste zuerst (wie nb_verlauf_items). */
	function verlaufItems(data) {
		var aktiv = {};
		(data.stoerungen || []).forEach(function (s) { if (s.key) aktiv[s.key] = true; });
		var out = [];
		(data.stoerungen_verlauf || []).forEach(function (e) {
			if (!e || !e.key || aktiv[e.key]) return;
			var start = Date.parse(e.first_seen), ende = Date.parse(e.last_seen);
			if (isNaN(start) || isNaN(ende)) return;
			out.push({ key: e.key, titel: (e.ki_titel || e.titel || 'Störung').trim(), text: (e.ki_text || '').trim(), start: start, ende: Math.max(start, ende) });
		});
		return out.sort(function (a, b) { return b.start - a.start; }); // juengster Beginn zuerst
	}

	function fmtDauer(ms) {
		var min = Math.round(ms / 60000);
		if (min < 60) return Math.max(1, min) + ' Min.';
		var h = Math.floor(min / 60), rest = min % 60;
		return h + ' Std.' + (rest ? ' ' + rest + ' Min.' : '');
	}

	/**
	 * Was gerade gilt, aus allen Quellen (wie nb_aktuelle_meldungen): Eilmeldungen der letzten
	 * vier Stunden, Stoerungen bis eine Stunde nach der Aufhebung, ungeplante HVV-Meldungen, die
	 * jetzt gelten. Geplante HVV-Meldungen bleiben draussen, inhaltsgleiche zaehlen einmal.
	 */
	function aktuelleMeldungen(now) {
		var liste = [];
		verlaufItems(state.data).forEach(function (it) {
			if (it.ende >= now - STOERUNG_NACHLAUF_MS) liste.push({ art: 'stoerung', ts: it.start, titel: it.titel, text: it.text, link: ereignisLink('stoerung', it.key, it.titel), meta: fmtBis(it.start, it.ende) + ' · ' + fmtDauer(it.ende - it.start) });
		});
		state.items.forEach(function (it) {
			var ms = isNaN(it.stand) ? it.ms : it.stand;
			if (ms >= now - EIL_AKUT_MS) liste.push({ art: 'eilmeldung', ts: ms, titel: it.title || it.id, text: it.text, link: ereignisLink('eilmeldung', it.id, it.title || it.id), meta: '', id: it.id });
		});
		var gesehen = {};
		(state.hvv || []).forEach(function (m) {
			if (!m || !m.aktiv || m.geplant) return;
			var schluessel = (m.linien || []).join(',') + '|' + (m.von || '') + '|' + (m.bis || '');
			if (gesehen[schluessel]) return;
			gesehen[schluessel] = true;
			var von = Date.parse(m.von || '');
			liste.push({ art: 'hvv', ts: !isNaN(von) ? von : (Date.parse(m.stand || '') || now), meldung: m });
		});
		liste.sort(function (a, b) { return b.ts - a.ts; });
		return liste;
	}

	/** Stoerung oder Eilmeldung als Listeneintrag (wie nb_render_wichtig_eintrag); Eilmeldungen mit Knopf zum Ausblenden. */
	function meldungEintrag(it) {
		var meta = el('p', { class: 'feed__meta' }, [it.meta]);
		if (it.text) { if (it.meta) meta.appendChild(el('br')); meta.appendChild(document.createTextNode(it.text)); }
		var attrs = istHeute(it.ts) ? { class: 'is-heute' } : {};
		var weg = it.art === 'eilmeldung' && it.id;
		if (weg) attrs['data-eil-id'] = it.id;
		return el('li', attrs, [
			el('div', { class: 'feed__time' }, [fmtWann(it.ts)]),
			el('div', {}, [
				el('p', { class: 'feed__kicker feed__kicker--' + it.art, text: it.art === 'stoerung' ? 'Störung' : 'Eilmeldung' }),
				el('p', { class: 'feed__title' }, [el('a', { href: it.link, text: it.titel })]),
				it.meta || it.text ? meta : null
			]),
			weg ? el('button', { type: 'button', class: 'feed__weg', 'data-weg': it.id, 'aria-label': 'Eilmeldung ausblenden', title: 'Diese Eilmeldung für mich ausblenden', text: '\u00d7' }) : null
		]);
	}

	/* ---------- Eilmeldungen ausblenden ---------- */

	var EIL_WEG_KEY = 'nordbahn.eil.weg';          // { "<id>": <Zeitpunkt des Wegklickens in ms> }
	var EIL_WEG_MAX_MS = 7 * 24 * 3600 * 1000;    // so lange bleibt ein Eintrag gemerkt (akut ist eine Eilmeldung nur vier Stunden)

	/** Weggeklickte Eilmeldungen aus dem Browser; alte Eintraege fallen beim Lesen weg. */
	function eilWegLesen() {
		var weg = {};
		try { weg = JSON.parse(storage('get', EIL_WEG_KEY) || '{}') || {}; } catch (e) { weg = {}; }
		var grenze = Date.now() - EIL_WEG_MAX_MS, out = {};
		Object.keys(weg).forEach(function (id) { if (typeof weg[id] === 'number' && weg[id] >= grenze) out[id] = weg[id]; });
		return out;
	}

	function eilWegSchreiben(weg) {
		storage('set', EIL_WEG_KEY, JSON.stringify(weg));
	}

	/**
	 * Wendet die Auswahl auf die Liste im Bereich "Aktuelle Meldungen" an, egal ob sie vom
	 * Server oder vom Script kommt: weggeklickte Eilmeldungen werden versteckt, die Knoepfe
	 * sichtbar, darunter steht, wie viele fehlen, mit "Wieder anzeigen". Bleibt gar nichts
	 * mehr, verschwindet der Bereich wie bei ruhiger Lage.
	 */
	function eilWegAnwenden() {
		var section = document.getElementById('meldungen');
		var box = document.getElementById('meldungen-inhalt');
		if (!section || !box) return;
		var weg = eilWegLesen(), sichtbar = 0, versteckt = 0;
		Array.prototype.forEach.call(box.querySelectorAll('.feed__weg'), function (b) { b.hidden = false; });
		Array.prototype.forEach.call(box.querySelectorAll('#meldungen-feed > li'), function (li) {
			var id = li.getAttribute('data-eil-id');
			li.hidden = !!(id && weg[id]);
			if (li.hidden) versteckt++; else sichtbar++;
		});
		var hinweis = box.querySelector('.feed__weg-hinweis');
		if (hinweis) hinweis.parentNode.removeChild(hinweis);
		if (versteckt) {
			hinweis = el('p', { class: 'feed__weg-hinweis' + (sichtbar ? '' : ' is-allein') }, [
				plural(versteckt, 'Eilmeldung', 'Eilmeldungen') + ' ausgeblendet · ',
				el('button', { type: 'button', class: 'feed__weg-zurueck', text: 'Wieder anzeigen' })
			]);
			box.appendChild(hinweis);
		}
		section.hidden = !sichtbar && !versteckt;
	}

	/** Klicks im Bereich: × blendet eine Eilmeldung aus, "Wieder anzeigen" holt die versteckten dieser Liste zurueck. */
	function bindEilWeg() {
		var box = document.getElementById('meldungen-inhalt');
		if (!box) return;
		box.addEventListener('click', function (ev) {
			var btn = ev.target && ev.target.closest ? ev.target.closest('.feed__weg, .feed__weg-zurueck') : null;
			if (!btn) return;
			var weg = eilWegLesen();
			if (btn.classList.contains('feed__weg')) {
				weg[btn.getAttribute('data-weg')] = Date.now();
			} else {
				Array.prototype.forEach.call(box.querySelectorAll('#meldungen-feed > li[hidden][data-eil-id]'), function (li) { delete weg[li.getAttribute('data-eil-id')]; });
			}
			eilWegSchreiben(weg);
			eilWegAnwenden();
		});
	}

	/** Bereich "Aktuelle Meldungen" (wie nb_render_aktuelle_meldungen); ohne Meldung ausgeblendet. */
	function renderMeldungen() {
		var section = document.getElementById('meldungen');
		var box = document.getElementById('meldungen-inhalt');
		if (!section || !box || !state.data) return;
		// Mit HVV-Zugang den Stand vom Server stehen lassen, bis api/s3 geantwortet hat
		if (section.hasAttribute('data-hvv') && state.hvv === null) return;
		var now = Date.now();
		var liste = aktuelleMeldungen(now);
		clear(box);
		// Ohne aktuelle Meldung bleibt der Bereich ganz weg
		section.hidden = !liste.length;
		if (!liste.length) return;
		var ol = el('ol', { class: 'feed', id: 'meldungen-feed' });
		liste.forEach(function (it) { ol.appendChild(it.art === 'hvv' ? hvvEintrag(it.meldung, now) : meldungEintrag(it)); });
		box.appendChild(ol);
		eilWegAnwenden();
	}

	/* ---------- Abfahrten ---------- */

	var DEPARTURES_MS = 60 * 1000;

	/** "in 7 Min" auf Basis der Prognose, ab 60 Minuten nichts mehr. */
	function fmtCountdown(iso) {
		var diff = Math.round((Date.parse(iso) - Date.now()) / 60000);
		if (isNaN(diff) || diff > 59) return '';
		if (diff <= 0) return 'jetzt';
		return 'in ' + diff + ' Min';
	}

	/** Vormittags steht die Fahrt in die Stadt oben, nachmittags der Rueckweg; gilt fuer Nordbahn und S3. */
	function orderBoards() {
		var hour = parseInt(new Date().toLocaleTimeString('de-DE', { timeZone: TZ, hour: '2-digit', hour12: false }), 10);
		Array.prototype.forEach.call(document.querySelectorAll('.boards'), function (boards) {
			boards.classList.toggle('boards--rueck-first', hour >= 12);
		});
	}

	function fmtClock(iso) {
		var dt = new Date(iso);
		if (isNaN(dt)) return '--:--';
		return dt.toLocaleTimeString('de-DE', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
	}

	function renderDepartures(result) {
		var section = document.getElementById('abfahrten');
		if (!section) return;
		var sub = document.getElementById('abfahrten-sub');
		var boards = section.querySelectorAll('.board');
		var ok = result && result.ok;

		// Den Stand zeigt die Kopfzeile fuer die ganze Seite; hier steht nur, wenn etwas fehlt
		if (sub) {
			if (!result) sub.textContent = 'Lade …';
			else if (!ok && result.fetched_at) sub.textContent = 'Keine aktuellen Daten, letzter Stand ' + fmtClock(result.fetched_at) + ' Uhr';
			else if (!ok) sub.textContent = 'Keine aktuellen Daten';
			else sub.textContent = '';
		}
		renderFreshness();
		renderBetrieb(result);
		if (!result || (!ok && !result.richtungen)) {
			Array.prototype.forEach.call(boards, function (b) {
				var list = b.querySelector('.board__list');
				clear(list);
				list.appendChild(el('li', {}, [el('p', { class: 'board__empty', text: result ? 'Keine Daten.' : 'Lade …' })]));
			});
			return;
		}

		var ausfaelle = 0, verspaetet = 0;
		Array.prototype.forEach.call(boards, function (b) {
			var key = b.getAttribute('data-richtung');
			var list = b.querySelector('.board__list');
			var deps = (result.richtungen && result.richtungen[key]) || [];
			deps.slice(0, 6).forEach(function (d) {
				if (d.ausfall) ausfaelle++;
				else if (d.verspaetung >= 5) verspaetet++;
			});
			clear(list);
			if (!deps.length) {
				list.appendChild(el('li', {}, [el('p', { class: 'board__empty', text: 'Keine Abfahrt in den nächsten zwei Stunden.' })]));
				return;
			}
			var nextMarked = false;
			deps.slice(0, 6).forEach(function (d) {
				var late = !d.ausfall && d.verspaetung >= 3;
				var cls = 'dep' + (d.ausfall ? ' dep--cancelled' : late ? ' dep--late' : '');
				if (d.teil && !d.teil.plan && !d.ausfall) cls += ' dep--teil';
				if (d.ersatz_fuer) cls += ' dep--ersatz';
				if (!d.ausfall && !nextMarked) { cls += ' dep--next'; nextMarked = true; }

				var time = el('div', { class: 'dep__time' }, [fmtClock(d.zeit)]);
				if (d.ausfall) time.appendChild(el('small', { text: 'fällt aus' }));
				else if (late) time.appendChild(el('small', { text: '+' + d.verspaetung + ' → ' + fmtClock(d.ist) }));
				else {
					var cd = fmtCountdown(d.ist || d.zeit);
					if (cd) time.appendChild(el('small', { class: 'dep__countdown', text: cd }));
				}
				if (late) {
					var cdLate = fmtCountdown(d.ist);
					if (cdLate) time.appendChild(el('small', { class: 'dep__countdown', text: cdLate }));
				}

				var line = el('div', { class: 'dep__line' }, [d.linie]);
				if (d.wagen) line.appendChild(el('small', { text: plural(d.wagen, 'Wagen', 'Wagen') }));
				else if (d.bus) line.appendChild(el('small', { text: 'SEV' }));

				var dest = el('div', { class: 'dep__dest', title: d.ziel }, []);
				// Schienenersatzverkehr: der Bus faellt als Chip auf, nicht nur im Hinweistext
				if (d.bus) { dest.appendChild(el('span', { class: 'dep__ab dep__ab--bus', text: 'Bus' })); dest.appendChild(document.createTextNode(' ')); }
				// Jeder Hamburger Bahnhof hat seine Farbe: Hbf blau, Dammtor blau umrandet (gleicher Zug), Altona orange
				function bahnhofClass(name) {
					return /altona/i.test(name) ? ' dep__ab--altona' : /dammtor/i.test(name) ? ' dep__ab--dammtor' : /hbf/i.test(name) ? ' dep__ab--hbf' : '';
				}
				if (d.teil && d.teil.art === 'beginnt' && d.teil.plan && !d.ausfall) {
					// Rueckrichtung, Zug beginnt planmaessig unterwegs (nachts ab Pinneberg): neutral
					dest.appendChild(el('span', { class: 'dep__ab dep__ab--plan', text: 'ab ' + d.teil.name }));
					dest.appendChild(document.createTextNode(' '));
					dest.appendChild(el('span', { class: 'dep__ziel', text: d.ziel }));
					dest.appendChild(el('small', { text: 'Beginnt planmäßig in ' + d.teil.name + (/^AP$/.test(d.teil.id) ? ', aus Hamburg mit der S-Bahn' : '') }));
				} else if (d.teil && d.teil.art === 'beginnt' && !d.ausfall) {
					// Rueckrichtung, Zug startet erst unterwegs (z. B. Pinneberg): die Zeile ist die Abfahrt dort
					dest.appendChild(el('span', { class: 'dep__ab dep__ab--teil', text: 'erst ab ' + d.teil.name }));
					dest.appendChild(document.createTextNode(' '));
					dest.appendChild(el('span', { class: 'dep__ziel', text: d.ziel }));
					dest.appendChild(el('small', { class: 'dep__ersatz', text: 'Startet heute erst in ' + d.teil.name
						+ (d.zeit_plan ? ', planmäßig ' + fmtClock(d.zeit_plan) + ' ab ' + d.ab : '') }));
				} else if (d.ab) {
					// Rueckrichtung: der Startbahnhof ist die wichtige Information, Altona faellt auf
					dest.appendChild(el('span', { class: 'dep__ab' + bahnhofClass(d.ab), text: 'ab ' + d.ab }));
					dest.appendChild(document.createTextNode(' '));
					dest.appendChild(el('span', { class: 'dep__ziel', text: d.ziel }));
				} else if (d.teil && d.teil.art === 'endet' && !d.ausfall) {
					// Hinrichtung, Zug endet vorzeitig (Stellwerk kaputt) oder planmaessig (nachts
					// nur bis Pinneberg): nur bis dorthin, planmaessig ohne Warnfarbe
					dest.appendChild(el('span', { class: 'dep__ab ' + (d.teil.plan ? 'dep__ab--plan' : 'dep__ab--teil'), text: 'nur bis ' + d.teil.name }));
					dest.appendChild(el('small', { class: d.teil.plan ? '' : 'dep__ersatz', text: (d.teil.plan ? 'Endet planmäßig in ' : 'Endet heute in ') + d.teil.name + (d.teil.zeit ? ' (an ' + d.teil.zeit + ')' : '')
						+ (/^AP$/.test(d.teil.id) ? ', weiter mit der S-Bahn' : '') }));
				} else {
					// Hinrichtung: das Hamburger Ziel als Chip, gleiche Farben wie die Startbahnhoefe
					var zielKurz = /altona/i.test(d.ziel) ? 'Altona' : /dammtor/i.test(d.ziel) ? 'Dammtor' : /hbf|hauptbahnhof/i.test(d.ziel) ? 'Hbf' : '';
					if (zielKurz) dest.appendChild(el('span', { class: 'dep__ab' + bahnhofClass(zielKurz), text: 'nach ' + zielKurz }));
					else dest.appendChild(el('span', { class: 'dep__ziel', text: d.ziel }));
				}
				if (d.teil && d.teil.art === 'beginnt' && d.ausfall) {
					// Startzeit unterwegs unbekannt: die Zeile bleibt ein Ausfall mit Hinweis
					dest.appendChild(el('small', { class: 'dep__ersatz', text: 'Fährt erst ab ' + d.teil.name }));
				}
				if (d.ersatz_fuer) {
					dest.appendChild(el('small', { class: 'dep__ersatz', text: 'Ersatz für den ' + fmtClock(d.ersatz_fuer.zeit) + ' ab ' + d.ersatz_fuer.ab }));
				}
				if (d.dammtor && !d.ausfall && !(d.teil && d.teil.art === 'beginnt')) {
					// Zuege ab Hbf halten drei Minuten spaeter am Dammtor
					var dt = d.dammtor;
					if (dt.ausfall) dest.appendChild(el('small', { class: 'dep__dammtor dep__dammtor--ausfall', text: 'Halt am Dammtor entfällt' }));
					else {
						var dtLate = dt.verspaetung >= 3;
						dest.appendChild(el('small', { class: 'dep__dammtor', text: 'Dammtor ' + fmtClock(dtLate && dt.ist ? dt.ist : dt.zeit) + (dtLate ? ' (+' + dt.verspaetung + ')' : '')
							+ (dt.gleis ? ', Gl. ' + dt.gleis + (dt.gleis_plan ? ' (statt ' + dt.gleis_plan + ')' : '') : '') }));
					}
				}
				if (d.hinweis) dest.appendChild(el('small', { text: d.hinweis }));
				// Zuverlaessigkeit der letzten Tage, sobald der Zug oft genug beobachtet wurde
				if (d.ersatz) {
					var e = d.ersatz;
					var eLate = e.verspaetung >= 3;
					dest.appendChild(el('small', { class: 'dep__ersatz', text: 'Fährt stattdessen ab ' + e.ab + ' um ' + fmtClock(e.zeit)
						+ (eLate ? ' (+' + e.verspaetung + ')' : '') + (e.gleis ? ', Gl. ' + e.gleis + (e.gleis_plan ? ' (statt ' + e.gleis_plan + ')' : '') : '') }));
				}

				// Gleiswechsel: das heutige Gleis faellt auf, das Plangleis steht klein darunter
				var platform = el('div', { class: 'dep__platform' + (d.gleis_plan ? ' dep__platform--wechsel' : ''), text: d.gleis ? 'Gl. ' + d.gleis : '' });
				if (d.gleis_plan) platform.appendChild(el('small', { text: 'statt ' + d.gleis_plan }));
				var row = el('li', { class: cls }, [
					el('span', { class: 'dep__dot', 'aria-hidden': 'true' }),
					time,
					line,
					dest,
					platform
				]);
				var sr = (d.ausfall ? 'Fällt aus: ' : late ? 'Verspätet um ' + d.verspaetung + ' Minuten: ' : '') + (d.bus ? 'Bus statt Zug: ' : '');
				if (d.ersatz_fuer) sr = 'Ersatz für ' + fmtClock(d.ersatz_fuer.zeit) + ' ab ' + d.ersatz_fuer.ab + ': ';
				if (sr) row.setAttribute('aria-label', sr + fmtClock(d.zeit) + ' ' + d.linie + ' nach ' + d.ziel + (d.ab ? ' ab ' + d.ab : '') + (d.gleis_plan ? ', heute Gleis ' + d.gleis + ' statt ' + d.gleis_plan : ''));
				list.appendChild(row);
			});
		});
		alertState.ausfaelle = ausfaelle;
		alertState.verspaetet = verspaetet;
		updateBadge();
		notifyNew(collectEvents(result));
	}

	var departuresState = { last: null };

	/* ---------- Heimatbahnhof ---------- */

	var HEIMAT_KEY = 'nordbahn.heimat';
	var heimatState = { id: 'APD', name: 'Prisdorf', ast: 'stamm' };

	/** Liest die Auswahl aus dem Select; der Wert kommt aus localStorage oder der Vorgabe der Seite. */
	function initHeimat() {
		var select = document.getElementById('heimat');
		var section = document.getElementById('abfahrten');
		if (!select || !section) return;
		var wanted = section.getAttribute('data-heimat-default') || 'APD';
		try { wanted = localStorage.getItem(HEIMAT_KEY) || wanted; } catch (e) { /* privater Modus */ }
		if (select.querySelector('option[value="' + wanted + '"]')) select.value = wanted;
		applyHeimat(select);
		select.addEventListener('change', function () {
			applyHeimat(select);
			try { localStorage.setItem(HEIMAT_KEY, heimatState.id); } catch (e) { /* egal */ }
			track('Bahnhof', { bahnhof: heimatState.name });
			departuresState.last = null;
			renderDepartures(null);
			refreshDepartures();
		});
	}

	function applyHeimat(select) {
		var opt = select.options[select.selectedIndex];
		heimatState = { id: select.value, name: opt ? opt.textContent : select.value, ast: opt ? opt.getAttribute('data-ast') || 'stamm' : 'stamm' };
		var hin = document.getElementById('board-title-hin');
		var rueck = document.getElementById('board-title-rueck');
		if (hin) hin.textContent = heimatState.name + ' → Hamburg';
		if (rueck) rueck.textContent = rueckTitel();
		pushSync();
	}

	/** Hinter Elmshorn faehrt nur noch ein Ast, davor beide. */
	function rueckTitel() {
		return 'Hamburg → ' + (heimatState.ast === 'itzehoe' ? 'Itzehoe' : heimatState.ast === 'wrist' ? 'Wrist' : 'Wrist / Itzehoe');
	}

	function refreshDepartures() {
		var section = document.getElementById('abfahrten');
		if (!section || document.hidden || !window.fetch) return Promise.resolve();
		var heimat = heimatState.id;
		var url = urls.abfahrten + '?bhf=' + encodeURIComponent(heimat);
		return fetch(url, { cache: 'no-cache', headers: { 'Accept': 'application/json' } })
			.then(function (res) { return res.json(); })
			.then(function (result) {
				if (heimat !== heimatState.id) return; // inzwischen umgestellt, Antwort ist veraltet
				departuresState.last = result;
				renderDepartures(result);
			})
			.catch(function () {
				if (departuresState.last) {
					departuresState.last.ok = false;
					renderDepartures(departuresState.last);
				} else {
					renderDepartures({ ok: false });
				}
			});
	}

	/* ---------- S-Bahn S3 und HVV-Meldungen (api/s3, HVV Geofox) ---------- */

	var s3State = { last: null };
	var STADT_KEY = 'nordbahn.stadt';
	var stadtState = { id: 'hbf', name: 'Hamburg Hbf', kurz: 'Hbf' };

	/** Stadtbahnhof der S3 (Hbf, Jungfernstieg, Altona), wie "Mein Bahnhof" im Browser gemerkt. */
	function initStadt() {
		var section = document.getElementById('s3');
		var select = document.getElementById('stadt');
		if (!section || !select) return;
		var id = section.getAttribute('data-stadt-default') || 'hbf';
		try { id = localStorage.getItem(STADT_KEY) || id; } catch (e) {}
		if (select.querySelector('option[value="' + id + '"]')) select.value = id;
		applyStadt(select);
		select.addEventListener('change', function () {
			applyStadt(select);
			try { localStorage.setItem(STADT_KEY, stadtState.id); } catch (e) {}
			track('Stadtbahnhof', { stadt: stadtState.name });
			s3State.last = null;
			renderS3(null);
			refreshS3();
		});
	}

	function applyStadt(select) {
		var opt = select.options[select.selectedIndex];
		stadtState = { id: select.value, name: opt ? opt.textContent : select.value, kurz: opt ? (opt.getAttribute('data-kurz') || opt.textContent) : select.value };
		var hin = document.getElementById('s3-title-hin'), rueck = document.getElementById('s3-title-rueck');
		if (hin) hin.textContent = 'Pinneberg → ' + stadtState.kurz;
		if (rueck) rueck.textContent = stadtState.kurz + ' → Pinneberg';
	}

	/** Eine S3-Tafel fuellen: Zeilen mit passendem Abfahrtsort. */
	function renderS3Board(list, deps) {
		clear(list);
		if (!deps.length) {
			list.appendChild(el('li', {}, [el('p', { class: 'board__empty', text: 'Keine S3-Abfahrt in den nächsten 60 Minuten gemeldet.' })]));
			return;
		}
		var nextMarked = false;
		deps.forEach(function (d) {
			var late = !d.ausfall && d.delay_min >= 3;
			var cls = 'dep' + (d.ausfall ? ' dep--cancelled' : late ? ' dep--late' : '');
			if (!d.ausfall && !nextMarked) { cls += ' dep--next'; nextMarked = true; }
			var time = el('div', { class: 'dep__time' }, [fmtClock(d.zeit)]);
			if (d.ausfall) time.appendChild(el('small', { text: 'fällt aus' }));
			else if (late) time.appendChild(el('small', { text: '+' + d.delay_min + ' → ' + fmtClock(d.ist) }));
			var cd = d.ausfall ? '' : fmtCountdown(d.ist || d.zeit);
			if (cd) time.appendChild(el('small', { class: 'dep__countdown', text: cd }));
			var dest = el('div', { class: 'dep__dest', title: d.ziel }, [el('span', { class: 'dep__ziel', text: d.ziel })]);
			var platform = el('div', { class: 'dep__platform', text: d.gleis ? 'Gl. ' + d.gleis : '' });
			list.appendChild(el('li', { class: cls }, [el('span', { class: 'dep__dot' }), time, el('div', { class: 'dep__line' }, [d.linie || 'S3']), dest, platform]));
		});
	}

	/** Eine aktuelle HVV-Meldung als Listeneintrag (wie nb_render_hvv_eintrag). */
	function hvvEintrag(m, now) {
		var von = Date.parse(m.von || ''), bis = Date.parse(m.bis || '');
		var meta = [];
		if (m.linien && m.linien.length) meta.push(m.linien.join(', '));
		meta.push(isNaN(bis) ? 'bis auf Weiteres' : fmtBis(now, bis));
		var text = m.text || '';
		if (text.length > 300) text = text.slice(0, 300).replace(/\s+$/, '') + ' …';
		var kicker = el('p', { class: 'feed__kicker feed__kicker--hvv', text: 'HVV-Meldung' });
		kicker.appendChild(document.createTextNode(' '));
		kicker.appendChild(el('span', { class: 'dep__ab dep__ab--aktiv', text: 'aktuell' }));
		var titel = /^https?:\/\//i.test(m.link || '') ? el('a', { href: m.link, rel: 'noopener nofollow', text: m.titel || '' }) : (m.titel || '');
		var body = [kicker, el('p', { class: 'feed__title' }, [titel])];
		if (meta.length || text) {
			var p = el('p', { class: 'feed__meta', text: meta.join(' · ') });
			if (meta.length && text) p.appendChild(el('br'));
			if (text) p.appendChild(document.createTextNode(text));
			body.push(p);
		}
		var zeit = !isNaN(von) ? von : (Date.parse(m.stand || '') || now);
		return el('li', { class: 'is-heute' }, [el('div', { class: 'feed__time' }, [fmtWann(zeit)]), el('div', {}, body)]);
	}

	/** Karte, Tafeln und HVV-Meldungen aus der Antwort von api/s3; die Statusregel rechnet der Server (gf_status). */
	function renderS3(result) {
		var section = document.getElementById('s3');
		var card = document.getElementById('status-s3');
		if (!section) return;
		if (!result) {
			if (card && card.hasAttribute('data-ssr')) return;
			setStatus('status-s3', 'unknown', 'Lade S3 …');
			return;
		}
		if (card) card.removeAttribute('data-ssr');
		var sub = document.getElementById('s3-sub');
		var ok = result.ok && result.s3;
		if (sub) {
			if (!ok && result.fetched_at) sub.textContent = 'Keine aktuellen Daten, letzter Stand ' + fmtClock(result.fetched_at) + ' Uhr';
			else if (!ok) sub.textContent = 'Keine aktuellen Daten';
			else sub.textContent = '';
		}
		if (!ok) {
			setStatus('status-s3', 'unknown', 'S3-Lage unbekannt');
			// Tafeln und Meldungen bleiben auf dem letzten Stand; ohne jeden Stand eben ohne HVV
			if (state.hvv === null) { state.hvv = []; renderMeldungen(); }
			return;
		}
		var s3 = result.s3;
		setStatus('status-s3', s3.level || 'unknown', s3.title || 'S3', s3.detail || '');
		var hin = document.getElementById('s3-board-hin'), rueck = document.getElementById('s3-board-rueck');
		var deps = s3.abfahrten || [];
		if (hin) renderS3Board(hin, deps.filter(function (d) { return d.ab === 'Pinneberg'; }));
		if (rueck) renderS3Board(rueck, deps.filter(function (d) { return d.ab !== 'Pinneberg'; }));
		state.hvv = s3.meldungen || [];
		renderMeldungen();
	}

	function refreshS3() {
		if (!document.getElementById('s3') || document.hidden || !window.fetch) return Promise.resolve();
		var stadt = stadtState.id;
		return fetch(urls.s3 + '?stadt=' + encodeURIComponent(stadt), { cache: 'no-cache', headers: { 'Accept': 'application/json' } })
			.then(function (res) { return res.json(); })
			.then(function (result) {
				if (stadt !== stadtState.id) return; // inzwischen umgestellt, Antwort ist veraltet
				s3State.last = result;
				renderS3(result);
			})
			.catch(function () {
				if (s3State.last) { s3State.last.ok = false; renderS3(s3State.last); }
				else renderS3({ ok: false });
			});
	}

	/* ---------- Tab-Markierung ---------- */

	var BASE_TITLE = document.title;
	var faviconCache = {};
	var faviconBild = null;

	/**
	 * Zeichnet das Favicon (Zug von vorn, icon-64.png) und je nach Lage einen Punkt
	 * oben rechts. Solange das Bild noch laedt, null; updateBadge laeuft danach erneut.
	 */
	function faviconUrl(level) {
		if (faviconCache[level]) return faviconCache[level];
		if (!faviconBild) {
			faviconBild = new Image();
			faviconBild.onload = updateBadge;
			faviconBild.src = root() + 'icon-64.png';
		}
		if (!faviconBild.complete || !faviconBild.naturalWidth) return null;
		var canvas = document.createElement('canvas');
		canvas.width = 64; canvas.height = 64;
		var ctx = canvas.getContext('2d');
		if (!ctx) return null;
		ctx.drawImage(faviconBild, 0, 0, 64, 64);
		if (level) {
			ctx.beginPath(); ctx.arc(49, 15, 16, 0, Math.PI * 2);
			ctx.fillStyle = '#fff'; ctx.fill();
			ctx.beginPath(); ctx.arc(49, 15, 13, 0, Math.PI * 2);
			ctx.fillStyle = level === 'alert' ? '#d03b3b' : '#f59e0b'; ctx.fill();
		}
		try {
			faviconCache[level] = canvas.toDataURL('image/png');
		} catch (e) {
			return null;
		}
		return faviconCache[level];
	}

	/** Rot bei Störung ab "mittel" oder Zugausfall, orange bei leichter Störung oder Verspätung, sonst nichts. */
	function updateBadge() {
		var level = alertState.stoerungenSchwer || alertState.ausfaelle ? 'alert'
			: alertState.stoerungen || alertState.verspaetet ? 'warn' : '';
		var parts = [];
		if (alertState.stoerungen) parts.push(plural(alertState.stoerungen, 'Störung', 'Störungen'));
		if (alertState.ausfaelle) parts.push(plural(alertState.ausfaelle, 'Ausfall', 'Ausfälle'));
		if (!parts.length && alertState.verspaetet) parts.push(plural(alertState.verspaetet, 'Verspätung', 'Verspätungen'));
		document.title = parts.length ? '(' + parts.join(', ') + ') ' + BASE_TITLE : BASE_TITLE;
		try {
			var count = alertState.stoerungen + alertState.ausfaelle;
			if (navigator.setAppBadge) { if (count) navigator.setAppBadge(count); else navigator.clearAppBadge(); }
		} catch (e) { /* kein App-Badge verfuegbar */ }
		var link = document.getElementById('favicon');
		if (!link) return;
		var url = faviconUrl(level);
		if (url && link.href !== url) {
			link.type = 'image/png';
			link.href = url;
		}
	}

	/* ---------- Systembenachrichtigungen ---------- */

	var NOTIFY_KEY = 'nordbahn.notify';       // "1" = eingeschaltet
	var NOTIFY_ZEIT_KEY = 'nordbahn.notify.zeit'; // "pendel" (Vorgabe) oder "immer"
	var SEEN_KEY = 'nordbahn.notified';       // bereits gemeldete Ereignisse
	var PUSH_KEY = 'nordbahn.push';           // "1" = Push-Abo beim Server angelegt
	var seen = null;

	function storage(action, key, value) {
		try {
			if (action === 'get') return window.localStorage.getItem(key);
			window.localStorage.setItem(key, value);
		} catch (e) { return null; }
		return null;
	}

	function notifyEnabled() {
		return 'Notification' in window && Notification.permission === 'granted' && storage('get', NOTIFY_KEY) === '1';
	}

	/* ---------- Web Push ---------- */

	function pushKey() {
		return document.body.getAttribute('data-push-key') || '';
	}

	/** Push geht nur mit Service Worker, PushManager, HTTPS und eingerichtetem Schluessel. */
	function pushSupported() {
		return !!pushKey() && 'serviceWorker' in navigator && 'PushManager' in window && window.isSecureContext;
	}

	function pushActive() {
		return pushSupported() && storage('get', PUSH_KEY) === '1' && notifyEnabled();
	}

	function isIosOhneApp() {
		var ua = navigator.userAgent || '';
		var ios = /iP(hone|ad|od)/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		var standalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
		return ios && !standalone;
	}

	function keyBytes(b64url) {
		var pad = '='.repeat((4 - b64url.length % 4) % 4);
		var raw = window.atob((b64url + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
		return out;
	}

	function pushPost(body) {
		return fetch(root() + 'api/subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
			.then(function (res) { if (!res.ok) throw new Error('subscribe ' + res.status); return res.json(); });
	}

	/** Service Worker registrieren, beim Push-Dienst abonnieren, Abo dem Server melden. */
	function pushSubscribe() {
		if (!pushSupported()) return Promise.reject(new Error('kein Push'));
		return navigator.serviceWorker.register(root() + 'sw.js').then(function (reg) {
			return navigator.serviceWorker.ready.then(function () { return reg.pushManager.getSubscription(); }).then(function (sub) {
				return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(pushKey()) });
			});
		}).then(function (sub) {
			return pushPost({ action: 'subscribe', subscription: sub.toJSON(), heimat: heimatState.id, zeit: notifyZeit() });
		}).then(function () {
			storage('set', PUSH_KEY, '1');
		});
	}

	function pushUnsubscribe() {
		storage('set', PUSH_KEY, '0');
		if (!('serviceWorker' in navigator)) return Promise.resolve();
		return navigator.serviceWorker.getRegistration(root() + 'sw.js').then(function (reg) {
			return reg ? reg.pushManager.getSubscription() : null;
		}).then(function (sub) {
			if (!sub) return;
			return pushPost({ action: 'unsubscribe', endpoint: sub.endpoint }).catch(function () { /* egal */ }).then(function () { return sub.unsubscribe(); });
		}).catch(function () { /* egal */ });
	}

	/** Heimat oder Zeitregel geaendert: Abo beim Server nachziehen. */
	function pushSync() {
		if (!pushActive()) return;
		pushSubscribe().catch(function () { /* beim naechsten Mal */ });
	}

	function notifyZeit() {
		return storage('get', NOTIFY_ZEIT_KEY) === 'immer' ? 'immer' : 'pendel';
	}

	/** Pendelzeiten: Montag bis Freitag, 6 bis 9 und 16 bis 19 Uhr deutscher Zeit. */
	function inPendelzeit(now) {
		var hour = NaN, day = '';
		try {
			new Intl.DateTimeFormat('en-US', { timeZone: TZ, hour: 'numeric', hour12: false, weekday: 'short' })
				.formatToParts(now || new Date())
				.forEach(function (p) {
					if (p.type === 'hour') hour = parseInt(p.value, 10) % 24;
					if (p.type === 'weekday') day = p.value;
				});
		} catch (e) { return true; }
		if (day === 'Sat' || day === 'Sun') return false;
		return (hour >= 6 && hour < 9) || (hour >= 16 && hour < 19);
	}

	function notifyAllowedNow() {
		return notifyZeit() === 'immer' || inPendelzeit();
	}

	function loadSeen() {
		if (seen) return seen;
		seen = {};
		try {
			var raw = JSON.parse(storage('get', SEEN_KEY) || '{}');
			var cutoff = Date.now() - 12 * 3600 * 1000;
			Object.keys(raw).forEach(function (k) { if (raw[k] > cutoff) seen[k] = raw[k]; });
		} catch (e) { seen = {}; }
		return seen;
	}

	/** Ausfaelle der angezeigten Zuege als Ereignisse (eine ID je Zug und Tag). */
	function collectEvents(result) {
		var events = [];
		if (!result || !result.richtungen) return events;
		['hin', 'rueck'].forEach(function (key) {
			(result.richtungen[key] || []).slice(0, 6).forEach(function (d) {
				if (!d.ausfall) return;
				var richtung = key === 'hin' ? heimatState.name + ' → Hamburg' : rueckTitel();
				events.push({
					id: 'zug:' + heimatState.id + ':' + (d.zug || d.linie + d.zeit),
					title: 'Zugausfall ' + d.linie + ' um ' + fmtClock(d.zeit) + ' Uhr',
					body: richtung + (d.ab ? ' ab ' + d.ab : '') + (d.hinweis ? '. ' + d.hinweis : '')
						+ (d.ersatz ? '. Fährt stattdessen ab ' + d.ersatz.ab + ' um ' + fmtClock(d.ersatz.zeit) + ' Uhr' : '')
				});
			});
		});
		return events;
	}

	var notifyPrimed = false;

	/** Meldet nur, was seit dem letzten Mal neu ist; beim ersten Laden wird nichts gemeldet. */
	function notifyNew(events) {
		var list = loadSeen();
		var fresh = events.filter(function (ev) { return !list[ev.id]; });
		fresh.forEach(function (ev) { list[ev.id] = Date.now(); });
		if (fresh.length) storage('set', SEEN_KEY, JSON.stringify(list));
		if (!notifyPrimed) { notifyPrimed = true; return; }
		if (!notifyEnabled() || !notifyAllowedNow() || pushActive()) return;
		fresh.slice(0, 3).forEach(function (ev) {
			try {
				var n = new Notification(ev.title, { body: ev.body, icon: root() + 'icon-192.png', tag: ev.id, lang: 'de' });
				n.onclick = function () { window.focus(); n.close(); };
			} catch (e) { /* Benachrichtigung nicht moeglich */ }
		});
	}

	function renderNotifyButton() {
		var btn = document.getElementById('notify-toggle');
		if (!btn) return;
		if (!('Notification' in window)) { btn.hidden = true; return; }
		var label = document.getElementById('notify-label');
		var on = notifyEnabled();
		var text = on ? 'Benachrichtigungen an' + (notifyZeit() === 'pendel' ? ', nur zu Pendelzeiten' : '') : 'Benachrichtigungen aus';
		var hint = document.getElementById('notify-hint');
		var box = document.getElementById('notify-on');
		if (Notification.permission === 'denied') {
			text = 'Benachrichtigungen im Browser blockiert';
			if (box) box.disabled = true;
			if (hint) { hint.textContent = 'Im Browser blockiert. Erlaube Benachrichtigungen für diese Seite in den Einstellungen.'; hint.hidden = false; }
		} else if (hint) {
			hint.hidden = true;
		}
		var pushHint = document.getElementById('notify-push-hint');
		if (pushHint) {
			var ph = '';
			if (on && pushActive()) ph = 'Kommt auch bei geschlossener Seite aufs Gerät.';
			else if (on && pushSupported()) ph = 'Meldungen nur, solange die Seite offen ist.';
			else if (isIosOhneApp()) ph = 'Auf dem iPhone: Seite über „Teilen“ zum Home-Bildschirm hinzufügen und von dort öffnen, dann kommen Meldungen auch bei geschlossener App.';
			else if (!pushSupported()) ph = 'Dieser Browser meldet nur, solange die Seite offen ist.';
			pushHint.textContent = ph;
			pushHint.hidden = ph === '';
		}
		if (box) box.checked = on;
		Array.prototype.forEach.call(document.querySelectorAll('input[name="notify-zeit"]'), function (r) { r.checked = r.value === notifyZeit(); });
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		btn.title = text;
		if (label) label.textContent = text;
	}

	/** Die Glocke oeffnet ein kleines Menue: an/aus und ob nur zu Pendelzeiten gemeldet wird. */
	function initNotifyButton() {
		var btn = document.getElementById('notify-toggle');
		var menu = document.getElementById('notify-menu');
		var wrap = document.getElementById('notify-wrap');
		if (!btn || !menu) return;
		renderNotifyButton();

		function open(show) {
			menu.hidden = !show;
			btn.setAttribute('aria-expanded', show ? 'true' : 'false');
		}
		btn.addEventListener('click', function () { open(menu.hidden); });
		document.addEventListener('click', function (ev) { if (!menu.hidden && wrap && !wrap.contains(ev.target)) open(false); });
		document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !menu.hidden) { open(false); btn.focus(); } });

		var box = document.getElementById('notify-on');
		if (box) box.addEventListener('change', function () {
			if (!box.checked) {
				storage('set', NOTIFY_KEY, '0');
				pushUnsubscribe().then(renderNotifyButton);
				renderNotifyButton();
				return;
			}
			Notification.requestPermission().then(function (perm) {
				if (perm !== 'granted') { renderNotifyButton(); return; }
				storage('set', NOTIFY_KEY, '1');
				track('Benachrichtigung an');
				var done = function () {
					try {
						var body = pushActive()
							? 'Du bekommst jetzt eine Meldung bei neuen Zugausfällen und Störungen, auch wenn die Seite zu ist.'
							: 'Du bekommst jetzt eine Meldung bei neuen Zugausfällen und Störungen, solange die Seite offen ist.';
						new Notification('Bahn-Pinneberg.de', { body: body, icon: root() + 'icon-192.png', lang: 'de' });
					} catch (e) { /* egal */ }
					renderNotifyButton();
				};
				if (pushSupported()) pushSubscribe().then(done, function () { storage('set', PUSH_KEY, '0'); done(); });
				else done();
			});
		});
		Array.prototype.forEach.call(document.querySelectorAll('input[name="notify-zeit"]'), function (r) {
			r.addEventListener('change', function () {
				if (r.checked) { storage('set', NOTIFY_ZEIT_KEY, r.value); renderNotifyButton(); pushSync(); }
			});
		});
	}

	/* ---------- Steuerung ---------- */

	function renderAll() {
		renderFreshness();
		renderStatus();
		renderMeldungen();
	}

	function setData(data) {
		state.data = data || {};
		prepare(state.data);
		renderAll();
		// Ein neuer Sonderfahrplan aendert die Fahrplankarte auch ohne neue Abfahrten
		if (departuresState.last) renderBetrieb(departuresState.last);
	}

	function refresh() {
		if (document.hidden || !window.fetch) return Promise.resolve();
		var app = document.getElementById('app');
		var url = urls.data;
		return fetch(url, { cache: 'no-cache', headers: { 'Accept': 'application/json' } })
			.then(function (res) { if (!res.ok) throw new Error(res.status); return res.json(); })
			.then(function (data) {
				if (!data || !data.received_at) return;
				if (state.data && state.data.received_at === data.received_at) {
					renderFreshness();
					return;
				}
				app.classList.add('is-loading');
				setData(data);
				app.classList.remove('is-loading');
			})
			.catch(function () { renderFreshness(); });
	}

	function boot() {
		resolveUrls();
		var raw = document.getElementById('nordbahn-data');
		var data = {};
		try { data = JSON.parse(raw.textContent); } catch (e) { data = {}; }
		bindEilWeg();
		setData(data);
		// Mit HVV-Zugang steht bis zur Antwort von api/s3 noch die Liste vom Server
		eilWegAnwenden();
		setInterval(refresh, REFRESH_MS);
		setInterval(renderFreshness, 60000);
		setInterval(function () { if (!document.hidden) window.location.reload(); }, RELOAD_MS);
		orderBoards();
		setInterval(orderBoards, 30000);
		if (document.getElementById('abfahrten')) {
			initHeimat();
			renderDepartures(null);
			refreshDepartures();
			setInterval(refreshDepartures, DEPARTURES_MS);
			setInterval(function () { if (departuresState.last) renderDepartures(departuresState.last); }, 30000);
		}
		if (document.getElementById('s3')) {
			initStadt();
			renderS3(null);
			refreshS3();
			setInterval(refreshS3, DEPARTURES_MS);
			setInterval(function () { if (s3State.last) renderS3(s3State.last); }, 30000);
		}
		initNotifyButton();
		initRefreshButton();
		Array.prototype.forEach.call(document.querySelectorAll('a[href*="t.me"]'), function (a) {
			a.addEventListener('click', function () { track('Telegram', { kanal: a.getAttribute('href').replace(/.*\//, '') }); });
		});
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); refreshDepartures(); refreshS3(); } });
	}

	/**
	 * Manuell nachladen per Tipp auf die Zeitangabe. Die Seite laedt jede Minute und
	 * beim Zurueckholen in den Vordergrund ohnehin selbst nach; das hier ist fuer die
	 * Webapp ohne Browserleiste und fuer alle, die es genau wissen wollen.
	 */
	function initRefreshButton() {
		var btn = document.getElementById('freshness');
		if (!btn || !window.fetch || !window.Promise) return;
		btn.addEventListener('click', function () {
			if (btn.classList.contains('is-busy')) return;
			btn.classList.add('is-busy');
			var started = Date.now();
			Promise.all([refresh(), refreshDepartures(), refreshS3()]).then(done, done);
			function done() {
				// mindestens eine halbe Umdrehung, sonst flackert es nur
				setTimeout(function () { btn.classList.remove('is-busy'); renderFreshness(); }, Math.max(0, 600 - (Date.now() - started)));
			}
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
