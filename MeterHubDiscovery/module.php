<?php

// ---------------------------------------------------------------------------
// MeterHubDiscovery — Configurator-Modul: durchsucht einen IP-Bereich nach
// Energiezählern auf Modbus-TCP-Port 502, erkennt den Zählertyp anhand eines
// charakteristischen Registers (Frequenz + Spannung als Plausibilitätsprüfung)
// und legt auf Klick eine MeterHub-Instanz mit vorausgefüllten Werten an.
// Eigenständige, kompakte Modbus-Hilfsfunktionen (kein Zugriff auf die Klassen
// aus dem MeterHub-Modulordner — Module sind bewusst getrennt).
// ---------------------------------------------------------------------------

class MeterHubDiscovery extends IPSModule
{
    private const METERHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const MIGRATIONSHUB_GUID = '{330717BB-E309-41A2-90A8-FDA3179ED948}';

    // Kandidaten je Signatur: typische/dokumentierte Standard-Unit-IDs
    // (kleine Liste statt vollem 1-247-Bereich). Die Janitza-Modelle mit
    // klassischer Registerkarte teilen sich eine Signatur — Discovery kann sie
    // nicht auseinanderhalten und schlägt stellvertretend den UMG 604 vor
    // (identische Map; der Typ lässt sich in der Instanz umstellen). Der
    // UMG 800 hat eine eigene Signatur (Frequenz auf 19054 statt 19050).
    private const METER_UNIT_IDS = [
        'siemens_pac2200' => [1, 247, 126],
        'janitza_umg604'  => [1],
        'janitza_umg800'  => [1],
        // Native-TCP-Zähler mit charakteristischer Signatur. RTU-Gateway-Zähler
        // (Socomec, MBS) mit frei wählbarer Unit-ID werden bewusst NICHT
        // gescannt — dort bitte die Instanz manuell anlegen.
        'shelly_pro3em'    => [1],
        'carlo_gavazzi_em' => [1],
        'whatwatt'         => [1],
        'phoenix_eem375'   => [255, 1],
        'eastron_sdm72d'   => [1],
        'goe_controller'   => [1],
    ];

    private const METER_LABELS = [
        'siemens_pac2200' => 'Siemens PAC2200',
        'janitza_umg604'  => 'Janitza UMG (klassische Map)',
        'janitza_umg800'  => 'Janitza UMG 800',
        'shelly_pro3em'    => 'Shelly Pro 3EM',
        'carlo_gavazzi_em' => 'Carlo Gavazzi EM24/ET340',
        'whatwatt'         => 'WhatWatt',
        'phoenix_eem375'   => 'Phoenix EEM-EM375',
        'eastron_sdm72d'   => 'Eastron SDM72D/SDM630',
        'goe_controller'   => 'go-e Controller',
    ];

    // Gerätetyp-Register 40000 der blue'Log-SCADA-Schnittstelle → MeterHub-
    // Zählertyp. Nur die von MeterHub bereits unterstützten Typen (siehe
    // MHUB_BlueLogScadaInverterDriver::DEVICE_TYPE_ENUM in MeterHub/module.php
    // für alle zehn vom Hersteller definierten Typen) — ein Sensor/Tracker/
    // Genset/Batterie/Kraftwerksregler an dieser Adresse wird gefunden, aber
    // bewusst NICHT vorgeschlagen (kein passender Treiber vorhanden).
    // 0 = das blue'Log selbst, gilt NUR unter Adresse 97 (Dietmars Vorgabe
    // 11.09.2026: „Die 97 muss definitiv erscheinen") — siehe
    // identifyBlueLogDevicesBatch().
    private const BLUELOG_METER_MAP = [
        0 => 'bluelog_scada_logger',
        1 => 'bluelog_scada_inverter',
        3 => 'bluelog_scada_meter',
    ];
    private const BLUELOG_TYPE_LABELS = [
        0 => "Datenlogger – Summe aller Wechselrichter",
        1 => 'Wechselrichter',
        3 => 'Zähler',
    ];
    // SCADA-Adresse des blue'Log selbst (fest, laut Hersteller reserviert).
    private const BLUELOG_SELF_ADDR = 97;
    // Kurze Verschnaufpause zwischen zwei GANZEN parallelen Anfragerunden
    // zur selben blue'Log-IP (nicht mehr zwischen einzelnen Verbindungen —
    // siehe FÜNFTE KORREKTUR bei `identifyBlueLogHost()`: viele
    // GLEICHZEITIG offene Verbindungen sind für ein blue'Log unproblematisch
    // (live verifiziert: 8 von 8 in 0,07 s), nur schnelles AUFEINANDER-
    // FOLGENDES Verbinden/Trennen scheitert durchgängig. Frühere Fassung
    // dieses Konstanten-Kommentars ging noch von "Pause zwischen jeder
    // einzelnen Verbindung" aus — das hat das eigentliche Problem eher
    // verschärft, siehe `probeManyParallel()`.
    private const BLUELOG_CONN_PACING_US = 300000;

    // Wie viele SCADA-Adressen (Geräte-IDs) je Bündel GLEICHZEITIG geprüft
    // werden — siehe `identifyBlueLogDevicesBatch()`. Klein genug, um nicht
    // an Datei-Deskriptor-/Socket-Limits zu stoßen, groß genug, um einen
    // vollen 100er-Adressbereich in wenigen Bündeln statt 100 Einzel-
    // verbindungen mit Pause abzuarbeiten.
    private const BLUELOG_SCAN_BATCH_SIZE = 20;

