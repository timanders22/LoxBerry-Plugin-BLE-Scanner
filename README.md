# LoxBerry-Plugin BLE-Scanner NG

Version 1.3.17

Erkennt Bluetooth-Low-Energy-Geräte in Reichweite und meldet dem Loxone
Miniserver, ob ein hinterlegter Tag anwesend ist — samt Signalstärke,
Zeitstempel und, wo das Gerät sie mitsendet, Temperatur, Luftfeuchte und
Batteriestand. Typischer Einsatz: Schlüsselanhänger als Anwesenheitserkennung.

## Neu in 1.3.17 — der eigene Dienst wird argumentweise erkannt, und `daemon` startet nicht mehr blind

Zwei Fehlerklassen, beide am 18.09.2026 in WSL/Ubuntu an einem Wegwerfbaum
gemessen, nicht am Gerät. Der Prüfstand steht mit README unter
`Pruefung-BLE-Scanner-1.3.17/`; der Dienst ist darin ein Platzhalter, und
**Bluetooth wird dabei nicht angefasst**.

### Wer „unser Dienst" ist, entschied bisher eine Zeichenkette

An sechs Stellen — `uninstall/uninstall`, `preupgrade.sh`, `postinstall.sh`,
`postupgrade.sh` und in der Oberfläche `bl_lib.php` — galt jeder Prozess als
eigener Dienst, in dessen Befehlszeile irgendwo `ble_scanner_ng.py` vorkam.
Das trifft auch einen Editor, ein `tail` auf der Datei, ein `python3 -c` mit
dem Namen im Text und den Dienst einer **zweiten** Installation. Gemessen
wurde nicht der Verdacht, sondern der Schaden:

| Lage | bis 1.3.16 | seit 1.3.17 |
|---|---|---|
| In `dienst.pid` steht die Nummer eines fremden Prozesses | `<INFO> Halte den BLE-Scanner NG an (PID …)` — der fremde Prozess war tot | `<INFO> Kein laufender Dienst gefunden.` — er lebt |
| Ohne PID-Datei, ein `tail` auf der Dienstdatei | derselbe Schaden | er lebt |
| Der Dienst einer zweiten Installation | wurde von der Deinstallation mit beendet | er lebt |
| `bl_dienst_pid()` in der Oberfläche | lieferte die Nummer des fremden Prozesses, und „Dienst anhalten" beendete ihn | liefert 0 |
| **Zwei** eigene Dienste (nach einem Update möglich) | einer blieb stehen | beide werden beendet |
| `postinstall.sh`/`postupgrade.sh`, während nur ein fremder Prozess läuft | „Es läuft schon ein Dienst" — der eigene startete **nicht** | er startet |

Ein Treffer hat jetzt **genau zwei Argumente**: einen Python-Interpreter und
den vollen Dienstpfad **dieser** Installation; dazu muss der Prozess dem
Benutzer `loxberry` gehören. Behandelt werden **alle** Treffer, und vor dem
`kill -9` wird neu gesucht statt angenommen. Gibt es den Benutzer `loxberry`
nicht, wird nicht geraten: die Deinstallation sagt es und lässt den Dienst in
Ruhe.

### `daemon/daemon` startete bedingungslos

Diese Datei läuft beim Systemstart als `root`. Sie fragt jetzt zweierlei,
bevor sie startet:

* **Läuft schon einer?** Argumentweise gesucht, nicht über die PID-Datei —
  die löscht der Installer beim Upgrade zusammen mit dem Datenordner, und ein
  Dienst, der das Update überlebt hat, war danach unsichtbar. Gemessen: zwei
  Prozesse statt einem. Die gefundene Nummer wird in die PID-Datei
  nachgetragen, damit die Oberfläche den Dienst wieder sieht.
* **Läuft gerade eine Aktualisierung?** `preupgrade.sh` legt dazu seit 1.3.14
  die Marke `data/plugins/<ordner>.upgrade_laeuft` mit der Unixzeit an. Ist
  sie höchstens eine Stunde alt, startet `daemon` nicht — sonst liefe der
  Dienst mitten im Update mit der mitgelieferten Vorgabe-Konfiguration an.
  Eine ältere, eine in der Zukunft liegende und eine Marke ohne Zeitpunkt
  gelten nicht; eine abgebrochene Installation darf das Plugin nicht für immer
  stilllegen. **Ist die Uhr nicht lesbar, gilt die Marke** — ein Schutz fällt
  geschlossen aus. Dieselbe Berichtigung in `postinstall.sh`, das bis 1.3.16
  bei stummer Uhr jedes Upgrade für eine Neuinstallation hielt.

`uninstall` räumt die beiden Merker neben dem Datenordner
(`.upgrade_laeuft`, `.lief_vor_update`) jetzt mit weg; bis 1.3.16 blieben sie
auf dem Gerät liegen.

**Was hier bewusst nicht gebaut wurde:** dieses Plugin führt keinen Schalter
„eingeschaltet". Der Knopf *Dienst anhalten* im Reiter *Test* beendet nur den
Prozess — nach dem nächsten Neustart läuft er wieder. Einen solchen Schalter
einzuführen wäre eine neue Funktion und nicht das Nachziehen eines Befundes;
das entscheidet der Betreiber, nicht diese Fassung. Der Zustand steht als
Kommentar in `daemon/daemon`, damit ihn niemand für behoben hält.

Prüfstand: 54 Prüfzeilen in 31 Lagen. Vor den Korrekturen 31 grün und **23
rot**, danach 54 grün und 0 rot. Jede der zwölf Korrekturen wurde einzeln in
einer Kopie zurückgebaut; jedes Mal wurde genau die zugehörige Zeile rot
(12 von 12).

## Neu in 1.3.15 — die Loxone-Vorlage ist reproduzierbar

**Ein Befund an der Neuerung aus 1.3.14, gefunden beim Nachmessen am Gerät —
nachdem 1.3.14 schon veröffentlicht war.** Wer 1.3.14 einsetzt und die Vorlage
im Reiter *Einbindung in Loxone* herunterlädt, bekommt je nach Augenblick einen
anderen Satz virtueller Eingänge. Diese Fassung behebt das.

**Welche Messwerte ein Tag bekommt, entscheidet sein Beaconformat — nicht sein
letztes Paket.** Das ist am 13.09.2026 am Gerät aufgefallen, nachdem die erste
Fassung dieser Änderung schon stand: MiBeacon wechselt die Satzarten (0x1004
nur Temperatur, 0x1006 nur Feuchte, 0x100D beides, 0x100A Batterie), und das
Abbild des Dienstes trägt immer nur das zuletzt Gesehene. In vier Messungen
über 22 Sekunden:

```
16:26:58  Tag1: feuchte,folge,temperatur   Tag2: feuchte,folge,temperatur
16:27:13  Tag1: feuchte,folge              Tag2: feuchte,folge,temperatur
16:27:20  Tag1: feuchte,folge,temperatur   Tag2: folge,temperatur
```

