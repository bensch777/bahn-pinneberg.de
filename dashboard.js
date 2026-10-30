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
	var urls = { root: './', data: 'api/data', abfahrten: 'api/abfahrten', s3: 'api/s3', fahrplan: 'api/fahrplan' };

	function resolveUrls() {
		var section = document.getElementById('abfahrten');
		var s3Section = document.getElementById('s3');
		var wurzel = (section && section.getAttribute('data-root')) || document.body.getAttribute('data-root') || './';
		var rel = {
			root: wurzel,
			data: (section && section.getAttribute('data-data-endpoint')) || 'api/data',
			abfahrten: (section && section.getAttribute('data-endpoint')) || 'api/abfahrten',
			s3: (s3Section && s3Section.getAttribute('data-endpoint')) || 'api/s3',
			fahrplan: wurzel + 'api/fahrplan'
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

	/** "heute", "gestern", "morgen", sonst "29.10." (wie nb_fmt_tag_rel) */
	function fmtTagRel(ms, klein) {
		var heute = tagKey(Date.now());
		var t = tagKey(ms);
		if (t === heute) return klein === false ? 'Heute' : 'heute';
		var p = heute.split('-');
		var gestern = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] - 1)).toISOString().slice(0, 10);
		var morgen = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + 1)).toISOString().slice(0, 10);
		if (t === gestern) return klein === false ? 'Gestern' : 'gestern';
		if (t === morgen) return klein === false ? 'Morgen' : 'morgen';
		return new Date(ms).toLocaleDateString('de-DE', { timeZone: TZ, day: '2-digit', month: '2-digit' });
	}

	/** "14:50 Uhr" (heute), "gestern 15:49 Uhr", "morgen 14:00 Uhr", "29.10., 14:00 Uhr" (wie nb_fmt_zeit_rel) */
	function fmtZeitRel(ms, klein) {
		var tag = fmtTagRel(ms, klein);
		if (tag.toLowerCase() === 'heute') return fmtUhr(ms);
		return tag + (/^\d/.test(tag) ? ', ' : ' ') + fmtUhr(ms);
	}

	/** Zeitraum einer laufenden Stoerung in einem Satz (wie nb_zeitraum_satz); ende in ms oder null */
	function fmtZeitraum(start, ende, now, abgelaufen) {
		var satz = 'Seit ' + fmtZeitRel(start) + ' (' + fmtDauer(Math.max(60000, now - start)) + ')';
		if (ende !== null && ende > start && now > ende) satz += ' · länger als gemeldet, Ende war ' + fmtZeitRel(ende);
		else if (abgelaufen) satz += ' · länger als gemeldet';
		else if (ende !== null && ende > start) satz += ' · voraussichtlich bis ' + fmtZeitRel(ende);
		return satz;
	}

	/** Zeitbalken von Beginn bis gemeldetem Ende (wie nb_render_zeitbalken), null ohne Ende */
	function zeitbalken(start, ende, now, abgelaufen) {
		if (ende === null || ende <= start) return null;
		var ueber = abgelaufen || now > ende;
		var w = ueber ? 100 : Math.max(5, Math.min(100, 5 * Math.round(20 * (now - start) / (ende - start))));
		return el('div', { class: 'zeitbalken' + (ueber ? ' zeitbalken--ueber' : ''), 'aria-hidden': 'true' }, [el('div', { class: 'bar__fill', 'data-w': String(w) })]);
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
				live: !!live,
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

	var EIL_AKUT_MS = 4 * 3600 * 1000;   // so lange gilt eine Eilmeldung hoechstens als akut
	var EIL_HINWEIS_MS = 12 * 3600 * 1000; // bis dahin nennt die gruene Karte "Letzte vor …" (wie NB_EIL_HINWEIS_S)

	/**
	 * Akut (wie nb_eil_akut): juenger als vier Stunden und noch auf nordbahn.de. Was die
	 * Nordbahn entfernt hat, ist erledigt; war nordbahn.de nicht lesbar, zaehlt nur das Alter.
	 */
	function eilAkut(it, now) {
		var ms = isNaN(it.stand) ? it.ms : it.stand;
		if (ms < now - EIL_AKUT_MS) return false;
		var data = state.data;
		return it.live || !(data && data.quellen && data.quellen.nordbahn_ok);
	}

	/**
	 * Hinweis ohne spuerbare Folgen (wie nb_stoerung_hinweis): Urteil der KI (ki_folgen = keine),
	 * sonst nur ohne jede Wirkung auf den Nahverkehr und ohne schwere Einschraenkung.
	 */
	function istHinweis(st) {
		var folgen = st.ki_folgen || '';
		if (folgen) return folgen === 'keine';
		return Array.isArray(st.wirkungen) && st.wirkungen.length === 0 && String(st.einschraenkung || '').trim().toLowerCase() !== 'schwer';
	}

	function renderStatus() {
		var section = document.getElementById('status');
		if (!section) return;

		var data = state.data;
		var hasData = data && data.received_at;
		var alle = hasData ? data.stoerungen || [] : [];
		var list = alle.filter(function (st) { return !istHinweis(st); });
		var hinweise = alle.length - list.length;
		var ok = hasData && data.quellen && data.quellen.strecken_info_ok;

		// Karte 1: Infrastruktur laut strecken-info.de. Stuft strecken-info alle
		// Stoerungen als "leicht" ein, bleibt die Karte orange; ab "mittel" wird sie rot.
		// Hinweise ohne Folgen zaehlen nicht, die gruene Karte nennt sie klein.
		var schwer = list.filter(function (st) { return !/^leicht/i.test(st.einschraenkung || ''); }).length;
		if (!hasData) setStatus('status-infra', 'unknown', 'Noch keine Daten');
		else if (list.length === 0 && (ok || hinweise)) setStatus('status-infra', 'ok', 'Keine Infrastrukturstörung', hinweise ? plural(hinweise, 'Hinweis ohne Folgen', 'Hinweise ohne Folgen') : '');
		else if (list.length === 0) setStatus('status-infra', 'unknown', 'Infrastrukturstörungen unbekannt');
		else if (schwer === 0) setStatus('status-infra', 'warn', plural(list.length, 'leichte Infrastrukturstörung', 'leichte Infrastrukturstörungen'));
		else setStatus('status-infra', 'alert', plural(list.length, 'Infrastrukturstörung', 'Infrastrukturstörungen'));

		// Karte 2: Eilmeldungen der Nordbahn, akut siehe eilAkut
		var now = Date.now();
		var last = state.items.length ? state.items[state.items.length - 1] : null;
		var akut = state.items.filter(function (it) { return eilAkut(it, now); }).pop();
		if (!hasData) {
			setStatus('status-eil', 'unknown', 'Noch keine Daten');
		} else if (!last) {
			setStatus('status-eil', 'ok', 'Keine akuten Eilmeldungen');
		} else if (!akut) {
			var lastMs = isNaN(last.stand) ? last.ms : last.stand;
			setStatus('status-eil', 'ok', 'Keine akuten Eilmeldungen', now - lastMs <= EIL_HINWEIS_MS ? 'Letzte ' + relTime(lastMs) : '');
		} else {
			var akutMs = isNaN(akut.stand) ? akut.ms : akut.stand;
			setStatus('status-eil', now - akutMs < 3600 * 1000 ? 'alert' : 'warn', 'Eilmeldung ' + relTime(akutMs), akut.title);
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
			wegAnwenden();
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
		// Zeitraum als Satz plus Zeitbalken (wie nb_render_incident). "abgelaufen": das gemeldete
		// Ende ist vorbei, die Stoerung dauert aber an.
		var now = Date.now();
		var start = Date.parse(s.beginn || '');
		if (isNaN(start)) start = now;
		var ende = Date.parse(s.ende || '');
		ende = isNaN(ende) ? null : ende;

		return el('li', s.key ? { class: 'card incident', 'data-weg-id': 'st:' + s.key } : { class: 'card incident' }, [
			el('h4', { class: 'incident__title', text: titel }),
			text ? el('p', { class: 'incident__text', text: text }) : null,
			el('p', { class: 'zeitraum', text: fmtZeitraum(start, ende, now, !!s.abgelaufen) }),
			zeitbalken(start, ende, now, !!s.abgelaufen),
			meta.childNodes.length ? meta : null,
			s.key ? el('p', { class: 'incident__link' }, [el('a', { href: ereignisLink('stoerung', s.key, s.ki_titel || s.titel), text: 'Details und Verlauf' })]) : null,
			s.key ? wegKnopf('incident__weg', 'Störung') : null
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
			if (!e || !e.key || aktiv[e.key] || istHinweis(e)) return;
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
		if (h >= 24) { var d = Math.floor(h / 24); h %= 24; return d + (d === 1 ? ' Tag' : ' Tage') + (h ? ' ' + h + ' Std.' : ''); }
		return h + ' Std.' + (rest ? ' ' + rest + ' Min.' : '');
	}

	/**
	 * Was gerade gilt, aus allen Quellen (wie nb_aktuelle_meldungen): akute Eilmeldungen (eilAkut),
	 * Stoerungen bis eine Stunde nach der Aufhebung, ungeplante HVV-Meldungen, die
	 * jetzt gelten. Geplante HVV-Meldungen bleiben draussen, inhaltsgleiche zaehlen einmal.
	 */
	function aktuelleMeldungen(now) {
		var liste = [];
		verlaufItems(state.data).forEach(function (it) {
			if (it.ende >= now - STOERUNG_NACHLAUF_MS) liste.push({ art: 'stoerung', ts: it.start, titel: it.titel, text: it.text, link: ereignisLink('stoerung', it.key, it.titel), meta: fmtBis(it.start, it.ende) + ' · ' + fmtDauer(it.ende - it.start), wegId: 'st:' + it.key });
		});
		state.items.forEach(function (it) {
			var ms = isNaN(it.stand) ? it.ms : it.stand;
			if (eilAkut(it, now)) liste.push({ art: 'eilmeldung', ts: ms, titel: it.title || it.id, text: it.text, link: ereignisLink('eilmeldung', it.id, it.title || it.id), meta: '', wegId: it.id ? 'eil:' + it.id : '' });
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

	/** Stoerung oder Eilmeldung als Listeneintrag (wie nb_render_wichtig_eintrag), mit Knopf zum Wegklicken. */
	function meldungEintrag(it) {
		var meta = el('p', { class: 'feed__meta' }, [it.meta]);
		if (it.text) { if (it.meta) meta.appendChild(el('br')); meta.appendChild(document.createTextNode(it.text)); }
		var attrs = istHeute(it.ts) ? { class: 'is-heute' } : {};
		if (it.wegId) attrs['data-weg-id'] = it.wegId;
		var rubrik = it.art === 'stoerung' ? 'Störung' : 'Eilmeldung';
		return el('li', attrs, [
			el('div', { class: 'feed__time' }, [fmtWann(it.ts)]),
			el('div', {}, [
				el('p', { class: 'feed__kicker feed__kicker--' + it.art, text: rubrik }),
				el('p', { class: 'feed__title' }, [el('a', { href: it.link, text: it.titel })]),
				it.meta || it.text ? meta : null
			]),
			it.wegId ? wegKnopf('feed__weg', rubrik) : null
		]);
	}

	/* ---------- Meldungen wegklicken ---------- */

	/*
	 * Jede Meldung auf der Startseite (Stoerungskarten, "Aktuelle Meldungen") traegt eine
	 * Kennung in data-weg-id: eil:<id>, st:<key> oder hvv:<id>. Wer sie wegklickt, sieht sie
	 * in diesem Browser sieben Tage lang nicht mehr; die Kennungen liegen in localStorage.
	 * Die Lagekarten oben zeigen weiter die ganze Lage und fuehren zu /aktuell/, wo immer
	 * alles steht.
	 */
	var WEG_KEY = 'nordbahn.meldungen.weg';   // { "<kennung>": <Zeitpunkt des Wegklickens in ms> }
	var WEG_MAX_MS = 7 * 24 * 3600 * 1000;     // so lange bleibt ein Eintrag gemerkt

	/** Knopf zum Wegklicken (wie nb_weg_knopf), hier gleich sichtbar. */
	function wegKnopf(klasse, art) {
		return el('button', { type: 'button', class: klasse, 'aria-label': art + ' ausblenden', title: 'Diese Meldung für mich ausblenden', text: '\u00d7' });
	}

	/** Weggeklickte Meldungen aus dem Browser; alte Eintraege fallen beim Lesen weg. */
	function wegLesen() {
		var weg = {};
		try { weg = JSON.parse(storage('get', WEG_KEY) || '{}') || {}; } catch (e) { weg = {}; }
		var grenze = Date.now() - WEG_MAX_MS, out = {};
		Object.keys(weg).forEach(function (id) { if (typeof weg[id] === 'number' && weg[id] >= grenze) out[id] = weg[id]; });
		return out;
	}

	function wegSchreiben(weg) {
		storage('set', WEG_KEY, JSON.stringify(weg));
	}

	/** Versteckt die weggeklickten unter den Eintraegen, liefert die Zahl der sichtbaren. */
	function wegZaehlen(lis, weg) {
		var sichtbar = 0;
		Array.prototype.forEach.call(lis, function (li) {
			var id = li.getAttribute('data-weg-id');
			li.hidden = !!(id && weg[id]);
			if (!li.hidden) sichtbar++;
		});
		return sichtbar;
	}

	/**
	 * Wendet die Auswahl auf Stoerungskarten und "Aktuelle Meldungen" an, egal ob sie vom
	 * Server oder vom Script kommen: weggeklickte Eintraege werden versteckt, die Knoepfe
	 * sichtbar. Bleibt in einer Liste nichts uebrig, verschwindet sie wie bei ruhiger Lage.
	 */
	function wegAnwenden() {
		var weg = wegLesen();
		Array.prototype.forEach.call(document.querySelectorAll('.feed__weg, .incident__weg'), function (b) { b.hidden = false; });
		var ul = document.getElementById('incidents');
		if (ul) ul.hidden = !wegZaehlen(ul.querySelectorAll(':scope > li'), weg);
		var section = document.getElementById('meldungen');
		var box = document.getElementById('meldungen-inhalt');
		if (section && box) section.hidden = !wegZaehlen(box.querySelectorAll('#meldungen-feed > li'), weg);
	}

	/** Klick auf ×: blendet die Meldung des Eintrags aus. */
	function bindWeg() {
		document.addEventListener('click', function (ev) {
			var btn = ev.target && ev.target.closest ? ev.target.closest('.feed__weg, .incident__weg') : null;
			var li = btn ? btn.closest('[data-weg-id]') : null;
			if (!li) return;
			var weg = wegLesen();
			weg[li.getAttribute('data-weg-id')] = Date.now();
			wegSchreiben(weg);
			wegAnwenden();
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
		wegAnwenden();
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
		if (!select) return;
		var wanted = (section && section.getAttribute('data-heimat-default')) || select.getAttribute('data-heimat-default') || 'APD';
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
		meineHeimat();
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
		return el('li', m.id ? { class: 'is-heute', 'data-weg-id': 'hvv:' + m.id } : { class: 'is-heute' }, [el('div', { class: 'feed__time' }, [fmtWann(zeit)]), el('div', {}, body), m.id ? wegKnopf('feed__weg', 'HVV-Meldung') : null]);
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
			return pushPost({ action: 'subscribe', subscription: sub.toJSON(), heimat: heimatState.id, zeit: notifyZeit(), zuege: meineZuege(), nur: notifyNur() });
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

	/** Heimat, Zeitregel oder meine Zuege geaendert: Abo beim Server nachziehen. */
	function pushSync() {
		if (!pushActive()) return;
		pushSubscribe().catch(function () { /* beim naechsten Mal */ });
	}

	function notifyZeit() {
		return storage('get', NOTIFY_ZEIT_KEY) === 'immer' ? 'immer' : 'pendel';
	}

	/* ---------- Meine Zuege ---------- */

	/*
	 * Gemerkte Zuege liegen im Browser und reisen mit dem Push-Abo zum Server
	 * (api/subscribe). Der meldet sich ab einer Stunde vor der Abfahrt bei
	 * Verspaetung, Ausfall, Abfahrt von einem anderen Bahnhof, Teilausfall und
	 * Gleiswechsel, an den gewaehlten Wochentagen (siehe api/notify.php).
	 * Hinzugefuegt wird ueber die Suche im Abschnitt "Meine Zuege": sie kennt
	 * alle Zuege, die die Seite in den letzten zwei Wochen am Bahnhof gesehen
	 * hat (api/fahrplan), dazu die aktuelle Tafel.
	 */
	var MEINE_KEY = 'nordbahn.meine';           // gemerkte Zuege als JSON-Liste
	var NOTIFY_NUR_KEY = 'nordbahn.notify.nur'; // "1" = nur Meldungen zu meinen Zuegen
	var MEINE_MAX = 8;                          // wie NB_PUSH_MAX_ZUEGE
	var TAGE = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
	var VORSCHLAEGE_MAX = 8;                    // Zeilen in der Vorschlagsliste
	var fahrplanState = { heimat: '', daten: null, laedt: null };
	var sucheState = { treffer: [], aktiv: -1 };

	function notifyNur() {
		return storage('get', NOTIFY_NUR_KEY) === '1';
	}

	function meineZuege() {
		try {
			var list = JSON.parse(storage('get', MEINE_KEY) || '[]');
			return Array.isArray(list) ? list.filter(function (z) { return z && z.zug && z.heimat && z.richtung; }) : [];
		} catch (e) { return []; }
	}

	function meineSpeichern(list) {
		storage('set', MEINE_KEY, JSON.stringify(list));
		renderMeineZuege();
		pushSync();
	}

	/** Alle Zugnummern eines gemerkten oder gefundenen Zugs (Varianten wie im Baufahrplan eingeschlossen). */
	function nummernVon(z) {
		return z.nummern && z.nummern.length ? z.nummern : [z.zug];
	}

	/** Vereinigung zweier Listen, Reihenfolge der ersten bleibt. */
	function vereinige(a, b) {
		var out = a.slice();
		b.forEach(function (x) { if (out.indexOf(x) < 0) out.push(x); });
		return out;
	}

	function istMeiner(d, richtung) {
		return !!d.zug && meineZuege().some(function (z) { return z.heimat === heimatState.id && z.richtung === richtung && nummernVon(z).indexOf(d.zug) >= 0; });
	}

	/** Wochentag heute in Deutschland, 1 = Montag. */
	function wochentagHeute() {
		try {
			var tag = new Intl.DateTimeFormat('en-US', { timeZone: TZ, weekday: 'short' }).format(new Date());
			return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(tag) + 1;
		} catch (e) { return 0; }
	}

	function bahnhofName(id) {
		var opt = document.querySelector('#heimat option[value="' + id + '"]');
		return opt ? opt.textContent : id;
	}

	function hamburgKurz(name) {
		return /altona/i.test(name) ? 'Altona' : /dammtor/i.test(name) ? 'Dammtor' : /hbf|hauptbahnhof/i.test(name) ? 'Hbf' : (name || 'Hamburg');
	}

	/** "Prisdorf → Hbf" bzw. "Hbf → Prisdorf" fuer einen gemerkten oder gefundenen Zug. */
	function streckeText(z, heimat) {
		return z.richtung === 'hin' ? bahnhofName(heimat) + ' → ' + hamburgKurz(z.ziel) : (z.ab || 'Hamburg') + ' → ' + bahnhofName(heimat);
	}

	/** Wochentage kurz: "Mo–Fr", "Sa, So", "täglich". */
	function tageText(tage) {
		var t = (tage || []).slice().sort().join(',');
		if (t === '1,2,3,4,5,6,7') return 'täglich';
		if (t === '1,2,3,4,5') return 'Mo–Fr';
		return (tage || []).slice().sort().map(function (n) { return TAGE[n - 1]; }).join(', ');
	}

	/** Uhrzeit "HH:MM" jetzt in deutscher Zeit. */
	function uhrJetzt() {
		return fmtClock(new Date().toISOString());
	}

	/* Fahrplan fuer die Suche: einmal je Bahnhof laden, die aktuelle Tafel ergaenzt ihn. */
	function fahrplanLaden() {
		if (fahrplanState.heimat === heimatState.id && (fahrplanState.daten || fahrplanState.laedt)) return fahrplanState.laedt || Promise.resolve();
		var heimat = heimatState.id;
		fahrplanState = { heimat: heimat, daten: null, laedt: null };
		fahrplanState.laedt = fetch(urls.fahrplan + '?bhf=' + encodeURIComponent(heimat), { headers: { 'Accept': 'application/json' } })
			.then(function (res) { return res.json(); })
			.then(function (d) { if (fahrplanState.heimat === heimat) fahrplanState.daten = d && d.ok ? d : { hin: [], rueck: [] }; })
			.catch(function () { if (fahrplanState.heimat === heimat) fahrplanState.daten = { hin: [], rueck: [] }; })
			.then(function () { if (fahrplanState.heimat === heimat) { fahrplanState.laedt = null; meineAbgleichen(); } });
		return fahrplanState.laedt;
	}

	/**
	 * Gemerkte Zuege mit dem Fahrplan abgleichen: neue Varianten (Baufahrplan,
	 * Fahrplanwechsel) kommen zu den Nummern, Zeit und Ziel folgen der zuletzt
	 * gesehenen Variante, und zwei gemerkte Varianten desselben Pendelzugs werden
	 * ein Eintrag mit den Wochentagen beider.
	 */
	function meineAbgleichen() {
		if (!fahrplanState.daten) return;
		var list = meineZuege();
		var neu = [];
		var gruppen = {};
		var geaendert = false;
		list.forEach(function (z) {
			var g = null;
			var gi = -1;
			if (z.heimat === fahrplanState.heimat) {
				(fahrplanState.daten[z.richtung] || []).some(function (x, i) {
					if (nummernVon(x).some(function (n) { return nummernVon(z).indexOf(n) >= 0; })) { g = x; gi = i; return true; }
					return false;
				});
			}
			if (!g) { neu.push(z); return; }
			var key = z.richtung + gi;
			var nummern = vereinige(nummernVon(g), nummernVon(z)).slice(0, 5);
			if (gruppen[key]) {
				var m = gruppen[key];
				m.nummern = vereinige(m.nummern, nummern).slice(0, 5);
				m.tage = vereinige(m.tage || [], z.tage || []).sort();
				geaendert = true;
				return;
			}
			var vorher = JSON.stringify([z.zug, z.zeit, z.ziel, z.ab, nummernVon(z)]);
			z.zug = g.zug; z.zeit = g.zeit; z.ziel = g.ziel || z.ziel; z.ab = g.ab || ''; z.linie = g.linie || z.linie; z.nummern = nummern;
			if (JSON.stringify([z.zug, z.zeit, z.ziel, z.ab, z.nummern]) !== vorher) geaendert = true;
			gruppen[key] = z;
			neu.push(z);
		});
		if (geaendert) meineSpeichern(neu);
	}

	/** Zuege einer Richtung: Fahrplan plus die Tafel (Zuege, die das Log noch nicht kennt). */
	function fahrplanZuege(richtung) {
		var list = ((fahrplanState.daten && fahrplanState.daten[richtung]) || []).map(function (z) {
			return { zug: z.zug, nummern: nummernVon(z), varianten: z.varianten || [], linie: z.linie, zeit: z.zeit, ziel: z.ziel, ab: z.ab || '', tage: z.tage || [], richtung: richtung };
		});
		var bekannt = {};
		list.forEach(function (z) { z.nummern.forEach(function (n) { bekannt[n] = true; }); });
		var tafel = departuresState.last && departuresState.last.heimat && departuresState.last.heimat.id === heimatState.id && departuresState.last.richtungen;
		((tafel && tafel[richtung]) || []).forEach(function (d) {
			if (!d.zug || bekannt[d.zug] || d.ersatz_fuer) return;
			bekannt[d.zug] = true;
			list.push({ zug: d.zug, nummern: [d.zug], varianten: [], linie: d.linie, zeit: fmtClock(d.zeit_plan || d.zeit), ziel: d.ziel || '', ab: richtung === 'rueck' ? d.ab || '' : '', tage: [], richtung: richtung });
		});
		return list.sort(function (a, b) { return a.zeit < b.zeit ? -1 : a.zeit > b.zeit ? 1 : 0; });
	}

	/**
	 * Treffer fuer die Eingabe. Ziffern suchen die Abfahrt ("8", "813", "8:13",
	 * "08:13"), lange Ziffernfolgen auch die Zugnummer, Buchstaben Linie und Ziel
	 * ("rb71", "altona"). Ohne Eingabe die naechsten Abfahrten ab jetzt.
	 */
	function sucheTreffer(q, richtung) {
		var list = fahrplanZuege(richtung);
		// "RB 60" ist eine Linie, keine Uhrzeit: Buchstaben und folgende Ziffern gehoeren zusammen
		var woerter = String(q || '').toLowerCase().replace(/([a-zäöü])\s+(\d)/g, '$1$2').trim().split(/\s+/).filter(Boolean);
		if (!woerter.length) {
			var jetzt = uhrJetzt();
			var ab = list.filter(function (z) { return z.zeit >= jetzt; });
			return ab.concat(list.filter(function (z) { return z.zeit < jetzt; })).slice(0, VORSCHLAEGE_MAX);
		}
		return list.filter(function (z) {
			var hhmm = z.zeit.replace(':', '');
			var kurz = hhmm.replace(/^0/, '');
			var text = (z.linie + ' ' + z.ziel + ' ' + z.ab).toLowerCase().replace(/\s+/g, '');
			return woerter.every(function (w) {
				if (/^[\d:.]+$/.test(w)) {
					var ziffern = w.replace(/[:.]/g, '');
					if (/[:.]/.test(w) && w.split(/[:.]/)[0].length === 1) ziffern = '0' + ziffern; // "8:13" heisst 08:13
					return hhmm.indexOf(ziffern) === 0 || kurz.indexOf(ziffern) === 0
						|| (ziffern.length >= 4 && z.nummern.some(function (n) { return n.indexOf(ziffern) === 0; }))
						|| z.varianten.some(function (v) { var vz = v.zeit.replace(':', ''); return vz.indexOf(ziffern) === 0 || vz.replace(/^0/, '').indexOf(ziffern) === 0; });
				}
				return text.indexOf(w.replace(/\s+/g, '')) >= 0;
			});
		}).slice(0, VORSCHLAEGE_MAX);
	}

	function istGemerkt(z) {
		return meineZuege().some(function (m) {
			return m.heimat === heimatState.id && m.richtung === z.richtung && nummernVon(m).some(function (n) { return nummernVon(z).indexOf(n) >= 0; });
		});
	}

	/** "sonst 08:17 → Hbf" fuer die Varianten eines Vorschlags. */
	function variantenText(z) {
		return (z.varianten || []).map(function (v) {
			return 'sonst ' + v.zeit + (z.richtung === 'hin' ? ' → ' + hamburgKurz(v.ziel) : ' ab ' + (v.ab || 'Hamburg'));
		}).join(', ');
	}

	function sucheRichtung() {
		var r = document.querySelector('input[name="meine-richtung"]:checked');
		return r ? r.value : 'hin';
	}

	function sucheSchliessen() {
		var input = document.getElementById('meine-suche');
		var ul = document.getElementById('meine-vorschlaege');
		if (ul) ul.hidden = true;
		if (input) { input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
		sucheState.aktiv = -1;
	}

	function sucheZeigen() {
		var input = document.getElementById('meine-suche');
		var ul = document.getElementById('meine-vorschlaege');
		if (!input || !ul) return;
		var richtung = sucheRichtung();
		sucheState.treffer = sucheTreffer(input.value, richtung);
		if (sucheState.aktiv >= sucheState.treffer.length) sucheState.aktiv = sucheState.treffer.length - 1;
		clear(ul);
		if (!fahrplanState.daten) {
			ul.appendChild(el('li', { class: 'meine__leer', text: 'Lade Fahrplan …' }));
		} else if (!sucheState.treffer.length) {
			ul.appendChild(el('li', { class: 'meine__leer', text: input.value.trim()
				? 'Kein Zug gefunden. Probier eine Uhrzeit wie 8:13.'
				: 'Für diesen Bahnhof kennt die Seite noch keine Züge. Sie lernt sie, sobald jemand ihn öffnet.' }));
		}
		sucheState.treffer.forEach(function (z, i) {
			var gemerkt = istGemerkt(z);
			var varianten = variantenText(z);
			var li = el('li', { id: 'meine-opt-' + i, role: 'option', class: 'meine__vorschlag' + (i === sucheState.aktiv ? ' is-aktiv' : ''), 'aria-selected': i === sucheState.aktiv ? 'true' : 'false', 'aria-disabled': gemerkt ? 'true' : 'false' }, [
				el('strong', { text: z.zeit }), ' ',
				el('span', { class: 'meine__linie', text: z.linie }), ' ',
				el('span', { class: 'meine__strecke', text: streckeText(z, heimatState.id) }),
				el('small', { text: gemerkt ? 'gemerkt' : tageText(z.tage) }),
				// Baufahrplan oder Fahrplanwechsel: dieselbe Fahrt unter anderer Zeit und Nummer
				varianten ? el('small', { class: 'meine__variante', text: varianten }) : null
			]);
			// mousedown statt click: das Eingabefeld behaelt den Fokus, die Liste bleibt stehen
			li.addEventListener('mousedown', function (ev) { ev.preventDefault(); sucheWaehlen(i); });
			ul.appendChild(li);
		});
		ul.hidden = false;
		input.setAttribute('aria-expanded', 'true');
		if (sucheState.aktiv >= 0) input.setAttribute('aria-activedescendant', 'meine-opt-' + sucheState.aktiv);
		else input.removeAttribute('aria-activedescendant');
	}

	/**
	 * Zug aus den Vorschlaegen merken. Wochentage: an denen er beobachtet wurde,
	 * werktags also Mo-Fr; unbekannt (nur von der Tafel) Mo-Fr, am Wochenende
	 * zusaetzlich heute.
	 */
	function sucheWaehlen(i) {
		var z = sucheState.treffer[i];
		var input = document.getElementById('meine-suche');
		if (!z || istGemerkt(z)) return;
		var list = meineZuege();
		if (list.length >= MEINE_MAX) return;
		var gesehen = z.tage || [];
		var tage = gesehen.some(function (t) { return t <= 5; }) ? [1, 2, 3, 4, 5] : gesehen.slice();
		if (!tage.length) {
			tage = [1, 2, 3, 4, 5];
			if (wochentagHeute() > 5) tage.push(wochentagHeute());
		}
		list.push({ heimat: heimatState.id, richtung: z.richtung, zug: z.zug, nummern: nummernVon(z), linie: z.linie, zeit: z.zeit, ab: z.ab || '', ziel: z.ziel || '', tage: tage });
		track('Zug gemerkt', { richtung: z.richtung });
		if (input) input.value = '';
		sucheSchliessen();
		meineSpeichern(list);
		if (input) input.blur();
	}

	function initMeineZuege() {
		var section = document.getElementById('meine');
		var input = document.getElementById('meine-suche');
		if (!section || !input) return;
		section.hidden = false;
		input.addEventListener('focus', function () {
			sucheState.aktiv = -1;
			sucheZeigen();
			fahrplanLaden().then(function () { if (document.activeElement === input) sucheZeigen(); });
		});
		input.addEventListener('input', function () { sucheState.aktiv = -1; sucheZeigen(); });
		input.addEventListener('blur', sucheSchliessen);
		input.addEventListener('keydown', function (ev) {
			var n = sucheState.treffer.length;
			if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
				ev.preventDefault();
				if (!n) return;
				sucheState.aktiv = ev.key === 'ArrowDown' ? (sucheState.aktiv + 1) % n : (sucheState.aktiv <= 0 ? n - 1 : sucheState.aktiv - 1);
				sucheZeigen();
			} else if (ev.key === 'Enter') {
				ev.preventDefault();
				if (n) sucheWaehlen(sucheState.aktiv >= 0 ? sucheState.aktiv : 0);
			} else if (ev.key === 'Escape') {
				sucheSchliessen();
			}
		});
		Array.prototype.forEach.call(document.querySelectorAll('input[name="meine-richtung"]'), function (r) {
			r.addEventListener('change', function () { input.focus(); sucheZeigen(); });
		});
		renderMeineZuege();
	}

	/** Labels der Richtungswahl nach dem Heimatbahnhof; ein anderer Bahnhof hat einen anderen Fahrplan. */
	function meineHeimat() {
		var hin = document.getElementById('meine-label-hin');
		var rueck = document.getElementById('meine-label-rueck');
		if (hin) hin.textContent = heimatState.name + ' → Hamburg';
		if (rueck) rueck.textContent = 'Hamburg → ' + heimatState.name;
		sucheSchliessen();
		renderMeineZuege();
	}

	/** Liste "Meine Zuege": Zeit, Linie, Strecke, Wochentage zum Umschalten, Kreuz zum Entfernen. */
	function renderMeineZuege() {
		var ul = document.getElementById('meine-zuege-liste');
		if (!ul) return;
		var list = meineZuege();
		var leer = document.getElementById('meine-leer');
		if (leer) leer.hidden = list.length > 0;
		clear(ul);
		list.forEach(function (z, i) {
			var weg = el('button', { type: 'button', class: 'meine__weg', title: 'Entfernen', 'aria-label': 'Zug ' + z.zeit + ' ' + z.linie + ' entfernen', text: '×' });
			weg.addEventListener('click', function () {
				meineSpeichern(meineZuege().filter(function (x, j) { return j !== i; }));
			});
			var tage = el('div', { class: 'meine__tage', role: 'group', 'aria-label': 'Wochentage für ' + z.zeit + ' ' + z.linie });
			TAGE.forEach(function (name, j) {
				var an = (z.tage || []).indexOf(j + 1) >= 0;
				var b = el('button', { type: 'button', class: 'meine__tag', 'aria-pressed': an ? 'true' : 'false', text: name });
				b.addEventListener('click', function () {
					var l = meineZuege();
					if (!l[i]) return;
					var neu = (l[i].tage || []).filter(function (t) { return t !== j + 1; });
					if (!an) neu.push(j + 1);
					if (!neu.length) return; // mindestens ein Tag, sonst lieber entfernen
					l[i].tage = neu.sort();
					meineSpeichern(l);
				});
				tage.appendChild(b);
			});
			ul.appendChild(el('li', { class: 'meine__zug' }, [
				el('div', { class: 'meine__kopf' }, [
					el('strong', { class: 'meine__zeit', text: z.zeit }), ' ',
					el('span', { class: 'meine__linie', text: z.linie }), ' ',
					el('span', { class: 'meine__strecke', text: streckeText(z, z.heimat) }),
					weg
				]),
				tage
			]));
		});

		var input = document.getElementById('meine-suche');
		if (input) {
			var voll = list.length >= MEINE_MAX;
			input.disabled = voll;
			input.placeholder = voll ? 'Höchstens acht Züge, entferne erst einen' : (list.length ? 'Weiteren Zug suchen, z. B. 17:04' : 'Abfahrt suchen, z. B. 8:13');
		}

		// Hinweis: was fehlt, damit Meldungen kommen
		var hinweis = document.getElementById('meine-hinweis');
		if (!hinweis) return;
		clear(hinweis);
		var text = '';
		var knopf = null;
		if (!('Notification' in window)) {
			text = isIosOhneApp()
				? 'Auf dem iPhone kommen Meldungen nur, wenn die Seite auf dem Home-Bildschirm liegt. Wie das geht, steht unten bei „Benachrichtigungen“.'
				: 'Dieser Browser kann keine Benachrichtigungen anzeigen.';
		} else if (Notification.permission === 'denied') {
			text = ''; // steht beim Schalter unten
		} else if (list.length && !notifyEnabled()) {
			text = 'Noch kommen keine Meldungen.';
			knopf = el('button', { type: 'button', class: 'meine__an', text: 'Benachrichtigungen einschalten' });
			knopf.addEventListener('click', function () { notifyEinschalten(); });
		}
		if (text) hinweis.appendChild(document.createTextNode(text + ' '));
		if (knopf) hinweis.appendChild(knopf);
		hinweis.hidden = !text;
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
					mein: istMeiner(d, key),
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
		if (!notifyEnabled() || pushActive()) return;
		// Meine Zuege melden sich immer, alles andere nur ohne "nur meine Zuege" und zur gewaehlten Zeit
		var allesErlaubt = !notifyNur() && notifyAllowedNow();
		fresh.filter(function (ev) { return ev.mein || allesErlaubt; }).slice(0, 3).forEach(function (ev) {
			try {
				var n = new Notification(ev.title, { body: ev.body, icon: root() + 'icon-192.png', tag: ev.id, lang: 'de' });
				n.onclick = function () { window.focus(); n.close(); };
			} catch (e) { /* Benachrichtigung nicht moeglich */ }
		});
	}

	/**
	 * Glocke im Kopf (Link zu den Einstellungen) zeigt, ob Benachrichtigungen an
	 * sind; auf der Einstellungsseite stehen Schalter, Was und Wann.
	 */
	function renderNotify() {
		var unterstuetzt = 'Notification' in window;
		var blockiert = unterstuetzt && Notification.permission === 'denied';
		var on = notifyEnabled();
		var nur = notifyNur();
		var text = !unterstuetzt ? 'Benachrichtigungen hier nicht möglich' : blockiert ? 'Benachrichtigungen im Browser blockiert'
			: on ? 'Benachrichtigungen an' + (nur ? ', nur meine Züge' : notifyZeit() === 'pendel' ? ', nur zu Pendelzeiten' : '') : 'Benachrichtigungen aus';
		var btn = document.getElementById('notify-toggle');
		if (btn) {
			btn.classList.toggle('tool--an', on);
			btn.title = text + ' · Einstellungen';
			var label = document.getElementById('notify-label');
			if (label) label.textContent = text + ', Einstellungen öffnen';
		}

		var box = document.getElementById('notify-on');
		if (box) {
			box.disabled = !unterstuetzt || blockiert;
			box.checked = on;
			var hint = document.getElementById('notify-hint');
			if (hint) {
				var h = !unterstuetzt
					? (isIosOhneApp() ? 'Auf dem iPhone geht das nur, wenn die Seite auf dem Home-Bildschirm liegt, siehe unten.' : 'Dieser Browser kann keine Benachrichtigungen anzeigen.')
					: blockiert ? 'Im Browser blockiert. Erlaube Benachrichtigungen für diese Seite in den Browser-Einstellungen.' : '';
				hint.textContent = h;
				hint.hidden = h === '';
			}
			var pushHint = document.getElementById('notify-push-hint');
			if (pushHint) {
				var ph = '';
				if (on && pushActive()) ph = 'Kommt auch bei geschlossener Seite aufs Gerät.';
				else if (on) ph = 'Dieser Browser meldet nur, solange die Seite offen ist.';
				pushHint.textContent = ph;
				pushHint.hidden = ph === '';
			}
			Array.prototype.forEach.call(document.querySelectorAll('input[name="notify-zeit"]'), function (r) { r.checked = r.value === notifyZeit(); });
			Array.prototype.forEach.call(document.querySelectorAll('input[name="notify-was"]'), function (r) { r.checked = r.value === (nur ? 'nur' : 'alles'); });
			// Erklaerung nur zur gewaehlten Option
			var wasText = document.getElementById('notify-was-text');
			if (wasText) wasText.textContent = nur ? 'Verspätung, Ausfall, anderer Bahnhof oder Gleiswechsel bei meinen Zügen.' : 'Zugausfälle am Bahnhof, Infrastrukturstörungen und Eilmeldungen, dazu meine Züge.';
			var wannText = document.getElementById('notify-wann-text');
			if (wannText) wannText.textContent = notifyZeit() === 'pendel' ? 'Mo–Fr, 6–9 und 16–19 Uhr. Meine Züge melden sich immer zu ihrer Abfahrt.' : 'Rund um die Uhr, auch nachts und am Wochenende.';
			var wann = document.getElementById('notify-wann');
			if (wann) wann.hidden = nur; // meine Zuege melden sich zu ihrer Abfahrt, die Zeitregel gilt nur fuer den Rest
			var inst = document.getElementById('installieren');
			if (inst && isIosOhneApp()) inst.open = true;
		}
		renderMeineZuege();
	}

	/** Einschalten: Erlaubnis holen, Push abonnieren, kurze Bestaetigung zeigen. */
	function notifyEinschalten() {
		if (!('Notification' in window)) return;
		Notification.requestPermission().then(function (perm) {
			if (perm !== 'granted') { renderNotify(); return; }
			storage('set', NOTIFY_KEY, '1');
			track('Benachrichtigung an');
			var done = function () {
				try {
					var was = notifyNur() ? 'zu deinen gemerkten Zügen' : 'bei neuen Zugausfällen und Störungen';
					var body = pushActive()
						? 'Du bekommst jetzt eine Meldung ' + was + ', auch wenn die Seite zu ist.'
						: 'Du bekommst jetzt eine Meldung ' + was + ', solange die Seite offen ist.';
					new Notification('Bahn-Pinneberg.de', { body: body, icon: root() + 'icon-192.png', lang: 'de' });
				} catch (e) { /* egal */ }
				renderNotify();
			};
			if (pushSupported()) pushSubscribe().then(done, function () { storage('set', PUSH_KEY, '0'); done(); });
			else done();
		});
	}

	function initNotify() {
		renderNotify();
		var box = document.getElementById('notify-on');
		if (box) box.addEventListener('change', function () {
			if (box.checked) { notifyEinschalten(); return; }
			storage('set', NOTIFY_KEY, '0');
			pushUnsubscribe().then(renderNotify);
			renderNotify();
		});
		Array.prototype.forEach.call(document.querySelectorAll('input[name="notify-zeit"]'), function (r) {
			r.addEventListener('change', function () {
				if (r.checked) { storage('set', NOTIFY_ZEIT_KEY, r.value); renderNotify(); pushSync(); }
			});
		});
		Array.prototype.forEach.call(document.querySelectorAll('input[name="notify-was"]'), function (r) {
			r.addEventListener('change', function () {
				if (r.checked) { storage('set', NOTIFY_NUR_KEY, r.value === 'nur' ? '1' : '0'); renderNotify(); pushSync(); }
			});
		});
	}

	/* ---------- Alles loeschen (Einstellungen) ---------- */

	function initDaten() {
		var btn = document.getElementById('daten-loeschen');
		if (!btn) return;
		btn.addEventListener('click', function () {
			if (!window.confirm('Alles löschen, was dieser Browser für Bahn-Pinneberg.de gespeichert hat? Gemerkte Züge und Benachrichtigungen sind dann weg.')) return;
			btn.disabled = true;
			pushUnsubscribe().then(function () {
				try {
					Object.keys(window.localStorage).forEach(function (k) { if (k.indexOf('nordbahn.') === 0) window.localStorage.removeItem(k); });
				} catch (e) { /* privater Modus */ }
				window.location.reload();
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

	/** Einstellungsseite: kein Nachladen der Lage, nur Bahnhof, Meine Zuege, Benachrichtigungen, Daten. */
	function bootEinstellungen() {
		initHeimat();
		initNotify();
		initMeineZuege();
		initDaten();
		if (meineZuege().length) fahrplanLaden();
	}

	function boot() {
		resolveUrls();
		if (document.body.getAttribute('data-seite') === 'einstellungen') { bootEinstellungen(); return; }
		var raw = document.getElementById('nordbahn-data');
		var data = {};
		try { data = JSON.parse(raw.textContent); } catch (e) { data = {}; }
		bindWeg();
		setData(data);
		// Mit HVV-Zugang steht bis zur Antwort von api/s3 noch die Liste vom Server
		wegAnwenden();
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
		initNotify();
		// Gemerkte Zuege mit dem Fahrplan abgleichen (neue Varianten im Baufahrplan)
		if (meineZuege().length) fahrplanLaden();
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
