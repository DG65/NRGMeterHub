# Store-Texte MeterHub 0.35.0 (Entwurf, noch nicht eingereicht)

Stand: 12.11.2026. Bundle `eu.dg.meterhub`, Commit `1a86966be5366fef69db837e19b3b517eac3b363` (beta und main).
Vorbild für Stil: RoboHub im Store (Versionsinformation als Stichpunkte „- New: …“ / „- Fix: …“, Dokumentationslink je Sprache).

## Deutsch (de)

**Name:** NRG-Stack MeterHub

**Beschreibung:**

NRG-Stack MeterHub liest Energiezähler verschiedener Hersteller direkt aus und stellt Leistung, Bezug und Einspeisung als normale Symcon-Variablen bereit — ein gemeinsames Treibergerüst statt eines Moduls pro Hersteller. Unterstützt werden unter anderem Siemens SENTRON PAC2200, Janitza UMG, Eastron SDM, Carlo Gavazzi, Shelly Pro 3EM, go-e Controller, WhatWatt, Phoenix Contact und Meteocontrol blue'Log (per Modbus TCP, auch über das Symbox-Gateway) sowie Inexogy/Discovergy über die Cloud-API.

Dazu gehören eine Gerätesuche, die Zähler im lokalen Netz findet und mit einem Klick anlegt, und virtuelle Zähler, die aus vorhandenen Werten eine neue Zahl rechnen, zum Beispiel den Rest eines Hausanschlusses. Die Archivierung der Messwerte schaltest du selbst ein; MeterHub hilft mit Archiv-Verdichtung und bei Inexogy mit dem Nachtrag des 15-Minuten-Lastgangs.

Teil des NRG-Stack. Rückmeldungen zu falschen oder fehlenden Werten sind willkommen — bitte mit Zählertyp und betroffenem Register melden.

**Versionsinformation:**

- Neu: zweisprachig Deutsch/Englisch (alle vier Module), dazu eine englische README
- Neu: Panel „Formular: Aufbau“ — Bereiche auf- und zuklappen, ausblenden und anordnen
- Neu: Archivierung nur noch auf Klick im Formular („Archivierung fehlt“ mit Knopf); bestehende Archivierung bleibt unberührt
- Fix: Einheit und Nachkommastellen kommen als Standarddarstellung der Variable statt als benutzerdefiniertes Profil
- Fix: alle Variablen liegen direkt unter der Instanz (keine Unterkategorien mehr); bestehende Instanzen räumen sich beim Übernehmen selbst auf
- Fix: Aufräumen über UnregisterVariable; Abgleich mit Geschwister-Instanzen beim Öffnen des Formulars statt beim Übernehmen

**Dokumentation:** https://github.com/DG65/NRGMeterHub/blob/main/README.md

## Englisch (en)

**Name:** NRG-Stack MeterHub

**Description:**

NRG-Stack MeterHub reads energy meters from many manufacturers directly and makes power, import and feed-in available as ordinary Symcon variables — one shared driver framework instead of one module per vendor. Supported are, among others, Siemens SENTRON PAC2200, Janitza UMG, Eastron SDM, Carlo Gavazzi, Shelly Pro 3EM, go-e Controller, WhatWatt, Phoenix Contact and Meteocontrol blue'Log (via Modbus TCP, also through the Symbox gateway), as well as Inexogy/Discovergy through the cloud API.

It also includes a discovery that finds meters in your local network and creates them with one click, and virtual meters that calculate a new number from existing values, for example the remainder of a house connection. You switch archiving of the measured values on yourself; MeterHub helps with archive compaction and, for Inexogy, with backfilling the 15-minute load profile.

Part of the NRG-Stack. Feedback on wrong or missing values is welcome — please report the meter type and the register concerned.

**Release notes:**

- New: bilingual German/English (all four modules), plus an English README
- New: panel "Form: layout" — expand, collapse, hide and reorder sections
- New: archiving only on a click in the form ("Archiving missing" with a button); existing archiving stays untouched
- Fix: unit and decimal places come as the variable's default presentation instead of a custom profile
- Fix: all variables sit directly under the instance (no subcategories any more); existing instances tidy themselves up on Apply
- Fix: cleanup via UnregisterVariable; comparison with sibling instances happens when the form opens instead of on Apply

**Documentation:** https://github.com/DG65/NRGMeterHub/blob/main/README.en.md

## Ablauf beim Einreichen (nach Dietmars Freigabe)

1. Beta und Stable je ein neues Release anlegen (`publish_add_module`), Git-URL `https://github.com/DG65/NRGMeterHub` und Commit `1a86966be5366fef69db837e19b3b517eac3b363` setzen (`publish_set_git`), Warten bis der Clone fertig ist.
2. Die Texte oben je Sprache setzen (`publish_set_localization`, de und en, Dokumentationslink je Sprache).
3. Einreichen (`publish_submit_release`). Für Stable gilt „Stable nur bei gravierenden Änderungen“: Dieses Release ist für alle relevant (Store-Remarks, Zweisprachigkeit).
