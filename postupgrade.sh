#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
LBPLOG="${LBPLOG:-$5/log/plugins}"
LBPBIN="${LBPBIN:-$5/bin/plugins}"
# sudo -n -u loxberry setzt die Umgebung zurueck - ohne diesen
# Rueckfall zeigte $LBPDATA ins Nichts und der Pfad auf /<ordner>.
LBPDATA="${LBPDATA:-$5/data/plugins}"
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR
PCONFIG=$LBPCONFIG/$PDIR
PBIN=$LBPBIN/$PDIR

SICHER="$PDATA.upgrade_sicherung"

# --- Konfiguration zurueckspielen -------------------------------------------
#
# Der Installer kopiert config/* aus dem Archiv ueber config/plugins/<ordner>
# und ueberschreibt dabei die Datei des Nutzers. Hier wird sie zurueckgeholt.
if [ -d "$SICHER" ]; then
    # ZUERST der Verlauf: er gehoert unter data/, nicht nach config/. Die
    # pauschale Kopie unten schiebt sonst alles in den Konfigordner.
    # verlauf.csv waechst ueber Wochen und ergibt sich nicht neu; data/ raeumt
    # der Installer bei jedem Update ab (plugininstall.pl :886 -> :1631).
    if [ -f "$SICHER/verlauf.csv" ]; then
        mkdir -p "$PDATA" 2>/dev/null
        if [ ! -s "$PDATA/verlauf.csv" ]; then
            cp -p "$SICHER/verlauf.csv" "$PDATA/verlauf.csv" 2>/dev/null \
                && echo "<OK> verlauf.csv ueber das Update gerettet."
        fi
        rm -f "$SICHER/verlauf.csv" 2>/dev/null
    fi
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
# Den Merker aus preupgrade.sh wegraeumen - postinstall.sh hat ihn gelesen.
rm -f "$PDATA/upgrade_laeuft" 2>/dev/null

if [ -f "$PDATA/lief_vor_update" ]; then
    rm -f "$PDATA/lief_vor_update"
    if P=$(dienst_pid); then
        echo "<OK> Der Dienst laeuft bereits (PID $P)."
    else
        dienst_starten
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
