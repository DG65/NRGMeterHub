<?php
/**
 * Prüfstand MHUB_EastronSdmSinglePhaseDriver (Eastron SDM120 / SDM220 / SDM230, 0.31.0).
 *
 *   php .tools/test-eastron-single-phase.php    # 0 = alle Prüfungen bestanden
 *
 * Der Treiber läuft über den echten MHUB_ModbusGatewayClient gegen eine
 * nachgebaute Registertabelle (FC 0x04, Float32 Big-Endian): geprüft werden
 * damit die gesendeten Anfragen (Funktion, Adresse, Länge), die Dekodierung
 * und das Verhalten bei fehlenden Antworten. Die Registerkarte selbst stammt
 * aus nmakel/sdm_modbus, evcc und volkszaehler/mbmd gegengelesen; ein Forum-Tester
 * hat am 20.09.2026 bestätigt, dass SDM120, SDM220 und SDM230 an echter Hardware funktionieren.
 */

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        public $InstanceID = 0;
        public function __construct($id = 0) { $this->InstanceID = $id; }
    }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}
if (!function_exists('IPS_LogMessage')) { function IPS_LogMessage($s, $m) {} }
require_once dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    echo ($cond ? '  ok    ' : '  FEHLT ') . $label . ($cond || $detail === '' ? '' : "  ($detail)") . "\n";
    if (!$cond) { $fails++; }
}

/** Minimaler Hub-Ersatz: merkt sich, was der Treiber schreibt. */
class FakeHub
{
    public array $floats = [];
    public array $energy = [];
    public array $bools = [];
    public array $groups = [];
    public function SetVarFloat($i, $v) { $this->floats[$i] = $v; }
    public function SetVarEnergykWh($i, $v) { $this->energy[$i] = $v; }
    public function SetVarBool($i, $v) { $this->bools[$i] = $v; }
    public function GroupActive($g) { return $this->groups[$g] ?? false; }
}

// Registertabelle: Wire-Adresse => Float. Gesendet wird FC 0x04 mit je 2 Registern.
$table = [0 => 230.5, 6 => 4.25, 12 => 980.0, 18 => 1000.0, 24 => 200.0, 30 => 0.98, 70 => 50.01, 72 => 1234.5, 74 => 67.8];
$requests = [];
$drop = [];   // Adressen, die nicht antworten
$client = function () use (&$table, &$requests, &$drop) {
    return new MHUB_ModbusGatewayClient(1, function (string $json) use (&$table, &$requests, &$drop): string {
        $req = json_decode($json, true);
        $requests[] = $req;
        if ($req['Function'] !== 4 || $req['Quantity'] !== 2 || !isset($table[$req['Address']]) || in_array($req['Address'], $drop, true)) {
            return '';
        }
        return chr(4) . chr(4) . pack('G', $table[$req['Address']]) ; // Function, Byte Count, 4 Byte Big-Endian
    });
};
// pack('G') liefert IEEE754 Big-Endian als 4 Byte — dieselbe Lage wie zwei Register (Hi, Lo).

echo "1) Registrierung\n";
$drivers = (new ReflectionClassConstant('MeterHub', 'DRIVERS'))->getValue();
$labels = (new ReflectionClassConstant('MeterHub', 'METER_LABELS'))->getValue();
foreach (['eastron_sdm120' => 'SDM120', 'eastron_sdm220' => 'SDM220', 'eastron_sdm230' => 'SDM230'] as $key => $name) {
    check("1: $name ist registriert (Treiber und Anzeigename)", ($drivers[$key] ?? '') === 'MHUB_EastronSdmSinglePhaseDriver' && ($labels[$key] ?? '') === "Eastron $name");
}
check('1: der dreiphasige Treiber bleibt für SDM72D/SDM630 unverändert', $drivers['eastron_sdm630'] === 'MHUB_EastronSdmDriver' && $drivers['eastron_sdm72d'] === 'MHUB_EastronSdmDriver');

