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

	// Reihenfolge = Prioritaet: "Streckensperrung ... Zugausfaelle" zaehlt als Sperrung.
	var CATEGORIES = [
		{ key: 'sperrung', label: 'Streckensperrung', test: /sperrung|gesperrt/ },
		{ key: 'technik', label: 'Technische Störung', test: /stellwerk|weiche|signal|oberleitung|zugfunk|streckenst|technische|störung/ },
		{ key: 'ersatz', label: 'Ersatzverkehr', test: /ersatzverkehr|\bsev\b|\bbus/ },
		{ key: 'umleitung', label: 'Umleitung', test: /umleitung|umgeleitet/ },
		{ key: 'ausfall', label: 'Zugausfall', test: /ausf|zugf|entf[aä]ll|f[aä]llt aus/ },
		{ key: 'verspaetung', label: 'Verspätung', test: /versp[aä]t/ },
		{ key: 'sonstiges', label: 'Sonstiges', test: /[\s\S]*/ }
	];
	var LINES = ['RB61', 'RB71', 'RB60'];

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

	function categorize(title) {
		var t = (title || '').toLowerCase();
		for (var i = 0; i < CATEGORIES.length; i++) {
			if (CATEGORIES[i].test.test(t)) return CATEGORIES[i];
		}
		return CATEGORIES[CATEGORIES.length - 1];
	}

	/** Linien aus dem Titel: nur wenn "RB" vorkommt, dann jede freistehende 60, 61, 71. */
	function linesOf(title) {
		var t = (title || '').toLowerCase();
		if (t.indexOf('rb') === -1) return [];
		var found = [];
		var re = /(^|[^0-9])(60|61|71)(?![0-9])/g;
		var m;
		while ((m = re.exec(t)) !== null) {
			var line = 'RB' + m[2];
			if (found.indexOf(line) === -1) found.push(line);
		}
		return found;
	}

	function prepare(data) {
		var liveById = {};
		(data.eilmeldungen_live || []).forEach(function (e) { if (e && e.id) liveById[e.id] = e; });

		var seen = {};
		var items = [];
		function push(id, title, iso, live) {
			var ms = Date.parse(iso || '');
			if (!id || isNaN(ms)) return;
			seen[id] = true;
			var cat = categorize(title);
			items.push({
				id: id, title: title || '', ms: ms,
				cat: cat.key, catLabel: cat.label, lines: linesOf(title),
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

	function renderStatus() {
		var section = document.getElementById('status');
		if (!section) return;
		Array.prototype.slice.call(section.children).forEach(function (c) { if (c.tagName !== 'H2') section.removeChild(c); });

		var data = state.data;
		var hasData = data && data.received_at;
		var list = hasData ? data.stoerungen || [] : [];
		var ok = hasData && data.quellen && data.quellen.strecken_info_ok;

		var title, cls, icon;
		if (!hasData) {
			cls = 'status--unknown'; icon = '?'; title = 'Noch keine Daten';
		} else if (list.length === 0 && ok) {
			cls = 'status--ok'; icon = '✓'; title = 'Keine Infrastrukturstörung';
		} else if (list.length === 0) {
			cls = 'status--unknown'; icon = '?'; title = 'Infrastrukturstörungen unbekannt';
		} else {
			cls = 'status--alert'; icon = '!'; title = plural(list.length, 'Infrastrukturstörung', 'Infrastrukturstörungen');
		}
		var box = el('div', { class: 'card status ' + cls }, [
			el('div', { class: 'status__icon', 'aria-hidden': 'true', text: icon }),
			el('h3', { class: 'status__title', text: title })
		]);
		alertState.stoerungen = list.length;
		updateBadge();
		section.appendChild(box);

		if (list.length) {
			var ul = el('ul', { class: 'incidents' });
			list.forEach(function (s) { ul.appendChild(renderIncident(s)); });
			section.appendChild(ul);
		}
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
			var chips = el('ul', { class: 'chips' });
			it.lines.forEach(function (l) { chips.appendChild(el('li', { class: 'chip chip--line', text: l })); });
			chips.appendChild(el('li', { class: 'chip', text: it.catLabel }));
			ol.appendChild(el('li', {}, [time, el('div', {}, [el('p', { class: 'feed__title' }, [title]), chips])]));
		});
	}

	/* ---------- Abfahrten ---------- */

	var DEPARTURES_MS = 60 * 1000;

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
			deps.slice(0, 6).forEach(function (d) {
				var late = !d.ausfall && d.verspaetung >= 3;
				var cls = 'dep' + (d.ausfall ? ' dep--cancelled' : late ? ' dep--late' : '');
				var time = el('div', { class: 'dep__time' }, [fmtClock(d.zeit)]);
				if (d.ausfall) time.appendChild(el('small', { text: 'fällt aus' }));
				else if (late) time.appendChild(el('small', { text: '+' + d.verspaetung + ' → ' + fmtClock(d.ist) }));
				var line = el('div', { class: 'dep__line' }, [d.linie]);
				if (d.wagen) line.appendChild(el('small', { text: plural(d.wagen, 'Wagen', 'Wagen') }));
				var dest = el('div', { class: 'dep__dest', title: d.ziel }, [d.ziel]);
				if (d.hinweis) dest.appendChild(el('small', { text: d.hinweis }));
				var where = [d.ab ? 'ab ' + d.ab : '', d.gleis ? 'Gl. ' + d.gleis : ''].filter(Boolean).join(', ');
				var row = el('li', { class: cls }, [
					el('span', { class: 'dep__dot', 'aria-hidden': 'true' }),
					time,
					line,
					dest,
					el('div', { class: 'dep__platform', text: where })
				]);
				var sr = (d.ausfall ? 'Fällt aus: ' : late ? 'Verspätet um ' + d.verspaetung + ' Minuten: ' : '');
				if (sr) row.setAttribute('aria-label', sr + fmtClock(d.zeit) + ' ' + d.linie + ' nach ' + d.ziel + (d.ab ? ' ab ' + d.ab : ''));
				list.appendChild(row);
			});
		});
		alertState.ausfaelle = ausfaelle;
		alertState.verspaetet = verspaetet;
		updateBadge();
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
		var link = document.getElementById('favicon');
		if (!link) return;
		var url = faviconUrl(level);
		if (url && link.href !== url) {
			link.type = 'image/png';
			link.href = url;
		}
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
			renderDepartures(null);
			refreshDepartures();
			setInterval(refreshDepartures, DEPARTURES_MS);
		}
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); refreshDepartures(); } });
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
