<?php
/**
 * Mutationsproben für die gemeinsamen Bausteine in libs/MeterHubShared.php (Umbau 3, 0.39.7).
 *
 *   php .tools/ref/mutate-shared.php
 *
 * Die Traits werden von drei Modulen und zwei Modbus-Clients eingebunden und mehrere Prüfstände laden diese Module gemeinsam in einem
 * Prozess. Darum läuft jede Mutation in einer Kopie der ganzen Arbeitsstruktur (Module, libs, .tools): der Prüfstand der Kopie
 * lädt dann überall die veränderte Datei. Erwartet wird, dass mindestens ein Prüfstand rot wird.
 */
$root = dirname(__DIR__, 2);
$target = 'libs/MeterHubShared.php';
$orig = file_get_contents("$root/$target");
$m = [];
$add = function (string $name, string $find, string $replace, ?string $file = null) use (&$m, $target) { $m[] = [$name, $find, $replace, $file ?? $target]; };
// Registerdekodierung
$add('Codec: u16 maskiert falsch', '$regs[$offset] & 0xFFFF', '$regs[$offset] & 0x7FFF');
$add('Codec: u32 Wortbreite', '$this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1)', '$this->u16($regs, $offset) << 15) | $this->u16($regs, $offset + 1)');
$add('Codec: s32 Vorzeichengrenze', '$v > 2147483647 ? $v - 4294967296 : $v;
    }

    // 32-Bit mit getauschter', '$v > 2147483648 ? $v - 4294967296 : $v;
    }

    // 32-Bit mit getauschter');
