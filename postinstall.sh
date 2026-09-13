#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PLOG=$LBPLOG/$PDIR       # Achtung: liegt auf einer Ramdisk
PCONFIG=$LBPCONFIG/$PDIR
PDATA=$LBPDATA/$PDIR
PBIN=$LBPBIN/$PDIR

# mkdir mit -p und in Anfuehrungszeichen: ohne -p meldet mkdir einen Fehler,
# sobald das Verzeichnis schon existiert - und genau das ist bei einer
# Installation ueber eine alte hinweg der Normalfall.
mkdir -p "$PLOG" "$PDATA" "$PCONFIG"
touch "$PLOG/$PSHNAME.log"
chown loxberry:loxberry "$PLOG/$PSHNAME.log"

# --- BLE-Scanner NG ---------------------------------------------------------
# Ausfuehrbar machen. Ohne das startet der Daemon beim Systemstart nicht.
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

# --- Gemeinsame Hilfen ------------------------------------------------------
#
# WARUM OHNE "su": dieses Skript laeuft bereits als loxberry. LoxBerry ruft
# postinstall.sh und postupgrade.sh mit "sudo -n -u loxberry" auf
# (plugininstall.pl, Zeile 1311 bzw. 1336); nur preroot und postroot laufen
# als root. Ein "su loxberry -c" verlangt dann ein Kennwort und scheitert mit
# "su: Authentication failure", Rueckgabewert 1 - am Geraet gemessen am
# 13.09.2026. Bis 1.3.11 stand genau das in postupgrade.sh: der Neustart nach
# einem Update hat deshalb NIE funktioniert, und weil die Zeile ihre Ausgabe
# umleitete, stand darueber auch nichts im Protokoll.
dienst_pid() {
    for d in /proc/[0-9]*; do
        [ -r "$d/cmdline" ] || continue
        if tr '\0' '\n' < "$d/cmdline" 2>/dev/null | grep -qx ".*/ble_scanner_ng\.py"; then
            basename "$d"
            return 0
        fi
    done
    return 1
}

dienst_starten() {
    mkdir -p "$PLOG" "$PDATA" 2>/dev/null
    nohup "$PBIN/ble_scanner_ng.py" >> "$PLOG/ble_scanner_ng.log" 2>&1 &
    echo $! > "$PDATA/dienst.pid"
    sleep 2
    # Geprueft wird die WIRKUNG, nicht der Rueckgabewert von nohup - der ist
    # immer 0.
    if P=$(dienst_pid); then
        echo "<OK> Der Dienst laeuft (PID $P)."
        return 0
    fi
    echo "<INFO> Der Dienst liess sich nicht starten. Das Protokoll steht im"
    echo "<INFO> Reiter Logdateien; starten laesst er sich dort im Reiter"
    echo "<INFO> Einstellungen mit 'Dienst starten'."
    return 1
}

# Fassungsnummer an EINE Stelle schreiben. bl_common.py und bl_lib.php lesen
# sie von hier; bis 1.2.10 stand sie an drei Stellen verschieden im Archiv.
if [ -n "$PVERSION" ]; then
    printf '%s\n' "$PVERSION" > "$PCONFIG/fassung.txt"
    chmod 0644 "$PCONFIG/fassung.txt" 2>/dev/null
    echo "<OK> Fassung $PVERSION vermerkt."
fi

# --- Bluetooth-Zugriff: messen, nicht erinnern ------------------------------
#
# BIS 1.3.11 STAND HIER EINE FALSCHAUSSAGE, und sie kostete zwei Warnungen bei
# jeder Installation. Das Plugin behauptete, BlueZ bringe eine Richtlinie fuer
# die Gruppe bluetooth mit - gemessen an bluez 5.64. Am 13.09.2026 an
# bluez 5.82-1.1+rpt2 (Raspberry Pi OS trixie) nachgemessen:
#
#     /usr/share/dbus-1/system.d/bluetooth.conf
#       <policy user="root">            ... alles
#       <policy context="default">      <allow send_destination="org.bluez"/>
#
# Eine Gruppenregel gibt es dort NICHT mehr; stattdessen darf JEDER Benutzer
# senden. Die Gruppe bluetooth existiert (gid 116) und ist leer - sie wird
# nicht gebraucht. Das Plugin warnte trotzdem, die Gruppenzuordnung genuege
# nicht: eine Falschaussage, die den Anwender auf die Suche nach einem
# Rechteproblem schickte, das es nicht gab.
#
# Und der zweite Teil derselben Warnung war ebenfalls falsch begruendet: das
# "usermod" konnte gar nicht gelingen, weil DIESES SKRIPT ALS LOXBERRY LAEUFT
# (plugininstall.pl ruft es mit "sudo -n -u loxberry"). Die Meldung riet auf
# "nicht als root?" - richtig, aber als Vermutung formuliert, wo es eine
# Gewissheit ist. Eine Gruppenzuordnung gehoert nach postroot.sh oder in die
# Anleitung; versucht wird sie hier nicht mehr.
#
# BEWUSST KEINE eigene Richtlinie unter /etc/dbus-1/system.d/: das waere eine
# systemweite Rechteaenderung durch ein Plugin, sie stuende beim naechsten
# BlueZ-Update neben der mitgelieferten - und sie ist nach dieser Messung
# ohnehin unnoetig.
BTCONF=""
for k in /etc/dbus-1/system.d/bluetooth.conf /usr/share/dbus-1/system.d/bluetooth.conf; do
    [ -f "$k" ] && BTCONF="$k" && break
