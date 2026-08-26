<?php
/**
 * BLE-Scanner NG - gemeinsame Hilfsfunktionen
 *
 * Die Konfiguration liegt im selben Format, das bin/bl_common.py liest.
 * Beide Seiten muessen sich hier einig sein, sonst verliert die Oberflaeche
 * beim Speichern die Tags.
 *
 * WAS BIS 1.2.10 FALSCH WAR - und warum es hier so ausfuehrlich steht:
 * bl_common.py hat in 1.2.0 zwei Fehler des Konfigurationslesers behoben und
 * die Begruendung in zwei langen Kommentaren festgehalten. Behoben wurden sie
 * NUR in Python. Diese Datei trug beide weiter:
 *
 *   1. Die Formaterkennung entschied am Vorhandensein eines Doppelpunkts
 *      ohne Strich. Eine von Hand geschriebene Zeile "tag1=AA:BB:CC:DD:EE:FF"
 *      landete damit im Zweig fuer das alte Format - und verschwand.
 *   2. explode('|', $wert) ohne Obergrenze kuerzte einen Kommentar mit einem
 *      senkrechten Strich.
 *
 * Gemessen am 20.08.2026: ein Klick auf "Speichern", ohne irgendetwas zu
 * aendern, loeschte einen Tag und benannte einen zweiten um. Seit 1.3.0
 * gibt es eine gemeinsame Pruefreihe (bl_test.php, "Beide
 * Konfigurationsleser"), die beide Seiten an denselben Faellen misst.
 *
 * Eigenes Praefix "bl_", weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('bl_e')) {
    function bl_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen. */
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
    // Zustands- und Steuerdatei liegen auf der Ramdisk: nach einem Neustart
    // ist keine von beiden mehr gueltig, und genau so soll es sein.
    $shm    = is_dir('/run/shm') ? '/run/shm' : '/tmp';
    // Die PID-Datei liegt NICHT auf der Ramdisk, sondern im Datenverzeichnis -
    // dort, wo daemon/daemon sie hinschreibt. Dieselbe Datei benutzen
    // preupgrade.sh und uninstall/uninstall.
    if ($home) {
        $datadir = $home . '/data/plugins/' . $dir;
        $configdir = $home . '/config/plugins/' . $dir;
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $configdir,
            'config'    => $configdir . '/ble_scanner_ng.cfg',
            'fassung'   => $configdir . '/fassung.txt',
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'datadir'   => $datadir,
            'verlauf'   => $datadir . '/verlauf.csv',
            'status'    => $shm . '/ble_scanner_ng_status.json',
            'steuer'    => $shm . '/ble_scanner_ng_steuer.json',
            'pid'       => $datadir . '/dienst.pid',
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $base . '/config',
            'config'    => $base . '/config/ble_scanner_ng.cfg',
            'fassung'   => $base . '/config/fassung.txt',
            'bindir'    => $base . '/bin',
            'logdir'    => sys_get_temp_dir(),
            'datadir'   => $base . '/data',
            'verlauf'   => $base . '/data/verlauf.csv',
            'status'    => $shm . '/ble_scanner_ng_status.json',
            'steuer'    => $shm . '/ble_scanner_ng_steuer.json',
            'pid'       => $base . '/data/dienst.pid',
        );
    }
    return $p;
}

/**
 * Fassungsnummer - EINE Quelle, dieselbe wie in bl_common.py.
 *
 * postinstall.sh schreibt die Nummer, die der Installer uebergibt, nach
 * <configdir>/fassung.txt. Fehlt sie (nicht installiert), wird der
 * Rueckfallwert aus bl_common.py gelesen - nicht hier noch einmal getippt.
 * Bis 1.2.10 standen drei verschiedene Nummern im selben Archiv.
 */
function bl_fassung()
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $p = bl_paths();
    if (is_file($p['fassung'])) {
        $wert = trim((string) @file_get_contents($p['fassung']));
        if (preg_match('/^\d+(\.\d+){0,3}$/', $wert)) {
            return $f = $wert;
        }
    }
    $py = $p['bindir'] . '/bl_common.py';
    if (is_file($py) && preg_match('/VERSION_RUECKFALL\s*=\s*"([0-9.]+)"/',
                                   (string) @file_get_contents($py), $m)) {
        return $f = $m[1];
    }
    return $f = '';
}

/**
 * Voreinstellungen.
 *
 * MUSS Schluessel fuer Schluessel zu VORGABEN in bin/bl_common.py passen -
 * bl_config_write() baut die Datei vollstaendig hieraus neu auf, ein
 * Schluessel, den nur die Python-Seite kennt, waere nach dem naechsten
 * Speichern weg. Der Reiter Test misst die Uebereinstimmung nach.
 */
