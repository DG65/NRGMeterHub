<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Prüfstand `phaseCurrentIDs` im Vertrag MHUB_GetFunctions 1.5 (EMS-Phasenwächter, SUITE.md „Vertragsfeld phaseCurrentIDs“,
 * MeterHub 0.39.0): nur bei Netzanschluss-Zuordnung (`grid`, kombinierter Modus), aktiver Gruppe „Strom je Phase“ und allen
 * drei Phasenstrom-Variablen; sonst fehlt das Feld. Die IDs sind die der echt gemessenen Variablen i_l1/i_l2/i_l3.
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KR_READY = 104;
const VARIABLE_PRESENTATION_LEGACY = '{4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C}';
const IPS_KERNELMESSAGE = 10603;
$GLOBALS['RUNLEVEL'] = KR_READY;
function IPS_GetKernelRunlevel() { return $GLOBALS['RUNLEVEL']; }

$GLOBALS['OBJ'] = [];      // id => ObjectType/ObjectIdent/ObjectName/ParentID
$GLOBALS['VAR'] = [];      // id => VariableType/Profile/Updated
$GLOBALS['VAL'] = [];
$GLOBALS['PROP'] = [];     // iid => name => wert
$GLOBALS['INSTMOD'] = [];  // iid => GUID
$GLOBALS['MODOBJ'] = [];   // iid => PHP-Objekt
$GLOBALS['NEXTID'] = 9000;
$GLOBALS['FORMFIELDS'] = [];

function obj($id, $type, $name, $parent, $ident = '') {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => $type, 'ObjectIdent' => $ident, 'ObjectName' => $name, 'ParentID' => $parent, 'ObjectPosition' => 0];
    return $id;
}

// Objektbaum-Modus (0.25.0): Meldungs-Konstanten live an Dietmars Anlage
// abgelesen (11.09.2026), Links als ObjectType 6.
const OM_UNREGISTER = 10402;
const OM_CHANGENAME = 10404;
const OM_CHANGEPOSITION = 10408;
const OM_CHILDADDED = 10412;
const OM_CHILDREMOVED = 10413;
const LM_CHANGETARGET = 11003;
$GLOBALS['LINKS'] = [];
function IPS_CreateLink() {
    $id = $GLOBALS['NEXTID']++;
    obj($id, 6, 'Verknüpfung', 0);
    $GLOBALS['LINKS'][$id] = 0;
    return $id;
}
function IPS_SetLinkTargetID($id, $t) { $GLOBALS['LINKS'][$id] = $t; return true; }
function IPS_GetLink($id) { return isset($GLOBALS['LINKS'][$id]) ? ['TargetID' => $GLOBALS['LINKS'][$id]] : false; }
function IPS_DeleteLink($id) { unset($GLOBALS['OBJ'][$id], $GLOBALS['LINKS'][$id]); return true; }
// Bisherige Blöcke 1–34 prüfen die Tabellen-Mitglieder. Seit 0.25.0 startet
// eine Instanz ohne Tabellenzeilen im Objektbaum-Modus — die alten Blöcke
// halten deshalb ausdrücklich an der Tabelle fest (MemberSource='list');
// Block 35 schaltet das ab und prüft den echten Standard.
$GLOBALS['LEGACY_LIST'] = true;
function vari($id, $name, $parent, $ident, $profile, $value, $age = 0, $type = 2, $action = 0) {
    obj($id, 2, $name, $parent, $ident);
    // $type: 0=Bool 1=Int 2=Float; $action > 0 = Variable hat eine Aktion
    // (Schaltaktor) — beides für die Schaltgruppen-Prüfungen (Block 34).
    $GLOBALS['VAR'][$id] = ['VariableType' => $type, 'VariableProfile' => $profile,
                            'VariableCustomProfile' => '', 'VariableUpdated' => time() - $age,
                            'VariableAction' => $action, 'VariableCustomAction' => 0];
    $GLOBALS['VAL'][$id] = $value;
    return $id;
}