Die Vorlage war damit **nicht reproduzierbar** — zweimal heruntergeladen,
zweimal ein anderer Satz Eingänge, und in Loxone fehlte dann ein Wert. Jetzt
sagt `FORMAT_GROESSEN` in `bin/bl_beacon.py`, was ein Format liefern kann; das
Abbild entscheidet weiterhin, **ob** ein Tag überhaupt Messwerte liefert (ein
Schlüsselanhänger bekommt keine), aber nicht mehr **welche**. Am Gerät
nachgemessen: sechs Läufe in 31 Sekunden, jedes Mal dieselben acht Eingänge —
vorher 6, 6, 6, 5, 4. Eine Prüfzeile hält die Tabelle gegen die
`_sammle()`-Aufrufe der fünf Dekoder, damit sie nicht wegdriftet.

## Neu in 1.3.14 — Messwerte in Loxone

**Das Plugin las Temperatur und Luftfeuchte, aber sie kamen nie am Miniserver
an.** Seit 1.3.13 liest es ein Xiaomi-Thermometer `MJ_HT_V1` (LYWSDCGQ/01ZM)
ab Werk — der Dienst veröffentlicht die Werte auch:

```
blescanner/<Tag>/sensor/temperatur      21.4      flüchtig
blescanner/<Tag>/sensor/feuchte         48.0      flüchtig
blescanner/<Tag>/sensor/batterie        97        flüchtig
```

Nur auf der Loxone-Seite gab es sie nicht. Die Funktion, aus der die Vorlage
`VI_BLE-Scanner-NG.xml` entsteht, kannte `distance`, `battery`, `raum` — und
keinen einzigen Messwert. Wer die Vorlage importierte, bekam **keine Zeile**
für Temperatur oder Luftfeuchte, und die Bausteinliste im Reiter *Einbindung
in Loxone* führte sie ebenso wenig. Nirgends stand, wie die Themen heißen.

**Am schwersten wiegt, dass die Prüfung dazu schwieg.** Das Plugin hält im
Reiter *Test* seine Themenliste gegen die tatsächlichen `_senden()`-Aufrufe im
Quelltext — genau gegen diesen Fall gebaut, und ihr eigener Kommentar
beschreibt ihn wörtlich:

> „Eine Liste, die niemand nachmisst, läuft auseinander — und dann legt die
> Loxone-Vorlage virtuelle Eingänge an, die dauerhaft auf 0 stehen, ohne jede
> Fehlermeldung."

Sie fand `sensor/` auch. Aber `sensor/` stand auf ihrer Ausnahmeliste, unter
Themen, „die in der Anleitung bewusst nur bei eingeschalteter Einstellung
stehen". Für `distance`, `battery` und `raum` stimmt das. Für `sensor/` nicht:
dafür gab es keine Einstellung und keinen Eintrag. **Eine Ausnahme, die einen
echten Fehler stumm schaltet, ist schlimmer als keine Prüfung — sie erzeugt
Vertrauen.**

Was 1.3.14 daraus macht:

* **Die Messwerte stehen in der Vorlage** — mit Grenzen und Einheit
  (−45…90 °C, 0…100 %, 500…1200 hPa, 0…100 %, 500…4500 mV). Es sind **neun**
  Größen, gelesen aus `SENSORTHEMEN` in `bin/bl_beacon.py`.
* **Nur für Tags, die wirklich senden.** Ein Schlüsselanhänger bekäme sonst
  Eingänge, die dauerhaft auf 0 stehen — genau die Karteileichen, gegen die der
  Themenvergleich gebaut ist. Die Vorlage wird also erst vollständig, wenn der
  Sensor einmal gesendet hat; der Reiter sagt das. **Welche** Größen ein Tag
  bekommt, entscheidet seit 1.3.15 sein Beaconformat — siehe den Abschnitt
  darüber.
* **Ein eigener Abschnitt** im Reiter *Einbindung in Loxone* mit allen neun
  Messwerten, Namensvorschlag, Wertebereich und Bedeutung. Bewusst **nicht** in
  der großen Bausteintabelle: deren Einträge verweisen mit ihrer Nummer
  aufeinander (13 Stellen über beide Sprachdateien), eine Zeile in der Mitte
  verschiebt alle folgenden — still, ohne dass etwas rot wird. Messwerte
  brauchen ohnehin keinen Logikbaustein.
* **Die Ausnahme heißt nicht mehr „ignorieren".** Der Themenvergleich fragt
  jetzt zweierlei: darf es heute fehlen (Einstellung aus, Tag hat noch nicht
  gesendet)? *Und*: gibt es überhaupt irgendwo einen Eintrag dafür? Fehlt der,
  meldet er es als vergessenes Thema. Mit dieser Prüfung hätte sich der Mangel
  oben von selbst gemeldet.
* **Eine neue Prüfzeile hält die Grenzen zusammen.** Dieselben Zahlen stehen in
  `bin/bl_beacon.py` (zum Verwerfen unplausibler Werte) und in der Vorlage (als
  Min/Max). Zwei Listen laufen auseinander — in dieser Linie ist das schon
  dreimal passiert. Die Prüfung liest die Python-Datei und vergleicht.

Ein Hinweis für die Praxis: Messwerte gehen **flüchtig** hinaus, nicht
zurückbehalten. Nach einem Neustart des Miniservers steht der Eingang auf 0,
bis der Sensor das nächste Mal sendet — das ist Absicht, sonst zeigte Loxone
nach einem Ausfall einen alten Wert als aktuell.

## Neu in 1.3.14 — drei Befunde aus einem echten Upgrade-Protokoll

1.3.13 ist am 13.09.2026 auf der Entwicklungsanlage über 1.3.11 installiert
worden. Das Installationsprotokoll endete mit `ALLES ERLEDIGT!` — und enthielt
trotzdem drei Befunde. Zwei davon hätte man ohne Protokoll nie bemerkt.

### Der Merker für den Upgrade-Fall hat nie getragen

`preupgrade.sh` legte zwei Merker an: `upgrade_laeuft` (dies ist ein Upgrade,
`postinstall.sh` soll den Dienst also **nicht** starten) und `lief_vor_update`
(der Dienst lief, `postupgrade.sh` soll ihn also wieder starten). Beide lagen
**in** `data/plugins/ble_scanner_ng/` — und genau diesen Ordner räumt der
Installer zwischen `preupgrade.sh` und `postinstall.sh` restlos ab
(`plugininstall.pl`: `&purge_installation` im Upgrade-Zweig, `:886` → `:1629 ff.`).

Im Protokoll steht es Zeile für Zeile:

```
13:00:06  Plugin is already installed -> proceeding with upgrade
13:00:13  removed '.../data/plugins/ble_scanner_ng/upgrade_laeuft'
13:01:16  <INFO> Neuinstallation - der Dienst wird gestartet.
13:01:22  <INFO> Der Dienst lief vor dem Update nicht und wurde nicht gestartet.
```

