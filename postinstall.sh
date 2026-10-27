#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # NICHT der Auspackordner: $1 ist eine zehnstellige Zufallskennung; der Ordner steht in $6
              # bzw. im Arbeitsverzeichnis (Regeln/06 Z. 276-277; Gruppe 13, E4). Unbenutzt.
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.
# EINE Wurzel (seit 1.3.20, Regeln/06; Pruefung 29.09.2026, B8) - dieselben
# Zeilen wie in preupgrade.sh und postupgrade.sh. Bis 1.3.19 galt die
# LB-Umgebung vor dem fuenften Argument, NETZ_BASE unten aber $5 zuerst.
BASE="${5:-$LBHOMEDIR}"
LBPLOG="$BASE/log/plugins"
LBPCONFIG="$BASE/config/plugins"
LBPDATA="$BASE/data/plugins"
LBPBIN="$BASE/bin/plugins"

# --- Ohne brauchbare Wurzel wird nichts angefasst ---------------------------
#
# NEU IN 1.3.19 (Muster 1 der Nachlese). Fehlten das fuenfte Argument und die
# Umgebung, lauteten die Pfade bis 1.3.18 "/data/plugins/...",
# "/config/plugins/..." - ab der Laufwerkswurzel; zeigte $5 auf einen Ordner
# ohne diese Unterordner, wurden sie dort angelegt (in WSL gemessen,
# Pruefung-BLE-Scanner-1.3.19, Faelle H1 bis H3). Jetzt: warnen statt
# vollziehen.
# --- Gemeinsame Hilfen (Gruppe 13, V9): bin/bl_haken.sh aus dem Auspackordner -----------------
# $6 ist der Auspackordner und zugleich das Arbeitsverzeichnis (plugininstall.pl: cd "$tempfolder" && ...);
# $1 ist nur eine zehnstellige Zufallskennung. Fehlt die Datei oder ist sie defekt, bricht postinstall.sh
# ab: lieber kein Update als eines ohne Sicherung und Zweitschrift.
BLH="${6:-$(pwd)}/bin/bl_haken.sh"
if [ -f "$BLH" ] && sh -n "$BLH" 2>/dev/null && grep -qx 'BL_HAKEN_FASSUNG=1' "$BLH" 2>/dev/null; then
    BL_HAKEN_LADEN=1
    . "$BLH"
else
    echo "<ERROR> Die gemeinsamen Hilfen $BLH fehlen oder sind defekt - postinstall.sh bricht ab."
    exit 1
fi
# B7 (Gruppe 13): dieselbe Torbedingung wie preinstall.sh - auch config/system/general.json und ein Ordnername
# ohne "/" und "..". Bis 1.3.27 genuegten hier config/plugins und data/plugins; in einem Pruefbaum ohne
# general.json legte preinstall nichts beiseite, postinstall spielte aber die Zweitschrift zurueck.
if ! bl_wurzel_gilt "$BASE" "$PDIR"; then
    echo "<WARNING> Keine brauchbare LoxBerry-Wurzel (Ordner '$PDIR', Wurzel '$BASE', general.json"
    echo "<WARNING> noetig) - dieses Skript tut nichts."
    exit 0
fi

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
# Seit 1.3.20 leiten die Startwege nur noch in diese Datei um (Pruefung
# 29.09.2026, C5); das Protokoll schreibt der Dienst selbst.
touch "$PLOG/ble_scanner_ng_start.log"
chown loxberry:loxberry "$PLOG/ble_scanner_ng_start.log" 2>/dev/null

# --- BLE-Scanner NG ---------------------------------------------------------
# Ausfuehrbar machen. Ohne das startet der Daemon beim Systemstart nicht.
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null
# Gruppe 13: die Befehlsbruecke und die gemeinsamen Hilfen. KEIN Start der Bruecke hier - der Waechter in
# cron.01min startet sie binnen einer Minute, wenn "Befehle ueber MQTT annehmen" an ist.
chmod 755 "$LBPBIN/$PDIR/befehlsbruecke.sh" "$LBPBIN/$PDIR/bl_haken.sh" 2>/dev/null

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
# bl_dienste_finden() steht seit 1.3.28 in bin/bl_haken.sh (Gruppe 13, V9; oben eingebunden) - bis 1.3.27 wortgleich in
# mehreren Hakenskripten.

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
    # Nur in die Startdatei (seit 1.3.20, C5): das Protokoll oeffnet der
    # Dienst selbst; eine Umleitung in dieselbe Datei schrieb nach der ersten
    # Kappung in einen geloeschten Inode.
    nohup "$PBIN/ble_scanner_ng.py" >> "$PLOG/ble_scanner_ng_start.log" 2>&1 &
    echo $! > "$PDATA/dienst.pid"
    # Drei Sekunden (Regeln/03: an Python gemessen, eine reichte auf dem Pi
    # nicht, bis ein sofort abbrechender Dienst tot war).
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
# B5 (Gruppe 13): ob <allow send_destination="org.bluez"/> INNERHALB von <policy context="default"> steht -
# wortgleich in postupgrade.sh. Bis 1.3.27 genuegten zwei getrennte greps, und ein <deny .../> im default-Block
# meldete "erlaubt den Zugriff jedem Benutzer" (Probe deny.conf).
elif awk '/<policy context="default">/{d=1} d&&/<allow send_destination="org.bluez"/{a=1} /<\/policy>/{d=0} END{exit !a}' "$BTCONF"; then
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

