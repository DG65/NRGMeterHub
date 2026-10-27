# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](https://github.com/DG65/NRGMeterHub/blob/beta/CHANGELOG-Archiv.md)

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

## 0.31.1-beta.1 (2026-09-19)

- **MeterHubDiscovery: Hinweis „nicht automatisch gefunden" vervollständigt.** Er nannte nur
  Socomec und MBS. Jetzt zusätzlich: Zähler an einem ModBus Gateway wie dem RS485-Port der
  Symbox (darunter die neuen reinen RS485-Geräte Eastron SDM120/220/230 — die Suche scannt nur
  TCP), der Phoenix EEM-XM, Inexogy (Cloud) und die schreibenden blue'Log-Typen RPC/Power
  Control; dazu der Weg: Instanz von Hand anlegen, bei der Symbox mit dem Verbindungsweg
  „Symbox-Gateway". Steht an beiden Stellen im Formular und in der README. Fund bei der Frage,
  ob die Suche alle Zählertypen enthält: sie findet 12 der 28 Typen automatisch (9 per Signatur,
  3 über den SCADA-Scan), dazu die klassischen Janitza-Modelle und den SDM630 stellvertretend.
  Ob sich der Phoenix EEM-XM per Signatur erkennen ließe, ist offen (Herstellerunterlagen
  fehlen).

## 0.31.0-beta.1 (2026-09-19)

- **Neu: drei einphasige Eastron-Zähler — SDM120, SDM220, SDM230** (Wunsch des Forum-Testers,
  der sie an seiner Symbox betreibt). Neuer Treiber `MHUB_EastronSdmSinglePhaseDriver`:
  Wirkleistung (Register 12), Spannung (0), Strom (6), Frequenz (70), Energie Bezug (72) und
  Abgabe (74) in kWh, optional Scheinleistung (18), Blindleistung (24) und Leistungsfaktor (30).
  FC 0x04, Float32 Big-Endian, Modbus-Adresse ab Werk 1. Die drei Geräte teilen dieselbe
  Registerkarte.
- **Registerkarte gegen drei unabhängige Quellen gegengelesen:** nmakel/sdm_modbus (SDM120,
  SDM230), evcc (SDM120, SDM220/230) und volkszaehler/mbmd (SDM120, SDM220, SDM230) stimmen in
  Funktionscode, Adressen, Datentyp und Wortreihenfolge überein und decken sich mit dem
  vorhandenen SDM630-Treiber. **Nicht an echter Hardware bestätigt** — im Dropdown
  „experimentell", der Tester prüft an seinen Geräten.
- **Bewusst ein eigener Treiber statt des dreiphasigen:** der SDM630-Treiber liest zwei große
  Blöcke (0–40, 42–63) und nimmt die Summenleistung aus Register 52 — die einphasigen Geräte
  haben keine Summenregister, die Leistung steht auf 12, und die Register dazwischen sind nicht
  belegt (ein Blocklesen riskiert „Illegal Data Address" für die ganze Anfrage, dieselbe Lehre
  wie beim PAC2200). Jede Größe wird deshalb einzeln gelesen (je 2 Register), wie in allen drei
  Quellen.
- Reine RS485-Geräte: die Netzwerksuche (MeterHubDiscovery) scannt sie bewusst nicht; Anlage von
  Hand, für den Anschluss an die Symbox über den Verbindungsweg „Symbox-Gateway".
- Prüfstand `test-eastron-single-phase.php` (neu): der Treiber läuft über den echten
  Gateway-Client gegen eine nachgebaute Registertabelle — Anfragen (FC 4, je 2 Register, keine
  Blocklesung), Dekodierung, negative Leistung bei Einspeisung, Ausfall einzelner Register.
  Doku: README-Tabelle, Doku-Panel, News-Eintrag (`NEWS_VERSION` 0.31.0).

## 0.30.1-beta.1 (2026-09-19)

- **Verständlichere Texte im Symbox-Gateway-Weg (Rückmeldung des Forum-Testers zum Handling,
  „Getestet, läuft"):** Die Statuszeile ganz oben nannte im Gateway-Modus „IP-Adresse bzw.
  Inexogy-Anmeldung" und „Zähler nicht erreichbar" — sie hängt jetzt vom Verbindungsweg ab. Im
  Gateway-Modus: Status 104 „Bitte die Brücke zum ModBus Gateway eintragen …", Status 201
  „keine Antwort über die Brücke: Brücke und ModBus Gateway prüfen (Unit-ID = DeviceID)"; bei
  „Direkt" und Cloud-Zählern unverändert. Die Felder heißen jetzt „ModBus Gateway zum Gerät" und
  „NRG-Stack Brücke zum ModBus Gateway" (vorher „Einfachster Weg: natives ModBus Gateway dieses
  Geräts wählen …" und „Brücke (MeterHub Brücke zum ModBus-Gateway)", das erste wurde in der
  Anzeige abgeschnitten); der Knopf heißt „Brücke anlegen und verbinden" ohne das „…" davor.
