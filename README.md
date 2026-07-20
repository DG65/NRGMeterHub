# MeterHub

IP-Symcon-Modul, das Energiezähler verschiedener Hersteller direkt per **Modbus TCP**
ausliest — ein generisches Treiber-Framework statt eines Moduls pro Hersteller, analog
zum [InverterHub](https://github.com/DG65/InverterHub).

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
| **Eastron SDM72D-M v2** | Summen-Wirkleistung, Ø U/I, Frequenz, Energie Bezug/Abgabe, optional U/I/P/Q/S je Phase, Leistungsfaktor, L-L-Spannung, Neutralleiterstrom | **FC 0x04** (Input-Register), Float32 Big-Endian ab Reg. 0, Energie in kWh (Reg. 72/74). Spricht Modbus RTU → über RTU/TCP-Gateway. |
| **WhatWatt** | Summen-Wirkleistung (Bezug − Abgabe), Ø U/I, Energie Bezug/Abgabe (+ Tarif 1/2), optional U/I/P je Phase | **FC 0x04**, Float32 + 64-Bit-Double (Tarif-Energie), Big-Endian. Modbus TCP direkt. Getrennte Bezugs-/Abgabeleistung (501/505). |
| **Phoenix Contact EEM-EM375 / EEM-XM** | Summen-Wirkleistung, Ø U/I, Bezugsenergie, optional U/I/P je Phase | **FC 0x04**, Float32. EM375 ab Reg. 4096 (Unit-ID oft 255), EEM-XM ab Reg. 32774 (Unit-ID meist 1). Bei EEM-XM ggf. den WordSwap-Schalter nutzen. |

Die Janitza-Modelle mit klassischer Karte sind funktional identisch — der Zählertyp im
Formular dient nur der richtigen Beschriftung.

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch.

## Module in diesem Repository

### MeterHub

Die eigentliche Datenauslese-Instanz. Ein Modul, ein `Zählertyp`-Auswahlfeld — je nach
gewähltem Zähler werden die passenden Datenpunkt-Gruppen (Checkboxen) und Register
freigeschaltet. Architektur:

- **`ModbusTcpClient`** — gemeinsame Modbus-TCP-Grundfunktionen (Read Holding/Input
  Register, Datentyp-Hilfen inkl. Float32 und 64-Bit-Double), von allen Treibern genutzt.
- **`MeterDriverInterface`** — Vertrag, den jeder Zähler-Treiber erfüllt (Basisvariablen,
  optionale Gruppen, Profile, `readFast`/`readSlow`). Zähler werden nur gelesen.
- **Treiber je Registerkarte** — `Pac2200Driver` (Siemens), `JanitzaClassicDriver`
  (klassische Janitza-Karte, deckt UMG 604/605/509/512/806/96PA/801 ab) und `Umg800Driver`
  (UMG 800). Jeder Treiber kapselt Registeradressen, Datentypen und Blockaufteilung.

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

**Polling:** Momentanwerte (Leistung, Spannung, Strom, Frequenz) werden im Schnell-
Intervall gelesen, die Energiezähler im Langsam-Intervall.

### MeterHubDiscovery

Ein **Configurator**-Modul, das einen IP-Bereich im lokalen Netz nach Zählern auf
Modbus-TCP-Port 502 durchsucht:

1. Start- und End-IP eintragen (wird beim Anlegen anhand des eigenen Netzwerks vorbelegt,
   bleibt aber änderbar), optional eine Namens-Vorlage für neu anzulegende Instanzen.
2. „Netzwerk durchsuchen" klicken — nicht-blockierender Parallel-Scan auf Port 502.
3. Für jede offene IP wird der Zählertyp anhand dokumentierter Standard-Unit-IDs und einer
   Plausibilitätsprüfung (Netzfrequenz 45–65 Hz **und** eine plausible Spannung) erkannt:
   PAC2200 (Frequenz auf Reg. 55), klassische Janitza-Karte (Frequenz auf 19050) und
   UMG 800 (Frequenz auf 19054) liegen jeweils auf unterschiedlichen Registern und lassen
   sich so trennen. Die klassischen Janitza-Modelle teilen sich eine Signatur — Discovery
   schlägt stellvertretend „Janitza UMG (klassische Map)" vor; das exakte Modell lässt sich
   danach in der Instanz einstellen (die Registerkarte ist ohnehin identisch).
4. Treffer erscheinen in der Ergebnistabelle — Klick auf „Erstellen" legt eine
   `MeterHub`-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Zählertyp an.

**IPs ignorieren:** Adressen in dieser Liste werden beim Scan komplett übersprungen —
gedacht für andere Modbus-Geräte, die sonst Probe-Zeit kosten. Mehrere IPs Komma-getrennt.

**Namens-Vorlage:** leer lassen für den Standard „Zählertyp + laufende Nummer", oder ein
eigenes Muster mit den Platzhaltern `{zaehler}` `{ip}` `{unitid}` `{nr}` eintragen (z. B.
`{zaehler} Keller ({ip})`).

## Vorzeichen-Konvention

Modulweit gilt: **+ = Bezug** aus dem Netz, **− = Einspeisung**. PAC2200 und UMG604 melden
ihre Summen-Wirkleistung bereits vorzeichenbehaftet; passt die Richtung durch Einbaulage
oder Verdrahtung nicht, hilft der Invers-Schalter.

## Installation

Über die IP-Symcon Modulverwaltung „Hinzufügen" mit der URL dieses Repositories:

```
https://github.com/DG65/MeterHub
```

Solange sich MeterHub in der Testphase befindet, in der Modulverwaltung den Zweig **`beta`**
auswählen — dort liegt der jeweils aktuelle Stand. Der Zweig `main` bleibt der stabile Kanal.

## Mitwirken / Fehler melden

Rückmeldungen zu falschen Registerwerten, fehlenden Datenpunkten oder neuen unterstützten
Zählern gerne als Issue auf GitHub. Besonders hilfreich: Zählertyp, betroffenes
Register/Ident und beobachteter vs. erwarteter Wert.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
