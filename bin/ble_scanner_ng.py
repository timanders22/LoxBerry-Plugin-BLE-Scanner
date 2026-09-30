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


# Grund einer abgewiesenen MQTT-Anmeldung in Worten. paho 1.x liefert die
# CONNACK-Codes aus MQTT 3.1.1 (1-5), paho 2.x fuer dieselben Faelle die
# Ursachencodes aus MQTT 5 (132-136) - je nach installierter Fassung kommt
# also die eine ODER die andere Zahl an (Regeln/07). Nachgetragen 17.09.2026.
MQTT_ANMELDUNG_TEXT = {
    1: "der Broker lehnt die Protokollfassung ab",
    2: "der Broker lehnt die Kennung des Clients ab",
    3: "der Broker ist nicht verfuegbar",
    4: "Benutzer oder Kennwort des Brokers sind falsch (System -> MQTT Gateway)",
    5: "nicht berechtigt - Benutzer und Kennwort des Brokers pruefen (System -> MQTT Gateway)",
}
for _alt, _neu in ((1, 132), (2, 133), (3, 136), (4, 134), (5, 135)):
    MQTT_ANMELDUNG_TEXT[_neu] = MQTT_ANMELDUNG_TEXT[_alt]


def mqtt_anmeldegrund(rc):
    """'Code 135: nicht berechtigt - ...' aus einer Zahl oder einem ReasonCode."""
    try:
        code = int(getattr(rc, "value", rc))
    except (TypeError, ValueError):
        return "Code %s" % rc
    return "Code %d: %s" % (code, MQTT_ANMELDUNG_TEXT.get(code, "unbekannter Grund"))
import bl_beacon             # noqa: E402

import logging               # noqa: E402
import logging.handlers  # noqa: E402


# ---------------------------------------------------------------------------
# Protokoll
# ---------------------------------------------------------------------------
#
# Nur EIN Schreiber auf die Datei. Bis 1.2.10 leitete daemon/daemon stdout
# nach derselben Datei um, in die Python zusaetzlich einen FileHandler
# schrieb - jede Zeile stand doppelt drin, aus zwei Puffern verschraenkt.
# Seit 1.3.20 ist der eine Schreiber der Dienst selbst (log_datei_einrichten()
# unten); die Startwege leiten nur noch in ble_scanner_ng_start.log um.

LOG_DATEI = os.path.join(gem.LOG_DIR, "ble_scanner_ng.log")


def _log_einrichten():
    # Beim EINBINDEN nach STDERR, nicht nach stdout. Im Dienstbetrieb stellt
    # main() auf die eigene Datei um (log_datei_einrichten(), seit 1.3.20).
    #
    # daemon/daemon und bl_dienst() starten mit ">> $log 2>&1" - im Protokoll
    # landet also beides. Aber stdout ist die Leitung, auf der Werkzeuge ihre
    # Antwort ausgeben: bl_selbsttest.py --json und bl_lesen.py binden dieses
    # Modul ein, und sein Protokoll schob sich dann VOR das JSON. Gemessen am
    # 13.09.2026 am Gerät: die Pruefzeile "Besteht die Python-Seite ihre
    # eigene Pruefung?" stand auf "nicht pruefbar", weil json_decode an zwei
    # vorangestellten INFO-Zeilen scheiterte. Eine Zeile behebt die ganze
    # Klasse.
    handlers = [logging.StreamHandler(sys.stderr)]
    if os.environ.get("BLE_LOGDATEI") == "1":
        try:
            os.makedirs(gem.LOG_DIR, exist_ok=True)
            # WatchedFileHandler, NICHT FileHandler.
            # Am Geraet gemessen (06.09.2026, LoxBerry 4.0.0.15): log/plugins liegt auf
            # einer Ramdisk (/dev/zram0). Wird sie geleert, ist die Protokolldatei fort -
            # und ein FileHandler, der sie beim Start EINMAL geoeffnet hat, schreibt bis
            # zum naechsten Neustart in einen geloeschten Inode. Sichtbar wird davon
            # nichts. Der WatchedFileHandler prueft bei jeder Zeile Geraetenummer und
            # Inode und oeffnet noetigenfalls neu; er steht in der Standardbibliothek.
            # Aufgefallen am Heimkino-Dienst, der sieben Stunden ohne Protokoll lief.
            handlers.append(logging.handlers.WatchedFileHandler(LOG_DATEI))
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


class GleicheMeldungBremse(logging.Filter):
    """Gleiche Meldung ab WARNING hoechstens einmal je Stunde (seit 1.3.20).

    Regeln/03 ("Wer ein Protokoll anzeigt, muss es auch schreiben - gebremst"):
    bei einem BlueZ-Ausfall schrieben Sicherungsabfrage, Wiederverbindung und
    verbinden_mit_geduld() ungebremst je 30 s zwei ERROR-Zeilen, rund 5 700 am
    Tag auf der Ramdisk (Pruefung 29.09.2026, C13). Jetzt steht die erste
    Zeile da; Wiederholungen derselben Meldung werden gezaehlt und beim
    naechsten Durchlassen als Zusatz genannt. Fehlt die Protokolldatei
    (Ramdisk geleert, Logwartung), setzt sich die Bremse zurueck - sonst
    unterdrueckte sie die erste Zeile der neuen Datei.
    """

    SPERRE = 3600

    def __init__(self, datei):
        super().__init__()
        self.datei = datei
        self.zuletzt = {}      # Meldung -> (Zeitpunkt, unterdrueckt)

    def filter(self, satz):
        if satz.levelno < logging.WARNING:
            return True
        if self.datei and not os.path.exists(self.datei):
            self.zuletzt.clear()
        try:
            text = satz.getMessage()
        except Exception:  # noqa: BLE001
            return True
        jetzt = time.monotonic()
        vorher = self.zuletzt.get(text)
        if vorher is not None and jetzt - vorher[0] < self.SPERRE:
            self.zuletzt[text] = (vorher[0], vorher[1] + 1)
            return False
        if vorher is not None and vorher[1]:
            satz.msg = "%s (in der Stunde davor %d-mal wiederholt, nicht geschrieben)" % (
                text, vorher[1])
            satz.args = None
        self.zuletzt[text] = (jetzt, 0)
        return True


def log_datei_einrichten():
    """Im Dienstbetrieb schreibt der Dienst sein Protokoll SELBST (seit 1.3.20).

    Bis 1.3.19 schrieb er nur nach stderr, und die vier Startwege leiteten mit
    ">> ble_scanner_ng.log" in die Datei um. Die Kappung tauscht die Datei per
    os.replace aus - danach schrieb der Dienst in einen geloeschten Inode, und
    jede Zeile ging verloren (in WSL gemessen, Pruefung 29.09.2026, C5: fd 1
    und 2 auf "(deleted)"). Das ist die dritte Protokollart aus Regeln/03, bei
    der ein WatchedFileHandler NICHT hilft, solange ihn nur eine
    Umgebungsvariable einschaltet, die kein Startweg setzt.

    Jetzt, im installierten Zustand: nur der WatchedFileHandler auf die eigene
    Datei, kein zweiter Kanal (Regeln/03, "Genau ein Prozess schreibt in die
    Logdatei"). Die Startwege leiten nur noch in ble_scanner_ng_start.log um;
    dort steht, was vor diesem Aufruf scheitert. Nicht installiert (Pruefstand,
    ausgepacktes Archiv) bleibt es bei stderr, ausser BLE_LOGDATEI=1.
    Gerufen wird das nur in main() - bl_selbsttest.py und bl_lesen.py binden
    dieses Modul ein und duerfen nicht in das Dienstprotokoll schreiben.
    """
    wurzel = logging.getLogger()
    if gem.INSTALLIERT or os.environ.get("BLE_LOGDATEI") == "1":
        try:
            os.makedirs(gem.LOG_DIR, exist_ok=True)
            datei = logging.handlers.WatchedFileHandler(LOG_DATEI)
            datei.setFormatter(logging.Formatter(
                "%(asctime)s %(levelname)-7s %(message)s", "%Y-%m-%d %H:%M:%S"))
            for h in list(wurzel.handlers):
                wurzel.removeHandler(h)
            wurzel.addHandler(datei)
        except OSError as fehler:
            log.warning("Protokolldatei %s nicht zu oeffnen (%s) - es bleibt bei stderr.",
                        LOG_DATEI, fehler)
    log.addFilter(GleicheMeldungBremse(LOG_DATEI))


# ---------------------------------------------------------------------------
# MQTT
# ---------------------------------------------------------------------------

