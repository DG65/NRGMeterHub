<?php
/**
 * Mutationsproben für die AUFRUFSTELLEN der Zählerart-Weichen (Umbau 4, 0.39.8): an jeder Stelle, die IsCloudMeter(), IsHttpMeter(),
 * IsMqttMeter(), IsWritableMeter(), HasRpcValidTime() oder IsSinglePhaseType() fragt, wird die Antwort umgedreht. Mindestens ein
 * Prüfstand muss rot werden; wer überlebt, zeigt eine Stelle, die kein Test absichert.
 *
 *   php .tools/ref/mutate-weichen-stellen.php            # alle, 6 parallel
 *   php .tools/ref/mutate-weichen-stellen.php run 7      # eine Mutation (intern)
 */
$root = dirname(__DIR__, 2);
$files = ['MeterHub/module.php', 'libs/MeterHubInexogy.php', 'libs/MeterHubArchive.php'];
$re = '/\$this->(IsCloudMeter|IsHttpMeter|IsMqttMeter|IsWritableMeter|HasRpcValidTime|IsSinglePhaseType)\(([^()]*)\)/';
$sites = [];
foreach ($files as $f) {
    $src = file_get_contents("$root/$f");
    // Rumpf der Prädikate selbst ausnehmen: Methodenköpfe stehen als "function Name(", Aufrufe als "$this->Name("
    preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as $i => $hit) {
        $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
        $sites[] = [$f, $hit[1], strlen($hit[0]), $hit[0], $line];
    }
}
$tests = array_map('basename', glob("$root/.tools/test-*.php"));
if (($argv[1] ?? '') === 'run') {
    [$f, $off, $len, $text, $line] = $sites[(int)$argv[2]];
    $tree = sys_get_temp_dir() . '/mhsite_' . getmypid();
    foreach (['MeterHub', 'MeterHubVirtual', 'MeterHubDiscovery', 'MeterHubBridge', 'MeterHubMqttBridge', 'libs', '.tools', '.tools/ref'] as $dir) {
        @mkdir("$tree/$dir", 0777, true);
        foreach (glob("$root/$dir/*") as $g) { if (is_file($g)) { copy($g, "$tree/$dir/" . basename($g)); } }
    }
    $src = file_get_contents("$root/$f");
    file_put_contents("$tree/$f", substr($src, 0, $off) . '(!' . $text . ')' . substr($src, $off + $len));
    $hit = null;
    foreach ($tests as $t) {
        $out = (string)shell_exec('cd ' . escapeshellarg($tree) . ' && php ' . escapeshellarg(".tools/$t") . ' 2>&1');
        if (!preg_match('/ALLE PRÜFUNGEN BESTANDEN|Alle Prüfungen bestanden/u', $out)) { $hit = $t; break; }
    }
    shell_exec('rm -rf ' . escapeshellarg($tree));
    echo ($hit ? "erkannt ($hit)" : 'NICHT ERKANNT') . "  $f:$line  $text\n";
    exit(0);
}
echo count($sites) . " Stellen\n";
$out = [];
exec('seq 0 ' . (count($sites) - 1) . ' | xargs -P 6 -I{} php ' . escapeshellarg(__FILE__) . ' run {}', $out);
sort($out);
echo implode("\n", $out) . "\n";
$missed = array_filter($out, fn($l) => str_starts_with($l, 'NICHT'));
echo count($out) - count($missed) . ' erkannt, ' . count($missed) . " nicht erkannt\n";
echo $missed ? "NICHT ALLE STELLEN ABGESICHERT\n" : "ALLE STELLEN ABGESICHERT\n";
exit($missed ? 1 : 0);
