<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    flash_set('error', 'Evolution API não configurada.');
    header('Location: /chat_web.php');
    exit;
}

try {
    // Sincronizar conversas
    $ch = curl_init($baseUrl . '/chat/findChats/' . urlencode($instanceName));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $chats = json_decode($response, true);
        $totalChats = is_array($chats) ? count($chats) : 0;
        $groups = 0;
        $private = 0;
        
        if (is_array($chats)) {
            foreach ($chats as $chat) {
                if (isset($chat['id']) && strpos($chat['id'], '@g.us') !== false) {
                    $groups++;
                } else {
                    $private++;
                }
            }
        }
        
        flash_set('success', "Sincronização concluída! Total: $totalChats conversas ($groups grupos, $private conversas privadas)");
        audit_log('whatsapp_sync', "WhatsApp sincronizado: $totalChats conversas");
    } else {
        flash_set('error', 'Erro ao sincronizar. Código: ' . $httpCode);
    }
} catch (Exception $e) {
    flash_set('error', 'Erro ao sincronizar: ' . $e->getMessage());
}

header('Location: /chat_web.php');
exit;
