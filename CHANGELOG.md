# Changelog

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
