<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// Pendências são visíveis para todos os usuários logados

// ============================================
// PENDÊNCIAS FINANCEIRAS
// ============================================

// 1. Contas a Pagar em Atraso
$payablesOverdue = db()->query("
    SELECT 
        id, 
        description, 
        amount, 
        due_date,
        DATEDIFF(CURDATE(), due_date) as days_overdue
    FROM financial_entries 
    WHERE entry_type = 'expense' 
    AND status = 'pending' 
    AND due_date < CURDATE()
    ORDER BY due_date ASC
    LIMIT 50
")->fetchAll();

// 2. Contas a Receber em Atraso
$receivablesOverdue = db()->query("
    SELECT 
        id, 
        description, 
        amount, 
        due_date,
        DATEDIFF(CURDATE(), due_date) as days_overdue
    FROM financial_entries 
    WHERE entry_type = 'income' 
    AND status = 'pending' 
    AND due_date < CURDATE()
    ORDER BY due_date ASC
    LIMIT 50
")->fetchAll();

// ============================================
// PENDÊNCIAS OPERACIONAIS
// ============================================

// 3. Documentos de Profissionais Atrasados
// Temporariamente desabilitado até configurar corretamente a estrutura de documentos
$documentsOverdue = [];

// 4. Atendimentos Parados >24h nas Etapas Críticas
$appointmentsStuck = db()->query("
    SELECT 
        a.id,
        p.full_name as patient_name,
        a.status,
        a.updated_at,
        TIMESTAMPDIFF(HOUR, a.updated_at, NOW()) as hours_stuck,
        d.id as demand_id
    FROM appointments a
    INNER JOIN patients p ON p.id = a.patient_id
    LEFT JOIN demands d ON d.id = a.demand_id
    WHERE a.status IN ('agendado', 'pendente_formulario', 'revisao_admin')
    AND TIMESTAMPDIFF(HOUR, a.updated_at, NOW()) > 24
    ORDER BY a.updated_at ASC
    LIMIT 50
")->fetchAll();

// 5. Cards de Pré-admissão Aguardando Aprovação
$preAdmissionPending = db()->query("
    SELECT 
        pa.id,
        pa.demand_id,
        pa.created_at,
        d.title as demand_title,
        p.full_name as patient_name,
        u.name as professional_name,
        pa.specialty,
        DATEDIFF(CURDATE(), pa.created_at) as days_pending
    FROM patient_assignments pa
    INNER JOIN demands d ON d.id = pa.demand_id
    INNER JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    WHERE pa.status = 'confirmed'
    AND pa.approved_at IS NULL
    ORDER BY pa.created_at ASC
    LIMIT 50
")->fetchAll();

view_header('Pendências');

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Pendências do Sistema</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Itens em atraso que requerem atenção imediata.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">← Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// ============================================
// BLOCO 1: PENDÊNCIAS FINANCEIRAS
// ============================================
echo '<section class="card col12">';
echo '<h2 style="font-size:18px;font-weight:700;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid hsl(var(--border))">Pendências Financeiras</h2>';

// Contas a Pagar em Atraso
echo '<div style="margin-bottom:24px">';
echo '<h3 style="font-size:16px;font-weight:600;margin-bottom:12px;color:#ef4444">Contas a Pagar em Atraso (' . count($payablesOverdue) . ')</h3>';

if (count($payablesOverdue) > 0) {
    echo '<div style="display:flex;flex-direction:column;gap:8px">';
    foreach ($payablesOverdue as $item) {
        $daysOverdue = (int)$item['days_overdue'];
        $urgencyColor = $daysOverdue > 30 ? '#dc2626' : ($daysOverdue > 7 ? '#f59e0b' : '#ef4444');
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:hsl(var(--muted));border-radius:8px;border-left:4px solid ' . $urgencyColor . '">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:600">' . h((string)$item['description']) . '</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">';
        echo 'Vencimento: ' . date('d/m/Y', strtotime((string)$item['due_date'])) . ' • ';
        echo '<span style="color:' . $urgencyColor . ';font-weight:600">' . $daysOverdue . ' dias em atraso</span>';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;align-items:center;gap:12px">';
        echo '<div style="font-size:16px;font-weight:700;color:#ef4444">R$ ' . number_format((float)$item['amount'], 2, ',', '.') . '</div>';
        echo '<a class="btn" href="/finance_payable_list.php" style="font-size:12px;padding:6px 12px">Ver Detalhes</a>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
} else {
    echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma conta a pagar em atraso</div>';
}
echo '</div>';

// Contas a Receber em Atraso
echo '<div>';
echo '<h3 style="font-size:16px;font-weight:600;margin-bottom:12px;color:#f59e0b">Contas a Receber em Atraso (' . count($receivablesOverdue) . ')</h3>';

if (count($receivablesOverdue) > 0) {
    echo '<div style="display:flex;flex-direction:column;gap:8px">';
    foreach ($receivablesOverdue as $item) {
        $daysOverdue = (int)$item['days_overdue'];
        $urgencyColor = $daysOverdue > 30 ? '#dc2626' : ($daysOverdue > 7 ? '#f59e0b' : '#fbbf24');
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:hsl(var(--muted));border-radius:8px;border-left:4px solid ' . $urgencyColor . '">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:600">' . h((string)$item['description']) . '</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">';
        echo 'Vencimento: ' . date('d/m/Y', strtotime((string)$item['due_date'])) . ' • ';
        echo '<span style="color:' . $urgencyColor . ';font-weight:600">' . $daysOverdue . ' dias em atraso</span>';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;align-items:center;gap:12px">';
        echo '<div style="font-size:16px;font-weight:700;color:#f59e0b">R$ ' . number_format((float)$item['amount'], 2, ',', '.') . '</div>';
        echo '<a class="btn" href="/finance_receivable_list.php" style="font-size:12px;padding:6px 12px">Ver Detalhes</a>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
} else {
    echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma conta a receber em atraso</div>';
}
echo '</div>';

echo '</section>';

// ============================================
// BLOCO 2: PENDÊNCIAS OPERACIONAIS
// ============================================
echo '<section class="card col12">';
echo '<h2 style="font-size:18px;font-weight:700;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid hsl(var(--border))">Pendências Operacionais</h2>';

// Documentos de Profissionais Atrasados
echo '<div style="margin-bottom:24px">';
echo '<h3 style="font-size:16px;font-weight:600;margin-bottom:12px;color:#8b5cf6">Documentos de Profissionais Não Recebidos (' . count($documentsOverdue) . ')</h3>';

if (count($documentsOverdue) > 0) {
    echo '<div style="display:flex;flex-direction:column;gap:8px">';
    foreach ($documentsOverdue as $item) {
        $daysSince = (int)$item['days_since_creation'];
        $urgencyColor = $daysSince > 30 ? '#dc2626' : ($daysSince > 14 ? '#f59e0b' : '#8b5cf6');
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:hsl(var(--muted));border-radius:8px;border-left:4px solid ' . $urgencyColor . '">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:600">' . h((string)$item['name']) . '</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">';
        echo 'E-mail: ' . h((string)$item['email']) . ' • ';
        echo 'Cadastrado há: <span style="color:' . $urgencyColor . ';font-weight:600">' . $daysSince . ' dias</span> • ';
        echo 'Prazo: ' . $docDeadlineDays . ' dias';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        echo '<a class="btn" href="/documents_list.php?user_id=' . (int)$item['user_id'] . '" style="font-size:12px;padding:6px 12px">Ver Documentos</a>';
        echo '<a class="btn" href="/users_edit.php?id=' . (int)$item['user_id'] . '" style="font-size:12px;padding:6px 12px">Ver Perfil</a>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
} else {
    echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px;text-align:center;color:hsl(var(--muted-foreground))">Todos os profissionais enviaram documentos no prazo</div>';
}
echo '</div>';

// Atendimentos Parados >24h
echo '<div style="margin-bottom:24px">';
echo '<h3 style="font-size:16px;font-weight:600;margin-bottom:12px;color:#3b82f6">Atendimentos Parados há Mais de 24h (' . count($appointmentsStuck) . ')</h3>';

if (count($appointmentsStuck) > 0) {
    echo '<div style="display:flex;flex-direction:column;gap:8px">';
    foreach ($appointmentsStuck as $item) {
        $hoursStuck = (int)$item['hours_stuck'];
        $daysStuck = floor($hoursStuck / 24);
        $urgencyColor = $hoursStuck > 72 ? '#dc2626' : ($hoursStuck > 48 ? '#f59e0b' : '#3b82f6');
        
        $statusLabels = [
            'captacao' => 'Captação',
            'aguardando_email' => 'Aguardando E-mail',
            'tratamento_manual' => 'Tratamento Manual'
        ];
        $statusLabel = $statusLabels[$item['status']] ?? $item['status'];
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:hsl(var(--muted));border-radius:8px;border-left:4px solid ' . $urgencyColor . '">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:600">' . h((string)$item['patient_name']) . '</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">';
        echo 'Etapa: <span style="font-weight:600">' . $statusLabel . '</span> • ';
        echo 'Parado há: <span style="color:' . $urgencyColor . ';font-weight:600">' . $daysStuck . ' dias e ' . ($hoursStuck % 24) . ' horas</span>';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        if ($item['demand_id']) {
            echo '<a class="btn" href="/demands_view.php?id=' . (int)$item['demand_id'] . '" style="font-size:12px;padding:6px 12px">Ver Demanda</a>';
        }
        echo '<a class="btn btnPrimary" href="/appointments_view.php?id=' . (int)$item['id'] . '" style="font-size:12px;padding:6px 12px">Resolver</a>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
} else {
    echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum atendimento parado há mais de 24h</div>';
}
echo '</div>';

// Cards de Pré-admissão Aguardando Aprovação
echo '<div>';
echo '<h3 style="font-size:16px;font-weight:600;margin-bottom:12px;color:#10b981">Pré-admissão Aguardando Aprovação (' . count($preAdmissionPending) . ')</h3>';

if (count($preAdmissionPending) > 0) {
    echo '<div style="display:flex;flex-direction:column;gap:8px">';
    foreach ($preAdmissionPending as $item) {
        $daysPending = (int)$item['days_pending'];
        $urgencyColor = $daysPending > 3 ? '#dc2626' : ($daysPending > 1 ? '#f59e0b' : '#10b981');
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:hsl(var(--muted));border-radius:8px;border-left:4px solid ' . $urgencyColor . '">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:600">' . h((string)$item['demand_title']) . '</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">';
        echo 'Paciente: <span style="font-weight:600">' . h((string)$item['patient_name']) . '</span> • ';
        echo 'Profissional: <span style="font-weight:600">' . h((string)($item['professional_name'] ?? 'Não atribuído')) . '</span> • ';
        echo 'Especialidade: ' . h((string)$item['specialty']) . ' • ';
        echo 'Aguardando há: <span style="color:' . $urgencyColor . ';font-weight:600">' . $daysPending . ' dia' . ($daysPending !== 1 ? 's' : '') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        echo '<a class="btn" href="/pre_admissao.php?id=' . (int)$item['id'] . '" style="font-size:12px;padding:6px 12px;background:#10b981;color:#fff">Aprovar</a>';
        echo '<a class="btn" href="/demands_view.php?id=' . (int)$item['demand_id'] . '" style="font-size:12px;padding:6px 12px">Ver Card</a>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
} else {
    echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum atendimento aguardando aprovação</div>';
}
echo '</div>';

echo '</section>';

echo '</div>';

view_footer();
