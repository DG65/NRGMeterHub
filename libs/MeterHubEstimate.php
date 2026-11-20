<?php

// ---------------------------------------------------------------------------
// MeterHubEstimate — Rechenkern des Schätzzählers (ohne Symcon-Aufrufe, einzeln testbar).
//
// Die Verfahren und die Messdaten-Formate folgen dem Projekt Powercalc für Home Assistant
// (https://github.com/bramstroker/homeassistant-powercalc, Bram Gerritsen, MIT-Lizenz): die Geräte-Datenbank
// "profile_library" und die Rechenarten fest (fixed), linear (linear) und Messtabelle (lut). Der Code hier ist eine
// eigene PHP-Umsetzung; die Datenbank selbst wird nicht mitgeliefert, sondern zur Einrichtung je Gerät von GitHub geladen.
//
// Alle Leistungen in Watt, alle Energien in kWh.
// ---------------------------------------------------------------------------

class MHUBE_Engine
{
    /** Die Messtabellen-Arten der Datenbank (Dateiname = <Art>.csv bzw. <Art>.csv.gz). */
    public const LUT_MODES = ['brightness', 'color_temp', 'hs'];

    /** Farbtemperatur, wenn die Lampe keine meldet, die Tabelle aber eine braucht (warmweiß). */
    public const DEFAULT_KELVIN = 2700.0;

    // -----------------------------------------------------------------------
    // Farben
    // -----------------------------------------------------------------------

    public static function KelvinToMired(float $kelvin): int
    {
        return $kelvin > 0 ? (int)round(1000000 / $kelvin) : 0;
    }

    public static function MiredToKelvin(float $mired): int
    {
        return $mired > 0 ? (int)round(1000000 / $mired) : 0;
    }

    /** Farbtemperatur → RGB (Näherung nach Tanner Helland, wie in Home Assistant). @return int[] [r, g, b] 0..255 */
    public static function KelvinToRgb(float $kelvin): array
    {
        $kelvin = max(1000.0, min(40000.0, $kelvin));
        $t = $kelvin / 100.0;
        $red = $t <= 66 ? 255.0 : 329.698727446 * pow($t - 60, -0.1332047592);
        $green = $t <= 66 ? 99.4708025861 * log($t) - 161.1195681661 : 288.1221695283 * pow($t - 60, -0.0755148492);
        if ($t >= 66) {
            $blue = 255.0;
        } elseif ($t <= 19) {
            $blue = 0.0;
        } else {
            $blue = 138.5177312231 * log($t - 10) - 305.0447927307;
        }
        $c = fn($v) => (int)max(0, min(255, round($v)));
        return [$c($red), $c($green), $c($blue)];
    }

    /** @return float[] [Farbton 0..360, Sättigung 0..100, Hellwert 0..100] */
    public static function RgbToHsv(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        if ($d == 0) {
            $h = 0.0;
        } elseif ($max == $r) {
            $h = fmod(($g - $b) / $d, 6);
        } elseif ($max == $g) {
            $h = ($b - $r) / $d + 2;
        } else {
            $h = ($r - $g) / $d + 4;
        }
        $h = $h * 60;
        if ($h < 0) {
            $h += 360;
        }
        return [$h, $max == 0 ? 0.0 : $d / $max * 100, $max * 100];
    }

    /** Farbtemperatur (Kelvin) → [Farbton, Sättigung] wie Home Assistant (für Lampen ohne Tabelle für Weißtöne). */
    public static function KelvinToHs(float $kelvin): array
    {
        [$r, $g, $b] = self::KelvinToRgb($kelvin);
        [$h, $s] = self::RgbToHsv($r, $g, $b);
        return [$h, $s];
    }

