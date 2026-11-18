<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Referenzlauf der Archiv-Reparatur (18.11.2026): Energie-Archiv prüfen/reparieren, Richtung im Archiv prüfen/korrigieren
 * und Live-Zwischenwerte bereinigen, jeweils als Probelauf und mit Anwendung. Vorher lief keine dieser Funktionen in einem
 * Prüfstand. Festgehalten wird das HEUTIGE Verhalten: der Ergebnistext (Zeitangaben relativ zum Prüfbeginn) und jede
 * Änderung am simulierten Archiv (geänderte, hinzugefügte, gelöschte Punkte), dazu die Sicherungsdatei.
 *
 *   php .tools/test-ref-archiv.php            # vergleicht mit .tools/ref/archiv.json
 *   UPDATE_REF=1 php .tools/test-ref-archiv.php
 *
 * Das Archiv ist ein Speicher-Nachbau (AC_GetLoggedValues absteigend, AC_AddLoggedValues, AC_DeleteVariableData); die
 * Zählerreihen sind MUSTER (gleichmäßiger Anstieg), keine Anlagendaten. Die IPS-Funktionen stammen aus test-virtual.php.
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

date_default_timezone_set('UTC');
require_once __DIR__ . '/ref/harness.php';
MHUB_Text::set(function (string $t): string { return i18n_tr($t); });

// ---- Archiv-Nachbau ---------------------------------------------------------------------------------------------
$GLOBALS['ARCH'] = [];     // vid => [ts => wert]
$GLOBALS['KERNELDIR'] = sys_get_temp_dir() . '/mhub_ref_' . getmypid() . '/';
@mkdir($GLOBALS['KERNELDIR']);
function IPS_GetKernelDir() { return $GLOBALS['KERNELDIR']; }
function AC_GetLoggedValues($ac, $vid, $from, $to, $limit)
{
    $rows = [];
    foreach ($GLOBALS['ARCH'][$vid] ?? [] as $ts => $v) { if ($ts >= $from && $ts <= $to) { $rows[] = ['TimeStamp' => $ts, 'Value' => $v]; } }
    usort($rows, fn($x, $y) => $y['TimeStamp'] <=> $x['TimeStamp']);
    return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
}
// Das Verhalten von AC_AddLoggedValues() bei einem schon belegten Zeitstempel ist nicht dokumentiert (Überschreiben oder Doppeln):
// der Nachbau nimmt das ungünstige an (alter Wert bleibt, der neue zählt als Doppel) — nur Löschen vor dem Eintragen führt zum Ziel.
function AC_AddLoggedValues($ac, $vid, $rows)
{
    foreach ($rows as $r) {
        $ts = (int)$r['TimeStamp'];
        if (array_key_exists($ts, $GLOBALS['ARCH'][$vid] ?? [])) { $GLOBALS['DUPES'][$vid][] = $ts; continue; }
        $GLOBALS['ARCH'][$vid][$ts] = (float)$r['Value'];
    }
    return true;
}
function AC_DeleteVariableData($ac, $vid, $from, $to) { foreach (array_keys($GLOBALS['ARCH'][$vid] ?? []) as $ts) { if ($ts >= $from && $ts <= $to) { unset($GLOBALS['ARCH'][$vid][$ts]); } } return true; }
function AC_ReAggregateVariable($ac, $vid) { $GLOBALS['REAGG'][] = $vid; return true; }
obj(50, 1, 'Archive Control', 0);
$GLOBALS['INSTMOD'][50] = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

class RefArchHub extends MeterHub
{
    public function SetBuffer($n, $v) { $GLOBALS['BUF'][$this->InstanceID][$n] = $v; }
    public function GetBuffer($n) { return (string)($GLOBALS['BUF'][$this->InstanceID][$n] ?? ''); }
    protected function SetStatus($s) { }
}

