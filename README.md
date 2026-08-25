# LoxBerry-Plugin BLE-Scanner NG

Version 1.3.1

Erkennt Bluetooth-Low-Energy-Geräte in Reichweite und meldet dem Loxone
Miniserver, ob ein hinterlegter Tag anwesend ist — samt Signalstärke,
Zeitstempel und, wo das Gerät sie mitsendet, Temperatur, Luftfeuchte und
Batteriestand. Typischer Einsatz: Schlüsselanhänger als Anwesenheitserkennung.

## Fassung 1.3.0

Zwei Dinge auf einmal: die Befunde einer zeilenweisen Durchsicht sind behoben,
und der Funktionsumfang ist erweitert. Die vollständige Liste steht in `NOTICE`
(Apache-Lizenz, Abschnitt 4 b); hier die Punkte, die für den Betrieb zählen.

### Behoben — drei stille Falschaussagen

**Die PHP-Seite hatte die Parser-Korrekturen aus 1.2.0 nie bekommen.**
`bl_common.py` beschreibt in zwei ausführlichen Kommentaren, welche zwei Fehler
des Konfigurationslesers behoben wurden. Behoben wurden sie nur in Python.
Gemessen an einem Klick auf *Speichern*, ohne irgendetwas zu ändern:

    VORHER   tag1=AA:BB:CC:DD:EE:FF
             tag2=11:22:33:44:55:66|1|Schluessel | Justin

    NACHHER  tag1=11:22:33:44:55:66|1|Schluessel

Ein Tag gelöscht, einer umbenannt. Beide Leser werden jetzt im Reiter *Test* an
einer Reihe von Prüffällen gegeneinander gehalten — eine Prüfung, die rot wird,
sobald sie wieder auseinanderlaufen.

**`last_seen` sprang nach zehn Minuten auf −1.** Gemessen an der echten
Auswertung des Dienstes:

| zuletzt gesehen vor | present | last_seen (1.2.10) | last_seen (1.3.0) |
|---|---|---|---|
| 599 s | 0 | 599 | 599 |
| 601 s | 0 | **−1** | 601 |

−1 ist in der eigenen Themenliste als „nie gesehen" dokumentiert. Ursache:
`einlesen()` ließ auch die Sichtungen **konfigurierter** Tags nach zehn Minuten
verfallen. Sie verfallen nicht mehr, und zusätzlich gibt es `last_seen_ts` —
einen echten Zeitstempel. MQTT ist ein Push-Weg; dort ist das Alter beim Senden
immer null, die Gegenseite soll rechnen.

**Zurückbehaltene Themen entfernter Tags blieben für immer stehen.** Wer einen
Anhänger austauschte, hatte danach dauerhaft `…/present=1` im Broker — in
Loxone nicht von einer echten Anwesenheit zu unterscheiden. Sie werden jetzt
mit leerer Nutzlast gelöscht.

### Behoben — Betrieb

* **`preupgrade.sh` fand den laufenden Dienst ohne PID-Datei nie.** Das
  Suchmuster hieß `[b]le_scanner.py`, der Dienst heißt `ble_scanner_ng.py`;
  gemessen null Treffer. Gesucht wird jetzt argumentweise über `/proc`.
* **Nach einem Update startete den Dienst niemand wieder.** `postupgrade.sh`
  tut es jetzt — aber nur, wenn er vorher lief.
* **Der Aufräumer entkoppelte fremde Bluetooth-Geräte.** `RemoveDevice`
  löscht bei einem gekoppelten Gerät die Kopplung; eine fremde Tastatur verlor
  sie lautlos. `Paired`, `Trusted` und `Connected` werden jetzt verschont.
* **Der HTTP-Weg verwarf Zustandswechsel endgültig**, wenn der Miniserver
  gerade gesperrt war. Jetzt wird der Sollwert gemerkt und wiederholt — und er
  trägt dieselben Werte wie MQTT.
* **Das Protokoll wuchs unbegrenzt auf einer Ramdisk** und wurde von zwei
  Schreibern zugleich gefüllt.
* **Die Fassungsnummer stand an drei Stellen verschieden** (1.2.10 / 1.2.9 /
  1.2.0). Sie steht jetzt an einer: `postinstall.sh` schreibt sie nach
  `fassung.txt`, beide Seiten lesen von dort.
* **Zeitrechnung auf die monotone Uhr umgestellt.** Auf einem Pi ohne
  Echtzeituhr springt die Uhr beim ersten NTP-Abgleich — rückwärts wurde
  dadurch jedes je gesehene Gerät als anwesend gemeldet.
* **`uninstall/uninstall`** riet einen falschen Ordnernamen und ließ die
  Zweitschrift der Konfiguration liegen. Die ist eine Anwesenheitsliste des
  Haushalts, und eine spätere Neuinstallation holte sie ungefragt zurück.

