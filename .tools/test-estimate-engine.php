<?php
/**
 * Prüfstand Schätzzähler-Rechenkern (libs/MeterHubEstimate.php).
 *
 *   php .tools/test-estimate-engine.php    # 0 = alle Prüfungen bestanden
 *
 * Die Referenzwerte in .tools/fixtures/estimate-reference.json stammen aus einer Python-Umsetzung des ORIGINALCODES
 * von Powercalc (lut.py / linear.py) auf denselben Messtabellen (.tools/fixtures/powercalc/*.csv, MIT-Lizenz, Powercalc).
 * Der PHP-Kern wird also gegen das Verhalten der Vorlage geprüft, nicht gegen eigene Annahmen.
 */
require_once __DIR__ . '/../libs/MeterHubEstimate.php';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : ($detail !== '' ? "  — $detail" : '')) . "\n";
    if (!$ok) {
        $fails++;
    }
}
function near(float $a, float $b, float $eps = 1e-9): bool
{
    return abs($a - $b) <= $eps * max(1.0, abs($b));
}

$ref = json_decode((string)file_get_contents(__DIR__ . '/fixtures/estimate-reference.json'), true);
$ct = MHUBE_Engine::ParseLut((string)file_get_contents(__DIR__ . '/fixtures/powercalc/color_temp.csv'), 'color_temp');
$hs = MHUBE_Engine::ParseLut((string)file_get_contents(__DIR__ . '/fixtures/powercalc/hs.csv'), 'hs');

echo "1: Messtabellen gegen Powercalc-Referenz\n";
check('1a: Tabellen gelesen', count($ct) > 10 && count($hs) > 10, count($ct) . '/' . count($hs));
$bad = 0; $first = '';
foreach ($ref['cases']['color_temp'] as [$bri, $mired, $want]) {
    $got = MHUBE_Engine::LutPower($ct, 'color_temp', $bri, $mired);
    if ($got === null || !near($got, $want)) { $bad++; $first = $first ?: "bri=$bri mired=$mired ist " . var_export($got, true) . " soll $want"; }
}
check('1b: color_temp (' . count($ref['cases']['color_temp']) . ' Fälle) stimmt mit der Vorlage', $bad === 0, "$bad abweichend, z. B. $first");
$bad = 0; $first = '';
foreach ($ref['cases']['hs'] as [$bri, $h, $s, $want]) {
    $got = MHUBE_Engine::LutPower($hs, 'hs', $bri, 0, $h, $s);
    if ($got === null || !near($got, $want)) { $bad++; $first = $first ?: "bri=$bri h=$h s=$s ist " . var_export($got, true) . " soll $want"; }
}
check('1c: hs (' . count($ref['cases']['hs']) . ' Fälle) stimmt mit der Vorlage', $bad === 0, "$bad abweichend, z. B. $first");
$bad = 0; $first = '';
foreach ($ref['linear'] as [$cal, $gamma, $v, $want]) {
    $got = MHUBE_Engine::Linear($cal, (float)$v, $gamma);
    if ($got === null || !near($got, $want, 1e-9)) { $bad++; $first = $first ?: json_encode([$gamma, $v]) . ' ist ' . var_export($got, true) . " soll $want"; }
}
check('1d: linear (' . count($ref['linear']) . ' Fälle) stimmt mit der Vorlage', $bad === 0, "$bad abweichend, z. B. $first");

