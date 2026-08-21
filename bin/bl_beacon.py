#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner NG - Werbedaten dekodieren

BlueZ liefert in org.bluez.Device1 zwei Felder, die der Dienst bis 1.2.10
eingelesen und weggeworfen hat:

    ManufacturerData   {Hersteller-Kennung: Bytefolge}
    ServiceData        {Dienst-UUID: Bytefolge}

Beide stecken bereits im Ergebnis von GetManagedObjects(), kosten also
keinen zusaetzlichen Funkverkehr und keine Verbindung. Damit lassen sich
vier verbreitete Formate lesen:

    iBeacon      stabile Kennung (UUID/Major/Minor) und "measured power"
    Eddystone    UID, URL und TLM - TLM traegt Batteriespannung und Temperatur
    ATC / pvvx   Xiaomi-Thermometer mit freier Firmware: Temperatur, Feuchte,
                 Batterie
    RuuviTag     Temperatur, Feuchte, Luftdruck, Batteriespannung

BEWUSST NICHT dekodiert wird das originale Xiaomi-Format (ServiceData unter
0000fe95): es ist bei neueren Geraeten verschluesselt und braucht je Geraet
einen Schluessel aus der Hersteller-App. Der Ausweg ist die freie Firmware,
also der ATC-Fall.

BEWUSST KEINE Bibliothek: TheengsDecoder deckt hunderte Geraete ab, ist aber
kein Debian-Paket. Ein systemweites pip3 install scheitert auf Bookworm und
Trixie an PEP 668; das brauchte eine virtuelle Umgebung oder
--break-system-packages im Installationsskript und passt nicht zu einem
Plugin, das mit vier Paketen aus dem Grundsystem auskommt.

GRUNDSATZ DIESER DATEI: falsche Zahlen sind schlimmer als keine. Jede
Dekodierung prueft zuerst die Laenge und danach die Plausibilitaet. Passt
etwas nicht, wird NICHTS zurueckgegeben - kein geratener Wert.

