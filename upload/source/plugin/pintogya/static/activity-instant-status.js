(function () {
  'use strict';
  var states = ['paid', 'pending', 'waiting_sync', 'waiting_policy', 'waiting_access', 'blocked', 'no_reward', 'unavailable'];
  var sources = ['waiting_sync', 'waiting_access', 'blocked', 'synced', 'not_participating'];
  function label(node, name) {
    return node.dataset['label' + name.split('_').map(function (part) {
      return part.charAt(0).toUpperCase() + part.slice(1);
    }).join('')];
  }
  document.querySelectorAll('.pintogya-instant-status').forEach(function (node) {
    if (node.dataset.ready === '1') { return; }
    node.dataset.ready = '1';
    var root = node.parentElement;
    var viewer = root.querySelector('.pintogya-activity-viewer');
    var snapshot = null;
    var message = label(node, 'checking');
    var controller = null;
    var serial = 0;
    var timer = 0;
    var nextAt = 0;
    function removeRows() {
      root.querySelectorAll('[data-pintogya-reward-status]').forEach(function (row) { row.remove(); });
    }
    function clear() { snapshot = null; removeRows(); }
    function badge(item, data) {
      var state = document.createElement(item.state === 'paid' ? 'a' : 'span');
      state.className = 'pintogya-stage-reward';
      state.dataset.state = item.state;
      state.dataset.pintogyaRewardStatus = '1';
      state.textContent = item.amount + ' ' + data.credit + ' · ' + label(node, item.state);
      if (item.state === 'paid') {
        state.href = 'home.php?mod=spacecp&ac=credit&op=log';
        state.title = label(node, 'log') + ' · ' + new Date(item.paidAt * 1000).toLocaleString();
      }
      return state;
    }
    function paint() {
      removeRows();
      if (document.hidden) { return; }
      var personal = root.querySelector('.pintogya-activity-stages');
      var section = personal && !personal.hidden ? personal : root.querySelector('.pintogya-activity-public-stages');
      if (!section) { return; }
      var summary = document.createElement('p');
      summary.className = 'pintogya-stage-reward-summary';
      summary.dataset.pintogyaRewardStatus = '1';
      summary.setAttribute('role', 'status');
      summary.setAttribute('aria-live', 'polite');
      summary.textContent = message;
      section.insertBefore(summary, section.querySelector('ol'));
      if (!snapshot) { return; }
      var rows = section.querySelectorAll('li[data-stage-id]');
      snapshot.items.forEach(function (item) {
        if (item.kind === 'base') {
          var base = document.createElement('p');
          base.className = 'pintogya-stage-reward-base';
          base.dataset.pintogyaRewardStatus = '1';
          base.appendChild(document.createTextNode(label(node, 'base') + ': '));
          base.appendChild(badge(item, snapshot));
          section.appendChild(base);
          return;
        }
        rows.forEach(function (row) {
          if (row.dataset.stageId === item.stageId) {
            row.insertBefore(badge(item, snapshot), row.querySelector('p'));
          }
        });
      });
    }
    function render(data) {
      if (!data || data.model !== 'instant' || !Number.isInteger(data.creditId) || data.creditId < 1 || data.creditId > 8 ||
          typeof data.credit !== 'string' || !data.credit || data.credit.length > 120 ||
          !sources.includes(data.sourceState) || !Number.isInteger(data.checkedAt) || data.checkedAt < 0 ||
          data.checkedAt > 4294967295 || !Array.isArray(data.items) || !data.items.length || data.items.length > 101) {
        throw new Error('reward_status_invalid');
      }
      var seen = new Set();
      data.items.forEach(function (item) {
        if (!item || !['stage', 'base'].includes(item.kind) || !states.includes(item.state) ||
            typeof item.title !== 'string' || item.title.length > 500 || !Number.isInteger(item.amount) ||
            item.amount < 0 || item.amount > 2147483647 ||
            (item.kind === 'base' ? item.stageId !== null : typeof item.stageId !== 'string' ||
              !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(item.stageId)) ||
            (item.state === 'paid' ? item.amount === 0 || !Number.isInteger(item.paidAt) || item.paidAt < 1 ||
              item.paidAt > 4294967295 || !Number.isInteger(item.creditLogId) || item.creditLogId < 1 ||
              item.creditLogId > 4294967295 : item.paidAt !== null || item.creditLogId !== null)) {
          throw new Error('reward_item_invalid');
        }
        var key = item.kind === 'base' ? 'base' : item.stageId;
        if (seen.has(key)) { throw new Error('reward_item_duplicate'); }
        seen.add(key);
      });
      snapshot = data;
      message = label(node, data.sourceState) + (data.checkedAt > 0 ?
        ' · ' + label(node, 'checked') + ': ' + new Date(data.checkedAt * 1000).toLocaleString() : '');
      paint();
    }
    function refresh() {
      if (document.hidden || controller || Date.now() < nextAt) { return; }
      var current = ++serial;
      controller = new AbortController();
      var pending = controller;
      var timeout = setTimeout(function () { pending.abort(); }, 12000);
      nextAt = Date.now() + 60000;
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid });
      fetch(node.dataset.endpoint, { method: 'POST', credentials: 'same-origin', redirect: 'error',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString(), signal: pending.signal })
        .then(function (response) { if (!response.ok) { throw new Error('reward_status_failed'); } return response.json(); })
        .then(function (result) {
          if (current !== serial) { return; }
          if (!result || result.ok !== true) { throw new Error('reward_status_failed'); }
          render(result.data);
        }).catch(function () {
          if (current !== serial) { return; }
          clear(); message = label(node, 'unavailable'); paint();
        }).finally(function () {
          clearTimeout(timeout);
          if (current === serial) { controller = null; timer = setTimeout(refresh, 60000); }
        });
    }
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        ++serial; clearTimeout(timer);
        if (controller) { controller.abort(); controller = null; }
        clear(); message = label(node, 'checking');
      } else { nextAt = 0; refresh(); }
    });
    window.addEventListener('pagehide', function () {
      ++serial; clearTimeout(timer);
      if (controller) { controller.abort(); controller = null; }
      clear();
    });
    window.addEventListener('pageshow', function (event) { if (event.persisted) { nextAt = 0; refresh(); } });
    if (viewer) { viewer.addEventListener('pintogya:stages-rendered', paint); }
    paint();
    refresh();
  });
})();
