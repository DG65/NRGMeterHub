<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Prüfstand Tasmota-Treiber (0.36.0, experimentell).
 *
 *   php .tools/test-tasmota.php    # 0 = alle Prüfungen bestanden
 *
 * Startet einen echten kleinen HTTP-Server (php -S) mit Beispielantworten nach der Tasmota-Dokumentation
 * (NICHT von echten Geräten aufgezeichnet) und prüft Client (genau ein Aufruf, Fehlertexte) und Treiber
 * (Feldzuordnung, Phasenlisten, SML-Skriptnamen, Wh/kWh, Vorzeichen) gegen einen Fake-Hub.
 */
if (!class_exists('IPSModule')) {
    class IPSModule { use I18nStub; public $InstanceID = 0; public function __construct($id = 0) { $this->InstanceID = $id; } }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}
require_once dirname(__DIR__) . '/MeterHub/module.php';
MHUB_Text::set(function (string $t): string { return i18n_tr($t); });

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

// --- Server: Antwort und Zähler liegen in Dateien, so lässt sich je Fall umschalten --------------------
$dir = sys_get_temp_dir() . '/tasmota-test-' . getmypid();
mkdir($dir);
file_put_contents("$dir/router.php", <<<'PHP'
<?php
$d = getenv('TASMOTA_DIR');
file_put_contents("$d/count", (int)@file_get_contents("$d/count") + 1);
file_put_contents("$d/lastquery", $_SERVER['REQUEST_URI']);
$status = (int)@file_get_contents("$d/status") ?: 200;
http_response_code($status);
header('Content-Type: application/json');
echo (string)@file_get_contents("$d/body");
PHP);
$port = random_int(20000, 60000);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, ['TASMOTA_DIR' => $dir]);
register_shutdown_function(function () use ($proc, $dir) { proc_terminate($proc); array_map('unlink', glob("$dir/*")); @rmdir($dir); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port, $e, $es, 0.1)) { break; } usleep(100000); }

function serve(string $body, int $status = 200): void
{
    global $dir;
    file_put_contents("$dir/body", $body);
    file_put_contents("$dir/status", (string)$status);
    file_put_contents("$dir/count", '0');
}
function calls(): int { global $dir; return (int)file_get_contents("$dir/count"); }

class FakeHub
{
    public $v = []; public $groups = []; public $set = [];
    public function __construct(array $set = [], array $groups = []) { $this->set = $set; $this->groups = $groups; }
    public function HttpSetting(string $k): string { return $this->set[$k] ?? ''; }
    public function GroupActive(string $g): bool { return in_array($g, $this->groups, true); }
    public function SetVarFloat($id, $x) { $this->v[$id] = (float)$x; }
    public function SetVarBool($id, $x) { $this->v[$id] = (bool)$x; }
    public function SetVarEnergykWh($id, $x) { $this->v[$id] = round((float)$x, 6); }
}
function run(MHUB_TasmotaDriver $drv, MHUB_TasmotaClient $c, FakeHub $h): FakeHub { $drv->readFast($c, $h); $drv->readSlow($c, $h); return $h; }

$drv = new MHUB_TasmotaDriver();
$cl  = new MHUB_TasmotaClient('127.0.0.1', $port, 3.0);

// 1) Einphasig, Standardblock ENERGY (Nous A1T o. ä.) ----------------------------------------------
echo "1) Einphasig, Standardblock ENERGY\n";
serve('{"StatusSNS":{"Time":"2026-11-14T10:00:00","ENERGY":{"TotalStartTime":"2025-01-01T00:00:00","Total":1234.567,"Yesterday":1.2,"Today":0.5,"Period":3,"Power":230,"ApparentPower":240,"ReactivePower":50,"Factor":0.95,"Voltage":231,"Current":1.0,"ExportActive":7.5}}}');
$h = run($drv, $cl, new FakeHub([], ['GroupElectrical']));
check('1a: genau ein HTTP-Aufruf je Lesevorgang', calls() === 2, 'Aufrufe: ' . calls());
check('1b: Abfrage ist „Status 8“', str_contains(file_get_contents("$dir/lastquery"), 'cmnd=Status%208'));
check('1c: Leistung', ($h->v['power_total'] ?? null) === 230.0);
check('1d: Bezug in kWh (nicht skaliert)', ($h->v['energy_import'] ?? null) === 1234.567);
check('1e: Einspeisung aus ExportActive', ($h->v['energy_export'] ?? null) === 7.5);
check('1f: Spannung und Strom nur mit aktiver Gruppe', ($h->v['voltage_avg'] ?? null) === 231.0 && ($h->v['current_avg'] ?? null) === 1.0);
check('1g: verbunden', ($h->v['connected'] ?? null) === true);
$h2 = run($drv, $cl, new FakeHub());
check('1h: ohne aktive Gruppe keine Spannungs-/Stromvariablen', !isset($h2->v['voltage_avg']) && !isset($h2->v['current_avg']));
$h3 = run($drv, $cl, new FakeHub(['TasmotaEnergyUnit' => 'wh']));
check('1i: Einheit „Wh“ ändert beim Standardblock nichts (immer kWh)', ($h3->v['energy_import'] ?? null) === 1234.567);

