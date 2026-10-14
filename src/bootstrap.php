<?php
/**
 * Bootstrap comune: costanti, autoload minimale, caricamento config.
 * NON avvia la sessione (lo fanno solo le pagine della dashboard).
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('SRC_DIR', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');

// Autoload semplice delle classi in src/
spl_autoload_register(function (string $class): void {
    $file = SRC_DIR . '/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once SRC_DIR . '/helpers.php';

/**
 * Carica e mette in cache la configurazione.
 */
function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Manca config.php. Copia config.example.php in config.php e compilalo.');
        }
        $cfg = require $file;
        if (!is_array($cfg)) {
            http_response_code(500);
            exit('config.php non valido: deve restituire un array.');
        }
    }
    return $cfg;
}

// Fuso orario predefinito coerente con lo schedule.
$__cfg = config();
date_default_timezone_set($__cfg['timezone'] ?? 'Europe/Rome');
unset($__cfg);
