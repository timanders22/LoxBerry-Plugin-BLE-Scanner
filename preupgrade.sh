#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # NICHT der Auspackordner: $1 ist eine zehnstellige Zufallskennung; der Ordner steht in $6
              # bzw. im Arbeitsverzeichnis (Regeln/06 Z. 276-277; Gruppe 13, E4). Unbenutzt.
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
# EINE Wurzel (seit 1.3.20, Regeln/06; Pruefung 29.09.2026, B8). Bis 1.3.19
# galt die LB-Umgebung vor dem fuenften Argument, und NETZ_BASE weiter unten
# nahm $5 zuerst - zwei Wurzeln in einem Skript. Am Geraet sind beide gleich;
# ein Pruefstand am Geraet (/etc/environment setzt LB*) griff damit aber die
# Anlage an (in WSL gemessen, Fall W1: Anlagen-Dienst angehalten und neu
# gestartet, fassung.txt der Anlage umgeschrieben). Jetzt: $5 zuerst, sonst
# LBHOMEDIR, und alle Pfade daraus.
BASE="${5:-$LBHOMEDIR}"
LBHOMEDIR="$BASE"
LBPCONFIG="$BASE/config/plugins"
LBPDATA="$BASE/data/plugins"
# Seit 1.3.17: der volle Dienstpfad wird gebraucht, weil der Dienst
# argumentweise gesucht wird (argv[1] ist GENAU dieser Pfad).
LBPBIN="$BASE/bin/plugins"
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

# --- Ohne brauchbare Wurzel wird nichts angefasst ---------------------------
#
# NEU IN 1.3.19 (Muster 1 der Nachlese). Fehlten das fuenfte Argument und die
# Umgebung, lauteten die Pfade bis 1.3.18 "/data/plugins/...",
# "/config/plugins/..." - ab der Laufwerkswurzel; zeigte $5 auf einen Ordner
# ohne diese Unterordner, wurden sie dort angelegt (in WSL gemessen,
# Pruefung-BLE-Scanner-1.3.19, Faelle H1 bis H3). Jetzt: warnen statt
# vollziehen.
# --- Gemeinsame Hilfen (Gruppe 13, V9): bin/bl_haken.sh aus dem Auspackordner -----------------
# $6 ist der Auspackordner und zugleich das Arbeitsverzeichnis (plugininstall.pl: cd "$tempfolder" && ...);
# $1 ist nur eine zehnstellige Zufallskennung. Fehlt die Datei oder ist sie defekt, bricht preupgrade.sh
# ab: lieber kein Update als eines ohne Sicherung und Zweitschrift.
BLH="${6:-$(pwd)}/bin/bl_haken.sh"
if [ -f "$BLH" ] && sh -n "$BLH" 2>/dev/null && grep -qx 'BL_HAKEN_FASSUNG=1' "$BLH" 2>/dev/null; then
    BL_HAKEN_LADEN=1
    . "$BLH"
else
    echo "<ERROR> Die gemeinsamen Hilfen $BLH fehlen oder sind defekt - preupgrade.sh bricht ab."
    exit 1
fi
# B7 (Gruppe 13): dieselbe Torbedingung wie preinstall.sh - auch config/system/general.json und ein Ordnername
# ohne "/" und "..". Bis 1.3.27 genuegten hier config/plugins und data/plugins; in einem Pruefbaum ohne
# general.json legte preinstall nichts beiseite, postinstall spielte aber die Zweitschrift zurueck.
if ! bl_wurzel_gilt "$BASE" "$PDIR"; then
    echo "<WARNING> Keine brauchbare LoxBerry-Wurzel (Ordner '$PDIR', Wurzel '$BASE', general.json"
    echo "<WARNING> noetig) - dieses Skript tut nichts."
    exit 0
fi

PDATA=$LBPDATA/$PDIR
PCONFIG=$LBPCONFIG/$PDIR

