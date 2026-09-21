<?php
/**
 * Prüfstand MeterHubBridge und Brückenanbindung im MeterHub (0.30.0, SUITE.md 9j).
 *
 *   php .tools/test-bridge.php    # 0 = alle Prüfungen bestanden
 *
 * Deckt ab: Forward()/GetState() der Brücke (alle Fehlerarten, Binärdaten wie
 * 0xFFFF unbeschadet über die Instanzgrenze), MeterHub::BridgeCall() bis zur
 * dekodierten Registerantwort im MHUB_ModbusGatewayClient, CreateBridge() (anlegen,
 * verbinden, wiederverwenden) und die module.json-Einträge beider Module.
 * Das Kernel-Verhalten selbst (Funktionsaufruf zwischen Instanzen, Konsole) bildet
 * der Prüfstand nicht nach — dort gilt nur der Bericht vom echten Gerät.
 */

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        public $InstanceID = 0;
        public function __construct($id = 0) { $this->InstanceID = $id; }
        public function Create() {}
        public function ApplyChanges() {}
        public function SetStatus($s) { $GLOBALS['STATUS'][$this->InstanceID] = $s; }
        public function UpdateFormField($f, $k, $v) { $GLOBALS['FORMFIELDS'][$f][$k] = $v; }
        protected function SendDataToParent($json)
        {
            $GLOBALS['SENT'][] = $json;
            $r = $GLOBALS['PARENT_RESPONSE'];
            if ($r instanceof \Throwable) { throw $r; }
            return $r;
        }
    }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}

const GW_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
const BRIDGE_GUID = '{39E4438F-FE02-4025-973E-318355C6DC82}';

// Kleines Objektmodell: id => ['module' => GUID, 'parent' => ConnectionID, 'status' => int, 'cfg' => array, 'name' => string, 'tree' => ParentID]
$GLOBALS['INST'] = [];
$GLOBALS['NEXT_ID'] = 900;
$GLOBALS['PARENT_RESPONSE'] = '';
$GLOBALS['SENT'] = [];
$GLOBALS['STATUS'] = [];
$GLOBALS['FORMFIELDS'] = [];

function IPS_InstanceExists($id) { return isset($GLOBALS['INST'][$id]); }
function IPS_GetInstance($id)
{
    $i = $GLOBALS['INST'][$id];
    return ['ConnectionID' => $i['parent'], 'InstanceStatus' => $i['status'], 'ModuleInfo' => ['ModuleID' => $i['module']]];
}
function IPS_GetConfiguration($id) { return json_encode($GLOBALS['INST'][$id]['cfg']); }
function IPS_GetName($id) { return $GLOBALS['INST'][$id]['name']; }
function IPS_GetObject($id) { return ['ParentID' => $GLOBALS['INST'][$id]['tree']]; }
function IPS_SetName($id, $n) { $GLOBALS['INST'][$id]['name'] = $n; }
function IPS_SetParent($id, $p) { $GLOBALS['INST'][$id]['tree'] = $p; }
function IPS_ConnectInstance($id, $p) { $GLOBALS['INST'][$id]['parent'] = $p; }
function IPS_ApplyChanges($id) { $GLOBALS['APPLIED'][] = $id; }
function IPS_CreateInstance($guid)
{
    $id = $GLOBALS['NEXT_ID']++;
    $GLOBALS['INST'][$id] = ['module' => $guid, 'parent' => 0, 'status' => 102, 'cfg' => [], 'name' => 'neu', 'tree' => 0];
    return $id;
}
function IPS_GetInstanceListByModuleID($guid)
{
    return array_keys(array_filter($GLOBALS['INST'], fn($i) => $i['module'] === $guid));
}
if (!function_exists('IPS_LogMessage')) { function IPS_LogMessage($s, $m) {} }

// Stellvertreter des vom Kernel bereitgestellten Prefix-Wrappers MHUBB_Forward($id, $json).
$GLOBALS['BRIDGES'] = [];
function MHUBB_Forward($id, $json) { return $GLOBALS['BRIDGES'][$id]->Forward($json); }
function MHUBB_GetState($id) { return $GLOBALS['BRIDGES'][$id]->GetState(); }

