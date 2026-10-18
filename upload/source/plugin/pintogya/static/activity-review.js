(function () {
  document.querySelectorAll('.pintogya-activity-review-queue').forEach(function (queue) {
    if (queue.dataset.ready === '1') { return; }
    queue.dataset.ready = '1';
    var button = queue.querySelector('.pintogya-activity-review-queue-more');
    var list = queue.querySelector('.pintogya-activity-review-queue-list');
    var status = queue.querySelector('.pintogya-activity-review-queue-status');
    var cursor = 0;
    var pending = null;
    var mediaUrls = [];
    var generation = 0;

    function reset() {
      generation++;
      if (pending) { pending.abort(); pending = null; }
      mediaUrls.forEach(function (url) { URL.revokeObjectURL(url); });
      mediaUrls = [];
      cursor = 0;
      list.replaceChildren();
      button.hidden = false;
      button.textContent = queue.dataset.labelShow;
      button.disabled = false;
      status.hidden = true;
      status.textContent = '';
    }

    function request(endpoint, fields, asImage) {
      if (pending) { pending.abort(); }
      var controller = new AbortController();
      pending = controller;
      var timeout = setTimeout(function () { controller.abort(); }, 12000);
      var body = new URLSearchParams(Object.assign({
        formhash: queue.dataset.formhash, tid: queue.dataset.tid
      }, fields));
      return fetch(endpoint, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), signal: controller.signal })
        .then(function (response) {
          if (!response.ok) { throw new Error('review_request_failed'); }
          return asImage ? response.blob() : response.json();
        }).finally(function () {
          clearTimeout(timeout);
          if (pending === controller) { pending = null; }
        });
    }

    function addRejectAction(detail, reviewPk) {
      var actions = document.createElement('div');
      actions.className = 'pintogya-activity-review-decisions';
      var message = document.createElement('p');
      message.setAttribute('role', 'status');
      message.hidden = true;
      var action = document.createElement('button');
      action.type = 'button';
      action.textContent = queue.dataset.labelReject;
      action.addEventListener('click', function () {
        var current = generation;
        action.disabled = true;
        request(queue.dataset.rejectEndpoint, { review_pk: String(reviewPk) }, false)
          .then(function (result) {
            if (current !== generation) { return; }
            if (!result || !result.ok || !result.data || result.data.state !== 'rejected') {
              throw new Error('review_reject_failed');
            }
            reset();
            status.hidden = false;
            status.textContent = queue.dataset.labelRejected;
          }).catch(function () {
            if (current !== generation) { return; }
            message.textContent = queue.dataset.labelUnavailable;
            message.hidden = false;
            action.disabled = false;
          });
      });
      actions.appendChild(action);
      actions.appendChild(message);
      detail.appendChild(actions);
    }

    function showDetail(entry, reviewPk, action) {
      var current = generation;
      action.disabled = true;
      request(queue.dataset.detailEndpoint, { review_pk: String(reviewPk) }, false)
        .then(function (result) {
          if (current !== generation) { return; }
          var item = result && result.ok && result.data;
          if (!item || item.reviewPk !== reviewPk || item.kind !== 'checkin' ||
              typeof item.author !== 'string' || item.author.length > 200 ||
              typeof item.body !== 'string' || item.body.length > 8192 ||
              typeof item.createdAt !== 'string' || item.createdAt.length > 64 ||
              typeof item.hasMedia !== 'boolean' ||
              typeof item.stageId !== 'string' || !/^[0-9a-f-]{36}$/.test(item.stageId) ||
              typeof item.checkinId !== 'string' || !/^[0-9a-f-]{36}$/.test(item.checkinId) ||
              typeof item.cursor !== 'string' ||
              (item.cursor !== '' && !/^[A-Za-z0-9_-]{1,2048}$/.test(item.cursor))) {
            throw new Error('review_detail_invalid');
          }
          var detail = document.createElement('div');
          detail.className = 'pintogya-activity-review-detail';
          var byline = document.createElement('strong');
          byline.textContent = item.author + ' · ' + item.createdAt;
          var body = document.createElement('p');
          body.textContent = item.body;
          detail.appendChild(byline);
          detail.appendChild(body);
          if (item.hasMedia) {
            var photo = document.createElement('button');
            photo.type = 'button';
            photo.textContent = queue.dataset.labelPhoto;
            photo.addEventListener('click', function () {
              var photoGeneration = generation;
              photo.disabled = true;
              request(queue.dataset.imageEndpoint, { stage_id: item.stageId,
                cursor: item.cursor, checkin_id: item.checkinId }, true)
                .then(function (blob) {
                  if (photoGeneration !== generation) { return; }
                  if (!blob || !['image/jpeg', 'image/png', 'image/webp'].includes(blob.type) ||
                      blob.size < 1 || blob.size > 12582912) { throw new Error('review_image_invalid'); }
                  var url = URL.createObjectURL(blob);
                  mediaUrls.push(url);
                  var image = document.createElement('img');
                  image.src = url;
                  image.alt = queue.dataset.labelPhoto;
                  image.loading = 'lazy';
                  detail.appendChild(image);
                  photo.hidden = true;
                  addRejectAction(detail, reviewPk);
                }).catch(function () {
                  if (photoGeneration === generation) { photo.textContent = queue.dataset.labelUnavailable; }
                }).finally(function () {
                  if (photoGeneration === generation) { photo.disabled = false; }
                });
            });
            detail.appendChild(photo);
          } else {
            addRejectAction(detail, reviewPk);
          }
          entry.appendChild(detail);
          action.hidden = true;
        }).catch(function () {
          if (current === generation) { action.textContent = queue.dataset.labelUnavailable; }
        }).finally(function () {
          if (current === generation) { action.disabled = false; }
        });
    }

    function load() {
      var current = generation;
      button.disabled = true;
      status.hidden = false;
      status.textContent = queue.dataset.labelLoading;
      request(queue.dataset.queueEndpoint, { after: String(cursor) }, false)
        .then(function (result) {
          if (current !== generation) { return; }
          var data = result && result.ok && result.data;
          if (!data || !Array.isArray(data.items) || data.items.length > 20 ||
              !Number.isSafeInteger(data.nextCursor) || data.nextCursor < cursor ||
              typeof data.hasMore !== 'boolean') { throw new Error('review_queue_invalid'); }
          var fragment = document.createDocumentFragment();
          data.items.forEach(function (item) {
            if (!item || !Number.isSafeInteger(item.reviewPk) || item.reviewPk <= cursor ||
                !Number.isSafeInteger(item.uid) || item.uid < 1 ||
                !['checkin', 'completion'].includes(item.kind) ||
                !Number.isSafeInteger(item.submittedAt) || item.submittedAt < 0 ||
                !Number.isSafeInteger(item.autoDueAt) || item.autoDueAt < 0 ||
                typeof item.canDecide !== 'boolean') { throw new Error('review_queue_item_invalid'); }
            var entry = document.createElement('li');
            var title = document.createElement('span');
            title.textContent = '#' + item.uid + ' · ' +
              (item.kind === 'checkin' ? queue.dataset.labelCheckin : queue.dataset.labelCompletion) +
              ' · ' + new Date(item.submittedAt * 1000).toLocaleString();
            entry.appendChild(title);
            if (item.autoDueAt > 0) {
              var due = document.createElement('small');
              due.textContent = queue.dataset.labelDue + ': ' +
                new Date(item.autoDueAt * 1000).toLocaleString();
              entry.appendChild(due);
            }
            if (item.kind === 'checkin' && item.canDecide) {
              var detailButton = document.createElement('button');
              detailButton.type = 'button';
              detailButton.textContent = queue.dataset.labelDetail;
              detailButton.addEventListener('click', function () {
                showDetail(entry, item.reviewPk, detailButton);
              });
              entry.appendChild(detailButton);
            } else if (item.kind === 'completion' && item.canDecide) {
              addRejectAction(entry, item.reviewPk);
            }
            fragment.appendChild(entry);
          });
          list.appendChild(fragment);
          cursor = data.nextCursor;
          button.hidden = !data.hasMore;
          button.textContent = queue.dataset.labelMore;
          status.hidden = data.items.length !== 0 || data.hasMore;
          status.textContent = status.hidden ? '' : queue.dataset.labelEmpty;
        }).catch(function () {
          if (current === generation) { status.textContent = queue.dataset.labelError; }
        }).finally(function () {
          if (current === generation) { button.disabled = false; }
        });
    }

    button.addEventListener('click', load);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { reset(); }
    });
    window.addEventListener('focus', reset);
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) { reset(); }
    });
  });
})();
