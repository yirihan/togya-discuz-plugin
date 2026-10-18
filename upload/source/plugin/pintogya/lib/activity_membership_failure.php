<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }
require_once __DIR__.'/refresh_budget.php';

final class PintogyaMembershipFailure {
    public static function classify($error, $activity = false) {
        $message = $error->getMessage();
        $http = $message === 'api_http_error' ? $error->getCode() : 0;
        if($error instanceof PintogyaRefreshDeferred && $message === 'auth_refresh_budget_wait') {
            return array('scope'=>'deferred','code'=>'auth_refresh_budget_wait');
        }
        if(in_array($message,array('binding_inactive','binding_changed','binding_token_unavailable',
            'api_unauthorized','activity_identity_mismatch','instant_read_session_expired','instant_read_permission_unavailable'),true) ||
            preg_match('/^api_http_(401|403)(?:_[0-9]+)?$/D',$message) || (!$activity && in_array($http,array(403,404),true))) {
            return array('scope'=>'isolated','code'=>'membership_binding_unavailable');
        }
        if($activity && (in_array($message,array('activity_not_public','activity_not_owned_public','activity_source_changed',
            'activity_version_changed','activity_membership_audit_source_changed'),true) || $http === 404 || preg_match('/^api_http_404(?:_[0-9]+)?$/D',$message))) {
            return array('scope'=>'isolated','code'=>'membership_activity_unavailable');
        }
        if(($http >= 500 && $http <= 599) || $http === 429 || preg_match('/^api_http_(429|5[0-9]{2})(?:_[0-9]+)?$/D',$message) ||
            in_array($message,array('api_transport_failed','api_transport_unavailable','api_deadline_exhausted','api_timeout','api_connection_failed'),true)) {
            return array('scope'=>'service','code'=>'membership_service_unavailable');
        }
        // Unclassified/database outcomes are not made safe by labelling them an object fault.
        return array('scope'=>'unknown','code'=>'membership_outcome_unknown');
    }

    public static function counters() { return array('isolated'=>0,'deferred'=>0,'service'=>0,'unknown'=>0); }

    public static function valid($fault) {
        $scopes = array('auth_refresh_budget_wait'=>'deferred','membership_binding_unavailable'=>'isolated',
            'membership_activity_unavailable'=>'isolated','membership_service_unavailable'=>'service','membership_outcome_unknown'=>'unknown');
        return is_array($fault) && isset($scopes[$fault['code'] ?? '']) && $scopes[$fault['code']] === ($fault['scope'] ?? null);
    }
}
