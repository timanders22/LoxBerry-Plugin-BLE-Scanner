<?php
/**
 * BLE-Scanner - Admin-Oberflaeche (v1.0.0)
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Loest die alte Perl-CGI-Oberflaeche (index.cgi, HTML::Template,
 * settings.html, zwei Sprachdateien) ab. Alles auf Deutsch.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

require_once __DIR__ . '/bl_lib.php';

$bl_p = bl_paths();
if ($bl_p['home']) {
    $bl_sdk = $bl_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($bl_sdk)) {
        require_once $bl_sdk;
        require_once $bl_p['home'] . '/libs/phplib/loxberry_web.php';
    }
}

$bl_saved = false;
$bl_error = '';
$bl_hinweis = '';
$bl_such = null;
$bl_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

list($bl_cfg, $bl_tags, $bl_altformat) = bl_config_read();

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $aktive = array_filter($bl_tags, function ($t) { return $t['aktiv'] === '1'; });
    if (!$aktive) {
        $bl_error = 'Es ist kein Tag aktiv &mdash; die Vorlage h&auml;tte keine Eintr&auml;ge.';
        $bl_tab = 'tab-loxone';
    } else {
        list($name, $inhalt) = bl_vorlage($bl_cfg, $bl_tags);
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename=' . $name);
        header('Content-Length: ' . strlen($inhalt));
        echo $inhalt;
        exit;
    }
}

/* ============ Suchlauf ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suchen'])) {
    $skript = $bl_p['bindir'] . '/bl_discover.py';
    if (!is_file($skript)) {
        $bl_error = 'bl_discover.py nicht gefunden: ' . bl_e($skript);
    } else {
        $out = array();
        @exec('timeout 40 python3 ' . escapeshellarg($skript) . ' 2>&1', $out);
        $roh = trim(implode("\n", $out));
        $bl_such = @json_decode($roh, true);
        if (!is_array($bl_such)) {
            $bl_error = 'Der Suchlauf lieferte keine verwertbare Antwort: ' . bl_e(mb_substr($roh, 0, 400));
            $bl_such = null;
        } elseif (isset($bl_such['fehler'])) {
            $bl_error = bl_e($bl_such['fehler']) . ' &mdash; ' . bl_e($bl_such['hinweis'] ?? '');
            $bl_such = null;
        }
    }
    $bl_tab = 'tab-settings';
}

/* ============ Test-Aktionen ============ */
$bl_test_titel = '';
$bl_test_text = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    require_once __DIR__ . '/bl_test.php';
    list($bl_test_titel, $bl_test_text) = bl_test_ausfuehren((string) $_POST['test']);
    $bl_tab = 'tab-test';
}

