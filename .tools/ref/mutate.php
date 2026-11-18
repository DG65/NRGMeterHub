<?php
/**
 * Mutationsproben für die Referenzläufe (Prüfwerkzeug, 18.11.2026).
 *
 *   php .tools/ref/mutate.php treiber        # eine Gruppe
 *   php .tools/ref/mutate.php                # alle
 *
 * Je Mutation wird eine Kopie des Moduls mit EINER absichtlichen Änderung (Adresse, Vorzeichen, Wortreihenfolge,
 * Skalierung, Ungültig-Marke, Gruppenbedingung, Archiv-Lücke …) erzeugt und der zugehörige Referenzlauf dagegen
 * gestartet (MHUB_MODULE). Erwartet wird ein echter Prüfungsfehler ("FEHLT"), nicht nur ein Absturz. Das Modul im
 * Repository wird nie verändert. Mutationen, deren Fundstelle im Treiber fehlt, werden als "n/a" gemeldet.
 */
$root = dirname(__DIR__, 2);
$orig = file_get_contents("$root/MeterHub/module.php");
$origD = file_get_contents("$root/MeterHubDiscovery/module.php");

/** Bereich einer Klasse im Quelltext: [Start, Länge]. */
function classRegion(string $src, string $name): array
{
    if (!preg_match('/^(?:final\s+)?class\s+' . preg_quote($name, '/') . '\b/m', $src, $m, PREG_OFFSET_CAPTURE)) { return [0, 0]; }
    $start = $m[0][1];
    $end = preg_match('/^(?:final\s+)?(?:class|interface)\s+\w+/m', $src, $n, PREG_OFFSET_CAPTURE, $start + 10) ? $n[0][1] : strlen($src);
    return [$start, $end - $start];
}
/** Ersetzt die erste Fundstelle im Klassenbereich; null, wenn nichts gefunden wurde. */
function mutateIn(string $src, string $class, string $regex, $replacement): ?string
{
    [$s, $l] = classRegion($src, $class);
    if ($l === 0) { return null; }
    $region = substr($src, $s, $l);
    $new = is_callable($replacement) ? preg_replace_callback($regex, $replacement, $region, 1, $count) : preg_replace($regex, $replacement, $region, 1, $count);
    return $count === 1 ? substr($src, 0, $s) . $new . substr($src, $s + $l) : null;
}
/** Ersetzt in einer Methode der Hauptklasse (von "function name(" bis zur nächsten "    public/private/protected function"). */
function mutateMethod(string $src, string $method, string $regex, $replacement): ?string
{
    if (!preg_match('/^    (?:public|private|protected)(?: static)? function ' . preg_quote($method, '/') . '\(/m', $src, $m, PREG_OFFSET_CAPTURE)) { return null; }
    $s = $m[0][1];
    $e = preg_match('/^    (?:public|private|protected)[^\n]*function \w+\(/m', $src, $n, PREG_OFFSET_CAPTURE, $s + 20) ? $n[0][1] : strlen($src);
    $region = substr($src, $s, $e - $s);
    $new = is_callable($replacement) ? preg_replace_callback($regex, $replacement, $region, 1, $count) : preg_replace($regex, $replacement, $region, 1, $count);
    return $count === 1 ? substr($src, 0, $s) . $new . substr($src, $e) : null;
}

$mutations = [];
$groups = [];

// ---- Zählertreiber -------------------------------------------------------------------------------------------
$drivers = ['MHUB_BlueLogScadaInverterDriver', 'MHUB_BlueLogScadaMeterDriver', 'MHUB_ShellyPro3emDriver', 'MHUB_GoeControllerDriver',
    'MHUB_JanitzaClassicDriver', 'MHUB_Umg800Driver', 'MHUB_EastronSdmDriver', 'MHUB_WhatWattDriver', 'MHUB_PhoenixEem375Driver',
    'MHUB_PhoenixEemXmDriver', 'MHUB_CarloGavazziDriver', 'MHUB_SocomecCountisDriver', 'MHUB_MbsProfessionalDriver',
    'MHUB_BlueLogScadaLoggerDriver', 'MHUB_BlueLogPowerControlDriver'];
