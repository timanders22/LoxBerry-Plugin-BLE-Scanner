<?php
/**
 * BLE-Scanner NG - Reiter Test: Selbstpruefung und Aktionen
 *
 * Zwei Teile:
 *   bl_pruefzeilen()    die Selbstpruefung - je Zeile eine Frage mit Haken,
 *                       Kreuz oder Strich. Ein Strich ist KEIN Haken: was
 *                       nicht gemessen werden konnte, sagt das auch.
 *   bl_test_ausfuehren()  die Knoepfe.
 *
 * Bis 1.2.10 war diese Datei durchgehend deutsch - null Aufrufe von bl_t().
 * In einer englischen Oberflaeche stand hier woertlich "Dienst: laeuft
 * nicht". Jeder sichtbare Text laeuft jetzt ueber die Sprachdateien.
 */

require_once __DIR__ . '/bl_lib.php';

/**
 * Einen Systembefehl ausfuehren und die Ausgabe zurueckgeben.
 *
 * Der Rueckgabewert wird ausgewertet: laeuft bluetoothctl in die Zeitgrenze,
 * bricht timeout ab und liefert 124 - die Ausgabe ist dann leer oder halb.
 * Eine leere Zeile haelt man fuer "kein Adapter vorhanden"; das ist etwas
 * voellig anderes als "BlueZ antwortet nicht mehr".
 */
function bl_sh($cmd)
{
    $out = array();
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    if ($code === 124) {
        return trim($text) === ''
            ? bl_t('TEST.ZEITGRENZE_LEER')
            : $text . "\n" . bl_t('TEST.ZEITGRENZE_HALB');
    }
    if ($code === 127) {
        return bl_t('TEST.BEFEHL_FEHLT');
    }
    if ($code !== 0 && trim($text) === '') {
        return sprintf(bl_t('TEST.RUECKGABEWERT'), (int) $code);
    }
    return $text;
}

/** Python-Aufruf zusammenbauen. */
function bl_python($skript, $argumente = '')
{
    $p = bl_paths();
    $datei = $p['bindir'] . '/' . $skript;
    if (!is_file($datei)) {
        return array(false, sprintf(bl_t('TEST.SKRIPT_FEHLT'), $datei));
    }
    $out = array();
    $code = 0;
    @exec('timeout 60 python3 ' . escapeshellarg($datei) . ' ' . $argumente
          . ' 2>&1', $out, $code);
    return array($code === 0 || $code === 1, implode("\n", $out));
}

/* ==================================================================
 * Selbstpruefung
 * ================================================================== */

function bl_zeile($text, $zustand, $anmerkung = '')
{
    return array('text' => $text, 'zustand' => $zustand, 'anmerkung' => $anmerkung);
}

/**
 * Misst, ob PHP und Python dieselbe Konfiguration lesen.
 *
 * Das ist die Pruefung zu dem Befund, an dem 1.2.10 Tags verloren hat: die
 * beiden Leser waren sich an zwei Faellen uneinig, und beim naechsten
 * Speichern gewann der PHP-Leser. Geprueft wird an EINER Datei, mit beiden
 * Lesern, ohne die Konfiguration des Anwenders anzufassen.
 */
function bl_leser_vergleich()
{
    $faelle = array(
        'tag1=AA:BB:CC:DD:EE:FF',
        'tag1=AA:BB:CC:DD:EE:FF|1|Schluessel | Justin',
        'tag1=AA:BB:CC:DD:EE:FF|1|Name|abw=90,alias=anna',
        'TAG1="BLE_AA_BB_CC_DD_EE_FF:on:1^on~2^off:Anna"',
        'tag1=IB:FDA50693A4E24FB1AFCFC6EB07647825:1:2|1|Beacon',
        'tag1=UNSINN',
    );
    $tmp = tempnam(sys_get_temp_dir(), 'blecmp');
    if ($tmp === false) {
        return array(null, bl_t('TEST.KEINE_TEMPDATEI'));
    }
    $abweichungen = array();
    $nicht_pruefbar = '';
    foreach ($faelle as $nr => $zeile) {
        @file_put_contents($tmp, "[CONFIG]\n" . $zeile . "\n");
        list($_w, $tags, $alt) = bl_config_read($tmp);
        $php = array();
        foreach ($tags as $t) {
            $php[] = $t['kennung'] . '|' . $t['aktiv'] . '|' . $t['name']
                   . '|' . bl_optionen_schreiben($t['opt']);
        }
        $php[] = 'ALT=' . ($alt ? '1' : '0');

        list($ok, $ausgabe) = bl_python('bl_lesen.py', escapeshellarg($tmp));
        if (!$ok) {
            $nicht_pruefbar = $ausgabe;
            break;
        }
        $py = @json_decode($ausgabe, true);
        if (!is_array($py) || !isset($py['zeilen'])) {
            $nicht_pruefbar = $ausgabe;
            break;
        }
        if ($php !== $py['zeilen']) {
            $abweichungen[] = sprintf(bl_t('TEST.LESER_ABWEICHUNG'),
                                      ($nr + 1), $zeile,
                                      implode(' / ', $php),
                                      implode(' / ', $py['zeilen']));
        }
    }
    @unlink($tmp);
    if ($nicht_pruefbar !== '') {
        return array(null, $nicht_pruefbar);
    }
    return array(count($abweichungen) === 0,
                 implode("\n", $abweichungen));
}

