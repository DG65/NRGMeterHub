<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Prüfstand Shelly über MQTT (0.37.0, experimentell): MeterHubMqttBridge + MHUB_ShellyMqttClient/-Driver.
 *
 *   php .tools/test-shelly-mqtt.php    # 0 = alle Prüfungen bestanden
 *
 * Die Nachrichten sind Beispiele nach der Shelly-Dokumentation (NotifyStatus mit nur geänderten Feldern,
 * NotifyFullStatus, status/<Komponente>, online) — NICHT von einem echten Gerät aufgezeichnet.
 */
const VARIABLETYPE_BOOLEAN = 0; const VARIABLETYPE_INTEGER = 1; const VARIABLETYPE_FLOAT = 2; const VARIABLETYPE_STRING = 3;
const KR_READY = 10103;

class IPSModule
{
    use I18nStub;
    public $InstanceID;
    public $filter = '';
    public $status = 0;
    public function __construct($id = 0) { $this->InstanceID = $id; }
    public function Create() {}
    public function ApplyChanges() {}
    public function SetReceiveDataFilter($f) { $this->filter = $f; }
    public function SetStatus($s) { $this->status = $s; }
    public function SendDebug($a, $b, $c) {}
    public function GetBuffer($k) { return $GLOBALS['BUF'][$this->InstanceID][$k] ?? ''; }
    public function SetBuffer($k, $v) { $GLOBALS['BUF'][$this->InstanceID][$k] = $v; }
}
$GLOBALS['BUF'] = []; $GLOBALS['INST'] = []; $GLOBALS['PROPS'] = [];
function IPS_GetInstance($id) { return $GLOBALS['INST'][$id]; }
function IPS_InstanceExists($id) { return isset($GLOBALS['INST'][$id]); }
function IPS_GetInstanceListByModuleID($g) { return array_keys(array_filter($GLOBALS['INST'], fn($i) => ($i['module'] ?? '') === $g)); }
function IPS_GetProperty($id, $n) { return $GLOBALS['PROPS'][$id][$n] ?? null; }
function IPS_GetName($id) { return 'Instanz ' . $id; }
function IPS_GetKernelDate() { return time(); }
function IPS_SemaphoreEnter($n, $t) { return true; }
function IPS_SemaphoreLeave($n) { return true; }

require_once dirname(__DIR__) . '/MeterHub/module.php';
require_once dirname(__DIR__) . '/MeterHubMqttBridge/module.php';
MHUB_Text::set(function (string $t): string { return i18n_tr($t); });

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

// Brücke #700 hängt am aktiven MQTT-Server #701; Hub #800 (Shelly MQTT) und #801 (anderer Zählertyp) zeigen auf sie
$HUBGUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
$GLOBALS['INST'][700] = ['module' => '{EF16A82F-4B66-4722-91C7-CB9518E2F807}', 'ConnectionID' => 701, 'InstanceStatus' => 102];
$GLOBALS['INST'][701] = ['module' => 'mqtt', 'ConnectionID' => 0, 'InstanceStatus' => 102];
$GLOBALS['INST'][800] = ['module' => $HUBGUID, 'ConnectionID' => 0, 'InstanceStatus' => 102];
$GLOBALS['INST'][801] = ['module' => $HUBGUID, 'ConnectionID' => 0, 'InstanceStatus' => 102];
$GLOBALS['PROPS'][800] = ['Meter' => 'shelly_mqtt', 'MqttBridgeID' => 700, 'MqttTopic' => ' shellypro3em-aabbcc/ ', 'MqttChannel' => 0];
$GLOBALS['PROPS'][801] = ['Meter' => 'siemens_pac2200', 'MqttBridgeID' => 700, 'MqttTopic' => 'darf-nicht-gelten'];
$bridge = new MeterHubMqttBridge(700);
function MHUBM_GetDevice($id, $topic) { global $bridge; return $bridge->GetDevice($topic); }
function MHUBM_GetState($id) { global $bridge; return $bridge->GetState(); }