done
if [ -z "$BTCONF" ]; then
    echo "<INFO> Keine D-Bus-Richtlinie fuer BlueZ gefunden. Ist bluez vollstaendig"
    echo "<INFO> installiert? Ohne sie kann der Dienst org.bluez nicht ansprechen."
elif grep -q 'context="default"' "$BTCONF" && grep -q 'send_destination="org.bluez"' "$BTCONF"; then
    echo "<OK> $BTCONF erlaubt den Zugriff auf org.bluez jedem Benutzer"
    echo "<OK> (<policy context=\"default\">) - eine Gruppenmitgliedschaft ist unnoetig."
elif grep -q 'group="bluetooth"' "$BTCONF"; then
    if id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx bluetooth; then
        echo "<OK> $BTCONF erlaubt den Zugriff der Gruppe bluetooth, und loxberry ist darin."
    else
        echo "<INFO> $BTCONF erlaubt den Zugriff nur der Gruppe bluetooth, und loxberry"
        echo "<INFO> ist nicht darin. Dieses Skript laeuft als loxberry und kann die"
        echo "<INFO> Gruppe nicht setzen. Einmal von Hand, dann ist es erledigt:"
        echo "<INFO>     sudo usermod -a -G bluetooth loxberry && sudo reboot"
    fi
else
    echo "<INFO> In $BTCONF steht weder eine Regel fuer alle Benutzer noch fuer eine"
    echo "<INFO> Gruppe. Der Reiter Test nennt den genauen Grund, wenn der Zugriff"
    echo "<INFO> abgewiesen wird."
fi

# --- Ist ueberhaupt Bluetooth da? ------------------------------------------
#
# Die Frage vor allen anderen. Fehlt /sys/class/bluetooth, startet systemd
# bluetooth.service gar nicht (ConditionPathIsDirectory), und jede Anfrage an
# org.bluez laeuft in die Aktivierungs-Zeitgrenze. Das ist keine
# Fehlfunktion des Plugins, sondern eine fehlende Voraussetzung - deshalb
# <INFO> und nicht <WARNING>.
if [ -d /sys/class/bluetooth ] && [ -n "$(ls -A /sys/class/bluetooth 2>/dev/null)" ]; then
    echo "<OK> Bluetooth-Adapter vorhanden: $(ls -A /sys/class/bluetooth | tr '\n' ' ')"
else
    echo "<INFO> Es ist KEIN Bluetooth-Adapter vorhanden: /sys/class/bluetooth fehlt"
    echo "<INFO> oder ist leer. Der Dienst startet und wartet, kann aber nichts"
    echo "<INFO> finden. Ein Raspberry Pi mit abgeschaltetem eingebautem Bluetooth"
    echo "<INFO> braucht einen USB-Adapter; der Reiter Test zeigt die Lage."
fi

# Pruefen, ob die Bausteine wirklich da sind. python3-gi traegt seit 1.3.0
# den Signalbetrieb - bis dahin wurde es verlangt, geprueft und nie benutzt.
for modul in dbus gi paho.mqtt.client; do
    if python3 -c "import $modul" >/dev/null 2>&1; then
        echo "<OK> Python-Modul $modul vorhanden."
    else
        echo "<WARNING> Python-Modul $modul fehlt."
        [ "$modul" = "gi" ] && echo "<WARNING> Ohne python3-gi faellt der Dienst auf den Abfragebetrieb zurueck."
    fi
done
if command -v bluetoothctl >/dev/null 2>&1; then
    echo "<OK> bluez ist vorhanden."
