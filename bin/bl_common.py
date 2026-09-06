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

Grundlage ist das Plugin BLE-Scanner von Christian Woerstenfeld (2021.2.3,
Apache-Lizenz 2.0). Die Aenderungen stehen in NOTICE.
"""

import os
import re
import socket
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

def _ordner_aus_ablageort():
    """Den Plugin-Ordnernamen aus dem eigenen Ablageort ableiten.

    Diese Datei liegt installiert unter <home>/bin/plugins/<ordner>/. Der
    Name des uebergeordneten Verzeichnisses IST der Ordnername.

    Bis 1.2.10 stand hier ein festes "ble_scanner_ng" als Rueckfall. Das war
    geraten: LoxBerry haengt bei einem Namenskonflikt 01, 02 ... an den
    Ordnernamen, und dann zeigte jeder Pfad auf eine fremde oder gar keine
    Installation - worauf konfiguration_lesen() stillschweigend die Vorgaben
    lieferte und der Dienst mit null Tags weiterlief. bl_lib.php leitet den
    Namen seit jeher ab; hier wird es jetzt genauso gemacht.
    """
    eltern = os.path.basename(os.path.dirname(os.path.abspath(__file__)))
    if eltern and eltern not in ("bin", "."):
        return eltern
    return "ble_scanner_ng"


PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = _ordner_aus_ablageort()

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = lb_wurzel_ermitteln() + "/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = lb_wurzel_ermitteln() + "/log/plugins/" + PLUGIN_NAME

DATA_DIR = "REPLACELBPDATADIR"
if DATA_DIR.startswith("REPLACE"):
    DATA_DIR = lb_wurzel_ermitteln() + "/data/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln()
CONFIG_FILE = os.path.join(CONFIG_DIR, "ble_scanner_ng.cfg")
VERLAUF_FILE = os.path.join(DATA_DIR, "verlauf.csv")

_SHM = "/run/shm" if os.path.isdir("/run/shm") else "/tmp"
STATUS_FILE = _SHM + "/ble_scanner_ng_status.json"
STEUER_FILE = _SHM + "/ble_scanner_ng_steuer.json"


# ---------------------------------------------------------------------------
# Fassungsnummer - EINE Quelle
# ---------------------------------------------------------------------------
#
# Bis 1.2.10 stand hier eine zweite, von Hand gepflegte Zahl. Sie war
# 1.2.0, waehrend plugin.cfg 1.2.10 und release.cfg 1.2.9 sagten - drei
# verschiedene Fassungen in einem Archiv, und die aus dieser Datei landete im
# Protokoll und in der Zustandsdatei. Wer damit Hilfe suchte, nannte eine
# Fassung, die es seit acht Staenden nicht mehr gab.
#
# Jetzt schreibt postinstall.sh die Nummer, die der Installer ihm uebergibt
# ($PVERSION), nach <configdir>/fassung.txt. Das ist der einzige Ort, den
# beide Seiten (Python und PHP) lesen. Die Konstante darunter ist nur der
# Rueckfall fuer den nicht installierten Zustand; der Reiter Test misst
# nach, ob beide uebereinstimmen.

VERSION_RUECKFALL = "1.3.0"


def fassung():
    """Fassungsnummer: aus <configdir>/fassung.txt, sonst der Rueckfall."""
    try:
        with open(os.path.join(CONFIG_DIR, "fassung.txt"),
                  "r", encoding="utf-8") as fh:
            wert = fh.read().strip()
        if re.fullmatch(r"\d+(\.\d+){0,3}", wert):
            return wert
    except OSError:
        pass
    return VERSION_RUECKFALL


VERSION = fassung()


# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------
#
# ACHTUNG: diese Liste muss Schluessel fuer Schluessel zu bl_defaults() in
# webfrontend/htmlauth/bl_lib.php passen. bl_config_write() baut die Datei
# vollstaendig aus bl_defaults() neu auf - ein Schluessel, den nur diese
# Seite kennt, ist nach dem naechsten Speichern weg. Der Reiter Test misst
# die Uebereinstimmung nach (Pruefzeile "Vorgabewerte").

VORGABEN = {
    # -- Grundlage
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
    # -- neu in 1.3.0: Anwesenheit ruhiger machen
    "rssi_minimum": "-100",       # schwaechere Pakete zaehlen nicht als Sichtung
    "ankunft_sichtungen": "1",    # so viele Sichtungen, bevor "anwesend" gilt
    "glaettung": "1",             # RSSI mitteln statt letzten Wert nehmen
    "glaettung_fenster": "5",     # so viele Messungen in den Median
    "hysterese_db": "3",          # Stufenwechsel erst ab dieser Ueberschreitung
    # -- Betrieb
    "betriebsart": "signal",      # signal | abfrage
    "wachhund": "1",              # Adapter wiederbeleben, wenn nichts mehr kommt
    "wachhund_stille": "300",     # Sekunden ohne JEDE Sichtung
    "discovery_rssi": "0",        # 0 = aus, sonst Grenze im BlueZ-Filter
    "log_kappung_kb": "500",      # Protokoll ab dieser Groesse kuerzen
    # -- Aufzeichnung
    "ereignisse": "1",
    "ereignisse_tage": "7",
    # -- Mehrere Scanner
    "scanner_name": "",           # leer = Rechnername
    "scanner_themen": "0",        # zweiten Themenzweig je Scanner senden
    "raum": "0",                  # Raumzuordnung aus mehreren Scannern bilden
    "raum_hysterese_db": "5",
    "raum_ausgleich_db": "0",     # Ausgleich DIESES Scanners
    # -- Abgeleitete Werte
    "entfernung": "0",            # Entfernungsschaetzung veroeffentlichen
    "daempfung": "2.5",           # Pfadverlustexponent n
    "beacon": "1",                # Werbedaten dekodieren (iBeacon, Eddystone, ...)
    # -- Batterie ueber GATT (stoert den Scan - deshalb ab Werk aus)
    "batterie": "0",
    "batterie_uhrzeit": "04:00",
}

# Erlaubte Schluessel der Zusatzangaben je Tag (viertes Feld der Tag-Zeile).
TAG_OPTIONEN = ("abw", "min", "ref", "alias", "person", "batt", "raum")


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


def ibeacon_normieren(wert):
    """iBeacon-Kennung in die Form IB:<32 Hex>:<major>:<minor> bringen.

    Ein Geraet mit wechselnder Adresse ist ueber die MAC nicht zu fassen;
    ein iBeacon-Tripel dagegen ist stabil. Erkannt wird alles, was mit
    "ib:" beginnt.
    """
    wert = (wert or "").strip()
    if not re.match(r"(?i)^ib[:_-]", wert):
        return ""
    rest = re.sub(r"(?i)^ib[:_-]", "", wert)
    teile = re.split(r"[:_\-\s]+", rest.strip())
    uuid = re.sub(r"[^0-9A-Fa-f]", "", teile[0] if teile else "")
    if len(uuid) != 32:
        return ""
    try:
        major = int(teile[1]) if len(teile) > 1 and teile[1] != "" else 0
        minor = int(teile[2]) if len(teile) > 2 and teile[2] != "" else 0
    except (TypeError, ValueError):
        return ""
    if not (0 <= major <= 65535 and 0 <= minor <= 65535):
        return ""
    return "IB:{0}:{1}:{2}".format(uuid.upper(), major, minor)


def kennung_lesen(wert):
    """Erstes Feld einer Tag-Zeile deuten.

    Rueckgabe: (art, kennung) mit art in ("mac", "ibeacon") - oder ("", "").
    """
    ib = ibeacon_normieren(wert)
    if ib:
        return "ibeacon", ib
    mac = mac_normieren(wert)
    if mac:
        return "mac", mac
    return "", ""


def thema_saeubern(name):
    """Namen in ein MQTT-taugliches Thema umformen."""
    ersetzungen = {"ä": "ae", "ö": "oe", "ü": "ue",
                   "Ä": "Ae", "Ö": "Oe", "Ü": "Ue", "ß": "ss"}
    for alt, neu in ersetzungen.items():
        name = name.replace(alt, neu)
    name = re.sub(r"[^A-Za-z0-9_-]+", "_", name)
    return name.strip("_")


def thema_der_kennung(art, kennung, alias=""):
    """Themenzweig eines Tags.

    Ein gesetzter Alias gewinnt - er entkoppelt die Loxone-Konfiguration von
    der Hardware: wird ein defekter Anhaenger getauscht, aendert sich die MAC,
    nicht aber der Name des virtuellen Eingangs.
    """
    alias = thema_saeubern((alias or "").strip())
    if alias:
        return alias
    if art == "ibeacon":
        # IB:<uuid>:<major>:<minor> -> ib_<letzte 8 Hex>_<major>_<minor>
        teile = kennung.split(":")
        if len(teile) == 4:
            return "ib_{0}_{1}_{2}".format(teile[1][-8:].lower(), teile[2], teile[3])
        return thema_saeubern(kennung)
    return thema_saeubern((kennung or "").replace(":", "")) or "unbekannt"


# Ein viertes Feld ist nur dann eine Zusatzangabe, wenn es auch so
# AUSSIEHT. Sonst gehoert es zum Kommentar.
#
# Der Grund ist derselbe wie bei der Formaterkennung weiter unten: die
# Entscheidung faellt an der FORM des Wertes. Eine von Hand geschriebene
# Zeile
#
#     tag1=AA:BB:CC:DD:EE:FF|1|Schluessel | Justin
#
# hat vier Felder, und das vierte ist " Justin" - keine Zusatzangabe. Ohne
# diese Pruefung waere der Kommentar auf "Schluessel" gekuerzt, also genau
# der Fehler, den 1.2.0 fuer den dritten Strich behoben hat, nur eine
# Stelle weiter rechts. Gemessen in bl_selbsttest.py, Fall 2.
OPTIONSFORM = re.compile(r"(?i)^\s*(" + "|".join(TAG_OPTIONEN) + r")\s*=")


def sind_optionen(text):
    """Sieht dieses Feld nach Zusatzangaben aus?"""
    return OPTIONSFORM.match(text or "") is not None


def tag_optionen_lesen(text):
    """Viertes Feld einer Tag-Zeile zerlegen: "abw=90,alias=anna".

    Unbekannte Schluessel werden verworfen, nicht durchgereicht - sonst
    wandern sie beim naechsten Speichern in eine Datei, die sie niemand mehr
    zuordnen kann.
    """
    out = {}
    for stueck in (text or "").split(","):
        stueck = stueck.strip()
        if not stueck or "=" not in stueck:
            continue
        k, v = stueck.split("=", 1)
        k = k.strip().lower()
        if k in TAG_OPTIONEN:
            out[k] = v.strip()
    return out


def tag_optionen_schreiben(opt):
    """Umkehrung von tag_optionen_lesen(). Komma und Gleichheitszeichen sind
    Trennzeichen und werden aus den Werten entfernt - genauso wie der
    senkrechte Strich aus den drei vorderen Feldern."""
    teile = []
    for k in TAG_OPTIONEN:
        v = (opt or {}).get(k, "")
        v = re.sub(r"[,=|\r\n]", "", str(v)).strip()
        if v != "":
            teile.append(k + "=" + v)
    return ",".join(teile)


def konfiguration_lesen(pfad=None):
    """Konfiguration lesen. Erkennt und wandelt das alte Format mit.

    Alt (Config::Simple, ohne Abschnitt):
        LOXBERRY_ID=haus
        TAG1="BLE_AA_BB_CC_DD_EE_FF:on:1^on~2^off:Kommentar"

    Neu:
        [CONFIG]
        loxberry_id=haus
        tag1=AA:BB:CC:DD:EE:FF|1|Kommentar|abw=90,alias=anna

    Rueckgabe: (werte, tags, altes_format). Ein Tag ist
        {"art", "kennung", "mac", "aktiv", "name", "opt"}
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
        # Leerstring. Der Tag verschwand lautlos.
        #
        # Neue Regel: ein Strich heisst neues Format. Ohne Strich wird
        # versucht, den ganzen Wert als Kennung zu lesen; klappt das, ist es
        # ebenfalls das neue Format. Erst wenn beides nicht zutrifft, ist es
        # die alte Schreibweise mit ihren Doppelpunkt-Feldern.
        ist_tag = re.fullmatch(r"(?i)(default\.)?TAG\d+", schluessel) is not None
        neues_format = ist_tag and ("|" in wert or kennung_lesen(wert)[0] != "")

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
                tags.append({"art": "mac", "kennung": mac, "mac": mac,
                             "aktiv": aktiv, "name": kommentar, "opt": {}})
            continue

        # --- neues Format: tag1=AA:BB:..|1|Kommentar|abw=90
        if neues_format:
            # split mit Obergrenze 3: alles hinter dem dritten Strich gehoert
            # zu den Zusatzangaben. Der Kommentar (Feld 3) darf selbst keine
            # Striche enthalten - die Oberflaeche ersetzt sie beim Schreiben.
            teile = wert.split("|", 3)
            art, kennung = kennung_lesen(teile[0] if teile else "")
            aktiv = teile[1].strip() if len(teile) > 1 else "1"
            name = teile[2] if len(teile) > 2 else ""
            viertes = teile[3] if len(teile) > 3 else ""
            if viertes and sind_optionen(viertes):
                opt = tag_optionen_lesen(viertes)
            else:
                # Kein Zusatzfeld: der Strich gehoerte zum Kommentar.
                opt = {}
                if viertes:
                    name = name + "|" + viertes
            name = name.strip()
            if art:
                tags.append({"art": art,
                             "kennung": kennung,
                             "mac": kennung if art == "mac" else "",
                             "aktiv": "1" if aktiv in ("1", "on", "true") else "0",
                             "name": name,
                             "opt": opt})
            continue

        schluessel = re.sub(r"^default\.", "", schluessel, flags=re.I).lower()
        if schluessel in VORGABEN:
            werte[schluessel] = wert

    return werte, tags, alt_gefunden


