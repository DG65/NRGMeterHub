<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Referenzlauf MeterHub::ApplyChanges() (18.11.2026): vorher lief die Funktion in keinem Prüfstand (die Prüfstände
 * riefen RegisterVariables() direkt auf). Festgehalten wird das HEUTIGE Verhalten je Zustand: Status, Timer, registrierte
 * Variablen je Zählertyp, und der Rückfallwert beim Deaktivieren (nur bei Ausfallverhalten „Default-Sollwert“, bereiter
 * Instanz und schreibendem Treiber; unter der Sperre).
 *
 *   php .tools/test-ref-applychanges.php            # vergleicht mit .tools/ref/applychanges.json
 *   UPDATE_REF=1 php .tools/test-ref-applychanges.php
 *
 * Die IPS-Funktionen sind Nachbauten aus test-virtual.php.
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
MHUB_Text::set(function (string $t): string { return i18n_tr($t); });   // deutsche Oberfläche wie die anderen Prüfstände

// Sperre (SUITE.md Stolperstein 31) als Nachbau, der die Aufrufe mitzählt; es gibt nie einen anderen Halter
$GLOBALS['SEM_LOG'] = [];
function IPS_SemaphoreEnter($name, $ms) { $GLOBALS['SEM_LOG'][] = "enter $name $ms"; return true; }
function IPS_SemaphoreLeave($name)      { $GLOBALS['SEM_LOG'][] = "leave $name"; return true; }

/** Zeichnet Schreibzugriffe des Rückfallwerts auf; Lesen liefert nichts. */
class RefWriteMb
{
    public array $writes = [];
    public bool $wordSwap = false;
    public function setWordSwap(bool $s) { $this->wordSwap = $s; }
    public function packFloat32(float $v): array { $u = unpack('n2', pack('G', $v)); return $this->wordSwap ? [$u[2], $u[1]] : [$u[1], $u[2]]; }
    public function writeHolding($reg, array $regs): bool { $this->writes[] = [$reg, $regs, $this->wordSwap]; return true; }
    public function readHolding($a, $n) { return null; }
    public function readInput($a, $n) { return null; }
    public function close(): void {}
    public $lastError = '';
    public function failureText(): string { return ''; }
}

/** MeterHub-Instanz mit nachgebautem Transport und aufgezeichneten Timern/Status. */
class RefApplyHub extends MeterHub
{
    public array $timers = [];
    public int $transports = 0;
    public ?RefWriteMb $mb = null;
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; }
    protected function RegisterTimer($n, $i, $s) { }
    public function SetBuffer($n, $v) { $GLOBALS['BUF'][$this->InstanceID][$n] = $v; }
    public function GetBuffer($n) { return (string)($GLOBALS['BUF'][$this->InstanceID][$n] ?? ''); }
    protected function GetTransport() { $this->transports++; return $this->mb; }
}

obj(50, 1, 'Archive Control', 0);
$GLOBALS['INSTMOD'][50] = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
$nextIid = 5000;
function newHub(array $props, array $attrs = []): RefApplyHub
{
    global $nextIid;
    $iid = $nextIid; $nextIid += 1000;
    obj($iid, 1, 'Prüf-Zähler', 10);
    $GLOBALS['INSTMOD'][$iid] = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    $GLOBALS['PROP'][$iid] = $props;
    foreach ($attrs as $k => $v) { $GLOBALS['ATTR'][$iid][$k] = $v; }
    $h = new RefApplyHub($iid);
    $h->Create();
    $h->mb = new RefWriteMb();
    $GLOBALS['MODOBJ'][$iid] = $h;
    return $h;
}
function variables(RefApplyHub $h): array
{
    $out = [];
    $walk = function ($parent) use (&$walk, &$out) {
        foreach (IPS_GetChildrenIDs($parent) as $c) {
            $o = IPS_GetObject($c);
            if ($o['ObjectType'] === 2) {
                $v = $GLOBALS['VAR'][$c] ?? [];
                $out[$o['ObjectIdent']] = [$o['ObjectName'], $v['VariableType'] ?? null, $v['VariablePresentation']['PROFILE'] ?? ($v['VariableProfile'] ?? ''), $c];
            } else { $walk($c); }
        }
    };
    $GLOBALS['REF_IID'] = $h->InstanceID;
    $walk($h->InstanceID);
    // Objekt-IDs des Nachbaus hängen von der Reihenfolge der Prüfungen ab: als Rang der Anlage-Reihenfolge ablegen
    $ids = array_column($out, 3); sort($ids);
    foreach ($out as $k => $v) { $out[$k][3] = 'angelegt_als_' . (array_search($v[3], $ids) + 1); }
    ksort($out);
    return $out;
}
function applyAndRecord(RefApplyHub $h, bool $withVars = false): array
{
    $GLOBALS['SEM_LOG'] = [];
    $GLOBALS['LOGMSG'] = [];
    $warn = [];
    set_error_handler(function ($no, $str) use (&$warn) { $warn[] = $str; return true; });
    $h->ApplyChanges();
    restore_error_handler();
    $r = ['status' => $GLOBALS['STATUS'][$h->InstanceID] ?? null, 'timers' => $h->timers, 'transporte' => $h->transports,
          'schreibzugriffe' => array_map(fn($w) => ['reg' => $w[0], 'werte' => $w[1], 'wortreihenfolge_getauscht' => $w[2]], $h->mb->writes),
          'sperre' => $GLOBALS['SEM_LOG'] ? implode(' | ', array_map(fn($x) => preg_replace('/_\d+/', '_ID', $x), $GLOBALS['SEM_LOG'])) : '',
          'protokoll' => $GLOBALS['LOGMSG'], 'warnungen' => $warn];
    if ($withVars) { $r['variablen'] = variables($h); }
    return $r;
}

