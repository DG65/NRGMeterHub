# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](https://github.com/DG65/NRGMeterHub/blob/beta/CHANGELOG-Archiv.md)

## 0.42.1 (2026-11-20)

- **Neu (Schätzzähler): Forum-Hinweis mit Verknüpfung.** Im Formular von MeterHubEstimate führt der Hinweis „Feedback im Symcon-Forum“ jetzt mit einer Schaltfläche direkt zum Thread des Schätzzählers (Rückmeldungen zu Geräten und zur Genauigkeit der Schätzung sind dort willkommen). Sonst ändert sich nichts.
- **Prüfung:** `test-estimate.php` prüft die Verknüpfung im Formular, zwei neue Mutationsproben in `mutate-estimate.php`.

## 0.42.0 (2026-11-20)

- **Neu (Vertrag): Selbstbeschreibung `contractType: 'meter'`, Vertragsversion 1.6.** `MHUB_GetFunctions` (MeterHub), `MHUBV_GetFunctions` (virtueller Zähler) und `MHUBE_GetFunctions` (Schätzzähler) tragen jetzt `contractType: 'meter'` auf Instanz-Ebene und in jeder Zuordnung (`assignments`). Damit erkennen Konsumenten wie das EMS einen Zähler-Vertrag, auch wenn sie das Präfix oder die Modul-GUID nicht kennen (Verbundregel „Fremdanbindung“, SUITE.md). Rein additiv: nichts Bestehendes ändert sich, auch das Feld `meter` (Zählerart, z. B. `'virtual'`) bleibt, wie es war, und ist ausdrücklich nicht der Vertragstyp.
- **Neu: Anleitung „Eigenes Zähler-Modul an den NRG-Stack anbinden“** (`EIGENES_ZAEHLER_MODUL.md`, englisch `EIGENES_ZAEHLER_MODUL.en.md`) für Entwickler fremder Zähler-Module: Pflichtfelder, Vorzeichen-Konvention, kumulative Zählerstände, geschätzte Werte (`measured: false` wird nie geregelt), Frische, Beispiel und Checkliste.
- **Prüfung:** Die Vertragsprüfungen in `test-auto-backfill.php`, `test-virtual.php`, `test-phasecurrent.php` und `test-estimate.php` erwarten 1.6 und prüfen `contractType` an Instanz und Zuordnung (und dass `meter` unverändert die Zählerart bleibt). Ein Seriennummernfeld `deviceSerial` liefert MeterHub bewusst nicht: die Zähler kennen ihre Seriennummer nicht, erfunden wird sie nicht.

## 0.41.0 (2026-11-20)

- **Neu: Modul MeterHubEstimate („Schätzzähler“).** Schätzt Leistung (W) und Energie (kWh) von Geräten ohne eigene Messung — Lampen, Unterputzaktoren, Zwischenstecker, Lautsprecher, Router. Betriebsarten: feste Leistung (an/Standby), linear aus einer Stufe (mit Kurve) oder ein Modell aus der Powercalc-Gerätedatenbank (Messtabellen für Helligkeit, Farbtemperatur und Farbe; Suche nach Hersteller/Modell, Laden auf Klick von GitHub, danach lokal gespeichert). Der Energiezähler läuft ereignisgesteuert mit der jeweils geltenden Leistung; Lücken über fünf Takte (mindestens fünf Minuten) werden nicht überbrückt. Der Vertrag `MHUBE_GetFunctions` entspricht den anderen Quellen, trägt aber `measured: false` und `energyMeasured: false`. Die Archivierung schaltet das Modul nicht selbst ein.
- **Prüfung:** `test-estimate-engine.php` (Rechenkern gegen die Python-Umsetzung des Originalcodes von Powercalc auf denselben Messtabellen: 800 Messtabellen- und 120 Linear-Fälle), `test-estimate.php` (Modul im nachgebauten Symcon, Datenbank-Anbindung gegen lokalen Testserver, Vertrag, Formular, Archiv, deutsche Oberfläche), 94 Mutationsproben in `.tools/ref/mutate-estimate.php`. Die Datenbank-Anbindung lief zusätzlich einmal gegen das echte GitHub-Verzeichnis. An einer laufenden Symcon-Anlage noch nicht erprobt.
- **Hinweis zur Quelle:** Rechenverfahren und Datenbank stammen aus Powercalc für Home Assistant (Bram Gerritsen, MIT-Lizenz); das Modul liefert keine Daten der Datenbank mit.

## 0.40.3 (2026-11-20)

- **Fix (MQTT-Brücke für Shelly): Der Status der Brücke folgt jetzt dem MQTT-Server.** Bisher setzte die Brücke ihren Status (aktiv oder „Kein aktiver MQTT-Server verbunden.“) nur einmal in `ApplyChanges()`. Nach einem Symcon-Neustart oder -Absturz ist der MQTT-Server oft noch nicht aktiv, wenn die Brücke startet; sie stand dann auf „kein aktiver MQTT-Server“ und blieb dort, obwohl längst Nachrichten des Shelly ankamen (Forum, Dirk, 20.11.2026: die Meldung erschien innerhalb der Zeitspanne, in der der Shelly als zuletzt verbunden stand; erst nach dem Update, das die Brücke neu lud, war sie wieder aktiv). Jetzt meldet sich die Brücke für die Statusmeldungen des Servers an (`IM_CHANGESTATUS`) und setzt 102 oder 104 neu, sobald er aktiv oder inaktiv wird, und noch einmal, wenn der Kern nach dem Start bereit ist (`KR_READY`). Beim Wechsel auf einen anderen MQTT-Server wird die alte Anmeldung gelöst. Die Werte selbst liefen schon vorher, geändert ist nur die Statusanzeige.
- **Prüfung:** `test-shelly-mqtt.php` Abschnitt 7 (Start mit noch nicht aktivem Server, Server wird aktiv und wieder inaktiv, fremder Absender, Kernel bereit, Serverwechsel, kein Server, gelöschte Instanz); sieben Mutationsproben in `mutate-shared.php` (alle erkannt; die erste Fassung ließ die Probe „gelöschte Instanz“ durch, der Test setzt jetzt den beobachteten Server).

## 0.40.2 (2026-11-18)

- **Fix (virtueller Zähler): Bezug und Einspeisung laufen nie rückwärts.** Ein virtueller Zähler, der Zähler abzieht (z. B. „Hausverbrauch ohne Wallboxen“: Hausanschluss minus Wärmepumpe, Herd, Wallboxen), ist eine Differenz mehrerer Zähler, die zu leicht verschiedenen Zeiten aktualisieren. Der Stand wippt deshalb um wenige Wh auf und ab. Symcons Archiv-Aggregation „Zähler“ summiert nur die Anstiege; jeder Rückgang wurde beim nächsten Anstieg ein zweites Mal gezählt. Folge: Tages-, Monats- und Jahreswerte kamen etwa doppelt so hoch heraus (Privat am 18.11.2026: 16,9 statt 8,9 kWh; gestern 21,7 statt 11,3; vorgestern 37,7 statt 25,9). Jetzt gilt: Fällt die berechnete Summe unter den zuletzt ausgegebenen Stand, bleibt dieser stehen, bis die Summe ihn wieder übersteigt. Der Stand weicht dadurch höchstens um die Größe des Wippens (wenige Wh) nach oben von der reinen Summe ab. Ein negativer Anfangsstand ist gültig, beim ersten Lauf wird nichts gehalten. Zusammensetzungswechsel (Stetigkeit) und Zählertausch eines Mitglieds (Rückschrittschutz) laufen davor und bleiben unverändert. Schalter im Panel „Berechnung“ (Standard: an). **Werte, die schon im Archiv stehen, bleiben, wie sie waren**; die Rohwerte im Archiv sind richtig (nur das Wippen), die daraus berechneten Tages- und Monatswerte bleiben zu hoch, bis die Rohwerte monoton neu geschrieben werden. Ein fertiges Werkzeug dafür gibt es nicht.
- **Prüfung:** `test-virtual.php` um 2f und 2g erweitert (reine Rechnung mit Randwerten, Durchlauf mit Wippen nach unten, Schalter aus, Leistung nie gehalten, frische Instanz mit negativer Summe); fünf Mutationsproben in `mutate-shared.php`.

## 0.40.1 (2026-11-18)

- **Fix (virtueller Zähler): Die Stetigkeit der Zählerstände galt nur für positive Stände.** Ändert sich die Zusammensetzung (ein Mitglied kommt hinzu oder fällt weg, eine Quelle wird umgehängt), gleicht der virtuelle Zähler den Unterschied aus, damit Bezug und Einspeisung nahtlos weiterlaufen. Das tat er bisher nur bei einem Ausgabewert über 0. Stand die Summe im Minus (z. B. „Hausverbrauch ohne Wallboxen“: die Lebenszeit-Zähler der Abzüge sind größer als der Hauszähler), sprang der Stand beim Umhängen zweier Wallbox-Zähler um 403,9 kWh und legte einen Sprung ins Archiv. Jetzt gilt nur ein Stand von genau 0 als „noch kein Ausgabewert“.
- **Prüfung:** `test-virtual.php` Block 40 um drei Fälle mit negativen Ständen erweitert, zwei neue Mutationsproben in `mutate-shared.php`.

## 0.40.0 (2026-11-18)

Zwei Änderungen aus dem Fund von EMS an Dietmars Privat-System (Symcon-Energie-Dashboard, 18.11.2026).

- **Neu (virtueller Zähler): Rückschrittschutz für Mitgliedszähler.** Ein kumulativer Zählerstand läuft nie rückwärts. Meldet ein Mitglied einen niedrigeren Stand als zuletzt, wird der letzte Stand gehalten, statt den Rückgang in die Summe zu rechnen. Bleibt der Stand 6 Stunden lang ununterbrochen niedriger, gilt der Zähler als getauscht oder zurückgesetzt, und die Summe läuft ohne Sprung weiter (der Verbrauch dieser Stunden geht dabei verloren). Anlass: ein Z-Wave-Zähler fiel jede Stunde von 0,01 auf 0 und zurück, die Summe und das Archiv rechneten daraus 0,14 kWh Verbrauch am Tag. Der Schutz gilt nur für Zählerstände (Bezug, Einspeisung), nie für die Leistung, und ist im Panel „Berechnung“ abschaltbar (Standard: an), z. B. für ein Mitglied, das absichtlich neu startet (Tageszähler). Er ändert die Rechnung bestehender Instanzen nur, wenn ein Mitglied tatsächlich rückwärts springt.
- **Neu (MeterHub und MeterHubVirtual): nach einem Wechsel des Archiv-Aggregationstyps wird die Variable neu aggregiert.** MeterHub setzt bei jedem Übernehmen den Typ „Zähler“ (Bezug, Einspeisung) bzw. „Standard“ (Leistung) für archivierte Variablen. Stand der Typ vorher anders, blieb die Zusammenfassung des Archivs leer oder falsch. Jetzt wird nur bei einer echten Änderung neu aggregiert, nie bei jedem Übernehmen. Läuft gerade eine andere Aggregation, kommt die Variable in eine Warteschlange und wird im nächsten Takt nachgeholt (die Warteschlange stand schon im Hauptmodul und ist jetzt für beide Module gemeinsam).
- **Prüfung:** `test-virtual.php` um 2a bis 2e erweitert (Schutz als reine Rechnung mit Randwerten und im Durchlauf, Neuaggregation nur bei Typwechsel, Warteschlange). 14 neue Mutationsproben in `mutate-shared.php` (jetzt 52). Die Hinweise im „Neu in“-Panel des virtuellen Zählers nennen den Schutz (NEWS_VERSION 0.40.0).

## 0.39.9 (2026-11-18)

Interner Umbau, kein Verhalten geändert (Umbau 5 der Code-Bestandsaufnahme vom 18.11.2026): Der Rest der Formularbausteine, die in MeterHub, MeterHubVirtual und MeterHubDiscovery bisher fast gleich standen, liegt jetzt ebenfalls in `libs/MeterHubShared.php`.