$nextIid = 7000;
/** Instanz mit Bezug-, Einspeise- und Leistungsvariable, alle archiviert. Rückgabe [Hub, I, E, P]. */
function newArch(array $props, bool $withPower = true): array
{
    global $nextIid;
    $iid = $nextIid; $nextIid += 100;
    obj($iid, 1, 'Archiv-Zähler', 10);
    $GLOBALS['INSTMOD'][$iid] = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    $GLOBALS['PROP'][$iid] = $props + ['Meter' => 'siemens_pac2200', 'Active' => true];
    $mk = function ($id, $name, $ident) use ($iid) {
        obj($id, 2, $name, $iid, $ident);
        $GLOBALS['VAR'][$id] = ['VariableType' => 2];
        $GLOBALS['VAL'][$id] = 0.0;
        $GLOBALS['ARCHIVED'][$id] = true;
        $GLOBALS['ARCH'][$id] = [];
        return $id;
    };
    $i = $mk($iid + 1, 'Bezug', 'energy_import');
    $e = $mk($iid + 2, 'Abgabe', 'energy_export');
    $p = $withPower ? $mk($iid + 3, 'Leistung', 'power_total') : 0;
    $h = new RefArchHub($iid);
    $h->Create();
    $GLOBALS['MODOBJ'][$iid] = $h;
    return [$h, $i, $e, $p];
}
/** Muster-Reihe: Bezug +0,25 kWh je Viertelstunde ab 1000, Abgabe +0,05 ab 200, Leistung 1000 W. $k = Viertelstunden seit Reihenbeginn. */
function fillSeries(array $vids, int $A, int $quarterHours = 6 * 96): void
{
    [$i, $e, $p] = $vids;
    for ($k = 0; $k <= $quarterHours; $k++) {
        $ts = $A - ($quarterHours - $k) * 900;
        $GLOBALS['ARCH'][$i][$ts] = 1000.0 + 0.25 * $k;
        $GLOBALS['ARCH'][$e][$ts] = 200.0 + 0.05 * $k;
        if ($p) { $GLOBALS['ARCH'][$p][$ts] = 1000.0; }
    }
}
function at(int $A, int $quartersBack): int { return $A - $quartersBack * 900; }