// 2) Dreiphasig mit Listen --------------------------------------------------------------------------
echo "2) Dreiphasig (Listen je Phase)\n";
serve('{"StatusSNS":{"ENERGY":{"TotalStartTime":"x","Total":[10.0,20.0,30.0],"Power":[100,200,-50],"Voltage":[230,231,229],"Current":[0.5,1.0,0.25]}}}');
$h = run($drv, $cl, new FakeHub([], ['GroupElectrical', 'GroupPowerPhase', 'GroupVoltagePhase', 'GroupCurrentPhase']));
check('2a: Leistung = Summe der Phasen', ($h->v['power_total'] ?? null) === 250.0);
check('2b: Leistung je Phase inkl. Vorzeichen', ($h->v['p_l1'] ?? 0) === 100.0 && ($h->v['p_l3'] ?? 0) === -50.0);
check('2c: Bezug = Summe der Zähler', ($h->v['energy_import'] ?? null) === 60.0);
check('2d: Spannung je Phase und Mittelwert', ($h->v['u_l2_n'] ?? 0) === 231.0 && abs(($h->v['voltage_avg'] ?? 0) - 230.0) < 1e-9);
check('2e: Strom je Phase', ($h->v['i_l3'] ?? 0) === 0.25);

// 3) IR-Lesekopf mit SML-Skript ---------------------------------------------------------------------
echo "3) IR-Lesekopf (SML-Skript)\n";
serve('{"StatusSNS":{"Time":"x","SML":{"Total_in":4711.1234,"Total_out":12.5,"Power_curr":-320}}}');
$h = run($drv, $cl, new FakeHub());
check('3a: Sensor „SML“ ohne Namensangabe gefunden', ($h->v['connected'] ?? null) === true && ($h->v['power_total'] ?? null) === -320.0);
check('3b: Total_in/Total_out als Bezug/Einspeisung', ($h->v['energy_import'] ?? null) === 4711.1234 && ($h->v['energy_export'] ?? null) === 12.5);
$h = run($drv, $cl, new FakeHub(['TasmotaEnergyUnit' => 'wh']));
check('3c: Einheit „Wh“ wird bei SML-Werten durch 1000 geteilt', ($h->v['energy_import'] ?? null) === 4.711123 && ($h->v['energy_export'] ?? null) === 0.0125);
serve('{"StatusSNS":{"Strom":{"Zaehler_Bezug":99.5,"Leistung":410},"ENERGY":{"Total":1,"Power":2}}}');
$h = run($drv, $cl, new FakeHub(['TasmotaSensor' => 'strom', 'TasmotaFieldPower' => 'leistung', 'TasmotaFieldImport' => 'Zaehler_Bezug']));
check('3d: Sensor- und Feldnamen aus den Einstellungen (Groß-/Kleinschreibung egal)', ($h->v['power_total'] ?? null) === 410.0 && ($h->v['energy_import'] ?? null) === 99.5);
$h = run($drv, $cl, new FakeHub(['TasmotaSensor' => 'gibtsnicht']));
check('3e: unbekannter Sensorname → nicht verbunden, nichts erfunden', ($h->v['connected'] ?? null) === false && !isset($h->v['power_total']));

// 4) Fehlerfälle ------------------------------------------------------------------------------------
echo "4) Fehlerfälle\n";
serve('{"WARNING":"Need user=<username>&password=<password>"}', 200);
$c = new MHUB_TasmotaClient('127.0.0.1', $port, 3.0);
check('4a: Web-Passwort (keine StatusSNS) → null mit Hinweistext', $c->getSensors() === null && str_contains($c->getLastError(), 'Web-Passwort'), $c->getLastError());
$h = run($drv, $c, new FakeHub());
check('4b: Treiber meldet „nicht verbunden“', ($h->v['connected'] ?? null) === false);
serve('<html>no</html>');
check('4c: kein JSON → Hinweistext', $c->getSensors() === null && str_contains($c->getLastError(), 'kein gültiges JSON'), $c->getLastError());
serve('{}', 500);
check('4d: HTTP-Status ≠ 200 → Status im Text', $c->getSensors() === null && str_contains($c->getLastError(), '500'), $c->getLastError());
$dead = new MHUB_TasmotaClient('127.0.0.1', 1, 2.0);
check('4e: nicht erreichbar → Netzwerkfehler', $dead->getSensors() === null && str_contains($dead->getLastError(), 'Netzwerkfehler'), $dead->getLastError());
foreach (['', 'a b', 'x/../y', "h;rm", 'ho st'] as $bad) {
    $b = new MHUB_TasmotaClient($bad, $port);
    check('4f: ungültiger Host „' . $bad . '“ wird abgelehnt, ohne Aufruf', $b->getSensors() === null && $b->getLastError() !== '');
}
serve('{"StatusSNS":{"Time":"x","Wifi":{"RSSI":50}}}');
$h = run($drv, new MHUB_TasmotaClient('127.0.0.1', $port, 3.0), new FakeHub());
check('4g: Sensor ohne Energiefelder → nicht verbunden', ($h->v['connected'] ?? null) === false);

// 5) Registrierung im Hauptmodul --------------------------------------------------------------------
echo "5) Registrierung\n";
$src = file_get_contents(dirname(__DIR__) . '/MeterHub/module.php');
check('5a: DRIVERS-Eintrag', preg_match("/'tasmota'\s*=>\s*'MHUB_TasmotaDriver'/", $src) === 1);
check('5b: als HTTP-Zähler geführt', preg_match("/const HTTP_METERS\s*=\s*\['tasmota'\]/", $src) === 1);
check('5c: Zählertyp ist als experimentell beschriftet', preg_match("/'tasmota'\s*=>\s*'Tasmota \(HTTP, experimental\)'/", $src) === 1);
check('5d: Treiber ist reiner Leser (kein Schreibaufruf im Client)', (function () use ($src) { $a = strpos($src, 'class MHUB_TasmotaClient'); $b = strpos($src, 'class MHUB_TasmotaDriver'); $c = substr($src, $a, $b - $a); return !preg_match('/function write|cmnd=Power|Backlog|POST/', $c); })());
check('5e: kein Passwort-Feld in v1', !preg_match("/'name' => 'Tasmota(Password|User)/", $src));

echo $fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
