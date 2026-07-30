<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

// Dashboard acessivel por qualquer usuario com role administrativa
// (nao restrito apenas a admin.dashboard, pois analistas e gerentes tambem precisam ver)
$uid = auth_user_id();
$canAccessDashboard = false;
if ($uid) {
    // Verificar por permissoes
    if (rbac_user_can($uid, 'admin.dashboard') || rbac_user_can($uid, 'reports.view')) {
        $canAccessDashboard = true;
    }
    // Verificar por roles - qualquer role administrativa tem acesso
    if (!$canAccessDashboard) {
        $userRolesForDash = rbac_user_roles($uid);
        $adminRolesForDash = ['admin', 'ti', 'analista', 'captador', 'financeiro', 'coordenador', 'gerente', 'gerente_rh', 'supervisor'];
        if (!empty(array_intersect($userRolesForDash, $adminRolesForDash))) {
            $canAccessDashboard = true;
        }
    }
}
if (!$canAccessDashboard) {
    header('Location: /forbidden.php');
    exit;
}

$db = db();

$metrics = [];

error_log("[DASHBOARD] Iniciando coleta de métricas...");

// Métricas de Demandas
try {
    $result = $db->query("SELECT COUNT(*) c FROM demands WHERE status IN ('aguardando_captacao','tratamento_manual','em_captacao')");
    if ($result) {
        $row = $result->fetch();
        $metrics['demands_open'] = (int)($row['c'] ?? 0);
        error_log("[DASHBOARD] Demandas abertas: " . $metrics['demands_open']);
    } else {
        $metrics['demands_open'] = 0;
    }
} catch (Exception $e) {
    error_log("[DASHBOARD] Erro em demands: " . $e->getMessage());
    $metrics['demands_open'] = 0;
}

// Função helper para executar queries com segurança
function getMetric($db, $query, $default = 0) {
    try {
        $result = $db->query($query);
        if ($result) {
            $row = $result->fetch(PDO::FETCH_ASSOC);
            return (int)($row['c'] ?? $default);
        }
        return $default;
    } catch (Exception $e) {
        error_log("[DASHBOARD] Erro na query: " . $e->getMessage());
        return $default;
    }
}

// Métricas de Documentação
$metrics['docs_submitted'] = getMetric($db, "SELECT COUNT(*) c FROM professional_documentations WHERE status = 'submitted'");

// Métricas de Agendamentos
$metrics['appointments_pending'] = getMetric($db, "SELECT COUNT(*) c FROM appointments WHERE status = 'pendente_formulario'");
$metrics['appointments_total'] = getMetric($db, "SELECT COUNT(*) c FROM appointments");

// Métricas Financeiras
$metrics['ar_pending'] = getMetric($db, "SELECT COUNT(*) c FROM finance_accounts_receivable WHERE status = 'pendente'");
$metrics['ap_pending'] = getMetric($db, "SELECT COUNT(*) c FROM finance_accounts_payable WHERE status = 'pendente'");

// Métricas de Usuários
$metrics['users_total'] = getMetric($db, "SELECT COUNT(*) c FROM users");
$metrics['users_active'] = getMetric($db, "SELECT COUNT(*) c FROM users WHERE status = 'active'");

// Métricas de Profissionais
$metrics['professionals_total'] = getMetric($db, "SELECT COUNT(*) c FROM users WHERE role = 'professional'");

// Métricas de Pacientes
$metrics['patients_total'] = getMetric($db, "SELECT COUNT(*) c FROM patients");

// Métricas de Chat
$metrics['chat_contacts'] = getMetric($db, "SELECT COUNT(*) c FROM chat_contacts");
$metrics['chat_messages'] = getMetric($db, "SELECT COUNT(*) c FROM chat_messages");
$metrics['chat_unread'] = getMetric($db, "SELECT COUNT(*) c FROM chat_messages WHERE from_me = 0 AND is_read = 0");

// Métricas de Eventos WhatsApp
$metrics['whatsapp_events'] = getMetric($db, "SELECT COUNT(*) c FROM whatsapp_events WHERE status = 'active'");
$metrics['whatsapp_sent'] = getMetric($db, "SELECT COUNT(*) c FROM whatsapp_event_logs WHERE status = 'sent'");