/** Vorgabewerte beider Seiten vergleichen. */
function bl_vorgaben_vergleich()
{
    list($ok, $ausgabe) = bl_python('bl_lesen.py', '--vorgaben');
    if (!$ok) {
        return array(null, $ausgabe);
    }
    $py = @json_decode($ausgabe, true);
    if (!is_array($py) || !isset($py['vorgaben'])) {
        return array(null, $ausgabe);
    }
    $php = bl_defaults();
    $nur_php = array_diff(array_keys($php), array_keys($py['vorgaben']));
    $nur_py = array_diff(array_keys($py['vorgaben']), array_keys($php));
    $andere = array();
    foreach ($php as $k => $v) {
        if (isset($py['vorgaben'][$k]) && (string) $py['vorgaben'][$k] !== (string) $v) {
            $andere[] = $k . ' (PHP ' . $v . ' / Python ' . $py['vorgaben'][$k] . ')';
        }
    }
    $meldung = array();
    if ($nur_php) { $meldung[] = sprintf(bl_t('TEST.NUR_PHP'), implode(', ', $nur_php)); }
    if ($nur_py)  { $meldung[] = sprintf(bl_t('TEST.NUR_PYTHON'), implode(', ', $nur_py)); }
    if ($andere)  { $meldung[] = sprintf(bl_t('TEST.ANDERER_WERT'), implode(', ', $andere)); }
    return array(count($meldung) === 0, implode("\n", $meldung));
}

/**
 * Passt die Themenliste zu dem, was der Sendecode wirklich veroeffentlicht?
 *
 * Eine Liste, die niemand nachmisst, laeuft auseinander - und dann legt die
 * Loxone-Vorlage virtuelle Eingaenge an, die dauerhaft auf 0 stehen, ohne
 * jede Fehlermeldung.
 */
function bl_themen_vergleich($cfg)
{
    $gesendet = bl_gesendete_themen();
    if ($gesendet === null) {
        return array(null, bl_t('TEST.SENDECODE_FEHLT'));
    }
    $erwartet = array();
    foreach (array_merge(bl_status_themen(), bl_zusatzthemen($cfg)) as $k => $_i) {
        $erwartet[] = $k;
    }
    foreach (bl_allgemeine_themen() as $k => $_i) {
        $erwartet[] = $k;
    }
    // Themen, die der Sendecode nur unter Bedingungen kennt und die in der
    // Anleitung bewusst nur bei eingeschalteter Einstellung stehen.
    $bedingt = array('distance', 'battery', 'battery_ts', 'raum', 'raum_seit',
                     'sensor/', 'person/', 'scanner/');
    $fehlt = array();
    foreach ($erwartet as $k) {
        if (!in_array($k, $gesendet, true)) {
            $fehlt[] = $k;
        }
    }
    $unbekannt = array();
    foreach ($gesendet as $k) {
        if (in_array($k, $erwartet, true)) {
            continue;
        }
        $treffer = false;
        foreach ($bedingt as $b) {
            if (strpos($k, $b) === 0 || strpos($k, rtrim($b, '/')) === 0) {
                $treffer = true;
                break;
            }
        }
        if (!$treffer) {
            $unbekannt[] = $k;
        }
    }
    $meldung = array();
    if ($fehlt) {
        $meldung[] = sprintf(bl_t('TEST.THEMA_FEHLT'), implode(', ', $fehlt));
    }
    if ($unbekannt) {
        $meldung[] = sprintf(bl_t('TEST.THEMA_UNDOKUMENTIERT'), implode(', ', $unbekannt));
    }
    return array(count($meldung) === 0, implode("\n", $meldung));
}

/** Die erzeugte Loxone-Vorlage auf Wohlgeformtheit pruefen. */
function bl_vorlage_pruefen($cfg, $tags)
{
    if (!function_exists('simplexml_load_string')) {
        return array(null, bl_t('TEST.KEIN_SIMPLEXML'));
    }
    $probe = $tags;
    if (!$probe) {
        // Auch ohne eingetragenen Tag laesst sich das Format pruefen - mit
        // einem Namen, der Anfuehrungszeichen, ein Und und einen Umlaut
        // enthaelt. Genau dort bricht es, wenn ENT_XML1 fehlt.
        $probe = array(array('art' => 'mac', 'kennung' => 'AA:BB:CC:DD:EE:FF',
                             'mac' => 'AA:BB:CC:DD:EE:FF', 'aktiv' => '1',
                             'name' => 'Schlüssel "Anna" & Co', 'opt' => array()));
    }
    list($_n, $inhalt, $anzahl, $uebersprungen) = bl_vorlage($cfg, $probe);
    $vorher = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($inhalt);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    if ($xml === false) {
        return array(false, bl_t('TEST.VORLAGE_KAPUTT'));
    }
    $crlf = substr_count($inhalt, "\r\n");
    $lf = substr_count($inhalt, "\n");
    $meldung = array();
    if ($crlf !== $lf) {
        $meldung[] = sprintf(bl_t('TEST.VORLAGE_ZEILENENDEN'), $crlf, $lf);
    }
    if (strpos($inhalt, '<Info templateType="2" minVersion="17010727"/>') === false) {
        $meldung[] = bl_t('TEST.VORLAGE_INFO_FEHLT');
    }
    if (strpos($inhalt, 'MinVal="-2147483647"') !== false) {
        $meldung[] = bl_t('TEST.VORLAGE_GRENZEN');
    }
    return array(count($meldung) === 0,
                 implode("\n", $meldung)
                 . ($meldung ? "\n" : '')
                 . sprintf(bl_t('TEST.VORLAGE_ANZAHL'), $anzahl, count($uebersprungen)));
}

