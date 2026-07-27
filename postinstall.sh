#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

# Create log $PCONFIG/$PSHNAME.log
mkdir $PLOG
touch $PLOG/$PSHNAME.log
chown loxberry:loxberry $PLOG/$PSHNAME.log
# Exit with Status 0

# --- BLE-Scanner ---------------------------------------------------------
# Ausfuehrbar machen. Ohne das startet der Daemon beim Systemstart nicht.
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

# Der Dienst laeuft als loxberry und spricht BlueZ ueber D-Bus an. BlueZ
# erlaubt das Mitgliedern der Gruppe bluetooth.
if getent group bluetooth >/dev/null 2>&1; then
    usermod -a -G bluetooth loxberry 2>/dev/null && \
        echo "<INFO> Benutzer loxberry zur Gruppe bluetooth hinzugefuegt." || \
        echo "<INFO> Gruppenzuordnung bluetooth konnte nicht gesetzt werden (nicht als root?)."
else
    echo "<WARNING> Gruppe bluetooth nicht vorhanden - ist bluez installiert?"
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
