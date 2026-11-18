<?php
require_once __DIR__ . '/i18n-test-support.php';
require_once __DIR__ . '/ref/harness.php';
/**
 * Referenzlauf der Zählertreiber, die vorher kein Prüfstand ausgeführt hat (18.11.2026): blue'Log SCADA
 * (Wechselrichter, Zähler), Shelly Pro 3EM, go-e Controller, Janitza klassisch, Janitza UMG 800, Eastron SDM72D/630,
 * WhatWatt, Phoenix EEM-EM375/EEM-XM, Carlo Gavazzi, Socomec Countis, MBS Professional.
 *
 *   php .tools/test-ref-treiber.php             # vergleicht mit .tools/ref/treiber.json
 *   UPDATE_REF=1 php .tools/test-ref-treiber.php  # schreibt die Referenz bewusst neu
 *
 * Je Treiber und Szenario werden readFast() und readSlow() ausgeführt; festgehalten werden die Rückgabewerte, die
 * Anfragen (Funktion, Adresse, Anzahl, in Reihenfolge) und jede in den Hub geschriebene Variable mit Typ und Wert.
 * Szenarien: positive Muster, negative Muster (Vorzeichen), alles 0, alles 0xFFFF (Ungültig-Marke), keine Antwort,
 * Gruppen aus, und je die n-te Anfrage ohne Antwort. Die Registerwerte sind Muster, keine Gerätemessung.
 */

$drivers = ['MHUB_BlueLogScadaInverterDriver', 'MHUB_BlueLogScadaMeterDriver', 'MHUB_ShellyPro3emDriver', 'MHUB_GoeControllerDriver',
    'MHUB_JanitzaClassicDriver', 'MHUB_Umg800Driver', 'MHUB_EastronSdmDriver', 'MHUB_WhatWattDriver', 'MHUB_PhoenixEem375Driver',
    'MHUB_PhoenixEemXmDriver', 'MHUB_CarloGavazziDriver', 'MHUB_SocomecCountisDriver', 'MHUB_MbsProfessionalDriver',
    'MHUB_BlueLogScadaLoggerDriver', 'MHUB_BlueLogPowerControlDriver'];

function runScenario(string $cls, string $pattern, ?int $failAt, bool $groups): array
{
    $drv = new $cls();
    $hub = new RefHub();
    $hub->defaultGroup = $groups;
    $log = new RefMb();
    $mb = RefMb::make($pattern, $failAt, $log);
    $out = [];
    $warn = [];
    set_error_handler(function ($no, $str) use (&$warn) { $warn[] = $str; return true; });
    foreach (['readFast', 'readSlow'] as $m) {
        try {
            $r = $drv->$m($mb, $hub);
            $out[$m] = is_bool($r) ? $r : ($r === null ? null : RefNorm::value($r));
        } catch (\Throwable $e) {
            $out[$m] = 'Ausnahme: ' . get_class($e) . ': ' . $e->getMessage();
        }
    }
    restore_error_handler();
    ksort($hub->vars);
    return ['ret' => $out, 'warnungen' => array_values(array_unique($warn)), 'requests' => array_map(fn($r) => implode('/', $r), $log->requests), 'vars' => $hub->vars, 'order' => count($hub->order), 'calls' => $hub->calls];
}

$result = [];
foreach ($drivers as $cls) {
    $sc = [];
    foreach (['pos' => 'positiv', 'neg' => 'negativ', 'zero' => 'null', 'invalid' => 'ungueltig', 'silent' => 'keine_Antwort'] as $p => $name) {
        $sc[$name] = runScenario($cls, $p, null, true);
    }
    $sc['positiv_Gruppen_aus'] = runScenario($cls, 'pos', null, false);
    $n = count($sc['positiv']['requests']);
    for ($k = 1; $k <= $n; $k++) { $sc["Abbruch_bei_Anfrage_$k"] = runScenario($cls, 'pos', $k, true); }
    $result[$cls] = $sc;
}

$result = ['_hinweis' => 'Registerwerte sind MUSTER (je Adresse ein eigener Wert, positiv 0x4000+, negativ 0xC000+, ungueltig 0xFFFF), keine Geraetemessung. Die Referenz haelt das heutige Verhalten fest, nicht das gewuenschte.'] + $result;
$refFile = __DIR__ . '/ref/treiber.json';
$ref = RefFile::load($refFile);
if (getenv('UPDATE_REF') || $ref === null) {
    RefFile::save($refFile, $result);
    echo "Referenz geschrieben: $refFile (" . (count($result) - 1) . " Treiber)\n";
    $ref = $result;
}

foreach ($result as $cls => $sc) {
    if ($cls === '_hinweis') { continue; }
    $short = str_replace(['MHUB_', 'Driver'], '', $cls);
    $bad = [];
    foreach ($sc as $name => $obs) {
        if (!isset($ref[$cls][$name])) { $bad[] = "$name (fehlt in der Referenz)"; continue; }
        if (json_encode($obs) !== json_encode($ref[$cls][$name])) { $bad[] = $name; }
    }
    foreach (array_keys($ref[$cls] ?? []) as $name) { if (!isset($sc[$name])) { $bad[] = "$name (in der Referenz, nicht mehr erzeugt)"; } }
    check("$short: " . count($sc) . ' Szenarien stimmen mit der Referenz überein', $bad === [], implode(', ', array_slice($bad, 0, 4)));
    check("$short: Lesepfad schreibt im Normalfall mindestens eine Variable", count($sc['positiv']['vars']) > 0);
}

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