function bl_defaults()
{
    return array(
        'adapter'            => 'hci0',
        'themenpraefix'      => 'blescanner',
        'mqtt'               => '1',
        'http_push'          => '0',
        'loxberry_id'        => '',
        'intervall'          => '5',
        'abwesenheit_nach'   => '30',
        'aktualisierung'     => '60',
        'rssi_nah'           => '-65',
        'rssi_mittel'        => '-85',
        'rssi_minimum'       => '-100',
        'ankunft_sichtungen' => '1',
        'glaettung'          => '1',
        'glaettung_fenster'  => '5',
        'hysterese_db'       => '3',
        'betriebsart'        => 'signal',
        'wachhund'           => '1',
        'wachhund_stille'    => '300',
        'discovery_rssi'     => '0',
        'log_kappung_kb'     => '500',
        'ereignisse'         => '1',
        'ereignisse_tage'    => '7',
        'scanner_name'       => '',
        'scanner_themen'     => '0',
        'raum'               => '0',
        'raum_hysterese_db'  => '5',
        'raum_ausgleich_db'  => '0',
        'entfernung'         => '0',
        'daempfung'          => '2.5',
        'beacon'             => '1',
        'batterie'           => '0',
        'batterie_uhrzeit'   => '04:00',
    );
}

/** Erlaubte Zusatzangaben je Tag - dieselbe Liste wie TAG_OPTIONEN in Python. */
function bl_tag_optionen()
{
    return array('abw', 'min', 'ref', 'alias', 'person', 'batt', 'raum');
}

/**
 * Eine Zeichenkette kuerzen, ohne mbstring vorauszusetzen.
 *
 * Gemessen am 20.08.2026: ein Seitenaufbau ohne geladenes mbstring endete
 * mit "Call to undefined function mb_substr()" - einem FATAL mitten in der
 * Selbstpruefung, also genau in dem Teil, der immer mitlaeuft. Die halbe
 * Seite fehlte danach, einschliesslich des Reiters Logdateien, in dem man
 * die Ursache haette nachlesen koennen.
 *
 * REGELN_2 fuehrt eigens, dass php-mbstring auf einem PHP-7.4-LoxBerry das
 * falsche Paket ist. Ein Plugin, das ohne die Erweiterung auskommt, muss sie
 * also auch nicht verlangen.
 *
 * Der Rueckfall nutzt PCRE im UTF-8-Modus; das ist Bestandteil jeder
 * PHP-Installation. Erst wenn auch das scheitert, wird byteweise gekuerzt.
 */
function bl_kuerzen($s, $n)
{
    $s = (string) $s;
    $n = (int) $n;
    if ($n <= 0) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $n, 'UTF-8');
    }
    if (preg_match('/^.{0,' . $n . '}/us', $s, $m)) {
        return $m[0];
    }
    return substr($s, 0, $n);
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

/** iBeacon-Kennung normieren: IB:<32 Hex>:<major>:<minor>, sonst leer. */
function bl_ibeacon($wert)
{
    $wert = trim((string) $wert);
    if (!preg_match('/^ib[:_-]/i', $wert)) {
        return '';
    }
    $rest = preg_replace('/^ib[:_-]/i', '', $wert);
    $teile = preg_split('/[:_\-\s]+/', trim($rest));
    $uuid = preg_replace('/[^0-9A-Fa-f]/', '', isset($teile[0]) ? $teile[0] : '');
    if (strlen($uuid) !== 32) {
        return '';
    }
    $major = (isset($teile[1]) && $teile[1] !== '') ? (int) $teile[1] : 0;
    $minor = (isset($teile[2]) && $teile[2] !== '') ? (int) $teile[2] : 0;
    if ($major < 0 || $major > 65535 || $minor < 0 || $minor > 65535) {
        return '';
    }
    return 'IB:' . strtoupper($uuid) . ':' . $major . ':' . $minor;
}

/** Erstes Feld einer Tag-Zeile deuten: array(art, kennung). */
function bl_kennung($wert)
{
    $ib = bl_ibeacon($wert);
    if ($ib !== '') {
        return array('ibeacon', $ib);
    }
    $mac = bl_mac($wert);
    if ($mac !== '') {
        return array('mac', $mac);
    }
    return array('', '');
}

/** Namen in ein MQTT-taugliches Thema umformen (wie thema_saeubern in Python). */
function bl_saeubern($name)
{
    $ersetzungen = array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
                         'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss');
    $name = strtr((string) $name, $ersetzungen);
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
    return trim($name, '_');
}

/** Themenzweig eines Tags. Ein Alias gewinnt vor der Kennung. */
function bl_thema_der_kennung($art, $kennung, $alias = '')
{
    $alias = bl_saeubern(trim((string) $alias));
    if ($alias !== '') {
        return $alias;
    }
    if ($art === 'ibeacon') {
        $teile = explode(':', $kennung);
        if (count($teile) === 4) {
            return 'ib_' . strtolower(substr($teile[1], -8)) . '_' . $teile[2] . '_' . $teile[3];
        }
        return bl_saeubern($kennung);
    }
    $t = bl_saeubern(str_replace(':', '', (string) $kennung));
    return $t !== '' ? $t : 'unbekannt';
}

/** Themenzweig eines gelesenen Tags. */
function bl_thema($tag)
{
    $alias = isset($tag['opt']['alias']) ? $tag['opt']['alias'] : '';
    return bl_thema_der_kennung($tag['art'], $tag['kennung'], $alias);
}

