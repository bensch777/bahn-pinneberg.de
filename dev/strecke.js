#!/usr/bin/env node
/**
 * Streckenverlauf fuer die Live-Karte aus OpenStreetMap:
 *
 *   node dev/strecke.js            Gleise von Overpass holen, karte/strecke.json neu schreiben
 *   node dev/strecke.js osm.json   dieselbe Rechnung mit einer gespeicherten Overpass-Antwort
 *
 * Holt alle Gleise (railway=rail) im Kasten Hamburg - Itzehoe - Wrist, baut daraus
 * einen Graphen (Wege teilen sich Knoten an Weichen) und sucht fuer jede Kante aus
 * karte/strecke.json den kuerzesten Weg ueber die Schienen zwischen ihren beiden
 * Bahnhoefen (Dijkstra). Nebengleise (service=*) und S-Bahn-Gleise mit Stromschiene
 * (electrified=rail) kosten mehr, damit der Weg auf der Fernbahn bleibt. Der Abzweig
 * "J" (Altona-Nord) wird nicht geraten, sondern als der Punkt bestimmt, an dem sich
 * die Wege Dammtor - Pinneberg und Altona - Pinneberg vereinigen.
 *
 * Ergebnis: die 'zwischen'-Stuetzpunkte jeder Kante (vereinfacht auf TOLERANZ Meter),
 * die Koordinate von J und ein '_quelle'-Vermerk. Bahnhoefe, Linien und Ansichten
 * bleiben, wie sie sind. Geometrie aus OpenStreetMap steht unter ODbL; die Seite
 * nennt OpenStreetMap als Quelle. Ohne Fremdpakete.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');

const DATEI = path.join(__dirname, '..', 'karte', 'strecke.json');
const OVERPASS = 'https://overpass-api.de/api/interpreter';
const RAND = 0.03;        // Grad Rand um die Bahnhoefe fuer den Abfragekasten
const TOLERANZ = 4;       // Meter: Douglas-Peucker fuer die Stuetzpunkte
const SNAP_MAX = 400;     // Meter: so weit darf der naechste Gleisknoten vom Bahnhof weg sein

/* ---------- Geometrie ---------- */

function dist(a, b) {
	const r = 6371000, la1 = a[0] * Math.PI / 180, la2 = b[0] * Math.PI / 180;
	const dla = la2 - la1, dlo = (b[1] - a[1]) * Math.PI / 180;
	const h = Math.sin(dla / 2) ** 2 + Math.cos(la1) * Math.cos(la2) * Math.sin(dlo / 2) ** 2;
	return 2 * r * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Abstand eines Punkts von der Strecke a-b in Metern (lokal eben gerechnet). */
function abstandVonStrecke(p, a, b) {
	const k = Math.cos(a[0] * Math.PI / 180);
	const x = (lat, lon) => [(lon - a[1]) * k * 111320, (lat - a[0]) * 111320];
	const P = x(p[0], p[1]), B = x(b[0], b[1]);
	const l2 = B[0] * B[0] + B[1] * B[1];
	let t = l2 > 0 ? (P[0] * B[0] + P[1] * B[1]) / l2 : 0;
	t = Math.max(0, Math.min(1, t));
	const dx = P[0] - t * B[0], dy = P[1] - t * B[1];
	return Math.sqrt(dx * dx + dy * dy);
}

/** Douglas-Peucker: Punkte, die weniger als toleranz Meter von der Verbindung abweichen, fallen weg. */
function vereinfachen(punkte, toleranz) {
	if (punkte.length <= 2) return punkte;
	let max = 0, idx = 0;
	for (let i = 1; i < punkte.length - 1; i++) {
		const d = abstandVonStrecke(punkte[i], punkte[0], punkte[punkte.length - 1]);
		if (d > max) { max = d; idx = i; }
	}
	if (max <= toleranz) return [punkte[0], punkte[punkte.length - 1]];
	const links = vereinfachen(punkte.slice(0, idx + 1), toleranz);
	const rechts = vereinfachen(punkte.slice(idx), toleranz);
	return links.slice(0, -1).concat(rechts);
}

/* ---------- Overpass ---------- */

function kasten(stationen) {
	let s = 90, w = 180, n = -90, o = -180;
	for (const id of Object.keys(stationen)) {
		const st = stationen[id];
		s = Math.min(s, st.lat); n = Math.max(n, st.lat); w = Math.min(w, st.lon); o = Math.max(o, st.lon);
	}
	return [s - RAND, w - RAND, n + RAND, o + RAND].map(v => v.toFixed(4)).join(',');
}

function overpassHolen(bbox) {
	const query = `[out:json][timeout:180];way["railway"="rail"](${bbox});out geom;`;
	const body = 'data=' + encodeURIComponent(query);
	return new Promise((resolve, reject) => {
		const req = https.request(OVERPASS, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'User-Agent': 'bahn-pinneberg.de dev/strecke.js' },
		}, res => {
			let text = '';
			res.setEncoding('utf8');
			res.on('data', c => { text += c; });
			res.on('end', () => {
				if (res.statusCode !== 200) return reject(new Error('Overpass antwortet mit HTTP ' + res.statusCode + ': ' + text.slice(0, 300)));
				try { resolve(JSON.parse(text)); } catch (e) { reject(new Error('Overpass-Antwort ist kein JSON: ' + text.slice(0, 300))); }
			});
		});
		req.on('error', reject);
		req.write(body);
		req.end();
	});
}