- Prüfstand `test-virtual.php` Block 47 um die Statustexte je Verbindungsweg erweitert.

## 0.30.0-beta.1 (2026-09-19)

- **Neu: Modul „MeterHub Brücke" (`MeterHubBridge`, Prefix `MHUBB`) — der Symbox-Gateway-Weg
  läuft jetzt darüber; `parentRequirements`/`implemented` sind in `MeterHub/module.json`
  zurückgenommen.** Auslöser: Beim Schwestermodul InverterHub zeigte jede bestehende
  Direkt-Instanz den orangen Balken „Die Instanz benötigt eine übergeordnete Instanz, hat aber
  keine" (Tester im Forum), bei MeterHub derselbe Mechanismus (0.29.10–0.29.14). Die
  Anforderung trägt jetzt allein die Brücke (Kind des nativen ModBus Gateways); MeterHub wählt
  sie im neuen Feld `BridgeInstanceID` und ruft `MHUBB_Forward()`. GUID und Prefix von
  MeterHub bleiben unverändert — Konsumenten von `MHUB_GetFunctions` (EMS, Dashboard, OCPPHub,
  HeishaMon, RLTHub, MeterHubVirtual) merken nichts, bestehende Instanzen behalten ihre Historie.
- **Vertrag der Brücke (in allen drei Hubs identisch, jeweils eigene Brücke in der eigenen
  Bibliothek — Dietmars Entscheidung, damit jedes Modul für sich eigenständig bleibt):**
  `Forward(string $json): string` antwortet immer JSON `{"ok":true,"data":"<base64>"}` oder
  `{"ok":false,"error":"not_connected"|"parent_inactive"|"no_response"}` — rohe Registerbytes
  sind meist kein gültiges UTF-8, deshalb base64 über die Instanzgrenze. `GetState(): string`
  liefert `{connected, parentActive, parentStatus, unitId}` (Unit-ID aus der `DeviceID` des
  Gateways). Die Brücke kennt keine Function Codes und reicht Lesen wie Schreiben durch. Eine
  Brücke bedient genau eine Unit-ID.
- **Halbautomatisch:** Neben der Brückenauswahl gibt es „Natives ModBus Gateway wählen … und
  Brücke anlegen und verbinden" — der Knopf legt die Brücke an, hängt sie ans Gateway, benennt
  sie und trägt sie ein (eine vorhandene Brücke am selben Gateway wird wiederverwendet).
  Anlegen nur per Klick, nie in `ApplyChanges()`/`GetConfigurationForm()`.
- Verbindungstest und Statustexte nennen jetzt den Grund: keine Brücke gewählt, Brücke ohne
  Gateway, Gateway nicht aktiv, Gateway antwortet nicht (Unit-ID prüfen). Bereitschaft im
  Gateway-Modus = Brücke gewählt (`BridgeInstanceID > 0`).
- **Wer den Gateway-Weg mit 0.29.10–0.29.14 eingerichtet hatte:** Instanz und Messhistorie
  bleiben; Gateway wählen, „Brücke anlegen und verbinden" klicken, übernehmen. Der direkte
  Anschluss der Instanz ans Gateway entfällt ohne diese Einträge.
- Prüfstand `test-bridge.php` (neu): alle Fehlerarten der Brücke, Binärwerte 0xFFFF/0x8001
  Bit für Bit über die Brücke, `BridgeCall()` bis zu den dekodierten Registern,
  `CreateBridge()` (anlegen, verbinden, wiederverwenden), `module.json` beider Module.
  Nicht nachgebildet: das Kernel-Verhalten beim Funktionsaufruf zwischen Instanzen und die
  Konsole — an echter Hardware unbestätigt. `test-virtual.php` Block 47: Gateway-Auswahl,
  Knopf und Brückenauswahl sind nur im Verbindungsweg „Symbox-Gateway" sichtbar (nicht bei
  „Direkt" und nicht bei Cloud-Zählern).
- Hilfe und Doku: neuer Absatz im Doku-Panel des Formulars, Abschnitt „MeterHubBridge" in der
  README, News-Panel-Eintrag (`NEWS_VERSION` 0.30.0), Formularhinweise mit den drei Schritten.

## 0.29.14-beta.1 (2026-09-19)