require_once dirname(__DIR__) . '/MeterHubBridge/module.php';
require_once dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    echo ($cond ? '  ok    ' : '  FEHLT ') . $label . ($cond || $detail === '' ? '' : "  ($detail)") . "\n";
    if (!$cond) { $fails++; }
}
function newInstance(string $module, int $parent = 0, int $status = 102, array $cfg = [], string $name = 'x'): int
{
    $id = IPS_CreateInstance($module);
    $GLOBALS['INST'][$id] = ['module' => $module, 'parent' => $parent, 'status' => $status, 'cfg' => $cfg, 'name' => $name, 'tree' => 0];
    return $id;
}

echo "1) Brücke: Forward() — Fehlerarten\n";
$gw = newInstance(GW_GUID, 0, 102, ['DeviceID' => 41], 'ModBus Gateway BCR PV Zähler');
$bridgeNo = newInstance(BRIDGE_GUID, 0);
$bNo = new MeterHubBridge($bridgeNo);
$r = json_decode($bNo->Forward('{}'), true);
check('1a: ohne Gateway -> not_connected', $r === ['ok' => false, 'error' => 'not_connected'], json_encode($r));
check('1a: es wurde nichts an einen Elternteil gesendet', ($GLOBALS['SENT'] ?? []) === []);

$gwDown = newInstance(GW_GUID, 0, 201, ['DeviceID' => 7]);
$bridgeDown = newInstance(BRIDGE_GUID, $gwDown);
$r = json_decode((new MeterHubBridge($bridgeDown))->Forward('{}'), true);
check('1b: Gateway nicht aktiv -> parent_inactive', $r === ['ok' => false, 'error' => 'parent_inactive'], json_encode($r));

$bridge = newInstance(BRIDGE_GUID, $gw);
$b = new MeterHubBridge($bridge);
$GLOBALS['BRIDGES'][$bridge] = $b;
$GLOBALS['PARENT_RESPONSE'] = '';
check('1c: leere Antwort -> no_response', json_decode($b->Forward('{}'), true) === ['ok' => false, 'error' => 'no_response']);
$GLOBALS['PARENT_RESPONSE'] = false;
check('1c: false -> no_response', json_decode($b->Forward('{}'), true) === ['ok' => false, 'error' => 'no_response']);
$GLOBALS['PARENT_RESPONSE'] = new RuntimeException('Timeout');
check('1c: Ausnahme im Gateway -> no_response, kein Absturz', json_decode($b->Forward('{}'), true) === ['ok' => false, 'error' => 'no_response']);

echo "\n2) Brücke: Binärdaten unbeschadet über die Instanzgrenze (bewusst NICHT-UTF-8: 0xFFFF, 0x8001)\n";
$raw = chr(3) . chr(6) . pack('n*', 0xFFFF, 0x8001, 0x0000);
check('2: Testdaten sind wirklich kein gültiges UTF-8', json_encode(['x' => $raw]) === false);
$GLOBALS['PARENT_RESPONSE'] = $raw;
$req = json_encode(['DataID' => '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}', 'Function' => 3, 'Address' => 100, 'Quantity' => 3, 'Data' => '']);
$out = $b->Forward($req);
$dec = json_decode($out, true);
check('2: Antwort der Brücke ist gültiges JSON', is_array($dec) && ($dec['ok'] ?? false) === true, $out);
check('2: base64 ergibt die rohen Bytes zurück, Bit für Bit', is_array($dec) && base64_decode($dec['data'], true) === $raw);
check('2: der Request wurde unverändert durchgereicht', end($GLOBALS['SENT']) === $req);

