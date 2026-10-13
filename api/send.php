<?php
/**
 * Приём заявки с формы (PHP 8.1+).
 *  1. Проверяет данные и отсекает спам.
 *  2. Добавляет строку в таблицу data/leads.csv (открывается в Excel).
 *  3. Отправляет письмо психологу.
 *
 * Ответ: {"ok":true,"leadId":"..."} или {"ok":false,"error":"..."}
 */
declare(strict_types=1);

require __DIR__ . '/Logger.php';

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.php';
$config = require (is_file($configFile) ? $configFile : __DIR__ . '/config.example.php');
$log = new Logger('send', $config['data_dir'] . '/logs', $config['log_level']);

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Method not allowed']);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) {
        $log->warn('bad json');
        respond(400, ['ok' => false, 'error' => 'Bad request']);
    }

    // --- Антиспам: ловушка и скорость заполнения. Боту отвечаем «ок», чтобы он не пробовал снова.
    if (!empty($input['website']) || (int)($input['fillSeconds'] ?? 0) < $config['min_fill_seconds']) {
        $log->info('spam blocked', ['fillSeconds' => $input['fillSeconds'] ?? null]);
        respond(200, ['ok' => true, 'leadId' => 'x']);
    }

    // --- Проверка полей
    $clean = static fn($v, int $max) => mb_substr(trim(strip_tags((string)($v ?? ''))), 0, $max);
    $name    = $clean($input['name'] ?? '', 80);
    $phone   = preg_replace('/\D+/', '', (string)($input['phone'] ?? ''));
    $format  = $clean($input['format'] ?? '', 20);
    $message = $clean($input['message'] ?? '', 1000);
    $t       = is_array($input['tracking'] ?? null) ? $input['tracking'] : [];

    if (mb_strlen($name) < 2 || strlen($phone) !== 11 || empty($input['consent'])) {
        $log->info('validation failed', ['nameLen' => mb_strlen($name), 'phoneLen' => strlen($phone)]);
        respond(422, ['ok' => false, 'error' => 'Проверьте имя, телефон и согласие']);
    }
    $phoneFormatted = sprintf('+7 %s %s-%s-%s', substr($phone, 1, 3), substr($phone, 4, 3), substr($phone, 7, 2), substr($phone, 9, 2));
    $leadId = date('ymd-His') . '-' . Logger::requestId();

    // --- Запись в таблицу (CSV с разделителем «;» и BOM — так русский Excel открывает без «кракозябр»)
    $csv = $config['data_dir'] . '/leads.csv';
    $isNew = !is_file($csv);
    $fh = fopen($csv, 'ab');
    if ($fh === false) {
        throw new RuntimeException('Cannot open leads.csv for writing');
    }
    flock($fh, LOCK_EX);
    if ($isNew) {
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['ID', 'Дата', 'Имя', 'Телефон', 'Формат', 'Запрос', 'Источник', 'Кампания', 'Объявление', 'Ключевая фраза', 'yclid', 'Статус', 'Комментарий'], ';');
    }
    // Защита от «формул» в Excel: значения, начинающиеся с = + - @, экранируем апострофом
    $safe = static fn(string $v) => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    fputcsv($fh, array_map($safe, [
        $leadId, date('d.m.Y H:i'), $name, $phoneFormatted, $format, $message,
        (string)($t['utm_source'] ?? 'прямой заход'), (string)($t['utm_campaign'] ?? ''),
        (string)($t['utm_content'] ?? ''), (string)($t['utm_term'] ?? ''), (string)($t['yclid'] ?? ''),
        'Новая', '',
    ]), ';');
    flock($fh, LOCK_UN);
    fclose($fh);

    // --- Письмо психологу
    $subject = '=?UTF-8?B?' . base64_encode("Новая заявка: $name") . '?=';
    $body = "Новая заявка на консультацию\n\n"
          . "Имя: $name\nТелефон: $phoneFormatted\nФормат: $format\n"
          . "Запрос: " . ($message !== '' ? $message : '—') . "\n\n"
          . "Источник: " . ($t['utm_source'] ?? 'прямой заход') . ' / ' . ($t['utm_campaign'] ?? '') . "\n"
          . "ID заявки: $leadId\n";
    $headers = "From: {$config['from_email']}\r\nContent-Type: text/plain; charset=utf-8\r\n";
    $mailed = @mail($config['notify_email'], $subject, $body, $headers);
    if (!$mailed) {
        // Заявка уже сохранена в таблице, поэтому клиенту всё равно отвечаем «ок»
        $log->warn('mail() failed', ['leadId' => $leadId, 'to' => $config['notify_email']]);
    }

    $log->info('lead saved', ['leadId' => $leadId, 'source' => $t['utm_source'] ?? null, 'mailed' => $mailed]);
    respond(200, ['ok' => true, 'leadId' => $leadId]);
} catch (Throwable $e) {
    $log->error('lead processing failed', $e);
    respond(500, ['ok' => false, 'error' => 'Server error']);
}
