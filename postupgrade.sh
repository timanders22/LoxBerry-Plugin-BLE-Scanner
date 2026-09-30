#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
# EINE Wurzel (seit 1.3.20, Regeln/06; Pruefung 29.09.2026, B8): $5 zuerst,
# sonst LBHOMEDIR, und alle Pfade daraus. Bis 1.3.19 galt die LB-Umgebung vor
# dem fuenften Argument - ein Pruefstand am Geraet griff so die Anlage an.
BASE="${5:-$LBHOMEDIR}"
LBPCONFIG="$BASE/config/plugins"
LBPLOG="$BASE/log/plugins"
LBPBIN="$BASE/bin/plugins"
LBPDATA="$BASE/data/plugins"
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

# --- Ohne brauchbare Wurzel wird nichts angefasst ---------------------------
#
# NEU IN 1.3.19 (Muster 1 der Nachlese). Fehlten das fuenfte Argument und die
# Umgebung, lauteten die Pfade bis 1.3.18 "/data/plugins/...",
# "/config/plugins/..." - ab der Laufwerkswurzel; zeigte $5 auf einen Ordner
# ohne diese Unterordner, wurden sie dort angelegt (in WSL gemessen,
# Pruefung-BLE-Scanner-1.3.19, Faelle H1 bis H3). Jetzt: warnen statt
# vollziehen.
if [ -z "$PDIR" ] || [ ! -d "$LBPCONFIG" ] || [ ! -d "$LBPDATA" ] \
   || [ "$LBPCONFIG" = "/config/plugins" ] || [ "$LBPDATA" = "/data/plugins" ]; then
    echo "<WARNING> Keine brauchbare LoxBerry-Wurzel (Ordner '$PDIR', Konfiguration"
    echo "<WARNING> '$LBPCONFIG', Daten '$LBPDATA') - dieses Skript tut nichts."
    exit 0
fi

PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR
PCONFIG=$LBPCONFIG/$PDIR
PBIN=$LBPBIN/$PDIR

SICHER="$PDATA.upgrade_sicherung"

# Die Merker liegen NEBEN dem Datenordner - sonst raeumt der Installer sie
# zwischen preupgrade.sh und hier ab. Begruendung und Messung: preupgrade.sh.
# Berichtigt in 1.3.14.
MERK_UPGRADE="$PDATA.upgrade_laeuft"
# "lief_vor_update" legt seit 1.3.20 niemand mehr an; ein Rest wird unten
# weggeraeumt. Entschieden wird nach dem Sollmerker (siehe unten).
MERK_LIEF="$PDATA.lief_vor_update"
MERK_SOLL="$PDATA.soll_laufen"

# --- Traegt eine Konfiguration INHALT? --------------------------------------
#
# NEU HIER IN 1.3.20 (Pruefung 29.09.2026, B2) - wortgleich mit preupgrade.sh
# und postinstall.sh; ein /bin/sh-Hakenskript kann keine gemeinsame Datei
# einbinden. Wer eine anfasst, fasst alle drei an.
bl_cfg_traegt_inhalt() {
    [ -s "$1" ] || return 1
    grep -q '^[[:space:]]*\[CONFIG\][[:space:]]*$' "$1" 2>/dev/null || return 1
    grep -q '^[[:space:]]*[A-Za-z_][A-Za-z0-9_.]*[[:space:]]*=' "$1" 2>/dev/null || return 1
    [ "$(tail -c 1 "$1" 2>/dev/null | wc -l | tr -d ' ')" = "1" ] || return 1
    return 0
}

