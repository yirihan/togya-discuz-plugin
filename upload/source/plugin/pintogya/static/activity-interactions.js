(function () {
  'use strict';
  // Lucide 0.468.0; see static/vendor/lucide-LICENSE.txt.
  function actionIcon(action) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    [['viewBox', '0 0 24 24'], ['width', '18'], ['height', '18'], ['fill', 'none'],
      ['stroke', 'currentColor'], ['stroke-width', '2'], ['stroke-linecap', 'round'],
      ['stroke-linejoin', 'round'], ['aria-hidden', 'true'], ['focusable', 'false']].forEach(function (a) {
      svg.setAttribute(a[0], a[1]);
    });
    var path = document.createElementNS(ns, 'path');
    path.setAttribute('d', action === 'comment' ? 'M7.9 20A9 9 0 1 0 4 16.1L2 22Z' :
      'M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z');
    svg.appendChild(path); return svg;
  }
  window.pintogyaSortCheckins = function (list) {
    Array.from(list.children).sort(function (a, b) {
      for (var field of ['ratingScore', 'commentCount']) {
        var order = Number(b.dataset[field] || 0) - Number(a.dataset[field] || 0);
        if (order) { return order; }
      }
      var left = a.dataset.checkinId || '', right = b.dataset.checkinId || '';
      return right < left ? -1 : right > left ? 1 : 0;
    }).forEach(function (entry) { list.appendChild(entry); });
  };
  window.pintogyaRenderCheckinInteraction = function (target, meta, context) {
    if (!meta || typeof meta.canComment !== 'boolean' || typeof meta.canRate !== 'boolean' ||
        (meta.pid !== null && (!Number.isInteger(meta.pid) || meta.pid < 1 || meta.pid > 4294967295)) ||
        typeof meta.cursor !== 'string' || !/^[A-Za-z0-9_-]{0,2048}$/.test(meta.cursor) ||
        !['comments', 'ratings'].every(function (k) { return Number.isInteger(meta[k]) && meta[k] >= 0; })) { return; }
    if (!meta.canComment && !meta.canRate && meta.pid === null) { return; }
    var endpoint;
    try {
      endpoint = new URL(context.endpoint, window.location.href);
      if (endpoint.origin !== window.location.origin || !endpoint.pathname.endsWith('/plugin.php') ||
          endpoint.searchParams.get('id') !== 'pintogya:activity' || endpoint.searchParams.get('op') !== 'interaction_prepare') { return; }
    } catch (ignored) { return; }
    var bar = document.createElement('div'); bar.className = 'pintogya-checkin-interactions';
    var busy = false;
    var status = document.createElement('span'); status.setAttribute('role', 'status');
    var labels = context.labels || {};
    function nativeUrl(value, action, pid) {
      var url = new URL(value, window.location.href);
      if (url.origin !== window.location.origin || !url.pathname.endsWith('/forum.php') ||
          url.searchParams.get('mod') !== 'misc' || url.searchParams.get('action') !== action ||
          url.searchParams.get('tid') !== String(context.tid) || url.searchParams.get('pid') !== String(pid)) {
        throw new Error('native_interaction_url_invalid');
      }
      return url.href;
    }
    function show(action) {
      if (busy) { return; } busy = true; status.textContent = labels.opening || 'Opening...';
      var controller = new AbortController(); var timeout = setTimeout(function () { controller.abort(); }, 12000);
      var body = new URLSearchParams({ tid: context.tid, stage_id: context.stageId, checkin_id: context.checkinId,
        cursor: meta.cursor, action: action, formhash: context.formhash });
      fetch(endpoint.href, { method: 'POST', credentials: 'same-origin', redirect: 'error',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString(), signal: controller.signal })
        .then(function (response) { return response.json().then(function (result) {
          if (!response.ok || !result || result.ok !== true || !result.data ||
              !Number.isInteger(result.data.pid) || result.data.pid < 1) { throw new Error('native_interaction_unavailable'); }
          var data = result.data; var href = nativeUrl(data.href, action, data.pid);
          if (typeof window.showWindow === 'function') { window.showWindow(action, href); }
          else { window.location.assign('forum.php?mod=redirect&goto=findpost&ptid=' + context.tid + '&pid=' + data.pid); }
          status.textContent = '';
        }); }).catch(function () { status.textContent = labels.error || 'Unavailable'; })
        .finally(function () { clearTimeout(timeout); busy = false; });
    }
    [['comment', meta.canComment, labels.comment || 'Comment'], ['rate', meta.canRate, labels.rate || 'Rate']]
      .forEach(function (item) {
        if (!item[1]) { return; }
        var button = document.createElement('button'); button.type = 'button';
        button.className = 'pintogya-checkin-action'; button.title = item[2];
        button.setAttribute('aria-label', item[2]); button.appendChild(actionIcon(item[0]));
        button.addEventListener('click', function (event) { event.preventDefault(); show(item[0]); }); bar.appendChild(button);
      });
    if (meta.pid !== null) {
      var records = document.createElement('a'); records.className = 'xi2';
      records.href = 'forum.php?mod=redirect&goto=findpost&ptid=' + context.tid + '&pid=' + meta.pid;
      records.textContent = (labels.records || 'Comments and ratings') + ' (' + meta.comments + ' / ' + meta.ratings + ')';
      bar.appendChild(records);
    }
    bar.appendChild(status); target.appendChild(bar);
  };
})();