echo "2: Messtabelle – Kanten\n";
$t = [1 => 1.0, 255 => 5.0];
check('2a: genauer Treffer', MHUBE_Engine::LutPower($t, 'brightness', 255) === 5.0);
check('2b: unterhalb der Tabelle → erster Wert', MHUBE_Engine::LutPower($t, 'brightness', 0) === 1.0);
check('2c: Mitte wird interpoliert', near(MHUBE_Engine::LutPower($t, 'brightness', 128), 1.0 + 4.0 * (127 / 254)));
check('2d: über 255 wird auf 255 begrenzt', MHUBE_Engine::LutPower($t, 'brightness', 400) === 5.0);
check('2e: leere Tabelle → null', MHUBE_Engine::LutPower([], 'brightness', 100) === null);
check('2f: ParseLut ignoriert Müllzeilen', MHUBE_Engine::ParseLut("bri,watt\n1,2.5\nxx,yy\n\n5,abc\n10,4", 'brightness') === [1 => 2.5, 10 => 4.0]);
check('2g: ParseLut nur Kopfzeile → leer', MHUBE_Engine::ParseLut("bri,watt\n", 'brightness') === []);
$csv = "bri,watt\n1,1.5\n255,9.0\n";
check('2h: gz+Base64-Ablage verlustfrei', MHUBE_Engine::UnpackLut(MHUBE_Engine::PackLut($csv)) === $csv);
check('2i: CsvText erkennt gzip und Klartext', MHUBE_Engine::CsvText((string)gzencode($csv)) === $csv && MHUBE_Engine::CsvText($csv) === $csv);
check('2j: UnpackLut mit Müll → leer', MHUBE_Engine::UnpackLut('%%%') === '');
check('2k: Gleichstand beim Mired nimmt den unteren Schlüssel', MHUBE_Engine::LutPower([100 => [200 => 1.0, 300 => 2.0]], 'color_temp', 100, 250) === 1.0);

check('2l: ParseLut überspringt immer die erste Zeile (Kopfzeile), wie Powercalc', MHUBE_Engine::ParseLut("1,2.0\n3,4.0", 'brightness') === [3 => 4.0]);
check('2m: ParseLut liefert aufsteigende Schlüssel', array_keys(MHUBE_Engine::ParseLut("bri,watt\n9,1\n3,2\n5,3", 'brightness')) === [3, 5, 9]);
$big = "bri,watt\n" . implode("\n", array_map(fn($i) => "$i," . ($i * 0.01), range(1, 255))) . "\n";
check('2n: Ablage ist komprimiert (kleiner als Base64 des Klartexts)', strlen(MHUBE_Engine::PackLut($big)) < strlen(base64_encode($big)) / 2);

echo "3: Farben\n";
$k = MHUBE_Engine::KelvinToRgb(6500);
check('3a: 6500 K ist nahezu weiß', $k[0] >= 250 && $k[1] >= 245 && $k[2] >= 240, json_encode($k));
$k = MHUBE_Engine::KelvinToRgb(2000);
check('3b: 2000 K ist orange (Blau stark unten)', $k[0] === 255 && $k[2] < 80 && $k[1] > 100 && $k[1] < 190, json_encode($k));
[$h, $s, $v] = MHUBE_Engine::ColorIntToHsv(0xFF0000);
check('3c: Rot = Farbton 0, Sättigung 100, Hellwert 100', $h == 0 && $s == 100 && $v == 100);
[$h, $s, $v] = MHUBE_Engine::ColorIntToHsv(0x00FF00);
check('3d: Grün = Farbton 120', near($h, 120.0));
[$h, $s, $v] = MHUBE_Engine::ColorIntToHsv(0x0000FF);
check('3e: Blau = Farbton 240', near($h, 240.0));
[$h, $s, $v] = MHUBE_Engine::ColorIntToHsv(0xFF0080);
check('3h: Farbton im Rot-Violett-Bereich ist positiv (329°)', near($h, 329.88, 1e-3), (string)$h);
check('3i: Farbtemperatur wird auf 1000…40000 K begrenzt', MHUBE_Engine::KelvinToRgb(300) === MHUBE_Engine::KelvinToRgb(1000) && MHUBE_Engine::KelvinToRgb(90000) === MHUBE_Engine::KelvinToRgb(40000));
// Rechenwerte des Verfahrens (Tanner Helland, wie Home Assistant), unabhängig in Python nachgerechnet
foreach ([[2000, [255, 137, 14]], [3000, [255, 177, 110]], [4000, [255, 206, 166]], [5000, [255, 228, 206]], [6600, [255, 255, 255]], [6800, [250, 246, 255]], [10000, [202, 218, 255]], [15000, [181, 205, 255]]] as [$kk, $want]) {
    $got = MHUBE_Engine::KelvinToRgb($kk);
    check("3j: $kk K = " . implode(',', $want), $got === $want, implode(',', $got));
}
[$h, $s, $v] = MHUBE_Engine::ColorIntToHsv(0x000000);
check('3f: Schwarz ohne Division durch 0', $s == 0 && $v == 0);
check('3g: Mired ↔ Kelvin', MHUBE_Engine::KelvinToMired(2700) === 370 && MHUBE_Engine::MiredToKelvin(370) === 2703 && MHUBE_Engine::KelvinToMired(0) === 0);

