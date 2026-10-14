<?php
/**
 * Endpoint chiamato ogni minuto da cron-jobs.org:
 *   https://TUOSITO/cron.php?key=CRON_SECRET
 *
 * Verifica la chiave, esegue il Controller e stampa un breve riepilogo.
 */
require __DIR__ . '/src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$cfg = config();
$key = $_GET['key'] ?? '';
$expected = (string)($cfg['cron_secret'] ?? '');

if ($expected === '' || $expected === 'CAMBIA_QUESTA_CHIAVE' || !hash_equals($expected, (string)$key)) {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
}

$r = Controller::run();

echo strtoupper($r['action'] ?? 'unknown') . "\n";
echo "time:    " . ($r['time'] ?? '') . "\n";
echo "target:  " . (($r['target'] ?? false) ? 'ON' : 'OFF') . "\n";
if (isset($r['command'])) {
    echo "command: " . $r['command'] . "\n";
}
if (isset($r['message'])) {
    echo "message: " . $r['message'] . "\n";
}
