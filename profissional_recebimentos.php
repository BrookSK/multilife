<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();

// Buscar estatísticas de recebimentos
$statsStmt = db()->prepare("
    SELECT 
        COUNT(DISTINCT pa.id) as total_atendimentos,
        SUM(COALESCE(pa.agreed_value, pa.payment_value) * pa.session_quantity) as total_servicos,
        SUM(CASE WHEN pa.status IN ('admitted', 'awaiting_financial_approval', 'approved') THEN COALESCE(pa.agreed_value, pa.payment_value) * pa.session_quantity ELSE 0 END) as total_pendente,
        SUM(CASE WHEN pa.status = 'completed' THEN COALESCE(pa.agreed_value, pa.payment_value) * pa.session_quantity ELSE 0 END) as total_pago
    FROM patient_assignments pa
    WHERE pa.professional_user_id = ?
");
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Buscar histórico de pagamentos (atendimentos concluídos)
$paymentsStmt = db()->prepare("
    SELECT 
        pa.id,
        pa.patient_id,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        COALESCE(pa.agreed_value, pa.payment_value) as payment_value,
        pa.agreed_value,
        pa.authorized_value,
        pa.completed_at,
        p.full_name as patient_name,
        bi.final_value,
        bi.paid_at,
        bi.payment_reference
    FROM patient_assignments pa
    INNER JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN billing_invoices bi ON bi.assignment_id = pa.id
    WHERE pa.professional_user_id = ?
    AND pa.status = 'completed'
    ORDER BY pa.completed_at DESC
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
        COALESCE(pa.agreed_value, pa.payment_value) as payment_value,
        pa.agreed_value,
        pa.authorized_value,
        pa.status,
        pa.created_at,
        p.full_name as patient_name,
        (SELECT COUNT(*) FROM billing_document_requirements bdr 
         WHERE bdr.assignment_id = pa.id AND bdr.status = 'pending') as pending_docs
    FROM patient_assignments pa
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE pa.professional_user_id = ?
    AND pa.status IN ('admitted', 'awaiting_financial_approval', 'approved')
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
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">';

echo '<div style="padding:24px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:12px;color:white;box-shadow:0 4px 12px rgba(102,126,234,0.3)">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
echo '<div style="font-size:14px;font-weight:600;opacity:0.9">Número de Atendimentos</div>';
echo '<svg style="width:32px;height:32px;opacity:0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
echo '</div>';
echo '<div style="font-size:40px;font-weight:700">' . $totalAtendimentos . '</div>';
echo '<div style="font-size:12px;opacity:0.8;margin-top:8px">Total de atendimentos realizados</div>';
echo '</div>';

echo '<div style="padding:24px;background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);border-radius:12px;color:white;box-shadow:0 4px 12px rgba(240,147,251,0.3)">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
echo '<div style="font-size:14px;font-weight:600;opacity:0.9">Total em Serviços</div>';
echo '<svg style="width:32px;height:32px;opacity:0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>';
echo '</div>';
echo '<div style="font-size:40px;font-weight:700">R$ ' . number_format($totalServicos, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:0.8;margin-top:8px">Valor total de todos os serviços</div>';
echo '</div>';

echo '<div style="padding:24px;background:linear-gradient(135deg,#fa709a 0%,#fee140 100%);border-radius:12px;color:white;box-shadow:0 4px 12px rgba(250,112,154,0.3)">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
echo '<div style="font-size:14px;font-weight:600;opacity:0.9">Total Pendente</div>';
echo '<svg style="width:32px;height:32px;opacity:0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>';
echo '</div>';
echo '<div style="font-size:40px;font-weight:700">R$ ' . number_format($totalPendente, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:0.8;margin-top:8px">Aguardando aprovação e pagamento</div>';
echo '</div>';

echo '<div style="padding:24px;background:linear-gradient(135deg,#30cfd0 0%,#330867 100%);border-radius:12px;color:white;box-shadow:0 4px 12px rgba(48,207,208,0.3)">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
echo '<div style="font-size:14px;font-weight:600;opacity:0.9">Total Pago</div>';
echo '<svg style="width:32px;height:32px;opacity:0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>';
echo '</div>';
echo '<div style="font-size:40px;font-weight:700">R$ ' . number_format($totalPago, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:0.8;margin-top:8px">Histórico de pagamentos recebidos</div>';
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
        $totalValue = (float)$pending['payment_value'] * (int)$pending['session_quantity'];
        
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
        echo '<td>R$ ' . number_format((float)$pending['payment_value'], 2, ',', '.') . '</td>';
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
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum pagamento ainda</div>';
    echo '<div style="font-size:14px">Seu histórico de pagamentos aparecerá aqui</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Especialidade</th><th>Sessões</th><th>Valor Original</th><th>Valor Pago</th><th>Data Conclusão</th><th>Data Pagamento</th><th>Referência</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($payments as $payment) {
        $originalValue = (float)$payment['payment_value'] * (int)$payment['session_quantity'];
        $finalValue = $payment['final_value'] ? (float)$payment['final_value'] : $originalValue;
        $wasAdjusted = abs($finalValue - $originalValue) > 0.01;
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($payment['patient_name']) . '</td>';
        echo '<td>' . h($payment['specialty'] ?? '-') . '</td>';
        echo '<td>' . (int)$payment['session_quantity'] . '</td>';
        echo '<td>R$ ' . number_format($originalValue, 2, ',', '.') . '</td>';
        echo '<td style="font-weight:700;color:#10b981">';
        echo 'R$ ' . number_format($finalValue, 2, ',', '.');
        if ($wasAdjusted) {
            echo ' <span style="font-size:11px;color:#667781">(ajustado)</span>';
        }
        echo '</td>';
        echo '<td>' . ($payment['completed_at'] ? date('d/m/Y', strtotime($payment['completed_at'])) : '-') . '</td>';
        echo '<td>' . ($payment['paid_at'] ? date('d/m/Y', strtotime($payment['paid_at'])) : '-') . '</td>';
        echo '<td>' . h($payment['payment_reference'] ?? '-') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
