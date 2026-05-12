<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Debug - Disparo de Evento WhatsApp</h2><pre>';

// 1. Verificar se o evento existe no banco
echo "=== EVENTOS CONFIGURADOS ===\n";
$stmt = db()->query("SELECT id, name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient FROM whatsapp_events ORDER BY id");
$events = $stmt->fetchAll();

if (empty($events)) {
    echo "❌ NENHUM EVENTO CONFIGURADO!\n";
    echo "Vá em Configurações → WhatsApp → Criar Evento\n";
} else {
    foreach ($events as $evt) {
        echo "  #" . $evt['id'] . " | " . $evt['system_event'] . " | status=" . $evt['status'];
        echo " | prof=" . ($evt['send_to_professional'] ? 'SIM' : 'NÃO');
        echo " | pac=" . ($evt['send_to_patient'] ? 'SIM' : 'NÃO');
        echo " | template_prof=" . (empty($evt['template_professional']) ? 'VAZIO!' : substr($evt['template_professional'], 0, 50) . '...');
        echo "\n";
    }
}

// 2. Verificar profissional lucas augusto
echo "\n=== PROFISSIONAL (lucas augusto) ===\n";
$stmt = db()->prepare("SELECT id, name, email, phone, status FROM users WHERE name LIKE '%lucas%' OR email LIKE '%augusto%' LIMIT 5");
$stmt->execute();
$users = $stmt->fetchAll();
foreach ($users as $u) {
    echo "  #" . $u['id'] . " | " . $u['name'] . " | phone=" . ($u['phone'] ?? 'NULL') . " | status=" . $u['status'] . "\n";
}

// 3. Tentar disparar o evento manualmente
echo "\n=== TESTE DE DISPARO MANUAL ===\n";

$testPhone = '5517988358367'; // telefone do lucas augusto que vi antes
echo "Telefone de teste: $testPhone\n";

// Verificar se Evolution API está configurada
$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');
echo "Evolution: base=$baseUrl | instance=$instanceName | key=" . substr($apiKey, 0, 8) . "...\n\n";

// Tentar enviar mensagem direta via API
echo "=== TESTE ENVIO DIRETO VIA EVOLUTION API ===\n";
try {
    $api = new EvolutionApiV1();
    $testMsg = "🧪 TESTE DE EVENTO - " . date('H:i:s') . "\nSe você recebeu esta mensagem, o envio via Evolution API está funcionando.";
    echo "Enviando para: $testPhone\n";
    echo "Mensagem: $testMsg\n\n";
    
    $res = $api->sendText($testPhone, $testMsg);
    $httpCode = (int)($res['status'] ?? 0);
    echo "HTTP: $httpCode\n";
    echo "Resposta: " . json_encode($res['json'] ?? $res['body_raw'] ?? 'null', JSON_PRETTY_PRINT) . "\n";
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "\n✅ ENVIO DIRETO FUNCIONOU!\n";
    } else {
        echo "\n❌ ENVIO DIRETO FALHOU!\n";
    }
} catch (Exception $e) {
    echo "❌ EXCEÇÃO: " . $e->getMessage() . "\n";
}

// 4. Tentar disparar via WhatsAppEventDispatcher
echo "\n=== TESTE VIA DISPATCHER ===\n";
try {
    $dispatcher = new WhatsAppEventDispatcher();
    $result = $dispatcher->dispatch('attendance_assigned', [
        'professional_id' => $users[0]['id'] ?? 0,
        'professional_name' => 'lucas augusto',
        'professional_phone' => $testPhone,
        'patient_id' => 0,
        'patient_name' => 'Paciente Teste',
        'patient_phone' => '',
        'attendance_id' => '999',
        'attendance_date' => date('d/m/Y'),
    ]);
    echo "Resultado: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "❌ EXCEÇÃO NO DISPATCHER: " . $e->getMessage() . "\n";
    echo "Stack: " . $e->getTraceAsString() . "\n";
}

echo '</pre>';
echo '<br><a href="/admin_settings.php">Voltar</a>';