foreach ($drivers as $d) {
    $short = str_replace(['MHUB_', 'Driver'], '', $d);
    $t = 'test-ref-treiber.php';
    $mutations[] = ['treiber', "$short: Registeradresse +1", $t, fn($s) => mutateIn($s, $d, '/(readHolding|readInput)\((\d+),/', fn($m) => $m[1] . '(' . ($m[2] + 1) . ',')
        ?? mutateIn($s, $d, '/(readHolding|readInput)\((0x[0-9A-Fa-f]+),/', fn($m) => $m[1] . '(' . (hexdec($m[2]) + 1) . ',')];
    $mutations[] = ['treiber', "$short: Vorzeichen der Wirkleistung", $t, fn($s) => mutateIn($s, $d, '/(SetVarFloat\(\'power_total\', )-/', '$1')   // Treiber, der schon negiert (Power Control): Negation entfernen
        ?? mutateIn($s, $d, '/(SetVarFloat\(\'power_total\', |put\(\$hub, \'power_total\', )/', '$1-')];
    $mutations[] = ['treiber', "$short: Wortreihenfolge", $t, function ($s) use ($d) {
        return mutateIn($s, $d, '/setWordSwap\(true\)/', 'setWordSwap(false)')
            ?? mutateIn($s, $d, '/\$mb->s32sw\(/', '$mb->s32(')
            ?? mutateIn($s, $d, '/\$mb->s32\(/', '$mb->s32sw(')
            ?? mutateIn($s, $d, '/(public function readFast\(\$mb, \$hub\)\s*\{)/', '$1 $mb->setWordSwap(true);');
    }];
    $mutations[] = ['treiber', "$short: Skalierung", $t, function ($s) use ($d) {
        foreach (['/\* 0\.1\b/' => '* 0.2', '/\* 0\.01\b/' => '* 0.02', '/\* 0\.001\b/' => '* 0.002', '/\* 10\.0\b/' => '* 100.0', '/\/ 1000\.0\b/' => '/ 100.0'] as $re => $rep) {
            $r = mutateIn($s, $d, $re, $rep); if ($r !== null) { return $r; }
        }
        return mutateIn($s, $d, '/(readFloat32\(\$[a-z]+, )(\d+)\)/', fn($m) => $m[1] . ($m[2] + 2) . ')');   // Float32-Treiber ohne Faktor: Offset
    }];
    $mutations[] = ['treiber', "$short: Gruppenbedingung umgekehrt", $t, fn($s) => mutateIn($s, $d, '/\$hub->GroupActive\(/', '!$hub->GroupActive(')];
}
$mutations[] = ['treiber', 'Janitza klassisch: Drehfeld ohne is_finite-Prüfung (Fund 2)', 'test-ref-treiber.php', fn($s) => mutateIn($s, 'MHUB_JanitzaClassicDriver', '/is_finite\(\$seq\)/', 'true')];
$mutations[] = ['treiber', 'GoeController: Ungültig-Marke (is_finite) entfernt', 'test-ref-treiber.php', fn($s) => mutateIn($s, 'MHUB_GoeControllerDriver', '/is_finite\(\$v\)/', 'true')];

