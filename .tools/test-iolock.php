<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Prüfstand „Sperre je Instanz" (SUITE.md Stolperstein 31, MeterHub 0.38.5).
 *
 *   php .tools/test-iolock.php               # 0 = alle Prüfungen bestanden
 *   php .tools/test-iolock.php --mutations   # Mutationsprobe: jede Mutation MUSS mindestens eine Prüfung brechen
 *
 * Bildet die Sperre (IPS_SemaphoreEnter/-Leave) so nach, dass ein zweiter Akteur sie halten kann, und prüft gegen einen
 * echten kleinen Modbus-TCP-Server: ein Lesezyklus, der die Sperre nicht bekommt, öffnet KEINE Verbindung; der
 * Schreibvorgang läuft nach der Wartezeit trotzdem; die Sperre wird immer freigegeben (auch nach einer Ausnahme);
 * verwaiste Sperren (> 45 s) werden ignoriert; Cloud-/HTTP-/MQTT-Zähler nehmen die Sperre nicht; ein Wiederholversuch
 * nach einem Verbindungsfehler (nicht nach einer Modbus-Exception); Fehlergrund mit errno/errstr; Schreibstatus-
 * Variablen ctl_write_*.
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;
foreach (['KL_MESSAGE' => 101, 'KL_WARNING' => 104, 'KL_ERROR' => 103, 'KL_NOTIFY' => 102, 'KL_DEBUG' => 100, 'KL_CUSTOM' => 105, 'KR_READY' => 10103] as $c => $v) { if (!defined($c)) { define($c, $v); } }

$GLOBALS['OBJ']  = [];
$GLOBALS['VAL']  = [];
$GLOBALS['PROP'] = [];
$GLOBALS['LOG']  = [];
$GLOBALS['SEM']  = [];          // Name => Halter ('other' = ein anderer Akteur) oder null
$GLOBALS['SEM_CALLS'] = 0;      // Zahl der IPS_SemaphoreEnter-Aufrufe
$GLOBALS['SEM_RELEASE_ON_WAIT'] = false; // true: der fremde Halter gibt während der Wartezeit frei

function obj($id, $type, $name, $parent, $ident = '') { $GLOBALS['OBJ'][$id] = ['ObjectType' => $type, 'ObjectIdent' => $ident, 'ObjectName' => $name, 'ParentID' => $parent]; return $id; }
function vari($id, $name, $parent, $ident, $type, $value) { obj($id, 2, $name, $parent, $ident); $GLOBALS['VARINFO'][$id] = ['VariableType' => $type]; $GLOBALS['VAL'][$id] = $value; return $id; }
function IPS_GetObject($id)       { return $GLOBALS['OBJ'][$id] ?? null; }
function IPS_GetVariable($id)     { return $GLOBALS['VARINFO'][$id] ?? ['VariableType' => 2]; }
function IPS_GetChildrenIDs($id)  { $o = []; foreach ($GLOBALS['OBJ'] as $k => $x) { if ($x['ParentID'] == $id) { $o[] = $k; } } return $o; }
function IPS_LogMessage($s, $m)   { $GLOBALS['LOG'][] = $m; }
function IPS_SetProperty($id, $n, $v) { $GLOBALS['PROP'][$id][$n] = $v; return true; }
function IPS_ApplyChanges($id)    { return true; }
function GetValue($id)            { return $GLOBALS['VAL'][$id] ?? 0; }
function GetValueInteger($id)     { return (int)($GLOBALS['VAL'][$id] ?? 0); }
function SetValueInteger($id, $v) { $GLOBALS['VAL'][$id] = (int)$v; }
function SetValueFloat($id, $v)   { $GLOBALS['VAL'][$id] = (float)$v; }
function SetValueBoolean($id, $v) { $GLOBALS['VAL'][$id] = (bool)$v; }
function SetValueString($id, $v)  { $GLOBALS['VAL'][$id] = (string)$v; }
function IPS_VariableExists($id)  { return isset($GLOBALS['OBJ'][$id]); }
function IPS_GetInstanceListByModuleID($guid) { return []; }
function IPS_SemaphoreEnter($name, $ms)
{
    $GLOBALS['SEM_CALLS']++;
    if (($GLOBALS['SEM'][$name] ?? null) !== null) {
        if ($GLOBALS['SEM_RELEASE_ON_WAIT']) { $GLOBALS['SEM'][$name] = null; }   // der andere Akteur wurde während der Wartezeit fertig
        else { return false; }
    }
    $GLOBALS['SEM'][$name] = 'module';
    return true;
}
function IPS_SemaphoreLeave($name) { $GLOBALS['SEM'][$name] = null; return true; }

