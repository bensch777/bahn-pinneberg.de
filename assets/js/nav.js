/* Menue-Knopf im Kopf (nb_render_kopf): das <details> klappt von selbst,
   hier nur schliessen bei Klick daneben, mit Escape und beim Wechsel auf die
   breite Ansicht, in der die Bereiche ohnehin in der Kopfzeile stehen. */
(function () {
	'use strict';
	var menu = document.getElementById('nav-menu');
	if (!menu) return;
	function zu() { menu.open = false; }
	document.addEventListener('click', function (ev) { if (menu.open && !menu.contains(ev.target)) zu(); });
	document.addEventListener('keydown', function (ev) {
		if (ev.key === 'Escape' && menu.open) { zu(); menu.querySelector('summary').focus(); }
	});
	if (window.matchMedia) {
		var breit = window.matchMedia('(min-width: 721px)');
		var wechsel = function () { if (breit.matches) zu(); };
		if (breit.addEventListener) breit.addEventListener('change', wechsel);
		else if (breit.addListener) breit.addListener(wechsel);
	}
})();

/* Hell/Dunkel-Schalter im Kopf (nb_render_kopf): wechselt gegen das gerade
   sichtbare Schema und merkt sich die Wahl in localStorage (nordbahn.theme).
   Entspricht die Wahl wieder dem System, wird sie vergessen, dann folgt die
   Seite wieder der Systemeinstellung. Das Setzen beim Laden macht nb_theme_head(). */
(function () {
	'use strict';
	var knopf = document.getElementById('theme-toggle');
	if (!knopf) return;
	var KEY = 'nordbahn.theme';
	var html = document.documentElement;
	var dunkelSys = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
	function system() { return dunkelSys && dunkelSys.matches ? 'dark' : 'light'; }
	function aktuell() {
		var t = html.getAttribute('data-theme');
		return t === 'light' || t === 'dark' ? t : system();
	}
	function metaFarben(t) {
		var m = document.querySelectorAll('meta[name="theme-color"]');
		for (var i = 0; i < m.length; i++) {
			var eigen = (m[i].getAttribute('media') || '').indexOf('dark') !== -1 ? '#121211' : '#f6f6f4';
			m[i].setAttribute('content', t ? (t === 'dark' ? '#121211' : '#f6f6f4') : eigen);
		}
	}
	function beschriften() {
		var text = aktuell() === 'dark' ? 'Helles Design' : 'Dunkles Design';
		knopf.title = text;
		var label = document.getElementById('theme-label');
		if (label) label.textContent = text;
	}
	knopf.addEventListener('click', function () {
		var neu = aktuell() === 'dark' ? 'light' : 'dark';
		if (neu === system()) {
			html.removeAttribute('data-theme');
			try { localStorage.removeItem(KEY); } catch (e) { /* privater Modus */ }
			metaFarben(null);
		} else {
			html.setAttribute('data-theme', neu);
			try { localStorage.setItem(KEY, neu); } catch (e) { /* privater Modus */ }
			metaFarben(neu);
		}
		beschriften();
	});
	if (dunkelSys) {
		if (dunkelSys.addEventListener) dunkelSys.addEventListener('change', beschriften);
		else if (dunkelSys.addListener) dunkelSys.addListener(beschriften);
	}
	beschriften();
})();