/* ============ Speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = $bl_cfg;

    // Eingaben nie hart filtern - nur Steuerzeichen und Anfuehrungszeichen raus.
    $saeubern = function ($s) {
        $s = preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s);
        return trim($s);
    };
    $zahl = function ($wert, $vorgabe, $min, $max) {
        if (!is_numeric($wert)) {
            return (string) $vorgabe;
        }
        $n = (int) $wert;
        return ($n >= $min && $n <= $max) ? (string) $n : (string) $vorgabe;
    };

    $adapter = $saeubern($_POST['adapter'] ?? 'hci0');
    $neu['adapter'] = preg_match('/^hci[0-9]+$/', $adapter) ? $adapter : 'hci0';
    $praefix = preg_replace('/[^A-Za-z0-9_-]+/', '', $saeubern($_POST['themenpraefix'] ?? ''));
    $neu['themenpraefix']    = $praefix !== '' ? $praefix : 'blescanner';
    $neu['mqtt']             = isset($_POST['mqtt']) ? '1' : '0';
    $neu['http_push']        = isset($_POST['http_push']) ? '1' : '0';
    $neu['loxberry_id']      = $saeubern($_POST['loxberry_id'] ?? '');
    $neu['intervall']        = $zahl($_POST['intervall'] ?? '', 5, 2, 600);
    $neu['abwesenheit_nach'] = $zahl($_POST['abwesenheit_nach'] ?? '', 30, 5, 3600);
    $neu['aktualisierung']   = $zahl($_POST['aktualisierung'] ?? '', 60, 5, 86400);
    $neu['rssi_nah']         = $zahl($_POST['rssi_nah'] ?? '', -65, -120, 0);
    $neu['rssi_mittel']      = $zahl($_POST['rssi_mittel'] ?? '', -85, -120, 0);
    if ((int) $neu['rssi_mittel'] > (int) $neu['rssi_nah']) {
        // Vertauscht eingegeben - stillschweigend richtig herum drehen,
        // sonst waere die Signalstufe unbrauchbar.
        $tausch = $neu['rssi_nah'];
        $neu['rssi_nah'] = $neu['rssi_mittel'];
        $neu['rssi_mittel'] = $tausch;
    }

    // Tags einsammeln: bestehende Zeilen plus angehakte Fundstellen
    $tags = array();
    $gesehen = array();
    $macs   = isset($_POST['tag_mac']) && is_array($_POST['tag_mac']) ? $_POST['tag_mac'] : array();
    $namen  = isset($_POST['tag_name']) && is_array($_POST['tag_name']) ? $_POST['tag_name'] : array();
    $aktive = isset($_POST['tag_aktiv']) && is_array($_POST['tag_aktiv']) ? $_POST['tag_aktiv'] : array();
    foreach ($macs as $i => $roh) {
        $mac = bl_mac($roh);
        if ($mac === '' || isset($gesehen[$mac])) {
            continue;
        }
        $gesehen[$mac] = true;
        $tags[] = array(
            'mac'   => $mac,
            'aktiv' => !empty($aktive[$i]) ? '1' : '0',
            'name'  => $saeubern($namen[$i] ?? ''),
        );
    }
    // Aus dem Suchlauf uebernommene Geraete
    $neue = isset($_POST['neu_mac']) && is_array($_POST['neu_mac']) ? $_POST['neu_mac'] : array();
    $neue_namen = isset($_POST['neu_name']) && is_array($_POST['neu_name']) ? $_POST['neu_name'] : array();
    foreach ($neue as $mac_roh => $an) {
        $mac = bl_mac($mac_roh);
        if ($mac === '' || isset($gesehen[$mac])) {
            continue;
        }
        $gesehen[$mac] = true;
        $tags[] = array(
            'mac'   => $mac,
            'aktiv' => '1',
            'name'  => $saeubern($neue_namen[$mac_roh] ?? ''),
        );
    }

    if (bl_config_write($neu, $tags)) {
        $bl_saved = true;
        require_once __DIR__ . '/bl_test.php';
        bl_dienst('restart');
        $bl_hinweis = bl_dienst_pid()
            ? 'Der Dienst wurde neu gestartet.'
            : 'Der Dienst l&auml;uft nicht &mdash; siehe Reiter Logdateien.';
        list($bl_cfg, $bl_tags, $bl_altformat) = bl_config_read();
    } else {
        $bl_error = 'Die Konfigurationsdatei konnte nicht geschrieben werden: ' . bl_e($bl_p['config']);
    }
}

$bl_praefix = bl_cfg($bl_cfg, 'themenpraefix', 'blescanner');
$bl_pid = bl_dienst_pid();
$bl_status = bl_status();
$bl_alter = bl_status_alter();
$bl_broker = bl_mqtt_broker();
$bl_log = bl_log_file();
$bl_zeilen = bl_log_tail($bl_log);
$bl_aktiv = 0;
foreach ($bl_tags as $t) { if ($t['aktiv'] === '1') { $bl_aktiv++; } }
$bl_zustand = array();
if ($bl_status) {
    foreach (($bl_status['tags'] ?? array()) as $z) {
        $bl_zustand[$z['mac']] = $z;
    }
}

// WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall bl_-Praefix.
$bl_frame = class_exists('LBWeb', false);
if ($bl_frame) {
    LBWeb::lbheader('BLE-Scanner', 'https://wiki.loxberry.de/plugins/ble_scanner/start', 'help.html');
}
?>
<style>
.bl-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.bl-wrap, .bl-wrap * { text-shadow: none !important; }
.bl-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.bl-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.bl-wrap input[type=text], .bl-wrap input[type=number], .bl-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.bl-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.bl-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.bl-row { display: flex; gap: 12px; flex-wrap: wrap; }
.bl-row > div { flex: 1; min-width: 180px; }
.bl-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.bl-wrap .bl-btn, .bl-wrap a.bl-btn, .bl-wrap button { box-shadow: none !important; }
.bl-wrap a.bl-btn, .bl-wrap a.bl-btn:visited, .bl-wrap a.bl-btn:hover { color: #fff !important; text-decoration: none; }
.bl-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.bl-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.bl-err { background: #ffebee; border: 1px solid #ef9a9a; }
.bl-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.bl-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.bl-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.bl-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.bl-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.bl-tab.bl-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.bl-pane { display: none; padding-top: 4px; }
.bl-pane.bl-active { display: block; }
.bl-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.bl-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.bl-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.bl-tbl th, .bl-tbl td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.bl-tbl th { background: #f0f0f0; }
.bl-tbl input[type=text] { padding: 5px 7px; }
.bl-bar { display: inline-block; height: 11px; border-radius: 3px; vertical-align: middle; }
.bl-l3 { background: #6dac20; width: 42px; }
.bl-l2 { background: #9ec84f; width: 28px; }
.bl-l1 { background: #cfcfcf; width: 14px; }
.bl-l0 { background: #e6e6e6; width: 8px; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.bl-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.bl-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.bl-knopfreihe form { margin: 0; display: flex; }
.bl-knopfreihe .bl-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.bl-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.bl-legende span { display: inline-flex; align-items: center; gap: 6px; }
.bl-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.bl-btn.bl-b-lesen   { background: #6dac20; }
.bl-btn.bl-b-technik { background: #546e7a; }
.bl-btn.bl-b-aktion  { background: #e0620d; }
.bl-punkt.bl-b-lesen   { background: #6dac20; }
.bl-punkt.bl-b-technik { background: #546e7a; }
.bl-punkt.bl-b-aktion  { background: #e0620d; }
</style>
<div class="bl-wrap">

<?php if ($bl_saved) { ?>
<div class="bl-alert bl-ok"><b>Gespeichert.</b> <?= $bl_hinweis ?></div>
<?php } ?>
<?php if ($bl_error !== '') { ?><div class="bl-alert bl-err"><b>Fehler:</b> <?= $bl_error ?></div><?php } ?>
<?php if ($bl_altformat) { ?>
<div class="bl-alert bl-info">Die Konfiguration stammt noch aus der Originalfassung. Sie wird gelesen wie sie ist &mdash;
beim n&auml;chsten Speichern schreibt das Plugin sie ins neue Format um. Die Tags bleiben dabei erhalten.</div>
<?php } ?>

<div class="bl-alert bl-info">
Dienst: <b><?= $bl_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $bl_pid ? ' (PID ' . $bl_pid . ') ' : ' ' ?>
&middot; Tags: <b><?= count($bl_tags) ?></b> (<?= $bl_aktiv ?> aktiv)
<?php if ($bl_status) { ?>&middot; anwesend: <b><?= (int) ($bl_status['anwesend'] ?? 0) ?></b><?php } ?>
&middot; Adapter: <span class="bl-mono"><?= bl_e(bl_cfg($bl_cfg, 'adapter', 'hci0')) ?></span>
&middot; MQTT: <b><?= bl_cfg($bl_cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus' ?></b>
<?php if ($bl_alter >= 0) { ?>&middot; Stand vor <?= $bl_alter ?> s<?php } ?>
</div>

<div class="bl-tabs">
    <div class="bl-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="bl-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="bl-tab" data-pane="tab-test">Test</div>
    <div class="bl-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="bl-pane" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Tags</h2>
<div class="bl-small" style="margin-bottom:8px;">
Jeder Tag wird &uuml;ber seine Bluetooth-Adresse erkannt. Nur <b>aktive</b> Tags werden
gemeldet. Zum Entfernen die Adresse leeren und speichern.
</div>
<table class="bl-tbl">
<tr><th style="width:170px;">Adresse</th><th>Bezeichnung</th>
<th style="width:70px;">aktiv</th><th style="width:160px;">Zustand</th></tr>
<?php foreach ($bl_tags as $i => $tag) {
    $z = $bl_zustand[$tag['mac']] ?? null; ?>
<tr>
<td><input data-role="none" type="text" name="tag_mac[<?= $i ?>]" value="<?= bl_e($tag['mac']) ?>"></td>
<td><input data-role="none" type="text" name="tag_name[<?= $i ?>]" value="<?= bl_e($tag['name']) ?>" placeholder="z.&nbsp;B. Schl&uuml;ssel Anna"></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="tag_aktiv[<?= $i ?>]" value="1"<?= $tag['aktiv'] === '1' ? ' checked' : '' ?>></td>
<td><?php if ($z) {
        $stufe = (int) $z['stufe'];
        echo '<i class="bl-bar bl-l' . $stufe . '"></i> ';
        echo $z['anwesend'] ? '<b>anwesend</b>' : 'abwesend';
        if ($z['rssi'] !== null) { echo ' <span class="bl-small">' . (int) $z['rssi'] . ' dBm</span>'; }
    } else { echo '<span class="bl-small">&ndash;</span>'; } ?></td>
</tr>
<?php } ?>
<tr>
<td><input data-role="none" type="text" name="tag_mac[neu]" value="" placeholder="AA:BB:CC:DD:EE:FF"></td>
<td><input data-role="none" type="text" name="tag_name[neu]" value="" placeholder="von Hand hinzuf&uuml;gen"></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="tag_aktiv[neu]" value="1" checked></td>
<td><span class="bl-small">neu</span></td>
</tr>
</table>

<?php if ($bl_such && !empty($bl_such['geraete'])) { ?>
<h2>Gefundene Ger&auml;te</h2>
<div class="bl-small" style="margin-bottom:8px;">
Quelle: <?= bl_e($bl_such['quelle']) ?> &middot; <?= (int) $bl_such['anzahl'] ?> Ger&auml;t(e).
Anhaken und speichern &uuml;bernimmt sie in die Tag-Liste.
</div>
<table class="bl-tbl">
<tr><th style="width:26px;"></th><th style="width:170px;">Adresse</th><th style="width:110px;">Signal</th>
<th>gemeldeter Name</th><th>Bezeichnung</th></tr>
<?php foreach ($bl_such['geraete'] as $g) {
    if (!empty($g['bekannt'])) { continue; }
    $stufe = 1;
    if ($g['rssi'] !== null) {
        $stufe = (int) $g['rssi'] >= (int) bl_cfg($bl_cfg, 'rssi_nah', '-65') ? 3
               : ((int) $g['rssi'] >= (int) bl_cfg($bl_cfg, 'rssi_mittel', '-85') ? 2 : 1);
    } ?>
<tr>
<td><input data-role="none" type="checkbox" name="neu_mac[<?= bl_e($g['mac']) ?>]" value="1"></td>
<td><span class="bl-mono"><?= bl_e($g['mac']) ?></span></td>
<td><i class="bl-bar bl-l<?= $stufe ?>"></i> <span class="bl-small"><?= (int) $g['rssi'] ?> dBm</span></td>
<td><?= bl_e($g['name']) ?></td>
<td><input data-role="none" type="text" name="neu_name[<?= bl_e($g['mac']) ?>]" value="<?= bl_e($g['name']) ?>"></td>
</tr>
<?php } ?>
</table>
<?php } elseif ($bl_such) { ?>
<div class="bl-alert bl-info">Der Suchlauf hat nichts Neues gefunden (Quelle: <?= bl_e($bl_such['quelle']) ?>).</div>
<?php } ?>

<button data-role="none" class="bl-btn bl-b-lesen" type="submit" name="suchen" value="1">Ger&auml;te suchen</button>
<div class="bl-small">L&auml;uft der Dienst, werden dessen Sichtungen benutzt &mdash; sonst wird selbst
rund 12&nbsp;Sekunden gesucht. BLE-Ger&auml;te melden sich nur, wenn sie gerade werben; manche Tags tun das erst nach einem Tastendruck.</div>

<h2>Weg zum Miniserver</h2>
<label class="bl-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= bl_cfg($bl_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b>MQTT</b> &mdash; empfohlen</label>
<div class="bl-small">Zust&auml;nde gehen retained an den Broker. Nach einem Neustart des Miniservers steht die Anwesenheit sofort wieder da.</div>

<label class="bl-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="http_push" value="1"<?= bl_cfg($bl_cfg, 'http_push', '0') === '1' ? ' checked' : '' ?>> Zus&auml;tzlich virtuelle Eing&auml;nge per HTTP setzen</label>
<div class="bl-small">Der Weg der Originalfassung: bei jedem Wechsel wird
<span class="bl-mono">/dev/sps/io/&lt;Kennung&gt;BLE_&lt;MAC&gt;/&lt;0|1&gt;</span> aufgerufen.
Nur einschalten, wenn eine bestehende Loxone-Konfiguration daran h&auml;ngt.
Die Zugangsdaten gehen dabei im Authorization-Kopf mit, nicht mehr in der Adresse.</div>

<div class="bl-row" style="margin-top:12px;">
<div>
<label>Kennung vor dem Eingangsnamen</label>
<input data-role="none" type="text" name="loxberry_id" value="<?= bl_e(bl_cfg($bl_cfg, 'loxberry_id', '')) ?>">
<div class="bl-small">Nur f&uuml;r den HTTP-Weg. Muss zu den vorhandenen virtuellen Eing&auml;ngen passen.</div>
</div>
<div>
<label>MQTT-Themenpr&auml;fix</label>
<input data-role="none" type="text" name="themenpraefix" value="<?= bl_e($bl_praefix) ?>">
</div>
<div>
<label>Bluetooth-Adapter</label>
<input data-role="none" type="text" name="adapter" value="<?= bl_e(bl_cfg($bl_cfg, 'adapter', 'hci0')) ?>">
<div class="bl-small">Fast immer <span class="bl-mono">hci0</span>.</div>
</div>
</div>

<h2>Zeiten und Schwellen</h2>
<div class="bl-row">
<div>
<label>Auswertung alle &hellip; Sekunden</label>
<input data-role="none" type="number" name="intervall" min="2" max="600" value="<?= bl_e(bl_cfg($bl_cfg, 'intervall', '5')) ?>">
</div>
<div>
<label>Abwesend nach &hellip; Sekunden</label>
<input data-role="none" type="number" name="abwesenheit_nach" min="5" max="3600" value="<?= bl_e(bl_cfg($bl_cfg, 'abwesenheit_nach', '30')) ?>">
<div class="bl-small">Wie lange ein Tag nach der letzten Sichtung noch als anwesend gilt. Zu klein gew&auml;hlt flattert die Anwesenheit.</div>
</div>
<div>
<label>Alles neu melden alle &hellip; Sekunden</label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= bl_e(bl_cfg($bl_cfg, 'aktualisierung', '60')) ?>">
</div>
</div>
<div class="bl-row">
<div>
<label>Schwelle &bdquo;nah&ldquo; in dBm</label>
<input data-role="none" type="number" name="rssi_nah" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'rssi_nah', '-65')) ?>">
</div>
<div>
<label>Schwelle &bdquo;mittel&ldquo; in dBm</label>
<input data-role="none" type="number" name="rssi_mittel" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'rssi_mittel', '-85')) ?>">
<div class="bl-small">Gr&ouml;&szlig;er als &minus;65 hei&szlig;t nah, gr&ouml;&szlig;er als &minus;85 mittel, darunter schwach.</div>
</div>
</div>

<button data-role="none" class="bl-btn" type="submit" name="save" value="1">Speichern</button>
<div class="bl-small">Beim Speichern wird der Dienst neu gestartet.</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="bl-pane" id="tab-loxone">

<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<div class="bl-small">Der Dienst sucht laufend nach den eingetragenen Tags und meldet je Tag vier
Werte per MQTT (Schritt&nbsp;1 bis&nbsp;3). Im Miniserver wird daraus Anwesenheit &mdash; und die ist
nur so gut wie die Entprellung, siehe Schritt&nbsp;5.</div>
<div class="bl-step"><b>Schritt 1: Tags eintragen</b><br><br>
Im Reiter <i>Einstellungen</i>, am einfachsten &uuml;ber <i>Ger&auml;te suchen</i>, anhaken,
speichern.</div>
<div class="bl-step"><b>Schritt 2: Abo im MQTT-Gateway eintragen</b><br><br>
<b>Ohne diesen Eintrag kommt am Miniserver nichts an</b> &mdash; einzutragen unter
<i>System-Einstellungen &rarr; MQTT Gateway &rarr; Abonnements</i>:
<div class="bl-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= bl_e($bl_praefix) ?>/#</div></div>
<div class="bl-step"><b>Schritt 3: Vorlage einlesen</b><br><br>
Vorlage herunterladen (unten) und in Loxone Config einlesen: Rechtsklick auf den Miniserver &rarr;
<i>Vorlage einf&uuml;gen</i>. Sie legt die virtuellen Eing&auml;nge mit den richtigen Namen an; die
Werte liefert das Gateway. <b>Von Hand angelegt</b> hei&szlig;t ein Eingang
<span class="bl-mono"><?= bl_e($bl_praefix) ?>_&lt;MAC ohne Trennzeichen&gt;_present</span> &mdash;
das Gateway ersetzt jeden Schr&auml;gstrich durch einen Unterstrich.</div>
<div class="bl-step"><b>Schritt 4: Kachel in der App</b><br><br>
Einen <i>Status</i>-Baustein anlegen und <span class="bl-mono">v1</span> mit
<span class="bl-mono">summary_present</span> verbinden &mdash; das ist die Zahl der gerade
anwesenden Tags. Statustext zum Beispiel:
<span class="bl-mono">&lt;v1.0&gt; von <?= $bl_aktiv ?> Tags da</span>.</div>
<div class="bl-step"><b>Schritt 5: Anwesenheit entprellen</b><br><br>
BLE-Tags senden nicht ununterbrochen, sondern in Abst&auml;nden von Sekunden bis Minuten &mdash; je
sparsamer das Tag, desto seltener. <b>Ein einzelner verpasster Empfang darf keine Abwesenheit
ausl&ouml;sen.</b> Das Plugin hat daf&uuml;r die Einstellung <i>Abwesend nach</i>; im Miniserver
kommt eine Ausschaltverz&ouml;gerung dazu, damit auch ein kurzer Aussetzer des Dienstes selbst
nichts umwirft. Aufbau in Schritt&nbsp;6, Zeile 6.</div>

<div class="bl-small" style="margin-top:10px;">
Broker: <span class="bl-mono"><?= $bl_broker !== '' ? bl_e($bl_broker) : 'MQTT-Gateway nicht gefunden' ?></span>
&middot; Themenpr&auml;fix: <span class="bl-mono"><?= bl_e($bl_praefix) ?></span>
</div>

<?php if (bl_cfg($bl_cfg, 'mqtt', '1') !== '1') { ?>
<div class="bl-alert bl-err">MQTT ist im Reiter Einstellungen ausgeschaltet &mdash; die Vorlage liefert dann keine Werte.</div>
<?php } ?>

<h2>Vorlage</h2>
<?php if (!$bl_aktiv) { ?>
<div class="bl-alert bl-err">Kein Tag ist aktiv &mdash; die Vorlage h&auml;tte keine Eintr&auml;ge.</div>
<?php } else { ?>
<div class="bl-small">F&uuml;r <b><?= $bl_aktiv ?></b> aktive(n) Tag(s), je <?= count(bl_status_themen()) ?> Eing&auml;nge,
dazu drei allgemeine &mdash; zusammen <?= 3 + $bl_aktiv * count(bl_status_themen()) ?> Eintr&auml;ge.</div>
<?php } ?>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="bl-legende"><span><i class="bl-punkt bl-b-aktion"></i> L&ouml;st etwas aus &mdash; erzeugt eine Datei</span></div>
<div class="bl-knopfreihe">
<button data-role="none" class="bl-btn bl-b-aktion" type="submit" name="download" value="mqtt_in">Vorlage: Eing&auml;nge</button>
</div>
</form>

<h2>Zust&auml;nde je Tag</h2>
<table class="bl-tbl">
<tr><th style="width:22%;">Thema</th><th style="width:14%;">Art</th><th>Bedeutung</th></tr>
<?php foreach (bl_status_themen() as $k => $info) { ?>
<tr><td><span class="bl-mono"><?= bl_e($k) ?></span></td><td><?= bl_e($info[1]) ?></td><td><?= $info[0] ?></td></tr>
<?php } ?>
</table>
<div class="bl-small">Vollst&auml;ndig lautet ein Thema
<span class="bl-mono"><?= bl_e($bl_praefix) ?>/&lt;MAC ohne Trennzeichen&gt;/&lt;Zustand&gt;</span>.
Dazu <span class="bl-mono"><?= bl_e($bl_praefix) ?>/server/online</span>,
<span class="bl-mono">&hellip;/summary/present</span> und <span class="bl-mono">&hellip;/summary/tags</span>.</div>

<?php if ($bl_tags) { ?>
<h2>Themen der eingetragenen Tags</h2>
<table class="bl-tbl">
<tr><th style="width:170px;">Adresse</th><th>Bezeichnung</th><th>Themenzweig</th></tr>
<?php foreach ($bl_tags as $tag) { ?>
<tr><td><span class="bl-mono"><?= bl_e($tag['mac']) ?></span></td><td><?= bl_e($tag['name']) ?><?= $tag['aktiv'] === '1' ? '' : ' <span class="bl-small">(nicht aktiv)</span>' ?></td>
<td><span class="bl-mono"><?= bl_e($bl_praefix . '/' . bl_thema($tag['mac'])) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2>Schritt 6: Komplette Baustein-Liste zum 1:1-Nachbauen</h2>
<div class="bl-small">So sieht die vollst&auml;ndige Logik auf der Programmierseite aus (jede Zeile =
ein Baustein). <span class="bl-mono">&lt;T&gt;</span> steht f&uuml;r den Themenzweig eines Tags aus der
Tabelle oben. Alle Bausteine findet man in Loxone Config &uuml;ber die Baustein-Suche (F5):</div>
<table class="bl-tbl">
<tr><th>#</th><th>Baustein (Typ)</th><th>Name (Vorschlag)</th><th>Parameter</th><th>Eing&auml;nge verbinden mit</th></tr>
<tr><td>1</td><td>Virtueller Eingang</td><td class="bl-mono"><?= bl_e($bl_praefix) ?>_server_online</td><td>digital</td><td>&mdash; (kommt &uuml;ber das Gateway)</td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td class="bl-mono"><?= bl_e($bl_praefix) ?>_summary_present</td><td>analog, Anzahl</td><td>&mdash;</td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td class="bl-mono"><?= bl_e($bl_praefix) ?>_&lt;T&gt;_present</td><td>digital, je Tag einer</td><td>&mdash;</td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td class="bl-mono"><?= bl_e($bl_praefix) ?>_&lt;T&gt;_rssi</td><td>analog, dBm</td><td>&mdash;</td></tr>
<tr><td>5</td><td>Virtueller Eingang</td><td class="bl-mono"><?= bl_e($bl_praefix) ?>_&lt;T&gt;_last_seen</td><td>analog, Sekunden</td><td>&mdash;</td></tr>
<tr><td>6</td><td>Ausschaltverz&ouml;gerung</td><td>Schl&uuml;ssel da (entprellt)</td><td>120&nbsp;s &mdash; je sparsamer das Tag, desto l&auml;nger</td><td>Eingang = #3</td></tr>
<tr><td>7</td><td>ODER</td><td>Jemand ist zu Hause</td><td>eine Quelle je Person</td><td>I1&hellip;In = je ein #6</td></tr>
<tr><td>8</td><td>Flankenerkennung (fallend)</td><td>Letzter ist gegangen</td><td>&mdash;</td><td>Eingang = #7</td></tr>
<tr><td>9</td><td>Flankenerkennung (steigend)</td><td>Erster ist gekommen</td><td>&mdash;</td><td>Eingang = #7</td></tr>
<tr><td>10</td><td>NICHT</td><td>Dienst antwortet nicht</td><td>&mdash;</td><td>Eingang = #1</td></tr>
<tr><td>11</td><td>Einschaltverz&ouml;gerung</td><td>Ausfall best&auml;tigt</td><td>900&nbsp;s</td><td>Eingang = #10 &rarr; Benachrichtigung</td></tr>
<tr><td>12</td><td>UND + NICHT</td><td>Abwesenheit gilt</td><td>&mdash;</td><td>I1 = #8, I2 = #10 <b>invertiert</b></td></tr>
<tr><td>13</td><td>Status</td><td>Anwesenheit</td><td>Statustext siehe Schritt&nbsp;4, Visualisierung EIN</td><td>v1 = #2</td></tr>
</table>
<div class="bl-alert bl-info">
<b>Zu #12 &mdash; die wichtigste Zeile.</b> F&auml;llt der Dienst aus, melden alle Tags Abwesenheit.
Ohne diese Sperre f&auml;hrt das Haus in den Abwesenheitsbetrieb, obwohl alle da sind: Heizung
runter, Alarm scharf. Deshalb darf #8 nur wirken, solange #1 wahr ist.<br>
<b>Zu #6:</b> die Ausschaltverz&ouml;gerung kommt <i>zus&auml;tzlich</i> zur Einstellung
<i>Abwesend nach</i> im Plugin. Beide zusammen ergeben die Zeit, nach der eine Person als gegangen
gilt.<br>
<b>Zu #11:</b> ein Benachrichtigungs-Baustein sendet nur bei einem Wechsel von Aus auf Ein. Niemals
mehrere Quellen direkt an seinen Eingang legen &mdash; erst &uuml;ber einen ODER-Baustein
zusammenf&uuml;hren.
</div>

<h2>Worauf man sich nicht verlassen kann</h2>
<div class="bl-small">
Viele moderne Ger&auml;te &mdash; Mobiltelefone, Uhren, Kopfh&ouml;rer &mdash; wechseln ihre
Bluetooth-Adresse regelm&auml;&szlig;ig, damit man sie nicht verfolgen kann. Solche Ger&auml;te
taugen <b>nicht</b> zur Anwesenheitserkennung: die eingetragene Adresse gilt nur bis zum
n&auml;chsten Wechsel. Zuverl&auml;ssig sind einfache BLE-Beacons und Schl&uuml;sselfinder mit
fester Adresse. Ob eine Adresse fest ist, sieht man daran, dass sie &uuml;ber Tage
dieselbe bleibt.
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="bl-pane" id="tab-test">

<div class="bl-legende">
<span><i class="bl-punkt bl-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="bl-punkt bl-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="bl-punkt bl-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="bl-h3">Ansehen</h3>
<div class="bl-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-lesen" type="submit" name="test" value="status">Zustand des Dienstes</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-lesen" type="submit" name="test" value="tags">Zustand der Tags</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-lesen" type="submit" name="test" value="sichtbar">Sichtbare Ger&auml;te</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-lesen" type="submit" name="test" value="themen">MQTT-Themen anzeigen</button></form>
</div>

<h3 class="bl-h3">Technische Auskunft</h3>
<div class="bl-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-technik" type="submit" name="test" value="bluetooth">Bluetooth pr&uuml;fen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-technik" type="submit" name="test" value="konfig">Konfiguration anzeigen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-technik" type="submit" name="test" value="umgebung">Umgebung und Module</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-technik" type="submit" name="test" value="mqttinfo">MQTT-Gateway</button></form>
</div>

<h3 class="bl-h3">L&ouml;st etwas aus</h3>
<div class="bl-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-aktion" type="submit" name="test" value="restart">Dienst neu starten</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="bl-btn bl-b-aktion" type="submit" name="test" value="stop">Dienst anhalten</button></form>
</div>

<?php if ($bl_test_titel !== '') { ?>
<h2><?= bl_e($bl_test_titel) ?></h2>
<div class="bl-log"><?= bl_e($bl_test_text) ?></div>
<?php } else { ?>
<div class="bl-alert bl-info" style="margin-top:18px;">Noch nichts abgefragt. Die Ausgabe erscheint hier.</div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="bl-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="bl-small">
<?php if ($bl_log !== '') { ?>
Datei: <span class="bl-mono"><?= bl_e($bl_log) ?></span> &middot; neueste Zeile zuerst
<?php } else { ?>
Noch keine Protokolldatei vorhanden. Sie entsteht, sobald der Dienst das erste Mal l&auml;uft.
<?php } ?>
</div>
<?php if ($bl_zeilen) { ?>
<div class="bl-log"><?php foreach ($bl_zeilen as $z) { echo bl_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.bl-tab');
    var start = <?= json_encode($bl_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('bl-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.bl-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('bl-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function () { zeige(t.getAttribute('data-pane')); });
        })(tabs[i]);
    }
    zeige(start);
})();
</script>
<?php
if ($bl_frame) {
    LBWeb::lbfooter();
}
