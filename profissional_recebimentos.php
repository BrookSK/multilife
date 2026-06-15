<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();

// Buscar estatísticas de recebimentos
// Usar COALESCE para pegar o valor correto (agreed_value pode ser NULL)
$statsStmt = db()->prepare("
    SELECT 
        COUNT(DISTINCT pa.id) as total_atendimentos,
        SUM(COALESCE(pa.agreed_value, pa.payment_value, 0) * pa.session_quantity) as total_servicos,
        (SELECT COUNT(*) FROM billing_document_requirements bdr 
         INNER JOIN patient_assignments pa2 ON pa2.id = bdr.assignment_id
         WHERE pa2.professional_user_id = ? AND bdr.status IN ('approved', 'paid')) as sessoes_aprovadas,
        (SELECT COUNT(*) FROM billing_document_requirements bdr 
         INNER JOIN patient_assignments pa2 ON pa2.id = bdr.assignment_id
         WHERE pa2.professional_user_id = ? AND bdr.status = 'pending') as sessoes_pendentes
    FROM patient_assignments pa
    WHERE pa.professional_user_id = ?
    AND pa.status IN ('admitted', 'confirmed', 'approved', 'awaiting_documents', 'awaiting_financial_approval', 'completed')
");
$statsStmt->execute([$userId, $userId, $userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Calcular totais baseado nas sessões individuais (não no atendimento inteiro)
$valorPorSessaoStmt = db()->prepare("
    SELECT 
        COALESCE(SUM(COALESCE(pa.agreed_value, pa.payment_value, 0)), 0) as total_valor_sessao,
        SUM(pa.session_quantity) as total_sessoes
    FROM patient_assignments pa
    WHERE pa.professional_user_id = ?
    AND pa.status IN ('admitted', 'confirmed', 'approved', 'awaiting_documents', 'awaiting_financial_approval', 'completed')
");
$valorPorSessaoStmt->execute([$userId]);
$valorData = $valorPorSessaoStmt->fetch(PDO::FETCH_ASSOC);

$sessoesAprovadas = (int)($stats['sessoes_aprovadas'] ?? 0);
$sessoesPendentes = (int)($stats['sessoes_pendentes'] ?? 0);

// Buscar valor real por sessão de cada atendimento
$recebiveisStmt = db()->prepare("
    SELECT 
        SUM(COALESCE(pa.agreed_value, pa.payment_value, 0)) as total_por_sessao_aprovada
    FROM patient_assignments pa
    INNER JOIN billing_document_requirements bdr ON bdr.assignment_id = pa.id
    WHERE pa.professional_user_id = ? AND bdr.status IN ('approved', 'paid')
");
$recebiveisStmt->execute([$userId]);
$recebiveisRow = $recebiveisStmt->fetch(PDO::FETCH_ASSOC);

$pagosStmt = db()->prepare("
    SELECT 
        SUM(COALESCE(pa.agreed_value, pa.payment_value, 0)) as total_pago
    FROM patient_assignments pa
    INNER JOIN billing_document_requirements bdr ON bdr.assignment_id = pa.id
    WHERE pa.professional_user_id = ? AND bdr.status = 'paid'
");
$pagosStmt->execute([$userId]);
$pagosRow = $pagosStmt->fetch(PDO::FETCH_ASSOC);

$totalAtendimentos = (int)($stats['total_atendimentos'] ?? 0);
$totalServicos = (float)($stats['total_servicos'] ?? 0);
$totalPendente = (float)($recebiveisRow['total_por_sessao_aprovada'] ?? 0) - (float)($pagosRow['total_pago'] ?? 0);
$totalPago = (float)($pagosRow['total_pago'] ?? 0);

// Buscar histórico de pagamentos (sessões aprovadas/pagas)
$paymentsStmt = db()->prepare("
    SELECT 
        pa.id,
        pa.patient_id,
        pa.specialty,
        pa.service_type,
        bdr.session_number,
        bdr.session_date,
        bdr.status as doc_status,
        bdr.created_at as approved_at,
        COALESCE(pa.agreed_value, pa.payment_value, 0) as valor_sessao,
        p.full_name as patient_name
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE pa.professional_user_id = ?
    AND bdr.status IN ('approved', 'paid')
    ORDER BY bdr.created_at DESC, bdr.session_date DESC
    LIMIT 50
");
$paymentsStmt->execute([$userId]);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar atendimentos pendentes de pagamento
$pendingStmt = db()->prepare("
    SELECT 
        pa.id,
        pa.patient_id,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        COALESCE(pa.agreed_value, pa.payment_value, 0) as agreed_value,
        pa.status,
        pa.created_at,
        p.full_name as patient_name,
        (SELECT COUNT(*) FROM billing_document_requirements bdr 
         WHERE bdr.assignment_id = pa.id AND bdr.status = 'pending') as pending_docs,
        (SELECT COUNT(*) FROM billing_document_requirements bdr 
         WHERE bdr.assignment_id = pa.id AND bdr.status IN ('approved', 'paid')) as approved_docs
    FROM patient_assignments pa
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE pa.professional_user_id = ?
    AND pa.status IN ('admitted', 'confirmed', 'awaiting_documents', 'awaiting_financial_approval', 'approved')
    ORDER BY pa.created_at DESC
");
$pendingStmt->execute([$userId]);
$pendingPayments = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$totalAtendimentos = (int)($stats['total_atendimentos'] ?? 0);
$totalServicos = (float)($stats['total_servicos'] ?? 0);
$totalPendente = (float)($stats['total_pendente'] ?? 0);
$totalPago = (float)($stats['total_pago'] ?? 0);

view_header('Recebimentos');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Meus Recebimentos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Acompanhe seus atendimentos e pagamentos</div>';
echo '</div>';
echo '</div>';
echo '</section>';

// Cards de Resumo
echo '<section class="card col12">';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">';

// Card 1: Número de Atendimentos
echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px">';
echo '<div style="width:40px;height:40px;border-radius:10px;background:hsla(var(--primary)/.1);display:flex;align-items:center;justify-content:center">';
echo '<svg style="width:20px;height:20px;color:hsl(var(--primary))" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
echo '</div>';
echo '</div>';
echo '<div style="font-size:13px;font-weight:600;color:hsl(var(--muted-foreground));margin-bottom:6px">Número de Atendimentos</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--foreground));line-height:1">' . $totalAtendimentos . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:8px">Total de atendimentos realizados</div>';
echo '</div>';

// Card 2: Total em Serviços
echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px">';
echo '<div style="width:40px;height:40px;border-radius:10px;background:hsla(var(--info)/.1);display:flex;align-items:center;justify-content:center">';
echo '<svg style="width:20px;height:20px;color:hsl(var(--info))" fill="currentColor" viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>';
echo '</div>';
echo '</div>';
echo '<div style="font-size:13px;font-weight:600;color:hsl(var(--muted-foreground));margin-bottom:6px">Total em Serviços</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--foreground));line-height:1">R$ ' . number_format($totalServicos, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:8px">Valor total de todos os serviços</div>';
echo '</div>';

// Card 3: Total Pendente
echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px">';
echo '<div style="width:40px;height:40px;border-radius:10px;background:hsla(var(--warning)/.1);display:flex;align-items:center;justify-content:center">';
echo '<svg style="width:20px;height:20px;color:hsl(var(--warning))" fill="currentColor" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>';
echo '</div>';
echo '</div>';
echo '<div style="font-size:13px;font-weight:600;color:hsl(var(--muted-foreground));margin-bottom:6px">Total Pendente</div>';
echo '<div style="font-size:32px;font-weight:900;color:hsl(var(--warning));line-height:1">R$ ' . number_format($totalPendente, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:8px">Aguardando aprovação e pagamento</div>';
echo '</div>';

// Card 4: Total Pago
echo '<div style="padding:20px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) + 4px);box-shadow:var(--shadow-card)">';
echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px">';
echo '<div style="width:40px;height:40px;border-radius:10px;background:hsla(142 76% 36%/.1);display:flex;align-items:center;justify-content:center">';
echo '<svg style="width:20px;height:20px;color:#10b981" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>';
echo '</div>';
echo '</div>';
echo '<div style="font-size:13px;font-weight:600;color:hsl(var(--muted-foreground));margin-bottom:6px">Total Pago</div>';
echo '<div style="font-size:32px;font-weight:900;color:#10b981;line-height:1">R$ ' . number_format($totalPago, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:8px">Histórico de pagamentos recebidos</div>';
echo '</div>';

echo '</div>';
echo '</section>';

// Atendimentos Pendentes de Pagamento
echo '<section class="card col12">';
echo '<h3>Atendimentos Pendentes de Pagamento</h3>';

if (count($pendingPayments) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">';
    echo '<svg style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum atendimento pendente</div>';
    echo '<div style="font-size:14px">Todos os seus atendimentos foram pagos!</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Especialidade</th><th>Sessões</th><th>Valor/Sessão</th><th>Total</th><th>Status</th><th>Docs Pendentes</th><th>Data</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($pendingPayments as $pending) {
        $totalValue = (float)$pending['agreed_value'] * (int)$pending['session_quantity'];
        
        $statusColors = [
            'admitted' => '#f59e0b',
            'awaiting_financial_approval' => '#0284c7',
            'approved' => '#10b981'
        ];
        $statusLabels = [
            'admitted' => 'Aguardando Docs',
            'awaiting_financial_approval' => 'Aguardando Aprovação',
            'approved' => 'Aprovado'
        ];
        $statusColor = $statusColors[$pending['status']] ?? '#667781';
        $statusLabel = $statusLabels[$pending['status']] ?? $pending['status'];
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($pending['patient_name']) . '</td>';
        echo '<td>' . h($pending['specialty'] ?? '-') . '</td>';
        echo '<td>' . (int)$pending['session_quantity'] . '</td>';
        echo '<td>R$ ' . number_format((float)$pending['agreed_value'], 2, ',', '.') . '</td>';
        echo '<td style="font-weight:700;color:#00a884">R$ ' . number_format($totalValue, 2, ',', '.') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusLabel . '</span></td>';
        
        $pendingDocs = (int)$pending['pending_docs'];
        if ($pendingDocs > 0) {
            echo '<td style="color:#ef4444;font-weight:600">' . $pendingDocs . '</td>';
        } else {
            echo '<td style="color:#10b981">-</td>';
        }
        
        echo '<td>' . date('d/m/Y', strtotime($pending['created_at'])) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

// Histórico de Pagamentos
echo '<section class="card col12">';
echo '<h3>Histórico de Pagamentos</h3>';

if (count($payments) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">';
    echo '<svg style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm7 14H5V5h2v3h10V5h2v12z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum documento aprovado ainda</div>';
    echo '<div style="font-size:14px">Quando seus documentos de sessão forem aprovados, aparecerão aqui</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Especialidade</th><th>Sessão</th><th>Data Sessão</th><th>Valor</th><th>Aprovado em</th><th>Status</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($payments as $payment) {
        $docStatus = (string)($payment['doc_status'] ?? 'approved');
        $statusLabel = $docStatus === 'paid' ? 'Pago' : 'Aprovado';
        $statusColor = $docStatus === 'paid' ? '#10b981' : '#0284c7';
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($payment['patient_name']) . '</td>';
        echo '<td>' . h($payment['specialty'] ?? '-') . '</td>';
        echo '<td>Sessão ' . (int)$payment['session_number'] . '</td>';
        echo '<td>' . ($payment['session_date'] ? date('d/m/Y', strtotime($payment['session_date'])) : '-') . '</td>';
        echo '<td style="font-weight:700;color:#10b981">R$ ' . number_format((float)$payment['valor_sessao'], 2, ',', '.') . '</td>';
        echo '<td>' . ($payment['approved_at'] ? date('d/m/Y', strtotime($payment['approved_at'])) : '-') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusLabel . '</span></td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