# --- Die beiden Merker liegen NEBEN dem Datenordner -------------------------
#
# BERICHTIGT IN 1.3.14. Bis 1.3.13 lagen sie DARIN ("$PDATA/upgrade_laeuft"),
# und damit hat keiner von beiden je getragen: zwischen preupgrade.sh und
# postinstall.sh raeumt der Installer data/plugins/<ordner>/ restlos ab
# (plugininstall.pl: &purge_installation im Upgrade-Zweig, :886 -> :1629 ff.).
# Am Geraet am 13.09.2026 im Installationsprotokoll Zeile fuer Zeile belegt:
#
#     13:00:06  Plugin is already installed -> proceeding with upgrade
#     13:00:13  removed '.../data/plugins/ble_scanner_ng/upgrade_laeuft'
#     13:01:16  <INFO> Neuinstallation - der Dienst wird gestartet.
#     13:01:22  <INFO> Der Dienst lief vor dem Update nicht und wurde nicht
#               gestartet.
#
# Zwei Folgen, beide unerwuenscht: postinstall.sh hielt JEDES Upgrade fuer
# eine Neuinstallation und startete auch einen bewusst angehaltenen Dienst;
# und der Neustart nach einem Update lief nie an, weil "lief_vor_update" bei
# postupgrade.sh ebenfalls nie ankam. Damit war der Befund aus 1.3.13 (das
# "su loxberry -c", das als loxberry nicht geht) nur halb behoben - die
# Ursache war weg, der ausloesende Merker aber auch.
#
# Der Punkt im Namen ist der ganze Unterschied, genau wie beim
# Sicherungsordner weiter unten: "rm -rf .../<ordner>/" trifft den Nachbarn
# "<ordner>.upgrade_laeuft" nicht.
MERK_UPGRADE="$PDATA.upgrade_laeuft"
MERK_LIEF="$PDATA.lief_vor_update"

# --- Den laufenden Dienst zuerst anhalten -----------------------------------
#
# Was passiert, wenn man es nicht tut: LoxBerry ersetzt bin/*.py unter einem
# laufenden Python-Prozess. Der hat seinen Quelltext laengst geladen und
# laeuft unbeirrt mit der ALTEN Fassung weiter, bis irgendwann neu gestartet
# wird. Der Anwender sieht die neue Oberflaeche, waehrend im Hintergrund der
# alte Dienst arbeitet.
#
# BERICHTIGT IN 1.3.0: die Rueckfallebene ohne PID-Datei suchte mit
#
#     pgrep -o -f "[b]le_scanner.py"
#
# Das Muster heisst ble_scanner.py, der Dienst heisst ble_scanner_ng.py. Der
# Punkt steht fuer EIN beliebiges Zeichen, danach muesste sofort "py" folgen -
# tatsaechlich folgt "ng". Gemessen: null Treffer. Fehlte also die PID-Datei
# (nach einem Absturz, oder bei einem Dienst aus der Zeit vor 1.2.0), meldete
# dieses Skript "Es lief kein BLE-Scanner NG", waehrend er lief - genau der
# stille Fassungsversatz, den es verhindern soll.
#
# Gesucht wird jetzt argumentweise ueber /proc, so wie es die Oberflaeche
# schon lange tut. Das trifft weder einen Editor mit offener Datei noch ein
# grep auf dem Quelltext.
# Merker: dies ist ein UPGRADE. preupgrade.sh laeuft nur dann - gemessen am
# Quelltext des Installers (plugininstall.pl, Zeile 845: "if ($isupgrade)").
# postinstall.sh laeuft dagegen IMMER und darf den Dienst deshalb nur auf
# einer Neuinstallation starten; den Upgrade-Fall erledigt postupgrade.sh,
# nachdem es die Konfiguration zurueckgespielt hat.
#
# In den Merker kommt der Zeitpunkt, nicht nichts. Bricht ein Upgrade zwischen
# preupgrade.sh und postupgrade.sh ab, bleibt die Datei neben dem Ordner
# liegen - und wuerde eine spaetere echte Neuinstallation faelschlich als
# Upgrade ausweisen. postinstall/preinstall pruefen nur das Vorhandensein
# (Entscheidung 1 vom 29.09.); daemon und Waechter begrenzen auf eine Stunde;
# uninstall raeumt die Marke ab. (B3, Gruppe 13 - bis 1.3.27 stand hier, wer
# ihn lese, pruefe sein Alter.)
date +%s > "$MERK_UPGRADE" 2>/dev/null
chown loxberry:loxberry "$MERK_UPGRADE" 2>/dev/null
chmod 0644 "$MERK_UPGRADE" 2>/dev/null