class IPSModule
{
    use I18nStub;
    public $InstanceID;
    public function __construct($id) { $this->InstanceID = $id; }
    public function LogMessage($m, $type) { $GLOBALS['LOG'][] = $m; }
    public function SendDebug($sender, $msg, $format) {}
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($GLOBALS['PROP'][$this->InstanceID][$n] ?? false); }
    public function ReadPropertyFloat($n)   { return (float)($GLOBALS['PROP'][$this->InstanceID][$n] ?? 0.0); }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    public function ReadAttributeBoolean($n) { return (bool)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    public function ReadAttributeInteger($n) { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    public function SetBuffer($n, $v) { $GLOBALS['BUF'][$this->InstanceID][$n] = $v; }
    public function GetBuffer($n)     { return (string)($GLOBALS['BUF'][$this->InstanceID][$n] ?? ''); }
    public function SetStatus($s)     { $GLOBALS['STATUS'][$this->InstanceID] = $s; }
}


// ---------------------------------------------------------------------------
// Mutationsprobe: jede Mutation verändert eine Stelle der Sperrlogik und MUSS mindestens eine Prüfung brechen.
// ---------------------------------------------------------------------------
if (in_array('--mutations', $argv ?? [], true)) {
    $src = file_get_contents(dirname(__DIR__) . '/MeterHub/module.php');
    $mutations = [
        'Sperre komplett aus (RunLocked läuft immer ohne Semaphore)' => ["if (!\$this->UsesModbus() || !function_exists('IPS_SemaphoreEnter')) {", "if (true) {"],
        'Sperre wird nie freigegeben' => ["            if (\$held) {\n                \$this->LockLeave();\n            }\n        }\n        return true;\n    }", "        }\n        return true;\n    }"],
        'Lesezyklus läuft auch ohne Sperre' => ["} elseif (!\$this->LockIsStale()) {\n                return false;\n            }", "}"],
        'Schreibvorgang läuft nicht trotzdem' => ["if (\$mustRun) {", "if (false) {"],
        'verwaiste Sperre wird nicht erkannt' => ["} elseif (!\$this->LockIsStale()) {", "} else {"],
        'verschachtelter Aufruf scheitert' => ["\$this->mhubLockDepth++;\n            return true;", "return false;"],
        'Cloud-/HTTP-/MQTT-Zähler nehmen die Sperre' => ["    private function UsesModbus(): bool\n    {", "    private function UsesModbus(): bool\n    {\n        return true;"],
        'kein Wiederholversuch' => ["if (!\$ok && \$this->WriteRetryable(\$mb)) {", "if (false) {"],
        'Wiederholversuch auch nach Modbus-Exception' => ["return in_array(\$mb->lastError, ['connect', 'write', 'eof', 'timeout'], true);", "return true;"],
        'errno fehlt' => ["\$this->lastErrno = (int)\$errno;", "\$this->lastErrno = 0;"],
        'errstr fehlt' => ["\$this->lastErrstr = (string)\$errstr;", "\$this->lastErrstr = '';"],
        'ok_time wird nicht gesetzt' => ["\$this->SetVarInt('ctl_write_ok_time', time());", ";"],
        'Fehlerzähler zählt nicht' => ["SetValueInteger(\$count, (int)GetValueInteger(\$count) + 1);", ";"],
        'Fehlerzeit wird nicht gesetzt' => ["\$this->SetVarInt('ctl_write_fail_time', time());", ";"],
        'Protokolleintrag nicht gedrosselt' => ["if (time() - (int)\$this->GetBuffer('WriteFailLogTs') >= 60) {", "if (true) {"],
        'ReadFast ohne Sperre' => ["\$ran = \$this->RunLocked(self::LOCK_WAIT_READ_MS, false, function () use (\$driver, &\$ok) {", "\$ran = (function (\$w, \$m, \$fn) { \$fn(); return true; })(0, false, function () use (\$driver, &\$ok) {"],
        'ReadSlow ohne Sperre' => ["\$ran = \$this->RunLocked(self::LOCK_WAIT_READ_MS, false, function () use (\$driver) {", "\$ran = (function (\$w, \$m, \$fn) { \$fn(); return true; })(0, false, function () use (\$driver) {"],
        'TestConnection ohne Sperre' => ["\$ran = \$this->RunLocked(self::LOCK_WAIT_READ_MS, false, function () use (&\$mb, &\$ok) {", "\$ran = (function (\$w, \$m, \$fn) { \$fn(); return true; })(0, false, function () use (&\$mb, &\$ok) {"],
        'SetBlueLogTarget ohne Sperre' => ["\$this->RunLocked(self::LOCK_WAIT_WRITE_MS, true, function () use (\$driver, &\$ok) {", "(function (\$w, \$m, \$fn) { \$fn(); return true; })(0, true, function () use (\$driver, &\$ok) {"],
        'SetBlueLogTarget ohne Schreibstatus/Wiederholversuch' => ["\$ok = \$this->WriteSetpointChecked(\$driver, \$mb, 'setpoint');\n            \$this->FlushTransportNotices(\$mb);\n            if (method_exists(\$mb, 'close')) {", "\$ok = (bool)\$driver->writeSetpoint(\$mb, \$this);\n            \$this->FlushTransportNotices(\$mb);\n            if (method_exists(\$mb, 'close')) {"],
        'Schreib-Schalter: ReadFast schreibt immer' => ["if (\$driver instanceof MHUB_WritableMeterDriverInterface && \$this->SetpointWritingEnabled()) {", "if (\$driver instanceof MHUB_WritableMeterDriverInterface) {"],
        'Schreib-Schalter: SetBlueLogTarget schreibt immer' => ["if (!\$this->SetpointWritingEnabled()) {\n            return MHUB_Text::t('❌ Read only:", "if (false) {\n            return MHUB_Text::t('❌ Read only:"],
        'Schreib-Schalter: Rückfallwert schreibt immer' => ["|| !\$this->SetpointWritingEnabled() || \$this->FailsafeMode() !== 'default') {", "|| \$this->FailsafeMode() !== 'default') {"],
        'Schreib-Schalter: „Nur lesen“ wirkt nicht' => ["if (\$mode === 'off') {\n            return false;\n        }\n        return \$this->ReadAttributeString('WriteLegacy') !== '0';", "return \$this->ReadAttributeString('WriteLegacy') !== '0';"],
        'Schreib-Schalter: „Schreiben“ wirkt nicht' => ["if (\$mode === 'on') {\n            return true;\n        }\n        if (\$mode === 'off') {", "if (\$mode === 'off') {"],
        'Schreib-Schalter: Automatik schreibt nie' => ["return \$this->ReadAttributeString('WriteLegacy') !== '0';\n    }\n\n    /** Einmalig je Instanz", "return false;\n    }\n\n    /** Einmalig je Instanz"],
        'Schreib-Schalter: Automatik liest nie nur' => ["return \$this->ReadAttributeString('WriteLegacy') !== '0';\n    }\n\n    /** Einmalig je Instanz", "return true;\n    }\n\n    /** Einmalig je Instanz"],
        'Schreib-Schalter: Bestand ohne Variablen gilt als Bestand' => ["if (is_array(\$o) && (\$o['ObjectType'] ?? 0) === 2) {\n                    \$legacy = true;", "if (true) {\n                    \$legacy = true;"],
        'Schreib-Schalter: nicht schreibender Typ gilt als Bestand' => ["        if (\$this->IsWritableMeter()) {\n            foreach (IPS_GetChildrenIDs(\$this->InstanceID) as \$cid) {", "        if (true) {\n            foreach (IPS_GetChildrenIDs(\$this->InstanceID) as \$cid) {"],
        'Schreib-Schalter: Entscheidung nicht einmalig' => ["if (\$this->ReadAttributeString('WriteLegacy') !== '') {\n            return;\n        }\n        \$legacy = false;", "\$legacy = false;"],
    ];
    $procs = [];
    $survived = 0;
    $i = 0;
    foreach ($mutations as $name => [$find, $replace]) {
        if (substr_count($src, $find) !== 1) { echo "  ?? Mutation „$name“: Fundstelle " . substr_count($src, $find) . "x (muss genau 1x vorkommen)\n"; $survived++; continue; }
        // module.php braucht ../libs (ausgelagerte Methodengruppen, ab 0.40.0): Mutation in einer Kopie der Ordnerstruktur
        $dir = sys_get_temp_dir() . '/mhub_mut_' . getmypid() . '_' . (++$i);
        @mkdir("$dir/MeterHub", 0777, true);
        @mkdir("$dir/libs", 0777, true);
        foreach (glob(dirname(__DIR__) . '/libs/*.php') as $lib) { copy($lib, "$dir/libs/" . basename($lib)); }
        $tmp = "$dir/MeterHub/module.php";
        file_put_contents($tmp, str_replace($find, $replace, $src));
        $procs[$name] = [$tmp, proc_open([PHP_BINARY, __FILE__], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MHUB_MODULE' => $tmp] + getenv()), $pipes];
        if (count($procs) >= 6) { foreach ($procs as $n => $pr) { $survived += mutDone($n, $pr); } $procs = []; }
    }
    foreach ($procs as $n => $pr) { $survived += mutDone($n, $pr); }
    echo "\n" . ($survived === 0 ? "ALLE " . count($mutations) . " MUTATIONEN ERKANNT\n" : "$survived MUTATION(EN) ÜBERLEBT\n");
    exit($survived === 0 ? 0 : 1);
}
function mutDone(string $name, array $pr): int
{
    $out = stream_get_contents($pr[2][1]) . stream_get_contents($pr[2][2]);
    $code = proc_close($pr[1]);
    shell_exec('rm -rf ' . escapeshellarg(dirname($pr[0], 2)));
    if ($code === 0) { echo "  ÜBERLEBT  $name\n"; return 1; }
    // „erkannt“ zählt nur, wenn die Mutante wirklich an einer Prüfung scheitert und nicht schlicht abstürzt.
    $broken = substr_count($out, 'FEHLT');
    if ($broken === 0) { echo "  ABSTURZ   $name (keine Prüfung gebrochen, Mutante läuft nicht sauber)\n"; return 1; }
    echo "  erkannt   $name ($broken Prüfung(en) gebrochen)\n";
    return 0;
}

$modulePath = getenv('MHUB_MODULE') ?: dirname(__DIR__) . '/MeterHub/module.php';
require_once $modulePath;
MHUB_Text::set(function (string $t): string { return i18n_tr($t); });   // Prüfstand simuliert die deutsche Oberfläche

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

/** Server-Prozess: nimmt Verbindungen an und zählt sie. Modi: normal | exception */
function startServer(string $mode): array
{
    $port = random_int(20000, 60000);
    $cnt  = tempnam(sys_get_temp_dir(), 'mbcnt');
    $code = <<<'PHP'
[$port, $mode, $cnt] = [(int)$argv[1], $argv[2], $argv[3]];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) { exit(1); }
file_put_contents($cnt, '0');
$conns = 0;
$rx = function ($c, $n) { $b = ''; while (strlen($b) < $n) { $x = fread($c, $n - strlen($b)); if ($x === false || $x === '') { return null; } $b .= $x; } return $b; };
while ($c = @stream_socket_accept($srv, 30)) {
    $conns++;
    file_put_contents($cnt, (string)$conns);
    while (true) {
        $head = $rx($c, 7);
        if ($head === null) { break; }
        $h = unpack('ntid/npid/nlen/Cunit', $head);
        $pdu = $rx($c, $h['len'] - 1);
        if ($pdu === null) { break; }
        $fc = ord($pdu[0]);
        if ($mode === 'exception') {
            $resp = chr($fc | 0x80) . chr(2);
        } elseif ($fc === 3 || $fc === 4) {
            $a = unpack('nstart/ncount', substr($pdu, 1, 4));
            $data = '';
            for ($i = 0; $i < $a['count']; $i++) { $data .= pack('n', ($a['start'] + $i) & 0xFFFF); }
            $resp = chr($fc) . chr(strlen($data)) . $data;
        } else {
            $resp = substr($pdu, 0, 5);
        }
        fwrite($c, pack('nnn', $h['tid'], 0, strlen($resp) + 1) . chr($h['unit']) . $resp);
    }
    fclose($c);
}
PHP;
    $proc = proc_open([PHP_BINARY, '-r', $code, (string)$port, $mode, $cnt], [], $pipes);
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents($cnt) === '0') { break; }
        usleep(100000);
    }
    return [$proc, $port, $cnt];
}
function stopServer(array $srv): void { proc_terminate($srv[0]); proc_close($srv[0]); @unlink($srv[2]); }
function serverConns(array $srv): int { usleep(200000); return (int)@file_get_contents($srv[2]); }