# Es gibt bewusst KEIN konfiguration_schreiben() hier.
#
# Geschrieben wird die Datei ausschliesslich von der Oberflaeche
# (bl_config_write in bl_lib.php), und dabei bleibt es: zwei Schreiber fuer
# ein Format laufen zwangslaeufig auseinander.
#
# Genau das ist mit den beiden LESERN passiert: bis 1.2.10 trug bl_lib.php
# noch die Formaterkennung und das Zerlegen des Kommentars in der Fassung,
# die hier oben als falsch beschrieben und behoben ist. Gemessen an
# "tag1=AA:BB:CC:DD:EE:FF" sah Python einen Tag und PHP keinen - und beim
# naechsten Speichern war er weg. Seit 1.3.0 misst der Reiter Test die
# Uebereinstimmung der beiden Leser an einer Reihe von Prueffaellen nach
# (Pruefzeile "Beide Konfigurationsleser").


def signalstufe(rssi, nah, mittel, bisher=None, hysterese=0):
    """RSSI in eine grobe Stufe uebersetzen: 3 nah, 2 mittel, 1 schwach, 0 weg.

    Mit Hysterese: liegt der Wert nahe an einer Schwelle, bleibt die bisherige
    Stufe stehen, bis er sie um `hysterese` dB ueberschreitet. Ohne das
    wechselt die Stufe bei einem Anhaenger, der genau auf der Schwelle liegt,
    in jeder Runde - und jeder Wechsel ist eine MQTT-Nachricht und eine
    Flanke am Miniserver.
    """
    if rssi is None:
        return 0
    try:
        rssi = int(rssi)
        nah = int(nah)
        mittel = int(mittel)
        hysterese = max(0, int(hysterese))
    except (TypeError, ValueError):
        return 0

    def roh(x):
        if x >= nah:
            return 3
        if x >= mittel:
            return 2
        return 1

    neu = roh(rssi)
    if bisher is None or hysterese == 0 or bisher == 0 or neu == bisher:
        return neu
    # Aufwaerts nur, wenn die Schwelle um die Hysterese ueberschritten ist;
    # abwaerts nur, wenn sie um die Hysterese unterschritten ist.
    if neu > bisher:
        schwelle = nah if bisher == 2 else mittel
        return neu if rssi >= schwelle + hysterese else bisher
    schwelle = nah if neu == 2 else mittel
    return neu if rssi < schwelle - hysterese else bisher


