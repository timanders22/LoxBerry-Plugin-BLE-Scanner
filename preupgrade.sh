#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPCONFIG="${LBPCONFIG:-$5/config/plugins}"
# sudo -n -u loxberry setzt die Umgebung zurueck - ohne diesen
# Rueckfall zeigte $LBPDATA ins Nichts und der Pfad auf /<ordner>.
LBPDATA="${LBPDATA:-$5/data/plugins}"
# Seit 1.3.17: der volle Dienstpfad wird gebraucht, weil der Dienst
# argumentweise gesucht wird (argv[1] ist GENAU dieser Pfad).
LBPBIN="${LBPBIN:-$5/bin/plugins}"
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PDATA=$LBPDATA/$PDIR
PCONFIG=$LBPCONFIG/$PDIR

# --- Die beiden Merker liegen NEBEN dem Datenordner -------------------------
#
# BERICHTIGT IN 1.3.14. Bis 1.3.13 lagen sie DARIN ("$PDATA/upgrade_laeuft"),
# und damit hat keiner von beiden je getragen: zwischen preupgrade.sh und
# postinstall.sh raeumt der Installer data/plugins/<ordner>/ restlos ab
# (plugininstall.pl: &purge_installation im Upgrade-Zweig, :886 -> :1629 ff.).
# Am Geraet am 13.09.2026 im Installationsprotokoll Zeile fuer Zeile belegt:
#
#     13:00:06  Plugin is already installed -> proceeding with upgrade
#     13:00:13  removed '.../data/plugins/ble_scanner_ng/upgrade_laeuft'
#     13:01:16  <INFO> Neuinstallation - der Dienst wird gestartet.
#     13:01:22  <INFO> Der Dienst lief vor dem Update nicht und wurde nicht
#               gestartet.
#
# Zwei Folgen, beide unerwuenscht: postinstall.sh hielt JEDES Upgrade fuer
# eine Neuinstallation und startete auch einen bewusst angehaltenen Dienst;
# und der Neustart nach einem Update lief nie an, weil "lief_vor_update" bei
# postupgrade.sh ebenfalls nie ankam. Damit war der Befund aus 1.3.13 (das
# "su loxberry -c", das als loxberry nicht geht) nur halb behoben - die
# Ursache war weg, der ausloesende Merker aber auch.
#
# Der Punkt im Namen ist der ganze Unterschied, genau wie beim
# Sicherungsordner weiter unten: "rm -rf .../<ordner>/" trifft den Nachbarn
# "<ordner>.upgrade_laeuft" nicht.
MERK_UPGRADE="$PDATA.upgrade_laeuft"
MERK_LIEF="$PDATA.lief_vor_update"

# --- Den laufenden Dienst zuerst anhalten -----------------------------------
#
# Was passiert, wenn man es nicht tut: LoxBerry ersetzt bin/*.py unter einem
# laufenden Python-Prozess. Der hat seinen Quelltext laengst geladen und
# laeuft unbeirrt mit der ALTEN Fassung weiter, bis irgendwann neu gestartet
# wird. Der Anwender sieht die neue Oberflaeche, waehrend im Hintergrund der
# alte Dienst arbeitet.
#
# BERICHTIGT IN 1.3.0: die Rueckfallebene ohne PID-Datei suchte mit
#
#     pgrep -o -f "[b]le_scanner.py"
#
# Das Muster heisst ble_scanner.py, der Dienst heisst ble_scanner_ng.py. Der
# Punkt steht fuer EIN beliebiges Zeichen, danach muesste sofort "py" folgen -
# tatsaechlich folgt "ng". Gemessen: null Treffer. Fehlte also die PID-Datei
# (nach einem Absturz, oder bei einem Dienst aus der Zeit vor 1.2.0), meldete
# dieses Skript "Es lief kein BLE-Scanner NG", waehrend er lief - genau der
# stille Fassungsversatz, den es verhindern soll.
#
# Gesucht wird jetzt argumentweise ueber /proc, so wie es die Oberflaeche
# schon lange tut. Das trifft weder einen Editor mit offener Datei noch ein
# grep auf dem Quelltext.
# Merker: dies ist ein UPGRADE. preupgrade.sh laeuft nur dann - gemessen am
# Quelltext des Installers (plugininstall.pl, Zeile 845: "if ($isupgrade)").
# postinstall.sh laeuft dagegen IMMER und darf den Dienst deshalb nur auf
# einer Neuinstallation starten; den Upgrade-Fall erledigt postupgrade.sh,
# nachdem es die Konfiguration zurueckgespielt hat.
#
# In den Merker kommt der Zeitpunkt, nicht nichts. Bricht ein Upgrade zwischen
# preupgrade.sh und postupgrade.sh ab, bleibt die Datei neben dem Ordner
# liegen - und wuerde eine spaetere echte Neuinstallation faelschlich als
# Upgrade ausweisen. Wer ihn liest, prueft deshalb sein Alter.
date +%s > "$MERK_UPGRADE" 2>/dev/null
chown loxberry:loxberry "$MERK_UPGRADE" 2>/dev/null
chmod 0644 "$MERK_UPGRADE" 2>/dev/null

