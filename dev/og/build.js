// Erzeugt die Vorschaubilder og/<lage>.png (1200x630) aus dev/og/template.html.
//
//   node dev/og/build.js            (braucht: npm i -g playwright, Chromium)
//   node dev/og/build.js karte      nur das genannte Bild
//
// Farben wie die Lagekarten der Seite. 'image' statt 'icon' zeigt ein Bild im Kreis,
// 'sub' ersetzt die Unterzeile.
const { chromium } = require('playwright');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');

const LAGEN = [
  { name: 'ok',    color: '#1aa139', icon: '✓', title: 'Keine Störung auf der Strecke' },
  { name: 'warn',  color: '#fab219', icon: '!', title: 'Betrieb beeinträchtigt' },
  { name: 'alert', color: '#d33b3b', icon: '!', title: 'Störung auf der Strecke' },
  { name: 'info',  color: '#2a78d6', icon: 'i', title: 'Störungen, Abfahrten und Eilmeldungen' },
  { name: 'karte', color: '#1f9d55', image: '../../karte/zug.png', title: 'Nordbahn live: wo die Züge gerade sind', sub: 'RB60, RB61 und RB71 auf der Karte · berechnete Positionen, keine GPS-Ortung', foot: 'Live-Karte mit Verspätung und nächstem Halt je Zug' },
];
const nur = process.argv[2] || '';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
  await page.goto('file://' + path.join(__dirname, 'template.html'));
  await page.evaluate(() => document.fonts.ready);
  for (const l of LAGEN) {
    if (nur && l.name !== nur) continue;
    await page.evaluate((l) => {
      document.body.style.setProperty('--c', l.color);
      const icon = document.getElementById('icon');
      if (l.image) {
        icon.innerHTML = '';
        const img = document.createElement('img');
        img.src = l.image;
        icon.appendChild(img);
      } else {
        icon.textContent = l.icon;
      }
      document.getElementById('title').textContent = l.title;
      document.getElementById('sub').textContent = l.sub || 'RB60, RB61 und RB71 · Hamburg – Itzehoe / Wrist';
      document.getElementById('foot').textContent = l.foot || 'Störungen, Abfahrten und Eilmeldungen, live';
    }, l);
    if (l.image) {
      await page.waitForFunction(() => { const i = document.querySelector('#icon img'); return i && i.complete && i.naturalWidth > 0; });
    }
    const out = path.join(root, 'og', l.name + '.png');
    await page.screenshot({ path: out, type: 'png' });
    console.log('geschrieben', path.relative(root, out));
  }
  await browser.close();
})();
