(function () {
    'use strict';

    var endpoint = 'plugin.php?id=pintogya:activity&op=public_card';
    var queue = [];
    var active = 0;
    var observer = null;

    function coverUrl(value) {
        if (typeof value !== 'string' || value.length > 500) { return ''; }
        try {
            var url = new URL(value, window.location.href);
            if (url.protocol !== 'https:' || url.username || url.password || url.port || url.search || url.hash) { return ''; }
            if (url.origin === window.location.origin && /^\/[A-Za-z0-9_/-]+\/forum\/pintogya_cover\/[0-9a-f]{64}\.webp$/.test(url.pathname)) {
                return url.href;
            }
            if (url.origin === window.location.origin && /^\/source\/plugin\/pintogya\/static\/examples\/example-(?:craft|cooking|fitness)\.webp$/.test(url.pathname)) { return url.href; }
        } catch (error) { return ''; }
        return '';
    }

    function addCover(marker, source) {
        var card = marker.closest('li.pin-ibook-card');
        var target = card ? card.querySelector('a.pin-ibook-cover') : null;
        var touch = !target ? marker.closest('li.list, li.list_top') : null;
        if (!target && !touch) { return; }
        var image = document.createElement('img');
        image.className = 'pintogya-list-cover-image';
        image.width = 640;
        image.height = 360;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        image.referrerPolicy = 'no-referrer';
        image.hidden = true;
        var holder = null;
        if (touch) {
            holder = document.createElement('a');
            holder.className = 'pintogya-list-touch-cover';
            holder.hidden = true;
            var threadLink = touch.querySelector('a[href*="mod=viewthread"]');
            if (!threadLink) { return; }
            holder.href = threadLink.href;
            holder.appendChild(image);
            var footer = touch.querySelector('.threadlist_foot');
            touch.insertBefore(holder, footer || null);
        } else {
            target.appendChild(image);
        }
        image.onload = function () {
            image.hidden = false;
            if (holder) { holder.hidden = false; }
            if (target) {
                var placeholder = target.querySelector('.nophoto');
                if (placeholder) { placeholder.hidden = true; }
                target.classList.remove('is-empty');
                target.classList.add('is-loaded');
            }
        };
        image.onerror = function () {
            if (holder) { holder.remove(); }
            else { image.remove(); }
        };
        image.src = source;
    }

    function render(marker, data) {
        if (!data || !Number.isInteger(data.rewardAmount) || data.rewardAmount < 0 || data.rewardAmount > 2147483647 ||
            typeof data.displayTitle !== 'string' || !data.displayTitle.trim() || data.displayTitle.length > 240 ||
            typeof data.rewardCredit !== 'string' || !data.rewardCredit || data.rewardCredit.length > 120 ||
            !['open', 'closing', 'closed'].includes(data.rewardState) ||
            typeof data.payoutEnabled !== 'boolean') { return; }
        var instant = data.rewardModel === 'instant';
        if (instant) {
            if (data.qualifiedParticipantCount !== null || data.confirmedCompletionCount !== null ||
                data.rewardState !== 'open' || !Number.isInteger(data.baseReward) || data.baseReward < 0 ||
                typeof data.finishStoryAvailable !== 'boolean' || !Array.isArray(data.stageRewards) ||
                data.stageRewards.length > 100) { return; }
            var total = data.baseReward;
            var ids = new Set();
            for (var index = 0; index < data.stageRewards.length; index++) {
                var stage = data.stageRewards[index];
                if (!stage || typeof stage.id !== 'string' || ids.has(stage.id) ||
                    !Number.isInteger(stage.position) || stage.position !== index ||
                    !Number.isInteger(stage.amount) || stage.amount < 0) { return; }
                ids.add(stage.id);
                total += stage.amount;
            }
            if (total !== data.rewardAmount) { return; }
        } else if (!Number.isInteger(data.qualifiedParticipantCount) || data.qualifiedParticipantCount < 0 ||
            data.qualifiedParticipantCount > 2147483647 || !Number.isInteger(data.confirmedCompletionCount) ||
            data.confirmedCompletionCount < 0 || data.confirmedCompletionCount > data.qualifiedParticipantCount) { return; }
        var state = data.payoutEnabled ? marker.getAttribute('data-label-' + data.rewardState) :
            marker.getAttribute(instant ? 'data-label-instant-paused' : 'data-label-paused');
        var fullStatus = instant ? 'Togya | ' + marker.getAttribute('data-label-instant-reward') + ': '
            + data.rewardAmount + ' ' + data.rewardCredit + ' | ' + state : 'Togya | ' + marker.getAttribute('data-label-qualified') + ': ' + data.qualifiedParticipantCount
            + ' | ' + marker.getAttribute('data-label-completed') + ': ' + data.confirmedCompletionCount
            + ' | ' + marker.getAttribute('data-label-reward') + ': ' + data.rewardAmount + ' ' + data.rewardCredit
            + ' | ' + state;
        var card = marker.closest('li.pin-ibook-card');
        marker.textContent = '';
        if (card) {
            var rewardRow = card.querySelector('.pin-ibook-meta-row');
            if (rewardRow) {
                // TOGYA_PLUGIN: pintogya_list_participant_icon; same local count as the thread.
                if (Number.isInteger(data.forumParticipantCount) && data.forumParticipantCount >= 0 &&
                    data.forumParticipantCount <= 2147483647 && !rewardRow.querySelector('.pintogya-list-participants')) {
                    var participants = document.createElement('span');
                    participants.className = 'pin-ibook-pill pintogya-list-participants';
                    participants.title = marker.getAttribute('data-label-forum-participants') + ': ' + data.forumParticipantCount;
                    participants.setAttribute('aria-label', participants.title);
                    var participantIcon = document.createElement('img');
                    participantIcon.src = 'source/plugin/pintogya/static/images/participants.svg?1.0.0-alpha.1-x5';
                    participantIcon.width = 14;
                    participantIcon.height = 14;
                    participantIcon.alt = '';
                    participantIcon.setAttribute('aria-hidden', 'true');
                    var participantCount = document.createElement('strong');
                    participantCount.textContent = data.forumParticipantCount;
                    participants.append(participantIcon, participantCount);
                    rewardRow.appendChild(participants);
                }
                marker.className = 'pin-ibook-pill pin-ibook-reward-pill pintogya-list-marker';
                var icon = document.createElement('i');
                icon.className = 'fico-reward fc-l pin-ibook-reward-icon';
                icon.setAttribute('aria-hidden', 'true');
                var amount = document.createElement('strong');
                amount.textContent = data.rewardAmount;
                var credit = document.createElement('em');
                credit.textContent = data.rewardCredit;
                marker.append(icon, amount, credit);
                rewardRow.appendChild(marker);
            }
        } else {
            marker.className = 'pintogya-list-marker pintogya-list-reward-inline xi1';
            var label = document.createElement('span');
            label.textContent = '[' + marker.getAttribute('data-label-reward-short') + ' ';
            var value = document.createElement('strong');
            value.textContent = data.rewardAmount;
            marker.append(label, value, document.createTextNode(' ' + data.rewardCredit + ']'));
        }
        marker.title = fullStatus;
        marker.hidden = false;
        var touch = card ? null : marker.closest('li.list, li.list_top');
        var title = card ? card.querySelector('h3.pin-ibook-title > a') :
            (touch ? touch.querySelector('.threadlist_tit em, a > em') :
                marker.parentElement.querySelector('a.s.xst'));
        if (title) {
            title.textContent = data.displayTitle;
            title.title = data.displayTitle;
        }
        if (card) {
            var cover = card.querySelector('a.pin-ibook-cover');
            if (cover) { cover.title = data.displayTitle; }
        }
        var source = coverUrl(data.thumbnailUrl);
        if (source) { addCover(marker, source); }
        // TOGYA_PLUGIN: pintogya_native_card_reflow; reuse DX after async title growth.
        if (card && typeof window.waterfall === 'function') { window.wf = window.waterfall(); }
    }

    function load(marker) {
        var body = 'tid=' + encodeURIComponent(marker.getAttribute('data-tid'))
            + '&formhash=' + encodeURIComponent(marker.getAttribute('data-formhash'));
        fetch(endpoint, {
            method: 'POST', credentials: 'same-origin', redirect: 'error',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (result) {
            if (result && result.ok === true) { render(marker, result.data); }
        }).catch(function () {}).then(function () {
            active--;
            pump();
        });
    }

    function pump() {
        while (active < 2 && queue.length) {
            active++;
            load(queue.shift());
        }
    }

    function enqueue(marker) {
        queue.push(marker);
        pump();
    }

    function scan() {
        var markers = document.querySelectorAll('.pintogya-list-marker:not([data-pintogya-queued])');
        for (var index = 0; index < markers.length; index++) {
            var marker = markers[index];
            marker.setAttribute('data-pintogya-queued', '1');
            if (!observer) { enqueue(marker); continue; }
            var target = marker.closest('li.pin-ibook-card, li.list, li.list_top, tbody') || marker.parentElement;
            target.pintogyaMarker = marker;
            observer.observe(target);
        }
    }

    function start() {
        if (typeof fetch !== 'function') { return; }
        if (typeof IntersectionObserver === 'function') {
            observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) { return; }
                    observer.unobserve(entry.target);
                    enqueue(entry.target.pintogyaMarker);
                });
            }, { rootMargin: '300px' });
        }
        scan();
        if (typeof MutationObserver === 'function') {
            new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); }
    else { start(); }
})();
