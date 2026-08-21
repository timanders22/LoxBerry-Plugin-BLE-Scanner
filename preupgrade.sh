#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PDATA=$LBPDATA/$PDIR
PCONFIG=$LBPCONFIG/$PDIR

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
    mkdir -p "$PDATA" 2>/dev/null
    : > "$PDATA/lief_vor_update"
    chown loxberry:loxberry "$PDATA/lief_vor_update" 2>/dev/null
else
    echo "<INFO> Es lief kein BLE-Scanner NG."
    rm -f "$PDATA/lief_vor_update" 2>/dev/null
fi

# --- Sicherung der Konfiguration --------------------------------------------
#
# Der Sicherungsordner liegt unter data/, NICHT unter /tmp: /tmp ist auf dem
# LoxBerry eine Ramdisk, und /tmp ist fuer jeden lesbar - in
# ble_scanner_ng.cfg stehen MAC-Adressen und die Namen der ueberwachten
# Personen, also eine Anwesenheitsliste des Haushalts.
SICHER="$PDATA/upgrade_sicherung"

echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null

echo "<INFO> Backing up existing config files $PCONFIG/ -> $SICHER/"
cp -a "$PCONFIG/." "$SICHER/" 2>/dev/null \
    && echo "<OK> Konfiguration gesichert (Rechte 0700)."

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