PIDDATEI="$PDATA/dienst.pid"
DIENST="$LBPBIN/$PDIR/ble_scanner_ng.py"

# BERICHTIGT IN 1.3.17. Die Suche fand zwar schon argumentweise statt, aber sie
# verlangte nur IRGENDEIN Argument, das auf "/ble_scanner_ng.py" endet - der
# Interpreter, die Zahl der Argumente und der Benutzer blieben ungeprueft, und
# vor dem "kill -9" stand wieder die blosse Teilzeichenkette. In WSL gemessen
# (18.09.2026, Fall v1): die Nummer eines "tail -f <dienst>" in der PID-Datei
# hat der alte Zweig ohne Rueckfrage beendet. Ein Treffer hat jetzt GENAU zwei
# Argumente: einen python-Interpreter und den vollen Dienstpfad DIESER
# Installation; dazu muss er dem Benutzer mit der Nummer $2 gehoeren. Dieselbe
# Funktion steht in uninstall, postinstall.sh, postupgrade.sh und daemon.
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

# Beendet ALLE Treffer mit Geduld und sucht vor dem -9 neu, statt anzunehmen.
bl_dienste_beenden() {
    BL_GEFUNDEN=0
    BL_UEBRIG=""
    bl_pids=$(bl_dienste_finden "$1" "$2")
    for bl_p in $bl_pids; do
        BL_GEFUNDEN=$((BL_GEFUNDEN + 1))
    done
    [ -n "$bl_pids" ] || return 0
    echo "<INFO> Halte den laufenden BLE-Scanner NG an (PID$(for bl_p in $bl_pids; do printf ' %s' "$bl_p"; done))."
    kill $bl_pids 2>/dev/null
    bl_i=0
    while [ $bl_i -lt 20 ]; do
        bl_lebt=0
        for bl_p in $bl_pids; do
            kill -0 "$bl_p" 2>/dev/null && bl_lebt=1
        done
        [ "$bl_lebt" = 0 ] && break
        sleep 0.5
        bl_i=$((bl_i + 1))
    done
    bl_rest=$(bl_dienste_finden "$1" "$2")
    if [ -n "$bl_rest" ]; then
        echo "<WARNING> Der Dienst reagierte nicht auf SIGTERM - er wird abgeschossen."
        kill -9 $bl_rest 2>/dev/null
        sleep 1
        bl_rest=$(bl_dienste_finden "$1" "$2")
    fi
    for bl_p in $bl_rest; do
        BL_UEBRIG="$BL_UEBRIG $bl_p"
    done
}

BL_UID=$(id -u loxberry 2>/dev/null)
if [ -z "$BL_UID" ]; then
    echo "<WARNING> Benutzer loxberry nicht gefunden - der Dienst wurde nicht gesucht."
    BL_GEFUNDEN=0
    BL_UEBRIG=""
else
    bl_dienste_beenden "$DIENST" "$BL_UID"
fi

if [ -n "$BL_UEBRIG" ]; then
    echo "<WARNING> Der Dienst laesst sich nicht beenden (PID$BL_UEBRIG)."
fi

if [ "$BL_GEFUNDEN" != 0 ]; then
    rm -f "$PIDDATEI"
    # Merker fuer postupgrade.sh: der Dienst LIEF und gehoert danach wieder
    # gestartet. Ohne diesen Merker wuerde ein bewusst angehaltener Dienst
    # nach jedem Update ungefragt wieder anlaufen.
    date +%s > "$MERK_LIEF" 2>/dev/null
    chown loxberry:loxberry "$MERK_LIEF" 2>/dev/null
    chmod 0644 "$MERK_LIEF" 2>/dev/null
else
    echo "<INFO> Es lief kein BLE-Scanner NG."
    rm -f "$MERK_LIEF" 2>/dev/null
fi

