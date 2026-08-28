<?php
/**
 * BLE-Scanner NG - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Verlauf | Test | Logdateien
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * Bis 1.2.10 waren es vier Reiter, und MQTT stand mitten im
 * Einstellungsformular. Der Hausstandard verlangt einen EIGENEN Reiter mit
 * eigenem Formular und eigenem Speicher-Handler - sonst schaltet ein
 * Sammel-Handler mit isset() beim Absenden des einen Formulars die Werte
 * des anderen stillschweigend ab.
 *
 * Ebenfalls neu: Beanstandungen werden GESAMMELT und angezeigt. Bis 1.2.10
 * fiel ein Wert ausserhalb der Grenzen stillschweigend auf die VORGABE
 * zurueck - wer 300 stehen hatte und 700 tippte, hatte danach 5.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once __DIR__ . '/bl_lib.php';

$bl_p = bl_paths();
$bl_home = (string) $bl_p['home'];
if ($bl_home !== '' && file_exists($bl_home . '/libs/phplib/loxberry_system.php')) {
    require_once $bl_home . '/libs/phplib/loxberry_system.php';
    require_once $bl_home . '/libs/phplib/loxberry_web.php';
    $bl_p = bl_paths();
}

$bl_saved   = false;
$bl_hinweis = '';
$bl_error   = '';
$bl_mangel  = array();   // gesammelte Beanstandungen

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
$bl_wache = bl_wachposten();
if ($bl_wache !== '') {
    $bl_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($bl_reiter_merk !== null) {
        $_POST['activetab'] = $bl_reiter_merk;
    }
    $bl_mangel[] = $bl_wache;
}

$bl_such    = null;
$bl_test_titel = '';
$bl_test_text  = '';

/* ================= Reiter: EINE ausgeschriebene Liste =================
 * Ausgeschrieben, nicht gerechnet: hausstandard_pruefen.py sucht die
 * Positivliste als Literal. Dass sie damit von Leiste und Bereichs-ids
 * abweichen KANN, faengt die Pruefzeile "Reiter" im Reiter Test ab. */
$bl_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-verlauf',
                   'tab-test', 'tab-log');
$bl_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $bl_reiter, true)) {
    $bl_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && in_array('tab-' . (string) $_GET['form'], $bl_reiter, true)) {
    $bl_tab = 'tab-' . (string) $_GET['form'];
}

list($bl_cfg, $bl_tags, $bl_altformat) = bl_config_read();
$bl_ist_post = ($_SERVER['REQUEST_METHOD'] === 'POST');

/* ================= Hilfen fuer die Eingabe ================= */

/** Steuerzeichen und Anfuehrungszeichen raus - sonst nichts. */
function bl_saubere_eingabe($s)
{
    $s = preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s);
    return trim($s);
}

/**
 * Eine Zahl pruefen. Was nicht passt, wird BEANSTANDET und der BISHERIGE
 * Wert behalten - nicht die Vorgabe.
 *
 * Bis 1.2.10 gab diese Stelle bei jedem Verstoss die Vorgabe zurueck, ohne
 * ein Wort. Gemessen: intervall=99999 wurde zu 5, abwesenheit_nach="abc" zu
 * 30, aktualisierung=3 zu 60 - und die Oberflaeche meldete "Gespeichert".
 */
function bl_zahl($roh, $bisher, $min, $max, $feld, &$mangel)
{
    $roh = trim((string) $roh);
    if ($roh === '' || !is_numeric($roh)) {
        $mangel[] = sprintf(bl_t('MANGEL.KEINE_ZAHL'), $feld, $roh, $bisher);
        return (string) $bisher;
    }
    $n = (int) $roh;
    if ($n < $min || $n > $max) {
        $mangel[] = sprintf(bl_t('MANGEL.AUSSERHALB'), $feld, $n, $min, $max, $bisher);
        return (string) $bisher;
    }
    return (string) $n;
}

function bl_komma($roh, $bisher, $min, $max, $feld, &$mangel)
{
    $roh = str_replace(',', '.', trim((string) $roh));
    if ($roh === '' || !is_numeric($roh)) {
        $mangel[] = sprintf(bl_t('MANGEL.KEINE_ZAHL'), $feld, $roh, $bisher);
        return (string) $bisher;
    }
    $n = (float) $roh;
    if ($n < $min || $n > $max) {
        $mangel[] = sprintf(bl_t('MANGEL.AUSSERHALB'), $feld, $n, $min, $max, $bisher);
        return (string) $bisher;
    }
    return rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
}

/**
 * Die Tag-Zeilen aus dem Formular einsammeln.
 *
 * Dieselbe Funktion benutzen der Speichern- UND der Suchlauf-Zweig. Bis
 * 1.2.10 las der Suchlauf sie gar nicht: er sass im selben Formular, bekam
 * die getippten Werte also mitgeschickt - und warf sie weg. Wer eine
 * Bezeichnung eintrug und dann auf "Geräte suchen" drueckte, fand sie
 * danach nicht wieder, ohne jeden Hinweis.
 */
function bl_tags_aus_post(&$mangel)
{
    $tags = array();
    $gesehen = array();
    $kenn  = isset($_POST['tag_kennung']) && is_array($_POST['tag_kennung']) ? $_POST['tag_kennung'] : array();
    $namen = isset($_POST['tag_name'])    && is_array($_POST['tag_name'])    ? $_POST['tag_name']    : array();
    $aktiv = isset($_POST['tag_aktiv'])   && is_array($_POST['tag_aktiv'])   ? $_POST['tag_aktiv']   : array();
    $weg   = isset($_POST['tag_weg'])     && is_array($_POST['tag_weg'])     ? $_POST['tag_weg']     : array();
    $erlaubt = bl_tag_optionen();

    foreach ($kenn as $i => $roh) {
        $roh = bl_saubere_eingabe($roh);
        if ($roh === '') {
            continue;                      // leere Zeile: nichts eingetragen
        }
        if (!empty($weg[$i])) {
            continue;                      // ausdruecklich zum Entfernen angehakt
        }
        list($art, $kennung) = bl_kennung($roh);
        if ($art === '') {
            // ABWEISEN und melden, nicht stillschweigend verwerfen. Bis
            // 1.2.10 verschwand eine unbrauchbare Adresse spurlos, und die
            // Oberflaeche meldete trotzdem "Gespeichert".
            $mangel[] = sprintf(bl_t('MANGEL.KENNUNG'), $roh);
            continue;
        }
        if (isset($gesehen[$kennung])) {
            $mangel[] = sprintf(bl_t('MANGEL.DOPPELT'), $kennung);
            continue;
        }
        $gesehen[$kennung] = true;
        $opt = array();
        foreach ($erlaubt as $k) {
            $wert = isset($_POST['tag_' . $k][$i]) ? bl_saubere_eingabe($_POST['tag_' . $k][$i]) : '';
            if ($wert !== '') {
                $opt[$k] = $wert;
            }
        }
        if (isset($opt['abw']) && (!ctype_digit($opt['abw']) || (int) $opt['abw'] < 5 || (int) $opt['abw'] > 3600)) {
            $mangel[] = sprintf(bl_t('MANGEL.TAG_ABW'), $kennung, $opt['abw']);
            unset($opt['abw']);
        }
        if (isset($opt['ref']) && (!is_numeric($opt['ref']) || (int) $opt['ref'] > 0 || (int) $opt['ref'] < -120)) {
            $mangel[] = sprintf(bl_t('MANGEL.TAG_REF'), $kennung, $opt['ref']);
            unset($opt['ref']);
        }
        if (isset($opt['batt'])) {
            $opt['batt'] = ($opt['batt'] === '1') ? '1' : '';
            if ($opt['batt'] === '') { unset($opt['batt']); }
        }
        $tags[] = array(
            'art' => $art,
            'kennung' => $kennung,
            'mac' => $art === 'mac' ? $kennung : '',
            'aktiv' => !empty($aktiv[$i]) ? '1' : '0',
            'name' => bl_saubere_eingabe(isset($namen[$i]) ? $namen[$i] : ''),
            'opt' => $opt,
        );
    }

    // Aus dem Suchlauf uebernommene Geraete
    $neue = isset($_POST['neu_kennung']) && is_array($_POST['neu_kennung']) ? $_POST['neu_kennung'] : array();
    $neue_namen = isset($_POST['neu_name']) && is_array($_POST['neu_name']) ? $_POST['neu_name'] : array();
    foreach ($neue as $roh => $_an) {
        list($art, $kennung) = bl_kennung(bl_saubere_eingabe($roh));
        if ($art === '' || isset($gesehen[$kennung])) {
            continue;
        }
        $gesehen[$kennung] = true;
        $tags[] = array(
            'art' => $art, 'kennung' => $kennung,
            'mac' => $art === 'mac' ? $kennung : '',
            'aktiv' => '1',
            'name' => bl_saubere_eingabe(isset($neue_namen[$roh]) ? $neue_namen[$roh] : ''),
            'opt' => array(),
        );
    }
    return $tags;
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ============ Loxone-Vorlage herunterladen ============ */
if ($bl_ist_post && isset($_POST['download'])) {
    $aktive = array_filter($bl_tags, function ($t) { return $t['aktiv'] === '1'; });
    if (!$aktive) {
        $bl_error = bl_t('TEXT.VORLAGE_OHNE_TAG');
        $bl_tab = 'tab-loxone';
    } else {
        list($name, $inhalt, $anzahl, $weg) = bl_vorlage($bl_cfg, $bl_tags);
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($inhalt));
        echo $inhalt;
        exit;
    }
}

