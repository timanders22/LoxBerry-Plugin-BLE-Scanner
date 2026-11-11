<?php
/**
 * BLE-Scanner NG - Bruecke vom Dienst zur gemeinsamen Sprachausgabe (seit 1.3.31).
 * Aufruf: php bl_ansage.php <anlass> <0|1> <pluginordner> [name] [frist]. Anlaesse: 'ausfall'. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$bl_a = isset($argv[1]) ? (string) $argv[1] : '';
$bl_g = isset($argv[2]) ? (string) $argv[2] : '';
$bl_o = isset($argv[3]) ? (string) $argv[3] : '';
$bl_n = isset($argv[4]) ? (string) $argv[4] : '';
$bl_f = isset($argv[5]) ? (int) $argv[5] : 0;
if (!in_array($bl_a, array('ausfall'), true) || ($bl_g !== '0' && $bl_g !== '1') || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $bl_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
// 1.3.38 (K8): eine LoxBerry-Wurzel nur mit config/plugins, data/plugins und config/system/general.json - dieselbe
// Torbedingung wie preinstall.sh und bl_wurzel_gilt(). Bis 1.3.37 genuegte config/plugins, und der Rueckfall
// (drei Ebenen ueber bin/plugins/<ordner>) galt ungeprueft.
$bl_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$bl_h = getenv('LBHOMEDIR');
if (!$bl_wurzel($bl_h)) {
    $bl_h = dirname(dirname(dirname(__DIR__)));
    if (!$bl_wurzel($bl_h)) {
        echo "ANSAGE;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$bl_lib = $bl_h . '/webfrontend/htmlauth/plugins/' . $bl_o . '/bl_lib.php';
if (!is_file($bl_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $bl_h);
putenv('LBPPLUGINDIR=' . $bl_o);
require_once $bl_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) bl_ansage_flanke($bl_a, $bl_g === '1', $bl_n, $bl_f)) . "\n";
exit(0);