function IPS_ObjectExists($id)    { return isset($GLOBALS['OBJ'][$id]); }
function IPS_InstanceExists($id)  { return isset($GLOBALS['INSTMOD'][$id]); }
function IPS_VariableExists($id)  { return isset($GLOBALS['VAR'][$id]); }
function IPS_GetObject($id)       { return $GLOBALS['OBJ'][$id] ?? null; }
function IPS_GetVariable($id)     { return $GLOBALS['VAR'][$id] ?? false; }
function IPS_GetName($id)         { return $GLOBALS['OBJ'][$id]['ObjectName'] ?? ('#' . $id); }
function IPS_GetParent($id)       { return $GLOBALS['OBJ'][$id]['ParentID'] ?? 0; }
function IPS_SetName($id, $n)     { $GLOBALS['OBJ'][$id]['ObjectName'] = $n; }
function IPS_SetParent($id, $p)   { $GLOBALS['OBJ'][$id]['ParentID'] = $p; }
function IPS_SetIdent($id, $i)    { $GLOBALS['OBJ'][$id]['ObjectIdent'] = $i; }
function IPS_SetPosition($id, $p) { $GLOBALS['OBJ'][$id]['ObjectPosition'] = $p; }
function IPS_SetInfo($id, $text)  { $GLOBALS['OBJ'][$id]['ObjectInfo'] = $text; }
function IPS_GetVariableList()    { return array_keys($GLOBALS['VAR']); }
function GetValue($id)            { return $GLOBALS['VAL'][$id] ?? 0; }
function SetValueFloat($id, $v)   { $GLOBALS['VAL'][$id] = $v; }
function SetValueInteger($id, $v) { $GLOBALS['VAL'][$id] = (int)$v; }
function SetValueBoolean($id, $v) { $GLOBALS['VAL'][$id] = (bool)$v; }
// Globale Aktions-Auslösung (Symcon-Kernfunktion): schaltet die Ziel-
// variable eines Aktors — im Prüfstand schlicht der Wert.
function RequestAction($id, $v)   { $GLOBALS['VAL'][$id] = $v; $GLOBALS['REQUESTED'][] = [$id, $v]; return true; }