function resetWorld(): void
{
    $GLOBALS['SEM'] = []; $GLOBALS['SEM_CALLS'] = 0; $GLOBALS['SEM_RELEASE_ON_WAIT'] = false;
    $GLOBALS['LOG'] = []; $GLOBALS['BUF'] = []; $GLOBALS['STATUS'] = [];
}
function priv(object $o, string $method, ...$args)
{
    $m = new ReflectionMethod($o, $method);
    return $m->invoke($o, ...$args);
}
function lastLogContains(string $needle): bool
{
    foreach ($GLOBALS['LOG'] as $l) { if (strpos($l, $needle) !== false) { return true; } }
    return false;
}

const ID = 100;
obj(ID, 1, 'blue\'Log', 0);
vari(110, 'Fehler (Anzahl)', ID, 'ctl_write_fail_count', VARIABLETYPE_INTEGER, 0);
vari(111, 'Fehler (Zeit)',   ID, 'ctl_write_fail_time',  VARIABLETYPE_INTEGER, 0);
vari(112, 'Fehler (Text)',   ID, 'ctl_write_fail_text',  VARIABLETYPE_STRING, '');
vari(113, 'OK (Zeit)',       ID, 'ctl_write_ok_time',    VARIABLETYPE_INTEGER, 0);
$hub = new MeterHub(ID);
$GLOBALS['PROP'][ID] = ['Meter' => 'bluelog_rpc', 'Host' => '127.0.0.1', 'Port' => 1, 'UnitId' => 10, 'Active' => true, 'ConnectionMode' => 'direct', 'SetpointMode' => 'rel', 'TargetSetpoint' => 80.0, 'RpcValidTimeMin' => 10];