# --- Traegt eine Datei INHALT? ----------------------------------------------
#
# NEU IN 1.3.18. Die GROESSE beantwortet die Frage nicht: eine abgeschnittene
# Datei ist nicht leer, besteht jede Groessenpruefung und verdraengt damit den
# brauchbaren Stand (Bestandsmessung Klasse C, 18.09.2026). Gefragt wird
# deshalb nach dem, was das Plugin selbst liest:
#   * der Abschnittskopf [CONFIG], den bl_config_write() (bl_lib.php:594)
#     immer als erste nicht auskommentierte Zeile schreibt,
#   * mindestens eine vollstaendige Zeile "schluessel=wert",
#   * ein Zeilenumbruch als letztes Byte - abgeschnitten wird mitten in einer
#     Zeile, und bl_config_write() schliesst jede Datei mit "\n".
#
# Im Zweifel faellt die Pruefung GESCHLOSSEN aus (CLAUDE.md 4): es wird nichts
# ueberschrieben, und es wird gesagt. Wortgleich in postinstall.sh - ein
# /bin/sh-Hakenskript kann keine gemeinsame Datei einbinden, der Installer
# ruft es aus dem Auspackordner heraus. Wer eine anfasst, fasst beide an.
bl_cfg_traegt_inhalt() {
    [ -s "$1" ] || return 1
    grep -q '^[[:space:]]*\[CONFIG\][[:space:]]*$' "$1" 2>/dev/null || return 1
    grep -q '^[[:space:]]*[A-Za-z_][A-Za-z0-9_.]*[[:space:]]*=' "$1" 2>/dev/null || return 1
    [ "$(tail -c 1 "$1" 2>/dev/null | wc -l | tr -d ' ')" = "1" ] || return 1
    return 0
}

# --- Sicherung der Konfiguration --------------------------------------------
#
# Der Sicherungsordner liegt unter data/, NICHT unter /tmp: /tmp ist auf dem
# LoxBerry eine Ramdisk, und /tmp ist fuer jeden lesbar - in
# ble_scanner_ng.cfg stehen MAC-Adressen und die Namen der ueberwachten
# Personen, also eine Anwesenheitsliste des Haushalts.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$PDATA.upgrade_sicherung"
NEU="$SICHER.neu"

# BERICHTIGT IN 1.3.18. Bis 1.3.17 stand hier
#
#     rm -rf "$SICHER"; mkdir -p "$SICHER"; cp -a "$PCONFIG/." "$SICHER/"
#
# also: die vorhandene Sicherung faellt, BEVOR die neue steht. Bricht der Lauf
# in dieser Luecke ab - abgebrochener Installer, volle Karte, Stromausfall -
# und stoesst der Anwender das Upgrade danach erneut an, gibt es weder die
# alte noch eine neue Sicherung. Genau das macht purge_installation moeglich:
# der Datenordner ist dann weg, es gibt nichts Neues zu sichern, und der
# zweite Lauf loescht die einzige Abschrift des Standes.
#
# In WSL gemessen (18.09.2026, Pruefung-BLE-Scanner-1.3.18, Faelle d1 und d2):
# 2 von 3 Dateien mit Merkwort verloren - die ganze Konfiguration und
# verlauf.csv; uebrig blieb nur die Zweitschrift neben dem Konfigordner.
#
# Die Reihenfolge jetzt ist die von GardenaSmartSystem 1.2.10
# (preupgrade.sh:93 ff.; in derselben Lage gemessen: 0 von 3 verloren):
# in "$SICHER.neu" bauen -> Rueckgabewert UND Inhalt pruefen -> die alte nach
# "$SICHER.alt" schieben -> die neue an ihren Platz -> die alte wegraeumen.
# Scheitert irgendetwas davon, bleibt die alte Sicherung unangetastet.
echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$NEU" 2>/dev/null
mkdir -p "$NEU"
chmod 0700 "$NEU" 2>/dev/null

SICHER_OK=0
ZU_SICHERN=0
[ -d "$PCONFIG" ] && ZU_SICHERN=1
# verlauf.csv waechst ueber Wochen und ergibt sich nicht neu - es liegt
# unter data/, und data/plugins/<x>/ raeumt der Installer bei jedem
# Update ab (plugininstall.pl :886 -> :1631).
[ -f "$PDATA/verlauf.csv" ] && ZU_SICHERN=1

if [ "$ZU_SICHERN" = 1 ]; then
    CP_RC=0
    if [ -d "$PCONFIG" ]; then
        echo "<INFO> Backing up existing config files $PCONFIG/ -> $SICHER/"
        cp -a "$PCONFIG/." "$NEU/" 2>/dev/null || CP_RC=$?
    fi
    if [ -f "$PDATA/verlauf.csv" ]; then
        cp -p "$PDATA/verlauf.csv" "$NEU/verlauf.csv" 2>/dev/null || CP_RC=$?
    fi
    # Die WIRKUNG pruefen, nicht den Rueckgabewert allein (CLAUDE.md 2):
    # jede Datei byteweise in der neuen Sicherung.
    ABWEICHEND=""
    if [ -d "$PCONFIG" ]; then
        ABWEICHEND=$( { cd "$PCONFIG" && find . -type f | while IFS= read -r f; do
                          cmp -s "$f" "$NEU/$f" || printf '%s ' "${f#./}"
                      done; } 2>/dev/null || echo "(Konfiguration nicht lesbar)" )
    fi
    if [ -f "$PDATA/verlauf.csv" ] && ! cmp -s "$PDATA/verlauf.csv" "$NEU/verlauf.csv"; then
        ABWEICHEND="$ABWEICHEND verlauf.csv"
    fi
    if [ "$CP_RC" -eq 0 ] && [ -z "$ABWEICHEND" ]; then
        SICHER_OK=1
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT vollstaendig sichern"
        echo "<WARNING> (cp Rueckgabewert $CP_RC; nicht in der Sicherung: ${ABWEICHEND:-keine})."
    fi