function snapshot(): array { return $GLOBALS['ARCH']; }
/** Unterschied zweier Archivzustände je Variable: geänderte/hinzugefügte/gelöschte Punkte, Zeit relativ zum Anker in Minuten. */
function archiveDiff(array $before, array $after, int $A): array
{
    $out = [];
    foreach ($after as $vid => $series) {
        $b = $before[$vid] ?? [];
        $chg = []; $add = []; $del = [];
        foreach ($series as $ts => $v) {
            if (!array_key_exists($ts, $b)) { $add[] = [intdiv($ts - $A, 60), sprintf('%.6g', $v)]; }
            elseif (abs($b[$ts] - $v) > 1e-9) { $chg[] = [intdiv($ts - $A, 60), sprintf('%.6g', $b[$ts]), sprintf('%.6g', $v)]; }
        }
        foreach ($b as $ts => $v) { if (!array_key_exists($ts, $series)) { $del[] = [intdiv($ts - $A, 60), sprintf('%.6g', $v)]; } }
        if ($chg || $add || $del) { $out[$vid - $GLOBALS['BASE_VID']] = ['geaendert' => $chg, 'hinzugefuegt' => $add, 'geloescht' => $del]; }
    }
    ksort($out);
    return $out;
}
/** Ergebnistext: Zeitangaben relativ zum Anker, Dateinamen ohne Zeitstempel. */
function normText(string $t, int $A): string
{
    $t = preg_replace_callback('/(\d{2})\.(\d{2})\.(\d{4}) (\d{2}):(\d{2})/', function ($m) use ($A) {
        $ts = gmmktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[1], (int)$m[3]);
        return 'T' . (($ts - $A) >= 0 ? '+' : '-') . abs(intdiv($ts - $A, 60)) . 'min';
    }, $t);
    $t = preg_replace_callback('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', function ($m) use ($A) {
        $ts = gmmktime(0, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
        return 'Tag' . (intdiv($ts - intdiv($A, 86400) * 86400, 86400));
    }, $t);
    $t = preg_replace('/_\d{8}_\d{6}\.json/', '_STAMP.json', $t);
    return preg_replace_callback('/#(\d+)/', fn($m) => '#' . ($m[1] - $GLOBALS['BASE_VID']), $t);
}
function run(string $label, callable $setup, callable $call, array &$result): void
{
    $GLOBALS['ARCH'] = []; $GLOBALS['REAGG'] = []; $GLOBALS['LOGMSG'] = []; $GLOBALS['DUPES'] = [];
    foreach (glob($GLOBALS['KERNELDIR'] . '*') as $f) { @unlink($f); }
    $A = intdiv(time() - 600, 900) * 900;
    $ctx = $setup($A);
    $GLOBALS['BASE_VID'] = $ctx['base'];
    $before = snapshot();
    $warn = [];
    set_error_handler(function ($no, $str) use (&$warn) { $warn[] = $str; return true; });
    $text = $call($ctx, $A);
    restore_error_handler();
    $files = array_map('basename', glob($GLOBALS['KERNELDIR'] . 'meterhub_sicherung_*'));
    sort($files);
    $normd = normText($text, $A);
    if (str_starts_with($label, 'A13')) {
        // Der Beginn des Prüfzeitraums (jetzt − 6 Tage) fällt je nach Minute des Laufs auf einen anderen Rasterpunkt: Anzahl und Anfang der
        // Nullwerte im Zeitraum schwanken um eine Viertelstunde. Gleichbleibend ist nur die Aussage („ohne gültigen Stand davor“).
        $normd = preg_replace('/(?<!\d)[1-9]\d* Nullwert/', 'N Nullwert', $normd);
        $normd = preg_replace('/T-\d+min – T-\d+min \(\d+ Punkte\)/u', 'T-… – T-… (N Punkte)', $normd);
    }
    $result[$label] = ['text' => explode("\n", $normd), 'archiv' => archiveDiff($before, snapshot(), $A),
        'neu_aggregiert' => count($GLOBALS['REAGG']), 'doppelte_zeitstempel' => array_sum(array_map('count', $GLOBALS['DUPES'])), 'sicherungen' => array_map(fn($f) => normText($f, $A), $files),
        'protokoll' => array_map(fn($m) => normText($m, $A), $GLOBALS['LOGMSG']), 'warnungen' => $warn];
}

$result = ['_hinweis' => 'Muster-Reihen (Bezug +0,25 kWh, Abgabe +0,05 kWh je Viertelstunde, Leistung 1000 W) in einem Speicher-Nachbau des Archivs; Zeitangaben relativ zum Prüfbeginn (T-xxmin). Referenz des heutigen Verhaltens.'];