// ---------------------------------------------------------------------------
echo "1) Sperre: RunLocked\n";
resetWorld();
$ran = false;
$r = priv($hub, 'RunLocked', 8000, false, function () use (&$ran) { $ran = true; });
check('1a: Sperre frei -> Funktion läuft', $r === true && $ran);
check('1b: Sperre danach wieder frei, Zeitstempel gelöscht', ($GLOBALS['SEM']['MHUB_IO_' . ID] ?? null) === null && $hub->GetBuffer('IoLockSince') === '');

resetWorld();
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$ran = false;
$r = priv($hub, 'RunLocked', 8000, false, function () use (&$ran) { $ran = true; });
check('1c: fremder Halter, Lesezyklus -> fällt aus', $r === false && !$ran);
check('1d: die fremde Sperre bleibt unangetastet', $GLOBALS['SEM']['MHUB_IO_' . ID] === 'other');

resetWorld();
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$ran = false;
$r = priv($hub, 'RunLocked', 20000, true, function () use (&$ran) { $ran = true; });
check('1e: fremder Halter, Schreibvorgang -> läuft trotzdem', $r === true && $ran);
check('1f: ... mit Warnung im Protokoll', lastLogContains('nicht innerhalb von 20 s'));
check('1g: ... und gibt die fremde Sperre nicht frei', $GLOBALS['SEM']['MHUB_IO_' . ID] === 'other');

resetWorld();
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$GLOBALS['SEM_RELEASE_ON_WAIT'] = true;
$ran = false;
$r = priv($hub, 'RunLocked', 20000, true, function () use (&$ran) { $ran = true; });
check('1h: Halter wird während der Wartezeit fertig -> Schreiben läuft ohne Warnung', $r === true && $ran && $GLOBALS['LOG'] === []);
check('1i: ... und die Sperre ist danach frei', ($GLOBALS['SEM']['MHUB_IO_' . ID] ?? null) === null);

resetWorld();
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$hub->SetBuffer('IoLockSince', (string)(time() - 100));
$ran = false;
$r = priv($hub, 'RunLocked', 8000, false, function () use (&$ran) { $ran = true; });
check('1j: verwaiste Sperre (> 45 s) wird ignoriert, Lesezyklus läuft', $r === true && $ran);

