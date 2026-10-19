<?php
/**
 * Gemeinsamer Footer fuer Startseite, FAQ und Changelog. Erwartet $root (Pfad zum
 * Wurzelverzeichnis, "./" oder "../") und $current ("lage", "faq", "eilmeldungen", "stoerungen", "statistik", "changelog", "impressum" oder "ereignis").
 */
?>
<footer class="site-foot">
	<div class="wrap site-foot__grid">
		<div class="site-foot__about">
			<a class="site-foot__brand" href="<?= $root ?>">Nordbahn Monitor</a>
			<p>St&ouml;rungen, Abfahrten und Eilmeldungen der RB61 und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist. Ein privates Projekt, keine offizielle Seite der Nordbahn.</p>
		</div>
		<div>
			<h2 class="site-foot__head">Kan&auml;le</h2>
			<ul class="site-foot__list">
				<li><a class="foot__link" href="https://t.me/NordbahnEilmeldungen" rel="noopener" title="Telegram"><svg class="foot__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 3.3 2.9 10.6c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.3 4.8 3.6c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.4-1.7ZM8.4 13.1l9.6-6c.5-.3.9-.1.5.2l-7.9 7.2-.3 3.3-1.9-4.7Z"/></svg>Nordbahn Eilmeldungen</a></li>
				<li><a class="foot__link" href="https://t.me/prisdorfpendler" rel="noopener" title="Telegram"><svg class="foot__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 3.3 2.9 10.6c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.3 4.8 3.6c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.4-1.7ZM8.4 13.1l9.6-6c.5-.3.9-.1.5.2l-7.9 7.2-.3 3.3-1.9-4.7Z"/></svg>Prisdorf Pendler</a></li>
				<li><a class="foot__link" href="https://x.com/bahn_pinneberg" rel="noopener" title="X"><svg class="foot__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18.2 2.3h3.3l-7.2 8.2 8.5 11.2h-6.6l-5.2-6.8-6 6.8H1.7l7.7-8.8L1.3 2.3H8l4.7 6.2 5.5-6.2Zm-1.2 19.4h1.8L7.1 4.1H5.2l11.8 17.6Z"/></svg>@bahn_pinneberg</a></li>
			</ul>
		</div>
		<div>
			<h2 class="site-foot__head">Seite</h2>
			<ul class="site-foot__list">
<?php if ($current !== 'lage'): ?>
				<li><a href="<?= $root ?>">Aktuelle Lage</a></li>
<?php endif; ?>
<?php if ($current !== 'faq'): ?>
				<li><a href="<?= $root ?>faq/">H&auml;ufige Fragen</a></li>
<?php endif; ?>
<?php if ($current !== 'eilmeldungen'): ?>
				<li><a href="<?= $root ?>eilmeldungen/">Alle Eilmeldungen</a></li>
<?php endif; ?>
<?php if ($current !== 'stoerungen'): ?>
				<li><a href="<?= $root ?>stoerungen/">Alle St&ouml;rungen</a></li>
<?php endif; ?>
<?php if ($current !== 'statistik'): ?>
				<li><a href="<?= $root ?>statistik/">St&ouml;rungsstatistik</a></li>
<?php endif; ?>
<?php if ($current !== 'changelog'): ?>
				<li><a href="<?= $root ?>changelog/">Was ist neu</a></li>
<?php endif; ?>
<?php if ($current !== 'impressum'): ?>
				<li><a href="<?= $root ?>impressum/" rel="nofollow">Impressum &amp; Datenschutz</a></li>
<?php endif; ?>
				<li>Quellen: <a href="https://www.nordbahn.de/" rel="noopener">nordbahn.de</a>, <a href="https://strecken-info.de/" rel="noopener">strecken-info.de</a></li>
			</ul>
		</div>
	</div>
</footer>
