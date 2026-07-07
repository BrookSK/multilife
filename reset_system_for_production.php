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
    // Pacientes
    'patients',
    'patient_assignments',
    'patient_documents',
    'patient_prontuarios',
    'patient_access_logs',
    'patient_frequency_changes',
    'patient_professional_substitutions',
    
    // Demandas / Captação
    'demands',
    'demand_interested_professionals',
    'authorization_requests',
    'authorization_request_history',
    
    // Profissionais
    'professional_applications',
    'professional_documents',
    
    // Agendamentos
    'appointments',
    'appointment_value_authorizations',
    
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
    
    // Documentos
    'documents',
    'document_versions',
    
    // Notificações
    'notifications',
    
    // Pendências
    'pending_items',
    
    // Logs
    'audit_logs',
    'tech_logs',
    'integration_jobs',
    'council_validation_cache',
    'council_validation_logs',
    
    // E-mail recebido
    'inbound_emails',
    
    // WhatsApp logs de envio
    'whatsapp_event_logs',
    'email_event_logs',
    
    // WhatsApp instances tracking
    'whatsapp_instances',
    
    // WhatsApp grupos
    'whatsapp_groups',
    'chat_groups',
    'chat_group_participants',
    'demand_dispatch_logs',
    
    // RH
    'hr_employees',
    'hr_employee_dependents',
    'hr_employee_history',
    'hr_employee_documents',
    'zapsign_contracts',
    
    // Monitoramento
    'pre_admissao_requests',
    
    // Tabelas de backup de testes anteriores
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

// Limpar uploads (mídias de chat, documentos, etc.)
$uploadDirs = [
    __DIR__ . '/uploads/chat',
    __DIR__ . '/uploads/documents',
    __DIR__ . '/uploads/patients',
    __DIR__ . '/uploads/professional',
];

foreach ($uploadDirs as $dir) {
    if (is_dir($dir)) {
        $files = glob($dir . '/*');
        $count = 0;
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $count++;
                }
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
