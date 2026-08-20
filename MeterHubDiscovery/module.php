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

    public function Create()
    {
        parent::Create();

        $prefix = $this->guessLocalSubnetPrefix();
        $this->RegisterPropertyString('RangeStart', $prefix !== '' ? $prefix . '.1'   : '');
        $this->RegisterPropertyString('RangeEnd',   $prefix !== '' ? $prefix . '.254' : '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyString('NameTemplate', '');
        $this->RegisterPropertyString('IgnoreIPs', '');
        $this->RegisterAttributeString('ResultsJSON', '[]');
        // Für die Status-Kopfzeile (siehe ScanSummaryLine()) — Verbund-Konvention
        // „Einheitliche Verbund-Status-Kopfzeile" (SUITE.md, 20.08.2026).
        $this->RegisterAttributeInteger('LastScanTs', 0);
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
        $count = count(json_decode($this->ReadAttributeString('ResultsJSON'), true) ?: []);
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

    public function GetConfigurationForm()
    {
        $results = json_decode($this->ReadAttributeString('ResultsJSON'), true);
        if (!is_array($results)) {
            $results = [];
        }

        $existing = $this->findExistingInstances();
        $template = trim($this->ReadPropertyString('NameTemplate'));

        $meterCounter = [];
        $values = [];
        foreach ($results as $r) {
            $key = $r['ip'] . '|' . $r['unitId'];
            $meterCounter[$r['meter']] = ($meterCounter[$r['meter']] ?? 0) + 1;
            $nr = $meterCounter[$r['meter']];

            if ($template !== '') {
                $instanceName = str_replace(
                    ['{zaehler}', '{ip}', '{unitid}', '{nr}'],
                    [$r['label'], $r['ip'], $r['unitId'], $nr],
                    $template
                );
            } else {
                $instanceName = $r['label'] . ' ' . $nr;
            }

            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId']);
            $config = [
                'Host'   => $r['ip'],
                'Port'   => $this->ReadPropertyInteger('Port'),
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
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Durchsucht einen IP-Bereich im lokalen Netz nach Energiezählern auf Modbus-TCP-Port 502 und erkennt den Zählertyp anhand eines charakteristischen Registers (Frequenz + Spannung als Plausibilitätsprüfung).'],
                        ['type' => 'Label', 'caption' => 'Start- und End-IP eintragen (Vorschlag anhand des eigenen Netzwerks ist schon ausgefüllt), dann „Netzwerk durchsuchen" klicken. Gefundene Zähler erscheinen unten — Klick auf „Erstellen" legt eine MeterHub-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Zählertyp an.'],
                        ['type' => 'Label', 'caption' => '🔀 Neue Instanz kommt mit „Kommunikation aktiv" bereits eingeschaltet. Falls ein Umstieg von einem anderen Zähler-/Hub-Modul mit Übernahme der Messhistorie geplant ist: direkt nach dem Anlegen an der neuen MeterHub-Instanz wieder ausschalten, bis MigrationsHub die alte Historie übernommen hat — sonst überlappen sich die neu geloggten Werte mit der übertragenen Alt-Historie.'],
                        ['type' => 'Label', 'caption' => 'Die Suche prüft nur wenige dokumentierte Standard-Unit-IDs je Zähler, keinen vollen 1-247-Bereich — bei exotisch konfigurierter Unit-ID bitte die MeterHub-Instanz manuell anlegen.'],
                        ['type' => 'Label', 'caption' => 'Erkannt werden: Siemens PAC2200, Janitza UMG (klassisch + UMG 800), Shelly Pro 3EM, Carlo Gavazzi EM24/ET340, WhatWatt, Phoenix EEM-EM375 und Eastron SDM72D/SDM630. Beim Shelly Pro 3EM muss Modbus TCP am Gerät aktiviert sein. Zähler hinter RTU/TCP-Gateways mit frei wählbarer Unit-ID (z. B. Socomec, MBS) werden nicht automatisch gefunden — dort die Instanz manuell anlegen.'],
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
                        ['type' => 'ValidationTextBox', 'name' => 'NameTemplate', 'caption' => 'Name-Vorlage (leer = Zählertyp + lfd. Nr.)'],
                        ['type' => 'Label', 'caption' => 'Platzhalter für die Vorlage: {zaehler} {ip} {unitid} {nr} — z. B. „{zaehler} Keller ({ip})"'],
                        ['type' => 'ValidationTextBox', 'name' => 'IgnoreIPs', 'caption' => 'IPs ignorieren (Komma-getrennt)'],
                        ['type' => 'Label', 'caption' => 'Diese Adressen werden bei der Suche komplett übersprungen — z. B. andere Modbus-Geräte, die sonst fälschlich erscheinen würden.'],
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
                    'caption' => '🛠️  Erstellen',
                    'expanded' => true,
                    'items' => [
                        [
                            'type'     => 'Configurator',
                            'name'     => 'DiscoveryList',
                            'caption'  => 'Gefundene Zähler',
                            'rowCount' => 6,
                            'delete'   => false,
                            'sort'     => ['column' => 'ip', 'direction' => 'ascending'],
                            'columns'  => [
                                ['caption' => 'Zählertyp',    'name' => 'meter',  'width' => '220px'],
                                ['caption' => 'IP-Adresse',   'name' => 'ip',     'width' => '150px'],
                                ['caption' => 'Unit ID',      'name' => 'unitId', 'width' => '100px'],
                                ['caption' => 'Alt-Instanz gefunden (MigrationsHub)', 'name' => 'legacy', 'width' => '280px',
                                 'visible' => function_exists('MIGHUB_FindLegacyCandidates')],
                            ],
                            'values' => $values,
                        ],
                        [
                            'type' => 'Label', 'caption' => '🔀 Migration von einer Alt-Instanz (anderes Modul, gleiche IP/Unit-ID): erst oben „Erstellen" klicken — Kommunikation bleibt bei erkannter Alt-Instanz automatisch aus —, dann hier „Migration vorbereiten". Verknüpft die neue mit der alten Instanz in MigrationsHub; Simulation, Bestätigung und Ausführung bleiben dort bewusst manuelle Schritte. Bei mehreren Treffern: nach jeder abgeschlossenen Migration erneut klicken.',
                            'visible' => function_exists('MIGHUB_FindLegacyCandidates'),
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

        $results = [];
        $total   = count($openIps);
        $i       = 0;
        $aborted = $this->scanAborted();
        foreach ($openIps as $ip) {
            if ($this->scanAborted()) { $aborted = true; break; }
            $i++;
            $this->ShowProgress("Prüfe Zählertyp: $ip ($i von $total offenen Ports) …", (int)round(($i / max(1, $total)) * 100));
            $found = $this->identifyMeter($ip, $port);
            if ($found !== null) {
                $results[] = $found;
            }
        }

        if ($aborted) {
            $this->ShowProgress('Scan abgebrochen – ' . count($results) . ' Zähler bis dahin gefunden.', 100);
        } else {
            $this->ShowProgress('Fertig: ' . count($results) . ' Zähler gefunden (von ' . $total . ' offenen Ports).', 100);
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
     */
    private function LegacyCandidateFor(string $host, int $unitId): array
    {
        if (!function_exists('MIGHUB_FindLegacyCandidates')) {
            return ['id' => 0, 'name' => ''];
        }
        $found = @MIGHUB_FindLegacyCandidates($this->InstanceID, $host, $this->ReadPropertyInteger('Port'), $unitId);
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

        $results = json_decode($this->ReadAttributeString('ResultsJSON'), true);
        $results = is_array($results) ? $results : [];
        $existing = $this->findExistingInstances();

        foreach ($results as $r) {
            $targetID = $existing[$r['ip'] . '|' . $r['unitId']] ?? 0;
            if ($targetID <= 0) {
                continue; // Für diese Zeile wurde noch keine MeterHub-Instanz erstellt.
            }
            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId']);
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

            $migIDs = IPS_GetInstanceListByModuleID(self::MIGRATIONSHUB_GUID);
            $migID = $migIDs[0] ?? 0;
            if ($migID <= 0) {
                $migID = IPS_CreateInstance(self::MIGRATIONSHUB_GUID);
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
