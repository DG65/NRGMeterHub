Deutsch | [English](EIGENES_ZAEHLER_MODUL.en.md)

# Eigenes Zähler-Modul an den NRG-Stack anbinden

Diese Anleitung richtet sich an Entwickler, die ein **eigenes Symcon-Modul für einen Zähler** haben (Strom-, Energie- oder
Leistungsmessung) und es für das EMS, das Dashboard und die Prognose des NRG-Stacks sichtbar machen wollen. Das eigene
Modul bleibt ein eigenständiges Modul. Es muss weder MeterHub noch ein anderes NRG-Stack-Modul installiert haben, und
MeterHub braucht das Fremdmodul nicht.

Gemeint ist der **Vertrag `meter`, Version 1.6**. Er wird von MeterHub (`MHUB_GetFunctions`), vom virtuellen Zähler
(`MHUBV_GetFunctions`) und vom Schätzzähler (`MHUBE_GetFunctions`) angeboten.

> **Stand 20.11.2026:** Der Vertrag ist festgelegt. Das EMS findet fremde Wallboxen schon allgemein; die Suche nach
> fremden Zählern folgt. Bis dahin erkennen Dashboard und andere Konsumenten Zähler zum Teil noch über die Modul-GUID.

## Das Prinzip in drei Sätzen

1. Dein Modul bietet eine Funktion `<PRÄFIX>_GetFunctions($id)` an. Sie liefert einen **JSON-Text** mit einer Liste von
   Zuordnungen (`assignments`), je Zuordnung eine Messstelle („Netzanschluss“, „Hausverbrauch“, „Wärmepumpe“ …).
2. Gelesen wird über **Variablen-IDs**. Es gibt keine Abfragefunktion für Messwerte.
3. Ein Zähler **misst nur**. Der Vertrag hat keine Steuerbefehle.

## Die Funktion `<PRÄFIX>_GetFunctions($id)`

- Der Präfix steht in der `module.json` deines Moduls (`"prefix": "MZ"`). Eine öffentliche Methode `GetFunctions()` in der
  Modulklasse wird von Symcon automatisch als `MZ_GetFunctions($id)` bereitgestellt.
- Rückgabe: ein **JSON-Text** (Objekt) wie unten.
- Die Funktion muss **schnell und ohne Nebenwirkung** sein: nur Variablen-IDs nachschlagen und Eigenschaften lesen, nichts
  vom Gerät abfragen. Während die Instanz neu lädt, gib `{"instanceID": <id>, "ready": false, "assignments": []}` zurück,
  statt Warnungen zu erzeugen.

## Felder der Instanz-Ebene

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `contractVersion` | ja | `'1.6'`. Konsumenten prüfen die Hauptversion (vor dem Punkt). Fehlt das Feld, gilt die niedrigste bekannte. |
| `contractType` | ja | immer `'meter'` |
| `instanceID` | ja | ID deiner Instanz |
| `assignments` | ja | Liste der Zuordnungen, siehe unten (leer, wenn dem Zähler keine Funktion zugewiesen ist) |
| `latency` | empfohlen | `'realtime'` (Standard bei fehlendem Feld) oder `'delayed'` (z. B. Cloud-Zähler mit verzögertem Archiv) |
| `pollInterval` | empfohlen | Abfragetakt in Sekunden |
| `authority` | empfohlen | `'billing'` für einen geeichten, abrechnungsverbindlichen Zähler am Netzübergabepunkt, sonst `'auxiliary'` (Hilfszähler). Fehlt das Feld, gilt `'auxiliary'`. |
| `meter` | nein | Zählerart oder Treiber als freier Text (z. B. `'siemens_pac2200'`). **Das ist nicht der Vertragstyp.** Der Typ steht immer in `contractType`. |

## Felder einer Zuordnung (`assignments[]`)

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `contractType` | ja | immer `'meter'` |
| `function` | ja | die Messstelle, siehe „Funktionen und Vorzeichen“ |
| `label` | ja | Anzeigename (meist der Instanzname) |
| `powerID` | ja | Variablen-ID der Leistung in **Watt** (Float), `0` wenn nicht vorhanden |
| `energyImportID` | ja | Variablen-ID eines **kumulativen** Zählerstands in kWh (Bezug bzw. Verbrauch), `0` wenn nicht vorhanden |
| `energyExportID` | nein | Variablen-ID des kumulativen Zählerstands der Einspeisung in kWh, `0` wenn nicht vorhanden |
| `energyKind` | empfohlen | `'counter'` für einen kumulativen Zählerstand |
| `measured` | nein | `true` (Standard bei fehlendem Feld), wenn die **Leistung** gemessen ist. `false`, wenn sie geschätzt oder berechnet ist. |
| `energyMeasured` | nein | `true` (Standard bei fehlendem Feld), wenn der **Zählerstand** gemessen ist. `false`, wenn er aus der Leistung hochgerechnet oder geschätzt ist. |
| `latency`, `authority`, `pollInterval` | empfohlen | wie auf Instanz-Ebene, in jede Zuordnung gespiegelt |
| `deviceSerial` | nein | Seriennummer des Geräts als Text, **nur wenn das Gerät sie kennt**. Dient dem EMS, dieselbe Hardware zu erkennen, wenn sie über zwei Module angebunden ist. Sonst weglassen, nie erfinden. |
| `phaseCurrentIDs` | nein | `[L1, L2, L3]`: Variablen-IDs der **gemessenen** Phasenströme in A, nur am Netzanschluss. Nie aus Leistung und Spannung geschätzt. |
| `slot` | nein | Unterscheidet Zuordnungen eines Zählers: `'total'` (alle Phasen zusammen) oder `'L1'`, `'L2'`, `'L3'` bei Messung je Phase |