Derselbe Lauf nennt sich oben ein Upgrade und unten eine Neuinstallation. Zwei
Folgen, beide unerwünscht:

* `postinstall.sh` hielt **jedes** Upgrade für eine Neuinstallation und startete
  den Dienst — auch einen, den jemand bewusst angehalten hatte.
* Der Neustart nach einem Update lief nie an, weil `lief_vor_update` bei
  `postupgrade.sh` ebenso wenig ankam. Damit war der Befund aus 1.3.13 (das
  `su loxberry -c`, das als `loxberry` nicht funktioniert) nur **halb** behoben:
  die Ursache war weg, der auslösende Merker aber auch.

Beide Merker liegen jetzt **neben** dem Ordner (`<ordner>.upgrade_laeuft`,
`<ordner>.lief_vor_update`) — dieselbe Stelle und derselbe Grund wie beim
Sicherungsordner: `rm -rf .../<ordner>/` trifft den Nachbarn mit dem Punkt
nicht. Im Merker steht der Zeitpunkt, nicht nichts; wer ihn liest, prüft sein
Alter (eine Stunde), damit der Rest eines abgebrochenen Upgrades nicht später
eine echte Neuinstallation als Upgrade ausweist. Beidseitig geeicht: der Merker
überlebt das Abräumen, und die Altersprüfung fällt bei leerem, unlesbarem, zu
altem und in der Zukunft liegendem Merker.

### Eine Warnung bei jedem Upgrade, die nicht gelingen konnte

`postupgrade.sh` versuchte `usermod -a -G bluetooth loxberry` und meldete bei
Misserfolg `<WARNING> Gruppenzuordnung bluetooth konnte nicht gesetzt werden.`
Das stand bei jedem Upgrade im Protokoll und war doppelt falsch:

* Es konnte gar nicht gelingen. LoxBerry ruft `postupgrade.sh` mit
  `sudo -n -u loxberry` auf (`plugininstall.pl`, Zeile 1336) — `usermod`
  verlangt root. Dieselbe Klasse wie das `su loxberry -c`, das in 1.3.13 aus
  genau diesem Skript verschwunden ist.
* Die Gruppe wird überhaupt nicht gebraucht. bluez 5.82 liefert
  `/usr/share/dbus-1/system.d/bluetooth.conf` mit `<policy context="default">`,
  und das gilt für jeden Benutzer. `postinstall.sh` misst das seit 1.3.12
  richtig und sagt es im selben Protokoll 20 Zeilen vorher — nur hier war die
  alte, falsche Annahme stehen geblieben.

`postupgrade.sh` prüft jetzt dasselbe wie `postinstall.sh`: die Regel, nicht die
Gruppe. Eine Gruppenmitgliedschaft wird weder gesetzt noch verlangt.

### Die Selbstprüfung behauptete, statt zu messen

Sie führte die Zeile „BlueZ über D-Bus (Suche, RSSI, RemoveDevice)" als nicht
prüfbar und gab als Grund an, es sei „kein Bluetooth-Adapter und kein laufendes
bluetoothd erreichbar". Das war ein **unbedingtes** `p.offen(...)` ohne jede
Messung — und am Gerät nachgemessen falsch: `/org/bluez/hci0` antwortete, neun
Geräte waren sichtbar. Die drei Zeilen daneben begründen sich ehrlich mit
„braucht ein Gerät"; diese eine log.

Neu ist `bluez_ueber_dbus()`: sie fragt den **Dienst**, während `adapterlage()`
den **Kernel** fragt (`/sys/class/bluetooth`). Beides kann auseinanderliegen —
ein angemeldeter Adapter ohne laufendes `bluetoothd` ist genau der Fall, den die
alte Zeile behauptete. Gemessen am Gerät: `org.bluez fuehrt hci0, 9 Geraet(e)
sichtbar.` Beidseitig geeicht, alle drei Zweige erreicht (richtiger Adapter →
Haken, erfundener Adapter → Kreuz, Dienst tot → Strich mit gedeutetem Grund).
Offen bleibt in „Nicht prüfbar ohne Gerät" nur noch, was ohne ein zweites Gerät
wirklich nicht geht.

Die Selbstprüfung zählt damit **119 Fälle, 0 Fehlschläge** (111 bestanden,
8 nicht prüfbar), am Gerät gemessen.

## Neu in 1.3.13

### Xiaomi-Sensoren werden gelesen (MiBeacon, `0xFE95`)

Am 13.09.2026 **am Gerät gemessen**, nachdem das eingebaute Bluetooth des
Raspberry Pi 4 eingeschaltet war: ein *Mi Temperature and Humidity Monitor*
(LYWSDCGQ/01ZM, in der Werbung `MJ_HT_V1`) sendet seine Werte
**unverschlüsselt in der Werbung** — ohne Kopplung, ohne Verbindung.

```
ServiceData 0000fe95 (18 B): 50 20 AA 01 83 FF EE DD CC BB AA 0D 10 04 0A 01 E2 01
                             └───┘ └──┘ └┘ └──────────┘ └───┘ └┘ └──────┘
                             Rahmen Typ  Z   MAC rückw.   Art    L   Werte
→ 26,6 °C / 48,2 %
```

| Bit im Rahmen | Bedeutung |
|---|---|
| `0x0008` | **verschlüsselt** |
| `0x0010` | MAC dabei |
| `0x0020` | Capability-Byte dabei |
| `0x0040` | Werte dabei |

| Satzart | Inhalt |
|---|---|
| `0x1004` | Temperatur, 2 B vorzeichenbehaftet, /10 → °C |
| `0x1006` | Luftfeuchte, 2 B, /10 → % |
| `0x100A` | Batteriestand, 1 B, % |
| `0x100D` | beides, 4 B (Temperatur, dann Feuchte) |

Damit gehen `<tag>/sensor/temperatur`, `…/feuchte`, `…/batterie` und
`…/folge` hinaus — dieselben Themen wie bei ATC/pvvx und RuuviTag, ohne
eine neue Zeile in der Loxone-Vorlage.

**Drei Dinge, die dieser Dekoder bewusst anders macht als die übrigen:**

1. **Er prüft den Absender.** Das Paket trägt die MAC des Geräts, das
   gemessen hat. Stimmt sie nicht mit der Adresse überein, von der das Paket
   kam, wird **nichts** geliefert — sonst schreibt ein Nachbarpaket fremde
   Temperaturen in den eigenen Tag. `deuten()` nimmt dafür ein drittes
   Argument; wer es leer lässt, verzichtet auf die Prüfung, und das steht im
   Quelltext, damit es eine Entscheidung ist und kein Versehen.
