<?php
/**
 * Sammelt die deutschen Texte aus den module.php-Dateien (Hilfswerkzeug für die Zweisprachigkeit, SUITE.md Punkt 9).
 * Aufruf: php .tools/i18n-extract.php [--all]   →  JSON-Liste auf stdout
 * Je Eintrag: file, line, kind (plain|concat|interp), text, ctx (die letzten Tokens davor).
 * "Deutsch" wird heuristisch erkannt (Umlaute, ß, häufige deutsche Wörter); --all liefert jedes Textliteral.
 */
$all = in_array('--all', $argv, true);
$files = ['MeterHub', 'MeterHubVirtual', 'MeterHubDiscovery', 'MeterHubBridge'];
const GERMAN_WORDS = '/\b(der|die|das|den|dem|des|und|oder|nicht|wird|werden|ist|sind|für|mit|ein|eine|einen|einer|bitte|Zähler|Fehler|Wert|Werte|Instanz|keine|kein|noch|nur|wenn|auch|aber|beim|nach|von|zum|zur|dieser|diese|dieses|alle|aktiv|Verbindung|Leistung|Bezug|Einspeisung|Archiv|Formular|Übernehmen|Hinweis|Gerät|Variable|Variablen|Mitglieder|Zeile|Zeilen|bereits|jetzt|neu|nichts|wurde|wurden|zuerst|danach|muss|kann|im|am|auf|aus|bei|als|über|unter)\b/u';
$out = [];
foreach ($files as $m) {
    $path = __DIR__ . "/../$m/module.php";
    $tokens = token_get_all(file_get_contents($path));
    $n = count($tokens);
    $sig = []; // Indizes signifikanter Tokens
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $sig[] = $i;
    }
    $pos = array_flip($sig);
    $line = 1;
    foreach ($sig as $k => $i) {
        $t = $tokens[$i];
        if (!is_array($t)) { continue; }
        $text = null; $kind = 'plain';
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $q = $t[1][0];
            $body = substr($t[1], 1, -1);
            $text = $q === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body) : stripcslashes($body);
        } elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
            $text = $t[1]; $kind = 'interp';
        }
        if ($text === null) { continue; }
        $isText = preg_match('/\p{L}/u', $text) && (preg_match('/[äöüÄÖÜß]/u', $text) || preg_match(GERMAN_WORDS, $text));
        if (!$all && !$isText) { continue; }
        // Verkettung?
        $prev = $sig[$k - 1] ?? null; $next = $sig[$k + 1] ?? null;
        $isDot = fn($idx) => $idx !== null && !is_array($tokens[$idx]) && $tokens[$idx] === '.';
        if ($kind === 'plain' && ($isDot($prev) || $isDot($next))) { $kind = 'concat'; }
        $ctx = [];
        for ($j = max(0, $k - 3); $j < $k; $j++) { $x = $tokens[$sig[$j]]; $ctx[] = is_array($x) ? $x[1] : $x; }
        $out[] = ['file' => $m, 'line' => $t[2], 'kind' => $kind, 'text' => $text, 'ctx' => implode(' ', $ctx)];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
