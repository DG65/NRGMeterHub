<?php
/**
 * Prüfstand für die MigrationsHub-Kopplung in MeterHubDiscovery
 * (LegacyCandidateFor()/PrepareMigration(), Verbund-Konvention 29.07.2026).
 * Bildet die nötigen IPS-Funktionen nach und simuliert MIGHUB_* wahlweise
 * vorhanden/fehlend — ein Syntaxcheck sieht weder das function_exists()-
 * Verhalten noch die Feldnamen-Kette zwischen Formular und Backend.
 */

const VARIABLETYPE_FLOAT = 2;

$GLOBALS['OBJ'] = [];
$GLOBALS['PROP'] = [];
$GLOBALS['ATTR'] = [];
$GLOBALS['INSTMOD'] = [];
$GLOBALS['NEXTID'] = 9000;
$GLOBALS['FORMFIELDS'] = [];
$GLOBALS['APPLIED'] = [];   // iid => Anzahl ApplyChanges-Aufrufe (Aktivitätsnachweis)

function obj($id, $type, $name, $parent, $ident = '') {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => $type, 'ObjectIdent' => $ident, 'ObjectName' => $name, 'ParentID' => $parent];
    return $id;
}
function IPS_GetObject($id)   { return $GLOBALS['OBJ'][$id] ?? null; }
function IPS_GetName($id)     { return $GLOBALS['OBJ'][$id]['ObjectName'] ?? ('#' . $id); }
function IPS_ObjectExists($id){ return isset($GLOBALS['OBJ'][$id]); }
function IPS_GetInstanceListByModuleID($guid) {
    $out = [];
    foreach ($GLOBALS['INSTMOD'] as $iid => $g) { if ($g === $guid) { $out[] = $iid; } }
    return $out;
}
function IPS_CreateInstance($guid) {
    $id = $GLOBALS['NEXTID']++;
    obj($id, 1, 'neue Instanz', 0);
    $GLOBALS['INSTMOD'][$id] = $guid;
    return $id;
}
function IPS_SetProperty($iid, $n, $v) { $GLOBALS['PROP'][$iid][$n] = $v; }
function IPS_GetProperty($iid, $n)     { return $GLOBALS['PROP'][$iid][$n] ?? ''; }
function IPS_ApplyChanges($iid) { $GLOBALS['APPLIED'][$iid] = ($GLOBALS['APPLIED'][$iid] ?? 0) + 1; }

