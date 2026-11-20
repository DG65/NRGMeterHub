<?php

// ---------------------------------------------------------------------------
// MeterHubEstimate — Schätzzähler: Leistung und Energie von Geräten OHNE eigene Messung.
//
// Aus Schaltzustand, Helligkeit und Farbe (oder einer festen Angabe) wird die Leistung geschätzt, daraus ein
// Energiezähler fortgeschrieben. Die Ausgabe hat denselben Vertrag wie die anderen MeterHub-Quellen
// (MHUBE_GetFunctions), ist aber als GESCHÄTZT gekennzeichnet (measured=false, energyMeasured=false): Dashboard,
// Gruppen („Rest“) und Prognose können sie von echten Messwerten unterscheiden.
//
// Rechenarten: fest, linear, oder ein Modell aus der Powercalc-Datenbank (Messtabellen für Lampen u. a.). Die
// Datenbank wird NICHT mitgeliefert, sondern auf Klick je Gerät von GitHub geladen. Verfahren und Datenformat:
// Powercalc für Home Assistant, MIT-Lizenz — siehe libs/MeterHubEstimate.php und README.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../libs/MeterHubShared.php';
require_once __DIR__ . '/../libs/MeterHubEstimate.php';

/** Texte zur Laufzeit übersetzen (Quelltexte englisch, Deutsch in locale.json — SUITE.md Punkt 9). */
class MHUBE_Text
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

class MeterHubEstimate extends IPSModule
{
    use MHUB_SharedForm;
    use MHUB_SharedInstance;

    private function txt(string $text, ...$args): string
    {
        return MHUBE_Text::t($text, ...$args);
    }

    private function fnPrefix(): string
    {
        return 'MHUBE';
    }

    private function siblingModuleGuid(): string
    {
        return self::GUID_ESTIMATE;
    }

    private const GUID_ESTIMATE = '{D9F12FFD-BA63-4A91-B1DE-B1CFCB118B6D}';
    private const LICENSE_URL = 'https://github.com/DG65/NRGMeterHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-modul-nrg-stack-meterhub-schaetzzaehler-leistung-und-energie-fuer-lampen-zwischenstecker-und-co-ohne-eigene-messung-powercalc-fuer-symcon/145001';
    private const POWERCALC_URL = 'https://github.com/bramstroker/homeassistant-powercalc';
    private const NEWS_VERSION = '0.41.0';

    private const IDENT_POWER = 'power';
    private const IDENT_ENERGY = 'energy_import';

