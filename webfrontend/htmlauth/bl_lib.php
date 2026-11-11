<?php
require_once __DIR__ . '/sprachausgabe.php';     // Stamm, fuer die Vorgaben der Sprachansagen schon beim ersten Lesen der Konfiguration
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

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * BERICHTIGT IN 1.3.19: gesucht wird ein Verzeichnis mit config/plugins,
 * data/plugins UND config/system/general.json. Bis 1.3.18 genuegten
 * config/plugins und webfrontend - genau die hinterlaesst ein Pruefstand; am
 * 05.09.2026 hielt eine solche Suche das Laufwerk eines Arbeitsrechners fuer
 * einen LoxBerry (Regeln/06). Rueckgabe '' heisst: keine Wurzel. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Ein ausdruecklich genanntes LBHOMEDIR gilt mit config/plugins UND
 * data/plugins darunter - general.json wird dort nicht verlangt, damit die
 * Attrappen der Pruefwerkzeuge weiter tragen (Bauart tb_lbhome() der Linie
 * Spotpreis-Tibber). Sonst die Suche; danach nichts mehr. */
function bl_lbhome()
{
    $h = rtrim((string) getenv('LBHOMEDIR'), '/');
    if ($h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return $h;
    }
    return lb_wurzel_ermitteln();
}

/*
 * Die Pfade - der Anlage nur, wenn diese Oberflaeche dort installiert ist.
 *
 * BERICHTIGT IN 1.3.19 (Muster 3 der Nachlese), zwei Fehler an einer Stelle:
 *
 * 1. Ein ausgepacktes Archiv unterhalb einer echten Wurzel - oder mit
 *    LBHOMEDIR allein, wie es am Geraet in /etc/environment steht - nahm die
 *    Wurzel und fiel auf den festen Namen 'ble_scanner_ng' zurueck:
 *    Konfiguration, PID-Datei und Dienst DER ANLAGE. "Dienst starten" aus dem
 *    Archiv startete den Dienst der Anlage, "Testmodus" schrieb in ihre
 *    Steuerdatei (in WSL gemessen, Pruefung-BLE-Scanner-1.3.19, P1, P4, P5).
 * 2. Installiert liegt diese Datei unter
 *    <wurzel>/webfrontend/htmlauth/plugins/<ordner>; der Ordnername wurde
 *    aber aus ZWEI Ebenen darueber gelesen ('htmlauth') und fiel dann auf
 *    den festen Namen zurueck. Eine Zweitinstallation ble_scanner_ng01
 *    arbeitete damit auf der Konfiguration der ersten (Fall P3).
 *
 * Die Anlage gilt jetzt nur, wenn diese Datei dort physisch installiert liegt
 * (realpath verglichen) oder LBHOMEDIR UND LBPPLUGINDIR ausdruecklich gesetzt
 * sind (so arbeiten die Pruefwerkzeuge mit ihrer Attrappe). Sonst bleibt
 * alles im eigenen Baum, 'home' ist leer, und bl_dienst()/bl_steuern()
 * verweigern. 'archiv' nennt dann die gefundene Wurzel fuer die Meldung.
 */
/* Ordnername als Teil eines Dateinamens auf der Ramdisk - dieselbe Regel wie
 * _RAM in bl_common.py. */
function bl_ram_name($dir)
{
    $n = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $dir);
    return $n !== '' ? $n : 'ble_scanner_ng';
}

function bl_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = bl_lbhome();
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp,
        array('.', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true));
    $dir = $lbp_gilt ? $lbp : basename(__DIR__);
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/htmlauth/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $home = '';
        }
    }
    if ($home === '' && !$lbp_gilt) {
        $dir = 'ble_scanner_ng';
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
            // Seit 1.3.19 mit dem Ordnernamen (Zweitinstallation, Faelle Z4
            // und Z6); fuer ble_scanner_ng bleibt der Name derselbe.
            'status'    => $shm . '/' . bl_ram_name($dir) . '_status.json',
            'steuer'    => $shm . '/' . bl_ram_name($dir) . '_steuer.json',
            'pid'       => $datadir . '/dienst.pid',
            'archiv'    => '',
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
            // Nicht die Dateien der Anlage: die Ramdisk-Namen sind fuer jede
            // Installation dieselben (seit 1.3.19).
            'status'    => $base . '/data/ble_scanner_ng_status.json',
            'steuer'    => $base . '/data/ble_scanner_ng_steuer.json',
            'pid'       => $base . '/data/dienst.pid',
            'archiv'    => $gefunden,
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
    // B21 (Gruppe 13): danach LBSystem::pluginversion() MIT dem Ordnernamen (Regeln/06 Z. 551-559; ohne Argument
    // liefert es nichts Brauchbares, Gedaechtnis pluginversion-ohne-argument), dann plugin.cfg zwei Ebenen ueber
    // htmlauth - wie fassung() in bl_common.py. Bis 1.3.27 suchte hier ein Muster den Rueckfallwert
    // VERSION_RUECKFALL = "" und lieferte '' (Probe b21: "Rueckfallwert  aus bl_common.py", doppeltes Leerzeichen).
    if (class_exists('LBSystem') && method_exists('LBSystem', 'pluginversion')) {
        $wert = trim((string) @LBSystem::pluginversion($p['plugin']));
        if (preg_match('/^\d+(\.\d+){0,3}$/', $wert)) {
            return $f = $wert;
        }
    }
    $k = dirname(dirname(__DIR__)) . '/plugin.cfg';
    if (is_file($k) && preg_match('/^\s*VERSION\s*=\s*"?([0-9]+(?:\.[0-9]+){0,3})"?\s*$/m', (string) @file_get_contents($k), $m)) {
        return $f = $m[1];
    }
    // 1.3.36 (Fremdpruefung 09.11.2026): plugindatabase.json stand bis 1.3.35 in derselben Liste, wurde aber von der
    // Pruefung auf "plugin.cfg" sofort uebersprungen. Jetzt gelesen: der Eintrag mit folder = eigener Ordner.
    $db = $p['home'] !== '' ? $p['home'] . '/data/system/plugindatabase.json' : '';
    if ($db !== '' && is_file($db)) {
        $j = json_decode((string) @file_get_contents($db), true);
        $eintraege = (is_array($j) && isset($j['plugins']) && is_array($j['plugins'])) ? $j['plugins'] : array();
        foreach ($eintraege as $e) {
            if (is_array($e) && isset($e['folder'], $e['version']) && $e['folder'] === $p['plugin']
                && preg_match('/^\d+(\.\d+){0,3}$/', (string) $e['version'])) {
                return $f = (string) $e['version'];
            }
        }
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
        'ansage_block'       => '',     // die Sprachansagen, ein Feld (base64), eigene Pruefung
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
        // Personen und Raeume (1.3.36), ab Werk aus; wirkt nur mit raum=1
        'raum_personen'      => '0',
        // Raum-Empfaenger halten den Anhaenger anwesend (1.3.38, K13), ab Werk aus; wirkt nur mit raum=1
        'raum_anwesenheit'   => '0',
        // ESPresense-Empfaenger in der Raumzuordnung (1.3.36), ab Werk aus
        'espresense'           => '0',
        'espresense_praefix'   => 'espresense',
        'espresense_zuordnung' => '',
        'entfernung'         => '0',
        'daempfung'          => '2.5',
        'beacon'             => '1',
        'batterie'           => '0',
        'batterie_uhrzeit'   => '04:00',
        // Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026), ab Werk aus
        'wlan_kopplung'      => '0',
        'wlan_zuordnung'     => '',
        // Gruppe 13 (1.3.28): Befehlsbruecke (beide ab Werk aus), Haus-Themen (aus), BTHome v2 (0; die Paket-cfg
        // traegt 1 - nur eine Neuinstallation ist an), IRK-Aufloesung (aus)
        'befehle_mqtt_ein'        => '0',
        'befehle_eingreifend_ein' => '0',
        'haus_anwesenheit'        => '0',
        'bthome'                  => '0',
        'irk_aufloesen'           => '0',
        // Gruppe 33 (1.3.29): Frist der Messwerte (s) und Vorwahl der Region im Bind-Key-Abruf
        'messwert_frist'          => '1800',
        'xiaomi_region'           => 'de',
    );
}

/**
 * Schluessel, die eine aeltere Konfiguration oder Sicherung noch nicht kennt
 * (Verbesserungsbau 30.09.2026). Fehlen sie, gilt die Werkseinstellung - das
 * ist kein Mangel: die Zeile "Konfiguration heil" bleibt gruen, und eine
 * Sicherung aus 1.3.21 wird weiter zurueckgespielt.
 */
function bl_schluessel_spaeter()
{
    return array('wlan_kopplung', 'wlan_zuordnung', 'befehle_mqtt_ein', 'befehle_eingreifend_ein',
                 'haus_anwesenheit', 'bthome', 'irk_aufloesen', 'messwert_frist', 'xiaomi_region',
                 'espresense', 'espresense_praefix', 'espresense_zuordnung', 'raum_personen', 'raum_anwesenheit');
}

/**
 * Die Regeln je Einstellung - EINE Tabelle fuer Formular und Zurueckspielen
 * (seit 1.3.20, Pruefung 29.09.2026, C3/O2).
 *
 * Bis 1.3.19 standen die Grenzen nur in den Aufrufen im Speichern-Handler, und
 * bl_sicherung_lesen() uebernahm jeden Wert ungeprueft: ein Praefix mit "#",
 * ein leeres Praefix, "intervall=abc" und eine Liste ("Array") waeren beim
 * Zurueckspielen in die Datei gekommen, obwohl das Formular sie abweist.
 *
 * Art: 'zahl' (ganze Zahl, Muster statt (int), min/max), 'komma' (min/max),
 * 'schalter' ("0"/"1"), 'wahl' (Liste), 'muster' (regulaerer Ausdruck),
 * 'text' (ohne Steuerzeichen und Anfuehrungszeichen).
 */
function bl_regeln()
{
    return array(
        'ansage_block'       => array('muster', '/^[A-Za-z0-9+\/=]*$/'),
        'adapter'            => array('muster', '/^hci[0-9]+$/'),
        'themenpraefix'      => array('muster', '/^[A-Za-z0-9_-]+$/'),
        'mqtt'               => array('schalter'),
        'http_push'          => array('schalter'),
        'loxberry_id'        => array('text'),
        'intervall'          => array('zahl', 2, 600),
        'abwesenheit_nach'   => array('zahl', 5, 3600),
        'aktualisierung'     => array('zahl', 5, 86400),
        'rssi_nah'           => array('zahl', -120, 0),
        'rssi_mittel'        => array('zahl', -120, 0),
        'rssi_minimum'       => array('zahl', -120, 0),
        'ankunft_sichtungen' => array('zahl', 1, 20),
        'glaettung'          => array('schalter'),
        'glaettung_fenster'  => array('zahl', 1, 30),
        'hysterese_db'       => array('zahl', 0, 20),
        'betriebsart'        => array('wahl', array('signal', 'abfrage')),
        'wachhund'           => array('schalter'),
        'wachhund_stille'    => array('zahl', 60, 86400),
        'discovery_rssi'     => array('zahl', -120, 0),
        'log_kappung_kb'     => array('zahl', 16, 20000),
        'ereignisse'         => array('schalter'),
        'ereignisse_tage'    => array('zahl', 1, 365),
        'scanner_name'       => array('muster', '/^[A-Za-z0-9_-]{0,32}$/'),
        'scanner_themen'     => array('schalter'),
        'raum'               => array('schalter'),
        'raum_hysterese_db'  => array('zahl', 0, 30),
        'raum_ausgleich_db'  => array('zahl', -30, 30),
        'raum_personen'      => array('schalter'),
        'raum_anwesenheit'   => array('schalter'),
        'espresense'           => array('schalter'),
        'espresense_praefix'   => array('muster', '/^[A-Za-z0-9_-]{1,32}$/'),
        'espresense_zuordnung' => array('es_zuordnung'),
        'entfernung'         => array('schalter'),
        'daempfung'          => array('komma', 1.5, 6.0),
        'beacon'             => array('schalter'),
        'batterie'           => array('schalter'),
        'batterie_uhrzeit'   => array('muster', '/^([01]?\d|2[0-3]):[0-5]\d$/'),
        'wlan_kopplung'      => array('schalter'),
        'wlan_zuordnung'     => array('zuordnung'),
        'befehle_mqtt_ein'        => array('schalter'),
        'befehle_eingreifend_ein' => array('schalter'),
        'haus_anwesenheit'        => array('schalter'),
        'bthome'                  => array('schalter'),
        'irk_aufloesen'           => array('schalter'),
        'messwert_frist'          => array('zahl', 300, 21600),
        'xiaomi_region'           => array('wahl', array('de', 'cn', 'i2', 'ru', 'sg', 'us', 'alle')),
    );
}

/** Enthaelt ein Text Steuerzeichen oder Anfuehrungszeichen? (seit 1.3.20, C9) */
function bl_unzulaessige_zeichen($s)
{
    return (bool) preg_match('/[\x00-\x1F\x7F"\']/', (string) $s);
}

/** Ist das eine ganze Zahl in Schreibweise, nicht nur nach (int)? */
function bl_ganzzahl($s)
{
    return (bool) preg_match('/^-?[0-9]{1,9}$/', (string) $s);
}

/**
 * Einen Wert gegen seine Regel halten. Zuerst is_string - eine Liste aus einer
 * Sicherungsdatei ist kein Wert (Klasse 12 des Auftrags: sonst entsteht
 * "Array"). Rueckgabe: array(ok, Wert, Grund).
 */
function bl_wert_pruefen($k, $roh)
{
    $regeln = bl_regeln();
    if (!isset($regeln[$k])) {
        return array(false, '', 'unbekannt');
    }
    if (is_int($roh)) {
        $roh = (string) $roh;
    }
    if (!is_string($roh)) {
        return array(false, '', 'kein_text');
    }
    $r = $regeln[$k];
    $w = trim($roh);
    switch ($r[0]) {
        case 'zahl':
            if (!bl_ganzzahl($w)) {
                return array(false, '', 'keine_zahl');
            }
            if ((int) $w < $r[1] || (int) $w > $r[2]) {
                return array(false, '', 'grenze');
            }
            return array(true, (string) (int) $w, '');
        case 'komma':
            $w2 = str_replace(',', '.', $w);
            if (!preg_match('/^-?[0-9]{1,4}(\.[0-9]{1,4})?$/', $w2)) {
                return array(false, '', 'keine_zahl');
            }
            if ((float) $w2 < $r[1] || (float) $w2 > $r[2]) {
                return array(false, '', 'grenze');
            }
            return array(true, rtrim(rtrim(sprintf('%.2F', (float) $w2), '0'), '.'), '');
        case 'schalter':
            return in_array($w, array('0', '1'), true) ? array(true, $w, '')
                                                        : array(false, '', 'muster');
        case 'wahl':
            return in_array($w, $r[1], true) ? array(true, $w, '') : array(false, '', 'muster');
        case 'muster':
            return preg_match($r[1], $w) ? array(true, $w, '') : array(false, '', 'muster');
        case 'zuordnung':
            // "<WLAN-Name>=<Person>, ..." (Verbesserungsbau 30.09.2026): je Seite
            // die Zeichen eines MQTT-Themas, jeder Name hoechstens einmal,
            // hoechstens 20 Paare. Leer ist zulaessig. Abgewiesen, nicht
            // zurechtgebogen; nur die Leerzeichen um , und = fallen weg.
            if ($w === '') {
                return array(true, '', '');
            }
            $bl_zu = array();
            $bl_wl = array();
            $bl_pe = array();
            foreach (preg_split('/\s*,\s*/', $w) as $bl_paar) {
                if (!preg_match('/^([A-Za-z0-9_-]{1,40})\s*=\s*([A-Za-z0-9_-]{1,40})$/', $bl_paar, $bl_m)) {
                    return array(false, '', 'muster');
                }
                if (isset($bl_wl[$bl_m[1]]) || isset($bl_pe[$bl_m[2]])) {
                    return array(false, '', 'doppelt');
                }
                $bl_wl[$bl_m[1]] = true;
                $bl_pe[$bl_m[2]] = true;
                $bl_zu[] = $bl_m[1] . '=' . $bl_m[2];
            }
            if (count($bl_zu) > 20) {
                return array(false, '', 'grenze');
            }
            return array(true, implode(', ', $bl_zu), '');
        case 'es_zuordnung':
            // "<ESPresense-Kennung>=<Tag-Alias>, ..." (1.3.36): links die Kennung, wie ESPresense sie im
            // Thema fuehrt (dazu Doppelpunkt und Punkt, z. B. iBeacon:...-100-1), rechts ein Tag-Alias.
            // Dieselbe Regel wie es_zuordnung_lesen() in bin/bl_common.py; abgewiesen, nicht zurechtgebogen.
            if ($w === '') {
                return array(true, '', '');
            }
            $bl_zu = array();
            $bl_li = array();
            $bl_re = array();
            foreach (preg_split('/\s*,\s*/', $w) as $bl_paar) {
                if (!preg_match('/^([A-Za-z0-9:._-]{1,80})\s*=\s*([A-Za-z0-9_-]{1,40})$/', $bl_paar, $bl_m)) {
                    return array(false, '', 'muster');
                }
                if (isset($bl_li[$bl_m[1]]) || isset($bl_re[$bl_m[2]])) {
                    return array(false, '', 'doppelt');
                }
                $bl_li[$bl_m[1]] = true;
                $bl_re[$bl_m[2]] = true;
                $bl_zu[] = $bl_m[1] . '=' . $bl_m[2];
            }
            if (count($bl_zu) > 20) {
                return array(false, '', 'grenze');
            }
            return array(true, implode(', ', $bl_zu), '');
        case 'text':
            return bl_unzulaessige_zeichen($roh) ? array(false, '', 'zeichen')
                                                 : array(true, $w, '');
    }
    return array(false, '', 'unbekannt');
}

/**
 * Einen Tag aus einer Sicherung pruefen - dieselben Regeln wie die
 * Tag-Tabelle im Formular (bl_tags_aus_post() in index.php).
 * Rueckgabe: array(Tag|null, Grund).
 */
function bl_tag_pruefen($t)
{
    if (!is_array($t)) {
        return array(null, 'kein Eintrag');
    }
    foreach (array('kennung', 'aktiv', 'name') as $f) {
        if (!isset($t[$f]) || !is_string($t[$f])) {
            return array(null, $f);
        }
    }
    list($art, $kennung) = bl_kennung_eingabe($t['kennung']);     // 1.3.38 (K12): dieselbe Regel wie das Formular
    if ($art === '') {
        return array(null, 'kennung');
    }
    if (!in_array($t['aktiv'], array('0', '1'), true)) {
        return array(null, 'aktiv');
    }
    if (bl_unzulaessige_zeichen($t['name']) || strpos($t['name'], '|') !== false) {
        return array(null, 'name');
    }
    $opt = array();
    $roh = isset($t['opt']) ? $t['opt'] : array();
    if (!is_array($roh)) {
        return array(null, 'opt');
    }
    foreach ($roh as $k => $v) {
        if (!in_array($k, bl_tag_optionen(), true)) {
            return array(null, 'opt.' . $k);
        }
        if (!is_string($v) || bl_unzulaessige_zeichen($v) || preg_match('/[,=|]/', $v)) {
            return array(null, 'opt.' . $k);
        }
        $v = trim($v);
        if ($v === '') {
            continue;
        }
        if ($k === 'abw' && (!ctype_digit($v) || (int) $v < 5 || (int) $v > 3600)) {
            return array(null, 'opt.abw');
        }
        if ($k === 'ref' && (!bl_ganzzahl($v) || (int) $v > 0 || (int) $v < -120)) {
            return array(null, 'opt.ref');
        }
        if ($k === 'alias' && in_array(strtolower(bl_saeubern($v)), bl_reservierte_zweige(), true)) {
            return array(null, 'opt.alias');
        }
        if ($k === 'batt' && $v !== '1') {
            return array(null, 'opt.batt');
        }
        if ($k === 'haus' && $v !== '1') {
            return array(null, 'opt.haus');
        }
        $opt[$k] = $v;
    }
    // V1 (Gruppe 13): ein IRK-Tag braucht einen Alias - sonst hiesse sein Themenzweig nach dem Schluesselnamen.
    if ($art === 'irk' && (!isset($opt['alias']) || bl_saeubern($opt['alias']) === '')) {
        return array(null, 'opt.alias');
    }
    return array(array('art' => $art, 'kennung' => $kennung,
                       'mac' => $art === 'mac' ? $kennung : '',
                       'aktiv' => $t['aktiv'], 'name' => trim($t['name']),
                       'opt' => $opt), '');
}

/* ==================================================================
 * Retain je Thema - Hausstandard seit 03.09.2026
 *
 * "Zustaende retained, Messwerte mit Zeitbezug nicht, das Lebenszeichen nie."
 *
 * Diese Tabelle muss Eintrag fuer Eintrag zu RETAIN in bin/bl_common.py
 * passen; die Pruefzeile "Retain je Thema" im Reiter Test haelt beide
 * gegeneinander und wird rot, sobald eine Seite abweicht. Gelesen wird die
 * Python-Seite ueber "bl_lesen.py --vorgaben".
 *
 * Bis 1.3.11 gab es die Unterscheidung gar nicht: der Dienst schickte ALLES
 * zurueckbehalten hinaus, einschliesslich des Lebenszeichens server/ts.
 * ================================================================== */

function bl_retain()
{
    return array(
        // -- Zustaende je Tag
        'present'        => true,
        'level'          => true,
        'name'           => true,
        'raum'           => true,
        // -- absolute Zeitstempel: koennen nicht "aktuell erscheinen"
        'last_seen_ts'   => true,
        'present_since'  => true,
        'battery_ts'     => true,
        'raum_seit'      => true,
        // -- Batteriestand: einmal taeglich, "zuletzt gueltiger Wert"
        'battery'        => true,
        // -- Letzter Wille (Regeln/07, 18.09.2026): retained, als Paar auf
        //    einem Thema; die Deinstallation raeumt es ab
        'server/online'          => true,
        // -- Aussagen des Dienstes ueber sich selbst: nie retained
        //    (Regeln/07, 19.09.2026; bis 1.3.18 retained)
        'server/ok'              => false,
        'server/adapter_ok'      => false,
        // -- bleiben auch nach dem Tod des Dienstes wahr
        'server/letzte_sichtung' => true,
        'server/version'         => true,
        'server/scanner'         => true,
        // -- Zusammenfassung
        'summary/present'     => true,
        'summary/tags'        => true,
        'summary/tags_gesamt' => true,
        // -- Personen und zweiter Themenzweig je Scanner
        'person/present'      => true,
        'person/last_seen_ts' => true,
        // -- Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026)
        'person/anwesend_gesamt' => true,
        'person/quelle'          => true,
        // -- Personen und Raeume (1.3.36, raum_personen); die Namensliste je Raum ist regelmaessig leer
        'person/raum'            => true,
        'person/raum_seit'       => true,
        'summary/raum/anzahl'    => true,
        'summary/raum/namen'     => false,
        'scanner/present'     => true,

        // -- Messwerte mit Zeitbezug: NICHT retained
        'rssi'          => false,
        'rssi_avg'      => false,
        'distance'      => false,
        'sensor'        => false,
        // Gruppe 33: die Lage der Messwerte eines Thermometers ist ein Zustand - retained
        'sensor/lage'   => true,
        'scanner/rssi'  => false,
        // -- eine Dauer altert von selbst
        'last_seen'     => false,
        // -- das Lebenszeichen
        'server/ts'     => false,
        // -- regelmaessig leer; eine leere Nutzlast loescht ein retained Thema
        'summary/names' => false,
        // -- Gruppe 13 (1.3.28): Lebenszeichen fuer Loxone und je Scanner, Taster-Ereignis (V2), Kalibrierung (E5)
        'status/zaehler'  => false,
        'scanner/zaehler' => false,
        'button'          => false,
        'kalibrierung'    => false,
    );
}

/** Zweignamen, die der Themenbaum belegt - dieselbe Liste wie in Python. */
function bl_reservierte_zweige()
{
    // F2 (Gruppe 13): status und befehl dazu.
    return array('server', 'summary', 'person', 'scanner', 'sensor', 'status', 'befehl');
}

/**
 * Aus einem Thema oder einem Quelltextmuster den Stamm machen.
 * Gleiche Regel wie thema_stamm() in bl_common.py.
 */
function bl_thema_stamm($thema)
{
    $teile = array();
    foreach (explode('/', (string) $thema) as $x) {
        if ($x === '' || preg_match('/^\{\d\}$/', $x)) {
            continue;
        }
        $teile[] = $x;
    }
    if (!$teile) {
        return '';
    }
    if (in_array('sensor', $teile, true)) {
        // Gruppe 33: sensor/lage ist ein eigener Stamm (retained), alle anderen Messwerte "sensor".
        return $teile[count($teile) - 1] === 'lage' ? 'sensor/lage' : 'sensor';
    }
    if ($teile[0] === 'summary' && count($teile) >= 3 && $teile[1] === 'raum') {
        // 1.3.36: summary/raum/<R>/anzahl -> summary/raum/anzahl (wie gem.thema_stamm())
        return 'summary/raum/' . $teile[count($teile) - 1];
    }
    if (in_array($teile[0], array('server', 'summary', 'status'), true)) {
        return $teile[0] . '/' . (isset($teile[1]) ? $teile[1] : '');
    }
    if (in_array($teile[0], array('person', 'scanner'), true)) {
        return $teile[0] . '/' . $teile[count($teile) - 1];
    }
    return $teile[count($teile) - 1];
}

/** Geht dieses Thema zurueckbehalten hinaus? Unbekannter Stamm: nein. */
function bl_retain_fuer($thema)
{
    $tab = bl_retain();
    $stamm = bl_thema_stamm($thema);
    return isset($tab[$stamm]) ? (bool) $tab[$stamm] : false;
}

/** Anzeigetext fuer die Thementabelle. */
function bl_retain_text($thema)
{
    return bl_retain_fuer($thema) ? bl_t('TEXT.RETAIN_JA') : bl_t('TEXT.RETAIN_NEIN');
}