/* ---------- Graph und Wegsuche ---------- */

/** Gewicht eines Gleises je Meter: Nebengleise und S-Bahn-Gleise sind "teurer". */
function faktor(tags) {
	let f = 1;
	if (tags.service) f *= 4;
	if (tags.electrified === 'rail') f *= 3;
	if (tags.usage && tags.usage !== 'main' && tags.usage !== 'branch') f *= 2;
	if (tags.railway !== 'rail') f *= 10;
	return f;
}

/** Knoten (id -> [lat, lon]) und Nachbarn (id -> [{zu, kosten}]) aus den Overpass-Wegen. */
function graphBauen(osm) {
	const knoten = new Map();
	const nachbarn = new Map();
	const kante = (a, b, kosten) => {
		if (!nachbarn.has(a)) nachbarn.set(a, []);
		nachbarn.get(a).push({ zu: b, kosten });
	};
	let wege = 0;
	for (const el of osm.elements || []) {
		if (el.type !== 'way' || !Array.isArray(el.nodes) || !Array.isArray(el.geometry) || el.nodes.length !== el.geometry.length) continue;
		const f = faktor(el.tags || {});
		wege++;
		for (let i = 0; i < el.nodes.length; i++) {
			const g = el.geometry[i];
			knoten.set(el.nodes[i], [g.lat, g.lon]);
		}
		for (let i = 1; i < el.nodes.length; i++) {
			const a = el.nodes[i - 1], b = el.nodes[i];
			const d = dist(knoten.get(a), knoten.get(b)) * f;
			kante(a, b, d);
			kante(b, a, d);
		}
	}
	return { knoten, nachbarn, wege };
}

/** Naechster Gleisknoten zu einem Punkt (nur Knoten mit mindestens einer Kante). */
function naechsterKnoten(graph, punkt) {
	let best = null, bestD = Infinity;
	for (const [id, p] of graph.knoten) {
		if (!graph.nachbarn.has(id)) continue;
		const d = dist(p, punkt);
		if (d < bestD) { bestD = d; best = id; }
	}
	return { id: best, abstand: bestD };
}

/** Dijkstra mit einfacher Prioritaetsliste (Binaerhaufen). */
function kuerzesterWeg(graph, start, ziel) {
	const abstand = new Map([[start, 0]]);
	const vorgaenger = new Map();
	const heap = [[0, start]];
	const push = (e) => { heap.push(e); let i = heap.length - 1; while (i > 0) { const p = (i - 1) >> 1; if (heap[p][0] <= heap[i][0]) break; [heap[p], heap[i]] = [heap[i], heap[p]]; i = p; } };
	const pop = () => { const top = heap[0]; const last = heap.pop(); if (heap.length) { heap[0] = last; let i = 0; for (;;) { const l = 2 * i + 1, r = l + 1; let m = i; if (l < heap.length && heap[l][0] < heap[m][0]) m = l; if (r < heap.length && heap[r][0] < heap[m][0]) m = r; if (m === i) break; [heap[m], heap[i]] = [heap[i], heap[m]]; i = m; } } return top; };
	const fertig = new Set();
	while (heap.length) {
		const [d, n] = pop();
		if (fertig.has(n)) continue;
		fertig.add(n);
		if (n === ziel) break;
		for (const { zu, kosten } of graph.nachbarn.get(n) || []) {
			const nd = d + kosten;
			if (nd < (abstand.has(zu) ? abstand.get(zu) : Infinity)) {
				abstand.set(zu, nd);
				vorgaenger.set(zu, n);
				push([nd, zu]);
			}
		}
	}
	if (!abstand.has(ziel)) return null;
	const weg = [];
	for (let n = ziel; n !== undefined; n = vorgaenger.get(n)) weg.unshift(n);
	return weg;
}