# Die Anleitung fuer die Ersteinrichtung steht seit 1.3.19 am ENDE und nur
# dort, wo sie stimmt (siehe unten).

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
NETZ_BASE="$BASE"
NETZ_PDIR="${3:-ble_scanner_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"

# bl_cfg_traegt_inhalt() steht seit 1.3.28 in bin/bl_haken.sh (Gruppe 13, V9; oben eingebunden) - bis 1.3.27 wortgleich in
# mehreren Hakenskripten.

netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    # BERICHTIGT IN 1.3.18: die Zweitschrift wird nicht mehr blind kopiert.
    # Eine abgeschnittene Zweitschrift ueber eine Konfiguration zu legen macht
    # aus einem halben Verlust einen ganzen.
    if ! bl_cfg_traegt_inhalt "$zweit"; then
        echo "<WARNING> Die Zweitschrift $zweit traegt keinen lesbaren Inhalt -"
        echo "<WARNING> es wurde nichts zurueckgespielt."
        return 0
    fi
    verloren=0
    # BERICHTIGT IN 1.3.18: hier stand "[ ! -f ] || [ ! -s ]" - die blosse
    # Groesse. Eine abgeschnittene Konfiguration ist weder fehlend noch leer
    # noch zeichengleich die Vorgabe; sie galt als heil, und die heile
    # Zweitschrift wurde NICHT geholt. In WSL gemessen (18.09.2026,
    # Pruefung-BLE-Scanner-1.3.18, Fall c3): 34 Byte blieben stehen, 958 lagen
    # unbenutzt daneben.
    if ! bl_cfg_traegt_inhalt "$ziel"; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        # Daneben schreiben, byteweise gegenpruefen, dann umbenennen: ein
        # abgebrochenes cp hinterliesse sonst eine halbe Konfiguration -
        # dieselbe Bauart wie bl_datei_schreiben() in bl_lib.php:560.
        if cp -p "$zweit" "$ziel.neu" 2>/dev/null \
           && cmp -s "$zweit" "$ziel.neu" \
           && mv "$ziel.neu" "$ziel" 2>/dev/null; then
            # B8 (Gruppe 13): 0640 wie die Oberflaeche (bl_config_write) - cp -p uebernahm den Modus der
            # Zweitschrift, und eine aus der Zeit vor 1.3.18 trug 0644.
            chmod 0640 "$ziel" 2>/dev/null
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            rm -f "$ziel.neu" 2>/dev/null
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
# Gruppe 13: die Summe der Paket-cfg 1.3.28 (neue Schluessel befehle_mqtt_ein, befehle_eingreifend_ein,
# haus_anwesenheit, bthome=1, irk_aufloesen) - mitgezogen wie verlangt (B18).
netz_zurueck "ble_scanner_ng.cfg" "2426aeb33f82210a94d87326b9ca80816aab512532085417bb9a4fb724b515a8"
# V1 (Gruppe 13): irk.json liefert das Paket nicht mit. Fehlt sie nach dem Kopierschritt und liegt eine Zweitschrift,
# kommt sie zurueck - mit 0600. Eine vorhandene wird nie ueberschrieben (postupgrade.sh spielt die Sicherung danach
# ohnehin vollstaendig zurueck).
NETZ_IRK="$NETZ_CFG/irk.json"
NETZ_IRK_ZWEIT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.irk.json"
if [ ! -e "$NETZ_IRK" ] && [ -s "$NETZ_IRK_ZWEIT" ] && [ "$(head -c 1 "$NETZ_IRK_ZWEIT" 2>/dev/null)" = "{" ]; then
    if cp "$NETZ_IRK_ZWEIT" "$NETZ_IRK.neu" 2>/dev/null \
       && chmod 0600 "$NETZ_IRK.neu" 2>/dev/null \
       && cmp -s "$NETZ_IRK_ZWEIT" "$NETZ_IRK.neu" \
       && mv "$NETZ_IRK.neu" "$NETZ_IRK" 2>/dev/null; then
        echo "<OK> irk.json aus der Zweitschrift wiederhergestellt (0600)."
    else
        rm -f "$NETZ_IRK.neu" 2>/dev/null
        echo "<WARNING> irk.json liess sich nicht zurueckspielen; die Zweitschrift liegt unter $NETZ_IRK_ZWEIT."
    fi
