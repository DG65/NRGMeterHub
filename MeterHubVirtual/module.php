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
        'pool'       => ['Pool',                     'Waves'],
        'sauna'      => ['Sauna',                    'Flame'],
        'light'      => ['Beleuchtung',              'Bulb'],
        'it'         => ['Server / Netzwerk',        'Gear'],
        'workshop'   => ['Werkstatt',                'Gear'],
        'other'      => ['Sonstiger Verbraucher',    'Electricity'],
    ];

    // Formular-Konvention des Verbunds (SUITE.md „Einheitliche Formular-
    // Optik", Referenz InverterHub). NEWS_VERSION korrespondiert mit dem
    // CHANGELOG-Eintrag, der den jeweiligen Sprung erklärt.
    private const NEWS_VERSION = '0.24.0';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        // Formel: [{Name,Sign('+'|'-'),PowerID,EnergyImportID,EnergyExportID}]
        $this->RegisterPropertyString('Nodes', '[]');
        $this->RegisterPropertyString('Function', 'none');
        // Rein informativer Standort (Raum/Geschoss) — Dietmars Anregung
        // 31.08.2026, bewusst GETRENNT von "Function": "Function" ist ein
        // fester Vertrag mit dem Dashboard/InverterHubTile (Icon-Mapping in
        // einem anderen Repo), ein freier Raum-/Geschossname hätte dort kein
        // passendes Icon. "Location" ist reines Freitext-Label ohne Vertrag.
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyInteger('Interval', 10);
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
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterTimer('Recalc', 0, 'MHUBV_Recalc($_IPS[\'TARGET\']);');
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
                ['type' => 'Label', 'caption' => '• Komplett neues, einfacheres Modell: Diese Instanz ist jetzt selbst die oberste Ebene. Jede Zeile ist ein Term mit Vorzeichen (+/−) — kein „Kürzel“, kein „hängt hinter“, keine Sammelzeilen mehr.'],
                ['type' => 'Label', 'caption' => '• Die Funktion (fürs Dashboard) wird jetzt einmal für die ganze Instanz gesetzt, nicht mehr pro Zeile.'],
                ['type' => 'Label', 'caption' => '• Mehrstufige Verschachtelung (z. B. ein Zwischenwert aus mehreren Zählern, von dem dann wieder etwas abgezogen wird) geht jetzt über mehrere verkettete Instanzen statt innerhalb einer einzigen — Details im Doku-Panel unten.'],
                ['type' => 'Label', 'caption' => '• Schon verdrahtete Instanzen brauchen eine einmalige Bestätigung: ein Migrations-Panel zeigt die bisherigen Zeilen als Vorschlag, nichts wird automatisch übernommen.'],
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

    /** Alte Baum-Zeilen ins neue flache Format übertragen — Vorzeichen immer „+“, der Rest bleibt dem Formular zur Prüfung überlassen. */
    private function MigratedRows(array $rawRows): array
    {
        $out = [];
        foreach ($rawRows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = [
                'Name'           => trim((string)($r['Name'] ?? '')) ?: 'Unbenannt',
                'Sign'           => '+',
                'PowerID'        => (int)($r['PowerID'] ?? 0),
                'EnergyImportID' => (int)($r['EnergyImportID'] ?? 0),
                'EnergyExportID' => (int)($r['EnergyExportID'] ?? 0),
            ];
        }
        return $out;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $rawRows = json_decode($this->ReadPropertyString('Nodes'), true);
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
    // Formel lesen und prüfen
    // -----------------------------------------------------------------------

    /** Normalisierte Zeilen der Formel (Liste, keine Baum-Beziehung mehr). */
    private function Nodes(): array
    {
        $rows = json_decode($this->ReadPropertyString('Nodes'), true);
        $rows = is_array($rows) ? $rows : [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = [
                'name'  => trim((string)($r['Name'] ?? '')),
                'sign'  => ((string)($r['Sign'] ?? '+')) === '-' ? '-' : '+',
                'power' => (int)($r['PowerID'] ?? 0),
                'imp'   => (int)($r['EnergyImportID'] ?? 0),
                'exp'   => (int)($r['EnergyExportID'] ?? 0),
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
        $errors = [];

        // Derselbe Datenpunkt in zwei Zeilen würde ihn doppelt zählen —
        // unabhängig vom Vorzeichen. Das ist die eigentliche Absicherung
        // gegen Doppelzählung, nicht die Formel-Struktur selbst.
        $usedVars = [];
        foreach ($nodes as $i => $n) {
            $nr = $i + 1;
            foreach ([['power', 'PowerID', 'Leistung'], ['imp', 'EnergyImportID', 'Bezug'], ['exp', 'EnergyExportID', 'Einspeisung']] as [$f, , $lbl]) {
                $vid = $n[$f];
                if ($vid <= 0) {
                    continue;
                }
                if (!IPS_VariableExists($vid)) {
                    $errors[] = "Zeile $nr: $lbl verweist auf Variable #$vid, die es nicht gibt.";
                    continue;
                }
                if (isset($usedVars[$vid])) {
                    $errors[] = "Variable #$vid ($lbl) wird in Zeile {$usedVars[$vid]} und Zeile $nr verwendet. Ein Zähler darf nur einmal eingehen — sonst würde er doppelt gerechnet.";
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
                        $units[$u][] = 'Zeile ' . ($i + 1);
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
            $errors[] = 'Die aktuelle Formel ergibt keine einzige Ausgabe mehr — keine Zeile hat mehr einen Zähler. Vorhandene Ausgabevariablen bleiben deshalb unangetastet, bis das behoben ist.';
        }

        return $errors;
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
    public function Recalc(): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return 'ℹ️ Instanz ist deaktiviert, es wurde nichts berechnet.';
        }
        $nodes = $this->Nodes();

        $count = 0;
        foreach ($this->OutputDefs() as [$ident, , , $field]) {
            $sum = 0.0;
            foreach ($nodes as $n) {
                $vid = $n[$field];
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $sign = $n['sign'] === '-' ? -1 : 1;
                    $sum += $sign * (float)GetValue($vid);
                }
            }
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($vid && is_finite($sum)) {
                SetValueFloat($vid, $sum);
                $count++;
            }
        }

        return $count > 0
            ? "✅ Neu berechnet: $count Ausgabe(n) aktualisiert (" . date('H:i:s') . ' Uhr).'
            : 'ℹ️ Keine Ausgabe zum Berechnen vorhanden — erst oben Zähler eintragen und übernehmen.';
    }

    // -----------------------------------------------------------------------
    // Automatische Suche nach Zähler-Datenpunkten im System
    // -----------------------------------------------------------------------

    private const GUID_VIRTUAL = '{ADF18291-2E60-4354-92F5-B96863C127C8}';

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
     * schlägt sie als neue Zeilen vor. Persistiert bewusst NICHTS: Die
     * Vorschläge landen nur in der geöffneten Maske, bestätigt wird mit
     * „Übernehmen" — so bleibt ein versehentlicher Klick folgenlos.
     *
     * Die vier Filter kommen aus der Maske und werden im onClick übergeben,
     * damit eine noch nicht übernommene Änderung sofort greift.
     */
    public function ScanMeters(?int $root = null, ?string $filter = null, ?bool $needEnergy = null, ?bool $onlyActive = null, ?bool $onlyUsedElsewhere = null)
    {
        // Direktaufruf ohne Argumente (Skript, Konsole): gespeicherte Filter.
        $root              = $root              === null ? $this->ReadPropertyInteger('ScanRoot')              : (int)$root;
        $filter            = $filter            === null ? $this->ReadPropertyString('ScanFilter')             : (string)$filter;
        $needEnergy        = $needEnergy        === null ? $this->ReadPropertyBoolean('ScanNeedEnergy')        : (bool)$needEnergy;
        $onlyActive        = $onlyActive        === null ? $this->ReadPropertyBoolean('ScanOnlyActive')        : (bool)$onlyActive;
        $onlyUsedElsewhere = $onlyUsedElsewhere === null ? $this->ReadPropertyBoolean('ScanOnlyUsedElsewhere')  : (bool)$onlyUsedElsewhere;
        $filter     = trim($filter);

        if ($root > 0 && !IPS_ObjectExists($root)) {
            $this->UpdateFormField('ScanResult', 'caption', "❌ Der gewählte Suchbereich (#$root) existiert nicht mehr.");
            $this->UpdateFormField('ScanResult', 'visible', true);
            return;
        }
        $existing = json_decode($this->ReadPropertyString('Nodes'), true);
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
        $skipped = ['einheit' => 0, 'schonverwendet' => 0, 'virtuell' => 0, 'bereich' => 0, 'name' => 0, 'verbund' => 0, 'andereinstanz' => 0];

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
            // Teurere Prüfung (läuft die Elternkette hoch) bewusst zuletzt,
            // erst nachdem die billigen Filter schon aussortiert haben.
            if ($this->BelongsToExcludedModule($vid)) { $skipped['verbund']++; continue; }

            [$did, $dname] = $this->DeviceOf($vid);
            if ($filter !== '' && mb_stripos($dname, $filter) === false && mb_stripos(IPS_GetName($vid), $filter) === false) {
                $skipped['name']++;
                continue;
            }
            $key = $did > 0 ? 'd' . $did : 'v' . $vid;
            if (!isset($devices[$key])) {
                $devices[$key] = ['name' => $dname, 'power' => 0, 'import' => 0, 'usedIn' => []];
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

        $rows = $existing;
        $added = 0;
        $notes = [];
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
                $warn[] = 'bereits verwendet in „' . implode('“, „', array_keys($d['usedIn'])) . '“ — bei Aufnahme hier Doppelzählung prüfen';
            }
            $rows[] = [
                'Name' => $d['name'], 'Sign' => '+',
                'PowerID' => $d['power'], 'EnergyImportID' => $d['import'], 'EnergyExportID' => 0,
            ];
            $added++;
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

        $msg = $added > 0
            ? "🔎 $added Gerät(e) gefunden und unten mit Vorzeichen „+“ eingetragen — bitte prüfen, ob sie wirklich zu DIESER Formel gehören sollen, sonst Papierkorb-Symbol, und mit „Übernehmen“ bestätigen. Nichts wurde bereits gespeichert."
            : '🔎 Keine neuen Geräte gefunden.';
        $msg .= "\nSuchbereich: " . ($scope ? implode(', ', $scope) : 'ganze Installation, ungefiltert');
        $msg .= sprintf("\nÜbersprungen: %d ohne W/kWh-Profil, %d bereits eingetragen, %d Ausgaben virtueller Zähler, %d aus anderen NRG-Stack-Modulen, %d schon in einer anderen virtuellen Zähler-Instanz, %d außerhalb des Suchbereichs, %d durch den Namensfilter, %d ohne Energiezähler, %d länger als 7 Tage still.",
            $skipped['einheit'], $skipped['schonverwendet'], $skipped['virtuell'], $skipped['verbund'], $skipped['andereinstanz'],
            $skipped['bereich'], $skipped['name'], $filteredOut['ohneenergie'], $filteredOut['inaktiv']);
        if ($added === 0 && ($filteredOut['ohneenergie'] + $filteredOut['inaktiv'] + $skipped['bereich'] + $skipped['name']) > 0) {
            $msg .= "\n💡 Es wurde etwas gefunden, aber wegfiltriert — probeweise einen Filter lockern.";
        }
        if ($notes) {
            $msg .= "\n" . implode("\n", $notes);
        }

        $this->UpdateFormField('ScanResult', 'caption', $msg);
        $this->UpdateFormField('ScanResult', 'visible', true);
        $this->UpdateFormField('Nodes', 'values', json_encode($rows));
        // Liste mitwachsen lassen, damit die Funde ohne Scrollen sichtbar sind.
        $this->UpdateFormField('Nodes', 'rowCount', $this->RowCountFor(count($rows)));
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

    public function GetFunctions(): string
    {
        $func = $this->ReadPropertyString('Function');
        $pollInterval = max(2, $this->ReadPropertyInteger('Interval'));
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
                'sourceCount'    => count($this->Nodes()),
                'latency'        => 'realtime',
                'authority'      => 'auxiliary',
                'pollInterval'   => $pollInterval,
            ];
        }
        return json_encode([
            'contractVersion' => '1.1',
            'instanceID'  => $this->InstanceID,
            'meter'       => 'virtual',
            'measureMode' => 'combined',
            'latency'     => 'realtime',
            'authority'   => 'auxiliary',
            'pollInterval'=> $pollInterval,
            'assignments' => $list,
        ]);
    }

    // -----------------------------------------------------------------------
    // Konfigurationsformular
    // -----------------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $rawRows   = json_decode($this->ReadPropertyString('Nodes'), true);
        $rawRows   = is_array($rawRows) ? $rawRows : [];
        $migration = $this->NeedsMigration($rawRows);

        $nodes  = $migration ? [] : $this->Nodes();
        $errors = $migration ? [] : $this->Validate();

        $funcOptions = [];
        foreach (self::FUNCTIONS as $key => $def) {
            $funcOptions[] = ['caption' => $def[0], 'value' => $key];
        }

        // Vorschlagsliste für "Standort": alle Werte, die irgendeine
        // MeterHubVirtual-Instanz (auch diese selbst) schon eingetragen hat —
        // wächst mit der eigenen Nutzung, statt eine erfundene Raumliste
        // vorzugeben, die an keiner echten Anlage passt.
        $locationOptions = [['caption' => '— Vorschlag wählen —', 'value' => '']];
        $seenLocations = [];
        foreach (IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL) as $iid) {
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
        if ($migration) {
            $check[] = ['type' => 'Label', 'caption' => 'Migration ausstehend — siehe Panel oben.'];
        } elseif (count($errors) > 0) {
            $check[] = ['type' => 'Label', 'caption' => '❌ ' . count($errors) . ' Problem(e) — solange sie bestehen, wird nicht gerechnet:'];
            foreach ($errors as $e) {
                $check[] = ['type' => 'Label', 'caption' => '   • ' . $e];
            }
        } elseif (count($nodes) === 0) {
            $check[] = ['type' => 'Label', 'caption' => 'Noch keine Zähler eingetragen.'];
        } else {
            $check[] = ['type' => 'Label', 'caption' => '✅ Formel schlüssig:'];
            foreach ($this->FormulaPreview($nodes) as $line) {
                $check[] = ['type' => 'Label', 'caption' => $line];
            }
        }

        $migrationPanel = null;
        $listDef = [
            'type' => 'List', 'name' => 'Nodes', 'caption' => 'Zähler und ihr Vorzeichen',
            'rowCount' => $this->RowCountFor($migration ? count($rawRows) : count($nodes)),
            'add' => true, 'delete' => true,
            'columns' => [
                ['caption' => 'Bezeichnung', 'name' => 'Name', 'width' => '240px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => 'Vorzeichen', 'name' => 'Sign', 'width' => '140px', 'add' => '+', 'edit' => ['type' => 'Select', 'options' => [
                    ['caption' => '＋  addieren', 'value' => '+'],
                    ['caption' => '−  abziehen', 'value' => '-'],
                ]]],
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
            ],
        ];

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

        $newsBanner = $migration ? null : $this->NewsBanner();

        $meterItems = [];
        if (!$migration) {
            $meterItems[] = ['type' => 'Label', 'caption' => 'Zähler im System automatisch suchen: Findet alle Datenpunkte mit W-/kW- bzw. kWh-Profil, gruppiert sie nach Gerät und übernimmt den Gerätenamen als Bezeichnung. Die Funde werden nur vorgeschlagen — gespeichert wird erst mit „Übernehmen“.'];
            $meterItems[] = ['type' => 'Label', 'caption' => 'Variablen aus bekannten NRG-Stack-Modulen (EMS, InverterHub, ChargerHub, Prognose, Tibber Grid Rewards …) werden dabei übersprungen — sie sind dort schon korrekt eingebunden.'];
            $meterItems[] = ['type' => 'SelectObject', 'name' => 'ScanRoot', 'caption' => 'Nur in diesem Bereich suchen (leer = ganze Installation)'];
            $meterItems[] = ['type' => 'ValidationTextBox', 'name' => 'ScanFilter', 'caption' => 'Nur Geräte, deren Name das hier enthält (leer = alle)'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanNeedEnergy', 'caption' => 'Nur Geräte mit Energiezähler (kWh) — blendet Schalter aus, die bloß die Momentanleistung melden'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanOnlyActive', 'caption' => 'Nur Geräte, die in den letzten 7 Tagen Werte geliefert haben — blendet Karteileichen aus'];
            $meterItems[] = ['type' => 'CheckBox', 'name' => 'ScanOnlyUsedElsewhere', 'caption' => 'Nur Datenpunkte zeigen, die schon in einer ANDEREN virtuellen Zähler-Instanz stecken (zum gezielten Prüfen auf Doppelverwendung) — sonst werden sie wie gewohnt ausgeblendet'];
            $meterItems[] = ['type' => 'Button', 'caption' => '🔎  Zähler im System suchen', 'onClick' => 'MHUBV_ScanMeters($id, $ScanRoot, $ScanFilter, $ScanNeedEnergy, $ScanOnlyActive, $ScanOnlyUsedElsewhere);'];
            $meterItems[] = ['type' => 'Label', 'name' => 'ScanResult', 'caption' => '', 'visible' => false];
            $meterItems[] = ['type' => 'Label', 'caption' => 'So wird verdrahtet: pro Zähler eine Zeile (per Suchlauf oder von Hand über „+“), Vorzeichen setzen — „+“ addiert, „−“ zieht ab —, dann „Übernehmen“. Die Reihenfolge der Zeilen spielt keine Rolle, nur das Vorzeichen zählt.'];
        }
        $meterItems[] = $listDef;

        $form = [
            'elements' => array_values(array_filter([
                $migrationPanel,
                $newsBanner,
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Dokumentation & Hilfe', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'MeterHubVirtual ' . self::NEWS_VERSION . ' — Stand dieser Anleitung.'],
                        ['type' => 'Label', 'caption' => 'Bildet einen virtuellen Zähler aus einer FORMEL: Diese Instanz ist die oberste Ebene, jede Zeile unten ein Term mit Vorzeichen. Ergebnis = Summe aller „+“-Zeilen minus Summe aller „−“-Zeilen, getrennt für Leistung, Bezug und Einspeisung.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Sammeln“: Kühlschrank (+) und Brunnenpumpe (+) ergeben deren Summe — nützlich, wenn es keinen echten Zähler gibt, der beide zusammen misst.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Abziehen“: Hausanschluss (+, eigener Zähler), Wärmepumpe (−) und Wallbox (−) ergeben Hausanschluss minus Wärmepumpe minus Wallbox — der unbekannte Rest des Hauses.'],
                        ['type' => 'Label', 'caption' => 'Beispiel „Durchreichen“: nur EINE Zeile (+) — die Instanz gibt einfach diesen einen Zähler weiter, nützlich um ihm über „Funktion“ eine Dashboard-Zuordnung zu geben, ohne ihn mit etwas anderem zu verrechnen.'],
                        ['type' => 'Label', 'caption' => 'Mehrstufige Verschachtelung (z. B. ein Zwischenwert aus mehreren Zählern, von dem dann wieder etwas abgezogen wird) geht über mehrere Instanzen: eine Instanz rechnet den Zwischenwert, dessen Ausgabe wird als ganz normale Zeile in der nächsten Instanz verdrahtet — nicht mehr innerhalb einer einzigen Instanz.'],
                        ['type' => 'Label', 'caption' => '━━━ Schritt für Schritt ━━━'],
                        ['type' => 'Label', 'caption' => '1. Zeilen anlegen — per Suchlauf (Knopf unten) oder von Hand über „+“ in der Tabelle.'],
                        ['type' => 'Label', 'caption' => '2. Vorzeichen setzen: „+“ addiert, „−“ zieht ab.'],
                        ['type' => 'Label', 'caption' => '3. „Übernehmen“ klicken.'],
                        ['type' => 'Label', 'caption' => '4. Unten im Panel „Prüfung & Vorschau“ kontrollieren: ✅ zeigt die fertige Formel, ❌ nennt genau, was noch fehlt.'],
                        ['type' => 'Label', 'caption' => '5. Optional: „Funktion“ oben setzen, damit das Dashboard diese Instanz als Verbraucher erkennt.'],
                        ['type' => 'Label', 'caption' => 'Ein Datenpunkt darf nur in EINER Zeile stehen — sonst würde er doppelt gezählt. Die Prüfung meldet das.'],
                        ['type' => 'Label', 'caption' => 'Einheiten: Leistung in W, Energie als kumulative kWh-Zählerstände. Alle Datenpunkte je Spalte müssen dieselbe Einheit haben; Abweichungen meldet die Prüfung.'],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Berechnung aktiv'],
                ['type' => 'Select', 'name' => 'Function', 'caption' => 'Funktion (fürs Dashboard)', 'options' => $funcOptions],
                // Standort: reines Freitext-Label (Raum/Geschoss …), bewusst
                // getrennt vom Dashboard-Vertrag "Function". Auswahlliste aus
                // den Werten, die irgendeine Instanz schon benutzt — plus
                // jederzeit frei eintragbar für den Einzelfall, der in keine
                // Liste passt (Dietmars Auftrag: "auch die letzten
                // Absurditäten noch bezeichnen können").
                ['type' => 'Select', 'name' => 'LocationPreset', 'caption' => 'Standort (Vorschlag übernehmen …)', 'options' => $locationOptions, 'value' => '', 'onChange' => 'MHUBV_ApplyLocationPreset($id, $LocationPreset);'],
                ['type' => 'ValidationTextBox', 'name' => 'Location', 'caption' => 'Standort (Raum/Geschoss, frei eintragbar)'],
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
            ])),
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt neu berechnen', 'onClick' => 'echo MHUBV_Recalc($id);'],
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
    private function FormulaPreview(array $nodes): array
    {
        $lines = [];
        foreach ([['power', 'Leistung'], ['imp', 'Bezug'], ['exp', 'Einspeisung']] as [$f, $lbl]) {
            $terms = [];
            foreach ($nodes as $n) {
                if ($n[$f] > 0) {
                    $name = $n['name'] !== '' ? $n['name'] : '(ohne Namen)';
                    $terms[] = ($n['sign'] === '-' ? '−' : '+') . ' ' . $name;
                }
            }
            if ($terms) {
                $expr = implode('  ', $terms);
                // Führendes „+ “ weglassen, liest sich als Summe natürlicher.
                $expr = preg_replace('/^\+ /', '', $expr);
                $lines[] = '   ' . $lbl . ' = ' . $expr;
            }
        }
        if (!$lines) {
            $lines[] = '   (kein Term ergibt eine Ausgabe)';
        }
        return $lines;
    }
}
