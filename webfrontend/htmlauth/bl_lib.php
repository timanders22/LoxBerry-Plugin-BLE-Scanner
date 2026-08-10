<?php
/**
 * BLE-Scanner NG - gemeinsame Hilfsfunktionen
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

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function bl_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home) {
        $home = lb_wurzel_ermitteln();
    }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(dirname(dirname(__DIR__)));
    }
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(basename(dirname(__DIR__)), 'ble_scanner_ng') as $cand) {
            if (is_dir($home . '/config/plugins/' . $cand)) {
                $dir = $cand;
                break;
            }
        }
    }
    // Zustands- und PID-Datei liegen beide auf der Ramdisk: nach einem
    // Neustart ist keine von beiden mehr gueltig, und genau so soll es sein.
    $shm    = is_dir('/run/shm');
    $status = $shm ? '/run/shm/ble_scanner_ng_status.json' : '/tmp/ble_scanner_ng_status.json';
    // Die PID-Datei liegt NICHT auf der Ramdisk, sondern im Datenverzeichnis.
    //
    // Sie muss dort liegen, wo daemon/daemon sie hinschreibt - und das ist
    // <datadir>/dienst.pid. Dieselbe Datei benutzen preupgrade.sh und
    // uninstall/uninstall. Stuende hier /run/shm/..., schriebe niemand dorthin,
    // und bl_dienst_pid() fiele bei JEDEM Aufruf auf die Namenssuche zurueck -
    // also genau auf den Weg, den die PID-Datei ersetzen soll.
    if ($home) {
        $datadir = $home . '/data/plugins/' . $dir;
        $p = array(
            'home'    => $home,
            'plugin'  => $dir,
            'config'  => $home . '/config/plugins/' . $dir . '/ble_scanner_ng.cfg',
            'bindir'  => $home . '/bin/plugins/' . $dir,
            'logdir'  => $home . '/log/plugins/' . $dir,
            'datadir' => $datadir,
            'status'  => $status,
            'pid'     => $datadir . '/dienst.pid',
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'    => '',
            'plugin'  => $dir,
            'config'  => $base . '/config/ble_scanner_ng.cfg',
            'bindir'  => $base . '/bin',
            'logdir'  => sys_get_temp_dir(),
            'datadir' => $base . '/data',
            'status'  => $status,
            'pid'     => $base . '/data/dienst.pid',
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
    $txt = "; BLE-Scanner NG\n; Geschrieben von der Plugin-Oberflaeche.\n\n[CONFIG]\n";
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

/**
 * Ablageort der PID-Datei.
 *
 * Hier stand bis 1.2.0
 *     return bl_paths()['datadir'] . '/dienst.pid';
 * - und bl_paths() hat gar keinen Schluessel 'datadir'. Herausgekommen ist
 * dadurch "/dienst.pid", also das WURZELVERZEICHNIS. Als Benutzer loxberry
 * ist das nicht beschreibbar, das
 *     echo $! > /dienst.pid
 * beim Start lief also jedes Mal ins Leere, ohne dass es jemand gemerkt
 * haette: bl_dienst_pid() faellt still auf die pgrep-Suche zurueck, und die
 * findet den Dienst ja. Der PID-Weg, der eigentlich der massgebliche sein
 * sollte, ist damit seit jeher tot gewesen.
 *
 * Unter PHP 7.4 war der Zugriff auf den fehlenden Schluessel eine Notice und
 * wurde vom error_reporting der Oberflaeche verschluckt; unter PHP 8 ist es
 * eine Warning - so ist es beim Rendern gegen beide Fassungen aufgefallen.
 */
function bl_pid_datei()
{
    return bl_paths()['pid'];
}

/**
 * Laeuft der Dienst? Rueckgabe: PID oder 0.
 *
 * Zuerst die PID-Datei, und die eingetragene Nummer wird gegen
 * /proc/<pid>/cmdline gehalten. Erst wenn es keine Datei gibt, wird gesucht -
 * das ist die Rueckfallebene fuer einen Dienst, der noch vor 1.2.0 gestartet
 * wurde und deshalb keine Datei geschrieben hat.
 *
 * Warum nicht einfach pgrep? 'pgrep -f ble_scanner_ng.py' trifft jede
 * Befehlszeile, in der diese Zeichenkette vorkommt: ein Editor, der die
 * Datei offen hat, ein 'grep ble_scanner_ng.py', ein 'less' auf dem Quelltext.
 * '-o' nimmt davon den AELTESTEN Treffer - also womoeglich genau den
 * fremden. Die Oberflaeche zeigte dann einen laufenden Dienst, den es nicht
 * gibt, und 'pkill -f' haette den fremden Prozess erwischt.
 *
 * Die Klammer in '[b]le_scanner.py' verhindert, dass das Suchmuster sich
 * selbst findet - pgrep sieht seine eigene Befehlszeile nicht, aber ein
 * gleichzeitig laufendes zweites pgrep schon.
 */
