#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner NG - Dienst

Sucht dauerhaft nach Bluetooth-Low-Energy-Geraeten und meldet je
konfiguriertem Tag, ob es in Reichweite ist. Zustaende gehen per MQTT
retained an den Broker; auf Wunsch zusaetzlich als virtueller Eingang
per HTTP an den Miniserver, wie es die Originalfassung getan hat.

Grundlage ist das Plugin von Christian Woerstenfeld. Der Dienst wurde fuer
LoxBerry 4 neu geschrieben; die Aenderungen stehen in NOTICE.

Zwei Betriebsarten (Einstellung "betriebsart"):

  signal   BlueZ meldet jede Aenderung ueber PropertiesChanged; der Dienst
           bekommt damit JEDES Werbepaket statt einer Stichprobe je Runde.
           Braucht python3-gi - das steht seit jeher in dpkg/apt und wurde
           bis 1.2.10 nie benutzt. Eine Sicherungsabfrage laeuft trotzdem
           alle 30 Sekunden mit, damit ein verpasstes Signal nichts kostet.

  abfrage  der Weg bis 1.2.10: alle `intervall` Sekunden einmal
           GetManagedObjects(). Bleibt als Rueckfallebene bestehen.
"""

import errno
import json
import os
import re
import signal
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import bl_common as gem      # noqa: E402
import bl_beacon             # noqa: E402

import logging               # noqa: E402


# ---------------------------------------------------------------------------
# Protokoll
# ---------------------------------------------------------------------------
#
# Nur EIN Schreiber auf die Datei. Bis 1.2.10 leitete daemon/daemon stdout
# nach derselben Datei um, in die Python zusaetzlich einen FileHandler
# schrieb - jede Zeile stand doppelt drin, aus zwei Puffern verschraenkt.
# Jetzt schreibt Python auf stdout, und WER stdout auffaengt, bestimmt der
# Aufrufer. Nur wenn stdout ins Leere zeigt (kein Startskript), wird selbst
# eine Datei geoeffnet.

LOG_DATEI = os.path.join(gem.LOG_DIR, "ble_scanner_ng.log")


def _log_einrichten():
    handlers = [logging.StreamHandler(sys.stdout)]
    if os.environ.get("BLE_LOGDATEI") == "1":
        try:
            os.makedirs(gem.LOG_DIR, exist_ok=True)
            handlers.append(logging.FileHandler(LOG_DATEI))
        except OSError:
            pass
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)-7s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
        handlers=handlers,
    )


_log_einrichten()
log = logging.getLogger("ble_scanner_ng")


# ---------------------------------------------------------------------------
# MQTT
# ---------------------------------------------------------------------------

def mqtt_zugangsdaten():
    """Zugangsdaten des MQTT-Gateways aus general.json lesen.
    Gross- und Kleinschreibung ist dort uneinheitlich - beide Varianten."""
    pfad = os.path.join(gem.HOME_DIR, "config", "system", "general.json")
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError) as fehler:
        log.warning("general.json nicht lesbar (%s) - MQTT nicht möglich", fehler)
        return None
    for abschnitt in ("Mqtt", "mqtt"):
        block = daten.get(abschnitt)
        if not isinstance(block, dict):
            continue

        def hole(*namen):
            for n in namen:
                if block.get(n):
                    return block[n]
            return None

        host = hole("Brokerhost", "brokerhost")
        if not host:
            continue
        # Ein unsinniger Port darf den ganzen Dienst nicht beenden - bis
        # 1.2.10 warf int() hier ein ValueError, und die Hauptschleife hatte
        # kein try darum.
        try:
            port = int(hole("Brokerport", "brokerport") or 1883)
        except (TypeError, ValueError):
            log.warning("Brokerport in general.json ist keine Zahl - es gilt 1883")
            port = 1883
        return {"host": str(host), "port": port,
                "user": hole("Brokeruser", "brokeruser"),
                "pass": hole("Brokerpass", "brokerpass")}
    log.warning("Kein MQTT-Broker in general.json gefunden")
    return None


class Mqtt:
    """Duenne Huelle um paho-mqtt. Faellt still aus, wenn Bibliothek oder
    Gateway fehlen - der HTTP-Weg funktioniert dann weiter.

    paho verbindet nach einem Abbruch von SELBST wieder; loop_start() laesst
    loop_forever() in einem Thread laufen, und der hat seine eigene
    Wiederverbindungsschleife.

    Zwei Dinge, die bis 1.1.0 falsch waren und behoben bleiben:

    1. Der ERSTE Verbindungsversuch. connect_async() plus loop_start() in
       JEDEM Fall - sonst verschwindet jede spaetere Nachricht spurlos.
    2. Nach einem Neustart des Brokers sind die retained-Werte weg. Der
       on_connect-Behandler verlangt deshalb eine vollstaendige Neumeldung.

    Neu in 1.3.0: die Neumeldungsmarke ist ein threading.Event. Bis 1.2.10
    war es ein bool, das der paho-Netzfaden setzte und der Hauptfaden las
    und danach auf False setzte - faellt eine Neuverbindung genau
    dazwischen, ging sie verloren, und die vollstaendige Neumeldung
    unterblieb.
    """

    def __init__(self, praefix):
        self.praefix = praefix
        self.client = None
        self.verbunden = False
        self.neumeldung = threading.Event()
        self.verluste = 0
        self.gesendet = 0
        self.letzter_erfolg = 0
        self.abo_rueckruf = None

    def start(self):
        try:
            import paho.mqtt.client as mqtt
        except ImportError:
            log.error("paho-mqtt fehlt - MQTT bleibt aus. "
                      "Paket python3-paho-mqtt nachinstallieren.")
            return False
        zugang = mqtt_zugangsdaten()
        if not zugang:
            return False
        try:
            self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1)
        except (AttributeError, TypeError):
            self.client = mqtt.Client()      # paho-mqtt 1.x
        if zugang["user"]:
            self.client.username_pw_set(zugang["user"], zugang["pass"] or "")
        self.client.will_set(self.praefix + "/server/online", "0", retain=True)

        def bei_verbindung(_c, _u, _f, rc, *_a):
            if rc == 0:
                self.verbunden = True
                self.neumeldung.set()
                log.info("MQTT verbunden mit %s:%s, Themenpräfix %s",
                         zugang["host"], zugang["port"], self.praefix)
                self.senden("server/online", "1")
                if self.abo_rueckruf:
                    try:
                        self.abo_rueckruf(self.client)
                    except Exception as fehler:       # noqa: BLE001
                        log.warning("MQTT-Abo fehlgeschlagen: %s", fehler)
            else:
                # rc 4 und 5 sind falsche Zugangsdaten - die behebt kein
                # Warten, deshalb wird der Grund benannt.
                self.verbunden = False
                log.error("MQTT-Anmeldung abgelehnt (Code %s). Bei 4 oder 5 stimmen "
                          "Benutzer oder Kennwort des Brokers nicht.", rc)

        def bei_trennung(_c, _u, rc, *_a):
            self.verbunden = False
            if rc != 0:
                log.warning("MQTT-Verbindung abgerissen (Code %s) - paho verbindet "
                            "selbst wieder.", rc)

        self.client.on_connect = bei_verbindung
        self.client.on_disconnect = bei_trennung
        try:
            self.client.reconnect_delay_set(min_delay=1, max_delay=30)
        except Exception:  # noqa: BLE001
            pass
        try:
            self.client.connect_async(zugang["host"], zugang["port"], keepalive=60)
        except AttributeError:
            try:
                self.client.connect(zugang["host"], zugang["port"], keepalive=60)
            except OSError as fehler:
                log.warning("MQTT-Broker %s:%s noch nicht erreichbar (%s) - "
                            "es wird weiter versucht.",
                            zugang["host"], zugang["port"], fehler)
        self.client.loop_start()
        log.info("MQTT-Schleife gestartet, Ziel %s:%s", zugang["host"], zugang["port"])
        return True

    def senden(self, unterthema, wert, retain=True):
        if not self.client:
            return False
        try:
            erg = self.client.publish(self.praefix + "/" + unterthema,
                                      str(wert), qos=0, retain=retain)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veröffentlichung fehlgeschlagen: %s", fehler)
            return False
        rc = getattr(erg, "rc", 0)
        if rc != 0:
            self.verluste += 1
            if self.verluste in (1, 10, 100) or self.verluste % 1000 == 0:
                log.warning("MQTT: %d Nachricht(en) nicht abgesetzt (letzter Code %s). "
                            "Laeuft das MQTT-Gateway?", self.verluste, rc)
            return False
        self.gesendet += 1
        self.letzter_erfolg = int(time.time())
        return True

    def loeschen(self, unterthema):
        """Ein retained Thema aus dem Broker entfernen.

        MQTT loescht ein zurueckbehaltenes Thema durch eine Nachricht mit
        LEERER Nutzlast und retain=True. Ohne das bleibt der letzte Wert
        eines entfernten Tags fuer immer stehen - und in Loxone ist
        "present=1" von einer echten Anwesenheit nicht zu unterscheiden.
        """
        if not self.client:
            return False
        try:
            self.client.publish(self.praefix + "/" + unterthema, "", qos=0, retain=True)
            return True
        except Exception:  # noqa: BLE001
            return False

    def stop(self):
        if not self.client:
            return
        try:
            self.senden("server/online", "0")
            self.senden("server/ok", "0")
            # Kurz warten, damit die letzte Nachricht das Haus verlaesst -
            # ohne das steht im Broker retained weiter '1'.
            time.sleep(0.3)
            self.client.loop_stop()
            self.client.disconnect()
        except Exception:  # noqa: BLE001
            pass


# ---------------------------------------------------------------------------
# HTTP-Weg an den Miniserver
# ---------------------------------------------------------------------------

def miniserver_liste():
    """Miniserver aus general.json."""
    pfad = os.path.join(gem.HOME_DIR, "config", "system", "general.json")
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return []
    out = []
    for nr, ms in (daten.get("Miniserver") or {}).items():
        if not isinstance(ms, dict):
            continue
        adresse = ms.get("Ipaddress") or ms.get("IPAddress") or ""
        if not adresse:
            continue
        try:
            port = int(ms.get("Port") or 80)
        except (TypeError, ValueError):
            port = 80
        out.append({
            "nr": str(nr),
            "name": ms.get("Name") or ("Miniserver " + str(nr)),
            "adresse": adresse,
            "port": port,
            "user": ms.get("Admin") or ms.get("Username") or "",
            "pass": ms.get("Pass") or ms.get("Password") or "",
        })
    return out


def http_push(ms, name, wert, zeitgrenze=4):
    """Virtuellen Eingang am Miniserver setzen.

    Die Zugangsdaten stehen nicht in der URL, sondern im
    Authorization-Kopf - in der URL landen sie sonst in jedem Proxy- und
    Serverprotokoll.

    Die Meldung sagt, WER geantwortet hat: eine abgewiesene Verbindung
    (Dienst laeuft nicht) bedeutet etwas anderes als eine Zeitgrenze
    (nichts antwortet) oder "kein Weg dorthin".
    """
    url = "http://{0}:{1}/dev/sps/io/{2}/{3}".format(
        ms["adresse"], ms["port"], urllib.parse.quote(str(name)),
        urllib.parse.quote(str(wert)))
    anfrage = urllib.request.Request(url)
    anfrage.add_header("User-Agent", "LoxBerry-BLE-Scanner-NG/" + gem.VERSION)
    anfrage.add_header("Accept", "*/*")
    if ms["user"]:
        import base64
        roh = "{0}:{1}".format(ms["user"], ms["pass"]).encode("utf-8")
        anfrage.add_header("Authorization",
                           "Basic " + base64.b64encode(roh).decode("ascii"))
    try:
        with urllib.request.urlopen(anfrage, timeout=zeitgrenze):
            return True, ""
    except urllib.error.HTTPError as fehler:
        if fehler.code in (401, 403):
            return False, ("HTTP {0} - der Miniserver hat geantwortet und die "
                           "Anmeldung abgelehnt. Benutzer und Kennwort stehen in "
                           "den LoxBerry-Systemeinstellungen.".format(fehler.code))
        if fehler.code == 404:
            return False, ("HTTP 404 - der Miniserver kennt den virtuellen Eingang "
                           "nicht. Ist die Vorlage eingelesen?")
        return False, "HTTP {0}".format(fehler.code)
    except urllib.error.URLError as fehler:
        grund = getattr(fehler, "reason", fehler)
        nr = getattr(grund, "errno", None)
        if nr == errno.ECONNREFUSED:
            return False, ("Verbindung abgewiesen - der Miniserver ist erreichbar, "
                           "nimmt auf diesem Port aber nichts an.")
        if nr == errno.EHOSTUNREACH or nr == errno.ENETUNREACH:
            return False, "Kein Weg zum Miniserver (Netz oder Route fehlt)."
        if isinstance(grund, OSError) and "timed out" in str(grund).lower():
            return False, "Zeitgrenze - es hat niemand geantwortet."
        return False, str(grund)
    except Exception as fehler:  # noqa: BLE001
        return False, str(fehler)


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------

class Dienst:

    def __init__(self):
        self.cfg, self.tags, alt = gem.konfiguration_lesen()
        if alt:
            log.info("Konfiguration im alten Format erkannt - wird übernommen "
                     "und beim nächsten Speichern neu geschrieben")
        self.praefix = self.cfg.get("themenpraefix") or "blescanner"
        self.scanner = (self.cfg.get("scanner_name") or "").strip() or gem.rechnername()
        self.mqtt = Mqtt(self.praefix)
        self.bluez = None
        self.laeuft = True
        self.startzeit = time.time()
        self.startzeit_mono = time.monotonic()

        # MAC -> Sichtung. "mono" ist die monotone Uhr (fuer Altersrechnung),
        # "zeit" die Wanduhr (fuer den veroeffentlichten Zeitstempel).
        self.gesehen = {}
        self.sperre = threading.RLock()   # gesehen wird auch aus dem D-Bus-Faden gefuellt
        self.tagzustand = {}              # Kennung -> {anwesend, stufe, seit_mono, ...}
        self.letzter_stand = {}           # Thema -> Wert
        self.veroeffentlicht = set()      # alle je gesendeten Themen (zum Loeschen)
        self.config_mtime = self._mtime()
        self.ms = miniserver_liste()

        # HTTP: Sollwert je (Miniserver, Eingangsname). Es gibt keine
        # Warteschlange mehr - ein Auftrag kann deshalb nicht mehr verloren
        # gehen. Bis 1.2.10 wurde er waehrend der 60-Sekunden-Sperre mit
        # "continue" aus der Schlange geworfen und NIE wiederholt.
        self.push_soll = {}
        self.push_ist = {}
        self.push_sperre = {}
        self.push_fehler = 0
        self.push_faden = None

        self.letzte_sichtung_mono = None
        self.letzte_sichtung_zeit = 0
        self.adapter_ok = True
        self.wachhund_stufe = 0
        self.letzte_wiederbelebung = 0.0
        self.suchfilter = 0
        self.batterie_zuletzt = ""        # Datum des letzten Batterielaufs
        self.batterie_unmoeglich = set()  # Kennungen, die keine Verbindung annehmen
        self.raumdaten = {}               # Kennung -> {Scanner -> (zeit, rssi)}
        self.testmodus = {}               # Kennung -> Ablaufzeitpunkt
        self.testwerte = []
        self.kalibrierung = None          # {"kennung":..., "bis":..., "werte":[]}
        self.ereignisse_gesamt = 0

    # -- Hilfen -------------------------------------------------------------

    def _mtime(self):
        try:
            return os.path.getmtime(gem.CONFIG_FILE)
        except OSError:
            return 0

    def _zahl(self, schluessel, vorgabe):
        try:
            return int(float(self.cfg.get(schluessel, vorgabe)))
        except (TypeError, ValueError):
            return int(vorgabe)

    def _komma(self, schluessel, vorgabe):
        try:
            return float(str(self.cfg.get(schluessel, vorgabe)).replace(",", "."))
        except (TypeError, ValueError):
            return float(vorgabe)

    def _tagzahl(self, tag, optname, cfgname, vorgabe):
        """Wert je Tag, sonst der globale. Leer heisst: globaler gilt."""
        roh = (tag.get("opt") or {}).get(optname, "")
        if str(roh).strip() != "":
            try:
                return int(float(roh))
            except (TypeError, ValueError):
                pass
        return self._zahl(cfgname, vorgabe)

    def _zustand(self, kennung):
        """Zustand eines Tags - immer mit allen Schluesseln.

        Ein setdefault(kennung, {}) an einer zweiten Stelle wuerde einen
        unvollstaendigen Eintrag anlegen, und der erste Zugriff auf einen
        fehlenden Schluessel waere ein KeyError mitten im Dienst.
        """
        return self.tagzustand.setdefault(
            kennung, {"anwesend": 0, "stufe": 0, "seit_mono": None,
                      "seit_zeit": 0, "ankunft": 0, "batterie": None,
                      "batterie_zeit": 0, "raum": "", "raum_seit": 0})

    def _zweig(self, tag):
        return gem.thema_der_kennung(tag["art"], tag["kennung"],
                                     (tag.get("opt") or {}).get("alias", ""))

    # -- Sichtungen ---------------------------------------------------------

    def sichtung(self, mac, werte):
        """Eine Messung eintragen. Laeuft in beiden Betriebsarten durch hier.

        Rueckgabe: True, wenn die Messung angenommen wurde.
        """
        rssi = werte.get("rssi")
        if rssi is None:
            return False
        mindest = self._zahl("rssi_minimum", -100)
        if rssi < mindest:
            # Zu schwach, um als Sichtung zu zaehlen. Das ist die Gegenwehr
            # gegen einen Anhaenger, der auf der Strasse vorbeigetragen wird.
            return False

        jetzt_m = time.monotonic()
        jetzt_w = time.time()
        fenster = max(1, self._zahl("glaettung_fenster", 5))
        with self.sperre:
            eintrag = self.gesehen.get(mac)
            if eintrag is None:
                eintrag = {"messungen": [], "beacon": None, "kennung_ib": "",
                           "mac": mac}
                self.gesehen[mac] = eintrag
            eintrag["mono"] = jetzt_m
            eintrag["zeit"] = jetzt_w
            eintrag["rssi"] = rssi
            eintrag["pfad"] = werte.get("pfad", eintrag.get("pfad", ""))
            eintrag["adresstyp"] = werte.get("adresstyp", eintrag.get("adresstyp", ""))
            eintrag["txpower"] = werte.get("txpower", eintrag.get("txpower"))
            for merker in ("paired", "trusted", "connected"):
                if merker in werte:
                    eintrag[merker] = werte[merker]
            # Der Name wird nur ERGAENZT, nie geleert: BlueZ meldet oft zuerst
            # nur die Adresse und den Namen erst mit dem naechsten Paket.
            if werte.get("name"):
                eintrag["name"] = werte["name"]
            eintrag.setdefault("name", "")
            eintrag["messungen"].append((jetzt_m, rssi))
            # Alte Messungen verfallen - ein Puffer, der eine Minute alte
            # Werte mittelt, haengt der Wirklichkeit hinterher.
            grenze = jetzt_m - max(10, self._zahl("abwesenheit_nach", 30))
            eintrag["messungen"] = [(z, r) for z, r in eintrag["messungen"]
                                    if z >= grenze][-4 * fenster:]
            if self._zahl("glaettung", 1) == 1:
                eintrag["rssi_avg"] = gem.geglaettet(eintrag["messungen"], fenster)
            else:
                eintrag["rssi_avg"] = rssi

            if self._zahl("beacon", 1) == 1 and (werte.get("mdata") or werte.get("sdata")):
                gedeutet = bl_beacon.deuten(werte.get("mdata"), werte.get("sdata"))
                if gedeutet:
                    eintrag["beacon"] = gedeutet
                    if gedeutet.get("kennung", "").startswith("IB:"):
                        eintrag["kennung_ib"] = gedeutet["kennung"]

        self.letzte_sichtung_mono = jetzt_m
        self.letzte_sichtung_zeit = jetzt_w
        if not self.adapter_ok:
            log.info("Es kommen wieder Advertisements an - Adapter in Ordnung.")
            self.adapter_ok = True
            self.wachhund_stufe = 0

        # Testmodus: jede Einzelmessung mitschreiben, damit sich die
        # Schwellen kalibrieren lassen, ohne dass man raten muss.
        if self.testmodus or self.kalibrierung:
            self._testmessung(mac, rssi, jetzt_w)
        return True

    def _testmessung(self, mac, rssi, jetzt_w):
        with self.sperre:
            if self.kalibrierung and time.time() < self.kalibrierung["bis"]:
                if self.kalibrierung.get("mac") == mac:
                    self.kalibrierung["werte"].append(rssi)
            for kennung, bis in list(self.testmodus.items()):
                if time.time() > bis:
                    del self.testmodus[kennung]
                    continue
                if self._mac_der_kennung(kennung) == mac:
                    self.testwerte.append({"zeit": int(jetzt_w), "rssi": rssi})
                    self.testwerte = self.testwerte[-400:]

    def _mac_der_kennung(self, kennung):
        if not kennung.startswith("IB:"):
            return kennung
        with self.sperre:
            for mac, e in self.gesehen.items():
                if e.get("kennung_ib") == kennung:
                    return mac
        return ""

    def eintrag_zum_tag(self, tag):
        """Die Sichtung, die zu diesem Tag gehoert.

        Bei einem MAC-Tag ist das die Sichtung unter dieser MAC. Bei einem
        iBeacon-Tag wird ueber den Inhalt des Advertisements gesucht - genau
        das ist der Ausweg aus der wechselnden Adresse: die MAC darf sich
        aendern, das Tripel nicht.
        """
        with self.sperre:
            if tag["art"] == "mac":
                return self.gesehen.get(tag["kennung"])
            neuester = None
            for e in self.gesehen.values():
                if e.get("kennung_ib") != tag["kennung"]:
                    continue
                if neuester is None or e.get("mono", 0) > neuester.get("mono", 0):
                    neuester = e
            return neuester

    # -- Melden -------------------------------------------------------------

    def _senden(self, thema, wert, erzwingen=False):
        wert = "" if wert is None else str(wert)
        geaendert = self.letzter_stand.get(thema) != wert
        if not erzwingen and not geaendert:
            return False
        self.letzter_stand[thema] = wert
        self.veroeffentlicht.add(thema)
        self.mqtt.senden(thema, wert)
        return geaendert

    def themen_loeschen(self, zweige):
        """Alle Themen unter diesen Zweigen aus dem Broker entfernen."""
        def trifft(thema):
            # Genau auf Zweiggrenzen vergleichen. Ein blosses "in" wuerde bei
            # einem Alias namens "present" auch summary/present treffen.
            for z in zweige:
                if thema.startswith(z + "/") or ("/" + z + "/") in thema:
                    return True
            return False

        entfernt = 0
        for thema in sorted(self.veroeffentlicht):
            if trifft(thema):
                self.mqtt.loeschen(thema)
                self.letzter_stand.pop(thema, None)
                entfernt += 1
        self.veroeffentlicht = {t for t in self.veroeffentlicht if not trifft(t)}
        if entfernt:
            log.info("%d zurückbehaltene Themen entfernter Tags gelöscht (%s)",
                     entfernt, ", ".join(sorted(zweige)))
        return entfernt

    def auswerten(self, erzwingen=False):
        """Aus den zuletzt gesehenen Geraeten den Zustand je Tag bilden."""
        jetzt_m = time.monotonic()
        jetzt_w = time.time()
        nah = self._zahl("rssi_nah", -65)
        mittel = self._zahl("rssi_mittel", -85)
        hyst = self._zahl("hysterese_db", 3)
        entfernung_an = self._zahl("entfernung", 0) == 1
        daempfung = self._komma("daempfung", 2.5)
        scannerthemen = self._zahl("scanner_themen", 0) == 1
        raum_an = self._zahl("raum", 0) == 1
        ausgleich = self._zahl("raum_ausgleich_db", 0)

        anwesend_gesamt = 0
        namen_da = []
        uebersicht = []
        personen = {}

        for tag in self.tags:
            kennung = tag["kennung"]
            zweig = self._zweig(tag)
            eintrag = self.eintrag_zum_tag(tag)
            zustand = self._zustand(kennung)

            grenze = self._tagzahl(tag, "abw", "abwesenheit_nach", 30)
            noetig = max(1, self._zahl("ankunft_sichtungen", 1))

            alter = None
            if eintrag and eintrag.get("mono") is not None:
                alter = jetzt_m - eintrag["mono"]

            frisch = alter is not None and alter <= grenze
            if frisch:
                zustand["ankunft"] = min(zustand["ankunft"] + 1, noetig)
            else:
                zustand["ankunft"] = 0

            # Eine Ankunft muss sich bestaetigen, ein Weggang nicht: beim
            # Gehen gibt es ohnehin schon die Wartezeit `abwesenheit_nach`.
            # Ohne diese Bedingung genuegte EIN Paket beliebiger Staerke, um
            # die Anwesenheit einzuschalten.
            if zustand["anwesend"]:
                anwesend = 1 if frisch else 0
            else:
                anwesend = 1 if (frisch and zustand["ankunft"] >= noetig) else 0

            if anwesend != zustand["anwesend"]:
                zustand["anwesend"] = anwesend
                zustand["seit_mono"] = jetzt_m
                zustand["seit_zeit"] = jetzt_w
                self.verlauf_schreiben(jetzt_w, tag, zweig,
                                       "kommt" if anwesend else "geht",
                                       eintrag.get("rssi") if eintrag else None)
            if zustand["seit_mono"] is None:
                zustand["seit_mono"] = jetzt_m
                zustand["seit_zeit"] = jetzt_w

            roh = eintrag.get("rssi") if (eintrag and anwesend) else None
            avg = eintrag.get("rssi_avg") if (eintrag and anwesend) else None
            if avg is None:
                avg = roh
            stufe = gem.signalstufe(avg, nah, mittel,
                                    zustand["stufe"] or None, hyst) if anwesend else 0
            zustand["stufe"] = stufe

            if tag.get("aktiv", "1") != "1":
                uebersicht.append(self._uebersicht_zeile(
                    tag, zweig, eintrag, anwesend, roh, avg, stufe, alter, zustand))
                continue

            if anwesend:
                anwesend_gesamt += 1
                namen_da.append(tag.get("name") or kennung)

            geaendert = self._senden("{0}/present".format(zweig), anwesend, erzwingen)
            self._senden("{0}/rssi".format(zweig),
                         roh if roh is not None else -255, erzwingen)
            self._senden("{0}/rssi_avg".format(zweig),
                         avg if avg is not None else -255, erzwingen)
            self._senden("{0}/level".format(zweig), stufe, erzwingen)
            self._senden("{0}/last_seen".format(zweig),
                         int(alter) if alter is not None else -1, erzwingen)
            # Der Zeitstempel ist der massgebliche Wert: MQTT ist ein
            # Push-Weg, das Alter ist beim Senden immer null. Und er springt
            # nicht nach zehn Minuten auf -1, wie es last_seen bis 1.2.10 tat,
            # weil die Sichtung dann aus dem Zwischenspeicher fiel.
            self._senden("{0}/last_seen_ts".format(zweig),
                         int(eintrag["zeit"]) if (eintrag and eintrag.get("zeit")) else 0,
                         erzwingen)
            self._senden("{0}/present_since".format(zweig),
                         int(zustand["seit_zeit"]), erzwingen)
            self._senden("{0}/name".format(zweig),
                         tag.get("name") or (eintrag or {}).get("name", ""), erzwingen)

            if entfernung_an:
                ref = self._referenz(tag, eintrag)
                d = gem.entfernung_schaetzen(avg, ref, daempfung) if (anwesend and ref is not None) else None
                self._senden("{0}/distance".format(zweig),
                             d if d is not None else -1, erzwingen)

            if zustand["batterie"] is not None:
                self._senden("{0}/battery".format(zweig), zustand["batterie"], erzwingen)
                self._senden("{0}/battery_ts".format(zweig),
                             int(zustand["batterie_zeit"]), erzwingen)

            if eintrag and eintrag.get("beacon"):
                for name, wert in (eintrag["beacon"].get("werte") or {}).items():
                    if name in bl_beacon.SENSORTHEMEN:
                        self._senden("{0}/sensor/{1}".format(zweig, name), wert, erzwingen)

            if scannerthemen:
                self._senden("scanner/{0}/{1}/rssi".format(self.scanner, zweig),
                             (avg + ausgleich) if avg is not None else -255, erzwingen)
                self._senden("scanner/{0}/{1}/present".format(self.scanner, zweig),
                             anwesend, erzwingen)

            if raum_an:
                raum = self.raum_bestimmen(kennung, avg, ausgleich, jetzt_w, zustand)
                self._senden("{0}/raum".format(zweig), raum, erzwingen)
                self._senden("{0}/raum_seit".format(zweig),
                             int(zustand["raum_seit"]), erzwingen)

            person = (tag.get("opt") or {}).get("person", "").strip()
            if person:
                p = gem.thema_saeubern(person)
                eintragp = personen.setdefault(p, {"present": 0, "ts": 0, "namen": []})
                eintragp["present"] = max(eintragp["present"], anwesend)
                if eintrag and eintrag.get("zeit"):
                    eintragp["ts"] = max(eintragp["ts"], int(eintrag["zeit"]))
                eintragp["namen"].append(tag.get("name") or kennung)

            if geaendert and self.cfg.get("http_push", "0") == "1":
                self.an_miniserver(tag, zweig, anwesend, roh, stufe, alter, eintrag)

            uebersicht.append(self._uebersicht_zeile(
                tag, zweig, eintrag, anwesend, roh, avg, stufe, alter, zustand))

        # -- Personen
        for name, p in personen.items():
            self._senden("person/{0}/present".format(name), p["present"], erzwingen)
            self._senden("person/{0}/last_seen_ts".format(name), p["ts"], erzwingen)

        # -- Zusammenfassung
        aktive = sum(1 for t in self.tags if t.get("aktiv") == "1")
        self._senden("summary/present", anwesend_gesamt, erzwingen)
        # Bis 1.2.10 zaehlte "tags" ALLE konfigurierten, "present" aber nur
        # die aktiven - "2 von 7" war damit falsch, sobald ein Tag abgehakt
        # war. Jetzt zaehlen beide dieselbe Menge; die Gesamtzahl steht
        # zusaetzlich unter summary/tags_gesamt.
        self._senden("summary/tags", aktive, erzwingen)
        self._senden("summary/tags_gesamt", len(self.tags), erzwingen)
        self._senden("summary/names", ", ".join(namen_da), erzwingen)

        # -- Herzschlag. Ohne ihn ist ein toter Dienst nicht von einem ruhigen
        #    Haus zu unterscheiden: es kommt schlicht nichts mehr, und die
        #    letzten Werte stehen retained weiter im Broker.
        self._senden("server/ts", int(jetzt_w), erzwingen=True)
        self._senden("server/ok", 1 if self.adapter_ok else 0, erzwingen)
        self._senden("server/adapter_ok", 1 if self.adapter_ok else 0, erzwingen)
        self._senden("server/letzte_sichtung", int(self.letzte_sichtung_zeit), erzwingen)
        self._senden("server/version", gem.VERSION, erzwingen)
        self._senden("server/scanner", self.scanner, erzwingen)

        self.zustand_schreiben(uebersicht, anwesend_gesamt, aktive, personen)

    def _uebersicht_zeile(self, tag, zweig, eintrag, anwesend, roh, avg, stufe, alter, zustand):
        typ = gem.adresstyp_deuten(eintrag.get("mac", ""),
                                   eintrag.get("adresstyp", "")) if eintrag else "unbekannt"
        return {
            "art": tag["art"],
            "kennung": tag["kennung"],
            "mac": tag.get("mac", ""),
            "zweig": zweig,
            "name": tag.get("name", ""),
            "aktiv": tag.get("aktiv", "1"),
            "anwesend": anwesend,
            "rssi": roh,
            "rssi_avg": avg,
            "stufe": stufe,
            "seit": int(alter) if alter is not None else None,
            "zuletzt": int(eintrag["zeit"]) if (eintrag and eintrag.get("zeit")) else 0,
            "seit_zeit": int(zustand["seit_zeit"]),
            "adresstyp": typ,
            "batterie": zustand["batterie"],
            "raum": zustand["raum"],
            "opt": tag.get("opt", {}),
            # "beacon" ist vorhanden, aber None, solange nichts dekodiert
            # wurde - .get("beacon", {}) liefert dann None, nicht {}.
            "sensor": ((eintrag or {}).get("beacon") or {}).get("werte", {}),
            "beaconart": ((eintrag or {}).get("beacon") or {}).get("art", ""),
        }

    def _referenz(self, tag, eintrag):
        """Bezugspegel auf einem Meter fuer die Entfernungsschaetzung.

        Reihenfolge: kalibrierter Wert je Tag, dann "measured power" aus
        einem iBeacon (das IST der Pegel auf einem Meter), dann eine
        Faustformel aus Device1.TxPower.
        """
        roh = (tag.get("opt") or {}).get("ref", "")
        if str(roh).strip() != "":
            try:
                return int(float(roh))
            except (TypeError, ValueError):
                pass
        if eintrag and eintrag.get("beacon") and eintrag["beacon"].get("ref_1m") is not None:
            return eintrag["beacon"]["ref_1m"]
        if eintrag and eintrag.get("txpower") is not None:
            # TxPower ist die abgestrahlte Leistung (Pegel auf 0 m), NICHT
            # der Pegel auf einem Meter. Der Unterschied betraegt bei
            # 2,4 GHz rund 41 dB. Faustformel, keine Kalibrierung.
            return int(eintrag["txpower"]) - 41
        return None

    # -- Raumzuordnung ------------------------------------------------------

    def raum_bestimmen(self, kennung, eigener_rssi, ausgleich, jetzt_w, zustand):
        """Aus den Meldungen aller Scanner den staerksten waehlen.

        Zwei Dinge, an denen solche Aufbauten in der Praxis scheitern, und
        die deshalb beide hier stehen:

        * Eine HYSTERESE auf der Zuordnung. Ohne sie springt der Raum
          zwischen zwei fast gleich starken Scannern hin und her.
        * Ein AUSGLEICH je Scanner. Ein USB-Adapter mit externer Antenne
          hoert systematisch staerker als ein Pi Zero; ohne Ausgleich
          gewinnt immer derselbe.
        """
        eigene = self.raumdaten.setdefault(kennung, {})
        if eigener_rssi is not None:
            eigene[self.scanner] = (jetzt_w, eigener_rssi + ausgleich)
        hoechstalter = max(30, self._zahl("abwesenheit_nach", 30))
        for name in [n for n, (z, _r) in eigene.items() if jetzt_w - z > hoechstalter]:
            del eigene[name]
        if not eigene:
            if zustand["raum"] != "":
                zustand["raum"] = ""
                zustand["raum_seit"] = jetzt_w
            return ""
        bester, (_z, bester_wert) = max(eigene.items(), key=lambda p: p[1][1])
        jetziger = zustand.get("raum", "")
        if jetziger and jetziger in eigene:
            hyst = self._zahl("raum_hysterese_db", 5)
            if bester != jetziger and bester_wert < eigene[jetziger][1] + hyst:
                return jetziger
        if bester != jetziger:
            zustand["raum"] = bester
            zustand["raum_seit"] = jetzt_w
        return bester

    def raum_abonnieren(self, client):
        client.subscribe(self.praefix + "/scanner/+/+/rssi", qos=0)
        client.on_message = self.raum_nachricht
        log.info("Raumzuordnung: abonniert %s/scanner/+/+/rssi", self.praefix)

    def raum_nachricht(self, _c, _u, nachricht):
        try:
            teile = nachricht.topic.split("/")
            # <praefix>/scanner/<name>/<zweig>/rssi
            if len(teile) < 5 or teile[-1] != "rssi":
                return
            scanner = teile[-3]
            zweig = teile[-2]
            if scanner == self.scanner:
                return
            wert = int(float(nachricht.payload.decode("utf-8", "replace")))
        except (ValueError, UnicodeDecodeError, IndexError):
            return
        if wert <= -255:
            return
        kennung = self._kennung_zum_zweig(zweig)
        if not kennung:
            return
        self.raumdaten.setdefault(kennung, {})[scanner] = (time.time(), wert)

    def _kennung_zum_zweig(self, zweig):
        for tag in self.tags:
            if self._zweig(tag) == zweig:
                return tag["kennung"]
        return ""

    # -- HTTP ---------------------------------------------------------------

    def _eingangsname(self, tag, zweig):
        """Name des virtuellen Eingangs am Miniserver.

        Ohne Alias bleibt es bei der Schreibweise der Originalfassung
        (<Kennung>BLE_AA_BB_CC_DD_EE_FF) - bestehende Loxone-Konfigurationen
        laufen damit weiter. Mit Alias tritt der Alias an die Stelle der MAC.
        """
        kennung = (self.cfg.get("loxberry_id") or "").strip()
        alias = (tag.get("opt") or {}).get("alias", "").strip()
        if alias or tag["art"] != "mac":
            return "{0}BLE_{1}".format(kennung, gem.thema_saeubern(alias) or zweig)
        return "{0}BLE_{1}".format(kennung, tag["kennung"].replace(":", "_"))

    def an_miniserver(self, tag, zweig, anwesend, rssi, stufe, alter, eintrag):
        """Virtuelle Eingaenge setzen - der Weg der Originalfassung.

        Der MQTT-Weg traegt dieselben Werte wie dieser: bis 1.2.10 kam ueber
        HTTP nur `present` an, ueber MQTT fuenf Werte. Ein Plugin, dessen
        einer Weg weniger enthaelt als der andere, macht die Umstellung
        unmoeglich - und zwar unauffaellig, denn es kommen ja Werte an.
        """
        basis = self._eingangsname(tag, zweig)
        zeitstempel = int(eintrag["zeit"]) if (eintrag and eintrag.get("zeit")) else 0
        werte = {
            basis: anwesend,
            basis + "_RSSI": rssi if rssi is not None else -255,
            basis + "_LEVEL": stufe,
            basis + "_LASTSEEN": int(alter) if alter is not None else -1,
            basis + "_TS": zeitstempel,
        }
        for ms in self.ms:
            for name, wert in werte.items():
                self.push_soll[(ms["nr"], name)] = wert

    def push_arbeiter(self):
        """Hintergrundfaden: gleicht Soll und Ist am Miniserver ab.

        Es gibt keine Warteschlange mehr. Der Faden vergleicht Soll und Ist;
        ein Wert, der waehrend einer Sperre anfaellt, bleibt im Soll stehen
        und wird gesendet, sobald die Sperre ablaeuft. Bis 1.2.10 wurde er
        mit "continue" aus der Schlange geworfen und nie wiederholt: kam
        jemand in diesen 60 Sekunden nach Hause, erfuhr der Miniserver es
        NIE - erst der uebernaechste Wechsel kam wieder an.
        """
        while self.laeuft:
            time.sleep(1.0)
            if self.cfg.get("http_push", "0") != "1" or not self.ms:
                continue
            for ms in list(self.ms):
                if time.time() < self.push_sperre.get(ms["nr"], 0):
                    continue
                offen = [(k, v) for k, v in list(self.push_soll.items())
                         if k[0] == ms["nr"] and self.push_ist.get(k) != v]
                for schluessel, wert in offen[:20]:
                    if not self.laeuft:
                        return
                    ok, fehler = http_push(ms, schluessel[1], wert)
                    if ok:
                        self.push_ist[schluessel] = wert
                        self.push_sperre.pop(ms["nr"], None)
                        log.info("An %s gesendet: %s = %s",
                                 ms["name"], schluessel[1], wert)
                    else:
                        self.push_fehler += 1
                        self.push_sperre[ms["nr"]] = time.time() + 60
                        log.warning("An %s fehlgeschlagen: %s = %s (%s). Der Wert "
                                    "bleibt gemerkt und wird in 60 s erneut "
                                    "versucht.", ms["name"], schluessel[1], wert, fehler)
                        break

    # -- Verlauf ------------------------------------------------------------

    def verlauf_schreiben(self, jetzt_w, tag, zweig, ereignis, rssi):
        """Kommen und Gehen mitschreiben.

        Die Datei liegt unter <datadir>, NICHT auf der Ramdisk: sie soll
        einen Neustart ueberdauern. Ohne sie kann niemand beantworten, ob
        die eingestellte Zeit "Abwesend nach" richtig ist - und genau das
        ist die Frage, die jeder nach einer Woche hat.
        """
        if self._zahl("ereignisse", 1) != 1:
            return
        zeile = "{0};{1};{2};{3};{4}\n".format(
            int(jetzt_w), zweig,
            re.sub(r"[;\r\n]", " ", tag.get("name", "")),
            ereignis, rssi if rssi is not None else "")
        try:
            os.makedirs(os.path.dirname(gem.VERLAUF_FILE), exist_ok=True)
            neu = not os.path.exists(gem.VERLAUF_FILE)
            with open(gem.VERLAUF_FILE, "a", encoding="utf-8") as fh:
                if neu:
                    fh.write("zeit;zweig;name;ereignis;rssi\n")
                fh.write(zeile)
            if neu:
                try:
                    os.chmod(gem.VERLAUF_FILE, 0o640)
                except OSError:
                    pass
            self.ereignisse_gesamt += 1
        except OSError as fehler:
            log.warning("Verlauf nicht schreibbar: %s", fehler)
        log.info("%s: %s (%s dBm)", tag.get("name") or tag["kennung"], ereignis,
                 rssi if rssi is not None else "-")

    def verlauf_kappen(self):
        """Den Verlauf auf die eingestellte Zahl Tage kuerzen."""
        tage = max(1, self._zahl("ereignisse_tage", 7))
        grenze = time.time() - tage * 86400
        try:
            if not os.path.isfile(gem.VERLAUF_FILE):
                return
            with open(gem.VERLAUF_FILE, "r", encoding="utf-8", errors="replace") as fh:
                zeilen = fh.read().splitlines()
        except OSError:
            return
        kopf = zeilen[0] if zeilen and zeilen[0].startswith("zeit;") else "zeit;zweig;name;ereignis;rssi"
        rest = []
        for z in zeilen:
            if z.startswith("zeit;") or not z.strip():
                continue
            try:
                if int(z.split(";", 1)[0]) >= grenze:
                    rest.append(z)
            except (ValueError, IndexError):
                continue
        rest = rest[-20000:]
        if len(rest) == max(0, len(zeilen) - 1):
            return
        try:
            temp = gem.VERLAUF_FILE + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                fh.write(kopf + "\n")
                fh.write("\n".join(rest) + ("\n" if rest else ""))
            os.replace(temp, gem.VERLAUF_FILE)
        except OSError:
            pass

    # -- Zustandsdatei ------------------------------------------------------

    def zustand_schreiben(self, uebersicht, anwesend_gesamt, aktive, personen):
        """Zustandsdatei fuer die Oberflaeche.

        Rechte 0640: darin stehen die Namen der ueberwachten Personen und
        ihre Sichtbarkeit. Bis 1.2.10 stand hier 0644, waehrend
        preupgrade.sh dieselben Daten ausdruecklich mit 0600 sicherte -
        entweder ist die Begruendung dort falsch oder waren diese Rechte es.
        """
        with self.sperre:
            sichtbar = []
            for mac, w in sorted(self.gesehen.items(),
                                 key=lambda p: -((p[1].get("rssi_avg") if p[1].get("rssi_avg") is not None
                                                  else p[1].get("rssi")) or -255)):
                sichtbar.append({
                    "mac": mac,
                    "rssi": w.get("rssi"),
                    "rssi_avg": w.get("rssi_avg"),
                    "name": w.get("name", ""),
                    "seit": int(time.monotonic() - w.get("mono", time.monotonic())),
                    "zuletzt": int(w.get("zeit", 0)),
                    "adresstyp": gem.adresstyp_deuten(mac, w.get("adresstyp", "")),
                    "messungen": len(w.get("messungen", [])),
                    "beaconart": (w.get("beacon") or {}).get("art", ""),
                    "beaconkennung": (w.get("beacon") or {}).get("kennung", ""),
                    "sensor": (w.get("beacon") or {}).get("werte", {}),
                    "gekoppelt": bool(w.get("paired") or w.get("trusted")),
                })
            sichtbar = sichtbar[:120]
            testwerte = list(self.testwerte)
            kal = None
            if self.kalibrierung:
                kal = {"kennung": self.kalibrierung["kennung"],
                       "bis": int(self.kalibrierung["bis"]),
                       "anzahl": len(self.kalibrierung["werte"]),
                       "ergebnis": self.kalibrierung.get("ergebnis")}

        daten = {
            "zeit": int(time.time()),
            "gestartet": int(self.startzeit),
            "version": gem.VERSION,
            "scanner": self.scanner,
            "adapter": self.cfg.get("adapter", "hci0"),
            "betriebsart": self.betriebsart(),
            "adapter_ok": 1 if self.adapter_ok else 0,
            "letzte_sichtung": int(self.letzte_sichtung_zeit),
            "suchfilter": self.suchfilter,
            "anwesend": anwesend_gesamt,
            "aktiv": aktive,
            "tags_gesamt": len(self.tags),
            "mqtt_verbunden": 1 if self.mqtt.verbunden else 0,
            "mqtt_gesendet": self.mqtt.gesendet,
            "mqtt_verluste": self.mqtt.verluste,
            "mqtt_letzter_erfolg": self.mqtt.letzter_erfolg,
            "push_offen": sum(1 for k, v in self.push_soll.items()
                              if self.push_ist.get(k) != v),
            "push_fehler": self.push_fehler,
            "ereignisse": self.ereignisse_gesamt,
            "themen": sorted(self.veroeffentlicht),
            "tags": uebersicht,
            "personen": {k: {"present": v["present"], "ts": v["ts"],
                             "namen": v["namen"]} for k, v in personen.items()},
            "sichtbar": sichtbar,
            "testwerte": testwerte,
            "kalibrierung": kal,
        }
        try:
            temp = gem.STATUS_FILE + ".tmp"
            # Rechte VOR dem Inhalt: sonst steht die Datei fuer die Dauer des
            # Schreibens mit den Vorgaben der umask da.
            merker = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
            with os.fdopen(merker, "w", encoding="utf-8") as fh:
                json.dump(daten, fh, ensure_ascii=False)
            os.replace(temp, gem.STATUS_FILE)
        except (OSError, TypeError, ValueError) as fehler:
            log.warning("Zustandsdatei nicht schreibbar: %s", fehler)

    # -- Steuerdatei --------------------------------------------------------

    def steuerdatei_lesen(self):
        """Auftraege der Oberflaeche entgegennehmen, ohne Neustart.

        Die Oberflaeche kann den Dienst nur ueber die Konfigurationsdatei
        erreichen - und deren Aenderung loest einen Neustart aus. Fuer
        Testmodus und Kalibrierung ist das gerade nicht erwuenscht. Deshalb
        eine kurzlebige Steuerdatei auf der Ramdisk.
        """
        try:
            if not os.path.isfile(gem.STEUER_FILE):
                return
            with open(gem.STEUER_FILE, "r", encoding="utf-8") as fh:
                auftrag = json.load(fh)
            os.unlink(gem.STEUER_FILE)
        except (OSError, ValueError):
            return
        if not isinstance(auftrag, dict):
            return
        art = str(auftrag.get("art", ""))
        kennung = str(auftrag.get("kennung", ""))
        if art == "testmodus" and kennung:
            dauer = min(300, max(10, int(auftrag.get("dauer", 60) or 60)))
            with self.sperre:
                self.testmodus[kennung] = time.time() + dauer
                self.testwerte = []
            log.info("Testmodus für %s, %d Sekunden", kennung, dauer)
        elif art == "kalibrierung" and kennung:
            dauer = min(60, max(5, int(auftrag.get("dauer", 10) or 10)))
            with self.sperre:
                self.kalibrierung = {"kennung": kennung,
                                     "mac": self._mac_der_kennung(kennung),
                                     "bis": time.time() + dauer,
                                     "werte": [], "ergebnis": None}
            log.info("Kalibrierung für %s, %d Sekunden", kennung, dauer)
        elif art == "batterie":
            self.batterie_zuletzt = ""
            log.info("Batterielauf von Hand angefordert")

    def kalibrierung_pruefen(self):
        with self.sperre:
            if not self.kalibrierung or self.kalibrierung.get("ergebnis") is not None:
                return
            if time.time() < self.kalibrierung["bis"]:
                return
            werte = self.kalibrierung["werte"]
            self.kalibrierung["ergebnis"] = int(gem.median(werte)) if werte else None
        log.info("Kalibrierung beendet: %d Messungen, Median %s",
                 len(werte), self.kalibrierung["ergebnis"])

    # -- Wachhund -----------------------------------------------------------

    def wachhund(self):
        """Merkt, wenn gar nichts mehr ankommt, und belebt den Adapter.

        Lebenszeichen ist die Sichtung IRGENDEINES Geraetes, nicht die eines
        Tags: ein Tag darf legitim eine Woche weg sein. In einer Wohngegend
        ist irgendetwas im Sekundentakt zu hoeren.

        Fail safe: solange nichts Belastbares vorliegt (keine Antwort von
        BlueZ, noch keine einzige Sichtung seit dem Start), wird NICHT
        eingegriffen. Ein Waechter, der im Zweifel zuschlaegt, ist schlimmer
        als keiner.
        """
        if self._zahl("wachhund", 1) != 1 or self.bluez is None:
            return
        stille = max(60, self._zahl("wachhund_stille", 300))

        # Stufe 1: hat ein anderes Programm die Suche beendet?
        sucht = self.bluez.sucht()
        if sucht is False:
            log.warning("Die Suche steht - sie wird neu gestartet. (Ein anderes "
                        "Programm kann sie beendet haben; BlueZ laesst nur eine "
                        "Suche gleichzeitig zu.)")
            try:
                self.suchfilter = self.bluez.suche_starten(self._zahl("discovery_rssi", 0))
            except gem.BlueZFehlt as fehler:
                log.error("%s", fehler)
            return

        if self.letzte_sichtung_mono is None:
            # Noch nie etwas gehoert. Erst nach der doppelten Stille-Zeit ab
            # dem Start ist das ein Befund - vorher ist es ein leerer Anfang.
            if time.monotonic() - (self.startzeit_mono) < 2 * stille:
                return
            leer = 2 * stille
        else:
            leer = time.monotonic() - self.letzte_sichtung_mono
        if leer < stille:
            return

        # Bremse: nicht im Minutentakt nachsetzen und das Protokoll fluten.
        if time.time() - self.letzte_wiederbelebung < max(300, stille):
            return
        self.letzte_wiederbelebung = time.time()
        self.adapter_ok = False
        self.wachhund_stufe += 1

        if self.wachhund_stufe == 1:
            log.warning("Seit %d Sekunden kein einziges Advertisement. Die Suche "
                        "wird angehalten und neu gestartet.", int(leer))
            try:
                self.bluez.suche_beenden()
                time.sleep(1.0)
                self.suchfilter = self.bluez.suche_starten(self._zahl("discovery_rssi", 0))
            except gem.BlueZFehlt as fehler:
                log.error("%s", fehler)
            return

        log.warning("Seit %d Sekunden kein einziges Advertisement, und der Neustart "
                    "der Suche hat nicht geholfen. Der Adapter wird aus- und wieder "
                    "eingeschaltet.", int(leer))
        try:
            self.bluez.aus_und_an()
            self.bluez.einschalten()
            self.suchfilter = self.bluez.suche_starten(self._zahl("discovery_rssi", 0))
        except gem.BlueZFehlt as fehler:
            log.error("%s", fehler)
            log.error("Hilft auch das nicht, hilft am Gerät: "
                      "sudo systemctl restart bluetooth. Dieses Plugin führt den "
                      "Befehl bewusst nicht selbst aus - er bräuchte eine "
                      "sudo-Regel, also eine systemweite Rechteänderung.")

    # -- Batterie -----------------------------------------------------------

    def batterie_runde(self):
        """Einmal taeglich den Batteriestand verbindungsfaehiger Tags lesen.

        Ab Werk AUS, und je Tag einzeln einzuschalten (Zusatzangabe batt=1).
        Gruende, die in der Oberflaeche auch so stehen:

          * Der Scan steht still, solange verbunden wird.
          * Die meisten Beacons sind gar nicht verbindungsfaehig; ein
            Versuch laeuft dann in die Zeitgrenze. Das wird je Tag gemerkt
            und nicht taeglich wiederholt.
          * Manche Schluesselfinder PIEPEN beim Verbinden. Nachts.
        """
        if self._zahl("batterie", 0) != 1 or self.bluez is None:
            return
        soll = str(self.cfg.get("batterie_uhrzeit", "04:00"))
        if not re.fullmatch(r"\d{1,2}:\d{2}", soll):
            soll = "04:00"
        jetzt = time.localtime()
        heute = time.strftime("%Y-%m-%d", jetzt)
        if self.batterie_zuletzt == heute:
            return
        stunde, minute = (int(x) for x in soll.split(":"))
        if (jetzt.tm_hour, jetzt.tm_min) < (stunde, minute):
            return

        kandidaten = []
        for tag in self.tags:
            if tag.get("aktiv") != "1":
                continue
            if str((tag.get("opt") or {}).get("batt", "")) != "1":
                continue
            if tag["kennung"] in self.batterie_unmoeglich:
                continue
            eintrag = self.eintrag_zum_tag(tag)
            if eintrag and eintrag.get("pfad"):
                kandidaten.append((tag, eintrag["pfad"]))
        self.batterie_zuletzt = heute
        if not kandidaten:
            return

        log.info("Batterielauf: %d Tag(s). Der Scan steht dabei kurz still.",
                 len(kandidaten))
        try:
            self.bluez.suche_beenden()
            time.sleep(0.5)
            for tag, pfad in kandidaten[:10]:
                prozent, meldung = self.bluez.batterie_lesen(pfad)
                zustand = self._zustand(tag["kennung"])
                if prozent is not None:
                    zustand["batterie"] = int(prozent)
                    zustand["batterie_zeit"] = time.time()
                    log.info("%s: Batterie %d %%", tag.get("name") or tag["kennung"],
                             prozent)
                else:
                    self.batterie_unmoeglich.add(tag["kennung"])
                    log.info("%s: kein Batteriestand lesbar - wird nicht erneut "
                             "versucht. %s", tag.get("name") or tag["kennung"], meldung)
        finally:
            try:
                self.suchfilter = self.bluez.suche_starten(self._zahl("discovery_rssi", 0))
            except gem.BlueZFehlt as fehler:
                log.error("Suche nach dem Batterielauf nicht wieder gestartet: %s",
                          fehler)

    # -- Aufraeumen ---------------------------------------------------------

    def aufraeumen(self, geraete):
        """Fremde Geraete aus dem BlueZ-Zwischenspeicher werfen.

        BlueZ merkt sich jedes je gesehene Geraet; in einer Wohngegend sind
        das schnell hunderte, und GetManagedObjects wird entsprechend traege.

        VERSCHONT werden: konfigurierte Tags, alles, was in den letzten fuenf
        Minuten zu hoeren war - und seit 1.3.0 auch GEKOPPELTE, vertraute und
        verbundene Geraete. RemoveDevice loescht bei einem gekoppelten Geraet
        die Kopplung; bis 1.2.10 verlor eine fremde Bluetooth-Tastatur oder
        ein Lautsprecher sie dadurch dauerhaft und lautlos.
        """
        bekannt = {t["kennung"] for t in self.tags if t["art"] == "mac"}
        jetzt_m = time.monotonic()
        entfernt = 0
        geschont = 0
        for mac, werte in geraete.items():
            if mac in bekannt:
                continue
            if werte.get("paired") or werte.get("trusted") or werte.get("connected"):
                geschont += 1
                continue
            with self.sperre:
                eintrag = self.gesehen.get(mac)
                jung = eintrag and (jetzt_m - eintrag.get("mono", 0)) < 300
            if jung:
                continue
            if self.bluez.vergessen(werte["pfad"]):
                entfernt += 1
                with self.sperre:
                    self.gesehen.pop(mac, None)
        if entfernt or geschont:
            log.info("%d fremde Geräte aus dem BlueZ-Zwischenspeicher entfernt, "
                     "%d gekoppelte verschont", entfernt, geschont)

    def sichtungen_verfallen(self):
        """Alte Sichtungen vergessen, damit die Liste nicht unbegrenzt waechst.

        Konfigurierte Tags werden dabei NICHT vergessen. Bis 1.2.10 fielen
        auch sie nach zehn Minuten heraus, und last_seen sprang dann von 599
        auf -1 ("nie gesehen") - eine stille Falschaussage genau dort, wo der
        Wert erst interessant wird.
        """
        jetzt_m = time.monotonic()
        grenze = max(600, self._zahl("abwesenheit_nach", 30) * 10)
        geschuetzt = {t["kennung"] for t in self.tags if t["art"] == "mac"}
        with self.sperre:
            for mac in [m for m, w in self.gesehen.items()
                        if m not in geschuetzt and jetzt_m - w.get("mono", 0) > grenze]:
                del self.gesehen[mac]

    # -- Betriebsart --------------------------------------------------------

    def betriebsart(self):
        art = str(self.cfg.get("betriebsart", "signal")).strip().lower()
        return art if art in ("signal", "abfrage") else "signal"

    def einlesen(self):
        """Geraete von BlueZ holen (Abfragebetrieb und Sicherungsabfrage)."""
        try:
            geraete = self.bluez.geraete()
        except gem.BlueZFehlt as fehler:
            log.error("%s", fehler)
            return None
        for mac, werte in geraete.items():
            # Kein RSSI heisst: BlueZ kennt das Geraet noch aus dem
            # Zwischenspeicher, empfaengt es aber gerade nicht.
            if werte.get("rssi") is not None:
                self.sichtung(mac, werte)
            else:
                with self.sperre:
                    e = self.gesehen.get(mac)
                    if e is not None:
                        e["pfad"] = werte.get("pfad", e.get("pfad", ""))
                        for merker in ("paired", "trusted", "connected"):
                            e[merker] = werte.get(merker, e.get(merker, False))
        self.sichtungen_verfallen()
        return geraete

    # -- Ablauf -------------------------------------------------------------

    def verbinden_mit_geduld(self):
        while self.laeuft:
            try:
                self.bluez.verbinden()
                self.bluez.einschalten()
                self.suchfilter = self.bluez.suche_starten(self._zahl("discovery_rssi", 0))
                log.info("Suche läuft auf %s (Filterstufe %d)",
                         self.cfg.get("adapter", "hci0"), self.suchfilter)
                return True
            except gem.BlueZFehlt as fehler:
                log.error("%s", fehler)
                log.error("Neuer Versuch in 30 Sekunden.")
                for _ in range(30):
                    if not self.laeuft:
                        return False
                    time.sleep(1)
        return False

    def runde(self):
        """Ein Durchlauf: auswerten, melden, aufraeumen. Beide Betriebsarten."""
        self.steuerdatei_lesen()
        self.kalibrierung_pruefen()

        # Nach einer Neuverbindung zum Broker sind dessen retained-Werte
        # womoeglich weg. Dann muss ALLES neu gesendet werden.
        neu_verbunden = self.mqtt.neumeldung.is_set()
        if neu_verbunden:
            self.mqtt.neumeldung.clear()
            self.letzter_stand.clear()
            log.info("MQTT neu verbunden - alle Zustände werden erneut gemeldet")

        vollmeldung = max(self.intervall, self._zahl("aktualisierung", 60))
        erzwingen = neu_verbunden or (time.time() - self.letzte_vollmeldung) >= vollmeldung
        self.auswerten(erzwingen=erzwingen)
        if erzwingen:
            self.letzte_vollmeldung = time.time()
            # Der HTTP-Weg bekommt die Vollmeldung ebenfalls: bis 1.2.10 lief
            # sie an ihm vorbei, weil _senden() dann "nicht geaendert" meldete.
            if self.cfg.get("http_push", "0") == "1":
                self.push_ist.clear()

        self.wachhund()
        self.batterie_runde()

        if time.time() - self.letztes_aufraeumen > 900:
            self.letztes_aufraeumen = time.time()
            try:
                self.aufraeumen(self.bluez.geraete())
            except gem.BlueZFehlt:
                pass
            self.verlauf_kappen()
            gem.log_kappen(LOG_DATEI, self._zahl("log_kappung_kb", 500))

        if self._mtime() != self.config_mtime:
            self.konfiguration_neu_einlesen()

    def konfiguration_neu_einlesen(self):
        """Konfiguration uebernehmen, ohne den Dienst neu zu starten.

        Bis 1.2.10 wurden `themenpraefix`, `adapter` und der MQTT-Schalter
        NICHT uebernommen - sie steckten in Objekten, die nur der
        Konstruktor setzte -, waehrend das Protokoll "wird neu eingelesen"
        schrieb. Jetzt wird jede Aenderung entweder uebernommen oder
        ausdruecklich als Neustart gemeldet.
        """
        self.config_mtime = self._mtime()
        alte_zweige = {self._zweig(t) for t in self.tags}
        alter_praefix = self.praefix
        alter_adapter = self.cfg.get("adapter", "hci0")
        altes_mqtt = self.cfg.get("mqtt", "1")

        self.cfg, self.tags, _ = gem.konfiguration_lesen()
        self.ms = miniserver_liste()
        self.letzter_stand.clear()
        self.push_ist.clear()
        self.intervall = max(2, self._zahl("intervall", 5))
        self.scanner = (self.cfg.get("scanner_name") or "").strip() or gem.rechnername()

        neue_zweige = {self._zweig(t) for t in self.tags}
        entfallen = alte_zweige - neue_zweige
        # Auch abgehakte Tags: fuer sie wird nichts mehr gesendet, ihr
        # letzter Wert stuende sonst fuer immer retained im Broker.
        for t in self.tags:
            if t.get("aktiv") != "1":
                entfallen.add(self._zweig(t))
        if entfallen:
            self.themen_loeschen(entfallen)
        for kennung in [k for k in self.tagzustand
                        if k not in {t["kennung"] for t in self.tags}]:
            del self.tagzustand[kennung]

        neuer_praefix = self.cfg.get("themenpraefix") or "blescanner"
        if neuer_praefix != alter_praefix:
            log.info("Themenpräfix geändert: %s -> %s. Die alten Themen bleiben "
                     "im Broker stehen, bis sie dort entfernt werden.",
                     alter_praefix, neuer_praefix)
            self.praefix = neuer_praefix
            self.mqtt.praefix = neuer_praefix
            self.veroeffentlicht.clear()
        if self.cfg.get("adapter", "hci0") != alter_adapter:
            log.info("Bluetooth-Adapter geändert: %s -> %s. Die Suche wird "
                     "umgehängt.", alter_adapter, self.cfg.get("adapter", "hci0"))
            try:
                self.bluez.suche_beenden()
            except Exception:      # noqa: BLE001
                pass
            self.bluez = gem.BlueZ(self.cfg.get("adapter", "hci0"))
            self.verbinden_mit_geduld()
        if self.cfg.get("mqtt", "1") != altes_mqtt:
            if self.cfg.get("mqtt", "1") == "1":
                log.info("MQTT wurde eingeschaltet - die Verbindung wird aufgebaut.")
                self.mqtt.start()
            else:
                log.info("MQTT wurde ausgeschaltet.")
                self.mqtt.stop()
                self.mqtt.client = None
        log.info("Konfiguration neu eingelesen: %d Tag(s), davon %d aktiv",
                 len(self.tags), sum(1 for t in self.tags if t.get("aktiv") == "1"))

    # -- Signalbetrieb ------------------------------------------------------

    def signalbetrieb(self):
        """Ereignisgesteuert ueber PropertiesChanged.

        Gewinn gegenueber der Abfrage: der Dienst sieht JEDES Werbepaket
        statt einer Stichprobe je Runde. Ein Beacon, das alle 100 ms wirbt,
        liefert in fuenf Sekunden fuenfzig Pakete - bis 1.2.10 wurden
        neunundvierzig davon weggeworfen, und fuer eine Mittelung ist das
        der Unterschied zwischen brauchbar und nicht.

        Die Auswertung bleibt getaktet: sie laeuft als GLib-Zeitgeber und
        ruft dieselbe runde() wie der Abfragebetrieb.

        Rueckgabe: False, wenn python3-gi fehlt - dann faellt der Aufrufer
        auf den Abfragebetrieb zurueck und SAGT das auch.
        """
        try:
            from dbus.mainloop.glib import DBusGMainLoop
            from gi.repository import GLib
        except ImportError as fehler:
            log.warning("python3-gi ist nicht verfügbar (%s) - es wird im "
                        "Abfragebetrieb gearbeitet.", fehler)
            return False

        # MUSS vor der ersten SystemBus()-Erzeugung stehen.
        DBusGMainLoop(set_as_default=True)

        self.bluez = gem.BlueZ(self.cfg.get("adapter", "hci0"))
        if not self.verbinden_mit_geduld():
            return True

        def bei_aenderung(schnittstelle, geaendert, _entfernt, pfad=None):
            if str(schnittstelle) != gem.DEVICE_IF or not pfad:
                return
            if not str(pfad).startswith(self.bluez.adapterpfad + "/"):
                return
            if "RSSI" not in geaendert:
                return
            # PropertiesChanged liefert nur die geaenderten Werte. Adresse und
            # Werbedaten werden einmal nachgeholt und danach aus dem eigenen
            # Bestand fortgeschrieben.
            mac, werte = self.bluez.eigenschaften(str(pfad))
            if mac and werte:
                self.sichtung(mac, werte)

        def bei_neuem_geraet(pfad, schnittstellen):
            geraet = schnittstellen.get(gem.DEVICE_IF)
            if not geraet or not str(pfad).startswith(self.bluez.adapterpfad + "/"):
                return
            mac, werte = self.bluez.geraet_lesen(pfad, geraet)
            if mac and werte and werte.get("rssi") is not None:
                self.sichtung(mac, werte)

        self.bluez.bus.add_signal_receiver(
            bei_aenderung, dbus_interface="org.freedesktop.DBus.Properties",
            signal_name="PropertiesChanged", arg0=gem.DEVICE_IF,
            path_keyword="pfad")
        self.bluez.bus.add_signal_receiver(
            bei_neuem_geraet, dbus_interface=gem.OBJMGR_IF,
            signal_name="InterfacesAdded")

        schleife = GLib.MainLoop()

        def takt():
            if not self.laeuft:
                schleife.quit()
                return False
            try:
                self.runde()
            except Exception as fehler:      # noqa: BLE001
                log.exception("Fehler im Durchlauf: %s", fehler)
            return True

        def sicherungsabfrage():
            """Alle 30 Sekunden einmal vollstaendig nachsehen.

            Faengt verpasste Signale auf und haelt die Pfade der Geraete
            frisch, die der Aufraeumer und der Batterielauf brauchen.
            """
            if not self.laeuft:
                return False
            try:
                self.einlesen()
            except Exception as fehler:      # noqa: BLE001
                log.warning("Sicherungsabfrage fehlgeschlagen: %s", fehler)
            return True

        def abbruch():
            if not self.laeuft:
                schleife.quit()
                return False
            return True

        GLib.timeout_add_seconds(self.intervall, takt)
        GLib.timeout_add_seconds(30, sicherungsabfrage)
        GLib.timeout_add_seconds(1, abbruch)
        log.info("Signalbetrieb: BlueZ meldet jede Änderung, Auswertung alle %d s",
                 self.intervall)
        schleife.run()
        return True

    def abfragebetrieb(self):
        self.bluez = gem.BlueZ(self.cfg.get("adapter", "hci0"))
        if not self.verbinden_mit_geduld():
            return
        log.info("Abfragebetrieb: alle %d s einmal GetManagedObjects()", self.intervall)
        while self.laeuft:
            if self.einlesen() is None:
                try:
                    self.bluez.verbinden()
                    self.bluez.einschalten()
                    self.suchfilter = self.bluez.suche_starten(
                        self._zahl("discovery_rssi", 0))
                    log.info("Verbindung zu BlueZ wiederhergestellt")
                except gem.BlueZFehlt as fehler:
                    log.error("%s", fehler)
                    self.adapter_ok = False
                    for _ in range(15):
                        if not self.laeuft:
                            return
                        time.sleep(1)
                    continue
            try:
                self.runde()
            except Exception as fehler:      # noqa: BLE001
                log.exception("Fehler im Durchlauf: %s", fehler)
            for _ in range(self.intervall):
                if not self.laeuft:
                    return
                time.sleep(1)

    def start(self):
        self.startzeit_mono = time.monotonic()
        log.info("BLE-Scanner NG %s startet (Scanner %s)", gem.VERSION, self.scanner)
        log.info("Konfiguration: %s", gem.CONFIG_FILE)
        log.info("%d Tag(s) konfiguriert, davon %d aktiv",
                 len(self.tags), sum(1 for t in self.tags if t.get("aktiv") == "1"))
        gem.log_kappen(LOG_DATEI, self._zahl("log_kappung_kb", 500))

        self.intervall = max(2, self._zahl("intervall", 5))
        self.letzte_vollmeldung = 0.0
        self.letztes_aufraeumen = time.time()

        if self.cfg.get("mqtt", "1") == "1":
            if self._zahl("raum", 0) == 1:
                self.mqtt.abo_rueckruf = self.raum_abonnieren
            self.mqtt.start()
        else:
            log.info("MQTT ist ausgeschaltet")

        # Der Push-Faden laeuft immer mit, auch wenn http_push gerade aus ist:
        # der Schalter kann zur Laufzeit umgestellt werden.
        self.push_faden = threading.Thread(target=self.push_arbeiter,
                                           name="http-push", daemon=True)
        self.push_faden.start()

        if self.betriebsart() == "signal":
            if self.signalbetrieb():
                return
            log.warning("Es wird auf den Abfragebetrieb zurückgefallen. Damit sieht "
                        "der Dienst je Runde nur EINEN Wert je Gerät statt jedes "
                        "Werbepakets - die Glättung wird dadurch gröber.")
        self.abfragebetrieb()

    def stop(self):
        self.laeuft = False
        if self.bluez:
            self.bluez.suche_beenden()
        self.mqtt.stop()


def main():
    dienst = Dienst()

    def beenden(signum, rahmen):   # noqa: ARG001
        log.info("Signal %s empfangen - beende", signum)
        dienst.laeuft = False

    signal.signal(signal.SIGTERM, beenden)
    signal.signal(signal.SIGINT, beenden)

    try:
        dienst.start()
    except KeyboardInterrupt:
        pass
    except Exception as fehler:      # noqa: BLE001
        # Eine einzige unerwartete Ausnahme beendete den Dienst bis 1.2.10
        # endgueltig, und im Broker standen die letzten Werte retained
        # weiter. Jetzt wird sie protokolliert und server/ok auf 0 gesetzt,
        # damit Loxone den Ausfall sieht.
        log.exception("Unerwarteter Fehler - der Dienst endet: %s", fehler)
        try:
            dienst.mqtt.senden("server/ok", "0")
        except Exception:            # noqa: BLE001
            pass
        raise
    finally:
        dienst.stop()
        log.info("Beendet")


if __name__ == "__main__":
    main()
