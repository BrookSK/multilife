<?php
/**
 * RESET DO SISTEMA PARA ENTREGA AO CLIENTE
 * 
 * Apaga TODOS os dados operacionais (pacientes, demandas, financeiro, chat, etc.)
 * Mantém: admin_settings, users, roles, permissions, especialidades, operadoras,
 *         centros de custo, templates, configurações de integração.
 * 
 * EXECUTAR UMA ÚNICA VEZ E DEPOIS DELETAR ESTE ARQUIVO!
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

// Segurança: precisa confirmar via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['confirm'] ?? '') !== 'ZERAR_TUDO') {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Reset Sistema</title></head><body style="font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px">';
    echo '<h1 style="color:red">⚠️ RESET COMPLETO DO SISTEMA</h1>';
    echo '<p><strong>Esta ação vai APAGAR PERMANENTEMENTE:</strong></p>';
    echo '<ul>';
    echo '<li>Todos os pacientes e prontuários</li>';
    echo '<li>Todas as demandas/captação</li>';
    echo '<li>Todos os agendamentos/atendimentos</li>';
    echo '<li>Todo o financeiro (entradas, contas, faturas)</li>';
    echo '<li>Todo o chat/mensagens WhatsApp</li>';
    echo '<li>Todas as notificações</li>';
    echo '<li>Todos os documentos</li>';
    echo '<li>Todos os profissionais cadastrados</li>';
    echo '<li>Candidaturas de profissionais</li>';
    echo '<li>RH (funcionários, contratos)</li>';
    echo '<li>Logs de auditoria e técnicos</li>';
    echo '<li>Jobs de integração</li>';
    echo '</ul>';
    echo '<p><strong>Será MANTIDO:</strong></p>';
    echo '<ul>';
    echo '<li>Configurações do sistema (admin_settings)</li>';
    echo '<li>Usuários e senhas</li>';
    echo '<li>Roles e permissões</li>';
    echo '<li>Especialidades e serviços</li>';
    echo '<li>Operadoras de saúde</li>';
    echo '<li>Centros de custo</li>';
    echo '<li>Templates de WhatsApp e e-mail</li>';
    echo '<li>Eventos de WhatsApp e e-mail</li>';
    echo '<li>Configurações ZapSign</li>';
    echo '</ul>';
    echo '<form method="post" onsubmit="return confirm(\'TEM CERTEZA ABSOLUTA? Isso é IRREVERSÍVEL!\')">';
    echo '<input type="hidden" name="confirm" value="ZERAR_TUDO">';
    echo '<button type="submit" style="background:red;color:white;padding:15px 30px;font-size:18px;border:none;border-radius:8px;cursor:pointer">🗑️ ZERAR TUDO AGORA</button>';
    echo '</form>';
    echo '<br><a href="/admin_settings.php">← Cancelar e voltar</a>';
    echo '</body></html>';
    exit;
}

// =============================================
// EXECUTAR RESET
// =============================================

$db = db();
$db->exec('SET FOREIGN_KEY_CHECKS = 0');

$tablesToTruncate = [
    // Pacientes - todos os dados relacionados
    'patients',
    'patient_assignments',
    'patient_documents',
    'patient_prontuario_entries',
    'patient_prontuarios',
    'patient_access_logs',
    'patient_frequency_changes',
    'patient_professional_substitutions',
    'patient_professionals',
    'patient_allergies',
    'patient_chronic_conditions',
    'patient_family_history',
    'patient_medications',
    'patient_medical_history',
    'patient_legal_guardians',
    
    // Demandas / Captação
    'demands',
    'demand_sub_requests',
    'demand_interested_professionals',
    'demand_dispatch_logs',
    'demand_status_logs',
    'authorization_requests',
    'authorization_request_history',
    
    // Profissionais - candidaturas e respostas
    'professional_applications',
    'professional_application_replies',
    'professional_documents',
    
    // Agendamentos
    'appointments',
    'appointment_value_authorizations',
    'appointment_patient_feedback',
    
    // Financeiro
    'financial_entries',
    'billing_invoices',
    'billing_document_requirements',
    'billing_document_files',
    
    // Chat / WhatsApp
    'chat_messages',
    'chat_contacts',
    'chat_conversations',
    'chat_groups',
    'chat_group_participants',
    'chat_reactions',
    'chat_capture_info',
    
    // WhatsApp grupos
    'whatsapp_groups',
    'whatsapp_instances',
    'whatsapp_event_logs',
    
    // Documentos
    'documents',
    'document_versions',
    
    // Notificações e Pendências
    'notifications',
    'pending_items',
    
    // Logs e Auditoria
    'audit_logs',
    'tech_logs',
    'integration_jobs',
    'council_validation_cache',
    'council_validation_logs',
    
    // E-mail
    'inbound_emails',
    'email_event_logs',
    
    // RH
    'hr_employees',
    'hr_employee_dependents',
    'hr_employee_history',
    'hr_employee_documents',
    'zapsign_contracts',
    
    // Monitoramento
    'pre_admissao_requests',
    
    // Tabelas de backup
    'patient_assignments_backup_antes_zerar',
    'financial_entries_backup_antes_zerar',
    'billing_invoices_backup_antes_zerar',
    'patient_assignments_backup_20260307',
    'chat_messages_backup_urls',
];

$results = [];
foreach ($tablesToTruncate as $table) {
    try {
        $db->exec("DELETE FROM `$table`");
        $db->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
        $results[] = "✅ $table — limpa";
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Base table')) {
            $results[] = "⏭️ $table — não existe (ignorada)";
        } else {
            $results[] = "❌ $table — erro: " . $e->getMessage();
        }
    }
}

$db->exec('SET FOREIGN_KEY_CHECKS = 1');

// Excluir operadoras desativadas
try {
    $delInactive = $db->prepare("DELETE FROM health_insurers WHERE is_active = 0");
    $delInactive->execute();
    $deletedInsurers = $delInactive->rowCount();
    if ($deletedInsurers > 0) {
        $results[] = "🗑️ health_insurers inativas — $deletedInsurers removidas";
    }
} catch (\PDOException $e) {
    $results[] = "⏭️ health_insurers inativas — erro: " . $e->getMessage();
}

// Limpar uploads (mídias de chat, documentos, etc.)
$uploadDirs = [
    __DIR__ . '/uploads/chat',
    __DIR__ . '/uploads/chat_media',
    __DIR__ . '/uploads/documents',
    __DIR__ . '/uploads/patients',
    __DIR__ . '/uploads/professional',
    __DIR__ . '/uploads/application_replies',
];

foreach ($uploadDirs as $dir) {
    if (is_dir($dir)) {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                unlink($item->getPathname());
                $count++;
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
        $results[] = "🗑️ $dir — $count arquivos removidos";
    }
}

// Resultado
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Reset Concluído</title></head><body style="font-family:sans-serif;max-width:800px;margin:40px auto;padding:20px">';
echo '<h1 style="color:green">✅ Sistema Resetado com Sucesso</h1>';
echo '<p>O sistema está limpo e pronto para entrega ao cliente.</p>';
echo '<pre style="background:#f5f5f5;padding:16px;border-radius:8px;overflow:auto;max-height:600px">';
foreach ($results as $r) {
    echo $r . "\n";
}
echo '</pre>';
echo '<br><p style="color:red;font-weight:bold">⚠️ IMPORTANTE: Delete este arquivo (reset_system_for_production.php) após usar!</p>';
echo '<a href="/admin_settings.php" style="display:inline-block;padding:12px 24px;background:#0ea5e9;color:white;text-decoration:none;border-radius:8px">Ir para Configurações</a>';
echo '</body></html>';
