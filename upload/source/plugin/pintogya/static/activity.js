(function () {
  'use strict';

  function init(composer) {
    var form = composer.closest('form');
    if (!form || form.id !== 'postform') { return; }
    form.classList.add('pintogya-activity-form');
    var captcha = form.querySelector('.post_from > ul input[name="secanswer"], .post_from > ul input[name="seccodehash"]');
    if (captcha) { captcha.closest('ul').classList.add('pintogya-activity-captcha'); }

    var status = composer.querySelector('.pintogya-activity-status');
    var qrBox = composer.querySelector('.pintogya-activity-qr');
    var qrImage = qrBox.querySelector('img');
    var list = composer.querySelector('.pintogya-activity-list');
    var items = composer.querySelector('.pintogya-activity-items');
    var restart = composer.querySelector('.pintogya-activity-restart');
    var refresh = composer.querySelector('.pintogya-activity-refresh');
    var publish = composer.querySelector('.pintogya-activity-publish');
    var tokenField = composer.querySelector('input[name="pintogya_action_token"]');
    var formhash = form.querySelector('input[name="formhash"]');
    var serial = 0;
    var timer = 0;
    var qrExpiry = 0;
    var tokenExpiry = 0;
    var submitting = false;

    function label(name) { return composer.getAttribute('data-label-' + name) || ''; }
    function show(name) { status.textContent = label(name); }
    function resetSelection() {
      tokenField.value = '';
      tokenExpiry = 0;
      publish.disabled = true;
    }
    function schedule(delay, callback) {
      window.clearTimeout(timer);
      timer = window.setTimeout(callback, delay);
    }
    function request(operation, activityId) {
      if (!window.fetch || !formhash || !formhash.value) {
        return Promise.reject(new Error('activity_unavailable'));
      }
      var url = new window.URL(composer.getAttribute('data-endpoint'), document.baseURI);
      if (url.origin !== window.location.origin) {
        return Promise.reject(new Error('activity_unavailable'));
      }
      url.searchParams.set('op', operation);
      var body = new window.FormData();
      body.append('formhash', formhash.value);
      if (activityId) { body.append('activity_id', activityId); }
      return window.fetch(url.href, {
        method: 'POST', body: body, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || payload.ok !== true || !payload.data) {
            throw new Error(payload && payload.error || 'activity_unavailable');
          }
          return payload.data;
        });
      });
    }
    function failure(error) {
      resetSelection();
      refresh.hidden = true;
      var code = error && error.message || '';
      show(code === 'activity_already_mapped' ? 'mapped' : 'error');
    }
    function loadActivities(current) {
      qrBox.hidden = true;
      list.hidden = false;
      items.replaceChildren();
      refresh.hidden = false;
      show('loading');
      request('list').then(function (data) {
        if (serial !== current) { return; }
        if (!Array.isArray(data.activities)) { throw new Error('activity_unavailable'); }
        if (data.activities.length === 0) { show('empty'); return; }
        show('ready');
        data.activities.forEach(function (activity) {
          if (!activity || typeof activity.id !== 'string' || typeof activity.title !== 'string') { return; }
          var row = document.createElement('div');
          row.className = 'pintogya-activity-row';
          var description = document.createElement('div');
          var title = document.createElement('strong');
          title.textContent = activity.title;
          description.appendChild(title);
          if (activity.summary) {
            var summary = document.createElement('p');
            summary.textContent = activity.summary;
            description.appendChild(summary);
          }
          var button = document.createElement('button');
          button.type = 'button';
          button.textContent = label('select');
          button.addEventListener('click', function () {
            if (tokenField.value) { return; }
            button.disabled = true;
            request('select', activity.id).then(function (selected) {
              if (serial !== current) { return; }
              if (!selected || !selected.activity || selected.activity.id !== activity.id ||
                  typeof selected.token !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(selected.token) ||
                  !Number.isInteger(selected.expiresAt) || selected.expiresAt * 1000 <= Date.now()) {
                throw new Error('activity_unavailable');
              }
              tokenField.value = selected.token;
              tokenExpiry = selected.expiresAt * 1000;
              publish.disabled = false;
              refresh.hidden = true;
              items.replaceChildren(row);
              button.textContent = label('selected');
              button.disabled = true;
              status.textContent = label('selected') + ': ' + activity.title;
            }).catch(function (error) {
              if (serial !== current) { return; }
              button.disabled = false;
              failure(error);
            });
          });
          row.appendChild(description);
          row.appendChild(button);
          items.appendChild(row);
        });
      }).catch(function (error) {
        if (serial === current) { failure(error); }
      });
    }
    function poll(current) {
      if (serial !== current) { return; }
      if (qrExpiry && Date.now() >= qrExpiry) {
        qrBox.hidden = true;
        show('expired');
        return;
      }
      if (document.hidden) { schedule(2000, function () { poll(current); }); return; }
      request('poll').then(function (data) {
        if (serial !== current) { return; }
        if (data.state === 'approved') { loadActivities(current); return; }
        if (data.state !== 'pending') { throw new Error('activity_unavailable'); }
        schedule(5000, function () { poll(current); });
      }).catch(function (error) {
        if (serial !== current) { return; }
        show('error');
        schedule(10000, function () { poll(current); });
      });
    }
    function start() {
      var current = ++serial;
      window.clearTimeout(timer);
      qrExpiry = 0;
      qrImage.removeAttribute('src');
      qrBox.hidden = true;
      list.hidden = true;
      refresh.hidden = true;
      resetSelection();
      show('connecting');
      request('start').then(function (data) {
        if (serial !== current) { return; }
        if (data.state === 'approved') { loadActivities(current); return; }
        if (data.state !== 'pending' || typeof data.qrImage !== 'string' ||
            !/^data:image\/(?:png|svg\+xml);base64,[A-Za-z0-9+/=]+$/.test(data.qrImage) ||
            !Number.isInteger(data.expiresAt) || data.expiresAt * 1000 <= Date.now()) {
          throw new Error('activity_unavailable');
        }
        qrExpiry = data.expiresAt * 1000;
        qrImage.src = data.qrImage;
        qrBox.hidden = false;
        show('pending');
        schedule(5000, function () { poll(current); });
      }).catch(function (error) {
        if (serial === current) { failure(error); }
      });
    }

    restart.addEventListener('click', start);
    refresh.addEventListener('click', function () { resetSelection(); loadActivities(serial); });
    publish.addEventListener('click', function () {
      if (submitting || publish.disabled || !tokenField.value) { return; }
      if (Date.now() >= tokenExpiry - 5000) {
        resetSelection();
        show('expired');
        return;
      }
      submitting = true;
      publish.disabled = true;
      show('publishing');
      window.HTMLFormElement.prototype.submit.call(form);
    });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && qrExpiry && !qrBox.hidden) { schedule(100, function () { poll(serial); }); }
    });
    start();
  }

  function boot() {
    var composers = document.querySelectorAll('.pintogya-activity-composer');
    for (var i = 0; i < composers.length; i += 1) { init(composers[i]); }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }
})();
