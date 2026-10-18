<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

require_once dirname(__FILE__).'/lib/pintogya.php';
require_once dirname(__FILE__).'/lib/activity_api.php';
require_once dirname(__FILE__).'/lib/activity_settings.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

try {
    pintogya_assert_post();
    $operation = $_GET['op'] ?? '';
    if(!is_string($operation)) { throw new InvalidArgumentException('activity_request_invalid'); }
    if(in_array($operation,array('public_card','viewer','reward_status','participants','stage_feed','stage_image',
        'public_stage_feed','public_stage_image','public_comments','interaction_prepare','review_status','review_queue'),true)) {
        if(!defined('PINTOGYA_DISPLAY_REQUEST')) { define('PINTOGYA_DISPLAY_REQUEST',true); }
    }
    $services = pintogya_services();
    if($operation === 'public_card' || $operation === 'viewer' || $operation === 'publication_resume' || $operation === 'reward_status' || $operation === 'join_start' || $operation === 'join_poll' ||
        $operation === 'participants' || $operation === 'stage_feed' || $operation === 'stage_image' ||
        $operation === 'public_stage_feed' || $operation === 'public_stage_image' || $operation === 'public_comments' || $operation === 'interaction_prepare' ||
        $operation === 'photo_removal_request' ||
        $operation === 'review_submit' || $operation === 'review_status' ||
        $operation === 'review_queue' || $operation === 'review_detail' || $operation === 'review_reject') {
        // TOGYA_PLUGIN BEGIN pintogya_activity_viewer_state
        $rawTid = $_POST['tid'] ?? '';
        if(!is_string($rawTid) || !preg_match('/^[1-9][0-9]{0,9}$/D', $rawTid) || (int)$rawTid > 4294967295) {
            throw new InvalidArgumentException('activity_tid_invalid');
        }
        if(in_array($operation,array('public_card','viewer','reward_status','participants','stage_feed','stage_image',
            'public_stage_feed','public_stage_image','public_comments','interaction_prepare'),true)) {
            PintogyaActivityReadAccess::assertCurrent((int)$rawTid);
        }
        if(in_array($operation,array('public_card','viewer','participants','stage_feed','stage_image',
            'public_stage_feed','public_stage_image','public_comments','interaction_prepare'),true)) {
            $display=pintogya_display_services($services);
        }
        if($operation === 'interaction_prepare') {
            if((int)$_G['uid']<1)throw new RuntimeException('activity_login_required');
            $stageId=$_POST['stage_id']??'';$checkinId=$_POST['checkin_id']??'';$cursor=$_POST['cursor']??'';$action=$_POST['action']??'';
            if(!is_string($stageId)||!is_string($checkinId)||!is_string($cursor)||!is_string($action))throw new InvalidArgumentException('checkin_interaction_input_invalid');
            $response=pintogya_checkin_interactions($display)->prepare((int)$_G['uid'],(int)$rawTid,$stageId,$checkinId,$cursor,
                pintogya_api_locale(),$action,(int)TIMESTAMP);
        } elseif($operation === 'public_card') {
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            $response = $display['activity_thread']->publicCard((int)$rawTid, pintogya_api_locale(),
                $_G['setting']['extcredits'] ?? array(),
                (string)($_G['setting']['pintogya_activity_settlement_enabled'] ?? '0') === '1');
        } elseif(in_array($operation,array('public_stage_feed','public_stage_image','public_comments'),true)) {
            // The DX topic gate above applies equally to guests and members; the source is always anonymous.
            $projection = $display['activity_thread']->publicProjection((int)$rawTid, pintogya_api_locale());
            $activityId = $projection['activity']['id'];
            $version = $projection['activity']['stageVersion'];
            $stageId = $_POST['stage_id'] ?? '';
            $cursor = $_POST['cursor'] ?? '';
            if(!is_string($stageId) || !is_string($cursor)) { throw new InvalidArgumentException('activity_feed_input_invalid'); }
            if($operation === 'public_comments') {
                $response = $display['activity_public_read']->comments($activityId,$version,pintogya_api_locale(),$cursor);
            } elseif($operation === 'public_stage_feed') {
                $response = pintogya_checkin_interactions($display)->feed((int)$_G['uid'],(int)$rawTid,$stageId,$cursor,pintogya_api_locale(),true);
            } else {
                $checkinId = $_POST['checkin_id'] ?? '';
                $index = $_POST['image_index'] ?? '';
                if(!is_string($checkinId) || !is_string($index) || !preg_match('/^[0-5]$/D',$index)) {
                    throw new InvalidArgumentException('activity_image_input_invalid');
                }
                $image = $display['activity_public_read']->image($activityId,$version,pintogya_api_locale(),$stageId,
                    $cursor,$checkinId,(int)$index);
                header('Content-Type: '.$image['contentType']);
                header('Content-Length: '.strlen($image['body']));
                pintogya_display_headers();
                echo $image['body'];
                exit;
            }
        } elseif($operation === 'publication_resume') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            if(!PintogyaActivitySettings::isPublisherGroupAllowed((int)($_G['groupid'] ?? 0), $_G['setting'])) {
                throw new RuntimeException('activity_publisher_group_not_permitted');
            }
            $response = $services['activity_thread']->resumePublication((int)$_G['uid'], (int)$rawTid,
                pintogya_session_digest(), (int)TIMESTAMP);
        } elseif($operation === 'reward_status') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            $response = $services['activity_thread']->instantRewardStatus((int)$_G['uid'], (int)$rawTid,
                pintogya_api_locale());
        } elseif($operation === 'viewer') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            $response = $display['display_view']->viewerState((int)$_G['uid'], (int)$rawTid,pintogya_api_locale());
        } elseif($operation === 'review_status') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            if((string)($_G['setting'][PintogyaActivitySettings::REVIEW_ENABLED_KEY] ?? '0') !== '1') {
                throw new RuntimeException('activity_reviews_disabled');
            }
            $response = (new PintogyaActivityReviewStatus($services['repository'],
                pintogya_display_services($services)['activity_thread']))->forMember((int)$_G['uid'], (int)$rawTid,
                pintogya_api_locale());
        } elseif($operation === 'review_queue') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            if((string)($_G['setting'][PintogyaActivitySettings::REVIEW_ENABLED_KEY] ?? '0') !== '1') {
                throw new RuntimeException('activity_reviews_disabled');
            }
            $rawAfter = $_POST['after'] ?? '0';
            if(!is_string($rawAfter) || !preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $rawAfter) ||
                (string)(int)$rawAfter !== $rawAfter) {
                throw new InvalidArgumentException('activity_review_cursor_invalid');
            }
            $response = (new PintogyaActivityReviewStatus($services['repository'],
                pintogya_display_services($services)['activity_thread']))->forModerator((int)$_G['uid'],
                (int)$rawTid, (int)$rawAfter, pintogya_api_locale());
        } elseif($operation === 'review_detail') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            if((string)($_G['setting'][PintogyaActivitySettings::REVIEW_ENABLED_KEY] ?? '0') !== '1') {
                throw new RuntimeException('activity_reviews_disabled');
            }
            $rawReviewPk = $_POST['review_pk'] ?? '';
            if(!is_string($rawReviewPk) || !preg_match('/^[1-9][0-9]{0,18}$/D', $rawReviewPk) ||
                (string)(int)$rawReviewPk !== $rawReviewPk) {
                throw new InvalidArgumentException('activity_review_input_invalid');
            }
            $response = $services['activity_review_detail']->checkinForModerator((int)$_G['uid'],
                (int)$rawTid, (int)$rawReviewPk, pintogya_api_locale());
        } elseif($operation === 'review_reject') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            if((string)($_G['setting'][PintogyaActivitySettings::REVIEW_ENABLED_KEY] ?? '0') !== '1') {
                throw new RuntimeException('activity_reviews_disabled');
            }
            $rawReviewPk = $_POST['review_pk'] ?? '';
            if(!is_string($rawReviewPk) || !preg_match('/^[1-9][0-9]{0,18}$/D', $rawReviewPk) ||
                (string)(int)$rawReviewPk !== $rawReviewPk) {
                throw new InvalidArgumentException('activity_review_input_invalid');
            }
            $scope = (new PintogyaActivityReviewAccess($services['repository']))->assertCurrentModerator(
                (int)$_G['uid'], (int)$rawTid);
            $review = $services['repository']->reviewCaseByPk((int)$rawReviewPk);
            if(!$review || !in_array($review['kind'], array('checkin', 'completion'), true) ||
                $review['state_code'] !== 'pending' || $review['decision_source'] !== 'none' ||
                (int)$review['activity_pk'] !== $scope['activity_pk'] ||
                (int)$review['stage_version'] !== $scope['stage_version'] ||
                (int)$review['uid'] === (int)$_G['uid']) {
                throw new RuntimeException('activity_review_evidence_unavailable');
            }
            if($review['kind'] === 'checkin') {
                $services['activity_review_detail']->checkinForModerator((int)$_G['uid'],
                    (int)$rawTid, (int)$rawReviewPk, pintogya_api_locale());
            }
            $extcredits = $_G['setting']['extcredits'] ?? array();
            $response = array('state' => $services['activity_moderation']->decide((int)$_G['uid'],
                (int)$rawReviewPk, 'rejected', is_array($extcredits) ? $extcredits : array(), (int)$rawTid));
        } elseif($operation === 'review_submit') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            if((string)($_G['setting'][PintogyaActivitySettings::REVIEW_ENABLED_KEY] ?? '0') !== '1') {
                throw new RuntimeException('activity_reviews_disabled');
            }
            $kind = $_POST['kind'] ?? '';
            $stageId = $_POST['stage_id'] ?? '';
            $checkinId = $_POST['checkin_id'] ?? '';
            if(!is_string($kind) || !is_string($stageId) || !is_string($checkinId)) {
                throw new InvalidArgumentException('activity_review_input_invalid');
            }
            $response = $services['activity_review']->submit((int)$_G['uid'], (int)$rawTid,
                $kind, $kind === 'completion' ? null : $stageId,
                $kind === 'completion' ? null : $checkinId, pintogya_api_locale(),
                (string)($_G['setting'][PintogyaActivitySettings::REVIEW_AUTO_DAYS_KEY] ??
                    '{"checkin":0,"completion":0}'));
        } elseif($operation === 'photo_removal_request') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            if(!$services['activity_photo_removal_store']->isInstalled()) {
                throw new RuntimeException('activity_photo_removal_unavailable');
            }
            $stageId = $_POST['stage_id'] ?? '';
            $checkinId = $_POST['checkin_id'] ?? '';
            $cursor = $_POST['cursor'] ?? '';
            if(!is_string($stageId) || !is_string($checkinId) || !is_string($cursor)) {
                throw new InvalidArgumentException('activity_photo_removal_input_invalid');
            }
            $response = $services['activity_photo_removal']->request((int)$_G['uid'], (int)$rawTid,
                $stageId, $checkinId, $cursor, pintogya_api_locale());
        } elseif($operation === 'stage_feed') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            $stageId = $_POST['stage_id'] ?? '';
            $cursor = $_POST['cursor'] ?? '';
            if(!is_string($stageId) || !is_string($cursor)) {
                throw new InvalidArgumentException('activity_feed_input_invalid');
            }
            $response = pintogya_checkin_interactions($display)->feed((int)$_G['uid'],(int)$rawTid,$stageId,$cursor,pintogya_api_locale());
        } elseif($operation === 'stage_image') {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            $stageId = $_POST['stage_id'] ?? '';
            $cursor = $_POST['cursor'] ?? '';
            $checkinId = $_POST['checkin_id'] ?? '';
            $imageIndex = $_POST['image_index'] ?? '0';
            if(!is_string($stageId) || !is_string($cursor) || !is_string($checkinId) ||
                !is_string($imageIndex) || !preg_match('/^[0-5]$/D',$imageIndex)) {
                throw new InvalidArgumentException('activity_image_input_invalid');
            }
            $image = $display['activity_thread']->stageImage((int)$_G['uid'], (int)$rawTid,
                pintogya_api_locale(), $stageId, $cursor, $checkinId, (int)$imageIndex);
            header('Content-Type: '.$image['contentType']);
            header('Content-Length: '.strlen($image['body']));
            pintogya_display_headers();
            echo $image['body'];
            exit;
        } elseif($operation === 'participants') {
            $visibility = DB::fetch_first('SELECT f.status,f.type,ff.viewperm,ff.password,t.readperm,t.price FROM '
                .DB::table('forum_thread').' t INNER JOIN '.DB::table('forum_forum').' f ON f.fid=t.fid'
                .' INNER JOIN '.DB::table('forum_forumfield').' ff ON ff.fid=f.fid WHERE t.tid='.(int)$rawTid.' LIMIT 1');
            if(!PintogyaActivityApi::publicForumAllowsRoster($visibility)) {
                throw new RuntimeException('activity_thread_unavailable');
            }
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            $rawAfter = $_POST['after'] ?? '0';
            if(!is_string($rawAfter) || !preg_match('/^(?:0|[1-9][0-9]{0,17})$/D', $rawAfter)) {
                throw new InvalidArgumentException('activity_participants_cursor_invalid');
            }
            $response = $display['display_view']->participants((int)$rawTid, (int)$rawAfter);
            foreach($response['items'] as &$participant) {
                $participant['avatarUrl'] = avatar($participant['uid'], 'small', true);
            }
            unset($participant);
        } else {
            if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
            // TOGYA_PLUGIN BEGIN pintogya_activity_join
            if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
                throw new RuntimeException('activity_posts_disabled');
            }
            $method = $operation === 'join_start' ? 'start' : 'poll';
            $response = $services['activity_join']->$method((int)$_G['uid'], (int)$rawTid,
                pintogya_session_digest(), pintogya_api_locale(), (int)TIMESTAMP);
            // TOGYA_PLUGIN END pintogya_activity_join
        }
        // TOGYA_PLUGIN END pintogya_activity_viewer_state
    } else {
        if((int)$_G['uid'] < 1) { throw new RuntimeException('activity_login_required'); }
        if((string)($_G['setting']['pintogya_activity_posts_enabled'] ?? '0') !== '1') {
            throw new RuntimeException('activity_posts_disabled');
        }
        if(!PintogyaActivitySettings::isPublisherGroupAllowed((int)($_G['groupid'] ?? 0), $_G['setting'])) {
            throw new RuntimeException('activity_publisher_group_not_permitted');
        }
        $fid = (int)($_G['setting']['pintogya_activity_forum_fid'] ?? 0);
        $activityId = $_POST['activity_id'] ?? '';
        if(!is_string($activityId)) { throw new InvalidArgumentException('activity_request_invalid'); }
        $handler = new PintogyaActivityApi($services['binding'], $services['activity_read'],
            $services['activity_publish'], 'pintogya_qr_data_uri');
        $response = $handler->execute($operation, (int)$_G['uid'], $fid,
            pintogya_session_digest(), pintogya_api_locale(), (int)TIMESTAMP, $activityId);
    }
    if(isset($display) || ($operation ?? '')==='reward_status') { pintogya_display_headers(); }
    echo json_encode(array('ok' => true, 'data' => $response), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
} catch(Throwable $error) {
    http_response_code($error->getMessage()==='activity_display_pending' ? 503 : 400);
    if($error->getMessage()==='activity_display_pending') { header('Retry-After: 60'); }
    $reconnectErrors = array('api_unauthorized', 'binding_token_unavailable', 'refresh_session_invalid', 'instant_read_session_expired');
    $allowed = array('activity_display_pending','activity_display_forbidden','checkin_interaction_unavailable','checkin_interaction_busy','checkin_interaction_schema_missing',
        'activity_login_required', 'activity_posts_disabled', 'activity_qr_approval_required',
        'activity_not_owned_public', 'activity_already_mapped', 'activity_qr_unavailable',
        'activity_reward_rules_unavailable', 'activity_join_closed',
        'activity_join_identity_mismatch', 'activity_join_approval_expired',
        'activity_join_approval_changed', 'activity_join_verification_unavailable',
        'activity_review_participation_required', 'activity_review_source_unavailable',
        'activity_review_context_changed', 'activity_review_evidence_unavailable',
        'activity_photo_removal_unavailable', 'activity_photo_removal_input_invalid',
        'activity_photo_removal_binding_unavailable', 'activity_photo_removal_context_changed',
        'activity_photo_removal_evidence_unavailable', 'activity_review_forbidden',
        'instant_publication_resume_unavailable', 'instant_publication_commit_outcome_unknown',
        'instant_publication_rollback_outcome_unknown');
    $code = ($operation ?? '') === 'viewer' && in_array($error->getMessage(), $reconnectErrors, true)
        ? 'activity_reconnect_required'
        : (in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'activity_request_failed');
    if($code==='activity_display_pending' && isset($display) && !empty($_G['uid']) &&
        in_array($operation,array('stage_feed','stage_image'),true) &&
        method_exists($display['display_view'],'confirmsStage') &&
        $display['display_view']->confirmsStage((int)$_G['uid'],(int)$rawTid,pintogya_api_locale(),$_POST['stage_id']??null)) {
        // Only a still-valid local parent grant permits retaining personal instructions while media fills.
        $code='activity_media_sync_pending';
    }
    if(isset($display) || ($operation ?? '')==='reward_status') { pintogya_display_headers(); }
    echo json_encode(array('ok' => false, 'error' => $code));
}
exit;
