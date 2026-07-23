# Changelog

## 0.17.0-beta.1 (2026-07-23)

- **Vertragsversionierung `contractVersion` (Verbund-Konvention).** `MHUB_GetFunctions` und
  `MHUBV_GetFunctions` liefern jetzt additiv `contractVersion => '1.1'` — 1.0 = Ur-Vertrag,
  1.1 = die latency/authority/pollInterval/energyKind/sourceCount-Erweiterung. Ein Konsument
  kann damit die Kompatibilität prüfen (Major nur bei Bruch, volle Verträglichkeit innerhalb
  derselben Major; fehlendes Feld = konservativ 1.0). Rein additiv, bestehende Konsumenten
  ignorieren es. Regel in CLAUDE.md, Prüfstand kontrolliert das Feld mit.
- **README:** Zeile „Teil der DG65 Energie-Suite" mit Verweis auf `DG65/EMS/SUITE.md` (Manifest
  der zusammen getesteten Modulstände).

## 0.16.4-beta.1 (2026-07-23)

- **Emoji-Entfernung aus 0.16.3 rückgängig.** Der Verbund hatte die Emoji-Regel zunächst
  restriktiv gefasst (nur Panel-Icons); Dietmar hat sie danach permissiv entschieden: Status-
  und Aufmerksamkeitssymbole (`✅`/`❌`/`⚠️`/`💡`/`ℹ️`) sind erwünscht, wo sie Fokus schaffen —
  kein Review hat je eines beanstandet. Die in 0.16.3 durch Wörter ersetzten Symbole sind damit
  wieder da; die permissive Regel steht in CLAUDE.md. (0.16.3 war netto ein Rundweg — hier zur
  Nachvollziehbarkeit dokumentiert statt stillschweigend übersprungen.)

## 0.16.3-beta.1 (2026-07-23, zurückgenommen)

- Dekorative Emoji aus Fließtext/Status entfernt (restriktive Zwischenregel) — in 0.16.4
  wieder rückgängig gemacht.

## 0.16.1-beta.1 (2026-07-23)

- **Migrations-Hinweis im Inexogy-Cloud-Panel.** Wer von einem anderen Discovergy-/Inexogy-Modul
  umsteigt und die Messhistorie behalten will, sieht jetzt den mit MigrationsHub abgestimmten
  Ablauf: Instanz erst mit „Kommunikation aktiv = AUS" anlegen und anmelden, dann adoptieren,
  danach einschalten — so bleibt die Zielvariable bis zur Übernahme ohne eigene Historie und es
  gibt keine Grauzone.

## 0.16.0-beta.1 (2026-07-23)

- **Neuer Zählertyp: Inexogy / Discovergy (Cloud-API) — der erste Nicht-Modbus-Zähler.**
  MeterHub bekommt damit eine zweite **Transportklasse neben Modbus**: `ModbusTcpClient` bleibt
  unverändert, ein neuer `InexogyClient` (OAuth 1.0a, HMAC-SHA1 in reinem PHP) tritt daneben.
  Der Hub baut je Zählertyp den passenden Transport (`GetTransport`), die 15 Modbus-Treiber
  bleiben unberührt. Architekturregel dahinter: getrennt wird nach Lebenszyklus (Pull/Timer wie
  Modbus vs. Push), nicht nach Protokoll — deshalb passt der gepollte Cloud-Zähler hier hinein,
  ein Push-Empfänger (SMA-Speedwire) bekäme dagegen ein eigenes Modul.
- **Sicherer Zugang.** Anmeldung mit E-Mail/Passwort des Inexogy-Kontos, der OAuth-Handshake
  (Consumer-Token selbst registrieren → Request-Token → Autorisieren → Access-Token) läuft
  programmatisch. Danach werden **nur die Token** gespeichert (Attribute, nicht im Formular),
  das **Passwort wird sofort gelöscht**. Kein Klartext-Passwort im System — anders als das alte
  Discovergy-Fremdmodul (Basic Auth). Kein Token/Passwort in Log oder Anzeige.