    /** Symcon-Farbwert (0xRRGGBB) → [Farbton 0..360, Sättigung 0..100, Hellwert 0..100]. */
    public static function ColorIntToHsv(int $color): array
    {
        return self::RgbToHsv(($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF);
    }

    // -----------------------------------------------------------------------
    // Messtabellen (lut)
    // -----------------------------------------------------------------------

    /**
     * Liest eine Messtabelle (CSV mit Kopfzeile). brightness: bri,watt — color_temp: bri,mired,watt — hs: bri,hue,sat,watt.
     * Rückgabe: brightness: [bri => W]; color_temp: [bri => [mired => W]]; hs: [bri => [hue => [sat => W]]]. Leer bei ungültigen Daten.
     */
    public static function ParseLut(string $csv, string $mode): array
    {
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (!is_array($lines) || count($lines) < 2) {
            return [];
        }
        array_shift($lines);
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $r = str_getcsv($line, ',', '"', '');
            if ($mode === 'hs') {
                if (count($r) < 4 || !is_numeric($r[3])) {
                    continue;
                }
                $out[(int)$r[0]][(int)$r[1]][(int)$r[2]] = (float)$r[3];
            } elseif ($mode === 'color_temp') {
                if (count($r) < 3 || !is_numeric($r[2])) {
                    continue;
                }
                $out[(int)$r[0]][(int)$r[1]] = (float)$r[2];
            } else {
                if (count($r) < 2 || !is_numeric($r[1])) {
                    continue;
                }
                $out[(int)$r[0]] = (float)$r[1];
            }
        }
        ksort($out);
        return $out;
    }

    /** CSV (oder bereits gzip-komprimierte Bytes) → Text. */
    public static function CsvText(string $bytes): string
    {
        if (strlen($bytes) > 2 && ord($bytes[0]) === 0x1f && ord($bytes[1]) === 0x8b) {
            $t = @gzdecode($bytes);
            return $t === false ? '' : $t;
        }
        return $bytes;
    }

    /** Messtabelle für die Ablage in einer Eigenschaft/einem Attribut: gzip + Base64. */
    public static function PackLut(string $csv): string
    {
        return base64_encode((string)gzencode($csv, 9));
    }

    public static function UnpackLut(string $packed): string
    {
        $raw = base64_decode($packed, true);
        return $raw === false ? '' : self::CsvText($raw);
    }

    /** Nächster Schlüssel (bei Gleichstand der untere) — wie Powercalc. */
    private static function NearestKey(array $keys, int $x): int
    {
        $n = count($keys);
        if ($n === 0) {
            return $x;
        }
        // $keys ist aufsteigend sortiert
        $i = 0;
        while ($i < $n && $keys[$i] < $x) {
            $i++;
        }
        if ($i === 0) {
            return $keys[0];
        }
        if ($i >= $n) {
            return $keys[$n - 1];
        }
        $before = $keys[$i - 1];
        $after = $keys[$i];
        return ($x - $before) <= ($after - $x) ? $before : $after;
    }

    private static function Nearest(array $table, int $key)
    {
        if (isset($table[$key])) {
            return $table[$key];
        }
        $keys = array_keys($table);
        sort($keys, SORT_NUMERIC);
        return $table[self::NearestKey($keys, $key)];
    }

    /** Wert zu einer Helligkeit-Zeile: bei color_temp/hs die Zeile je Farbe auflösen. */
    private static function RowValue($row, string $mode, int $mired, int $hue, int $sat): float
    {
        if (!is_array($row)) {
            return (float)$row;
        }
        if ($mode === 'color_temp') {
            return (float)self::Nearest($row, $mired);
        }
        $sats = self::Nearest($row, $hue);
        return (float)self::Nearest($sats, $sat);
    }

