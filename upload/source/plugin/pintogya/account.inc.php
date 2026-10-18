<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
require_once dirname(__FILE__).'/lib/pintogya.php';
require_once dirname(__FILE__).'/lib/account_connect.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$lang = pintogya_lang_pack();
$uid = (int)($_G['uid'] ?? 0);
if(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        pintogya_assert_post();
        if($uid < 1) { throw new RuntimeException('account_login_required'); }
        $binding = pintogya_services()['binding'];
        $operation = $_GET['op'] ?? '';
        if(!is_string($operation)) { throw new InvalidArgumentException('account_request_invalid'); }
        if($operation === 'connect_start') {
            // TOGYA_PLUGIN: pintogya_account_automatic_qr -- task-independent, owner-bound QR.
            $response = (new PintogyaAccountConnect($binding))->start($uid,
                pintogya_session_digest(), pintogya_api_locale());
            if($response['state'] === 'pending') {
                $response['qrUrl'] = pintogya_qr_browser_url($response['qrUrl']);
                $response['qrImage'] = pintogya_qr_data_uri($response['qrUrl']);
                if($response['qrImage'] === '') { throw new RuntimeException('account_qr_unavailable'); }
            }
        } elseif($operation === 'connect_poll') {
            $challengeId = $_POST['challenge_id'] ?? '';
            if(!is_string($challengeId)) { throw new InvalidArgumentException('account_request_invalid'); }
            $response = (new PintogyaAccountConnect($binding))->poll($uid,
                pintogya_session_digest(), $challengeId);
        } elseif($operation === 'unlink') {
            // TOGYA_PLUGIN: pintogya_button_unlink -- authenticated forum owner, no QR proof.
            $response = array('state' => $binding->unlink($uid));
        } elseif($operation === 'unlink_start') {
            $response = $binding->startUnlinkChallenge($uid, pintogya_session_digest(), pintogya_api_locale());
            $response['qrUrl'] = $response['state'] === 'pending' ? pintogya_qr_browser_url($response['qrUrl']) : '';
            $response['qrImage'] = $response['state'] === 'pending' ? pintogya_qr_data_uri($response['qrUrl']) : '';
            if($response['state'] === 'pending' && $response['qrImage'] === '') {
                throw new RuntimeException('account_qr_unavailable');
            }
        } elseif($operation === 'unlink_poll') {
            $challengeId = $_POST['challenge_id'] ?? '';
            if(!is_string($challengeId)) { throw new InvalidArgumentException('account_request_invalid'); }
            $response = array('approved' => $binding->pollUnlinkChallenge($uid,
                pintogya_session_digest(), $challengeId));
        } elseif($operation === 'unlink_confirm') {
            $challengeId = $_POST['challenge_id'] ?? '';
            if(!is_string($challengeId)) { throw new InvalidArgumentException('account_request_invalid'); }
            $response = array('state' => $binding->unlinkAfterProof($uid,
                pintogya_session_digest(), $challengeId));
        } elseif($operation === 'unlink_retry') {
            if($binding->status($uid) !== 'remote_revoke_pending') {
                throw new RuntimeException('account_retry_unavailable');
            }
            $response = array('state' => $binding->unlink($uid));
        } else { throw new InvalidArgumentException('account_request_invalid'); }
        echo json_encode(array('ok' => true, 'data' => $response), JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    } catch(Throwable $error) {
        http_response_code(400);
        $allowed = array('account_login_required', 'account_qr_unavailable', 'binding_inactive',
            'binding_identity_mismatch', 'unlink_proof_required', 'unlink_proof_revoke_pending',
            'remote_revoke_pending', 'activity_join_identity_mismatch', 'activity_join_approval_expired');
        $code = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'account_request_failed';
        echo json_encode(array('ok' => false, 'error' => $code));
    }
    exit;
}

$state = 'disconnected';
if($uid > 0) {
    try { $state = pintogya_services()['binding']->status($uid); }
    catch(Throwable $error) { $state = 'unavailable'; }
}
$escape = static function($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$navtitle = $lang['account_manage_title'];
include template('common/header');
echo '<link rel="stylesheet" href="source/plugin/pintogya/static/account.css?automatic-qr-20261005" />';
echo '<main class="pintogya-account" data-endpoint="plugin.php?id=pintogya:account"'
    .' data-formhash="'.$escape(formhash()).'" data-state="'.$escape($state).'"'
    .' data-label-error="'.$escape($lang['account_unlink_error']).'"'
    .' data-label-remote-pending="'.$escape($lang['account_unlink_remote_pending']).'"'
    .' data-label-connect-error="'.$escape($lang['activity_join_error']).'"'
    .' data-label-preparing="'.$escape($lang['activity_join_connecting']).'"'
    .' data-label-waiting="'.$escape($lang['pending']).'"'
    .' data-label-expired="'.$escape($lang['activity_join_expired']).'"'
    .' data-label-mismatch="'.$escape($lang['activity_join_identity_mismatch']).'"'
    .' data-label-done="'.$escape($lang['unlinked']).'">';
echo '<h1>'.$escape($lang['account_manage_title']).'</h1>';
if($uid < 1) {
    echo '<p>'.$escape($lang['account_login_required']).'</p>';
} elseif($state === 'connected') {
    echo '<p class="pintogya-account-state">'.$escape($lang['state_connected']).'</p>'
        .'<button class="pintogya-account-disconnect" type="button">'.$escape($lang['account_unlink_confirm']).'</button>';
} elseif($state === 'remote_revoke_pending') {
    echo '<p>'.$escape($lang['account_unlink_remote_pending']).'</p>'
        .'<button class="pintogya-account-retry" type="button">'.$escape($lang['account_unlink_retry']).'</button>';
} elseif($state === 'unavailable') {
    echo '<p>'.$escape($lang['account_unlink_error']).'</p>';
} else {
    echo '<p class="pintogya-account-state">'.$escape($lang['state_not_connected']).'</p>'
        .'<div class="pintogya-account-qr" hidden><img alt="'.$escape($lang['qr_alt']).'" />'
        .'<p>'.$escape($lang['activity_scan_help']).'</p></div>'
        .'<button class="pintogya-account-connect" type="button" hidden>'.$escape($lang['new_qr']).'</button>';
}
echo '<p class="pintogya-account-status" role="status" aria-live="polite"></p>'
    .'<p class="pintogya-account-note">'.$escape($lang['account_unlink_note']).'</p>'
    .'</main>';
if($uid > 0) { echo '<script defer src="source/plugin/pintogya/static/account.js?automatic-qr-20261005"></script>'; }
include template('common/footer');