resetWorld();
$thrown = false;
try { priv($hub, 'RunLocked', 8000, false, function () { throw new RuntimeException('boom'); }); } catch (RuntimeException $e) { $thrown = true; }
check('1k: Ausnahme in der Funktion läuft nach außen', $thrown);
check('1l: ... und die Sperre ist trotzdem frei (finally)', ($GLOBALS['SEM']['MHUB_IO_' . ID] ?? null) === null && $hub->GetBuffer('IoLockSince') === '');

resetWorld();
$inner = false;
$r = priv($hub, 'RunLocked', 8000, true, function () use ($hub, &$inner) {
    priv($hub, 'RunLocked', 8000, false, function () use (&$inner) { $inner = true; });
});
check('1m: verschachtelter Aufruf läuft (Tiefenzähler, kein Selbst-Deadlock)', $r === true && $inner);
check('1n: ... und gibt die Sperre erst am äußeren Ende frei', ($GLOBALS['SEM']['MHUB_IO_' . ID] ?? null) === null);

foreach (['inexogy', 'tasmota', 'shelly_mqtt'] as $m) {
    resetWorld();
    $GLOBALS['PROP'][ID]['Meter'] = $m;
    $GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
    $ran = false;
    $r = priv($hub, 'RunLocked', 8000, false, function () use (&$ran) { $ran = true; });
    check("1o: $m nimmt die Sperre nicht (läuft auch bei fremdem Halter, kein Semaphore-Aufruf)", $r === true && $ran && $GLOBALS['SEM_CALLS'] === 0);
}
$GLOBALS['PROP'][ID]['Meter'] = 'bluelog_rpc';

// ---------------------------------------------------------------------------
echo "\n2) Sollwert schreiben: Wiederholversuch, Fehlergrund, Schreibstatus\n";
class FakeDriver implements MHUB_WritableMeterDriverInterface
{
    public $seq; public $calls = 0;
    public function __construct(array $seq) { $this->seq = $seq; }
    public function writeSetpoint($mb, $hub): bool { $r = array_shift($this->seq) ?? false; $this->calls++; return $r; }
    public function writeFailsafe($mb, $hub): void {}
}
class FakeClient
{
    public $lastError = ''; public $closed = 0; public $text = '';
    public function close(): void { $this->closed++; }
    public function failureText(): string { return $this->text; }
}
function setVars(int $fc, int $ft, string $tx, int $ok): void { $GLOBALS['VAL'][110] = $fc; $GLOBALS['VAL'][111] = $ft; $GLOBALS['VAL'][112] = $tx; $GLOBALS['VAL'][113] = $ok; }

resetWorld(); setVars(0, 0, '', 0);
$d = new FakeDriver([true]); $c = new FakeClient();
$ok = priv($hub, 'WriteSetpointChecked', $d, $c, 'setpoint');
check('2a: sofort angekommen -> ein Versuch', $ok === true && $d->calls === 1);
check('2b: ok_time gesetzt, kein Fehler vermerkt', GetValue(113) > 0 && GetValue(110) === 0 && GetValue(111) === 0);

resetWorld(); setVars(0, 0, '', 0);
$d = new FakeDriver([false, true]); $c = new FakeClient(); $c->lastError = 'connect'; $c->text = 'connect (111: Connection refused)';
$t0 = microtime(true);
$ok = priv($hub, 'WriteSetpointChecked', $d, $c, 'setpoint');
$dt = microtime(true) - $t0;
check('2c: Verbindungsfehler -> zweiter Versuch gelingt', $ok === true && $d->calls === 2);
check('2d: ... nach etwa 1,5 s Pause', $dt >= 1.4 && $dt < 3.0, sprintf('%.2f s', $dt));
check('2e: ... Verbindung dazwischen geschlossen', $c->closed >= 1);
check('2f: ... als Erfolg gezählt, kein Fehler vermerkt', GetValue(113) > 0 && GetValue(110) === 0);

resetWorld(); setVars(0, 0, '', 0);
$d = new FakeDriver([false, false]); $c = new FakeClient(); $c->lastError = 'connect'; $c->text = 'connect (111: Connection refused)';
$ok = priv($hub, 'WriteSetpointChecked', $d, $c, 'setpoint');
check('2g: beide Versuche scheitern -> false', $ok === false && $d->calls === 2);
check('2h: Fehlerzähler 1, Zeit gesetzt', GetValue(110) === 1 && GetValue(111) > 0);
check('2i: Fehlertext mit errno/errstr', strpos((string)GetValue(112), 'connect (111: Connection refused)') !== false, (string)GetValue(112));
check('2j: ok_time bleibt unberührt', GetValue(113) === 0);
check('2k: Fehlergrund im Protokoll', lastLogContains('connect (111: Connection refused)'));
$logs = count($GLOBALS['LOG']);
$d = new FakeDriver([false, false]);
priv($hub, 'WriteSetpointChecked', $d, $c, 'setpoint');
check('2l: Zähler zählt weiter (2)', GetValue(110) === 2);
check('2m: Protokoll höchstens einmal je Minute (kein zweiter Eintrag)', count($GLOBALS['LOG']) === $logs, count($GLOBALS['LOG']) . ' statt ' . $logs);

