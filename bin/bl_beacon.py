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
MIBEACON_UUID = "0000fe95-0000-1000-8000-00805f9b34fb"
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
# MiBeacon (Xiaomi / Mijia), ServiceData 0000fe95
# ---------------------------------------------------------------------------

# Die Geraetetypen, die hier benannt werden koennen. Die Liste dient nur der
# Anzeige - ein unbekannter Typ wird trotzdem gelesen, solange die Saetze
# stimmen. Sie zu erweitern kostet nichts und aendert nichts am Verhalten.
MI_TYPEN = {
    0x01AA: "LYWSDCGQ/01ZM",      # der runde Mi-Fuehler, am 13.09.2026 gemessen
    0x045B: "LYWSD02",
    0x055B: "LYWSD03MMC",
    0x0387: "MHO-C303",
    0x02DF: "JQJCY01YM",
    0x0098: "HHCCJCY01",          # Flower Care
    0x03BC: "GCLS002",
    0x0576: "CGD1",
    0x066F: "CGDK2",
}

# Rahmenbits. DIESE ZUORDNUNG WAR MEIN FEHLER und ist die Stelle, an der ein
# Nachbau am leichtesten scheitert: ich hatte 0x0040 fuer "verschluesselt"
# gehalten. Gefangen hat es die Eichung unten - das gemessene Paket waere als
# unlesbar gemeldet worden, obwohl es sauber dekodiert.
MI_VERSCHLUESSELT = 0x0008
MI_MAC_DABEI = 0x0010
MI_CAPABILITY = 0x0020
MI_WERTE_DABEI = 0x0040


def mibeacon(sdata, absender=""):
    r"""ServiceData unter 0000fe95 (Xiaomi / Mijia), unverschluesselte Fassung.

    AM GERAET GEMESSEN am 13.09.2026 an einem LYWSDCGQ/01ZM (MJ_HT_V1), und
    zwar an zwei verschiedenen Satzarten - das ist der Unterschied zu atc()
    und ruuvi(), die nur gegen die Beschreibung geprueft sind.

    EINE EINSCHRAENKUNG, damit das Beispiel nicht mehr behauptet, als es ist:
    die ADRESSE im Beispiel unten und in der Eichung ist durch eine
    Platzhalter-MAC ersetzt (AA:BB:CC:DD:EE:FF). Die Adresse eines Geraets in
    einem bewohnten Haus gehoert nicht in ein veroeffentlichtes Archiv - das
    Freigabetor des Hauses hat sie zu Recht beanstandet. Alle uebrigen Byte,
    und damit die Werte 26,6 GradC und 48,2 %, sind die gemessenen.

        50 20  AA01  83  FF EE DD CC BB AA  0D10 04 0A01 E201
        \___/  \__/  \_/ \______________/   \__/ \_/ \_______/
        Rahmen Typ  Zaehler  MAC rueckwaerts Art Laenge Werte
        -> 26,6 GradC / 48,2 %

    Aufbau:
      * Rahmen  2 Byte, klein zuerst. Bits siehe MI_* oben.
      * Typ     2 Byte, Geraetetyp (MI_TYPEN).
      * Zaehler 1 Byte, steigt je Werbung - wird als "folge" veroeffentlicht.
      * MAC     6 Byte RUECKWAERTS, nur wenn MI_MAC_DABEI gesetzt ist.
      * dann beliebig viele Saetze: Art 2 Byte, Laenge 1 Byte, Werte.

    DIE ABSENDERPRUEFUNG IST DER WICHTIGE TEIL. Das Paket traegt die MAC des
    Geraets, das den Wert GEMESSEN hat - und ein Nachbarpaket kann dieselbe
    UUID tragen. Wer die beiden nicht gegeneinander haelt, schreibt fremde
    Temperaturen in den eigenen Tag. Stimmen sie nicht, wird NICHTS geliefert.

    Verschluesselte Pakete (MI_VERSCHLUESSELT, bei neueren Geraeten und nach
    dem Binden in der Mi-Home-App der Normalfall) brauchen den Bindungs-
    schluessel. Den gibt es hier nicht, also wird kein Wert erfunden - es wird
    aber auch nicht geschwiegen: das Ergebnis traegt "hinweis" und leere
    Werte, damit die Oberflaeche den Grund nennen kann.
    """
    roh = (sdata or {}).get(MIBEACON_UUID)
    if not roh or len(roh) < 5:
        return None

    rahmen, typ = struct.unpack("<HH", roh[0:4])
    zaehler = roh[4]
    out = {"art": "mibeacon", "kennung": "", "ref_1m": None, "werte": {},
           "geraet": MI_TYPEN.get(typ, "0x%04X" % typ)}

    i = 5
    if rahmen & MI_MAC_DABEI:
        if len(roh) < i + 6:
            return None
        mac = ":".join("%02X" % b for b in roh[i:i + 6][::-1])
        i += 6
        if absender and mac != str(absender).upper():
            return None
    if rahmen & MI_CAPABILITY:
        i += 1

    if rahmen & MI_VERSCHLUESSELT:
        out["hinweis"] = "verschluesselt"
        return out

    if not rahmen & MI_WERTE_DABEI:
        return None

    _sammle(out["werte"], "folge", zaehler)
    while i + 3 <= len(roh):
        art, laenge = struct.unpack("<HB", roh[i:i + 3])
        i += 3
        daten = roh[i:i + laenge]
        i += laenge
        if len(daten) < laenge:
            break
        # Nur die Saetze, fuer die es ein Thema gibt (SENSORTHEMEN). Ein
        # Bodenfeuchte- oder Leitwertsatz eines Blumenfuehlers wird bewusst
        # uebergangen statt unter einem erfundenen Namen veroeffentlicht.
        if art == 0x1004 and laenge == 2:
            _sammle(out["werte"], "temperatur",
                    round(struct.unpack("<h", daten)[0] / 10.0, 1))
        elif art == 0x1006 and laenge == 2:
            _sammle(out["werte"], "feuchte",
                    round(struct.unpack("<H", daten)[0] / 10.0, 1))
        elif art == 0x100A and laenge == 1:
            _sammle(out["werte"], "batterie", float(daten[0]))
        elif art == 0x100D and laenge == 4:
            grad, feucht = struct.unpack("<hH", daten)
            _sammle(out["werte"], "temperatur", round(grad / 10.0, 1))
            _sammle(out["werte"], "feuchte", round(feucht / 10.0, 1))

    # "folge" allein ist kein Messwert - dann ist nichts Brauchbares dabei.
    if set(out["werte"]) <= {"folge"}:
        return None
    return out


