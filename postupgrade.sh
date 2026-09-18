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

# Die beiden Merker liegen NEBEN dem Datenordner - sonst raeumt der Installer
# sie zwischen preupgrade.sh und hier ab, und dieses Skript startet einen
# Dienst, der lief, nie wieder. Begruendung und Messung: preupgrade.sh.
# Berichtigt in 1.3.14.
MERK_UPGRADE="$PDATA.upgrade_laeuft"
MERK_LIEF="$PDATA.lief_vor_update"

# Gilt eine Stunde, damit ein Rest eines abgebrochenen Upgrades nicht spaeter
# einen Dienst hochfaehrt, den niemand angehalten hat.
#
# ABSICHTLICH ANDERSHERUM ALS IN postinstall.sh: dort entscheidet dieselbe
# Frage ueber die Marke "upgrade_laeuft" und faellt ohne lesbare Uhr
# GESCHLOSSEN aus (die Marke gilt, es wird nicht gestartet). Hier geht es um
# "lief_vor_update", und "geschlossen" heisst genau umgekehrt: ohne lesbare Uhr
# wird NICHT gestartet. Beides ist dieselbe Richtung - im Zweifel laeuft kein
# Dienst an, den niemand angefordert hat. Deshalb bleibt es hier beim
# Rueckgabewert 1.
merker_frisch() {
    [ -f "$1" ] || return 1
    _dann=$(cat "$1" 2>/dev/null)
    case "$_dann" in
        ''|*[!0-9]*) return 1 ;;
    esac
    _jetzt=$(date +%s 2>/dev/null) || return 1
    [ $((_jetzt - _dann)) -lt 3600 ] && [ $((_jetzt - _dann)) -ge 0 ]
}

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
# BERICHTIGT IN 1.3.17: die Suche verlangte nur IRGENDEIN Argument, das auf
# "/ble_scanner_ng.py" endet - Interpreter, Zahl der Argumente und Benutzer
# blieben ungeprueft, und der Dienst einer ZWEITEN Installation zaehlte mit.
# Ein Treffer hat jetzt GENAU zwei Argumente: einen python-Interpreter und den
# vollen Dienstpfad DIESER Installation ($1); dazu muss der Prozess dem
# Benutzer mit der Nummer $2 gehoeren. Dieselbe Funktion steht in
# preupgrade.sh, postinstall.sh, uninstall und daemon.
bl_dienste_finden() {
    for bl_d in /proc/[0-9]*; do
        grep -qaF "ble_scanner_ng.py" "$bl_d/cmdline" 2>/dev/null || continue
        [ "$(stat -c %u "$bl_d" 2>/dev/null)" = "$2" ] || continue
        bl_n=0
        bl_treffer=0
        while IFS= read -r bl_arg; do
            bl_n=$((bl_n + 1))
            if [ "$bl_n" = 1 ]; then
                case "${bl_arg##*/}" in
                    python|python3|python3.*) ;;
                    *) break ;;
                esac
            elif [ "$bl_n" = 2 ] && [ "$bl_arg" = "$1" ]; then
                bl_treffer=1
            fi
        done <<BL_ARGUMENTE
$(tr '\0' '\n' < "$bl_d/cmdline" 2>/dev/null)
BL_ARGUMENTE
        if [ "$bl_treffer" = 1 ] && [ "$bl_n" = 2 ]; then
            echo "${bl_d#/proc/}"
        fi
    done
}

