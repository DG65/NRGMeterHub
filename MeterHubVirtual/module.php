<?php

// ---------------------------------------------------------------------------
// MeterHubVirtual — virtuelle Zähler aus der VERDRAHTUNG statt aus Formeln.
//
// Statt Rechenoperationen zu konfigurieren („A − B − C“) beschreibt man, welcher
// Zähler hinter welchem sitzt. Die Rechnung leitet das Modul daraus ab:
//
//   Summe            = Summe aller direkt untergeordneten Zähler
//   Rest             = eigener Zähler − Summe der untergeordneten
//
// Der Vorteil ist nicht die Bequemlichkeit, sondern die Fehlersicherheit: Weil
// jeder Zähler im Baum genau EINEN Platz hat, kann er nicht zweimal abgezogen
// werden. Ein doppelter Abzug müsste denselben Zähler an zwei Stellen hängen —
// das lässt die Struktur nicht zu. Was der Baum nicht ausschließt (Zyklen,
// derselbe Datenpunkt in zwei Knoten, gemischte Einheiten), prüft Validate().
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
        'aircon'     => ['Klimaanlage',              'Snowflake'],
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
        'pool'       => ['Pool',                     'Waves'],
        'sauna'      => ['Sauna',                    'Flame'],
        'light'      => ['Beleuchtung',              'Bulb'],
        'it'         => ['Server / Netzwerk',        'Gear'],
        'workshop'   => ['Werkstatt',                'Gear'],
        'other'      => ['Sonstiger Verbraucher',    'Electricity'],
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        // Verdrahtung: [{Key,Name,Parent,PowerID,EnergyImportID,EnergyExportID,Function}]
        $this->RegisterPropertyString('Nodes', '[]');
        $this->RegisterPropertyInteger('Interval', 10);
        $this->RegisterTimer('Recalc', 0, 'MHUBV_Recalc($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $errors = $this->Validate();
        $this->RegisterVariables($errors);

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
    // Verdrahtung lesen und prüfen
    // -----------------------------------------------------------------------

    private function Nodes(): array
    {
        $rows = json_decode($this->ReadPropertyString('Nodes'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $key = strtolower(trim((string)($r['Key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $out[$key] = [
                'key'    => $key,
                'name'   => trim((string)($r['Name'] ?? '')) ?: $key,
                'parent' => strtolower(trim((string)($r['Parent'] ?? ''))),
                'power'  => (int)($r['PowerID'] ?? 0),
                'imp'    => (int)($r['EnergyImportID'] ?? 0),
                'exp'    => (int)($r['EnergyExportID'] ?? 0),
                'func'   => (string)($r['Function'] ?? 'none'),
            ];
        }
        return $out;
    }

    /** Direkte Kinder je Knoten. */
    private function Children(array $nodes): array
    {
        $kids = [];
        foreach ($nodes as $k => $n) {
            if ($n['parent'] !== '' && isset($nodes[$n['parent']])) {
                $kids[$n['parent']][] = $k;
            }
        }
        return $kids;
    }

    /**
     * Prüft, was die Baumstruktur NICHT schon ausschließt. Rückgabe: Liste von
     * Klartext-Fehlern (leer = in Ordnung).
     */
    private function Validate(): array
    {
        $rows = json_decode($this->ReadPropertyString('Nodes'), true);
        $rows = is_array($rows) ? $rows : [];
        $errors = [];
        $seenKeys = [];
        $usedVars = [];

        foreach ($rows as $i => $r) {
            $nr   = $i + 1;
            $key  = strtolower(trim((string)($r['Key'] ?? '')));
            $name = trim((string)($r['Name'] ?? ''));

            if ($key === '') {
                $errors[] = "Zeile $nr: Kürzel fehlt. Es dient als technischer Name und als Bezug für „hängt hinter“.";
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $key)) {
                $errors[] = "Zeile $nr („{$key}“): Kürzel darf nur Kleinbuchstaben, Ziffern und _ enthalten.";
            }
            if (isset($seenKeys[$key])) {
                $errors[] = "Kürzel „{$key}“ ist doppelt vergeben (Zeilen {$seenKeys[$key]} und $nr). Kürzel müssen eindeutig sein.";
            } else {
                $seenKeys[$key] = $nr;
            }

            // Derselbe Datenpunkt in zwei Knoten = der Zähler hinge an zwei
            // Stellen. Genau das würde zu doppeltem Abzug führen.
            foreach ([['PowerID', 'Leistung'], ['EnergyImportID', 'Bezug'], ['EnergyExportID', 'Einspeisung']] as [$f, $lbl]) {
                $vid = (int)($r[$f] ?? 0);
                if ($vid <= 0) {
                    continue;
                }
                if (!IPS_VariableExists($vid)) {
                    $errors[] = "Zeile $nr („{$key}“): $lbl verweist auf Variable #$vid, die es nicht gibt.";
                    continue;
                }
                if (isset($usedVars[$vid])) {
                    $errors[] = "Variable #$vid ($lbl) wird in „{$usedVars[$vid]}“ und „{$key}“ verwendet. Ein Zähler darf nur an EINER Stelle hängen — sonst würde er doppelt gerechnet.";
                } else {
                    $usedVars[$vid] = $key;
                }
            }
        }

        $nodes = $this->Nodes();

        // Elternbezug muss existieren, und niemand ist sein eigener Vorfahr.
        foreach ($nodes as $k => $n) {
            if ($n['parent'] === '') {
                continue;
            }
            if (!isset($nodes[$n['parent']])) {
                $errors[] = "„{$k}“ hängt hinter „{$n['parent']}“, das es nicht gibt.";
                continue;
            }
            $seen = [$k => true];
            $cur  = $n['parent'];
            while ($cur !== '' && isset($nodes[$cur])) {
                if (isset($seen[$cur])) {
                    $errors[] = "Ringschluss in der Verdrahtung bei „{$k}“ — ein Zähler kann nicht hinter sich selbst hängen.";
                    break;
                }
                $seen[$cur] = true;
                $cur = $nodes[$cur]['parent'];
            }
        }

        // Gemischte Einheiten innerhalb einer Rechnung ergeben stillschweigend
        // falsche Werte — daher aktiv prüfen statt nur zu dokumentieren.
        foreach ($this->Children($nodes) as $parent => $kids) {
            foreach ([['power', 'Leistung'], ['imp', 'Bezug'], ['exp', 'Einspeisung']] as [$f, $lbl]) {
                $units = [];
                foreach (array_merge([$parent], $kids) as $k) {
                    $vid = $nodes[$k][$f] ?? 0;
                    if ($vid > 0 && IPS_VariableExists($vid)) {
                        $u = $this->UnitOf($vid);
                        if ($u !== '') {
                            $units[$u][] = $k;
                        }
                    }
                }
                if (count($units) > 1) {
                    $parts = [];
                    foreach ($units as $u => $ks) {
                        $parts[] = $u . ' (' . implode(', ', $ks) . ')';
                    }
                    $errors[] = "Unter „{$parent}“ haben die $lbl-Datenpunkte verschiedene Einheiten: " . implode(' vs. ', $parts) . ". Das ergäbe still falsche Werte — bitte auf eine Einheit bringen.";
                }
            }
        }

        return $errors;
    }

    /** Einheit (Profil-Suffix) einer Variable, leer wenn unbekannt. */
    private function UnitOf(int $vid): string
    {
        $v = @IPS_GetVariable($vid);
        if (!$v) {
            return '';
        }
        $p = $v['VariableCustomProfile'] !== '' ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        if ($p === '' || !IPS_VariableProfileExists($p)) {
            return '';
        }
        return trim(IPS_GetVariableProfile($p)['Suffix']);
    }

    // -----------------------------------------------------------------------
    // Variablen
    // -----------------------------------------------------------------------

    /** Auszugebende Variablen: [ident, caption, profil, quelle-feld, art]. */
    private function OutputDefs(): array
    {
        $nodes = $this->Nodes();
        $kids  = $this->Children($nodes);
        $defs  = [];
        foreach ($kids as $parent => $list) {
            $n = $nodes[$parent];
            foreach ([['power', 'MHB.W', 'Leistung'], ['imp', 'MHB.kWh', 'Bezug'], ['exp', 'MHB.kWh', 'Einspeisung']] as [$f, $prof, $lbl]) {
                // Summe der untergeordneten Zähler
                $defs[] = [$parent . '_sum_' . $f, $n['name'] . ': ' . $lbl . ' untergeordnet', $prof, $f, 'sum', $parent];
                // Rest nur, wenn der Knoten einen eigenen Zähler hat
                if ($n[$f] > 0) {
                    $defs[] = [$parent . '_rest_' . $f, $n['name'] . ': ' . $lbl . ' Rest', $prof, $f, 'rest', $parent];
                }
            }
        }
        return $defs;
    }

    private function RegisterVariables(array $errors)
    {
        $defs  = $errors ? [] : $this->OutputDefs();
        $valid = [];
        foreach ($defs as $d) {
            $valid[$d[0]] = true;
        }
        // Reste einer früheren Verdrahtung entfernen.
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] === 2 && $o['ObjectIdent'] !== '' && !isset($valid[$o['ObjectIdent']])) {
                @IPS_DeleteVariable($cid);
            }
        }
        $pos = 0;
        foreach ($defs as [$ident, $caption, $profile]) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if (!$vid) {
                $vid = IPS_CreateVariable(VARIABLETYPE_FLOAT);
                IPS_SetIdent($vid, $ident);
                IPS_SetParent($vid, $this->InstanceID);
            }
            IPS_SetName($vid, $caption);
            IPS_SetPosition($vid, $pos++);
            if (@IPS_GetVariable($vid)['VariableCustomProfile'] !== $profile) {
                IPS_SetVariableCustomProfile($vid, $profile);
            }
            $this->SetArchive($vid);
        }
    }

    private function SetArchive($vid)
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($ids) > 0) {
            AC_SetLoggingStatus($ids[0], $vid, true);
            AC_SetAggregationType($ids[0], $vid, 0);
        }
    }

    private function CreateProfiles()
    {
        foreach ([['MHB.W', ' W', 0], ['MHB.kWh', ' kWh', 1]] as [$n, $suf, $dig]) {
            if (!IPS_VariableProfileExists($n)) {
                IPS_CreateVariableProfile($n, VARIABLETYPE_FLOAT);
            }
            IPS_SetVariableProfileDigits($n, $dig);
            IPS_SetVariableProfileText($n, '', $suf);
        }
    }

    // -----------------------------------------------------------------------
    // Berechnung
    // -----------------------------------------------------------------------

    public function Recalc()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $nodes = $this->Nodes();
        $kids  = $this->Children($nodes);

        foreach ($this->OutputDefs() as [$ident, , , $field, $kind, $parent]) {
            $sum = 0.0;
            foreach ($kids[$parent] as $k) {
                $vid = $nodes[$k][$field] ?? 0;
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $sum += (float)GetValue($vid);
                }
            }
            $val = $sum;
            if ($kind === 'rest') {
                $own = $nodes[$parent][$field] ?? 0;
                $val = ($own > 0 && IPS_VariableExists($own)) ? ((float)GetValue($own) - $sum) : 0.0;
            }
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid && is_finite($val)) {
                SetValueFloat($vid, $val);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Vertrag (siehe Konvention in CLAUDE.md) — gleiche Struktur wie
    // MHUB_GetFunctions, damit Kachel und Sankey virtuelle Zähler wie echte
    // übernehmen können.
    // -----------------------------------------------------------------------

    public function GetFunctions(): string
    {
        $nodes = $this->Nodes();
        $kids  = $this->Children($nodes);
        $list  = [];
        foreach ($kids as $parent => $list_) {
            $n = $nodes[$parent];
            if ($n['func'] === '' || $n['func'] === 'none' || !isset(self::FUNCTIONS[$n['func']])) {
                continue;
            }
            $id = function (string $suffix) use ($parent) {
                return (int)@IPS_GetObjectIDByIdent($parent . $suffix, $this->InstanceID);
            };
            $list[] = [
                'slot'           => $parent,
                'function'       => $n['func'],
                'label'          => $n['name'],
                'powerID'        => $id('_rest_power') ?: $id('_sum_power'),
                'energyImportID' => $id('_rest_imp')   ?: $id('_sum_imp'),
                'energyExportID' => $id('_rest_exp')   ?: $id('_sum_exp'),
                'measured'       => true, // Rechenergebnis gemessener Zähler
            ];
        }
        return json_encode([
            'instanceID'  => $this->InstanceID,
            'meter'       => 'virtual',
            'measureMode' => 'combined',
            'assignments' => $list,
        ]);
    }

    // -----------------------------------------------------------------------
    // Konfigurationsformular (dynamisch: Elternauswahl aus den Kürzeln)
    // -----------------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $nodes  = $this->Nodes();
        $errors = $this->Validate();

        $parentOptions = [['caption' => '— oberste Ebene —', 'value' => '']];
        foreach ($nodes as $k => $n) {
            $parentOptions[] = ['caption' => $n['name'] . '  [' . $k . ']', 'value' => $k];
        }
        $funcOptions = [];
        foreach (self::FUNCTIONS as $key => $def) {
            $funcOptions[] = ['caption' => $def[0], 'value' => $key];
        }

        // Prüfergebnis und Baumvorschau als Klartext.
        $check = [];
        if (count($errors) > 0) {
            $check[] = ['type' => 'Label', 'caption' => '❌ ' . count($errors) . ' Problem(e) — solange sie bestehen, wird nicht gerechnet:'];
            foreach ($errors as $e) {
                $check[] = ['type' => 'Label', 'caption' => '   • ' . $e];
            }
        } elseif (count($nodes) === 0) {
            $check[] = ['type' => 'Label', 'caption' => 'Noch keine Verdrahtung angelegt.'];
        } else {
            $check[] = ['type' => 'Label', 'caption' => '✅ Verdrahtung schlüssig. Vorschau:'];
            foreach ($this->TreePreview($nodes) as $line) {
                $check[] = ['type' => 'Label', 'caption' => $line];
            }
        }

        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Dokumentation & Hilfe', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Bildet virtuelle Zähler, indem die VERDRAHTUNG beschrieben wird statt einer Formel: Für jeden Zähler wird angegeben, hinter welchem er sitzt. Daraus ergibt sich je Knoten mit Untergeordneten automatisch die „Summe untergeordnet“ und — falls der Knoten einen eigenen Zähler hat — der „Rest“ (eigener Zähler minus Untergeordnete).'],
                        ['type' => 'Label', 'caption' => 'Beispiel: „Hausanschluss“ (eigener Zähler) mit den untergeordneten „Wärmepumpe“ und „Wallbox“ ergibt „Hausanschluss: Leistung Rest“ — also alles, was weder Wärmepumpe noch Wallbox verbraucht.'],
                        ['type' => 'Label', 'caption' => '🛡️ Warum keine freie Formel: Weil jeder Zähler im Baum genau EINEN Platz hat, kann er nicht doppelt abgezogen werden. Was die Struktur nicht verhindert (derselbe Datenpunkt in zwei Zeilen, Ringschlüsse, gemischte Einheiten), meldet die Prüfung unten — und solange etwas offen ist, wird bewusst nicht gerechnet.'],
                        ['type' => 'Label', 'caption' => 'Das Kürzel ist der technische Name: Es bildet die Variablen-Idents und dient als Bezug für „hängt hinter“. Die Bezeichnung ist frei änderbar, das Kürzel sollte stehen bleiben — sonst entstehen neue Variablen und die Historie der alten geht verloren.'],
                        ['type' => 'Label', 'caption' => 'Einheiten: Leistung in W, Energie als kumulative kWh-Zählerstände. Alle Datenpunkte eines Knotens müssen dieselbe Einheit haben; Abweichungen meldet die Prüfung.'],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Berechnung aktiv'],
                [
                    'type' => 'ExpansionPanel', 'caption' => '🔌  Verdrahtung', 'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Neue Zeilen erscheinen erst nach „Übernehmen“ in der Auswahl „hängt hinter“ — zuerst die Zeile anlegen und übernehmen, dann verdrahten.'],
                        [
                            'type' => 'List', 'name' => 'Nodes', 'caption' => 'Zähler und ihre Verdrahtung',
                            'rowCount' => 8, 'add' => true, 'delete' => true,
                            'columns' => [
                                ['caption' => 'Kürzel', 'name' => 'Key', 'width' => '130px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'Bezeichnung', 'name' => 'Name', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'hängt hinter', 'name' => 'Parent', 'width' => '190px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $parentOptions]],
                                ['caption' => 'Leistung (W)', 'name' => 'PowerID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                                ['caption' => 'Bezug (kWh)', 'name' => 'EnergyImportID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                                ['caption' => 'Einspeisung (kWh)', 'name' => 'EnergyExportID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                                ['caption' => 'Funktion', 'name' => 'Function', 'width' => '190px', 'add' => 'none', 'edit' => ['type' => 'Select', 'options' => $funcOptions]],
                            ],
                        ],
                    ],
                ],
                ['type' => 'ExpansionPanel', 'caption' => '🔎  Prüfung & Vorschau', 'expanded' => true, 'items' => $check],
                [
                    'type' => 'ExpansionPanel', 'caption' => '⏱️  Berechnung', 'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Neu berechnen alle', 'minimum' => 2, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt neu berechnen', 'onClick' => 'MHUBV_Recalc($id);'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Berechnung aktiv.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Berechnung deaktiviert.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Verdrahtung unvollständig oder widersprüchlich — siehe Prüfung.'],
            ],
        ];
        return json_encode($form);
    }

    /** Baum als eingerückte Zeilen (Vorschau im Formular). */
    private function TreePreview(array $nodes): array
    {
        $kids  = $this->Children($nodes);
        $lines = [];
        $walk = function (string $k, int $depth) use (&$walk, $nodes, $kids, &$lines) {
            $n    = $nodes[$k];
            $pre  = str_repeat('    ', $depth) . ($depth > 0 ? '└─ ' : '');
            $what = [];
            if ($n['power'] > 0) { $what[] = 'W'; }
            if ($n['imp'] > 0)   { $what[] = 'Bezug'; }
            if ($n['exp'] > 0)   { $what[] = 'Einsp.'; }
            $tail = $what ? ' (' . implode('/', $what) . ')' : ' (ohne eigenen Zähler)';
            if (isset($kids[$k])) {
                $tail .= ' → Summe' . ($n['power'] > 0 || $n['imp'] > 0 ? ' + Rest' : '');
            }
            $lines[] = $pre . $n['name'] . ' [' . $k . ']' . $tail;
            foreach ($kids[$k] ?? [] as $c) {
                $walk($c, $depth + 1);
            }
        };
        foreach ($nodes as $k => $n) {
            if ($n['parent'] === '' || !isset($nodes[$n['parent']])) {
                $walk($k, 0);
            }
        }
        return $lines;
    }
}