resetWorld(); setVars(0, 0, '', 0);
$d = new FakeDriver([false, true]); $c = new FakeClient(); $c->lastError = ''; // Modbus-Exception: gültige Antwort, das Gerät lehnt ab
$t0 = microtime(true);
$ok = priv($hub, 'WriteSetpointChecked', $d, $c, 'setpoint');
check('2n: Modbus-Exception -> KEIN Wiederholversuch', $ok === false && $d->calls === 1 && (microtime(true) - $t0) < 1.0);
check('2o: ... Text „Gerät hat den Wert abgelehnt“', strpos((string)GetValue(112), 'abgelehnt') !== false, (string)GetValue(112));

// ohne die vier Variablen darf nichts brechen
resetWorld();
$GLOBALS['OBJ'] = array_filter($GLOBALS['OBJ'], function ($o) { return strpos($o['ObjectIdent'], 'ctl_write_') !== 0; });
$d = new FakeDriver([false, false]); $c = new FakeClient(); $c->lastError = 'timeout';
$ok = priv($hub, 'WriteSetpointChecked', $d, $c, 'setpoint');
check('2p: ohne ctl_write_*-Variablen läuft nichts schief', $ok === false);
vari(110, 'Fehler (Anzahl)', ID, 'ctl_write_fail_count', VARIABLETYPE_INTEGER, 0);
vari(111, 'Fehler (Zeit)',   ID, 'ctl_write_fail_time',  VARIABLETYPE_INTEGER, 0);
vari(112, 'Fehler (Text)',   ID, 'ctl_write_fail_text',  VARIABLETYPE_STRING, '');
vari(113, 'OK (Zeit)',       ID, 'ctl_write_ok_time',    VARIABLETYPE_INTEGER, 0);

// ---------------------------------------------------------------------------
echo "\n3) Echter Client: errno/errstr bei Verbindungsfehler, Treiber meldet Erfolg\n";
$cl = new MHUB_ModbusTcpClient('127.0.0.1', 1, 1);   // Port 1: nichts lauscht
$cl->readHolding(0, 1);
check('3a: lastError connect, errno/errstr gesetzt', $cl->lastError === 'connect' && $cl->lastErrno !== 0 && $cl->lastErrstr !== '', "errno={$cl->lastErrno} errstr={$cl->lastErrstr}");
check('3b: failureText nennt Grund und Nummer', preg_match('/^connect \(\d+: .+\)$/', $cl->failureText()) === 1, $cl->failureText());
$cl2 = new MHUB_ModbusTcpClient('127.0.0.1', 1, 1);
check('3c: ohne Fehler ist failureText leer', $cl2->failureText() === '');
$srv = startServer('normal');
$cl3 = new MHUB_ModbusTcpClient('127.0.0.1', $srv[1], 10);
$drv = new MHUB_BlueLogRpcDriver();
check('3d: blue\'Log RPC writeSetpoint meldet true bei Erfolg', $drv->writeSetpoint($cl3, $hub) === true);
$cl3->close();
stopServer($srv);
$srvE = startServer('exception');
$cl4 = new MHUB_ModbusTcpClient('127.0.0.1', $srvE[1], 10);
check('3e: ... und false bei Modbus-Exception', $drv->writeSetpoint($cl4, $hub) === false && $cl4->lastError === '');
$cl4->close();
stopServer($srvE);
$pc = new MHUB_BlueLogPowerControlDriver();
$srv = startServer('normal');
$cl5 = new MHUB_ModbusTcpClient('127.0.0.1', $srv[1], 1);
check('3f: blue\'Log Power Control writeSetpoint meldet true bei Erfolg', $pc->writeSetpoint($cl5, $hub) === true);
$cl5->close();
stopServer($srv);
foreach (['MHUB_BlueLogRpcDriver', 'MHUB_BlueLogPowerControlDriver'] as $cls) {
    $idents = array_map(function ($v) { return $v[0]; }, (new $cls())->getBaseVars());
    check("3g: $cls führt die vier ctl_write_*-Variablen", count(array_intersect($idents, ['ctl_write_fail_count', 'ctl_write_fail_time', 'ctl_write_fail_text', 'ctl_write_ok_time'])) === 4);
}

// ---------------------------------------------------------------------------
echo "\n4) Ganzer Weg gegen den Server: ReadFast / ReadSlow / TestConnection / SetBlueLogTarget\n";
$srv = startServer('normal');
$GLOBALS['PROP'][ID]['Port'] = $srv[1];

resetWorld(); setVars(0, 0, '', 0);
$hub->ReadFast();
check('4a: ReadFast bei freier Sperre: genau EINE Verbindung für Lesen und Schreiben', serverConns($srv) === 1, (string)serverConns($srv));
check('4b: ... Sperre danach frei, Sollwert als angekommen vermerkt', ($GLOBALS['SEM']['MHUB_IO_' . ID] ?? null) === null && GetValue(113) > 0);

resetWorld();
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$before = serverConns($srv);
$hub->ReadFast();
check('4c: ReadFast bei fremdem Halter öffnet KEINE Verbindung', serverConns($srv) === $before, serverConns($srv) . ' statt ' . $before);