def mqtt_zugangsdaten():
    """Zugangsdaten des MQTT-Gateways aus general.json lesen.
    Gross- und Kleinschreibung ist dort uneinheitlich - beide Varianten."""
    # Ohne Wurzel (ausgepacktes Archiv) gibt es keinen Broker der Anlage. Bis
    # 1.3.18 wurde der Pfad dann relativ zum Arbeitsverzeichnis gebildet.
    if not gem.HOME_DIR:
        log.warning("Keine LoxBerry-Wurzel - MQTT nicht möglich")
        return None
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


# ---------------------------------------------------------------------------
# Zurueckbehaltene Themen am Broker loeschen - UND NACHLESEN (seit 1.3.19)
# ---------------------------------------------------------------------------
#
# Drei Aufrufer: der Dienst raeumt einmal die Altwerte aus Vorfassungen ab
# (server/ok und server/adapter_ok gingen bis 1.3.18 retained, bis 1.3.11
# sogar jedes Thema), er raeumt nach einem Praefixwechsel das alte Praefix
# ab, und uninstall/uninstall ruft "ble_scanner_ng.py --mqtt-leeren".
#
# Geloescht wird mit leerer Nutzlast und Retain (QoS 1), und hinterher wird
# NACHGELESEN: ein neues Abonnement bekommt alles, was noch behalten ist. Erst
# dann gilt die Sache als erledigt - der Rueckgabewert von publish() sagt nur,
# dass etwas abging (Regeln/07, Beschattungswaechter 0.9.19 und BatterieBMS
# 0.9.22: Merker nach dem Senden, Altwert stand weiter im Broker).
#
# Beide Abonnements gelten erst mit ihrem SUBACK. Ein Broker, dessen ACL das
# Lesen verweigert, antwortet mit 0x80 und schickt danach nichts; ungeprueft
# hiesse das "nichts behalten" (Muster 11 der Nachlese). CONNACK ungleich 0
# heisst ebenso "nicht zu fragen", nie "nichts belegt". Bauart:
# broker_leeren() in APC-UPS 1.2.13 (apc_common.py).
#
# auswahl(rest) entscheidet je Thema (rest = Thema ohne "<praefix>/").
# nach_loeschen(themen) laeuft unmittelbar nach den Loeschungen, vor dem
# Nachlesen - dort geht der gueltige Wert hinterher.
#
# Rueckgabe {"rc", "geleert", "rest", "grund"}: rc 0 = nichts (mehr)
# behalten, 1 = nach dem Loeschen stand noch etwas, 2 = nicht zu fragen.
#
# Den Grund einer abgewiesenen Anmeldung nennt seit 1.3.20 mqtt_anmeldegrund()
# oben - EINE Tabelle fuer 1-5 (paho 1.x) und 132-136 (paho 2.x). Bis 1.3.19
# stand hier eine zweite, die 134 und 135 nicht kannte: unter paho 2.x meldete
# die Deinstallation bei falschen Zugangsdaten "unbekannter Grund" (Pruefung
# 29.09.2026, MQTT 7).

def broker_leeren(praefix, auswahl, warten=1.5, nach_loeschen=None):
    erg = {"rc": 2, "geleert": [], "rest": [], "grund": ""}
    praefix = str(praefix or "").strip("/")
    if not praefix or "#" in praefix or "+" in praefix:
        erg["grund"] = "das Themenpraefix '{0}' taugt nicht fuer ein Abonnement".format(praefix)
        return erg
    try:
        import paho.mqtt.client as mqtt
    except ImportError:
        erg["grund"] = "das Paket paho-mqtt fehlt"
        return erg
    z = mqtt_zugangsdaten()
    if not z:
        erg["grund"] = "kein MQTT-Broker in general.json"
        return erg
    wo = "{0}:{1}".format(z["host"], z["port"])
    gesehen = set()
    angemeldet = threading.Event()
    code = {"wert": None}
    subacks = {}

    def bei_verbindung(_k, _d, _f, *rest):
        # paho 1.x und VERSION1: rc als Zahl; VERSION2: ReasonCode mit .value
        try:
            code["wert"] = int(getattr(rest[0], "value", rest[0]) or 0) if rest else 0
        except (TypeError, ValueError):
            code["wert"] = 0
        angemeldet.set()

    def bei_nachricht(_k, _d, n):
        # Nur BEHALTENES mit Inhalt: ein live gesendeter Wert ist keine
        # Altlast, und ein leeres Thema ist schon geloescht.
        if not (n.retain and n.payload):
            return
        thema = str(n.topic)
        if thema.startswith(praefix + "/") and auswahl(thema[len(praefix) + 1:]):
            gesehen.add(thema)

    def bei_abo(_k, _d, mid, codes, *_rest):
        werte = []
        try:
            for c in (codes or ()):
                werte.append(int(getattr(c, "value", c)))
        except (TypeError, ValueError):
            werte = [0x80]
        subacks[mid] = werte or [0x80]

    def abonnieren():
        """praefix/# abonnieren und den SUBACK abwarten. "" = bestaetigt."""
        erg_sub = k.subscribe(praefix + "/#")
        try:
            rc_sub, mid = int(erg_sub[0]), erg_sub[1]
        except (TypeError, ValueError, IndexError):
            return "das Abonnement liess sich nicht absenden ({0!r})".format(erg_sub)
        if rc_sub != 0:
            return "das Abonnement liess sich nicht absenden (rc {0})".format(rc_sub)
        ende = time.time() + 10
        while mid not in subacks and time.time() < ende:
            time.sleep(0.05)
        if mid not in subacks:
            return "der Broker {0} hat das Abonnement nicht bestaetigt (kein SUBACK)".format(wo)
        schlecht = [w for w in subacks[mid] if w >= 0x80]
        if schlecht:
            return ("der Broker {0} verweigert das Lesen von '{1}/#' (SUBACK 0x{2:02X})"
                    .format(wo, praefix, schlecht[0]))
        return ""

    name = "ble-scanner-ng-leeren-{0}".format(os.getpid())
    k = None
    for art in ("VERSION2", "VERSION1"):
        api = getattr(getattr(mqtt, "CallbackAPIVersion", None), art, None)
        if api is None:
            continue
        try:
            k = mqtt.Client(api, client_id=name)
            break
        except (AttributeError, TypeError, ValueError):
            k = None
    if k is None:
        k = mqtt.Client(client_id=name)          # paho-mqtt 1.x
    k.on_connect = bei_verbindung
    k.on_message = bei_nachricht
    k.on_subscribe = bei_abo
    if z.get("user"):
        k.username_pw_set(str(z["user"]), str(z.get("pass") or "") or None)
    try:
        k.connect(z["host"], z["port"], 30)
    except Exception as fehler:  # noqa: BLE001
        erg["grund"] = "der Broker {0} ist nicht erreichbar ({1}: {2})".format(
            wo, type(fehler).__name__, fehler)
        return erg
    k.loop_start()
    try:
        if not angemeldet.wait(10):
            erg["grund"] = "der Broker {0} hat auf die Verbindung nicht geantwortet".format(wo)
            return erg
        if code["wert"]:
            erg["grund"] = "der Broker {0} hat die Anmeldung abgewiesen (CONNACK {1})".format(
                wo, mqtt_anmeldegrund(code["wert"]))
            return erg
        grund = abonnieren()
        if grund:
            erg["grund"] = grund
            return erg
        time.sleep(warten)
        k.unsubscribe(praefix + "/#")
        zu_leeren = sorted(gesehen)
        for thema in zu_leeren:
            info = k.publish(thema, b"", qos=1, retain=True)
            try:
                info.wait_for_publish(5)
            except TypeError:                    # paho 1.x vor 1.6 kennt kein timeout
                info.wait_for_publish()
        erg["geleert"] = zu_leeren
        if nach_loeschen is not None and zu_leeren:
            try:
                nach_loeschen(zu_leeren)
            except Exception:  # noqa: BLE001
                pass
        gesehen.clear()
        grund = abonnieren()
        if grund:
            erg["grund"] = "Nachlesen nicht moeglich - " + grund
            return erg
        time.sleep(warten)
        erg["rest"] = sorted(gesehen)
        erg["rc"] = 1 if erg["rest"] else 0
    except Exception as fehler:  # noqa: BLE001
        erg["rc"] = 2
        erg["grund"] = "das Loeschen am Broker {0} scheiterte ({1}: {2})".format(
            wo, type(fehler).__name__, fehler)
    finally:
        try:
            k.disconnect()
        except Exception:  # noqa: BLE001
            pass
        k.loop_stop()
    return erg