/** Sprachdateien: deckungsgleich? */
function bl_sprachen_vergleich()
{
    $abschnitte = array('REITER', 'TEXT', 'LEGENDE', 'THEMA', 'TEST', 'VORLAGE', 'BAUSTEIN');
    $fehlend = array();
    foreach ($abschnitte as $a) {
        $s = bl_sprachschluessel($a);
        foreach (array_diff($s['de'], $s['en']) as $k) { $fehlend[] = 'en: ' . $a . '.' . $k; }
        foreach (array_diff($s['en'], $s['de']) as $k) { $fehlend[] = 'de: ' . $a . '.' . $k; }
    }
    return array(count($fehlend) === 0, implode(', ', array_slice($fehlend, 0, 12)));
}

/**
 * Die Selbstpruefung.
 *
 * Zustand: true = Haken, false = Kreuz, null = nicht pruefbar (Strich).
 */
function bl_pruefzeilen($cfg, $tags)
{
    $p = bl_paths();
    $zeilen = array();
    $status = bl_status();
    $pid = bl_dienst_pid();
    $aktiv = 0;
    foreach ($tags as $t) { if ($t['aktiv'] === '1') { $aktiv++; } }

    // --- Dienst
    $zeilen[] = bl_zeile(bl_t('PRUEF.DIENST'), $pid > 0,
                         $pid > 0 ? 'PID ' . $pid : bl_t('PRUEF.DIENST_NEIN'));

    $alter = bl_status_alter();
    $zeilen[] = bl_zeile(bl_t('PRUEF.ABBILD'),
                         $alter >= 0 ? ($alter <= 120) : null,
                         $alter < 0 ? bl_t('PRUEF.ABBILD_KEINS')
                                    : sprintf(bl_t('PRUEF.ABBILD_ALTER'), $alter));

    // Die Frage, die bis 1.2.10 nicht beantwortet werden konnte: hoert der
    // Adapter ueberhaupt noch etwas? Die Zustandsdatei allein taugt dafuer
    // nicht - sie wird in jedem Durchlauf geschrieben.
    $stille = bl_stille();
    $grenze = max(60, (int) bl_cfg($cfg, 'wachhund_stille', '300'));
    $zeilen[] = bl_zeile(bl_t('PRUEF.EMPFANG'),
                         $stille < 0 ? null : ($stille <= $grenze),
                         $stille < 0 ? bl_t('PRUEF.EMPFANG_UNBEKANNT')
                                     : sprintf(bl_t('PRUEF.EMPFANG_VOR'), $stille));

    if ($status && isset($status['adapter_ok'])) {
        $zeilen[] = bl_zeile(bl_t('PRUEF.ADAPTER_OK'), ((int) $status['adapter_ok']) === 1,
                             (string) bl_cfg($cfg, 'adapter', 'hci0'));
    } else {
        $zeilen[] = bl_zeile(bl_t('PRUEF.ADAPTER_OK'), null, bl_t('PRUEF.ABBILD_KEINS'));
    }

    // --- Betriebsart
    if ($status && !empty($status['betriebsart'])) {
        $zeilen[] = bl_zeile(bl_t('PRUEF.BETRIEBSART'),
                             $status['betriebsart'] === bl_cfg($cfg, 'betriebsart', 'signal'),
                             sprintf(bl_t('PRUEF.BETRIEBSART_IST'),
                                     $status['betriebsart'],
                                     bl_cfg($cfg, 'betriebsart', 'signal')));
    }

    // --- Werkzeuge und Module
    foreach (array('dbus', 'gi', 'paho.mqtt.client') as $m) {
        $r = bl_sh('python3 -c ' . escapeshellarg('import ' . $m));
        $zeilen[] = bl_zeile(sprintf(bl_t('PRUEF.MODUL'), $m), trim($r) === '',
                             trim($r) === '' ? '' : bl_t('PRUEF.MODUL_FEHLT'));
    }
    $bt = trim(bl_sh('command -v bluetoothctl'));
    $zeilen[] = bl_zeile(bl_t('PRUEF.BLUETOOTHCTL'), $bt !== '', $bt);

    // --- Tags
    $zeilen[] = bl_zeile(bl_t('PRUEF.TAGS'), $aktiv > 0,
                         sprintf(bl_t('PRUEF.TAGS_ANZAHL'), count($tags), $aktiv));

    // Wechselnde Adressen: der haeufigste Anwenderfehler dieser Plugin-Art.
    $wechselnd = array();
    if ($status) {
        foreach (bl_zustaende() as $k => $z) {
            if ($z['adresstyp'] === 'wechselnd') {
                $wechselnd[] = $z['name'] !== '' ? $z['name'] : $k;
            }
        }
        $zeilen[] = bl_zeile(bl_t('PRUEF.ADRESSTYP'), count($wechselnd) === 0,
                             $wechselnd ? implode(', ', $wechselnd) : '');
    } else {
        $zeilen[] = bl_zeile(bl_t('PRUEF.ADRESSTYP'), null, bl_t('PRUEF.ABBILD_KEINS'));
    }

    // --- MQTT
    $broker = bl_mqtt_broker();
    if (bl_cfg($cfg, 'mqtt', '1') === '1') {
        $zeilen[] = bl_zeile(bl_t('PRUEF.BROKER'), $broker !== '', $broker);
        $auto = bl_mqtt_autostart();
        $zeilen[] = bl_zeile(bl_t('PRUEF.AUTOSTART'), $auto,
                             $auto === null ? bl_t('PRUEF.AUTOSTART_UNBEKANNT') : '');
        if ($status) {
            $zeilen[] = bl_zeile(bl_t('PRUEF.MQTT_VERBUNDEN'),
                                 ((int) ($status['mqtt_verbunden'] ?? 0)) === 1,
                                 sprintf(bl_t('PRUEF.MQTT_ZAHLEN'),
                                         (int) ($status['mqtt_gesendet'] ?? 0),
                                         (int) ($status['mqtt_verluste'] ?? 0)));
        }
    }

    // --- HTTP
    if (bl_cfg($cfg, 'http_push', '0') === '1') {
        $ms = bl_miniserver();
        $zeilen[] = bl_zeile(bl_t('PRUEF.MINISERVER'), count($ms) > 0,
                             $ms ? $ms[0]['name'] . ' (' . $ms[0]['adresse'] . ')' : '');
        if ($status) {
            $offen = (int) ($status['push_offen'] ?? 0);
            $zeilen[] = bl_zeile(bl_t('PRUEF.PUSH_OFFEN'), $offen === 0,
                                 sprintf(bl_t('PRUEF.PUSH_ZAHLEN'), $offen,
                                         (int) ($status['push_fehler'] ?? 0)));
        }
    }

    // --- Die Pruefungen, die die Prüfkette selbst nicht sieht
    list($ok, $meldung) = bl_leser_vergleich();
    $zeilen[] = bl_zeile(bl_t('PRUEF.LESER'), $ok, $meldung);

    list($ok, $meldung) = bl_vorgaben_vergleich();
    $zeilen[] = bl_zeile(bl_t('PRUEF.VORGABEN'), $ok, $meldung);

    list($ok, $meldung) = bl_themen_vergleich($cfg);
    $zeilen[] = bl_zeile(bl_t('PRUEF.THEMENLISTE'), $ok, $meldung);

    list($ok, $meldung) = bl_vorlage_pruefen($cfg, $tags);
    $zeilen[] = bl_zeile(bl_t('PRUEF.VORLAGE'), $ok, $meldung);

    list($ok, $meldung) = bl_sprachen_vergleich();
    $zeilen[] = bl_zeile(bl_t('PRUEF.SPRACHEN'), $ok, $meldung);

    // --- Fassungsnummer
    $fassung = bl_fassung();
    $ausdatei = is_file($p['fassung'])
        ? trim((string) @file_get_contents($p['fassung'])) : '';
    if ($ausdatei === '') {
        $zeilen[] = bl_zeile(bl_t('PRUEF.FASSUNG'), null,
                             sprintf(bl_t('PRUEF.FASSUNG_KEINE'), $fassung));
    } else {
        $status_f = $status ? (string) ($status['version'] ?? '') : '';
        $zeilen[] = bl_zeile(bl_t('PRUEF.FASSUNG'),
                             $status_f === '' ? null : ($status_f === $ausdatei),
                             sprintf(bl_t('PRUEF.FASSUNG_IST'), $ausdatei,
                                     $status_f !== '' ? $status_f : '?'));
    }

    // --- Python-Selbstpruefung
    list($ok, $ausgabe) = bl_python('bl_selbsttest.py', '--json');
    $j = @json_decode($ausgabe, true);
    if (is_array($j) && isset($j['ok'])) {
        $zeilen[] = bl_zeile(bl_t('PRUEF.PYTHON_SELBSTTEST'),
                             ((int) $j['fehler']) === 0,
                             sprintf(bl_t('PRUEF.PYTHON_ZAHLEN'),
                                     (int) $j['ok'], (int) $j['fehler'],
                                     (int) $j['offen']));
    } else {
        $zeilen[] = bl_zeile(bl_t('PRUEF.PYTHON_SELBSTTEST'), null,
                             bl_kuerzen(trim($ausgabe), 200));
    }

    // --- Protokoll
    $logdatei = bl_log_file();
    if ($logdatei !== '') {
        $gr = (int) @filesize($logdatei);
        $kappung = max(16, (int) bl_cfg($cfg, 'log_kappung_kb', '500')) * 1024;
        $zeilen[] = bl_zeile(bl_t('PRUEF.PROTOKOLL'), $gr <= $kappung * 1.5,
                             sprintf(bl_t('PRUEF.PROTOKOLL_GROESSE'),
                                     round($gr / 1024), round($kappung / 1024)));
    }

    return $zeilen;
}