// ============================================================================================================
// A) Energie-Archiv prüfen / reparieren
// ============================================================================================================
$basic = function (array $mod = [], array $props = [], bool $power = true) {
    return function (int $A) use ($mod, $props, $power) {
        [$h, $i, $e, $p] = newArch($props, $power);
        fillSeries([$i, $e, $p], $A);
        foreach ($mod as $fn) { $fn($i, $e, $p, $A); }
        return ['hub' => $h, 'i' => $i, 'e' => $e, 'p' => $p, 'base' => $h->InstanceID];
    };
};
$zero = fn($vidKey, $fromQ, $n) => function ($i, $e, $p, $A) use ($vidKey, $fromQ, $n) { $v = ['i' => $i, 'e' => $e][$vidKey]; for ($q = $fromQ; $q > $fromQ - $n; $q--) { $GLOBALS['ARCH'][$v][at($A, $q)] = 0.0; } };
$offset = fn($vidKey, $fromQ, $n, $d) => function ($i, $e, $p, $A) use ($vidKey, $fromQ, $n, $d) { $v = ['i' => $i, 'e' => $e][$vidKey]; for ($q = $fromQ; $q > $fromQ - $n; $q--) { $GLOBALS['ARCH'][$v][at($A, $q)] += $d; } };
$energy = fn(bool $apply, int $days = 4, bool $back = true, ?callable $ref = null) => function ($c, $A) use ($apply, $days, $back, $ref) {
    $refId = $ref ? $ref($c, $A) : 0;
    return $apply ? $c['hub']->RepairEnergyArchive($refId, $days, $back) : $c['hub']->CheckEnergyArchive($refId, $days, $back);
};
run('A01_sauber_Probelauf', $basic(), $energy(false), $result);
run('A02_Nullwerte_Luecke_Probelauf', $basic([$zero('i', 150, 3)]), $energy(false), $result);
run('A03_Nullwerte_Luecke_repariert', $basic([$zero('i', 150, 3)]), $energy(true), $result);
run('A04_Nullwerte_Luecke_Bezug_und_Abgabe_mit_Leistung', $basic([$zero('i', 150, 3), $zero('e', 150, 3)]), $energy(true), $result);
run('A05_Nullwerte_Luecke_Leistung_PowerInvert', $basic([$zero('i', 150, 3)], ['PowerInvert' => true]), $energy(true), $result);
run('A06_Nullwerte_Luecke_Leistung_Wh', $basic([$zero('i', 150, 3)], ['EnergyUnitWh' => true]), $energy(true), $result);
run('A07_Nullwerte_Luecke_ohne_Leistungsarchiv', $basic([$zero('i', 150, 3)], [], false), $energy(true), $result);
run('A08_Nullwerte_mit_Referenzzaehler', function (int $A) use ($basic) {
        $c = $basic([function ($i, $e, $p, $A) { for ($q = 150; $q > 147; $q--) { $GLOBALS['ARCH'][$i][at($A, $q)] = 0.0; } }])($A);
        [$rh, $ri, $re, $rp] = newArch([]);
        fillSeries([$ri, $re, $rp], $A);
        // Referenz mit ungleichmäßigem Verlauf (doppelter Anstieg im Lückenfenster)
        for ($q = 150; $q > 147; $q--) { $GLOBALS['ARCH'][$ri][at($A, $q)] += ($q === 149 ? 2.0 : 0.0); }
        $c['ref'] = $rh->InstanceID;
        return $c;
    }, function ($c, $A) { return $c['hub']->RepairEnergyArchive($c['ref'], 4, true); }, $result);
run('A09_einzelner_Rueckschritt_mit_Rueckwaertsreparatur', $basic([$offset('i', 140, 1, -5.0)]), $energy(true, 4, true), $result);
run('A10_einzelner_Rueckschritt_ohne_Rueckwaertsreparatur', $basic([$offset('i', 140, 1, -5.0)]), $energy(false, 4, false), $result);
run('A11_lange_Rueckschrittreihe_nicht_repariert', $basic([$offset('i', 140, 5, -5.0)]), $energy(true, 4, true), $result);
run('A12_Luecke_ohne_gueltigen_Wert_danach', $basic([$zero('i', 2, 3)]), $energy(true), $result);
run('A12b_Luecke_kurz_vor_dem_Ende', $basic([$zero('i', 2, 2)]), $energy(true), $result);
run('A13_Luecke_ohne_gueltigen_Wert_davor', $basic([function ($i, $e, $p, $A) { foreach (array_keys($GLOBALS['ARCH'][$i]) as $ts) { if ($ts < at($A, 6 * 96 - 20)) { $GLOBALS['ARCH'][$i][$ts] = 0.0; } } }]), $energy(false, 6), $result);
run('A14_Tage_werden_auf_45_begrenzt', $basic(), $energy(false, 99), $result);
run('A15_kein_archivierter_Zaehler', function (int $A) { [$h] = newArch([]); foreach ([1, 2, 3] as $d) { unset($GLOBALS['ARCHIVED'][$h->InstanceID + $d]); } return ['hub' => $h, 'base' => $h->InstanceID]; }, $energy(false), $result);
run('A16_kein_Archivmodul', function (int $A) { [$h] = newArch([]); $GLOBALS['SAVE_AC'] = $GLOBALS['INSTMOD'][50]; unset($GLOBALS['INSTMOD'][50]); return ['hub' => $h, 'base' => $h->InstanceID]; }, function ($c, $A) { $t = $c['hub']->CheckEnergyArchive(0, 4, true); $GLOBALS['INSTMOD'][50] = $GLOBALS['SAVE_AC']; return $t; }, $result);