# Dieses Skript laeuft als loxberry (sudo -n -u loxberry), der Dienst auch -
# deshalb ist die eigene Nummer der richtige Rueckfall, wenn es den Benutzer
# loxberry nicht gibt.
dienst_pid() {
    bl_uid=$(id -u loxberry 2>/dev/null)
    [ -n "$bl_uid" ] || bl_uid=$(id -u 2>/dev/null)
    bl_erste=$(bl_dienste_finden "$PBIN/ble_scanner_ng.py" "$bl_uid" | head -1)
    [ -n "$bl_erste" ] || return 1
    echo "$bl_erste"
    return 0
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
rm -f "$MERK_UPGRADE" 2>/dev/null

if merker_frisch "$MERK_LIEF"; then
    rm -f "$MERK_LIEF"
    if P=$(dienst_pid); then
        echo "<OK> Der Dienst laeuft bereits (PID $P)."
    else
        dienst_starten
    fi
else
    # Auch einen abgelaufenen Rest wegraeumen, sonst liegt er fuer immer.
    rm -f "$MERK_LIEF" 2>/dev/null
    echo "<INFO> Der Dienst lief vor dem Update nicht und wurde nicht gestartet."
fi

# --- Zugriff auf org.bluez und die Module pruefen ---------------------------
#
# BERICHTIGT IN 1.3.14. Hier stand bis 1.3.13 ein Versuch,
#
#     usermod -a -G bluetooth loxberry
#
# auszufuehren, und bei Misserfolg "<WARNING> Gruppenzuordnung bluetooth
# konnte nicht gesetzt werden." Diese Warnung stand bei JEDEM Upgrade im
# Protokoll - am Geraet am 13.09.2026 gemessen - und war doppelt falsch:
#
# 1. Sie konnte gar nicht gelingen. LoxBerry ruft postupgrade.sh mit
#    "sudo -n -u loxberry" auf (plugininstall.pl, Zeile 1336); dieses Skript
#    laeuft also als loxberry, und usermod verlangt root. Nur preroot.sh und
#    postroot.sh laufen als root - dieselbe Klasse wie das "su loxberry -c",
#    das in 1.3.13 aus genau diesem Skript verschwunden ist.
# 2. Die Gruppe wird ueberhaupt nicht gebraucht. bluez 5.82 liefert
#    /usr/share/dbus-1/system.d/bluetooth.conf mit
#    <policy context="default">, und das gilt fuer JEDEN Benutzer.
#    postinstall.sh misst das seit 1.3.12 richtig und sagt es auch - nur hier
#    war die alte, falsche Annahme stehen geblieben.
#
# Geprueft wird deshalb dasselbe wie in postinstall.sh: die Regel, nicht die
# Gruppe. Eine Gruppenmitgliedschaft wird weder gesetzt noch verlangt.
BTCONF=""
for k in /etc/dbus-1/system.d/bluetooth.conf /usr/share/dbus-1/system.d/bluetooth.conf; do
    [ -f "$k" ] && BTCONF="$k" && break
done
if [ -z "$BTCONF" ]; then
    echo "<INFO> Keine bluetooth.conf fuer D-Bus gefunden - bluez scheint zu fehlen."
elif grep -q '<policy context="default">' "$BTCONF" 2>/dev/null; then
    echo "<OK> $BTCONF erlaubt den Zugriff auf org.bluez jedem Benutzer"
    echo "<OK> (<policy context=\"default\">) - eine Gruppenmitgliedschaft ist unnoetig."
elif grep -q 'group="bluetooth"' "$BTCONF" 2>/dev/null; then
    if id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx bluetooth; then
        echo "<OK> $BTCONF erlaubt den Zugriff der Gruppe bluetooth, und loxberry ist darin."
    else
        echo "<INFO> $BTCONF erlaubt den Zugriff nur der Gruppe bluetooth, und loxberry"
        echo "<INFO> ist nicht darin. Dieses Skript laeuft als loxberry und kann das nicht"
        echo "<INFO> aendern. Einmalig als root:"
        echo "<INFO>     sudo usermod -a -G bluetooth loxberry && sudo reboot"
    fi
else
    echo "<INFO> $BTCONF nennt weder eine Vorgaberegel noch die Gruppe bluetooth -"
    echo "<INFO> der Zugriff auf org.bluez ist von hier aus nicht beurteilbar."
fi

for modul in dbus gi paho.mqtt.client; do
    if python3 -c "import $modul" >/dev/null 2>&1; then
        echo "<OK> Python-Modul $modul vorhanden."
    else
        echo "<WARNING> Python-Modul $modul fehlt."
    fi
done

exit 0
