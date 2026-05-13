<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Teste - Bloqueio de Notificação para Paciente com Óbito</h2><pre>';

// 1. Buscar paciente com status óbito
$stmt = db()->query("SELECT id, full_name, admin_status, deleted_at FROM patients WHERE admin_status LIKE '%bito%' OR admin_status LIKE '%Óbito%' OR admin_status LIKE '%obito%' LIMIT 5");
$patients = $stmt->fetchAll();

echo "=== PACIENTES COM STATUS ÓBITO ===\n";
if (empty($patients)) {
    echo "❌ Nenhum paciente com status óbito encontrado!\n";
    echo "Vá em Pacientes → Editar → Aba Administrativo → Status = Óbito\n";
    echo '</pre>';
    exit;
}

foreach ($patients as $p) {
    echo "  #" . $p['id'] . " | " . $p['full_name'] . " | status=" . $p['admin_status'] . "\n";
}

$testPatientId = (int)$patients[0]['id'];
$testPatientName = $patients[0]['full_name'];
echo "\nUsando paciente #$testPatientId ($testPatientName) para teste\n\n";

// 2. Testar notification_guard
echo "=== TESTE NOTIFICATION GUARD ===\n";
$guardResult = notification_guard_check_patient($testPatientId);
echo "Resultado: " . json_encode($guardResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

if (!$guardResult['allowed']) {
    echo "✅ CORRETO! Paciente BLOQUEADO: " . $guardResult['reason'] . "\n\n";
} else {
    echo "❌ ERRO! Paciente deveria estar bloqueado mas não está!\n\n";
}

// 3. Testar disparo do evento (deve ser bloqueado)
echo "=== TESTE DISPARO DE EVENTO (deve ser bloqueado) ===\n";
try {
    $dispatcher = new WhatsAppEventDispatcher();
    $result = $dispatcher->dispatch('attendance_assigned', [
        'professional_id' => 2,
        'professional_name' => 'lucas augusto',
        'professional_phone' => '5517988358367',
        'patient_id' => $testPatientId,
        'patient_name' => $testPatientName,
        'patient_phone' => '5511999999999',
        'attendance_id' => '9999',
        'attendance_date' => date('d/m/Y'),
    ]);
    echo "Resultado do dispatcher: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // Verificar se o envio para paciente foi bloqueado
    if (isset($result['results']['patient']['blocked']) && $result['results']['patient']['blocked']) {
        echo "✅ ENVIO PARA PACIENTE BLOQUEADO CORRETAMENTE!\n";
    } else {
        echo "ℹ️ Evento #1 (attendance_assigned) envia só para profissional, não para paciente.\n";
        echo "   O profissional recebeu normalmente (não é bloqueado por status do paciente).\n";
    }
    
    // Testar com evento que envia para paciente
    echo "\n=== TESTE COM EVENTO QUE ENVIA PARA PACIENTE ===\n";
    $result2 = $dispatcher->dispatch('preadmission_approved', [
        'professional_id' => 2,
        'professional_name' => 'lucas augusto',
        'professional_phone' => '5517988358367',
        'patient_id' => $testPatientId,
        'patient_name' => $testPatientName,
        'patient_phone' => '5511999999999',
        'attendance_id' => '9999',
        'attendance_date' => date('d/m/Y'),
        'id_preadmissao' => '9999',
        'data_aprovacao' => date('d/m/Y'),
    ]);
    echo "Resultado: " . json_encode($result2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    if (isset($result2['results']['patient']['blocked']) && $result2['results']['patient']['blocked']) {
        echo "✅ ENVIO PARA PACIENTE COM ÓBITO FOI BLOQUEADO!\n";
        echo "Motivo: " . ($result2['results']['patient']['error'] ?? '') . "\n";
    } else {
        echo "⚠️ Verificar resultado acima\n";
    }
    
} catch (Exception $e) {
    echo "❌ EXCEÇÃO: " . $e->getMessage() . "\n";
}

echo "\n=== CONCLUSÃO ===\n";
echo "Se os testes acima mostram ✅, o bloqueio por óbito está funcionando.\n";
echo "Mensagens NÃO serão enviadas para pacientes com status Óbito.\n";

echo '</pre>';
echo '<br><a href="/admin_settings.php">Voltar</a>';