function rx(string $topic, string $payload): void
{
    global $bridge;
    $bridge->ReceiveData(json_encode(['DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0, 'Retain' => false, 'Topic' => $topic, 'Payload' => mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1')]));   // so liefert der Kernel: Rohbytes als Latin-1, UTF-8-kodiert
}
function notify(array $params, string $method = 'NotifyStatus'): string
{
    return json_encode(['src' => 'shellypro3em-aabbcc', 'dst' => 'shellypro3em-aabbcc/events', 'method' => $method, 'params' => ['ts' => 1760000000.5] + $params]);
}
function dev(): array { return json_decode((string)MHUBM_GetDevice(700, 'shellypro3em-aabbcc'), true); }

// 1) Filter und Zuordnung --------------------------------------------------------------------------
echo "1) Empfangsfilter und Zuordnung\n";
$bridge->Refresh();
check('1a: Filter enthält das bereinigte Topic und die drei Unterthemen', str_contains($bridge->filter, 'shellypro3em\\-aabbcc') && str_contains($bridge->filter, 'events') && str_contains($bridge->filter, 'online') && str_contains($bridge->filter, 'status'), $bridge->filter);
check('1b: Filter ist ein gültiger regulärer Ausdruck und trifft maskierte wie unmaskierte Schrägstriche', (function () use ($bridge) {
    $re = '/' . str_replace('/', '\/', $bridge->filter) . '/';
    return @preg_match($re, '{"Topic":"shellypro3em-aabbcc\/events\/rpc","Payload":"x"}') === 1 && @preg_match($re, '{"Topic":"shellypro3em-aabbcc/events/rpc","Payload":"x"}') === 1
        && @preg_match($re, '{"Topic":"shellypro3em-aabbcc/status/em:0","Payload":"x"}') === 1 && @preg_match($re, '{"Topic":"shellypro3em-aabbcc/online","Payload":"true"}') === 1;
})(), $bridge->filter);
check('1c: fremde Topics, Befehlsthemen und Präfix-Verwandte werden nicht durchgelassen', (function () use ($bridge) {
    $re = '/' . str_replace('/', '\/', $bridge->filter) . '/';
    return preg_match($re, '{"Topic":"anderes-geraet\/events\/rpc","Payload":"x"}') === 0 && preg_match($re, '{"Topic":"shellypro3em-aabbcc\/command","Payload":"x"}') === 0 && preg_match($re, '{"Topic":"xshellypro3em-aabbcc\/events\/rpc","Payload":"x"}') === 0;
})(), $bridge->filter);
check('1d: nur Instanzen mit Zählertyp Shelly (MQTT) sind zugeordnet', json_decode($bridge->GetState(), true)['hubs'] === 1);
$GLOBALS['PROPS'][800]['MqttTopic'] = 'a+b';
$bridge->Refresh();
check('1e: Wildcards im Topic werden verworfen, dann wird nichts empfangen', $bridge->filter === '(?!)');
$GLOBALS['PROPS'][800]['MqttTopic'] = ' shellypro3em-aabbcc/ ';
$bridge->Refresh();

// 2) Vor der ersten Nachricht und Fehlerzustände -------------------------------------------------------
echo "2) Zustände ohne Daten\n";
check('2a: noch keine Nachricht → no_message', dev()['error'] === 'no_message');
check('2b: leeres Topic → no_topic', json_decode((string)MHUBM_GetDevice(700, '  / '), true)['error'] === 'no_topic');
$GLOBALS['INST'][701]['InstanceStatus'] = 104;
check('2c: MQTT-Server inaktiv → parent_inactive', dev()['error'] === 'parent_inactive');
$GLOBALS['INST'][701]['InstanceStatus'] = 102; $GLOBALS['INST'][700]['ConnectionID'] = 0;
check('2d: ohne Server → not_connected', dev()['error'] === 'not_connected');
$GLOBALS['INST'][700]['ConnectionID'] = 701;