echo "4: Rechenart und Zustand (Power)\n";
$fixed = ['strategy' => 'fixed', 'standby' => 0.3, 'standbyOn' => 0.0, 'fixed' => ['power' => 12.0]];
check('4a: fest, an', MHUBE_Engine::Power($fixed, ['on' => true]) === 12.0);
check('4b: fest, aus = Standby', MHUBE_Engine::Power($fixed, ['on' => false]) === 0.3);
$plug = ['strategy' => 'fixed', 'standby' => 0.23, 'standbyOn' => 0.98, 'fixed' => ['power' => 0.0]];
check('4c: Zwischenstecker an = nur Eigenverbrauch', MHUBE_Engine::Power($plug, ['on' => true]) === 0.98);
$lin = ['strategy' => 'linear', 'standby' => 0.5, 'standbyOn' => 0.0, 'linear' => ['calibrate' => [[0, 2.0], [100, 12.0]], 'scale' => 100]];
check('4d: linear 50 %', near(MHUBE_Engine::Power($lin, ['on' => true, 'percent' => 50]), 7.0));
check('4e: linear ohne Wertquelle = Volllast', near(MHUBE_Engine::Power($lin, ['on' => true]), 12.0));
check('4f: linear aus = Standby', MHUBE_Engine::Power($lin, ['on' => false, 'percent' => 50]) === 0.5);
$lin255 = ['strategy' => 'linear', 'standby' => 0, 'linear' => ['calibrate' => [[0, 0.5], [255, 12.0]], 'scale' => 255]];
check('4g: Skala 255: 50 % = Wert 127,5', near(MHUBE_Engine::Power($lin255, ['on' => true, 'percent' => 50]), 0.5 + 11.5 * 0.5));
check('4h: linear mit nur einem Punkt → null', MHUBE_Engine::Linear([[0, 1]], 0.5) === null);
check('4i: linear mit Müllpunkten → null', MHUBE_Engine::Linear([['a', 1], [2]], 0.5) === null);
check('4j: unbekannte Rechenart → null', MHUBE_Engine::Power(['strategy' => 'composite'], ['on' => true]) === null);
check('4k: Standby auch ohne Angabe = 0', MHUBE_Engine::Power(['strategy' => 'fixed', 'fixed' => ['power' => 5]], ['on' => false]) === 0.0);