else
    echo "<WARNING> bluez fehlt. Nachinstallieren: sudo apt-get install -y bluez"
fi

# Eigentuemer richtigstellen. Die Installation laeuft als root; alles, was
# dabei entsteht, gehoerte danach root - und die Oberflaeche laeuft als
# loxberry und koennte die Konfiguration nicht mehr schreiben.
if id loxberry >/dev/null 2>&1; then
    for d in "$PCONFIG" "$PDATA" "$PLOG"; do
        [ -d "$d" ] && chown -R loxberry:loxberry "$d" 2>/dev/null
    done
    echo "<OK> Eigentuemer der Konfigurations-, Daten- und Protokollordner: loxberry."
fi

echo "<INFO> Naechster Schritt: Reiter Einstellungen -> Geraete suchen,"
echo "<INFO> gefundene Tags anhaken und speichern. Danach im Reiter MQTT das"
echo "<INFO> Abo eintragen - ohne das kommt am Miniserver nichts an."

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# ACHTUNG: die Pruefsumme gehoert zu config/ble_scanner_ng.cfg. Wer diese
# Datei aendert, ohne die Summe hier mitzuziehen, legt den Rettungsweg still
# lahm - ohne jede Meldung. In 1.3.0 sind Schluessel dazugekommen, die Summe
# ist entsprechend neu.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-ble_scanner_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "ble_scanner_ng.cfg" "1310ac7910fdf451128de437f2b5b345e3a225924ba180c03f92c303503c1c68"

# --- Den Dienst starten, wenn dies eine NEUINSTALLATION ist -----------------
#
# DAS FEHLTE BIS 1.3.11 VOLLSTAENDIG, und es waren zwei Luecken:
#
#  1. Der Installer startet den Daemon NIE. Gemessen am Quelltext
#     (plugininstall.pl, Zeilen 1126-1143): er kopiert die Datei nach
#     system/daemons/plugins/, setzt Rechte und Eigentuemer - und das ist
#     alles. Gestartet wird sie erst beim naechsten Systemstart.
#  2. postupgrade.sh startete nur beim UPGRADE, und dort mit "su loxberry -c",
#     was als loxberry scheitert (siehe dort).
#
# Folge, am Geraet am 13.09.2026 gemessen: nach der Installation von 1.3.11
# lief kein Prozess, es gab keine PID-Datei, kein Abbild, und das Protokoll
# war 0 Byte gross. Das Plugin war tot bis zum naechsten Neustart.
#
# Gestartet wird nur auf einer Neuinstallation. Beim Upgrade setzt
# preupgrade.sh den Merker "upgrade_laeuft", und dann gehoert der Start nach
# postupgrade.sh - der laeuft NACH diesem Skript und spielt vorher die
# Konfiguration zurueck. Ein Start hier wuerde den Dienst mit der
# mitgelieferten Vorgabe hochfahren.
#
# BERICHTIGT IN 1.3.14: der Merker liegt NEBEN dem Datenordner, nicht darin.
# Darin wurde er zwischen preupgrade.sh und diesem Skript vom Installer
# mitabgeraeumt, und dieser Zweig hat deshalb NIE gegriffen - jedes Upgrade
# lief hier als "Neuinstallation" durch. Begruendung und Messung stehen in
# preupgrade.sh.
MERK_UPGRADE="$PDATA.upgrade_laeuft"

# Ein liegengebliebener Merker eines abgebrochenen Upgrades darf eine echte
# Neuinstallation nicht als Upgrade ausweisen - sonst startet den Dienst
# niemand. Er gilt eine Stunde; ein Upgrade dauert Sekunden bis Minuten.
merker_frisch() {
    [ -f "$1" ] || return 1
    _dann=$(cat "$1" 2>/dev/null)
    case "$_dann" in
        ''|*[!0-9]*) return 1 ;;   # leer oder keine Zahl: nicht vertrauen
    esac
    _jetzt=$(date +%s 2>/dev/null) || return 1
    [ $((_jetzt - _dann)) -lt 3600 ] && [ $((_jetzt - _dann)) -ge 0 ]
}

if merker_frisch "$MERK_UPGRADE"; then
    echo "<INFO> Upgrade - der Dienst wird von postupgrade.sh gestartet."
elif P=$(dienst_pid); then
    echo "<INFO> Es laeuft schon ein Dienst (PID $P) - es wird keiner gestartet."
else
    echo "<INFO> Neuinstallation - der Dienst wird gestartet."
    dienst_starten
fi

exit 0