    /**
     * Leistung aus einer Messtabelle. Helligkeit wird zwischen den Messpunkten linear interpoliert, Farbtemperatur und
     * Farbe nehmen den nächsten Messpunkt (Powercalc-Verfahren).
     *
     * @param array  $table      Ergebnis von ParseLut()
     * @param int    $bri        Helligkeit 0..255
     * @param int    $mired      Farbtemperatur in Mired (nur color_temp)
     * @param int    $hue        Farbton 0..65535 (nur hs)
     * @param int    $sat        Sättigung 0..255 (nur hs)
     */
    public static function LutPower(array $table, string $mode, int $bri, int $mired = 0, int $hue = 0, int $sat = 0): ?float
    {
        if (!$table) {
            return null;
        }
        $bri = max(0, min(255, $bri));
        $keys = array_keys($table);
        sort($keys, SORT_NUMERIC);
        if (isset($table[$bri])) {
            return self::RowValue($table[$bri], $mode, $mired, $hue, $sat);
        }
        $n = count($keys);
        $i = 0;
        while ($i < $n && $keys[$i] < $bri) {
            $i++;
        }
        if ($i === 0) {
            return self::RowValue($table[$keys[0]], $mode, $mired, $hue, $sat);
        }
        if ($i >= $n) {
            return self::RowValue($table[$keys[$n - 1]], $mode, $mired, $hue, $sat);
        }
        $b0 = $keys[$i - 1];
        $b1 = $keys[$i];
        $p0 = self::RowValue($table[$b0], $mode, $mired, $hue, $sat);
        $p1 = self::RowValue($table[$b1], $mode, $mired, $hue, $sat);
        return $p0 + ($p1 - $p0) * (($bri - $b0) / ($b1 - $b0));
    }

    /**
     * Welche Tabellenart zur Lampe passt. $wanted: auto|brightness|color_temp|hs.
     * Ohne passende Tabelle weicht die Wahl aus: Farbtemperatur → Farbe → Helligkeit, Farbe → Helligkeit.
     *
     * @param string[] $supported  vorhandene Tabellenarten des Modells
     */
    public static function SelectMode(array $supported, string $wanted, bool $hasColorTemp, bool $hasColor): string
    {
        if ($wanted === 'auto') {
            $wanted = $hasColorTemp ? 'color_temp' : ($hasColor ? 'hs' : 'brightness');
        }
        $order = ['color_temp' => ['color_temp', 'hs', 'brightness'], 'hs' => ['hs', 'brightness'], 'brightness' => ['brightness']];
        foreach ($order[$wanted] ?? ['brightness'] as $m) {
            if (in_array($m, $supported, true)) {
                return $m;
            }
        }
        // Die gewünschte Art gibt es nicht und kein Ausweichziel: die erste vorhandene Tabelle nehmen
        return $supported[0] ?? 'brightness';
    }

    // -----------------------------------------------------------------------
    // fest / linear
    // -----------------------------------------------------------------------