function bl_dienst_pid()
{
    $datei = bl_pid_datei();
    if (is_file($datei)) {
        $pid = (int) trim((string) @file_get_contents($datei));
        if ($pid > 0 && is_dir('/proc/' . $pid)) {
            $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
            if (strpos($cmd, 'ble_scanner_ng.py') !== false) {
                return $pid;
            }
        }
        // Die Datei zeigt ins Leere - sie ist von einem Absturz uebrig.
        @unlink($datei);
    }
    // Rueckfallebene ohne pgrep. Bis 1.2.0 stand hier
    //     pgrep -o -f "[b]le_scanner.py"
    // und danach eine Teilstringsuche in /proc/<pid>/cmdline. Die Klammer
    // verhindert nur, dass die Suche sich selbst findet - einen Editor mit
    // offener Datei oder ein zweites Exemplar des Plugins (LoxBerry haengt
    // bei Namenskonflikt 01, 02 ... an den Ordnernamen) trifft sie sehr wohl.
    //
    // Verglichen wird jetzt argumentweise gegen den VOLLEN Pfad: cmdline
    // trennt die Argumente mit Nullbytes. Ein Treffer liegt vor, wenn das
    // erste Argument das Skript ist (Shebang-Start) oder wenn das erste
    // Argument ein Python-Interpreter ist und der volle Pfad unter den
    // Argumenten steht.
    $skript = bl_paths()['bindir'] . '/ble_scanner_ng.py';
    foreach ((array) @scandir('/proc') as $eintrag) {
        if (!ctype_digit((string) $eintrag)) {
            continue;
        }
        $roh = @file_get_contents('/proc/' . $eintrag . '/cmdline');
        if ($roh === false || $roh === '') {
            continue;
        }
        $args = explode("\0", $roh);
        $erstes = isset($args[0]) ? $args[0] : '';
        if ($erstes === $skript
            || (strpos(basename($erstes), 'python') === 0 && in_array($skript, $args, true))) {
            return (int) $eintrag;
        }
    }
    return 0;
}

/** Dienst starten, stoppen, neu starten. */
function bl_dienst($aktion)
{
    $p = bl_paths();
    $skript = $p['bindir'] . '/ble_scanner_ng.py';
    $datei = bl_pid_datei();
    $meldungen = array();

    if (in_array($aktion, array('stop', 'restart'), true)) {
        $pid = bl_dienst_pid();
        if ($pid > 0) {
            // Gezielt an DIESE Nummer, nicht an ein Namensmuster.
            @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            // Dem Dienst Zeit lassen: er nimmt die Suche zurueck und meldet
            // server/online=0 an den Broker. Wird er hart abgeschossen,
            // bleibt im Broker ein retained '1' stehen und Loxone glaubt
            // weiter an einen laufenden Scanner.
            for ($i = 0; $i < 20; $i++) {
                usleep(500000);
                if (bl_dienst_pid() === 0) {
                    break;
                }
            }
            if (bl_dienst_pid() === $pid) {
                @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
                usleep(500000);
                $meldungen[] = 'Der Dienst reagierte nicht auf SIGTERM und wurde abgeschossen.';
            }
        } else {
            $meldungen[] = 'Es lief kein Dienst.';
        }
        @unlink($datei);
    }

    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return 'Dienst nicht gefunden: ' . $skript;
        }
        $log = $p['logdir'] . '/ble_scanner_ng.log';
        @mkdir(dirname($datei), 0775, true);
        // Die Prozessnummer wird von der Shell weggeschrieben, nicht geraten.
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo $! > ' . escapeshellarg($datei) . '; echo gestartet', $meldungen);
        for ($i = 0; $i < 12; $i++) {
            usleep(500000);
            if (bl_dienst_pid() > 0) {
                break;
            }
        }
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
    $fuss = 'Erzeugt vom LoxBerry-Plugin BLE-Scanner NG (' . date('d.m.Y') . ')';
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
    return array('ble_scanner_ng_eingaenge.xml', bl_xml_virtual_in_http(array(
        'title'   => 'BLE-Scanner NG',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => $fuss,
    ), $cmds));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function bl_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function bl_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . bl_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