// ---- ApplyChanges und Rückfallwert -------------------------------------------------------------------------------
$ta = 'test-ref-applychanges.php';
$am = function (string $name, string $find, string $replace) use (&$mutations, $ta) {
    $mutations[] = ['apply', $name, $ta, fn($s) => mutateMethod($s, 'ApplyChanges', '/' . preg_quote($find, '/') . '/', str_replace('$', '\$', $replace))];
};
$am('Rückfallwert: Bedingung „Default“ umgekehrt', "\$this->FailsafeMode() === 'default'", "\$this->FailsafeMode() !== 'default'");
$am('Rückfallwert: Bereitschaft wird nicht geprüft', '$ready && $driver instanceof', '$driver instanceof');
$am('Rückfallwert: ohne Sperre geschrieben', '$this->RunLocked(self::LOCK_WAIT_WRITE_MS, true, function () use ($driver) {', '(function ($w, $m, $fn) { $fn(); return true; })(0, true, function () use ($driver) {');
$am('Rückfallwert: Schreiben entfällt', '$driver->writeFailsafe($mb, $this);', ';');
$am('Deaktiviert: Status 104 wird nicht gesetzt', '$this->SetStatus(104);', '$this->SetStatus(102);');
$am('Deaktiviert: Schnell-Timer bleibt an', "\$this->SetTimerInterval('FastTimer', 0);", ';');
$am('Deaktiviert: Langsam-Timer bleibt an', "\$this->SetTimerInterval('SlowTimer', 0);", ';');
$am('Aktiv-Bedingung: UND statt ODER', "!\$this->ReadPropertyBoolean('Active') || !\$ready", "!\$this->ReadPropertyBoolean('Active') && !\$ready");
$am('Cloud: Token-Prüfung umgekehrt', "\$this->ReadAttributeString('InexogyToken') !== ''", "\$this->ReadAttributeString('InexogyToken') === ''");
$am('MQTT: Brücke wird nicht verlangt', "\$this->ReadPropertyInteger('MqttBridgeID') > 0 && trim", "\$this->ReadPropertyInteger('MqttBridgeID') >= 0 && trim");
$am('Gateway: Brücke wird nicht verlangt', "\$this->ReadPropertyInteger('BridgeInstanceID') > 0", "\$this->ReadPropertyInteger('BridgeInstanceID') >= 0");
$am('HTTP: Host wird nicht verlangt', "\$this->ReadPropertyString('Host') !== ''\n                    : (\$this->ReadPropertyString('ConnectionMode')", "true\n                    : (\$this->ReadPropertyString('ConnectionMode')");
$am('Schnell-Timer nimmt das langsame Intervall', "\$this->ReadPropertyInteger('IntervalFast') * 1000", "\$this->ReadPropertyInteger('IntervalSlow') * 1000");
$am('Variablen werden nicht registriert', '$this->RegisterVariables();', ';');
$am('PowerInvert: Idents werden nicht getauscht', '$this->SyncInvertLayout();', ';');
$mutations[] = ['apply', 'blue\'Log RPC Rückfallwert: falsches Register (5001)', $ta, fn($s) => mutateIn($s, 'MHUB_BlueLogRpcDriver', '/writeHolding\(5000, \$mb->packFloat32\(\$hub->FailsafeDefaultPercent\(\)\)\)/', 'writeHolding(5001, $mb->packFloat32($hub->FailsafeDefaultPercent()))')];
$mutations[] = ['apply', 'blue\'Log RPC Rückfallwert: fester Wert statt Einstellung', $ta, fn($s) => mutateIn($s, 'MHUB_BlueLogRpcDriver', '/packFloat32\(\$hub->FailsafeDefaultPercent\(\)\)/', 'packFloat32(100.0)')];
$mutations[] = ['apply', 'blue\'Log Power Control Rückfallwert: Wortreihenfolge', $ta, fn($s) => mutateIn($s, 'MHUB_BlueLogPowerControlDriver', '/(function writeFailsafe\(\$mb, \$hub\): void\s*\{\s*)\$mb->setWordSwap\(true\);/', '$1$mb->setWordSwap(false);')];

