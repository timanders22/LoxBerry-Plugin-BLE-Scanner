<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie BLE-Scanner NG.
 *
 * Gruppe 13 / MQTT (1.3.28; Bauvorschrift wz_bild/GRUPPE13_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe13_bl.txt
 * Abschnitte 3.2-3.4, ENTSCHEIDUNGEN_GRUPPE13_BLE.md E1-E4; Muster vb_g12_db_bau/bin/befehle_zuordnung.php). Die Bruecke
 * selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur
 * diese Datei.
 *
 * Themen: <praefix>/befehl/testmodus <Zweig> | kalibrierung <Zweig> | batterie 1 (E1). <Zweig> ist der Themenzweig des
 * Tags, genau wie im Thema <praefix>/<Zweig>/... (Alias oder abgeleiteter Name) - die Adresse steht damit nie in einem
 * Loxone-Befehl. Art text statt Muster testmodus/{tag}: Ebenen der Stammfassung sind nur [a-z0-9_], Aliase duerfen
 * Grossbuchstaben und "-" tragen, und die Zuordnung wird nur beim Start der Bruecke geladen.
 *
 * tun() ruft bl_bb_tun() in webfrontend/htmlauth/bl_lib.php: Upgrade-Marke, laufender Dienst, Zweig -> Kennung bzw.
 * Batterielauf moeglich, dann bl_steuern() - DIESELBE Auftragsdatei <ramdisk>/<ordner>_steuer.json wie die Knoepfe im
 * Reiter Test (wartet, bis sie frei ist, E3). Kein Dienst-Neustart, kein HTTP auf sich selbst, kein Kindprozess.
 *
 * Suchlauf und Dienst start/neu/anhalten kommen NICHT auf die Bruecke (E2): der Suchlauf hat kein Ergebnis fuer Loxone und
 * blockierte 40 s; ein Dienststart aus dem Dauerprozess der Bruecke waere dessen Kind und erbte seine Pfeifen.
 *
 * Haken: "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) schaltet die Bruecke; der Batterielauf ist
 * eingreifend und braucht zusaetzlich "Eingreifende Befehle ueber MQTT annehmen" (befehle_eingreifend_ein, ab Werk aus).
 *
 * Die Bibliothek liegt unter webfrontend/htmlauth/: installiert <wurzel>/webfrontend/htmlauth/plugins/<ordner>/bl_lib.php,
 * im Archiv bzw. Pruefstand ../webfrontend/htmlauth/bl_lib.php. bl_paths() erkennt "installiert" am eigenen Ablageort -
 * die Bruecke (Cron als loxberry, ohne LBPPLUGINDIR) bekommt die Anlagenpfade ohne Umgebung.
 */
if (!function_exists('bl_bb_tun')) {
    $bl_bb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $bl_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/bl_lib.php';
    }
    $bl_bb_kand[] = dirname(__DIR__) . '/webfrontend/htmlauth/bl_lib.php';
    foreach ($bl_bb_kand as $bl_bb_k) {
        if (is_file($bl_bb_k)) { require_once $bl_bb_k; break; }
    }
}
/* Ein Eintrag: Art, Titel, Kachel, Quittung, Sperre. tun() reicht Name und Wert an bl_bb_tun(). */
$bl_bb_e = function ($name, $art, $titel, $kachel, $extra = array()) {
    return array_merge(array(
        'art' => $art, 'eingreifend' => false, 'titel' => 'BLE-Scanner NG ' . $titel . ' (MQTT)',
        'kachel' => $kachel, 'quittung' => 'ausgefuehrt',
        'tun' => function ($w, $t) use ($name) {
            return bl_bb_tun($name, (string) $w);
        }), $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'ble_scanner_ng',
    'plugin'  => 'BLE-Scanner NG',
    'zweiter_haken' => 'befehle_eingreifend_ein',
    'geladen' => function_exists('bl_bb_tun') && function_exists('bl_config_read')
                 && function_exists('bl_bb_einstellungen') && function_exists('bl_bb_protokoll'),
    'einstellungen' => function () {
        return bl_bb_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        bl_bb_protokoll($stufe, $text);
    },
    'befehle' => array(
        // Vorgabesperre 60 s: ein gleicher Auftrag binnen der Laufzeit (60 s Testmodus, 10 s Messung) bringt nichts Neues.
        'testmodus'    => $bl_bb_e('testmodus', 'text', 'Testmodus', 'BEFEHL.K_TESTMODUS', array('max_byte' => 40)),
        'kalibrierung' => $bl_bb_e('kalibrierung', 'text', 'Kalibrierung', 'BEFEHL.K_KALIBRIERUNG', array('max_byte' => 40)),
        'batterie'     => $bl_bb_e('batterie', 'puls', 'Batterielauf', 'BEFEHL.K_BATTERIE', array(
            'eingreifend' => true,
            'sperre_s'    => 3600,
            'begruendung' => 'Ein Batterielauf je Stunde genuegt; jeder weitere Lauf kostet Knopfzellen-Strom und '
                           . 'Anwesenheitsdaten. Eingreifend: der Lauf verbindet sich mit dem Anhaenger (GATT), haelt die '
                           . 'Suche an und laesst manche Schluesselfinder piepen - ein Befehl von aussen wirkt auf ein Geraet.',
        )),
    ),
);
