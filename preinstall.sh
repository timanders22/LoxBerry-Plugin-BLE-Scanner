#!/bin/sh

# BLE-Scanner NG - preinstall. NEU IN 1.3.20, uebernommen aus dem gegengemessenen
# Vorschlag des Installer-Pruefers (Durchgang 29.09.2026, B4).
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Entscheidung 1 vom 29.09.2026. Der Installer ruft dieses Skript bei JEDEM
# Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren und
# vor postinstall.sh (sbin/plugininstall.pl: preupgrade :846, purge :874,
# preinstall :877 - Geraet/2026-09-28/08_plugininstall.pl). Bauform:
# LoxBerry-Plugin-Abfahrtsassistent-1.6.16/preinstall.sh.
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft (preupgrade.sh legt sie als Erstes an;
# kein Altersvergleich). Dann tut es nichts.
#
# Ohne Marke ist es eine NEUINSTALLATION. Bis 1.3.19 spielte postinstall.sh
# eine liegengebliebene Zweitschrift ungefragt zurueck - die Personenliste
# einer frueheren Installation (in WSL gemessen, Fall N1). Zweitschrift und
# Update-Sicherung gehen deshalb nach <name>.alt, gemeldet mit genau einer
# <WARNING>; die Deinstallation raeumt die .alt ab.

LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${3:-ble_scanner_ng}"
BASE="${5:-$LBHOMEDIR}"

if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.ble_scanner_ng.cfg"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
BEISEITE=""
FEST=""
for ZIEL in "$BK" "$SICHER"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null
# Ein Merker "Dienst lief vor dem Update" gehoert zu keinem Vorgang mehr.
rm -f "$BASE/data/plugins/$PFOLDER.lief_vor_update" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    BL_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && BL_TEXT="$BL_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && BL_TEXT="$BL_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$BL_TEXT"
fi
exit 0
