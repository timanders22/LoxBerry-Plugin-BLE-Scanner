<?php
/**
 * BLE-Scanner NG - Admin-Oberflaeche (v1.0.0)
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
/* Aktiver Reiter. Die Positivliste muss Zeichen fuer Zeichen zu den vier
 * id-Werten der Bereiche weiter unten passen - sonst springt die Seite nach
 * jedem Absenden zurueck auf Einstellungen, obwohl der Reiter sichtbar ist. */
$bl_tabliste = array('tab-settings', 'tab-loxone', 'tab-test', 'tab-log');
$bl_tab = 'tab-settings';
if (isset($_GET['tab']) && in_array('tab-' . (string) $_GET['tab'], $bl_tabliste, true)) {
    $bl_tab = 'tab-' . (string) $_GET['tab'];
}
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $bl_tabliste, true)) {
    $bl_tab = (string) $_POST['activetab'];
}

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
    // Der Verweis zeigt auf DIESES Repository, nicht auf die Wiki-Seite des
    // Originalplugins: die beschreibt eine andere Fassung mit anderer
    // Bedienung, und Rueckfragen dazu gehoeren nicht zum Originalautor.
    LBWeb::lbheader('BLE-Scanner NG', 'https://github.com/timanders22/LoxBerry-Plugin-BLE-Scanner', 'help.html');
}
?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 180px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-wrap a.sm-tab, .sm-wrap a.sm-tab:visited { text-decoration: none; display: inline-block; }
.sm-wrap a.sm-tab.sm-active, .sm-wrap a.sm-tab.sm-active:visited { color: #fff !important; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.sm-tbl th { background: #f0f0f0; }
.sm-tbl input[type=text] { padding: 5px 7px; }
.sm-bar { display: inline-block; height: 11px; border-radius: 3px; vertical-align: middle; }
.sm-l3 { background: #6dac20; width: 42px; }
.sm-l2 { background: #9ec84f; width: 28px; }
.sm-l1 { background: #cfcfcf; width: 14px; }
.sm-l0 { background: #e6e6e6; width: 8px; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($bl_saved) { ?>
<div class="sm-alert sm-ok"><b><?php echo bl_t('TEXT.GESPEICHERT'); ?></b> <?= $bl_hinweis ?></div>
<?php } ?>
<?php if ($bl_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo bl_t('TEXT.FEHLER'); ?></b> <?= $bl_error ?></div><?php } ?>
<?php if ($bl_altformat) { ?>
<div class="sm-alert sm-info"><?php echo bl_t('TEXT.DIE_KONFIGURATION_STAMMT_NOCH_AUS_'); ?></div>
<?php } ?>

<div class="sm-alert sm-info">
<?php echo bl_t('TEXT.DIENST'); ?> <b><?= $bl_pid ? bl_t('TEXT.LAEUFT') : bl_t('TEXT.LAEUFT_NICHT') ?></b><?= $bl_pid ? ' (PID ' . $bl_pid . ') ' : ' ' ?>
<?php echo bl_t('TEXT.TAGS'); ?> <b><?= count($bl_tags) ?></b> (<?= $bl_aktiv ?> <?php echo bl_t('TEXT.AKTIV'); ?>)
<?php if ($bl_status) { ?><?php echo bl_t('TEXT.TEXT'); ?> <?php echo bl_t('TEXT.ANWESEND_LABEL'); ?> <b><?= (int) ($bl_status['anwesend'] ?? 0) ?></b><?php } ?>
<?php echo bl_t('TEXT.ADAPTER'); ?> <span class="sm-mono"><?= bl_e(bl_cfg($bl_cfg, 'adapter', 'hci0')) ?></span>
<?php echo bl_t('TEXT.MQTT'); ?> <b><?= bl_cfg($bl_cfg, 'mqtt', '1') === '1' ? bl_t('TEXT.EIN') : bl_t('TEXT.AUS') ?></b>
<?php if ($bl_alter >= 0) { ?><?php echo bl_t('TEXT.STAND_VOR'); ?> <?= $bl_alter ?> s<?php } ?>
</div>

<div class="sm-tabs">
    <a class="sm-tab<?= $bl_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-pane="tab-settings" href="index.php?tab=settings"><?php echo bl_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab<?= $bl_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-pane="tab-loxone" href="index.php?tab=loxone"><?php echo bl_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab<?= $bl_tab === 'tab-test' ? ' sm-active' : '' ?>" data-pane="tab-test" href="index.php?tab=test"><?php echo bl_t('REITER.TEST'); ?></a>
    <a class="sm-tab<?= $bl_tab === 'tab-log' ? ' sm-active' : '' ?>" data-pane="tab-log" href="index.php?tab=log"><?php echo bl_t('REITER.LOG'); ?></a>
</div>

<!-- ================= Reiter: <?php echo bl_t('TEXT.EINSTELLUNGEN'); ?> ================= -->
<div class="sm-pane<?= $bl_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo bl_t('TEXT.TAGS_2'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;">
<?php echo bl_t('TEXT.JEDER_TAG_WIRD_BER_SEINE_BLUETOOTH'); ?> <b><?php echo bl_t('TEXT.AKTIVE'); ?></b> <?php echo bl_t('TEXT.TAGS_WERDEN_GEMELDET_ZUM_ENTFERNEN'); ?>
</div>
<table class="sm-tbl">
<tr><th style="width:170px;"><?php echo bl_t('TEXT.ADRESSE'); ?></th><th><?php echo bl_t('TEXT.BEZEICHNUNG'); ?></th>
<th style="width:70px;">aktiv</th><th style="width:160px;"><?php echo bl_t('TEXT.ZUSTAND'); ?></th></tr>
<?php foreach ($bl_tags as $i => $tag) {
    $z = $bl_zustand[$tag['mac']] ?? null; ?>
<tr>
<td><input data-role="none" type="text" name="tag_mac[<?= $i ?>]" value="<?= bl_e($tag['mac']) ?>"></td>
<td><input data-role="none" type="text" name="tag_name[<?= $i ?>]" value="<?= bl_e($tag['name']) ?>" placeholder="z.&nbsp;B. Schl&uuml;ssel Anna"></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="tag_aktiv[<?= $i ?>]" value="1"<?= $tag['aktiv'] === '1' ? ' checked' : '' ?>></td>
<td><?php if ($z) {
        $stufe = (int) $z['stufe'];
        echo '<i class="sm-bar sm-l' . $stufe . '"></i> ';
        echo $z['anwesend'] ? '<b>' . bl_t('TEXT.ANWESEND') . '</b>' : bl_t('TEXT.ABWESEND');
        if ($z['rssi'] !== null) { echo ' <span class="sm-small">' . (int) $z['rssi'] . ' dBm</span>'; }
    } else { echo '<span class="sm-small">&ndash;</span>'; } ?></td>
</tr>
<?php } ?>
<tr>
<td><input data-role="none" type="text" name="tag_mac[neu]" value="" placeholder="AA:BB:CC:DD:EE:FF"></td>
<td><input data-role="none" type="text" name="tag_name[neu]" value="" placeholder="von Hand hinzuf&uuml;gen"></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="tag_aktiv[neu]" value="1" checked></td>
<td><span class="sm-small">neu</span></td>
</tr>
</table>

<?php if ($bl_such && !empty($bl_such['geraete'])) { ?>
<h2><?php echo bl_t('TEXT.GEFUNDENE_GERTE'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;">
<?php echo bl_t('TEXT.QUELLE'); ?> <?= bl_e($bl_such['quelle']) ?> &middot; <?= (int) $bl_such['anzahl'] ?> <?php echo bl_t('TEXT.GERT_E_ANHAKEN_UND_SPEICHERN_BERNI'); ?>
</div>
<table class="sm-tbl">
<tr><th style="width:26px;"></th><th style="width:170px;">Adresse</th><th style="width:110px;"><?php echo bl_t('TEXT.SIGNAL'); ?></th>
<th><?php echo bl_t('TEXT.GEMELDETER_NAME'); ?></th><th>Bezeichnung</th></tr>
<?php foreach ($bl_such['geraete'] as $g) {
    if (!empty($g['bekannt'])) { continue; }
    $stufe = 1;
    if ($g['rssi'] !== null) {
        $stufe = (int) $g['rssi'] >= (int) bl_cfg($bl_cfg, 'rssi_nah', '-65') ? 3
               : ((int) $g['rssi'] >= (int) bl_cfg($bl_cfg, 'rssi_mittel', '-85') ? 2 : 1);
    } ?>
<tr>
<td><input data-role="none" type="checkbox" name="neu_mac[<?= bl_e($g['mac']) ?>]" value="1"></td>
<td><span class="sm-mono"><?= bl_e($g['mac']) ?></span></td>
<td><i class="sm-bar sm-l<?= $stufe ?>"></i> <span class="sm-small"><?= (int) $g['rssi'] ?> dBm</span></td>
<td><?= bl_e($g['name']) ?></td>
<td><input data-role="none" type="text" name="neu_name[<?= bl_e($g['mac']) ?>]" value="<?= bl_e($g['name']) ?>"></td>
</tr>
<?php } ?>
</table>
<?php } elseif ($bl_such) { ?>
<div class="sm-alert sm-info"><?php echo bl_t('TEXT.DER_SUCHLAUF_HAT_NICHTS_NEUES_GEFU'); ?> <?= bl_e($bl_such['quelle']) ?>).</div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo bl_t('LEGENDE.LESEN'); ?></span>
</div>
<button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="suchen" value="1"><?php echo bl_t('TEXT.GERTE_SUCHEN'); ?></button>
<div class="sm-small"><?php echo bl_t('TEXT.LUFT_DER_DIENST_WERDEN_DESSEN_SICH'); ?></div>

<h2><?php echo bl_t('TEXT.WEG_ZUM_MINISERVER'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= bl_cfg($bl_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b><?php echo bl_t('TEXT.MQTT_2'); ?></b> <?php echo bl_t('TEXT.EMPFOHLEN'); ?></label>
<div class="sm-small"><?php echo bl_t('TEXT.ZUSTNDE_GEHEN_RETAINED_AN_DEN_BROK'); ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="http_push" value="1"<?= bl_cfg($bl_cfg, 'http_push', '0') === '1' ? ' checked' : '' ?><?php echo bl_t('TEXT.ZUSTZLICH_VIRTUELLE_EINGNGE_PER_HT'); ?></label>
<div class="sm-small"><?php echo bl_t('TEXT.DER_WEG_DER_ORIGINALFASSUNG_BEI_JE'); ?>
<span class="sm-mono"><?php echo bl_t('TEXT.DEV_SPS_IO_KENNUNGBLE_MAC_0_1'); ?></span> <?php echo bl_t('TEXT.AUFGERUFEN_NUR_EINSCHALTEN_WENN_EI'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo bl_t('TEXT.KENNUNG_VOR_DEM_EINGANGSNAMEN'); ?></label>
<input data-role="none" type="text" name="loxberry_id" value="<?= bl_e(bl_cfg($bl_cfg, 'loxberry_id', '')) ?>">
<div class="sm-small"><?php echo bl_t('TEXT.NUR_FR_DEN_HTTP_WEG_MUSS_ZU_DEN_VO'); ?></div>
</div>
<div>
<label><?php echo bl_t('TEXT.MQTT_THEMENPRFIX'); ?></label>
<input data-role="none" type="text" name="themenpraefix" value="<?= bl_e($bl_praefix) ?>">
</div>
<div>
<label><?php echo bl_t('TEXT.BLUETOOTH_ADAPTER'); ?></label>
<input data-role="none" type="text" name="adapter" value="<?= bl_e(bl_cfg($bl_cfg, 'adapter', 'hci0')) ?>">
<div class="sm-small"><?php echo bl_t('TEXT.FAST_IMMER'); ?> <span class="sm-mono"><?php echo bl_t('TEXT.HCI0'); ?></span>.</div>
</div>
</div>

<h2><?php echo bl_t('TEXT.ZEITEN_UND_SCHWELLEN'); ?></h2>
<div class="sm-row">
<div>
<label><?php echo bl_t('TEXT.AUSWERTUNG_ALLE_SEKUNDEN'); ?></label>
<input data-role="none" type="number" name="intervall" min="2" max="600" value="<?= bl_e(bl_cfg($bl_cfg, 'intervall', '5')) ?>">
</div>
<div>
<label><?php echo bl_t('TEXT.ABWESEND_NACH_SEKUNDEN'); ?></label>
<input data-role="none" type="number" name="abwesenheit_nach" min="5" max="3600" value="<?= bl_e(bl_cfg($bl_cfg, 'abwesenheit_nach', '30')) ?>">
<div class="sm-small"><?php echo bl_t('TEXT.WIE_LANGE_EIN_TAG_NACH_DER_LETZTEN'); ?></div>
</div>
<div>
<label><?php echo bl_t('TEXT.ALLES_NEU_MELDEN_ALLE_SEKUNDEN'); ?></label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= bl_e(bl_cfg($bl_cfg, 'aktualisierung', '60')) ?>">
</div>
</div>
<div class="sm-row">
<div>
<label><?php echo bl_t('TEXT.SCHWELLE_NAH_IN_DBM'); ?></label>
<input data-role="none" type="number" name="rssi_nah" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'rssi_nah', '-65')) ?>">
</div>
<div>
<label><?php echo bl_t('TEXT.SCHWELLE_MITTEL_IN_DBM'); ?></label>
<input data-role="none" type="number" name="rssi_mittel" min="-120" max="0" value="<?= bl_e(bl_cfg($bl_cfg, 'rssi_mittel', '-85')) ?>">
<div class="sm-small"><?php echo bl_t('TEXT.GRER_ALS_65_HEIT_NAH_GRER_ALS_85_M'); ?></div>
</div>
</div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?php echo bl_t('TEXT.SPEICHERN'); ?></button>
<div class="sm-small"><?php echo bl_t('TEXT.BEIM_SPEICHERN_WIRD_DER_DIENST_NEU'); ?></div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?= $bl_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">

<h2><?php echo bl_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<div class="sm-small"><?php echo bl_t('TEXT.DER_DIENST_SUCHT_LAUFEND_NACH_DEN_'); ?></div>
<div class="sm-step"><b><?php echo bl_t('TEXT.SCHRITT_1_TAGS_EINTRAGEN'); ?></b><br><br>
<?php echo bl_t('TEXT.IM_REITER'); ?> <i>Einstellungen</i><?php echo bl_t('TEXT.AM_EINFACHSTEN_BER'); ?> <i>Ger&auml;te suchen</i><?php echo bl_t('TEXT.ANHAKEN_SPEICHERN'); ?></div>
<div class="sm-step"><b><?php echo bl_t('TEXT.SCHRITT_2_ABO_IM_MQTT_GATEWAY_EINT'); ?></b><br><br>
<?php if (!function_exists('bl_hs_autostart')) { function bl_hs_autostart() { $h = getenv('LBHOMEDIR') ?: '/opt/loxberry'; $g = $h . '/config/system/general.json'; if (!is_file($g)) { return null; } $j = json_decode((string) @file_get_contents($g), true); if (!is_array($j) || !isset($j['Mqtt'])) { return null; } return !empty($j['Mqtt']['Gatewayautostart']); } } if (bl_hs_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo bl_t('TEXT.W_AUTOSTART'); ?></div><?php } ?>
<b><?php echo bl_t('TEXT.OHNE_DIESEN_EINTRAG_KOMMT_AM_MINIS'); ?></b> <?php echo bl_t('TEXT.EINZUTRAGEN_UNTER'); ?>
<i><?php echo bl_t('TEXT.SYSTEM_EINSTELLUNGEN_MQTT_GATEWAY_'); ?></i>:
<div class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= bl_e($bl_praefix) ?>/#</div></div>
<div class="sm-step"><b><?php echo bl_t('TEXT.SCHRITT_3_VORLAGE_EINLESEN'); ?></b><br><br>
<?php echo bl_t('TEXT.VORLAGE_HERUNTERLADEN_UNTEN_UND_IN'); ?>
<i><?php echo bl_t('TEXT.VORLAGE_EINFGEN'); ?></i><?php echo bl_t('TEXT.SIE_LEGT_DIE_VIRTUELLEN_EINGNGE_MI'); ?> <b><?php echo bl_t('TEXT.VON_HAND_ANGELEGT'); ?></b> <?php echo bl_t('TEXT.HEIT_EIN_EINGANG'); ?>
<span class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.MAC_OHNE_TRENNZEICHEN_PRESENT'); ?></span> <?php echo bl_t('TEXT.DAS_GATEWAY_ERSETZT_JEDEN_SCHRGSTR'); ?></div>
<div class="sm-step"><b><?php echo bl_t('TEXT.SCHRITT_4_KACHEL_IN_DER_APP'); ?></b><br><br>
<?php echo bl_t('TEXT.EINEN'); ?> <i><?php echo bl_t('TEXT.STATUS'); ?></i><?php echo bl_t('TEXT.BAUSTEIN_ANLEGEN_UND'); ?> <span class="sm-mono">v1</span> mit
<span class="sm-mono"><?php echo bl_t('TEXT.SUMMARY_PRESENT'); ?></span> <?php echo bl_t('TEXT.VERBINDEN_DAS_IST_DIE_ZAHL_DER_GER'); ?>
<span class="sm-mono"><?php echo bl_t('TEXT.V1_0_VON'); ?> <?= $bl_aktiv ?> <?php echo bl_t('TEXT.TAGS_DA'); ?></span>.</div>
<div class="sm-step"><b><?php echo bl_t('TEXT.SCHRITT_5_ANWESENHEIT_ENTPRELLEN'); ?></b><br><br>
<?php echo bl_t('TEXT.BLE_TAGS_SENDEN_NICHT_UNUNTERBROCH'); ?> <b><?php echo bl_t('TEXT.EIN_EINZELNER_VERPASSTER_EMPFANG_D'); ?></b> <?php echo bl_t('TEXT.DAS_PLUGIN_HAT_DAFR_DIE_EINSTELLUN'); ?> <i><?php echo bl_t('TEXT.ABWESEND_NACH'); ?></i><?php echo bl_t('TEXT.IM_MINISERVER_KOMMT_EINE_AUSSCHALT'); ?></div>

<div class="sm-small" style="margin-top:10px;">
<?php echo bl_t('TEXT.BROKER'); ?> <span class="sm-mono"><?= $bl_broker !== '' ? bl_e($bl_broker) : 'MQTT-Gateway nicht gefunden' ?></span>
<?php echo bl_t('TEXT.THEMENPRFIX'); ?> <span class="sm-mono"><?= bl_e($bl_praefix) ?></span>
</div>

<?php if (bl_cfg($bl_cfg, 'mqtt', '1') !== '1') { ?>
<div class="sm-alert sm-err"><?php echo bl_t('TEXT.MQTT_IST_IM_REITER_EINSTELLUNGEN_A'); ?></div>
<?php } ?>

<h2><?php echo bl_t('TEXT.VORLAGE'); ?></h2>
<?php if (!$bl_aktiv) { ?>
<div class="sm-alert sm-err"><?php echo bl_t('TEXT.KEIN_TAG_IST_AKTIV_DIE_VORLAGE_HTT'); ?></div>
<?php } else { ?>
<div class="sm-small"><?php echo bl_t('TEXT.FR'); ?> <b><?= $bl_aktiv ?></b> <?php echo bl_t('TEXT.AKTIVE_N_TAG_S_JE'); ?> <?= count(bl_status_themen()) ?> <?php echo bl_t('TEXT.EINGNGE_DAZU_DREI_ALLGEMEINE_ZUSAM'); ?> <?= 3 + $bl_aktiv * count(bl_status_themen()) ?> <?php echo bl_t('TEXT.EINTRGE'); ?></div>
<?php } ?>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo bl_t('LEGENDE.AKTION_DATEI'); ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="mqtt_in"><?php echo bl_t('TEXT.VORLAGE_EINGNGE'); ?></button>
</div>
</form>

<h2><?php echo bl_t('TEXT.ZUSTNDE_JE_TAG'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:22%;"><?php echo bl_t('TEXT.THEMA'); ?></th><th style="width:14%;">Art</th><th><?php echo bl_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php foreach (bl_status_themen() as $k => $info) { ?>
<tr><td><span class="sm-mono"><?= bl_e($k) ?></span></td><td><?= bl_e($info[1]) ?></td><td><?= $info[0] ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo bl_t('TEXT.VOLLSTNDIG_LAUTET_EIN_THEMA'); ?>
<span class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.MAC_OHNE_TRENNZEICHEN_ZUSTAND'); ?></span><?php echo bl_t('TEXT.DAZU'); ?> <span class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.SERVER_ONLINE'); ?></span>,
<span class="sm-mono"><?php echo bl_t('TEXT.SUMMARY_PRESENT_2'); ?></span> und <span class="sm-mono"><?php echo bl_t('TEXT.SUMMARY_TAGS'); ?></span>.</div>

<?php if ($bl_tags) { ?>
<h2><?php echo bl_t('TEXT.THEMEN_DER_EINGETRAGENEN_TAGS'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:170px;">Adresse</th><th>Bezeichnung</th><th><?php echo bl_t('TEXT.THEMENZWEIG'); ?></th></tr>
<?php foreach ($bl_tags as $tag) { ?>
<tr><td><span class="sm-mono"><?= bl_e($tag['mac']) ?></span></td><td><?= bl_e($tag['name']) ?><?= $tag['aktiv'] === '1' ? '' : ' <span class="sm-small">' . bl_t('TEXT.NICHT_AKTIV') . '</span>' ?></td>
<td><span class="sm-mono"><?= bl_e($bl_praefix . '/' . bl_thema($tag['mac'])) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2><?php echo bl_t('TEXT.SCHRITT_6_KOMPLETTE_BAUSTEIN_LISTE'); ?></h2>
<div class="sm-small"><?php echo bl_t('TEXT.SO_SIEHT_DIE_VOLLSTNDIGE_LOGIK_AUF'); ?> <span class="sm-mono">&lt;T&gt;</span> <?php echo bl_t('TEXT.STEHT_FR_DEN_THEMENZWEIG_EINES_TAG'); ?></div>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo bl_t('TEXT.BAUSTEIN_TYP'); ?></th><th><?php echo bl_t('TEXT.NAME_VORSCHLAG'); ?></th><th><?php echo bl_t('TEXT.PARAMETER'); ?></th><th><?php echo bl_t('TEXT.EINGNGE_VERBINDEN_MIT'); ?></th></tr>
<tr><td>1</td><td><?php echo bl_t('TEXT.VIRTUELLER_EINGANG'); ?></td><td class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.SERVER_ONLINE_2'); ?></td><td><?php echo bl_t('TEXT.DIGITAL'); ?></td><td><?php echo bl_t('TEXT.KOMMT_BER_DAS_GATEWAY'); ?></td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.SUMMARY_PRESENT_3'); ?></td><td><?php echo bl_t('TEXT.ANALOG_ANZAHL'); ?></td><td><?php echo bl_t('TEXT.TEXT_2'); ?></td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.T_PRESENT'); ?></td><td><?php echo bl_t('TEXT.DIGITAL_JE_TAG_EINER'); ?></td><td>&mdash;</td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.T_RSSI'); ?></td><td><?php echo bl_t('TEXT.ANALOG_DBM'); ?></td><td>&mdash;</td></tr>
<tr><td>5</td><td>Virtueller Eingang</td><td class="sm-mono"><?= bl_e($bl_praefix) ?><?php echo bl_t('TEXT.T_LAST_SEEN'); ?></td><td><?php echo bl_t('TEXT.ANALOG_SEKUNDEN'); ?></td><td>&mdash;</td></tr>
<tr><td>6</td><td><?php echo bl_t('TEXT.AUSSCHALTVERZGERUNG'); ?></td><td><?php echo bl_t('TEXT.SCHLSSEL_DA_ENTPRELLT'); ?></td><td><?php echo bl_t('TEXT.120S_JE_SPARSAMER_DAS_TAG_DESTO_LN'); ?></td><td><?php echo bl_t('TEXT.EINGANG_3'); ?></td></tr>
<tr><td>7</td><td><?php echo bl_t('TEXT.ODER'); ?></td><td><?php echo bl_t('TEXT.JEMAND_IST_ZU_HAUSE'); ?></td><td><?php echo bl_t('TEXT.EINE_QUELLE_JE_PERSON'); ?></td><td><?php echo bl_t('TEXT.I1IN_JE_EIN_6'); ?></td></tr>
<tr><td>8</td><td><?php echo bl_t('TEXT.FLANKENERKENNUNG_FALLEND'); ?></td><td><?php echo bl_t('TEXT.LETZTER_IST_GEGANGEN'); ?></td><td>&mdash;</td><td><?php echo bl_t('TEXT.EINGANG_7'); ?></td></tr>
<tr><td>9</td><td><?php echo bl_t('TEXT.FLANKENERKENNUNG_STEIGEND'); ?></td><td><?php echo bl_t('TEXT.ERSTER_IST_GEKOMMEN'); ?></td><td>&mdash;</td><td>Eingang = #7</td></tr>
<tr><td>10</td><td><?php echo bl_t('TEXT.NICHT'); ?></td><td><?php echo bl_t('TEXT.DIENST_ANTWORTET_NICHT'); ?></td><td>&mdash;</td><td><?php echo bl_t('TEXT.EINGANG_1'); ?></td></tr>
<tr><td>11</td><td><?php echo bl_t('TEXT.EINSCHALTVERZGERUNG'); ?></td><td><?php echo bl_t('TEXT.AUSFALL_BESTTIGT'); ?></td><td><?php echo bl_t('TEXT.900S'); ?></td><td><?php echo bl_t('TEXT.EINGANG_10_BENACHRICHTIGUNG'); ?></td></tr>
<tr><td>12</td><td><?php echo bl_t('TEXT.UND_NICHT'); ?></td><td><?php echo bl_t('TEXT.ABWESENHEIT_GILT'); ?></td><td>&mdash;</td><td>I1 = #8, I2 = #10 <b><?php echo bl_t('TEXT.INVERTIERT'); ?></b></td></tr>
<tr><td>13</td><td>Status</td><td><?php echo bl_t('TEXT.ANWESENHEIT'); ?></td><td><?php echo bl_t('TEXT.STATUSTEXT_SIEHE_SCHRITT4_VISUALIS'); ?></td><td>v1 = #2</td></tr>
</table>
<div class="sm-alert sm-info">
<b><?php echo bl_t('TEXT.ZU_12_DIE_WICHTIGSTE_ZEILE'); ?></b> <?php echo bl_t('TEXT.FLLT_DER_DIENST_AUS_MELDEN_ALLE_TA'); ?><br>
<b>Zu #6:</b> <?php echo bl_t('TEXT.DIE_AUSSCHALTVERZGERUNG_KOMMT'); ?> <i><?php echo bl_t('TEXT.ZUSTZLICH'); ?></i> <?php echo bl_t('TEXT.ZUR_EINSTELLUNG'); ?>
<i>Abwesend nach</i> <?php echo bl_t('TEXT.IM_PLUGIN_BEIDE_ZUSAMMEN_ERGEBEN_D'); ?><br>
<b>Zu #11:</b> <?php echo bl_t('TEXT.EIN_BENACHRICHTIGUNGS_BAUSTEIN_SEN'); ?>
</div>

<h2><?php echo bl_t('TEXT.WORAUF_MAN_SICH_NICHT_VERLASSEN_KA'); ?></h2>
<div class="sm-small">
<?php echo bl_t('TEXT.VIELE_MODERNE_GERTE_MOBILTELEFONE_'); ?> <b><?php echo bl_t('TEXT.NICHT_2'); ?></b> <?php echo bl_t('TEXT.ZUR_ANWESENHEITSERKENNUNG_DIE_EING'); ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?= $bl_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo bl_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo bl_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo bl_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo bl_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo bl_t('TEXT.ZUSTAND_DES_DIENSTES'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="tags"><?php echo bl_t('TEXT.ZUSTAND_DER_TAGS'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="sichtbar"><?php echo bl_t('TEXT.SICHTBARE_GERTE'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="themen"><?php echo bl_t('TEXT.MQTT_THEMEN_ANZEIGEN'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo bl_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="bluetooth"><?php echo bl_t('TEXT.BLUETOOTH_PRFEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?php echo bl_t('TEXT.KONFIGURATION_ANZEIGEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?php echo bl_t('TEXT.UMGEBUNG_UND_MODULE'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqttinfo"><?php echo bl_t('TEXT.MQTT_GATEWAY'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo bl_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?php echo bl_t('TEXT.DIENST_NEU_STARTEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?php echo bl_t('TEXT.DIENST_ANHALTEN'); ?></button></form>
</div>

<?php if ($bl_test_titel !== '') { ?>
<h2><?= bl_e($bl_test_titel) ?></h2>
<div class="sm-log"><?= bl_e($bl_test_text) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info" style="margin-top:18px;"><?php echo bl_t('TEXT.NOCH_NICHTS_ABGEFRAGT_DIE_AUSGABE_'); ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-pane<?= $bl_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo bl_t('TEXT.PROTOKOLL'); ?></h2>
<div class="sm-small">
<?php if ($bl_log !== '') { ?>
<?php echo bl_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= bl_e($bl_log) ?></span> <?php echo bl_t('TEXT.LOG_NEUESTE'); ?>
<?php } else { ?>
<?php echo bl_t('TEXT.LOG_LEER'); ?>
<?php } ?>
</div>
<?php if ($bl_zeilen) { ?>
<div class="sm-log"><?php foreach ($bl_zeilen as $z) { echo bl_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    var start = <?= json_encode($bl_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('sm-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.sm-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('sm-active', panes[i].id === id);
        }
        var felder = document.querySelectorAll('input[name="activetab"]');
        for (i = 0; i < felder.length; i++) {
            felder[i].value = id;
        }
    }
    /* Die Reiter sind ECHTE Verweise. Solange dieses Skript laeuft, wird der
       Klick abgefangen und nur umgeschaltet; ohne Skript folgt der Browser dem
       Verweis und baut die Seite neu auf. Beides fuehrt zum selben Reiter.
       Vorher waren es <div>-Bereiche: ohne JavaScript - oder wenn ein Skript
       weiter oben abbrach - war jeder Reiter ausser dem ersten unerreichbar,
       und die Seite sah aus, als fehle der halbe Inhalt. */
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function (ev) {
                ev.preventDefault();
                zeige(t.getAttribute('data-pane'));
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', t.getAttribute('href'));
                }
            });
        })(tabs[i]);
    }
    zeige(start);
})();
</script>
<?php
if ($bl_frame) {
    LBWeb::lbfooter();
}