// ---- Archiv-Reparatur ---------------------------------------------------------------------------------------------
$tr = 'test-ref-archiv.php';
$ar = function (string $name, string $method, string $find, string $replace) use (&$mutations, $tr) {
    $mutations[] = ['archiv', $name, $tr, fn($s) => mutateMethod($s, $method, '/' . preg_quote($find, '/') . '/', str_replace('$', '\$', $replace))];
};
$ar('Energie: Tage-Obergrenze 45 entfällt', 'EnergyArchiveRepair', 'min(45, $days)', 'min(90, $days)');
$ar('Energie: Rückwärtsschalter wirkt umgekehrt', 'EnergyArchiveRepair', "if (!\$s['zero'] && !\$withBackward) {", "if (!\$s['zero'] && \$withBackward) {");
$ar('Energie: lange Rückschrittreihe wird doch repariert', 'EnergyArchiveRepair', "!\$s['zero'] && \$n > 3", "!\$s['zero'] && \$n > 30");
$ar('Energie: PowerInvert-Vorzeichen bei der Leistung', 'EnergyArchiveRepair', '$sign = $this->PowerSign();', '$sign = 1.0;');
$ar('Energie: Wh statt kWh bei der Leistung', 'EnergyArchiveRepair', "? 1.0 : 1000.0;", "? 1000.0 : 1000.0;");
$ar('Energie: Leistung aus Bezug PLUS Einspeisung', 'EnergyArchiveRepair', '($dI - $dE)', '($dI + $dE)');
$ar('Energie: Nullwerte werden nicht gelöscht', 'EnergyArchiveRepair', 'AC_DeleteVariableData($ac, $vid, $ts, $ts);', ';');
$ar('Energie: keine Neuaggregation', 'EnergyArchiveRepair', "\$this->ReAggregate(\$ac, \$vid);\n        }\n        if (\$powerPlan)", "}\n        if (\$powerPlan)");
$ar('Energie: Referenzzähler wird nicht berücksichtigt', 'EnergyArchiveRepair', '$refCands[] = $rv;', ';');
$ar('Energie: Leistungsfenster beginnt falsch', 'EnergyArchiveRepair', 'AC_DeleteVariableData($ac, $pvid, $ta + 1, $tb);', 'AC_DeleteVariableData($ac, $pvid, $ta, $tb);');
$ar('Zählerlücken: Nullwert gilt nicht mehr als ungültig', 'FindCounterGaps', '$v <= 0 || ($last', '$v < 0 || ($last');
$ar('Zählerlücken: Rückschritt-Toleranz', 'FindCounterGaps', '$v < $last[1] - 1e-9', '$v < $last[1] - 50.0');
$ar('Lückenfüllung: Verlauf nicht auf 0..1 begrenzt', 'ShapeFill', '$f = max(0.0, min(1.0, $f));', '$f = $f;');
$ar('Lückenfüllung: Referenzverlauf wird ignoriert', 'ShapeFill', '$useRef = $ra !== null', '$useRef = false && $ra !== null');
$ar('Referenzwahl: Übereinstimmung nicht geprüft', 'PickReference', '$b - $a < 0.01', '$b - $a < 1000.0');
$ar('Richtung: Kreuzsprung-Schwelle', 'RetrackStep', '$sep >= 0.05 * max($lo, $lt)', '$sep >= 5.0 * max($lo, $lt)');
$ar('Richtung: Ungültig-Marke (Wert <= 0) entfällt', 'RetrackStep', '!is_finite($v) || $v <= 0', '!is_finite($v)');
$ar('Richtung: unklares Paar wird doch korrigiert', 'DirectionArchive', "\$dry['on'][0] === \$dry['on'][1]", 'false');
$ar('Richtung: Abschnitt bis heute wird nicht erkannt', 'DirectionArchive', "\$swapEnd = \$dry['on'][0] === 1;", "\$swapEnd = false;");
$ar('Richtung: Sicherung der Energie unter anderem Namen', 'DirectionArchive', "'energie'", "'energie_x'");
$ar('Richtung: Cloudzähler bekommt Leistungsabgleich', 'DirectionArchive', 'self::CLOUD_METERS, true)) {', 'self::CLOUD_METERS, true) && false) {');
$ar('Richtung: Leistungsfenster wird nicht gedreht', 'FlipPowerWindow', '$new[$ts] = -$v;', '$new[$ts] = $v;');
$ar('Leistung: Wh/kWh-Faktor', 'PowerMismatch', '? 4.0 : 4000.0;', '? 4.0 : 400.0;');
$ar('Leistung: Kantenbestimmung ohne Vorzeichenvergleich', 'RefineEdge', '($net > 0) !== ($p > 0)', '($net > 0) === ($p > 0)');
$ar('Live-Werte: Raster der offiziellen Punkte', 'SelectStaleLivePoints', '$ts % 900 === 0', '$ts % 900 !== 0');
$ar('Live-Werte: Abdeckungsfenster', 'SelectStaleLivePoints', '$ts - $prev <= 1800', '$ts - $prev <= 60');
$ar('Live-Werte: Überzählung ohne Nettoabzug', 'CounterOvercount', '$net = max(0.0, $pts[count($pts) - 1][1] - $pts[0][1]);', '$net = 0.0;');
$ar('Live-Werte: Tage-Obergrenze', 'InexogyCleanup', 'min(45, $days)', 'min(90, $days)');
$ar('Live-Werte: Löschen entfällt', 'CleanStaleLivePoints', 'AC_DeleteVariableData($ac, $vid, $ts, $ts);', ';');