$lutP = ['strategy' => 'lut', 'standby' => 0.4, 'luts' => ['color_temp' => $ct, 'hs' => $hs]];
$a = MHUBE_Engine::Power($lutP, ['on' => true, 'bri' => 200, 'kelvin' => 2700, 'mode' => 'auto']);
check('4l: lut auto mit Farbtemperatur = color_temp-Tabelle', near($a, MHUBE_Engine::LutPower($ct, 'color_temp', 200, 370)));
$a = MHUBE_Engine::Power($lutP, ['on' => true, 'bri' => 200, 'color' => 0xFF8000, 'mode' => 'auto']);
[$h, $s] = MHUBE_Engine::ColorIntToHsv(0xFF8000);
check('4m: lut auto mit Farbe = hs-Tabelle', near($a, MHUBE_Engine::LutPower($hs, 'hs', 200, 0, (int)($h / 360 * 65535), (int)($s / 100 * 255))));
$a = MHUBE_Engine::Power($lutP, ['on' => true, 'bri' => 200, 'color' => 0xFF8000, 'kelvin' => 2700, 'mode' => 'hs']);
check('4n: erzwungenes hs gewinnt gegen vorhandene Farbtemperatur', near($a, MHUBE_Engine::LutPower($hs, 'hs', 200, 0, (int)($h / 360 * 65535), (int)($s / 100 * 255))));
$a = MHUBE_Engine::Power($lutP, ['on' => true, 'bri' => 200, 'mode' => 'brightness']);
check('4o: nur color_temp+hs vorhanden, ohne Farbe: warmweiß (2700 K) statt null', $a !== null && near($a, MHUBE_Engine::LutPower($ct, 'color_temp', 200, 370)), var_export($a, true));
$a = MHUBE_Engine::Power($lutP, ['on' => true, 'bri' => null, 'kelvin' => 2700]);
check('4p: ohne Helligkeit = volle Helligkeit', near($a, MHUBE_Engine::LutPower($ct, 'color_temp', 255, 370)));
$a = MHUBE_Engine::Power($lutP, ['on' => true, 'color' => 0x804000, 'mode' => 'hs']);
[$h, $s, $v] = MHUBE_Engine::ColorIntToHsv(0x804000);
check('4q: hs ohne Helligkeitsquelle nimmt den Hellwert der Farbe', near($a, MHUBE_Engine::LutPower($hs, 'hs', (int)round($v / 100 * 255), 0, (int)($h / 360 * 65535), (int)($s / 100 * 255))));
check('4r: lut ohne jede Tabelle → null', MHUBE_Engine::Power(['strategy' => 'lut', 'luts' => []], ['on' => true]) === null);
check('4s: lut aus = Standby', MHUBE_Engine::Power($lutP, ['on' => false]) === 0.4);
$onlyB = ['strategy' => 'lut', 'luts' => ['brightness' => [1 => 1.0, 255 => 9.0]]];
check('4t: nur Helligkeitstabelle, Farbwunsch weicht aus', near(MHUBE_Engine::Power($onlyB, ['on' => true, 'bri' => 255, 'color' => 0xFF0000, 'mode' => 'hs']), 9.0));
check('4v: SelectMode: Weißlicht-Wunsch fällt auf hs zurück, bevor Helligkeit genommen wird', MHUBE_Engine::SelectMode(['hs', 'brightness'], 'color_temp', true, true) === 'hs');
check('4w: SelectMode: auto nimmt Farbtemperatur vor Farbe', MHUBE_Engine::SelectMode(['color_temp', 'hs'], 'auto', true, true) === 'color_temp');
check('4u: SelectMode: color_temp → hs → brightness', MHUBE_Engine::SelectMode(['hs'], 'color_temp', true, true) === 'hs'
    && MHUBE_Engine::SelectMode(['brightness'], 'hs', true, true) === 'brightness'
    && MHUBE_Engine::SelectMode(['color_temp', 'hs'], 'auto', false, true) === 'hs'
    && MHUBE_Engine::SelectMode(['color_temp', 'hs'], 'auto', false, false) === 'color_temp');

