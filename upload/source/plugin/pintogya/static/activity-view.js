(function () {
  var nodes = document.querySelectorAll('.pintogya-activity-viewer');
  nodes.forEach(function (node) {
    if (node.dataset.ready === '1') { return; }
    node.dataset.ready = '1';
    var checkingLabel = node.textContent;
    var account = node.parentElement.querySelector('.pintogya-activity-account');
    var stages = node.parentElement.querySelector('.pintogya-activity-stages');
    var publicStages = node.parentElement.querySelector('.pintogya-activity-public-stages');
    var stageList = stages ? stages.querySelector('ol') : null;
    var reviewActions = null;
    var participants = node.parentElement.querySelector('.pintogya-activity-participants');
    var controller = null;
    var feedControllers = [];
    var mediaUrls = [];
    var imageJobs = [];
    var activeImages = 0;
    var refreshTimer = 0;
    var serial = 0;
    var reviewStates = Object.create(null);
    var reviewMutation = 0;
    var renderedSignature = '';
    var renderedAt = 0;
    function clearViewer(keepGallery) {
      if (feedControllers.length) { renderedAt = 0; }
      feedControllers.forEach(function (pending) { pending.abort(); });
      feedControllers = [];
      imageJobs.forEach(function (job) {
        if (job.button.pintogyaImagePending === job.identity) { job.button.pintogyaImagePending = false; job.button.hidden = false; }
      });
      imageJobs = [];
      if (!keepGallery) {
        mediaUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        mediaUrls = [];
        renderedSignature = '';
        renderedAt = 0;
      }
      reviewStates = Object.create(null);
      reviewMutation = 0;
      node.dataset.state = 'checking';
      node.textContent = checkingLabel;
      if (account) { account.hidden = true; account.textContent = ''; }
      if (stages && stageList) { stages.hidden = true; if (!keepGallery) { stageList.replaceChildren(); } }
      if (publicStages) { publicStages.hidden = false; }
      if (reviewActions) { reviewActions.remove(); reviewActions = null; }
      node.dispatchEvent(new Event('pintogya:stages-rendered'));
    }
    function pumpImages() {
      while (activeImages < 2 && imageJobs.length) {
        var job = imageJobs.shift();
        activeImages++;
        Promise.resolve().then(job.start).catch(function () {}).finally(function () {
          activeImages--;
          pumpImages();
        });
      }
    }
    function reviewKey(kind, sourceRef) {
      return kind === 'completion' ? 'completion' : 'checkin:' + sourceRef;
    }
    function applyReviewState(button, status, data) {
      if (!data) { return; }
      var label = data.state === 'pending' ? stages.dataset.labelReviewPending :
        data.state === 'approved' ? (data.decisionSource === 'timeout' ?
          stages.dataset.labelReviewApprovedAuto : stages.dataset.labelReviewApproved) :
          data.state === 'rejected' ? stages.dataset.labelReviewRejected :
            stages.dataset.labelReviewInvalid;
      status.textContent = label + (data.state === 'pending' && data.autoDueAt > 0 ?
        ' · ' + stages.dataset.labelReviewAuto + ': ' +
          new Date(data.autoDueAt * 1000).toLocaleString() : '');
      status.hidden = false;
      button.hidden = data.state === 'pending' || data.state === 'approved';
      button.disabled = false;
    }
    function loadReviewStatus() {
      if (!stages || !stages.dataset.reviewStatusEndpoint) { return; }
      var current = serial;
      var mutation = reviewMutation;
      var pending = new AbortController();
      feedControllers.push(pending);
      var timeout = setTimeout(function () { pending.abort(); }, 12000);
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid });
      fetch(stages.dataset.reviewStatusEndpoint, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), signal: pending.signal })
        .then(function (response) { if (!response.ok) { throw new Error('review_status_failed'); } return response.json(); })
        .then(function (result) {
          if (current !== serial || mutation !== reviewMutation) { return; }
          var items = result && result.ok && result.data && result.data.items;
          if (!Array.isArray(items) || items.length > 100) { throw new Error('review_status_invalid'); }
          items.forEach(function (item) {
            if (!item || !['checkin', 'completion'].includes(item.kind) ||
                typeof item.sourceRef !== 'string' || !/^[0-9a-f-]{36}$/.test(item.sourceRef) ||
                !['pending', 'approved', 'rejected', 'invalid'].includes(item.state) ||
                !['none', 'moderator', 'timeout'].includes(item.decisionSource) ||
                !Number.isInteger(item.autoDueAt) || item.autoDueAt < 0) {
              throw new Error('review_status_invalid');
            }
            reviewStates[reviewKey(item.kind, item.sourceRef)] = item;
          });
          stages.querySelectorAll('[data-review-key]').forEach(function (button) {
            applyReviewState(button, button.nextElementSibling,
              reviewStates[button.dataset.reviewKey]);
          });
        }).catch(function () {
          // A status read failure must not submit a review or hide the member's actions.
        }).finally(function () {
          clearTimeout(timeout);
          feedControllers = feedControllers.filter(function (item) { return item !== pending; });
        });
    }
    function submitReview(kind, stageId, checkinId, button, status) {
      var current = serial;
      reviewMutation++;
      button.disabled = true;
      status.hidden = false;
      status.textContent = stages.dataset.labelReviewPending;
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid,
        kind: kind, stage_id: stageId || '', checkin_id: checkinId || '' });
      fetch(stages.dataset.reviewEndpoint, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(function (response) { if (!response.ok) { throw new Error('review_submit_failed'); } return response.json(); })
        .then(function (result) {
          if (current !== serial) { return; }
          var data = result && result.ok && result.data;
          if (!data || !['pending', 'approved', 'rejected', 'invalid'].includes(data.state) ||
              !Number.isInteger(data.autoDueAt) || data.autoDueAt < 0) {
            throw new Error('review_submit_response_invalid');
          }
          var saved = { state: data.state, autoDueAt: data.autoDueAt, decisionSource: 'none' };
          reviewStates[reviewKey(kind, checkinId)] = saved;
          applyReviewState(button, status, saved);
        }).catch(function () {
          if (current !== serial) { return; }
          status.textContent = stages.dataset.labelReviewError;
          button.disabled = false;
        });
    }
    function requestPhotoRemoval(stageId, cursor, mediaId, button, status) {
      if (button.disabled || !stages.dataset.photoRemovalEndpoint) { return; }
      var current = serial;
      var pending = new AbortController();
      feedControllers.push(pending);
      var timeout = setTimeout(function () { pending.abort(); }, 12000);
      button.disabled = true;
      status.textContent = '';
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid,
        stage_id: stageId, cursor: cursor, checkin_id: mediaId });
      fetch(stages.dataset.photoRemovalEndpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), signal: pending.signal
      }).then(function (response) {
        if (!response.ok) { throw new Error('photo_removal_request_failed'); }
        return response.json();
      }).then(function (payload) {
        if (current !== serial) { return; }
        if (!payload || payload.ok !== true || !payload.data || payload.data.state !== 'pending_provider' ||
            typeof payload.data.requestId !== 'string' || !/^[1-9][0-9]{0,19}$/.test(payload.data.requestId)) {
          throw new Error('photo_removal_response_invalid');
        }
        status.textContent = stages.dataset.labelPhotoRemovalPendingShort || stages.dataset.labelPhotoRemovalPending;
        status.title = stages.dataset.labelPhotoRemovalPending;
      }).catch(function () {
        if (current !== serial) { return; }
        status.textContent = stages.dataset.labelPhotoRemovalError;
        button.disabled = false;
      }).finally(function () {
        clearTimeout(timeout);
        feedControllers = feedControllers.filter(function (item) { return item !== pending; });
      });
    }
    function loadImage(stageId, cursor, mediaId, entry, button, imageIndex, imageUrl) {
      if (button.pintogyaImagePending) { return; }
      button.hidden = true;
      function unavailable(text) {
        if (!button.pintogyaPendingStatus) {
          var status = document.createElement('p');
          status.className = 'pintogya-stage-media-pending';
          status.setAttribute('role', 'status');
          entry.appendChild(status); button.pintogyaPendingStatus = status;
        }
        button.pintogyaPendingStatus.textContent = text;
      }
      var endpoint;
      try { endpoint = new URL(stages.dataset.imageEndpoint, window.location.href); } catch (error) {}
      if (!endpoint || endpoint.origin !== window.location.origin) {
        unavailable(stages.dataset.labelPhotoUnavailable || stages.dataset.labelFeedPhoto);
        button.hidden = false;
        return;
      }
      // Never trust a legacy static URL for member media: every new image request passes the native POST gate.
      var current = serial;
      function render(url) {
        var photo = document.createElement('img');
        photo.src = url;
        photo.alt = stages.dataset.labelFeedPhoto;
        photo.width = 96;
        photo.height = 96;
        photo.tabIndex = 0;
        photo.loading = 'lazy';
        function enlarge() {
          if (typeof window.zoom === 'function') { window.zoom(photo, photo.src, 0, 0, 0); }
        }
        photo.addEventListener('click', enlarge);
        photo.addEventListener('keydown', function(event) {
          if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); enlarge(); }
        });
        var gallery = document.createElement('span');
        gallery.className = 'pintogya-checkin-photo';
        gallery.appendChild(photo);
        if (button.pintogyaPendingStatus) { button.pintogyaPendingStatus.remove(); button.pintogyaPendingStatus = null; }
        // Existing removal requests address the primary photo only; do not target it from a secondary photo.
        if (stages.dataset.photoRemovalEndpoint && imageIndex === 0) {
          var removeButton = document.createElement('button');
          removeButton.type = 'button';
          removeButton.className = 'pintogya-photo-removal-button';
          removeButton.textContent = '\u00d7';
          removeButton.title = stages.dataset.labelPhotoRemoval;
          removeButton.setAttribute('aria-label', stages.dataset.labelPhotoRemoval);
          var removeStatus = document.createElement('span');
          removeStatus.className = 'pintogya-photo-removal-status';
          removeStatus.setAttribute('role', 'status');
          removeStatus.setAttribute('aria-live', 'polite');
          removeButton.addEventListener('click', function () {
            requestPhotoRemoval(stageId, cursor, mediaId, removeButton, removeStatus);
          });
          gallery.appendChild(removeButton);
          entry.appendChild(removeStatus);
        }
        entry.appendChild(gallery);
        button.hidden = true;
      photo.addEventListener('error', function () {
        gallery.hidden = true;
        URL.revokeObjectURL(url);
        mediaUrls = mediaUrls.filter(function (item) { return item !== url; });
        unavailable(stages.dataset.labelPhotoUnavailable || stages.dataset.labelFeedPhoto);
        button.hidden = false;
      });
      }
      var jobIdentity = {};
      button.pintogyaImagePending = jobIdentity;
      unavailable(stages.dataset.labelFeedLoading);
      imageJobs.push({ button: button, identity: jobIdentity, start: function () {
        if (current !== serial) {
          if (button.pintogyaImagePending === jobIdentity) { button.pintogyaImagePending = false; button.hidden = false; }
          return;
        }
        var pending = new AbortController();
        feedControllers.push(pending);
        var timeout = setTimeout(function () { pending.abort(); }, 12000);
        var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid,
          stage_id: stageId, cursor: cursor, checkin_id: mediaId, image_index: imageIndex });
        return fetch(endpoint.href, { method: 'POST', credentials: 'same-origin', redirect: 'error',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString(), signal: pending.signal
        }).then(function (response) {
          if (!response.ok) {
            return response.json().then(function (data) { throw new Error(data && data.error === 'activity_display_pending' ? 'activity_display_pending' : 'private_image_denied'); });
          }
          var type = (response.headers.get('Content-Type') || '').toLowerCase();
          var length = Number(response.headers.get('Content-Length'));
          if (['image/jpeg', 'image/png', 'image/webp'].indexOf(type) === -1 || length > 12582912) { throw new Error('private_image_invalid'); }
          return response.blob().then(function (blob) {
            if (blob.size < 1 || blob.size > 12582912 || blob.type !== type) { throw new Error('private_image_invalid'); }
            return blob;
          });
        }).then(function (blob) {
          if (current !== serial) { return; }
          var url = URL.createObjectURL(blob);
          mediaUrls.push(url);
          render(url);
        }).catch(function (error) {
          if (current !== serial) { return; }
          unavailable(error.message === 'activity_display_pending' ?
            (stages.dataset.labelCachePending || stages.dataset.labelFeedLoading) : (stages.dataset.labelPhotoUnavailable || stages.dataset.labelFeedPhoto));
          button.hidden = false;
        }).finally(function () {
          clearTimeout(timeout);
          if (button.pintogyaImagePending === jobIdentity) {
            button.pintogyaImagePending = false;
            if (current !== serial && !entry.querySelector('.pintogya-checkin-photo')) { button.hidden = false; }
          }
          feedControllers = feedControllers.filter(function (item) { return item !== pending; });
        });
      } });
      pumpImages();
    }
    function loadFeed(stageId, feedList, showButton, moreButton, status, cursor) {
      var current = serial;
      var pending = new AbortController();
      feedControllers.push(pending);
      var timeout = setTimeout(function () { pending.abort(); }, 12000);
      showButton.disabled = true;
      moreButton.disabled = true;
      status.hidden = false;
      status.textContent = stages.dataset.labelFeedLoading;
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid,
        stage_id: stageId, cursor: cursor });
      fetch(stages.dataset.feedEndpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), signal: pending.signal
      }).then(function (response) {
        if (!response.ok) {
          return response.json().then(function (result) {
            throw new Error(response.status === 503 && result && result.ok === false && result.error === 'activity_media_sync_pending' ?
              'stage_media_sync_pending' : 'stage_feed_request_failed');
          });
        }
        return response.json();
      }).then(function (result) {
        if (current !== serial) { return; }
        var data = result && result.ok && result.data;
        if (!data || data.stageId !== stageId || !Array.isArray(data.items) || data.items.length > 20 ||
            (data.nextCursor !== null && data.nextCursor === cursor) ||
            (data.nextCursor !== null &&
              (typeof data.nextCursor !== 'string' || !/^[A-Za-z0-9_-]{1,2048}$/.test(data.nextCursor)))) {
          throw new Error('stage_feed_response_invalid');
        }
        var fragment = document.createDocumentFragment();
        data.items.forEach(function (item) {
          if (!item || typeof item.author !== 'string' || item.author.length > 200 ||
              typeof item.body !== 'string' || item.body.length > 8192 ||
              typeof item.createdAt !== 'string' || item.createdAt.length > 64 ||
              typeof item.hasMedia !== 'boolean' ||
              typeof item.isSelf !== 'boolean' ||
              typeof item.checkinId !== 'string' || !/^[0-9a-f-]{36}$/.test(item.checkinId) ||
              (item.mediaId !== null &&
                (typeof item.mediaId !== 'string' || !/^[0-9a-f-]{36}$/.test(item.mediaId)))) {
            throw new Error('stage_feed_item_invalid');
          }
          var entry = document.createElement('li');
          entry.dataset.checkinId = item.checkinId;
          entry.dataset.ratingScore = Number.isSafeInteger(item.interaction && item.interaction.score) ? String(item.interaction.score) : '0';
          entry.dataset.commentCount = Number.isSafeInteger(item.interaction && item.interaction.comments) ? String(item.interaction.comments) : '0';
          if (Array.from(feedList.children).some(function (row) { return row.dataset.checkinId === item.checkinId; })) { return; }
          var sourceCursor = typeof item.sourceCursor === 'string' && /^[A-Za-z0-9_-]{0,2048}$/.test(item.sourceCursor) ? item.sourceCursor : cursor;
          var byline = document.createElement('strong');
          byline.textContent = item.author;
          entry.appendChild(byline);
          var paragraph = document.createElement('p');
          paragraph.textContent = item.body;
          entry.appendChild(paragraph);
          if (item.hasMedia) {
            if (item.mediaId !== null) {
              var indices = item.media === undefined ? [0] : item.media;
              if (!Array.isArray(indices) || indices.length < 1 || indices.length > 6 ||
                  indices.some(function (value, index) { return !Number.isInteger(value) || value !== index; })) {
                throw new Error('stage_feed_item_invalid');
              }
              indices.forEach(function (imageIndex) {
                var imageUrl = Array.isArray(item.imageUrls) ? item.imageUrls[imageIndex] : null;
                var imageButton = document.createElement('button');
                imageButton.type = 'button';
                imageButton.className = 'pintogya-activity-stage-image-show';
                imageButton.textContent = stages.dataset.labelFeedPhoto;
                imageButton.hidden = true;
                imageButton.addEventListener('click', function () {
                  loadImage(stageId, sourceCursor, item.mediaId, entry, imageButton, imageIndex, imageUrl);
                });
                entry.appendChild(imageButton);
                loadImage(stageId, sourceCursor, item.mediaId, entry, imageButton, imageIndex, imageUrl);
              });
            } else {
              var media = document.createElement('p');
              media.className = 'pintogya-activity-stage-media';
              media.textContent = stages.dataset.labelFeedMedia;
              entry.appendChild(media);
            }
          }
          if (item.isSelf && node.dataset.state === 'joined' && stages.dataset.reviewEndpoint) {
            var reviewButton = document.createElement('button');
            reviewButton.type = 'button';
            reviewButton.className = 'pintogya-activity-review-submit';
            reviewButton.dataset.reviewKey = reviewKey('checkin', item.checkinId);
            reviewButton.textContent = stages.dataset.labelReviewCheckin;
            var reviewStatus = document.createElement('span');
            reviewStatus.className = 'pintogya-activity-review-status';
            reviewStatus.setAttribute('role', 'status');
            reviewStatus.hidden = true;
            reviewButton.addEventListener('click', function () {
              submitReview('checkin', stageId, item.checkinId, reviewButton, reviewStatus);
            });
            entry.appendChild(reviewButton);
            entry.appendChild(reviewStatus);
            applyReviewState(reviewButton, reviewStatus, reviewStates[reviewButton.dataset.reviewKey]);
          }
          if (typeof window.pintogyaRenderCheckinInteraction === 'function') {
            window.pintogyaRenderCheckinInteraction(entry, item.interaction, { tid: node.dataset.tid,
              stageId: stageId, checkinId: item.checkinId, formhash: node.dataset.formhash,
              endpoint: stages.dataset.interactionEndpoint, labels: { comment: stages.dataset.labelCheckinComment,
                rate: stages.dataset.labelCheckinRate, records: stages.dataset.labelCheckinRecords,
                opening: stages.dataset.labelCheckinOpening, error: node.dataset.labelUnavailable } });
          }
          fragment.appendChild(entry);
        });
        feedList.appendChild(fragment);
        if (typeof window.pintogyaSortCheckins === 'function') { window.pintogyaSortCheckins(feedList); }
        showButton.hidden = true;
        moreButton.hidden = data.nextCursor === null;
        moreButton.dataset.cursor = data.nextCursor || '';
        status.hidden = data.items.length > 0 || data.nextCursor !== null;
        status.textContent = status.hidden ? '' : stages.dataset.labelFeedEmpty;
      }).catch(function (reason) {
        if (current !== serial) { return; }
        if (reason && reason.message === 'stage_media_sync_pending') {
          status.hidden = false;
          status.textContent = stages.dataset.labelCachePending || stages.dataset.labelFeedLoading;
          showButton.hidden = false; moreButton.hidden = true;
          return;
        }
        // A denied/malformed feed hides that feed only. Local snapshots are not deleted.
        feedList.replaceChildren();
        status.hidden = false; status.textContent = node.dataset.labelUnavailable;
        showButton.hidden = false; moreButton.hidden = true;
      }).finally(function () {
        clearTimeout(timeout);
        feedControllers = feedControllers.filter(function (item) { return item !== pending; });
        if (current === serial) { showButton.disabled = false; moreButton.disabled = false; }
      });
    }
    function stageFragment(items) {
      if (!Array.isArray(items) || items.length < 1 || items.length > 100) {
        throw new Error('viewer_stages_invalid');
      }
      var fragment = document.createDocumentFragment();
      items.forEach(function (item, index) {
        if (!item || item.position !== index || typeof item.title !== 'string' ||
            !item.title.trim() || item.title.length > 500 || typeof item.readable !== 'boolean' ||
            typeof item.feedAvailable !== 'boolean' || typeof item.completed !== 'boolean' ||
            (item.id !== null && (typeof item.id !== 'string' || !/^[0-9a-f-]{36}$/.test(item.id))) ||
            (item.feedAvailable && item.id === null) ||
            typeof item.objective !== 'string' || item.objective.length > 8192 ||
            typeof item.completionCriteria !== 'string' || item.completionCriteria.length > 8192 ||
            typeof item.checkinPrompt !== 'string' || item.checkinPrompt.length > 8192 ||
            (!item.readable && (item.objective || item.completionCriteria || item.checkinPrompt))) {
          throw new Error('viewer_stage_invalid');
        }
        var entry = document.createElement('li');
        if (item.id !== null) { entry.dataset.stageId = item.id; }
        var heading = document.createElement('h4');
        heading.textContent = (index + 1) + '. ' + item.title;
        entry.appendChild(heading);
        if (item.completed || !item.readable) {
          var state = document.createElement('span');
          state.className = 'pintogya-activity-stage-state';
          state.textContent = item.completed ? stages.dataset.labelCompleted : stages.dataset.labelLocked;
          entry.appendChild(state);
        }
        if (item.readable) {
          [['objective', 'labelObjective'], ['completionCriteria', 'labelCriteria'],
            ['checkinPrompt', 'labelPrompt']].forEach(function (field) {
            if (!item[field[0]]) { return; }
            var paragraph = document.createElement('p');
            paragraph.textContent = stages.dataset[field[1]] + ': ' + item[field[0]];
            entry.appendChild(paragraph);
          });
        }
        if (item.feedAvailable) {
          var showButton = document.createElement('button');
          showButton.type = 'button';
          showButton.className = 'pintogya-activity-stage-feed-show';
          showButton.textContent = stages.dataset.labelFeedShow;
          var feedList = document.createElement('ul');
          feedList.className = 'pintogya-activity-stage-feed-list';
          var moreButton = document.createElement('button');
          moreButton.type = 'button';
          moreButton.className = 'pintogya-activity-stage-feed-more';
          moreButton.textContent = stages.dataset.labelFeedMore;
          moreButton.hidden = true;
          var status = document.createElement('p');
          status.className = 'pintogya-activity-stage-feed-status';
          status.hidden = true;
          showButton.addEventListener('click', function () {
            loadFeed(item.id, feedList, showButton, moreButton, status, '');
          });
          moreButton.addEventListener('click', function () {
            loadFeed(item.id, feedList, showButton, moreButton, status, moreButton.dataset.cursor);
          });
          entry.appendChild(showButton);
          entry.appendChild(feedList);
          entry.appendChild(moreButton);
          entry.appendChild(status);
          // Load the readable stage's gallery without a separate "View check-ins" click.
          showButton.hidden = true;
          setTimeout(function() {
            if (!stages.hidden) { loadFeed(item.id, feedList, showButton, moreButton, status, ''); }
          }, 0);
        }
        fragment.appendChild(entry);
      });
      return fragment;
    }
    function refresh() {
      if (document.hidden) { return; }
      var current = ++serial;
      if (controller) { controller.abort(); }
      controller = new AbortController();
      var requestController = controller;
      var timeout = setTimeout(function () { requestController.abort(); }, 12000);
      clearViewer(true);
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid });
      fetch(node.dataset.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), signal: requestController.signal
      }).then(function (response) {
        return response.json().then(function (result) {
          if (!response.ok) {
            throw new Error(result && result.error === 'activity_reconnect_required' ?
              'viewer_reconnect_required' : result && result.error === 'activity_display_pending' ? 'viewer_display_pending' : 'viewer_request_failed');
          }
          return result;
        });
      }).then(function (result) {
        if (current !== serial) { return; }
        var state = result && result.ok && result.data && result.data.state;
        if (state !== 'joined' && state !== 'not_joined') {
          throw new Error('viewer_state_invalid');
        }
        // pintogya_stage_gallery_cache: reuse loaded photos only after fresh account/permission validation.
        var scope = result.data.cacheScope;
        var signature = typeof scope === 'string' && /^[a-f0-9]{64}$/.test(scope) ?
          JSON.stringify([scope, state, result.data.stages, result.data.personalCompleted, result.data.accountRef]) : '';
        var lease = result.data.expiresAt;
        var leaseValid = lease === 0 && result.data.syncPending !== true;
        var reuse = signature && signature === renderedSignature && (leaseValid || Date.now() - renderedAt < 60000);
        if (!reuse) {
          mediaUrls.forEach(function (url) { URL.revokeObjectURL(url); });
          mediaUrls = [];
          if (stageList) { stageList.replaceChildren(); }
        }
        var stageNodes = state === 'joined' && result.data.syncPending !== true && stages && stageList && !reuse ? stageFragment(result.data.stages) : null;
        renderedSignature = signature;
        if (!reuse) { renderedAt = Date.now(); }
        node.dataset.state = state;
        node.textContent = node.dataset[state === 'joined' ? 'labelJoined' : 'labelNotJoined'];
        if (result.data.syncPending === true) { node.textContent = node.dataset.labelUpdating || node.textContent; }
        var joinControl = node.parentElement.querySelector('.pintogya-activity-join');
        if (joinControl && typeof result.data.forumJoined === 'boolean') {
          joinControl.dataset.forumJoined = result.data.forumJoined ? '1' : '0';
          joinControl.dispatchEvent(new Event(result.data.forumJoinPending === true ? 'pintogya:participation-pending' : 'pintogya:participation'));
        }
        if (stages && stageList && (stageNodes || reuse)) {
          if (stageNodes) { stageList.replaceChildren(stageNodes); }
          stages.hidden = false;
          if (result.data.syncPending === true) { stages.hidden = true; }
          // pintogya_public_stages_stable: keep the public fallback unless the personal view is actually ready.
          if (publicStages) { publicStages.hidden = !stages.hidden && stageList.children.length > 0; }
          if (state === 'joined' && result.data.personalCompleted === true && stages.dataset.reviewEndpoint) {
            reviewActions = document.createElement('div');
            reviewActions.className = 'pintogya-activity-review-actions';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'pintogya-activity-review-submit';
            button.dataset.reviewKey = 'completion';
            button.textContent = stages.dataset.labelReviewCompletion;
            var status = document.createElement('span');
            status.className = 'pintogya-activity-review-status';
            status.setAttribute('role', 'status');
            status.hidden = true;
            button.addEventListener('click', function () {
              submitReview('completion', null, null, button, status);
            });
            reviewActions.appendChild(button);
            reviewActions.appendChild(status);
            applyReviewState(button, status, reviewStates.completion);
            stageList.parentElement.insertBefore(reviewActions, stageList);
          }
        }
        if (state === 'joined' && result.data.syncPending !== true) { loadReviewStatus(); }
        node.dispatchEvent(new Event('pintogya:stages-rendered'));
        var accountRef = result.data.accountRef;
        if (account && typeof accountRef === 'string' &&
            /^[A-Za-z0-9._:-]{1,200}$/.test(accountRef)) {
          account.textContent = account.dataset.label + ': ' + accountRef;
          account.hidden = false;
        }
      }).catch(function (error) {
        if (current !== serial) { return; }
        clearViewer();
        var reconnect = error && error.message === 'viewer_reconnect_required';
        node.dataset.state = reconnect ? 'reconnect' : 'unavailable';
        node.textContent = reconnect ? node.dataset.labelReconnect : error && error.message === 'viewer_display_pending' ?
          (node.dataset.labelPending || node.dataset.labelUnavailable) : node.dataset.labelUnavailable;
        var joinControl = node.parentElement.querySelector('.pintogya-activity-join');
        if (joinControl) { joinControl.dispatchEvent(new Event('pintogya:participation-unavailable')); }
      }).finally(function () {
        clearTimeout(timeout);
        if (current === serial) { controller = null; }
      });
    }
    function scheduleRefresh() {
      if (document.hidden) { return; }
      ++serial;
      if (controller) { controller.abort(); controller = null; }
      clearViewer(true);
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(refresh, 150);
    }
    node.addEventListener('pintogya:refresh', function () { clearViewer(); refresh(); });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        ++serial;
        clearTimeout(refreshTimer);
        if (controller) { controller.abort(); controller = null; }
        clearViewer(true);
      } else { scheduleRefresh(); }
    });
    // Do not refetch on every window focus; account/visibility changes still revalidate private data.
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) { scheduleRefresh(); }
    });
    window.addEventListener('pagehide', function () {
      ++serial;
      clearTimeout(refreshTimer);
      if (controller) { controller.abort(); controller = null; }
      clearViewer();
    });
    refresh();
  });

  document.querySelectorAll('.pintogya-activity-participants').forEach(function (node) {
    var button = node.querySelector('.pintogya-activity-participants-more');
    var list = node.querySelector('.pintogya-activity-participants-list');
    var status = node.querySelector('.pintogya-activity-participants-status');
    if (!button || !list || !status) { return; }
    var cursor = node.dataset.cursor || '0';
    var serial = 0;
    var controller = null;
    node.addEventListener('pintogya:reset', function () {
      ++serial;
      if (controller) { controller.abort(); controller = null; }
      cursor = '0';
      list.replaceChildren();
      button.disabled = false;
      button.hidden = false;
      button.textContent = node.dataset.labelShow;
      status.hidden = true;
      status.textContent = '';
    });
    button.addEventListener('click', function () {
      if (node.hidden || button.disabled) { return; }
      var current = ++serial;
      var url = new URL(node.dataset.endpoint, document.baseURI);
      if (url.origin !== location.origin) {
        status.textContent = node.dataset.labelError;
        status.hidden = false;
        return;
      }
      controller = new AbortController();
      var requestController = controller;
      var timeout = setTimeout(function () { requestController.abort(); }, 12000);
      button.disabled = true;
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid, after: cursor });
      fetch(url.href, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), signal: requestController.signal
      }).then(function (response) {
        if (!response.ok) { throw new Error('participants_request_failed'); }
        return response.json();
      }).then(function (result) {
        if (current !== serial) { return; }
        var data = result && result.ok && result.data;
        if (!data || !Array.isArray(data.items) || data.items.length > 20 ||
            typeof data.nextCursor !== 'string' ||
            !/^(?:0|[1-9][0-9]{0,17})$/.test(data.nextCursor)) {
          throw new Error('participants_response_invalid');
        }
        if (data.nextCursor !== '0' && (data.items.length === 0 ||
            data.nextCursor.length < cursor.length ||
            (data.nextCursor.length === cursor.length && data.nextCursor <= cursor))) {
          throw new Error('participants_cursor_invalid');
        }
        data.items.forEach(function (item) {
          if (!Number.isSafeInteger(item.uid) || item.uid < 1 ||
              typeof item.avatarUrl !== 'string' || !item.avatarUrl || item.avatarUrl.length > 2048) {
            throw new Error('participant_invalid');
          }
        });
        data.items.forEach(function (item) {
          var entry = document.createElement('li');
          var image = document.createElement('img');
          var avatarUrl = new URL(item.avatarUrl, document.baseURI);
          if (!/^https?:$/.test(avatarUrl.protocol)) { throw new Error('participant_avatar_invalid'); }
          image.src = avatarUrl.href;
          image.alt = '';
          image.width = 32;
          image.height = 32;
          image.loading = 'lazy';
          image.referrerPolicy = 'no-referrer';
          entry.appendChild(image);
          list.appendChild(entry);
        });
        cursor = data.nextCursor;
        button.textContent = node.dataset.labelMore;
        button.hidden = cursor === '0';
        status.hidden = list.children.length > 0;
        status.textContent = list.children.length > 0 ? '' : node.dataset.labelEmpty;
      }).catch(function () {
        if (current !== serial) { return; }
        status.textContent = node.dataset.labelError;
        status.hidden = false;
      }).finally(function () {
        clearTimeout(timeout);
        if (current === serial) { controller = null; button.disabled = false; }
      });
    });
  });

  document.querySelectorAll('.pintogya-activity-join').forEach(function (node) {
    if (node.dataset.ready === '1') { return; }
    node.dataset.ready = '1';
    var button = node.querySelector('.pintogya-activity-join-button');
    var status = node.querySelector('.pintogya-activity-join-status');
    var qr = node.querySelector('.pintogya-activity-join-qr');
    var timer = 0;
    var deadline = 0;
    var approved = false;
    var serial = 0;

    var originalText = node.dataset.labelJoin || button.textContent;
    // pintogya_participation_initial_check: do not offer a duplicate QR before the viewer result.
    if (node.dataset.forumJoined === '1') { joined(); }
    else if (node.parentElement.querySelector('.pintogya-activity-viewer')) {
      node.dataset.state = 'checking';
      button.disabled = true;
    }
    function joined() {
      ++serial;
      clearTimeout(timer);
      node.dataset.state = 'joined';
      qr.hidden = true;
      button.hidden = false;
      button.disabled = true;
      button.textContent = node.dataset.labelDone;
      show('labelDone');
    }
    node.addEventListener('pintogya:participation', function () {
      if (node.dataset.forumJoined === '1') { joined(); }
      else if (node.dataset.state === 'joined' || node.dataset.state === 'checking' || node.dataset.state === 'unavailable') {
        node.dataset.state = 'ready';
        button.disabled = false;
        button.textContent = originalText;
        status.hidden = true;
      }
    });
    node.addEventListener('pintogya:participation-unavailable', function () {
      // pintogya_participation_unknown_disabled: failed verification is not a not-joined result.
      if (node.dataset.forumJoined === '1') { joined(); return; }
      if (node.dataset.state !== 'checking') { return; }
      node.dataset.state = 'unavailable';
      button.disabled = true;
    });
    node.addEventListener('pintogya:participation-pending', function () {
      ++serial;
      clearTimeout(timer);
      node.dataset.state = 'checking';
      qr.hidden = true;
      button.disabled = true;
      button.textContent = node.dataset.labelConnecting || originalText;
      status.hidden = false;
      status.textContent = node.dataset.labelSyncPending || node.dataset.labelWaiting;
    });

    function show(label) {
      status.hidden = false;
      status.textContent = node.dataset[label];
    }
    function fail(current, error) {
      if (current !== serial) { return; }
      clearTimeout(timer);
      var closed = error && error.message === 'activity_join_closed';
      button.disabled = !!closed;
      button.hidden = !!closed;
      qr.hidden = true;
      var code = error && error.message;
      show(closed ? 'labelClosed' : code === 'activity_join_identity_mismatch' ?
        'labelIdentityMismatch' : code === 'activity_join_approval_expired' ||
        code === 'activity_join_approval_changed' ? 'labelExpired' : 'labelError');
    }
    function request(operation) {
      var url = new URL(node.dataset.endpoint, document.baseURI);
      if (url.origin !== location.origin) { return Promise.reject(new Error('join_origin_invalid')); }
      url.searchParams.set('op', operation);
      var body = new URLSearchParams({ formhash: node.dataset.formhash, tid: node.dataset.tid });
      var controller = new AbortController();
      var timeout = setTimeout(function () { controller.abort(); }, 12000);
      return fetch(url.href, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(), signal: controller.signal
      }).then(function (response) {
        return response.json().then(function (result) {
          if (!response.ok || !result || result.ok !== true || !result.data) {
            var allowed = ['activity_join_closed', 'activity_join_identity_mismatch',
              'activity_join_approval_expired', 'activity_join_approval_changed',
              'activity_join_verification_unavailable'];
            throw new Error(result && allowed.includes(result.error) ? result.error : 'join_request_failed');
          }
          return result.data;
        });
      }).finally(function () { clearTimeout(timeout); });
    }
    function poll(current) {
      if (current !== serial) { return; }
      if (Date.now() > deadline || (!approved && Date.now() > deadline - 300000)) {
        fail(current);
        return;
      }
      if (document.hidden) {
        timer = setTimeout(function () { poll(current); }, 2000);
        return;
      }
      request('join_poll').then(function (data) {
        if (current !== serial) { return; }
        if (data.state === 'joined') {
          joined();
          var viewer = node.parentElement.querySelector('.pintogya-activity-viewer');
          if (viewer) { viewer.dispatchEvent(new Event('pintogya:refresh')); }
          return;
        }
        if (data.state === 'not_joined') {
          approved = true;
          qr.hidden = true;
          show('labelNotJoined');
        } else if (data.state === 'pending') {
          show(approved ? 'labelWaiting' : 'labelScan');
        } else {
          throw new Error('join_state_invalid');
        }
        timer = setTimeout(function () { poll(current); }, 5000);
      }).catch(function (error) { fail(current, error); });
    }
    button.addEventListener('click', function () {
      if (button.disabled) { return; }
      var current = ++serial;
      clearTimeout(timer);
      button.disabled = true;
      qr.hidden = true;
      approved = false;
      show('labelConnecting');
      request('join_start').then(function (data) {
        if (current !== serial) { return; }
        if (data.state === 'joined') {
          joined();
          var viewer = node.parentElement.querySelector('.pintogya-activity-viewer');
          if (viewer) { viewer.dispatchEvent(new Event('pintogya:refresh')); }
          return;
        }
        if (data.state === 'approved') {
          approved = true;
          deadline = Date.now() + 300000;
          poll(current);
          return;
        }
        if (data.state !== 'pending' || typeof data.qrImage !== 'string' ||
            !/^data:image\/(?:png|svg\+xml);base64,[A-Za-z0-9+/=]+$/.test(data.qrImage) ||
            typeof data.openUrl !== 'string' ||
            !/^https:\/\/togya\.com\/auth\/browser\?challenge=[0-9a-f-]{36}&nonce=[A-Za-z0-9_-]{43}$/.test(data.openUrl) ||
            !Number.isInteger(data.expiresAt) || data.expiresAt * 1000 <= Date.now()) {
          throw new Error('join_start_invalid');
        }
        deadline = data.expiresAt * 1000 + 300000;
        qr.src = data.qrImage;
        qr.hidden = false;
        show('labelScan');
        timer = setTimeout(function () { poll(current); }, 5000);
      }).catch(function (error) { fail(current, error); });
    });
  });
})();