# ---------------------------------------------------------------------------
# Zusammenfuehrung
# ---------------------------------------------------------------------------

def deuten(mdata, sdata, absender=""):
    """Alle Dekoder der Reihe nach. Rueckgabe: dict oder None.

    Ergebnis:
        art      "ibeacon" | "eddystone" | "atc" | "ruuvi" | "mibeacon"
        kennung  stabile Kennung, wenn das Format eine traegt (sonst "")
        ref_1m   RSSI auf einem Meter, wenn das Format ihn traegt
        werte    {"temperatur": ..., "feuchte": ..., "batterie": ...}
        hinweis  nur wenn ein Format etwas zu SAGEN hat, ohne Werte liefern
                 zu koennen (MiBeacon verschluesselt)

    "absender" ist die MAC, von der das Paket KAM. MiBeacon traegt die MAC des
    messenden Geraets im Paket; beide muessen uebereinstimmen, sonst werden
    fremde Werte einem Tag zugeschrieben. Wer den Absender nicht kennt, laesst
    das Feld leer - dann entfaellt die Pruefung, und das steht hier, damit es
    eine Entscheidung ist und kein Versehen.
    """
    for fn, arg in ((ibeacon, mdata), (ruuvi, mdata),
                    (eddystone, sdata), (atc, sdata)):
        try:
            erg = fn(arg)
        except (struct.error, IndexError, TypeError, ValueError):
            erg = None
        if erg:
            return erg
    try:
        erg = mibeacon(sdata, absender)
    except (struct.error, IndexError, TypeError, ValueError):
        erg = None
    return erg or None


