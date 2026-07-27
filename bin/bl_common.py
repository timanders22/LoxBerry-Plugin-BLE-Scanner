#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner - gemeinsame Grundlagen fuer Dienst und Suchlauf

Pfade, Konfiguration und der eigentliche BlueZ-Zugriff liegen hier, damit
ble_scanner.py und bl_discover.py dieselbe Sicht auf die Geraete haben.

Gescannt wird ueber die **D-Bus-Schnittstelle von BlueZ**. Die alte Fassung
benutzte pybluez (`bluetooth._bluetooth`) und rief `hciconfig` auf - beides
ist seit Jahren abgekuendigt, pybluez gibt es nur fuer Python 2. Der D-Bus-Weg
braucht kein zusaetzliches Paket: bluez, python3-dbus und python3-gi sind auf
LoxBerry 3 und 4 bereits im Grundsystem.
"""

import os
import re
import time

# ---------------------------------------------------------------------------
# Pfade - LoxBerry ersetzt die REPLACE-Marken bei der Installation
# ---------------------------------------------------------------------------

PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = "ble_scanner"

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = "/opt/loxberry/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = "/opt/loxberry/log/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR", "/opt/loxberry")
CONFIG_FILE = os.path.join(CONFIG_DIR, "ble_scanner.cfg")
STATUS_FILE = "/run/shm/ble_scanner_status.json"
if not os.path.isdir("/run/shm"):
    STATUS_FILE = "/tmp/ble_scanner_status.json"

VERSION = "1.0.0"

# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------

VORGABEN = {
    "adapter": "hci0",
    "themenpraefix": "blescanner",
    "mqtt": "1",
    "http_push": "0",
    "loxberry_id": "",
    "intervall": "5",
    "abwesenheit_nach": "30",
    "aktualisierung": "60",
    "rssi_nah": "-65",
    "rssi_mittel": "-85",
}


def mac_normieren(wert):
    """MAC in die Form AA:BB:CC:DD:EE:FF bringen.

    Die alte Fassung speicherte sie als BLE_AA_BB_CC_DD_EE_FF, weil daraus
    direkt der Name des virtuellen Eingangs in Loxone wurde. Hier wird
    intern immer die echte MAC gehalten und der Loxone-Name erst beim
    Senden gebildet.
    """
    wert = (wert or "").strip().upper()
    wert = re.sub(r"^BLE[_:-]", "", wert)
    hexziffern = re.sub(r"[^0-9A-F]", "", wert)
    if len(hexziffern) != 12:
        return ""
    return ":".join(hexziffern[i:i + 2] for i in range(0, 12, 2))


def thema_saeubern(name):
    """Namen in ein MQTT-taugliches Thema umformen."""
    ersetzungen = {"ä": "ae", "ö": "oe", "ü": "ue",
                   "Ä": "Ae", "Ö": "Oe", "Ü": "Ue", "ß": "ss"}
    for alt, neu in ersetzungen.items():
        name = name.replace(alt, neu)
    name = re.sub(r"[^A-Za-z0-9_-]+", "_", name)
    return name.strip("_")


def konfiguration_lesen(pfad=None):
    """Konfiguration lesen. Erkennt und wandelt das alte Format mit.

    Alt (Config::Simple, ohne Abschnitt):
        LOXBERRY_ID=haus
        TAG1="BLE_AA_BB_CC_DD_EE_FF:on:1^on~2^off:Kommentar"

    Neu:
        [CONFIG]
        loxberry_id=haus
        tag1=AA:BB:CC:DD:EE:FF|1|Kommentar
    """
    pfad = pfad or CONFIG_FILE
    werte = dict(VORGABEN)
    tags = []
    alt_gefunden = False

    try:
        with open(pfad, "r", encoding="utf-8", errors="replace") as fh:
            zeilen = fh.read().splitlines()
    except OSError:
        return werte, tags, False

    for zeile in zeilen:
        t = zeile.strip()
        if not t or t[0] in ";#[":
            continue
        if "=" not in t:
            continue
        schluessel, wert = t.split("=", 1)
        schluessel = schluessel.strip()
        wert = wert.strip().strip('"').strip("'")

        # --- altes Format: TAG1=BLE_..:on:1^on~2^off:Kommentar
        if re.fullmatch(r"(?i)(default\.)?TAG\d+", schluessel) and ":" in wert and "|" not in wert:
            alt_gefunden = True
            teile = wert.split(":")
            # Die MAC steht mit Unterstrichen am Anfang und enthaelt selbst
            # keine Doppelpunkte, deshalb ist teile[0] die MAC.
            mac = mac_normieren(teile[0])
            aktiv = "1" if len(teile) > 1 and teile[1].strip().lower() in ("on", "1", "true") else "0"
            kommentar = ":".join(teile[3:]).strip() if len(teile) > 3 else ""
            if mac:
                tags.append({"mac": mac, "aktiv": aktiv, "name": kommentar})
            continue

        # --- neues Format: tag1=AA:BB:..|1|Kommentar
        if re.fullmatch(r"(?i)tag\d+", schluessel):
            teile = wert.split("|")
            mac = mac_normieren(teile[0] if teile else "")
            aktiv = teile[1].strip() if len(teile) > 1 else "1"
            name = teile[2].strip() if len(teile) > 2 else ""
            if mac:
                tags.append({"mac": mac,
                             "aktiv": "1" if aktiv in ("1", "on", "true") else "0",
                             "name": name})
            continue

        schluessel = re.sub(r"^default\.", "", schluessel, flags=re.I).lower()
        if schluessel in VORGABEN:
            werte[schluessel] = wert

    return werte, tags, alt_gefunden


def konfiguration_schreiben(werte, tags, pfad=None):
    """Konfiguration im neuen Format schreiben."""
    pfad = pfad or CONFIG_FILE
    try:
        os.makedirs(os.path.dirname(pfad), exist_ok=True)
    except OSError:
        pass
    zeilen = [
        "; BLE-Scanner",
        "; Geschrieben von der Plugin-Oberflaeche.",
        "",
        "[CONFIG]",
    ]
    for schluessel, vorgabe in VORGABEN.items():
        zeilen.append("{0}={1}".format(schluessel, werte.get(schluessel, vorgabe)))
    zeilen.append("")
    for nummer, tag in enumerate(tags, start=1):
        zeilen.append("tag{0}={1}|{2}|{3}".format(
            nummer, tag["mac"], tag.get("aktiv", "1"), tag.get("name", "")))
    try:
        with open(pfad, "w", encoding="utf-8") as fh:
            fh.write("\n".join(zeilen) + "\n")
        os.chmod(pfad, 0o644)
        return True
    except OSError:
        return False


def signalstufe(rssi, nah, mittel):
    """RSSI in eine grobe Stufe uebersetzen: 3 nah, 2 mittel, 1 schwach, 0 weg."""
    if rssi is None:
        return 0
    try:
        rssi = int(rssi)
    except (TypeError, ValueError):
        return 0
    if rssi >= int(nah):
        return 3
    if rssi >= int(mittel):
        return 2
    return 1


# ---------------------------------------------------------------------------
# BlueZ ueber D-Bus
# ---------------------------------------------------------------------------

BLUEZ = "org.bluez"
ADAPTER_IF = "org.bluez.Adapter1"
DEVICE_IF = "org.bluez.Device1"
PROPS_IF = "org.freedesktop.DBus.Properties"
OBJMGR_IF = "org.freedesktop.DBus.ObjectManager"


class BlueZFehlt(Exception):
    """python3-dbus fehlt oder BlueZ antwortet nicht."""


class BlueZ:
    """Duenne Huelle um die BlueZ-D-Bus-Schnittstelle."""

    def __init__(self, adapter="hci0"):
        self.adaptername = adapter
        self.bus = None
        self.adapterpfad = "/org/bluez/" + adapter

    def verbinden(self):
        try:
            import dbus
        except ImportError as fehler:
            raise BlueZFehlt(
                "python3-dbus fehlt. Nachinstallieren mit: "
                "sudo apt-get install -y python3-dbus") from fehler
        try:
            self.bus = dbus.SystemBus()
            obj = self.bus.get_object(BLUEZ, self.adapterpfad)
            self.props = dbus.Interface(obj, PROPS_IF)
            self.adapter = dbus.Interface(obj, ADAPTER_IF)
        except Exception as fehler:  # noqa: BLE001
            raise BlueZFehlt(
                "Bluetooth-Adapter {0} nicht ansprechbar: {1}".format(
                    self.adaptername, fehler)) from fehler
        return True

    def einschalten(self):
        """Adapter einschalten. Ersetzt das frueher aufgerufene
        'hciconfig hci0 up' und den ganzen hciuart-Block im Startskript."""
        import dbus
        try:
            if not bool(self.props.Get(ADAPTER_IF, "Powered")):
                self.props.Set(ADAPTER_IF, "Powered", dbus.Boolean(True))
                time.sleep(1.0)
            return True
        except Exception as fehler:  # noqa: BLE001
            raise BlueZFehlt("Adapter lässt sich nicht einschalten: {0}".format(fehler))

    def suche_starten(self):
        import dbus
        try:
            # Nur auf LE hoeren - klassisches Bluetooth interessiert nicht
            # und macht den Scan unnoetig langsam.
            self.adapter.SetDiscoveryFilter({
                "Transport": dbus.String("le"),
                "DuplicateData": dbus.Boolean(True),
            })
        except Exception:  # noqa: BLE001
            pass   # aeltere BlueZ-Fassungen kennen den Filter nicht
        try:
            if not bool(self.props.Get(ADAPTER_IF, "Discovering")):
                self.adapter.StartDiscovery()
        except Exception as fehler:  # noqa: BLE001
            text = str(fehler)
            if "InProgress" not in text:
                raise BlueZFehlt("Suche lässt sich nicht starten: {0}".format(text))
        return True

    def suche_beenden(self):
        try:
            self.adapter.StopDiscovery()
        except Exception:  # noqa: BLE001
            pass

    def geraete(self):
        """Alle bekannten Geraete des Adapters.

        Rueckgabe: {MAC: {"rssi": int|None, "name": str, "pfad": str}}
        BlueZ liefert RSSI nur, solange das Geraet in Reichweite ist -
        genau das brauchen wir als Anwesenheitszeichen.
        """
        try:
            obj = self.bus.get_object(BLUEZ, "/")
            import dbus
            objmgr = dbus.Interface(obj, OBJMGR_IF)
            verwaltet = objmgr.GetManagedObjects()
        except Exception as fehler:  # noqa: BLE001
            raise BlueZFehlt("Geräteliste nicht lesbar: {0}".format(fehler))

        out = {}
        for pfad, schnittstellen in verwaltet.items():
            geraet = schnittstellen.get(DEVICE_IF)
            if not geraet:
                continue
            if not str(pfad).startswith(self.adapterpfad + "/"):
                continue
            mac = mac_normieren(str(geraet.get("Address", "")))
            if not mac:
                continue
            rssi = geraet.get("RSSI")
            name = geraet.get("Alias") or geraet.get("Name") or ""
            out[mac] = {
                "rssi": int(rssi) if rssi is not None else None,
                "name": str(name),
                "pfad": str(pfad),
            }
        return out

    def vergessen(self, pfad):
        """Geraet aus dem BlueZ-Zwischenspeicher entfernen.

        Ohne das sammelt BlueZ mit der Zeit hunderte Eintraege an, und alte
        Geraete tauchen ohne RSSI immer wieder in der Liste auf.
        """
        try:
            self.adapter.RemoveDevice(pfad)
            return True
        except Exception:  # noqa: BLE001
            return False
