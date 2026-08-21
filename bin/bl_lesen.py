#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner NG - den Python-Konfigurationsleser von aussen abfragen

Damit misst der Reiter Test, ob die PHP-Seite und die Python-Seite dieselbe
Konfiguration lesen. Es gibt zwei Leser fuer ein Format; bis 1.2.10 waren
sie sich an zwei Faellen uneinig, und beim naechsten Speichern gewann der
PHP-Leser - ein Tag verschwand, ein zweiter wurde umbenannt.

Diese Datei liest NUR. Sie schreibt nichts und veraendert nichts.

Aufruf:
    python3 bl_lesen.py <datei>     Tags dieser Datei als JSON
    python3 bl_lesen.py --vorgaben  die Vorgabewerte als JSON
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import bl_common as gem   # noqa: E402


def main():
    argumente = [a for a in sys.argv[1:] if a.strip() != ""]
    if "--vorgaben" in argumente:
        print(json.dumps({"vorgaben": gem.VORGABEN,
                          "tag_optionen": list(gem.TAG_OPTIONEN),
                          "version": gem.VERSION}, ensure_ascii=False))
        return 0

    if not argumente:
        print(json.dumps({"fehler": "Es wurde keine Datei genannt."},
                         ensure_ascii=False))
        return 2

    pfad = argumente[0]
    if not os.path.isfile(pfad):
        print(json.dumps({"fehler": "Datei nicht gefunden: " + pfad},
                         ensure_ascii=False))
        return 2

    _werte, tags, alt = gem.konfiguration_lesen(pfad)
    # Dieselbe Schreibweise, die bl_test.php auf der PHP-Seite bildet -
    # sonst vergliche man Aepfel mit Birnen.
    zeilen = []
    for t in tags:
        zeilen.append("{0}|{1}|{2}|{3}".format(
            t["kennung"], t["aktiv"], t["name"],
            gem.tag_optionen_schreiben(t.get("opt", {}))))
    zeilen.append("ALT=" + ("1" if alt else "0"))
    print(json.dumps({"zeilen": zeilen}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
