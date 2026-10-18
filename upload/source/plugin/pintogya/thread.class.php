<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

require_once dirname(__FILE__).'/lib/activity_native_post.php';
require_once __DIR__.'/lib/capabilities.php';

class threadplugin_pintogya {
    public $name = 'Togya';
    public $iconfile = 'static/image/task/togya.png';
    public $buttontext = 'Publish';

    public function newthread($fid) {
        require_once dirname(__FILE__).'/lib/pintogya.php';
        require_once dirname(__FILE__).'/lib/native_task.php';
        $lang = pintogya_lang_pack();
        $escape = static function($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
        if(!PintogyaCapabilities::available('native_composer')) {
            return '<p class="pintogya-activity-unavailable" role="status">'.$escape($lang['activity_view_unavailable']).'</p>';
        }
        $labels = array('connecting', 'pending', 'ready', 'loading', 'empty', 'selected', 'expired',
            'error', 'mapped', 'select', 'publishing');
        $attributes = '';
        foreach($labels as $label) {
            $attributes .= ' data-label-'.$label.'="'.$escape($lang['activity_'.$label]).'"';
        }
        return '<link rel="stylesheet" href="source/plugin/pintogya/static/activity.css?cover-natural-20261006&amp;participants=1.0.0-alpha.1-x5&amp;composer=1.0.0-alpha.1-x5" />'
            .'<div class="pintogya-activity-composer" data-endpoint="plugin.php?id=pintogya:activity"'.$attributes.'>'
            .'<h3>'.$escape($lang['activity_heading']).'</h3>'
            .'<ol><li>'.$escape($lang['activity_step_create']).'</li><li>'.$escape($lang['activity_step_scan']).'</li>'
            .'<li>'.$escape($lang['activity_step_publish']).'</li></ol>'
            .'<p class="pintogya-activity-status" role="status" aria-live="polite">'.$escape($lang['activity_connecting']).'</p>'
            .'<div class="pintogya-activity-qr" hidden><img alt="'.$escape($lang['activity_qr_alt']).'" />'
            .'<p>'.$escape($lang['activity_scan_help']).'</p></div>'
            .'<div class="pintogya-activity-list" hidden><h4>'.$escape($lang['activity_list_heading']).'</h4>'
            .'<div class="pintogya-activity-items"></div></div>'
            // TOGYA_PLUGIN pintogya_composer_store_downloads: reuse the existing local store badges.
            .'<div class="pintogya-activity-downloads">'.pintogya_native_render_platform_links($lang).'</div>'
            .'<div class="pintogya-activity-actions"><button type="button" class="pintogya-activity-restart">'
            .$escape($lang['activity_restart']).'</button>'
            .'<button type="button" class="pintogya-activity-refresh" hidden>'.$escape($lang['activity_refresh']).'</button>'
            .'<button type="button" class="pintogya-activity-publish" disabled>'.$escape($lang['activity_publish']).'</button></div>'
            .'<input type="hidden" name="pintogya_action_token" value="" />'
            .'<noscript>'.$escape($lang['activity_javascript_required']).'</noscript>'
            .'</div><script defer src="source/plugin/pintogya/static/activity.js?g3-20260928"></script>';
    }

    public function newthread_submit($fid) {
        global $_G;
        // Stock DX ignores the return value; close until its before-model adapter is verified.
        PintogyaCapabilities::assertAvailable('native_composer');
        require_once dirname(__FILE__).'/lib/pintogya.php';
        pintogya_assert_post();
        $token = PintogyaActivityNativePost::actionToken($_POST);
        return $this->activityThread()->prepare((int)$_G['uid'], (int)$fid, $token,
            pintogya_session_digest(), (int)TIMESTAMP);
    }

    public function newthread_submit_end($fid, $tid) {
        global $_G;
        if((int)$tid < 1) { return null; }
        PintogyaCapabilities::assertAvailable('native_composer');
        require_once dirname(__FILE__).'/lib/pintogya.php';
        $token = PintogyaActivityNativePost::actionToken($_POST);
        $draft = $this->activityThread()->attachDraft((int)$_G['uid'], (int)$fid, $token,
            pintogya_session_digest(), (int)$tid, (int)TIMESTAMP);
        if($draft['state_code'] === 'active') { return 1; }
        if(!function_exists('threadpubsave')) { require_once libfile('function/forum'); }
        $publication = threadpubsave((int)$tid);
        if($publication !== 1 && $publication !== -1) { throw new RuntimeException('activity_native_publish_failed'); }
        $this->activityThread()->markPublished((int)$_G['uid'], (int)$fid, $token,
            pintogya_session_digest(), (int)$tid, $publication, (int)TIMESTAMP);
        return $publication;
    }

    public function viewthread($tid) {
        global $_G, $navtitle, $metadescription, $metakeywords, $thread;
        if(!defined('PINTOGYA_DISPLAY_REQUEST')) { define('PINTOGYA_DISPLAY_REQUEST',true); }
        require_once dirname(__FILE__).'/lib/pintogya.php';
        $lang = pintogya_lang_pack();
        $escape = static function($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
        try {
            // pintogya_public_request_reader: one anonymous authority snapshot per rendered post.
            $services = $this->activityServices();
            $services = $this->activityDisplayServices($services);
            $activityThread = $services['activity_thread'];
            $projection = $activityThread->publicProjection((int)$tid, pintogya_api_locale());
            $activity = $projection['activity'];
            $id = PintogyaCrypto::assertUuid($activity['id']);
            if(!is_string($activity['summary'])) { throw new RuntimeException('activity_projection_invalid'); }
        } catch(Throwable $error) {
            pintogya_display_headers();
            $metadescription = $metakeywords = 'Togya activity';
            try {
                if($error->getMessage()!=='activity_display_pending' && !empty($_G['uid']) && isset($activityThread) &&
                    (string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') === '1' &&
                    PintogyaActivitySettings::isPublisherGroupAllowed((int)($_G['groupid'] ?? 0), $_G['setting']) &&
                    $activityThread->canResumePublication((int)$_G['uid'], (int)$tid, pintogya_session_digest(), (int)TIMESTAMP)) {
                    $english = pintogya_task_lang_pack();
                    $text = static function($key) use ($lang, $english, $escape) { return $escape($lang[$key] ?? $english[$key]); };
                    return '<section class="pintogya-activity-view pintogya-publication-resume"'
                        .' data-endpoint="plugin.php?id=pintogya:activity&amp;op=publication_resume"'
                        .' data-tid="'.(int)$tid.'" data-formhash="'.$escape(formhash()).'"'
                        .' data-label-pending="'.$text('activity_publication_pending').'"'
                        .' data-label-working="'.$text('activity_publication_working').'"'
                        .' data-label-failed="'.$text('activity_publication_failed').'"'
                        .' data-label-unknown="'.$text('activity_publication_unknown').'">'
                        .'<p role="status" aria-live="polite">'.$text('activity_publication_pending').'</p>'
                        .'<button type="button" class="pn pnc"><span>'.$text('activity_publication_resume').'</span></button>'
                        .'</section><script defer src="source/plugin/pintogya/static/activity-publication-resume.js?instant-20261003"></script>';
                }
            } catch(Throwable $resumeError) { /* Never disclose another author's pending mapping. */ }
            return '<div class="pintogya-activity-view" role="status">'.$escape($lang[$error->getMessage()==='activity_display_pending' ?
                'activity_display_pending' : 'activity_view_unavailable']).'</div>';
        }
        $safeTitle = $escape($projection['displayTitle']);
        $rewardBox = '';
        $stageRewardAmounts = array();
        $stageRewardNotes = '';
        // Classify before reading amounts: failed instant rules must never reactivate legacy review.
        $instantRewards = ($projection['rewardModel'] ?? null) === 'instant';
        try {
            $card = $activityThread->publicCard((int)$tid, pintogya_api_locale(),
                $_G['setting']['extcredits'] ?? array(),
                (string)($_G['setting']['pintogya_activity_settlement_enabled'] ?? '0') === '1', $projection);
            if((($card['rewardModel'] ?? null) === 'instant') !== $instantRewards) {
                throw new RuntimeException('activity_reward_model_changed');
            }
            $rewardTitle = $lang['activity_card_per_completer'];
            if($instantRewards) {
                $english = pintogya_task_lang_pack();
                $text = static function($key) use ($lang, $english, $escape) { return $escape($lang[$key] ?? $english[$key]); };
                $rewardTitle = $lang['activity_instant_total'] ?? $english['activity_instant_total'];
                foreach($card['stageRewards'] as $stage) {
                    if($stage['kind'] !== 'tutorial') { continue; }
                    $stageRewardAmounts[$stage['id']] = (int)$stage['amount'];
                }
                // TOGYA_PLUGIN: pintogya_activity_stage_single_display -- no second stage reward list.
                $stageRewardNotes = '<div class="pintogya-stage-reward-notes">'
                    .'<p>'.$text('activity_instant_stage_condition').'</p>'
                    .'<p>'.$text('activity_instant_base').': <strong>'.(int)$card['baseReward'].'</strong> '
                    .$escape($card['rewardCredit']).'. '.$text($card['finishStoryAvailable'] ?
                        'activity_instant_base_condition' : 'activity_instant_finish_unavailable').'</p>'
                    .'<p>'.$text('activity_instant_independent').'</p>'
                    .'<p>'.$text('activity_instant_automatic').'</p>'
                    .'<p>'.$text('activity_instant_viewer_scope').'</p></div>';
            }
            $paused = $instantRewards ? ($lang['activity_instant_paused'] ?? $english['activity_instant_paused'])
                : $lang['activity_card_status_paused'];
            $pausedShort = $instantRewards ? ($lang['activity_instant_paused_short'] ?? $english['activity_instant_paused_short'])
                : $lang['activity_card_status_paused_short'];
            $rewardStatus = $card['payoutEnabled'] ? $lang['activity_card_status_'.$card['rewardState']] : $paused;
            $rewardBox = '<div class="rwd cl pintogya-reward'.((int)$card['rewardAmount'] > 999999 ? ' pintogya-reward-wide' : '').'" title="'.$escape($rewardTitle).'">'
                .'<div class="rusld z"><i class="fico-reward fc-l" aria-hidden="true"></i>'
                .$escape(lang('forum/template', 'thread_reward'))
                .'<cite>'.(int)$card['rewardAmount'].'</cite>'.$escape($card['rewardCredit'])
                .'<em title="'.$escape($rewardStatus).'">'.$escape($card['payoutEnabled'] ? $rewardStatus
                    : $pausedShort).'</em></div></div>';
        } catch(Throwable $error) { /* An unverified rule must not display a reward amount. */ }
        $_G['forum_thread']['subject'] = $safeTitle;
        $thread['subjectenc'] = rawurlencode($projection['displayTitle']);
        $thread['short_subject'] = $escape(cutstr($projection['displayTitle'], 52));
        $navtitle = str_replace('Togya activity', $safeTitle, $navtitle);
        $metadescription = $safeTitle;
        $metakeywords = $safeTitle;
        $_G['pintogya_public_thread_title'] = $projection['displayTitle'];
        $summary = nl2br($escape($activity['summary']), false);
        $thumbnail = PintogyaActivityCover::displayUrl((string)($activity['thumbnailUrl'] ?? ''));
        $cover = $thumbnail === '' ? '' : '<div class="pintogya-activity-cover"><img src="'.$escape($thumbnail).'"'
            .' alt="'.$escape($projection['displayTitle']).'" width="640" height="360" loading="lazy" decoding="async" referrerpolicy="no-referrer" /></div>';
        // pintogya_public_stages_visible: public content does not depend on login or QR approval.
        $publicStages = '';
        try {
            $rows = $services['activity_read']->publicStages($id, pintogya_api_locale(), $activity['stageVersion']);
            $items = '';
            foreach($rows as $stage) {
                $stageIdentity = !empty($stage['id']) ? ' data-stage-id="'.$escape($stage['id']).'"' : '';
                $items .= '<li'.$stageIdentity.'><h4>'.($stage['position']+1).'. '.$escape($stage['title']).'</h4>';
                if(isset($stageRewardAmounts[$stage['id'] ?? ''])) {
                    $items .= '<p class="pintogya-stage-reward-amount"><strong>'
                        .$stageRewardAmounts[$stage['id']].'</strong> '.$escape($card['rewardCredit']).'</p>';
                }
                foreach(array('objective'=>'activity_stage_objective', 'completionCriteria'=>'activity_stage_criteria',
                    'checkinPrompt'=>'activity_stage_prompt') as $field => $label) {
                    if($stage[$field] !== '') {
                        $items .= '<p>'.$escape($lang[$label]).': '.$escape($stage[$field]).'</p>';
                    }
                }
                if($stage['position'] === 0 && $stage['readable'] && !empty($stage['id'])) {
                    $items .= $this->publicContent((int)$tid,$stage['id'],false,$lang)
                        .$this->publicContent((int)$tid,$stage['id'],true,$lang);
                }
                $items .= '</li>';
            }
            $publicStages = '<section class="pintogya-activity-public-stages"><h3>'
                .$escape($lang['activity_stages_title']).'</h3><ol>'.$items.'</ol>'.$stageRewardNotes.'</section>';
        } catch(Throwable $error) { /* Never replace a public read with a member's private data. */ }
        $viewer = '';
        if(!empty($_G['uid'])) {
            if($instantRewards) {
                $english = pintogya_task_lang_pack();
                $labels = '';
                foreach(array('base','checking','paid','pending','waiting_sync','waiting_policy',
                    'waiting_access','blocked','no_reward','unavailable','synced','not_participating','checked','log') as $label) {
                    $key = 'activity_instant_status_'.$label;
                    $labels .= ' data-label-'.str_replace('_','-',$label).'="'.$escape($lang[$key] ?? $english[$key]).'"';
                }
                // TOGYA_PLUGIN: pintogya_activity_stage_status_ui -- personal reward states live beside stages.
                $viewer .= '<div class="pintogya-instant-status" hidden'
                    .' data-endpoint="plugin.php?id=pintogya:activity&amp;op=reward_status"'
                    .' data-tid="'.(int)$tid.'" data-formhash="'.$escape(formhash()).'"'.$labels.'>'
                    .'</div><script defer src="source/plugin/pintogya/static/activity-instant-status.js?stage-status-20261005"></script>';
            }
            $photoRemovalAttributes = '';
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') === '1') {
                try {
                    (new PintogyaActivityReviewAccess($services['repository']))->assertCurrentModerator((int)$_G['uid'], (int)$tid);
                    if($services['activity_photo_removal_store']->isInstalled()) {
                        $photoLabels = pintogya_task_lang_pack();
                        $photoRemovalAttributes = ' data-photo-removal-endpoint="plugin.php?id=pintogya:activity&amp;op=photo_removal_request"'
                            .' data-label-photo-removal="'.$escape($lang['activity_photo_removal_request'] ?? $photoLabels['activity_photo_removal_request']).'"'
                            .' data-label-photo-removal-pending="'.$escape($lang['activity_photo_removal_pending'] ?? $photoLabels['activity_photo_removal_pending']).'"'
                            .' data-label-photo-removal-pending-short="'.$escape($lang['activity_photo_removal_pending_short'] ?? $photoLabels['activity_photo_removal_pending_short']).'"'
                            .' data-label-photo-removal-error="'.$escape($lang['activity_photo_removal_error'] ?? $photoLabels['activity_photo_removal_error']).'"';
                    }
                } catch(Throwable $error) { /* No installed queue or current permission: no management button. */ }
            }
            $reviewEndpoints = !$instantRewards && (string)($_G['setting'][PintogyaActivitySettings::REVIEW_ENABLED_KEY] ?? '0') === '1' ?
                ' data-review-endpoint="plugin.php?id=pintogya:activity&amp;op=review_submit"'
                .' data-review-status-endpoint="plugin.php?id=pintogya:activity&amp;op=review_status"' : '';
            $viewer .= '<p class="pintogya-activity-viewer" role="status" aria-live="polite"'
                .' data-endpoint="plugin.php?id=pintogya:activity&amp;op=viewer"'
                .' data-tid="'.(int)$tid.'" data-formhash="'.$escape(formhash()).'"'
                .' data-label-joined="'.$escape($lang['activity_view_joined']).'"'
                .' data-label-not-joined="'.$escape($lang['activity_view_not_joined']).'"'
                .' data-label-updating="'.$escape($lang['activity_display_refreshing']).'"'
                .' data-label-pending="'.$escape($lang['activity_display_pending']).'"'
                .' data-label-reconnect="'.$escape($lang['activity_view_reconnect']).'"'
                .' data-label-unavailable="'.$escape($lang['activity_view_status_unavailable']).'">'
                .$escape($lang['activity_view_checking']).'</p>';
            $viewer .= '<section class="pintogya-activity-stages"'
                .$photoRemovalAttributes
                .$this->interactionAttributes($lang,$escape)
                .' data-feed-endpoint="plugin.php?id=pintogya:activity&amp;op=stage_feed"'
                .' data-image-endpoint="plugin.php?id=pintogya:activity&amp;op=stage_image"'
                .' data-label-cache-pending="'.$escape($lang['activity_display_pending']).'"'
                .$reviewEndpoints
                .' data-label-review-checkin="'.$escape($lang['activity_review_checkin']).'"'
                .' data-label-review-completion="'.$escape($lang['activity_review_completion']).'"'
                .' data-label-review-pending="'.$escape($lang['activity_review_pending']).'"'
                .' data-label-review-approved="'.$escape($lang['activity_review_approved']).'"'
                .' data-label-review-approved-auto="'.$escape($lang['activity_review_approved_auto']).'"'
                .' data-label-review-rejected="'.$escape($lang['activity_review_rejected']).'"'
                .' data-label-review-invalid="'.$escape($lang['activity_review_invalid']).'"'
                .' data-label-review-auto="'.$escape($lang['activity_review_auto']).'"'
                .' data-label-review-error="'.$escape($lang['activity_review_error']).'"'
                .' data-label-locked="'.$escape($lang['activity_stage_locked']).'"'
                .' data-label-completed="'.$escape($lang['activity_stage_completed']).'"'
                .' data-label-objective="'.$escape($lang['activity_stage_objective']).'"'
                .' data-label-criteria="'.$escape($lang['activity_stage_criteria']).'"'
                .' data-label-prompt="'.$escape($lang['activity_stage_prompt']).'"'
                .' data-label-feed-show="'.$escape($lang['activity_stage_feed_show']).'"'
                .' data-label-feed-more="'.$escape($lang['activity_stage_feed_more']).'"'
                .' data-label-feed-loading="'.$escape($lang['activity_stage_feed_loading']).'"'
                .' data-label-feed-empty="'.$escape($lang['activity_stage_feed_empty']).'"'
                .' data-label-feed-media="'.$escape($lang['activity_stage_feed_media']).'"'
                .' data-label-photo-unavailable="'.$escape($lang['activity_stage_photo_unavailable']).'"'
                .' data-label-feed-photo="'.$escape($lang['activity_stage_feed_photo']).'" hidden>'
                .'<h3>'.$escape($lang['activity_join_account']).': '
                .$escape($lang['activity_stages_title']).'</h3><ol></ol></section>';
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') === '1') {
                // TOGYA_PLUGIN BEGIN pintogya_activity_join
                // TOGYA_PLUGIN pintogya_participation_local_display: display only, not a permission or reward grant.
                $forumJoined = false;
                try {
                    $binding = $services['repository']->activeBindingForUid((int)$_G['uid'], false);
                    $participant = $services['repository']->participationForUid((int)$projection['activityPk'], (int)$_G['uid'], false);
                    $forumJoined = $binding && $participant && ($participant['state_code'] ?? null) === 'active' &&
                        hash_equals((string)$participant['togya_user_id'], (string)$binding['togya_user_id']) &&
                        (int)$participant['binding_generation'] === (int)$binding['generation'] &&
                        (int)$participant['stage_version'] === (int)$activity['stageVersion'];
                } catch(Throwable $error) { /* Unknown membership must not be rendered as confirmed. */ }
                $viewer .= '<p class="pintogya-activity-account" data-label="'.$escape($lang['activity_join_account']).'" hidden></p>'
                    .'<div class="pintogya-activity-join" data-endpoint="plugin.php?id=pintogya:activity&amp;op=join_start"'
                    .' data-forum-joined="'.($forumJoined ? '1' : '0').'"'
                    .' data-label-join="'.$escape($lang['activity_join_button']).'"'
                    .' data-tid="'.(int)$tid.'" data-formhash="'.$escape(formhash()).'"'
                    .' data-label-connecting="'.$escape($lang['activity_join_connecting']).'"'
                    .' data-label-scan="'.$escape($lang['activity_join_scan']).'"'
                    .' data-label-waiting="'.$escape($lang['activity_join_waiting']).'"'
                    .' data-label-sync-pending="'.$escape($lang['activity_display_participation_pending']).'"'
                    .' data-label-not-joined="'.$escape($lang['activity_join_not_joined']).'"'
                    .' data-label-done="'.$escape($lang['activity_join_done']).'"'
                    .' data-label-closed="'.$escape($lang['activity_join_closed']).'"'
                    .' data-label-identity-mismatch="'.$escape($lang['activity_join_identity_mismatch']).'"'
                    .' data-label-expired="'.$escape($lang['activity_join_expired']).'"'
                    .' data-label-error="'.$escape($lang['activity_join_error']).'">'
                    .'<button type="button" class="pintogya-activity-join-button" disabled>'.$escape($lang[$forumJoined ? 'activity_join_done' : 'activity_join_button']).'</button>'
                    .'<p class="pintogya-activity-join-status" role="status" aria-live="polite" hidden></p>'
                    .'<img class="pintogya-activity-join-qr" alt="'.$escape($lang['activity_join_qr_alt']).'" hidden />'
                    .'</div>';
                // TOGYA_PLUGIN END pintogya_activity_join
            }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') === '1' &&
                !$instantRewards &&
                (string)($_G['setting'][PintogyaActivitySettings::REVIEW_ENABLED_KEY] ?? '0') === '1') {
                try {
                    (new PintogyaActivityReviewAccess($services['repository']))->assertCurrentModerator((int)$_G['uid'], (int)$tid);
                    $viewer .= '<section class="pintogya-activity-review-queue"'
                        .' data-queue-endpoint="plugin.php?id=pintogya:activity&amp;op=review_queue"'
                        .' data-detail-endpoint="plugin.php?id=pintogya:activity&amp;op=review_detail"'
                        .' data-reject-endpoint="plugin.php?id=pintogya:activity&amp;op=review_reject"'
                        .' data-image-endpoint="plugin.php?id=pintogya:activity&amp;op=stage_image"'
                        .' data-tid="'.(int)$tid.'" data-formhash="'.$escape(formhash()).'"'
                        .' data-label-show="'.$escape($lang['activity_review_queue_show']).'"'
                        .' data-label-more="'.$escape($lang['activity_review_queue_more']).'"'
                        .' data-label-loading="'.$escape($lang['activity_review_queue_loading']).'"'
                        .' data-label-empty="'.$escape($lang['activity_review_queue_empty']).'"'
                        .' data-label-error="'.$escape($lang['activity_review_queue_error']).'"'
                        .' data-label-detail="'.$escape($lang['activity_review_queue_detail']).'"'
                        .' data-label-unavailable="'.$escape($lang['activity_review_queue_unavailable']).'"'
                        .' data-label-checkin="'.$escape($lang['activity_review_queue_checkin']).'"'
                        .' data-label-completion="'.$escape($lang['activity_review_queue_completion']).'"'
                        .' data-label-reject="'.$escape($lang['activity_review_queue_reject']).'"'
                        .' data-label-rejected="'.$escape($lang['activity_review_rejected']).'"'
                        .' data-label-due="'.$escape($lang['activity_review_auto']).'"'
                        .' data-label-photo="'.$escape($lang['activity_stage_feed_photo']).'">'
                        .'<h3>'.$escape($lang['activity_review_queue_title']).'</h3>'
                        .'<button type="button" class="pintogya-activity-review-queue-more">'
                        .$escape($lang['activity_review_queue_show']).'</button>'
                        .'<ul class="pintogya-activity-review-queue-list"></ul>'
                        .'<p class="pintogya-activity-review-queue-status" role="status" hidden></p>'
                        .'</section>';
                    $viewer .= '<script defer src="source/plugin/pintogya/static/activity-review.js?g5-review-20260929b"></script>';
                } catch(Throwable $error) {
                    // No moderator queue is rendered when the current forum scope cannot be verified.
                }
            }
            $viewer .= '<p><a href="plugin.php?id=pintogya:account">'.$escape($lang['account_manage_link']).'</a></p>';
        }
        $publicParticipants = '';
        if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') === '1') {
            try {
                $page = $services['display_view']->participants((int)$tid, 0);
                // Count the same active local members as the avatar roster, not a Togya aggregate.
                $participantCount = $services['repository']->participationCount((int)$projection['activityPk']);
                $avatars = '';
                foreach($page['items'] as $participant) {
                    $avatars .= '<li><img src="'.$escape(avatar($participant['uid'], 'small', true)).'"'
                        .' alt="" width="32" height="32" loading="lazy" referrerpolicy="no-referrer" /></li>';
                }
                // TOGYA_PLUGIN: pintogya_public_participant_avatars -- identical public markup for guest/member.
                $publicParticipants = '<section class="pintogya-activity-participants"'
                    .' data-endpoint="plugin.php?id=pintogya:activity&amp;op=participants"'
                    .' data-tid="'.(int)$tid.'" data-formhash="'.$escape(formhash()).'"'
                    .' data-cursor="'.$escape($page['nextCursor']).'"'
                    .' data-label-show="'.$escape($lang['activity_participants_show']).'"'
                    .' data-label-more="'.$escape($lang['activity_participants_more']).'"'
                    .' data-label-empty="'.$escape($lang['activity_participants_empty']).'"'
                    .' data-label-error="'.$escape($lang['activity_participants_error']).'">'
                    .'<h3 class="pintogya-participant-heading" title="'.$escape($lang['activity_participants_title']).'"'
                    .' aria-label="'.$escape($lang['activity_participants_title']).': '.$participantCount.'">'
                    .'<img class="pintogya-participant-icon" src="source/plugin/pintogya/static/images/participants.svg?1.0.0-alpha.1-x5"'
                    .' alt="" aria-hidden="true" width="18" height="18" />'
                    .'<span class="pintogya-forum-participant-count">'
                    .$participantCount.'</span></h3>'
                    .'<ul class="pintogya-activity-participants-list">'.$avatars.'</ul>'
                    .'<button type="button" class="pintogya-activity-participants-more"'
                    .($page['nextCursor'] === '0' ? ' hidden' : '').'>'
                    .$escape($lang['activity_participants_more']).'</button>'
                    .'<p class="pintogya-activity-participants-status" role="status" aria-live="polite"'
                    .($avatars !== '' ? ' hidden' : '').'>'.$escape($avatars === '' ? $lang['activity_participants_empty'] : '').'</p>'
                    .'</section>';
            } catch(Throwable $error) { /* Do not expose an invalid or inaccessible forum participant list. */ }
        }
        require_once dirname(__FILE__).'/lib/native_task.php';
        pintogya_display_headers();
        return '<link rel="stylesheet" href="source/plugin/pintogya/static/activity.css?1.0.0-alpha.1-x5" />'
            .'<section class="pintogya-activity-view" data-tid="'.(int)$tid.'">'
            .$rewardBox
            .'<h2>'.$escape($projection['displayTitle']).'</h2>'
            .$cover
            .'<div class="pintogya-activity-summary">'.$summary.'</div>'
            .$publicStages
            .$viewer
            .$publicParticipants
            .'<script defer src="source/plugin/pintogya/static/activity-interactions.js?1.0.0-alpha.1-x5"></script>'
            .'<script defer src="source/plugin/pintogya/static/activity-view.js?participation-state-20261006&amp;display=1.0.0-alpha.1-x5"></script>'
            .'<script defer src="source/plugin/pintogya/static/activity-public.js?1.0.0-alpha.1-x5"></script>'
            .'<a href="https://app.togya.com/#/activities/'.$escape($id).'" target="_blank" rel="noopener noreferrer">'
            .$escape($lang['activity_view_open']).'</a>'
            // TOGYA_PLUGIN pintogya_activity_store_downloads: reuse the task store badges and links.
            .'<div class="pintogya-activity-downloads">'.pintogya_native_render_platform_links($lang).'</div>'
            .'</section>';
    }

    private function publicContent($tid,$stageId,$comments,$lang) {
        $escape = static function($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); };
        $operation = $comments ? 'public_comments' : 'public_stage_feed';
        $title = $comments ? lang('forum/template','comments') : $lang['activity_stage_feed_show'];
        return '<section class="pintogya-public-content" data-kind="'.($comments?'comments':'feed').'"'
            .$this->interactionAttributes($lang,$escape)
            .' data-endpoint="plugin.php?id=pintogya:activity&amp;op='.$operation.'"'
            .' data-image-endpoint="plugin.php?id=pintogya:activity&amp;op=public_stage_image"'
            .' data-tid="'.$tid.'" data-stage-id="'.$escape($stageId).'" data-formhash="'.$escape(formhash()).'"'
            .' data-label-empty="'.$escape($comments?lang('forum/template','none'):$lang['activity_stage_feed_empty']).'"'
            .' data-label-error="'.$escape($lang['activity_error']).'" data-label-pending="'.$escape($lang['activity_display_pending']).'"'
            .' data-label-photo="'.$escape($lang['activity_stage_feed_photo']).'">'
            .'<h5>'.$escape($title).'</h5><ul class="pintogya-public-content-list"></ul>'
            .'<button type="button" class="pintogya-public-more" hidden>'.$escape(lang('forum/template','more')).'</button>'
            .'<p class="pintogya-public-status" role="status" aria-live="polite" hidden></p></section>';
    }

    private function interactionAttributes($lang,$escape) {
        $english=pintogya_task_lang_pack();$attributes=' data-interaction-endpoint="plugin.php?id=pintogya:activity&amp;op=interaction_prepare"';
        foreach(array('comment','rate','records','opening') as $key) {
            $label='activity_checkin_'.$key;$attributes.=' data-label-checkin-'.$key.'="'.$escape($lang[$label]??$english[$label]).'"';
        }
        return $attributes;
    }

    public function editpost($fid, $tid) {
        return '<p>This activity is managed in Togya.</p>';
    }

    public function editpost_submit($fid, $tid) {
        showmessage('forum_access_disallow');
    }

    protected function activityThread() {
        $services = $this->activityServices();
        return $services['activity_thread'];
    }

    protected function activityServices() {
        return pintogya_services();
    }

    protected function activityDisplayServices($services) {
        return pintogya_display_services($services);
    }
}
