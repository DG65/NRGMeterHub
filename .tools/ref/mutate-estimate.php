<?php
/**
 * Mutationsproben für den Schätzzähler (libs/MeterHubEstimate.php, MeterHubEstimate/module.php).
 *
 *   php .tools/ref/mutate-estimate.php [Namensteil]
 *
 * Jede Mutation läuft in einer Kopie der Arbeitsstruktur; erwartet wird, dass test-estimate-engine.php, test-estimate.php oder
 * test-i18n.php rot werden.
 */
$root = dirname(__DIR__, 2);
$E = 'libs/MeterHubEstimate.php';
$M = 'MeterHubEstimate/module.php';
$m = [];
$add = function (string $name, string $find, string $replace, string $file) use (&$m) { $m[] = [$name, $find, $replace, $file]; };

// --- Rechenkern
$add('Mired: Umrechnung verdreht', 'return $kelvin > 0 ? (int)round(1000000 / $kelvin) : 0;', 'return $kelvin > 0 ? (int)round(100000 / $kelvin) : 0;', $E);
$add('Kelvin: Untergrenze entfällt', '$kelvin = max(1000.0, min(40000.0, $kelvin));', '$kelvin = max(0.0, min(40000.0, $kelvin));', $E);
$add('Farbe: Rot-Kanal im Warmbereich', '$red = $t <= 66 ? 255.0 :', '$red = $t <= 70 ? 255.0 :', $E);
$add('HSV: Grünzweig', '} elseif ($max == $g) {
            $h = ($b - $r) / $d + 2;', '} elseif ($max == $g) {
            $h = ($b - $r) / $d + 1;', $E);
$add('HSV: Blauzweig', '$h = ($r - $g) / $d + 4;', '$h = ($r - $g) / $d + 3;', $E);
$add('HSV: negativer Farbton', 'if ($h < 0) {
            $h += 360;', 'if ($h < -100) {
            $h += 360;', $E);
$add('Symcon-Farbe: Kanäle vertauscht', '(($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF)', '($color & 0xFF, ($color >> 8) & 0xFF, ($color >> 16) & 0xFF)', $E);
$add('Tabelle: Kopfzeile wird mitgelesen', 'array_shift($lines);', ';', $E);
$add('Tabelle: hs liest falsche Spalte', '$out[(int)$r[0]][(int)$r[1]][(int)$r[2]] = (float)$r[3];', '$out[(int)$r[0]][(int)$r[2]][(int)$r[1]] = (float)$r[3];', $E);
$add('Tabelle: color_temp liest falsche Spalte', '$out[(int)$r[0]][(int)$r[1]] = (float)$r[2];', '$out[(int)$r[0]][(int)$r[1]] = (float)$r[1];', $E);
$add('Tabelle: Sortierung entfällt', 'ksort($out);', ';', $E);
$add('gzip: Erkennung der Kopfbytes', 'ord($bytes[0]) === 0x1f && ord($bytes[1]) === 0x8b', 'ord($bytes[0]) === 0x1f && ord($bytes[1]) === 0x8c', $E);
$add('Ablage: ohne Komprimierung', 'return base64_encode((string)gzencode($csv, 9));', 'return base64_encode($csv);', $E);
$add('Nächster Schlüssel: Gleichstand nimmt den oberen', 'return ($x - $before) <= ($after - $x) ? $before : $after;', 'return ($x - $before) < ($after - $x) ? $before : $after;', $E);
$add('Nächster Schlüssel: Anfang', "if (\$i === 0) {\n            return \$keys[0];", "if (\$i === 0) {\n            return \$keys[1] ?? \$keys[0];", $E);
$add('Nächster Schlüssel: Ende', 'return $keys[$n - 1];
        }
        $before', 'return $keys[0];
        }
        $before', $E);
$add('Helligkeit: Interpolation falsch', 'return $p0 + ($p1 - $p0) * (($bri - $b0) / ($b1 - $b0));', 'return $p0 + ($p1 - $p0) * 0.5;', $E);
$add('Helligkeit: unterhalb der Tabelle', "if (\$i === 0) {\n            return self::RowValue(\$table[\$keys[0]]", "if (\$i === 0) {\n            return self::RowValue(\$table[\$keys[\$n - 1]]", $E);
$add('Helligkeit: oberhalb der Tabelle', 'if ($i >= $n) {
            return self::RowValue($table[$keys[$n - 1]]', 'if ($i >= $n) {
            return self::RowValue($table[$keys[0]]', $E);
$add('Farbe: Sättigung vor Farbton', '$sats = self::Nearest($row, $hue);
        return (float)self::Nearest($sats, $sat);', '$sats = self::Nearest($row, $sat);
        return (float)self::Nearest($sats, $hue);', $E);
$add('Modus: Ausweichreihenfolge', "'color_temp' => ['color_temp', 'hs', 'brightness']", "'color_temp' => ['color_temp', 'brightness', 'hs']", $E);
$add('Modus: auto bevorzugt Farbe', "\$wanted = \$hasColorTemp ? 'color_temp' : (\$hasColor ? 'hs' : 'brightness');", "\$wanted = \$hasColor ? 'hs' : (\$hasColorTemp ? 'color_temp' : 'brightness');", $E);
$add('Linear: Gamma wirkt nicht', '$rel = $rel < 0 ? $rel : pow($rel, $gamma);', '$rel = $rel;', $E);
$add('Linear: sortiert nicht', 'usort($pts, fn($a, $b) => $a[0] <=> $b[0]);', ';', $E);
$add('Fest: Eigenverbrauch fehlt', "return (float)(\$profile['fixed']['power'] ?? 0) + \$standbyOn;", "return (float)(\$profile['fixed']['power'] ?? 0);", $E);
$add('Aus: nicht Standby', "if (empty(\$state['on'])) {\n            return \$standby;", "if (empty(\$state['on'])) {\n            return 0.0;", $E);
$add('Linear: ohne Wertquelle nicht Volllast', "isset(\$state['percent']) ? (float)\$state['percent'] : 100.0", "isset(\$state['percent']) ? (float)\$state['percent'] : 0.0", $E);
$add('Linear: Skala 255 ignoriert', "\$percent / 100 * \$scale", "\$percent / 100 * 100", $E);
$add('Lampe: Hellwert der Farbe nicht genutzt', "if (\$bri === null) {\n                \$bri = \$v / 100 * 255;", "if (\$bri === null) {\n                \$bri = 255;", $E);
$add('Lampe: Farbton-Skala', "(int)(\$h / 360 * 65535), (int)(\$s / 100 * 255))", "(int)(\$h / 360 * 255), (int)(\$s / 100 * 255))", $E);
$add('Lampe: Sättigungs-Skala', "(int)(\$s / 100 * 255));\n    }", "(int)(\$s / 100 * 100));\n    }", $E);
$add('Lampe: Standard-Farbtemperatur', 'public const DEFAULT_KELVIN = 2700.0;', 'public const DEFAULT_KELVIN = 6500.0;', $E);
$add('Energie: Lückengrenze strenger', '$dtSec > $maxGapSec', '$dtSec >= $maxGapSec', $E);
$add('Energie: Lücke wird überbrückt', 'if ($dtSec <= 0 || $dtSec > $maxGapSec ||', 'if ($dtSec <= 0 ||', $E);
$add('Energie: Umrechnung', 'return $energyKWh + $lastPowerW * $dtSec / 3600000.0;', 'return $energyKWh + $lastPowerW * $dtSec / 360000.0;', $E);
$add('Energie: negative Leistung zählt', '|| !is_finite($lastPowerW) || $lastPowerW < 0) {', '|| !is_finite($lastPowerW)) {', $E);
$add('Profil: Eigenverbrauch bei only_self_usage', "!empty(\$model['only_self_usage']) ? (float)(\$model['standby_power'] ?? 0) : 0.0", "false ? 0.0 : 0.0", $E);
$add('Profil: Vorlage statt Zahl', 'if ($power !== null && !is_numeric($power)) {', 'if (false) {', $E);
$add('Profil: Skala der Lichter', "\$scale = (in_array(\$attribute, ['percentage', 'volume_level'], true) || !\$isLight) ? 100 : 255;", "\$scale = 100;", $E);
$add('Profil: min_power ohne Standby', "(float)(\$cfg['min_power'] ?? \$p['standby'])", "(float)(\$cfg['min_power'] ?? 0)", $E);
$add('Profil: power_curve wird ignoriert', "if (isset(\$cfg['power_curve'])) {", "if (false) {", $E);
$add('Profil: Unterprofile erlaubt', "if ((int)(\$model['sub_profile_count'] ?? 0) > 0 || isset(\$model['sub_profile_select']) || isset(\$model['sub_profile'])) {", "if (false) {", $E);
$add('Suche: ein Wort genügt', "\$ok = true;\n                foreach (\$words as \$w) {\n                    if (!str_contains(\$text, \$w)) {\n                        \$ok = false;\n                        break;", "\$ok = false;\n                foreach (\$words as \$w) {\n                    if (str_contains(\$text, \$w)) {\n                        \$ok = true;\n                        break;", $E);
$add('Suche: Hersteller-Alias', "(array)(\$m['aliases'] ?? [])", "[]", $E);
$add('Suche: Groß-/Kleinschreibung', 'mb_strtolower(trim($query))', 'trim($query)', $E);
$add('Suche: Begrenzung', 'if (count($out) >= $limit) {', 'if (count($out) >= $limit + 100) {', $E);
$add('Suche: nicht nutzbar wird angeboten', "'supported'    => self::IndexEntrySupported(\$md),", "'supported'    => true,", $E);
$add('HTTP: Größenbegrenzung', 'if (strlen($body) + strlen($chunk) > $maxBytes) {', 'if (false) {', $E);

// Gleichwertige Mutanten (bewusst nicht aufgeführt): Helligkeit über 255 (keine Tabelle hat Punkte darüber), Linear: Wert genau auf einem
// Stützpunkt (beide Abschnitte liefern dort denselben Wert), Trennzeichen der Kalibrierung (is_numeric toleriert Leerzeichen).

// --- Modul
$add('Modul: Zählerfortschreibung ohne Lückengrenze', 'return (float)max(300, 5 * max(10, $this->ReadPropertyInteger(\'Interval\')));', 'return 1e12;', $M);
$add('Modul: Energie wird gerundet', '$this->SetValue(self::IDENT_ENERGY, $energy);', '$this->SetValue(self::IDENT_ENERGY, round($energy, 6));', $M);
$add('Modul: Zähler nutzt neue statt alter Leistung', "(float)\$this->ReadAttributeFloat('LastPower'), \$now - \$last", "\$power, \$now - \$last", $M);
$add('Modul: Zeitstempel wird nicht fortgeschrieben', "\$this->WriteAttributeFloat('LastTs', \$now);", ";", $M);
$add('Modul: Leistung wird nicht gemerkt', "\$this->WriteAttributeFloat('LastPower', \$power);", ";", $M);
$add('Modul: Mindesttakt', 'max(10, $this->ReadPropertyInteger(\'Interval\')) * 1000);
        $this->SetStatus(102);', '$this->ReadPropertyInteger(\'Interval\') * 1000);
        $this->SetStatus(102);', $M);
$add('Modul: ohne Schalter gilt an', "\$state['on'] = \$state['percent'] > 0;", "\$state['on'] = true;", $M);
$add('Modul: Schalter wird ignoriert', "\$state['on'] = (bool)\$sv;", "\$state['on'] = true;", $M);
$add('Modul: Mired-Erkennung', "(\$unit === 'auto' && (float)\$kv < 1000)", "(\$unit === 'auto' && (float)\$kv < 100)", $M);
$add('Modul: Mired wird als Kelvin gelesen', "\$state['kelvin'] = \$isMired ? (float)MHUBE_Engine::MiredToKelvin((float)\$kv) : (float)\$kv;", "\$state['kelvin'] = (float)\$kv;", $M);
$add('Modul: Helligkeit ohne Obergrenze', '$pct = max(0.0, min(100.0, (float)$bv / $max * 100));', '$pct = (float)$bv / $max * 100;', $M);
$add('Modul: Profil-Obergrenze nicht gelesen', "if (\$max > 0) {\n                return \$max;", "if (false) {\n                return \$max;", $M);
$add('Modul: Gleitkomma 0-1 nicht erkannt', "(\$value > 0 && \$value <= 1 && (int)(\$v['VariableType'] ?? 0) === VARIABLETYPE_FLOAT) ? 1.0 : 100.0", "100.0", $M);
$add('Modul: Quellen werden nicht gelöst', "@\$this->UnregisterMessage((int)\$id, VM_UPDATE);", ";", $M);
$add('Modul: Quellen werden nicht beobachtet', "\$this->RegisterMessage(\$id, VM_UPDATE);\n        }", ";\n        }", $M);
$add('Modul: deaktiviert rechnet weiter', "if (!\$this->InstanceReady() || !\$this->ReadPropertyBoolean('Active')) {\n                return;", "if (!\$this->InstanceReady()) {\n                return;", $M);
$add('Modul: Linear: Maximum unter Minimum erlaubt', "if (\$max <= \$min) {\n                    return null;", "if (false) {\n                    return null;", $M);
$add('Modul: Linear: Prüfung des Maximums', "\$this->ReadPropertyFloat('PowerMax') <= \$this->ReadPropertyFloat('PowerMin')", "false", $M);
$add('Modul: Gamma wird nicht übergeben', "\$p['linear']['gamma'] = \$this->ReadPropertyFloat('Gamma');", ";", $M);
$add('Modul: Datenbank ohne Modell geht durch', "if (\$mode === 'database' && \$this->EffectiveProfile() === null) {", "if (false) {", $M);
$add('Modul: Tabellenmodell ohne Tabellen', "if (\$p['strategy'] === 'lut' && !\$p['luts']) {", "if (false) {", $M);
$add('Modul: Suche ohne Mindestlänge', 'if (mb_strlen($query) < 2) {', 'if (false) {', $M);
$add('Modul: Laden ohne Listenprüfung', 'if ($entry === null || !MHUBE_Engine::IndexEntrySupported($entry)) {', 'if (false) {', $M);
$add('Modul: Adresse ohne Kodierung', "rawurlencode(\$parts[0]) . '/' . rawurlencode(\$parts[1])", "\$parts[0] . '/' . \$parts[1]", $M);
$add('Modul: Tabellen nicht gzip-fähig', "\$csv = MHUBE_Engine::CsvText(\$f['body']);", "\$csv = \$f['body'];", $M);
$add('Modul: Klartext-Ausweichdatei fehlt', "foreach (['.csv.gz', '.csv'] as \$ext) {", "foreach (['.csv.gz'] as \$ext) {", $M);
$add('Modul: leere Tabelle gilt als geladen', "if (MHUBE_Engine::ParseLut(\$csv, \$mode)) {", "if (true) {", $M);
$add('Modul: ohne Tabellen trotzdem gespeichert', "if (!\$luts) {\n                return MHUBE_Text::t('❌ The measurement tables", "if (false) {\n                return MHUBE_Text::t('❌ The measurement tables", $M);
$add('Modul: Index nur ein Tag gültig', 'time() - $ts < 86400', 'true', $M);
$add('Modul: Fehlerantwort wird als Liste gelesen', "if (\$r['code'] !== 200) {\n            \$error", "if (false) {\n            \$error", $M);
$add('Modul: Vertrag: gemessen', "'measured'       => false,", "'measured'       => true,", $M);
$add('Modul: Vertrag: Energie gemessen', "'energyMeasured' => false,\n                'energyKind'", "'energyMeasured' => true,\n                'energyKind'", $M);
$add('Modul: Vertrag: Instanz-Ebene gemessen', "'energyMeasured'  => false,\n            'members'", "'energyMeasured'  => true,\n            'members'", $M);
$add('Modul: Vertrag: Zählerart', "'meter'           => 'estimate',", "'meter'           => 'virtual',", $M);
$add('Modul: Vertrag: Energie-ID', "'energyImportID' => \$this->OutputId(self::IDENT_ENERGY),", "'energyImportID' => \$this->OutputId(self::IDENT_POWER),", $M);
$add('Modul: Archiv: Zählertyp', '$wanted = $counter ? 1 : 0;', '$wanted = 0;', $M);
$add('Modul: Archiv: Neuaggregation', 'if ($old !== $wanted) {
            $this->ReAggregate($ac, $vid);', 'if (false) {
            $this->ReAggregate($ac, $vid);', $M);
$add('Modul: Archiv: schaltet selbst ein', "if (\$ac <= 0 || !@AC_GetLoggingStatus(\$ac, \$vid)) {\n            return;\n        }", "if (\$ac <= 0) {\n            return;\n        }\n        AC_SetLoggingStatus(\$ac, \$vid, true);", $M);
$add('Modul: Archiv: Hinweis nur Leistung', "foreach (\$this->OutputDefs() as [\$ident, , , \$counter]) {\n            \$vid = \$this->OutputId(\$ident);\n            if (\$vid &&", "foreach (array_slice(\$this->OutputDefs(), 0, 1) as [\$ident, , , \$counter]) {\n            \$vid = \$this->OutputId(\$ident);\n            if (\$vid &&", $M);
$add('Modul: eigenes Profil gesetzt', "\$this->RegisterVariableFloat(\$ident, \$caption, ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => \$profile], \$pos);", "\$this->RegisterVariableFloat(\$ident, \$caption, \$profile, \$pos);", $M);
$add('Modul: Sichtbarkeit der Betriebsart', "'ModelLinear'   => \$mode === 'linear',", "'ModelLinear'   => \$mode === 'fixed',", $M);
$add('Modul: Standby bei Datenbank sichtbar', "'StandbyPower'  => \$mode !== 'database',", "'StandbyPower'  => true,", $M);
$add('Modul: Deaktiviert-Status', "\$this->SetTimerInterval('Update', 0);\n            \$this->SetStatus(104);", "\$this->SetStatus(104);", $M);
$add('Modul: Kernelstart wendet nicht an', "if (IPS_InstanceExists(\$this->InstanceID)) {\n                \$this->ApplyChanges();", "if (false) {\n                \$this->ApplyChanges();", $M);
$add('Modul: Umbenennen', "IPS_SetName(\$this->InstanceID, \$name);\n        }\n    }", ";\n        }\n    }", $M);
$add('Modul: Zweck-Hinweis wird nicht gemerkt', "\$this->WriteAttributeBoolean('PurposeIntroGone', true);\n        \$this->UpdateFormField('PurposeIntroPanel', 'visible', false);\n        \$this->PropagateDismiss('PurposeIntro');", "\$this->UpdateFormField('PurposeIntroPanel', 'visible', false);\n        \$this->PropagateDismiss('PurposeIntro');", $M);

$add('Modul: Forum-Hinweis ohne Verknüpfung', "['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => \"echo '\" . self::FORUM_THREAD_URL . \"';\", 'link' => true],\n", "", $M);
$add('Modul: Forum-Verknüpfung zeigt auf Powercalc', "\"echo '\" . self::FORUM_THREAD_URL . \"';\"", "\"echo '\" . self::POWERCALC_URL . \"';\"", $M);
$tests = ['test-estimate-engine.php', 'test-estimate.php', 'test-i18n.php'];
function copyAll(string $root, string $dest, string $target, string $content): void
{
    foreach (['MeterHub', 'MeterHubVirtual', 'MeterHubDiscovery', 'MeterHubBridge', 'MeterHubMqttBridge', 'MeterHubEstimate', 'libs', '.tools', '.tools/fixtures', '.tools/fixtures/powercalc'] as $dir) {
        @mkdir("$dest/$dir", 0777, true);
        foreach (glob("$root/$dir/*") as $f) { if (is_file($f)) { copy($f, "$dest/$dir/" . basename($f)); } }
    }
    file_put_contents("$dest/$target", $content);
}
$ok = function (string $out): bool { return (bool)preg_match('/ALLE PRÜFUNGEN BESTANDEN/u', $out); };
$tree = sys_get_temp_dir() . '/mhest_' . getmypid() . '_ctl';
copyAll($root, $tree, $E, file_get_contents("$root/$E"));
foreach ($tests as $t) {
    $out = (string)shell_exec('cd ' . escapeshellarg($tree) . ' && php ' . escapeshellarg(".tools/$t") . ' 2>&1');
    if (!$ok($out)) { echo "KONTROLLE FEHLGESCHLAGEN: $t\n" . substr($out, -300) . "\n"; shell_exec('rm -rf ' . escapeshellarg($tree)); exit(2); }
}
shell_exec('rm -rf ' . escapeshellarg($tree));
echo 'Kontrolle: ' . count($tests) . " Prüfstände bestehen mit unveränderter Kopie\n";
$caught = 0; $missed = []; $na = [];
$only = $argv[1] ?? null;
foreach ($m as $i => [$name, $find, $replace, $file]) {
    if ($only !== null && stripos($name, $only) === false) { continue; }
    $orig = file_get_contents("$root/$file");
    if (substr_count($orig, $find) !== 1) { $na[] = $name . ' (Fundstellen: ' . substr_count($orig, $find) . ')'; continue; }
    $tree = sys_get_temp_dir() . '/mhest_' . getmypid() . '_' . $i;
    copyAll($root, $tree, $file, str_replace($find, $replace, $orig));
    $hit = null;
    foreach ($tests as $t) {
        $out = (string)shell_exec('cd ' . escapeshellarg($tree) . ' && php ' . escapeshellarg(".tools/$t") . ' 2>&1');
        if (!$ok($out)) { $hit = $t; break; }
    }
    shell_exec('rm -rf ' . escapeshellarg($tree));
    if ($hit) { $caught++; echo "  erkannt ($hit)  $name\n"; } else { $missed[] = $name; echo "  NICHT ERKANNT  $name\n"; }
}
echo 'Mutationen: ' . ($caught + count($missed)) . " angewendet, $caught erkannt, " . count($missed) . ' nicht erkannt, ' . count($na) . " nicht anwendbar\n";
foreach ($na as $n) { echo "  n/a: $n\n"; }
echo ($missed || $na) ? "NICHT ALLE MUTATIONEN ERKANNT\n" : "ALLE MUTATIONEN ERKANNT\n";
exit(($missed || $na) ? 1 : 0);