// 3) Pro 3EM: Änderungsmeldungen werden zusammengeführt ------------------------------------------------
echo "3) Pro 3EM (em + emdata)\n";
rx('shellypro3em-aabbcc/events/rpc', notify(['em:0' => ['id' => 0, 'a_act_power' => 100.5, 'b_act_power' => 200.0, 'c_act_power' => -50.0, 'total_act_power' => 250.5, 'a_voltage' => 230.0, 'b_voltage' => 231.0, 'c_voltage' => 229.0, 'a_current' => 0.5, 'b_current' => 1.0, 'c_current' => 0.25, 'total_current' => 1.75]], 'NotifyFullStatus'));
rx('shellypro3em-aabbcc/events/rpc', notify(['em:0' => ['total_act_power' => 300.0, 'a_act_power' => 150.5], 'emdata:0' => ['id' => 0, 'total_act' => 1234567.0, 'total_act_ret' => 8900.0]]));
$d = dev();
check('3a: Antwort ok, Alter klein', $d['ok'] === true && $d['age'] <= 2, json_encode($d));
check('3b: geänderte Felder überschreiben, ungeänderte bleiben erhalten', $d['components']['em:0']['total_act_power'] == 300.0 && $d['components']['em:0']['a_act_power'] == 150.5 && $d['components']['em:0']['b_act_power'] == 200.0);
check('3c: emdata kommt als eigene Komponente', $d['components']['emdata:0']['total_act'] == 1234567.0);
check('3d: ts wird nicht als Komponente gespeichert', !isset($d['components']['ts']));
rx('shellypro3em-aabbcc/events/rpc', notify(['em:0' => ['errors' => ['out_of_range:voltage'], 'a_voltage' => null]]));
$d = dev();
check('3e: null löscht ein Feld, Listen werden ersetzt', !isset($d['components']['em:0']['a_voltage']) && $d['components']['em:0']['errors'] === ['out_of_range:voltage']);
rx('shellypro3em-aabbcc/events/rpc', notify(['em:0' => ['errors' => []]]));
check('3f: leere Liste ersetzt die alte Liste', dev()['components']['em:0']['errors'] === []);
rx('shellypro3em-aabbcc/events/rpc', notify(['em:0' => ['a_voltage' => 230.0]], 'NotifyEvent'));
rx('shellypro3em-aabbcc/events/rpc', 'kein json');
rx('shellypro3em-aabbcc/command', '{"x":1}');
rx('anderes/events/rpc', notify(['em:0' => ['total_act_power' => 99999.0]]));
check('3g: NotifyEvent, kaputtes JSON und fremde Topics ändern nichts', dev()['components']['em:0']['total_act_power'] == 300.0 && !isset(dev()['components']['em:0']['a_voltage']));
rx('shellypro3em-aabbcc/status/em:0', json_encode(['id' => 0, 'total_act_power' => 42.0]));
check('3h: status/<Komponente> ersetzt den Merker vollständig', dev()['components']['em:0'] == ['id' => 0, 'total_act_power' => 42.0]);
rx('shellypro3em-aabbcc/online', 'false');
check('3i: online=false wird gemeldet', dev()['online'] === false);
rx('shellypro3em-aabbcc/online', 'true');
check('3j: online=true', dev()['online'] === true);
// Utf8-Weg: Payload mit Umlaut im Gerätenamen darf nicht kaputtgehen
rx('shellypro3em-aabbcc/events/rpc', notify(['sys' => ['device' => ['name' => 'Küche']]]));
check('3k: Umlaute in der Payload bleiben erhalten', (dev()['components']['sys']['device']['name'] ?? '') === 'Küche');
// Wiederherstellen für die Treiberprüfung
rx('shellypro3em-aabbcc/events/rpc', notify(['em:0' => ['total_act_power' => 300.0, 'a_act_power' => 150.5, 'b_act_power' => 200.0, 'c_act_power' => -50.0, 'a_voltage' => 230.0, 'b_voltage' => 231.0, 'c_voltage' => 229.0, 'a_current' => 0.5, 'b_current' => 1.0, 'c_current' => 0.25]]));