2. **Bei einem verschlüsselten Paket schweigt er nicht.** Neuere Xiaomi-Geräte
   (und jedes, das in der Mi-Home-App gebunden wurde) verschlüsseln die Werte
   mit einem Bindungsschlüssel, den dieses Plugin nicht hat. Statt gar nichts
   zu melden, gibt der Dekoder ein Ergebnis mit leeren Werten und dem Hinweis
   `verschluesselt` zurück — so kann die Oberfläche den **Grund** nennen.
3. **Er ist an zwei verschiedenen Satzarten desselben Geräts gemessen**
   (`0x100D` und `0x1004`), nicht nur gegen eine Beschreibung. Ein Dekoder, der
   nur `0x100D` kennt, schweigt bei der Hälfte der Pakete.

Die Eichung (`python3 bin/bl_beacon.py`) prüft **35 Fälle**, darunter drei
Gegenproben, die **rot werden müssen**: ein fremder Absender, ein
verschlüsseltes Paket und ein Paket ohne Wertebit. Beide Richtungen sind
gefahren worden — nimmt man die Absenderprüfung heraus, fällt genau eine
Zeile; verdreht man die Bitmaske (`0x40` statt `0x08` für „verschlüsselt" —
**mein eigener Irrtum beim ersten Versuch**), fallen vier.

### Behoben — die Selbstprüfung ließ ihre Arbeitsordner liegen

Am Gerät gemessen: unter `/tmp` lagen **45 Arbeitsordner** dieser
Selbstprüfung.

| Vorsilbe | Anzahl |
|---|---|
| `ble_selbsttest_` | 35 |
| `ble_cfg_` | 5 |
| `ble_log_` | 5 |

**Neun davon gehörten `loxberry`** — also Läufen über die Oberfläche. Die
Datei rief dreimal `tempfile.mkdtemp()` und kein einziges Mal `rmtree`. Auf dem
LoxBerry liegt `/tmp` auf einer Ramdisk: kein Platzproblem (3,3 MB von 1,9 GB),
aber jeder Knopfdruck hinterließ drei Ordner, und sie blieben bis zum
Neustart.

Jetzt gehen die Ordner dieses Laufs in einem `finally` weg — auch wenn eine
Gruppe mit einer Ausnahme abbricht, was genau der Fall war, der sie
hinterließ. Rückstände **früherer** Läufe räumt die Prüfung selbst ab, mit
drei Wachen: nur die eigenen Vorsilben, nur was dem eigenen Benutzer gehört,
nur älter als eine Stunde — ein Lauf dauert Sekunden, also kann sie keinem
gleichzeitigen Lauf die Arbeit wegnehmen.

Dazu eine neue Prüfgruppe *Eigene Rückstände* mit drei Zeilen, beidseitig
geeicht: macht man `_entfernen()` wirkungslos, werden zwei davon rot. Beim
ersten Lauf auf dem Entwicklungsrechner hat sie **252** eigene Altlasten
abgeräumt.

### Behoben — die Symbole trugen ein C2PA-Manifest

Die fünf Dateien unter `icons/` waren gegenüber 1.3.12 gewachsen: jede PNG um
genau 5 770 Byte, die SVG von 1 880 auf 9 652 Zeichen. Nachgemessen ist der
**Bildinhalt byteweise gleich** (dieselbe IDAT-Prüfsumme); dazugekommen war
allein ein `caBX`-Block in den PNG und ein `<metadata><c2pa:manifest>` in der
SVG — Herkunftsangaben eines Werkzeugs, das die Dateien angefasst hat. Sie
gehören nicht in ein Plugin-Archiv; die sauberen Dateien aus 1.3.12 sind
wieder eingesetzt.

### Berichtigt — zwei Lücken und eine Falschaussage im README

Zu 1.3.10 und 1.3.11 fehlte hier jeder Abschnitt, obwohl beide Tags auf GitHub
stehen; sie sind unten nachgetragen. Umgekehrt stand „Neu in 1.3.8" da, **ohne
dass es diesen Tag gibt** — siehe dort.


## Neu in 1.3.12 — am Gerät gemessen, und der Retain-Hausstandard

Diese Fassung ist am 13.09.2026 **an der Anlage** entstanden: SSH stand, der
Broker stand, und 1.3.11 war soeben installiert. Gemessen wurde am
installierten Stand, nicht am Archiv.

### Retain je Thema (Hausstandard seit 03.09.2026)

Bis 1.3.11 ging **alles** zurückbehalten hinaus — `senden()` hatte
`retain=True` als Vorgabe, und `_senden()` gab nie etwas anderes mit. Damit war
auch `server/ts` retained, also das **Lebenszeichen**: zurückbehalten zeigt es
auf Dauer „lebt", und genau dafür ist es nicht da.

Jetzt entscheidet eine Tabelle je **Themenstamm**: **21 zurückbehalten, 8
flüchtig.**

| Art | Themen | Retain |
|---|---|---|
| Zustände je Tag | `present`, `level`, `name`, `raum` | **ja** |
| Zeitstempel | `last_seen_ts`, `present_since`, `battery_ts`, `raum_seit` | **ja** |
| Batteriestand | `battery` | **ja** |
| Zustände des Dienstes | `server/online`, `server/ok`, `server/adapter_ok`, `server/letzte_sichtung`, `server/version`, `server/scanner` | **ja** |
| Zusammenfassung | `summary/present`, `summary/tags`, `summary/tags_gesamt` | **ja** |
| Personen, zweiter Scannerzweig | `person/present`, `person/last_seen_ts`, `scanner/present` | **ja** |
| Messwerte mit Zeitbezug | `rssi`, `rssi_avg`, `distance`, `sensor/*`, `scanner/rssi` | nein |
| eine Dauer, die von selbst altert | `last_seen` | nein |
| Lebenszeichen | `server/ts` | **nie** |
| regelmäßig leer | `summary/names` | nein |

Drei Regeln, die der Hausstandard ausdrücklich nennt und die hier alle gelten:
entschieden wird je Themenstamm und nicht je Aufruf; ein Thema **ohne** Eintrag
geht flüchtig hinaus; und ein **leerer** Wert geht nie retained hinaus — eine
leere Nutzlast löscht ein zurückbehaltenes Thema. Die Tabelle steht in
`bin/bl_common.py` und in `bl_lib.php`; eine Prüfzeile im Reiter *Test* hält
beide Seiten **und den Sendecode** gegeneinander und wird rot, sobald ein
Eintrag fehlt. Die Thementabelle im Reiter *MQTT* hat jetzt eine Spalte
*Retain*.

*Zur Abwägung bei `battery`:* der Wert entsteht einmal täglich über eine
Verbindung. Ohne Retain fehlte er nach einem Neustart des Miniservers bis zu
24 Stunden, und der Zweck — vor der leeren Knopfzelle warnen — wäre dahin. Als
„zuletzt gültiger Wert" mit eigenem Zeitstempel daneben kann er nicht für
aktuell gehalten werden. Der laufend aus den Werbedaten gelesene
Spannungswert ist dagegen ein Messwert und steht unter `sensor`.