def median(werte):
    """Median einer kleinen Liste. Ohne numpy, ohne statistics-Import."""
    w = sorted(x for x in werte if x is not None)
    if not w:
        return None
    n = len(w)
    if n % 2:
        return w[n // 2]
    return (w[n // 2 - 1] + w[n // 2]) / 2.0


def geglaettet(messungen, fenster=5):
    """Median ueber die letzten `fenster` Messungen, danach leicht gemittelt.

    Warum Median plus exponentiell gleitendes Mittel und nicht Kalman: der
    Median entfernt Ausreisser robust, das EMA glaettet den Rest, und beides
    steht in zehn Zeilen ohne Bibliothek da. Ein Kalman-Filter braucht
    Prozess- und Messrauschen als Parameter, die man ohne eine Messreihe am
    Ort nicht sinnvoll setzen kann.

    `messungen` ist eine Liste (zeit, rssi), aelteste zuerst.
    """
    try:
        fenster = max(1, int(fenster))
    except (TypeError, ValueError):
        fenster = 5
    letzte = [r for _z, r in messungen[-fenster:] if r is not None]
    if not letzte:
        return None
    m = median(letzte)
    if m is None:
        return None
    # EMA ueber den Median hinweg, damit ein Sprung nicht sofort durchschlaegt.
    wert = float(letzte[0])
    for r in letzte:
        wert = 0.6 * wert + 0.4 * float(r)
    return int(round(0.5 * m + 0.5 * wert))


def entfernung_schaetzen(rssi, ref_1m, daempfung):
    """Entfernung in Metern aus dem Pfadverlustmodell.

        d = 10 ^ ((P1m - RSSI) / (10 * n))

    P1m ist der RSSI in einem Meter Abstand (kalibriert oder aus dem
    iBeacon-Feld "measured power"), n der Daempfungsexponent: 2,0 bei freier
    Sicht, 2,5 bis 3,5 in einer Wohnung.

    Das ist eine SCHAETZUNG. In Innenraeumen liegt sie leicht um den Faktor
    zwei daneben; die Oberflaeche sagt das auch so.
    """
    try:
        rssi = float(rssi)
        ref_1m = float(ref_1m)
        daempfung = float(daempfung)
    except (TypeError, ValueError):
        return None
    if daempfung <= 0:
        return None
    try:
        d = 10.0 ** ((ref_1m - rssi) / (10.0 * daempfung))
    except (OverflowError, ValueError):
        return None
    if d != d or d < 0 or d > 1000:      # NaN oder unsinnig
        return None
    return round(d, 1)


def rechnername():
    try:
        return thema_saeubern(socket.gethostname().split(".")[0]) or "loxberry"
    except Exception:      # noqa: BLE001
        return "loxberry"


def log_kappen(pfad, grenze_kb=500, behalten=200):
    """Protokolldatei kuerzen. Hausmuster: ab 500 kB bleiben 200 Zeilen.

    log/plugins liegt auf einer Ramdisk; eine unbegrenzt wachsende Datei ist
    dort kein Schoenheitsfehler, sondern trifft irgendwann ALLE Plugins.
    Rueckgabe: True, wenn gekuerzt wurde.
    """
    try:
        grenze = max(16, int(grenze_kb)) * 1024
    except (TypeError, ValueError):
        grenze = 512000
    try:
        if not os.path.isfile(pfad) or os.path.getsize(pfad) <= grenze:
            return False
        with open(pfad, "r", encoding="utf-8", errors="replace") as fh:
            zeilen = fh.read().splitlines()
        rest = zeilen[-max(20, int(behalten)):]
        temp = pfad + ".tmp"
        with open(temp, "w", encoding="utf-8") as fh:
            fh.write("\n".join(rest) + "\n")
        os.replace(temp, pfad)
        try:
            os.chmod(pfad, 0o644)
        except OSError:
            pass
        return True
    except OSError:
        return False


# ---------------------------------------------------------------------------
# BlueZ ueber D-Bus
# ---------------------------------------------------------------------------

BLUEZ = "org.bluez"
ADAPTER_IF = "org.bluez.Adapter1"
DEVICE_IF = "org.bluez.Device1"
BATTERY_IF = "org.bluez.Battery1"
PROPS_IF = "org.freedesktop.DBus.Properties"
OBJMGR_IF = "org.freedesktop.DBus.ObjectManager"


class BlueZFehlt(Exception):
    """python3-dbus fehlt oder BlueZ antwortet nicht."""


def dbus_fehler_deuten(fehler, adapter="hci0"):
    """Aus einem D-Bus-Fehler eine Anweisung machen.

    Die drei Faelle brauchen voellig verschiedene Abhilfen, sehen aber im
    Protokoll gleich aus, wenn man nur str(fehler) hinschreibt.

    Zur oft gehoerten Behauptung, seit Bookworm reiche die Gruppe bluetooth
    nicht mehr und man muesse eine eigene Richtliniendatei unter
    /etc/dbus-1/system.d/ ablegen: das ist nicht so. BlueZ bringt seine
    Richtlinie selbst mit, und darin steht wortwoertlich

        <!-- allow users of bluetooth group to communicate -->
        <policy group="bluetooth">
          <allow send_destination="org.bluez"/>
        </policy>

    (geprueft an bluez 5.64, /etc/dbus-1/system.d/bluetooth.conf). Die
    Gruppe IST der vorgesehene Weg.

    Was tatsaechlich schiefgehen kann: eine neue Gruppe wirkt erst in einer
    NEUEN Sitzung. Wird der Dienst aus der Oberflaeche gestartet, erbt er die
    Gruppen des Webservers.
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


def adresstyp_deuten(mac, adresstyp):
    """Taugt diese Adresse als dauerhafte Kennung?

    Rueckgabe: "fest" | "statisch" | "wechselnd" | "unbekannt"

    BlueZ liefert in Device1.AddressType nur "public" oder "random". Das
    genuegt nicht: sehr viele fest adressierte Beacons benutzen eine
    STATISCHE Zufallsadresse und sind trotzdem dauerhaft brauchbar. Die
    Unterscheidung steckt in den obersten zwei Bit des hoechstwertigen
    Adressbytes (Bluetooth-Kernspezifikation, "Device Address"):

        11  statisch zufaellig  - fest bis zum Neustart des Geraetes,
                                  in der Praxis dauerhaft
        01  aufloesbar privat   - wechselt typisch alle 15 Minuten
                                  (Telefone, Uhren, AirTags)
        00  nicht aufloesbar    - wechselt ebenfalls

    Fehlt AddressType, wird "unbekannt" zurueckgegeben - nicht geraten.
    """
    typ = (adresstyp or "").strip().lower()
    if typ == "public":
        return "fest"
    if typ != "random":
        return "unbekannt"
    hexz = re.sub(r"[^0-9A-Fa-f]", "", mac or "")
    if len(hexz) != 12:
        return "unbekannt"
    try:
        oben = int(hexz[0:2], 16) >> 6
    except ValueError:
        return "unbekannt"
    if oben == 0b11:
        return "statisch"
    return "wechselnd"


def _bytes_aus_dbus(wert):
    """dbus.Array von dbus.Byte in echte bytes wandeln.

    Ohne das scheitert spaeter json.dump() an der Zustandsdatei - dbus.Byte
    ist kein int, das json kennt.
    """
    try:
        return bytes(bytearray(int(b) & 0xFF for b in wert))
    except (TypeError, ValueError):
        return b""


class BlueZ:
    """Duenne Huelle um die BlueZ-D-Bus-Schnittstelle."""

    def __init__(self, adapter="hci0"):
        self.adaptername = adapter
        self.bus = None
        self.props = None
        self.adapter = None
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

    def aus_und_an(self):
        """Adapter aus- und wieder einschalten (Wachhund, Stufe 3).

        Das darf der Dienst als Mitglied der Gruppe bluetooth; es ist
        dieselbe Eigenschaft, die einschalten() ohnehin setzt. Ein
        'systemctl restart bluetooth' waere die naechste Stufe, braucht aber
        eine sudo-Regel - eine systemweite Rechteaenderung durch ein Plugin
        wird hier bewusst nicht gemacht. Das Protokoll nennt stattdessen den
        Befehl, der helfen wuerde.
        """
        import dbus
        try:
            self.props.Set(ADAPTER_IF, "Powered", dbus.Boolean(False))
            time.sleep(2.0)
            self.props.Set(ADAPTER_IF, "Powered", dbus.Boolean(True))
            time.sleep(2.0)
            return True
        except Exception as fehler:  # noqa: BLE001
            raise BlueZFehlt("Adapter lässt sich nicht neu aufsetzen. "
                             + dbus_fehler_deuten(fehler, self.adaptername))

    def sucht(self):
        """Laeuft die Suche gerade? None, wenn die Frage nicht zu klaeren ist.

        Ein sauberes True/False/None ist wichtig: der Wachhund darf im
        Zweifel NICHT zuschlagen (fail safe).
        """
        try:
            return bool(self.props.Get(ADAPTER_IF, "Discovering"))
        except Exception:  # noqa: BLE001
            return None

    def suche_starten(self, rssi_grenze=0):
        """Suche starten. Der Filter wird GESTUFT aufgebaut.

        Bis 1.2.10 stand hier EIN Aufruf mit try/except Exception: pass.
        Kannte eine aeltere BlueZ-Fassung einen der Schluessel nicht, fiel
        der GANZE Filter weg - einschliesslich Transport="le". Dann scannt
        der Adapter wieder klassisches Bluetooth mit, und niemand erfaehrt
        es. Jetzt wird der reichste Filter zuerst versucht und bei einem
        Fehlschlag Schluessel fuer Schluessel abgespeckt.

        Rueckgabe: die gesetzte Stufe (0 = gar kein Filter).
        """
        import dbus
        stufen = []
        try:
            grenze = int(rssi_grenze)
        except (TypeError, ValueError):
            grenze = 0
        if grenze < 0:
            # RSSI und Pathloss schliessen einander aus - nur einer von beiden.
            stufen.append({
                "Transport": dbus.String("le"),
                "DuplicateData": dbus.Boolean(True),
                "RSSI": dbus.Int16(grenze),
            })
        stufen.append({
            "Transport": dbus.String("le"),
            "DuplicateData": dbus.Boolean(True),
        })
        stufen.append({"Transport": dbus.String("le")})

        gesetzt = 0
        for nr, f in enumerate(stufen, 1):
            try:
                self.adapter.SetDiscoveryFilter(f)
                gesetzt = len(stufen) - nr + 1
                break
            except Exception:  # noqa: BLE001
                continue

        try:
            if not bool(self.props.Get(ADAPTER_IF, "Discovering")):
                self.adapter.StartDiscovery()
        except Exception as fehler:  # noqa: BLE001
            text = str(fehler)
            if "InProgress" not in text:
                raise BlueZFehlt("Suche lässt sich nicht starten. "
                                 + dbus_fehler_deuten(fehler, self.adaptername))
        return gesetzt

    def suche_beenden(self):
        try:
            self.adapter.StopDiscovery()
        except Exception:  # noqa: BLE001
            pass

    def geraet_lesen(self, pfad, geraet):
        """Ein Device1-Abbild in unsere Form bringen."""
        mac = mac_normieren(str(geraet.get("Address", "")))
        if not mac:
            return None, None
        rssi = geraet.get("RSSI")
        name = geraet.get("Alias") or geraet.get("Name") or ""
        txp = geraet.get("TxPower")
        mdata = {}
        for k, v in (geraet.get("ManufacturerData") or {}).items():
            try:
                mdata[int(k)] = _bytes_aus_dbus(v)
            except (TypeError, ValueError):
                continue
        sdata = {}
        for k, v in (geraet.get("ServiceData") or {}).items():
            sdata[str(k).lower()] = _bytes_aus_dbus(v)
        return mac, {
            "rssi": int(rssi) if rssi is not None else None,
            "name": str(name),
            "pfad": str(pfad),
            "adresstyp": str(geraet.get("AddressType", "") or ""),
            "txpower": int(txp) if txp is not None else None,
            # Gekoppelte, vertraute und verbundene Geraete werden beim
            # Aufraeumen VERSCHONT - RemoveDevice loescht sonst die Kopplung
            # einer fremden Tastatur oder eines Lautsprechers, lautlos und
            # dauerhaft.
            "paired": bool(geraet.get("Paired", False)),
            "trusted": bool(geraet.get("Trusted", False)),
            "connected": bool(geraet.get("Connected", False)),
            "mdata": mdata,
            "sdata": sdata,
        }

    def geraete(self):
        """Alle bekannten Geraete des Adapters.

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
            mac, werte = self.geraet_lesen(pfad, geraet)
            if mac:
                out[mac] = werte
        return out

    def eigenschaften(self, pfad):
        """Alle Device1-Eigenschaften eines Pfades einzeln nachlesen.

        Wird von der Signalbetriebsart gebraucht: PropertiesChanged liefert
        nur die GEAENDERTEN Werte, meist also nur RSSI. Adresse und
        Werbedaten muessen dann einmal nachgeholt werden.
        """
        try:
            import dbus
            obj = self.bus.get_object(BLUEZ, pfad)
            props = dbus.Interface(obj, PROPS_IF)
            return self.geraet_lesen(pfad, props.GetAll(DEVICE_IF))
        except Exception:  # noqa: BLE001
            return None, None

    def batterie_lesen(self, pfad, zeitgrenze=12):
        """Batteriestand ueber GATT lesen. Rueckgabe: (prozent, meldung).

        BlueZ bildet nach einer Verbindung org.bluez.Battery1 mit der
        Eigenschaft Percentage auf das Geraeteobjekt ab, WENN das Geraet den
        Battery Service (0x180F) anbietet. Das erspart den Umgang mit
        GattService1/GattCharacteristic1 von Hand.

        Der Aufrufer muss die Suche vorher anhalten und danach wieder
        starten - waehrend einer Verbindung sieht der Adapter nichts.
        """
        import dbus     # noqa: F401  (nur damit ein fehlendes dbus hier auffaellt)
        geraet = None
        verbunden_von_uns = False
        try:
            obj = self.bus.get_object(BLUEZ, pfad)
            geraet = dbus.Interface(obj, DEVICE_IF)
            props = dbus.Interface(obj, PROPS_IF)
        except Exception as fehler:  # noqa: BLE001
            return None, "Geräteobjekt nicht erreichbar: " + str(fehler)

        try:
            if not bool(props.Get(DEVICE_IF, "Connected")):
                geraet.Connect()
                verbunden_von_uns = True
            ende = time.time() + zeitgrenze
            while time.time() < ende:
                try:
                    if bool(props.Get(DEVICE_IF, "ServicesResolved")):
                        break
                except Exception:  # noqa: BLE001
                    pass
                time.sleep(0.5)
            wert = props.Get(BATTERY_IF, "Percentage")
            return int(wert), ""
        except Exception as fehler:  # noqa: BLE001
            text = str(fehler)
            if "Battery1" in text or "UnknownInterface" in text or "InvalidArgs" in text:
                return None, ("Das Gerät bietet keinen Battery Service (0x180F) an. "
                              "Die meisten Beacons sind nicht verbindungsfähig.")
            return None, text
        finally:
            if verbunden_von_uns and geraet is not None:
                try:
                    geraet.Disconnect()
                except Exception:  # noqa: BLE001
                    pass

    def vergessen(self, pfad):
        """Geraet aus dem BlueZ-Zwischenspeicher entfernen.

        Ohne das sammelt BlueZ mit der Zeit hunderte Eintraege an, und alte
        Geraete tauchen ohne RSSI immer wieder in der Liste auf.

        ACHTUNG: bei einem GEKOPPELTEN Geraet loescht RemoveDevice die
        Kopplung. Der Aufrufer prueft das vorher (siehe aufraeumen()).
        """
        try:
            self.adapter.RemoveDevice(pfad)
            return True
        except Exception:  # noqa: BLE001
            return False