/* ==================================================================
 * Aktionen
 * ================================================================== */

function bl_test_ausfuehren($was, $zusatz = '')
{
    $p = bl_paths();
    list($cfg, $tags, $alt) = bl_config_read();

    switch ($was) {

        case 'selbsttest':
            $zeilen = bl_pruefzeilen($cfg, $tags);
            $t = '';
            $ok = $rot = $offen = 0;
            foreach ($zeilen as $z) {
                if ($z['zustand'] === true) { $zeichen = '[ok]'; $ok++; }
                elseif ($z['zustand'] === false) { $zeichen = '[XX]'; $rot++; }
                else { $zeichen = '[--]'; $offen++; }
                $t .= sprintf("%-4s %s%s\n", $zeichen, $z['text'],
                              $z['anmerkung'] !== '' ? "\n       " . str_replace("\n", "\n       ", $z['anmerkung']) : '');
            }
            $t .= "\n" . sprintf(bl_t('TEST.BILANZ'), $ok, $rot, $offen);
            return array(bl_t('TEST.T_SELBSTTEST'), $t);

        case 'status':
            $pid = bl_dienst_pid();
            $alter = bl_status_alter();
            $stille = bl_stille();
            $s = bl_status();
            $aktiv = 0;
            foreach ($tags as $t2) { if ($t2['aktiv'] === '1') { $aktiv++; } }
            $t  = sprintf("%-22s %s\n", bl_t('TEST.F_DIENST'),
                          $pid ? sprintf(bl_t('TEST.LAEUFT_PID'), $pid) : bl_t('TEST.LAEUFT_NICHT'));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_FASSUNG'), bl_fassung());
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_SCANNER'),
                          $s ? (string) ($s['scanner'] ?? '?') : '?');
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_BETRIEBSART'),
                          $s ? (string) ($s['betriebsart'] ?? '?') : '?');
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_ABBILD'),
                          $alter < 0 ? bl_t('TEST.NICHT_VORHANDEN')
                                     : sprintf(bl_t('TEST.SEKUNDEN_ALT'), $alter));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_EMPFANG'),
                          $stille < 0 ? bl_t('TEST.NICHT_VORHANDEN')
                                      : sprintf(bl_t('TEST.VOR_SEKUNDEN'), $stille));
            $t .= sprintf("%-22s %d / %d\n", bl_t('TEST.F_TAGS'), $aktiv, count($tags));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_ADAPTER'), bl_cfg($cfg, 'adapter', 'hci0'));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_MQTT'),
                          bl_cfg($cfg, 'mqtt', '1') === '1' ? bl_t('TEXT.EIN') : bl_t('TEXT.AUS'));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_HTTP'),
                          bl_cfg($cfg, 'http_push', '0') === '1' ? bl_t('TEXT.EIN') : bl_t('TEXT.AUS'));
            $t .= "\n";
            if ($alt) {
                $t .= bl_t('TEST.ALTES_FORMAT') . "\n\n";
            }
            if (!$pid) {
                $t .= bl_t('TEST.DIENST_TOT') . "\n\n";
            } elseif ($stille >= 0 && $stille > max(60, (int) bl_cfg($cfg, 'wachhund_stille', '300'))) {
                $t .= sprintf(bl_t('TEST.KEIN_EMPFANG'), $stille) . "\n\n";
            }
            $t .= bl_sh('ps -o pid,etime,rss,args -C python3 2>/dev/null | grep -iE "ble_scanner_ng|PID"');
            return array(bl_t('TEST.T_STATUS'), trim($t) !== '' ? $t : bl_t('TEST.KEINE_ANGABEN'));

        case 'sichtbar':
            $s = bl_status();
            if (!$s) {
                return array(bl_t('TEST.T_SICHTBAR'), bl_t('TEST.KEIN_ABBILD'));
            }
            $t = sprintf(bl_t('TEST.STAND_VOR'), bl_status_alter()) . "\n\n";
            $t .= sprintf("%-19s %6s %6s  %-11s %-22s %s\n", 'MAC', 'RSSI', 'Ø',
                          bl_t('TEST.SP_ADRESSE'), bl_t('TEST.SP_NAME'), bl_t('TEST.SP_ZULETZT'));
            $t .= str_repeat('-', 84) . "\n";
            foreach (($s['sichtbar'] ?? array()) as $g) {
                $t .= sprintf("%-19s %6s %6s  %-11s %-22s %s\n",
                    $g['mac'],
                    $g['rssi'] === null ? '-' : $g['rssi'],
                    isset($g['rssi_avg']) && $g['rssi_avg'] !== null ? $g['rssi_avg'] : '-',
                    bl_t('ADRESSTYP.' . strtoupper($g['adresstyp'] ?? 'unbekannt')),
                    bl_kuerzen((string) ($g['name'] ?? ''), 22),
                    sprintf(bl_t('TEST.VOR_SEKUNDEN'), (int) $g['seit']));
            }
            if (!($s['sichtbar'] ?? array())) {
                $t .= bl_t('TEST.NICHTS_GESEHEN');
            }
            return array(bl_t('TEST.T_SICHTBAR'), $t);

        case 'tags':
            $s = bl_status();
            if (!$tags) {
                return array(bl_t('TEST.T_TAGS'), bl_t('TEST.KEIN_TAG'));
            }
            $t = sprintf("%-24s %-20s %-6s %5s %6s %5s %s\n",
                bl_t('TEST.SP_KENNUNG'), bl_t('TEST.SP_NAME'), bl_t('TEST.SP_AKTIV'),
                bl_t('TEST.SP_DA'), 'RSSI', bl_t('TEST.SP_STUFE'), bl_t('TEST.SP_ZULETZT'));
            $t .= str_repeat('-', 92) . "\n";
            $zustand = bl_zustaende();
            foreach ($tags as $tag) {
                $z = $zustand[$tag['kennung']] ?? null;
                $t .= sprintf("%-24s %-20s %-6s %5s %6s %5s %s\n",
                    bl_kuerzen($tag['kennung'], 24),
                    bl_kuerzen($tag['name'], 20),
                    $tag['aktiv'] === '1' ? bl_t('TEXT.JA') : bl_t('TEXT.NEIN'),
                    $z ? ($z['anwesend'] ? bl_t('TEXT.JA') : bl_t('TEXT.NEIN')) : '?',
                    $z && $z['rssi'] !== null ? $z['rssi'] : '-',
                    $z ? $z['stufe'] : '-',
                    $z && !empty($z['zuletzt']) ? date('d.m. H:i:s', (int) $z['zuletzt']) : '-');
            }
            if (!$s) {
                $t .= "\n" . bl_t('TEST.KEIN_ABBILD_KURZ');
            }
            return array(bl_t('TEST.T_TAGS'), $t);

        case 'themen':
            $praefix = bl_cfg($cfg, 'themenpraefix', 'blescanner');
            $t = bl_t('TEST.THEMEN_KOPF') . "\n\n";
            foreach (bl_allgemeine_themen() as $thema => $info) {
                $t .= sprintf("  %s/%-26s %s\n", $praefix, $thema, bl_t($info['s']));
            }
            $alle = array_merge(bl_status_themen(), bl_zusatzthemen($cfg));
            $aktive = 0;
            foreach ($tags as $tag) {
                if ($tag['aktiv'] !== '1') { continue; }
                $aktive++;
                $th = bl_thema($tag);
                $t .= "\n  " . $tag['kennung'] . ($tag['name'] !== '' ? '  (' . $tag['name'] . ')' : '') . "\n";
                foreach ($alle as $k => $info) {
                    $t .= sprintf("    %s/%s/%-14s %s\n", $praefix, $th, $k, bl_t($info['s']));
                }
                $person = $tag['opt']['person'] ?? '';
                if ($person !== '') {
                    $t .= sprintf("    %s/person/%s/present\n", $praefix, bl_saeubern($person));
                }
            }
            if (!$aktive) {
                $t .= "\n" . bl_t('TEST.KEIN_AKTIVER_TAG');
            }
            return array(bl_t('TEST.T_THEMEN'), $t);

        case 'verlauf':
            $v = bl_verlauf_lesen(24);
            if (!$v['vorhanden']) {
                return array(bl_t('TEST.T_VERLAUF'), bl_t('TEST.KEIN_VERLAUF'));
            }
            $t = bl_t('TEST.VERLAUF_KOPF') . "\n\n";
            $t .= sprintf("%-20s %6s %6s %10s %s\n", bl_t('TEST.SP_NAME'),
                          bl_t('TEST.SP_KOMMT'), bl_t('TEST.SP_GEHT'),
                          bl_t('TEST.SP_LUECKE'), bl_t('TEST.SP_EMPFEHLUNG'));
            $t .= str_repeat('-', 78) . "\n";
            foreach ($v['je_zweig'] as $zweig => $s) {
                $empf = $s['luecke_max'] > 0
                    ? sprintf(bl_t('TEST.EMPFEHLUNG'), max(30, (int) ceil($s['luecke_max'] * 2 / 10) * 10))
                    : bl_t('TEST.ZU_WENIG_DATEN');
                $t .= sprintf("%-20s %6d %6d %8d s %s\n",
                              bl_kuerzen($s['name'] !== '' ? $s['name'] : $zweig, 20),
                              $s['kommt'], $s['geht'], $s['luecke_max'], $empf);
            }
            $t .= "\n" . sprintf(bl_t('TEST.VERLAUF_ZEILEN'), count($v['zeilen'])) . "\n\n";
            foreach (array_slice(array_reverse($v['zeilen']), 0, 40) as $z) {
                $t .= sprintf("  %s  %-20s %-6s %s\n", date('d.m. H:i:s', $z['zeit']),
                              bl_kuerzen($z['name'], 20), $z['ereignis'],
                              $z['rssi'] === null ? '' : $z['rssi'] . ' dBm');
            }
            return array(bl_t('TEST.T_VERLAUF'), $t);

        case 'bluetooth':
            $adapter = bl_cfg($cfg, 'adapter', 'hci0');
            $t = sprintf(bl_t('TEST.BT_GESUCHT'), $adapter) . "\n\n";
            $t .= "--- bluetoothctl list ---\n" . bl_sh('timeout 8 bluetoothctl list') . "\n\n";
            $t .= "--- bluetoothctl show ---\n" . bl_sh('timeout 8 bluetoothctl show') . "\n\n";
            $t .= "--- bluetooth.service ---\n"
                . bl_sh('systemctl is-active bluetooth 2>/dev/null; systemctl is-enabled bluetooth 2>/dev/null') . "\n\n";
            $t .= "--- dmesg | grep -i blue ---\n"
                . bl_sh('dmesg 2>/dev/null | grep -i blue | tail -8') . "\n\n";
            $t .= "--- id loxberry ---\n" . bl_sh('id loxberry') . "\n";
            $t .= "\n" . bl_t('TEST.BT_HINWEIS');
            return array(bl_t('TEST.T_BLUETOOTH'), $t);

        case 'konfig':
            $t = bl_t('TEST.F_DATEI') . ': ' . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
                $t .= "\n" . sprintf(bl_t('TEST.RECHTE'),
                                     substr(sprintf('%o', @fileperms($p['config'])), -4));
            } else {
                $t .= bl_t('TEST.KEINE_DATEI') . "\n\n";
                foreach (bl_defaults() as $k => $v) {
                    $t .= $k . '=' . $v . "\n";
                }
            }
            return array(bl_t('TEST.T_KONFIG'), $t);

        case 'umgebung':
            $t  = sprintf("%-14s %s\n", 'PHP', PHP_VERSION);
            $t .= sprintf("%-14s %s\n", 'LBHOMEDIR', $p['home'] !== '' ? $p['home'] : '-');
            $t .= sprintf("%-14s %s\n", 'Plugin', $p['plugin']);
            $t .= sprintf("%-14s %s\n", 'bin', $p['bindir']);
            $t .= sprintf("%-14s %s\n", 'log', $p['logdir']);
            $t .= sprintf("%-14s %s\n", 'data', $p['datadir']);
            $t .= sprintf("%-14s %s\n", 'config', $p['config']);
            $t .= sprintf("%-14s %s\n", 'status', $p['status']);
            $t .= "\n" . bl_sh('python3 --version') . "\n\n";
            $t .= bl_t('TEST.MODULE') . "\n";
            foreach (array('dbus', 'gi', 'paho.mqtt.client') as $m) {
                $r = bl_sh('python3 -c ' . escapeshellarg('import ' . $m));
                $t .= sprintf("  %-20s %s\n", $m,
                              $r === '' ? bl_t('TEST.VORHANDEN') : bl_t('TEST.FEHLT'));
            }
            $t .= "\n" . bl_t('TEST.WERKZEUGE') . "\n";
            foreach (array('bluetoothctl', 'python3', 'timeout') as $w) {
                $t .= sprintf("  %-20s %s\n", $w,
                              trim(bl_sh('command -v ' . escapeshellarg($w))) ?: bl_t('TEST.FEHLT'));
            }
            $t .= "\n" . bl_t('TEST.NACHINSTALLIEREN');
            return array(bl_t('TEST.T_UMGEBUNG'), $t);

        case 'mqttinfo':
            $broker = bl_mqtt_broker();
            $auto = bl_mqtt_autostart();
            $s = bl_status();
            $t  = sprintf("%-22s %s\n", bl_t('TEST.F_BROKER'),
                          $broker !== '' ? $broker : bl_t('TEST.NICHT_GEFUNDEN'));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_AUTOSTART'),
                          $auto === null ? '?' : ($auto ? bl_t('TEXT.EIN') : bl_t('TEXT.AUS')));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_MQTT'),
                          bl_cfg($cfg, 'mqtt', '1') === '1' ? bl_t('TEXT.EIN') : bl_t('TEXT.AUS'));
            $t .= sprintf("%-22s %s\n", bl_t('TEST.F_PRAEFIX'),
                          bl_cfg($cfg, 'themenpraefix', 'blescanner'));
            if ($s) {
                $t .= sprintf("%-22s %s\n", bl_t('TEST.F_VERBUNDEN'),
                              ((int) ($s['mqtt_verbunden'] ?? 0)) === 1 ? bl_t('TEXT.JA') : bl_t('TEXT.NEIN'));
                $t .= sprintf("%-22s %d\n", bl_t('TEST.F_GESENDET'), (int) ($s['mqtt_gesendet'] ?? 0));
                $t .= sprintf("%-22s %d\n", bl_t('TEST.F_VERLUSTE'), (int) ($s['mqtt_verluste'] ?? 0));
                if (!empty($s['themen'])) {
                    $t .= "\n" . bl_t('TEST.THEMEN_LIVE') . "\n";
                    foreach ($s['themen'] as $th) {
                        $t .= '  ' . bl_cfg($cfg, 'themenpraefix', 'blescanner') . '/' . $th . "\n";
                    }
                }
            }
            if ($broker === '') {
                $t .= "\n" . bl_t('TEST.KEIN_GATEWAY');
            }
            $t .= "\n" . sprintf(bl_t('TEST.MITLESEN'), bl_cfg($cfg, 'themenpraefix', 'blescanner'));
            return array(bl_t('TEST.T_MQTT'), $t);

        case 'probewert':
            $ms = bl_miniserver();
            if (!$ms) {
                return array(bl_t('TEST.T_PROBEWERT'), bl_t('TEST.KEIN_MINISERVER'));
            }
            $kennung = trim((string) bl_cfg($cfg, 'loxberry_id', ''));
            $name = $kennung . 'BLE_SELBSTTEST';
            $t = '';
            foreach ($ms as $m) {
                $url = 'http://' . $m['adresse'] . ':' . $m['port'] . '/dev/sps/io/'
                     . rawurlencode($name) . '/1';
                $t .= $m['name'] . ' (' . $m['adresse'] . ':' . $m['port'] . ")\n";
                $t .= '  ' . $url . "\n";
                $kopf = "User-Agent: LoxBerry-BLE-Scanner-NG/" . bl_fassung() . "\r\nAccept: */*\r\n";
                if ($m['user'] !== '') {
                    $kopf .= 'Authorization: Basic '
                           . base64_encode($m['user'] . ':' . $m['pass']) . "\r\n";
                }
                $ctx = stream_context_create(array('http' => array(
                    'method' => 'GET', 'header' => $kopf, 'timeout' => 5,
                    'ignore_errors' => true)));
                $antwort = @file_get_contents($url, false, $ctx);
                $kopfzeile = isset($http_response_header[0]) ? $http_response_header[0] : '';
                if ($antwort === false && $kopfzeile === '') {
                    $t .= '  ' . bl_t('TEST.PROBE_KEINE_ANTWORT') . "\n\n";
                } else {
                    $t .= '  ' . $kopfzeile . "\n";
                    $t .= '  ' . bl_kuerzen(trim((string) $antwort), 200) . "\n\n";
                }
            }
            $t .= sprintf(bl_t('TEST.PROBE_HINWEIS'), $name);
            return array(bl_t('TEST.T_PROBEWERT'), $t);

        case 'testmodus':
            $kennung = trim((string) $zusatz);
            if ($kennung === '') {
                return array(bl_t('TEST.T_TESTMODUS'), bl_t('TEST.TESTMODUS_OHNE_TAG'));
            }
            $ok = bl_steuern('testmodus', $kennung, 60);
            return array(bl_t('TEST.T_TESTMODUS'),
                         $ok ? sprintf(bl_t('TEST.TESTMODUS_LAEUFT'), $kennung)
                             : bl_t('TEST.STEUER_FEHLER'));

        case 'kalibrieren':
            $kennung = trim((string) $zusatz);
            if ($kennung === '') {
                return array(bl_t('TEST.T_KALIBRIEREN'), bl_t('TEST.TESTMODUS_OHNE_TAG'));
            }
            $ok = bl_steuern('kalibrierung', $kennung, 10);
            return array(bl_t('TEST.T_KALIBRIEREN'),
                         $ok ? bl_t('TEST.KALIBRIERUNG_LAEUFT') : bl_t('TEST.STEUER_FEHLER'));

        case 'batterie':
            $ok = bl_steuern('batterie');
            return array(bl_t('TEST.T_BATTERIE'),
                         $ok ? bl_t('TEST.BATTERIE_ANGEFORDERT') : bl_t('TEST.STEUER_FEHLER'));

        case 'start':
            $a = bl_dienst('start');
            $pid = bl_dienst_pid();
            return array(bl_t('TEST.T_START'),
                         ($a !== '' ? $a . "\n\n" : '')
                         . ($pid ? sprintf(bl_t('TEST.JETZT_PID'), $pid)
                                 : bl_t('TEST.START_FEHLGESCHLAGEN')));

        case 'restart':
            $a = bl_dienst('restart');
            $pid = bl_dienst_pid();
            return array(bl_t('TEST.T_RESTART'),
                         ($a !== '' ? $a . "\n\n" : '')
                         . ($pid ? sprintf(bl_t('TEST.JETZT_PID'), $pid)
                                 : bl_t('TEST.START_FEHLGESCHLAGEN')));

        case 'stop':
            $a = bl_dienst('stop');
            return array(bl_t('TEST.T_STOP'),
                         ($a !== '' ? $a . "\n\n" : '')
                         . (bl_dienst_pid() ? bl_t('TEST.STOP_LAEUFT_NOCH')
                                            : bl_t('TEST.STOP_OK')));
    }

    return array(bl_t('TEST.T_UNBEKANNT'), bl_t('TEST.UNBEKANNTE_AKTION'));
}
