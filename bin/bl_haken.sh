#!/bin/sh
# BLE-Scanner NG - gemeinsame Hilfen der Hakenskripte (Gruppe 13, 1.3.28, V9). POSIX sh.
#
# Bis 1.3.27 standen bl_dienste_finden() sechsmal (preupgrade, postinstall, postupgrade, uninstall, daemon,
# cron.05min), bl_dienste_beenden() zweimal und bl_cfg_traegt_inhalt() dreimal wortgleich in den Skripten - mit dem
# Satz "ein /bin/sh-Hakenskript kann keine gemeinsame Datei einbinden". Das stimmt nicht (Pruefbericht V9; belegt an
# plugininstall.pl, Geraetekopie 28.09.2026): der Installer ruft jedes Hakenskript mit
#   cd "$tempfolder" && ... "$tempfile" "$pname" "$pfolder" "$pversion" "$lbhomedir" "$tempfolder"
# - $1 ist eine zehnstellige Zufallskennung, der Auspackordner ist $6 und das Arbeitsverzeichnis; er lebt durch alle
# Hakenskripte ("Cleaning" folgt nach postroot). uninstall laeuft aus data/system/uninstall/<ordner> OHNE
# Auspackordner, bin/plugins/<ordner> steht dann noch; daemon und cron laufen installiert (REPLACELBPBINDIR).
#
# Einbinden:
#   pre*/post*:        BLH="${6:-$(pwd)}/bin/bl_haken.sh"
#   uninstall:         BLH="$BASE/bin/plugins/$PDIR/bl_haken.sh"
#   daemon, cron:      BLH="REPLACELBPBINDIR/bl_haken.sh"
# vorher "sh -n" und die Kennzeile BL_HAKEN_FASSUNG=1 pruefen, dann BL_HAKEN_LADEN=1 setzen und ". "$BLH"".
# Fehlt die Datei oder ist sie defekt: preupgrade/postinstall <ERROR> und exit 1 (Abbruch ist sicherer, als ohne
# Zweitschrift weiterzumachen); postupgrade, uninstall, daemon und cron <WARNING> und eine Minimalform (nur nach der
# PID-Datei) - keine stille Luecke.
#
# Diese Datei tut beim Einbinden NICHTS ausser Funktionen zu definieren.
# Selbsttest:  sh bl_haken.sh --selbsttest   ("N Faelle geprueft, M Fehlschlaege.", Rueckgabe 0/1)
BL_HAKEN_FASSUNG=1

# Alle Prozesse dieses Dienstes: GENAU zwei Argumente, ein python-Interpreter und der volle Dienstpfad DIESER
# Installation ($1); der Prozess gehoert dem Benutzer mit der Nummer $2. (Seit 1.3.17, Wortlaut unveraendert.)
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

# Beendet ALLE Treffer mit Geduld und sucht vor dem -9 neu, statt anzunehmen. Setzt BL_GEFUNDEN und BL_UEBRIG.
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
    # kill -9 NUR fuer das, was JETZT noch laeuft und immer noch unserer ist.
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

# Traegt eine Konfiguration INHALT? Kopf [CONFIG], eine Zeile schluessel=wert, Zeilenumbruch als letztes Byte.
bl_cfg_traegt_inhalt() {
    [ -s "$1" ] || return 1
    grep -q '^[[:space:]]*\[CONFIG\][[:space:]]*$' "$1" 2>/dev/null || return 1
    grep -q '^[[:space:]]*[A-Za-z_][A-Za-z0-9_.]*[[:space:]]*=' "$1" 2>/dev/null || return 1
    [ "$(tail -c 1 "$1" 2>/dev/null | wc -l | tr -d ' ')" = "1" ] || return 1
    return 0
}

