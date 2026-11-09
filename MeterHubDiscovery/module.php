<?php

// ---------------------------------------------------------------------------
// MeterHubDiscovery — Configurator-Modul: durchsucht einen IP-Bereich nach
// Energiezählern auf Modbus-TCP-Port 502, erkennt den Zählertyp anhand eines
// charakteristischen Registers (Frequenz + Spannung als Plausibilitätsprüfung)
// und legt auf Klick eine MeterHub-Instanz mit vorausgefüllten Werten an.
// Eigenständige, kompakte Modbus-Hilfsfunktionen (kein Zugriff auf die Klassen
// aus dem MeterHub-Modulordner — Module sind bewusst getrennt).
// ---------------------------------------------------------------------------

/**
 * Texte zur Laufzeit übersetzen (Quelltexte englisch, Deutsch in locale.json — SUITE.md Punkt 9). Die Modulklasse
 * hinterlegt im Konstruktor ihre Translate()-Methode; auch Hilfsklassen und statische Methoden kommen so an die Übersetzung.
 * Mit Argumenten: sprintf() auf den übersetzten Text.
 */
class MHUBD_Text
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
}

class MeterHubDiscovery extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        MHUBD_Text::set(function (string $text): string {
            return $this->Translate($text);
        });
    }

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
        'janitza_umg604'  => 'Janitza UMG (classic register map)',
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
        0 => 'Data logger – total of all inverters',
        1 => 'Inverter',
        3 => 'Meter',
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
        $this->RegisterPropertyString('FormLayout', '[]');   // Formular: Aufbau (SUITE.md 3a)
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

    private const NEWS_VERSION = '0.35.0';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-nrg-stack-meterhub-energiezaehler-ein-modbus-tcp-modul-fuer-siemens-janitza-eastron-shelly-go-e-meteocontrol-bluelog-u-a-discovery-virtuelle-zaehler/144395';
    private const LICENSE_URL = 'https://github.com/DG65/NRGMeterHub/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    // Eigene Modul-GUID (= module.json 'id') für das geteilte Ausblenden über
    // Geschwister-Instanzen, siehe PropagateDismiss().
    private const GUID_DISCOVERY = '{196DA9E9-7C40-4128-99DE-85186BF64A8D}';

    /** Siehe MeterHubVirtual::PurposeIntro() für die volle Herleitung — steht ganz vorn, noch vor dem News-Panel. */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Searches your local network for supported energy meters and, on a click, creates a suitably prefilled MeterHub instance — IP address, unit ID and meter type do not have to be entered by hand.'],
                ['type' => 'Label', 'caption' => 'The benefit: with several meters (e.g. per consumer or per apartment) a few clicks instead of configuring each instance manually. Switching from another meter/hub module while keeping the measurement history is also done here (see below).'],
                ['type' => 'Label', 'caption' => 'The module itself measures nothing — the actual data acquisition is then done by the newly created MeterHub instance.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBD_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /** Aufgeklappt und pro Version einmalig bestätigbar — Formular-Konvention, siehe MeterHub::NewsBanner(). */
    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  New in this version',
            'items' => [
                ['type' => 'Label', 'caption' => '• 🌐 New: MeterHubDiscovery is now available in German and English. Texts follow the Symcon language setting: the form is translated by the console, messages are shown in the set language, and variable names are set in the language that is active when you click "Apply". Identifiers, properties and functions are unchanged — nothing is lost on existing instances.'],
                ['type' => 'Label', 'caption' => '• 🪟 New: choose the form layout yourself. In the new panel "Form: layout" (at the bottom, before the forum notice) you decide per section whether it is expanded, collapsed or hidden when opened, and at which position it sits. Symcon does not remember expanding and collapsing by itself, so the form looks the way you need it every time. Hidden sections keep their settings and keep working. No tick means "default"; "Reset to default" restores everything. Takes effect after "Apply" the next time the form is opened.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBD_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::NEWS_VERSION);
    }

    /** Symcon-Forum-Hinweis — einmalig dismissible, kein Versionsbezug, siehe MeterHubVirtual::ForumHint(). */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Feedback, especially on newly recognized meter types, is explicitly welcome in the community thread.'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBD_AckForumHint($id);'],
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
        foreach (IPS_GetInstanceListByModuleID(self::GUID_DISCOVERY) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                MHUBD_AdoptDismissState($sib, $what, $value);
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
        foreach (IPS_GetInstanceListByModuleID(self::GUID_DISCOVERY) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = MHUBD_GetDismissState($sib);
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
     * Kopfzeile fürs Suchbereich-Panel nach der Verbund-Konvention „Einheitliche
     * Verbund-Status-Kopfzeile" (SUITE.md, 20.08.2026, Referenz: EMS'
     * getDiscoverySummaryLine()). Direkt unter dem Suche-Button: eine Zeile,
     * Icon + Kernzahl + Zeitstempel statt technischem Fließtext.
     */
    private function ScanSummaryLine(): string
    {
        $ts = $this->ReadAttributeInteger('LastScanTs');
        if ($ts === 0) {
            return MHUBD_Text::t('ℹ️ Not searched yet — press the button above.');
        }
        $count = count(json_decode((string)$this->ReadAttributeString('ResultsJSON'), true) ?: []);
        $icon  = $count > 0 ? '✅' : '⚠️';
        return sprintf(MHUBD_Text::t('%s %d meters found (last at %s).'), $icon, $count, date('H:i:s', $ts));
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
            $this->RegisterVariableBoolean('ScanAbort', MHUBD_Text::t('Scan abort'), '', 100);
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
        @$this->UpdateFormField('ScanProgress', 'caption', MHUBD_Text::t('Abort requested – please wait a moment …'));
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
            return MHUBD_Text::t('❌ Please enter the blue\'Log IP address first.');
        }
        // Kurz genug, damit jede Zeile im nativen Meldungsdialog ohne Umbruch
        // passt (Dietmars Screenshot 08.09.2026: "RPC" statt ausgeschriebenem
        // "Remote Power Control / RPC" reicht — die Klammer erklärt die Rolle).
        $roles = [
            1  => MHUBD_Text::t('Power Control (grid operator)'),
            10 => MHUBD_Text::t('RPC (direct marketer)'),
            97 => MHUBD_Text::t('SCADA (all devices)'),
        ];
        $parts = [];
        foreach ($roles as $unitId => $label) {
            $ok = $this->probeUnitResponds($host, $port, $unitId, 1.0);
            // ❌ statt "—" — eindeutiges Gegenteil zu ✅ (Dietmars Einwand:
            // "—" liest sich nicht klar als "nicht erreichbar").
            $parts[] = ($ok ? '✅ ' : '❌ ') . MHUBD_Text::t('%s (slave ID %s)', $label, $unitId);
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
        $blueLogHostOptions = [['caption' => '— choose a known host —', 'value' => '']];
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
                'name'       => MHUBD_Text::t('%s @ %s (unit %s)', $r['label'], $r['ip'], $r['unitId']),
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

        // Feste Elemente oben (Zweck, Neu, Dokumentation), dazwischen die wählbaren Fachpanels, unten Aufbau/Forum/Über (SUITE.md 3a).
        $top = [
                $this->PurposeIntro(),
                $this->NewsBanner(),
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Documentation & help',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => MHUBD_Text::t('MeterHubDiscovery %s — version of this guide.', self::NEWS_VERSION)],
                        ['type' => 'Label', 'caption' => 'Searches an IP range in the local network for energy meters on Modbus TCP port 502 and recognizes the meter type by a characteristic register (frequency + voltage as a plausibility check).'],
                        ['type' => 'Label', 'caption' => 'Enter start and end IP (a suggestion based on your own network is already filled in), then click "Search network". Meters found appear below — a click on "Create" creates a MeterHub instance with prefilled IP address, unit ID and meter type.'],
                        ['type' => 'Label', 'caption' => '🔀 A new instance comes with "Communication active" already switched on. If you plan to switch from another meter/hub module while keeping the measurement history: switch it off again on the new MeterHub instance right after creating it, until Device swap has taken over the old history — otherwise the newly logged values overlap with the transferred old history.'],
                        ['type' => 'Label', 'caption' => 'The search checks only a few documented default unit IDs per meter, not a full 1-247 range — if the unit ID has an unusual setting, please create the MeterHub instance manually.'],
                        ['type' => 'Label', 'caption' => 'Recognized: Siemens PAC2200, Janitza UMG (classic + UMG 800), Shelly Pro 3EM, Carlo Gavazzi EM24/ET340, WhatWatt, Phoenix EEM-EM375 and Eastron SDM72D/SDM630. For the Shelly Pro 3EM, Modbus TCP must be enabled on the device. Not found automatically: meters behind RTU/TCP gateways with a freely selectable unit ID (e.g. Socomec, MBS), meters on a ModBus gateway such as the RS485 port of the Symbox (e.g. the pure RS485 devices Eastron SDM120/220/230), the Phoenix EEM-XM, Inexogy (cloud) and the write-capable blue\'Log types RPC/Power Control — create the MeterHub instance manually there, with the Symbox using the connection path "Symbox gateway".'],
                        ['type' => 'Label', 'caption' => '🆕 A Meteocontrol blue\'Log data logger is now recognized by "🔎 Search network" on its own — no previously known IP needed. On a hit, the SCADA address range behind it is searched directly as well (setting in the panel "blue\'Log SCADA address range" below). The panel of its own there remains the faster, targeted way if the IP is already known.'],
                    ],
                ],
            ];

        // Fachpanels in Standardreihenfolge — Reihenfolge und Aufklapp-Zustand wählt der Nutzer im Panel „Formular: Aufbau“.
        $free = [
                'range' => [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔎  Search range',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'RangeStart', 'caption' => 'Start IP', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'ValidationTextBox', 'name' => 'RangeEnd',   'caption' => 'End IP',   'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Modbus TCP port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'Label', 'caption' => '💡 Applies to classic meters AND as a first attempt for blue\'Log SCADA/Power Control/RPC. If a blue\'Log runs on a DIFFERENT port than the other meters, enter the different port in the field "Modbus TCP port" in the panel "blue\'Log SCADA address range" below — the search then tries both ports automatically.'],
                        ['type' => 'ValidationTextBox', 'name' => 'IgnoreIPs', 'caption' => 'Ignore IPs (comma-separated)'],
                        ['type' => 'Label', 'caption' => 'These addresses are skipped completely during the search — e.g. other Modbus devices that would otherwise appear by mistake.'],
                        [
                            'type' => 'PopupButton', 'caption' => 'Which meters does the search find — and which not?', 'width' => '480px',
                            'popup' => [
                                'caption' => 'What the search recognizes',
                                'items' => [
                                    ['type' => 'Label', 'caption' => 'Recognized: Siemens PAC2200, Janitza UMG (classic + UMG 800), Shelly Pro 3EM, Carlo Gavazzi EM24/ET340, WhatWatt, Phoenix EEM-EM375, Eastron SDM72D/SDM630 and the go-e Controller.'],
                                    ['type' => 'Label', 'caption' => '🆕 Meteocontrol blue\'Log data loggers are additionally recognized automatically (independent of the meter search above) — on a hit, the configured SCADA address range behind it is searched right away, without knowing the IP beforehand.'],
                                    ['type' => 'Label', 'caption' => 'The search checks only a few documented default unit IDs per meter, not a full 1-247 range — if the unit ID has an unusual setting, please create the MeterHub instance manually.'],
                                    ['type' => 'Label', 'caption' => 'Not found automatically: meters behind RTU/TCP gateways with a freely selectable unit ID (e.g. Socomec, MBS), meters on a ModBus gateway such as the RS485 port of the Symbox (e.g. the pure RS485 devices Eastron SDM120/220/230), the Phoenix EEM-XM, Inexogy (cloud) and the write-capable blue\'Log types RPC/Power Control — create the MeterHub instance manually there, with the Symbox using the connection path "Symbox gateway".'],
                                    ['type' => 'Label', 'caption' => 'For the Shelly Pro 3EM, Modbus TCP must be enabled on the device first, otherwise port 502 does not answer at all.'],
                                ],
                            ],
                        ],
                        ['type' => 'Button', 'name' => 'BtnScan',  'caption' => '🔎  Search network', 'onClick' => 'MHUBD_Discover($id);'],
                        ['type' => 'Button', 'name' => 'BtnAbort', 'caption' => '✖  Abort search', 'onClick' => 'MHUBD_AbortScan($id);', 'visible' => false],
                        ['type' => 'Label', 'name' => 'ScanSummary', 'caption' => $this->ScanSummaryLine()],
                        [
                            'type'          => 'ProgressBar',
                            'name'          => 'ScanProgress',
                            'caption'       => 'Ready.',
                            'minimum'       => 0,
                            'maximum'       => 100,
                            'current'       => 0,
                            'indeterminate' => false,
                            'visible'       => false,
                        ],
                    ],
                ],
                'scada' => [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🆕 🔎  blue\'Log SCADA address range',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'For Meteocontrol blue\'Log solar park data loggers: ONE known blue\'Log, but many devices connected behind it (inverters, meters …) — each reachable via its own "SCADA address" assigned freely on the blue\'Log itself (device list on the blue\'Log → column "SCADA address").'],
                        ['type' => 'Label', 'caption' => '🆕 "🔎 Search network" above now finds a blue\'Log by itself and automatically also searches the SCADA address range set here. This panel remains the faster way if the blue\'Log IP is already known and you do not want to search the whole network — the address range below applies to BOTH ways.'],
                        ['type' => 'Label', 'caption' => '⚠️ The SCADA license is a separate, paid add-on module and is rarely used in practice — the standard license of a blue\'Log contains neither SCADA nor RPC. Before the address range search it is worth taking a look at "Check interfaces" below to see whether SCADA is licensed on this blue\'Log at all.'],
                        [
                            'type' => 'Select', 'name' => 'BlueLogHostPreset',
                            'caption' => 'Choose a known host … (from existing MeterHub instances)',
                            'options' => $blueLogHostOptions, 'value' => '',
                            'onChange' => 'MHUBD_ApplyBlueLogHostPreset($id, $BlueLogHostPreset);',
                        ],
                        ['type' => 'ValidationTextBox', 'name' => 'BlueLogHost', 'caption' => 'blue\'Log IP address', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'BlueLogPort', 'caption' => 'Modbus TCP port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'Label', 'caption' => '🆕 This port now also applies to "🔎 Search network" above — if the blue\'Log is not found there via the general search port, the search automatically also tries the port entered here.'],
                        ['type' => 'Button', 'name' => 'BtnCheckLicenses', 'caption' => '🔍  Check the interfaces of this IP (Power Control/RPC/SCADA)', 'onClick' => 'echo MHUBD_CheckBlueLogLicenses($id, $BlueLogHost, $BlueLogPort);'],
                        ['type' => 'NumberSpinner', 'name' => 'ScadaAddrStart', 'caption' => 'SCADA address from', 'minimum' => 1, 'maximum' => 247],
                        ['type' => 'NumberSpinner', 'name' => 'ScadaAddrEnd',   'caption' => 'SCADA address to',  'minimum' => 1, 'maximum' => 247],
                        ['type' => 'Label', 'caption' => 'Address 97 is usually the blue\'Log itself (total values, not relevant here) — the range of the connected individual devices is in the same column on the blue\'Log, often from 100 upwards.'],
                        ['type' => 'Label', 'caption' => '💡 Custom names instead of "Inverter (model) 1, 2, 3 …": the "Name template" in the panel "🛠️ Create" below applies to BOTH search modes — e.g. "Inverter SCADA {unitid}" or "Inverter bus {busaddr}" (bus address = column "Address" in the blue\'Log device list).'],
                        ['type' => 'Button', 'name' => 'BtnScanBlueLog',  'caption' => '🔎  Search blue\'Log address range', 'onClick' => 'MHUBD_DiscoverBlueLog($id);'],
                        ['type' => 'Button', 'name' => 'BtnAbortBlueLog', 'caption' => '✖  Abort search', 'onClick' => 'MHUBD_AbortScan($id);', 'visible' => false],
                    ],
                ],
                'create' => [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🛠️  Create',
                    'expanded' => true,
                    'items' => [
                        // Namens-Vorlage steht hier, nicht bei den Suchbereichen — sie hat
                        // nichts mit dem Suchen/Filtern zu tun, sondern nur mit dem
                        // "Erstellen"-Schritt (Dietmars Einwand 08.09.2026: "die Benennung
                        // hat eigentlich nichts mit der Suche zu tun"). Gilt für Funde BEIDER
                        // Suchmodi (IP-Bereich UND blue'Log-SCADA-Adressbereich).
                        ['type' => 'ValidationTextBox', 'name' => 'NameTemplate', 'caption' => 'Name template (empty = meter type + sequential no.)'],
                        ['type' => 'Label', 'caption' => 'Placeholders for the template: {zaehler} {ip} {unitid} {nr} {busaddr} — e.g. "{zaehler} basement ({ip})". {busaddr} (RS485 bus address) is only filled for results of the blue\'Log SCADA search, otherwise empty.'],
                        [
                            'type'     => 'Configurator',
                            'name'     => 'DiscoveryList',
                            'caption'  => 'Meters found',
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
                                ['caption' => 'Meter type',    'name' => 'meter',  'width' => 'auto'],
                                ['caption' => 'IP address',   'name' => 'ip',     'width' => '150px'],
                                ['caption' => 'Unit ID',      'name' => 'unitId', 'width' => '100px'],
                                ['caption' => 'Old instance found (Device swap)', 'name' => 'legacy', 'width' => '280px',
                                 'visible' => $this->MigrationApiPrefix() !== ''],
                            ],
                            'values' => $values,
                        ],
                        ['type' => 'Label', 'name' => 'MigrationStatusLine', 'caption' => $this->MigrationStatusLine()],
                        [
                            'type' => 'Label', 'caption' => '🔀 Migration from an old instance (another module, same IP/unit ID): first click "Create" above, then "Prepare migration" here.',
                            'visible' => $this->MigrationApiPrefix() !== '',
                        ],
                        [
                            'type' => 'PopupButton', 'caption' => 'How exactly does "Prepare migration" work?', 'width' => '480px',
                            'visible' => $this->MigrationApiPrefix() !== '',
                            'popup' => [
                                'caption' => 'Prepare migration',
                                'items' => [
                                    ['type' => 'Label', 'caption' => 'Prerequisite: Device swap (formerly MigrationsHub) is installed and knows an old instance (another module) at exactly the same IP address and unit ID as a meter found here — the column "Old instance found" shows this.'],
                                    ['type' => 'Label', 'caption' => '1. Click "Create" in the result row above. The new MeterHub instance automatically gets "Communication active" SWITCHED OFF — otherwise newly logged values would overlap with the old history that has not been transferred yet.'],
                                    ['type' => 'Label', 'caption' => '2. Click "Prepare migration" here. Links the new instance with the old instance in Device swap (creates a Device swap instance if needed).'],
                                    ['type' => 'Label', 'caption' => '3. Continue in the Device swap instance (the button "→ To the Device swap instance" appears afterwards) — simulation, confirmation and the actual takeover of the archive history deliberately remain manual steps there, each to be confirmed individually.'],
                                    ['type' => 'Label', 'caption' => 'With several hits: only ONE click on "Prepare migration" per still open migration — first finish the running one in Device swap, then click here again for the next hit.'],
                                ],
                            ],
                        ],
                        [
                            'type' => 'Button', 'name' => 'BtnPrepareMigration', 'caption' => '🔀  Prepare migration',
                            'onClick' => 'MHUBD_PrepareMigration($id);',
                            'visible' => $this->MigrationApiPrefix() !== '',
                        ],
                        ['type' => 'Label', 'name' => 'MigrationResult', 'caption' => '', 'visible' => false],
                        ['type' => 'OpenObjectButton', 'name' => 'BtnOpenMigration', 'caption' => '→ To the Device swap instance', 'objectID' => 0, 'visible' => false],
                    ],
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
                ['type' => 'Button', 'caption' => '🔄  Force apply (without a form change)', 'onClick' => MHUBD_Text::t('IPS_ApplyChanges($id); echo \'✅ ApplyChanges() executed.\';'), 'confirm' => MHUBD_Text::t('Apply the instance now (ApplyChanges)?')],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Ready.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Please enter the search IP range.'],
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
        return trim((string)preg_replace('/\s*\(.*$/u', '', MHUBD_Text::t((string)($panel['caption'] ?? $key))));
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
            ? [['type' => 'Label', 'caption' => MHUBD_Text::t('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => MHUBD_Text::t('🪟  Form: layout%s', $hidden ? MHUBD_Text::t(' (%s hidden)', count($hidden)) : ''),
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
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo MHUBD_ResetFormLayout($id);'],
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
        return MHUBD_Text::t('ℹ️ Reset to default. Click "Apply" below to save.');
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
            @$this->UpdateFormField('ScanSummary', 'caption', MHUBD_Text::t('❌ Start/end IP is missing — please fill in both fields and apply.'));
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

        $this->ShowProgress(MHUBD_Text::t('Searching %d IP addresses on port %d …', count($ips), $port), 0);

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
            $this->ShowProgress(MHUBD_Text::t('Checking meter type: %s (%d of %d open ports) …', $ip, $i, $total), (int)round(($i / max(1, $total)) * 100));
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
                        MHUBD_Text::t("blue'Log %s found — SCADA addresses %d–%d …", $ip, $batch[0], end($batch)),
                        (int)round(($i / max(1, $total)) * 100)
                    );
                    foreach ($this->identifyBlueLogDevicesBatch($ip, $blueLogPortUsed, $batch) as $blueLogFound) {
                        $results[] = $blueLogFound;
                    }
                }
            }
        }

        if ($aborted) {
            $this->ShowProgress(MHUBD_Text::t('Scan aborted – %d meters found so far.', count($results)), 100);
        } else {
            $blueLogNote = $blueLogsFound > 0 ? MHUBD_Text::t(" (including %d blue'Log(s) with their SCADA devices)", $blueLogsFound) : '';
            $this->ShowProgress(MHUBD_Text::t('Done: %d meters found (of %d open ports)%s.', count($results), $total, $blueLogNote), 100);
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
            @$this->UpdateFormField('ScanSummary', 'caption', MHUBD_Text::t('❌ blue\'Log IP address is missing — please enter it and apply.'));
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

        $this->ShowProgress(MHUBD_Text::t('Checking %d SCADA addresses at %s:%d …', count($addrs), $host, $port), 0);

        $results = [];
        $total   = count($addrs);
        $done    = 0;
        $aborted = $this->scanAborted();
        foreach (array_chunk($addrs, self::BLUELOG_SCAN_BATCH_SIZE) as $batch) {
            if ($this->scanAborted()) { $aborted = true; break; }
            $this->ShowProgress(
                MHUBD_Text::t('SCADA addresses %d–%d (%d of %d) …', $batch[0], end($batch), $done, $total),
                (int)round(($done / max(1, $total)) * 100)
            );
            foreach ($this->identifyBlueLogDevicesBatch($host, $port, $batch) as $found) {
                $results[] = $found;
            }
            $done += count($batch);
        }

        if ($aborted) {
            $this->ShowProgress(MHUBD_Text::t('Search aborted – %d devices found so far.', count($results)), 100);
        } else {
            $this->ShowProgress(MHUBD_Text::t('Done: %d devices found (of %d checked addresses).', count($results), $total), 100);
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
            $label   = MHUBD_Text::t(self::BLUELOG_TYPE_LABELS[$type]) . ($model !== '' ? " ($model)" : '');
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
     * Präfix der Übernahme-Schnittstelle des Partnermoduls: `GTAUSCH_` (Gerätetausch, vormals
     * MigrationsHub, Umbenennung 13.10.2026 — gleiche Modul-GUID, gleiche Parameter), ersatzweise
     * der alte `MIGHUB_`. Neuer Präfix zuerst, damit die Reihenfolge der Pushes beider Seiten
     * egal ist. '' = Partnermodul nicht installiert. $needPrefill: auch PrefillMigration nötig.
     */
    private function MigrationApiPrefix(bool $needPrefill = false): string
    {
        if (function_exists('GTAUSCH_FindLegacyCandidates') && (!$needPrefill || function_exists('GTAUSCH_PrefillMigration'))) {
            return 'GTAUSCH';
        }
        if (function_exists('MIGHUB_FindLegacyCandidates') && (!$needPrefill || function_exists('MIGHUB_PrefillMigration'))) {
            return 'MIGHUB';
        }
        return '';
    }

    /**
     * Live berechnete Statuszeile zur MigrationsHub-Kopplung (SUITE.md, „Verbund-
     * Verbindungen im Formular sichtbar machen", verbindlich seit 21.09.2026): sagt, ob die
     * Kopplung gerade zustande kommt und was sonst gilt. Steht IMMER im Formular, auch wenn
     * MigrationsHub fehlt (die Migrations-Knöpfe sind dann ausgeblendet, der Grund dafür nicht).
     */
    private function MigrationStatusLine(): string
    {
        if ($this->MigrationApiPrefix() === '') {
            return MHUBD_Text::t('ℹ️ Device swap (formerly MigrationsHub) is not installed — taking over old instances including their measurement history is not available. Meters found can still be created as a MeterHub instance.');
        }
        $ids = IPS_GetInstanceListByModuleID(self::MIGRATIONSHUB_GUID);
        if (!$ids) {
            return MHUBD_Text::t('ℹ️ Device swap is installed but has no instance yet — "Prepare migration" creates it on the first click; the column "Old instance found" stays empty until then.');
        }
        $id = (int)$ids[0];
        if (count($ids) > 1) {
            return MHUBD_Text::t('⚠️ Several Device swap instances found (%s) — the first one is used, #%d "%s". Please do not use the others for the same takeover.', implode(', ', array_map(fn($i) => '#' . $i, $ids)), $id, IPS_GetName($id));
        }
        return MHUBD_Text::t('✅ Device swap instance #%d "%s" found — old instances with the same IP and unit ID appear in the column "Old instance found".', $id, IPS_GetName($id));
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
     * 2. FindLegacyCandidates (GTAUSCH_/MIGHUB_) hat inzwischen 5 Parameter
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
        $api = $this->MigrationApiPrefix();
        if ($api === '') {
            return ['id' => 0, 'name' => ''];
        }
        $migIDs = IPS_GetInstanceListByModuleID(self::MIGRATIONSHUB_GUID);
        $migID  = (int)($migIDs[0] ?? 0);
        if ($migID <= 0) {
            return ['id' => 0, 'name' => ''];
        }
        try {
            $found = $api === 'GTAUSCH'
                ? GTAUSCH_FindLegacyCandidates($migID, $host, $this->ReadPropertyInteger('Port'), $unitId, $excludeInstanceID)
                : MIGHUB_FindLegacyCandidates($migID, $host, $this->ReadPropertyInteger('Port'), $unitId, $excludeInstanceID);
        } catch (\Throwable $e) {
            $this->SendDebug('LegacyCandidateFor', $api . '_FindLegacyCandidates fehlgeschlagen: ' . $e->getMessage(), 0);
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
     * PrefillMigration() des Partnermoduls (GTAUSCH_, ersatzweise MIGHUB_) auf. Absichtlich nur EIN Treffer je Klick:
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
        $api = $this->MigrationApiPrefix(true);
        if ($api === '') {
            $say(MHUBD_Text::t('❌ Device swap (formerly MigrationsHub) is not installed.'));
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

            if ($api === 'GTAUSCH') {
                GTAUSCH_PrefillMigration($migID, $legacy['id'], $targetID);
            } else {
                MIGHUB_PrefillMigration($migID, $legacy['id'], $targetID);
            }

            $say(MHUBD_Text::t('✅ Migration prepared: "%s" (#%d) → "%s" (#%d). Continue in the Device swap instance — simulate, check and run there.', $legacy['name'], $legacy['id'], IPS_GetName($targetID), $targetID));
            $this->UpdateFormField('BtnOpenMigration', 'objectID', $migID);
            $this->UpdateFormField('BtnOpenMigration', 'visible', true);
            return;
        }

        $say(MHUBD_Text::t('🔎 No matching combination of an already created MeterHub instance and an old instance found — first click "Create" above.'));
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
                    MHUBD_Text::t('Port scan running … %d open, %d of %d still pending', count($open), count($pending), $totalOpen),
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
                        'label'  => MHUBD_Text::t(self::METER_LABELS[$meter]),
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