// ---- Gerätesuche ----------------------------------------------------------------------------------------------------
$ts = 'test-ref-suche.php';
$sm = function (string $name, string $method, string $find, string $replace) use (&$mutations, $ts) {
    $mutations[] = ['suche', $name, $ts, fn($s) => mutateMethod($s, $method, '/' . preg_quote($find, '/') . '/', str_replace('$', '\$', $replace)), 'D'];
};
$sc = function (string $name, string $find, string $replace) use (&$mutations, $ts) {
    $mutations[] = ['suche', $name, $ts, fn($s) => mutateIn($s, 'MeterHubDiscovery', '/' . preg_quote($find, '/') . '/', str_replace('$', '\$', $replace)), 'D'];
};
$sm('PAC2200: Frequenzgrenze oben', 'probeMeter', '$f > 65.0', '$f > 75.0');
$sm('PAC2200: Spannungsgrenze unten', 'probeMeter', '$u >= 30.0', '$u >= 10.0');
$sm('PAC2200: Frequenzregister 55 → 56', 'probeMeter', '$unitId, 55, 1.0', '$unitId, 56, 1.0');
$sm('Shelly Pro 3EM: Frequenzregister', 'probeMeter', '$unitId, 1033, 1.0', '$unitId, 1034, 1.0');
$sm('Carlo Gavazzi: Frequenzfaktor', 'probeMeter', '$fraw * 0.1 >= 45.0', '$fraw * 0.2 >= 45.0');
$sm('WhatWatt: zweites Spannungsregister', 'probeMeter', '$unitId, 3, 1.0', '$unitId, 5, 1.0');
$sm('go-e: zweites Spannungsregister', 'probeMeter', '$unitId, 1002, 1.0', '$unitId, 1004, 1.0');
$sm('Eastron: Frequenzregister', 'probeMeter', '$unitId, 70, 1.0', '$unitId, 71, 1.0');
$sc('Unit-ID 247 des PAC2200 entfällt', "'siemens_pac2200' => [1, 247, 126]", "'siemens_pac2200' => [1, 126]");
$sc('Unit-ID 255 des Phoenix EEM entfällt', "'phoenix_eem375'   => [255, 1]", "'phoenix_eem375'   => [1]");
$sm('blue\'Log-Host: Logger-Typ 0 unter Adresse 97', 'identifyBlueLogHost', '$type === 0 : isset', '$type === 1 : isset');
$sm('blue\'Log-Host: Frequenzgrenze', 'identifyBlueLogHost', '$f <= 65.0', '$f <= 40.0');
$sm('blue\'Log-Geräte: Logger nur unter Adresse 97', 'identifyBlueLogDevicesBatch', "((\$type === 0) !== (\$t['unitId'] === self::BLUELOG_SELF_ADDR))", 'false');
$sm('blue\'Log: Stichprobe ohne Bereichsmitte', 'blueLogScadaSampleIds', '[97, $from, $mid]', '[97, $from]');
$sm('blue\'Log: Modellname in falscher Registerreihenfolge', 'readAscii', 'for ($i = $regCount - 1; $i >= 0; $i--) {', 'for ($i = 0; $i < $regCount; $i++) {');
$sm('blue\'Log: Float-Wortreihenfolge', 'wordSwappedFloat', "pack('nn', \$regLow, \$regHigh)", "pack('nn', \$regHigh, \$regLow)");
$sm('Bereich: Start gleich Ende liefert nichts', 'expandRange', '$start > $end', '$start >= $end');
$sm('Ausschlussliste: ungültige Adressen bleiben', 'ParseIgnoreIPs', 'ip2long($part) !== false', 'true');
$sm('Suche: Abbruch wird im Lauf nicht beachtet', 'Discover', 'if ($this->scanAborted()) { $aborted = true; break; }', ';');
$sm('Suche: Ausschlussliste wird nicht angewendet', 'Discover', '$ips = array_values(array_diff($ips, $ignore));', ';');
$sm('Lizenzprüfung: Symbole vertauscht', 'CheckBlueLogLicenses', "(\$ok ? '✅ ' : '❌ ')", "(\$ok ? '❌ ' : '✅ ')");
$sm('Lizenzprüfung: Exception zählt nicht mehr', 'probeUnitResponds', 'strlen($response) >= 8;', 'strlen($response) >= 10;');
$sm('Modbus-Lesen: Exception-Abkürzung entfällt (wartet bis zur Zeitgrenze)', 'modbusRead', "if (ord(\$response[7]) & 0x80) {\n                    break;\n                }", ';');
$sm('Parallele Probe: geschlossene Gegenseite wird nicht erkannt (dreht bis zur Zeitgrenze)', 'probeManyParallel', "if ((\$chunk === '' || \$chunk === false) && feof(\$s)) {\n                        \$closed[\$i] = true;\n                    }", ';');
$sm('Parallele Probe: Ergebnis der antwortenden Unit geht verloren', 'probeManyParallel', '$buffers[$i] .= $chunk;', ';');
$sm('Zeitgrenze: Nachkommaanteil wird abgeschnitten (Fund 5)', 'setSocketTimeout', 'stream_set_timeout($sock, $sec, (int)round(((float)$timeout - $sec) * 1000000));', 'stream_set_timeout($sock, $sec);');
// (Mutation „Exception wird als Daten gelesen“ entfällt: seit 0.39.2 beendet die Exception-Abkürzung die Abfrage, und `$rfc !== $fc` allein fängt die Exception genauso ab, gleichwertige Mutante.)

