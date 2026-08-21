#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner NG - Selbstpruefung der Python-Seite

Laeuft ohne Bluetooth-Adapter, ohne Miniserver und ohne Broker. Geprueft
wird gegen die ECHTEN Klassen des Dienstes, nicht gegen Nachbauten: die
Auswertung wird mit kuenstlich gealterten Sichtungen gefahren und der
MQTT-Versand mitgeschrieben.

Jede Pruefung ist so gebaut, dass sie ROT wird, wenn man die zugehoerige
Korrektur wieder herausnimmt. Wo das nicht geht, steht es dabei.

Aufruf:
    python3 bl_selbsttest.py           lesbar
    python3 bl_selbsttest.py --json    fuer die Oberflaeche
"""

import json
import os
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import bl_common as gem       # noqa: E402
import bl_beacon              # noqa: E402


class Pruefung:
    def __init__(self):
        self.zeilen = []

    def merke(self, gruppe, text, ok, anmerkung=""):
        self.zeilen.append({"gruppe": gruppe, "text": text,
                            "ok": bool(ok), "anmerkung": anmerkung})
        return ok

    def gleich(self, gruppe, text, ist, soll):
        return self.merke(gruppe, text, ist == soll,
                          "" if ist == soll else "ist %r, soll %r" % (ist, soll))

    def offen(self, gruppe, text, grund):
        """Nicht pruefbar. Ein Strich ist kein Haken - das wird als eigener
        Zustand gefuehrt und nicht als bestanden gezaehlt."""
        self.zeilen.append({"gruppe": gruppe, "text": text,
                            "ok": None, "anmerkung": grund})


def _dienst_bauen(cfgtext):
    """Einen echten Dienst mit einer Konfigurationsdatei aufsetzen.

    MQTT wird nicht gestartet; senden() wird mitgeschrieben.
    """
    import ble_scanner_ng as dienstmodul
    ordner = tempfile.mkdtemp(prefix="ble_selbsttest_")
    pfad = os.path.join(ordner, "ble_scanner_ng.cfg")
    with open(pfad, "w", encoding="utf-8") as fh:
        fh.write(cfgtext)
    gem.CONFIG_FILE = pfad
    gem.STATUS_FILE = os.path.join(ordner, "status.json")
    gem.STEUER_FILE = os.path.join(ordner, "steuer.json")
    gem.VERLAUF_FILE = os.path.join(ordner, "verlauf.csv")

    gesendet = {}

    def mitschreiben(self, unterthema, wert, retain=True):   # noqa: ARG001
        gesendet[unterthema] = str(wert)
        return True

    def loeschen(self, unterthema):                          # noqa: ARG001
        gesendet.pop(unterthema, None)
        gesendet["__geloescht__" + unterthema] = ""
        return True

    dienstmodul.Mqtt.senden = mitschreiben
    dienstmodul.Mqtt.loeschen = loeschen
    d = dienstmodul.Dienst()
    d.intervall = 5
    d.letzte_vollmeldung = 0.0
    d.letztes_aufraeumen = time.time()
    return d, gesendet, ordner


def _sichtung_setzen(d, mac, rssi, alter_s, name="Tag", **rest):
    """Eine Sichtung mit einem bestimmten Alter eintragen."""
    jetzt_m = time.monotonic()
    jetzt_w = time.time()
    eintrag = {
        "messungen": [(jetzt_m - alter_s, rssi)],
        "beacon": rest.get("beacon"),
        "kennung_ib": rest.get("kennung_ib", ""),
        "mac": mac,
        "mono": jetzt_m - alter_s,
        "zeit": jetzt_w - alter_s,
        "rssi": rssi,
        "rssi_avg": rest.get("rssi_avg", rssi),
        "name": name,
        "pfad": "/org/bluez/hci0/dev_" + mac.replace(":", "_"),
        "adresstyp": rest.get("adresstyp", "public"),
        "txpower": rest.get("txpower"),
        "paired": rest.get("paired", False),
        "trusted": False,
        "connected": False,
    }
    d.gesehen[mac] = eintrag
    return eintrag


def alles_pruefen():
    p = Pruefung()

    # =====================================================================
    G = "Beacon-Dekoder"
    ok, gesamt, meldungen = bl_beacon.eichung()
    p.merke(G, "Eichung der vier Formate: %d von %d" % (ok, gesamt),
            ok == gesamt, "; ".join(meldungen[:3]))

    # =====================================================================
    G = "Konfigurationsleser"
    faelle = [
        ("tag1=AA:BB:CC:DD:EE:FF", 1, "AA:BB:CC:DD:EE:FF", "", False),
        ("tag1=AA:BB:CC:DD:EE:FF|1|Schluessel | Justin", 1,
         "AA:BB:CC:DD:EE:FF", "Schluessel | Justin", False),
        ('TAG1="BLE_AA_BB_CC_DD_EE_FF:on:1^on~2^off:Anna"', 1,
         "AA:BB:CC:DD:EE:FF", "Anna", True),
        ("tag1=AA:BB:CC:DD:EE:FF|1|Name|abw=90,alias=anna", 1,
         "AA:BB:CC:DD:EE:FF", "Name", False),
        ("tag1=UNSINN", 0, "", "", False),
    ]
    ordner = tempfile.mkdtemp(prefix="ble_cfg_")
    for nr, (zeile, anzahl, kennung, name, alt_erwartet) in enumerate(faelle, 1):
        pfad = os.path.join(ordner, "f%d.cfg" % nr)
        with open(pfad, "w", encoding="utf-8") as fh:
            fh.write("[CONFIG]\n" + zeile + "\n")
        _w, tags, alt = gem.konfiguration_lesen(pfad)
        p.gleich(G, "Fall %d: Anzahl Tags aus %r" % (nr, zeile[:34]),
                 len(tags), anzahl)
        if anzahl:
            p.gleich(G, "Fall %d: Kennung" % nr, tags[0]["kennung"], kennung)
            p.gleich(G, "Fall %d: Bezeichnung" % nr, tags[0]["name"], name)
        p.gleich(G, "Fall %d: altes Format erkannt" % nr, alt, alt_erwartet)

    # Zusatzangaben je Tag: hin und zurueck
    opt = gem.tag_optionen_lesen("abw=90, alias=anna, unfug=1")
    p.gleich(G, "Zusatzangaben: unbekannter Schlüssel wird verworfen",
             "unfug" in opt, False)
    p.gleich(G, "Zusatzangaben: Rundlauf",
             gem.tag_optionen_lesen(gem.tag_optionen_schreiben(opt)), opt)
    p.gleich(G, "Zusatzangaben: Trennzeichen im Wert werden entfernt",
             gem.tag_optionen_schreiben({"alias": "a,b=c|d"}), "alias=abcd")

    # =====================================================================
    G = "Kennungen"
    p.gleich(G, "MAC normieren", gem.mac_normieren("ble_aa-bb-cc-dd-ee-ff"),
             "AA:BB:CC:DD:EE:FF")
    p.gleich(G, "zu kurze MAC wird abgewiesen", gem.mac_normieren("AA:BB:CC"), "")
    p.gleich(G, "iBeacon-Kennung normieren",
             gem.ibeacon_normieren("ib:fda50693a4e24fb1afcfc6eb07647825:1:2"),
             "IB:FDA50693A4E24FB1AFCFC6EB07647825:1:2")
    p.gleich(G, "Themenzweig aus MAC",
             gem.thema_der_kennung("mac", "AA:BB:CC:DD:EE:FF"), "AABBCCDDEEFF")
    p.gleich(G, "Alias gewinnt vor MAC",
             gem.thema_der_kennung("mac", "AA:BB:CC:DD:EE:FF", "Anna Schlüssel"),
             "Anna_Schluessel")

    # =====================================================================
    G = "Adresstyp"
    p.gleich(G, "öffentliche Adresse ist fest",
             gem.adresstyp_deuten("AA:BB:CC:DD:EE:FF", "public"), "fest")
    p.gleich(G, "oberste zwei Bit 11 = statisch zufällig",
             gem.adresstyp_deuten("C4:BB:CC:DD:EE:FF", "random"), "statisch")
    p.gleich(G, "oberste zwei Bit 01 = wechselnd (Telefon)",
             gem.adresstyp_deuten("4A:BB:CC:DD:EE:FF", "random"), "wechselnd")
    p.gleich(G, "oberste zwei Bit 00 = wechselnd",
             gem.adresstyp_deuten("0A:BB:CC:DD:EE:FF", "random"), "wechselnd")
    p.gleich(G, "ohne AddressType wird nicht geraten",
             gem.adresstyp_deuten("AA:BB:CC:DD:EE:FF", ""), "unbekannt")

    # =====================================================================
    G = "Signalstufe und Hysterese"
    p.gleich(G, "-60 dBm ist nah (Stufe 3)",
             gem.signalstufe(-60, -65, -85), 3)
    p.gleich(G, "-70 dBm ist mittel (Stufe 2)",
             gem.signalstufe(-70, -65, -85), 2)
    p.gleich(G, "ohne Hysterese wechselt die Stufe sofort",
             gem.signalstufe(-64, -65, -85, bisher=2, hysterese=0), 3)
    p.gleich(G, "mit 3 dB Hysterese bleibt sie bei -64 auf Stufe 2",
             gem.signalstufe(-64, -65, -85, bisher=2, hysterese=3), 2)
    p.gleich(G, "bei -61 springt sie auf Stufe 3",
             gem.signalstufe(-61, -65, -85, bisher=2, hysterese=3), 3)
    p.gleich(G, "abwärts erst unter -68",
             gem.signalstufe(-67, -65, -85, bisher=3, hysterese=3), 3)
    p.gleich(G, "bei -69 fällt sie auf Stufe 2",
             gem.signalstufe(-69, -65, -85, bisher=3, hysterese=3), 2)

    # =====================================================================
    G = "Glättung"
    reihe = [(0, -70), (1, -71), (2, -95), (3, -69), (4, -70)]
    wert = gem.geglaettet(reihe, 5)
    p.merke(G, "Ein Ausreißer (-95) verschiebt den Wert um höchstens 5 dB",
            wert is not None and abs(wert - (-70)) <= 5,
            "geglättet: %s" % wert)
    p.gleich(G, "leere Reihe ergibt keinen Wert", gem.geglaettet([], 5), None)

    # =====================================================================
    G = "Entfernungsschätzung"
    p.gleich(G, "RSSI gleich Bezugswert ergibt 1,0 m",
             gem.entfernung_schaetzen(-59, -59, 2.5), 1.0)
    p.merke(G, "schwächeres Signal ergibt mehr als 1 m",
            (gem.entfernung_schaetzen(-79, -59, 2.5) or 0) > 1.0)
    p.gleich(G, "ohne Bezugswert kein Ergebnis",
             gem.entfernung_schaetzen(-70, None, 2.5), None)

    # =====================================================================
    G = "Auswertung im Dienst"
    cfg = ("[CONFIG]\nabwesenheit_nach=30\nrssi_nah=-65\nrssi_mittel=-85\n"
           "ankunft_sichtungen=1\nglaettung=0\nereignisse=0\n"
           "tag1=AA:BB:CC:DD:EE:FF|1|Schlüssel Anna\n")
    d, gesendet, _o = _dienst_bauen(cfg)
    zweig = "AABBCCDDEEFF"

    ergebnisse = {}
    for alter in (0, 60, 599, 601, 900):
        gesendet.clear()
        d.letzter_stand.clear()
        _sichtung_setzen(d, "AA:BB:CC:DD:EE:FF", -70, alter)
        d.sichtungen_verfallen()
        d.auswerten(erzwingen=True)
        ergebnisse[alter] = {
            "present": gesendet.get(zweig + "/present"),
            "last_seen": gesendet.get(zweig + "/last_seen"),
            "ts": gesendet.get(zweig + "/last_seen_ts"),
        }

    p.gleich(G, "frisch gesehen: anwesend", ergebnisse[0]["present"], "1")
    p.gleich(G, "nach 60 s: abwesend", ergebnisse[60]["present"], "0")
    # Der Kern von Befund A2: bis 1.2.10 sprang last_seen hier auf -1,
    # weil die Sichtung eines KONFIGURIERTEN Tags verfiel.
    p.merke(G, "nach 601 s ist last_seen weiterhin eine Dauer, nicht −1",
            ergebnisse[601]["last_seen"] not in (None, "-1"),
            "gemessen: %s" % ergebnisse[601]["last_seen"])
    p.merke(G, "nach 900 s ebenso",
            ergebnisse[900]["last_seen"] not in (None, "-1"),
            "gemessen: %s" % ergebnisse[900]["last_seen"])
    p.merke(G, "der Zeitstempel bleibt gesetzt",
            ergebnisse[900]["ts"] not in (None, "0", ""),
            "gemessen: %s" % ergebnisse[900]["ts"])

    # -- Herzschlag
    p.merke(G, "server/ts wird in jedem Durchlauf gesendet",
            gesendet.get("server/ts") not in (None, ""))
    p.merke(G, "server/ok wird gesendet", gesendet.get("server/ok") is not None)

    # -- Zusammenfassung zaehlt dieselbe Menge
    gesendet.clear()
    d.letzter_stand.clear()
    d.tags.append({"art": "mac", "kennung": "11:22:33:44:55:66",
                   "mac": "11:22:33:44:55:66", "aktiv": "0",
                   "name": "abgehakt", "opt": {}})
    d.auswerten(erzwingen=True)
    p.gleich(G, "summary/tags zählt nur aktive Tags",
             gesendet.get("summary/tags"), "1")
    p.gleich(G, "summary/tags_gesamt zählt alle", gesendet.get("summary/tags_gesamt"), "2")
    d.tags.pop()

    # =====================================================================
    G = "Ankunfts-Entprellung"
    cfg2 = ("[CONFIG]\nabwesenheit_nach=30\nankunft_sichtungen=3\n"
            "glaettung=0\nereignisse=0\ntag1=AA:BB:CC:DD:EE:FF|1|Anna\n")
    d2, gesendet2, _o2 = _dienst_bauen(cfg2)
    _sichtung_setzen(d2, "AA:BB:CC:DD:EE:FF", -70, 0)
    folge = []
    for _ in range(4):
        gesendet2.clear()
        _sichtung_setzen(d2, "AA:BB:CC:DD:EE:FF", -70, 0)
        d2.auswerten(erzwingen=True)
        folge.append(gesendet2.get(zweig + "/present"))
    p.gleich(G, "erste Sichtung schaltet noch nicht ein", folge[0], "0")
    p.gleich(G, "zweite auch nicht", folge[1], "0")
    p.gleich(G, "die dritte schaltet ein", folge[2], "1")

    # Gegenprobe: mit ankunft_sichtungen=1 schaltet die erste sofort ein.
    # Nimmt man die Entprellung heraus, wird die Zeile darueber rot - nicht
    # diese hier. Beide zusammen zeigen, dass die Einstellung wirkt.
    cfg3 = ("[CONFIG]\nabwesenheit_nach=30\nankunft_sichtungen=1\n"
            "glaettung=0\nereignisse=0\ntag1=AA:BB:CC:DD:EE:FF|1|Anna\n")
    d3, gesendet3, _o3 = _dienst_bauen(cfg3)
    _sichtung_setzen(d3, "AA:BB:CC:DD:EE:FF", -70, 0)
    d3.auswerten(erzwingen=True)
    p.gleich(G, "Gegenprobe: ohne Entprellung schaltet die erste sofort ein",
             gesendet3.get(zweig + "/present"), "1")

    # -- Mindest-Signalstärke
    cfg4 = ("[CONFIG]\nrssi_minimum=-80\nglaettung=0\nereignisse=0\n"
            "tag1=AA:BB:CC:DD:EE:FF|1|Anna\n")
    d4, _g4, _o4 = _dienst_bauen(cfg4)
    p.gleich(G, "ein Paket mit −95 dBm zählt bei Mindestwert −80 nicht",
             d4.sichtung("AA:BB:CC:DD:EE:FF",
                         {"rssi": -95, "name": "x", "pfad": "/p"}), False)
    p.gleich(G, "ein Paket mit −70 dBm zählt",
             d4.sichtung("AA:BB:CC:DD:EE:FF",
                         {"rssi": -70, "name": "x", "pfad": "/p"}), True)

    # =====================================================================
    G = "Zurückbehaltene Themen"
    cfg5 = ("[CONFIG]\nglaettung=0\nereignisse=0\n"
            "tag1=AA:BB:CC:DD:EE:FF|1|Anna\ntag2=11:22:33:44:55:66|1|Berta\n")
    d5, gesendet5, ordner5 = _dienst_bauen(cfg5)
    _sichtung_setzen(d5, "AA:BB:CC:DD:EE:FF", -70, 0)
    _sichtung_setzen(d5, "11:22:33:44:55:66", -70, 0)
    d5.auswerten(erzwingen=True)
    vorher = sum(1 for k in gesendet5 if k.startswith("112233445566/"))
    p.merke(G, "der zweite Tag hat Themen", vorher > 0)
    # Tag entfernen und Konfiguration neu einlesen
    with open(gem.CONFIG_FILE, "w", encoding="utf-8") as fh:
        fh.write("[CONFIG]\nglaettung=0\nereignisse=0\ntag1=AA:BB:CC:DD:EE:FF|1|Anna\n")
    d5.konfiguration_neu_einlesen()
    geloescht = sum(1 for k in gesendet5 if k.startswith("__geloescht__112233445566/"))
    p.merke(G, "beim Entfernen werden seine Themen im Broker gelöscht",
            geloescht > 0, "%d Themen gelöscht" % geloescht)
    p.merke(G, "die Themen des verbliebenen Tags bleiben",
            any(k.startswith("AABBCCDDEEFF/") for k in gesendet5))

    # =====================================================================
    G = "Personen"
    cfg6 = ("[CONFIG]\nglaettung=0\nereignisse=0\nankunft_sichtungen=1\n"
            "tag1=AA:BB:CC:DD:EE:FF|1|Schlüsselbund|person=Anna\n"
            "tag2=11:22:33:44:55:66|1|Rucksack|person=Anna\n")
    d6, gesendet6, _o6 = _dienst_bauen(cfg6)
    _sichtung_setzen(d6, "11:22:33:44:55:66", -70, 0)     # nur der zweite ist da
    d6.auswerten(erzwingen=True)
    p.gleich(G, "eine Person gilt als anwesend, wenn EIN Tag da ist",
             gesendet6.get("person/Anna/present"), "1")
    gesendet6.clear()
    d6.letzter_stand.clear()
    d6.gesehen.clear()
    d6.auswerten(erzwingen=True)
    p.gleich(G, "und als abwesend, wenn keiner mehr da ist",
             gesendet6.get("person/Anna/present"), "0")

    # =====================================================================
    G = "HTTP-Weg"
    cfg7 = ("[CONFIG]\nhttp_push=1\nloxberry_id=haus\nglaettung=0\n"
            "ereignisse=0\nankunft_sichtungen=1\ntag1=AA:BB:CC:DD:EE:FF|1|Anna\n")
    d7, _g7, _o7 = _dienst_bauen(cfg7)
    d7.ms = [{"nr": "1", "name": "MS", "adresse": "127.0.0.1", "port": 80,
              "user": "", "pass": ""}]
    _sichtung_setzen(d7, "AA:BB:CC:DD:EE:FF", -70, 0)
    d7.auswerten(erzwingen=True)
    namen = {k[1] for k in d7.push_soll}
    p.merke("HTTP-Weg", "der Eingangsname der Originalfassung bleibt erhalten",
            "hausBLE_AA_BB_CC_DD_EE_FF" in namen, ", ".join(sorted(namen)))
    p.merke("HTTP-Weg", "der HTTP-Weg trägt dieselben Werte wie MQTT (5 Eingänge)",
            len(namen) == 5, "%d Eingänge" % len(namen))
    # Sperre: der Sollwert darf nicht verloren gehen
    d7.push_sperre["1"] = time.time() + 60
    _sichtung_setzen(d7, "AA:BB:CC:DD:EE:FF", -70, 900)
    d7.auswerten(erzwingen=True)
    offen = sum(1 for k, v in d7.push_soll.items() if d7.push_ist.get(k) != v)
    p.merke("HTTP-Weg", "während der Sperre bleibt der Wert gemerkt (nicht verworfen)",
            offen > 0, "%d offene Werte" % offen)

    # =====================================================================
    G = "Protokoll"
    ordner8 = tempfile.mkdtemp(prefix="ble_log_")
    logdatei = os.path.join(ordner8, "gross.log")
    with open(logdatei, "w", encoding="utf-8") as fh:
        for i in range(40000):
            fh.write("Zeile %d mit etwas Text, damit die Datei waechst\n" % i)
    vorher_gr = os.path.getsize(logdatei)
    gekappt = gem.log_kappen(logdatei, grenze_kb=100, behalten=200)
    nachher_gr = os.path.getsize(logdatei)
    p.merke(G, "Protokoll wird ab der Grenze gekappt", gekappt and nachher_gr < vorher_gr,
            "%d -> %d Bytes" % (vorher_gr, nachher_gr))
    with open(logdatei, "r", encoding="utf-8") as fh:
        rest = fh.read().splitlines()
    p.gleich(G, "es bleiben die letzten 200 Zeilen", len(rest), 200)
    p.merke(G, "die JÜNGSTE Zeile bleibt stehen", rest[-1].startswith("Zeile 39999"))
    p.gleich(G, "eine kleine Datei wird nicht angefasst",
             gem.log_kappen(logdatei, grenze_kb=5000), False)

    # =====================================================================
    G = "Nicht prüfbar ohne Gerät"
    p.offen(G, "BlueZ über D-Bus (Suche, RSSI, RemoveDevice)",
            "kein Bluetooth-Adapter und kein laufendes bluetoothd erreichbar")
    p.offen(G, "Batteriestand über GATT (org.bluez.Battery1)",
            "braucht ein verbindungsfähiges Gerät")
    p.offen(G, "Wie dicht PropertiesChanged feuert",
            "am Gerät mit dbus-monitor zu messen; davon hängt das Fenster der "
            "Glättung ab")
    p.offen(G, "ATC/pvvx-Anordnung an einem echten Sensor",
            "die Dekoder sind gegen die Formatbeschreibung geprüft, nicht gegen "
            "ein Gerät")

    return p.zeilen


def main():
    # Auf einer Konsole ohne UTF-8 (Windows) wuerde ein Minuszeichen die
    # ganze Ausgabe abbrechen. Auf dem LoxBerry ist das folgenlos.
    for strom in (sys.stdout, sys.stderr):
        try:
            strom.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass
    zeilen = alles_pruefen()
    ok = sum(1 for z in zeilen if z["ok"] is True)
    rot = sum(1 for z in zeilen if z["ok"] is False)
    offen = sum(1 for z in zeilen if z["ok"] is None)
    if "--json" in sys.argv:
        print(json.dumps({"zeilen": zeilen, "ok": ok, "fehler": rot,
                          "offen": offen, "version": gem.VERSION},
                         ensure_ascii=False))
        return 1 if rot else 0

    gruppe = ""
    for z in zeilen:
        if z["gruppe"] != gruppe:
            gruppe = z["gruppe"]
            print("\n== " + gruppe)
        zeichen = "[ok]" if z["ok"] is True else ("[--]" if z["ok"] is None else "[XX]")
        print("  %s %s%s" % (zeichen, z["text"],
                             ("   (" + z["anmerkung"] + ")") if z["anmerkung"] else ""))
    print("\n%d bestanden, %d fehlgeschlagen, %d nicht prüfbar" % (ok, rot, offen))
    return 1 if rot else 0


if __name__ == "__main__":
    sys.exit(main())
