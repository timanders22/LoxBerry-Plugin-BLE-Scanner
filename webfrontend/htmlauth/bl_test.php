<?php
/**
 * BLE-Scanner NG - Aktionen des Reiters Test
 *
 * Jede Funktion liefert array(Ueberschrift, Text). Der Text wird von der
 * Oberflaeche maskiert ausgegeben, hier also bewusst als Klartext erzeugt.
 */

require_once __DIR__ . '/bl_lib.php';

/**
 * Einen Systembefehl ausfuehren und die Ausgabe zurueckgeben.
 *
 * Der Rueckgabewert wird ausgewertet. Bis 1.1.0 wurde er verworfen, und das
 * hat genau bei den Aufrufen mit 'timeout' gestoert: laeuft bluetoothctl in
 * die Zeitgrenze, bricht timeout ab und liefert 124 - die Ausgabe ist dann
 * leer oder halb. Im Reiter Test stand daraufhin eine leere Zeile, und wer
 * sie sah, hielt sie fuer 'kein Adapter vorhanden'. Das ist etwas voellig
 * anderes als 'BlueZ antwortet nicht mehr', und nur das zweite erklaert,
 * warum der Scanner stumm bleibt.
 *
 * 127 wird ebenso benannt: dann fehlt der Befehl, und niemand muss raten.
 */
function bl_sh($cmd)
{
    $out = array();
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    if ($code === 124) {
        return trim($text) === ''
            ? '[Zeitgrenze] Der Befehl hat innerhalb der Wartezeit nichts geliefert '
              . 'und wurde abgebrochen. Das heisst NICHT, dass kein Adapter da ist - '
              . 'es heisst, dass BlueZ nicht antwortet. Pruefen mit: '
              . 'systemctl status bluetooth'
            : $text . "\n[Zeitgrenze] Abgebrochen - die Ausgabe oben ist unvollstaendig.";
    }
    if ($code === 127) {
        return '[fehlt] Der Befehl ist auf diesem System nicht vorhanden. '
             . 'Bei bluetoothctl hilft: sudo apt-get install -y bluez';
    }
    if ($code !== 0 && trim($text) === '') {
        return '[Rueckgabewert ' . (int) $code . '] Der Befehl endete mit einem Fehler, '
             . 'ohne etwas auszugeben.';
    }
    return $text;
}

