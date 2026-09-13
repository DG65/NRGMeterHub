<?php

// ---------------------------------------------------------------------------
// MeterHubVirtual — virtuelle Zähler als FORMEL statt als Baum.
//
// Die Instanz selbst ist die oberste Ebene ("der virtuelle Zähler"). Jede
// Zeile in der Tabelle ist ein Term dieser Formel, mit Vorzeichen:
//
//   Ergebnis = Summe aller „+“-Zeilen  −  Summe aller „−“-Zeilen
//
// getrennt gerechnet für Leistung, Bezug und Einspeisung. Kein „Kürzel“, kein
// „hängt hinter“, keine Sammelzeilen — Dietmars Einwand 31.08.2026: „für mein
// Verständnis ist die Instanz die oberste Ebene, und die angeklickten Zähler
// sind die untergeordneten Zähler, die den virtuellen Zähler bilden". Ersetzt
// das bisherige Baum-Modell (Zeilen mit „hängt hinter" auf andere Zeilen),
// das dieselben drei Anwendungsfälle nur komplizierter ausdrückte.
//
// Mehrstufige Verschachtelung (z. B. „Wallbox-Summe" als Zwischenschritt,
// davon dann wieder etwas abgezogen) geht nicht mehr innerhalb EINER Instanz,
// sondern über mehrere verkettete Instanzen: eine Instanz berechnet den
// Zwischenwert, deren Ausgabe wird als ganz normale Zeile in der nächsten
// Instanz verdrahtet. Das ist kein Verlust, sondern folgt demselben Zuschnitt
// wie der Rest des NRG-Stacks: eine Instanz = eine Zahl.
//
// Schutz gegen Doppelzählung: Validate() prüft, dass jeder Datenpunkt nur in
// EINER Zeile vorkommt — unabhängig vom Vorzeichen. Das war schon vorher die
// eigentliche Absicherung, nicht der Baum selbst.
// ---------------------------------------------------------------------------

class MeterHubVirtual extends IPSModule
{
    // Vokabular wie im MeterHub-Hauptmodul, damit virtuelle Zähler in Kachel
    // und Sankey dieselben Funktionen belegen können. Bewusst dupliziert:
    // Konstanten lassen sich zwischen IPS-Modulen nicht teilen. Änderungen
    // hier und in MeterHub/module.php gleich halten.
    private const FUNCTIONS = [
        'none'       => ['— keine Zuordnung —',      ''],
        'grid'       => ['Netzanschluss',            'Electricity'],
        'house'      => ['Hausverbrauch',            'HollowHouse'],
        'pv'         => ['PV-Erzeugung',             'Sun'],
        'battery'    => ['Batterie',                 'Battery'],
        'heatpump'   => ['Wärmepumpe',               'Temperature'],
        'heater'     => ['Heizung / Heizstab',       'Temperature'],
        'hotwater'   => ['Warmwasser',               'Drops'],
        'aircon'     => ['Klimaanlage',               'Snowflake'],
        'ventilation'=> ['Lüftung',                  'Ventilation'],
        'wallbox1'   => ['Wallbox 1',                'Car'],
        'wallbox2'   => ['Wallbox 2',                'Car'],
        'wallbox3'   => ['Wallbox 3',                'Car'],
        'wallbox4'   => ['Wallbox 4',                'Car'],
        'wallbox5'   => ['Wallbox 5',                'Car'],
        'garage'     => ['Garage',                   'Car'],
        'washer'     => ['Waschmaschine',            'Drops'],
        'dryer'      => ['Trockner',                 'Wind'],
        'dishwasher' => ['Spülmaschine',             'Drops'],
        'oven'       => ['Backofen',                 'Flame'],
        'stove'      => ['Herd',                     'Flame'],
        'fridge'     => ['Kühl-/Gefriergerät',       'Snowflake'],
        'kitchen'    => ['Küche (gesamt)',           'Gear'],
        'appliances' => ['Haushaltsgeräte (allgemein)', 'Plug'],
        'pool'       => ['Pool',                     'Waves'],
        'sauna'      => ['Sauna',                    'Flame'],
        'light'      => ['Beleuchtung',              'Bulb'],
        'it'         => ['Server / Netzwerk',        'Gear'],
        'entertainment' => ['Unterhaltungsmedien',   'TV'],
        'workshop'   => ['Werkstatt',                'Gear'],
        'other'      => ['Sonstiger Verbraucher',    'Electricity'],
    ];