# Gruppe 13 (3.5): die Befehlsbruecke NACH der Marke anhalten - befehlsbruecke.sh start und ihr Waechter pruefen
# dieselbe Marke und starten nicht, solange sie liegt (hoechstens 3600 s). Danach startet sie der Waechter in
# cron.01min binnen einer Minute (nur mit "Befehle ueber MQTT annehmen"). 9>&-: keine offene Sperre vererben.
BL_BB="$LBPBIN/$PDIR/befehlsbruecke.sh"
if [ -f "$BL_BB" ] && command -v bash >/dev/null 2>&1; then
    echo "<INFO> Befehlsbruecke: $(timeout 40 bash "$BL_BB" stop 2>&1 9>&- | head -n 1)"
fi

PIDDATEI="$PDATA/dienst.pid"
DIENST="$LBPBIN/$PDIR/ble_scanner_ng.py"

# bl_dienste_finden() und bl_dienste_beenden() steht seit 1.3.28 in bin/bl_haken.sh (Gruppe 13, V9; oben eingebunden) - bis 1.3.27 wortgleich in
# mehreren Hakenskripten.

BL_UID=$(id -u loxberry 2>/dev/null)
if [ -z "$BL_UID" ]; then
    echo "<WARNING> Benutzer loxberry nicht gefunden - der Dienst wurde nicht gesucht."
    BL_GEFUNDEN=0
    BL_UEBRIG=""
else
    bl_dienste_beenden "$DIENST" "$BL_UID"
fi

if [ -n "$BL_UEBRIG" ]; then
    echo "<WARNING> Der Dienst laesst sich nicht beenden (PID$BL_UEBRIG)."
fi

# SEIT 1.3.20 entscheidet postupgrade.sh nach dem SOLLMERKER
# ($PDATA.soll_laufen, "0" = im Reiter Einstellungen angehalten), nicht mehr
# danach, ob der Dienst gerade lief (Pruefung 29.09.2026, B1). Bis 1.3.19 legte
# dieses Skript dafuer "lief_vor_update" ab: ein abgestuerzter Dienst blieb
# danach ueber jedes Update hinweg aus - so am Geraet am 26.09.2026 ("Es lief
# kein BLE-Scanner NG", danach "wurde nicht gestartet"). Ein Rest der alten
# Fassung wird weggeraeumt.
rm -f "$MERK_LIEF" 2>/dev/null
if [ "$BL_GEFUNDEN" != 0 ]; then
    rm -f "$PIDDATEI"
else
    echo "<INFO> Es lief kein BLE-Scanner NG."
fi

# bl_cfg_traegt_inhalt() steht seit 1.3.28 in bin/bl_haken.sh (Gruppe 13, V9; oben eingebunden) - bis 1.3.27 wortgleich in
# mehreren Hakenskripten.

# --- Sicherung der Konfiguration --------------------------------------------
#
# Der Sicherungsordner liegt unter data/, NICHT unter /tmp: /tmp ist auf dem
# LoxBerry eine Ramdisk, und /tmp ist fuer jeden lesbar - in
# ble_scanner_ng.cfg stehen MAC-Adressen und die Namen der ueberwachten
# Personen, also eine Anwesenheitsliste des Haushalts.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$PDATA.upgrade_sicherung"
NEU="$SICHER.neu"