# --- Konfiguration zurueckspielen -------------------------------------------
#
# Der Installer kopiert config/* aus dem Archiv ueber config/plugins/<ordner>
# und ueberschreibt dabei die Datei des Nutzers. Hier wird sie zurueckgeholt.
#
# BERICHTIGT IN 1.3.18 - drei Fehler in diesem Block, alle derselben Bauart:
# es wird weggeraeumt, bevor feststeht, dass das Neue steht (Faelle c2, d3,
# d4 in Pruefung-BLE-Scanner-1.3.18). Weggeraeumt wird seither erst, wenn die
# Wirkung nachgewiesen ist.
#
# BERICHTIGT IN 1.3.20, zwei weitere Luecken (Pruefung 29.09.2026):
# B7  Lief in der Upgrade-Luecke ein Dienst (aus der Oberflaeche gestartet),
#     schrieb er einen neuen Verlauf, und bis 1.3.19 wurde die Rettung dann
#     STILL verworfen - Wochen an Verlauf weg, ohne eine Zeile im Protokoll
#     (in WSL nachgestellt, Fall E6d). Jetzt wird zusammengefuehrt: die
#     Rettung, dahinter die Zeilen aus der Luecke; scheitert das, bleibt die
#     Rettung liegen und wird genannt.
# B2  Die gesicherte Konfiguration wurde ohne Inhaltspruefung
#     zurueckgespielt - eine abgeschnittene ueberschrieb die eben aus der
#     heilen Zweitschrift geholte, und das Protokoll meldete zweimal Erfolg
#     (Fall E4). Jetzt wird sie vorher geprueft; eine kaputte geht nach
#     "$SICHER.kaputt" (0600), mit einer <WARNING>.
if [ -d "$SICHER" ]; then
    ALLES_ZURUECK=1
    # ZUERST der Verlauf: er gehoert unter data/, nicht nach config/. Die
    # pauschale Kopie unten schiebt sonst alles in den Konfigordner.
    # verlauf.csv waechst ueber Wochen und ergibt sich nicht neu; data/ raeumt
    # der Installer bei jedem Update ab (plugininstall.pl :886 -> :1631).
    if [ -f "$SICHER/verlauf.csv" ]; then
        mkdir -p "$PDATA" 2>/dev/null
        if [ -s "$PDATA/verlauf.csv" ]; then
            # Ein Dienst in der Luecke hat geschrieben: zusammenfuehren. Die
            # Rettung wird OHNE Inhaltspruefung genommen - sie ist der
            # Bestand; angehaengt werden die Datenzeilen aus der Luecke, die
            # darin noch nicht stehen.
            MISCH="$PDATA/verlauf.csv.misch.$$"
            LUECKE=$(grep -c '^[0-9][0-9]*;' "$PDATA/verlauf.csv" 2>/dev/null)
            case "$LUECKE" in ''|*[!0-9]*) LUECKE=0 ;; esac
            if { cat "$SICHER/verlauf.csv" \
                 && { [ "$(tail -c 1 "$SICHER/verlauf.csv" | wc -l | tr -d ' ')" = 1 ] || echo; } \
                 && { grep '^[0-9][0-9]*;' "$PDATA/verlauf.csv" | grep -vxF -f "$SICHER/verlauf.csv"; true; }; } \
                   > "$MISCH" 2>/dev/null \
               && cmp -s -n "$(wc -c < "$SICHER/verlauf.csv" | tr -d ' ')" "$SICHER/verlauf.csv" "$MISCH" \
               && chmod 0640 "$MISCH" 2>/dev/null \
               && mv -f "$MISCH" "$PDATA/verlauf.csv" 2>/dev/null; then
                echo "<OK> verlauf.csv ueber das Update gerettet; $LUECKE Zeile(n), die ein Dienst"
                echo "<OK> waehrend des Updates schrieb, stehen dahinter."
                rm -f "$SICHER/verlauf.csv" 2>/dev/null
            else
                rm -f "$MISCH" 2>/dev/null
                ALLES_ZURUECK=0
                echo "<WARNING> verlauf.csv liess sich nicht mit den $LUECKE Zeile(n) aus der"
                echo "<WARNING> Update-Luecke zusammenfuehren. Die Rettung bleibt unter"
                echo "<WARNING> $SICHER/verlauf.csv liegen; nichts wurde geloescht."
            fi
        elif cp -p "$SICHER/verlauf.csv" "$PDATA/verlauf.csv" 2>/dev/null \
             && cmp -s "$SICHER/verlauf.csv" "$PDATA/verlauf.csv"; then
            echo "<OK> verlauf.csv ueber das Update gerettet."
            rm -f "$SICHER/verlauf.csv" 2>/dev/null
        else
            ALLES_ZURUECK=0
            echo "<WARNING> verlauf.csv liess sich nicht zurueckspielen. Die Rettung"
            echo "<WARNING> bleibt unter $SICHER/verlauf.csv liegen."
        fi
        # 0640 nach dem Zurueckspielen (seit 1.3.20, B10): am Geraet stand
        # verlauf.csv auf 0664, und "cp -p" trug den Modus ueber jedes Update.
        # Darin stehen Namen und Ankunftszeiten der Hausbewohner.
        [ -f "$PDATA/verlauf.csv" ] && chmod 0640 "$PDATA/verlauf.csv" 2>/dev/null
    fi
    # Die gesicherte Konfiguration VOR dem Zurueckspielen pruefen (B2).
    KAPUTT="$SICHER.kaputt"
    if [ -f "$SICHER/ble_scanner_ng.cfg" ] && ! bl_cfg_traegt_inhalt "$SICHER/ble_scanner_ng.cfg"; then
        rm -f "$KAPUTT" 2>/dev/null
        if mv "$SICHER/ble_scanner_ng.cfg" "$KAPUTT" 2>/dev/null; then
            chmod 0600 "$KAPUTT" 2>/dev/null
            if cmp -s "$PCONFIG/ble_scanner_ng.cfg" "$LBPCONFIG/$PDIR.backup.ble_scanner_ng.cfg"; then
                BL_JETZT="die aus der Zweitschrift wiederhergestellte"
            else
                BL_JETZT="die mitgelieferte Vorgabe - die Tags stehen in der beiseitegelegten Datei"
            fi
            echo "<WARNING> Die gesicherte Konfiguration ist unvollstaendig ($(wc -c < "$KAPUTT" | tr -d ' ') Byte) und wird NICHT zurueckgespielt. Sie liegt unter $KAPUTT (0600); es gilt $BL_JETZT."
        else
            ALLES_ZURUECK=0
            echo "<WARNING> Die gesicherte Konfiguration ist unvollstaendig und liess sich nicht beiseitelegen - sie wird NICHT zurueckgespielt und bleibt unter $SICHER liegen."
        fi
    fi
    echo "<INFO> Restoring config files $SICHER/ -> $PCONFIG/"
    mkdir -p "$PCONFIG"
    if [ -f "$SICHER/ble_scanner_ng.cfg" ] && ! bl_cfg_traegt_inhalt "$SICHER/ble_scanner_ng.cfg"; then
        # Nur, wenn das Beiseitelegen oben scheiterte: dann nichts kopieren.
        FEHLT="(Konfiguration unvollstaendig, nicht zurueckgespielt)"
    elif cp -a "$SICHER/." "$PCONFIG/" 2>/dev/null; then
        # Die WIRKUNG pruefen, nicht den Rueckgabewert (CLAUDE.md 2).
        # verlauf.csv bleibt aussen vor: es gehoert unter data/.
        FEHLT=$( { cd "$SICHER" && find . -type f ! -name verlauf.csv | while IFS= read -r f; do
                     cmp -s "$f" "$PCONFIG/$f" || printf '%s ' "${f#./}"
                 done; } 2>/dev/null || echo "(Sicherung nicht lesbar)" )
    else
        FEHLT="(cp scheiterte)"
    fi
    # Ist die Rettung liegengeblieben, hat die pauschale Kopie sie nach
    # config/ mitgenommen - dort gehoert sie nicht hin.
    rm -f "$PCONFIG/verlauf.csv" 2>/dev/null
    if [ -z "$FEHLT" ]; then
        echo "<OK> Konfiguration zurueckgespielt."
    else
        ALLES_ZURUECK=0
        echo "<WARNING> Die Konfiguration liess sich nicht vollstaendig zurueckspielen"
        echo "<WARNING> (nicht angekommen: $FEHLT)."
    fi
    if [ "$ALLES_ZURUECK" = 1 ]; then
        rm -rf "${SICHER:?}" 2>/dev/null
    else
        echo "<WARNING> Die Sicherung bleibt unter $SICHER liegen und wird beim"
        echo "<WARNING> naechsten Update beiseitegelegt, nicht zurueckgespielt."
    fi
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
    # Nur in die Startdatei (seit 1.3.20, C5) - das Protokoll oeffnet der
    # Dienst selbst.
    [ -e "$PLOG/ble_scanner_ng_start.log" ] || : > "$PLOG/ble_scanner_ng_start.log"
    nohup "$PBIN/ble_scanner_ng.py" >> "$PLOG/ble_scanner_ng_start.log" 2>&1 &
    echo $! > "$PDATA/dienst.pid"
    # Drei Sekunden (Regeln/03).
    sleep 3
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
# BERICHTIGT IN 1.3.20 (Pruefung 29.09.2026, B1; Hausstandard Regeln/03):
# gestartet wird, wenn der Dienst laufen SOLL - nicht nur, wenn er vor dem
# Update gerade lief. Bis 1.3.19 blieb ein abgestuerzter Dienst ueber jedes
# Update hinweg aus ("Der Dienst lief vor dem Update nicht und wurde nicht
# gestartet" - so am Geraet am 26.09.2026). Der Sollmerker
# ($PDATA.soll_laufen) steht auf "0" nur, wenn im Reiter Einstellungen "Dienst
# anhalten" gedrueckt wurde; ohne ihn gilt "eingeschaltet".
# Die Marke aus preupgrade.sh wegraeumen - postinstall.sh hat sie gelesen -
# und einen Rest der alten Merker-Bauart ebenso.
rm -f "$MERK_UPGRADE" 2>/dev/null
rm -f "$MERK_LIEF" 2>/dev/null

