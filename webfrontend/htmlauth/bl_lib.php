<?php
/**
 * BLE-Scanner - gemeinsame Hilfsfunktionen
 *
 * Die Konfiguration liegt im selben Format, das bin/bl_common.py liest und
 * schreibt. Beide Seiten muessen sich hier einig sein, sonst verliert die
 * Oberflaeche beim Speichern die Tags.
 *
 * Eigenes Praefix "bl_", weil LBWeb::lbheader() SDK-Globale setzt und sonst
 * Namen kollidieren.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('bl_e')) {
    function bl_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */
function bl_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home && is_dir('/opt/loxberry')) {
        $home = '/opt/loxberry';
    }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(dirname(dirname(__DIR__)));
    }
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(basename(dirname(__DIR__)), 'ble_scanner') as $cand) {
            if (is_dir($home . '/config/plugins/' . $cand)) {
                $dir = $cand;
                break;
            }
        }
    }
    $status = is_dir('/run/shm') ? '/run/shm/ble_scanner_status.json'
                                 : '/tmp/ble_scanner_status.json';
    if ($home) {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => $home . '/config/plugins/' . $dir . '/ble_scanner.cfg',
            'bindir' => $home . '/bin/plugins/' . $dir,
            'logdir' => $home . '/log/plugins/' . $dir,
            'status' => $status,
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'   => '',
            'plugin' => $dir,
            'config' => $base . '/config/ble_scanner.cfg',
            'bindir' => $base . '/bin',
            'logdir' => sys_get_temp_dir(),
            'status' => $status,
        );
    }
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in bl_common.py passen. */
function bl_defaults()
{
    return array(
        'adapter'          => 'hci0',
        'themenpraefix'    => 'blescanner',
        'mqtt'             => '1',
        'http_push'        => '0',
        'loxberry_id'      => '',
        'intervall'        => '5',
        'abwesenheit_nach' => '30',
        'aktualisierung'   => '60',
        'rssi_nah'         => '-65',
        'rssi_mittel'      => '-85',
    );
}