# BERICHTIGT IN 1.3.18. Bis 1.3.17 stand hier
#
#     rm -rf "$SICHER"; mkdir -p "$SICHER"; cp -a "$PCONFIG/." "$SICHER/"
#
# also: die vorhandene Sicherung faellt, BEVOR die neue steht. Bricht der Lauf
# in dieser Luecke ab - abgebrochener Installer, volle Karte, Stromausfall -
# und stoesst der Anwender das Upgrade danach erneut an, gibt es weder die
# alte noch eine neue Sicherung. Genau das macht purge_installation moeglich:
# der Datenordner ist dann weg, es gibt nichts Neues zu sichern, und der
# zweite Lauf loescht die einzige Abschrift des Standes.
#
# In WSL gemessen (18.09.2026, Pruefung-BLE-Scanner-1.3.18, Faelle d1 und d2):
# 2 von 3 Dateien mit Merkwort verloren - die ganze Konfiguration und
# verlauf.csv; uebrig blieb nur die Zweitschrift neben dem Konfigordner.
#
# Die Reihenfolge jetzt ist die von GardenaSmartSystem 1.2.10
# (preupgrade.sh:93 ff.; in derselben Lage gemessen: 0 von 3 verloren):
# in "$SICHER.neu" bauen -> Rueckgabewert UND Inhalt pruefen -> die alte nach
# "$SICHER.alt" schieben -> die neue an ihren Platz -> die alte wegraeumen.
# Scheitert irgendetwas davon, bleibt die alte Sicherung unangetastet.
#
# ERGAENZT IN 1.3.20 (Entscheidung 1 vom 29.09.2026, letzter Satz; Pruefung
# B3): eine Sicherung, die hier schon LIEGT, stammt aus einem FRUEHEREN Lauf
# (abgebrochenes Update, gescheitertes Zurueckspielen). Bis 1.3.19 spielte
# postupgrade.sh sie zurueck, wenn die neue Sicherung scheiterte - in WSL
# gemessen (Fall E8): eine Konfiguration vom 01.08. ersetzte die laufende, und
# das Protokoll meldete "Aktualisierung abgeschlossen". Jetzt wird sie VOR dem
# Neubau aus dem Rueckspielweg genommen: nach "$SICHER.vorher" - nicht
# geloescht, sie faellt erst, wenn die neue steht. Scheitert die neue, bleibt
# sie dort liegen und wird genannt; zurueckgespielt wird sie nie. Die
# Deinstallation raeumt sie ab.
VORHER="$SICHER.vorher"
if [ -d "$SICHER" ]; then
    rm -rf "${VORHER:?}" 2>/dev/null
    if mv "$SICHER" "$VORHER" 2>/dev/null; then
        echo "<INFO> Eine Sicherung aus einem frueheren Lauf lag noch da; sie wird nicht"
        echo "<INFO> zurueckgespielt und liegt bis zum Ende dieses Laufs unter $VORHER."
    else
        echo "<WARNING> Die Sicherung aus einem frueheren Lauf ($SICHER) liess sich nicht"
        echo "<WARNING> beiseitelegen - postupgrade.sh spielt sie womoeglich zurueck."
    fi
fi
echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$NEU" 2>/dev/null
mkdir -p "$NEU"
chmod 0700 "$NEU" 2>/dev/null

SICHER_OK=0
ZU_SICHERN=0
[ -d "$PCONFIG" ] && ZU_SICHERN=1
# verlauf.csv waechst ueber Wochen und ergibt sich nicht neu - es liegt
# unter data/, und data/plugins/<x>/ raeumt der Installer bei jedem
# Update ab (plugininstall.pl :886 -> :1631).
[ -f "$PDATA/verlauf.csv" ] && ZU_SICHERN=1