class IPSModule
{
    public $InstanceID;
    protected $defs = [];
    public function __construct($id) { $this->InstanceID = $id; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v)  { $this->defs[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->defs[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeInteger($n)  { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeBoolean($n)  { return (bool)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    public function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFIELDS'][$f][$p] = $v; }
    protected function ReloadForm() {}
    protected function RegisterVariableBoolean($n, $c, $p, $pos) {}
    protected function SetStatus($s) {}
    public function GetValue($n) { return false; }
    public function SetValue($n, $v) {}
    protected function RegisterTimer($n, $i, $s) {}
    protected function SendDebug($sender, $msg, $format) {}
}

require_once dirname(__DIR__) . '/MeterHubDiscovery/module.php';

const G_METER  = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
const G_MIGHUB = '{330717BB-E309-41A2-90A8-FDA3179ED948}';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

// ---------------------------------------------------------------------------
echo "\n1) Ohne MigrationsHub (function_exists false)\n";
$d = new MeterHubDiscovery(1);
$d->Create();
$ref = new ReflectionMethod($d, 'LegacyCandidateFor');
$legacy = $ref->invoke($d, '10.0.0.5', 1);
check('kein Treffer ohne MigrationsHub-Funktionen', $legacy === ['id' => 0, 'name' => '']);

$d->PrepareMigration();
check('Meldung: MigrationsHub nicht installiert', str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', 'nicht installiert'));

// ---------------------------------------------------------------------------
// Ab hier MIGHUB_* simulieren, als wäre MigrationsHub installiert. In eine
// Bedingung verpackt, sonst hebt PHP die Funktionsdefinition beim Parsen an
// den Dateianfang und Abschnitt 1 könnte sie nie als "fehlend" sehen.
$GLOBALS['MIGHUB_PREFILL_CALLS'] = [];
$GLOBALS['MIGHUB_FIND_CALLS'] = [];
if (!function_exists('MIGHUB_FindLegacyCandidates')) {
    // Spiegelt die ECHTE Kernel-Wrapper-Signatur: 5 Parameter, ALLE Pflicht —
    // PREFIX_-Wrapper honorieren PHP-Defaults nicht (SUITE.md-Stolperstein;
    // genau so brach der 4-Arg-Aufruf am 30.08.2026 live Dietmars Formular).
    function MIGHUB_FindLegacyCandidates($id, string $host, int $port, int $unitId, int $excludeInstanceID): array {
        $GLOBALS['MIGHUB_FIND_CALLS'][] = [$id, $host, $port, $unitId, $excludeInstanceID];
        if ($host === '10.0.0.5' && $unitId === 1 && $excludeInstanceID !== 555) {
            return [['instanceID' => 555, 'name' => 'Alter Zähler (Fremdmodul)']];
        }
        return [];
    }
    function MIGHUB_PrefillMigration($id, $oldInstanceID, $newInstanceID): void {
        $GLOBALS['MIGHUB_PREFILL_CALLS'][] = [$id, $oldInstanceID, $newInstanceID];
    }
}

echo "\n1b) Funktionen vorhanden, aber keine MigrationsHub-INSTANZ -> kein Treffer, kein Fehlaufruf\n";
// LegacyCandidateFor() braucht eine MigrationsHub-Instanz als Dispatch-Ziel
// (der Kernel-Wrapper dispatcht auf die übergebene Instanz-ID) und legt
// bewusst keine an — GetConfigurationForm() darf keine Instanzen erzeugen.
$legacy = $ref->invoke($d, '10.0.0.5', 1);
check('kein Treffer ohne MigrationsHub-Instanz', $legacy === ['id' => 0, 'name' => '']);
check('kein MIGHUB-Aufruf abgesetzt', count($GLOBALS['MIGHUB_FIND_CALLS']) === 0);

echo "\n2) Mit MigrationsHub — Alt-Instanz gefunden\n";
obj(555, 1, 'Alter Zähler (Fremdmodul)', 0);
$GLOBALS['INSTMOD'][555] = '{SOME-OTHER-GUID}';
// Vorhandene MigrationsHub-Instanz als Dispatch-Ziel.
$migPre = IPS_CreateInstance(G_MIGHUB);

$legacy = $ref->invoke($d, '10.0.0.5', 1);
check('Alt-Instanz #555 gefunden', $legacy['id'] === 555 && $legacy['name'] === 'Alter Zähler (Fremdmodul)');
check('Aufruf dispatcht auf die MigrationsHub-Instanz, NICHT die eigene',
    ($GLOBALS['MIGHUB_FIND_CALLS'][0][0] ?? 0) === $migPre, json_encode($GLOBALS['MIGHUB_FIND_CALLS'][0] ?? null));
check('excludeInstanceID (5. Argument) wird übergeben', array_key_exists(4, $GLOBALS['MIGHUB_FIND_CALLS'][0] ?? []));
$noMatch = $ref->invoke($d, '10.0.0.9', 1);
check('andere IP liefert nichts', $noMatch === ['id' => 0, 'name' => '']);
// Selbstausschluss: die eigene frisch angelegte Instanz darf nicht als
// Alt-Instanz zurückkommen.
$selfEx = $ref->invoke($d, '10.0.0.5', 1, 555);
check('excludeInstanceID schließt den Kandidaten aus', $selfEx === ['id' => 0, 'name' => '']);

echo "\n3) GetConfigurationForm: Active=false + legacy-Spalte bei Treffer\n";
$GLOBALS['ATTR'][1]['ResultsJSON'] = json_encode([
    ['meter' => 'Siemens PAC2200', 'label' => 'Siemens PAC2200', 'ip' => '10.0.0.5', 'unitId' => 1],
    ['meter' => 'Shelly Pro 3EM',  'label' => 'Shelly Pro 3EM',  'ip' => '10.0.0.9', 'unitId' => 1],
]);
$d2 = new MeterHubDiscovery(1);
$d2->Create();
$form = json_decode($d2->GetConfigurationForm(), true);
check('Formular ist gültiges JSON', is_array($form));
$list = null;
foreach ($form['elements'] ?? [] as $el) {
    foreach ($el['items'] ?? [] as $it) {
        if (($it['name'] ?? '') === 'DiscoveryList') { $list = $it; }
    }
}
check('Configurator-Liste gefunden', $list !== null);
$rowMatch  = $list['values'][0] ?? [];
$rowNoMatch = $list['values'][1] ?? [];
check('Treffer-Zeile: legacy-Text gefüllt', str_contains($rowMatch['legacy'] ?? '', '#555'), json_encode($rowMatch['legacy'] ?? null));
check('Treffer-Zeile: Active=false in create.configuration', ($rowMatch['create']['configuration']['Active'] ?? null) === false);
check('Nicht-Treffer-Zeile: legacy-Text leer', ($rowNoMatch['legacy'] ?? 'x') === '');
check('Nicht-Treffer-Zeile: kein Active-Override', !array_key_exists('Active', $rowNoMatch['create']['configuration'] ?? []));
$legacyCol = null;
foreach ($list['columns'] ?? [] as $c) { if ($c['name'] === 'legacy') { $legacyCol = $c; } }
check('legacy-Spalte sichtbar (MigrationsHub vorhanden)', $legacyCol !== null && $legacyCol['visible'] === true);

echo "\n4) PrepareMigration(): erstellte Instanz + Alt-Instanz -> verknüpft\n";
$target = IPS_CreateInstance(G_METER);
IPS_SetProperty($target, 'Host', '10.0.0.5');
IPS_SetProperty($target, 'UnitId', 1);
IPS_SetProperty($target, 'Active', true); // Nutzer hatte es doch aktiv gelassen

$d3 = new MeterHubDiscovery(1);
$d3->Create();
$d3->PrepareMigration();

check('Ziel-Instanz nachträglich deaktiviert', IPS_GetProperty($target, 'Active') === false);
check('ApplyChanges auf Ziel-Instanz aufgerufen', ($GLOBALS['APPLIED'][$target] ?? 0) === 1);
$migIDs = IPS_GetInstanceListByModuleID(G_MIGHUB);
check('genau eine MigrationsHub-Instanz (vorhandene wiederverwendet, keine zweite angelegt)', count($migIDs) === 1, 'count=' . count($migIDs));
check('PrefillMigration mit korrekten IDs aufgerufen',
    count($GLOBALS['MIGHUB_PREFILL_CALLS']) === 1
    && $GLOBALS['MIGHUB_PREFILL_CALLS'][0][1] === 555
    && $GLOBALS['MIGHUB_PREFILL_CALLS'][0][2] === $target);
$lastFind = end($GLOBALS['MIGHUB_FIND_CALLS']);
check('PrepareMigration übergibt die Zielinstanz als excludeInstanceID', ($lastFind[4] ?? -1) === $target, json_encode($lastFind));
check('OpenObjectButton zeigt auf MigrationsHub-Instanz',
    ($GLOBALS['FORMFIELDS']['BtnOpenMigration']['objectID'] ?? null) === $migIDs[0]
    && ($GLOBALS['FORMFIELDS']['BtnOpenMigration']['visible'] ?? null) === true);
check('Erfolgsmeldung nennt beide Instanzen',
    str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', '#555')
    && str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', '#' . $target));

echo "\n5) Zweiter Aufruf: vorhandene MigrationsHub-Instanz wird wiederverwendet, kein zweiter Treffer nötig\n";
$GLOBALS['MIGHUB_PREFILL_CALLS'] = [];
$d3->PrepareMigration();
check('MigrationsHub-Instanz nicht doppelt angelegt', count(IPS_GetInstanceListByModuleID(G_MIGHUB)) === 1);
check('PrefillMigration erneut aufgerufen (Zeile weiterhin ohne Bestätigung im Test)', count($GLOBALS['MIGHUB_PREFILL_CALLS']) === 1);

echo "\n6) Keine erstellte Zielinstanz vorhanden -> klare Meldung, kein Fehler\n";
// Eigene IP (nicht 10.0.0.5) — sonst kollidiert das mit der in Abschnitt 4
// bereits angelegten Ziel-Instanz, die denselben globalen Zustand teilt.
$d4 = new MeterHubDiscovery(2);
$d4->Create();
$GLOBALS['ATTR'][2]['ResultsJSON'] = json_encode([
    ['meter' => 'Siemens PAC2200', 'label' => 'Siemens PAC2200', 'ip' => '10.0.0.42', 'unitId' => 1],
]);
$d4->PrepareMigration();
check('Meldung: keine passende Kombination', str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', 'Keine passende Kombination'));

// ---------------------------------------------------------------------------
echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
