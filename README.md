# LoxBerry-Plugin BLE-Scanner NG

Version 1.3.12

Erkennt Bluetooth-Low-Energy-Geräte in Reichweite und meldet dem Loxone
Miniserver, ob ein hinterlegter Tag anwesend ist — samt Signalstärke,
Zeitstempel und, wo das Gerät sie mitsendet, Temperatur, Luftfeuchte und
Batteriestand. Typischer Einsatz: Schlüsselanhänger als Anwesenheitserkennung.

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
Kette trotz der Sperre auf. Bis zum nächsten Neustart hält das; **dauerhaft**
geht es über `dietpi-config` → Advanced Options → Bluetooth oder dadurch, dass
die Zeilen aus der genannten Datei verschwinden. Beides gehört dem System, nicht
dem Plugin: `dietpi-config` würde eine Pluginänderung an dieser Datei beim
nächsten Lauf ohnehin überschreiben.

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
1.3.5, während 1.3.8 veröffentlicht war.


## Neu in 1.3.8

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
