# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

An diesen Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet:

- **MeterHub** (dieses Repo): Energiezähler per Modbus TCP — https://github.com/DG65/NRGMeterHub
- **InverterHub**: Wechselrichter per Modbus TCP — https://github.com/DG65/NRGInverterHub
  (lokale Arbeitskopie: `../InverterHub`)
- **Prognose** (Suite EnergiePrognose): PV- und Verbrauchsprognose —
  https://github.com/DG65/NRGPrognose (lokale Arbeitskopie: `../Prognose`)
- **ChargerHub**: Wallboxen per Modbus TCP — https://github.com/DG65/NRGChargerHub
- **MigrationsHub**: Übernahme von Bestandsgeräten samt Archivwerten —
  https://github.com/DG65/NRGMigrationsHub
- **EMS**: Energiemanagement, Steuerungshoheit über den Verbund
- **WPHub**: Panasonic Comfort Cloud (Wärmepumpe) — liest seit 20.08.2026 optional/lesend
  unseren `MHUB_GetFunctions($id)`-Vertrag (siehe unten)

**MeterHub koppelt direkt an InverterHub und (seit 20.08.2026, rein lesend) WPHub.** Zum
Prognose-Repo besteht derzeit keine Verbindung; es ist hier nur zur Orientierung genannt, weil
an allen dreien parallel gearbeitet wird. Die Prognose ist ihrerseits an den
`InverterHubMonitor` gekoppelt (Vertrag dort: `PVF_Get*`). Sollte MeterHub jemals
Prognosewerte einbeziehen, ist das vorher mit der Prognose-Sitzung abzustimmen — nichts
eigenmächtig in fremden Repos anlegen.

**ChargerHub und MigrationsHub** sind seit dem 21.07.2026 eigene Repos mit eigenen Sitzungen,
zunächst als Gerüst (v0.1.0) ohne Fachlogik. Für MeterHub folgt daraus vor allem eines: Ein
**Migrationswerkzeug gehört nicht hierher.** Wer von einem Fremdmodul auf MeterHub umsteigt und
seine Archivwerte behalten will, wird von MigrationsHub bedient. Anfragen in diese Richtung
also dorthin verweisen, statt hier eine zweite Lösung zu bauen. Ebenso ist ChargerHub der Ort
für Wallboxen — MeterHub misst eine Wallbox allenfalls als Zähler (Funktion `wallbox1…5`), es
steuert sie nicht.

**Koordination:** Dietmar ist der zentrale Ansprechpartner für den gesamten Verbund. Die
Modul-Sitzungen werden von ihm direkt angesprochen, wenn es um **modulspezifische** Aufgaben
geht; alles Übergreifende läuft über ihn. Sitzung-zu-Sitzung-Nachrichten bleiben deshalb dem
vorbehalten, was zwei Module technisch unmittelbar verbindet — beim MeterHub ist das die
Kopplung an InverterHub (Kachel und Sankey) und, seit 20.08.2026, WPHub als lesender
`MHUB_GetFunctions`-Konsument (siehe unten). Keine unaufgeforderten Rundnachrichten an neue
Sitzungen; wenn eine andere Sitzung etwas von hier braucht, geht das über Dietmar.

## Kopplung an InverterHub

Beide Module sind eigenständig lauffähig und koppeln nur optional aneinander. Die
Berührungspunkte:

| Berührungspunkt | Wo im Code |
|---|---|
| Kombinierte Gerätesuche (ein Scan findet WR **und** Zähler; Zähler werden als MeterHub-Instanz angeboten) | `InverterHubDiscovery/module.php` (dort `METERHUB_GUID`) |
| Verbraucher-Kreise der Stromflusskachel aus der Funktionszuordnung | `InverterHubTile/module.php` (`CONSUMER_TYPES`, `MHUB_TYPE_MAP`), Icons im `ICONS`-Objekt in `InverterHubTile/module.html` |
| Datenschnittstelle für beides | `MHUB_GetFunctions($id)` in `MeterHub/module.php` — liefert Modus, Zuordnungen und Variablen-IDs als JSON |

**Grundregel:** Keines der Module darf das andere voraussetzen. Fehlt das jeweils andere,
entfallen nur die Zusatzfunktionen — es darf nichts brechen. Auf der InverterHub-Seite sichert
das ein `function_exists('MHUB_GetFunctions')`-Guard ab.

**Wenn sich `MHUB_GetFunctions` ändert** (Feldnamen, Struktur), muss die Gegenseite in
`InverterHubTile/module.php` mitgezogen werden — das ist ein Vertrag zwischen den Repos.
Ebenso gilt: Neue Funktionen im Vokabular `FUNCTIONS` brauchen einen Eintrag in
`MHUB_TYPE_MAP` der Kachel, sonst fallen sie dort stillschweigend raus. Kernwerte (`grid`,
`house`, `pv`, `battery`, `none`) sind dort bewusst **nicht** gemappt.

**Zweiter Konsument seit 20.08.2026: WPHub.** Rein lesend, hinter
`function_exists('MHUB_GetFunctions')`, kein eigenes Vertragsfeld — sucht in allen
MeterHub-Instanzen nach `assignments[].function === 'heatpump'` und bietet dem Nutzer eine
Ein-Klick-Übernahme von `powerID`/`energyImportID` in die eigenen `Ext_PowerVariable`/
`Ext_EnergyVariable`-Felder an (nie automatisch). Genutzte Felder: `function`, `label`,
`powerID`, `energyImportID`. Eine Änderung an `MHUB_GetFunctions` betrifft also **beide**
Konsumenten (InverterHubTile **und** WPHub), nicht nur die Kachel.

Drei Invarianten der Kopplung (identisch in der CLAUDE.md von InverterHub):

1. **Verbraucher-Arten nur in `CONSUMER_TYPES` pflegen.** Die Auswahlliste der Spalte „Art"
   erzeugt `injectConsumerTypeOptions()` in `GetConfigurationForm` zur Laufzeit und
   überschreibt dabei die statischen `options` der `form.json`. Wer eine Art nur dort
   einträgt, erzeugt ein stilles Auseinanderlaufen.
2. **Vorzeichen des Netz-Kernwerts wird negiert.** MeterHub zählt `+` = Bezug, die Kachel
   `+` = Einspeisung.
3. **`form.json` nicht maschinell umformatieren** (siehe Commit-Regeln unten).

## Kopplung an MigrationsHub (Verbund-Konvention 29.07.2026)

Optionale Kopplung, mit MigrationsHub abgestimmt (kein Pflicht-Partnermodul, alles hinter
`function_exists()`) — `MeterHubDiscovery::LegacyCandidateFor()`/`PrepareMigration()` in
`MeterHubDiscovery/module.php`. Anlass: Dietmars Wunsch, dass Migration Teil des normalen
Geräte-Scans wird statt separates Werkzeug — „Wallbox/Zähler finden und Zähler bereitstellen"
muss ohne manuellen Eingriff funktionieren, für jeden Hersteller.

**Vertrag:** `MIGHUB_FindLegacyCandidates($id, string $host, int $port, int $unitId,
int $excludeInstanceID): array` (Match-Schlüssel Host+Unit-ID, **nie über den Namen** —
MigrationsHub selbst ist zweimal auf Namens-Fallen reingelaufen; `$excludeInstanceID` =
die eigene frisch angelegte Zielinstanz, damit sie nicht als vermeintliche Alt-Instanz
zurückkommt), `MIGHUB_PrefillMigration($id, $oldInstanceID,
$newInstanceID): void` (setzt Source/Target auf einer MigrationsHub-Instanz, stößt NICHT
automatisch Simulieren/Ausführen an — bewusste Sicherheitskette bei einem destruktiven
Vorgang, bleibt Nutzeraktion im MigrationsHub-Formular).

**Live-Bruch 30.08.2026 — zwei Lehren, beide im Code verankert
(`LegacyCandidateFor()`):** Als MigrationsHub an Dietmars Anlage erstmals wirklich
installiert war, tötete unser Aufruf das komplette Konfigurationsformular
(`ArgumentCountError`): MigrationsHub hatte der veröffentlichten Funktion einen 5.
Parameter gegeben (mit PHP-Default — aber PREFIX_-Wrapper honorieren Defaults nicht,
SUITE.md-Stolperstein, alle Argumente sind Pflicht), und wir übergaben zusätzlich die
FALSCHE Dispatch-Instanz (`$this->InstanceID` statt einer MigrationsHub-Instanz — nie
aufgefallen, weil `function_exists()` bis dahin immer false war und der Fehlpfad nie
lief). Konsequenzen: (1) Aufruf jetzt in `try/catch (\Throwable)` — ein `@` hält
Fatals nachweislich nicht auf; ein künftiger Vertragsbruch des Partnermoduls degradiert
zu „kein Kandidat" statt das Formular zu töten. (2) `LegacyCandidateFor()` legt bewusst
KEINE MigrationsHub-Instanz an (GetConfigurationForm() darf keine Instanzen erzeugen);
`PrepareMigration()` legt sie beim ersten Klick VOR der Kandidatensuche an — sonst
Henne-Ei (keine Instanz → keine Kandidaten → nie eine Instanz).

**Bewusst nur ein Treffer je `PrepareMigration()`-Aufruf:** ein zweiter Aufruf vor Abschluss
der ersten Migration würde die noch unbestätigte Source/Target-Zuordnung auf derselben
MigrationsHub-Instanz überschreiben. Bei mehreren gefundenen Alt-Instanzen erst die laufende
Migration abschließen, dann erneut klicken.

**`Configurator` (das Formularelement der Fund-Liste) hat laut offizieller Doku keine
interaktiven Spalten/Buttons je Zeile** — nur Anzeige plus die eingebaute Erstellen-Aktion.
Deshalb ein eigener Button unterhalb der Liste statt eines Elements in der Zeile selbst;
`PrepareMigration()` findet die passende(n) Zeile(n) serverseitig erneut (Scan-Ergebnisse +
bereits erstellte Instanzen), nicht über eine Zeilenauswahl im Formular.

**Bei Treffer kommt die neu erstellte Instanz automatisch mit `Active=false`** — verhindert
von vornherein die überlappende Historie, die der allgemeine Migrations-Hinweis (0.20.10)
bisher nur beschrieb, ohne es zu erzwingen.

Verifiziert in `.tools/test-discovery-migration.php` (20 Prüfungen, eigener Prüfstand mit
IPSModule-Stub nach demselben Muster wie `test-virtual.php`).