/* ============ Verlauf herunterladen ============ */
if ($bl_ist_post && isset($_POST['verlauf_download'])) {
    $datei = $bl_p['verlauf'];
    if (is_file($datei)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ble_scanner_ng_verlauf.csv"');
        header('Content-Length: ' . (string) filesize($datei));
        readfile($datei);
        exit;
    }
    $bl_error = bl_t('TEXT.KEIN_VERLAUF');
    $bl_tab = 'tab-verlauf';
}

/* ============ Suchlauf ============ */
if ($bl_ist_post && isset($_POST['suchen'])) {
    // Die getippten Zeilen NICHT verlieren.
    $eingetippt = bl_tags_aus_post($bl_mangel);
    if ($eingetippt) {
        $bl_tags = $eingetippt;
    }
    $skript = $bl_p['bindir'] . '/bl_discover.py';
    if (!is_file($skript)) {
        $bl_error = sprintf(bl_t('TEXT.DISCOVER_FEHLT'), bl_e($skript));
    } else {
        $out = array();
        @exec('timeout 40 python3 ' . escapeshellarg($skript) . ' 2>&1', $out);
        $roh = trim(implode("\n", $out));
        $bl_such = @json_decode($roh, true);
        if (!is_array($bl_such)) {
            $bl_error = bl_t('TEXT.SUCHLAUF_UNVERSTAENDLICH') . ' '
                      . bl_e(bl_kuerzen($roh, 400));
            $bl_such = null;
        } elseif (isset($bl_such['fehler'])) {
            $bl_error = bl_e($bl_such['fehler']) . ' &mdash; '
                      . bl_e(isset($bl_such['hinweis']) ? $bl_such['hinweis'] : '');
            $bl_such = null;
        }
    }
    $bl_tab = 'tab-settings';
}

/* ============ Test-Aktionen ============ */
if ($bl_ist_post && isset($_POST['test'])) {
    require_once __DIR__ . '/bl_test.php';
    $zusatz = isset($_POST['testtag']) ? (string) $_POST['testtag'] : '';
    list($bl_test_titel, $bl_test_text) = bl_test_ausfuehren((string) $_POST['test'], $zusatz);
    $bl_tab = 'tab-test';
    list($bl_cfg, $bl_tags, $bl_altformat) = bl_config_read();
}

/* ============ Speichern: Einstellungen ============
 * Fasst MQTT-Werte NICHT an. Sie stehen im eigenen Reiter mit eigenem
 * Handler; hier werden sie aus dem Bestand uebernommen. */
if ($bl_ist_post && isset($_POST['save'])) {
    $neu = $bl_cfg;

    $adapter = bl_saubere_eingabe(isset($_POST['adapter']) ? $_POST['adapter'] : 'hci0');
    if (preg_match('/^hci[0-9]+$/', $adapter)) {
        $neu['adapter'] = $adapter;
    } else {
        $bl_mangel[] = sprintf(bl_t('MANGEL.ADAPTER'), $adapter, $bl_cfg['adapter']);
    }

    $betriebsart = isset($_POST['betriebsart']) ? (string) $_POST['betriebsart'] : '';
    if (in_array($betriebsart, array('signal', 'abfrage'), true)) {
        $neu['betriebsart'] = $betriebsart;
    } else {
        $bl_mangel[] = sprintf(bl_t('MANGEL.BETRIEBSART'), $betriebsart);
    }

    $neu['http_push']   = isset($_POST['http_push']) ? '1' : '0';
    $neu['loxberry_id'] = bl_saubere_eingabe(isset($_POST['loxberry_id']) ? $_POST['loxberry_id'] : '');

    $neu['intervall']          = bl_zahl($_POST['intervall'] ?? '', $bl_cfg['intervall'], 2, 600, bl_t('FELD.INTERVALL'), $bl_mangel);
    $neu['abwesenheit_nach']   = bl_zahl($_POST['abwesenheit_nach'] ?? '', $bl_cfg['abwesenheit_nach'], 5, 3600, bl_t('FELD.ABWESEND'), $bl_mangel);
    $neu['aktualisierung']     = bl_zahl($_POST['aktualisierung'] ?? '', $bl_cfg['aktualisierung'], 5, 86400, bl_t('FELD.AKTUALISIERUNG'), $bl_mangel);
    $neu['rssi_nah']           = bl_zahl($_POST['rssi_nah'] ?? '', $bl_cfg['rssi_nah'], -120, 0, bl_t('FELD.RSSI_NAH'), $bl_mangel);
    $neu['rssi_mittel']        = bl_zahl($_POST['rssi_mittel'] ?? '', $bl_cfg['rssi_mittel'], -120, 0, bl_t('FELD.RSSI_MITTEL'), $bl_mangel);
    $neu['rssi_minimum']       = bl_zahl($_POST['rssi_minimum'] ?? '', $bl_cfg['rssi_minimum'], -120, 0, bl_t('FELD.RSSI_MINIMUM'), $bl_mangel);
    $neu['ankunft_sichtungen'] = bl_zahl($_POST['ankunft_sichtungen'] ?? '', $bl_cfg['ankunft_sichtungen'], 1, 20, bl_t('FELD.ANKUNFT'), $bl_mangel);
    $neu['glaettung']          = isset($_POST['glaettung']) ? '1' : '0';
    $neu['glaettung_fenster']  = bl_zahl($_POST['glaettung_fenster'] ?? '', $bl_cfg['glaettung_fenster'], 1, 30, bl_t('FELD.FENSTER'), $bl_mangel);
    $neu['hysterese_db']       = bl_zahl($_POST['hysterese_db'] ?? '', $bl_cfg['hysterese_db'], 0, 20, bl_t('FELD.HYSTERESE'), $bl_mangel);
    $neu['wachhund']           = isset($_POST['wachhund']) ? '1' : '0';
    $neu['wachhund_stille']    = bl_zahl($_POST['wachhund_stille'] ?? '', $bl_cfg['wachhund_stille'], 60, 86400, bl_t('FELD.STILLE'), $bl_mangel);
    $neu['discovery_rssi']     = bl_zahl($_POST['discovery_rssi'] ?? '', $bl_cfg['discovery_rssi'], -120, 0, bl_t('FELD.DISCOVERY_RSSI'), $bl_mangel);
    $neu['log_kappung_kb']     = bl_zahl($_POST['log_kappung_kb'] ?? '', $bl_cfg['log_kappung_kb'], 16, 20000, bl_t('FELD.LOGKAPPUNG'), $bl_mangel);
    $neu['ereignisse']         = isset($_POST['ereignisse']) ? '1' : '0';
    $neu['ereignisse_tage']    = bl_zahl($_POST['ereignisse_tage'] ?? '', $bl_cfg['ereignisse_tage'], 1, 365, bl_t('FELD.EREIGNISTAGE'), $bl_mangel);
    $neu['entfernung']         = isset($_POST['entfernung']) ? '1' : '0';
    $neu['daempfung']          = bl_komma($_POST['daempfung'] ?? '', $bl_cfg['daempfung'], 1.5, 6.0, bl_t('FELD.DAEMPFUNG'), $bl_mangel);
    $neu['beacon']             = isset($_POST['beacon']) ? '1' : '0';
    $neu['batterie']           = isset($_POST['batterie']) ? '1' : '0';
    $neu['scanner_themen']     = isset($_POST['scanner_themen']) ? '1' : '0';
    $neu['raum']               = isset($_POST['raum']) ? '1' : '0';
    $neu['raum_hysterese_db']  = bl_zahl($_POST['raum_hysterese_db'] ?? '', $bl_cfg['raum_hysterese_db'], 0, 30, bl_t('FELD.RAUM_HYST'), $bl_mangel);
    $neu['raum_ausgleich_db']  = bl_zahl($_POST['raum_ausgleich_db'] ?? '', $bl_cfg['raum_ausgleich_db'], -30, 30, bl_t('FELD.RAUM_AUSGLEICH'), $bl_mangel);

    $uhr = bl_saubere_eingabe($_POST['batterie_uhrzeit'] ?? '');
    if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $uhr)) {
        $neu['batterie_uhrzeit'] = $uhr;
    } else {
        $bl_mangel[] = sprintf(bl_t('MANGEL.UHRZEIT'), $uhr, $bl_cfg['batterie_uhrzeit']);
    }

    $sname = bl_saubere_eingabe($_POST['scanner_name'] ?? '');
    if ($sname === '' || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $sname)) {
        $neu['scanner_name'] = $sname;
    } else {
        $bl_mangel[] = sprintf(bl_t('MANGEL.SCANNERNAME'), $sname);
    }

    if ((int) $neu['rssi_mittel'] > (int) $neu['rssi_nah']) {
        // Vertauscht eingegeben. Gedreht wird es - aber es wird auch GESAGT.
        $tausch = $neu['rssi_nah'];
        $neu['rssi_nah'] = $neu['rssi_mittel'];
        $neu['rssi_mittel'] = $tausch;
        $bl_mangel[] = bl_t('MANGEL.SCHWELLEN_GEDREHT');
    }

    $tags = bl_tags_aus_post($bl_mangel);

    if (bl_config_write($neu, $tags)) {
        $bl_saved = true;
        require_once __DIR__ . '/bl_test.php';
        // Neu starten nur, wenn der Dienst auch lief. Bis 1.2.10 startete
        // jedes Speichern ihn ungefragt - auch wenn er bewusst angehalten war.
        if (bl_dienst_pid() > 0) {
            bl_dienst('restart');
            $bl_hinweis = bl_dienst_pid() ? bl_t('TEXT.DIENST_NEU_GESTARTET')
                                          : bl_t('TEXT.DIENST_LAEUFT_NICHT');
        } else {
            $bl_hinweis = bl_t('TEXT.DIENST_WAR_AUS');
        }
        list($bl_cfg, $bl_tags, $bl_altformat) = bl_config_read();
    } else {
        $bl_error = sprintf(bl_t('TEXT.SCHREIBFEHLER'), bl_e($bl_p['config']));
    }
    $bl_tab = 'tab-settings';
}