### Funktionen und Vorzeichen

`function` nennt die Messstelle. Üblich sind `grid` (Netzanschluss), `house` (Hausverbrauch), `pv`, `battery` sowie
Verbraucher wie `heatpump`, `wallbox1`, `washer`. Welche Funktionen ein Konsument auswertet, entscheidet er selbst.

Die Richtung der Leistung ist verbindlich festgelegt, damit niemand raten muss:

- **`grid`:** **+ = Bezug aus dem Netz, − = Einspeisung.** Das EMS rechnet dies beim Einlesen in seine eigene Richtung um.
- **`house`:** **+ = Verbrauch.** Ein dauerhaft negativer Wert ist ein Richtungsfehler am Zähler und kein Messwert. Konsumenten
  drehen ihn nicht stillschweigend um.
- **Verbraucher** (Wärmepumpe, Wallbox, Waschmaschine …): **+ = Verbrauch.**
- **`pv` und `battery`:** kein festgelegtes Vorzeichen. Bei `pv` verwenden Konsumenten den Betrag.

`powerID` zeigt immer auf die Leistung **nach** einem eventuellen Invers-Schalter deines Moduls.

### Kumulative Zählerstände

`energyImportID` und `energyExportID` müssen **kumulative** Zählerstände sein, die nie zurückgehen. Ein Wert, der je Tag oder
je Vorgang auf 0 springt, ist kein Zählerstand. Archiviere die Variable mit dem Aggregationstyp „Zähler“.

### Geschätzt oder gemessen

Schätzt oder berechnet dein Modul Werte (Schätzzähler, Hochrechnung aus der Leistung), **melde `measured: false` und/oder
`energyMeasured: false`.** Solche Einträge dienen der Anzeige und der Rest-Rechnung, aber **nie der Regelung**: Das EMS
verwendet sie nicht als Netzanschluss- oder Hausverbrauchs-Messwert für Entscheidungen.

### Frische

Ein eigenes Feld für „zuletzt gesehen“ ist für Zähler nicht nötig. Konsumenten prüfen die Aktualisierungszeit der
Leistungsvariable (`VariableUpdated`). Schreibe die Variable deshalb nur, wenn wirklich ein neuer Wert vom Gerät kam. Meldet
das Gerät einen Wert als „nicht verfügbar“, schreibe **keine 0**, sondern lass die Variable alt werden.

## Beispiel

```php
public function GetFunctions(): string
{
    $id = fn(string $ident) => (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    return json_encode([
        'contractVersion' => '1.6',
        'contractType'    => 'meter',
        'instanceID'      => $this->InstanceID,
        'meter'           => 'mein_zaehler',
        'latency'         => 'realtime',
        'authority'       => 'auxiliary',
        'pollInterval'    => 10,
        'assignments'     => [[
            'contractType'   => 'meter',
            'slot'           => 'total',
            'function'       => 'grid',
            'label'          => IPS_GetName($this->InstanceID),
            'powerID'        => $id('power'),            // W, + = Bezug
            'energyImportID' => $id('energy_import'),    // kWh, kumulativ
            'energyExportID' => $id('energy_export'),    // kWh, kumulativ
            'energyKind'     => 'counter',
            'measured'       => true,
            'energyMeasured' => true,
            'latency'        => 'realtime',
            'authority'      => 'auxiliary',
            'pollInterval'   => 10,
        ]],
    ]);
}
```

## Checkliste

- [ ] `contractType: 'meter'` auf Instanz-Ebene **und** in jeder Zuordnung, `contractVersion: '1.6'`
- [ ] `powerID` in Watt, Vorzeichen nach der Tabelle oben
- [ ] Zählerstände kumulativ und archiviert (Aggregationstyp „Zähler“)
- [ ] Geschätzte oder hochgerechnete Werte als `measured: false` bzw. `energyMeasured: false` gekennzeichnet
- [ ] Keine 0 für „nicht verfügbar“ schreiben
- [ ] `GetFunctions()` schnell, ohne Gerätezugriff, während des Neuladens mit `ready: false`
- [ ] Dein Modul läuft ohne MeterHub und ohne EMS

## Weiter

Die Vorgaben für alle Vertragstypen und die Regeln der Fremdanbindung (Frische, Doppelanbindung, Steuern nur mit Freigabe)
stehen im Verbund-Manifest `SUITE.md`, Abschnitt „Fremdanbindung: Module anderer Autoren“. Ein Vorbild für den Aufbau einer
solchen Anleitung ist die für Wallbox-Module (`EIGENES_WALLBOX_MODUL.md` im Repository ChargerHub).
