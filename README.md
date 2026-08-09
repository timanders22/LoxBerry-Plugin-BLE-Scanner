# LoxBerry-Plugin BLE-Scanner NG

Erkennt Bluetooth-Low-Energy-Geräte in Reichweite und meldet dem Loxone
Miniserver, ob ein hinterlegter Tag anwesend ist — samt Signalstärke. Typischer
Einsatz: Schlüsselanhänger als Anwesenheitserkennung.

## Fassung 1.2.1 — gegen PHP 7.4 *und* 8.1 gemessen

Beide Fassungen wurden diesmal nicht bloss syntaktisch geprueft, sondern die
Oberflaeche wurde unter **PHP 7.4.3 und PHP 8.1.2** tatsaechlich gerendert
(mit einer Attrappe des LoxBerry-SDK, `error_reporting=E_ALL`,
`display_errors=1`). Dabei kam ein Fehler heraus, den `php -l` nicht finden
kann und den das `error_reporting` der Oberflaeche unter 7.4 verschluckt hat:

### Die PID-Datei lag im Wurzelverzeichnis

    function bl_pid_datei()
    {
        return bl_paths()['datadir'] . '/dienst.pid';   // FALSCH
    }

`bl_paths()` hat gar keinen Schluessel `datadir` — es gibt `home`, `plugin`,
`config`, `bindir`, `logdir` und `status`. Herausgekommen ist damit
**`/dienst.pid`**, also das Wurzelverzeichnis. Als Benutzer `loxberry` ist das
nicht beschreibbar; das

    echo $! > /dienst.pid

beim Start lief jedes Mal ins Leere. Gemerkt hat es niemand, weil
`bl_dienst_pid()` still auf die `pgrep`-Suche zurueckfaellt — und die findet
den Dienst ja. Der PID-Weg, der eigentlich der massgebliche sein sollte, war
damit **seit jeher tot**.

Unter PHP 7.4 ist der Zugriff auf den fehlenden Schluessel eine *Notice* und
wird von `error_reporting(E_ALL & ~E_NOTICE)` verschluckt; unter PHP 8 ist es
eine *Warning*. So ist es beim Rendern gegen beide Fassungen aufgefallen.

**Behoben:** `bl_paths()` liefert jetzt `pid` — `/run/shm/ble_scanner_ng.pid`,
also neben der Zustandsdatei auf der Ramdisk, wo eine PID hingehoert.
Nachgemessen: der Pfad ist beschreibbar.

### Die Rueckfallebene war die einzige Ebene

Weil die PID-Datei nie entstand, lief jede Abfrage ueber

    pgrep -o -f "[b]le_scanner.py"

Die Klammer verhindert nur, dass die Suche sich selbst findet. Ein Editor mit
offener Datei oder ein zweites Exemplar des Plugins (LoxBerry haengt bei
Namenskonflikt 01, 02 … an den Ordnernamen) werden sehr wohl getroffen.

Gegenprobe mit vier laufenden Prozessen — das eigene Exemplar ueber `python3`,
dasselbe ueber den Shebang, ein zweites unter `…/b/`, dazu ein offenes `tail`:

| Verfahren | Ergebnis |
|---|---|
| `pgrep -o -f "[b]le_scanner.py"` | **PID 1** (der init-Prozess) |
| argumentweise ueber `/proc` | die beiden richtigen, sonst nichts |

`pgrep -o` liefert den *aeltesten* Treffer, und das war hier PID 1. Die
anschliessende Pruefung der Befehlszeile schlaegt dort fehl — die Oberflaeche
haette also „laeuft nicht" gemeldet, obwohl drei Exemplare liefen.

**Behoben:** verglichen wird jetzt argumentweise gegen den vollen Skriptpfad;
ein Treffer ist entweder der Shebang-Start oder ein Python-Interpreter mit dem
Pfad unter den Argumenten.

### Kleinigkeit

Vier tote Sprachschluessel entfernt (`ALLGEMEIN.JA`, `.NEIN`, `.SPEICHERN`,
`REITER.MQTT`). Beide Sprachdateien: 193 Schluessel, deckungsgleich, keiner
fehlt, keiner unbenutzt.