echo "5: Energie\n";
check('5a: 3600 W × 60 s = 0,06 kWh', near(MHUBE_Engine::Integrate(1.0, 3600, 60, 300), 1.06));
check('5b: Lücke über der Grenze wird nicht überbrückt', MHUBE_Engine::Integrate(1.0, 3600, 301, 300) === 1.0);
check('5c: negative/unendliche Leistung zählt nicht', MHUBE_Engine::Integrate(1.0, -5, 60, 300) === 1.0 && MHUBE_Engine::Integrate(1.0, INF, 60, 300) === 1.0 && MHUBE_Engine::Integrate(1.0, NAN, 60, 300) === 1.0);
check('5d: Zeit rückwärts zählt nicht', MHUBE_Engine::Integrate(1.0, 100, -5, 300) === 1.0);
check('5e: Grenze selbst zählt noch', near(MHUBE_Engine::Integrate(0.0, 1000, 300, 300), 1000 * 300 / 3.6e6));

echo "6: Profile aus der Datenbank\n";
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'fixed', 'standby_power' => 0.23, 'standby_power_on' => 0.98], 'ikea', 'E1603');
check('6a: fest ohne fixed_config → Leistung 0 + Eigenverbrauch', $p['strategy'] === 'fixed' && $p['fixed']['power'] === 0.0 && $p['standbyOn'] === 0.98 && !isset($p['error']));
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'fixed', 'fixed_config' => ['power' => 14.5]], 'avm', 'x');
check('6b: fest mit Leistung', $p['fixed']['power'] === 14.5);
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'fixed', 'fixed_config' => ['power' => '{{ states(x) }}']], 'a', 'b');
check('6c: Vorlage statt Zahl wird abgelehnt', ($p['error'] ?? '') === 'strategy');
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'device_type' => 'light', 'standby_power' => 0.2, 'linear_config' => ['attribute' => 'percentage', 'calibrate' => ['1 -> 1.6', '100 -> 24.8']]], 'ikea', 'E2007');
check('6d: linear mit Kalibrierung, Prozent-Skala', $p['linear']['scale'] === 100 && $p['linear']['calibrate'] === [[1.0, 1.6], [100.0, 24.8]]);
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'device_type' => 'light', 'linear_config' => ['max_power' => 10, 'min_power' => 1]], 'a', 'b');
check('6e: Licht ohne Attribut → Skala 255, min/max', $p['linear']['scale'] === 255 && $p['linear']['calibrate'] === [[0.0, 1.0], [255.0, 10.0]]);
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'device_type' => 'speaker', 'linear_config' => ['max_power' => 10]], 'a', 'b');
check('6f: Nicht-Licht → Skala 100, min = Standby', $p['linear']['scale'] === 100 && $p['linear']['calibrate'][0][1] === 0.0);
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'standby_power_on' => 0.35], 'a', 'b');
check('6g: linear ohne Angaben → nur Eigenverbrauch', $p['strategy'] === 'fixed' && $p['standbyOn'] === 0.35 && !isset($p['error']));
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'linear_config' => ['max_power' => 10, 'gamma_curve' => 2.2]], 'a', 'b');
check('6h: Gamma wird übernommen', $p['linear']['gamma'] === 2.2);
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'linear_config' => ['max_power' => 10, 'power_curve' => ['0 -> 0']]], 'a', 'b');
check('6i: Powercalc-Kurve (power_curve) wird abgelehnt', ($p['error'] ?? '') === 'strategy');
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'device_type' => 'light', 'standby_power' => 0.2, 'linear_config' => ['max_power' => 10]], 'a', 'b');
check('6o: ohne min_power gilt der Standby als unterer Punkt', $p['linear']['calibrate'][0][1] === 0.2);
foreach (['composite', 'multi_switch', 'playbook', ''] as $s) {
    check("6j: Rechenart '$s' nicht unterstützt", (MHUBE_Engine::ProfileFromModel(['calculation_strategy' => $s], 'a', 'b')['error'] ?? '') === 'strategy');
}
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'lut', 'sub_profile_count' => 2], 'a', 'b');
check('6k: Messtabelle mit Unterprofilen wird abgelehnt', ($p['error'] ?? '') === 'subprofile');
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'lut', 'standby_power' => 0.4, 'name' => 'Lampe'], 'a', 'b');
check('6l: lut-Profil ohne Fehler, Name übernommen', !isset($p['error']) && $p['name'] === 'Lampe' && $p['standby'] === 0.4);
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'fixed', 'only_self_usage' => true, 'standby_power' => 0.7], 'a', 'b');
check('6m: only_self_usage ohne standby_power_on → Eigenverbrauch = Standby', $p['standbyOn'] === 0.7);
$p = MHUBE_Engine::ProfileFromModel(['calculation_strategy' => 'linear', 'device_type' => 'light', 'linear_config' => ['calibrate' => ['x -> y', '1 -> 2']]], 'a', 'b');
check('6n: Kalibrierung mit zu wenigen gültigen Punkten wird abgelehnt', ($p['error'] ?? '') === 'strategy');