$add('Codec: u32sw nicht getauscht', '$this->u16($regs, $offset + 1) << 16) | $this->u16($regs, $offset)', '$this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1)');
$add('Codec: s32sw ohne Vorzeichen', '$v = $this->u32sw($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;', '$v = $this->u32sw($regs, $offset);
        return $v;');
$add('Codec: readFloat32 ignoriert Wortreihenfolge', "if (\$this->wordSwap) {\n            \$tmp = \$w0;", "if (false) {\n            \$tmp = \$w0;");
$add('Codec: readFloat32 Byte-Reihenfolge', "unpack('G', \$raw)", "unpack('g', \$raw)");
$add('Codec: readDouble64 ignoriert Wortreihenfolge', '$w = array_reverse($w);', ';');
$add('Codec: packFloat32 ignoriert Wortreihenfolge', 'return $this->wordSwap ? [$w1, $w0] : [$w0, $w1];', 'return [$w0, $w1];');
// Fachpanels und Lizenzhinweis
$add('Aufbau: ausgeblendet hat nicht mehr Vorrang', "!empty(\$r['Hidden']) ? 'hidden' : (!empty(\$r['Closed']) ? 'closed'", "!empty(\$r['Closed']) ? 'closed' : (!empty(\$r['Hidden']) ? 'hidden'");
$add('Aufbau: Sortierung vertauscht Position und Reihenfolge', "[\$a['pos'], \$a['idx']] <=> [\$b['pos'], \$b['idx']]", "[\$a['idx'], \$a['pos']] <=> [\$b['idx'], \$b['pos']]");
$add('Aufbau: Position wird nicht begrenzt', 'max(1, min(99, (int)$r[\'Pos\']))', '(int)$r[\'Pos\']');
$add('Aufbau: ausgeblendete Panels bleiben sichtbar', "\$panel['visible'] = false;", ';');
$add('Aufbau: Aufgeklappt-Wunsch entfällt', "if (\$l['open'] === 'open') {\n                \$panel['expanded'] = true;", "if (\$l['open'] === 'open') {\n                \$panel['expanded'] = false;");
$add('Aufbau: gespeicherte Reihenfolge wird ignoriert', "\$saved = json_decode((string)\$this->ReadPropertyString('FormLayout'), true);", "\$saved = [];");
$add('Lizenzhinweis: Verknüpfung zeigt auf PayPal', "\"echo '\" . self::LICENSE_URL . \"';\"", "\"echo '\" . self::PAYPAL_URL . \"';\"");
$add('Lizenzhinweis: Panel startet aufgeklappt', "'type' => 'ExpansionPanel', 'expanded' => false,\n            'caption' => '🧡", "'type' => 'ExpansionPanel', 'expanded' => true,\n            'caption' => '🧡");
// Instanz-Bausteine
$add('Bereitschaft: Laufstufe wird nicht geprüft', "IPS_GetKernelRunlevel() !== KR_READY", 'false');
$add('Bereitschaft: fehlende Instanz wird nicht erkannt', '!IPS_InstanceExists($this->InstanceID)', 'false');
$add('Bereitschaft: Meldung der Instanz wird übersehen', "return \$e === null || !str_contains((string)(\$e['message'] ?? ''), 'InstanceInterface');", 'return true;');
$add('Archiv-Instanz: immer 0', 'return count($ids) > 0 ? (int)$ids[0] : 0;', 'return 0;');
$add('Archiv-Instanz: ohne Archivfunktion nicht 0', "if (!function_exists('AC_GetLoggingStatus')) {\n            return 0;\n        }", ';');
$add('Verdichtungsmerker: wird nicht geschrieben', "\$this->WriteAttributeBoolean('CompactionApplied' . \$kind, \$on);", ';');
$add('Verdichtungsauswahl: Typ „löschen“ falsch', "'value' => 7", "'value' => 6");

// Bausteine mit Haken der Klasse (0.39.9): Fachpanel-Aufbau, Ausblenden über Geschwister, Verdichtungsplan
$add('Panelname: Klammerzusatz bleibt stehen', "'/\\s*\\(.*$/u'", "'/\\s*\\(zzz.*$/u'");
$add('Aufbau-Panel: Symbole bleiben im Hinweis', "\$hidden[] = trim((string)preg_replace('/^[^\\p{L}\\p{N}]+/u', '', \$name));", "\$hidden[] = \$name;");
$add('Aufbau-Panel: Zurücksetzen ohne Präfix der Klasse', "'echo ' . \$this->fnPrefix() . '_ResetFormLayout(\$id);'", "'echo MHUB_ResetFormLayout(\$id);'");
$add('Aufbau-Panel: Ausgeblendet-Spalte zeigt zugeklappt', "'Hidden' => \$l['open'] === 'hidden'", "'Hidden' => \$l['open'] === 'closed'");
$add('Ausblenden teilen: Weitergabe entfällt', "(\$this->fnPrefix() . '_AdoptDismissState')(\$sib, \$what, \$value);", ';');
$add('Ausblenden teilen: Fehler einer Instanz reißt mit', "} catch (\\Throwable \$e) {\n                // Eine Geschwister-Instanz", "} catch (\\LogicException \$e) {\n                // Eine Geschwister-Instanz");
$add('Übernahme: Geschwister werden nicht gefunden', "foreach (IPS_GetInstanceListByModuleID(\$this->siblingModuleGuid()) as \$sib) {\n            if (\$sib === \$this->InstanceID) {\n                continue;\n            }\n            try {\n                \$state", "foreach ([] as \$sib) {\n            if (\$sib === \$this->InstanceID) {\n                continue;\n            }\n            try {\n                \$state");
$add('Übernahme: alle Geschwister statt der ersten', "\$this->WriteAttributeString('SeenNews', self::NEWS_VERSION);\n            }\n            break;", "\$this->WriteAttributeString('SeenNews', self::NEWS_VERSION);\n            }");
$add('Übernahme: Frühausstieg bei bestätigtem Stand entfällt', "&& \$this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {\n            return;", "&& \$this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION) {\n            return;");
$add('Übernahme: Nicht-Array wird nicht übersprungen', "if (!is_array(\$state)) {\n                continue;", "if (false) {\n                continue;");
$add('Verdichtungsplan: Gleichstand zählt als gröber', "self::COMPACTION_SECONDS[\$type] > \$intervalSeconds", "self::COMPACTION_SECONDS[\$type] >= \$intervalSeconds");
$add('Verdichtungsplan: „löschen“ hängt am Intervall', "\$type === 7 || self::COMPACTION_SECONDS", "false || self::COMPACTION_SECONDS");
$add('Verdichtungsplan: nie angewendet setzt trotzdem zurück', "if (!\$this->ReadAttributeBoolean('CompactionApplied' . \$kind)) {\n                return [];", "if (false) {\n                return [];");
$add('Verdichtungsplan: Zurücksetzen ohne zweite Stufe', "[(int)\$this->ReadPropertyInteger('CompactStage2Months' . \$kind), -1],\n                [(int)\$this->ReadPropertyInteger('CompactStage3Months' . \$kind), -1],", "[(int)\$this->ReadPropertyInteger('CompactStage3Months' . \$kind), -1],\n                [(int)\$this->ReadPropertyInteger('CompactStage3Months' . \$kind), -1],");

// Rückschrittschutz der Mitgliedszähler und Neuaggregation (0.40.0), in MeterHubVirtual / MeterHub
$V = 'MeterHubVirtual/module.php';
$add('Rückschrittschutz: Gleichstand zählt als Rückschritt', "if (\$raw >= \$a) {", "if (\$raw > \$a) {", $V);
$add('Rückschrittschutz: Haltezeit nur „länger als“', "\$now - \$t >= self::MEMBER_RESET_HOLD_SEC", "\$now - \$t > self::MEMBER_RESET_HOLD_SEC", $V);
$add('Rückschrittschutz: Haltezeit nur 1 Stunde', "MEMBER_RESET_HOLD_SEC = 21600;", "MEMBER_RESET_HOLD_SEC = 3600;", $V);
$add('Rückschrittschutz: Tausch ohne Ausgleich (Sprung)', "\$o = \$a + \$o - \$raw;", "\$o = \$o;", $V);
$add('Rückschrittschutz: „niedriger seit“ wird nie gemerkt', "if (\$t === 0) {\n            \$t = \$now;\n        }", ";", $V);
$add('Rückschrittschutz: NaN verändert den Stand', "if (!is_finite(\$raw)) {\n            return [\$a + \$o, \$st, ''];", "if (false) {\n            return [\$a + \$o, \$st, ''];", $V);
$add('Rückschrittschutz: gilt auch für die Leistung', "if (\$guardOn && \$field !== 'power') {", "if (\$guardOn) {", $V);
$add('Rückschrittschutz: Zustand wird nicht gespeichert', "if (\$guardOn && \$guardNew !== \$guard) {", "if (false && \$guardNew !== \$guard) {", $V);
$add('Rückschrittschutz: Schalter wird ignoriert', "\$guardOn = \$this->ReadPropertyBoolean('MemberCounterGuard');", "\$guardOn = true;", $V);
$add('Neuaggregation: bei jedem Übernehmen (virtueller Zähler)', "if (\$oldType !== \$wantedType) {", "if (true) {", $V);
$add('Neuaggregation: nie (virtueller Zähler)', "if (\$oldType !== \$wantedType) {", "if (false) {", $V);
$add('Neuaggregation: Warteschlange wird nicht abgearbeitet', "        \$this->ProcessReAggQueue();\n        \$this->AdvanceCalculatedEnergy(\$nodes);", "        \$this->AdvanceCalculatedEnergy(\$nodes);", $V);
$add('Neuaggregation: bei jedem Übernehmen (MeterHub)', "if (\$oldType !== \$wantedType) {", "if (true) {", 'MeterHub/module.php');
$add('Neuaggregation: nie (MeterHub)', "if (\$oldType !== \$wantedType) {", "if (false) {", 'MeterHub/module.php');
$add('Stetigkeit: nur für positive Ausgabewerte', "\$state['sig'] !== \$sig && \$prev != 0.0", "\$state['sig'] !== \$sig && \$prev > 0", $V);
$add('Stetigkeit: auch ohne bisherigen Ausgabewert', "\$state['sig'] !== \$sig && \$prev != 0.0", "\$state['sig'] !== \$sig", $V);
// Monotone Ausgabe der Zählerstände (0.40.2)
$add('Monotone Ausgabe: niedrigere Summe wird nicht gehalten', "return \$sum < \$prev ? \$prev : \$sum;", "return \$sum;", $V);
$add('Monotone Ausgabe: auch der erste Lauf wird gehalten', "if (!\$hadState || !is_finite(\$prev)) {", "if (!is_finite(\$prev)) {", $V);
$add('Monotone Ausgabe: Zustand wird im Durchlauf immer als vorhanden gewertet', "\$hadState = isset(\$cont[\$ident]['sig']);", "\$hadState = true;", $V);
$add('Monotone Ausgabe: Schalter wird ignoriert (immer an)', "if (\$this->ReadPropertyBoolean('MonotoneOutput')) {", "if (true) {", $V);
$add('Monotone Ausgabe: Schalter wird ignoriert (immer aus)', "if (\$this->ReadPropertyBoolean('MonotoneOutput')) {", "if (false) {", $V);
// MQTT-Brücke folgt dem Status des Servers (0.40.3)
$B = 'MeterHubMqttBridge/module.php';
$add('Brücke: keine Anmeldung für die Statusmeldungen des Servers', "\$this->RegisterMessage(\$parent, IM_CHANGESTATUS);", ';', $B);
$add('Brücke: Status aus dem alten Zustand statt aus der Meldung', "\$this->UpdateStatus((int)(\$Data[0] ?? 0));", "\$this->UpdateStatus();", $B);
$add('Brücke: Meldungen anderer Absender werden verarbeitet', "\$Message === IM_CHANGESTATUS && \$SenderID === \$this->ReadAttributeInteger('Parent')", "\$Message === IM_CHANGESTATUS", $B);
$add('Brücke: alter Server wird nicht abgemeldet', "if (\$before > 0 && \$before !== \$parent) {", "if (false) {", $B);
$add('Brücke: Kernel bereit wird ignoriert', "if (\$Message === IPS_KERNELMESSAGE && (\$Data[0] ?? null) === KR_READY) {", "if (false) {", $B);
$add('Brücke: Status der Meldung wird ignoriert', "(\$parentStatus ?? \$s['parentStatus']) === 102", "\$s['parentStatus'] === 102", $B);
$add('Brücke: gelöschte Instanz verarbeitet noch', "if (!IPS_InstanceExists(\$this->InstanceID)) {\n            return;\n        }\n        if (\$Message === IPS_KERNELMESSAGE", "if (false) {\n            return;\n        }\n        if (\$Message === IPS_KERNELMESSAGE", $B);
$tests = array_map('basename', glob("$root/.tools/test-*.php"));
$jobs = [];
function copyAll(string $root, string $dest, string $target, string $content): void
{
    foreach (['MeterHub', 'MeterHubVirtual', 'MeterHubDiscovery', 'MeterHubBridge', 'MeterHubMqttBridge', 'libs', '.tools', '.tools/ref'] as $dir) {
        @mkdir("$dest/$dir", 0777, true);
        foreach (glob("$root/$dir/*") as $f) { if (is_file($f)) { copy($f, "$dest/$dir/" . basename($f)); } }
    }
    file_put_contents("$dest/$target", $content);
}
// Kontrolle mit unveränderter Kopie
$tree = sys_get_temp_dir() . '/mhshared_' . getmypid() . '_ctl';
copyAll($root, $tree, $target, $orig);
foreach ($tests as $t) {
    $out = (string)shell_exec('cd ' . escapeshellarg($tree) . ' && php ' . escapeshellarg(".tools/$t") . ' 2>&1');
    if (!preg_match('/ALLE PRÜFUNGEN BESTANDEN|Alle Prüfungen bestanden/u', $out)) { echo "KONTROLLE FEHLGESCHLAGEN: $t\n" . substr($out, -300) . "\n"; shell_exec('rm -rf ' . escapeshellarg($tree)); exit(2); }
}
shell_exec('rm -rf ' . escapeshellarg($tree));
echo 'Kontrolle: ' . count($tests) . " Prüfstände bestehen mit unveränderter Kopie\n";
$caught = 0; $missed = []; $na = [];
$only = $argv[1] ?? null;   // nur Proben, deren Name den Text enthält (php mutate-shared.php Neuaggregation)
foreach ($m as $i => [$name, $find, $replace, $file]) {
    if ($only !== null && stripos($name, $only) === false) { continue; }
    $orig = file_get_contents("$root/$file");
    if (substr_count($orig, $find) !== 1) { $na[] = $name . ' (Fundstellen: ' . substr_count($orig, $find) . ')'; continue; }
    $tree = sys_get_temp_dir() . '/mhshared_' . getmypid() . '_' . $i;
    copyAll($root, $tree, $file, str_replace($find, $replace, $orig));
    $hit = null;
    foreach ($tests as $t) {
        $out = (string)shell_exec('cd ' . escapeshellarg($tree) . ' && php ' . escapeshellarg(".tools/$t") . ' 2>&1');
        if (!preg_match('/ALLE PRÜFUNGEN BESTANDEN|Alle Prüfungen bestanden/u', $out)) { $hit = $t; break; }
    }
    shell_exec('rm -rf ' . escapeshellarg($tree));
    if ($hit) { $caught++; echo "  erkannt ($hit)  $name\n"; } else { $missed[] = $name; echo "  NICHT ERKANNT  $name\n"; }
}
echo 'Mutationen: ' . ($caught + count($missed)) . " angewendet, $caught erkannt, " . count($missed) . ' nicht erkannt, ' . count($na) . " nicht anwendbar\n";
foreach ($na as $n) { echo "  n/a: $n\n"; }
echo ($missed || $na) ? "NICHT ALLE MUTATIONEN ERKANNT\n" : "ALLE MUTATIONEN ERKANNT\n";
exit(($missed || $na) ? 1 : 0);
