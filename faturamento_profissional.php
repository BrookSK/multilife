<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Buscar pendências de documentos do profissional
$sql = "
    SELECT 
        bdr.id,
        bdr.assignment_id,
        bdr.session_number,
        bdr.session_date,
        bdr.status,
        bdr.uploaded_at,
        bdr.rejection_reason,
        pa.patient_id,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        pa.payment_value,
        p.full_name as patient_name,
        d.title as document_title
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN documents d ON d.id = bdr.document_id
    WHERE bdr.professional_user_id = ?
    AND bdr.status IN ('pending', 'rejected')
";

if ($searchQuery !== '') {
    $sql .= " AND p.full_name LIKE ?";
}

$sql .= " ORDER BY bdr.session_date ASC, bdr.created_at ASC";

$stmt = db()->prepare($sql);
if ($searchQuery !== '') {
    $stmt->execute([$userId, '%' . $searchQuery . '%']);
} else {
    $stmt->execute([$userId]);
}
$pendingDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar documentos já enviados
$sentSql = "
    SELECT 
        bdr.id,
        bdr.assignment_id,
        bdr.session_number,
        bdr.session_date,
        bdr.status,
        bdr.uploaded_at,
        pa.patient_id,
        pa.specialty,
        pa.service_type,
        p.full_name as patient_name,
        d.title as document_title,
        reviewer.name as reviewer_name,
        bdr.reviewed_at
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN documents d ON d.id = bdr.document_id
    LEFT JOIN users reviewer ON reviewer.id = bdr.reviewed_by_user_id
    WHERE bdr.professional_user_id = ?
    AND bdr.status IN ('uploaded', 'approved')
    ORDER BY bdr.uploaded_at DESC
    LIMIT 20
";

$sentStmt = db()->prepare($sentSql);
$sentStmt->execute([$userId]);
$sentDocs = $sentStmt->fetchAll(PDO::FETCH_ASSOC);

view_header('Minhas Pendências de Faturamento');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Minhas Pendências de Faturamento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Envie os documentos de comprovação dos atendimentos realizados</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Alertas
if (count($pendingDocs) > 0) {
    echo '<section class="card col12" style="background:#fef3c7;border-left:4px solid #f59e0b">';
    echo '<div style="display:flex;align-items:center;gap:12px">';
    echo '<svg style="width:24px;height:24px;color:#f59e0b" fill="currentColor" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>';
    echo '<div>';
    echo '<div style="font-weight:700;color:#92400e">Você tem ' . count($pendingDocs) . ' documento(s) pendente(s)</div>';
    echo '<div style="font-size:14px;color:#78350f">Envie os documentos de comprovação para liberar o pagamento</div>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

// Filtro de pesquisa
echo '<section class="card col12" style="margin-top:16px">';
echo '<form method="get" action="/faturamento_profissional.php" style="margin-bottom:0">';
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Buscar por paciente..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</form>';
echo '</section>';

// Documentos Pendentes
echo '<section class="card col12" style="margin-top:16px">';
echo '<h3>Documentos Pendentes</h3>';

if (count($pendingDocs) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">';
    echo '<svg style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhuma pendência!</div>';
    echo '<div style="font-size:14px">Você está em dia com o envio de documentos</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Especialidade</th><th>Sessão</th><th>Data</th><th>Status</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($pendingDocs as $doc) {
        $statusColor = $doc['status'] === 'rejected' ? '#dc2626' : '#ef4444';
        $statusText = $doc['status'] === 'rejected' ? 'Rejeitado - Reenviar' : 'Pendente';
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($doc['patient_name']) . '</td>';
        echo '<td>' . h($doc['specialty'] ?? '-') . '</td>';
        echo '<td>Sessão ' . (int)$doc['session_number'] . '</td>';
        echo '<td>' . ($doc['session_date'] ? date('d/m/Y', strtotime($doc['session_date'])) : '-') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusText . '</span></td>';
        echo '<td style="text-align:right">';
        echo '<a class="btn btnPrimary" href="/faturamento_upload_doc.php?requirement_id=' . (int)$doc['id'] . '">Enviar Documento</a>';
        echo '</td>';
        echo '</tr>';
        
        if ($doc['status'] === 'rejected' && $doc['rejection_reason']) {
            echo '<tr>';
            echo '<td colspan="6" style="background:#fef2f2;padding:12px;border-left:3px solid #dc2626">';
            echo '<strong>Motivo da rejeição:</strong> ' . h($doc['rejection_reason']);
            echo '</td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

// Documentos Enviados
echo '<section class="card col12" style="margin-top:16px">';
echo '<h3>Documentos Enviados Recentemente</h3>';

if (count($sentDocs) === 0) {
    echo '<div style="padding:20px;text-align:center;color:#667781">Nenhum documento enviado ainda</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Especialidade</th><th>Sessão</th><th>Enviado em</th><th>Status</th><th>Revisado por</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($sentDocs as $doc) {
        $statusColors = [
            'uploaded' => '#f59e0b',
            'approved' => '#10b981'
        ];
        $statusColor = $statusColors[$doc['status']] ?? '#667781';
        $statusText = $doc['status'] === 'approved' ? 'Aprovado' : 'Aguardando Revisão';
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($doc['patient_name']) . '</td>';
        echo '<td>' . h($doc['specialty'] ?? '-') . '</td>';
        echo '<td>Sessão ' . (int)$doc['session_number'] . '</td>';
        echo '<td>' . ($doc['uploaded_at'] ? date('d/m/Y H:i', strtotime($doc['uploaded_at'])) : '-') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusText . '</span></td>';
        echo '<td>' . h($doc['reviewer_name'] ?? '-') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
