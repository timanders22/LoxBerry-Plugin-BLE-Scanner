# LoxBerry-Plugin BLE-Scanner

Erkennt Bluetooth-Low-Energy-Geräte in Reichweite und meldet dem Loxone
Miniserver, ob ein hinterlegter Tag anwesend ist — samt Signalstärke. Typischer
Einsatz: Schlüsselanhänger als Anwesenheitserkennung.

## Herkunft und Pflege

Grundlage ist das Plugin von **Christian Woerstenfeld**, Version 2021.2.3,
Apache-Lizenz 2.0
([Woersty/LoxBerry-Plugin-BLE-Scanner](https://github.com/Woersty/LoxBerry-Plugin-BLE-Scanner) ·
[LoxBerry-Wiki](https://wiki.loxberry.de/plugins/ble_scanner/start)).

**Die Urheberschaft bleibt bei ihm** — Autorenangabe in `plugin.cfg` und die
Apache-Lizenz sind unverändert. Das verlangt nicht nur die Lizenz: LoxBerry
identifiziert ein Plugin über genau diese beiden Felder. Wer sie ändert, macht
daraus für LoxBerry ein anderes Plugin, und jedes Update schlägt fehl.

## Version 1.0.0 — LoxBerry 4 und Hausstandard

### Warum die Originalfassung auf LoxBerry 4 nicht läuft

Vier voneinander unabhängige Gründe, jeder für sich ausreichend:

- **`each()` ist in PHP 8 entfernt.** In `webfrontend/html/index.php` — genau
  der Seite, die Loxone abfragt — steht es dreimal
  (`while (list($k,$v) = each($array))`). PHP 8 wirft dort
  `Call to undefined function each()`. LoxBerry 4 liefert PHP 8.4: die
  Kernfunktion des Plugins ist tot.
- **`blescan.py` ist Python 2.** `print`-Anweisungen ohne Klammern, `xrange`,
  `struct.unpack` auf Zeichenketten. Python 3 kann die Datei nicht einmal
  einlesen.
- **Die Abhängigkeiten gibt es nicht mehr.** `dpkg/apt` verlangte `python`,
  `python-pip`, `python-dev` und `python-bluez` — allesamt Python-2-Pakete, die
  auf Bookworm und Trixie fehlen. Damit bricht schon die Installation ab.
  `raspberrypi-sys-mods` und `pi-bluetooth` sind zudem Raspberry-Pi-spezifisch,
  obwohl sich das Plugin als `"raspberry,x86"` auswies.
- **`hciconfig` und `hciuart` sind Auslaufmodelle.** Der Startskript-Block, der
  `hciuart.service` bis zu dreimal neu startet und `hciconfig hci0 up` aufruft,
  hängt an Werkzeugen, die BlueZ selbst als veraltet erklärt hat.

Dazu kam ein handfester Fehler in der Verpackung: **`plugin.cfg` enthielt den
Abschnitt `[SYSTEM]` zweimal**, mit unterschiedlichem `LB_MINIMUM` (1.0.0 und
1.0.3). Welcher galt, hing vom Parser ab.

### Was neu ist

- **Scanner neu in Python 3 über die D-Bus-Schnittstelle von BlueZ.** Das ist
  der von BlueZ vorgesehene Weg und braucht **kein zusätzliches Paket**:
  `bluez`, `python3-dbus` und `python3-gi` sind auf LoxBerry 3 und 4 bereits
  im Grundsystem. Läuft auf arm64 und x86.
- **Ein durchlaufender Dienst** statt „Scan auf Zuruf". Die alte Bauweise —
  Loxone ruft eine PHP-Seite auf, die über einen TCP-Socket einen PHP-Daemon
  weckt, der ein Python-Skript startet, das in eine SQLite-Datei schreibt, die
  dann zurückgelesen wird — entfällt vollständig. Damit entfällt auch der
  **unauthentifizierte Endpunkt** unter `webfrontend/html/`.
- **MQTT als Weg zum Miniserver**, retained. Fünf Zustände je Tag statt bisher
  einem: `present`, `rssi`, `level`, `last_seen`, `name`, dazu
  `summary/present`, `summary/tags` und `server/online`.
- **Der HTTP-Weg bleibt erhalten**, abschaltbar, und erzeugt dieselben Namen
  virtueller Eingänge wie bisher (`<Kennung>BLE_AA_BB_CC_DD_EE_FF`) — bestehende
  Loxone-Konfigurationen laufen also weiter. Die Zugangsdaten stehen dabei
  nicht mehr in der URL, sondern im Authorization-Kopf.
- **Neue Oberfläche als `index.php`** mit vier Reitern: *Einstellungen*,
  *Einbindung in Loxone*, *Test*, *Logdateien*. Vollständig auf Deutsch. Die
  Perl-CGI-Oberfläche mit HTML::Template und den beiden Sprachdateien entfällt.
- **Reiter Test** nach Hausstandard mit drei Gruppen und Legende. Geprüft
  werden Dienst, Tags, sichtbare Geräte, Bluetooth-Adapter, Python-Module und
  das MQTT-Gateway.
- **Die vorhandene Konfiguration wird übernommen.** Das alte Format
  (`TAG1="BLE_AA_BB_..:on:1^on~2^off:Kommentar"`) wird erkannt und beim
  nächsten Speichern ins neue umgeschrieben; die Tags bleiben erhalten.
- **Der Dienst läuft als `loxberry`, nicht als `root`.** Er braucht nur
  D-Bus-Zugriff auf BlueZ; die Installation nimmt `loxberry` dafür in die
  Gruppe `bluetooth` auf.
- Die beiden Endlosschleifen, die alle 15 Sekunden mit `sed` in
  `/var/log/syslog` und `/var/log/kern.log` herumschnitten, sind ersatzlos weg.

## MQTT-Themen

Je Tag unter `blescanner/<MAC ohne Trennzeichen>/`:

| Thema | Art | Bedeutung |
|---|---|---|
| `present` | digital | Tag in Reichweite |
| `rssi` | analog | Signalstärke in dBm, −255 wenn außer Reichweite |
| `level` | analog | 3 nah, 2 mittel, 1 schwach, 0 weg |
| `last_seen` | analog | Sekunden seit der letzten Sichtung, −1 wenn nie |
| `name` | Text | Bezeichnung des Tags |

Dazu `blescanner/server/online`, `blescanner/summary/present` und
`blescanner/summary/tags`.

## Dateien

| Datei | Zweck |
|---|---|
| `bin/ble_scanner.py` | Dienst: Scan, Auswertung, MQTT, HTTP |
| `bin/bl_common.py` | Pfade, Konfiguration, BlueZ-Zugriff |
| `bin/bl_discover.py` | Gerätesuche für die Oberfläche |
| `webfrontend/htmlauth/index.php` | Oberfläche, vier Reiter |
| `webfrontend/htmlauth/bl_lib.php` | Konfiguration, Themen, Loxone-XML |
| `webfrontend/htmlauth/bl_test.php` | Aktionen des Reiters Test |
| `config/ble_scanner.cfg` | Konfiguration |

## Voraussetzungen

- LoxBerry ab 2.0
- Ein Bluetooth-Adapter mit BLE-Unterstützung
- MQTT-Gateway (eigenes LoxBerry-Plugin) für den MQTT-Weg

## Bekannte Grenze

Mobiltelefone, Uhren und Kopfhörer wechseln ihre Bluetooth-Adresse regelmäßig
und taugen deshalb nicht zur Anwesenheitserkennung. Zuverlässig sind BLE-Beacons
und Schlüsselfinder mit fester Adresse. Das gilt für jedes Plugin dieser Art und
lässt sich nicht umgehen.

## Lizenz

Apache-Lizenz 2.0, wie das Ausgangsprojekt. Der Lizenztext in `LICENSE` und die
Autorenangabe sind unverändert übernommen.
