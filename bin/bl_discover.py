#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner NG - Suchlauf fuer die Oberflaeche

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
import bl_beacon          # noqa: E402

DAUER = 12


def hoechstalter(cfg):
    """Wie alt darf die Zustandsdatei sein, damit sie noch genuegt?

    Bis 1.2.10 stand hier fest 30 Sekunden, waehrend `intervall` bis 600
    eingestellt werden darf. Bei jedem Wert ueber 30 war die Datei fast
    immer zu alt, und die Oberflaeche startete JEDES Mal einen zwoelf
    Sekunden langen Parallel-Suchlauf - genau das, was der Kopf dieser
    Datei vermeiden will, und BlueZ laesst nur eine Suche gleichzeitig zu.
    """
    try:
        intervall = int(float(cfg.get("intervall", 5)))
    except (TypeError, ValueError):
        intervall = 5
    return max(30, 3 * intervall)


def aus_zustandsdatei(cfg):
    """Sichtungen des laufenden Dienstes, falls frisch genug."""
    try:
        with open(gem.STATUS_FILE, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return None
    if time.time() - int(daten.get("zeit", 0)) > hoechstalter(cfg):
        return None
    return daten.get("sichtbar") or []


def selbst_suchen(cfg):
    """Eigener Suchlauf, wenn der Dienst nicht laeuft.

    Behalten wird der STAERKSTE gemessene Wert je Geraet, nicht der letzte.
    Ueber zwoelf Sekunden werden rund acht Abfragen gemacht; faengt die
    letzte ein schwaches Paket ein (-95 dBm, weil jemand mit dem Schluessel
    in der Tasche gerade um die Ecke ging), stuende das Geraet mit -95 in
    der Liste, obwohl es zwei Sekunden vorher mit -55 zu hoeren war. Die
    Liste ist nach Signalstaerke sortiert und dient dazu, den eigenen
    Anhaenger unter fremden Geraeten zu ERKENNEN - dafuer ist der beste Wert
    der aussagekraeftige.

    Der Name wird nur ergaenzt, nie geleert: BlueZ meldet oft zuerst nur die
    Adresse und den Namen erst, wenn das naechste Advertising-Paket ihn
    mitbringt.
    """
    bluez = gem.BlueZ(cfg.get("adapter", "hci0"))
    bluez.verbinden()
    bluez.einschalten()
    try:
        grenze = int(float(cfg.get("discovery_rssi", 0)))
    except (TypeError, ValueError):
        grenze = 0
    bluez.suche_starten(grenze)
    gesehen = {}
    messungen = {}
    try:
        ende = time.time() + DAUER
        while time.time() < ende:
            for mac, werte in bluez.geraete().items():
                if werte.get("rssi") is None:
                    continue
                messungen[mac] = messungen.get(mac, 0) + 1
                alt = gesehen.get(mac)
                gedeutet = None
                if werte.get("mdata") or werte.get("sdata"):
                    gedeutet = bl_beacon.deuten(werte.get("mdata"), werte.get("sdata"))
                if alt is None:
                    gesehen[mac] = {
                        "mac": mac,
                        "rssi": werte["rssi"],
                        "rssi_letzter": werte["rssi"],
                        "name": werte.get("name", ""),
                        "seit": 0,
                        "zuletzt": int(time.time()),
                        "adresstyp": gem.adresstyp_deuten(mac, werte.get("adresstyp", "")),
                        "gekoppelt": bool(werte.get("paired") or werte.get("trusted")),
                        "beaconart": (gedeutet or {}).get("art", ""),
                        "beaconkennung": (gedeutet or {}).get("kennung", ""),
                        "sensor": (gedeutet or {}).get("werte", {}),
                    }
                    continue
                # Bester Wert gewinnt.
                if werte["rssi"] > alt["rssi"]:
                    alt["rssi"] = werte["rssi"]
                alt["rssi_letzter"] = werte["rssi"]
                alt["zuletzt"] = int(time.time())
                # Namen nur ergaenzen, nicht ueberschreiben.
                if werte.get("name") and not alt["name"]:
                    alt["name"] = werte["name"]
                if gedeutet:
                    alt["beaconart"] = gedeutet.get("art", alt["beaconart"])
                    if gedeutet.get("kennung"):
                        alt["beaconkennung"] = gedeutet["kennung"]
                    if gedeutet.get("werte"):
                        alt["sensor"] = gedeutet["werte"]
                if alt["adresstyp"] == "unbekannt":
                    alt["adresstyp"] = gem.adresstyp_deuten(mac, werte.get("adresstyp", ""))
            time.sleep(1.5)
    finally:
        # Ohne try/finally blieb die Suche stehen, wenn der Lauf mittendrin
        # abbrach - und BlueZ laesst nur eine gleichzeitig zu.
        bluez.suche_beenden()
    for mac, eintrag in gesehen.items():
        eintrag["messungen"] = messungen.get(mac, 1)
    return sorted(gesehen.values(), key=lambda g: -(g["rssi"] or -255))


def main():
    cfg, tags, _ = gem.konfiguration_lesen()
    bekannt = set()
    for t in tags:
        bekannt.add(t["kennung"])
        if t["art"] == "mac":
            bekannt.add(t["mac"])

    quelle = "Dienst"
    sichtbar = aus_zustandsdatei(cfg)
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
        g["bekannt"] = 1 if (g.get("mac") in bekannt
                             or g.get("beaconkennung") in bekannt) else 0
        g.setdefault("adresstyp", "unbekannt")
        g.setdefault("beaconart", "")
        g.setdefault("beaconkennung", "")
        g.setdefault("sensor", {})
        g.setdefault("messungen", 0)

    print(json.dumps({
        "quelle": quelle,
        "anzahl": len(sichtbar),
        "geraete": sichtbar,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
