<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();

// Buscar pacientes atribuídos ao profissional
$patientsStmt = db()->prepare("
    SELECT 
        p.id,
        p.full_name,
        p.cpf,
        p.phone,
        pa.specialty,
        pa.service_type,
        pa.session_quantity,
        pa.status,
        pa.created_at,
        (SELECT COUNT(*) FROM billing_document_requirements bdr 
         WHERE bdr.assignment_id = pa.id AND bdr.professional_user_id = ? AND bdr.status = 'pending') as pending_docs
    FROM patient_assignments pa
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE pa.professional_user_id = ?
    AND pa.status IN ('admitted', 'awaiting_financial_approval', 'approved', 'completed')
    ORDER BY pa.created_at DESC
");
$patientsStmt->execute([$userId, $userId]);
$patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar documentos pendentes
$pendingDocsStmt = db()->prepare("
    SELECT 
        bdr.id,
        bdr.session_number,
        bdr.session_date,
        bdr.status,
        pa.id as assignment_id,
        p.full_name as patient_name,
        pa.specialty
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    INNER JOIN patients p ON p.id = pa.patient_id
    WHERE bdr.professional_user_id = ?
    AND bdr.status IN ('pending', 'rejected')
    ORDER BY bdr.created_at ASC
    LIMIT 10
");
$pendingDocsStmt->execute([$userId]);
$pendingDocs = $pendingDocsStmt->fetchAll(PDO::FETCH_ASSOC);

view_header('Meus Registros');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Meus Registros</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Pacientes atribuídos e documentos pendentes</div>';
echo '</div>';
echo '</div>';
echo '</section>';

// Alertas de Documentos Pendentes
if (count($pendingDocs) > 0) {
    echo '<section class="card col12" style="background:#fef3c7;border-left:4px solid #f59e0b">';
    echo '<div style="display:flex;align-items:center;gap:12px">';
    echo '<svg style="width:24px;height:24px;color:#f59e0b" fill="currentColor" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>';
    echo '<div>';
    echo '<div style="font-weight:700;color:#92400e">Você tem ' . count($pendingDocs) . ' documento(s) pendente(s)</div>';
    echo '<div style="font-size:14px;color:#78350f">Envie os documentos de comprovação para liberar o pagamento</div>';
    echo '</div>';
    echo '<a href="/faturamento_profissional.php" class="btn btnPrimary" style="margin-left:auto">Ver Pendências</a>';
    echo '</div>';
    echo '</section>';
}

// Resumo
echo '<section class="card col12">';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">';

$totalPatients = count($patients);
$totalPending = count($pendingDocs);
$totalActive = count(array_filter($patients, fn($p) => in_array($p['status'], ['admitted', 'awaiting_financial_approval', 'approved'])));

echo '<div style="padding:20px;background:#f0f9ff;border-radius:8px;border-left:4px solid #0284c7">';
echo '<div style="font-size:14px;color:#0369a1;font-weight:600;margin-bottom:8px">Total de Pacientes</div>';
echo '<div style="font-size:32px;font-weight:700;color:#0c4a6e">' . $totalPatients . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:#f0fdf4;border-radius:8px;border-left:4px solid #10b981">';
echo '<div style="font-size:14px;color:#059669;font-weight:600;margin-bottom:8px">Atendimentos Ativos</div>';
echo '<div style="font-size:32px;font-weight:700;color:#065f46">' . $totalActive . '</div>';
echo '</div>';

echo '<div style="padding:20px;background:#fef3c7;border-radius:8px;border-left:4px solid #f59e0b">';
echo '<div style="font-size:14px;color:#d97706;font-weight:600;margin-bottom:8px">Documentos Pendentes</div>';
echo '<div style="font-size:32px;font-weight:700;color:#92400e">' . $totalPending . '</div>';
echo '</div>';

echo '</div>';
echo '</section>';

// Lista de Pacientes
echo '<section class="card col12">';
echo '<h3>Meus Pacientes</h3>';

if (count($patients) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">';
    echo '<svg style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum paciente atribuído</div>';
    echo '<div style="font-size:14px">Aguarde a atribuição de pacientes pela equipe</div>';
    echo '</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>CPF</th><th>Telefone</th><th>Especialidade</th><th>Sessões</th><th>Status</th><th>Docs Pendentes</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($patients as $patient) {
        $statusColors = [
            'admitted' => '#f59e0b',
            'awaiting_financial_approval' => '#0284c7',
            'approved' => '#10b981',
            'completed' => '#667781'
        ];
        $statusLabels = [
            'admitted' => 'Aguardando Docs',
            'awaiting_financial_approval' => 'Aguardando Aprovação',
            'approved' => 'Aprovado',
            'completed' => 'Concluído'
        ];
        $statusColor = $statusColors[$patient['status']] ?? '#667781';
        $statusLabel = $statusLabels[$patient['status']] ?? $patient['status'];
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($patient['full_name']) . '</td>';
        echo '<td>' . h($patient['cpf'] ?? '-') . '</td>';
        echo '<td>' . h($patient['phone'] ?? '-') . '</td>';
        echo '<td>' . h($patient['specialty'] ?? '-') . '</td>';
        echo '<td>' . (int)$patient['session_quantity'] . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusLabel . '</span></td>';
        
        $pendingCount = (int)$patient['pending_docs'];
        if ($pendingCount > 0) {
            echo '<td style="color:#ef4444;font-weight:600">' . $pendingCount . '</td>';
        } else {
            echo '<td style="color:#10b981">-</td>';
        }
        
        echo '<td style="text-align:right">';
        echo '<a class="btn" href="/patients_view.php?id=' . (int)$patient['id'] . '">Ver Paciente</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

// Documentos Pendentes Recentes
if (count($pendingDocs) > 0) {
    echo '<section class="card col12">';
    echo '<h3>Documentos Pendentes Recentes</h3>';
    
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Especialidade</th><th>Sessão</th><th>Data</th><th>Status</th><th style="text-align:right">Ações</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($pendingDocs as $doc) {
        $statusColor = $doc['status'] === 'rejected' ? '#dc2626' : '#ef4444';
        $statusText = $doc['status'] === 'rejected' ? 'Rejeitado' : 'Pendente';
        
        echo '<tr>';
        echo '<td style="font-weight:600">' . h($doc['patient_name']) . '</td>';
        echo '<td>' . h($doc['specialty'] ?? '-') . '</td>';
        echo '<td>Sessão ' . (int)$doc['session_number'] . '</td>';
        echo '<td>' . ($doc['session_date'] ? date('d/m/Y', strtotime($doc['session_date'])) : '-') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . $statusText . '</span></td>';
        echo '<td style="text-align:right">';
        echo '<a class="btn btnPrimary" href="/faturamento_upload_doc.php?requirement_id=' . (int)$doc['id'] . '">Enviar</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    echo '<div style="margin-top:16px;text-align:center">';
    echo '<a href="/faturamento_profissional.php" class="btn">Ver Todas as Pendências</a>';
    echo '</div>';
    
    echo '</section>';
}

echo '</div>';

view_footer();