function IPS_GetChildrenIDs($id) {
    $out = [];
    foreach ($GLOBALS['OBJ'] as $k => $o) { if ($o['ParentID'] == $id) { $out[] = $k; } }
    return $out;
}
function IPS_GetObjectIDByIdent($ident, $parent) {
    foreach (IPS_GetChildrenIDs($parent) as $c) {
        if ($GLOBALS['OBJ'][$c]['ObjectIdent'] === $ident) { return $c; }
    }
    return false;
}
function IPS_CreateVariable($t) {
    $id = $GLOBALS['NEXTID']++;
    obj($id, 2, 'neu', 0);
    $GLOBALS['VAR'][$id] = ['VariableType' => $t, 'VariableProfile' => '', 'VariableCustomProfile' => '', 'VariableUpdated' => time()];
    $GLOBALS['VAL'][$id] = 0;
    return $id;
}
function IPS_SetVariableCustomProfile($id, $p) { $GLOBALS['VAR'][$id]['VariableCustomProfile'] = $p; }
function IPS_DeleteVariable($id) { unset($GLOBALS['OBJ'][$id], $GLOBALS['VAR'][$id]); }
foreach (['KL_MESSAGE' => 101, 'KL_WARNING' => 104, 'KL_ERROR' => 103, 'KL_NOTIFY' => 102, 'KL_DEBUG' => 100, 'KL_CUSTOM' => 105] as $c => $v) { if (!defined($c)) { define($c, $v); } }
function IPS_CreateCategory() { $GLOBALS['CATEGORIES_CREATED']++; $id = $GLOBALS['NEXTID']++; obj($id, 0, 'neu', 0); return $id; }
function IPS_DeleteCategory($id) { unset($GLOBALS['OBJ'][$id]); }
$GLOBALS['CATEGORIES_CREATED'] = 0;
// Echte, zustandsbehaftete Profil-Registry (statt fixer Whitelist) — nötig,
// um zu verifizieren, dass ensureSharedProfile() ein bereits vorhandenes
// Profil NICHT überschreibt (Verbund-Konvention: kein Eigentümer-Modul).
$GLOBALS['PROFILES'] = [
    'MHB.W'   => ['Digits' => 0, 'Suffix' => ' W'],
    'MHB.kWh' => ['Digits' => 1, 'Suffix' => ' kWh'],
    'MHB.A'   => ['Digits' => 1, 'Suffix' => ' A'],
];
function IPS_VariableProfileExists($n) { return isset($GLOBALS['PROFILES'][$n]); }
function IPS_GetVariableProfile($n) { return $GLOBALS['PROFILES'][$n] ?? ['Suffix' => '']; }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['PROFILES'][$n] = $GLOBALS['PROFILES'][$n] ?? ['Digits' => 0, 'Suffix' => '']; }
function IPS_SetVariableProfileDigits($n, $d) { $GLOBALS['PROFILES'][$n]['Digits'] = $d; }
function IPS_SetVariableProfileText($n, $a, $b) { $GLOBALS['PROFILES'][$n]['Suffix'] = $b; }
function IPS_SetVariableProfileIcon($n, $i) { $GLOBALS['PROFILES'][$n]['Icon'] = $i; }
function IPS_SetVariableProfileAssociation($n, $v, $name, $icon, $color) { $GLOBALS['PROFILES'][$n]['Assoc'][$v] = $name; }
function IPS_GetInstanceListByModuleID($guid) {
    $out = [];
    foreach ($GLOBALS['INSTMOD'] as $iid => $g) { if ($g === $guid) { $out[] = $iid; } }
    return $out;
}
function IPS_GetInstance($iid) {
    return ['ModuleInfo' => ['ModuleID' => $GLOBALS['INSTMOD'][$iid] ?? ''], 'InstanceStatus' => $GLOBALS['INSTSTATUS'][$iid] ?? 102];
}
// Modul-Präfix für die Vertragserkennung (ContractMetersOf(), 0.26.1) plus
// ein ChargerHub-artiger Testvertrag.
$GLOBALS['MODULES'] = [];
function IPS_GetModule($guid) { return $GLOBALS['MODULES'][$guid] ?? ['ModuleID' => $guid, 'Prefix' => '']; }
function TESTCHUB_GetFunctions($iid) { return $GLOBALS['CONTRACT'][$iid] ?? []; }
function IPS_CreateInstance($guid) {
    $id = $GLOBALS['NEXTID']++;
    // Bestandsinstanzen: die Verdichtung steht gespeichert AN (so war der Standard seit 31.08.2026,
    // der Wert liegt in jeder Instanzkonfiguration). Neue Instanzen mit Standard „aus" bildet
    // Block 58 gesondert nach.
    $GLOBALS['PROP'][$id]['AutoCompactionPower'] = true;
    $GLOBALS['PROP'][$id]['AutoCompactionEnergy'] = true;
    obj($id, 1, 'neue Instanz', 0);
    $GLOBALS['INSTMOD'][$id] = $guid;
    return $id;
}
function IPS_SetProperty($iid, $n, $v) { $GLOBALS['PROP'][$iid][$n] = $v; }
function IPS_GetProperty($iid, $n)     { return $GLOBALS['PROP'][$iid][$n] ?? ''; }
function IPS_ApplyChanges($iid) {
    // Das ist der eigentliche Integrationstest: Die frisch erzeugte
    // Formel muss vom Zielmodul auch akzeptiert werden.
    if ($GLOBALS['INSTMOD'][$iid] === '{ADF18291-2E60-4354-92F5-B96863C127C8}') {
        $m = new MeterHubVirtual($iid);
        $m->Create();
        $GLOBALS['MODOBJ'][$iid] = $m;
        $m->ApplyChanges();
    }
}
function AC_SetLoggingStatus($a, $b, $c) { if ($c) { $GLOBALS['ARCHIVED'][$b] = true; } else { unset($GLOBALS['ARCHIVED'][$b]); } }
function AC_SetAggregationType($a, $vid, $type) { $GLOBALS['AGGTYPE'][$vid] = $type; }
function AC_GetAggregationType($a, $vid) { return $GLOBALS['AGGTYPE'][$vid] ?? 0; }
function AC_ReAggregateVariable($ac, $vid) { $GLOBALS['REAGG'][] = $vid; return true; }
// Bildet Sepps zweiten Fund vom 01.09.2026 nach ("Verdichtungseinträge, die
// näher an der Gegenwart sind, müssen lockerer als die vorherigen sein"),
// live an Dietmars Archiv nachgestellt: der Verdichtungstyp darf mit
// wachsendem Monats-Offset nie wieder feiner werden. $GLOBALS['COMPACTION']
// bleibt als reines Aufruf-Protokoll bestehen (viele bestehende Prüfungen
// verlassen sich darauf) — $GLOBALS['COMPACTION_STATE'] bildet zusätzlich den
// tatsächlichen Archiv-Zustand je Variable nach, inkl. der beiden echten
// IPS-Warnungen als PHP-Warnung (durch @ im Modulcode unterdrückt).
function AC_SetCompaction($a, $vid, $offset, $type) {
    $GLOBALS['COMPACTION'][$vid][] = [$offset, $type];
    $state = $GLOBALS['COMPACTION_STATE'][$vid] ?? [];
    if ($type === -1) {
        if (!array_key_exists($offset, $state)) {
            trigger_error('Der zu löschende Verdichtungseintrag wurde nicht gefunden', E_USER_WARNING);
            return false;
        }
        unset($state[$offset]);
        $GLOBALS['COMPACTION_STATE'][$vid] = $state;
        return true;
    }
    foreach ($state as $existOffset => $existType) {
        if (($existOffset < $offset && $existType > $type) || ($existOffset > $offset && $existType < $type)) {
            trigger_error('Verdichtungseinträge, die näher an der Gegenwart sind, müssen lockerer als die vorherigen sein', E_USER_WARNING);
            return false;
        }
    }
    $state[$offset] = $type;
    $GLOBALS['COMPACTION_STATE'][$vid] = $state;
    return true;
}
function AC_GetCompaction($a, $vid) {
    $out = [];
    foreach (($GLOBALS['COMPACTION_STATE'][$vid] ?? []) as $offset => $type) {
        $out[] = ['MonthOffset' => $offset, 'CompactionType' => $type];
    }
    return $out;
}
function AC_GetLoggingStatus($a, $vid) { return isset($GLOBALS['ARCHIVED'][$vid]); }

