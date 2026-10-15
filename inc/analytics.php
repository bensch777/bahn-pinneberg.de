<?php
/**
 * Plausible-Snippet, nur wenn NORDBAHN_PLAUSIBLE_JS in config.php gesetzt ist.
 * Die Script-URL ist an die Plausible-Site gebunden; fuer eine neue Domain in
 * Plausible eine Site anlegen und die neue URL eintragen. Das Inline-Snippet
 * traegt die CSP-Nonce der Antwort (inc/security.php).
 */
$plausibleJs = nordbahn_plausible_js();
if ($plausibleJs !== ''): ?>
<!-- Privacy-friendly analytics by Plausible -->
<script async src="<?= htmlspecialchars($plausibleJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script nonce="<?= nb_csp_nonce() ?>">
  window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};
  plausible.init()
</script>
<?php endif;