fi
[ -f "$NETZ_IRK" ] && chmod 0600 "$NETZ_IRK" 2>/dev/null
# Gruppe 33 (1.3.29): bindkey.json genauso (Bind-Keys der Xiaomi-Thermometer, 0600; eine vorhandene bleibt).
NETZ_BK="$NETZ_CFG/bindkey.json"
NETZ_BK_ZWEIT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.bindkey.json"
if [ ! -e "$NETZ_BK" ] && [ -s "$NETZ_BK_ZWEIT" ] && [ "$(head -c 1 "$NETZ_BK_ZWEIT" 2>/dev/null)" = "{" ]; then
    if cp "$NETZ_BK_ZWEIT" "$NETZ_BK.neu" 2>/dev/null \
       && chmod 0600 "$NETZ_BK.neu" 2>/dev/null \
       && cmp -s "$NETZ_BK_ZWEIT" "$NETZ_BK.neu" \
       && mv "$NETZ_BK.neu" "$NETZ_BK" 2>/dev/null; then
        echo "<OK> bindkey.json aus der Zweitschrift wiederhergestellt (0600)."
    else
        rm -f "$NETZ_BK.neu" 2>/dev/null
        echo "<WARNING> bindkey.json liess sich nicht zurueckspielen; die Zweitschrift liegt unter $NETZ_BK_ZWEIT."
    fi
fi
[ -f "$NETZ_BK" ] && chmod 0600 "$NETZ_BK" 2>/dev/null

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
MERK_SOLL="$PDATA.soll_laufen"

# BERICHTIGT IN 1.3.20 (Entscheidung 1 vom 29.09.2026; Pruefung B6): ob dies
# ein Upgrade ist, entscheidet allein, ob die Marke DA ist - kein
# Altersvergleich. Bis 1.3.19 galt sie nur eine Stunde; ein Upgrade mit mehr
# als einer Stunde zwischen preupgrade.sh und diesem Skript galt als
# Neuinstallation und startete auch einen bewusst angehaltenen Dienst (in
# WSL gemessen, Faelle E7/E7B). Eine liegengebliebene Marke eines
# abgebrochenen Laufs raeumt preinstall.sh nicht weg - sie gehoert zu einem
# Upgrade, und das Upgrade raeumt sie in postupgrade.sh ab; die Deinstallation
# ebenso. Die Startsperre in daemon/daemon und im Waechter behaelt ihre
# Stunde: dort darf eine vergessene Marke den Dienst nicht fuer immer
# stilllegen.
if [ -f "$MERK_UPGRADE" ]; then
    echo "<INFO> Upgrade - der Dienst wird von postupgrade.sh gestartet."
elif P=$(dienst_pid); then
    echo "<INFO> Es laeuft schon ein Dienst (PID $P) - es wird keiner gestartet."
else
    echo "<INFO> Neuinstallation - der Dienst wird gestartet."
    # Ein Sollmerker "angehalten" aus einer frueheren Installation gilt fuer
    # diese nicht (die Deinstallation raeumt ihn sonst ab). Er faellt erst,
    # wenn der Dienst laeuft (Regeln/03, "erst nach erfolgreicher Pruefung").
    if dienst_starten; then
        rm -f "$MERK_SOLL" 2>/dev/null
    fi
fi

# --- Schlusswort: Anleitung nur, wo sie stimmt ------------------------------
#
# BERICHTIGT IN 1.3.19. Bis 1.3.18 riet dieses Skript bei JEDEM Aufruf zur
# Ersteinrichtung ("Geraete suchen ... speichern") - auch bei jedem Upgrade,
# wo die Tags eingetragen sind und postupgrade.sh sie gleich zurueckspielt
# (Fall E1; Regeln/06, "Nach einer Aktualisierung darf der Schlusstext nicht
# zur Erstinstallation raten"). Entschieden wird nach INHALT: steht eine
# Tag-Zeile in der Konfiguration, ist sie eingerichtet.
if [ -f "$MERK_UPGRADE" ]; then
    echo "<INFO> Die Einstellungen spielt postupgrade.sh gleich zurueck."
elif grep -q '^tag[0-9][0-9]*=' "$PCONFIG/ble_scanner_ng.cfg" 2>/dev/null; then
    echo "<INFO> Die Einstellungen sind uebernommen (Tags eingetragen) - es ist nichts weiter zu tun."
else
    echo "<INFO> Naechster Schritt: Reiter Einstellungen -> Geraete suchen,"
    echo "<INFO> gefundene Tags anhaken und speichern. Danach im Reiter MQTT das"
    echo "<INFO> Abo eintragen - ohne das kommt am Miniserver nichts an."
fi

exit 0
