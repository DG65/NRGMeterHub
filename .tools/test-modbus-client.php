<?php
/**
 * Prüfstand MHUB_ModbusTcpClient: eine Verbindung je Lesezyklus (0.26.5).
 * Block 6 prüft zusätzlich MHUB_ModbusGatewayClient (0.29.6, SUITE.md 9j).
 *
 *   php .tools/test-modbus-client.php    # 0 = alle Prüfungen bestanden
 *
 * Startet einen echten kleinen Modbus-TCP-Server in einem eigenen PHP-Prozess
 * (Register = eigene Adresse, FC16 wird quittiert) und zählt, wie viele
 * Verbindungen der Client tatsächlich aufbaut. Anlass: im Solarpark standen
 * 2 333 abgebaute Verbindungen zu einem einzigen blue'Log im Wartezustand,
 * weil jede einzelne Anfrage eine eigene Verbindung öffnete.
 */

// Minimal-Stubs, damit MeterHub/module.php geladen werden kann — der
// Modbus-Client selbst braucht kein IP-Symcon.
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
if (!function_exists('IPS_LogMessage')) {
    function IPS_LogMessage($s, $m) { $GLOBALS['LOG'][] = $m; }
}
require_once dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

/** Server-Prozess starten; Modi: normal | dropafter1 | exception | silent */
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
    $served = 0;
    while (true) {
        $head = $rx($c, 7);
        if ($head === null) { break; }
        $h = unpack('ntid/npid/nlen/Cunit', $head);
        $pdu = $rx($c, $h['len'] - 1);
        if ($pdu === null) { break; }
        if ($mode === 'silent') { continue; }
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
        if ($mode === 'stray') {
            // Verspätete Antwort einer "früheren" Anfrage: fremde TID, Müllwert.
            $bad = chr($fc) . chr(2) . pack('n', 0xDEAD);
            fwrite($c, pack('nnn', ($h['tid'] + 1000) & 0xFFFF, 0, strlen($bad) + 1) . chr($h['unit']) . $bad);
        }
        fwrite($c, pack('nnn', $h['tid'], 0, strlen($resp) + 1) . chr($h['unit']) . $resp);
        $served++;
        if ($mode === 'dropafter1') { break; }
    }
    fclose($c);
}
PHP;
    $proc = proc_open([PHP_BINARY, '-r', $code, (string)$port, $mode, $cnt], [], $pipes);
    // Warten, bis der Server lauscht.
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents($cnt) === '0') { break; }
        usleep(100000);
    }
    return [$proc, $port, $cnt];
}
function stopServer(array $srv): void
{
    proc_terminate($srv[0]);
    proc_close($srv[0]);
    @unlink($srv[2]);
}
function serverConns(array $srv): int
{
    usleep(150000);
    return (int)@file_get_contents($srv[2]);
}

echo "1) Normalbetrieb: mehrere Abfragen + Schreiben über EINE Verbindung\n";
$s = startServer('normal');
$mb = new MHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$r1 = $mb->readHolding(41000, 14);
$r2 = $mb->readHolding(40000, 1);
$r3 = $mb->readInput(1011, 4);
$w  = $mb->writeHolding(5000, [0x1234, 0x5678]);
check('Werte korrekt (Register = Adresse)', ($r1[0] ?? null) === 41000 && ($r1[13] ?? null) === 41013 && ($r2[0] ?? null) === 40000 && ($r3[3] ?? null) === 1014, json_encode([$r1[0] ?? null, $r2, $r3]));
check('Schreiben quittiert', $w === true);
check('nur eine Verbindung für 4 Anfragen (Client-Zähler)', $mb->connects === 1, (string)$mb->connects);
check('nur eine Verbindung für 4 Anfragen (Server-Zähler)', serverConns($s) === 1, (string)serverConns($s));
$mb->close();
$mb->readHolding(100, 2);
check('nach close() öffnet der nächste Zyklus eine neue Verbindung', serverConns($s) === 2 && $mb->connects === 2);
unset($mb);
stopServer($s);

echo "2) Gegenstelle schließt nach jeder Antwort: einmal neu verbinden, Werte trotzdem vollständig\n";
$s = startServer('dropafter1');
$mb = new MHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$ok = true;
for ($i = 0; $i < 3; $i++) {
    $r = $mb->readHolding(200 + $i, 1);
    $ok = $ok && (($r[0] ?? null) === 200 + $i);
}
check('alle drei Abfragen beantwortet', $ok);
check('je Abfrage genau eine Verbindung (kein Endlos-Wiederholen)', serverConns($s) === 3, (string)serverConns($s));
$mb->close();
stopServer($s);

echo "3) Modbus-Exception: gültige Antwort, Verbindung bleibt, kein zweiter Versuch\n";
$s = startServer('exception');
$mb = new MHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('Exception → null', $mb->readHolding(1, 1) === null);
check('zweite Abfrage ebenfalls null', $mb->readHolding(2, 1) === null);
check('dieselbe Verbindung weiterbenutzt', serverConns($s) === 1 && $mb->connects === 1, (string)serverConns($s));
$mb->close();
stopServer($s);