    /** Zwischenspeicher des geparsten Datenbank-Profils: [Hash des Attributs, Profil]. */
    private $profileCache = null;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        MHUBE_Text::set(function (string $text): string {
            return $this->Translate($text);
        });
    }

    // Vokabular wie im MeterHub-Hauptmodul (Funktion = Vertrag mit Dashboard/Prognose). Bewusst dupliziert: Konstanten
    // lassen sich zwischen IPS-Modulen nicht teilen. Änderungen hier, in MeterHub/module.php und MeterHubVirtual gleich halten.
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

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Function', 'none');
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyString('FormLayout', '[]');
        // fixed | linear | database
        $this->RegisterPropertyString('Mode', 'fixed');
        $this->RegisterPropertyInteger('SwitchID', 0);
        $this->RegisterPropertyInteger('BrightnessID', 0);
        $this->RegisterPropertyString('BrightnessScale', 'auto');   // auto | 100 | 255 | 1
        $this->RegisterPropertyInteger('ColorTempID', 0);
        $this->RegisterPropertyString('ColorTempUnit', 'auto');     // auto | kelvin | mired
        $this->RegisterPropertyInteger('ColorID', 0);
        $this->RegisterPropertyString('LightMode', 'auto');         // auto | brightness | color_temp | hs
        $this->RegisterPropertyFloat('PowerOn', 0.0);
        $this->RegisterPropertyFloat('StandbyPower', 0.0);
        $this->RegisterPropertyFloat('PowerMin', 0.0);
        $this->RegisterPropertyFloat('PowerMax', 0.0);
        $this->RegisterPropertyFloat('Gamma', 0.0);
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyString('SearchText', '');
        $this->RegisterPropertyString('SearchResult', '');
        // geladenes Datenbank-Profil: {profile: {...}, luts: {<Art>: gz+Base64-CSV}}
        $this->RegisterAttributeString('Profile', '');
        // Zählerfortschreibung: Zeitpunkt und Leistung des letzten Schritts
        $this->RegisterAttributeFloat('LastTs', 0.0);
        $this->RegisterAttributeFloat('LastPower', 0.0);
        // beobachtete Quellvariablen (zum sauberen Lösen)
        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeString('SeenNews', self::NEWS_VERSION);
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('ReAggQueue', '[]');
        $this->RegisterTimer('Update', 0, 'MHUBE_Update($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    // -----------------------------------------------------------------------
    // Hinweise im Formular (Formular-Konvention des Verbunds)
    // -----------------------------------------------------------------------

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Feedback is welcome in the Symcon forum — especially which devices you estimate and how close the estimate comes to a real measurement.'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBE_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->PropagateDismiss('ForumHint');
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

    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => $this->ReadAttributeBoolean('ForumHintGone'),
            'seenNews'         => $this->ReadAttributeString('SeenNews'),
        ];
    }

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Many devices have no power measurement of their own: lamps, in-wall switches, smart plugs, speakers, routers. MeterHubEstimate ESTIMATES their power and energy from what is known — on/off, brightness, colour — and a device database or your own figures.'],
                ['type' => 'Label', 'caption' => 'The result looks like a meter (power in W, energy in kWh) and can be used in the NRG-Stack Dashboard, in meter groups and in the forecast. It is always marked as estimated, so nobody mistakes it for a measurement.'],
                ['type' => 'Label', 'caption' => 'An estimate is never as exact as a meter: the device database holds typical values measured on sample devices. For a total over many lamps it is usually very good, for a single lamp a good indication.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBE_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  New in this version',
            'items' => [
                ['type' => 'Label', 'caption' => '• 🆕 New module: MeterHubEstimate estimates power and energy of devices without their own measurement — fixed, linear, or from the Powercalc device database (lamps with brightness and colour).'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUBE_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::NEWS_VERSION);
    }

    /** Benennt die Instanz um (IPS_SetName ist keine Eigenschaft und nimmt an „Übernehmen“ nicht teil — wirkt sofort). */
    public function RenameInstance(string $name)
    {
        $name = trim($name);
        if ($name !== '' && $name !== IPS_GetName($this->InstanceID)) {
            IPS_SetName($this->InstanceID, $name);
        }
    }

    // -----------------------------------------------------------------------
    // Anwenden, Quellen beobachten
    // -----------------------------------------------------------------------

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->profileCache = null;
        $this->CreateProfiles();
        $this->RegisterVariables();
        $this->WatchSources();

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }
        if ($this->Problems() !== []) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(201);
            return;
        }
        $this->SetTimerInterval('Update', max(10, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->SetStatus(102);
        $this->Tick();
    }

    /** Quellvariablen (Schalter, Helligkeit, Farbtemperatur, Farbe), die diese Instanz beobachtet. */
    private function SourceIds(): array
    {
        $ids = [];
        foreach (['SwitchID', 'BrightnessID', 'ColorTempID', 'ColorID'] as $p) {
            $id = $this->ReadPropertyInteger($p);
            if ($id > 0 && IPS_VariableExists($id)) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function WatchSources(): void
    {
        $old = json_decode((string)$this->ReadAttributeString('Watched'), true);
        foreach (is_array($old) ? $old : [] as $id) {
            if ((int)$id > 0) {
                @$this->UnregisterMessage((int)$id, VM_UPDATE);
            }
        }
        $ids = $this->SourceIds();
        foreach ($ids as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $this->WriteAttributeString('Watched', (string)json_encode($ids));
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if ($message === IPS_KERNELMESSAGE && isset($data[0]) && $data[0] === KR_READY) {
            if (IPS_InstanceExists($this->InstanceID)) {
                $this->ApplyChanges();
            }
            return;
        }
        if ($message === VM_UPDATE) {
            // eine Quelle hat sich bewegt: sofort neu schätzen (der Zähler läuft mit der alten Leistung bis jetzt)
            if (!$this->InstanceReady() || !$this->ReadPropertyBoolean('Active')) {
                return;
            }
            $this->Tick();
        }
    }

    // -----------------------------------------------------------------------
    // Variablen, Profile, Archiv
    // -----------------------------------------------------------------------

    private function CreateProfiles(): void
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

    /** [Ident, Name, Profil, ist Zählerstand] */
    private function OutputDefs(): array
    {
        return [
            [self::IDENT_POWER, MHUBE_Text::t('Power (estimated)'), 'NRG.Watt', false],
            [self::IDENT_ENERGY, MHUBE_Text::t('Energy (estimated)'), 'NRG.kWh', true],
        ];
    }

    private function RegisterVariables(): void
    {
        $pos = 0;
        foreach ($this->OutputDefs() as [$ident, $caption, $profile]) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $created = !$vid;
            $posBefore = $vid ? (int)IPS_GetObject($vid)['ObjectPosition'] : 0;
            // Das Profil kommt als Standarddarstellung: das benutzerdefinierte Profil gehört dem Benutzer und wird nie gesetzt.
            $vid = $this->RegisterVariableFloat($ident, $caption, ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => $profile], $pos);
            IPS_SetName($vid, $caption);
            if (!$created && (int)IPS_GetObject($vid)['ObjectPosition'] !== $posBefore) {
                IPS_SetPosition($vid, $posBefore);
            }
            $pos++;
            $this->SetArchive($vid, $ident === self::IDENT_ENERGY);
        }
    }

    private function OutputId(string $ident): int
    {
        return (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    }

    /**
     * Aggregationstyp setzen — nur dort, wo das Archiv die Variable schon loggt. Das Logging selbst schaltet das Modul
     * nie von selbst ein, sondern nur auf Klick im Formular (EnableArchiving()). Zählerstände: Typ „Zähler“.
     */
    private function SetArchive(int $vid, bool $counter): void
    {
        $ac = $this->ArchiveInstanceID();
        if ($ac <= 0 || !@AC_GetLoggingStatus($ac, $vid)) {
            return;
        }
        $wanted = $counter ? 1 : 0;
        $old = @AC_GetAggregationType($ac, $vid);
        AC_SetAggregationType($ac, $vid, $wanted);
        if ($old !== $wanted) {
            $this->ReAggregate($ac, $vid);
        }
    }

    private function ArchivingMissing(): array
    {
        $ac = $this->ArchiveInstanceID();
        if ($ac <= 0) {
            return [];
        }
        $out = [];
        foreach ($this->OutputDefs() as [$ident, , , $counter]) {
            $vid = $this->OutputId($ident);
            if ($vid && !@AC_GetLoggingStatus($ac, $vid)) {
                $out[$vid] = $counter;
            }
        }
        return $out;
    }

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
            'caption' => count($missing) === 1 ? MHUBE_Text::t('📈  Archiving missing (1 variable)') : sprintf(MHUBE_Text::t('📈  Archiving missing (%d variables)'), count($missing)),
            'items' => array_merge(
                [['type' => 'Label', 'caption' => 'These variables are not archived. Without an archive there is no history and no consumption analysis. MeterHubEstimate does not switch archiving on by itself — you decide.']],
                $lines,
                [['type' => 'Button', 'caption' => '📈  Enable archiving for these variables', 'onClick' => 'echo MHUBE_EnableArchiving($id);']]
            ),
        ];
    }

    /** Schaltet die Archivierung für die fehlenden eigenen Variablen ein (Klick im Formular). */
    public function EnableArchiving(): string
    {
        $ac = $this->ArchiveInstanceID();
        if ($ac <= 0) {
            return MHUBE_Text::t('❌ No archive instance found.');
        }
        $n = 0;
        foreach ($this->ArchivingMissing() as $vid => $counter) {
            AC_SetLoggingStatus($ac, $vid, true);
            $this->SetArchive($vid, $counter);
            $n++;
        }
        $this->UpdateFormField('PanelLogging', 'visible', false);
        return $n === 0 ? MHUBE_Text::t('ℹ️ Nothing was missing — all variables are already archived.') : sprintf(MHUBE_Text::t('✅ Archiving enabled for %d variable(s).'), $n);
    }

    // -----------------------------------------------------------------------
    // Zustand der Quellen lesen
    // -----------------------------------------------------------------------

    private function Val(int $id)
    {
        return ($id > 0 && IPS_VariableExists($id)) ? GetValue($id) : null;
    }

    /** Obergrenze der Helligkeitsvariablen: gewählte Skala oder aus dem Variablenprofil, sonst nach dem Wert geschätzt. */
    private function BrightnessMax(int $id, float $value): float
    {
        $scale = $this->ReadPropertyString('BrightnessScale');
        if (in_array($scale, ['100', '255', '1'], true)) {
            return (float)$scale;
        }
        $v = @IPS_GetVariable($id);
        $prof = (string)(($v['VariableCustomProfile'] ?? '') !== '' ? $v['VariableCustomProfile'] : ($v['VariableProfile'] ?? ''));
        if ($prof !== '' && IPS_VariableProfileExists($prof)) {
            $max = (float)(IPS_GetVariableProfile($prof)['MaxValue'] ?? 0);
            if ($max > 0) {
                return $max;
            }
        }
        if ($value > 100) {
            return 255.0;
        }
        return ($value > 0 && $value <= 1 && (int)($v['VariableType'] ?? 0) === VARIABLETYPE_FLOAT) ? 1.0 : 100.0;
    }

    /** Aktueller Zustand für den Rechenkern: ['on', 'percent', 'bri', 'kelvin', 'color', 'mode'] und Beschreibungsfelder. */
    private function ReadState(): array
    {
        $state = ['on' => true, 'mode' => $this->ReadPropertyString('LightMode')];
        $bid = $this->ReadPropertyInteger('BrightnessID');
        $bv = $this->Val($bid);
        if ($bv !== null) {
            $max = $this->BrightnessMax($bid, (float)$bv);
            $pct = max(0.0, min(100.0, (float)$bv / $max * 100));
            $state['percent'] = $pct;
            $state['bri'] = $pct * 2.55;
        }
        $sv = $this->Val($this->ReadPropertyInteger('SwitchID'));
        if ($sv !== null) {
            $state['on'] = (bool)$sv;
        } elseif ($bv !== null) {
            // ohne Schalter gilt: Helligkeit über 0 = an
            $state['on'] = $state['percent'] > 0;
        }
        $kv = $this->Val($this->ReadPropertyInteger('ColorTempID'));
        if ($kv !== null && (float)$kv > 0) {
            $unit = $this->ReadPropertyString('ColorTempUnit');
            $isMired = $unit === 'mired' || ($unit === 'auto' && (float)$kv < 1000);
            $state['kelvin'] = $isMired ? (float)MHUBE_Engine::MiredToKelvin((float)$kv) : (float)$kv;
        }
        $cv = $this->Val($this->ReadPropertyInteger('ColorID'));
        if ($cv !== null) {
            $state['color'] = (int)$cv;
        }
        return $state;
    }

    // -----------------------------------------------------------------------
    // Profil
    // -----------------------------------------------------------------------

    /** Geladenes Datenbank-Profil mit geparsten Messtabellen, oder null. */
    private function LoadedProfile(): ?array
    {
        $raw = (string)$this->ReadAttributeString('Profile');
        if ($raw === '') {
            return null;
        }
        $key = md5($raw);
        if (is_array($this->profileCache) && $this->profileCache[0] === $key) {
            return $this->profileCache[1];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !is_array($d['profile'] ?? null)) {
            return null;
        }
        $p = $d['profile'];
        $p['luts'] = [];
        foreach ((array)($d['luts'] ?? []) as $mode => $packed) {
            if (in_array($mode, MHUBE_Engine::LUT_MODES, true)) {
                $t = MHUBE_Engine::ParseLut(MHUBE_Engine::UnpackLut((string)$packed), (string)$mode);
                if ($t) {
                    $p['luts'][$mode] = $t;
                }
            }
        }
        $this->profileCache = [$key, $p];
        return $p;
    }

    /** Das Profil, mit dem gerechnet wird (nach Betriebsart), oder null, wenn die Angaben nicht reichen. */
    private function EffectiveProfile(): ?array
    {
        switch ($this->ReadPropertyString('Mode')) {
            case 'fixed':
                return ['strategy' => 'fixed', 'standby' => $this->ReadPropertyFloat('StandbyPower'), 'standbyOn' => 0.0,
                    'fixed' => ['power' => $this->ReadPropertyFloat('PowerOn')]];
            case 'linear':
                $min = $this->ReadPropertyFloat('PowerMin');
                $max = $this->ReadPropertyFloat('PowerMax');
                if ($max <= $min) {
                    return null;
                }
                $p = ['strategy' => 'linear', 'standby' => $this->ReadPropertyFloat('StandbyPower'), 'standbyOn' => 0.0,
                    'linear' => ['calibrate' => [[0.0, $min], [100.0, $max]], 'scale' => 100]];
                if ($this->ReadPropertyFloat('Gamma') > 0) {
                    $p['linear']['gamma'] = $this->ReadPropertyFloat('Gamma');
                }
                return $p;
            case 'database':
                $p = $this->LoadedProfile();
                if ($p === null || isset($p['error'])) {
                    return null;
                }
                if ($p['strategy'] === 'lut' && !$p['luts']) {
                    return null;
                }
                return $p;
        }
        return null;
    }

    /** Was an den Einstellungen fehlt (Klartext, leer = in Ordnung). */
    private function Problems(): array
    {
        $out = [];
        $mode = $this->ReadPropertyString('Mode');
        if ($mode === 'linear' && $this->ReadPropertyFloat('PowerMax') <= $this->ReadPropertyFloat('PowerMin')) {
            $out[] = MHUBE_Text::t('The maximum power must be higher than the minimum power.');
        }
        if ($mode === 'database' && $this->EffectiveProfile() === null) {
            $out[] = MHUBE_Text::t('No device model loaded yet — search and load a model in the panel "Power model".');
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Rechnen
    // -----------------------------------------------------------------------

    /** Aktuelle Zeit (Sekunden mit Bruchteil); eigene Methode, damit die Prüfstände eine feste Uhr einsetzen können. */
    protected function Now(): float
    {
        return microtime(true);
    }

    /** Größte Lücke, die der Zähler noch überbrückt — danach (Symcon war aus) bleibt er stehen statt zu raten. */
    private function MaxGapSeconds(): float
    {
        return (float)max(300, 5 * max(10, $this->ReadPropertyInteger('Interval')));
    }

    /**
     * Ein Rechenschritt: Zähler mit der bisherigen Leistung bis jetzt fortschreiben, dann die Leistung neu schätzen.
     * Liefert die neue Leistung oder null, wenn nichts zu rechnen ist.
     */
    private function Tick(): ?float
    {
        $profile = $this->EffectiveProfile();
        if ($profile === null) {
            return null;
        }
        $pid = $this->OutputId(self::IDENT_POWER);
        $eid = $this->OutputId(self::IDENT_ENERGY);
        if ($pid <= 0 || $eid <= 0) {
            return null;
        }
        $power = MHUBE_Engine::Power($profile, $this->ReadState());
        if ($power === null) {
            return null;
        }
        $power = round($power, 3);
        $now = $this->Now();
        $last = (float)$this->ReadAttributeFloat('LastTs');
        $energy = (float)GetValue($eid);
        if ($last > 0) {
            $energy = MHUBE_Engine::Integrate($energy, (float)$this->ReadAttributeFloat('LastPower'), $now - $last, $this->MaxGapSeconds());
        }
        // ungerundet: Runden bei jedem Schritt verschöbe den Zähler systematisch (die Anzeige rundet über das Profil)
        $this->SetValue(self::IDENT_ENERGY, $energy);
        $this->SetValue(self::IDENT_POWER, $power);
        $this->WriteAttributeFloat('LastTs', $now);
        $this->WriteAttributeFloat('LastPower', $power);
        return $power;
    }

    /** Zeitgeber und Knopf „Jetzt rechnen“. */
    public function Update(): string
    {
        if (!$this->InstanceReady()) {
            return '';
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return MHUBE_Text::t('ℹ️ The instance is deactivated.');
        }
        $p = $this->Tick();
        return $p === null ? MHUBE_Text::t('ℹ️ Nothing to calculate yet — see the checks in the form.') : $this->Describe($p);
    }

    /** Klartext zum aktuellen Zustand (Vorschau im Formular). */
    private function Describe(?float $power = null): string
    {
        $s = $this->ReadState();
        $parts = [$s['on'] ? MHUBE_Text::t('on') : MHUBE_Text::t('off')];
        if (isset($s['percent'])) {
            $parts[] = sprintf(MHUBE_Text::t('brightness %s %%'), MHUBE_Text::num($s['percent'], 0));
        }
        if (isset($s['kelvin'])) {
            $parts[] = sprintf(MHUBE_Text::t('colour temperature %s K'), MHUBE_Text::num($s['kelvin'], 0));
        }
        if (isset($s['color'])) {
            $parts[] = sprintf(MHUBE_Text::t('colour #%06X'), $s['color'] & 0xFFFFFF);
        }
        $pw = $power ?? (float)$this->Val($this->OutputId(self::IDENT_POWER));
        $parts[] = sprintf(MHUBE_Text::t('→ %s W'), MHUBE_Text::num($pw, 2));
        return '✅ ' . implode(' · ', $parts);
    }

    // -----------------------------------------------------------------------
    // Datenbank: suchen und laden
    // -----------------------------------------------------------------------

    /** Die Modell-Liste (library.json), höchstens einen Tag alt; im Speicher gehalten, nicht in Dateien. */
    private function LibraryIndex(?string &$error = null): ?array
    {
        $buf = $this->GetBuffer('Index');
        $ts = (int)$this->GetBuffer('IndexTs');
        if ($buf !== '' && time() - $ts < 86400) {
            $d = json_decode($buf, true);
            if (is_array($d)) {
                return $d;
            }
        }
        $r = MHUBE_Http::Get(MHUBE_Http::$rawBase . 'library.json');
        if ($r['code'] !== 200) {
            $error = $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['code'];
            return null;
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d) || !isset($d['manufacturers'])) {
            $error = 'unexpected data';
            return null;
        }
        // nur, was gebraucht wird
        $slim = ['manufacturers' => []];
        foreach ((array)$d['manufacturers'] as $m) {
            $row = ['name' => $m['name'] ?? '', 'full_name' => $m['full_name'] ?? '', 'dir_name' => $m['dir_name'] ?? '', 'aliases' => $m['aliases'] ?? [], 'models' => []];
            foreach ((array)($m['models'] ?? []) as $md) {
                $row['models'][] = array_intersect_key($md, array_flip(['id', 'name', 'aliases', 'calculation_strategy', 'device_type', 'color_modes', 'sub_profile_count']));
            }
            $slim['manufacturers'][] = $row;
        }
        $this->SetBuffer('Index', (string)json_encode($slim));
        $this->SetBuffer('IndexTs', (string)time());
        return $slim;
    }

    /** Sucht in der Datenbank und füllt die Auswahlliste. */
    public function SearchModels(string $query): string
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return MHUBE_Text::t('ℹ️ Please enter at least two characters (manufacturer and/or model, e.g. "ikea tradfri").');
        }
        $err = '';
        $index = $this->LibraryIndex($err);
        if ($index === null) {
            return sprintf(MHUBE_Text::t('❌ The device database could not be loaded (%s). Is the internet connection available?'), $err);
        }
        $all = MHUBE_Engine::SearchIndex($index, $query, 400);
        $usable = array_values(array_filter($all, fn($r) => $r['supported']));
        $usable = array_slice($usable, 0, 100);
        $options = [];
        foreach ($usable as $r) {
            $options[] = ['caption' => $r['label'], 'value' => $r['manufacturer'] . '|' . $r['id']];
        }
        if (!$options) {
            $options[] = ['caption' => MHUBE_Text::t('— no usable match —'), 'value' => ''];
        }
        $this->UpdateFormField('SearchResult', 'options', (string)json_encode($options, JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('SearchResult', 'value', $options[0]['value']);
        $skipped = count($all) - count(array_filter($all, fn($r) => $r['supported']));
        if (!$usable) {
            return $all ? sprintf(MHUBE_Text::t('ℹ️ %d match(es), but none of them can be used by this module yet (calculation method not supported).'), count($all))
                : MHUBE_Text::t('ℹ️ No match. Try fewer or different words, or enter the values by hand (operating mode "fixed" or "linear").');
        }
        $msg = sprintf(MHUBE_Text::t('✅ %d usable match(es) — choose one in the list and click "Load selected model".'), count($usable));
        if ($skipped > 0) {
            $msg .= ' ' . sprintf(MHUBE_Text::t('(%d more not shown: calculation method not supported.)'), $skipped);
        }
        return $msg;
    }

    /** Lädt das gewählte Modell samt Messtabellen aus der Datenbank. */
    public function LoadModel(string $choice): string
    {
        $parts = explode('|', $choice, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return MHUBE_Text::t('ℹ️ Please search first and choose a model in the list.');
        }
        $err = '';
        $index = $this->LibraryIndex($err);
        if ($index === null) {
            return sprintf(MHUBE_Text::t('❌ The device database could not be loaded (%s). Is the internet connection available?'), $err);
        }
        // Nur Modelle laden, die die Liste wirklich kennt — Eingaben aus dem Formular werden nicht blind in eine Adresse gesetzt
        $entry = null;
        foreach ((array)$index['manufacturers'] as $m) {
            if (($m['dir_name'] ?? '') !== $parts[0]) {
                continue;
            }
            foreach ((array)$m['models'] as $md) {
                if ((string)($md['id'] ?? '') === $parts[1]) {
                    $entry = $md;
                    break 2;
                }
            }
        }
        if ($entry === null || !MHUBE_Engine::IndexEntrySupported($entry)) {
            return MHUBE_Text::t('ℹ️ This model is not in the list or cannot be used by this module yet.');
        }
        $base = MHUBE_Http::$rawBase . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]) . '/';
        $r = MHUBE_Http::Get($base . 'model.json');
        $model = $r['code'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($model)) {
            return sprintf(MHUBE_Text::t('❌ The model data could not be loaded (%s).'), $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['code']);
        }
        $profile = MHUBE_Engine::ProfileFromModel($model, $parts[0], $parts[1]);
        if (isset($profile['error'])) {
            return MHUBE_Text::t('ℹ️ This model uses a calculation method this module cannot reproduce yet.');
        }
        $authors = [];
        foreach ((array)($model['authors'] ?? []) as $a) {
            $n = trim((string)($a['name'] ?? $a['github'] ?? ''));
            if ($n !== '') {
                $authors[] = $n;
            }
        }
        $profile['authors'] = $authors;
        $luts = [];
        if ($profile['strategy'] === 'lut') {
            $modes = array_values(array_intersect((array)($entry['color_modes'] ?? []), MHUBE_Engine::LUT_MODES));
            if (!$modes) {
                $modes = ['brightness'];
            }
            foreach ($modes as $mode) {
                foreach (['.csv.gz', '.csv'] as $ext) {
                    $f = MHUBE_Http::Get($base . $mode . $ext);
                    if ($f['code'] === 200) {
                        $csv = MHUBE_Engine::CsvText($f['body']);
                        if (MHUBE_Engine::ParseLut($csv, $mode)) {
                            $luts[$mode] = MHUBE_Engine::PackLut($csv);
                            break;
                        }
                    }
                }
            }
            if (!$luts) {
                return MHUBE_Text::t('❌ The measurement tables of this model could not be loaded.');
            }
        }
        $this->WriteAttributeString('Profile', (string)json_encode(['profile' => $profile, 'luts' => $luts], JSON_UNESCAPED_UNICODE));
        $this->profileCache = null;
        $this->UpdateFormField('Mode', 'value', 'database');
        $this->UpdateFormField('ModelStatus', 'caption', $this->ModelStatusText());
        $this->OnChangeMode('database');
        if ($this->ReadPropertyString('Mode') === 'database' && $this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(102);
            $this->SetTimerInterval('Update', max(10, $this->ReadPropertyInteger('Interval')) * 1000);
            $this->Tick();
        }
        return MHUBE_Text::t('✅ Model loaded.') . ' ' . $this->ModelStatusText() . '. ' . MHUBE_Text::t('Click "Apply" to keep the operating mode "Device database".');
    }

    /** Entfernt das geladene Modell. */
    public function ClearModel(): string
    {
        $this->WriteAttributeString('Profile', '');
        $this->profileCache = null;
        $this->UpdateFormField('ModelStatus', 'caption', $this->ModelStatusText());
        return MHUBE_Text::t('ℹ️ The loaded model was removed.');
    }

    private function ModelStatusText(): string
    {
        $p = $this->LoadedProfile();
        if ($p === null) {
            return MHUBE_Text::t('No model loaded.');
        }
        $kinds = ['fixed' => MHUBE_Text::t('fixed power'), 'linear' => MHUBE_Text::t('linear'), 'lut' => MHUBE_Text::t('measurement table')];
        $t = sprintf(MHUBE_Text::t('Loaded: %s (%s)'), (string)($p['name'] ?? ''), $kinds[$p['strategy']] ?? (string)$p['strategy']);
        if ($p['strategy'] === 'lut') {
            $t .= ' — ' . implode(', ', array_keys($p['luts']));
        }
        if (!empty($p['authors'])) {
            $t .= ' — ' . sprintf(MHUBE_Text::t('measured by %s'), implode(', ', $p['authors']));
        }
        return $t;
    }

    // -----------------------------------------------------------------------
    // Vertrag (wie die anderen MeterHub-Quellen)
    // -----------------------------------------------------------------------

    public function GetFunctions(): string
    {
        if (!$this->InstanceReady()) {
            return (string)json_encode(['instanceID' => $this->InstanceID, 'ready' => false, 'assignments' => []]);
        }
        $func = $this->ReadPropertyString('Function');
        $pollInterval = max(10, $this->ReadPropertyInteger('Interval'));
        $list = [];
        if ($func !== '' && $func !== 'none' && isset(self::FUNCTIONS[$func])) {
            $list[] = [
                'contractType'   => 'meter',   // 1.6: Selbstbeschreibung (SUITE.md „Fremdanbindung“)
                'slot'           => 'main',
                'function'       => $func,
                'label'          => IPS_GetName($this->InstanceID),
                'powerID'        => $this->OutputId(self::IDENT_POWER),
                'energyImportID' => $this->OutputId(self::IDENT_ENERGY),
                'energyExportID' => 0,
                // geschätzt, nicht gemessen — Leistung UND Energie
                'measured'       => false,
                'energyMeasured' => false,
                'energyKind'     => 'counter',
                'sourceCount'    => 0,
                'members'        => [],
                'switchID'       => 0,
                'switchStateID'  => 0,
                'latency'        => 'realtime',
                'authority'      => 'auxiliary',
                'pollInterval'   => $pollInterval,
            ];
        }
        return (string)json_encode([
            // 1.6 = contractType 'meter' (SUITE.md „Fremdanbindung“); `meter` ist die Zählerart, nicht der Vertragstyp.
            'contractVersion' => '1.6',
            'contractType'    => 'meter',
            'instanceID'      => $this->InstanceID,
            'meter'           => 'estimate',
            'measureMode'     => 'combined',
            'latency'         => 'realtime',
            'authority'       => 'auxiliary',
            'pollInterval'    => $pollInterval,
            'archiveWatermarkTs' => null,
            'energyMeasured'  => false,
            'members'         => [],
            'switchID'        => 0,
            'switchStateID'   => 0,
            'assignments'     => $list,
        ]);
    }

    // -----------------------------------------------------------------------
    // Formular
    // -----------------------------------------------------------------------

    /** Welche Bereiche zu welcher Betriebsart sichtbar sind. */
    private function ModeVisibility(string $mode): array
    {
        return [
            'ModelFixed'    => $mode === 'fixed',
            'ModelLinear'   => $mode === 'linear',
            'ModelDatabase' => $mode === 'database',
            'StandbyPower'  => $mode !== 'database',
            'SourceBrightness' => $mode !== 'fixed',
            'SourceColor'      => $mode === 'database',
        ];
    }

    public function OnChangeMode(string $mode)
    {
        foreach ($this->ModeVisibility($mode) as $name => $visible) {
            $this->UpdateFormField($name, 'visible', $visible);
        }
    }

    public function GetConfigurationForm()
    {
        return json_encode($this->BuildForm());
    }

    private function BuildForm(bool $freeOnly = false)
    {
        if (!$freeOnly) {
            $this->AdoptDismissFromSibling();
        }
        $vis = $this->ModeVisibility($this->ReadPropertyString('Mode'));
        $funcOptions = [];
        foreach (self::FUNCTIONS as $key => $def) {
            $funcOptions[] = ['caption' => $def[0], 'value' => $key];
        }
        $problems = $freeOnly ? [] : $this->Problems();
        $checkLines = [];
        if (!$freeOnly) {
            foreach ($problems as $pr) {
                $checkLines[] = ['type' => 'Label', 'caption' => '⚠️ ' . $pr];
            }
            if (!$problems && $this->ReadPropertyBoolean('Active')) {
                $checkLines[] = ['type' => 'Label', 'caption' => $this->Describe()];
            }
        }
        $selected = $this->ReadPropertyString('SearchResult');
        $resultOptions = [['caption' => $this->txt('— search first —'), 'value' => '']];
        if ($selected !== '') {
            $resultOptions[] = ['caption' => $selected, 'value' => $selected];
        }

        $free = [
            'device' => [
                'type' => 'ExpansionPanel', 'caption' => '🔌  Device', 'expanded' => true,
                'items' => [
                    ['type' => 'Select', 'name' => 'Function', 'caption' => 'Function (for the dashboard)', 'options' => $funcOptions],
                    ['type' => 'ValidationTextBox', 'name' => 'Location', 'caption' => 'Location (free text, e.g. room)'],
                    ['type' => 'SelectVariable', 'name' => 'SwitchID', 'caption' => 'Switch (on/off) of the device'],
                    [
                        'type' => 'ExpansionPanel', 'name' => 'SourceBrightness', 'caption' => 'Brightness / level', 'expanded' => true, 'visible' => $vis['SourceBrightness'],
                        'items' => [
                            ['type' => 'SelectVariable', 'name' => 'BrightnessID', 'caption' => 'Brightness or level (optional)'],
                            ['type' => 'Select', 'name' => 'BrightnessScale', 'caption' => 'Value range of this variable', 'options' => [
                                ['caption' => 'Detect automatically', 'value' => 'auto'],
                                ['caption' => '0 – 100 (percent)', 'value' => '100'],
                                ['caption' => '0 – 255', 'value' => '255'],
                                ['caption' => '0 – 1', 'value' => '1'],
                            ]],
                        ],
                    ],
                    [
                        'type' => 'ExpansionPanel', 'name' => 'SourceColor', 'caption' => 'Colour (lamps)', 'expanded' => true, 'visible' => $vis['SourceColor'],
                        'items' => [
                            ['type' => 'SelectVariable', 'name' => 'ColorTempID', 'caption' => 'Colour temperature (optional)'],
                            ['type' => 'Select', 'name' => 'ColorTempUnit', 'caption' => 'Unit of the colour temperature', 'options' => [
                                ['caption' => 'Detect automatically', 'value' => 'auto'],
                                ['caption' => 'Kelvin', 'value' => 'kelvin'],
                                ['caption' => 'Mired', 'value' => 'mired'],
                            ]],
                            ['type' => 'SelectVariable', 'name' => 'ColorID', 'caption' => 'Colour (optional)'],
                            ['type' => 'Select', 'name' => 'LightMode', 'caption' => 'Which measurement table applies', 'options' => [
                                ['caption' => 'Automatic (colour temperature if given, otherwise colour, otherwise brightness)', 'value' => 'auto'],
                                ['caption' => 'Brightness only', 'value' => 'brightness'],
                                ['caption' => 'Colour temperature (white)', 'value' => 'color_temp'],
                                ['caption' => 'Colour', 'value' => 'hs'],
                            ]],
                            ['type' => 'Label', 'caption' => 'A lamp with both colour temperature and colour is either white or coloured at a time. Choose the table that matches how you mostly use it.'],
                        ],
                    ],
                ],
            ],
            'model' => [
                'type' => 'ExpansionPanel', 'caption' => '📚  Power model', 'expanded' => true,
                'items' => [
                    ['type' => 'Select', 'name' => 'Mode', 'caption' => 'Operating mode', 'onChange' => 'MHUBE_OnChangeMode($id, $Mode);', 'options' => [
                        ['caption' => 'Fixed power (on / standby)', 'value' => 'fixed'],
                        ['caption' => 'Linear (from brightness or level)', 'value' => 'linear'],
                        ['caption' => 'Device database (Powercalc)', 'value' => 'database'],
                    ]],
                    [
                        'type' => 'ExpansionPanel', 'name' => 'ModelFixed', 'caption' => 'Fixed power', 'expanded' => true, 'visible' => $vis['ModelFixed'],
                        'items' => [
                            ['type' => 'NumberSpinner', 'name' => 'PowerOn', 'caption' => 'Power when on', 'digits' => 2, 'minimum' => 0, 'suffix' => 'W'],
                            ['type' => 'Label', 'caption' => 'Without a switch variable the device counts as always on.'],
                        ],
                    ],
                    [
                        'type' => 'ExpansionPanel', 'name' => 'ModelLinear', 'caption' => 'Linear', 'expanded' => true, 'visible' => $vis['ModelLinear'],
                        'items' => [
                            ['type' => 'NumberSpinner', 'name' => 'PowerMin', 'caption' => 'Power at 0 % (lowest level)', 'digits' => 2, 'minimum' => 0, 'suffix' => 'W'],
                            ['type' => 'NumberSpinner', 'name' => 'PowerMax', 'caption' => 'Power at 100 %', 'digits' => 2, 'minimum' => 0, 'suffix' => 'W'],
                            ['type' => 'NumberSpinner', 'name' => 'Gamma', 'caption' => 'Curve (gamma, 0 = straight line)', 'digits' => 2, 'minimum' => 0, 'maximum' => 5],
                            ['type' => 'Label', 'caption' => 'The power rises between the two values with the level of the variable chosen under "Brightness / level". A gamma above 1 bends the curve down (dimmed lamps use proportionally less).'],
                        ],
                    ],
                    ['type' => 'NumberSpinner', 'name' => 'StandbyPower', 'caption' => 'Power when off (standby)', 'digits' => 2, 'minimum' => 0, 'suffix' => 'W', 'visible' => $vis['StandbyPower']],
                    [
                        'type' => 'ExpansionPanel', 'name' => 'ModelDatabase', 'caption' => 'Device database', 'expanded' => true, 'visible' => $vis['ModelDatabase'],
                        'items' => [
                            ['type' => 'Label', 'caption' => 'Enter manufacturer and/or model, search, choose, load. The data is fetched from GitHub only when you click; it is stored in this instance, so no connection is needed afterwards.'],
                            ['type' => 'ValidationTextBox', 'name' => 'SearchText', 'caption' => 'Search (e.g. ikea tradfri, hue, fritz)'],
                            ['type' => 'Button', 'caption' => '🔍  Search', 'onClick' => 'echo MHUBE_SearchModels($id, $SearchText);'],
                            ['type' => 'Select', 'name' => 'SearchResult', 'caption' => 'Matches', 'options' => $resultOptions],
                            ['type' => 'Button', 'caption' => '⬇️  Load selected model', 'onClick' => 'echo MHUBE_LoadModel($id, $SearchResult);'],
                            ['type' => 'Label', 'name' => 'ModelStatus', 'caption' => $freeOnly ? '' : $this->ModelStatusText()],
                            ['type' => 'Button', 'caption' => '🗑️  Remove loaded model', 'onClick' => 'echo MHUBE_ClearModel($id);'],
                            ['type' => 'Label', 'caption' => 'Device database and calculation methods: Powercalc for Home Assistant by Bram Gerritsen and contributors (MIT license). The module contains no data of the database; each model is loaded from there on your click.'],
                            ['type' => 'Button', 'caption' => 'Open Powercalc on GitHub', 'onClick' => "echo '" . self::POWERCALC_URL . "';", 'link' => true],
                        ],
                    ],
                ],
            ],
            'check' => ['type' => 'ExpansionPanel', 'caption' => '🔎  Check & preview', 'expanded' => true, 'items' => array_merge($checkLines, [
                ['type' => 'Button', 'caption' => '🔄  Calculate now', 'onClick' => 'echo MHUBE_Update($id);'],
            ])],
            'calc' => [
                'type' => 'ExpansionPanel', 'caption' => '⏱️  Calculation', 'expanded' => false,
                'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Recalculate at least every', 'minimum' => 10, 'maximum' => 3600, 'suffix' => 's'],
                    ['type' => 'Label', 'caption' => 'Every change of a source variable is calculated at once; this interval only keeps the energy meter running while nothing changes. If Symcon was not running for longer than five intervals (at least five minutes), the meter does not bridge that gap — it stays where it was instead of guessing.'],
                ],
            ],
        ];
        if ($freeOnly) {
            return $free;
        }
        $lay = $this->ApplyFormLayout($free);
        $top = [
            ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Active'],
            ['type' => 'ValidationTextBox', 'name' => 'InstanceName', 'caption' => 'Name of this instance', 'value' => IPS_GetName($this->InstanceID),
                'onChange' => 'MHUBE_RenameInstance($id, $InstanceName);'],
            $this->PurposeIntro(),
            $this->NewsBanner(),
            $this->ArchivingPanel(),
            ['type' => 'ExpansionPanel', 'caption' => '📖  Documentation & help', 'expanded' => false, 'items' => [
                ['type' => 'Label', 'caption' => '1. Choose the device\'s switch variable (and, if it has one, its brightness / colour variables).'],
                ['type' => 'Label', 'caption' => '2. Choose how to estimate: fixed watts, linear from brightness, or a model from the device database.'],
                ['type' => 'Label', 'caption' => '3. Choose a function for the dashboard, click "Apply". The instance now provides "Power (estimated)" and "Energy (estimated)".'],
                ['type' => 'Label', 'caption' => '4. Optional: enable archiving (the notice appears at the top) so the energy shows up in daily and monthly values.'],
                ['type' => 'Label', 'caption' => 'The estimate is marked as "not measured" towards the dashboard and the groups. In a meter group (MeterHubVirtual) it can fill the "rest" of the house with real numbers instead of an unknown blob.'],
            ]],
        ];
        return [
            'elements' => array_values(array_filter(array_merge($top, $lay['panels'],
                [$this->LayoutPanel($lay['layout'], $free), $this->ForumHint(), $this->LicenseHint()]))),
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Estimating.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Deactivated.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Settings incomplete — see the check.'],
            ],
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
        return MHUBE_Text::t('ℹ️ Reset to default. Click "Apply" below to save.');
    }
}
