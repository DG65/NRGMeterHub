# Changelog

## 0.28.7-beta.1 (2026-09-13)

- **`MHUBV_ScanMeters` hat wieder Parametertypen** (`?int`, `?string`, `?bool`, `?bool`). In
  0.27.9 waren sie beim Aufteilen in `ScanMeters`/`ScanMetersEx` versehentlich weggefallen.
  Seitdem meldete das Systemprotokoll bei jedem Laden des Moduls viermal „Parameter … hat
  keinen Datentyp“. Die vier Parameter bleiben unverändert (feste Arität).

## 0.28.6-beta.1 (2026-09-13)

- **Doppelte Anbindung: Eine inaktive Instanz gilt nicht als frisch** (Hinweis EMS). Hat die
  Anbindung selbst oder ihre übergeordnete Instanz einen Status ≠ 102, zählt sie für die Wahl
  der Zählquelle als veraltet, auch wenn ihr Vertrag noch Werte liefert. Übergeordnet heißt das
  Gateway über die Symcon-Verbindung, bei OCPPHub-Ladepunkten der Splitter aus `SplitterID`.
  Anlass: Dietmar hat den OCPPHub-Splitter ausgeschaltet, die Ladepunkte liefern seither keine
  Werte mehr.

## 0.28.5-beta.1 (2026-09-13)

- **Doppelte Anbindung: Haltezeit für die Zählquelle** (EMS-Anmerkung). Ohne sie pendelte die
  Gruppe bei einer wackligen Verbindung zwischen den beiden Anbindungen, und jeder Wechsel
  veränderte den Ausgleichswert. Jetzt merkt sich MeterHubVirtual je unmarkiertem Paar die
  zählende Anbindung (Attribut `DupChoice`, `ChooseCounting`). Sie bleibt, solange sie frisch
  ist. Gewechselt wird nur, wenn sie 15 min ohne Kontakt ist, die andere frisch ist und der
  letzte Wechsel mindestens 30 min zurückliegt. Die erste Wahl ist wie in 0.28.4 die frische,
  sonst die erste Anbindung.
- Prüfstand `test-virtual.php` Block 41.

## 0.28.4-beta.1 (2026-09-13)

- **Doppelte Anbindung: Bis zur Markierung zählt die Anbindung mit aktuellen Messwerten**
  (Hinweis EMS). Bisher zählte immer die erste eines erkannten Paars. Bei Dietmars WB2 war das
  der OCPPHub-Ladepunkt, der seit dem 10.09. keine Verbindung hat, während nur ChargerHub
  misst. Eine Ladung hätte in Summe und Energie gefehlt. „Aktuell“ heißt: Das Quellmodul hat
  das Gerät in den letzten 15 min erreicht (Vertragsfeld `lastSeenAt`, ersatzweise die jüngste
  Aktualisierung der Leistungs-/Zählervariablen). Sind beide gleich aktuell, zählt wie bisher die
  erste. Wechselt die zählende Anbindung, läuft die Energie-Summe dank 0.28.3 nahtlos weiter,
  ohne Doppelzählung. Der Hinweis in „Prüfung & Vorschau“ nennt die tatsächlich zählende
  Anbindung.
- Prüfstand Block 39 um Frische-Fälle ergänzt.

## 0.28.3-beta.1 (2026-09-13)

- **MeterHubVirtual: Energie-Summen laufen nahtlos weiter** (Dietmars Wunsch). Ändert sich die
  Zusammensetzung (Mitglied hinzu oder weg, Dublette umgestellt, „aktiv“ geändert), springt die
  Rohsumme von Bezug bzw. Einspeisung um den Unterschied der Zählerstände. Anlass: Die
  „Ladestation“ fiel beim Wechsel von ChargerHub auf OCPPHub um 41,7 kWh zurück. Archiv und
  Tagesbalken werten so einen Sprung als Riesenverbrauch bzw. Reset. Jetzt merkt sich
  MeterHubVirtual je Energie-Ausgabe die Zusammensetzung. Ändert sie sich, wird der Unterschied
  zum letzten Ausgabewert als Ausgleich festgehalten (`ContinuityStep`, Attribut
  `EnergyContinuity`). Die Reihenfolge der Mitglieder zählt nicht, die Leistung bleibt
  unberührt. Beim ersten Start nach dem Update wird nur gemerkt, rückwirkend ändert sich nichts.
  „Prüfung & Vorschau“ nennt einen bestehenden Ausgleich, damit klar ist, warum der Zählerstand
  von der Summe der Mitglieder abweicht.
- Prüfstand Block 35h auf die neue Bedeutung umgestellt: Die Einspeisung läuft bei einer neuen
  Rolle erst nahtlos weiter, mit leerem Ausgleich ergibt sich die reine Summe.
- Prüfstand `test-virtual.php` Block 40.

## 0.28.2-beta.1 (2026-09-13)

- **Doppelte Anbindung: Entschieden wird am Quellmodul** (Dietmars Entscheidung, nur ein Ort).
  Markiert der Nutzer in ChargerHub oder OCPPHub eine Anbindung als Dublette (Vertragsfeld
  `duplicateOf`), zählt sie in MeterHubVirtual nicht mit. Das gilt überall, also auch für Dashboard
  und EMS. Bei noch nicht markierten Paaren weist „Prüfung & Vorschau“ darauf hin, und bis dahin
  zählt nur die erste Anbindung. MeterHubVirtual schaltet selbst keine Instanz mehr ab, der
  Aufruf von `{Präfix}_SetActive` aus 0.28.0 ist entfernt. Die Spalte „aktiv“ nimmt ein
  Mitglied nur aus dieser Summe.
- Prüfstand Block 39 um markierte Dubletten ergänzt.

## 0.28.1-beta.1 (2026-09-13)

- Doppelte Anbindung: Gerätemerkmale auch über `{Präfix}_GetContractEntry` (so kündigt OCPPHub sie
  für seine Ladepunkte an, Vertrag 1.4). ChargerHub 0.9.62 liefert sie bereits in
  `CHUB_GetFunctions` 1.4 (`deviceSerial`, `deviceHost`) und bietet `CHUB_SetActive` an. Beides
  passt ohne Anpassung zu MeterHubVirtual 0.28.0.

## 0.28.0-beta.1 (2026-09-13)

- **MeterHubVirtual: doppelte Anbindung desselben Geräts** (Dietmars Regel). Eine Wallbox kann
  zugleich über ChargerHub und OCPPHub eingebunden sein. In einer Summe zählte sie dann doppelt,
  und zwei Module steuerten dieselbe Wallbox. Jetzt:
  - **Erkennen:** Gleiche Seriennummer oder IP-Adresse belegen es, fast gleiche Zählerstände
    (≤ 1 %, ≥ 100) gelten als Hinweis. Der Zählerstand-Vergleich gilt nur zwischen verschiedenen
    Modulen, damit viele gleiche Wechselrichter keine Fehlalarme auslösen. Verschiedene
    Seriennummern schließen ein Paar sicher aus. Die Merkmale kommen aus dem Vertrag des Moduls
    (`deviceSerial`/`deviceIP`), ersatzweise aus der Variable `dev_serial` bzw. der Eigenschaft
    `Host`.
  - **Bis zur Wahl** zählt nur die erste Anbindung, die Summe ist nie doppelt. „Prüfung &
    Vorschau“ nennt beide und den Grund.
  - **Neue Spalte „aktiv“** je Mitglied. Abgewählt zählt ein Mitglied nicht mit und schaltet
    nicht mit. Ist es die Doppel-Anbindung eines Paars, wird ihre Instanz über ihr Modul ganz
    abgeschaltet (`{Präfix}_SetActive`), angewählt wieder eingeschaltet. Bietet ein Modul das
    nicht an, weist die Prüfung darauf hin, die Instanz von Hand zu deaktivieren.
  - Ausgesetzte Mitglieder erscheinen nicht mehr in `members` des Vertrags.
- Prüfstand `test-virtual.php` Block 39.

## 0.27.10-beta.1 (2026-09-13)

- **Keine Warnungsflut beim Update (Store-Checkliste 9c).** Während Symcon ein Modul neu lädt,
  liefen Lesezyklen und Aufrufe anderer Module (z. B. das Dashboard über `GetFunctions`) noch in
  die Instanz. Jeder Zugriff meldete „InstanceInterface is not available“, Dutzende Einträge im
  Meldungsprotokoll je Update (live 13.09.2026). Die Einstiegspunkte prüfen jetzt zuerst still,
  ob die Instanz bereit ist:
  - MeterHub: `ReadFast`, `ReadSlow`, `GetFunctions`, `GetDiagnostics`
  - MeterHubVirtual: Neuberechnung, `GetFunctions`, Objektbaum-Meldungen

  Solange nicht, liefern `GetFunctions`/`GetDiagnostics` eine leere Antwort mit dem neuen, rein
  additiven Feld `ready: false`.

## 0.27.9-beta.1 (2026-09-13)

- **Keine Migration für Skripte:** `MHUBV_ScanMeters` hat wieder genau die 4 Parameter von beta
  (root, filter, needEnergy, onlyActive). Symcon erzeugt die Modulfunktionen mit fester
  Parameterzahl, ein Skript mit 4 Argumenten wäre nach der Übernahme gescheitert (Fund
  Migrationsvergleich, SUITE.md 9e). Die beiden neueren Suchfilter („schon anderswo genutzt“,
  „Funktion X“) stecken jetzt in der neuen Funktion `MHUBV_ScanMetersEx` mit 6 Parametern; der
  Formular-Button nutzt sie.

## 0.27.8-beta.1 (2026-09-13)

Durchgang Store-Review-Checkliste (SUITE.md), MeterHub, MeterHubVirtual und MeterHubDiscovery:

- **Punkt 5, Profile:** Variablenprofile werden nicht mehr bei jedem „Übernehmen“ erzwungen.
  Gesetzt wird nur, wenn noch keins gesetzt ist oder das vorhandene eines der eigenen Profile ist
  (NRG.*, MHB.*, MHBV.*). Der Wechsel kWh ↔ Wh wirkt weiter, ein vom Nutzer gewähltes fremdes
  Profil bleibt unangetastet. Betrifft die Zählervariablen von MeterHub sowie die Ausgaben,
  „Gruppe schalten“ und „Gruppenstatus“ von MeterHubVirtual.
- **Punkt 9c:** `ReadPropertyString`/`ReadAttributeString` werden vor `json_decode()` in einen
  String umgewandelt. Der Neustart-Zweig in `MessageSink` von MeterHubVirtual prüft zuerst, ob
  die Instanz noch existiert.
- **Punkt 12:** Autor- und Anlagenbezüge aus sichtbaren Hinweistexten entfernt („Dietmars
  Fund“ → „Praxistest“, „an Dietmars Solarpark“ → „an einer realen Solarpark-Anlage“).
- Geprüft und in Ordnung: `vendor` leer (Mehrhersteller), library.json-Felder, keine
  `$_IPS['TARGET']` in `onClick`, keine ungültigen Darstellungs-Optionen, Datumsformat und
  Umlaute (9b, 0.27.6), Statuscodes (9d: MeterHub 104 inaktiv/201 nur echter
  Verbindungsfehler, Discovery 102/104).

## 0.27.7-beta.1 (2026-09-13)

- **Verdichtung neu bilden ohne Fehler.** Symcon erlaubt nur eine Neuberechnung der
  Archiv-Verdichtung zur selben Zeit. MeterHub rief sie nach dem Inexogy-Nachtrag für jede
  ergänzte Reihe direkt hintereinander auf. Ab der zweiten scheiterte es mit „Eine andere
  Aggregation wird aktuell durchgeführt“ (Fehler im Meldungsprotokoll, live 13.09.2026), und die
  Tages- und Monatswerte dieser Reihen blieben veraltet. Jetzt kommt eine abgelehnte
  Neuberechnung in eine Warteschlange, die der Lesezyklus abarbeitet, eine je Durchlauf. Das gilt
  für alle Stellen: Inexogy-Nachtrag und -Bereinigung, Energie-Archiv-Reparatur und
  Richtungskorrektur.
- Prüfstand `test-powerinvert.php` Block 9.

## 0.27.6-beta.1 (2026-09-13)

- Verbund-Regel 9b (SUITE.md): Nutzersichtbare Datumsangaben durchgehend im Format
  TT.MM.JJJJ bzw. TT.MM.JJJJ HH:MM. Betroffen waren die Meldungen des Inexogy-Nachtrags
  (bisher JJJJ-MM-TT) und der Archiv-Prüfungen (bisher TT.MM. ohne Jahr). Die Sicherungs-
  Dateinamen bleiben maschinenlesbar. Ersatzschreibweisen für Umlaute gab es in sichtbaren
  Texten keine; der Vorlagen-Platzhalter `{zaehler}` bleibt als Kennung ASCII.

## 0.27.5-beta.1 (2026-09-12)

- Richtungsprüfung, PV-Vergleich: Die Begründung nennt die Quelle nicht mehr doppelt
  („PV-Erzeugung (PV-Erzeugung (20 Zähler))“) und zeigt wie die anderen Texte Übereinstimmung
  und Anzahl der Werte. Live bestätigt mit 0.27.4: PAC2200 „passt“ gegen die GoodWe-Netzmessung,
  Inexogy als Cloud-Zähler ausgenommen, beide Solarpark-NAPs „passt“ gegen die PV-Summe aus
  20 Zählern (unabhängig, Übereinstimmung 100 %).

## 0.27.4-beta.1 (2026-09-12)

- Richtungsprüfung sofort aktuell: „Übernehmen“ und das Neuladen des Moduls verwerfen den
  30-Minuten-Zwischenspeicher, und ein Zwischenspeicher mit anderer Vertragsversion gilt als
  veraltet. Live gesehen: Nach dem Update auf 0.27.3 lieferten beide Anlagen noch das Ergebnis
  des alten Codes (Vertrag 1.0). Nach dem Umschalten von „Bezug/Einspeisung vertauscht“ hätte die
  alte Bewertung bis zu 30 min stehen bleiben können.

## 0.27.3-beta.1 (2026-09-12)

- Diagnose-Vertrag: klargestellt, dass `correlation` immer normiert ist (+1 = Richtung passt,
  −1 = verkehrt; bei `opposite`/`pv` die umgekehrte Rohkorrelation) und alle Schwellen dafür
  gelten (Rückfrage Dashboard). Die PV-Begründung bei „auffällig/kritisch“ nennt jetzt ebenfalls
  die normierte „Übereinstimmung“ statt der Rohkorrelation.

## 0.27.2-beta.1 (2026-09-12)

- **Richtungsprüfung: unabhängige Quellen zuerst** (Einwand Dashboard). Im Solarpark prüften
  sich die beiden NAPs nur gegenseitig. Gerade die heutige Lage, beide gleichzeitig mit
  falschem PowerInvert, hätte trotzdem „passt“ ergeben. Die neue Reihenfolge:
  1. InverterHub-Netzmessung
  2. PV-Erzeugung als Summe der Beträge aller PV-Zuordnungen von InverterHub, MeterHub und
     MeterHubVirtual. Durch den Betrag ist egal, wie herum ein PV-Zähler eingestellt ist.
     Stark gegenläufig (≤ −0,8) gilt als kritisch, schwächer nur als auffällig.
  3. andere MeterHub-Netzzähler nur als Notlösung, mit Hinweis im Begründungstext
- Diagnose-Vertrag **1.1**: `independent` und `referenceIDs` je Eintrag. Die Bezeichnung trägt den
  Instanznamen („Richtung NAP Albersboesch“) statt des Funktionsnamens.

## 0.27.1-beta.1 (2026-09-12)

Erster Live-Probelauf von „Richtung im Archiv prüfen" an Dietmars Anlage und im Solarpark:

- Solarpark (beide Netzanschlüsse) sauber: Energie durchgehend, die Leistung passt in rund 5 750
  Viertelstunden. Richtungsprüfung gegenseitig „passt“ (100 %).
- Haus-PAC2200: Der Probelauf fand den Abschnitt 26.07. 14:45 – 27.07. 14:15, in dem die Leistung
  gegenläufig zur Energie steht. Das Dashboard hatte ihn gegen die GoodWe-Netzmessung gedreht,
  die dort selbst verkehrt herum war. Zwei unabhängige Zählerstände (PAC, Inexogy) bestätigen den
  Befund. Der Abschnitt zerfiel aber in zwei, weil in der Nacht zu wenig Leistung floss. Jetzt
  werden unklare Strecken bis 12 h zwischen zwei gegenläufigen Abschnitten überbrückt (bisher
  2 h). Eine passende Viertelstunde dazwischen trennt weiterhin immer.
- Cloud-Zähler (Inexogy): kein Leistungsabgleich mehr (vorher 14 Fehlalarme aus je 2–5 Werten,
  Zeitversatz zwischen Leistung und Zählerstand) und keine Richtungsprüfung.

## 0.27.0-beta.1 (2026-09-12)

Anlass: An Dietmars PAC2200 und an beiden Solarpark-Netzanschlüssen stand „Bezug/Einspeisung
vertauscht" zeitweise falsch. Seit 0.22.7 tauscht der Schalter die Energie-Ziele: Jedes
Umschalten ließ Bezug und Einspeisung aufeinander springen, und die gespeicherte Leistung behielt
vor dem Umschalten ihr altes Vorzeichen. Korrigiert werden musste das per Einzelskript. Jetzt
kann es jeder Nutzer selbst.

- **↔️ Umschalten ohne Sprung.** Ändert sich PowerInvert, tauschen die Energie-Variablen ihre
  Rolle (Ident/Name): `energy_import*` ↔ `energy_export*` und die Sammel-Variablen
  `fn_*_import` ↔ `fn_*_export`. Jedes Zählerregister landet weiter in derselben Variable, es
  gibt keinen Sprung und keinen Fehlalarm im Zählerschutz. Die Bezeichnung passt zur neuen
  Richtung, Zurückschalten macht es rückgängig. Beim ersten Start mit 0.27.0 wird die bestehende
  Anordnung nur übernommen. Das Meldungsprotokoll nennt die getauschten Variablen, damit Skripte
  mit festen IDs geprüft werden können.
- **🛠️ Neues Panel „Richtung im Archiv prüfen / korrigieren"** für frühere Umschaltungen, mit
  Probelauf, JSON-Sicherung vorab und tageweiser Verarbeitung (speicherfest):
  - Energie: Jede Zählervariable wird wieder eine durchgehende Reihe. Ein vertauschter
    Abschnitt beginnt, wenn ein Stand auf den des anderen Zählers springt. Ein Einheitenwechsel
    (Wh→kWh) oder Zählertausch ist kein solcher Sprung. Reicht der Abschnitt bis heute,
    tauschen die Variablen zusätzlich ihre Rolle.
  - Leistung: Je Viertelstunde wird die Richtung aus Bezug − Einspeisung mit dem Vorzeichen
    der gespeicherten Leistung verglichen. Gegenläufige Abschnitte werden gedreht, die Kanten
    minutengenau bestimmt.
  - Lehnt das Archiv das Nachtragen ab (Schreibpuffer), versucht das Modul es erneut und
    stellt den Tag sonst unverändert wieder her.
- **🧭 Richtungsprüfung** für Zuordnungen „Netzanschluss". Verglichen wird mit einem anderen
  MeterHub-Netzzähler (gleichläufig), mit der InverterHub-Netzmessung (gegenläufig) und
  hilfsweise mit der PV-Erzeugung (nur Hinweis). Das Ergebnis steht beim Schalter und landet
  einmal im Meldungsprotokoll. Neu ist der Diagnose-Vertrag **`MHUB_GetDiagnostics` 1.0**
  (Format wie `IHUBMON_GetDiagnostics`, Eintrag `meter_direction`), 30 min zwischengespeichert.
- Prüfstand `test-powerinvert.php` Blöcke 6–8.

## 0.26.9-beta.1 (2026-09-12)

- **🧹 Inexogy: nur offizielle Viertelstundenwerte im Zähler-Archiv.** Befund an Dietmars
  Anlage: Der Live-Zählerstand von Inexogy hinkt 15–20 min hinterher (um 16:18 kam der
  offizielle Wert von 16:00), wurde aber mit dem Ankunftszeitpunkt archiviert, zwischen
  den offiziellen Viertelstundenwerten des Nachtrags. Das ergab hunderte kleine
  Rückschritte (523/566 in 14 Tagen). Symcons Zähler-Verdichtung zählt jeden davon
  doppelt: Am 10.09. zeigte der Tageswert 3,973 statt 2,454 kWh Bezug.
  - Live: Ein Zählerstand, der nicht über dem neuesten archivierten Stand liegt, wird
    nicht mehr geschrieben.
  - Nachtrag: Nach jedem Lauf werden die Live-Zwischenwerte entfernt, die jetzt von
    offiziellen Werten eng umschlossen sind. Offizielle Werte (Viertelstunden-Raster)
    werden nie angefasst, Live-Werte in Zeiträumen ohne offizielle Daten bleiben.
  - Neu im Inexogy-Bereich: „Live-Zwischenwerte prüfen" (Probelauf) und „bereinigen"
    (`MHUB_CheckInexogyArchiveCleanup`/`MHUB_CleanInexogyArchive`) für die Vergangenheit.
    Der Probelauf zeigt die Rückschritte und die doppelt gezählte Energie vorher und
    nachher.
- Prüfstand Block 38.

## 0.26.8-beta.1 (2026-09-12)

- **Energie-Archiv prüfen/reparieren: Schalter „Einzelne Rückschritte mitreparieren"**
  (Standard an). Der Probelauf an Dietmars Inexogy-Zähler fand neben der Nullwert-Lücke
  524 bzw. 565 einzelne Rückschritte. Sie entstehen, weil Live- und offizielle
  Viertelstundenwerte im selben Archiv liegen. Dort kann der tiefere Punkt gerade der
  abrechnungsrelevante offizielle Wert sein, eine Interpolation zwischen Live-Werten
  wäre also schlechter als das Original. Mit abgeschaltetem Schalter werden Rückschritte
  nur gezählt und gemeldet, repariert werden ausschließlich Nullwert-Lücken.
  `MHUB_CheckEnergyArchive`/`MHUB_RepairEnergyArchive` haben dafür einen dritten
  Parameter.

## 0.26.7-beta.1 (2026-09-12)

- **Energie-Archiv prüfen/reparieren speicherfest für jeden Nutzer.** Die Prüfung hielt
  die Zählerreihe des ganzen Zeitraums mehrfach im Speicher. Bei Zählern mit
  Minutenwerten und langem Zeitraum hätte das Symcons festes 32-MB-Limit für Skripte
  sprengen können. Jetzt bleibt nur der Bereich rund um die Nullwert-Lücken im Speicher,
  die Rohreihe wird nach jeder Variable freigegeben, und der Zeitraum ist auf höchstens
  45 Tage begrenzt.

## 0.26.6-beta.1 (2026-09-12)

