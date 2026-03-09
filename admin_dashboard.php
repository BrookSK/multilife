<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.dashboard');

$db = db();

$metrics = [];

// Métricas de Demandas
try {
    $metrics['demands_open'] = (int)$db->query("SELECT COUNT(*) c FROM demands WHERE status IN ('aguardando_captacao','tratamento_manual','em_captacao')")->fetch()['c'];
} catch (Exception $e) {
    $metrics['demands_open'] = 0;
}

// Métricas de Documentação
try {
    $metrics['docs_submitted'] = (int)$db->query("SELECT COUNT(*) c FROM professional_documentations WHERE status = 'submitted'")->fetch()['c'];
} catch (Exception $e) {
    $metrics['docs_submitted'] = 0;
}

// Métricas de Agendamentos
try {
    $metrics['appointments_pending'] = (int)$db->query("SELECT COUNT(*) c FROM appointments WHERE status = 'pendente_formulario'")->fetch()['c'];
    $metrics['appointments_total'] = (int)$db->query("SELECT COUNT(*) c FROM appointments")->fetch()['c'];
} catch (Exception $e) {
    $metrics['appointments_pending'] = 0;
    $metrics['appointments_total'] = 0;
}

// Métricas Financeiras
try {
    $metrics['ar_pending'] = (int)$db->query("SELECT COUNT(*) c FROM finance_accounts_receivable WHERE status = 'pendente'")->fetch()['c'];
} catch (Exception $e) {
    $metrics['ar_pending'] = 0;
}

try {
    $metrics['ap_pending'] = (int)$db->query("SELECT COUNT(*) c FROM finance_accounts_payable WHERE status = 'pendente'")->fetch()['c'];
} catch (Exception $e) {
    $metrics['ap_pending'] = 0;
}

// Métricas de Usuários
try {
    $metrics['users_total'] = (int)$db->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
    $metrics['users_active'] = (int)$db->query("SELECT COUNT(*) c FROM users WHERE status = 'active'")->fetch()['c'];
} catch (Exception $e) {
    $metrics['users_total'] = 0;
    $metrics['users_active'] = 0;
}

// Métricas de Profissionais
try {
    $metrics['professionals_total'] = (int)$db->query("SELECT COUNT(*) c FROM users WHERE role = 'professional'")->fetch()['c'];
} catch (Exception $e) {
    $metrics['professionals_total'] = 0;
}

// Métricas de Pacientes
try {
    $metrics['patients_total'] = (int)$db->query("SELECT COUNT(*) c FROM patients")->fetch()['c'];
} catch (Exception $e) {
    $metrics['patients_total'] = 0;
}

// Métricas de Chat
try {
    $metrics['chat_contacts'] = (int)$db->query("SELECT COUNT(*) c FROM chat_contacts")->fetch()['c'];
    $metrics['chat_messages'] = (int)$db->query("SELECT COUNT(*) c FROM chat_messages")->fetch()['c'];
    $metrics['chat_unread'] = (int)$db->query("SELECT COUNT(*) c FROM chat_messages WHERE from_me = 0 AND is_read = 0")->fetch()['c'];
} catch (Exception $e) {
    $metrics['chat_contacts'] = 0;
    $metrics['chat_messages'] = 0;
    $metrics['chat_unread'] = 0;
}

// Métricas de Eventos WhatsApp
try {
    $metrics['whatsapp_events'] = (int)$db->query("SELECT COUNT(*) c FROM whatsapp_events WHERE status = 'active'")->fetch()['c'];
    $metrics['whatsapp_sent'] = (int)$db->query("SELECT COUNT(*) c FROM whatsapp_event_logs WHERE status = 'sent'")->fetch()['c'];
} catch (Exception $e) {
    $metrics['whatsapp_events'] = 0;
    $metrics['whatsapp_sent'] = 0;
}

// Métricas de Eventos Email
try {
    $metrics['email_events'] = (int)$db->query("SELECT COUNT(*) c FROM email_events WHERE status = 'active'")->fetch()['c'];
    $metrics['email_sent'] = (int)$db->query("SELECT COUNT(*) c FROM email_event_logs WHERE status = 'sent'")->fetch()['c'];
} catch (Exception $e) {
    $metrics['email_events'] = 0;
    $metrics['email_sent'] = 0;
}

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