Nach der Korrektur liefern 7.4 und 8.1 **zeichengleiche Ausgabe ohne jede
Meldung**.

## Neu in 1.2.0

**Die Versionsnummer stand an vier Stellen verschieden.** Der Ordner sagte
1.0.0 (und war doppelt verschachtelt: `LoxBerry-Plugin-BLE-Scanner NG-1.0.0/`
enthielt noch einmal denselben Namen), `plugin.cfg` und `release.cfg` sagten
1.1.0, `prerelease.cfg` sagte 1.0.0 samt Tag `v1.0.0`, und `bl_common.py`
meldete der Oberfläche und der Zustandsdatei ebenfalls 1.0.0 — wer damit Hilfe
suchte, nannte die falsche Fassung. Jetzt steht überall 1.2.0, und die
Verschachtelung ist aufgelöst.

- **Eigentümer nach dem Update.** `postupgrade.sh` spielte die Konfiguration
  als `root` zurück und setzte nie `chown`. Die Oberfläche läuft als
  `loxberry`: sie konnte `ble_scanner_ng.cfg` danach lesen, aber nicht schreiben.
  Wer Tags anhakte und speicherte, bekam nichts gespeichert — das Schreiben ist
  mit `@` unterdrückt, also lautlos.
- **Der Dienst wird vor dem Update angehalten.** Das fehlte ganz. Die Folge war
  nicht, wie vermutet, ein hängender Bluetooth-Stack — BlueZ räumt Discovery
  auf, wenn die D-Bus-Verbindung wegfällt. Die Folge war ein stiller
  Fassungsversatz: LoxBerry ersetzt `bin/*.py` unter dem laufenden Prozess, der
  seinen Quelltext längst geladen hat und unbeirrt mit der **alten** Fassung
  weiterläuft, bis irgendwann neu gestartet wird.
- **PID-Datei statt `pkill -f`.** `pgrep -o -f ble_scanner_ng.py` trifft jede
  Befehlszeile, in der die Zeichenkette vorkommt — ein Editor, der die Datei
  offen hat, genügt — und `-o` nimmt davon den ältesten Treffer. `pkill -f`
  hätte dann den fremden Prozess erwischt. Jetzt: PID-Datei, geprüft gegen
  `/proc/<pid>/cmdline`, und ein gezieltes `kill` mit zehn Sekunden Geduld.
  `daemon/daemon` schreibt die Datei beim Systemstart mit.
- **MQTT: der erste Verbindungsversuch.** Schlug `connect()` fehl — und beim
  Systemstart ist das der Regelfall, weil das Gateway noch nicht läuft —, kehrte
  `start()` zurück, ohne `loop_start()` aufzurufen, ließ `self.client` aber
  gesetzt. Jedes spätere `publish()` verschwand für die gesamte Laufzeit
  spurlos. Jetzt `connect_async()` plus `loop_start()`, der Rückgabewert von
  `publish()` wird ausgewertet, und **nach einer Neuverbindung werden alle
  Zustände erneut gemeldet** — sonst bleiben die retained-Themen nach einem
  Neustart des Brokers leer, bis sich zufällig etwas ändert. Bei einem Tag im
  Schrank kann das Wochen dauern.
- **Der HTTP-Push blockiert den Scan nicht mehr.** Fünf Tags, die gleichzeitig
  wechseln, mal vier Sekunden Zeitgrenze: zwanzig Sekunden, in denen niemand
  BlueZ abfragt und Beacons verloren gehen. Jetzt ein Hintergrundfaden mit
  begrenzter Warteschlange und einer Minute Ruhe für einen Miniserver, der
  nicht antwortet.
- **Der stärkste RSSI gewinnt, nicht der letzte.** Im Suchlauf für die
  Oberfläche überschrieb jede Runde die vorige. Fing die letzte ein schwaches
  Paket ein, stand das Gerät mit −95 dBm in der Liste, obwohl es zwei Sekunden
  vorher mit −55 zu hören war — in einer nach Signalstärke sortierten Liste, die
  dazu dient, den eigenen Anhänger unter fremden Geräten zu erkennen. Der Name
  wird nur noch ergänzt, nie geleert.
