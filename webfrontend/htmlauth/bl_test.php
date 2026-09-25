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
    // 137: nach der Frist nicht auf SIGTERM reagiert und hart beendet
    // (timeout -k, seit 1.3.19 - bl_frist()).
    if ($code === 137) {
        return (trim($text) === '' ? '' : $text . "\n") . bl_t('TEST.ZEITGRENZE_HART');
    }
    if ($code === 127) {
        return bl_t('TEST.BEFEHL_FEHLT');
    }
    if ($code !== 0 && trim($text) === '') {
        return sprintf(bl_t('TEST.RUECKGABEWERT'), (int) $code);
    }
    return $text;
}

/**
 * Das JSON aus der Ausgabe eines Python-Werkzeugs holen.
 *
 * Ein eingebundenes Modul kann Zeilen VOR die Antwort schreiben. Genau das
 * ist am 13.09.2026 am Geraet passiert: bl_selbsttest.py bindet den Dienst
 * ein, dessen Protokoll ging nach stdout, und json_decode scheiterte an zwei
 * vorangestellten INFO-Zeilen - die Pruefzeile stand auf "nicht pruefbar"
 * und zeigte Protokolltext als Anmerkung. Das Protokoll geht seit 1.3.12
 * nach stderr; diese Funktion ist die zweite Sicherung, damit dieselbe
 * Klasse nicht beim naechsten Werkzeug wiederkommt.
 *
 * Gesucht wird zeilenweise von hinten - die Werkzeuge geben ihr JSON in
 * einer Zeile aus.
 */