// ReadSlow: einen Zähler mit echtem readSlow() (PAC2200 liest Energieregister)
resetWorld();
$GLOBALS['PROP'][ID]['Meter'] = 'siemens_pac2200';
$hubP = new MeterHub(ID);   // GetDriver() merkt sich den Treiber je Objekt
$before = serverConns($srv);
$hubP->ReadSlow();
check('4d: ReadSlow bei freier Sperre liest (Verbindung)', serverConns($srv) === $before + 1, serverConns($srv) . ' statt ' . ($before + 1));
$before = serverConns($srv);
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$hubP->ReadSlow();
check('4e: ReadSlow bei fremdem Halter öffnet KEINE Verbindung', serverConns($srv) === $before, serverConns($srv) . ' statt ' . $before);

// TestConnection
resetWorld();
$before = serverConns($srv);
$msg = $hubP->TestConnection();
check('4f: TestConnection bei freier Sperre liest', serverConns($srv) === $before + 1 && strpos($msg, '✅') !== false, $msg);
$before = serverConns($srv);
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$msg = $hubP->TestConnection();
check('4g: TestConnection bei fremdem Halter öffnet KEINE Verbindung und meldet es', serverConns($srv) === $before && strpos($msg, 'ausgelassen') !== false, $msg);

// SetBlueLogTarget
$GLOBALS['PROP'][ID]['Meter'] = 'bluelog_rpc';
resetWorld(); setVars(0, 0, '', 0);
$before = serverConns($srv);
$msg = $hub->SetBlueLogTarget(55.0);
check('4h: SetBlueLogTarget schreibt über EINE Verbindung', serverConns($srv) === $before + 1 && strpos($msg, '✅') !== false, $msg);
check('4i: ... ok_time gesetzt', GetValue(113) > 0);

resetWorld(); setVars(0, 0, '', 0);
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';
$GLOBALS['SEM_RELEASE_ON_WAIT'] = true;      // der laufende Lesezyklus wird während der Wartezeit fertig
$before = serverConns($srv);
$msg = $hub->SetBlueLogTarget(60.0);
check('4j: SetBlueLogTarget wartet auf den laufenden Zyklus und schreibt dann', serverConns($srv) === $before + 1 && strpos($msg, '✅') !== false && $GLOBALS['SEM_CALLS'] >= 1, $msg);

resetWorld(); setVars(0, 0, '', 0);
$GLOBALS['SEM']['MHUB_IO_' . ID] = 'other';  // hängender Lesezyklus: nie fertig
$before = serverConns($srv);
$msg = $hub->SetBlueLogTarget(61.0);
check('4k: SetBlueLogTarget schreibt bei hängendem Lesezyklus trotzdem (Sollwert nicht verlieren)', serverConns($srv) === $before + 1 && strpos($msg, '✅') !== false, $msg);
check('4l: ... und warnt im Protokoll', lastLogContains('trotzdem geschrieben'));

// ---------------------------------------------------------------------------
echo "\n5) Nur-lesen-Schalter (0.42.5): SetpointWrite auto/on/off\n";
$GLOBALS['PROP'][ID]['Meter'] = 'bluelog_rpc';
$GLOBALS['PROP'][ID]['Port'] = $srv[1];
$hubW = new MeterHub(ID);
$modes = [
    ['off',  '',  false, '5a: „Nur lesen“ liest, schreibt aber nichts'],
    ['on',   '0', true,  '5b: „Schreiben“ schreibt (auch bei neuer Instanz)'],
    ['auto', '',  true,  '5c: Automatik, noch unentschieden: schreibt wie bisher (laufende Regelung nie unterbrechen)'],
    ['auto', '1', true,  '5d: Automatik, Bestandsinstanz: schreibt weiter'],
    ['auto', '0', false, '5e: Automatik, neue Instanz: liest nur'],
    ['off',  '1', false, '5f: „Nur lesen“ gilt auch bei einer Bestandsinstanz'],
];
foreach ($modes as [$mode, $legacy, $expectWrite, $label]) {
    resetWorld(); setVars(0, 0, '', 0);
    $GLOBALS['PROP'][ID]['SetpointWrite'] = $mode;
    $hubW->WriteAttributeString('WriteLegacy', $legacy);
    $before = serverConns($srv);
    $hubW->ReadFast();
    $read = serverConns($srv) === $before + 1;
    $wrote = GetValue(113) > 0;
    check($label, $read && $wrote === $expectWrite, "Verbindungen +" . (serverConns($srv) - $before) . ', geschrieben: ' . var_export($wrote, true));
}
check('5g: SetpointWritingEnabled() antwortet je Stellung', (function () use ($hubW) {
    $r = [];
    foreach ([['on', '0', true], ['off', '1', false], ['auto', '1', true], ['auto', '0', false], ['auto', '', true]] as [$m, $l, $want]) {
        $GLOBALS['PROP'][ID]['SetpointWrite'] = $m; $hubW->WriteAttributeString('WriteLegacy', $l);
        $r[] = $hubW->SetpointWritingEnabled() === $want;
    }
    return !in_array(false, $r, true);
})());

// SetBlueLogTarget: bei „Nur lesen“ keine Verbindung, kein Schreiben, Zielwert bleibt unverändert
resetWorld(); setVars(0, 0, '', 0);
$GLOBALS['PROP'][ID]['SetpointWrite'] = 'off';
$GLOBALS['PROP'][ID]['TargetSetpoint'] = 80.0;
$before = serverConns($srv);
$msg = $hubW->SetBlueLogTarget(42.0);
check('5h: SetBlueLogTarget bei „Nur lesen“ lehnt ab, ohne Verbindung', strpos($msg, '❌') !== false && serverConns($srv) === $before && GetValue(113) === 0, $msg);
check('5i: ... und lässt den gespeicherten Zielwert unverändert', (float)$GLOBALS['PROP'][ID]['TargetSetpoint'] === 80.0);
$GLOBALS['PROP'][ID]['SetpointWrite'] = 'on';
$msg = $hubW->SetBlueLogTarget(43.0);
check('5j: bei „Schreiben“ geht SetBlueLogTarget wie bisher', strpos($msg, '✅') !== false, $msg);