### Neu — ruhigere Anwesenheit

* **Ereignisgesteuerter Betrieb.** BlueZ meldet jede Änderung über
  `PropertiesChanged`, statt dass alle paar Sekunden das gesamte
  Objektverzeichnis abgefragt wird. Der Dienst sieht damit **jedes**
  Werbepaket statt einer Stichprobe je Runde — für eine Mittelung ist das der
  Unterschied zwischen brauchbar und nicht. `python3-gi` stand seit jeher in
  `dpkg/apt` und wurde nie benutzt; jetzt trägt es diesen Betrieb. Fehlt es,
  fällt der Dienst auf den Abfragebetrieb zurück und sagt es im Protokoll.
* **Glättung und Hysterese.** Median plus gleitendes Mittel statt des zuletzt
  empfangenen Wertes; die Signalstufe wechselt erst bei einer Überschreitung um
  3 dB. Ein Anhänger, der genau auf der Schwelle liegt, erzeugt damit keine
  Flanke mehr in jeder Runde.
* **Ankunfts-Entprellung und Mindest-Signalstärke.** Bis 1.2.10 war die Logik
  unsymmetrisch: beim Gehen 30 Sekunden Geduld, beim Kommen genügte **ein**
  Paket beliebiger Stärke. Beides ist jetzt einstellbar, ab Werk neutral.
* **Wachhund.** Erkennt, wenn gar nichts mehr ankommt, und setzt in Stufen
  Suche und Adapter neu auf. Das ist der häufigste Dauerbetriebsfehler dieser
  Geräteklasse — und die Prüfung im Reiter *Test*, die davor warnen sollte,
  konnte bis 1.2.10 gar nicht ansprechen.

### Neu — mehr als anwesend/abwesend

* **Adresstyp je Gerät**: *fest*, *statisch zufällig* oder *wechselt*. Der
  häufigste Anwenderfehler — das Telefon als Tag — wird dort abgefangen, wo er
  entsteht: in der Fundliste.
* **Werbedaten dekodieren**: iBeacon, Eddystone (UID/URL/TLM), ATC/pvvx und
  RuuviTag. Ein Xiaomi-Thermometer mit freier Firmware liefert damit Temperatur
  und Luftfeuchte je Raum, ohne WLAN und ohne Cloud. Jede Dekodierung prüft
  Länge und Plausibilität; passt etwas nicht, wird **nichts** veröffentlicht.
* **Entfernungsschätzung** in Metern, mit einem Kalibrierknopf im Reiter *Test*.
* **Batteriestand** aus Eddystone-TLM und RuuviTag ohne Verbindung, sonst je
  Tag einschaltbar über GATT (ab Werk aus — der Scan steht dabei still, und
  manche Schlüsselfinder piepen).
* **Verlauf** von Kommen und Gehen mit eigenem Reiter. Daraus nennt das Plugin
  eine Zahl für *Abwesend nach*, statt sie raten zu lassen.
* **Einstellungen je Tag**, **Themen-Alias** (entkoppelt die
  Loxone-Konfiguration von der Hardware) und **Personen**: mehrere Tags, ein
  Mensch, und `person/<Name>/present` ist das ODER darüber.
* **Mehrere Scanner**: eigener Themenzweig je Scanner und Raumzuordnung mit
  Hysterese und Ausgleichswert.
* **Herzschlag**: `server/ts` kommt in jedem Durchlauf, `server/ok` und
  `server/adapter_ok` sagen, ob der Dienst wirklich arbeitet. Ohne einen
  solchen Zeitstempel sieht ein toter Dienst in Loxone genauso aus wie ein
  ruhiges Haus.

### Neu — Oberfläche

Sechs Reiter statt vier: *Einstellungen*, **MQTT**, *Einbindung in Loxone*,
**Verlauf**, *Test*, *Logdateien*. MQTT hat einen eigenen Reiter mit eigenem
Speicher-Handler; Beanstandungen werden gesammelt und angezeigt, statt Eingaben
stillschweigend auf die Vorgabe zurückzubiegen; die Statuskacheln erneuern sich
selbst; Tags lassen sich mit einem Haken entfernen; der Suchlauf wirft die
getippten Zeilen nicht mehr weg.

Der Reiter *Test* hat eine **Selbstprüfung**: je Zeile eine Frage mit Haken,
Kreuz oder Strich. Ein Strich ist kein Haken — was nicht gemessen werden
konnte, steht als Strich da.

## MQTT-Themen

Je Tag unter `<Präfix>/<T>/`, wobei `<T>` die Adresse ohne Trennzeichen oder
der eingetragene Alias ist:

| Thema | Art | Bedeutung |
|---|---|---|
| `present` | digital | Tag in Reichweite |
| `rssi` | analog | Signalstärke in dBm, −255 wenn außer Reichweite |
| `rssi_avg` | analog | geglättete Signalstärke |
| `level` | analog | 3 nah, 2 mittel, 1 schwach, 0 weg |
| `last_seen` | analog | Sekunden seit der letzten Sichtung |
| `last_seen_ts` | analog | Zeitstempel der letzten Sichtung |
| `present_since` | analog | Zeitstempel des letzten Wechsels |
| `name` | Text | Bezeichnung des Tags |

Auf Wunsch dazu `distance`, `battery`, `battery_ts`, `raum`, `raum_seit` und
`sensor/<Wert>`.

Allgemein: `server/online`, `server/ok`, `server/ts`, `server/adapter_ok`,
`server/letzte_sichtung`, `server/version`, `server/scanner`,
`summary/present`, `summary/tags`, `summary/tags_gesamt`, `summary/names` und
`person/<Name>/present`.

## Dateien

| Datei | Zweck |
|---|---|
| `bin/ble_scanner_ng.py` | Dienst: Scan, Auswertung, MQTT, HTTP |
| `bin/bl_common.py` | Pfade, Konfiguration, BlueZ-Zugriff |
| `bin/bl_beacon.py` | Werbedaten dekodieren, mit eigener Eichung |
| `bin/bl_discover.py` | Gerätesuche für die Oberfläche |
| `bin/bl_selbsttest.py` | Selbstprüfung der Python-Seite |
| `bin/bl_lesen.py` | Konfigurationsleser von außen abfragbar |
| `webfrontend/htmlauth/index.php` | Oberfläche, sechs Reiter |
| `webfrontend/htmlauth/bl_lib.php` | Konfiguration, Themen, Loxone-Vorlage |
| `webfrontend/htmlauth/bl_test.php` | Selbstprüfung und Aktionen |
| `webfrontend/htmlauth/bl_live.php` | Zustandsdatei als JSON (angemeldet) |
| `config/ble_scanner_ng.cfg` | Konfiguration |

Die Sprachdateien werden aus **einer** Tabelle erzeugt:
`Werkzeuge/ble_sprache_erzeugen.py`. Wer einen Text ändert, ändert ihn dort.

## Voraussetzungen

- LoxBerry ab 3.0.0
- Ein Bluetooth-Adapter mit BLE-Unterstützung
- Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems (System →
  MQTT Gateway) und muss auf Autostart stehen

## Bekannte Grenze

Mobiltelefone, Uhren und Kopfhörer wechseln ihre Bluetooth-Adresse regelmäßig
und taugen deshalb nicht zur Anwesenheitserkennung. Seit 1.3.0 muss man das
nicht mehr ausprobieren: die Fundliste sagt es. Wer ein Telefon trotzdem
einbinden will, lässt es als iBeacon werben und trägt die Kennung
`ib:UUID:major:minor` ein — die bleibt stabil.

## Was nicht am Gerät gemessen ist

Ehrlichkeitshalber, weil es den Umgang mit Fehlerberichten erleichtert: die
Fassung 1.3.0 ist gegen PHP 7.4 und 8.4 gerendert, gegen den echten Parser und
gegen die echte Auswertung des Dienstes gemessen — aber **nicht** an einem
LoxBerry mit Bluetooth-Adapter. Offen sind damit:

* wie dicht `PropertiesChanged` tatsächlich feuert (davon hängt die Breite des
  Glättungsfensters ab; mit `dbus-monitor` zu messen),
* ob `org.bluez.Battery1` in der BlueZ-Fassung des LoxBerry aktiv ist,
* die Anordnung der ATC/pvvx-Nutzdaten an einem echten Sensor.

Die Selbstprüfung im Reiter *Test* führt diese Punkte als Strich, nicht als
Haken.

## Herkunft und Pflege

Grundlage ist das Plugin **BLE-Scanner** von **Christian Woerstenfeld**,
Version 2021.2.3, Apache-Lizenz 2.0
([Woersty/LoxBerry-Plugin-BLE-Scanner](https://github.com/Woersty/LoxBerry-Plugin-BLE-Scanner)).
Die dort beschriebene Bedienung gilt für das Original, nicht für diese Fassung.

**Die urheberrechtliche Nennung bleibt bei ihm** und steht dort, wo die
Apache-Lizenz sie verlangt: in `NOTICE` (samt Liste der Änderungen nach
Abschnitt 4 b), hier im README, in der Hilfeseite und im Kopf der
Python-Dateien.

**Maintainer dieser Fortführung:** [timanders22](https://github.com/timanders22).
Fehlermeldungen und Wünsche bitte als Issue in **diesem** Repository.

## Lizenz

Apache-Lizenz 2.0, wie das Ausgangsprojekt.