echo "\n3) Brücke: GetState() und Status\n";
$st = json_decode($b->GetState(), true);
check('3a: verbunden, aktiv, Unit-ID aus der DeviceID des Gateways', $st === ['connected' => true, 'parentActive' => true, 'parentStatus' => 102, 'unitId' => 41], json_encode($st));
$st = json_decode($bNo->GetState(), true);
check('3b: ohne Gateway: nicht verbunden, keine Unit-ID', $st['connected'] === false && $st['parentActive'] === false && $st['unitId'] === null, json_encode($st));
$st = json_decode((new MeterHubBridge($bridgeDown))->GetState(), true);
check('3c: Gateway inaktiv: verbunden, aber nicht aktiv, Status durchgereicht', $st['connected'] === true && $st['parentActive'] === false && $st['parentStatus'] === 201, json_encode($st));
$b->ApplyChanges();
$bNo->ApplyChanges();
check('3d: Status 102 mit aktivem Gateway, 104 ohne', $GLOBALS['STATUS'][$bridge] === 102 && $GLOBALS['STATUS'][$bridgeNo] === 104, json_encode($GLOBALS['STATUS']));
$form = json_decode($b->GetConfigurationForm(), true);
check('3e: Formular ist gültig und die Statuszeile (✅) nennt Gateway mit ID und Name, Unit-ID und Quelle', is_array($form) && str_contains($form['elements'][0]['caption'] ?? '', '✅') && str_contains($form['elements'][0]['caption'] ?? '', '#' . $gw . ' „ModBus Gateway BCR PV Zähler"') && str_contains($form['elements'][0]['caption'] ?? '', 'Unit-ID 41') && str_contains($form['elements'][0]['caption'] ?? '', 'DeviceID'), $form['elements'][0]['caption'] ?? '');
$formNo = json_decode($bNo->GetConfigurationForm(), true);
check('3e: ohne Gateway (⚠️): sagt, was fehlt und was dann gilt', str_starts_with($formNo['elements'][0]['caption'] ?? '', '⚠️') && str_contains($formNo['elements'][0]['caption'], 'Gateway ändern') && str_contains($formNo['elements'][0]['caption'], 'keine Werte'), $formNo['elements'][0]['caption'] ?? '');
$formDown = json_decode((new MeterHubBridge($bridgeDown))->GetConfigurationForm(), true);
check('3e: Gateway inaktiv (⚠️): nennt Gateway und Status', str_starts_with($formDown['elements'][0]['caption'] ?? '', '⚠️') && str_contains($formDown['elements'][0]['caption'], '#' . $gwDown) && str_contains($formDown['elements'][0]['caption'], 'Status 201'), $formDown['elements'][0]['caption'] ?? '');
check('3e: Formular sagt „eine Brücke = genau eine Unit-ID"', str_contains(json_encode($form, JSON_UNESCAPED_UNICODE), 'genau EINE Unit-ID'));

echo "\n4) MeterHub: BridgeCall() bis zu den dekodierten Registern\n";
$call = fn(int $id, string $j, &$e) => (new ReflectionMethod('MeterHub', 'BridgeCall'))->invokeArgs(null, [$id, $j, &$e]);
$err = 'x';
check('4a: keine Brücke gewählt (0) -> leer, no_bridge', $call(0, '{}', $err) === '' && $err === 'no_bridge');
check('4a: gewählte Instanz gibt es nicht mehr -> no_bridge', $call(99999, '{}', $err) === '' && $err === 'no_bridge');
$GLOBALS['PARENT_RESPONSE'] = $raw;
$got = $call($bridge, $req, $err);
check('4b: rohe Antwort kommt Bit für Bit an, kein Fehler', $got === $raw && $err === null);
$GLOBALS['BRIDGES'][$bridgeNo] = $bNo;
$GLOBALS['BRIDGES'][$bridgeDown] = new MeterHubBridge($bridgeDown);
check('4c: Brücke ohne Gateway -> leer, not_connected', $call($bridgeNo, '{}', $err) === '' && $err === 'not_connected');
check('4d: Gateway inaktiv -> parent_inactive', $call($bridgeDown, '{}', $err) === '' && $err === 'parent_inactive');
$GLOBALS['PARENT_RESPONSE'] = '';
check('4e: Gateway antwortet nicht -> no_response', $call($bridge, $req, $err) === '' && $err === 'no_response');

$GLOBALS['PARENT_RESPONSE'] = $raw;
$client = new MHUB_ModbusGatewayClient(1, function (string $json) use ($bridge, $call): string {
    $e = null;
    return $call($bridge, $json, $e);
});
$regs = $client->readHolding(100, 3);
check('4f: MHUB_ModbusGatewayClient liest über die Brücke [0xFFFF, 0x8001, 0]', $regs === [0xFFFF, 0x8001, 0], json_encode($regs));
$GLOBALS['PARENT_RESPONSE'] = '';
check('4f: ohne Antwort liefert der Client null statt eines Fehlers', $client->readHolding(100, 3) === null);

