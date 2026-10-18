(function () {
  'use strict';

  function guardSubmit(form) {
    if (form.dataset.pintogyaGuarded === '1') {
      return;
    }
    form.dataset.pintogyaGuarded = '1';
    var button = form.querySelector('button[type="submit"]');
    var cooldown = parseInt(form.getAttribute('data-pintogya-cooldown') || '0', 10);
    if (button && cooldown > 0) {
      button.disabled = true;
      window.setTimeout(function () { button.disabled = false; }, Math.min(cooldown, 900) * 1000);
    }
    form.addEventListener('submit', function (event) {
      if (form.dataset.pintogyaSubmitting === '1') {
        event.preventDefault();
        return;
      }
      form.dataset.pintogyaSubmitting = '1';
      if (button) {
        button.disabled = true;
      }
    });
  }

  function initSubmitGuards() {
    var forms = document.querySelectorAll('form[data-pintogya-submit-guard="1"]');
    for (var index = 0; index < forms.length; index += 1) {
      guardSubmit(forms[index]);
    }
  }

  function startPendingQrForm() {
    var form = document.querySelector('form[data-pintogya-autostart="1"]');
    if (!form || form.dataset.pintogyaSubmitted === '1') {
      return;
    }
    form.dataset.pintogyaSubmitted = '1';
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
      return;
    }
    form.submit();
  }

  function taskActionMatches(value, taskId, action) {
    try {
      var url = new window.URL(value, document.baseURI);
      return url.origin === window.location.origin &&
        url.searchParams.get('mod') === 'task' &&
        url.searchParams.get('do') === action &&
        url.searchParams.get('id') === String(taskId);
    } catch (ignored) {
      return false;
    }
  }

  function submitNativeDraw(link, progressForm) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = link.href;
    var formhash = progressForm.querySelector('input[name="formhash"]');
    if (!formhash || !formhash.value) {
      return;
    }
    [['formhash', formhash ? formhash.value : ''], ['drawsubmit', 'yes']].forEach(function (field) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = field[0];
      input.value = field[1];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  function updateNativeProgress(form, progress) {
    var taskId = parseInt(form.getAttribute('data-pintogya-task-id') || '0', 10);
    var ready = progress >= 100;
    var counter = document.getElementById('csc_' + taskId);
    if (counter) {
      counter.textContent = ready ? '100' : '0';
      var progressBox = counter.closest ? counter.closest('.pbg') : null;
      var bar = progressBox ? progressBox.querySelector('.pbr') : null;
      if (bar) {
        bar.style.width = ready ? '100%' : '8px';
      }
    }

    var forms = document.querySelectorAll('form[action]');
    for (var formIndex = 0; formIndex < forms.length; formIndex += 1) {
      if (!taskActionMatches(forms[formIndex].action, taskId, 'draw')) {
        continue;
      }
      var button = forms[formIndex].querySelector('button[type="submit"]');
      if (button) {
        button.disabled = !ready;
        button.classList.toggle('taskrw', ready);
        button.classList.toggle('taskda', !ready);
        button.setAttribute('aria-disabled', ready ? 'false' : 'true');
      }
    }

    var links = document.querySelectorAll('a[href]');
    for (var linkIndex = 0; linkIndex < links.length; linkIndex += 1) {
      var link = links[linkIndex];
      if (taskActionMatches(link.href, taskId, 'delete') && ready) {
        link.style.display = 'none';
      }
      if (!taskActionMatches(link.href, taskId, 'draw')) {
        continue;
      }
      link.dataset.pintogyaReady = ready ? '1' : '0';
      link.classList.toggle('taskrw', ready);
      link.classList.toggle('taskda', !ready);
      link.setAttribute('aria-disabled', ready ? 'false' : 'true');
      if (link.dataset.pintogyaDrawBound !== '1') {
        link.dataset.pintogyaDrawBound = '1';
        link.addEventListener('click', function (event) {
          event.preventDefault();
          if (this.dataset.pintogyaReady === '1') {
            submitNativeDraw(this, form);
          }
        });
      }
    }
  }

  function startProgressSync(form) {
    if (!window.fetch || form.dataset.pintogyaProgressStarted === '1') {
      return;
    }
    form.dataset.pintogyaProgressStarted = '1';
    var status = document.querySelector('[data-pintogya-auto-status="1"]');
    var expiresAt = parseInt(form.getAttribute('data-pintogya-expires-at') || '0', 10) * 1000;
    var interval = parseInt(form.getAttribute('data-pintogya-interval') || '15000', 10);
    var taskId = parseInt(form.getAttribute('data-pintogya-task-id') || '0', 10);
    if (!taskActionMatches(form.action, taskId, 'view')) {
      return;
    }
    var stopped = false;
    var timer = 0;

    function setStatus(name) {
      var value = form.getAttribute('data-pintogya-' + name) || '';
      if (status && value) {
        status.textContent = value;
      }
    }

    function schedule(delay) {
      if (stopped) {
        return;
      }
      window.clearTimeout(timer);
      timer = window.setTimeout(poll, delay);
    }

    function poll() {
      if (stopped) {
        return;
      }
      if (expiresAt > 0 && Date.now() > expiresAt) {
        stopped = true;
        setStatus('expired');
        return;
      }
      if (document.hidden) {
        schedule(2000);
        return;
      }
      window.fetch(form.action, {
        method: 'POST',
        body: new window.FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('http_' + response.status);
        }
        return response.json();
      }).then(function (payload) {
        if (!payload || payload.ok !== true || parseInt(payload.taskId, 10) !== taskId) {
          throw new Error('invalid_progress_response');
        }
        var progress = parseInt(payload.csc, 10) >= 100 ? 100 : 0;
        updateNativeProgress(form, progress);
        if (progress === 100) {
          stopped = true;
          setStatus('ready');
          return;
        }
        setStatus('waiting');
        schedule(interval);
      }).catch(function () {
        setStatus('retrying');
        schedule(Math.min(Math.max(interval * 2, 8000), 60000));
      });
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !stopped) {
        schedule(250);
      }
    });
    var counter = document.getElementById('csc_' + taskId);
    updateNativeProgress(form, counter && parseFloat(counter.textContent || '0') >= 100 ? 100 : 0);
    schedule(350);
  }

  function initProgressSync() {
    var forms = document.querySelectorAll('form[data-pintogya-progress-sync="1"]');
    for (var index = 0; index < forms.length; index += 1) {
      startProgressSync(forms[index]);
    }
  }

  function init() {
    initSubmitGuards();
    startPendingQrForm();
    initProgressSync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
