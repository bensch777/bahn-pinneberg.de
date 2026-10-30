<?php
/**
 * Testlauf fuer den Nordbahn Monitor, ohne Fremdpakete:
 *
 *   php tests/run.php            alle Tests
 *   php tests/run.php teil       nur Tests, deren Name "teil" enthaelt
 *
 * Jede Funktion test_* in tests/nordbahn/*Test.php ist ein Test. Sie wirft bei
 * einem Fehlschlag (siehe t_assert in support.php). Der Cache liegt in einem
 * Wegwerfverzeichnis, damit kein Test den echten cache/ anfasst.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Berlin');

$tmp = sys_get_temp_dir() . '/nordbahn-tests-' . getmypid();
@mkdir($tmp, 0775, true);
define('NORDBAHN_CACHE_DIR', $tmp);

require_once __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require_once __DIR__ . '/../api/abfahrten.php';
require_once __DIR__ . '/../api/geofox.php';
require_once __DIR__ . '/../api/karte.php';
require_once __DIR__ . '/../api/feedback.php';
require_once __DIR__ . '/../api/fahrplan.php';
require_once __DIR__ . '/../api/mcp.php';
require_once __DIR__ . '/support.php';

foreach (glob(__DIR__ . '/nordbahn/*Test.php') as $file) {
    require_once $file;
}

$filter = $argv[1] ?? '';
$tests = array_values(array_filter(get_defined_functions()['user'], function ($f) use ($filter) {
    return strpos($f, 'test_') === 0 && ($filter === '' || stripos($f, $filter) !== false);
}));
sort($tests);

$failed = 0;
foreach ($tests as $test) {
    t_reset();
    try {
        $test();
        echo "ok   $test\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $test\n     " . str_replace("\n", "\n     ", $e->getMessage()) . "\n";
        if (!$e instanceof TestFailure) {
            echo '     ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}

foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

printf("\n%d Tests, %d fehlgeschlagen\n", count($tests), $failed);
exit($failed ? 1 : 0);
