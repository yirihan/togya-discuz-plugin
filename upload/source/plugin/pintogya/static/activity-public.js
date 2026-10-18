(function () {
  'use strict';
  var uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  document.querySelectorAll('.pintogya-public-content').forEach(function (node) {
    if (node.dataset.ready === '1') { return; }
    node.dataset.ready = '1';
    var list = node.querySelector('.pintogya-public-content-list');
    var more = node.querySelector('.pintogya-public-more');
    var status = node.querySelector('.pintogya-public-status');
    var comments = node.dataset.kind === 'comments';
    var seen = new Set();
    var requests = new Set();
    var busy = false;
    var started = false;

    function error(reason) {
      status.hidden = false;
      status.textContent = reason && reason.message === 'activity_display_pending' ? (node.dataset.labelPending || node.dataset.labelError) : node.dataset.labelError;
    }
    function request(endpoint, fields) {
      var url = new URL(endpoint, window.location.href);
      if (url.origin !== window.location.origin) { return Promise.reject(new Error('public_origin_invalid')); }
      var controller = new AbortController();
      requests.add(controller);
      var timeout = setTimeout(function () { controller.abort(); }, 12000);
      var body = new URLSearchParams(Object.assign({ formhash: node.dataset.formhash, tid: node.dataset.tid }, fields));
      return fetch(url.href, { method: 'POST', credentials: 'same-origin', redirect: 'error',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString(), signal: controller.signal })
        .then(function (response) {
          if (!response.ok) {
            return response.json().then(function (result) { throw new Error(result && result.error === 'activity_display_pending' ? 'activity_display_pending' : 'public_read_failed'); });
          }
          return response.json().then(function (result) {
            if (!result || result.ok !== true || !result.data) { throw new Error('public_response_invalid'); }
            return result.data;
          });
        }).finally(function () { clearTimeout(timeout); requests.delete(controller); });
    }

    function row(item, cursor) {
      if (!item || typeof item.id !== 'string' || !uuid.test(item.id) ||
          typeof item.author !== 'string' || !/^[\p{L}\p{N}]?\*{3}$/u.test(item.author) ||
          typeof item.body !== 'string' || item.body.length > 8192 ||
          typeof item.createdAt !== 'string' || item.createdAt.length > 64 ||
          (comments ? item.parentId !== null && (typeof item.parentId !== 'string' || !uuid.test(item.parentId)) :
            !Array.isArray(item.media) || item.media.length > 6 || item.media.some(function (index, position) { return index !== position; }))) {
        throw new Error('public_item_invalid');
      }
      var li = document.createElement('li');
      if (!comments) {
        li.dataset.checkinId = item.id;
        li.dataset.ratingScore = Number.isSafeInteger(item.interaction && item.interaction.score) ? String(item.interaction.score) : '0';
        li.dataset.commentCount = Number.isSafeInteger(item.interaction && item.interaction.comments) ? String(item.interaction.comments) : '0';
      }
      if (comments && item.parentId !== null) { li.className = 'pintogya-public-reply'; }
      var author = document.createElement('strong'); author.textContent = item.author; li.appendChild(author);
      var text = document.createElement('p'); text.textContent = item.body; li.appendChild(text);
      if (!comments && item.media.length) {
        var gallery = document.createElement('div'); gallery.className = 'pintogya-public-photos';
        item.media.forEach(function (index) {
          var src = Array.isArray(item.imageUrls) ? item.imageUrls[index] : null;
          if (typeof src !== 'string' || !/^\/data\/attachment\/forum\/pintogya\/[a-f0-9]{64}\.(?:jpg|png|webp)$/.test(src)) {
            var pending = document.createElement('p');
            pending.className = 'pintogya-stage-media-pending';
            pending.textContent = node.dataset.labelPending || node.dataset.labelPhoto;
            gallery.appendChild(pending);
            return;
          }
          var photo = document.createElement('img');
          photo.src = src; photo.alt = node.dataset.labelPhoto;
          photo.width = 96; photo.height = 96; photo.tabIndex = 0; photo.loading = 'lazy';
          function enlarge() { if (typeof window.zoom === 'function') { window.zoom(photo, photo.src, 0, 0, 0); } }
          photo.addEventListener('click', enlarge);
          photo.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); enlarge(); }
          });
          gallery.appendChild(photo);
        });
        li.appendChild(gallery);
      }
      if (!comments && typeof window.pintogyaRenderCheckinInteraction === 'function') {
        window.pintogyaRenderCheckinInteraction(li, item.interaction, { tid: node.dataset.tid,
          stageId: node.dataset.stageId, checkinId: item.id, formhash: node.dataset.formhash,
          endpoint: node.dataset.interactionEndpoint, labels: { comment: node.dataset.labelCheckinComment,
            rate: node.dataset.labelCheckinRate, records: node.dataset.labelCheckinRecords,
            opening: node.dataset.labelCheckinOpening, error: node.dataset.labelError } });
      }
      return li;
    }
    function load(cursor) {
      if (busy || document.hidden) { return; }
      busy = true; started = true; more.disabled = true; status.hidden = true;
      node.setAttribute('aria-busy', 'true');
      request(node.dataset.endpoint, { stage_id: node.dataset.stageId, cursor: cursor }).then(function (data) {
        var items = comments ? data.comments : data.items;
        if (!Array.isArray(items) || items.length > 20 ||
            (!comments && data.stageId !== node.dataset.stageId) ||
            (data.nextCursor !== null && (typeof data.nextCursor !== 'string' ||
              !/^[A-Za-z0-9_-]{1,2048}$/.test(data.nextCursor) || data.nextCursor === cursor))) {
          throw new Error('public_page_invalid');
        }
        var fragment = document.createDocumentFragment();
        var added = [];
        items.forEach(function (item) {
          var entry = row(item, cursor);
          if (!seen.has(item.id) && !added.includes(item.id)) { added.push(item.id); fragment.appendChild(entry); }
        });
        list.appendChild(fragment);
        if (!comments && typeof window.pintogyaSortCheckins === 'function') { window.pintogyaSortCheckins(list); }
        added.forEach(function (id) { seen.add(id); });
        more.dataset.cursor = data.nextCursor || ''; more.hidden = data.nextCursor === null;
        if (!seen.size) { status.hidden = false; status.textContent = node.dataset.labelEmpty; }
      }).catch(function (reason) { more.hidden = false; more.dataset.cursor = cursor; error(reason); })
        .finally(function () { busy = false; more.disabled = false; node.setAttribute('aria-busy', 'false'); });
    }
    more.addEventListener('click', function () { load(more.dataset.cursor || ''); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !started) { load(''); } });
    window.addEventListener('pagehide', function (event) {
      requests.forEach(function (controller) { controller.abort(); });
    });
    load('');
  });
})();
