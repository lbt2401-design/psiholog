<?php
/**
 * Приём ошибок из браузера (warn/error от assets/js/logger.js).
 * Сохраняет их в тот же лог-файл, что и серверные ошибки, с пометкой module=browser.
 */
declare(strict_types=1);

require __DIR__ . '/Logger.php';

$configFile = __DIR__ . '/config.php';
$config = require (is_file($configFile) ? $configFile : __DIR__ . '/config.example.php');
$log = new Logger('browser', $config['data_dir'] . '/logs', 'warn');

$raw = file_get_contents('php://input', false, null, 0, 20000) ?: ''; // не больше 20 КБ
$entry = json_decode($raw, true);

if (is_array($entry) && in_array($entry['level'] ?? '', ['warn', 'error'], true)) {
    $log->warn('client ' . ($entry['level']) . ': ' . mb_substr((string)($entry['message'] ?? ''), 0, 200), [
        'client' => $entry,
    ]);
}
http_response_code(204);
