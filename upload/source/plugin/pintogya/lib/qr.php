<?php
if(!defined('IN_DISCUZ') && !defined('PINTOGYA_STATIC_TEST')) { exit('Access Denied'); }

function pintogya_qr_register_autoloader() {
	static $registered = false;
	if($registered || class_exists('chillerlan\\QRCode\\QRCode')) {
		return true;
	}
	if(PHP_VERSION_ID < 80200) {
		return false;
	}

	$vendor = dirname(__DIR__).'/vendor/chillerlan/';
	$prefixes = array(
		'chillerlan\\QRCode\\' => $vendor.'php-qrcode/src/',
		'chillerlan\\Settings\\' => $vendor.'php-settings-container/src/',
	);
	spl_autoload_register(static function($class) use ($prefixes) {
		foreach($prefixes as $prefix => $directory) {
			if(strncmp($class, $prefix, strlen($prefix)) !== 0) {
				continue;
			}
			$relative = substr($class, strlen($prefix));
			if(!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
				return;
			}
			$file = $directory.str_replace('\\', '/', $relative).'.php';
			if(is_file($file)) {
				require_once $file;
			}
			return;
		}
	}, true, true);
	$registered = true;

	return class_exists('chillerlan\\QRCode\\QRCode');
}

function pintogya_qr_browser_url($url) {
	$url = (string)$url;
	if($url === '' || $url !== trim($url) || strlen($url) > 512) {
		return '';
	}
	$parts = parse_url($url);
	if(!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'togya.com' || ($parts['path'] ?? '') !== '/auth/browser' || isset($parts['fragment']) || !isset($parts['query'])) { return ''; }
	$query = array();
	foreach(explode('&', $parts['query']) as $pair) {
		if(substr_count($pair, '=') !== 1) { return ''; }
		list($key, $value) = explode('=', $pair, 2); $key = rawurldecode($key); $value = rawurldecode($value);
		if(isset($query[$key])) { return ''; } $query[$key] = $value;
	}
	ksort($query, SORT_STRING);
	if(array_keys($query) !== array('challenge','nonce')) { return ''; }
	try {
		PintogyaCrypto::assertUuid($query['challenge']);
		PintogyaCrypto::b64urlDecode($query['nonce'], 32);
	} catch(\Throwable $exception) {
		return '';
	}
	return 'https://togya.com/auth/browser?challenge='.rawurlencode($query['challenge']).'&nonce='.rawurlencode($query['nonce']);
}

function pintogya_qr_safe_data_uri($dataUri) {
	$dataUri = trim((string)$dataUri);
	if($dataUri === '' || strlen($dataUri) > 1048576) {
		return '';
	}
	return preg_match('#^data:image/(?:png|svg\+xml);base64,[A-Za-z0-9+/=]+$#D', $dataUri) ? $dataUri : '';
}

function pintogya_qr_data_uri($content) {
	$content = pintogya_qr_browser_url($content);
	if($content === '' || !pintogya_qr_register_autoloader()) {
		return '';
	}

	try {
		$options = new \chillerlan\QRCode\QROptions(array(
			'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
			'outputBase64' => true,
			'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
			'addQuietzone' => true,
			'svgAddXmlHeader' => false,
		));
		$dataUri = (new \chillerlan\QRCode\QRCode($options))->render($content);
		return pintogya_qr_safe_data_uri($dataUri);
	} catch(\Throwable $exception) {
		return '';
	}
}