def mqtt_leeren():
    """Fuer uninstall/uninstall: die zurueckbehaltenen Themen dieser Linie am
    Broker abraeumen und nachlesen (seit 1.3.19).

    Entschieden am 18.09.2026 (Regeln/07): ein retained Letzter Wille ist nur
    erlaubt, wenn die Deinstallation das Thema abraeumt - sonst bliebe die 0
    eines entfernten Plugins fuer immer stehen. Bis 1.3.18 gab uninstall nur
    einen Hinweis aus. Geraeumt wird jedes Thema unter dem Praefix aus der
    Konfiguration, dessen Stamm diese Linie fuehrt (gem.stamm_der_linie),
    samt Altlasten aus Vorfassungen; ein fremdes Thema und die Themen eines
    anderen Scanners bleiben stehen.

    Ausgabe in der Form der Installationsmeldungen; Rueckgabe 0 erledigt,
    1 Reste, 2 nicht moeglich.
    """
    cfg, _tags, _alt = gem.konfiguration_lesen()
    praefix = (cfg.get("themenpraefix") or "blescanner").strip("/") or "blescanner"
    scanner = (cfg.get("scanner_name") or "").strip() or gem.rechnername()
    # SEIT 1.3.20 auch das zuletzt benutzte Praefix aus gem.PRAEFIX_DATEI,
    # wenn es abweicht (Pruefung 29.09.2026, MQTT 8). Das alte Praefix raeumte
    # bis dahin nur der LAUFENDE Dienst ab; wurde es bei angehaltenem Dienst
    # geaendert und das Plugin danach deinstalliert, blieben dessen retained
    # Themen - samt server/online=0 - fuer immer stehen.
    praefixe = [praefix]
    try:
        with open(gem.PRAEFIX_DATEI, "r", encoding="utf-8") as fh:
            alt = fh.read().strip().strip("/")
    except OSError:
        alt = ""
    if alt and alt != praefix:
        praefixe.append(alt)
    rc_gesamt = 0
    for p in praefixe:
        erg = broker_leeren(p, lambda rest: gem.stamm_der_linie(rest, scanner) != "")
        if erg["rc"] == 2:
            print("<INFO> MQTT: zurueckbehaltene Themen unter {0}/ nicht geleert - {1}. "
                  "Von Hand: mosquitto_pub -r -n -t <thema>".format(p, erg["grund"]))
            rc_gesamt = max(rc_gesamt, 2)
            continue
        if erg["rest"]:
            print("<WARNING> MQTT: {0} zurueckbehaltene Themen stehen nach dem Loeschen "
                  "noch im Broker: {1}".format(len(erg["rest"]), ", ".join(erg["rest"])))
            rc_gesamt = max(rc_gesamt, 1)
            continue
        if erg["geleert"]:
            print("<OK> MQTT: {0} zurueckbehaltene Themen unter {1}/ geloescht und "
                  "nachgelesen.".format(len(erg["geleert"]), p))
        else:
            print("<OK> MQTT: unter {0}/ stand nichts zurueckbehalten (nachgelesen).".format(p))
    return rc_gesamt


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
        # Seit 1.3.19: nach JEDER gelungenen Anmeldung (auch nach einer
        # Neuverbindung durch paho) - der Dienst stoesst dort das Abraeumen
        # von Altwerten und altem Praefix an. Laeuft in einem eigenen Faden.
        self.nach_verbindung = None

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
        # Die Fassung wird abgetastet, nicht angenommen: paho-mqtt 2.x schreibt
        # bei VERSION1 eine DeprecationWarning in JEDES Protokoll (am Geraet an
        # 2.1.0 gemessen, 06.09.2026), paho 1.x kennt die Aufzaehlung gar nicht.
        try:
            self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
        except (AttributeError, TypeError):
            try:
                self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1)
            except (AttributeError, TypeError):
                self.client = mqtt.Client()      # paho-mqtt 1.x
        if zugang["user"]:
            self.client.username_pw_set(zugang["user"], zugang["pass"] or "")
        # Das Testament traegt denselben Retain-Stand wie das Thema selbst -
        # server/online ist der Letzte Wille (Tabelle: retained). Genau deshalb
        # ist es NICHT das Lebenszeichen: stirbt der Prozess hart, setzt der
        # Broker die 0 von selbst. Das Lebenszeichen ist server/ts und geht
        # fluechtig. Die drei Bedingungen aus Regeln/07 (18.09.2026): (a)
        # online=1 in bei_verbindung() - bei JEDER Anmeldung, auch nach einer
        # Neuverbindung durch paho; und ein Praefixwechsel baut seit 1.3.19
        # eine neue Verbindung mit neuem Willen auf. (b) beide retained. (c)
        # uninstall/uninstall ruft "ble_scanner_ng.py --mqtt-leeren".
        self.client.will_set(self.praefix + "/server/online", "0",
                             retain=gem.retain_fuer("server/online", "0"))

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
                if self.nach_verbindung:
                    try:
                        self.nach_verbindung()
                    except Exception as fehler:       # noqa: BLE001
                        log.warning("MQTT-Aufraeumen nicht angestossen: %s", fehler)
            else:
                # Falsche Zugangsdaten behebt kein Warten, deshalb wird der
                # Grund benannt - fuer paho 1.x (4, 5) wie 2.x (134, 135).
                self.verbunden = False
                log.error("MQTT-Anmeldung abgelehnt (%s).", mqtt_anmeldegrund(rc))

        def bei_trennung(_c, _u, *rest):
            # paho ruft hier VERSCHIEDEN, am Geraet an 2.1.0 gemessen:
            # VERSION1 mit (rc), VERSION2 mit (flags, rc, properties). Wer
            # blind das dritte Argument als Code liest, bekommt unter
            # VERSION2 die DisconnectFlags - und meldete jeden sauberen
            # Abschied als Abriss.
            rc = rest[1] if len(rest) >= 3 else (rest[0] if rest else 0)
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

    def senden(self, unterthema, wert, retain=None):
        """Ein Thema veroeffentlichen.

        retain=None heisst: die Tabelle in bl_common entscheidet (Hausstandard
        vom 03.09.2026). Bis 1.3.11 stand hier retain=True als Vorgabe, und
        _senden() hat nie etwas anderes mitgegeben - damit ging ALLES
        zurueckbehalten hinaus, auch das Lebenszeichen server/ts.
        """
        if not self.client:
            return False
        if retain is None:
            retain = gem.retain_fuer(unterthema, wert)
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

        Seit 1.3.20 wird der Rueckgabewert von publish() ausgewertet (Pruefung
        29.09.2026, MQTT 1): ohne Verbindung liefert paho einen Code ungleich
        0, und die Loeschung ist verloren. Bis 1.3.19 hiess das trotzdem
        True, und das Thema fiel aus der Liste der zu loeschenden.
        """
        if not self.client:
            return False
        try:
            erg = self.client.publish(self.praefix + "/" + unterthema, "", qos=0, retain=True)
        except Exception:  # noqa: BLE001
            return False
        return getattr(erg, "rc", 0) == 0

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
    # Ohne Wurzel keine general.json - bis 1.3.18 wurde der Pfad dann
    # relativ zum Arbeitsverzeichnis gebildet (Fall W7).
    if not gem.HOME_DIR:
        return []
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
        self.mqtt.nach_verbindung = self.mqtt_aufraeumen_anstossen
        # Stand des Abraeumens am Broker (seit 1.3.19): altlast_erledigt wird
        # erst nach dem Nachlesen wahr; aufraeumen_offen heisst "stuendlich
        # erneut versuchen" (runde()).
        self.aufraeumen_faden = None
        self.aufraeumen_zeit = 0.0
        self.aufraeumen_offen = False
        self.aufraeumen_nochmal = False
        self.altlast_erledigt = False
        # Themen entfernter/abgehakter Tags beim Start abraeumen (seit 1.3.20).
        self.entfallen_erledigt = False
        # Sendetakt der Zeitstempel (seit 1.3.20): Thema -> monotone Zeit der
        # letzten Sendung, und ob der laufende Durchlauf eine Neumeldung nach
        # einer Neuverbindung ist.
        self.gesendet_um = {}
        self.neumeldung_laeuft = False
        # BlueZ wurde neu gestartet (NameOwnerChanged, seit 1.3.20) - die
        # Stellvertreter zeigen auf den alten Besitzer und muessen neu her.
        self.bluez_neu = threading.Event()
        self.letzte_neuverbindung = 0.0
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
        self.batterie_erledigt = set()    # Kennungen, die HEUTE schon dran waren (1.3.20)
        self.batterie_erledigt_am = ""
        self.raumdaten = {}               # Kennung -> {Scanner -> (zeit, rssi)}
        self.testmodus = {}               # Kennung -> Ablaufzeitpunkt
        self.testwerte = []
        self.kalibrierung = None          # {"kennung":..., "bis":..., "werte":[]}
        self.ereignisse_gesamt = 0
        # Die zuletzt geschriebene Uebersicht. Sie wird bei einer Stoerung
        # WIEDERVERWENDET statt neu gebildet: eine Stoerung darf die zuletzt
        # gemessenen Werte nicht ueberschreiben - sonst meldete ein
        # unerreichbares BlueZ "alle abwesend", und das ist eine Aussage, die
        # niemand gemessen hat.
        self.letzte_uebersicht = []
        self.letzte_personen = {}
        self.stoerung = ""
        # Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026,
        # ab Werk aus): was der WiFi-Scanner zuletzt sagte. ts_empfang ist die
        # monotone Zeit, zu der ein NICHT zurueckbehaltenes status/ts ankam.
        self.wlan_sperre = threading.Lock()
        self.wlan = {"ts_empfang": 0.0, "ts": 0, "ok": "", "intervall": 0, "personen": {}}
        self.wlan_lage = {"an": 0}

    # -- Aufraeumen am Broker (seit 1.3.19) ---------------------------------

    def _mqtt_neu(self):
        """Eine neue MQTT-Huelle fuer das aktuelle Praefix - samt Letztem
        Willen auf dessen server/online (Mqtt.start)."""
        m = Mqtt(self.praefix)
        m.nach_verbindung = self.mqtt_aufraeumen_anstossen
        if self._zahl("raum", 0) == 1 or self.cfg.get("wlan_kopplung", "0") == "1":
            m.abo_rueckruf = self.abonnieren
        return m

    def mqtt_aufraeumen_anstossen(self):
        """Aus bei_verbindung() im paho-Netzfaden: das Abraeumen laeuft in
        einem eigenen Faden, damit die Anmeldung nicht auf zwei Rueckfragen
        beim Broker wartet."""
        with self.sperre:
            if self.aufraeumen_faden is not None and self.aufraeumen_faden.is_alive():
                # Seit 1.3.20 geht ein Anstoss waehrend eines laufenden
                # Abraeumens nicht verloren: der Faden laeuft danach noch
                # einmal. Das Abraeumen der entfernten Tags (MQTT 1) macht den
                # ersten Lauf laenger, und ein Praefixwechsel kurz nach dem
                # Start blieb sonst liegen (Pruefstand 1.3.19, Fall X2b).
                self.aufraeumen_nochmal = True
                return
            self.aufraeumen_zeit = time.time()
            self.aufraeumen_faden = threading.Thread(
                target=self._aufraeumen_schleife, name="mqtt-aufraeumen", daemon=True)
            self.aufraeumen_faden.start()

    def _aufraeumen_schleife(self):
        while True:
            self.mqtt_aufraeumen()
            with self.sperre:
                if not self.aufraeumen_nochmal:
                    return
                self.aufraeumen_nochmal = False
                self.aufraeumen_zeit = time.time()

    def _altlast_kennung(self, praefix, scanner):
        """Inhalt des Merkers. Traegt Kennung, Praefix, Scanner und die
        Stammliste - ein Merker einer anderen Fassung, eines anderen Praefixes
        oder mit anderer Liste zaehlt nicht, dann wird neu nachgesehen."""
        staemme = sorted(s for s, r in gem.RETAIN.items() if not r)
        return "ble-scanner-ng-altlast-1|{0}|{1}|{2}".format(
            praefix, scanner, ",".join(staemme))

    @staticmethod
    def _merker_schreiben(pfad, inhalt):
        try:
            os.makedirs(os.path.dirname(pfad), exist_ok=True)
            temp = pfad + ".neu"
            with open(temp, "w", encoding="utf-8") as fh:
                fh.write(inhalt + "\n")
            os.replace(temp, pfad)
            return True
        except OSError as fehler:
            log.warning("Merker %s nicht schreibbar: %s", pfad, fehler)
            return False

    @staticmethod
    def _merker_lesen(pfad):
        try:
            with open(pfad, "r", encoding="utf-8") as fh:
                return fh.read().strip()
        except OSError:
            return ""

    def mqtt_aufraeumen(self):
        """Zurueckbehaltenes, das nicht (mehr) stehen darf, einmal abraeumen -
        mit Nachlesen beim Broker, Merker erst danach.

        1. Altes Praefix. Bis 1.3.18 blieben nach einem Praefixwechsel alle
           retained Themen des alten Praefixes fuer immer stehen, samt
           server/online=0 aus dem Letzten Willen (Pruefung-BLE-Scanner-1.3.19,
           Faelle X1 und X2). Das zuletzt benutzte Praefix steht in
           gem.PRAEFIX_DATEI; es wird erst nach bestaetigtem Abraeumen
           ueberschrieben.
        2. Altwerte unter dem aktuellen Praefix: alles, was nach RETAIN
           fluechtig ist, aber aus einer Vorfassung noch behalten im Broker
           steht - server/ok und server/adapter_ok bis 1.3.18, jedes Thema
           bis 1.3.11 (Faelle R5 bis R10). Unmittelbar nach der Loeschung
           geht der gueltige Wert fluechtig hinterher.
        Scheitert eine Rueckfrage (kein Broker, CONNACK != 0, SUBACK 0x80),
        bleibt der Merker aus, und runde() versucht es stuendlich erneut.
        """
        praefix = self.praefix
        scanner = self.scanner
        offen = False

        alt = self._merker_lesen(gem.PRAEFIX_DATEI)
        if alt and alt != praefix:
            erg = broker_leeren(alt, lambda rest: gem.stamm_der_linie(rest, scanner) != "")
            if erg["rc"] == 0:
                log.info("MQTT: altes Themenpraefix %s abgeraeumt (%d Thema/Themen, "
                         "nachgelesen).", alt, len(erg["geleert"]))
                self._merker_schreiben(gem.PRAEFIX_DATEI, praefix)
            else:
                offen = True
                log.warning("MQTT: altes Themenpraefix %s nicht abgeraeumt (%s) - "
                            "neuer Versuch in einer Stunde.", alt,
                            erg["grund"] or "%d stehen noch im Broker" % len(erg["rest"]))
        elif alt != praefix:
            self._merker_schreiben(gem.PRAEFIX_DATEI, praefix)

        kennung = self._altlast_kennung(praefix, scanner)
        if self._merker_lesen(gem.ALTLAST_MERKER) == kennung:
            self.altlast_erledigt = True

        # 3. Zweige, die nicht (mehr) gesendet werden - NEU IN 1.3.20.
        #    Bis 1.3.19 raeumte nur konfiguration_neu_einlesen() die Themen
        #    entfernter oder abgehakter Tags ab, also nur bei einer Aenderung
        #    der Datei zur Laufzeit. Die Oberflaeche startet den Dienst beim
        #    Speichern aber neu - der neue Prozess kannte die alten Zweige nicht,
        #    und ein entfernter Tag stand fuer immer mit present=1 im Broker
        #    (Pruefung 29.09.2026, MQTT 1, Fall E: 5 Themen). Jetzt wird beim
        #    Start jedes Thema unter <praefix>/ abgeraeumt, das zu dieser Linie
        #    gehoert (gem.stamm_der_linie), dessen Zweig aber weder ein aktiver
        #    Tag noch server, summary, person/<aktive Person> oder
        #    scanner/<dieser Scanner>/<aktiver Tag> ist. Die Themen eines
        #    anderen Scanners und fremde Themen bleiben stehen.
        #    Beides - Altwerte (2.) und entfallene Zweige (3.) - geht in EINEN
        #    Durchgang mit Nachlesen (Bauart broker_leeren()); die
        #    Wiederholung nach der Loeschung betrifft nur Themen mit
        #    gueltigem Wert, und entfallene Zweige haben keinen.
        with self.sperre:
            zweige = {self._zweig(t) for t in self.tags if t.get("aktiv") == "1"}
            personen = set()
            for t in self.tags:
                pn = (t.get("opt") or {}).get("person", "").strip()
                if t.get("aktiv") == "1" and pn:
                    personen.add(gem.thema_saeubern(pn))
            # Personen der WLAN-Kopplung (Verbesserungsbau 30.09.2026): ihre
            # Themen anwesend_gesamt/quelle bleiben nur, solange die Kopplung
            # an ist und die Zuordnung sie fuehrt.
            gekoppelt = set()
            if self.cfg.get("wlan_kopplung", "0") == "1":
                gekoppelt = {pn for _wn, pn in
                             gem.wlan_zuordnung_lesen(self.cfg.get("wlan_zuordnung", ""))}
        altlast = not self.altlast_erledigt
        entfall = not self.entfallen_erledigt

        def fluechtig(rest):
            stamm = gem.stamm_der_linie(rest, scanner)
            return stamm != "" and not gem.RETAIN.get(stamm, False)

        def entfallen(rest):
            if gem.stamm_der_linie(rest, scanner) == "":
                return False
            teile = rest.split("/")
            if teile[0] in ("server", "summary"):
                return False
            if teile[0] == "person":
                if teile[-1] in ("anwesend_gesamt", "quelle"):
                    return not (len(teile) == 3 and teile[1] in gekoppelt)
                return not (len(teile) > 1 and teile[1] in personen)
            if teile[0] == "scanner":
                return not (len(teile) > 2 and teile[2] in zweige)
            return teile[0] not in zweige

        def auswahl(rest):
            return (altlast and fluechtig(rest)) or (entfall and entfallen(rest))

        def nachsenden(themen):
            for thema in themen:
                rest = thema[len(praefix) + 1:]
                wert = self.letzter_stand.get(rest)
                if wert not in (None, ""):
                    self.mqtt.senden(rest, wert)

        if altlast or entfall:
            erg = broker_leeren(praefix, auswahl, nach_loeschen=nachsenden)
            if erg["rc"] == 2:
                offen = True
                log.warning("MQTT: Altwerte und Themen entfernter Tags im Broker nicht "
                            "geprueft (%s) - neuer Versuch in einer Stunde.", erg["grund"])
            elif erg["rest"]:
                offen = True
                log.warning("MQTT: %d Thema/Themen stehen nach dem Loeschen noch im "
                            "Broker (%s) - neuer Versuch in einer Stunde.",
                            len(erg["rest"]), ", ".join(erg["rest"]))
            else:
                if altlast and self._merker_schreiben(gem.ALTLAST_MERKER, kennung):
                    self.altlast_erledigt = True
                if entfall:
                    self.entfallen_erledigt = True
                if erg["geleert"]:
                    log.info("MQTT: %d zurueckbehaltene Altwert(e) bzw. Thema/Themen "
                             "entfernter oder abgehakter Tags geloescht und nachgelesen: %s",
                             len(erg["geleert"]), ", ".join(erg["geleert"]))
        self.aufraeumen_offen = offen

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
                # Der Absender wird MITGEGEBEN: MiBeacon traegt die MAC des
                # messenden Geraets im Paket, und nur wenn beide gleich sind,
                # gehoert der Wert diesem Tag.
                gedeutet = bl_beacon.deuten(werte.get("mdata"),
                                            werte.get("sdata"), mac)
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

    def _senden(self, thema, wert, erzwingen=False, abstand=0, wechsel=False):
        """Ein Thema senden, wenn es sich geaendert hat oder erzwungen ist.

        Zwei Zusaetze seit 1.3.20:

        * Entscheidung 5 des Hausherrn (29.09.2026): ein retained Zustand ohne
          Aussage geht als "-" hinaus - nie als leere Nutzlast (die loeschte
          das Thema im Broker, und das Gateway reichte einen leeren Wert
          weiter) und nie als stehenbleibender Altwert. Bis 1.3.19 ging
          <T>/raum nach dem Verstummen eines Tags leer und fluechtig hinaus,
          und der alte Raumname blieb retained stehen (Pruefung 29.09.2026,
          MQTT 2). Das gilt fuer jeden retained Stamm, nicht nur raum und
          name - server/version kann seit C12 ebenfalls leer sein.
        * Sendetakt: mit abstand > 0 geht das Thema hoechstens alle abstand
          Sekunden hinaus - ausser bei einem Zustandswechsel (wechsel) und bei
          der Neumeldung nach einer Neuverbindung. Bis 1.3.19 gingen
          server/ts, server/letzte_sichtung und <T>/last_seen_ts in JEDEM
          Durchlauf hinaus: 39 von 57 Nachrichten in 60 s bei einem Tag
          (Pruefung 29.09.2026, MQTT 4, Fall L).
        """
        wert = "" if wert is None else str(wert)
        if wert == "" and gem.retain_fuer(thema, "-"):
            wert = "-"
        geaendert = self.letzter_stand.get(thema) != wert
        if abstand > 0 and not self.neumeldung_laeuft and not wechsel:
            zuletzt = self.gesendet_um.get(thema)
            if zuletzt is not None and time.monotonic() - zuletzt < abstand:
                return False
        if not erzwingen and not geaendert and not wechsel:
            return False
        self.letzter_stand[thema] = wert
        self.veroeffentlicht.add(thema)
        self.gesendet_um[thema] = time.monotonic()
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

        # Seit 1.3.20 bleibt ein Thema, dessen Loeschung nicht abging, in der
        # Liste (Mqtt.loeschen() wertet publish() aus) - bis 1.3.19 fiel es
        # auch dann heraus, und niemand versuchte es erneut.
        entfernt = 0
        gescheitert = set()
        for thema in sorted(self.veroeffentlicht):
            if trifft(thema):
                if self.mqtt.loeschen(thema):
                    entfernt += 1
                else:
                    gescheitert.add(thema)
                self.letzter_stand.pop(thema, None)
        self.veroeffentlicht = {t for t in self.veroeffentlicht
                                if not trifft(t) or t in gescheitert}
        if entfernt:
            log.info("%d zurückbehaltene Themen entfernter Tags gelöscht (%s)",
                     entfernt, ", ".join(sorted(zweige)))
        if gescheitert:
            # mqtt_aufraeumen() raeumt sie mit Nachlesen ab - nach der naechsten
            # Anmeldung oder beim stuendlichen Nachversuch (runde()).
            self.entfallen_erledigt = False
            self.aufraeumen_offen = True
            log.warning("%d Thema/Themen entfernter Tags liessen sich nicht loeschen "
                        "(keine MQTT-Verbindung?): %s", len(gescheitert),
                        ", ".join(sorted(gescheitert)))
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
        wechsel_runde = False     # hat in diesem Durchlauf ein Tag gewechselt?

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
            if geaendert:
                wechsel_runde = True
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
            # Seit 1.3.20 beim Wechsel der Anwesenheit sofort, sonst hoechstens
            # alle 60 s (Pruefung 29.09.2026, MQTT 4).
            self._senden("{0}/last_seen_ts".format(zweig),
                         int(eintrag["zeit"]) if (eintrag and eintrag.get("zeit")) else 0,
                         erzwingen, abstand=60, wechsel=geaendert)
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
        # person/<P>/last_seen_ts seit dem Verbesserungsbau vom 30.09.2026 (a1)
        # wie <T>/last_seen_ts: beim Wechsel der Anwesenheit der Person sofort,
        # sonst hoechstens alle 60 s. Bis dahin ging es mit jeder neuen
        # Sichtung hinaus - in jedem Durchlauf, solange jemand da war.
        for name, p in personen.items():
            p_wechsel = self._senden("person/{0}/present".format(name), p["present"], erzwingen)
            self._senden("person/{0}/last_seen_ts".format(name), p["ts"], erzwingen,
                         abstand=60, wechsel=p_wechsel)

        # -- Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026,
        #    ab Werk aus)
        self.wlan_zusammenfuehren(personen, erzwingen)

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
        #    Seit 1.3.20 hoechstens alle 30 s (Regeln/07, Einspeisebremse
        #    0.9.20; Pruefung 29.09.2026, MQTT 4), die letzte Sichtung beim
        #    Wechsel eines Tags sofort, sonst hoechstens alle 60 s.
        self._senden("server/ts", int(jetzt_w), erzwingen=True, abstand=30)
        self._senden("server/ok", 1 if self.adapter_ok else 0, erzwingen)
        self._senden("server/adapter_ok", 1 if self.adapter_ok else 0, erzwingen)
        self._senden("server/letzte_sichtung", int(self.letzte_sichtung_zeit), erzwingen,
                     abstand=60, wechsel=wechsel_runde)
        self._senden("server/version", gem.VERSION, erzwingen)
        self._senden("server/scanner", self.scanner, erzwingen)

        self.letzte_uebersicht = uebersicht
        self.letzte_personen = personen
        self.stoerung = ""
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
            "beaconart": bl_beacon.beschriftung((eintrag or {}).get("beacon")),
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

    # -- Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026) ------

    def abonnieren(self, client):
        """Die Abos nach jeder Anmeldung: Raumzuordnung und/oder WiFi-Scanner.
        Ein gemeinsamer Empfaenger verteilt die Nachrichten."""
        if self._zahl("raum", 0) == 1:
            self.raum_abonnieren(client)
        if self.cfg.get("wlan_kopplung", "0") == "1":
            client.subscribe(gem.WLAN_PRAEFIX + "/#", qos=0)
            log.info("Anwesenheit mit dem WLAN-Scanner: abonniert %s/#", gem.WLAN_PRAEFIX)
        client.on_message = self.nachricht

    def nachricht(self, c, u, nachricht):
        try:
            if self.wlan_nachricht(nachricht):
                return
        except Exception as fehler:      # noqa: BLE001
            log.warning("Nachricht des WLAN-Scanners nicht auswertbar: %s", fehler)
            return
        self.raum_nachricht(c, u, nachricht)

    def wlan_nachricht(self, nachricht):
        """Eine Nachricht unter wifi_ng/ merken. Rueckgabe: war es eine?

        Das Lebenszeichen status/ts und status/ok zaehlen nur, wenn sie NICHT
        zurueckbehalten ankamen: ein Abo bekommt beim Anmelden jeden
        behaltenen Wert und haelt ihn sonst fuer laufenden Verkehr (Regeln/07;
        der WiFi-Scanner sandte status/* bis 3.2.3 retained - ein Altwert im
        Broker saehe sonst wie ein lebender Scanner aus).
        """
        praefix = gem.WLAN_PRAEFIX + "/"
        thema = str(getattr(nachricht, "topic", ""))
        if not thema.startswith(praefix):
            return False
        rest = thema[len(praefix):]
        try:
            wert = bytes(nachricht.payload or b"").decode("utf-8", "replace").strip()
        except (TypeError, ValueError):
            wert = ""
        behalten = bool(getattr(nachricht, "retain", False))
        with self.wlan_sperre:
            if rest == "status/ts":
                if not behalten:
                    self.wlan["ts_empfang"] = time.monotonic()
                    try:
                        self.wlan["ts"] = int(float(wert))
                    except ValueError:
                        self.wlan["ts"] = 0
            elif rest == "status/ok":
                if not behalten:
                    self.wlan["ok"] = wert[:8]
            elif rest == "status/interval":
                try:
                    self.wlan["intervall"] = int(float(wert))
                except ValueError:
                    pass
            elif re.fullmatch(r"[A-Za-z0-9_-]{1,40}", rest):
                if wert == "":
                    self.wlan["personen"].pop(rest, None)
                elif rest in self.wlan["personen"] or len(self.wlan["personen"]) < 200:
                    self.wlan["personen"][rest] = wert[:8]
        return True

    def wlan_zusammenfuehren(self, personen, erzwingen):
        """person/<P>/anwesend_gesamt und person/<P>/quelle je Zuordnung.

        Nur bei eingeschalteter Kopplung (ab Werk aus). Schweigt der
        WiFi-Scanner (kein frisches Lebenszeichen, status/ok nicht 1, MQTT
        aus), hat er keine Aussage - dann zaehlt nur BLE, und die Lage steht im
        Abbild fuer den Reiter Test.
        """
        if self.cfg.get("wlan_kopplung", "0") != "1":
            self.wlan_lage = {"an": 0}
            return
        zuordnung = gem.wlan_zuordnung_lesen(self.cfg.get("wlan_zuordnung", ""))
        with self.wlan_sperre:
            empfang = self.wlan["ts_empfang"]
            ok = self.wlan["ok"]
            takt = self.wlan["intervall"]
            werte = dict(self.wlan["personen"])
        frist = gem.wlan_frist(takt)
        alter = int(time.monotonic() - empfang) if empfang else -1
        if self.cfg.get("mqtt", "1") != "1":
            grund = "mqtt"
        elif not empfang:
            grund = "nie"
        elif alter > frist:
            grund = "alt"
        elif ok != "1":
            grund = "ok"
        else:
            grund = ""
        frisch = grund == ""
        if frisch != bool(self.wlan_lage.get("frisch")) or self.wlan_lage.get("an") != 1:
            if frisch:
                log.info("Anwesenheit mit dem WLAN-Scanner: er meldet sich - beide Quellen zaehlen.")
            else:
                log.warning("Anwesenheit mit dem WLAN-Scanner: er schweigt (%s) - es zaehlt "
                            "nur BLE.", {"mqtt": "MQTT ist aus",
                                         "nie": "noch kein Lebenszeichen wifi_ng/status/ts",
                                         "alt": "Lebenszeichen aelter als %d s" % frist,
                                         "ok": "wifi_ng/status/ok ist nicht 1"}.get(grund, grund))
        lage = {}
        for wlan_name, person in zuordnung:
            ble = personen.get(person)
            ble_wert = int(ble["present"]) if ble is not None else None
            roh = werte.get(wlan_name) if frisch else None
            wlan_wert = 1 if roh == "1" else (0 if roh == "0" else None)
            gesamt, quelle = gem.anwesenheit_gesamt(ble_wert, wlan_wert)
            self._senden("person/{0}/anwesend_gesamt".format(person), gesamt, erzwingen)
            self._senden("person/{0}/quelle".format(person), quelle, erzwingen)
            lage[person] = {"wlan_name": wlan_name, "ble": ble_wert, "wlan": wlan_wert,
                            "gesamt": gesamt, "quelle": quelle}
        self.wlan_lage = {"an": 1, "frisch": 1 if frisch else 0, "grund": grund,
                          "alter": alter, "frist": frist, "ok": ok, "intervall": takt,
                          "personen": lage}

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
            # 0640 bei JEDEM Schreiben, nicht nur beim Anlegen (seit 1.3.20,
            # Pruefung 29.09.2026, B10): am Geraet stand verlauf.csv auf 0664,
            # und jedes Update trug den Modus weiter - Namen und
            # Ankunftszeiten der Hausbewohner fuer jeden lokalen Benutzer.
            try:
                if (os.stat(gem.VERLAUF_FILE).st_mode & 0o777) != 0o640:
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
            # Rechte vor dem Inhalt, 0640 (seit 1.3.20, B10).
            fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
            os.chmod(temp, 0o640)
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write(kopf + "\n")
                fh.write("\n".join(rest) + ("\n" if rest else ""))
            os.replace(temp, gem.VERLAUF_FILE)
        except OSError:
            pass

    # -- Zustandsdatei ------------------------------------------------------

    def zustand_schreiben(self, uebersicht, anwesend_gesamt, aktive, personen):
        # Der Stoerungstext steht im Abbild, damit die Oberflaeche ihn zeigen
        # kann. Ueber MQTT genuegen server/ok und server/adapter_ok.
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
                    "beaconart": bl_beacon.beschriftung(w.get("beacon")),
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
            "stoerung": self.stoerung,
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
            # Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026):
            # die Lage fuer die Pruefzeile im Reiter Test.
            "wlan": self.wlan_lage,
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

    def stoerung_melden(self, grund):
        """Eine Stoerung sichtbar machen, ohne Werte zu erfinden.

        WARUM ES DAS BRAUCHT: bis 1.3.11 schwieg der Dienst vollstaendig,
        solange er BlueZ nicht erreichte. verbinden_mit_geduld() versuchte es
        alle 30 Sekunden, und die Hauptschleife - und damit auswerten() und
        zustand_schreiben() - wurde nie erreicht. Am 13.09.2026 am Geraet
        gemessen: nach 34 Sekunden Laufzeit gab es KEIN Abbild, und damit
        stand in der Selbstpruefung bei jeder Zeile, die das Abbild braucht,
        ein Strich. Die Oberflaeche konnte "laeuft, ist aber blind" nicht von
        "laeuft nicht" unterscheiden, und am Miniserver kam gar nichts an.

        Gemeldet wird NUR das Kennzeichen, nicht der Wert: eine Stoerung darf
        die zuletzt gemessenen Werte nicht ueberschreiben, sonst hiesse ein
        unerreichbares BlueZ "alle abwesend".
        """
        self.adapter_ok = False
        self.stoerung = str(grund)
        self._senden("server/ok", 0, erzwingen=True)
        self._senden("server/adapter_ok", 0, erzwingen=True)
        self._senden("server/ts", int(time.time()), erzwingen=True, abstand=30)
        aktive = sum(1 for t in self.tags if t.get("aktiv") == "1")
        anwesend = sum(1 for z in self.letzte_uebersicht if z.get("anwesend"))
        self.zustand_schreiben(self.letzte_uebersicht, anwesend, aktive,
                               self.letzte_personen)

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
        # NEU IN 1.3.19 (Muster 5 der Nachlese): ein Auftrag gilt 60 Sekunden.
        # Bis 1.3.18 nahm der Dienst auch einen Stunden alten an - die
        # Oberflaeche reihte ihn ohne laufenden Dienst ein, und der naechste
        # Start fuhr den Testmodus ungefragt hoch (in WSL gemessen,
        # Pruefung-BLE-Scanner-1.3.19, Fall S1). bl_steuern() schreibt den
        # Zeitpunkt seit jeher mit. Aus der Zukunft gelten 300 s Vorlauf.
        try:
            zeit = int(auftrag.get("zeit"))
        except (TypeError, ValueError):
            zeit = None
        jetzt = time.time()
        if zeit is None or jetzt - zeit > 60 or zeit - jetzt > 300:
            log.warning("Auftrag der Oberfläche verworfen (%s, %s) - er ist zu alt "
                        "oder ohne Zeitpunkt.", art or "?",
                        "ohne Zeitpunkt" if zeit is None else "%d s" % int(jetzt - zeit))
            return
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
            self.batterie_erledigt = set()
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

    # -- Neu verbinden (seit 1.3.20) ----------------------------------------
    #
    # BlueZ.verbinden() legt die Stellvertreter fuer Adapter und Eigenschaften
    # einmal an, und dbus-python bindet sie an den EINDEUTIGEN Namen des
    # damaligen Besitzers von org.bluez. Startet bluetoothd neu (systemctl
    # restart bluetooth, bluez-Update, Absturz), bekommt org.bluez einen neuen
    # Besitzer, und dieselben Stellvertreter laufen ins Leere: sucht() lieferte
    # None, suche_starten() und aus_und_an() scheiterten - dauerhaft, bis der
    # Prozess neu startete. Der Wachhund schaltete alle 300 s ohne Wirkung den
    # Adapter aus und an (an einer bluez-Attrappe auf eigenem D-Bus gemessen,
    # Pruefung 29.09.2026, C6). Jetzt: bei jedem BlueZFehlt aus Wachhund und
    # Suchstart zuerst neu verbinden, und im Signalbetrieb auf NameOwnerChanged
    # fuer org.bluez hoeren.

    def bluez_neu_verbinden(self, anlass):
        """Stellvertreter neu anlegen, Adapter einschalten, Suche starten.

        Hoechstens alle 10 s - ein bluetoothd, der gerade erst hochkommt,
        fuehrt den Adapter noch nicht; dann versucht es der naechste
        Durchlauf. Rueckgabe: True, wenn die Suche danach laeuft.
        """
        if self.bluez is None:
            return False
        jetzt = time.monotonic()
        if jetzt - self.letzte_neuverbindung < 10:
            return False
        self.letzte_neuverbindung = jetzt
        try:
            self.bluez.verbinden()
            self.bluez.einschalten()
            self.suchfilter = self.bluez.suche_starten(self._zahl("discovery_rssi", 0))
        except gem.BlueZFehlt as fehler:
            log.error("BlueZ ist nach %s nicht wieder zu erreichen: %s", anlass, fehler)
            return False
        log.info("Verbindung zu BlueZ neu aufgebaut (%s) - die Suche laeuft wieder.", anlass)
        self.wachhund_stufe = 0
        return True

    def _suche_starten(self, anlass):
        """suche_starten() - scheitert es, einmal neu verbinden und erneut."""
        try:
            self.suchfilter = self.bluez.suche_starten(self._zahl("discovery_rssi", 0))
            return True
        except gem.BlueZFehlt as fehler:
            log.error("%s", fehler)
        self.letzte_neuverbindung = 0.0
        return self.bluez_neu_verbinden(anlass)

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

        # Stufe 0 (seit 1.3.20): laesst sich die Frage gar nicht stellen, zeigen
        # die Stellvertreter meist auf einen alten Besitzer von org.bluez. Neu
        # verbinden greift nicht in den Adapter ein - es schaltet ihn nur ein,
        # falls er aus ist, und startet die Suche, falls sie steht.
        sucht = self.bluez.sucht()
        if sucht is None:
            self.bluez_neu_verbinden("die Suche liess sich nicht abfragen")
            return

        # Stufe 1: hat ein anderes Programm die Suche beendet?
        if sucht is False:
            log.warning("Die Suche steht - sie wird neu gestartet. (Ein anderes "
                        "Programm kann sie beendet haben; BlueZ laesst nur eine "
                        "Suche gleichzeitig zu.)")
            self._suche_starten("die Suche stand")
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
            self.bluez.suche_beenden()
            time.sleep(1.0)
            self._suche_starten("die Suche liess sich nicht neu starten")
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
            self.letzte_neuverbindung = 0.0
            if self.bluez_neu_verbinden("der Adapter liess sich nicht neu aufsetzen"):
                return
            # BERICHTIGT IN 1.3.20 (Entscheidung 2 vom 29.09.2026, Pruefung C11):
            # bis 1.3.19 stand hier, der Befehl braeuchte "eine sudo-Regel, also
            # eine systemweite Rechteaenderung". Das Plugin HAT eine eigene
            # sudo-Regel (fuer den Helfer "Bluetooth einschalten"), und
            # loxberry darf systemctl ueber lbdefaults ohnehin. Der Dienst ruft
            # den Befehl trotzdem nicht selbst: er startet einen Systemdienst,
            # den auch andere Programme benutzen - das entscheidet der Mensch.
            log.error("Hilft auch das nicht, hilft am Gerät: "
                      "sudo systemctl restart bluetooth. Der Dienst ruft diesen "
                      "Befehl nicht selbst auf: er startet bluetoothd für alle "
                      "Programme neu, und das entscheidet der Anwender.")

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

        # SEIT 1.3.20 JE DURCHLAUF NUR EIN TAG (Pruefung 29.09.2026, C14). Bis
        # 1.3.19 wurden bis zu zehn Tags in einem Zug verbunden, je Tag bis zu
        # 25 s Connect() (D-Bus-Vorgabe) und 12 s Warten - so lange standen
        # Auswertung, Herzschlag und Anwesenheit still. Jetzt kommt je
        # Durchlauf ein Tag dran, mit Zeitgrenze fuer Connect(); die uebrigen
        # folgen in den naechsten Durchlaeufen desselben Tages.
        if self.batterie_erledigt and self.batterie_erledigt_am != heute:
            self.batterie_erledigt = set()
        self.batterie_erledigt_am = heute
        kandidaten = []
        for tag in self.tags:
            if tag.get("aktiv") != "1":
                continue
            if str((tag.get("opt") or {}).get("batt", "")) != "1":
                continue
            if tag["kennung"] in self.batterie_unmoeglich \
                    or tag["kennung"] in self.batterie_erledigt:
                continue
            eintrag = self.eintrag_zum_tag(tag)
            if eintrag and eintrag.get("pfad"):
                kandidaten.append((tag, eintrag["pfad"]))
        # Hoechstens zehn Tags je Tag, wie bisher.
        if not kandidaten or len(self.batterie_erledigt) >= 10:
            self.batterie_zuletzt = heute
            self.batterie_erledigt = set()
            return

        tag, pfad = kandidaten[0]
        self.batterie_erledigt.add(tag["kennung"])
        log.info("Batterielauf: %s (noch %d Tag(s) heute). Der Scan steht dabei kurz "
                 "still.", tag.get("name") or tag["kennung"], len(kandidaten) - 1)
        try:
            self.bluez.suche_beenden()
            time.sleep(0.5)
            prozent, meldung = self.bluez.batterie_lesen(pfad, zeitgrenze=10)
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
            if not self._suche_starten("dem Batterielauf"):
                log.error("Suche nach dem Batterielauf nicht wieder gestartet.")

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
                # Die Stoerung wird gemeldet, BEVOR gewartet wird - sonst
                # erfaehrt niemand, dass der Dienst laeuft und nichts sieht.
                try:
                    self.stoerung_melden(str(fehler))
                except Exception as f2:      # noqa: BLE001
                    log.warning("Störung ließ sich nicht melden: %s", f2)
                for _ in range(30):
                    if not self.laeuft:
                        return False
                    time.sleep(1)
        return False

    def runde(self):
        """Ein Durchlauf: auswerten, melden, aufraeumen. Beide Betriebsarten."""
        self.steuerdatei_lesen()
        self.kalibrierung_pruefen()

        # bluetoothd wurde neu gestartet (NameOwnerChanged, seit 1.3.20).
        # Gelingt die Neuverbindung noch nicht, bleibt die Marke stehen, und
        # der naechste Durchlauf versucht es erneut.
        if self.bluez_neu.is_set():
            if self.bluez_neu_verbinden("einem Neustart von bluetoothd"):
                self.bluez_neu.clear()

        # Nach einer Neuverbindung zum Broker sind dessen retained-Werte
        # womoeglich weg. Dann muss ALLES neu gesendet werden.
        neu_verbunden = self.mqtt.neumeldung.is_set()
        if neu_verbunden:
            self.mqtt.neumeldung.clear()
            self.letzter_stand.clear()
            log.info("MQTT neu verbunden - alle Zustände werden erneut gemeldet")

        vollmeldung = max(self.intervall, self._zahl("aktualisierung", 60))
        erzwingen = neu_verbunden or (time.time() - self.letzte_vollmeldung) >= vollmeldung
        # Nach einer Neuverbindung geht ALLES sofort hinaus, auch was sonst
        # nur im groben Takt gesendet wird (_senden, abstand).
        self.neumeldung_laeuft = neu_verbunden
        try:
            self.auswerten(erzwingen=erzwingen)
        finally:
            self.neumeldung_laeuft = False
        if erzwingen:
            self.letzte_vollmeldung = time.time()
            # Der HTTP-Weg bekommt die Vollmeldung ebenfalls: bis 1.2.10 lief
            # sie an ihm vorbei, weil _senden() dann "nicht geaendert" meldete.
            if self.cfg.get("http_push", "0") == "1":
                self.push_ist.clear()

        self.wachhund()
        self.batterie_runde()

        # Gescheitertes Abraeumen am Broker stuendlich erneut (seit 1.3.19).
        if self.aufraeumen_offen and self.mqtt.verbunden \
                and time.time() - self.aufraeumen_zeit >= 3600:
            self.mqtt_aufraeumen_anstossen()

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
        alte_wlan = (self.cfg.get("wlan_kopplung", "0"), self.cfg.get("wlan_zuordnung", ""))

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
            # BERICHTIGT IN 1.3.19. Bis 1.3.18 wurde hier nur das Praefix der
            # MQTT-Huelle umgesetzt: der Letzte Wille blieb auf dem ALTEN
            # server/online, und online=1 ging auf dem neuen nie hinaus
            # (Regeln/07, Bedingung a; Fall X2). Jetzt wird die Verbindung mit
            # dem neuen Willen neu aufgebaut; die retained Themen des alten
            # Praefixes raeumt mqtt_aufraeumen() nach der Anmeldung ab.
            log.info("Themenpräfix geändert: %s -> %s. Die MQTT-Verbindung wird "
                     "neu aufgebaut; die Themen des alten Präfixes werden abgeräumt.",
                     alter_praefix, neuer_praefix)
            self.praefix = neuer_praefix
            self.veroeffentlicht.clear()
            self.altlast_erledigt = False
            self.entfallen_erledigt = False
            self.gesendet_um.clear()
            lief = self.mqtt.client is not None
            if lief:
                self.mqtt.stop()
                self.mqtt.client = None
            self.mqtt = self._mqtt_neu()
            if self.cfg.get("mqtt", "1") == "1":
                self.abo_datei_nachziehen()
            if lief and altes_mqtt == "1" and self.cfg.get("mqtt", "1") == "1":
                self.mqtt.start()
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
        # Anwesenheit mit dem WLAN-Scanner (Verbesserungsbau 30.09.2026): Kopplung
        # oder Zuordnung geaendert - Abo nachziehen und entfallene Themen mit
        # Nachlesen abraeumen (mqtt_aufraeumen, "entfallen").
        neue_wlan = (self.cfg.get("wlan_kopplung", "0"), self.cfg.get("wlan_zuordnung", ""))
        if neue_wlan != alte_wlan:
            log.info("Anwesenheit mit dem WLAN-Scanner: Einstellung geaendert (%s).",
                     "an" if neue_wlan[0] == "1" else "aus")
            if neue_wlan[0] != "1":
                with self.wlan_sperre:
                    self.wlan = {"ts_empfang": 0.0, "ts": 0, "ok": "", "intervall": 0,
                                 "personen": {}}
                self.wlan_lage = {"an": 0}
            if neue_wlan[0] == "1" or self._zahl("raum", 0) == 1:
                self.mqtt.abo_rueckruf = self.abonnieren
            self.entfallen_erledigt = False
            if self.mqtt.client is not None and self.mqtt.verbunden:
                try:
                    if neue_wlan[0] == "1" and alte_wlan[0] != "1":
                        self.abonnieren(self.mqtt.client)
                    elif neue_wlan[0] != "1" and alte_wlan[0] == "1":
                        self.mqtt.client.unsubscribe(gem.WLAN_PRAEFIX + "/#")
                except Exception as fehler:      # noqa: BLE001
                    log.warning("MQTT-Abo des WLAN-Scanners nicht umgestellt: %s", fehler)
                self.mqtt_aufraeumen_anstossen()
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

        def bei_besitzerwechsel(name, alt, neu):
            # Neu seit 1.3.20: bluetoothd wurde neu gestartet. Das Signal kommt
            # im D-Bus-Faden; neu verbunden wird im naechsten Durchlauf.
            if str(name) == gem.BLUEZ and str(neu or ""):
                log.info("org.bluez hat einen neuen Besitzer (%s -> %s) - bluetoothd "
                         "wurde neu gestartet; es wird neu verbunden.",
                         str(alt or "-"), str(neu))
                self.bluez_neu.set()

        self.bluez.bus.add_signal_receiver(
            bei_besitzerwechsel, signal_name="NameOwnerChanged",
            dbus_interface="org.freedesktop.DBus", bus_name="org.freedesktop.DBus",
            arg0=gem.BLUEZ)

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
                    self.stoerung_melden(str(fehler))
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
            if self._zahl("raum", 0) == 1 or self.cfg.get("wlan_kopplung", "0") == "1":
                self.mqtt.abo_rueckruf = self.abonnieren
            self.abo_datei_nachziehen()
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

    def abo_datei_nachziehen(self):
        """mqtt_subscriptions.cfg auf "<praefix>/#" (seit 1.3.20, MQTT 6)."""
        geschrieben, fehler = gem.abo_datei_nachziehen(self.praefix)
        if geschrieben:
            log.info("Gateway-Abo gesetzt: %s/# (%s)", self.praefix, gem.ABO_DATEI)
        elif fehler:
            log.warning("Gateway-Abo %s nicht geschrieben: %s", gem.ABO_DATEI, fehler)

    def stop(self):
        self.laeuft = False
        if self.bluez:
            self.bluez.suche_beenden()
        self.mqtt.stop()


_SPERRE = None


def main():
    # GENAU EIN SCHALTER (seit 1.3.19): "--mqtt-leeren" fuer die
    # Deinstallation. Er startet keinen Dienst. Mit drei Argumenten gilt der
    # Aufruf fuer jede Diensterkennung dieses Plugins als Einmallauf (argv[1]
    # ist das Skript, argv[2] der Schalter). Bis 1.3.18 wurde JEDES Argument
    # uebergangen, und ein Aufruf mit Tippfehler startete einen zweiten Dienst.
    if sys.argv[1:] == ["--mqtt-leeren"]:
        sys.exit(mqtt_leeren())
    if sys.argv[1:]:
        for a in sys.argv[1:]:
            sys.stderr.write("Unbekannter Schalter: {0}\n".format(a))
        sys.stderr.write("Dieses Skript ist der Dauerdienst; es kennt nur --mqtt-leeren "
                         "(fuer die Deinstallation).\n")
        sys.exit(2)
    # Aus einem ausgepackten Archiv startet kein Dienst (Muster 3, Fall W4):
    # er nahm bis 1.3.18 Konfiguration, Daten und Broker der Anlage.
    if not gem.INSTALLIERT:
        sys.stderr.write(
            "ble_scanner_ng.py: Diese Datei liegt nicht in einer LoxBerry-Installation "
            "(ausgepacktes Archiv oder Pruefordner"
            + (" unter " + gem.ARCHIV_WURZEL if gem.ARCHIV_WURZEL else "") + ").\n"
            "Damit nichts in die Anlage kommt, startet hier kein Dienst. Abhilfe: den "
            "Dienst der Installation starten oder LBHOMEDIR und LBPPLUGINDIR "
            "ausdruecklich setzen.\n")
        sys.exit(1)
    # Das eigene Protokoll oeffnen (seit 1.3.20, C5) - erst hier, damit die
    # einbindenden Werkzeuge nicht in das Dienstprotokoll schreiben.
    log_datei_einrichten()
    # EIN Dienst (seit 1.3.20, Regeln/03 "Ein Dauerlaeufer nimmt eine
    # Sperrdatei"; Pruefung 29.09.2026, C4): zweimal "Dienst starten" oder F5
    # nach dem Absenden legte bis 1.3.19 einen zweiten Scanner daneben - beide
    # sendeten dieselben Themen, und der Letzte Wille des einen setzte
    # server/online=0, waehrend der andere lief. Das Handle bleibt bis zum
    # Prozessende offen (global); faellt es, faellt die Sperre. Python-Dateien
    # werden seit 3.4 nicht an Kindprozesse vererbt (PEP 446) - der Dienst
    # startet ohnehin keine.
    global _SPERRE
    try:
        import fcntl
        os.makedirs(gem.DATA_DIR, exist_ok=True)
        _SPERRE = open(gem.SPERR_DATEI, "a")
        fcntl.flock(_SPERRE.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except ImportError:
        _SPERRE = None          # kein fcntl (nicht Linux): ohne Sperre weiter
    except OSError as fehler:
        if fehler.errno in (errno.EAGAIN, errno.EACCES, errno.EWOULDBLOCK):
            msg = ("BLE-Scanner NG laeuft bereits (Sperre %s belegt) - dieser "
                   "zweite Start endet.") % gem.SPERR_DATEI
            log.warning("%s", msg)
            sys.stderr.write(msg + "\n")
            sys.exit(3)
        log.warning("Sperrdatei %s nicht zu nehmen (%s) - weiter ohne Sperre.",
                    gem.SPERR_DATEI, fehler)
    for zeile in gem.alte_ramdisk_namen_uebernehmen():
        log.info("Ramdisk-Datei unter dem alten Namen: %s", zeile)
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