- **🛡️ Zählerschutz für kumulative Energiezähler.** Live-Befund an Dietmars Anlage,
  bestätigt durch Inexogys eigenes Portal und CSV-Export: Für eine Übertragungslücke
  des Smart-Meter-Gateways (10.09. 22:45 – 11.09. 10:45) lieferte Inexogy Zählerstand
  **0** statt „kein Wert" und berechnete daraus ±24 MW. Im Archiv entstanden
  ±10 993 kWh je Viertelstunde, der 10.09. zeigte −10 993 kWh, der 11.09. +10 993 kWh.
  - Live-Werte (`SetVarEnergyWh/kWh`, alle Zählertypen): ein Zählerstand wird nur
    übernommen, wenn er > 0 ist und nicht rückwärts läuft. Ein dauerhaft niedrigerer
    Stand (30 Lesungen in Folge) gilt als Zählertausch und wird protokolliert übernommen.
  - Inexogy-Archivnachtrag: Datensätze mit Zählerstand 0 oder hinter Inexogys letztem
    gültigen Stand werden verworfen, ebenso die Leistung dieser Datensätze und des ersten
    danach. Die Lücke bleibt offen statt mit Unsinn gefüllt.
- **🩺 Neues Panel „Energie-Archiv prüfen / reparieren"** (`MHUB_CheckEnergyArchive`,
  `MHUB_RepairEnergyArchive`): findet Nullwerte und Rückschritte in den archivierten
  Zählerständen, zeigt sie zuerst im Probelauf. Die Reparatur löscht die ungültigen
  Punkte und verteilt die bekannte Menge zwischen dem letzten gültigen Stand davor und
  dem ersten danach nach dem Verlauf eines Referenzzählers. Welcher Zähler der Referenz
  passt, ermittelt das Modul an der Übereinstimmung der 24 h davor und nicht am Namen,
  weil der PAC2200 an Dietmars Anlage vertauscht war. Ohne Referenz wird gleichmäßig
  verteilt. Die Leistung in Nullwert-Lücken wird aus der aufgefüllten Energie neu
  berechnet, danach wird neu verdichtet. Lücken ohne gültigen Stand danach und längere
  Rückschritt-Folgen ohne Nullwerte werden nur gemeldet.
- Prüfstand `.tools/test-virtual.php` Block 37 (Zählerschutz inkl. Zählertausch,
  Lückenerkennung am echten Muster, Verteilung nach Referenz/gleichmäßig, Monotonie).

## 0.26.5-beta.1 (2026-09-12)

