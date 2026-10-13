/*
 * Nordbahn Monitor: baut aus dem eingebetteten JSON (siehe index.php) das
 * Dashboard, holt alle zwei Minuten frische Daten von api/data und laedt die
 * Seite einmal pro Stunde komplett neu.
 *
 * Alle Texte aus den Daten (Titel, Meldungstexte, Bahnhofsnamen) stammen von
 * fremden Seiten und landen deshalb ausschliesslich per textContent im DOM.
 * Zeiten werden in Europe/Berlin gerechnet, egal wo der Browser steht.
 */
(function () {
	'use strict';

	var TZ = 'Europe/Berlin';
	var REFRESH_MS = 2 * 60 * 1000;       // Störungen und Eilmeldungen nachladen
	var RELOAD_MS = 60 * 60 * 1000;        // komplette Seite neu laden, damit lange offene Tabs neue Versionen bekommen
	var STALE_MS = 20 * 60 * 1000;

	var state = {
		data: null,
		items: []        // aufbereitete Eilmeldungen
	};

	// Was die Tab-Markierung (Favicon-Punkt und Titel) wissen muss
	var alertState = { stoerungen: 0, ausfaelle: 0, verspaetet: 0 };

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
		var stale = Date.now() - received > STALE_MS;
		if (stale) box.classList.add('is-stale');
		box.appendChild(el('span', { class: 'dot' }));
		var text = 'Stand ' + fmtDateTime(state.data.received_at) + ' (' + relTime(received) + ')';
		if (stale) text += ', Daten veraltet';
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

		// Karte 1: Infrastruktur laut strecken-info.de
		if (!hasData) setStatus('status-infra', 'unknown', 'Noch keine Daten');
		else if (list.length === 0 && ok) setStatus('status-infra', 'ok', 'Keine Infrastrukturstörung');
		else if (list.length === 0) setStatus('status-infra', 'unknown', 'Infrastrukturstörungen unbekannt');
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
		updateBadge();
		notifyNew(list.map(function (st) {
			return { id: 'st:' + st.key, title: 'Infrastrukturstörung', body: (st.titel || 'Störung') + (st.text ? ': ' + st.text : '') };
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
		if (!result) { setStatus('status-betrieb', 'unknown', 'Lade Fahrplan …'); return; }
		if (!result.richtungen) { setStatus('status-betrieb', 'unknown', 'Fahrplanlage unbekannt'); return; }
		var total = 0, ausfaelle = 0, umleitungen = 0, verspaetet = 0, maxDelay = 0;
		['hin', 'rueck'].forEach(function (key) {
			(result.richtungen[key] || []).slice(0, 6).forEach(function (d) {
				if (d.ersatz_fuer) return; // Ersatzzug ist schon ueber den Ausfall erfasst
				total++;
				var rb60 = /RB\s?60/i.test(d.linie || '');
				var altona = !rb60 && (/altona/i.test(d.ab || '') || /altona/i.test(d.ziel || '') || !!d.ersatz);
				if (d.ausfall && !d.ersatz) ausfaelle++;
				else if (altona) umleitungen++;
				else if (d.verspaetung >= 10) { verspaetet++; maxDelay = Math.max(maxDelay, d.verspaetung); }
			});
		});
		var betroffen = ausfaelle + umleitungen + verspaetet;
		if (!total || !betroffen) {
			setStatus('status-betrieb', result.ok ? 'ok' : 'unknown', result.ok ? 'Fahrplan läuft' : 'Fahrplanlage unbekannt');
			return;
		}
		var parts = [];
		if (ausfaelle) parts.push(plural(ausfaelle, 'Zugausfall', 'Zugausfälle'));
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
		var abschnitte = (s.abschnitte || []).map(function (a) {
			return [a.von, a.bis].filter(Boolean).join(' – ') + (a.strecke ? ' (Strecke ' + a.strecke + ')' : '');
		}).filter(Boolean).join(', ');
		row('Abschnitt', abschnitte || (s.betriebsstellen || []).join(', '));
		row('Auswirkung', (s.wirkungen || []).join(', '));
		row('Einschränkung', s.einschraenkung ? s.einschraenkung.charAt(0).toUpperCase() + s.einschraenkung.slice(1) : '');
		var zeit = '';
		if (s.beginn) zeit = 'seit ' + fmtDateTime(s.beginn);
		if (s.ende) zeit += (zeit ? ', ' : '') + 'voraussichtlich bis ' + fmtDateTime(s.ende);
		row('Zeitraum', zeit);

		return el('li', { class: 'card incident' }, [
			el('h4', { class: 'incident__title', text: s.titel || 'Störung' }),
			s.text ? el('p', { class: 'incident__text', text: s.text }) : null,
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

		if (sub) {
			if (!result) sub.textContent = 'Lade …';
			else if (!ok && result.fetched_at) sub.textContent = 'Keine aktuellen Daten, letzter Stand ' + fmtClock(result.fetched_at) + ' Uhr';
			else if (!ok) sub.textContent = 'Keine aktuellen Daten';
			else sub.textContent = 'Stand ' + fmtClock(result.fetched_at) + ' Uhr';
		}
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
				if (d.ab) {
					// Rueckrichtung: der Startbahnhof ist die wichtige Information, Altona faellt auf
					dest.appendChild(el('span', { class: 'dep__ab' + (/altona/i.test(d.ab) ? ' dep__ab--altona' : ''), text: 'ab ' + d.ab }));
					dest.appendChild(document.createTextNode(' '));
				}
				dest.appendChild(el('span', { class: 'dep__ziel', text: d.ziel }));
				if (d.ersatz_fuer) {
					dest.appendChild(el('small', { class: 'dep__ersatz', text: 'Ersatz für den ' + fmtClock(d.ersatz_fuer.zeit) + ' ab ' + d.ersatz_fuer.ab }));
				}
				if (d.hinweis) dest.appendChild(el('small', { text: d.hinweis }));
				if (d.ersatz) {
					var e = d.ersatz;
					var eLate = e.verspaetung >= 3;
					dest.appendChild(el('small', { class: 'dep__ersatz', text: 'Fährt stattdessen ab ' + e.ab + ' um ' + fmtClock(e.zeit)
						+ (eLate ? ' (+' + e.verspaetung + ')' : '') + (e.gleis ? ', Gl. ' + e.gleis : '') }));
				}

				var where = d.gleis ? 'Gl. ' + d.gleis : '';
				var row = el('li', { class: cls }, [
					el('span', { class: 'dep__dot', 'aria-hidden': 'true' }),
					time,
					line,
					dest,
					el('div', { class: 'dep__platform', text: where })
				]);
				var sr = (d.ausfall ? 'Fällt aus: ' : late ? 'Verspätet um ' + d.verspaetung + ' Minuten: ' : '');
				if (d.ersatz_fuer) sr = 'Ersatz für ' + fmtClock(d.ersatz_fuer.zeit) + ' ab ' + d.ersatz_fuer.ab + ': ';
				if (sr) row.setAttribute('aria-label', sr + fmtClock(d.zeit) + ' ' + d.linie + ' nach ' + d.ziel + (d.ab ? ' ab ' + d.ab : ''));
				list.appendChild(row);
			});
		});
		alertState.ausfaelle = ausfaelle;
		alertState.verspaetet = verspaetet;
		updateBadge();
		notifyNew(collectEvents(result));
	}

	var departuresState = { last: null };

	function refreshDepartures() {
		var section = document.getElementById('abfahrten');
		if (!section || document.hidden || !window.fetch) return;
		var url = section.getAttribute('data-endpoint') || 'api/abfahrten';
		fetch(url, { cache: 'no-cache', headers: { 'Accept': 'application/json' } })
			.then(function (res) { return res.json(); })
			.then(function (result) {
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

	/** Rot bei Störung oder Zugausfall, orange bei Verspätung, sonst nichts. */
	function updateBadge() {
		var level = alertState.stoerungen || alertState.ausfaelle ? 'alert' : alertState.verspaetet ? 'warn' : '';
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
				var richtung = key === 'hin' ? 'Prisdorf → Hamburg' : 'Hamburg → Prisdorf';
				events.push({
					id: 'zug:' + (d.zug || d.linie + d.zeit),
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
		if (!notifyEnabled()) return;
		fresh.slice(0, 3).forEach(function (ev) {
			try {
				var n = new Notification(ev.title, { body: ev.body, icon: 'favicon.svg', tag: ev.id, lang: 'de' });
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
		var text = on ? 'Benachrichtigungen ausschalten' : 'Bei Ausfällen benachrichtigen';
		if (Notification.permission === 'denied') {
			text = 'Benachrichtigungen im Browser blockiert';
			btn.disabled = true;
		}
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		btn.title = text;
		if (label) label.textContent = text;
	}

	function initNotifyButton() {
		var btn = document.getElementById('notify-toggle');
		if (!btn) return;
		renderNotifyButton();
		btn.addEventListener('click', function () {
			if (notifyEnabled()) {
				storage('set', NOTIFY_KEY, '0');
				renderNotifyButton();
				return;
			}
			Notification.requestPermission().then(function (perm) {
				if (perm === 'granted') {
					storage('set', NOTIFY_KEY, '1');
					try { new Notification('Nordbahn Monitor', { body: 'Du bekommst jetzt eine Meldung bei neuen Zugausfällen und Störungen.', icon: 'favicon.svg', lang: 'de' }); } catch (e) { /* egal */ }
				}
				renderNotifyButton();
			});
		});
	}

	/* ---------- Steuerung ---------- */

	function renderAll() {
		renderFreshness();
		renderStatus();
		renderFeed();
	}

	function setData(data) {
		state.data = data || {};
		prepare(state.data);
		renderAll();
	}

	function refresh() {
		if (document.hidden || !window.fetch) return;
		var app = document.getElementById('app');
		var url = (document.getElementById('abfahrten') || app).getAttribute('data-data-endpoint') || 'api/data';
		fetch(url, { cache: 'no-cache', headers: { 'Accept': 'application/json' } })
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
		var raw = document.getElementById('nordbahn-data');
		var data = {};
		try { data = JSON.parse(raw.textContent); } catch (e) { data = {}; }
		setData(data);
		setInterval(refresh, REFRESH_MS);
		setInterval(renderFreshness, 60000);
		setInterval(function () { if (!document.hidden) window.location.reload(); }, RELOAD_MS);
		if (document.getElementById('abfahrten')) {
			orderBoards();
			renderDepartures(null);
			refreshDepartures();
			setInterval(refreshDepartures, DEPARTURES_MS);
			setInterval(function () { orderBoards(); if (departuresState.last) renderDepartures(departuresState.last); }, 30000);
		}
		initNotifyButton();
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); refreshDepartures(); } });
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