if [ "$ZU_SICHERN" = 1 ]; then
    CP_RC=0
    if [ -d "$PCONFIG" ]; then
        echo "<INFO> Backing up existing config files $PCONFIG/ -> $SICHER/"
        cp -a "$PCONFIG/." "$NEU/" 2>/dev/null || CP_RC=$?
    fi
    if [ -f "$PDATA/verlauf.csv" ]; then
        cp -p "$PDATA/verlauf.csv" "$NEU/verlauf.csv" 2>/dev/null || CP_RC=$?
    fi
    # Die WIRKUNG pruefen, nicht den Rueckgabewert allein (CLAUDE.md 2):
    # jede Datei byteweise in der neuen Sicherung.
    ABWEICHEND=""
    if [ -d "$PCONFIG" ]; then
        ABWEICHEND=$( { cd "$PCONFIG" && find . -type f | while IFS= read -r f; do
                          cmp -s "$f" "$NEU/$f" || printf '%s ' "${f#./}"
                      done; } 2>/dev/null || echo "(Konfiguration nicht lesbar)" )
    fi
    if [ -f "$PDATA/verlauf.csv" ] && ! cmp -s "$PDATA/verlauf.csv" "$NEU/verlauf.csv"; then
        ABWEICHEND="$ABWEICHEND verlauf.csv"
    fi
    if [ "$CP_RC" -eq 0 ] && [ -z "$ABWEICHEND" ]; then
        SICHER_OK=1
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT vollstaendig sichern"
        echo "<WARNING> (cp Rueckgabewert $CP_RC; nicht in der Sicherung: ${ABWEICHEND:-keine})."
    fi
else
    echo "<INFO> Weder Konfiguration noch Verlauf vorhanden - es gibt nichts zu sichern."
fi

if [ "$SICHER_OK" = 1 ]; then
    rm -rf "$SICHER.alt" 2>/dev/null
    if [ -d "$SICHER" ]; then mv "$SICHER" "$SICHER.alt" 2>/dev/null; fi
    if mv "$NEU" "$SICHER" 2>/dev/null; then
        rm -rf "$SICHER.alt" 2>/dev/null
        chmod 0700 "$SICHER" 2>/dev/null
        echo "<OK> Konfiguration gesichert (Rechte 0700)."
        # Die neue steht - jetzt erst faellt die aus dem frueheren Lauf.
        rm -rf "${VORHER:?}" 2>/dev/null
    else
        if [ -d "$SICHER.alt" ]; then mv "$SICHER.alt" "$SICHER" 2>/dev/null; fi
        rm -rf "$NEU" 2>/dev/null
        echo "<WARNING> Die neue Sicherung liess sich nicht an ihren Platz bringen."
        echo "<WARNING> Platz und Rechte in $LBPDATA pruefen."
    fi
else
    rm -rf "$NEU" 2>/dev/null
    if [ -d "$VORHER" ]; then
        echo "<WARNING> Die Sicherung aus einem frueheren Lauf wird NICHT zurueckgespielt;"
        echo "<WARNING> sie liegt unter $VORHER (die Deinstallation raeumt sie ab)."
    fi
fi

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> und ueberschreibt dabei die Datei des Nutzers.
NETZ_BASE="$BASE"
NETZ_PDIR="${3:-ble_scanner_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
NETZ_QUELLE="$NETZ_CFG/ble_scanner_ng.cfg"
NETZ_ZIEL="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ble_scanner_ng.cfg"

# BERICHTIGT IN 1.3.18 - drei Fehler in vier Zeilen.
#
# 1. "[ -s ]" fragte nur nach der GROESSE. Eine abgeschnittene Konfiguration
#    ist nicht leer, bestand die Pruefung und wurde ueber die heile
#    Zweitschrift kopiert - der einzige Rueckweg war fort, ohne eine Zeile im
#    Protokoll. In WSL gemessen (18.09.2026, Fall c1): 34 Byte verdraengten
#    958. Gefragt wird jetzt nach dem Inhalt.
# 2. "cp -p" oeffnet das Ziel mit O_TRUNC: die vorhandene Zweitschrift ist
#    SOFORT leer und wird erst danach gefuellt. Bricht es dazwischen ab, ist
#    weder die alte noch die neue da. Gemessen (Fall d5, "ulimit -f 0"):
#    958 Byte -> 0 Byte. Geschrieben wird jetzt daneben und erst nach der
#    byteweisen Gegenprobe umbenannt - dieselbe Bauart wie
#    bl_datei_schreiben() in bl_lib.php:560.
# 3. "<INFO> Zweitschrift der Einstellungen angelegt." stand auch dann im
#    Protokoll, wenn gar nichts kopiert worden war (im zweiten Lauf der
#    Messung d1 belegt) - eine Erfolgsmeldung ohne Wirkung.
if bl_cfg_traegt_inhalt "$NETZ_QUELLE"; then
    if cp -p "$NETZ_QUELLE" "$NETZ_ZIEL.neu" 2>/dev/null \
       && chmod 0600 "$NETZ_ZIEL.neu" 2>/dev/null \
       && cmp -s "$NETZ_QUELLE" "$NETZ_ZIEL.neu" \
       && mv "$NETZ_ZIEL.neu" "$NETZ_ZIEL" 2>/dev/null; then
        echo "<INFO> Zweitschrift der Einstellungen angelegt ($NETZ_ZIEL)."
    else
        rm -f "$NETZ_ZIEL.neu" 2>/dev/null
        echo "<WARNING> Die Zweitschrift liess sich nicht anlegen ($NETZ_ZIEL)."
        if [ -f "$NETZ_ZIEL" ]; then
            echo "<WARNING> Die bisherige Zweitschrift bleibt unangetastet."
        fi
    fi