// 4) Treiber ---------------------------------------------------------------------------------------
class FakeHub
{
    public $v = []; public $groups = [];
    public function __construct(array $groups = []) { $this->groups = $groups; }
    public function GroupActive(string $g): bool { return in_array($g, $this->groups, true); }
    public function SetVarFloat($id, $x) { $this->v[$id] = (float)$x; }
    public function SetVarBool($id, $x) { $this->v[$id] = (bool)$x; }
    public function SetVarEnergykWh($id, $x) { $this->v[$id] = round((float)$x, 6); }
}
function runShelly(MHUB_ShellyMqttClient $c, FakeHub $h): FakeHub { $d = new MHUB_ShellyMqttDriver(); $d->readFast($c, $h); $d->readSlow($c, $h); return $h; }
echo "4) Treiber: Pro 3EM\n";
$c = new MHUB_ShellyMqttClient(700, ' shellypro3em-aabbcc/ ', 0);
$h = runShelly($c, new FakeHub(['GroupElectrical', 'GroupPowerPhase', 'GroupVoltagePhase', 'GroupCurrentPhase']));
check('4a: Gesamtleistung aus total_act_power (+ = Bezug)', ($h->v['power_total'] ?? null) === 300.0, json_encode($h->v));
check('4b: Bezug/Abgabe aus emdata in kWh', ($h->v['energy_import'] ?? null) === 1234.567 && ($h->v['energy_export'] ?? null) === 8.9);
check('4c: Leistung je Phase mit Vorzeichen', ($h->v['p_l1'] ?? null) === 150.5 && ($h->v['p_l3'] ?? null) === -50.0);
check('4d: Spannung/Strom je Phase und Mittelwerte', ($h->v['u_l2_n'] ?? null) === 231.0 && ($h->v['i_l3'] ?? null) === 0.25 && abs(($h->v['voltage_avg'] ?? 0) - 230.0) < 1e-9 && abs(($h->v['current_avg'] ?? 0) - 0.58333333) < 1e-6);
check('4e: verbunden', ($h->v['connected'] ?? null) === true);
$h = runShelly($c, new FakeHub());
check('4f: ohne aktive Gruppen nur die Kernwerte', !isset($h->v['p_l1']) && !isset($h->v['voltage_avg']) && isset($h->v['power_total']));

