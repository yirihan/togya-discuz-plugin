(function () {
    'use strict';
    document.querySelectorAll('.pintogya-publication-resume').forEach(function (root) {
        var button = root.querySelector('button');
        var status = root.querySelector('p');
        var active = null;
        var timeout = null;
        if (!button || !status || !/^[1-9][0-9]{0,9}$/.test(root.dataset.tid) ||
            Number(root.dataset.tid) > 4294967295 || !root.dataset.formhash ||
            root.dataset.endpoint !== 'plugin.php?id=pintogya:activity&op=publication_resume') { return; }
        function cancel() {
            if (active) { active.abort(); active = null; }
            if (timeout !== null) { clearTimeout(timeout); timeout = null; }
            button.disabled = false;
            status.textContent = root.dataset.labelPending;
        }
        button.addEventListener('click', async function () {
            if (active || document.hidden) { return; }
            var controller = new AbortController();
            active = controller;
            button.disabled = true;
            status.textContent = root.dataset.labelWorking;
            timeout = setTimeout(function () { controller.abort(); }, 12000);
            try {
                var response = await fetch(root.dataset.endpoint, {
                    method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store',
                    signal: controller.signal,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ tid: root.dataset.tid, formhash: root.dataset.formhash }).toString()
                });
                var payload = await response.json();
                if (active !== controller || controller.signal.aborted || document.hidden) { return; }
                if (response.ok && payload && payload.ok === true && payload.data &&
                    Object.keys(payload.data).sort().join(',') === 'state,tid' &&
                    payload.data.state === 'active' && payload.data.tid === Number(root.dataset.tid)) {
                    window.location.reload();
                } else {
                    status.textContent = payload && /^instant_publication_(?:commit|rollback)_outcome_unknown$/.test(payload.error)
                        ? root.dataset.labelUnknown : root.dataset.labelFailed;
                }
            } catch (error) {
                if (active === controller && !document.hidden) { status.textContent = root.dataset.labelUnknown; }
            } finally {
                if (active === controller) {
                    active = null;
                    if (timeout !== null) { clearTimeout(timeout); timeout = null; }
                    button.disabled = false;
                }
            }
        });
        document.addEventListener('visibilitychange', function () { if (document.hidden) { cancel(); } });
        window.addEventListener('pagehide', cancel);
    });
}());