/** MAC in die Form AA:BB:CC:DD:EE:FF bringen, sonst leer. */
function bl_mac($wert)
{
    $wert = strtoupper(trim((string) $wert));
    $wert = preg_replace('/^BLE[_:-]/', '', $wert);
    $hex = preg_replace('/[^0-9A-F]/', '', $wert);
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

/** MQTT-Thema eines Tags: MAC ohne Trennzeichen. */
function bl_thema($mac)
{
    return str_replace(':', '', bl_mac($mac));
}

/**
 * Konfiguration lesen. Erkennt das alte Format des Originalplugins mit.
 * Rueckgabe: array($werte, $tags, $altesFormat)
 */
function bl_config_read()
{
    $werte = bl_defaults();
    $tags = array();
    $alt = false;
    $file = bl_paths()['config'];
    if (!is_file($file)) {
        return array($werte, $tags, $alt);
    }
    foreach (preg_split('/\R/', (string) @file_get_contents($file)) as $zeile) {
        $t = trim($zeile);
        if ($t === '' || $t[0] === ';' || $t[0] === '#' || $t[0] === '[') {
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            continue;
        }
        $schluessel = trim(substr($t, 0, $pos));
        $wert = trim(substr($t, $pos + 1));
        $wert = trim($wert, "\"'");

        // altes Format: TAG1=BLE_..:on:1^on~2^off:Kommentar
        if (preg_match('/^(default\.)?TAG\d+$/i', $schluessel)
            && strpos($wert, ':') !== false && strpos($wert, '|') === false) {
            $alt = true;
            $teile = explode(':', $wert);
            $mac = bl_mac($teile[0]);
            $aktiv = (isset($teile[1]) && in_array(strtolower(trim($teile[1])), array('on', '1', 'true'), true)) ? '1' : '0';
            $name = count($teile) > 3 ? trim(implode(':', array_slice($teile, 3))) : '';
            if ($mac !== '') {
                $tags[] = array('mac' => $mac, 'aktiv' => $aktiv, 'name' => $name);
            }
            continue;
        }

        // neues Format: tag1=AA:BB:..|1|Name
        if (preg_match('/^tag\d+$/i', $schluessel)) {
            $teile = explode('|', $wert);
            $mac = bl_mac(isset($teile[0]) ? $teile[0] : '');
            $aktiv = isset($teile[1]) ? trim($teile[1]) : '1';
            $name = isset($teile[2]) ? trim($teile[2]) : '';
            if ($mac !== '') {
                $tags[] = array(
                    'mac' => $mac,
                    'aktiv' => in_array($aktiv, array('1', 'on', 'true'), true) ? '1' : '0',
                    'name' => $name,
                );
            }
            continue;
        }

        $schluessel = strtolower(preg_replace('/^default\./i', '', $schluessel));
        if (array_key_exists($schluessel, $werte)) {
            $werte[$schluessel] = $wert;
        }
    }
    return array($werte, $tags, $alt);
}

/** Wert lesen, mit Vorgabe. */
function bl_cfg($cfg, $key, $default = '')
{
    return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
}

/** Konfiguration schreiben - Format wie bl_common.py es erwartet. */
function bl_config_write($werte, $tags)
{
    $file = bl_paths()['config'];
    @mkdir(dirname($file), 0775, true);
    $txt = "; BLE-Scanner\n; Geschrieben von der Plugin-Oberflaeche.\n\n[CONFIG]\n";
    foreach (bl_defaults() as $k => $vorgabe) {
        $v = isset($werte[$k]) ? $werte[$k] : $vorgabe;
        // Senkrechter Strich trennt die Tag-Felder und darf sonst nirgends
        // auftauchen; Zeilenumbrueche wuerden die Datei zerlegen.
        $v = str_replace(array("\r", "\n", '|'), array('', ' ', '/'), (string) $v);
        $txt .= $k . '=' . trim($v) . "\n";
    }
    $txt .= "\n";
    $nr = 0;
    foreach ($tags as $tag) {
        $mac = bl_mac($tag['mac']);
        if ($mac === '') {
            continue;
        }
        $nr++;
        $name = str_replace(array("\r", "\n", '|'), array('', ' ', '/'), (string) $tag['name']);
        $txt .= 'tag' . $nr . '=' . $mac . '|' . ($tag['aktiv'] === '1' ? '1' : '0')
              . '|' . trim($name) . "\n";
    }
    $ok = @file_put_contents($file, $txt) !== false;
    if ($ok) {
        @chmod($file, 0644);
    }
    return $ok;
}

/** Zustandsdatei des Dienstes lesen. */
function bl_status()
{
    $f = bl_paths()['status'];
    if (!is_file($f)) {
        return null;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/** Wie alt ist die Zustandsdatei in Sekunden? -1 = keine. */
function bl_status_alter()
{
    $s = bl_status();
    if (!$s || !isset($s['zeit'])) {
        return -1;
    }
    return max(0, time() - (int) $s['zeit']);
}

/** Laeuft der Dienst? Rueckgabe: PID oder 0. */
function bl_dienst_pid()
{
    $out = array();
    @exec('pgrep -o -f ble_scanner.py 2>/dev/null', $out);
    return $out ? (int) $out[0] : 0;
}

/** Dienst starten, stoppen, neu starten. */
function bl_dienst($aktion)
{
    $p = bl_paths();
    $skript = $p['bindir'] . '/ble_scanner.py';
    $meldungen = array();
    if (in_array($aktion, array('stop', 'restart'), true)) {
        @exec('pkill -f ble_scanner.py 2>&1', $meldungen);
        sleep(2);
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return 'Dienst nicht gefunden: ' . $skript;
        }
        $log = $p['logdir'] . '/ble_scanner.log';
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo gestartet', $meldungen);
        sleep(3);
    }
    return implode("\n", $meldungen);
}

/** Miniserver aus general.json. */
function bl_miniservers()
{
    $out = array();
    $f = bl_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return $out;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || !isset($j['Miniserver']) || !is_array($j['Miniserver'])) {
        return $out;
    }
    foreach ($j['Miniserver'] as $nr => $ms) {
        $out[(string) $nr] = array(
            'name' => isset($ms['Name']) ? $ms['Name'] : ('Miniserver ' . $nr),
            'ip'   => isset($ms['Ipaddress']) ? $ms['Ipaddress']
                    : (isset($ms['IPAddress']) ? $ms['IPAddress'] : ''),
        );
    }
    return $out;
}

/** Adresse des MQTT-Brokers, nur zur Anzeige, ohne Kennwort. */
function bl_mqtt_broker()
{
    $f = bl_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return '';
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return '';
    }
    foreach (array('Mqtt', 'mqtt') as $a) {
        foreach (array('Brokerhost', 'brokerhost') as $h) {
            if (!empty($j[$a][$h])) {
                $port = 1883;
                foreach (array('Brokerport', 'brokerport') as $pk) {
                    if (!empty($j[$a][$pk])) {
                        $port = (int) $j[$a][$pk];
                    }
                }
                return $j[$a][$h] . ':' . $port;
            }
        }
    }
    return '';
}