def beschriftung(gedeutet):
    """Was in der Oberflaeche unter dem Namen steht - EINE Quelle fuer drei
    Aufrufstellen (Dienst, Abbild, Geraetesuche).

    Ohne das stuende bei einem verschluesselten Xiaomi-Paket nur "mibeacon" da
    und daneben keine Werte: der Anwender sucht dann den Fehler bei sich. Mit
    dem Hinweis steht der Grund daneben. Das ist dieselbe Linie wie bei der
    Adapterlage - nicht schweigen, sondern sagen, warum nichts kommt.
    """
    if not gedeutet:
        return ""
    art = str(gedeutet.get("art", "") or "")
    hinweis = str(gedeutet.get("hinweis", "") or "")
    geraet = str(gedeutet.get("geraet", "") or "")
    if geraet and geraet not in art:
        art = (art + " " + geraet).strip()
    return (art + " (" + hinweis + ")") if hinweis and art else (art or hinweis)


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

    # --- MiBeacon, AM GERAET GEMESSEN am 13.09.2026 (LYWSDCGQ/01ZM)
    #     Satzart 0x100D: Temperatur und Feuchte zusammen.
    #     Die MAC ist ein Platzhalter (siehe mibeacon()); die Werte sind echt.
    mi = bytes.fromhex("5020AA0183FFEEDDCCBBAA0D10040A01E201")
    erg = mibeacon({MIBEACON_UUID: mi}, "AA:BB:CC:DD:EE:FF")
    pruefe("MiBeacon Geraet", erg and erg["geraet"], "LYWSDCGQ/01ZM")
    pruefe("MiBeacon Temperatur", erg and erg["werte"].get("temperatur"), 26.6)
    pruefe("MiBeacon Feuchte", erg and erg["werte"].get("feuchte"), 48.2)
    pruefe("MiBeacon Folge", erg and erg["werte"].get("folge"), 0x83)
    #     Zweite gemessene Werbung desselben Geraets, Satzart 0x1004 - nur
    #     Temperatur. Beide Arten kommen vor; ein Dekoder, der nur 0x100D
    #     kennt, schweigt bei der Haelfte der Pakete.
    mi2 = bytes.fromhex("5020AA016BFFEEDDCCBBAA041002 0901".replace(" ", ""))
    erg = mibeacon({MIBEACON_UUID: mi2}, "AA:BB:CC:DD:EE:FF")
    pruefe("MiBeacon zweite Satzart", erg and erg["werte"].get("temperatur"), 26.5)
    pruefe("MiBeacon zweite Satzart ohne Feuchte",
           erg and "feuchte" in erg["werte"], False)
    #     EICHUNG der Absenderpruefung: dasselbe Paket, fremder Absender.
    #     Nimmt man die Pruefung heraus, wird diese Zeile rot - und fremde
    #     Temperaturen landen im eigenen Tag.
    pruefe("MiBeacon fremder Absender verworfen",
           mibeacon({MIBEACON_UUID: mi}, "11:22:33:44:55:66"), None)
    #     EICHUNG der Bitmaske: 0x08 gesetzt heisst verschluesselt. Haelt man
    #     wie ich zuerst 0x40 dafuer, wird das GEMESSENE Paket oben als
    #     unlesbar gemeldet - dann sind die drei Zeilen davor rot.
    miv = bytes.fromhex("5820AA0183FFEEDDCCBBAA9999")
    erg = mibeacon({MIBEACON_UUID: miv}, "AA:BB:CC:DD:EE:FF")
    pruefe("MiBeacon verschluesselt erkannt",
           erg and erg.get("hinweis"), "verschluesselt")
    pruefe("MiBeacon verschluesselt ohne erfundene Werte",
           erg and erg["werte"], {})
    #     Ein Paket ohne Wertebit ist kein Messpaket.
    pruefe("MiBeacon ohne Wertebit",
           mibeacon({MIBEACON_UUID: bytes.fromhex("1020AA0183FFEEDDCCBBAA")},
                    "AA:BB:CC:DD:EE:FF"), None)
    #     Ueber den Verteiler, mit Absender.
    erg = deuten({}, {MIBEACON_UUID: mi}, "AA:BB:CC:DD:EE:FF")
    pruefe("MiBeacon ueber deuten()", erg and erg["art"], "mibeacon")

    # --- Beschriftung: der Grund muss in der Anzeige ankommen.
    pruefe("Beschriftung mit Geraet",
           beschriftung({"art": "mibeacon", "geraet": "LYWSDCGQ/01ZM"}),
           "mibeacon LYWSDCGQ/01ZM")
    pruefe("Beschriftung mit Hinweis",
           beschriftung({"art": "mibeacon", "geraet": "LYWSD03MMC",
                         "hinweis": "verschluesselt"}),
           "mibeacon LYWSD03MMC (verschluesselt)")
    pruefe("Beschriftung ohne alles", beschriftung(None), "")

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