**Zweiter Teil des Vertrags — `MHUB_GetIdentMapping($id, string $foreignModuleGUID, array
$foreignIdents): array`** (`MeterHub/module.php`, `GetIdentMapping()`): löst genau die
Preflight-Sonden-Fragilität, die MigrationsHub bei der eigentlichen Übernahme (Reparenting)
hatte — `PruneForeignObjects()` löscht bei uns wie bei anderen Hub-Modulen jede Kind-Variable
mit unbekanntem Ident sofort, MigrationsHub musste das bisher per Wegwerf-Variable+
`ApplyChanges()`-Testlauf erraten. Reine Auskunft, **MeterHub reparentet/benennt selbst
nichts um** — das bleibt komplett bei MigrationsHub inkl. deren Nutzer-Review vor der
Ausführung (bewusst so entschieden, nicht die zuerst diskutierte
`AdoptFromLegacyInstance($identMap)`-Variante, die MeterHub selbst hätte reparenten lassen —
Ident-Entscheidungshoheit bleibt zentral bei MigrationsHub, siehe auch die Grundsatzfrage
weiter oben im Verbund).

`$foreignIdents` (die tatsächlich vorhandenen Idents der Alt-Instanz) sind Pflicht, nicht
optional: manche Alt-Module benennen dasselbe Feld je nach Firmware/Version unterschiedlich
(unser eigener Inexogy-Treiber behandelt genau deshalb sowohl `power1/2/3` als auch
`phase1Power/2Power/3Power` als dieselbe Größe) — eine Zuordnung allein nach Modul-GUID wäre
nicht eindeutig auflösbar. Bekannte Alt-Modul-Tabelle ist bewusst **statisch im Code**
(`static $known` in `GetIdentMapping()`), nicht dynamisch — neue Alt-Module dort ergänzen,
Idents nur aus einer echten, live abgelesenen Installation übernehmen (nicht aus der
Modul-Doku raten, siehe „Registerkarten: erst messen, dann glauben" unten). Verifiziert in
`.tools/test-ident-mapping.php` (9 Prüfungen).

## Hilfsordner im Wurzelverzeichnis müssen mit einem Punkt beginnen

Die Store-Prüfung von IP-Symcon behandelt **jeden sichtbaren Ordner im Repo-Wurzelverzeichnis
als Modul-Kandidaten** und verlangt dort eine `module.json`. Ein Ordner `tools/` lässt die
Einreichung mit „Das Modul tools hat keine module.json" scheitern.

**Regel für alles Künftige** (Skripte, Testdaten, CI): entweder mit führendem Punkt benennen
(`.tools/`) oder unterhalb eines bestehenden Modulordners ablegen.

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Findet Aufrufe fremder Modulfunktionen (`IHUB*_`, `PVF_`, `HEISHA_` …), denen **in derselben
Funktion** ein `function_exists()`-Wächter fehlt. Das ist kein Stilthema: Der Aufruf einer
nicht vorhandenen Funktion ist in PHP ein **Fatal Error**, und ein vorangestelltes `@`
unterdrückt ihn **nicht** — es unterdrückt nur Warnungen. Ohne Wächter bräche die Instanz
hart ab, statt die Zusatzfunktion wegzulassen.

MeterHub ist derzeit reiner **Anbieter** (`MHUB_GetFunctions`) und ruft kein fremdes Modul
auf — die Prüfung meldet also 0 Aufrufe. Sie läuft trotzdem mit, damit eine künftige Kopplung
nicht unbemerkt ungesichert hereinkommt. `MHUB`/`MHUBD` stehen bewusst **nicht** in
`FOREIGN_PREFIXES`, das sind die eigenen Präfixe.

Das Skript stammt aus dem InverterHub-Repo (dort `.tools/check-standalone.php`); Änderungen an
der Prüflogik bitte in beiden Repos gleich halten.

## Klassengrenzen prüfen: `.tools/check-class-scope.php`

```
php .tools/check-class-scope.php    # 0 = sauber, 1 = klassenfremder Aufruf
```

Meldet ein `$this->foo()`, wenn `foo()` in der aufrufenden Klasse (und ihren Vorfahren
innerhalb derselben Datei) fehlt, aber in einer **anderen** Klasse derselben Datei existiert.

Der Anlass ist ein realer Fehler aus dem InverterHub: Treiberklassen enthalten wortgleiche
Codeblöcke, weil sie dasselbe Protokoll bedienen. Ein Textersatz traf deshalb den erstbesten
Treffer statt den gemeinten — eine Methode landete im SMA-Treiber, aufgerufen wurde sie im
Fronius-Treiber. Ergebnis: **Fatal Error in jedem Lesezyklus** der betroffenen Geräte.
`php -l` sieht das nicht, denn syntaktisch ist die Datei einwandfrei.

Gegengeprüft am echten Fall: Auf dem defekten Stand (InverterHub build 146) meldet das Skript
beide Hälften des Fehlers mit Klassen- und Zeilenangabe, auf dem reparierten (build 147)
meldet es sauber.

Die Meldung ist bewusst auf „existiert woanders in der Datei" eingeschränkt. Von `IPSModule`
geerbte Methoden sind nirgends in der Datei definiert und lösen deshalb keinen Fehlalarm aus.
Die Analyse arbeitet auf PHP-Token, nicht auf Textsuche — Kommentare und Zeichenketten können
keine Fundstellen vortäuschen.

**Praktische Konsequenz für die Arbeit an Treiberdateien:** Vor einem Textersatz in
`MeterHub/module.php` (13 Treiberklassen) prüfen, in welcher Klasse die Fundstelle liegt.
`Edit` mit eindeutigem Kontext statt `replace_all`, danach dieses Skript laufen lassen.

## Globale Klassennamen brauchen einen Modul-Präfix (Verbund-Konvention 25.07.2026)

**Auslöser:** Der erste echte EMS-Discovery-Test lud MeterHub, ChargerHub und InverterHub im
selben PHP-Prozess — alle drei hatten unabhängig voneinander eine globale Klasse
`ModbusTcpClient` deklariert. Solange die Module einzeln liefen, nie ein Problem; sobald ein
Konsument mehrere lädt: `Fatal error: Cannot redeclare class ModbusTcpClient`. Ein
`class_exists()`-Guard hätte das nur kaschiert (zufällig gewinnt, wer zuerst lädt — eine
stille Fehlerquelle statt eines klaren Fehlers), deshalb Umbenennung statt Guard.

**Alle 13 globalen Nicht-Modul-Klassen/-Interfaces in diesem Repo tragen den Präfix
`MHUB_`:** `MHUB_ModbusTcpClient`, `MHUB_MeterDriverInterface`, `MHUB_InexogyClient`,
`MHUB_InexogyDriver` und die zehn `MHUB_<Hersteller>Driver`-Klassen. **Ausnahme:** die drei
Modul-Klassen `MeterHub`/`MeterHubDiscovery`/`MeterHubVirtual` bleiben unpräfixiert — ihr
Name muss exakt dem `name`-Feld der jeweiligen `module.json` entsprechen (siehe
[[ips-module-pitfalls]]), ein Präfix würde die Instanzerzeugung brechen.

**Vor jeder neuen globalen Hilfsklasse** (Modbus-/HTTP-Client, Interface, Treiber) kurz
prüfen, ob der naheliegende Name schon vergeben sein könnte, und gleich mit `MHUB_` anlegen
— nicht erst, wenn der nächste Konsument beide Module lädt und es kracht. Umbenennungen sind
mit `.tools/check-class-scope.php` (Klassengrenzen) und den `.tools/test-*.php`-Prüfständen
sicher zu verifizieren: wortgrenzen-basierter Ersatz (`\bKlassenname\b`) trifft sowohl nackte
Bezeichner (`new X`, `implements X`) als auch die Zählertyp→Treiber-Zuordnung in
`MeterHub::DRIVERS`, wo die Klassennamen als Zeichenketten stehen.

## Emoji-Regel (Verbund-Entscheidung 23.07.2026, permissiv)

Emoji sind **erwünscht, wo sie Nutzen stiften** — Dietmar schätzt sie für Fokus und
Auflockerung, und kein Symcon-Store-Review hat je eines beanstandet. Zwei Einsatzarten:

- **Panel-/Schaltflächen-Icon** als Ersatz für das fehlende `icon`-Feld der ExpansionPanels
  (`📖 Dokumentation`, `🔌 Verbindung`, `🔎 Netzwerk durchsuchen`).
- **Status-/Aufmerksamkeitssymbol** dort, wo etwas herausgestellt werden soll oder Beachtung
  braucht (`✅` `❌` `⚠️` `💡` `ℹ️` in Meldungen und Hinweistexten).

Keine feste Obergrenze, aber mit Augenmaß — als Akzent, nicht als Flächenfüller. Es gibt keinen
Zwang, Symbole durch Wörter zu ersetzen. (Zwischenzeitlich, am 23.07.2026, galt kurz eine
strengere „nur Panel-Icon"-Fassung; sie wurde von Dietmar zugunsten dieser permissiveren Regel
zurückgenommen — Historie im Changelog 0.16.3/0.16.4.) **Beobachtungsklausel:** Falls ein
echter Stable-Review Emoji je bemängelt, entscheidet der Verbund gemeinsam neu. MigrationsHub
führt die einheitliche Checklisten-Zeile für alle Module.

## Sprachregel: alles Nutzersichtbare auf Deutsch

Verbund-Regel seit 22.07.2026 (Anweisung Dietmars an alle zehn Module). Deutsch ist alles, was
der Nutzer zu sehen bekommt: Formularbeschriftungen, Hinweis- und Warntexte, Fehler- und
Statusmeldungen, Rückgabetexte (etwa das `reason`-Feld eines Ergebnisses), Protokollmeldungen,
**Variablen- und Profilnamen**, README und Changelog. Vermeidbare Anglizismen werden ersetzt:
Scan → Suche, Button → Schaltfläche, Dry-Run → Probelauf, Link → Verknüpfung, Event → Ereignis,
Polling → Abfragetakt, Framework → Gerüst.

**Ausgenommen — und diese Grenze ist wichtiger als die Regel selbst:**

- **Idents sind API und werden nie umbenannt.** Ein umbenannter Ident erzeugt eine neue Variable
  und wirft die Historie der alten weg. Das gilt genauso für Property-, Methoden-, Klassen- und
  Feldnamen: `ScanMeters`, `ScanRoot`, `ScanResult`, `BtnScan`, `power_total` bleiben, wie sie
  sind — auch wenn „Scan" im Anzeigetext ersetzt wird.
- Feststehende Fachbegriffe: Modbus TCP, `SelectVariable`, WebFront, `AC_ChangeVariableID`,
  Float32/CDAB, Unit-ID. Eindeutschung würde hier Verständlichkeit kosten, nicht schaffen.
- Eigennamen: Modulnamen (`MeterHubDiscovery`, `MeterHubVirtual`), Hersteller- und
  Produktbezeichnungen, Registernamen aus Herstellerdoku (`usePvSurplus`, `modelStatus`).

Praktisch heißt das: Beim Aufräumen die Trefferliste immer danach trennen, ob eine Fundstelle
in einem String steht, den ein Mensch liest, oder in einem Bezeichner, den Code auflöst. Im
Zweifel ist es ein Bezeichner — dann bleibt er.

**Nach dem Ersetzen den Diff lesen, nicht nur `replace_all` laufen lassen.** Ein Wort-für-Wort-
Tausch bricht Sätze auf zwei Arten, die keine Prüfung automatisch findet:

- *Grammatik* — mit dem Wort ändert sich das Genus. Aus „nicht-blockierender Parallel-Scan"
  (m.) muss „nicht-blockierende, parallele Suche" (f.) werden, nicht „nicht-blockierender
  Suche". Ebenso „der Netzwerk-Scan" → „die Netzwerksuche".
- *Bedeutung* — das Ersatzwort passt grammatisch, meint aber etwas anderes. Real passiert:
  „Zähler … lassen sich nicht sinnvoll **scannen**" wurde zu „… **durchsuchen**". Man
  durchsucht aber nicht die Zähler; die Suche findet sie nicht. Richtig: „Zähler hinter
  RTU-Gateways findet die Suche nicht zuverlässig." Bei „scannen" ist genau zu unterscheiden,
  ob etwas *abgesucht* wird (Adressbereich) oder *gefunden* werden soll (Gerät).

Und nicht überdehnen: Eindeutschen, wo es das Verständnis **verbessert** (Token →
Zugangsschlüssel, Polling → Abfragetakt); stehen lassen, wo der englische Begriff der
Fachbegriff **ist** (Modbus TCP, CDAB, Unit-ID, WebFront, SunSpec). `'type' => 'Button'` ist
ein Formularelementtyp, also Code — kein Anzeigetext.

## Prüfstand: `.tools/test-virtual.php`

```
php .tools/test-virtual.php    # 0 = alle Prüfungen bestanden
```

Bildet so viel IP-Symcon nach (Objektbaum, Variablen, Properties, `IPS_CreateInstance`,
`IPS_ApplyChanges`), dass `MHUB_CreateVirtual` und `MHUBV_ScanMeters` **wirklich ausgeführt**
werden. Geprüft wird die ganze Kette: Knotenaufbau beider Rollen, Übernahme der erzeugten
Verdrahtung durch das Zielmodul, die gerechneten Summen und Reste, die Ablehnung eines zweiten
virtuellen Zählers, alle vier Suchfilter und der Rückkopplungsschutz.

Anlass ist ein früherer Laufzeitfehler in genau diesem Modul: Typografische Anführungszeichen
hatten Variablennamen verschluckt, `php -l` meldete nichts, und der Fehler zeigte sich erst am
Gerät. Nach Änderungen an der Brücke oder am Suchlauf diesen Prüfstand laufen lassen — ein
Syntaxcheck allein genügt für dieses Modul nachweislich nicht.

`IPS_ApplyChanges()` erzeugt im Prüfstand absichtlich ein echtes `MeterHubVirtual`-Objekt und
ruft dessen `ApplyChanges()` auf. Damit ist der Test kein Selbstgespräch des Hauptmoduls,
sondern deckt genau die Stelle ab, an der die beiden Module sich einig sein müssen.

## Zählersuche schließt bekannte NRG-Stack-Module aus (Verbund-Entscheidung 25.07.2026)

**Auslöser:** Erster Praxistest an Dietmars Installation. Der Suchbereich war auf seine
Geräte-Wurzelkategorie gesetzt — praktisch die ganze Installation — und der Suchlauf fand
neben echten Steckdosen/Schaltern auch 197 Zeilen, von denen viele interne, berechnete
Variablen anderer NRG-Stack-Module waren (EMS-Hauslast, PV-Prognose, Tibber-Erlöse, Batterie-
Aggregate …). Technisch korrekt gefunden (W/kWh-Profil vorhanden), fachlich aber kein
Fremdzähler. Dietmars Begründung für den Ausschluss: (1) diese Werte sind „im NRG-Stack schon
beheimatet", tauchen an ihrer eigentlichen Stelle korrekt auf; (2) Zirkularitätsrisiko — eine
vom EMS berechnete Hauslast, die selbst aus MeterHub-Rohdaten stammt, könnte sonst in einen
virtuellen Zähler einfließen, der wieder in dieselbe Berechnung zurückwirkt.

**Umsetzung:** `MHUBV::EXCLUDED_NRG_STACK_MODULES` — eine Liste bekannter Modul-GUIDs, live an
Dietmars Installation abgelesen (`IPS_GetModuleList()` + `IPS_GetModule()`, gefiltert auf
`URL` mit `github.com/DG65` oder bekanntes Präfix), **nicht geraten**. `BelongsToExcludedModule()`
läuft von der Kandidaten-Variable bis zur Wurzel und prüft jede Vorfahren-Instanz gegen die
Liste — nicht nur die nächste, falls Instanzen verschachtelt sind (z. B. hinter einem
I/O-Splitter). Läuft in `ScanMeters()` bewusst **nach** den billigen Filtern (schon
verwendet, kein W/kWh-Profil, außerhalb des Suchbereichs) — der Elternketten-Walk ist der
teuerste Schritt und soll nur Kandidaten treffen, die alle anderen Filter schon passiert haben.

**Bewusst NICHT ausgeschlossen:** MeterHub selbst — dessen Instanzen sind der Zweck der Suche,
nicht das, wovor sie schützen soll. `MeterHubVirtual` ist über den bestehenden
`$ownOutputs`-Mechanismus bereits präziser abgedeckt (nur die tatsächlichen Ausgabevariablen,
nicht die ganze Instanz).

## Grundregel: keine eigene Anlage als Norm annehmen (Verbund-Konvention 27.07.2026)

Volle Herleitung/Beispiele in `EMS/SUITE.md` — hier nur die Prüffrage für dieses Repo: **„Gilt
das für JEDEN Nutzer, oder nur für meine/Dietmars eigene Anlage?"** Bei jedem neuen
Formularfeld/Default/Hilfetext anwenden. Ausgelöst durch denselben Fehler an mehreren
NRG-Stack-Modulen unabhängig (EMS: „Goodwe SmartMeter (Pflicht)" obwohl nur ein Beispiel;
ChargerHub; hier: `Meter`-Standardwert `siemens_pac2200` stand in der manuellen
Instanz-Anlage ohne Hinweis, dass es nur ein Platzhalter ist — behoben mit einem immer
sichtbaren Label vor der Auswahl, siehe Changelog 0.20.12).

**Bei dieser Gelegenheit (27.07.2026) gegen alle drei Module durchgeprüft, keine weiteren
Funde:** Property-Standardwerte in `MeterHub`/`MeterHubVirtual`/`MeterHubDiscovery` sind
durchgehend generisch (`Port` 502 = Modbus-Standard, `UnitId` 1 = verbreiteter Werks-Default,
`Host`/`ScanFilter`/`NameTemplate` leer) — keine eigenen IDs, PLZ-Gebiete oder
Vertrags-/Teilnahme-Flags hartkodiert. `MeterHubDiscovery`s IP-Bereichsvorschlag
(`guessLocalSubnetPrefix()`) wird zur Laufzeit aus dem eigenen Netzwerkinterface abgeleitet,
nicht als fester Wert im Code hinterlegt — genau das von der Regel geforderte Verhalten.

**Pflege:** Bei jedem neuen NRG-Stack-Mitglied die Liste ergänzen — sonst taucht dessen Modul
unbemerkt wieder als „Fremdzähler" im Suchlauf auf. Verifiziert in `.tools/test-virtual.php`
(Block 6b): eine Variable innerhalb einer simulierten EMS-Instanz wird nicht vorgeschlagen,
ein echtes Gerät direkt daneben schon.

## Konvention für `*_GetFunctions`-Verträge (Referenz für neue Partnermodule)

`MHUB_GetFunctions($id)` ist die Referenzimplementierung dieses Musters. Wer ein weiteres
Modul anbindet (HeishaMon, StromGedacht, Tibber, EMS …), sollte sich daran orientieren —
sonst kostet jeder neue Partner eine eigene Übersetzungsschicht in Kachel und Sankey.

**Empfohlene Struktur** — eine **Liste** von Einträgen, auch wenn es zunächst nur einen gibt
(so bricht eine spätere Aufteilung, z. B. Verdichter und Heizstab getrennt, die Signatur nicht):

| Feld | Bedeutung |
|---|---|
| `function` | Rolle als Schlüssel aus einem festen Vokabular (z. B. `heatpump`, `wallbox1`) |
| `label` | Anzeigename, vom Nutzer überschreibbar |
| `powerID` | Variablen-ID der Momentanleistung in **W**, `0` = nicht vorhanden |
| `energyImportID` | Variablen-ID des **kumulativen** kWh-Zählers, `0` = keiner |
| `energyExportID` | dito für Einspeisung, `0` = keine |
| `measured` | `bool` — ist `powerID` gemessen oder geschätzt? |
| `energyKind` | `'counter'` (kumulativer Zählerstand → Konsument bildet Differenzen) oder `'interval'` (fertiger Periodenverbrauch → Konsument summiert) |

Dazu auf **Instanz-Ebene** (neben `instanceID`/`meter`/`measureMode`), abgestimmt im Verbund am
22.07.2026:

| Feld | Bedeutung |
|---|---|
| `contractVersion` | `'Major.Minor'`-String der Vertragsversion (siehe unten) |
| `latency` | `'realtime'` (lokal, in Sekunden regelbar) oder `'delayed'` (Cloud-API mit Latenz) — Regelfähigkeit |
| `authority` | `'billing'` (geeichter, abrechnungsverbindlicher Zähler am Netzübergabepunkt) oder `'auxiliary'` (Hilfszähler) |
| `pollInterval` | reale Aktualisierungsrate in Sekunden |
| `sourceCount` | nur MHUBV: Zahl der beteiligten Quellen eines Rest-/Summenknotens (Güte) |
| `archiveWatermarkTs` | nur bei `latency: 'delayed'`: Unix-Zeitstempel des letzten **vollständig archivierten** Datensatzes, sonst `null`. Bei `'realtime'` bewusst `null` statt eines Werts — dort ist Archiv praktisch verzögerungsfrei, eine Abfrage brächte keinen Erkenntnisgewinn |

**`latency` und `authority` sind orthogonal, keine Gegenteile** — alle vier Kombinationen
existieren real: Inexogy (billing+delayed), lokaler Shelly am NAP (auxiliary+realtime), ein
lokal ausgelesenes iMSys (billing+realtime), ein virtueller Rest-Knoten (auxiliary+realtime).
Ein einzelnes „billingGrade"-Flag könnte das nicht trennen; deshalb zwei Felder. Konservative
Defaults bei fehlenden Feldern (alter Anbieter): `latency→realtime`, `authority→auxiliary`,
`energyKind→counter`. Konsumentenbedingung für „der abrechnungsgenaue Netzzähler": `function ==
'grid' && authority == 'billing'`, mit Rückfall, wenn keiner vorhanden.

**Herkunft von `archiveWatermarkTs` (27.08.2026):** Dietmars Wunsch, den Zeitstempel des
letzten vorhandenen Lastgang-Datensatzes sichtbar zu machen — im eigenen Formular UND für
Konsumenten wie das Dashboard, dessen Strompreis-Diagramm auf denselben archivierten
MeterHub-Energiezählern aufbaut und deshalb dieselbe Backfill-Verzögerung erbt (siehe
"Automatischer wiederkehrender Lauf" oben). Berechnung teilt sich MeterHub selbst mit
`InexogyArchiveWatermark()` (Minimum über `energy_import`/`energy_export`/`power_total`,
je nur ein `AC_GetLoggedValues(..., Limit=1)`-Aufruf).

**Vertragsversionierung (Verbund-Konvention 23.07.2026, Manifest `DG65/EMS/SUITE.md`):**
`contractVersion` ist ein `'Major.Minor'`-String — **1.0** = Ur-Vertrag (function/label/…/
measured), **1.1** = die latency/authority/pollInterval/energyKind/sourceCount-Erweiterung,
**1.2** = `archiveWatermarkTs` (MeterHub, 27.08.2026).
**Major nur bei Bruch;** volle Kompatibilität ist nur innerhalb derselben Major garantiert
(blue'Log-Prinzip). Additiv erweitern hebt die Minor, nie die Major. Fehlt das Feld (alter
Anbieter), ist konservativ `'1.0'` anzunehmen. Ein Konsument, der eine höhere Major braucht als
der Anbieter liefert, läuft **eigenständig weiter, deaktiviert die Kopplung und meldet das
sichtbar** (Instanzstatus/Formular) — kein harter Abbruch. Die Modul-SemVer (`library.json`)
ist davon unabhängig und hat ihren eigenen Takt.

**`latency`/`authority`/`pollInterval` stehen an ZWEI Orten: auf Instanz-Ebene UND in jede
Zuordnung gespiegelt.** Grund: Ein Konsument, der über `assignments[]` iteriert und nach
`function` filtert (so macht es die InverterHub-Netzbezug-Auswertung), liest `authority` direkt
an der Zuordnung, ohne zum Instanz-Objekt zurückzuspringen. Beide Orte stammen im selben
Aufruf aus derselben Property — sie können nicht auseinanderlaufen. Die Redundanz ist bewusst:
Sie löst die Erbungslogik einmal beim Anbieter statt bei jedem Konsumenten. Merksatz:
**Zähler-Eigenschaften (latency/authority/pollInterval) an beiden Orten, Zuordnungs-
Eigenschaften (energyKind/sourceCount) nur je Zuordnung.**

**Regeln aus konkreten Vorfällen:**

1. **Ein veröffentlichter Vertrag wird nicht umbenannt.** Sobald ein Modul im Store ist, sind
   Feldnamen öffentliche API. Abweichende Namen (HeishaMon nutzt `Type`/`Caption`/`PowerID`/
   `EnergyID`/`Measured`) werden auf der **Konsumentenseite** übersetzt, nicht beim Anbieter
   erzwungen. Änderungen dort nur **additiv und nach Ankündigung**.
2. **Genauigkeit braucht ein eigenes Flag.** Nicht aus `energyImportID == 0` ableiten, ob ein
   Wert gemessen ist — beide Eigenschaften sind unabhängig. Wer nur eine Leistungsvariable
   zuweist, hat einen *gemessenen* Wert *ohne* Energiezähler. Genau daran wäre die
   HeishaMon-Anbindung fast falsch geworden; deshalb gibt es dort jetzt `Measured`.
3. **Energie nur aus kumulativen Zählern.** Tages-/Monatswerte, die periodisch auf 0
   zurückspringen, taugen nicht für Bilanzen (die Auswertung bildet Zählerdifferenzen). Fehlt
   ein kumulativer Zähler, wird die Größe **weggelassen** — niemals aus der Leistung
   hochrechnen.
4. **Immer hinter `function_exists('XXX_GetFunctions')`.** Das Partnermodul ist optional; ohne
   es muss alles unverändert laufen.
5. **Das Suffix `ID` kennzeichnet eine Referenz, es ist keine Stilregel.** `powerID` heißt
   „hier steht eine IPS-Variablen-ID, die du auflösen musst" — im Unterschied zu einem
   direkt verwertbaren Wert. Verträge, die **Werte** statt Referenzen liefern (z. B. eine
   Preiskurve mit `start`/`end`/`price`), benennen diese passend zu ihrer Domäne und
   übernehmen dabei ruhig das Vokabular der Datenquelle. Was fehlt, muss die Doku tragen:
   **Einheit und Zeitbasis** (ct/kWh vs. €/kWh, Unix-Zeit, ob `end` inklusiv oder exklusiv
   ist) sind die klassischen Faktor-100- und Randfehler.
6. **Abgeleitete Felder brauchen eine definierte Herleitung — oder sie sind optional.**
   Liefert eine Quelle ein Feld nativ und eine andere nicht (etwa eine Einstufung wie
   „günstig/teuer"), muss entweder die Berechnung verbindlich festgeschrieben sein oder das
   Feld ausdrücklich `null` sein dürfen. Sonst verhält sich der Konsument je nach Quelle
   unterschiedlich, ohne dass es jemand bemerkt.

Intern werden alle Quellen auf dieselbe Zeilenstruktur normalisiert (siehe
`MeterHubAssignments()` / `HeishaAssignments()` in `InverterHubTile` und `InverterHubEnergy`).
Ein neuer Partner heißt also: eine Einleseschicht ergänzen, nicht die Verarbeitung anfassen.

## Zugangsdaten bei Cloud-/API-Zählern (Verbund-Konvention 23.07.2026)

MeterHubs Inexogy-Anbindung (`MHUB_InexogyClient` in `MeterHub/module.php`) ist die Referenz­
implementierung dieser verbundweiten Regel — nichts hier anzupassen, nur zur Einordnung:

1. **Handshake-/Token-Verfahren bevorzugen.** Existiert eines, dient das Passwort nur dem
   einmaligen Handshake und wird danach **nicht gespeichert** — nur Token/Secret bleiben liegen.
2. Ein Passwort wird nur dauerhaft gespeichert, wenn es wirklich wiederholt gebraucht wird
   (kein Token-Verfahren verfügbar). Handshake-Weg hat Vorrang.
3. **Speicherort: `RegisterAttributeString`, nicht Property** — nicht im Formular sichtbar.
4. **Technischer Vorbehalt:** IP-Symcon verschlüsselt nicht at rest. „Sicher" heißt hier „nicht
   im Formular/Log/Anzeigetext sichtbar", nicht „verschlüsselt".
5. Formulareingabe als `PasswordTextBox`, Wert nach dem Handshake sofort geleert.

Umgesetzt in `InexogyLogin()`: E-Mail/Passwort nur für den OAuth-1.0a-Handshake, danach nur
Consumer-/Access-Token in Attributen; `InexogyPassword` wird nach erfolgreichem Handshake
sowohl als Property geleert (`IPS_SetProperty` + `IPS_ApplyChanges`) als auch im offenen
Formular (`UpdateFormField`), damit kein Klartext-Rest sichtbar bleibt.

## Gemeinsame NRG-Stack-Profile (Verbund-Konvention 24.07.2026)

Sechs physikalische Grundgrößen bekommen einen gemeinsamen `NRG.*`-Präfix statt eines
Profils je Modul: `NRG.Watt`, `NRG.kWh`, `NRG.Ampere`, `NRG.Volt`, `NRG.Percent`,
`NRG.Celsius`. MeterHub führt davon aktuell fünf (kein Temperatursensor, daher kein
`NRG.Celsius`). Modulspezifische Status-/Enum-Profile bleiben beim eigenen `MHB.*`-Präfix:
`MHB.Hz`, `MHB.VA`, `MHB.var`, `MHB.PF`, `MHB.Wh` (Wh ist nicht in den sechs, nur kWh),
`MHB.PhaseSeq` (Enum).

**Anlage ist idempotent, MeterHub ist NICHT Eigentümer:** `ensureSharedProfile()` (Pendant zu
`ensureProfile()`, das für die modulspezifischen Profile weiterhin Digits/Suffix bei jedem
`ApplyChanges` durchsetzt) prüft nur `IPS_VariableProfileExists()` und legt **ausschließlich
bei Fehlen** an — eine bereits von einem anderen NRG-Stack-Modul angelegte Definition wird
**nicht** überschrieben. Das ist eine bewusste Verschärfung gegenüber dem sonst in diesem
Repo üblichen Muster (durchsetzendes `ensureProfile()`): Für ein Profil, das mehrere Module
teilen, wäre fortlaufendes Überschreiben ein stiller Konflikt um die Deutungshoheit.

**Migration bestehender Instanzen läuft automatisch, kein manueller Schritt nötig.**
`RegisterVar()`/`RegisterVariables()` vergleichen bei jedem `ApplyChanges` das Ziel-Profil
der Variablendefinition gegen `VariableCustomProfile` der existierenden Variable und rufen
bei Abweichung `IPS_SetVariableCustomProfile()` — das war schon vor dieser Änderung so
(kein neues Verhalten) und migriert bestehende `MHB.W`/`MHB.V`/`MHB.A`/`MHB.kWh`/
`MHB.Percent`-Variablen beim nächsten Zyklus automatisch auf `NRG.*`. Die alten Profile
werden dabei nicht gelöscht (könnten anderswo noch referenziert sein), nur nicht mehr aktiv
gepflegt.

Verifiziert in `.tools/test-virtual.php` (Block 9): ein fremd mit abweichenden Werten
angelegtes `NRG.Watt` bleibt bei erneutem `ensureSharedProfile()`-Aufruf unangetastet; ein
fehlendes Profil wird korrekt angelegt; modulspezifische Profile werden weiterhin durchgesetzt.

## Einheitliche Formular-Optik (Verbund-Konvention 24.07.2026) — geplant, noch nicht umgesetzt

Standard für alle NRG-Stack-Module (Details: `EMS/SUITE.md`, „Einheitliche Formular-Optik").

**Pflege ist Pflicht bei jedem Fix/Update, nicht nur bei großen Releases** (Ergänzung Dietmar,
24.07.2026): Bei **jeder** Änderung an einem Formular kurz prüfen, ob dort etwas ins
News-Panel gehört (ein neues Feld, ein geändertes Verhalten) — das Ergebnis darf „nein" sein,
aber die Prüfung muss stattfinden, nicht nur bei größeren Versionssprüngen.

**Layout-Qualität allgemein** (dieselbe Ergänzung): logische Gruppierung der Felder,
Bedienung Schritt für Schritt ohne Scroll-Zickzack (verwandte Einstellungen nicht über
mehrere weit auseinanderliegende Panels verstreuen), Feldkanten auf einer Linie statt kreuz
und quer (einheitliche Spaltenbreiten innerhalb eines Panels).

Reihenfolge von oben:

1. **„🆕 Neu in Version X.Y"** — aufgeklappt, **pro Version** dismissible (ein Attribut merkt
   die zuletzt bestätigte Version; erscheint bei jeder neuen Version wieder). Keine
   Versionsnummer im Panel-Inhalt — die steht nur in der Caption.
2. **„📖 Dokumentation & Hilfe"** — eingeklappt. Hier, nicht im News-Panel, gehört die
   Versionsnummer als Text hin.
3. **Fachpanels** — neue/wichtige Felder mit `🆕`-Präfix im Label kennzeichnen.
4. **Symcon-Forum-Hinweis** nach den Haupteinstellungen, **einmalig** dismissible (kein
   Versionsbezug, bleibt weg sobald bestätigt).

**Referenzimplementierung: InverterHub** (`InverterHub/module.php`, gelesen 24.07.2026) —
Mechanik, keine Wort-für-Wort-Vorlage (nicht alle vier Teile sind dort schon vollständig
umgesetzt, z. B. steht die Versionsnummer dort noch nicht sichtbar im Doku-Panel):

```php
private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';   // einmalig (Forum-Hinweis)
// News-Panel: Attribut 'SeenNews' speichert die zuletzt bestätigte NEWS_VERSION (pro Version)
private function newsBanner() {
    if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) { return null; }
    // … Panel mit Bullet-Liste + Button 'Verstanden – nicht mehr anzeigen' …
    return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, …];
}
// im Formular ganz vorn einhängen:
$banner = $this->newsBanner();
if ($banner !== null) { array_unshift($form['elements'], $banner); }

