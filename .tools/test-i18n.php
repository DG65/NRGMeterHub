<?php
/**
 * Statischer Prüfstand für die Zweisprachigkeit (SUITE.md Punkt 9, Store-Checkliste 25).
 *  - locale.json gültig, nur "de" (Quelltexte im Code sind Englisch)
 *  - jeder Text, der über MHUB*_Text::t() läuft, steht in locale.json
 *  - kein deutscher Text mehr im Code (Wortschatz aus den Übersetzungen selbst abgeleitet)
 *  - keine toten Einträge in locale.json
 *  - Platzhalter (%s, %d) stimmen zwischen Englisch und Deutsch überein
 */
$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $GLOBALS['fails']++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
$mods = ['MeterHub' => 'MHUB', 'MeterHubVirtual' => 'MHUBV', 'MeterHubDiscovery' => 'MHUBD', 'MeterHubBridge' => 'MHUBB'];
$root = dirname(__DIR__);
$dec = function (string $tok): string {
    $q = $tok[0]; $body = substr($tok, 1, -1);
    return $q === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body) : stripcslashes($body);
};
// Wörter, die deutsch klingen, aber erlaubt sind (Eigennamen, Technik) und Literale, die bewusst deutsch bleiben
$allowWords = ['modbus', 'symcon', 'meterhub', 'inexogy', 'discovergy', 'janitza', 'siemens', 'eastron', 'shelly', 'phoenix', 'whatwatt',
    'socomec', 'symbox', 'scada', 'power', 'control', 'float', 'gateway', 'community', 'forum', 'watt', 'kwh', 'blue'];
// Bewusst deutsche Literale: Suchbegriffe, mit denen Objektnamen deutscher Anlagen erkannt werden — keine Anzeigetexte
$keepGerman = [
    // Suchbegriffe/Muster, mit denen deutsche Objektnamen erkannt werden (kein Anzeigetext)
    'wirkleistung', 'hauptzähler kwh', 'hauptzaehler kwh', 'wirkleistung p14 (w)', 'wirkleistung p23 (w)', 'wirkenergie a14 (kwh)', 'wirkenergie a23 (kwh)',   // Suchmuster für deutsche KNX-Instanznamen
    '/session|sitzung|ladevorgang|zwischen|heute|today|tages/u',
    // interne Schlüssel von Zählern/Zusammenfassungen
    'relais', 'akku', 'relais_power', 'akku_power', 'relais_energy_import', 'relais_energy_export', 'akku_energy_import', 'akku_energy_export',
    'energie', 'leistung', 'meterhub_sicherung_',   // Idents der go-e-Kategorien, Dateinamen der Sicherungen (technische Kennungen)
    'einheit', 'bereich', 'funktion', 'virtuell', 'verbund', 'schonverwendet', 'andereinstanz', 'ohneenergie', 'inaktiv',
];
$wd = fn(string $s) => array_unique(array_map('mb_strtolower', preg_match_all('/[A-Za-zÄÖÜäöüß]{4,}/u', $s, $m) ? $m[0] : []));

$allDe = []; $allEn = [];
$loc = [];
foreach ($mods as $m => $p) {
    $f = "$root/$m/locale.json";
    $j = json_decode((string)file_get_contents($f), true);
    check("$m: locale.json ist gültiges JSON mit translations.de", is_array($j['translations']['de'] ?? null) && count(($j['translations'] ?? [])) === 1);
    $loc[$m] = $j['translations']['de'] ?? [];
    foreach ($loc[$m] as $en => $de) { foreach ($wd($de) as $w) { $allDe[$w] = true; } foreach ($wd($en) as $w) { $allEn[$w] = true; } }
}
// Wortschatz der fremden Schwestermodule dazunehmen (breitere deutsche Abdeckung)
foreach (glob("$root/../*/*/locale.json") as $f) {
    if (strpos($f, '/MeterHub') !== false) { continue; }
    $j = json_decode((string)file_get_contents($f), true);
    foreach (($j['translations']['de'] ?? []) as $en => $de) { foreach ($wd($de) as $w) { $allDe[$w] = true; } foreach ($wd($en) as $w) { $allEn[$w] = true; } }
}
$germanOnly = array_diff_key($allDe, $allEn, array_flip($allowWords));
// Deutsche Fachwörter, die absichtlich in englischen Texten als Muster zitiert werden (KNX-Namensmuster „…Wirkleistung“): zählen trotzdem als deutsch
foreach (['wirkleistung', 'wirkenergie', 'hauptzähler', 'hauptzaehler'] as $w) { $germanOnly[$w] = true; }

