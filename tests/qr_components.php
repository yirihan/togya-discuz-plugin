<?php
define('IN_DISCUZ',true);define('PINTOGYA_STATIC_TEST',true);
$source=getenv('TOGYA_PLUGIN_SOURCE');
if(!$source||!is_file($source.'/lib/qr.php'))exit(2);
require $source.'/lib/crypto.php';require $source.'/lib/qr.php';
$id='10000000-0000-4000-8000-000000000001';
$nonce=PintogyaCrypto::b64urlEncode(str_repeat('n',32));
$url='https://togya.com/auth/browser?challenge='.$id.'&nonce='.$nonce;
$cases=array();
function qrCase($name,$passed){global $cases;$cases[]=array('case'=>$name,'passed'=>$passed);}
qrCase('canonical_url',pintogya_qr_browser_url($url)===$url);
qrCase('foreign_origin_rejected',pintogya_qr_browser_url(str_replace('togya.com','other.example.org',$url))==='');
qrCase('duplicate_nonce_rejected',pintogya_qr_browser_url($url.'&nonce='.$nonce)==='');
qrCase('fragment_rejected',pintogya_qr_browser_url($url.'#synthetic')==='');
qrCase('unsafe_mime_rejected',pintogya_qr_safe_data_uri('data:text/html;base64,PGgxPg==')==='');
$data=pintogya_qr_data_uri($url);
$prefix='data:image/svg+xml;base64,';$svg=str_starts_with($data,$prefix)?base64_decode(substr($data,strlen($prefix)),true):false;
$dom=new DOMDocument();
$rendered=is_string($svg)&&$svg!==''&&$dom->loadXML($svg,LIBXML_NONET)&&$dom->documentElement->localName==='svg';
qrCase('vendor_svg_rendered',$rendered&&$dom->getElementsByTagName('path')->length>0&&$dom->getElementsByTagName('script')->length===0);
$passed=!in_array(false,array_column($cases,'passed'),true);
echo json_encode(array('scope'=>'offline QR validation/rendering only; no live QR approval, camera, browser or native install proof','cases'=>$cases,'passed'=>$passed)).PHP_EOL;
exit($passed?0:1);