echo "\n5) MeterHub: CreateBridge()\n";
$hubTree = 7;
$hub = newInstance('{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}', 0, 102, [], 'Zähler');
$GLOBALS['INST'][$hub]['tree'] = $hubTree;
$mh = new MeterHub($hub);
check('5a: ohne Auswahl -> Hinweis, nichts angelegt', str_starts_with($mh->CreateBridge(0), '❌') && count(IPS_GetInstanceListByModuleID(BRIDGE_GUID)) === 3);
$other = newInstance('{00000000-0000-0000-0000-000000000000}', 0, 102, [], 'Kein Gateway');
check('5b: Instanz, die kein ModBus Gateway ist -> abgelehnt', str_starts_with($mh->CreateBridge($other), '❌'));
$gw2 = newInstance(GW_GUID, 0, 102, ['DeviceID' => 5], 'Gateway Zähler 5');
$before = count(IPS_GetInstanceListByModuleID(BRIDGE_GUID));
$msg = $mh->CreateBridge($gw2);
$after = IPS_GetInstanceListByModuleID(BRIDGE_GUID);
$newId = (int)end($after);
check('5c: legt genau eine Brücke an', count($after) === $before + 1 && str_starts_with($msg, '✅ Brücke #' . $newId . ' angelegt'), $msg);
check('5c: Brücke ist mit dem gewählten Gateway verbunden', $GLOBALS['INST'][$newId]['parent'] === $gw2);
check('5c: Brücke liegt neben dem Hub im Objektbaum und trägt einen sprechenden Namen', $GLOBALS['INST'][$newId]['tree'] === $hubTree && $GLOBALS['INST'][$newId]['name'] === 'MeterHub Brücke Gateway Zähler 5');
check('5c: die Auswahl im offenen Formular ist gesetzt', ($GLOBALS['FORMFIELDS']['BridgeInstanceID']['value'] ?? null) === $newId);
check('5c: ApplyChanges an der neuen Brücke ausgeführt', in_array($newId, $GLOBALS['APPLIED'] ?? [], true));
$msg2 = $mh->CreateBridge($gw2);
check('5d: zweiter Klick am selben Gateway legt KEINE zweite Brücke an', count(IPS_GetInstanceListByModuleID(BRIDGE_GUID)) === $before + 1 && str_contains($msg2, 'wiederverwendet'), $msg2);