echo "\n2) readFast(): Anfragen und Dekodierung\n";
$drv = new MHUB_EastronSdmSinglePhaseDriver();
$mb = $client();
$hub = new FakeHub();
$ok = $drv->readFast($mb, $hub);
check('2a: Lesen gelingt, „connected" wird gesetzt', $ok === true && $hub->bools['connected'] === true);
check('2a: Wirkleistung 980 W aus Register 12', abs($hub->floats['power_total'] - 980.0) < 1e-3, json_encode($hub->floats));
check('2a: Spannung 230,5 V aus Register 0', abs($hub->floats['voltage_avg'] - 230.5) < 1e-3);
check('2a: Strom 4,25 A aus Register 6', abs($hub->floats['current_avg'] - 4.25) < 1e-3);
check('2a: Frequenz 50,01 Hz aus Register 70', abs($hub->floats['frequency'] - 50.01) < 1e-3);
check('2a: ohne aktivierte Gruppen keine Blind-/Schein-/Leistungsfaktor-Werte', !isset($hub->floats['s_total']) && !isset($hub->floats['q_total']) && !isset($hub->floats['pf_total']));
$addr = array_column($requests, 'Address');
check('2b: jede Größe einzeln gelesen (FC 4, je 2 Register) — keine Blocklesung über Lücken', $addr === [12, 0, 6, 70] && count(array_unique(array_column($requests, 'Function'))) === 1 && $requests[0]['Function'] === 4 && array_unique(array_column($requests, 'Quantity')) === [2], json_encode($addr));

$requests = [];
$hub = new FakeHub();
$hub->groups = ['GroupReactiveApparent' => true, 'GroupPowerFactor' => true];
$drv->readFast($client(), $hub);
check('2c: mit aktivierten Gruppen: Scheinleistung 18, Blindleistung 24, Leistungsfaktor 30', abs($hub->floats['s_total'] - 1000.0) < 1e-3 && abs($hub->floats['q_total'] - 200.0) < 1e-3 && abs($hub->floats['pf_total'] - 0.98) < 1e-3, json_encode($hub->floats));

$table[12] = -1500.0; // Einspeisung
$hub = new FakeHub();
$drv->readFast($client(), $hub);
check('2d: Einspeisung bleibt negativ (+ = Bezug, wie beim SDM630)', abs($hub->floats['power_total'] + 1500.0) < 1e-3);
$table[12] = 980.0;

echo "\n3) Fehlerfälle\n";
$drop = [12];
$hub = new FakeHub();
check('3a: keine Antwort auf die Wirkleistung -> readFast false, „connected" false', $drv->readFast($client(), $hub) === false && $hub->bools['connected'] === false && !isset($hub->floats['power_total']));
$drop = [0, 6];
$hub = new FakeHub();
$ok = $drv->readFast($client(), $hub);
check('3b: fehlen nur Spannung/Strom, bleibt die Verbindung gültig und die Leistung kommt an', $ok === true && isset($hub->floats['power_total']) && !isset($hub->floats['voltage_avg']) && !isset($hub->floats['current_avg']));
$drop = [];

echo "\n4) readSlow(): Energie\n";
$requests = [];
$hub = new FakeHub();
$drv->readSlow($client(), $hub);
check('4a: Bezug 1234,5 kWh (Register 72), Abgabe 67,8 kWh (Register 74)', abs($hub->energy['energy_import'] - 1234.5) < 1e-3 && abs($hub->energy['energy_export'] - 67.8) < 1e-3, json_encode($hub->energy));
check('4a: zwei getrennte Abrufe', array_column($requests, 'Address') === [72, 74]);
$drop = [74];
$hub = new FakeHub();
$drv->readSlow($client(), $hub);
check('4b: fehlt die Abgabe, kommt der Bezug trotzdem an', isset($hub->energy['energy_import']) && !isset($hub->energy['energy_export']));
$drop = [];

echo "\n5) Grunddaten des Treibers\n";
$idents = array_column($drv->getBaseVars(), 0);
check('5a: dieselben Idents wie die anderen Zähler (power_total, energy_import/export, frequency …)', array_diff(['power_total', 'energy_import', 'energy_export', 'frequency', 'voltage_avg', 'current_avg', 'connected'], $idents) === []);
$groups = $drv->getOptionalGroups();
check('5b: nur die zwei Gruppen, die ein einphasiges Gerät hat', array_keys($groups) === ['GroupReactiveApparent', 'GroupPowerFactor']);
check('5c: Energie ist archivierter Zählerstand (Gruppe „energy")', array_values(array_filter($drv->getBaseVars(), fn($v) => $v[0] === 'energy_import'))[0][5] === 'energy');

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