function bl_test_ausfuehren($was)
{
    $p = bl_paths();
    list($cfg, $tags, $alt) = bl_config_read();

    switch ($was) {

        case 'status':
            $pid = bl_dienst_pid();
            $alter = bl_status_alter();
            $aktiv = 0;
            foreach ($tags as $t) { if ($t['aktiv'] === '1') { $aktiv++; } }
            $t = "Dienst:            " . ($pid ? "laeuft (PID $pid)" : 'laeuft nicht') . "\n";
            $t .= "Zustandsdatei:     " . ($alter < 0 ? 'nicht vorhanden' : $alter . ' Sekunden alt') . "\n";
            $t .= "Tags:              " . count($tags) . " konfiguriert, $aktiv aktiv\n";
            $t .= "Adapter:           " . bl_cfg($cfg, 'adapter', 'hci0') . "\n";
            $t .= "MQTT:              " . (bl_cfg($cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "HTTP an Miniserver: " . (bl_cfg($cfg, 'http_push', '0') === '1' ? 'ein' : 'aus') . "\n\n";
            if ($alt) {
                $t .= "Die Konfiguration liegt noch im Format der Originalfassung.\n"
                    . "Sie wird gelesen und beim naechsten Speichern umgeschrieben.\n\n";
            }
            if (!$pid) {
                $t .= "Der Dienst laeuft nicht. Ursache steht meistens im Protokoll\n"
                    . "(Reiter Logdateien). Mit \"Dienst neu starten\" erneut versuchen.\n\n";
            } elseif ($alter > 60) {
                $t .= "Der Dienst laeuft, hat aber seit $alter Sekunden nichts mehr\n"
                    . "geschrieben. Das deutet auf ein haengendes BlueZ hin -\n"
                    . "\"Bluetooth pruefen\" gibt Aufschluss.\n\n";
            }
            $t .= bl_sh('ps -o pid,etime,rss,args -C python3 2>/dev/null | grep -iE "ble_scanner_ng|PID"');
            return array('Zustand des Dienstes', trim($t) !== '' ? $t : 'Keine Angaben.');

        case 'sichtbar':
            $s = bl_status();
            if (!$s) {
                return array('Sichtbare Geraete',
                    "Es gibt noch keine Zustandsdatei.\n\n"
                    . "Sie entsteht, sobald der Dienst das erste Mal einen Durchlauf\n"
                    . "beendet hat. Laeuft der Dienst? Siehe \"Zustand des Dienstes\".");
            }
            $alter = bl_status_alter();
            $t = "Stand: vor $alter Sekunden\n\n";
            $t .= sprintf("%-19s %6s  %-28s %s\n", 'MAC', 'RSSI', 'Name', 'zuletzt');
            $t .= str_repeat('-', 70) . "\n";
            foreach (($s['sichtbar'] ?? array()) as $g) {
                $t .= sprintf("%-19s %6s  %-28s vor %s s\n",
                    $g['mac'],
                    $g['rssi'] === null ? '-' : $g['rssi'],
                    mb_substr((string) ($g['name'] ?? ''), 0, 28),
                    $g['seit']);
            }
            if (!($s['sichtbar'] ?? array())) {
                $t .= "(nichts gesehen)\n\nBLE-Geraete senden nur, wenn sie eingeschaltet sind\n"
                    . "und aktiv werben. Manche Tags tun das nur nach einem Tastendruck.";
            }
            return array('Sichtbare Geraete', $t);

        case 'tags':
            $s = bl_status();
            if (!$tags) {
                return array('Zustand der Tags', 'Es ist kein Tag konfiguriert.');
            }
            $t = sprintf("%-19s %-24s %-7s %6s %6s %s\n",
                'MAC', 'Name', 'aktiv', 'da', 'RSSI', 'Stufe');
            $t .= str_repeat('-', 78) . "\n";
            $zustand = array();
            foreach (($s['tags'] ?? array()) as $z) {
                $zustand[$z['mac']] = $z;
            }
            foreach ($tags as $tag) {
                $z = $zustand[$tag['mac']] ?? null;
                $t .= sprintf("%-19s %-24s %-7s %6s %6s %s\n",
                    $tag['mac'],
                    mb_substr($tag['name'], 0, 24),
                    $tag['aktiv'] === '1' ? 'ja' : 'nein',
                    $z ? ($z['anwesend'] ? 'ja' : 'nein') : '?',
                    $z && $z['rssi'] !== null ? $z['rssi'] : '-',
                    $z ? $z['stufe'] : '-');
            }
            if (!$s) {
                $t .= "\n(Keine Zustandsdatei - der Dienst laeuft vermutlich nicht.)";
            }
            return array('Zustand der Tags', $t);

        case 'themen':
            $praefix = bl_cfg($cfg, 'themenpraefix', 'blescanner');
            $t = "Zustaende - retained, der Miniserver hat den Stand also sofort\n"
               . "nach einem Neustart wieder:\n\n";
            $t .= "  $praefix/server/online       Dienst laeuft\n";
            $t .= "  $praefix/summary/present     Anzahl anwesender Tags\n";
            $t .= "  $praefix/summary/tags        Anzahl konfigurierter Tags\n";
            $aktive = 0;
            foreach ($tags as $tag) {
                if ($tag['aktiv'] !== '1') {
                    continue;
                }
                $aktive++;
                $th = bl_thema($tag['mac']);
                $t .= "\n  " . $tag['mac'] . ($tag['name'] !== '' ? '  (' . $tag['name'] . ')' : '') . "\n";
                foreach (bl_status_themen() as $k => $info) {
                    $t .= sprintf("    %s/%s/%-10s %s\n", $praefix, $th, $k,
                        strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
                }
            }
            if (!$aktive) {
                $t .= "\nKein Tag ist aktiv - es wird nichts je Tag veroeffentlicht.";
            }
            return array('MQTT-Themen', $t);

        case 'bluetooth':
            $adapter = bl_cfg($cfg, 'adapter', 'hci0');
            $t = "Gesucht wird auf Adapter: $adapter\n\n";
            $t .= "--- Adapter laut BlueZ (bluetoothctl list) ---\n";
            $t .= bl_sh('timeout 8 bluetoothctl list') . "\n\n";
            $t .= "--- Zustand (bluetoothctl show) ---\n";
            $t .= bl_sh('timeout 8 bluetoothctl show') . "\n\n";
            $t .= "--- Dienst bluetooth.service ---\n";
            $t .= bl_sh('systemctl is-active bluetooth 2>/dev/null; systemctl is-enabled bluetooth 2>/dev/null') . "\n\n";
            $t .= "--- Kernel-Meldungen zu Bluetooth ---\n";
            $t .= bl_sh('dmesg 2>/dev/null | grep -i blue | tail -8');
            if (trim($t) === '' ) {
                $t = 'Keine Angaben - ist bluez installiert?';
            }
            $t .= "\n\nHinweis: Wird kein Adapter aufgelistet, fehlt die Hardware oder\n"
                . "der Benutzer darf nicht auf BlueZ zugreifen. Auf einem Raspberry Pi\n"
                . "ohne eingebautes Bluetooth wird ein USB-Adapter gebraucht.";
            return array('Bluetooth', $t);

        case 'konfig':
            $t = "Datei: " . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= "Datei nicht vorhanden - es gelten die Vorgabewerte:\n\n";
                foreach (bl_defaults() as $k => $v) {
                    $t .= $k . '=' . $v . "\n";
                }
            }
            return array('Konfiguration', $t);

        case 'umgebung':
            $t = "PHP:        " . PHP_VERSION . "\n";
            $t .= "LBHOMEDIR:  " . ($p['home'] !== '' ? $p['home'] : '(nicht gesetzt)') . "\n";
            $t .= "Plugin:     " . $p['plugin'] . "\n";
            $t .= "bin:        " . $p['bindir'] . "\n";
            $t .= "log:        " . $p['logdir'] . "\n";
            $t .= "Zustand:    " . $p['status'] . "\n\n";
            $t .= bl_sh('python3 --version');
            $t .= "\n\nBenoetigte Python-Module:\n";
            foreach (array('dbus', 'gi', 'paho.mqtt.client') as $m) {
                $r = bl_sh('python3 -c ' . escapeshellarg('import ' . $m));
                $t .= sprintf("  %-20s %s\n", $m, $r === '' ? 'vorhanden' : 'FEHLT');
            }
            $t .= "\nWerkzeuge:\n";
            foreach (array('bluetoothctl', 'python3') as $w) {
                $t .= sprintf("  %-20s %s\n", $w,
                    trim(bl_sh('command -v ' . escapeshellarg($w))) ?: 'FEHLT');
            }
            $t .= "\nFehlt etwas, hat die Installation die Pakete nicht eingerichtet.\n"
                . "Nachholen mit:\n"
                . "  sudo apt-get install -y bluez python3-dbus python3-gi python3-paho-mqtt\n";
            return array('Umgebung', $t);

        case 'mqttinfo':
            $broker = bl_mqtt_broker();
            $t = "Broker:         " . ($broker !== '' ? $broker : 'nicht gefunden') . "\n";
            $t .= "MQTT im Plugin: " . (bl_cfg($cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "Themenpraefix:  " . bl_cfg($cfg, 'themenpraefix', 'blescanner') . "\n\n";
            if ($broker === '') {
                $t .= "Ohne MQTT-Gateway kann das Plugin nichts veroeffentlichen.\n"
                    . "Das Gateway ist ein eigenes LoxBerry-Plugin und muss installiert sein.\n\n";
            }
            $t .= "Zum Mitlesen eignet sich der MQTT Finder des Gateways;\n"
                . "dort auf " . bl_cfg($cfg, 'themenpraefix', 'blescanner') . "/# achten.";
            return array('MQTT-Gateway', $t);

        case 'restart':
            $a = bl_dienst('restart');
            $pid = bl_dienst_pid();
            $t = ($a !== '' ? $a . "\n\n" : '');
            $t .= $pid ? "Dienst laeuft jetzt (PID $pid).\n\nDer erste vollstaendige Durchlauf\n"
                       . "dauert einige Sekunden; danach steht die Zustandsdatei."
                       : "Der Dienst laeuft nicht. Protokoll im Reiter Logdateien pruefen.";
            return array('Dienst neu starten', $t);

        case 'stop':
            $a = bl_dienst('stop');
            $t = ($a !== '' ? $a . "\n\n" : '');
            $t .= bl_dienst_pid() ? "Es laeuft noch etwas - bitte Protokoll pruefen."
                : "Angehalten.\n\nHinweis: Beim naechsten Systemstart startet der Dienst wieder.";
            return array('Dienst anhalten', $t);
    }

    return array('Unbekannt', 'Diese Aktion gibt es nicht.');
}