else
    echo "<INFO> Weder Konfiguration noch Verlauf vorhanden - es gibt nichts zu sichern."
fi

if [ "$SICHER_OK" = 1 ]; then
    rm -rf "$SICHER.alt" 2>/dev/null
    if [ -d "$SICHER" ]; then mv "$SICHER" "$SICHER.alt" 2>/dev/null; fi
    if mv "$NEU" "$SICHER" 2>/dev/null; then
        rm -rf "$SICHER.alt" 2>/dev/null
        chmod 0700 "$SICHER" 2>/dev/null
        echo "<OK> Konfiguration gesichert (Rechte 0700)."
    else
        if [ -d "$SICHER.alt" ]; then mv "$SICHER.alt" "$SICHER" 2>/dev/null; fi
        rm -rf "$NEU" 2>/dev/null
        echo "<WARNING> Die neue Sicherung liess sich nicht an ihren Platz bringen."
        echo "<WARNING> Platz und Rechte in $LBPDATA pruefen."
    fi
else
    rm -rf "$NEU" 2>/dev/null
    if [ -d "$SICHER" ]; then
        echo "<WARNING> Die bisherige Sicherung unter $SICHER bleibt unangetastet."
    fi
fi

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> und ueberschreibt dabei die Datei des Nutzers.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-ble_scanner_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
NETZ_QUELLE="$NETZ_CFG/ble_scanner_ng.cfg"
NETZ_ZIEL="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ble_scanner_ng.cfg"

# BERICHTIGT IN 1.3.18 - drei Fehler in vier Zeilen.
#
# 1. "[ -s ]" fragte nur nach der GROESSE. Eine abgeschnittene Konfiguration
#    ist nicht leer, bestand die Pruefung und wurde ueber die heile
#    Zweitschrift kopiert - der einzige Rueckweg war fort, ohne eine Zeile im
#    Protokoll. In WSL gemessen (18.09.2026, Fall c1): 34 Byte verdraengten
#    958. Gefragt wird jetzt nach dem Inhalt.
# 2. "cp -p" oeffnet das Ziel mit O_TRUNC: die vorhandene Zweitschrift ist
#    SOFORT leer und wird erst danach gefuellt. Bricht es dazwischen ab, ist
#    weder die alte noch die neue da. Gemessen (Fall d5, "ulimit -f 0"):
#    958 Byte -> 0 Byte. Geschrieben wird jetzt daneben und erst nach der
#    byteweisen Gegenprobe umbenannt - dieselbe Bauart wie
#    bl_datei_schreiben() in bl_lib.php:560.
# 3. "<INFO> Zweitschrift der Einstellungen angelegt." stand auch dann im
#    Protokoll, wenn gar nichts kopiert worden war (im zweiten Lauf der
#    Messung d1 belegt) - eine Erfolgsmeldung ohne Wirkung.
if bl_cfg_traegt_inhalt "$NETZ_QUELLE"; then
    if cp -p "$NETZ_QUELLE" "$NETZ_ZIEL.neu" 2>/dev/null \
       && chmod 0600 "$NETZ_ZIEL.neu" 2>/dev/null \
       && cmp -s "$NETZ_QUELLE" "$NETZ_ZIEL.neu" \
       && mv "$NETZ_ZIEL.neu" "$NETZ_ZIEL" 2>/dev/null; then
        echo "<INFO> Zweitschrift der Einstellungen angelegt ($NETZ_ZIEL)."
    else
        rm -f "$NETZ_ZIEL.neu" 2>/dev/null
        echo "<WARNING> Die Zweitschrift liess sich nicht anlegen ($NETZ_ZIEL)."
        if [ -f "$NETZ_ZIEL" ]; then
            echo "<WARNING> Die bisherige Zweitschrift bleibt unangetastet."
        fi
    fi
elif [ -f "$NETZ_ZIEL" ]; then
    echo "<WARNING> $NETZ_QUELLE traegt keinen lesbaren Inhalt - die vorhandene"
    echo "<WARNING> Zweitschrift bleibt unveraendert ($NETZ_ZIEL)."
else
    echo "<INFO> Keine lesbaren Einstellungen vorhanden - keine Zweitschrift angelegt."
fi

exit 0
