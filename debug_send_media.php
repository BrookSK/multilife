<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
header('Content-Type: application/json');

$baseUrl = trim((string)admin_setting_get('evolution.base_url', ''));
$apiKey = trim((string)admin_setting_get('evolution.api_key', ''));
$instance = trim((string)admin_setting_get('evolution.instance', ''));

// Testar envio de áudio com diferentes formatos de payload
$testNumber = '5517988358367';
$testBase64 = base64_encode('test'); // base64 falso só para ver o formato aceito

$results = [];

// Formato 1: campos no nível raiz
$payload1 = json_encode([
    'number' => $testNumber,
    'mediatype' => 'audio',
    'media' => 'data:audio/ogg;base64,' . $testBase64,
    'fileName' => 'test.ogg',
]);
$url = $baseUrl . '/message/sendMedia/' . urlencode($instance);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$r1 = curl_exec($ch);
$c1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$results[] = ['format' => 'flat_mediatype', 'code' => $c1, 'response' => substr($r1, 0, 300)];

// Formato 2: mediaMessage wrapper
$payload2 = json_encode([
    'number' => $testNumber,
    'mediaMessage' => [
        'mediatype' => 'audio',
        'media' => 'data:audio/ogg;base64,' . $testBase64,
        'fileName' => 'test.ogg',
    ],
]);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload2);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$r2 = curl_exec($ch);
$c2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$results[] = ['format' => 'mediaMessage_wrapper', 'code' => $c2, 'response' => substr($r2, 0, 300)];

// Formato 3: sendWhatsAppAudio com base64
$url3 = $baseUrl . '/message/sendWhatsAppAudio/' . urlencode($instance);
$payload3 = json_encode([
    'number' => $testNumber,
    'audio' => 'data:audio/ogg;base64,' . $testBase64,
]);
$ch = curl_init($url3);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload3);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$r3 = curl_exec($ch);
$c3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$results[] = ['format' => 'sendWhatsAppAudio_flat', 'code' => $c3, 'response' => substr($r3, 0, 300)];

echo json_encode($results, JSON_PRETTY_PRINT);
