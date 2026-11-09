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

/**
 * Texte zur Laufzeit übersetzen (Quelltexte englisch, Deutsch in locale.json — SUITE.md Punkt 9). Die Modulklasse
 * hinterlegt im Konstruktor ihre Translate()-Methode; auch Hilfsklassen und statische Methoden kommen so an die Übersetzung.
 * Mit Argumenten: sprintf() auf den übersetzten Text.
 */
class MHUBV_Text
{
    private static $fn = null;

    public static function set(\Closure $fn): void
    {
        self::$fn = $fn;
    }

    public static function t(string $text, ...$args): string
    {
        $s = self::$fn !== null ? (string)(self::$fn)($text) : $text;
        return $args ? sprintf($s, ...$args) : $s;
    }

    /** Zahl im Format der Oberflächensprache (Deutsch: 1.234,5 — Englisch: 1,234.5). */
    public static function num(float $value, int $decimals): string
    {
        return self::t('<decimal separator>') === ',' ? number_format($value, $decimals, ',', '.') : number_format($value, $decimals, '.', ',');
    }
}

class MeterHubVirtual extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        MHUBV_Text::set(function (string $text): string {
            return $this->Translate($text);
        });
    }

    // Vokabular wie im MeterHub-Hauptmodul, damit virtuelle Zähler in Kachel
    // und Sankey dieselben Funktionen belegen können. Bewusst dupliziert:
    // Konstanten lassen sich zwischen IPS-Modulen nicht teilen. Änderungen
    // hier und in MeterHub/module.php gleich halten.
    private const FUNCTIONS = [
        'none'       => ['— no assignment —',      ''],
        'grid'       => ['Grid connection',            'Electricity'],
        'house'      => ['House consumption',            'HollowHouse'],
        'pv'         => ['PV generation',             'Sun'],
        'battery'    => ['Battery',                 'Battery'],
        'heatpump'   => ['Heat pump',               'Temperature'],
        'heater'     => ['Heating / heating element',       'Temperature'],
        'hotwater'   => ['Hot water',               'Drops'],
        'aircon'     => ['Air conditioning',               'Snowflake'],
        'ventilation'=> ['Ventilation',                  'Ventilation'],
        'wallbox1'   => ['Wallbox 1',                'Car'],
        'wallbox2'   => ['Wallbox 2',                'Car'],
        'wallbox3'   => ['Wallbox 3',                'Car'],
        'wallbox4'   => ['Wallbox 4',                'Car'],
        'wallbox5'   => ['Wallbox 5',                'Car'],
        'garage'     => ['Garage',                   'Car'],
        'washer'     => ['Washing machine',            'Drops'],
        'dryer'      => ['Dryer',                 'Wind'],
        'dishwasher' => ['Dishwasher',             'Drops'],
        'oven'       => ['Backofen',                 'Flame'],
        'stove'      => ['Stove',                     'Flame'],
        'fridge'     => ['Refrigerator/freezer',       'Snowflake'],
        'kitchen'    => ['Kitchen (total)',           'Gear'],
        'appliances' => ['Household appliances (general)', 'Plug'],
        'pool'       => ['Pool',                     'Waves'],
        'sauna'      => ['Sauna',                    'Flame'],
        'light'      => ['Lighting',              'Bulb'],
        'it'         => ['Server / network',        'Gear'],
        'entertainment' => ['Unterhaltungsmedien',   'TV'],
        'workshop'   => ['Werkstatt',                'Gear'],
        'other'      => ['Other consumer',    'Electricity'],
    ];

    // Formular-Konvention des Verbunds (SUITE.md „Einheitliche Formular-
    // Optik", Referenz InverterHub). NEWS_VERSION korrespondiert mit dem
    // CHANGELOG-Eintrag, der den jeweiligen Sprung erklärt.
    private const NEWS_VERSION = '0.35.0';

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
        $this->RegisterPropertyString('FormLayout', '[]');   // Formular: Aufbau (SUITE.md 3a)
        // Merker: das EIGENE alte benutzerdefinierte Profil wird genau einmal geleert (Checkliste 28, EMS-Ausnahmeregel 09.11.2026)
        $this->RegisterAttributeBoolean('OwnCustomProfileCleared', false);
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
            // Standard AUS (Store-Review-Checkliste 9k: archivbezogene Schalter starten aus, der Nutzer
            // stimmt bewusst zu). Bestehende Instanzen behalten ihren gespeicherten Wert (an) — und
            // selbst wo ein Wert fehlen sollte, fasst ein ausgeschalteter Schalter das Archiv nicht an,
            // siehe CompactionPlan(): zurückgesetzt wird nur beim Wechsel von an auf aus.
            $this->RegisterPropertyBoolean('AutoCompaction' . $kind, false);
            $this->RegisterAttributeBoolean('CompactionApplied' . $kind, false);
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

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-nrg-stack-meterhub-energiezaehler-ein-modbus-tcp-modul-fuer-siemens-janitza-eastron-shelly-go-e-meteocontrol-bluelog-u-a-discovery-virtuelle-zaehler/144395';
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
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Feedback, especially on new/untested meter types, is explicitly welcome in the community thread.'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBV_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->PropagateDismiss('ForumHint');
    }

    /**
     * Ausblenden von "Wozu dieses Modul?"/"Was ist Neu?"/Forum-Hinweis über
     * alle Geschwister-Instanzen dieses Moduls teilen (Dietmar 14.09.2026,
     * SUITE.md "Ausblenden über mehrere Instanzen desselben Moduls teilen").
     * Ruft bei jeder Geschwister-Instanz NUR den reinen Übernahme-Schritt
     * auf (AdoptDismissState), nicht erneut die volle Ack-Methode — dadurch
     * kein Ping-Pong möglich, ganz ohne Prozessmerker: AdoptDismissState()
     * propagiert selbst nie weiter.
     */
    private function PropagateDismiss(string $what, string $value = ''): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                MHUBV_AdoptDismissState($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Geschwister-Instanz mitten im Reload/Löschen darf das
                // Ausblenden der aufrufenden Instanz nicht mitreißen — @ hält
                // Fatals bekanntlich nicht auf (siehe CLAUDE.md, MigrationsHub-
                // Brücke).
            }
        }
    }

    /** Reiner Übernahme-Schritt für eine Geschwister-Instanz — siehe PropagateDismiss(). */
    public function AdoptDismissState(string $what, string $value)
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ForumHint':
                $this->WriteAttributeBoolean('ForumHintGone', true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString('SeenNews', $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Für Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand übernehmen wollen — siehe AdoptDismissFromSibling(). */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => $this->ReadAttributeBoolean('ForumHintGone'),
            'seenNews'         => $this->ReadAttributeString('SeenNews'),
        ];
    }

    /**
     * Gegenrichtung zu PropagateDismiss(): eine neu angelegte Instanz sieht
     * beim ersten ApplyChanges() bei einer beliebigen Geschwister-Instanz
     * nach und übernimmt deren Stand, statt die Hinweise erneut zu zeigen.
     * Zieht nur vor (false→true, ältere→neuere News-Version), überschreibt
     * nie einen schon weiter fortgeschrittenen eigenen Stand.
     */
    private function AdoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone') && $this->ReadAttributeBoolean('ForumHintGone')
            && $this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = MHUBV_GetDismissState($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean('PurposeIntroGone') && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!$this->ReadAttributeBoolean('ForumHintGone') && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean('ForumHintGone', true);
            }
            if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION && ($state['seenNews'] ?? '') === self::NEWS_VERSION) {
                $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
            }
            break;
        }
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
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own energy system — and a few evenings spent typing. Still: hobby or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private, non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or license questions? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Like the module and want to leave something anyway? A small donation is appreciated — entirely voluntary, nothing expected in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'MeterHubVirtual does not measure anything itself — it CALCULATES, from power/energy values that already exist (from MeterHub or other sources), exactly the one number that is missing.'],
                ['type' => 'Label', 'caption' => 'Typical cases: the unknown remainder of a house connection (main meter minus all known consumers), the sum of several meters into one total, or a meter whose value has to be split proportionally between several instances/tenants.'],
                ['type' => 'Label', 'caption' => 'In short: an additional, virtual meter without any additional hardware. How to put it together is described in the panel "🔌 Meters" further below.'],
                ['type' => 'Label', 'caption' => '🙏 Thanks to Sepp Lausch (seppm, community.symcon.de/u/seppm) — beta tester with KNX metering expertise, who decisively shaped this module.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBV_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
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
            'caption' => '🆕  New in this version',
            'items' => [
                ['type' => 'Label', 'caption' => '• 🌐 New: MeterHubVirtual is now available in German and English. Texts follow the Symcon language setting: the form is translated by the console, messages are shown in the set language, and variable names are set in the language that is active when you click "Apply". Identifiers, properties and functions are unchanged — nothing is lost on existing instances.'],
                ['type' => 'Label', 'caption' => '• 📈 Important: MeterHubVirtual no longer switches archiving (logging) on by itself. If the outputs (power, import, feed-in, extrapolated energy) are not archived, a notice with the button "Enable archiving for these variables" appears at the top of the form. Archiving that is already running stays as it is and is never switched off. New instances only archive after you click; archive compaction only works on archived variables.'],
                ['type' => 'Label', 'caption' => '• 🎛️ Display: the profile now comes as the default presentation of the output, no longer as a custom profile — that belongs to you. A custom profile of its own (NRG.*, MHB.*, MHBV.*) that MeterHubVirtual set earlier is removed by the module exactly once per instance (marker); the default presentation shows the same thing, and if you enter one yourself later, it stays. A different profile you chose stays as well.'],
                ['type' => 'Label', 'caption' => '• 🪟 New: choose the form layout yourself. In the new panel "Form: layout" (at the bottom, before the forum notice) you decide per section whether it is expanded, collapsed or hidden when opened, and at which position it sits. Symcon does not remember expanding and collapsing by itself, so the form looks the way you need it every time. Hidden sections keep their settings and keep working. No tick means "default"; "Reset to default" restores everything. Takes effect after "Apply" the next time the form is opened.'],
                ['type' => 'Label', 'caption' => '• 📈 Import and feed-in now continue seamlessly when the composition changes (member added or removed, duplicate switched, "active" changed). Previously the total meter reading jumped by the difference of the members — in the archive and in daily bars this looked like a huge consumption or a meter reset.'],
                ['type' => 'Label', 'caption' => '• 👯 Duplicate connection detected: if the same device is a member twice (e.g. a wallbox via ChargerHub AND via OCPPHub), only the first connection counts until you decide, and "Check & preview" names both with the reason (same serial number, IP address or almost identical meter readings). The redundant one is flagged in the module of the connection itself (e.g. ChargerHub, OCPPHub) — then it no longer counts anywhere. The new column "active" takes a member out of this sum only.'],
                ['type' => 'Label', 'caption' => '• 🌳 New: members directly in the object tree — everything that is placed as a link or directly under this instance automatically becomes a member, in the order of its position there. New instances start this way; deleted devices stand out immediately as "target missing" instead of silently living on as an orphaned entry.'],
                ['type' => 'Label', 'caption' => '• 🔧 Fix: for devices without a MeterHub identifier (e.g. wallboxes) a single phase could be chosen instead of the total power. Now the values the device module itself reports apply first; otherwise total value before phase value, ambiguous cases are reported. Please look at the "Detected" column of your own instances once.'],
                ['type' => 'Label', 'caption' => '• ✏️ Edit members directly in the table: add (column "Target"), delete, reorder by drag & drop, rename, change target — with "Apply" the links in the object tree are adjusted accordingly. Only links are deleted, never devices.'],
                ['type' => 'Label', 'caption' => '• 🔀 Switches of the linked devices are detected automatically (switch group without any setting); ambiguous devices are reported. Per member you can override or choose "do not switch". Links without a name of their own are named like their target.'],
                ['type' => 'Label', 'caption' => '• 🌳 Nesting: place a link to another virtual meter instance underneath (e.g. several wallbox meters under "Vehicle charging"). Circular references are detected and blocked.'],
                ['type' => 'Label', 'caption' => '• 🌳 Instances with the previous table keep calculating unchanged. A button "Convert to object tree members" creates the links on request — arithmetically identical, the old table is backed up.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBV_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::NEWS_VERSION);
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
                        $meterNote = MHUBV_Text::t('several equivalent power values on the device (%s) — chosen: "%s"', implode(', ', array_map('IPS_GetName', array_merge([$met['power']], $met['extraPower']))), IPS_GetName($met['power']));
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
            return $sa === $sb ? MHUBV_Text::t('same serial number %s', trim((string)$a['serial'])) : null;
        }
        $ia = trim((string)($a['ip'] ?? ''));
        $ib = trim((string)($b['ip'] ?? ''));
        if ($ia !== '' && $ia === $ib) {
            return MHUBV_Text::t('same IP address %s', $ia);
        }
        if ($ea !== null && $eb !== null && $ea >= 100.0 && $eb >= 100.0 && abs($ea - $eb) <= 0.01 * max($ea, $eb)) {
            return MHUBV_Text::t('meter readings almost identical (%s / %s)', MHUBV_Text::num($ea, 1), MHUBV_Text::num($eb, 1));
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
                // Viele Geräte hinter EINEM Modbus-TCP-Gateway (blue'Log SCADA,
                // jedes RTU-over-TCP-Gateway) teilen sich dieselbe Host-IP und
                // unterscheiden sich nur über die Unit-ID — ohne sie hätte
                // SameDevice() sie fälschlich als dasselbe Gerät erkannt
                // (Dashboard-Fund Solarpark 14.09.2026: 24 WR-Instanzen hinter
                // einem blue'Log mit identischer Host-IP, 23 davon fälschlich
                // als Dublette der 24. ausgeschlossen — MHUBV_GetFunctions()
                // lieferte nur 1 von 24 members). Nur der Fallback über die
                // eigene Host-Property betroffen; deviceIP/deviceHost aus
                // einem Fremdvertrag (ChargerHub/OCPPHub) bleibt unverändert,
                // dort ist "gleiche IP" absichtlich der Erkennungsweg.
                $u = @IPS_GetProperty($inst, 'UnitId');
                if (is_int($u) && $u > 0) {
                    $id['ip'] .= ':' . $u;
                }
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
            $label = MHUBV_Text::t('"%s"', $n['name']);
            if ($n['target'] === $this->InstanceID) {
                $errors[] = MHUBV_Text::t('Member %s points to this instance itself — that would create an endless loop.', $label);
                continue;
            }
            foreach (['power', 'imp', 'exp'] as $f) {
                $vid = $n[$f];
                if ($vid > 0 && IPS_ObjectExists($vid) && (int)IPS_GetParent($vid) === $this->InstanceID
                    && in_array((string)IPS_GetObject($vid)['ObjectIdent'], ['power', 'energy_import', 'energy_export'], true)) {
                    $errors[] = MHUBV_Text::t('Member %s points to an output of this instance itself — that would create a feedback loop.', $label);
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
                $errors[] = MHUBV_Text::t('Member %s contains (directly or via further virtual meters) this instance again — a circular reference that can never be resolved.', $label);
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
            return $n['target'] > 0
                ? MHUBV_Text::t('⚠️ Target missing (#%d) — delete the link or link it again', $n['target'])
                : MHUBV_Text::t('⚠️ Target missing — delete the link or link it again');
        }
        $parts = [];
        foreach (['power' => 'Power: %s', 'imp' => 'Import: %s', 'exp' => 'Feed-in: %s'] as $f => $fmt) {
            if ($n[$f] > 0 && IPS_ObjectExists($n[$f])) {
                $parts[] = MHUBV_Text::t($fmt, IPS_GetName($n[$f]));
            }
        }
        if ($n['switch'] > 0 && IPS_ObjectExists($n['switch'])) {
            $parts[] = MHUBV_Text::t('Switch: %s', IPS_GetName($n['switch']));
        } elseif (($n['switchNote'] ?? '') !== '') {
            $parts[] = MHUBV_Text::t('Switch: ambiguous');
        }
        $prefix = ($n['isLink'] ? '🔗 ' : '') . IPS_GetName($n['target']) . ' → ';
        return $prefix . ($parts ? implode(' · ', $parts) : MHUBV_Text::t('nothing found'));
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
            return MHUBV_Text::t('ℹ️ This instance already uses the object tree.');
        }
        $raw = json_decode((string)$this->ReadPropertyString('Nodes'), true);
        if (is_array($raw) && $this->NeedsMigration($raw)) {
            return MHUBV_Text::t('❌ Please complete the pending migration above first.');
        }
        $settings = [];
        $viaDevice = 0;
        $viaVariable = 0;
        $skipped = [];
        foreach ($this->Nodes() as $i => $n) {
            $label = $n['name'] !== '' ? $n['name'] : MHUBV_Text::t('Row %d', $i + 1);
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

        $msg = MHUBV_Text::t('✅ Converted: %d link(s) created under this instance', $viaDevice + $viaVariable);
        $msg .= $viaVariable > 0 ? MHUBV_Text::t(' (%d to the device, %d to a single variable with a fixed assignment).', $viaDevice, $viaVariable) : '.';
        if ($skipped) {
            $msg .= "\n" . MHUBV_Text::t('ℹ️ Without a data point, therefore not added: %s.', implode(', ', $skipped));
        }
        $msg .= MHUBV_Text::t('
The previous table is backed up. From now on: add, remove and reorder members in the object tree.');
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
                $notes[] = MHUBV_Text::t('"%s" sits directly (not as a link) under this instance — a device or variable is never deleted from the form. To remove it, move it elsewhere in the object tree.', $members[$mid]['name']);
            }
        }

        // 2. Zeile für Zeile: umbenennen, Ziel ändern, neu anlegen
        $refused = function (int $target) use (&$notes): bool {
            if (!IPS_ObjectExists($target)) {
                $notes[] = MHUBV_Text::t('Target #%d does not exist — row not applied.', $target);
                return true;
            }
            if ($target === $this->InstanceID) {
                $notes[] = MHUBV_Text::t('This instance cannot be its own member — row not applied.');
                return true;
            }
            $seen = [];
            if ((int)IPS_GetObject($target)['ObjectType'] !== 2 && $this->ReferencesInstance($target, $this->InstanceID, $seen, 0)) {
                $notes[] = MHUBV_Text::t('"%s" itself contains (directly or nested) this instance — circular reference, row not applied.', IPS_GetName($target));
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
                        $notes[] = MHUBV_Text::t('"%s" is not a link — its target cannot be changed.', $members[$mid]['name']);
                    } elseif (isset($targets[$target])) {
                        $notes[] = MHUBV_Text::t('"%s" is already a member (as "%s") — target of "%s" not changed.', IPS_GetName($target), $targets[$target], $members[$mid]['name']);
                    } elseif (!$refused($target)) {
                        unset($targets[$members[$mid]['target']]);
                        IPS_SetLinkTargetID($mid, $target);
                        $targets[$target] = $members[$mid]['name'];
                    }
                }
                $order[] = $mid;
            } elseif ($target > 0) {
                if (isset($targets[$target])) {
                    $notes[] = MHUBV_Text::t('"%s" is already a member (as "%s") — not linked twice.', IPS_GetName($target), $targets[$target]);
                    continue;
                }
                if ($refused($target)) {
                    continue;
                }
                $link = $this->CreateMemberLink($target, $name !== '' ? $name : IPS_GetName($target));
                $targets[$target] = IPS_GetName($link);
                $order[] = $link;
            } else {
                $notes[] = MHUBV_Text::t('A new row without a target was ignored — please choose a device, a variable or another virtual meter in the column "Target".');
            }
        }

        // 3. Reihenfolge: nur neu vergeben, wenn die Einreichung vom natürlichen
        // Ergebnis OHNE Umsortieren abweicht — Basis ist der Stand beim ÖFFNEN
        // (FormSnapshot), nicht der Baum JETZT (HeishaMon-Fund im Symcon-Store-
        // Review 23.09.2026, verwandtes Muster: eine sortierbare Liste, die bei
        // jedem Übernehmen blind ihre gespeicherte Reihenfolge durchsetzt,
        // überschreibt eine zwischenzeitliche Umsortierung von außen — z. B. ein
        // Ziehen im Objektbaum, während diese Maske offen war, ohne dass der
        // Nutzer die Liste selbst angefasst hätte). „Natürlich" heißt: überlebende
        // alte Mitglieder behalten ihre ursprüngliche Relativ-Reihenfolge aus dem
        // Öffnungsstand, neu angelegte Mitglieder hängen ans Ende (ihre in
        // CreateMemberLink() vergebene Anfangsposition bleibt dann unangetastet).
        // Weicht die eingereichte Reihenfolge davon ab — echtes Umsortieren ODER
        // ein neues Mitglied an anderer Stelle als am Ende —, wird neu positioniert.
        $snapshotIds = is_array($snapshot) ? array_map('intval', $snapshot) : [];
        $survivors   = array_values(array_intersect($snapshotIds, $order));
        $newOnes     = array_values(array_diff($order, $snapshotIds));
        $noOpOrder   = array_merge($survivors, $newOnes);
        if ($order !== $noOpOrder) {
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
                echo json_encode(['ok' => false, 'error' => MHUBV_Text::t('Invalid data.')]);
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
            foreach ([['power', 'PowerID', 'Power'], ['imp', 'EnergyImportID', 'Import'], ['exp', 'EnergyExportID', 'Feed-in']] as [$f, , $lbl]) {
                $lbl = MHUBV_Text::t($lbl);
                $vid = $n[$f];
                if ($vid <= 0) {
                    continue;
                }
                if (!IPS_VariableExists($vid)) {
                    $errors[] = MHUBV_Text::t('%s: %s points to variable #%d, which does not exist.', $nr, $lbl, $vid);
                    continue;
                }
                if (isset($usedVars[$vid])) {
                    $errors[] = MHUBV_Text::t('Variable #%d (%s) is used in %s and in %s. A meter may only be included once — otherwise it would be counted twice.', $vid, $lbl, $usedVars[$vid], $nr);
                } else {
                    $usedVars[$vid] = $nr;
                }
            }
        }

        // Gemischte Einheiten je Feld ergeben stillschweigend falsche Werte.
        foreach ([['power', 'Power'], ['imp', 'Import'], ['exp', 'Feed-in']] as [$f, $lbl]) {
            $lbl = MHUBV_Text::t($lbl);
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
                $errors[] = MHUBV_Text::t('For %s the data points have different units: %s. That would silently give wrong values — please bring them to one unit.', $lbl, implode(' vs. ', $parts));
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
                ? MHUBV_Text::t('No member under this instance provides a meter any more (all links are missing or point to nothing). Existing output variables are therefore left untouched until at least one member sits in the object tree again.')
                : MHUBV_Text::t('The current formula yields no output at all any more — no row has a meter any more. Existing output variables are therefore left untouched until this is fixed.');
        }

        return $errors;
    }

    /** „Zeile 3" (Tabelle) bzw. „Mitglied „Wallbox"" (Objektbaum) für Meldungen. */
    private function RowLabel(int $i, array $n): string
    {
        if (isset($n['member'])) {
            return $n['name'] !== '' ? MHUBV_Text::t('Member "%s"', $n['name']) : MHUBV_Text::t('Member #%s', $n['member']);
        }
        return MHUBV_Text::t('Row %d', $i + 1);
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
                $sure = !str_starts_with($d['why'], (string)strstr(MHUBV_Text::t('meter readings almost identical (%s / %s)'), '(', true));
                $counted = (($n['excluded'] ?? '') === 'undecided') ? $lb : $la;
                $warnings[] = $sure
                    ? MHUBV_Text::t('%s and %s are the same device (%s) — it would be counted twice in the total; until you decide, only %s counts (the connection with current readings, otherwise the first). Please set in the module of the redundant connection (e.g. ChargerHub or OCPPHub) that it is a duplicate: then it no longer counts anywhere, including the dashboard.', $la, $lb, $d['why'], $counted)
                    : MHUBV_Text::t('%s and %s are probably the same device (%s) — it would be counted twice in the total; until you decide, only %s counts (the connection with current readings, otherwise the first). Please set in the module of the redundant connection (e.g. ChargerHub or OCPPHub) that it is a duplicate: then it no longer counts anywhere, including the dashboard.', $la, $lb, $d['why'], $counted);
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
                $warnings[] = MHUBV_Text::t('%s: the linked target no longer exists — counts as 0 in the total. Delete the link in the object tree or repoint it to an existing device.', $label);
            } elseif ($n['power'] <= 0 && $n['imp'] <= 0 && $n['exp'] <= 0) {
                $warnings[] = MHUBV_Text::t('%s: neither a power (W) nor an energy meter (kWh) was found at the target — counts as 0 in the total%s. A data point can be assigned by hand in the members table below.', $label, $n['switch'] > 0 ? MHUBV_Text::t(', but is switched with the group') : '');
            }
            if (($n['meterNote'] ?? '') !== '') {
                $warnings[] = MHUBV_Text::t('%s: %s — if this is not the total power, set "Override power" in the members table.', $label, $n['meterNote']);
            }
            if (($n['switchNote'] ?? '') !== '') {
                $warnings[] = MHUBV_Text::t('%s: switch ambiguous — %s (column "Override switch", or "do not switch").', $label, $n['switchNote']);
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
                $prefix = $n['name'] !== '' ? MHUBV_Text::t('Row %d ("%s")', $i + 1, $n['name']) : MHUBV_Text::t('Row %d (unnamed)', $i + 1);
            }
            if ($anyImp && $n['imp'] <= 0) {
                $warnings[] = MHUBV_Text::t('%s: has power but no import — other rows in this formula have one. The import total counts this row with 0 kWh instead of its actual consumption. The missing value can be extrapolated from the power below.', $prefix);
            }
            if ($anyExp && $n['exp'] <= 0) {
                $warnings[] = MHUBV_Text::t('%s: has power but no feed-in — other rows in this formula have one. The feed-in total counts this row with 0 kWh.', $prefix);
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
                : ($n['name'] !== '' ? MHUBV_Text::t('Row %d ("%s")', $i + 1, $n['name']) : MHUBV_Text::t('Row %d (unnamed)', $i + 1));
            if (!IPS_VariableExists($n['switch'])) {
                $warnings[] = MHUBV_Text::t('%s: the entered switch (variable #%d) no longer exists — the row is not switched by the group.', $prefix, $n['switch']);
            } elseif ((int)(IPS_GetVariable($n['switch'])['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                $warnings[] = MHUBV_Text::t('%s: the entered switch is not a Boolean variable (on/off) — the row is not switched by the group.', $prefix);
            } elseif ($n['factor'] <= 0) {
                $warnings[] = MHUBV_Text::t('%s: switch set, but the share is subtracted or 0 — subtracted rows are deliberately not switched by the group switch (the row itself stays switchable).', $prefix);
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
            $defs[] = ['power', MHUBV_Text::t('Power'), 'NRG.Watt', 'power'];
        }
        if ($has['imp']) {
            $defs[] = ['energy_import', MHUBV_Text::t('Import'), 'NRG.kWh', 'imp'];
        }
        if ($has['exp']) {
            $defs[] = ['energy_export', MHUBV_Text::t('Feed-in'), 'NRG.kWh', 'exp'];
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
        // Beim Symcon-START (Kernel noch nicht KR_READY) wird NICHT gelöscht, nur angelegt
        // (Store-Review-Checkliste Punkt 17, EMS 18.10.2026): im Baum-Modus lösen sich die
        // Datenpunkte der Mitglieder über Fremdinstanzen auf (MetersOfDevice() fragt deren
        // *_GetFunctions-Vertrag), und die sind beim Start noch nicht instanziiert — eine
        // dadurch zu klein ausfallende Ausgabeliste würde sonst Ausgabevariablen samt
        // Archivhistorie löschen. Nach KR_READY läuft ApplyChanges() über MessageSink()
        // ohnehin erneut und räumt dann mit vollständigen Daten auf.
        $mayDelete = !function_exists('IPS_GetKernelRunlevel') || IPS_GetKernelRunlevel() === KR_READY;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            // "Energie hochgerechnet"-Variablen (siehe CALC_ENERGY_IDENT_PREFIX)
            // bleiben von dieser Aufräumung ausgenommen — sie sollen auch dann
            // als Historie stehen bleiben, wenn keine Zeile sie gerade mehr
            // referenziert (AddCalculatedEnergy()), nur ihre Fortschreibung
            // in AdvanceCalculatedEnergy() endet dann.
            if ($mayDelete && $o['ObjectType'] === 2 && $o['ObjectIdent'] !== '' && !isset($valid[$o['ObjectIdent']])
                && !str_starts_with($o['ObjectIdent'], self::CALC_ENERGY_IDENT_PREFIX)) {
                $this->UnregisterVariable($o['ObjectIdent']);   // Checkliste 26: UnregisterVariable statt IPS_DeleteVariable
            }
        }
        $pos = 0;
        foreach ($defs as [$ident, $caption, $profile, $field]) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $created = !$vid;
            $posBefore = $vid ? (int)IPS_GetObject($vid)['ObjectPosition'] : 0;
            // Das Profil kommt als Standarddarstellung (Checkliste 28, Store-Review Messwertrechner): das
            // benutzerdefinierte Profil gehört dem Benutzer und wird nie gesetzt.
            $vid = $this->RegisterVariableFloat($ident, $caption, ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => $profile], $pos);
            IPS_SetName($vid, $caption);
            // Store-Review-Fund 9m (HeishaMon-Sitzung, 28.09.2026, siehe CLAUDE.md):
            // Position nur beim erstmaligen Anlegen setzen, sonst wirft eine
            // Umsortierung im Objektbaum von Hand bei jedem ApplyChanges() automatisch
            // wieder um. $pos zählt trotzdem immer weiter — neue Variablen sollen sich
            // weiterhin an dieser Reihenfolge orientieren.
            if (!$created && (int)IPS_GetObject($vid)['ObjectPosition'] !== $posBefore) {
                IPS_SetPosition($vid, $posBefore);
            }
            $pos++;
            $this->DropOwnCustomProfile($vid);
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
        $this->WriteAttributeBoolean('OwnCustomProfileCleared', true);
        $this->CommitCompactionState();
    }

    /**
     * Merkt nach einem vollständigen Registrierungsdurchlauf, ob die automatische Verdichtung je Kategorie
     * jetzt angewendet wird. Erst damit löst ein späteres Ausschalten (an → aus) das einmalige Zurücksetzen
     * der Regeln im Archiv aus; ein von vornherein ausgeschalteter Schalter lässt das Archiv in Ruhe.
     */
    private function CommitCompactionState(): void
    {
        foreach (['Power', 'Energy'] as $kind) {
            $on = $this->ReadPropertyBoolean('AutoCompaction' . $kind);
            if ($this->ReadAttributeBoolean('CompactionApplied' . $kind) !== $on) {
                $this->WriteAttributeBoolean('CompactionApplied' . $kind, $on);
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
     * Frühere Versionen haben ein eigenes Profil (NRG.*, MHB.*, MHBV.*) als benutzerdefiniertes Profil gesetzt. Das
     * Profil gehört jetzt der Standarddarstellung (Checkliste 28): ein solches EIGENES Profil wird einmalig entfernt,
     * sonst würde es die Anzeige dauerhaft überdecken. Ein vom Nutzer gewähltes fremdes Profil bleibt unangetastet.
     */
    private function DropOwnCustomProfile(int $vid): void
    {
        // Nur EINMAL je Instanz (Merker OwnCustomProfileCleared, gesetzt am Ende von RegisterVariables()): trägt der Nutzer
        // später selbst wieder ein Profil ein, bleibt es unangetastet.
        $custom = (string)(@IPS_GetVariable($vid)['VariableCustomProfile'] ?? '');
        if (!$this->ReadAttributeBoolean('OwnCustomProfileCleared') && $custom !== '' && preg_match('/^(NRG|MHB|MHBV)\./', $custom) === 1) {
            IPS_SetVariableCustomProfile($vid, '');
        }
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
        // Store-Review-Fund 9m (HeishaMon-Sitzung, 28.09.2026, siehe CLAUDE.md):
        // Position nur beim erstmaligen Anlegen setzen, sonst wirft eine
        // Umsortierung im Objektbaum von Hand bei jedem ApplyChanges() automatisch
        // wieder um.
        $vid = @IPS_GetObjectIDByIdent(self::IDENT_GROUP_SWITCH, $this->InstanceID);
        $created = !$vid;
        $posBefore = $vid ? (int)IPS_GetObject($vid)['ObjectPosition'] : 0;
        $vid = $this->RegisterVariableBoolean(self::IDENT_GROUP_SWITCH, MHUBV_Text::t('Group switch'), ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => '~Switch'], $pos);
        IPS_SetName($vid, MHUBV_Text::t('Group switch'));
        if (!$created && (int)IPS_GetObject($vid)['ObjectPosition'] !== $posBefore) {
            IPS_SetPosition($vid, $posBefore);
        }
        $pos++;
        $this->DropOwnCustomProfile($vid);
        $this->EnableAction(self::IDENT_GROUP_SWITCH);

        $vid = @IPS_GetObjectIDByIdent(self::IDENT_GROUP_STATE, $this->InstanceID);
        $created = !$vid;
        $posBefore = $vid ? (int)IPS_GetObject($vid)['ObjectPosition'] : 0;
        $vid = $this->RegisterVariableInteger(self::IDENT_GROUP_STATE, MHUBV_Text::t('Group state'), ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => self::PROFILE_GROUP_STATE], $pos);
        IPS_SetName($vid, MHUBV_Text::t('Group state'));
        if (!$created && (int)IPS_GetObject($vid)['ObjectPosition'] !== $posBefore) {
            IPS_SetPosition($vid, $posBefore);
        }
        $pos++;
        $this->DropOwnCustomProfile($vid);
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
            return [0, MHUBV_Text::t('several switchable variables (%s) — please choose by hand in the column "Switch"', implode(', ', array_map('IPS_GetName', $found)))];
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
            return MHUBV_Text::t('ℹ️ This group has no switchable members (column "Switch" is empty or only set on subtracted rows).');
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
        $msg = $on ? MHUBV_Text::t('✅ Switched on: %d of %d member(s).', $ok, count($sw)) : MHUBV_Text::t('✅ Switched off: %d of %d member(s).', $ok, count($sw));
        if ($failed) {
            $msg .= ' ' . MHUBV_Text::t('⚠️ Not switched: %s.', implode(', ', $failed));
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
            return MHUBV_Text::t('❌ No rows passed.');
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
                $label = $n['name'] !== '' ? $n['name'] : MHUBV_Text::t('Member %d', $i + 1);
            } else {
                if ((int)($r['PowerID'] ?? 0) <= 0) {
                    continue;
                }
                [$did] = $this->DeviceOf((int)$r['PowerID']);
                $label = trim((string)($r['Name'] ?? '')) !== '' ? $r['Name'] : MHUBV_Text::t('Row %d', $i + 1);
            }
            if ($did <= 0 || $did === $this->InstanceID) {
                continue;
            }
            [$sw, $note] = $this->SwitchOfDevice($did);
            if ($sw > 0) {
                $rows[$i]['SwitchID'] = $sw;
                $set++;
            } elseif ($note !== '') {
                $notes[] = '   ' . MHUBV_Text::t('⚠️ %s: %s', $label, $note);
            }
        }
        if ($set > 0) {
            $this->UpdateFormField($tree ? 'MemberSettings' : 'Nodes', 'values', json_encode(array_values($rows)));
        }
        $msg = $set > 0
            ? MHUBV_Text::t('✅ %d switch(es) found and entered in the column "Switch" — please check and do not forget to "Apply".', $set)
            : MHUBV_Text::t('ℹ️ No further switches found (rows without power or without a Boolean variable with an action on the device).');
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
            ['caption' => '— off —', 'value' => -1],
            ['caption' => '1× per minute', 'value' => 0],
            ['caption' => '1× per 5 minutes', 'value' => 1],
            ['caption' => '1× per hour', 'value' => 2],
            ['caption' => '1× per day', 'value' => 3],
            ['caption' => '1× per week', 'value' => 4],
            ['caption' => '1× per month', 'value' => 5],
            ['caption' => '1× per year', 'value' => 6],
            ['caption' => 'Delete values', 'value' => 7],
        ];
    }

    /** Sechs Formularfelder für eine Verdichtungs-Kategorie ('Power'/'Energy'), mit erklärender Zwischenüberschrift. */
    private function CompactionFields(string $kind): array
    {
        $opts = $this->CompactionTypeOptions();
        return [
            ['type' => 'CheckBox', 'name' => 'AutoCompaction' . $kind, 'caption' => 'Enable automatic compaction'],
            ['type' => 'Label', 'caption' => '⚠️ When switching off (from on to off), MeterHub actively deletes all three compaction stages of this category in the archive once (including ones set by hand in the console). If the switch is off from the start, MeterHub does not touch the compaction in the archive — new instances therefore start with "off".'],
            [
                'type' => 'PopupButton', 'caption' => 'What do the three compaction stages mean?', 'width' => '480px',
                'popup' => [
                    'caption' => 'Archive compaction in three stages',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Compaction subsequently reduces the level of detail of logged values so the archive does not grow without limit — the older a value, the coarser it usually can be. Three stages, each with its own point in time and its own target resolution:'],
                        ['type' => 'Label', 'caption' => '"Compact directly to" — applies immediately, from the first logged value.'],
                        ['type' => 'Label', 'caption' => '"After this many months … compact to" (two stages) — only takes effect once a value has reached the given age.'],
                        ['type' => 'Label', 'caption' => 'Example (default presets): directly 1×/minute, after 1 month 1×/5 minutes, after 12 months 1×/hour — values thus automatically become coarser over time, never finer.'],
                        ['type' => 'Label', 'caption' => 'A stage only takes effect if its target resolution is actually COARSER than the calculation interval above — otherwise there would be nothing to compact there; the stage then has no effect, even if it is set.'],
                        ['type' => 'Label', 'caption' => '"— off —" on a single stage actively DELETES a rule set there before, exactly like the main switch above — not just "stop touching it".'],
                    ],
                ],
            ],
            ['type' => 'Select', 'name' => 'CompactDirect' . $kind, 'caption' => 'Compact directly to', 'options' => $opts],
            ['type' => 'NumberSpinner', 'name' => 'CompactStage2Months' . $kind, 'caption' => 'After this many months', 'minimum' => 0, 'maximum' => 120, 'suffix' => MHUBV_Text::t(' month(s)')],
            ['type' => 'Select', 'name' => 'CompactStage2Type' . $kind, 'caption' => '… compact to', 'options' => $opts],
            ['type' => 'NumberSpinner', 'name' => 'CompactStage3Months' . $kind, 'caption' => 'After this many months', 'minimum' => 0, 'maximum' => 120, 'suffix' => MHUBV_Text::t(' month(s)')],
            ['type' => 'Select', 'name' => 'CompactStage3Type' . $kind, 'caption' => '… compact to', 'options' => $opts],
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
            // Nie angewendet (neue Instanz mit Standard „aus" oder ein Bestandswert, der ohne unser
            // Zutun auf aus steht): das Archiv NICHT anfassen. Nur der beobachtete Wechsel von an auf
            // aus setzt zurück (Attribut CompactionApplied, siehe CommitCompactionState()).
            if (!$this->ReadAttributeBoolean('CompactionApplied' . $kind)) {
                return [];
            }
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

    // -----------------------------------------------------------------------
    // Archivierung (Store-Review 30.10.2026, Checkliste 19): nur auf Klick im Formular einschalten
    // -----------------------------------------------------------------------

    /** Archiv-Instanz oder 0, wenn es keine gibt. */
    private function ArchiveInstanceID(): int
    {
        if (!function_exists('AC_GetLoggingStatus')) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 ? (int)$ids[0] : 0;
    }

    /**
     * Eigene Variablen, die archiviert werden sollen, aber (noch) nicht geloggt werden.
     *
     * @return array vid => Zählerstand?
     */
    private function ArchivingMissing(): array
    {
        $ac = $this->ArchiveInstanceID();
        if ($ac <= 0) {
            return [];
        }
        $out = [];
        foreach ($this->OutputDefs() as [$ident, , , $field]) {
            $vid = (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid && !@AC_GetLoggingStatus($ac, $vid)) {
                $out[$vid] = $field !== 'power';
            }
        }
        foreach ($this->Nodes() as $n) {
            foreach (['imp', 'exp'] as $ef) {
                $vid = (int)$n[$ef];
                if ($vid > 0 && $this->IsOwnCalculatedEnergyVar($vid) && !@AC_GetLoggingStatus($ac, $vid)) {
                    $out[$vid] = true;
                }
            }
        }
        return $out;
    }

    /** Formular-Hinweis samt Knopf; null, wenn alles geloggt wird oder kein Archiv da ist. */
    private function ArchivingPanel(): ?array
    {
        $missing = $this->ArchivingMissing();
        if (!$missing) {
            return null;
        }
        $lines = [];
        foreach (array_keys($missing) as $vid) {
            $lines[] = ['type' => 'Label', 'caption' => sprintf('   • %s (#%d)', IPS_GetName($vid), $vid)];
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PanelLogging', 'expanded' => true,
            'caption' => count($missing) === 1 ? MHUBV_Text::t('📈  Archiving missing (1 variable)') : sprintf(MHUBV_Text::t('📈  Archiving missing (%d variables)'), count($missing)),
            'items' => array_merge(
                [['type' => 'Label', 'caption' => 'These variables are not archived. Without an archive there is no history, no consumption analysis and no archive compaction. MeterHubVirtual does not switch archiving on by itself — that is your decision. The button switches it on for these variables (the module sets the aggregation type and compaction appropriately). Archiving that is already running is never switched off.']],
                $lines,
                [['type' => 'Button', 'caption' => '📈  Enable archiving for these variables', 'onClick' => 'echo MHUBV_EnableArchiving($id);']]
            ),
        ];
    }

    /** Schaltet die Archivierung für die fehlenden eigenen Variablen ein (Klick im Formular) und richtet Aggregationstyp und Verdichtung ein. */
    public function EnableArchiving(): string
    {
        $ac = $this->ArchiveInstanceID();
        if ($ac <= 0) {
            return MHUBV_Text::t('❌ No archive instance found.');
        }
        $n = 0;
        foreach ($this->ArchivingMissing() as $vid => $counter) {
            AC_SetLoggingStatus($ac, $vid, true);
            $this->SetArchive($vid, $counter, $this->ReadPropertyInteger('Interval'));
            $n++;
        }
        $this->UpdateFormField('PanelLogging', 'visible', false);
        return $n === 0 ? MHUBV_Text::t('ℹ️ Nothing was missing — all variables are already archived.') : sprintf(MHUBV_Text::t('✅ Archiving enabled for %d variable(s).'), $n);
    }

    private function SetArchive($vid, bool $counter = false, int $intervalSeconds = 10)
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($ids) > 0) {
            // Das Logging einer Variablen gehört dem Benutzer (Store-Review 30.10.2026, Checkliste 19): MeterHubVirtual
            // schaltet es nie von selbst ein, sondern nur auf Klick im Formular (EnableArchiving()). Aggregationstyp und
            // Verdichtung setzt es nur dort, wo das Logging schon läuft.
            if (!AC_GetLoggingStatus($ids[0], $vid)) {
                return;
            }
            AC_SetAggregationType($ids[0], $vid, $counter ? 1 : 0);
            $plan = $this->CompactionPlan($intervalSeconds, $counter ? 'Energy' : 'Power');
            if ($plan === []) {
                return; // Verdichtung aus und nie angewendet — Archiv unangetastet lassen
            }
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
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP_STATE, 0, MHUBV_Text::t('Off'), 'Power', -1);
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP_STATE, 1, MHUBV_Text::t('Partly on'), 'Power', 0xFFA500);
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP_STATE, 2, MHUBV_Text::t('On'), 'Power', 0x00FF00);
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
            return MHUBV_Text::t('ℹ️ The instance is being reloaded, nothing was calculated.');
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return MHUBV_Text::t('ℹ️ The instance is deactivated, nothing was calculated.');
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
            return MHUBV_Text::t('✅ Members applied again from the object tree and calculated (%s).', date('H:i:s'));
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
            ? MHUBV_Text::t('✅ Recalculated: %d output(s) updated (%s).', $count, date('H:i:s'))
            : MHUBV_Text::t('ℹ️ No output to calculate — first enter meters above and apply.');
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
            $label = MHUBV_Text::t($ident === 'energy_export' ? 'Feed-in' : 'Import');
            $out[] = MHUBV_Text::t('%s: offset %s kWh due to changed composition — the meter reading continues seamlessly and therefore deviates by this value from the sum of the members.', $label, ($off > 0 ? '+' : '−') . MHUBV_Text::num(abs($off), 3));
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
            return MHUBV_Text::t('❌ No device selected.');
        }
        if ($this->IsTreeMode()) {
            return $this->AddDeviceToTree($deviceId);
        }
        $m = $this->MetersOfDevice($deviceId);
        if ($m['power'] === 0 && $m['imp'] === 0 && $m['exp'] === 0) {
            return MHUBV_Text::t('❌ "%s" — no suitable power/energy data points found (neither known NRG-Stack idents nor a W/kWh profile below). Please use "Add" below instead and choose the variable by hand.', IPS_GetName($deviceId));
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

        $parts = $this->FoundParts($m, $sw, $swNote);
        $msg = MHUBV_Text::t('✅ "%s" added as a new row — %s.', IPS_GetName($deviceId), implode(', ', $parts));
        if (!empty($m['extra'])) {
            $names = array_map('IPS_GetName', $m['extra']);
            $msg .= "\n" . MHUBV_Text::t('⚠️ Further kWh data points found on this device but not assigned automatically — they could be feed-in instead of import, please check and enter them in the row by hand if needed: %s.', implode(', ', $names));
        }
        $msg .= MHUBV_Text::t(' Please check in the table and do not forget to "Apply".');
        return $msg;
    }

    /** Lesbare Aufzählung, welche Datenpunkte (und welcher Schalter) für ein Gerät gefunden wurden — für die Meldungen von AddDevice()/AddDeviceToTree(). */
    private function FoundParts(array $m, int $sw, string $swNote): array
    {
        $parts   = [];
        $parts[] = $m['power'] > 0 ? MHUBV_Text::t('Power "%s"', IPS_GetName($m['power'])) : MHUBV_Text::t('Power: not found');
        $parts[] = $m['imp']   > 0 ? MHUBV_Text::t('Import "%s"', IPS_GetName($m['imp'])) : MHUBV_Text::t('Import: not found');
        $parts[] = $m['exp']   > 0 ? MHUBV_Text::t('Feed-in "%s"', IPS_GetName($m['exp'])) : MHUBV_Text::t('Feed-in: not found');
        if ($sw > 0) {
            $parts[] = MHUBV_Text::t('Switch "%s"', IPS_GetName($sw));
        } elseif ($swNote !== '') {
            $parts[] = MHUBV_Text::t('Switch: %s', $swNote);
        }
        return $parts;
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
            return MHUBV_Text::t('❌ This instance cannot be its own member.');
        }
        foreach ($this->TreeMembers() as $mem) {
            if ($mem['target'] === $deviceId) {
                return MHUBV_Text::t('ℹ️ "%s" is already a member (as "%s").', $name, $mem['name']);
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
            return MHUBV_Text::t('❌ "%s" — no suitable power/energy data points found (neither known NRG-Stack idents nor a W/kWh profile). Nothing linked. A single variable without a recognizable unit can still be put under this instance as a link and then be given a role in the members table.', $name);
        }
        $seen = [];
        if (!$isVar && $this->ReferencesInstance($deviceId, $this->InstanceID, $seen, 0)) {
            return MHUBV_Text::t('❌ "%s" itself contains (directly or nested) this instance — that would be a circular reference. Nothing linked.', $name);
        }
        [$sw, $swNote] = $this->SwitchOfDevice($isVar ? $this->DeviceOf($deviceId)[0] : $deviceId);
        $link = $this->CreateMemberLink($deviceId, $name);
        // Schalter nicht als Übersteuerung speichern — TreeNodes() findet ihn
        // bei jedem Takt selbst, auch wenn er am Gerät später wechselt.
        $this->StoreMemberSettings([$link => ['Factor' => 100]]);
        $this->ReloadForm();

        $parts = $this->FoundParts($m, $sw, $swNote);
        $msg = MHUBV_Text::t('✅ "%s" linked as a member (link in the object tree under this instance, at the end of the order) — %s.', $name, implode(', ', $parts));
        if (!empty($m['extra'])) {
            $names = array_map('IPS_GetName', $m['extra']);
            $msg .= "\n" . MHUBV_Text::t('⚠️ Further kWh data points found on this device but not assigned automatically — they could be feed-in instead of import, override them in the members table if needed: %s.', implode(', ', $names));
        }
        $msg .= MHUBV_Text::t(' Already in effect — no "Apply" needed. To undo: delete the link in the object tree.');
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
            return MHUBV_Text::t('❌ No area selected.');
        }
        $devices = $this->FindDeviceFamilies($rootId);
        if (!$devices) {
            return MHUBV_Text::t('❌ No known pattern was found under "%s" (currently recognized: instances whose names end in "Wirkleistung"/"Hauptzähler kWh", e.g. MDT AZI, or in "Wirkleistung P14/P23 (W)"/"Wirkenergie A14/A23 (kWh)", e.g. Lingg&Janke). Please use the device picker or "Add" in the table instead.', IPS_GetName($rootId));
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
                $alreadyUsed[] = MHUBV_Text::t('%s (%s)', $label, MHUBV_Text::t($fieldLabel));
                return 0;
            }
            return $vid;
        };
        foreach ($devices as $label => $d) {
            $rowsForDevice = [];

            $power = $useField($d, 'power', $label, 'Power');
            $imp   = $useField($d, 'imp', $label, 'Import');
            if ($power > 0 || $imp > 0) {
                $rowsForDevice[] = ['Name' => $label, 'Factor' => 100, 'PowerID' => $power, 'EnergyImportID' => $imp, 'EnergyExportID' => 0];
            }

            $powerImport  = $useField($d, 'power_import', $label, 'Import power');
            $powerExport  = $useField($d, 'power_export', $label, 'Feed-in power');
            $energyImport = $useField($d, 'energy_import', $label, 'Import energy');
            $energyExport = $useField($d, 'energy_export', $label, 'Feed-in energy');
            if ($powerImport > 0 || $energyImport > 0) {
                $rowsForDevice[] = ['Name' => MHUBV_Text::t('%s — Import', $label), 'Factor' => 100, 'PowerID' => $powerImport, 'EnergyImportID' => $energyImport, 'EnergyExportID' => 0];
            }
            if ($powerExport > 0) {
                // Eigene Zeile, NICHT mit der Bezugs-Zeile zusammengelegt:
                // die Leistung braucht hier Faktor -100 (signierte Summe),
                // eine gemeinsame Zeile würde ein gleichzeitig eingetragenes
                // A23 fälschlich mit ins Negative ziehen.
                $rowsForDevice[] = ['Name' => MHUBV_Text::t('%s — Feed-in (power)', $label), 'Factor' => -100, 'PowerID' => $powerExport, 'EnergyImportID' => 0, 'EnergyExportID' => 0];
            }
            if ($energyExport > 0) {
                $rowsForDevice[] = ['Name' => MHUBV_Text::t('%s — Feed-in (energy)', $label), 'Factor' => 100, 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => $energyExport];
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
            return MHUBV_Text::t('ℹ️ All devices found (%s) are already completely wired.', implode(', ', array_keys($devices)));
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
            $msg = MHUBV_Text::t('✅ %d device(s) linked as %d member(s) in the object tree: %s.', count($added), $addedRows, implode(', ', $added));
            if ($alreadyUsed) {
                $msg .= "\n" . MHUBV_Text::t('ℹ️ Already a member, therefore not linked again: %s.', implode(', ', $alreadyUsed));
            }
            return $msg . ' ' . MHUBV_Text::t('Already in effect — no "Apply" needed.');
        }

        $this->UpdateFormField('Nodes', 'values', json_encode($existing));
        $this->UpdateFormField('Nodes', 'rowCount', $this->RowCountFor(count($existing)));

        $msg = MHUBV_Text::t('✅ %d device(s) added as %d new row(s): %s.', count($added), $addedRows, implode(', ', $added));
        if ($alreadyUsed) {
            $msg .= "\n" . MHUBV_Text::t('ℹ️ Already wired elsewhere, therefore not entered again: %s.', implode(', ', $alreadyUsed));
        }
        $msg .= MHUBV_Text::t(' Please check in the table and do not forget to "Apply".');
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

    /**
     * Plausibilitätssperre für AdvanceCalculatedEnergy() (15.09.2026,
     * Solarpark-Fund): bisher schützte dort nur is_finite() gegen NaN/
     * Unendlich — ein einzelner riesiger-aber-endlicher Fehl-Lesewert (z. B.
     * eine verunglückte Modbus-Dekodierung bei einem der bekannten
     * Verbindungsaussetzer) lief ungebremst durch und blieb dauerhaft in
     * der kumulativen kWh-Variable stehen. Live beobachtet: eine 24er-
     * WR-Gruppe sprang in 591 s um 998.698 kWh (≈ 6 GW).
     *
     * Referenzwert ist bewusst NICHT ein fester Watt-Grenzwert (verstieße
     * gegen "keine eigene Anlage als Norm" — eine Balkonanlage und ein
     * Solarpark haben völlig andere Maßstäbe), sondern der ROBUSTE MEDIAN
     * der anderen Mitglieder DESSELBEN Durchlaufs (PlausiblePowerMedian()):
     * Geräte derselben Gruppe unterscheiden sich unter normalen Bedingungen
     * (auch bei Teilverschattung) nicht um Größenordnungen, ein Fehl-
     * Lesewert schon. Braucht kein Verlaufsgedächtnis und passt sich damit
     * automatisch an Tag/Nacht bzw. jede Anlagengröße an. Erst ab drei
     * Mitgliedern mit gültiger Leistung sinnvoll auswertbar; darunter (und
     * bei nahe Null liegendem Median, z. B. nachts) greift nur der
     * generische Absolut-Deckel — kein realer Einzel-Messpunkt dieser Art
     * erreicht 1 GW.
     */
    private const IMPLAUSIBLE_POWER_CEILING_W = 1_000_000_000.0;
    private const IMPLAUSIBLE_POWER_FACTOR = 200.0;

    /** Robuster Median der |Leistung| aller Mitglieder mit gültigem powerID im selben Durchlauf. */
    private function PlausiblePowerMedian(array $nodes): array
    {
        $powers = [];
        foreach ($nodes as $n) {
            $pid = (int)($n['power'] ?? 0);
            if ($pid > 0 && IPS_VariableExists($pid)) {
                $v = (float)GetValue($pid);
                if (is_finite($v)) {
                    $powers[] = abs($v);
                }
            }
        }
        sort($powers);
        $c = count($powers);
        if ($c === 0) {
            return [0.0, 0];
        }
        $median = $c % 2 ? $powers[intdiv($c, 2)] : ($powers[$c / 2 - 1] + $powers[$c / 2]) / 2;
        return [$median, $c];
    }

    /** true = $abs (W) gilt als plausibel und darf in AdvanceCalculatedEnergy() einfließen. */
    private function PlausiblePower(float $abs, float $median, int $peerCount): bool
    {
        if ($abs > self::IMPLAUSIBLE_POWER_CEILING_W) {
            return false;
        }
        if ($peerCount >= 3 && $median > 1.0 && $abs > $median * self::IMPLAUSIBLE_POWER_FACTOR) {
            return false;
        }
        return true;
    }

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
        $vid = $this->RegisterVariableFloat($ident, MHUBV_Text::t('%s — Energy extrapolated', $label), ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => 'NRG.kWh'], 500);
        IPS_SetInfo($vid, MHUBV_Text::t('Extrapolated from power × calculation interval, not a real meter reading — accuracy depends on the interval.'));
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
            $label = $label !== '' ? $label : MHUBV_Text::t('Row %d', $i + 1);
            $r['EnergyImportID'] = $this->EnsureCalculatedEnergyVar($powerId, $label);
            $touched[] = $label;
        }
        unset($r);

        if (!$touched) {
            return MHUBV_Text::t('ℹ️ No row found that has power but no import — nothing to do.');
        }

        $this->UpdateFormField($tree ? 'MemberSettings' : 'Nodes', 'values', json_encode($rows));
        $msg = MHUBV_Text::t('✅ %d row(s) get an extrapolated import variable (power × calculation interval — not an estimate, but less precise than a real meter): %s.', count($touched), implode(', ', $touched));
        $msg .= MHUBV_Text::t(' Please check in the table and do not forget to "Apply".');
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
        [$median, $peerCount] = $this->PlausiblePowerMedian($nodes);
        foreach ($nodes as $n) {
            $powerId = (int)$n['power'];
            if ($powerId <= 0 || !IPS_VariableExists($powerId)) {
                continue;
            }
            $power = (float)GetValue($powerId);
            $deltaKWh = $power * $intervalHours / 1000.0;
            if (!is_finite($deltaKWh)) {
                continue;
            }
            if (!$this->PlausiblePower(abs($power), $median, $peerCount)) {
                $this->SendDebug('Energie hochrechnen', IPS_GetName($powerId) . ": Leistung $power W wirkt unplausibel (Median der Gruppe " . round($median) . " W, $peerCount Mitglieder) — dieser Durchlauf nicht verrechnet.", 0);
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
    public function ScanMeters(?int $root = null, ?string $filter = null, ?bool $needEnergy = null, ?bool $onlyActive = null)
    {
        // Feste Arität (SUITE.md 9e, Migrationsvergleich 13.09.2026): Symcon
        // erzeugt MHUBV_ScanMeters mit genau diesen 4 Parametern — Skripte,
        // die so aufrufen, dürfen nach einem Update nicht brechen. Die
        // Erweiterung um zwei Filter liegt deshalb in ScanMetersEx().
        // Typen Pflicht (0.23.4): ohne sie meldet PHPLibrary bei jedem Laden
        // „Parameter … hat keinen Datentyp“.
        return $this->ScanMetersEx($root, $filter, $needEnergy, $onlyActive);
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
            $this->UpdateFormField('ScanResult', 'caption', MHUBV_Text::t('❌ The chosen search area (#%d) no longer exists.', $root));
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
        $scanOptions = [['caption' => '— choose a result —', 'value' => 0]];
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
                $warn[] = MHUBV_Text::t('Energy not archived (needed for long-term analysis)');
            }
            if ($d['power'] > 0) {
                $age = time() - (int)(@IPS_GetVariable($d['power'])['VariableUpdated'] ?? 0);
                if ($age > 7 * 86400) {
                    $warn[] = MHUBV_Text::t('Power not updated for more than 7 days');
                }
            }
            if (count($d['usedIn']) > 0) {
                $warn[] = MHUBV_Text::t('already used in %s — watch for double counting/splitting when adding it here', implode(', ', array_map(fn($k) => MHUBV_Text::t('"%s"', $k), array_keys($d['usedIn']))));
            }
            $found[] = $d['name'];
            $scanOptions[] = ['caption' => $d['name'], 'value' => $d['id']];
            if ($warn) {
                $notes[] = '   ' . MHUBV_Text::t('⚠️ %s: %s', $d['name'], implode('; ', $warn));
            }
        }

        $scope = [];
        if ($root > 0)          { $scope[] = MHUBV_Text::t('only below "%s"', IPS_GetName($root)); }
        if ($filter !== '')     { $scope[] = MHUBV_Text::t('name contains "%s"', $filter); }
        if ($needEnergy)        { $scope[] = MHUBV_Text::t('only with energy meter'); }
        if ($onlyActive)        { $scope[] = MHUBV_Text::t('only updated in the last 7 days'); }
        if ($onlyUsedElsewhere) { $scope[] = MHUBV_Text::t('only data points that are already part of another instance'); }
        if ($onlyFunction !== '') { $scope[] = MHUBV_Text::t('only function "%s"', isset(self::FUNCTIONS[$onlyFunction]) ? MHUBV_Text::t(self::FUNCTIONS[$onlyFunction][0]) : $onlyFunction); }

        // Trägt weiterhin NICHT automatisch in die Formel-Tabelle ein (bis
        // 0.24.4: jeder Fund landete automatisch als neue Zeile, musste bei
        // Nichtgefallen einzeln mit dem Papierkorb entfernt werden — genau
        // das fand Dietmar unübersichtlich). Die Funde stehen aber jetzt
        // direkt als Auswahlliste unter dem Ergebnistext bereit
        // ("ScanPick" + Knopf) — auswählen und übernehmen, ohne den
        // Umweg über den separaten Geräte-Picker weiter unten.
        $msg = count($found) > 0
            ? MHUBV_Text::t('🔎 %d device(s) found: %s. Choose below in "Select result" and add it — or use "Add" in the table further below for manual variable selection.', count($found), implode(', ', $found))
            : MHUBV_Text::t('🔎 No devices found.');
        $msg .= "\n" . MHUBV_Text::t('Search area: %s', $scope ? implode(', ', $scope) : MHUBV_Text::t('whole installation, unfiltered'));
        $msg .= sprintf(MHUBV_Text::t('
Skipped: %d without W/kWh profile, %d already entered, %d outputs of virtual meters, %d from other NRG-Stack modules, %d already in another virtual meter instance, %d outside the search area, %d by the name filter, %d by the function filter, %d without energy meter, %d silent for more than 7 days.'),
            $skipped['einheit'], $skipped['schonverwendet'], $skipped['virtuell'], $skipped['verbund'], $skipped['andereinstanz'],
            $skipped['bereich'], $skipped['name'], $skipped['funktion'], $filteredOut['ohneenergie'], $filteredOut['inaktiv']);
        if (count($found) === 0 && ($filteredOut['ohneenergie'] + $filteredOut['inaktiv'] + $skipped['bereich'] + $skipped['name'] + $skipped['funktion']) > 0) {
            $msg .= MHUBV_Text::t('
💡 Something was found but filtered out — try loosening a filter.');
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

    /**
     * Vertrag 1.5: ist der Zählerstand dieses virtuellen Zählers aus gemessenen
     * Zählerständen gebildet (true) oder geht mindestens ein Term ein, dessen
     * Stand selbst hochgerechnet ist (false)? Betrachtet werden nur Terme, die
     * wirklich eingehen (nicht ausgesetzt, Anteil ≠ 0). Ein Term gilt als
     * hochgerechnet, wenn er (a) eine eigene „Energie hochgerechnet"-Variable
     * dieser Instanz ist, (b) laut MHUB_GetFunctions().calculatedEnergyIDs zu
     * einem MeterHub gehört, dessen Stand hochgerechnet ist, oder (c) zu einem
     * anderen virtuellen Zähler gehört, der selbst energyMeasured=false meldet
     * (Verkettung; Kreisverweise sind über Validate() ausgeschlossen, ein
     * Wiedereintritt wird in EnergyMeasured() zusätzlich abgefangen). Alles Unbekannte zählt als gemessen —
     * das ist der Vertragsstandard, wenn das Feld fehlt.
     */
    private function EnergyMeasured(): bool
    {
        // Wiedereintritt (Kreisverweis zweier Instanzen, die Validate() eigentlich
        // verhindert): nicht endlos rekursieren, sondern den Vertragsstandard geben.
        static $running = [];
        if (isset($running[$this->InstanceID])) {
            return true;
        }
        $running[$this->InstanceID] = true;
        try {
            return $this->EnergyMeasuredUnguarded();
        } finally {
            unset($running[$this->InstanceID]);
        }
    }

    private function EnergyMeasuredUnguarded(): bool
    {
        $perInstance = []; // Fremdaufruf je Instanz nur einmal
        foreach ($this->Nodes() as $n) {
            if (!empty($n['excluded']) || (float)$n['factor'] == 0.0) {
                continue;
            }
            foreach (['imp', 'exp'] as $k) {
                $vid = (int)$n[$k];
                if ($vid <= 0 || !IPS_VariableExists($vid)) {
                    continue;
                }
                if ($this->IsOwnCalculatedEnergyVar($vid)) {
                    return false;
                }
                $pi = (int)@IPS_GetParent($vid);
                if ($pi <= 0 || $pi === $this->InstanceID || !IPS_InstanceExists($pi)) {
                    continue;
                }
                if (!isset($perInstance[$pi])) {
                    $guid = (string)(IPS_GetInstance($pi)['ModuleInfo']['ModuleID'] ?? '');
                    $d = null;
                    try {
                        if ($guid === self::GUID_METER && function_exists('MHUB_GetFunctions')) {
                            $d = json_decode((string)MHUB_GetFunctions($pi), true);
                        } elseif ($guid === self::GUID_VIRTUAL && function_exists('MHUBV_GetFunctions')) {
                            $d = json_decode((string)MHUBV_GetFunctions($pi), true);
                        }
                    } catch (\Throwable $e) {
                        $d = null;
                    }
                    $perInstance[$pi] = ['guid' => $guid, 'data' => is_array($d) ? $d : []];
                }
                $info = $perInstance[$pi];
                if ($info['guid'] === self::GUID_METER
                    && in_array($vid, array_map('intval', (array)($info['data']['calculatedEnergyIDs'] ?? [])), true)) {
                    return false;
                }
                if ($info['guid'] === self::GUID_VIRTUAL && ($info['data']['energyMeasured'] ?? true) === false) {
                    return false;
                }
            }
        }
        return true;
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
        $energyMeasured = $this->EnergyMeasured();
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
                // 1.5: false = mindestens ein eingehender Term hat einen
                // hochgerechneten Zählerstand (siehe EnergyMeasured()).
                'energyMeasured' => $energyMeasured,
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
            //       (03.09.2026, Schaltgruppe — siehe Abschnitt "Schaltgruppe"),
            // 1.5 = energyMeasured (21.09.2026, je Zuordnung UND auf Instanz-Ebene:
            //       ein reiner Zwischenknoten ohne Funktion hat keine Zuordnung,
            //       die Verkettung braucht das Feld trotzdem).
            'contractVersion' => '1.5',
            'instanceID'  => $this->InstanceID,
            'meter'       => 'virtual',
            'measureMode' => 'combined',
            'latency'     => 'realtime',
            'authority'   => 'auxiliary',
            'pollInterval'=> $pollInterval,
            'archiveWatermarkTs' => null,
            'energyMeasured' => $energyMeasured,
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
        return json_encode($this->BuildForm());
    }

    /**
     * Baut das Formular. Mit $freeOnly nur die Fachpanels (key => Panel, Standardreihenfolge) — dient auch ResetFormLayout().
     *
     * @return array
     */
    private function BuildForm(bool $freeOnly = false)
    {
        // Abgleich mit Geschwister-Instanzen beim Öffnen des Formulars, nicht in ApplyChanges: beim Neuladen der
        // Module sind die anderen Instanzen evtl. noch nicht geladen (Store-Review Tessie, Checkliste 27).
        if (!$freeOnly) {
            $this->AdoptDismissFromSibling();
        }
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
        $locationOptions = [['caption' => '— choose a suggestion —', 'value' => '']];
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
                $check[] = ['type' => 'Label', 'caption' => MHUBV_Text::t('⚠️ At the last "Apply": %s', $note)];
            }
        }
        if ($migration) {
            $check[] = ['type' => 'Label', 'caption' => 'Migration pending — see the panel above.'];
        } elseif (count($errors) > 0) {
            $check[] = ['type' => 'Label', 'caption' => MHUBV_Text::t('❌ %d problem(s) — as long as they exist, nothing is calculated:', count($errors))];
            foreach ($errors as $e) {
                $check[] = ['type' => 'Label', 'caption' => '   • ' . $e];
            }
        } elseif (count($nodes) === 0) {
            $check[] = ['type' => 'Label', 'caption' => $tree
                ? MHUBV_Text::t('No members yet — link a device above or put a link under this instance in the object tree.')
                : MHUBV_Text::t('No meters entered yet.')];
        } else {
            $check[] = ['type' => 'Label', 'caption' => '✅ Formula consistent:'];
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
            'type' => 'List', 'name' => 'Nodes', 'caption' => 'Meters and their share',
            'rowCount' => $this->RowCountFor($migration ? count($rawRows) : count($nodes)),
            'add' => true, 'delete' => true,
            // Drag & Drop zum Umsortieren (Dietmars Anregung 31.08.2026) —
            // rein organisatorisch, das Rechenergebnis ist ordnungsunabhängig
            // (Summe aller Anteile). Symcon erlaubt changeOrder nicht
            // zusammen mit Spalten-Sortierung — wird hier nicht vermisst,
            // die Liste sortiert bisher ohnehin nirgends nach Spalte.
            'changeOrder' => true,
            'columns' => [
                ['caption' => 'Label', 'name' => 'Name', 'width' => '240px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
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
                ['caption' => 'Share (%)', 'name' => 'Factor', 'width' => '130px', 'add' => 100, 'edit' => ['type' => 'NumberSpinner', 'minimum' => -1000, 'maximum' => 1000, 'digits' => 2, 'suffix' => ' %']],
                // Feste statt "auto" Breite (Dietmars Fund 31.08.2026: eine
                // SelectVariable-Spalte zeigt den vollen Objektpfad, "auto"
                // ließ die Zeile dadurch beliebig breit werden — Papierkorb-
                // und Zahnrad-Symbol am Zeilenende rutschten aus dem
                // sichtbaren Bereich, ohne dass sich dorthin scrollen ließ.
                // Feste Breite kappt die Spalte stattdessen (Text wird vom
                // Browser abgeschnitten, per Klick weiterhin änderbar).
                ['caption' => 'Power (W)', 'name' => 'PowerID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                ['caption' => 'Import (kWh)', 'name' => 'EnergyImportID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                ['caption' => 'Feed-in (kWh)', 'name' => 'EnergyExportID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                // Schaltgruppe (Dietmars Entscheidung 03.09.2026, über
                // Dashboard adressiert): viele Mitglieder sind Aktoren, die
                // schalten UND messen — die Bool-Variable mit Aktion hier
                // eintragen (automatisch vorgeschlagen beim Übernehmen eines
                // Geräts bzw. per Knopf „Schalter suchen", von Hand
                // korrigierbar). Nur positive Anteile werden mitgeschaltet.
                ['caption' => 'Switch (Boolean)', 'name' => 'SwitchID', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
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
                ['caption' => 'automatic', 'value' => ''],
                ['caption' => 'Power (W)', 'value' => 'power'],
                ['caption' => 'Import (kWh)', 'value' => 'imp'],
                ['caption' => 'Feed-in (kWh)', 'value' => 'exp'],
            ];
            // Beim Öffnen gezeigte Mitglieder merken — nur diese darf ein
            // gelöschte Zeile beim „Übernehmen" entfernen (ein inzwischen im
            // Objektbaum hinzugekommener Link fehlt in der Tabelle, darf aber
            // nicht deshalb gelöscht werden).
            $this->WriteAttributeString('FormSnapshot', (string)json_encode(array_column($treeRows, 'MemberID')));
            $listDef = [
                'type' => 'List', 'name' => 'MemberSettings',
                'caption' => 'Members — adding, deleting, reordering and renaming takes effect on the links in the object tree with "Apply"',
                'rowCount' => $this->RowCountFor(count($treeRows)),
                'add' => true, 'delete' => true, 'changeOrder' => true,
                'loadValuesFromConfiguration' => false,
                'values' => $treeRows,
                'columns' => [
                    ['caption' => 'Member', 'name' => 'Name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Target', 'name' => 'Target', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectObject']],
                    ['caption' => 'Detected', 'name' => 'Found', 'width' => '360px', 'add' => MHUBV_Text::t('🆕 will be linked with "Apply"')],
                    ['caption' => 'Share (%)', 'name' => 'Factor', 'width' => '130px', 'add' => 100, 'edit' => ['type' => 'NumberSpinner', 'minimum' => -1000, 'maximum' => 1000, 'digits' => 2, 'suffix' => ' %']],
                    ['caption' => 'Role (for a link to a variable)', 'name' => 'Role', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $roleOptions]],
                    ['caption' => 'Override power', 'name' => 'PowerID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Override import', 'name' => 'EnergyImportID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Override feed-in', 'name' => 'EnergyExportID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Override switch', 'name' => 'SwitchID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'do not switch', 'name' => 'NoSwitch', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'active', 'name' => 'Active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'Object ID', 'name' => 'MemberID', 'width' => '90px', 'add' => 0, 'save' => true],
                    // Unsichtbare Ausgangswerte für den Abgleich, siehe TreeFormRows().
                    ['caption' => 'OrigName', 'name' => 'OrigName', 'width' => '0px', 'add' => '', 'save' => true, 'visible' => false],
                    ['caption' => 'OrigTarget', 'name' => 'OrigTarget', 'width' => '0px', 'add' => 0, 'save' => true, 'visible' => false],
                    ['caption' => 'Form', 'name' => 'Form', 'width' => '0px', 'add' => true, 'save' => true, 'visible' => false],
                ],
            ];
        } elseif (!$migration && count($nodes) > 0) {
            $treeOffer = [
                'type' => 'ExpansionPanel', 'name' => 'TreeOfferPanel', 'expanded' => true,
                'caption' => '🌳  New: members directly in the object tree',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Virtual meters can now be assembled solely via the object tree: everything that is placed as a link or directly under this instance is a member — in the order of its position there. A deleted device immediately stands out as "target missing" instead of silently staying in the table as an orphaned entry, and virtual meters can be nested into each other (link to another instance).'],
                    ['type' => 'Label', 'caption' => 'The conversion creates a link under this instance for every row below — to the device if it delivers exactly these data points, otherwise to the single variable with a fixed assignment. Shares and switches are kept, the result is arithmetically identical. The previous table is backed up. Nothing changes without a click here.'],
                    ['type' => 'Button', 'caption' => '🌳  Convert to object tree members', 'onClick' => 'echo MHUBV_ConvertToTree($id);', 'confirm' => MHUBV_Text::t('Create links under this instance for all %d rows and switch to the object tree? Unsaved changes in the table will be lost.', count($nodes))],
                ],
            ];
        }

        if ($migration) {
            $migratedRows = $this->MigratedRows($rawRows);
            $listDef['value'] = json_encode($migratedRows);
            $migrationPanel = [
                'type' => 'ExpansionPanel', 'name' => 'MigrationPanel', 'expanded' => true,
                'caption' => '🔄  Migration required',
                'items' => [
                    ['type' => 'Label', 'caption' => 'MeterHubVirtual now calculates with a flat formula instead of a tree of "abbreviations" and "sits behind" — this instance itself is the top level, every row below is a term with a sign.'],
                    ['type' => 'Label', 'caption' => MHUBV_Text::t('All %d previous rows are shown below as a suggestion, for now all with the sign "+". IMPORTANT: keep only what really should belong to THIS instance (trash icon for all others — in a grown installation that is often the majority, e.g. rows from an earlier search that were never actually wired). Set the sign to "−" where needed, then "Apply".', count($migratedRows))],
                    ['type' => 'Label', 'caption' => 'Until "Apply" is clicked, existing output variables stay unchanged — nothing is recalculated automatically.'],
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
                $meterItems[] = ['type' => 'Label', 'caption' => '🌳 Members of this instance are everything that sits directly under it in the object tree — most simply a link to a meter device, a single variable or another virtual meter instance. Order = position in the object tree. Removing = deleting the link. The three alternatives below create such links for you.'];
            }
            $meterItems[] = ['type' => 'Label', 'caption' => $tree
                ? MHUBV_Text::t('Four equivalent alternatives to add a member — choose the one that suits your situation:')
                : MHUBV_Text::t('Four equivalent alternatives to add a row — choose the one that suits your situation (not a sequence to be worked through in order):')];
            $meterItems[] = ['type' => 'Label', 'caption' => '1. The finder alternative — search the system systematically for candidates, then use one from the result list.'];
            $meterItems[] = ['type' => 'Label', 'caption' => '2. The quick-pick alternative — meter instance/device already known? Choose it directly, no search needed.'];
            $meterItems[] = ['type' => 'Label', 'caption' => '3. The family alternative — add several devices at once from one category (e.g. many identical KNX actuators).'];
            $meterItems[] = ['type' => 'Label', 'caption' => $tree
                ? MHUBV_Text::t('4. The object tree alternative — create a link yourself and put it under this instance (or move an existing one here).')
                : MHUBV_Text::t('4. The manual alternative — by hand in the table below, field by field with the built-in Symcon variable picker.')];

            $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 1. The finder alternative ━━━'];
            $meterItems[] = ['type' => 'Label', 'caption' => '🔎 Finds all data points with a W/kW or kWh profile and groups them by device. Enters nothing into the table automatically — the results are then ready to use directly under "Select result". Variables from known NRG-Stack modules (EMS, InverterHub, ChargerHub, Forecast, Tibber Grid Rewards …) are skipped — they are already correctly integrated there.'];
            $meterItems[] = ['type' => 'SelectObject', 'name' => 'ScanRoot', 'caption' => 'Only search in this area (empty = whole installation)'];
            $meterItems[] = ['type' => 'ValidationTextBox', 'name' => 'ScanFilter', 'caption' => 'Only devices whose name contains this (empty = all)'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanNeedEnergy', 'caption' => 'Only devices with an energy meter (kWh) — hides switches that only report the instantaneous power'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanOnlyActive', 'caption' => 'Only devices that delivered values in the last 7 days — hides dead entries'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanOnlyUsedElsewhere', 'caption' => 'Show only data points that are already part of ANOTHER virtual meter instance (for targeted checking of double use/splitting) — otherwise they are hidden as usual'];
            // Filter nach bereits vergebener Funktion (Dietmars Anregung
            // 02.09.2026): eine Sparte wie "Beleuchtung" sammeln, auch wenn
            // die einzelnen Stromkreise ganz unterschiedlich heißen (Flur,
            // Wohnzimmer, Außenbeleuchtung …) — Name allein (Feld oben)
            // trifft das nicht zuverlässig, die schon an der jeweiligen
            // Ursprungsinstanz gesetzte Funktions-Zuordnung schon.
            $scanFuncOptions = [['caption' => '— any (no restriction) —', 'value' => '']];
            foreach (self::FUNCTIONS as $key => $def) {
                $scanFuncOptions[] = ['caption' => $def[0], 'value' => $key];
            }
            $meterItems[] = ['type' => 'Select', 'name' => 'ScanOnlyFunction', 'caption' => 'Only data points whose source instance already carries this function (e.g. "Lighting" — handy for combining a category across several circuits into one collective meter)', 'options' => $scanFuncOptions];
            $meterItems[] = ['type' => 'Button', 'caption' => '🔎  Search for meters in the system', 'onClick' => 'MHUBV_ScanMetersEx($id, $ScanRoot, $ScanFilter, $ScanNeedEnergy, $ScanOnlyActive, $ScanOnlyUsedElsewhere, $ScanOnlyFunction);'];
            $meterItems[] = ['type' => 'Label', 'name' => 'ScanResult', 'caption' => '', 'visible' => false];
            // Funde direkt übernehmbar (Dietmars Anregung 31.08.2026: "wenn
            // ich schon etwas suchen muss, dann möchte ich auch direkt aus
            // dem Suchdialog etwas übernehmen") — ScanMeters() füllt die
            // Optionen dieses Felds bei jedem Suchlauf neu (siehe dort).
            $meterItems[] = ['type' => 'Select', 'name' => 'ScanPick', 'caption' => 'Select result', 'options' => [['caption' => '— search above first —', 'value' => 0]], 'value' => 0];
            $meterItems[] = ['type' => 'Button', 'caption' => '✅  Use result', 'onClick' => 'echo MHUBV_AddDevice($id, $ScanPick);'];

            // Schneller Weg OHNE Suche (Dietmars Anregung 31.08.2026: "ich
            // möchte die Zählerinstanz auswählen müssen und der Rest muss
            // von alleine kommen" — drei einzelne Variablen-Picker pro
            // Zeile "klickt sich zu Tode"): EIN Gerät direkt wählen,
            // Leistung/Bezug/Einspeisung werden automatisch gesucht (siehe
            // MetersOfDevice()) und als neue Zeile eingetragen — inklusive
            // Ergebnistext, damit sofort sichtbar ist, was gefunden wurde
            // ("genau dieses Ergebnis zu Gesicht bekommen").
            $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 2. The quick-pick alternative ━━━'];
            $meterItems[] = ['type' => 'Label', 'caption' => '⚡ Without a prior search: choose a meter instance/device directly.'];
            $meterItems[] = ['type' => 'SelectObject', 'name' => 'DevicePick', 'caption' => 'Meter instance or device'];
            $meterItems[] = ['type' => 'Button', 'caption' => $tree ? MHUBV_Text::t('🔗  Link as member') : MHUBV_Text::t('✅  Add as new row'), 'onClick' => 'echo MHUBV_AddDevice($id, $DevicePick);'];

            // Geräte-Familie ohne gemeinsamen Container (Dietmars Auftrag
            // 01.09.2026, ausgelöst durch Sepps Diagnose seiner MDT-AZI-
            // Aktoren): jeder Messwert eine eigene KNX-Instanz, nur der
            // Namens-Anfang verbindet sie — der Geräte-Picker oben braucht
            // aber einen Container. Siehe FindDeviceFamilies()/
            // AddDeviceFamily() für die volle Herleitung.
            $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 3. The family alternative ━━━'];
            $meterItems[] = ['type' => 'Label', 'caption' => '🏷️ Detect several meters at once — two patterns: devices without a common container, every measured value its own KNX instance (e.g. MDT AZI, "…Wirkleistung"/"…Hauptzähler kWh"); or a KNX meter with separate import/feed-in values (e.g. Lingg&Janke, "…Wirkleistung P14/P23 (W)"/"…Wirkenergie A14/A23 (kWh)" — automatically yields the right wiring with signs). Choose the category that contains the matching instances.'];
            $meterItems[] = ['type' => 'SelectObject', 'name' => 'FamilyRoot', 'caption' => 'Category with the device family'];
            $meterItems[] = ['type' => 'Button', 'caption' => '🏷️  Detect and add device family', 'onClick' => 'echo MHUBV_AddDeviceFamily($id, $FamilyRoot);'];

            if ($tree) {
                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 4. Directly in the table or in the object tree ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '✏️ In the table below: "Add" and in "Target" choose a device, a variable or another virtual meter; delete rows, reorder by drag & drop, rename "Member" or change the "Target" — with "Apply" the module creates, deletes, sorts and names the links in the object tree accordingly. Only links are deleted, never a device.'];
                $meterItems[] = ['type' => 'Label', 'caption' => '🔗 Or create a link under this instance in the object tree itself — it automatically becomes a member at the next calculation cycle, without "Apply". For a link to a single variable without a recognizable unit, set the "Role" in the table.'];
                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ Afterwards, for each member ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '📐 "Share (%)" in the table below: 100 = add fully, −100 = subtract fully, any value in between is a partial share. Power/import/feed-in are found automatically at the target; only if that does not fit, choose a variable in the respective "override" column (empty = automatic).'];
                $meterItems[] = ['type' => 'Label', 'caption' => '↕️ Order and names: in the table (takes effect with "Apply") or in the object tree (position or name of the link) — both stay in sync. What you change in the object tree while the form is open does not overwrite the form; it only changes what you changed in the table yourself.'];
            } else {
                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ 4. The manual alternative ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '➕ Click "Add" under the table, then choose the matching variable in "Power"/"Import"/"Feed-in" with the built-in Symcon variable picker — for individual cases that the automatic alternatives do not find (correctly).'];

                $meterItems[] = ['type' => 'Label', 'caption' => '━━━ Afterwards, for each row ━━━'];
                $meterItems[] = ['type' => 'Label', 'caption' => '📐 Set "Share (%)": 100 = add fully, −100 = subtract fully, any value in between is a partial share. Example: a feed-in is attributed half to two tenants by quota → 50 in the instance for tenant A, 50 as well in the one for tenant B (or −50, depending on whether it should be added or subtracted) for the SAME variable.'];
                $meterItems[] = ['type' => 'Label', 'caption' => '↕️ Rows can be reordered by drag & drop — purely for your own overview, the result does not depend on the order.'];
            }
            // Fehlende Bezugswerte hochrechnen (Dietmars Auftrag 01.09.2026,
            // ausgelöst durch AZI-Geräte mit reiner Wirkleistung ohne
            // Hauptzähler kWh, z. B. "AZI Backofen"). Bewusst "hochgerechnet",
            // nicht "geschätzt" — Dietmars Grundsatzeinwand: Leistung ×
            // Berechnungs-Intervall ist eine echte Rechnung aus real
            // gemessenen Werten, keine Vermutung. Siehe AddCalculatedEnergy().
            // Nur außerhalb der Migration sinnvoll, die Nodes-Liste zeigt
            // dort erst einen unbestätigten Alt-Vorschlag.
            $meterItems[] = ['type' => 'Label', 'caption' => '🧮 Rows with power but without import: extrapolate from power × calculation interval (not an estimate, but less precise than a real meter — more precise with a short interval, less precise for erratic consumers such as a washing machine).'];
            $meterItems[] = ['type' => 'Button', 'caption' => '🧮  Extrapolate missing energy values from the power', 'onClick' => $tree ? 'echo MHUBV_AddCalculatedEnergy($id, $MemberSettings);' : 'echo MHUBV_AddCalculatedEnergy($id, $Nodes);'];
            // Schaltgruppe (Dietmars Entscheidung 03.09.2026): Schalter für
            // bereits vorhandene Zeilen nachtragen — beim Übernehmen eines
            // Geräts wird er schon automatisch vorgeschlagen, ältere Zeilen
            // holen ihn hiermit nach. Nur die offene Maske, „Übernehmen"
            // bleibt der bewusste letzte Schritt.
            $meterItems[] = ['type' => 'Label', 'caption' => '🔀 Switch group: rows whose device both switches AND measures (e.g. a Z-Wave actuator per light) get their Boolean variable in the column "Switch" — this creates "Group switch" (on/off for all) and "Group state" (off / partial / on) on this instance. Only rows with a positive share are switched together with the group; subtracted rows deliberately are not.'];
            $meterItems[] = ['type' => 'Button', 'caption' => '🔀  Find switches for existing rows', 'onClick' => $tree ? 'echo MHUBV_FindSwitches($id, $MemberSettings);' : 'echo MHUBV_FindSwitches($id, $Nodes);'];
            $meterItems[] = ['type' => 'Label', 'caption' => '✅ Finally click "Apply" (end of the form).'];
        }
        $meterItems[] = $listDef;

        // Feste Elemente oben (Migration, Zweck, Neu, Dokumentation, Grundfelder), dazwischen die wählbaren Fachpanels,
        // unten Aufbau/Forum/Über (SUITE.md 3a).
        $top = [
                $migrationPanel,
                $purposeIntro,
                $newsBanner,
                $this->ArchivingPanel(),
                $treeOffer,
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Documentation & help', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => MHUBV_Text::t('MeterHubVirtual %s — version of this guide.', self::NEWS_VERSION)],
                        ['type' => 'Label', 'caption' => 'Builds a virtual meter from a FORMULA: this instance is the top level, every row below is a term with a share in percent. Result = sum of all shares (100 % = fully added, −100 % = fully subtracted, any value in between is a partial share), separately for power, import and feed-in.'],
                        ['type' => 'Label', 'caption' => 'Example "Collecting": refrigerator (100 %) and well pump (100 %) give their sum — useful when there is no real meter that measures both together.'],
                        ['type' => 'Label', 'caption' => 'Example "Collecting" as a cost block: combine all circuits of a category such as "Lighting" (100 %) in an instance of their own, even if the individual circuits have completely different names (hallway, living room, outdoor lighting …). The result is the energy value (kWh) of this category — the matching euro amount only comes from a tariff (e.g. Tibber Grid Rewards or EMS), MeterHubVirtual itself does not calculate prices. To find all circuits: under "Search for meters" below, set the field "Only data points whose source instance already carries this function" to "Lighting" — this also matches when the name alone is not unambiguous.'],
                        ['type' => 'Label', 'caption' => 'Example "Subtracting": house connection (100 %, own meter), heat pump (−100 %) and wallbox (−100 %) give house connection minus heat pump minus wallbox — the unknown remainder of the house.'],
                        ['type' => 'Label', 'caption' => 'Example "Passing through": just ONE row (100 %) — the instance simply passes this one meter on, useful to give it a dashboard assignment via "Function" without combining it with anything else.'],
                        ['type' => 'Label', 'caption' => 'Example "Splitting": a PV system built in several stages (commissioning years) is allocated the feed-in tariff proportionally by quota — the same feed-in variable is entered with e.g. 60 % in the instance for one share, and 40 % in a second instance for the other share. The same works for a proportional allocation to several tenants.'],
                        ['type' => 'Image', 'image' => self::KONZEPT_DIAGRAMM, 'center' => true],
                        ['type' => 'Label', 'caption' => 'Multi-level nesting (e.g. an intermediate value from several meters from which something is then subtracted again) works across several instances: one instance calculates the intermediate value, its output is wired as an ordinary row in the next instance — no longer within a single instance.'],
                        ['type' => 'Label', 'caption' => '🆕 Object tree as the source of members (default for new instances): everything that is placed as a link or directly under this instance is a member, in the order of its position there. Nesting = put a link to another virtual meter instance underneath (e.g. "Wallbox garage" and "Wallbox carport" under "Vehicle charging"). A circular reference (A contains B, B contains A) is detected and blocked. A link whose target was deleted appears as ⚠️ "target missing" and counts as 0. Instances with the previous table keep calculating unchanged; conversion happens only via the button.'],
                        ['type' => 'Label', 'caption' => '━━━ Step by step ━━━'],
                        ['type' => 'Label', 'caption' => '1. Optionally first: set "Meter name" above — this is at the same time the name of this instance in the object tree, no two separate names to maintain.'],
                        ['type' => 'Label', 'caption' => '2. Optionally for an overview: click "Search for meters" below — shows usable candidates in the result text, but enters nothing.'],
                        ['type' => 'Label', 'caption' => '3. One row per meter — four ways: after the search, choose from "Select result" and add it; without a search, choose directly under "Meter instance or device" and add it; for several devices without a common container (e.g. MDT AZI) choose a category under "Detect device family" — finds all matching devices at once; or by hand click "Add" under the table and choose the matching variable per column with the built-in Symcon variable picker. In the first three cases power/import/feed-in are searched automatically.'],
                        ['type' => 'Label', 'caption' => '4. Set "Share (%)": 100 adds fully, −100 subtracts fully, any value in between is a partial share, also with decimal places (see example "Splitting").'],
                        ['type' => 'Label', 'caption' => '5. Click "Apply".'],
                        ['type' => 'Label', 'caption' => '6. Check at the bottom in the panel "Check & preview": ✅ shows the finished formula WITH current values, ❌ names exactly what is still missing, ⚠️ points to rows that have power but no import although other rows have one (blocks nothing, but can make the import sum come out too low). If a meter fundamentally lacks the import value (e.g. pure active-power actuators such as MDT AZI): click "Extrapolate missing energy values from the power" — calculates power × calculation interval into a variable of its own, clearly labeled (not an estimate, but less precise than a real meter).'],
                        ['type' => 'Label', 'caption' => '7. Optionally: set "Function" so that the dashboard recognizes this instance as a consumer; "Location" (room/floor) for your own overview, independent of the function.'],
                        ['type' => 'Label', 'caption' => 'A data point may appear in only ONE row within the SAME instance — otherwise it would be counted twice, the check reports this. Across several instances, however, the same variable is explicitly allowed (see "Splitting") — the responsibility that the shares make sense overall is then yours.'],
                        ['type' => 'Label', 'caption' => 'Units: power in W, energy as cumulative kWh meter readings. All data points per column must have the same unit; the check reports deviations.'],
                        ['type' => 'Label', 'caption' => 'Rows can be reordered by drag & drop, purely for your own overview — the result does not depend on the order.'],
                    ],
                ],
                // Name der Instanz — Dietmars Frage 31.08.2026, ob sich der
                // nicht auch direkt im eigenen Formular setzen lassen sollte
                // (er fließt als Dashboard-Label in GetFunctions() ein).
                // "InstanceName" ist bewusst KEINE registrierte Property,
                // sondern nur mit dem aktuellen IPS-Namen vorbelegt —
                // IPS_SetName() läuft sofort per onChange, unabhängig vom
                // Client (Konsole/WebFront/App) und von "Übernehmen".
                ['type' => 'ValidationTextBox', 'name' => 'InstanceName', 'caption' => '🆕 Meter name', 'value' => IPS_GetName($this->InstanceID), 'onChange' => 'MHUBV_RenameInstance($id, $InstanceName);'],
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Calculation active'],
                ['type' => 'Select', 'name' => 'Function', 'caption' => 'Function (for the dashboard)', 'options' => $funcOptions],
                // Standort: reines Freitext-Label (Raum/Geschoss …), bewusst
                // getrennt vom Dashboard-Vertrag "Function". Auswahlliste aus
                // den Werten, die irgendeine Instanz schon benutzt — plus
                // jederzeit frei eintragbar für den Einzelfall, der in keine
                // Liste passt (Dietmars Auftrag: "auch die letzten
                // Absurditäten noch bezeichnen können").
                ['type' => 'Select', 'name' => 'LocationPreset', 'caption' => 'Location (apply a suggestion …)', 'options' => $locationOptions, 'value' => '', 'onChange' => 'MHUBV_ApplyLocationPreset($id, $LocationPreset);'],
                ['type' => 'ValidationTextBox', 'name' => 'Location', 'caption' => '🆕 Location (room/floor, free text)'],
            ];

        // Fachpanels in Standardreihenfolge — Reihenfolge und Aufklapp-Zustand wählt der Nutzer im Panel „Formular: Aufbau“.
        $free = [
                'meters' => [
                    'type' => 'ExpansionPanel', 'caption' => '🔌  Meters', 'expanded' => true,
                    'items' => $meterItems,
                ],
                'check' => ['type' => 'ExpansionPanel', 'caption' => '🔎  Check & preview', 'expanded' => true, 'items' => $check],
                'calc' => [
                    'type' => 'ExpansionPanel', 'caption' => '⏱️  Calculation', 'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Recalculate every', 'minimum' => 2, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                'archPower' => [
                    // Zwei eigene Panels statt einem gemeinsamen (Dietmars
                    // Rückmeldung 31.08.2026: Symcons Formularsprache kennt
                    // kein horizontales Nebeneinander — schon bei den
                    // PopupButtons geprüft. Zwei einzeln auf-/zuklappbare
                    // Panels halten wenigstens die sichtbare Feldzahl klein.
                    'type' => 'ExpansionPanel', 'caption' => '🗄️  Archive compaction: power', 'expanded' => false,
                    'items' => array_merge(
                        [['type' => 'Label', 'caption' => 'Automatically reduces the level of detail of older archive values (saves memory), set anew on every "Apply" — only for variables that are archived (you switch archiving on yourself, see the notice at the top of the form). Each stage only takes effect if its resolution is actually coarser than the update interval above — otherwise there would be nothing to compact.']],
                        $this->CompactionFields('Power')
                    ),
                ],
                'archEnergy' => [
                    'type' => 'ExpansionPanel', 'caption' => '🗄️  Archive compaction: energy (import/feed-in)', 'expanded' => false,
                    'items' => array_merge(
                        [['type' => 'Label', 'caption' => 'Can be set independently of power — energy meter readings often have different retention requirements.']],
                        $this->CompactionFields('Energy')
                    ),
                ],
            ];
        if ($freeOnly) {
            return $free;
        }
        $lay = $this->ApplyFormLayout($free);

        $form = [
            'elements' => array_values(array_filter(array_merge($top, $lay['panels'],
                [$this->LayoutPanel($lay['layout'], $free), $this->ForumHint(), $this->LicenseHint()]))),
            'actions' => [
                ['type' => 'Button', 'caption' => 'Recalculate now', 'onClick' => 'echo MHUBV_RecalcAndRefreshForm($id);'],
                ['type' => 'Button', 'caption' => '🔄  Force apply (without a form change)', 'onClick' => MHUBV_Text::t('IPS_ApplyChanges($id); echo \'✅ ApplyChanges() executed.\';'), 'confirm' => MHUBV_Text::t('Apply the instance now (ApplyChanges)?')],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Calculation active.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Calculation deactivated.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Formula incomplete or contradictory — see the check.'],
                ['code' => 202, 'icon' => 'error',    'caption' => 'Migration required — open the form and confirm in the panel at the top.'],
            ],
        ];
        return $form;
    }

    // -----------------------------------------------------------------------
    // Formular: Aufbau (SUITE.md 3a) — Aufklapp-Zustand, Reihenfolge und
    // Ausblenden der Fachpanels wählbar; Referenz Batteriewächter.
    // -----------------------------------------------------------------------

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout“.
     * Unbekannte oder fehlende Einträge fallen auf die Standardreihenfolge und „Standard“ zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], in der anzuwendenden Reihenfolge
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Kontrollkästchen; Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keines = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    /** Name eines Fachpanels für die Liste im Aufbau-Panel (ohne Klammerzusatz). */
    private function layoutPanelName(array $panel, string $key): string
    {
        // Der Name steht als Wert in einer Liste (nicht als Beschriftung) — die Konsole übersetzt ihn nicht, deshalb hier.
        return trim((string)preg_replace('/\s*\(.*$/u', '', MHUBV_Text::t((string)($panel['caption'] ?? $key))));
    }

    /**
     * Wendet den gewählten Aufbau auf die Fachpanels an.
     *
     * @param array $free key => Panel, in Standardreihenfolge
     * @return array ['layout' => formLayout(), 'panels' => Panels in gewählter Reihenfolge]
     */
    private function ApplyFormLayout(array $free): array
    {
        $layout = $this->formLayout(array_keys($free));
        $panels = [];
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten
                $panel['visible'] = false;
            }
            $panels[] = $panel;
        }
        return ['layout' => $layout, 'panels' => $panels];
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    private function LayoutPanel(array $layout, array $free): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = $this->layoutPanelName($free[$key], $key);
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => MHUBV_Text::t('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => MHUBV_Text::t('🪟  Form: layout%s', $hidden ? MHUBV_Text::t(' (%s hidden)', count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing by itself, so this setting applies every time the form opens. It takes effect after “Apply” the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No tick means "default": as the module intends. The position is a number; with the same number the default order stays. "Hidden" takes a section out of the display without it losing anything: its settings are kept and keep working (meant for sections you rarely need). To show it again, remove the tick at "Hidden" or click "Reset to default". If several ticks are set, "Hidden" wins over "Collapsed" over "Expanded". The sections at the top (purpose, news, documentation) and at the bottom (feedback, About this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Areas', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Area', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo MHUBV_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach ($this->BuildForm(true) as $key => $panel) {
            $rows[] = ['Key' => $key, 'Panel' => $this->layoutPanelName($panel, $key), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return MHUBV_Text::t('ℹ️ Reset to default. Click "Apply" below to save.');
    }

    /** Formel je Feld als Klartext (Vorschau im Formular). */
    /** Zahl im Format der jeweiligen NRG-Stack-Profile (0 bzw. 1 Nachkommastelle, deutsches Komma). */
    private function FormatValue(float $v, string $field): string
    {
        return MHUBV_Text::num($v, $field === 'power' ? 0 : 1);
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
        foreach ([['power', 'Power'], ['imp', 'Import'], ['exp', 'Feed-in']] as [$f, $lbl]) {
            $lbl   = MHUBV_Text::t($lbl);
            $terms = [];
            $sum   = 0.0;
            foreach ($nodes as $n) {
                $vid = $n[$f];
                if ($vid <= 0) {
                    continue;
                }
                $name   = $n['name'] !== '' ? $n['name'] : MHUBV_Text::t('(unnamed)');
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
                    $valText = MHUBV_Text::t('Value missing');
                }
                $pctText = $isFull ? '' : ' × ' . rtrim(rtrim(MHUBV_Text::num(abs($factor), 2), '0'), MHUBV_Text::t('<decimal separator>') === ',' ? ',' : '.') . ' %';
                // Quelle je Term (SUITE.md „Verbund-Verbindungen im Formular sichtbar machen", 21.09.2026):
                // welche Variable tatsächlich gelesen wird — bei automatisch am Ziel gefundenen Datenpunkten
                // sonst nicht erkennbar.
                $src = ' ' . (IPS_VariableExists($vid) ? MHUBV_Text::t('[source #%d "%s"]', $vid, IPS_GetName($vid)) : MHUBV_Text::t('[source #%d missing]', $vid));
                $terms[] = $sign . ' ' . $name . $src . $pctText . ' (' . $valText . ')';
            }
            if ($terms) {
                $expr = implode('  ', $terms);
                // Führendes „+ “ weglassen, liest sich als Summe natürlicher.
                $expr = preg_replace('/^\+ /', '', $expr);
                $lines[] = '   ' . $lbl . ' = ' . $expr . '  =  ' . $this->FormatValue($sum, $f) . ' ' . $units[$f];
            }
        }
        if (!$lines) {
            $lines[] = MHUBV_Text::t('   (no term yields an output)');
        }
        return $lines;
    }
}
