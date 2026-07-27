#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner - Suchlauf fuer die Oberflaeche

Gibt die gerade sichtbaren BLE-Geraete als JSON aus. Wird vom Reiter
Einstellungen aufgerufen, um Tags zum Anhaken anzubieten.

Laeuft der Dienst, wird dessen Zustandsdatei benutzt - dann muss der
Bluetooth-Adapter nicht ein zweites Mal in den Suchmodus, was BlueZ
ohnehin nur einmal zulaesst. Laeuft er nicht, wird selbst gesucht.
"""

import json
import os
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import bl_common as gem   # noqa: E402

DAUER = 12


def aus_zustandsdatei(hoechstalter=30):
    """Sichtungen des laufenden Dienstes, falls frisch genug."""
    try:
        with open(gem.STATUS_FILE, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return None
    if time.time() - int(daten.get("zeit", 0)) > hoechstalter:
        return None
    return daten.get("sichtbar") or []


def selbst_suchen(cfg):
    """Eigener Suchlauf, wenn der Dienst nicht laeuft."""
    bluez = gem.BlueZ(cfg.get("adapter", "hci0"))
    bluez.verbinden()
    bluez.einschalten()
    bluez.suche_starten()
    gesehen = {}
    ende = time.time() + DAUER
    while time.time() < ende:
        for mac, werte in bluez.geraete().items():
            if werte["rssi"] is None:
                continue
            gesehen[mac] = {"mac": mac, "rssi": werte["rssi"],
                            "name": werte["name"], "seit": 0}
        time.sleep(1.5)
    bluez.suche_beenden()
    return sorted(gesehen.values(), key=lambda g: -(g["rssi"] or -255))


def main():
    cfg, tags, _ = gem.konfiguration_lesen()
    bekannt = {t["mac"] for t in tags}

    quelle = "Dienst"
    sichtbar = aus_zustandsdatei()
    if sichtbar is None:
        quelle = "eigener Suchlauf"
        try:
            sichtbar = selbst_suchen(cfg)
        except gem.BlueZFehlt as fehler:
            print(json.dumps({
                "fehler": str(fehler),
                "hinweis": "Läuft der Dienst? Ist ein Bluetooth-Adapter vorhanden? "
                           "Im Reiter Test steht unter „Bluetooth prüfen“ mehr.",
            }, ensure_ascii=False))
            return 1

    for g in sichtbar:
        g["bekannt"] = 1 if g.get("mac") in bekannt else 0

    print(json.dumps({
        "quelle": quelle,
        "anzahl": len(sichtbar),
        "geraete": sichtbar,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