// ---- phaseCurrentIDs ------------------------------------------------------------------------------------------------
$tp = 'test-phasecurrent.php';
$pm = function (string $name, string $method, string $find, string $replace) use (&$mutations, $tp) {
    $mutations[] = ['phase', $name, $tp, fn($s) => mutateMethod($s, $method, '/' . preg_quote($find, '/') . '/', str_replace('$', '\$', $replace))];
};
$pm('Phasenströme auch an Nicht-Netz-Zuordnungen', 'GetFunctions', "\$a['key'] === 'grid' && ", '');
$pm('Phasenströme auch in der Je-Phase-Zuordnung', 'GetFunctions', "\$a['slot'] === 'total' && ", '');
$pm('Phasenströme fehlen immer', 'GetFunctions', '$phaseCurrentIDs !== null ?', 'false ?');
$pm('Vertragsversion bleibt 1.4', 'GetFunctions', "'contractVersion' => '1.5'", "'contractVersion' => '1.4'");
$pm('Gruppe „Strom je Phase“ wird nicht geprüft', 'PhaseCurrentIDs', " || !\$this->GroupActive('GroupCurrentPhase')", '');
$pm('Einphasige Zähler werden nicht ausgeschlossen', 'PhaseCurrentIDs', '$this->IsSinglePhaseMeter() || ', '');
$pm('Phasenreihenfolge L2 vor L1', 'PhaseCurrentIDs', "['i_l1', 'i_l2', 'i_l3']", "['i_l2', 'i_l1', 'i_l3']");
$pm('Fehlende Phase wird als 0 geliefert', 'PhaseCurrentIDs', "if (!\$vid) {\n                return null;\n            }", "if (!\$vid) {\n                \$vid = 0;\n            }");