/* ---------- Rechnung ---------- */

/**
 * Schleifen kappen: Liegt der naechste Gleisknoten eines Bahnhofs auf dem Nachbargleis, fuehrt
 * der kuerzeste Weg am Bahnhof vorbei bis zur naechsten Weiche und zurueck. Dann endet der Weg
 * schon dort, wo er dem Bahnhof am naechsten kommt (ebenso am Anfang). $punkte: [lat, lon][].
 */
function schleifenKappen(punkte, von, bis) {
	const laenge = (liste) => { let l = 0; for (let i = 1; i < liste.length; i++) l += dist(liste[i - 1], liste[i]); return l; };
	const naechster = (ziel) => { let idx = 0, best = Infinity; punkte.forEach((p, i) => { const d = dist(p, ziel); if (d < best) { best = d; idx = i; } }); return { idx, abstand: best }; };
	const ende = naechster(bis);
	if (ende.idx < punkte.length - 1 && laenge(punkte.slice(ende.idx)) > 2 * ende.abstand + 50) punkte = punkte.slice(0, ende.idx + 1);
	const anfang = naechster(von);
	if (anfang.idx > 0 && laenge(punkte.slice(0, anfang.idx + 1)) > 2 * anfang.abstand + 50) punkte = punkte.slice(anfang.idx);
	return punkte;
}

/** Weg zwischen zwei Bahnhoefen als Knotenliste, oder null mit Grund. */
function wegZwischen(graph, stationen, von, bis) {
	const a = naechsterKnoten(graph, [stationen[von].lat, stationen[von].lon]);
	const b = naechsterKnoten(graph, [stationen[bis].lat, stationen[bis].lon]);
	if (a.id === null || b.id === null) return { fehler: 'kein Gleis gefunden' };
	if (a.abstand > SNAP_MAX || b.abstand > SNAP_MAX) return { fehler: `Gleis zu weit vom Bahnhof weg (${Math.round(a.abstand)} m / ${Math.round(b.abstand)} m)` };
	const weg = kuerzesterWeg(graph, a.id, b.id);
	if (!weg) return { fehler: 'kein zusammenhaengender Weg ueber die Gleise' };
	return { weg };
}

/** Knotenliste zu vereinfachten Stuetzpunkten ohne die Endpunkte (die Bahnhoefe selbst). */
function zwischenpunkte(graph, weg, von, bis) {
	const punkte = schleifenKappen(weg.map(id => graph.knoten.get(id)), von, bis);
	const voll = [von].concat(punkte, [bis]);
	const einfach = vereinfachen(voll, TOLERANZ);
	return einfach.slice(1, -1).map(p => [Number(p[0].toFixed(5)), Number(p[1].toFixed(5))]);
}

/**
 * Rechnet alle Kanten. Kanten an J (Dammtor - J, Altona - J, J - Pinneberg) entstehen aus den
 * Wegen Dammtor - Pinneberg und Altona - Pinneberg: J ist der erste gemeinsame Knoten, vom
 * Pinneberger Ende her gesehen. Gibt die neue Strecke und ein Protokoll zurueck.
 */
