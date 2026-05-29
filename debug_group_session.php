<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

$groupJid = trim($_GET['jid'] ?? '120363408796989466@g.us');

echo '<h2>Diagnóstico de Grupo - Sessão</h2><pre>';
echo "Grupo JID: $groupJid\n\n";

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

// 1. Verificar se o grupo existe na Evolution
echo "=== 1. BUSCAR GRUPO NA EVOLUTION ===\n";
$url = $baseUrl . '/group/findGroupInfos/' . urlencode($instanceName) . '?groupJid=' . urlencode($groupJid);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $code\n";
if ($code === 200) {
    $data = json_decode($resp, true);
    echo "Nome: " . ($data['subject'] ?? $data['name'] ?? '?') . "\n";
    echo "Participantes: " . (isset($data['participants']) ? count($data['participants']) : '?') . "\n";
    echo "Criado em: " . ($data['creation'] ?? '?') . "\n";
    if (isset($data['participants'])) {
        echo "Lista de participantes:\n";
        foreach ($data['participants'] as $p) {
            echo "  - " . ($p['id'] ?? '?') . " (admin: " . ($p['admin'] ?? 'no') . ")\n";
        }
    }
} else {
    echo "❌ Grupo não encontrado! Resposta: " . substr($resp, 0, 300) . "\n";
}

// 2. Verificar status da conexão
echo "\n=== 2. STATUS DA CONEXÃO ===\n";
$url2 = $baseUrl . '/instance/connectionState/' . urlencode($instanceName);
$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
$resp2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo "HTTP: $code2\n";
$stateData = json_decode($resp2, true);
$state = $stateData['state'] ?? $stateData['instance']['state'] ?? '?';
echo "Estado: $state\n";

// 3. Tentar enviar mensagem diretamente (sem wrapper)
echo "\n=== 3. TESTE ENVIO DIRETO (curl raw) ===\n";
$sendUrl = $baseUrl . '/message/sendText/' . urlencode($instanceName);
$payload = json_encode([
    'number' => $groupJid,
    'textMessage' => ['text' => 'Teste de sessão - ' . date('H:i:s')],
]);
echo "URL: $sendUrl\n";
echo "Payload: $payload\n\n";

$ch3 = curl_init($sendUrl);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    'apikey: ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch3, CURLOPT_TIMEOUT, 15);
$resp3 = curl_exec($ch3);
$code3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
curl_close($ch3);
echo "HTTP: $code3\n";
echo "Resposta: $resp3\n";

if ($code3 === 200 || $code3 === 201) {
    echo "\n✅ ENVIO FUNCIONOU!\n";
} else {
    echo "\n❌ ENVIO FALHOU\n";
    
    // 4. Verificar se o grupo está na lista de grupos da instância
    echo "\n=== 4. VERIFICAR SE GRUPO ESTÁ NA LISTA ===\n";
    $url4 = $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName);
    $ch4 = curl_init($url4);
    curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch4, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch4, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch4, CURLOPT_TIMEOUT, 15);
    $resp4 = curl_exec($ch4);
    $code4 = curl_getinfo($ch4, CURLINFO_HTTP_CODE);
    curl_close($ch4);
    
    if ($code4 === 200) {
        $allGroups = json_decode($resp4, true);
        $found = false;
        if (is_array($allGroups)) {
            foreach ($allGroups as $g) {
                if (($g['id'] ?? '') === $groupJid) {
                    $found = true;
                    echo "✅ Grupo encontrado na lista!\n";
                    echo "  Nome: " . ($g['subject'] ?? '?') . "\n";
                    echo "  Participantes: " . (isset($g['participants']) ? count($g['participants']) : '?') . "\n";
                    break;
                }
            }
            if (!$found) {
                echo "❌ Grupo NÃO encontrado na lista de grupos da instância!\n";
                echo "Total de grupos na instância: " . count($allGroups) . "\n";
                echo "Isso explica o erro 'No sessions' - a instância não reconhece este grupo.\n";
                echo "\nPossíveis causas:\n";
                echo "- O grupo foi criado por outra instância\n";
                echo "- A instância precisa ser reconectada (QR Code)\n";
                echo "- O número conectado não é membro deste grupo\n";
            }
        }
    }
}

echo "\n=== CONCLUSÃO ===\n";
echo "Se o grupo não está na lista da instância, o número conectado não é membro dele.\n";
echo "Verifique no WhatsApp (celular) se o número LRV Web está realmente no grupo.\n";

echo '</pre>';
echo '<br><a href="/chat_web.php?type=grupos">Voltar ao Chat</a>';
