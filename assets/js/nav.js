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

/* Design-Wahl auf der Einstellungsseite (Darstellung): Wie Geraet, Hell oder Dunkel.
   Hell/Dunkel merkt sich localStorage (nordbahn.theme), "Wie Geraet" loescht den
   Eintrag, dann folgt die Seite wieder dem System. Das Setzen beim Laden macht
   nb_theme_head() auf jeder Seite. */
(function () {
	'use strict';
	var radios = document.querySelectorAll('input[name="theme-wahl"]');
	if (!radios.length) return;
	var KEY = 'nordbahn.theme';
	var html = document.documentElement;
	function gewaehlt() {
		var t = html.getAttribute('data-theme');
		return t === 'light' || t === 'dark' ? t : 'system';
	}
	function metaFarben(t) {
		var m = document.querySelectorAll('meta[name="theme-color"]');
		for (var i = 0; i < m.length; i++) {
			var eigen = (m[i].getAttribute('media') || '').indexOf('dark') !== -1 ? '#121211' : '#f6f6f4';
			m[i].setAttribute('content', t ? (t === 'dark' ? '#121211' : '#f6f6f4') : eigen);
		}
	}
	Array.prototype.forEach.call(radios, function (r) {
		r.checked = r.value === gewaehlt();
		r.addEventListener('change', function () {
			if (!r.checked) return;
			if (r.value === 'system') {
				html.removeAttribute('data-theme');
				try { localStorage.removeItem(KEY); } catch (e) { /* privater Modus */ }
				metaFarben(null);
			} else {
				html.setAttribute('data-theme', r.value);
				try { localStorage.setItem(KEY, r.value); } catch (e) { /* privater Modus */ }
				metaFarben(r.value);
			}
		});
	});
})();
