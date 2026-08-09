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

echo "<INFO> Copy back existing config files /tmp/${PDIR}.SAVE/* $PCONFIG/"
cp -v -r /tmp/${PDIR}.SAVE/* $PCONFIG/

# Eigentuemer richtigstellen - das fehlte bis 1.1.0.
#
# LoxBerry fuehrt dieses Skript als root aus. cp uebernimmt den Eigentuemer
# des Kopierenden, also root. Die Oberflaeche laeuft als loxberry: sie konnte
# ble_scanner_ng.cfg danach LESEN (0644), aber nicht mehr schreiben. Wer nach
# einem Update Tags anhakte und speicherte, bekam nichts gespeichert -
# file_put_contents scheitert mit @ unterdrueckt, also lautlos.
if id loxberry >/dev/null 2>&1; then
    chown -R loxberry:loxberry "$PCONFIG" 2>/dev/null
    [ -d "$PDATA" ] && chown -R loxberry:loxberry "$PDATA" 2>/dev/null
    [ -d "$PLOG" ] && chown -R loxberry:loxberry "$PLOG" 2>/dev/null
    echo "<OK> Eigentuemer der Konfiguration auf loxberry gesetzt."
else
    echo "<WARNING> Benutzer loxberry nicht gefunden - Eigentuemer nicht geaendert."
fi

echo "<INFO> Remove temporary folder /tmp/${PDIR}.SAVE"
rm -rf /tmp/${PDIR}.SAVE

# Exit with Status 0

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

echo "<INFO> Naechster Schritt: Reiter Einstellungen -> Geraete suchen,"
echo "<INFO> gefundene Tags anhaken und speichern."

exit 0