/** Sieht dieses Feld nach Zusatzangaben aus? (wie sind_optionen in Python) */
function bl_sind_optionen($text)
{
    return (bool) preg_match('/^\s*(' . implode('|', bl_tag_optionen()) . ')\s*=/i',
                             (string) $text);
}

/** Viertes Feld zerlegen: "abw=90,alias=anna". */
function bl_optionen_lesen($text)
{
    $out = array();
    $erlaubt = bl_tag_optionen();
    foreach (explode(',', (string) $text) as $stueck) {
        $stueck = trim($stueck);
        if ($stueck === '' || strpos($stueck, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $stueck, 2);
        $k = strtolower(trim($k));
        if (in_array($k, $erlaubt, true)) {
            $out[$k] = trim($v);
        }
    }
    return $out;
}

/** Umkehrung. Trennzeichen werden aus den Werten entfernt. */
function bl_optionen_schreiben($opt)
{
    $teile = array();
    foreach (bl_tag_optionen() as $k) {
        $v = isset($opt[$k]) ? (string) $opt[$k] : '';
        $v = trim(preg_replace('/[,=|\r\n]/', '', $v));
        if ($v !== '') {
            $teile[] = $k . '=' . $v;
        }
    }
    return implode(',', $teile);
}

/**
 * Konfiguration lesen. Erkennt das alte Format des Originalplugins mit.
 * Rueckgabe: array($werte, $tags, $altesFormat)
 *
 * Ein Tag ist array('art','kennung','mac','aktiv','name','opt').
 */
function bl_config_read($datei = null)
{
    $werte = bl_defaults();
    $tags = array();
    $alt = false;
    $file = $datei !== null ? $datei : bl_paths()['config'];
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

        $istTag = (bool) preg_match('/^(default\.)?TAG\d+$/i', $schluessel);
        // Die Entscheidung faellt an der FORM des Wertes, nicht am
        // Vorhandensein eines Strichs - genau wie in bl_common.py.
        list($vorabArt, ) = bl_kennung($wert);
        $neuesFormat = $istTag && (strpos($wert, '|') !== false || $vorabArt !== '');

        // altes Format: TAG1=BLE_..:on:1^on~2^off:Kommentar
        if ($istTag && !$neuesFormat && strpos($wert, ':') !== false) {
            $alt = true;
            $teile = explode(':', $wert);
            $mac = bl_mac($teile[0]);
            $aktiv = (isset($teile[1]) && in_array(strtolower(trim($teile[1])),
                     array('on', '1', 'true'), true)) ? '1' : '0';
            $name = count($teile) > 3 ? trim(implode(':', array_slice($teile, 3))) : '';
            if ($mac !== '') {
                $tags[] = array('art' => 'mac', 'kennung' => $mac, 'mac' => $mac,
                                'aktiv' => $aktiv, 'name' => $name, 'opt' => array());
            }
            continue;
        }

        // neues Format: tag1=AA:BB:..|1|Name|abw=90
        if ($neuesFormat) {
            // Obergrenze 4: alles hinter dem dritten Strich gehoert zu den
            // Zusatzangaben - und nur dann, wenn es auch danach AUSSIEHT.
            $teile = explode('|', $wert, 4);
            list($art, $kennung) = bl_kennung(isset($teile[0]) ? $teile[0] : '');
            $aktiv = isset($teile[1]) ? trim($teile[1]) : '1';
            $name = isset($teile[2]) ? $teile[2] : '';
            $viertes = isset($teile[3]) ? $teile[3] : '';
            $opt = array();
            if ($viertes !== '' && bl_sind_optionen($viertes)) {
                $opt = bl_optionen_lesen($viertes);
            } elseif ($viertes !== '') {
                $name .= '|' . $viertes;
            }
            if ($art !== '') {
                $tags[] = array(
                    'art' => $art,
                    'kennung' => $kennung,
                    'mac' => $art === 'mac' ? $kennung : '',
                    'aktiv' => in_array($aktiv, array('1', 'on', 'true'), true) ? '1' : '0',
                    'name' => trim($name),
                    'opt' => $opt,
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

/**
 * Eine Datei unteilbar schreiben: Rechte VOR dem Inhalt, dann umbenennen.
 *
 * Bis 1.2.10 ging file_put_contents() unmittelbar auf die Zieldatei und das
 * chmod DANACH. Der Dienst liest dieselbe Datei; ein Lesevorgang mitten im
 * Schreiben sah eine halbe Datei - und konfiguration_lesen() liefert dann
 * stillschweigend weniger Tags.
 */
function bl_datei_schreiben($ziel, $inhalt, $rechte = 0640)
{
    $temp = $ziel . '.' . getmypid() . '.tmp';
    $fh = @fopen($temp, 'wb');
    if ($fh === false) {
        return false;
    }
    @chmod($temp, $rechte);
    $ok = @fwrite($fh, $inhalt) !== false;
    @fflush($fh);
    @fclose($fh);
    if (!$ok) {
        @unlink($temp);
        return false;
    }
    if (!@rename($temp, $ziel)) {
        @unlink($temp);
        return false;
    }
    return true;
}

/**
 * Konfiguration schreiben - Format wie bl_common.py es erwartet.
 *
 * Rechte 0640: in der Datei stehen MAC-Adressen und die Namen der
 * ueberwachten Personen, also eine Anwesenheitsliste des Haushalts.
 * preupgrade.sh sichert dieselben Daten mit 0600 und begruendet das
 * ausfuehrlich; bis 1.2.10 lag das Original mit 0644 daneben.
 */
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
        $art = isset($tag['art']) ? $tag['art'] : 'mac';
        $kennung = isset($tag['kennung']) ? $tag['kennung'] : '';
        if ($art === 'ibeacon') {
            $kennung = bl_ibeacon($kennung);
        } else {
            $art = 'mac';
            $kennung = bl_mac($kennung);
        }
        if ($kennung === '') {
            continue;
        }
        $nr++;
        $name = str_replace(array("\r", "\n", '|'), array('', ' ', '/'),
                            (string) (isset($tag['name']) ? $tag['name'] : ''));
        $zeile = $kennung . '|' . (($tag['aktiv'] === '1') ? '1' : '0') . '|' . trim($name);
        $opt = bl_optionen_schreiben(isset($tag['opt']) ? $tag['opt'] : array());
        if ($opt !== '') {
            $zeile .= '|' . $opt;
        }
        $txt .= 'tag' . $nr . '=' . $zeile . "\n";
    }
    return bl_datei_schreiben($file, $txt, 0640);
}

/** Zustandsdatei des Dienstes lesen. */
function bl_status()
{
    static $s = false;
    if ($s !== false) {
        return $s;
    }
    $f = bl_paths()['status'];
    if (!is_file($f)) {
        return $s = null;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    return $s = (is_array($j) ? $j : null);
}

/**
 * Die Tag-Zustaende aus dem Abbild, nach Kennung.
 *
 * Auch ein Abbild einer AELTEREN Fassung muss lesbar bleiben. Nach einem
 * Update liegt es noch auf der Ramdisk, waehrend die neue Oberflaeche schon
 * laeuft - und bis 1.2.10 hiess das Feld dort "mac", nicht "kennung".
 * Gemessen beim Rendern gegen ein altes Abbild: "Undefined array key
 * kennung". Ein fehlendes Feld ist hier kein Fehler, sondern der Normalfall
 * fuer die Dauer eines Durchlaufs.
 */
function bl_zustaende()
{
    $s = bl_status();
    $out = array();
    if (!$s || empty($s['tags']) || !is_array($s['tags'])) {
        return $out;
    }
    foreach ($s['tags'] as $z) {
        if (!is_array($z)) {
            continue;
        }
        $k = '';
        foreach (array('kennung', 'mac') as $feld) {
            if (!empty($z[$feld])) { $k = (string) $z[$feld]; break; }
        }
        if ($k === '') {
            continue;
        }
        // Fehlende Felder auffuellen, damit die Anzeige nicht je Stelle
        // pruefen muss.
        $z += array('kennung' => $k, 'mac' => $k, 'name' => '', 'aktiv' => '1',
                    'anwesend' => 0, 'rssi' => null, 'rssi_avg' => null,
                    'stufe' => 0, 'seit' => null, 'zuletzt' => 0,
                    'seit_zeit' => 0, 'adresstyp' => 'unbekannt',
                    'batterie' => null, 'raum' => '', 'opt' => array(),
                    'sensor' => array(), 'beaconart' => '', 'zweig' => '');
        $out[$k] = $z;
    }
    return $out;
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
 * Wie lange ist die letzte SICHTUNG her? -1 = keine Angabe.
 *
 * Das ist die Groesse, an der ein haengendes BlueZ zu erkennen ist. Die
 * Zustandsdatei allein taugt dafuer nicht: sie wird in jedem Durchlauf
 * geschrieben, auch wenn der Adapter seit Stunden nichts mehr hoert. Bis
 * 1.2.10 hing die Warnung "haengendes BlueZ" genau daran und konnte
 * deshalb nie ansprechen.
 */
function bl_stille()
{
    $s = bl_status();
    if (!$s || empty($s['letzte_sichtung'])) {
        return -1;
    }
    return max(0, time() - (int) $s['letzte_sichtung']);
}

/** Ablageort der PID-Datei. */
function bl_pid_datei()
{
    return bl_paths()['pid'];
}

/**
 * Laeuft der Dienst? Rueckgabe: PID oder 0.
 *
 * Zuerst die PID-Datei, und die eingetragene Nummer wird gegen
 * /proc/<pid>/cmdline gehalten. Erst wenn es keine Datei gibt, wird gesucht.
 *
 * Warum nicht pgrep: 'pgrep -f ble_scanner_ng.py' trifft jede Befehlszeile,
 * in der diese Zeichenkette vorkommt - ein Editor, ein 'less' auf dem
 * Quelltext. '-o' nimmt davon den AELTESTEN Treffer. Verglichen wird deshalb
 * argumentweise gegen den VOLLEN Pfad.
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
            @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            // Dem Dienst Zeit lassen: er nimmt die Suche zurueck und meldet
            // server/online=0 an den Broker. Wird er hart abgeschossen,
            // bleibt im Broker ein retained '1' stehen.
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
        @mkdir($p['logdir'], 0775, true);
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

/**
 * Einen Auftrag an den laufenden Dienst geben, OHNE ihn neu zu starten.
 *
 * Die Oberflaeche erreicht den Dienst sonst nur ueber die
 * Konfigurationsdatei - und deren Aenderung loest einen Neustart aus. Fuer
 * Testmodus und Kalibrierung ist das gerade nicht erwuenscht.
 */
function bl_steuern($art, $kennung = '', $dauer = 0)
{
    $daten = array('art' => (string) $art, 'kennung' => (string) $kennung,
                   'dauer' => (int) $dauer, 'zeit' => time());
    $json = json_encode($daten, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return bl_datei_schreiben(bl_paths()['steuer'], $json, 0640);
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

/**
 * Steht das MQTT-Gateway auf Autostart?
 * true / false / null (keine Angabe).
 *
 * Der Schluessel heisst Gatewayautostart. Ein 'Mqtt.Autostart' gibt es
 * nicht - eine Pruefung darauf warnt immer.
 */
function bl_mqtt_autostart()
{
    $f = bl_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return null;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || !isset($j['Mqtt'])) {
        return null;
    }
    return !empty($j['Mqtt']['Gatewayautostart']);
}

/** Miniserver aus general.json - fuer die Anzeige und den Probewert. */
function bl_miniserver()
{
    $f = bl_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return array();
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || empty($j['Miniserver'])) {
        return array();
    }
    $out = array();
    foreach ($j['Miniserver'] as $nr => $ms) {
        if (!is_array($ms)) {
            continue;
        }
        $adresse = '';
        foreach (array('Ipaddress', 'IPAddress') as $k) {
            if (!empty($ms[$k])) { $adresse = $ms[$k]; break; }
        }
        if ($adresse === '') {
            continue;
        }
        $out[] = array(
            'nr' => (string) $nr,
            'name' => !empty($ms['Name']) ? $ms['Name'] : ('Miniserver ' . $nr),
            'adresse' => $adresse,
            'port' => !empty($ms['Port']) ? (int) $ms['Port'] : 80,
            'user' => !empty($ms['Admin']) ? $ms['Admin'] : (!empty($ms['Username']) ? $ms['Username'] : ''),
            'pass' => !empty($ms['Pass']) ? $ms['Pass'] : (!empty($ms['Password']) ? $ms['Password'] : ''),
        );
    }
    return $out;
}

/**
 * Zustandsthemen je Tag.
 *
 * Die Beschreibung steht als SPRACHSCHLUESSEL da, nicht als deutscher Satz.
 * Bis 1.2.10 standen hier feste deutsche Texte - und die landeten nicht nur
 * in der Tabelle des Reiters, sondern als Comment auch in der erzeugten
 * Loxone-Vorlage. Ein englischer Anwender importierte damit deutsche
 * Kommentare in seine Loxone-Konfiguration.
 *
 * 'art'     digital | analog | text
 * 'min'/'max'  echte Grenzen. Loxone zieht daraus Reglergrenzen und
 *              Plausibilitaetspruefung; pauschale +-2147483647 verschenken
 *              beides.
 * 'einheit' erscheint als Unit="<v.1> ..." in der Vorlage.
 */
function bl_status_themen()
{
    return array(
        'present'       => array('s' => 'THEMA.PRESENT',       'art' => 'digital', 'min' => 0,    'max' => 1,          'einheit' => ''),
        'rssi'          => array('s' => 'THEMA.RSSI',          'art' => 'analog',  'min' => -255, 'max' => 0,          'einheit' => 'dBm'),
        'rssi_avg'      => array('s' => 'THEMA.RSSI_AVG',      'art' => 'analog',  'min' => -255, 'max' => 0,          'einheit' => 'dBm'),
        'level'         => array('s' => 'THEMA.LEVEL',         'art' => 'analog',  'min' => 0,    'max' => 3,          'einheit' => ''),
        'last_seen'     => array('s' => 'THEMA.LAST_SEEN',     'art' => 'analog',  'min' => -1,   'max' => 2592000,    'einheit' => 's'),
        'last_seen_ts'  => array('s' => 'THEMA.LAST_SEEN_TS',  'art' => 'analog',  'min' => 0,    'max' => 2147483647, 'einheit' => ''),
        'present_since' => array('s' => 'THEMA.PRESENT_SINCE', 'art' => 'analog',  'min' => 0,    'max' => 2147483647, 'einheit' => ''),
        'name'          => array('s' => 'THEMA.NAME',          'art' => 'text',    'min' => 0,    'max' => 0,          'einheit' => ''),
    );
}

/** Zusaetzliche Themen, die nur bei eingeschalteter Einstellung kommen. */
function bl_zusatzthemen($cfg)
{
    $out = array();
    if (bl_cfg($cfg, 'entfernung', '0') === '1') {
        $out['distance'] = array('s' => 'THEMA.DISTANCE', 'art' => 'analog',
                                 'min' => -1, 'max' => 1000, 'einheit' => 'm');
    }
    if (bl_cfg($cfg, 'batterie', '0') === '1') {
        $out['battery'] = array('s' => 'THEMA.BATTERY', 'art' => 'analog',
                                'min' => 0, 'max' => 100, 'einheit' => '%');
        $out['battery_ts'] = array('s' => 'THEMA.BATTERY_TS', 'art' => 'analog',
                                   'min' => 0, 'max' => 2147483647, 'einheit' => '');
    }
    if (bl_cfg($cfg, 'raum', '0') === '1') {
        $out['raum'] = array('s' => 'THEMA.RAUM', 'art' => 'text',
                             'min' => 0, 'max' => 0, 'einheit' => '');
        $out['raum_seit'] = array('s' => 'THEMA.RAUM_SEIT', 'art' => 'analog',
                                  'min' => 0, 'max' => 2147483647, 'einheit' => '');
    }
    return $out;
}

/** Allgemeine Themen (nicht je Tag). */
function bl_allgemeine_themen()
{
    return array(
        'server/online'         => array('s' => 'THEMA.SRV_ONLINE',   'art' => 'digital', 'min' => 0, 'max' => 1, 'einheit' => ''),
        'server/ok'             => array('s' => 'THEMA.SRV_OK',       'art' => 'digital', 'min' => 0, 'max' => 1, 'einheit' => ''),
        'server/ts'             => array('s' => 'THEMA.SRV_TS',       'art' => 'analog',  'min' => 0, 'max' => 2147483647, 'einheit' => ''),
        'server/adapter_ok'     => array('s' => 'THEMA.SRV_ADAPTER',  'art' => 'digital', 'min' => 0, 'max' => 1, 'einheit' => ''),
        'server/letzte_sichtung' => array('s' => 'THEMA.SRV_SICHT',   'art' => 'analog',  'min' => 0, 'max' => 2147483647, 'einheit' => ''),
        'summary/present'       => array('s' => 'THEMA.SUM_PRESENT',  'art' => 'analog',  'min' => 0, 'max' => 999, 'einheit' => ''),
        'summary/tags'          => array('s' => 'THEMA.SUM_TAGS',     'art' => 'analog',  'min' => 0, 'max' => 999, 'einheit' => ''),
        'summary/tags_gesamt'   => array('s' => 'THEMA.SUM_GESAMT',   'art' => 'analog',  'min' => 0, 'max' => 999, 'einheit' => ''),
        'summary/names'         => array('s' => 'THEMA.SUM_NAMES',    'art' => 'text',    'min' => 0, 'max' => 0,   'einheit' => ''),
        'server/version'        => array('s' => 'THEMA.SRV_VERSION',  'art' => 'text',    'min' => 0, 'max' => 0,   'einheit' => ''),
        'server/scanner'        => array('s' => 'THEMA.SRV_SCANNER',  'art' => 'text',    'min' => 0, 'max' => 0,   'einheit' => ''),
    );
}

/**
 * Welche Themen veroeffentlicht der Sendecode WIRKLICH?
 *
 * Gelesen wird aus bin/ble_scanner_ng.py, aus den _senden()-Aufrufen. Die
 * Themenliste dieser Datei ist die ANLEITUNG - und eine Anleitung, die
 * niemand nachmisst, laeuft auseinander. Genau das ist in der
 * Renault-Linie passiert: fuenf Themen in der Anleitung, die der Sendecode
 * nie veroeffentlicht hat, und zwanzig gesendete, die nirgends standen.
 *
 * Rueckgabe: Liste der Unterthemen-Muster.
 */
function bl_gesendete_themen()
{
    $datei = bl_paths()['bindir'] . '/ble_scanner_ng.py';
    if (!is_file($datei)) {
        return null;
    }
    $quelle = (string) @file_get_contents($datei);
    if ($quelle === '') {
        return null;
    }
    $out = array();
    // self._senden("...")  und  self.senden("...")  und  self.mqtt.senden("...")
    if (preg_match_all('/\b_?senden\(\s*"([^"]+)"/', $quelle, $m)) {
        foreach ($m[1] as $roh) {
            // "{0}/present" -> "present" ; "server/ts" -> "server/ts"
            $roh = str_replace(array('{0}/', '{1}'), array('', ''), $roh);
            $roh = preg_replace('/^\{[0-9]\}\//', '', $roh);
            $out[$roh] = true;
        }
    }
    if (preg_match_all('/\b_?senden\(\s*"([^"]*)"\.format/', $quelle, $m2)) {
        foreach ($m2[1] as $roh) {
            $roh = preg_replace('/^\{0\}\//', '', $roh);
            $out[$roh] = true;
        }
    }
    return array_keys($out);
}

/** Logdatei-Kandidaten. */
function bl_log_file()
{
    $c = glob(bl_paths()['logdir'] . '/*.log');
    if (!$c) {
        return '';
    }
    usort($c, function ($a, $b) {
        $fa = @filemtime($a); $fb = @filemtime($b);
        return ($fb ?: 0) - ($fa ?: 0);
    });
    return $c[0];
}

/**
 * Die letzten N Zeilen einer Datei, neueste zuerst - rueckwaerts gelesen.
 *
 * Bis 1.2.10 wurde die GANZE Datei eingelesen und zerlegt, um 300 Zeilen zu
 * zeigen. Die Hausregel hat das siebenmal nachgemessen: file() kostet bei
 * 610 kB rund 2 MB Speicher, exec("tail") einen Prozessstart, das
 * Rueckwaertslesen mit fseek 0,05 ms und 0 kB. Zusammen mit einer
 * ungekappten Datei auf einer Ramdisk war der alte Weg die schlechteste
 * aller Moeglichkeiten.
 */
function bl_log_ende($datei, $max = 300)
{
    if ($datei === '' || !is_file($datei)) {
        return array();
    }
    $fh = @fopen($datei, 'rb');
    if ($fh === false) {
        return array();
    }
    $block = 8192;
    $puffer = '';
    $pos = 0;
    @fseek($fh, 0, SEEK_END);
    $groesse = ftell($fh);
    $zeilen = array();
    while ($pos < $groesse) {
        $lese = (int) min($block, $groesse - $pos);
        $pos += $lese;
        @fseek($fh, $groesse - $pos, SEEK_SET);
        $puffer = fread($fh, $lese) . $puffer;
        $zeilen = preg_split('/\R/', $puffer);
        if (count($zeilen) > $max + 1) {
            break;
        }
    }
    @fclose($fh);
    $zeilen = array_values(array_filter($zeilen, function ($l) { return trim($l) !== ''; }));
    return array_reverse(array_slice($zeilen, -$max));
}

/**
 * Verlauf lesen und je Zweig auswerten.
 *
 * Damit beantwortet das Plugin die Frage, die jeder nach einer Woche hat:
 * ist "Abwesend nach" richtig eingestellt? Ohne diese Zahlen kann das
 * niemand wissen - und der Reiter "Einbindung" empfiehlt eine
 * Ausschaltverzoegerung, ohne eine Zahl nennen zu koennen.
 */
function bl_verlauf_lesen($stunden = 24, $hoechstens = 20000)
{
    $datei = bl_paths()['verlauf'];
    $out = array('zeilen' => array(), 'je_zweig' => array(), 'vorhanden' => false);
    if (!is_file($datei)) {
        return $out;
    }
    $out['vorhanden'] = true;
    $ab = time() - $stunden * 3600;
    $fh = @fopen($datei, 'rb');
    if ($fh === false) {
        return $out;
    }
    $n = 0;
    while (($zeile = fgets($fh)) !== false) {
        if (++$n > $hoechstens) {
            break;
        }
        $zeile = rtrim($zeile, "\r\n");
        if ($zeile === '' || strpos($zeile, 'zeit;') === 0) {
            continue;
        }
        $t = explode(';', $zeile, 5);
        if (count($t) < 4 || !ctype_digit($t[0])) {
            continue;
        }
        $eintrag = array('zeit' => (int) $t[0], 'zweig' => $t[1],
                         'name' => $t[2], 'ereignis' => $t[3],
                         'rssi' => isset($t[4]) && $t[4] !== '' ? (int) $t[4] : null);
        if ($eintrag['zeit'] >= $ab) {
            $out['zeilen'][] = $eintrag;
        }
        $z = $eintrag['zweig'];
        if (!isset($out['je_zweig'][$z])) {
            $out['je_zweig'][$z] = array('name' => $eintrag['name'], 'kommt' => 0,
                                         'geht' => 0, 'luecke_max' => 0,
                                         'luecke_letzte' => 0, 'ging_um' => 0,
                                         'da_summe' => 0, 'kam_um' => 0);
        }
        $s = &$out['je_zweig'][$z];
        $s['name'] = $eintrag['name'] !== '' ? $eintrag['name'] : $s['name'];
        if ($eintrag['ereignis'] === 'geht') {
            $s['geht']++;
            $s['ging_um'] = $eintrag['zeit'];
            if ($s['kam_um'] > 0) {
                $s['da_summe'] += max(0, $eintrag['zeit'] - $s['kam_um']);
                $s['kam_um'] = 0;
            }
        } elseif ($eintrag['ereignis'] === 'kommt') {
            $s['kommt']++;
            $s['kam_um'] = $eintrag['zeit'];
            if ($s['ging_um'] > 0) {
                // Wie lange war der Tag weg? Das ist die Empfangsluecke,
                // aus der sich "Abwesend nach" ableiten laesst.
                $luecke = $eintrag['zeit'] - $s['ging_um'];
                $s['luecke_letzte'] = $luecke;
                if ($luecke > $s['luecke_max']) {
                    $s['luecke_max'] = $luecke;
                }
            }
        }
        unset($s);
    }
    @fclose($fh);
    return $out;
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul
 * gibt es nur in Perl.
 *
 * ERGAENZT IN 1.3.0 gegen die massgebliche Ausfuhr aus Loxone Config
 * (VI_Marstek Speicher (LoxBerry-Plugin)_Test.xml, 12.08.2026):
 *   - HintText am Wurzelelement, VORNE
 *   - <Info templateType="2" minVersion="17010727"/> als erstes Kindelement
 *   - Unit und HintText je Eintrag
 *   - echte MinVal/MaxVal statt pauschal +-2147483647
 *   - Textthemen bleiben draussen (das Format ist nur fuer Zahlen belegt)
 *
 * Bis 1.2.10 fehlte all das: die Vorlage war der geerbte Stand von
 * APC-UPS 1.0.0. APC-UPS selbst hat die Nachbesserung in 1.2.0 bekommen,
 * dieses Plugin nie.
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
    $o .= 'HintText="' . bl_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . bl_x($kopf['title']) . '" ';
    $o .= 'Comment="' . bl_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . bl_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . bl_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $min = isset($c['min']) && $c['min'] !== null ? $c['min'] : 0;
        $max = isset($c['max']) && $c['max'] !== null ? $c['max'] : 100;
        $einheit = isset($c['einheit']) ? trim((string) $c['einheit']) : '';
        $unit = $einheit === '' ? '<v.1>' : '<v.1> ' . $einheit;
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . bl_x($c['title']) . '" ';
        $o .= 'Comment="' . bl_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . bl_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="' . (((int) $min) < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . bl_x($min) . '" ';
        $o .= 'MaxVal="' . bl_x($max) . '" ';
        $o .= 'Unit="' . bl_x($unit) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Vorlage der MQTT-Eingaenge erzeugen.
 * Rueckgabe: array(dateiname, inhalt, anzahl, uebersprungen)
 *
 * Der Gateway-Name eines Themas entsteht, indem das Gateway '/' und '%'
 * durch '_' ersetzt. Punkte bleiben stehen.
 */
function bl_vorlage($cfg, $tags)
{
    $praefix = bl_cfg($cfg, 'themenpraefix', 'blescanner');
    $fuss = sprintf(bl_t('VORLAGE.FUSS'), date('d.m.Y'));
    $cmds = array();
    $uebersprungen = array();

    foreach (bl_allgemeine_themen() as $thema => $info) {
        if ($info['art'] === 'text') {
            $uebersprungen[] = $praefix . '/' . $thema;
            continue;
        }
        $cmds[] = array(
            'title'   => $praefix . '_' . str_replace('/', '_', $thema),
            'comment' => bl_t($info['s']),
            'check'   => ' ',
            'min'     => $info['min'], 'max' => $info['max'],
            'einheit' => $info['einheit'],
        );
    }

    $alle = array_merge(bl_status_themen(), bl_zusatzthemen($cfg));
    foreach ($tags as $tag) {
        if ($tag['aktiv'] !== '1') {
            continue;
        }
        $t = bl_thema($tag);
        $bez = $tag['name'] !== '' ? $tag['name'] : $tag['kennung'];
        foreach ($alle as $schluessel => $info) {
            if ($info['art'] === 'text') {
                // Textthemen gehoeren NICHT in die Vorlage: das nachgebaute
                // Format ist nur fuer Zahlenwerte belegt, und ein
                // Analogeingang auf einem Text steht dauerhaft auf 0.
                $uebersprungen[] = $praefix . '/' . $t . '/' . $schluessel;
                continue;
            }
            $cmds[] = array(
                'title'   => $praefix . '_' . $t . '_' . $schluessel,
                'comment' => $bez . ' - ' . bl_t($info['s']),
                'check'   => ' ',
                'min'     => $info['min'], 'max' => $info['max'],
                'einheit' => $info['einheit'],
            );
        }
    }

    $inhalt = bl_xml_virtual_in_http(array(
        'title'   => 'BLE-Scanner NG',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => $fuss,
        'hint'    => '',
    ), $cmds);
    return array('VI_BLE-Scanner-NG.xml', $inhalt, count($cmds), $uebersprungen);
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch.
 * ================================================================== */

function bl_sprache()
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return $s = (in_array($sprache, array('de', 'en'), true) ? $sprache : 'en');
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt.
 */
function bl_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if ($k !== '' && is_dir($k)) { $home = $k; break; }
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
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    $a = $teile[0]; $s = $teile[1];
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/** Alle Sprachschluessel eines Abschnitts - fuer die Selbstpruefung. */
function bl_sprachschluessel($abschnitt)
{
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) { $home = lb_wurzel_ermitteln(); }
    $ordner = basename(dirname(__FILE__));
    $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
    if (!is_dir($pfad)) {
        $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
    }
    $out = array();
    foreach (array('de', 'en') as $spr) {
        $d = @parse_ini_file($pfad . '/language_' . $spr . '.ini', true, INI_SCANNER_RAW);
        $out[$spr] = (is_array($d) && isset($d[$abschnitt])) ? array_keys($d[$abschnitt]) : array();
    }
    return $out;
}


/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 */
function bl_gateway_fassung()
{
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function bl_abo_text()
{
    $f = bl_gateway_fassung();
    if ($f <= 0) {
        return bl_t('TEXT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(bl_t('TEXT.ABO_GEMESSEN'), $f) . '</span>';
    return bl_t($f >= 2 ? 'TEXT.ABO_V2' : 'TEXT.ABO_PFLICHT') . $gemessen;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function bl_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(bl_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = bl_defaults();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(bl_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = bl_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