public function AckNews() {
    $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
    $this->UpdateFormField('NewsPanel', 'visible', false);   // NIE IPS_SetProperty+ApplyChanges
}
```

Der Forum-Hinweis folgt demselben Muster mit einem `bool`-Attribut statt einem Versionsstring.
**Wichtig:** ausschließlich `UpdateFormField` + Attribut — kein `IPS_SetProperty`/
`IPS_ApplyChanges` im Dismiss-Handler (Store-Review-Regel, siehe `check-standalone`-Nachbarn
in diesem Dokument).

**In MeterHubVirtual seit 31.08.2026 umgesetzt** (Teile 1–3; Dietmars Auftrag „hier maximal
nachbessern", ausgelöst durch eine echte Verdrahtungs-Verwirrung im Praxistest von Sepp): News-
Panel (`NEWS_VERSION`-Konstante, Attribut `SeenNews`, Button `MHUBV_AckNews($id)`) + komplett
neu geschriebenes Doku-Panel mit Versionsnummer als Text (nicht in der Caption) + `🆕`-Präfix an
den neuen/wichtigen Doku-Zeilen. Referenzimplementierung jetzt `MeterHubVirtual::NewsBanner()`/
`AckNews()`/`GetConfigurationForm()`, nicht mehr nur InverterHub — bei Bedarf von dort
kopieren statt neu zu entwerfen. Verifiziert in `.tools/test-virtual.php` Block 11 (News-Panel
erscheint/verschwindet korrekt, Doku-Panel enthält Versionsnummer + alle Verdrahtungs-Muster).

**Bei MeterHub/MeterHubDiscovery weiterhin nicht umgesetzt** (kein aktueller Anlass, dieselben
Gründe wie bisher):
- Ausdrücklich als nicht eilig markiert („bei Gelegenheit nachziehen").
- **Blockierende Abhängigkeit für Teil 4 (Forum-Hinweis, gilt weiter für alle drei Module
  inkl. MeterHubVirtual):** bräuchte einen echten Link zum MeterHub-Thread — der Entwurf in
  `.forum/ankuendigung.md` ist noch nicht veröffentlicht, es gibt also noch keine URL zum
  Verlinken. Erst nach Veröffentlichung einbauen.
- Beide haben wie MeterHubVirtual **kein separates `form.json`** — Struktur passt also direkt
  hinein, sobald angegangen (kein technisches Hindernis, nur bisher kein Anlass).

## Formularfelder live umschalten: `onChange` + `UpdateFormField`, nicht `PropertyCondition`

**Auslöser:** Dietmar legte eine Inexogy-Instanz an und sah weiterhin das Host-Feld
(IP-Adresse) — für einen Cloud-Zähler nichts Sinnvolles. Ursache: Das Verbindungspanel
(`InexogyEmail`/… vs. `Host`/`Port`/`UnitId` in `GetConfigurationForm()`) wählte ein reiner
PHP-`if`/`else` auf den **gespeicherten** Zählertyp — die Funktion läuft aber nur beim Öffnen
der Maske, nicht bei jeder Auswahländerung. Wer im offenen Formular auf „Inexogy" umschaltete,
sah also weiter das alte Panel samt IP-Pflichtregex.

**Zwei mögliche Techniken, eine davon unverifiziert:** IP-Symcon kennt `"visible": {"type":
"PropertyCondition", "property": …, "value": …}` (belegt in `EMS/EMS/form.json`) — dort aber
nur mit **einzelnem Wert** (`true`/`2`), nie mit Array oder Negation. Für „sichtbar bei genau
einem von 19 Zählertyp-Werten, unsichtbar bei den anderen 18" hätte das eine ungeprüfte Annahme
gebraucht (Array-Wert? `!=`? `Or`-Wrapper?) — laut offizieller Doku und Community-Suche nicht
belegt. Statt zu raten: die **dokumentierte** Alternative aus dem Symcon-Forum verwendet, die
für beliebig viele Quellwerte funktioniert, ohne Negation zu brauchen — `onChange` am
Select-Feld ruft eine PHP-Methode, die beide Feldgruppen per `UpdateFormField('name',
'visible', …)` umschaltet:

```php
['type' => 'Select', 'name' => 'Meter', 'onChange' => 'MHUB_OnChangeMeter($id, $Meter);', …]