echo "4) Stummes Gerät: Zeitüberschreitung ohne zweiten Versuch (keine doppelte Wartezeit)\n";
$s = startServer('silent');
$mb = new MHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$mb->readHolding(1, 1); // öffnet die Verbindung (wartet 3 s)
$t0 = microtime(true);
$res = $mb->readHolding(2, 1);
$dt = microtime(true) - $t0;
check('liefert null', $res === null);
check('nur einmal gewartet (≈3 s, nicht 6 s)', $dt < 4.5, round($dt, 2) . ' s');
check('Grund: timeout', $mb->lastError === 'timeout', $mb->lastError);
$mb->close();
stopServer($s);

echo "5b) Fremde Antwort mit falscher Transaktions-ID (Befund InverterHub 12.09.2026: 261,5 MW nachts aus vertauschten Registerhälften)\n";
$s = startServer('stray');
$mb = new MHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$st1 = $mb->readHolding(41000, 1);
$st2 = $mb->readHolding(41001, 1);
check('fremder Frame verworfen, jede Abfrage bekommt ihren eigenen Wert', ($st1[0] ?? null) === 41000 && ($st2[0] ?? null) === 41001, json_encode([$st1, $st2]));
check('kein 0xDEAD durchgerutscht, dieselbe Verbindung', !in_array(0xDEAD, array_merge((array)$st1, (array)$st2), true) && serverConns($s) === 1);
$mb->close();
stopServer($s);

echo "5) Kein Server erreichbar: sauber null, kein Hängen\n";
$mb = new MHUB_ModbusTcpClient('127.0.0.1', 1, 1);
check('null und Grund connect', $mb->readHolding(1, 1) === null && $mb->lastError === 'connect', $mb->lastError);

echo "6) MHUB_ModbusGatewayClient (SUITE.md 9j, Nutzlastformat am Rohcode von SymconBC/EM24-DIN verifiziert)\n";
$GLOBALS['LOG'] = [];
$calls = [];
$fakeSend = function (string $json) use (&$calls) {
    $calls[] = json_decode($json, true);
    $req = end($calls);
    if ($req['Function'] === 16) {
        return "\x10\x02"; // FC-Echo + ByteCount, wie im Vorbild kein Nutzdaten-Body noetig
    }
    // FC3/FC4: 2 Byte Header ueberspringen, dann Quantity Register a 2 Byte,
    // Wert = Register-Adresse (leicht pruefbar, wie im TCP-Server oben).
    $data = '';
    for ($i = 0; $i < $req['Quantity']; $i++) {
        $data .= pack('n', ($req['Address'] + $i) & 0xFFFF);
    }
    return "\xFF\xFF" . $data; // erste 2 Byte sind laut Vorbild irrelevant/uebersprungen
};
$gw = new MHUB_ModbusGatewayClient(1, $fakeSend);
check('implementiert MHUB_ModbusClientInterface', $gw instanceof MHUB_ModbusClientInterface);
$r = $gw->readHolding(100, 3);
check('readHolding: DataID/Function/Address/Quantity korrekt gesendet', $calls[0] === ['DataID' => '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}', 'Function' => 3, 'Address' => 100, 'Quantity' => 3, 'Data' => ''], json_encode($calls[0]));
check('readHolding: Antwort korrekt dekodiert (2-Byte-Header uebersprungen, 0-indiziert)', $r === [100, 101, 102], json_encode($r));
$r2 = $gw->readInput(200, 2);
check('readInput: Function 4 gesendet', $calls[1]['Function'] === 4);
check('readInput: Werte korrekt', $r2 === [200, 201], json_encode($r2));
// 0xFFFF/0x8001 bewusst gewaehlt: der rohe gepackte Binaerstring dieser Werte
// ist KEIN gueltiges UTF-8 -- json_encode() auf den rohen Bytes (statt
// base64) schlaegt fehl und liefert false (Fund ChargerHub 18.09.2026).
$w = $gw->writeHolding(300, [0xFFFF, 0x8001]);
check('writeHolding: Function 16 gesendet', $calls[2]['Function'] === 16 && $calls[2]['Address'] === 300 && $calls[2]['Quantity'] === 2);
check('writeHolding: "Data" ist gueltiges JSON trotz nicht-UTF8-Registerwerten (base64)', $calls[2]['Data'] === base64_encode(pack('n', 0xFFFF) . pack('n', 0x8001)), $calls[2]['Data']);
check('writeHolding: liefert true bei Erfolg', $w === true);
check('writeHolding: genau EIN Warnhinweis (ungetestete Ableitung) trotz mehrerer Aufrufe', count($GLOBALS['LOG']) === 1, (string)count($GLOBALS['LOG']));
$fakeFail = function (string $json) { return false; };
$gwFail = new MHUB_ModbusGatewayClient(1, $fakeFail);
check('Verbindungsfehler (false von SendDataToParent) liefert null, kein Fatal Error', $gwFail->readHolding(1, 1) === null);
check('close() ist gefahrlos aufrufbar (No-Op)', ($gw->close() ?? true) === true);
check('Register-Dekodierung identisch zu MHUB_ModbusTcpClient (Float32)', $gw->readFloat32([16968, 0], 0) === (new MHUB_ModbusTcpClient('x', 1, 1))->readFloat32([16968, 0], 0));

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