### Behoben — der Dienst lief nach der Installation überhaupt nicht

Am Gerät gemessen, unmittelbar nach der Installation von 1.3.11: **kein
Prozess, keine PID-Datei, kein Abbild, Protokoll 0 Byte.** Zwei Lücken,
unabhängig voneinander:

1. **Der Installer startet den Daemon nie.** Am Quelltext nachgemessen
   (`plugininstall.pl`, Zeilen 1126–1143): er kopiert die Datei, setzt Rechte
   und Eigentümer — und das ist alles. Gestartet wird sie erst beim nächsten
   Systemstart. `postinstall.sh` startet den Dienst jetzt, wenn dies eine
   **Neuinstallation** ist.
2. **Der Neustart nach einem Update hat nie funktioniert.** `postupgrade.sh`
   rief `su loxberry -c` — aber LoxBerry ruft `postupgrade.sh` schon mit
   `sudo -n -u loxberry` auf. Am Gerät gemessen: `su: Authentication failure`,
   Rückgabewert 1; und weil die Zeile ihre Ausgabe umleitete, stand darüber
   nichts im Protokoll. Jetzt ohne `su`, und geprüft wird die **Wirkung**
   (läuft ein Prozess?), nicht der Rückgabewert von `nohup` — der ist immer 0.
   `daemon/daemon` behält sein `su`: das läuft als root.

### Behoben — eine Falschaussage über BlueZ, vier Jahre alt

Das Plugin behauptete an vier Stellen, BlueZ bringe eine Richtlinie für die
Gruppe `bluetooth` mit — gemessen an bluez 5.64. An **bluez 5.82-1.1+rpt2**
nachgemessen: diese Regel gibt es nicht mehr. Stattdessen steht dort

    <policy context="default">
      <allow send_destination="org.bluez"/>
    </policy>

also eine Erlaubnis für **jeden** Benutzer; die Gruppe `bluetooth` existiert
(gid 116) und ist **leer**. Die Installation warnte trotzdem zweimal, die
Gruppenzuordnung genüge nicht — das schickte den Anwender auf die Suche nach
einem Rechteproblem, das es nicht gab. Die Prüfung misst die Richtlinie jetzt
und sagt, was sie findet.

Zur zweiten Warnung desselben Blocks: das `usermod` konnte nie gelingen, weil
`postinstall.sh` **als `loxberry` läuft**. Der Versuch ist entfallen; wo eine
Gruppe wirklich gebraucht wird, nennt das Protokoll den Befehl zum Abtippen —
als `<INFO>`, denn eine fehlende fremde Voraussetzung ist kein Fehler des
Plugins.

### Behoben — der Dienst schwieg, solange er blind war

Solange BlueZ nicht erreichbar ist, versucht `verbinden_mit_geduld()` es alle
30 Sekunden — und die Hauptschleife wurde nie erreicht. Folge: **kein Abbild,
keine MQTT-Meldung.** Am Gerät gemessen: nach 34 Sekunden Laufzeit gab es keine
Zustandsdatei, und in der Selbstprüfung stand bei jeder Zeile, die das Abbild
braucht, ein Strich. Die Oberfläche konnte „läuft, ist aber blind" nicht von
„läuft nicht" unterscheiden.

Jetzt meldet der Dienst die Störung, **bevor** er wartet: `server/ok=0`,
`server/adapter_ok=0`, ein frisches `server/ts` und ein Abbild mit dem Grund im
Klartext. Die zuletzt gemessenen Werte werden dabei **nicht** überschrieben —
ein unerreichbares BlueZ darf nicht „alle abwesend" melden.

### Behoben — 18 von 24 Knöpfen hatten keine Wirkung

Gefunden beim Bauen des neuen Knopfes, am **gerenderten HTML** gemessen, nicht
am Quelltext:

| | 1.3.11 | 1.3.12 |
|---|---|---|
| Formulare | 24 | 24 |
| davon mit Formulartoken | **6** | **24** |

Der Wachposten in `index.php` weist jede Absendung ohne gültiges Token ab, leert
`$_POST` und meldet `WACHE.FEHLT` — richtig so. Nur stand der Aufruf
`<?php echo bl_fmt(); ?>` bei 17 Formularen auf der Zeile **nach** `</form>`.
Ein verstecktes Feld ausserhalb eines Formulars wird nie mitgesendet. Im
Quelltext sieht beides gleich aus, im Browser auch — und danach tut der Knopf
nichts, während die Seite aussieht wie vorher. Betroffen waren *Selbstprüfung*,
*Status*, *Tags*, *Sichtbar*, *Themen*, *Verlauf*, *Bluetooth*, *Konfiguration*,
*Umgebung*, *MQTT-Info*, *Start*, *Neu starten*, *Anhalten*, *Probewert* und
*Batterie* — also praktisch der ganze Reiter *Test*.

Das Token steht jetzt **innerhalb** jedes Formulars, und eine neue Prüfzeile
„Tragen alle Formulare ihr Token?" liest den Quelltext von `index.php` und
zählt. Geeicht, indem ein Token wieder nach draußen gelegt wurde: die Zeile
wird rot und sagt „25 Formulare, 24 mit Token, 1 ohne".

**Warum es so lange unsichtbar war:** der Wirkungstest des Hauses prüft die
Gestalt der Formulare und meldete 25 Absendungen als in Ordnung — er drückt
die Knöpfe nicht gegen den Wachposten. Gefunden wurde es erst, als ein eigener
Prueflauf *nichts* messen konnte: die erste Vermutung war der eigene Prüfstand,
und sie war falsch.

### Behoben — Kleineres, alles gemessen

* **Die Fehlerdeutung kannte die Zeitgrenze nicht.** Ist kein Adapter da,
  antwortet der D-Bus mit `org.freedesktop.DBus.Error.TimedOut` nach 25 s.
  `dbus_fehler_deuten()` kannte nur `AccessDenied`, `ServiceUnknown` und
  `UnknownObject` — der Anwender bekam den rohen D-Bus-Text. Jetzt steht dort
  ein Satz, der sagt, was zu tun ist.
* **Neue Prüfzeile „Ist ein Bluetooth-Adapter vorhanden?"** Sie misst
  `/sys/class/bluetooth` und `systemctl is-active bluetooth` und hängt nicht
  am Abbild des Dienstes. Das ist die Frage vor allen anderen. Fehlt der
  Adapter, geht sie noch einen Schritt weiter und unterscheidet **fehlende
  Hardware** von **gesperrtem Treiber** — im zweiten Fall nennt sie die Datei
  und die Modulnamen. „Kein Adapter vorhanden" schickt sonst jemanden einen
  USB-Stecker kaufen, den er nicht braucht (siehe unten).