elif [ -f "$NETZ_ZIEL" ]; then
    echo "<WARNING> $NETZ_QUELLE traegt keinen lesbaren Inhalt - die vorhandene"
    echo "<WARNING> Zweitschrift bleibt unveraendert ($NETZ_ZIEL)."
else
    echo "<INFO> Keine lesbaren Einstellungen vorhanden - keine Zweitschrift angelegt."
fi

# V1 (Gruppe 13): Zweitschrift von irk.json neben den Konfigurationsordner - wie die der Konfiguration, aber 0600
# (Identitaetsschluessel). Nur eine Datei, die als JSON-Objekt beginnt und endet; sonst bleibt die bisherige.
NETZ_IRK="$NETZ_CFG/irk.json"
NETZ_IRK_ZIEL="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.irk.json"
if [ -s "$NETZ_IRK" ] && [ "$(head -c 1 "$NETZ_IRK" 2>/dev/null)" = "{" ] \
   && tail -c 3 "$NETZ_IRK" 2>/dev/null | grep -q '}'; then
    if cp "$NETZ_IRK" "$NETZ_IRK_ZIEL.neu" 2>/dev/null \
       && chmod 0600 "$NETZ_IRK_ZIEL.neu" 2>/dev/null \
       && cmp -s "$NETZ_IRK" "$NETZ_IRK_ZIEL.neu" \
       && mv "$NETZ_IRK_ZIEL.neu" "$NETZ_IRK_ZIEL" 2>/dev/null; then
        echo "<INFO> Zweitschrift der IRK-Schluessel angelegt (0600)."
    else
        rm -f "$NETZ_IRK_ZIEL.neu" 2>/dev/null
        echo "<WARNING> Die Zweitschrift der IRK-Schluessel liess sich nicht anlegen."
    fi
fi

# Gruppe 33 (1.3.29): Zweitschrift von bindkey.json (Bind-Keys der Xiaomi-Thermometer) - wie irk.json, 0600.
NETZ_BK="$NETZ_CFG/bindkey.json"
NETZ_BK_ZIEL="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.bindkey.json"
if [ -s "$NETZ_BK" ] && [ "$(head -c 1 "$NETZ_BK" 2>/dev/null)" = "{" ] \
   && tail -c 3 "$NETZ_BK" 2>/dev/null | grep -q '}'; then
    if cp "$NETZ_BK" "$NETZ_BK_ZIEL.neu" 2>/dev/null \
       && chmod 0600 "$NETZ_BK_ZIEL.neu" 2>/dev/null \
       && cmp -s "$NETZ_BK" "$NETZ_BK_ZIEL.neu" \
       && mv "$NETZ_BK_ZIEL.neu" "$NETZ_BK_ZIEL" 2>/dev/null; then
        echo "<INFO> Zweitschrift der Bind-Keys angelegt (0600)."
    else
        rm -f "$NETZ_BK_ZIEL.neu" 2>/dev/null
        echo "<WARNING> Die Zweitschrift der Bind-Keys liess sich nicht anlegen."
    fi
fi

exit 0