function rechnen(strecke, osm) {
	const graph = graphBauen(osm);
	const protokoll = [`${graph.wege} Gleiswege, ${graph.knoten.size} Knoten`];
	const stationen = strecke.stationen;
	const neu = JSON.parse(JSON.stringify(strecke));
	const kanten = neu.kanten;
	const punkt = id => [stationen[id].lat, stationen[id].lon];
	const setzen = (von, bis, zwischen, info) => {
		const k = kanten.find(k => (k.von === von && k.bis === bis) || (k.von === bis && k.bis === von));
		if (!k) { protokoll.push(`Kante ${von} - ${bis} fehlt in strecke.json`); return; }
		k.zwischen = k.von === von ? zwischen : zwischen.slice().reverse();
		protokoll.push(`${von} - ${bis}: ${zwischen.length} Stuetzpunkte${info ? ', ' + info : ''}`);
	};

	// Abzweig J aus den beiden Wegen nach Pinneberg
	const anJ = kanten.filter(k => k.von === 'J' || k.bis === 'J').map(k => (k.von === 'J' ? k.bis : k.von));
	if (stationen.J && anJ.length === 3 && anJ.includes('AP')) {
		const andere = anJ.filter(id => id !== 'AP');
		const w1 = wegZwischen(graph, stationen, andere[0], 'AP');
		const w2 = wegZwischen(graph, stationen, andere[1], 'AP');
		if (w1.weg && w2.weg) {
			// gemeinsames Ende der beiden Wege (beide enden am Pinneberger Knoten)
			let i = w1.weg.length - 1, j = w2.weg.length - 1;
			while (i > 0 && j > 0 && w1.weg[i - 1] === w2.weg[j - 1]) { i--; j--; }
			const jId = w1.weg[i];
			const jPunkt = graph.knoten.get(jId);
			neu.stationen.J.lat = Number(jPunkt[0].toFixed(5));
			neu.stationen.J.lon = Number(jPunkt[1].toFixed(5));
			protokoll.push(`J liegt bei ${neu.stationen.J.lat}, ${neu.stationen.J.lon} (${Math.round(dist(jPunkt, punkt('J')))} m vom bisherigen Wert)`);
			const jp = [neu.stationen.J.lat, neu.stationen.J.lon];
			setzen(andere[0], 'J', zwischenpunkte(graph, w1.weg.slice(0, i + 1), punkt(andere[0]), jp));
			setzen(andere[1], 'J', zwischenpunkte(graph, w2.weg.slice(0, j + 1), punkt(andere[1]), jp));
			setzen('J', 'AP', zwischenpunkte(graph, w1.weg.slice(i), jp, punkt('AP')));
		} else {
			protokoll.push(`Abzweig J: ${w1.fehler || w2.fehler}`);
		}
	}

	for (const k of kanten) {
		if (k.von === 'J' || k.bis === 'J') continue;
		const w = wegZwischen(graph, stationen, k.von, k.bis);
		if (!w.weg) { protokoll.push(`${k.von} - ${k.bis}: ${w.fehler}, bleibt wie bisher`); continue; }
		setzen(k.von, k.bis, zwischenpunkte(graph, w.weg, punkt(k.von), punkt(k.bis)));
	}
	neu._quelle = 'Streckenverlauf aus OpenStreetMap (ODbL, © OpenStreetMap-Mitwirkende), erzeugt mit dev/strecke.js am ' + new Date().toISOString().slice(0, 10);
	return { strecke: neu, protokoll };
}

/** JSON im Stil der Datei: Bahnhoefe und Kanten je eine Zeile. */
function schreiben(strecke) {
	const z = (v) => JSON.stringify(v);
	const zeilen = ['{'];
	const schluessel = Object.keys(strecke);
	schluessel.forEach((key, idx) => {
		const komma = idx < schluessel.length - 1 ? ',' : '';
		if (key === 'stationen') {
			zeilen.push('\t"stationen": {');
			const ids = Object.keys(strecke.stationen);
			ids.forEach((id, i) => zeilen.push(`\t\t${z(id)}: ${z(strecke.stationen[id])}${i < ids.length - 1 ? ',' : ''}`));
			zeilen.push('\t}' + komma);
		} else if (key === 'kanten') {
			zeilen.push('\t"kanten": [');
			strecke.kanten.forEach((k, i) => zeilen.push(`\t\t${z(k)}${i < strecke.kanten.length - 1 ? ',' : ''}`));
			zeilen.push('\t]' + komma);
		} else {
			zeilen.push(`\t${z(key)}: ${z(strecke[key])}${komma}`);
		}
	});
	zeilen.push('}');
	return zeilen.join('\n') + '\n';
}

async function main() {
	const strecke = JSON.parse(fs.readFileSync(DATEI, 'utf8'));
	let osm;
	if (process.argv[2]) {
		osm = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
	} else {
		const bbox = kasten(strecke.stationen);
		process.stderr.write(`Hole Gleise von Overpass, Kasten ${bbox} …\n`);
		osm = await overpassHolen(bbox);
	}
	const { strecke: neu, protokoll } = rechnen(strecke, osm);
	protokoll.forEach(z => process.stderr.write(z + '\n'));
	fs.writeFileSync(DATEI, schreiben(neu));
	process.stderr.write(`karte/strecke.json geschrieben.\n`);
}

if (require.main === module) {
	main().catch(e => { process.stderr.write('Fehler: ' + e.message + '\n'); process.exit(1); });
}

module.exports = { rechnen, vereinfachen, graphBauen, kuerzesterWeg, schreiben, schleifenKappen };