    public function Create()
    {
        parent::Create();

        $prefix = $this->guessLocalSubnetPrefix();
        $this->RegisterPropertyString('RangeStart', $prefix !== '' ? $prefix . '.1'   : '');
        $this->RegisterPropertyString('RangeEnd',   $prefix !== '' ? $prefix . '.254' : '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyString('NameTemplate', '');
        $this->RegisterPropertyString('IgnoreIPs', '');
        // Zweiter Suchmodus (Dietmars Auftrag „universeller blue'Log-Treiber",
        // Teil 2, 07.09.2026): EIN fest bekannter blue'Log (Solarpark-
        // Datenlogger), aber viele dahinter angeschlossene Geräte, jedes über
        // seine eigene, am blue'Log frei vergebene SCADA-Adresse (= Unit-ID)
        // erreichbar — daher ein Adressbereich statt eines IP-Bereichs. Siehe
        // MHUB_BlueLogScadaInverterDriver in MeterHub/module.php für die volle
        // Herleitung der SCADA-Adresse.
        $this->RegisterPropertyString('BlueLogHost', '');
        $this->RegisterPropertyInteger('BlueLogPort', 502);
        $this->RegisterPropertyInteger('ScadaAddrStart', 100);
        $this->RegisterPropertyInteger('ScadaAddrEnd', 199);
        $this->RegisterAttributeString('ResultsJSON', '[]');
        // Für die Status-Kopfzeile (siehe ScanSummaryLine()) — Verbund-Konvention
        // „Einheitliche Verbund-Status-Kopfzeile" (SUITE.md, 20.08.2026).
        $this->RegisterAttributeInteger('LastScanTs', 0);
        // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik") — Forum-
        // Hinweis + Versionsnummer im Doku-Panel + News-Panel nachgezogen
        // 01.09.2026 im Zuge von Dietmars Manifest-Abgleich-Auftrag, Muster 1:1
        // aus MeterHub/MeterHubVirtual übernommen. Ursprünglich ohne News-Panel
        // begonnen ("nichts Neues anzukündigen") — Dietmars Rückmeldung: das
        // Modul hatte NIE eines, die längst vorhandenen Fähigkeiten (Migration,
        // Abbruch, Status-Kopfzeile, immer mehr erkannte Zählertypen) waren also
        // nie zusammengefasst. Der Inhalt fasst deshalb den aktuellen Stand
        // zusammen statt nur diese eine Version.
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        // Zweck-Einführung (Dietmars Auftrag 01.09.2026, ausgelöst durch
        // Sepps Rückmeldung — siehe MeterHubVirtual::PurposeIntro() für die
        // volle Herleitung). Einmalig dismissible, nicht pro Version.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
    }

    private const NEWS_VERSION = '0.24.47';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/PLATZHALTER-meterhub-thread-folgt/00000';
    private const LICENSE_URL = 'https://github.com/DG65/NRGMeterHub/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    /** Siehe MeterHubVirtual::PurposeIntro() für die volle Herleitung — steht ganz vorn, noch vor dem News-Panel. */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Durchsucht dein lokales Netz nach unterstützten Energiezählern und legt auf Klick eine passend vorausgefüllte MeterHub-Instanz an — IP-Adresse, Unit-ID und Zählertyp müssen nicht von Hand eingetragen werden.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: bei mehreren Zählern (z. B. je Verbraucher oder je Wohnung) ein paar Klicks statt manueller Konfiguration jeder einzelnen Instanz. Auch der Umstieg von einem anderen Zähler-/Hub-Modul mit Übernahme der Messhistorie läuft hier mit (siehe unten).'],
                ['type' => 'Label', 'caption' => 'Das Modul selbst misst nichts — die eigentliche Datenerfassung übernimmt danach die neu angelegte MeterHub-Instanz.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MHUBD_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    /** Aufgeklappt und pro Version einmalig bestätigbar — Formular-Konvention, siehe MeterHub::NewsBanner(). */
    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in dieser Version',
            'items' => [
                ['type' => 'Label', 'caption' => '• 🔧 Kernfix: blue\'Log-Erkennung fragte bisher eine Schnittstelle nach der anderen ab, mit Pause dazwischen — genau das lässt ein blue\'Log offenbar scheitern (live gemessen: 8 rasch AUFEINANDERFOLGENDE Verbindungen scheitern komplett, 8 GLEICHZEITIGE werden dagegen anstandslos beantwortet). Alle Kandidaten — SCADA-Selbstauskunft, zwei Geräte-IDs aus dem konfigurierten Bereich, Power Control, RPC — werden jetzt in einem Rutsch parallel angefragt. Dasselbe gilt für den SCADA-Adressbereich-Suchlauf: bis zu 20 Adressen gleichzeitig statt einzeln mit Pause, dadurch spürbar schneller.'],
                ['type' => 'Label', 'caption' => '• 🔧 Fix: automatische blue\'Log-Erkennung verwendete für SCADA/Power Control/RPC immer denselben Port wie die klassische Zählersuche (Fund aus dem Praxistest: Gesucht wurde standardmäßig auf Port 502, SCADA und Power Control/RPC liegen aber oft auf anderen Ports). Läuft ein blue\'Log auf einem abweichenden Port, probiert „🔎 Netzwerk durchsuchen" jetzt zusätzlich den im Panel „blue\'Log SCADA-Adressbereich" eingetragenen Port — beide Panels erklären das jetzt auch im Formulartext.'],
                ['type' => 'Label', 'caption' => '• 🔧 Fix: blue\'Log-Erkennung fand in der Praxis KEINEN einzigen blue\'Log (Praxistest: 15 echte Geräte im Netz, null Treffer). Ursache laut Live-Messung: mehrere rasch aufeinanderfolgende Modbus-TCP-Verbindungen zum selben Gerät scheitern reihenweise, besonders bei parallel laufenden eigenen Regel-Skripten — jede Schnittstellen-Prüfung bekommt jetzt bis zu drei Versuche mit wachsender Pause. Reihenfolge zusätzlich an die reale Geräteflotte angepasst: SCADA zuerst (meist vorhanden), Power Control/RPC nur noch als Rückfall für Master-/EZA-Regler.'],
                ['type' => 'Label', 'caption' => '• 🆕 „🔎 Netzwerk durchsuchen" erkennt Meteocontrol-blue\'Log-Datenlogger jetzt von sich aus — keine vorher bekannte IP mehr nötig. Bei einem Treffer wird automatisch der eingestellte SCADA-Adressbereich dahinter mitdurchsucht, dieselbe Fundliste wie bei klassischen Zählern.'],
                ['type' => 'Label', 'caption' => '• 🆕 Neuer zweiter Suchmodus „blue\'Log SCADA-Adressbereich": statt eines IP-Bereichs EIN fest bekannter Meteocontrol-blue\'Log-Solarpark-Datenlogger, aber viele dahinter angeschlossene Geräte (Wechselrichter, Zähler …) über ihre eigene, am blue\'Log selbst frei vergebene SCADA-Adresse. Findet und schlägt jedes unterstützte Gerät als eigene MeterHub-Instanz vor — dieselbe Fundliste/„Erstellen"-Mechanik wie beim normalen Netzwerk-Suchlauf. Bleibt der schnellere, gezielte Weg, wenn die IP schon bekannt ist.'],
                ['type' => 'Label', 'caption' => '• 🆕 Neuer Platzhalter `{busaddr}` (RS485-Busadresse) für die „Namens-Vorlage" — nützlich, um viele gleichartige blue\'Log-Funde (z. B. 100 Wechselrichter) nach einem eigenen Muster statt einer reinen laufenden Nummer zu benennen.'],
                ['type' => 'Label', 'caption' => '• 🆕 „Bekannten Host übernehmen" schlägt die blue\'Log-IP aus bereits bestehenden MeterHub-Instanzen vor — kein Umweg mehr über das Meteocontrol-VCOM-Portal, wenn die IP schon einmal verwendet wurde.'],
                ['type' => 'Label', 'caption' => '• 🆕 „Schnittstellen prüfen" testet vorab, ob Power Control/RPC/SCADA an einer blue\'Log-IP überhaupt antworten — eine SCADA-Lizenz ist teuer und in der Praxis selten, die Standardlizenz hat weder sie noch RPC.'],
                ['type' => 'Label', 'caption' => '• Fundliste im „Erstellen"-Panel ist jetzt breiter/höher, die Namens-Vorlage sitzt dort statt im Suchbereich-Panel, und die blue\'Log-Suche ist bei dünn besetzten Adressbereichen spürbar schneller.'],
                ['type' => 'Label', 'caption' => '• 🔧 Fix: Die Modellbezeichnung bei blue\'Log-SCADA-Funden war fehlerhaft dekodiert (zeigte Zeichensalat statt eines lesbaren Namens) — jetzt an mehreren echten Geräten geprüft.'],
                ['type' => 'Label', 'caption' => '• 🔀 Migration von einer Alt-Instanz (anderes Modul, gleiche IP/Unit-ID): „Migration vorbereiten" verknüpft automatisch mit MigrationsHub — Simulieren/Ausführen bleiben dort bewusst manuelle Schritte. Details über das „?" beim Knopf.'],
                ['type' => 'Label', 'caption' => '• Die Suche lässt sich jederzeit abbrechen („✖ Suche abbrechen"), die Kopfzeile zeigt live, wie viele Zähler bereits gefunden wurden.'],
                ['type' => 'Label', 'caption' => '• Erkennt inzwischen neun Zählertypen: Siemens PAC2200, Janitza UMG (klassisch + UMG 800), Shelly Pro 3EM, Carlo Gavazzi EM24/ET340, WhatWatt, Phoenix EEM-EM375, Eastron SDM72D/SDM630, go-e Controller. Details über das „?" beim Suche-Knopf.'],
                ['type' => 'Label', 'caption' => '• IPs lassen sich gezielt von der Suche ausschließen (Feld „IPs ignorieren").'],
                ['type' => 'Label', 'caption' => '• 🧡 Neues Panel „Über dieses Modul" ganz unten — Lizenz (PolyForm Noncommercial 1.0.0), Kontakt für gewerbliche Nutzung und ein PayPal-Link für alle, die etwas dalassen möchten.'],
                ['type' => 'Label', 'caption' => '• 👋 Neue Zweck-Einführung „Wozu dieses Modul?" ganz oben im Formular — kurz und knapp, wofür MeterHubDiscovery gedacht ist, bevor es an die Bedienung geht.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MHUBD_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /** Symcon-Forum-Hinweis — einmalig dismissible, kein Versionsbezug, siehe MeterHubVirtual::ForumHint(). */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'MeterHubDiscovery ist Beta — Rückmeldungen, gerade zu neu erkannten Zählertypen, sind ausdrücklich willkommen im Community-Thread.'],
                ['type' => 'Label', 'caption' => '⚠️ Platzhalter-Link, Thread noch nicht veröffentlicht.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MHUBD_AckForumHint($id);'],
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
     * Kopfzeile fürs Suchbereich-Panel nach der Verbund-Konvention „Einheitliche
     * Verbund-Status-Kopfzeile" (SUITE.md, 20.08.2026, Referenz: EMS'
     * getDiscoverySummaryLine()). Direkt unter dem Suche-Button: eine Zeile,
     * Icon + Kernzahl + Zeitstempel statt technischem Fließtext.
     */
    private function ScanSummaryLine(): string
    {
        $ts = $this->ReadAttributeInteger('LastScanTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht — Button oben drücken.';
        }
        $count = count(json_decode((string)$this->ReadAttributeString('ResultsJSON'), true) ?: []);
        $icon  = $count > 0 ? '✅' : '⚠️';
        return sprintf('%s %d Zähler gefunden (zuletzt %s Uhr).', $icon, $count, date('H:i:s', $ts));
    }

    // Ermittelt heuristisch die ersten drei Oktette des lokalen Subnetzes
    // (z. B. „192.168.1"), um Start-/End-IP sinnvoll vorzubelegen.
    private function guessLocalSubnetPrefix()
    {
        $ip = @gethostbyname(gethostname());
        if ($ip === false || $ip === gethostname()) {
            return '';
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        $isPrivate = ($parts[0] === '10')
            || ($parts[0] === '192' && $parts[1] === '168')
            || ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31);
        if (!$isPrivate) {
            return '';
        }
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Versteckte Abbruch-Flagge für laufende Scans (thread-sicher über
        // GetValue/SetValue — der „Abbrechen"-Button läuft in einem eigenen
        // Thread und setzt sie, die Scan-Schleifen prüfen sie). Nur bei echter
        // Neuanlage registrieren (Verbund-Erkenntnis, SUITE.md „IP-Symcon-
        // Stolpersteine" Punkt 3): RegisterVariableXXX() bedingungslos bei
        // JEDEM ApplyChanges() für eine bereits bestehende Variable erneut
        // aufzurufen kollidiert mit der Ident-Eindeutigkeit und lässt die
        // ganze Transaktion abbrechen. Bestehende Instanzen bekommen die
        // Variable trotzdem einmalig nachgezogen, da IPS_GetObjectIDByIdent()
        // erst nach der ersten erfolgreichen Anlage etwas findet.
        if (!@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->RegisterVariableBoolean('ScanAbort', 'Scan-Abbruch', '', 100);
            IPS_SetHidden($this->GetIDForIdent('ScanAbort'), true);
        }
    }

    // true, wenn während eines laufenden Scans „Abbrechen" geklickt wurde.
    private function scanAborted(): bool
    {
        return @$this->GetValue('ScanAbort') === true;
    }

    public function AbortScan()
    {
        if (@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->SetValue('ScanAbort', true);
        }
        @$this->UpdateFormField('ScanProgress', 'caption', 'Abbruch angefordert – bitte kurz warten …');
        @$this->UpdateFormField('ScanProgress', 'indeterminate', true);
    }

    /** Übernimmt einen gewählten bekannten Host in das Freitext-Feld "BlueLogHost". */
    public function ApplyBlueLogHostPreset(string $preset)
    {
        if (trim($preset) !== '') {
            $this->UpdateFormField('BlueLogHost', 'value', $preset);
        }
    }

    /**
     * Prüft, welche der drei blue'Log-Sollwert-/SCADA-Rollen an dieser IP
     * überhaupt antworten — Dietmars berechtigter Einwand 08.09.2026: eine
     * SCADA-Lizenz ist teuer und selten, die Standard-Lizenz hat weder sie
     * noch RPC. Statt blind einen SCADA-Adressbereich zu konfigurieren, kann
     * hier vorab geprüft werden, was überhaupt lizenziert/erreichbar ist.
     * Reine Existenzprüfung (irgendeine Antwort, auch eine Modbus-Exception,
     * zählt als "Unit vorhanden") — nicht, ob die gelesenen Werte plausibel
     * sind (das leistet der eigentliche Suchlauf/Treiber danach).
     */
    public function CheckBlueLogLicenses(string $host, int $port): string
    {
        $host = trim($host);
        if ($host === '') {
            return "❌ Bitte zuerst die blue'Log-IP-Adresse eintragen.";
        }
        // Kurz genug, damit jede Zeile im nativen Meldungsdialog ohne Umbruch
        // passt (Dietmars Screenshot 08.09.2026: "RPC" statt ausgeschriebenem
        // "Remote Power Control / RPC" reicht — die Klammer erklärt die Rolle).
        $roles = [
            1  => 'Power Control (Netzbetreiber)',
            10 => 'RPC (Direktvermarkter)',
            97 => 'SCADA (alle Geräte)',
        ];
        $parts = [];
        foreach ($roles as $unitId => $label) {
            $ok = $this->probeUnitResponds($host, $port, $unitId, 1.0);
            // ❌ statt "—" — eindeutiges Gegenteil zu ✅ (Dietmars Einwand:
            // "—" liest sich nicht klar als "nicht erreichbar").
            $parts[] = ($ok ? '✅ ' : '❌ ') . "$label (Slave-ID $unitId)";
        }
        // Eine Zeile je Rolle statt mit "·" aneinandergereiht — der native
        // Meldungsdialog (Dietmars Screenshot 08.09.2026) macht aus einem
        // langen, umbrechenden Fließtext sonst eine schwer lesbare Wand.
        return implode("\n", $parts);
    }

    // Reine Existenzprüfung: Antwortet IRGENDETWAS auf dieser Unit-ID (auch
    // eine Modbus-Exception zählt — die Adresse ist dann trotzdem belegt),
    // im Unterschied zu modbusRead()/readHolding(), die eine Exception wie
    // "keine Antwort" behandeln (für die eigentlichen Lesezugriffe richtig,
    // für eine reine Vorhanden-Prüfung nicht).
    private function probeUnitResponds($host, $port, $unitId, $timeout): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, $timeout);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x03, 0, 1);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unitId);
        fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 8) {
                break; // MBAP(7) + Funktionscode(1) reicht zur Existenzprüfung
            }
        }
        fclose($sock);
        return strlen($response) >= 8;
    }

    public function GetConfigurationForm()
    {
        $results = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        if (!is_array($results)) {
            $results = [];
        }

        $existing = $this->findExistingInstances();
        $template = trim($this->ReadPropertyString('NameTemplate'));

        // Vorschlagsliste für "blue'Log-IP-Adresse": aus bereits bestehenden
        // MeterHub-Instanzen (Dietmars Einwand 08.09.2026 — die IP eines
        // blue'Log erst über das Meteocontrol-VCOM-Portal/VPN suchen zu
        // müssen, obwohl sie oft schon in einer eigenen Instanz steht).
        // Gleiches Muster wie MeterHub::GetConfigurationForm() "Standort".
        $blueLogHostOptions = [['caption' => '— bekannten Host wählen —', 'value' => '']];
        $seenHosts = [];
        foreach (IPS_GetInstanceListByModuleID(self::METERHUB_GUID) as $iid) {
            $h = trim((string)@IPS_GetProperty($iid, 'Host'));
            if ($h !== '' && !isset($seenHosts[$h])) {
                $seenHosts[$h] = true;
            }
        }
        $sortedHosts = array_keys($seenHosts);
        sort($sortedHosts, SORT_NATURAL);
        foreach ($sortedHosts as $h) {
            $blueLogHostOptions[] = ['caption' => $h, 'value' => $h];
        }

        $meterCounter = [];
        $values = [];
        foreach ($results as $r) {
            $key = $r['ip'] . '|' . $r['unitId'];
            $meterCounter[$r['meter']] = ($meterCounter[$r['meter']] ?? 0) + 1;
            $nr = $meterCounter[$r['meter']];

            if ($template !== '') {
                // {busaddr} nur bei Funden der blue'Log-SCADA-Suche gefüllt
                // (RS485-Busadresse, Register 40113) — beim normalen IP-Suchlauf
                // leer, damit ein Muster mit {busaddr} dort nicht fehlschlägt.
                $instanceName = str_replace(
                    ['{zaehler}', '{ip}', '{unitid}', '{nr}', '{busaddr}'],
                    [$r['label'], $r['ip'], $r['unitId'], $nr, $r['busAddr'] ?? ''],
                    $template
                );
            } else {
                $instanceName = $r['label'] . ' ' . $nr;
            }

            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId'], (int)($existing[$key] ?? 0));
            // Port kommt aus dem Fund selbst (dort, wo die Adresse tatsächlich
            // ansprach), nicht aus einer Property — sonst würde z. B. ein
            // blue'Log, der beim NORMALEN Netzwerk-Suchlauf mitgefunden wurde
            // (dessen Port aus der Property "Port" stammt), fälschlich mit
            // "BlueLogPort" angelegt (Fund 08.09.2026: zwei verschiedene
            // Such-Einstiege können denselben Fund liefern).
            $config = [
                'Host'   => $r['ip'],
                'Port'   => $r['port'] ?? $this->ReadPropertyInteger('Port'),
                'UnitId' => $r['unitId'],
                'Meter'  => $r['meter'],
            ];
            if ($legacy['id'] > 0) {
                // Kommunikation bleibt aus, bis die Migration abgeschlossen ist
                // — sonst überlappt sich neu geloggte mit übertragener
                // Alt-Historie (siehe Doku-Panel-Hinweis oben).
                $config['Active'] = false;
            }

            $values[] = [
                'name'       => $r['label'] . ' @ ' . $r['ip'] . ' (Unit ' . $r['unitId'] . ')',
                'meter'      => $r['label'],
                'ip'         => $r['ip'],
                'unitId'     => $r['unitId'],
                'legacy'     => $legacy['id'] > 0 ? ('⚠️ ' . $legacy['name'] . ' (#' . $legacy['id'] . ')') : '',
                'instanceID' => $existing[$key] ?? 0,
                'create'     => [
                    'moduleID'      => self::METERHUB_GUID,
                    'name'          => $instanceName,
                    'configuration' => $config,
                ],
            ];
        }

        $form = [
            'elements' => array_values(array_filter(array_merge([
                $this->PurposeIntro(),
                $this->NewsBanner(),
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'MeterHubDiscovery ' . self::NEWS_VERSION . ' — Stand dieser Anleitung.'],
                        ['type' => 'Label', 'caption' => 'Durchsucht einen IP-Bereich im lokalen Netz nach Energiezählern auf Modbus-TCP-Port 502 und erkennt den Zählertyp anhand eines charakteristischen Registers (Frequenz + Spannung als Plausibilitätsprüfung).'],
                        ['type' => 'Label', 'caption' => 'Start- und End-IP eintragen (Vorschlag anhand des eigenen Netzwerks ist schon ausgefüllt), dann „Netzwerk durchsuchen" klicken. Gefundene Zähler erscheinen unten — Klick auf „Erstellen" legt eine MeterHub-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Zählertyp an.'],
                        ['type' => 'Label', 'caption' => '🔀 Neue Instanz kommt mit „Kommunikation aktiv" bereits eingeschaltet. Falls ein Umstieg von einem anderen Zähler-/Hub-Modul mit Übernahme der Messhistorie geplant ist: direkt nach dem Anlegen an der neuen MeterHub-Instanz wieder ausschalten, bis MigrationsHub die alte Historie übernommen hat — sonst überlappen sich die neu geloggten Werte mit der übertragenen Alt-Historie.'],
                        ['type' => 'Label', 'caption' => 'Die Suche prüft nur wenige dokumentierte Standard-Unit-IDs je Zähler, keinen vollen 1-247-Bereich — bei exotisch konfigurierter Unit-ID bitte die MeterHub-Instanz manuell anlegen.'],
                        ['type' => 'Label', 'caption' => 'Erkannt werden: Siemens PAC2200, Janitza UMG (klassisch + UMG 800), Shelly Pro 3EM, Carlo Gavazzi EM24/ET340, WhatWatt, Phoenix EEM-EM375 und Eastron SDM72D/SDM630. Beim Shelly Pro 3EM muss Modbus TCP am Gerät aktiviert sein. Zähler hinter RTU/TCP-Gateways mit frei wählbarer Unit-ID (z. B. Socomec, MBS) werden nicht automatisch gefunden — dort die Instanz manuell anlegen.'],
                        ['type' => 'Label', 'caption' => '🆕 Ein Meteocontrol blue\'Log-Datenlogger wird von „🔎 Netzwerk durchsuchen" jetzt von sich aus erkannt — keine vorher bekannte IP mehr nötig. Bei einem Treffer wird direkt der SCADA-Adressbereich dahinter mitdurchsucht (Einstellung im Panel „blue\'Log SCADA-Adressbereich" unten). Das eigene Panel dort bleibt der schnellere, gezielte Weg, wenn die IP schon bekannt ist.'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔎  Suchbereich',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'RangeStart', 'caption' => 'Start-IP', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'ValidationTextBox', 'name' => 'RangeEnd',   'caption' => 'End-IP',   'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Modbus-TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'Label', 'caption' => '💡 Gilt für klassische Zähler UND als erster Versuch für blue\'Log SCADA/Power Control/RPC. Läuft ein blue\'Log auf einem ANDEREN Port als die übrigen Zähler, dort den abweichenden Port im Feld „Modbus-TCP-Port" im Panel „blue\'Log SCADA-Adressbereich" unten eintragen — die Suche probiert dann automatisch beide Ports.'],
                        ['type' => 'ValidationTextBox', 'name' => 'IgnoreIPs', 'caption' => 'IPs ignorieren (Komma-getrennt)'],
                        ['type' => 'Label', 'caption' => 'Diese Adressen werden bei der Suche komplett übersprungen — z. B. andere Modbus-Geräte, die sonst fälschlich erscheinen würden.'],
                        [
                            'type' => 'PopupButton', 'caption' => 'Welche Zähler findet die Suche — und welche nicht?', 'width' => '480px',
                            'popup' => [
                                'caption' => 'Was die Suche erkennt',
                                'items' => [
                                    ['type' => 'Label', 'caption' => 'Erkannt werden: Siemens PAC2200, Janitza UMG (klassisch + UMG 800), Shelly Pro 3EM, Carlo Gavazzi EM24/ET340, WhatWatt, Phoenix EEM-EM375, Eastron SDM72D/SDM630 und der go-e Controller.'],
                                    ['type' => 'Label', 'caption' => '🆕 Meteocontrol blue\'Log-Datenlogger werden zusätzlich automatisch erkannt (unabhängig vom Zähler-Suchlauf oben) — bei einem Treffer wird gleich der eingestellte SCADA-Adressbereich dahinter mitdurchsucht, ganz ohne die IP vorher zu kennen.'],
                                    ['type' => 'Label', 'caption' => 'Die Suche prüft nur wenige dokumentierte Standard-Unit-IDs je Zähler, keinen vollen 1-247-Bereich — bei exotisch konfigurierter Unit-ID bitte die MeterHub-Instanz manuell anlegen.'],
                                    ['type' => 'Label', 'caption' => 'Zähler hinter RTU/TCP-Gateways mit frei wählbarer Unit-ID (z. B. Socomec, MBS) werden nicht automatisch gefunden — dort die Instanz ebenfalls manuell anlegen.'],
                                    ['type' => 'Label', 'caption' => 'Beim Shelly Pro 3EM muss Modbus TCP am Gerät erst aktiviert werden, sonst antwortet Port 502 gar nicht.'],
                                ],
                            ],
                        ],
                        ['type' => 'Button', 'name' => 'BtnScan',  'caption' => '🔎  Netzwerk durchsuchen', 'onClick' => 'MHUBD_Discover($id);'],
                        ['type' => 'Button', 'name' => 'BtnAbort', 'caption' => '✖  Suche abbrechen', 'onClick' => 'MHUBD_AbortScan($id);', 'visible' => false],
                        ['type' => 'Label', 'name' => 'ScanSummary', 'caption' => $this->ScanSummaryLine()],
                        [
                            'type'          => 'ProgressBar',
                            'name'          => 'ScanProgress',
                            'caption'       => 'Bereit.',
                            'minimum'       => 0,
                            'maximum'       => 100,
                            'current'       => 0,
                            'indeterminate' => false,
                            'visible'       => false,
                        ],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => "🆕 🔎  blue'Log SCADA-Adressbereich",
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Für Meteocontrol-blue\'Log-Solarpark-Datenlogger: EIN fest bekannter blue\'Log, aber viele dahinter angeschlossene Geräte (Wechselrichter, Zähler …) — jedes über seine eigene, am blue\'Log selbst frei vergebene „SCADA-Adresse" erreichbar (Geräteliste am blue\'Log → Spalte „SCADA Adresse").'],
                        ['type' => 'Label', 'caption' => '🆕 „🔎 Netzwerk durchsuchen" oben findet einen blue\'Log inzwischen von selbst und durchsucht den hier eingestellten SCADA-Adressbereich automatisch mit. Dieses Panel bleibt der schnellere Weg, wenn die blue\'Log-IP schon bekannt ist und man nicht das ganze Netz absuchen möchte — der Adressbereich unten gilt für BEIDE Wege.'],
                        ['type' => 'Label', 'caption' => '⚠️ Die SCADA-Lizenz ist ein separates, kostenpflichtiges Zusatzmodul und wird in der Praxis selten verwendet — die Standard-Lizenz eines blue\'Log enthält weder SCADA noch RPC. Vor dem Adressbereich-Suchlauf lohnt sich ein Blick über „Schnittstellen prüfen" unten, ob SCADA an diesem blue\'Log überhaupt lizenziert ist.'],
                        [
                            'type' => 'Select', 'name' => 'BlueLogHostPreset',
                            'caption' => 'Bekannten Host übernehmen … (aus bestehenden MeterHub-Instanzen)',
                            'options' => $blueLogHostOptions, 'value' => '',
                            'onChange' => 'MHUBD_ApplyBlueLogHostPreset($id, $BlueLogHostPreset);',
                        ],
                        ['type' => 'ValidationTextBox', 'name' => 'BlueLogHost', 'caption' => "blue'Log-IP-Adresse", 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'BlueLogPort', 'caption' => 'Modbus-TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'Label', 'caption' => '🆕 Dieser Port gilt jetzt auch für „🔎 Netzwerk durchsuchen" oben — wird der blue\'Log dort nicht über den allgemeinen Suchport gefunden, probiert die Suche automatisch diesen hier eingetragenen Port zusätzlich.'],
                        ['type' => 'Button', 'name' => 'BtnCheckLicenses', 'caption' => '🔍  Schnittstellen dieser IP prüfen (Power Control/RPC/SCADA)', 'onClick' => 'echo MHUBD_CheckBlueLogLicenses($id, $BlueLogHost, $BlueLogPort);'],
                        ['type' => 'NumberSpinner', 'name' => 'ScadaAddrStart', 'caption' => 'SCADA-Adresse von', 'minimum' => 1, 'maximum' => 247],
                        ['type' => 'NumberSpinner', 'name' => 'ScadaAddrEnd',   'caption' => 'SCADA-Adresse bis',  'minimum' => 1, 'maximum' => 247],
                        ['type' => 'Label', 'caption' => 'Adresse 97 ist üblicherweise das blue\'Log selbst (Summenwerte, hier nicht relevant) — der Bereich der angeschlossenen Einzelgeräte steht in derselben Spalte am blue\'Log, oft ab 100 aufwärts.'],
                        ['type' => 'Label', 'caption' => '💡 Eigene Namen statt „Wechselrichter (Modell) 1, 2, 3 …": die „Namens-Vorlage" im Panel „🛠️ Erstellen" unten gilt für BEIDE Suchmodi — z. B. „WR SCADA {unitid}" oder „WR Bus {busaddr}" (Busadresse = Spalte „Adresse" in der blue\'Log-Geräteliste).'],
                        ['type' => 'Button', 'name' => 'BtnScanBlueLog',  'caption' => "🔎  blue'Log-Adressbereich durchsuchen", 'onClick' => 'MHUBD_DiscoverBlueLog($id);'],
                        ['type' => 'Button', 'name' => 'BtnAbortBlueLog', 'caption' => '✖  Suche abbrechen', 'onClick' => 'MHUBD_AbortScan($id);', 'visible' => false],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🛠️  Erstellen',
                    'expanded' => true,
                    'items' => [
                        // Namens-Vorlage steht hier, nicht bei den Suchbereichen — sie hat
                        // nichts mit dem Suchen/Filtern zu tun, sondern nur mit dem
                        // "Erstellen"-Schritt (Dietmars Einwand 08.09.2026: "die Benennung
                        // hat eigentlich nichts mit der Suche zu tun"). Gilt für Funde BEIDER
                        // Suchmodi (IP-Bereich UND blue'Log-SCADA-Adressbereich).
                        ['type' => 'ValidationTextBox', 'name' => 'NameTemplate', 'caption' => 'Name-Vorlage (leer = Zählertyp + lfd. Nr.)'],
                        ['type' => 'Label', 'caption' => 'Platzhalter für die Vorlage: {zaehler} {ip} {unitid} {nr} {busaddr} — z. B. „{zaehler} Keller ({ip})". {busaddr} (RS485-Busadresse) ist nur bei Funden der blue\'Log-SCADA-Suche gefüllt, sonst leer.'],
                        [
                            'type'     => 'Configurator',
                            'name'     => 'DiscoveryList',
                            'caption'  => 'Gefundene Zähler',
                            // Laut SDK-Doku würde 0 "den verbleibenden Platz füllen" — live
                            // getestet (Dietmars Screenshot 08.09.2026) blieb die Tabelle in der
                            // Konsole trotzdem klein, wenn nur wenige Treffer da sind. Fester,
                            // großzügiger Wert ist daher verlässlicher als die dokumentierte
                            // Auto-Höhe, die sich in der Praxis nicht wie erwartet zeigte.
                            'rowCount' => 15,
                            'delete'   => false,
                            'sort'     => ['column' => 'ip', 'direction' => 'ascending'],
                            'columns'  => [
                                // "auto" darf laut SDK-Doku genau EINE Spalte tragen — hier die
                                // mit den längsten Texten (blue'Log-Funde tragen die
                                // Modellbezeichnung im Zählertyp-Text, z. B. "Wechselrichter
                                // (M3L-KT360-00N2SU)").
                                ['caption' => 'Zählertyp',    'name' => 'meter',  'width' => 'auto'],
                                ['caption' => 'IP-Adresse',   'name' => 'ip',     'width' => '150px'],
                                ['caption' => 'Unit ID',      'name' => 'unitId', 'width' => '100px'],
                                ['caption' => 'Alt-Instanz gefunden (MigrationsHub)', 'name' => 'legacy', 'width' => '280px',
                                 'visible' => function_exists('MIGHUB_FindLegacyCandidates')],
                            ],
                            'values' => $values,
                        ],
                        [
                            'type' => 'Label', 'caption' => '🔀 Migration von einer Alt-Instanz (anderes Modul, gleiche IP/Unit-ID): erst oben „Erstellen" klicken, dann hier „Migration vorbereiten".',
                            'visible' => function_exists('MIGHUB_FindLegacyCandidates'),
                        ],
                        [
                            'type' => 'PopupButton', 'caption' => 'Wie funktioniert „Migration vorbereiten" genau?', 'width' => '480px',
                            'visible' => function_exists('MIGHUB_FindLegacyCandidates'),
                            'popup' => [
                                'caption' => 'Migration vorbereiten',
                                'items' => [
                                    ['type' => 'Label', 'caption' => 'Voraussetzung: MigrationsHub ist installiert und kennt eine Alt-Instanz (anderes Modul) an genau derselben IP-Adresse und Unit-ID wie ein hier gefundener Zähler — die Spalte „Alt-Instanz gefunden" zeigt das.'],
                                    ['type' => 'Label', 'caption' => '1. Oben bei der Fund-Zeile auf „Erstellen" klicken. Die neue MeterHub-Instanz bekommt „Kommunikation aktiv" automatisch AUSGESCHALTET — sonst würden sich neu geloggte Werte mit der noch nicht übertragenen Alt-Historie überlappen.'],
                                    ['type' => 'Label', 'caption' => '2. Hier „Migration vorbereiten" klicken. Verknüpft die neue Instanz mit der Alt-Instanz in MigrationsHub (legt bei Bedarf eine MigrationsHub-Instanz an).'],
                                    ['type' => 'Label', 'caption' => '3. Weiter geht es in der MigrationsHub-Instanz (Knopf „→ Zur MigrationsHub-Instanz" erscheint danach) — dort bleiben Simulation, Bestätigung und die eigentliche Übernahme der Archiv-Historie bewusst manuelle, einzeln zu bestätigende Schritte.'],
                                    ['type' => 'Label', 'caption' => 'Bei mehreren Treffern: immer nur EIN Klick auf „Migration vorbereiten" pro noch offener Migration — erst die laufende in MigrationsHub abschließen, dann hier für den nächsten Treffer erneut klicken.'],
                                ],
                            ],
                        ],
                        [
                            'type' => 'Button', 'name' => 'BtnPrepareMigration', 'caption' => '🔀  Migration vorbereiten',
                            'onClick' => 'MHUBD_PrepareMigration($id);',
                            'visible' => function_exists('MIGHUB_FindLegacyCandidates'),
                        ],
                        ['type' => 'Label', 'name' => 'MigrationResult', 'caption' => '', 'visible' => false],
                        ['type' => 'OpenObjectButton', 'name' => 'BtnOpenMigration', 'caption' => '→ Zur MigrationsHub-Instanz', 'objectID' => 0, 'visible' => false],
                    ],
                ],
            ], [$this->ForumHint(), $this->LicenseHint()]))),
            'actions' => [
                ['type' => 'Button', 'caption' => '🔄  Übernehmen erzwingen (ohne Formularänderung)', 'onClick' => "IPS_ApplyChanges(\$id); echo '✅ ApplyChanges() ausgeführt.';", 'confirm' => 'Instanz jetzt neu anwenden (ApplyChanges)?'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Bereit.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte Such-IP-Bereich eintragen.'],
            ],
        ];

        return json_encode($form);
    }

    // -----------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------

    private function ShowProgress($caption, $current, $indeterminate = false)
    {
        @$this->UpdateFormField('ScanProgress', 'visible', true);
        @$this->UpdateFormField('ScanProgress', 'caption', $caption);
        @$this->UpdateFormField('ScanProgress', 'indeterminate', $indeterminate);
        @$this->UpdateFormField('ScanProgress', 'current', $current);
    }

    public function Discover()
    {
        $start = $this->ReadPropertyString('RangeStart');
        $end   = $this->ReadPropertyString('RangeEnd');
        $port  = $this->ReadPropertyInteger('Port');

        if ($start === '' || $end === '') {
            $this->SetStatus(104);
            // Gezielt per UpdateFormField, kein ReloadForm() hier: das würde auch
            // gerade erst getippte, noch nicht übernommene Werte in RangeStart/
            // RangeEnd wieder verwerfen — also genau die Felder, die der Nutzer
            // jetzt korrigieren soll.
            @$this->UpdateFormField('ScanSummary', 'caption', '❌ Start-/End-IP fehlt — bitte beide Felder ausfüllen und übernehmen.');
            return;
        }

        // Abbruch-Flagge zu Beginn zurücksetzen.
        if (@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->SetValue('ScanAbort', false);
        }
        // Start-Button aus, Abbrechen-Button ein (am Scan-Ende stellt ReloadForm
        // die Ausgangslage wieder her).
        @$this->UpdateFormField('BtnScan', 'visible', false);
        @$this->UpdateFormField('BtnAbort', 'visible', true);

        $ips = $this->expandRange($start, $end);
        if (count($ips) > 1024) {
            $ips = array_slice($ips, 0, 1024);
        }

        $this->ShowProgress('Durchsuche ' . count($ips) . ' IP-Adressen auf Port ' . $port . ' …', 0);

        $ignore = $this->ParseIgnoreIPs();
        if (count($ignore) > 0) {
            $ips = array_values(array_diff($ips, $ignore));
        }

        $openIps = $this->scanPortOpen($ips, $port, 3.0);

        // blue'Log-SCADA-Adressbereich für einen im Netzwerk-Suchlauf selbst
        // gefundenen blue'Log — dieselbe Property wie im eigenen Panel, aber
        // hier automatisch statt manuell angestoßen.
        $scadaFrom = $this->ReadPropertyInteger('ScadaAddrStart');
        $scadaTo   = $this->ReadPropertyInteger('ScadaAddrEnd');
        if ($scadaTo < $scadaFrom) {
            [$scadaFrom, $scadaTo] = [$scadaTo, $scadaFrom];
        }

        $results     = [];
        $blueLogsFound = 0;
        $total   = count($openIps);
        $i       = 0;
        $aborted = $this->scanAborted();
        // Dietmars Hinweis 08.09.2026: "Standardmäßig wird mit dem Port 502
        // gescannt, SCADA und Power Control/RPC haben aber andere Register" —
        // bei ihm liegen zufällig beide auf 502, aber der allgemeine
        // Suchport (klassische Zähler) und der blue'Log-eigene Modbus-Port
        // sind zwei unabhängige Einstellungen (siehe Panel "blue'Log
        // SCADA-Adressbereich" unten, Feld "Modbus-TCP-Port"). Ein Treffer
        // wird deshalb zuerst auf dem allgemeinen Suchport versucht (deckt
        // den — vermutlich häufigsten — Fall ab, dass beide identisch sind,
        // ohne zusätzliche Verbindungen), erst bei Fehlschlag UND falls
        // beide Ports voneinander abweichen zusätzlich auf dem separat
        // konfigurierten blue'Log-Port.
        $blueLogPort = $this->ReadPropertyInteger('BlueLogPort');

        foreach ($openIps as $ip) {
            if ($this->scanAborted()) { $aborted = true; break; }
            $i++;
            $this->ShowProgress("Prüfe Zählertyp: $ip ($i von $total offenen Ports) …", (int)round(($i / max(1, $total)) * 100));
            $found = $this->identifyMeter($ip, $port);
            if ($found !== null) {
                $results[] = $found;
            }
            // blue'Log-Erkennung direkt im normalen Suchlauf mit — bei einem
            // Treffer gleich den SCADA-Adressbereich dahinter mitdurchsuchen,
            // statt nur die IP zu melden und einen zweiten manuellen Schritt
            // zu verlangen.
            $blueLogPortUsed = $port;
            $isBlueLog = $this->identifyBlueLogHost($ip, $port);
            if (!$isBlueLog && $blueLogPort !== $port) {
                $isBlueLog = $this->identifyBlueLogHost($ip, $blueLogPort);
                $blueLogPortUsed = $blueLogPort;
            }
            if ($isBlueLog) {
                $blueLogsFound++;
                // Adresse 97 (das blue'Log selbst, Summe) immer mit, auch
                // wenn sie außerhalb des Geräte-Bereichs liegt.
                $scadaAddrs = array_values(array_unique(array_merge([self::BLUELOG_SELF_ADDR], range($scadaFrom, $scadaTo))));
                foreach (array_chunk($scadaAddrs, self::BLUELOG_SCAN_BATCH_SIZE) as $batch) {
                    if ($this->scanAborted()) { $aborted = true; break 2; }
                    $this->ShowProgress(
                        "blue'Log $ip gefunden — SCADA-Adressen {$batch[0]}–" . end($batch) . ' …',
                        (int)round(($i / max(1, $total)) * 100)
                    );
                    foreach ($this->identifyBlueLogDevicesBatch($ip, $blueLogPortUsed, $batch) as $blueLogFound) {
                        $results[] = $blueLogFound;
                    }
                }
            }
        }

        if ($aborted) {
            $this->ShowProgress('Scan abgebrochen – ' . count($results) . ' Zähler bis dahin gefunden.', 100);
        } else {
            $blueLogNote = $blueLogsFound > 0 ? " (davon $blueLogsFound blue'Log(s) samt SCADA-Geräten)" : '';
            $this->ShowProgress('Fertig: ' . count($results) . " Zähler gefunden (von $total offenen Ports)$blueLogNote.", 100);
        }

        $this->WriteAttributeString('ResultsJSON', json_encode($results));
        $this->WriteAttributeInteger('LastScanTs', time());
        $this->SetStatus(102);
        // Stolperfalle 12 (SUITE.md, EMS-Fund 20.08.2026): ein per RequestAction/
        // onClick aufgerufener Button aktualisiert ein bereits offenes Formular
        // NICHT automatisch — Labels, die nur beim ersten Formularaufbau
        // berechnet wurden, frieren ein. UpdateFormField() ist die gezielte
        // Lösung; das bestehende ReloadForm() (unten, ohnehin für BtnScan/
        // BtnAbort nötig) ist die von InverterHub live bestätigte gleichwertige
        // Alternative — baut aber das ganze Formular neu. Hier bewusst beides:
        // UpdateFormField zuerst (billig, gezielt), ReloadForm bleibt für die
        // Button-Sichtbarkeit ohnehin bestehen.
        @$this->UpdateFormField('ScanSummary', 'caption', $this->ScanSummaryLine());
        $this->ReloadForm();
    }

    /**
     * Zweiter Suchmodus: EIN fest bekannter blue'Log, aber viele dahinter
     * angeschlossene Geräte über ihre eigene SCADA-Adresse (= Unit-ID) statt
     * eines IP-Bereichs. Schreibt in dieselbe `ResultsJSON`/`Configurator`-
     * Fundliste wie Discover() — deren Zeilenformat (ip/unitId/meter/label)
     * ist identisch, ein zweiter Ergebnis-Mechanismus wäre unnötige
     * Verdopplung. Ein Suchlauf ersetzt jeweils die Funde des anderen Modus
     * (wie bei Discover() selbst auch: neu suchen überschreibt alte Funde).
     */
    public function DiscoverBlueLog()
    {
        $host = trim($this->ReadPropertyString('BlueLogHost'));
        $port = $this->ReadPropertyInteger('BlueLogPort');
        $from = $this->ReadPropertyInteger('ScadaAddrStart');
        $to   = $this->ReadPropertyInteger('ScadaAddrEnd');

        if ($host === '') {
            $this->SetStatus(104);
            @$this->UpdateFormField('ScanSummary', 'caption', "❌ blue'Log-IP-Adresse fehlt — bitte eintragen und übernehmen.");
            return;
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        if (@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->SetValue('ScanAbort', false);
        }
        @$this->UpdateFormField('BtnScanBlueLog', 'visible', false);
        @$this->UpdateFormField('BtnAbortBlueLog', 'visible', true);

        $addrs = array_values(array_unique(array_merge([self::BLUELOG_SELF_ADDR], range($from, $to))));
        // Sicherheitsgrenze wie bei Discover()s IP-Bereich (dort 1024) — der
        // SCADA-Adressraum (0..247, Modbus-Unit-ID) ist ohnehin viel kleiner.
        if (count($addrs) > 247) {
            $addrs = array_slice($addrs, 0, 247);
        }

        $this->ShowProgress("Prüfe " . count($addrs) . " SCADA-Adressen an $host:$port …", 0);

        $results = [];
        $total   = count($addrs);
        $done    = 0;
        $aborted = $this->scanAborted();
        foreach (array_chunk($addrs, self::BLUELOG_SCAN_BATCH_SIZE) as $batch) {
            if ($this->scanAborted()) { $aborted = true; break; }
            $this->ShowProgress(
                "SCADA-Adressen {$batch[0]}–" . end($batch) . " ($done von $total) …",
                (int)round(($done / max(1, $total)) * 100)
            );
            foreach ($this->identifyBlueLogDevicesBatch($host, $port, $batch) as $found) {
                $results[] = $found;
            }
            $done += count($batch);
        }

        if ($aborted) {
            $this->ShowProgress('Suche abgebrochen – ' . count($results) . ' Geräte bis dahin gefunden.', 100);
        } else {
            $this->ShowProgress('Fertig: ' . count($results) . ' Geräte gefunden (von ' . $total . ' geprüften Adressen).', 100);
        }

        $this->WriteAttributeString('ResultsJSON', json_encode($results));
        $this->WriteAttributeInteger('LastScanTs', time());
        $this->SetStatus(102);
        @$this->UpdateFormField('ScanSummary', 'caption', $this->ScanSummaryLine());
        $this->ReloadForm();
    }

    /**
     * Ist an dieser IP ein blue'Log erreichbar? Dietmars berechtigter Einwand
     * 08.09.2026: "wenn ich Zähler im Netzwerk suchen lasse, dann möchte ich
     * auch die blue'Logs angezeigt bekommen" — der normale IP-Bereichs-
     * Suchlauf setzte bis dahin voraus, dass man die blue'Log-IP schon
     * kennt. Jetzt prüft `Discover()` das für jede offene IP automatisch
     * mit und durchsucht bei einem Treffer direkt den SCADA-Adressbereich
     * dahinter — kein separater manueller Schritt mehr nötig.
     *
     * ZWEITE KORREKTUR, noch am selben Tag (Dietmars Live-Angabe zur
     * tatsächlichen Solarpark-Flotte): An seiner Anlage haben von ~15
     * blue'Logs nur die zwei Master-/EZA-Regler Power Control/RPC, SCADA
     * dagegen (nach seiner Kenntnis) praktisch alle. SCADA deshalb wieder
     * ZUERST (deckt den Regelfall mit einer einzigen Verbindung ab, nicht
     * drei), Power Control/RPC nur noch als Rückfall für die zwei
     * Sonderfälle bzw. Installationen ohne SCADA-Lizenz. Live am Solarpark
     * gemessen: Reihenfolge PC→RPC→SCADA hätte für die meisten seiner
     * Geräte ZWEI von Anfang an aussichtslose Verbindungen verschwendet,
     * bevor die eigentlich passende Schnittstelle überhaupt versucht wird —
     * bei der unten dokumentierten Verbindungs-Empfindlichkeit ein
     * unnötiges zusätzliches Risiko.
     *
     * DRITTE KORREKTUR, noch am selben Tag (Dietmar bestätigt: seine
     * eigenen "Schreiberling"-Skripte laufen parallel und greifen aktiv auf
     * dieselben Geräte zu): eine einzelne feste Pause reicht gegen echte
     * Verbindungs-Konkurrenz mit fremdem Datenverkehr nicht zuverlässig
     * (live gemessen: auch 300 ms nach vorherigen Verbindungen zur selben
     * IP scheiterten in einem Testlauf alle drei Schnittstellen). Jede
     * Schnittstelle bekommt deshalb bis zu zwei Versuche mit steigendem
     * Abstand (`probeBlueLogInterface()`), statt nach einem einzigen
     * Fehlversuch sofort zur nächsten Schnittstelle zu wechseln.
     *
     * VIERTE KORREKTUR, 09.09.2026 (Dietmars Hinweis: "vielleicht solltest
     * Du auch mal die Geräte-IDs in dieses Spiel mit einplanen"): Ein
     * echter Suchlauf über die Instanz fand trotz aller obigen Korrekturen
     * weiterhin NULL blue'Logs — live isoliert nachgestellt (dieselbe
     * Logik, 3 Versuche, SCADA zuerst) scheiterten .201 UND .204 komplett,
     * zeitgleich mit einer aktiven RPC-Regelung durch den Direktvermarkter
     * ("der DV hat gerade geregelt"). Unit-ID 97 ist nur die
     * Selbstauskunft des blue'Log — genau die dürfte während einer
     * Schreib-Transaktion des Direktvermarkters am ehesten belegt sein.
     * Die angeschlossenen EINZELGERÄTE (SCADA-Adressen ab
     * `ScadaAddrStart`) werden davon unabhängig bedient und antworten
     * erfahrungsgemäß trotzdem. Die SCADA-Erkennung probiert deshalb
     * zusätzlich zu 97 noch zwei Geräte-IDs aus dem konfigurierten Bereich
     * (Anfang und Mitte) — jede für sich ein gleichwertiger Nachweis
     * "hier ist ein blue'Log", mit derselben 3-Versuche-Pause je ID. Mehr
     * unabhängige Kandidaten statt einer einzigen, potenziell blockierten
     * Adresse.
     *
     * FÜNFTE KORREKTUR, noch am selben Tag (Dietmars Testvorschlag "wir
     * müssen nur z.B. #41462 duplizieren" — live mit parallelen Sockets
     * statt einer Instanz-Duplizierung nachgestellt): DAS war die
     * eigentliche Ursache. Ein blue'Log beantwortet 8 GLEICHZEITIG offene
     * Verbindungen anstandslos (0,07 s), aber 8 rasch AUFEINANDERFOLGENDE
     * Verbindungen (verbinden → anfragen → schließen → sofort neu
     * verbinden — genau das, was die "3 Versuche mit Pause"-Strategie
     * bisher tat) scheitern ALLE. Die bisherige Sequenz-mit-Pause-Logik
     * (`probeBlueLogInterface()`) hat das Problem also eher verschärft als
     * gelöst. Alle Kandidaten (SCADA-Geräte-IDs + Power Control + RPC)
     * werden jetzt über `probeManyParallel()` in EINEM Rutsch gleichzeitig
     * angefragt, mit einem zweiten parallelen Versuch als Rückfall statt
     * einer wachsenden Pausenkette.
     */
    private function identifyBlueLogHost($host, $port): bool
    {
        $targets = [];
        foreach ($this->blueLogScadaSampleIds() as $unitId) {
            $targets[] = ['host' => $host, 'port' => $port, 'unitId' => $unitId, 'reg' => 40000, 'count' => 1, 'kind' => 'scada'];
        }
        $targets[] = ['host' => $host, 'port' => $port, 'unitId' => 1, 'reg' => 98, 'count' => 2, 'kind' => 'freq']; // Power Control: PPC_F_AC
        $targets[] = ['host' => $host, 'port' => $port, 'unitId' => 10, 'reg' => 42, 'count' => 2, 'kind' => 'freq']; // RPC: PPC_F_AC

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $results = $this->probeManyParallel($targets, 0.7);
            foreach ($targets as $i => $t) {
                $regs = $results[$i] ?? null;
                if ($regs === null) {
                    continue;
                }
                if ($t['kind'] === 'scada') {
                    $type = $regs[0] ?? -1;
                    // Unit 97 = das blue'Log selbst (Gerätetyp 0 = "Data
                    // logger"); jede andere ID ist ein angeschlossenes
                    // Einzelgerät, dessen Gerätetyp in der bekannten
                    // Zuordnung stehen muss.
                    if ($t['unitId'] === 97 ? $type === 0 : isset(self::BLUELOG_METER_MAP[$type])) {
                        return true;
                    }
                } elseif (count($regs) >= 2) {
                    $f = $this->wordSwappedFloat($regs[0], $regs[1]);
                    if ($f !== null && $f >= 45.0 && $f <= 65.0) {
                        return true;
                    }
                }
            }
            if ($attempt === 0) {
                usleep(self::BLUELOG_CONN_PACING_US); // kurze Verschnaufpause vor einem zweiten parallelen Versuch
            }
        }
        return false;
    }

    // Zwei UInt16-Register (wortgetauscht, CDAB) als IEEE-754-Float32
    // interpretieren — dieselbe Konvention wie bei den blue'Log-Treibern in
    // MeterHub/module.php (ByteOrder 3, live verifiziert).
    private function wordSwappedFloat(int $regHigh, int $regLow): ?float
    {
        $f = unpack('G', pack('nn', $regLow, $regHigh))[1] ?? null;
        return ($f !== null && is_finite($f)) ? (float)$f : null;
    }

    // Geräte-IDs, die als Nachweis "hier ist ein blue'Log" geprüft werden:
    // die Selbstauskunft (97) plus Anfang und Mitte des konfigurierten
    // SCADA-Bereichs — siehe VIERTE KORREKTUR oben.
    private function blueLogScadaSampleIds(): array
    {
        $from = $this->ReadPropertyInteger('ScadaAddrStart');
        $to   = $this->ReadPropertyInteger('ScadaAddrEnd');
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $mid = (int)round(($from + $to) / 2);
        return array_values(array_unique(array_filter(
            [97, $from, $mid],
            fn($id) => $id >= 1 && $id <= 247
        )));
    }

    /**
     * Ein ganzes Bündel SCADA-Adressen GLEICHZEITIG prüfen statt eine nach
     * der anderen mit Pause — siehe FÜNFTE KORREKTUR bei
     * `identifyBlueLogHost()`: viele parallele Verbindungen sind für ein
     * blue'Log unproblematisch, schnelles Nacheinander dagegen nicht. Nur
     * die erste, billige Existenzprüfung (Register 40000) läuft parallel;
     * die Detailabfragen (Modellname, Busadresse) laufen für die wenigen
     * tatsächlichen Treffer weiterhin einzeln nacheinander — bei einem
     * typischen SCADA-Bereich sind das nur eine Handvoll echter Geräte,
     * kein spürbarer Zeitverlust, und weniger Risiko als auch diese
     * Detailabfragen zu parallelisieren.
     */
    private function identifyBlueLogDevicesBatch($host, $port, array $unitIds): array
    {
        $targets = [];
        foreach ($unitIds as $unitId) {
            $targets[] = ['host' => $host, 'port' => $port, 'unitId' => $unitId, 'reg' => 40000, 'count' => 1];
        }
        $results = $this->probeManyParallel($targets, 0.7);

        $found = [];
        foreach ($targets as $i => $t) {
            $regs = $results[$i] ?? null;
            $type = $regs[0] ?? null;
            // Gerätetyp 0 (Datenlogger) nur unter Adresse 97 und dort nur er —
            // alles andere wäre eine widersprüchliche Meldung.
            if ($type === null || !isset(self::BLUELOG_METER_MAP[$type])
                || (($type === 0) !== ($t['unitId'] === self::BLUELOG_SELF_ADDR))) {
                continue;
            }
            $unitId  = $t['unitId'];
            $model   = trim((string)$this->readAscii($host, $port, $unitId, 40033, 32, 1.5));
            $busAddr = $this->readU16Holding($host, $port, $unitId, 40113, 1.0);
            $label   = self::BLUELOG_TYPE_LABELS[$type] . ($model !== '' ? " ($model)" : '');
            $found[] = [
                'ip'      => $host,
                'port'    => $port,
                'unitId'  => $unitId,
                'meter'   => self::BLUELOG_METER_MAP[$type],
                'label'   => $label,
                'busAddr' => $busAddr,
            ];
        }
        return $found;
    }

    // UInt16 per FC 0x03 (Holding-Register) — blue'Log-SCADA-Gerätetyp (40000).
    private function readU16Holding($host, $port, $unitId, $startReg, $timeout)
    {
        $regs = $this->readHolding($host, $port, $unitId, $startReg, 1, $timeout);
        if ($regs === null || count($regs) < 1) {
            return null;
        }
        return $regs[0] & 0xFFFF;
    }

    // ASCII-String über mehrere Register (je 2 Zeichen groß-endian, mit 0x00
    // aufgefüllt) — Meteocontrol-SCADA-Konvention für Vendor/Model/Serial.
    private function readAscii($host, $port, $unitId, $startReg, $regCount, $timeout)
    {
        $regs = $this->readHolding($host, $port, $unitId, $startReg, $regCount, $timeout);
        if ($regs === null) {
            return null;
        }
        // Bei diesen Geräten (ByteOrder 3) ist bei mehrregistrigen Strings
        // nicht nur paarweise wie bei Float32 (CDAB) vertauscht, sondern die
        // GESAMTE Registerreihenfolge umgekehrt — das letzte Register trägt
        // die ERSTEN Zeichen. Live verifiziert (08.09.2026, Rohbytes eines
        // echten Modell-Strings direkt am Solarpark): erst nach Umkehr der
        // Registerreihenfolge ergab sich "AE 3TL 20-IEC (Gen 2)" statt der
        // vorher rückwärts/rechtsbündig im Feld stehenden Zeichen.
        $bytes = '';
        for ($i = $regCount - 1; $i >= 0; $i--) {
            $v = $regs[$i] ?? 0;
            $bytes .= chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
        }
        // Am ersten 0x00 abschneiden statt nur rechts zu trimmen — falls ein
        // kürzerer Name einen längeren überschrieben hat, könnten hinter dem
        // eigentlichen Null-Terminator noch Alt-Bytes stehen.
        $nullPos = strpos($bytes, "\x00");
        return trim($nullPos === false ? $bytes : substr($bytes, 0, $nullPos));
    }

    private function findExistingInstances()
    {
        $map = [];
        foreach (IPS_GetInstanceListByModuleID(self::METERHUB_GUID) as $iid) {
            $host   = @IPS_GetProperty($iid, 'Host');
            $unitId = @IPS_GetProperty($iid, 'UnitId');
            if ($host !== false && $host !== null && $host !== '') {
                $map[$host . '|' . $unitId] = $iid;
            }
        }
        return $map;
    }

    /**
     * Alt-Instanz eines Fremdmoduls an derselben IP/Unit-ID, falls
     * MigrationsHub installiert ist und eine kennt. Optionale Kopplung
     * (Verbund-Konvention 29.07.2026, mit MigrationsHub abgestimmt) —
     * ohne MigrationsHub liefert dies immer "nichts gefunden", bricht
     * nichts.
     *
     * Zwei Live-Fixes vom 30.08.2026 (aufgedeckt, als MigrationsHub an
     * Dietmars Anlage erstmals wirklich installiert war — vorher lief hier
     * immer nur der function_exists-Kurzschluss, der Fehlpfad war nie
     * erreichbar):
     * 1. Ziel der PREFIX_-Funktion muss eine MIGRATIONSHUB-Instanz sein,
     *    nicht $this->InstanceID — der Kernel-Wrapper dispatcht auf die
     *    übergebene Instanz. Existiert keine, gibt es keine Kandidaten
     *    (hier wird bewusst KEINE angelegt — GetConfigurationForm() darf
     *    keine Instanzen erzeugen; PrepareMigration() legt sie beim ersten
     *    Klick an, danach füllt sich auch die Formular-Spalte).
     * 2. MIGHUB_FindLegacyCandidates hat inzwischen 5 Parameter
     *    ($excludeInstanceID gegen "migriere von deiner eigenen frisch
     *    angelegten Instanz") — PREFIX_-Wrapper honorieren PHP-Defaults
     *    NICHT (SUITE.md-Stolperstein), alle Argumente sind Pflicht. Ein
     *    vorangestelltes @ hält den ArgumentCountError nicht auf (Fatal,
     *    keine Warnung — ebenfalls dokumentierte Lehre), deshalb try/catch:
     *    ein künftiger Vertragsbruch des Partnermoduls degradiert damit zu
     *    "kein Kandidat" statt das komplette Konfigurationsformular zu
     *    töten, wie live bei Dietmar geschehen.
     */
    private function LegacyCandidateFor(string $host, int $unitId, int $excludeInstanceID = 0): array
    {
        if (!function_exists('MIGHUB_FindLegacyCandidates')) {
            return ['id' => 0, 'name' => ''];
        }
        $migIDs = IPS_GetInstanceListByModuleID(self::MIGRATIONSHUB_GUID);
        $migID  = (int)($migIDs[0] ?? 0);
        if ($migID <= 0) {
            return ['id' => 0, 'name' => ''];
        }
        try {
            $found = MIGHUB_FindLegacyCandidates($migID, $host, $this->ReadPropertyInteger('Port'), $unitId, $excludeInstanceID);
        } catch (\Throwable $e) {
            $this->SendDebug('LegacyCandidateFor', 'MIGHUB_FindLegacyCandidates fehlgeschlagen: ' . $e->getMessage(), 0);
            return ['id' => 0, 'name' => ''];
        }
        if (!is_array($found) || count($found) === 0) {
            return ['id' => 0, 'name' => ''];
        }
        $first = $found[0];
        $id = (int)($first['instanceID'] ?? $first['id'] ?? 0);
        if ($id <= 0) {
            return ['id' => 0, 'name' => ''];
        }
        return ['id' => $id, 'name' => (string)($first['name'] ?? IPS_GetName($id))];
    }

    /**
     * Verknüpft die erste bereits erstellte MeterHub-Instanz, für die eine
     * Alt-Instanz gefunden wurde, mit MigrationsHub — legt bei Bedarf eine
     * MigrationsHub-Instanz an (wiederverwendet eine vorhandene) und ruft
     * MIGHUB_PrefillMigration() auf. Absichtlich nur EIN Treffer je Klick:
     * PrefillMigration setzt Source/Target auf EINER MigrationsHub-Instanz,
     * ein zweiter Aufruf vor Abschluss der ersten Migration würde die noch
     * nicht bestätigte Zuordnung überschreiben.
     */
    public function PrepareMigration()
    {
        $say = function (string $m) {
            $this->UpdateFormField('MigrationResult', 'caption', $m);
            $this->UpdateFormField('MigrationResult', 'visible', true);
        };
        if (!function_exists('MIGHUB_FindLegacyCandidates') || !function_exists('MIGHUB_PrefillMigration')) {
            $say('❌ MigrationsHub ist nicht installiert.');
            return;
        }

        $results = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        $results = is_array($results) ? $results : [];
        $existing = $this->findExistingInstances();

        // MigrationsHub-Instanz VOR der Kandidatensuche sicherstellen —
        // LegacyCandidateFor() braucht sie als Aufrufziel (PREFIX_-Wrapper
        // dispatcht auf die Instanz) und legt selbst bewusst keine an.
        // Ohne diese Reihenfolge wäre der erste Klick ein Henne-Ei-Problem:
        // keine Instanz → keine Kandidaten → nie eine Instanz angelegt.
        $migIDs = IPS_GetInstanceListByModuleID(self::MIGRATIONSHUB_GUID);
        $migID = $migIDs[0] ?? 0;
        if ($migID <= 0) {
            $migID = IPS_CreateInstance(self::MIGRATIONSHUB_GUID);
        }

        foreach ($results as $r) {
            $targetID = $existing[$r['ip'] . '|' . $r['unitId']] ?? 0;
            if ($targetID <= 0) {
                continue; // Für diese Zeile wurde noch keine MeterHub-Instanz erstellt.
            }
            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId'], (int)$targetID);
            if ($legacy['id'] <= 0) {
                continue;
            }

            // Kommunikation sicherheitshalber aus, falls sie inzwischen
            // (manuell oder weil die Zeile vor dieser Funktion schon einmal
            // erstellt wurde) doch aktiv ist.
            if (@IPS_GetProperty($targetID, 'Active') === true) {
                IPS_SetProperty($targetID, 'Active', false);
                IPS_ApplyChanges($targetID);
            }

            MIGHUB_PrefillMigration($migID, $legacy['id'], $targetID);

            $say('✅ Migration vorbereitet: „' . $legacy['name'] . '" (#' . $legacy['id'] . ') → „' .
                IPS_GetName($targetID) . '" (#' . $targetID . '). Weiter in der MigrationsHub-Instanz — dort simulieren, prüfen, ausführen.');
            $this->UpdateFormField('BtnOpenMigration', 'objectID', $migID);
            $this->UpdateFormField('BtnOpenMigration', 'visible', true);
            return;
        }

        $say('🔎 Keine passende Kombination aus bereits erstellter MeterHub-Instanz und gefundener Alt-Instanz — erst oben „Erstellen" klicken.');
    }

    private function ParseIgnoreIPs()
    {
        $raw = (string)$this->ReadPropertyString('IgnoreIPs');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ip2long($part) !== false) {
                $out[] = long2ip(ip2long($part));
            }
        }
        return array_unique($out);
    }

    private function expandRange($startIp, $endIp)
    {
        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }
        $ips = [];
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    // Nicht-blockierender Parallel-Scan: testet alle IPs gleichzeitig, ob
    // Port 502 offen ist, statt sie nacheinander abzuklopfen.
    private function scanPortOpen($ips, $port, $timeoutSec)
    {
        $pending = [];
        foreach ($ips as $ip) {
            $s = @stream_socket_client(
                "tcp://$ip:$port",
                $errno,
                $errstr,
                0.01,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($s !== false) {
                stream_set_blocking($s, false);
                $pending[$ip] = $s;
            }
        }

        $open      = [];
        $totalOpen = count($pending);
        $startTime = microtime(true);
        $deadline  = $startTime + $timeoutSec;
        $lastUi    = 0.0;
        while (count($pending) > 0 && microtime(true) < $deadline) {
            if ($this->scanAborted()) {
                break;
            }
            $write  = array_values($pending);
            $read   = [];
            $except = [];
            $n = @stream_select($read, $write, $except, 0, 200000);
            if ($n === false) {
                break;
            }
            foreach ($pending as $ip => $sock) {
                if (in_array($sock, $write, true)) {
                    $peer = @stream_socket_get_name($sock, true);
                    if ($peer !== false) {
                        $open[] = $ip;
                    }
                    fclose($sock);
                    unset($pending[$ip]);
                }
            }
            $now = microtime(true);
            if ($now - $lastUi >= 0.3) {
                $lastUi  = $now;
                $elapsed = $now - $startTime;
                $pct     = (int)round(min(95, ($elapsed / $timeoutSec) * 90));
                $this->ShowProgress(
                    "Portscan läuft … " . count($open) . " offen, " . count($pending) . " von $totalOpen noch offen",
                    $pct
                );
                $deadline += microtime(true) - $now;
            }
        }
        foreach ($pending as $sock) {
            @fclose($sock);
        }
        return $open;
    }

    private function identifyMeter($ip, $port)
    {
        foreach (self::METER_UNIT_IDS as $meter => $unitIds) {
            foreach ($unitIds as $unitId) {
                if ($this->probeMeter($meter, $ip, $port, $unitId)) {
                    return [
                        'ip'     => $ip,
                        'port'   => $port,
                        'unitId' => $unitId,
                        'meter'  => $meter,
                        'label'  => self::METER_LABELS[$meter],
                    ];
                }
            }
        }
        return null;
    }

    // Erkennung über Plausibilität zweier Float32-Register: Netzfrequenz
    // (45..65 Hz) UND eine Spannung (30..500 V). Beide Zähler liegen in
    // verschiedenen Adressbereichen (PAC2200 niedrig, UMG604 ab 19000), was sie
    // zuverlässig unterscheidet — ein falsch angesprochenes Register liefert
    // entweder einen Modbus-Fehler (null) oder einen unplausiblen Float.
    private function probeMeter($meter, $ip, $port, $unitId)
    {
        switch ($meter) {
            case 'siemens_pac2200':
                // Reg 55: Frequenz (Float32), Reg 1: Spannung L1-N (Float32).
                $f = $this->readFloat($ip, $port, $unitId, 55, 1.0);
                if ($f === null || $f < 45.0 || $f > 65.0) {
                    return false;
                }
                $u = $this->readFloat($ip, $port, $unitId, 1, 1.0);
                return ($u !== null && $u >= 30.0 && $u <= 500.0);

            case 'janitza_umg604':
                // Klassische Janitza-Karte: Frequenz auf 19050, Spannung 19000.
                $f = $this->readFloat($ip, $port, $unitId, 19050, 1.0);
                if ($f === null || $f < 45.0 || $f > 65.0) {
                    return false;
                }
                $u = $this->readFloat($ip, $port, $unitId, 19000, 1.0);
                return ($u !== null && $u >= 30.0 && $u <= 500.0);

            case 'janitza_umg800':
                // UMG 800 (Werkskarte): Frequenz auf 19054, Spannung 19000.
                // Zusätzlich 19050 gegenprüfen — dort steht beim UMG 800 KEIN
                // Frequenzwert (sondern ein Leistungsfaktor 0..1), was ihn von
                // der klassischen Karte trennt.
                $f = $this->readFloat($ip, $port, $unitId, 19054, 1.0);
                if ($f === null || $f < 45.0 || $f > 65.0) {
                    return false;
                }
                $f50 = $this->readFloat($ip, $port, $unitId, 19050, 1.0);
                if ($f50 !== null && $f50 >= 45.0 && $f50 <= 65.0) {
                    return false; // sieht nach klassischer Karte aus
                }
                $u = $this->readFloat($ip, $port, $unitId, 19000, 1.0);
                return ($u !== null && $u >= 30.0 && $u <= 500.0);

            case 'shelly_pro3em':
                // Shelly Pro 3EM (Modbus muss am Gerät aktiviert sein), FC 0x04,
                // Float32 wortgetauscht (CDAB). Wire-Adresse = Doku − 30000:
                // Frequenz 1033, Spannung L1 1020. (An echtem Gerät verifiziert.)
                $f = $this->readFloatInputSw($ip, $port, $unitId, 1033, 1.0);
                if ($f === null || $f < 45.0 || $f > 65.0) {
                    return false;
                }
                $u = $this->readFloatInputSw($ip, $port, $unitId, 1020, 1.0);
                return ($u !== null && $u >= 30.0 && $u <= 500.0);

            case 'carlo_gavazzi_em':
                // Carlo Gavazzi EM24/ET340, FC 0x04: Spannung als Int32 CDAB
                // (×0,1) auf 0, Frequenz als UInt16 (×0,1) auf 51.
                $uraw = $this->readS32swInput($ip, $port, $unitId, 0, 1.0);
                if ($uraw === null || $uraw * 0.1 < 30.0 || $uraw * 0.1 > 500.0) {
                    return false;
                }
                $fraw = $this->readU16Input($ip, $port, $unitId, 51, 1.0);
                return ($fraw !== null && $fraw * 0.1 >= 45.0 && $fraw * 0.1 <= 65.0);

            case 'whatwatt':
                // WhatWatt, FC 0x04, Float32. Keine Frequenz — daher Spannung
                // L1 (1) und L2 (3) als zwei Plausibilitätskriterien.
                $u1 = $this->readFloatInput($ip, $port, $unitId, 1, 1.0);
                if ($u1 === null || $u1 < 30.0 || $u1 > 500.0) {
                    return false;
                }
                $u2 = $this->readFloatInput($ip, $port, $unitId, 3, 1.0);
                return ($u2 !== null && $u2 >= 30.0 && $u2 <= 500.0);

            case 'phoenix_eem375':
                // Phoenix EEM-EM375, FC 0x04, Float32 ab 4096 (Spannung L1/L2).
                $u1 = $this->readFloatInput($ip, $port, $unitId, 4096, 1.0);
                if ($u1 === null || $u1 < 30.0 || $u1 > 500.0) {
                    return false;
                }
                $u2 = $this->readFloatInput($ip, $port, $unitId, 4098, 1.0);
                return ($u2 !== null && $u2 >= 30.0 && $u2 <= 500.0);

            case 'eastron_sdm72d':
                // Eastron SDM72D/SDM630, FC 0x04, Float32: Spannung auf 0,
                // Frequenz auf 70.
                $u = $this->readFloatInput($ip, $port, $unitId, 0, 1.0);
                if ($u === null || $u < 30.0 || $u > 500.0) {
                    return false;
                }
                $f = $this->readFloatInput($ip, $port, $unitId, 70, 1.0);
                return ($f !== null && $f >= 45.0 && $f <= 65.0);

            case 'goe_controller':
                // go-e Controller, FC 0x04, Float32 Big-Endian: Spannung L1 auf
                // 1000, L2 auf 1002 — beide plausibel als Doppelkriterium (eine
                // Frequenz hat das Gerät nicht, Register 1008 liefert NaN).
                // Unbelegte Register beantwortet der Controller mit 0xFFFF…
                // (NaN) statt einer Exception; die Float-Helfer geben dafür
                // null zurück, wodurch er bei allen Fremd-Proben durchfällt —
                // und umgekehrt NaN hier nicht als Treffer zählt.
                // Modbus muss am Gerät aktiviert sein (App/HTTP-API men=true).
                $u1 = $this->readFloatInput($ip, $port, $unitId, 1000, 1.0);
                if ($u1 === null || $u1 < 30.0 || $u1 > 500.0) {
                    return false;
                }
                $u2 = $this->readFloatInput($ip, $port, $unitId, 1002, 1.0);
                return ($u2 !== null && $u2 >= 30.0 && $u2 <= 500.0);
        }
        return false;
    }

    // Liest ein einzelnes Float32 (2 Register, Big-Endian) per FC 0x03.
    // Rückgabe null bei Fehler/kein Wert.
    private function readFloat($host, $port, $unitId, $startReg, $timeout)
    {
        $regs = $this->readHolding($host, $port, $unitId, $startReg, 2, $timeout);
        if ($regs === null || count($regs) < 2) {
            return null;
        }
        $raw = pack('nn', $regs[0] & 0xFFFF, $regs[1] & 0xFFFF);
        $val = unpack('G', $raw);
        $f = (float)($val[1] ?? 0.0);
        return is_finite($f) ? $f : null;
    }

    private function readHolding($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x03, $startReg, $count, $timeout);
    }

    /**
     * Mehrere Holding-Register-Anfragen ECHT GLEICHZEITIG stellen statt
     * nacheinander mit Pause — live am Solarpark verifiziert (09.09.2026,
     * Dietmars Testvorschlag "wir müssen nur z.B. #41462 duplizieren", hier
     * stattdessen direkt mit parallelen Sockets nachgestellt): ein blue'Log
     * beantwortet 8 gleichzeitig offene Verbindungen anstandslos (0,07 s),
     * scheitert aber komplett (0 von 8) bei 8 rasch AUFEINANDERFOLGENDEN
     * Verbindungen (verbinden → anfragen → schließen → sofort neu
     * verbinden) — das eingebettete Gerät verkraftet offenbar keine
     * schnelle Verbindungs-Wiederverwendung, aber problemlos mehrere
     * parallele Sitzungen. Ersetzt damit die bisherige
     * "Sequenz-mit-wachsender-Pause"-Strategie (`probeBlueLogInterface()`),
     * die genau das falsche Verhalten (schnelles Nacheinander) noch
     * verstärkt hatte.
     *
     * $targets: Liste von ['host', 'port', 'unitId', 'reg', 'count'].
     * Rückgabe: Liste in derselben Reihenfolge/denselben Schlüsseln, je
     * Eintrag entweder null (keine/keine gültige Modbus-Antwort) oder das
     * Array der gelesenen 16-Bit-Register.
     */
    private function probeManyParallel(array $targets, float $timeout): array
    {
        $sockets = [];
        foreach ($targets as $i => $t) {
            $s = @stream_socket_client("tcp://{$t['host']}:{$t['port']}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
            $sockets[$i] = $s;
            if ($s !== false) {
                stream_set_blocking($s, false);
                $pdu  = pack('Cnn', 0x03, $t['reg'], $t['count']);
                $mbap = pack('nnn', mt_rand(1, 65535), 0, strlen($pdu) + 1) . chr($t['unitId']);
                @fwrite($s, $mbap . $pdu);
            }
        }

        $buffers = [];
        foreach (array_keys($targets) as $i) {
            $buffers[$i] = '';
        }
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $read = [];
            foreach ($sockets as $i => $s) {
                if ($s !== false && strlen($buffers[$i]) < 9) {
                    $read[$i] = $s;
                }
            }
            if (empty($read)) {
                break;
            }
            $write     = null;
            $except    = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $sec       = (int)$remaining;
            $usec      = (int)(($remaining - $sec) * 1000000);
            $n = @stream_select($read, $write, $except, $sec, $usec);
            if ($n > 0) {
                foreach ($read as $i => $s) {
                    $chunk = @fread($s, 512);
                    if ($chunk !== false) {
                        $buffers[$i] .= $chunk;
                    }
                }
            }
        }

        $results = [];
        foreach ($targets as $i => $t) {
            if ($sockets[$i] !== false) {
                @fclose($sockets[$i]);
            }
            $resp = $buffers[$i];
            if (strlen($resp) < 9 || ord($resp[7]) !== 0x03) {
                $results[$i] = null;
                continue;
            }
            $byteCount = ord($resp[8]);
            if (strlen($resp) < 9 + $byteCount) {
                $results[$i] = null;
                continue;
            }
            $regs = [];
            for ($k = 0; $k < $byteCount / 2; $k++) {
                $regs[] = (ord($resp[9 + $k * 2]) << 8) | ord($resp[10 + $k * 2]);
            }
            $results[$i] = $regs;
        }
        return $results;
    }

    private function readInput($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x04, $startReg, $count, $timeout);
    }

    // Einzelnes Float32 (Big-Endian) per FC 0x04 (Input-Register).
    private function readFloatInput($host, $port, $unitId, $startReg, $timeout)
    {
        $regs = $this->readInput($host, $port, $unitId, $startReg, 2, $timeout);
        if ($regs === null || count($regs) < 2) {
            return null;
        }
        $f = (float)(unpack('G', pack('nn', $regs[0] & 0xFFFF, $regs[1] & 0xFFFF))[1] ?? 0.0);
        return is_finite($f) ? $f : null;
    }

    // Float32 mit getauschter Wortreihenfolge (CDAB) per FC 0x04 — Shelly Pro 3EM.
    private function readFloatInputSw($host, $port, $unitId, $startReg, $timeout)
    {
        $regs = $this->readInput($host, $port, $unitId, $startReg, 2, $timeout);
        if ($regs === null || count($regs) < 2) {
            return null;
        }
        $f = (float)(unpack('G', pack('nn', $regs[1] & 0xFFFF, $regs[0] & 0xFFFF))[1] ?? 0.0);
        return is_finite($f) ? $f : null;
    }

    // Int32 mit getauschter Wortreihenfolge (CDAB) per FC 0x04 — Carlo Gavazzi.
    private function readS32swInput($host, $port, $unitId, $startReg, $timeout)
    {
        $regs = $this->readInput($host, $port, $unitId, $startReg, 2, $timeout);
        if ($regs === null || count($regs) < 2) {
            return null;
        }
        $v = (($regs[1] & 0xFFFF) << 16) | ($regs[0] & 0xFFFF);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    // UInt16 per FC 0x04 — Carlo Gavazzi Frequenz.
    private function readU16Input($host, $port, $unitId, $startReg, $timeout)
    {
        $regs = $this->readInput($host, $port, $unitId, $startReg, 1, $timeout);
        if ($regs === null || count($regs) < 1) {
            return null;
        }
        return $regs[0] & 0xFFFF;
    }

    private function modbusRead($host, $port, $unitId, $fc, $startReg, $count, $timeout)
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, $timeout);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', $fc, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unitId);

        fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        fclose($sock);

        if (strlen($response) < 9) {
            return null;
        }
        $rfc = ord($response[7]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);
        $regs      = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }
}