public function OnChangeMeter($meter) {
    $isCloud = in_array($meter, self::CLOUD_METERS, true);
    foreach ([...Inexogy-Feldnamen...] as $f) { $this->UpdateFormField($f, 'visible', $isCloud); }
    $this->UpdateFormField('Host', 'visible', !$isCloud);
    $this->UpdateFormField('Host', 'validate', $isCloud ? '' : '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$');
    …
}
```

**Beide Feldgruppen stehen dafür immer im Formular** (nicht mehr per PHP-`if` weggelassen),
Anfangszustand weiter aus dem gespeicherten Wert berechnet. **Wichtig:** Nicht nur `'visible'`
umschalten — auch ein `'validate'`-Regex (wie beim Host-Feld) bleibt sonst möglicherweise an
einem unsichtbaren Feld aktiv und blockiert „Übernehmen" weiterhin still. `'validate' => ''`
beim Ausblenden entschärft das zusätzlich, unabhängig davon, ob Symcon versteckte Felder
überhaupt noch prüft. `$Meter` als Parameter im `onChange`-String liefert den *gerade
gewählten, noch ungespeicherten* Wert — dasselbe Muster wie `$ScanRoot`/`$ScanFilter` bei
`MHUBV_ScanMeters($id, …)` in `MeterHubVirtual/module.php`.

**Wo das Muster noch fehlt:** `MeasureMode` (kombiniert/je Phase) schaltet die
Funktionszuordnungs-Felder bisher ebenfalls nur bei „Übernehmen" um (siehe Label „Nach dem
Umschalten einmal Übernehmen …" in `GetConfigurationForm()`) — bewusst nicht mitgezogen, da
nicht gemeldet und ein größerer Umbau (Felder je Phase vs. gesamt sind strukturell
verschieden, nicht nur ein-/ausblendbar).

## MeterHubVirtual: Bereinigung nie bei Fehlerzustand ausführen (Vorfall 25.07.2026)

**Auslöser:** An Dietmars Installation eine einzelne tote Zeile aus der Verdrahtung von
#16933 entfernt (196 statt 197 Zeilen) — direkt danach hatte die Instanz **null** Kinder mehr.
Ursache: `RegisterVariables(array $errors)` berechnete `$defs = $errors ? [] : OutputDefs();`
und löschte danach jede vorhandene Ausgabevariable, deren Ident nicht in `$defs` steckte —
unabhängig davon, ob überhaupt diese eine Zeile betroffen war. Da an dieser Installation
**alle** Zeilen `Parent=''` hatten (flache Verdrahtung, kein Knoten hat Kinder), war
`OutputDefs()` schon vorher permanent leer — jedes erfolgreiche `ApplyChanges()` hätte die
Instanz jederzeit leerräumen können, nicht nur dieses eine Mal.

**Fix (zwei Teile, siehe `RegisterVariables()`/`Validate()` im Code):**

1. `RegisterVariables()` fasst **nichts** an (weder Löschung noch Neuanlage), solange
   `Validate()` irgendeinen Fehler meldet. Ein Fehlerzustand darf nur zu veralteten, aber
   niemals zu gelöschten Ausgaben führen.
2. `Validate()` erkennt zusätzlich „Verdrahtung ergibt keine einzige Summe-/Rest-Ausgabe mehr"
   (kein Knoten hat mehr Kinder) als eigenen Fehler — aber nur, wenn die Instanz **bereits**
   Ausgaben hat (`HasExistingOutputs()`), damit eine brandneue, nie verdrahtete Instanz nicht
   blockiert wird.

**Wieder-hochgezogen als proaktiver Fix, nicht erst auf erneute Meldung gewartet** — passend
zum Verbund-Zielbild „Zuverlässigkeit ohne KI-Krücke" (SUITE.md, DG65/NRGEMS): der Entwurf
stand schon während des Vorfalls, wurde aber wegen der Tragweite zurückgestellt, bis eine
zweite Session ohne Live-Daten-Risiko (die betroffene Instanz war da bereits gelöscht) die
Umsetzung erlaubte.

**Verifiziert in `.tools/test-virtual.php` Block 10:** stellt den realen Vorfall nach (flache
Verdrahtung → Ausgaben bleiben erhalten statt gelöscht, Fehlermeldung erklärt warum), prüft
zusätzlich den allgemeinen Fall (unabhängiger Fehler bei sonst intakter Verdrahtung schützt
ebenso) sowie die Gegenprobe (nach Reparatur läuft alles normal weiter, das Sicherheitsnetz
wird nicht zur Dauerblockade).

## MeterHubVirtual: kinderlose Knoten mit eigenem Zähler bekommen eine Durchreichung (31.08.2026)

**Auslöser:** Praxistest (Sepp, per Dietmar weitergegeben) — eine einzelne Steckdose (Shelly
Plug „Kühlschrank"), nur verdrahtet, um sie über die Funktionszuordnung sichtbar zu machen,
blieb komplett ohne Ausgabe. `Recalc()` meldete „Keine Ausgabe zum Berechnen vorhanden", obwohl
die Zeile einen gültigen `PowerID` trug. Ursache: `OutputDefs()` iterierte bis dahin
ausschließlich über `$kids` (das Ergebnis von `Children()`, also NUR Knoten, die mindestens ein
Kind haben) — ein kinderloser Knoten mit eigenem Zähler wurde nie besucht, unabhängig davon, ob
er selbst Daten trägt. **Nebeneffekt, der bis dahin unbemerkt blieb:** das betraf nicht nur
absichtlich alleinstehende Knoten, sondern JEDES Blatt in einer bestehenden Verdrahtung —
„Wärmepumpe“/„Wallbox“ als Kinder von „Hausanschluss“ hatten selbst nie eine eigene Ausgabe,
nur der Elternknoten. Eine Funktionszuordnung auf einem Kind-Knoten war dadurch über
`MHUBV_GetFunctions()` unsichtbar, ganz ohne Fehlermeldung.

**Fix (`OutputDefs()`):** Schleife läuft jetzt über ALLE Knoten (`$nodes`), nicht mehr nur über
`$kids`. „Summe untergeordnet“ entsteht weiterhin nur bei vorhandenen Kindern; „Rest“ entsteht
weiterhin nur bei eigenem Zähler — aber beide Bedingungen jetzt unabhängig voneinander geprüft,
nicht mehr an „ist Elternknoten" gekoppelt. Ein kinderloser Knoten mit eigenem Zähler bekommt
dadurch eine reine Durchreichungs-Ausgabe (`Rest = eigener Zähler − 0 = eigener Zähler`), mit
angepasster Bezeichnung ohne das irreführende Wort „Rest“, wenn nichts abgezogen wird.

**Zwei Folgeänderungen, beide notwendig, damit der Fix nicht selbst etwas bricht:**
1. `Recalc()`: `$kids[$parent]` durch `$kids[$parent] ?? []` ersetzt — ein rein
   durchreichender Knoten hat keinen Eintrag in `$kids`, ein direkter Zugriff hätte eine
   PHP-Warnung erzeugt.
2. `Validate()`s Sicherheitsnetz aus dem 25.07.2026-Vorfall (oben) geprüfte bisher nur
   `empty($childMap)` — das reicht seit diesem Fix nicht mehr: eine komplett flache Verdrahtung
   (kein Knoten hat Kinder) kann jetzt trotzdem gültige Durchreichungs-Ausgaben haben, wenn
   irgendein Knoten einen eigenen Zähler trägt. Die Bedingung prüft jetzt zusätzlich, ob
   überhaupt ein Knoten `power`/`imp`/`exp` gesetzt hat — nur wenn WEDER Kinder NOCH ein
   einziger eigener Zähler übrig sind, greift die Sperre noch.

**Verifiziert in `.tools/test-virtual.php` Block 10 (erweitert):** 10a prüft jetzt die neue
Durchreichung an einer komplett geflachten, aber weiterhin voll bemessenen Verdrahtung (Status
bleibt aktiv, `_sum_`-Ausgaben verschwinden korrekt, `_rest_`-Durchreichung entsteht für alle
drei Knoten, der Wert entspricht 1:1 dem eigenen Zählerstand). Eine neue 10a-safety deckt den
ECHTEN Sicherheitsnetz-Fall ab (weder Kinder noch irgendein eigener Zähler → weiterhin Sperre).

## Formular-Konvention in MeterHubVirtual umgesetzt (31.08.2026)

Derselbe Anlass wie oben — Dietmars Auftrag „hier maximal nachbessern“ deckte nicht nur den
Rechenkern-Bug auf, sondern auch, dass „das Verfahren zum Verdrahten... überhaupt nicht
beschrieben“ war und die verbundweite Formular-Konvention hier fehlte (siehe „Einheitliche
Formular-Optik“ oben — News-Panel, Doku-Panel mit Versionsnummer). Beides jetzt in
`MeterHubVirtual` umgesetzt: `NewsBanner()`/`AckNews()` nach dem InverterHub-Muster, Doku-Panel
komplett neu geschrieben mit den drei Verdrahtungs-Mustern (reiner Sammelknoten / Zähler mit
Kindern / kinderloser Zähler) und einer Schritt-für-Schritt-Anleitung, die es vorher schlicht
nicht gab.

**Zweite Runde, noch am selben Tag — die Doku allein genügte Dietmar nicht:** Er verwies
explizit auf SUITE.md „Feld-Hilfestellung" (`PopupButton` mit `caption="?"`, da Symcon keinen
Mouseover-Tooltip kennt — gegen die SDK-Doku geprüft, nicht angenommen). Jetzt umgesetzt, **erste
Referenzimplementierung dieser Konvention in diesem Repo**: eine nummerierte
Schritt-für-Schritt-Anleitung direkt IM „🔌 Verdrahtung"-Panel (nicht nur im weit entfernten,
eingeklappten Doku-Panel — am Ort der Handlung, nicht nur im Nachschlagewerk), dazu zwei
„?"-PopupButtons genau an den zwei Stellen, die im Praxistest zu Verwirrung führten: „hängt
hinter" (öffnet ein Popup mit allen drei Mustern samt Beispiel) und „Kürzel" (Historie-Warnung).
`popup`-Struktur (`caption`/`items`) gegen die offizielle SDK-Doku verifiziert. Verifiziert in
`.tools/test-virtual.php` Block 11 (beide PopupButtons vorhanden, Popup-Inhalt enthält die
erwarteten Kernaussagen).

**Layout-Hinweis:** `PopupButton` steht als eigenes, volles Zeilen-Element im Formular — Symcons
Formularsprache kennt kein horizontales Nebeneinander von Label und Button in einer Zeile
(„direkt neben dem Feld" in SUITE.md heißt: unmittelbar in der Element-Reihenfolge danach, nicht
optisch in derselben Zeile). Der Button-Text selbst trägt deshalb den Kontext („3. „hängt
hinter" setzen — bei Bedarf hier klicken: ?"), nicht nur ein nacktes „?".

## `CombineSelected()` — Schnellweg zum Verdrahten (31.08.2026)

**Dritte Runde, noch am selben Tag.** Dietmars eigentlicher Einwand ging tiefer als Doku: „warum
muss ich noch den 'Neuen Virtuellen Zähler' auch anlegen? Das mache ich doch schon mit der
Anlage der Instanz!" — Recherche ergab: Der Einklick-Weg existierte bereits, aber nur für EINEN
von zwei Fällen. `MHUB_CreateVirtual()` (im Hauptmodul `MeterHub/module.php`) legt eine
MeterHubVirtual-Instanz samt Verdrahtung in einem Schritt an — aber NUR aus anderen
MeterHub-Instanzen (`VirtualPartners`-Liste filtert auf `IPS_GetInstanceListByModuleID(GUID_METER)`).
Für beliebige Systemvariablen (Sepps Shelly-Plugs — kein MeterHub, ein fremdes Modul) gab es
diesen Weg nicht; dort blieb nur der umständliche Weg: leere Instanz anlegen, darin suchen,
Zeile für Zeile per „hängt hinter" verdrahten.

**Fix — `MeterHubVirtual::CombineSelected(string $nodesJson, string $target): string`,** neue
Spalte „Auswählen" (CheckBox) in der Verdrahtungs-Liste + Knopf „✅ Ausgewählte zusammenfassen /
abziehen". Zwei Fälle über ein `Select`-Feld `CombineTarget` (Sentinel `__NEU__` vs. Kürzel einer
vorhandenen Zeile, da `''` bei `Parent` schon „oberste Ebene" bedeutet):
- **Neue Sammelzeile** (Muster ①): erzeugt eine zählerlose Zeile, Kürzel kollisionsfrei
  (`sammelzaehler`, `sammelzaehler_2`, …), Name aus den Gerätenamen abgeleitet (≤3 Geräte:
  „A + B + C", sonst „Sammelzähler (N Geräte)"), hängt die Auswahl dahinter.
- **Von einer vorhandenen Zeile abziehen** (Muster ②) — **Dietmars Ergänzung, noch während der
  Umsetzung:** „denke aber bitte auch daran, dass man vielleicht den einen oder anderen Zähler
  von einem anderen Zähler abziehen mag". Keine neue Zeile, die Auswahl hängt direkt hinter dem
  gewählten vorhandenen Zähler — dessen „Rest" schließt sie danach automatisch aus. `$combineOptions`
  markiert Zeilen mit eigenem Zähler im Dropdown sichtbar („↳ von „Hausanschluss" abziehen").

**Bewusste Entwurfsentscheidungen:**
- Schreibt wie `ScanMeters()` nur in die OFFENE Formularmaske (`UpdateFormField('Nodes', 'values', …)`),
  nicht in die gespeicherte Property — „Übernehmen" bleibt der bewusste letzte Schritt.
- Zyklen (Ziel ist Nachfahre der Auswahl) werden NICHT hier abgefangen, sondern vom ohnehin
  vorhandenen `Validate()` bei der nächsten Prüfung — keine Logik doppelt pflegen.
- Selbstbezug (Zielzeile selbst mit ausgewählt) wird beim Verschieben übersprungen, nicht als
  Fehler behandelt — die übrige Auswahl wird trotzdem verdrahtet.
- `$id`/`$Nodes`-Parameterübergabe im `onClick` folgt demselben, bereits verifizierten Muster wie
  `MHUB_CreateVirtual($id, $VirtualPartners, $VirtualRole)` — ein außenstehender Button, der ein
  `List`-Feld beim Namen nennt, bekommt dessen aktuellen (auch unsichtbaren/ungesicherten)
  Zeileninhalt als JSON-String übergeben.

Verifiziert in `.tools/test-virtual.php` Block 12 (neue Sammelzeile inkl. Namensableitung und
Kürzel-Kollision, Abziehen von einer vorhandenen Zeile inkl. Erhalt der Funktionszuordnung, sowie
die Randfälle keine Auswahl / Zielzeile ist die einzige Auswahl / unbekanntes Ziel).

## Parallele Sitzungen: Zuständigkeiten

An beiden Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet. Beide
committen auf denselben Branch `beta`. Vereinbarte Aufteilung:

- **MeterHub-Seite (diese):** das MeterHub-Repo vollständig, plus die Integrationslogik in
  InverterHub — `InverterHubTile/module.php`, `form.json`, `CONSUMER_TYPES`, `MHUB_TYPE_MAP`
  und die Verbraucher-Icons; ebenso die Anbindung von `InverterHubEnergy` (Sankey) an die
  MeterHub-Zähler. Also alles zu Daten und Konfiguration.
- **Darstellungs-Seite:** die Darstellungsschicht in `InverterHubTile/module.html` —
  SVG-Geometrie, CSS, Farben, Filter/Verläufe, Browser-Kompatibilität. Dazu die
  Versionspflege in `library.json` von InverterHub.

**Die Grenze in `module.html` verläuft exakt am `ICONS`-Objekt.** Diese Seite arbeitet dort
ausschließlich: je Verbraucher-Art eine Funktion `name(g)`, die im 32×32-Raster zentriert auf
(0,0) Kindelemente anhängt (`data-hollow` für offene Konturen). Alles außerhalb von `ICONS` —
Filter, Verläufe, Layout, viewBox — gehört der Darstellungs-Seite.

**Versionsnummern in InverterHub:** `library.json` pflegt dort die Darstellungs-Seite. Wer eine
Erhöhung braucht, nennt die gewünschte Nummer, statt die Datei selbst zu bearbeiten. In
**diesem** Repo pflegt die MeterHub-Seite `library.json` und Changelog selbst.

## Regeln fürs Committen

Entstanden aus einem konkreten Vorfall: Ein `git add -A` hat die in Arbeit befindlichen
Änderungen der jeweils anderen Sitzung in einen fremden Commit gezogen, dessen Botschaft sie
nicht beschrieb.

- **Kein `git add -A`.** Nur die Dateien stagen, die man selbst geändert hat.
- **Vor dem Commit `git pull --rebase origin beta`.**
- **Vor dem Committen prüfen**, ob im Arbeitsbaum fremde Änderungen liegen (`git status`,
  `git diff`) — wenn ja, nicht mitcommitten und nicht stashen.
- **Versionsbump und Changelog-Eintrag gehören zusammen** und müssen synchron sein.
- **Den gewünschten Changelog-Text gleich beim Push mitschicken**, nicht danach. Wer in einem
  fremden Repo committet, dessen Version eine andere Sitzung pflegt, kann sonst überholt
  werden: Ein Feature lag bereits auf `beta`, als die andere Seite für einen eigenen Fix
  bumpte — es ging dadurch **ohne Changelog-Eintrag** an die Tester. Der Eintrag wurde
  nachträglich am tatsächlich ausliefernden Release ergänzt (nicht unter einer neuen Nummer,
  die fälschlich Neuheit suggeriert hätte). Umgekehrt gilt: **vor einem Bump prüfen**, ob seit
  dem letzten Release fremde Commits dazugekommen sind, und deren Einträge mitnehmen.
- **Konfigurationsdateien (`form.json`) nie maschinell umformatieren.** Ein `json.dump`-Lauf
  hat dort schon 929 Zeilen für eine 13-zeilige Ergänzung geändert und den Diff unlesbar
  gemacht. Kompakte Handformatierung beibehalten, rein additiv als Text arbeiten.

## Registerkarten: erst messen, dann glauben

Die wichtigste Lehre dieses Projekts: **Registerkarten aus Dokumentation oder Fremdkonfigurationen
sind unzuverlässig.** Wo irgend möglich am echten Gerät gegenprüfen, bevor ein Treiber
ausgeliefert wird.

- **Shelly Pro 3EM** — drei Anläufe: Adressen aus einer ESPHome-Konfig (Basis 1011, FC 0x04,
  ohne Wort-Swap) lieferten Müll; die offizielle Doku führte zu FC 0x03 mit absoluten Adressen
  (31011) und damit zu lauter Modbus-Exceptions. Richtig ist: **FC 0x04, Wire-Adresse =
  Doku-Nummer − 30000, Float32 wortgetauscht (CDAB)**. Ebenso liegen die Energiezähler je Phase
  **nicht** bei 1170/1190/1210 (so legt es die Doku-Übersicht nahe), sondern bei
  **1182/1184, 1202/1204, 1222/1224**. Gefunden durch Dump des Registerfensters und Abgleich
  mit der geräteeigenen RPC-API (`/rpc/EMData.GetStatus?id=0`) — die Probe „L1+L2+L3 =
  Gesamtzähler" war der Beweis.
- **Siemens PAC2200** — Energie ist ein **64-Bit-Double** (4 Register ab 801), und zwischen
  Reg. 41 und 55 klafft eine Lücke: Ein Block-Read darüber hinweg riskiert „Illegal Data
  Address" für die *ganze* Anfrage. Daher zwei getrennte Blöcke (1–42, 55–72).
- **Janitza** — UMG 604/605/509/512/806/96PA/801 teilen sich eine Registerkarte; nur der
  **UMG 800** weicht ab (frei konfigurierbare Werkskarte, Summen an anderer Stelle).
- Als **experimentell** markierte Treiber (Socomec, MBS) sind aus Vorlagen abgeleitet und
  nicht hardwareverifiziert — das gehört sichtbar ins Dropdown und in die README.
- **Gegenrecherche statt Neuentwurf (27.07.2026, Verbund-Erwartung „aktives Engagement"):**
  Für Socomec Countis gegen die offizielle E23-Kommunikationstabelle gegengeprüft (über eine
  Drittquelle, da socomec.fr/us automatisierte Abrufe blockt) — sieben Register
  (Spannung/Strom je Phase, Frequenz, Wirkleistung gesamt) stimmen exakt mit dem bestehenden,
  von OpenEMS abgeleiteten Treiber überein. Der Energiezähler weicht von der (unvollständigen)
  Drittquelle ab — **nicht blind übernommen**, sondern als offene Frage im Treiber-Kommentar
  dokumentiert (`MHUB_SocomecCountisDriver` in `MeterHub/module.php`). Gegenrecherche ersetzt
  keine Hardwareverifikation, senkt aber das Risiko, bevor „experimentell" fällt.

## Historische Werte rückwirkend ins Archiv eintragen (10.08.2026)

Anlass: Inexogy als Dietmars Abrechnungszähler, `/last_reading` liefert nur den Momentanwert,
`/readings` (siehe `ref-inexogy-api`-Memory) den Lastgang. Muster in
`MHUB_BackfillInexogyArchive()` (`MeterHub/module.php`), wiederverwendbar für jede künftige
Cloud-API mit historischen Werten:

1. **`AC_AddLoggedValues(int $ArchivInstanzID, int $VariableID, array $Datasets)`** —
   `$Datasets` = Liste von `['TimeStamp' => unixSekunden, 'Value' => …]`. Danach ist die
   Aggregation der Variable laut Doku ungültig, `AC_ReAggregateVariable()` nachschieben.
2. **Vor dem Schreiben auf Zeitstempel-Kollisionen prüfen** — `AC_AddLoggedValues()`s
   Verhalten bei einem bereits archivierten Zeitpunkt (überschreiben vs. duplizieren) ist
   nirgends dokumentiert; die Symcon-Community behilft sich durchgehend mit demselben
   Schutzmuster: vorab `AC_GetLoggedValues(int $ArchivInstanzID, int $VariableID, int
   $StartTime, int $EndTime, int $Limit)` (Limit `0` = kein Limit, hartes Maximum 10.000)
   abfragen, vorhandene `TimeStamp`s in ein Lookup-Set packen, nur neue Zeitpunkte
   eintragen. Bei Abrechnungsdaten (wie hier) ist das kein Stilthema — eine Dopplung würde
   den Verbrauch verfälschen.
3. **Kumulativ vs. Intervalldelta live gegenprüfen, nicht annehmen.** Undokumentierte
   API-Felder mit identischem Namen wie ein bereits bekannter Momentanwert-Endpunkt (hier:
   `values.energy` in `/readings` vs. `/last_reading`) MÜSSEN nicht dieselbe Semantik haben.
   Verifiziert über eine unabhängige Gegenprobe: die Differenz zweier benachbarter
   Lastgang-Werte reproduzierte exakt das im selben Datensatz mitgelieferte `power`-Feld —
   erst danach als kumulativ behandelt.

**Automatischer wiederkehrender Lauf (27.08.2026, noch am selben Tag überarbeitet):**
Dietmar wies darauf hin, dass die laufende Live-Abfrage (`power_total`/`energy_*` über
`FastTimer`/`SlowTimer`, siehe `ReadFast()`/`ReadSlow()`) zwar schon immer automatisch
lief — der Archiv-Nachtrag selbst aber ausschließlich über den Formular-Knopf erreichbar
war, nirgends von selbst. Fix: `MaybeAutoBackfillInexogy()`, aufgerufen am Ende von
`ReadSlow()`. Kein eigener Timer/Event — die ohnehin laufende `SlowTimer` (Default 60 s)
reicht, um ein „mindestens N Minuten seit dem letzten Lauf"-Intervall zu prüfen; ein
Attribut (`InexogyAutoBackfillLastRunTs`, Unix-Zeit) hält den letzten Lauf fest.

**Erste Fassung war „einmal täglich ab HH:MM" (`SelectTime`-Formularelement) — noch am
selben Tag auf Dietmars Rückfrage hin verworfen:** „Warum nicht alle 15 Min. die letzten
30 Tage holen, es passiert ja ohnehin nichts?" Die Antwort in zwei Teilen, beide wichtig
für künftige Änderungen an diesem Feature:

- **Die Taktfrage (15 Minuten) war richtig gedacht** — Inexogys `/readings` liefert
  ohnehin nur 15-Minuten-Werte (siehe `resolution=fifteen_minutes` oben), öfter fragen
  bringt keine frischeren Daten. `InexogyAutoBackfillIntervalMin` (Default 15, Minimum
  15) setzt das um.
- **Der Rückblick (30 Tage) bei JEDEM Takt wäre die eigentliche Verschwendung gewesen.**
  Bei 96 Läufen/Tag hätte ein 30-Tage-Fenster ~276.000 Zeilen täglich von Inexogy geholt
  und dreimal (je Zielvariable: `energy_import`/`energy_export`/`power_total`) aus dem
  lokalen Symcon-Archiv zum Dopplungsabgleich zurückgelesen — praktisch immer nur um
  „kenn ich schon" festzustellen, ohne die Daten dadurch frischer zu machen (frischer als
  der 15-Minuten-Takt der Quelle geht ohnehin nicht). Reales Risiko: unnötige Last auf
  Inexogys API (Rate-Limiting) und dem lokalen Archiv. `InexogyAutoBackfillDays` blieb
  deshalb klein (Default 1, Maximum 7) — genug, um eine kurze Störung bei Inexogy oder
  Symcon abzudecken, nicht um bei jedem Takt die halbe Historie erneut zu prüfen.

**Merksatz für ähnliche Fälle:** Takt-Frequenz und Rückblick-Fenster sind zwei
unabhängige Stellschrauben. Häufiger fragen hilft nur, wenn die Quelle auch tatsächlich
so oft neue Daten hat — ein größeres Fenster bei gleicher Frequenz bringt dagegen nur
mehr redundante Arbeit, keine frischeren Werte.

**Dritte Fassung, noch am selben Tag (Dietmars zweite Rückfrage): fester Rückblick durch
Nachsehen ersetzt.** „Wie wäre es nachzusehen, welche Daten schon da sind, und nur das
Notwendige zu holen?" — ein fester Tage-Wert (auch ein kleiner) fragt bei jedem Takt einen
Bereich ab, der zu über 99 % schon archiviert ist. `ComputeAutoBackfillRange()` ermittelt
stattdessen je Zielvariable den **tatsächlich neuesten archivierten Zeitstempel** und holt
nur, was seither fehlt:

- **`AC_GetLoggedValues($archiveID, $vid, 0, $now, 1)` kostet dafür nur EINEN Datensatz.**
  Laut SDK-Doku ist die Ausgabe absteigend sortiert (neuester zuerst) — `Limit=1` liefert
  also exakt den neuesten Eintrag, kein Scan der Historie nötig (gegen die offizielle Doku
  verifiziert, nicht angenommen).
- **Minimum über alle Zielvariablen** (`energy_import`/`energy_export`/`power_total`):
  hinkt eine hinterher, holt der Lauf alle drei gemeinsam nach — verhindert eine
  auseinanderlaufende Historie.
- **30 Minuten Sicherheitsabstand** (zwei Inexogy-Intervalle) vor dem ermittelten Stand,
  nicht mehr — fängt einen knapp verpassten/nachträglich korrigierten Wert ab, ohne bei
  jedem Takt unnötig viel erneut abzufragen. Der bestehende Dopplungsschutz in
  `DoBackfillInexogyArchive()` macht die Überlappung ohnehin gefahrlos.
- **`InexogyAutoBackfillDays` ist damit keine Fenstergröße mehr, sondern eine
  Obergrenze** — greift nur beim allerersten Lauf (noch nichts archiviert) oder nach
  einer größeren Lücke (z. B. mehrtägiger Ausfall). Deshalb wieder auf 3 Tage (Maximum 30)
  angehoben, ohne dass das die Kosten im Normalfall erhöht — die Obergrenze wird ja fast
  nie gezogen.
- **`DoBackfillInexogyArchive()` nimmt jetzt `(int $fromTs, int $toTs)` statt
  `int $days`** — eine Tage-Rundung hätte den ganzen Gewinn der Minuten-genauen
  Bereichsermittlung zunichtegemacht (ein 20-Minuten-Rückstand wäre auf einen vollen Tag
  aufgerundet worden). Blieb intern `private`, betrifft also nicht die veröffentlichte
  `MHUB_BackfillInexogyArchive($id)`-Signatur.

Bereichsermittlung bewusst von der eigentlichen Netzwerkabfrage getrennt
(`ComputeAutoBackfillRange()` vs. `DoAutoBackfillInexogyArchive()`/
`DoBackfillInexogyArchive()`) — lässt sich dadurch in `.tools/test-auto-backfill.php`
(Fälle 8a–8f) vollständig ohne echten API-Zugriff prüfen: Wasserstand normal/uneinheitlich/
nie archiviert/in der Zukunft, fehlendes Archiv-Modul, fehlende Zielvariablen.

**Wichtig für künftige Änderungen an `BackfillInexogyArchive()`:** Die Methode ist
öffentlich (`MHUB_BackfillInexogyArchive($id)`, vom Formular-Knopf einarmig aufgerufen) —
ein zusätzlicher Parameter für die Tage-Anzahl hätte laut „PREFIX_-Funktionen sind fixer
Arität" (siehe unten) den bestehenden Knopf gebrochen. Deshalb Kern-Logik in
`DoBackfillInexogyArchive(int $days): string` (private) ausgelagert; sowohl der
öffentliche Knopf-Handler als auch `MaybeAutoBackfillInexogy()` rufen diese mit ihrer
jeweils eigenen Tage-Zahl auf (`InexogyBackfillDays` vs. `InexogyAutoBackfillDays`,
Default 3 — täglicher Lauf, daher bewusst klein, im Unterschied zum manuellen
Einmal-Rückstand).

## `PowerInvert` galt bisher nur für die Leistung, nicht die Energiezähler (21.08.2026)

Live gefunden (Dietmars Inexogy-Instanz, gemeldet über das Dashboard-Team): `PowerInvert`
drehte nur das Vorzeichen von `power_total` (ein signierter Wert, +/− für Bezug/Einspeisung).
Die beiden Energiezähler (`energy_import`/`energy_export`, auch die Tarif-/Phasen-Varianten
wie `energy_import_t1`/`energy_export_l1`) sind aber getrennte, immer positive Zählerstände —
bei vertauschter Anschlussrichtung muss dort das ZIEL vertauscht werden (Bezug↔Abgabe), kein
Vorzeichen. `EnergyIdentForInvert()` (`MeterHub/module.php`) übersetzt das: bei aktivem
`PowerInvert` wird `energy_import` beim Schreiben auf `energy_export` umgeleitet und
umgekehrt — aber nur, wenn die Gegenrichtung bei diesem Treiber überhaupt existiert (z. B.
Phoenix EEM hat nur `energy_import`, keine Gegenrichtung; sonst würde der Wert ins Leere
geschrieben und die Variable einfriert). Betraf schon immer ALLE Treiber mit einem
`energy_import`/`energy_export`-Paar, nicht nur Inexogy — der Fehler lag im gemeinsamen
Setter (`SetVarEnergyWh()`/`SetVarEnergykWh()`), nicht in einem einzelnen Treiber. Verifiziert
in `.tools/test-powerinvert.php` (eigener, leichtgewichtiger IPSModule-Stub, kein
Objektbaum/Treiber nötig — nur Property/Attribut/Variablenwerte).

## Nützliches beim Testen am Live-IPS

- `php_eval` (MCP `ips-automation`) **gibt Rückgabewerte nicht aus**. Zuverlässigster Weg zur
  Ausgabe: `trigger_error('TEXT', E_USER_WARNING)` — erscheint sofort im Systemprotokoll und
  ist über `system_log` mit den Standardtypen lesbar.
- Ergebnisse in eine IPS-Variable zu schreiben und deren ID über eine bekannte Variable
  durchzureichen funktioniert ebenfalls, ist aber fragil: Laufende Modul-Timer überschreiben
  solche Marker-Variablen.
- Angelegte Hilfsvariablen hinterher wieder **löschen**.
- Die Weboberfläche eines Geräts sagt nichts über Modbus: Der PAC2200 antwortet auf Port 80,
  während Port 502 separat freigeschaltet sein kann. Beim Shelly muss Modbus TCP erst per
  `Modbus.SetConfig` aktiviert werden.


## Verbund-Manifest SUITE.md — Bezugsquelle (19.08.2026)

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der
Branch `ems-integration` der aktuellste Stand, nicht `main`). In diesem Repo
liegt eine automatisch synchronisierte READ-ONLY-Kopie als `SUITE.md` im
Repo-Root — dort lokal grep'en/lesen. NIEMALS die Kopie hier editieren:
Änderungen gehören ins EMS-Repo; der Sync (GitHub Action `sync-suite` im
EMS-Repo) überschreibt lokale Änderungen kommentarlos.

Fallback, falls die Kopie (noch) fehlt oder veraltet wirkt:
https://raw.githubusercontent.com/DG65/NRGEMS/ems-integration/SUITE.md