/* ============ Speichern: MQTT (eigener Handler) ============
 * Laedt den Bestand und fasst AUSSCHLIESSLICH die MQTT-Werte an. */
if ($bl_ist_post && isset($_POST['save_mqtt'])) {
    list($neu, $bestand_tags, ) = bl_config_read();
    $neu['mqtt'] = isset($_POST['mqtt']) ? '1' : '0';
    $praefix = bl_saubere_eingabe($_POST['themenpraefix'] ?? '');
    if ($praefix === '') {
        $bl_mangel[] = bl_t('MANGEL.PRAEFIX_LEER');
    } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $praefix)) {
        // ABWEISEN, nicht filtern. Bis 1.2.10 wurde hier hart gefiltert:
        // aus "haus/keller etage" wurde "hauskelleretage", und das landete
        // danach in jeder angezeigten Adresse und im MQTT-Abo.
        $bl_mangel[] = sprintf(bl_t('MANGEL.PRAEFIX'), $praefix, $neu['themenpraefix']);
    } else {
        $neu['themenpraefix'] = $praefix;
    }
    if (bl_config_write($neu, $bestand_tags)) {
        $bl_saved = true;
        require_once __DIR__ . '/bl_test.php';
        if (bl_dienst_pid() > 0) {
            bl_dienst('restart');
            $bl_hinweis = bl_t('TEXT.DIENST_NEU_GESTARTET');
        } else {
            $bl_hinweis = bl_t('TEXT.DIENST_WAR_AUS');
        }
        list($bl_cfg, $bl_tags, $bl_altformat) = bl_config_read();
    } else {
        $bl_error = sprintf(bl_t('TEXT.SCHREIBFEHLER'), bl_e($bl_p['config']));
    }
    $bl_tab = 'tab-mqtt';
}

/* ================= Anzeigedaten ================= */
$bl_praefix = bl_cfg($bl_cfg, 'themenpraefix', 'blescanner');
$bl_pid     = bl_dienst_pid();
$bl_status  = bl_status();
$bl_alter   = bl_status_alter();
$bl_stille  = bl_stille();
$bl_broker  = bl_mqtt_broker();
$bl_autostart = bl_mqtt_autostart();
$bl_log     = bl_log_file();
$bl_aktiv   = 0;
foreach ($bl_tags as $t) { if ($t['aktiv'] === '1') { $bl_aktiv++; } }
$bl_zustand = bl_zustaende();
$bl_themen_tag = array_merge(bl_status_themen(), bl_zusatzthemen($bl_cfg));
$bl_verlauf = bl_verlauf_lesen(24);