echo "5) Treiber: Einkanal-Geräte und Fehler\n";
function setupDevice(string $topic, array $components): void
{
    global $bridge; $GLOBALS['BUF'][700] = [];
    $GLOBALS['PROPS'][800]['MqttTopic'] = $topic;
    rx($topic . '/events/rpc', json_encode(['src' => $topic, 'method' => 'NotifyFullStatus', 'params' => ['ts' => 1.0] + $components]));
}
setupDevice('shellyplusplugs-112233', ['switch:0' => ['id' => 0, 'output' => true, 'apower' => 61.5, 'voltage' => 231.2, 'current' => 0.27, 'aenergy' => ['total' => 5432.1, 'by_minute' => [1, 2, 3], 'minute_ts' => 1760000000], 'ret_aenergy' => ['total' => 12.0]]]);
$GLOBALS['PROPS'][800]['MqttTopic'] = 'shellyplusplugs-112233';
$h = runShelly(new MHUB_ShellyMqttClient(700, 'shellyplusplugs-112233', 0), new FakeHub(['GroupElectrical']));
check('5a: Steckdose (switch:0): apower, Spannung, Strom, aenergy.total in kWh', ($h->v['power_total'] ?? null) === 61.5 && ($h->v['voltage_avg'] ?? null) === 231.2 && ($h->v['current_avg'] ?? null) === 0.27 && ($h->v['energy_import'] ?? null) === 5.4321 && ($h->v['energy_export'] ?? null) === 0.012, json_encode($h->v));
setupDevice('shellypro1pm-9', ['pm1:0' => ['apower' => 10.0, 'aenergy' => ['total' => 1000.0]]]);
$h = runShelly(new MHUB_ShellyMqttClient(700, 'shellypro1pm-9', 0), new FakeHub());
check('5b: pm1:0 wird erkannt', ($h->v['power_total'] ?? null) === 10.0 && ($h->v['energy_import'] ?? null) === 1.0 && !isset($h->v['energy_export']));
setupDevice('shellyproem50-7', ['em1:0' => ['act_power' => -420.0, 'voltage' => 229.0, 'current' => 1.9], 'em1:1' => ['act_power' => 5.0], 'em1data:0' => ['total_act_energy' => 2000.0, 'total_act_ret_energy' => 3500.0], 'em1data:1' => ['total_act_energy' => 7.0]]);
$h = runShelly(new MHUB_ShellyMqttClient(700, 'shellyproem50-7', 0), new FakeHub());
check('5c: EM1 Kanal 0: Einspeisung negativ, Energie aus em1data', ($h->v['power_total'] ?? null) === -420.0 && ($h->v['energy_import'] ?? null) === 2.0 && ($h->v['energy_export'] ?? null) === 3.5);
$h = runShelly(new MHUB_ShellyMqttClient(700, 'shellyproem50-7', 1), new FakeHub());
check('5d: EM1 Kanal 1 liest em1:1 und em1data:1', ($h->v['power_total'] ?? null) === 5.0 && ($h->v['energy_import'] ?? null) === 0.007);
$c = new MHUB_ShellyMqttClient(700, 'shellyproem50-7', 5);
$h = runShelly($c, new FakeHub());
check('5e: Kanal ohne Zähler → nicht verbunden mit erklärendem Text', ($h->v['connected'] ?? null) === false && str_contains($c->getLastError(), 'Kanal 5'), $c->getLastError());
$c = new MHUB_ShellyMqttClient(700, 'unbekannt-1', 0);
check('5f: kein Topic bekannt → Fehlertext „Noch keine Nachricht“', $c->getDevice() === null && str_contains($c->getLastError(), 'Noch keine Nachricht'), $c->getLastError());
$c = new MHUB_ShellyMqttClient(0, 'x', 0);
check('5g: keine Brücke gewählt', $c->getDevice() === null && str_contains($c->getLastError(), 'Keine MQTT-Brücke'));
$c = new MHUB_ShellyMqttClient(999, 'x', 0);
check('5h: Brücke existiert nicht', $c->getDevice() === null && str_contains($c->getLastError(), 'gibt es nicht'));
$c = new MHUB_ShellyMqttClient(700, 'x', 0);
$GLOBALS['INST'][700]['ConnectionID'] = 0;
check('5i: Brücke ohne MQTT-Server', $c->getDevice() === null && str_contains($c->getLastError(), 'mit keinem MQTT-Server'));
$GLOBALS['INST'][700]['ConnectionID'] = 701;
setupDevice('shellyplusplugs-112233', ['switch:0' => ['apower' => 1.0]]);
rx('shellyplusplugs-112233/online', 'false');
$c = new MHUB_ShellyMqttClient(700, 'shellyplusplugs-112233', 0);
$h = runShelly($c, new FakeHub());
check('5j: Gerät meldet sich offline (Last Will) → nicht verbunden', ($h->v['connected'] ?? null) === false && str_contains($c->getLastError(), 'offline'));
rx('shellyplusplugs-112233/online', 'true');
$st = json_decode($GLOBALS['BUF'][700]['dev_' . md5('shellyplusplugs-112233')], true);
$st['rx'] = time() - 2000;
$GLOBALS['BUF'][700]['dev_' . md5('shellyplusplugs-112233')] = json_encode($st);
$h = runShelly($c, new FakeHub());
check('5k: seit über 15 Minuten keine Nachricht → nicht verbunden, Alter im Text', ($h->v['connected'] ?? null) === false && str_contains($c->getLastError(), '20'), $c->getLastError());