class IPSModule
{
    use I18nStub;
    public $InstanceID;
    protected $defs = [];
    public function __construct($id) {
        $this->InstanceID = $id;
        if ($GLOBALS['LEGACY_LIST'] && !isset($GLOBALS['PROP'][$id]['MemberSource'])) {
            $GLOBALS['PROP'][$id]['MemberSource'] = 'list';
        }
    }
    public function Create() {}
    public function Destroy() {}
    public function ApplyChanges() {}
    protected function ConnectParent($guid) {}
    protected function RegisterPropertyString($n, $v)  { $this->defs[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->defs[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->defs[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v)   { $this->defs[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? false); }
    public function ReadPropertyFloat($n)   { return (float)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0.0); }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    // RegisterVariable*()/UnregisterVariable() wie der Kern: Ident direkt unter der Instanz, bei vorhandener
    // Variable nur die Darstellung aktualisieren (Name/Position bleiben), bei anderem Typ neu anlegen.
    // $GLOBALS['PRELOGGED'] (Standard an) simuliert einen Benutzer, der das Logging schon eingeschaltet hat.
    private function t_reg($type, $ident, $name, $pres, $pos) {
        $vid = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== $type) { IPS_DeleteVariable($vid); $vid = false; }
        if (!$vid) {
            $vid = IPS_CreateVariable($type);
            IPS_SetIdent($vid, $ident); IPS_SetParent($vid, $this->InstanceID); IPS_SetName($vid, $name); IPS_SetPosition($vid, $pos);
            if ($GLOBALS['PRELOGGED'] ?? true) { $GLOBALS['ARCHIVED'][$vid] = true; }
        }
        $GLOBALS['VAR'][$vid]['VariablePresentation'] = is_array($pres) ? $pres : [];
        $GLOBALS['VAR'][$vid]['VariableProfile'] = is_array($pres) ? (string)($pres['PROFILE'] ?? '') : (string)$pres;
        return $vid;
    }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0)   { return $this->t_reg(VARIABLETYPE_FLOAT, $i, $n, $p, $pos); }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { return $this->t_reg(VARIABLETYPE_INTEGER, $i, $n, $p, $pos); }
    protected function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { return $this->t_reg(VARIABLETYPE_BOOLEAN, $i, $n, $p, $pos); }
    protected function RegisterVariableString($i, $n, $p = '', $pos = 0)  { return $this->t_reg(VARIABLETYPE_STRING, $i, $n, $p, $pos); }
    protected function UnregisterVariable($ident) {
        $vid = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (!$vid) { return false; }
        IPS_DeleteVariable($vid);
        return true;
    }
    protected function SetStatus($s) { $GLOBALS['STATUS'][$this->InstanceID] = $s; }
    public function GetStatus() { return $GLOBALS['STATUS'][$this->InstanceID] ?? 0; }
    protected function SetVisualizationType($t) {}
    protected function RegisterMessage($senderID, $message) { $GLOBALS['MSGS'][$this->InstanceID][$senderID . ':' . $message] = true; }
    protected function UnregisterMessage($senderID, $message) { unset($GLOBALS['MSGS'][$this->InstanceID][$senderID . ':' . $message]); }
    protected function EnableAction($ident) { $GLOBALS['ENABLED_ACTIONS'][$this->InstanceID][$ident] = true; }
    protected function SendDebug($sender, $msg, $format) {}
    public function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFIELDS'][$f][$p] = (in_array($p, ['caption', 'label'], true) && is_string($v)) ? i18n_tr($v) : $v; }   // die Konsole übersetzt auch nachgeschobene Texte
    public function LogMessage($m, $type) { $GLOBALS['LOGMSG'][] = $m; }
    protected function ReloadForm() {}
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeInteger($n)  { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeBoolean($n)  { return (bool)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
}

require_once __DIR__ . '/ref/harness.php';
MHUB_Text::set(function (string $t): string { return i18n_tr($t); });

if (!function_exists('IPS_SetIcon')) { function IPS_SetIcon($id, $icon) { $GLOBALS['OBJ'][$id]['ObjectIcon'] = $icon; } }
function AC_GetLoggedValues($ac, $vid, $a, $b, $l) { return []; }
obj(50, 1, 'Archive Control', 0);
$GLOBALS['INSTMOD'][50] = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
class PhHub extends MeterHub
{
    protected function SetTimerInterval($n, $i) { }
    protected function RegisterTimer($n, $i, $s) { }
    public function SetBuffer($n, $v) { $GLOBALS['BUF'][$this->InstanceID][$n] = $v; }
    public function GetBuffer($n) { return (string)($GLOBALS['BUF'][$this->InstanceID][$n] ?? ''); }
}
$next = 3000;
function hub(array $props, array $attrs = []): PhHub
{
    global $next;
    $iid = $next; $next += 1000;
    obj($iid, 1, 'Netzzähler', 10);
    $GLOBALS['INSTMOD'][$iid] = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    $GLOBALS['PROP'][$iid] = $props + ['Meter' => 'siemens_pac2200', 'Active' => true, 'Host' => '10.0.0.5', 'MeasureMode' => 'combined', 'FuncTotal' => 'grid', 'GroupCurrentPhase' => true];
    foreach ($attrs as $k => $v) { $GLOBALS['ATTR'][$iid][$k] = $v; }
    $h = new PhHub($iid);
    $h->Create();
    $GLOBALS['MODOBJ'][$iid] = $h;
    $h->ApplyChanges();
    return $h;
}
function ident(PhHub $h, string $ident): int { return (int)(new ReflectionMethod('MeterHub', 'FindVarByIdent'))->invoke($h, $ident); }
function gf(PhHub $h): array { return json_decode($h->GetFunctions(), true); }

$cases = [];
$a = hub([]);
$g = gf($a);
$ids = [ident($a, 'i_l1'), ident($a, 'i_l2'), ident($a, 'i_l3')];
check('1a: Netzzähler (grid) mit Gruppe „Strom je Phase“: phaseCurrentIDs = [i_l1, i_l2, i_l3]', ($g['assignments'][0]['phaseCurrentIDs'] ?? null) === $ids && !in_array(0, $ids, true), json_encode([$g['assignments'][0]['phaseCurrentIDs'] ?? 'fehlt', $ids]));
check('1b: Vertragsversion 1.5', ($g['contractVersion'] ?? '') === '1.5', $g['contractVersion'] ?? 'fehlt');
check('1c: die drei IDs sind verschieden und gehören zu Phasenstrom-Variablen (Profil Ampere)', count(array_unique($ids)) === 3 && ($GLOBALS['VAR'][$ids[0]]['VariablePresentation']['PROFILE'] ?? '') === 'NRG.Ampere');

$b = hub(['FuncTotal' => 'house']);
check('2: Zuordnung „house“ statt „grid“: kein phaseCurrentIDs', !array_key_exists('phaseCurrentIDs', gf($b)['assignments'][0]));
$c = hub(['GroupCurrentPhase' => false]);
check('3: Gruppe „Strom je Phase“ aus: kein phaseCurrentIDs', !array_key_exists('phaseCurrentIDs', gf($c)['assignments'][0]));
// Zustand zwischen Ändern und Übernehmen: Variablen existieren noch, die Einstellung sagt schon etwas anderes — der Vertrag folgt der Einstellung
$c2 = hub([]);
$GLOBALS['PROP'][$c2->InstanceID]['GroupCurrentPhase'] = false;
check('3b: Gruppe nachträglich ausgeschaltet (Variablen noch da): kein phaseCurrentIDs', !array_key_exists('phaseCurrentIDs', gf($c2)['assignments'][0]));
$d2 = hub([]);
$GLOBALS['PROP'][$d2->InstanceID]['Meter'] = 'eastron_sdm120';
check('4b: Zählertyp nachträglich auf einphasig gestellt (Variablen noch da): kein phaseCurrentIDs', !array_key_exists('phaseCurrentIDs', gf($d2)['assignments'][0]));
$d = hub(['Meter' => 'eastron_sdm120']);
check('4: einphasiger Zähler (SDM120) hat keine Phasenströme: kein phaseCurrentIDs', !array_key_exists('phaseCurrentIDs', gf($d)['assignments'][0]));
$e = hub(['Meter' => 'inexogy', 'InexogyMeterID' => 'abc'], ['InexogyToken' => 't']);
check('5: Inexogy (nur Phasenleistung, kein Strom): kein phaseCurrentIDs', !array_key_exists('phaseCurrentIDs', gf($e)['assignments'][0] ?? []));
$f = hub(['MeasureMode' => 'perphase', 'FuncTotal' => 'none', 'FuncL1' => 'grid', 'FuncL2' => 'grid', 'FuncL3' => 'grid']);
$gl = gf($f)['assignments'];
check('6: je-Phase-Modus (drei Zuordnungen): kein phaseCurrentIDs (die Ströme gehören zum ganzen Anschluss)', count($gl) === 3 && count(array_filter($gl, fn($x) => array_key_exists('phaseCurrentIDs', $x))) === 0, json_encode(array_column($gl, 'slot')));
$h2 = hub(['Meter' => 'shelly_pro3em']);
$ids2 = [ident($h2, 'i_l1'), ident($h2, 'i_l2'), ident($h2, 'i_l3')];
check('7: Shelly Pro 3EM (dreiphasig, echte Phasenströme): phaseCurrentIDs vorhanden', (gf($h2)['assignments'][0]['phaseCurrentIDs'] ?? null) === $ids2);
// ein Wert pro Lesezyklus: der Hub schreibt die Phasenströme bei jedem Lesezyklus (SetVarFloat, auch bei gleichem Wert)
$hub = new RefHub();
$drv = new MHUB_Pac2200Driver();
$mb = RefMb::make('pos', null, new RefMb());
$drv->readFast($mb, $hub);
check('8: der Treiber schreibt die Phasenströme in readFast (jeder schnelle Lesezyklus)', isset($hub->vars['i_l1'], $hub->vars['i_l2'], $hub->vars['i_l3']));

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