    /**
     * Linear (mit Stützstellen) wie Powercalc: innerhalb der Tabelle zwischen den Nachbarpunkten, außerhalb entlang der
     * Gerade vom ersten zum letzten Punkt. Gamma-Kurve wirkt auf den relativen Wert im Abschnitt.
     *
     * @param array $calibrate [[Wert, Watt], ...] (beliebige Reihenfolge)
     */
    public static function Linear(array $calibrate, float $value, ?float $gamma = null): ?float
    {
        $pts = [];
        foreach ($calibrate as $p) {
            if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                $pts[] = [(float)$p[0], (float)$p[1]];
            }
        }
        if (count($pts) < 2) {
            return null;
        }
        usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
        $n = count($pts);
        if ($value < $pts[0][0] || $value > $pts[$n - 1][0]) {
            $lo = $pts[0];
            $hi = $pts[$n - 1];
        } else {
            // bisect_right: erster Punkt mit Wert > $value, mindestens Index 1
            $idx = 0;
            while ($idx < $n && $pts[$idx][0] <= $value) {
                $idx++;
            }
            $idx = min(max($idx, 1), $n - 1);
            $lo = $pts[$idx - 1];
            $hi = $pts[$idx];
        }
        $valueRange = $hi[0] - $lo[0];
        if ($valueRange == 0) {
            return $lo[1];
        }
        $rel = ($value - $lo[0]) / $valueRange;
        if ($gamma !== null && $gamma > 0) {
            // Unterhalb des kalibrierten Bereichs gibt es keine Kurve: linear bleiben (negative Basis wäre komplex)
            $rel = $rel < 0 ? $rel : pow($rel, $gamma);
        }
        return ($hi[1] - $lo[1]) * $rel + $lo[1];
    }

    /**
     * Leistung eines Profils in einem Zustand.
     *
     * Profil: ['strategy' => 'fixed'|'linear'|'lut', 'standby' => W (aus), 'standbyOn' => W Eigenverbrauch (an),
     *          'fixed' => ['power' => W], 'linear' => ['calibrate' => [[v,W]..], 'gamma' => ?, 'scale' => 100|255],
     *          'luts' => ['brightness' => Tabelle, 'color_temp' => ..., 'hs' => ...] (geparst)]
     * Zustand: ['on' => bool, 'percent' => ?0..100, 'bri' => ?0..255, 'kelvin' => ?, 'color' => ?int (0xRRGGBB),
     *           'mode' => auto|brightness|color_temp|hs]
     */
    public static function Power(array $profile, array $state): ?float
    {
        $standby = (float)($profile['standby'] ?? 0);
        if (empty($state['on'])) {
            return $standby;
        }
        $standbyOn = (float)($profile['standbyOn'] ?? 0);
        $strategy = (string)($profile['strategy'] ?? 'fixed');
        if ($strategy === 'fixed') {
            return (float)($profile['fixed']['power'] ?? 0) + $standbyOn;
        }
        if ($strategy === 'linear') {
            $lin = $profile['linear'] ?? [];
            $scale = (float)($lin['scale'] ?? 100);
            // ohne Wertquelle: Volllast annehmen
            $percent = isset($state['percent']) ? (float)$state['percent'] : 100.0;
            $p = self::Linear($lin['calibrate'] ?? [], $percent / 100 * $scale, isset($lin['gamma']) ? (float)$lin['gamma'] : null);
            return $p === null ? null : $p + $standbyOn;
        }
        if ($strategy === 'lut') {
            return self::LutStatePower($profile, $state);
        }
        return null;
    }

    private static function LutStatePower(array $profile, array $state): ?float
    {
        $luts = $profile['luts'] ?? [];
        $supported = array_values(array_filter(self::LUT_MODES, fn($m) => !empty($luts[$m])));
        if (!$supported) {
            return null;
        }
        $hasKelvin = isset($state['kelvin']) && (float)$state['kelvin'] > 0;
        $hasColor = isset($state['color']) && $state['color'] !== null;
        $mode = self::SelectMode($supported, (string)($state['mode'] ?? 'auto'), $hasKelvin, $hasColor);
        $bri = isset($state['bri']) ? (float)$state['bri'] : null;
        if ($mode === 'brightness' && !empty($luts['brightness'])) {
            return self::LutPower($luts['brightness'], 'brightness', (int)round($bri ?? 255));
        }
        // Lampe ohne Helligkeitstabelle oder ohne bekannte Farbe: warmweiß (2700 K) annehmen, statt nichts zu liefern
        $kelvin = $hasKelvin ? (float)$state['kelvin'] : self::DEFAULT_KELVIN;
        if ($mode === 'color_temp' || ($mode === 'brightness' && !empty($luts['color_temp']))) {
            if (!$hasKelvin && !empty($luts['brightness'])) {
                return self::LutPower($luts['brightness'], 'brightness', (int)round($bri ?? 255));
            }
            return self::LutPower($luts['color_temp'], 'color_temp', (int)round($bri ?? 255), self::KelvinToMired($kelvin));
        }
        // hs: aus der Farbe, sonst aus der Farbtemperatur (wie Powercalc, wenn die Lampe eine Weißlicht-Tabelle nicht hat)
        if ($hasColor) {
            [$h, $s, $v] = self::ColorIntToHsv((int)$state['color']);
            if ($bri === null) {
                $bri = $v / 100 * 255;
            }
        } else {
            [$h, $s] = self::KelvinToHs($kelvin);
        }
        return self::LutPower($luts['hs'], 'hs', (int)round($bri ?? 255), 0, (int)($h / 360 * 65535), (int)($s / 100 * 255));
    }

    // -----------------------------------------------------------------------
    // Energie
    // -----------------------------------------------------------------------

    /**
     * Zählerstand fortschreiben: die zuletzt bekannte Leistung gilt bis jetzt. Ist die Lücke größer als $maxGapSec
     * (Symcon war aus, Quelle tot), wird sie nicht überbrückt — der Zähler bleibt stehen statt zu raten.
     */
    public static function Integrate(float $energyKWh, float $lastPowerW, float $dtSec, float $maxGapSec): float
    {
        if ($dtSec <= 0 || $dtSec > $maxGapSec || !is_finite($lastPowerW) || $lastPowerW < 0) {
            return $energyKWh;
        }
        return $energyKWh + $lastPowerW * $dtSec / 3600000.0;
    }

    // -----------------------------------------------------------------------
    // Profile aus der Powercalc-Datenbank
    // -----------------------------------------------------------------------

    /**
     * Profil aus der model.json eines Datenbank-Eintrags (ohne Messtabellen; die kommen separat).
     * Nicht unterstützte Rechenarten (composite, multi_switch, ...) ergeben ['error' => 'strategy'].
     */
    public static function ProfileFromModel(array $model, string $manufacturer, string $id): array
    {
        $strategy = (string)($model['calculation_strategy'] ?? '');
        $p = [
            'source'       => 'powercalc',
            'manufacturer' => $manufacturer,
            'id'           => $id,
            'name'         => (string)($model['name'] ?? $id),
            'strategy'     => $strategy,
            'standby'      => (float)($model['standby_power'] ?? 0),
            'standbyOn'    => isset($model['standby_power_on']) ? (float)$model['standby_power_on']
                : (!empty($model['only_self_usage']) ? (float)($model['standby_power'] ?? 0) : 0.0),
            'deviceType'   => (string)($model['device_type'] ?? ''),
        ];
        if ($strategy === 'fixed') {
            $power = $model['fixed_config']['power'] ?? null;
            if ($power !== null && !is_numeric($power)) {
                // Vorlage (Template) statt Zahl: nicht abbildbar
                return ['error' => 'strategy'] + $p;
            }
            $p['fixed'] = ['power' => $power === null ? 0.0 : (float)$power];
            return $p;
        }
        if ($strategy === 'linear') {
            $cfg = $model['linear_config'] ?? [];
            if (!is_array($cfg) || (!isset($cfg['calibrate']) && !isset($cfg['max_power']))) {
                // Profil ohne Linear-Angaben: nur Eigenverbrauch (wie Powercalc: Standby-an)
                $p['strategy'] = 'fixed';
                $p['fixed'] = ['power' => 0.0];
                return $p;
            }
            $attribute = (string)($cfg['attribute'] ?? '');
            $isLight = $p['deviceType'] === 'light';
            $scale = (in_array($attribute, ['percentage', 'volume_level'], true) || !$isLight) ? 100 : 255;
            if (isset($cfg['calibrate'])) {
                $cal = [];
                foreach ((array)$cfg['calibrate'] as $line) {
                    $parts = explode(' -> ', (string)$line);
                    if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                        $cal[] = [(float)$parts[0], (float)$parts[1]];
                    }
                }
            } else {
                $min = (float)($cfg['min_power'] ?? $p['standby']);
                $cal = [[0.0, $min], [(float)$scale, (float)$cfg['max_power']]];
            }
            if (count($cal) < 2) {
                return ['error' => 'strategy'] + $p;
            }
            $p['linear'] = ['calibrate' => $cal, 'scale' => $scale];
            if (isset($cfg['gamma_curve']) && is_numeric($cfg['gamma_curve'])) {
                $p['linear']['gamma'] = (float)$cfg['gamma_curve'];
            }
            if (isset($cfg['power_curve'])) {
                // Powercalc-Kurve (Punktliste): nicht abgebildet — lieber ehrlich ablehnen als falsch rechnen
                return ['error' => 'strategy'] + $p;
            }
            return $p;
        }
        if ($strategy === 'lut') {
            if ((int)($model['sub_profile_count'] ?? 0) > 0 || isset($model['sub_profile_select']) || isset($model['sub_profile'])) {
                return ['error' => 'subprofile'] + $p;
            }
            return $p;
        }
        return ['error' => 'strategy'] + $p;
    }

    /** Ein Datenbank-Eintrag ist hier einsetzbar (fest, linear oder Messtabelle ohne Unterprofile). */
    public static function IndexEntrySupported(array $model): bool
    {
        $s = (string)($model['calculation_strategy'] ?? '');
        if (!in_array($s, ['fixed', 'linear', 'lut'], true)) {
            return false;
        }
        return !($s === 'lut' && (int)($model['sub_profile_count'] ?? 0) > 0);
    }

    /**
     * Sucht in library.json der Datenbank: alle Suchwörter müssen im Hersteller- oder Modelltext vorkommen.
     *
     * @return array[] je Treffer ['manufacturer' => Verzeichnis, 'id' => Modell, 'label' => Anzeigetext, 'strategy', 'supported', 'modes' => []]
     */
    public static function SearchIndex(array $index, string $query, int $limit = 25): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], fn($w) => $w !== ''));
        if (!$words) {
            return [];
        }
        $out = [];
        foreach ((array)($index['manufacturers'] ?? []) as $m) {
            $mText = mb_strtolower(implode(' ', array_merge([(string)($m['name'] ?? ''), (string)($m['full_name'] ?? ''), (string)($m['dir_name'] ?? '')], (array)($m['aliases'] ?? []))));
            foreach ((array)($m['models'] ?? []) as $md) {
                $text = $mText . ' ' . mb_strtolower(implode(' ', array_merge([(string)($md['name'] ?? ''), (string)($md['id'] ?? '')], (array)($md['aliases'] ?? []))));
                $ok = true;
                foreach ($words as $w) {
                    if (!str_contains($text, $w)) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
                $out[] = [
                    'manufacturer' => (string)($m['dir_name'] ?? ''),
                    'id'           => (string)($md['id'] ?? ''),
                    'label'        => trim((string)($m['full_name'] ?? $m['name'] ?? '') . ' – ' . (string)($md['name'] ?? $md['id'] ?? '') . ' (' . (string)($md['id'] ?? '') . ')'),
                    'strategy'     => (string)($md['calculation_strategy'] ?? ''),
                    'type'         => (string)($md['device_type'] ?? ''),
                    'supported'    => self::IndexEntrySupported($md),
                    'modes'        => array_values(array_filter((array)($md['color_modes'] ?? []), fn($c) => in_array($c, self::LUT_MODES, true))),
                ];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }
}

/**
 * Minimaler HTTP-Abruf für die Powercalc-Datenbank (nur GET, TLS-Prüfung an, Größenbegrenzung).
 * $rawBase ist für die Prüfstände überschreibbar (lokaler Server statt GitHub).
 */
class MHUBE_Http
{
    /** Verzeichnis der Datenbank im Powercalc-Repository (Rohdateien). */
    public static string $rawBase = 'https://raw.githubusercontent.com/bramstroker/homeassistant-powercalc/master/profile_library/';

    /** @return array ['code' => HTTP-Status (0 bei Verbindungsfehler), 'body' => string, 'error' => string] */
    public static function Get(string $url, int $timeout = 20, int $maxBytes = 8000000): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'body' => '', 'error' => 'curl is not available'];
        }
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'NRG-Stack-MeterHub',
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0;   // Abbruch
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $tooBig ? 'response too large' : curl_error($ch);
        return ['code' => $code, 'body' => $tooBig ? '' : $body, 'error' => $err];
    }
}