foreach ($mods as $m => $p) {
    echo "\n$m\n";
    $src = file_get_contents("$root/$m/module.php");
    $tokens = token_get_all($src);
    $lits = []; $tCalls = [];
    $n = count($tokens);
    // Entwickler-Texte (SendDebug, Exceptions) bleiben deutsch und werden nicht geprüft
    $skipUntil = -1; $depth = 0;
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) { if ($skipUntil >= 0) { if ($t === '(') { $depth++; } if ($t === ')') { $depth--; if ($depth <= 0) { $skipUntil = -1; } } } continue; }
        if ($skipUntil < 0 && $t[0] === T_STRING && in_array($t[1], ['SendDebug', 'Exception', 'InvalidArgumentException', 'RuntimeException'], true)) { $skipUntil = $i; $depth = 0; continue; }
        if ($skipUntil >= 0) { continue; }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) { $lits[] = [$dec($t[1]), $t[2]]; }
        if ($t[0] === T_ENCAPSED_AND_WHITESPACE) { $lits[] = [$t[1], $t[2]]; }
        // MHUB*_Text::t('…')
        if ($t[0] === T_STRING && $t[1] === $p . '_Text') {
            $j = $i + 1; $seq = '';
            while ($j < $n && $j < $i + 6) { $seq .= is_array($tokens[$j]) ? ($tokens[$j][0] === T_WHITESPACE ? '' : $tokens[$j][1]) : $tokens[$j]; if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) { $tCalls[] = [$dec($tokens[$j][1]), $tokens[$j][2]]; break; } $j++; }
        }
    }
    $de = $loc[$m];
    // 1) Jeder t()-Text steht in locale.json
    $miss = [];
    foreach ($tCalls as [$txt, $line]) { if (!isset($de[$txt])) { $miss[$txt] = $line; } }
    $keysMiss = array_keys($miss);
    check("$m: jeder Text in {$p}_Text::t() steht in locale.json", !$miss, count($miss) . ' fehlen, z. B. ' . substr(json_encode(array_slice($keysMiss, 0, 3), JSON_UNESCAPED_UNICODE), 0, 220));
    // 2) kein deutscher Text mehr im Code
    $german = [];
    foreach ($lits as [$txt, $line]) {
        if (isset($de[$txt]) || strpos($txt, 'http') === 0 || in_array($txt, $keepGerman, true)) { continue; }
        $hit = array_filter($wd($txt), fn($w) => isset($germanOnly[$w]));
        if ($hit || preg_match('/[äöüÄÖÜß]/u', $txt)) { $german[$line] = $txt; }
    }
    $ex = [];
    foreach (array_slice($german, 0, 4, true) as $l => $tx) { $ex[] = "$l: " . mb_substr($tx, 0, 60); }
    check("$m: kein deutscher Text mehr im Code", !$german, count($german) . ' verdächtig: ' . implode(' | ', $ex));
    // 3) tote Einträge
    $litset = []; foreach ($lits as [$txt]) { $litset[$txt] = true; }
    $dead = array_filter(array_keys($de), fn($k) => !isset($litset[$k]));
    check("$m: keine toten Einträge in locale.json", !$dead, count($dead) . ' tot, z. B. ' . substr(json_encode(array_slice(array_values($dead), 0, 2), JSON_UNESCAPED_UNICODE), 0, 160));
    // 4) Platzhalter
    $bad = [];
    foreach ($de as $en => $d) {
        $pe = preg_match_all('/%(?:\d+\$)?[sdf]/', $en, $a) ? $a[0] : [];
        $pd = preg_match_all('/%(?:\d+\$)?[sdf]/', $d, $b) ? $b[0] : [];
        sort($pe); sort($pd);
        if ($pe !== $pd) { $bad[] = mb_substr($en, 0, 60); }
    }
    check("$m: Platzhalter (%s/%d) in Englisch und Deutsch gleich", !$bad, implode(' | ', array_slice($bad, 0, 3)));
}
echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