* **`bl_selbsttest.py --json` verschmutzte seine eigene Antwort.** Das Modul
  des Dienstes protokollierte nach **stdout**, und `json_decode` scheiterte an
  den vorangestellten Zeilen. Das Protokoll geht jetzt nach stderr (die
  Startskripte leiten `2>&1` um, im Protokoll steht also alles wie vorher), und
  die Oberfläche holt das JSON zeilenweise von hinten.
* **Ein Tag-Alias darf nicht `server`, `summary`, `person`, `scanner` oder
  `sensor` heißen.** Sonst stritte `<alias>/present` mit `summary/present` um
  dieselbe Stelle, und die Retain-Tabelle ordnete das Thema dem falschen Stamm
  zu. Wird abgewiesen und gemeldet.
* **Der Rückfallwert der Fassungsnummer stand seit 1.3.0 auf `1.3.0`.** Im
  Sandkasten meldete der Dienst „BLE-Scanner NG 1.3.0 startet", obwohl 1.3.11
  lief; auf einer Installation fiel es nicht auf, weil `fassung.txt` davorsteht.
  `fassung_setzen.py` kennt diese Konstante nicht und zieht sie nie mit. Jetzt
  steht die `plugin.cfg` davor — die gibt es im ausgepackten Archiv, und sie
  wird gepflegt.
* **Ein toter Sprachschlüssel** (`LOX.S2_TEXT`, seit 1.3.11 durch
  `bl_abo_text()` ersetzt) ist entfallen. 507 Schlüssel je Sprache,
  deckungsgleich.
* **Ein toter Zweig im eigenen Umbau.** Beim Herausnehmen der
  BlueZ-Gruppenbehauptung war der Zweig nur mit `if False:` stillgelegt statt
  entfernt — Quelltext, der dem nächsten Leser weiterhin erzählt, die Gruppe
  sei ein Weg. Jetzt ist er weg.

### Was an dieser Anlage offen bleibt — und woran es wirklich liegt

**Dieser LoxBerry hat Bluetooth, es ist aber abgeschaltet.** Das ist die
Berichtigung einer eigenen Fehlannahme: zuerst sah es nach fehlender Hardware
aus. Nachgemessen am 13.09.2026 ist das Gegenteil der Fall — der Raspberry Pi 4
hat ein eingebautes Bluetooth (CYW43455 am PL011-UART), und es ist vollständig
verdrahtet:

| gemessen | Ergebnis |
|---|---|
| Gerätebaum `/proc/device-tree/soc/serial@7e201000` | `status: okay`, Kindknoten `bluetooth`, `compatible brcm,bcm43438-bt` |
| serdev-Bus | `serial0-0` angemeldet … **aber kein Treiber gebunden** |
| Firmware `/lib/firmware/brcm/BCM4345C0.hcd` | vorhanden |
| `bluetooth.service`, `hciuart.service` | beide `enabled` |
| Module `bluetooth`, `btbcm`, `hci_uart` | als `.ko.xz` vorhanden |
| `/boot/firmware/config.txt` | `dtoverlay=disable-wifi` — **kein** `disable-bt` |
| `/etc/modprobe.d/dietpi-disable_bluetooth.conf` | `blacklist hci_uart, hidp, rfcomm, btbcm, bnep, bluetooth` |

Gehalten wird es also von **einer einzigen Datei**: DietPi hat Bluetooth bei der
Einrichtung abgeschaltet. Das ist eine Absicht des Systembesitzers, keine
Störung — und deshalb nimmt das Plugin sie **nicht** zurück. Was es seit 1.3.12
tut: es **benennt** sie. Die Prüfzeile „Ist ein Bluetooth-Adapter vorhanden?"
unterscheidet jetzt drei Fälle statt einem:

* kein Gerätebaum-Eintrag → hier hilft nur ein USB-Adapter,
* Eintrag vorhanden und eine Blacklist gefunden → **Datei und Modulnamen werden
  genannt**, dazu der Befehl,
* Eintrag vorhanden, keine Blacklist → Hinweis auf `systemctl status bluetooth`.

### Neu: der Knopf „Bluetooth einschalten" im Reiter *Test*

Weil genau zwei Befehle fehlen, gibt es sie jetzt auf Knopfdruck. Der Weg dahin
folgt dem Hausmuster für Rechte (Regeln/06), und zwar genau deshalb, weil der
naheliegende Weg ein Loch wäre:

* `postroot.sh` — das **einzige** Skript dieses Plugins, das als root läuft —
  schreibt einen Helfer nach `/usr/local/sbin/ble_scanner_ng_bluetooth`,
  `root:root`, `0755`.
* `sudoers/sudoers` nennt **genau diesen einen Pfad, ohne Argumente**. LoxBerry
  legt die Datei beim Installieren nach `<home>/system/sudoers/ble_scanner_ng`
  ab — dasselbe Verzeichnis wie `/etc/sudoers.d`, am Gerät über die
  Inode-Nummer nachgemessen — und entfernt sie beim Deinstallieren wieder.
* **Warum nicht einfach ein Skript in `bin/`?** Weil `bin/` `loxberry` gehört.
  Eine sudo-Regel auf eine Datei in einem Verzeichnis, in das derselbe Benutzer
  schreiben darf, ist ein Weg nach Root. Regeln/06 sagt das ausdrücklich; die
  Selbstprüfung hält es fest („die sudo-Regel zeigt nicht in den
  Plugin-Ordner", „… nennt keine Argumente") und wurde beidseitig geeicht.
* Der Helfer ändert **keine Datei unter `/etc`**. Er lädt `hci_uart` (und
  versucht `btusb` für einen eingesteckten Adapter), wartet bis
  `/sys/class/bluetooth` da ist und startet dann `bluetooth.service` — vorher
  wäre der Start wirkungslos und hätte trotzdem Erfolg gemeldet.
* Danach wird **nachgemessen**, nicht gehofft: der Knopf antwortet mit der neuen
  Adapterlage, und wenn der Adapter jetzt da ist, mit dem Hinweis, dass der
  Dienst einmal neu starten muss — er hat beim Anlauf noch keinen gesehen.
* Und er sagt, was er **nicht** kann: es gilt bis zum nächsten Neustart.