# B7 (Gruppe 13): EINE Torbedingung wie preinstall.sh - Wurzel $1 mit config/plugins, data/plugins UND
# config/system/general.json (ein Pruefbaum hat sie nie, ein LoxBerry immer; Regeln/06 Z. 531), Ordner $2 ohne "/"
# und "..". Rueckgabe 0 = brauchbar.
bl_wurzel_gilt() {
    case "$2" in ''|*/*|*..*) return 1 ;; esac
    [ -n "$1" ] && [ "$1" != "/" ] || return 1
    [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] && [ -f "$1/config/system/general.json" ]
}

if [ "${BL_HAKEN_LADEN:-}" != "1" ] && [ "${1:-}" = "--selbsttest" ]; then
    bl_st_n=0
    bl_st_f=0
    bl_st() {   # Text, erwartet, ist
        bl_st_n=$((bl_st_n + 1))
        if [ "$2" = "$3" ]; then echo "  [OK]   $1"; else bl_st_f=$((bl_st_f + 1)); echo "  [FEHL] $1 (ist $3, soll $2)"; fi
    }
    bl_st_d=$(mktemp -d 2>/dev/null) || { echo "kein Arbeitsordner"; exit 1; }
    printf '[CONFIG]\nadapter=hci0\n' > "$bl_st_d/gut.cfg"
    printf '[CONFIG]\nadapter=hc' > "$bl_st_d/halb.cfg"
    printf 'adapter=hci0\n' > "$bl_st_d/ohnekopf.cfg"
    : > "$bl_st_d/leer.cfg"
    for bl_st_x in gut halb ohnekopf leer fehlt; do
        bl_cfg_traegt_inhalt "$bl_st_d/$bl_st_x.cfg" && bl_st_r=0 || bl_st_r=1
        case "$bl_st_x" in gut) bl_st_s=0 ;; *) bl_st_s=1 ;; esac
        bl_st "bl_cfg_traegt_inhalt $bl_st_x" "$bl_st_s" "$bl_st_r"
    done
    mkdir -p "$bl_st_d/lb/config/plugins" "$bl_st_d/lb/data/plugins" "$bl_st_d/rest/config/plugins" "$bl_st_d/rest/data/plugins"
    mkdir -p "$bl_st_d/lb/config/system" && echo '{}' > "$bl_st_d/lb/config/system/general.json"
    bl_wurzel_gilt "$bl_st_d/lb" ble_scanner_ng && bl_st_r=0 || bl_st_r=1; bl_st "Wurzel mit general.json" 0 "$bl_st_r"
    bl_wurzel_gilt "$bl_st_d/rest" ble_scanner_ng && bl_st_r=0 || bl_st_r=1; bl_st "Pruefbaum ohne general.json" 1 "$bl_st_r"
    bl_wurzel_gilt "$bl_st_d/lb" "../x" && bl_st_r=0 || bl_st_r=1; bl_st "Ordner mit .." 1 "$bl_st_r"
    bl_wurzel_gilt "$bl_st_d/lb" "" && bl_st_r=0 || bl_st_r=1; bl_st "Ordner leer" 1 "$bl_st_r"
    bl_wurzel_gilt "/" ble_scanner_ng && bl_st_r=0 || bl_st_r=1; bl_st "Laufwerkswurzel" 1 "$bl_st_r"
    if command -v python3 >/dev/null 2>&1 && [ -d /proc/self ]; then
        printf 'import time\ntime.sleep(30)\n' > "$bl_st_d/ble_scanner_ng.py"
        python3 "$bl_st_d/ble_scanner_ng.py" &
        bl_st_pid=$!
        sleep 1
        bl_st "bl_dienste_finden findet die Attrappe" "$bl_st_pid" "$(bl_dienste_finden "$bl_st_d/ble_scanner_ng.py" "$(id -u)")"
        bl_st "bl_dienste_finden: anderer Pfad findet nichts" "" "$(bl_dienste_finden "$bl_st_d/x/ble_scanner_ng.py" "$(id -u)")"
        bl_dienste_beenden "$bl_st_d/ble_scanner_ng.py" "$(id -u)" > /dev/null
        bl_st "bl_dienste_beenden: gefunden 1, uebrig keiner" "1:" "$BL_GEFUNDEN:$(echo $BL_UEBRIG)"
        wait "$bl_st_pid" 2>/dev/null
    else
        echo "  [INFO] bl_dienste_finden/-beenden: ohne python3 oder /proc nicht pruefbar"
    fi
    rm -rf "$bl_st_d"
    echo "$bl_st_n Faelle geprueft, $bl_st_f Fehlschlaege."
    [ "$bl_st_f" = 0 ]
    exit $?
fi
