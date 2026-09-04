<?php
/**
 * Logiktest ohne WordPress.
 *
 * Ausführen:
 *   docker run --rm -v "$PWD:/app" -w /app php:8.4-cli php tests/test-cost-display.php
 */
define('ABSPATH', '/tmp/');

require_once __DIR__ . '/../includes/class-cost-display.php';

use TEC_Addons\Cost_Display;

$pass = 0; $fail = 0;
function check(string $label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; return; }
    $fail++;
    echo "FEHLER: $label\n   erwartet: " . var_export($expected, true) . "\n   bekommen: " . var_export($actual, true) . "\n";
}

/* ---------- läuft auf "kostenlos" hinaus ---------- */
check('nackte Null',          Cost_Display::reads_as_free('0', 'Kostenlos'), true);
check('0,00 mit Währung',     Cost_Display::reads_as_free('0,00 €', 'Kostenlos'), true);
check('0.00 mit Symbol',      Cost_Display::reads_as_free('$0.00', 'Kostenlos'), true);
check('übersetztes Wort',     Cost_Display::reads_as_free('Kostenlos', 'Kostenlos'), true);
check('englisches Free',      Cost_Display::reads_as_free('Free', 'Free'), true);
check('Groß-/Kleinschreibung', Cost_Display::reads_as_free('kostenlos', 'Kostenlos'), true);

/* ---------- bleibt stehen ---------- */
check('leer bleibt unberührt', Cost_Display::reads_as_free('', 'Kostenlos'), false);
check('echter Betrag',         Cost_Display::reads_as_free('15 €', 'Kostenlos'), false);
check('Dezimalbetrag',         Cost_Display::reads_as_free('12,50 €', 'Kostenlos'), false);
check('Spanne mit Betrag',     Cost_Display::reads_as_free('Kostenlos – 20,00 €', 'Kostenlos'), false);
check('Text ohne Zahl',        Cost_Display::reads_as_free('Eintritt frei', 'Kostenlos'), false);
check('Spende',                Cost_Display::reads_as_free('Spende erbeten', 'Kostenlos'), false);

echo "\n$pass Prüfungen bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
