<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

echo '<h2>Corrigir Webhook para Grupos</h2><pre>';

// 1. Verificar settings atuais da instância
echo "=== SETTINGS ATUAIS ===\n";
$url = $baseUrl . '/settings/find/' . urlencode($instanceName);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $code\n";
echo "Resposta: $resp\n\n";

// 2. Atualizar settings para NÃO ignorar grupos
echo "=== ATUALIZANDO SETTINGS (groups_ignore = false) ===\n";
$url2 = $baseUrl . '/settings/set/' . urlencode($instanceName);
$payload2 = json_encode([
    'reject_call' => false,
    'msg_call' => '',
    'groups_ignore' => false,
    'always_online' => true,
    'read_messages' => false,
    'read_status' => false,
    'sync_full_history' => false,
    'wavoipToken' => '',
]);

$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload2);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
$resp2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo "HTTP: $code2\n";
echo "Resposta: $resp2\n\n";

// 3. Tentar também via PUT
if ($code2 !== 200 && $code2 !== 201) {
    echo "=== TENTANDO VIA PUT ===\n";
    $ch3 = curl_init($url2);
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch3, CURLOPT_POSTFIELDS, $payload2);
    curl_setopt($ch3, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
    curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch3, CURLOPT_TIMEOUT, 10);
    $resp3 = curl_exec($ch3);
    $code3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    curl_close($ch3);
    echo "HTTP: $code3\n";
    echo "Resposta: $resp3\n\n";
}

// 4. Verificar settings após atualização
echo "=== SETTINGS APÓS ATUALIZAÇÃO ===\n";
$ch4 = curl_init($url);
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch4, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch4, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch4, CURLOPT_TIMEOUT, 10);
$resp4 = curl_exec($ch4);
$code4 = curl_getinfo($ch4, CURLINFO_HTTP_CODE);
curl_close($ch4);
echo "HTTP: $code4\n";
echo "Resposta: $resp4\n\n";

// 5. Reconfigurar webhook com groups_ignore explícito
echo "=== RECONFIGURANDO WEBHOOK ===\n";
$webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/chat_webhook.php';
$url5 = $baseUrl . '/webhook/set/' . urlencode($instanceName);
$payload5 = json_encode([
    'url' => $webhookUrl,
    'webhook_by_events' => false,
    'webhook_base64' => true,
    'events' => [
        'MESSAGES_UPSERT',
        'SEND_MESSAGE',
        'CONTACTS_UPSERT',
        'CONTACTS_UPDATE',
        'CONNECTION_UPDATE',
        'GROUPS_UPSERT',
        'GROUP_UPDATE',
        'GROUP_PARTICIPANTS_UPDATE',
    ]
]);

$ch5 = curl_init($url5);
curl_setopt($ch5, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch5, CURLOPT_POST, true);
curl_setopt($ch5, CURLOPT_POSTFIELDS, $payload5);
curl_setopt($ch5, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
curl_setopt($ch5, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch5, CURLOPT_TIMEOUT, 10);
$resp5 = curl_exec($ch5);
$code5 = curl_getinfo($ch5, CURLINFO_HTTP_CODE);
curl_close($ch5);
echo "HTTP: $code5\n";
echo "Resposta: $resp5\n\n";

echo "=== CONCLUÍDO ===\n";
echo "Agora envie uma mensagem no grupo pelo WhatsApp e verifique se aparece no sistema.\n";
echo "Se ainda não funcionar, o problema pode ser que a Evolution precisa ser reiniciada.\n";
echo "Clique em REINICIAR no Manager da Evolution para a instância multilife_whats.\n";

echo '</pre>';
echo '<br><a href="/admin_settings.php">Voltar</a>';