echo "7: Suche in der Bibliothek\n";
$idx = ['manufacturers' => [
    ['name' => 'ikea', 'full_name' => 'IKEA', 'dir_name' => 'ikea', 'aliases' => ['IKEA of Sweden'], 'models' => [
        ['id' => 'E1603', 'name' => 'TRADFRI control outlet', 'aliases' => ['TRADFRI control outlet'], 'calculation_strategy' => 'fixed', 'device_type' => 'smart_switch'],
        ['id' => 'LED1623G12', 'name' => 'TRADFRI bulb E27', 'aliases' => [], 'calculation_strategy' => 'lut', 'device_type' => 'light', 'color_modes' => ['color_temp', 'effect']],
    ]],
    ['name' => 'signify', 'full_name' => 'Signify', 'dir_name' => 'signify', 'aliases' => ['Philips'], 'models' => [
        ['id' => '9290012573A', 'name' => 'Hue White and Color', 'calculation_strategy' => 'lut', 'sub_profile_count' => 3],
        ['id' => 'X', 'name' => 'Anlage', 'calculation_strategy' => 'composite'],
    ]],
]];
check('7a: Suche nach Modellname', count(MHUBE_Engine::SearchIndex($idx, 'tradfri')) === 2);
check('7b: alle Suchwörter müssen passen', count(MHUBE_Engine::SearchIndex($idx, 'ikea bulb')) === 1);
check('7c: Hersteller-Alias (Philips)', count(MHUBE_Engine::SearchIndex($idx, 'philips hue')) === 1);
check('7d: Groß-/Kleinschreibung egal', count(MHUBE_Engine::SearchIndex($idx, 'TRADFRI OUTLET')) === 1);
check('7e: leere Suche → nichts', MHUBE_Engine::SearchIndex($idx, '   ') === []);
check('7f: Begrenzung', count(MHUBE_Engine::SearchIndex($idx, 'tradfri', 1)) === 1);
$r = MHUBE_Engine::SearchIndex($idx, 'ikea');
check('7g: Treffer trägt Verzeichnis, ID, Rechenart', $r[0]['manufacturer'] === 'ikea' && $r[0]['id'] === 'E1603' && $r[0]['strategy'] === 'fixed' && $r[0]['supported'] === true);
check('7h: Messtabelle mit Unterprofilen und composite sind „nicht unterstützt“',
    MHUBE_Engine::SearchIndex($idx, 'hue')[0]['supported'] === false && MHUBE_Engine::SearchIndex($idx, 'anlage')[0]['supported'] === false);
check('7i: Farbarten nur die bekannten', MHUBE_Engine::SearchIndex($idx, 'bulb')[0]['modes'] === ['color_temp']);
check('7j: Umlaute in der Suche (UTF-8)', count(MHUBE_Engine::SearchIndex(['manufacturers' => [['name' => 'x', 'dir_name' => 'x', 'models' => [['id' => '1', 'name' => 'Küchenlicht', 'calculation_strategy' => 'fixed']]]]], 'KÜCHEN')) === 1);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
