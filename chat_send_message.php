<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$chatId = trim($_POST['chat_id'] ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($chatId) || empty($message)) {
    flash_set('error', 'Chat ID e mensagem são obrigatórios.');
    header('Location: /chat_web.php');
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    flash_set('error', 'Evolution API não configurada.');
    header('Location: /chat_web.php');
    exit;
}

try {
    $payload = [
        'number' => $chatId,
        'text' => $message
    ];
    
    $ch = curl_init($baseUrl . '/message/sendText/' . urlencode($instanceName));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        flash_set('success', 'Mensagem enviada com sucesso!');
        audit_log('chat_message_sent', 'Mensagem enviada para ' . $chatId);
    } else {
        flash_set('error', 'Erro ao enviar mensagem. Código: ' . $httpCode);
    }
} catch (Exception $e) {
    flash_set('error', 'Erro ao enviar mensagem: ' . $e->getMessage());
}

header('Location: /chat_web.php?chat=' . urlencode($chatId));
exit;
