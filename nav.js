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