Belegstand: iBeacon, Eddystone und RuuviTag sind gegen die
Formatbeschreibungen gebaut und mit eichung() gegen selbst erzeugte
Bytefolgen geprueft. Die beiden ATC-Anordnungen sind NICHT an einem echten
Geraet gemessen worden - deshalb die engen Plausibilitaetsgrenzen und
deshalb nennt die Oberflaeche sie als "zu pruefen".
"""

import struct

EDDYSTONE_UUID = "0000feaa-0000-1000-8000-00805f9b34fb"
ATC_UUID = "0000181a-0000-1000-8000-00805f9b34fb"
APPLE = 0x004C
RUUVI = 0x0499

# Plausibilitaetsgrenzen. Was ausserhalb liegt, wird verworfen.
GRENZEN = {
    "temperatur": (-45.0, 90.0),
    "feuchte": (0.0, 100.0),
    "druck": (500.0, 1200.0),
    "batterie": (0.0, 100.0),
    "batterie_mv": (500.0, 4500.0),
}


def _plausibel(name, wert):
    grenze = GRENZEN.get(name)
    if grenze is None or wert is None:
        return wert
    try:
        w = float(wert)
    except (TypeError, ValueError):
        return None
    if w != w:                      # NaN
        return None
    return wert if grenze[0] <= w <= grenze[1] else None


def _sammle(ziel, name, wert):
    wert = _plausibel(name, wert)
    if wert is not None:
        ziel[name] = wert


# ---------------------------------------------------------------------------
# iBeacon
# ---------------------------------------------------------------------------

def ibeacon(mdata):
    """iBeacon aus ManufacturerData[0x004C].

    Aufbau nach dem ersten Byte der Herstellerdaten:
        02 15 | UUID (16) | Major (2, big endian) | Minor (2) | TxPower (1, signed)

    Das letzte Byte ist "measured power": der RSSI, den ein Empfaenger in
    EINEM METER Abstand sieht. Das ist genau der Bezugswert, den die
    Entfernungsschaetzung braucht - anders als Device1.TxPower, das die
    abgestrahlte Leistung meint.
    """
    roh = (mdata or {}).get(APPLE)
    if not roh or len(roh) < 23:
        return None
    if roh[0] != 0x02 or roh[1] != 0x15:
        return None
    uuid = roh[2:18].hex().upper()
    major, minor = struct.unpack(">HH", roh[18:22])
    ref = struct.unpack(">b", roh[22:23])[0]
    if not (-120 <= ref <= 20):
        ref = None
    return {
        "art": "ibeacon",
        "kennung": "IB:{0}:{1}:{2}".format(uuid, major, minor),
        "uuid": uuid,
        "major": major,
        "minor": minor,
        "ref_1m": ref,
        "werte": {},
    }


# ---------------------------------------------------------------------------
# Eddystone
# ---------------------------------------------------------------------------

_URL_SCHEMA = ("http://www.", "https://www.", "http://", "https://")
_URL_ENDE = (".com/", ".org/", ".edu/", ".net/", ".info/", ".biz/", ".gov/",
             ".com", ".org", ".edu", ".net", ".info", ".biz", ".gov")


def eddystone(sdata):
    """Eddystone aus ServiceData[0000feaa-...].

    Erstes Byte ist der Rahmentyp:
        0x00 UID  - Namensraum und Instanz, dazu "ranging data" (RSSI auf 0 m)
        0x10 URL  - verkuerzte Adresse
        0x20 TLM  - Telemetrie: Batteriespannung in mV, Temperatur als
                    8.8-Festkomma, Paketzaehler, Betriebszeit in 0,1 s

    TLM ist der billige Batteriestand: er kommt ohne Verbindungsaufbau und
    stoert den Scan nicht.
    """
    roh = (sdata or {}).get(EDDYSTONE_UUID)
    if not roh or len(roh) < 2:
        return None
    typ = roh[0]
    out = {"art": "eddystone", "kennung": "", "ref_1m": None, "werte": {}}

    if typ == 0x00 and len(roh) >= 18:
        ref = struct.unpack(">b", roh[1:2])[0]
        namensraum = roh[2:12].hex().upper()
        instanz = roh[12:18].hex().upper()
        out["kennung"] = "EDS:{0}:{1}".format(namensraum, instanz)
        # "ranging data" ist der Pegel auf 0 m, nicht auf 1 m. Der Unterschied
        # betraegt bei 2,4 GHz rund 41 dB (Freiraumdaempfung auf einen Meter).
        # Das ist eine Faustformel und ersetzt keine Kalibrierung - deshalb
        # steht sie hier und nicht als "gemessener" Wert in der Oberflaeche.
        if -120 <= ref <= 20:
            out["ref_1m"] = ref - 41
        return out

    if typ == 0x10 and len(roh) >= 3:
        # 0x10 | Sendeleistung (1) | Schema (1) | verkuerzte Adresse
        schema = roh[2]
        text = _URL_SCHEMA[schema] if schema < len(_URL_SCHEMA) else ""
        for b in roh[3:]:
            if b < len(_URL_ENDE):
                text += _URL_ENDE[b]
            elif 0x20 <= b <= 0x7E:
                text += chr(b)
        out["werte"]["url"] = text
        return out

    if typ == 0x20 and len(roh) >= 14:
        # 0x20 | Version (1) | Batterie mV (2, BE) | Temp 8.8 (2, BE, signed)
        #      | Werbezaehler (4, BE) | Betriebszeit in 0,1 s (4, BE)
        mv, temp_roh, zaehler, laufzeit = struct.unpack(">HhII", roh[2:14])
        # 0x8000 heisst bei Eddystone-TLM ausdruecklich "nicht unterstuetzt".
        if temp_roh != -32768:
            _sammle(out["werte"], "temperatur", round(temp_roh / 256.0, 2))
        if mv:
            _sammle(out["werte"], "batterie_mv", mv)
        out["werte"]["pakete"] = zaehler
        out["werte"]["laufzeit_s"] = int(laufzeit / 10)
        return out

    return None


# ---------------------------------------------------------------------------
# ATC / pvvx (Xiaomi-Thermometer mit freier Firmware)
# ---------------------------------------------------------------------------

def atc(sdata):
    """ServiceData unter 0000181a (Environmental Sensing).

    Es gibt zwei verbreitete Anordnungen unter DERSELBEN UUID; unterschieden
    werden sie an der Laenge:

        13 Byte  "ATC1441"      MAC(6) Temp(2, BE, 0,1 GradC) Feuchte(1, %)
                                Batterie(1, %) Batterie(2, BE, mV) Zaehler(1)
        15 Byte  "pvvx custom"  MAC(6, umgekehrt) Temp(2, LE, 0,01 GradC)
                                Feuchte(2, LE, 0,01 %) Batterie(2, LE, mV)
                                Batterie(1, %) Zaehler(1) Merker(1)

    NICHT an einem Geraet gemessen. Die Plausibilitaetsgrenzen sind deshalb
    eng gesetzt: passt ein Wert nicht, wird er verworfen statt veroeffentlicht.
    """
    roh = (sdata or {}).get(ATC_UUID)
    if not roh:
        return None
    out = {"art": "atc", "kennung": "", "ref_1m": None, "werte": {}}

    if len(roh) == 13:
        temp = struct.unpack(">h", roh[6:8])[0] / 10.0
        feuchte = roh[8]
        batt = roh[9]
        mv = struct.unpack(">H", roh[10:12])[0]
        _sammle(out["werte"], "temperatur", round(temp, 1))
        _sammle(out["werte"], "feuchte", float(feuchte))
        _sammle(out["werte"], "batterie", float(batt))
        _sammle(out["werte"], "batterie_mv", mv)
        return out if out["werte"] else None

    if len(roh) == 15:
        temp = struct.unpack("<h", roh[6:8])[0] / 100.0
        feuchte = struct.unpack("<H", roh[8:10])[0] / 100.0
        mv = struct.unpack("<H", roh[10:12])[0]
        batt = roh[12]
        _sammle(out["werte"], "temperatur", round(temp, 2))
        _sammle(out["werte"], "feuchte", round(feuchte, 2))
        _sammle(out["werte"], "batterie", float(batt))
        _sammle(out["werte"], "batterie_mv", mv)
        return out if out["werte"] else None

    return None


# ---------------------------------------------------------------------------
# RuuviTag
# ---------------------------------------------------------------------------

def ruuvi(mdata):
    """RuuviTag, Rohformat 2 (ManufacturerData[0x0499], erstes Byte 0x05).

        0x05 | Temp (2, BE, 0,005 GradC) | Feuchte (2, BE, 0,0025 %)
             | Druck (2, BE, Pa - 50000) | Beschleunigung x,y,z (je 2, BE, mG)
             | Leistungsangabe (2, BE) | Bewegungszaehler (1) | Folge (2, BE)
             | MAC (6)

    In der Leistungsangabe stecken elf Bit Batteriespannung (Wert + 1600 mV)
    und fuenf Bit Sendeleistung. Alle Felder kennen einen ausdruecklichen
    Wert fuer "nicht verfuegbar"; der wird verworfen, nicht gerechnet.
    """
    roh = (mdata or {}).get(RUUVI)
    if not roh or len(roh) < 24 or roh[0] != 0x05:
        return None
    (temp, feuchte, druck, _ax, _ay, _az,
     leistung, bewegung, folge) = struct.unpack(">hHHhhhHBH", roh[1:18])
    out = {"art": "ruuvi", "kennung": "", "ref_1m": None, "werte": {}}
    if temp != -32768:
        _sammle(out["werte"], "temperatur", round(temp * 0.005, 2))
    if feuchte != 0xFFFF:
        _sammle(out["werte"], "feuchte", round(feuchte * 0.0025, 2))
    if druck != 0xFFFF:
        _sammle(out["werte"], "druck", round((druck + 50000) / 100.0, 2))
    if leistung != 0xFFFF:
        mv = (leistung >> 5) + 1600
        _sammle(out["werte"], "batterie_mv", mv)
    out["werte"]["bewegung"] = bewegung
    out["werte"]["folge"] = folge
    return out


# ---------------------------------------------------------------------------
# Zusammenfuehrung
# ---------------------------------------------------------------------------

def deuten(mdata, sdata):
    """Alle Dekoder der Reihe nach. Rueckgabe: dict oder None.

    Ergebnis:
        art      "ibeacon" | "eddystone" | "atc" | "ruuvi"
        kennung  stabile Kennung, wenn das Format eine traegt (sonst "")
        ref_1m   RSSI auf einem Meter, wenn das Format ihn traegt
        werte    {"temperatur": ..., "feuchte": ..., "batterie": ...}
    """
    for fn, arg in ((ibeacon, mdata), (ruuvi, mdata),
                    (eddystone, sdata), (atc, sdata)):
        try:
            erg = fn(arg)
        except (struct.error, IndexError, TypeError, ValueError):
            erg = None
        if erg:
            return erg
    return None


# Themennamen der Sensorwerte. Die Oberflaeche und die Loxone-Vorlage lesen
# diese Liste - so kann sie nicht von dem abweichen, was hier entsteht.
# Reihenfolge: Thema -> (Beschreibung, Einheit, MinVal, MaxVal)
SENSORTHEMEN = {
    "temperatur":  ("Temperatur", "°C", -45, 90),
    "feuchte":     ("Relative Luftfeuchte", "%", 0, 100),
    "druck":       ("Luftdruck", "hPa", 500, 1200),
    "batterie":    ("Batteriestand", "%", 0, 100),
    "batterie_mv": ("Batteriespannung", "mV", 500, 4500),
    "pakete":      ("Gesendete Werbepakete", "", 0, 4294967295),
    "laufzeit_s":  ("Betriebszeit", "s", 0, 4294967295),
    "bewegung":    ("Bewegungszähler", "", 0, 255),
    "folge":       ("Folgenummer", "", 0, 65535),
}


# ---------------------------------------------------------------------------
# Eichung
# ---------------------------------------------------------------------------

def eichung():
    """Selbstpruefung der Dekoder. Rueckgabe: (bestanden, gesamt, meldungen).

    Wichtig zum Verstaendnis: das misst die Dekoder gegen die BESCHRIEBENE
    Anordnung, nicht gegen ein echtes Geraet. Es faengt Zahlendreher,
    Vorzeichen- und Endianness-Fehler - nicht die Frage, ob die Beschreibung
    stimmt. Die letzte Zeile prueft ausserdem, dass eine ABSICHTLICH
    unsinnige Bytefolge NICHTS liefert; nimmt man die Plausibilitaetsgrenzen
    heraus, wird sie rot.
    """
    fehler = []
    gesamt = 0
    bestanden = 0

    def pruefe(text, ist, soll):
        nonlocal gesamt, bestanden
        gesamt += 1
        if ist == soll:
            bestanden += 1
        else:
            fehler.append("%s: ist %r, soll %r" % (text, ist, soll))

    # --- iBeacon
    roh = (b"\x02\x15" + bytes.fromhex("fda50693a4e24fb1afcfc6eb07647825")
           + struct.pack(">HH", 1, 2) + struct.pack(">b", -59))
    erg = ibeacon({APPLE: roh})
    pruefe("iBeacon Kennung", erg and erg["kennung"],
           "IB:FDA50693A4E24FB1AFCFC6EB07647825:1:2")
    pruefe("iBeacon ref_1m", erg and erg["ref_1m"], -59)
    pruefe("iBeacon falsches Praeambel", ibeacon({APPLE: b"\x02\x16" + roh[2:]}), None)
    pruefe("iBeacon zu kurz", ibeacon({APPLE: roh[:20]}), None)

    # --- Eddystone TLM: 3000 mV, 23,5 GradC
    tlm = b"\x20\x00" + struct.pack(">HhII", 3000, int(23.5 * 256), 7, 12345)
    erg = eddystone({EDDYSTONE_UUID: tlm})
    pruefe("Eddystone TLM Batterie", erg and erg["werte"].get("batterie_mv"), 3000)
    pruefe("Eddystone TLM Temperatur", erg and erg["werte"].get("temperatur"), 23.5)
    pruefe("Eddystone TLM Laufzeit", erg and erg["werte"].get("laufzeit_s"), 1234)
    # 0x8000 heisst "nicht unterstuetzt" und darf KEINE Temperatur ergeben.
    tlm2 = b"\x20\x00" + struct.pack(">HhII", 3000, -32768, 7, 10)
    erg2 = eddystone({EDDYSTONE_UUID: tlm2})
    pruefe("Eddystone TLM ohne Temperatur",
           erg2 and "temperatur" in erg2["werte"], False)

    # --- Eddystone UID
    uid = b"\x00" + struct.pack(">b", -20) + bytes(range(10)) + bytes(range(6))
    erg = eddystone({EDDYSTONE_UUID: uid})
    pruefe("Eddystone UID Kennung", erg and erg["kennung"].startswith("EDS:"), True)
    pruefe("Eddystone UID ref_1m", erg and erg["ref_1m"], -61)

    # --- ATC1441 (13 Byte): 21,5 GradC, 48 %, 87 %, 2900 mV
    a13 = (bytes.fromhex("A4C1380102FF") + struct.pack(">h", 215)
           + bytes([48, 87]) + struct.pack(">H", 2900) + bytes([9]))
    erg = atc({ATC_UUID: a13})
    pruefe("ATC1441 Laenge", len(a13), 13)
    pruefe("ATC1441 Temperatur", erg and erg["werte"].get("temperatur"), 21.5)
    pruefe("ATC1441 Feuchte", erg and erg["werte"].get("feuchte"), 48.0)
    pruefe("ATC1441 Batterie", erg and erg["werte"].get("batterie"), 87.0)

    # --- pvvx (15 Byte): -3,25 GradC, 55,5 %, 2812 mV, 62 %
    a15 = (bytes.fromhex("A4C1380102FF") + struct.pack("<h", -325)
           + struct.pack("<H", 5550) + struct.pack("<H", 2812)
           + bytes([62, 3, 0]))
    erg = atc({ATC_UUID: a15})
    pruefe("pvvx Laenge", len(a15), 15)
    pruefe("pvvx Temperatur", erg and erg["werte"].get("temperatur"), -3.25)
    pruefe("pvvx Feuchte", erg and erg["werte"].get("feuchte"), 55.5)
    pruefe("pvvx Batterie mV", erg and erg["werte"].get("batterie_mv"), 2812)

    # --- RuuviTag Rohformat 2
    rv = (b"\x05" + struct.pack(">hHH", 4000, 20000, 51325)
          + struct.pack(">hhh", 100, -200, 1000)
          + struct.pack(">H", ((3000 - 1600) << 5) | 22)
          + bytes([66]) + struct.pack(">H", 205) + bytes(6))
    erg = ruuvi({RUUVI: rv})
    pruefe("Ruuvi Temperatur", erg and erg["werte"].get("temperatur"), 20.0)
    pruefe("Ruuvi Feuchte", erg and erg["werte"].get("feuchte"), 50.0)
    pruefe("Ruuvi Druck", erg and erg["werte"].get("druck"), 1013.25)
    pruefe("Ruuvi Batterie mV", erg and erg["werte"].get("batterie_mv"), 3000)

    # --- Gegenprobe: unsinnige Werte muessen VERWORFEN werden.
    #     Nimmt man die Plausibilitaetsgrenzen heraus, wird diese Zeile rot.
    unsinn = (bytes.fromhex("A4C1380102FF") + struct.pack(">h", 9999)
              + bytes([200, 250]) + struct.pack(">H", 60000) + bytes([1]))
    erg = atc({ATC_UUID: unsinn})
    pruefe("Gegenprobe: unsinnige Werte verworfen",
           erg is None or erg["werte"] == {}, True)

    # --- Gegenprobe: leere Eingabe
    pruefe("Gegenprobe: nichts drin", deuten({}, {}), None)

    return bestanden, gesamt, fehler


if __name__ == "__main__":
    ok, alle, meldungen = eichung()
    print("Eichung der Beacon-Dekoder: %d von %d bestanden" % (ok, alle))
    for m in meldungen:
        print("  FEHLER " + m)
    raise SystemExit(0 if ok == alle else 1)
