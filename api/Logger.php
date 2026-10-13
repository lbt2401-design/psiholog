<?php
/**
 * Logger — серверный логгер (PHP 8.1+).
 *
 * Пишет по одной JSON-строке на событие в data/logs/app-ГГГГ-ММ-ДД.log.
 * Формат совместим с ELK / Loki / Cloud Logging: их агенты умеют читать такие файлы.
 *
 * Пример записи ошибки:
 * {"timestamp":"2026-09-29T12:00:00+03:00","level":"error","requestId":"a1b2c3d4",
 *  "module":"send","message":"CSV write failed","context":{"file":"leads.csv"},
 *  "error":{"class":"RuntimeException","message":"...","file":"...","line":42,"trace":"#0 ..."}}
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];

    private static ?string $requestId = null;

    public function __construct(
        private string $module,
        private string $dir,
        private string $minLevel = 'info'
    ) {
    }

    /** Один ID на весь HTTP-запрос — по нему находятся все связанные записи. */
    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(4));
    }

    public function debug(string $msg, array $ctx = []): void { $this->write('debug', $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void { $this->write('info', $msg, $ctx); }
    public function warn(string $msg, array $ctx = []): void { $this->write('warn', $msg, $ctx); }

    public function error(string $msg, ?\Throwable $e = null, array $ctx = []): void
    {
        $this->write('error', $msg, $ctx, $e);
    }

    private function write(string $level, string $msg, array $ctx, ?\Throwable $e = null): void
    {
        if (self::LEVELS[$level] < (self::LEVELS[$this->minLevel] ?? 20)) {
            return;
        }
        $entry = [
            'timestamp' => date('c'),
            'level'     => $level,
            'requestId' => self::requestId(),
            'module'    => $this->module,
            'message'   => $msg,
            'context'   => $ctx,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        if ($e !== null) {
            $entry['error'] = [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ];
        }
        try {
            if (!is_dir($this->dir)) {
                mkdir($this->dir, 0750, true);
            }
            $file = $this->dir . '/app-' . date('Y-m-d') . '.log';
            file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            error_log('[Logger] ' . json_encode($entry, JSON_UNESCAPED_UNICODE)); // запасной вариант — лог хостинга
        }
    }
}
