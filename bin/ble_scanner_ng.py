#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BLE-Scanner NG - Dienst

Sucht dauerhaft nach Bluetooth-Low-Energy-Geraeten und meldet je
konfiguriertem Tag, ob es in Reichweite ist. Zustaende gehen per MQTT
retained an den Broker; auf Wunsch zusaetzlich als virtueller Eingang
per HTTP an den Miniserver, wie es die Originalfassung getan hat.

Grundlage ist das Plugin von Christian Woerstenfeld. Der Dienst wurde fuer
LoxBerry 4 neu geschrieben:

  * Python 3 statt Python 2 - blescan.py war Python-2-Quelltext
    (print-Anweisungen, xrange) und laesst sich unter Python 3 nicht
    einmal einlesen.
  * BlueZ ueber D-Bus statt pybluez und hciconfig. pybluez gibt es nur
    fuer Python 2, die Pakete python-bluez und python-dev existieren auf
    Bookworm und Trixie nicht mehr.
  * ein durchlaufender Dienst statt "Scan auf Zuruf": Loxone muss nichts
    mehr abfragen, die Zustaende stehen retained im Broker.
"""

import json
import os
import queue
import signal
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import bl_common as gem   # noqa: E402

import logging  # noqa: E402

_handlers = []
try:
    os.makedirs(gem.LOG_DIR, exist_ok=True)
    _handlers.append(logging.FileHandler(os.path.join(gem.LOG_DIR, "ble_scanner_ng.log")))
except OSError:
    pass
_handlers.append(logging.StreamHandler(sys.stdout))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=_handlers,
)
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
        return {"host": str(host),
                "port": int(hole("Brokerport", "brokerport") or 1883),
                "user": hole("Brokeruser", "brokeruser"),
                "pass": hole("Brokerpass", "brokerpass")}
    log.warning("Kein MQTT-Broker in general.json gefunden")
    return None


class Mqtt:
    """Duenne Huelle um paho-mqtt. Faellt still aus, wenn Bibliothek oder
    Gateway fehlen - der HTTP-Weg funktioniert dann weiter.

    Zum haeufigsten Missverstaendnis vorweg: paho verbindet nach einem
    Abbruch von SELBST wieder. loop_start() laesst loop_forever() in einem
    Thread laufen, und der hat seine eigene Wiederverbindungsschleife. Ein
    on_disconnect-Behandler mit eigenem reconnect() ist dafuer nicht noetig.

    Zwei andere Dinge waren bis 1.1.0 wirklich falsch:

    1. Der ERSTE Verbindungsversuch. Schlug connect() fehl - und beim Start
       zum Systemstart ist das der Regelfall, weil das MQTT-Gateway noch
       nicht laeuft -, kehrte start() mit False zurueck, ohne loop_start()
       aufzurufen. self.client blieb dabei gesetzt. senden() rief danach
       publish() auf einem Client ohne Netzwerkschleife auf: die Nachricht
       verschwand, ohne Fehler, fuer die gesamte Laufzeit. Nur eine Zeile im
       Protokoll wies darauf hin, und die las niemand.
       Jetzt wird connect_async() benutzt und loop_start() in JEDEM Fall
       gestartet - dann kuemmert sich paho auch um den ersten Versuch.

    2. Nach einem Neustart des Brokers sind die retained-Werte weg. Da
       _senden() unveraenderte Werte unterdrueckt, blieben die Themen dann
       leer, bis sich zufaellig etwas aenderte - bei einem Tag, der eine
       Woche im Schrank liegt, also eine Woche lang. Der on_connect-Behandler
       verlangt jetzt eine vollstaendige Neumeldung.
    """

    def __init__(self, praefix):
        self.praefix = praefix
        self.client = None
        self.verbunden = False
        # Wird von aussen gelesen: nach einer Neuverbindung muessen ALLE
        # Werte erneut gesendet werden, weil der Broker seine retained-Werte
        # verloren haben kann.
        self.neumeldung_faellig = False
        self.verluste = 0

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
                self.neumeldung_faellig = True
                log.info("MQTT verbunden mit %s:%s, Themenpräfix %s",
                         zugang["host"], zugang["port"], self.praefix)
                self.senden("server/online", "1")
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
        # Wartezeit zwischen den Versuchen begrenzen, sonst waechst sie bei
        # paho bis auf zwei Minuten - beim Systemstart zu lang.
        try:
            self.client.reconnect_delay_set(min_delay=1, max_delay=30)
        except Exception:  # noqa: BLE001
            pass
        # connect_async statt connect: es wirft nicht, wenn der Broker noch
        # nicht da ist, und loop_start() versucht es weiter.
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

    def senden(self, unterthema, wert):
        if not self.client:
            return False
        try:
            erg = self.client.publish(self.praefix + "/" + unterthema,
                                      str(wert), qos=0, retain=True)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veröffentlichung fehlgeschlagen: %s", fehler)
            return False
        # Der Rueckgabewert wird ausgewertet. Bei qos=0 und getrennter
        # Verbindung ist die Nachricht verloren - das gehoert gezaehlt und
        # nicht verschwiegen.
        rc = getattr(erg, "rc", 0)
        if rc != 0:
            self.verluste += 1
            if self.verluste in (1, 10, 100) or self.verluste % 1000 == 0:
                log.warning("MQTT: %d Nachricht(en) nicht abgesetzt (letzter Code %s). "
                            "Laeuft das MQTT-Gateway?", self.verluste, rc)
            return False
        return True

    def stop(self):
        if not self.client:
            return
        try:
            self.senden("server/online", "0")
            # Kurz warten, damit die letzte Nachricht das Haus verlaesst -
            # ohne das steht im Broker retained weiter '1'.
            time.sleep(0.3)
            self.client.loop_stop()
            self.client.disconnect()
        except Exception:  # noqa: BLE001
            pass


# ---------------------------------------------------------------------------
# HTTP-Rueckfallweg an den Miniserver
# ---------------------------------------------------------------------------

def miniserver_liste():
    """Miniserver aus general.json. Rueckgabe: Liste aus (Name, Adresse,
    Port, Benutzer, Kennwort)."""
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
        out.append({
            "nr": str(nr),
            "name": ms.get("Name") or ("Miniserver " + str(nr)),
            "adresse": adresse,
            "port": int(ms.get("Port") or 80),
            "user": ms.get("Admin") or ms.get("Username") or "",
            "pass": ms.get("Pass") or ms.get("Password") or "",
        })
    return out


def http_push(ms, name, wert, zeitgrenze=4):
    """Virtuellen Eingang am Miniserver setzen.

    Anders als frueher stehen die Zugangsdaten nicht mehr in der URL, sondern
    im Authorization-Kopf. In der URL landen sie sonst in jedem Proxy- und
    Serverprotokoll.
    """
    url = "http://{0}:{1}/dev/sps/io/{2}/{3}".format(
        ms["adresse"], ms["port"], urllib.parse.quote(name), wert)
    anfrage = urllib.request.Request(url)
    if ms["user"]:
        import base64
        roh = "{0}:{1}".format(ms["user"], ms["pass"]).encode("utf-8")
        anfrage.add_header("Authorization",
                           "Basic " + base64.b64encode(roh).decode("ascii"))
    try:
        with urllib.request.urlopen(anfrage, timeout=zeitgrenze):
            return True, ""
    except urllib.error.HTTPError as fehler:
        return False, "HTTP {0}".format(fehler.code)
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
        self.mqtt = Mqtt(self.praefix)
        self.bluez = None
        self.laeuft = True
        self.gesehen = {}        # MAC -> {"zeit": float, "rssi": int, "name": str}
        self.letzter_stand = {}  # Thema -> Wert
        self.config_mtime = self._mtime()
        self.ms = miniserver_liste()
        # HTTP-Push laeuft in einem eigenen Faden. Die Schlange ist begrenzt:
        # eine unbegrenzte wuerde bei einem dauerhaft stummen Miniserver
        # unbemerkt Speicher fressen.
        self.push_schlange = queue.Queue(maxsize=200)
        self.push_sperre = {}
        self.push_verworfen = 0
        self.push_faden = None

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

    # -- Melden -------------------------------------------------------------

    def _senden(self, thema, wert, erzwingen=False):
        wert = "" if wert is None else str(wert)
        if not erzwingen and self.letzter_stand.get(thema) == wert:
            return False
        geaendert = self.letzter_stand.get(thema) != wert
        self.letzter_stand[thema] = wert
        self.mqtt.senden(thema, wert)
        return geaendert

    def auswerten(self, erzwingen=False):
        """Aus den zuletzt gesehenen Geraeten den Zustand je Tag bilden."""
        jetzt = time.time()
        grenze = self._zahl("abwesenheit_nach", 30)
        nah = self._zahl("rssi_nah", -65)
        mittel = self._zahl("rssi_mittel", -85)
        anwesend_gesamt = 0
        uebersicht = []

        for tag in self.tags:
            mac = tag["mac"]
            thema = gem.thema_saeubern(mac.replace(":", "")) or "unbekannt"
            eintrag = self.gesehen.get(mac)
            alter = (jetzt - eintrag["zeit"]) if eintrag else None
            anwesend = 1 if (alter is not None and alter <= grenze) else 0
            rssi = eintrag["rssi"] if (eintrag and anwesend) else None
            stufe = gem.signalstufe(rssi, nah, mittel) if anwesend else 0

            if tag.get("aktiv", "1") == "1":
                # Nur aktive Tags zaehlen in die Zusammenfassung. Ein
                # abgehakter Tag soll die Anwesenheitszahl nicht verfaelschen.
                if anwesend:
                    anwesend_gesamt += 1
                geaendert = self._senden("{0}/present".format(thema), anwesend, erzwingen)
                self._senden("{0}/rssi".format(thema), rssi if rssi is not None else -255, erzwingen)
                self._senden("{0}/level".format(thema), stufe, erzwingen)
                self._senden("{0}/last_seen".format(thema),
                             int(alter) if alter is not None else -1, erzwingen)
                self._senden("{0}/name".format(thema),
                             tag.get("name") or (eintrag or {}).get("name", ""), erzwingen)
                if geaendert and self.cfg.get("http_push", "0") == "1":
                    self.an_miniserver(mac, anwesend)

            uebersicht.append({
                "mac": mac,
                "name": tag.get("name", ""),
                "aktiv": tag.get("aktiv", "1"),
                "anwesend": anwesend,
                "rssi": rssi,
                "stufe": stufe,
                "seit": int(alter) if alter is not None else None,
            })

        self._senden("summary/present", anwesend_gesamt, erzwingen)
        self._senden("summary/tags", len(self.tags), erzwingen)
        self.zustand_schreiben(uebersicht, anwesend_gesamt)

    def an_miniserver(self, mac, anwesend):
        """Virtuellen Eingang setzen - der Weg der Originalfassung.

        Der Auftrag wird nur EINGEREIHT, nicht hier ausgefuehrt. Bis 1.1.0
        lief der HTTP-Aufruf im Scan-Faden: bei fuenf Tags, die gleichzeitig
        wechseln, und einem Miniserver, der gerade neu startet, blockierte das
        5 x 4 s = 20 Sekunden. In dieser Zeit fragt niemand BlueZ ab, und
        genau dann gesendete Beacons gehen verloren - das Ergebnis ist eine
        flatternde Anwesenheit, deren Ursache man nirgends sieht.
        """
        kennung = (self.cfg.get("loxberry_id") or "").strip()
        name = "{0}BLE_{1}".format(kennung, mac.replace(":", "_"))
        for ms in self.ms:
            try:
                self.push_schlange.put_nowait((ms, name, anwesend))
            except queue.Full:
                # Voll heisst: der Miniserver nimmt seit langem nichts an.
                # Dann ist der aktuelle Wert wichtiger als der aelteste.
                try:
                    self.push_schlange.get_nowait()
                    self.push_schlange.put_nowait((ms, name, anwesend))
                    self.push_verworfen += 1
                except (queue.Empty, queue.Full):
                    pass
                if self.push_verworfen in (1, 10, 100):
                    log.warning("HTTP-Push: %d Auftrag/Auftraege verworfen - der "
                                "Miniserver nimmt nichts an. MQTT ist davon "
                                "unberuehrt.", self.push_verworfen)

    def push_arbeiter(self):
        """Hintergrundfaden: arbeitet die HTTP-Aufträge ab.

        Ein eigener Faden statt asyncio, weil der Dienst sonst keinen
        Ereignisverwalter braucht - und ein Faden, der nur wartet und
        gelegentlich eine Adresse abruft, ist die einfachere Loesung, die man
        auch in zwei Jahren noch versteht.
        """
        while self.laeuft:
            try:
                auftrag = self.push_schlange.get(timeout=1.0)
            except queue.Empty:
                continue
            ms, name, wert = auftrag
            # Ein Miniserver, der nicht antwortet, wird eine Weile in Ruhe
            # gelassen - sonst kostet jeder Auftrag erneut die volle
            # Zeitgrenze, auch wenn seit Stunden klar ist, dass niemand da ist.
            sperre = self.push_sperre.get(ms["nr"], 0)
            if time.time() < sperre:
                continue
            ok, fehler = http_push(ms, name, wert)
            if ok:
                log.info("An %s gesendet: %s = %s", ms["name"], name, wert)
                self.push_sperre.pop(ms["nr"], None)
            else:
                self.push_sperre[ms["nr"]] = time.time() + 60
                log.warning("An %s fehlgeschlagen: %s = %s (%s). Naechster Versuch "
                            "an diesen Miniserver in 60 s.",
                            ms["name"], name, wert, fehler)

    def zustand_schreiben(self, uebersicht, anwesend_gesamt):
        """Zustandsdatei fuer die Oberflaeche."""
        daten = {
            "zeit": int(time.time()),
            "version": gem.VERSION,
            "adapter": self.cfg.get("adapter", "hci0"),
            "anwesend": anwesend_gesamt,
            "tags": uebersicht,
            "sichtbar": [
                {"mac": mac, "rssi": w["rssi"], "name": w["name"],
                 "seit": int(time.time() - w["zeit"])}
                for mac, w in sorted(self.gesehen.items(),
                                     key=lambda p: -(p[1]["rssi"] or -255))
            ][:80],
        }
        try:
            temp = gem.STATUS_FILE + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump(daten, fh, ensure_ascii=False)
            os.replace(temp, gem.STATUS_FILE)
            os.chmod(gem.STATUS_FILE, 0o644)
        except OSError as fehler:
            log.warning("Zustandsdatei nicht schreibbar: %s", fehler)

    # -- Ablauf -------------------------------------------------------------

    def einlesen(self):
        """Geraete von BlueZ holen und die Sichtungen fortschreiben."""
        try:
            geraete = self.bluez.geraete()
        except gem.BlueZFehlt as fehler:
            log.error("%s", fehler)
            return False

        jetzt = time.time()
        for mac, werte in geraete.items():
            if werte["rssi"] is None:
                # Kein RSSI heisst: BlueZ kennt das Geraet noch aus dem
                # Zwischenspeicher, empfaengt es aber gerade nicht.
                continue
            self.gesehen[mac] = {"zeit": jetzt,
                                 "rssi": werte["rssi"],
                                 "name": werte["name"]}

        # Alte Sichtungen vergessen, damit die Liste nicht unbegrenzt waechst
        grenze = max(600, self._zahl("abwesenheit_nach", 30) * 10)
        for mac in [m for m, w in self.gesehen.items() if jetzt - w["zeit"] > grenze]:
            del self.gesehen[mac]
        return True

    def aufraeumen(self, geraete):
        """Fremde Geraete aus dem BlueZ-Zwischenspeicher werfen.

        BlueZ merkt sich jedes je gesehene Geraet. In einer Wohngegend sind
        das schnell hunderte, und GetManagedObjects wird entsprechend traege.
        Konfigurierte Tags bleiben verschont.
        """
        bekannt = {t["mac"] for t in self.tags}
        jetzt = time.time()
        entfernt = 0
        for mac, werte in geraete.items():
            if mac in bekannt:
                continue
            eintrag = self.gesehen.get(mac)
            if eintrag and jetzt - eintrag["zeit"] < 300:
                continue
            if self.bluez.vergessen(werte["pfad"]):
                entfernt += 1
                self.gesehen.pop(mac, None)
        if entfernt:
            log.info("%d fremde Geräte aus dem BlueZ-Zwischenspeicher entfernt", entfernt)

    def start(self):
        log.info("BLE-Scanner NG %s startet", gem.VERSION)
        log.info("Konfiguration: %s", gem.CONFIG_FILE)
        log.info("%d Tag(s) konfiguriert, davon %d aktiv",
                 len(self.tags), sum(1 for t in self.tags if t.get("aktiv") == "1"))

        if self.cfg.get("mqtt", "1") == "1":
            self.mqtt.start()
        else:
            log.info("MQTT ist ausgeschaltet")

        # Der Push-Faden laeuft immer mit, auch wenn http_push gerade aus ist:
        # der Schalter kann zur Laufzeit umgestellt werden, und ein wartender
        # Faden kostet nichts.
        self.push_faden = threading.Thread(target=self.push_arbeiter,
                                           name="http-push", daemon=True)
        self.push_faden.start()

        self.bluez = gem.BlueZ(self.cfg.get("adapter", "hci0"))
        while self.laeuft:
            try:
                self.bluez.verbinden()
                self.bluez.einschalten()
                self.bluez.suche_starten()
                log.info("Suche läuft auf %s", self.cfg.get("adapter", "hci0"))
                break
            except gem.BlueZFehlt as fehler:
                log.error("%s", fehler)
                log.error("Neuer Versuch in 30 Sekunden.")
                time.sleep(30)

        intervall = max(2, self._zahl("intervall", 5))
        vollmeldung_alle = max(intervall, self._zahl("aktualisierung", 60))
        letzte_vollmeldung = 0
        letztes_aufraeumen = time.time()

        while self.laeuft:
            if not self.einlesen():
                # Verbindung verloren - neu aufbauen
                try:
                    self.bluez.verbinden()
                    self.bluez.einschalten()
                    self.bluez.suche_starten()
                    log.info("Verbindung zu BlueZ wiederhergestellt")
                except gem.BlueZFehlt as fehler:
                    log.error("%s", fehler)
                    time.sleep(15)
                    continue

            # Nach einer Neuverbindung zum Broker sind dessen retained-Werte
            # womoeglich weg. Dann muss ALLES neu gesendet werden - sonst
            # bleiben die Themen leer, bis sich zufaellig etwas aendert, und
            # bei einem Tag im Schrank kann das Wochen dauern.
            neu_verbunden = self.mqtt.neumeldung_faellig
            if neu_verbunden:
                self.mqtt.neumeldung_faellig = False
                self.letzter_stand.clear()
                log.info("MQTT neu verbunden - alle Zustände werden erneut gemeldet")

            erzwingen = neu_verbunden or (time.time() - letzte_vollmeldung) >= vollmeldung_alle
            self.auswerten(erzwingen=erzwingen)
            if erzwingen:
                letzte_vollmeldung = time.time()

            if time.time() - letztes_aufraeumen > 900:
                letztes_aufraeumen = time.time()
                try:
                    self.aufraeumen(self.bluez.geraete())
                except gem.BlueZFehlt:
                    pass

            if self._mtime() != self.config_mtime:
                log.info("Konfiguration geändert - wird neu eingelesen")
                self.config_mtime = self._mtime()
                self.cfg, self.tags, _ = gem.konfiguration_lesen()
                self.ms = miniserver_liste()
                self.letzter_stand.clear()
                intervall = max(2, self._zahl("intervall", 5))
                vollmeldung_alle = max(intervall, self._zahl("aktualisierung", 60))

            time.sleep(intervall)

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
    finally:
        dienst.stop()
        log.info("Beendet")


if __name__ == "__main__":
    main()
