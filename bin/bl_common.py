#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner NG - gemeinsame Grundlagen fuer Dienst und Suchlauf

Pfade, Konfiguration und der eigentliche BlueZ-Zugriff liegen hier, damit
ble_scanner_ng.py und bl_discover.py dieselbe Sicht auf die Geraete haben.

Gescannt wird ueber die **D-Bus-Schnittstelle von BlueZ**. Die alte Fassung
benutzte pybluez (`bluetooth._bluetooth`) und rief `hciconfig` auf - beides
ist seit Jahren abgekuendigt, pybluez gibt es nur fuer Python 2. Der D-Bus-Weg
braucht kein zusaetzliches Paket: bluez, python3-dbus und python3-gi sind auf
LoxBerry 3 und 4 bereits im Grundsystem.
"""

import os
import re
import time


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


# ---------------------------------------------------------------------------
# Pfade - LoxBerry ersetzt die REPLACE-Marken bei der Installation
# ---------------------------------------------------------------------------

PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = "ble_scanner_ng"

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = lb_wurzel_ermitteln() + "/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = lb_wurzel_ermitteln() + "/log/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln()
CONFIG_FILE = os.path.join(CONFIG_DIR, "ble_scanner_ng.cfg")
STATUS_FILE = "/run/shm/ble_scanner_ng_status.json"
if not os.path.isdir("/run/shm"):
    STATUS_FILE = "/tmp/ble_scanner_ng_status.json"

VERSION = "1.2.0"

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

        # Welches Format ist das? Die Entscheidung faellt an der FORM des
        # Wertes, nicht am Vorhandensein eines senkrechten Strichs.
        #
        # Bis 1.1.0 lautete die Bedingung '":" in wert and "|" not in wert'.
        # Eine Zeile im neuen Format, die nur die MAC enthaelt, also
        #
        #     tag1=AA:BB:CC:DD:EE:FF
        #
        # hat Doppelpunkte und keinen Strich - sie landete deshalb im Zweig
        # fuer das alte Format. Dort ist teile[0] das erste Feld bis zum
        # ersten Doppelpunkt, also "AA", und mac_normieren("AA") ergibt
        # Leerstring. Der Tag verschwand lautlos. Aus der Oberflaeche kann
        # das nicht kommen - die schreibt immer alle drei Felder -, aber aus
        # einer von Hand bearbeiteten Datei schon.
        #
        # Neue Regel: ein Strich heisst neues Format. Ohne Strich wird
        # versucht, den ganzen Wert als MAC zu lesen; klappt das, ist es
        # ebenfalls das neue Format. Erst wenn beides nicht zutrifft, ist es
        # die alte Schreibweise mit ihren Doppelpunkt-Feldern.
        ist_tag = re.fullmatch(r"(?i)(default\.)?TAG\d+", schluessel) is not None
        neues_format = ist_tag and ("|" in wert or mac_normieren(wert) != "")

        # --- altes Format: TAG1=BLE_..:on:1^on~2^off:Kommentar
        if ist_tag and not neues_format and ":" in wert:
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
        if neues_format:
            # split mit Obergrenze 2: alles hinter dem zweiten Strich gehoert
            # zum Kommentar, auch wenn dort selbst Striche stehen.
            #
            # Die Oberflaeche ersetzt einen eingegebenen Strich zwar durch
            # einen Schraegstrich, bevor sie speichert (bl_config_write in
            # bl_lib.php) - aus dieser Richtung kann der Fall also nicht
            # kommen. Wer die Datei aber von Hand bearbeitet, und das kommt
            # vor, verlor bis 1.1.0 alles hinter dem dritten Strich
            # stillschweigend: aus "Schluessel | Justin" wurde "Schluessel".
            teile = wert.split("|", 2)
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


# Es gibt bewusst KEIN konfiguration_schreiben() mehr.
#
# Bis 1.1.0 stand hier eines - aufgerufen wurde es von nirgends. Geschrieben
# wird die Datei ausschliesslich von der Oberflaeche (bl_config_write in
# bl_lib.php), und dabei bleibt es: zwei Schreiber fuer ein Format laufen
# zwangslaeufig auseinander, und der ungenutzte war schon falsch - er liess
# einen senkrechten Strich im Kommentar unveraendert durch und haette damit
# genau die Zeile erzeugt, die der Leser nicht mehr richtig zerlegen kann.
#
# Wer die Datei aus Python heraus schreiben will, filtert vorher \r, \n und
# | aus jedem Feld - so wie die PHP-Seite es tut.


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


def dbus_fehler_deuten(fehler, adapter="hci0"):
    """Aus einem D-Bus-Fehler eine Anweisung machen.

    Die drei Faelle brauchen voellig verschiedene Abhilfen, sehen aber im
    Protokoll gleich aus, wenn man nur str(fehler) hinschreibt. Bis 1.1.0
    stand dort genau das - und wer 'Adapter hci0 nicht ansprechbar' las,
    suchte am Dongle, obwohl die Gruppenmitgliedschaft fehlte.

    Zur oft gehoerten Behauptung, seit Bookworm reiche die Gruppe bluetooth
    nicht mehr und man muesse eine eigene Richtliniendatei unter
    /etc/dbus-1/system.d/ ablegen: das ist nicht so. BlueZ bringt seine
    Richtlinie selbst mit, und darin steht wortwoertlich

        <!-- allow users of bluetooth group to communicate -->
        <policy group="bluetooth">
          <allow send_destination="org.bluez"/>
        </policy>

    (geprueft an bluez 5.64, /etc/dbus-1/system.d/bluetooth.conf). Die
    Gruppe IST der vorgesehene Weg. Eine eigene Datei dorthin zu legen waere
    eine systemweite Rechteaenderung durch ein Plugin, sie waere doppelt, und
    beim naechsten BlueZ-Update stuende sie neben der mitgelieferten.

    Was tatsaechlich schiefgehen kann: eine neue Gruppe wirkt erst in einer
    NEUEN Sitzung. Wird der Dienst aus der Oberflaeche gestartet, erbt er die
    Gruppen des Webservers - und der laeuft womoeglich noch aus der Zeit vor
    der Installation. Beim Systemstart ueber daemon/daemon tritt das nicht
    auf. Genau darauf weist die Meldung jetzt hin.
    """
    text = str(fehler)
    if "AccessDenied" in text or "Rejected send message" in text:
        return ("Der D-Bus weist den Zugriff auf org.bluez ab. Die Gruppe bluetooth "
                "ist der vorgesehene Weg dorthin - pruefen mit: id loxberry. "
                "Fehlt sie: sudo usermod -a -G bluetooth loxberry. "
                "ACHTUNG: eine neue Gruppe wirkt erst in einer neuen Sitzung. Wird "
                "der Dienst aus der Oberflaeche gestartet, erbt er die Gruppen des "
                "Webservers - nach einem Neustart des LoxBerry ist das erledigt. "
                "Urspruenglicher Fehler: " + text)
    if "ServiceUnknown" in text or "was not provided by any .service" in text:
        return ("Der Dienst org.bluez antwortet nicht - bluetoothd laeuft nicht. "
                "Pruefen mit: systemctl status bluetooth. Starten mit: "
                "sudo systemctl enable --now bluetooth. Urspruenglicher Fehler: " + text)
    if "UnknownObject" in text or "No such object" in text or "DoesNotExist" in text:
        return ("BlueZ laeuft, kennt aber keinen Adapter {0}. Steckt ein "
                "Bluetooth-Adapter, und heisst er wirklich so? Liste mit: "
                "bluetoothctl list. Urspruenglicher Fehler: {1}".format(adapter, text))
    return text


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
            raise BlueZFehlt(dbus_fehler_deuten(fehler, self.adaptername)) from fehler
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
            raise BlueZFehlt("Adapter lässt sich nicht einschalten. "
                             + dbus_fehler_deuten(fehler, self.adaptername))

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
                raise BlueZFehlt("Suche lässt sich nicht starten. "
                                 + dbus_fehler_deuten(fehler, self.adaptername))
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
            raise BlueZFehlt("Geräteliste nicht lesbar. "
                             + dbus_fehler_deuten(fehler, self.adaptername))

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
