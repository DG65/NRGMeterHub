# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

An diesen Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet:

- **MeterHub** (dieses Repo): Energiezähler per Modbus TCP — https://github.com/DG65/MeterHub
- **InverterHub**: Wechselrichter per Modbus TCP — https://github.com/DG65/InverterHub
  (lokale Arbeitskopie: `../InverterHub`)
- **Prognose** (Suite EnergiePrognose): PV- und Verbrauchsprognose —
  https://github.com/DG65/Prognose (lokale Arbeitskopie: `../Prognose`)
- **ChargerHub**: Wallboxen per Modbus TCP — https://github.com/DG65/ChargerHub
- **MigrationsHub**: Übernahme von Bestandsgeräten samt Archivwerten —
  https://github.com/DG65/MigrationsHub
- **EMS**: Energiemanagement, Steuerungshoheit über den Verbund

**MeterHub koppelt direkt nur an InverterHub.** Zum Prognose-Repo besteht derzeit keine
Verbindung; es ist hier nur zur Orientierung genannt, weil an allen dreien parallel gearbeitet
wird. Die Prognose ist ihrerseits an den `InverterHubMonitor` gekoppelt (Vertrag dort:
`PVF_Get*`). Sollte MeterHub jemals Prognosewerte einbeziehen, ist das vorher mit der
Prognose-Sitzung abzustimmen — nichts eigenmächtig in fremden Repos anlegen.

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
Kopplung an InverterHub (Kachel und Sankey). Keine unaufgeforderten Rundnachrichten an neue
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

Drei Invarianten der Kopplung (identisch in der CLAUDE.md von InverterHub):

1. **Verbraucher-Arten nur in `CONSUMER_TYPES` pflegen.** Die Auswahlliste der Spalte „Art"
   erzeugt `injectConsumerTypeOptions()` in `GetConfigurationForm` zur Laufzeit und
   überschreibt dabei die statischen `options` der `form.json`. Wer eine Art nur dort
   einträgt, erzeugt ein stilles Auseinanderlaufen.
2. **Vorzeichen des Netz-Kernwerts wird negiert.** MeterHub zählt `+` = Bezug, die Kachel
   `+` = Einspeisung.
3. **`form.json` nicht maschinell umformatieren** (siehe Commit-Regeln unten).

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
`MeterHub/module.php` (12 Treiberklassen) prüfen, in welcher Klasse die Fundstelle liegt.
`Edit` mit eindeutigem Kontext statt `replace_all`, danach dieses Skript laufen lassen.

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
| `latency` | `'realtime'` (lokal, in Sekunden regelbar) oder `'delayed'` (Cloud-API mit Latenz) — Regelfähigkeit |
| `authority` | `'billing'` (geeichter, abrechnungsverbindlicher Zähler am Netzübergabepunkt) oder `'auxiliary'` (Hilfszähler) |
| `pollInterval` | reale Aktualisierungsrate in Sekunden |
| `sourceCount` | nur MHUBV: Zahl der beteiligten Quellen eines Rest-/Summenknotens (Güte) |

**`latency` und `authority` sind orthogonal, keine Gegenteile** — alle vier Kombinationen
existieren real: Inexogy (billing+delayed), lokaler Shelly am NAP (auxiliary+realtime), ein
lokal ausgelesenes iMSys (billing+realtime), ein virtueller Rest-Knoten (auxiliary+realtime).
Ein einzelnes „billingGrade"-Flag könnte das nicht trennen; deshalb zwei Felder. Konservative
Defaults bei fehlenden Feldern (alter Anbieter): `latency→realtime`, `authority→auxiliary`,
`energyKind→counter`. Konsumentenbedingung für „der abrechnungsgenaue Netzzähler": `function ==
'grid' && authority == 'billing'`, mit Rückfall, wenn keiner vorhanden.

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