- **Verifizierte Werte, kein Raten.** Feldstruktur und Skalierung aus dem öffentlichen
  Quellcode des Alt-Moduls geholt und gegen die echten Zählerwerte gegengeprüft: `energy`/
  `energyOut` /10^10 → kWh (kumulativ, `energyKind: counter`), `power` /1000 → W (Rohwert mW),
  Phasen/Spannung /1000, beide Firmware-Feldnamen (`power1` und `phase1Power`). Vertrag: Rolle
  grid, `authority: billing`, `latency: delayed`. Keine Kostenrechnung — reine Messung.
- **Idents identisch zum MeterHub-Standard** (`energy_import`/`energy_export`/`power_total`/
  `p_l*`/`u_l*`), damit die geplante MigrationsHub-Adoption der Alt-Historie einfach ausrichtet.
- **Neu: `.tools/test-inexogy.php`.** Prüft OAuth-Prozentkodierung (RFC 3986), die
  Signaturbildung gegen eine unabhängige Nachrechnung und die Treiber-Skalierung gegen ein
  last_reading mit Dietmars echten Größenordnungen (27 Prüfungen). Der Handshake selbst ist
  erst am echten Login verifizierbar — bewusst nicht abgedeckt, klar markiert.

## 0.15.1-beta.1 (2026-07-22)

- **`latency`/`authority`/`pollInterval` zusätzlich in jede Zuordnung gespiegelt** (bisher nur
  auf Instanz-Ebene). Anlass: Die InverterHub-Netzbezug-Auswertung iteriert über
  `assignments[]` und filtert nach `function`; sie liest `authority` an der Zuordnung, nicht am
  Instanz-Objekt. Ohne die Spiegelung hätte sie `authority` verfehlt und keinen Zähler als
  `billing` erkannt. Die Werte stehen jetzt an beiden Orten (aus derselben Property, können
  nicht auseinanderlaufen) — Konsumenten können frei wählen. Rein additiv.
- `.tools/test-virtual.php` prüft `authority`/`latency` je Zuordnung mit (34 Prüfungen).

## 0.15.0-beta.1 (2026-07-22)

- **Vertragserweiterung `MHUB_GetFunctions` / `MHUBV_GetFunctions` (additiv, im Verbund
  abgestimmt).** Vier neue Felder, damit ein Konsument (EMS, Auswertung, InverterHub-Balken)
  Zähler nach Eignung unterscheiden kann:
  - `latency` (`realtime`|`delayed`) — darf das EMS in Sekunden darauf regeln? Modbus-Zähler
    sind `realtime`, Cloud-Zähler (Inexogy folgt) `delayed`.
  - `authority` (`billing`|`auxiliary`) — steht der Wert auf der Rechnung? Neue Checkbox
    „Abrechnungsverbindlicher Zähler am Netzübergabepunkt" setzt `billing`.
  - `pollInterval` — reale Aktualisierungsrate in Sekunden.
  - `energyKind` je Zuordnung (`counter`|`interval`) — kumulativer Zählerstand (Konsument
    bildet Differenzen) vs. Periodenverbrauch (summieren). Alle bisherigen Zähler `counter`.
  - `sourceCount` je Zuordnung (nur MHUBV) — Zahl der beteiligten Quellen eines Rest-/
    Summenknotens als Güte-Hinweis (der Rest wird still zu groß, wenn eine Quelle ausfällt).
- **`latency` und `authority` sind bewusst getrennt.** Sie sind orthogonal — alle vier
  Kombinationen existieren real (Inexogy billing+delayed, lokaler Shelly auxiliary+realtime,
  lokal ausgelesenes iMSys billing+realtime, virtueller Rest-Knoten auxiliary+realtime). Ein
  einzelnes Flag könnte das nicht trennen; deshalb zwei Felder.