$result = ['_hinweis' => 'Referenz des heutigen Verhaltens von MeterHub::ApplyChanges(); Variablenlisten je Zaehlertyp bei Standardeinstellungen (alle optionalen Gruppen aus).'];

// 1) Variablensatz je Zählertyp (Modbus-Zähler mit Host; Cloud mit Token; HTTP mit Host; MQTT mit Brücke)
$drivers = (new ReflectionClassConstant('MeterHub', 'DRIVERS'))->getValue();
$types = [];
foreach (array_keys($drivers) as $meter) {
    $props = ['Meter' => $meter, 'Active' => true, 'Host' => '10.0.0.5', 'IntervalFast' => 10, 'IntervalSlow' => 60];
    $attrs = [];
    if ($meter === 'inexogy') { $props['InexogyMeterID'] = 'abc'; $attrs['InexogyToken'] = 'tok'; }
    if ($meter === 'shelly_mqtt') { $props['MqttBridgeID'] = 77; $props['MqttTopic'] = 'shellies/x'; }
    $h = newHub($props, $attrs);
    $types[$meter] = applyAndRecord($h, true);
}
$result['zaehlertypen'] = $types;

// 2) Zustände (Siemens PAC2200 als Standardzähler)
$base = ['Meter' => 'siemens_pac2200', 'Active' => true, 'Host' => '10.0.0.5', 'IntervalFast' => 10, 'IntervalSlow' => 60];
$states = [
    'aktiv_mit_host'                  => $base,
    'deaktiviert'                     => ['Active' => false] + $base,
    'aktiv_ohne_host'                 => ['Host' => ''] + $base,
    'gateway_ohne_bruecke'            => ['ConnectionMode' => 'gateway', 'BridgeInstanceID' => 0] + $base,
    'gateway_mit_bruecke'             => ['ConnectionMode' => 'gateway', 'BridgeInstanceID' => 88] + $base,
    'http_ohne_host'                  => ['Meter' => 'tasmota', 'Host' => ''] + $base,
    'http_mit_host'                   => ['Meter' => 'tasmota'] + $base,
    'mqtt_ohne_bruecke'               => ['Meter' => 'shelly_mqtt', 'MqttBridgeID' => 0, 'MqttTopic' => 'shellies/x'] + $base,
    'mqtt_ohne_topic'                 => ['Meter' => 'shelly_mqtt', 'MqttBridgeID' => 77, 'MqttTopic' => '  '] + $base,
    'mqtt_vollstaendig'               => ['Meter' => 'shelly_mqtt', 'MqttBridgeID' => 77, 'MqttTopic' => 'shellies/x'] + $base,
    'intervalle_5_und_300'            => ['IntervalFast' => 5, 'IntervalSlow' => 300] + $base,
];
$apply = [];
foreach ($states as $name => $props) { $apply[$name] = applyAndRecord(newHub($props)); }
foreach (['cloud_ohne_token' => [[], ['InexogyMeterID' => 'abc']], 'cloud_ohne_zaehler' => [['InexogyToken' => 'tok'], []], 'cloud_vollstaendig' => [['InexogyToken' => 'tok'], ['InexogyMeterID' => 'abc']]] as $name => [$attrs, $extra]) {
    $apply[$name] = applyAndRecord(newHub(['Meter' => 'inexogy'] + $extra + $base, $attrs));
}
$result['zustaende'] = $apply;

