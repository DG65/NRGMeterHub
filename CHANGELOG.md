# Changelog

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