// ============================================================================================================
// B) Richtung im Archiv prüfen / korrigieren
// ============================================================================================================
$cross = fn(int $fromQ, int $n) => function ($i, $e, $p, $A) use ($fromQ, $n) { for ($q = $fromQ; $q > $fromQ - $n; $q--) { $t = at($A, $q); $x = $GLOBALS['ARCH'][$i][$t]; $GLOBALS['ARCH'][$i][$t] = $GLOBALS['ARCH'][$e][$t]; $GLOBALS['ARCH'][$e][$t] = $x; } };
$flipPower = fn(int $fromQ, int $n) => function ($i, $e, $p, $A) use ($fromQ, $n) { for ($q = $fromQ; $q > $fromQ - $n; $q--) { $GLOBALS['ARCH'][$p][at($A, $q)] = -1000.0; } };
$dir = fn(bool $apply, int $days = 4) => fn($c, $A) => $apply ? $c['hub']->RepairDirectionArchive($days) : $c['hub']->CheckDirectionArchive($days);
run('B01_durchgehend_Probelauf', $basic(), $dir(false), $result);
run('B02_vertauschter_Abschnitt_Probelauf', $basic([$cross(200, 50)]), $dir(false), $result);
run('B03_vertauschter_Abschnitt_korrigiert', $basic([$cross(200, 50)]), $dir(true), $result);
run('B04_vertauschter_Abschnitt_bis_heute_Probelauf', $basic([$cross(60, 61)]), $dir(false), $result);
run('B05_vertauschter_Abschnitt_bis_heute_korrigiert', $basic([$cross(60, 61)]), $dir(true), $result);
run('B06_nur_eine_Variable_wechselt_Probelauf', $basic([function ($i, $e, $p, $A) { for ($q = 200; $q > 150; $q--) { $GLOBALS['ARCH'][$i][at($A, $q)] = $GLOBALS['ARCH'][$e][at($A, $q)]; } }]), $dir(false), $result);
run('B07_Leistung_entgegengesetzt_Probelauf', $basic([$flipPower(250, 40)]), $dir(false), $result);
run('B08_Leistung_entgegengesetzt_korrigiert', $basic([$flipPower(250, 40)]), $dir(true), $result);
run('B09_Leistung_entgegengesetzt_bis_heute_Probelauf', $basic([$flipPower(30, 30)]), $dir(false), $result);
run('B10_Cloudzaehler_kein_Leistungsabgleich', $basic([$flipPower(250, 40)], ['Meter' => 'inexogy']), $dir(false), $result);
run('B11_ohne_Leistungsarchiv', $basic([], [], false), $dir(false), $result);
run('B13_vertauschter_Abschnitt_mit_Nullwert_darin', $basic([$cross(200, 50), function ($i, $e, $p, $A) { $GLOBALS['ARCH'][$i][at($A, 190)] = 0.0; }]), $dir(false), $result);
run('B12_Energie_und_Leistung_zugleich_Probelauf', $basic([$cross(200, 50), $flipPower(300, 20)]), $dir(false), $result);