// 3) Rückfallwert beim Deaktivieren
$fs = [];
foreach (['bluelog_rpc', 'bluelog_powercontrol', 'siemens_pac2200'] as $meter) {
    foreach (['default' => 'default', 'hold' => 'hold'] as $mode => $m) {
        foreach (['deaktiviert' => false, 'aktiv' => true] as $actName => $active) {
            $props = ['Meter' => $meter, 'FailsafeMode' => $m, 'FailsafeDefaultPercent' => 40.0, 'Active' => $active] + $base;
            $fs["{$meter}_{$mode}_$actName"] = applyAndRecord(newHub($props));
        }
        $fs["{$meter}_{$mode}_deaktiviert_ohne_host"] = applyAndRecord(newHub(['Meter' => $meter, 'FailsafeMode' => $m, 'FailsafeDefaultPercent' => 40.0, 'Active' => false, 'Host' => ''] + $base));
    }
}
$fs['bluelog_rpc_default_100_Prozent'] = applyAndRecord(newHub(['Meter' => 'bluelog_rpc', 'FailsafeMode' => 'default', 'FailsafeDefaultPercent' => 100.0, 'Active' => false] + $base));
$result['rueckfallwert'] = $fs;

// 4) PowerInvert über ApplyChanges: erster Lauf übernimmt nur, Umschalten tauscht Energie-Idents (SyncInvertLayout)
$h = newHub(['PowerInvert' => false] + $base);
$inv = ['aus_1' => applyAndRecord($h, true)];
$GLOBALS['PROP'][$h->InstanceID]['PowerInvert'] = true;
$inv['an'] = applyAndRecord($h, true);
$GLOBALS['PROP'][$h->InstanceID]['PowerInvert'] = false;
$inv['aus_2'] = applyAndRecord($h, true);
$result['powerinvert'] = $inv;

$refFile = __DIR__ . '/ref/applychanges.json';
$ref = RefFile::load($refFile);
if (getenv('UPDATE_REF') || $ref === null) {
    RefFile::save($refFile, $result);
    echo "Referenz geschrieben: $refFile\n";
    $ref = $result;
}
$cmp = function (string $label, array $obs, array $exp) {
    $bad = [];
    foreach ($obs as $k => $v) { if (json_encode($v) !== json_encode($exp[$k] ?? null)) { $bad[] = $k; } }
    foreach (array_keys($exp) as $k) { if (!array_key_exists($k, $obs)) { $bad[] = "$k (in der Referenz, nicht mehr erzeugt)"; } }
    check("$label: " . count($obs) . ' Fälle stimmen mit der Referenz überein', $bad === [], implode(', ', array_slice($bad, 0, 5)));
};
echo "ApplyChanges: Referenzvergleich\n";
$cmp('Variablensatz und Status je Zählertyp', $result['zaehlertypen'], $ref['zaehlertypen']);
$cmp('Zustände (aktiv, deaktiviert, ohne Host, Gateway, Cloud, HTTP, MQTT, Intervalle)', $result['zustaende'], $ref['zustaende']);
$cmp('Rückfallwert beim Deaktivieren', $result['rueckfallwert'], $ref['rueckfallwert']);
$cmp('PowerInvert über ApplyChanges', $result['powerinvert'], $ref['powerinvert']);

// Eigenschaften, die die Referenz nicht trägt, aber die ein Leser sofort erwartet (Gegenprobe gegen eine versehentlich leere Referenz)
$z = $result['zustaende']; $r = $result['rueckfallwert'];
check('aktiv und bereit: Status 102, beide Timer laufen', $z['aktiv_mit_host']['status'] === 102 && $z['aktiv_mit_host']['timers'] === ['FastTimer' => 10000, 'SlowTimer' => 60000]);
check('deaktiviert: Status 104, beide Timer aus', $z['deaktiviert']['status'] === 104 && $z['deaktiviert']['timers'] === ['FastTimer' => 0, 'SlowTimer' => 0]);
check('Rückfallwert: blue\'Log RPC, deaktiviert, Default: genau ein Schreibzugriff (Register 5000) unter der Sperre', count($r['bluelog_rpc_default_deaktiviert']['schreibzugriffe']) === 1 && $r['bluelog_rpc_default_deaktiviert']['schreibzugriffe'][0]['reg'] === 5000 && str_contains($r['bluelog_rpc_default_deaktiviert']['sperre'], 'enter') && str_contains($r['bluelog_rpc_default_deaktiviert']['sperre'], 'leave'));
check('Rückfallwert: im aktiven Betrieb, bei „halten“, ohne Host und bei nicht schreibendem Treiber wird nichts geschrieben',
    $r['bluelog_rpc_default_aktiv']['schreibzugriffe'] === [] && $r['bluelog_rpc_hold_deaktiviert']['schreibzugriffe'] === [] && $r['bluelog_rpc_default_deaktiviert_ohne_host']['schreibzugriffe'] === [] && $r['siemens_pac2200_default_deaktiviert']['schreibzugriffe'] === []);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