- **Fix: „Ertrag gesamt (hochgerechnet)" des blue'Log-Datenloggers (Adresse 97) hatte keine
  Plausibilitätssperre.** Das Gegenstück zu MeterHubVirtual 0.29.5: `CalcEnergyStep()` prüfte
  nur `is_finite()` — ein einzelner riesiger, aber endlicher Fehl-Lesewert (verunglückte
  Modbus-Dekodierung bei einem Verbindungsaussetzer) lief ungebremst in den kumulativen
  Zählerstand und blieb dort dauerhaft stehen. Zwei Sperren: (1) derselbe generische
  Absolutdeckel von 1 GW wie bei den virtuellen Zählern; (2) ein Sprungvergleich gegen den
  letzten akzeptierten Wert derselben lückenlosen Messreihe (mehr als das 20-Fache, bei
  Ausgangswert über 1 W). Der Median-Vergleich der virtuellen Zähler passt hier nicht — ein
  Einzelzähler hat im selben Durchlauf keine Vergleichsgeräte. Ein Sprung, der drei Takte in
  Folge ungefähr gleich hoch bleibt, gilt als echter Stufenwechsel und wird übernommen; eine
  Fehlablehnung kostet damit nur die Energie von höchstens zwei Takten, eine durchgelassene
  Fehllesung wäre dauerhaft. Faktor 20 statt 200 der virtuellen Zähler: bei 3,6 MW Grundlast
  hätte 200 noch Werte bis 720 MW durchgelassen (vom Prüfstand aufgedeckt).
- Bekannte Grenze: Nach einer Lücke über 300 s gibt es keinen Vergleichswert mehr, der neue
  Ausgangspunkt wird nur gegen den Absolutdeckel geprüft. Bereits im Archiv stehende
  Fehl-Sprünge behebt das nicht (separate Bereinigung, nicht Teil dieses Fixes).
- Prüfstand `test-virtual.php` Block 46: Deckel, Einzelausreißer, Bestätigung eines echten
  Stufenwechsels, wechselnder Unsinn, kleiner Ausgangswert (Balkonanlage/Sonnenaufgang),
  normaler Anstieg, Lücke sowie der Solarpark-Fund (6-GW-Ausreißer in einer Stunde 3,6 MW)
  und ein 500-MW-Ausreißer unter dem Deckel.

## 0.29.13-beta.1 (2026-09-19)

- **Fix: Symbox-Gateway-Instanz startete nie — `ApplyChanges()` verlangte weiterhin einen Host.**
  Im Gateway-Modus ist das Host-Feld ausgeblendet und damit leer; die Bereitschaftsprüfung
  (`Host !== ''`) setzte deshalb Status 104 („Bitte Verbindung vervollständigen (IP-Adresse …)")
  und stellte beide Timer auf 0 — die Instanz las nie einen Wert, egal ob ein Gateway
  verbunden war. Fund über den Forum-Beta-Test (Kopfzeile mit IP-Aufforderung trotz
  Symbox-Gateway). Im Gateway-Modus gilt die Instanz jetzt als bereit; ohne verbundenes
  Gateway endet der Lesezyklus in Status 201 statt in einem stillen Stillstand. Der Sendeweg
  prüft dafür vorab die Verbindung (`ConnectionID`) und liefert leer zurück, statt bei jedem
  Takt Symcons Warnung „Keine übergeordnete Instanz ist konfiguriert …" zu erzeugen. Status-
  text 201 nennt jetzt auch das fehlende Gateway.

## 0.29.12-beta.1 (2026-09-19)

- **Fix: Symbox-Gateway-Verbindung — die Reparatur aus 0.29.10 war nur die halbe
  Kompatibilitätsangabe.** Der Beta-Tester sah nach dem Update auf 0.29.11 im Fenster
  „Gateway ändern" weiterhin keine Auswahl, es blitzte nur kurz auf. Das native ModBus
  Gateway hat selbst `ChildRequirements {77B31ABB-…}` (live per `IPS_GetModule()` gelesen) und
  akzeptiert nur Kinder, die diese Schnittstelle in `implemented` führen. `module.json`
  ergänzt: `implemented ["{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}"]`. Gegengelesen an Symcons
  Referenzmodul (symcon/SymconBC, EM24-DIN) und am Gateway-Modul von WPModbusHub, das an
  echter Hardware läuft — beide führen `parentRequirements` UND `implemented`.
- Noch offen und bewusst unverifiziert: ob die Angaben am Hauptmodul den Anlege-Ablauf oder
  bestehende Direkt-Instanzen in der Konsole beeinflussen. WPModbusHub hat den Gateway-Weg
  deshalb als eigenes Schwestermodul gebaut; die Entscheidung dazu steht noch aus.
