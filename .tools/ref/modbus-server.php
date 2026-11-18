<?php
/**
 * Kleiner Modbus-TCP-Server als Prüfwerkzeug (18.11.2026) für den Referenzlauf der Gerätesuche.
 *
 *   php ref/modbus-server.php <Profil.json>
 *
 * Meldet auf der Standardausgabe "PORT <n>" und beantwortet danach FC 3 und FC 4 aus dem Profil:
 *   {"units": {"<Unit-ID>": {"3": {"<Adresse>": <Wort>, …}, "4": {…}}}}
 * Fehlt ein Wort des angefragten Bereichs, kommt die Exception 02. Eine Unit-ID ohne Eintrag bekommt keine Antwort
 * (Verbindung wird geschlossen). Mehrere Verbindungen gleichzeitig sind erlaubt.
 */
$profile = json_decode(file_get_contents($argv[1]), true);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) { fwrite(STDERR, "kein Server: $errstr\n"); exit(1); }
$port = (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
fwrite(STDOUT, "PORT $port\n"); fflush(STDOUT);
$clients = []; $buf = [];
while (true) {
    $read = array_merge([$server], $clients);
    $w = $e = null;
    if (@stream_select($read, $w, $e, 0, 200000) === false) { break; }
    foreach ($read as $s) {
        if ($s === $server) { $c = @stream_socket_accept($server, 0); if ($c) { stream_set_blocking($c, false); $clients[(int)$c] = $c; $buf[(int)$c] = ''; } continue; }
        $data = @fread($s, 4096);
        $id = (int)$s;
        if ($data === '' || $data === false) { if (feof($s)) { fclose($s); unset($clients[$id], $buf[$id]); } continue; }
        $buf[$id] .= $data;
        while (strlen($buf[$id]) >= 12) {
            $tid = unpack('n', substr($buf[$id], 0, 2))[1];
            $unit = ord($buf[$id][6]); $fc = ord($buf[$id][7]);
            $addr = unpack('n', substr($buf[$id], 8, 2))[1];
            $qty = unpack('n', substr($buf[$id], 10, 2))[1];
            $buf[$id] = '';
            $words = $profile['units'][(string)$unit][(string)$fc] ?? null;
            if (!isset($profile['units'][(string)$unit])) { fclose($s); unset($clients[$id], $buf[$id]); break; }
            $ok = is_array($words);
            $out = '';
            for ($i = 0; $ok && $i < $qty; $i++) {
                if (!array_key_exists((string)($addr + $i), $words)) { $ok = false; break; }
                $out .= pack('n', $words[(string)($addr + $i)]);
            }
            $pdu = $ok ? chr($fc) . chr(strlen($out)) . $out : chr($fc | 0x80) . chr(2);
            @fwrite($s, pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unit) . $pdu);
            // Ein echtes Gerät lässt die Verbindung nach einer Exception offen; die Suche wartet dann bis zum Ende ihrer
            // Zeitgrenze (siehe Funde in MeterHub.md). Der Prüfserver schließt, damit der Lauf nicht minutenlang dauert —
            // für die Suche ist beides „keine Antwort“.
            if (!$ok) { fclose($s); unset($clients[$id], $buf[$id]); }
            break;
        }
    }
}
