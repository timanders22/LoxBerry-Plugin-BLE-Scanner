#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Von den Pfadvariablen der LoxBerry-Vorlage sind nur die uebrig, die
# wirklich gebraucht werden. PCGI, PHTML, PTEMPL, PSBIN und PBIN wurden
# zugewiesen und nie gelesen.
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR

# Den laufenden Dienst zuerst anhalten - das fehlte bis 1.1.0 vollstaendig.
#
# Was wirklich passiert, wenn man es nicht tut: LoxBerry ersetzt bin/*.py
# unter einem laufenden Python-Prozess. Der hat seinen Quelltext laengst
# geladen und laeuft unbeirrt weiter - mit der ALTEN Fassung, bis irgendwann
# neu gestartet wird. Der Anwender sieht die neue Oberflaeche, waehrend im
# Hintergrund der alte Dienst arbeitet, und wundert sich, dass eine behobene
# Sache noch auftritt.
#
# Die oft genannte Sorge vor einem Kernel-Deadlock auf hci0 ist dagegen
# unbegruendet: BlueZ zaehlt Discovery-Anforderungen je D-Bus-Verbindung mit
# und raeumt sie auf, wenn die Verbindung wegfaellt. Der Schaden ist ein
# stiller Fassungsversatz, kein haengendes Geraet.
#
# Angehalten wird mit SIGTERM und Geduld: der Dienst nimmt in seinem
# Signalbehandler die Suche zurueck und meldet server/online=0 an den Broker.
# Wird er hart abgeschossen, bleibt im Broker ein retained '1' stehen, und
# Loxone glaubt weiter an einen laufenden Scanner.
PIDDATEI="$PDATA/dienst.pid"
P=""
if [ -f "$PIDDATEI" ]; then
    P=$(cat "$PIDDATEI" 2>/dev/null)
fi
if [ -z "$P" ]; then
    P=$(pgrep -o -f "[b]le_scanner.py" 2>/dev/null)
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
    if kill -0 "$P" 2>/dev/null && grep -qa "ble_scanner_ng.py" "/proc/$P/cmdline" 2>/dev/null; then
        echo "<WARNING> Der Dienst reagierte nicht auf SIGTERM - er wird abgeschossen."
        kill -9 "$P" 2>/dev/null
    fi
    rm -f "$PIDDATEI"
else
    echo "<INFO> Es lief kein BLE-Scanner NG."
fi

# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg. Und /tmp ist fuer
# jeden lesbar - in ble_scanner_ng.cfg stehen MAC-Adressen und die Namen der
# ueberwachten Personen, also eine Anwesenheitsliste des Haushalts.
# Geaendert am 10.08.2026 nach der Durchsicht aller Plugins.
SICHER="$PDATA/upgrade_sicherung"

echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null

echo "<INFO> Backing up existing config files $PCONFIG/ -> $SICHER/"
cp -a "$PCONFIG/." "$SICHER/" 2>/dev/null \
    && echo "<OK> Konfiguration gesichert (Rechte 0700)."

# Exit with Status 0

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-ble_scanner_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/ble_scanner_ng.cfg" ]; then
    cp -p "$NETZ_CFG/ble_scanner_ng.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ble_scanner_ng.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ble_scanner_ng.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