echo "\n6) module.json\n";
$main = json_decode(file_get_contents(dirname(__DIR__) . '/MeterHub/module.json'), true);
$brg = json_decode(file_get_contents(dirname(__DIR__) . '/MeterHubBridge/module.json'), true);
check('6a: MeterHub trägt KEINE parentRequirements/implemented (kein Balken bei Direkt-Instanzen)', $main['parentRequirements'] === [] && $main['implemented'] === []);
check('6b: die Brücke trägt beide Einträge', $brg['parentRequirements'] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'] && $brg['implemented'] === ['{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}']);
check('6c: Modulname = Klassenname, eigene GUID und eigenes Prefix', $brg['name'] === 'MeterHubBridge' && class_exists($brg['name']) && $brg['id'] === BRIDGE_GUID && $brg['prefix'] === 'MHUBB' && $brg['id'] !== $main['id']);
check('6d: die GUID im Hauptmodul stimmt mit der module.json der Brücke überein', (new ReflectionClassConstant('MeterHub', 'BRIDGE_GUID'))->getValue() === $brg['id']);

$expected = ['MeterHub' => 'NRG-Stack MeterHub', 'MeterHubVirtual' => 'NRG-Stack MeterHub Virtueller Zähler', 'MeterHubDiscovery' => 'NRG-Stack MeterHub Suche', 'MeterHubBridge' => 'NRG-Stack MeterHub Brücke (ModBus-Gateway)'];
$aliasOk = true; $aliasDetail = [];
foreach ($expected as $mod => $alias) {
    $al = json_decode(file_get_contents(dirname(__DIR__) . "/$mod/module.json"), true)['aliases'] ?? [];
    if ($al !== [$alias]) { $aliasOk = false; $aliasDetail[] = "$mod: " . json_encode($al, JSON_UNESCAPED_UNICODE); }
}
check('6e: jedes Modul hat genau EINEN Alias nach dem Muster „NRG-Stack MeterHub …" (jeder Alias wäre im Anlege-Dialog ein eigener Eintrag — Forum-Feedback Mstaudi 20.09.2026)', $aliasOk, implode('; ', $aliasDetail));

echo "\n7) MeterHub: live berechnete Statuszeile zur Brücke (SUITE.md „Verbund-Verbindungen im Formular sichtbar machen\")\n";
$mhLine = fn(int $bid) => (new ReflectionMethod('MeterHub', 'BridgeStatusLine'))->invoke($mh, $bid);
$l = $mhLine(0);
check('7a: keine Brücke gewählt (⛔): sagt, was fehlt und dass dann nichts gelesen wird', str_starts_with($l, '⛔') && str_contains($l, 'Keine Brücke gewählt') && str_contains($l, 'liest diese Instanz nichts'), $l);
$l = $mhLine(99999);
check('7b: gewählte Brücke gibt es nicht mehr (⛔)', str_starts_with($l, '⛔') && str_contains($l, '#99999') && str_contains($l, 'nicht mehr'), $l);
$l = $mhLine($bridgeNo);
check('7c: Brücke ohne Gateway (⚠️): nennt die Brücke und den Weg zum Gateway', str_starts_with($l, '⚠️') && str_contains($l, '#' . $bridgeNo) && str_contains($l, 'Gateway ändern'), $l);
$l = $mhLine($bridgeDown);
check('7d: Gateway inaktiv (⚠️): nennt Brücke, Gateway und Status', str_starts_with($l, '⚠️') && str_contains($l, '#' . $bridgeDown) && str_contains($l, '#' . $gwDown) && str_contains($l, 'Status 201'), $l);
$l = $mhLine($bridge);
check('7e: alles in Ordnung (✅): Brücke und Gateway mit ID und Name, Unit-ID, Quelle', str_starts_with($l, '✅') && str_contains($l, '#' . $bridge) && str_contains($l, '#' . $gw . ' „ModBus Gateway BCR PV Zähler"') && str_contains($l, 'Unit-ID 41') && str_contains($l, 'DeviceID'), $l);

echo "\n8) MeterHub: Unit-ID kommt automatisch vom Gateway und ersetzt das Eingabefeld (SUITE.md „Wert kommt automatisch: Eingabefeld ersetzen\", 21.09.2026)\n";
$uLine = fn(int $bid) => (new ReflectionMethod('MeterHub', 'UnitIdAutoLine'))->invoke($mh, $bid);
$l = $uLine(0);
check('8a: keine Brücke (ℹ️): sagt ehrlich, dass nichts verfügbar ist und woher der Wert kommt', str_starts_with($l, 'ℹ️') && str_contains($l, 'noch nicht verfügbar') && str_contains($l, 'ModBus Gateway'), $l);
$l = $uLine($bridgeNo);
check('8b: Brücke ohne Gateway (ℹ️ statt einer erfundenen Zahl)', str_starts_with($l, 'ℹ️') && !preg_match('/Unit-ID: \d/', $l), $l);
$l = $uLine($bridge);
check('8c: Gateway liefert Unit-ID (🔗): Wert, Gateway mit ID und Name, Quelle', str_starts_with($l, '🔗') && str_contains($l, 'Unit-ID: 41') && str_contains($l, '#' . $gw . ' „ModBus Gateway BCR PV Zähler"') && str_contains($l, 'DeviceID'), $l);
$src = file_get_contents(dirname(__DIR__) . '/MeterHub/module.php');
check('8d: das Eingabefeld „Unit ID" ist im Gateway-Weg ausgeblendet, die 🔗-Zeile nur dort sichtbar', str_contains($src, "'name' => 'UnitId', 'visible' => !\$isCloud && \$connectionMode !== 'gateway'") && str_contains($src, "'name' => 'UnitIdAutoLine', 'visible' => !\$isCloud && \$connectionMode === 'gateway'"));
check('8e: der automatische Wert wird NIE per UpdateFormField in das Eingabefeld geschrieben', !preg_match("/UpdateFormField\\('UnitId',\\s*'value'/", $src));
$GLOBALS['FORMFIELDS'] = [];
$mh->OnChangeBridge($bridge);
check('8f: Brücken-Auswahl im offenen Formular frischt die 🔗-Zeile auf', str_starts_with($GLOBALS['FORMFIELDS']['UnitIdAutoLine']['caption'] ?? '', '🔗'), json_encode($GLOBALS['FORMFIELDS'], JSON_UNESCAPED_UNICODE));
$GLOBALS['FORMFIELDS'] = [];
$mh->OnChangeConnectionMode('gateway');
check('8g: Wechsel auf Gateway-Weg blendet die 🔗-Zeile ein, Wechsel zurück blendet sie aus', ($GLOBALS['FORMFIELDS']['UnitIdAutoLine']['visible'] ?? null) === true && ($GLOBALS['FORMFIELDS']['UnitId']['visible'] ?? null) === false);
$mh->OnChangeConnectionMode('direct');
check('8h: zurück auf direkt: Zeile aus, Feld an', ($GLOBALS['FORMFIELDS']['UnitIdAutoLine']['visible'] ?? null) === false && ($GLOBALS['FORMFIELDS']['UnitId']['visible'] ?? null) === true);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
