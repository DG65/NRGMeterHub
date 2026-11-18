# Titel

[Beta-Tester gesucht] MeterHub / Energiezähler - ein Modbus-TCP-Modul für Siemens, Janitza, Eastron, Shelly, go-e, Meteocontrol blue'Log u.a. (+ Discovery + virtuelle Zähler)

# Beitrag

Moin zusammen,

MeterHub liest Energiezähler verschiedenster Hersteller direkt per Modbus TCP aus - ein generisches Treiber-Framework statt eines eigenen Moduls pro Hersteller, nach demselben Prinzip wie [InverterHub](https://community.symcon.de/t/beta-tester-gesucht-inverterhub-multi-wechselrichter-ein-modbus-tcp-modul-fuer-goodwe-sma-fronius-sungrow-solis-growatt-solax-solaredge-deye-solplanet-kostal-victron-huawei-und-weitere-hersteller/144121), mit dem es auch direkt zusammenarbeitet (Stromflusskachel, Sankey). Dazu gibt es einen Netzwerk-Discovery und einen virtuellen Zähler, der mehrere Zähler zu einem Formel-Ergebnis zusammenrechnet.

## Die drei Module

- **MeterHub** - die Auslese-Instanz. Zählertyp wählen, IP eintragen (bzw. bei Cloud-Zählern anmelden), Datenpunkt-Gruppen aktivieren.
- **MeterHubDiscovery** - durchsucht einen IP-Bereich und legt gefundene Zähler automatisch als Instanz an (Typ-Erkennung über charakteristische Register). Erkennt inzwischen auch Meteocontrol-blue'Log-Datenlogger von selbst und durchsucht den SCADA-Adressbereich dahinter gleich mit.
- **MeterHubVirtual** - virtueller Zähler aus einer Formel: beliebig viele reale Zähler, je einen Anteil in Prozent (100 % = addieren, −100 % = abziehen, jeder Wert dazwischen ein echter Teil-Anteil, z. B. für eine anteilige Einspeisevergütung). Mitglieder entweder als Tabellenzeilen oder - neu - einfach durch Anordnung im Objektbaum.

## Unterstützte Zähler

Legende: ✅ an realer Anlage bestätigt · 🧪 experimentell (aus Vorlage/Doku abgeleitet, noch nicht an Hardware geprüft)

| Hersteller | Status | Hinweis |
|---|---|---|
| Siemens SENTRON PAC2200 | ✅ | |
| Janitza UMG 604/605/509/512/806/96PA/801 | ✅ | gemeinsame Registerkarte |
| Janitza UMG 800 | ✅ | eigene, frei konfigurierbare Werkskarte |
| Eastron SDM72D-M v2 / SDM630 v2 | ✅ | meist per RTU/TCP-Gateway |
| Phoenix Contact EEM-EM375 / EEM-XM | ✅ | meist per RTU/TCP-Gateway |
| WhatWatt | ✅ | spricht Modbus TCP direkt |
| Carlo Gavazzi EM24 / EM300 / ET340 | ✅ | |
| Shelly Pro 3EM | ✅ | Modbus TCP muss am Gerät erst aktiviert werden |
| go-e Controller | ✅ | Modbus TCP muss am Gerät erst aktiviert werden |
| Inexogy / Discovergy | ✅ | Cloud-API statt Modbus, für Abrechnungszähler inkl. rückwirkendem Archiv-Nachtrag |
| Meteocontrol blue'Log SCADA (Wechselrichter/Zähler/Datenlogger-Summe) | ✅ | SCADA-Lizenz ist ein kostenpflichtiges Zusatzmodul, an realer Solarpark-Anlage verifiziert |
| Socomec Countis | 🧪 | gegen Herstelldoku gegengeprüft, Energiezähler noch offen |
| MBS Professional 3-75 | 🧪 | |

## Highlights

- **Ein Modul, viele Hersteller** - austauschbare Treiber, gemeinsame Konventionen (Vorzeichen: + = Bezug, − = Einspeisung, Invers-Schalter bei falscher Anschlussrichtung - gilt jetzt für Leistung UND Energiezähler gemeinsam, ohne Sprung beim Umschalten).
- **MeterHubVirtual** - Formel statt Baum: Summe, Differenz oder prozentualer Anteil, beliebig viele Zähler, Mitglieder wahlweise per Tabelle oder aus dem Objektbaum.
- **Automatische Archiv-Pflege** - passender Aggregationstyp (Zähler vs. Standard), automatische Verdichtungsstaffel aus dem Update-Intervall abgeleitet, konfigurierbar.
- **Zählerschutz** - kumulative Zählerstände laufen nie rückwärts und sind nie 0; erkennt Übertragungslücken und Zählertausch automatisch.
- **Rollen-Plausibilitätsprüfung** - Netz-/NAP-Zähler, Verbraucher oder Erzeuger; bei Unterzählern prüft das Modul aus echten Messwerten, ob die gewählte Rolle zur tatsächlichen Flussrichtung passt.
- **Gekoppelt an InverterHub** (Stromflusskachel/Sankey, Funktionszuordnung als gemeinsamer Vertrag) und optional an WPHub (Wärmepumpe).

## Anschluss-Besonderheiten (kurz)

- **Siemens PAC2200:** Energie als 64-Bit-Double, Registerlücke zwischen 41 und 55 - zwei getrennte Leseblöcke nötig.
- **Shelly Pro 3EM / go-e Controller:** Modbus TCP ist ab Werk aus, muss am Gerät erst aktiviert werden.
- **Meteocontrol blue'Log:** Die SCADA-Adresse ist die Unit-ID, NICHT 1 - steht am blue'Log selbst (Geräteliste → Spalte „SCADA Adresse").
- **Eastron/Phoenix:** meist per RTU/TCP-Gateway, dessen IP eintragen.

Alle Registeradressen stehen im Beschreibungsfeld jeder Variable.

## Installation

Ab sofort direkt über den Symcon Module Store - Kanal **Beta** einstellen, dann nach „MeterHub" suchen. Alternativ klassisch über eigene Module → GitHub-Repository:
https://github.com/DG65/NRGMeterHub (Branch beta)

## Status

Das Ganze ist Beta. Die Register basieren auf öffentlicher Hersteller-Doku, Referenzprojekten und - wo möglich - an realer Hardware geprüft; zwei Treiber (Socomec, MBS) sind noch nicht hardwareverifiziert und entsprechend markiert.

Rückmeldungen sind ausdrücklich erwünscht - besonders zu den 🧪 markierten Herstellern. Bitte mit Hersteller, Modell und betroffenem Register/Wert melden.

MeterHub ist Teil des NRG-Stack - ein offener Modulverbund fürs Energiemanagement, siehe die [Vorankündigung](https://community.symcon.de/t/vorankuendiung-nrg-stack-ein-offener-modulverbund-fuers-energiemanagement-mit-live-demo/144354). InverterHub hat hier schon seinen eigenen Thread, weitere Module folgen.

Danke fürs Testen!

## Was noch kommt

- Weitere Zählertypen - Vorschläge und vor allem Registerdokumentation willkommen.
- Engere Kopplung mit MigrationsHub für die Übernahme von Bestandszählern samt Archivwerten.