- **🔧 Eine Modbus-Verbindung je Abfragezyklus statt je Anfrage.** Live-Befund im
  Solarpark: Der Symcon-Rechner hatte gleichzeitig 2 666 abgebaute Verbindungen im
  Wartezustand, davon 2 333 zu einem einzigen blue'Log (.204, 48 MeterHub-Wechselrichter-
  Instanzen) — rund 20 neue TCP-Verbindungen pro Sekunde, weil jede einzelne Anfrage
  eine eigene Verbindung öffnete und sofort schloss. Genau schnelles Verbinden/Trennen
  verkraften blue'Logs nachweislich schlecht (gemessen 09.09.2026).
  - `MHUB_ModbusTcpClient` hält die Verbindung jetzt für alle Anfragen eines Zyklus
    (Lesen und das Sollwert-Schreiben der blue'Log-RPC/Power-Control-Typen) offen;
    MeterHub schließt sie am Ende von Schnell- und Langsam-Zyklus sowie nach
    „Verbindung testen".
  - Antworten werden exakt nach Länge gelesen und über die Transaktions-ID zugeordnet.
  - Bricht eine schon benutzte Verbindung weg, wird einmal neu verbunden; bei
    Zeitüberschreitung oder Modbus-Exception bewusst nicht (keine doppelten Wartezeiten).
  - Die Gerätesuche (MeterHubDiscovery) ist nicht betroffen — sie läuft nur auf
    Knopfdruck und nutzt eigene, bereits parallelisierte Abfragen.
- Neuer Prüfstand `.tools/test-modbus-client.php` mit echtem Modbus-Server im eigenen
  Prozess (Verbindungszählung, Verbindungsabbruch, Exception, stummes Gerät, Schreiben).

## 0.26.4-beta.1 (2026-09-11)

- **🆕 blue'Log-Datenlogger (Adresse 97): Ertrag wird hochgerechnet.** Dietmars Auftrag:
  „Wenn 97 kein Register für Energie liefert, dann müssen wir die Energie selbst
  ausrechnen lassen." Neue Variable „Ertrag gesamt (hochgerechnet)" (kWh, archiviert
  als Zähler): nach jedem erfolgreichen Lesezyklus Leistung × Zeit (Trapez zwischen
  zwei Messwerten) auf den bisherigen Stand.
  - Zeitbasis ist der Aktualisierungszeitpunkt der Leistungsvariable — ein Takt ohne
    gültigen Wert zählt nicht, die Summe der Zeitabschnitte ist exakt die überdeckte
    Zeit.
  - Lücken über 5 Abfragetakte (mindestens 5 Minuten) werden nicht überbrückt —
    lieber etwas zu wenig als erfundener Ertrag. Negative Nachtwerte zählen als 0.
  - Allgemein gelöst über den neuen optionalen Treibervertrag
    `MHUB_CalculatedEnergyDriverInterface` — weitere Geräte ohne Zählerstand lassen
    sich mit einer Zeile anschließen.
  - `MHUB_GetFunctions` meldet solche Energie mit `energyMeasured: false`
    (Vertrag 1.3, additiv).
- News-Panel nachgezogen (Datenlogger-Zählertyp aus 0.26.2/0.26.3 und die Hochrechnung).
- Prüfstand Block 36b (Trapez, Lücke, fehlender Wert, Nacht, Stunden-Probe).

## 0.26.3-beta.1 (2026-09-11)

- **🆕 blue'Log-Datenlogger (Adresse 97): Wechselrichter-Anzahl und Leistungsregelung.**
  Die neue Herstellerdoku „SCADA Interface Register V2.27.0" (2026-05-29, Help Center)
  beschreibt unter 97 weitere Register, die die ältere V2.24.0 nicht kannte:
  - Immer: **installierte** (10004) und **aktive Wechselrichter** (10006) — zeigt
    ausgefallene Wechselrichter auf einen Blick.
  - Optionale Gruppe „Leistungsregelung": **verfügbare Wirkleistung** (10008),
    **verfügbare Blindleistung** (10010), **wirksamer Wirkleistungs-Sollwert in %**
    (10100). Rein lesend — die Doku sieht unter 97 keinen Schreibzugriff vor.
  - Live an drei blue'Logs im Solarpark geprüft: .201 (XM-3000) 31/31 Wechselrichter —
    per Modbus-Zählung bestätigt (31 WR an SCADA-Adresse 127–157); .212 (XC-10000)
    276/271, 3,50 MW/3,26 Mvar verfügbar, Sollwert 100 %; .217 (XC-20000) 440/432.
    Nicht gelieferte Werte (NaN) werden nicht geschrieben.
- Prüfstand Block 36 erweitert.

## 0.26.2-beta.1 (2026-09-11)

- **🆕 blue'Log-Adresse 97 als eigener Zähler.** Dietmars Vorgabe: „Die 97 muss
  definitiv erscheinen, weil die 97 die Zusammenfassung des kompletten Datenloggers
  darstellt." Neuer Zählertyp „Meteocontrol blue'Log SCADA – Datenlogger (Summe,
  Adresse 97)": Register 10000 `P_AC_INV_SUM` (Summe der AC-Leistung aller
  Wechselrichter, beim XC-Master inkl. Slaves), Float32 CDAB, rein lesend.
  - Registerkarte aus „SCADA Interface Register V2.24.0" und live am Solarpark
    gemessen (blue'Log .201: Gerätetyp 0, 567 725 W) — deckungsgleich mit der dort
    von Hand konfigurierten Symcon-Abfrage derselben Größe.
  - Die Discovery prüft Adresse 97 bei jedem gefundenen blue'Log mit (sowohl im
    Netzwerk-Suchlauf als auch im blue'Log-Adressbereich) und bietet sie als
    anlegbaren Zähler an. Gerätetyp 0 wird nur unter 97 akzeptiert.
  - Kein Zählerstand: unter 97 ist keine Energie dokumentiert. Power Control/RPC
    bleiben von Hand anzulegen (keine zwei Schreiber).
- Prüfstand `.tools/test-virtual.php` Block 36 (echte Rohwerte vom blue'Log).

## 0.26.1-beta.1 (2026-09-11)

- **🔧 Fix: falsche Leistung bei Geräten ohne MeterHub-Idents (z. B. Wallboxen).**
  Dashboard-Befund aus Dietmars Anlage: „KFZ-Ladestationen" zählte von WB 1 nur
  „Leistung L2" statt der Gesamt-Ladeleistung — beim dreiphasigen Laden ein Drittel.
  Ursache: die generische Suche nahm die erste W-Variable in Baumreihenfolge, und bei
  WB 1 lag die Kategorie „Phasen" vorn. Jetzt, in dieser Reihenfolge:
  - Bietet das Ziel einen `*_GetFunctions`-Vertrag an (ChargerHub, HeishaMon …,
    erkannt über das Modul-Präfix), gelten dessen Leistung und Zähler — dieselben
    Werte, die das Modul selbst an EMS und Dashboard meldet. Nicht-kumulative Energie
    (`energyKind` ≠ counter) wird nicht übernommen.
  - Der Ident `power` gilt wie `power_total` als Gesamtleistung.
  - Die Suche rankt statt „erster Treffer": Gesamtwert vor Ladevorgangs-/Tageswert vor
    Phasenwert, dann weniger tief verschachtelt. Gibt es mehrere gleichwertige
    Gesamtwerte, erscheint im Baum-Modus eine Warnung mit der getroffenen Wahl.
  - Für MeterHub- und MeterHubVirtual-Ziele bleibt es bei den Idents.
- Prüfstand Block 35n.

## 0.26.0-beta.1 (2026-09-11)

- **✏️ MeterHubVirtual: Mitglieder im Formular bearbeiten.** Dietmars Wunsch: „die
  Mitglieder im Virtual Meter innerhalb des Formulars bearbeitbar machen". Die
  Mitglieder-Tabelle des Baum-Modus erlaubt jetzt Hinzufügen (neue Spalte „Ziel":
  Gerät, Variable oder anderer virtueller Zähler), Löschen, Umsortieren per Drag & Drop,
  Umbenennen und das Ziel zu ändern. Mit „Übernehmen" legt das Modul die Links im
  Objektbaum entsprechend an, löscht, sortiert und benennt sie.
  - Geändert wird nur, was in der Tabelle geändert wurde — eine zwischenzeitliche
    Umbenennung oder ein neuer Link im Objektbaum wird nicht überschrieben bzw.
    gelöscht.
  - Gelöscht werden nur Links. Ein Gerät, das direkt unter der Instanz hängt, bleibt
    und wird gemeldet.
  - Doppelte Ziele, Selbstbezug, Kreisverweise und Zeilen ohne Ziel werden nicht
    übernommen, sondern oben in „Prüfung & Vorschau" gemeldet.
  - Prüfstand Block 35m.
- **🔧 Fix (seit 0.25.0):** Eine Variable, die direkt (nicht als Link) unter einer
  MeterHubVirtual-Instanz einsortiert war, wurde fälschlich als Kreisverweis gemeldet
  und blockierte die ganze Instanz. Echte eigene Ausgaben werden weiterhin abgewiesen.

## 0.25.1-beta.1 (2026-09-11)

Drei Befunde der Dashboard-Sitzung aus Dietmars Live-Anlage („Licht EG"/„Licht OG",
21 Z-Wave-Aktoren als Links):

- **🔀 Schalter werden im Baum-Modus am Ziel erkannt.** Bisher kam der Schalter nur aus
  einer gespeicherten Einstellung — ohne sie war kein Mitglied schaltbar und die
  Gruppenschaltung hatte nichts zu tun. Jetzt sucht das Modul bei jedem Takt wie bei
  Leistung/Bezug die schaltbare Bool-Variable am verknüpften Gerät. Hat ein Gerät
  mehrere, gewinnt genau eine mit dem Ident `StatusVariable` (Schaltvariable des
  Symcon-Z-Wave-Moduls, an allen 21 Aktoren belegt); sonst ⚠️ Hinweis statt Raten.
  Neue Spalte „nicht schalten", die Schalter-Spalte heißt jetzt „Schalter übersteuern"
  und speichert nur noch eine bewusste Abweichung.
- **Links ohne eigenen Namen** heißen wie ihr Ziel (so zeigt Symcon sie auch im Baum) —
  bisher standen sie namenlos in Formular, Kachel und `members[]`.
- **`sourceCount`** zählt nur noch messende Mitglieder (mit mindestens einem Datenpunkt),
  wie dokumentiert („Güte"); `members[]` enthält weiterhin auch reine Schalt-Mitglieder.
- Prüfstand Block 35l.

## 0.25.0-beta.1 (2026-09-11)

- **🌳 MeterHubVirtual: Mitglieder direkt im Objektbaum.** Dietmars Anregung: „einen
  virtuellen Zähler nur durch die Anordnung im Objektbaum zusammenbauen … man würde
  sehr viel schneller erkennen, welcher Zähler noch existiert, und es gäbe nicht so
  viele Leichen" — dazu „die Reihenfolge nach deren Einsortierung".
  - Alles, was direkt unter einer MeterHubVirtual-Instanz hängt — vor allem
    Verknüpfungen (Links) auf ein Gerät, eine einzelne Variable oder eine andere
    virtuelle Zähler-Instanz —, ist Mitglied. Reihenfolge = Position im Objektbaum
    (Symcon liefert Kinder in Anlage-Reihenfolge, das Modul sortiert selbst).
  - Leistung/Bezug/Einspeisung werden bei jedem Takt frisch am Ziel gefunden; je
    Mitglied lassen sich Anteil, Rolle (bei Link auf eine Variable), einzelne
    Datenpunkte und der Schalter in einer Mitglieder-Tabelle übersteuern.
  - Hinzufügen, Entfernen, Umsortieren und Umbenennen im Objektbaum wirken ohne
    „Übernehmen": das Modul hört auf die Objektbaum-Meldungen und prüft zusätzlich
    bei jedem Berechnungstakt einen Fingerabdruck der Mitglieder.
  - Ein Link, dessen Ziel gelöscht wurde, erscheint als ⚠️ „Ziel fehlt" und geht mit 0
    ein — keine stillen Leichen mehr. Selbstbezug, Rückkopplung auf eine eigene
    Ausgabe und Kreisverweise über verschachtelte Instanzen (A enthält B enthält A)
    werden blockiert; vorhandene Ausgaben bleiben dabei erhalten.
  - Verschachteln: einen Link auf eine andere virtuelle Zähler-Instanz unterhängen
    (z. B. mehrere Wallbox-Zähler unter „Fahrzeugbeladung").
  - Sucher-, Quick-Pick- und Familien-Alternative legen im Baum-Modus Links an
    (Familie: dieselbe Verdrahtung inkl. −100 für P23 wie bisher, nur als Objekte);
    „Energie hochrechnen" und „Schalter suchen" arbeiten auf der Mitglieder-Tabelle.
  - Neue Instanzen starten im Baum-Modus. Instanzen mit der bisherigen Tabelle
    rechnen unverändert weiter; ein Panel bietet „In Objektbaum-Mitglieder
    umwandeln" an (`MHUBV_ConvertToTree`) — nur auf Knopfdruck, rechnerisch identisch
    (Link aufs Gerät, wenn es genau diese Datenpunkte liefert, sonst auf die
    Variable mit festgehaltener Zuordnung), die alte Tabelle wird gesichert.
  - WebFront-Kachel: im Baum-Modus Name/Anteil änderbar, Entfernen nur im Objektbaum.
  - Prüfstand `.tools/test-virtual.php` Block 35 (Reihenfolge, Summen, Anteile,
    Nachführung, tote Links, Selbstbezug, Kreisverweis, Verschachtelung,
    Variablen-Links, AddDevice, Hochrechnen, Formular-Liste, Umwandlung).

## 0.24.47-beta.1 (2026-09-09)

- **🔧 Kernursache gefunden und behoben: blue'Log verkraftet keine schnelle
  Verbindungs-Wiederverwendung, aber problemlos viele PARALLELE Verbindungen.**
  Dietmars Testvorschlag „wir müssen nur z.B. #41462 duplizieren" — live mit
  echten parallelen Sockets statt einer Instanz-Duplizierung nachgestellt:
  ein blue'Log beantwortet 8 gleichzeitig offene Verbindungen anstandslos
  (0,07 s), scheitert aber komplett (0 von 8) bei 8 rasch aufeinander-
  folgenden Verbindungen (verbinden → anfragen → schließen → sofort neu
  verbinden) — exakt das Verhalten der bisherigen „mehrere Versuche mit
  Pause"-Strategie, die das Problem damit eher verschärft als gelöst hatte.
  - `identifyBlueLogHost()` fragt jetzt alle Kandidaten (SCADA-Selbst-
    auskunft, zwei Geräte-IDs aus dem SCADA-Bereich, Power Control, RPC) in
    EINEM Rutsch parallel ab (`probeManyParallel()`, echte Sockets +
    `stream_select()`), mit einem zweiten parallelen Versuch als Rückfall
    statt einer wachsenden Pausenkette.
  - Der SCADA-Adressbereich-Suchlauf (sowohl im automatischen „🔎 Netzwerk
    durchsuchen" als auch im gezielten „blue'Log-Adressbereich durchsuchen")
    prüft jetzt bis zu 20 Adressen gleichzeitig statt einzeln mit Pause
    (`identifyBlueLogDevicesBatch()`) — bei einem vollen 100er-Bereich
    dadurch deutlich schneller.
  - Live erneut gegengeprüft: beide zuvor scheiternden echten blue'Logs
    werden jetzt in unter 0,75 s zuverlässig erkannt.
  - Alte, jetzt ungenutzte Sequenz-mit-Pause-Hilfsfunktionen entfernt.

## 0.24.46-beta.1 (2026-09-09)

- **🔧 Fix: blue'Log-Erkennung stützte sich allein auf die Selbstauskunft des Geräts
  (Slave 97).** Auch mit den bisherigen Fixes (getrennter Port, drei Versuche mit Pause)
  fand ein echter Suchlauf über die Instanz weiterhin null blue'Logs. Live isoliert
  nachgestellt (dieselbe Erkennungslogik, an zwei bekannten echten blue'Logs): beide
  scheiterten komplett, zeitgleich mit einer aktiven Regelung durch den Direktvermarkter
  über die RPC-Schnittstelle. Dietmars Hinweis: „vielleicht solltest Du auch mal die
  Geräte-IDs in dieses Spiel mit einplanen" — Slave 97 ist nur die Selbstauskunft des
  blue'Log, genau die dürfte während einer Schreib-Transaktion des Direktvermarkters am
  ehesten belegt sein. Die angeschlossenen Einzelgeräte (SCADA-Adressen ab
  `ScadaAddrStart`) werden davon unabhängig bedient. Die Erkennung probiert deshalb jetzt
  zusätzlich zu 97 noch zwei Geräte-IDs aus dem konfigurierten SCADA-Bereich (Anfang und
  Mitte) — jede für sich ein gleichwertiger Nachweis „hier ist ein blue'Log", mit
  derselben Drei-Versuche-Pause je ID.

## 0.24.45-beta.1 (2026-09-08)

- **🔧 Fix: automatische blue'Log-Erkennung verwendete für SCADA/Power Control/RPC immer
  denselben Port wie die klassische Zählersuche.** Dietmars Fund: „Standardmäßig wird mit
  dem Port 502 gescannt, SCADA und Power Control/RPC haben aber andere Register." Bei ihm
  liegen beide Ports zufällig gleich (502), aber der allgemeine Suchport (Feld „Modbus-TCP-
  Port" im Panel „Suchbereich") und der blue'Log-eigene Port (Feld „Modbus-TCP-Port" im
  Panel „blue'Log SCADA-Adressbereich") sind zwei unabhängige Einstellungen. „🔎 Netzwerk
  durchsuchen" probiert bei einem Fehlschlag jetzt zusätzlich den separat konfigurierten
  blue'Log-Port, falls er vom allgemeinen Suchport abweicht — keine zusätzlichen
  Verbindungen im (vermutlich häufigsten) Fall, dass beide identisch sind. Erklärender Text
  jetzt an beiden Feldern.
- **🔧 Fix: sichtbarer Backslash in drei Hinweistexten** ("blue\\'Log" statt "blue'Log",
  Dietmars Live-Screenshot) — Ursache war die immer wiederkehrende Anführungszeichen-Falle
  dieses Repos: `\'` ist nur in einfach angeführten PHP-Strings eine gültige Escape-Sequenz,
  in doppelt angeführten (nötig für ein eingebettetes „…" mit ASCII-Anführungszeichen als
  Schließer) bleibt der Backslash wörtlich stehen. Auf einfache Anführungszeichen
  umgestellt, wie im übrigen Repo üblich.

## 0.24.44-beta.1 (2026-09-08)

- **🔧 Kritischer Fix: blue'Log-Erkennung fand in der Praxis NICHTS.** Direkt nach dem Ausliefern
  von 0.24.43-beta.1 gemeldet: „Wir haben im Netzwerk etwa 15 blue'Log und Du findest keinen
  Einzigen!" Live am Solarpark gemessen, zwei Ursachen gefunden — keine davon eine falsche
  Register-/Slave-Wahl:
  - **Verbindungs-Konkurrenz mit Dietmars eigenen, parallel laufenden Regel-Skripten.**
    Isolierte, einzelne Modbus-TCP-Verbindungen zu einem blue'Log funktionieren zuverlässig;
    mehrere rasch aufeinanderfolgende Verbindungen zum selben Gerät (wie es `identifyMeter()` +
    `identifyBlueLogHost()` bisher ungebremst taten) scheitern dagegen reihenweise, verschärft
    durch parallelen Zugriff der bereits laufenden Skripte. Jede Schnittstellen-Prüfung bekommt
    jetzt bis zu drei Versuche mit wachsender Pause (300/600/1200 ms) statt eines einzigen
    ungepufferten Versuchs.
  - **Falsche Prioritäten für die tatsächliche Geräteflotte.** An Dietmars Solarpark haben von
    ~15 blue'Logs nur die zwei Master-/EZA-Regler Power Control/RPC, SCADA dagegen praktisch
    alle — die Erkennung prüft deshalb wieder zuerst SCADA (Register 40000, Gerätetyp 0), Power
    Control/RPC nur noch als Rückfall für die Sonderfälle. Vermeidet zusätzlich zwei von
    vornherein aussichtslose Verbindungen für den Regelfall.
  - Live bestätigt: mindestens zwei unabhängige blue'Logs (192.168.200.201, .204) antworten
    zuverlässig über SCADA. Ein Port-502-Suchlauf im Solarpark-Subnetz fand daneben auch ein
    fremdes Gerät (Geutebrück-Videoserver) mit offenem Modbus-Port, das aber auf keiner der drei
    blue'Log-Schnittstellen antwortet — kein Fehlalarm.
  - **Kein Anspruch auf 100 % Trefferquote bei aktiver Fremdlast:** die Wiederholungen erhöhen
    die Erfolgsquote deutlich, können echte Dauerkonkurrenz mit fremdem Datenverkehr aber nicht
    vollständig ausschließen — ein zweiter Suchlauf findet typischerweise mehr.

## 0.24.43-beta.1 (2026-09-08)

- **🔧 Konstruktionsfehler behoben: „🔎 Netzwerk durchsuchen" fand blue'Logs nicht.** Dietmars
  scharfe Rückmeldung nach dem ersten Praxistest des SCADA-Suchmodus: „wenn ich Zähler im
  Netzwerk suchen lasse, dann möchte ich vielleicht auch die blue'Logs angezeigt bekommen …
  ich weiss auch genau was ich von der Qualität Deines Scans halten soll - NIX." Der normale
  IP-Bereichs-Suchlauf erkennt jetzt selbst, ob an einer offenen IP ein Meteocontrol-blue'Log
  antwortet (Register 40000 über Unit-ID 97 == 0, „Datenlogger"), und durchsucht bei einem
  Treffer automatisch den eingestellten SCADA-Adressbereich dahinter mit — ohne dass die
  blue'Log-IP vorher händisch bekannt sein muss. Das bisherige eigene Panel „blue'Log
  SCADA-Adressbereich" bleibt als schnellerer, gezielter Weg bestehen, wenn die IP bereits
  bekannt ist.
- Dokumentations-/Hinweistexte (Doku-Panel, „Welche Zähler findet die Suche"-Popup, blue'Log-
  Panel-Einleitung) entsprechend aktualisiert.

## 0.24.42-beta.1 (2026-09-08)

- **🔧 Fix: „Schnittstellen prüfen"-Zeilen weiter verbessert** (Dietmars zweiter Screenshot): die
  RPC-Zeile war zu lang und brach im nativen Meldungsdialog um — gekürzt auf „RPC
  (Direktvermarkter)" statt ausgeschriebenem „Remote Power Control / RPC (Direktvermarkter)".
  Nicht-erreichbar wird jetzt mit ❌ markiert statt mit „—" — eindeutiges Gegenteil von ✅, statt
  eines Zeichens, das sich nicht klar als „nicht erreichbar" liest.

## 0.24.41-beta.1 (2026-09-08)

- **🔧 Fix: „Schnittstellen prüfen"-Ergebnis war eine schwer lesbare, mit „·" aneinander-
  gereihte Zeile** (Dietmars Screenshot des nativen Meldungsdialogs) — jetzt eine Zeile je
  Rolle (Power Control/RPC/SCADA).

## 0.24.40-beta.1 (2026-09-08)

- **🔧 Fix: Modellbezeichnung bei blue'Log-SCADA-Funden war fehlerhaft dekodiert.** Direkt aus
  Dietmars Screenshot der ersten echten Mehrfach-Suche gefunden (Zählertyp zeigte
  „Wechselrichter () 2en(GC IE0- 2TL 3AE)" statt eines lesbaren Modellnamens). Root Cause live
  verifiziert: Bei diesen Geräten (ByteOrder 3) ist bei mehrregistrigen Strings nicht nur
  paarweise wie bei Float32 (CDAB) vertauscht, sondern die GESAMTE Registerreihenfolge
  umgekehrt — das letzte Register trägt die ersten Zeichen. `readAscii()` liest die Register
  jetzt rückwärts und schneidet am ersten Null-Byte ab (statt nur rechts zu trimmen, falls
  Alt-Bytes eines vorher längeren Namens dahinterstehen). Nebenbei aufgefallen: die vorherige,
  scheinbar korrekte erste Verifikation dieser Funktion zeigte ebenfalls einen falschen, nur
  zufällig plausibel aussehenden Modellnamen — jetzt an vier verschiedenen Geräten zweier
  Kombiner-Boxen sauber gegengeprüft ("AE 3TL 20-IEC (Gen 2)", "SUN2000-36KTL-M3").
- **Fundliste im „Erstellen"-Panel höher**: `rowCount: 0` ("füllt den verbleibenden Platz" laut
  SDK-Doku) zeigte sich in der Praxis nicht wie erhofft (Dietmars Live-Screenshot) — durch
  einen festen, großzügigen Wert (15) ersetzt, der zuverlässig mehr Zeilen zeigt.

## 0.24.39-beta.1 (2026-09-08)

- **Praxis-Feinschliff am „Erstellen"-Panel und der blue'Log-Suche**, alles direkt am Live-Fund
  (Screenshot der ersten echten SCADA-Suche) gefunden und live gegengeprüft:
  - **Fundliste breiter/höher**: Spalte „Zählertyp" auf `width: 'auto'` (füllt den
    verbleibenden Platz — laut SDK-Doku darf genau eine Spalte das), `rowCount: 0` (füllt
    laut SDK-Doku den verfügbaren Platz statt fester 6 Zeilen) — bei vielen blue'Log-Funden
    (z. B. 48 Wechselrichtern) vorher zu schmal und zu niedrig.
  - **Namens-Vorlage ins „🛠️ Erstellen"-Panel verschoben** (aus „🔎 Suchbereich") — Dietmars
    berechtigter Einwand: Benennung hat nichts mit Suchen/Filtern zu tun, sondern mit dem
    Erstellen-Schritt. Gilt weiterhin für beide Suchmodi.
  - **Schnellere Suche bei dünn besetztem Adressbereich**: Zeitlimit der reinen
    Existenzprüfung je SCADA-Adresse von 1,0 s auf 0,5 s halbiert — unbelegte Adressen
    landeten schon vorher nie im Ergebnis (Rückfrage geklärt, kein Anzeigefehler), aber ihre
    Zeitüberschreitung dominierte bei einem vollen 1-247-Bereich die Suchdauer. Live
    verifiziert: eine unbelegte Adresse braucht am Solarpark ca. 1,0–1,02 s bis zur
    Zeitüberschreitung, real also fast exakte Halbierung.
  - **🔧 Fix**: blue'Log-Funde bekamen bisher den Port des allgemeinen IP-Suchlaufs
    (Property `Port`) statt des separaten `BlueLogPort` — bei abweichendem Port wäre die
    neue Instanz mit dem falschen Wert angelegt worden.
  - **🆕 „Bekannten Host übernehmen"**: Auswahlliste für „blue'Log-IP-Adresse" aus den
    Host-Werten bereits bestehender MeterHub-Instanzen — Dietmars Einwand: die IP sonst
    erst über das Meteocontrol-VCOM-Portal + VPN + Login heraussuchen zu müssen, obwohl sie
    oft schon in einer eigenen Instanz steht.
  - **🆕 „Schnittstellen prüfen"**: neue Schaltfläche prüft Slave-ID 1 (Power Control), 10
    (RPC) und 97 (SCADA) auf reine Erreichbarkeit, bevor ein Adressbereich konfiguriert
    wird. Hintergrund (Dietmars Hinweis): die SCADA-Lizenz ist ein teures Zusatzmodul und in
    der Praxis selten — die blue'Log-Standardlizenz enthält weder sie noch RPC. Live an
    Dietmars Solarpark verifiziert: beide NAP-„MASTER"-blue'Logs antworten auf allen drei
    Rollen, der Feld-Datenlogger (SP Hohberg) dagegen nur auf SCADA — Power Control/RPC
    laufen dort korrekt in die Zeitüberschreitung.

## 0.24.38-beta.1 (2026-09-08)

- **Eigene Namen für blue'Log-SCADA-Funde: neuer Platzhalter `{busaddr}`.** Dietmars Fund nach
  dem ersten erfolgreichen Suchlauf ("die WR werden perfekt gefunden, aber wie benenne ich sie
  im Formular?") — die bestehende „Namens-Vorlage" (Platzhalter `{zaehler} {ip} {unitid} {nr}`)
  war schon immer für beide Suchmodi gültig, aber nirgends darauf hingewiesen. Zusätzlich neuer
  Platzhalter `{busaddr}` — liest die RS485-Busadresse (Register 40113, entspricht der Spalte
  „Adresse" in der blue'Log-Geräteliste) direkt beim Suchlauf mit aus; live an Dietmars
  Solarpark verifiziert (sequenzielle Werte 22-26 für die SCADA-Adressen 172-176). Neue
  Hinweiszeile direkt im blue'Log-Panel verweist auf das Namens-Vorlage-Feld, statt dass es nur
  in einem anderen Panel dokumentiert bleibt.

## 0.24.37-beta.1 (2026-09-07)

- **Erster Schreibzugriff in MeterHub: blue'Log RPC/Power Control.** Universeller Treiber,
  Teil 3 — nach Rücksprache mit ModbusSlave und EMS. Zwei neue Zählertypen,
  `MHUB_BlueLogRpcDriver` (Slave-ID 10, Direktvermarkter-Rolle) und
  `MHUB_BlueLogPowerControlDriver` (Slave-ID 1, Netzbetreiber-Rolle — mit deutlicher
  Formular-Warnung, da eine Instanz damit diese Rolle gegenüber dem blue'Log übernimmt).
  Bisher war MeterHub rein lesend (siehe Modulkopf); neues optionales
  `MHUB_WritableMeterDriverInterface` markiert nur diese zwei Treiber als schreibend, ohne
  die 17 bestehenden, rein lesenden Treiber anzufassen. Neue, erstmalige Schreibfähigkeit in
  `MHUB_ModbusTcpClient` (FC 0x10, `writeHolding()`/`packFloat32()` als Gegenstück zu
  `readFloat32()`).
  - **Sollwert-Modus umschaltbar** (Select „Relativ %"/„Absolut W", Dietmars ausdrücklicher
    Wunsch: „je nach Anwendungsfall muss man zwischen ABS und REL umschalten können") —
    schreibt je nach Modus auf REL (5000) oder ABS (5002 bei RPC, 5006 bei Power Control).
  - **MeterHub hält den Zielwert selbst** (Property „Zielwert", bei jedem Lesezyklus erneut
    geschrieben) statt nur eine aufrufbare Funktion anzubieten — bei RPC ist das zugleich das
    Sicherheitsnetz gegen das Ablaufen der Gültigkeitszeit (Register 5006, konfigurierbar).
    Power Control hat laut Herstellerdoku **keinen** vergleichbaren Watchdog-Mechanismus —
    ein gesetzter Wert bleibt stehen, bis er aktiv überschrieben wird (im Formular als eigene
    Warnung gekennzeichnet).
  - **Ausfallverhalten wählbar**: „Default-Sollwert schreiben" (einmalig aktiv beim
    Deaktivieren, immer relativ — 100 % ist ein eindeutiges „Normalbetrieb"-Signal, unabhängig
    vom sonst gewählten Modus) oder „letzten Sollwert halten" (kein aktives Eingreifen).
  - Neue öffentliche Funktion `MHUB_SetBlueLogTarget($id, $wert)` für Skripte/EMS — setzt den
    Zielwert und schreibt ihn sofort, statt auf den nächsten Zyklus zu warten. MeterHub
    berechnet den Wert bewusst nicht selbst (z. B. keine kapazitätsgewichtete Aggregation
    mehrerer Direktvermarkter-Quellwerte) — das bleibt außerhalb.
  - **Hintergrund/Zweck** (Dietmar, 07.09.2026): Ersatz für eine an seinem Solarpark bereits
    produktiv laufende, aber nur schwer nachvollziehbare Ad-hoc-PHP-Skript-Lösung
    („Regelung der blue'Log Master", zwei Instanzen je NAP + ein Quotierungs-Timer) — geplant
    als Parallelbetrieb-Test, danach vollständige Ablösung der Skripte. Zusätzlich als
    generischer NRG-Stack-Baustein gedacht (auch für andere Nutzer mit ähnlichem Bedarf).
  - **Sicherheitsklärung mit ModbusSlave (Cross-Session, 07.09.2026):** Vor jeder ersten
    Aktivierung an einer echten Anlage sicherstellen, dass kein zweites System (Skript, EMS,
    Fremdgerät) bereits denselben Kanal bedient — zwei Schreiber auf demselben RPC-/
    Power-Control-Kanal sind nicht vorgesehen und wurden von Dietmar ausdrücklich
    ausgeschlossen. Formular trägt entsprechend deutliche Warnhinweise.
  - **Nur lokal (`packFloat32`/Interface-Zuordnung) verifiziert, NICHT live gegen den echten
    Schreibkanal getestet** — das bleibt bewusst aus, bis die bestehenden Ad-hoc-Skripte am
    Solarpark koordiniert deaktiviert sind (Kollisionsschutz).

## 0.24.36-beta.1 (2026-09-07)

- **Neuer Suchmodus in MeterHubDiscovery: „blue'Log SCADA-Adressbereich".** Dietmars Auftrag
  „universeller Treiber für die blue'Logs" — Teil 2. Bisher fand MeterHubDiscovery Zähler nur
  über einen IP-Bereich (ein Gerät je IP). Bei einem Meteocontrol-blue'Log ist es umgekehrt: EIN
  fest bekannter Datenlogger, aber viele dahinter angeschlossene Geräte, jedes über seine eigene,
  am blue'Log selbst frei vergebene SCADA-Adresse. Neuer Suchlauf (`MHUBD_DiscoverBlueLog`)
  fragt Register 40000 (Gerätetyp) über einen konfigurierbaren SCADA-Adressbereich ab, schlägt
  jede erkannte, unterstützte Adresse (Wechselrichter/Zähler) als eigene MeterHub-Instanz vor —
  dieselbe Fundliste/„Erstellen"-Mechanik wie beim bestehenden IP-Suchlauf, keine Verdopplung.
  Nicht unterstützte Gerätetypen (Sensor, Tracker, Genset, Batterie, Kraftwerksregler, das
  blue'Log selbst) werden erkannt, aber bewusst nicht vorgeschlagen. Modellbezeichnung (Register
  40033) fließt zur besseren Beschriftung mit ein. Live an Dietmars Solarpark verifiziert:
  Adresse 97 (das blue'Log selbst) korrekt als „nicht unterstützt" übersprungen, mehrere echte
  Wechselrichter-Adressen korrekt erkannt samt Modellbezeichnung, unbelegte Adressen liefern
  sauber „keine Antwort" statt eines Fehlers. Damit lässt sich die „Kette" aus vielen einzelnen
  SCADA-Geräten (siehe 0.24.35) jetzt per Suchlauf statt Handarbeit aufbauen.

## 0.24.35-beta.1 (2026-09-07)

- **Zwei neue Zählertypen: Meteocontrol blue'Log SCADA (Wechselrichter/Zähler).** Dietmars
  Auftrag „universeller Treiber für die blue'Logs" — Teil 1 (lesend). Ein blue'Log
  (Solarpark-Datenlogger) kann laut Meteocontrols SCADA-Interface-Lizenz (557.009) jedes
  angeschlossene Gerät unter einer eigenen, am blue'Log selbst frei vergebenen „SCADA-Adresse"
  im selben Registerschema anbieten (Adresse 97 = das blue'Log selbst/Summenwerte; jedes
  angeschlossene Gerät bekommt eine eigene Adresse, an Dietmars Anlage ab 100 aufwärts) —
  `MHUB_BlueLogScadaInverterDriver` (Registerblock 41000) und `MHUB_BlueLogScadaMeterDriver`
  (Registerblock 43000) lesen genau EIN Gerät über dessen SCADA-Adresse (= `UnitId` der
  Instanz), FC 0x03, Float32 wortgetauscht (CDAB). Byte-Reihenfolge und Registeradressen NICHT
  aus der Herstellerdoku angenommen, sondern live an Dietmars Solarpark gegengeprüft:
  `ByteOrder 3` aus Symcons eigener Modbus-Konfiguration abgelesen, und der Wechselrichter-
  Treiber lieferte exakt denselben Momentanwert wie die dort unabhängig konfigurierte
  Alt-Verdrahtung (48 Wechselrichter-Instanzen „WR ⟨Feld⟩.1.⟨Kombiner⟩.⟨WR⟩"). Der
  Zähler-Registerblock (43000) folgt derselben, am selben Tag gelesenen Registerkarte,
  hatte an dieser Anlage aber kein eigenes Prüfobjekt (kein per SCADA-Adresse einzeln
  angesprochener Zähler vorhanden) — vor dem ersten produktiven Einsatz gegen die
  Geräteanzeige abgleichen. Neues geteiltes Enum-Profil `MHB.BlueLogDeviceType`
  (Register 40000, Gerätetyp-Rückmeldung 0–9) als Diagnose-Variable in beiden Treibern.
  Viele solcher Basisinstanzen (eine je Gerät/SCADA-Adresse) lassen sich anschließend über
  MeterHubVirtual zu einer Feld-/NAP-Summe verketten — die eigentliche „Kette", die Dietmar
  für seinen Solarpark gebraucht hat.

## 0.24.34-beta.1 (2026-09-03)

- **Schaltgruppe (MeterHubVirtual, `MHUBV_GetFunctions` 1.4).** Dietmars Entscheidung, über
  die Dashboard-Sitzung adressiert: Viele Mitglieder eines virtuellen Zählers sind Aktoren,
  die schalten UND messen (z. B. Z-Wave-Schaltaktoren mit Leistungsmessung) — ein virtueller
  Zähler wie „Licht OG" ist damit auch eine Schaltgruppe. Neue Spalte „Schalter" in der
  Zeilentabelle (automatisch vorgeschlagen beim Übernehmen eines Geräts, bei Mehrdeutigkeit
  bewusst nicht geraten; neuer Knopf „Schalter für vorhandene Zeilen suchen" trägt es für
  ältere Zeilen nach). Gibt es mindestens ein schaltbares Mitglied mit **positivem** Anteil,
  entstehen „Gruppe schalten" (Bool) und „Gruppenstatus" (aus/teilweise/an) an der Instanz —
  ein Klick schaltet alle passenden Mitglieder gemeinsam. **Nur positive Anteile werden
  mitgeschaltet**, abgezogene Zeilen (z. B. Wallboxen in „Hausverbrauch ohne Wallboxen")
  bewusst nicht — einzeln bleiben sie über ihr eigenes `switchID` schaltbar. Vertrag additiv
  auf 1.4 (`switchID` je Mitglied, `switchID`/`switchStateID` der Gruppe, je Zuordnung UND
  auf Instanzebene für verkettete virtuelle Zähler). `MHUBV_SwitchGroup($id, $on)` auch für
  Skripte.

## 0.24.33-beta.1 (2026-09-03)

- **`MHUBV_GetFunctions` 1.3: neues Feld `members` (MeterHubVirtual).** Dashboards Anfrage,
  von Dietmar so entschieden: Sammelknoten sollen sich in der Energiefluss-Kachel per Klick
  „aufschachteln" lassen, und die Hierarchie verketteter virtueller Zähler kommt dabei vom
  Anbieter, nicht aus einer Dashboard-eigenen Gruppierung. Jede Zuordnung — und unabhängig
  von der Funktion auch die Instanzebene — liefert jetzt ihre Terme (`name`, `factor`,
  `powerID`, `energyImportID`, `energyExportID`). Nur die eigene Ebene; ob ein Term selbst
  ein virtueller Zähler ist, löst der Konsument über dessen `GetFunctions()` auf. Negative
  Anteile werden bewusst mitgeliefert (am Vorzeichen als „abgezogen" erkennbar). Additiv:
  `sourceCount` und alle bisherigen Felder bleiben unverändert, `archiveWatermarkTs` wird
  (wie bei jedem `realtime`-Zähler) als `null` mitgeführt.

## 0.24.32-beta.1 (2026-09-02)

- **Dank an Sepp Lausch (seppm).** README bekommt einen neuen Abschnitt „Dank" — als
  Betatester mit fundiertem KNX-Zählertechnik-Fachwissen hat er maßgeblich dazu beigetragen,
  MeterHubVirtual praxistauglich zu machen. Ein kurzer Dank dazu auch im „Wozu dieses
  Modul?"-Panel von MeterHubVirtual.

## 0.24.31-beta.1 (2026-09-02)

- **Zwei neue Sammelfunktionen: „Haushaltsgeräte (allgemein)" und „Unterhaltungsmedien".**
  Dietmars Anregung, abgestimmt mit der Dashboard-Sitzung (die Kostenauswertung über Zeit +
  Vergleich mit dem Deutschen Mittelpreis baut dort auf, MeterHubVirtual bleibt bei reiner
  Energie-Aggregation): der neue Suchfilter „Nur Funktion X" (0.24.29) konnte bisher nur
  vorhandene Einzelkategorien wie „Waschmaschine" oder „Beleuchtung" finden — für einen
  Sammelzähler über beliebige Haushaltsgeräte oder Unterhaltungselektronik (TV, Audio,
  Spielekonsole) fehlte eine passende Funktion. Wie gewohnt in MeterHub UND MeterHubVirtual
  gleich, damit das Vokabular synchron bleibt.

## 0.24.30-beta.1 (2026-09-02)

- **Fix: eine MeterHubVirtual-Instanz als Quelle für eine ANDERE MeterHubVirtual-Instanz
  übernehmen fand „Leistung" nicht.** Sepps Live-Fund: beim Verketten zweier virtueller
  Zähler (siehe Doku-Panel „Mehrstufige Verschachtelung … über mehrere Instanzen") meldete
  „Gerät wählen" Bezug und Einspeisung korrekt, aber „Leistung: nicht gefunden" — obwohl die
  Quellinstanz sichtbar eine Leistungs-Variable hatte. Ursache: die automatische
  Ident-Erkennung suchte nur nach dem MeterHub-Ident „power_total", die eigene
  MeterHubVirtual-Ausgabe heißt aber „power". Bezug/Einspeisung fanden sich nur, weil beide
  Module dafür denselben Ident verwenden. Betraf ausschließlich das Verketten von virtuellen
  Zählern untereinander, nicht die Übernahme echter MeterHub-Zähler.

## 0.24.29-beta.1 (2026-09-02)

- **Neuer Suchfilter „Nur Funktion X" + Beispiel „Sammeln als Kostenblock".** Dietmars
  Anregung: eine Sparte wie „Beleuchtung" über mehrere, unterschiedlich benannte
  Stromkreise hinweg zu einem Sammelzähler zusammenfassen, um sie als Energie- und (über
  einen Tarif) Kostenblock darzustellen. Der Suchlauf („Zähler suchen") kann jetzt zusätzlich
  nach der Funktions-Zuordnung filtern, die einzelne Stromkreise schon an ihrer jeweiligen
  MeterHub-Instanz tragen — trifft auch dann, wenn der Gerätename allein nicht eindeutig ist
  (z. B. „Flur", „Wohnzimmer", „Außenbeleuchtung" statt durchgängig „Licht …"). Dazu ein
  neues Beispiel im Doku-Panel, das den Anwendungsfall und den neuen Filter erklärt.

## 0.24.28-beta.1 (2026-09-02)

- **🧪 Experiment: WebFront-Kachel für MeterHubVirtual.** Dietmars Anregung, nach dem
  Vorbild seiner bereits produktiv laufenden Konfigurationskachel in OCPPHubAbrechnung:
  „vielleicht bietet HTML eine bessere Alternative um solche Virtuellen Zähler
  zusammenzubauen." Die Kachel zeigt die Formel-Zeilen mit ihren aktuellen Live-Werten
  und dem Gesamtergebnis (Leistung/Bezug/Einspeisung), Name und Anteil (%) lassen sich
  direkt in der Kachel bearbeiten, ebenso das Entfernen einer Zeile. Eine neue Zeile
  hinzufügen oder eine Variable neu zuweisen bleibt bewusst dem Konsolenformular
  vorbehalten — Symcons eingebauter Variablenpicker lässt sich in reinem HTML nicht
  gleichwertig nachbauen. Ergänzt das Konsolenformular, ersetzt es nicht.

## 0.24.27-beta.1 (2026-09-02)

- **Zweck-Einführung „Wozu dieses Modul?" (alle drei Module).** Sepps Rückmeldung: er wusste
  anfangs nicht, wofür MeterHub/MeterHubDiscovery/MeterHubVirtual überhaupt gedacht sind, nur
  wie man sie bedient. Neues, aufgeklapptes Panel ganz oben im Formular — kurz und knapp WOFÜR
  das jeweilige Modul da ist, bevor es an die Bedienung geht. Einmalig dismissible wie der
  Forum-Hinweis (nicht pro Version, der Zweck ändert sich nicht mit jedem Release).
- **Übersichtsgrafik im Doku-Panel (MeterHubVirtual).** Auf Dietmars Anregung („wie die
  BDEW-Messkonzepte") zeigt eine eingebettete Skizze die drei Verdrahtungs-Muster
  (Sammeln/Abziehen/Aufteilen) als Boxen-und-Pfeile-Diagramm, mit denselben Beispielgeräten wie
  die bestehenden Text-Beispiele direkt darunter.

## 0.24.26-beta.1 (2026-09-01)

- **Übersichtlicheres Verdrahtungs-Panel (MeterHubVirtual).** Sepps Rückmeldung, per
  Dietmar weitergegeben: „nicht so klar was erreicht man wie" — die vier Wege, eine Zeile
  hinzuzufügen (Suchlauf, Direktwahl, Geräte-Familie, von Hand), standen bisher flach
  hintereinander, ohne erkennbar zu machen, dass es gleichwertige ALTERNATIVEN sind, kein
  Ablauf zum Abarbeiten. Jetzt: eine kurze, benannte Übersicht direkt am Anfang des Panels
  („1. Die Sucher-Alternative", „2. Die Quick-Pick-Alternative", „3. Die
  Familien-Alternative", „4. Die Handarbeit-Alternative"), und dieselben Namen als eigene
  Zwischenüberschrift bei den jeweils passenden Bedienelementen.

## 0.24.25-beta.1 (2026-09-01)

- **Fix: „Geräte-Familie erkennen" (Lingg&Janke-Muster) erzeugte bei Sepps Zähler eine
  falsche vierte Zeile** „Zähler Stichtag — Bezug". Ursache: die Instanz „Zähler Stichtag
  Wirkenergie A14 (kWh)" (ein Stichtags-Schnappschuss, kein laufender Zählerstand) endet
  zufällig auf denselben Namens-Suffix wie der echte Zähler. Wird jetzt wie „Zwischenzähler"
  bewusst ausgeschlossen.

## 0.24.24-beta.1 (2026-09-01)

- **Fix: „Lizenztext ansehen" zeigte die alte MIT-Lizenz statt PolyForm Noncommercial.**
  Dietmars Fund: der Knopf verlinkte auf `main`, dessen `LICENSE`-Datei noch die MIT-Lizenz
  aus der Repo-Frühphase trägt — laut SUITE.md-Konvention bewusst so, der Lizenzwechsel
  wirkt nur nach vorn und gilt erst ab dem jeweiligen beta→main-Release. Der Knopf verlinkt
  jetzt auf den `ems-integration`-Branch, wo bereits die aktuelle PolyForm-Lizenz steht.

## 0.24.23-beta.1 (2026-09-01)

- **Fix: „Lizenztext ansehen"/„Spenden via PayPal" (und „Zum Forums-Thread") taten beim
  Klick nichts.** Dietmars Live-Fund: bei einem normalen `Button` ist `link` kein URL-String,
  sondern ein reiner `true`/`false`-Schalter — die eigentliche URL muss die Echo-Ausgabe des
  `onClick`-Skripts sein. Betraf alle drei neuen Lizenz-/PayPal-Knöpfe (0.24.22) UND den
  bereits länger bestehenden Forum-Hinweis-Knopf (der Fehler fiel dort nur nie auf, weil
  dessen Link noch ein Platzhalter ist). Gegen die offizielle SDK-Doku geprüft, jetzt in
  SUITE.md als Stolperfalle dokumentiert, damit kein künftiger Link-Knopf denselben Fehler
  wiederholt.

## 0.24.22-beta.1 (2026-09-01)

- **🆕 Neues Panel „🧡 Über dieses Modul"** ganz unten in allen drei Formularen (nach dem
  Forum-Hinweis), verbundweite Konvention ab sofort — Dietmars Auftrag: die Modulentwicklung
  soll erwähnen, dass sie mit Freude entsteht, aber auch geistiges Eigentum ist und Geld
  kostet. Nennt die Lizenz (PolyForm Noncommercial 1.0.0, privat/nicht-kommerziell frei,
  gewerblich lizenzpflichtig) mit Link zum vollständigen Lizenztext, eine Kontakt-E-Mail für
  Lizenzanfragen (dietmar@gureth.eu) und einen PayPal-Link für freiwillige Unterstützung.
  Anders als der Forum-Hinweis bewusst NICHT wegklickbar — eingeklappt, aber dauerhaft
  vorhanden. Wortlaut jetzt in SUITE.md „Einheitliche Formular-Optik" festgehalten, damit
  alle NRG-Stack-Module denselben Text übernehmen.

## 0.24.21-beta.1 (2026-09-01)

- **Fix: doppeltes Fragezeichen an allen sechs „?"-Hilfe-Knöpfen** (z. B. „Was bedeuten die
  drei Verdichtungsstufen? ?"). Dietmars Fund: die Caption ist inzwischen eine volle Frage
  statt eines bloßen „?" — das zusätzlich angehängte „ ?" war dadurch überflüssig geworden.
  Jetzt verbundweit als Konvention in SUITE.md „Feld-Hilfestellung" festgehalten (volle
  Frage als Caption, kein zweites Fragezeichen anhängen), damit alle Module das künftig
  gleich machen.

## 0.24.20-beta.1 (2026-09-01)

- **🆕 „Geräte-Familie erkennen" versteht jetzt auch KNX-Zähler mit getrennten Bezugs-/
  Einspeisungswerten** (z. B. Lingg&Janke „Haus Zähler": P14/P23 Leistung, A14/A23 Energie
  in kWh) — bisher nur das MDT-AZI-Muster (ein Wert = eine Wirkleistung + ein Hauptzähler
  kWh). Anders als bei AZI liefert dieser Zählertyp keinen vorzeichenbehafteten Netzwert
  wie ein Modbus-Zähler, sondern vier getrennte, immer positive Werte — ein Klick baut jetzt
  automatisch die dafür nötige Drei-Zeilen-Verdrahtung: „Bezug" (P14 + A14, Faktor 100),
  „Einspeisung (Leistung)" (P23 allein, Faktor −100 — ergibt zusammen mit der Bezugs-Leistung
  eine signierte Summe) und „Einspeisung (Energie)" (A23 allein, Faktor 100). Erkennt gezielt
  nur die Gesamtwerte „…Wirkleistung P14/P23 (W)"/„…Wirkenergie A14/A23 (kWh)" — die
  Phasenaufteilung (P14 L1/L2/L3) und das (Wh)-Duplikat (statt kWh) werden bewusst
  übersprungen.

## 0.24.19-beta.1 (2026-09-01)

- **🆕 Fehlende Bezugswerte aus der Leistung hochrechnen (MeterHubVirtual).** Auslöser:
  Sepps AZI-Aktoren, bei denen manche Geräte nur eine Wirkleistung, aber keinen Hauptzähler
  kWh liefern (z. B. „AZI Backofen"). Neuer Knopf im Panel „🔌 Verdrahtung": „Fehlende
  Energiewerte aus der Leistung hochrechnen" — legt für jede Zeile mit Leistung, aber ohne
  Bezug, eine eigene, klar beschriftete Variable an (Name endet auf „— Energie
  hochgerechnet") und summiert dort bei jedem Berechnungsdurchlauf Leistung × Berechnungs-
  Intervall auf. **Bewusst „hochgerechnet", nicht „geschätzt"** (Dietmars Grundsatzeinwand:
  das ist eine echte Rechnung aus real gemessenen Werten, keine Vermutung — die Genauigkeit
  hängt vom Berechnungs-Intervall ab, das steht so auch im Ergebnistext und an der Variable
  selbst). Schreibt wie die anderen Picker nur ins offene Formular, „Übernehmen" bleibt der
  letzte Schritt; die Variable bekommt bei „Übernehmen" dieselbe Archiv-Behandlung
  (Aggregationstyp „Zähler", Verdichtungs-Staffelung) wie ein echter Bezugszähler und
  übersteht die normale Variablen-Aufräumung, auch wenn keine Zeile mehr auf sie verweist
  (bleibt als Historie stehen, wird dann aber nicht mehr fortgeschrieben).
- **🆕 „Prüfung & Vorschau" warnt jetzt vor unvollständigen Zeilen** (nicht blockierend):
  hat eine Zeile eine Leistung, aber keinen Bezug, während andere Zeilen derselben Formel
  einen Bezug haben, zählt die Bezug-Summe diese Zeile bisher lautlos mit 0 kWh statt mit
  ihrem tatsächlichen Verbrauch — jetzt weist ein ⚠️-Hinweis direkt darauf hin und verlinkt
  auf die neue Hochrechnen-Funktion. Eine reine Leistungs-Formel (keine Zeile hat einen
  Bezug) bleibt bewusst ohne Warnung — das ist ein gültiger, oft gewollter Fall.

## 0.24.18-beta.1 (2026-09-01)

- **🆕 Geräte-Familien ohne gemeinsamen Container erkennen (MeterHubVirtual).** Auslöser:
  Sepps Diagnose-Ergebnis seiner MDT-AZI-Aktoren — dort ist jeder Messwert eine EIGENE
  KNX-Instanz (kein Geräte-Container mit mehreren Kindern, wie es der bestehende
  Geräte-Picker erwartet), nur der gemeinsame Namens-Anfang verbindet z. B. „AZI
  Waschmaschine Wirkleistung" und „AZI Waschmaschine Hauptzähler kWh". Neuer Weg im Panel
  „🔌 Verdrahtung": Kategorie wählen, „🏷️ Geräte-Familie erkennen und übernehmen" findet
  alle passenden Geschwister-Instanzen darunter und schlägt sie auf einmal als neue Zeilen
  vor (schreibt wie die anderen Picker nur ins offene Formular, „Übernehmen" bleibt der
  letzte Schritt). „Zwischenzähler" wird bewusst nicht als Energiequelle erkannt (laut
  Namen ein rücksetzbarer Zwischenstand). Beide in Sepps Anlage beobachteten Schreibweisen
  („Hauptzähler"/„Hauptzaehler") werden erkannt.
- Nebenbefund bei der Analyse, live bestätigt: Der normale Suchlauf (`ScanMeters()`)
  findet diese AZI-Wirkleistungs-Variablen NICHT — sie tragen weder ein klassisches Profil
  noch eine Darstellung mit `SUFFIX`/`PROFILE`, nur eine reine `TEMPLATE`-Darstellung ohne
  auslesbare Einheit. Der Lingg&Janke-„Haus Zähler" ist davon NICHT betroffen (seine
  Variablen tragen echte System-Profile wie `~ValuePower.KNX` direkt) und wird vom
  bestehenden Suchlauf bereits zuverlässig gefunden.

## 0.24.17-beta.1 (2026-09-01)

- **Fix: „Übernehmen" konnte mit „Fehler beim Übernehmen der Änderungen" (Code -32603)
  fehlschlagen**, sobald an der Archiv-Verdichtung etwas geändert wurde — zwei getrennte,
  live nachgestellte Ursachen (Sepps Testerfund, „Hausverbrauch"-Instanz):
  1. Eine Verdichtungsstufe auf „aus" gestellt, an deren Monats-Offset noch NIE eine Regel
     im Archiv existierte (z. B. gleich beim Anlegen einer neuen Instanz): `AC_SetCompaction()`
     wirft dafür eine PHP-Warnung („Der zu löschende Verdichtungseintrag wurde nicht
     gefunden"), die unbehandelt das ganze „Übernehmen" scheitern ließ. Der Aufruf ist jetzt
     bewusst mit `@` abgesichert — live geprüft, dass das die Warnung zuverlässig unterdrückt.
  2. Eine Verdichtungsregel an einem Monats-Offset AUSSERHALB der aktuell konfigurierten drei
     Stufen (z. B. von einer früheren „Nach so vielen Monaten"-Einstellung, oder weil die
     Variable schon vor der Aufnahme in MeterHub/MeterHubVirtual eine eigene Verdichtung
     hatte — bei extern verdrahteten Datenpunkten wie KNX-Zählern durchaus möglich) blieb
     bisher für immer im Archiv stehen. IPS verlangt aber, dass der Verdichtungstyp mit
     wachsendem Monats-Offset nie wieder feiner wird — eine solche verwaiste Regel kann das
     brechen und jedes künftige „Übernehmen" blockieren. `SetArchive()` räumt jetzt vor dem
     Setzen der neuen Staffelung jede Regel weg, die nicht zum aktuellen Plan gehört.
- **Fix: „Jetzt neu berechnen" (MeterHubVirtual) aktualisierte das offene Formular nicht.**
  Der Knopf meldete zwar per Text Erfolg, das Panel „Prüfung & Vorschau" — aus den Werten im
  bereits offenen Formular berechnet — blieb aber auf dem alten Stand (Stolperfalle 12,
  SUITE.md: ein per `onClick` aufgerufener Button aktualisiert ein offenes Formular nicht von
  selbst). Ruft nach dem Neuberechnen jetzt zusätzlich `ReloadForm()` auf.

## 0.24.16-beta.1 (2026-09-01)

- **Feld-Hilfestellung („?"-PopupButtons) ergänzt** — Dietmars Rückmeldung zu 0.24.15: das neue
  News-Panel in `MeterHubDiscovery` fehlte komplett, und an mehreren Stellen mit wirklich
  nicht-offensichtlichem Verhalten gab es kein „?" (Symcon kennt keinen Mouseover-Tooltip,
  siehe SUITE.md „Feld-Hilfestellung").
  - **`MeterHubDiscovery`**: bekommt jetzt doch ein News-Panel (fasst den aktuellen
    Funktionsstand zusammen, nicht nur diese eine Version — das Modul hatte nie eines).
    Zwei neue „?": „Welche Zähler findet die Suche — und welche nicht?" (Unit-ID-Grenzen,
    RTU-Gateways, Shelly-Modbus-Aktivierung) und „Wie funktioniert 'Migration vorbereiten'
    genau?" (kompletter Drei-Schritt-Ablauf inkl. Active=false-Verhalten).
  - **`MeterHub`/`MeterHubVirtual`**: neues „?" bei den Archiv-Verdichtungsstufen (erklärt das
    Zusammenspiel von „direkt"/„nach X Monaten" mit Beispiel und der Intervall-Bedingung).
  - **`MeterHub`**: zwei weitere neue „?" bei „Bezug/Einspeisung vertauscht" (warum ein reiner
    Vorzeichenwechsel bei den Energiezählern nicht reicht, sondern die Ziele vertauscht werden)
    und bei „Zusätzliche Sammel-Variablen je Funktion" (was dabei konkret angelegt wird und
    wozu es gut ist).

## 0.24.15-beta.1 (2026-09-01)

- **Formular-Konvention (SUITE.md „Einheitliche Formular-Optik") jetzt in allen drei Modulen
  umgesetzt**, nicht nur in `MeterHubVirtual`. Auslöser: Dietmars vollständiger Abgleich aller
  Instanzformulare gegen das Verbund-Manifest.
  - **`MeterHub`**: neues News-Panel (Archiv-Verdichtung, Aggregationstyp-Fix, neue Felder
    „Zählerbezeichnung"/„Standort"), Versionsnummer im Doku-Panel, Symcon-Forum-Hinweis
    (Platzhalter-Link, siehe unten).
  - **`MeterHubDiscovery`**: Versionsnummer im Doku-Panel, Symcon-Forum-Hinweis. Kein
    News-Panel — an diesem Modul gab es zuletzt nichts Neues zu vermelden.
  - **`MeterHubVirtual`**: veraltete Versionsnummer im News-Panel (`0.24.5`) auf den aktuellen
    Stand nachgezogen, Inhalt um alles seit `0.24.5` Verschiffte ergänzt (Archiv-Verdichtung,
    Zählerbezeichnung/Standort, zwei Nachkommastellen bei „Anteil (%)").
  - `🆕`-Präfix jetzt direkt an den tatsächlichen Feld-Beschriftungen „Zählerbezeichnung" und
    „Standort" (beide Module), nicht mehr nur im News-Panel-Text.
- **„Zählerbezeichnung"/„Standort" jetzt auch im normalen `MeterHub`-Instanzformular**, nicht
  mehr nur in `MeterHubVirtual`. Identisches Muster: `InstanceName`-Feld ruft `IPS_SetName()`
  sofort per `onChange`, `Location` ist ein freies Textfeld mit Vorschlägen aus bereits
  benutzten Werten — der Vorschlagspool umfasst jetzt beide Module gemeinsam (`MeterHub` und
  `MeterHubVirtual`).
- **Symcon-Forum-Hinweis** (SUITE.md-Konvention, Punkt 4) in allen drei Modulen ergänzt.
  Bisher blockiert, weil kein echter Thread-Link existierte — Dietmar hat für einen
  Platzhalter-Link ausdrücklich grünes Licht gegeben, bis der Thread veröffentlicht ist
  („auch wenn nur eine Phantasie-URL drinnsteht"). Vor dem Store-Release durch den echten
  Link ersetzen.
- **„Anteil (%)" erlaubt jetzt bis zu zwei Nachkommastellen** (`MeterHubVirtual`), z. B. für
  eine exakte Drittel-Aufteilung — bisher nur ganze Prozent.
- **Schritt-für-Schritt-Anleitung (`MeterHubVirtual`-Doku-Panel) nachgebessert:** die neuen
  Felder „Zählerbezeichnung" und „Standort" fehlten dort bisher komplett.
- Store-Review-Fix: `library.json`s `name` trug bisher das Suffix „for IP-Symcon" — laut
  SUITE.md-Checkliste (Punkt 6) nicht zulässig, jetzt „NRG-Stack MeterHub".

## 0.24.14-beta.1 (2026-09-01)

- **Fix: „aus" bei einer Verdichtungsstufe löschte die Regel im Archiv nicht wirklich.** Dietmars
  Live-Fund: „nach so viel Monaten = aus" eingestellt, aber die Regel stand danach weiter in
  den Archiv-Einstellungen. Ursache, live an `AC_SetCompaction()` verifiziert: eine
  ausgeschaltete Stufe wurde bisher einfach übersprungen (kein Aufruf), das ließ eine schon
  vorhandene Regel unangetastet. Fix: jede Stufe bekommt jetzt IMMER einen
  `AC_SetCompaction()`-Aufruf — Verdichtungstyp `-1` löscht die Regel am jeweiligen Monats-
  Offset aktiv (bestätigt per `AC_GetCompaction()` vorher/nachher). Betrifft auch den
  rechnerischen Leerlauf-Fall (Zielauflösung nicht gröber als das Intervall), nicht nur ein
  bewusstes „aus".
- **Hauptschalter „Automatische Verdichtung aktivieren" setzt jetzt ebenfalls aktiv zurück,
  statt nur nichts mehr zu tun** (Dietmars Entscheidung, konsistent zur Einzelstufen-Regel) —
  räumt beim Ausschalten auch Regeln aus einem früheren „an"-Zustand auf. Ein Warnhinweis
  direkt am Schalter macht das jetzt ausdrücklich klar: das Ausschalten überschreibt auch von
  Hand in der Konsole gesetzte Verdichtungsregeln.

## 0.24.13-beta.1 (2026-08-31)

- **Archiv-Verdichtung: zwei eigene Panels statt einem gemeinsamen.** Dietmars Frage, ob die
  zwölf Felder statt untereinander nicht nebeneinander stehen könnten — Symcons
  Formularsprache kennt dafür kein horizontales Layout (bereits bei den PopupButtons geprüft).
  Stattdessen jetzt „🗄️ Archiv-Verdichtung: Leistung" und „🗄️ Archiv-Verdichtung: Energie"
  als zwei separat auf-/zuklappbare Panels — zeigt jeweils nur die 6 Felder der Kategorie, an
  der gerade gearbeitet wird, statt aller 12 auf einmal.

## 0.24.12-beta.1 (2026-08-31)

- **Archiv-Verdichtung jetzt getrennt für Leistung und Energie.** Dietmars Ergänzung, noch am
  selben Tag: „wir müssen zwischen Leistungswerten und Energiewerten unterscheiden. Wir
  bräuchten deshalb diese Einstellungen doppelt." Aus den sechs Feldern im Panel „🗄️ Archiv-
  Verdichtung" wurden zwölf — je ein vollständiger Satz (Ein/Aus + drei Stufen) für „⚡
  Leistung" und „🔋 Energie" —, da beide unabhängig vom Update-Takt ganz unterschiedliche
  Aufbewahrungs-Anforderungen haben können (z. B. Wirkarbeit-Zählerstände länger roh behalten
  als Momentanleistung).

## 0.24.11-beta.1 (2026-08-31)

- **Archiv-Verdichtung jetzt konfigurierbar, statt fest im Code.** Dietmars berechtigter
  Einwand zur eben gebauten Automatik: „ich bin nicht der einzigste Nutzer, andere Nutzer
  haben vielleicht andere Vorstellungen." Neues Panel „🗄️ Archiv-Verdichtung" in beiden
  Modulen: Checkbox zum kompletten Ein-/Ausschalten, dazu drei Stufen (direkt / nach X
  Monaten / nach Y Monaten) mit je einer frei wählbaren Ziel-Auflösung (1×/Minute … 1×/Jahr,
  „aus" oder „Werte löschen") — Dietmars eigene Werte (1 Min / 5 Min nach 1 Monat / 1 Std
  nach 12 Monaten) bleiben nur noch die Vorbelegung für neue Instanzen, keine feste Vorgabe
  mehr für jeden Nutzer.

## 0.24.10-beta.1 (2026-08-31)

- **Neu: automatische Archiv-Verdichtung, aus dem Update-Intervall abgeleitet.** Sepps
  Rückmeldung „bei 100 Zählern ist das eine Mordsarbeit, die immer wieder einzustellen" traf
  einen echten wunden Punkt — jede Ausgabevariable brauchte bislang von Hand eine Verdichtungs-
  Staffelung in der Konsole (~1 Minute pro Datenpunkt). Beide Module (`MeterHub` und
  `MeterHubVirtual`) setzen jetzt automatisch bei jedem „Übernehmen“/Reload eine Staffelung
  über `AC_SetCompaction()` (Dietmars bevorzugte Werte für Datenpunkte mit „richtig vielen
  Werten": direkt auf 1×/Minute, nach 1 Monat auf 1×/5 Minuten, nach 12 Monaten auf
  1×/Stunde) — abgeleitet aus dem TATSÄCHLICH bekannten Update-Intervall der Instanz
  (`Interval` bzw. `IntervalFast`/`IntervalSlow`), nicht geschätzt aus der Archiv-Historie
  (die wäre bei einer frischen Instanz leer und bei „nur Änderungen aufzeichnen" ohnehin kein
  verlässliches Maß). Jede Stufe wird nur gesetzt, wenn ihre Ziel-Auflösung tatsächlich gröber
  ist als das Roh-Intervall — sonst wäre sie Leerlauf.

## 0.24.9-beta.1 (2026-08-31)

- **Fix: Bezug/Einspeisung bekamen den falschen Archiv-Aggregationstyp.** Sepps Testerfund:
  „Bei „Bezug" ist der falsche Zähler Typ, ist Standard, muss Zähler sein." Beide Module
  (`MeterHub` und `MeterHubVirtual`) setzten für JEDE archivierte Ausgabevariable pauschal
  `AC_SetAggregationType(..., 0)` (Standard: Min/Max/Durchschnitt je Periode) — richtig für
  Momentanwerte wie Leistung, aber falsch für kumulative Wirkarbeit-Zählerstände (Bezug/
  Einspeisung), die Aggregationstyp 1 (Zähler: Delta je Periode) brauchen, sonst rechnet
  WebFront die Periodenwerte falsch. Live an Dietmars Anlage verifiziert (echte Wirkarbeit-
  Zähler stehen dort auf Typ 1, nicht geraten). `MeterHubVirtual` unterscheidet jetzt nach
  Ausgabefeld (Leistung = Standard, Bezug/Einspeisung = Zähler), `MeterHub` nach der
  Variablengruppe („energy" = Zähler). Betrifft auch bereits bestehende Instanzen automatisch
  beim nächsten „Übernehmen"/Modul-Reload, keine manuelle Migration nötig.
- Statuscode 202 („Migration nötig") live geprüft: `ApplyChanges()` und der gespeicherte
  Status laufen sauber, ein von einem Tester gemeldetes Konsolen-Popup während eines
  Mehrfach-Modul-Updates war vermutlich ein einmaliger Anzeige-Hänger, keine dauerhafte
  Blockade.

## 0.24.8-beta.1 (2026-08-31)

- **Neu: Zähler-Instanz/Gerät wählen statt drei einzelne Variablen-Picker.** Dietmars
  Rückmeldung: „mit dem Picker für Leistung und Bezug und Einspeisung bin ich überhaupt nicht
  zufrieden … man klickt sich zu Tode. Ich möchte die Zählerinstanz auswählen müssen und der
  Rest muss von alleine kommen." Neuer Weg: eine Zähler-Instanz oder ein Gerät wählen,
  „Übernehmen" — Leistung/Bezug/Einspeisung werden automatisch gefunden (zuerst über bekannte
  NRG-Stack-Idents, sonst über dieselbe Profil-Klassifizierung wie der Suchlauf) und als neue
  Zeile eingetragen. Der Ergebnistext nennt genau, was gefunden wurde („ich möchte auch genau
  dieses Ergebnis zu Gesicht bekommen") — inklusive Warnung, falls ein Gerät zwei kWh-
  Datenpunkte hat und nicht eindeutig ist, welcher Bezug bzw. Einspeisung ist. Der bisherige
  Weg (Tabellenzeile von Hand anlegen, je Spalte einzeln über den nativen Variablenpicker
  wählen) bleibt für Einzelfälle bestehen, die die automatische Suche nicht abdeckt.
- **Suchlauf-Funde direkt übernehmbar.** Dietmars Ergänzung: „wenn ich schon etwas suchen
  muss, dann möchte ich auch direkt aus dem Suchdialog etwas übernehmen." Nach „Zähler im
  System suchen" stehen die Funde jetzt zusätzlich in „Fund auswählen" bereit — auswählen,
  „Fund übernehmen" klicken, fertig, ohne separat zum Geräte-Picker wechseln zu müssen.
- Feld „Name dieser Instanz" in „Zählerbezeichnung" umbenannt.

## 0.24.7-beta.1 (2026-08-31)

- **Neu: Name der Instanz direkt im Formular änderbar.** Ich hatte zunächst behauptet, Symcon
  zeige dafür schon ein natives Feld am Kopf jeder Instanzseite — Dietmar konnte es live nicht
  finden ("ich finde nichts!"), die Behauptung war ungeprüft und falsch bzw. zumindest nicht
  in seinem Client auffindbar. Jetzt gibt es das Feld „Name dieser Instanz" direkt im
  Formular, wirkt sofort per `onChange` (`IPS_SetName()`, unabhängig von „Übernehmen" — der
  Name ist keine Modul-Property), client-unabhängig (Konsole/WebFront/App).

## 0.24.6-beta.1 (2026-08-31)

- **Fix: Hilfetexte sprachen von einem „+"-Symbol, das es so gar nicht gibt.** Dietmars
  Live-Fund: bei leerer Formel-Tabelle zeigt Symcon den Hinzufügen-Knopf als ausgeschriebenes
  „HINZUFÜGEN", nicht als kleines „+". Alle Hilfetexte (Doku-Panel, Zähler-Panel, Suchlauf-
  Ergebnistext) sprechen jetzt vom „Hinzufügen"-Knopf statt einem nicht vorhandenen Symbol.

## 0.24.5-beta.1 (2026-08-31)

- **Suchlauf schreibt nichts mehr automatisch — reine Fundstellen-Übersicht.** Dietmars
  Rückmeldung: das bisherige automatische Eintragen jedes Fundes in die Formel-Tabelle war
  „nicht wirklich intelligent" — jeder unerwünschte Fund musste einzeln mit dem Papierkorb
  entfernt werden. „Zähler suchen" zeigt die brauchbaren Kandidaten jetzt nur noch im
  Ergebnistext; aufgenommen wird bewusst über das normale „+" der Tabelle mit dem eingebauten
  Symcon-Variablenpicker (recherchiert: Symcons `Tree`-Element mit `multiAdd` böte
  Mehrfachauswahl aus dem Objektbaum, filtert dabei aber nicht nach Einheit/Modul — die
  bestehende Vorprüfung wäre verloren gegangen, deshalb dieser sicherere Weg).
- **Neu: ein Zähler lässt sich aufteilen — Spalte „Anteil (%)" statt reinem +/−.** Dietmars
  Praxisfall: eine PV-Anlage mit mehreren Baujahren bekommt die Einspeisevergütung anteilig
  nach Quotierung. 100/−100 verhalten sich wie bisher „+"/„−", jeder Wert dazwischen ist ein
  echter Teil-Anteil — dieselbe Variable darf jetzt bewusst in mehreren Instanzen mit
  unterschiedlichem Anteil stehen (z. B. 60 % Mieter A, 40 % Mieter B), Doppelzählung
  INNERHALB einer Instanz bleibt weiterhin ein Fehler. Ältere Zeilen mit „Sign" statt „Factor"
  werden weiterhin gelesen, keine Migration nötig.
- **Formel-Tabelle per Drag & Drop umsortierbar** (`changeOrder`) — rein organisatorisch, das
  Rechenergebnis ist ordnungsunabhängig.
- „Prüfung & Vorschau" zeigt bei einem Teil-Anteil jetzt zusätzlich Rohwert UND anteiligen
  Beitrag (z. B. „8.000,0 kWh → 4.800,0 kWh" bei 60 %).
- `MHUB_CreateVirtual()` (Brücke im Hauptmodul) erzeugt jetzt `Factor` statt `Sign`.

## 0.24.4-beta.1 (2026-08-31)

- **„Prüfung & Vorschau" zeigt jetzt die aktuellen Live-Werte, nicht nur die Formel-Struktur.**
  Dietmars Anregung: die Formel-Zeile je Feld (z. B. „Leistung = Hausanschluss − Wärmepumpe −
  Wallbox") nennt jetzt auch den aktuellen Messwert jedes Terms sowie das Rechenergebnis
  („… = 5.000 W − 1.200 W − 1.800 W = 2.000 W"), ohne extra „Jetzt neu berechnen" klicken zu
  müssen — dieselben Zahlen, die die nächste `Recalc()` ohnehin berechnen würde.

## 0.24.3-beta.1 (2026-08-31)

- **Neu: Feld „Standort" (Raum/Geschoss) an MeterHubVirtual-Instanzen.** Dietmars Auftrag: „alle
  möglichen Raum-/Geschossbezeichnungen zur Auswahl, aber auch manuelle Eingabe für die letzten
  Absurditäten." Bewusst GETRENNT von „Funktion" — die ist ein fester Vertrag mit dem
  Dashboard/InverterHubTile (Icon-Mapping in einem anderen Repo), ein freier Raumname hätte dort
  kein passendes Icon. „Standort" ist reines Freitext-Label ohne Vertrag: eine
  Vorschlags-Auswahl (`LocationPreset`, onChange füllt das Textfeld) zeigt alle Werte, die
  irgendeine MeterHubVirtual-Instanz bereits benutzt — wächst mit der eigenen Nutzung statt
  eine erfundene, an keiner echten Anlage passende Raumliste vorzugeben —, das Textfeld
  `Location` bleibt daneben jederzeit frei änderbar.

## 0.24.2-beta.1 (2026-08-31)

- **Textkorrektur: „InverterHubTile" aus nutzersichtbaren Texten entfernt.** Dietmars Hinweis:
  InverterHubTile wird es so nicht mehr geben (SUITE.md dokumentiert bereits den geplanten
  Wechsel zu `NRGDashboardTile`, sobald NRGDashboard veröffentlicht ist). Die Funktions-
  Beschriftung und die zugehörigen Hilfetexte nennen jetzt neutral „Dashboard" statt einen
  konkreten, sich gerade ändernden Kachel-Namen festzuschreiben. Der interne GUID-Ausschluss
  für die Zählersuche bleibt unverändert (betrifft echten, heute noch existierenden Code, kein
  nutzersichtbarer Text).

## 0.24.1-beta.1 (2026-08-31)

- **Fix: Papierkorb-/Zahnrad-Symbol am Zeilenende der Formel-Liste unerreichbar.** Dietmars
  Live-Fund direkt nach 0.24.0: die drei `SelectVariable`-Spalten (Leistung/Bezug/Einspeisung)
  standen auf Breite `"auto"` und zeigten den vollen Objektpfad der gewählten Variable — bei
  tief verschachtelten Variablen wurde die Zeile dadurch beliebig breit, die Aktions-Symbole
  rutschten aus dem sichtbaren Formularbereich, ohne dass sich dorthin scrollen ließ. Feste
  Breite (220px je Spalte) statt „auto" behoben.

## 0.24.0-beta.1 (2026-08-31)

- **MeterHubVirtual grundlegend neu: flache Formel statt Baum.** Dietmars Einwand traf den
  Kern: „für mein Verständnis ist die Instanz die oberste Ebene, und die angeklickten Zähler
  sind die untergeordneten Zähler, die den virtuellen Zähler bilden — wenn Du dann noch eine
  Rechenoperation vor jeder Zeile zulässt, ist das Problem doch schon gelöst?" War es. Das
  bisherige Baum-Modell (Zeilen mit Kürzel und „hängt hinter" auf andere Zeilen, drei
  Verdrahtungs-Muster, eine Sammelzeile-Zwischenzeile für reine Summen) drückte genau
  dasselbe nur komplizierter aus. Neu: die Instanz selbst ist die oberste Ebene, jede Zeile
  ein Term mit Vorzeichen (+/−), Ergebnis = Summe „+" minus Summe „−", getrennt für Leistung/
  Bezug/Einspeisung. Entfällt komplett: Kürzel, „hängt hinter", Sammelzeilen, der
  fehleranfällige Schnellweg „Ausgewählte zusammenfassen / abziehen" aus 0.23.7 (dessen
  Auswahl-Logik Dietmar live als fehlerhaft meldete — mit dem flachen Modell erübrigt sich
  die ganze Funktion, statt sie zu debuggen). „Funktion" (für Dashboard/InverterHubTile) ist
  jetzt ein Instanz-Feld statt ein Zeilen-Feld, da eine Instanz nur noch EIN Ergebnis liefert.
  Mehrstufige Verschachtelung (Zwischenwert, von dem wieder etwas abgezogen wird) geht über
  mehrere verkettete Instanzen statt innerhalb einer einzigen — passend zum Rest des NRG-
  Stacks („eine Instanz = eine Zahl").
- **Migrationssicherung für bereits verdrahtete Instanzen.** Altes Datenformat (erkennbar an
  „Kürzel"/„hängt hinter" in den gespeicherten Zeilen) wird beim ersten `ApplyChanges()` NICHT
  blind übernommen — das hätte auf einer live genutzten Anlage sofort jede nur lose gefundene
  Kandidatenzeile mitsummiert, ein still falscher Wert. Stattdessen: Status „Migration nötig",
  vorhandene Ausgaben bleiben unangetastet, das Formular zeigt die alten Zeilen als
  ungespeicherten Vorschlag (Vorzeichen „+"), bis „Übernehmen" bestätigt.
- `MHUB_CreateVirtual()` (Schnellweg im Hauptmodul) erzeugt jetzt direkt das neue flache
  Format — Rolle „parent" (eigener Zähler „+", Partner „−") und „sibling" (alle „+").
- **Neu: Kreuz-Instanz-Prüfung im Suchlauf.** Ein Datenpunkt, der schon in einer ANDEREN
  MeterHubVirtual-Instanz als Term steckt, wird standardmäßig nicht mehr erneut vorgeschlagen
  (bisher prüfte das nur `Validate()` innerhalb einer Instanz). Umgekehrt per Schalter
  einsehbar: „nur schon verwendete Datenpunkte zeigen", zum gezielten Nachschauen, wo ein
  bestimmter Zähler sonst noch eingeht (Dietmars Anregung).
- Prüfstand `.tools/test-virtual.php` komplett neu für das flache Modell, inklusive
  Migrationstest und Kreuz-Instanz-Prüfung.

## 0.23.7-beta.1 (2026-08-31)

- **Neuer Schnellweg zum Verdrahten: „Ausgewählte zusammenfassen / abziehen".** Dietmars
  eigentlicher Einwand ging über die Doku hinaus: „warum muss ich den virtuellen Zähler AUCH
  noch anlegen, das mache ich doch schon mit der Instanz?" Antwort: Der Einklick-Weg
  (`MHUB_CreateVirtual()` im Hauptmodul) existierte schon, aber nur für andere MeterHub-
  Instanzen — für beliebige Systemvariablen (Shelly-Plugs, Zigbee-Steckdosen …) fehlte er.
  Neue Spalte „Auswählen" in der Verdrahtungs-Liste + Knopf: Zeilen ankreuzen, Ziel wählen,
  klicken — verdrahtet alles in einem Schritt. Zwei Fälle: **neue Sammelzeile** (Zähler
  einfach zusammenzählen, Name automatisch aus den Gerätenamen abgeleitet) oder **von einer
  vorhandenen Zeile abziehen** (Dietmars Ergänzung: „man mag auch den einen oder anderen
  Zähler von einem anderen abziehen" — z. B. Wärmepumpe + Wallbox nachträglich vom
  Hausanschluss abziehen, ohne jede Zeile einzeln umzustellen). Details/Herleitung in
  CLAUDE.md.
- Prüfstand `.tools/test-virtual.php` um 17 weitere Prüfungen ergänzt (74 insgesamt).

## 0.23.6-beta.1 (2026-08-31)

- **Verdrahtungs-Hilfe nachgeschärft: Schritt-für-Schritt direkt im Verdrahtungs-Panel +
  „?"-Hilfe an den zwei kritischen Stellen.** Dietmars Rückmeldung: die Doku-Panel-Erklärung
  von eben genügte noch nicht — das Verfahren musste am Ort der Handlung stehen, nicht nur
  im entfernten, eingeklappten Nachschlage-Panel. Neu: eine nummerierte 6-Schritte-Anleitung
  direkt über der Verdrahtungs-Tabelle, dazu zwei `PopupButton`-„?"-Hilfen (SUITE.md „Feld-
  Hilfestellung" — Symcon kennt keinen Mouseover-Tooltip) — eine für „hängt hinter" (öffnet
  ein Popup mit allen drei Verdrahtungs-Mustern samt Beispiel), eine für „Kürzel"
  (Historie-Warnung bei nachträglicher Änderung). Erste Umsetzung dieser Verbund-Konvention
  in diesem Repo. Prüfstand `.tools/test-virtual.php` um 7 weitere Prüfungen ergänzt (57
  insgesamt).

## 0.23.5-beta.1 (2026-08-31)

- **Fix: ein Zähler ohne untergeordnete Zähler bekam bislang keine eigene Ausgabe, selbst
  mit eigenem Zähler.** Live-Fund (Sepp/Dietmar, Praxistest): eine einzelne Steckdose, nur
  zur Funktionszuordnung verdrahtet, blieb komplett unsichtbar — `Recalc()` meldete „keine
  Ausgabe vorhanden“. `OutputDefs()` iterierte bislang nur über Knoten MIT Kindern; ein
  kinderloser Knoten mit eigenem Zähler wurde nie besucht. Betraf rückwirkend auch jedes
  Kind einer bestehenden Verdrahtung (z. B. „Wärmepumpe“/„Wallbox“ unter „Hausanschluss“
  hatten nie eine eigene Ausgabe) — deren Funktionszuordnung war dadurch über
  `MHUBV_GetFunctions()` unsichtbar, ganz ohne Fehlermeldung. Ein kinderloser Knoten mit
  eigenem Zähler bekommt jetzt eine reine Durchreichungs-Ausgabe. Details/Herleitung in
  CLAUDE.md.
- **Formular-Konvention des Verbunds erstmals in MeterHubVirtual umgesetzt** (News-Panel +
  Doku-Panel mit Versionsnummer, InverterHub-Referenzmuster). Das Doku-Panel wurde komplett
  neu geschrieben: alle drei Verdrahtungs-Muster (reiner Sammelknoten / Zähler mit Kindern /
  kinderloser Zähler) mit Beispiel, plus eine Schritt-für-Schritt-Anleitung, die es vorher
  nicht gab — Dietmars ausdrücklicher Auftrag, nachdem das Verdrahtungsverfahren im
  Praxistest für Verwirrung sorgte.
- Prüfstand `.tools/test-virtual.php` von 42 auf 51 Prüfungen erweitert (neues
  Durchreichungs-Verhalten, angepasstes Sicherheitsnetz, News-/Doku-Panel-Inhalt).

## 0.23.4-beta.1 (2026-08-31)

- **Fix: fehlende Parametertypen bei `MHUB_CreateVirtual`, `MHUB_OnChangeMeter` und
  `MHUBV_ScanMeters` ergänzt** (Fund OCPPHub, per Systemlog-Durchsicht bei Dietmar).
  Reine Typdeklarationen an bereits vorhandenen Parametern — Parameteranzahl
  unverändert, kein Bruch der veröffentlichten `PREFIX_`-Signaturen (siehe
  "PREFIX_-Funktionen sind fixer Arität"). `ScanMeters()` bleibt bewusst nullable
  (`?int`/`?string`/`?bool`), weil ein Direktaufruf ohne Argumente (Skript/Konsole,
  „gespeicherte Filter") weiter unterstützt wird.
- **Geprüft, nicht bei uns:** die von OCPPHub gemeldete
  „InstanceInterface is not available"-Warnung beim System-Boot betrifft MeterHub
  strukturell nicht — der Modbus-TCP-Transport (`MHUB_ModbusTcpClient`) nutzt
  ausschließlich rohe `fsockopen()`-Sockets, keine IPS-I/O-Instanz-Kette
  (`SendDataToParent`/`HasActiveParent`), an der eine solche Warnung entstehen
  könnte — bestätigt einen reinen Kernel-Boot-Timing-Fall, kein Modul-Bug.

## 0.23.3-beta.1 (2026-08-30)

- **Fix: MeterHubDiscoverys Konfigurationsformular stürzte ab, sobald MigrationsHub
  installiert ist** (`ArgumentCountError` bei `MIGHUB_FindLegacyCandidates`, live bei
  Dietmar). Zwei Ursachen, beide behoben: MigrationsHub erwartet inzwischen ein 5.
  Argument (`$excludeInstanceID` — PREFIX_-Wrapper honorieren PHP-Defaults nicht, alle
  Argumente Pflicht), und wir übergaben als Dispatch-Ziel die eigene Discovery-Instanz
  statt einer MigrationsHub-Instanz (nie aufgefallen, weil der Pfad ohne installiertes
  MigrationsHub nie lief). Zusätzlich abgesichert: der Aufruf steht jetzt in
  `try/catch` — ein künftiger Vertragsbruch des Partnermoduls degradiert zu „kein
  Kandidat" statt das Formular zu töten (`@` hält Fatals nicht auf). `PrepareMigration()`
  legt die MigrationsHub-Instanz jetzt vor der Kandidatensuche an (Henne-Ei-Auflösung),
  und die eigene Zielinstanz wird als `excludeInstanceID` übergeben (kein „migriere von
  deiner eigenen frisch angelegten Instanz" mehr). Prüfstand von 20 auf 27 Prüfungen
  erweitert (u. a. Dispatch-Ziel, 5. Argument, Selbstausschluss, kein Instanz-Anlegen
  im Formularaufbau).

## 0.23.2-beta.1 (2026-08-30)

- **Fix: Die Zählersuche (MeterHubVirtual) erkennt jetzt auch Variablen mit den neuen
  „Darstellungen" (IPS 7+/8), nicht nur klassische Profile.** Testerfund von Sepp: KNX-Watt-
  Variablen und Shellys mit Darstellung statt Profil fielen komplett durch den Suchlauf,
  nur Alt-Profil-Variablen (z. B. KNX-kWh) wurden gefunden. `UnitOf()` liest jetzt zusätzlich
  `VariableCustomPresentation`/`VariablePresentation` — sowohl die Form mit direktem `SUFFIX`
  (" W", " kWh") als auch die Form, die intern ein Alt-Profil referenziert (`PROFILE`-Feld);
  beide Formen live verifiziert, ältere IPS-Kerne ohne diese Felder bleiben abgesichert.
  Drei neue Prüffälle in `.tools/test-virtual.php` (6c).
- **Fix: Der automatische Lastgang-Nachtrag protokollierte alle 15 Minuten einen
  vermeintlichen Fehler.** `trigger_error` im Timer-Kontext erscheint im Symcon-Log als
  ❌-Eintrag des TimerPools — auch beim völlig normalen „0 neue Werte"-Lauf. Jetzt: echte
  Fehler (❌-Meldungen) weiter im Log, der Normalfall nur noch im Instanz-Debug.

## 0.23.1-beta.1 (2026-08-28)

- **StrukturHub in die NRG-Stack-Ausschlussliste der Zählersuche aufgenommen**
  (`MHUBV::EXCLUDED_NRG_STACK_MODULES`, GUID von der StrukturHub-Sitzung gemeldet) —
  Verbund-Pflegeregel: jedes neue Verbund-Mitglied eintragen, sonst tauchen dessen
  Variablen als vermeintliche „Fremdzähler" im Suchlauf von MeterHubVirtual auf.

## 0.23.0-beta.1 (2026-08-27)

- **Neu: Zeitstempel des letzten archivierten Lastgang-Datensatzes sichtbar.** Dietmars
  Wunsch: anzeigen, wie aktuell der Inexogy-Lastgang im Archiv tatsächlich ist. Im eigenen
  Formular als neues Label „📊 Archiv-Lastgang vollständig bis … Uhr" (nach jedem Nachtrag,
  manuell oder automatisch, aktualisiert). Zusätzlich als neues Feld `archiveWatermarkTs`
  in `MHUB_GetFunctions()` (`contractVersion` 1.1 → 1.2, additiv) — nur bei
  `latency: 'delayed'` gesetzt, sonst `null`, damit z. B. das Dashboard denselben Stand im
  Strompreis-Diagramm anzeigen kann, das auf denselben archivierten Energiezählern
  aufbaut. Sechs neue Prüffälle in `.tools/test-auto-backfill.php`.

## 0.22.9-beta.1 (2026-08-27)

- **Automatischer Lastgang-Nachtrag: fester Rückblick durch Nachsehen ersetzt.** Dietmars
  Rückfrage: "wie wäre es nachzusehen, welche Daten schon da sind, und nur das Notwendige
  zu holen?" Neue `ComputeAutoBackfillRange()` ermittelt je Zielvariable den tatsächlich
  neuesten archivierten Zeitstempel (`AC_GetLoggedValues(..., Limit=1)`, laut Doku
  absteigend sortiert — kostet nur einen Datensatz, kein Historien-Scan) und holt nur den
  seither fehlenden Zeitraum, mit 30 Minuten Sicherheitsabstand statt eines festen
  Tage-Fensters. `InexogyAutoBackfillDays` wirkt jetzt nur noch als Obergrenze für den
  Fall einer größeren Lücke (erster Lauf, längerer Ausfall), nicht mehr als Fenstergröße
  bei jedem Takt — deshalb Default wieder auf 3 Tage angehoben (Maximum 30), ohne dass das
  die laufenden Kosten erhöht. `DoBackfillInexogyArchive()` intern auf exakte Zeitstempel
  statt Tage-Anzahl umgestellt (keine Tages-Rundung mehr, die den Vorteil der
  Minuten-genauen Ermittlung zunichtegemacht hätte) — betrifft nur die private
  Implementierung, nicht die veröffentlichte `MHUB_BackfillInexogyArchive($id)`-Signatur.
  Neue Prüffälle in `.tools/test-auto-backfill.php` (8a–8f), vollständig ohne echten
  API-Zugriff.

## 0.22.8-beta.1 (2026-08-27)

- **Automatischer Lastgang-Nachtrag überarbeitet: wiederkehrend statt einmal täglich.**
  Auf Dietmars Rückfrage ("warum nicht alle 15 Min. die letzten 30 Tage, es passiert ja
  ohnehin nichts?") noch am selben Tag umgebaut. Der 15-Minuten-Takt war richtig gedacht
  (Inexogy liefert ohnehin nur 15-Minuten-Werte) — ein 30-Tage-Rückblick bei jedem Takt
  wäre aber unnötige Last gewesen (~276.000 Zeilen täglich von Inexogy geholt und lokal
  gegengeprüft, nur um praktisch immer "kenn ich schon" zu finden). Jetzt: „alle …
  Minuten" (`NumberSpinner`, Default 15, Minimum 15 statt fixer Uhrzeit), Rückblick klein
  gehalten (Default 1 Tag, Maximum 7 statt 30). Details/Herleitung in CLAUDE.md.

## 0.22.7-beta.1 (2026-08-27)

- **Fix: `PowerInvert` galt bisher nur für `power_total`, nicht für die Energiezähler.**
  Live an Dietmars Inexogy-Instanz aufgefallen (Dashboard-Team, 21.08.2026): eine vertauschte
  Anschlussrichtung ließ sich für die Leistung korrigieren, die Energiezähler
  (`energy_import`/`energy_export`, auch Tarif-/Phasen-Varianten) blieben aber vertauscht.
  `EnergyIdentForInvert()` leitet den Schreibzugriff jetzt bei aktivem `PowerInvert` auf die
  jeweils andere Richtung um — nur wenn diese beim Treiber existiert, sonst bleibt der Wert
  auf seinem ursprünglichen Ident (kein Datenverlust bei Zählern mit nur einer Richtung, z. B.
  Phoenix EEM). Betraf alle Treiber mit `energy_import`/`energy_export`-Paar, nicht nur
  Inexogy. Neuer Prüfstand `.tools/test-powerinvert.php`.
- **Neu: Lastgang-Nachtrag lässt sich jetzt automatisch täglich ausführen**, statt nur über
  den Formular-Knopf. Neue Felder „Lastgang automatisch täglich nachtragen" (Checkbox),
  Uhrzeit (`SelectTime`) und Tage-Rückstand je automatischem Lauf. Läuft über die ohnehin
  aktive `SlowTimer` mit, kein neuer Timer/Event nötig. Neuer Prüfstand
  `.tools/test-auto-backfill.php` (Auslöse-Logik, ohne Netzwerkzugriff).

## 0.22.6-beta.1 (2026-08-20)

- **Neu in allen drei Modulen: Formular-Knopf „🔄 Übernehmen erzwingen (ohne Formularänderung)"**
  (EMS-Angebot, keine Pflicht-Konvention, Referenz `EMS_ApplyChanges`/0.22.4) — ruft direkt
  `IPS_ApplyChanges($id)` auf, mit Bestätigungsdialog (`'confirm'`, gegen die offizielle
  Button-Dokumentation verifiziert) und Rückmeldung per `echo` (Muster 1 der „Sichtbare
  Rückmeldung"-Konvention). Praktisch nach jedem Modul-Update über die Modulverwaltung, wenn
  eine Instanz die neue Version übernehmen soll, ohne dass sich an ihrer Konfiguration etwas
  geändert hat.

## 0.22.5-beta.1 (2026-08-20)

- **Alle Formular-Buttons gegen die neue, verbindliche Verbund-Konvention „Sichtbare
  Rückmeldung bei jeder Aktion" (SUITE.md, 20.08.2026) durchgeprüft** — jeden Button
  angeklickt-gedacht: sieht man ohne Formular-Neuöffnen, dass etwas passiert ist? Sechs von
  neun Buttons hatten das bereits (InexogyLogin, BackfillInexogyArchive, CreateVirtual,
  PrepareMigration, AbortScan, ScanMeters — alle mit benanntem Ergebnis-Label). Drei Lücken
  gefunden und geschlossen:
  - **MeterHub „Verbindung testen / Daten sofort lesen"** rief bisher nur still
    `ReadFast()`/`ReadSlow()` auf (kein Ergebnistext, und beide brechen bei `Active=false`
    zusätzlich unbemerkt ab — der Knopf tat bei einer neu angelegten, noch inaktiven Instanz
    also buchstäblich nichts). Neue Methode `MHUB_TestConnection($id)` bündelt beide, bewusst
    OHNE den `Active`-Guard (ein manueller Test soll gerade auch vor dem Aktivieren
    funktionieren), `onClick` jetzt `echo MHUB_TestConnection($id);` (Muster 1 — Ergebnis-Popup).
  - **MeterHubVirtual „Jetzt neu berechnen"** rief `Recalc()` still auf. `Recalc()` gibt jetzt
    einen Ergebnistext zurück (Anzahl aktualisierter Ausgaben), `onClick` jetzt
    `echo MHUBV_Recalc($id);`. Timer- und interner Aufruf verwerfen den Rückgabewert einfach,
    kein Verhaltensunterschied dort.
  - **MeterHubDiscovery „Netzwerk durchsuchen"** zeigte beim Validierungsfehler (Start-/End-IP
    leer) nur einen Status-Code (104), keinen Text im offenen Formular. Jetzt zusätzlich
    `UpdateFormField('ScanSummary', …)` — bewusst ohne `ReloadForm()` an dieser Stelle, das
    würde gerade erst getippte, noch nicht übernommene Werte in genau den Feldern verwerfen,
    die der Nutzer korrigieren soll.

## 0.22.4-beta.1 (2026-08-20)

- **MeterHubDiscovery: `ScanSummaryLine()` zusätzlich per `UpdateFormField()` aktualisiert**
  (SUITE.md-Stolperfalle 12, EMS-Fund an Dietmars Live-Anlage: ein Formular-Button aktualisiert
  ein bereits offenes Formular nicht automatisch, nur beim ersten Aufbau berechnete Labels
  frieren sonst ein). Bei uns bestand dieser Bug real nicht — `Discover()` ruft am Ende bereits
  `ReloadForm()` auf (ursprünglich für BtnScan/BtnAbort), das laut SUITE.md von InverterHub live
  als gleichwertige, aber teurere Alternative bestätigt ist. `UpdateFormField()` zusätzlich
  ergänzt: gezielter/billiger, und macht die Kopfzeile unabhängig davon, ob `ReloadForm()` an
  dieser Stelle je entfällt.

## 0.22.3-beta.1 (2026-08-20)

- **MeterHubDiscovery: einheitliche Status-Kopfzeile nach Verbund-Konvention** (SUITE.md,
  „Einheitliche Verbund-Status-Kopfzeile", 20.08.2026, Referenz: EMS'
  `getDiscoverySummaryLine()`). Direkt unter dem Suche-Button steht jetzt eine Zeile
  „✅/⚠️/ℹ️ N Zähler gefunden (zuletzt HH:MM:SS Uhr)." statt eines nur transienten
  Fortschrittsbalken-Textes, der beim erneuten Öffnen des Formulars wieder verschwand. Neues
  Attribut `LastScanTs` (Zeitstempel des letzten Suchlaufs, auch bei Abbruch gesetzt).
  `.tools/test-discovery-migration.php`s IPSModule-Stub um `RegisterAttributeInteger`/
  `ReadAttributeInteger`/`WriteAttributeInteger` ergänzt (bisher nur String-Varianten
  vorhanden).

## 0.22.2-beta.1 (2026-08-20)

- **README-Badge-Zeile nach Verbund-Konvention (EMS/SUITE.md, „README-Badges", 18.08.2026).**
  Symcon | Modul-Version | Symcon-Version | Lizenz | Check-Style-CI | PayPal, direkt unter der
  H1-Überschrift, wie in `EMS/README.md` als Referenz vorgegeben. Der Lizenz-Badge nutzt einen
  eigenen shields.io-Text (`License-PolyForm_Noncommercial_1.0.0-lightgrey`), da PolyForm kein
  vorgefertigtes Preset hat.
- **Neu: `.github/workflows/check-style.yml`** (`php -l` über alle PHP-Dateien bei Push/PR) —
  gab es bisher nicht, war also Voraussetzung für einen echten (nicht vorgetäuschten)
  Check-Style-Badge. Identisch zur Referenzumsetzung in `EMS/.github/workflows/check-style.yml`.

## 0.22.1-beta.1 (2026-08-10)

- **Lastgang-Nachtrag jetzt für mehrmonatige Rückstände geeignet** (Dietmars Anschlussfrage:
  „ab 01.01.2026" nachtragen — bislang auf 30 Tage begrenzt). `MHUB_BackfillInexogyArchive()`
  verarbeitet den angeforderten Zeitraum jetzt in 30-Tage-Blöcken statt in einem Rutsch: hält
  jede `/readings`-Anfrage handhabbar und bleibt sicher unter dem dokumentierten 10.000er-Limit
  von `AC_GetLoggedValues()` je Aufruf, das bei einem einzigen Abruf über mehrere Monate den
  Dopplungsschutz unbemerkt hätte lückenhaft werden lassen können. „Tage rückwirkend"-Obergrenze
  von 30 auf 730 angehoben. `AC_ReAggregateVariable()` läuft jetzt einmal je Variable am Ende
  statt je Block (unnötige wiederholte Last vermieden). Blöcke ohne Inexogy-Daten (z. B. vor
  Kontoeröffnung) werden gemeldet, nicht stillschweigend übersprungen.

## 0.22.0-beta.1 (2026-08-10)

- **Neu: Inexogy-Lastgang rückwirkend ins Archiv nachtragen** (`MHUB_BackfillInexogyArchive($id)`,
  neuer Knopf im Formular). Dietmars Anlass: Inexogy ist sein Abrechnungszähler, der laufende
  Abfragetakt reicht nicht, um eine Rechnung im Detail zu kontrollieren.
  Live gegengeprüft, bevor gebaut wurde (nicht angenommen): die Werte aus `/readings` sind
  kumulative Zählerstände wie `/last_reading`, keine Intervalldeltas — die Differenz zweier
  Nachbarwerte reproduziert exakt das separat gemeldete `power`-Feld derselben Antwort. Werden
  also als normale archivierte Zählerstände eingetragen (`AC_AddLoggedValues`), kein
  Delta-Rechnen nötig. Trägt `Wirkarbeit Bezug`/`Wirkarbeit Abgabe`/`Wirkleistung gesamt` nach,
  einstellbarer Zeitraum (Tage rückwirkend, Default 7). Bereits archivierte Zeitpunkte werden
  vorab per `AC_GetLoggedValues` ermittelt und übersprungen — ob `AC_AddLoggedValues` bei einer
  Zeitstempel-Kollision überschreibt oder dupliziert, ist nicht dokumentiert, und bei
  Abrechnungsdaten darf nichts doppelt gezählt werden. Nach jedem Nachtrag `AC_ReAggregateVariable`
  (laut Doku sonst veraltete Tages-/Monatssummen).
- Vorstufe dazu (0.21.4/0.21.3/0.21.2): `resolution=15min` war der falsche API-Wert (Server
  verlangt `fifteen_minutes`, siehe `pydiscovergy/const.py`-Enum), gefunden über die
  `getLastError()`-Diagnose statt durch Raten.

## 0.21.4-beta.1 (2026-08-10)

- **Fix: `resolution=15min` war der falsche Parameterwert für `/readings`.** Der Live-Fehler
  war dank 0.21.3s `getLastError()`-Ausgabe sofort sichtbar: `HTTP 400 – Invalid value for
  parameter resolution: 15min`. In der Referenzquelle (`pydiscovergy/const.py`,
  `Resolution`-Enum) direkt nachgesehen statt weiter zu raten — gültige Werte sind `raw`,
  `three_minutes`, `fifteen_minutes`, `one_hour`, `one_day`, `one_week`, `one_month`,
  `one_year`. `getReadings()`-Default und `DiagnoseInexogyReadings()` auf `fifteen_minutes`
  korrigiert.

## 0.21.3-beta.1 (2026-08-10)

- **`DiagnoseInexogyReadings()` nachgebessert**, nachdem der erste Live-Lauf `count=0` ohne
  erkennbaren Grund meldete: `getLastError()` (aus der Login-Diagnose 0.20.3 bereits vorhanden)
  jetzt mit ausgegeben, damit ein HTTP-Fehler von einer echt leeren Antwort unterscheidbar
  ist. Zeitfenster von 6 auf 48 Stunden geweitet, falls die API erst mit Verzögerung berichtet
  oder ein schmales Fenster selbst schon leer/fehlerhaft beantwortet.

## 0.21.2-beta.1 (2026-08-04)

- **Vorarbeit für Lastgang-Nachtrag ins Archiv (Inexogy).** Auslöser: Dietmars Abrechnungszähler
  läuft über Inexogy, `/last_reading` liefert aber nur den aktuellen Momentanwert, keinen
  Lastgang zur Rechnungskontrolle. Recherchiert (zwei unabhängige Open-Source-Clients
  gegengeprüft, PHP `andig/discovergy` und Python `jpbede/pydiscovergy`, da die offizielle Doku
  unter api.inexogy.com/docs den Endpunkt nicht dokumentiert): Der Lastgang ist über die
  bestehende OAuth1-Verbindung ohne Web-Portal-Umweg erreichbar — `GET /public/v1/readings`
  (Parameter `meterId`, `from`/`to` in Millisekunden, `resolution` z. B. `15min`), signiert wie
  der bereits funktionierende `/last_reading`-Aufruf.
  Neu: `MHUB_InexogyClient::getReadings()` (Client-Methode) und `MHUB_DiagnoseInexogyReadings($id)`
  (Diagnosefunktion, meldet die letzten Lastgang-Einträge samt Rohwerten ins Systemprotokoll —
  ändert nichts an Formular/Konfiguration). **Bewusst noch kein Archiv-Nachtrag gebaut:** ob
  `/readings` kumulative Zählerstände liefert (wie `/last_reading`) oder Intervalldeltas, ist
  noch nicht live verifiziert — entscheidet aber, wie `AC_AddLoggedValues()` rechnen muss.
  Auch `AC_AddLoggedValues($InstanceID, $VariableID, [['TimeStamp'=>…, 'Value'=>…], …])` gegen
  die offizielle Doku geprüft (inkl. Hinweis: danach `AC_ReAggregateVariable()` nötig, sonst
  bleiben aggregierte Werte veraltet).

## 0.21.1-beta.1 (2026-07-29)

- **Neuer Verbund-Vertrag `MHUB_GetIdentMapping($id, string $foreignModuleGUID, array
  $foreignIdents): array`** — für MigrationsHubs zentrale Alt-Instanz-Übernahme, gemeinsam mit
  MigrationsHub/ChargerHub/InverterHub abgestimmt. Auslöser: MigrationsHub reparentete Alt-
  Variablen bisher „blind" per Preflight-Sonde (Wegwerf-Variable anhängen, prüfen ob sie eine
  `ApplyChanges()` übersteht), weil `PruneForeignObjects()` — bei uns wie bei anderen Hub-
  Modulen — jede Kind-Variable mit unbekanntem Ident sofort löscht. Reine Auskunftsfunktion:
  MeterHub reparentet/benennt selbst nichts um, das bleibt komplett bei MigrationsHub
  (inkl. deren „Zuordnung prüfen"-Schritt mit Nutzer-Bestätigung vor der Ausführung).
  `$foreignIdents` (die tatsächlich vorhandenen Idents der Alt-Instanz) sind nötig, weil
  manche Alt-Module dasselbe Feld je nach Firmware/Version unterschiedlich benennen — eine
  Zuordnung allein nach Modul-GUID wäre nicht eindeutig. Erster echter Eintrag: das alte
  `Discovergy_Smartmeter`-Modul (elueckel), Idents live an Dietmars vorheriger Installation
  abgelesen (`energy`→`energy_import`, `energyout`→`energy_export`, `power`→`power_total`,
  `phase1-3`→`p_l1-3`, `voltage1-3`→`u_l1-3_n`). Neuer Prüfstand
  `.tools/test-ident-mapping.php` (9 Prüfungen).

## 0.21.0-beta.1 (2026-07-29)

- **MeterHubDiscovery erkennt Alt-Instanzen anderer Module an derselben IP/Unit-ID und
  bereitet die Übernahme über MigrationsHub vor.** Dietmars Wunsch: Migration soll Teil des
  normalen Geräte-Scans werden statt separates Werkzeug — Zähler finden und Historie
  übernehmen soll für jeden Hersteller ohne manuellen Eingriff funktionieren. Mit
  MigrationsHub abgestimmt (deren neue `MIGHUB_FindLegacyCandidates()`/
  `MIGHUB_PrefillMigration()`, optionale Kopplung hinter `function_exists()`, kein
  Pflicht-Partnermodul):
  - Fund-Liste zeigt eine neue Spalte „Alt-Instanz gefunden", wenn MigrationsHub eine
    passende Alt-Instanz (Fremdmodul, gleiche IP+Unit-ID) kennt.
  - Bei einem Treffer kommt die neu erstellte MeterHub-Instanz automatisch mit
    „Kommunikation aktiv = AUS" — verhindert von vornherein die überlappende Historie, die
    der Doku-Hinweis aus 0.20.10 bisher nur beschrieb.
  - Neuer Knopf „Migration vorbereiten": verknüpft die erste erstellte Zielinstanz mit
    passender Alt-Instanz in MigrationsHub (legt bei Bedarf eine Instanz an, wiederverwendet
    eine vorhandene) und springt per `OpenObjectButton` dorthin. Bewusst nur EIN Treffer je
    Klick — Simulieren/Bestätigen/Ausführen bleiben in MigrationsHub bewusst manuelle
    Schritte (Sicherheitskette bei einem destruktiven Vorgang), ein zweiter Aufruf vor
    Abschluss würde die noch unbestätigte Zuordnung überschreiben.
  - `Configurator`-Formularelement unterstützt laut offizieller Doku keine interaktiven
    Spalten/Buttons je Zeile — deshalb eigener Button unterhalb der Liste statt eines
    Felds direkt in der Zeile.
  - Neuer Prüfstand `.tools/test-discovery-migration.php` (20 Prüfungen): Verhalten mit und
    ohne installiertes MigrationsHub, Formular-Felder (legacy-Spalte, `Active`-Override),
    Ende-zu-Ende-Ablauf von `PrepareMigration()` inkl. Wiederverwendung der
    MigrationsHub-Instanz bei mehreren Aufrufen.

## 0.20.12-beta.1 (2026-07-27)

- **Klarstellender Hinweis vor der Zählertyp-Auswahl** — Verbund-Erkenntnis (EMS,
  GoodWe-Netzmesspunkt-Formular 27.07.2026): ein implizit vorausgewählter Hersteller kann
  leicht als „der" Weg statt als bloß einer von mehreren gelesen werden. Betraf MeterHub
  konkret: der Property-Standardwert `Meter` ist `siemens_pac2200` — wer eine Instanz von Hand
  anlegt (statt über `MeterHubDiscovery`, das den echten Zählertyp korrekt setzt), sah bisher
  ein Formular mit „Siemens SENTRON PAC2200" bereits ausgewählt, ohne erklärenden Hinweis
  außerhalb des standardmäßig eingeklappten Doku-Panels. Neues, immer sichtbares Label direkt
  über der Auswahl: macht deutlich, dass die Vorauswahl nur ein Platzhalter ist (19
  gleichwertige Optionen), und weist auf die Inexogy-Cloud-Alternative für Nutzer ohne
  eigenen Modbus-Zähler hin. Reine Formularänderung, kein Verhalten geändert.

## 0.20.11-beta.1 (2026-07-25)

- **Fix: `MeterHubVirtual` löschte bei einer fehlerhaften oder flachen Verdrahtung ALLE
  vorhandenen Ausgabevariablen auf einen Schlag**, statt nur die einer tatsächlich geänderten
  Zeile — genau der Mechanismus, der am 25.07.2026 #16933 zerlegt hat (eine einzelne
  Zeilen-Entfernung wischte die komplette Instanz leer, weil die Verdrahtung danach flach war
  und `OutputDefs()` nichts mehr lieferte). Proaktiv aufgegriffen im Zuge des neuen
  Verbund-Zielbilds „Zuverlässigkeit ohne KI-Krücke" (SUITE.md) — der Fix war schon während des
  Vorfalls entworfen, aber wegen der Tragweite zurückgestellt.
  - `RegisterVariables()` fasst jetzt **nichts mehr an**, solange `Validate()` auch nur einen
    Fehler meldet — weder Löschung noch Neuanlage. Vorher lief die Löschrunde im Fehlerfall
    trotzdem durch (mit einer leeren „gültig"-Menge), was jeden Fehlerzustand potenziell zur
    Komplettlöschung machte.
  - `Validate()` erkennt jetzt zusätzlich den Fall „Verdrahtung ergibt keine einzige
    Summe-/Rest-Ausgabe mehr" (kein Knoten hat mehr Kinder) als eigenen, klartextlichen Fehler
    — aber nur, wenn die Instanz bereits Ausgaben hat (`HasExistingOutputs()`); eine
    brandneue, nie verdrahtete Instanz wird dadurch nicht blockiert.
  - `.tools/test-virtual.php` Block 10 stellt den realen Vorfall nach (Verdrahtung flach
    machen → Ausgaben bleiben erhalten, Fehlermeldung erklärt warum), prüft zusätzlich den
    allgemeinen Fall (unabhängiger Fehler bei sonst intakter Verdrahtung schützt ebenso) und
    die Gegenprobe (nach Reparatur funktioniert alles wieder normal) — 8 neue Prüfungen.

## 0.20.10-beta.1 (2026-07-25)

- **Fix: `MeterHubDiscovery` registrierte `ScanAbort` bei jedem `ApplyChanges()` erneut**, statt
  nur bei der ersten Anlage — genau das Muster, das SUITE.md (DG65/NRGEMS) unter
  „IP-Symcon-Stolpersteine" Punkt 3 verbundweit als Ident-Kollisionsrisiko dokumentiert
  (`RegisterVariableXXX()` bedingungslos auf eine bereits bestehende Variable). Jetzt hinter
  `if (!@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID))` — bestehende Instanzen
  bekommen die Variable weiterhin einmalig nachgezogen, danach ist der Aufruf ein No-Op statt
  eines wiederholten Registrierungsversuchs. Gegen die restlichen fünf Punkte der Liste
  geprüft: Punkte 1/2 (`RequestAction`/`IPS_SetVariableCustomAction`) betreffen dieses Repo
  nicht (keine schreibbaren/aktionsfähigen Variablen); `MeterHub`s und `MeterHubVirtual`s
  eigene Variablenregistrierung (`RegisterVar()`) nutzt ohnehin schon `IPS_CreateVariable()`
  mit demselben „nur bei Fehlen anlegen"-Muster, nicht die eingebauten
  `RegisterVariableXXX()`-Funktionen — dort bestand das Risiko strukturell nicht.

## 0.20.9-beta.1 (2026-07-25)

- **Korrektur zu 0.20.8: `module.json["name"]` zurückgesetzt.** Das Feld ist entgegen der
  Annahme in 0.20.8 KEIN Anzeigename, sondern der PHP-Klassenname, den IP-Symcon per
  Reflection sucht — beim tatsächlichen Neuladen des Moduls (`MC_DeleteModule`+
  `MC_CreateModule`) hätte das bei allen drei MeterHub-Modulen zu „Class NRGMeterHub… does not
  exist" geführt (live bereits so bei ChargerHub/MigrationsHub/InverterHub eingetreten, deren
  Umbenennung als — fälschliches — Vorbild diente). Betraf hier nur den Code-Stand, nicht die
  Live-Installation: MeterHub wurde zwischen 0.20.8 und dieser Korrektur nicht aktualisiert,
  alle sieben Instanzen liefen durchgehend weiter.
  `MeterHub/module.json`, `MeterHubDiscovery/module.json`, `MeterHubVirtual/module.json`
  „name" wieder auf `MeterHub`/`MeterHubDiscovery`/`MeterHubVirtual` — die NRG-präfixierten
  Namen bleiben als zusätzlicher Eintrag in `aliases`. `library.json["name"]` (reines
  Anzeigefeld, unkritisch) bleibt bei „NRGMeterHub for IP-Symcon".

## 0.20.8-beta.1 (2026-07-25) — teilweise zurückgenommen, siehe 0.20.9

- **Modulname auf NRG-Präfix aktualisiert** (Verbund-Entscheidung Dietmars, analog zu
  ChargerHub Commit 5e9ea21): `library.json` „name" → „NRGMeterHub for IP-Symcon";
  `MeterHub/module.json` „name" → „NRGMeterHub", `MeterHubDiscovery/module.json` „name" →
  „NRGMeterHubDiscovery", `MeterHubVirtual/module.json` „name" → „NRGMeterHubVirtual" — jeweils
  mit dem alten Namen zusätzlich in `aliases` erhalten. **Unangetastet: GUIDs (`id`), Präfixe
  (`MHUB`/`MHUBD`/`MHUBV`), Idents und die PHP-Klassennamen** (`MeterHub`/`MeterHubDiscovery`/
  `MeterHubVirtual` bleiben in `module.php` exakt so, wie im Verbund vorgegeben). Reine
  Anzeigenamen-Änderung. **Die `module.json["name"]`-Teile dieser Änderung waren falsch — siehe
  Korrektur in 0.20.9.**

## 0.20.7-beta.1 (2026-07-25)

- **Fix: Fatal Error beim Datenlesen jeder Inexogy-Instanz** — „Call to undefined method
  MHUB_ModbusTcpClient::getLastReading()". Ursache: `ReadSlow()` reichte dem Treiber
  unbedingt einen `GetModbusClient()` durch, statt wie `ReadFast()` über `GetTransport()` nach
  Zählertyp zu unterscheiden — der Inexogy-Treiber bekam damit immer einen Modbus-Client statt
  des `MHUB_InexogyClient`. Betraf ausschließlich den langsamen Lesezyklus (Energiezähler) von
  Cloud-Zählern; der schnelle Zyklus (Leistung) über `ReadFast()` war schon vorher korrekt.
  Bislang unbemerkt, weil dies live der erste tatsächlich funktionierende Inexogy-Login war —
  ein Ein-Zeilen-Fix (`GetModbusClient()` → `GetTransport()`), verhält sich für alle
  Modbus-Zählertypen identisch wie zuvor (`GetTransport()` liefert dort exakt denselben
  Modbus-Client).

## 0.20.6-beta.1 (2026-07-25)

- **Registrierungsschritt (Schritt 1/4) setzt jetzt `Content-Type`/`Accept` explizit**, wie in
  der offiziellen Inexogy-API-Doku (`api.inexogy.com/docs`) für `/oauth1/consumer_token`
  gefordert — bislang schickte `http()` bei diesem Aufruf (unauthentifiziert, kein
  OAuth-Header) gar keine eigenen Header, nur den von curl automatisch aus dem String-Body
  abgeleiteten `Content-Type`. `http()` bekommt dafür einen fünften, optionalen Parameter für
  zusätzliche Header. Auslöser: Dietmars Hinweis, dass das Inexogy-Web-Portal normal
  funktioniert — spricht gegen einen allgemeinen Ausfall und für ein Detail im
  Request-Format des API-Endpunkts, das nach Gegenprüfung mit der Doku näher an die
  Spezifikation gebracht wurde.

## 0.20.5-beta.1 (2026-07-25)

- **Fix: Inexogy-Registrierung (Schritt 1/4) schickte ihre Nutzdaten nie im POST-Body,
  sondern immer im Query-String der URL** — unabhängig von der HTTP-Methode. Live gemeldet als
  „HTTP 500 – 500 Internal Server Error" bei jedem Anmeldeversuch. `registerConsumer()` ist der
  einzige der sechs `http()`-Aufrufe, der eine POST-Anfrage mit echten Nutzdaten sendet (die
  übrigen POST-Aufrufe signieren einen leeren Body, GET-Aufrufe gehören ohnehin in den
  Query-String) — Inexogy bekam also eine leere Anfrage ohne das erwartete `client`-Feld.
  Fix: `http()` unterscheidet jetzt nach Methode — GET weiterhin Query-String, alles andere
  `CURLOPT_POSTFIELDS` (form-kodiert). Betrifft ausschließlich `registerConsumer()`; die
  übrigen fünf Aufrufe verhalten sich unverändert (leerer Body bzw. GET-Query-String wie
  bisher). Kein Testaufbau in diesem Repo prüft den echten Netzwerkpfad (kein Netzzugriff im
  Testlauf) — Verifikation über einen erneuten Live-Anmeldeversuch.

## 0.20.4-beta.1 (2026-07-25)

- **Inexogy-Fehlermeldungen nennen jetzt den konkreten HTTP-/Netzwerkfehler**, nicht mehr nur
  „fehlgeschlagen". Auslöser: Dietmars gemeldeter Fehlschlag bei Schritt 1/4 (Registrierung,
  noch vor jeder E-Mail/Passwort-Prüfung) ließ offen, ob DNS-Fehler, Timeout oder eine echte
  4xx/5xx-Antwort von Inexogy die Ursache war — `http()` verwarf `curl_error()` und den
  Antwort-Body bislang komplett. Jetzt merkt sich `MHUB_InexogyClient` das Diagnosedetail des
  letzten Aufrufs (`getLastError()`) und alle vier Handshake-Fehlermeldungen hängen es an —
  landet dank der vorigen Version auch im Systemprotokoll. Rein additiv, kein geänderter
  Ablauf bei Erfolg.

## 0.20.3-beta.1 (2026-07-25)

- **Inexogy-Anmeldung protokolliert Fehlschläge jetzt zusätzlich im Systemprotokoll**
  (`trigger_error`), nicht mehr nur als Text im offenen Konfigurationsformular. Auslöser: beim
  Versuch, den fehlgeschlagenen Login an #22570 fernzudiagnostizieren, stellte sich heraus,
  dass die einzige Fehlermeldung in der Maske selbst steckte — mit dem Schließen unwiederbring-
  lich weg, für eine spätere oder entfernte Fehlersuche also unauffindbar. Rein additiv, keine
  Änderung an Ablauf oder Erfolgsfall.

## 0.20.2-beta.1 (2026-07-25)

- **Fix: Neuanlage einer Inexogy-Instanz zeigte weiterhin das Host-Feld (IP-Adresse)**, obwohl
  dort für einen Cloud-Zähler nichts Sinnvolles einzutragen ist. Ursache: Das Verbindungspanel
  (Host/Port/Unit-ID vs. Inexogy-Anmeldung) wurde bisher über einen reinen PHP-`if`/`else` auf
  den GESPEICHERTEN Zählertyp gewählt — `GetConfigurationForm()` läuft aber nur beim Öffnen der
  Maske, nicht bei jeder Auswahländerung. Wer im offenen Formular von einem Modbus-Zähler auf
  „Inexogy" umschaltete, sah also weiterhin das Host-Feld samt IP-Pflichtformat.
  Fix: beide Feldgruppen stehen jetzt immer im Formular, die Sichtbarkeit wechselt sofort über
  ein `onChange` an der Zählertyp-Auswahl (`OnChangeMeter()`, per `UpdateFormField`) — wie bei
  „Übernehmen" bereits an anderer Stelle üblich. Das Host-Feld verliert beim Umschalten auf
  Inexogy zusätzlich seine IP-Pflicht-Regex (`validate`), nicht nur die Sichtbarkeit.
  Gefunden über eine Live-Rückmeldung Dietmars beim Versuch, einen Inexogy-Zähler anzulegen.

## 0.20.1-beta.1 (2026-07-25)

- **Fix: „Übernehmen" einer frisch angelegten Inexogy-Instanz schlug fehl**, solange noch
  nicht angemeldet war — Meldung „Aktueller Wert '' ist nicht verfügbar". Ursache: das
  Auswahlfeld „Zähler-UID" (`InexogyMeterID`, Standardwert `''`) bekam seine Optionsliste erst
  nach erfolgreichem Login befüllt; vorher war sie leer, ohne einen Eintrag für den leeren
  Startwert. Fix: ein Platzhalter-Eintrag „— bitte zuerst anmelden —" (Wert `''`) ist jetzt
  immer vorhanden, unabhängig vom Anmeldestatus. Gefunden über eine Live-Meldung an Dietmars
  Installation (Instanz #49738).

## 0.20.0-beta.1 (2026-07-25)

- **Zählersuche schließt bekannte NRG-Stack-Module aus.** Der erste Praxistest an Dietmars
  Installation fand neben echten Steckdosen/Schaltern auch 197 Zeilen, viele davon interne,
  berechnete Variablen anderer NRG-Stack-Module (EMS-Hauslast, PV-Prognose, Tibber-Erlöse,
  Batterie-Aggregate …) — technisch korrekt gefunden (W/kWh-Profil vorhanden), fachlich aber
  kein Fremdzähler. Eine Variable aus einer bekannten NRG-Stack-Modul-Instanz (EMS,
  InverterHub, ChargerHub, Prognose, Tibber Grid Rewards, StromGedacht, HeishaMon, Tessie,
  MigrationsHub, Gleitender Mittelwert, SteuerboxHub, GoodweET) wird jetzt übersprungen —
  sowohl wegen doppelter Buchführung als auch wegen echtem Zirkularitätsrisiko: eine vom EMS
  berechnete Hauslast, die selbst aus MeterHub-Rohdaten stammt, könnte sonst in einen
  virtuellen Zähler einfließen, der wieder in dieselbe Berechnung zurückwirkt.
- Erkennung über eine Liste bekannter Modul-GUIDs, **live an der Installation abgelesen**
  (nicht geraten), läuft die Elternkette bis zur Wurzel hoch — nicht nur bis zur nächsten
  Instanz, falls Instanzen verschachtelt sind. MeterHub selbst bleibt bewusst ausgenommen
  (dessen Instanzen sind der Zweck der Suche); `MeterHubVirtual` war über den bestehenden
  Rückkopplungsschutz schon präziser abgedeckt.
- Ergebnismeldung und Doku-Panel nennen den neuen Ausschlussgrund. `.tools/test-virtual.php`
  prüft ihn mit einer simulierten EMS-Instanz (46 Prüfungen insgesamt).

## 0.19.0-beta.1 (2026-07-25)

- **Fix: globale Klassennamen kollidierten mit ChargerHub und InverterHub.** Der erste echte
  EMS-Discovery-Test lud mehrere NRG-Stack-Module im selben PHP-Prozess und traf auf
  `Fatal error: Cannot redeclare class ModbusTcpClient` — drei Module hatten unabhängig
  voneinander eine gleichnamige globale Klasse deklariert. Alle 13 globalen Klassen/
  Interfaces in diesem Repo (bisher ohne Präfix: `ModbusTcpClient`, `MeterDriverInterface`,
  zehn `<Hersteller>Driver`-Klassen, `InexogyClient`, `InexogyDriver`) tragen jetzt den
  Präfix `MHUB_`. Kein `class_exists()`-Guard — das hätte die Kollision nur kaschiert
  (zufällig gewinnt, wer zuerst lädt); Umbenennung beseitigt sie strukturell.
  **Verhaltensneutral:** reines Symbol-Renaming, keine Logikänderung. Betrifft nur, wer
  direkt gegen diese internen Klassennamen programmiert hätte (niemand außerhalb dieses
  Moduls — die Klassen sind kein Bestandteil des `MHUB_GetFunctions`-Vertrags).
  Die drei Modul-Klassen (`MeterHub`/`MeterHubDiscovery`/`MeterHubVirtual`) bleiben
  unpräfixiert — ihr Name muss dem `module.json`-Feld `name` entsprechen.
- Neue Verbund-Konvention in CLAUDE.md: globale Klassennamen brauchen einen Modul-Präfix,
  genau wie Idents und Variablenprofile. Vor jeder neuen globalen Hilfsklasse kurz prüfen,
  ob der naheliegende Name in einem anderen Verbund-Modul schon vergeben ist.
- Rename verifiziert per wortgrenzen-basiertem Ersatz (`\bKlassenname\b`, trifft nackte
  Bezeichner UND die Zeichenketten in `MeterHub::DRIVERS`), Klassengrenzen-Prüfstand und
  alle Testgerüste laufen unverändert grün.

## 0.18.0-beta.1 (2026-07-24)

- **Gemeinsame NRG-Stack-Profile** (Verbund-Konvention): Die fünf bei MeterHub verwendeten
  physikalischen Grundgrößen wechseln von modulspezifischen auf gemeinsame Profile —
  `MHB.W → NRG.Watt`, `MHB.kWh → NRG.kWh`, `MHB.V → NRG.Volt`, `MHB.A → NRG.Ampere`,
  `MHB.Percent → NRG.Percent`. Modulspezifisch bleiben `MHB.Hz`, `MHB.VA`, `MHB.var`,
  `MHB.PF`, `MHB.Wh`, `MHB.PhaseSeq`.
- **Bestehende Instanzen migrieren automatisch** beim nächsten `ApplyChanges` — kein manueller
  Schritt nötig. Alte Profile werden nicht gelöscht, nur nicht mehr aktiv gepflegt.
- **Neue gemeinsame Profile sind eigentümerlos:** `ensureSharedProfile()` legt sie nur an,
  wenn sie fehlen, und überschreibt eine bereits von einem anderen NRG-Stack-Modul angelegte
  Definition nicht mehr — anders als das bisherige `ensureProfile()` für modulspezifische
  Profile, das Digits/Suffix weiterhin bei jedem `ApplyChanges` durchsetzt.
- `.tools/test-virtual.php` prüft die Eigentümerlosigkeit jetzt direkt (43 Prüfungen): ein
  fremd mit abweichenden Werten angelegtes `NRG.Watt` bleibt unangetastet, ein fehlendes wird
  korrekt angelegt, modulspezifische Profile werden weiterhin durchgesetzt.

## 0.17.0-beta.1 (2026-07-23)

- **Vertragsversionierung `contractVersion` (Verbund-Konvention).** `MHUB_GetFunctions` und
  `MHUBV_GetFunctions` liefern jetzt additiv `contractVersion => '1.1'` — 1.0 = Ur-Vertrag,
  1.1 = die latency/authority/pollInterval/energyKind/sourceCount-Erweiterung. Ein Konsument
  kann damit die Kompatibilität prüfen (Major nur bei Bruch, volle Verträglichkeit innerhalb
  derselben Major; fehlendes Feld = konservativ 1.0). Rein additiv, bestehende Konsumenten
  ignorieren es. Regel in CLAUDE.md, Prüfstand kontrolliert das Feld mit.
- **README:** Zeile „Teil der DG65 Energie-Suite" mit Verweis auf `DG65/EMS/SUITE.md` (Manifest
  der zusammen getesteten Modulstände).

## 0.16.4-beta.1 (2026-07-23)

- **Emoji-Entfernung aus 0.16.3 rückgängig.** Der Verbund hatte die Emoji-Regel zunächst
  restriktiv gefasst (nur Panel-Icons); Dietmar hat sie danach permissiv entschieden: Status-
  und Aufmerksamkeitssymbole (`✅`/`❌`/`⚠️`/`💡`/`ℹ️`) sind erwünscht, wo sie Fokus schaffen —
  kein Review hat je eines beanstandet. Die in 0.16.3 durch Wörter ersetzten Symbole sind damit
  wieder da; die permissive Regel steht in CLAUDE.md. (0.16.3 war netto ein Rundweg — hier zur
  Nachvollziehbarkeit dokumentiert statt stillschweigend übersprungen.)

## 0.16.3-beta.1 (2026-07-23, zurückgenommen)

- Dekorative Emoji aus Fließtext/Status entfernt (restriktive Zwischenregel) — in 0.16.4
  wieder rückgängig gemacht.

## 0.16.1-beta.1 (2026-07-23)

- **Migrations-Hinweis im Inexogy-Cloud-Panel.** Wer von einem anderen Discovergy-/Inexogy-Modul
  umsteigt und die Messhistorie behalten will, sieht jetzt den mit MigrationsHub abgestimmten
  Ablauf: Instanz erst mit „Kommunikation aktiv = AUS" anlegen und anmelden, dann adoptieren,
  danach einschalten — so bleibt die Zielvariable bis zur Übernahme ohne eigene Historie und es
  gibt keine Grauzone.

## 0.16.0-beta.1 (2026-07-23)

- **Neuer Zählertyp: Inexogy / Discovergy (Cloud-API) — der erste Nicht-Modbus-Zähler.**
  MeterHub bekommt damit eine zweite **Transportklasse neben Modbus**: `ModbusTcpClient` bleibt
  unverändert, ein neuer `InexogyClient` (OAuth 1.0a, HMAC-SHA1 in reinem PHP) tritt daneben.
  Der Hub baut je Zählertyp den passenden Transport (`GetTransport`), die 15 Modbus-Treiber
  bleiben unberührt. Architekturregel dahinter: getrennt wird nach Lebenszyklus (Pull/Timer wie
  Modbus vs. Push), nicht nach Protokoll — deshalb passt der gepollte Cloud-Zähler hier hinein,
  ein Push-Empfänger (SMA-Speedwire) bekäme dagegen ein eigenes Modul.
- **Sicherer Zugang.** Anmeldung mit E-Mail/Passwort des Inexogy-Kontos, der OAuth-Handshake
  (Consumer-Token selbst registrieren → Request-Token → Autorisieren → Access-Token) läuft
  programmatisch. Danach werden **nur die Token** gespeichert (Attribute, nicht im Formular),
  das **Passwort wird sofort gelöscht**. Kein Klartext-Passwort im System — anders als das alte
  Discovergy-Fremdmodul (Basic Auth). Kein Token/Passwort in Log oder Anzeige.
- **Verifizierte Werte, kein Raten.** Feldstruktur und Skalierung aus dem öffentlichen
  Quellcode des Alt-Moduls geholt und gegen die echten Zählerwerte gegengeprüft: `energy`/
  `energyOut` /10^10 → kWh (kumulativ, `energyKind: counter`), `power` /1000 → W (Rohwert mW),
  Phasen/Spannung /1000, beide Firmware-Feldnamen (`power1` und `phase1Power`). Vertrag: Rolle
  grid, `authority: billing`, `latency: delayed`. Keine Kostenrechnung — reine Messung.
- **Idents identisch zum MeterHub-Standard** (`energy_import`/`energy_export`/`power_total`/
  `p_l*`/`u_l*`), damit die geplante MigrationsHub-Adoption der Alt-Historie einfach ausrichtet.
- **Neu: `.tools/test-inexogy.php`.** Prüft OAuth-Prozentkodierung (RFC 3986), die
  Signaturbildung gegen eine unabhängige Nachrechnung und die Treiber-Skalierung gegen ein
  last_reading mit Dietmars echten Größenordnungen (27 Prüfungen). Der Handshake selbst ist
  erst am echten Login verifizierbar — bewusst nicht abgedeckt, klar markiert.

## 0.15.1-beta.1 (2026-07-22)

- **`latency`/`authority`/`pollInterval` zusätzlich in jede Zuordnung gespiegelt** (bisher nur
  auf Instanz-Ebene). Anlass: Die InverterHub-Netzbezug-Auswertung iteriert über
  `assignments[]` und filtert nach `function`; sie liest `authority` an der Zuordnung, nicht am
  Instanz-Objekt. Ohne die Spiegelung hätte sie `authority` verfehlt und keinen Zähler als
  `billing` erkannt. Die Werte stehen jetzt an beiden Orten (aus derselben Property, können
  nicht auseinanderlaufen) — Konsumenten können frei wählen. Rein additiv.
- `.tools/test-virtual.php` prüft `authority`/`latency` je Zuordnung mit (34 Prüfungen).

## 0.15.0-beta.1 (2026-07-22)

- **Vertragserweiterung `MHUB_GetFunctions` / `MHUBV_GetFunctions` (additiv, im Verbund
  abgestimmt).** Vier neue Felder, damit ein Konsument (EMS, Auswertung, InverterHub-Balken)
  Zähler nach Eignung unterscheiden kann:
  - `latency` (`realtime`|`delayed`) — darf das EMS in Sekunden darauf regeln? Modbus-Zähler
    sind `realtime`, Cloud-Zähler (Inexogy folgt) `delayed`.
  - `authority` (`billing`|`auxiliary`) — steht der Wert auf der Rechnung? Neue Checkbox
    „Abrechnungsverbindlicher Zähler am Netzübergabepunkt" setzt `billing`.
  - `pollInterval` — reale Aktualisierungsrate in Sekunden.
  - `energyKind` je Zuordnung (`counter`|`interval`) — kumulativer Zählerstand (Konsument
    bildet Differenzen) vs. Periodenverbrauch (summieren). Alle bisherigen Zähler `counter`.
  - `sourceCount` je Zuordnung (nur MHUBV) — Zahl der beteiligten Quellen eines Rest-/
    Summenknotens als Güte-Hinweis (der Rest wird still zu groß, wenn eine Quelle ausfällt).
- **`latency` und `authority` sind bewusst getrennt.** Sie sind orthogonal — alle vier
  Kombinationen existieren real (Inexogy billing+delayed, lokaler Shelly auxiliary+realtime,
  lokal ausgelesenes iMSys billing+realtime, virtueller Rest-Knoten auxiliary+realtime). Ein
  einzelnes Flag könnte das nicht trennen; deshalb zwei Felder.
- Bestehende Konsumenten (Kachel, Sankey) ignorieren die neuen Felder — kein Bruch. Konvention
  in CLAUDE.md dokumentiert (inkl. konservativer Defaults bei fehlenden Feldern).
- `.tools/test-virtual.php` prüft die neuen Felder mit (32 Prüfungen). Vorbereitung des
  Cloud-Zählertyps: `CLOUD_METERS`-Liste im Hauptmodul steht bereit (noch leer), Inexogy trägt
  sich dort ein, sobald der Treiber gebaut wird.

## 0.14.2-beta.1 (2026-07-22)

- **Sprachregel des Modul-Verbunds umgesetzt: alles Nutzersichtbare auf Deutsch.** Ersetzt
  wurden vermeidbare Anglizismen in Anzeigetexten und Dokumentation — „Scan abbrechen" →
  „Suche abbrechen", „Der Scan prüft…" → „Die Suche prüft…", „beim Scan übersprungen" → „bei
  der Suche übersprungen", Polling → Abfragetakt, Framework → Treibergerüst, Checkboxen →
  Schalter, Highlights → „Das Wichtigste in Kürze".
- **Bezeichner blieben unangetastet.** `ScanMeters`, `ScanRoot`, `ScanResult`, `BtnScan`,
  `AbortScan` und alle Idents sind unverändert: Idents sind API, ein umbenannter Ident
  erzeugte eine neue Variable und würfe die Historie der alten weg. Die Abgrenzung zwischen
  Anzeigetext und Bezeichner ist in CLAUDE.md festgehalten.

## 0.14.1-beta.1 (2026-07-22)

- **Zwei-Regler-Warnung für den go-e Controller** (README + Konfigurationsformular): Der
  Controller kann die go-e-Wallboxen **selbst** regeln (PV-Überschussladen, Lastbegrenzung).
  Wer stattdessen ein EMS steuern lässt, muss die interne Regelung deaktivieren — sonst
  arbeiten zwei Regler gegeneinander. Recherchiert und dokumentiert: Der Regelzustand ist über
  die Modbus-Karte des Controllers **nicht** sichtbar (auch nicht über dessen HTTP-API, die
  nur Messwerte führt) — er liegt an den Wallboxen selbst (`fup`, `loe`, `modelStatus` mit
  Klartextgrund, `lpsc`). Die Statusvariable dafür gehört deshalb in ChargerHub; dieses Modul
  liest ausschließlich und ist vom Konflikt nicht betroffen.

## 0.14.0-beta.1 (2026-07-22)

- **Neuer Zähler: go-e Controller — an echtem Gerät verifiziert.** Die Energiemess-Zentrale
  von go-e wechselt fachlich vom Schwestermodul ChargerHub hierher (dort bleiben die
  Wallboxen). Kernwerte kommen aus der Kategorie **Grid** (Wirkleistung, Bezug/Abgabe als
  64-Bit-Double in Wh); zuschaltbar sind Spannung je Phase (inkl. N), Strom je Phase (inkl. N),
  die **Stromsensoren 1–6** (Strom/Leistung/Leistungsfaktor) und die Kategorien
  **Home/Car/Relais/Solar/Akku** (je Leistung + Energie Ein/Aus). FC 0x04, Float32/Float64
  Big-Endian, Wire-Adresse = Doku − 30001.
- **Besonderheiten des Geräts, im Treiber berücksichtigt:** Es gibt keine Frequenz (Register
  1008 ist „nicht implementiert") — der Kern verzichtet darauf. Unbelegte Register beantwortet
  der Controller mit 0xFFFF… (NaN) statt einer Modbus-Exception; alle Werte werden deshalb vor
  der Übernahme auf Endlichkeit geprüft, statt NaN in die Variablen zu schreiben. Modbus TCP
  muss am Gerät erst aktiviert werden (go-e-App: Internet → Erweiterte Einstellungen → Modbus);
  nach dem Aktivieren die Einstellung ggf. einmal aus-/einschalten.
- **Netzwerksuche erkennt den go-e Controller** (Spannung L1/L2 auf 1000/1002 als
  Doppelkriterium, Unit-ID 1). Das NaN-Verhalten schützt zugleich vor Verwechslung: Bei allen
  Fremd-Proben fällt der Controller sauber durch, und NaN zählt bei der eigenen Probe nicht
  als Treffer — beides am Gerät gegengeprüft.
- Verifikation am echten Controller: Spannungen, Sensorleistungen (Summe der Netzsensoren ≙
  Grid-Leistung), Energiezählerstände und Vorzeichen (− = Einspeisung, passt zur Konvention)
  live abgeglichen; Blocklesungen mit 125 Registern bestätigt.

## 0.13.0-beta.1 (2026-07-22)

- **Virtuellen Zähler direkt aus einer Zählerinstanz anlegen.** Jede MeterHub-Instanz hat das
  neue Panel „🧮 Virtueller Zähler": weitere beteiligte Instanzen auswählen, Rolle festlegen —
  *übergeordnet* (die anderen hängen dahinter → Summe **und** Rest) oder *gleichrangig* (nur
  Summe) — und anlegen. Die Verdrahtung der neuen MeterHubVirtual-Instanz wird fertig
  vorbelegt; die Variablen-IDs muss niemand von Hand zusammensuchen. Geschrieben wird
  ausschließlich in die **neue** Instanz, an der eigenen Konfiguration ändert der Knopf nichts.
- **Prüfung vor dem Anlegen.** Steckt der Zähler bereits in einem virtuellen Zähler, wird
  **nicht** angelegt, sondern auf die vorhandene Instanz samt Kürzel verwiesen — ein zweiter
  virtueller Zähler mit demselben Gerät wäre der Anfang doppelter Buchführung. Ebenso wird
  gemeldet, wenn ein Zähler weder Gesamtleistung noch Bezug liefert oder je Phase misst.
- **Funktionszuordnung wird bewusst nicht übernommen.** Belegte der virtuelle Knoten dieselbe
  Funktion wie der echte Zähler, erschiene der Verbraucher in Stromflusskachel und Sankey
  doppelt. Der Hinweistext nennt stattdessen den sinnvollen Griff: dem übergeordneten Knoten
  „Hausverbrauch" geben, dann ist der Rest alles, was nicht auf die Unterzähler entfällt.
- **Hinweis in der Zählerinstanz.** Ist ein Zähler in einen virtuellen eingebunden, zeigt das
  Panel das an — mit Instanz und Kürzel. Vorher gab es von der normalen Instanz aus keinerlei
  Hinweis, dass es virtuelle Zähler überhaupt gibt.
- **Vier Filter für den Zählersuchlauf** (MeterHubVirtual): Suchbereich (nur unterhalb eines
  Objekts), Namensbestandteil, „nur Geräte mit Energiezähler" und „nur in den letzten 7 Tagen
  aktualisiert". Sie wirken sofort beim Klick, auch ohne vorher zu übernehmen. Das Ergebnis
  nennt den verwendeten Suchbereich und zählt auf, was woran gescheitert ist; wurde alles
  wegfiltriert, sagt es das ausdrücklich statt nur „nichts gefunden".
- **Verdrahtungsliste wächst mit dem Inhalt** (12 bis 30 Zeilen statt fest 8) — auch direkt
  nach einem Suchlauf, damit die Funde ohne Scrollen sichtbar sind.
- **Neu: `.tools/test-virtual.php`.** Prüfstand mit nachgebildeter IP-Symcon-Umgebung, der die
  Brücke wirklich ausführt: Knotenaufbau beider Rollen, Übernahme durch das Zielmodul,
  gerechnete Summen und Reste, die Ablehnung des zweiten virtuellen Zählers, alle vier
  Suchfilter und der Rückkopplungsschutz. Anlass ist ein früherer Laufzeitfehler in genau
  diesem Modul, den `php -l` nicht sehen konnte.

## 0.12.0-beta.1 (2026-07-21)

- **Neues Modul: MeterHubVirtual — virtuelle Zähler aus der Verdrahtung.** Statt frei
  konfigurierbarer Rechenoperationen wird beschrieben, welcher Zähler *hinter* welchem sitzt.
  Daraus leitet das Modul je Knoten „Summe untergeordnet" und „Rest" (eigener Zähler minus
  Untergeordnete) ab — für Leistung (W) und Energie (kWh), archiviert. Der Grund ist
  Fehlersicherheit: Weil jeder Zähler im Baum genau **einen** Platz hat, ist ein doppelter
  Abzug strukturell ausgeschlossen. Was der Baum nicht verhindert, meldet die Prüfung
  (derselbe Datenpunkt in zwei Knoten, Ringschlüsse, unbekannte Elternknoten, doppelte
  Kürzel, gemischte Einheiten) — und solange etwas offen ist, wird bewusst nicht gerechnet.
  Jeder Knoten kann eine Funktion bekommen; `MHUBV_GetFunctions` liefert denselben Vertrag wie
  das Hauptmodul, sodass virtuelle Zähler in Stromflusskachel und Sankey wie echte erscheinen.
- **Automatische Zählersuche.** „🔎 Zähler im System suchen" findet Datenpunkte mit W-/kW- bzw.
  kWh-Profil (Steckdosen, Licht- und Jalousieschalter, Zwischenzähler), gruppiert sie je Gerät
  und übernimmt den **Gerätenamen** als Bezeichnung. Geprüft wird dabei: unbrauchbare Einheiten
  werden übersprungen, bereits eingetragene nicht doppelt vorgeschlagen, **Ausgaben virtueller
  Zähler ausgeschlossen** (sonst Rückkopplung), fehlende Archivierung und veraltete Werte
  gemeldet. Die Funde sind nur ein Vorschlag in der geöffneten Maske — gespeichert wird mit
  „Übernehmen"; die Verdrahtung bleibt eine bewusste Entscheidung.

## 0.11.1-beta.1 (2026-07-21)

- **Funktions-Vokabular vervollständigt.** Neu: Waschmaschine, Spülmaschine, Backofen, Herd,
  Kühl-/Gefriergerät, Heizung/Heizstab, Lüftung, Server/Netzwerk und Werkstatt. Die Liste ist
  jetzt nach Bereichen gruppiert (Anlage · Wärme/Klima · Mobilität · Haushaltsgeräte ·
  Weitere), was die Auswahl im Dropdown übersichtlicher macht. Bestehende Zuordnungen bleiben
  erhalten — es kamen nur Einträge hinzu, vorhandene Schlüssel wurden nicht verändert.

## 0.11.0-beta.1 (2026-07-21)

- **Funktionszuordnung: Zähler bzw. Phasen bestimmten Verbrauchern zuordnen.** Neues Panel
  „Funktionszuordnung". Zuerst wird der **Messmodus** festgelegt — das ist die Weiche:
  - *Dreiphasig* (ein Verbraucher über alle 3 Phasen) → **eine** Funktion für den Zähler,
    z. B. „Netzanschluss" oder „Wärmepumpe".
  - *Einphasig getrennt* (3 unabhängige Verbraucher) → **je Phase** eine eigene Funktion,
    z. B. L1 = Garage, L2 = Wärmepumpe, L3 = Wallbox 1.

  Vokabular: Netzanschluss, Hausverbrauch, PV-Erzeugung, Batterie, Wärmepumpe, Wallbox 1–5,
  Garage, Warmwasser, Klimaanlage, Pool, Sauna, Trockner, Küche, Beleuchtung, Sonstiger
  Verbraucher — jeweils mit optionaler eigener Bezeichnung. Bewusst an den Verbraucher-Arten
  der InverterHubTile-Kachel orientiert.

  Wirkung:
  - **Benennung + Icon**: betroffene Variablen heißen z. B. „Wärmepumpe — Wirkarbeit Bezug"
    und bekommen ein passendes Icon.
  - **Maschinenlesbar**: neue Funktion `MHUB_GetFunctions($id)` liefert Modus, Zuordnungen
    und die Variablen-IDs (Leistung/Bezug/Einspeisung) als JSON — damit können EMS oder
    Kacheln die Zuordnung automatisch übernehmen.
  - **Optionale Sammel-Variablen** je Funktion (Kategorie „Funktionen"), die den zugeordneten
    Kanal spiegeln — bequem für Charts und Automationen. Werden nur angelegt, wenn der
    Quellkanal beim gewählten Zähler existiert.

## 0.10.0-beta.1 (2026-07-21)

- **Shelly Pro 3EM: Energiezähler je Phase.** Neue optionale Gruppe „Energie je Phase
  (Bezug/Abgabe)" mit eigenen kWh-Zählern für L1, L2 und L3 — damit lässt sich jede Phase
  als eigenständiger Verbraucher führen. Die Registeradressen wurden am realen Gerät
  (Shelly Pro 3EM Gen3) ermittelt und gegen dessen eigene RPC-API gegengeprüft: Summe
  1162/1164, danach je Phase im Abstand von 20 Registern (L1 1182/1184, L2 1202/1204,
  L3 1222/1224) — die in der Doku-Übersicht genannten 1170/1190/1210 sind etwas anderes.
  Die Probe bestätigt L1+L2+L3 = Gesamtzähler. Alles wird in **einem** Block-Read
  (1162..1225) geholt, kostet also keine zusätzlichen Modbus-Anfragen.

## 0.9.2-beta.1 (2026-07-20)

- **Shelly Pro 3EM an echtem Gerät verifiziert und final korrigiert.** Ein Live-Test am
  Shelly Pro 3EM zeigte: gelesen wird über **FC 0x04** (Input-Register), die Doku-
  Registernummern sind um **30000 versetzt** (Wire-Adresse = Doku − 30000, also Messwerte
  ab 1011, Energie 1162/1164) und die Float32-Werte sind **wortgetauscht (CDAB)**. Damit
  liefert der Treiber jetzt korrekte Werte (Spannung ~233 V, Leistung, Frequenz 49,99 Hz,
  Bezug/Einspeisung). Die Discovery-Erkennung wurde entsprechend angepasst (FC 0x04,
  CDAB, 1033/1020). Modbus TCP muss am Gerät aktiviert sein.

## 0.9.1-beta.1 (2026-07-20)

- Shelly Pro 3EM auf FC 0x03 / absolute Adressen (31011) umgestellt — **fehlerhaft**, siehe
  0.9.2 (das Gerät nutzt FC 0x04, Adressen − 30000, wortgetauscht).

## 0.9.0-beta.1 (2026-07-20)

- **Discovery erkennt jetzt mehr Zähler.** Neben PAC2200 und Janitza findet der Netzwerk-
  Scan nun auch **Shelly Pro 3EM, Carlo Gavazzi EM24/ET340, WhatWatt, Phoenix EEM-EM375**
  und **Eastron SDM72D/SDM630** — je über eine Plausibilitätsprüfung (Spannung/Frequenz) am
  charakteristischen Register. Dafür liest die Discovery jetzt auch Input-Register (FC 0x04)
  sowie Int32-CDAB/UInt16 (Carlo Gavazzi). Hinweis: Beim Shelly Pro 3EM muss Modbus TCP am
  Gerät aktiviert sein. Zähler hinter RTU/TCP-Gateways mit frei wählbarer Unit-ID (Socomec,
  MBS) werden weiterhin nicht automatisch gefunden — dort die Instanz manuell anlegen.

## 0.8.0-beta.1 (2026-07-20)

- **Drei experimentelle Zähler ergänzt** (aus Vorlagen/Fremdquellen abgeleitet, noch nicht
  an echter Hardware geprüft — im Dropdown als „experimentell" markiert):
  - **Socomec Countis** (E23/E24/E27/E28/E34/E44) — FC 0x03, Register/Skalen nach OpenEMS.
  - **MBS Professional 3-75** — M-Bus/Modbus-Gateway, FC 0x03, aus den Symcon-Vorlagen.
  - **Shelly Pro 3EM** — FC 0x04, Float32 (Adressen aus realer ESPHome-Konfiguration).
    Enthält vorerst **keine** Energiezähler (EMData-Register folgen).
- ABB B23/B24 und Schneider iEM3000 warten auf Geräte-Modbus-Tabellen.

## 0.7.0-beta.1 (2026-07-20)

- **Eastron SDM630 v2** ergänzt — nutzt dieselbe Registerkarte wie der SDM72D-M v2 (per
  OpenEMS bestätigt), teilt sich daher den Treiber.
- **Carlo Gavazzi EM24 / EM300 / ET340** ergänzt — Int32 mit getauschter Wortreihenfolge
  (CDAB) und Skalierung (U ×0,1 · I ×0,001 · P ×0,1 · f ×0,1 · Energie ×0,1 kWh), FC 0x04.
  Registerkarte nach OpenEMS. Neue Client-Helfer `u32sw`/`s32sw` für wortgetauschte 32-Bit-
  Werte (CDAB-Dekodierung per PHP-Test verifiziert).

## 0.6.0-beta.1 (2026-07-20)

- **Vier weitere Zähler ergänzt** (aus IP-Symcon-Forum-Vorlagen, alle Float32 über
  Funktionscode 0x04):
  - **Eastron SDM72D-M v2** — vollständige Karte (U/I/P/Q/S je Phase, Leistungsfaktor,
    L-L-Spannung, Neutralleiterstrom, Frequenz), Energie in kWh.
  - **WhatWatt** — getrennte Bezugs-/Abgabeleistung (Gesamt = Bezug − Abgabe), Energie inkl.
    Tarif 1/2 (64-Bit-Double).
  - **Phoenix Contact EEM-EM375** (ab Reg. 4096) und **EEM-XM** (ab Reg. 32774).
- **Neuer Schalter „Float-Wortreihenfolge tauschen (CDAB)"** für Geräte/Gateways, die die
  16-Bit-Wörter gedreht liefern (z. B. manche Phoenix EEM-XM) — wirkt auf Float32 und Double.
- Die „Siemens Sentron"-Forumvorlage wurde bewusst **nicht** übernommen: sie enthält
  Leistungsschalter/Schutzgeräte (5SL/5SV/5ST/3RV COM, 7KN-Datensammler), keine Energiezähler.

## 0.5.0-beta.1 (2026-07-20)

- **Modul MeterHubWebFront wieder entfernt.** Ein Test am realen Siemens PAC2200 hat gezeigt,
  dass dessen Webserver die Einbettung hart verbietet (`X-Frame-Options: deny` und
  `Content-Security-Policy: frame-ancestors 'self'`) — kein Browser rendert die Oberfläche
  dann in einer Kachel. Da Industriezähler diese Sperre typischerweise setzen, wird der
  iframe-Ansatz nicht weiterverfolgt.

## 0.4.0-beta.1 (2026-07-20)

- **Neues Modul: MeterHubWebFront (Kachel).** Bettet das Web-Frontend eines Zählers
  (Siemens PAC2200, Janitza UMG) per iframe direkt in eine Kachel ein. Die IP kommt
  wahlweise aus einer verknüpften MeterHub-Instanz oder wird manuell eingetragen;
  Protokoll/Port/Pfad beziehen sich auf die Weboberfläche des Geräts. Werkzeugleiste mit
  Titel, „Neu laden" und „In neuem Tab öffnen", einstellbarer Zoom und optionales
  automatisches Neuladen. Hinweis: Ob die Einbettung klappt, hängt vom Geräte-Webserver ab
  (X-Frame-Options / CSP) und — bei über HTTPS aufgerufenem Symcon — am Mixed-Content-Block
  des Browsers; in diesen Fällen hilft „In neuem Tab öffnen".

## 0.3.0-beta.1 (2026-07-20)

- **Netzwerk-Scan abbrechbar.** Während eines laufenden Scans erscheint ein „✖ Scan
  abbrechen"-Button (der Start-Button wird solange ausgeblendet). Der Abbruch wird über
  eine versteckte, thread-sichere Flagge angefordert; sowohl der Portscan als auch die
  anschließende Zählererkennung prüfen sie und brechen zeitnah ab — die bis dahin
  gefundenen Zähler bleiben in der Ergebnisliste. Analog zur InverterHub-Discovery.

## 0.2.0-beta.1 (2026-07-20)

- **Weitere Janitza-Zähler ergänzt.** Der bisherige UMG604-Treiber wurde zum gemeinsamen
  `JanitzaClassicDriver` verallgemeinert und deckt nun **UMG 604, 605-PRO, 509-PRO,
  512-PRO, 806, 96PA und 801** ab — alle nutzen dieselbe klassische Registerkarte ab 19000
  (per Handbuch/Adressliste verifiziert). Ø-Spannung/-Strom werden jetzt aus den
  Phasenwerten berechnet statt aus dem 19630-Mittelwertblock, den nicht jedes Modell dieser
  Familie führt (z. B. der UMG 96PA nicht); der Gesamt-Leistungsfaktor wird separat und
  fehlertolerant gelesen.
- **Neuer Treiber `Umg800Driver`** für den **Janitza UMG 800**. Der UMG 800 hat eine frei
  konfigurierbare Modbus-Karte mit abweichendem Aufbau (Summe P 19030, Frequenz 19054,
  Bezug 19072, Abgabe 19080, Lücke bei 19020–19023) — der Treiber folgt der ausgelieferten
  Werksvorgabe und liest lückenbewusst in zwei Blöcken.
- **Discovery** unterscheidet jetzt klassische Janitza-Karte (Frequenz 19050) und UMG 800
  (Frequenz 19054, 19050 kein Frequenzwert) und schlägt den passenden Typ vor.

## 0.1.0-beta.1 (2026-07-20)

- **Erste Version.** Generisches Modbus-TCP-Framework für Energiezähler, analog zum
  InverterHub.
  - **MeterHub** (Lesemodul): Treiber-Framework mit `ModbusTcpClient`,
    `MeterDriverInterface` und je einem Treiber pro Modell. Zählertyp-Auswahl schaltet die
    passenden Datenpunkt-Gruppen frei. Rollen-Auswahl (Netz-/NAP-Zähler vs. Unterzähler),
    Invers-Schalter für die Wirkleistung, kWh/Wh-Umschaltung, getrennte Polling-Intervalle
    für Momentan- und Energiewerte, automatische Archivierung der relevanten Variablen.
    - **Siemens SENTRON PAC2200**: Float32-Messgrößen (zwei lückenfreie Block-Reads 1–42
      und 55–72), Energiezähler Bezug/Abgabe Tarif 1+2 als 64-Bit-Double ab Reg. 801.
      Optionale Gruppen: Spannung/Strom/Wirkleistung je Phase, Blind-/Scheinleistung,
      Leistungsfaktor, Energie Tarif 2.
    - **Janitza UMG 604(-PRO)**: Float32-Messgrößen ab Reg. 19000, Energie (Wh) bei
      19068/19076, Mittelwerte ab 19630. Optionale Gruppen: Spannung/Strom/Wirkleistung je
      Phase, Blind-/Scheinleistung, cos φ, Netzqualität (THD + Drehfeld).
  - **MeterHubDiscovery** (Configurator): nicht-blockierender Netzwerk-Scan auf Port 502,
    Zählererkennung über Plausibilitätsprüfung (Frequenz 45–65 Hz + plausible Spannung),
    Abgleich mit bereits angelegten MeterHub-Instanzen, Namens-Vorlagen mit Platzhaltern.
