# Forum-Beitrag für die IP-Symcon Community

**Kategorie:** dieselbe wie beim InverterHub (Kategorie 73 — dort liegt
„[Beta-Tester gesucht] InverterHub / Multi Wechselrichter …", Thema 144121).

**Titelvorschlag:**

> [Beta-Tester gesucht] MeterHub — ein Modbus-TCP-Modul für Energiezähler: Siemens PAC2200, Janitza UMG, Shelly Pro 3EM, go-e Controller, Eastron, Carlo Gavazzi, Phoenix Contact, WhatWatt u. a. (+ Netzwerksuche + virtuelle Zähler)

**Bilder:** `virtuelle-zaehler.png` an der markierten Stelle, `suite.png` im Abschnitt
„Die Modulfamilie". Beide liegen neben dieser Datei; Screenshots aus der eigenen Anlage
(Konfigurationsmaske, angelegte Variablen, Suchlauf) sind wie beim InverterHub sinnvoll —
die habe ich nicht, die musst Du beisteuern.

---

# MeterHub — ein Modbus-TCP-Modul für viele Energiezähler (+ Netzwerksuche + virtuelle Zähler)

Moin zusammen,

nach dem **InverterHub** für Wechselrichter kommt hier das Gegenstück für die andere Seite der
Anlage: **MeterHub** liest **Energiezähler direkt per Modbus TCP** aus — wieder ein gemeinsames
Treibergerüst statt eines eigenen Moduls je Hersteller. Wer den InverterHub kennt, findet
sich sofort zurecht: gleiche Bedienlogik, gleiche Konventionen, gleiche Netzwerksuche.

Drei Zähler sind an echter Hardware verifiziert und laufen produktiv, der Rest ist aus
Herstellerdoku, OpenEMS und euren Forum-Vorlagen abgeleitet. **Genau dafür suche ich
Rückmeldungen.**

## Die drei Module

- **MeterHub** — die Auslese-Instanz. Zählertyp wählen, IP eintragen, Datenpunkt-Gruppen
  aktivieren. Ein kuratierter Kern (Gesamtleistung, Ø Spannung/Strom, Frequenz, Bezug/
  Einspeisung) ist immer aktiv, alles Weitere je Gruppe zuschaltbar.
- **MeterHub Discovery** — durchsucht einen IP-Bereich und legt gefundene Zähler automatisch
  als Instanz an. Die Erkennung läuft über charakteristische Register mit
  Plausibilitätsprüfung (Spannung, Frequenz), nicht über Rateverfahren.
- **MeterHub Virtuell** — bildet **virtuelle Zähler aus der Verdrahtung** statt aus Formeln.
  Dazu gleich mehr, das ist der eigenwilligste Teil.

## Virtuelle Zähler: Verdrahtung statt Rechenfeld

Der klassische Weg wäre ein Eingabefeld „A − B − C". Das geht schief, sobald die Anlage wächst:
Man zieht einen Zähler zweimal ab, merkt es nicht, und der Hausverbrauch stimmt monatelang nicht.

MeterHub geht anders vor. Man trägt nicht die Rechnung ein, sondern **welcher Zähler hinter
welchem sitzt**. Summe und Rest ergeben sich daraus von selbst:

> **[hier `virtuelle-zaehler.png` einfügen]**

Der Gewinn ist nicht Bequemlichkeit, sondern Fehlersicherheit: Weil jeder Zähler im Baum genau
**einen** Platz hat, *kann* er nicht doppelt abgezogen werden — dafür müsste er an zwei Stellen
hängen, und das lässt die Struktur nicht zu. Was die Struktur nicht ausschließt, meldet die
Prüfung (derselbe Datenpunkt in zwei Zeilen, Ringschlüsse, unbekannte Elternknoten, gemischte
Einheiten) — und solange etwas offen ist, wird bewusst **nicht gerechnet**. Lieber kein Wert
als ein falscher.

Die Quellen müssen dabei **keine MeterHub-Zähler** sein: Ein Suchlauf durchkämmt die
Installation nach Datenpunkten mit W-/kW- bzw. kWh-Profil und findet so auch die Messwerte von
Steckdosen, Licht- und Jalousieschaltern. Er gruppiert sie je Gerät, übernimmt den Gerätenamen
als Bezeichnung und schlägt sie nur vor — gespeichert wird erst mit „Übernehmen". Vier Filter
(Suchbereich, Namensbestandteil, nur mit Energiezähler, nur kürzlich aktualisiert) halten die
Liste in einer gewachsenen Installation beherrschbar.

Anlegen lässt sich so ein virtueller Zähler direkt **aus einer Zählerinstanz heraus**: die
weiteren beteiligten Instanzen auswählen, Rolle festlegen (übergeordnet → Summe *und* Rest,
gleichrangig → nur Summe), fertig.

## Unterstützte Zähler

**Legende:** ✅ an realer Anlage bestätigt · 🔧 Registerkarte gegen Handbuch abgeglichen,
Hardware-Rückmeldung fehlt · 🧪 aus Doku/Referenz implementiert (Feldrückmeldung willkommen)

| Zähler | Status | Anmerkung |
|---|---|---|
| **Siemens SENTRON PAC2200** | ✅ | Produktiv im Einsatz. Energie als 64-Bit-Double ab Register 801; die Lücke zwischen Register 41 und 55 erzwingt zwei getrennte Blocklesungen — sonst „Illegal Data Address" |
| **Shelly Pro 3EM** | ✅ | Am Gerät verifiziert. Inkl. **Energie je Phase**, damit jede Phase als eigener Verbraucher zählen kann |
| **go-e Controller** | ✅ | Am Gerät verifiziert. Kern aus der Kategorie Grid; zuschaltbar die Stromsensoren 1–6 und die Kategorien Home/Car/Relais/Solar/Akku (je Leistung + Energie). Modbus TCP erst in der go-e-App aktivieren |
| **Janitza UMG 604 / 605-PRO / 509-PRO / 512-PRO / 806 / 96PA / 801** | 🔧 | Gemeinsame klassische Registerkarte ab 19000, per Handbuch als identisch bestätigt |
| **Janitza UMG 800** | 🔧 | Modbus-Zuordnung ist frei konfigurierbar — der Treiber folgt der **Werksvorgabe**. Wurde sie in GridVis geändert, stimmen die Adressen nicht |
| **Eastron SDM72D-M v2 / SDM630 v2** | 🧪 | Float32 über FC 0x04, Energie in kWh |
| **Carlo Gavazzi EM24 / EM300 / ET340** | 🧪 | Int32 wortgetauscht (CDAB) mit Skalierung, Registerkarte aus OpenEMS |
| **Phoenix Contact EEM-EM375 / EEM-XM** | 🧪 | Aus Forum-Vorlagen. EEM-EM375 nutzt oft Unit-ID **255**, EEM-XM meist 1 |
| **WhatWatt** | 🧪 | Getrennte Bezugs- und Abgabeleistung → Gesamtleistung wird daraus gebildet |
| **Socomec Countis** | 🧪 | Experimentell, Skalierung der Leistung noch unsicher — bitte gegen die Geräteanzeige prüfen |
| **MBS Professional 3-75** | 🧪 | Experimentell, aus Symcon-Vorlagen |

Bewusst **nicht** gebaut: Die kursierende „Siemens Sentron"-Vorlage beschreibt Leistungsschalter
und Schutzgeräte, keine Zähler.

Alle Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch für den Abgleich mit dem Handbuch.

## Das Wichtigste in Kürze

- 🔌 **Ein Modul, viele Hersteller** — austauschbare Treiber, gemeinsame Konventionen.
- 🔍 **Netzwerksuche** über Port 502 mit Abbrechen-Knopf; erkennt PAC2200, Janitza (klassisch
  und 800er), Shelly, go-e Controller, Carlo Gavazzi, WhatWatt, Phoenix EEM-EM375 und Eastron. Zähler hinter
  RTU-Gateways (Socomec, MBS) findet die Suche nicht zuverlässig — deren Unit-ID ist frei
  wählbar; die legt man von Hand an.
- 🏷️ **Funktionszuordnung** — jedem Zähler lässt sich sagen, *was* er misst: Netzanschluss,
  Hausverbrauch, Wärmepumpe, Wallbox 1–5, Garage, Waschmaschine, Trockner, Backofen …
  (29 Einträge). Die Weiche davor ist der **Messmodus**: misst das Gerät einen Verbraucher über
  drei Phasen, oder drei unabhängige Verbraucher je Phase? Daraus ergibt sich, ob eine Funktion
  zugeordnet wird oder drei. Die Zuordnung benennt die Variablen um und setzt passende Icons.
- 🖼️ **Zusammenspiel mit der InverterHub-Stromflusskachel und dem Sankey** — zugeordnete Zähler
  erscheinen dort automatisch als Verbraucher, Netz und Hauslast werden aus den Funktionen
  „Netzanschluss" und „Hausverbrauch" gespeist. Das gilt für echte **und** virtuelle Zähler.
- 🧮 **Virtuelle Zähler** aus der Verdrahtung (siehe oben).
- 🔄 **Invers-Schalter** für die Wirkleistung und Rolle „Netz-/NAP-Zähler" vs. „Unterzähler",
  damit die Vorzeichenkonvention zur eigenen Einbaulage passt (**+ = Bezug, − = Einspeisung**).
- ⚙️ **Energie-Einheit** wahlweise kWh oder Wh, sowie ein **WordSwap-Schalter** (CDAB) für
  Geräte mit gedrehter Wortreihenfolge.

## Anschluss-Besonderheiten (kurz)

- **Shelly Pro 3EM:** Modbus TCP muss am Gerät erst aktiviert werden (Einstellungen → Modbus).
  Gelesen wird über FC 0x04, Float wortgetauscht, Wire-Adresse = Doku-Nummer − 30000. Wer die
  Herstellerdoku wörtlich nimmt, bekommt Modbus-Ausnahmen — das war hier ein längerer Abend.
- **Eastron / Phoenix:** sprechen meist Modbus RTU und hängen an einem RTU/TCP-Gateway —
  **dessen** IP eintragen, nicht die des Zählers.
- **go-e Controller:** Modbus muss in der go-e-App freigeschaltet werden (Internet → Erweiterte
  Einstellungen → Modbus); danach die Einstellung ggf. einmal aus- und wieder einschalten, sonst
  bleibt Port 502 zu. Unbelegte Register beantwortet das Gerät mit NaN statt einer Fehlermeldung —
  der Treiber fängt das ab.
- **PAC2200:** antwortet oft unabhängig von der Unit-ID.
- **Janitza UMG 800:** siehe Tabelle — Werksvorgabe vorausgesetzt.

## Installation

Über die **Modulverwaltung** → Modul hinzufügen → GitHub-Repository:

`https://github.com/DG65/MeterHub` (Zweig **beta**)

Im Symcon Module Store ist das Modul noch nicht — der Beta-Zweig ist der schnellere Weg zu
Korrekturen.

## Status und was ich brauche

Das Ganze ist **Beta**. Rückmeldungen sind ausdrücklich erwünscht, besonders zu den mit 🔧/🧪
markierten Zählern. Bitte mit **Hersteller, Modell und betroffenem Register/Wert** melden; bei
Vorzeichen-Themen hilft ein kurzer Vergleich mit der Geräteanzeige enorm.

Zwei Zähler stehen konkret auf der Liste, mir fehlen aber die Registertabellen:

- **ABB B23 / B24**
- **Schneider iEM3000-Reihe**

Wer eine Modbus-Adressliste oder eine funktionierende Symcon-Vorlage dafür hat: her damit, dann
baue ich die Treiber. Geraten wird nicht — falsche Registeradressen liefern still falsche
Werte, und das ist schlimmer als ein fehlender Zähler.

## Die Modulfamilie

MeterHub steht nicht allein. Über die Jahre ist ein ganzer Baukasten entstanden, dessen Teile
zusammenarbeiten — aber **jedes Modul läuft auch für sich**. Es gibt keine
Pflichtabhängigkeiten: Fehlt der Partner, fällt nur dessen Zusatzfunktion weg.

> **[hier `suite.png` einfügen]**

Konkret heißt das für MeterHub: Ohne InverterHub misst er einfach Zähler. Mit InverterHub
wandern die zugeordneten Verbraucher automatisch in die Stromflusskachel und den Sankey. Und
das EMS kann beides über eine gemeinsame Schnittstelle abfragen — `MHUB_GetFunctions($id)`
liefert Messmodus, Zuordnungen und Variablen-IDs als JSON, `MHUBV_GetFunctions($id)` für
virtuelle Zähler denselben Vertrag.

## Was noch kommt

Das Modul ist als wachsendes Projekt angelegt:

- **SMA Sunny Home Manager 2.0 und SMA Energy Meter** — die spannendste offene Baustelle. Beide
  sprechen kein Modbus, sondern senden per Speedwire-Multicast (UDP). Das wird deshalb ein
  eigenes Empfängermodul auf dem Multicast-Socket von IP-Symcon. Konzept steht, Umsetzung hängt
  an einem Tester mit SMA-Anlage — Freiwillige vor.
- **Chint DTSU666** — der Zähler, der bei vielen Huawei-, Sungrow- und Growatt-Anlagen mitgeliefert
  wird. Guter nächster Kandidat.
- **ABB B23/B24 und Schneider iEM3000** — sobald die Registerdaten da sind (siehe oben). Weitere
  Vorschläge mit Doku sind willkommen.
- **Umstieg ohne Datenverlust** — wer heute Zähler als einzelne Modbus-Datenpunkte betreibt,
  soll auf MeterHub wechseln können, **ohne die Archivwerte zu verlieren**. Dafür entsteht ein
  eigenes Modul, weil es nicht nur MeterHub betrifft.
- **Wallboxen** bekommen mit ChargerHub ein eigenes Gegenstück nach demselben Muster.

Danke fürs Lesen — und fürs Testen! 🙌