// Métricas de Eventos Email
$metrics['email_events'] = getMetric($db, "SELECT COUNT(*) c FROM email_events WHERE status = 'active'");
$metrics['email_sent'] = getMetric($db, "SELECT COUNT(*) c FROM email_event_logs WHERE status = 'sent'");

error_log("[DASHBOARD] Métricas coletadas: " . json_encode($metrics));

view_header('Admin - Dashboard');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Dashboard Administrativo</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Indicadores operacionais e atalhos do Admin.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Configurações</a>';
echo '<a class="btn" href="/audit_logs_list.php">Auditoria</a>';
echo '<a class="btn" href="/patient_access_logs_list.php">Acessos a prontuário</a>';
echo '<a class="btn" href="/hr_employees_list.php">RH</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Organizar cards por categoria
$cardSections = [
    'Pendências' => [
        ['Demandas em aberto', $metrics['demands_open'], '/demands_list.php', 'warning'],
        ['Docs pendentes de revisão', $metrics['docs_submitted'], '/professional_docs_review_list.php', 'warning'],
        ['Agendamentos pendentes', $metrics['appointments_pending'], '/appointments_list.php?status=pendente_formulario', 'warning'],
        ['Contas a receber pendentes', $metrics['ar_pending'], '/finance_receivable_list.php?status=pendente', 'danger'],
        ['Repasses pendentes', $metrics['ap_pending'], '/finance_payable_list.php?status=pendente', 'danger'],
    ],
    'Usuários e Cadastros' => [
        ['Total de usuários', $metrics['users_total'], '/users_list.php', 'info'],
        ['Usuários ativos', $metrics['users_active'], '/users_list.php?status=active', 'success'],
        ['Profissionais', $metrics['professionals_total'], '/professionals_list.php', 'primary'],
        ['Pacientes', $metrics['patients_total'], '/patients_list.php', 'primary'],
    ],
    'Chat WhatsApp' => [
        ['Contatos no chat', $metrics['chat_contacts'], '/chat_web.php', 'info'],
        ['Total de mensagens', $metrics['chat_messages'], '/chat_web.php', 'info'],
        ['Mensagens não lidas', $metrics['chat_unread'], '/chat_web.php?type=atendendo', 'warning'],
    ],
    'Automações' => [
        ['Eventos WhatsApp ativos', $metrics['whatsapp_events'], '/admin_whatsapp_events.php', 'success'],
        ['WhatsApp enviados', $metrics['whatsapp_sent'], '/admin_whatsapp_events.php', 'info'],
        ['Eventos E-mail ativos', $metrics['email_events'], '/admin_email_events.php', 'success'],
        ['E-mails enviados', $metrics['email_sent'], '/admin_email_events.php', 'info'],
    ],
];

foreach ($cardSections as $sectionTitle => $cards) {
    echo '<section class="card col12">';
    echo '<div class="cardTitle">' . h($sectionTitle) . '</div>';
    echo '<div class="grid">';
    
    foreach ($cards as $c) {
        $colorClass = match($c[3] ?? 'info') {
            'danger' => 'color:hsl(var(--destructive))',
            'warning' => 'color:hsl(var(--warning))',
            'success' => 'color:hsl(var(--success))',
            'primary' => 'color:hsl(var(--primary))',
            default => 'color:hsl(var(--muted-foreground))'
        };
        
        echo '<div class="col3">';
        echo '<div class="pill" style="display:block;padding:16px;text-align:center">';
        echo '<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;' . $colorClass . '">' . h((string)$c[0]) . '</div>';
        echo '<div style="font-size:32px;font-weight:900;margin:8px 0;' . $colorClass . '">' . h((string)$c[1]) . '</div>';
        if (!empty($c[2])) {
            echo '<div style="margin-top:12px"><a class="btn btnSmall" href="' . h((string)$c[2]) . '">Ver</a></div>';
        }
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</section>';
}

echo '</div>';

view_footer();
