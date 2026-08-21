<?php
/**
 * BLE-Scanner NG - Zustandsdatei als JSON fuer die Live-Ansicht
 *
 * Liegt unter htmlauth/, nicht unter html/. Das ist Absicht: die
 * Originalfassung hatte einen unangemeldeten Endpunkt, und der ist mit der
 * Neufassung ersatzlos entfallen. In der Zustandsdatei stehen die Namen der
 * ueberwachten Personen und ihre Sichtbarkeit - eine Anwesenheitsliste des
 * Haushalts. Die gehoert hinter die Anmeldung.
 *
 * Dieser Aufruf LIEST nur. Er loest nichts aus und braucht deshalb kein
 * Token (Hausregel: "ein Aufruf, der etwas ausloest, verlangt ein Token" -
 * abfragende Aufrufe bleiben offen).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once __DIR__ . '/bl_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$bl_p = bl_paths();
$bl_status = bl_status();

$antwort = array(
    'zeit'     => time(),
    'vorhanden' => $bl_status ? 1 : 0,
    'pid'      => bl_dienst_pid(),
    'alter'    => bl_status_alter(),
    'stille'   => bl_stille(),
    'status'   => $bl_status,
);

$json = json_encode($antwort, JSON_UNESCAPED_UNICODE);
if ($json === false) {
    // json_encode kann an ungueltigem UTF-8 scheitern - dann darf hier keine
    // leere Antwort stehen, sondern eine, die den Grund nennt.
    http_response_code(500);
    echo '{"fehler":"Die Zustandsdatei liess sich nicht als JSON ausgeben: '
       . addslashes(json_last_error_msg()) . '"}';
    exit;
}
echo $json;