- **Zusätzlich in `MHUB_SharedForm`:** `layoutPanelName`, `LayoutPanel` (Panel „Formular: Aufbau“), `PropagateDismiss` und `AdoptDismissFromSibling` (Ausblenden von Hinweisen über Geschwister-Instanzen). **In `MHUB_SharedInstance`:** `CompactionPlan` (Verdichtungsplan, in MeterHub und MeterHubVirtual Zeichen für Zeichen gleich).
- Was sich zwischen den Modulen unterschied, war nur der Name der Übersetzungsklasse, das Präfix der öffentlichen Funktionen (`MHUB`, `MHUBV`, `MHUBD`) und die GUID der Geschwister-Instanzen. Das liefert jede Klasse über drei kleine Haken (`txt()`, `fnPrefix()`, `siblingModuleGuid()`); die Bausteine sind als `abstract private` deklariert, eine Klasse ohne die Haken lässt sich nicht laden. Eine neue Verbundregel für diese Panels ändert man jetzt an einer Stelle.
- Bewusst nicht zusammengelegt: Bausteine mit eigenem Text je Modul (`ForumHint`, `NewsBanner`, `PurposeIntro`, `ArchivingPanel`, `CompactionFields`) und alles mit unterschiedlicher Logik (`ArchivingMissing`, `EnableArchiving`).
- **Prüfung:** `test-shared.php` deckt die verschobenen Bausteine mit den Haken ab (Panel-Aufbau, Weitergabe und Übernahme des Ausblendens, Verdichtungsplan an den Intervallgrenzen); `test-i18n.php` erkennt Texte über `$this->txt(…)`. 13 weitere Mutationsproben in `mutate-shared.php` (jetzt 38). Die erste Fassung ließ eine durch (die Abkürzung in `AdoptDismissFromSibling`, die ohne sichtbaren Unterschied im Ergebnis bleibt); der Test prüft jetzt, dass in dem Fall kein Nachbar gefragt wird.

## 0.39.8 (2026-11-18)

Interner Umbau, kein Verhalten geändert (Umbau 4 der Code-Bestandsaufnahme vom 18.11.2026): Die Entscheidung „zu welcher Art gehört dieser Zählertyp“ fällt jetzt an einer Stelle.

- Bisher stand an 30 Stellen in der Hauptklasse und in den ausgelagerten Dateien ein eigener Vergleich gegen die Gruppenlisten (Cloud, HTTP, MQTT, schreibend, einphasig, Gültigkeitszeit), oft mit dem Zählertyp frisch aus den Einstellungen gelesen. Jetzt gibt es dafür je eine Methode (`IsCloudMeter()`, `IsHttpMeter()`, `IsMqttMeter()`, `IsWritableMeter()`, `HasRpcValidTime()`, `IsSinglePhaseType()`), die alle Aufrufer fragen. Ein neuer Zählertyp wird nur noch in der passenden Liste eingetragen.
- **Prüfung:** Neuer Prüfstand `test-meter-groups.php` nagelt für jeden der 30 Zählertypen aus der Treiberliste fest, zu welcher Gruppe er gehört, und prüft das Umschalten im Formular (Sollwert-Panel, Gültigkeitszeit, Messmodus), die Cloud-Weichen im Archivpfad und die Fehlertexte des Verbindungstests. Zehn Mutationsproben auf die Methoden selbst und `.tools/ref/mutate-weichen-stellen.php`: Dort wird an jeder der 30 Aufrufstellen die Antwort umgedreht, und jede Stelle lässt mindestens einen Prüfstand rot werden. Beim ersten Lauf waren acht Stellen ungeprüft (Messmodus beim Umschalten, Sollwert-Panel, Verbindungstest, Richtungsprüfung der Cloud-Zähler, Diagnose-Knopf, Archivstatus); sie sind jetzt abgedeckt.
- Die MQTT-Brücke führt weiter ihre eigene Liste der MQTT-Zählertypen, weil sie ein eigenes Modul ist.

## 0.39.7 (2026-11-18)

Interner Umbau, kein Verhalten geändert (Umbau 3 der Code-Bestandsaufnahme vom 18.11.2026): Gleicher Code, der bisher mehrfach im Repository stand, liegt jetzt einmal in `libs/MeterHubShared.php` und wird von dort eingebunden.

- **Modbus-Umwandlung (`MHUB_ModbusCodec`):** Die acht Funktionen, die Registerwerte in Zahlen umrechnen (`u16`, `u32`, `s32`, `u32sw`, `s32sw`, `readFloat32`, `readDouble64`, `packFloat32`), standen in den beiden Modbus-Clients (direkt und Gateway) Zeichen für Zeichen doppelt. Jetzt gibt es sie einmal.
- **Formularbausteine (`MHUB_SharedForm`):** Aufbau der Fachpanels (`formLayout`, `ApplyFormLayout`) und der Lizenzhinweis kommen für MeterHub, MeterHubVirtual und MeterHubDiscovery aus derselben Stelle.
- **Instanzbausteine (`MHUB_SharedInstance`):** Bereitschaftsprüfung beim Neuladen, Archiv-Instanz, Merker der Archiv-Verdichtung und deren Auswahlliste für MeterHub und MeterHubVirtual.
- Insgesamt stehen rund 250 Zeilen weniger im Repository. Öffentliche Funktionen (`MHUB_AckNews()` usw.) bleiben in den Klassen, damit die von Symcon erzeugten Funktionen unverändert sind. Bausteine, bei denen sich die Module nur im Klassen- oder Funktionspräfix unterscheiden (z. B. `ForumHint`, `LayoutPanel`), bleiben vorerst getrennt.
- **Prüfung:** Kein Code verloren (Zeilenvergleich), alle 19 Prüfstände unverändert grün, die bisherigen Mutationsproben unverändert erkannt. Neu: der Prüfstand `.tools/test-shared.php` (Randwerte der Umwandlungen, Aufbau, Bereitschaft) und `.tools/ref/mutate-shared.php` mit 24 Proben auf die gemeinsame Datei, die in einer Kopie der ganzen Arbeitsstruktur laufen. Die erste Fassung der Proben ließ neun durch, vor allem die Umwandlungen mit vertauschter Wortreihenfolge; der neue Prüfstand schließt diese Lücken.

## 0.39.6 (2026-11-18)

Interner Umbau, kein Verhalten geändert (Umbau 2 der Code-Bestandsaufnahme vom 18.11.2026, zweiter Teil): Die Entscheidung des Haken „Bezug/Einspeisung vertauscht“ (`PowerInvert`) fällt jetzt an **einer** Stelle.

- Bisher fragten fünf Stellen die Einstellung selbst ab (Live-Wert, Hochrechnung, Archiv-Nachtrag, Archiv-Reparatur, Zielwahl der Zählerregister). Fehlte in einer das Vorzeichen oder die Umleitung, mischten sich Live- und Nachtragswerte (so geschehen am 17.11.2026, 0.38.6).
- Neu: `PowerSign()` liefert das Vorzeichen (−1 bei gesetztem Haken, sonst +1), `EnergyIdentForInvert()` das Ziel. Alle Pfade fragen nur noch diese beiden Methoden.
- **Prüfung:** Der Prüfstand `test-powerinvert.php` deckt jetzt auch die Hochrechnung aus der Leistung mit gesetztem Haken ab, die vorher ungeprüft war. Sieben Mutationsproben (`.tools/ref/mutate-invert.php`, dazu die bestehende Probe der Archiv-Reparatur) schlagen alle an; `test-backfill-invert.php` liest dafür den Modulpfad aus der Umgebung.

## 0.39.5 (2026-11-18)

Interner Umbau, kein Verhalten geändert (Umbau 2 der Code-Bestandsaufnahme vom 18.11.2026, erster Teil): Zwei große Methodengruppen sind aus der Klasse `MeterHub` in Dateien unter `libs/` gewandert.

- **`libs/MeterHubInexogy.php`** (Trait `MHUB_InexogyCloud`, 19 Methoden, 731 Zeilen): Anmeldung und automatische Neuanmeldung über einen Tresor, Archiv-Nachtrag der Inexogy-Viertelstundenwerte samt Wasserstand.
- **`libs/MeterHubArchive.php`** (Trait `MHUB_ArchiveRepair`, 47 Methoden, 1.618 Zeilen): Zählerschutz, Energie-Archiv prüfen/reparieren, Richtung im Archiv, Live-Zwischenwerte bereinigen, Diagnose und Rollenprüfung.
- `MeterHub/module.php` sinkt von 9.853 auf 7.537 Zeilen und bindet die beiden Traits ein. Die Methoden stehen **wörtlich** so da wie vorher (Prüfung: keine Zeile des alten Stands fehlt, nur die
  Trait-Köpfe und die zwei `require_once`/`use`-Zeilen sind neu). Öffentliche Funktionen und Formular-Handler bleiben in der Klasse, damit die von Symcon erzeugten `MHUB_…`-Funktionen unverändert sind.
- **Prüfstände:** Alle 18 laufen unverändert grün gegen den Umbau, die Referenzdateien blieben unberührt. Prüfstände, die den Quelltext lesen, und die Mutationsproben kennen die neue Aufteilung; dabei wurden
  zwei Lücken der Gerätesuche-Prüfung geschlossen, die vorher von einem Namensunterschied der Prüfdatei verdeckt waren (Carlo-Gavazzi-Frequenzgrenze, Logger-Typ nur unter Adresse 97).

## 0.39.4 (2026-11-18)

Gerätesuche: Zeitgrenzen mit Nachkommaanteil werden nicht mehr abgeschnitten und erzeugen keine PHP-Meldung mehr (Fund 5 der Code-Bestandsaufnahme vom 18.11.2026).