Am Gerät gemessen (13.09.2026, der Helfer von Hand als root ausgeführt): von
„kein `/sys/class/bluetooth`" zu `hci0`, `bluetooth.service active`, Rückgabe 0
— danach fand der Suchlauf des Plugins sieben Geräte, darunter einen Marstek
Venus E, eine Grundfos SCALA und ein MJ_HT_V1. **Nicht** gemessen ist der Weg
über die sudo-Regel selbst: das Einspielen von `/etc/sudoers.d/ble_scanner_ng`
und des root-Helfers geschieht bei der Installation, nicht aus einer
Fernsitzung. Die Regel ist mit `visudo -cf` geprüft („parsed OK").

Von Hand geht es weiterhin:

Einschalten, gemessen und nachvollziehbar:

```
sudo modprobe hci_uart          # zieht bluetooth und btbcm mit
sudo systemctl start bluetooth
```

Eine `blacklist`-Zeile verhindert nur das **selbsttätige** Laden, nicht ein
ausdrückliches `modprobe` — `modprobe --show-depends hci_uart` löst die ganze
Kette trotz der Sperre auf. Bis zum nächsten Neustart hält das.

**Dauerhaft** geht es auf einem DietPi mit einem Befehl. Er muss als **root**
laufen — der Benutzer `loxberry` darf ihn nicht, also zuerst `su -`:

```
su -
sudo /boot/dietpi/func/dietpi-set_hardware bluetooth enable
```

Am Gerät gemessen (13.09.2026): entfernt `dtoverlay=disable-bt` aus
`/boot/firmware/config.txt`, stellt `pi-bluetooth` sicher, **löscht
`/etc/modprobe.d/dietpi-disable_bluetooth.conf`**, lädt die Module, hebt eine
`rfkill`-Sperre auf und schaltet `bluetooth.service` sowie — auf einem Pi bis
einschließlich 4 — `hciuart` dauerhaft ein. Danach kommt `hci0` nach jedem
Neustart von selbst.

Wer lieber klickt: `dietpi-config` → *Advanced Options* → *Bluetooth* macht
dasselbe.

Beides gehört dem System, nicht dem Plugin: `dietpi-config` würde eine
Pluginänderung an dieser Datei beim nächsten Lauf ohnehin überschreiben. Die
Datei ist für DietPi nicht nur Wirkung, sondern **Schalterstellung** — im
Quelltext steht an der Stelle, die sie schreibt, `keep as flag for
dietpi-config`. Deshalb ist ein Umbenennen von Hand der schlechtere Weg: es
wirkt, aber DietPi hält Bluetooth danach weiter für abgeschaltet.

## Neu in 1.3.11

**Eine unvollständige Sicherung wird nicht mehr zurückgespielt.**

Bis 1.3.10 war die Vorgabenliste der Ausgangspunkt, und nur was in der
hochgeladenen Datei stand, wurde darüber geschrieben. Eine Sicherung mit einem
**einzigen** Schlüssel lief damit ohne Beanstandung durch, wurde gespeichert —
und alle übrigen Einstellungen fielen auf Werk zurück. Quittiert mit
„1 Wert übernommen".

Gemessen am VolkswagenID-Plugin 0.9.11 am 03.09.2026 unter PHP 7.4 **und** 8.4:
dort fiel dabei auch das Aktionstoken auf `''`, und jede im Miniserver
eingetragene Adresse war **stumm ungültig**. Am 07.09.2026 über den Bestand
ausgerollt (30 Linien), hier mit `TEXT.SICH_FEHLEND`.

Der Hausstandard sagt: eine halb gültige Datei ändert **gar nichts**. Verglichen
wird gegen die **Vorgaben**, nicht gegen die Liste der bekannten Schlüssel —
was ausserhalb der Konfigurationsdatei liegt, fällt nicht auf Werk zurück und
darf fehlen.

Geändert: `webfrontend/htmlauth/bl_lib.php` und beide Sprachdateien. Sonst
nichts.

## Neu in 1.3.10

**paho-mqtt 2.x: die Fassung wird abgetastet, nicht angenommen — und der
Abschied richtig gelesen.**

Am Gerät an paho-mqtt **2.1.0** gemessen (06.09.2026). Zwei Dinge:

* `mqtt.Client(mqtt.CallbackAPIVersion.VERSION1)` schreibt unter 2.x eine
  `DeprecationWarning` in **jedes** Protokoll, und paho 1.x kennt die
  Aufzählung überhaupt nicht. Jetzt wird VERSION2 genommen, wo es sie gibt,
  und auf 1.x sauber zurückgefallen.
* **Die Rückrufe haben je Fassung verschiedene Argumente.** `on_disconnect`
  kommt unter VERSION1 mit `(rc)`, unter VERSION2 mit
  `(flags, rc, properties)`. Wer blind das dritte Argument als Code liest,
  bekommt unter VERSION2 die *DisconnectFlags* — und meldete **jeden sauberen
  Abschied als Abriss**. Der Code wird jetzt aus der richtigen Stelle genommen.

Das betrifft jede Linie, die paho benutzt: auf dieser Anlage stehen 1.6.1 und
2.1.0 **nebeneinander**, je nach Plugin. Geändert: `bin/ble_scanner_ng.py`.
Sonst nichts.

## Neu in 1.3.9

**Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel.**

Am 06.09.2026 an einem laufenden LoxBerry gemessen — aufgefallen am
Heimkino-Plugin, das sieben Stunden lief und keine Protokolldatei hatte:
`log/plugins` liegt auf einer **Ramdisk** (`/dev/zram0`). Wird sie geleert,
ist die Datei fort — und ein `logging.FileHandler`, der sie beim Start
**einmal** geöffnet hat, schreibt bis zum nächsten Neustart in einen
gelöschten Inode. Es gibt keine Fehlermeldung; es gibt gar nichts.

Diese Fassung benutzt deshalb `logging.handlers.WatchedFileHandler`. Der
prüft bei jeder Zeile Gerätenummer und Inode und öffnet nötigenfalls neu; er
steht in der Standardbibliothek und ist für genau diesen Fall gebaut.

Auf dem Gerät geeicht, in beide Richtungen: mit dem alten Handler ist die
Zeile nach dem Löschen verloren, mit dem neuen steht sie in der wieder
angelegten Datei. Auf einem Windows-Arbeitsplatz lässt sich das nicht
messen — dort kann eine offene Datei gar nicht gelöscht werden.

Dieselbe Bauart hatten APC-UPS NG, BLE-Scanner NG, Heimkino und Ultraschall
Entfernung; alle vier sind am selben Tag nachgezogen worden. Über alle
Plugin-Ordner gezählt (06.09.2026) benutzen jetzt genau diese vier den
`WatchedFileHandler`.

**Eine fünfte Stelle ist offen und soll hier benannt sein, statt zu fehlen:**
Skoda Connect NG stand hier zunächst als Ausnahme mit der Begründung, ein Cron
starte das Programm bei jedem Lauf neu. Nachgemessen trifft das nicht zu: der
Cron ruft dort nur `waechter` und `wachzeichen`; der eigentliche Dienst läuft
dauerhaft (`bin/dienst.sh`, `nohup … &`). In diesem Zweig steht ein
`RotatingFileHandler` — der hält ebenfalls einen offenen Deskriptor und öffnet
nur bei seiner **eigenen** Größenrotation neu, nicht wenn die Datei unter ihm
verschwindet. Die Bauart ist dort also dieselbe, nur in anderem Gewand, und
noch nicht behoben.

**Weiter:** die Fassungszeile im Kopf dieser Datei stand noch auf
1.3.5. Hier stand „während 1.3.8 veröffentlicht war" — das ist berichtigt:
veröffentlicht war damals **1.3.7**, einen Tag `v1.3.8` gibt es nicht.


## Neu in 1.3.8 — diese Nummer ist nie veröffentlicht worden

Am 13.09.2026 an der Tag-Liste des Repositoriums gemessen: von `v1.0.0` bis
`v1.3.12` stehen **23 Tags**, und `v1.3.8` ist **nicht** darunter. Der hier
beschriebene Stand hat die Anwender also mit **1.3.9** erreicht. Der Abschnitt
bleibt stehen, weil der Inhalt stimmt — die Überschrift ist kein Datum,
sondern der Ordnername, unter dem gearbeitet wurde.

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 1.3.7 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

## Fassung 1.3.5

**Ein Befund, und er kam aus dem Betrieb.** Beim Einspielen meldet der
Pluginprüfer von LoxBerry:

```
WARNING BLE-Scanner NG: HARDCODED PATH'S: Das Plugin nutzt einen hardkodierten
Pfad zu <LoxBerry-Wurzel> … /uninstall/uninstall
```

In `uninstall/uninstall` stand ein fester Rückfall auf das übliche
Installationsverzeichnis. Es war die einzige Stelle im gesamten Plugin-Bestand
dieses Hauses, die der Prüfer beim Einspielen wirklich meldet — alle übrigen
Fundstellen stehen in Dateien *mit* Endung, und die überspringt er.

Die Wurzel wird jetzt **gesucht statt gesetzt**, nach dem Hausmuster: vom
eigenen Ablageort aufwärts, bis ein Verzeichnis gefunden ist, das
`config/plugins` **und** `data/plugins` trägt — mit **harter Obergrenze von
acht Ebenen**. Die Grenze ist kein Beiwerk: ohne sie läuft die Suche bis zur
Wurzel des Dateisystems durch und nimmt das erste Verzeichnis, das die beiden
Namen zufällig trägt. Auf dem Entwicklungsrechner war das das Laufwerk selbst.

**Und ein zweiter Befund kam beim Lesen dazu:** die Datei führte *zwei*
Wurzelvariablen. `BASE` oben mit dem harten Rückfall, und ganz am Ende ein
eigenes `UN_BASE` ohne jeden — dazu ein eigenes `UN_FOLDER` ohne den
Vorgabewert, den `PDIR` weiter oben längst hatte. Fehlten die Argumente, tat
der letzte Block still nichts, während der Rest der Datei arbeitete. Zwei
Wahrheiten über dieselbe Sache in einer Datei; jetzt ist es eine.

**Fail closed:** findet die Suche nichts, wird **nichts gelöscht**, sondern
genannt, was liegenbleibt. Das ist hier nicht gleichgültig — in der
Zweitschrift stehen MAC-Adressen und die Klarnamen der überwachten Personen.

Geeicht in vier Lagen: mit den Argumenten des Installers, nur über
`LBHOMEDIR`, ohne beides mit dem Skript im Baum, und als Gegenprobe außerhalb
jedes Baums — dort räumt es nichts weg und sagt es.

Sonst ist 1.3.5 inhaltlich gleich 1.3.4.

> **Zu den Fassungen 1.3.1 bis 1.3.4** steht in dieser Datei nichts; die
> Kopfzeile war zwei Fassungen lang auf 1.3.2 stehengeblieben. Die Anmerkungen
> dazu stehen auf den Release-Seiten des Repositories — nachgesehen, alle vier
> tragen einen Text. Sie werden hier nicht nacherzählt.

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
* **Werbedaten dekodieren**: iBeacon, Eddystone (UID/URL/TLM), ATC/pvvx,
  RuuviTag und — seit 1.3.13 — MiBeacon (Xiaomi/Mijia, `0xFE95`). Ein
  Xiaomi-Thermometer `MJ_HT_V1` liefert damit Temperatur und Luftfeuchte je
  Raum, ohne WLAN und ohne Cloud, und **ab Werk**: die freie Firmware ist nur
  noch für die neueren, verschlüsselt sendenden Geräte nötig. Jede Dekodierung
  prüft Länge und Plausibilität; passt etwas nicht, wird **nichts**
  veröffentlicht. Wohin die Werte in Loxone gehen, steht im Reiter
  *Einbindung in Loxone* und weiter unten unter „Messwerte in Loxone".
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
| `postroot.sh` | läuft als root; legt den Bluetooth-Helfer in `/usr/local/sbin` ab |
| `sudoers/sudoers` | sudo-Regel auf genau diesen Helfer, ohne Argumente |

Die Sprachdateien werden aus **einer** Tabelle erzeugt:
`Werkzeuge/ble_sprache_erzeugen.py`. Wer einen Text ändert, ändert ihn dort.

## Voraussetzungen

- LoxBerry ab 3.0.0
- Ein Bluetooth-Adapter mit BLE-Unterstützung. Beim Raspberry Pi 3, 4 und 5 ist er eingebaut — er kann aber abgeschaltet sein (DietPi tut das bei der Einrichtung). Der Reiter *Test* sagt, welcher der beiden Fälle vorliegt, und hat einen Knopf zum Einschalten.
- Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems (System →
  MQTT Gateway) und muss auf Autostart stehen

## Bekannte Grenze

Mobiltelefone, Uhren und Kopfhörer wechseln ihre Bluetooth-Adresse regelmäßig
und taugen deshalb nicht zur Anwesenheitserkennung. Seit 1.3.0 muss man das
nicht mehr ausprobieren: die Fundliste sagt es. Wer ein Telefon trotzdem
einbinden will, lässt es als iBeacon werben und trägt die Kennung
`ib:UUID:major:minor` ein — die bleibt stabil.

## Was nicht am Gerät gemessen ist

Ehrlichkeitshalber, weil es den Umgang mit Fehlerberichten erleichtert.
**Seit 1.3.13 ist ein Teil davon erledigt:** am 13.09.2026 lief diese Linie zum
ersten Mal an einem LoxBerry mit eingeschaltetem Bluetooth. Gemessen wurden der
Suchlauf (sieben Geräte), die Adapterlage und der MiBeacon-Dekoder an einem
echten LYWSDCGQ/01ZM, an **zwei** verschiedenen Satzarten.

Offen bleibt:

* wie dicht `PropertiesChanged` tatsächlich feuert (davon hängt die Breite des
  Glättungsfensters ab; mit `dbus-monitor` zu messen),
* ob `org.bluez.Battery1` in der BlueZ-Fassung des LoxBerry aktiv ist — dafür
  fehlt ein verbindungsfähiges Gerät mit Batteriedienst,
* die Anordnung der ATC/pvvx- und RuuviTag-Nutzdaten an einem echten Sensor;
  diese drei Dekoder sind weiter nur gegen ihre Formatbeschreibung geprüft,
* ob ein verschlüsseltes MiBeacon-Paket richtig als solches erkannt wird —
  geprüft ist das an einem **gebauten** Paket, nicht an einem gebundenen Gerät.

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