- Bestehende Konsumenten (Kachel, Sankey) ignorieren die neuen Felder — kein Bruch. Konvention
  in CLAUDE.md dokumentiert (inkl. konservativer Defaults bei fehlenden Feldern).
- `.tools/test-virtual.php` prüft die neuen Felder mit (32 Prüfungen). Vorbereitung des
  Cloud-Zählertyps: `CLOUD_METERS`-Liste im Hauptmodul steht bereit (noch leer), Inexogy trägt
  sich dort ein, sobald der Treiber gebaut wird.

## 0.14.2-beta.1 (2026-07-22)

- **Sprachregel des Modul-Verbunds umgesetzt: alles Nutzersichtbare auf Deutsch.** Ersetzt
  wurden vermeidbare Anglizismen in Anzeigetexten und Dokumentation — „Scan abbrechen" →
  „Suche abbrechen", „Der Scan prüft…" → „Die Suche prüft…", „beim Scan übersprungen" → „bei
  der Suche übersprungen", Polling → Abfragetakt, Framework → Treibergerüst, Checkboxen →
  Schalter, Highlights → „Das Wichtigste in Kürze".
- **Bezeichner blieben unangetastet.** `ScanMeters`, `ScanRoot`, `ScanResult`, `BtnScan`,
  `AbortScan` und alle Idents sind unverändert: Idents sind API, ein umbenannter Ident
  erzeugte eine neue Variable und würfe die Historie der alten weg. Die Abgrenzung zwischen
  Anzeigetext und Bezeichner ist in CLAUDE.md festgehalten.

## 0.14.1-beta.1 (2026-07-22)

- **Zwei-Regler-Warnung für den go-e Controller** (README + Konfigurationsformular): Der
  Controller kann die go-e-Wallboxen **selbst** regeln (PV-Überschussladen, Lastbegrenzung).
  Wer stattdessen ein EMS steuern lässt, muss die interne Regelung deaktivieren — sonst
  arbeiten zwei Regler gegeneinander. Recherchiert und dokumentiert: Der Regelzustand ist über
  die Modbus-Karte des Controllers **nicht** sichtbar (auch nicht über dessen HTTP-API, die
  nur Messwerte führt) — er liegt an den Wallboxen selbst (`fup`, `loe`, `modelStatus` mit
  Klartextgrund, `lpsc`). Die Statusvariable dafür gehört deshalb in ChargerHub; dieses Modul
  liest ausschließlich und ist vom Konflikt nicht betroffen.

## 0.14.0-beta.1 (2026-07-22)

- **Neuer Zähler: go-e Controller — an echtem Gerät verifiziert.** Die Energiemess-Zentrale
  von go-e wechselt fachlich vom Schwestermodul ChargerHub hierher (dort bleiben die
  Wallboxen). Kernwerte kommen aus der Kategorie **Grid** (Wirkleistung, Bezug/Abgabe als
  64-Bit-Double in Wh); zuschaltbar sind Spannung je Phase (inkl. N), Strom je Phase (inkl. N),
  die **Stromsensoren 1–6** (Strom/Leistung/Leistungsfaktor) und die Kategorien
  **Home/Car/Relais/Solar/Akku** (je Leistung + Energie Ein/Aus). FC 0x04, Float32/Float64
  Big-Endian, Wire-Adresse = Doku − 30001.
