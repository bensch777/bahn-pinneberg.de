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
	var urls = { root: './', data: 'api/data', abfahrten: 'api/abfahrten' };

	function resolveUrls() {
		var section = document.getElementById('abfahrten');
		var rel = {
			root: (section && section.getAttribute('data-root')) || './',
			data: (section && section.getAttribute('data-data-endpoint')) || 'api/data',
			abfahrten: (section && section.getAttribute('data-endpoint')) || 'api/abfahrten'
		};
		try {
			Object.keys(rel).forEach(function (k) { urls[k] = new URL(rel[k], document.baseURI).href; });
		} catch (e) { urls = rel; }
	}

	/** Wurzelverzeichnis der Seite, fuer Icons. */
	function root() {
		return urls.root;
	}

	/** Zaehlt ein Ereignis in Plausible, wenn das Script geladen ist. */
	function track(name, props) {
		try { if (window.plausible) window.plausible(name, props ? { props: props } : undefined); } catch (e) { /* egal */ }
	}

	var state = {
		data: null,
		items: []        // aufbereitete Eilmeldungen
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
		function push(id, title, iso, live) {
			var ms = Date.parse(iso || '');
			if (!id || isNaN(ms)) return;
			seen[id] = true;
			items.push({
				id: id, title: title || '', ms: ms,
				stand: live && live.stand ? Date.parse(live.stand) : NaN,
				link: live && live.link ? live.link : ''
			});
		}
		(data.eilmeldungen || []).forEach(function (e) { if (e) push(e.id, e.title, e.seen_at, liveById[e.id]); });
		// Meldungen, die auf der Seite stehen, aber noch nicht in der Historie sind
		(data.eilmeldungen_live || []).forEach(function (e) { if (e && !seen[e.id]) push(e.id, e.title, e.stand, e); });
		items.sort(function (a, b) { return a.ms - b.ms; });

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
		clear(box);
		box.className = 'freshness';
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
		box.title = 'Lagebild: ' + fmtDateTime(state.data.received_at) + (isNaN(abfMs) ? '' : ', Abfahrten: ' + fmtDateTime(abf.fetched_at));
		box.appendChild(document.createTextNode(text));
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
	}

	var EIL_AKUT_MS = 4 * 3600 * 1000;   // so lange gilt eine Eilmeldung als akut

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
			if (age > EIL_AKUT_MS) setStatus('status-eil', 'ok', 'Keine akuten Eilmeldungen', 'Letzte ' + relTime(lastMs));
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

	function renderBetrieb(result) {
		var card = document.getElementById('status-betrieb');
		if (!result) {
			// Vom Server gerenderte Karte stehen lassen, bis die ersten Abfahrten da sind
			if (card && card.hasAttribute('data-ssr')) return;
			setStatus('status-betrieb', 'unknown', 'Lade Fahrplan …');
			return;
		}
		if (card) card.removeAttribute('data-ssr');
		if (!result.richtungen) { setStatus('status-betrieb', 'unknown', 'Fahrplanlage unbekannt'); return; }
		var total = 0, ausfaelle = 0, umleitungen = 0, verspaetet = 0, maxDelay = 0, teil = 0, teilOrt = '';
		['hin', 'rueck'].forEach(function (key) {
			(result.richtungen[key] || []).slice(0, 6).forEach(function (d) {
				if (d.ersatz_fuer) return; // Ersatzzug ist schon ueber den Ausfall erfasst
				total++;
				var rb60 = /RB\s?60/i.test(d.linie || '');
				var altona = !rb60 && (/altona/i.test(d.ab || '') || /altona/i.test(d.ziel || '') || !!d.ersatz);
				if (d.teil) { teil++; teilOrt = d.teil.name; }
				else if (d.ausfall && !d.ersatz) ausfaelle++;
				else if (altona) umleitungen++;
				else if (d.verspaetung >= 10) { verspaetet++; maxDelay = Math.max(maxDelay, d.verspaetung); }
			});
		});
		var betroffen = ausfaelle + umleitungen + verspaetet + teil;
		if (!total || !betroffen) {
			setStatus('status-betrieb', result.ok ? 'ok' : 'unknown', result.ok ? 'Fahrplan läuft' : 'Fahrplanlage unbekannt');
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
			level === 'alert' ? 'Betrieb gestört' : 'Betrieb beeinträchtigt',
			betroffen + ' von ' + total + ' Zügen (' + prozent + ' %): ' + parts.join(', '));
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
		if (s.ende) zeit += (zeit ? ', ' : '') + 'voraussichtlich bis ' + fmtDateTime(s.ende);
		row('Zeitraum', zeit);

		return el('li', { class: 'card incident' }, [
			el('h4', { class: 'incident__title', text: titel }),
			text ? el('p', { class: 'incident__text', text: text }) : null,
			meta
		]);
	}

	/* ---------- Feed ---------- */

	function renderFeed() {
		var ol = document.getElementById('feed');
		if (!ol) return;
		clear(ol);
		ol.className = 'feed card';
		var limit = parseInt(ol.getAttribute('data-limit'), 10) || 12;
		var maxAgeDays = parseInt(ol.getAttribute('data-max-age-days'), 10) || 0;
		var cutoff = maxAgeDays ? Date.now() - maxAgeDays * 86400000 : 0;
		// Massgeblich ist der Stand der Nordbahn, sonst der Erfassungszeitpunkt
		var items = state.items.filter(function (it) {
			return (isNaN(it.stand) ? it.ms : it.stand) >= cutoff;
		}).slice(-limit).reverse();
		if (!items.length) {
			ol.appendChild(el('li', {}, [el('p', { class: 'feed__empty', text: maxAgeDays
				? 'Keine Eilmeldung in den letzten ' + maxAgeDays + ' Tagen.'
				: 'Noch keine Eilmeldungen erfasst.' })]));
			return;
		}
		items.forEach(function (it) {
			var hasStand = !isNaN(it.stand);
			var time = el('div', { class: 'feed__time' }, [fmtDateTime(new Date(hasStand ? it.stand : it.ms).toISOString())]);
			var title = it.link
				? el('a', { href: it.link, rel: 'noopener', text: it.title || it.id })
				: document.createTextNode(it.title || it.id);
			ol.appendChild(el('li', {}, [time, el('p', { class: 'feed__title' }, [title])]));
		});
	}

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
		return out.sort(function (a, b) { return b.ende - a.ende; });
	}

	function fmtDauer(ms) {
		var min = Math.round(ms / 60000);
		if (min < 60) return Math.max(1, min) + ' Min.';
		var h = Math.floor(min / 60), rest = min % 60;
		return h + ' Std.' + (rest ? ' ' + rest + ' Min.' : '');
	}

	function renderVerlauf() {
		var ol = document.getElementById('verlauf-feed');
		if (!ol || !state.data) return;
		clear(ol);
		var limit = parseInt(ol.getAttribute('data-limit'), 10) || 6;
		var maxAgeDays = parseInt(ol.getAttribute('data-max-age-days'), 10) || 0;
		var cutoff = maxAgeDays ? Date.now() - maxAgeDays * 86400000 : 0;
		var items = verlaufItems(state.data).filter(function (it) { return it.ende >= cutoff; }).slice(0, limit);
		if (!items.length) {
			ol.appendChild(el('li', {}, [el('p', { class: 'feed__empty', text: maxAgeDays
				? 'Keine aufgehobene Störung in den letzten ' + maxAgeDays + ' Tagen.'
				: 'Noch keine Störung erfasst.' })]));
			return;
		}
		items.forEach(function (it) {
			var meta = el('p', { class: 'feed__meta', text: 'bis ' + fmtDateTime(new Date(it.ende).toISOString()) + ' · ' + fmtDauer(it.ende - it.start) });
			if (it.text) { meta.appendChild(el('br')); meta.appendChild(document.createTextNode(it.text)); }
			ol.appendChild(el('li', {}, [
				el('div', { class: 'feed__time' }, [fmtDateTime(new Date(it.start).toISOString())]),
				el('div', {}, [el('p', { class: 'feed__title', text: it.titel }), meta])
			]));
		});
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

	/** Vormittags steht die Fahrt in die Stadt oben, nachmittags der Rueckweg. */
	function orderBoards() {
		var boards = document.querySelector('.boards');
		if (!boards) return;
		var hour = parseInt(new Date().toLocaleTimeString('de-DE', { timeZone: TZ, hour: '2-digit', hour12: false }), 10);
		boards.classList.toggle('boards--rueck-first', hour >= 12);
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
				if (d.teil && !d.ausfall) cls += ' dep--teil';
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

				var dest = el('div', { class: 'dep__dest', title: d.ziel }, []);
				// Jeder Hamburger Bahnhof hat seine Farbe: Hbf blau, Dammtor blau umrandet (gleicher Zug), Altona orange
				function bahnhofClass(name) {
					return /altona/i.test(name) ? ' dep__ab--altona' : /dammtor/i.test(name) ? ' dep__ab--dammtor' : /hbf/i.test(name) ? ' dep__ab--hbf' : '';
				}
				if (d.teil && d.teil.art === 'beginnt' && !d.ausfall) {
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
					// Hinrichtung, Zug endet vorzeitig (z. B. Stellwerk kaputt): nur bis dorthin
					dest.appendChild(el('span', { class: 'dep__ab dep__ab--teil', text: 'nur bis ' + d.teil.name }));
					dest.appendChild(el('small', { class: 'dep__ersatz', text: 'Endet heute in ' + d.teil.name + (d.teil.zeit ? ' (an ' + d.teil.zeit + ')' : '')
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
				var sr = (d.ausfall ? 'Fällt aus: ' : late ? 'Verspätet um ' + d.verspaetung + ' Minuten: ' : '');
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

	/* ---------- Tab-Markierung ---------- */

	var BASE_TITLE = document.title;
	var faviconCache = {};

	/** Zeichnet das Favicon (blaues Quadrat mit N) und je nach Lage einen Punkt oben rechts. */
	function faviconUrl(level) {
		if (faviconCache[level]) return faviconCache[level];
		var canvas = document.createElement('canvas');
		canvas.width = 64; canvas.height = 64;
		var ctx = canvas.getContext('2d');
		if (!ctx) return null;
		var r = 14;
		ctx.fillStyle = '#2a78d6';
		ctx.beginPath();
		ctx.moveTo(r, 0); ctx.lineTo(64 - r, 0); ctx.quadraticCurveTo(64, 0, 64, r);
		ctx.lineTo(64, 64 - r); ctx.quadraticCurveTo(64, 64, 64 - r, 64);
		ctx.lineTo(r, 64); ctx.quadraticCurveTo(0, 64, 0, 64 - r);
		ctx.lineTo(0, r); ctx.quadraticCurveTo(0, 0, r, 0);
		ctx.closePath(); ctx.fill();
		ctx.fillStyle = '#fff';
		ctx.font = '700 40px Inter, system-ui, -apple-system, "Segoe UI", sans-serif';
		ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
		ctx.fillText('N', 32, 34);
		if (level) {
			ctx.beginPath(); ctx.arc(49, 15, 16, 0, Math.PI * 2);
			ctx.fillStyle = '#fff'; ctx.fill();
			ctx.beginPath(); ctx.arc(49, 15, 13, 0, Math.PI * 2);
			ctx.fillStyle = level === 'alert' ? '#d03b3b' : '#f59e0b'; ctx.fill();
		}
		faviconCache[level] = canvas.toDataURL('image/png');
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
		if (!notifyEnabled() || !notifyAllowedNow()) return;
		fresh.slice(0, 3).forEach(function (ev) {
			try {
				var n = new Notification(ev.title, { body: ev.body, icon: root() + 'favicon.svg', tag: ev.id, lang: 'de' });
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
				renderNotifyButton();
				return;
			}
			Notification.requestPermission().then(function (perm) {
				if (perm === 'granted') {
					storage('set', NOTIFY_KEY, '1');
					try { new Notification('Nordbahn Monitor', { body: 'Du bekommst jetzt eine Meldung bei neuen Zugausfällen und Störungen.', icon: root() + 'favicon.svg', lang: 'de' }); } catch (e) { /* egal */ }
					track('Benachrichtigung an');
				}
				renderNotifyButton();
			});
		});
		Array.prototype.forEach.call(document.querySelectorAll('input[name="notify-zeit"]'), function (r) {
			r.addEventListener('change', function () {
				if (r.checked) { storage('set', NOTIFY_ZEIT_KEY, r.value); renderNotifyButton(); }
			});
		});
	}

	/* ---------- Steuerung ---------- */

	function renderAll() {
		renderFreshness();
		renderStatus();
		renderFeed();
		renderVerlauf();
	}

	function setData(data) {
		state.data = data || {};
		prepare(state.data);
		renderAll();
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
		setData(data);
		setInterval(refresh, REFRESH_MS);
		setInterval(renderFreshness, 60000);
		setInterval(function () { if (!document.hidden) window.location.reload(); }, RELOAD_MS);
		if (document.getElementById('abfahrten')) {
			initHeimat();
			orderBoards();
			renderDepartures(null);
			refreshDepartures();
			setInterval(refreshDepartures, DEPARTURES_MS);
			setInterval(function () { orderBoards(); if (departuresState.last) renderDepartures(departuresState.last); }, 30000);
		}
		initNotifyButton();
		initRefreshButton();
		Array.prototype.forEach.call(document.querySelectorAll('a[href*="t.me"]'), function (a) {
			a.addEventListener('click', function () { track('Telegram', { kanal: a.getAttribute('href').replace(/.*\//, '') }); });
		});
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); refreshDepartures(); } });
	}

	/** Manuell nachladen, vor allem fuer die Webapp ohne Browserleiste. Das Icon dreht sich, solange geladen wird. */
	function initRefreshButton() {
		var btn = document.getElementById('refresh-button');
		if (!btn) return;
		if (!window.fetch || !window.Promise) { btn.hidden = true; return; }
		btn.addEventListener('click', function () {
			if (btn.classList.contains('is-busy')) return;
			btn.classList.add('is-busy');
			var started = Date.now();
			Promise.all([refresh(), refreshDepartures()]).then(done, done);
			function done() {
				// mindestens eine halbe Umdrehung, sonst flackert es nur
				setTimeout(function () { btn.classList.remove('is-busy'); }, Math.max(0, 600 - (Date.now() - started)));
			}
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
