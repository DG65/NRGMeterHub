<?php
/**
 * check-class-scope.php — findet Methodenaufrufe über Klassengrenzen hinweg.
 *
 * Anlass: In einer Datei mit mehreren Klassen (z. B. ein Treiber je Hersteller)
 * sind Codeblöcke oft wortgleich. Ein Textersatz trifft dann den erstbesten
 * Treffer statt den gemeinten — real passiert: eine Methode wurde im SMA-Treiber
 * definiert, aber aus dem Fronius-Treiber aufgerufen. Das ergibt einen FATAL
 * ERROR in jedem Lesezyklus, und zwar erst zur Laufzeit beim betroffenen Gerät.
 * Ein Syntaxcheck (php -l) sieht das nicht.
 *
 * Gemeldet wird ein `$this->foo()`, wenn `foo()` in DIESER Klasse (und ihren
 * Vorfahren innerhalb derselben Datei) fehlt, aber in einer ANDEREN Klasse der
 * Datei existiert. Diese Einschränkung hält die Fehlalarme fern: Von IPSModule
 * geerbte Methoden sind nirgends in der Datei definiert und werden daher nicht
 * angemeckert.
 *
 * Aufruf:  php .tools/check-class-scope.php [Pfad ...]
 * Rückgabe: 0 = sauber, 1 = mindestens ein klassenfremder Aufruf
 */

$targets = array_slice($argv, 1);
if (!$targets) {
    $targets = [dirname(__DIR__)];
}

/** Alle PHP-Dateien einsammeln; das Verzeichnis dieses Skripts bleibt außen vor. */
function phpFiles(array $targets): array
{
    $self = realpath(__DIR__) . DIRECTORY_SEPARATOR;
    $out  = [];
    foreach ($targets as $t) {
        if (is_file($t)) { $out[] = $t; continue; }
        if (!is_dir($t)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (substr($p, -4) !== '.php') { continue; }
            if (strpos($p, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) { continue; }
            if (strpos(realpath($p) ?: $p, $self) === 0) { continue; }
            $out[] = $p;
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

/**
 * Zerlegt eine Datei in Klassen. Rückgabe je Klasse:
 * ['name','extends','methods'=>[name=>zeile],'calls'=>[[name,zeile]]]
 * Grundlage sind PHP-Token, nicht Textsuche — Kommentare und Zeichenketten
 * können dadurch keine Fehltreffer erzeugen.
 */
function classesOf(string $code): array
{
    $tok     = token_get_all($code);
    $classes = [];
    $cur     = null;
    $depth   = 0;      // Klammertiefe innerhalb der aktuellen Klasse
    $n       = count($tok);

    for ($i = 0; $i < $n; $i++) {
        $t = $tok[$i];

        if (is_array($t) && $t[0] === T_CLASS) {
            // Klassennamen und optionales extends einsammeln
            $name = ''; $ext = '';
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tok[$j]) && $tok[$j][0] === T_STRING) { $name = $tok[$j][1]; break; }
            }
            for ($j = $i + 1; $j < $n && $tok[$j] !== '{'; $j++) {
                if (is_array($tok[$j]) && $tok[$j][0] === T_EXTENDS) {
                    for ($k = $j + 1; $k < $n; $k++) {
                        if (is_array($tok[$k]) && $tok[$k][0] === T_STRING) { $ext = $tok[$k][1]; break; }
                    }
                    break;
                }
            }
            $cur = ['name' => $name, 'extends' => $ext, 'methods' => [], 'calls' => []];
            // bis zur öffnenden Klammer vorspulen
            while ($i < $n && $tok[$i] !== '{') { $i++; }
            $depth = 1;
            continue;
        }

        if ($cur === null) { continue; }

        if ($t === '{') { $depth++; continue; }
        if ($t === '}') {
            $depth--;
            if ($depth === 0) { $classes[] = $cur; $cur = null; }
            continue;
        }

        // Methodendefinition
        if (is_array($t) && $t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tok[$j]) && $tok[$j][0] === T_STRING) {
                    $cur['methods'][$tok[$j][1]] = $t[2];
                    break;
                }
                if ($tok[$j] === '(') { break; }   // anonyme Funktion
            }
            continue;
        }

        // Aufruf $this->foo(
        if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$this'
            && isset($tok[$i + 1]) && is_array($tok[$i + 1]) && $tok[$i + 1][0] === T_OBJECT_OPERATOR
            && isset($tok[$i + 2]) && is_array($tok[$i + 2]) && $tok[$i + 2][0] === T_STRING) {
            $k = $i + 3;
            while ($k < $n && is_array($tok[$k]) && $tok[$k][0] === T_WHITESPACE) { $k++; }
            if (isset($tok[$k]) && $tok[$k] === '(') {
                $cur['calls'][] = [$tok[$i + 2][1], $t[2]];
            }
        }
    }
    return $classes;
}

$files = phpFiles($targets);
$findings = [];
$checked  = 0;

foreach ($files as $file) {
    $classes = classesOf(file_get_contents($file));
    if (count($classes) < 2) {
        continue;   // ohne zweite Klasse ist eine Verwechslung nicht möglich
    }
    $byName = [];
    foreach ($classes as $c) { $byName[$c['name']] = $c; }

    foreach ($classes as $c) {
        // Eigene Methoden plus die der Vorfahren INNERHALB dieser Datei
        $own = $c['methods'];
        $p   = $c['extends'];
        $seen = [];
        while ($p !== '' && isset($byName[$p]) && !isset($seen[$p])) {
            $seen[$p] = true;
            $own += $byName[$p]['methods'];
            $p = $byName[$p]['extends'];
        }
        foreach ($c['calls'] as [$m, $line]) {
            $checked++;
            if (isset($own[$m])) { continue; }
            // Nur melden, wenn es die Methode woanders in der Datei GIBT.
            foreach ($classes as $other) {
                if ($other['name'] !== $c['name'] && isset($other['methods'][$m])) {
                    $findings[] = [$file, $c['name'], $m, $line, $other['name'], $other['methods'][$m]];
                    break;
                }
            }
        }
    }
}

echo "Klassengrenzen-Prüfung — Methodenaufrufe über Klassen hinweg\n";
echo str_repeat('-', 62) . "\n";
printf("Dateien: %d | geprüfte \$this->-Aufrufe: %d\n\n", count($files), $checked);

if (!$findings) {
    echo "OK — jeder Aufruf liegt in der Klasse, die die Methode definiert.\n";
    exit(0);
}

printf("FEHLER — %d Aufruf(e) über Klassengrenzen:\n\n", count($findings));
foreach ($findings as [$file, $cls, $m, $line, $other, $oline]) {
    $rel = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file);
    echo "  $rel\n";
    echo "      $cls ruft in Zeile $line \$this->$m() auf,\n";
    echo "      definiert ist die Methode aber in $other (Zeile $oline).\n\n";
}
echo "Das ergibt zur Laufzeit einen Fatal Error, sobald der Zweig ausgeführt\n";
echo "wird. Meist Folge eines Textersatzes, der in wortgleichem Code die\n";
echo "falsche Klasse getroffen hat.\n";
exit(1);