/** Zustandsthemen je Tag. */
function bl_status_themen()
{
    return array(
        'present'   => array('Anwesend (1/0)', 'digital'),
        'rssi'      => array('Signalst&auml;rke in dBm, &minus;255 wenn nicht in Reichweite', 'analog'),
        'level'     => array('Signalstufe: 3 nah, 2 mittel, 1 schwach, 0 weg', 'analog'),
        'last_seen' => array('Sekunden seit der letzten Sichtung, &minus;1 wenn nie', 'analog'),
        'name'      => array('Bezeichnung des Tags', 'text'),
    );
}

/** Logdatei-Kandidaten. */
function bl_log_file()
{
    $c = glob(bl_paths()['logdir'] . '/*.log');
    if (!$c) {
        return '';
    }
    usort($c, function ($a, $b) { return filemtime($b) - filemtime($a); });
    return $c[0];
}

/** Die letzten N Zeilen einer Datei, neueste zuerst. */
function bl_log_tail($file, $max = 300)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $lines = preg_split('/\R/', (string) @file_get_contents($file));
    $lines = array_values(array_filter($lines, function ($l) { return trim($l) !== ''; }));
    return array_reverse(array_slice($lines, -$max));
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul
 * gibt es nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der
 * Tabulator vor den Kindelementen entsprechen dem Original.
 * ================================================================== */

function bl_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function bl_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . bl_x($kopf['title']) . '" ';
    $o .= 'Comment="' . bl_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . bl_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . bl_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . bl_x($c['title']) . '" ';
        $o .= 'Comment="' . bl_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . bl_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Vorlage der MQTT-Eingaenge erzeugen.
 * Rueckgabe: array(dateiname, inhalt)
 */
function bl_vorlage($cfg, $tags)
{
    $praefix = bl_cfg($cfg, 'themenpraefix', 'blescanner');
    $fuss = 'Erzeugt vom LoxBerry-Plugin BLE-Scanner (' . date('d.m.Y') . ')';
    $cmds = array(
        array('title' => $praefix . '_server_online',
              'comment' => 'Dienst laeuft', 'check' => ' '),
        array('title' => $praefix . '_summary_present',
              'comment' => 'Anzahl anwesender Tags', 'check' => ' '),
        array('title' => $praefix . '_summary_tags',
              'comment' => 'Anzahl konfigurierter Tags', 'check' => ' '),
    );
    foreach ($tags as $tag) {
        if ($tag['aktiv'] !== '1') {
            continue;
        }
        $t = bl_thema($tag['mac']);
        $bez = $tag['name'] !== '' ? $tag['name'] : $tag['mac'];
        foreach (bl_status_themen() as $schluessel => $info) {
            $cmds[] = array(
                'title'   => $praefix . '_' . $t . '_' . $schluessel,
                'comment' => $bez . ' - ' . strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')),
                'check'   => ' ',
            );
        }
    }
    return array('ble_scanner_eingaenge.xml', bl_xml_virtual_in_http(array(
        'title'   => 'BLE-Scanner',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => $fuss,
    ), $cmds));
}
