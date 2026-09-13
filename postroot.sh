#!/bin/sh

# BLE-Scanner NG - postroot.sh
#
# DIESES SKRIPT LAEUFT ALS ROOT. Es ist das einzige im Plugin, das das tut:
# plugininstall.pl ruft preroot.sh und postroot.sh direkt auf, waehrend
# postinstall.sh und postupgrade.sh mit "sudo -n -u loxberry" starten (Zeilen
# 1311 und 1336). Deshalb steht hier genau eine Aufgabe, die Rechte braucht -
# und sonst nichts.
#
# AUFGABE: den Helfer ablegen, mit dem der Reiter Test das eingebaute
# Bluetooth einschalten kann.
#
# WARUM EIN HELFER AUSSERHALB DES PLUGINS, und nicht einfach eine sudo-Regel
# auf ein Skript in bin/? Weil das ein Weg nach Root waere: bin/ gehoert
# loxberry, und wer dort schreiben darf, koennte sich mit einer Regel auf eine
# Datei in diesem Verzeichnis beliebigen root-Code verschaffen. Regeln/06
# sagt das ausdruecklich ("Die sudoers-Vorlage loeschen, wenn sie nicht
# gebraucht wird"). Das Haus hat dafuer ein Muster, dem dieses Skript folgt
# (EVCC, /usr/local/sbin/loxberry-evcc-update): die Datei liegt in einem
# Verzeichnis, das root gehoert, sie gehoert root, sie ist 0755, und die
# sudo-Regel nennt genau diesen einen Pfad OHNE Argumente.

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4

HELFER=/usr/local/sbin/ble_scanner_ng_bluetooth

if [ ! -d /usr/local/sbin ]; then
    mkdir -p /usr/local/sbin || {
        echo "<FAIL> /usr/local/sbin liess sich nicht anlegen."
        exit 0
    }
fi

# Der Helfer wird HIER geschrieben, nicht aus dem Archiv kopiert. Damit steht
# sein Inhalt unter der Aufsicht dieses Skripts, und eine spaetere Aenderung im
# Plugin-Ordner kann ihn nicht veraendern.
cat > "$HELFER" <<'ENDE'
#!/bin/sh
#
# BLE-Scanner NG - eingebautes Bluetooth einschalten.
#
# Abgelegt von postroot.sh des Plugins, Eigentuemer root. Aufgerufen wird es
# ueber /etc/sudoers.d/ble_scanner_ng (%loxberry, OHNE Argumente) aus dem
# Reiter Test. Es nimmt keine Argumente an und liest keine Eingabe - alles,
# was es tut, steht hier.
#
# WAS ES NICHT TUT: es aendert KEINE Datei unter /etc. Auf einem DietPi sperrt
# /etc/modprobe.d/dietpi-disable_bluetooth.conf die Bluetooth-Module; das ist
# eine Entscheidung des Systembesitzers, und dietpi-config wuerde eine
# Aenderung daran beim naechsten Lauf ueberschreiben. Eine blacklist-Zeile
# verhindert nur das SELBSTTAETIGE Laden - ein ausdrueckliches modprobe wirkt
# trotzdem. Deshalb wirkt dieses Skript bis zum naechsten Neustart, und nur
# dietpi-config (Advanced Options, Bluetooth) macht es dauerhaft.
#
# Gemessen am 13.09.2026 an einem Raspberry Pi 4 mit DietPi: nach
# "modprobe hci_uart" erschien hci0 (BCM43455, Firmware BCM4345C0
# 003.001.025), bluetoothd lief, und der Suchlauf des Plugins fand drei
# Geraete.

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

echo "--- vorher ---"
if [ -d /sys/class/bluetooth ]; then
    ls /sys/class/bluetooth
else
    echo "kein /sys/class/bluetooth"
fi

# hci_uart zieht bluetooth und btbcm als Abhaengigkeit mit; btusb ist der Weg
# fuer einen eingesteckten Adapter. Beide werden versucht, keines ist Pflicht:
# auf einem Geraet ohne eingebautes Bluetooth scheitert hci_uart folgenlos.
for m in hci_uart btusb; do
    if modprobe "$m" 2>/dev/null; then
        echo "geladen: $m"
    else
        echo "nicht geladen: $m (auf dieser Hardware nicht vorhanden?)"
    fi
done

# Erst jetzt den Dienst: systemd ueberspringt bluetooth.service, solange
# /sys/class/bluetooth fehlt (ConditionPathIsDirectory). Vor dem modprobe
# waere der Start also wirkungslos - und haette "started" gemeldet.
i=0
while [ $i -lt 10 ] && [ ! -d /sys/class/bluetooth ]; do
    sleep 1
    i=$((i + 1))
done

systemctl start bluetooth 2>&1
echo "bluetooth.service: $(systemctl is-active bluetooth 2>/dev/null)"

echo "--- nachher ---"
if [ -d /sys/class/bluetooth ]; then
    ls /sys/class/bluetooth
else
    echo "kein /sys/class/bluetooth - es ist kein Bluetooth-Geraet angemeldet"
    exit 1
fi
exit 0
ENDE

chown root:root "$HELFER"
chmod 0755 "$HELFER"

# WIRKUNGSPRUEFUNG statt Zuversicht: die Datei muss da, root und ausfuehrbar
# sein. Ohne diese drei Eigenschaften ist die sudo-Regel wirkungslos, und der
# Knopf im Reiter Test wuerde nur eine Fehlermeldung zeigen.
if [ -x "$HELFER" ] && [ "$(stat -c %U "$HELFER" 2>/dev/null)" = "root" ]; then
    echo "<OK> Helfer fuer das Einschalten von Bluetooth abgelegt ($HELFER)."
else
    echo "<FAIL> $HELFER ist nicht ausfuehrbar oder gehoert nicht root."
fi

# Die sudo-Regel selbst legt LoxBerry aus sudoers/sudoers ab (nach
# <home>/system/sudoers/$PSHNAME, was dasselbe Verzeichnis wie
# /etc/sudoers.d ist - am Geraet ueber die Inode-Nummer nachgemessen) und
# entfernt sie beim Deinstallieren wieder. Hier wird sie nur nachgesehen.
if [ -f "$LBHOMEDIR/system/sudoers/$PSHNAME" ] \
   || [ -f "/etc/sudoers.d/$PSHNAME" ]; then
    echo "<INFO> Die sudo-Regel fuer diesen Helfer ist eingerichtet."
else
    echo "<INFO> Die sudo-Regel ist noch nicht da. Der Knopf 'Bluetooth"
    echo "<INFO> einschalten' im Reiter Test bleibt dann ohne Wirkung; die"
    echo "<INFO> beiden Befehle stehen aber in der Anzeige und lassen sich"
    echo "<INFO> von Hand als root ausfuehren."
fi

exit 0
