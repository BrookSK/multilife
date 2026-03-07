<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.manage');

$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($assignmentId === 0) {
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar atendimento
$stmt = db()->prepare("
    SELECT 
        pa.*,
        p.full_name as patient_name,
        p.cpf as patient_cpf,
        u.name as professional_name,
        u.email as professional_email,
        assigned_by.name as assigned_by_name,
        approved_by.name as approved_by_name
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    LEFT JOIN users assigned_by ON assigned_by.id = pa.assigned_by_user_id
    LEFT JOIN users approved_by ON approved_by.id = pa.approved_by_user_id
    WHERE pa.id = ?
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar pendências de documentos
$docsStmt = db()->prepare("
    SELECT 
        bdr.*,
        d.title as document_title,
        d.file_path,
        reviewer.name as reviewer_name
    FROM billing_document_requirements bdr
    LEFT JOIN documents d ON d.id = bdr.document_id
    LEFT JOIN users reviewer ON reviewer.id = bdr.reviewed_by_user_id
    WHERE bdr.assignment_id = ?
    ORDER BY bdr.session_number ASC
");
$docsStmt->execute([$assignmentId]);
$documentRequirements = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar fatura se existir
$invoiceStmt = db()->prepare("
    SELECT 
        bi.*,
        adjusted_by.name as adjusted_by_name,
        approved_by.name as approved_by_name,
        cancelled_by.name as cancelled_by_name
    FROM billing_invoices bi
    LEFT JOIN users adjusted_by ON adjusted_by.id = bi.adjusted_by_user_id
    LEFT JOIN users approved_by ON approved_by.id = bi.approved_by_user_id
    LEFT JOIN users cancelled_by ON cancelled_by.id = bi.cancelled_by_user_id
    WHERE bi.assignment_id = ?
");
$invoiceStmt->execute([$assignmentId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

$totalValue = (float)$assignment['payment_value'] * (int)$assignment['session_quantity'];

view_header('Detalhes do Faturamento');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<a href="/faturamento_list.php" class="btn" style="margin-bottom:8px">← Voltar</a>';
echo '<div style="font-size:22px;font-weight:900">Atendimento #' . (int)$assignment['id'] . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">' . h($assignment['patient_name']) . ' - ' . h($assignment['professional_name']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';

// Botões de ação baseados no status
if ($assignment['status'] === 'awaiting_financial_approval') {
    echo '<a class="btn btnPrimary" href="/faturamento_approve.php?id=' . $assignmentId . '">Aprovar Financeiro</a>';
} elseif ($assignment['status'] === 'approved') {
    echo '<a class="btn btnPrimary" href="/faturamento_complete.php?id=' . $assignmentId . '">Finalizar Atendimento</a>';
}

echo '</div>';
echo '</div>';
echo '</section>';

// Informações do Atendimento
echo '<section class="card col6">';
echo '<h3>Informações do Atendimento</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Paciente:</td><td>' . h($assignment['patient_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">CPF:</td><td>' . h($assignment['patient_cpf'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Profissional:</td><td>' . h($assignment['professional_name']) . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Especialidade:</td><td>' . h($assignment['specialty'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Tipo de Serviço:</td><td>' . h($assignment['service_type'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Quantidade de Sessões:</td><td>' . (int)$assignment['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Frequência:</td><td>' . h($assignment['session_frequency'] ?? '-') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Status:</td><td><span class="pill">' . h($assignment['status']) . '</span></td></tr>';
echo '</table>';
echo '</section>';

// Valores
echo '<section class="card col6">';
echo '<h3>Valores</h3>';
echo '<table style="width:100%">';
echo '<tr><td style="font-weight:600;padding:8px 0">Valor por Sessão:</td><td>R$ ' . number_format((float)$assignment['payment_value'], 2, ',', '.') . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0">Total de Sessões:</td><td>' . (int)$assignment['session_quantity'] . '</td></tr>';
echo '<tr><td style="font-weight:600;padding:8px 0;border-top:2px solid #e5e7eb">Valor Total:</td><td style="font-size:18px;font-weight:700;color:#00a884;border-top:2px solid #e5e7eb">R$ ' . number_format($totalValue, 2, ',', '.') . '</td></tr>';

if ($invoice) {
    if ($invoice['adjusted_value'] !== null) {
        echo '<tr><td style="font-weight:600;padding:8px 0">Valor Ajustado:</td><td>R$ ' . number_format((float)$invoice['adjusted_value'], 2, ',', '.') . '</td></tr>';
        echo '<tr><td style="font-weight:600;padding:8px 0">Motivo do Ajuste:</td><td>' . h($invoice['adjustment_reason'] ?? '-') . '</td></tr>';
    }
    echo '<tr><td style="font-weight:600;padding:8px 0;border-top:2px solid #e5e7eb">Valor Final:</td><td style="font-size:18px;font-weight:700;color:#00a884;border-top:2px solid #e5e7eb">R$ ' . number_format((float)$invoice['final_value'], 2, ',', '.') . '</td></tr>';
}

echo '</table>';

if ($assignment['status'] === 'approved' && !$invoice) {
    echo '<div style="margin-top:16px">';
    echo '<a class="btn btnPrimary" href="/faturamento_adjust.php?id=' . $assignmentId . '">Ajustar Valores</a>';
    echo '</div>';
}

echo '</section>';

// Documentos de Comprovação
echo '<section class="card col12">';
echo '<h3>Documentos de Comprovação</h3>';

if (count($documentRequirements) === 0) {
    echo '<div style="padding:20px;text-align:center;color:#667781">Nenhum documento pendente</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Sessão</th><th>Data</th><th>Status</th><th>Documento</th><th>Enviado em</th><th>Revisado por</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($documentRequirements as $req) {
        $statusColors = [
            'pending' => '#ef4444',
            'uploaded' => '#f59e0b',
            'approved' => '#10b981',
            'rejected' => '#dc2626'
        ];
        $statusColor = $statusColors[$req['status']] ?? '#667781';
        
        echo '<tr>';
        echo '<td>Sessão ' . (int)$req['session_number'] . '</td>';
        echo '<td>' . ($req['session_date'] ? date('d/m/Y', strtotime($req['session_date'])) : '-') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . h($req['status']) . '</span></td>';
        echo '<td>' . h($req['document_title'] ?? '-') . '</td>';
        echo '<td>' . ($req['uploaded_at'] ? date('d/m/Y H:i', strtotime($req['uploaded_at'])) : '-') . '</td>';
        echo '<td>' . h($req['reviewer_name'] ?? '-') . '</td>';
        echo '<td style="text-align:right">';
        
        if ($req['status'] === 'uploaded') {
            echo '<a class="btn" href="/faturamento_review_doc.php?id=' . (int)$req['id'] . '">Revisar</a>';
        } elseif ($req['document_id']) {
            echo '<a class="btn" href="/documents_view.php?id=' . (int)$req['document_id'] . '">Ver</a>';
        }
        
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

// Histórico
echo '<section class="card col12">';
echo '<h3>Histórico</h3>';
echo '<div style="border-left:3px solid #e5e7eb;padding-left:16px">';

if ($assignment['created_at']) {
    echo '<div style="margin-bottom:12px">';
    echo '<div style="font-weight:600">Atribuído</div>';
    echo '<div style="font-size:14px;color:#667781">' . date('d/m/Y H:i', strtotime($assignment['created_at'])) . ' - ' . h($assignment['assigned_by_name']) . '</div>';
    echo '</div>';
}

if ($assignment['approved_at']) {
    echo '<div style="margin-bottom:12px">';
    echo '<div style="font-weight:600">Aprovado (Pré-admissão)</div>';
    echo '<div style="font-size:14px;color:#667781">' . date('d/m/Y H:i', strtotime($assignment['approved_at'])) . ' - ' . h($assignment['approved_by_name']) . '</div>';
    echo '</div>';
}

if ($assignment['admitted_at']) {
    echo '<div style="margin-bottom:12px">';
    echo '<div style="font-weight:600">Admitido</div>';
    echo '<div style="font-size:14px;color:#667781">' . date('d/m/Y H:i', strtotime($assignment['admitted_at'])) . '</div>';
    echo '</div>';
}

if ($invoice && $invoice['approved_at']) {
    echo '<div style="margin-bottom:12px">';
    echo '<div style="font-weight:600">Aprovado Financeiramente</div>';
    echo '<div style="font-size:14px;color:#667781">' . date('d/m/Y H:i', strtotime($invoice['approved_at'])) . ' - ' . h($invoice['approved_by_name']) . '</div>';
    echo '</div>';
}

if ($assignment['completed_at']) {
    echo '<div style="margin-bottom:12px">';
    echo '<div style="font-weight:600">Concluído</div>';
    echo '<div style="font-size:14px;color:#667781">' . date('d/m/Y H:i', strtotime($assignment['completed_at'])) . '</div>';
    echo '</div>';
}

echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