- **D-Bus-Fehler werden unterschieden.** „Zugriff abgewiesen", „bluetoothd läuft
  nicht" und „kein Adapter dieses Namens" brauchen völlig verschiedene
  Abhilfen, standen aber alle drei als „Bluetooth-Adapter hci0 nicht
  ansprechbar" im Protokoll. Jetzt nennt jede Meldung den Befehl, der hilft.
- **Der senkrechte Strich im Kommentar** und, dabei gefunden, die
  Formaterkennung: eine Zeile im neuen Format, die nur die MAC enthält, wurde
  als altes Format gelesen und der Tag verschwand lautlos. Die Entscheidung
  fällt jetzt an der Form des Wertes, nicht am Vorhandensein eines Strichs.
- **`timeout` wird ausgewertet.** Lief `bluetoothctl` in die Zeitgrenze, stand
  im Reiter Test eine leere Zeile — die man für „kein Adapter" hält, während sie
  „BlueZ antwortet nicht" bedeutet.

### Bewusst nicht geändert

Es wird **keine eigene D-Bus-Richtlinie** unter `/etc/dbus-1/system.d/`
installiert. BlueZ bringt seine Richtlinie selbst mit, und darin steht
wortwörtlich `<policy group="bluetooth"><allow send_destination="org.bluez"/>`
— nachgesehen in bluez 5.64. Die Gruppe ist der vorgesehene Weg; die Behauptung,
das reiche seit Bookworm nicht mehr, trifft nicht zu. Eine eigene Datei dorthin
zu legen wäre eine systemweite Rechteänderung durch ein Plugin, sie wäre doppelt,
und beim nächsten BlueZ-Update stünde sie neben der mitgelieferten. Stattdessen
prüft die Installation, ob die Richtlinie die Gruppe kennt, und weist darauf hin,
dass eine neue Gruppe erst in einer neuen Sitzung wirkt — der Dienst aus der
Oberfläche erbt sonst die alten Gruppen des Webservers.

Ebenso wird `vergessen()` **nicht** aus dem Suchlauf heraus aufgerufen. Der
Suchlauf läuft zwölf Sekunden aus der Oberfläche heraus; würde er dabei den
BlueZ-Zwischenspeicher beschneiden, nähme er dem laufenden Dienst die Geräte
weg, die dieser gerade beobachtet. Aufgeräumt wird im Dienst, und der schont
konfigurierte Tags und alles, was in den letzten fünf Minuten zu hören war.

## Herkunft und Pflege