- **Besonderheiten des Geräts, im Treiber berücksichtigt:** Es gibt keine Frequenz (Register
  1008 ist „nicht implementiert") — der Kern verzichtet darauf. Unbelegte Register beantwortet
  der Controller mit 0xFFFF… (NaN) statt einer Modbus-Exception; alle Werte werden deshalb vor
  der Übernahme auf Endlichkeit geprüft, statt NaN in die Variablen zu schreiben. Modbus TCP
  muss am Gerät erst aktiviert werden (go-e-App: Internet → Erweiterte Einstellungen → Modbus);
  nach dem Aktivieren die Einstellung ggf. einmal aus-/einschalten.
- **Netzwerksuche erkennt den go-e Controller** (Spannung L1/L2 auf 1000/1002 als
  Doppelkriterium, Unit-ID 1). Das NaN-Verhalten schützt zugleich vor Verwechslung: Bei allen
  Fremd-Proben fällt der Controller sauber durch, und NaN zählt bei der eigenen Probe nicht
  als Treffer — beides am Gerät gegengeprüft.
- Verifikation am echten Controller: Spannungen, Sensorleistungen (Summe der Netzsensoren ≙
  Grid-Leistung), Energiezählerstände und Vorzeichen (− = Einspeisung, passt zur Konvention)
  live abgeglichen; Blocklesungen mit 125 Registern bestätigt.

## 0.13.0-beta.1 (2026-07-22)

- **Virtuellen Zähler direkt aus einer Zählerinstanz anlegen.** Jede MeterHub-Instanz hat das
  neue Panel „🧮 Virtueller Zähler": weitere beteiligte Instanzen auswählen, Rolle festlegen —
  *übergeordnet* (die anderen hängen dahinter → Summe **und** Rest) oder *gleichrangig* (nur
  Summe) — und anlegen. Die Verdrahtung der neuen MeterHubVirtual-Instanz wird fertig
  vorbelegt; die Variablen-IDs muss niemand von Hand zusammensuchen. Geschrieben wird
  ausschließlich in die **neue** Instanz, an der eigenen Konfiguration ändert der Knopf nichts.
- **Prüfung vor dem Anlegen.** Steckt der Zähler bereits in einem virtuellen Zähler, wird
  **nicht** angelegt, sondern auf die vorhandene Instanz samt Kürzel verwiesen — ein zweiter
  virtueller Zähler mit demselben Gerät wäre der Anfang doppelter Buchführung. Ebenso wird
  gemeldet, wenn ein Zähler weder Gesamtleistung noch Bezug liefert oder je Phase misst.
- **Funktionszuordnung wird bewusst nicht übernommen.** Belegte der virtuelle Knoten dieselbe
  Funktion wie der echte Zähler, erschiene der Verbraucher in Stromflusskachel und Sankey
  doppelt. Der Hinweistext nennt stattdessen den sinnvollen Griff: dem übergeordneten Knoten
  „Hausverbrauch" geben, dann ist der Rest alles, was nicht auf die Unterzähler entfällt.
- **Hinweis in der Zählerinstanz.** Ist ein Zähler in einen virtuellen eingebunden, zeigt das
  Panel das an — mit Instanz und Kürzel. Vorher gab es von der normalen Instanz aus keinerlei
  Hinweis, dass es virtuelle Zähler überhaupt gibt.
- **Vier Filter für den Zählersuchlauf** (MeterHubVirtual): Suchbereich (nur unterhalb eines
  Objekts), Namensbestandteil, „nur Geräte mit Energiezähler" und „nur in den letzten 7 Tagen
  aktualisiert". Sie wirken sofort beim Klick, auch ohne vorher zu übernehmen. Das Ergebnis
  nennt den verwendeten Suchbereich und zählt auf, was woran gescheitert ist; wurde alles
  wegfiltriert, sagt es das ausdrücklich statt nur „nichts gefunden".
- **Verdrahtungsliste wächst mit dem Inhalt** (12 bis 30 Zeilen statt fest 8) — auch direkt
  nach einem Suchlauf, damit die Funde ohne Scrollen sichtbar sind.
- **Neu: `.tools/test-virtual.php`.** Prüfstand mit nachgebildeter IP-Symcon-Umgebung, der die
  Brücke wirklich ausführt: Knotenaufbau beider Rollen, Übernahme durch das Zielmodul,
  gerechnete Summen und Reste, die Ablehnung des zweiten virtuellen Zählers, alle vier
  Suchfilter und der Rückkopplungsschutz. Anlass ist ein früherer Laufzeitfehler in genau
  diesem Modul, den `php -l` nicht sehen konnte.

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
