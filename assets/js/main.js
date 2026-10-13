/**
 * Основная логика лендинга:
 *  1. UTM-метки и yclid из Яндекс Директа — запоминаем, чтобы знать, какое объявление привело клиента.
 *  2. Цели Яндекс.Метрики: клик по телефону, отправка формы.
 *  3. Форма записи: проверка, маска телефона, отправка на сервер.
 *  4. Просмотр сертификатов крупно.
 */
(function () {
  'use strict';

  var config = window.SITE_CONFIG || {};
  var log = Logger.create('main');

  /* ---------- 1. Метки рекламы ---------- */
  var TRACK_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid'];

  function captureTracking() {
    var params = new URLSearchParams(location.search);
    var saved = {};
    try { saved = JSON.parse(sessionStorage.getItem('tracking') || '{}'); } catch (e) { saved = {}; }
    TRACK_PARAMS.forEach(function (key) {
      if (params.get(key)) saved[key] = params.get(key);
    });
    try { sessionStorage.setItem('tracking', JSON.stringify(saved)); } catch (e) { /* приватный режим */ }
    log.debug('tracking captured', saved);
    return saved;
  }
  var tracking = captureTracking();

  /* ---------- 2. Цели Метрики ---------- */
  function reachGoal(goal, params) {
    log.info('goal', { goal: goal });
    if (config.metrikaId && typeof window.ym === 'function') {
      window.ym(config.metrikaId, 'reachGoal', goal, params || {});
    }
  }

  document.querySelectorAll('.js-phone').forEach(function (link) {
    link.addEventListener('click', function () { reachGoal('phone_click'); });
  });

  /* ---------- 3. Форма ---------- */
  var form = document.getElementById('signup-form');
  var status = form.querySelector('.form__status');
  var phoneInput = form.elements.phone;
  var startedAt = Date.now();

  // Простая маска: +7 (905) 567-97-81
  phoneInput.addEventListener('input', function () {
    var digits = phoneInput.value.replace(/\D/g, '');
    if (digits.startsWith('8')) digits = '7' + digits.slice(1);
    if (!digits.startsWith('7')) digits = '7' + digits;
    digits = digits.slice(0, 11);
    var d = digits.slice(1);
    var out = '+7';
    if (d.length) out += ' (' + d.slice(0, 3);
    if (d.length >= 3) out += ') ' + d.slice(3, 6);
    if (d.length >= 6) out += '-' + d.slice(6, 8);
    if (d.length >= 8) out += '-' + d.slice(8, 10);
    phoneInput.value = out;
  });

  function setStatus(text, type) {
    status.textContent = text;
    status.className = 'form__status' + (type ? ' form__status--' + type : '');
  }

  function validate() {
    var errors = [];
    form.querySelectorAll('.invalid').forEach(function (el) { el.classList.remove('invalid'); });
    if (form.elements.name.value.trim().length < 2) { errors.push('имя'); form.elements.name.classList.add('invalid'); }
    if (phoneInput.value.replace(/\D/g, '').length !== 11) { errors.push('телефон'); phoneInput.classList.add('invalid'); }
    if (!form.elements.consent.checked) errors.push('согласие на обработку данных');
    return errors;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var errors = validate();
    if (errors.length) {
      setStatus('Проверьте поля: ' + errors.join(', ') + '.', 'error');
      log.info('validation failed', { fields: errors });
      return;
    }

    var payload = {
      name: form.elements.name.value.trim(),
      phone: phoneInput.value,
      format: form.elements.format.value,
      message: form.elements.message.value.trim(),
      website: form.elements.website.value,          // ловушка для ботов
      fillSeconds: Math.round((Date.now() - startedAt) / 1000),
      consent: true,
      tracking: tracking,
      sessionId: Logger.sessionId,
      page: location.href
    };

    var button = form.querySelector('button[type=submit]');
    button.disabled = true;
    setStatus('Отправляем…');
    // В лог — без персональных данных, только маскированный телефон
    log.info('submit start', { phoneMasked: payload.phone.slice(0, 4) + '***' + payload.phone.slice(-2), format: payload.format });

    fetch(config.formEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok || !data.ok) {
            var err = new Error(data.error || ('HTTP ' + res.status));
            err.status = res.status;
            throw err;
          }
          return data;
        });
      })
      .then(function (data) {
        setStatus('Спасибо! Заявка принята — я перезвоню вам в ближайшее время.', 'ok');
        reachGoal('form_submit', { format: payload.format });
        log.info('submit ok', { leadId: data.leadId });
        form.reset();
      })
      .catch(function (err) {
        setStatus('Не получилось отправить заявку. Пожалуйста, позвоните: +7 905 567-97-81', 'error');
        log.error('submit failed', err, { status: err.status || null, endpoint: config.formEndpoint });
      })
      .finally(function () { button.disabled = false; });
  });

  /* ---------- 4. Сертификаты ---------- */
  var lightbox = document.getElementById('lightbox');
  if (lightbox && typeof lightbox.showModal === 'function') {
    var lightImg = lightbox.querySelector('img');
    document.querySelectorAll('.cert').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        lightImg.src = link.getAttribute('href');
        lightImg.alt = link.querySelector('img').alt;
        lightbox.showModal();
      });
    });
    lightbox.addEventListener('click', function () { lightbox.close(); });
  }

  log.info('page ready', { hasUtm: Object.keys(tracking).length > 0 });
})();
