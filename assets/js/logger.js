/**
 * Logger — переиспользуемый модуль логирования для браузера.
 *
 * Уровни: debug < info < warn < error.
 * Каждая запись — JSON-объект с контекстом (sessionId, функция, параметры),
 * который можно отправить в любую внешнюю систему (Sentry, ELK, свой сервер).
 *
 * Использование:
 *   const log = Logger.create('form');          // 'form' — имя модуля
 *   log.info('submit', { phone: '+7***81' });
 *   log.error('submit failed', err, { status: 500 });
 */
(function (global) {
  'use strict';

  var LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };
  var config = global.SITE_CONFIG || {};
  var minLevel = LEVELS[config.logLevel] || LEVELS.info;

  // ID сессии посетителя: связывает все записи одного визита
  var sessionId = (function () {
    try {
      var id = sessionStorage.getItem('log_session_id');
      if (!id) {
        id = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
        sessionStorage.setItem('log_session_id', id);
      }
      return id;
    } catch (e) {
      return 'no-storage-' + Math.random().toString(36).slice(2, 8);
    }
  })();

  function serializeError(err) {
    if (!err) return null;
    return { name: err.name, message: err.message, stack: err.stack || null };
  }

  /** Отправка на сервер: только warn и error, чтобы не засорять логи. */
  function sendRemote(entry) {
    if (!config.logEndpoint || LEVELS[entry.level] < LEVELS.warn) return;
    try {
      var body = JSON.stringify(entry);
      if (navigator.sendBeacon) {
        navigator.sendBeacon(config.logEndpoint, new Blob([body], { type: 'application/json' }));
      } else {
        fetch(config.logEndpoint, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true });
      }
    } catch (e) { /* логгер никогда не должен ломать сайт */ }
  }

  function write(level, module, message, error, context) {
    if (LEVELS[level] < minLevel) return;
    var entry = {
      timestamp: new Date().toISOString(),
      level: level,
      module: module,
      message: message,
      sessionId: sessionId,
      page: location.pathname,
      context: context || {},
      error: serializeError(error),
      userAgent: navigator.userAgent
    };
    var out = console[level === 'debug' ? 'log' : level] || console.log;
    out.call(console, '[' + level.toUpperCase() + '] [' + module + '] ' + message, entry);
    sendRemote(entry);
  }

  var Logger = {
    sessionId: sessionId,
    create: function (module) {
      return {
        debug: function (msg, ctx) { write('debug', module, msg, null, ctx); },
        info: function (msg, ctx) { write('info', module, msg, null, ctx); },
        warn: function (msg, ctx) { write('warn', module, msg, null, ctx); },
        error: function (msg, err, ctx) { write('error', module, msg, err, ctx); }
      };
    }
  };

  // Ловим все необработанные ошибки на странице
  var globalLog = Logger.create('window');
  global.addEventListener('error', function (e) {
    globalLog.error('Uncaught error', e.error || new Error(e.message), { source: e.filename, line: e.lineno });
  });
  global.addEventListener('unhandledrejection', function (e) {
    var err = e.reason instanceof Error ? e.reason : new Error(String(e.reason));
    globalLog.error('Unhandled promise rejection', err);
  });

  global.Logger = Logger;
})(window);