Grundlage ist das Plugin **BLE-Scanner** von **Christian Woerstenfeld**,
Version 2021.2.3, Apache-Lizenz 2.0
([Woersty/LoxBerry-Plugin-BLE-Scanner](https://github.com/Woersty/LoxBerry-Plugin-BLE-Scanner) ·
[LoxBerry-Wiki des Originals](https://wiki.loxberry.de/plugins/ble_scanner/start)).
Die dort beschriebene Bedienung gilt für das Original, nicht für diese Fassung.

**Die urheberrechtliche Nennung bleibt bei ihm** und steht dort, wo die
Apache-Lizenz sie verlangt: in `NOTICE` (samt Liste der Änderungen nach
Abschnitt 4 b), hier im README, in der Hilfeseite und im Kopf der
Python-Dateien.

**Nicht mehr in `plugin.cfg`** — und das ist Absicht. LoxBerry benutzt
`[AUTHOR] NAME` und `EMAIL` zusammen mit `[PLUGIN] NAME` als **Kennung** des
Plugins, nicht als Urhebervermerk. Bis 1.1.0 trug diese Fortführung deshalb die
Kennung eines fremden Plugins, zeigte mit `AUTOMATIC_UPDATES` aber auf ein
anderes Repository — eine Mischform, die LoxBerry nicht sauber auflöst. Und
Fehlerberichte wären beim Originalautor gelandet, der mit dieser Fassung nichts
zu tun hat.

**Maintainer dieser Fortführung:** [timanders22](https://github.com/timanders22).
Das Original ist seit dem 03.02.2021 ohne Commit. Fehlermeldungen und Wünsche
bitte als Issue in **diesem** Repository, nicht beim ursprünglichen Autor.

## Version 1.2.1 — eigene Kennung, eigener Name

Diese Fassung heißt **BLE-Scanner NG** und trägt eine **eigene Kennung**. Ordner
und interne Namen lauten `ble_scanner_ng`, der Anzeigename ist *BLE-Scanner NG*.

**Warum zusätzlich ein neuer Ordner:** Kennung allein hätte gereicht, damit
LoxBerry beide auseinanderhält. Aber mehrere Dateien liegen in einem von allen
Plugins geteilten Bereich:

| Datei | vorher | jetzt |
|---|---|---|
| Zwischenstand | `/run/shm/ble_scanner_status.json` | `…ble_scanner_ng_status.json` |
| Protokollstufe | `/tmp/BLE-Scanner.loglevel` | `/tmp/ble_scanner_ng.loglevel` |

Wären die geblieben, hätten sich Original und Fortführung bei paralleler
Installation **gegenseitig überschrieben**.

### Ein älterer Fehler, dabei aufgefallen

Die **PID-Datei** liegt nicht auf der Ramdisk, sondern unter
`<datadir>/dienst.pid` — sie wandert mit dem Ordnernamen ohnehin mit. Beim
Nachziehen fiel auf, dass die Oberfläche sie an einer ganz anderen Stelle
suchte: unter `/run/shm/`, wo sie nie lag.

`daemon/daemon`, `preupgrade.sh` und `uninstall/uninstall` benutzten alle drei
die richtige Datei — nur die Oberfläche nicht. Sie fiel deshalb bei **jedem**
Aufruf auf die Suche nach dem Prozessnamen zurück, also genau auf den Weg, den
die PID-Datei ersetzen sollte. Und diese Suche trifft auch einen Editor, der
`ble_scanner_ng.py` geöffnet hat. Behoben.

Aus demselben Grund räumt `uninstall/uninstall` nur noch die **eigenen**
Zustandsdateien weg. Bis 1.1.0 löschte es auch `/tmp/BLE-Scanner.loglevel` und
`/tmp/BLE-Scanner.daemon.pid` — Rückstände der Originalfassung. Das war richtig,
solange diese Fortführung das Original ersetzte; jetzt wäre es ein Eingriff in
eine fremde, weiterlaufende Installation.

**Was das für Sie bedeutet:**

* Wer **1.1.0 aus diesem Repository** hat: LoxBerry sieht 1.2.1 als neues
  Plugin. Einmal von Hand installieren, danach greift das Auto-Update wieder von
  selbst. Die alte Installation kann anschließend weg.
* Wer das **Original** hat: unverändert — beide können nebeneinander laufen.
* **Die Loxone-Adressen ändern sich**, weil der Ordner im Pfad steht: aus
  `/plugins/ble_scanner/…` wird `/plugins/ble_scanner_ng/…`.

## Version 1.0.0 — LoxBerry 4 und Hausstandard

**Zur Versionsnummer:** Das Original zählte nach Datum (2021.2.3). Hier beginnt
die Zählung neu bei `1.0.0`. Für LoxBerry ist das rechnerisch **älter** als die
vorhandene Fassung: `LoxBerry::System::plugin_version_compare` vergleicht
Haupt-, Neben- und Fehlerstand als Zahlen, und 1 ist kleiner als 2021.

Praktische Folge: Wer noch 2021.2.3 installiert hat, bekommt diese Fassung
**nicht** als Update angeboten und muss sie einmal von Hand einspielen — über
das ZIP oder die Release-Adresse. Ab dann greift das Auto-Update normal, weil
alle weiteren Fassungen von `1.0.0` aus aufwärts zählen.

### Warum die Originalfassung auf LoxBerry 4 nicht läuft

Vier voneinander unabhängige Gründe, jeder für sich ausreichend:

- **`each()` ist in PHP 8 entfernt.** In `webfrontend/html/index.php` — genau
  der Seite, die Loxone abfragt — steht es dreimal
  (`while (list($k,$v) = each($array))`). PHP 8 wirft dort
  `Call to undefined function each()`. Auf LoxBerry 3.0.0 bis 4.0 laeuft noch
  PHP 7.4, dort geht es gerade noch gut; mit dem Wechsel auf Debian 13
  („Trixie“) und PHP 8 ist die Kernfunktion des Plugins tot.
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
| `bin/ble_scanner_ng.py` | Dienst: Scan, Auswertung, MQTT, HTTP |
| `bin/bl_common.py` | Pfade, Konfiguration, BlueZ-Zugriff |
| `bin/bl_discover.py` | Gerätesuche für die Oberfläche |
| `webfrontend/htmlauth/index.php` | Oberfläche, vier Reiter |
| `webfrontend/htmlauth/bl_lib.php` | Konfiguration, Themen, Loxone-XML |
| `webfrontend/htmlauth/bl_test.php` | Aktionen des Reiters Test |
| `config/ble_scanner_ng.cfg` | Konfiguration |

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

## Aufgeräumt

Die Ordnerstruktur war bereits in Ordnung — keine verschachtelte
`-master`-Kopie, keine doppelten Icons, keine leeren Ordner,
`preinstall.sh`/`preroot.sh` gab es gar nicht erst. Gefunden wurden andere
Rückstände:

- **`uninstall/uninstall` benutzte `pkill -f ble_scanner_ng.py`**, obwohl
  `daemon/daemon` beim Start längst eine PID-Datei unter
  `data/plugins/<Ordner>/dienst.pid` anlegt. `pkill -f` trifft jeden Prozess,
  in dessen Kommandozeile die Zeichenkette irgendwo vorkommt, und schickt das
  Signal an *alle* Treffer — beim Beenden ist das nicht reparabel. Jetzt über
  die vorhandene PID-Datei, mit argumentweiser Gegenprobe über
  `/proc/<pid>/cmdline`. Die PID-Datei wird dabei auch entfernt; das fehlte.
- **Vorlagenreste in den Installationsskripten.** `postinstall.sh`,
  `preupgrade.sh` und `postupgrade.sh` wiesen je acht Pfadvariablen der
  LoxBerry-Vorlage zu und lasen davon eine oder zwei. Die ungenutzten sind
  entfallen (je fünf in den Upgrade-Skripten, sieben in `postinstall.sh`).
- **`mkdir $PLOG`** ohne `-p` und ohne Anführungszeichen meldete bei jeder
  Installation über eine vorhandene hinweg einen Fehler ins Protokoll.
- **`.gitignore`** ergänzt.

### Rückstände einer früheren Textumstellung

Sieben deutsche Texte standen fest in `index.php`, obwohl es für sie
Sprachschlüssel gab oder geben sollte — auf Englisch erschienen sie deutsch:
`läuft`/`läuft nicht`, `anwesend:`, `anwesend`/`abwesend`, `ein`/`aus`,
`(nicht aktiv)`, `· neueste Zeile zuerst` und der Satz `Noch keine
Protokolldatei vorhanden…`.

Zwei Schlüssel waren dabei aus einem maschinellen Durchlauf **zusammengeklebt**:

- `AKTIV_ANWESEND = "aktiv) · anwesend:"` — mit einer verirrten schließenden
  Klammer aus dem umgebenden Satz,
- `NEUESTE_ZEILE_ZUERST_NOCH_KEINE_PR` enthielt **zwei** unabhängige Sätze in
  einem Wert.

Beide sind durch sauber getrennte Schlüssel ersetzt, alle sieben Stellen sind
angeschlossen. 197 Schlüssel je Sprachdatei, deckungsgleich.

*Nicht* angetastet: `ALLGEMEIN.JA/NEIN/SPEICHERN` und `REITER.MQTT` sind
tatsächlich unbenutzt, kosten aber nichts und bleiben als Reserve stehen.