- **Vorher:** `modbusRead()` und `probeUnitResponds()` übergaben die Zeitgrenze (z. B. 1,5 s bei den blue'Log-Textproben) als Fließkommazahl an `stream_set_timeout()`, das die Sekunden als Ganzzahl erwartet. PHP ab 8.1
  meldet dabei „Implicit conversion from float 1.5 to int loses precision“ (zweimal je blue'Log-Gerät in der Suche), und die Zeitgrenze des Sockets wurde auf 1 s abgeschnitten.
- **Jetzt:** Die Zeitgrenze wird in Sekunden und Mikrosekunden gesetzt (`setSocketTimeout()`): 1,5 s gelten voll, die Meldung entfällt.
- **Prüfstand:** `test-ref-suche.php` (Server, der annimmt und nie antwortet: 1,5 s statt 1,0 s Wartezeit, keine PHP-Meldung); gegen 0.39.3 schlägt der Fall an; eine Mutationsprobe. In der Referenz fallen nur die beiden Meldungen weg, sonst ändert sich nichts.

## 0.39.3 (2026-11-18)

Gerätesuche: Die parallele blue'Log-Probe dreht nicht mehr mit voller CPU, wenn die Gegenseite die Verbindung schließt (Fund 4 der Code-Bestandsaufnahme vom 18.11.2026).

- **Vorher:** `probeManyParallel()` öffnet mehrere Verbindungen gleichzeitig und liest, bis je Verbindung 9 Byte da sind oder die Zeitgrenze (0,7 s) abläuft. Schließt die Gegenseite eine
  Verbindung (ein Gerät, das eine unbekannte Unit-ID nicht beantwortet, sondern die Verbindung beendet), bleibt der Socket für `stream_select()` dauerhaft „lesbar“, der Puffer
  bleibt aber leer. Die Schleife lief dann bis zur Zeitgrenze ohne zu warten mit voller CPU, bei jeder Runde der blue'Log-Erkennung.
- **Jetzt:** Wer die Verbindung schließt, wird erkannt (`feof`) und aus der Lesemenge genommen; ist keine Verbindung mehr offen, endet die Probe sofort und gilt wie bisher als „keine Antwort“. Antwortende
  Verbindungen werden unverändert ausgewertet.
- **Prüfstand:** `test-ref-suche.php` (drei geschlossene Unit-IDs, dazu gemischt mit einer antwortenden): gegen 0.39.2 0,7 s Wartezeit und 0,7 s CPU, jetzt unter 0,05 s; zwei Mutationsproben.

## 0.39.2 (2026-11-18)

Gerätesuche: Eine Modbus-Exception lässt die Suche nicht mehr bis zum Ende der Zeitgrenze warten (Fund 3 der Code-Bestandsaufnahme vom 18.11.2026).

- **Vorher:** Antwortet ein Gerät auf eine Probeabfrage mit einer Modbus-Exception (9 Byte: Kopf, Funktionscode mit gesetztem Bit 7, Fehlercode), erwartete `modbusRead()` zusätzlich
  zwei Datenbyte, die nie kommen, und las bis zum Ablauf der Zeitgrenze (1,0 s bei den Zählerproben, 1,5 s bei den Textproben des blue'Log). Jede Probe an einer
  Adresse, die ein Gerät nicht kennt, kostete deshalb die volle Zeit: ein Eastron wurde nach rund 8 Sekunden erkannt, weil sechs vorherige Proben je eine Sekunde warteten.
- **Jetzt:** Die Exception wird an Bit 7 des Funktionscodes erkannt, die Abfrage endet sofort und gilt wie bisher als „keine Antwort“. Das Ergebnis der Suche ändert sich nicht, sie wird schneller.
- **Prüfstand:** `test-ref-suche.php` (Modbus-Server mit offen bleibender Verbindung nach der Exception, wie ein echtes Gerät) und eine Mutationsprobe; gegen 0.39.1 dauern die zwei Proben 2 s, jetzt unter 0,1 s.

## 0.39.1 (2026-11-18)

Janitza (klassische Registerkarte): Das Drehfeld („Phasenfolge“, `phase_seq`) wird bei einem nicht verfügbaren Wert nicht mehr geschrieben (Fund 2 der Code-Bestandsaufnahme vom 18.11.2026).

- **Vorher:** Lieferte ein Gerät dort `0xFFFFFFFF` (NaN, „nicht verfügbar“), wandelte der Treiber den Wert mit `(int)round(...)` in eine Ganzzahl. PHP 8 meldet dabei „The float NAN is not representable as an int“ in jedem Lesezyklus, und die Variable bekam 0, das im Drehfeld „kein Drehfeld“ bedeutet.
- **Jetzt:** Ein nicht endlicher Wert wird nicht geschrieben, die Variable behält ihren letzten Wert; keine PHP-Warnung. Das ist dieselbe Regel wie seit 0.38.7 für Leistung, Spannung und Strom. Gültige Werte (1, 0, −1) werden wie bisher geschrieben.
- **Prüfstand:** `test-ref-treiber.php` (Referenz für Janitza klassisch neu geschrieben: nur `phase_seq` und die Warnung fallen im Szenario „ungültig“ weg, sonst nichts) plus zwei ausdrückliche Prüfungen und eine Mutationsprobe.

## 0.39.0 (2026-11-18)

Vertragsfeld `phaseCurrentIDs` für den Phasenwächter des EMS (`MHUB_GetFunctions` **1.5**, additiv; Anlass: Ausfall bei Paul am 15.11.2026, L1 mit 57,7 A an einem 50-A-SLS).

- **Neu:** An der Netzanschluss-Zuordnung (`function = grid`, kombinierter Modus) liefert `MHUB_GetFunctions` jetzt `phaseCurrentIDs` = `[L1, L2, L3]`, die Variablen-IDs der
  drei Phasenströme in A. Das sind die vom Zähler **gemessenen** Ströme (die Treiber lesen sie aus eigenen Registern, bei jedem schnellen Lesezyklus), nie aus Leistung
  durch Spannung geschätzt. Das Vorzeichen ist egal, das EMS rechnet mit dem Betrag.
- **Wann das Feld fehlt:** bei einphasigen Zählern (SDM120/220/230, MBS, Tasmota, Shelly mit einem Kanal), bei Inexogy (nur Phasenleistung), bei ausgeschalteter
  Gruppe „Strom je Phase“, im Je-Phase-Modus und bei jeder Zuordnung außer „Netzanschluss“. Es gibt dann keinen Eintrag, nicht 0 und keine leere Liste.
- **Zusammenspiel mit 0.38.7:** Meldet ein Zähler einen Strom als „nicht verfügbar“, wird er nicht als 0 A geschrieben; die Variable wird alt, und das EMS ignoriert Werte
  über 120 s. Ein 0-A-Wert würde den Wächter blind machen.
- **Voraussetzung am Zähler:** Die Gruppe „Strom je Phase“ muss in der Instanz eingeschaltet sein (Bereich „Datenpunkte“).
- **Prüfstand:** `.tools/test-phasecurrent.php` (12 Prüfungen) und 8 Mutationsproben (`php .tools/ref/mutate.php phase`).

## 0.38.7 (2026-11-18)

„Nicht verfügbar“ wird nicht mehr als 0 geschrieben (Fund 1 der Code-Bestandsaufnahme vom 18.11.2026, Anlass: EMS-Phasenwächter).

- **Vorher:** Meldet ein Zähler einen Messwert als „nicht verfügbar“ (Float32 `0xFFFFFFFF` = NaN, oder unendlich), gaben 11 von 15 Treibern den Wert so an den
  Hauptmodul-Setter weiter, und dieser machte daraus **0**. Eine Leistung, Spannung oder ein Strom stand dann als 0 W, 0 V oder 0 A in der Variablen, statt
  „unbekannt“ zu sein; für ein Energiemanagement ist 0 A kein Messwert, sondern eine falsche Aussage.
- **Jetzt:** `SetVarFloat`, `SetVarEnergyWh` und `SetVarEnergykWh` schreiben einen nicht endlichen Wert **gar nicht**. Die Variable behält ihren letzten Wert samt
  Zeitstempel (veraltet ist dann an der Aktualisierungszeit erkennbar). Im Debug-Fenster steht „<Ident>: nicht verfügbar — nicht geschrieben“. Eine echte 0
  wird weiterhin geschrieben. Die Energiezähler waren schon vorher über den Zählerschutz vor einem 0-Stand geschützt; sie ändern sich nicht.
- **Betroffen:** alle Zählertypen, deren Treiber einen Float32 ohne eigene Prüfung weiterreichen (PAC2200, Janitza, Eastron, WhatWatt, Phoenix, Shelly Pro 3EM,
  blue'Log SCADA Wechselrichter/Zähler und Power Control). Der go-e Controller prüfte schon selbst.
- **Nicht Teil dieser Version:** die Warnung „The float NAN is not representable as an int“ beim Drehfeld der klassischen Janitza-Karte (Fund 2) bleibt.
- **Prüfstand:** `.tools/test-powerinvert.php` Abschnitt 12 (NaN, INF, −INF, mit und ohne PowerInvert, gültiger Wert danach, echte 0 bleibt); gegen den Stand 0.38.6
  schlägt die Leistungsprüfung an.

## 0.38.6 (2026-11-17)

Fehlerbehebung beim Inexogy-Archiv-Nachtrag mit gesetztem Haken „Bezug/Einspeisung vertauscht“ (Befund Forum, Tester hbraun). Der Live-Pfad leitet bei diesem
Haken das Bezugsregister in die jeweils andere Energie-Variable um und dreht das Vorzeichen der Wirkleistung; der Nachtrag (`MHUB_BackfillInexogyArchive()`
und der automatische Lauf) schrieb dagegen ohne beides.

- **Folge des Fehlers:** Bezug und Abgabe wurden vom Nachtrag in der jeweils falschen Variable abgelegt, also lagen in jeder Energie-Variable zwei
  Zählerreihen gemischt (Live-Werte der einen, Nachtragswerte der anderen), und die archivierte Wirkleistung der Nachträge hatte das umgekehrte Vorzeichen
  der Live-Werte.
- **Jetzt:** Der Nachtrag schreibt in dieselbe Variable wie der Live-Pfad und mit demselben Vorzeichen. Ohne den Haken ändert sich nichts.
- **Bereits betroffene Archive:** das Panel „Richtung im Archiv prüfen / korrigieren“ erkennt die Kreuzsprünge der Energiezähler und
  richtet sie aus; zuerst die Prüfung (Probelauf) ausführen.
- **Prüfstand:** `.tools/test-backfill-invert.php` prüft, dass Live- und Nachtragswert desselben Zählerregisters in derselben Variable landen und die
  Leistung dasselbe Vorzeichen hat; gegen den Stand 0.38.5 schlägt er an.

## 0.38.5 (2026-11-17)

Sperre je Instanz für Modbus-Zähler (Verbund-Regel SUITE.md Stolperstein 31, Vorlage InverterHub 0.86.0). Anlass: Viele Geräte und Logger nehmen nur
EINE Modbus-TCP-Verbindung gleichzeitig an. Beim Solis kamen deshalb 30 Ladebefehle nicht an, weil der Lese-Timer und ein Schreibbefehl gleichzeitig je eine
eigene Verbindung öffneten. MeterHub liest überwiegend; geschrieben wird nur der Sollwert der blue'Log-Zähler (Direktvermarktung im Solarpark), und der
Schreibaufruf `MHUB_SetBlueLogTarget()` öffnete bisher eine eigene Verbindung neben dem laufenden Lese-Timer.

- **Lesen und Schreiben laufen unter einer Sperre je Instanz** (`MHUB_IO_<ID>`). `ReadFast`, `ReadSlow` und „Verbindung testen“ warten bis zu 8 s; bekommt ein
  Lesezyklus die Sperre nicht, fällt er ohne Fehlermeldung aus. `MHUB_SetBlueLogTarget()` und der Rückfallwert beim Deaktivieren warten bis zu 20 s und
  schreiben danach trotzdem (ein verlorener Sollwert ist teurer als eine Überlappung; dann steht eine Warnung im Protokoll). Eine Sperre, die länger als 45 s
  gehalten wird, gilt als verwaist und wird ignoriert; `finally` gibt sie immer frei. Die Sperre gilt nur für Modbus-Zähler: Inexogy, Tasmota und Shelly
  (MQTT) kennen das Problem nicht und warten auch nicht auf einen langen Inexogy-Nachtrag. Die Nacharbeiten in `ReadSlow` (Nachtrag, Richtungsprüfung,
  Verdichtung) liegen bewusst außerhalb der Sperre.
- **Ein Wiederholversuch beim Sollwert-Schreiben** nach 1,5 s Pause, nur bei einem Verbindungsfehler (nicht, wenn das Gerät den Wert mit einer
  Modbus-Exception ablehnt: das ist eine gültige Antwort).
- **Fehlergrund im Protokoll:** bei einem Verbindungsfehler stehen `errno` und `errstr` von `fsockopen` im Text („connect (111: Connection refused)“). Der
  Eintrag erscheint höchstens einmal je Minute.
- **Schreibstatus als Variablen** (nur blue'Log RPC und blue'Log Power Control): `ctl_write_fail_count`, `ctl_write_fail_time`, `ctl_write_fail_text`,
  `ctl_write_ok_time` (Unixzeit). „Hängt“ heißt `fail_time > ok_time`. Das EMS wertet diese Idents generisch aus; für den Solarpark sind sie der Beleg, ob
  Sollwerte ankommen.
- Die Brücke `MeterHubBridge` zu Symcons ModBus-Gateway bleibt unverändert; die Reihenfolge der Anfragen regelt dort das Gateway.

Prüfstand `.tools/test-iolock.php` (echter Modbus-Server, nachgebildete Sperre mit fremdem Halter) mit Mutationsprobe `--mutations` (20 Mutationen, alle erkannt).
Nicht abgedeckt im Prüfstand: der Rückfallwert beim Deaktivieren (`ApplyChanges`); die Sperre dort ist derselbe `RunLocked`-Aufruf.

## 0.38.4 (2026-11-16)

Drei Verbesserungen der Prüfung im virtuellen Zähler, ausgelöst durch den Fall hbraun im Forum (16.11.2026: Hauptzähler minus Unterzähler
ergab −224 W, beim Bezug war die falsche Variable gewählt):

- **Hinweis „Netzzähler minus Unterzähler bei PV/Batterie“.** Zieht eine Formel einen Unterzähler von einem Zähler am Netzanschluss ab (Inexogy-Zähler
  oder MeterHub mit der Funktion „Netzanschluss“) und gibt es im System einen Wechselrichter (InverterHub) oder einen MeterHub mit der Funktion
  PV/Batterie, weist die Prüfung darauf hin: Der Netzzähler misst netto, bei Deckung durch PV steht er bei etwa 0 W, die Differenz wird negativ. Nur ein
  Hinweis, nichts wird gesperrt; gemeldet wird nur, wenn Netzzähler und PV/Batterie belegbar sind.
- **Zeitraum-Auswertungen sind kein Bezug mehr.** Eine Variable mit „Zeitraum“ oder „Zeitspanne“ im Namen (zum Beispiel „Verbrauch Zeitraum“) wird bei der
  automatischen Suche am Gerät nicht mehr als Zählerstand (Bezug) vorgeschlagen. Fehlt dann der Bezug, trägt ihn der Nutzer unter „Bezug übersteuern“ ein.
- **Hinweis bei kW-Profil an einem zählerartig benannten Wert.** Bei mehreren gleichwertigen Leistungswerten am Gerät nennt die Prüfung jetzt, wenn eine
  davon ein kW-Profil trägt, aber wie ein Zähler heißt („Stromzähler …“): Ist es ein Zählerstand in kWh, das Profil der Variable auf kWh stellen, dann wird sie
  als Bezug erkannt.

Prüfstand `test-virtual.php` Block 68 mit dem Fall nachgebaut; die Proben ohne den jeweiligen Schutz schlagen an.

## 0.38.3 (2026-11-15)

- **Doku und Formular nennen jetzt, was an echter Hardware bestätigt ist.** Dirk (dallard) hat am 15.11.2026 Tasmota-Steckdosen (mit und ohne
  Web-Passwort), einen IR-Lesekopf mit SML-Skript und eine Shelly Plus Plug S per MQTT durchgespielt. Deshalb:
  - **Tasmota:** Der Zählertyp heißt „Tasmota (HTTP)“ ohne den Zusatz „experimentell“. Die Hinweise im Formular und die READMEs nennen die
    bestätigten Geräte; andere Geräte und Firmware-Stände können abweichen.
  - **Shelly (MQTT):** bleibt „experimentell“, weil nur die Plus Plug S bestätigt ist. Die Hinweise sagen das jetzt ausdrücklich; Pro 3EM, Pro EM und
    die übrigen Modelle sind weiter aus der Shelly-Dokumentation abgeleitet.
  - Keine Änderung an der Logik. Prüfstand `test-tasmota.php` 5c angepasst.

## 0.38.2 (2026-11-15)

- **Tasmota: Web-Passwort am Gerät wird jetzt unterstützt.** Die meisten Steckdosen im Feld haben ein Web-Passwort gesetzt (Rückmeldung
  Dirk, 15.11.2026), und ein solches Gerät lieferte keine Messwerte. Im Formular der Tasmota-Instanz gibt es das Feld „Web-Passwort des
  Geräts“ mit „Passwort speichern“ und „Gespeichertes Passwort löschen“. Der Benutzer ist bei Tasmota immer `admin`. Das Passwort liegt
  als Attribut (nicht sichtbar im Formular, in keiner Einstellung, in keinem Fehlertext; die Statuszeile nennt nur die Länge) und geht als
  Parameter `user=admin&password=…` mit jeder Abfrage, URL-kodiert. Das verlangt Tasmota laut seiner Dokumentation so; im lokalen Netz
  läuft es im Klartext. Antwortet ein Gerät mit „Need user=…&password=…“ oder HTTP 401, sagt der Fehlertext, ob noch kein Passwort
  gespeichert ist oder ob es abgelehnt wurde. Prüfstand `test-tasmota.php` 6 (echter Server, Sonderzeichen im Passwort) und
  `test-virtual.php` 64h.

## 0.38.1 (2026-11-15)

- **Shelly (MQTT): Geräte mit nur einem Kanal gelten als einphasig.** Eine Steckdose (Plus Plug S), ein 1PM oder ein EM50 sendet
  seine Werte in `switch:`, `pm1:` oder `em1:` und hat nur eine Phase. Der MeterHub bot dafür trotzdem die Wahl „Dreiphasig /
  Einphasig getrennt“ und legte Wirkleistung, Spannung und Strom für L1 bis L3 an, die für immer leer blieben (Rückmeldung Dirk
  mit der ersten echten Shelly-Nachricht, 15.11.2026). Jetzt erkennt der Treiber die Komponentenart aus den Nachrichten, und bei
  einem Ein-Kanal-Gerät entfallen die Messmodus-Auswahl und die drei Phasen-Gruppen im Bereich „Datenpunkte“; bereits angelegte
  leere L1–L3-Variablen räumt das nächste „Übernehmen“ ab. Der Pro 3EM (`em`) bleibt unverändert dreiphasig. Prüfstand 65h und
  4e2/5b0/5c0/5l0, dazu die echte Nachricht des Plug S als Regressionsfall (5l–5n).

## 0.38.0 (2026-11-15)

- **Neu: Assistent für virtuelle Zähler.** In MeterHubVirtual führt das Panel „Assistent“ ganz oben durch das Zusammenstellen:
  „Zähler zu einer Gruppe zusammenfassen“, „Einen Rest berechnen (Zähler abziehen)“ oder „Einen Zähler anteilig aufteilen“.
  Die Knöpfe „Als Summand verknüpfen“ und „Als Abzug verknüpfen (−100 %)“ setzen den Anteil beim Verknüpfen, er muss nicht mehr in
  der Tabelle geändert werden (neue Funktion `MHUBV_AddDeviceAs`). Das Panel ist aufgeklappt, solange die Instanz höchstens ein
  Mitglied hat, danach eingeklappt. Anlass: Forum-Fund hbraun (Hauptzähler minus Homematic-Unterzähler).
- **Neu: Einstieg aus jedem MeterHub-Zähler, auch Inexogy/Discovergy.** Der Knopf „Virtuellen Zähler mit dem Assistenten anlegen“
  im Panel „Virtueller Zähler“ legt eine neue Instanz im Objektbaum-Modus an, verknüpft diesen Zähler als ersten Summanden und
  die gewählten weiteren Zähler als Summanden oder Abzüge (neue Funktion `MHUB_StartVirtualAssistant`). Die Partner dürfen
  beliebige Instanzen oder Geräte sein, nicht nur MeterHub-Instanzen. `MHUB_CreateVirtual` bleibt unverändert erhalten.
- **Fix: Ein Zähler, der Mitglied eines virtuellen Zählers im Objektbaum-Modus ist, wird jetzt als solches erkannt.** Das Panel
  zeigte bisher nur Mitgliedschaften in Tabellen-Instanzen und bot dadurch einen zweiten virtuellen Zähler an.
- **Neu: Geräte des Fremdmoduls „Discovergy Smartmeter“ als Mitglied.** Bezug (`energy`) und Einspeisung (`energyout`) werden über
  ihre Idents getrennt, Leistung über `power`.
- Hinweis im News-Panel von MeterHub und MeterHubVirtual, Beispiel in der Dokumentation, README und Anleitung ergänzt.

## 0.37.4 (2026-11-15)

- **Zähler-Gruppen sichtbar gemacht.** Mehrere Zähler lassen sich als EIN aufklappbarer Knoten im NRG-Stack Dashboard
  darstellen, ohne Formel und ohne Eintrag im Dashboard: virtuellen Zähler anlegen, Zähler als Mitglieder darunter
  einsortieren, Funktion wählen. Die Funktion bestand schon, wurde aber kaum entdeckt. Neu: README-Abschnitt „Zähler-Gruppen“
  ganz oben (deutsch und englisch) und eine ausführliche Anleitung mit Bildern (`Anleitung-Zaehler-Gruppen.md`); im Formular
  ein Highlight-Hinweis im News-Panel von MeterHub und MeterHubVirtual und im Zweck-Panel von MeterHub; in der Dokumentation
  von MeterHubVirtual ein Beispiel „Zähler-Gruppe im Dashboard“. Schaltbare Mitglieder (z. B. Z-Wave) geben der Gruppe
  „Gruppe schalten“ und „Gruppenstatus“, auch das ist jetzt erklärt. Keine Änderung an der Rechenlogik.

## 0.37.3 (2026-11-15)

- **Formular: „Automatische Neuanmeldung (optional)“ liegt jetzt in einem eigenen, eingeklappten Panel.** Die Felder für den
  Tresor (SymconSecrets) standen bei Inexogy-Instanzen mitten im Verbindungsbereich und wirkten wie ein Pflichtteil
  (Rückmeldung hbraun, 15.11.2026). Sie sind nur für die automatische Neuanmeldung gedacht, wenn Inexogy den Zugriff
  ungültig macht; ohne Tresor genügt die manuelle Anmeldung. Inhalt und Verhalten unverändert, die Statuszeile bleibt live.
  Prüfstand 54k2.
- **Fix: Hilfetext im Bereich „Zähler“ des virtuellen Zählers.** Dort stand „die drei Alternativen unten“, gezeigt werden vier.
  Jetzt: „die Alternativen unten“.

## 0.37.2 (2026-11-15)

- **Fix: Konfigurationsformular einer neuen Inexogy-Instanz ließ sich nicht öffnen** („Konnte Konfigurationsform nicht
  laden“, Fund hbraun 15.11.2026). Der Archiv-Wasserstand (`InexogyArchiveWatermark()`) fragte `AC_GetLoggedValues()`
  auch für Variablen ab, die gar nicht archiviert sind. Symcon wirft dafür die Warnung „Logging ist für diese Variable
  nicht verfügbar“, und deren Text landete im Formular vor dem JSON. Seit 0.34.0 schaltet MeterHub das Logging nicht mehr
  selbst ein, deshalb trat es bei jeder neuen Instanz auf, solange der Nutzer die Archivierung noch nicht angeklickt hatte.
  Betroffen war dasselbe auch bei `archiveWatermarkTs` im Vertrag `MHUB_GetFunctions` (Konsumenten wie das Dashboard). Jetzt
  gilt eine nicht archivierte Variable als „noch nichts archiviert“ (Wasserstand 0), ohne Abfrage und ohne Warnung. Prüfstand
  `test-auto-backfill.php` 8g, gegengeprobt (ohne den Fix schlägt er an).

## 0.37.1 (2026-11-15)

- **Fix: MeterHubVirtual räumt seinen WebHook beim Löschen der Instanz auf.** Die Kachel-Registrierung
  (`/hook/mhubvtile<ID>`) schrieb ihren Eintrag fest in die Hooks-Liste des „WebHook Control“, entfernt wurde er nie.
  Wer Instanzen anlegte und wieder löschte, behielt verwaiste Einträge (Fund des Testers dallard, 14.11.2026).
  Neu: `Destroy()` entfernt nur den eigenen Eintrag (zeigt `TargetID` auf diese Instanz) und nur dann, wenn die Instanz
  wirklich nicht mehr existiert (`Destroy()` läuft auch beim Entladen des Moduls). Bereits verwaiste Einträge bleiben
  stehen und lassen sich in der Instanz „WebHook Control“ von Hand löschen. Prüfstand 66.

## 0.37.0 (2026-11-14)

- **Neu (experimentell): Shelly über MQTT.** Neuer Zählertyp „Shelly (MQTT, experimentell)“ für Shelly Gen2/Gen3/Pro,
  die per MQTT an den MQTT-Server von Symcon senden (Auftrag Dietmar 14.11.2026: „Doch, Shelly über MQTT bauen“).
  Gen1 wird nicht unterstützt (Topic-Formate nur aus Drittquellen bekannt).
  - **Neues Modul `MeterHubMqttBridge`** (Präfix `MHUBM`, Alias „NRG-Stack MeterHub Brücke (MQTT)“): trägt als Einziges die
    MQTT-Schnittstellen (`parentRequirements`/`implemented` wie InverterHubMqttBridge, HeishaMon), damit bestehende
    MeterHub-Instanzen ohne Elternteil bleiben. Sie hört nur zu (nichts wird veröffentlicht), baut ihren Empfangsfilter aus
    den MQTT-Präfixen der zugeordneten Instanzen (maskierte und unmaskierte Schrägstriche) und führt je Gerät den letzten
    Stand zusammen: `events/rpc` (`NotifyStatus` nur mit Änderungen, `NotifyFullStatus`), `status/<Komponente>`
    (ersetzt vollständig) und `online` (Last Will); `null` löscht ein Feld, Listen werden ersetzt. Abfrage über
    `MHUBM_GetDevice($id, $topic)` (Alter, online, Komponenten).
  - **Treiber `MHUB_ShellyMqttDriver`:** bevorzugt `em` (Pro 3EM: `total_act_power`, Phasenwerte; Energie aus `emdata`
    `total_act`/`total_act_ret`), dann `em1` (`act_power`; `em1data`), `pm1` und `switch` (`apower`, `aenergy.total`,
    `ret_aenergy.total`), Kanal wählbar; Wh → kWh; Vorzeichen + = Bezug. Energie nur, wenn das Gerät sie gesendet hat.
    Zustand „nicht verbunden“: keine Nachricht seit 15 Minuten, Gerät offline gemeldet oder keine passende Komponente.
  - **Formular:** Brückenauswahl (gefiltert auf das neue Modul), MQTT-Präfix, Kanal und eine live berechnete Statuszeile
    (⛔/⚠️/✅ mit Alter der letzten Nachricht, folgt der Eingabe per `onChange`); Host, Port, Unit-ID und Verbindungsweg
    entfallen. Die Brücke zeigt eine Tabelle der zugeordneten Geräte mit Alter der letzten Nachricht.
  - **Grenzen:** aus der Shelly-Dokumentation abgeleitet, nicht an einem Gerät gemessen (Nachrichtenfrequenz des
    Pro 3EM und Energie-Meldungen unbekannt); nur der MQTT-Server von Symcon ist vorgesehen; Payload-Dekodierung
    (`utf8_decode` ab Kernel 1670886000) wie bei InverterHubMqttBridge aus Referenzmodulen übernommen.
  - Prüfstand `.tools/test-shelly-mqtt.php` (Zusammenführen, Filter, Fehlerzustände, alle Komponenten) und Block 65 in
    `test-virtual.php` (Formular).

## 0.36.0 (2026-11-14)

- **Neu (experimentell): Tasmota-Zähler.** Neuer Zählertyp „Tasmota (HTTP, experimentell)“ für Zähler und Steckdosen mit
  Tasmota-Firmware (z. B. Nous A1T/A8T) und für einen IR-Lesekopf mit SML-Skript. Anlass: Anfrage im Forum (Dirk).
  Shelly bekommt keinen HTTP-Weg (Pro 3EM läuft über Modbus).
  - **Nur lesend, ein Aufruf je Lesevorgang:** `MHUB_TasmotaClient` fragt `/cm?cmnd=Status 8` ab und liest `StatusSNS`;
    es wird nichts geschaltet oder geschrieben. Host wird auf Buchstaben/Ziffern/`.`/`_`/`-` geprüft.
  - **Erkennung:** ausdrücklich genannter Sensor, sonst `ENERGY`, sonst das erste Objekt mit bekannten Feldern
    (`Power`/`Power_curr`, `Total`/`Total_in`, `ExportActive`/`Total_out`, Groß-/Kleinschreibung egal). Listen je Phase
    (Dreiphasen-`ENERGY`) werden zur Summe und in die Phasengruppen verteilt. Eigene Sensor-/Feldnamen und die Einheit der
    Energiefelder (Wh/kWh; der Standardblock ist immer kWh) stehen im Formular.
  - **Grenzen:** Formate stammen aus der Tasmota-Dokumentation, nicht von echten Geräten; Web-Passwort am Gerät wird nicht
    unterstützt (Hinweis im Verbindungstest und Formular).
  - Prüfstand `.tools/test-tasmota.php` (echter HTTP-Server mit Beispielantworten: einphasig, dreiphasig, SML, Fehlerfälle).
- Fix: `curl_close()` in `MHUB_TasmotaClient` entfernt (seit PHP 8.0 wirkungslos, in PHP 8.5 veraltet).

## 0.35.1 (2026-11-14)

- **Fix: Variablennamen „Wirkleistung L1/L2/L3“, „Wirkleistung“ und „Wirkleistung AC“ blieben in der englischen
  Oberfläche deutsch.** In 0.35.0 waren diese Namen in zwölf Treibern beim Umstellen auf englische Quelltexte
  übersehen worden (Deutsch-Nutzer sahen weiter dasselbe). Jetzt „Active power L1/L2/L3“, „Active power“, „AC active
  power“; Deutsch kommt aus der `locale.json`. Der statische Prüfstand `.tools/test-i18n.php` hat das nicht gefunden,
  weil eine englische Beispielformulierung das Wort „Wirkleistung“ in den englischen Wortschatz gebracht hatte — die
  deutschen Fachwörter `wirkleistung`, `wirkenergie`, `hauptzähler` zählen dort jetzt immer als deutsch (die
  absichtlich deutschen KNX-Suchmuster stehen in der Liste `$keepGerman`). Test 62a ignoriert Namen aus den
  Prüfstand-Anlagen (Instanz- und Standortnamen).

## 0.35.0 (2026-11-09)

- **Zweisprachig: Deutsch und Englisch (Auftrag Dietmar 05.11.2026, SUITE.md Konvention 9).** Alle vier Module
  (`MeterHub`, `MeterHubVirtual`, `MeterHubDiscovery`, `MeterHubBridge`) haben englische Quelltexte, die deutschen
  Fassungen stehen in der jeweiligen `locale.json` (rund 1.100 Texte). Die Texte folgen der Symcon-Spracheinstellung.
  - **Formular (Checkliste 25):** Beschriftungen, Hinweise und Auswahltexte im Formular bleiben englischer Quelltext,
    die Konsole übersetzt sie selbst (`GetConfigurationForm` und `UpdateFormField`). Selbst übersetzt werden nur
    Texte mit Werten (Statuszeilen, Meldungen, Fortschritt), Rückgaben von Knöpfen (`echo`), Bestätigungsfragen,
    Variablennamen, Profiltexte und Listenwerte (z. B. Panelnamen im „Formular: Aufbau“).
  - **Fassade `MHUB_Text`/`MHUBV_Text`/`MHUBD_Text`/`MHUBB_Text`:** `::t('English %s', $arg)` übersetzt über die
    `Translate()`-Methode der Modulklasse (im Konstruktor hinterlegt) und wendet danach `sprintf()` an. Sie ist auch in
    Hilfsklassen und statischen Methoden nutzbar. Variablennamen werden einmal je „Übernehmen“ übersetzt, nie je
    Abfrage (Stolperstein 24; Prüfstand Block 63: 200 Messwert-Schreibvorgänge lösen null Übersetzungen aus).
  - **Kennungen unverändert:** Idents, Property-/Attribut-/Funktionsnamen und Vertrags-Schlüssel sind dieselben
    (Migrationsvergleich ohne Bruch). Für Deutsch-Nutzer ändert sich kein sichtbarer Text, außer wo eine Aussage
    inhaltlich veraltet war (siehe unten).
  - **Neu: `README.en.md`** (englische README), Sprachlink in beiden Dateien.
  - **„Neu in dieser Version“ gekürzt:** Die Panels von `MeterHub`, `MeterHubVirtual` und `MeterHubDiscovery` zeigen nur
    noch die jüngsten Einträge; die älteren Punkte (bis 0.31.x) stehen weiter im CHANGELOG.
  - **Inhaltlich aktualisiert:** „MeterHub/MeterHubVirtual/MeterHubDiscovery sind Beta“ (Forum-Hinweis) gilt seit 0.32.5
    nicht mehr; die Beschreibung des virtuellen Zählers im Hauptmodul sprach noch vom früheren Verdrahtungs-Modell
    („hängen dahinter“) und beschreibt jetzt Formel mit Anteil in Prozent; README entsprechend.
  - Prüfstand: `.tools/test-i18n.php` (neu, statisch: jeder Text in `locale.json`, kein deutscher Rest im Code, keine
    toten Einträge, Platzhalter gleich), `test-virtual.php` Block 62 (englische Formulare ohne deutsche Reste) und
    Block 63 (Anzahl der Übersetzungen). Die übrigen Prüfstände simulieren die deutsche Oberfläche
    (`.tools/i18n-test-support.php`); die englische Oberfläche wird gezielt in Block 62 geprüft.

## 0.34.1 (2026-11-09)

- **Altes eigenes benutzerdefiniertes Profil wird genau EINMAL je Instanz geleert (EMS-Ausnahmeregel zu
  Checkliste 28, Bedingungen vom 09.11.2026).** In 0.34.0 räumte `RegisterVar()`/`DropOwnCustomProfile()` bei jedem
  „Übernehmen" ein `NRG.*`-/`MHB.*`-/`MHBV.*`-Profil ab; trug der Nutzer später selbst eines mit diesem Präfix ein,
  wäre es erneut gelöscht worden. Jetzt gibt es das Attribut `OwnCustomProfileCleared`: gesetzt wird es am Ende des
  ersten vollständigen Registrierungsdurchlaufs, danach fasst das Modul benutzerdefinierte Profile nie wieder an.
  Fremde Profile bleiben ohnehin. Das Leeren selbst bleibt nötig, weil ein von früheren Versionen gesetztes `NRG.kWh`
  nach „Energie in Wh" sonst kWh über einem Wh-Wert anzeigen würde. Benannt im „Neu"-Panel von `MeterHub` und
  `MeterHubVirtual`. Prüfstand: `.tools/test-virtual.php` Block 61 (Merker gesetzt, zweiter Eintrag bleibt).

## 0.34.0 (2026-11-09)

- **Archivierung nur auf Klick (Symcon-Store-Checkliste 19; Review-Wortlaut bei WPHub, WPModbusHub, SamsungEhs,
  WPBsbLan: „Du aktivierst hier für deine Variablen das Logging ganz ohne die Zustimmung des Benutzers …
  es muss bewusst durch den Benutzer aktiviert werden.").** `MeterHub` und `MeterHubVirtual` schalten das
  Logging ihrer Variablen nicht mehr von selbst ein (`AC_SetLoggingStatus` steht nur noch in
  `EnableArchiving()`). Fehlt bei eigenen, zu archivierenden Variablen das Logging, erscheint oben im Formular
  das Panel „📈 Archivierung fehlt (n Variablen)" mit der Liste und dem Knopf „Archivierung für diese
  Variablen einschalten"; das Panel verschwindet, sobald nichts mehr fehlt. Bestehendes Logging wird nie
  abgeschaltet. Aggregationstyp („Zähler" für Energie) und Archiv-Verdichtung setzt das Modul nur noch bei
  Variablen, deren Logging läuft — und beim Klick.
  - **Folge für neue Instanzen:** Sie archivieren erst nach dem Klick. Die Archiv-Verdichtung und der
    Inexogy-Nachtrag der 15-Minuten-Werte wirken nur bei archivierten Variablen; der Nachtrag meldet sonst
    klar „Die Zielvariablen werden noch nicht archiviert …" und schreibt nichts. Bestandsinstanzen laufen
    unverändert weiter, ihr Logging bleibt eingeschaltet.
- **Kein benutzerdefiniertes Profil mehr (Checkliste 28; Review Messwertrechner: „… setzt du das
  benutzerdefinierte Profil (und somit ja auch die Darstellung) von Variablen. Diese sind aber stets in der
  Hoheit des Benutzers.").** Variablen werden über `RegisterVariable*()` angelegt, das Profil kommt als
  Standarddarstellung (`VARIABLE_PRESENTATION_LEGACY` + `PROFILE`). „Energie in Wh" stellt das Standardprofil
  auf `MHB.Wh` um. Ein von früheren Versionen gesetztes EIGENES benutzerdefiniertes Profil (`NRG.*`, `MHB.*`,
  `MHBV.*`) entfernt das Modul einmal, weil es sonst die Anzeige dauerhaft überdecken würde (z. B. kWh nach dem
  Umschalten auf Wh) — die Standarddarstellung zeigt dasselbe. Ein vom Nutzer gewähltes fremdes Profil bleibt
  unangetastet. Position und Name bleiben wie bisher (Position nur beim Anlegen).
- **`UnregisterVariable()` statt `IPS_DeleteVariable()` (Checkliste 26; Review Automat).** Überzählige eigene
  Variablen (anderer Treiber, abgeschaltete Gruppe, nicht mehr gebrauchte Ausgabe) gehen über
  `$this->UnregisterVariable($ident)`.
- **Kein Abgleich mit Geschwister-Instanzen in `ApplyChanges()` (Checkliste 27; Review Tessie: „Beim Neuladen
  von Modulen kann AdoptDismissFromSibling fehlschlagen, da die anderen Instanzen noch nicht geladen sein
  könnten.").** Das gemeinsame Ausblenden von Zweck-Hinweis, „Neu"-Panel und Forum-Hinweis
  (`AdoptDismissFromSibling()`) läuft in `MeterHub`, `MeterHubVirtual` und `MeterHubDiscovery` jetzt beim
  Öffnen des Formulars (`BuildForm()`), nicht mehr in `ApplyChanges()`.
  - Prüfstand: `.tools/test-virtual.php` Block 61 (Archivierung nur auf Klick in beiden Modulen, Panel und
    Knopf, nichts wird abgeschaltet, Standarddarstellung statt Custom-Profil, Wh-Umschaltung, Bestand mit
    eigenem altem Profil, fremdes Profil bleibt, `UnregisterVariable`, Quelltextprüfung für 19/26/27/28,
    Inexogy-Nachtrag ohne Archivierung); sechs absichtlich eingebaute Fehler werden gefangen.

## 0.33.1 (2026-10-30)

- **MeterHub: keine Kategorien mehr unter der Instanz (Symcon-Store-Review 30.10.2026: „Kategorien unter
  Instanzen sind nicht vorgesehen, das wird auch von der Visualisierung gar nicht unterstützt").** Bisher
  legte MeterHub je Variablengruppe eine Unterkategorie an (Summenwerte, Spannung, Strom, Leistung je
  Phase, Energiezähler, Funktionen, Netzqualität, Gerät, Fehler / Verbindung, Gleichstrom). Alle Variablen
  liegen jetzt direkt unter der Instanz — von den beiden Wegen des Reviewers (alles direkt unter die
  Instanz oder mehrere Instanzen über einen Konfigurator) die erste, weil die zweite aus einem Zähler
  mehrere Instanzen machen und die Auswahl der Datenpunkt-Gruppen sowie die Funktionsverträge
  (`GetFunctions()`) umbauen würde.
  - **Bestand wird beim nächsten „Übernehmen" von selbst aufgeräumt:** `RegisterVar()` setzt jede
    Variable unter die Instanz, danach löscht `RemoveLegacyCategories()` die leeren `cat_*`-Kategorien.
    Die Variablen behalten ihre **ID** — Archiv, Verknüpfungen, Ereignisse, Skripte und andere Module
    (EMS, NRGDashboard, MeterHubVirtual) sind nicht betroffen, nur der Weg im Objektbaum wird kürzer.
  - Hat der Nutzer selbst etwas in eine solche Kategorie gelegt, bleibt sie samt Inhalt bestehen;
    fremde Objekte fasst das Modul nicht an.
  - Neue Instanzen legen nie Kategorien an. Die Reihenfolge im Objektbaum bleibt die der bisherigen
    Positionen (laufender Index über alle Gruppen).
  - MeterHubVirtual, MeterHubDiscovery und MeterHubBridge legten schon bisher keine Kategorien an.
  - Prüfstand: `.tools/test-virtual.php` Block 60 (neue Instanz, Altbestand mit gleicher ID, Nutzerobjekt
    bleibt, zweiter Durchlauf stabil, kein `IPS_CreateCategory` mehr im Quelltext).

## 0.33.0 (2026-10-29)

- **Neu: „🪟 Formular: Aufbau" in MeterHub, MeterHubVirtual und MeterHubDiscovery (NRG-Stack-Konvention
  SUITE.md 3a, Auftrag Dietmar 29.10.2026, Referenz Batteriewächter).** Symcon merkt sich das Auf- und
  Zuklappen der Bereiche nicht selbst; das Formular von MeterHub hat über ein Dutzend Bereiche. Im neuen,
  eingeklappten Panel vor dem Forum-Hinweis legt der Nutzer je Fachpanel fest, ob es beim Öffnen
  „aufgeklappt", „zugeklappt" oder „🙈 ausgeblendet" ist, und an welcher Position (1–99) es steht.
  - Kein Haken = Standard (wie bisher). Bei Widerspruch gilt ausgeblendet vor zugeklappt vor aufgeklappt.
    Bei gleicher Position bleibt die Standardreihenfolge.
  - **Ausgeblendet heißt `visible: false`, nie weglassen:** die Felder bleiben im Formular, gespeicherte
    Werte bleiben beim „Übernehmen" erhalten und der Bereich arbeitet weiter. Das Aufbau-Panel nennt die
    Zahl der ausgeblendeten Bereiche und welche es sind.
  - Fest bleiben oben Zweck, Neu, Dokumentation & Hilfe und die Grundfelder (Zählerbezeichnung, Standort,
    Zählertyp …), unten Aufbau, Forum-Hinweis und „Über dieses Modul".
  - Neue Property `FormLayout` (String, `'[]'`) je Modul, Liste `{Key, Panel, Opened, Closed, Hidden, Pos}`;
    unbekannte oder fehlende Schlüssel (neues Panel nach einem Update) fallen auf Standard zurück, ein
    unlesbarer Inhalt ebenso. Knopf „↩️ Auf Standard zurücksetzen" → `MHUB_`/`MHUBV_`/`MHUBD_ResetFormLayout()`
    (setzt nur die Liste im offenen Formular, gespeichert wird mit „Übernehmen").
  - MeterHub: Die Statuszeile zur Archiv-Verdichtung zog in das Panel „Archiv-Verdichtung: Leistung/
    Momentanwerte" um, damit sie beim Verschieben beieinander bleibt.
  - MeterHubBridge hat kein Fachpanel und bekommt das Panel nicht (Konvention: mehr als zwei Fachpanels).
  - Prüfstand: Reihenfolge, Aufklapp-Zustand, `visible:false`, Rückfall bei unbekanntem Schlüssel und
    ungültigem Inhalt, Reset, Vorrang bei Widerspruch (`.tools/test-virtual.php` Block 59,
    `.tools/test-discovery-migration.php` Abschnitt 9).

## 0.32.6 (2026-10-27)

- **Archiv-Verdichtung: Standard „aus" für neue Instanzen, Bestand bleibt unverändert (Store-Review-
  Checkliste 9k, Entscheidung Dietmar 27.10.2026: „bei den vorhandenen Installationen an lassen").**
  `AutoCompactionPower`/`AutoCompactionEnergy` starten in `MeterHub` und `MeterHubVirtual` jetzt mit
  „aus"; der Nutzer stimmt bewusst zu. Bestehende Instanzen haben „an" in ihrer Konfiguration
  gespeichert und behalten es.
  - **Sicherheitsnetz gegen stilles Löschen:** Das Archiv wird nur noch beim BEOBACHTETEN Wechsel
    von an auf aus zurückgesetzt (neues Attribut `CompactionApplied<Power|Energy>`, gesetzt nach jedem
    vollständigen Durchlauf). Ein von vornherein ausgeschalteter Schalter lässt Regeln im Archiv in Ruhe
    — auch dort, wo bei einer Bestandsinstanz der gespeicherte Wert fehlen und deshalb der neue Standard
    „aus" greifen sollte. Vorher hätte genau das bei jedem „Übernehmen" alle Verdichtungsregeln
    gelöscht. Die Aktion „aus" bleibt für den bewussten Fall unverändert: einmaliges, aktives
    Zurücksetzen aller drei Stufen.
  - Formulartext am Schalter und News-Panel angepasst. Prüfstand: `test-virtual.php` Block 58
    (neuer Standard, Bestand an, Wechsel an → aus, weiter aus lässt Handregeln unberührt, fehlender
    Wert löscht nichts, dieselbe Kette im Hauptmodul); zwei Mutationen (Leer-Plan-Stopp, Wechselprüfung)
    schlagen an.

## 0.32.5 (2026-10-27)

- **Erste Stable-Version.** Inhaltlich identisch mit 0.32.4-beta.1; es entfällt nur das Beta-Suffix.
  Der Symcon-Store führt MeterHub jetzt im stabilen Kanal. Vor der Freigabe gegen die
  Store-Review-Checkliste (SUITE.md) geprüft und der Migrationsvergleich gegen den bisherigen Stand
  von `main` gefahren (kein Bruch, nur additive Erweiterungen, neue Module `MeterHubBridge` und
  `MeterHubVirtual`). `main` und `beta` stehen ab dieser Version auf demselben Commit; der Zweig
  `ems-integration` entfällt (siehe CLAUDE.md).
- **Bekannt, bewusst offen (Checkliste 9k):** Die Archiv-Verdichtung (`AutoCompactionPower`/
  `AutoCompactionEnergy`) ist standardmäßig an. Ein Umstellen des Standards auf „aus" würde bei
  Bestandsinstanzen ohne gespeicherten Wert auch bestehende Verdichtungsregeln im Archiv zurücksetzen —
  deshalb nur nach Entscheidung und mit eigener Migration, nicht nebenbei.

## 0.32.4-beta.1 (2026-10-18)

- **Symcon-Start: `MeterHubVirtual` löscht beim Start keine Ausgabevariablen mehr (Store-Review-
  Checkliste Punkt 17, Hinweis von EMS aus dem HeishaMon-Review).** Im Baum-Modus lösen sich die
  Datenpunkte der Mitglieder über Fremdinstanzen auf (`MetersOfDevice()` fragt deren
  `*_GetFunctions`-Vertrag). Beim Symcon-Start sind die noch nicht instanziiert; fiel die Ausgabeliste
  dadurch zu klein aus, hätte `RegisterVariables()` Ausgabevariablen samt Archivhistorie gelöscht.
  Jetzt wird nur noch aufgeräumt, wenn der Kernel `KR_READY` ist; davor wird nur angelegt. Nach
  `KR_READY` läuft `ApplyChanges()` über `MessageSink()` ohnehin erneut und räumt mit vollständigen
  Daten auf. `MeterHub` selbst war nicht betroffen (sein Aufräumen hängt nur an den eigenen
  Einstellungen, nicht an Fremdabfragen).
- **Darstellungen geprüft (Punkt 16):** MeterHub baut keine Presentation-OPTIONS (nur Profile),
  nichts zu ändern; ein Test hält das fest, damit eine künftige Darstellung bewusst gegen die
  Symcon-Doku geprüft wird.
  Prüfstand: `test-virtual.php` Block 56 (Start: nichts gelöscht, mit Gegenprobe bei KR_READY) und 57.
  Keine Formularänderung, daher kein News-Panel-Eintrag.

## 0.32.3-beta.1 (2026-10-13)

- **Sichtbare Texte auf „Gerätetausch" umgestellt** (das Partnermodul MigrationsHub heißt seit
  13.10.2026 Gerätetausch; wer es im Store sucht, findet es unter dem neuen Namen). Betrifft die
  Suche (Statuszeile zur Kopplung, Hinweise zu „Migration vorbereiten", Spaltenüberschrift „Alt-Instanz
  gefunden (Gerätetausch)", Knopf „→ Zur Gerätetausch-Instanz", Meldungen), die Umstiegshinweise im
  Zähler-Formular (Modbus und Inexogy) und die README. Beim ersten Auftreten steht „(vormals
  MigrationsHub)", damit niemand nach dem alten Namen suchen muss. Reine Textänderung: Funktionsnamen
  (0.32.2), Modul-GUID und Verhalten bleiben. Ältere Changelog-Einträge nennen weiter den damaligen
  Namen.

## 0.32.2-beta.1 (2026-10-13)

- **Übernahme-Schnittstelle: Präfix-Umbenennung `MIGHUB_` → `GTAUSCH_` nachgezogen** (Partnermodul
  MigrationsHub heißt jetzt „Gerätetausch", Repo `DG65/Geraetetausch`; Modul-GUID und Parameter
  unverändert, DG65-Toolkit-Regel „Umbenennung auf Funktionsnamen", Meldung der Gerätetausch-Sitzung).
  `MeterHubDiscovery` ruft `GTAUSCH_FindLegacyCandidates()`/`GTAUSCH_PrefillMigration()` zuerst auf
  und fällt auf `MIGHUB_…` zurück, solange nur der alte Stand installiert ist — die Reihenfolge der
  Updates beider Module ist damit egal. Der Rückfall kann entfernt werden, sobald GTAUSCH_ überall
  installiert ist. Prüfstand: `test-discovery-migration.php` Abschnitt 8 (Rückfall, Vorrang,
  Kandidatensuche und Vorbereiten über GTAUSCH_, Statuszeile). Unverändert: die eigene
  `MHUB_GetIdentMapping`. Nur Funktionsnamen; die sichtbaren Texte nennen weiter „MigrationsHub".

## 0.32.1-beta.1 (2026-10-12)

- **Logging über `$this->LogMessage()` statt `IPS_LogMessage()` (Symcon-Store-Review 12.10.2026,
  SUITE.md Store-Review-Checkliste Punkt 15; Reviewer: „Verwende stattdessen bitte das bessere
  $this->LogMessage").** Zehn Stellen in `MeterHub/module.php` umgestellt, jeweils mit Typ
  (`KL_WARNING` für Auffälliges wie abgelehnten Schlüssel, fehlgeschlagene Neuanmeldung,
  Zählertausch, Richtungsbefund; `KL_MESSAGE` für Normales wie Reparatur, Umschalten,
  erfolgreiche Neuanmeldung). Die Instanz erscheint im Protokoll nun als Absender, der
  Instanzname steht deshalb nicht mehr im Text. Der einzige Aufruf in einer Bibliotheksklasse
  (Symbox-Gateway-Client: Hinweis auf die ungetestete Schreib-Ableitung) gibt den Hinweis jetzt
  über `takeNotices()` an die Modulklasse zurück, die ihn nach dem Schreiben protokolliert.
  Prüfstand: `test-virtual.php` Block 55 (kein `IPS_LogMessage(` in den vier `module.php`, jeder
  LogMessage-Aufruf trägt eine KL_*-Konstante), `test-modbus-client.php` (takeNotices()).
  Keine Verhaltens- oder Formularänderung, daher kein News-Panel-Eintrag.

## 0.32.0-beta.1 (2026-10-06)

- **Inexogy: optionale automatische Neuanmeldung aus einem Tresor (Dietmars Frage 06.10.2026:
  „Wäre es nicht denkbar, das Passwort an anderer Stelle zur Verfügung zu stellen?").**
  Ein ungültig gewordener Zugriffsschlüssel (HTTP 401) ließ sich bisher nur von Hand erneuern, weil
  MeterHub das Passwort bewusst nicht speichert. Jetzt nutzt MeterHub einen Tresor
  (Community-Modul **SymconSecrets**, `SEC_GetSecret`), **wenn er da ist und das Passwort enthält**
  — sonst gilt der normale Weg ohne Tresor. Eintrag (Standard `Inexogy`), Feld mit dem Passwort
  (Standard `Pass`) und optional ein Feld mit der E-Mail (Standard `User`, nur genutzt, wenn in der
  Instanz keine eingetragen ist). Bei 401 meldet sich MeterHub dann selbst neu an.
  - **Selbst erkannt, nicht geraten:** Ohne Auswahl nutzt MeterHub den Tresor automatisch, wenn
    GENAU EIN Tresor Eintrag und Feld mit Inhalt hat. Bei mehreren wird nicht geraten (⚠️, bitte
    wählen). Eine ausdrückliche Auswahl gewinnt immer und wird nicht still durch einen anderen
    Tresor ersetzt. Die Checkbox „Tresor für die automatische Neuanmeldung nutzen, wenn vorhanden"
    (Standard an) schaltet es ganz ab.
  - **Hinweis auf die Tresor-Möglichkeit, wo sie nützt:** Lehnt Inexogy den Schlüssel ab und ist
    kein Tresor in Benutzung, steht in der 401-Zeile (und im Systemprotokoll) ein Tipp mit dem
    Verweis auf „SymconSecrets" und den Abschnitt „Automatische Neuanmeldung". Ist ein Tresor in
    Benutzung, nennt die Zeile stattdessen den Stand der automatischen Neuanmeldung. Hat man die
    Tresor-Nutzung bewusst abgeschaltet, wird nicht weiter geworben.
  - **Passwort nie in MeterHub:** nur für die Dauer der Anmeldung im Arbeitsspeicher, in keinem
    Protokoll, Attribut oder Formularfeld (Prüfstand 54c/54e, mit Gegenprobe: ein absichtlich
    eingebautes Leck wird gefunden).
  - **Abstand zwischen Versuchen:** mindestens 15 Minuten, nach jedem Fehlversuch doppelt so
    lang (Obergrenze 6 h) — ein falsches Passwort soll das Inexogy-Konto nicht überhäufen. Eine
    von Hand geglückte Anmeldung setzt die Wartezeit zurück.
  - **Statuszeile zum Tresor** (live, folgt der Eingabe im offenen Formular): ℹ️ kein Tresor,
    ⚠️ Tresor-Modul nicht geladen / Instanz fehlt / Eintrag nicht gefunden / Feld fehlt (mit
    den vorhandenen Feldern) / Feld leer, ✅ Passwort gefunden (nur die LÄNGE wird gezeigt) samt
    Kurzbericht des letzten automatischen Versuchs.
  - **„Anmelden und Zähler abrufen"** kommt bei gewähltem Tresor und leerem Passwortfeld ohne
    eingetipptes Passwort aus.
  - **Live gemessen statt aus der README geraten:** `SEC_GetSecret($id, 'Eintrag/Feld')` lieferte
    bei Dietmar nichts, `SEC_GetSecret($id, 'Eintrag')` dagegen ein JSON mit allen Feldern —
    deshalb liest MeterHub den ganzen Eintrag und zieht das Feld selbst heraus.
  - **Hinweise vorab:** Ist noch kein Tresor gewählt, sagt die Zeile, ob das Tresor-Modul
    installiert ist und welche Instanz(en) es schon gibt (zum Wählen) bzw. dass noch keine
    angelegt ist oder das Modul im Store fehlt. Wählt man versehentlich eine andere Instanz (die
    Auswahl zeigt alle), steht dort „ist kein Tresor" statt eines irreführenden „Eintrag nicht
    gefunden" — geprüft über das Modul-Präfix der Instanz.
  - Ohne Tresor ändert sich nichts. Der Aufruf steht hinter `function_exists('SEC_GetSecret')`.
  - Intern: der Handshake ist aus `InexogyLogin()` in `InexogyHandshake()` herausgelöst (ein
    Weg für Knopf und Automatik), der Client über `NewInexogyClient()` ersetzbar — dadurch ist die
    Automatik im Prüfstand ohne Netzzugriff prüfbar (`test-virtual.php` Block 54).

## 0.31.8-beta.1 (2026-10-06)

- **Inexogy: „Zugriffsschlüssel abgelehnt" (HTTP 401) wird erkannt und klar gemeldet.** Anlass:
  Dietmars Instanz zeigte „✅ Bei Inexogy angemeldet … Die letzte Abfrage ist fehlgeschlagen" und
  der Lastgang-Nachtrag meldete nur „HTTP 401 (leere Antwort)" — zweiter Fall nach dem 24.08.
  Laut Inexogy-Dokumentation kann ein Zugriffsschlüssel ungültig werden; dann muss die Anmeldung
  wiederholt werden. Der Screenshot zeigte gespeicherte Tokens (also kein Attribut-Verlust nach
  einem Modul-Reload) mit abgelehnter Abfrage. Jetzt: der Client merkt sich den HTTP-Code, der
  Treiber setzt bei 401 das neue Attribut `InexogyAuthRejected` (nur bei Wechsel geschrieben,
  einmal im Systemprotokoll), die Statuszeile sagt „⚠️ Inexogy lehnt den gespeicherten
  Zugriffsschlüssel ab (HTTP 401) … neu anmelden" samt Weg; ein sonstiger Abfragefehler ist
  nicht mehr ✅, sondern ⚠️. Der Nachtrag hängt bei 401 denselben Hinweis an seine Meldung. Eine
  erfolgreiche Abfrage oder Anmeldung hebt den Zustand wieder auf. Automatisch erneuern geht
  nicht: das Passwort speichern wir bewusst nicht (Zugangsdaten-Konvention).
  Prüfstand: `test-virtual.php` 50b3, `test-inexogy.php` (401/500/Erfolg am Treiber).

## 0.31.7-beta.1 (2026-09-28)

- **Fix: Store-Review-Fund 9m, weiterer Fall (EMS-Nachtrag zur systematischen Verbund-Suche).**
  `MeterHub::RegisterVar()` setzte `IPS_SetPosition()` bisher außerhalb des Neuanlage-Zweigs — bei
  JEDEM `ApplyChanges()` erneut, für jede bereits vorhandene Variable (im Gegensatz zu
  `EnsureCategory()` im selben Modul, die es schon immer nur bei Neuanlage tut). Eine Variable, die
  im Objektbaum von Hand umsortiert wurde, fiel dadurch bei jedem „Übernehmen" auf die feste
  Treiber-Reihenfolge zurück. Dasselbe Muster fand sich — beim eigenen Durchsehen aller
  `IPS_SetPosition()`-Aufrufe aus demselben Anlass — auch in `MeterHubVirtual::RegisterVariables()`
  (die drei Ausgabevariablen `power`/`energy_import`/`energy_export`) und `EnsureGroupVariables()`
  (Gruppenschalter/-status). Fix an allen vier Stellen identisch: Position nur setzen, wenn die
  Variable in diesem Aufruf neu angelegt wurde (`$created`-Flag) — 1:1 dasselbe Muster, mit dem
  InverterHub denselben Fund an eigener Stelle behoben hat.
  Prüfstand: `test-virtual.php` Block 52 (`RegisterVar()`: Neuanlage setzt Position, ein erneuter
  Durchlauf mit von Hand geänderten Positionen lässt sie unangetastet, Name/Ident bleiben gepflegt,
  eine wirklich neue Variable bekommt weiterhin ihre Position) und Block 53 (dieselbe Prüfung für
  `MeterHubVirtual`s eigene Ausgaben).

## 0.31.6-beta.1 (2026-09-28)

- **Fix: `ApplyChanges()`+`UpdateFormField()`-Antipattern (HeishaMon-Fund im Symcon-Store-Review,
  weitergegeben 23.09.2026).** Zwei Stellen betroffen, beide korrigiert:
  - **`InexogyLogin()`:** rief `IPS_SetProperty()`+`IPS_ApplyChanges()` (Passwort-Löschung) bisher
    VOR den `UpdateFormField()`-Aufrufen auf, die danach laut Reviewer-Zitat „wirkungslos" sind
    (bestätigt gegen die offizielle Doku: `IPS_SetProperty()` plant den Wert nur, erst
    `IPS_ApplyChanges()` aktiviert ihn — ganz weglassen verbietet sich hier, das Passwort muss
    sofort wirklich gelöscht werden). Fix: Reihenfolge getauscht, das Löschen steht jetzt ganz am
    Ende. Die frisch gefundene Zählerliste zusätzlich im neuen Attribut `InexogyMeterOptions`
    gecacht, damit sie auch den durch `IPS_ApplyChanges()` ausgelösten Formular-Reload übersteht
    (vorher wäre sie dabei verloren gegangen — ein echter, bisher unbemerkter Anzeigefehler direkt
    nach jeder erfolgreichen Anmeldung, nicht nur ein Store-Review-Formalismus).
  - **`MeterHubVirtual::ReconcileFormMembers()`** (Mitglieder-Reihenfolge im Baum-Modus): setzte
    beim „Übernehmen" die Positionen neu, sobald die eingereichte Reihenfolge vom Baum JETZT abwich
    — das überschrieb auch eine Umsortierung von außen (z. B. ein Ziehen im Objektbaum, während die
    Maske offen war), obwohl der Nutzer die Liste selbst gar nicht angefasst hatte. Fix: Vergleich
    jetzt gegen den Stand beim Öffnen (`FormSnapshot`) statt gegen den Baum jetzt — nur ein echtes
    Umsortieren im Formular (oder ein neues Mitglied an anderer Stelle als am Ende) löst noch eine
    Neupositionierung aus.
  - Prüfstand: `test-virtual.php` Block 50b2 (Zählerliste übersteht den Reload, alle drei
    Cache-Zustände) und 35m2 (externe Umsortierung bleibt erhalten, echtes Umsortieren wirkt
    weiterhin). Keine Formular-/Vertragsänderung, daher kein News-Panel-Eintrag.

## 0.31.5-beta.1 (2026-09-21)

- **Vertrag `GetFunctions` erweitert — additiv, kein bestehendes Feld ändert sich (Anlass: Prognose
  nutzt `function='house'` automatisch als Lernmaterial und muss wissen, ob ein Zählerstand gemessen ist).**
  - **MeterHubVirtual 1.5: `energyMeasured`**, an der Instanz **und** an der Zuordnung. `false`,
    sobald ein eingehender Term (Anteil ≠ 0, nicht ausgesetzt) einen hochgerechneten Zählerstand hat:
    eine eigene „Energie hochgerechnet"-Variable, eine Variable, die ein MeterHub als hochgerechnet
    meldet, oder ein verketteter virtueller Zähler, der selbst `false` meldet. Vorher fehlte das Feld
    und galt damit fälschlich als „gemessen". Auf der Instanz-Ebene, weil ein Zwischenknoten ohne
    Funktion keine Zuordnung hat und die Verkettung das Feld trotzdem braucht.
  - **MeterHub 1.4: `calculatedEnergyIDs`** (Instanz-Ebene): die Variablen-IDs mit hochgerechnetem
    Zählerstand (blue'Log-Datenlogger, Adresse 97), auch wenn dem Zähler keine Funktion zugeordnet ist.
    Grundlage für den virtuellen Zähler; die Zuordnungs-Liste kennt nur Zähler mit Funktion.
  - Konsumenten ohne Kenntnis der neuen Felder sind nicht betroffen (Standard: fehlt das Feld → `true`).
    Nichts davon ist im Formular sichtbar, daher kein Eintrag im News-Panel.
  - Prüfstand: `test-virtual.php` Block 51 (eigene Hochrechnung, MeterHub-Meldung, Gegenprobe, Anteil 0,
    Verkettung, Fremdvariable), `test-auto-backfill.php` 9c/9d. Gegenprobe mit stillgelegter
    Ermittlung: drei Prüfungen schlagen an.

## 0.31.4-beta.1 (2026-09-21)

- **Verbund-Verbindungen im Formular sichtbar machen (neue verbindliche SUITE.md-Konvention,
  21.09.2026: „woher soll ich wissen, ob die Verbindung zustande gekommen ist und welche Werte
  übernommen wurden?").** Jede automatische Verbindung bekommt eine live in
  `GetConfigurationForm()` berechnete Statuszeile — ✅ verbunden (Instanz, Name, welche Werte mit
  Quelle), ⚠️ verbunden, aber nichts Brauchbares, ℹ️ nicht gefunden (was dann gilt), ⛔ Pflichtangabe
  fehlt. Die Messung der vier Formulare gegen die Konvention ergab diese Lücken, alle geschlossen:
  - **MeterHub — Brücke (Symbox-Weg):** bisher nur statische Hinweise. Jetzt eine Zeile mit
    Brücke, Gateway, Unit-ID und Quelle („DeviceID am Gateway") bzw. ⛔ keine/fehlende Brücke, ⚠️
    Brücke ohne Gateway oder Gateway inaktiv. Sie folgt der Auswahl im offenen Formular
    (`onChange`), nicht erst dem gespeicherten Stand.
  - **MeterHub — Inexogy:** beim Öffnen war nicht erkennbar, ob die Anmeldung besteht. Jetzt ⛔ nicht
    angemeldet, ⚠️ angemeldet ohne Zähler-UID, ✅ angemeldet mit UID, letzter Abfrage und aktueller
    Leistung samt Quelle; aktualisiert sich nach der Anmeldung.
  - **MeterHub — Archiv:** fand MeterHub kein Archiv, wurde stillschweigend nichts aufgezeichnet.
    Jetzt ✅ mit Archiv-Instanz, ⚠️ bei mehreren Archiven (das erste wird verwendet), ℹ️ „nicht
    aufgezeichnet, Verdichtung entfällt".
  - **MeterHubDiscovery — MigrationsHub:** nur der statische Satz „Voraussetzung: MigrationsHub ist
    installiert". Jetzt eine Zeile, die IMMER im Formular steht (auch wenn die Migrations-Knöpfe
    ausgeblendet sind): ℹ️ nicht installiert, ℹ️ installiert ohne Instanz, ✅ Instanz mit ID und Name,
    ⚠️ mehrere Instanzen (nicht raten).
  - **MeterHubBridge:** die Zeile nannte weder Gateway-ID noch -Name. Jetzt „Verbunden mit ModBus
    Gateway #ID „Name" — Unit-ID N (Quelle: DeviceID am Gateway)".
  - **MeterHubVirtual:** die ✅-Vorschau der Formel nannte je Term nur den Namen. Jetzt zusätzlich
    die gelesene Variable („[Quelle #ID „Name"]") — bei automatisch am Ziel gefundenen Datenpunkten
    sonst nicht erkennbar. (Die ❌-Zeile bei Formelfehlern bleibt: sie meldet ungültige Eingaben,
    keine fehlende Verbindung.)
- **Wert kommt automatisch: Eingabefeld ersetzen (SUITE.md-Konvention 21.09.2026).** Im
  Symbox-Gateway-Weg kommt die Unit-ID automatisch vom „ModBus Gateway" hinter der Brücke. Das
  Eingabefeld „Unit ID" war dort schon ausgeblendet; jetzt steht an seiner Stelle eine
  schreibgeschützte Zeile „🔗 Unit-ID: N (automatisch vom ModBus Gateway #ID „Name", Property
  „DeviceID")" — bzw. „ℹ️ noch nicht verfügbar", solange keine mit einem Gateway verbundene Brücke
  gewählt ist. Die 🔗-Zeile ist grün (Label-Farbe 0x2E8B3D, beim Aktualisieren mitgesetzt, sonst
  Standardfarbe). Sie folgt der Brücken-Auswahl im offenen Formular und dem Wechsel des
  Verbindungswegs. Der Wert wird bewusst nie in die Property „UnitId" geschrieben (er soll dem
  Gateway folgen). Geprüft an den übrigen Feldern: Brücke, Archiv, Inexogy-Zähler-UID (Auswahl,
  nichts kommt automatisch) und die Suche (Adressbereich ist ein Startvorschlag zum Bearbeiten,
  kein nachgeführter Wert) haben kein Feld, das eine Automatik überholt.
- **Vertrag `GetFunctions` klargestellt (nur Text, kein Feld geändert; Frage von EMS/Prognose):**
  Vorzeichen von `powerID` je Funktion (`house` + Verbrauch, `grid` + Bezug, `pv`/`battery` nicht
  festgelegt) und der Unterschied `measured` (nur virtueller Zähler) / `energyMeasured` (nur echter
  MeterHub) stehen jetzt in README („Vorzeichen-Konvention") und CLAUDE.md.
- **Prüfstand nach den zwei Fallen der Konvention:** die Zeile wird im ausgelieferten JSON
  **rekursiv** über alle `items` gesucht (nicht nur auf oberster Ebene) und je Zustand geprüft
  (`test-virtual.php` 50, `test-bridge.php` 3e/7, `test-discovery-migration.php` 1/1b/2/7).
  Gegenprobe mit absichtlich umbenannten Zeilen: die Tests schlagen an.
- Offen, bewusst nicht Teil dieses Stands: Der Auflösungsweg der Datenpunkte am Ziel (Vertrag
  `*_GetFunctions` mit Version, bekannter Ident oder Suche) und die Schalter-Erkennung sind in der
  Formelvorschau noch nicht genannt — dafür müsste `MetersOfDevice()` den Weg mitliefern.

## 0.31.3-beta.1 (2026-09-20)

- **Einheitliche Anzeigenamen, kein Modul mehr doppelt im Anlege-Dialog** (Forum-Feedback: „Was
  ist der Unterschied zwischen Energiezählersuche und MeterHubDiscovery?"). Jeder Alias in der
  `module.json` erscheint in „Instanz hinzufügen" als eigener Eintrag — die Suche stand deshalb
  als „Energiezähler Suche" UND „NRG-Stack MeterHubDiscovery" da, dasselbe Modul unter zwei Namen;
  bei den anderen Modulen ebenso. Jetzt genau ein Alias je Modul: „NRG-Stack MeterHub",
  „NRG-Stack MeterHub Virtueller Zähler", „NRG-Stack MeterHub Suche" (statt „…Discovery",
  einheitlich deutsch und wie „NRG-Stack InverterHub Suche") und „NRG-Stack MeterHub Brücke
  (ModBus-Gateway)". Betrifft nur die Anzeige im Dialog und den vorgeschlagenen Instanznamen
  NEUER Instanzen — bestehende Instanzen und Modulnamen/GUIDs/Prefixe bleiben unverändert.
  Preis: Die Suchbegriffe „Energiezähler" und „Virtueller Zähler" aus den entfernten Aliasen
  finden die Module im Schnellfilter nicht mehr.
- Prüfstand `test-bridge.php` 6e: jedes Modul hat genau einen Alias nach dem Muster.

## 0.31.2-beta.1 (2026-09-20)

- **Eastron SDM120, SDM220, SDM230 bestätigt:** Der Forum-Tester meldete am 20.09.2026, dass alle
  drei an seiner Symbox funktionieren. Im Dropdown steht jetzt „einphasig" ohne „experimentell",
  die README führt sie in der Hauptliste statt unter „Experimentell", Doku-Panel und News-Eintrag
  entsprechend. (Beruht auf der Meldung „funktionieren alle"; einen ausdrücklichen
  Wertevergleich mit der Geräteanzeige gab es dabei nicht.)
- **Einphasige Zähler ohne Messmodus:** Bei SDM120/220/230 bot die Funktionszuordnung „Dreiphasig —
  ein Verbraucher über alle 3 Phasen" oder „Einphasig getrennt — 3 unabhängige Verbraucher" — für
  ein Gerät mit einer Phase sinnlos (Tester-Hinweis mit Screenshot). Die Auswahl ist dort
  ausgeblendet, stattdessen steht ein Hinweis „Dieser Zähler misst nur eine Phase — die Funktion
  gilt für das ganze Gerät"; es gibt nur ein Zuordnungsfeld. Ein früher gespeicherter Wert „je
  Phase" wirkt bei einem einphasigen Zähler nicht mehr, auch nicht im `MHUB_GetFunctions`-Vertrag
  (`measureMode` ist dort immer `combined`). Beim Wechsel des Zählertyps im offenen Formular
  schalten die Felder live um.
- **Statuszeile „Bitte Verbindung einstellen." in jedem Verbindungsweg gleich:** Die Zeile ganz oben
  sagte bei einer neuen Instanz „Bitte Verbindung vervollständigen (IP-Adresse bzw. Inexogy-
  Anmeldung)" — passt nicht mehr, seit es die Symbox-Anbindung gibt. Außerdem folgt die Statuszeile
  dem GESPEICHERTEN Stand: wer im offenen Formular den Verbindungsweg wechselte, sah weiter den
  Text des alten Wegs („Brücke eintragen" trotz „Direkt"). Symcon lässt sich die Statuszeile nicht
  live umschalten, deshalb jetzt ein neutraler Text für 104. Status 201 (nur bei laufender
  Instanz) nennt im Gateway-Modus weiter Brücke und Gateway.
- Prüfstand `test-virtual.php` Block 47 (Statustexte), 48 (Dropdown ohne „experimentell") und
  neu 49 (einphasiger Messmodus: ausgeblendet, ein Zuordnungsfeld, gespeichertes „je Phase"
  wirkungslos, dreiphasige Zähler unverändert).