// Rückfallwert beim Deaktivieren ist auch ein Schreibzugriff
resetWorld(); setVars(0, 0, '', 0);
$GLOBALS['PROP'][ID]['FailsafeMode'] = 'default';
$GLOBALS['PROP'][ID]['Active'] = false;
$GLOBALS['PROP'][ID]['SetpointWrite'] = 'off';
$before = serverConns($srv);
priv($hubW, 'WriteFailsafeOnDeactivate');
check('5k: Deaktivieren bei „Nur lesen“ schreibt keinen Rückfallwert', serverConns($srv) === $before, (string)(serverConns($srv) - $before));
$GLOBALS['PROP'][ID]['SetpointWrite'] = 'on';
$before = serverConns($srv);
priv($hubW, 'WriteFailsafeOnDeactivate');
check('5l: ... bei „Schreiben“ schon (wie bisher)', serverConns($srv) === $before + 1, (string)(serverConns($srv) - $before));
$GLOBALS['PROP'][ID]['Active'] = true;
$GLOBALS['PROP'][ID]['FailsafeMode'] = 'hold';

// Entscheidung „Bestand“ einmalig beim ersten Übernehmen
$mkHub = function (int $id, string $meter, bool $withVar) {
    obj($id, 1, 'Test ' . $id, 0);
    if ($withVar) { vari($id + 1000, 'Wert', $id, 'power_total', VARIABLETYPE_FLOAT, 1.0); }
    $GLOBALS['PROP'][$id] = ['Meter' => $meter, 'Host' => '127.0.0.1', 'Port' => 1, 'UnitId' => 10, 'Active' => false, 'ConnectionMode' => 'direct'];
    return new MeterHub($id);
};
$hNew = $mkHub(200, 'bluelog_rpc', false);
priv($hNew, 'DecideWriteLegacy');
check('5m: neue schreibende Instanz ohne Variablen: Automatik liest nur', $hNew->ReadAttributeString('WriteLegacy') === '0' && $hNew->SetpointWritingEnabled() === false);
$hCat = $mkHub(230, 'bluelog_rpc', false);
obj(1230, 0, 'Kategorie', 230);   // ein Kind, das keine Variable ist, macht die Instanz nicht zum Bestand
priv($hCat, 'DecideWriteLegacy');
check('5m2: nur ein Nicht-Variablen-Kind: keine Bestandsinstanz', $hCat->ReadAttributeString('WriteLegacy') === '0');
$hOld = $mkHub(210, 'bluelog_rpc', true);
priv($hOld, 'DecideWriteLegacy');
check('5n: Bestand (schreibender Typ, Variablen vorhanden): Automatik schreibt weiter', $hOld->ReadAttributeString('WriteLegacy') === '1' && $hOld->SetpointWritingEnabled() === true);
$hOther = $mkHub(220, 'siemens_pac2200', true);
priv($hOther, 'DecideWriteLegacy');
check('5o: nicht schreibender Zählertyp: entschieden auf „0“', $hOther->ReadAttributeString('WriteLegacy') === '0');
$GLOBALS['PROP'][220]['Meter'] = 'bluelog_rpc';
priv($hOther, 'DecideWriteLegacy');
check('5p: später auf RPC umgestellt: bleibt „0“ (einmal entschieden, nie nachträglich Bestand)', $hOther->ReadAttributeString('WriteLegacy') === '0' && $hOther->SetpointWritingEnabled() === false);
$GLOBALS['PROP'][210]['Meter'] = 'siemens_pac2200';
priv($hOld, 'DecideWriteLegacy');
check('5q: Entscheidung ist einmalig (zweiter Aufruf ändert nichts)', $hOld->ReadAttributeString('WriteLegacy') === '1');

stopServer($srv);

// Verbindungsfehler: Wiederholversuch + Fehlergrund
resetWorld(); setVars(0, 0, '', 0);
$GLOBALS['PROP'][ID]['Port'] = 1;
$t0 = microtime(true);
$msg = $hub->SetBlueLogTarget(70.0);
$dt = microtime(true) - $t0;
check('4m: Verbindung unmöglich -> Meldung „fehlgeschlagen“', strpos($msg, '❌') !== false && strpos($msg, 'fehlgeschlagen') !== false, $msg);
check('4n: ... nach einem Wiederholversuch (etwa 1,5 s Pause)', $dt >= 1.4, sprintf('%.2f s', $dt));
check('4o: ... Fehlerzähler 1 und Text mit errno/errstr', GetValue(110) === 1 && preg_match('/connect \(\d+: /', (string)GetValue(112)) === 1, (string)GetValue(112));
check('4p: ... Sperre danach frei', ($GLOBALS['SEM']['MHUB_IO_' . ID] ?? null) === null);

echo "\n";
if ($fails === 0) { echo "ALLE PRÜFUNGEN BESTANDEN\n"; exit(0); }
echo "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n";
exit(1);
