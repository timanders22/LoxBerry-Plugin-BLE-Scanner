<?php
/**
 * Konfiguration vervollstaendigen - Kommandozeile (seit 1.3.40).
 *
 * Aufgerufen von postupgrade.sh nach dem Zurueckspielen und vom Dienst beim
 * Start. Geschrieben wird ausschliesslich ueber bl_config_vervollstaendigen()
 * in bl_lib.php - der Dienst schreibt die Datei nicht selbst (ein Schreiber
 * fuer ein Format, siehe bl_common.py nach konfiguration_lesen()).
 *
 * Aufruf: php bl_vervollstaendigen.php <pluginordner>
 * Ausgabe genau eine Zeile:
 *   VERVOLLSTAENDIGEN;STAND=<ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 0 bei ohne_datei, ohne_merkwort, vollstaendig, ergaenzt; sonst 1.
 * Die Zeile nennt nur Schluesselnamen, nie einen Wert.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$bl_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $bl_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$bl_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$bl_h = getenv('LBHOMEDIR');
if (!$bl_wurzel($bl_h)) {
    $bl_h = dirname(dirname(dirname(__DIR__)));
    if (!$bl_wurzel($bl_h)) {
        echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$bl_lib = $bl_h . '/webfrontend/htmlauth/plugins/' . $bl_o . '/bl_lib.php';
if (!is_file($bl_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $bl_h);
putenv('LBPPLUGINDIR=' . $bl_o);
require_once $bl_lib;
$bl_e = bl_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $bl_e['stand'] . ';SCHLUESSEL=' . implode(',', $bl_e['schluessel']) . "\n";
exit(in_array($bl_e['stand'], array('ohne_datei', 'ohne_merkwort', 'vollstaendig', 'ergaenzt'), true) ? 0 : 1);
