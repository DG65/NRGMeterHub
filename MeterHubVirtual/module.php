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

    // Formular-Konvention des Verbunds (SUITE.md „Einheitliche Formular-
    // Optik", Referenz InverterHub) — News-Panel pro Version, einmalig
    // bestätigt und dann weg, bis zur nächsten NEWS_VERSION. Hier zum ersten
    // Mal in diesem Repo umgesetzt (31.08.2026, Dietmars Auftrag „hier
    // maximal nachbessern", ausgelöst durch die Verdrahtungs-Verwirrung im
    // Praxistest): genau die Version, die den Fund behebt, ist es wert,
    // sichtbar hervorgehoben zu werden — nicht erst beim x-ten stillen
    // Scrollen durchs eingeklappte Doku-Panel.
    private const NEWS_VERSION = '0.23.5';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        // Verdrahtung: [{Key,Name,Parent,PowerID,EnergyImportID,EnergyExportID,Function}]
        $this->RegisterPropertyString('Nodes', '[]');
        $this->RegisterPropertyInteger('Interval', 10);
        // Filter für den Suchlauf. Sie merken sich die letzte Eingabe; wirksam
        // ist beim Klick aber immer der aktuelle Stand der Maske.
        $this->RegisterPropertyInteger('ScanRoot', 0);
        $this->RegisterPropertyString('ScanFilter', '');
        $this->RegisterPropertyBoolean('ScanNeedEnergy', false);
        $this->RegisterPropertyBoolean('ScanOnlyActive', true);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterTimer('Recalc', 0, 'MHUBV_Recalc($_IPS[\'TARGET\']);');
    }

    /**
     * Aufgeklappt und pro Version einmalig bestätigbar — Inhalt aus dem
     * jeweils aktuellen CHANGELOG-Eintrag abgeleitet, nicht neu formuliert
     * (Formular-Konvention). `null`, sobald `NEWS_VERSION` schon bestätigt
     * wurde, dann taucht das Panel gar nicht erst im Formular auf.
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
                ['type' => 'Label', 'caption' => '• Ein Zähler OHNE untergeordnete Zähler bekommt jetzt endlich eine eigene Ausgabe, wenn er einen eigenen Zähler hat — bisher blieb er komplett unsichtbar (weder Berechnung noch Funktionszuordnung), ohne erkennbaren Grund. Betrifft z. B. eine einzelne Steckdose, die nur für die Funktionszuordnung („Kühl-/Gefriergerät" …) verdrahtet wird.'],
                ['type' => 'Label', 'caption' => '• Das Doku-Panel unten wurde komplett neu geschrieben: alle drei Verdrahtungs-Muster mit Beispiel, unabhängig von Vorwissen verständlich.'],
                ['type' => 'Label', 'caption' => '• Die Zählersuche erkennt jetzt auch die neuen IPS-„Darstellungen" (Shelly, KNX u. a.), nicht mehr nur klassische Profile.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'MHUBV_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
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

        $nodes    = $this->Nodes();
        $childMap = $this->Children($nodes);

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
        foreach ($childMap as $parent => $kids) {
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

        // Sicherheitsnetz gegen den 25.07.2026-Vorfall (#16933): Eine
        // Verdrahtung ohne eine einzige Summe-/Rest-Ausgabe würde
        // RegisterVariables() sonst als "nichts ist mehr gültig" lesen und
        // JEDE schon vorhandene Ausgabevariable auf einen Schlag löschen,
        // auch wenn nur eine einzelne, unbeteiligte Zeile geändert wurde.
        // Nur relevant, wenn es überhaupt schon Ausgaben gibt — eine
        // brandneue, noch nie verdrahtete Instanz hat keine und wird
        // hierdurch nicht blockiert.
        //
        // Seit 31.08.2026 reicht "kein Knoten hat Kinder" (leeres $childMap)
        // allein NICHT mehr als Kriterium: ein kinderloser Knoten mit
        // eigenem Zähler erzeugt seither eine Durchreichungs-Ausgabe (siehe
        // OutputDefs()), auch ohne jede Kind-Beziehung. Deshalb hier
        // dieselbe Bedingung wie dort — echte Ausgabe gibt es, sobald
        // irgendein Knoten Kinder ODER einen eigenen Zähler hat.
        $anyOutputLeft = !empty($childMap);
        if (!$anyOutputLeft) {
            foreach ($nodes as $n) {
                if ($n['power'] > 0 || $n['imp'] > 0 || $n['exp'] > 0) {
                    $anyOutputLeft = true;
                    break;
                }
            }
        }
        if (!$anyOutputLeft && $this->HasExistingOutputs()) {
            $errors[] = 'Die aktuelle Verdrahtung ergibt keine einzige Ausgabe mehr — kein Zähler hat mehr Kinder oder einen eigenen Zähler. Vorhandene Ausgabevariablen bleiben deshalb unangetastet, bis das behoben ist. Prüfen, ob eine Zeile versehentlich ihr „hängt hinter“ oder ihren Zähler verloren hat.';
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

    /** Auszugebende Variablen: [ident, caption, profil, quelle-feld, art]. */
    /**
     * Bis 31.08.2026 lief diese Funktion nur über `$kids` (Knoten, die
     * MINDESTENS ein Kind haben) — ein kinderloser Knoten mit eigenem Zähler
     * (z. B. eine einzelne Steckdose, die nur zur Funktionszuordnung wie
     * „Kühl-/Gefriergerät“ dienen soll) bekam dadurch KEINE Ausgabe, obwohl
     * er einen eigenen Zähler trägt — Recalc() meldete „keine Ausgabe
     * vorhanden“, und die Funktion war über MHUBV_GetFunctions() unsichtbar,
     * ganz ohne Fehlermeldung, die auf die Ursache hindeutete (Live-Fund
     * über einen Praxistest, Dietmar/Sepp). Jetzt läuft die Schleife über
     * ALLE Knoten: „Summe“ weiterhin nur bei Kindern, „Rest“ weiterhin nur
     * bei eigenem Zähler — aber unabhängig voneinander. Ein kinderloser
     * Knoten mit eigenem Zähler bekommt dadurch eine reine
     * Durchreichungs-Ausgabe (Rest = eigener Zähler − 0 = eigener Zähler),
     * mit angepasster Bezeichnung (kein irreführendes „Rest“ ohne etwas,
     * das abgezogen wird).
     */
    private function OutputDefs(): array
    {
        $nodes = $this->Nodes();
        $kids  = $this->Children($nodes);
        $defs  = [];
        foreach ($nodes as $key => $n) {
            $hasKids = isset($kids[$key]);
            foreach ([['power', 'NRG.Watt', 'Leistung'], ['imp', 'NRG.kWh', 'Bezug'], ['exp', 'NRG.kWh', 'Einspeisung']] as [$f, $prof, $lbl]) {
                // Summe der untergeordneten Zähler — nur, wenn welche da sind.
                if ($hasKids) {
                    $defs[] = [$key . '_sum_' . $f, $n['name'] . ': ' . $lbl . ' untergeordnet', $prof, $f, 'sum', $key];
                }
                // Rest (bzw. bei kinderlosen Knoten: reine Durchreichung des
                // eigenen Zählers) — unabhängig davon, ob der Knoten Kinder hat.
                if ($n[$f] > 0) {
                    $label = $hasKids ? ($n['name'] . ': ' . $lbl . ' Rest') : ($n['name'] . ': ' . $lbl);
                    $defs[] = [$key . '_rest_' . $f, $label, $prof, $f, 'rest', $key];
                }
            }
        }
        return $defs;
    }

    private function RegisterVariables(array $errors)
    {
        // Solange Validate() Fehler meldet, wird NICHTS angefasst — weder
        // gelöscht noch neu angelegt. Vorher lief die Löschrunde unten auch
        // im Fehlerfall durch (mit $defs=[], also "nichts ist mehr gültig"),
        // was am 25.07.2026 #16933 alle Ausgabevariablen gekostet hat, obwohl
        // nur eine einzelne, dead-reference-Zeile betroffen war. Lieber
        // vorübergehend veraltete Werte behalten als sie fälschlich löschen.
        if ($errors) {
            return;
        }
        $defs  = $this->OutputDefs();
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
        $kids  = $this->Children($nodes);

        $count = 0;
        foreach ($this->OutputDefs() as [$ident, , , $field, $kind, $parent]) {
            $sum = 0.0;
            // ?? [] : ein kinderloser Knoten mit reiner Durchreichungs-
            // Ausgabe (seit 31.08.2026, siehe OutputDefs()) hat keinen
            // Eintrag in $kids — Summe bleibt dann korrekt 0.0.
            foreach ($kids[$parent] ?? [] as $k) {
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
                $count++;
            }
        }

        return $count > 0
            ? "✅ Neu berechnet: $count Ausgabe(n) aktualisiert (" . date('H:i:s') . ' Uhr).'
            : 'ℹ️ Keine Ausgabe zum Berechnen vorhanden — erst oben verdrahten und übernehmen.';
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

    /** Aus dem Gerätenamen ein eindeutiges Kürzel bilden. */
    private function SlugFor(string $name, array $taken): string
    {
        $map = ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'];
        $s = strtr(mb_strtolower($name, 'UTF-8'), $map);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string)$s, '_');
        if ($s === '') {
            $s = 'zaehler';
        }
        $s = substr($s, 0, 24);
        $base = $s; $i = 2;
        while (isset($taken[$s])) {
            $s = $base . '_' . $i++;
        }
        return $s;
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
    public function ScanMeters(?int $root = null, ?string $filter = null, ?bool $needEnergy = null, ?bool $onlyActive = null)
    {
        // Direktaufruf ohne Argumente (Skript, Konsole): gespeicherte Filter.
        $root       = $root       === null ? $this->ReadPropertyInteger('ScanRoot')       : (int)$root;
        $filter     = $filter     === null ? $this->ReadPropertyString('ScanFilter')      : (string)$filter;
        $needEnergy = $needEnergy === null ? $this->ReadPropertyBoolean('ScanNeedEnergy') : (bool)$needEnergy;
        $onlyActive = $onlyActive === null ? $this->ReadPropertyBoolean('ScanOnlyActive') : (bool)$onlyActive;
        $filter     = trim($filter);

        if ($root > 0 && !IPS_ObjectExists($root)) {
            $this->UpdateFormField('ScanResult', 'caption', "❌ Der gewählte Suchbereich (#$root) existiert nicht mehr.");
            $this->UpdateFormField('ScanResult', 'visible', true);
            return;
        }
        $existing = json_decode($this->ReadPropertyString('Nodes'), true);
        $existing = is_array($existing) ? $existing : [];

        $taken = [];
        $used  = [];
        foreach ($existing as $r) {
            $k = strtolower(trim((string)($r['Key'] ?? '')));
            if ($k !== '') {
                $taken[$k] = true;
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

        $devices = [];
        $skipped = ['einheit' => 0, 'schonverwendet' => 0, 'virtuell' => 0, 'bereich' => 0, 'name' => 0, 'verbund' => 0];

        foreach (IPS_GetVariableList() as $vid) {
            if (isset($ownOutputs[$vid])) { $skipped['virtuell']++; continue; }
            if (isset($used[$vid]))       { $skipped['schonverwendet']++; continue; }
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
                $devices[$key] = ['name' => $dname, 'power' => 0, 'import' => 0];
            }
            // Je Gerät den ersten brauchbaren Datenpunkt je Art nehmen.
            if ($devices[$key][$kind] === 0) {
                $devices[$key][$kind] = $vid;
            }
        }

        // Prüfung je Fundstelle
        $rows = $existing;
        $added = 0;
        $notes = [];
        $filteredOut = ['ohneenergie' => 0, 'inaktiv' => 0];
        foreach ($devices as $d) {
            if ($d['power'] === 0 && $d['import'] === 0) {
                continue;
            }
            // Nachgelagerte Filter: sie brauchen das fertige Gerät, nicht die
            // einzelne Variable.
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
            $key = $this->SlugFor($d['name'], $taken);
            $taken[$key] = true;
            $rows[] = [
                'Key' => $key, 'Name' => $d['name'], 'Parent' => '',
                'PowerID' => $d['power'], 'EnergyImportID' => $d['import'],
                'EnergyExportID' => 0, 'Function' => 'none',
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

        $msg = $added > 0
            ? "🔎 $added Gerät(e) gefunden und unten eingetragen — bitte prüfen, verdrahten („hängt hinter“) und mit „Übernehmen“ bestätigen. Nichts wurde bereits gespeichert."
            : '🔎 Keine neuen Geräte gefunden.';
        $msg .= "\nSuchbereich: " . ($scope ? implode(', ', $scope) : 'ganze Installation, ungefiltert');
        $msg .= sprintf("\nÜbersprungen: %d ohne W/kWh-Profil, %d bereits eingetragen, %d Ausgaben virtueller Zähler, %d aus anderen NRG-Stack-Modulen, %d außerhalb des Suchbereichs, %d durch den Namensfilter, %d ohne Energiezähler, %d länger als 7 Tage still.",
            $skipped['einheit'], $skipped['schonverwendet'], $skipped['virtuell'], $skipped['verbund'],
            $skipped['bereich'], $skipped['name'], $filteredOut['ohneenergie'], $filteredOut['inaktiv']);
        if ($added === 0 && ($filteredOut['ohneenergie'] + $filteredOut['inaktiv'] + $skipped['bereich'] + $skipped['name']) > 0) {
            $msg .= "\n💡 Es wurde etwas gefunden, aber wegfiltriert — probeweise einen Filter lockern.";
        }
        if ($notes) {
            $msg .= "\n" . implode("\n", $notes);
        }
        $msg .= "\nHinweis: Alle Funde stehen zunächst auf oberster Ebene — die Verdrahtung („hängt hinter“) muss von Hand gesetzt werden, denn welcher Zähler hinter welchem sitzt, weiß nur die Anlage.";

        $this->UpdateFormField('ScanResult', 'caption', $msg);
        $this->UpdateFormField('ScanResult', 'visible', true);
        $this->UpdateFormField('Nodes', 'values', json_encode($rows));
        // Liste mitwachsen lassen, damit die Funde ohne Scrollen sichtbar sind.
        $this->UpdateFormField('Nodes', 'rowCount', $this->RowCountFor(count($rows)));
    }

    /** Sichtbare Zeilen der Verdrahtungsliste: wächst mit dem Inhalt. */
    private function RowCountFor(int $count): int
    {
        return max(12, min(30, $count + 3));
    }

    private function IsArchived(int $vid): bool
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 && (bool)@AC_GetLoggingStatus($ids[0], $vid);
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
        $pollInterval = max(2, $this->ReadPropertyInteger('Interval'));
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
                // Summe/Rest kumulativer Zählerstände sind selbst kumulativ.
                'energyKind'     => 'counter',
                // Güte-/Abdeckungshinweis: Fällt eine Quelle aus, wird der Rest
                // still zu groß. Ein Konsument kann bei kleiner Quellenzahl
                // vorsichtiger sein. Zahl der direkt untergeordneten Zähler.
                'sourceCount'    => count($kids[$parent] ?? []),
                // Zähler-Eigenschaften auch je Zuordnung gespiegelt (wie MHUB).
                'latency'        => 'realtime',
                'authority'      => 'auxiliary',
                'pollInterval'   => $pollInterval,
            ];
        }
        // Ein virtueller Zähler ist ein Rechenergebnis lokaler Werte: so
        // echtzeitnah wie seine Quellen, aber nie abrechnungsverbindlich.
        return json_encode([
            // Gleiche Vertragsversion wie MHUB_GetFunctions (siehe dort).
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

        $newsBanner = $this->NewsBanner();
        $form = [
            'elements' => array_values(array_filter([
                $newsBanner,
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Dokumentation & Hilfe', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'MeterHubVirtual ' . self::NEWS_VERSION . ' — Stand dieser Anleitung.'],
                        ['type' => 'Label', 'caption' => 'Bildet virtuelle Zähler, indem die VERDRAHTUNG beschrieben wird statt einer Formel: Für jeden Zähler wird angegeben, hinter welchem er sitzt (Spalte „hängt hinter“). Daraus leitet das Modul automatisch ab, was berechnet wird — welches der drei Muster unten zutrifft, entscheidet allein, ob eine Zeile einen EIGENEN Zähler hat und/oder KINDER (andere Zeilen, die hinter ihr hängen).'],
                        ['type' => 'Label', 'caption' => '━━━ Die drei Verdrahtungs-Muster ━━━'],
                        ['type' => 'Label', 'caption' => '① REINER SAMMELKNOTEN (kein eigener Zähler, hat Kinder) → nur „Summe untergeordnet“. Beispiel: eine Zeile „Steckdosen gesamt“ ohne eigene Leistungs-/Energiespalte, „Kühlschrank“ und „Brunnenpumpe“ hängen dahinter → „Steckdosen gesamt: Leistung untergeordnet“ = Kühlschrank + Brunnenpumpe. Genau dieses Muster braucht es, wenn es KEINEN echten Zähler gibt, der beide zusammen misst.'],
                        ['type' => 'Label', 'caption' => '② ZÄHLER MIT KINDERN (eigener Zähler UND Kinder) → „Summe untergeordnet“ UND „Rest“ (eigener Zähler minus Summe). Beispiel: „Hausanschluss“ (eigener Zähler) mit den untergeordneten „Wärmepumpe“ und „Wallbox“ ergibt „Hausanschluss: Leistung Rest“ — also alles, was weder Wärmepumpe noch Wallbox verbraucht.'],
                        ['type' => 'Label', 'caption' => '③ EINZELNER ZÄHLER OHNE KINDER (eigener Zähler, „hängt hinter“ = oberste Ebene, niemand hängt an ihm) → reine Durchreichung des eigenen Werts, keine „Summe“. 🆕 Neu seit ' . self::NEWS_VERSION . ' — vorher blieb so eine Zeile komplett ohne Ausgabe. Nützlich, um EINEN einzelnen, bereits gemessenen Zähler nur für die Funktionszuordnung (z. B. „Kühl-/Gefriergerät“) sichtbar zu machen, ohne ihn mit etwas anderem zu verrechnen.'],
                        ['type' => 'Label', 'caption' => '❌ Eine Zeile OHNE eigenen Zähler UND OHNE Kinder erzeugt dagegen weiterhin keine Ausgabe — es gäbe schlicht nichts zu berechnen.'],
                        ['type' => 'Label', 'caption' => '━━━ Schritt für Schritt ━━━'],
                        ['type' => 'Label', 'caption' => '1. Zeilen anlegen — per Suchlauf (Knopf unten) oder von Hand über „+“ in der Tabelle. Kürzel und mindestens eine Datenpunkt-Spalte ausfüllen.'],
                        ['type' => 'Label', 'caption' => '2. „Übernehmen“ klicken. Erst danach erscheint die neue Zeile in der Auswahl „hängt hinter“ — sie steht ja erst ab jetzt als Bezugspunkt fest.'],
                        ['type' => 'Label', 'caption' => '3. Verdrahten: bei den KINDER-Zeilen „hängt hinter“ auf die gewünschte Eltern-Zeile setzen (Muster ① oder ②). Für Muster ③ „hängt hinter“ einfach auf „— oberste Ebene —“ stehen lassen.'],
                        ['type' => 'Label', 'caption' => '4. Erneut „Übernehmen“. Erst jetzt wertet das Modul die neue Verdrahtung aus.'],
                        ['type' => 'Label', 'caption' => '5. Unten im Panel „Prüfung & Vorschau“ kontrollieren: ✅ zeigt den fertigen Baum, ❌ nennt genau, was noch fehlt.'],
                        ['type' => 'Label', 'caption' => '6. Optional: Spalte „Funktion“ setzen, damit InverterHubTile/Dashboard den Knoten als Verbraucher erkennen — das funktioniert bei allen drei Mustern, seit ③ selbst eine Ausgabe hat.'],
                        ['type' => 'Label', 'caption' => '🛡️ Warum keine freie Formel statt der Verdrahtung: Weil jeder Zähler im Baum genau EINEN Platz hat, kann er nicht doppelt abgezogen werden. Was die Struktur nicht verhindert (derselbe Datenpunkt in zwei Zeilen, Ringschlüsse, gemischte Einheiten), meldet die Prüfung unten — und solange etwas offen ist, wird bewusst nicht gerechnet.'],
                        ['type' => 'Label', 'caption' => 'Das Kürzel ist der technische Name: Es bildet die Variablen-Idents und dient als Bezug für „hängt hinter“. Die Bezeichnung ist frei änderbar, das Kürzel sollte stehen bleiben — sonst entstehen neue Variablen und die Historie der alten geht verloren.'],
                        ['type' => 'Label', 'caption' => 'Einheiten: Leistung in W, Energie als kumulative kWh-Zählerstände. Alle Datenpunkte eines Knotens müssen dieselbe Einheit haben; Abweichungen meldet die Prüfung.'],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Berechnung aktiv'],
                [
                    'type' => 'ExpansionPanel', 'caption' => '🔌  Verdrahtung', 'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Zähler im System automatisch suchen: Findet alle Datenpunkte mit W-/kW- bzw. kWh-Profil (Steckdosen, Licht- und Jalousieschalter, Zwischenzähler …), gruppiert sie nach Gerät und übernimmt den Gerätenamen als Bezeichnung. Die Funde werden nur vorgeschlagen — gespeichert wird erst mit „Übernehmen“.'],
                        ['type' => 'Label', 'caption' => 'Variablen aus bekannten NRG-Stack-Modulen (EMS, InverterHub, ChargerHub, Prognose, Tibber Grid Rewards …) werden dabei übersprungen — sie sind dort schon korrekt eingebunden, und ein berechneter Wert (z. B. eine vom EMS ermittelte Hauslast) dürfte sonst versehentlich in eine Berechnung zurückfließen, aus der er selbst stammt.'],
                        ['type' => 'Label', 'caption' => 'In einer gewachsenen Installation findet die Suche schnell dreistellig viele Datenpunkte. Die Filter engen sie ein; sie wirken sofort beim Klick, auch ohne vorher zu übernehmen.'],
                        ['type' => 'SelectObject', 'name' => 'ScanRoot', 'caption' => 'Nur in diesem Bereich suchen (leer = ganze Installation)'],
                        ['type' => 'ValidationTextBox', 'name' => 'ScanFilter', 'caption' => 'Nur Geräte, deren Name das hier enthält (leer = alle)'],
                        ['type' => 'CheckBox', 'name' => 'ScanNeedEnergy', 'caption' => 'Nur Geräte mit Energiezähler (kWh) — blendet Schalter aus, die bloß die Momentanleistung melden'],
                        ['type' => 'CheckBox', 'name' => 'ScanOnlyActive', 'caption' => 'Nur Geräte, die in den letzten 7 Tagen Werte geliefert haben — blendet Karteileichen aus'],
                        ['type' => 'Button', 'caption' => '🔎  Zähler im System suchen', 'onClick' => 'MHUBV_ScanMeters($id, $ScanRoot, $ScanFilter, $ScanNeedEnergy, $ScanOnlyActive);'],
                        ['type' => 'Label', 'name' => 'ScanResult', 'caption' => '', 'visible' => false],
                        // Konkrete Schritt-für-Schritt-Anleitung direkt an der Stelle,
                        // an der gehandelt wird — nicht nur im weit entfernten,
                        // eingeklappten Doku-Panel (Dietmars Rückmeldung 31.08.2026:
                        // die bisherige Prosa dort genügte nicht). Zusätzlich zwei
                        // „?“-PopupButtons an genau den zwei Stellen, die im
                        // Praxistest zu Verwirrung führten — Symcon kennt keinen
                        // Mouseover-Tooltip (gegen die SDK-Doku geprüft, siehe
                        // SUITE.md „Feld-Hilfestellung“), PopupButton mit
                        // caption="?" ist die dafür vorgesehene Verbund-Konvention.
                        ['type' => 'Label', 'caption' => 'So wird verdrahtet:'],
                        ['type' => 'Label', 'caption' => '1. Zeile anlegen — per Suchlauf oben oder von Hand über „+“ unten in der Tabelle. Kürzel und mindestens einen Datenpunkt ausfüllen.'],
                        ['type' => 'Label', 'caption' => '2. „Übernehmen“ klicken (Knopf am unteren Formularrand). Erst danach steht die Zeile als Auswahl für „hängt hinter“ zur Verfügung.'],
                        [
                            'type' => 'PopupButton', 'caption' => '3. „hängt hinter“ setzen — bei Bedarf hier klicken: ?', 'width' => '520px',
                            'popup' => [
                                'caption' => 'Was bedeutet „hängt hinter“?',
                                'items' => [
                                    ['type' => 'Label', 'caption' => 'Legt fest, wo eine Zeile im Baum sitzt. Drei Muster sind möglich:'],
                                    ['type' => 'Label', 'caption' => '① Reiner Sammelknoten — Zeile OHNE eigenen Zähler, andere Zeilen hängen hinter ihr → „Summe untergeordnet“. Beispiel: Zeile „Steckdosen gesamt“ (keine eigene Leistung/Energie), „Kühlschrank“ und „Brunnenpumpe“ hängen beide hinter „steckdosen_gesamt“ → deren Summe erscheint dort.'],
                                    ['type' => 'Label', 'caption' => '② Zähler mit Kindern — Zeile MIT eigenem Zähler, andere hängen dahinter → „Summe“ UND „Rest“ (eigener Zähler minus Summe). Beispiel: „Hausanschluss“ mit „Wärmepumpe“ und „Wallbox“ dahinter → „Rest“ = alles, was weder Wärmepumpe noch Wallbox verbraucht.'],
                                    ['type' => 'Label', 'caption' => '③ Einzelner Zähler — eigener Zähler, „hängt hinter“ bleibt auf „— oberste Ebene —“, niemand hängt dahinter → reine Durchreichung des eigenen Werts. Nützlich, um EINEN Zähler nur für die Spalte „Funktion“ sichtbar zu machen.'],
                                    ['type' => 'Label', 'caption' => 'Ohne eigenen Zähler UND ohne irgendetwas dahinter (Muster ① ohne Kinder) entsteht dagegen keine Ausgabe — dafür gibt es nichts zu berechnen.'],
                                    ['type' => 'Label', 'caption' => 'Praktisch: erst die Sammelzeile (Muster ①) oder den echten Zähler (Muster ②) anlegen und übernehmen — sie taucht erst DANACH in der Auswahl „hängt hinter“ der anderen Zeilen auf (Schritt 2).'],
                                ],
                            ],
                        ],
                        ['type' => 'Label', 'caption' => '4. Erneut „Übernehmen“ — erst jetzt wertet das Modul die neue Verdrahtung aus.'],
                        ['type' => 'Label', 'caption' => '5. Ergebnis im Panel „Prüfung & Vorschau“ unten kontrollieren: ✅ zeigt den fertigen Baum, ❌ nennt genau, was noch fehlt.'],
                        ['type' => 'Label', 'caption' => '6. Optional: Spalte „Funktion“ setzen, damit InverterHubTile/Dashboard den Knoten als Verbraucher zeigen.'],
                        [
                            'type' => 'List', 'name' => 'Nodes', 'caption' => 'Zähler und ihre Verdrahtung',
                            'rowCount' => $this->RowCountFor(count($nodes)), 'add' => true, 'delete' => true,
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
                        [
                            'type' => 'PopupButton', 'caption' => 'Warum steht bei „Kürzel“ eine Warnung? ?', 'width' => '350px',
                            'popup' => [
                                'caption' => 'Kürzel = technischer Name',
                                'items' => [
                                    ['type' => 'Label', 'caption' => 'Das Kürzel bildet die Variablen-Idents dieser Zeile und ist der Bezug, auf den andere Zeilen bei „hängt hinter“ zeigen.'],
                                    ['type' => 'Label', 'caption' => 'Die Bezeichnung (Spalte daneben) lässt sich jederzeit gefahrlos ändern. Das Kürzel dagegen sollte nach dem ersten „Übernehmen“ stehen bleiben — eine Änderung erzeugt NEUE Variablen und wirft die Archiv-Historie der alten weg.'],
                                ],
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
            ])),
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt neu berechnen', 'onClick' => 'echo MHUBV_Recalc($id);'],
                ['type' => 'Button', 'caption' => '🔄  Übernehmen erzwingen (ohne Formularänderung)', 'onClick' => "IPS_ApplyChanges(\$id); echo '✅ ApplyChanges() ausgeführt.';", 'confirm' => 'Instanz jetzt neu anwenden (ApplyChanges)?'],
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
