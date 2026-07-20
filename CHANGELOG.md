# Changelog

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
