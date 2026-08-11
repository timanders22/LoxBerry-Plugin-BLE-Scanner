#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Von den acht Pfadvariablen der LoxBerry-Vorlage wurden sieben nie
# benutzt (PCGI, PHTML, PTEMPL, PDATA, PCONFIG, PSBIN, PBIN) - sie sind
# entfallen. Gebraucht wird nur das Protokollverzeichnis.
PLOG=$LBPLOG/$PDIR   # Achtung: liegt auf einer Ramdisk

# mkdir mit -p und in Anfuehrungszeichen: ohne -p meldet mkdir einen
# Fehler, sobald das Verzeichnis schon existiert - und genau das ist bei
# einer Neuinstallation ueber eine alte hinweg der Normalfall.
mkdir -p "$PLOG"
touch "$PLOG/$PSHNAME.log"
chown loxberry:loxberry "$PLOG/$PSHNAME.log"

# --- BLE-Scanner NG ---------------------------------------------------------
# Ausfuehrbar machen. Ohne das startet der Daemon beim Systemstart nicht.
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

# Der Dienst laeuft als loxberry und spricht BlueZ ueber D-Bus an. BlueZ
# erlaubt das Mitgliedern der Gruppe bluetooth.
#
# BEWUSST KEINE eigene D-Bus-Richtlinie unter /etc/dbus-1/system.d/.
#
# BlueZ bringt seine Richtlinie selbst mit, und darin steht wortwoertlich:
#
#     <!-- allow users of bluetooth group to communicate -->
#     <policy group="bluetooth">
#       <allow send_destination="org.bluez"/>
#     </policy>
#
# (nachgesehen in bluez 5.64, Datei /etc/dbus-1/system.d/bluetooth.conf). Die
# Gruppe IST der vorgesehene Weg - die Behauptung, das reiche seit Bookworm
# nicht mehr, trifft nicht zu. Eine eigene Datei dorthin zu legen waere eine
# systemweite Rechteaenderung durch ein Plugin, sie waere doppelt, und beim
# naechsten BlueZ-Update stuende sie neben der mitgelieferten. Deshalb wird
# hier nur die Gruppe gesetzt - und geprueft, ob die Richtlinie sie kennt.
if getent group bluetooth >/dev/null 2>&1; then
    if id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx bluetooth; then
        echo "<OK> Benutzer loxberry ist in der Gruppe bluetooth."
    elif usermod -a -G bluetooth loxberry 2>/dev/null; then
        echo "<OK> Benutzer loxberry zur Gruppe bluetooth hinzugefuegt."
        echo "<INFO> ACHTUNG: eine neue Gruppe wirkt erst in einer NEUEN Sitzung."
        echo "<INFO> Beim Systemstart ist das erledigt. Wer den Dienst jetzt aus der"
        echo "<INFO> Oberflaeche startet, erbt womoeglich noch die alten Gruppen des"
        echo "<INFO> Webservers - dann meldet der Reiter Test 'Zugriff abgewiesen'."
        echo "<INFO> Abhilfe: LoxBerry einmal neu starten."
    else
        echo "<WARNING> Gruppenzuordnung bluetooth konnte nicht gesetzt werden (nicht als root?)."
        echo "<WARNING> Nachholen mit: sudo usermod -a -G bluetooth loxberry && sudo reboot"
    fi
else
    echo "<WARNING> Gruppe bluetooth nicht vorhanden - ist bluez installiert?"
fi

# Kennt die mitgelieferte Richtlinie die Gruppe wirklich? Wenn nicht, hilft
# keine Gruppenzuordnung, und der Anwender soll das erfahren, bevor er
# stundenlang am Dongle sucht.
BTCONF=""
for k in /etc/dbus-1/system.d/bluetooth.conf /usr/share/dbus-1/system.d/bluetooth.conf; do
    [ -f "$k" ] && BTCONF="$k" && break
done
if [ -z "$BTCONF" ]; then
    echo "<WARNING> Keine D-Bus-Richtlinie fuer BlueZ gefunden. Ist bluez vollstaendig installiert?"
elif grep -q 'group="bluetooth"' "$BTCONF"; then
    echo "<OK> Die D-Bus-Richtlinie ($BTCONF) erlaubt der Gruppe bluetooth den Zugriff."
else
    echo "<WARNING> In $BTCONF steht keine Regel fuer die Gruppe bluetooth."
    echo "<WARNING> Dann genuegt die Gruppenzuordnung allein nicht. Der Reiter Test"
    echo "<WARNING> zeigt in diesem Fall 'Zugriff abgewiesen' mit dem genauen Grund."
fi

# Pruefen, ob die Bausteine wirklich da sind.
for modul in dbus gi paho.mqtt.client; do
    if python3 -c "import $modul" >/dev/null 2>&1; then
        echo "<OK> Python-Modul $modul vorhanden."
    else
        echo "<WARNING> Python-Modul $modul fehlt."
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
    for d in "$LBPCONFIG/$PDIR" "$LBPDATA/$PDIR" "$LBPLOG/$PDIR"; do
        [ -d "$d" ] && chown -R loxberry:loxberry "$d" 2>/dev/null
    done
    echo "<OK> Eigentuemer der Konfigurations-, Daten- und Protokollordner: loxberry."
fi

echo "<INFO> Naechster Schritt: Reiter Einstellungen -> Geraete suchen,"
echo "<INFO> gefundene Tags anhaken und speichern."


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
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
netz_zurueck "ble_scanner_ng.cfg" "7ac1c0efe356e01bc46c6a297c7ff11c0a655320e7032958a242720000af4358"

exit 0
