<?php
/**
 * Plausible-Snippet, nur wenn NORDBAHN_PLAUSIBLE_JS in config.php gesetzt ist.
 * Die Script-URL ist an die Plausible-Site gebunden; fuer eine neue Domain in
 * Plausible eine Site anlegen und die neue URL eintragen. Das Inline-Snippet
 * traegt die CSP-Nonce der Antwort (inc/security.php).
 *
 * Jeder Seitenaufruf bekommt die Eigenschaft "heimat": der im Browser
 * gewaehlte Heimatbahnhof (localStorage, siehe dashboard.js) oder
 * "<Vorgabe> (Vorgabe)", wenn nie einer gewaehlt wurde. In Plausible unter
 * Properties nach "heimat" filtern, um zu sehen, woher die Pendler kommen.
 */
$plausibleJs = nordbahn_plausible_js();
if ($plausibleJs !== ''):
    $bahnhofNamen = [];
    foreach (NORDBAHN_BAHNHOEFE as $id => $bhf) {
        $bahnhofNamen[$id] = $bhf['name'];
    }
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!-- Privacy-friendly analytics by Plausible -->
<script async src="<?= htmlspecialchars($plausibleJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script nonce="<?= nb_csp_nonce() ?>">
  window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};
  (function(){
    var namen=<?= json_encode($bahnhofNamen, $jsonFlags) ?>,vorgabe=<?= json_encode(NORDBAHN_BAHNHOEFE[NORDBAHN_HEIMAT_DEFAULT]['name'] . ' (Vorgabe)', $jsonFlags) ?>,heimat='';
    try{heimat=localStorage.getItem('nordbahn.heimat')||''}catch(e){}
    plausible.init({customProperties:{heimat:namen[heimat]||vorgabe}})
  })()
</script>
<?php endif;
