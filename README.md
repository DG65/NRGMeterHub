# MeterHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.24.6--beta.1-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGMeterHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGMeterHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

IP-Symcon-Modul, das Energiezähler verschiedener Hersteller direkt per **Modbus TCP**
ausliest — ein gemeinsames Treibergerüst statt eines Moduls pro Hersteller, analog
zum [InverterHub](https://github.com/DG65/NRGInverterHub).

**Teil des NRG-Stack** — welche Modulstände zusammenpassen, listet
[SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).

**Status: Beta.** Die Register-Zuordnungen basieren auf den öffentlich verfügbaren
Modbus-Protokolldokumenten der Hersteller (Siemens Gerätehandbuch L1V30415167A, Janitza
Modbus-Adressenliste UMG 604-PRO). Rückmeldungen zu falschen/fehlenden Werten sind
willkommen — bitte mit Zählertyp und betroffenem Register melden.

## Unterstützte Zähler

| Zähler | Umfang | Anmerkung |
|---|---|---|
| **Siemens SENTRON PAC2200** | Summen-Wirkleistung, Ø Spannung/Strom, Frequenz, Energie Bezug/Abgabe (Tarif 1+2), optional U/I/P/Q/S je Phase, Leistungsfaktor | Float32-Messgrößen ab Register 1, Energiezähler als **64-Bit-Double** ab Register 801. FC 0x03. |
| **Janitza UMG 604 / 605 / 509 / 512 / 806 / 96PA / 801** | Summen-Wirkleistung, Ø Spannung/Strom, Frequenz, Energie Bezug/Abgabe, optional U/I/P/Q/S je Phase, cos φ, Netzqualität (THD, Drehfeld) | Gemeinsame **klassische Janitza-Registerkarte**: Float32 ab Register 19000, Energie in Wh bei 19068/19076, THD ab 19110, Drehfeld 19052. Ø-Werte werden aus den Phasen berechnet. FC 0x03. |
| **Janitza UMG 800** | wie oben | Eigene, **frei konfigurierbare** Modbus-Karte — der Treiber folgt der ausgelieferten Werksvorgabe (Summe P 19030, Frequenz 19054, Bezug 19072, Abgabe 19080). Wurde die Zuordnung im Gerät (GridVis) geändert, stimmen die Adressen ggf. nicht. FC 0x03. |
| **Eastron SDM72D-M v2 / SDM630 v2** | Summen-Wirkleistung, Ø U/I, Frequenz, Energie Bezug/Abgabe, optional U/I/P/Q/S je Phase, Leistungsfaktor, L-L-Spannung, Neutralleiterstrom | **FC 0x04** (Input-Register), Float32 Big-Endian ab Reg. 0, Energie in kWh (Reg. 72/74). SDM630 nutzt dieselbe Karte. Modbus RTU → über RTU/TCP-Gateway. |
| **Carlo Gavazzi EM24 / EM300 / ET340** | Summen-Wirkleistung, Ø U/I, Frequenz, Energie Bezug/Abgabe, optional U/I/P/Q/S je Phase | **FC 0x04**, Int32 **wortgetauscht (CDAB)** mit Skalierung (U ×0,1 · I ×0,001 · P ×0,1 · f ×0,1 · Energie ×0,1 kWh). Registerkarte nach OpenEMS. |
| **WhatWatt** | Summen-Wirkleistung (Bezug − Abgabe), Ø U/I, Energie Bezug/Abgabe (+ Tarif 1/2), optional U/I/P je Phase | **FC 0x04**, Float32 + 64-Bit-Double (Tarif-Energie), Big-Endian. Modbus TCP direkt. Getrennte Bezugs-/Abgabeleistung (501/505). |
| **Phoenix Contact EEM-EM375 / EEM-XM** | Summen-Wirkleistung, Ø U/I, Bezugsenergie, optional U/I/P je Phase | **FC 0x04**, Float32. EM375 ab Reg. 4096 (Unit-ID oft 255), EEM-XM ab Reg. 32774 (Unit-ID meist 1). Bei EEM-XM ggf. den WordSwap-Schalter nutzen. |
| **Shelly Pro 3EM** | Summen-Wirkleistung, Ø U/I, Frequenz, Energie Bezug/Abgabe, optional U/I/P je Phase sowie **Energiezähler je Phase** | **FC 0x04**, Float32 **wortgetauscht (CDAB)**; Wire-Adressen = Doku − 30000 (Messwerte ab 1011, Energie-Summe 1162/1164, je Phase 1182/1184 · 1202/1204 · 1222/1224). An echtem Gerät (Gen3) verifiziert. **Modbus TCP am Gerät aktivieren** (Einstellungen → Modbus, Port 502). |
| **go-e Controller** | Kern aus der Kategorie **Grid** (Wirkleistung, Energie Bezug/Abgabe), Ø U/I, optional U je Phase + N, I je Phase + N, **Stromsensoren 1–6** (I/P/cos φ) und die Kategorien **Home/Car/Relais/Solar/Akku** (Leistung + Energie Ein/Aus) | **FC 0x04**, Float32/Float64 Big-Endian; Wire-Adresse = Doku − 30001 (Spannungen ab 1000, Sensoren ab 1010, Kategorie-Blöcke im 26er-Raster ab 1046). Keine Frequenz (Register nicht implementiert); unbelegte Register liefern NaN statt Fehler und werden verworfen. An echtem Gerät verifiziert. **Modbus TCP am Gerät aktivieren** (go-e-App: Internet → Erweiterte Einstellungen → Modbus; danach ggf. einmal aus-/einschalten). Die go-e-**Wallboxen** bedient das Schwestermodul ChargerHub. |

| **Inexogy / Discovergy** (Cloud) | Bezug/Einspeisung (kumulativ, kWh), Gesamtleistung, optional Leistung und Spannung je Phase | **Kein Modbus** — Cloud-API (`api.inexogy.com`) über **OAuth 1.0a**. E-Mail/Passwort einmal beim Einrichten, danach nur Zugriffs-Token (kein Klartext-Passwort). Für das abrechnungsverbindliche iMSys am Netzübergabepunkt; als solches kennzeichnen (Checkbox oben). Skalierung aus dem Alt-Modul verifiziert und gegen echte Zählerwerte geprüft; der OAuth-Handshake ist am eigenen Konto zu bestätigen. |

> ⚠️ **go-e Controller und Überschussladen — Zwei-Regler-Warnung:** Der Controller ist nicht
> nur Messzentrale. Je nach Konfiguration regelt er die go-e-Wallboxen **selbst**
> (PV-Überschussladen, Lastbegrenzung — eine geräteinterne Regelschleife). Wer stattdessen ein
> Energiemanagement die Wallboxen steuern lässt, muss die interne Regelung bewusst deaktivieren
> — sonst arbeiten zwei Regler gegeneinander an derselben Wallbox. Umgekehrt gilt: Regelt der
> Controller, darf das Energiemanagement die betroffenen Wallboxen nur **lesend** einbinden.
> Dieses Modul liest ausschließlich und ist von dem Konflikt nicht betroffen. Der Regelzustand
> ist über die Modbus-Karte des Controllers **nicht** sichtbar — er liegt an den Wallboxen
> selbst (go-e-Charger-API: `fup` = usePvSurplus, `loe` = Lastmanagement, `modelStatus` mit
> Klartextgrund wie *ChargingBecausePvSurplus*). Die passende Statusvariable gehört daher in
> ChargerHub, nicht hierher.

Die Janitza-Modelle mit klassischer Karte sind funktional identisch — der Zählertyp im
Formular dient nur der richtigen Beschriftung.

### Experimentell (noch nicht an echter Hardware geprüft)

| Zähler | Anmerkung |
|---|---|
| **Socomec Countis** (E23/E24/E27/E28/E34/E44) | FC 0x03; U/I/f als UInt32, P/Q als Int32, Energie UInt32. Skalen aus OpenEMS abgeleitet — **v. a. die Leistungs-Skala am Gerät prüfen**. |
| **MBS Professional 3-75** | M-Bus→Modbus-Gateway, FC 0x03. Bezug/Abgabe (kWh), Wirkleistung, Spannung, Frequenz. Integer-Typgrößen aus den Symcon-Vorlagen abgeleitet. |

Bei experimentellen Zählern die Messwerte gegen die Geräteanzeige abgleichen; bei
unplausiblen Werten helfen der **WordSwap**- bzw. **Invers**-Schalter.

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch.

## Module in diesem Repository

### MeterHub

Die eigentliche Datenauslese-Instanz. Ein Modul, ein `Zählertyp`-Auswahlfeld — je nach
gewähltem Zähler werden die passenden Datenpunkt-Gruppen (Schalter) und Register
freigeschaltet. Architektur:

- **`MHUB_ModbusTcpClient`** — gemeinsame Modbus-TCP-Grundfunktionen (Read Holding/Input
  Register, Datentyp-Hilfen inkl. Float32 und 64-Bit-Double), von allen Treibern genutzt.
- **`MHUB_MeterDriverInterface`** — Vertrag, den jeder Zähler-Treiber erfüllt (Basisvariablen,
  optionale Gruppen, Profile, `readFast`/`readSlow`). Zähler werden nur gelesen.
- **Treiber je Registerkarte** — `MHUB_Pac2200Driver` (Siemens), `MHUB_JanitzaClassicDriver`
  (klassische Janitza-Karte, deckt UMG 604/605/509/512/806/96PA/801 ab) und `MHUB_Umg800Driver`
  (UMG 800). Jeder Treiber kapselt Registeradressen, Datentypen und Blockaufteilung.

Alle globalen Klassennamen tragen bewusst den `MHUB_`-Präfix (Verbund-Konvention seit
25.07.2026) — ohne Präfix kollidieren gleichnamige Hilfsklassen, sobald ein Konsument (das
EMS) mehrere NRG-Stack-Module im selben PHP-Prozess lädt.

Einrichtung: Instanz anlegen, Zählertyp wählen, IP-Adresse (und bei Bedarf Port/Unit-ID)
eintragen, gewünschte Datenpunkt-Gruppen aktivieren, übernehmen.

**Rolle des Zählers:** Über „Rolle des Zählers" wird die Vorzeichen-Semantik festgelegt —
*Netz-/NAP-Zähler* (+ Bezug / − Einspeisung, als Eingang fürs EMS gedacht) oder
*Unterzähler / Verbraucher* (immer positiv). Stimmt die Richtung an der eigenen Anlage
nicht, dreht der Schalter **„Wirkleistung invertieren"** das Vorzeichen der
Gesamt-Wirkleistung um.

**Energie-Einheit (kWh/Wh):** Standardmäßig werden Energiewerte in kWh ausgegeben. Der
Schalter „Energie in Wh statt kWh ausgeben" stellt sie auf die Basiseinheit Wh um —
konsistent zur Leistung (W); die neue IP-Symcon-Darstellung skaliert dann selbst auf
Wh/kWh/MWh. Bestehende Instanzen bleiben ohne Umschalten bei kWh (kein Sprung in der
Historie).

**Abfragetakt:** Momentanwerte (Leistung, Spannung, Strom, Frequenz) werden im Schnell-
Intervall gelesen, die Energiezähler im Langsam-Intervall.

### MeterHubDiscovery

Ein **Configurator**-Modul, das einen IP-Bereich im lokalen Netz nach Zählern auf
Modbus-TCP-Port 502 durchsucht:

1. Start- und End-IP eintragen (wird beim Anlegen anhand des eigenen Netzwerks vorbelegt,
   bleibt aber änderbar), optional eine Namens-Vorlage für neu anzulegende Instanzen.
2. „Netzwerk durchsuchen" klicken — nicht-blockierende, parallele Suche auf Port 502.
3. Für jede offene IP wird der Zählertyp anhand dokumentierter Standard-Unit-IDs und einer
   Plausibilitätsprüfung (Netzfrequenz 45–65 Hz **und** eine plausible Spannung) erkannt:
   PAC2200 (Frequenz auf Reg. 55), klassische Janitza-Karte (Frequenz auf 19050) und
   UMG 800 (Frequenz auf 19054) liegen jeweils auf unterschiedlichen Registern und lassen
   sich so trennen. Die klassischen Janitza-Modelle teilen sich eine Signatur — Discovery
   schlägt stellvertretend „Janitza UMG (klassische Map)" vor; das exakte Modell lässt sich
   danach in der Instanz einstellen (die Registerkarte ist ohnehin identisch).
4. Treffer erscheinen in der Ergebnistabelle — Klick auf „Erstellen" legt eine
   `MeterHub`-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Zählertyp an.

**IPs ignorieren:** Adressen in dieser Liste werden bei der Suche komplett übersprungen —
gedacht für andere Modbus-Geräte, die sonst Probe-Zeit kosten. Mehrere IPs Komma-getrennt.

**Namens-Vorlage:** leer lassen für den Standard „Zählertyp + laufende Nummer", oder ein
eigenes Muster mit den Platzhaltern `{zaehler}` `{ip}` `{unitid}` `{nr}` eintragen (z. B.
`{zaehler} Keller ({ip})`).

## Funktionszuordnung (welcher Verbraucher hängt hier?)

Im Panel **„Funktionszuordnung"** lässt sich festlegen, *was* ein Zähler eigentlich misst.
Ausgangspunkt ist der **Messmodus** — er entscheidet, wie zugeordnet wird:

| Messmodus | Bedeutung | Zuordnung |
|---|---|---|
| **Dreiphasig** | ein Verbraucher über alle 3 Phasen (Netzanschluss, Wärmepumpe, …) | **eine** Funktion für den ganzen Zähler |
| **Einphasig getrennt** | 3 unabhängige einphasige Verbraucher | **je Phase** eine eigene Funktion |

Zur Auswahl stehen, nach Bereichen gruppiert:

- **Anlage:** Netzanschluss, Hausverbrauch, PV-Erzeugung, Batterie
- **Wärme/Klima:** Wärmepumpe, Heizung/Heizstab, Warmwasser, Klimaanlage, Lüftung
- **Mobilität:** Wallbox 1–5, Garage
- **Haushaltsgeräte:** Waschmaschine, Trockner, Spülmaschine, Backofen, Herd,
  Kühl-/Gefriergerät, Küche (gesamt)
- **Weitere Bereiche:** Pool, Sauna, Beleuchtung, Server/Netzwerk, Werkstatt,
  Sonstiger Verbraucher

Jeweils mit optionaler **eigener Bezeichnung** (z. B. „Garage hinten").

Die Zuordnung bewirkt dreierlei:

1. **Benennung + Icon** — betroffene Variablen heißen dann z. B. „Wärmepumpe — Wirkarbeit
   Bezug" und bekommen ein passendes Icon.
2. **Maschinenlesbar** — `MHUB_GetFunctions($id)` liefert Modus, Zuordnungen und die
   Variablen-IDs (Leistung/Bezug/Einspeisung) als JSON, sodass EMS oder Kacheln die
   Zuordnung automatisch übernehmen können.
3. **Optionale Sammel-Variablen** je Funktion (Kategorie „Funktionen"), die den zugeordneten
   Kanal spiegeln — praktisch für Charts und Automationen.

Für getrennte Energiezähler je Phase zusätzlich im Panel „Datenpunkte" die Gruppe
**„Energie je Phase"** aktivieren (beim Shelly Pro 3EM verfügbar).

## Vorzeichen-Konvention

Modulweit gilt: **+ = Bezug** aus dem Netz, **− = Einspeisung**. PAC2200 und UMG604 melden
ihre Summen-Wirkleistung bereits vorzeichenbehaftet; passt die Richtung durch Einbaulage
oder Verdrahtung nicht, hilft der Invers-Schalter.

### MeterHubVirtual (virtuelle Zähler)

Bildet **virtuelle Zähler aus der Verdrahtung** statt aus Formeln. Statt Rechenoperationen zu
konfigurieren, gibt man je Zähler an, **hinter welchem er sitzt**. Daraus ergibt sich je Knoten
mit Untergeordneten automatisch:

- **Summe untergeordnet** — die Summe aller direkt darunter hängenden Zähler
- **Rest** — eigener Zähler minus Summe der Untergeordneten (nur mit eigenem Zähler)

*Beispiel:* „Hausanschluss" (eigener Zähler) mit den untergeordneten „Wärmepumpe" und
„Wallbox" ergibt automatisch „Hausanschluss: Leistung Rest" — alles, was weder Wärmepumpe noch
Wallbox verbraucht.

**Warum keine freie Formel:** Weil jeder Zähler im Baum genau **einen** Platz hat, kann er
nicht doppelt abgezogen werden — ein doppelter Abzug müsste denselben Zähler an zwei Stellen
hängen, und das lässt die Struktur nicht zu. Was der Baum nicht verhindert, meldet die Prüfung:
derselbe Datenpunkt in zwei Zeilen, Ringschlüsse, unbekannte Elternknoten, doppelte Kürzel und
**gemischte Einheiten** (W neben kW ergäbe still falsche Werte). Solange etwas offen ist, wird
bewusst **nicht gerechnet** — lieber kein Wert als ein falscher.

Jeder Knoten kann eine **Funktion** bekommen (Netzanschluss, Hausverbrauch, Wärmepumpe …).
Über `MHUBV_GetFunctions($id)` — denselben Vertrag wie das Hauptmodul — erscheinen virtuelle
Zähler damit automatisch in der InverterHub-Stromflusskachel und im Sankey.

**Zähler automatisch finden:** Der Knopf „🔎 Zähler im System suchen" durchsucht die
Installation nach Datenpunkten mit W-/kW- bzw. kWh-Profil — also nach den Messwerten von
Steckdosen, Licht- und Jalousieschaltern, Zwischenzählern usw. Die Funde werden **je Gerät
gruppiert** (Leistung und Energie desselben Geräts landen in einer Zeile), die **Bezeichnung
kommt aus dem Gerätenamen**, und das Kürzel wird daraus abgeleitet und bei Namensgleichheit
eindeutig gemacht.

Dabei wird geprüft: Datenpunkte ohne verwertbare Einheit werden übersprungen, bereits
eingetragene nicht doppelt vorgeschlagen, und **Ausgaben virtueller Zähler bleiben
ausgeschlossen** — sonst flösse ein berechneter Wert wieder als Quelle ein. Ebenso bleiben
**Variablen aus bekannten NRG-Stack-Modulen** außen vor (EMS, InverterHub, ChargerHub,
Prognose, Tibber Grid Rewards, StromGedacht, HeishaMon, Tessie, MigrationsHub, Gleitender
Mittelwert, SteuerboxHub, GoodweET) — sie sind dort schon korrekt eingebunden, und ein
berechneter Wert (z. B. eine vom EMS ermittelte Hauslast) dürfte sonst versehentlich in eine
Berechnung zurückfließen, aus der er selbst stammt. Zusätzlich wird gemeldet, wenn ein
Energiezähler nicht archiviert ist oder eine Leistung seit über einer Woche nicht aktualisiert
wurde.

In einer gewachsenen Installation findet der Suchlauf schnell dreistellig viele Datenpunkte.
Vier **Filter** engen ihn ein — Suchbereich (nur unterhalb eines Objekts), Namensbestandteil,
„nur Geräte mit Energiezähler" (blendet Schalter aus, die bloß die Momentanleistung melden) und
„nur in den letzten 7 Tagen aktualisiert" (blendet Karteileichen aus). Sie wirken **sofort beim
Klick**, auch ohne vorher zu übernehmen. Das Ergebnis nennt den verwendeten Suchbereich und
zählt auf, was woran gescheitert ist — wurde alles wegfiltriert, sagt es das ausdrücklich.

Die Funde erscheinen zunächst **auf oberster Ebene und nur als Vorschlag in der geöffneten
Maske** — gespeichert wird erst mit „Übernehmen". Die **Verdrahtung setzt man von Hand**: Welcher
Zähler hinter welchem sitzt, weiß nur die Anlage — und genau diese Entscheidung ist es, die den
doppelten Abzug ausschließt.

Geliefert werden **Leistung (W)** und **Energie (kWh)** jeweils als Summe und Rest; die
Variablen werden archiviert.

**Aus einer Zählerinstanz heraus anlegen:** Meist entsteht ein virtueller Zähler aus zwei, drei
echten Zählern, die man ohnehin gerade vor sich hat. Deshalb hat jede MeterHub-Instanz das Panel
„🧮 Virtueller Zähler": die weiteren beteiligten Instanzen auswählen, die Rolle festlegen —
*übergeordnet* (die anderen hängen dahinter, ergibt Summe **und** Rest) oder *gleichrangig*
(alle werden nur addiert) — und anlegen. Die Verdrahtung wird fertig vorbelegt, die
Variablen-IDs muss man nicht von Hand zusammensuchen.

Zwei Dinge macht der Generator bewusst *nicht*: Er legt **keinen zweiten** virtuellen Zähler an,
wenn dieses Gerät schon in einem steckt — dann ist die richtige Stelle die vorhandene Instanz,
sonst beginnt doppelte Buchführung. Und er überträgt **keine Funktionszuordnung**, denn belegte
der virtuelle Knoten dieselbe Funktion wie der echte Zähler, erschiene der Verbraucher in Kachel
und Sankey doppelt. Typisch ist, dem übergeordneten Knoten anschließend „Hausverbrauch" zu
geben: der Rest ist dann alles, was nicht auf die untergeordneten Zähler entfällt.

Umgekehrt zeigt das Panel bei einem bereits eingebundenen Zähler an, **in welchem virtuellen
Zähler er steckt** — und unter welchem Kürzel.

## Verwandtes Projekt: InverterHub

MeterHub hat ein eng verwandtes Schwester-Repository:

- **MeterHub** (dieses Repo): Energiezähler per Modbus TCP
- **[InverterHub](https://github.com/DG65/NRGInverterHub)**: Wechselrichter per Modbus TCP —
  gleiches Treibergerüst, gleiche Bedienlogik

Beide Module sind **eigenständig lauffähig**; die Kopplung ist **beidseitig optional**. Fehlt
das jeweils andere Modul, entfallen lediglich die Zusatzfunktionen — es bricht nichts.

| Berührungspunkt | Was passiert |
|---|---|
| **Kombinierte Gerätesuche** | Ist MeterHub installiert, bietet die Netzwerksuche des `InverterHubDiscovery` gefundene **Energiezähler** gleich als MeterHub-Instanz zum Anlegen an — eine Suche findet Wechselrichter *und* Zähler. Umgekehrt sucht `MeterHubDiscovery` weiterhin eigenständig nur nach Zählern. |
| **Verbraucher-Kreise der Stromflusskachel** | Die `InverterHubTile` übernimmt die **Funktionszuordnung** dieses Moduls automatisch als Verbraucher-Kreise (Art, Bezeichnung, Leistungsvariable). Zusätzlich speist ein Zähler mit Funktion **„Netzanschluss"** die Netz-Leistung und einer mit **„Hausverbrauch"** die gemessene Hauslast — die Kachel läuft dadurch auch ganz ohne InverterHub-Instanz. |

Grundlage dafür ist die Abfragefunktion `MHUB_GetFunctions($id)` (siehe
[Funktionszuordnung](#funktionszuordnung-welcher-verbraucher-hängt-hier)), die Modus,
Zuordnungen und Variablen-IDs als JSON liefert.

## Installation

Über die IP-Symcon Modulverwaltung „Hinzufügen" mit der URL dieses Repositories:

```
https://github.com/DG65/NRGMeterHub
```

Solange sich MeterHub in der Testphase befindet, in der Modulverwaltung den Zweig **`beta`**
auswählen — dort liegt der jeweils aktuelle Stand. Der Zweig `main` bleibt der stabile Kanal.

## Mitwirken / Fehler melden

Rückmeldungen zu falschen Registerwerten, fehlenden Datenpunkten oder neuen unterstützten
Zählern gerne als Issue auf GitHub. Besonders hilfreich: Zählertyp, betroffenes
Register/Ident und beobachteter vs. erwarteter Wert.

## Lizenz

PolyForm Noncommercial 1.0.0 — private/nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Vollständiger Text:
[LICENSE](LICENSE). Spenden sind willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
