#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR
PCONFIG=$LBPCONFIG/$PDIR
PBIN=$LBPBIN/$PDIR

SICHER="$PDATA/upgrade_sicherung"

# --- Konfiguration zurueckspielen -------------------------------------------
#
# Der Installer kopiert config/* aus dem Archiv ueber config/plugins/<ordner>
# und ueberschreibt dabei die Datei des Nutzers. Hier wird sie zurueckgeholt.
if [ -d "$SICHER" ]; then
    echo "<INFO> Restoring config files $SICHER/ -> $PCONFIG/"
    mkdir -p "$PCONFIG"
    cp -a "$SICHER/." "$PCONFIG/" 2>/dev/null && echo "<OK> Konfiguration zurueckgespielt."
    rm -rf "$SICHER" 2>/dev/null
fi

# Eigentuemer richtigstellen. Das Update laeuft als root; alles, was dabei
# entsteht, gehoerte danach root - und die Oberflaeche laeuft als loxberry
# und koennte die Konfiguration nicht mehr schreiben. Bis 1.1.0 fehlte das
# ganz: wer Tags anhakte und speicherte, bekam nichts gespeichert, und das
# Schreiben ist mit @ unterdrueckt, also lautlos.
if id loxberry >/dev/null 2>&1; then
    for d in "$PCONFIG" "$PDATA" "$PLOG"; do
        [ -d "$d" ] && chown -R loxberry:loxberry "$d" 2>/dev/null
    done
    echo "<OK> Eigentuemer der Konfigurations-, Daten- und Protokollordner: loxberry."
fi

chmod 755 "$PBIN"/*.py 2>/dev/null

# --- Fassungsnummer an EINE Stelle schreiben --------------------------------
#
# bl_common.py und bl_lib.php lesen sie von hier. Bis 1.2.10 stand sie an
# drei Stellen verschieden im Archiv (plugin.cfg 1.2.10, release.cfg 1.2.9,
# bl_common.py 1.2.0), und die aus bl_common.py landete im Protokoll.
if [ -n "$PVERSION" ]; then
    mkdir -p "$PCONFIG" 2>/dev/null
    printf '%s\n' "$PVERSION" > "$PCONFIG/fassung.txt"
    chown loxberry:loxberry "$PCONFIG/fassung.txt" 2>/dev/null
    chmod 0644 "$PCONFIG/fassung.txt" 2>/dev/null
    echo "<OK> Fassung $PVERSION vermerkt."
fi

# --- Den Dienst wieder starten ----------------------------------------------
#
# DAS FEHLTE BIS 1.2.10 VOLLSTAENDIG. preupgrade.sh hielt den Dienst an, und
# niemand startete ihn wieder: kein nohup, kein Aufruf von daemon, und
# REBOOT=false. Nach jedem Auto-Update war die Anwesenheitserkennung tot, bis
# jemand in der Oberflaeche speicherte oder den LoxBerry neu startete. Im
# Broker stand dann server/online=0, die Tag-Themen behielten aber ihren
# zurueckbehaltenen Wert - in Loxone sah das aus wie "alle noch da".
#
# Gestartet wird nur, wenn er VORHER lief (Merker aus preupgrade.sh) - sonst
# liefe ein bewusst angehaltener Dienst nach jedem Update wieder an.
if [ -f "$PDATA/lief_vor_update" ]; then
    rm -f "$PDATA/lief_vor_update"
    mkdir -p "$PLOG" "$PDATA" 2>/dev/null
    chown loxberry:loxberry "$PLOG" "$PDATA" 2>/dev/null
    su loxberry -c "nohup '$PBIN/ble_scanner_ng.py' >> '$PLOG/ble_scanner_ng.log' 2>&1 & echo \$! > '$PDATA/dienst.pid'"
    chown loxberry:loxberry "$PDATA/dienst.pid" 2>/dev/null
    sleep 2
    if [ -s "$PDATA/dienst.pid" ] && kill -0 "$(cat "$PDATA/dienst.pid")" 2>/dev/null; then
        echo "<OK> Der Dienst laeuft wieder (PID $(cat "$PDATA/dienst.pid"))."
    else
        echo "<WARNING> Der Dienst liess sich nicht wieder starten."
        echo "<WARNING> Nachsehen im Reiter Logdateien, dann im Reiter Einstellungen"
        echo "<WARNING> auf 'Dienst starten' druecken."
    fi
else
    echo "<INFO> Der Dienst lief vor dem Update nicht und wurde nicht gestartet."
fi

# --- Gruppe und Module pruefen ----------------------------------------------
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
        echo "<WARNING> Gruppenzuordnung bluetooth konnte nicht gesetzt werden."
    fi
fi

for modul in dbus gi paho.mqtt.client; do
    if python3 -c "import $modul" >/dev/null 2>&1; then
        echo "<OK> Python-Modul $modul vorhanden."
    else
        echo "<WARNING> Python-Modul $modul fehlt."
    fi
done

exit 0
