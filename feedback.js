/*
 * Feedback-Dialog (Knopf unten links auf jeder Seite, Markup in nb_render_feedback):
 * oeffnet den <dialog>, schickt das Formular per fetch an api/feedback und zeigt
 * die Antwort an. Ohne Script bleibt der Knopf versteckt, der Dialog liesse sich
 * sonst nicht oeffnen.
 */
(function () {
	'use strict';

	var knopf = document.getElementById('feedback-open');
	var dialog = document.getElementById('feedback-dialog');
	var form = document.getElementById('feedback-form');
	if (!knopf || !dialog || !form || !window.fetch || !window.FormData) return;

	var schliessen = dialog.querySelector('.feedback-dialog__close');
	var nachricht = form.querySelector('[name="nachricht"]');
	var art = form.querySelector('[name="art"]');
	var senden = form.querySelector('.feedback-form__submit');
	var status = form.querySelector('.feedback-form__status');
	var sendenText = senden.textContent;
	var TEXTE = {
		zu_kurz: 'Bitte schreib ein paar Worte mehr.',
		limit: 'Du hast gerade schon Feedback geschickt. Bitte versuch es später noch mal.',
		nicht_eingerichtet: 'Das Formular ist gerade nicht aktiv.',
		fehler: 'Das hat nicht geklappt. Bitte versuch es später noch mal.',
		danke: 'Danke! Deine Nachricht ist angekommen.'
	};

	function setStatus(text, ok) {
		status.textContent = text;
		status.className = 'feedback-form__status ' + (ok ? 'is-ok' : 'is-fehler');
	}

	function oeffnen() {
		status.textContent = '';
		status.className = 'feedback-form__status';
		if (typeof dialog.showModal === 'function') dialog.showModal();
		else dialog.setAttribute('open', '');
		nachricht.focus();
	}

	function zu() {
		if (typeof dialog.close === 'function' && dialog.open) dialog.close();
		else dialog.removeAttribute('open');
	}

	knopf.hidden = false;
	knopf.addEventListener('click', oeffnen);
	if (schliessen) schliessen.addEventListener('click', zu);
	// Klick auf den Hintergrund schliesst; innerhalb des Dialogs liegt das Ziel im Inhalt
	dialog.addEventListener('click', function (ev) { if (ev.target === dialog) zu(); });

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		if (nachricht.value.trim().length < 5) { setStatus(TEXTE.zu_kurz, false); nachricht.focus(); return; }
		var daten = new FormData(form);
		daten.append('seite', window.location.pathname);
		senden.disabled = true;
		senden.textContent = 'Wird gesendet …';
		fetch(form.getAttribute('action'), { method: 'POST', body: daten, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (res) { return res.json().catch(function () { return {}; }).then(function (json) { return { ok: res.ok, status: json.status || '' }; }); })
			.then(function (r) {
				if (r.ok) {
					setStatus(TEXTE.danke, true);
					try { if (window.plausible) window.plausible('Feedback', { props: { art: art ? art.value : 'sonst' } }); } catch (e) { /* egal */ }
					form.reset();
					setTimeout(zu, 1800);
				} else {
					setStatus(TEXTE[r.status] || TEXTE.fehler, false);
				}
			})
			.catch(function () { setStatus(TEXTE.fehler, false); })
			.then(function () { senden.disabled = false; senden.textContent = sendenText; });
	});
})();