if [ "$(cat "$MERK_SOLL" 2>/dev/null)" = "0" ]; then
    echo "<INFO> Der Dienst ist im Reiter Einstellungen angehalten und wurde nicht gestartet."
elif P=$(dienst_pid); then
    echo "<OK> Der Dienst laeuft bereits (PID $P)."
else
    dienst_starten
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

# --- Schlusswort (seit 1.3.19) ----------------------------------------------
# Nach INHALT: steht nach dem Zurueckspielen eine Tag-Zeile in der
# Konfiguration, ist nichts weiter zu tun; sonst (Rueckholung gescheitert oder
# nie eingerichtet) die Anleitung der Ersteinrichtung.
# ERGAENZT IN 1.3.20 (B2): zuerst, ob die Datei ueberhaupt Inhalt traegt - eine
# mitten in einer Tag-Zeile abgeschnittene hat auch eine Tag-Zeile, und bis
# 1.3.19 stand dann "Aktualisierung abgeschlossen" im Protokoll (Fall E4).
if ! bl_cfg_traegt_inhalt "$PCONFIG/ble_scanner_ng.cfg"; then
    echo "<WARNING> Die Konfiguration $PCONFIG/ble_scanner_ng.cfg traegt keinen lesbaren"
    echo "<WARNING> Inhalt. Reiter Test, Zeile 'Ist die Konfiguration heil?', nennt den Grund."
elif grep -q '^tag[0-9][0-9]*=' "$PCONFIG/ble_scanner_ng.cfg" 2>/dev/null; then
    echo "<OK> Aktualisierung abgeschlossen - die Einstellungen sind uebernommen, es ist nichts weiter zu tun."
else
    echo "<INFO> Naechster Schritt: Reiter Einstellungen -> Geraete suchen,"
    echo "<INFO> gefundene Tags anhaken und speichern. Danach im Reiter MQTT das"
    echo "<INFO> Abo eintragen - ohne das kommt am Miniserver nichts an."
fi

exit 0
