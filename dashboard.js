/*
 * Nordbahn Monitor: baut aus dem eingebetteten JSON (siehe index.php) das
 * Dashboard und holt alle fuenf Minuten frische Daten von api/data.
 *
 * Alle Texte aus den Daten (Titel, Meldungstexte, Bahnhofsnamen) stammen von
 * fremden Seiten und landen deshalb ausschliesslich per textContent im DOM.
 * Zeiten werden in Europe/Berlin gerechnet, egal wo der Browser steht.
 */
(function () {
	'use strict';

	var TZ = 'Europe/Berlin';
	var DAY = 86400000;
	var REFRESH_MS = 5 * 60 * 1000;
	var STALE_MS = 20 * 60 * 1000;
	var WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
	var MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

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
		items: [],       // aufbereitete Eilmeldungen
		incidents: [],   // aufbereiteter Stoerungsverlauf
		range: '91'
	};

	/* ---------- Zeit-Helfer ---------- */

	var partsFormatter = new Intl.DateTimeFormat('de-DE', {
		timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false
	});

	/** Jahr, Monat, Tag, Stunde eines Zeitpunkts in deutscher Zeit, dazu der Montag der Woche (UTC-Mitternacht). */
	function berlinParts(ms) {
		var parts = {};
		partsFormatter.formatToParts(new Date(ms)).forEach(function (p) { parts[p.type] = p.value; });
		var y = +parts.year, m = +parts.month, d = +parts.day, h = +parts.hour % 24;
		var dayUtc = Date.UTC(y, m - 1, d);
		var wd = (new Date(dayUtc).getUTCDay() + 6) % 7; // 0 = Montag
		return { y: y, m: m, d: d, h: h, wd: wd, dayUtc: dayUtc, weekStart: dayUtc - wd * DAY };
	}

	/** ISO-Kalenderwoche fuer einen Montag (UTC-Mitternacht). */
	function isoWeek(weekStart) {
		var thu = new Date(weekStart + 3 * DAY);
		var year = thu.getUTCFullYear();
		var firstThu = new Date(Date.UTC(year, 0, 4));
		firstThu = new Date(firstThu.getTime() - ((firstThu.getUTCDay() + 6) % 7) * DAY + 3 * DAY);
		return { year: year, week: 1 + Math.round((thu - firstThu) / (7 * DAY)) };
	}

	function fmtDayShort(dayUtc) {
		var dt = new Date(dayUtc);
		return dt.getUTCDate() + '. ' + MONTHS[dt.getUTCMonth()];
	}

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
				id: id, title: title || '', ms: ms, parts: berlinParts(ms),
				cat: cat.key, catLabel: cat.label, lines: linesOf(title),
				stand: live && live.stand ? Date.parse(live.stand) : NaN,
				link: live && live.link ? live.link : ''
			});
		}
		(data.eilmeldungen || []).forEach(function (e) { if (e) push(e.id, e.title, e.seen_at, liveById[e.id]); });
		// Meldungen, die auf der Seite stehen, aber noch nicht in der Historie sind
		(data.eilmeldungen_live || []).forEach(function (e) { if (e && !seen[e.id]) push(e.id, e.title, e.stand, e); });
		items.sort(function (a, b) { return a.ms - b.ms; });

		var incidents = [];
		(data.stoerungen_verlauf || []).forEach(function (s) {
			var ms = Date.parse(s.first_seen || '');
			if (isNaN(ms)) return;
			incidents.push({ key: s.key, titel: s.titel || '', ms: ms, parts: berlinParts(ms) });
		});
		incidents.sort(function (a, b) { return a.ms - b.ms; });

		state.items = items;
		state.incidents = incidents;
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

		var box;
		if (!hasData) {
			box = el('div', { class: 'card status status--unknown' }, [
				el('div', { class: 'status__icon', 'aria-hidden': 'true', text: '?' }),
				el('div', {}, [
					el('p', { class: 'status__kicker', text: 'Jetzt auf der Strecke' }),
					el('h3', { class: 'status__title', text: 'Noch keine Daten' })
				])
			]);
		} else if (list.length === 0) {
			box = el('div', { class: 'card status ' + (ok ? 'status--ok' : 'status--unknown') }, [
				el('div', { class: 'status__icon', 'aria-hidden': 'true', text: ok ? '✓' : '?' }),
				el('div', {}, [
					el('p', { class: 'status__kicker', text: 'Jetzt auf der Strecke' }),
					el('h3', { class: 'status__title', text: ok ? 'Keine Störung' : 'Störungslage unbekannt' }),
					el('p', { class: 'status__sub', text: ok ? 'Hamburg – Elmshorn – Itzehoe/Wrist, alles normal.' : 'Gerade keine aktuellen Daten.' })
				])
			]);
		} else {
			box = el('div', { class: 'card status status--alert' }, [
				el('div', { class: 'status__icon', 'aria-hidden': 'true', text: '!' }),
				el('div', {}, [
					el('p', { class: 'status__kicker', text: 'Jetzt auf der Strecke' }),
					el('h3', { class: 'status__title', text: plural(list.length, 'aktive Störung', 'aktive Störungen') }),
					el('p', { class: 'status__sub', text: 'Hamburg – Elmshorn – Itzehoe/Wrist' })
				])
			]);
		}
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

	/* ---------- Kacheln ---------- */

	function countSince(items, fromMs, toMs) {
		var n = 0;
		for (var i = 0; i < items.length; i++) {
			if (items[i].ms >= fromMs && items[i].ms < toMs) n++;
		}
		return n;
	}

	function deltaNode(now, before, label) {
		var diff = now - before;
		var cls = diff > 0 ? 'up' : diff < 0 ? 'down' : 'flat';
		var sign = diff > 0 ? '▲ +' + diff : diff < 0 ? '▼ ' + diff : '= ±0';
		return el('p', { class: 'tile__delta' }, [el('span', { class: cls, text: sign }), ' ' + label]);
	}

	function renderTiles() {
		var box = document.getElementById('tiles');
		if (!box) return;
		clear(box);
		var items = state.items;
		var now = Date.now();
		var w7 = countSince(items, now - 7 * DAY, now);
		var w7prev = countSince(items, now - 14 * DAY, now - 7 * DAY);
		var d30 = countSince(items, now - 30 * DAY, now);
		var d30prev = countSince(items, now - 60 * DAY, now - 30 * DAY);

		var perWeek = null;
		if (items.length) {
			var weeks = Math.max(1, (now - items[0].ms) / (7 * DAY));
			perWeek = items.length / weeks;
		}
		var last = items.length ? items[items.length - 1] : null;

		box.appendChild(el('div', { class: 'card tile' }, [
			el('p', { class: 'tile__label', text: 'Letzte 7 Tage' }),
			el('p', { class: 'tile__value', text: String(w7) }),
			deltaNode(w7, w7prev, 'zur Vorwoche')
		]));
		box.appendChild(el('div', { class: 'card tile' }, [
			el('p', { class: 'tile__label', text: 'Letzte 30 Tage' }),
			el('p', { class: 'tile__value', text: String(d30) }),
			deltaNode(d30, d30prev, 'zu den 30 Tagen davor')
		]));
		box.appendChild(el('div', { class: 'card tile' }, [
			el('p', { class: 'tile__label', text: 'Im Schnitt pro Woche' }),
			el('p', { class: 'tile__value' }, [perWeek === null ? '–' : perWeek.toFixed(1).replace('.', ',')]),
			el('p', { class: 'tile__sub', text: items.length
				? plural(items.length, 'Eilmeldung', 'Eilmeldungen') + ' seit ' + fmtDayShort(items[0].parts.dayUtc) + ' ' + items[0].parts.y
				: 'Noch keine Historie' })
		]));
		box.appendChild(el('div', { class: 'card tile' }, [
			el('p', { class: 'tile__label', text: 'Letzte Eilmeldung' }),
			el('p', { class: 'tile__value', text: last ? relTime(last.ms) : '–' }),
			el('p', { class: 'tile__sub', text: last ? last.title : '' , title: last ? last.title : '' })
		]));
	}

	/* ---------- Feed ---------- */

	function renderFeed() {
		var ol = document.getElementById('feed');
		if (!ol) return;
		clear(ol);
		ol.className = 'feed card';
		var limit = parseInt(ol.getAttribute('data-limit'), 10) || 12;
		var items = state.items.slice(-limit).reverse();
		if (!items.length) {
			ol.appendChild(el('li', {}, [el('p', { class: 'feed__empty', text: 'Noch keine Eilmeldungen erfasst.' })]));
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

	/* ---------- Auswertung ---------- */

	function rangeStart() {
		var now = Date.now();
		if (state.range === 'all') {
			var first = Infinity;
			if (state.items.length) first = Math.min(first, state.items[0].ms);
			if (state.incidents.length) first = Math.min(first, state.incidents[0].ms);
			return first === Infinity ? now - 28 * DAY : first;
		}
		return now - parseInt(state.range, 10) * DAY;
	}

	function inRange(list, start) {
		return list.filter(function (x) { return x.ms >= start; });
	}

	/** Zaehlt pro Kalenderwoche von der Startwoche bis zur aktuellen Woche, auch leere Wochen. */
	function weeklySeries(list, start) {
		var first = berlinParts(start).weekStart;
		var last = berlinParts(Date.now()).weekStart;
		var counts = {};
		list.forEach(function (x) { counts[x.parts.weekStart] = (counts[x.parts.weekStart] || 0) + 1; });
		var out = [];
		for (var w = first; w <= last; w += 7 * DAY) {
			var iso = isoWeek(w);
			out.push({
				label: 'KW ' + iso.week,
				value: counts[w] || 0,
				tipLabel: 'KW ' + iso.week + ' · ' + fmtDayShort(w) + ' – ' + fmtDayShort(w + 6 * DAY)
			});
		}
		return out;
	}

	function countBy(list, keyFn, keys, labelFn) {
		var counts = {};
		list.forEach(function (x) {
			var ks = keyFn(x);
			(Array.isArray(ks) ? ks : [ks]).forEach(function (k) { counts[k] = (counts[k] || 0) + 1; });
		});
		return keys.map(function (k) { return { key: k, label: labelFn(k), value: counts[k] || 0 }; });
	}

	function renderAnalysis() {
		var box = document.getElementById('charts');
		if (!box) return;
		clear(box);
		var start = rangeStart();
		var items = inRange(state.items, start);
		var incidents = inRange(state.incidents, start);
		var noun = plural(items.length, 'Eilmeldung', 'Eilmeldungen');

		var sub = document.getElementById('analysis-sub');
		if (sub) sub.textContent = state.items.length
			? noun + ' im Zeitraum seit ' + fmtDayShort(berlinParts(start).dayUtc) + ' ' + berlinParts(start).y
			: 'Noch keine Historie vorhanden.';

		// 1. Eilmeldungen pro Woche
		box.appendChild(chartCard({
			title: 'Eilmeldungen pro Woche',
			sub: 'Nach Kalenderwoche, in der die Nordbahn die Meldung veröffentlicht hat.',
			wide: true,
			unit: ['Eilmeldung', 'Eilmeldungen'],
			columns: weeklySeries(items, start)
		}));

		// 2. Nach Kategorie
		var cats = countBy(items, function (x) { return x.cat; }, CATEGORIES.map(function (c) { return c.key; }), function (k) {
			return CATEGORIES.filter(function (c) { return c.key === k; })[0].label;
		}).filter(function (r) { return r.value > 0; }).sort(function (a, b) { return b.value - a.value; });
		box.appendChild(chartCard({
			title: 'Worum es ging',
			sub: 'Aus dem Titel der Meldung abgeleitet, eine Kategorie pro Meldung.',
			unit: ['Eilmeldung', 'Eilmeldungen'],
			bars: cats
		}));

		// 3. Nach Linie
		var lines = countBy(items, function (x) { return x.lines.length ? x.lines : ['ohne']; }, LINES.concat(['ohne']), function (k) {
			return k === 'ohne' ? 'Ohne Linienangabe' : k;
		}).filter(function (r) { return r.value > 0; });
		box.appendChild(chartCard({
			title: 'Welche Linie betroffen war',
			sub: 'Nennungen im Titel; eine Meldung kann mehrere Linien betreffen.',
			unit: ['Nennung', 'Nennungen'],
			bars: lines
		}));

		// 4. Nach Wochentag
		box.appendChild(chartCard({
			title: 'Nach Wochentag',
			sub: 'An welchem Tag die Meldung erschien.',
			unit: ['Eilmeldung', 'Eilmeldungen'],
			columns: countBy(items, function (x) { return x.parts.wd; }, [0, 1, 2, 3, 4, 5, 6], function (k) { return WEEKDAYS[k]; })
		}));

		// 5. Nach Uhrzeit
		var hours = [];
		for (var h = 0; h < 24; h++) hours.push(h);
		box.appendChild(chartCard({
			title: 'Nach Uhrzeit',
			sub: 'Stunde, in der die Meldung erschien (deutsche Zeit).',
			unit: ['Eilmeldung', 'Eilmeldungen'],
			labelEvery: 3,
			columns: countBy(items, function (x) { return x.parts.h; }, hours, function (k) { return k + ' Uhr'; }).map(function (r) {
				r.tipLabel = r.label + ' bis ' + ((r.key + 1) % 24) + ' Uhr';
				r.label = String(r.key);
				return r;
			})
		}));

		// 6. Stoerungen pro Woche (eigenes Logbuch, waechst erst mit der Zeit)
		var incidentCard = state.incidents.length
			? chartCard({
				title: 'Störungen pro Woche',
				sub: plural(incidents.length, 'Störung', 'Störungen') + ' im Zeitraum, erfasst seit Oktober 2026.',
				wide: true,
				unit: ['Störung', 'Störungen'],
				columns: weeklySeries(incidents, start)
			})
			: chartCard({
				title: 'Störungen pro Woche',
				sub: 'Erfasst seit Oktober 2026.',
				wide: true,
				empty: 'Noch keine Störung erfasst.'
			});
		box.appendChild(incidentCard);

		redrawCharts();
	}

	function redrawCharts() {
		Array.prototype.forEach.call(document.querySelectorAll('.chart'), function (c) { if (c._redraw) c._redraw(); });
	}

	/* ---------- Diagramm-Karte ---------- */

	function chartCard(spec) {
		var plot = el('div', { class: 'chart__plot' });
		var table = el('table', { hidden: '' });
		var toggle = el('button', { type: 'button', class: 'chart__toggle', 'aria-pressed': 'false', text: 'Tabelle' });
		var tip = el('div', { class: 'tip', role: 'status' });
		var card = el('figure', { class: 'card chart' + (spec.wide ? ' chart--wide' : '') }, [
			el('figcaption', { class: 'chart__head' }, [
				el('div', {}, [el('h3', { class: 'chart__title', text: spec.title }), spec.sub ? el('p', { class: 'chart__sub', text: spec.sub }) : null]),
				spec.empty ? null : toggle
			]),
			plot, table, tip
		]);

		var rows = spec.columns || spec.bars || [];
		if (spec.empty || !rows.length) {
			plot.appendChild(el('p', { class: 'chart__empty', text: spec.empty || 'Keine Daten im Zeitraum.' }));
			return card;
		}

		// Tabellen-Zwilling: dieselben Zahlen ohne Grafik
		var thead = el('thead', {}, [el('tr', {}, [el('th', { text: spec.columns ? 'Zeitraum' : 'Kategorie' }), el('th', { text: 'Anzahl' })])]);
		var tbody = el('tbody');
		rows.forEach(function (r) {
			tbody.appendChild(el('tr', {}, [el('td', { text: r.tipLabel || r.label }), el('td', { text: String(r.value) })]));
		});
		table.appendChild(thead);
		table.appendChild(tbody);

		toggle.addEventListener('click', function () {
			var on = toggle.getAttribute('aria-pressed') !== 'true';
			toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
			toggle.textContent = on ? 'Diagramm' : 'Tabelle';
			table.hidden = !on;
			plot.hidden = on;
		});

		var unit = spec.unit || ['', ''];
		function tipText(r) {
			return { value: plural(r.value, unit[0], unit[1]), label: r.tipLabel || r.label };
		}

		if (spec.columns) {
			// Erst zeichnen, wenn die Karte im DOM haengt und ihre Breite kennt (siehe renderAnalysis).
			card._redraw = function () { drawColumns(plot, spec.columns, spec.labelEvery, tip, tipText); };
		} else {
			drawBars(plot, spec.bars, tip, tipText);
		}
		return card;
	}

	function showTip(tip, card, content, x, y) {
		clear(tip);
		tip.appendChild(el('strong', { text: content.value }));
		tip.appendChild(document.createTextNode(content.label));
		tip.classList.add('is-on');
		var cw = card.clientWidth;
		var tw = tip.offsetWidth;
		var left = Math.max(8, Math.min(cw - tw - 8, x - tw / 2));
		tip.style.left = left + 'px';
		tip.style.top = Math.max(4, y - tip.offsetHeight - 10) + 'px';
	}

	function hideTip(tip) {
		tip.classList.remove('is-on');
	}

	function niceMax(max) {
		if (max <= 4) return 4;
		var steps = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500];
		for (var i = 0; i < steps.length; i++) {
			var step = steps[i];
			if (max <= step * 4) return Math.ceil(max / step) * step;
		}
		return max;
	}

	/** Saeulendiagramm in SVG: eine Datenreihe, Hairline-Gitter, 4px gerundete Spitze, Wert nur am Maximum. */
	function drawColumns(plot, rows, labelEvery, tip, tipText) {
		clear(plot);
		var card = plot.parentNode;
		var W = Math.max(240, plot.clientWidth || card.clientWidth - 36);
		var H = 190;
		var pad = { top: 18, right: 6, bottom: 24, left: 26 };
		var plotW = W - pad.left - pad.right;
		var plotH = H - pad.top - pad.bottom;
		var n = rows.length;
		var max = 0;
		rows.forEach(function (r) { if (r.value > max) max = r.value; });
		var yMax = niceMax(max);
		var slot = plotW / n;
		var bw = Math.min(24, Math.max(2, slot - 2));
		var every = labelEvery || (n <= 8 ? 1 : n <= 16 ? 2 : n <= 32 ? 4 : 8);

		var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img' });
		svg.setAttribute('aria-label', rows.map(function (r) { return (r.tipLabel || r.label) + ': ' + r.value; }).join(', '));

		var ticks = 4;
		for (var t = 0; t <= ticks; t++) {
			var v = yMax * t / ticks;
			var y = pad.top + plotH - plotH * t / ticks;
			svg.appendChild(svgEl('line', { class: t === 0 ? 'axis' : 'grid', x1: pad.left, x2: W - pad.right, y1: y, y2: y }));
			if (t > 0) svg.appendChild(svgEl('text', { class: 'tick', x: pad.left - 6, y: y + 4, 'text-anchor': 'end', text: String(Math.round(v)) }));
		}

		var maxLabeled = false;
		rows.forEach(function (r, i) {
			var x = pad.left + i * slot + (slot - bw) / 2;
			var h = yMax ? plotH * r.value / yMax : 0;
			var y = pad.top + plotH - h;
			var hit = svgEl('rect', { class: 'hit', x: pad.left + i * slot, y: pad.top, width: slot, height: plotH, tabindex: '0' });
			svg.appendChild(hit);
			if (h > 0) {
				var rad = Math.min(4, bw / 2, h);
				var d = 'M' + x + ',' + (y + h) + ' L' + x + ',' + (y + rad) + ' Q' + x + ',' + y + ' ' + (x + rad) + ',' + y +
					' L' + (x + bw - rad) + ',' + y + ' Q' + (x + bw) + ',' + y + ' ' + (x + bw) + ',' + (y + rad) +
					' L' + (x + bw) + ',' + (y + h) + ' Z';
				var bar = svgEl('path', { class: 'bar', d: d });
				svg.appendChild(bar);
				hit._bar = bar;
			}
			if (r.value === max && max > 0 && !maxLabeled) {
				maxLabeled = true;
				svg.appendChild(svgEl('text', { class: 'label', x: x + bw / 2, y: y - 5, 'text-anchor': 'middle', text: String(r.value) }));
			}
			if (i % every === 0) {
				svg.appendChild(svgEl('text', { x: pad.left + i * slot + slot / 2, y: H - 7, 'text-anchor': 'middle', text: r.label }));
			}
			var content = tipText(r);
			function on(evt) {
				if (hit._bar) hit._bar.classList.add('is-hover');
				var rect = card.getBoundingClientRect();
				var px, py;
				if (evt && evt.clientX !== undefined && evt.type !== 'focus') {
					px = evt.clientX - rect.left;
					py = evt.clientY - rect.top;
				} else {
					var b = hit.getBoundingClientRect();
					px = b.left - rect.left + b.width / 2;
					py = b.top - rect.top + (h > 0 ? plotH - h : plotH / 2);
				}
				showTip(tip, card, content, px, py);
			}
			function off() {
				if (hit._bar) hit._bar.classList.remove('is-hover');
				hideTip(tip);
			}
			hit.addEventListener('pointerenter', on);
			hit.addEventListener('pointermove', on);
			hit.addEventListener('pointerleave', off);
			hit.addEventListener('focus', on);
			hit.addEventListener('blur', off);
		});

		plot.appendChild(svg);
	}

	/** Horizontale Balken in HTML: Label, Balken, Wert an der Spitze. */
	function drawBars(plot, rows, tip, tipText) {
		clear(plot);
		var card = plot.parentNode;
		var max = 0;
		rows.forEach(function (r) { if (r.value > max) max = r.value; });
		var list = el('div', { class: 'hbars', role: 'list' });
		rows.forEach(function (r) {
			var fill = el('div', { class: 'hbar__fill' });
			fill.style.width = (max ? (100 * r.value / max) : 0) + '%';
			var track = el('div', { class: 'hbar__track', tabindex: '0', role: 'img', 'aria-label': r.label + ': ' + r.value }, [fill]);
			var row = el('div', { class: 'hbar', role: 'listitem' }, [
				el('div', { class: 'hbar__label', text: r.label, title: r.label }),
				track,
				el('div', { class: 'hbar__value', text: String(r.value) })
			]);
			var content = tipText(r);
			function on(evt) {
				var rect = card.getBoundingClientRect();
				var b = track.getBoundingClientRect();
				var px = evt && evt.clientX !== undefined && evt.type !== 'focus' ? evt.clientX - rect.left : b.left - rect.left + b.width * (max ? r.value / max : 0) / 2;
				showTip(tip, card, content, px, b.top - rect.top);
			}
			function off() { hideTip(tip); }
			track.addEventListener('pointerenter', on);
			track.addEventListener('pointermove', on);
			track.addEventListener('pointerleave', off);
			track.addEventListener('focus', on);
			track.addEventListener('blur', off);
			list.appendChild(row);
		});
		plot.appendChild(list);
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

		Array.prototype.forEach.call(boards, function (b) {
			var key = b.getAttribute('data-richtung');
			var list = b.querySelector('.board__list');
			var deps = (result.richtungen && result.richtungen[key]) || [];
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

	/* ---------- Steuerung ---------- */

	function renderAll() {
		renderFreshness();
		renderStatus();
		renderTiles();
		renderFeed();
		renderAnalysis();
	}

	function setData(data) {
		state.data = data || {};
		prepare(state.data);
		renderAll();
	}

	function initRange() {
		var group = document.getElementById('range');
		if (!group) return;
		group.addEventListener('click', function (evt) {
			var btn = evt.target.closest('button[data-range]');
			if (!btn) return;
			state.range = btn.getAttribute('data-range');
			Array.prototype.forEach.call(group.querySelectorAll('button'), function (b) {
				b.setAttribute('aria-checked', b === btn ? 'true' : 'false');
			});
			renderAnalysis();
		});
	}

	var resizeTimer = null;
	window.addEventListener('resize', function () {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(redrawCharts, 150);
	});

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
		initRange();
		setData(data);
		setInterval(refresh, REFRESH_MS);
		setInterval(renderFreshness, 60000);
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
