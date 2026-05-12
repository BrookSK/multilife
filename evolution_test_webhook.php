<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Teste de Webhook</h2><pre>';

// Simular um payload que a Evolution enviaria
$testPayload = json_encode([
    'event' => 'messages.upsert',
    'instance' => 'multilife_whats',
    'data' => [
        [
            'key' => [
                'remoteJid' => '120363409753301661@g.us',
                'fromMe' => false,
                'id' => 'TEST_' . time(),
                'participant' => '5517999999999@s.whatsapp.net'
            ],
            'message' => [
                'conversation' => 'TESTE WEBHOOK - ' . date('H:i:s')
            ],
            'messageTimestamp' => time(),
            'pushName' => 'Teste Webhook'
        ]
    ]
]);

$webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/chat_webhook.php';

echo "Enviando POST para: $webhookUrl\n";
echo "Payload: " . substr($testPayload, 0, 200) . "...\n\n";

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $testPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
echo "cURL Error: $curlError\n\n";

if ($httpCode === 200) {
    echo "✅ Webhook acessível do próprio servidor!\n";
    echo "Se a mensagem 'TESTE WEBHOOK' aparecer no chat do grupo, o webhook funciona.\n";
    echo "Se não aparecer, o problema é que a Evolution API não está disparando.\n\n";
    
    echo "=== PRÓXIMO PASSO ===\n";
    echo "Acesse o Manager da Evolution: http://177.104.185.7:8081/manager/\n";
    echo "1. Selecione a instância 'multilife_whats'\n";
    echo "2. Vá em Settings/Webhook\n";
    echo "3. Verifique se a URL está correta\n";
    echo "4. Tente desativar e reativar o webhook\n";
} else {
    echo "❌ Webhook NÃO acessível do próprio servidor!\n";
    echo "Problema de rede/SSL.\n";
    
    // Tentar com HTTP
    $httpUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/chat_webhook.php';
    echo "\nTentando com HTTP (sem SSL): $httpUrl\n";
    
    $ch2 = curl_init($httpUrl);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $testPayload);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    
    $response2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $curlError2 = curl_error($ch2);
    curl_close($ch2);
    
    echo "HTTP Code: $httpCode2\n";
    echo "Response: $response2\n";
    echo "cURL Error: $curlError2\n";
}

echo '</pre>';
echo '<br><a href="/admin_settings.php">Voltar</a>';