// ---- Ablauf ---------------------------------------------------------------------------------------------------
$only = $argv[1] ?? null;
$jobs = [];
// Quelltext-Dateien, in denen eine Mutation ihre Fundstelle haben kann: module.php und die aus der Klasse ausgelagerten Methodengruppen
// (libs/MeterHub*.php, ab 0.40.0). Jede Mutation läuft in einer Kopie der ganzen Ordnerstruktur (module.php braucht ../libs).
$mainFiles = ['MeterHub/module.php', 'libs/MeterHubInexogy.php', 'libs/MeterHubArchive.php'];
$sources = [];
foreach ($mainFiles as $f) { $sources[$f] = file_get_contents("$root/$f"); }
function copyTree(string $root, string $dest, array $override): void
{
    foreach (['MeterHub', 'MeterHubDiscovery', 'libs'] as $dir) {
        @mkdir("$dest/$dir", 0777, true);
        foreach (glob("$root/$dir/*.php") as $f) { copy($f, "$dest/$dir/" . basename($f)); }
    }
    foreach ($override as $rel => $content) { file_put_contents("$dest/$rel", $content); }
}
function tempTree(): string { $d = sys_get_temp_dir() . '/mhmut_' . getmypid() . '_' . bin2hex(random_bytes(3)); mkdir($d); return $d; }
// Kontrolle: unveränderte Kopie des Moduls muss jeden Referenzlauf bestehen (sonst beweist ein rotes Ergebnis nichts)
foreach (array_unique(array_column($mutations, 2)) as $test) {
    if ($only && !in_array($only, array_column(array_filter($mutations, fn($m) => $m[2] === $test), 0), true)) { continue; }
    $tree = tempTree();
    copyTree($root, $tree, []);
    $text = (string)shell_exec('MHUB_MODULE=' . escapeshellarg("$tree/MeterHub/module.php") . ' MHUB_DISCOVERY=' . escapeshellarg("$tree/MeterHubDiscovery/module.php") . ' php ' . escapeshellarg("$root/.tools/$test") . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($tree));
    if (!str_contains($text, 'ALLE PRÜFUNGEN BESTANDEN')) { echo "KONTROLLE FEHLGESCHLAGEN: $test besteht mit unveränderter Kopie nicht\n" . substr($text, -400) . "\n"; exit(2); }
    echo "Kontrolle: $test besteht mit unveränderter Kopie\n";
}
foreach ($mutations as $i => $mu) {
    [$group, $name, $test, $fn] = $mu;
    $target = $mu[4] ?? 'M';   // 'M' = MeterHub (module.php + libs), 'D' = MeterHubDiscovery/module.php
    if ($only && $group !== $only) { continue; }
    $override = [];
    if ($target === 'D') {
        $mod = $fn($origD);
        if ($mod !== null && $mod !== $origD) { $override['MeterHubDiscovery/module.php'] = $mod; }
    } else {
        foreach ($sources as $rel => $src) {
            $mod = $fn($src);
            if ($mod !== null && $mod !== $src) { $override[$rel] = $mod; break; }
        }
    }
    if (!$override) { $jobs[] = ['name' => $name, 'na' => true]; continue; }
    $tree = tempTree();
    copyTree($root, $tree, $override);
    $out = tempnam(sys_get_temp_dir(), 'mhout');
    $env = ['MHUB_MODULE' => "$tree/MeterHub/module.php", 'MHUB_DISCOVERY' => "$tree/MeterHubDiscovery/module.php"] + getenv();
    $proc = proc_open(['php', "$root/.tools/$test"], [1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes, $root, $env);
    $jobs[] = ['name' => $name, 'proc' => $proc, 'tree' => $tree, 'out' => $out];
}
$caught = 0; $missed = []; $na = []; $crash = [];
foreach ($jobs as $j) {
    if (!empty($j['na'])) { $na[] = $j['name']; continue; }
    proc_close($j['proc']);   // wartet
    $text = (string)file_get_contents($j['out']);
    $hit = str_contains($text, 'FEHLT');
    $hardFail = !$hit && !str_contains($text, 'ALLE PRÜFUNGEN BESTANDEN');
    if ($hit) { $caught++; }
    elseif ($hardFail) { $crash[] = $j['name']; }
    else { $missed[] = $j['name']; }
    shell_exec('rm -rf ' . escapeshellarg($j['tree'])); @unlink($j['out']);
}
$total = count($jobs) - count($na);
echo "Mutationen: $total angewendet, $caught erkannt, " . count($missed) . ' nicht erkannt, ' . count($crash) . ' nur Absturz, ' . count($na) . " nicht anwendbar\n";
foreach ($missed as $m) { echo "  NICHT ERKANNT: $m\n"; }
foreach ($crash as $m) { echo "  NUR ABSTURZ (zählt nicht): $m\n"; }
foreach ($na as $m) { echo "  n/a (Fundstelle fehlt): $m\n"; }
echo ($missed || $crash) ? "NICHT ALLE MUTATIONEN ERKANNT\n" : "ALLE ANWENDBAREN MUTATIONEN ERKANNT\n";
exit(($missed || $crash) ? 1 : 0);