// ============================================================================================================
// C) Live-Zwischenwerte bereinigen (Inexogy)
// ============================================================================================================
$live = function (int $n, float $d) { return function ($i, $e, $p, $A) use ($n, $d) {
    for ($q = 100; $q > 100 - $n; $q--) { $GLOBALS['ARCH'][$i][at($A, $q) + 420] = 1000.0 + 0.25 * (6 * 96 - $q) - $d; }   // Live-Wert 7 Minuten nach dem offiziellen Punkt, etwas niedriger
}; };
$clean = fn(bool $apply, int $days = 4) => fn($c, $A) => $apply ? $c['hub']->CleanInexogyArchive($days) : $c['hub']->CheckInexogyArchiveCleanup($days);
run('C01_nur_offizielle_Werte_Probelauf', $basic([], ['Meter' => 'inexogy']), $clean(false), $result);
run('C02_Live_Zwischenwerte_Probelauf', $basic([$live(5, 0.3)], ['Meter' => 'inexogy']), $clean(false), $result);
run('C03_Live_Zwischenwerte_bereinigt', $basic([$live(5, 0.3)], ['Meter' => 'inexogy']), $clean(true), $result);
run('C05_Tage_werden_auf_45_begrenzt', $basic([], ['Meter' => 'inexogy']), $clean(false, 99), $result);
run('C04_kein_Inexogy_Zaehler', $basic([$live(5, 0.3)]), $clean(false), $result);

// ---- Vergleich ------------------------------------------------------------------------------------------------
foreach (glob($GLOBALS['KERNELDIR'] . '*') as $f) { @unlink($f); }
@rmdir($GLOBALS['KERNELDIR']);
$refFile = __DIR__ . '/ref/archiv.json';
$ref = RefFile::load($refFile);
if (getenv('UPDATE_REF') || $ref === null) {
    RefFile::save($refFile, $result);
    echo "Referenz geschrieben: $refFile (" . (count($result) - 1) . " Szenarien)\n";
    $ref = $result;
}
$groups = ['A' => 'Energie-Archiv prüfen/reparieren', 'B' => 'Richtung im Archiv', 'C' => 'Live-Zwischenwerte bereinigen'];
foreach ($groups as $g => $name) {
    $bad = [];
    $n = 0;
    foreach ($result as $k => $v) {
        if ($k[0] !== $g || $k[1] === 'i') { continue; }
        $n++;
        if (json_encode($v) !== json_encode($ref[$k] ?? null)) { $bad[] = $k; }
    }
    foreach (array_keys($ref) as $k) { if ($k[0] === $g && $k !== '_hinweis' && !isset($result[$k])) { $bad[] = "$k (in der Referenz, nicht mehr erzeugt)"; } }
    check("$name: $n Szenarien stimmen mit der Referenz überein", $bad === [], implode(', ', array_slice($bad, 0, 5)));
}
// Gegenprobe gegen eine leere Referenz: die wichtigsten Befunde stehen im Ergebnis
check('Archiv-Nachbau: der Normalfall erzeugt nie doppelte Zeitstempel', array_sum(array_column($result, 'doppelte_zeitstempel')) === 0);
check('A02: der Probelauf nennt die Nullwerte und ändert nichts', str_contains(implode("\n", $result['A02_Nullwerte_Luecke_Probelauf']['text']), '3 Nullwert') && $result['A02_Nullwerte_Luecke_Probelauf']['archiv'] === []);
check('A03: die Reparatur ersetzt die drei Nullwerte des Bezugs durch Zwischenwerte', count($result['A03_Nullwerte_Luecke_repariert']['archiv'][1]['geaendert'] ?? []) === 3);
check('B03: die Korrektur schreibt Werte zwischen den zwei Variablen um und sichert vorher', count($result['B03_vertauschter_Abschnitt_korrigiert']['sicherungen']) >= 2 && count($result['B03_vertauschter_Abschnitt_korrigiert']['archiv']) === 2);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