/** Erlaubte Zusatzangaben je Tag - dieselbe Liste wie TAG_OPTIONEN in Python. */
function bl_tag_optionen()
{
    // B19 (Gruppe 13): min und raum sind Altoptionen (bl_altoptionen()); V12: haus.
    return array('abw', 'ref', 'alias', 'person', 'batt', 'haus');
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
    $irk = bl_irk_kennung($wert);
    if ($irk !== '') {
        return array('irk', $irk);
    }
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

/**
 * 1.3.38 (K12): EINE Regel fuer die Kennung eines Tags - die Tag-Tabelle im Formular (bl_tags_aus_post()) und die
 * Sicherung (bl_tag_pruefen()) rufen beide diese Funktion. Angenommen wird, was bl_kennung() deutet und keine
 * unzulaessigen Zeichen traegt; zurueck kommt die genormte Kennung (MAC gross mit Doppelpunkten, IB:..., IRK:<Name>).
 * Bis 1.3.37 verlangte die Sicherung zusaetzlich, dass die Datei die Kennung schon genormt fuehrt - "irk:handy" oder
 * eine klein geschriebene MAC nahm das Formular an, das Zurueckspielen wies sie mit "Feld kennung" ab.
 */
function bl_kennung_eingabe($roh)
{
    if (!is_string($roh) || bl_unzulaessige_zeichen($roh)) {
        return array('', '');
    }
    return bl_kennung($roh);
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
    return (bool) preg_match('/^\s*(' . implode('|', array_merge(bl_tag_optionen(), bl_altoptionen())) . ')\s*=/i',
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
            $altopt = array();
            if ($viertes !== '' && bl_sind_optionen($viertes)) {
                $opt = bl_optionen_lesen($viertes);
                // B19 (Gruppe 13): wirkungslose Altoptionen merken - das Speichern verwirft sie mit Hinweis.
                if (preg_match_all('/(?:^|,)\s*(' . implode('|', bl_altoptionen()) . ')\s*=/i', $viertes, $bl_am)) {
                    $altopt = array_values(array_unique(array_map('strtolower', $bl_am[1])));
                }
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
                ) + ($altopt ? array('altopt' => $altopt) : array());
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
    // BERICHTIGT IN 1.3.20 (Pruefung 29.09.2026, C7; Regeln/03 "Atomares
    // Schreiben"): bis 1.3.19 galt "!== false" als Erfolg. Eine gekuerzte
    // Schreibung ist aber genauso kaputt - unter "ulimit -f 2" gemessen: 2048
    // statt 2387 Byte, 33 von 40 Tags, und die Oberflaeche meldete
    // "Gespeichert". Jetzt zaehlt nur die volle Laenge, und fflush muss
    // gelingen, bevor umbenannt wird.
    $geschrieben = @fwrite($fh, $inhalt);
    $ok = ($geschrieben === strlen($inhalt)) && (@fflush($fh) !== false);
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
        } elseif ($art === 'irk') {
            $kennung = bl_irk_kennung($kennung);   // V1 (Gruppe 13)
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

/**
 * Konfiguration vervollstaendigen (seit 1.3.40, Regeln/05 "Die Konfiguration
 * wird vervollstaendigt, nicht nur ergaenzt").
 *
 * Gemessen am Geraet 11.11.2026 nach dem Update auf 1.3.38: in der Datei
 * fehlten espresense, espresense_praefix, espresense_zuordnung,
 * raum_anwesenheit und raum_personen. Beide Leser ergaenzten sie nur im
 * Speicher; die Datei bekam sie erst beim naechsten Speichern im Formular.
 *
 * Bauform nach Regeln/05 (Docker NG 1.3.7):
 * - nur, wenn die Datei das Merkwort [CONFIG] traegt - eine fehlende,
 *   leere oder alte Datei ist Sache der Selbstheilung bzw. des Speicherns;
 * - geschrieben werden die ZEILEN DER DATEI, unveraendert, plus je fehlender
 *   Vorgabe eine Zeile "schluessel=vorgabe" vor den Tag-Zeilen - kein
 *   Neubau ueber bl_config_write(), der fremde Schluessel verwerfen und
 *   Tag-Zeilen umformen wuerde;
 * - Zeilenenden der Datei bleiben, Rechte bleiben;
 * - geschrieben wird nur, wenn etwas fehlt.
 *
 * Rueckgabe: array('stand' => ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler,
 *                  'schluessel' => array(...))
 */
function bl_config_vervollstaendigen($datei = null)
{
    $file = $datei !== null ? $datei : bl_paths()['config'];
    if (!is_file($file)) {
        return array('stand' => 'ohne_datei', 'schluessel' => array());
    }
    $txt = @file_get_contents($file);
    if (!is_string($txt) || $txt === '' || preg_match('/^[ \t]*\[CONFIG\]/m', $txt) !== 1) {
        return array('stand' => 'ohne_merkwort', 'schluessel' => array());
    }
    $zeilen = preg_split('/(?<=\n)/', $txt, -1, PREG_SPLIT_NO_EMPTY);
    $eol = (strpos($txt, "\r\n") !== false) ? "\r\n" : "\n";
    $da = array();
    $ersterTag = -1;
    foreach ($zeilen as $i => $zeile) {
        $t = trim($zeile);
        if ($t === '' || $t[0] === ';' || $t[0] === '#' || $t[0] === '[') {
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            continue;
        }
        $schluessel = trim(substr($t, 0, $pos));
        if (preg_match('/^(default\.)?TAG\d+$/i', $schluessel)) {
            if ($ersterTag < 0) {
                $ersterTag = $i;
            }
            continue;
        }
        $da[strtolower(preg_replace('/^default\./i', '', $schluessel))] = true;
    }
    $fehlend = array();
    foreach (bl_defaults() as $k => $vorgabe) {
        if (!isset($da[$k])) {
            $fehlend[$k] = str_replace(array("\r", "\n", '|'), array('', ' ', '/'), (string) $vorgabe);
        }
    }
    if (!$fehlend) {
        return array('stand' => 'vollstaendig', 'schluessel' => array());
    }
    $neu = '';
    foreach ($fehlend as $k => $v) {
        $neu .= $k . '=' . trim($v) . $eol;
    }
    if ($ersterTag >= 0) {
        // vor die Leerzeilen, die den Werteblock von den Tags trennen
        $stelle = $ersterTag;
        while ($stelle > 0 && trim($zeilen[$stelle - 1]) === '') {
            $stelle--;
        }
        array_splice($zeilen, $stelle, 0, array($neu));
    } else {
        $letzte = count($zeilen) - 1;
        if ($letzte >= 0 && substr($zeilen[$letzte], -1) !== "\n") {
            $zeilen[$letzte] .= $eol;
        }
        $zeilen[] = $neu;
    }
    $rechte = @fileperms($file);
    $rechte = ($rechte === false) ? 0640 : ($rechte & 0777);
    if (!bl_datei_schreiben($file, implode('', $zeilen), $rechte)) {
        return array('stand' => 'fehler', 'schluessel' => array_keys($fehlend));
    }
    return array('stand' => 'ergaenzt', 'schluessel' => array_keys($fehlend));
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
 * Die Benutzernummern, deren Prozesse als eigener Dienst gelten.
 *
 * Der Dienst laeuft als loxberry - daemon/daemon steigt dazu ab. Die Nummer
 * kommt aus /etc/passwd und nicht aus posix_getpwnam(): die posix-Erweiterung
 * ist auf einem LoxBerry nicht zugesichert, und eine Oberflaeche darf sich auf
 * keine Erweiterung verlassen, die nicht garantiert geladen ist (Regeln/02).
 * Dazu die eigene Nummer: was diese Seite selbst gestartet hat, gehoert ihr.
 */
function bl_dienst_uids()
{
    static $u = null;
    if ($u !== null) {
        return $u;
    }
    $u = array();
    $eigen = @getmyuid();
    if ($eigen !== false) {
        $u[] = (int) $eigen;
    }
    // is_readable() VOR dem Lesen, nicht nur ein @ davor: der Pruefstand
    // rendert die Oberflaeche unter Windows, dort gibt es weder /etc/passwd
    // noch /proc, und ein eigener Fehlerbehandler sieht die Meldung auch
    // hinter dem @.
    $zeilen = is_readable('/etc/passwd')
        ? @file('/etc/passwd', FILE_IGNORE_NEW_LINES) : false;
    if (is_array($zeilen)) {
        foreach ($zeilen as $z) {
            $f = explode(':', $z);
            if (isset($f[2]) && $f[0] === 'loxberry') {
                $u[] = (int) $f[2];
                break;
            }
        }
    }
    $u = array_values(array_unique($u));
    return $u;
}

/**
 * ALLE Prozesse dieses Dienstes - argumentweise erkannt.
 *
 * Ein Treffer hat GENAU zwei Argumente: einen python-Interpreter und den
 * vollen Dienstpfad DIESER Installation. Dazu gehoert er loxberry oder dem
 * Benutzer, unter dem diese Seite laeuft. Dieselbe Regel wie in
 * preupgrade.sh, postinstall.sh, postupgrade.sh, uninstall und daemon.
 *
 * BERICHTIGT IN 1.3.17. Bis 1.3.16 entschied im PID-Datei-Zweig eine
 * TEILZEICHENKETTE ueber die ganze Befehlszeile, und behandelt wurde immer nur
 * EIN Prozess. In WSL gemessen (18.09.2026):
 *
 *   p1  in dienst.pid stand die Nummer eines "tail -f <dienst>"
 *       -> bl_dienst_pid() lieferte 11604, bl_dienst('stop') hat ihn beendet
 *   p4  von ZWEI eigenen Diensten blieb nach 'stop' einer stehen
 *
 * Warum nicht pgrep: ein Suchlauf nach einer Zeichenkette trifft SYSTEMWEIT
 * jede Befehlszeile, in der sie vorkommt - einen Editor, ein 'tail', den
 * Dienst eines zweiten Plugin-Ordners; der Schalter fuer den aeltesten Treffer
 * nimmt davon ausgerechnet den falschen. Der Aufruf ist hier absichtlich nicht
 * abgeschrieben: ein Kommentar darf nicht die Zeichenfolge enthalten, nach der
 * ein Werkzeug den Bestand absucht (Regeln/02).
 *
 * Rueckgabe: aufsteigend sortierte Liste von Prozessnummern.
 */
function bl_dienst_pids()
{
    $skript = bl_paths()['bindir'] . '/ble_scanner_ng.py';
    $uids = bl_dienst_uids();
    $aus = array();
    // Ohne /proc gibt es hier nichts zu sehen - und die Frage danach wird
    // gestellt, bevor opendir() sie mit einer Warnung beantwortet.
    if (!is_dir('/proc')) {
        return $aus;
    }
    $d = @opendir('/proc');
    if ($d === false) {
        return $aus;
    }
    while (($e = readdir($d)) !== false) {
        if (!preg_match('/^[0-9]+$/', $e)) {
            continue;
        }
        $roh = @file_get_contents('/proc/' . $e . '/cmdline');
        if ($roh === false || $roh === '') {
            continue;
        }
        $args = explode("\0", rtrim($roh, "\0"));
        if (count($args) !== 2 || $args[1] !== $skript) {
            continue;
        }
        $i = basename($args[0]);
        if ($i !== 'python' && $i !== 'python3' && strpos($i, 'python3.') !== 0) {
            continue;
        }
        $besitzer = @fileowner('/proc/' . $e);
        if ($besitzer === false || !in_array((int) $besitzer, $uids, true)) {
            continue;
        }
        $aus[] = (int) $e;
    }
    closedir($d);
    sort($aus);
    return $aus;
}

/**
 * Laeuft der Dienst? Rueckgabe: PID oder 0.
 *
 * Die PID-Datei bleibt die erste Frage - steht ihre Nummer unter den Treffern,
 * ist sie die Antwort. Sonst gilt der erste Treffer; eine PID-Datei, die ins
 * Leere zeigt, wird abgeraeumt.
 */
function bl_dienst_pid()
{
    $pids = bl_dienst_pids();
    $datei = bl_pid_datei();
    if (!$pids) {
        if (is_file($datei)) {
            @unlink($datei);   // zeigt ins Leere - von einem Absturz uebrig
        }
        return 0;
    }
    if (is_file($datei)) {
        $eingetragen = (int) trim((string) @file_get_contents($datei));
        if (in_array($eingetragen, $pids, true)) {
            return $eingetragen;
        }
    }
    return $pids[0];
}

/** Dienst starten, stoppen, neu starten. */
function bl_dienst($aktion)
{
    $p = bl_paths();
    // Seit 1.3.19: aus einem ausgepackten Archiv wird kein Dienst gestartet
    // oder angehalten (Muster 3, Fall P4).
    if ($p['home'] === '') {
        return bl_archiv_text();
    }
    $skript = $p['bindir'] . '/ble_scanner_ng.py';
    $datei = bl_pid_datei();
    $meldungen = array();

    if (in_array($aktion, array('stop', 'restart'), true)) {
        // ALLE eigenen Prozesse, nicht einer. Bis 1.3.16 wurde genau die eine
        // Nummer aus bl_dienst_pid() beendet; lief nach einem Update ein
        // zweiter Dienst, blieb er stehen und suchte weiter (in WSL gemessen
        // 18.09.2026, Fall p4: nach 'stop' lief noch einer).
        $pids = bl_dienst_pids();
        if ($pids) {
            foreach ($pids as $pid) {
                @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            }
            // Dem Dienst Zeit lassen: er nimmt die Suche zurueck und meldet
            // server/online=0 an den Broker. Wird er hart abgeschossen,
            // bleibt im Broker ein retained '1' stehen.
            for ($i = 0; $i < 20; $i++) {
                usleep(500000);
                if (!bl_dienst_pids()) {
                    break;
                }
            }
            // Vor dem -9 wird NEU gesucht, nicht angenommen: die Nummer eines
            // beendeten Prozesses vergibt der Kern weiter.
            $rest = bl_dienst_pids();
            if ($rest) {
                foreach ($rest as $pid) {
                    @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
                }
                usleep(500000);
                $meldungen[] = 'Der Dienst reagierte nicht auf SIGTERM und wurde abgeschossen.';
            }
            if (count($pids) > 1) {
                $meldungen[] = 'Es liefen ' . count($pids) . ' Prozesse dieses Dienstes - alle wurden beendet.';
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
        // NEU IN 1.3.20 (Pruefung 29.09.2026, C4): laeuft schon einer, wird
        // KEIN zweiter gestartet. Bis 1.3.19 legte ein zweites "Dienst
        // starten" - oder F5 nach dem ersten - einen zweiten Scanner daneben
        // (gemessen: zwei Prozesse). Der Dienst nimmt zusaetzlich selbst eine
        // Sperre und endet als zweiter mit Rueckgabe 3.
        $laufend = bl_dienst_pids();
        if ($laufend) {
            $meldungen[] = sprintf(bl_t('TEST.LAEUFT_SCHON'), implode(', ', $laufend));
            bl_soll_setzen(true);
            return implode("\n", $meldungen);
        }
        // Seit 1.3.20 nur in die Startdatei: das Protokoll oeffnet der Dienst
        // selbst (C5). Hier landet, was vor dessen Einrichtung scheitert.
        $log = $p['logdir'] . '/ble_scanner_ng_start.log';
        @mkdir(dirname($datei), 0775, true);
        @mkdir($p['logdir'], 0775, true);
        // Die Prozessnummer wird von der Shell weggeschrieben, nicht geraten.
        // 1.3.36 (Fremdpruefung 09.11.2026): Eingabe aus /dev/null und die Deskriptoren 3 bis 9 fuer den Dienst
        // geschlossen - er soll nichts vom Webserver-Prozess erben (wie befehlsbruecke.sh 8<&-). Nicht gemessen,
        // ob Apache dort etwas ohne O_CLOEXEC offen haelt (fdinfo der Arbeitsprozesse nicht lesbar).
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 < /dev/null 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- & echo $! > ' . escapeshellarg($datei) . '; echo gestartet', $meldungen);
        // Drei Sekunden, dann nachsehen, ob er NOCH lebt (Regeln/03 Z. 397:
        // an Python gemessen; bis 1.3.19 galt er nach 0,5 s als gestartet,
        // und ein spaeterer Tod blieb unbemerkt).
        sleep(3);
        if (bl_dienst_pid() > 0) {
            // Der Sollmerker faellt erst nach dem gelungenen Start (Regeln/03,
            // "erst nach erfolgreicher Pruefung gesetzt").
            bl_soll_setzen(true);
        }
    }
    if ($aktion === 'stop') {
        // "Dienst anhalten" gilt bis zum naechsten "Dienst starten" - auch
        // ueber einen Neustart des LoxBerry und ein Update hinweg (daemon,
        // Waechter und postupgrade.sh fragen den Sollmerker, seit 1.3.20).
        bl_soll_setzen(false);
    }
    return implode("\n", $meldungen);
}

/**
 * Der Sollmerker (seit 1.3.20, Bauliste 29.09.2026 C8/B1).
 *
 * Er liegt NEBEN dem Datenordner (data/plugins/<ordner>.soll_laufen), weil der
 * Installer den Ordner bei jedem Upgrade abraeumt. Inhalt "0": angehalten.
 * Fehlt er, gilt "eingeschaltet" - so verhaelt sich jede bestehende
 * Installation wie bisher. Gelesen wird er von daemon/daemon, dem Waechter
 * cron/cron.05min und postupgrade.sh.
 */
function bl_soll_datei()
{
    $p = bl_paths();
    return $p['home'] !== '' ? $p['datadir'] . '.soll_laufen' : '';
}

/** true = der Dienst soll laufen. */
function bl_soll_laufen()
{
    $f = bl_soll_datei();
    if ($f === '' || !is_file($f)) {
        return true;
    }
    return trim((string) @file_get_contents($f)) !== '0';
}

function bl_soll_setzen($ein)
{
    $f = bl_soll_datei();
    if ($f === '') {
        return false;
    }
    if ($ein) {
        return !is_file($f) || @unlink($f);
    }
    return bl_datei_schreiben($f, "0\n", 0644);
}

/** Die Meldung, wenn diese Oberflaeche nicht in der Installation liegt. */
function bl_archiv_text()
{
    $a = bl_paths()['archiv'];
    return sprintf(bl_t('TEXT.ARCHIV_VERWEIGERT'), $a !== '' ? $a : '-');
}

/**
 * Einen Auftrag an den laufenden Dienst geben, OHNE ihn neu zu starten.
 *
 * Die Oberflaeche erreicht den Dienst sonst nur ueber die
 * Konfigurationsdatei - und deren Aenderung loest einen Neustart aus. Fuer
 * Testmodus und Kalibrierung ist das gerade nicht erwuenscht.
 *
 * Rueckgabe seit 1.3.19: '' = eingereiht, sonst der Grund in Worten.
 * BERICHTIGT IN 1.3.19 (Muster 5): bis 1.3.18 wurde der Auftrag auch ohne
 * laufenden Dienst eingereiht, und der naechste Start fuhr ihn ungefragt hoch
 * (Faelle P6, S1); aus einem Archiv schrieb er in die Steuerdatei der Anlage
 * (P5). Der Dienst verwirft zusaetzlich jeden Auftrag, der aelter als 60 s ist.
 */
function bl_steuern($art, $kennung = '', $dauer = 0, $bruecke = false)
{
    if (bl_paths()['home'] === '') {
        return $bruecke ? 'ARCHIV' : bl_archiv_text();
    }
    if (bl_dienst_pid() <= 0) {
        return $bruecke ? 'DIENST_AUS' : bl_t('TEST.STEUER_OHNE_DIENST');
    }
    $daten = array('art' => (string) $art, 'kennung' => (string) $kennung,
                   'dauer' => (int) $dauer, 'zeit' => time());
    $json = json_encode($daten, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return $bruecke ? 'STEUER_FEHLER' : bl_t('TEST.STEUER_FEHLER');
    }
    // E3 (Gruppe 13): die Auftragsdatei ist EIN Platz - der Dienst liest sie je Takt einmal und loescht sie. Bis
    // 1.3.27 ueberschrieb ein zweiter Auftrag binnen eines Taktes den ersten (heute ohne Folgen, weil nur die
    // Oberflaeche schrieb; mit der Befehlsbruecke schreiben zwei). Jetzt: warten, solange sie noch liegt, hoechstens
    // 2 x intervall + 1 s in 0,25-s-Schritten; Pruefen und Schreiben unter einer kurzen Sperrdatei <steuer>.sperre
    // (flock). Kein Dienstcode aendert sich; die Bruecke haelt keinen Kindprozess offen, also nichts zu vererben.
    $ziel = bl_paths()['steuer'];
    list($cfg) = bl_config_read();
    $ende = microtime(true) + 2 * max(2, (int) bl_cfg($cfg, 'intervall', '5')) + 1;
    $sh = @fopen($ziel . '.sperre', 'c');
    while (true) {
        $gesperrt = $sh !== false && @flock($sh, LOCK_EX);
        clearstatcache(true, $ziel);
        if (!is_file($ziel)) {
            $ok = bl_datei_schreiben($ziel, $json, 0640);
            if ($gesperrt) { @flock($sh, LOCK_UN); }
            if ($sh !== false) { @fclose($sh); }
            return $ok ? '' : ($bruecke ? 'STEUER_FEHLER' : bl_t('TEST.STEUER_FEHLER'));
        }
        if ($gesperrt) { @flock($sh, LOCK_UN); }
        if (microtime(true) >= $ende) {
            break;
        }
        usleep(250000);
    }
    if ($sh !== false) { @fclose($sh); }
    return $bruecke ? 'BELEGT' : bl_t('TEST.STEUER_BELEGT');
}

/**
 * Einen Befehl mit Zeitgrenze - IMMER mit Nachschlag fuer SIGKILL.
 *
 * NEU IN 1.3.19 (Muster 13 der Nachlese). Bis 1.3.18 stand an vier Stellen
 * "timeout <n>" ohne -k: nach der Frist schickt timeout nur SIGTERM, und ein
 * Programm, das es nicht annimmt (ein haengender D-Bus-Aufruf), hielt die
 * Seite bis zu seinem eigenen Ende fest. In WSL gemessen
 * (Pruefung-BLE-Scanner-1.3.19, Fall K1): Frist 2 s, SIGTERM-fester Befehl,
 * 28 s statt 7 s. Die Rueckgabe 124 (Frist) und 137 (hart beendet) wertet
 * bl_sh() aus.
 */
function bl_frist($sekunden, $befehl)
{
    return 'timeout -k 5 ' . (int) $sekunden . ' ' . $befehl;
}

/** Adresse des MQTT-Brokers, nur zur Anzeige, ohne Kennwort. */
function bl_mqtt_broker()
{
    // Ohne Wurzel gibt es keine general.json - bis 1.3.18 wurde dann
    // /config/system/general.json ab der Laufwerkswurzel gefragt (Fall P7).
    if (bl_paths()['home'] === '') {
        return '';
    }
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
    if (bl_paths()['home'] === '') {
        return null;
    }
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
    if (bl_paths()['home'] === '') {
        return array();
    }
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
            'user' => bl_ms_zugang($ms, 'Admin_raw', 'Admin', 'Username'),
            'pass' => bl_ms_zugang($ms, 'Pass_raw', 'Pass', 'Password'),
        );
    }
    return $out;
}

/**
 * Ein Zugangsfeld des Miniservers im Klartext.
 *
 * LoxBerry fuehrt Benutzer und Passwort zweimal: 'Admin'/'Pass' fuer die
 * Adresse kodiert (aus '!' wird '%21'), 'Admin_raw'/'Pass_raw' im Klartext
 * (LoxBerry::System, read_generaljson). Bis 1.3.36 nahm das Plugin das
 * kodierte Feld fuer die Basic-Anmeldung - ein Passwort mit Sonderzeichen
 * ergab HTTP 401. Gefunden als Bauart von Beschattungswaechter Issue #2
 * (09.11.2026). Vorrang hat das Klartextfeld; fehlt es (aeltere Anlage),
 * wird das kodierte Feld zurueckgewandelt. rawurldecode, nicht urldecode:
 * ein '+' im Passwort bleibt ein '+'. Der dritte Name ist die alte
 * Schreibweise und war nie kodiert.
 */
function bl_ms_zugang(array $ms, $roh, $kodiert, $alt)
{
    if (isset($ms[$roh]) && is_scalar($ms[$roh]) && (string) $ms[$roh] !== '') {
        return (string) $ms[$roh];
    }
    if (isset($ms[$kodiert]) && is_scalar($ms[$kodiert]) && (string) $ms[$kodiert] !== '') {
        return rawurldecode((string) $ms[$kodiert]);
    }
    if (isset($ms[$alt]) && is_scalar($ms[$alt]) && (string) $ms[$alt] !== '') {
        return (string) $ms[$alt];
    }
    return '';
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
 * 'einheit' erscheint als Unit="<v.0> ..." in der Vorlage - <v.1> nur bei
 *              distance und den Messwerten temperatur/feuchte/druck
 *              (bl_vorlage_stellen(), seit 1.3.24).
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

/**
 * Messwerte aus den Werbedaten - Katalog mit Grenzen und Einheit.
 *
 * NEU IN 1.3.14, und zwar als Behebung einer Luecke, die seit 1.2.x offen
 * war: der Dienst sendet diese Werte unter "<Tag>/sensor/<Name>"
 * (ble_scanner_ng.py, "{0}/sensor/{1}"), aber bl_zusatzthemen() kannte sie
 * nicht - und die Loxone-Vorlage entsteht aus genau dieser Liste. Wer sie
 * importierte, bekam KEINE Zeile fuer Temperatur oder Luftfeuchte, ohne dass
 * irgendwo etwas fehlte oder meldete. Mit 1.3.13 (MiBeacon) wurde das zum
 * eigentlichen Mangel: seither liest das Plugin ein Xiaomi-Thermometer ab
 * Werk, und der Wert kam trotzdem nicht am Miniserver an.
 *
 * DIE GRENZEN SIND DIESELBEN wie in bin/bl_beacon.py (GRENZEN). Dort werden
 * sie zum Verwerfen unplausibler Werte gebraucht, hier als Min/Max des
 * virtuellen Eingangs. Zwei Listen laufen auseinander - deshalb misst der
 * Reiter "Test" sie gegeneinander nach (bl_grenzen_vergleich()).
 */
function bl_sensor_katalog()
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    $k = bl_sensorthemen_lesen();
    if (!$k) {
        $k = bl_sensor_rueckfall();
    }
    return $k;
}

/**
 * Der eingebaute Katalog - er greift nur, wenn bin/bl_beacon.py fehlt oder
 * unlesbar ist, etwa waehrend eines Updates.
 *
 * WARUM ALS EIGENE FUNKTION: damit der Reiter "Test" ihn gegen die Quelle
 * halten kann. Steckte er als Zweig in bl_sensor_katalog(), bekaeme man ihn
 * nur zu fassen, indem man die Quelle entfernt - und eine Pruefung, die
 * dafuer erst die Datei wegnehmen muesste, gibt es nicht. Beim ersten
 * Versuch in dieser Fassung verglich die Pruefzeile deshalb den Katalog mit
 * der Quelle, also die Quelle mit sich selbst: sie konnte gar nicht
 * ansprechen. Gemessen und berichtigt, bevor sie eingebaut blieb.
 *
 * Ein Rueckfall, den niemand prueft, ist nur eine zweite Wahrheit.
 */
function bl_sensor_rueckfall()
{
    $k = array(
        'temperatur'  => array('min' => -999, 'max' => 90,        'einheit' => '°C'),
        'feuchte'     => array('min' => 0,   'max' => 100,        'einheit' => '%'),
        'druck'       => array('min' => 500, 'max' => 1200,       'einheit' => 'hPa'),
        'batterie'    => array('min' => 0,   'max' => 100,        'einheit' => '%'),
        'batterie_mv' => array('min' => 500, 'max' => 4500,       'einheit' => 'mV'),
        'pakete'      => array('min' => 0,   'max' => 4294967295, 'einheit' => ''),
        'laufzeit_s'  => array('min' => 0,   'max' => 4294967295, 'einheit' => 's'),
        'bewegung'    => array('min' => 0,   'max' => 255,        'einheit' => ''),
        'folge'       => array('min' => 0,   'max' => 65535,      'einheit' => ''),
        // Gruppe 13 (V2, BTHome v2)
        'helligkeit'  => array('min' => 0,   'max' => 120000,     'einheit' => 'lx'),
        'fenster'     => array('min' => 0,   'max' => 1,          'einheit' => ''),
        'bewegungsmelder' => array('min' => 0, 'max' => 1,        'einheit' => ''),
        // Gruppe 33 (1.3.29): je Thermometer
        'lage'        => array('min' => 0,   'max' => 5,          'einheit' => ''),
        'alter'       => array('min' => -1,  'max' => 2147483647, 'einheit' => 's'),
    );
    foreach ($k as $n => $_i) {
        $k[$n]['art'] = 'analog';
        $k[$n]['s']   = 'THEMA.S_' . strtoupper($n);
    }
    return $k;
}

/**
 * SENSORTHEMEN aus bin/bl_beacon.py lesen.
 *
 * NEU IN 1.3.14 - und damit wird eine Behauptung wahr, die dort seit
 * laengerem steht. Ueber der Liste in bl_beacon.py steht woertlich:
 *
 *     "Themennamen der Sensorwerte. Die Oberflaeche und die Loxone-Vorlage
 *      lesen diese Liste - so kann sie nicht von dem abweichen, was hier
 *      entsteht."
 *
 * Gemessen am 13.09.2026: KEINE einzige PHP-Datei las sie (0 Treffer fuer
 * "SENSORTHEMEN" im ganzen webfrontend/). Die Loxone-Seite kannte ueberhaupt
 * keinen Messwert, und der Satz war seit seiner Niederschrift falsch. Wer
 * eine Quelle zur "einen Quelle" erklaert, muss sie auch lesen - sonst ist
 * der Kommentar nur ein Vorsatz.
 *
 * Rueckgabe: array(Thema => array('s', 'art', 'min', 'max', 'einheit')),
 * oder array() wenn die Datei fehlt oder die Liste nicht lesbar ist.
 */
function bl_sensorthemen_lesen()
{
    $datei = bl_paths()['bindir'] . '/bl_beacon.py';
    if (!is_file($datei)) {
        return array();
    }
    $quelle = (string) @file_get_contents($datei);
    if (!preg_match('/^SENSORTHEMEN\s*=\s*\{(.*?)^\}/ms', $quelle, $m)) {
        return array();
    }
    $out = array();
    // "temperatur":  ("Temperatur", "°C", -45, 90),
    // Die Beschreibung (zweites Feld) muss passen, damit die Zeile erkannt
    // wird, gefangen wird sie nicht mehr: bis 1.3.25 hielt der Katalog sie
    // als Feld ersatz fuer bl_sensor_text(), den Rueckfall bei fehlendem
    // Sprachschluessel. Der Helfer wurde nie aufgerufen und ist seit 1.3.25
    // weg, das Feld seit 1.3.26. Die Beschriftung kommt aus THEMA.S_<NAME>.
    $muster = '/"([a-z_0-9]+)"\s*:\s*\(\s*"[^"]*"\s*,\s*"([^"]*)"\s*,'
            . '\s*(-?[0-9]+)\s*,\s*(-?[0-9]+)\s*\)/u';
    if (preg_match_all($muster, $m[1], $treffer, PREG_SET_ORDER)) {
        foreach ($treffer as $t) {
            $out[$t[1]] = array(
                's'       => 'THEMA.S_' . strtoupper($t[1]),
                'art'     => 'analog',
                'einheit' => $t[2],
                'min'     => (int) $t[3],
                'max'     => (int) $t[4],
            );
        }
    }
    return $out;
}

/**
 * Welche Messwerte hat DIESER Tag wirklich geliefert?
 *
 * Gemessen aus dem Abbild des Dienstes, nicht geraten. Der Grund steht in
 * bl_test.php: eine Vorlage, die jedem Tag alle fuenf Messwerte anlegt, gibt
 * einem Schluesselanhaenger fuenf virtuelle Eingaenge, die dauerhaft auf 0
 * stehen - genau die Sorte Karteileiche, gegen die der Themenvergleich
 * gebaut ist. Ein Xiaomi-Thermometer bekommt Temperatur und Feuchte, ein
 * Anhaenger bekommt nichts.
 *
 * Folge fuer den Anwender: die Vorlage wird erst vollstaendig, wenn der Tag
 * einmal gesendet hat. Der Reiter "Einbindung in Loxone" sagt das.
 */
function bl_sensor_themen($kennung)
{
    $katalog = bl_sensor_katalog();
    $out = array();
    $abbild = bl_zustaende();
    // Gruppe 33: ein verschluesselter Fuehler ohne passenden Schluessel liefert keine Werte, aber eine Lage - auch
    // er bekommt seine Themen (das Format steht fest, sobald er einmal gesendet hat).
    if (!isset($abbild[$kennung]) || (empty($abbild[$kennung]['sensor'])
            && !isset($abbild[$kennung]['sensor_lage']))) {
        return $out;
    }
    $z = $abbild[$kennung];

    // WELCHE Groessen - das bestimmt das erkannte FORMAT, nicht das letzte
    // Paket. Begruendung und Messung stehen bei FORMAT_GROESSEN in
    // bin/bl_beacon.py: MiBeacon wechselt die Satzarten, das Abbild traegt
    // immer nur das zuletzt Gesehene, und die Vorlage waere damit nicht
    // reproduzierbar. Am 13.09.2026 am Geraet aufgefallen - die erste
    // Fassung dieser Funktion gab demselben Tag einmal Temperatur und
    // Feuchte, sieben Sekunden spaeter nur Feuchte.
    $arten = bl_format_groessen();
    // ACHTUNG: "beaconart" im Abbild ist die BESCHRIFTUNG, nicht der blosse
    // Formatname - bl_beacon.beschriftung() haengt Geraet und Hinweis an
    // ("mibeacon LYWSDCGQ/01ZM", "mibeacon (verschluesselt)"). Der Formatname
    // ist das erste Wort. Beim Bauen dieser Fassung stand hier zuerst ein
    // Vergleich gegen den ganzen Text; er traf nie, und die Funktion fiel
    // still auf den Abbild-Zweig zurueck - also genau in den Fehler, den sie
    // beheben sollte. Am Geraet aufgefallen, weil die Vorlage weiter
    // schwankte (6, 6, 6, 5, 4 Eintraege in 30 Sekunden).
    $art = isset($z['beaconart']) ? trim((string) $z['beaconart']) : '';
    if ($art !== '') {
        $art = strtok($art, " \t(");
    }
    if ($art !== '' && isset($arten[$art]) && $arten[$art]) {
        $namen = $arten[$art];
    } else {
        // Format unbekannt oder nicht in der Tabelle: dann bleibt nur das,
        // was tatsaechlich gesehen wurde. Lieber unvollstaendig als geraten.
        $namen = array_keys((array) $z['sensor']);
    }
    // Was der Tag tatsaechlich schon geliefert hat, kommt IMMER dazu - falls
    // ein Format mehr kann, als die Tabelle kennt.
    $namen = array_unique(array_merge($namen, array_keys((array) $z['sensor'])));
    // Gruppe 33: Thermometer (Temperatur oder Feuchte) bekommen sensor/lage und sensor/alter - wie der Dienst.
    if (in_array('temperatur', $namen, true) || in_array('feuchte', $namen, true)) {
        $namen = array_unique(array_merge($namen, array('lage', 'alter')));
    }
    sort($namen);
    foreach ($namen as $name) {
        $name = (string) $name;
        if (isset($katalog[$name])) {
            $out['sensor/' . $name] = $katalog[$name];
        }
    }
    return $out;
}

/**
 * FORMAT_GROESSEN aus bin/bl_beacon.py lesen - dieselbe Quelle wie
 * SENSORTHEMEN, aus demselben Grund: eine zweite Liste liefe weg.
 */
function bl_format_groessen()
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $f = array();
    $datei = bl_paths()['bindir'] . '/bl_beacon.py';
    if (!is_file($datei)) {
        return $f;
    }
    $quelle = (string) @file_get_contents($datei);
    if (!preg_match('/^FORMAT_GROESSEN\s*=\s*\{(.*?)^\}/ms', $quelle, $m)) {
        return $f;
    }
    if (preg_match_all('/"(\w+)"\s*:\s*\(([^)]*)\)/', $m[1], $t, PREG_SET_ORDER)) {
        foreach ($t as $e) {
            $namen = array();
            if (preg_match_all('/"(\w+)"/', $e[2], $n)) {
                $namen = $n[1];
            }
            $f[$e[1]] = $namen;
        }
    }
    return $f;
}

/**
 * Alle ueberhaupt moeglichen Zusatzthemen - unabhaengig von Einstellungen
 * und davon, ob ein Tag schon gesendet hat.
 *
 * Gebraucht wird das NUR vom Themenvergleich im Reiter "Test": der fragt
 * nicht "steht es heute in der Vorlage?", sondern "gibt es ueberhaupt einen
 * Eintrag dafuer?". Ohne diese Trennung war "bedingt" gleichbedeutend mit
 * "ignorieren" - siehe bl_test.php.
 */
function bl_zusatzthemen_alle()
{
    $out = array(
        'distance'   => array('s' => 'THEMA.DISTANCE',   'art' => 'analog', 'min' => -1, 'max' => 1000, 'einheit' => 'm'),
        'battery'    => array('s' => 'THEMA.BATTERY',    'art' => 'analog', 'min' => -1, 'max' => 100,  'einheit' => '%'),
        // Gruppe 13: Taster-Ereignis (V2, nur mit bthome) und Ergebnis der Kalibrierung (E5)
        'button'       => array('s' => 'THEMA.BUTTON',       'art' => 'text',   'min' => 0,    'max' => 0, 'einheit' => ''),
        'kalibrierung' => array('s' => 'THEMA.KALIBRIERUNG', 'art' => 'analog', 'min' => -255, 'max' => 0, 'einheit' => 'dBm'),
        'battery_ts' => array('s' => 'THEMA.BATTERY_TS', 'art' => 'analog', 'min' => 0,  'max' => 2147483647, 'einheit' => ''),
        'raum'       => array('s' => 'THEMA.RAUM',       'art' => 'text',   'min' => 0,  'max' => 0,    'einheit' => ''),
        'raum_seit'  => array('s' => 'THEMA.RAUM_SEIT',  'art' => 'analog', 'min' => 0,  'max' => 2147483647, 'einheit' => ''),
    );
    foreach (bl_sensor_katalog() as $name => $info) {
        $out['sensor/' . $name] = $info;
    }
    return $out;
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
                                'min' => -1, 'max' => 100, 'einheit' => '%');
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
        // Gruppe 13: das Lebenszeichen fuer Loxone (hoechstens alle 30 s, 0-999, nie retained)
        'status/zaehler'        => array('s' => 'THEMA.STATUS_ZAEHLER', 'art' => 'analog', 'min' => 0, 'max' => 999, 'einheit' => ''),
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
    // Formatplatzhalter heraus, dann fuehrende Schraegstriche:
    //   "{0}/present"      -> "present"
    //   "{0}/sensor/{1}"   -> "sensor/"        (Stamm, mit Schraegstrich)
    //   "server/ts"        -> "server/ts"
    //
    // BERICHTIGT IN 1.3.14. Die beiden Schleifen unten trugen ZWEI
    // VERSCHIEDENE Normalisierungen: die erste entfernte "{0}/" und "{1}",
    // die zweite nur ein fuehrendes "{0}/". Weil beide Regeln denselben
    // Aufruf treffen, landete "{0}/sensor/{1}" doppelt in der Liste - einmal
    // als "sensor/" und einmal als "sensor/{1}". Das zweite ist keine
    // Themenbezeichnung, sondern ein Ueberrest der Zerlegung.
    //
    // Aufgefallen ist das erst, als der Themenvergleich in dieser Fassung
    // strenger wurde: vorher passte "sensor/{1}" auf den bedingten Stamm
    // "sensor/" und wurde stillschweigend verworfen. Zwei Wege, die dasselbe
    // tun sollen, tun es verschieden - deshalb jetzt EINE Normalisierung.
    $saeubern = function ($roh) {
        return ltrim(preg_replace('/\{[0-9]+\}/', '', $roh), '/');
    };
    // self._senden("...")  und  self.senden("...")  und  self.mqtt.senden("...")
    if (preg_match_all('/\b_?senden\(\s*"([^"]+)"/', $quelle, $m)) {
        foreach ($m[1] as $roh) {
            $out[$saeubern($roh)] = true;
        }
    }
    if (preg_match_all('/\b_?senden\(\s*"([^"]*)"\.format/', $quelle, $m2)) {
        foreach ($m2[1] as $roh) {
            $out[$saeubern($roh)] = true;
        }
    }
    unset($out['']);
    return array_keys($out);
}

/**
 * Die Protokolle des Plugins, benannt (Verbesserungsbau 30.09.2026, a2).
 *
 * Bis 1.3.21 zeigte der Reiter Logdateien nur die juengste *.log-Datei. Seit
 * 1.3.20 gibt es aber zwei: ble_scanner_ng.log schreibt der Dienst selbst,
 * ble_scanner_ng_start.log fuellen nur die Startwege (daemon, Waechter,
 * Oberflaeche, postinstall/postupgrade) mit dem, was VOR dem Dienst scheitert.
 * Je nach Zeitpunkt stand die eine oder die andere da, ohne Namen.
 *
 * Rueckgabe: Liste von array('art' => dienst|start|weitere, 'datei' => Pfad),
 * in fester Reihenfolge: Dienst, Start, dann jede weitere *.log-Datei des
 * Ordners, juengste zuerst. Nur Dateien, die es gibt.
 */
function bl_log_dateien()
{
    $ordner = bl_paths()['logdir'];
    $aus = array();
    $bekannt = array('dienst' => $ordner . '/ble_scanner_ng.log',
                     'start'  => $ordner . '/ble_scanner_ng_start.log');
    foreach ($bekannt as $art => $datei) {
        if (is_file($datei)) {
            $aus[] = array('art' => $art, 'datei' => $datei);
        }
    }
    $rest = array();
    foreach ((array) glob($ordner . '/*.log') as $datei) {
        if (is_string($datei) && is_file($datei) && !in_array($datei, $bekannt, true)) {
            $rest[] = $datei;
        }
    }
    usort($rest, function ($a, $b) {
        $fa = @filemtime($a); $fb = @filemtime($b);
        return ($fb ?: 0) - ($fa ?: 0);
    });
    foreach ($rest as $datei) {
        $aus[] = array('art' => 'weitere', 'datei' => $datei);
    }
    return $aus;
}

/**
 * Das Dienstprotokoll - die Datei, die die Kappung betrifft ('' = keins).
 * Bis 1.3.21 die juengste *.log-Datei, also oft das Startprotokoll, das gar
 * nicht gekappt wird; die Pruefzeile "Protokoll" mass dann die falsche Datei.
 */
function bl_log_file()
{
    foreach (bl_log_dateien() as $l) {
        if ($l['art'] === 'dienst') {
            return $l['datei'];
        }
    }
    return '';
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

/* Gruppe 13 (1.3.28, E12): bl_xml_virtual_in_http(), bl_vorlage() und ihre Helfer bl_zeichen(),
 * bl_kurz(), bl_kommentar(), bl_vorlage_stellen() sind entfallen - die Vorlage legte Virtuelle
 * HTTP-Eingaenge an http://localhost an, den verbotenen Kunstgriff (Wiki widget_mqtt). Ersatz:
 * Namenstabelle bl_mqtt_namen() und Vorlage MQTT-Befehle bl_vorlage_mqtt_out() (unten). */

/** Die Personen der aktiven Tags (Zusatzangabe "person"), in Tag-Reihenfolge und so
 *  gesaeubert wie im Dienst (thema_saeubern) - daraus werden die Themen person/<P>/... */
function bl_personen($tags)
{
    $aus = array();
    foreach ($tags as $tag) {
        if (!isset($tag['aktiv']) || $tag['aktiv'] !== '1') {
            continue;
        }
        $pn = isset($tag['opt']['person']) ? bl_saeubern(trim((string) $tag['opt']['person'])) : '';
        if ($pn !== '' && !in_array($pn, $aus, true)) {
            $aus[] = $pn;
        }
    }
    return $aus;
}

/** "Anna_Handy=Anna, Bernd=Bernd" -> array(array('Anna_Handy', 'Anna'), ...) - dieselbe
 *  Regel wie wlan_zuordnung_lesen() in bin/bl_common.py: ein Paar, das nicht so aussieht,
 *  wird uebergangen, doppelte Namen: das erste Paar gilt, hoechstens 20. */
function bl_wlan_zuordnung($text)
{
    $aus = array();
    $wl = array();
    $pe = array();
    foreach (explode(',', (string) $text) as $stueck) {
        if (!preg_match('/^([A-Za-z0-9_-]{1,40})\s*=\s*([A-Za-z0-9_-]{1,40})$/', trim($stueck), $m)) {
            continue;
        }
        if (isset($wl[$m[1]]) || isset($pe[$m[2]])) {
            continue;
        }
        $wl[$m[1]] = true;
        $pe[$m[2]] = true;
        $aus[] = array($m[1], $m[2]);
    }
    return array_slice($aus, 0, 20);
}

/**
 * Zuordnungen als Tabelle, eine Zeile je Paar (1.3.36, Hausherr 09.11.2026: das einzeilige Kommafeld
 * war bei sieben Paaren nicht mehr lesbar). Gespeichert wird unveraendert die Kommaliste
 * "<links>=<rechts>, ..." - Dienst, Sicherung und bestehende Anlagen merken nichts davon.
 *
 * bl_zuordnung_paare(): eine Kommaliste ungeprueft in Paare zerlegen, nur fuer die Anzeige.
 */
function bl_zuordnung_paare($text)
{
    $aus = array();
    foreach (explode(',', (string) $text) as $s) {
        $s = trim($s);
        if ($s === '') {
            continue;
        }
        $p = strpos($s, '=');
        $aus[] = $p === false ? array($s, '') : array(trim(substr($s, 0, $p)), trim(substr($s, $p + 1)));
    }
    return $aus;
}

/**
 * Die Zeilen <vor>_links[i], <vor>_rechts[i], <vor>_alt[i] aus $_POST lesen und jede einzeln gegen
 * die Regel $regel halten (Regeln/03: bei Listen beanstanden, den bisherigen Eintrag stehen lassen,
 * weitermachen). Eine leere Zeile ist kein Paar - wer beide Felder leert, loescht das Paar.
 * Rueckgabe: null, wenn das Formular keine Tabelle schickte; sonst array(angenommen, getippt):
 * angenommen = die Kommaliste zum Speichern, getippt = wie eingegeben (fuer die Anzeige nach einer
 * Beanstandung). Jede Beanstandung kommt nach $fehler, mit Zeile, Eingabe, Grund und was bleibt.
 */
function bl_zuordnung_zeilen($vor, $regel, $titel, &$fehler)
{
    $feld = function ($name) use ($vor) {
        return (isset($_POST[$vor . '_' . $name]) && is_array($_POST[$vor . '_' . $name]))
               ? $_POST[$vor . '_' . $name] : null;
    };
    $li = $feld('links');
    if ($li === null) {
        return null;
    }
    $re = $feld('rechts');
    $alt = $feld('alt');
    $wert = function ($liste, $i) {
        return (is_array($liste) && isset($liste[$i]) && is_string($liste[$i])) ? trim($liste[$i]) : '';
    };
    $angenommen = array();
    $links_da = array();
    $rechts_da = array();
    $getippt = array();
    $n = 0;
    foreach ($li as $i => $_l) {
        if (++$n > 60) {
            break;
        }
        $l = $wert($li, $i);
        $r = $wert($re, $i);
        if ($l === '' && $r === '') {
            continue;
        }
        $getippt[] = $l . '=' . $r;
        $grund = '';
        $wl = '';
        $wr = '';
        if ($l === '' || $r === '') {
            $grund = 'HALB';
        } else {
            list($ok, $w) = bl_wert_pruefen($regel, $l . '=' . $r);
            if (!$ok || strpos($w, ',') !== false) {
                $grund = 'MUSTER';
            } else {
                list($wl, $wr) = explode('=', $w, 2);
                if (isset($links_da[$wl]) || isset($rechts_da[$wr])) {
                    $grund = 'DOPPELT';
                }
            }
        }
        if ($grund === '') {
            $links_da[$wl] = true;
            $rechts_da[$wr] = true;
            $angenommen[] = $wl . '=' . $wr;
            continue;
        }
        $bleibt = '-';
        $a = $wert($alt, $i);
        if ($a !== '') {
            list($ok2, $w2) = bl_wert_pruefen($regel, $a);
            if ($ok2 && strpos($w2, ',') === false && strpos($w2, '=') !== false) {
                list($al, $ar) = explode('=', $w2, 2);
                if (!isset($links_da[$al]) && !isset($rechts_da[$ar])) {
                    $links_da[$al] = true;
                    $rechts_da[$ar] = true;
                    $angenommen[] = $al . '=' . $ar;
                    $bleibt = $al . '=' . $ar;
                }
            }
        }
        // Steuerzeichen weg wie bl_saubere_eingabe() in index.php - hier eigens, damit die Bibliothek
        // nicht von index.php abhaengt (der Pruefstand laedt sie allein).
        $sauber = function ($s) {
            return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $s));
        };
        $fehler[] = sprintf(bl_t('MANGEL.ZU_ZEILE'), $titel, (int) $i + 1, $sauber($l),
                            $sauber($r), bl_t('MANGEL.ZU_GRUND_' . $grund), $bleibt);
    }
    return array(implode(', ', $angenommen), implode(', ', $getippt));
}

/**
 * Die Tabelle zeichnen: die Paare aus $anzeige (nach einer Beanstandung das Eingetippte, sonst das
 * Gespeicherte), dazu drei leere Zeilen, hoechstens 23. Das versteckte <vor>_alt[i] traegt das
 * gespeicherte Paar derselben Zeile - es bleibt stehen, wenn die Zeile beanstandet wird.
 * Ausgeschriebener Index (Regeln/04), jeder Wert durch bl_e().
 */
function bl_zuordnung_tabelle($vor, $anzeige, $gespeichert, $kopf_links, $kopf_rechts, $platz_links, $platz_rechts, $markierung)
{
    $paare = bl_zuordnung_paare($anzeige);
    $alte = bl_zuordnung_paare($gespeichert);
    $zeilen = min(23, count($paare) + 3);
    $h = '<div' . $markierung . '><div class="sm-breit"><table class="sm-tbl">'
       . '<tr><th style="width:3em;">' . bl_e(bl_t('FELD.ZU_SP_NR')) . '</th><th>' . bl_e($kopf_links)
       . '</th><th>' . bl_e($kopf_rechts) . '</th></tr>';
    for ($i = 0; $i < $zeilen; $i++) {
        $l = isset($paare[$i]) ? $paare[$i][0] : '';
        $r = isset($paare[$i]) ? $paare[$i][1] : '';
        $a = isset($alte[$i]) ? $alte[$i][0] . '=' . $alte[$i][1] : '';
        $h .= '<tr><td>' . (int) ($i + 1) . '</td>'
            . '<td><input data-role="none" type="text" name="' . bl_e($vor) . '_links[' . (int) $i . ']" value="' . bl_e($l)
            . '" placeholder="' . bl_e($i === 0 ? $platz_links : '') . '" style="width:100%;box-sizing:border-box;">'
            . '<input data-role="none" type="hidden" name="' . bl_e($vor) . '_alt[' . (int) $i . ']" value="' . bl_e($a) . '"></td>'
            . '<td><input data-role="none" type="text" name="' . bl_e($vor) . '_rechts[' . (int) $i . ']" value="' . bl_e($r)
            . '" placeholder="' . bl_e($i === 0 ? $platz_rechts : '') . '" style="width:100%;box-sizing:border-box;"></td></tr>';
    }
    return $h . '</table></div></div>';
}

/**
 * Die Baustein-Listen des Reiters "Einbindung in Loxone", Schritt 7 - Welle Bild 19 (1.3.24, Hausherr
 * 11.10.2026: Vollausstattung, ein absoluter Laie soll das Maximum leicht uebernehmen koennen; Entwurf
 * Welle 19 bl, E1-E16, 18.10.2026). DREI Seiten wie im LoxBerry-Plugins Musterprojekt
 * (Musterprojekt/baustein_listen.txt, Abschnitte "BLE-Scanner NG" 27, "BLE-Scanner NG Tags" 15,
 * "BLE-Scanner NG Messwerte" 13 Zeilen). Bis 1.3.23 stand dort EINE Liste mit 19 Zeilen und darunter eine
 * Messwert-Tabelle. Berichtigt gegen sie: "Abwesenheit gilt" war ein UND hinter einer Flankenerkennung,
 * also ein Impuls von 1 s und kein Zustand; die Formeln "Wie lange schon weg" und "Alter des
 * Lebenszeichens" hatten keinen Zeiteingang und erkannten einen stehenden server_ts nie (jetzt
 * Analogwertvalidierung); das ODER "I1 bis In" je Tag ist jetzt person/<P>/present je Person.
 *
 * Jede Zeile ist EIN Sprachschluessel BAUSTEIN.Z<Seite>_<Nr> "Typ | Name | Parameter | Eingaenge"
 * (Zeilen mit Prozentzeichen dort doppelt und ueber sprintf() geholt, angezeigt mit einem; deutsch
 * wortgleich mit der Liste; Bausteinnamen auch englisch deutsch). DIE NAMEN WERDEN GERECHNET, NICHT
 * ABGESCHRIEBEN (bl_bausteinliste_ersatz()): mit Werkseinstellung und ohne Tags steht genau die Liste da.
 * Keine Leitung geht ueber eine Seitengrenze; blescanner_server_online steht auf Seite 3 als weitere
 * Instanz.
 *
 * Rueckgabe: Liste von array('nr', 'seite', 'bild', 'zeilen' => array(array(Nr, Typ, Name, Parameter,
 * Eingaenge), ...), 'schritte', 'hinweise'). Zellen und Schritte sind Klartext (beim Ausgeben maskiert),
 * die Hinweise freigegebenes HTML aus der Sprachdatei.
 */
function bl_bausteinliste($cfg, $tags)
{
    $ersatz = bl_bausteinliste_ersatz($cfg, $tags);
    // Nummer, Seitenname (= Config-Seite im Musterprojekt, deshalb fest), Bild, Zeilen, Kasten, Hinweise
    $seiten = array(
        array(1, 'BLE-Scanner NG', 'einbindung_loxone.png', array(
            bl_t('BAUSTEIN.Z1_01'),
            bl_t('BAUSTEIN.Z1_02'),
            bl_t('BAUSTEIN.Z1_03'),
            bl_t('BAUSTEIN.Z1_04'),
            bl_t('BAUSTEIN.Z1_05'),
            bl_t('BAUSTEIN.Z1_06'),
            bl_t('BAUSTEIN.Z1_07'),
            bl_t('BAUSTEIN.Z1_08'),
            bl_t('BAUSTEIN.Z1_09'),
            bl_t('BAUSTEIN.Z1_10'),
            bl_t('BAUSTEIN.Z1_11'),
            bl_t('BAUSTEIN.Z1_12'),
            bl_t('BAUSTEIN.Z1_13'),
            bl_t('BAUSTEIN.Z1_14'),
            bl_t('BAUSTEIN.Z1_15'),
            bl_t('BAUSTEIN.Z1_16'),
            bl_t('BAUSTEIN.Z1_17'),
            bl_t('BAUSTEIN.Z1_18'),
            bl_t('BAUSTEIN.Z1_19'),
            bl_t('BAUSTEIN.Z1_20'),
            bl_t('BAUSTEIN.Z1_21'),
            bl_t('BAUSTEIN.Z1_22'),
            bl_t('BAUSTEIN.Z1_23'),
            bl_t('BAUSTEIN.Z1_24'),
            bl_t('BAUSTEIN.Z1_25'),
            bl_t('BAUSTEIN.Z1_26'),
            bl_t('BAUSTEIN.Z1_27'),
        ), array(
            bl_t('BAUSTEIN.SEITE1_SCHRITT1'),
            bl_t('BAUSTEIN.SEITE1_SCHRITT2'),
            bl_t('BAUSTEIN.SEITE1_SCHRITT3'),
            bl_t('BAUSTEIN.SEITE1_SCHRITT4'),
            bl_t('BAUSTEIN.SEITE1_SCHRITT5'),
            bl_t('BAUSTEIN.SEITE1_SCHRITT6'),
            bl_t('BAUSTEIN.SEITE1_SCHRITT7'),
        ), array(
            bl_t('BAUSTEIN.SEITE1_H1'),
            bl_t('BAUSTEIN.SEITE1_H2'),
            bl_t('BAUSTEIN.SEITE1_H3'),
            bl_t('BAUSTEIN.SEITE1_H4'),
            bl_t('BAUSTEIN.SEITE1_H5'),
            bl_t('BAUSTEIN.SEITE1_H6'),
            bl_t('BAUSTEIN.SEITE1_H7'),
            bl_t('BAUSTEIN.SEITE1_H8'),
        )),
        array(2, 'BLE-Scanner NG Tags', 'einbindung_loxone_tags.png', array(
            bl_t('BAUSTEIN.Z2_01'),
            bl_t('BAUSTEIN.Z2_02'),
            bl_t('BAUSTEIN.Z2_03'),
            bl_t('BAUSTEIN.Z2_04'),
            bl_t('BAUSTEIN.Z2_05'),
            sprintf(bl_t('BAUSTEIN.Z2_06')),
            sprintf(bl_t('BAUSTEIN.Z2_07')),
            bl_t('BAUSTEIN.Z2_08'),
            bl_t('BAUSTEIN.Z2_09'),
            bl_t('BAUSTEIN.Z2_10'),
            bl_t('BAUSTEIN.Z2_11'),
            bl_t('BAUSTEIN.Z2_12'),
            bl_t('BAUSTEIN.Z2_13'),
            bl_t('BAUSTEIN.Z2_14'),
            bl_t('BAUSTEIN.Z2_15'),
            bl_t('BAUSTEIN.Z2_16'),
            bl_t('BAUSTEIN.Z2_17'),
            bl_t('BAUSTEIN.Z2_18'),
            bl_t('BAUSTEIN.Z2_19'),
            bl_t('BAUSTEIN.Z2_20'),
            bl_t('BAUSTEIN.Z2_21'),
            bl_t('BAUSTEIN.Z2_22'),
            bl_t('BAUSTEIN.Z2_23'),
            bl_t('BAUSTEIN.Z2_24'),
            bl_t('BAUSTEIN.Z2_25'),
            bl_t('BAUSTEIN.Z2_26'),
            bl_t('BAUSTEIN.Z2_27'),
        ), array(
            bl_t('BAUSTEIN.SEITE2_SCHRITT1'),
            bl_t('BAUSTEIN.SEITE2_SCHRITT2'),
            bl_t('BAUSTEIN.SEITE2_SCHRITT3'),
            bl_t('BAUSTEIN.SEITE2_SCHRITT4'),
            bl_t('BAUSTEIN.SEITE2_SCHRITT5'),
            bl_t('BAUSTEIN.SEITE2_SCHRITT6'),
        ), array(
            bl_t('BAUSTEIN.SEITE2_H1'),
            bl_t('BAUSTEIN.SEITE2_H2'),
            bl_t('BAUSTEIN.SEITE2_H3'),
            bl_t('BAUSTEIN.SEITE2_H4'),
            bl_t('BAUSTEIN.SEITE2_H5'),
        )),
        array(3, 'BLE-Scanner NG Messwerte', 'einbindung_loxone_messwerte.png', array(
            bl_t('BAUSTEIN.Z3_01'),
            bl_t('BAUSTEIN.Z3_02'),
            sprintf(bl_t('BAUSTEIN.Z3_03')),
            sprintf(bl_t('BAUSTEIN.Z3_04')),
            bl_t('BAUSTEIN.Z3_05'),
            bl_t('BAUSTEIN.Z3_06'),
            sprintf(bl_t('BAUSTEIN.Z3_07')),
            bl_t('BAUSTEIN.Z3_08'),
            bl_t('BAUSTEIN.Z3_09'),
            bl_t('BAUSTEIN.Z3_10'),
            bl_t('BAUSTEIN.Z3_11'),
            bl_t('BAUSTEIN.Z3_12'),
            bl_t('BAUSTEIN.Z3_13'),
            // Gruppe 33 (Entwurf Abschnitt 9): Lage, Alter, Formel, ODER
            bl_t('BAUSTEIN.Z3_14'),
            bl_t('BAUSTEIN.Z3_15'),
            bl_t('BAUSTEIN.Z3_16'),
            bl_t('BAUSTEIN.Z3_17'),
        ), array(
            bl_t('BAUSTEIN.SEITE3_SCHRITT1'),
            bl_t('BAUSTEIN.SEITE3_SCHRITT2'),
            bl_t('BAUSTEIN.SEITE3_SCHRITT3'),
            bl_t('BAUSTEIN.SEITE3_SCHRITT4'),
            bl_t('BAUSTEIN.SEITE3_SCHRITT5'),
        ), array(
            bl_t('BAUSTEIN.SEITE3_H1'),
            bl_t('BAUSTEIN.SEITE3_H2'),
            bl_t('BAUSTEIN.SEITE3_H3'),
        )),
    );
    $aus = array();
    foreach ($seiten as $bl_s) {
        $bl_zeilen = array();
        foreach ($bl_s[3] as $bl_i => $bl_z) {
            $bl_zeilen[] = array_merge(array($bl_i + 1),
                                       array_pad(explode(' | ', strtr($bl_z, $ersatz), 4), 4, ''));
        }
        $bl_tr = function ($x) use ($ersatz) { return strtr($x, $ersatz); };
        $aus[] = array('nr' => $bl_s[0], 'seite' => $bl_s[1], 'bild' => $bl_s[2], 'zeilen' => $bl_zeilen,
                       'schritte' => array_map($bl_tr, $bl_s[4]), 'hinweise' => array_map($bl_tr, $bl_s[5]));
    }
    return $aus;
}

/**
 * Was in den Listen gerechnet wird (Entwurf bl Abschnitt 4): die Liste traegt die Namen des Musterprojekts
 * (Praefix blescanner, Personen Person1/Person2, Alias schluessel1 und thermometer_wohnzimmer, Tmc 120 s).
 * Eingesetzt werden das Praefix aus dem Reiter MQTT, die erste und zweite Person der aktiven Tags, der
 * Themenzweig (Alias oder Adresse) des ersten aktiven Tags mit Person und des ersten mit Messwerten, und
 * Tmc = 4 x max(30, Intervall) - so wie der Dienst server/ts sendet (hoechstens alle 30 s, bei langem
 * Takt einmal je Durchlauf). Rueckgabe: Tabelle fuer strtr() (alle Ersetzungen in einem Durchgang).
 */
function bl_bausteinliste_ersatz($cfg, $tags)
{
    $praefix = (string) bl_cfg($cfg, 'themenpraefix', 'blescanner');
    if ($praefix === '') {
        $praefix = 'blescanner';
    }
    $personen = bl_personen($tags);
    $schluessel = '';
    $thermo = '';
    foreach ($tags as $tag) {
        if (!isset($tag['aktiv']) || $tag['aktiv'] !== '1') {
            continue;
        }
        $pn = isset($tag['opt']['person']) ? bl_saeubern(trim((string) $tag['opt']['person'])) : '';
        if ($schluessel === '' && $pn !== '') {
            $schluessel = bl_thema($tag);
        }
        if ($thermo === '' && bl_sensor_themen($tag['kennung'])) {
            $thermo = bl_thema($tag);
        }
    }
    $tmc = 4 * max(30, (int) bl_cfg($cfg, 'intervall', '5'));
    return array(
        'blescanner_'            => $praefix . '_',
        'blescanner/'            => $praefix . '/',
        'Person1'                => isset($personen[0]) ? $personen[0] : 'Person1',
        'Person2'                => isset($personen[1]) ? $personen[1] : 'Person2',
        'schluessel1'            => $schluessel !== '' ? $schluessel : 'schluessel1',
        'thermometer_wohnzimmer' => $thermo !== '' ? $thermo : 'thermometer_wohnzimmer',
        'Tmc 120 s'              => 'Tmc ' . $tmc . ' s',
    );
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
        // BERICHTIGT IN 1.3.19: bis 1.3.18 stand hier ein fester Rueckfall
        // auf ein Heimverzeichnis, und ohne Wurzel wurde
        // /templates/plugins/... ab der Laufwerkswurzel gefragt (Fall P7).
        $pp = bl_paths();
        $pfad = $pp['home'] !== ''
            ? $pp['home'] . '/templates/plugins/' . $pp['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
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

/* Gruppe 13 (B22): bl_sprachschluessel() ist entfallen - bl_sprachen_vergleich() in bl_test.php liest alle
 * Abschnitte von language_de/en.ini und help_de/en.ini selbst. */


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
    // Seit 1.3.19 aus bl_paths(): nur die Wurzel dieser Installation.
    $home = bl_paths()['home'];
    if ($home === '' || !is_dir($home)) {
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
/**
 * Die Sicherungsdatei bauen (seit 1.3.20, Pruefung 29.09.2026, C1/O1/O3).
 *
 * Bis 1.3.19 rief der Handler bl_cfg() ohne Argumente - "Too few arguments",
 * HTTP 500, seit 1.3.6 kam nie eine Datei heraus. Und selbst die gedachte
 * Datei trug nur die 32 Einstellungen, keinen einzigen Tag: ein Umzug brachte
 * keine MAC, keine Bezeichnung, keine Person mit.
 *
 * Jetzt: ein lesbarer Kopf unter "_" (Plugin, Fassung, Zeitpunkt, Hinweis),
 * alle Schluessel aus den Vorgaben mit ihrem gespeicherten Wert, und die Tags
 * unter "tags" (Kennung, aktiv, Bezeichnung, Zusatzangaben). Zugangsdaten
 * fuehrt diese Linie nicht; das Formularmerkwort gehoert nicht hinein.
 */
/** Die Sprachansagen aus dem Feld der Hauptkonfiguration: was fehlt, kommt aus den Vorgaben (ab Werk EIN). */
function bl_ansage_block_lesen()
{
    $roh = (function () { list($w) = bl_config_read(); return isset($w['ansage_block']) ? (string) $w['ansage_block'] : ''; })();
    $b = $roh !== '' ? json_decode((string) base64_decode($roh), true) : null;
    return (is_array($b) ? $b : array()) + bl_ansage_vorgaben();
}

/** Zurueck in das Feld der Hauptkonfiguration. */
function bl_ansage_block_schreiben(array $cfg)
{
    $x = array();
    foreach (bl_ansage_schluessel() as $k) { if (array_key_exists($k, $cfg)) { $x[$k] = $cfg[$k]; } }
    $b = base64_encode((string) json_encode($x));
    list($w, $t) = bl_config_read(); $w['ansage_block'] = $b; return bl_config_write($w, $t);
}

/** Die Schluessel der Sprachansagen in der Hauptkonfiguration. */
function bl_ansage_schluessel()
{
    return array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft', 'ansage_aus_datei');
}

/** Die Vorgaben: nie gesetzt = ab Werk EIN. Ein gespeicherter Block bleibt, auch 'aus'. */
function bl_ansage_vorgaben()
{
    return array('tts' => ansage_werk_block(), 'ansage_regeln' => ansage_regeln_vorgaben(), 'ansage_haken' => array('ausfall' => 1), 'ansage_token_herkunft' => array(), 'ansage_aus_datei' => 0);
}

/** Wertpruefung fuer die Sprachansagen: null = nicht meine Schluessel, sonst tauglich ja/nein. */
function bl_ansage_wert($k, $w)
{
    if (!in_array((string) $k, bl_ansage_schluessel(), true)) { return null; }
    $g = '';
    switch ((string) $k) {
        case 'tts':
            return is_array($w) && ansage_wert_pruefen($w, $g, bl_ansage_modi()) !== null;
        case 'ansage_regeln':
            return is_array($w) && ansage_regeln_wert($w, $g) !== null;
        case 'ansage_haken':
            if (!is_array($w)) { return false; }
            foreach ($w as $a => $v) {
                if (!array_key_exists($a, array('ausfall' => 1)) || !in_array($v, array(0, 1, '0', '1'), true)) { return false; }
            }
            return true;
        case 'ansage_token_herkunft':
            return is_array($w);
        case 'ansage_aus_datei':
            return in_array($w, array(0, 1, '0', '1'), true);
    }
    return false;
}

/** Fuer die Sicherung: nie ein Sprechtoken, nie der Herkunftsvermerk. Ausgabeart, Ziele, Regeln und Haken reisen mit. */
function bl_ansage_fuer_sicherung(array $c)
{
    unset($c['ansage_token_herkunft'], $c['ansage_aus_datei']);
    if (isset($c['tts']) && is_array($c['tts'])) { $c['tts'] = ansage_sicherung_bereinigen($c['tts']); }
    return $c;
}

/**
 * Vor dem Zurueckspielen. Eine Datei mit Sprechtoken wird abgewiesen (der Block wird unbrauchbar gemacht, die Wertpruefung nennt ihn). Die geltenden Token und der Vermerk bleiben.
 * Fehlt ein Schluessel (Sicherung einer aelteren Fassung), gilt der geltende Wert: Ein bewusstes AUS geht beim Zurueckspielen nicht verloren.
 */
function bl_ansage_sicherung_vor($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { return $roh; }
    $inhalt = false;
    foreach (array_keys($d) as $k) { if ((string) $k !== '' && ((string) $k)[0] !== '_') { $inhalt = true; } }
    if (!$inhalt) { return $roh; }
    $j = bl_ansage_cfg();
    unset($d['ansage_token_herkunft'], $d['ansage_aus_datei']);
    $jt = isset($j['tts']) && is_array($j['tts']) ? $j['tts'] : array();
    if (array_key_exists('tts', $d)) {
        $g = '';
        $p = is_array($d['tts']) ? ansage_wert_pruefen($d['tts'], $g, bl_ansage_modi()) : null;
        if (ansage_sicherung_mangel($d['tts']) || $p === null) {
            $d['tts'] = 'ABGEWIESEN';
        } else {
            list($tv) = ansage_vervollstaendigen($p, 'aus');
            $d['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
        }
    } else {
        $d['tts'] = bl_tts($j);
    }
    foreach (array_diff(bl_ansage_schluessel(), array('tts', 'ansage_token_herkunft', 'ansage_aus_datei')) as $k) {
        if (!array_key_exists($k, $d) && array_key_exists($k, $j)) { $d[$k] = $j[$k]; }
    }
    $d['ansage_token_herkunft'] = isset($j['ansage_token_herkunft']) && is_array($j['ansage_token_herkunft']) ? $j['ansage_token_herkunft'] : array();
    $d['ansage_aus_datei'] = 1;
    return (string) json_encode($d);
}

/**
 * Die Hauptkonfiguration. Liegt eine ansage.json aus einer Probe vom 01.11., wird sie EINMAL uebernommen (Vermerk ansage_aus_datei) und bleibt liegen.
 */
function bl_ansage_cfg()
{
    $c = bl_ansage_block_lesen();
    if (empty($c['ansage_aus_datei'])) {
        $f = rtrim(dirname(bl_paths()['config']), '/') . '/ansage.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                foreach (array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft') as $k) {
                    if (array_key_exists($k, $d) && bl_ansage_wert($k, $d[$k]) === true) { $c[$k] = $d[$k]; }
                }
            }
            $c['ansage_aus_datei'] = 1;
            bl_ansage_cfg_schreiben($c);
        }
    }
    return $c;
}

function bl_ansage_cfg_schreiben(array $cfg)
{
    return bl_ansage_block_schreiben($cfg);
}

/** Die Ausgabearten dieser Linie. */
function bl_ansage_modi()
{
    return array_values(array_diff(ansage_modi(), array('audioserver')));
}

/** Der Block tts: gespeichert bleibt, auch 'aus'; ungueltig gilt als 'aus'. */
function bl_tts($cfg = null)
{
    if ($cfg === null) { $cfg = bl_ansage_cfg(); }
    return ansage_block_oder_aus(isset($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), bl_ansage_modi());
}

/** Der Kontext des Moduls. */
function bl_ansage_k()
{
    $d = bl_paths()['datadir'];
    return array(
        'port' => ansage_webport(ansage_lbhome() . '/config/system/general.json'),
        'home' => ansage_lbhome(),
        'kopf' => array('User-Agent: LoxBerry BLE-Scanner NG'),
        'ordner' => @is_dir($d) ? $d : '',
        't' => function ($s) { return bl_t($s); },
        'werk' => ansage_werk_modus(),
    );
}

/** Die Anlaesse: Name => Beschriftung. */
function bl_ansage_anlaesse()
{
    return array('ausfall' => bl_t('ANSAGEPLUGIN.L_AUSFALL'));
}

/** Ist der Haken dieses Anlasses gesetzt? Fehlt er, gilt die Vorgabe der Linie. */
function bl_ansage_anlass_an($a, $cfg = null)
{
    if ($cfg === null) { $cfg = bl_ansage_cfg(); }
    $h = (isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken'])) ? $cfg['ansage_haken'] : array();
    $v = array('ausfall' => 1);
    return !empty(array_key_exists($a, $h) ? $h[$a] : (isset($v[$a]) ? $v[$a] : 0));
}

/** Die Eingaben nach einer Beanstandung bis zur naechsten Seite aufheben, nie ein Sprechtoken.
 *  1.3.38 (K5c): mit Zeitpunkt 'zeit'; aelter als BL_ANSAGE_EINGABEN_FRIST Sekunden gilt die Datei nicht mehr (bis 1.3.37
 *  tauchte eine liegengebliebene Beanstandung beim naechsten Oeffnen wieder auf, auch Tage spaeter). Geschrieben ueber
 *  bl_datei_schreiben() mit 0600 - Rechte vor Inhalt (Regeln/03); bis 1.3.37 file_put_contents() und chmod danach. */
if (!defined('BL_ANSAGE_EINGABEN_FRIST')) { define('BL_ANSAGE_EINGABEN_FRIST', 120); }
function bl_ansage_eingaben($setzen = null)
{
    $f = bl_paths()['datadir'] . '/ansage_eingaben.json';
    if (is_array($setzen)) {
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        $js = json_encode(array('zeit' => time()) + $setzen);
        if ($js !== false) { bl_datei_schreiben($f, $js, 0600); }
        return $setzen;
    }
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !$d) { return array(); }
    bl_datei_schreiben($f, '{}', 0600);
    $z = (isset($d['zeit']) && is_int($d['zeit'])) ? $d['zeit'] : 0;
    unset($d['zeit']);
    $alter = time() - $z;
    if ($z <= 0 || $alter < 0 || $alter > BL_ANSAGE_EINGABEN_FRIST) { return array(); }
    return $d;
}

/** Das Formular lesen und speichern: array(gelungen, Fehlertexte, Hinweise). */
function bl_ansage_post(array $post)
{
    $cfg = bl_ansage_cfg();
    $k = bl_ansage_k();
    $m = array();
    $b = array();
    $h = array();
    $neu = ansage_formular_lesen($post, bl_tts($cfg), $m, $b, array('modi' => bl_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), $k, $h);
    list($r, $hk) = ansage_regeln_formular($post, bl_ansage_regeln($cfg), bl_ansage_anlaesse(), $m, $b, $k);
    if ($m) {
        $t = array();
        foreach ($m as $x) { $t[] = $x['text']; }
        $e = array('_bean' => array_values($b));
        // 1.3.38 (K5b): alle Sprechtoken aus ansage_geheim() bleiben draussen. Bis 1.3.37 standen hier zwei Namen fest;
        // das HomePod-Token hat heute kein Feld, ein mitgeschicktes tts_homepod_token reiste aber in die Datei mit.
        $geheim = array();
        $fn = ansage_feldnamen();
        foreach (ansage_geheim() as $s) { $geheim[] = isset($fn[$s]) ? $fn[$s] : 'tts_' . $s; }
        foreach ($post as $n => $w) {
            if (strncmp((string) $n, 'tts_', 4) === 0 || strncmp((string) $n, 'ansage_', 7) === 0) {
                if (!in_array((string) $n, $geheim, true)) { $e[$n] = $w; }
            }
        }
        bl_ansage_eingaben($e);
        return array(false, $t, array());
    }
    $cfg['tts'] = $neu;
    // 1.3.33: ein eingetipptes oder geloeschtes Sprechtoken ist nicht mehr vom Nachbarn; bis 1.3.32 ueberschrieb es der naechste Abgleich.
    $cfg['ansage_token_herkunft'] = ansage_token_herkunft_formular($post, isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array());
    $cfg['ansage_regeln'] = $r;
    $cfg['ansage_haken'] = $hk;
    $t = array();
    foreach ($h as $x) { $t[] = $x['text']; }
    return array(bl_ansage_cfg_schreiben($cfg), array(), $t);
}

/** Der Abschnitt im Reiter Einstellungen, eigenes Formular. $felder: die versteckten Felder (Formularmerkmal, Reiter). Nach einer Beanstandung traegt er das Eingetippte. */
function bl_ansage_form_html($felder)
{
    $cfg = bl_ansage_cfg();
    $k = bl_ansage_k();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $ein = bl_ansage_eingaben();
    $bean = isset($ein['_bean']) && is_array($ein['_bean']) ? $ein['_bean'] : array();
    $h = array(
        'w' => function ($n, $g) use ($ein) { return (isset($ein[$n]) && is_string($ein[$n])) ? $ein[$n] : $g; },
        'm' => function ($n) use ($bean) { return in_array($n, $bean, true) ? ' class="sm-beanstandet"' : ''; },
        'c' => function ($n, $g) use ($ein) { return $ein ? !empty($ein[$n]) : (bool) $g; },
    );
    $o = array();
    $o[] = '<h2>' . $e(bl_t('ANSAGEPLUGIN.H')) . '</h2>';
    // 1.3.38 (K5a): nach einer Beanstandung sagen, dass das Eingetippte wieder dasteht (wie im Formular Einstellungen).
    if ($ein) { $o[] = '<div class="sm-hinweis">' . $e(bl_t('TEXT.EINGABEN_ZURUECK')) . '</div>'; }
    $o[] = '<form action="index.php" method="post" autocomplete="off">' . $felder;
    $o[] = ansage_formular_html(bl_tts($cfg), $h + array('modi' => bl_ansage_modi(), 'ziele' => true, 'ziele_post' => isset($ein['tts_ziel_art']) ? $ein : null), $k);
    $o[] = ansage_regeln_html(bl_ansage_regeln($cfg), isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken']) ? $cfg['ansage_haken'] : array('ausfall' => 1), bl_ansage_anlaesse(), $h + array('token_vor_ort' => true), $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_speichern" value="1">' . $e(bl_t('ANSAGEPLUGIN.K_SPEICHERN')) . '</button>';
    $o[] = '<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1">' . $e(bl_t('ANSAGEPLUGIN.K_TEST')) . '</button></div>';
    $o[] = '</form>';
    $o[] = bl_ansage_lage_html($cfg);
    return implode("\n", $o) . "\n";
}

/** Die Testansage (der Knopf spricht immer). Rueckgabe: array(gesendet, Grund). */
function bl_ansage_test()
{
    $k = bl_ansage_k();
    bl_ansage_token_pflegen();
    $r = ansage_testansage(bl_ansage_vor_ort(), $k);
    bl_ansage_zeile('Testansage: ' . ansage_kurz($r));
    return array($r['stand'] === 1, $r['stand'] === 1 ? '' : ansage_kennung_text($r['kennung'], $k));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (1.3.31, Welle S7, Stamm sprachausgabe.php 1.4.0) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('BL_ANSAGE_HOECHSTALTER')) { define('BL_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function bl_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = bl_ansage_cfg(); }
    $g = '';
    if (!empty($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function bl_ansage_warte($fn)
{
    $d = bl_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function bl_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    bl_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; bl_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function bl_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = bl_ansage_cfg(); }
    list($neu, $her2, $g) = ansage_token_abgleich(bl_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), bl_ansage_k());
    if (!$g) { return false; }
    $cfg = bl_ansage_cfg();
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!bl_ansage_cfg_schreiben($cfg)) { return false; }
    bl_ansage_zeile('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function bl_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = bl_ansage_cfg(); }
    return ansage_token_fuer_lauf(bl_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), bl_ansage_k());
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function bl_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = bl_ansage_cfg(); }
    $t = bl_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(bl_ansage_vor_ort($cfg), bl_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function bl_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = bl_ansage_cfg(); }
    $k = bl_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(bl_tts($cfg), bl_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function bl_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function bl_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = bl_ansage_cfg();
    $k = bl_ansage_k();
    $t = $tts === null ? bl_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, bl_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function bl_ansage_senden($jetzt = null)
{
    $d = bl_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = bl_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= BL_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = bl_ansage_cfg();
        bl_ansage_token_pflegen($conf);
        $conf = bl_ansage_cfg();
        $a = ansage_regel_sprechen($frisch, bl_tts($conf), bl_ansage_regeln($conf), bl_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { bl_ansage_zeile('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            bl_ansage_zeile('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/**
 * Die Flanke eines Anlasses (1.3.31). Merker <Daten>/ansage_flanke.json, unter Dateisperre; er ueberlebt einen Neustart des Dienstes.
 * Der erste Lauf schweigt: Was schon gestoert war, als die Linie zum ersten Mal hinsah, sagt sie nicht an und meldet auch keine Rueckkehr.
 * Nur der Eintritt spricht, nach $frist Sekunden Dauer; die Rueckkehr nur, wenn der Eintritt gesprochen hat. Ein abgewaehlter Haken laeuft mit: Einschalten sagt nichts Altes an.
 * $name: der Name, den der Nutzer vergeben hat, nur im Satz, nie im Protokoll; Kennungen, Adressen und Seriennummern gehoeren nicht hierher.
 * Rueckgabe: nichts, erster_lauf, aus, oder das Ergebnis von <px>_ansage_legen().
 */
function bl_ansage_flanke($anlass, $gestoert, $name = '', $frist = 0, $jetzt = null)
{
    $l = array('ausfall' => array('ausfall', 'SATZ_AUSFALL', 'SATZ_WIEDER', 0));
    if (!isset($l[$anlass])) { return 'unbekannt'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $name = trim((string) preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', (string) $name));
    $sch = $anlass . ($name !== '' ? '|' . substr(md5($name), 0, 8) : '');
    $d = bl_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $h = @fopen($d . '/ansage_flanke.json', 'c+');
    if ($h === false) { return 'nichts'; }
    if (!flock($h, LOCK_EX)) { fclose($h); return 'nichts'; }
    $m = json_decode((string) stream_get_contents($h), true);
    $m = is_array($m) ? $m : array();
    $e = (isset($m[$sch]) && is_array($m[$sch]) && isset($m[$sch]['z'], $m[$sch]['s'], $m[$sch]['g'])) ? $m[$sch] : null;
    $neu = $gestoert ? 1 : 0;
    $tat = 'nichts';
    if ($e === null) {
        $e = array('z' => $neu, 's' => $jetzt, 'g' => $neu ? 2 : 0);
        $tat = 'erster_lauf';
    } elseif ($neu === 1) {
        if ((int) $e['z'] === 0) { $e = array('z' => 1, 's' => $jetzt, 'g' => 0); }
        if ((int) $e['g'] === 0 && $jetzt - (int) $e['s'] >= (int) $frist) {
            if (bl_ansage_anlass_an($l[$anlass][0])) { $e['g'] = 1; $tat = 'stoerung'; } else { $e['g'] = 2; $tat = 'aus'; }
        }
    } elseif ((int) $e['z'] === 1) {
        if ((int) $e['g'] === 1) { $tat = $l[$anlass][2] !== '' ? 'wieder' : 'nichts'; }
        $e = array('z' => 0, 's' => $jetzt, 'g' => 0);
    }
    if (!isset($m[$sch]) || $m[$sch] !== $e) {     // nur bei einer Aenderung schreiben: Dienste rufen das im Takt
        $m[$sch] = $e;
        rewind($h);
        ftruncate($h, 0);
        fwrite($h, (string) json_encode($m));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($d . '/ansage_flanke.json', 0600);
    if ($tat !== 'stoerung' && $tat !== 'wieder') { return $tat; }
    $satz = bl_t('ANSAGEPLUGIN.' . ($tat === 'stoerung' ? $l[$anlass][1] : $l[$anlass][2]));
    if (strpos($satz, '%s') !== false) { $satz = sprintf($satz, $name); }
    $ein = array('schluessel' => $tat === 'stoerung' ? $sch : 'wieder|' . $sch, 'text' => $satz);
    if ($tat === 'wieder') { $ein['nur_nach'] = $sch; }
    if ($tat === 'stoerung' && $l[$anlass][3]) { $ein['dringend'] = true; }
    bl_ansage_token_pflegen();
    return bl_ansage_legen(array($ein));
}

/** Fuer die Sicherung: die Hauptkonfiguration ohne das Feld, dazu die Felder der Sprachansagen ohne Sprechtoken und Vermerk. */
function bl_ansage_sicherung_felder(array $c)
{
    unset($c['ansage_block']);
    return bl_ansage_fuer_sicherung(bl_ansage_cfg()) + $c;
}

/** Eine Protokollzeile der Sprachansagen. */
function bl_ansage_zeile($t)
{
    $f = bl_log_file();
    if ($f !== '' && is_dir(dirname($f))) { @file_put_contents($f, date('Y-m-d H:i:s') . ' Oberflaeche: ' . $t . "\n", FILE_APPEND | LOCK_EX); }
}

function bl_sicherung_bauen($cfg, $tags)
{
    $aus = array(
        '_' => array(
            'plugin'   => 'ble_scanner_ng',
            'fassung'  => bl_fassung(),
            'erstellt' => date('Y-m-d H:i:s'),
            'hinweis'  => bl_t('TEXT.SICH_KOPF'),
        ),
    );
    foreach (bl_defaults() as $k => $vorgabe) {
        $aus[$k] = isset($cfg[$k]) ? (string) $cfg[$k] : (string) $vorgabe;
    }
    $aus = bl_ansage_sicherung_felder($aus);     // nie ein Sprechtoken
    $aus['tags'] = array();
    foreach ($tags as $t) {
        $aus['tags'][] = array(
            'kennung' => (string) $t['kennung'],
            'aktiv'   => $t['aktiv'] === '1' ? '1' : '0',
            'name'    => (string) $t['name'],
            'opt'     => (object) (isset($t['opt']) && is_array($t['opt']) ? $t['opt'] : array()),
        );
    }
    // Gruppe 33 (E-E6, N5): die Schluessel gehoeren in die Sicherung (Regeln/05) - sonst ist der Umzug auf einen
    // zweiten LoxBerry fuer verschluesselte Fuehler und IRK-Tags wertlos. Der Kopf sagt es (TEXT.SICH_KOPF).
    $aus['bindkeys'] = (object) bl_bindkey_lesen();
    $aus['irk'] = (object) bl_irk_lesen();
    return $aus;
}

function bl_sicherung_lesen($roh, &$namen = null)
{
    $roh = bl_ansage_sicherung_vor($roh);     // Token und Vermerk bleiben die geltenden
    $bl_d = json_decode((string) $roh, true);
    if (is_array($bl_d)) { $bl_d['ansage_block'] = ''; $roh = (string) json_encode($bl_d); }
    $bl_ab = array();
    // X-3 (Verbesserungsbau 30.09.2026): zu jeder Beanstandung ein NAME, nie
    // ein Wert - fuer die Warnung beim Sichern (bl_rueckspiel_altwerte()).
    $namen = array();
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        $namen[] = '_';
        return array(null, array(bl_t('TEXT.SICH_KEIN_JSON')), 0, array());
    }
    /* Fremdes wird VOR dem Zusammenfuehren abgelehnt: der Kopf muss dieses
     * Plugin nennen (seit 1.3.20). */
    if (!isset($daten['_']) || !is_array($daten['_']) || !isset($daten['_']['plugin'])
        || $daten['_']['plugin'] !== 'ble_scanner_ng') {
        $namen[] = '_';
        return array(null, array(bl_t('TEXT.SICH_KEIN_KOPF')), 0, array());
    }
    $neu = bl_defaults();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        // Gruppe 33: die Schluessel-Abschnitte liest bl_sicherung_schluessel().
        if ($k === '_' || $k === 'tags' || $k === 'bindkeys' || $k === 'irk') {
            continue;
        }
        if ((string) $k === 'ansage_block') { continue; }     // das Feld mit den Sprechtoken kommt nie aus einer Datei
        $bl_aw = bl_ansage_wert($k, $w);
        if ($bl_aw !== null) {
            if ($bl_aw) { $bl_ab[$k] = $w; } else { $mangel[] = bl_t('ANSAGEPLUGIN.M_FEHL'); $namen[] = (string) $k; }
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(bl_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            $namen[] = (string) $k;
            continue;
        }
        /* Jeder Wert mit denselben Regeln wie das Formular, zuerst is_string
         * (seit 1.3.20, C3). Bis 1.3.19 hiess es hier nur $neu[$k] = $w. */
        list($gut, $wert, $grund) = bl_wert_pruefen($k, $w);
        if (!$gut) {
            $mangel[] = sprintf(bl_t('TEXT.SICH_WERT'),
                                htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'),
                                is_string($w) || is_int($w)
                                    ? htmlspecialchars(bl_kuerzen((string) $w, 40), ENT_QUOTES, 'UTF-8')
                                    : gettype($w));
            $namen[] = (string) $k;
            continue;
        }
        $neu[$k] = $wert;
        $anzahl++;
    }
    if ((int) $neu['rssi_mittel'] > (int) $neu['rssi_nah']) {
        $mangel[] = bl_t('TEXT.SICH_SCHWELLEN');
        $namen[] = 'rssi_nah/rssi_mittel';
    }
    /* Die Tags (seit 1.3.20, O3): jeder einzeln geprueft, doppelte Kennungen
     * abgewiesen. Eine Datei OHNE den Schluessel "tags" ist unvollstaendig -
     * sonst loeschte das Zurueckspielen alle eingetragenen Tags. */
    $tags = array();
    if (!array_key_exists('tags', $daten)) {
        $mangel[] = bl_t('TEXT.SICH_OHNE_TAGS');
        $namen[] = 'tags';
    } elseif (!is_array($daten['tags'])) {
        $mangel[] = bl_t('TEXT.SICH_OHNE_TAGS');
        $namen[] = 'tags';
    } else {
        $gesehen = array();
        foreach (array_values($daten['tags']) as $nr => $t) {
            list($tag, $grund) = bl_tag_pruefen($t);
            if ($tag === null) {
                $mangel[] = sprintf(bl_t('TEXT.SICH_TAG'), $nr + 1,
                                    htmlspecialchars($grund, ENT_QUOTES, 'UTF-8'));
                $namen[] = 'tags[' . ($nr + 1) . '].' . $grund;
                continue;
            }
            if (isset($gesehen[$tag['kennung']])) {
                $mangel[] = sprintf(bl_t('MANGEL.DOPPELT'), $tag['kennung']);
                $namen[] = 'tags[' . ($nr + 1) . '].kennung';
                continue;
            }
            $gesehen[$tag['kennung']] = true;
            $tags[] = $tag;
        }
    }
    /* 1.3.38 (K7a): Bind-Keys und IRK gehoeren zur Datei wie die Tags. Ein Eintrag, der nicht passt, ist eine
     * Beanstandung - geprueft HIER, also vor bl_config_write(); eine halb gueltige Datei aendert nichts. */
    list(, , $bl_sch_mangel, $bl_sch_namen) = bl_sicherung_schluessel($roh, $tags);
    foreach ($bl_sch_mangel as $bl_x) { $mangel[] = $bl_x; }
    foreach ($bl_sch_namen as $bl_x) { $namen[] = $bl_x; }
    if ($anzahl === 0) {
        $mangel[] = bl_t('TEXT.SICH_LEER');
        $namen[] = '_';
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    foreach (array_keys(bl_defaults()) as $fk) {
        if (!array_key_exists($fk, $daten) && !in_array($fk, bl_schluessel_spaeter(), true)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(bl_t('TEXT.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
        foreach ($fehlend as $fk) {
            $namen[] = $fk;
        }
    }
    $namen = array_values(array_unique($namen));
    if ($bl_ab) { $neu['ansage_block'] = base64_encode((string) json_encode($bl_ab)); }     // die Felder der Sprachansagen zurueck in das eine Feld
    return array($mangel ? null : $neu, $mangel, $anzahl, $tags);
}

/**
 * Gruppe 33 (E-E6): die Abschnitte "bindkeys" (MAC -> 24/32 Hex) und "irk" (Name -> 32 Hex) einer Sicherung lesen.
 * Rueckgabe array(bindkeys|null, irk|null, beanstandungen, namen): null = Abschnitt fehlt (Sicherung aus 1.3.28) - dann
 * bleiben die hinterlegten Schluessel unveraendert. Nur Schluessel zu Tags der Sicherung gelten (keine Waisen).
 * 1.3.38 (K7a): ein Eintrag mit falscher Form, ohne Tag in der Datei oder ein Abschnitt, der kein Objekt ist, wird
 * nicht mehr uebergangen, sondern beanstandet (maskiert, nur Name/MAC, nie ein Schluessel); bl_sicherung_lesen() lehnt
 * die Datei dann ganz ab. Bis 1.3.37 wurde der Eintrag NACH bl_config_write() mit Hinweis weggelassen - die Datei war
 * halb uebernommen, und ein Abschnitt "bindkeys": "x" loeschte alle hinterlegten Bind-Keys.
 */
function bl_sicherung_schluessel($roh, $tags)
{
    $d = json_decode((string) $roh, true);
    $mangel = array();
    $bean = array();
    $bk = null;
    $irk = null;
    $macs = array();
    $namen = array();
    foreach ((array) $tags as $t) {
        if ($t['art'] === 'mac') { $macs[] = $t['mac']; }
        if ($t['art'] === 'irk') { $namen[] = substr($t['kennung'], 4); }
    }
    $falsch = function ($abschnitt, $eintrag, $laenge) use (&$mangel, &$bean) {
        $mangel[] = sprintf(bl_t('TEXT.SICH_SCHLUESSEL_FALSCH'), $abschnitt,
                            htmlspecialchars(bl_kuerzen((string) $eintrag, $laenge), ENT_QUOTES, 'UTF-8'));
        $bean[] = $abschnitt;
    };
    if (is_array($d) && array_key_exists('bindkeys', $d)) {
        $bk = array();
        if (!is_array($d['bindkeys'])) {
            $falsch('bindkeys', '-', 20);
        } else {
            foreach ($d['bindkeys'] as $mac => $v) {
                $m = bl_mac((string) $mac);
                $k = is_string($v) ? bl_bindkey_form($v) : '';
                if ($m === '' || $k === '' || !in_array($m, $macs, true)) {
                    $falsch('bindkeys', $mac, 20);
                    continue;
                }
                $bk[$m] = $k;
            }
        }
    }
    if (is_array($d) && array_key_exists('irk', $d)) {
        $irk = array();
        if (!is_array($d['irk'])) {
            $falsch('irk', '-', 40);
        } else {
            foreach ($d['irk'] as $n => $v) {
                if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $n) || !is_string($v) || !preg_match('/^[0-9A-Fa-f]{32}$/', $v)
                    || !in_array((string) $n, $namen, true)) {
                    $falsch('irk', $n, 40);
                    continue;
                }
                $irk[(string) $n] = strtolower($v);
            }
        }
    }
    return array($bk, $irk, $mangel, array_values(array_unique($bean)));
}

/**
 * 1.3.38 (K7b): bindkey.json und irk.json, die vorhanden, aber nicht lesbar oder kein JSON-Objekt sind. Beim Sichern
 * schrieben bl_bindkey_lesen()/bl_irk_lesen() dafuer still {} in die Datei - die Sicherung sah vollstaendig aus und
 * haette beim Zurueckspielen die Schluessel geloescht. Eine fehlende Datei ist eine leere Tabelle (kein Befund).
 * Rueckgabe: Liste der Pfade (leer = in Ordnung).
 */
function bl_schluesseldateien_kaputt()
{
    $aus = array();
    foreach (array(bl_bindkey_datei(), bl_irk_datei()) as $f) {
        if (!file_exists($f) && !is_link($f)) { continue; }
        $roh = (is_file($f) && is_readable($f)) ? @file_get_contents($f) : false;
        $j = ($roh === false) ? null : json_decode((string) $roh, true);
        if (!is_array($j)) { $aus[] = $f; }
    }
    return $aus;
}

/**
 * Wuerde die eigene Sicherung das eigene Zurueckspielen bestehen? (X-3,
 * Verbesserungsbau 30.09.2026). Gebaut wie beim Sichern, geprueft mit
 * DERSELBEN Funktion wie beim Zurueckspielen. Rueckgabe: die Namen der
 * beanstandeten Einstellungen bzw. Tags (leer = alles in Ordnung), nie Werte.
 * Anlass: ein Wert, der von Hand oder aus einer Vorfassung in der Datei
 * steht, liess sich sichern, aber nicht zurueckspielen - das merkte man erst
 * beim Umzug.
 */
function bl_rueckspiel_altwerte($cfg, $tags)
{
    $js = json_encode(bl_sicherung_bauen($cfg, $tags),
                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($js === false) {
        return array('_');
    }
    $namen = array();
    bl_sicherung_lesen($js, $namen);
    return $namen;
}


/**
 * Die Abo-Datei des MQTT-Gateways (seit 1.3.20, Pruefung 29.09.2026, MQTT 6;
 * Bauart eb_abo_datei() aus Einspeisebremse 0.9.28).
 *
 * config/plugins/<ordner>/mqtt_subscriptions.cfg liest das Gateway V1 selbst
 * und abonniert jede Zeile. Bis 1.3.19 musste der Anwender <praefix>/# von
 * Hand eintragen, und nach einem Praefixwechsel stand dort das alte. Geschrieben
 * wird nur, wenn die Datei abweicht - dieselbe Regel wie
 * abo_datei_nachziehen() in bl_common.py (der Dienst beim Start).
 * Rueckgabe: array(Pfad, traegt das Abo).
 */
function bl_abo_datei($praefix, $schreiben = false)
{
    $p = bl_paths();
    $pfad = $p['configdir'] . '/mqtt_subscriptions.cfg';
    $soll = trim((string) $praefix, '/') . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = ($roh === $soll . "\n");
    if ($schreiben && !$da && $soll !== '/#' && $p['home'] !== '' && is_dir($p['configdir'])) {
        $da = bl_datei_schreiben($pfad, $soll . "\n", 0644);
    }
    return array($pfad, $da);
}

/**
 * Gilt das Abbild des Dienstes noch? (seit 1.3.20, Pruefung 29.09.2026, O5)
 *
 * Bis 1.3.19 zeigte die Oberflaeche aus einer liegengebliebenen Zustandsdatei
 * "2 anwesend", gruene Tag-Zeilen und drei Haken im Reiter Test - waehrend kein
 * Dienst lief. Jetzt gilt das Abbild nur, wenn ein Dienst laeuft UND es
 * hoechstens dreimal so alt ist wie der Takt. Der Takt zaehlt mindestens 30 s:
 * waehrend einer BlueZ-Stoerung schreibt der Dienst das Abbild nur alle 30 s
 * (verbinden_mit_geduld() in ble_scanner_ng.py).
 * Rueckgabe: array(gilt, Satz fuer "keine Aussage (...)").
 */
function bl_abbild_lage($cfg, $pid)
{
    $s = bl_status();
    $alter = bl_status_alter();
    $takt = max(30, (int) bl_cfg($cfg, 'intervall', '5'));
    $grenze = 3 * $takt;
    $stand = ($s && isset($s['zeit'])) ? date('H:i', (int) $s['zeit']) : '';
    if ($pid <= 0) {
        return array(false, $stand !== '' ? sprintf(bl_t('TEXT.KEINE_AUSSAGE_TOT_STAND'), $stand)
                                          : bl_t('TEXT.KEINE_AUSSAGE_TOT'));
    }
    if ($alter < 0) {
        return array(false, bl_t('TEXT.KEINE_AUSSAGE_OHNE'));
    }
    if ($alter > $grenze) {
        return array(false, sprintf(bl_t('TEXT.KEINE_AUSSAGE_ALT'), $stand, $alter, $grenze));
    }
    return array(true, '');
}

/**
 * Die Einmalmeldung (seit 1.3.20, Pruefung 29.09.2026, O4; Bauform
 * au_einmal_*() aus AudiConnect 0.9.22, Regeln/04 "Jeder POST-Handler endet
 * mit einer Umleitung" und Nachtrag Raumklima 0.11.8).
 *
 * Jeder ausloesende POST endet mit 303; was er zu sagen hat, reist in
 * data/plugins/<ordner>/einmalmeldung.json (0600). Gelesen wird sie NUR beim
 * GET, genau einmal, und vor der Anzeige geloescht; aelter als 120 s gilt sie
 * nicht. Sie traegt fertige Meldungstexte, das Ergebnis eines Suchlaufs und
 * die eingetippten Tag-Zeilen - keine Zugangsdaten (diese Linie fuehrt keine,
 * und der Probewert zeigt nur Adresse und Name des Miniservers).
 */
/* ==================================================================
 * Eingaben nach einer Beanstandung (Verbesserungsbau 30.09.2026, X-2;
 * Regeln/04 "Nach einer Beanstandung stehen die eingetippten Werte wieder
 * im Formular"; Entscheidung 16: gespeichert wird dann nichts)
 *
 * Nur nach einer Beanstandung, nur das eine Formular und nur seine Felder.
 * Diese Linie fuehrt keine Zugangsdaten und kein Token - es gibt kein Feld,
 * das nicht mitreisen duerfte.
 * ================================================================== */

/** Die Felder je Formular: array(text => [...], haken => [...]). */
function bl_eingabe_felder($form)
{
    $felder = array(
        'settings' => array(
            'text'  => array('loxberry_id', 'adapter', 'betriebsart', 'wachhund_stille',
                             'discovery_rssi', 'log_kappung_kb', 'intervall', 'abwesenheit_nach',
                             'aktualisierung', 'ankunft_sichtungen', 'rssi_minimum', 'rssi_nah',
                             'rssi_mittel', 'glaettung_fenster', 'hysterese_db', 'daempfung',
                             'batterie_uhrzeit', 'ereignisse_tage', 'scanner_name',
                             'raum_hysterese_db', 'raum_ausgleich_db', 'wlan_zuordnung',
                             'espresense_praefix', 'espresense_zuordnung',
                             // 1.3.36 (Fremdpruefung 09.11.2026): seit Gruppe 13/33 im Formular, hier vergessen -
                             // nach einer Beanstandung stand der gespeicherte Wert statt der Eingabe da.
                             'messwert_frist', 'xiaomi_region'),
            'haken' => array('http_push', 'wachhund', 'glaettung', 'beacon', 'entfernung',
                             'batterie', 'ereignisse', 'scanner_themen', 'raum', 'wlan_kopplung',
                             'espresense', 'bthome', 'irk_aufloesen', 'haus_anwesenheit', 'raum_personen',
                             'raum_anwesenheit'),
        ),
        'mqtt' => array(
            'text'  => array('themenpraefix'),
            'haken' => array('mqtt', 'befehle_mqtt_ein', 'befehle_eingreifend_ein'),
        ),
    );
    return isset($felder[$form]) ? $felder[$form] : null;
}

/** Ein eingetippter Wert, der mitreisen darf (UTF-8, hoechstens 256 Byte). */
function bl_eingabe_tauglich($v)
{
    return is_string($v) && strlen($v) <= 256 && preg_match('//u', $v) === 1;
}

/**
 * Die eingetippten Werte eines Formulars aus $_POST, fuer die Einmalmeldung.
 * $bean_tags: je Zeilenindex der Tag-Tabelle die beanstandeten Felder.
 */
function bl_eingaben_sammeln($form, $beanstandet, $bean_tags = array())
{
    $f = bl_eingabe_felder($form);
    if ($f === null || !$beanstandet) {
        return null;
    }
    $werte = array();
    foreach ($f['text'] as $feld) {
        if (isset($_POST[$feld]) && bl_eingabe_tauglich($_POST[$feld])) {
            $werte[$feld] = $_POST[$feld];
        }
    }
    foreach ($f['haken'] as $feld) {
        $werte[$feld] = isset($_POST[$feld]) ? '1' : '';
    }
    $tags = array();
    if ($form === 'settings') {
        $feld = function ($name, $i) {
            return (isset($_POST[$name]) && is_array($_POST[$name]) && isset($_POST[$name][$i])
                    && bl_eingabe_tauglich($_POST[$name][$i])) ? $_POST[$name][$i] : '';
        };
        $kenn = isset($_POST['tag_kennung']) && is_array($_POST['tag_kennung']) ? $_POST['tag_kennung'] : array();
        foreach ($kenn as $i => $_k) {
            if (count($tags) >= 200) {
                break;
            }
            $z = array('kennung' => $feld('tag_kennung', $i), 'alt' => $feld('tag_alt', $i),
                       'name' => $feld('tag_name', $i),
                       'aktiv' => (isset($_POST['tag_aktiv']) && is_array($_POST['tag_aktiv'])
                                   && !empty($_POST['tag_aktiv'][$i])) ? '1' : '0',
                       'weg' => (isset($_POST['tag_weg']) && is_array($_POST['tag_weg'])
                                 && !empty($_POST['tag_weg'][$i])) ? '1' : '',
                       'opt' => array());
            foreach (bl_tag_optionen() as $k) {
                $z['opt'][$k] = $feld('tag_' . $k, $i);
            }
            // Eine unberuehrte neue Zeile reist nicht mit.
            if (trim($z['kennung']) === '' && $z['alt'] === '' && trim($z['name']) === '') {
                continue;
            }
            $z['bean'] = isset($bean_tags[$i]) && is_array($bean_tags[$i])
                       ? array_values(array_unique($bean_tags[$i])) : array();
            $tags[] = $z;
        }
        // Aus dem Suchlauf angehakte Geraete: als neue Zeile, damit sie nicht verloren gehen.
        $neue = isset($_POST['neu_kennung']) && is_array($_POST['neu_kennung']) ? $_POST['neu_kennung'] : array();
        foreach ($neue as $roh => $_an) {
            if (count($tags) >= 200 || !bl_eingabe_tauglich((string) $roh)) {
                continue;
            }
            $nn = isset($_POST['neu_name']) && is_array($_POST['neu_name']) && isset($_POST['neu_name'][$roh])
                  && bl_eingabe_tauglich($_POST['neu_name'][$roh]) ? $_POST['neu_name'][$roh] : '';
            $tags[] = array('kennung' => (string) $roh, 'alt' => '', 'name' => $nn, 'aktiv' => '1',
                            'weg' => '', 'opt' => array(), 'bean' => array());
        }
    }
    return array('form' => $form, 'werte' => $werte,
                 'beanstandet' => array_values(array_unique(array_map('strval', $beanstandet))),
                 'tags' => $tags);
}

/** Die Eingaben aus der Einmalmeldung annehmen (nur bekannte Felder, nur Text). */
function bl_eingaben_setzen($roh = null)
{
    static $ein = array('form' => '', 'werte' => array(), 'beanstandet' => array(), 'tags' => array());
    if ($roh === null) {
        return $ein;
    }
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])
        || bl_eingabe_felder($roh['form']) === null) {
        return $ein;
    }
    $f = bl_eingabe_felder($roh['form']);
    $bekannt = array_merge($f['text'], $f['haken']);
    $werte = array();
    if (isset($roh['werte']) && is_array($roh['werte'])) {
        foreach ($roh['werte'] as $k => $v) {
            if (in_array((string) $k, $bekannt, true) && is_string($v)) {
                $werte[(string) $k] = $v;
            }
        }
    }
    $bean = array();
    if (isset($roh['beanstandet']) && is_array($roh['beanstandet'])) {
        foreach ($roh['beanstandet'] as $b) {
            if (is_string($b) && (in_array($b, $f['text'], true) || $b === 'tags')) {
                $bean[] = $b;
            }
        }
    }
    $tags = array();
    if ($roh['form'] === 'settings' && isset($roh['tags']) && is_array($roh['tags'])) {
        foreach (array_slice($roh['tags'], 0, 200) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $s = function ($k) use ($t) {
                return isset($t[$k]) && is_string($t[$k]) ? $t[$k] : '';
            };
            $opt = array();
            foreach (bl_tag_optionen() as $k) {
                if (isset($t['opt'][$k]) && is_string($t['opt'][$k]) && $t['opt'][$k] !== '') {
                    $opt[$k] = $t['opt'][$k];
                }
            }
            $tb = array();
            if (isset($t['bean']) && is_array($t['bean'])) {
                foreach ($t['bean'] as $b) {
                    // 1.3.36: auch irk und bindkey (bl_irk_aus_post/bl_bindkey_aus_post markieren sie) - sonst
                    // fehlte nach der Umleitung der rote Rahmen, und die Zusatzangaben blieben zugeklappt.
                    if (is_string($b) && in_array($b, array_merge(array('kennung', 'name', 'irk', 'bindkey'), bl_tag_optionen()), true)) {
                        $tb[] = $b;
                    }
                }
            }
            // 1.3.36 (Fremdpruefung 09.11.2026): die Art aus der eingetippten Kennung. Bis 1.3.35 stand 'art' => '' -
            // nach jeder Beanstandung fehlten in allen Tag-Zeilen die Felder fuer IRK und Bind-Key (index.php fragt
            // $tag['art'] === 'irk' bzw. 'mac').
            list($bl_ea, $bl_ek) = bl_kennung($s('kennung'));
            $tags[] = array('art' => $bl_ea, 'kennung' => $s('kennung'), 'mac' => $bl_ea === 'mac' ? $bl_ek : '', 'name' => $s('name'),
                            'aktiv' => $s('aktiv') === '1' ? '1' : '0', 'opt' => $opt,
                            '_alt' => $s('alt'), '_weg' => $s('weg') === '1', '_bean' => $tb);
        }
    }
    if ($bean) {
        $ein = array('form' => $roh['form'], 'werte' => $werte, 'beanstandet' => $bean, 'tags' => $tags);
    }
    return $ein;
}

/** Welches Formular zeigt gerade Eingaben ('' = keines)? */
function bl_eingaben_aktiv()
{
    $ein = bl_eingaben_setzen();
    return $ein['form'];
}

/** Die Tag-Zeilen, wie sie eingetippt waren (nur fuer das Formular Einstellungen). */
function bl_eingabe_tagzeilen()
{
    $ein = bl_eingaben_setzen();
    return $ein['form'] === 'settings' ? $ein['tags'] : array();
}

/** Wert eines Textfelds: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function bl_eingabe($form, $feld, $gespeichert)
{
    $ein = bl_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld];
    }
    return $gespeichert;
}

/** Haken: nach einer Beanstandung der abgeschickte Stand, sonst der gespeicherte. */
function bl_eingabe_an($form, $feld, $gespeichert)
{
    $ein = bl_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte'])) {
        return $ein['werte'][$feld] === '1';
    }
    return (bool) $gespeichert;
}

/** Das beanstandete Feld wird rot umrandet (Klasse sm-beanstandet). */
function bl_markierung($feld)
{
    $ein = bl_eingaben_setzen();
    return in_array($feld, $ein['beanstandet'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/** Dasselbe fuer ein Feld einer eingetippten Tag-Zeile. */
function bl_tag_markierung($tag, $feld)
{
    return (isset($tag['_bean']) && is_array($tag['_bean']) && in_array($feld, $tag['_bean'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/** Die Zusatzangaben einer Zeile aufgeklappt, wenn eine davon beanstandet ist. */
function bl_tag_offen($tag)
{
    if (isset($tag['_bean']) && is_array($tag['_bean'])) {
        foreach ($tag['_bean'] as $b) {
            if ($b !== 'kennung' && $b !== 'name') {
                return ' open';
            }
        }
    }
    return '';
}

function bl_einmal_datei()
{
    $p = bl_paths();
    return $p['datadir'] . '/einmalmeldung.json';
}

function bl_einmal_schreiben($daten)
{
    $p = bl_paths();
    if (!is_dir($p['datadir']) && !@mkdir($p['datadir'], 0775, true)) {
        return false;
    }
    $daten['zeit'] = time();
    // Die Ausgabe fremder Programme (bluetoothctl, dmesg, python3) kann
    // ungueltiges UTF-8 tragen; json_encode lieferte dann false, und die
    // Seite wurde ohne Umleitung gezeigt (im Pruefstand an test=bluetooth
    // und test=selbsttest gemessen). Ersetzen statt scheitern - die Flagge
    // gibt es seit PHP 7.2.
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                              | JSON_INVALID_UTF8_SUBSTITUTE);
    return $js !== false && bl_datei_schreiben(bl_einmal_datei(), $js, 0600);
}

function bl_einmal_lesen()
{
    $f = bl_einmal_datei();
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $aus = array();
    foreach (array('fehler', 'gescheitert') as $k) {
        $aus[$k] = array();
        if (isset($d[$k]) && is_array($d[$k])) {
            foreach ($d[$k] as $m) {
                if (is_string($m)) { $aus[$k][] = $m; }
            }
        }
    }
    foreach (array('hinweis', 'error', 'test_titel', 'test_text') as $k) {
        $aus[$k] = isset($d[$k]) && is_string($d[$k]) ? $d[$k] : '';
    }
    $aus['saved'] = !empty($d['saved']);
    $aus['such'] = isset($d['such']) && is_array($d['such']) ? $d['such'] : null;
    $aus['tags'] = isset($d['tags']) && is_array($d['tags']) ? $d['tags'] : null;
    // X-2 (Verbesserungsbau 30.09.2026): geprueft wird in bl_eingaben_setzen().
    $aus['eingaben'] = isset($d['eingaben']) && is_array($d['eingaben']) ? $d['eingaben'] : null;
    // Gruppe 33: Ergebnis des Cloud-Abrufs (Lage, Liste OHNE Schluessel, Zweitfaktor-Link, Meldung).
    $aus['cloud'] = isset($d['cloud']) && is_array($d['cloud']) && isset($d['cloud']['lage']) ? $d['cloud'] : null;
    return $aus;
}

/**
 * Die Zweige, die NICHT je Tag angelegt werden (seit 1.3.20, Pruefung
 * 29.09.2026, MQTT 5): person/<P>/... und scanner/<S>/<T>/... Bis 1.3.19
 * hatten sie keine Zeile in der Thementabelle, und die Pruefzeile nahm sie
 * ausdruecklich aus - vier gesendete Themen, drei davon retained, ohne Zeile.
 */
function bl_zweig_themen()
{
    return array(
        'person/<P>/present'      => array('s' => 'THEMA.P_PRESENT', 'art' => 'digital'),
        'person/<P>/last_seen_ts' => array('s' => 'THEMA.P_LAST_SEEN_TS', 'art' => 'analog'),
        // Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026); seit Gruppe 13 (V10) fuer jede Person
        'person/<P>/anwesend_gesamt' => array('s' => 'THEMA.P_GESAMT', 'art' => 'digital'),
        'person/<P>/quelle'          => array('s' => 'THEMA.P_QUELLE', 'art' => 'text'),
        // 1.3.36: Personen und Raeume (nur mit raum_personen und Raumzuordnung)
        'person/<P>/raum'            => array('s' => 'THEMA.P_RAUM', 'art' => 'text'),
        'person/<P>/raum_seit'       => array('s' => 'THEMA.P_RAUM_SEIT', 'art' => 'analog'),
        'summary/raum/<R>/anzahl'    => array('s' => 'THEMA.R_ANZAHL', 'art' => 'analog'),
        'summary/raum/<R>/namen'     => array('s' => 'THEMA.R_NAMEN', 'art' => 'text'),
        'scanner/<S>/<T>/present' => array('s' => 'THEMA.SC_PRESENT', 'art' => 'digital'),
        'scanner/<S>/<T>/rssi'    => array('s' => 'THEMA.SC_RSSI', 'art' => 'analog'),
        // Gruppe 13 (E9): Lebenszeichen je Scanner, nur bei scanner_themen
        'scanner/<S>/status/zaehler' => array('s' => 'THEMA.SC_ZAEHLER', 'art' => 'analog'),
    );
}

/** "person/<P>/present" -> "person//present" - die Form, die bl_gesendete_themen() liefert. */
function bl_zweig_normal($k)
{
    return preg_replace('/<[A-Z]>/', '', (string) $k);
}

/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function bl_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = bl_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt (seit 1.3.20 wirklich: bis 1.3.19 kam das chmod
     * erst nach file_put_contents), Nebendatei mit PID, und Erfolg nur bei
     * voller Laenge - bl_datei_schreiben() (Pruefung 29.09.2026, C7). Eine
     * gekuerzte Datei faellt beim naechsten Lesen durch das Muster und wuerde
     * neu gewuerfelt; jedes offene Formular waere dann ungueltig. */
    // Scheitert das Ablegen, gilt das neue Wort nur fuer diese Anfrage; die
    // naechste wuerfelt neu, und der Wachposten weist das Formular dann ab.
    bl_datei_schreiben($datei, $neu, 0600);
    $wort = $neu;
    return $wort;
}

function bl_formtoken()
{
    $grund = bl_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function bl_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(bl_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function bl_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = bl_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return bl_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return bl_t('WACHE.FALSCH');
    }
    return '';
}

/* ==================================================================
 * Gruppe 13 (1.3.28): MQTT-Gateway (Namenstabelle, V2-Hakenliste), Befehlsbruecke, IRK, Haus-Themen
 * ==================================================================
 *
 * Bauvorschrift wz_bild/GRUPPE13_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe13_bl.txt (2.5-2.7,
 * 3.2-3.7, 7), ENTSCHEIDUNGEN_GRUPPE13_BLE.md E1-E19. Die Werte sendet der Dienst selbst ueber paho (seit
 * jeher); der Reiter "Einbindung in Loxone" nennt jetzt die Gateway-Namen fuer einfache Virtuelle Eingaenge -
 * die Vorlage mit Virtuellen HTTP-Eingaengen (VirtualInHttp an http://localhost) ist entfallen (E12; Wiki
 * widget_mqtt, Gedaechtnis gateway-werte-virtueller-eingang). Befehle aus Loxone nimmt die gemeinsame
 * Befehlsbruecke an (bin/befehlsbruecke.php, Stammfassung; bin/befehle_zuordnung.php) und reicht sie ueber
 * bl_bb_tun() an bl_steuern() - die vorhandene Auftragsdatei. Kein HTTP auf sich selbst, kein Kindprozess.
 */

/** Altoptionen je Tag (B19): beim Lesen erkannt, beim Speichern verworfen - dieselbe Liste wie ALTOPTIONEN. */
function bl_altoptionen()
{
    return array('min', 'raum');
}

/** V1: "IRK:<name>" normieren (wie kennung_lesen() in Python), sonst ''. */
function bl_irk_kennung($wert)
{
    return preg_match('/^irk:([A-Za-z0-9_-]{1,40})$/i', trim((string) $wert), $m) ? 'IRK:' . $m[1] : '';
}

/** V1: irk.json (0600) neben der Konfiguration: Name -> 32 Hex. Nur gueltige Eintraege. */
function bl_irk_datei()
{
    return bl_paths()['configdir'] . '/irk.json';
}

function bl_irk_lesen()
{
    $f = bl_irk_datei();
    $j = is_readable($f) ? @json_decode((string) @file_get_contents($f), true) : null;
    $aus = array();
    if (is_array($j)) {
        foreach ($j as $n => $v) {
            if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $n) && is_string($v)
                && preg_match('/^[0-9A-Fa-f]{32}$/', $v)) {
                $aus[(string) $n] = strtolower($v);
            }
        }
    }
    return $aus;
}

/** Schreiben mit 0600 (Rechte vor Inhalt, atomar). Leere Tabelle: Datei bleibt mit {} stehen. */
function bl_irk_schreiben($tab)
{
    $f = bl_irk_datei();
    if (bl_paths()['home'] === '' && !is_dir(dirname($f))) {
        return false;
    }
    ksort($tab);
    return bl_datei_schreiben($f, json_encode((object) $tab, JSON_PRETTY_PRINT) . "\n", 0600);
}

/** Anzeige im Formular: nie der Schluessel und seit 1.3.29 auch keine Ziffern daraus (Gruppe 33, E-E5/N6) -
 * nur "hinterlegt" oder "fehlt", wie beim Bind-Key. */
function bl_irk_anzeige($name, $tab = null)
{
    if (!is_array($tab)) { $tab = bl_irk_lesen(); }
    return isset($tab[$name]) ? bl_t('TEXT.IRK_HINTERLEGT') : bl_t('TEXT.IRK_FEHLT');
}

/**
 * V1: die Schluessel aus dem Formular (tag_irk[<i>], nur Schreiben) zu den Tags. Leer = unveraendert.
 * Rueckgabe array(neue Tabelle, geaendert). Beanstandet wird in $mangel (Feld beanstandet, Tabelle unveraendert).
 */
function bl_irk_aus_post(&$mangel, $tags, &$bean = null)
{
    $tab = bl_irk_lesen();
    $roh = isset($_POST['tag_irk']) && is_array($_POST['tag_irk']) ? $_POST['tag_irk'] : array();
    $kenn = isset($_POST['tag_kennung']) && is_array($_POST['tag_kennung']) ? $_POST['tag_kennung'] : array();
    $geaendert = false;
    foreach ($roh as $i => $w) {
        if (!is_string($w) || trim($w) === '') {
            continue;
        }
        $name = isset($kenn[$i]) ? bl_irk_kennung($kenn[$i]) : '';
        $w = preg_replace('/[\s:]/', '', $w);
        if ($name === '' || !preg_match('/^[0-9A-Fa-f]{32}$/', $w)) {
            $mangel[] = sprintf(bl_t('MANGEL.IRK'), $name !== '' ? $name : (is_string($kenn[$i] ?? null) ? bl_kuerzen($kenn[$i], 40) : '-'));
            if (is_array($bean)) { $bean[$i][] = 'irk'; }
            continue;
        }
        $tab[substr($name, 4)] = strtolower($w);
        $geaendert = true;
    }
    // Schluessel entfernter Tags fallen weg (keine Waisen in irk.json).
    $namen = array();
    foreach ($tags as $t) {
        if (isset($t['art']) && $t['art'] === 'irk') { $namen[] = substr($t['kennung'], 4); }
    }
    foreach (array_keys($tab) as $n) {
        if (!in_array($n, $namen, true)) { unset($tab[$n]); $geaendert = true; }
    }
    return array($tab, $geaendert);
}

/** V6-V8/V12: <name> nach Regeln/07 Nr. 15 - dieselbe Regel wie haus_name() in bl_common.py. */
function bl_haus_name($text)
{
    $t = strtolower((string) $text);
    $t = strtr($t, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'));
    $t = preg_replace('/[^a-z0-9]/', '_', $t);
    return substr($t, 0, 40);
}

/** 1.3.38 (K6): je Eintrag der Haus-Name, gleiche Namen bekommen _2, _3 ... in der Reihenfolge der Liste - dieselbe
 *  Regel wie haus_namen() in bl_common.py (Nr. 15). Leer bleibt leer. */
function bl_haus_namen(array $liste)
{
    $aus = array();
    $gesehen = array();
    foreach ($liste as $x) {
        $n = bl_haus_name($x);
        if ($n === '') {
            $aus[] = '';
            continue;
        }
        if (isset($gesehen[$n])) {
            $gesehen[$n]++;
            $k = (string) $gesehen[$n];
            $aus[] = substr($n, 0, 40 - strlen($k) - 1) . '_' . $k;
        } else {
            $gesehen[$n] = 1;
            $aus[] = $n;
        }
    }
    return $aus;
}

/**
 * B24/E2: eine Tag-Zeile, die bl_tag_pruefen() verwirft, NICHT verlieren (Rueckweg nach dem Suchlauf). Uebernommen
 * wird sie, wenn Kennung, aktiv und Name taugen; die Zusatzangaben bleiben unveraendert stehen, die Zeile traegt die
 * Beanstandung ('_bean'). Speichern beanstandet sie erneut (bl_tags_aus_post) - nichts wird still geaendert oder
 * geloescht. Rueckgabe Tag oder null.
 */
function bl_tag_beanstandet($t, $grund)
{
    if (!is_array($t)) {
        return null;
    }
    foreach (array('kennung', 'aktiv', 'name') as $f) {
        if (!isset($t[$f]) || !is_string($t[$f])) {
            return null;
        }
    }
    list($art, $kennung) = bl_kennung($t['kennung']);
    if ($art === '') {
        return null;
    }
    $opt = array();
    foreach ((isset($t['opt']) && is_array($t['opt'])) ? $t['opt'] : array() as $k => $v) {
        if (in_array($k, bl_tag_optionen(), true) && is_string($v)) {
            $opt[$k] = $v;
        }
    }
    $feld = strpos((string) $grund, 'opt.') === 0 ? substr((string) $grund, 4) : (string) $grund;
    return array('art' => $art, 'kennung' => $kennung, 'mac' => $art === 'mac' ? $kennung : '',
                 'aktiv' => $t['aktiv'] === '1' ? '1' : '0', 'name' => $t['name'], 'opt' => $opt,
                 '_bean' => array($feld));
}

/* ---------------- Namenstabelle und V2-Hakenliste (Entwurf 2.5, E11) ---------------- */

/** Der Name, den das MQTT-Gateway einem Thema gibt: Praefix und Thema, "/", "%" und Leerzeichen als "_". EINE Stelle. */
function bl_vi_name($thema, $cfg)
{
    $p = (string) bl_cfg($cfg, 'themenpraefix', 'blescanner');
    return str_replace(array('/', '%', ' '), '_', $p . '/' . trim((string) $thema, '/'));
}

/**
 * Die Themen fuer Loxone - EINE Quelle fuer Namenstabelle (Reiter Einbindung), Thementabelle (Reiter Test,
 * test=themen, B25) und Pruefzeile. Aus denselben Quellen wie bisher: bl_allgemeine_themen(), bl_status_themen(),
 * bl_zusatzthemen(), bl_sensor_themen() je aktivem Tag, bl_personen() (V10: anwesend_gesamt/quelle fuer JEDE Person),
 * scanner/... bei scanner_themen, dazu status/zaehler, button, kalibrierung.
 * Je Zeile: thema (ohne Praefix), name (Gateway), text (bool), retained, v2 ('N' Noncached, 'R' Resetaftersend, ''),
 * muster (im Musterprojekt), min, max, s (Sprachschluessel der Bedeutung), wer (Tag-Bezeichnung oder Person).
 */
function bl_mqtt_namen($cfg, $tags)
{
    $aus = array();
    $zeile = function ($thema, $info, $wer = '') use ($cfg, &$aus) {
        $stamm = bl_thema_stamm($thema);
        $v2 = '';
        if ($thema === 'server/ok') { $v2 = 'N'; }
        if ($stamm === 'button') { $v2 = 'R'; }
        $aus[] = array('thema' => $thema, 'name' => bl_vi_name($thema, $cfg), 'text' => $info['art'] === 'text',
                       'retained' => bl_retain_fuer($thema), 'v2' => $v2, 'muster' => false,
                       'min' => isset($info['min']) ? $info['min'] : 0, 'max' => isset($info['max']) ? $info['max'] : 0,
                       's' => $info['s'], 'wer' => $wer);
    };
    foreach (bl_allgemeine_themen() as $k => $info) {
        $zeile($k, $info);
    }
    $grund = array_merge(bl_status_themen(), bl_zusatzthemen($cfg));
    $scanner = trim((string) bl_cfg($cfg, 'scanner_name', ''));
    foreach ($tags as $tag) {
        if (!isset($tag['aktiv']) || $tag['aktiv'] !== '1') {
            continue;
        }
        $t = bl_thema($tag);
        $wer = $tag['name'] !== '' ? $tag['name'] : $tag['kennung'];
        $alle = array_merge($grund, bl_sensor_themen($tag['kennung']));
        if (isset($tag['opt']['batt']) && $tag['opt']['batt'] === '1' && !isset($alle['battery'])) {
            $alle['battery'] = array('s' => 'THEMA.BATTERY', 'art' => 'analog', 'min' => -1, 'max' => 100, 'einheit' => '%');
        }
        $alle['kalibrierung'] = array('s' => 'THEMA.KALIBRIERUNG', 'art' => 'analog', 'min' => -255, 'max' => 0,
                                      'einheit' => 'dBm');
        foreach ($alle as $k => $info) {
            $zeile($t . '/' . $k, $info, $wer);
        }
        if (bl_cfg($cfg, 'bthome', '0') === '1' && bl_cfg($cfg, 'beacon', '1') === '1') {
            $zeile($t . '/button', array('s' => 'THEMA.BUTTON', 'art' => 'text'), $wer);
        }
    }
    $personen = bl_personen($tags);
    $tag_personen = $personen;
    // 1.3.36: Personen und Raeume nur mit raum_personen UND Raumzuordnung, und nur fuer Personen an Tags.
    $raum_personen = bl_cfg($cfg, 'raum', '0') === '1' && bl_cfg($cfg, 'raum_personen', '0') === '1';
    if (bl_cfg($cfg, 'wlan_kopplung', '0') === '1') {
        foreach (bl_wlan_zuordnung(bl_cfg($cfg, 'wlan_zuordnung', '')) as $paar) {
            if (!in_array($paar[1], $personen, true)) { $personen[] = $paar[1]; }
        }
    }
    foreach ($personen as $pn) {
        foreach (bl_zweig_themen() as $k => $info) {
            if (strpos($k, 'person/<P>/') !== 0) {
                continue;
            }
            if (in_array($k, array('person/<P>/raum', 'person/<P>/raum_seit'), true)
                && (!$raum_personen || !in_array($pn, $tag_personen, true))) {
                continue;
            }
            $info['min'] = substr($k, -15) === 'anwesend_gesamt' ? -1 : 0;
            $info['max'] = $info['art'] === 'analog' ? 2147483647 : 1;
            $zeile(str_replace('<P>', $pn, $k), $info, $pn);
        }
    }
    if ($raum_personen) {
        // Die Raeume kennt erst der Dienst (Scannernamen, ESPresense-Raeume): aus dem Abbild, sobald einmal jemand
        // darin war. Ohne Abbild fehlen diese Zeilen - sie kommen nach dem ersten Treffer.
        $s = bl_status();
        $raeume = ($s && isset($s['raeume']) && is_array($s['raeume'])) ? $s['raeume'] : array();
        foreach ($raeume as $r) {
            if (!is_string($r) || !preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $r)) {
                continue;
            }
            $zeile('summary/raum/' . $r . '/anzahl', array('s' => 'THEMA.R_ANZAHL', 'art' => 'analog', 'min' => 0, 'max' => 999), $r);
            $zeile('summary/raum/' . $r . '/namen', array('s' => 'THEMA.R_NAMEN', 'art' => 'text'), $r);
        }
    }
    if (bl_cfg($cfg, 'scanner_themen', '0') === '1') {
        $zeile('scanner/' . ($scanner !== '' ? $scanner : '<S>') . '/status/zaehler',
               array('s' => 'THEMA.SC_ZAEHLER', 'art' => 'analog', 'min' => 0, 'max' => 999));
    }
    // Musterprojekt (Entwurf 2.5): 21 Haken mit den Musternamen; mit E5 Kalibrierung 22; Gruppe 33 lage/alter 24.
    $muster = bl_muster_themen();
    foreach ($aus as $i => $z) {
        $aus[$i]['muster'] = in_array($z['thema'], $muster, true);
    }
    return $aus;
}

/**
 * Die Themen des Musterprojekts (Praefix-frei; Musternamen schluessel1, thermometer_wohnzimmer, Person1, Person2) -
 * Entwurf 2.5 und Abschnitt 9: Seite 1 (7), Tags (9 + Kalibrierung E5), Messwerte (5) = 22 Haken.
 * Mit eigenen Namen rechnet bl_bausteinliste_ersatz() dieselbe Ersetzung wie in der Bausteinliste.
 */
function bl_muster_themen()
{
    return array('server/online', 'status/zaehler', 'server/ok', 'summary/present', 'summary/tags',
                 'person/Person1/anwesend_gesamt', 'person/Person2/anwesend_gesamt',
                 'schluessel1/present', 'schluessel1/level', 'schluessel1/rssi_avg', 'schluessel1/last_seen',
                 'schluessel1/distance', 'schluessel1/battery', 'person/Person1/quelle', 'summary/names',
                 'schluessel1/raum', 'schluessel1/kalibrierung',
                 'thermometer_wohnzimmer/present', 'thermometer_wohnzimmer/sensor/temperatur',
                 'thermometer_wohnzimmer/sensor/feuchte', 'thermometer_wohnzimmer/sensor/batterie',
                 'thermometer_wohnzimmer/last_seen',
                 // Gruppe 33 (Entwurf 2.4, Musterprojekt #14/#15): 24 Haken
                 'thermometer_wohnzimmer/sensor/lage', 'thermometer_wohnzimmer/sensor/alter');
}

/** Die Musterthemen mit den eigenen Namen (Praefix ohne, Alias und Personen wie bl_bausteinliste_ersatz()). */
function bl_muster_themen_eigen($cfg, $tags)
{
    $e = bl_bausteinliste_ersatz($cfg, $tags);
    $ersatz = array('Person1' => $e['Person1'], 'Person2' => $e['Person2'], 'schluessel1' => $e['schluessel1'],
                    'thermometer_wohnzimmer' => $e['thermometer_wohnzimmer']);
    $aus = array();
    foreach (bl_muster_themen() as $t) {
        $aus[] = strtr($t, $ersatz);
    }
    return $aus;
}

/* ---------------- Gateway V2: Selbsttest der Haken (Nachtwelle N4, 1.3.30) ---------------- */

/**
 * Die Abos des MQTT-Gateways V2 aus config/system/subscriptions.json (Subscriptions[].Id je Thema, Noncached):
 * array(Thema => Noncached ja/nein), null = nicht lesbar. Form wie MGiSmart 1.1.28 mg_v2_abos() und Govee 0.9.30
 * gv_mqtt_v2_haken_lage(); die Datei liest bl_v2_haken_lage().
 */
function bl_v2_abos($daten)
{
    if (!is_array($daten)) {
        return null;
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) {
            $liste = $daten[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    return $abo;
}

/**
 * Soll gegen Ist, rein (liest nichts): array(1|0, fehlt[], noncached_fehlt[], noncached_zu_viel[], angehakt, soll).
 * Soll je Zeile array('thema' => volles Thema, 'name' => Gateway-Name, 'noncached' => bool). Noncached wird in
 * beide Richtungen verglichen: fehlt es, reicht das Gateway einen gleichen Wert nicht weiter; steht es zu viel, geht
 * jeder Wert erneut an den Miniserver.
 */
function bl_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc_fehlt = array();
    $nc_zuviel = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ($e['noncached'] && !$abo[$e['thema']]) {
            $nc_fehlt[] = $e['name'];
        } elseif (!$e['noncached'] && $abo[$e['thema']]) {
            $nc_zuviel[] = $e['name'];
        }
    }
    return array(($fehlt || $nc_fehlt || $nc_zuviel) ? 0 : 1, $fehlt, $nc_fehlt, $nc_zuviel, $da, count($soll));
}

/**
 * Das Soll fuer den Selbsttest: die Zeilen der Namenstabelle (bl_mqtt_namen() - DIESELBE Funktion wie die Tabelle im
 * Reiter Einbindung, Regeln/07), die dort in der Spalte Musterprojekt "ja" tragen (bl_muster_themen_eigen(), mit den
 * eigenen Namen), ohne Textthemen. Noncached: Spalte V2 = N (server/ok).
 */
function bl_v2_haken_soll($cfg, $tags)
{
    $p = (string) bl_cfg($cfg, 'themenpraefix', 'blescanner');
    $muster = bl_muster_themen_eigen($cfg, $tags);
    $aus = array();
    foreach (bl_mqtt_namen($cfg, $tags) as $z) {
        if ($z['text'] || !in_array($z['thema'], $muster, true)) {
            continue;
        }
        $aus[] = array('thema' => $p . '/' . trim((string) $z['thema'], '/'), 'name' => $z['name'],
                       'noncached' => $z['v2'] === 'N');
    }
    return $aus;
}

/**
 * Selbsttestzeile "Gateway V2: sind alle Datenpunkte der Hakenliste angehakt?" (Nachtwelle N4, Vorschrift Punkt 4):
 * array(1|0|2, Text ohne Markup). 1 alle Haken da und Noncached wie in der Hakenliste; 0 es fehlt ein Haken oder
 * Noncached stimmt nicht (mit Namen); 2 trifft nicht zu (MQTT dieses Plugins aus, Gateway V1) oder nicht
 * feststellbar (Fassung 0, keine Wurzel, subscriptions.json nicht lesbar). Liest nur.
 */
function bl_v2_haken_lage($cfg, $tags)
{
    if (bl_cfg($cfg, 'mqtt', '1') !== '1') {
        return array(2, bl_t('TEST.V2_AUS'));
    }
    $fassung = (int) bl_gateway_fassung();
    $home = (string) bl_paths()['home'];
    if ($fassung === 0 || $home === '') {
        return array(2, bl_t('TEST.V2_UNKLAR'));
    }
    if ($fassung < 2) {
        return array(2, bl_t('TEST.V2_V1'));
    }
    $abo = bl_v2_abos(@json_decode((string) @file_get_contents($home . '/config/system/subscriptions.json'), true));
    if ($abo === null) {
        return array(2, bl_t('TEST.V2_UNKLAR'));
    }
    $liste = bl_v2_haken_soll($cfg, $tags);
    if (!$liste) {
        return array(2, bl_t('TEST.V2_LEER'));   // leere Hakenliste: kein Urteil (Hausform wie N7)
    }
    list($ok, $fehlt, $nc_fehlt, $nc_zuviel, $da, $soll) = bl_v2_haken_abgleich($liste, $abo);
    $txt = sprintf(bl_t('TEST.V2_ZAHL'), $da, $soll);
    if ($fehlt) {
        $txt .= ' ' . sprintf(bl_t('TEST.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' …' : ''));
    }
    if ($nc_fehlt) {
        $txt .= ' ' . sprintf(bl_t('TEST.V2_NC_FEHLT'), implode(', ', $nc_fehlt));
    }
    if ($nc_zuviel) {
        $txt .= ' ' . sprintf(bl_t('TEST.V2_NC_ZUVIEL'), implode(', ', $nc_zuviel));
    }
    return array($ok, $txt);
}

/* ---------------- Befehle fuer Loxone (Entwurf 3.2, 3.7; Musterprojekt Abschnitt 6) ---------------- */

/** Der UDP-Eingang des MQTT-Gateways (general.json Mqtt.Udpinport), sonst 11884. */
function bl_udp_eingang()
{
    $h = bl_paths()['home'];
    $g = $h !== '' ? @json_decode((string) @file_get_contents($h . '/config/system/general.json'), true) : null;
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : array();
    $port = isset($m['Udpinport']) && is_numeric($m['Udpinport']) ? (int) $m['Udpinport'] : 0;
    return ($port >= 1 && $port <= 65535) ? $port : 11884;
}

/** Rechnername fuer /dev/udp/<LoxBerry>/<Port> - ohne Port aus der Adresszeile. */
function bl_host()
{
    $h = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $h = preg_replace('/:\d+$/', '', preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $h));
    if ($h === '') {
        $h = (string) (@gethostname() ?: 'loxberry');
    }
    return $h;
}

/**
 * Die Befehle fuer Loxone - EINE Quelle fuer die Tabelle im Reiter Einbindung und die Vorlage MQTT-Befehle:
 * je AKTIVEM Tag "BLE-Scanner NG: Testmodus <Name>" und "BLE-Scanner NG: Kalibrieren <Name>" (Nutzlast = Zweig des
 * Tags), dazu "BLE-Scanner NG: Batterielauf" (1). Kein Titel gleicht einem Gateway-Eingang (die heissen
 * <praefix>_..., hier "BLE-Scanner NG: ..."). Je Zeile: titel, name (Bruecke), wert, eingreifend.
 */
function bl_mqtt_befehle($cfg, $tags)
{
    $aus = array();
    foreach ($tags as $tag) {
        if (!isset($tag['aktiv']) || $tag['aktiv'] !== '1') {
            continue;
        }
        $wer = $tag['name'] !== '' ? $tag['name'] : bl_thema($tag);
        $aus[] = array('titel' => 'BLE-Scanner NG: Testmodus ' . $wer, 'name' => 'testmodus', 'wert' => bl_thema($tag),
                       'eingreifend' => false);
        $aus[] = array('titel' => 'BLE-Scanner NG: Kalibrieren ' . $wer, 'name' => 'kalibrierung',
                       'wert' => bl_thema($tag), 'eingreifend' => false);
    }
    $aus[] = array('titel' => 'BLE-Scanner NG: Batterielauf', 'name' => 'batterie', 'wert' => '1', 'eingreifend' => true);
    return $aus;
}

function bl_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    // Reihenfolge und Felder wie die massgebliche Ausfuhr aus Loxone Config (Bauart db_xml_virtual_out, Dashboard).
    $o .= '<VirtualOut HintText="" Title="' . bl_x($kopf['title']) . '" Comment="' . bl_x($kopf['comment'])
        . '" Address="' . bl_x($kopf['address']) . '" CmdInit="" CloseAfterSend="true" CmdSep=";">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd Title="' . bl_x($c['title']) . '" Comment="' . bl_x($c['comment'])
            . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . bl_x($c['on']) . '" CmdOnHTTP="" CmdOnPost="" '
            . 'CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText=""/>'
            . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Vorlage "MQTT-Befehle" (Entwurf 3.7): ein Virtueller Ausgang an /dev/udp/<LoxBerry>/<UDP-Eingang des Gateways>,
 * CloseAfterSend, je Zeile aus bl_mqtt_befehle() "publish <praefix>/befehl/<name> <wert>" (nie retain).
 * Rueckgabe array(Dateiname, Inhalt).
 */
function bl_vorlage_mqtt_out($cfg, $tags)
{
    $praefix = (string) bl_cfg($cfg, 'themenpraefix', 'blescanner');
    $cmds = array();
    foreach (bl_mqtt_befehle($cfg, $tags) as $b) {
        $cmds[] = array('title' => $b['titel'], 'comment' => bl_kuerzen(substr($b['titel'], strlen('BLE-Scanner NG: ')), 40),
                        'on' => 'publish ' . $praefix . '/befehl/' . $b['name'] . ' ' . $b['wert']);
    }
    return array('VQ_BLE-Scanner-NG_MQTT.xml', bl_xml_virtual_out(array(
        'title' => 'BLE-Scanner NG Befehle (MQTT)',
        'address' => '/dev/udp/' . bl_host() . '/' . bl_udp_eingang(),
        'comment' => bl_kuerzen(sprintf(bl_t('VORLAGE.FUSS'), date('d.m.Y')), 40),
    ), $cmds));
}

/* ---------------- Befehlsbruecke: die Seite der Linie (Entwurf 3.3-3.5) ---------------- */

/** Einstellungen fuer die Bruecke (bl_config_read() liest jedes Mal - ein Haken wirkt ohne Neustart). */
function bl_bb_einstellungen()
{
    list($c) = bl_config_read();
    return array('praefix' => (string) bl_cfg($c, 'themenpraefix', 'blescanner'),
                 'annehmen' => bl_cfg($c, 'befehle_mqtt_ein', '0') === '1',
                 'eingreifend' => bl_cfg($c, 'befehle_eingreifend_ein', '0') === '1',
                 'mqtt_ein' => bl_cfg($c, 'mqtt', '1') === '1');
}

/**
 * Protokoll der Befehlsbruecke: <logdir>/befehlsbruecke.log, angehaengt unter flock, "YYYY-MM-DD HH:MM:SS STUFE text",
 * gekappt wie log_kappung_kb (die zweite Haelfte bleibt). Der Reiter Logdateien listet die Datei von selbst.
 * Kein Kennwort - die Stammfassung gibt keins weiter.
 */
function bl_bb_protokoll($stufe, $text)
{
    $p = bl_paths();
    if ($p['home'] === '' || !is_dir($p['logdir'])) {
        return;
    }
    $f = $p['logdir'] . '/befehlsbruecke.log';
    $zeile = date('Y-m-d H:i:s') . ' ' . strtoupper((string) $stufe) . ' '
           . str_replace(array("\r", "\n"), ' ', (string) $text) . "\n";
    $fh = @fopen($f, 'a+');
    if ($fh === false) {
        return;
    }
    if (@flock($fh, LOCK_EX)) {
        list($c) = bl_config_read();
        $grenze = max(16, (int) bl_cfg($c, 'log_kappung_kb', '500')) * 1024;
        $st = @fstat($fh);
        if ($st && $st['size'] > $grenze) {
            @fseek($fh, (int) ($st['size'] / 2));
            $rest = (string) @stream_get_contents($fh);
            $nl = strpos($rest, "\n");
            $rest = $nl === false ? '' : substr($rest, $nl + 1);
            @ftruncate($fh, 0);
            @fseek($fh, 0);
            @fwrite($fh, $rest);
        }
        @fseek($fh, 0, SEEK_END);
        @fwrite($fh, $zeile);
        @flock($fh, LOCK_UN);
    }
    @fclose($fh);
}

/** Upgrade-Marke data/plugins/<ordner>.upgrade_laeuft: liegt sie und ist hoechstens 3600 s alt? (wie daemon, Waechter) */
function bl_upgrade_laeuft()
{
    $p = bl_paths();
    if ($p['home'] === '') {
        return false;
    }
    $m = $p['datadir'] . '.upgrade_laeuft';
    return is_file($m) && (time() - (int) @filemtime($m)) < 3600;
}

/**
 * Ein Befehl der Befehlsbruecke (tun() in bin/befehle_zuordnung.php). $name testmodus | kalibrierung | batterie, $wert
 * der von der Bruecke gepruefte Wert (Text = Zweig des Tags, bzw. 1). Reihenfolge (Entwurf 3.4): Upgrade-Marke ->
 * AKTUALISIERUNG; Dienst laeuft nicht -> DIENST_AUS; Zweig -> Kennung (TAG_UNBEKANNT/TAG_INAKTIV) bzw. Batterielauf
 * moeglich (BATTERIE_AUS); dann bl_steuern(..., true) - die Auftragsdatei, wartend bis sie frei ist (BELEGT).
 * Kein HTTP, kein Kindprozess. Rueckgabe array(bool ok, Grund ASCII).
 */
function bl_bb_tun($name, $wert)
{
    $name = (string) $name;
    $wert = trim((string) $wert);
    if (bl_upgrade_laeuft()) {
        return array(false, 'AKTUALISIERUNG');
    }
    if (bl_dienst_pid() <= 0) {
        return array(false, 'DIENST_AUS');
    }
    list($cfg, $tags) = bl_config_read();
    if ($name === 'testmodus' || $name === 'kalibrierung') {
        $treffer = null;
        foreach ($tags as $t) {
            if (bl_thema($t) === $wert) {
                $treffer = $t;
                if ($t['aktiv'] === '1') { break; }
            }
        }
        if ($treffer === null) {
            return array(false, 'TAG_UNBEKANNT');
        }
        if ($treffer['aktiv'] !== '1') {
            return array(false, 'TAG_INAKTIV');
        }
        $grund = bl_steuern($name, $treffer['kennung'], $name === 'testmodus' ? 60 : 10, true);
    } elseif ($name === 'batterie') {
        $mit = false;
        foreach ($tags as $t) {
            if ($t['aktiv'] === '1' && isset($t['opt']['batt']) && $t['opt']['batt'] === '1') { $mit = true; }
        }
        if (bl_cfg($cfg, 'batterie', '0') !== '1' || !$mit) {
            return array(false, 'BATTERIE_AUS');
        }
        $grund = bl_steuern('batterie', '', 0, true);
    } else {
        return array(false, 'UNBEKANNT');
    }
    return $grund === '' ? array(true, '') : array(false, $grund);
}

/** Wo liegt eine Datei unter bin/? Installiert bin/plugins/<ordner>/, im Archiv <wurzel>/bin/. '' = fehlt. */
function bl_bin_datei($name)
{
    foreach (array(bl_paths()['bindir'] . '/' . $name, dirname(dirname(__DIR__)) . '/bin/' . $name) as $k) {
        if (@is_file($k)) {
            return $k;
        }
    }
    return '';
}

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN: kein Hauptteil). */
function bl_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $f = bl_bin_datei('befehlsbruecke.php');
    if ($f === '') {
        return false;
    }
    if (!defined('BB_NUR_LADEN')) {
        define('BB_NUR_LADEN', true);
    }
    require_once $f;
    return function_exists('bb_waechter');
}

/**
 * Direktstart bzw. -stopp der Bruecke nach dem Speichern (Bauart sp_/db_bruecke_schalten): befehlsbruecke.sh start
 * startet nur nach --soll, nie als root, nie waehrend eines Updates, nie doppelt; der Waechter in cron.01min startet
 * hoechstens alle 600 s neu. $neustart: Praefix gewechselt (stop, dann start). Haengt NICHT am Neustart des Dienstes.
 * Rueckgabe array(''|'ein'|'aus'|'start_fehl'|'stop_fehl', Ausgabe).
 */
function bl_bruecke_schalten($neustart = false)
{
    if (DIRECTORY_SEPARATOR === '\\' || bl_paths()['home'] === '' || !bl_bruecke_laden()) {
        return array('', '');
    }
    $p = bb_pfade();
    if (!$p) {
        return array('', '');
    }
    $e = bl_bb_einstellungen();
    $laeuft = (bool) bb_dienste();
    if ($e['annehmen']) {
        if ($laeuft && !$neustart) {
            return array('', '');
        }
        if ($laeuft) {
            bb_sh($p, 'stop');
        }
        list($rc, $aus) = bb_sh($p, 'start');
        return array(($rc === 0 && bb_dienste()) ? 'ein' : 'start_fehl', $aus);
    }
    if (!$laeuft) {
        return array('', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array(($rc === 0 && !bb_dienste()) ? 'aus' : 'stop_fehl', $aus);
}

/** Ein Satz zum Ergebnis von bl_bruecke_schalten() ('' = nichts zu sagen), unmaskiert. */
function bl_bruecke_satz(array $r)
{
    $a = $r[1] !== '' ? trim((string) $r[1]) : '-';
    switch ($r[0]) {
        case 'ein':
            return sprintf(bl_t('BEFEHL.M_GESTARTET'), $a);
        case 'aus':
            return sprintf(bl_t('BEFEHL.M_ANGEHALTEN'), $a);
        case 'start_fehl':
            return sprintf(bl_t('BEFEHL.M_START_FEHL'), $a);
        case 'stop_fehl':
            return sprintf(bl_t('BEFEHL.M_STOP_FEHL'), $a);
    }
    return '';
}

/* ---------------- Pruefzeilen der Umstellung (Reiter Test; Entwurf 3.5) ----------------
 * Rueckgabe je array(Zustand, Anmerkung) fuer bl_zeile(): true in Ordnung, false Befund, null Hinweis/keine Aussage. */

/** Laeuft die Befehlsbruecke? Zustand und letzte Quittung aus ihrer zustand.json. Haken aus ist grau. */
function bl_pruef_bruecke($cfg)
{
    if (!bl_bruecke_laden()) {
        return array(false, bl_t('BEFEHL.T_FEHLT'));
    }
    list($z, $fehler) = bb_zuordnung_laden(bl_bin_datei('befehle_zuordnung.php'));
    if ($z === null) {
        return array(false, sprintf(bl_t('BEFEHL.T_DEFEKT'), $fehler));
    }
    list($f) = bb_zuordnung_pruefen($z);
    if ($f) {
        return array(false, sprintf(bl_t('BEFEHL.T_DEFEKT'), $f[0]));
    }
    if (bl_cfg($cfg, 'befehle_mqtt_ein', '0') !== '1') {
        return array(null, bl_t('BEFEHL.T_AUS'));
    }
    if (DIRECTORY_SEPARATOR === '\\' || bl_paths()['home'] === '' || !bb_pfade()) {
        return array(null, bl_t('BEFEHL.T_ARCHIV'));
    }
    $zu = bb_zustand_lesen();
    $alter = ($zu && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if (!bb_dienste()) {
        return array(false, bl_t('BEFEHL.T_STEHT'));
    }
    if ($alter === null || $alter > BB_WACHE_GRENZE) {
        return array(false, sprintf(bl_t('BEFEHL.T_HAENGT'), (int) $alter));
    }
    if (empty($zu['abo'])) {
        return array(false, sprintf(bl_t('BEFEHL.T_OHNE_ABO'), isset($zu['fehler']) ? (string) $zu['fehler'] : ''));
    }
    $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
    $letzte = (!empty($zu['letzter']) && is_array($zu['letzter']))
        ? (string) $zu['letzter']['name'] . ': ' . (string) $zu['letzter']['ergebnis'] . ' (vor '
          . max(0, time() - (int) $zu['letzter']['zeit']) . ' s)' : '-';
    return array(true, sprintf(bl_t('BEFEHL.T_LAEUFT'),
        date('d.m.Y H:i', isset($zu['start']) ? (int) $zu['start'] : time()),
        isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0,
        isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0, $letzte));
}

/** "befehlsbruecke.php --pruefen" (mosquitto_sub/_pub = mosquitto-clients, Zuordnung) als EINE Zeile. */
function bl_pruef_bruecke_einrichtung($cfg)
{
    if (!bl_bruecke_laden()) {
        return array(false, bl_t('BEFEHL.T_FEHLT'));
    }
    ob_start();
    bb_pruefen();
    $aus = array_values(array_filter(explode("\n", (string) ob_get_clean()), 'strlen'));
    if (!$aus) {
        return array(null, bl_t('BEFEHL.T_UNKLAR'));
    }
    $fehl = array_values(preg_grep('/^\[FEHL\]/', $aus));
    if ($fehl) {
        // Fehlende mosquitto-clients bei ausgeschaltetem Haken: ein Hinweis, kein Kreuz.
        return array(bl_cfg($cfg, 'befehle_mqtt_ein', '0') === '1' ? false : null,
                     implode(' | ', $fehl) . ' ' . bl_t('BEFEHL.T_EINRICHTUNG'));
    }
    return array(true, implode(' | ', array($aus[0], end($aus))));
}

/** Taugt das Praefix fuer die Bruecke (bb_praefix_gueltig)? Die eigene Regel ([A-Za-z0-9_-]+) ist enger in der Form,
 *  aber nicht in der Laenge (Bruecke: hoechstens 40 je Ebene). */
function bl_pruef_praefix_bruecke($cfg)
{
    $p = (string) bl_cfg($cfg, 'themenpraefix', 'blescanner');
    $ok = bl_bruecke_laden() && function_exists('bb_praefix_gueltig') ? bb_praefix_gueltig($p)
        : (bool) preg_match('#^[A-Za-z0-9_\-]{1,40}(/[A-Za-z0-9_\-]{1,40}){0,4}\z#', $p);
    return array($ok, sprintf(bl_t($ok ? 'BEFEHL.T_PRAEFIX' : 'BEFEHL.T_PRAEFIX_FALSCH'), $p));
}

/** F2: traegt ein Tag einen reservierten Alias (status, befehl ...)? Rot mit Satz "Alias umbenennen" - keine stille
 *  Umbenennung (Regeln/07: bestehende Themen werden nicht umbenannt). */
function bl_pruef_reserviert($tags)
{
    $fehl = array();
    foreach ($tags as $t) {
        $a = isset($t['opt']['alias']) ? strtolower(bl_saeubern($t['opt']['alias'])) : '';
        if ($a !== '' && in_array($a, bl_reservierte_zweige(), true)) {
            $fehl[] = $t['opt']['alias'] . ' (' . ($t['name'] !== '' ? $t['name'] : $t['kennung']) . ')';
        }
    }
    return $fehl ? array(false, sprintf(bl_t('TEST.ALIAS_RESERVIERT_DA'), implode(', ', $fehl)))
                 : array(true, implode(', ', bl_reservierte_zweige()));
}

/** Lebenszeichen status/zaehler im Abbild (zaehler) - zaehlt es? Nur mit gueltigem Abbild eine Aussage. */
function bl_pruef_lebenszeichen($status, $gilt, $grund)
{
    if (!$gilt || !is_array($status)) {
        return array(null, $grund);
    }
    if (!isset($status['zaehler'])) {
        return array(null, bl_t('TEST.ZAEHLER_ALT'));
    }
    return array(true, sprintf(bl_t('TEST.ZAEHLER'), (int) $status['zaehler']));
}

/** Auftragsdatei frei? (E3: liegt sie laenger als 2 x intervall + 1 s, nimmt der Dienst nichts an) */
function bl_pruef_auftrag($cfg)
{
    $f = bl_paths()['steuer'];
    if (!is_file($f)) {
        return array(true, bl_t('TEST.AUFTRAG_FREI'));
    }
    $alter = time() - (int) @filemtime($f);
    $grenze = 2 * max(2, (int) bl_cfg($cfg, 'intervall', '5')) + 1;
    return array($alter <= $grenze ? null : false, sprintf(bl_t('TEST.AUFTRAG_LIEGT'), $alter));
}

/** V1: IRK - Haken, Weg (cryptography oder rein), Schluessel je irk-Tag. */
function bl_pruef_irk($cfg, $tags, $status)
{
    $irk_tags = array();
    foreach ($tags as $t) {
        if ($t['art'] === 'irk' && $t['aktiv'] === '1') { $irk_tags[] = substr($t['kennung'], 4); }
    }
    if (bl_cfg($cfg, 'irk_aufloesen', '0') !== '1') {
        return array(null, bl_t($irk_tags ? 'TEST.IRK_AUS_MIT_TAGS' : 'TEST.IRK_AUS'));
    }
    $tab = bl_irk_lesen();
    $fehlt = array();
    foreach ($irk_tags as $n) {
        if (!isset($tab[$n])) { $fehlt[] = $n; }
    }
    $weg = is_array($status) && isset($status['irk']['weg']) ? (string) $status['irk']['weg'] : '?';
    if ($fehlt) {
        return array(false, sprintf(bl_t('TEST.IRK_FEHLT'), implode(', ', $fehlt)));
    }
    $f = bl_irk_datei();
    $rechte = is_file($f) ? substr(sprintf('%o', @fileperms($f)), -3) : '-';
    if (is_file($f) && DIRECTORY_SEPARATOR !== '\\' && $rechte !== '600') {
        return array(false, sprintf(bl_t('TEST.IRK_RECHTE'), $rechte));
    }
    return array(true, sprintf(bl_t('TEST.IRK_OK'), count($irk_tags), $weg));
}

/** V6-V8/V12: Haus-Themen - Haken, Sollstand und eigene Themen aus dem Abbild. */
function bl_pruef_haus($cfg, $tags, $status, $gilt)
{
    $tag_haus = 0;
    foreach ($tags as $t) {
        if ($t['aktiv'] === '1' && isset($t['opt']['haus']) && $t['opt']['haus'] === '1') { $tag_haus++; }
    }
    if (bl_cfg($cfg, 'haus_anwesenheit', '0') !== '1' && !$tag_haus) {
        return array(null, bl_t('TEST.HAUS_AUS'));
    }
    if (!$gilt || !is_array($status) || !isset($status['haus'])) {
        return array(null, bl_t('TEST.HAUS_UNKLAR'));
    }
    $th = (array) $status['haus']['themen'];
    // Dieselbe Namensregel in zwei Sprachen (Nr. 15): jede Person muss unter dem Namen im Abbild stehen, den der
    // Dienst rechnet. 1.3.38 (K6): dieselbe Namenmenge wie haus_sollstand() im Dienst - die Personen der aktiven Tags
    // und bei wlan_kopplung=1 die der WLAN-Zuordnung, eindeutig, sortiert wie Python sorted() (SORT_STRING), mit den
    // _2-Zusaetzen aus bl_haus_namen(). Bis 1.3.37 nur die Personen der Tags ohne Zusatz: "anna_2" und Personen nur
    // aus der Zuordnung wurden nie geprueft, und ein doppelter Name stand zweimal in der Meldung.
    $fehlt = array();
    if (bl_cfg($cfg, 'haus_anwesenheit', '0') === '1') {
        $namen = bl_personen($tags);
        if (bl_cfg($cfg, 'wlan_kopplung', '0') === '1') {
            foreach (bl_wlan_zuordnung(bl_cfg($cfg, 'wlan_zuordnung', '')) as $paar) { $namen[] = $paar[1]; }
        }
        $namen = array_values(array_unique($namen, SORT_STRING));
        sort($namen, SORT_STRING);
        foreach (bl_haus_namen($namen) as $hn) {
            if ($hn === '') { continue; }
            $soll = 'haus/anwesenheit/' . $hn;
            if (!in_array($soll, $th, true)) { $fehlt[] = $soll; }
        }
        $fehlt = array_values(array_unique($fehlt));
    }
    if ($fehlt) {
        return array(false, sprintf(bl_t('TEST.HAUS_NAMEN'), implode(', ', $fehlt)));
    }
    return array(true, sprintf(bl_t('TEST.HAUS_OK'), count($th), implode(', ', array_slice($th, 0, 6)),
                               (int) $status['haus']['zaehler']));
}

/** Jeder Name der Namenstabelle entsteht aus seinem Thema nach der Gateway-Regel, und jeder Stamm steht im Sendecode. */
function bl_pruef_namen($cfg, $tags, $namen = null)
{
    if (!is_array($namen)) { $namen = bl_mqtt_namen($cfg, $tags); }
    $p = (string) bl_cfg($cfg, 'themenpraefix', 'blescanner');
    $gesendet = bl_gesendete_themen();
    $fehl = array();
    foreach ($namen as $z) {
        $soll = str_replace(array('/', '%', ' '), '_', $p . '/' . $z['thema']);
        if ($z['name'] !== $soll) {
            $fehl[] = $z['name'];
            continue;
        }
        if (is_array($gesendet)) {
            $stamm = bl_thema_stamm($z['thema']);
            $da = false;
            foreach ($gesendet as $g) {
                if (bl_thema_stamm($g) === $stamm) { $da = true; break; }
            }
            if (!$da) { $fehl[] = $z['thema']; }
        }
    }
    return $fehl ? array(false, sprintf(bl_t('TEST.NAMEN_FEHL'), implode(', ', $fehl)))
                 : array(true, sprintf(bl_t('TEST.NAMEN'), count($namen)));
}

/** Kein Befehlstitel der Vorlage MQTT-Befehle gleicht einem Gateway-Namen; die Vorlage laedt als XML. */
function bl_pruef_vorlage_mqtt($cfg, $tags)
{
    list(, $xml) = bl_vorlage_mqtt_out($cfg, $tags);
    $namen = array();
    foreach (bl_mqtt_namen($cfg, $tags) as $z) { $namen[] = $z['name']; }
    $gleich = array();
    foreach (bl_mqtt_befehle($cfg, $tags) as $b) {
        if (in_array($b['titel'], $namen, true)) { $gleich[] = $b['titel']; }
    }
    if ($gleich) {
        return array(false, sprintf(bl_t('TEST.VORLAGE_GLEICH'), implode(', ', $gleich)));
    }
    if (!function_exists('simplexml_load_string')) {
        return array(null, bl_t('TEST.KEIN_SIMPLEXML'));
    }
    $alt = libxml_use_internal_errors(true);
    $x = simplexml_load_string((string) $xml);
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    if ($x === false) {
        return array(false, bl_t('TEST.VORLAGE_KAPUTT'));
    }
    return array(true, sprintf(bl_t('TEST.VORLAGE_MQTT'), count(bl_mqtt_befehle($cfg, $tags))));
}

/* ==================================================================
 * Gruppe 33 (1.3.29): Xiaomi-Thermometer - Bind-Keys, Cloud-Abruf, Pruefzeilen
 *
 * bindkey.json (0600) neben der Konfiguration: MAC -> 32 Hex (MiBeacon v4/v5) oder 24 Hex (alte Form, nicht
 * lesbar). Der Dienst braucht den Schluessel, also eigene 0600-Datei (Regeln/05) wie irk.json. Formular nur
 * Schreiben (Passwortfeld, nie vorbefuellt), Anzeige nur "hinterlegt"/"fehlt" - nie Ziffern (E-E3/E-E4/E-E5).
 * Der Abruf aus der Xiaomi-Cloud laeuft ueber das gemeinsame Stammmodul xiaomi_cloud.php 1.0.0 (Filter ble=1);
 * Kennwort, Sitzung und Schluessel gehen nie in Protokoll, Meldung oder HTML.
 * ================================================================== */

function bl_bindkey_datei()
{
    return bl_paths()['configdir'] . '/bindkey.json';
}

/** Bind-Key normieren wie schluessel_lesen() in bl_common.py: Leerraum, ':' und '-' weg, klein; 24/32 Hex, sonst ''. */
function bl_bindkey_form($wert)
{
    $t = strtolower((string) preg_replace('/[\s:\-]/', '', (string) $wert));
    return preg_match('/^([0-9a-f]{24}|[0-9a-f]{32})\z/', $t) ? $t : '';
}

function bl_bindkey_lesen()
{
    $f = bl_bindkey_datei();
    $j = is_readable($f) ? @json_decode((string) @file_get_contents($f), true) : null;
    $aus = array();
    if (is_array($j)) {
        foreach ($j as $mac => $v) {
            $m = bl_mac((string) $mac);
            $k = is_string($v) ? bl_bindkey_form($v) : '';
            if ($m !== '' && $k !== '') {
                $aus[$m] = $k;
            }
        }
    }
    ksort($aus);
    return $aus;
}

/** Schreiben mit 0600 (Rechte vor Inhalt, atomar), Zweitschrift macht preupgrade.sh. Leere Tabelle: {}. */
function bl_bindkey_schreiben($tab)
{
    $f = bl_bindkey_datei();
    if (bl_paths()['home'] === '' && !is_dir(dirname($f))) {
        return false;
    }
    ksort($tab);
    return bl_datei_schreiben($f, json_encode((object) $tab, JSON_PRETTY_PRINT) . "\n", 0600);
}

/** Anzeige an der Tag-Zeile: nie Ziffern (Entscheidung "nie in Ausgabe"). */
function bl_bindkey_anzeige($mac, $tab = null)
{
    if (!is_array($tab)) { $tab = bl_bindkey_lesen(); }
    if (!isset($tab[$mac])) {
        return bl_t('TEXT.BINDKEY_FEHLT');
    }
    return bl_t(strlen($tab[$mac]) === 32 ? 'TEXT.BINDKEY_HINTERLEGT' : 'TEXT.BINDKEY_ALT');
}

/**
 * Die Bind-Keys aus dem Formular (tag_bindkey[<i>] nur Schreiben, tag_bindkey_weg[<i>] Haken "loeschen").
 * Leeres Feld behaelt; Schluessel UND Haken zugleich -> beanstandet; Form 24/32 Hex, sonst MANGEL.BINDKEY mit der
 * Bezeichnung (nie der Wert). Schluessel entfernter MAC-Tags fallen weg (keine Waisen). Rueckgabe array(Tabelle, geaendert).
 */
function bl_bindkey_aus_post(&$mangel, $tags, &$bean = null)
{
    $tab = bl_bindkey_lesen();
    $roh = isset($_POST['tag_bindkey']) && is_array($_POST['tag_bindkey']) ? $_POST['tag_bindkey'] : array();
    $weg = isset($_POST['tag_bindkey_weg']) && is_array($_POST['tag_bindkey_weg']) ? $_POST['tag_bindkey_weg'] : array();
    $kenn = isset($_POST['tag_kennung']) && is_array($_POST['tag_kennung']) ? $_POST['tag_kennung'] : array();
    $namen = isset($_POST['tag_name']) && is_array($_POST['tag_name']) ? $_POST['tag_name'] : array();
    $geaendert = false;
    foreach (array_unique(array_merge(array_keys($roh), array_keys($weg))) as $i) {
        $w = isset($roh[$i]) && is_string($roh[$i]) ? trim($roh[$i]) : '';
        $loeschen = isset($weg[$i]) && $weg[$i] === '1';
        if ($w === '' && !$loeschen) {
            continue;
        }
        $mac = isset($kenn[$i]) && is_string($kenn[$i]) ? bl_mac($kenn[$i]) : '';
        $wer = (isset($namen[$i]) && is_string($namen[$i]) && trim($namen[$i]) !== '')
            ? bl_kuerzen(trim($namen[$i]), 40) : ($mac !== '' ? $mac : '-');
        if ($w !== '' && $loeschen) {
            $mangel[] = sprintf(bl_t('MANGEL.BINDKEY_BEIDES'), $wer);
            if (is_array($bean)) { $bean[$i][] = 'bindkey'; }
            continue;
        }
        if ($mac === '') {
            $mangel[] = sprintf(bl_t('MANGEL.BINDKEY'), $wer);
            if (is_array($bean)) { $bean[$i][] = 'bindkey'; }
            continue;
        }
        if ($loeschen) {
            if (isset($tab[$mac])) { unset($tab[$mac]); $geaendert = true; }
            continue;
        }
        $k = bl_bindkey_form($w);
        if ($k === '') {
            $mangel[] = sprintf(bl_t('MANGEL.BINDKEY'), $wer);
            if (is_array($bean)) { $bean[$i][] = 'bindkey'; }
            continue;
        }
        if (!isset($tab[$mac]) || $tab[$mac] !== $k) { $tab[$mac] = $k; $geaendert = true; }
    }
    $macs = array();
    foreach ($tags as $t) {
        if (isset($t['art']) && $t['art'] === 'mac') { $macs[] = $t['mac']; }
    }
    foreach (array_keys($tab) as $m) {
        if (!in_array($m, $macs, true)) { unset($tab[$m]); $geaendert = true; }
    }
    return array($tab, $geaendert);
}

/** Lage der Messwerte als Text (0-5; Regeln/04: jeder Zustand hat seinen Sprachschluessel). */
function bl_lage_text($lage)
{
    $lage = (int) $lage;
    return ($lage >= 0 && $lage <= 5) ? bl_t('TEXT.LAGE_' . $lage) : '-';
}

/* ---------------- Bind-Key aus der Xiaomi-Cloud (Stammmodul xiaomi_cloud.php 1.0.0) ---------------- */

/** Das Stammmodul laden und die Ablage der Zwischenstaende (data-Ordner, 0600, <= 10 min) setzen. */
function bl_cloud_bereit()
{
    require_once __DIR__ . '/xiaomi_cloud.php';
    $d = bl_paths()['datadir'];
    if ($d !== '' && !is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    xc_ablage($d);
    return defined('XC_FASSUNG');
}

/** Filter dieser Linie: nur Bluetooth-Geraete (did mit "blt"), alle Modelle. */
function bl_cloud_filter()
{
    return array('modelle' => array(), 'ble' => 1);
}

/**
 * Ein Geraet der Auswahl uebernehmen (E-E7): gibt es einen Tag mit dieser MAC, bekommt er den Schluessel; sonst ein
 * neuer aktiver Tag <MAC>|1|<Name aus der Cloud>|alias=<haus_name(Name)> - Alias nur, wenn frei und zulaessig. Die
 * Auswahl ist danach verbraucht. Rueckgabe array(ok, Lage, Bezeichnung, alte_form).
 */
function bl_cloud_uebernehmen($index)
{
    bl_cloud_bereit();
    $a = xc_auswahl_nehmen((int) $index);
    if ($a === null || !isset($a['geraet']) || !is_array($a['geraet'])) {
        return array(false, 'ABGELAUFEN', '', false);
    }
    $erg = bl_cloud_geraete_eintragen(array($a['geraet']));
    $a = null;
    return $erg[0];
}

/**
 * 1.3.36 (Fremdpruefung 09.11.2026, Funktionsluecke): MEHRERE Geraete der Auswahl in einem Griff uebernehmen. Bis
 * dahin verbrauchte die erste Uebernahme die Liste, jedes weitere Thermometer brauchte eine neue Anmeldung samt
 * Zweitfaktor. Das Stammmodul konnte es seit 1.0.1 (xc_auswahl_mehrere()), die Oberflaeche nutzte es nicht.
 * $indizes: die angehakten Indizes. Rueckgabe: null = Auswahl abgelaufen oder verbraucht; array() = nichts angehakt
 * (die Liste bleibt liegen); sonst je Geraet array(ok, Lage, Bezeichnung, alte_form) wie bl_cloud_uebernehmen().
 */
function bl_cloud_mehrere(array $indizes)
{
    bl_cloud_bereit();
    $wahl = xc_auswahl_mehrere($indizes);
    if ($wahl === null) {
        return null;
    }
    if (!$wahl) {
        return array();
    }
    $erg = bl_cloud_geraete_eintragen(array_values($wahl));
    $wahl = null;
    return $erg;
}

/**
 * Der gemeinsame Kern (E-E7): je Geraet gibt es einen Tag mit dieser MAC -> er bekommt den Schluessel; sonst ein neuer
 * aktiver Tag <MAC>|1|<Name aus der Cloud>|alias=<haus_name(Name)> - Alias nur, wenn frei und zulaessig (frei auch
 * gegenueber den Aliasen, die in DIESEM Griff entstehen). Ein Geraet ohne MAC, ohne brauchbaren Schluessel oder mit
 * unzulaessigem Namen wird gemeldet und uebersprungen, die uebrigen gehen hinein. Konfiguration und bindkey.json
 * werden je EINMAL geschrieben; scheitert eines davon, scheitern alle Geraete dieses Schreibens (Lage SCHREIBEN).
 */
function bl_cloud_geraete_eintragen(array $geraete)
{
    list($cfg, $tags, ) = bl_config_read();
    $aliase = array();
    $macs = array();
    foreach ($tags as $t) {
        if ($t['art'] === 'mac') { $macs[$t['mac']] = true; }
        if (isset($t['opt']['alias']) && $t['opt']['alias'] !== '') { $aliase[] = strtolower(bl_saeubern($t['opt']['alias'])); }
    }
    $erg = array();
    $schluessel = array();
    $neue_tags = false;
    foreach ($geraete as $i => $g) {
        $g = is_array($g) ? $g : array();
        $name = isset($g['name']) && is_string($g['name']) ? trim($g['name']) : '';
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"\'|,=<>\\\\]/u', '', $name));
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 40, 'UTF-8') : substr($name, 0, 40);
        $mac = bl_mac(isset($g['mac']) ? (string) $g['mac'] : '');
        $key = bl_bindkey_form(isset($g['bindkey']) ? (string) $g['bindkey'] : '');
        $g = null;
        if ($mac === '') {
            $erg[$i] = array(false, 'MAC', $name, false);
            continue;
        }
        if ($key === '') {
            $erg[$i] = array(false, 'SCHLUESSEL', $name, false);
            continue;
        }
        $lage = 'BESTEHEND';
        if (!isset($macs[$mac])) {
            $alias = bl_haus_name($name);
            if ($alias === '' || trim($alias, '_') === '' || in_array($alias, bl_reservierte_zweige(), true)
                || in_array($alias, $aliase, true)) {
                $alias = '';
            }
            $neu = array('kennung' => $mac, 'aktiv' => '1', 'name' => $name,
                         'opt' => $alias !== '' ? array('alias' => $alias) : array());
            list($tag, $grund) = bl_tag_pruefen($neu);
            if ($tag === null) {
                $neu['opt'] = array();
                $alias = '';
                list($tag, $grund) = bl_tag_pruefen($neu);
            }
            if ($tag === null) {
                $erg[$i] = array(false, 'TAG', $name, false);
                continue;
            }
            $tags[] = $tag;
            $macs[$mac] = true;
            if ($alias !== '') { $aliase[] = $alias; }
            $neue_tags = true;
            $lage = $alias !== '' ? 'NEU' : 'NEU_OHNE_ALIAS';
        }
        $schluessel[$mac] = $key;
        $erg[$i] = array(true, $lage, $name !== '' ? $name : $mac, strlen($key) === 24);
        $key = '';
    }
    $fehl = false;
    if ($neue_tags && !bl_config_write($cfg, $tags)) {
        $fehl = true;
    }
    if (!$fehl && $schluessel) {
        $tab = bl_bindkey_lesen();
        foreach ($schluessel as $mac => $key) {
            $tab[$mac] = $key;
        }
        if (!bl_bindkey_schreiben($tab)) {
            $fehl = true;
        }
        $tab = null;
    }
    $schluessel = null;
    if ($fehl) {
        foreach ($erg as $i => $e) {
            if ($e[0]) { $erg[$i] = array(false, 'SCHREIBEN', $e[2], false); }
        }
    }
    return array_values($erg);
}

/** Meldung zur Uebernahme (je Lage ein fester Sprachschluessel - die Platzhalterpruefung sieht jeden Aufruf). */
function bl_cloud_text($lage, $wer)
{
    switch ($lage) {
        case 'BESTEHEND':      return sprintf(bl_t('CLOUD.M_UEB_BESTEHEND'), $wer);
        case 'NEU':            return sprintf(bl_t('CLOUD.M_UEB_NEU'), $wer);
        case 'NEU_OHNE_ALIAS': return sprintf(bl_t('CLOUD.M_UEB_NEU_OHNE_ALIAS'), $wer);
        case 'MAC':            return sprintf(bl_t('CLOUD.F_UEB_MAC'), $wer);
        case 'SCHLUESSEL':     return sprintf(bl_t('CLOUD.F_UEB_SCHLUESSEL'), $wer);
        case 'TAG':            return sprintf(bl_t('CLOUD.F_UEB_TAG'), $wer);
        case 'SCHREIBEN':      return sprintf(bl_t('CLOUD.F_UEB_SCHREIBEN'), $wer);
        case 'ABGELAUFEN':     return bl_t('CLOUD.F_UEB_ABGELAUFEN');
    }
    return bl_t('CLOUD.F_UEB_WAHL');
}

/* ---------------- Reiter Test (Gruppe 33) ---------------- */

/** Pruefzeile "Bind-Keys": Schluessel ohne Tag (gelb), Rechte 0600, Anzahl - nie MAC oder Schluessel. */
function bl_pruef_bindkey($tags, $status, $gilt)
{
    $tab = bl_bindkey_lesen();
    $macs = array();
    foreach ($tags as $t) {
        if ($t['art'] === 'mac') { $macs[] = $t['mac']; }
    }
    $waisen = 0;
    foreach (array_keys($tab) as $m) {
        if (!in_array($m, $macs, true)) { $waisen++; }
    }
    $f = bl_bindkey_datei();
    $rechte = is_file($f) ? substr(sprintf('%o', @fileperms($f)), -3) : '-';
    if (is_file($f) && DIRECTORY_SEPARATOR !== '\\' && $rechte !== '600') {
        return array(false, sprintf(bl_t('TEST.BINDKEY_RECHTE'), $rechte));
    }
    if ($waisen > 0) {
        return array(null, sprintf(bl_t('TEST.BINDKEY_WAISEN'), $waisen));
    }
    $geladen = ($gilt && is_array($status) && isset($status['bindkeys']['anzahl'])) ? (string) (int) $status['bindkeys']['anzahl'] : '?';
    return array(true, sprintf(bl_t('TEST.BINDKEY_OK'), count($tab), $geladen));
}

/**
 * Je aktivem Thermometer eine Zeile: Lage (Text 0-5), Alter, Format/Fassung, Schluessel hinterlegt/fehlt - nie Ziffern.
 * Gruen bei Lage 0, gelb bei 1 (noch kein Messwert seit dem Start), rot bei 2-5. Rueckgabe Liste von bl_zeile().
 */
function bl_pruef_thermometer($tags, $gilt)
{
    $aus = array();
    if (!$gilt) {
        return $aus;
    }
    $abbild = bl_zustaende();
    $tab = bl_bindkey_lesen();
    foreach ($tags as $t) {
        if ($t['aktiv'] !== '1' || !isset($abbild[$t['kennung']])) {
            continue;
        }
        $z = $abbild[$t['kennung']];
        if (!isset($z['sensor_lage']) || $z['sensor_lage'] === null) {
            continue;
        }
        $lage = (int) $z['sensor_lage'];
        $text = sprintf(bl_t('TEST.THERMO'), bl_lage_text($lage), (int) (isset($z['sensor_alter']) ? $z['sensor_alter'] : -1),
                        (string) $z['beaconart'] !== '' ? (string) $z['beaconart'] : '-',
                        $t['art'] === 'mac' ? bl_bindkey_anzeige($t['mac'], $tab) : '-');
        if ($lage === 3) {
            $text .= ' ' . bl_t('TEXT.LAGE_3_HILFE');
        }
        $wer = $t['name'] !== '' ? $t['name'] : $t['kennung'];
        $aus[] = bl_zeile(sprintf(bl_t('PRUEF.THERMO'), $wer), $lage === 0 ? true : ($lage === 1 ? null : false), $text);
    }
    return $aus;
}