function bl_json_aus($text)
{
    $zeilen = preg_split('/\R/', trim((string) $text));
    foreach (array_reverse($zeilen) as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '' || $zeile[0] !== '{') {
            continue;
        }
        $j = json_decode($zeile, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return null;
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
    @exec(bl_frist(60, 'python3 ' . escapeshellarg($datei) . ' ' . $argumente)
          . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    if ($code === 124 || $code === 137) {
        $text .= "\n" . bl_t($code === 124 ? 'TEST.ZEITGRENZE_HALB' : 'TEST.ZEITGRENZE_HART');
    }
    return array($code === 0 || $code === 1, $text);
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
        $py = bl_json_aus($ausgabe);
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
    $py = bl_json_aus($ausgabe);
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
 * Retain je Thema: stimmen beide Tabellen, und ist jedes gesendete Thema
 * eingeordnet?
 *
 * Drei Fragen in einer Zeile, weil sie zusammengehoeren:
 *   1. Ist die PHP-Tabelle Eintrag fuer Eintrag die der Python-Seite?
 *   2. Hat jeder Themenstamm, den der Sendecode wirklich benutzt, einen
 *      Eintrag? Ein Thema ohne Eintrag geht fluechtig hinaus - das ist die
 *      sichere Seite, aber es war dann niemandes Entscheidung.
 *   3. Sind die reservierten Zweignamen auf beiden Seiten dieselben?
 *
 * Geeicht, indem ein Eintrag aus einer der Tabellen genommen wird: die Zeile
 * muss rot werden und den Namen nennen.
 */
function bl_retain_vergleich()
{
    list($ok, $ausgabe) = bl_python('bl_lesen.py', '--vorgaben');
    if (!$ok) {
        return array(null, $ausgabe);
    }
    $py = bl_json_aus($ausgabe);
    if (!is_array($py) || !isset($py['retain'])) {
        return array(null, bl_kuerzen(trim($ausgabe), 200));
    }
    $php = bl_retain();
    $meldung = array();

    $nur_php = array_diff(array_keys($php), array_keys($py['retain']));
    $nur_py  = array_diff(array_keys($py['retain']), array_keys($php));
    if ($nur_php) { $meldung[] = sprintf(bl_t('TEST.NUR_PHP'), implode(', ', $nur_php)); }
    if ($nur_py)  { $meldung[] = sprintf(bl_t('TEST.NUR_PYTHON'), implode(', ', $nur_py)); }
    $andere = array();
    foreach ($php as $k => $v) {
        if (isset($py['retain'][$k]) && ((bool) $py['retain'][$k]) !== ((bool) $v)) {
            $andere[] = $k;
        }
    }
    if ($andere) {
        $meldung[] = sprintf(bl_t('TEST.RETAIN_ANDERS'), implode(', ', $andere));
    }

    // Jeder gesendete Stamm braucht einen Eintrag.
    $gesendet = bl_gesendete_themen();
    if ($gesendet === null) {
        $meldung[] = bl_t('TEST.SENDECODE_FEHLT');
    } else {
        $ohne = array();
        foreach ($gesendet as $thema) {
            $stamm = bl_thema_stamm($thema);
            if ($stamm !== '' && !array_key_exists($stamm, $php)) {
                $ohne[$stamm] = true;
            }
        }
        if ($ohne) {
            $meldung[] = sprintf(bl_t('TEST.RETAIN_OHNE_EINTRAG'),
                                 implode(', ', array_keys($ohne)));
        }
    }

    if (isset($py['reserviert'])
        && array_values($py['reserviert']) !== array_values(bl_reservierte_zweige())) {
        $meldung[] = bl_t('TEST.RESERVIERT_ANDERS');
    }

    $anzahl_r = count(array_filter($php));
    return array(count($meldung) === 0,
                 implode("\n", $meldung) . ($meldung ? "\n" : '')
                 . sprintf(bl_t('TEST.RETAIN_BILANZ'), $anzahl_r,
                           count($php) - $anzahl_r));
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
    // Themen, die der Sendecode nur unter Bedingungen kennt: bei
    // eingeschalteter Einstellung, je Person, je Scanner, oder - bei den
    // Messwerten - erst, wenn ein Tag sie einmal gesendet hat. Sie duerfen
    // deshalb HEUTE in der Vorlage fehlen.
    //
    // BERICHTIGT IN 1.3.14. Bis 1.3.13 hiess "bedingt" schlicht
    // "ignorieren" - und genau das hat den groessten Mangel dieser Linie
    // verdeckt: 'sensor/' stand auf dieser Liste, hatte aber NIRGENDS einen
    // Eintrag. bl_zusatzthemen() kannte keinen einzigen Messwert, die
    // Loxone-Vorlage fuehrte also weder Temperatur noch Luftfeuchte, und
    // diese Pruefung schwieg dazu - obwohl ihr eigener Kommentar oben genau
    // diesen Fall beschreibt. Eine Ausnahme, die einen echten Fehler stumm
    // schaltet, ist schlimmer als keine Pruefung: sie erzeugt Vertrauen.
    //
    // Jetzt wird zweierlei gefragt. Erstens weiterhin: darf es heute
    // fehlen? Zweitens neu: gibt es ueberhaupt einen Eintrag dafuer - in
    // bl_zusatzthemen_alle(), also unabhaengig von jeder Einstellung? Wenn
    // nicht, ist es kein bedingtes Thema, sondern ein vergessenes.
    $bedingt = array('distance', 'battery', 'battery_ts', 'raum', 'raum_seit',
                     'sensor/', 'person/', 'scanner/');
    $ueberhaupt = array_keys(bl_zusatzthemen_alle());
    $fehlt = array();
    foreach ($erwartet as $k) {
        if (!in_array($k, $gesendet, true)) {
            $fehlt[] = $k;
        }
    }
    $unbekannt = array();
    $ohne_eintrag = array();
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
            continue;
        }
        // Es ist bedingt - aber steht es irgendwo? 'person/' und 'scanner/'
        // sind eigene Zweige und werden nicht je Tag angelegt; fuer sie
        // gilt die Frage nicht.
        if (strpos($k, 'person/') === 0 || strpos($k, 'scanner/') === 0) {
            continue;
        }
        $gedeckt = false;
        foreach ($ueberhaupt as $e) {
            if ($e === $k || strpos($e, rtrim($k, '/') . '/') === 0
                || strpos(rtrim($k, '/'), rtrim($e, '/')) === 0) {
                $gedeckt = true;
                break;
            }
        }
        if (!$gedeckt) {
            $ohne_eintrag[] = $k;
        }
    }
    $meldung = array();
    if ($fehlt) {
        $meldung[] = sprintf(bl_t('TEST.THEMA_FEHLT'), implode(', ', $fehlt));
    }
    if ($unbekannt) {
        $meldung[] = sprintf(bl_t('TEST.THEMA_UNDOKUMENTIERT'), implode(', ', $unbekannt));
    }
    if ($ohne_eintrag) {
        $meldung[] = sprintf(bl_t('TEST.THEMA_OHNE_EINTRAG'),
                             implode(', ', $ohne_eintrag));
    }
    return array(count($meldung) === 0, implode("\n", $meldung));
}

/**
 * Wird SENSORTHEMEN aus bin/bl_beacon.py wirklich gelesen - und deckt sich
 * der eingebaute Rueckfall damit?
 *
 * Zwei Fragen in einer Zeile, weil sie zusammengehoeren:
 *
 *  1. Kommt die Liste ueberhaupt an? Ueber SENSORTHEMEN steht in
 *     bl_beacon.py der Satz "Die Oberflaeche und die Loxone-Vorlage lesen
 *     diese Liste". Bis 1.3.13 war das schlicht falsch - null PHP-Treffer.
 *     Seit 1.3.14 liest bl_sensorthemen_lesen() sie; wenn das je wieder
 *     aufhoert (umbenannt, anders geschrieben, Datei weg), wird diese Zeile
 *     rot statt still auf den Rueckfall zu wechseln.
 *  2. Stimmt der Rueckfall? Er greift nur, wenn die Datei fehlt - also
 *     genau dann, wenn ihn niemand pruefen kann. Deshalb wird er hier
 *     gegen die Quelle gehalten, solange beide da sind.
 *
 * Neu in 1.3.14.
 */
function bl_grenzen_vergleich()
{
    $datei = bl_paths()['bindir'] . '/bl_beacon.py';
    if (!is_file($datei)) {
        return array(null, bl_t('TEST.SENDECODE_FEHLT'));
    }
    $quelle = bl_sensorthemen_lesen();
    if (!$quelle) {
        return array(false, bl_t('TEST.GRENZEN_UNLESBAR'));
    }
    // Verglichen wird der EINGEBAUTE RUECKFALL gegen die Quelle - nicht der
    // Katalog: der liest die Quelle ja und waere mit ihr immer einig. Genau
    // so stand es hier im ersten Versuch, und die Zeile konnte nicht
    // ansprechen; beim Eichen fiel es auf (Grenze in der Quelle verstellt,
    // Zeile blieb gruen).
    $rueckfall = bl_sensor_rueckfall();
    $abweichung = array();
    foreach ($quelle as $name => $info) {
        if (!isset($rueckfall[$name])) {
            $abweichung[] = $name . ' (fehlt im Rückfall)';
            continue;
        }
        $r = $rueckfall[$name];
        if ((int) $r['min'] !== (int) $info['min']
            || (int) $r['max'] !== (int) $info['max']
            || (string) $r['einheit'] !== (string) $info['einheit']) {
            $abweichung[] = sprintf('%s (Rückfall %s..%s %s, bl_beacon.py %s..%s %s)',
                                    $name, $r['min'], $r['max'], $r['einheit'],
                                    $info['min'], $info['max'], $info['einheit']);
        }
    }
    foreach (array_keys($rueckfall) as $name) {
        if (!isset($quelle[$name])) {
            $abweichung[] = $name . ' (nur im Rückfall)';
        }
    }
    if ($abweichung) {
        return array(false, sprintf(bl_t('TEST.GRENZEN_ANDERS'),
                                    implode(', ', $abweichung)));
    }
    return array(true, sprintf(bl_t('TEST.GRENZEN_OK'), count($quelle)));
}

/**
 * Deckt sich FORMAT_GROESSEN mit dem, was die Dekoder wirklich sammeln?
 *
 * FORMAT_GROESSEN sagt der Loxone-Vorlage, welche Messwerte ein Tag mit
 * diesem Beaconformat liefern KANN - und ist damit eine von Hand gepflegte
 * Zusammenfassung dessen, was in den fuenf Dekodern steht. Genau so eine
 * Liste laeuft weg: jemand ergaenzt einen _sammle()-Aufruf und denkt nicht
 * an die Tabelle, und die Vorlage laesst einen Eingang aus.
 *
 * Gelesen werden hier die _sammle(out["werte"], "NAME", ...)-Aufrufe je
 * Dekoderfunktion aus bin/bl_beacon.py und gegen die Tabelle gehalten.
 * Neu in 1.3.14.
 */
function bl_formate_vergleich()
{
    $datei = bl_paths()['bindir'] . '/bl_beacon.py';
    if (!is_file($datei)) {
        return array(null, bl_t('TEST.SENDECODE_FEHLT'));
    }
    $quelle = (string) @file_get_contents($datei);
    $tabelle = bl_format_groessen();
    if (!$tabelle) {
        return array(false, bl_t('TEST.FORMATE_UNLESBAR'));
    }
    // Funktionsgrenzen bestimmen, damit ein _sammle() der richtigen
    // Dekoderfunktion zugeordnet wird.
    if (!preg_match_all('/^def (\w+)\(/m', $quelle, $m, PREG_OFFSET_CAPTURE)) {
        return array(false, bl_t('TEST.FORMATE_UNLESBAR'));
    }
    $grenzen = array();
    foreach ($m[1] as $i => $treffer) {
        $start = $treffer[1];
        $ende = isset($m[1][$i + 1]) ? $m[1][$i + 1][1] : strlen($quelle);
        $grenzen[$treffer[0]] = array($start, $ende);
    }
    $abweichung = array();
    foreach ($tabelle as $format => $soll) {
        if (!isset($grenzen[$format])) {
            $abweichung[] = $format . ' (keine Dekoderfunktion)';
            continue;
        }
        list($a, $e) = $grenzen[$format];
        $block = substr($quelle, $a, $e - $a);
        $ist = array();
        if (preg_match_all('/_sammle\(out\["werte"\],\s*"(\w+)"/', $block, $t)) {
            $ist = array_values(array_unique($t[1]));
        }
        sort($ist);
        $soll = array_values($soll);
        sort($soll);
        if ($ist !== $soll) {
            $abweichung[] = sprintf('%s (Tabelle: %s / Dekoder: %s)', $format,
                                    implode('+', $soll) ?: '-',
                                    implode('+', $ist) ?: '-');
        }
    }
    if ($abweichung) {
        return array(false, sprintf(bl_t('TEST.FORMATE_ANDERS'),
                                    implode('; ', $abweichung)));
    }
    return array(true, sprintf(bl_t('TEST.FORMATE_OK'), count($tabelle)));
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
 * Traegt jedes Formular sein Token, und zwar INNERHALB von <form>?
 *
 * DER ANLASS, am gerenderten HTML gemessen (13.09.2026): von 24 Formularen
 * trugen 18 kein Token. Der Aufruf "<?php echo bl_fmt(); ?>" stand jeweils auf
 * der Zeile NACH "</form>" - gueltiges HTML, sichtbar im Quelltext, und
 * vollkommen wirkungslos: ein verstecktes Feld ausserhalb eines Formulars wird
 * nicht mitgesendet. Der Wachposten in index.php leert dann $_POST und meldet
 * WACHE.FEHLT. In 1.3.11 stand es genauso, also hat dort KEIN einziger dieser
 * Knoepfe gearbeitet - und man sah es nicht, weil die Oberflaeche danach
 * aussieht wie vorher.
 *
 * Gemessen wird am QUELLTEXT von index.php, nicht an der eigenen Anzeige: die
 * Pruefzeile laeuft innerhalb derselben Seite und koennte ihr eigenes HTML
 * nicht vollstaendig sehen.
 *
 * Rueckgabe: array(anzahl_formulare, anzahl_ohne_token).
 */
function bl_token_lage()
{
    $datei = __DIR__ . '/index.php';
    $quelle = (string) @file_get_contents($datei);
    if ($quelle === '') {
        return array(-1, -1);
    }
    // Jedes <form> bis zu seinem </form>. Der Quelltext traegt PHP-Schnipsel
    // dazwischen - genau deshalb wird nach dem AUFRUF bl_fmt() gesucht und
    // nicht nach dem fertigen Feld.
    $formen = array();
    preg_match_all('/<form\b.*?<\/form>/s', $quelle, $formen);
    $ohne = 0;
    foreach ($formen[0] as $f) {
        if (strpos($f, 'bl_fmt()') === false && strpos($f, 'name="fmt"') === false) {
            $ohne++;
        }
    }
    return array(count($formen[0]), $ohne);
}

/**
 * Der Helfer, der Bluetooth einschaltet - EIN Pfad, an einer Stelle.
 *
 * Er liegt bewusst NICHT im Plugin-Ordner: postroot.sh schreibt ihn nach
 * /usr/local/sbin (root, 0755), und /etc/sudoers.d/ble_scanner_ng nennt genau
 * diesen Pfad ohne Argumente. Eine sudo-Regel auf eine Datei unter bin/ waere
 * ein Weg nach Root, weil dieses Verzeichnis loxberry gehoert.
 */
function bl_bt_helfer()
{
    return '/usr/local/sbin/ble_scanner_ng_bluetooth';
}

/**
 * Kennt der Geraetebaum ein Bluetooth-Geraet? (gleiche Regel wie in Python)
 */
function bl_bt_hardware()
{
    foreach (array('/proc/device-tree/soc/serial@*/bluetooth',
                   '/proc/device-tree/soc/*/bluetooth') as $muster) {
        $treffer = glob($muster, GLOB_ONLYDIR);
        if ($treffer) {
            return true;
        }
    }
    foreach ((array) glob('/sys/bus/serial/devices/*/modalias') as $pfad) {
        $inhalt = strtolower((string) @file_get_contents($pfad));
        if (strpos($inhalt, '-bt') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Sperrt eine modprobe-Blacklist die Bluetooth-Treiber?
 * Rueckgabe: array(datei, module) - datei ist '' wenn nichts gesperrt ist.
 */
function bl_bt_gesperrt()
{
    $interessant = array('bluetooth', 'hci_uart', 'btbcm', 'btusb', 'bnep');
    foreach ((array) glob('/etc/modprobe.d/*.conf') as $datei) {
        $gefunden = array();
        foreach (preg_split('/\R/', (string) @file_get_contents($datei)) as $zeile) {
            $teile = preg_split('/\s+/', trim($zeile));
            if (count($teile) >= 2 && $teile[0] === 'blacklist'
                && in_array($teile[1], $interessant, true)) {
                $gefunden[] = $teile[1];
            }
        }
        if ($gefunden) {
            return array($datei, $gefunden);
        }
    }
    return array('', array());
}

/**
 * Ist ueberhaupt ein Bluetooth-Adapter da, und laeuft bluetoothd?
 *
 * Diese Frage beantwortet KEIN Abbild des Dienstes - das entsteht ja erst,
 * wenn der Dienst laufen kann. Bis 1.3.11 fehlte die Zeile, und am
 * 13.09.2026 stand auf einem LoxBerry ohne Bluetooth in der Selbstpruefung
 * ein Strich ("noch keine Sichtung seit dem Start"), wo ein Kreuz mit Grund
 * hingehoert: systemd startet bluetooth.service bei fehlendem
 * /sys/class/bluetooth gar nicht (ConditionPathIsDirectory), und jede
 * Anfrage an org.bluez laeuft in die Aktivierungs-Zeitgrenze.
 *
 * Rueckgabe: array(zustand, anmerkung)
 */
function bl_adapterlage($cfg)
{
    $soll = bl_cfg($cfg, 'adapter', 'hci0');
    if (!is_dir('/sys/class/bluetooth')) {
        // "Kein Adapter" ist erst die halbe Antwort: fehlt die Hardware, oder
        // ist nur der Treiber gesperrt? Das eine braucht einen USB-Stecker,
        // das andere zwei Befehle. Am 13.09.2026 an dieser Anlage gemessen -
        // die Hardware war da, sechs blacklist-Zeilen hielten sie zurueck.
        if (bl_bt_hardware()) {
            list($datei, $module) = bl_bt_gesperrt();
            if ($datei !== '') {
                return array(false, sprintf(bl_t('PRUEF.ADAPTER_GESPERRT'),
                                            $datei, implode(', ', $module)));
            }
            return array(false, bl_t('PRUEF.ADAPTER_KEIN_TREIBER'));
        }
        return array(false, bl_t('PRUEF.ADAPTER_KEIN_GERAET'));
    }
    $vorhanden = array();
    foreach ((array) @scandir('/sys/class/bluetooth') as $e) {
        if ($e !== '.' && $e !== '..') { $vorhanden[] = $e; }
    }
    if (!$vorhanden) {
        return array(false, bl_t('PRUEF.ADAPTER_LEER'));
    }
    // systemctl is-active braucht kein sudo - am Geraet gemessen 13.09.2026:
    // das Wort "inactive" und Rueckgabewert 3 ohne erhoehte Rechte.
    $aktiv = trim(bl_sh('systemctl is-active bluetooth 2>/dev/null'));
    if (!in_array($soll, $vorhanden, true)) {
        return array(false, sprintf(bl_t('PRUEF.ADAPTER_ANDERER'),
                                    implode(', ', $vorhanden), $soll));
    }
    if ($aktiv !== '' && strpos($aktiv, 'active') !== 0) {
        return array(false, sprintf(bl_t('PRUEF.ADAPTER_DIENST_AUS'), $aktiv));
    }
    return array(true, implode(', ', $vorhanden)
                 . ($aktiv !== '' ? ' / bluetooth.service ' . $aktiv : ''));
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
        // Der Grund steht im Abbild (seit 1.3.12), damit hier nicht nur ein
        // Kreuz ohne Erklaerung stehen muss.
        $grund = isset($status['stoerung']) ? (string) $status['stoerung'] : '';
        $zeilen[] = bl_zeile(bl_t('PRUEF.ADAPTER_OK'), ((int) $status['adapter_ok']) === 1,
                             $grund !== '' ? bl_kuerzen($grund, 300)
                                           : (string) bl_cfg($cfg, 'adapter', 'hci0'));
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

    // --- Die Frage, die alles andere erledigt, steht VOR den Modulen.
    list($ok, $meldung) = bl_adapterlage($cfg);
    $zeilen[] = bl_zeile(bl_t('PRUEF.ADAPTER_DA'), $ok, $meldung);

    // Ein Formular ohne Token ist ein Knopf ohne Wirkung - und zwar still.
    list($bl_tf_zahl, $bl_tf_ohne) = bl_token_lage();
    $zeilen[] = bl_zeile(bl_t('PRUEF.TOKEN'),
        $bl_tf_zahl > 0 && $bl_tf_ohne === 0,
        $bl_tf_zahl < 0
            ? bl_t('PRUEF.TOKEN_UNLESBAR')
            : sprintf(bl_t('PRUEF.TOKEN_BILANZ'), $bl_tf_zahl,
                      $bl_tf_zahl - $bl_tf_ohne, $bl_tf_ohne));

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

    list($ok, $meldung) = bl_grenzen_vergleich();
    $zeilen[] = bl_zeile(bl_t('PRUEF.GRENZEN'), $ok, $meldung);

    list($ok, $meldung) = bl_formate_vergleich();
    $zeilen[] = bl_zeile(bl_t('PRUEF.FORMATE'), $ok, $meldung);

    list($ok, $meldung) = bl_retain_vergleich();
    $zeilen[] = bl_zeile(bl_t('PRUEF.RETAIN'), $ok, $meldung);

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
    $j = bl_json_aus($ausgabe);
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
            $t .= "--- bluetoothctl list ---\n" . bl_sh(bl_frist(8, 'bluetoothctl list')) . "\n\n";
            $t .= "--- bluetoothctl show ---\n" . bl_sh(bl_frist(8, 'bluetoothctl show')) . "\n\n";
            $t .= "--- bluetooth.service ---\n"
                . bl_sh('systemctl is-active bluetooth 2>/dev/null; systemctl is-enabled bluetooth 2>/dev/null') . "\n\n";
            $t .= "--- dmesg | grep -i blue ---\n"
                . bl_sh('dmesg 2>/dev/null | grep -i blue | tail -8') . "\n\n";
            $t .= "--- id loxberry ---\n" . bl_sh('id loxberry') . "\n";
            $t .= "\n" . bl_t('TEST.BT_HINWEIS');
            return array(bl_t('TEST.T_BLUETOOTH'), $t);

        case 'btein':
            // Eingebautes Bluetooth einschalten. Drei Dinge muessen stimmen,
            // und jedes wird EINZELN gesagt - "hat nicht geklappt" schickt
            // sonst auf die Suche an der falschen Stelle.
            $helfer = bl_bt_helfer();
            if (!is_file($helfer)) {
                // Ohne postroot.sh gibt es den Helfer nicht. Das passiert bei
                // einer Installation, die vor 1.3.12 gemacht wurde: die
                // sudo-Regel und der Helfer kommen erst beim naechsten
                // Einspielen dazu. Dann bleiben die Befehle als Text.
                return array(bl_t('TEST.T_BTEIN'),
                             sprintf(bl_t('TEST.BTEIN_OHNE_HELFER'), $helfer)
                             . "\n\n" . bl_t('TEST.BTEIN_VON_HAND'));
            }
            $t = sprintf(bl_t('TEST.BTEIN_RUFE'), $helfer) . "\n\n";
            $t .= bl_sh('sudo -n ' . escapeshellarg($helfer)) . "\n";
            // WIRKUNGSPRUEFUNG, nicht Zuversicht: nach dem Aufruf wird die
            // Lage neu gemessen. Der Helfer kann "geladen" melden und hci0
            // trotzdem ausbleiben - etwa wenn die Firmware fehlt.
            clearstatcache();
            list($da, $grund) = bl_adapterlage($cfg);
            $t .= "\n" . ($da ? bl_t('TEST.BTEIN_OK') : bl_t('TEST.BTEIN_NICHTS'))
                . "\n" . $grund . "\n";
            if ($da) {
                // Der Dienst hat beim Start vermutlich noch keinen Adapter
                // gesehen. Ohne diesen Hinweis wartet der Anwender auf Werte,
                // die erst nach einem Neustart des Dienstes kommen.
                $t .= "\n" . bl_t('TEST.BTEIN_DIENST') . "\n";
            }
            $t .= "\n" . bl_t('TEST.BTEIN_NUR_BIS_NEUSTART');
            return array(bl_t('TEST.T_BTEIN'), $t);

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
                // Seit 1.3.19 ueber fopen und stream_get_meta_data (Muster 14 der
                // Nachlese): die magische Kopfzeilenvariable, die file_get_contents
                // hinterlaesst, meldet PHP 8.5 schon beim Uebersetzen als
                // "Deprecated". wrapper_data traegt dieselben Zeilen - bei einer
                // Weiterleitung alle Antworten, die erste Zeile zuerst -, in
                // PHP 7.4 bis 8.5 gleich; mit ignore_errors oeffnet fopen auch
                // 4xx und 5xx. Vorher/nachher gleich gemessen
                // (Pruefung-BLE-Scanner-1.3.19, messe_http.sh).
                $antwort = false;
                $kopfzeile = '';
                $fh = @fopen($url, 'rb', false, $ctx);
                if ($fh !== false) {
                    $antwort = stream_get_contents($fh);
                    $meta = stream_get_meta_data($fh);
                    fclose($fh);
                    if (isset($meta['wrapper_data'][0]) && is_string($meta['wrapper_data'][0])) {
                        $kopfzeile = $meta['wrapper_data'][0];
                    }
                }
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
            $grund = bl_steuern('testmodus', $kennung, 60);
            return array(bl_t('TEST.T_TESTMODUS'),
                         $grund === '' ? sprintf(bl_t('TEST.TESTMODUS_LAEUFT'), $kennung)
                                       : $grund);

        case 'kalibrieren':
            $kennung = trim((string) $zusatz);
            if ($kennung === '') {
                return array(bl_t('TEST.T_KALIBRIEREN'), bl_t('TEST.TESTMODUS_OHNE_TAG'));
            }
            $grund = bl_steuern('kalibrierung', $kennung, 10);
            return array(bl_t('TEST.T_KALIBRIEREN'),
                         $grund === '' ? bl_t('TEST.KALIBRIERUNG_LAEUFT') : $grund);

        case 'batterie':
            $grund = bl_steuern('batterie');
            return array(bl_t('TEST.T_BATTERIE'),
                         $grund === '' ? bl_t('TEST.BATTERIE_ANGEFORDERT') : $grund);

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
