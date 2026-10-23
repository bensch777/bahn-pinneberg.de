// Erzeugt die Vorschaubilder og/<lage>.png (1200x630) aus dev/og/template.html.
//
//   node dev/og/build.js            (braucht: npm i -g playwright, Chromium)
//
// Farben wie die Lagekarten der Seite.
const { chromium } = require('playwright');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');

const LAGEN = [
  { name: 'ok',    color: '#1aa139', icon: '✓', title: 'Keine Störung auf der Strecke' },
  { name: 'warn',  color: '#fab219', icon: '!', title: 'Betrieb beeinträchtigt' },
  { name: 'alert', color: '#d33b3b', icon: '!', title: 'Störung auf der Strecke' },
  { name: 'info',  color: '#2a78d6', icon: 'i', title: 'Störungen, Abfahrten und Eilmeldungen' },
];

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
  await page.goto('file://' + path.join(__dirname, 'template.html'));
  await page.evaluate(() => document.fonts.ready);
  for (const l of LAGEN) {
    await page.evaluate((l) => {
      document.body.style.setProperty('--c', l.color);
      document.getElementById('icon').textContent = l.icon;
      document.getElementById('title').textContent = l.title;
    }, l);
    const out = path.join(root, 'og', l.name + '.png');
    await page.screenshot({ path: out, type: 'png' });
    console.log('geschrieben', path.relative(root, out));
  }
  await browser.close();
})();
