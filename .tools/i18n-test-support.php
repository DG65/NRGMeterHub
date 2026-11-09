<?php
/**
 * Gemeinsame Hilfen der Prüfstände für die Zweisprachigkeit (SUITE.md Punkt 9).
 *
 * Standard: der Prüfstand simuliert die DEUTSCHE Oberfläche — $this->Translate() liefert den deutschen Text aus
 * locale.json, und cf() übersetzt das Formular wie die Konsole es tut (caption/label/confirm …). So bleiben die
 * deutschen Prüftexte der alten Prüfungen gültig.
 * Mit I18N_LANG=en (per putenv in einzelnen Blöcken, z. B. test-virtual.php Block 62) simuliert er die ENGLISCHE Oberfläche (Quelltexte unverändert). Die älteren Prüfungen erwarten deutsche Texte und laufen daher nur in der deutschen Simulation.
 */
function i18n_lang(): string
{
    return getenv('I18N_LANG') === 'en' ? 'en' : 'de';
}

/** Alle deutschen Übersetzungen der vier Module (Englisch => Deutsch). */
function i18n_de_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (glob(__DIR__ . '/../*/locale.json') as $f) {
            $d = json_decode((string)file_get_contents($f), true);
            $map += $d['translations']['de'] ?? [];
        }
    }
    return $map;
}

function i18n_tr(string $text): string
{
    return i18n_lang() === 'de' ? (i18n_de_map()[$text] ?? $text) : $text;
}

trait I18nStub
{
    public function Translate($text)
    {
        return i18n_tr((string)$text);
    }
}

/** Formular-Schlüssel, die die Konsole selbst übersetzt (Checkliste 25). */
function i18n_console(array $node): array
{
    foreach ($node as $k => $v) {
        if (is_array($v)) {
            $node[$k] = i18n_console($v);
        } elseif (is_string($v) && in_array($k, ['caption', 'label', 'title', 'confirm', 'suffix', 'help'], true)) {
            $node[$k] = i18n_tr($v);
        }
    }
    return $node;
}

/** Formular eines Moduls wie in der Konsole: bei Deutsch werden die Formulartexte übersetzt, bei Englisch nicht. */
function cf($module): string
{
    $json = $module->GetConfigurationForm();
    if (i18n_lang() === 'en') {
        return $json;
    }
    $d = json_decode($json, true);
    return json_encode(i18n_console(is_array($d) ? $d : []), JSON_UNESCAPED_UNICODE);
}

/** Wörter, die nur in den deutschen Übersetzungen vorkommen (nicht in englischen Quelltexten) — Wortschatz zum Aufspüren deutscher Reste. */
function i18n_german_only_words(): array
{
    static $only = null;
    if ($only === null) {
        $de = []; $en = [];
        $wd = fn(string $s) => array_map('mb_strtolower', preg_match_all('/[A-Za-zÄÖÜäöüß]{4,}/u', $s, $m) ? $m[0] : []);
        $files = array_merge(glob(__DIR__ . '/../*/locale.json'), glob(__DIR__ . '/../../*/*/locale.json'));
        foreach (array_unique($files) as $f) {
            $j = json_decode((string)file_get_contents($f), true);
            foreach (($j['translations']['de'] ?? []) as $k => $v) {
                foreach ($wd($v) as $w) { $de[$w] = true; }
                foreach ($wd($k) as $w) { $en[$w] = true; }
            }
        }
        $allow = ['modbus', 'symcon', 'meterhub', 'inexogy', 'discovergy', 'janitza', 'siemens', 'eastron', 'shelly', 'phoenix', 'whatwatt', 'socomec',
            'symbox', 'scada', 'power', 'control', 'float', 'gateway', 'community', 'forum', 'watt', 'kwh', 'blue'];
        $only = array_diff_key($de, $en, array_flip($allow));
    }
    return $only;
}

/** Alle Texte eines Formulars (caption, label, title, confirm, suffix, onClick-Texte …), die noch deutsche Wörter enthalten. */
function i18n_german_leftovers(array $node, array &$out = []): array
{
    $only = i18n_german_only_words();
    foreach ($node as $k => $v) {
        if (is_array($v)) { i18n_german_leftovers($v, $out); continue; }
        if (!is_string($v) || !in_array($k, ['caption', 'label', 'title', 'confirm', 'suffix', 'help', 'onClick'], true)) { continue; }
        if (preg_match('/[äöüÄÖÜß]/u', $v) || array_filter(preg_match_all('/[A-Za-zÄÖÜäöüß]{4,}/u', $v, $m) ? $m[0] : [], fn($w) => isset($only[mb_strtolower($w)]))) {
            $out[$v] = true;
        }
    }
    return array_keys($out);
}
