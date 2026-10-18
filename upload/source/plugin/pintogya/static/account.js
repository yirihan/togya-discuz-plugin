(function() {
  var root = document.querySelector('.pintogya-account');
  if (!root) return;
  var disconnect = root.querySelector('.pintogya-account-disconnect');
  var retry = root.querySelector('.pintogya-account-retry');
  var status = root.querySelector('.pintogya-account-status');
  var connect = root.querySelector('.pintogya-account-connect');
  var qrBox = root.querySelector('.pintogya-account-qr');
  var qr = qrBox ? qrBox.querySelector('img') : null;
  var busy = false;
  function submit(operation) {
    if (busy) return;
    busy = true;
    if (disconnect) disconnect.disabled = true;
    if (retry) retry.disabled = true;
    var body = new URLSearchParams({ formhash: root.dataset.formhash || '' });
    fetch((root.dataset.endpoint || '') + '&op=' + operation, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function(response) { return response.json(); }).then(function(payload) {
      if (payload && (payload.ok === true || payload.error === 'remote_revoke_pending')) {
        window.location.reload();
        return;
      }
      throw new Error('account_request_failed');
    }).catch(function() {
      if (status) status.textContent = root.dataset.labelError || '';
    }).then(function() {
      busy = false;
      if (disconnect) disconnect.disabled = false;
      if (retry) retry.disabled = false;
    });
  }
  if (disconnect) disconnect.addEventListener('click', function() { submit('unlink'); });
  if (retry) retry.addEventListener('click', function() { submit('unlink_retry'); });

  // TOGYA_PLUGIN: pintogya_account_automatic_qr -- no manual start or task claim.
  if (!connect || !qr || root.dataset.state !== 'disconnected') return;
  var timer = null;
  var challengeId = '';
  var expiresAt = 0;
  var failures = 0;
  var stopped = false;
  function schedule(delay) {
    clearTimeout(timer);
    if (!stopped) timer = setTimeout(poll, delay);
  }
  function fail(code) {
    stopped = true;
    clearTimeout(timer);
    qrBox.hidden = true;
    connect.hidden = false;
    connect.disabled = false;
    var label = code === 'activity_join_identity_mismatch' ? root.dataset.labelMismatch :
      code === 'activity_join_approval_expired' ? root.dataset.labelExpired : root.dataset.labelConnectError;
    status.textContent = label || '';
  }
  function request(operation, id) {
    var body = new URLSearchParams({ formhash: root.dataset.formhash || '' });
    if (id) body.set('challenge_id', id);
    return fetch((root.dataset.endpoint || '') + '&op=' + operation, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function(response) { return response.json(); }).then(function(payload) {
      if (!payload || payload.ok !== true || !payload.data) {
        var error = new Error('account_request_failed');
        error.code = payload && payload.error;
        throw error;
      }
      return payload.data;
    });
  }
  function poll() {
    if (stopped || busy) return;
    if (Date.now() >= expiresAt) { fail('activity_join_approval_expired'); return; }
    if (document.hidden) { schedule(3000); return; }
    busy = true;
    request('connect_poll', challengeId).then(function(data) {
      if (data.state === 'connected') {
        stopped = true;
        window.location.reload();
      } else if (data.state === 'pending') {
        failures = 0;
        status.textContent = root.dataset.labelWaiting || '';
        schedule(3000);
      } else { fail('account_request_failed'); }
    }).catch(function(error) {
      if (error.code === 'activity_join_identity_mismatch' ||
          error.code === 'activity_join_approval_expired') { fail(error.code); return; }
      failures++;
      if (failures >= 3) { fail('account_request_failed'); }
      else { schedule(3000 * failures); }
    }).then(function() { busy = false; });
  }
  function start() {
    if (busy) return;
    busy = true;
    stopped = false;
    failures = 0;
    clearTimeout(timer);
    connect.hidden = true;
    connect.disabled = true;
    qrBox.hidden = true;
    status.textContent = root.dataset.labelPreparing || '';
    request('connect_start').then(function(data) {
      if (data.state === 'connected') { stopped = true; window.location.reload(); return; }
      if (data.state !== 'pending' || typeof data.challengeId !== 'string' ||
          !/^[0-9a-f-]{36}$/.test(data.challengeId) || typeof data.qrImage !== 'string' ||
          !/^data:image\/(?:png|svg\+xml);base64,[A-Za-z0-9+/=]+$/.test(data.qrImage) ||
          !Number.isInteger(data.expiresAt) || data.expiresAt * 1000 <= Date.now()) {
        fail('account_request_failed'); return;
      }
      challengeId = data.challengeId;
      expiresAt = data.expiresAt * 1000;
      qr.src = data.qrImage;
      qrBox.hidden = false;
      status.textContent = root.dataset.labelWaiting || '';
      schedule(3000);
    }).catch(function(error) { fail(error.code); }).then(function() { busy = false; });
  }
  connect.addEventListener('click', start);
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden && !stopped && challengeId) schedule(0);
  });
  window.addEventListener('pagehide', function() { stopped = true; clearTimeout(timer); });
  start();
}());