// 5l) Echte Nachricht eines Shelly Plus Plug S (Dirk, 15.11.2026, erste Messung an echter Hardware)
$realTopic = 'shellyplusplugs-e465b841a270';
$realPayload = '{"src":"shellyplusplugs-e465b841a270","dst":"shellyplusplugs-e465b841a270/events","method":"NotifyFullStatus","params":{"ts":2.34,"ble":{},"cloud":{"connected":false},"mqtt":{"connected":true},"plugs_ui":{},"switch:0":{"id":0, "source":"init", "output":true, "apower":46.2, "voltage":238.2, "aenergy":{"total":197130.605},"temperature":{"tC":30.2, "tF":86.3}},"sys":{"mac":"E465B841A270","restart_required":false,"time":null,"unixtime":null,"uptime":2,"ram_size":245964,"ram_free":149592,"fs_size":458752,"fs_free":147456,"cfg_rev":14,"kvs_rev":0,"schedule_rev":0,"webhook_rev":0,"available_updates":{},"reset_reason":3},"wifi":{"sta_ip":"192.168.1.137","status":"got ip","ssid":"xxx","rssi":-42},"ws":{"connected":false}}}';
$GLOBALS['BUF'][700] = [];
$GLOBALS['PROPS'][800]['MqttTopic'] = $realTopic;
rx($realTopic . '/events/rpc', $realPayload);
$h = runShelly(new MHUB_ShellyMqttClient(700, $realTopic, 0), new FakeHub(['GroupElectrical']));
check('5l: echter Plus Plug S: apower 46,2 W, Spannung 238,2 V, aenergy.total 197130,605 Wh = 197,130605 kWh', ($h->v['power_total'] ?? null) === 46.2 && ($h->v['voltage_avg'] ?? null) === 238.2 && abs(($h->v['energy_import'] ?? 0) - 197.130605) < 1e-9 && ($h->v['connected'] ?? null) === true, json_encode($h->v));
// Danach nur Änderungen (NotifyStatus): Leistung wandert, der Zählerstand bleibt aus der Vollmeldung erhalten
rx($realTopic . '/events/rpc', json_encode(['src' => $realTopic, 'method' => 'NotifyStatus', 'params' => ['ts' => 60.0, 'switch:0' => ['apower' => 47.9]]]));
$h = runShelly(new MHUB_ShellyMqttClient(700, $realTopic, 0), new FakeHub(['GroupElectrical']));
check('5m: NotifyStatus mit nur apower: neue Leistung, Spannung und Zählerstand bleiben', ($h->v['power_total'] ?? null) === 47.9 && ($h->v['voltage_avg'] ?? null) === 238.2 && abs(($h->v['energy_import'] ?? 0) - 197.130605) < 1e-9, json_encode($h->v));
// Beginnt die Brücke ohne Vollmeldung (Symcon neu gestartet, Shelly lief weiter): nur Änderungen vorhanden
$GLOBALS['BUF'][700] = [];
rx($realTopic . '/events/rpc', json_encode(['src' => $realTopic, 'method' => 'NotifyStatus', 'params' => ['ts' => 120.0, 'switch:0' => ['apower' => 48.1]]]));
$h = runShelly(new MHUB_ShellyMqttClient(700, $realTopic, 0), new FakeHub(['GroupElectrical']));
check('5n: ohne Vollmeldung (nur NotifyStatus): Leistung kommt trotzdem, Zählerstand erst mit der nächsten Meldung', ($h->v['power_total'] ?? null) === 48.1 && !isset($h->v['energy_import']), json_encode($h->v));

echo "6) Hub: Registrierung, Formular, Statuszeile\n";
$src = file_get_contents(dirname(__DIR__) . '/MeterHub/module.php');
check('6a: DRIVERS/Beschriftung/MQTT_METERS', preg_match("/'shelly_mqtt'\s*=>\s*'MHUB_ShellyMqttDriver'/", $src) === 1 && preg_match("/'shelly_mqtt'\s*=>\s*'Shelly \(MQTT, experimental\)'/", $src) === 1 && preg_match("/const MQTT_METERS\s*=\s*\['shelly_mqtt'\]/", $src) === 1);
check('6b: Treiber und Client veröffentlichen nichts (kein Publish/PacketType im Shelly-Code)', (function () use ($src) { $a = strpos($src, 'class MHUB_ShellyMqttClient'); $b = strpos($src, '// MHUB_BlueLogScadaInverterDriver'); $c = substr($src, $a, $b - $a); return !preg_match('/Publish|PacketType|SendDataToParent/', $c); })());
check('6c: Brücke veröffentlicht nichts', !preg_match('/SendDataToParent|PacketType.{0,4}=>\s*3/', file_get_contents(dirname(__DIR__) . '/MeterHubMqttBridge/module.php')));
$form = json_decode((new MeterHubMqttBridge(700))->GetConfigurationForm(), true);
check('6d: Brückenformular hat Statuszeile, Gerätetabelle und Doku', count($form['elements']) === 4 && $form['elements'][2]['type'] === 'List' && $form['elements'][3]['type'] === 'ExpansionPanel');

echo $fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