    // Formular-Konvention des Verbunds (SUITE.md „Einheitliche Formular-
    // Optik", Referenz InverterHub). NEWS_VERSION korrespondiert mit dem
    // CHANGELOG-Eintrag, der den jeweiligen Sprung erklärt.
    private const NEWS_VERSION = '0.28.3';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        // Formel: [{Name,Factor(Prozent, z.B. 100/-100/50),PowerID,EnergyImportID,EnergyExportID}]
        // (frühere Zeilen mit Sign('+'|'-') statt Factor werden weiterhin gelesen, siehe Nodes())
        $this->RegisterPropertyString('Nodes', '[]');
        $this->RegisterPropertyString('Function', 'none');
        // Mitglieder-Quelle (Dietmars Anregung 11.09.2026): '' = automatisch
        // (ohne Tabellenzeilen → Objektbaum, sonst die bisherige Tabelle),
        // 'tree' = Objektbaum, 'list' = Tabelle. Automatisch statt Pflicht-
        // feld: bestehende Instanzen mit Tabelle rechnen unverändert weiter,
        // neue starten ohne Zutun im Baum-Modus.
        $this->RegisterPropertyString('MemberSource', '');
        // Einstellungen je Baum-Mitglied (Anteil, Rolle, Übersteuerungen,
        // Schalter), Schlüssel MemberID = Objekt-ID des Mitglieds.
        $this->RegisterPropertyString('MemberSettings', '[]');
        // Rein informativer Standort (Raum/Geschoss) — Dietmars Anregung
        // 31.08.2026, bewusst GETRENNT von "Function": "Function" ist ein
        // fester Vertrag mit dem Dashboard/InverterHubTile (Icon-Mapping in
        // einem anderen Repo), ein freier Raum-/Geschossname hätte dort kein
        // passendes Icon. "Location" ist reines Freitext-Label ohne Vertrag.
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyInteger('Interval', 10);
        // Archiv-Verdichtung, konfigurierbar statt fest im Code (Dietmars
        // Einwand 31.08.2026: "ich bin nicht der einzigste Nutzer, andere
        // haben vielleicht andere Vorstellungen" — seine eigenen Werte
        // bleiben nur noch die VORBELEGUNG, siehe CompactionPlan()). Doppelt
        // für Leistung und Energie (Dietmars Ergänzung, noch am selben Tag:
        // „wir müssen zwischen Leistungswerten und Energiewerten
        // unterscheiden" — unabhängig vom Update-Takt können beide ganz
        // andere Aufbewahrungs-Anforderungen haben, z. B. Energie-
        // Zählerstände länger roh behalten als Momentanleistung).
        foreach (['Power', 'Energy'] as $kind) {
            $this->RegisterPropertyBoolean('AutoCompaction' . $kind, true);
            $this->RegisterPropertyInteger('CompactDirect' . $kind, 0);        // direkt: 1×/Minute
            $this->RegisterPropertyInteger('CompactStage2Months' . $kind, 1);
            $this->RegisterPropertyInteger('CompactStage2Type' . $kind, 1);    // nach Stufe 2: 1×/5 Min
            $this->RegisterPropertyInteger('CompactStage3Months' . $kind, 12);
            $this->RegisterPropertyInteger('CompactStage3Type' . $kind, 2);    // nach Stufe 3: 1×/Stunde
        }
        // Filter für den Suchlauf. Sie merken sich die letzte Eingabe; wirksam
        // ist beim Klick aber immer der aktuelle Stand der Maske.
        $this->RegisterPropertyInteger('ScanRoot', 0);
        $this->RegisterPropertyString('ScanFilter', '');
        $this->RegisterPropertyBoolean('ScanNeedEnergy', false);
        $this->RegisterPropertyBoolean('ScanOnlyActive', true);
        // Kreuz-Instanz-Prüfung (Dietmars Anregung 31.08.2026): standardmäßig
        // blendet der Suchlauf Datenpunkte aus, die schon in einer ANDEREN
        // MeterHubVirtual-Instanz stecken (versehentliche Doppelverwendung
        // über Instanzgrenzen hinweg — Validate() prüft das bisher nur
        // INNERHALB einer Instanz). Umgekehrt eingeschaltet zeigt der
        // Suchlauf NUR solche schon-verwendeten Datenpunkte, zum gezielten
        // Nachschauen, wo ein bestimmter Zähler sonst noch eingeht.
        $this->RegisterPropertyBoolean('ScanOnlyUsedElsewhere', false);
        // Filter nach bereits vergebener Funktion (Dietmars Anregung
        // 02.09.2026: eine Sammel-Kategorie wie "Beleuchtung" über mehrere,
        // unterschiedlich benannte Stromkreise hinweg zusammensuchen, z. B.
        // um sie in einer eigenen Instanz als Energie- UND Kostenblock
        // darzustellen — Name allein (ScanFilter) trifft das nicht
        // zuverlässig, wohl aber die schon an der jeweiligen Ursprungs-
        // instanz gesetzte Funktions-Zuordnung). '' = keine Einschränkung.
        $this->RegisterPropertyString('ScanOnlyFunction', '');
        $this->RegisterAttributeString('SeenNews', '');
        // Symcon-Forum-Hinweis (SUITE.md "Einheitliche Formular-Optik", Punkt
        // 4): einmalig dismissible, kein Versionsbezug. URL noch ein
        // Platzhalter (Dietmar, 01.09.2026: ".forum/ankuendigung.md" ist noch
        // nicht veröffentlicht, "auch wenn nur eine Phantasie-URL drinnsteht"
        // soll die Zeile schon jetzt sichtbar sein) — vor main/Store-Release
        // durch den echten Thread-Link ersetzen.
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        // Zweck-Einführung (Dietmars Auftrag 01.09.2026, ausgelöst durch
        // Sepps Rückmeldung: er wusste anfangs nicht, WOFÜR das Modul
        // überhaupt gedacht ist, nur wie man es bedient). Einmalig
        // dismissible wie der Forum-Hinweis, nicht pro Version wie das
        // News-Panel — der Zweck ändert sich ja nicht mit jedem Release.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        // Baum-Modus: Fingerabdruck der zuletzt angewendeten Mitglieder, die
        // abonnierten Meldungen (zum sauberen Lösen) und die Sicherung der
        // Tabelle nach einer Umstellung (ConvertToTree()).
        $this->RegisterAttributeString('TreeSignature', '');
        $this->RegisterAttributeString('TreeMessages', '[]');
        $this->RegisterAttributeString('NodesBackup', '');
        // Mitglieder im Formular bearbeiten (0.26.0): zuletzt abgeglichener
        // Stand der Mitglieder-Tabelle, die beim Öffnen gezeigten Mitglieder
        // (nur die dürfen per Formular gelöscht werden) und Hinweise des
        // letzten Abgleichs.
        $this->RegisterAttributeString('ReconciledSettings', '');
        $this->RegisterAttributeString('FormSnapshot', '[]');
        $this->RegisterAttributeString('ReconcileNotes', '');
        // Zählerstetigkeit der Energie-Summen (0.28.3): je Ausgabe
        // {sig: Zusammensetzung, offset: Ausgleich}, siehe ContinuityStep().
        $this->RegisterAttributeString('EnergyContinuity', '{}');
        // Gewählte Zählquelle je unmarkiertem Doppel-Paar (0.28.5):
        // {"<id>-<id>": {count: Ziel-ID, since: Zeitpunkt}}, siehe ChooseCounting().
        $this->RegisterAttributeString('DupChoice', '{}');
        $this->RegisterTimer('Recalc', 0, 'MHUBV_Recalc($_IPS[\'TARGET\']);');
    }

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/PLATZHALTER-meterhub-thread-folgt/00000';
    private const LICENSE_URL = 'https://github.com/DG65/NRGMeterHub/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // Konzept-Diagramm (Dietmars Auftrag 01.09.2026, Anregung "wie die
    // BDEW-Messkonzepte" — Sepp hatte Mühe, sich das Verdrahtungs-Prinzip
    // rein aus Text vorzustellen): eine eingebettete SVG-Grafik, drei
    // Muster nebeneinander (Sammeln/Abziehen/Aufteilen), dieselben
    // Beispiele wie die Text-Beispiele direkt darunter im Doku-Panel.
    // Als Data-URI eingebettet (`'type' => 'Image', 'image' => 'data:...'`)
    // — gegen die offizielle SDK-Doku geprüft, nicht angenommen: das
    // Image-Element unterstützt `image` (Data-URI) ODER `mediaID`, beides
    // nicht kombinierbar. Data-URI gewählt, damit kein zusätzliches
    // Medienobjekt gepflegt werden muss.
    private const KONZEPT_DIAGRAMM = 'data:image/svg+xml;base64,PHN2ZyB2aWV3Qm94PSIwIDAgOTAwIDM0MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBmb250LWZhbWlseT0iLWFwcGxlLXN5c3RlbSxTZWdvZSBVSSxIZWx2ZXRpY2EsQXJpYWwsc2Fucy1zZXJpZiI+CiAgPGRlZnM+CiAgICA8bWFya2VyIGlkPSJhcnJvdyIgdmlld0JveD0iMCAwIDEwIDEwIiByZWZYPSI5IiByZWZZPSI1IiBtYXJrZXJXaWR0aD0iNyIgbWFya2VySGVpZ2h0PSI3IiBvcmllbnQ9ImF1dG8tc3RhcnQtcmV2ZXJzZSI+CiAgICAgIDxwYXRoIGQ9Ik0wLDAgTDEwLDUgTDAsMTAgeiIgZmlsbD0iIzU1NSIvPgogICAgPC9tYXJrZXI+CiAgPC9kZWZzPgogIDxyZWN0IHg9IjAiIHk9IjAiIHdpZHRoPSI5MDAiIGhlaWdodD0iMzQwIiBmaWxsPSIjZmZmZmZmIi8+CgogIDwhLS0gUGFuZWwgMTogU2FtbWVsbiAtLT4KICA8dGV4dCB4PSIxNTAiIHk9IjI2IiBmb250LXNpemU9IjE1IiBmb250LXdlaWdodD0iNzAwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjMWMxYzFlIj5TYW1tZWxuPC90ZXh0PgogIDx0ZXh0IHg9IjE1MCIgeT0iNDQiIGZvbnQtc2l6ZT0iMTEiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IiM2YjZiNmYiPm1laHJlcmUgWsOkaGxlciB6dSBlaW5lbSBXZXJ0PC90ZXh0PgoKICA8cmVjdCB4PSIxMCIgeT0iNzAiIHdpZHRoPSIxNDAiIGhlaWdodD0iMzQiIHJ4PSI2IiBmaWxsPSIjZWVmN2VmIiBzdHJva2U9IiMyZjhmNGUiIHN0cm9rZS13aWR0aD0iMS4zIi8+CiAgPHRleHQgeD0iODAiIHk9IjkxIiBmb250LXNpemU9IjExLjUiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IiMxYzFjMWUiPkvDvGhsc2NocmFuazwvdGV4dD4KICA8dGV4dCB4PSIxMCIgeT0iNjYiIGZvbnQtc2l6ZT0iMTAuNSIgZmlsbD0iIzJmOGY0ZSIgZm9udC13ZWlnaHQ9IjcwMCI+KzEwMCAlPC90ZXh0PgoKICA8cmVjdCB4PSIxMCIgeT0iMTUwIiB3aWR0aD0iMTQwIiBoZWlnaHQ9IjM0IiByeD0iNiIgZmlsbD0iI2VlZjdlZiIgc3Ryb2tlPSIjMmY4ZjRlIiBzdHJva2Utd2lkdGg9IjEuMyIvPgogIDx0ZXh0IHg9IjgwIiB5PSIxNzEiIGZvbnQtc2l6ZT0iMTEuNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzFjMWMxZSI+QnJ1bm5lbnB1bXBlPC90ZXh0PgogIDx0ZXh0IHg9IjEwIiB5PSIxNDYiIGZvbnQtc2l6ZT0iMTAuNSIgZmlsbD0iIzJmOGY0ZSIgZm9udC13ZWlnaHQ9IjcwMCI+KzEwMCAlPC90ZXh0PgoKICA8bGluZSB4MT0iMTUwIiB5MT0iODciIHgyPSIyMDUiIHkyPSIxMjIiIHN0cm9rZT0iIzU1NSIgc3Ryb2tlLXdpZHRoPSIxLjQiIG1hcmtlci1lbmQ9InVybCgjYXJyb3cpIi8+CiAgPGxpbmUgeDE9IjE1MCIgeTE9IjE2NyIgeDI9IjIwNSIgeTI9IjEzMiIgc3Ryb2tlPSIjNTU1IiBzdHJva2Utd2lkdGg9IjEuNCIgbWFya2VyLWVuZD0idXJsKCNhcnJvdykiLz4KCiAgPGNpcmNsZSBjeD0iMjIyIiBjeT0iMTI3IiByPSIyMCIgZmlsbD0iI2Y0ZjRmNiIgc3Ryb2tlPSIjMWMxYzFlIiBzdHJva2Utd2lkdGg9IjEuMyIvPgogIDx0ZXh0IHg9IjIyMiIgeT0iMTMzIiBmb250LXNpemU9IjE3IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjMWMxYzFlIj7OozwvdGV4dD4KCiAgPGxpbmUgeDE9IjI0MiIgeTE9IjEyNyIgeDI9IjI4MCIgeTI9IjEyNyIgc3Ryb2tlPSIjNTU1IiBzdHJva2Utd2lkdGg9IjEuNCIgbWFya2VyLWVuZD0idXJsKCNhcnJvdykiLz4KICA8cmVjdCB4PSIyODIiIHk9IjEwOCIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIzOCIgcng9IjYiIGZpbGw9IiNlYWYyZmIiIHN0cm9rZT0iIzJiNmNiMCIgc3Ryb2tlLXdpZHRoPSIxLjQiLz4KICA8dGV4dCB4PSIzNTciIHk9IjEzMiIgZm9udC1zaXplPSIxMiIgZm9udC13ZWlnaHQ9IjcwMCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzFjMWMxZSI+U3VtbWU8L3RleHQ+CgogIDwhLS0gZGl2aWRlciAtLT4KICA8bGluZSB4MT0iNDYwIiB5MT0iMTIiIHgyPSI0NjAiIHkyPSIzMjgiIHN0cm9rZT0iI2RjZGNlMCIgc3Ryb2tlLXdpZHRoPSIxIi8+CgogIDwhLS0gUGFuZWwgMjogQWJ6aWVoZW4gLS0+CiAgPHRleHQgeD0iNjEwIiB5PSIyNiIgZm9udC1zaXplPSIxNSIgZm9udC13ZWlnaHQ9IjcwMCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzFjMWMxZSI+QWJ6aWVoZW48L3RleHQ+CiAgPHRleHQgeD0iNjEwIiB5PSI0NCIgZm9udC1zaXplPSIxMSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzZiNmI2ZiI+dW5iZWthbm50ZW4gUmVzdCBiZXJlY2huZW48L3RleHQ+CgogIDxyZWN0IHg9IjQ4MCIgeT0iNjAiIHdpZHRoPSIxNTAiIGhlaWdodD0iMzQiIHJ4PSI2IiBmaWxsPSIjZWVmN2VmIiBzdHJva2U9IiMyZjhmNGUiIHN0cm9rZS13aWR0aD0iMS4zIi8+CiAgPHRleHQgeD0iNTU1IiB5PSI4MSIgZm9udC1zaXplPSIxMS41IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjMWMxYzFlIj5IYXVzYW5zY2hsdXNzPC90ZXh0PgogIDx0ZXh0IHg9IjQ4MCIgeT0iNTYiIGZvbnQtc2l6ZT0iMTAuNSIgZmlsbD0iIzJmOGY0ZSIgZm9udC13ZWlnaHQ9IjcwMCI+KzEwMCAlIChlaWdlbmVyIFrDpGhsZXIpPC90ZXh0PgoKICA8cmVjdCB4PSI0ODAiIHk9IjEzMCIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIzNCIgcng9IjYiIGZpbGw9IiNmZGVjZWMiIHN0cm9rZT0iI2MwMzkyYiIgc3Ryb2tlLXdpZHRoPSIxLjMiLz4KICA8dGV4dCB4PSI1NTUiIHk9IjE1MSIgZm9udC1zaXplPSIxMS41IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjMWMxYzFlIj5Xw6RybWVwdW1wZTwvdGV4dD4KICA8dGV4dCB4PSI0ODAiIHk9IjEyNiIgZm9udC1zaXplPSIxMC41IiBmaWxsPSIjYzAzOTJiIiBmb250LXdlaWdodD0iNzAwIj7iiJIxMDAgJTwvdGV4dD4KCiAgPHJlY3QgeD0iNDgwIiB5PSIyMDAiIHdpZHRoPSIxNTAiIGhlaWdodD0iMzQiIHJ4PSI2IiBmaWxsPSIjZmRlY2VjIiBzdHJva2U9IiNjMDM5MmIiIHN0cm9rZS13aWR0aD0iMS4zIi8+CiAgPHRleHQgeD0iNTU1IiB5PSIyMjEiIGZvbnQtc2l6ZT0iMTEuNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzFjMWMxZSI+V2FsbGJveDwvdGV4dD4KICA8dGV4dCB4PSI0ODAiIHk9IjE5NiIgZm9udC1zaXplPSIxMC41IiBmaWxsPSIjYzAzOTJiIiBmb250LXdlaWdodD0iNzAwIj7iiJIxMDAgJTwvdGV4dD4KCiAgPGxpbmUgeDE9IjYzMCIgeTE9Ijc3IiB4Mj0iNjg1IiB5Mj0iMTIyIiBzdHJva2U9IiM1NTUiIHN0cm9rZS13aWR0aD0iMS40IiBtYXJrZXItZW5kPSJ1cmwoI2Fycm93KSIvPgogIDxsaW5lIHgxPSI2MzAiIHkxPSIxNDciIHgyPSI2ODUiIHkyPSIxMzIiIHN0cm9rZT0iIzU1NSIgc3Ryb2tlLXdpZHRoPSIxLjQiIG1hcmtlci1lbmQ9InVybCgjYXJyb3cpIi8+CiAgPGxpbmUgeDE9IjYzMCIgeTE9IjIxNyIgeDI9IjY4NSIgeTI9IjE0MiIgc3Ryb2tlPSIjNTU1IiBzdHJva2Utd2lkdGg9IjEuNCIgbWFya2VyLWVuZD0idXJsKCNhcnJvdykiLz4KCiAgPGNpcmNsZSBjeD0iNzAyIiBjeT0iMTI3IiByPSIyMCIgZmlsbD0iI2Y0ZjRmNiIgc3Ryb2tlPSIjMWMxYzFlIiBzdHJva2Utd2lkdGg9IjEuMyIvPgogIDx0ZXh0IHg9IjcwMiIgeT0iMTMzIiBmb250LXNpemU9IjE3IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjMWMxYzFlIj7OozwvdGV4dD4KCiAgPGxpbmUgeDE9IjcyMiIgeTE9IjEyNyIgeDI9Ijc2MCIgeTI9IjEyNyIgc3Ryb2tlPSIjNTU1IiBzdHJva2Utd2lkdGg9IjEuNCIgbWFya2VyLWVuZD0idXJsKCNhcnJvdykiLz4KICA8cmVjdCB4PSI3NjIiIHk9IjEwOCIgd2lkdGg9IjEyOCIgaGVpZ2h0PSIzOCIgcng9IjYiIGZpbGw9IiNlYWYyZmIiIHN0cm9rZT0iIzJiNmNiMCIgc3Ryb2tlLXdpZHRoPSIxLjQiLz4KICA8dGV4dCB4PSI4MjYiIHk9IjEzMiIgZm9udC1zaXplPSIxMiIgZm9udC13ZWlnaHQ9IjcwMCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzFjMWMxZSI+UmVzdDwvdGV4dD4KCiAgPCEtLSBQYW5lbCAzOiBBdWZ0ZWlsZW4gKGZ1bGwgd2lkdGgsIGJlbG93KSAtLT4KICA8bGluZSB4MT0iMTAiIHkxPSIyNDgiIHgyPSI4OTAiIHkyPSIyNDgiIHN0cm9rZT0iI2RjZGNlMCIgc3Ryb2tlLXdpZHRoPSIxIi8+CiAgPHRleHQgeD0iNDUwIiB5PSIyNzIiIGZvbnQtc2l6ZT0iMTUiIGZvbnQtd2VpZ2h0PSI3MDAiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IiMxYzFjMWUiPkF1ZnRlaWxlbjwvdGV4dD4KICA8dGV4dCB4PSI0NTAiIHk9IjI4OCIgZm9udC1zaXplPSIxMSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzZiNmI2ZiI+ZWluIFrDpGhsZXIsIGFudGVpbGlnIGF1ZiBtZWhyZXJlIEluc3RhbnplbiAoei4gQi4gTWlldGVyKTwvdGV4dD4KCiAgPHJlY3QgeD0iMzUwIiB5PSIzMDAiIHdpZHRoPSIyMDAiIGhlaWdodD0iMzQiIHJ4PSI2IiBmaWxsPSIjZmZmN2U2IiBzdHJva2U9IiNiODg2MGIiIHN0cm9rZS13aWR0aD0iMS4zIi8+CiAgPHRleHQgeD0iNDUwIiB5PSIzMjEiIGZvbnQtc2l6ZT0iMTEuNSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzFjMWMxZSI+UFYtRWluc3BlaXN1bmcgKGRpZXNlbGJlIFZhcmlhYmxlKTwvdGV4dD4KCiAgPGxpbmUgeDE9IjM4MCIgeTE9IjMwMCIgeDI9IjIzMCIgeTI9IjIzMCIgc3Ryb2tlPSIjNTU1IiBzdHJva2Utd2lkdGg9IjEuNCIgbWFya2VyLWVuZD0idXJsKCNhcnJvdykiLz4KICA8dGV4dCB4PSIyOTAiIHk9IjI1NSIgZm9udC1zaXplPSIxMSIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iIzFjMWMxZSI+NjAgJTwvdGV4dD4KICA8cmVjdCB4PSI5MCIgeT0iMTk2IiB3aWR0aD0iMTQwIiBoZWlnaHQ9IjM0IiByeD0iNiIgZmlsbD0iI2VhZjJmYiIgc3Ryb2tlPSIjMmI2Y2IwIiBzdHJva2Utd2lkdGg9IjEuNCIvPgogIDx0ZXh0IHg9IjE2MCIgeT0iMjE3IiBmb250LXNpemU9IjExLjUiIGZvbnQtd2VpZ2h0PSI3MDAiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IiMxYzFjMWUiPkluc3RhbnogTWlldGVyIEE8L3RleHQ+CgogIDxsaW5lIHgxPSI1MjAiIHkxPSIzMDAiIHgyPSI2NzAiIHkyPSIyMzAiIHN0cm9rZT0iIzU1NSIgc3Ryb2tlLXdpZHRoPSIxLjQiIG1hcmtlci1lbmQ9InVybCgjYXJyb3cpIi8+CiAgPHRleHQgeD0iNjEwIiB5PSIyNTUiIGZvbnQtc2l6ZT0iMTEiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiMxYzFjMWUiPjQwICU8L3RleHQ+CiAgPHJlY3QgeD0iNjcwIiB5PSIxOTYiIHdpZHRoPSIxNDAiIGhlaWdodD0iMzQiIHJ4PSI2IiBmaWxsPSIjZWFmMmZiIiBzdHJva2U9IiMyYjZjYjAiIHN0cm9rZS13aWR0aD0iMS40Ii8+CiAgPHRleHQgeD0iNzQwIiB5PSIyMTciIGZvbnQtc2l6ZT0iMTEuNSIgZm9udC13ZWlnaHQ9IjcwMCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzFjMWMxZSI+SW5zdGFueiBNaWV0ZXIgQjwvdGV4dD4KPC9zdmc+Cg==';

    /** Symcon-Forum-Hinweis — einmalig dismissible, kein Versionsbezug (siehe NewsBanner() für das Versions-Pendant). */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'MeterHub/MeterHubVirtual sind Beta — Rückmeldungen, gerade zu neuen/ungetesteten Zählertypen, sind ausdrücklich willkommen im Community-Thread.'],
                ['type' => 'Label', 'caption' => '⚠️ Platzhalter-Link, Thread noch nicht veröffentlicht.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MHUBV_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    /**
     * Lizenz-/Unterstützungs-Hinweis — Dietmars Auftrag 01.09.2026, Wortlaut
     * ("Variante A") verbundweit als SUITE.md-Konvention festgehalten, damit
     * alle NRG-Stack-Module denselben Text verwenden. Anders als der
     * Forum-Hinweis bewusst NICHT wegklickbar — eine Lizenz ist kein
     * einmaliger Hinweis, der nach dem ersten Lesen verschwinden sollte.
     * Eingeklappt by default, aber immer im Formular vorhanden, ganz unten
     * nach dem Forum-Hinweis.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /**
     * Zweck-Einführung — beantwortet "wofür ist das Modul, was bringt's mir"
     * BEVOR die Bedienung erklärt wird (das übernimmt weiterhin das
     * Doku-Panel darunter). Dietmars Auftrag 01.09.2026, ausgelöst durch
     * Sepps Rückmeldung ("wusste am Anfang nicht was er damit machen kann
     * oder soll, was das Ziel sein könnte, was es ihm bringt"). Einmalig
     * dismissible (wie ForumHint(), NICHT pro Version wie NewsBanner()) —
     * der Zweck des Moduls ändert sich nicht mit jedem Release. Steht ganz
     * vorn im Formular, noch vor dem News-Panel: "wofür" kommt vor "was ist
     * neu".
     */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'MeterHubVirtual misst selbst nichts — es RECHNET aus bereits vorhandenen Leistungs-/Energiewerten (von MeterHub oder anderen Quellen) genau die eine Zahl, die dir fehlt.'],
                ['type' => 'Label', 'caption' => 'Typische Fälle: der unbekannte Rest eines Hausanschlusses (Hauptzähler minus alle bekannten Verbraucher), die Summe mehrerer Zähler zu einem Gesamtwert, oder ein Zähler, dessen Wert anteilig auf mehrere Instanzen/Mieter aufgeteilt werden muss.'],
                ['type' => 'Label', 'caption' => 'Kurz: ein zusätzlicher, virtueller Zähler ganz ohne zusätzliche Hardware. Wie man ihn zusammenbaut, steht im Panel „🔌 Zähler" weiter unten.'],
                ['type' => 'Label', 'caption' => '🙏 Dank an Sepp Lausch (seppm, community.symcon.de/u/seppm) — Betatester mit KNX-Zählertechnik-Fachwissen, hat dieses Modul entscheidend mitgeprägt.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MHUBV_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    /**
     * Aufgeklappt und pro Version einmalig bestätigbar — Formular-Konvention.
     * `null`, sobald `NEWS_VERSION` schon bestätigt wurde ODER eine Migration
     * aussteht (dann hat das Migrations-Panel Vorrang, siehe
     * GetConfigurationForm()).
     */
    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in dieser Version',
            'items' => [
                ['type' => 'Label', 'caption' => '• 📈 Bezug und Einspeisung laufen jetzt nahtlos weiter, wenn sich die Zusammensetzung ändert (Mitglied hinzu oder weg, Dublette umgestellt, „aktiv" geändert). Vorher sprang der Summen-Zählerstand um den Unterschied der Mitglieder — im Archiv und in Tagesbalken wirkte das wie ein Riesenverbrauch oder ein Zähler-Reset.'],
                ['type' => 'Label', 'caption' => '• 👯 Doppelte Anbindung erkannt: Ist dasselbe Gerät zweimal Mitglied (z. B. eine Wallbox über ChargerHub UND über OCPPHub), zählt bis zu deiner Wahl nur die erste Anbindung, und „Prüfung & Vorschau" nennt beide samt Grund (gleiche Seriennummer, IP-Adresse oder fast gleiche Zählerstände). Festgelegt wird die überzählige im Modul der Anbindung selbst (z. B. ChargerHub, OCPPHub) — dann zählt sie überall nicht mehr mit. Die neue Spalte „aktiv" nimmt ein Mitglied nur aus dieser Summe.'],
                ['type' => 'Label', 'caption' => '• 🌳 Neu: Mitglieder direkt im Objektbaum — alles, was als Verknüpfung (Link) oder direkt unter dieser Instanz hängt, wird automatisch Mitglied, in der Reihenfolge seiner Position dort. Neue Instanzen starten so; gelöschte Geräte fallen sofort als „Ziel fehlt" auf statt still als Leiche weiterzuleben.'],
                ['type' => 'Label', 'caption' => '• 🔧 Fix: bei Geräten ohne MeterHub-Kennung (z. B. Wallboxen) konnte statt der Gesamtleistung eine einzelne Phase gewählt werden. Jetzt gelten zuerst die Werte, die das Gerätemodul selbst meldet; sonst Gesamtwert vor Phasenwert, mehrdeutige Fälle werden gemeldet. Bitte die „Erkannt"-Spalte der eigenen Instanzen einmal ansehen.'],
                ['type' => 'Label', 'caption' => '• ✏️ Mitglieder direkt in der Tabelle bearbeiten: hinzufügen (Spalte „Ziel“), löschen, per Drag & Drop umsortieren, umbenennen, Ziel ändern — mit „Übernehmen“ werden die Links im Objektbaum entsprechend angepasst. Gelöscht werden nur Links, nie Geräte.'],
                ['type' => 'Label', 'caption' => '• 🔀 Schalter der verknüpften Geräte werden automatisch erkannt (Gruppe schalten ohne Einstellung); mehrdeutige Geräte werden gemeldet. Pro Mitglied lässt sich übersteuern oder „nicht schalten" wählen. Links ohne eigenen Namen heißen wie ihr Ziel.'],
                ['type' => 'Label', 'caption' => '• 🌳 Verschachteln: einen Link auf eine andere virtuelle Zähler-Instanz unterhängen (z. B. mehrere Wallbox-Zähler unter „Fahrzeugbeladung"). Kreisverweise werden erkannt und blockiert.'],
                ['type' => 'Label', 'caption' => '• 🌳 Instanzen mit der bisherigen Tabelle rechnen unverändert weiter. Ein Knopf „In Objektbaum-Mitglieder umwandeln" legt auf Wunsch die Links an — rechnerisch identisch, die alte Tabelle wird gesichert.'],
                ['type' => 'Label', 'caption' => '• Komplett neues, einfacheres Modell: Diese Instanz ist jetzt selbst die oberste Ebene. Jede Zeile ist ein Term mit einem Anteil in Prozent — kein „Kürzel“, kein „hängt hinter“, keine Sammelzeilen mehr.'],
                ['type' => 'Label', 'caption' => '• Ein Zähler lässt sich aufteilen: Spalte „Anteil (%)“ statt nur +/− — 100/−100 wie bisher, jeder Wert dazwischen ein Teil-Anteil, jetzt mit bis zu zwei Nachkommastellen (z. B. exakt ein Drittel). Details samt Beispiel im Doku-Panel unten.'],
                ['type' => 'Label', 'caption' => '• „Zähler suchen“ trägt nichts mehr automatisch ein, sondern zeigt nur noch die Fundstellen — aufgenommen wird über das normale „+“ mit dem eingebauten Symcon-Variablenpicker oder den neuen Geräte-Picker („Gerät wählen“).'],
                ['type' => 'Label', 'caption' => '• Zeilen lassen sich per Drag & Drop umsortieren, und „Prüfung & Vorschau“ zeigt die aktuellen Live-Werte samt Rechenergebnis, nicht nur die Formel-Struktur.'],
                ['type' => 'Label', 'caption' => '• 🆕 Neue Felder „Zählerbezeichnung“ (benennt die Instanz direkt im Formular um) und „Standort“ (Raum/Geschoss, mit Vorschlägen aus bereits benutzten Werten — jetzt auch aus normalen MeterHub-Instanzen).'],
                ['type' => 'Label', 'caption' => '• 🆕 Archiv-Verdichtung läuft automatisch: bei jedem „Übernehmen" wird eine konfigurierbare Staffelung gesetzt (Panels „🗄️ Archiv-Verdichtung", getrennt für Leistung/Energie), statt sie für jeden Datenpunkt von Hand in der Konsole einzustellen. Ein Fix stellt sicher, dass „aus" eine vorhandene Verdichtungsregel auch wirklich löscht. Ein neues „?" bei den Verdichtungsstufen erklärt das Zusammenspiel der drei Stufen mit Beispiel.'],
                ['type' => 'Label', 'caption' => '• Die Funktion (fürs Dashboard) wird einmal für die ganze Instanz gesetzt, nicht mehr pro Zeile.'],
                ['type' => 'Label', 'caption' => '• Mehrstufige Verschachtelung (z. B. ein Zwischenwert aus mehreren Zählern, von dem dann wieder etwas abgezogen wird) geht über mehrere verkettete Instanzen statt innerhalb einer einzigen — Details im Doku-Panel unten.'],
                ['type' => 'Label', 'caption' => '• Schon verdrahtete Instanzen (altes Baum-Format) brauchen eine einmalige Bestätigung: ein Migrations-Panel zeigt die bisherigen Zeilen als Vorschlag, nichts wird automatisch übernommen.'],
                ['type' => 'Label', 'caption' => '• Fix: „Übernehmen" konnte mit „Fehler beim Übernehmen der Änderungen" fehlschlagen, wenn eine Verdichtungsstufe auf „aus" stand oder eine Alt-Regel von einer früheren Einstellung im Archiv übrig war — beides räumt die Archiv-Verdichtung jetzt sauber auf.'],
                ['type' => 'Label', 'caption' => '• Fix: „Jetzt neu berechnen" meldete zwar Erfolg, das Panel „Prüfung & Vorschau" zeigte aber weiter die alten Werte — der Knopf lädt das Formular jetzt mit den frischen Werten neu.'],
                ['type' => 'Label', 'caption' => '• 🆕 Geräte-Familien ohne gemeinsamen Container (z. B. MDT AZI — jeder Messwert eine eigene KNX-Instanz): Kategorie wählen, „Geräte-Familie erkennen" liefert alle passenden Geräte auf einmal als neue Zeilen.'],
                ['type' => 'Label', 'caption' => '• 🆕 „Geräte-Familie erkennen" versteht jetzt auch KNX-Zähler mit getrennten Bezugs-/Einspeisungswerten (z. B. Lingg&Janke, P14/P23/A14/A23) — ergibt automatisch die richtige Drei-Zeilen-Verdrahtung mit Vorzeichen, statt sie von Hand zusammenzustellen.'],
                ['type' => 'Label', 'caption' => '• 🆕 Fehlt bei einem Zähler der Bezugswert (z. B. reine Wirkleistungs-Aktoren): „Fehlende Energiewerte aus der Leistung hochrechnen" rechnet Leistung × Berechnungs-Intervall zu einer eigenen, klar beschrifteten Variable auf — keine Schätzung, aber ungenauer als ein echter Zähler.'],
                ['type' => 'Label', 'caption' => '• 🆕 „Prüfung & Vorschau" warnt jetzt (⚠️, nicht blockierend), wenn eine Zeile Leistung, aber keinen Bezug hat, während andere Zeilen derselben Formel einen haben — sonst würde die Bezug-Summe diese Zeile still mit 0 kWh mitzählen.'],
                ['type' => 'Label', 'caption' => '• 🧡 Neues Panel „Über dieses Modul" ganz unten — Lizenz (PolyForm Noncommercial 1.0.0), Kontakt für gewerbliche Nutzung und ein PayPal-Link für alle, die etwas dalassen möchten.'],
                ['type' => 'Label', 'caption' => '• Übersichtlicher: die vier Wege, eine Zeile hinzuzufügen, stehen jetzt gleich am Anfang als Kurzübersicht mit eigenen Namen (Sucher-/Quick-Pick-/Familien-/Handarbeit-Alternative), jede mit eigener Zwischenüberschrift bei den passenden Bedienelementen.'],
                ['type' => 'Label', 'caption' => '• 👋 Neue Zweck-Einführung „Wozu dieses Modul?" ganz oben im Formular — kurz und knapp, wofür MeterHubVirtual gedacht ist, bevor es an die Bedienung geht.'],
                ['type' => 'Label', 'caption' => '• 🆕 Neue Übersichtsgrafik im Doku-Panel — zeigt die drei Verdrahtungs-Muster (Sammeln/Abziehen/Aufteilen) als Skizze, passend zu den bestehenden Text-Beispielen.'],
                ['type' => 'Label', 'caption' => '• 🧪 Experiment: WebFront-Kachel — zeigt die Formel-Zeilen mit Live-Werten und Ergebnis, Name/Anteil lassen sich direkt dort bearbeiten. Ergänzt das Konsolenformular, ersetzt es nicht (neue Zeilen/Variablen weiterhin dort).'],
                ['type' => 'Label', 'caption' => '• 🆕 Neuer Suchfilter „Nur Funktion X" — findet Datenpunkte, deren Ursprungsinstanz schon eine Funktion wie „Beleuchtung" trägt, auch wenn der Gerätename allein nicht eindeutig ist. Praktisch, um eine Sparte über mehrere Stromkreise hinweg zu einem Sammelzähler (Energie- UND Kostenblock) zusammenzufassen.'],
                ['type' => 'Label', 'caption' => '• Fix: „Gerät wählen" fand bei einer ANDEREN virtuellen Zähler-Instanz als Quelle die Leistung nicht (Bezug/Einspeisung schon) — betrifft das Verketten mehrerer virtueller Zähler.'],
                ['type' => 'Label', 'caption' => '• 🆕 Zwei neue Funktionen: „Haushaltsgeräte (allgemein)" und „Unterhaltungsmedien" — für einen Sammelzähler, der nicht in die schon vorhandenen Einzelkategorien passt.'],
                ['type' => 'Label', 'caption' => '• 🙏 Dank an Sepp Lausch (seppm) im Panel „Wozu dieses Modul?" und in der README — Betatester mit KNX-Zählertechnik-Fachwissen, hat dieses Modul entscheidend mitgeprägt.'],
                ['type' => 'Label', 'caption' => '• 🆕 Vertrag MHUBV_GetFunctions 1.3: liefert jetzt die Mitglieder („members") der Formel nach außen — damit kann das NRG-Dashboard Sammelzähler per Klick „aufschachteln" (verkettete virtuelle Zähler als Hierarchie). Ein reiner Zwischenknoten braucht dafür keine Funktion.'],
                ['type' => 'Label', 'caption' => '• 🆕 Schaltgruppe: neue Spalte „Schalter" für Mitglieder, die schalten UND messen (z. B. Z-Wave-Aktoren) — automatisch vorgeschlagen beim Übernehmen eines Geräts. Ab dem ersten schaltbaren, positiven Mitglied entstehen „Gruppe schalten" und „Gruppenstatus" an der Instanz. Nur positive Anteile werden mitgeschaltet, abgezogene Zeilen bewusst nicht.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MHUBV_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /**
     * Übernimmt einen gewählten Standort-Vorschlag ins Freitext-Feld
     * "Location" — dasselbe onChange+UpdateFormField-Muster wie an anderer
     * Stelle im Verbund (siehe CLAUDE.md), keine echte eigene Property: der
     * Vorschlag ist nur ein Schnellausfüller, gespeichert wird ausschließlich
     * "Location".
     */
    public function ApplyLocationPreset(string $preset)
    {
        if (trim($preset) !== '') {
            $this->UpdateFormField('Location', 'value', $preset);
        }
    }

    /**
     * Benennt die Instanz um. Zunächst angenommen, Symcon zeige dafür schon
     * ein natives Namensfeld am Kopf jeder Instanzseite — ungeprüfte
     * Behauptung, die Dietmar live widerlegt hat ("ich finde nichts").
     * Deshalb jetzt direkt im Formular, unabhängig vom Client (Konsole,
     * WebFront, App). `IPS_SetName()` ist KEINE registrierte Property und
     * nimmt an `ApplyChanges()`/„Übernehmen" nicht teil — wirkt deshalb
     * sofort per `onChange`.
     */
    public function RenameInstance(string $name)
    {
        $name = trim($name);
        if ($name !== '' && $name !== IPS_GetName($this->InstanceID)) {
            IPS_SetName($this->InstanceID, $name);
        }
    }

    /** Enthält $rawRows noch Zeilen im alten Baum-Format (Kürzel/„hängt hinter")? */
    private function NeedsMigration(array $rawRows): bool
    {
        foreach ($rawRows as $r) {
            if (is_array($r) && (array_key_exists('Parent', $r) || array_key_exists('Key', $r))) {
                return true;
            }
        }
        return false;
    }

    /** Alte Baum-Zeilen ins neue flache Format übertragen — Anteil immer 100 %, der Rest bleibt dem Formular zur Prüfung überlassen. */
    private function MigratedRows(array $rawRows): array
    {
        $out = [];
        foreach ($rawRows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = [
                'Name'           => trim((string)($r['Name'] ?? '')) ?: 'Unbenannt',
                'Factor'         => 100,
                'PowerID'        => (int)($r['PowerID'] ?? 0),
                'EnergyImportID' => (int)($r['EnergyImportID'] ?? 0),
                'EnergyExportID' => (int)($r['EnergyExportID'] ?? 0),
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Mitglieder aus dem Objektbaum (Dietmars Anregung 11.09.2026: „einen
    // virtuellen Zähler nur durch die Anordnung im Objektbaum zusammenbauen
    // … man würde viel schneller erkennen, welcher Zähler noch existiert,
    // und es gäbe nicht so viele Leichen"). Live an Dietmars Hausanlage
    // geprüft (11.09.2026, Testordner danach entfernt): Links lassen sich als
    // Kinder einer Instanz anlegen; IPS_GetChildrenIDs() liefert sie in
    // ANLAGE-Reihenfolge, nicht nach ObjectPosition — sortieren muss das
    // Modul selbst. OM_CHILDADDED/OM_CHILDREMOVED/OM_CHANGEPOSITION/
    // OM_CHANGENAME/OM_UNREGISTER/LM_CHANGETARGET existieren; WER sie jeweils
    // sendet, dokumentiert Symcon nicht — deshalb wird auf alle plausiblen
    // Absender gehört UND Recalc() vergleicht bei jedem Takt einen
    // Fingerabdruck der Mitglieder (Sicherheitsnetz, falls eine Meldung
    // anders ankommt als erwartet).
    // -----------------------------------------------------------------------

    /** Objektbaum als Mitglieder-Quelle? Siehe Property "MemberSource". */
    private function IsTreeMode(): bool
    {
        $src = $this->ReadPropertyString('MemberSource');
        if ($src === 'tree') {
            return true;
        }
        if ($src === 'list') {
            return false;
        }
        $rows = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        return !is_array($rows) || count($rows) === 0;
    }

    /**
     * Direkte Kinder dieser Instanz, die als Mitglied zählen, sortiert wie
     * die Konsole (Position, dann Name). Link → dessen Ziel; eine direkt
     * einsortierte Instanz oder ident-lose Variable → sie selbst. Eigene
     * Ausgabevariablen tragen immer einen Ident und fallen dadurch heraus,
     * ebenso Kategorien, Skripte, Ereignisse und Medien.
     */
    private function TreeMembers(): array
    {
        $out = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            $type = (int)$o['ObjectType'];
            if ($type === 6) {
                $target = (int)(@IPS_GetLink($cid)['TargetID'] ?? 0);
            } elseif ($type === 1 || ($type === 2 && (string)$o['ObjectIdent'] === '')) {
                $target = $cid;
            } else {
                continue;
            }
            $broken = $target <= 0 || !IPS_ObjectExists($target);
            // Ein Link ohne eigenen Namen zeigt im Symcon-Baum den Zielnamen,
            // sein ObjectName ist aber leer (Dashboard-Befund an Dietmars
            // Anlage, 11.09.2026) — hier genauso zurückfallen, sonst stünde
            // das Mitglied namenlos in Formular, Kachel und Vertrag.
            $name = (string)$o['ObjectName'];
            if ($name === '') {
                $name = $broken ? '#' . $cid : IPS_GetName($target);
            }
            $out[] = [
                'member' => $cid,
                'name'   => $name,
                'pos'    => (int)($o['ObjectPosition'] ?? 0),
                'isLink' => $type === 6,
                'target' => $target,
                'broken' => $broken,
            ];
        }
        usort($out, function ($a, $b) {
            return ($a['pos'] <=> $b['pos']) ?: strnatcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Gespeicherte Einstellungen je Mitglied, Schlüssel = Objekt-ID. Fehlt
     * einer gespeicherten Zeile die MemberID (falls Symcon die nicht
     * editierbare Spalte beim Speichern nicht mitschreibt — nicht live
     * verifiziert), gilt ersatzweise die Zeilen-Position: die Liste wird in
     * genau der Mitglieder-Reihenfolge aufgebaut.
     */
    private function MemberSettingsMap(): array
    {
        $rows = json_decode((string)$this->ReadPropertyString('MemberSettings'), true);
        $rows = is_array($rows) ? array_values($rows) : [];
        $members = null;
        $map = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $mid = (int)($r['MemberID'] ?? 0);
            if ($mid <= 0) {
                $members = $members ?? $this->TreeMembers();
                $target = (int)($r['Target'] ?? 0);
                if ($target > 0) {
                    // Per „+" im Formular neu angelegte Zeile: gehört zu dem
                    // Link, den ReconcileFormMembers() auf dieses Ziel angelegt
                    // hat (die echte ID kennt die gespeicherte Zeile noch nicht).
                    foreach ($members as $m) {
                        if ($m['target'] === $target && !isset($map[$m['member']])) {
                            $mid = $m['member'];
                            break;
                        }
                    }
                } elseif (empty($r['Form'])) {
                    $mid = (int)($members[$i]['member'] ?? 0);
                }
            }
            if ($mid > 0) {
                $map[$mid] = $r;
            }
        }
        return $map;
    }

    /**
     * Baum-Mitglieder als Formel-Zeilen — dieselbe Form wie die Tabellen-
     * Zeilen, plus member/isLink/target/broken. Leistung/Bezug/Einspeisung
     * werden bei jedem Aufruf frisch am Ziel aufgelöst (MetersOfDevice() bzw.
     * die Rolle einer direkt verknüpften Variable); ein Wert > 0 in den
     * Einstellungen übersteuert das. Der Schalter wird genauso am Ziel
     * gesucht (SwitchOfDevice(), nur bei einer Instanz als Ziel) —
     * Dashboard-Befund 11.09.2026: 21 Z-Wave-Aktoren unter Dietmars
     * „Licht EG/OG" blieben sonst unschaltbar. Übersteuern per SwitchID,
     * bewusst abschalten per NoSwitch.
     */
    private function TreeNodes(): array
    {
        $settings = $this->MemberSettingsMap();
        $out = [];
        foreach ($this->TreeMembers() as $m) {
            $s = $settings[$m['member']] ?? [];
            $auto = ['power' => 0, 'imp' => 0, 'exp' => 0];
            $meterNote = '';
            if (!$m['broken']) {
                if ((int)IPS_GetObject($m['target'])['ObjectType'] === 2) {
                    $role = (string)($s['Role'] ?? '');
                    if ($role === '') {
                        $kind = $this->Classify($m['target']);
                        $role = $kind === 'import' ? 'imp' : $kind;
                    }
                    if (isset($auto[$role])) {
                        $auto[$role] = $m['target'];
                    }
                } else {
                    $met = $this->MetersOfDevice($m['target']);
                    $auto = ['power' => $met['power'], 'imp' => $met['imp'], 'exp' => $met['exp']];
                    if (!empty($met['extraPower']) && (int)($s['PowerID'] ?? 0) <= 0) {
                        $meterNote = 'mehrere gleichwertige Leistungswerte am Gerät (' . implode(', ', array_map('IPS_GetName', array_merge([$met['power']], $met['extraPower']))) . ') — gewählt: „' . IPS_GetName($met['power']) . '“';
                    }
                }
            }
            $pick = function (string $field, string $key) use ($s, $auto): int {
                return (int)($s[$key] ?? 0) > 0 ? (int)$s[$key] : $auto[$field];
            };
            $switch = (int)($s['SwitchID'] ?? 0);
            $switchNote = '';
            if ($switch <= 0 && empty($s['NoSwitch']) && !$m['broken']
                && (int)IPS_GetObject($m['target'])['ObjectType'] === 1) {
                [$switch, $switchNote] = $this->SwitchOfDevice($m['target']);
            }
            $out[] = [
                'switchNote' => $switchNote,
                'meterNote'  => $meterNote,
                // Mitglied-Einstellung „aktiv" (0.28.0) — abgewählt zählt es
                // nicht mit, siehe ApplyDuplicateRules().
                'active' => !array_key_exists('Active', $s) || !empty($s['Active']),
                'name'   => $m['name'],
                'factor' => array_key_exists('Factor', $s) ? (float)$s['Factor'] : 100.0,
                'power'  => $pick('power', 'PowerID'),
                'imp'    => $pick('imp', 'EnergyImportID'),
                'exp'    => $pick('exp', 'EnergyExportID'),
                'switch' => $switch,
                'member' => $m['member'],
                'isLink' => $m['isLink'],
                'target' => $m['target'],
                'broken' => $m['broken'],
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Doppelte Anbindung (0.28.0, Dietmars Regel 13.09.2026). Dasselbe Gerät
    // kann über zwei Module eingebunden sein — z. B. eine Wallbox per
    // ChargerHub (Modbus) UND per OCPPHub. In einer Summe zählt es dann
    // doppelt, und zwei Module steuern dasselbe Gerät. MeterHubVirtual
    // erkennt das, zählt bis zur Entscheidung nur die erste Anbindung und
    // weist darauf hin. Entschieden wird am Quellmodul (Vertragsfeld
    // duplicateOf, Dietmars Entscheidung 13.09.2026): dort als Dublette
    // markiert zählt die Anbindung nicht mit. Die Spalte „aktiv" nimmt ein
    // Mitglied nur aus dieser einen Summe.
    // -----------------------------------------------------------------------

    /** Eine Anbindung gilt als frisch, wenn ihr Gerät in dieser Zeit (s) zuletzt erreicht wurde. */
    private const DUP_FRESH_S = 900;
    /** Frühestens so lange (s) nach einem Wechsel der Zählquelle wird wieder gewechselt. */
    private const DUP_HOLD_S = 1800;

    /**
     * Welche Anbindung eines unmarkierten Paars zählt? Frei von Symcon-
     * Aufrufen (Prüfstand). Beim ersten Mal die frische, sonst die erste
     * ($a). Danach bleibt es bei der gewählten, solange sie frisch ist;
     * gewechselt wird nur, wenn sie veraltet ist (15 min ohne Kontakt,
     * DUP_FRESH_S), die andere frisch ist und der letzte Wechsel mindestens
     * DUP_HOLD_S zurückliegt — sonst pendelt die Gruppe bei einer wackligen
     * Verbindung (EMS-Anmerkung 13.09.2026). Rückgabe [zählende Ziel-ID, Zustand].
     */
    private static function ChooseCounting(?array $prev, int $a, int $b, bool $fa, bool $fb, int $now): array
    {
        if ($prev === null || !in_array((int)($prev['count'] ?? 0), [$a, $b], true)) {
            $c = (!$fa && $fb) ? $b : $a;
            return [$c, ['count' => $c, 'since' => $now]];
        }
        $c = (int)$prev['count'];
        $cFresh = $c === $a ? $fa : $fb;
        $oFresh = $c === $a ? $fb : $fa;
        if (!$cFresh && $oFresh && $now - (int)($prev['since'] ?? 0) >= self::DUP_HOLD_S) {
            $c = $c === $a ? $b : $a;
            return [$c, ['count' => $c, 'since' => $now]];
        }
        return [$c, $prev];
    }

    /** Gewählte Zählquelle je unmarkiertem Paar anwenden und merken (Haltezeit, siehe ChooseCounting()). */
    private function ApplyCountingChoice(array &$nodes, array $pairs): void
    {
        $choices = json_decode((string)$this->ReadAttributeString('DupChoice'), true);
        $choices = is_array($choices) ? $choices : [];
        $new = [];
        foreach ($pairs as [$i, $j]) {
            if (!empty($nodes[$i]['markedDup']) || !empty($nodes[$j]['markedDup']) || !($nodes[$i]['active'] ?? true) || !($nodes[$j]['active'] ?? true)) {
                continue;
            }
            $ta = (int)$nodes[$i]['target'];
            $tb = (int)$nodes[$j]['target'];
            $key = min($ta, $tb) . '-' . max($ta, $tb);
            [$count, $st] = self::ChooseCounting($choices[$key] ?? null, $ta, $tb, (bool)($nodes[$i]['fresh'] ?? true), (bool)($nodes[$j]['fresh'] ?? true), time());
            $new[$key] = $st;
            $nodes[$count === $ta ? $i : $j]['dupCount'] = true;
        }
        if ($new != $choices) {
            $this->WriteAttributeString('DupChoice', (string)json_encode($new));
        }
    }

    /**
     * Sind zwei Anbindungen dasselbe Gerät? Frei von Symcon-Aufrufen
     * (Prüfstand). $a/$b: ['serial' => …, 'ip' => …]; $ea/$eb: Bezug-
     * Zählerstand oder null (nur übergeben, wenn die beiden aus verschiedenen
     * Modulen stammen — sonst gäbe es in einem Park mit vielen gleichen
     * Wechselrichtern Fehlalarme). Verschiedene Seriennummern schließen es
     * sicher aus, gleiche Seriennummer oder IP-Adresse belegen es, fast
     * gleiche Zählerstände (≤ 1 %, beide ≥ 100) gelten als Hinweis.
     * Rückgabe: Begründung oder null.
     */
    private static function SameDevice(array $a, array $b, ?float $ea, ?float $eb): ?string
    {
        $sa = strtolower(trim((string)($a['serial'] ?? '')));
        $sb = strtolower(trim((string)($b['serial'] ?? '')));
        if ($sa !== '' && $sb !== '') {
            return $sa === $sb ? 'gleiche Seriennummer ' . trim((string)$a['serial']) : null;
        }
        $ia = trim((string)($a['ip'] ?? ''));
        $ib = trim((string)($b['ip'] ?? ''));
        if ($ia !== '' && $ia === $ib) {
            return 'gleiche IP-Adresse ' . $ia;
        }
        if ($ea !== null && $eb !== null && $ea >= 100.0 && $eb >= 100.0 && abs($ea - $eb) <= 0.01 * max($ea, $eb)) {
            return 'Zählerstände fast gleich (' . number_format($ea, 1, ',', '.') . ' / ' . number_format($eb, 1, ',', '.') . ')';
        }
        return null;
    }

    /**
     * Doppel-Regel auf die Knoten anwenden (frei von Symcon-Aufrufen): jedes
     * Paar kennt sich gegenseitig ('dup'); sind beide noch aktiv, zählt bis
     * zur Wahl nur die erste Anbindung. Abgewählte (Mitglied-Einstellung
     * „aktiv" aus) und ausgesetzte Knoten gehen mit Anteil 0 und ohne
     * Schalter in Summe und Schaltgruppe ein.
     */
    private static function ApplyDuplicateRules(array $nodes, array $pairs): array
    {
        foreach ($pairs as [$i, $j, $why]) {
            $nodes[$i]['dup'][] = ['with' => $j, 'why' => $why];
            $nodes[$j]['dup'][] = ['with' => $i, 'why' => $why];
            if (($nodes[$i]['active'] ?? true) && ($nodes[$j]['active'] ?? true)
                && empty($nodes[$i]['excluded']) && empty($nodes[$j]['excluded'])
                && empty($nodes[$i]['markedDup']) && empty($nodes[$j]['markedDup'])) {
                // Bis zur Entscheidung zählt die gewählte Anbindung (Haltezeit,
                // ApplyCountingChoice()); ohne Wahl die mit aktuellen
                // Messwerten, sind beide gleich frisch, die erste.
                if (!empty($nodes[$i]['dupCount']) || !empty($nodes[$j]['dupCount'])) {
                    $nodes[!empty($nodes[$i]['dupCount']) ? $j : $i]['excluded'] = 'undecided';
                } else {
                    $fi = $nodes[$i]['fresh'] ?? true;
                    $fj = $nodes[$j]['fresh'] ?? true;
                    $nodes[(!$fi && $fj) ? $i : $j]['excluded'] = 'undecided';
                }
            }
        }
        foreach ($nodes as $k => $n) {
            if (!empty($n['markedDup'])) {
                $nodes[$k]['excluded'] = 'marked';
            }
            if (array_key_exists('active', $n) && !$n['active']) {
                $nodes[$k]['excluded'] = 'inactive';
            }
            if (!empty($nodes[$k]['excluded'])) {
                $nodes[$k]['factor'] = 0.0;
                $nodes[$k]['switch'] = 0;
            }
        }
        return $nodes;
    }

    /**
     * [Paare [i, j, Begründung] von Mitgliedern, die dasselbe Gerät anbinden,
     *  Indizes der Mitglieder, die ihr Quellmodul selbst als Dublette
     *  markiert (Vertragsfeld duplicateOf)].
     */
    private function DuplicateInfo(array $nodes): array
    {
        $cand = [];
        foreach ($nodes as $i => $n) {
            $t = (int)($n['target'] ?? 0);
            if (!isset($n['member']) || !empty($n['broken']) || $t <= 0 || (int)(@IPS_GetObject($t)['ObjectType'] ?? -1) !== 1) {
                continue;
            }
            $cand[$i] = $t;
        }
        $ids = [];
        $mods = [];
        $en = [];
        foreach ($cand as $i => $t) {
            $ids[$i] = $this->DeviceIdentity($t);
            $mods[$i] = (string)(@IPS_GetInstance($t)['ModuleInfo']['ModuleID'] ?? '');
            $imp = (int)($nodes[$i]['imp'] ?? 0);
            $en[$i] = $imp > 0 && IPS_VariableExists($imp) ? (float)GetValue($imp) : null;
        }
        $pairs = [];
        $keys = array_keys($cand);
        foreach ($keys as $x => $i) {
            foreach (array_slice($keys, $x + 1) as $j) {
                if ($cand[$i] === $cand[$j]) {
                    continue;
                }
                $other = $mods[$i] !== $mods[$j];
                $why = self::SameDevice($ids[$i], $ids[$j], $other ? $en[$i] : null, $other ? $en[$j] : null);
                if ($why !== null) {
                    $pairs[] = [$i, $j, $why];
                }
            }
        }
        $marked = array_keys(array_filter($ids, fn($x) => !empty($x['markedDup'])));
        // Frische je Anbindung (0.28.4, EMS-Hinweis 13.09.2026: OCPPHub WB2 seit
        // 10.09. ohne Verbindung, nur ChargerHub misst): lastSeenAt aus dem
        // Vertrag, sonst die jüngste Aktualisierung ihrer Leistungs-/Zählervariablen.
        $fresh = [];
        foreach ($cand as $i => $t) {
            $ls = (int)($ids[$i]['lastSeen'] ?? 0);
            if ($ls <= 0) {
                foreach (['power', 'imp', 'exp'] as $f) {
                    $v = (int)($nodes[$i][$f] ?? 0);
                    if ($v > 0 && IPS_VariableExists($v)) {
                        $ls = max($ls, (int)(IPS_GetVariable($v)['VariableUpdated'] ?? 0));
                    }
                }
            }
            $fresh[$i] = $ls > 0 && time() - $ls <= self::DUP_FRESH_S;
            // Instanz oder ihre übergeordnete (Gateway über die Verbindung,
            // bei OCPPHub-Ladepunkten der Splitter aus SplitterID) nicht aktiv
            // → nicht frisch, auch wenn der Vertrag noch Werte liefert
            // (EMS-Hinweis 13.09.2026: OCPPHub-Splitter ausgeschaltet).
            $inst = @IPS_GetInstance($t);
            $ups = [(int)($inst['ConnectionID'] ?? 0)];
            if (function_exists('IPS_GetProperty')) {
                $ups[] = (int)@IPS_GetProperty($t, 'SplitterID');
            }
            if ((int)($inst['InstanceStatus'] ?? 102) !== 102) {
                $fresh[$i] = false;
            }
            foreach ($ups as $up) {
                if ($up > 0 && IPS_InstanceExists($up) && (int)(IPS_GetInstance($up)['InstanceStatus'] ?? 102) !== 102) {
                    $fresh[$i] = false;
                }
            }
        }
        return [$pairs, $marked, $fresh];
    }

    /** Geräte-Merkmale einer Instanz: aus ihrem Vertrag, sonst Variable dev_serial bzw. Eigenschaft Host. */
    private function DeviceIdentity(int $inst): array
    {
        $id = ['serial' => '', 'ip' => ''];
        $e = $this->ContractEntryOf($inst);
        // Vom Nutzer am Quellmodul als Dublette markiert (Vertragsfeld duplicateOf).
        $id['markedDup'] = !empty($e['duplicateOf']);
        // Wann das Quellmodul das Gerät zuletzt erreicht hat (Vertragsfeld lastSeenAt), 0 = unbekannt.
        $id['lastSeen'] = (int)($e['lastSeenAt'] ?? 0);
        foreach (['deviceSerial', 'serialNumber', 'serial'] as $k) {
            if (is_scalar($e[$k] ?? null) && trim((string)$e[$k]) !== '') {
                $id['serial'] = trim((string)$e[$k]);
                break;
            }
        }
        foreach (['deviceIP', 'deviceHost', 'ip', 'host'] as $k) {
            if (is_scalar($e[$k] ?? null) && trim((string)$e[$k]) !== '') {
                $id['ip'] = trim((string)$e[$k]);
                break;
            }
        }
        if ($id['serial'] === '') {
            $v = $this->FindIdentDeep($inst, 'dev_serial', 3);
            if ($v > 0) {
                $id['serial'] = trim((string)GetValue($v));
            }
        }
        if ($id['ip'] === '' && function_exists('IPS_GetProperty')) {
            $h = @IPS_GetProperty($inst, 'Host');
            if (is_string($h) && trim($h) !== '') {
                $id['ip'] = trim($h);
            }
        }
        return $id;
    }

    /** Erster Eintrag des Vertrags {Präfix}_GetFunctions einer fremden Instanz, [] wenn keiner. */
    private function ContractEntryOf(int $inst): array
    {
        $guid = (string)(@IPS_GetInstance($inst)['ModuleInfo']['ModuleID'] ?? '');
        if ($guid === '' || $guid === self::GUID_VIRTUAL || $guid === self::GUID_METER) {
            return [];
        }
        $prefix = (string)(@IPS_GetModule($guid)['Prefix'] ?? '');
        // {Präfix}_GetFunctions (z. B. ChargerHub 1.4) oder
        // {Präfix}_GetContractEntry (OCPPHub-Ladepunkt, angekündigt 13.09.2026).
        $fn = '';
        foreach (['_GetFunctions', '_GetContractEntry'] as $suffix) {
            if ($prefix !== '' && function_exists($prefix . $suffix)) {
                $fn = $prefix . $suffix;
                break;
            }
        }
        if ($fn === '') {
            return [];
        }
        try {
            $res = $fn($inst);
        } catch (\Throwable $e) {
            return [];
        }
        if (is_string($res)) {
            $res = json_decode($res, true);
        }
        if (!is_array($res) || $res === []) {
            return [];
        }
        if (isset($res['assignments'][0]) && is_array($res['assignments'][0])) {
            return $res['assignments'][0] + $res;
        }
        if (array_keys($res) === range(0, count($res) - 1)) {
            return is_array($res[0]) ? $res[0] : [];
        }
        return $res;
    }

    /** Variable mit Ident $ident unterhalb von $parent (bis $depth Ebenen), 0 wenn keine. */
    private function FindIdentDeep(int $parent, string $ident, int $depth): int
    {
        foreach (IPS_GetChildrenIDs($parent) as $c) {
            $o = IPS_GetObject($c);
            if ($o['ObjectIdent'] === $ident && $o['ObjectType'] === 2) {
                return $c;
            }
            if ($depth > 1 && in_array($o['ObjectType'], [0, 1], true)) {
                $r = $this->FindIdentDeep($c, $ident, $depth - 1);
                if ($r > 0) {
                    return $r;
                }
            }
        }
        return 0;
    }


    /** Fingerabdruck der aufgelösten Mitglieder — ändert er sich, wird neu angewendet. */
    private function TreeSignature(array $nodes): string
    {
        $parts = [];
        foreach ($nodes as $n) {
            // switch gehört dazu: ein neu erkannter Schalter muss die
            // Gruppenvariablen (RegisterVariables()) nachziehen.
            $parts[] = [$n['member'] ?? 0, $n['target'] ?? 0, $n['name'], $n['broken'] ?? false, $n['power'], $n['imp'], $n['exp'], $n['switch']];
        }
        return md5((string)json_encode($parts));
    }

    /**
     * Meldungen des Baum-Modus neu abonnieren und den Fingerabdruck merken.
     * Vorherige Abos werden zuerst gelöst — über die eigene Liste im
     * Attribut, statt sich auf eine SDK-Abfrage bestehender Abos zu verlassen.
     */
    private function SyncTreeWatch(array $nodes): void
    {
        $old = json_decode((string)$this->ReadAttributeString('TreeMessages'), true);
        foreach (is_array($old) ? $old : [] as $pair) {
            try {
                @$this->UnregisterMessage((int)$pair[0], (int)$pair[1]);
            } catch (\Throwable $e) {
                // war nicht (mehr) abonniert — kein Fehler
            }
        }
        $reg = [];
        if ($this->IsTreeMode() && IPS_GetKernelRunlevel() === KR_READY) {
            $reg[$this->InstanceID . ':' . OM_CHILDADDED]   = [$this->InstanceID, OM_CHILDADDED];
            $reg[$this->InstanceID . ':' . OM_CHILDREMOVED] = [$this->InstanceID, OM_CHILDREMOVED];
            foreach ($nodes as $n) {
                $msgs = [OM_CHANGEPOSITION, OM_CHANGENAME, OM_UNREGISTER];
                if ($n['isLink']) {
                    $msgs[] = LM_CHANGETARGET;
                }
                foreach ($msgs as $msg) {
                    $reg[$n['member'] . ':' . $msg] = [$n['member'], $msg];
                }
                if (!$n['broken'] && $n['target'] !== $n['member']) {
                    $reg[$n['target'] . ':' . OM_UNREGISTER] = [$n['target'], OM_UNREGISTER];
                }
            }
            foreach ($reg as [$sender, $msg]) {
                $this->RegisterMessage($sender, $msg);
            }
        }
        $this->WriteAttributeString('TreeMessages', (string)json_encode(array_values($reg)));
        $this->WriteAttributeString('TreeSignature', $this->IsTreeMode() ? $this->TreeSignature($nodes) : '');
    }

    /** Blockierende Probleme nur des Baum-Modus: Selbstbezug, Rückkopplung, Kreisverweis. */
    private function TreeErrors(array $nodes): array
    {
        $errors = [];
        foreach ($nodes as $n) {
            if ($n['broken']) {
                continue;
            }
            $label = '„' . $n['name'] . '“';
            if ($n['target'] === $this->InstanceID) {
                $errors[] = "Mitglied $label verweist auf diese Instanz selbst — das ergäbe eine Endlosschleife.";
                continue;
            }
            foreach (['power', 'imp', 'exp'] as $f) {
                $vid = $n[$f];
                if ($vid > 0 && IPS_ObjectExists($vid) && (int)IPS_GetParent($vid) === $this->InstanceID
                    && in_array((string)IPS_GetObject($vid)['ObjectIdent'], ['power', 'energy_import', 'energy_export'], true)) {
                    $errors[] = "Mitglied $label verweist auf eine Ausgabe dieser Instanz selbst — das ergäbe eine Rückkopplung.";
                    continue 2;
                }
            }
            // Eine Variable, die direkt (ohne Ident) unter dieser Instanz
            // einsortiert ist, ist ein normales Mitglied, kein Kreisverweis —
            // ReferencesInstance() sähe sonst nur „Elternteil = diese
            // Instanz". Echte eigene Ausgaben fängt die Prüfung oben ab.
            $directVar = (int)IPS_GetObject($n['target'])['ObjectType'] === 2
                && (int)IPS_GetParent($n['target']) === $this->InstanceID;
            $seen = [];
            if (!$directVar && $this->ReferencesInstance($n['target'], $this->InstanceID, $seen, 0)) {
                $errors[] = "Mitglied $label enthält (direkt oder über weitere virtuelle Zähler) wiederum diese Instanz — ein Kreisverweis, der sich nie auflösen lässt.";
            }
        }
        return $errors;
    }

    /**
     * Rechnet $objectId (direkt oder über verschachtelte virtuelle Zähler)
     * mit Werten aus $needle? Deckt beide Mitglieder-Quellen fremder
     * MeterHubVirtual-Instanzen ab: Baum-Kinder und Tabellenzeilen.
     */
    private function ReferencesInstance(int $objectId, int $needle, array &$seen, int $depth): bool
    {
        if ($depth > 10 || isset($seen[$objectId]) || !IPS_ObjectExists($objectId)) {
            return false;
        }
        $seen[$objectId] = true;
        $o = IPS_GetObject($objectId);
        if ((int)$o['ObjectType'] === 2) {
            $parent = (int)$o['ParentID'];
            return $parent === $needle || $this->ReferencesInstance($parent, $needle, $seen, $depth + 1);
        }
        if ((int)$o['ObjectType'] !== 1 || (@IPS_GetInstance($objectId)['ModuleInfo']['ModuleID'] ?? '') !== self::GUID_VIRTUAL) {
            return false;
        }
        foreach (IPS_GetChildrenIDs($objectId) as $cid) {
            $c = IPS_GetObject($cid);
            $t = (int)$c['ObjectType'] === 6 ? (int)(@IPS_GetLink($cid)['TargetID'] ?? 0) : ((int)$c['ObjectType'] === 1 ? $cid : 0);
            if ($t === $needle || ($t > 0 && $this->ReferencesInstance($t, $needle, $seen, $depth + 1))) {
                return true;
            }
        }
        $rows = json_decode((string)@IPS_GetProperty($objectId, 'Nodes'), true);
        foreach (is_array($rows) ? $rows : [] as $r) {
            foreach (['PowerID', 'EnergyImportID', 'EnergyExportID'] as $f) {
                $vid = (int)($r[$f] ?? 0);
                if ($vid > 0 && $this->ReferencesInstance($vid, $needle, $seen, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Link auf $targetId als neues Mitglied anlegen, ans Ende der Reihenfolge. */
    private function CreateMemberLink(int $targetId, string $name): int
    {
        // Ab 100 aufwärts: die eigenen Ausgabevariablen belegen 0..n und
        // sollen im Objektbaum oben stehen bleiben.
        $pos = 100;
        foreach ($this->TreeMembers() as $m) {
            $pos = max($pos, $m['pos'] + 1);
        }
        $link = IPS_CreateLink();
        IPS_SetName($link, $name);
        IPS_SetLinkTargetID($link, $targetId);
        IPS_SetParent($link, $this->InstanceID);
        IPS_SetPosition($link, $pos);
        return $link;
    }

    /**
     * Gerät, das automatisch GENAU die Datenpunkte einer Tabellen-Zeile
     * liefert (dann genügt ein Link aufs Gerät), sonst 0. Zuerst die nächste
     * Instanz der Elternkette — DeviceOf() hält schon eine Kategorie für ein
     * „Gerät" (bei MeterHub z. B. „Summenwerte"), ein Link darauf wäre
     * rechnerisch richtig, im Objektbaum aber irreführend.
     */
    private function ExactDeviceFor(array $n): int
    {
        $fields = array_filter([$n['power'], $n['imp'], $n['exp']]);
        $instances = [];
        foreach ($fields as $vid) {
            $instances[$this->InstanceOf($vid)] = true;
        }
        // Liegt eine Instanz darüber, entscheidet NUR sie: liefert sie nicht
        // exakt diese Datenpunkte (z. B. Zeile nimmt nur die Leistung), ist
        // ein Link auf die Variable mit fester Rolle stabiler als einer auf
        // eine Zwischenkategorie. Kategorie-Geräte (DeviceOf()) nur, wenn
        // gar keine Instanz darüber liegt.
        if (isset($instances[0]) && count($instances) === 1) {
            $candidates = [];
            foreach ($fields as $vid) {
                $candidates[$this->DeviceOf($vid)[0]] = true;
            }
        } else {
            $candidates = $instances;
        }
        if (count($candidates) !== 1) {
            return 0;
        }
        $device = (int)array_key_first($candidates);
        if ($device <= 0 || $device === $this->InstanceID) {
            return 0;
        }
        $auto = $this->MetersOfDevice($device);
        return ($auto['power'] === $n['power'] && $auto['imp'] === $n['imp'] && $auto['exp'] === $n['exp']) ? $device : 0;
    }

    /** Nächste Instanz oberhalb von $vid, 0 = keine. */
    private function InstanceOf(int $vid): int
    {
        $pid = (int)IPS_GetParent($vid);
        while ($pid > 0) {
            $o = IPS_GetObject($pid);
            if ((int)$o['ObjectType'] === 1) {
                return $pid;
            }
            $pid = (int)$o['ParentID'];
        }
        return 0;
    }

    /**
     * Eine Formel-Zeile im Tabellen-Format als Baum-Mitglied anlegen: Link
     * auf den ersten gesetzten Datenpunkt (Leistung vor Bezug vor
     * Einspeisung) mit fester Rolle, die übrigen Datenpunkte als
     * Übersteuerung desselben Mitglieds — rechnerisch identisch zur Zeile.
     * Aufrufer stellen sicher, dass mindestens ein Datenpunkt gesetzt ist.
     * Rückgabe: [Link-ID, Einstellungs-Zeile].
     */
    private function LinkRowAsMember(array $row): array
    {
        $fields = array_filter([
            'power' => (int)($row['PowerID'] ?? 0),
            'imp'   => (int)($row['EnergyImportID'] ?? 0),
            'exp'   => (int)($row['EnergyExportID'] ?? 0),
        ]);
        $role = (string)array_key_first($fields);
        $link = $this->CreateMemberLink((int)$fields[$role], (string)($row['Name'] ?? ''));
        $s = [
            'MemberID' => $link,
            'Factor'   => (float)($row['Factor'] ?? 100),
            'Role'     => $role,
            'SwitchID' => (int)($row['SwitchID'] ?? 0),
        ];
        foreach (['power' => 'PowerID', 'imp' => 'EnergyImportID', 'exp' => 'EnergyExportID'] as $f => $key) {
            if ($f !== $role && ($fields[$f] ?? 0) > 0) {
                $s[$key] = $fields[$f];
            }
        }
        return [$link, $s];
    }

    /**
     * Zeilen für die Mitglieder-Tabelle im Formular — immer frisch aus dem
     * Objektbaum erzeugt (die Liste lädt bewusst NICHT die gespeicherten
     * Zeilen, siehe GetConfigurationForm()), damit ein neu verknüpftes
     * Mitglied nie neben veralteten Zeilen landet.
     */
    private function TreeFormRows(): array
    {
        $settings = $this->MemberSettingsMap();
        $rows = [];
        foreach ($this->TreeNodes() as $n) {
            $s = $settings[$n['member']] ?? [];
            $rows[] = [
                'MemberID'       => $n['member'],
                'Name'           => $n['name'],
                'Target'         => $n['target'],
                // Ausgangswerte, unsichtbar mitgespeichert: ReconcileFormMembers()
                // ändert im Objektbaum nur, was im Formular WIRKLICH geändert
                // wurde — eine zwischenzeitliche Umbenennung im Baum bleibt so
                // unangetastet.
                'OrigName'       => $n['name'],
                'OrigTarget'     => $n['target'],
                'Form'           => true,
                'Found'          => $this->FoundText($n),
                'Factor'         => $n['factor'],
                'Role'           => (string)($s['Role'] ?? ''),
                'PowerID'        => (int)($s['PowerID'] ?? 0),
                'EnergyImportID' => (int)($s['EnergyImportID'] ?? 0),
                'EnergyExportID' => (int)($s['EnergyExportID'] ?? 0),
                // Nur die Übersteuerung — der automatisch gefundene Schalter
                // steht in „Erkannt"; ihn hier einzutragen würde ihn beim
                // Speichern einfrieren.
                'SwitchID'       => (int)($s['SwitchID'] ?? 0),
                'NoSwitch'       => !empty($s['NoSwitch']),
                'Active'         => !array_key_exists('Active', $s) || !empty($s['Active']),
            ];
        }
        return $rows;
    }

    /** Lesbare Zusammenfassung, was für ein Mitglied gefunden bzw. verwendet wird. */
    private function FoundText(array $n): string
    {
        if ($n['broken']) {
            return '⚠️ Ziel fehlt' . ($n['target'] > 0 ? ' (#' . $n['target'] . ')' : '') . ' — Link löschen oder neu verknüpfen';
        }
        $parts = [];
        foreach (['power' => 'Leistung', 'imp' => 'Bezug', 'exp' => 'Einspeisung'] as $f => $lbl) {
            if ($n[$f] > 0 && IPS_ObjectExists($n[$f])) {
                $parts[] = $lbl . ': ' . IPS_GetName($n[$f]);
            }
        }
        if ($n['switch'] > 0 && IPS_ObjectExists($n['switch'])) {
            $parts[] = 'Schalter: ' . IPS_GetName($n['switch']);
        } elseif (($n['switchNote'] ?? '') !== '') {
            $parts[] = 'Schalter: mehrdeutig';
        }
        $prefix = ($n['isLink'] ? '🔗 ' : '') . IPS_GetName($n['target']) . ' → ';
        return $prefix . ($parts ? implode(' · ', $parts) : 'nichts gefunden');
    }

    /**
     * Ordnet Zeilen der Mitglieder-Tabelle (aus dem offenen Formular) den
     * aufgelösten Baum-Mitgliedern zu — über MemberID, ersatzweise über die
     * Zeilen-Position.
     */
    private function TreeRowResolver(): callable
    {
        $list = $this->TreeNodes();
        $byMember = [];
        foreach ($list as $n) {
            $byMember[$n['member']] = $n;
        }
        return function (int $i, array $r) use ($list, $byMember): ?array {
            $mid = (int)($r['MemberID'] ?? 0);
            if ($mid > 0) {
                return $byMember[$mid] ?? null;
            }
            if (!empty($r['Form']) || (int)($r['Target'] ?? 0) > 0) {
                return null; // neue Zeile, erst mit „Übernehmen" verknüpft
            }
            return $list[$i] ?? null;
        };
    }

    /**
     * Einstellungen einzelner Mitglieder dauerhaft ändern und anwenden.
     * Einträge nicht mehr vorhandener Mitglieder fallen dabei weg.
     */
    private function StoreMemberSettings(array $patch): void
    {
        $current = [];
        foreach ($this->TreeMembers() as $m) {
            $current[$m['member']] = true;
        }
        $map = [];
        foreach ($this->MemberSettingsMap() as $mid => $row) {
            if (isset($current[$mid])) {
                $map[$mid] = $row;
            }
        }
        foreach ($patch as $mid => $fields) {
            $map[$mid] = array_merge($map[$mid] ?? [], $fields, ['MemberID' => (int)$mid]);
        }
        // Nur die Einstellungen weiterschreiben, nicht die Formular-Spuren —
        // sonst würde ReconcileFormMembers() einen alten Formular-Stand
        // (Name, Ziel) erneut auf den Objektbaum anwenden.
        foreach ($map as $mid => $row) {
            unset($row['Form'], $row['OrigName'], $row['OrigTarget'], $row['Name'], $row['Found'], $row['Target']);
            $map[$mid] = $row;
        }
        IPS_SetProperty($this->InstanceID, 'MemberSettings', (string)json_encode(array_values($map)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Bestehende Tabelle einmalig in Links unter dieser Instanz umwandeln —
     * nur auf ausdrücklichen Knopfdruck, nie still (bei einer live genutzten
     * Anlage wäre ein automatischer Wechsel ein Risiko). Rechnerisch exakt:
     * liefert das Gerät einer Zeile automatisch genau deren Datenpunkte, wird
     * EIN Link aufs Gerät angelegt; sonst ein Link auf den ersten Datenpunkt
     * mit fester Rolle, die übrigen als Übersteuerung. Die bisherige Tabelle
     * bleibt als Sicherung im Attribut NodesBackup erhalten.
     */
    public function ConvertToTree(): string
    {
        if ($this->IsTreeMode()) {
            return 'ℹ️ Diese Instanz nutzt bereits den Objektbaum.';
        }
        $raw = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        if (is_array($raw) && $this->NeedsMigration($raw)) {
            return '❌ Bitte zuerst die ausstehende Migration oben abschließen.';
        }
        $settings = [];
        $viaDevice = 0;
        $viaVariable = 0;
        $skipped = [];
        foreach ($this->Nodes() as $i => $n) {
            $label = $n['name'] !== '' ? $n['name'] : 'Zeile ' . ($i + 1);
            $fields = array_filter(['power' => $n['power'], 'imp' => $n['imp'], 'exp' => $n['exp']]);
            if (!$fields) {
                $skipped[] = $label;
                continue;
            }
            $device = $this->ExactDeviceFor($n);
            if ($device > 0) {
                $link = $this->CreateMemberLink($device, $label);
                $settings[$link] = ['MemberID' => $link, 'Factor' => $n['factor'], 'SwitchID' => $n['switch']];
                $viaDevice++;
                continue;
            }
            [$link, $row] = $this->LinkRowAsMember([
                'Name' => $label, 'Factor' => $n['factor'], 'SwitchID' => $n['switch'],
                'PowerID' => $n['power'], 'EnergyImportID' => $n['imp'], 'EnergyExportID' => $n['exp'],
            ]);
            $settings[$link] = $row;
            $viaVariable++;
        }
        $this->WriteAttributeString('NodesBackup', $this->ReadPropertyString('Nodes'));
        IPS_SetProperty($this->InstanceID, 'MemberSettings', (string)json_encode(array_values($settings)));
        IPS_SetProperty($this->InstanceID, 'MemberSource', 'tree');
        IPS_SetProperty($this->InstanceID, 'Nodes', '[]');
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();

        $msg = '✅ Umgestellt: ' . ($viaDevice + $viaVariable) . ' Link(s) unter dieser Instanz angelegt';
        $msg .= $viaVariable > 0 ? " ($viaDevice aufs Gerät, $viaVariable auf eine einzelne Variable mit festgehaltener Zuordnung)." : '.';
        if ($skipped) {
            $msg .= "\nℹ️ Ohne Datenpunkt, deshalb nicht übernommen: " . implode(', ', $skipped) . '.';
        }
        $msg .= "\nDie bisherige Tabelle ist gesichert. Ab jetzt: Mitglieder im Objektbaum hinzufügen, entfernen und umsortieren.";
        return $msg;
    }

    /**
     * Mitglieder-Tabelle des Formulars auf den Objektbaum anwenden (Dietmars
     * Wunsch 11.09.2026: „die Mitglieder … innerhalb des Formulars
     * bearbeitbar machen"). Läuft nur, wenn sich die gespeicherte Tabelle
     * seit dem letzten Abgleich geändert hat UND sie aus dem Formular stammt
     * (Zeilen mit „Form"-Kennung) — interne Speicherungen
     * (StoreMemberSettings(), ConvertToTree()) und alle anderen
     * ApplyChanges()-Anlässe fassen den Objektbaum nicht an.
     *
     * Geändert wird nur, was im Formular geändert wurde (Vergleich mit den
     * unsichtbar mitgespeicherten Ausgangswerten), gelöscht nur, was beim
     * Öffnen des Formulars zu sehen war (FormSnapshot) und ein Link ist —
     * ein Gerät, das direkt unter der Instanz hängt, wird nie gelöscht.
     */
    private function ReconcileFormMembers(): void
    {
        $json = $this->ReadPropertyString('MemberSettings');
        if (!$this->IsTreeMode() || IPS_GetKernelRunlevel() !== KR_READY
            || $json === $this->ReadAttributeString('ReconciledSettings')) {
            return;
        }
        // Zuerst merken: die folgenden Objektbaum-Änderungen lösen Meldungen
        // und damit ein erneutes ApplyChanges() aus — das darf nicht noch
        // einmal abgleichen.
        $this->WriteAttributeString('ReconciledSettings', $json);
        $rows = json_decode($json, true);
        $rows = is_array($rows) ? array_values(array_filter($rows, fn($r) => is_array($r) && !empty($r['Form']))) : [];
        if (!$rows) {
            return;
        }
        $notes = [];
        $members = [];
        $targets = [];
        foreach ($this->TreeMembers() as $m) {
            $members[$m['member']] = $m;
            $targets[$m['target']] = $m['name'];
        }

        // 1. Zeilen, die im Formular gelöscht wurden
        $kept = [];
        foreach ($rows as $r) {
            $kept[(int)($r['MemberID'] ?? 0)] = true;
        }
        $snapshot = json_decode((string)$this->ReadAttributeString('FormSnapshot'), true);
        foreach (is_array($snapshot) ? $snapshot : [] as $mid) {
            $mid = (int)$mid;
            if (isset($kept[$mid]) || !isset($members[$mid])) {
                continue;
            }
            if ($members[$mid]['isLink']) {
                unset($targets[$members[$mid]['target']]);
                IPS_DeleteLink($mid);
                unset($members[$mid]);
            } else {
                $notes[] = '„' . $members[$mid]['name'] . '“ hängt direkt (nicht als Link) unter dieser Instanz — ein Gerät bzw. eine Variable wird aus dem Formular nie gelöscht. Zum Entfernen im Objektbaum an einen anderen Ort verschieben.';
            }
        }

        // 2. Zeile für Zeile: umbenennen, Ziel ändern, neu anlegen
        $refused = function (int $target) use (&$notes): bool {
            if (!IPS_ObjectExists($target)) {
                $notes[] = "Ziel #$target existiert nicht — Zeile nicht übernommen.";
                return true;
            }
            if ($target === $this->InstanceID) {
                $notes[] = 'Diese Instanz kann nicht ihr eigenes Mitglied sein — Zeile nicht übernommen.';
                return true;
            }
            $seen = [];
            if ((int)IPS_GetObject($target)['ObjectType'] !== 2 && $this->ReferencesInstance($target, $this->InstanceID, $seen, 0)) {
                $notes[] = '„' . IPS_GetName($target) . '“ enthält selbst (direkt oder verschachtelt) diese Instanz — Kreisverweis, Zeile nicht übernommen.';
                return true;
            }
            return false;
        };
        $order = [];
        foreach ($rows as $r) {
            $mid    = (int)($r['MemberID'] ?? 0);
            $name   = trim((string)($r['Name'] ?? ''));
            $target = (int)($r['Target'] ?? 0);
            if ($mid > 0) {
                if (!isset($members[$mid])) {
                    continue; // inzwischen im Objektbaum entfernt
                }
                if ($name !== '' && $name !== (string)($r['OrigName'] ?? '')) {
                    IPS_SetName($mid, $name);
                }
                if ($target > 0 && $target !== (int)($r['OrigTarget'] ?? 0) && $target !== $members[$mid]['target']) {
                    if (!$members[$mid]['isLink']) {
                        $notes[] = '„' . $members[$mid]['name'] . '“ ist kein Link — sein Ziel lässt sich nicht ändern.';
                    } elseif (isset($targets[$target])) {
                        $notes[] = '„' . IPS_GetName($target) . '“ ist bereits Mitglied (als „' . $targets[$target] . '“) — Ziel von „' . $members[$mid]['name'] . '“ nicht geändert.';
                    } elseif (!$refused($target)) {
                        unset($targets[$members[$mid]['target']]);
                        IPS_SetLinkTargetID($mid, $target);
                        $targets[$target] = $members[$mid]['name'];
                    }
                }
                $order[] = $mid;
            } elseif ($target > 0) {
                if (isset($targets[$target])) {
                    $notes[] = '„' . IPS_GetName($target) . '“ ist bereits Mitglied (als „' . $targets[$target] . '“) — nicht doppelt verknüpft.';
                    continue;
                }
                if ($refused($target)) {
                    continue;
                }
                $link = $this->CreateMemberLink($target, $name !== '' ? $name : IPS_GetName($target));
                $targets[$target] = IPS_GetName($link);
                $order[] = $link;
            } else {
                $notes[] = 'Eine neue Zeile ohne Ziel wurde ignoriert — bitte in der Spalte „Ziel" ein Gerät, eine Variable oder einen anderen virtuellen Zähler wählen.';
            }
        }

        // 3. Reihenfolge: nur neu vergeben, wenn sie im Formular geändert wurde
        $current = array_values(array_filter(array_column($this->TreeMembers(), 'member'), fn($id) => in_array($id, $order, true)));
        if ($current !== $order) {
            foreach ($order as $i => $id) {
                IPS_SetPosition($id, 100 + 10 * $i);
            }
        }
        $this->WriteAttributeString('ReconcileNotes', $notes ? (string)json_encode(['ts' => time(), 'notes' => $notes]) : '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Experimentelle WebFront-Kachel (Dietmars Anregung 02.09.2026:
        // "vielleicht bietet HTML eine bessere Alternative ... Kannst du das
        // auch mal probieren?", ausgelöst durch seine bereits produktiv
        // laufende Konfigurationskachel in OCPPHubAbrechnung/module.php).
        // Muster 1:1 von dort übernommen (RegisterHook()/MessageSink()/
        // ProcessHookData()-Struktur, dort bereits verbundweit bewährt).
        // Bewusst VOR jedem möglichen frühen Return unten registriert — die
        // Kachel soll auch bei anstehender Migration oder einem Formelfehler
        // erreichbar bleiben und den jeweiligen Status anzeigen, statt bei
        // einem Fehler ganz zu verschwinden.
        $this->SetVisualizationType(1);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook('/hook/mhubvtile' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }

        $rawRows = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        $rawRows = is_array($rawRows) ? $rawRows : [];

        if ($this->NeedsMigration($rawRows)) {
            // Sicherheitsnetz: eine alte Baum-Verdrahtung nicht blind ins neue
            // flache Modell übernehmen — das würde beim ersten automatischen
            // ApplyChanges nach dem Update sofort JEDE bisher nur lose
            // gefundene Kandidatenzeile mitsummieren, auf einer live
            // genutzten Anlage ein still falscher Wert. Stattdessen: nichts
            // anfassen, bis die Migrationsmaske im Formular bestätigt wurde.
            $this->SetTimerInterval('Recalc', 0);
            $this->SetStatus(202);
            return;
        }

        $this->ReconcileFormMembers();
        $this->CreateProfiles();
        $errors = $this->Validate();
        $this->RegisterVariables($errors);
        // Auch im Fehlerzustand: gerade dann muss eine Korrektur im
        // Objektbaum (Link entfernen/umhängen) sofort wirken.
        $this->SyncTreeWatch($this->Nodes());

        if (!$this->ReadPropertyBoolean('Active') || count($errors) > 0) {
            $this->SetTimerInterval('Recalc', 0);
            $this->SetStatus(count($errors) > 0 ? 201 : 104);
            return;
        }
        $this->SetTimerInterval('Recalc', max(2, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->SetStatus(102);
        $this->Recalc();
    }

    // -----------------------------------------------------------------------
    // WebFront-Kachel (Experiment, 02.09.2026) — reine Ergänzung zum
    // Konsolenformular, kein Ersatz: Zeilen hinzufügen und Variablen wählen
    // bleibt bewusst dort (Symcons eingebauter Variablenpicker im
    // "+"-Zeileneditor lässt sich in reinem HTML nicht gleichwertig
    // nachbauen, siehe SUITE.md "Symcon-Recherche: Tree+multiAdd..."). Die
    // Kachel kann: Name/Anteil je Zeile bearbeiten, eine Zeile entfernen,
    // die aktuellen Werte je Zeile und das Gesamtergebnis live sehen.
    // -----------------------------------------------------------------------

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if ($message === IPS_KERNELMESSAGE && isset($data[0]) && $data[0] === KR_READY) {
            // Store-Checkliste 9c: während eines Modul-Neuladens kann die Instanz kurz fehlen.
            if (IPS_InstanceExists($this->InstanceID)) {
                $this->ApplyChanges();
            }
            return;
        }
        // Baum-Modus: nur neu anwenden, wenn sich an den aufgelösten
        // Mitgliedern wirklich etwas geändert hat (eine Meldung kann auch
        // ein fremdes, hier irrelevantes Kind betreffen, z. B. eine eigene
        // Ausgabevariable).
        if (in_array($message, [OM_CHILDADDED, OM_CHILDREMOVED, OM_CHANGEPOSITION, OM_CHANGENAME, OM_UNREGISTER, LM_CHANGETARGET], true)) {
            if (!$this->InstanceReady()) {
                return;
            }
            $nodes = $this->IsTreeMode() ? $this->Nodes() : [];
            if ($this->ReadAttributeString('TreeSignature') !== ($this->IsTreeMode() ? $this->TreeSignature($nodes) : '')) {
                $this->SendDebug('Objektbaum', "Meldung $message von #$senderID — Mitglieder geändert, wende neu an.", 0);
                $this->ApplyChanges();
            }
        }
    }

    // Standard-WebHook-Registrierungsmuster (1:1 aus OCPPHubAbrechnung
    // übernommen, generischer Symcon-Mechanismus, keine modul-eigene Logik).
    private function RegisterHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $index => $hook) {
            if ($hook['Hook'] === $WebHook) {
                if ((int)$hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildTilePayload()) . ');</script>';
        return $html;
    }

    // Bedient sowohl die eingebettete WebFront-Kachel als auch eine
    // eigenständige Seite. Schreibzugriffe (?action=saveNodes) persistieren
    // SOFORT per IPS_SetProperty()+IPS_ApplyChanges() — anders als im
    // Konsolenformular gibt es hier keinen umschließenden "Übernehmen"-
    // Dialog (siehe ProcessHookData()-Docblock in OCPPHubAbrechnung).
    public function ProcessHookData()
    {
        if (isset($_GET['action']) && $_GET['action'] === 'saveNodes') {
            header('Content-Type: application/json; charset=utf-8');
            $rows = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($rows)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Ungültige Daten.']);
                return;
            }
            if ($this->IsTreeMode()) {
                // Baum-Modus: nur Anteil und Name sind hier änderbar (Name =
                // Name des Mitglieds im Objektbaum). Entfernen geht bewusst
                // nur im Objektbaum — die Kachel löscht keine Objekte.
                $members = [];
                foreach ($this->TreeMembers() as $m) {
                    $members[$m['member']] = $m;
                }
                $patch = [];
                foreach ($rows as $row) {
                    $mid = is_array($row) ? (int)($row['memberId'] ?? 0) : 0;
                    if (!isset($members[$mid])) {
                        continue;
                    }
                    $patch[$mid] = ['Factor' => (float)($row['factor'] ?? 100)];
                    $name = trim((string)($row['name'] ?? ''));
                    if ($name !== '' && $name !== $members[$mid]['name']) {
                        IPS_SetName($mid, $name);
                    }
                }
                $this->StoreMemberSettings($patch);
            } else {
                IPS_SetProperty($this->InstanceID, 'Nodes', json_encode($this->sanitizeNodeRows($rows)));
                IPS_ApplyChanges($this->InstanceID);
            }
            echo json_encode($this->buildTilePayload());
            return;
        }
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildTilePayload());
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->buildTilePayload()) . ');</script>';
        echo $html;
    }

    // Whitelist der per Kachel schreibbaren Felder je Zeile — Sicherheit
    // (kein beliebiger Property-Name über die Kachel erreichbar) UND
    // Datenhygiene (ungeprüfte JS-Werte). Die Variablen-IDs selbst kommen
    // unverändert aus dem zuvor gesendeten Payload zurück (die Kachel bietet
    // dafür keinen eigenen Picker an, siehe Klassenkommentar oben) —
    // trotzdem als int erzwungen, nicht blind durchgereicht.
    private function sanitizeNodeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'Name'           => (string)($row['name'] ?? ''),
                'Factor'         => (float)($row['factor'] ?? 100),
                'PowerID'        => (int)($row['powerId'] ?? 0),
                'EnergyImportID' => (int)($row['impId'] ?? 0),
                'EnergyExportID' => (int)($row['expId'] ?? 0),
                // Muss mit durchgereicht werden — sonst würde ein Speichern
                // aus der Kachel die Schalter-Zuordnung aller Zeilen löschen.
                'SwitchID'       => (int)($row['switchId'] ?? 0),
            ];
        }
        return $out;
    }

    private function buildTilePayload(): array
    {
        $nodes = $this->Nodes();
        $rows = [];
        foreach ($nodes as $n) {
            $rows[] = [
                'name'      => $n['name'],
                'factor'    => $n['factor'],
                'powerId'   => $n['power'],
                'powerName' => $n['power'] > 0 && IPS_VariableExists($n['power']) ? IPS_GetName($n['power']) : '',
                'powerVal'  => $n['power'] > 0 && IPS_VariableExists($n['power']) ? (float)GetValue($n['power']) : null,
                'impId'     => $n['imp'],
                'impName'   => $n['imp'] > 0 && IPS_VariableExists($n['imp']) ? IPS_GetName($n['imp']) : '',
                'impVal'    => $n['imp'] > 0 && IPS_VariableExists($n['imp']) ? (float)GetValue($n['imp']) : null,
                'expId'     => $n['exp'],
                'expName'   => $n['exp'] > 0 && IPS_VariableExists($n['exp']) ? IPS_GetName($n['exp']) : '',
                'expVal'    => $n['exp'] > 0 && IPS_VariableExists($n['exp']) ? (float)GetValue($n['exp']) : null,
                'switchId'  => $n['switch'],
                'memberId'  => $n['member'] ?? 0,
            ];
        }
        $result = ['power' => null, 'energy_import' => null, 'energy_export' => null];
        foreach (array_keys($result) as $ident) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid) {
                $result[$ident] = (float)GetValue($vid);
            }
        }
        return [
            'hookPath'     => '/hook/mhubvtile' . $this->InstanceID,
            'instanceName' => IPS_GetName($this->InstanceID),
            'location'     => $this->ReadPropertyString('Location'),
            'function'     => self::FUNCTIONS[$this->ReadPropertyString('Function')][0] ?? '',
            'status'       => $this->GetStatus(),
            'tree'         => $this->IsTreeMode(),
            'rows'         => $rows,
            'result'       => $result,
        ];
    }

    // -----------------------------------------------------------------------
    // Formel lesen und prüfen
    // -----------------------------------------------------------------------

    /**
     * Normalisierte Zeilen der Formel (Liste, keine Baum-Beziehung mehr).
     * `factor` in Prozent, z. B. 100 = voll addiert, −100 = voll abgezogen,
     * 50 = zur Hälfte addiert. Frühere Zeilen kennen nur `Sign` ('+'/'-') —
     * die werden weiterhin gelesen (100/−100), ohne dass eine Migration
     * nötig wäre; neue Zeilen tragen `Factor` direkt.
     */
    private function Nodes(): array
    {
        if ($this->IsTreeMode()) {
            $nodes = $this->TreeNodes();
            [$pairs, $marked, $fresh] = $this->DuplicateInfo($nodes);
            foreach ($marked as $i) {
                $nodes[$i]['markedDup'] = true;
            }
            foreach ($fresh as $i => $f) {
                $nodes[$i]['fresh'] = $f;
            }
            $this->ApplyCountingChoice($nodes, $pairs);
            return self::ApplyDuplicateRules($nodes, $pairs);
        }
        $rows = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        $rows = is_array($rows) ? $rows : [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (array_key_exists('Factor', $r)) {
                $factor = (float)$r['Factor'];
            } else {
                $factor = ((string)($r['Sign'] ?? '+')) === '-' ? -100.0 : 100.0;
            }
            $out[] = [
                'name'   => trim((string)($r['Name'] ?? '')),
                'factor' => $factor,
                'power'  => (int)($r['PowerID'] ?? 0),
                'imp'    => (int)($r['EnergyImportID'] ?? 0),
                'exp'    => (int)($r['EnergyExportID'] ?? 0),
                // Schaltvariable des Mitglieds (Bool mit Aktion, z. B. der
                // Z-Wave-Aktor, der die Leuchte schaltet UND misst) — Basis
                // der Schaltgruppe, siehe SwitchableMembers(). 0 = nicht
                // schaltbar. Fehlt der Schlüssel (ältere Zeilen), ist es 0.
                'switch' => (int)($r['SwitchID'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Prüft, was die Formel selbst nicht schon ausschließt. Rückgabe: Liste
     * von Klartext-Fehlern (leer = in Ordnung).
     */
    private function Validate(): array
    {
        $nodes  = $this->Nodes();
        $errors = $this->IsTreeMode() ? $this->TreeErrors($nodes) : [];

        // Derselbe Datenpunkt in zwei Zeilen würde ihn doppelt zählen —
        // unabhängig vom Vorzeichen. Das ist die eigentliche Absicherung
        // gegen Doppelzählung, nicht die Formel-Struktur selbst.
        $usedVars = [];
        foreach ($nodes as $i => $n) {
            $nr = $this->RowLabel($i, $n);
            foreach ([['power', 'PowerID', 'Leistung'], ['imp', 'EnergyImportID', 'Bezug'], ['exp', 'EnergyExportID', 'Einspeisung']] as [$f, , $lbl]) {
                $vid = $n[$f];
                if ($vid <= 0) {
                    continue;
                }
                if (!IPS_VariableExists($vid)) {
                    $errors[] = "$nr: $lbl verweist auf Variable #$vid, die es nicht gibt.";
                    continue;
                }
                if (isset($usedVars[$vid])) {
                    $errors[] = "Variable #$vid ($lbl) wird in {$usedVars[$vid]} und in $nr verwendet. Ein Zähler darf nur einmal eingehen — sonst würde er doppelt gerechnet.";
                } else {
                    $usedVars[$vid] = $nr;
                }
            }
        }

        // Gemischte Einheiten je Feld ergeben stillschweigend falsche Werte.
        foreach ([['power', 'Leistung'], ['imp', 'Bezug'], ['exp', 'Einspeisung']] as [$f, $lbl]) {
            $units = [];
            foreach ($nodes as $i => $n) {
                $vid = $n[$f];
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $u = $this->UnitOf($vid);
                    if ($u !== '') {
                        $units[$u][] = $this->RowLabel($i, $n);
                    }
                }
            }
            if (count($units) > 1) {
                $parts = [];
                foreach ($units as $u => $rows) {
                    $parts[] = $u . ' (' . implode(', ', $rows) . ')';
                }
                $errors[] = "Bei $lbl haben die Datenpunkte verschiedene Einheiten: " . implode(' vs. ', $parts) . '. Das ergäbe still falsche Werte — bitte auf eine Einheit bringen.';
            }
        }

        // Sicherheitsnetz gegen den 25.07.2026-Vorfall (#16933): Eine Formel
        // ohne eine einzige Ausgabe würde RegisterVariables() sonst als
        // "nichts ist mehr gültig" lesen und JEDE vorhandene Ausgabevariable
        // löschen, auch wenn nur eine einzelne Zeile versehentlich geändert
        // wurde. Nur relevant, wenn es überhaupt schon Ausgaben gibt.
        $anyOutput = false;
        foreach ($nodes as $n) {
            if ($n['power'] > 0 || $n['imp'] > 0 || $n['exp'] > 0) {
                $anyOutput = true;
                break;
            }
        }
        if (!$anyOutput && $this->HasExistingOutputs()) {
            $errors[] = $this->IsTreeMode()
                ? 'Unter dieser Instanz liefert kein Mitglied mehr einen Zähler (alle Links fehlen oder zeigen ins Leere). Vorhandene Ausgabevariablen bleiben deshalb unangetastet, bis wieder mindestens ein Mitglied im Objektbaum hängt.'
                : 'Die aktuelle Formel ergibt keine einzige Ausgabe mehr — keine Zeile hat mehr einen Zähler. Vorhandene Ausgabevariablen bleiben deshalb unangetastet, bis das behoben ist.';
        }

        return $errors;
    }

    /** „Zeile 3" (Tabelle) bzw. „Mitglied „Wallbox"" (Objektbaum) für Meldungen. */
    private function RowLabel(int $i, array $n): string
    {
        if (isset($n['member'])) {
            return 'Mitglied ' . ($n['name'] !== '' ? '„' . $n['name'] . '“' : '#' . $n['member']);
        }
        return 'Zeile ' . ($i + 1);
    }

    /**
     * Nicht-blockierende Hinweise, im Unterschied zu Validate()'s Fehlern
     * (die RegisterVariables() komplett stoppen) — Dietmars Auftrag
     * 01.09.2026: eine Zeile mit Leistung, aber ohne Bezug, während andere
     * Zeilen derselben Formel einen Bezug haben, verfälscht die Bezug-
     * Summe still — Recalc() zählt eine fehlende EnergyImportID einfach mit
     * 0 kWh mit, kein Fehler, keine Meldung (siehe Recalc()). Blockiert
     * hier bewusst nichts: eine unvollständige Zeile kann gewollt sein
     * (z. B. interessiert nur die Leistung).
     */
    private function Warnings(array $nodes): array
    {
        $warnings = [];
        // Doppelte Anbindung: jedes Paar einmal. Entschieden wird am
        // Quellmodul (Vertragsfeld duplicateOf, Dietmars Entscheidung
        // 13.09.2026) — ist eine Seite dort markiert oder hier in der Spalte
        // „aktiv" abgewählt, ist das Paar erledigt.
        foreach ($nodes as $i => $n) {
            foreach ($n['dup'] ?? [] as $d) {
                $j = (int)$d['with'];
                if ($j < $i || !isset($nodes[$j])) {
                    continue;
                }
                if (!empty($n['markedDup']) || !empty($nodes[$j]['markedDup']) || !($n['active'] ?? true) || !($nodes[$j]['active'] ?? true)) {
                    continue;
                }
                $la = $this->RowLabel($i, $n);
                $lb = $this->RowLabel($j, $nodes[$j]);
                $sure = !str_starts_with($d['why'], 'Zählerstände');
                $warnings[] = "$la und $lb sind " . ($sure ? '' : 'vermutlich ') . 'dasselbe Gerät (' . $d['why'] . ') — in der Summe zählte es doppelt, bis zur Entscheidung zählt nur ' . ((($n['excluded'] ?? '') === 'undecided') ? $lb : $la) . ' (die Anbindung mit aktuellen Messwerten, sonst die erste). Bitte im Modul der überzähligen Anbindung (z. B. ChargerHub oder OCPPHub) festlegen, dass sie eine Dublette ist: dann zählt sie überall nicht mehr mit, auch im Dashboard.';
            }
        }
        // Baum-Modus: tote und leere Mitglieder zuerst — genau diese
        // „Leichen" sichtbar zu machen war der Anlass des Baum-Modus.
        foreach ($nodes as $i => $n) {
            if (!isset($n['member'])) {
                continue;
            }
            $label = $this->RowLabel($i, $n);
            if ($n['broken']) {
                $warnings[] = "$label: das verknüpfte Ziel existiert nicht mehr — geht mit 0 in die Summe ein. Den Link im Objektbaum löschen oder auf ein vorhandenes Gerät umbiegen.";
            } elseif ($n['power'] <= 0 && $n['imp'] <= 0 && $n['exp'] <= 0) {
                $warnings[] = "$label: am Ziel wurde weder eine Leistung (W) noch ein Energiezähler (kWh) gefunden — geht mit 0 in die Summe ein" . ($n['switch'] > 0 ? ', wird aber mitgeschaltet' : '') . '. Unten in der Mitglieder-Tabelle lässt sich ein Datenpunkt von Hand zuordnen.';
            }
            if (($n['meterNote'] ?? '') !== '') {
                $warnings[] = "$label: " . $n['meterNote'] . ' — falls das nicht die Gesamtleistung ist, in der Mitglieder-Tabelle „Leistung übersteuern" setzen.';
            }
            if (($n['switchNote'] ?? '') !== '') {
                $warnings[] = "$label: Schalter nicht eindeutig — " . $n['switchNote'] . ' (Spalte „Schalter übersteuern", oder „nicht schalten").';
            }
        }
        $anyImp = false;
        $anyExp = false;
        foreach ($nodes as $n) {
            if ($n['imp'] > 0) {
                $anyImp = true;
            }
            if ($n['exp'] > 0) {
                $anyExp = true;
            }
        }
        foreach ($nodes as $i => $n) {
            if ($n['power'] <= 0) {
                continue;
            }
            // Ein Link auf eine einzelne Variable deckt bewusst nur EINE
            // Größe ab (z. B. Lingg&Janke: Leistung und Zähler als getrennte
            // Mitglieder) — dort ist „Leistung ohne Bezug" der Normalfall.
            if (isset($n['member']) && !$n['broken'] && $n['target'] > 0
                && (int)IPS_GetObject($n['target'])['ObjectType'] === 2) {
                continue;
            }
            if (isset($n['member'])) {
                $prefix = $this->RowLabel($i, $n);
            } else {
                $prefix = 'Zeile ' . ($i + 1) . ' (' . ($n['name'] !== '' ? '„' . $n['name'] . '“' : 'ohne Bezeichnung') . ')';
            }
            if ($anyImp && $n['imp'] <= 0) {
                $warnings[] = "$prefix: hat eine Leistung, aber keinen Bezug — andere Zeilen in dieser Formel haben einen. Die Bezug-Summe zählt diese Zeile mit 0 kWh statt mit ihrem tatsächlichen Verbrauch. Unten lässt sich der fehlende Wert aus der Leistung hochrechnen.";
            }
            if ($anyExp && $n['exp'] <= 0) {
                $warnings[] = "$prefix: hat eine Leistung, aber keine Einspeisung — andere Zeilen in dieser Formel haben eine. Die Einspeisung-Summe zählt diese Zeile mit 0 kWh mit.";
            }
        }
        // Schaltgruppe: nicht blockierend, aber sichtbar — ein Schalter, der
        // keine Bool-Variable ist, würde in SwitchableMembers() still
        // ignoriert; ein Schalter an einer abgezogenen Zeile gehört bewusst
        // nicht zur Gruppe (Dietmars Entscheidung 03.09.2026).
        foreach ($nodes as $i => $n) {
            if ($n['switch'] <= 0) {
                continue;
            }
            $prefix = isset($n['member'])
                ? $this->RowLabel($i, $n)
                : 'Zeile ' . ($i + 1) . ' (' . ($n['name'] !== '' ? '„' . $n['name'] . '“' : 'ohne Bezeichnung') . ')';
            if (!IPS_VariableExists($n['switch'])) {
                $warnings[] = "$prefix: der eingetragene Schalter (Variable #{$n['switch']}) existiert nicht mehr — die Zeile wird von der Gruppe nicht geschaltet.";
            } elseif ((int)(IPS_GetVariable($n['switch'])['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                $warnings[] = "$prefix: der eingetragene Schalter ist keine Bool-Variable (An/Aus) — die Zeile wird von der Gruppe nicht geschaltet.";
            } elseif ($n['factor'] <= 0) {
                $warnings[] = "$prefix: Schalter gesetzt, aber der Anteil ist abgezogen/0 — abgezogene Zeilen werden vom Gruppenschalter bewusst nicht mitgeschaltet (einzeln bleibt sie schaltbar).";
            }
        }
        return $warnings;
    }

    /** Hat die Instanz schon mindestens eine registrierte Ausgabevariable? */
    private function HasExistingOutputs(): bool
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] === 2 && $o['ObjectIdent'] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Einheit einer Variable, leer wenn unbekannt. Erst klassisches Profil,
     * dann die neuen Darstellungen (IPS 7+/8) — Tester-Fund von Sepp
     * (30.08.2026): KNX-Watt-Variablen und Shellys mit Darstellung statt
     * Profil fielen komplett durch den Suchlauf, nur Alt-Profil-Variablen
     * (kWh) wurden gefunden. Eine Darstellung trägt die Einheit entweder
     * direkt als SUFFIX ({"SUFFIX":" W","PRESENTATION":"{GUID}"}) oder
     * referenziert ein Alt-Profil ({"PROFILE":"~...","PRESENTATION":...}) —
     * beide Formen live an Dietmars Anlage verifiziert, nicht geraten.
     * Ältere IPS-Kerne liefern die Presentation-Felder gar nicht, deshalb
     * durchgehend mit ?? abgesichert.
     */
    private function UnitOf(int $vid): string
    {
        $v = @IPS_GetVariable($vid);
        if (!$v) {
            return '';
        }
        $p = $v['VariableCustomProfile'] !== '' ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($p !== '' && IPS_VariableProfileExists($p)) {
            return trim(IPS_GetVariableProfile($p)['Suffix']);
        }
        $pres = [];
        if (is_array($v['VariableCustomPresentation'] ?? null) && count($v['VariableCustomPresentation']) > 0) {
            $pres = $v['VariableCustomPresentation'];
        } elseif (is_array($v['VariablePresentation'] ?? null) && count($v['VariablePresentation']) > 0) {
            $pres = $v['VariablePresentation'];
        }
        if (trim((string) ($pres['SUFFIX'] ?? '')) !== '') {
            return trim((string) $pres['SUFFIX']);
        }
        $pp = (string) ($pres['PROFILE'] ?? '');
        if ($pp !== '' && IPS_VariableProfileExists($pp)) {
            return trim(IPS_GetVariableProfile($pp)['Suffix']);
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // Variablen
    // -----------------------------------------------------------------------

    /** Auszugebende Variablen: [ident, caption, profil, quelle-feld]. Höchstens drei — Leistung, Bezug, Einspeisung — je einmal pro Instanz. */
    private function OutputDefs(): array
    {
        $nodes = $this->Nodes();
        $has = ['power' => false, 'imp' => false, 'exp' => false];
        foreach ($nodes as $n) {
            foreach (['power', 'imp', 'exp'] as $f) {
                if ($n[$f] > 0) {
                    $has[$f] = true;
                }
            }
        }
        $defs = [];
        if ($has['power']) {
            $defs[] = ['power', 'Leistung', 'NRG.Watt', 'power'];
        }
        if ($has['imp']) {
            $defs[] = ['energy_import', 'Bezug', 'NRG.kWh', 'imp'];
        }
        if ($has['exp']) {
            $defs[] = ['energy_export', 'Einspeisung', 'NRG.kWh', 'exp'];
        }
        return $defs;
    }

    private function RegisterVariables(array $errors)
    {
        // Solange Validate() Fehler meldet, wird NICHTS angefasst — weder
        // gelöscht noch neu angelegt. Siehe #16933 in Validate().
        if ($errors) {
            return;
        }
        $defs  = $this->OutputDefs();
        $valid = [];
        foreach ($defs as $d) {
            $valid[$d[0]] = true;
        }
        // Schaltgruppe: die beiden Gruppen-Variablen existieren genau dann,
        // wenn mindestens ein positives Mitglied schaltbar ist — sonst
        // räumt die Aufräumschleife unten sie wie jede andere veraltete
        // Ausgabe weg (kein Sonderfall nötig).
        $switchables = $this->SwitchableMembers($this->Nodes());
        if ($switchables) {
            $valid[self::IDENT_GROUP_SWITCH] = true;
            $valid[self::IDENT_GROUP_STATE]  = true;
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            // "Energie hochgerechnet"-Variablen (siehe CALC_ENERGY_IDENT_PREFIX)
            // bleiben von dieser Aufräumung ausgenommen — sie sollen auch dann
            // als Historie stehen bleiben, wenn keine Zeile sie gerade mehr
            // referenziert (AddCalculatedEnergy()), nur ihre Fortschreibung
            // in AdvanceCalculatedEnergy() endet dann.
            if ($o['ObjectType'] === 2 && $o['ObjectIdent'] !== '' && !isset($valid[$o['ObjectIdent']])
                && !str_starts_with($o['ObjectIdent'], self::CALC_ENERGY_IDENT_PREFIX)) {
                @IPS_DeleteVariable($cid);
            }
        }
        $pos = 0;
        foreach ($defs as [$ident, $caption, $profile, $field]) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if (!$vid) {
                $vid = IPS_CreateVariable(VARIABLETYPE_FLOAT);
                IPS_SetIdent($vid, $ident);
                IPS_SetParent($vid, $this->InstanceID);
            }
            IPS_SetName($vid, $caption);
            IPS_SetPosition($vid, $pos++);
            if (self::ShouldSetProfile((string)(@IPS_GetVariable($vid)['VariableCustomProfile'] ?? ''), $profile)) {
                IPS_SetVariableCustomProfile($vid, $profile);
            }
            // Bezug/Einspeisung sind kumulative Zählerstände (Archiv-
            // Aggregationstyp "Zähler" = Delta je Periode), Leistung ist ein
            // Momentanwert ("Standard" = Min/Max/Durchschnitt). Live an
            // Dietmars Anlage verifiziert (echte Wirkarbeit-Zähler stehen
            // dort auf Aggregationstyp 1, reine Momentanwerte auf 0) — Sepps
            // Testerfund 31.08.2026: „Bei „Bezug“ ist der falsche Zähler-
            // Typ, ist Standard, muss Zähler sein“, da bisher pauschal
            // Standard (0) für jede Ausgabe gesetzt wurde.
            $this->SetArchive($vid, $field !== 'power', $this->ReadPropertyInteger('Interval'));
        }

        $this->EnsureGroupVariables($switchables, $pos);

        // "Energie hochgerechnet"-Variablen sind kumulative Zählerstände wie
        // ein echter Bezugszähler — bekommen bei jedem "Übernehmen" dieselbe
        // Archiv-Behandlung (Aggregationstyp "Zähler", Verdichtungs-Staffelung).
        foreach ($this->Nodes() as $n) {
            foreach (['imp', 'exp'] as $ef) {
                $vid = (int)$n[$ef];
                if ($vid > 0 && $this->IsOwnCalculatedEnergyVar($vid)) {
                    $this->SetArchive($vid, true, $this->ReadPropertyInteger('Interval'));
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Schaltgruppe (Dietmars Entscheidung 03.09.2026, per Dashboard-Sitzung
    // adressiert: „das mit dem Schalten ... die Aufgabe von MeterHub"). Viele
    // Mitglieder eines virtuellen Zählers sind Aktoren, die schalten UND
    // messen (Z-Wave-Schaltaktoren mit Leistungsmessung) — "Licht OG" ist
    // damit nicht nur eine Summe, sondern auch eine Schaltgruppe.
    //
    // Drei Entscheidungen Dietmars, hier verankert:
    // 1. MeterHubVirtual schaltet SELBST: Bool-Variable "Gruppe schalten"
    //    (EnableAction → RequestAction) + Integer "Gruppenstatus" 0/1/2
    //    (aus / teilweise / an). Erste bewusste Ausnahme von „MeterHub misst,
    //    steuert nicht" — beschränkt auf Mitglieder, die der Nutzer selbst
    //    als Schalter eingetragen hat.
    // 2. NUR POSITIVE Anteile werden geschaltet: ein „Aus" auf „Hausverbrauch
    //    ohne Wallboxen" darf keine Wallbox (−100) abwerfen.
    // 3. Erkennung automatisch + manuell korrigierbar: Bool-Variable mit
    //    Aktion am selben Gerät wie die Leistungsvariable wird vorgeschlagen;
    //    mehrdeutige Fälle (mehrere Schaltkanäle) bleiben leer und werden
    //    gemeldet, Spalte „Schalter" ist immer von Hand änderbar.
    //
    // Steuerhoheit (SUITE.md, EMS-Prioritätshierarchie): das hier ist reines
    // Einzel-/Gruppenschalten von Verbrauchern durch den Nutzer (Situation A
    // beim Nutzer, nicht beim EMS). Sobald so eine Gruppe lastmanagement-
    // relevant wird, gehört der Schreibkanal ins EMS — dann MHUBV_SwitchGroup
    // als Stellglied anbieten, nicht selbst Regeln einbauen.
    // -----------------------------------------------------------------------

    /**
     * Store-Checkliste Punkt 5: Profile nicht bei jedem ApplyChanges erzwingen.
     * Gesetzt wird nur, wenn noch keins da ist oder das vorhandene eines der
     * eigenen ist (NRG.*, MHB.*, MHBV.*) — ein vom Nutzer gewähltes fremdes
     * Profil bleibt unangetastet.
     */
    private static function ShouldSetProfile(string $current, string $wanted): bool
    {
        if ($current === $wanted) {
            return false;
        }
        return $current === '' || preg_match('/^(NRG|MHB|MHBV)\./', $current) === 1;
    }

    private const IDENT_GROUP_SWITCH = 'group_switch';
    private const IDENT_GROUP_STATE  = 'group_state';
    private const PROFILE_GROUP_STATE = 'MHBV.GroupState';

    /** Schalt-Variablen-IDs aller schaltbaren Mitglieder — nur positive Anteile, nur existierende Bool-Variablen. */
    private function SwitchableMembers(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if ($n['factor'] <= 0 || $n['switch'] <= 0 || !IPS_VariableExists($n['switch'])) {
                continue;
            }
            if ((int)(IPS_GetVariable($n['switch'])['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                continue;
            }
            $out[] = (int)$n['switch'];
        }
        return array_values(array_unique($out));
    }

    /** Legt die beiden Gruppen-Variablen an bzw. pflegt sie — nur wenn es schaltbare Mitglieder gibt (sonst räumt RegisterVariables() sie weg). */
    private function EnsureGroupVariables(array $switchables, int &$pos): void
    {
        if (!$switchables) {
            return;
        }
        $vid = @IPS_GetObjectIDByIdent(self::IDENT_GROUP_SWITCH, $this->InstanceID);
        if (!$vid) {
            $vid = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
            IPS_SetIdent($vid, self::IDENT_GROUP_SWITCH);
            IPS_SetParent($vid, $this->InstanceID);
        }
        IPS_SetName($vid, 'Gruppe schalten');
        IPS_SetPosition($vid, $pos++);
        if (self::ShouldSetProfile((string)(@IPS_GetVariable($vid)['VariableCustomProfile'] ?? ''), '~Switch')) {
            IPS_SetVariableCustomProfile($vid, '~Switch');
        }
        $this->EnableAction(self::IDENT_GROUP_SWITCH);

        $vid = @IPS_GetObjectIDByIdent(self::IDENT_GROUP_STATE, $this->InstanceID);
        if (!$vid) {
            $vid = IPS_CreateVariable(VARIABLETYPE_INTEGER);
            IPS_SetIdent($vid, self::IDENT_GROUP_STATE);
            IPS_SetParent($vid, $this->InstanceID);
        }
        IPS_SetName($vid, 'Gruppenstatus');
        IPS_SetPosition($vid, $pos++);
        if (self::ShouldSetProfile((string)(@IPS_GetVariable($vid)['VariableCustomProfile'] ?? ''), self::PROFILE_GROUP_STATE)) {
            IPS_SetVariableCustomProfile($vid, self::PROFILE_GROUP_STATE);
        }
        $this->RefreshGroupState($this->Nodes());
    }

    /**
     * Ist-Zustand der Gruppe aus den Mitgliedern: 0 = alle aus, 2 = alle an,
     * 1 = teilweise. Die Bool-Variable spiegelt „mindestens eines an" — so
     * zeigt ein Dashboard-Schalter „an", sobald irgendwo Licht brennt, und
     * ein Klick darauf schaltet die ganze Gruppe aus (Lichtgruppen-Konvention
     * wie bei KNX/HA-Gruppenadressen).
     */
    private function RefreshGroupState(array $nodes): void
    {
        $sw        = $this->SwitchableMembers($nodes);
        $vidSwitch = @IPS_GetObjectIDByIdent(self::IDENT_GROUP_SWITCH, $this->InstanceID);
        $vidState  = @IPS_GetObjectIDByIdent(self::IDENT_GROUP_STATE, $this->InstanceID);
        if (!$sw || !$vidSwitch || !$vidState) {
            return;
        }
        $on = 0;
        foreach ($sw as $vid) {
            if ((bool)GetValue($vid)) {
                $on++;
            }
        }
        $state = $on === 0 ? 0 : ($on === count($sw) ? 2 : 1);
        if ((int)GetValue($vidState) !== $state) {
            SetValueInteger($vidState, $state);
        }
        if ((bool)GetValue($vidSwitch) !== ($state > 0)) {
            SetValueBoolean($vidSwitch, $state > 0);
        }
    }

    /**
     * Schaltvariable eines Geräts finden: die EINE Bool-Variable mit
     * Aktion unterhalb des Geräte-Containers (Kategorien desselben Geräts
     * mit, nicht in fremde Instanzen hinein). Rückgabe [vid, hinweis]:
     * genau eine → vid; mehrere (Mehrkanal-Aktor) → 0 + Hinweis, weil Raten
     * hier den falschen Kanal schalten könnte; keine → 0.
     */
    private function SwitchOfDevice(int $deviceId): array
    {
        $o = @IPS_GetObject($deviceId);
        if (!$o || $o['ObjectType'] === 2) {
            return [0, ''];
        }
        $found = [];
        $stack = [$deviceId];
        while ($stack) {
            foreach (IPS_GetChildrenIDs((int)array_pop($stack)) as $cid) {
                $c = IPS_GetObject($cid);
                if ($c['ObjectType'] === 2) {
                    $v = IPS_GetVariable($cid);
                    $hasAction = (int)($v['VariableAction'] ?? 0) > 0 || (int)($v['VariableCustomAction'] ?? 0) > 0;
                    if ((int)($v['VariableType'] ?? -1) === VARIABLETYPE_BOOLEAN && $hasAction) {
                        $found[] = $cid;
                    }
                } elseif ($c['ObjectType'] === 0) {
                    $stack[] = $cid;
                }
            }
        }
        if (count($found) === 1) {
            return [$found[0], ''];
        }
        // Mehrere Kandidaten: genau EINE mit dem Ident "StatusVariable" ist
        // die Schaltvariable des Symcon-Z-Wave-Moduls — an 21 Aktoren in
        // Dietmars Anlage live belegt (Dashboard-Befund 11.09.2026), der
        // zweite Kandidat dort war "Daten (Boolean)"/DataVariableBoolean.
        // Eine feste Modul-Konvention, kein Raten; ohne sie (oder bei
        // mehreren) bleibt es beim Hinweis.
        if (count($found) > 1) {
            $status = array_values(array_filter($found, fn($vid) => (string)IPS_GetObject($vid)['ObjectIdent'] === 'StatusVariable'));
            if (count($status) === 1) {
                return [$status[0], ''];
            }
        }
        if (count($found) > 1) {
            return [0, 'mehrere schaltbare Variablen (' . implode(', ', array_map('IPS_GetName', $found)) . ') — bitte in der Spalte „Schalter" von Hand wählen'];
        }
        return [0, ''];
    }

    /** Symcon ruft das beim Umschalten der Variable „Gruppe schalten" (WebFront, Dashboard, Skript). */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === self::IDENT_GROUP_SWITCH) {
            $this->SwitchGroup((bool)$Value);
            return;
        }
        throw new Exception('Unbekannter Ident: ' . $Ident);
    }

    /**
     * Alle schaltbaren (positiven) Mitglieder gemeinsam schalten. Öffentlich
     * als MHUBV_SwitchGroup($id, $on) — damit auch Skripte und ein späteres
     * EMS-Stellglied denselben Weg nutzen, nicht nur der Variablen-Schalter.
     * Rückgabe ist ein lesbarer Ergebnistext (Verbund-Konvention „Sichtbare
     * Rückmeldung bei jeder Aktion").
     */
    public function SwitchGroup(bool $on): string
    {
        $nodes = $this->Nodes();
        $sw    = $this->SwitchableMembers($nodes);
        if (!$sw) {
            return 'ℹ️ Diese Gruppe hat keine schaltbaren Mitglieder (Spalte „Schalter" ist leer oder nur bei abgezogenen Zeilen gesetzt).';
        }
        $ok = 0;
        $failed = [];
        foreach ($sw as $vid) {
            try {
                if (@\RequestAction($vid, $on) === false) {
                    $failed[] = IPS_GetName($vid);
                } else {
                    $ok++;
                }
            } catch (\Throwable $e) {
                $failed[] = IPS_GetName($vid) . ' (' . $e->getMessage() . ')';
            }
        }
        $this->RefreshGroupState($nodes);
        $msg = ($on ? '✅ Eingeschaltet: ' : '✅ Ausgeschaltet: ') . $ok . ' von ' . count($sw) . ' Mitglied(ern).';
        if ($failed) {
            $msg .= ' ⚠️ Nicht geschaltet: ' . implode(', ', $failed) . '.';
        }
        return $msg;
    }

    /**
     * Formular-Knopf: für vorhandene Zeilen ohne Schalter die Schaltvariable
     * am Gerät der Leistungsvariable suchen. Schreibt wie ScanMeters() nur in
     * die OFFENE Maske — „Übernehmen" bleibt der bewusste letzte Schritt.
     */
    public function FindSwitches(string $nodesJson): string
    {
        $rows = json_decode($nodesJson, true);
        if (!is_array($rows)) {
            return '❌ Keine Zeilen übergeben.';
        }
        $tree = $this->IsTreeMode();
        $resolve = $tree ? $this->TreeRowResolver() : null;
        $set = 0;
        $notes = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r) || (int)($r['SwitchID'] ?? 0) > 0) {
                continue;
            }
            if ($tree) {
                // Baum-Modus: Gerät = Link-Ziel, falls es eine Instanz ist;
                // bei einem Link auf eine einzelne Variable deren Gerät.
                $n = $resolve($i, $r);
                if ($n === null || $n['broken']) {
                    continue;
                }
                $power = (int)($r['PowerID'] ?? 0) > 0 ? (int)$r['PowerID'] : $n['power'];
                $did = (int)IPS_GetObject($n['target'])['ObjectType'] === 1
                    ? $n['target']
                    : $this->DeviceOf($power > 0 ? $power : $n['target'])[0];
                $label = $n['name'] !== '' ? $n['name'] : 'Mitglied ' . ($i + 1);
            } else {
                if ((int)($r['PowerID'] ?? 0) <= 0) {
                    continue;
                }
                [$did] = $this->DeviceOf((int)$r['PowerID']);
                $label = trim((string)($r['Name'] ?? '')) !== '' ? $r['Name'] : 'Zeile ' . ($i + 1);
            }
            if ($did <= 0 || $did === $this->InstanceID) {
                continue;
            }
            [$sw, $note] = $this->SwitchOfDevice($did);
            if ($sw > 0) {
                $rows[$i]['SwitchID'] = $sw;
                $set++;
            } elseif ($note !== '') {
                $notes[] = '   ⚠️ ' . $label . ': ' . $note;
            }
        }
        if ($set > 0) {
            $this->UpdateFormField($tree ? 'MemberSettings' : 'Nodes', 'values', json_encode(array_values($rows)));
        }
        $msg = $set > 0
            ? "✅ $set Schalter gefunden und in die Spalte „Schalter\" eingetragen — bitte prüfen und „Übernehmen\" nicht vergessen."
            : 'ℹ️ Keine weiteren Schalter gefunden (Zeilen ohne Leistung oder ohne Bool-Variable mit Aktion am Gerät).';
        if ($notes) {
            $msg .= "\n" . implode("\n", $notes);
        }
        return $msg;
    }

    // Sekunden je Verdichtungstyp (0..6) — für den "wäre ein Leerlauf"-
    // Vergleich gegen das Roh-Intervall. Typ 7 (löschen) hat keine
    // Auflösung, wird in CompactionPlan() gesondert behandelt.
    private const COMPACTION_SECONDS = [0 => 60, 1 => 300, 2 => 3600, 3 => 86400, 4 => 604800, 5 => 2629746, 6 => 31556952];

    /** Auswahlliste für die Verdichtungstyp-Formularfelder (auch von GetConfigurationForm() genutzt). */
    private function CompactionTypeOptions(): array
    {
        return [
            ['caption' => '— aus —', 'value' => -1],
            ['caption' => '1× pro Minute', 'value' => 0],
            ['caption' => '1× pro 5 Minuten', 'value' => 1],
            ['caption' => '1× pro Stunde', 'value' => 2],
            ['caption' => '1× pro Tag', 'value' => 3],
            ['caption' => '1× pro Woche', 'value' => 4],
            ['caption' => '1× pro Monat', 'value' => 5],
            ['caption' => '1× pro Jahr', 'value' => 6],
            ['caption' => 'Werte löschen', 'value' => 7],
        ];
    }

    /** Sechs Formularfelder für eine Verdichtungs-Kategorie ('Power'/'Energy'), mit erklärender Zwischenüberschrift. */
    private function CompactionFields(string $kind): array
    {
        $opts = $this->CompactionTypeOptions();
        return [
            ['type' => 'CheckBox', 'name' => 'AutoCompaction' . $kind, 'caption' => 'Automatische Verdichtung aktivieren'],
            ['type' => 'Label', 'caption' => '⚠️ Ausschalten löscht aktiv alle drei Verdichtungsstufen dieser Kategorie im Archiv (auch von Hand in der Konsole gesetzte) — kein „einfach nicht mehr anfassen", sondern ein echtes Zurücksetzen auf „aus" bei jedem „Übernehmen".'],
            [
                'type' => 'PopupButton', 'caption' => 'Was bedeuten die drei Verdichtungsstufen?', 'width' => '480px',
                'popup' => [
                    'caption' => 'Archiv-Verdichtung in drei Stufen',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Verdichtung reduziert nachträglich den Detailgrad geloggter Werte, damit das Archiv nicht unbegrenzt wächst — je älter ein Wert, desto gröber genügt er meist. Drei Stufen, jede mit eigenem Zeitpunkt und eigener Ziel-Auflösung:'],
                        ['type' => 'Label', 'caption' => '„Direkt verdichten auf" — gilt sofort, ab dem ersten geloggten Wert.'],
                        ['type' => 'Label', 'caption' => '„Nach so vielen Monaten … verdichten auf" (zwei Stufen) — greift erst, sobald ein Wert das angegebene Alter erreicht hat.'],
                        ['type' => 'Label', 'caption' => 'Beispiel (Standardvorbelegung): direkt 1×/Minute, nach 1 Monat 1×/5 Minuten, nach 12 Monaten 1×/Stunde — Werte werden also mit der Zeit automatisch gröber, nie feiner.'],
                        ['type' => 'Label', 'caption' => 'Eine Stufe greift nur, wenn ihre Ziel-Auflösung tatsächlich GRÖBER ist als das Berechnungs-Intervall oben — sonst gäbe es dort nichts zu verdichten, die Stufe bleibt dann wirkungslos, auch wenn sie eingestellt ist.'],
                        ['type' => 'Label', 'caption' => '„— aus —" bei einer einzelnen Stufe LÖSCHT eine dort zuvor gesetzte Regel aktiv, genau wie der Hauptschalter oben — kein „einfach nicht mehr anfassen".'],
                    ],
                ],
            ],
            ['type' => 'Select', 'name' => 'CompactDirect' . $kind, 'caption' => 'Direkt verdichten auf', 'options' => $opts],
            ['type' => 'NumberSpinner', 'name' => 'CompactStage2Months' . $kind, 'caption' => 'Nach so vielen Monaten', 'minimum' => 0, 'maximum' => 120, 'suffix' => ' Monat(e)'],
            ['type' => 'Select', 'name' => 'CompactStage2Type' . $kind, 'caption' => '… verdichten auf', 'options' => $opts],
            ['type' => 'NumberSpinner', 'name' => 'CompactStage3Months' . $kind, 'caption' => 'Nach so vielen Monaten', 'minimum' => 0, 'maximum' => 120, 'suffix' => ' Monat(e)'],
            ['type' => 'Select', 'name' => 'CompactStage3Type' . $kind, 'caption' => '… verdichten auf', 'options' => $opts],
        ];
    }

    /**
     * Verdichtungs-Staffelung aus den Formular-Einstellungen und dem
     * bekannten Update-Intervall — konfigurierbar statt fest im Code
     * (Dietmars Einwand 31.08.2026: „ich bin nicht der einzigste Nutzer,
     * andere haben vielleicht andere Vorstellungen" — seine eigenen Werte
     * bleiben nur noch die VORBELEGUNG der drei Stufen, siehe Create()).
     * Getrennt nach Leistung/Energie (Dietmars Ergänzung, noch am selben
     * Tag: „wir müssen zwischen Leistungswerten und Energiewerten
     * unterscheiden" — beide können unabhängig vom Update-Takt ganz
     * andere Aufbewahrungs-Anforderungen haben), daher `$kind` = 'Power'
     * oder 'Energy' als Suffix der gelesenen Properties. Jede Stufe nur mit
     * ihrer gewählten Auflösung, wenn diese tatsächlich GRÖBER ist als das
     * Roh-Intervall — sonst wäre sie ein Leerlauf; Typ 7 (löschen) hat
     * keine Auflösung und wird immer übernommen, wenn gewählt. Intervall
     * bewusst aus der Instanz-eigenen Konfiguration gelesen statt aus der
     * Archiv-Historie geschätzt: die ist bei einer frisch angelegten
     * Instanz noch leer, und bei „nur Änderungen aufzeichnen" wäre die
     * tatsächliche Log-Dichte ohnehin kein zuverlässiges Maß für die
     * WIRKLICHE Update-Frequenz.
     * Rückgabe: IMMER drei [MonatsVersatz, Verdichtungstyp]-Paare für
     * `AC_SetCompaction()` — eine bewusst ausgeschaltete oder rechnerisch
     * überflüssige Stufe bekommt Verdichtungstyp -1 statt zu fehlen (siehe
     * SetArchive()).
     */
    private function CompactionPlan(int $intervalSeconds, string $kind): array
    {
        if (!$this->ReadPropertyBoolean('AutoCompaction' . $kind)) {
            // Aktiv zurücksetzen statt nur nichts mehr zu tun (Dietmars
            // Entscheidung 01.09.2026, konsistent zur Einzelstufen-Regel
            // oben): alle drei Stufen explizit auf -1, damit auch Regeln aus
            // einem früheren "an"-Zustand verschwinden. Der Hauptschalter
            // im Formular warnt deshalb ausdrücklich davor.
            return [
                [-1, -1],
                [(int)$this->ReadPropertyInteger('CompactStage2Months' . $kind), -1],
                [(int)$this->ReadPropertyInteger('CompactStage3Months' . $kind), -1],
            ];
        }
        $plan = [];
        $stages = [
            [-1, (int)$this->ReadPropertyInteger('CompactDirect' . $kind)],
            [(int)$this->ReadPropertyInteger('CompactStage2Months' . $kind), (int)$this->ReadPropertyInteger('CompactStage2Type' . $kind)],
            [(int)$this->ReadPropertyInteger('CompactStage3Months' . $kind), (int)$this->ReadPropertyInteger('CompactStage3Type' . $kind)],
        ];
        foreach ($stages as [$offset, $type]) {
            // Live verifiziert (Dietmars Fund 01.09.2026: "aus" eingestellt,
            // aber die Monate standen weiter in der Verdichtung): jede Stufe
            // bekommt IMMER einen AC_SetCompaction()-Aufruf, nie einfach
            // übersprungen — Verdichtungstyp -1 löscht die Regel am
            // jeweiligen Monats-Offset aktiv (bestätigt per AC_GetCompaction()
            // vorher/nachher), ein reines "nichts aufrufen" ließ eine schon
            // vorhandene Regel unangetastet stehen. Betrifft nicht nur ein
            // bewusstes "aus", sondern auch den rechnerischen Leerlauf-Fall
            // (Zielauflösung nicht gröber als das Intervall) — sonst bliebe
            // dort eine Regel aus einer früheren Modulversion oder einem
            // vorher größeren Intervall stehen.
            if ($type !== -1 && ($type === 7 || self::COMPACTION_SECONDS[$type] > $intervalSeconds)) {
                $plan[] = [$offset, $type];
            } else {
                $plan[] = [$offset, -1];
            }
        }
        return $plan;
    }

    private function SetArchive($vid, bool $counter = false, int $intervalSeconds = 10)
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($ids) > 0) {
            AC_SetLoggingStatus($ids[0], $vid, true);
            AC_SetAggregationType($ids[0], $vid, $counter ? 1 : 0);
            $plan = $this->CompactionPlan($intervalSeconds, $counter ? 'Energy' : 'Power');
            // Verwaiste Regeln an anderen Monats-Offsets zuerst entfernen —
            // Sepps zweiter Fund 01.09.2026 (Fehler "Verdichtungseinträge,
            // die näher an der Gegenwart sind, müssen lockerer als die
            // vorherigen sein"), live an Dietmars Archiv nachgestellt: IPS
            // verlangt, dass der Verdichtungstyp mit wachsendem Monats-
            // Offset nie wieder FEINER wird. Unser Plan selbst ist immer
            // konsistent (Stufen liegen aufsteigend), aber eine Regel an
            // einem Offset AUSSERHALB des aktuellen Plans — z. B. von einem
            // früher anderen "Nach so vielen Monaten"-Wert, oder weil die
            // Variable schon vor der Aufnahme in diese Instanz eine eigene
            // Verdichtung hatte (bei extern verdrahteten Datenpunkten wie
            // KNX-Zählern durchaus möglich) — kann diese Konsistenz brechen
            // und jeden künftigen "Übernehmen"-Versuch blockieren, bis sie
            // von Hand in der Konsole entfernt wird. Live verifiziert: nach
            // Bereinigung greift derselbe Plan anstandslos.
            $plannedOffsets = array_column($plan, 0);
            $existing = @AC_GetCompaction($ids[0], $vid);
            if (is_array($existing)) {
                foreach ($existing as $entry) {
                    $offset = (int)($entry['MonthOffset'] ?? 0);
                    if (!in_array($offset, $plannedOffsets, true)) {
                        @AC_SetCompaction($ids[0], $vid, $offset, -1);
                    }
                }
            }
            foreach ($plan as [$offset, $type]) {
                // @ ist hier bewusst und live geprüft (Sepps Fund 01.09.2026,
                // Fehler "Der zu löschende Verdichtungseintrag wurde nicht
                // gefunden"): löscht man Typ -1 an einem Monats-Offset, an
                // dem NIE zuvor eine Regel gesetzt war (z. B. "Direkt" gleich
                // beim Anlegen auf "aus"), wirft AC_SetCompaction() eine
                // PHP-Warnung — die lässt ApplyChanges() als "Fehler beim
                // Übernehmen" (Code -32603) fehlschlagen, obwohl fachlich
                // nichts schiefging (nichts zu löschen ist kein Fehler).
                // Live an Dietmars Archiv nachgestellt: ohne @ erscheint
                // exakt Sepps Warnung, mit @ nicht — anders als beim
                // ArgumentCountError aus dem MigrationsHub-Vorfall (siehe
                // CLAUDE.md) unterdrückt @ eine echte PHP-Warnung hier
                // zuverlässig, nur Fatal Errors kann @ nicht aufhalten.
                @AC_SetCompaction($ids[0], $vid, $offset, $type);
            }
        }
    }

    /**
     * Gemeinsame NRG-Stack-Profile: nur anlegen, wenn sie fehlen (Verbund-
     * Konvention 24.07.2026). MeterHubVirtual ist nicht Eigentümer — ein
     * anderes NRG-Stack-Modul kann dieselbe Definition schon angelegt haben;
     * ein fortlaufendes Überschreiben wäre ein stiller Konflikt.
     */
    private function CreateProfiles()
    {
        foreach ([['NRG.Watt', ' W', 0], ['NRG.kWh', ' kWh', 1]] as [$n, $suf, $dig]) {
            if (IPS_VariableProfileExists($n)) {
                continue;
            }
            IPS_CreateVariableProfile($n, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits($n, $dig);
            IPS_SetVariableProfileText($n, '', $suf);
        }
        // Gruppenstatus der Schaltgruppe — modulspezifisch (MHBV.*, kein
        // NRG.*-Kandidat: keine physikalische Grundgröße), wird wie die
        // eigenen Profile durchgesetzt, nicht nur bei Fehlen angelegt.
        if (!IPS_VariableProfileExists(self::PROFILE_GROUP_STATE)) {
            IPS_CreateVariableProfile(self::PROFILE_GROUP_STATE, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP_STATE, 0, 'Aus', 'Power', -1);
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP_STATE, 1, 'Teilweise an', 'Power', 0xFFA500);
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP_STATE, 2, 'An', 'Power', 0x00FF00);
    }

    // -----------------------------------------------------------------------
    // Berechnung
    // -----------------------------------------------------------------------

    /**
     * Rückgabe ist ein für den Formular-Knopf lesbarer Ergebnistext (Verbund-
     * Konvention „Sichtbare Rückmeldung bei jeder Aktion", 20.08.2026) — der
     * Aufruf über Timer/`$this->Recalc()` verwirft ihn einfach, das ist ohne
     * Nebenwirkung.
     */
    /**
     * Store-Checkliste 9c: Während Symcon ein Modul neu lädt, meldet jeder
     * SDK-Aufruf „InstanceInterface is not available“ (live 13.09.2026 aus
     * IsTreeMode/MemberSettingsMap/GetFunctions). Einstiegspunkte fragen das
     * zuerst still ab. function_exists nur für die Prüfstände.
     */
    private function InstanceReady(): bool
    {
        if (function_exists('IPS_GetKernelRunlevel') && IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }
        if (function_exists('IPS_InstanceExists') && !IPS_InstanceExists($this->InstanceID)) {
            return false;
        }
        error_clear_last();
        @$this->ReadPropertyBoolean('Active');
        $e = error_get_last();
        return $e === null || !str_contains((string)($e['message'] ?? ''), 'InstanceInterface');
    }

    public function Recalc(): string
    {
        if (!$this->InstanceReady()) {
            return 'ℹ️ Instanz wird gerade neu geladen, es wurde nichts berechnet.';
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return 'ℹ️ Instanz ist deaktiviert, es wurde nichts berechnet.';
        }
        $nodes = $this->Nodes();
        // Sicherheitsnetz des Baum-Modus: falls eine Objektbaum-Änderung
        // ohne passende Meldung durchgerutscht ist (Absender der OM_-/LM_-
        // Meldungen ist nicht dokumentiert), spätestens hier neu anwenden.
        // ApplyChanges() speichert den neuen Fingerabdruck und ruft Recalc()
        // selbst wieder auf — keine Schleife.
        if ($this->IsTreeMode() && $this->ReadAttributeString('TreeSignature') !== $this->TreeSignature($nodes)) {
            $this->SendDebug('Objektbaum', 'Mitglieder beim Berechnen verändert vorgefunden — wende neu an.', 0);
            $this->ApplyChanges();
            return '✅ Mitglieder aus dem Objektbaum neu übernommen und berechnet (' . date('H:i:s') . ' Uhr).';
        }
        $this->AdvanceCalculatedEnergy($nodes);

        $count = 0;
        $cont = json_decode((string)$this->ReadAttributeString('EnergyContinuity'), true);
        $cont = is_array($cont) ? $cont : [];
        $contChanged = false;
        foreach ($this->OutputDefs() as [$ident, , , $field]) {
            $sum = 0.0;
            $parts = [];
            foreach ($nodes as $n) {
                $vid = $n[$field];
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $sum += ($n['factor'] / 100.0) * (float)GetValue($vid);
                    if ((float)$n['factor'] !== 0.0) {
                        $parts[] = $vid . '*' . $n['factor'];
                    }
                }
            }
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid && is_finite($sum)) {
                // Zählerstände (Bezug/Einspeisung) laufen nahtlos weiter, auch
                // wenn sich die Zusammensetzung ändert; die Leistung nicht.
                if ($field !== 'power') {
                    sort($parts);
                    [$sum, $st] = self::ContinuityStep($cont[$ident] ?? null, implode(',', $parts), $sum, (float)GetValue($vid));
                    if (($cont[$ident] ?? null) !== $st) {
                        $cont[$ident] = $st;
                        $contChanged = true;
                    }
                }
                SetValueFloat($vid, $sum);
                $count++;
            }
        }
        if ($contChanged) {
            $this->WriteAttributeString('EnergyContinuity', (string)json_encode($cont));
        }

        // Gruppenstatus im selben Takt nachführen — ein von Hand oder von
        // einer anderen Automatik geschaltetes Mitglied ändert „teilweise/an"
        // sonst erst beim nächsten Gruppenschalten.
        $this->RefreshGroupState($nodes);

        return $count > 0
            ? "✅ Neu berechnet: $count Ausgabe(n) aktualisiert (" . date('H:i:s') . ' Uhr).'
            : 'ℹ️ Keine Ausgabe zum Berechnen vorhanden — erst oben Zähler eintragen und übernehmen.';
    }

    /**
     * Zählerstetigkeit einer Energie-Summe (0.28.3, Dietmars Wunsch
     * 13.09.2026, Anlass: Umstellung der „Ladestation" von ChargerHub auf
     * OCPPHub ließ die Summe um 41,7 kWh zurückspringen). Ändert sich die
     * Zusammensetzung ($sig: welche Zählervariablen mit welchem Anteil —
     * Mitglied hinzu/weg, Dublette umgestellt, „aktiv" geändert), springt die
     * Rohsumme. Ein Summen-Zählerstand darf das nicht: Archiv und Tagesbalken
     * werten einen Sprung als Riesenverbrauch bzw. einen Rückschritt als
     * Reset. Dann wird der Unterschied zum letzten Ausgabewert als Ausgleich
     * festgehalten, die Ausgabe läuft nahtlos weiter. Beim ersten Mal (kein
     * Zustand) nur merken, nichts rückwirkend ändern. Frei von Symcon-
     * Aufrufen (Prüfstand). Rückgabe [Ausgabe, neuer Zustand].
     */
    private static function ContinuityStep(?array $state, string $sig, float $raw, float $prev): array
    {
        if ($state === null || !isset($state['sig'])) {
            return [$raw, ['sig' => $sig, 'offset' => 0.0]];
        }
        $offset = (float)($state['offset'] ?? 0.0);
        if ($state['sig'] !== $sig && $prev > 0) {
            $offset = $prev - $raw;
        }
        return [$raw + $offset, ['sig' => $sig, 'offset' => $offset]];
    }

    /** Hinweise für „Prüfung & Vorschau": wie weit ein Zählerstand wegen des Ausgleichs von der Summe der Mitglieder abweicht. */
    private function ContinuityNotes(): array
    {
        $cont = json_decode((string)$this->ReadAttributeString('EnergyContinuity'), true);
        $out = [];
        foreach ((is_array($cont) ? $cont : []) as $ident => $st) {
            $off = (float)($st['offset'] ?? 0.0);
            if (abs($off) < 0.0005) {
                continue;
            }
            $label = $ident === 'energy_export' ? 'Einspeisung' : 'Bezug';
            $out[] = $label . ': Ausgleich ' . ($off > 0 ? '+' : '−') . number_format(abs($off), 3, ',', '.') . ' kWh wegen geänderter Zusammensetzung — der Zählerstand läuft nahtlos weiter und weicht deshalb um diesen Wert von der Summe der Mitglieder ab.';
        }
        return $out;
    }

    /**
     * Für den Formular-Knopf „Jetzt neu berechnen": Sepps Fund 01.09.2026 —
     * der Knopf meldete zwar per echo Erfolg, aber das Panel „Prüfung &
     * Vorschau" (aus den Werten im BEREITS OFFENEN Formular berechnet) blieb
     * auf den alten Werten stehen. Stolperfalle 12 (SUITE.md, EMS-Fund
     * 20.08.2026): ein per onClick aufgerufener Button aktualisiert ein
     * bereits offenes Formular nicht automatisch. Das Vorschau-Panel hat
     * keinen eigenen `name` und lässt sich daher nicht gezielt per
     * UpdateFormField patchen — ReloadForm() baut das ganze Formular mit den
     * frisch berechneten Werten neu auf (dieselbe, von InverterHub live
     * bestätigte Alternative, die auch MeterHubDiscovery::Discover() nutzt).
     * Der Timer-Aufruf von Recalc() bleibt davon unberührt — der ruft nach
     * wie vor die einfache Recalc() direkt auf, ein ständiges ReloadForm()
     * im Hintergrund wäre unnötig und störend.
     */
    public function RecalcAndRefreshForm(): string
    {
        $msg = $this->Recalc();
        $this->ReloadForm();
        return $msg;
    }

    // -----------------------------------------------------------------------
    // Automatische Suche nach Zähler-Datenpunkten im System
    // -----------------------------------------------------------------------

    private const GUID_VIRTUAL = '{ADF18291-2E60-4354-92F5-B96863C127C8}';
    // Für die "Standort"-Vorschlagsliste (Dietmars Auftrag 01.09.2026: das
    // Feld soll auch bei echten MeterHub-Zählern existieren, mit GETEILTER
    // Vorschlagsliste über beide Modultypen hinweg).
    private const GUID_METER = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';

    /**
     * Klassifiziert eine Variable anhand ihres Profil-Suffixes.
     * Rückgabe: 'power' | 'import' | '' (unbrauchbar).
     */
    private function Classify(int $vid): string
    {
        $v = @IPS_GetVariable($vid);
        // Nur Zahlen — ein Schalter-Status in W wäre sinnlos.
        if (!$v || ($v['VariableType'] !== 1 && $v['VariableType'] !== 2)) {
            return '';
        }
        $u = strtolower(str_replace(' ', '', $this->UnitOf($vid)));
        if ($u === 'w' || $u === 'kw') {
            return 'power';
        }
        if ($u === 'kwh' || $u === 'wh') {
            return 'import';
        }
        return '';
    }

    // Bekannte NRG-Stack-Module (Verbund-Entscheidung 25.07.2026, Dietmar): Ihre
    // Variablen sollen der Suchlauf NICHT als „Fremdzähler" vorschlagen. Zwei
    // Gründe — (1) sie sind „im NRG-Stack beheimatet", tauchen an ihrer
    // eigentlichen Stelle schon korrekt auf; (2) Zirkularität: eine vom EMS
    // berechnete Hauslast, die selbst aus MeterHub-Rohdaten stammt, würde sonst
    // in einen virtuellen Zähler einfließen können, der wieder in dieselbe
    // Berechnung zurückwirkt (Doppelzählung, im Extremfall eine Kette).
    //
    // Bewusst NICHT gelistet: MeterHub selbst (`{BAB8E05C-…}`) — dessen
    // Instanzen sind der Suchlauf-Zweck, nicht das, wovor er schützen soll.
    // MeterHubVirtual (dieses Modul) ist über den bestehenden `$ownOutputs`-
    // Mechanismus bereits präziser abgedeckt (schließt nur die tatsächlichen
    // Ausgabevariablen aus, nicht die ganze Instanz).
    //
    // GUIDs am 25.07.2026 live an Dietmars Installation abgelesen
    // (IPS_GetModuleList() + IPS_GetModule()), nicht geraten. Bei einem neuen
    // NRG-Stack-Mitglied hier ergänzen — sonst taucht dessen Modul unbemerkt
    // wieder als „Fremdzähler" im Suchlauf auf.
    private const EXCLUDED_NRG_STACK_MODULES = [
        '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}', // EMS
        '{BBE2C593-1A91-426D-A714-29A9C7E87589}', // InverterHub
        '{9A2E5C7F-3B1D-4A6E-8C9F-2D5B7E1A4C8F}', // InverterHubTile
        '{C3E7A1F4-9B2D-4E6A-8F1C-7A5B3D9E2C08}', // InverterHubEnergy
        '{7B1F9A34-6C52-4E8D-9A1B-4F3E2D7C6A19}', // InverterHubMonitor
        '{447C2BD6-5299-445A-9A08-5F29C50C9DB1}', // InverterHubDiscovery
        '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}', // ChargerHub
        '{613D9807-B975-91B2-C6BD-FDD3654EF87E}', // ChargerHubDiscovery
        '{1919151A-3C0F-4C09-B906-291638EC1469}', // HeishaMon
        '{7F7B979E-0D9F-4E4A-9C0D-2A3B1B0A4D21}', // TessieConfigurator
        '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}', // TessieVehicle
        '{ACAFF26A-C6AB-4D45-B51B-3832BE5C2CFA}', // TessieVehicleTile
        '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}', // TibberGridReward
        '{D5A8C3A1-2222-4A55-8888-123456789003}', // StromGedacht Widget
        '{E9B65213-BA33-426D-8486-D350A7DFCFEF}', // StromGedachtTile
        '{257DD4E8-9705-462E-89FC-56D0A1038353}', // PVPrognose
        '{DC5AD508-507F-40EA-8630-0959AED83050}', // Lastprognose
        '{330717BB-E309-41A2-90A8-FDA3179ED948}', // MigrationsHub
        '{83996C8A-1C77-424B-81D3-0A4AFFE54263}', // RollingAverage (GleitenderMittelwert)
        '{B76BE0BA-DF99-4B81-81BD-636A610011EE}', // SteuerboxHub
        '{1C4B7E2A-8F3D-5A9C-4E1B-7D2F9A3C6E8B}', // GoodweET
        '{3E8A1D5C-9F2B-4C7A-6E3D-1B5F8A2C4E7D}', // GoodweETTile
        '{CA700334-0982-F356-0617-6952868137E9}', // StrukturHub (GUID von der StrukturHub-Sitzung gemeldet, 28.08.2026)
    ];

    /**
     * Gehört $vid (direkt oder über eine Kette von Kategorien/Instanzen) zu
     * einer bekannten NRG-Stack-Instanz? Läuft bis zur Wurzel durch — nicht nur
     * bis zur nächsten Instanz —, falls Instanzen ineinander verschachtelt
     * sind (z. B. hinter einem I/O-Splitter).
     */
    private function BelongsToExcludedModule(int $vid): bool
    {
        $pid = IPS_GetParent($vid);
        while ($pid > 0) {
            $o = IPS_GetObject($pid);
            if ($o['ObjectType'] === 1) {
                $mid = @IPS_GetInstance($pid)['ModuleInfo']['ModuleID'] ?? '';
                if (in_array($mid, self::EXCLUDED_NRG_STACK_MODULES, true)) {
                    return true;
                }
            }
            $pid = $o['ParentID'];
        }
        return false;
    }

    /** Gerätename: der nächste übergeordnete Container/Instanz der Variable. */
    private function DeviceOf(int $vid): array
    {
        $pid = IPS_GetParent($vid);
        while ($pid > 0) {
            $o = IPS_GetObject($pid);
            // Instanz (1) oder Kategorie (0) gilt als „Gerät".
            if ($o['ObjectType'] === 1 || $o['ObjectType'] === 0) {
                return [$pid, IPS_GetName($pid)];
            }
            $pid = $o['ParentID'];
        }
        return [0, IPS_GetName($vid)];
    }

    /**
     * Funktions-Zuordnung ('grid'/'light'/'heatpump'/… oder '') der
     * Ursprungsinstanz von $vid — für den "ScanOnlyFunction"-Suchfilter
     * (Dietmars Anregung 02.09.2026). Läuft wie BelongsToExcludedModule()
     * die Elternkette hoch bis zur nächsten Instanz und fragt DEREN
     * MHUB_GetFunctions()/MHUBV_GetFunctions() ab, ob genau diese Variable
     * dort einer Funktion zugeordnet ist. MHUB_/MHUBV_ sind eigene, nicht
     * fremde Präfixe (siehe .tools/check-standalone.php) — kein
     * function_exists()-Wächter nötig, beide Module gehören zum selben Repo
     * und werden gemeinsam ausgeliefert.
     */
    private function OriginFunctionFor(int $vid): string
    {
        $pid = IPS_GetParent($vid);
        while ($pid > 0) {
            $o = IPS_GetObject($pid);
            if ($o['ObjectType'] === 1) {
                $mid = @IPS_GetInstance($pid)['ModuleInfo']['ModuleID'] ?? '';
                if ($mid === self::GUID_METER) {
                    return $this->FunctionForVarIn($vid, @MHUB_GetFunctions($pid));
                }
                if ($mid === self::GUID_VIRTUAL) {
                    return $this->FunctionForVarIn($vid, @MHUBV_GetFunctions($pid));
                }
                return '';
            }
            $pid = $o['ParentID'];
        }
        return '';
    }

    private function FunctionForVarIn(int $vid, $json): string
    {
        $data = json_decode((string)$json, true);
        // *_GetFunctions() liefert ein Objekt mit 'assignments' als Liste,
        // keine nackte Liste — siehe MHUB_GetFunctions()/MHUBV_GetFunctions().
        $list = is_array($data) ? ($data['assignments'] ?? []) : [];
        if (!is_array($list)) {
            return '';
        }
        foreach ($list as $a) {
            if (!is_array($a)) {
                continue;
            }
            foreach (['powerID', 'energyImportID', 'energyExportID'] as $f) {
                if ((int)($a[$f] ?? 0) === $vid) {
                    return (string)($a['function'] ?? '');
                }
            }
        }
        return '';
    }

    /** Erste Variable mit passendem Ident unterhalb von $rootId (auch über Kategorien hinweg, nicht über verschachtelte Instanzen). */
    private function FindByIdent(int $rootId, string $ident): int
    {
        $stack = [$rootId];
        while ($stack) {
            foreach (IPS_GetChildrenIDs((int)array_pop($stack)) as $cid) {
                $o = IPS_GetObject($cid);
                if ($o['ObjectIdent'] === $ident && $o['ObjectType'] === 2) {
                    return $cid;
                }
                if ($o['ObjectType'] === 0) {
                    $stack[] = $cid;
                }
            }
        }
        return 0;
    }

    /**
     * Findet zu einem gewählten Gerät (Instanz oder Kategorie) automatisch
     * dessen Leistungs-/Bezugs-/Einspeisungs-Variablen — Dietmars Anregung
     * 31.08.2026: „ich möchte die Zählerinstanz auswählen müssen und der
     * Rest muss von alleine kommen", weil drei einzelne Variablen-Picker pro
     * Zeile „zu Tode klickt". Zwei Stufen:
     * 1. Bekannte NRG-Stack-Idents (`power_total`/`energy_import`/
     *    `energy_export`, wie MeterHub sie vergibt) — eindeutig, kein Raten.
     * 2. Sonst: Kinder wie im Suchlauf nach W-/kWh-Profil klassifizieren
     *    (`Classify()`). Ein W-Fund ist immer eindeutig Leistung. Bei kWh
     *    ist NICHT unterscheidbar, ob Bezug oder Einspeisung gemeint ist
     *    (beide sind einfach "kWh") — der ERSTE Fund wird als Bezug
     *    vorgeschlagen (der weit häufigere Fall), weitere kommen als
     *    `extra` zurück und werden im Ergebnistext genannt, statt sie
     *    stillschweigend zu verwerfen oder falsch zu raten.
     */
    private function MetersOfDevice(int $deviceId): array
    {
        // Sonderfall: die "id" ist selbst schon eine Variable (Suchlauf-Fund
        // ohne erkennbaren Geräte-Container, siehe DeviceOf()-Fallback) —
        // dann direkt klassifizieren statt (erfolglos) nach Kindern zu
        // suchen, Variablen haben keine Kinder.
        $o = @IPS_GetObject($deviceId);
        if ($o && $o['ObjectType'] === 2) {
            $kind = $this->Classify($deviceId);
            return [
                'power' => $kind === 'power' ? $deviceId : 0,
                'imp'   => $kind === 'import' ? $deviceId : 0,
                'exp'   => 0,
                'extra' => [],
            ];
        }

        // Bietet das Ziel selbst einen *_GetFunctions-Vertrag an (ChargerHub,
        // HeishaMon …), weiß es am besten, welche Variable die Gesamtleistung
        // und welcher Zähler kumulativ ist — Dashboard-Befund 11.09.2026.
        $contract = $this->ContractMetersOf($deviceId);
        if ($contract !== null) {
            return $contract;
        }

        $power = $this->FindByIdent($deviceId, 'power_total');
        // Sepps Live-Fund 02.09.2026: eine MeterHubVirtual-Instanz als Quelle
        // für eine ANDERE MeterHubVirtual-Instanz übernehmen ("mehrstufige
        // Verschachtelung über mehrere Instanzen", siehe Doku-Panel) — Bezug/
        // Einspeisung fanden sich, weil beide Module dafür denselben Ident
        // verwenden, "Leistung" aber nicht: MeterHub nennt seine Ausgabe
        // "power_total", die eigene RegisterVariables() hier schlicht
        // "power". Ohne diesen zweiten Versuch bricht die Suche außerdem VOR
        // der generischen Fallback-Suche unten ab, sobald Bezug/Einspeisung
        // schon etwas gefunden haben (Zeile "if ($power > 0 || …)").
        if ($power === 0) {
            // „power" wie power_total: so heißt die Ausgabe von
            // MeterHubVirtual (Sepps Fund 02.09.2026) und die Gesamt-
            // Ladeleistung von ChargerHub (Dashboard-Befund 11.09.2026) —
            // ein Ident ist eindeutiger als jede Suche.
            $power = $this->FindByIdent($deviceId, 'power');
        }
        $imp   = $this->FindByIdent($deviceId, 'energy_import');
        $exp   = $this->FindByIdent($deviceId, 'energy_export');
        $guid  = (int)(@IPS_GetObject($deviceId)['ObjectType'] ?? -1) === 1
            ? (string)(@IPS_GetInstance($deviceId)['ModuleInfo']['ModuleID'] ?? '') : '';
        // Eigene Module sind über ihre Idents vollständig beschrieben — keine
        // generische Suche (sie fände z. B. die „Energie hochgerechnet"-
        // Variablen einer virtuellen Instanz, die zu deren Mitgliedern gehören).
        if (($power > 0 && ($imp > 0 || $exp > 0)) || in_array($guid, [self::GUID_VIRTUAL, self::GUID_METER], true)) {
            return ['power' => $power, 'imp' => $imp, 'exp' => $exp, 'extra' => [], 'extraPower' => []];
        }

        // Generische Suche für das, was die Idents nicht geliefert haben —
        // deterministisch gerankt statt „erste Variable in Baumreihenfolge".
        // Dashboard-Befund 11.09.2026: bei ChargerHub WB 1 lag die Kategorie
        // „Phasen" in der Suchreihenfolge vorn, gewählt wurde „Leistung L2"
        // (ein Drittel beim dreiphasigen Laden) statt der Gesamtleistung.
        $powers = [];
        $kwh    = [];
        $stack  = [[$deviceId, 0]];
        while ($stack) {
            [$pid, $depth] = array_pop($stack);
            foreach (IPS_GetChildrenIDs((int)$pid) as $cid) {
                $o = IPS_GetObject($cid);
                if ($o['ObjectType'] === 2) {
                    $kind = $this->Classify($cid);
                    if ($kind === 'power') {
                        $powers[] = [$cid, $depth, $this->MeterPenalty($o), (int)($o['ObjectPosition'] ?? 0)];
                    } elseif ($kind === 'import') {
                        $kwh[] = [$cid, $depth, $this->MeterPenalty($o), (int)($o['ObjectPosition'] ?? 0)];
                    }
                } elseif ($o['ObjectType'] === 0) {
                    // Kategorien innerhalb DESSELBEN Geräts weiter durchsuchen —
                    // nicht in verschachtelte fremde Instanzen hineingehen.
                    $stack[] = [$cid, $depth + 1];
                }
            }
        }
        // Rang: Gesamtwert vor Ladevorgangs-/Tageswert vor Phase, dann
        // weniger tief verschachtelt, dann Position, dann Objekt-ID.
        $rank = fn(array $a, array $b): int => [$a[2], $a[1], $a[3], $a[0]] <=> [$b[2], $b[1], $b[3], $b[0]];
        usort($powers, $rank);
        usort($kwh, $rank);
        $extraPower = [];
        if ($power === 0 && $powers) {
            $power = $powers[0][0];
            // Weitere GLEICHWERTIGE Gesamtwerte (kein Phasenwert, gleiche
            // Tiefe) nennen statt still zu entscheiden.
            foreach (array_slice($powers, 1) as $p) {
                if ($p[2] === 0 && $powers[0][2] === 0 && $p[1] === $powers[0][1]) {
                    $extraPower[] = $p[0];
                }
            }
        }
        $extra = [];
        if ($imp === 0 && $exp === 0 && $kwh) {
            $imp   = $kwh[0][0];
            $extra = array_column(array_slice($kwh, 1), 0);
        }
        return ['power' => $power, 'imp' => $imp, 'exp' => $exp, 'extra' => $extra, 'extraPower' => $extraPower];
    }

    /**
     * Nachrang eines Messwerts in der generischen Suche: 2 = Phasenwert
     * (Ident/Name mit L1/L2/L3 oder „Phase"), 1 = Ladevorgangs-/Tages-/
     * Zwischenwert (springt zurück, taugt nicht als Zählerstand), 0 = Gesamtwert.
     */
    private function MeterPenalty(array $o): int
    {
        $txt = mb_strtolower((string)$o['ObjectIdent'] . ' ' . (string)$o['ObjectName']);
        if (preg_match('/(^|[^a-z0-9])(l[123]|phase\w*)([^a-z0-9]|$)/u', $txt)) {
            return 2;
        }
        if (preg_match('/session|sitzung|ladevorgang|zwischen|heute|today|tages/u', $txt)) {
            return 1;
        }
        return 0;
    }

    /**
     * Datenpunkte aus dem *_GetFunctions-Vertrag des Ziels, falls es einen
     * anbietet — generisch über das Modul-Präfix (IPS_GetModule()['Prefix'],
     * laut SDK-Doku seit 6.1), also für jedes NRG-Stack-Modul, ohne dessen
     * Namen hier zu kennen. Versteht beide Schreibweisen im Verbund
     * (powerID/energyImportID bzw. HeishaMons PowerID/EnergyID). Nur bei
     * GENAU einer Zuordnung mit Datenpunkt — mehrere (z. B. Verdichter und
     * Heizstab) wären Raten, dann entscheidet die Suche. Ein fehlerhafter
     * Partner-Vertrag degradiert zu null statt die Instanz abzubrechen
     * (Lehre aus dem MigrationsHub-Vorfall 30.08.2026). Eigene Module
     * (MeterHub, MeterHubVirtual) laufen weiter über ihre Idents.
     */
    private function ContractMetersOf(int $instanceId): ?array
    {
        if ((int)(@IPS_GetObject($instanceId)['ObjectType'] ?? -1) !== 1) {
            return null;
        }
        $guid = (string)(@IPS_GetInstance($instanceId)['ModuleInfo']['ModuleID'] ?? '');
        if ($guid === '' || $guid === self::GUID_VIRTUAL || $guid === self::GUID_METER) {
            return null;
        }
        $prefix = (string)(@IPS_GetModule($guid)['Prefix'] ?? '');
        $fn = $prefix . '_GetFunctions';
        if ($prefix === '' || !function_exists($fn)) {
            return null;
        }
        try {
            $res = $fn($instanceId);
        } catch (\Throwable $e) {
            return null;
        }
        if (is_string($res)) {
            $res = json_decode($res, true);
        }
        if (!is_array($res) || $res === []) {
            return null;
        }
        if (isset($res['assignments']) && is_array($res['assignments'])) {
            $entries = $res['assignments'];
        } else {
            $entries = array_keys($res) === range(0, count($res) - 1) ? $res : [$res];
        }
        $found = [];
        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }
            $m = [
                'power' => (int)($e['powerID'] ?? $e['PowerID'] ?? 0),
                'imp'   => (int)($e['energyImportID'] ?? $e['EnergyImportID'] ?? $e['EnergyID'] ?? 0),
                'exp'   => (int)($e['energyExportID'] ?? $e['EnergyExportID'] ?? 0),
            ];
            // Nur kumulative Zählerstände taugen als Energie (Vertrags-Regel 3).
            if ((string)($e['energyKind'] ?? 'counter') !== 'counter') {
                $m['imp'] = 0;
                $m['exp'] = 0;
            }
            foreach ($m as $k => $vid) {
                if ($vid > 0 && !IPS_VariableExists($vid)) {
                    $m[$k] = 0;
                }
            }
            if ($m['power'] > 0 || $m['imp'] > 0 || $m['exp'] > 0) {
                $found[] = $m;
            }
        }
        if (count($found) !== 1) {
            return null;
        }
        return $found[0] + ['extra' => [], 'extraPower' => []];
    }

    /**
     * Übernimmt ein per Picker gewähltes Gerät als neue Formel-Zeile mit
     * automatisch gefundenen Datenpunkten. Schreibt wie ScanMeters() nur in
     * die OFFENE Formularmaske, nicht in die gespeicherte Property —
     * „Übernehmen" bleibt der bewusste letzte Schritt. Anders als der frühere
     * Suchlauf-Autoeintrag (bis 0.24.4) ist das hier unkritisch: es ist genau
     * EIN Gerät, das der Nutzer selbst gezielt ausgewählt hat, kein
     * blindes Einsammeln vieler Funde auf einmal.
     */
    public function AddDevice(int $deviceId): string
    {
        if ($deviceId <= 0 || !IPS_ObjectExists($deviceId)) {
            return '❌ Kein Gerät ausgewählt.';
        }
        if ($this->IsTreeMode()) {
            return $this->AddDeviceToTree($deviceId);
        }
        $m = $this->MetersOfDevice($deviceId);
        if ($m['power'] === 0 && $m['imp'] === 0 && $m['exp'] === 0) {
            return '❌ „' . IPS_GetName($deviceId) . '" — keine passenden Leistungs-/Energie-Datenpunkte gefunden (weder bekannte NRG-Stack-Idents noch W-/kWh-Profil darunter). Bitte stattdessen unten „Hinzufügen" nutzen und die Variable von Hand wählen.';
        }
        // Schaltgruppe: die Schaltvariable des Geräts gleich mit vorschlagen
        // (Dietmars Entscheidung 03.09.2026: automatisch + manuell
        // korrigierbar). Mehrdeutig → leer + Hinweis, nie geraten.
        [$sw, $swNote] = $this->SwitchOfDevice($deviceId);
        $rows = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        $rows = is_array($rows) ? $rows : [];
        $rows[] = [
            'Name' => IPS_GetName($deviceId), 'Factor' => 100,
            'PowerID' => $m['power'], 'EnergyImportID' => $m['imp'], 'EnergyExportID' => $m['exp'],
            'SwitchID' => $sw,
        ];
        $this->UpdateFormField('Nodes', 'values', json_encode($rows));
        $this->UpdateFormField('Nodes', 'rowCount', $this->RowCountFor(count($rows)));

        $parts   = [];
        $parts[] = $m['power'] > 0 ? 'Leistung „' . IPS_GetName($m['power']) . '"' : 'Leistung: nicht gefunden';
        $parts[] = $m['imp']   > 0 ? 'Bezug „' . IPS_GetName($m['imp']) . '"' : 'Bezug: nicht gefunden';
        $parts[] = $m['exp']   > 0 ? 'Einspeisung „' . IPS_GetName($m['exp']) . '"' : 'Einspeisung: nicht gefunden';
        if ($sw > 0) {
            $parts[] = 'Schalter „' . IPS_GetName($sw) . '"';
        } elseif ($swNote !== '') {
            $parts[] = 'Schalter: ' . $swNote;
        }
        $msg = '✅ „' . IPS_GetName($deviceId) . '" als neue Zeile übernommen — ' . implode(', ', $parts) . '.';
        if (!empty($m['extra'])) {
            $names = array_map('IPS_GetName', $m['extra']);
            $msg .= "\n⚠️ Weitere kWh-Datenpunkte an diesem Gerät gefunden, aber nicht automatisch zugeordnet — könnten Einspeisung statt Bezug sein, bitte prüfen und bei Bedarf von Hand in die Zeile eintragen: " . implode(', ', $names) . '.';
        }
        $msg .= ' Bitte in der Tabelle prüfen und „Übernehmen“ nicht vergessen.';
        return $msg;
    }

    /**
     * Baum-Modus von AddDevice(): legt SOFORT einen Link unter dieser Instanz
     * an (ein Objekt im Baum lässt sich nicht „nur im offenen Formular"
     * anlegen) — deshalb vorher alle Ablehnungsgründe prüfen. Die Datenpunkte
     * werden danach bei jedem Takt frisch am Ziel aufgelöst, nicht
     * eingefroren; nur der Schalter-Vorschlag wird gespeichert.
     */
    private function AddDeviceToTree(int $deviceId): string
    {
        $name = IPS_GetName($deviceId);
        if ($deviceId === $this->InstanceID) {
            return '❌ Diese Instanz kann nicht ihr eigenes Mitglied sein.';
        }
        foreach ($this->TreeMembers() as $mem) {
            if ($mem['target'] === $deviceId) {
                return 'ℹ️ „' . $name . '" ist bereits Mitglied (als „' . $mem['name'] . '").';
            }
        }
        $isVar = (int)IPS_GetObject($deviceId)['ObjectType'] === 2;
        if ($isVar) {
            $kind = $this->Classify($deviceId);
            $m = ['power' => $kind === 'power' ? $deviceId : 0, 'imp' => $kind === 'import' ? $deviceId : 0, 'exp' => 0, 'extra' => []];
        } else {
            $m = $this->MetersOfDevice($deviceId);
        }
        if ($m['power'] === 0 && $m['imp'] === 0 && $m['exp'] === 0) {
            return '❌ „' . $name . '" — keine passenden Leistungs-/Energie-Datenpunkte gefunden (weder bekannte NRG-Stack-Idents noch W-/kWh-Profil). Nichts verknüpft. Eine einzelne Variable ohne erkennbare Einheit lässt sich trotzdem als Link unter diese Instanz legen und dann in der Mitglieder-Tabelle mit einer Rolle versehen.';
        }
        $seen = [];
        if (!$isVar && $this->ReferencesInstance($deviceId, $this->InstanceID, $seen, 0)) {
            return '❌ „' . $name . '" enthält selbst (direkt oder verschachtelt) diese Instanz — das wäre ein Kreisverweis. Nichts verknüpft.';
        }
        [$sw, $swNote] = $this->SwitchOfDevice($isVar ? $this->DeviceOf($deviceId)[0] : $deviceId);
        $link = $this->CreateMemberLink($deviceId, $name);
        // Schalter nicht als Übersteuerung speichern — TreeNodes() findet ihn
        // bei jedem Takt selbst, auch wenn er am Gerät später wechselt.
        $this->StoreMemberSettings([$link => ['Factor' => 100]]);
        $this->ReloadForm();

        $parts   = [];
        $parts[] = $m['power'] > 0 ? 'Leistung „' . IPS_GetName($m['power']) . '"' : 'Leistung: nicht gefunden';
        $parts[] = $m['imp']   > 0 ? 'Bezug „' . IPS_GetName($m['imp']) . '"' : 'Bezug: nicht gefunden';
        $parts[] = $m['exp']   > 0 ? 'Einspeisung „' . IPS_GetName($m['exp']) . '"' : 'Einspeisung: nicht gefunden';
        if ($sw > 0) {
            $parts[] = 'Schalter „' . IPS_GetName($sw) . '"';
        } elseif ($swNote !== '') {
            $parts[] = 'Schalter: ' . $swNote;
        }
        $msg = '✅ „' . $name . '" als Mitglied verknüpft (Link im Objektbaum unter dieser Instanz, ans Ende der Reihenfolge) — ' . implode(', ', $parts) . '.';
        if (!empty($m['extra'])) {
            $names = array_map('IPS_GetName', $m['extra']);
            $msg .= "\n⚠️ Weitere kWh-Datenpunkte an diesem Gerät gefunden, aber nicht automatisch zugeordnet — könnten Einspeisung statt Bezug sein, bei Bedarf in der Mitglieder-Tabelle übersteuern: " . implode(', ', $names) . '.';
        }
        $msg .= ' Bereits wirksam — kein „Übernehmen" nötig. Rückgängig: den Link im Objektbaum löschen.';
        return $msg;
    }

    /**
     * Namens-Suffixe bekannter Aktor-Familien, bei denen jeder Messwert eine
     * EIGENE KNX-Instanz ist (kein gemeinsamer Geräte-Container mit mehreren
     * Kindern wie beim normalen Geräte-Picker) — live an Sepps MDT-AZI-
     * Installation verifiziert 01.09.2026 (Diagnose-Skript-Auswertung, nicht
     * geraten): z. B. "AZI Waschmaschine Wirkleistung" und "AZI Waschmaschine
     * Hauptzähler kWh" sind zwei getrennte Instanzen, die nur der gemeinsame
     * Namens-Anfang verbindet. Der Kind-Ident der eigentlichen Messwert-
     * Variable ist bei diesen Instanzen immer "Value" (ebenfalls verifiziert).
     * Zwei Schreibweisen für "Hauptzähler kWh", weil Sepps eigene KNX-Projekt-
     * beschriftung selbst uneinheitlich ist (mal "ä", mal "ae").
     *
     * Classify()/UnitOf() finden diese Wirkleistungs-Variablen NICHT über den
     * normalen Suchlauf — sie tragen weder ein klassisches Profil noch eine
     * SUFFIX-/PROFILE-Darstellung, nur eine reine TEMPLATE-Darstellung ohne
     * auslesbare Einheit (ebenfalls live bestätigt). Nur der Name verrät hier
     * die Bedeutung, deshalb ein eigener, namensbasierter Erkennungsweg statt
     * einer Erweiterung von UnitOf().
     *
     * "Zwischenzähler" wird bewusst NICHT als Energiequelle erkannt: laut
     * Namen ein rücksetzbarer Zwischenstand, kein dauerhaft aufsummierender
     * Hauptzähler — würde dem Archiv Sprünge nach unten liefern.
     *
     * Zweites Muster (01.09.2026, Sepps zweiter Diagnose-Dump): Lingg&Janke-
     * KNX-Zähler wie "Haus Zähler". Anders als bei AZI tragen dessen
     * Variablen zwar ein echtes System-Profil (der normale Suchlauf findet
     * sie also einzeln) — aber Bezug und Einspeisung stehen dort als VIER
     * getrennte, alle einzeln positive Werte (P14/P23 Leistung, A14/A23
     * Energie in kWh, live an Sepps Anlage verifiziert), kein
     * vorzeichenbehafteter Netzwert wie beim PAC2200. Ein Klick auf
     * „Geräte-Familie erkennen" spart hier das manuelle Verdrahten der in
     * AddDeviceFamily() beschriebenen Drei-Zeilen-Lösung (siehe dort). Die
     * L1/L2/L3-Phasenaufteilung UND die (Wh)-Duplikate (statt kWh) werden
     * bewusst NICHT erkannt — `mb_substr(...) === $suffix` prüft exakt das
     * Ende des Namens, "P14 L1 (W)" endet nicht auf "p14 (w)".
     */
    private const DEVICE_FAMILY_SUFFIXES = [
        'power' => ['wirkleistung'],
        'imp'   => ['hauptzähler kwh', 'hauptzaehler kwh'],
        'power_import' => ['wirkleistung p14 (w)'],
        'power_export' => ['wirkleistung p23 (w)'],
        'energy_import' => ['wirkenergie a14 (kwh)'],
        'energy_export' => ['wirkenergie a23 (kwh)'],
    ];

    /**
     * Findet unterhalb von $rootId (Kategorie oder Instanz, rekursiv über
     * Kategorien) alle Instanzen, deren Name auf einen bekannten Suffix aus
     * DEVICE_FAMILY_SUFFIXES endet, gruppiert sie über den gemeinsamen
     * Namens-Anfang zu einem "Gerät" und liest deren Kind-Variable mit Ident
     * "Value" aus. Reine Erkennung, keine IPS-Änderung.
     */
    private function FindDeviceFamilies(int $rootId): array
    {
        $devices = [];
        $stack = [$rootId];
        while ($stack) {
            foreach (IPS_GetChildrenIDs((int)array_pop($stack)) as $cid) {
                $o = IPS_GetObject($cid);
                if ($o['ObjectType'] === 0) {
                    $stack[] = $cid; // Kategorie: weiter absteigen
                    continue;
                }
                if ($o['ObjectType'] !== 1) {
                    continue; // nur Instanzen tragen hier den bedeutungstragenden Namen
                }
                $name = $o['ObjectName'];
                $lower = mb_strtolower(trim($name));
                // Sepps Live-Fund 01.09.2026: "Zähler Stichtag Wirkenergie A14
                // (kWh)" endet zufällig auf denselben Suffix wie der echte
                // laufende Zähler ("...Wirkenergie A14 (kWh)") — ist aber ein
                // Stichtags-Schnappschuss (Wert zu einem festen Datum), keine
                // laufende Summe. Genau wie "Zwischenzähler" bewusst NICHT als
                // Energiequelle erkannt, sonst entsteht eine falsche Extra-Zeile.
                if (mb_strpos($lower, 'stichtag') !== false) {
                    continue;
                }
                foreach (self::DEVICE_FAMILY_SUFFIXES as $role => $suffixes) {
                    $matched = false;
                    foreach ($suffixes as $suffix) {
                        if (mb_substr($lower, -mb_strlen($suffix)) !== $suffix) {
                            continue;
                        }
                        $matched = true;
                        $label = trim(mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix)));
                        $valueVid = $label !== '' ? (int)@IPS_GetObjectIDByIdent('Value', $cid) : 0;
                        if ($valueVid > 0) {
                            if (!isset($devices[$label])) {
                                $devices[$label] = [
                                    'label' => $label, 'power' => 0, 'imp' => 0,
                                    'power_import' => 0, 'power_export' => 0,
                                    'energy_import' => 0, 'energy_export' => 0,
                                ];
                            }
                            if ($devices[$label][$role] === 0) {
                                $devices[$label][$role] = $valueVid;
                            }
                        }
                        break;
                    }
                    if ($matched) {
                        break; // eine Instanz kann nur einer Rolle entsprechen
                    }
                }
            }
        }
        return $devices;
    }

    /**
     * Übernimmt eine ganze Geräte-Familie als neue Formel-Zeilen — Dietmars
     * Auftrag 01.09.2026, ausgelöst durch Sepps Diagnose-Ergebnisse. Zwei
     * unterschiedliche Muster, je nachdem welche Felder FindDeviceFamilies()
     * für ein Gerät gefunden hat:
     *
     * 1. AZI-Muster ('power'/'imp'): EINE Zeile, Leistung + Bezug zusammen,
     *    Faktor 100 — dort gibt es keinen gemeinsamen Geräte-Container, den
     *    der normale Geräte-Picker (AddDevice()) nehmen könnte.
     * 2. Lingg&Janke-Muster ('power_import'/'power_export'/'energy_import'/
     *    'energy_export'): BIS ZU DREI Zeilen, weil P14/P23 zwei getrennte,
     *    immer positive Leistungswerte sind (keine vorzeichenbehaftete
     *    Netzleistung wie beim PAC2200) — siehe die volle Herleitung in der
     *    Konversation mit Dietmar (01.09.2026): P14 und A14 teilen sich eine
     *    Zeile (beide brauchen Faktor +100), P23 braucht eine EIGENE Zeile
     *    mit Faktor −100 (nur die Leistungs-Spalte, sonst würde A23 mit ins
     *    Negative rutschen), A23 wieder eine eigene mit Faktor +100.
     *
     * Beide Muster können am selben Gerät gleichzeitig zutreffen (theoretisch
     * denkbar, in der Praxis nicht beobachtet) — dann entstehen einfach
     * beide Zeilensätze. Schreibt wie AddDevice() nur in die OFFENE
     * Formularmaske, nicht in die gespeicherte Property.
     */
    public function AddDeviceFamily(int $rootId): string
    {
        if ($rootId <= 0 || !IPS_ObjectExists($rootId)) {
            return '❌ Kein Bereich ausgewählt.';
        }
        $devices = $this->FindDeviceFamilies($rootId);
        if (!$devices) {
            return '❌ Unter „' . IPS_GetName($rootId) . '" wurde kein bekanntes Muster gefunden (aktuell erkannt: Instanzen, die auf „Wirkleistung"/„Hauptzähler kWh" enden, z. B. MDT AZI, oder auf „Wirkleistung P14/P23 (W)"/„Wirkenergie A14/A23 (kWh)", z. B. Lingg&Janke). Bitte stattdessen den Geräte-Picker oder „Hinzufügen" in der Tabelle nutzen.';
        }

        $tree = $this->IsTreeMode();
        $existing = $tree ? [] : json_decode((string)$this->ReadPropertyString('Nodes'), true);
        $existing = is_array($existing) ? $existing : [];
        $used = [];
        foreach ($existing as $r) {
            if (!is_array($r)) {
                continue;
            }
            foreach (['PowerID', 'EnergyImportID', 'EnergyExportID'] as $f) {
                $v = (int)($r[$f] ?? 0);
                if ($v > 0) {
                    $used[$v] = true;
                }
            }
        }
        if ($tree) {
            foreach ($this->TreeNodes() as $n) {
                foreach (['power', 'imp', 'exp'] as $f) {
                    if ($n[$f] > 0) {
                        $used[$n[$f]] = true;
                    }
                }
            }
        }
        $newRows = [];

        $added = [];
        $addedRows = 0;
        $alreadyUsed = [];
        $useField = function (array $d, string $field, string $label, string $fieldLabel) use ($used, &$alreadyUsed): int {
            $vid = (int)$d[$field];
            if ($vid <= 0) {
                return 0;
            }
            if (isset($used[$vid])) {
                $alreadyUsed[] = "$label ($fieldLabel)";
                return 0;
            }
            return $vid;
        };
        foreach ($devices as $label => $d) {
            $rowsForDevice = [];

            $power = $useField($d, 'power', $label, 'Leistung');
            $imp   = $useField($d, 'imp', $label, 'Bezug');
            if ($power > 0 || $imp > 0) {
                $rowsForDevice[] = ['Name' => $label, 'Factor' => 100, 'PowerID' => $power, 'EnergyImportID' => $imp, 'EnergyExportID' => 0];
            }

            $powerImport  = $useField($d, 'power_import', $label, 'Bezug-Leistung');
            $powerExport  = $useField($d, 'power_export', $label, 'Einspeisung-Leistung');
            $energyImport = $useField($d, 'energy_import', $label, 'Bezug-Energie');
            $energyExport = $useField($d, 'energy_export', $label, 'Einspeisung-Energie');
            if ($powerImport > 0 || $energyImport > 0) {
                $rowsForDevice[] = ['Name' => $label . ' — Bezug', 'Factor' => 100, 'PowerID' => $powerImport, 'EnergyImportID' => $energyImport, 'EnergyExportID' => 0];
            }
            if ($powerExport > 0) {
                // Eigene Zeile, NICHT mit der Bezugs-Zeile zusammengelegt:
                // die Leistung braucht hier Faktor -100 (signierte Summe),
                // eine gemeinsame Zeile würde ein gleichzeitig eingetragenes
                // A23 fälschlich mit ins Negative ziehen.
                $rowsForDevice[] = ['Name' => $label . ' — Einspeisung (Leistung)', 'Factor' => -100, 'PowerID' => $powerExport, 'EnergyImportID' => 0, 'EnergyExportID' => 0];
            }
            if ($energyExport > 0) {
                $rowsForDevice[] = ['Name' => $label . ' — Einspeisung (Energie)', 'Factor' => 100, 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => $energyExport];
            }

            if (!$rowsForDevice) {
                continue; // nichts Neues an diesem Gerät
            }
            foreach ($rowsForDevice as $r) {
                $existing[] = $r;
                $newRows[] = $r;
                $addedRows++;
            }
            $added[] = $label;
        }

        if (!$added) {
            return 'ℹ️ Alle gefundenen Geräte (' . implode(', ', array_keys($devices)) . ') sind bereits vollständig verdrahtet.';
        }

        if ($tree) {
            // Je Zeile ein Link auf ihren ersten Datenpunkt, die übrigen als
            // Übersteuerung — dieselbe Rechnung wie die Tabellen-Zeilen oben
            // (inkl. −100 für P23), nur als Objekte im Baum.
            $patch = [];
            foreach ($newRows as $r) {
                [$link, $s] = $this->LinkRowAsMember($r);
                $patch[$link] = $s;
            }
            $this->StoreMemberSettings($patch);
            $this->ReloadForm();
            $msg = '✅ ' . count($added) . ' Gerät(e) als ' . $addedRows . ' Mitglied(er) im Objektbaum verknüpft: ' . implode(', ', $added) . '.';
            if ($alreadyUsed) {
                $msg .= "\nℹ️ Bereits Mitglied, deshalb nicht erneut verknüpft: " . implode(', ', $alreadyUsed) . '.';
            }
            return $msg . ' Bereits wirksam — kein „Übernehmen" nötig.';
        }

        $this->UpdateFormField('Nodes', 'values', json_encode($existing));
        $this->UpdateFormField('Nodes', 'rowCount', $this->RowCountFor(count($existing)));

        $msg = '✅ ' . count($added) . ' Gerät(e) als ' . $addedRows . ' neue Zeile(n) übernommen: ' . implode(', ', $added) . '.';
        if ($alreadyUsed) {
            $msg .= "\nℹ️ Bereits anderswo verdrahtet, deshalb nicht erneut eingetragen: " . implode(', ', $alreadyUsed) . '.';
        }
        $msg .= ' Bitte in der Tabelle prüfen und „Übernehmen“ nicht vergessen.';
        return $msg;
    }

    /**
     * Ident-Präfix für selbst gepflegte "Energie hochgerechnet"-Variablen
     * (AddCalculatedEnergy()/AdvanceCalculatedEnergy()). Dietmars
     * Grundsatzeinwand 01.09.2026: "kannst Du nicht von Schätzung reden",
     * weil Leistung × Berechnungs-Intervall eine echte Rechnung aus real
     * gemessenen Werten ist, keine Vermutung — deshalb im gesamten
     * Nutzertext "hochgerechnet", nie "geschätzt". Der Ident selbst bleibt
     * technisches Englisch (API, nie übersetzt, siehe Sprachregel).
     * Enthält die PowerID statt einer Zeilen-Nummer, weil Zeilen per Drag &
     * Drop umsortierbar sind und seit 0.24.0 keinen eigenen Ident mehr
     * tragen — die PowerID ist der stabile Bezug.
     */
    private const CALC_ENERGY_IDENT_PREFIX = 'calc_energy_';

    /** Ist $vid eine von dieser Instanz selbst angelegte "Energie hochgerechnet"-Variable? */
    private function IsOwnCalculatedEnergyVar(int $vid): bool
    {
        if ($vid <= 0 || !IPS_VariableExists($vid) || @IPS_GetParent($vid) !== $this->InstanceID) {
            return false;
        }
        $ident = IPS_GetObject($vid)['ObjectIdent'] ?? '';
        return str_starts_with($ident, self::CALC_ENERGY_IDENT_PREFIX);
    }

    /** Legt bei Bedarf die "Energie hochgerechnet"-Variable für $powerId an (idempotent über den Ident) und liefert ihre ID. */
    private function EnsureCalculatedEnergyVar(int $powerId, string $label): int
    {
        $ident = self::CALC_ENERGY_IDENT_PREFIX . $powerId;
        $existing = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($existing) {
            return $existing;
        }
        $this->CreateProfiles();
        $vid = IPS_CreateVariable(VARIABLETYPE_FLOAT);
        IPS_SetIdent($vid, $ident);
        IPS_SetParent($vid, $this->InstanceID);
        IPS_SetName($vid, $label . ' — Energie hochgerechnet');
        IPS_SetPosition($vid, 500);
        IPS_SetVariableCustomProfile($vid, 'NRG.kWh');
        IPS_SetInfo($vid, 'Hochgerechnet aus Leistung × Berechnungs-Intervall, kein echter Zählerstand — Genauigkeit hängt vom Intervall ab.');
        SetValueFloat($vid, 0.0);
        return $vid;
    }

    /**
     * Trägt für Zeilen mit Leistung, aber ohne Bezug (kWh) im OFFENEN
     * Formular eine hochgerechnete Bezugs-Variable ein — Dietmars Auftrag
     * 01.09.2026, ausgelöst durch AZI-Geräte, die nur eine Wirkleistung
     * liefern (z. B. "AZI Backofen", siehe FindDeviceFamilies()). Schreibt
     * wie AddDevice()/AddDeviceFamily() nur ins offene Formular — die neue
     * Variable existiert danach zwar schon real in IPS (sie muss, um eine
     * ID für das Formularfeld zu haben), wird aber erst mit „Übernehmen"
     * dauerhaft in die Formel-Summe einbezogen und archiviert (siehe
     * RegisterVariables()).
     */
    public function AddCalculatedEnergy(string $nodesJson): string
    {
        $rows = json_decode($nodesJson, true);
        $rows = is_array($rows) ? $rows : [];
        $tree = $this->IsTreeMode();
        $resolve = $tree ? $this->TreeRowResolver() : null;
        $touched = [];
        foreach ($rows as $i => &$r) {
            if (!is_array($r)) {
                continue;
            }
            $powerId = (int)($r['PowerID'] ?? 0);
            $impId   = (int)($r['EnergyImportID'] ?? 0);
            $label   = trim((string)($r['Name'] ?? ''));
            if ($tree) {
                // Wirksam ist die Übersteuerung, sonst der automatisch
                // gefundene Wert des Mitglieds.
                $n = $resolve($i, $r);
                if ($n === null || $n['broken']) {
                    continue;
                }
                $powerId = $powerId > 0 ? $powerId : $n['power'];
                $impId   = $impId > 0 ? $impId : $n['imp'];
                $label   = $n['name'];
            }
            if ($powerId <= 0 || $impId > 0 || !IPS_VariableExists($powerId)) {
                continue;
            }
            $label = $label !== '' ? $label : ('Zeile ' . ($i + 1));
            $r['EnergyImportID'] = $this->EnsureCalculatedEnergyVar($powerId, $label);
            $touched[] = $label;
        }
        unset($r);

        if (!$touched) {
            return 'ℹ️ Keine Zeile gefunden, die eine Leistung, aber keinen Bezug hat — nichts zu tun.';
        }

        $this->UpdateFormField($tree ? 'MemberSettings' : 'Nodes', 'values', json_encode($rows));
        $msg = '✅ ' . count($touched) . ' Zeile(n) bekommen eine hochgerechnete Bezugs-Variable (Leistung × Berechnungs-Intervall — keine Schätzung, aber ungenauer als ein echter Zähler): ' . implode(', ', $touched) . '.';
        $msg .= ' Bitte in der Tabelle prüfen und „Übernehmen“ nicht vergessen.';
        return $msg;
    }

    /**
     * Rechnet alle SELBST gepflegten "Energie hochgerechnet"-Variablen um
     * ein weiteres Berechnungs-Intervall hoch (Leistung × Intervall,
     * aufsummiert auf den bisherigen Stand) — läuft VOR der eigentlichen
     * Summenbildung in Recalc(), damit der neue Stand noch im selben
     * Durchlauf einfließt. Erkennt eigene Variablen ausschließlich über
     * IsOwnCalculatedEnergyVar() (Ident-Präfix + Elternschaft), kein
     * zusätzliches Register nötig — bleibt so auch über Zeilen-
     * Umsortierung (Drag & Drop) hinweg stabil, weil sie an der PowerID
     * hängt, nicht an der Zeilen-Position.
     */
    private function AdvanceCalculatedEnergy(array $nodes): void
    {
        $intervalHours = max(2, $this->ReadPropertyInteger('Interval')) / 3600.0;
        foreach ($nodes as $n) {
            $powerId = (int)$n['power'];
            if ($powerId <= 0 || !IPS_VariableExists($powerId)) {
                continue;
            }
            $deltaKWh = (float)GetValue($powerId) * $intervalHours / 1000.0;
            if (!is_finite($deltaKWh)) {
                continue;
            }
            foreach (['imp', 'exp'] as $ef) {
                $vid = (int)$n[$ef];
                if ($vid > 0 && $this->IsOwnCalculatedEnergyVar($vid)) {
                    SetValueFloat($vid, (float)GetValue($vid) + $deltaKWh);
                }
            }
        }
    }

    /** Liegt $vid irgendwo unterhalb von $root? ($root = 0: ganze Installation) */
    private function IsBelow(int $vid, int $root): bool
    {
        if ($root <= 0) {
            return true;
        }
        $pid = IPS_GetParent($vid);
        while ($pid > 0) {
            if ($pid === $root) {
                return true;
            }
            $pid = IPS_GetParent($pid);
        }
        return false;
    }

    /**
     * Durchsucht die Installation nach Leistungs-/Energie-Datenpunkten und
     * zeigt sie als reine Fundstellen-Übersicht im Ergebnistext — schreibt
     * NICHT in die Formel-Tabelle (Dietmars Rückmeldung 31.08.2026: das
     * bisherige automatische Eintragen jedes Fundes war unübersichtlich,
     * jeder unerwünschte Fund musste einzeln entfernt werden). Aufnehmen
     * geschieht bewusst über den nativen Symcon-Variablenpicker am
     * „Hinzufügen"-Knopf der Tabelle unten.
     *
     * Die Filter kommen aus der Maske und werden im onClick übergeben, damit
     * eine noch nicht übernommene Änderung sofort greift.
     */
    public function ScanMeters($root = null, $filter = null, $needEnergy = null, $onlyActive = null)
    {
        // Feste Arität (SUITE.md 9e, Migrationsvergleich 13.09.2026): Symcon
        // erzeugt MHUBV_ScanMeters mit genau diesen 4 Parametern — Skripte,
        // die so aufrufen, dürfen nach einem Update nicht brechen. Die
        // Erweiterung um zwei Filter liegt deshalb in ScanMetersEx().
        return $this->ScanMetersEx(
            $root === null ? null : (int)$root,
            $filter === null ? null : (string)$filter,
            $needEnergy === null ? null : (bool)$needEnergy,
            $onlyActive === null ? null : (bool)$onlyActive
        );
    }

    /** Wie ScanMeters(), zusätzlich „Nur schon anderswo genutzte“ und „Nur Datenpunkte mit Funktion X“. */
    public function ScanMetersEx(?int $root = null, ?string $filter = null, ?bool $needEnergy = null, ?bool $onlyActive = null, ?bool $onlyUsedElsewhere = null, ?string $onlyFunction = null)
    {
        // Direktaufruf ohne Argumente (Skript, Konsole): gespeicherte Filter.
        $root              = $root              === null ? $this->ReadPropertyInteger('ScanRoot')              : (int)$root;
        $filter            = $filter            === null ? $this->ReadPropertyString('ScanFilter')             : (string)$filter;
        $needEnergy        = $needEnergy        === null ? $this->ReadPropertyBoolean('ScanNeedEnergy')        : (bool)$needEnergy;
        $onlyActive        = $onlyActive        === null ? $this->ReadPropertyBoolean('ScanOnlyActive')        : (bool)$onlyActive;
        $onlyUsedElsewhere = $onlyUsedElsewhere === null ? $this->ReadPropertyBoolean('ScanOnlyUsedElsewhere')  : (bool)$onlyUsedElsewhere;
        $onlyFunction      = $onlyFunction      === null ? $this->ReadPropertyString('ScanOnlyFunction')       : (string)$onlyFunction;
        $filter       = trim($filter);
        $onlyFunction = trim($onlyFunction);

        if ($root > 0 && !IPS_ObjectExists($root)) {
            $this->UpdateFormField('ScanResult', 'caption', "❌ Der gewählte Suchbereich (#$root) existiert nicht mehr.");
            $this->UpdateFormField('ScanResult', 'visible', true);
            return;
        }
        $existing = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        $existing = is_array($existing) ? $existing : [];

        $used = [];
        foreach ($existing as $r) {
            if (!is_array($r)) {
                continue;
            }
            foreach (['PowerID', 'EnergyImportID', 'EnergyExportID'] as $f) {
                $v = (int)($r[$f] ?? 0);
                if ($v > 0) {
                    $used[$v] = true;
                }
            }
        }

        // Ausgabevariablen ALLER virtuellen Zähler ausschließen — sonst könnte
        // ein berechneter Wert wieder als Quelle einfließen (Rückkopplung).
        $ownOutputs = [];
        foreach (IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL) as $iid) {
            foreach (IPS_GetChildrenIDs($iid) as $cid) {
                $ownOutputs[$cid] = true;
            }
        }

        // Kreuz-Instanz-Verwendung: welche Datenpunkte stecken schon in einer
        // ANDEREN MeterHubVirtual-Instanz? Validate() prüft Doppelverwendung
        // bisher nur innerhalb einer Instanz — hier geht es instanzübergreifend
        // (Dietmars Anregung 31.08.2026).
        $usedElsewhere = [];
        foreach (IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL) as $iid) {
            if ($iid === $this->InstanceID) {
                continue;
            }
            $otherRows = json_decode(@IPS_GetProperty($iid, 'Nodes') ?: '[]', true);
            if (!is_array($otherRows)) {
                continue;
            }
            $otherName = IPS_GetName($iid);
            foreach ($otherRows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                foreach (['PowerID', 'EnergyImportID', 'EnergyExportID'] as $f) {
                    $v = (int)($r[$f] ?? 0);
                    if ($v > 0) {
                        $usedElsewhere[$v][$otherName] = true;
                    }
                }
            }
        }

        $devices = [];
        $skipped = ['einheit' => 0, 'schonverwendet' => 0, 'virtuell' => 0, 'bereich' => 0, 'name' => 0, 'verbund' => 0, 'andereinstanz' => 0, 'funktion' => 0];

        foreach (IPS_GetVariableList() as $vid) {
            if (isset($ownOutputs[$vid])) { $skipped['virtuell']++; continue; }
            if (isset($used[$vid]))       { $skipped['schonverwendet']++; continue; }
            if ($onlyUsedElsewhere) {
                if (!isset($usedElsewhere[$vid])) { continue; }
            } elseif (isset($usedElsewhere[$vid])) {
                $skipped['andereinstanz']++;
                continue;
            }
            $kind = $this->Classify($vid);
            if ($kind === '') { $skipped['einheit']++; continue; }
            if (!$this->IsBelow($vid, $root)) { $skipped['bereich']++; continue; }
            // Teurere Prüfungen (laufen die Elternkette hoch) bewusst zuletzt,
            // erst nachdem die billigen Filter schon aussortiert haben.
            if ($this->BelongsToExcludedModule($vid)) { $skipped['verbund']++; continue; }
            if ($onlyFunction !== '' && $this->OriginFunctionFor($vid) !== $onlyFunction) { $skipped['funktion']++; continue; }

            [$did, $dname] = $this->DeviceOf($vid);
            if ($filter !== '' && mb_stripos($dname, $filter) === false && mb_stripos(IPS_GetName($vid), $filter) === false) {
                $skipped['name']++;
                continue;
            }
            $key = $did > 0 ? 'd' . $did : 'v' . $vid;
            if (!isset($devices[$key])) {
                // "id" = das, was AddDevice() später bekommt: der Geräte-
                // Container, wenn vorhanden, sonst (DeviceOf() fällt ohne
                // Instanz/Kategorie auf die Variable selbst zurück) die
                // Variable direkt — MetersOfDevice() erkennt diesen Fall.
                $devices[$key] = ['id' => $did > 0 ? $did : $vid, 'name' => $dname, 'power' => 0, 'import' => 0, 'usedIn' => []];
            }
            // Je Gerät den ersten brauchbaren Datenpunkt je Art nehmen.
            if ($devices[$key][$kind] === 0) {
                $devices[$key][$kind] = $vid;
            }
            if (isset($usedElsewhere[$vid])) {
                foreach (array_keys($usedElsewhere[$vid]) as $on) {
                    $devices[$key]['usedIn'][$on] = true;
                }
            }
        }

        $found = [];
        $notes = [];
        // Funde werden zugleich als Auswahlliste angeboten (Dietmars
        // Anregung 31.08.2026: „wenn ich schon etwas suchen muss, dann
        // möchte ich auch direkt aus dem Suchdialog etwas übernehmen") —
        // "value" ist die ID, die AddDevice() bekommt (Gerät oder, falls
        // DeviceOf() keins fand, die Variable selbst).
        $scanOptions = [['caption' => '— Fund wählen —', 'value' => 0]];
        $filteredOut = ['ohneenergie' => 0, 'inaktiv' => 0];
        foreach ($devices as $d) {
            if ($d['power'] === 0 && $d['import'] === 0) {
                continue;
            }
            if ($needEnergy && $d['import'] === 0) {
                $filteredOut['ohneenergie']++;
                continue;
            }
            if ($onlyActive) {
                $newest = 0;
                foreach (['power', 'import'] as $f) {
                    if ($d[$f] > 0) {
                        $newest = max($newest, (int)(@IPS_GetVariable($d[$f])['VariableUpdated'] ?? 0));
                    }
                }
                if ($newest === 0 || time() - $newest > 7 * 86400) {
                    $filteredOut['inaktiv']++;
                    continue;
                }
            }
            $warn = [];
            if ($d['import'] > 0 && !$this->IsArchived($d['import'])) {
                $warn[] = 'Energie nicht archiviert (für Langzeitauswertung nötig)';
            }
            if ($d['power'] > 0) {
                $age = time() - (int)(@IPS_GetVariable($d['power'])['VariableUpdated'] ?? 0);
                if ($age > 7 * 86400) {
                    $warn[] = 'Leistung seit über 7 Tagen nicht aktualisiert';
                }
            }
            if (count($d['usedIn']) > 0) {
                $warn[] = 'bereits verwendet in „' . implode('“, „', array_keys($d['usedIn'])) . '“ — bei Aufnahme hier auf Doppelzählung/Aufteilung achten';
            }
            $found[] = $d['name'];
            $scanOptions[] = ['caption' => $d['name'], 'value' => $d['id']];
            if ($warn) {
                $notes[] = '   ⚠️ ' . $d['name'] . ': ' . implode('; ', $warn);
            }
        }

        $scope = [];
        if ($root > 0)          { $scope[] = 'nur unterhalb „' . IPS_GetName($root) . '“'; }
        if ($filter !== '')     { $scope[] = 'Name enthält „' . $filter . '“'; }
        if ($needEnergy)        { $scope[] = 'nur mit Energiezähler'; }
        if ($onlyActive)        { $scope[] = 'nur in den letzten 7 Tagen aktualisiert'; }
        if ($onlyUsedElsewhere) { $scope[] = 'nur Datenpunkte, die schon in einer anderen Instanz stecken'; }
        if ($onlyFunction !== '') { $scope[] = 'nur Funktion „' . (self::FUNCTIONS[$onlyFunction][0] ?? $onlyFunction) . '“'; }

        // Trägt weiterhin NICHT automatisch in die Formel-Tabelle ein (bis
        // 0.24.4: jeder Fund landete automatisch als neue Zeile, musste bei
        // Nichtgefallen einzeln mit dem Papierkorb entfernt werden — genau
        // das fand Dietmar unübersichtlich). Die Funde stehen aber jetzt
        // direkt als Auswahlliste unter dem Ergebnistext bereit
        // ("ScanPick" + Knopf) — auswählen und übernehmen, ohne den
        // Umweg über den separaten Geräte-Picker weiter unten.
        $msg = count($found) > 0
            ? '🔎 ' . count($found) . ' Gerät(e) gefunden: ' . implode(', ', $found) . '. Unten in „Fund auswählen" wählen und übernehmen — oder in der Tabelle weiter unten „Hinzufügen" für die manuelle Variablen-Auswahl.'
            : '🔎 Keine Geräte gefunden.';
        $msg .= "\nSuchbereich: " . ($scope ? implode(', ', $scope) : 'ganze Installation, ungefiltert');
        $msg .= sprintf("\nÜbersprungen: %d ohne W/kWh-Profil, %d bereits eingetragen, %d Ausgaben virtueller Zähler, %d aus anderen NRG-Stack-Modulen, %d schon in einer anderen virtuellen Zähler-Instanz, %d außerhalb des Suchbereichs, %d durch den Namensfilter, %d durch den Funktionsfilter, %d ohne Energiezähler, %d länger als 7 Tage still.",
            $skipped['einheit'], $skipped['schonverwendet'], $skipped['virtuell'], $skipped['verbund'], $skipped['andereinstanz'],
            $skipped['bereich'], $skipped['name'], $skipped['funktion'], $filteredOut['ohneenergie'], $filteredOut['inaktiv']);
        if (count($found) === 0 && ($filteredOut['ohneenergie'] + $filteredOut['inaktiv'] + $skipped['bereich'] + $skipped['name'] + $skipped['funktion']) > 0) {
            $msg .= "\n💡 Es wurde etwas gefunden, aber wegfiltriert — probeweise einen Filter lockern.";
        }
        if ($notes) {
            $msg .= "\n" . implode("\n", $notes);
        }

        $this->UpdateFormField('ScanResult', 'caption', $msg);
        $this->UpdateFormField('ScanResult', 'visible', true);
        $this->UpdateFormField('ScanPick', 'options', json_encode($scanOptions));
        $this->UpdateFormField('ScanPick', 'value', 0);
    }

    /** Sichtbare Zeilen der Formel-Liste: wächst mit dem Inhalt, bleibt aber übersichtlich (typischerweise wenige Terme). */
    private function RowCountFor(int $count): int
    {
        return max(6, min(20, $count + 3));
    }

    private function IsArchived(int $vid): bool
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 && (bool)@AC_GetLoggingStatus($ids[0], $vid);
    }

    // -----------------------------------------------------------------------
    // Vertrag (siehe Konvention in CLAUDE.md) — gleiche Struktur wie
    // MHUB_GetFunctions, damit Kachel und Sankey virtuelle Zähler wie echte
    // übernehmen können. Seit 0.24.0 höchstens EINE Zuordnung je Instanz
    // („Funktion" ist jetzt ein Instanz-Property, kein Zeilen-Feld mehr).
    // -----------------------------------------------------------------------

    /**
     * Mitglieder (Terme) dieser Formel nach außen — Dashboards Anfrage
     * 03.09.2026, von Dietmar so entschieden: die HIERARCHIE verketteter
     * virtueller Zähler ("Geschoss OG" → "Licht OG" → Einzelgeräte) kommt
     * vom Anbieter, nicht aus einer Dashboard-eigenen Gruppierung. Nur die
     * EIGENE Ebene: ob ein Term selbst wieder eine MeterHubVirtual-Instanz
     * ist (→ nächste Ebene), löst der Konsument über dessen GetFunctions()
     * auf. Bewusst ALLE Terme inkl. negativer Anteile — "Hausverbrauch
     * ohne Wallboxen" BESTEHT aus Hausanschluss (+100) und Wallboxen
     * (−100); nur die positiven zu liefern, ließe den Knoten nach außen
     * so aussehen, als enthielte er nur den Hausanschluss. Der Konsument
     * unterscheidet am Vorzeichen von `factor` ("enthält" vs. "abgezogen").
     */
    private function MemberList(): array
    {
        $out = [];
        foreach ($this->Nodes() as $n) {
            // Abgewählte bzw. als Doppel ausgesetzte Anbindungen zählen nicht
            // mit — auch nicht als Mitglied für Konsumenten (0.28.0).
            if (!empty($n['excluded'])) {
                continue;
            }
            $out[] = [
                'name'           => $n['name'],
                'factor'         => $n['factor'],
                'powerID'        => $n['power'],
                'energyImportID' => $n['imp'],
                'energyExportID' => $n['exp'],
                // 1.4: Schaltvariable des Mitglieds (Bool mit Aktion), 0 =
                // nicht schaltbar. Bewusst auch bei abgezogenen Termen
                // geliefert — der Konsument darf ein einzelnes Mitglied
                // direkt schalten; nur die GRUPPE lässt negative aus.
                'switchID'       => $n['switch'],
            ];
        }
        return $out;
    }

    public function GetFunctions(): string
    {
        // Während des Neuladens: leer statt Warnungen (9c), 'ready' rein additiv.
        if (!$this->InstanceReady()) {
            return (string)json_encode(['instanceID' => $this->InstanceID, 'ready' => false, 'assignments' => []]);
        }
        $func = $this->ReadPropertyString('Function');
        $pollInterval = max(2, $this->ReadPropertyInteger('Interval'));
        $members = $this->MemberList();
        // Gruppenschalter (1.4): nur vorhanden, wenn mindestens ein positives
        // Mitglied schaltbar ist — sonst 0 (RegisterVariables() legt die
        // Variablen dann gar nicht erst an).
        $groupSwitch = (int)@IPS_GetObjectIDByIdent(self::IDENT_GROUP_SWITCH, $this->InstanceID);
        $groupState  = (int)@IPS_GetObjectIDByIdent(self::IDENT_GROUP_STATE, $this->InstanceID);
        $list = [];
        if ($func !== '' && $func !== 'none' && isset(self::FUNCTIONS[$func])) {
            $id = function (string $ident) {
                return (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            };
            $list[] = [
                'slot'           => 'main',
                'function'       => $func,
                'label'          => IPS_GetName($this->InstanceID),
                'powerID'        => $id('power'),
                'energyImportID' => $id('energy_import'),
                'energyExportID' => $id('energy_export'),
                'measured'       => true, // Rechenergebnis gemessener Zähler
                'energyKind'     => 'counter',
                // Güte = Zahl der MESSENDEN Mitglieder (Dashboard-Frage
                // 11.09.2026). Im Baum-Modus kann members[] mehr enthalten:
                // reine Schalt-Mitglieder ohne Zähler, tote Links.
                'sourceCount'    => count(array_filter($members, fn($m) => $m['powerID'] > 0 || $m['energyImportID'] > 0 || $m['energyExportID'] > 0)),
                'members'        => $members,
                'switchID'       => $groupSwitch,
                'switchStateID'  => $groupState,
                'latency'        => 'realtime',
                'authority'      => 'auxiliary',
                'pollInterval'   => $pollInterval,
            ];
        }
        return json_encode([
            // 1.1 = latency/authority/pollInterval/energyKind/sourceCount,
            // 1.2 = archiveWatermarkTs (bei 'realtime' bewusst null),
            // 1.3 = members (03.09.2026, additiv, siehe MemberList()),
            // 1.4 = switchID je Mitglied + switchID/switchStateID der Gruppe
            //       (03.09.2026, Schaltgruppe — siehe Abschnitt "Schaltgruppe").
            'contractVersion' => '1.4',
            'instanceID'  => $this->InstanceID,
            'meter'       => 'virtual',
            'measureMode' => 'combined',
            'latency'     => 'realtime',
            'authority'   => 'auxiliary',
            'pollInterval'=> $pollInterval,
            'archiveWatermarkTs' => null,
            // Auch auf Instanzebene, unabhängig von "Funktion": ein reiner
            // Zwischenknoten einer Verkettung (z. B. "Licht OG") braucht
            // keine Dashboard-Funktion — ohne dieses Feld würde die
            // Rekursion des Konsumenten dort abbrechen (assignments ist
            // ohne Funktion leer, siehe Sepps "Zähler Technik"-Fund).
            'members'     => $members,
            // Gruppenschalter ebenfalls auf Instanzebene: eine Untergruppe
            // ("Licht OG" unter "Geschoss OG") ist für die Elterngruppe ein
            // ganz normales schaltbares Mitglied — ihr switchID ist genau
            // diese Variable, die Rekursion des Konsumenten trägt das durch.
            'switchID'      => $groupSwitch,
            'switchStateID' => $groupState,
            'assignments' => $list,
        ]);
    }

    // -----------------------------------------------------------------------
    // Konfigurationsformular
    // -----------------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $rawRows   = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        $rawRows   = is_array($rawRows) ? $rawRows : [];
        $migration = $this->NeedsMigration($rawRows);

        $nodes  = $migration ? [] : $this->Nodes();
        $errors = $migration ? [] : $this->Validate();
        $tree   = !$migration && $this->IsTreeMode();

        $funcOptions = [];
        foreach (self::FUNCTIONS as $key => $def) {
            $funcOptions[] = ['caption' => $def[0], 'value' => $key];
        }

        // Vorschlagsliste für "Standort": alle Werte, die irgendeine
        // MeterHub- ODER MeterHubVirtual-Instanz (auch diese selbst) schon
        // eingetragen hat — geteilter Vorschlagspool über beide Modultypen
        // (Dietmars Auftrag 01.09.2026, Standort jetzt auch bei echten
        // Zählern), wächst mit der eigenen Nutzung statt eine erfundene
        // Raumliste vorzugeben, die an keiner echten Anlage passt.
        $locationOptions = [['caption' => '— Vorschlag wählen —', 'value' => '']];
        $seenLocations = [];
        foreach (array_merge(IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL), IPS_GetInstanceListByModuleID(self::GUID_METER)) as $iid) {
            $loc = trim((string)@IPS_GetProperty($iid, 'Location'));
            if ($loc !== '' && !isset($seenLocations[$loc])) {
                $seenLocations[$loc] = true;
            }
        }
        $sortedLocations = array_keys($seenLocations);
        sort($sortedLocations, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($sortedLocations as $loc) {
            $locationOptions[] = ['caption' => $loc, 'value' => $loc];
        }

        $check = [];
        // Hinweise des letzten Formular-Abgleichs (z. B. nicht übernommene
        // Zeilen) — zehn Minuten sichtbar, danach veraltet.
        $rn = json_decode((string)$this->ReadAttributeString('ReconcileNotes'), true);
        if (is_array($rn) && time() - (int)($rn['ts'] ?? 0) < 600) {
            foreach ((array)($rn['notes'] ?? []) as $note) {
                $check[] = ['type' => 'Label', 'caption' => '⚠️ Beim letzten „Übernehmen“: ' . $note];
            }
        }
        if ($migration) {
            $check[] = ['type' => 'Label', 'caption' => 'Migration ausstehend — siehe Panel oben.'];
        } elseif (count($errors) > 0) {
            $check[] = ['type' => 'Label', 'caption' => '❌ ' . count($errors) . ' Problem(e) — solange sie bestehen, wird nicht gerechnet:'];
            foreach ($errors as $e) {
                $check[] = ['type' => 'Label', 'caption' => '   • ' . $e];
            }
        } elseif (count($nodes) === 0) {
            $check[] = ['type' => 'Label', 'caption' => $tree
                ? 'Noch keine Mitglieder — oben ein Gerät verknüpfen oder im Objektbaum eine Verknüpfung unter diese Instanz legen.'
                : 'Noch keine Zähler eingetragen.'];
        } else {
            $check[] = ['type' => 'Label', 'caption' => '✅ Formel schlüssig:'];
            foreach ($this->FormulaPreview($nodes) as $line) {
                $check[] = ['type' => 'Label', 'caption' => $line];
            }
            foreach ($this->ContinuityNotes() as $line) {
                $check[] = ['type' => 'Label', 'caption' => 'ℹ️ ' . $line];
            }
            foreach ($this->Warnings($nodes) as $w) {
                $check[] = ['type' => 'Label', 'caption' => '⚠️ ' . $w];
            }
        }

        $migrationPanel = null;
        $listDef = [
            'type' => 'List', 'name' => 'Nodes', 'caption' => 'Zähler und ihr Anteil',
            'rowCount' => $this->RowCountFor($migration ? count($rawRows) : count($nodes)),
            'add' => true, 'delete' => true,
            // Drag & Drop zum Umsortieren (Dietmars Anregung 31.08.2026) —
            // rein organisatorisch, das Rechenergebnis ist ordnungsunabhängig
            // (Summe aller Anteile). Symcon erlaubt changeOrder nicht
            // zusammen mit Spalten-Sortierung — wird hier nicht vermisst,
            // die Liste sortiert bisher ohnehin nirgends nach Spalte.
            'changeOrder' => true,
            'columns' => [
                ['caption' => 'Bezeichnung', 'name' => 'Name', 'width' => '240px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                // Vorzeichen zu einem Prozent-Anteil verallgemeinert (Dietmars
                // Anregung 31.08.2026: ein Zähler soll sich aufteilen lassen,
                // z. B. PV-Einspeisevergütung nach Quotierung anteilig auf
                // mehrere Mieter/virtuelle Zähler). 100/−100 verhalten sich
                // wie das bisherige +/−, jeder Wert dazwischen ist ein
                // echter Anteil. Ältere Zeilen mit "Sign" statt "Factor"
                // werden weiterhin gelesen, siehe Nodes().
                // digits=2 (Dietmars Auftrag 01.09.2026): krumme Aufteilungen
                // wie 33,33 % (Drittel) müssen sich exakt eintragen lassen,
                // nicht nur ganze Prozentpunkte.
                ['caption' => 'Anteil (%)', 'name' => 'Factor', 'width' => '130px', 'add' => 100, 'edit' => ['type' => 'NumberSpinner', 'minimum' => -1000, 'maximum' => 1000, 'digits' => 2, 'suffix' => ' %']],
                // Feste statt "auto" Breite (Dietmars Fund 31.08.2026: eine
                // SelectVariable-Spalte zeigt den vollen Objektpfad, "auto"
                // ließ die Zeile dadurch beliebig breit werden — Papierkorb-
                // und Zahnrad-Symbol am Zeilenende rutschten aus dem
                // sichtbaren Bereich, ohne dass sich dorthin scrollen ließ.
                // Feste Breite kappt die Spalte stattdessen (Text wird vom
                // Browser abgeschnitten, per Klick weiterhin änderbar).
                ['caption' => 'Leistung (W)', 'name' => 'PowerID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                ['caption' => 'Bezug (kWh)', 'name' => 'EnergyImportID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                ['caption' => 'Einspeisung (kWh)', 'name' => 'EnergyExportID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                // Schaltgruppe (Dietmars Entscheidung 03.09.2026, über
                // Dashboard adressiert): viele Mitglieder sind Aktoren, die
                // schalten UND messen — die Bool-Variable mit Aktion hier
                // eintragen (automatisch vorgeschlagen beim Übernehmen eines
                // Geräts bzw. per Knopf „Schalter suchen", von Hand
                // korrigierbar). Nur positive Anteile werden mitgeschaltet.
                ['caption' => 'Schalter (Bool)', 'name' => 'SwitchID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
            ],
        ];

        // Baum-Modus: statt der Formel-Tabelle eine Mitglieder-Tabelle ohne
        // Hinzufügen/Löschen — Mitglieder entstehen und verschwinden im
        // Objektbaum. loadValuesFromConfiguration=false: laut SDK-Doku lädt
        // eine an eine Property gebundene Liste sonst ZUERST die gespeicherten
        // Zeilen; nach einer Änderung im Objektbaum stünden dann veraltete
        // Zeilen neben den neuen. Gespeichert werden nur die editierbaren
        // Spalten plus MemberID (save=true) — die Anzeige-Spalten nicht.
        $treeOffer = null;
        if ($tree) {
            $treeRows = $this->TreeFormRows();
            $roleOptions = [
                ['caption' => 'automatisch', 'value' => ''],
                ['caption' => 'Leistung (W)', 'value' => 'power'],
                ['caption' => 'Bezug (kWh)', 'value' => 'imp'],
                ['caption' => 'Einspeisung (kWh)', 'value' => 'exp'],
            ];
            // Beim Öffnen gezeigte Mitglieder merken — nur diese darf ein
            // gelöschte Zeile beim „Übernehmen" entfernen (ein inzwischen im
            // Objektbaum hinzugekommener Link fehlt in der Tabelle, darf aber
            // nicht deshalb gelöscht werden).
            $this->WriteAttributeString('FormSnapshot', (string)json_encode(array_column($treeRows, 'MemberID')));
            $listDef = [
                'type' => 'List', 'name' => 'MemberSettings',
                'caption' => 'Mitglieder — hinzufügen, löschen, umsortieren und umbenennen wirkt mit „Übernehmen“ auf die Links im Objektbaum',
                'rowCount' => $this->RowCountFor(count($treeRows)),
                'add' => true, 'delete' => true, 'changeOrder' => true,
                'loadValuesFromConfiguration' => false,
                'values' => $treeRows,
                'columns' => [
                    ['caption' => 'Mitglied', 'name' => 'Name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Ziel', 'name' => 'Target', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectObject']],
                    ['caption' => 'Erkannt', 'name' => 'Found', 'width' => '360px', 'add' => '🆕 wird mit „Übernehmen“ verknüpft'],
                    ['caption' => 'Anteil (%)', 'name' => 'Factor', 'width' => '130px', 'add' => 100, 'edit' => ['type' => 'NumberSpinner', 'minimum' => -1000, 'maximum' => 1000, 'digits' => 2, 'suffix' => ' %']],
                    ['caption' => 'Rolle (bei Link auf Variable)', 'name' => 'Role', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $roleOptions]],
                    ['caption' => 'Leistung übersteuern', 'name' => 'PowerID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Bezug übersteuern', 'name' => 'EnergyImportID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Einspeisung übersteuern', 'name' => 'EnergyExportID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Schalter übersteuern', 'name' => 'SwitchID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'nicht schalten', 'name' => 'NoSwitch', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'aktiv', 'name' => 'Active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'Objekt-ID', 'name' => 'MemberID', 'width' => '90px', 'add' => 0, 'save' => true],
                    // Unsichtbare Ausgangswerte für den Abgleich, siehe TreeFormRows().
                    ['caption' => 'OrigName', 'name' => 'OrigName', 'width' => '0px', 'add' => '', 'save' => true, 'visible' => false],
                    ['caption' => 'OrigTarget', 'name' => 'OrigTarget', 'width' => '0px', 'add' => 0, 'save' => true, 'visible' => false],
                    ['caption' => 'Form', 'name' => 'Form', 'width' => '0px', 'add' => true, 'save' => true, 'visible' => false],
                ],
            ];
        } elseif (!$migration && count($nodes) > 0) {
            $treeOffer = [
                'type' => 'ExpansionPanel', 'name' => 'TreeOfferPanel', 'expanded' => true,
                'caption' => '🌳  Neu: Mitglieder direkt im Objektbaum',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Virtuelle Zähler lassen sich jetzt allein über den Objektbaum zusammenstellen: alles, was als Verknüpfung (Link) oder direkt unter dieser Instanz hängt, ist Mitglied — in der Reihenfolge seiner Position dort. Ein gelöschtes Gerät fällt sofort als „Ziel fehlt" auf, statt still als Leiche in der Tabelle zu bleiben, und virtuelle Zähler lassen sich ineinander verschachteln (Link auf eine andere Instanz).'],
                    ['type' => 'Label', 'caption' => 'Die Umstellung legt für jede Zeile unten einen Link unter dieser Instanz an — aufs Gerät, wenn es genau diese Datenpunkte liefert, sonst auf die einzelne Variable mit festgehaltener Zuordnung. Anteile und Schalter bleiben erhalten, das Ergebnis ist rechnerisch identisch. Die bisherige Tabelle wird gesichert. Ohne Klick hier ändert sich nichts.'],
                    ['type' => 'Button', 'caption' => '🌳  In Objektbaum-Mitglieder umwandeln', 'onClick' => 'echo MHUBV_ConvertToTree($id);', 'confirm' => 'Für alle ' . count($nodes) . ' Zeilen Links unter dieser Instanz anlegen und auf den Objektbaum umstellen? Ungespeicherte Änderungen in der Tabelle gehen dabei verloren.'],
                ],
            ];
        }

        if ($migration) {
            $migratedRows = $this->MigratedRows($rawRows);
            $listDef['value'] = json_encode($migratedRows);
            $migrationPanel = [
                'type' => 'ExpansionPanel', 'name' => 'MigrationPanel', 'expanded' => true,
                'caption' => '🔄  Migration nötig',
                'items' => [
                    ['type' => 'Label', 'caption' => 'MeterHubVirtual rechnet jetzt mit einer flachen Formel statt mit einem Baum aus „Kürzel“ und „hängt hinter“ — diese Instanz selbst ist die oberste Ebene, jede Zeile unten ein Term mit Vorzeichen.'],
                    ['type' => 'Label', 'caption' => 'Alle ' . count($migratedRows) . ' bisherigen Zeilen stehen unten als Vorschlag, vorerst alle mit Vorzeichen „+“. WICHTIG: nur behalten, was wirklich zu DIESER Instanz gehören soll (Papierkorb-Symbol bei allen anderen — bei einer gewachsenen Installation ist das oft die Mehrzahl, etwa Zeilen aus einem früheren Suchlauf, die nie tatsächlich verdrahtet wurden). Vorzeichen wo nötig auf „−“ stellen, dann „Übernehmen“.'],
                    ['type' => 'Label', 'caption' => 'Bis „Übernehmen“ geklickt wird, bleiben vorhandene Ausgabevariablen unverändert — es wird nichts automatisch neu berechnet.'],
                ],
            ];
        }

        $purposeIntro = $migration ? null : $this->PurposeIntro();
        $newsBanner = $migration ? null : $this->NewsBanner();

        $meterItems = [];
        if (!$migration) {
            // Orientierung VOR den eigentlichen Bedienelementen (Sepps
            // Rückmeldung 01.09.2026, per Dietmar weitergegeben: "nicht so
            // klar was erreicht man wie", die vier Wege standen bisher nur
            // flach hintereinander, ohne erkennbar zu machen, dass es
            // ALTERNATIVEN sind, keine Abfolge von Schritten). Dieselbe
            // "vier Wege"-Übersicht stand vorher nur versteckt im
            // eingeklappten Doku-Panel weiter unten — jetzt zusätzlich hier,
            // direkt am Ort der Bedienelemente, mit denselben Namen wie die
            // Zwischenüberschriften darunter (Dietmars Vorgabe: jede
            // Alternative auch textlich/im Schriftstil herausstellen, z. B.
            // "Die Quick-Pick-Alternative").
            if ($tree) {
                $meterItems[] = ['type' => 'Label', 'caption' => '🌳 Mitglieder dieser Instanz ist alles, was im Objektbaum direkt unter ihr hängt — am einfachsten eine Verknüpfung (Link) auf ein Zählergerät, eine einzelne Variable oder eine andere virtuelle Zähler-Instanz. Reihenfolge = Position im Objektbaum. Entfernen = Link löschen. Die drei Alternativen unten legen solche Links für dich an.'];
            }
            $meterItems[] = ['type' => 'Label', 'caption' => $tree
                ? 'Vier gleichwertige Alternativen, ein Mitglied hinzuzufügen — wähl die, die zu deiner Situation passt:'
                : 'Vier gleichwertige Alternativen, eine Zeile hinzuzufügen — wähl die, die zu deiner Situation passt (kein Ablauf, den man der Reihe nach abarbeitet):'];
            $meterItems[] = ['type' => 'Label', 'caption' => '1. Die Sucher-Alternative — das System systematisch nach Kandidaten durchsuchen, dann aus der Fundliste übernehmen.'];
            $meterItems[] = ['type' => 'Label', 'caption' => '2. Die Quick-Pick-Alternative — Zähler-Instanz/Gerät schon bekannt? Direkt wählen, keine Suche nötig.'];
            $meterItems[] = ['type' => 'Label', 'caption' => '3. Die Familien-Alternative — mehrere Geräte auf einmal aus einer Kategorie übernehmen (z. B. viele gleichartige KNX-Aktoren).'];
            $meterItems[] = ['type' => 'Label', 'caption' => $tree
                ? '4. Die Objektbaum-Alternative — selbst eine Verknüpfung anlegen und unter diese Instanz legen (oder eine vorhandene hierher verschieben).'
                : '4. Die Handarbeit-Alternative — von Hand in der Tabelle unten, Feld für Feld mit dem eingebauten Symcon-Variablenpicker.'];

            $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 1. Die Sucher-Alternative ━━━'];
            $meterItems[] = ['type' => 'Label', 'caption' => '🔎 Findet alle Datenpunkte mit W-/kW- bzw. kWh-Profil, gruppiert sie nach Gerät. Trägt nichts automatisch in die Tabelle ein — die Funde stehen danach direkt unter „Fund auswählen" zum Übernehmen bereit. Variablen aus bekannten NRG-Stack-Modulen (EMS, InverterHub, ChargerHub, Prognose, Tibber Grid Rewards …) werden übersprungen — sie sind dort schon korrekt eingebunden.'];
            $meterItems[] = ['type' => 'SelectObject', 'name' => 'ScanRoot', 'caption' => 'Nur in diesem Bereich suchen (leer = ganze Installation)'];
            $meterItems[] = ['type' => 'ValidationTextBox', 'name' => 'ScanFilter', 'caption' => 'Nur Geräte, deren Name das hier enthält (leer = alle)'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanNeedEnergy', 'caption' => 'Nur Geräte mit Energiezähler (kWh) — blendet Schalter aus, die bloß die Momentanleistung melden'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanOnlyActive', 'caption' => 'Nur Geräte, die in den letzten 7 Tagen Werte geliefert haben — blendet Karteileichen aus'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanOnlyUsedElsewhere', 'caption' => 'Nur Datenpunkte zeigen, die schon in einer ANDEREN virtuellen Zähler-Instanz stecken (zum gezielten Prüfen auf Doppelverwendung/Aufteilung) — sonst werden sie wie gewohnt ausgeblendet'];
            // Filter nach bereits vergebener Funktion (Dietmars Anregung
            // 02.09.2026): eine Sparte wie "Beleuchtung" sammeln, auch wenn
            // die einzelnen Stromkreise ganz unterschiedlich heißen (Flur,
            // Wohnzimmer, Außenbeleuchtung …) — Name allein (Feld oben)
            // trifft das nicht zuverlässig, die schon an der jeweiligen
            // Ursprungsinstanz gesetzte Funktions-Zuordnung schon.
            $scanFuncOptions = [['caption' => '— egal (keine Einschränkung) —', 'value' => '']];
            foreach (self::FUNCTIONS as $key => $def) {
                $scanFuncOptions[] = ['caption' => $def[0], 'value' => $key];
            }
            $meterItems[] = ['type' => 'Select', 'name' => 'ScanOnlyFunction', 'caption' => 'Nur Datenpunkte, deren Ursprungsinstanz bereits diese Funktion trägt (z. B. „Beleuchtung" — praktisch, um eine Sparte über mehrere Stromkreise hinweg zu einem Sammelzähler zusammenzufassen)', 'options' => $scanFuncOptions];
            $meterItems[] = ['type' => 'Button', 'caption' => '🔎  Zähler im System suchen', 'onClick' => 'MHUBV_ScanMetersEx($id, $ScanRoot, $ScanFilter, $ScanNeedEnergy, $ScanOnlyActive, $ScanOnlyUsedElsewhere, $ScanOnlyFunction);'];
            $meterItems[] = ['type' => 'Label', 'name' => 'ScanResult', 'caption' => '', 'visible' => false];
            // Funde direkt übernehmbar (Dietmars Anregung 31.08.2026: "wenn
            // ich schon etwas suchen muss, dann möchte ich auch direkt aus
            // dem Suchdialog etwas übernehmen") — ScanMeters() füllt die
            // Optionen dieses Felds bei jedem Suchlauf neu (siehe dort).
            $meterItems[] = ['type' => 'Select', 'name' => 'ScanPick', 'caption' => 'Fund auswählen', 'options' => [['caption' => '— erst oben suchen —', 'value' => 0]], 'value' => 0];
            $meterItems[] = ['type' => 'Button', 'caption' => '✅  Fund übernehmen', 'onClick' => 'echo MHUBV_AddDevice($id, $ScanPick);'];

            // Schneller Weg OHNE Suche (Dietmars Anregung 31.08.2026: "ich
            // möchte die Zählerinstanz auswählen müssen und der Rest muss
            // von alleine kommen" — drei einzelne Variablen-Picker pro
            // Zeile "klickt sich zu Tode"): EIN Gerät direkt wählen,
            // Leistung/Bezug/Einspeisung werden automatisch gesucht (siehe
            // MetersOfDevice()) und als neue Zeile eingetragen — inklusive
            // Ergebnistext, damit sofort sichtbar ist, was gefunden wurde
            // ("genau dieses Ergebnis zu Gesicht bekommen").
            $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 2. Die Quick-Pick-Alternative ━━━'];
            $meterItems[] = ['type' => 'Label', 'caption' => '⚡ Ohne vorherige Suche: Zähler-Instanz/Gerät direkt wählen.'];
            $meterItems[] = ['type' => 'SelectObject', 'name' => 'DevicePick', 'caption' => 'Zähler-Instanz oder Gerät'];
            $meterItems[] = ['type' => 'Button', 'caption' => $tree ? '🔗  Als Mitglied verknüpfen' : '✅  Als neue Zeile übernehmen', 'onClick' => 'echo MHUBV_AddDevice($id, $DevicePick);'];

            // Geräte-Familie ohne gemeinsamen Container (Dietmars Auftrag
            // 01.09.2026, ausgelöst durch Sepps Diagnose seiner MDT-AZI-
            // Aktoren): jeder Messwert eine eigene KNX-Instanz, nur der
            // Namens-Anfang verbindet sie — der Geräte-Picker oben braucht
            // aber einen Container. Siehe FindDeviceFamilies()/
            // AddDeviceFamily() für die volle Herleitung.
            $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 3. Die Familien-Alternative ━━━'];
            $meterItems[] = ['type' => 'Label', 'caption' => '🏷️ Mehrere Zähler auf einmal erkennen — zwei Muster: Geräte ohne gemeinsamen Container, jeder Messwert eine eigene KNX-Instanz (z. B. MDT AZI, „…Wirkleistung"/„…Hauptzähler kWh"); oder ein KNX-Zähler mit getrennten Bezugs-/Einspeisungswerten (z. B. Lingg&Janke, „…Wirkleistung P14/P23 (W)"/„…Wirkenergie A14/A23 (kWh)" — ergibt automatisch die richtige Verdrahtung mit Vorzeichen). Kategorie wählen, die die passenden Instanzen enthält.'];
            $meterItems[] = ['type' => 'SelectObject', 'name' => 'FamilyRoot', 'caption' => 'Kategorie mit der Geräte-Familie'];
            $meterItems[] = ['type' => 'Button', 'caption' => '🏷️  Geräte-Familie erkennen und übernehmen', 'onClick' => 'echo MHUBV_AddDeviceFamily($id, $FamilyRoot);'];

            if ($tree) {
                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 4. Direkt in der Tabelle oder im Objektbaum ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '✏️ In der Tabelle unten: „Hinzufügen" und in „Ziel" ein Gerät, eine Variable oder einen anderen virtuellen Zähler wählen; Zeilen löschen, per Drag & Drop umsortieren, „Mitglied" umbenennen oder das „Ziel" ändern — mit „Übernehmen" legt das Modul die Links im Objektbaum entsprechend an, löscht, sortiert und benennt sie. Gelöscht werden dabei nur Links, nie ein Gerät.'];
                $meterItems[] = ['type' => 'Label', 'caption' => '🔗 Oder im Objektbaum selbst eine Verknüpfung unter diese Instanz legen — wird beim nächsten Berechnungstakt automatisch Mitglied, ohne „Übernehmen". Bei einem Link auf eine einzelne Variable ohne erkennbare Einheit in der Tabelle die „Rolle" setzen.'];
                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ Danach, für jedes Mitglied ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '📐 „Anteil (%)" in der Tabelle unten: 100 = voll addieren, −100 = voll abziehen, jeder Wert dazwischen ein Teil-Anteil. Leistung/Bezug/Einspeisung werden am Ziel automatisch gefunden; nur wenn das nicht passt, eine Variable in der jeweiligen „übersteuern"-Spalte wählen (leer = automatisch).'];
                $meterItems[] = ['type' => 'Label', 'caption' => '↕️ Reihenfolge und Namen: in der Tabelle (wirkt mit „Übernehmen") oder im Objektbaum (Position bzw. Name des Links) — beides bleibt synchron. Was du im Objektbaum änderst, während das Formular offen ist, überschreibt das Formular nicht; es ändert nur, was du in der Tabelle selbst geändert hast.'];
            } else {
                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 4. Die Handarbeit-Alternative ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '➕ Unter der Tabelle auf „Hinzufügen" klicken, dann in „Leistung"/„Bezug"/„Einspeisung" mit dem eingebauten Symcon-Variablenpicker die passende Variable wählen — für Einzelfälle, die die automatischen Alternativen nicht (richtig) finden.'];

                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ Danach, für jede Zeile ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '📐 „Anteil (%)" setzen: 100 = voll addieren, −100 = voll abziehen, jeder Wert dazwischen ein Teil-Anteil. Beispiel: eine Einspeisung wird per Quotierung zur Hälfte zwei Mietern zugerechnet → in der Instanz für Mieter A 50, in der für Mieter B ebenfalls 50 (oder −50, je nachdem ob addiert oder abgezogen werden soll) bei DERSELBEN Variable.'];
                $meterItems[] = ['type' => 'Label', 'caption' => '↕️ Zeilen lassen sich per Drag & Drop umsortieren — rein zur eigenen Übersicht, das Ergebnis ist unabhängig von der Reihenfolge.'];
            }
            // Fehlende Bezugswerte hochrechnen (Dietmars Auftrag 01.09.2026,
            // ausgelöst durch AZI-Geräte mit reiner Wirkleistung ohne
            // Hauptzähler kWh, z. B. "AZI Backofen"). Bewusst "hochgerechnet",
            // nicht "geschätzt" — Dietmars Grundsatzeinwand: Leistung ×
            // Berechnungs-Intervall ist eine echte Rechnung aus real
            // gemessenen Werten, keine Vermutung. Siehe AddCalculatedEnergy().
            // Nur außerhalb der Migration sinnvoll, die Nodes-Liste zeigt
            // dort erst einen unbestätigten Alt-Vorschlag.
            $meterItems[] = ['type' => 'Label', 'caption' => '🧮 Zeilen mit Leistung, aber ohne Bezug: aus Leistung × Berechnungs-Intervall hochrechnen (keine Schätzung, aber ungenauer als ein echter Zähler — genauer bei kurzem Intervall, bei sprunghaften Verbrauchern wie einer Waschmaschine ungenauer).'];
            $meterItems[] = ['type' => 'Button', 'caption' => '🧮  Fehlende Energiewerte aus der Leistung hochrechnen', 'onClick' => $tree ? 'echo MHUBV_AddCalculatedEnergy($id, $MemberSettings);' : 'echo MHUBV_AddCalculatedEnergy($id, $Nodes);'];
            // Schaltgruppe (Dietmars Entscheidung 03.09.2026): Schalter für
            // bereits vorhandene Zeilen nachtragen — beim Übernehmen eines
            // Geräts wird er schon automatisch vorgeschlagen, ältere Zeilen
            // holen ihn hiermit nach. Nur die offene Maske, „Übernehmen"
            // bleibt der bewusste letzte Schritt.
            $meterItems[] = ['type' => 'Label', 'caption' => '🔀 Schaltgruppe: Zeilen, deren Gerät schaltet UND misst (z. B. Z-Wave-Aktor je Leuchte), bekommen in der Spalte „Schalter" ihre Bool-Variable — dann entstehen an dieser Instanz „Gruppe schalten" (An/Aus für alle) und „Gruppenstatus" (aus / teilweise / an). Nur Zeilen mit positivem Anteil werden mitgeschaltet; abgezogene Zeilen bewusst nicht.'];
            $meterItems[] = ['type' => 'Button', 'caption' => '🔀  Schalter für vorhandene Zeilen suchen', 'onClick' => $tree ? 'echo MHUBV_FindSwitches($id, $MemberSettings);' : 'echo MHUBV_FindSwitches($id, $Nodes);'];
            $meterItems[] = ['type' => 'Label', 'caption' => '✅ Zuletzt „Übernehmen" klicken (Formular-Ende).'];
        }
        $meterItems[] = $listDef;

        $form = [
            'elements' => array_values(array_filter([
                $migrationPanel,
                $purposeIntro,
                $newsBanner,
                $treeOffer,
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Dokumentation & Hilfe', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'MeterHubVirtual ' . self::NEWS_VERSION . ' — Stand dieser Anleitung.'],
                        ['type' => 'Label', 'caption' => 'Bildet einen virtuellen Zähler aus einer FORMEL: Diese Instanz ist die oberste Ebene, jede Zeile unten ein Term mit einem Anteil in Prozent. Ergebnis = Summe aller Anteile (100 % = ganz addiert, −100 % = ganz abgezogen, jeder Wert dazwischen ein Teil-Anteil), getrennt für Leistung, Bezug und Einspeisung.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Sammeln“: Kühlschrank (100 %) und Brunnenpumpe (100 %) ergeben deren Summe — nützlich, wenn es keinen echten Zähler gibt, der beide zusammen misst.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Sammeln“ als Kostenblock: alle Stromkreise einer Sparte wie „Beleuchtung“ (100 %) in einer eigenen Instanz zusammenfassen, auch wenn die einzelnen Kreise ganz unterschiedlich heißen (Flur, Wohnzimmer, Außenbeleuchtung …). Das Ergebnis ist der Energiewert (kWh) dieser Sparte — den passenden Euro-Betrag liefert erst ein Tarif (z. B. Tibber Grid Rewards oder EMS), MeterHubVirtual selbst rechnet keine Preise. Zum Finden aller Stromkreise: unten bei „Zähler suchen“ das Feld „Nur Datenpunkte, deren Ursprungsinstanz bereits diese Funktion trägt“ auf „Beleuchtung“ stellen — trifft auch dann, wenn der Name allein nicht eindeutig ist.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Abziehen“: Hausanschluss (100 %, eigener Zähler), Wärmepumpe (−100 %) und Wallbox (−100 %) ergeben Hausanschluss minus Wärmepumpe minus Wallbox — der unbekannte Rest des Hauses.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Durchreichen“: nur EINE Zeile (100 %) — die Instanz gibt einfach diesen einen Zähler weiter, nützlich um ihm über „Funktion“ eine Dashboard-Zuordnung zu geben, ohne ihn mit etwas anderem zu verrechnen.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Aufteilen“: eine PV-Anlage mit mehreren Erzeugungsjahren bekommt die Einspeisevergütung anteilig nach Quotierung — dieselbe Einspeisungs-Variable wird in der Instanz für den einen Anteil mit z. B. 60 % eingetragen, in einer zweiten Instanz für den anderen Anteil mit 40 %. Dasselbe funktioniert für eine anteilige Zuordnung an mehrere Mieter.'],
                        ['type' => 'Image', 'image' => self::KONZEPT_DIAGRAMM, 'center' => true],
                        ['type' => 'Label', 'caption' => 'Mehrstufige Verschachtelung (z. B. ein Zwischenwert aus mehreren Zählern, von dem dann wieder etwas abgezogen wird) geht über mehrere Instanzen: eine Instanz rechnet den Zwischenwert, dessen Ausgabe wird als ganz normale Zeile in der nächsten Instanz verdrahtet — nicht mehr innerhalb einer einzigen Instanz.'],
                        ['type' => 'Label', 'caption' => '🆕 Objektbaum als Mitglieder-Quelle (Standard für neue Instanzen): alles, was als Verknüpfung (Link) oder direkt unter dieser Instanz hängt, ist Mitglied, in der Reihenfolge seiner Position dort. Verschachteln = einen Link auf eine andere virtuelle Zähler-Instanz unter diese legen (z. B. „Wallbox Garage" und „Wallbox Carport" unter „Fahrzeugbeladung"). Ein Kreisverweis (A enthält B, B enthält A) wird erkannt und blockiert. Ein Link, dessen Ziel gelöscht wurde, erscheint als ⚠️ „Ziel fehlt" und geht mit 0 ein. Instanzen mit der bisherigen Tabelle rechnen unverändert weiter; umgestellt wird nur per Knopf.'],
                        ['type' => 'Label', 'caption' => '━━━ Schritt für Schritt ━━━'],
                        ['type' => 'Label', 'caption' => '1. Optional zuerst: „Zählerbezeichnung“ oben setzen — das ist zugleich der Name dieser Instanz im Objektbaum, keine zwei getrennten Namen zu pflegen.'],
                        ['type' => 'Label', 'caption' => '2. Optional zur Übersicht: „Zähler suchen" unten klicken — zeigt brauchbare Kandidaten im Ergebnistext, trägt aber nichts ein.'],
                        ['type' => 'Label', 'caption' => '3. Je Zähler eine Zeile — vier Wege: nach dem Suchlauf bei „Fund auswählen" wählen und übernehmen; ohne Suche direkt bei „Zähler-Instanz oder Gerät" wählen und übernehmen; bei mehreren Geräten ohne gemeinsamen Container (z. B. MDT AZI) eine Kategorie bei „Geräte-Familie erkennen" wählen — findet alle passenden Geräte auf einmal; oder von Hand unter der Tabelle „Hinzufügen" klicken und je Spalte mit dem eingebauten Symcon-Variablenpicker die passende Variable wählen. In den ersten drei Fällen werden Leistung/Bezug/Einspeisung automatisch gesucht.'],
                        ['type' => 'Label', 'caption' => '4. „Anteil (%)" setzen: 100 addiert voll, −100 zieht voll ab, jeder Wert dazwischen ist ein Teil-Anteil, auch mit Nachkommastellen (siehe Beispiel „Aufteilen").'],
                        ['type' => 'Label', 'caption' => '5. „Übernehmen“ klicken.'],
                        ['type' => 'Label', 'caption' => '6. Unten im Panel „Prüfung & Vorschau“ kontrollieren: ✅ zeigt die fertige Formel MIT aktuellen Werten, ❌ nennt genau, was noch fehlt, ⚠️ weist auf Zeilen hin, die Leistung aber keinen Bezug haben, obwohl andere Zeilen einen haben (blockiert nichts, kann aber die Bezug-Summe zu niedrig ausfallen lassen). Fehlt bei einem Zähler grundsätzlich der Bezugswert (z. B. reine Wirkleistungs-Aktoren wie MDT AZI): „Fehlende Energiewerte aus der Leistung hochrechnen" klicken — rechnet Leistung × Berechnungs-Intervall zu einer eigenen, klar beschrifteten Variable auf (keine Schätzung, aber ungenauer als ein echter Zähler).'],
                        ['type' => 'Label', 'caption' => '7. Optional: „Funktion“ setzen, damit das Dashboard diese Instanz als Verbraucher erkennt; „Standort“ (Raum/Geschoss) für die eigene Übersicht, unabhängig von der Funktion.'],
                        ['type' => 'Label', 'caption' => 'Ein Datenpunkt darf innerhalb DERSELBEN Instanz nur in EINER Zeile stehen — sonst würde er doppelt gezählt, die Prüfung meldet das. Über mehrere Instanzen hinweg ist dieselbe Variable dagegen ausdrücklich erlaubt (siehe „Aufteilen") — die Verantwortung, dass die Anteile insgesamt sinnvoll sind, liegt dann bei dir.'],
                        ['type' => 'Label', 'caption' => 'Einheiten: Leistung in W, Energie als kumulative kWh-Zählerstände. Alle Datenpunkte je Spalte müssen dieselbe Einheit haben; Abweichungen meldet die Prüfung.'],
                        ['type' => 'Label', 'caption' => 'Zeilen lassen sich per Drag & Drop umsortieren, rein zur eigenen Übersicht — das Ergebnis ist unabhängig von der Reihenfolge.'],
                    ],
                ],
                // Name der Instanz — Dietmars Frage 31.08.2026, ob sich der
                // nicht auch direkt im eigenen Formular setzen lassen sollte
                // (er fließt als Dashboard-Label in GetFunctions() ein).
                // "InstanceName" ist bewusst KEINE registrierte Property,
                // sondern nur mit dem aktuellen IPS-Namen vorbelegt —
                // IPS_SetName() läuft sofort per onChange, unabhängig vom
                // Client (Konsole/WebFront/App) und von "Übernehmen".
                ['type' => 'ValidationTextBox', 'name' => 'InstanceName', 'caption' => '🆕 Zählerbezeichnung', 'value' => IPS_GetName($this->InstanceID), 'onChange' => 'MHUBV_RenameInstance($id, $InstanceName);'],
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Berechnung aktiv'],
                ['type' => 'Select', 'name' => 'Function', 'caption' => 'Funktion (fürs Dashboard)', 'options' => $funcOptions],
                // Standort: reines Freitext-Label (Raum/Geschoss …), bewusst
                // getrennt vom Dashboard-Vertrag "Function". Auswahlliste aus
                // den Werten, die irgendeine Instanz schon benutzt — plus
                // jederzeit frei eintragbar für den Einzelfall, der in keine
                // Liste passt (Dietmars Auftrag: "auch die letzten
                // Absurditäten noch bezeichnen können").
                ['type' => 'Select', 'name' => 'LocationPreset', 'caption' => 'Standort (Vorschlag übernehmen …)', 'options' => $locationOptions, 'value' => '', 'onChange' => 'MHUBV_ApplyLocationPreset($id, $LocationPreset);'],
                ['type' => 'ValidationTextBox', 'name' => 'Location', 'caption' => '🆕 Standort (Raum/Geschoss, frei eintragbar)'],
                [
                    'type' => 'ExpansionPanel', 'caption' => '🔌  Zähler', 'expanded' => true,
                    'items' => $meterItems,
                ],
                ['type' => 'ExpansionPanel', 'caption' => '🔎  Prüfung & Vorschau', 'expanded' => true, 'items' => $check],
                [
                    'type' => 'ExpansionPanel', 'caption' => '⏱️  Berechnung', 'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Neu berechnen alle', 'minimum' => 2, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                [
                    // Zwei eigene Panels statt einem gemeinsamen (Dietmars
                    // Rückmeldung 31.08.2026: Symcons Formularsprache kennt
                    // kein horizontales Nebeneinander — schon bei den
                    // PopupButtons geprüft. Zwei einzeln auf-/zuklappbare
                    // Panels halten wenigstens die sichtbare Feldzahl klein.
                    'type' => 'ExpansionPanel', 'caption' => '🗄️  Archiv-Verdichtung: Leistung', 'expanded' => false,
                    'items' => array_merge(
                        [['type' => 'Label', 'caption' => 'Reduziert automatisch den Detailgrad älterer Archivwerte (spart Speicher), bei jedem „Übernehmen" neu gesetzt. Jede Stufe greift nur, wenn ihre Auflösung tatsächlich gröber ist als das Update-Intervall oben — sonst gäbe es nichts zu verdichten.']],
                        $this->CompactionFields('Power')
                    ),
                ],
                [
                    'type' => 'ExpansionPanel', 'caption' => '🗄️  Archiv-Verdichtung: Energie (Bezug/Einspeisung)', 'expanded' => false,
                    'items' => array_merge(
                        [['type' => 'Label', 'caption' => 'Unabhängig von der Leistung einstellbar — Energie-Zählerstände haben oft andere Aufbewahrungs-Anforderungen.']],
                        $this->CompactionFields('Energy')
                    ),
                ],
                $this->ForumHint(),
                $this->LicenseHint(),
            ])),
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt neu berechnen', 'onClick' => 'echo MHUBV_RecalcAndRefreshForm($id);'],
                ['type' => 'Button', 'caption' => '🔄  Übernehmen erzwingen (ohne Formularänderung)', 'onClick' => "IPS_ApplyChanges(\$id); echo '✅ ApplyChanges() ausgeführt.';", 'confirm' => 'Instanz jetzt neu anwenden (ApplyChanges)?'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Berechnung aktiv.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Berechnung deaktiviert.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Formel unvollständig oder widersprüchlich — siehe Prüfung.'],
                ['code' => 202, 'icon' => 'error',    'caption' => 'Migration nötig — Formular öffnen und im Panel oben bestätigen.'],
            ],
        ];
        return json_encode($form);
    }

    /** Formel je Feld als Klartext (Vorschau im Formular). */
    /** Zahl im Format der jeweiligen NRG-Stack-Profile (0 bzw. 1 Nachkommastelle, deutsches Komma). */
    private function FormatValue(float $v, string $field): string
    {
        return number_format($v, $field === 'power' ? 0 : 1, ',', '.');
    }

    /**
     * Formel je Feld als Klartext, MIT den aktuellen Live-Werten (Dietmars
     * Anregung 31.08.2026: „Prüfung & Vorschau" sollte nicht nur die
     * Struktur, sondern auch die tatsächlichen Werte zeigen) — dieselben
     * Zahlen, die die nächste Recalc() auch berechnen würde, nur schon beim
     * bloßen Öffnen des Formulars sichtbar, ohne extra „Jetzt neu
     * berechnen" klicken zu müssen.
     */
    private function FormulaPreview(array $nodes): array
    {
        $units = ['power' => 'W', 'imp' => 'kWh', 'exp' => 'kWh'];
        $lines = [];
        foreach ([['power', 'Leistung'], ['imp', 'Bezug'], ['exp', 'Einspeisung']] as [$f, $lbl]) {
            $terms = [];
            $sum   = 0.0;
            foreach ($nodes as $n) {
                $vid = $n[$f];
                if ($vid <= 0) {
                    continue;
                }
                $name   = $n['name'] !== '' ? $n['name'] : '(ohne Namen)';
                $factor = $n['factor'];
                $sign   = $factor < 0 ? '−' : '+';
                // Volle 100%/−100% liest sich wie bisher als reines +/−, ein
                // echter Anteil (Dietmars Anregung 31.08.2026: Zähler-
                // Aufteilung, z. B. PV-Einspeisevergütung nach Quotierung auf
                // mehrere Mieter) bekommt zusätzlich Prozentsatz und
                // Beitrag zum Ergebnis angezeigt.
                $isFull = abs(abs($factor) - 100.0) < 0.001;
                if (IPS_VariableExists($vid)) {
                    $val = (float)GetValue($vid);
                    $contribution = ($factor / 100.0) * $val;
                    $sum += $contribution;
                    $valText = $isFull
                        ? $this->FormatValue($val, $f) . ' ' . $units[$f]
                        : $this->FormatValue($val, $f) . ' ' . $units[$f] . ' → ' . $this->FormatValue($contribution, $f) . ' ' . $units[$f];
                } else {
                    $valText = 'Wert fehlt';
                }
                $pctText = $isFull ? '' : ' × ' . rtrim(rtrim(number_format(abs($factor), 2, ',', '.'), '0'), ',') . ' %';
                $terms[] = $sign . ' ' . $name . $pctText . ' (' . $valText . ')';
            }
            if ($terms) {
                $expr = implode('  ', $terms);
                // Führendes „+ “ weglassen, liest sich als Summe natürlicher.
                $expr = preg_replace('/^\+ /', '', $expr);
                $lines[] = '   ' . $lbl . ' = ' . $expr . '  =  ' . $this->FormatValue($sum, $f) . ' ' . $units[$f];
            }
        }
        if (!$lines) {
            $lines[] = '   (kein Term ergibt eine Ausgabe)';
        }
        return $lines;
    }
}