/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($bl_ist_post && isset($_POST['bl_sichern'])) {
    $bl_js = json_encode(bl_cfg(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($bl_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ble_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $bl_js;
        exit;
    }
    $bl_error = bl_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($bl_ist_post && isset($_POST['bl_zurueck'])) {
    if (!isset($_FILES['bl_sicherung']) || !is_array($_FILES['bl_sicherung'])
        || !isset($_FILES['bl_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['bl_sicherung']['tmp_name'])) {
        $bl_error = bl_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['bl_sicherung']['size'] > 262144) {
        $bl_error = bl_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($bl_neu, $bl_mangel, $bl_n) = bl_sicherung_lesen(
            (string) @file_get_contents($_FILES['bl_sicherung']['tmp_name']));
        if ($bl_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $bl_error = bl_t('TEXT.SICH_ABGELEHNT') . ' '
                            . implode(' ', $bl_mangel);
        } elseif (bl_config_write($bl_neu)) {
            $bl_saved = true; $bl_hinweis = sprintf(bl_t('TEXT.SICH_UEBERNOMMEN'), $bl_n);
        } else {
            $bl_error = bl_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}


if (class_exists('LBWeb', false)) {
    LBWeb::lbheader('BLE-Scanner NG',
        'https://github.com/timanders22/LoxBerry-Plugin-BLE-Scanner', 'help.html');
}

?>
<style>
/* Hausstandard - wortgleich aus VORLAGE_hausstandard.css.html uebernommen. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-zeile-ok  { color: #1a7f1a; font-weight: 700; }
.sm-zeile-rot { color: #b00000; font-weight: 700; }
.sm-zeile-off { color: #8a6d3b; font-weight: 700; }
.sm-bar { display: inline-block; height: 11px; border-radius: 3px; vertical-align: middle; }
.sm-l3 { background: #6dac20; width: 42px; }
.sm-l2 { background: #9ec84f; width: 28px; }
.sm-l1 { background: #cfcfcf; width: 14px; }
.sm-l0 { background: #e6e6e6; width: 8px; }
.sm-klein { font-size: 0.82em; color: #666; }
.sm-wrap details > summary { cursor: pointer; font-size: 0.85em; color: #4f7d17; }
</style>
<div class="sm-wrap">

<?php if ($bl_saved) { ?>
<div class="sm-hinweis"><b><?= bl_e(bl_t('TEXT.GESPEICHERT')) ?></b> <?= bl_e($bl_hinweis) ?></div>
<?php } ?>
<?php if ($bl_error !== '') { ?>
<div class="sm-fehler"><b><?= bl_e(bl_t('TEXT.FEHLER')) ?></b> <?= $bl_error ?></div>
<?php } ?>
<?php if ($bl_mangel) { ?>
<div class="sm-warnung"><b><?= bl_e(bl_t('TEXT.BEANSTANDUNGEN')) ?></b>
<ul style="margin:6px 0 0 18px;">
<?php foreach ($bl_mangel as $m) { ?><li><?= bl_e($m) ?></li><?php } ?>
</ul></div>
<?php } ?>
<?php if ($bl_altformat) { ?>
<div class="sm-hinweis"><?= bl_e(bl_t('TEXT.ALTES_FORMAT')) ?></div>
<?php } ?>

<div class="sm-kacheln" id="bl-kacheln">
  <div class="sm-kachel"><b id="bl-k-dienst" class="<?= $bl_pid ? 'sm-an' : 'sm-aus' ?>"><?= $bl_pid ? bl_e(bl_t('TEXT.LAEUFT')) : bl_e(bl_t('TEXT.LAEUFT_NICHT')) ?></b><?= bl_e(bl_t('TEXT.K_DIENST')) ?></div>
  <div class="sm-kachel"><b id="bl-k-anwesend"><?= $bl_status ? (int) $bl_status['anwesend'] : 0 ?></b><?= bl_e(bl_t('TEXT.K_ANWESEND')) ?></div>
  <div class="sm-kachel"><b id="bl-k-tags"><?= (int) $bl_aktiv ?></b><?= bl_e(bl_t('TEXT.K_AKTIVE_TAGS')) ?></div>
  <div class="sm-kachel"><b id="bl-k-stille"><?= $bl_stille < 0 ? '?' : (int) $bl_stille ?></b><?= bl_e(bl_t('TEXT.K_EMPFANG')) ?></div>
  <div class="sm-kachel"><b id="bl-k-alter"><?= $bl_alter < 0 ? '?' : (int) $bl_alter ?></b><?= bl_e(bl_t('TEXT.K_ABBILD')) ?></div>
</div>

<div class="sm-tabs">
	<a class="sm-tab<?= $bl_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= bl_e(bl_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $bl_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $bl_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= bl_e(bl_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $bl_tab === 'tab-verlauf' ? ' sm-active' : '' ?>" data-ziel="tab-verlauf"
	   href="index.php?form=verlauf"><?= bl_e(bl_t('REITER.VERLAUF')) ?></a>
	<a class="sm-tab<?= $bl_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= bl_e(bl_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $bl_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= bl_e(bl_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $bl_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h3><?= bl_e(bl_t('TEXT.DIENST_STEUERN')) ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= bl_e(bl_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= bl_e(bl_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="start"><?= bl_e(bl_t('KNOPF.START')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?= bl_e(bl_t('KNOPF.RESTART')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?= bl_e(bl_t('KNOPF.STOP')) ?></button></form>
  <?php echo bl_fmt(); ?>
</div>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.DIENST_SOFORT')) ?></p>

<form method="post" action="index.php">
  <?php echo bl_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= bl_e(bl_t('TEXT.TAGS')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.TAGS_HILFE')) ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:190px;"><?= bl_e(bl_t('TEXT.SP_KENNUNG')) ?></th>
<th><?= bl_e(bl_t('TEXT.SP_BEZEICHNUNG')) ?></th>
<th style="width:60px;"><?= bl_e(bl_t('TEXT.SP_AKTIV')) ?></th>
<th style="width:70px;"><?= bl_e(bl_t('TEXT.SP_ENTFERNEN')) ?></th>
<th style="width:200px;"><?= bl_e(bl_t('TEXT.SP_ZUSTAND')) ?></th></tr>
<?php foreach ($bl_tags as $i => $tag) {
    $z = isset($bl_zustand[$tag['kennung']]) ? $bl_zustand[$tag['kennung']] : null;
    $o = $tag['opt']; ?>
<tr>
<td><input data-role="none" type="text" name="tag_kennung[<?= (int) $i ?>]" value="<?= bl_e($tag['kennung']) ?>" style="width:100%;box-sizing:border-box;"></td>
<td><input data-role="none" type="text" name="tag_name[<?= (int) $i ?>]" value="<?= bl_e($tag['name']) ?>" style="width:100%;box-sizing:border-box;" placeholder="<?= bl_e(bl_t('TEXT.PH_BEZEICHNUNG')) ?>">
<details>
<summary><?= bl_e(bl_t('TEXT.MEHR_EINSTELLUNGEN')) ?></summary>
<div class="sm-klein" style="margin-top:6px;">
<?= bl_e(bl_t('FELD.ALIAS')) ?>: <input data-role="none" type="text" name="tag_alias[<?= (int) $i ?>]" value="<?= bl_e(isset($o['alias']) ? $o['alias'] : '') ?>" size="14">
&nbsp; <?= bl_e(bl_t('FELD.PERSON')) ?>: <input data-role="none" type="text" name="tag_person[<?= (int) $i ?>]" value="<?= bl_e(isset($o['person']) ? $o['person'] : '') ?>" size="12">
&nbsp; <?= bl_e(bl_t('FELD.ABW_JE_TAG')) ?>: <input data-role="none" type="text" name="tag_abw[<?= (int) $i ?>]" value="<?= bl_e(isset($o['abw']) ? $o['abw'] : '') ?>" size="5">
&nbsp; <?= bl_e(bl_t('FELD.REF_JE_TAG')) ?>: <input data-role="none" type="text" name="tag_ref[<?= (int) $i ?>]" value="<?= bl_e(isset($o['ref']) ? $o['ref'] : '') ?>" size="5">
&nbsp; <label style="display:inline;font-weight:400;"><input data-role="none" type="checkbox" name="tag_batt[<?= (int) $i ?>]" value="1"<?= (isset($o['batt']) && $o['batt'] === '1') ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.BATT_JE_TAG')) ?></label>
<div class="sm-hilfe"><?= bl_e(bl_t('TEXT.ALIAS_HILFE')) ?></div>
</div>
</details></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="tag_aktiv[<?= (int) $i ?>]" value="1"<?= $tag['aktiv'] === '1' ? ' checked' : '' ?>></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="tag_weg[<?= (int) $i ?>]" value="1"></td>
<td><?php if ($z) {
        echo '<i class="sm-bar sm-l' . (int) $z['stufe'] . '"></i> ';
        echo $z['anwesend'] ? '<span class="sm-an">' . bl_e(bl_t('TEXT.ANWESEND')) . '</span>'
                            : '<span class="sm-aus">' . bl_e(bl_t('TEXT.ABWESEND')) . '</span>';
        if ($z['rssi'] !== null) { echo ' <span class="sm-klein">' . (int) $z['rssi'] . ' dBm</span>'; }
        if (($z['adresstyp'] ?? '') === 'wechselnd') {
            echo '<br><span class="sm-aus sm-klein">' . bl_e(bl_t('TEXT.ADRESSE_WECHSELT')) . '</span>';
        }
        if (!empty($z['sensor'])) {
            echo '<br><span class="sm-klein">';
            foreach ($z['sensor'] as $sk => $sv) { echo bl_e($sk . '=' . $sv) . ' '; }
            echo '</span>';
        }
    } else { echo '<span class="sm-klein">&ndash;</span>'; } ?></td>
</tr>
<?php } ?>
<?php for ($n = 0; $n < 3; $n++) { $i = 'neu' . $n; ?>
<tr>
<td><input data-role="none" type="text" name="tag_kennung[<?= $i ?>]" value="" placeholder="AA:BB:CC:DD:EE:FF" style="width:100%;box-sizing:border-box;"></td>
<td><input data-role="none" type="text" name="tag_name[<?= $i ?>]" value="" placeholder="<?= bl_e(bl_t('TEXT.PH_VON_HAND')) ?>" style="width:100%;box-sizing:border-box;"></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="tag_aktiv[<?= $i ?>]" value="1" checked></td>
<td></td>
<td><span class="sm-klein"><?= bl_e(bl_t('TEXT.NEU')) ?></span></td>
</tr>
<?php } ?>
</table>
</div>

<?php if ($bl_such && !empty($bl_such['geraete'])) { ?>
<h2><?= bl_e(bl_t('TEXT.GEFUNDENE_GERAETE')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.QUELLE')) ?>: <?= bl_e($bl_such['quelle']) ?> &middot;
<?= (int) $bl_such['anzahl'] ?> <?= bl_e(bl_t('TEXT.GERAETE_ANHAKEN')) ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:26px;"></th><th style="width:170px;"><?= bl_e(bl_t('TEXT.SP_ADRESSE')) ?></th>
<th style="width:110px;"><?= bl_e(bl_t('TEXT.SP_SIGNAL')) ?></th>
<th style="width:110px;"><?= bl_e(bl_t('TEXT.SP_ADRESSTYP')) ?></th>
<th><?= bl_e(bl_t('TEXT.SP_GEMELDETER_NAME')) ?></th>
<th style="width:150px;"><?= bl_e(bl_t('TEXT.SP_BEZEICHNUNG')) ?></th></tr>
<?php foreach ($bl_such['geraete'] as $g) {
    if (!empty($g['bekannt'])) { continue; }
    $stufe = 1;
    if ($g['rssi'] !== null) {
        $stufe = (int) $g['rssi'] >= (int) bl_cfg($bl_cfg, 'rssi_nah', '-65') ? 3
               : ((int) $g['rssi'] >= (int) bl_cfg($bl_cfg, 'rssi_mittel', '-85') ? 2 : 1);
    }
    $typ = isset($g['adresstyp']) ? $g['adresstyp'] : 'unbekannt'; ?>
<tr>
<td><input data-role="none" type="checkbox" name="neu_kennung[<?= bl_e($g['mac']) ?>]" value="1"></td>
<td><span class="sm-mono"><?= bl_e($g['mac']) ?></span>
<?php if (!empty($g['beaconart'])) { ?><br><span class="sm-klein"><?= bl_e($g['beaconart']) ?></span><?php } ?></td>
<td><i class="sm-bar sm-l<?= $stufe ?>"></i> <span class="sm-klein"><?= (int) $g['rssi'] ?> dBm</span></td>
<td class="<?= $typ === 'wechselnd' ? 'sm-aus' : '' ?>"><?= bl_e(bl_t('ADRESSTYP.' . strtoupper($typ))) ?></td>
<td><?= bl_e($g['name']) ?></td>
<td><input data-role="none" type="text" name="neu_name[<?= bl_e($g['mac']) ?>]" value="<?= bl_e($g['name']) ?>" style="width:100%;box-sizing:border-box;"></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?= bl_e(bl_t('TEXT.WECHSELNDE_WARNUNG')) ?></div>
<?php } elseif ($bl_such) { ?>
<div class="sm-hinweis"><?= bl_e(bl_t('TEXT.NICHTS_NEUES')) ?> (<?= bl_e($bl_such['quelle']) ?>)</div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= bl_e(bl_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= bl_e(bl_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="suchen" value="1"><?= bl_e(bl_t('KNOPF.SUCHEN')) ?></button>
</div>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.SUCHLAUF_HILFE')) ?></p>

<h2><?= bl_e(bl_t('TEXT.WEG_ZUM_MINISERVER')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.MQTT_IM_REITER')) ?></p>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="http_push" value="1"<?= bl_cfg($bl_cfg, 'http_push', '0') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.HTTP_PUSH')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.HTTP_HILFE')) ?></p>
</div>
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.LOXBERRY_ID')) ?></label>
<input data-role="none" type="text" name="loxberry_id" value="<?= bl_e(bl_cfg($bl_cfg, 'loxberry_id', '')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.LOXBERRY_ID_HILFE')) ?></p>
</div>
<?php $bl_ms = bl_miniserver(); if ($bl_ms) { ?>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.MINISERVER_IST')) ?>
<?php foreach ($bl_ms as $m) { ?><span class="sm-mono"><?= bl_e($m['name'] . ' &rarr; ' . $m['adresse'] . ':' . $m['port']) ?></span> <?php } ?></p>
<?php } ?>

<h2><?= bl_e(bl_t('TEXT.ADAPTER_UND_BETRIEB')) ?></h2>
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.ADAPTER')) ?></label>
<input data-role="none" type="text" name="adapter" value="<?= bl_e(bl_cfg($bl_cfg, 'adapter', 'hci0')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.ADAPTER_HILFE')) ?></p>
</div>
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.BETRIEBSART')) ?></label>
<select data-role="none" name="betriebsart">
<option value="signal"<?= bl_cfg($bl_cfg, 'betriebsart', 'signal') === 'signal' ? ' selected' : '' ?>><?= bl_e(bl_t('TEXT.BA_SIGNAL')) ?></option>
<option value="abfrage"<?= bl_cfg($bl_cfg, 'betriebsart', 'signal') === 'abfrage' ? ' selected' : '' ?>><?= bl_e(bl_t('TEXT.BA_ABFRAGE')) ?></option>
</select>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.BETRIEBSART_HILFE')) ?></p>
</div>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="wachhund" value="1"<?= bl_cfg($bl_cfg, 'wachhund', '1') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.WACHHUND')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.WACHHUND_HILFE')) ?></p>
</div>
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.STILLE')) ?></label>
<input data-role="none" type="number" name="wachhund_stille" min="60" max="86400" value="<?= bl_e(bl_cfg($bl_cfg, 'wachhund_stille', '300')) ?>">
</div>
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.DISCOVERY_RSSI')) ?></label>
<input data-role="none" type="number" name="discovery_rssi" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'discovery_rssi', '0')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.DISCOVERY_HILFE')) ?></p>
</div>
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.LOGKAPPUNG')) ?></label>
<input data-role="none" type="number" name="log_kappung_kb" min="16" max="20000" value="<?= bl_e(bl_cfg($bl_cfg, 'log_kappung_kb', '500')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.LOGKAPPUNG_HILFE')) ?></p>
</div>

<h2><?= bl_e(bl_t('TEXT.ZEITEN_UND_SCHWELLEN')) ?></h2>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.INTERVALL')) ?></label>
<input data-role="none" type="number" name="intervall" min="2" max="600" value="<?= bl_e(bl_cfg($bl_cfg, 'intervall', '5')) ?>"></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.ABWESEND')) ?></label>
<input data-role="none" type="number" name="abwesenheit_nach" min="5" max="3600" value="<?= bl_e(bl_cfg($bl_cfg, 'abwesenheit_nach', '30')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.ABWESEND_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.AKTUALISIERUNG')) ?></label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= bl_e(bl_cfg($bl_cfg, 'aktualisierung', '60')) ?>"></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.ANKUNFT')) ?></label>
<input data-role="none" type="number" name="ankunft_sichtungen" min="1" max="20" value="<?= bl_e(bl_cfg($bl_cfg, 'ankunft_sichtungen', '1')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.ANKUNFT_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.RSSI_MINIMUM')) ?></label>
<input data-role="none" type="number" name="rssi_minimum" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'rssi_minimum', '-100')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.RSSI_MINIMUM_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.RSSI_NAH')) ?></label>
<input data-role="none" type="number" name="rssi_nah" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'rssi_nah', '-65')) ?>"></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.RSSI_MITTEL')) ?></label>
<input data-role="none" type="number" name="rssi_mittel" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'rssi_mittel', '-85')) ?>"></div>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="glaettung" value="1"<?= bl_cfg($bl_cfg, 'glaettung', '1') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.GLAETTUNG')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.GLAETTUNG_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.FENSTER')) ?></label>
<input data-role="none" type="number" name="glaettung_fenster" min="1" max="30" value="<?= bl_e(bl_cfg($bl_cfg, 'glaettung_fenster', '5')) ?>"></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.HYSTERESE')) ?></label>
<input data-role="none" type="number" name="hysterese_db" min="0" max="20" value="<?= bl_e(bl_cfg($bl_cfg, 'hysterese_db', '3')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.HYSTERESE_HILFE')) ?></p></div>

<h2><?= bl_e(bl_t('TEXT.MEHR_WERTE')) ?></h2>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="beacon" value="1"<?= bl_cfg($bl_cfg, 'beacon', '1') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.BEACON')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.BEACON_HILFE')) ?></p></div>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="entfernung" value="1"<?= bl_cfg($bl_cfg, 'entfernung', '0') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.ENTFERNUNG')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.ENTFERNUNG_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.DAEMPFUNG')) ?></label>
<input data-role="none" type="text" name="daempfung" value="<?= bl_e(bl_cfg($bl_cfg, 'daempfung', '2.5')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.DAEMPFUNG_HILFE')) ?></p></div>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="batterie" value="1"<?= bl_cfg($bl_cfg, 'batterie', '0') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.BATTERIE')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.BATTERIE_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.BATTERIE_UHRZEIT')) ?></label>
<input data-role="none" type="text" name="batterie_uhrzeit" value="<?= bl_e(bl_cfg($bl_cfg, 'batterie_uhrzeit', '04:00')) ?>" placeholder="04:00"></div>

<h2><?= bl_e(bl_t('TEXT.AUFZEICHNUNG')) ?></h2>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="ereignisse" value="1"<?= bl_cfg($bl_cfg, 'ereignisse', '1') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.EREIGNISSE')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.EREIGNISSE_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.EREIGNISTAGE')) ?></label>
<input data-role="none" type="number" name="ereignisse_tage" min="1" max="365" value="<?= bl_e(bl_cfg($bl_cfg, 'ereignisse_tage', '7')) ?>"></div>

<h2><?= bl_e(bl_t('TEXT.MEHRERE_SCANNER')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.SCANNER_HILFE')) ?></p>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.SCANNER_NAME')) ?></label>
<input data-role="none" type="text" name="scanner_name" value="<?= bl_e(bl_cfg($bl_cfg, 'scanner_name', '')) ?>" placeholder="<?= bl_e($bl_status ? (string) ($bl_status['scanner'] ?? '') : '') ?>"></div>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="scanner_themen" value="1"<?= bl_cfg($bl_cfg, 'scanner_themen', '0') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.SCANNER_THEMEN')) ?></label></div>
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="raum" value="1"<?= bl_cfg($bl_cfg, 'raum', '0') === '1' ? ' checked' : '' ?>> <?= bl_e(bl_t('FELD.RAUM')) ?></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.RAUM_HILFE')) ?></p></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.RAUM_HYST')) ?></label>
<input data-role="none" type="number" name="raum_hysterese_db" min="0" max="30" value="<?= bl_e(bl_cfg($bl_cfg, 'raum_hysterese_db', '5')) ?>"></div>
<div class="sm-feld"><label><?= bl_e(bl_t('FELD.RAUM_AUSGLEICH')) ?></label>
<input data-role="none" type="number" name="raum_ausgleich_db" min="-30" max="30" value="<?= bl_e(bl_cfg($bl_cfg, 'raum_ausgleich_db', '0')) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.AUSGLEICH_HILFE')) ?></p></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= bl_e(bl_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?= bl_e(bl_t('KNOPF.SPEICHERN')) ?></button>
</div>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.SPEICHERN_HILFE')) ?></p>
</form>

<h2><?= bl_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= bl_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= bl_t('TEXT.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo bl_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="bl_sichern" value="1"><?= bl_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo bl_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="bl_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="bl_zurueck" value="1"><?= bl_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $bl_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?= bl_e(bl_t('TEXT.MQTT_UEBERTRAGUNG')) ?></h2>
<?php if ($bl_autostart === false) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?= bl_e(bl_t('TEXT.W_AUTOSTART')) ?></div>
<?php } elseif ($bl_autostart === null) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?= bl_e(bl_t('TEXT.W_AUTOSTART_UNBEKANNT')) ?></div>
<?php } ?>
<form method="post" action="index.php">
  <?php echo bl_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
<label style="font-weight:400;"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= bl_cfg($bl_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b><?= bl_e(bl_t('FELD.MQTT')) ?></b></label>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.MQTT_HILFE')) ?></p>
</div>
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.THEMENPRAEFIX')) ?></label>
<input data-role="none" type="text" name="themenpraefix" value="<?= bl_e($bl_praefix) ?>">
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.PRAEFIX_HILFE')) ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= bl_e(bl_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_mqtt" value="1"><?= bl_e(bl_t('KNOPF.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= bl_e(bl_t('TEXT.GATEWAY_ZUSTAND')) ?></h2>
<table class="sm-tbl">
<tr><th style="width:220px;"><?= bl_e(bl_t('TEXT.SP_ANGABE')) ?></th><th><?= bl_e(bl_t('TEXT.SP_WERT')) ?></th></tr>
<tr><td><?= bl_e(bl_t('TEXT.BROKER')) ?></td><td><span class="sm-mono"><?= $bl_broker !== '' ? bl_e($bl_broker) : bl_e(bl_t('TEXT.GATEWAY_FEHLT')) ?></span></td></tr>
<tr><td><?= bl_e(bl_t('TEXT.AUTOSTART')) ?></td><td><?= $bl_autostart === null ? '?' : ($bl_autostart ? bl_e(bl_t('TEXT.EIN')) : bl_e(bl_t('TEXT.AUS'))) ?></td></tr>
<tr><td><?= bl_e(bl_t('TEXT.VERBUNDEN')) ?></td><td><?= $bl_status ? (((int) ($bl_status['mqtt_verbunden'] ?? 0)) === 1 ? bl_e(bl_t('TEXT.JA')) : bl_e(bl_t('TEXT.NEIN'))) : '?' ?></td></tr>
<tr><td><?= bl_e(bl_t('TEXT.GESENDET')) ?></td><td><?= $bl_status ? (int) ($bl_status['mqtt_gesendet'] ?? 0) : '?' ?></td></tr>
<tr><td><?= bl_e(bl_t('TEXT.VERLUSTE')) ?></td><td><?= $bl_status ? (int) ($bl_status['mqtt_verluste'] ?? 0) : '?' ?></td></tr>
</table>

<h2><?= bl_e(bl_t('TEXT.ABO_EINTRAGEN')) ?></h2>
<div class="sm-step"><b><?= bl_abo_text() ?></b><br><br>
<?= bl_e(bl_t('TEXT.ABO_WO')) ?>
<div class="sm-mono" style="margin-top:6px;padding:8px;border:1px solid #ccc;background:#f4f4f4;"><?= bl_e($bl_praefix) ?>/#</div>
</div>

<h2><?= bl_e(bl_t('TEXT.THEMEN_TABELLE')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34%;"><?= bl_e(bl_t('TEXT.SP_THEMA')) ?></th><th style="width:10%;"><?= bl_e(bl_t('TEXT.SP_ART')) ?></th><th><?= bl_e(bl_t('TEXT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (bl_allgemeine_themen() as $k => $info) { ?>
<tr><td><span class="sm-mono"><?= bl_e($bl_praefix . '/' . $k) ?></span></td><td><?= bl_e(bl_t('ART.' . strtoupper($info['art']))) ?></td><td><?= bl_e(bl_t($info['s'])) ?></td></tr>
<?php } ?>
<?php foreach ($bl_themen_tag as $k => $info) { ?>
<tr><td><span class="sm-mono"><?= bl_e($bl_praefix) ?>/&lt;T&gt;/<?= bl_e($k) ?></span></td><td><?= bl_e(bl_t('ART.' . strtoupper($info['art']))) ?></td><td><?= bl_e(bl_t($info['s'])) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.T_STEHT_FUER')) ?></p>

<?php if ($bl_tags) { ?>
<h2><?= bl_e(bl_t('TEXT.THEMEN_DER_TAGS')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:200px;"><?= bl_e(bl_t('TEXT.SP_KENNUNG')) ?></th><th><?= bl_e(bl_t('TEXT.SP_BEZEICHNUNG')) ?></th><th><?= bl_e(bl_t('TEXT.SP_THEMENZWEIG')) ?></th></tr>
<?php foreach ($bl_tags as $tag) { ?>
<tr><td><span class="sm-mono"><?= bl_e($tag['kennung']) ?></span></td>
<td><?= bl_e($tag['name']) ?><?= $tag['aktiv'] === '1' ? '' : ' <span class="sm-klein">' . bl_e(bl_t('TEXT.NICHT_AKTIV')) . '</span>' ?></td>
<td><span class="sm-mono"><?= bl_e($bl_praefix . '/' . bl_thema($tag)) ?></span></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $bl_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= bl_e(bl_t('TEXT.LOX_SCHRITT_FUER_SCHRITT')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.LOX_EINLEITUNG')) ?></p>

<div class="sm-step"><b><?= bl_e(bl_t('LOX.S1')) ?></b><br><br><?= bl_e(bl_t('LOX.S1_TEXT')) ?></div>
<div class="sm-step"><b><?= bl_e(bl_t('LOX.S2')) ?></b><br><br><?= bl_abo_text() ?>
<div class="sm-mono" style="margin-top:6px;padding:8px;border:1px solid #ccc;background:#f4f4f4;"><?= bl_e($bl_praefix) ?>/#</div></div>
<div class="sm-step"><b><?= bl_e(bl_t('LOX.S3')) ?></b><br><br><?= bl_e(bl_t('LOX.S3_TEXT')) ?></div>
<div class="sm-step"><b><?= bl_e(bl_t('LOX.S4')) ?></b><br><br><?= bl_e(bl_t('LOX.S4_TEXT')) ?></div>
<div class="sm-step"><b><?= bl_e(bl_t('LOX.S5')) ?></b><br><br><?= bl_e(bl_t('LOX.S5_TEXT')) ?></div>
<div class="sm-step"><b><?= bl_e(bl_t('LOX.S6')) ?></b><br><br><?= bl_e(bl_t('LOX.S6_TEXT')) ?></div>

<h2><?= bl_e(bl_t('TEXT.ALLES_AUF_EINMAL')) ?></h2>
<?php if (!$bl_aktiv) { ?>
<div class="sm-fehler"><?= bl_e(bl_t('TEXT.VORLAGE_OHNE_TAG')) ?></div>
<?php } else {
    list($_vn, $_vi, $bl_vanzahl, $bl_vweg) = bl_vorlage($bl_cfg, $bl_tags); ?>
<p class="sm-hilfe"><?= sprintf(bl_e(bl_t('TEXT.VORLAGE_ANZAHL')), (int) $bl_aktiv, (int) $bl_vanzahl) ?></p>
<?php if ($bl_vweg) { ?>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.VORLAGE_OHNE_TEXT')) ?>
<span class="sm-mono"><?= bl_e(implode(', ', array_slice($bl_vweg, 0, 8))) ?></span></p>
<?php } ?>
<div class="sm-hinweis"><?= bl_e(bl_t('TEXT.VORLAGE_IMPORT_HINWEIS')) ?></div>
<?php } ?>
<form method="post" action="index.php">
  <?php echo bl_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= bl_e(bl_t('LEGENDE.TECHNIK')) ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="1"><?= bl_e(bl_t('KNOPF.VORLAGE')) ?></button>
</div>
</form>

<h2><?= bl_e(bl_t('TEXT.BAUSTEINLISTE')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.BAUSTEIN_EINLEITUNG')) ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34px;">#</th><th style="width:170px;"><?= bl_e(bl_t('TEXT.SP_BAUSTEIN')) ?></th>
<th style="width:210px;"><?= bl_e(bl_t('TEXT.SP_NAMENSVORSCHLAG')) ?></th>
<th style="width:150px;"><?= bl_e(bl_t('TEXT.SP_PARAMETER')) ?></th><th><?= bl_e(bl_t('TEXT.SP_EINGAENGE')) ?></th></tr>
<?php
$bl_bausteine = array(
    array('BAUSTEIN.VI', $bl_praefix . '_server_online',   'BAUSTEIN.DIGITAL',       'BAUSTEIN.VOM_GATEWAY'),
    array('BAUSTEIN.VI', $bl_praefix . '_server_ok',       'BAUSTEIN.DIGITAL',       'BAUSTEIN.VOM_GATEWAY'),
    array('BAUSTEIN.VI', $bl_praefix . '_server_ts',       'BAUSTEIN.ANALOG_ZEIT',   'BAUSTEIN.VOM_GATEWAY'),
    array('BAUSTEIN.VI', $bl_praefix . '_summary_present', 'BAUSTEIN.ANALOG_ANZAHL', 'BAUSTEIN.VOM_GATEWAY'),
    array('BAUSTEIN.VI', $bl_praefix . '_&lt;T&gt;_present',    'BAUSTEIN.DIGITAL_JE_TAG', '&mdash;'),
    array('BAUSTEIN.VI', $bl_praefix . '_&lt;T&gt;_last_seen_ts', 'BAUSTEIN.ANALOG_ZEIT', '&mdash;'),
    array('BAUSTEIN.AUS', 'BAUSTEIN.N_ENTPRELLT',  'BAUSTEIN.P_ENTPRELLT',  'BAUSTEIN.E_ENTPRELLT'),
    array('BAUSTEIN.ODER', 'BAUSTEIN.N_JEMAND',    'BAUSTEIN.P_JEMAND',     'BAUSTEIN.E_JEMAND'),
    array('BAUSTEIN.FLANKE_F', 'BAUSTEIN.N_LETZTER', '&mdash;',             'BAUSTEIN.E_LETZTER'),
    array('BAUSTEIN.FLANKE_S', 'BAUSTEIN.N_ERSTER',  '&mdash;',             'BAUSTEIN.E_ERSTER'),
    array('BAUSTEIN.NICHT', 'BAUSTEIN.N_TOT',       '&mdash;',              'BAUSTEIN.E_TOT'),
    array('BAUSTEIN.EIN', 'BAUSTEIN.N_AUSFALL',     'BAUSTEIN.P_AUSFALL',   'BAUSTEIN.E_AUSFALL'),
    array('BAUSTEIN.UND', 'BAUSTEIN.N_GILT',        '&mdash;',              'BAUSTEIN.E_GILT'),
    array('BAUSTEIN.FORMEL', 'BAUSTEIN.N_WIELANGE', 'BAUSTEIN.P_WIELANGE',  'BAUSTEIN.E_WIELANGE'),
    array('BAUSTEIN.STATUS', 'BAUSTEIN.N_ANWESENHEIT', 'BAUSTEIN.P_STATUS', 'BAUSTEIN.E_STATUS'),
);
foreach ($bl_bausteine as $nr => $b) { ?>
<tr><td><?= $nr + 1 ?></td><td><?= bl_e(bl_t($b[0])) ?></td>
<td class="sm-mono"><?= strpos($b[1], 'BAUSTEIN.') === 0 ? bl_e(bl_t($b[1])) : $b[1] ?></td>
<td><?= strpos($b[2], 'BAUSTEIN.') === 0 ? bl_e(bl_t($b[2])) : $b[2] ?></td>
<td><?= strpos($b[3], 'BAUSTEIN.') === 0 ? bl_e(bl_t($b[3])) : $b[3] ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hinweis">
<b><?= bl_e(bl_t('BAUSTEIN.ZU13')) ?></b> <?= bl_e(bl_t('BAUSTEIN.ZU13_TEXT')) ?><br>
<b><?= bl_e(bl_t('BAUSTEIN.ZU7')) ?></b> <?= bl_e(bl_t('BAUSTEIN.ZU7_TEXT')) ?><br>
<b><?= bl_e(bl_t('BAUSTEIN.ZU14')) ?></b> <?= bl_e(bl_t('BAUSTEIN.ZU14_TEXT')) ?><br>
<b><?= bl_e(bl_t('BAUSTEIN.ZU12')) ?></b> <?= bl_e(bl_t('BAUSTEIN.ZU12_TEXT')) ?>
</div>

<h2><?= bl_e(bl_t('TEXT.GEGENPROBE')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.GEGENPROBE_TEXT')) ?></p>

<h2><?= bl_e(bl_t('TEXT.NICHT_VERLASSEN')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.NICHT_VERLASSEN_TEXT')) ?></p>
</div>

<!-- ================= Reiter: Verlauf ================= -->
<div class="sm-seite<?= $bl_tab === 'tab-verlauf' ? ' sm-active' : '' ?>" id="tab-verlauf">
<h2><?= bl_e(bl_t('TEXT.VERLAUF_UEBERSCHRIFT')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.VERLAUF_EINLEITUNG')) ?></p>
<?php if (!$bl_verlauf['vorhanden']) { ?>
<div class="sm-hinweis"><?= bl_e(bl_t('TEXT.KEIN_VERLAUF')) ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= bl_e(bl_t('TEXT.SP_BEZEICHNUNG')) ?></th><th style="width:80px;"><?= bl_e(bl_t('TEXT.SP_KOMMT')) ?></th>
<th style="width:80px;"><?= bl_e(bl_t('TEXT.SP_GEHT')) ?></th><th style="width:130px;"><?= bl_e(bl_t('TEXT.SP_LUECKE')) ?></th>
<th><?= bl_e(bl_t('TEXT.SP_EMPFEHLUNG')) ?></th></tr>
<?php foreach ($bl_verlauf['je_zweig'] as $zweig => $s) {
    $empf = $s['luecke_max'] > 0 ? max(30, (int) ceil($s['luecke_max'] * 2 / 10) * 10) : 0; ?>
<tr><td><?= bl_e($s['name'] !== '' ? $s['name'] : $zweig) ?></td>
<td><?= (int) $s['kommt'] ?></td><td><?= (int) $s['geht'] ?></td>
<td><?= (int) $s['luecke_max'] ?> s</td>
<td><?= $empf ? sprintf(bl_e(bl_t('TEXT.EMPFEHLUNG')), $empf) : bl_e(bl_t('TEXT.ZU_WENIG_DATEN')) ?></td></tr>
<?php } ?>
</table>
</div>
<h2><?= bl_e(bl_t('TEXT.LETZTE_EREIGNISSE')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:150px;"><?= bl_e(bl_t('TEXT.SP_ZEIT')) ?></th><th><?= bl_e(bl_t('TEXT.SP_BEZEICHNUNG')) ?></th>
<th style="width:90px;"><?= bl_e(bl_t('TEXT.SP_EREIGNIS')) ?></th><th style="width:90px;">RSSI</th></tr>
<?php foreach (array_slice(array_reverse($bl_verlauf['zeilen']), 0, 60) as $z) { ?>
<tr><td><?= bl_e(date('d.m.Y H:i:s', $z['zeit'])) ?></td><td><?= bl_e($z['name']) ?></td>
<td><?= $z['ereignis'] === 'kommt' ? '<span class="sm-an">' . bl_e(bl_t('TEXT.E_KOMMT')) . '</span>'
                                   : '<span class="sm-aus">' . bl_e(bl_t('TEXT.E_GEHT')) . '</span>' ?></td>
<td><?= $z['rssi'] === null ? '&ndash;' : (int) $z['rssi'] . ' dBm' ?></td></tr>
<?php } ?>
</table>
</div>
<form method="post" action="index.php">
  <?php echo bl_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-verlauf">
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= bl_e(bl_t('LEGENDE.TECHNIK')) ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="verlauf_download" value="1"><?= bl_e(bl_t('KNOPF.VERLAUF_CSV')) ?></button>
</div>
</form>
<?php } ?>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $bl_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= bl_e(bl_t('TEXT.SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.SELBSTPRUEFUNG_HILFE')) ?></p>
<?php
require_once __DIR__ . '/bl_test.php';
$bl_zeilen = bl_pruefzeilen($bl_cfg, $bl_tags);
$bl_ok = $bl_rot = $bl_offen = 0;
foreach ($bl_zeilen as $z) {
    if ($z['zustand'] === true) { $bl_ok++; }
    elseif ($z['zustand'] === false) { $bl_rot++; }
    else { $bl_offen++; }
}
?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:44px;"></th><th style="width:44%;"><?= bl_e(bl_t('TEXT.SP_PRUEFUNG')) ?></th><th><?= bl_e(bl_t('TEXT.SP_ANMERKUNG')) ?></th></tr>
<?php foreach ($bl_zeilen as $z) {
    if ($z['zustand'] === true) { $kl = 'sm-zeile-ok'; $zeichen = '&#10003;'; }
    elseif ($z['zustand'] === false) { $kl = 'sm-zeile-rot'; $zeichen = '&#10007;'; }
    else { $kl = 'sm-zeile-off'; $zeichen = '&ndash;'; } ?>
<tr><td class="<?= $kl ?>" style="text-align:center;font-size:1.2em;"><?= $zeichen ?></td>
<td><?= bl_e($z['text']) ?></td>
<td class="sm-klein"><?= nl2br(bl_e($z['anmerkung'])) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= sprintf(bl_e(bl_t('TEXT.BILANZ')), $bl_ok, $bl_rot, $bl_offen) ?></p>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= bl_e(bl_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= bl_e(bl_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= bl_e(bl_t('LEGENDE.AKTION')) ?></span>
</div>

<h3><?= bl_e(bl_t('TEXT.G_ANSEHEN')) ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="selbsttest"><?= bl_e(bl_t('KNOPF.SELBSTTEST')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?= bl_e(bl_t('KNOPF.STATUS')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="tags"><?= bl_e(bl_t('KNOPF.TAGS')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="sichtbar"><?= bl_e(bl_t('KNOPF.SICHTBAR')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="themen"><?= bl_e(bl_t('KNOPF.THEMEN')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="verlauf"><?= bl_e(bl_t('KNOPF.VERLAUF')) ?></button></form>
  <?php echo bl_fmt(); ?>
</div>

<h3><?= bl_e(bl_t('TEXT.G_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="bluetooth"><?= bl_e(bl_t('KNOPF.BLUETOOTH')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?= bl_e(bl_t('KNOPF.KONFIG')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?= bl_e(bl_t('KNOPF.UMGEBUNG')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqttinfo"><?= bl_e(bl_t('KNOPF.MQTTINFO')) ?></button></form>
  <?php echo bl_fmt(); ?>
</div>

<h3><?= bl_e(bl_t('TEXT.G_AKTION')) ?></h3>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.AKTION_SOFORT')) ?></p>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?= bl_e(bl_t('KNOPF.RESTART')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?= bl_e(bl_t('KNOPF.STOP')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="probewert"><?= bl_e(bl_t('KNOPF.PROBEWERT')) ?></button></form>
  <?php echo bl_fmt(); ?>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="batterie"><?= bl_e(bl_t('KNOPF.BATTERIE')) ?></button></form>
  <?php echo bl_fmt(); ?>
</div>

<h3><?= bl_e(bl_t('TEXT.G_KALIBRIEREN')) ?></h3>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.KALIBRIEREN_HILFE')) ?></p>
<form method="post" action="index.php">
  <?php echo bl_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
<label><?= bl_e(bl_t('FELD.TESTTAG')) ?></label>
<select data-role="none" name="testtag">
<?php foreach ($bl_tags as $tag) { ?>
<option value="<?= bl_e($tag['kennung']) ?>"><?= bl_e(($tag['name'] !== '' ? $tag['name'] . ' - ' : '') . $tag['kennung']) ?></option>
<?php } ?>
</select>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="testmodus"><?= bl_e(bl_t('KNOPF.TESTMODUS')) ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="kalibrieren"><?= bl_e(bl_t('KNOPF.KALIBRIEREN')) ?></button>
</div>
</form>
<?php if ($bl_status && !empty($bl_status['kalibrierung'])) {
    $k = $bl_status['kalibrierung']; ?>
<div class="sm-hinweis"><?= sprintf(bl_e(bl_t('TEXT.KALIBRIERERGEBNIS')),
    bl_e($k['kennung']), (int) $k['anzahl'],
    $k['ergebnis'] === null ? '?' : (int) $k['ergebnis']) ?></div>
<?php } ?>

<?php if ($bl_test_titel !== '') { ?>
<h2><?= bl_e($bl_test_titel) ?></h2>
<div class="sm-log"><?= bl_e($bl_test_text) ?></div>
<?php } else { ?>
<div class="sm-hinweis" style="margin-top:18px;"><?= bl_e(bl_t('TEXT.NOCH_NICHTS_ABGEFRAGT')) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $bl_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= bl_e(bl_t('REITER.LOG')) ?></h2>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.LOG_RAMDISK')) ?></p>
<?php
// Der Hausstandard sieht LBWeb::loglist_html() vor - damit gibt es Auswahl,
// Herunterladen und Leeren. Bis 1.2.10 wurde stattdessen die juengste
// Datei selbst ausgelesen. Beides steht jetzt da: die Liste, wo das SDK
// vorhanden ist, und darunter die letzten Zeilen zum schnellen Blick.
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html(array('PACKAGE' => bl_paths()['plugin'],
                                   'NAME' => 'BLE-Scanner NG'));
}
?>
<?php if ($bl_log !== '') { ?>
<p class="sm-hilfe"><?= bl_e(bl_t('TEXT.LOG_DATEI')) ?>: <span class="sm-mono"><?= bl_e($bl_log) ?></span>
&middot; <?= bl_e(bl_t('TEXT.LOG_NEUESTE')) ?>
&middot; <?= (int) round(((int) @filesize($bl_log)) / 1024) ?> kB</p>
<div class="sm-log"><?php foreach (bl_log_ende($bl_log, 300) as $z) { echo bl_e($z) . "\n"; } ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= bl_e(bl_t('TEXT.LOG_LEER')) ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var reiter = document.querySelectorAll('.sm-tab');
    var start = <?= json_encode($bl_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < reiter.length; i++) {
            reiter[i].classList.toggle('sm-active', reiter[i].getAttribute('data-ziel') === id);
        }
        var seiten = document.querySelectorAll('.sm-seite');
        for (i = 0; i < seiten.length; i++) {
            seiten[i].classList.toggle('sm-active', seiten[i].id === id);
        }
        var felder = document.querySelectorAll('input[name="activetab"]');
        for (i = 0; i < felder.length; i++) { felder[i].value = id; }
    }
    /* Die Reiter sind ECHTE Verweise. Solange dieses Skript laeuft, wird der
       Klick abgefangen und nur umgeschaltet; ohne Skript folgt der Browser dem
       Verweis. Welcher Reiter offen ist, entscheidet ohnehin der Server -
       sm-active steht schon im ausgelieferten HTML. */
    for (var i = 0; i < reiter.length; i++) {
        (function (t) {
            t.addEventListener('click', function (ev) {
                ev.preventDefault();
                zeige(t.getAttribute('data-ziel'));
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', t.getAttribute('href'));
                }
            });
        })(reiter[i]);
    }
    zeige(start);

    /* Live-Ansicht: die Kacheln erneuern sich, ohne die Seite neu zu laden.
       Ohne das muss man zum Kalibrieren nach jedem Schritt zurueck an den
       Rechner und F5 druecken - die Zustandsdatei wird zwar alle paar
       Sekunden geschrieben, die Oberflaeche las sie aber nur beim Aufbau. */
    var kachelDienst = document.getElementById('bl-k-dienst');
    if (kachelDienst && window.fetch) {
        setInterval(function () {
            fetch('bl_live.php', {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d) { return; }
                    var s = d.status || {};
                    kachelDienst.textContent = d.pid > 0
                        ? <?= json_encode(bl_t('TEXT.LAEUFT')) ?>
                        : <?= json_encode(bl_t('TEXT.LAEUFT_NICHT')) ?>;
                    kachelDienst.className = d.pid > 0 ? 'sm-an' : 'sm-aus';
                    var setze = function (id, wert) {
                        var e = document.getElementById(id);
                        if (e) { e.textContent = wert; }
                    };
                    setze('bl-k-anwesend', s.anwesend != null ? s.anwesend : 0);
                    setze('bl-k-tags', s.aktiv != null ? s.aktiv : 0);
                    setze('bl-k-stille', d.stille < 0 ? '?' : d.stille);
                    setze('bl-k-alter', d.alter < 0 ? '?' : d.alter);
                })
                .catch(function () { /* Netz weg: die Kacheln bleiben stehen. */ });
        }, 3000);
    }
})();
</script>
<?php
if (class_exists('LBWeb', false)) {
    LBWeb::lbfooter();
}
