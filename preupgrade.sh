#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
# sudo -n -u loxberry setzt die Umgebung zurueck - ohne diesen
# Rueckfall zeigte $LBPDATA ins Nichts und der Pfad auf /<ordner>.
LBPDATA="${LBPDATA:-$5/data/plugins}"
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

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
# Upgrade ausweisen. Wer ihn liest, prueft deshalb sein Alter.
date +%s > "$MERK_UPGRADE" 2>/dev/null
chown loxberry:loxberry "$MERK_UPGRADE" 2>/dev/null
chmod 0644 "$MERK_UPGRADE" 2>/dev/null

PIDDATEI="$PDATA/dienst.pid"
P=""
if [ -f "$PIDDATEI" ]; then
    P=$(cat "$PIDDATEI" 2>/dev/null)
fi
if [ -z "$P" ] || ! kill -0 "$P" 2>/dev/null; then
    P=""
    for d in /proc/[0-9]*; do
        [ -r "$d/cmdline" ] || continue
        # Nullbytes durch Zeilenumbrueche ersetzen und argumentweise vergleichen.
        if tr '\0' '\n' < "$d/cmdline" 2>/dev/null \
             | grep -qx ".*/ble_scanner_ng\.py"; then
            P=$(basename "$d")
            break
        fi
    done
fi

if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
    echo "<INFO> Halte den laufenden BLE-Scanner NG an (PID $P)."
    kill "$P" 2>/dev/null
    i=0
    while [ $i -lt 15 ] && kill -0 "$P" 2>/dev/null; do
        sleep 1
        i=$((i + 1))
    done
    # Nummernrecycling ausschliessen, bevor mit -9 nachgesetzt wird.
    if kill -0 "$P" 2>/dev/null \
       && tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null | grep -q "ble_scanner_ng\.py"; then
        echo "<WARNING> Der Dienst reagierte nicht auf SIGTERM - er wird abgeschossen."
        kill -9 "$P" 2>/dev/null
    fi
    rm -f "$PIDDATEI"
    # Merker fuer postupgrade.sh: der Dienst LIEF und gehoert danach wieder
    # gestartet. Ohne diesen Merker wuerde ein bewusst angehaltener Dienst
    # nach jedem Update ungefragt wieder anlaufen.
    date +%s > "$MERK_LIEF" 2>/dev/null
    chown loxberry:loxberry "$MERK_LIEF" 2>/dev/null
    chmod 0644 "$MERK_LIEF" 2>/dev/null
else
    echo "<INFO> Es lief kein BLE-Scanner NG."
    rm -f "$MERK_LIEF" 2>/dev/null
fi

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

echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null

echo "<INFO> Backing up existing config files $PCONFIG/ -> $SICHER/"
cp -a "$PCONFIG/." "$SICHER/" 2>/dev/null \
    && echo "<OK> Konfiguration gesichert (Rechte 0700)."
# verlauf.csv waechst ueber Wochen und ergibt sich nicht neu - es liegt
# unter data/, und data/plugins/<x>/ raeumt der Installer bei jedem
# Update ab (plugininstall.pl :886 -> :1631).
[ -f "$PDATA/verlauf.csv" ] && cp -p "$PDATA/verlauf.csv" "$SICHER/verlauf.csv" 2>/dev/null

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> und ueberschreibt dabei die Datei des Nutzers.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-ble_scanner_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/ble_scanner_ng.cfg" ]; then
    cp -p "$NETZ_CFG/ble_scanner_ng.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ble_scanner_ng.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ble_scanner_ng.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
