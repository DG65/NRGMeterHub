# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

An diesen drei Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet:

- **MeterHub** (dieses Repo): Energiezähler per Modbus TCP — https://github.com/DG65/MeterHub
- **InverterHub**: Wechselrichter per Modbus TCP — https://github.com/DG65/InverterHub
  (lokale Arbeitskopie: `../InverterHub`)
- **Prognose** (Suite EnergiePrognose): PV- und Verbrauchsprognose —
  https://github.com/DG65/Prognose (lokale Arbeitskopie: `../Prognose`)

**MeterHub koppelt direkt nur an InverterHub.** Zum Prognose-Repo besteht derzeit keine
Verbindung; es ist hier nur zur Orientierung genannt, weil an allen dreien parallel gearbeitet
wird. Die Prognose ist ihrerseits an den `InverterHubMonitor` gekoppelt (Vertrag dort:
`PVF_Get*`). Sollte MeterHub jemals Prognosewerte einbeziehen, ist das vorher mit der
Prognose-Sitzung abzustimmen — nichts eigenmächtig in fremden Repos anlegen.

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

## Eigenständigkeit prüfen: `tools/check-standalone.php`

```
php tools/check-standalone.php     # 0 = sauber, 1 = ungesicherter Fremdaufruf
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

Das Skript stammt aus dem InverterHub-Repo (dort `tools/check-standalone.php`); Änderungen an
der Prüflogik bitte in beiden Repos gleich halten.

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
